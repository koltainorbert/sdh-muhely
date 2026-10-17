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
 * 3. Zárolás (0.71.0): amit másik ablakban (akár ugyanazzal a fiókkal, másik gépen) épp szerkesztenek, az nem nyitható
 *    meg, amíg be nem zárják – hacsak nem adják meg a felülbíráló kódot (Beállítások › Jogosultságok). Az ablakot
 *    egy, a böngészőlapon tárolt azonosító (`szerk_fiok`) különbözteti meg; a foglalás megnyitáskor azonnal él, a pulzus
 *    tartja, a bezárás és a lejárat (PULZUS_TTL) oldja. A kód nem kerül URL-be: külön POST-kérés ellenőrzi, és
 *    egyszeri, rövid életű engedélyt ad.
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

    /** Beállítások (Beállítások › Jogosultságok). A kód csak lenyomatként tárolódik (a napló titokként kezeli). */
    public const OPT = 'sdh_muhely_jogosultsagok';

    /** Ennyi sikertelen kódpróbálkozás után a felhasználó 5 percre kizárt a próbálkozásból. */
    private const MAX_PROBA = 5;

    public static function init(): void
    {
        add_filter('sdh_muhely_pulzus', [self::class, 'pulzus'], 20, 2);

        // A zárolás kapuja: a megnyitó kérés ELSŐ kezelője (a rekord saját kezelője előtt fut).
        // 0.95.0 (H1b): a Napi árukiadás és a Rendelés ugyanaz a munkalap-rekord, saját megnyitóval – őket is védi.
        foreach (['munkalapok' => 'munkalap', 'arukiadas' => 'munkalap', 'rendelesek' => 'munkalap', 'ugyfelek' => 'ugyfel', 'eszkozok' => 'eszkoz'] as $modul => $tipus) {
            add_action('wp_ajax_sdh_muhely_' . $modul . '_urlap', static function () use ($tipus): void {
                self::kapu($tipus);
            }, 1);
        }

        add_action('wp_ajax_sdh_muhely_szerk_felulir', [self::class, 'ajax_felulir']);
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    /**
     * @return array{zar: array<string, bool>, hash: string}
     */
    public static function beallitas(): array
    {
        $o = get_option(self::OPT, []);
        $o = is_array($o) ? $o : [];
        $z = isset($o['zar']) && is_array($o['zar']) ? $o['zar'] : [];

        return [
            // Alapból csak a munkalap zárol (az ügyfelet és az eszközt gyakran nyitják meg egymás mellől).
            'zar'  => [
                'munkalap' => array_key_exists('munkalap', $z) ? !empty($z['munkalap']) : true,
                'ugyfel'   => !empty($z['ugyfel']),
                'eszkoz'   => !empty($z['eszkoz']),
            ],
            'hash' => (string) ($o['felulir_zar_hash'] ?? ''),
        ];
    }

    public static function zar_be(string $tipus): bool
    {
        return !empty(self::beallitas()['zar'][$tipus]);
    }

    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Egyszerre egy ablak: zárolás</h2>
            <p class="sdh-sugo">
                Ha egy lapot (akár másik gépen, akár ugyanazzal a fiókkal) már megnyitottak szerkesztésre, az újabb megnyitás
                megáll, és kiírja, ki szerkeszti. Amíg a szerkesztő be nem zárja az ablakot, a lap nem nyitható meg – kivéve,
                ha megadod a <strong>felülbíráló kódot</strong>. Ha a szerkesztő ablaka bezáratlanul elromlik (összeomlik a
                böngésző), a zárolás <?php echo (int) self::PULZUS_TTL; ?> másodperc után magától lejár.
                (Külön „admin kód” nincs: erre a célra egy önálló, közös kód szolgál.)
            </p>

            <input type="hidden" name="jogosultsag_jelen" value="1">

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="zar_munkalap" id="zar_munkalap" value="1" <?php checked($b['zar']['munkalap']); ?>>
                <label for="zar_munkalap">Munkalap (javítás, árukiadás, rendelés)</label>
            </div>
            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="zar_ugyfel" id="zar_ugyfel" value="1" <?php checked($b['zar']['ugyfel']); ?>>
                <label for="zar_ugyfel">Ügyfél</label>
            </div>
            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="zar_eszkoz" id="zar_eszkoz" value="1" <?php checked($b['zar']['eszkoz']); ?>>
                <label for="zar_eszkoz">Eszköz</label>
            </div>

            <h2 class="sdh-doboz__cim">Felülbíráló kód</h2>
            <p class="sdh-sugo">
                Ezzel a kóddal nyitható meg az a lap, amit más épp szerkeszt. A kód csak lenyomatként tárolódik (nem olvasható vissza),
                a használata a Naplóba kerül. Legalább 4 karakter.
                <?php echo $b['hash'] !== '' ? '<strong>Most be van állítva.</strong>' : '<strong>Még nincs beállítva</strong> – amíg nincs, a zárolást nem lehet felülbírálni.'; ?>
            </p>
            <div class="sdh-mezo">
                <label for="felulir_kod_uj"><?php echo $b['hash'] !== '' ? 'Új kód (üresen hagyva a régi marad)' : 'Kód'; ?></label>
                <input type="password" name="felulir_kod_uj" id="felulir_kod_uj" value="" autocomplete="new-password" maxlength="64">
            </div>
            <?php if ($b['hash'] !== '') : ?>
                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" name="felulir_kod_torol" id="felulir_kod_torol" value="1">
                    <label for="felulir_kod_torol">A kód törlése (utána nem lehet felülbírálni)</label>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['jogosultsag_jelen'])) {
            return;
        }

        $hash = self::beallitas()['hash'];
        $uj   = isset($_POST['felulir_kod_uj']) ? trim((string) wp_unslash($_POST['felulir_kod_uj'])) : '';

        if (!empty($_POST['felulir_kod_torol'])) {
            $hash = '';
        } elseif ($uj !== '' && mb_strlen($uj) >= 4) {
            $hash = wp_hash_password($uj);
        }

        update_option(self::OPT, [
            'zar' => [
                'munkalap' => !empty($_POST['zar_munkalap']),
                'ugyfel'   => !empty($_POST['zar_ugyfel']),
                'eszkoz'   => !empty($_POST['zar_eszkoz']),
            ],
            'felulir_zar_hash' => $hash,
        ]);
        // phpcs:enable
    }

    /* =================================================================
     * Zárolás
     * ============================================================== */

    /** A böngészőlap azonosítója (a kérés `szerk_fiok` paramétere); üres = régi kliens, nincs zárolás. */
    private static function fiok(): string
    {
        $f = (string) wp_unslash($_REQUEST['szerk_fiok'] ?? ''); // phpcs:ignore WordPress.Security

        return preg_match('/^[a-z0-9]{8,48}$/', $f) === 1 ? $f : '';
    }

    /**
     * Más ablakok által nyitva tartott ugyanez a rekord.
     *
     * @return list<array{0: int, 1: string}> [felhasználó, megjelenített név]
     */
    public static function nyitva_masoknal(string $kulcs, int $user, string $fiok): array
    {
        $ki = [];

        foreach (self::jelenlet_olvas($kulcs) as $f => $adat) {
            if ($f === $fiok) {
                continue;
            }

            $ki[] = [$adat[0], $adat[0] === $user ? 'Te (másik ablakban)' : self::nev($adat[0])];
        }

        return $ki;
    }

    /**
     * A megnyitó kérés kapuja. Ha a rekordot másik ablak szerkeszti, és nincs érvényes felülbírálás,
     * a rekord űrlapja helyett a zárolás-doboz megy ki. Különben a foglalás azonnal rögzül.
     */
    private static function kapu(string $tipus): void
    {
        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sdh_muhely_modal') || !current_user_can(SDH_Muhely_Admin_UI::jog())) { // phpcs:ignore WordPress.Security
            return; // a rekord saját kezelője elutasítja
        }

        $id   = isset($_GET['id']) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security
        $fiok = self::fiok();
        $user = SDH_Muhely_Szemely::ki();

        if ($id <= 0 || $fiok === '' || !self::zar_be($tipus)) {
            return;
        }

        // A lezárt (zárolt) munkalap csak olvasható: nincs mit védeni.
        if ($tipus === 'munkalap' && class_exists('SDH_Muhely_Munkalap')) {
            if (SDH_Muhely_Munkalap::zarolva($id)) {
                return;
            }
        }

        $kulcs = $tipus . ':' . $id;
        $masok = self::nyitva_masoknal($kulcs, $user, $fiok);

        if ($masok !== []) {
            if (!self::felulir_engedely_fogyaszt($kulcs, $fiok, $user)) {
                self::zar_doboz($tipus, $id, $masok);
                wp_die();
            }

            if (class_exists('SDH_Muhely_Valtozasnaplo')) {
                SDH_Muhely_Valtozasnaplo::esemeny($tipus, $id, 'Zárolás felülbírálva kóddal (megnyitás, miközben más szerkesztette)');
            }
        }

        self::jelenlet_frissit($kulcs, $user, $fiok);
    }

    /** @param list<array{0: int, 1: string}> $masok */
    private static function zar_doboz(string $tipus, int $id, array $masok): void
    {
        $nevek = array_values(array_unique(array_map(static fn (array $m): string => $m[1], $masok)));
        $mit   = ['munkalap' => 'munkalapot', 'ugyfel' => 'ügyfelet', 'eszkoz' => 'eszközt'][$tipus] ?? 'rekordot';
        $van   = self::beallitas()['hash'] !== '';

        ?>
        <div class="sdh-uzenet sdh-uzenet--figyelem sdh-zar-doboz" data-sdh-zar="<?php echo esc_attr($tipus . ':' . $id); ?>">
            <h2 class="sdh-modal__cim">Ezt a <?php echo esc_html($mit); ?> most más szerkeszti</h2>
            <p>
                <strong><?php echo esc_html(implode(', ', $nevek)); ?></strong> már megnyitotta szerkesztésre.
                Amíg be nem zárja, nem nyitható meg.
            </p>
            <?php if ($van) : ?>
                <p class="sdh-zar-doboz__kod">
                    <label for="sdh-zar-kod">Felülbíráló kód</label>
                    <input type="password" id="sdh-zar-kod" data-sdh-zar-kod autocomplete="off" maxlength="64">
                </p>
            <?php else : ?>
                <p class="sdh-sugo">Felülbíráló kód nincs beállítva (Beállítások › Jogosultságok).</p>
            <?php endif; ?>
            <p class="sdh-zar-doboz__hiba" data-sdh-zar-hiba role="alert" hidden></p>
            <div class="sdh-zar-doboz__gombok">
                <?php if ($van) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-zar-nyit>Megnyitás kóddal</button>
                <?php endif; ?>
                <button type="button" class="sdh-gomb" data-sdh-zar-bezar>Bezárás</button>
            </div>
        </div>
        <?php
    }

    /** A kód ellenőrzése (POST): sikerre egyszeri, 60 másodperces engedélyt ad az adott ablaknak és rekordnak. */
    public static function ajax_felulir(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $user  = SDH_Muhely_Szemely::ki();
        $fiok  = self::fiok();
        $kulcs = (string) wp_unslash($_POST['kulcs'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
        $kod   = (string) wp_unslash($_POST['kod'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
        $hash  = self::beallitas()['hash'];

        if ($fiok === '' || preg_match('/^(munkalap|ugyfel|eszkoz):\d{1,12}$/', $kulcs) !== 1 || $hash === '') {
            wp_send_json_error(['uzenet' => 'A felülbírálás nincs beállítva.']);
        }

        $szamlalo = 'sdh_muhely_zar_proba_' . $user;
        $proba    = (int) get_transient($szamlalo);

        if ($proba >= self::MAX_PROBA) {
            wp_send_json_error(['uzenet' => 'Túl sok hibás kód. Próbáld újra 5 perc múlva.']);
        }

        if ($kod === '' || !wp_check_password($kod, $hash)) {
            set_transient($szamlalo, $proba + 1, 5 * MINUTE_IN_SECONDS);

            if (class_exists('SDH_Muhely_Valtozasnaplo')) {
                [$t, $i] = explode(':', $kulcs);
                SDH_Muhely_Valtozasnaplo::esemeny($t, (int) $i, 'Hibás felülbíráló kód a zárolt megnyitásához');
            }

            wp_send_json_error(['uzenet' => 'Hibás kód.']);
        }

        delete_transient($szamlalo);
        set_transient(self::engedely_kulcs($kulcs, $fiok, $user), 1, 60);

        wp_send_json_success(['ok' => true]);
    }

    private static function engedely_kulcs(string $kulcs, string $fiok, int $user): string
    {
        return 'sdh_muhely_zar_ok_' . md5($kulcs . '|' . $fiok . '|' . $user);
    }

    private static function felulir_engedely_fogyaszt(string $kulcs, string $fiok, int $user): bool
    {
        $t = self::engedely_kulcs($kulcs, $fiok, $user);

        if (get_transient($t)) {
            delete_transient($t);

            return true;
        }

        return false;
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
        $sorok = self::masok_modositasai($tipus, $id, $alap, SDH_Muhely_Szemely::ki());

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
     * „X is szerkeszti" – a közös pulzusra épül; a jelenlét ablakonként (fiok) tárolódik
     * ============================================================== */

    /**
     * A pulzus válaszába: ki tartja még nyitva ugyanazt, amit én (másik ablakban).
     *
     * A kérés `szerk` paramétere a nálam nyitott űrlapok („munkalap:12,ugyfel:3"), a `szerk_el` a most bezártak,
     * a `szerk_fiok` ennek az ablaknak az azonosítója. A válasz `szerkeszti` kulcsa: rekord => [nevek].
     *
     * @param array<string, mixed> $valasz
     * @return array<string, mixed>
     */
    public static function pulzus(array $valasz, int $user = 0): array
    {
        // Közös fiók (H2): a kiválasztott dolgozó nevén jelez.
        if ($user > 0 && $user === get_current_user_id()) {
            $user = SDH_Muhely_Szemely::ki();
        }

        $fiok = self::fiok();

        if ($user <= 0 || $fiok === '') {
            return $valasz;
        }

        $nyitott   = self::kulcsok((string) ($_REQUEST['szerk'] ?? '')); // phpcs:ignore WordPress.Security
        $elhagyott = self::kulcsok((string) ($_REQUEST['szerk_el'] ?? '')); // phpcs:ignore WordPress.Security

        foreach ($elhagyott as $k) {
            if (!in_array($k, $nyitott, true)) {
                self::jelenlet_torol($k, $fiok);
            }
        }

        $kik = [];

        foreach ($nyitott as $k) {
            self::jelenlet_frissit($k, $user, $fiok);

            $nev = array_map(static fn (array $m): string => $m[1], self::nyitva_masoknal($k, $user, $fiok));

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

    /** Saját jelenlét (ablak) rögzítése; a lejártak kimaradnak. */
    private static function jelenlet_frissit(string $kulcs, int $user, string $fiok): void
    {
        $mind        = self::jelenlet_olvas($kulcs);
        $mind[$fiok] = [$user, time()];

        set_transient(self::jelenlet_kulcs($kulcs), $mind, self::PULZUS_TTL * 2);
    }

    private static function jelenlet_torol(string $kulcs, string $fiok): void
    {
        $mind = self::jelenlet_olvas($kulcs);

        unset($mind[$fiok]);

        if ($mind === []) {
            delete_transient(self::jelenlet_kulcs($kulcs));

            return;
        }

        set_transient(self::jelenlet_kulcs($kulcs), $mind, self::PULZUS_TTL * 2);
    }

    /**
     * @return array<string, array{0: int, 1: int}> ablak => [felhasználó, utolsó pulzus ideje]
     */
    private static function jelenlet_olvas(string $kulcs): array
    {
        $mind  = get_transient(self::jelenlet_kulcs($kulcs));
        $ki    = [];
        $hatar = time() - self::PULZUS_TTL;

        foreach (is_array($mind) ? $mind : [] as $f => $adat) {
            if (is_array($adat) && isset($adat[0], $adat[1]) && (int) $adat[1] >= $hatar) {
                $ki[(string) $f] = [(int) $adat[0], (int) $adat[1]];
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
