<?php
/**
 * Párhuzamos szerkesztés védelme (0.70.0, HIBALISTA H1).
 *
 * Két dolgot old meg, amikor többen dolgoznak ugyanazon a lapon / ügyfélen / eszközön:
 *
 *  1. „Közben módosította": az űrlap betöltésekor a szerver megjegyzi, hol tart a
 *     Változásnapló (egy szám: az utolsó bejegyzés azonosítója). Mentéskor megnézi,
 *     került-e a naplóba ennél újabb mezőmódosítás EZEKRŐL az adatokról EGY MÁSIK
 *     BELÉPETT FELHASZNÁLÓTÓL. Ha igen, a mentés nem fut le, a válasz megmondja, ki
 *     és mikor módosított – a böngésző felkínálja a frissítést vagy a felülírást.
 *     Az ellenőrzés a Változásnaplóra épül, így minden kódutat lefed (űrlap, állapotváltás,
 *     fizetés, AI-eszköz), és nem kell új oszlop vagy tábla.
 *  2. „X is szerkeszti": a nyitott űrlap a közös pulzussal (30 mp) jelzi, hogy nyitva
 *     van; a válaszban megjön, ki más tartja még nyitva ugyanazt.
 *
 * Korlátok (szándékosan így):
 *  - Közös fióknál (ugyanaz a felhasználó két gépen) a két szerkesztő nem különböztethető
 *    meg – ezt a HIBALISTA H2 (Ki vagy?) oldja meg.
 *  - Az ügyfél vagy a rendszer (időzítő, RMA-oldal) módosítása nem számít ütközésnek,
 *    mert nem belépett felhasználótól jön.
 *  - A bezáráskor elküldött utolsó mentés (keepalive) választ nem olvas: ha éppen akkor
 *    ütközik, nem íródik be.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Utkozes
{
    /** Ennyi másodpercig számít valaki „nyitva tartónak" az utolsó pulzusa után. */
    public const PULZUS_TTL = 75;

    /** Egy kérésben legfeljebb ennyi nyitott űrlapot dolgozunk fel. */
    private const MAX_NYITOTT = 6;

    /**
     * Melyik rekordtípus mely naplózott táblákra figyel.
     * [fő típus a naplóban, naplózott táblák, azonosító oszlop]
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const TIPUSOK = [
        'munkalap' => ['munkalap', ['munkalap', 'munkalap_hiba', 'munkalap_eszkoz', 'munkalap_tetel']],
        'ugyfel'   => ['ugyfel', ['ugyfel']],
        'eszkoz'   => ['eszkoz', ['eszkoz']],
    ];

    public static function init(): void
    {
        add_filter('sdh_muhely_pulzus', [self::class, 'pulzus'], 20, 2);
    }

    public static function tipus_ervenyes(string $tipus): bool
    {
        return isset(self::TIPUSOK[$tipus]);
    }

    /** A Változásnapló jelenlegi vége (az utolsó bejegyzés azonosítója; 0, ha még üres). */
    public static function alap(): int
    {
        global $wpdb;

        $tabla = SDH_Muhely_Valtozasnaplo::tabla();

        return (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$tabla}");
    }

    /**
     * Az űrlap rejtett mezői: az alap (innen számít „közben") és a felülírás jelzője.
     * Új (még nem mentett) rekordnál nincs mit védeni.
     */
    public static function mezok(string $tipus, int $id): void
    {
        if ($id <= 0 || !self::tipus_ervenyes($tipus)) {
            return;
        }
        ?>
        <input type="hidden" name="szerk_naplo" value="<?php echo (int) self::alap(); ?>" data-sdh-szerk="<?php echo esc_attr($tipus . ':' . $id); ?>">
        <input type="hidden" name="szerk_felulir" value="0">
        <?php
    }

    /**
     * Mentés előtt: módosította-e más a betöltés óta?
     *
     * @return array<string, mixed>|null null = mehet a mentés; különben a hibaválasz adatai
     */
    public static function ellenoriz(string $tipus, int $id): ?array
    {
        if ($id <= 0 || !self::tipus_ervenyes($tipus) || !isset($_POST['szerk_naplo'])) {
            return null;
        }

        // A felhasználó döntött: a sajátját menti, a másikét felülírja.
        if (isset($_POST['szerk_felulir']) && (string) wp_unslash($_POST['szerk_felulir']) === '1') {
            return null;
        }

        $alap = (int) $_POST['szerk_naplo'];
        $sorok = self::masok_modositasai($tipus, $id, $alap, get_current_user_id());

        if ($sorok === []) {
            return null;
        }

        $nevek = [];

        foreach ($sorok as $s) {
            $nevek[(string) $s->felhasznalo_nev] = true;
        }

        $nevek = array_keys($nevek);
        $ido   = (string) $sorok[0]->ido;
        $ora   = $ido !== '' ? mysql2date('H:i', $ido) : '';

        return [
            'utkozes'     => true,
            'kik'         => $nevek,
            'ido'         => $ora,
            'szerk_naplo' => self::alap(),
            'uzenet'      => sprintf(
                'Közben %s módosította%s. Frissíts, hogy lásd a változást, vagy mentsd az enyémet (az övé felülíródik).',
                implode(', ', $nevek),
                $ora !== '' ? ' (' . $ora . ')' : ''
            ),
        ];
    }

    /**
     * A Változásnapló friss bejegyzései, amelyeket másik belépett felhasználó írt erről a rekordról.
     * Az események (nyomtatás, SMS) nem módosítják az űrlap adatait, ezért kimaradnak.
     *
     * @return list<object>
     */
    public static function masok_modositasai(string $tipus, int $id, int $alap, int $en): array
    {
        global $wpdb;

        if (!self::tipus_ervenyes($tipus)) {
            return [];
        }

        [$fo, $tablak] = self::TIPUSOK[$tipus];

        $naplo = SDH_Muhely_Valtozasnaplo::tabla();
        $ph    = implode(',', array_fill(0, count($tablak), '%s'));

        // Munkalapnál a tételek, hibák, eszközök is a lap „főrekordjához" tartoznak (fo_tipus / fo_id);
        // az ügyfél és az eszköz saját sorára a tábla + sor azonosítója mutat.
        if ($tipus === 'munkalap') {
            $hol  = "fo_tipus = %s AND fo_id = %d AND tabla IN ({$ph})";
            $args = array_merge([$fo, $id], $tablak);
        } else {
            $hol  = "tabla IN ({$ph}) AND sor_id = %d";
            $args = array_merge($tablak, [$id]);
        }

        $args = array_merge([$alap, $en], $args);

        $sql = "SELECT id, ido, felhasznalo, felhasznalo_nev
                  FROM {$naplo}
                 WHERE id > %d AND felhasznalo > 0 AND felhasznalo <> %d
                   AND muvelet IN ('letrehozas', 'modositas', 'torles')
                   AND {$hol}
              ORDER BY id DESC
                 LIMIT 20";

        $sorok = $wpdb->get_results($wpdb->prepare($sql, $args)); // phpcs:ignore WordPress.DB.PreparedSQL

        return is_array($sorok) ? array_values($sorok) : [];
    }

    /* =================================================================
     * „X is szerkeszti" – a közös pulzusra épül
     * ============================================================== */

    /**
     * A pulzus válaszába: ki tartja még nyitva ugyanazt, amit én.
     *
     * A kérés `szerk` paramétere a nálam nyitott űrlapok („munkalap:12,ugyfel:3"),
     * a `szerk_el` a most bezártak. A válasz `szerkeszti` kulcsa: rekord => [nevek].
     *
     * @param array<string, mixed> $valasz
     * @return array<string, mixed>
     */
    public static function pulzus(array $valasz, int $user = 0): array
    {
        if ($user <= 0) {
            return $valasz;
        }

        $nyitott = self::kulcsok((string) ($_REQUEST['szerk'] ?? '')); // phpcs:ignore WordPress.Security
        $elhagyott = self::kulcsok((string) ($_REQUEST['szerk_el'] ?? '')); // phpcs:ignore WordPress.Security

        foreach ($elhagyott as $k) {
            if (!in_array($k, $nyitott, true)) {
                self::jelenlet_torol($k, $user);
            }
        }

        $kik = [];

        foreach ($nyitott as $k) {
            $mind = self::jelenlet_frissit($k, $user);
            $nev  = [];

            foreach ($mind as $uid => $ido) {
                if ((int) $uid === $user) {
                    continue;
                }

                $nev[] = self::nev((int) $uid);
            }

            if ($nev !== []) {
                $kik[$k] = array_values(array_unique($nev));
            }
        }

        $valasz['szerkeszti'] = (object) $kik;

        return $valasz;
    }

    /** „munkalap:12,ugyfel:3" -> érvényes kulcsok tömbje (legfeljebb MAX_NYITOTT). */
    private static function kulcsok(string $szoveg): array
    {
        $ki = [];

        foreach (explode(',', wp_unslash($szoveg)) as $reszlet) {
            $reszlet = trim($reszlet);

            if (preg_match('/^(munkalap|ugyfel|eszkoz):(\d{1,12})$/', $reszlet, $m) === 1 && (int) $m[2] > 0) {
                $ki[$reszlet] = true;
            }

            if (count($ki) >= self::MAX_NYITOTT) {
                break;
            }
        }

        return array_keys($ki);
    }

    private static function jelenlet_kulcs(string $kulcs): string
    {
        // A „_pulzus" a Változásnapló szűrőjében zaj: a pulzus nem kerül a naplóba.
        return 'sdh_muhely_szerk_pulzus_' . str_replace(':', '_', $kulcs);
    }

    /**
     * Saját jelenlét rögzítése, a lejártak elhagyása.
     *
     * @return array<int, int> felhasználó => utolsó pulzus ideje
     */
    private static function jelenlet_frissit(string $kulcs, int $user): array
    {
        $mind = self::jelenlet_olvas($kulcs);
        $mind[$user] = time();

        set_transient(self::jelenlet_kulcs($kulcs), $mind, self::PULZUS_TTL * 2);

        return $mind;
    }

    private static function jelenlet_torol(string $kulcs, int $user): void
    {
        $mind = self::jelenlet_olvas($kulcs);

        unset($mind[$user]);

        if ($mind === []) {
            delete_transient(self::jelenlet_kulcs($kulcs));

            return;
        }

        set_transient(self::jelenlet_kulcs($kulcs), $mind, self::PULZUS_TTL * 2);
    }

    /** @return array<int, int> */
    private static function jelenlet_olvas(string $kulcs): array
    {
        $mind = get_transient(self::jelenlet_kulcs($kulcs));
        $ki   = [];
        $hatar = time() - self::PULZUS_TTL;

        foreach (is_array($mind) ? $mind : [] as $uid => $ido) {
            if ((int) $ido >= $hatar) {
                $ki[(int) $uid] = (int) $ido;
            }
        }

        return $ki;
    }

    private static function nev(int $uid): string
    {
        $u = get_userdata($uid);

        if (!$u) {
            return 'Egy kolléga';
        }

        return (string) ($u->display_name !== '' ? $u->display_name : $u->user_login);
    }
}
