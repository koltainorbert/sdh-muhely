<?php
/**
 * SMS-sablonok, státuszhoz kötött szabályok és a „küldjek SMS-t?” döntések (0.57).
 *
 * Három dolog él itt:
 *   1. Sablonok – szerkeszthető, verziózott szövegek {változókkal}. A gyári minták
 *      „PÉLDA – jóváhagyásra vár” jelzéssel, KIKAPCSOLVA kerülnek be: amíg valaki át nem
 *      olvasta és el nem mentette őket, nem aktiválhatók.
 *   2. Szabályok – státuszonként: melyik sablon, és mi történjen (ki / kérdez / automata).
 *   3. Döntések – egy státuszváltás nyomán nyíló „Küldjek SMS-t?” kérdés. A döntés az
 *      adatbázisban él (nem csak a böngészőben), ezért a „Később döntök” sem vész el.
 *
 * Alapelvek:
 *   - SMS-hiba sosem akadályozza meg a státuszváltást: minden kezelő elkap minden hibát.
 *   - Automata küldés csak akkor megy, ha a szöveg hiánytalanul kitölthető és van használható
 *     szám; különben a rendszer kérdez – így sosem megy ki félig üres üzenet.
 *   - Többeszközös lapon az eszköz saját státuszváltása számít (nem a lap összesített
 *     állapota), egyeszközös lapon a lapé.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Sms_Szabaly
{
    public const OPT_PELDAK = 'sdh_muhely_sms_peldak';
    public const SZOVEG_MAX = 480;

    /** A „Küldjek SMS-t?” kérdés ennyi ideig számít „friss”-nek a popuphoz (másodperc). */
    public const FRISS_MP = 600;

    public static function init(): void
    {
        add_action('sdh_muhely_munkalap_allapot', [self::class, 'lap_valtott'], 30, 3);
        add_action('sdh_muhely_munkalap_eszkoz_allapot', [self::class, 'eszkoz_valtott'], 30, 4);
        add_action('sdh_muhely_rendeles_allapot', [self::class, 'rendeles_valtott'], 30, 3);
        add_action('sdh_muhely_sema_frissult', [self::class, 'peldak_felvesz']);
    }

    private static function tabla(string $nev): string
    {
        return SDH_Muhely_Sms::tabla($nev);
    }

    /* =================================================================
     * Változók és kitöltés
     * ============================================================== */

    /** @return array<string, string> kulcs => magyarázat */
    public static function valtozok(): array
    {
        return [
            'ugyfel'   => 'Az ügyfél neve',
            'szam'     => 'A munkalap (rendelés) száma',
            'eszkoz'   => 'Az eszköz megnevezése',
            'allapot'  => 'Az új állapot neve',
            'szerviz'  => 'A szerviz neve (Beállítások › RMA)',
            'link'     => 'Az ügyféloldal linkje (állapotkövetés)',
            'hatarido' => 'A határidő',
            'osszeg'   => 'A munkalap bruttó értéke',
        ];
    }

    /** Az állapot megjelenített neve. */
    public static function allapot_nev(string $terulet, string $kulcs): string
    {
        $lista = $terulet === 'rendeles' && class_exists('SDH_Muhely_Eladas')
            ? SDH_Muhely_Eladas::rend_allapotok()
            : SDH_Muhely_Munkalap::allapotok();

        return isset($lista[$kulcs]['nev']) ? (string) $lista[$kulcs]['nev'] : $kulcs;
    }

    /**
     * A változók értékei egy laphoz (és eszközhöz). Ami nem állapítható meg, üres marad –
     * a kitöltés ezt „hiányzik”-ként jelzi.
     *
     * @return array<string, string>
     */
    public static function ertekek(int $munkalap_id, int $eszkoz_id = 0, string $terulet = 'lap', string $allapot = '', int $ugyfel_id = 0): array
    {
        $lap = SDH_Muhely_Rma::munkalap($munkalap_id);

        if (!$lap) {
            // Munkalap nélküli (pl. önálló) SMS: csak az ügyfél és a szerviz neve ismert.
            $u = $ugyfel_id > 0 ? SDH_Muhely_Rma::ugyfel($ugyfel_id) : null;

            return [
                'ugyfel'  => $u ? trim((string) $u->nev) : '',
                'szerviz' => trim((string) SDH_Muhely_Rma::beallitas()['szerviz_nev']),
            ];
        }

        $ugyfel = SDH_Muhely_Rma::ugyfel((int) $lap->ugyfel_id);
        $rma    = SDH_Muhely_Rma::beallitas();
        $szam   = (int) $lap->munkalap_szam > 0 ? SDH_Muhely_Munkalap::szam_formaz($lap->munkalap_szam) : '';

        $eszkoz_nev = '';

        if (!SDH_Muhely_Munkalap::eladas_e($lap)) {
            // Az eszkoz_bontas() számozott listát ad; a fő eszköz kulcsa 0, a csatoltaké az eszköz azonosítója.
            $kulcs = $eszkoz_id > 0 && $eszkoz_id !== (int) $lap->eszkoz_id ? $eszkoz_id : 0;

            foreach (SDH_Muhely_Munkalap::eszkoz_bontas($lap) as $b) {
                if ((int) $b['eszkoz_id'] === $kulcs && !empty($b['valodi_id'])) {
                    $eszkoz_nev = (string) $b['nev'];
                    break;
                }
            }
        }

        if ($allapot === '') {
            $allapot = $terulet === 'rendeles' ? (string) ($lap->rend_allapot ?? '') : (string) $lap->allapot;
        }

        $hatarido = (string) ($lap->hatarido ?? '');
        $hatarido = preg_match('/^\d{4}-\d{2}-\d{2}/', $hatarido) && strpos($hatarido, '0000') !== 0
            ? str_replace('-', '.', substr($hatarido, 0, 10)) . '.'
            : '';

        $osszeg = (float) ($lap->brutto_ertek ?? 0);
        $link   = '';

        // Az ügyféloldal linkje csak sorszámos lapnak van (a token ekkor jön létre).
        if ((int) $lap->munkalap_szam > 0) {
            $link = SDH_Muhely_Rma::url(SDH_Muhely_Rma::token($lap));
        }

        return [
            'ugyfel'   => $ugyfel ? trim((string) $ugyfel->nev) : '',
            'szam'     => $szam,
            'eszkoz'   => $eszkoz_nev,
            'allapot'  => self::allapot_nev($terulet, $allapot),
            'szerviz'  => trim((string) $rma['szerviz_nev']),
            'link'     => $link,
            'hatarido' => $hatarido,
            'osszeg'   => $osszeg > 0 ? number_format($osszeg, 0, ',', ' ') . ' Ft' : '',
        ];
    }

    /**
     * A sablon kitöltése. A `hianyzik` azokat a változókat sorolja fel, amelyek szerepelnek a
     * szövegben, de nincs értékük; az `ismeretlen` a nem létező változókat.
     *
     * @param array<string, string> $ertekek
     * @return array{szoveg: string, hianyzik: array<int, string>, ismeretlen: array<int, string>}
     */
    public static function kitolt(string $szoveg, array $ertekek): array
    {
        $hianyzik   = [];
        $ismeretlen = [];

        preg_match_all('/\{([a-z_]+)\}/', $szoveg, $m);

        foreach (array_unique($m[1]) as $nev) {
            if (!array_key_exists($nev, self::valtozok())) {
                $ismeretlen[] = $nev;
            } elseif (trim((string) ($ertekek[$nev] ?? '')) === '') {
                $hianyzik[] = $nev;
            }
        }

        $csere = [];

        foreach (self::valtozok() as $nev => $_) {
            $csere['{' . $nev . '}'] = trim((string) ($ertekek[$nev] ?? ''));
        }

        $ki = strtr($szoveg, $csere);

        // A sorvégek és a dupla szóközök (üres változó helye) rendezése.
        $ki = trim((string) preg_replace('/[ \t]{2,}/u', ' ', str_replace("\r\n", "\n", $ki)));

        return ['szoveg' => $ki, 'hianyzik' => array_values($hianyzik), 'ismeretlen' => array_values($ismeretlen)];
    }

    /* =================================================================
     * Sablonok
     * ============================================================== */

    /** @return array<int, object> */
    public static function sablonok(bool $csak_aktiv = false): array
    {
        global $wpdb;

        $sorok = $wpdb->get_results(
            'SELECT * FROM ' . self::tabla('sablon') . ($csak_aktiv ? ' WHERE aktiv = 1' : '') . ' ORDER BY aktiv DESC, nev ASC'
        );

        return is_array($sorok) ? $sorok : [];
    }

    public static function sablon(int $id): ?object
    {
        global $wpdb;

        $s = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla('sablon') . ' WHERE id = %d', $id)) : null;

        return is_object($s) ? $s : null;
    }

    /** @return array<int, object> a sablon korábbi változatai, újabb elöl */
    public static function verziok(int $sablon_id): array
    {
        global $wpdb;

        $v = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::tabla('sablon_verzio') . ' WHERE sablon_id = %d ORDER BY verzio DESC', $sablon_id));

        return is_array($v) ? $v : [];
    }

    private static function egyedi_kulcs(string $nev): string
    {
        global $wpdb;

        $alap  = sanitize_title($nev);
        $alap  = $alap !== '' ? substr($alap, 0, 50) : 'sablon';
        $kulcs = $alap;
        $i     = 2;

        while ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::tabla('sablon') . ' WHERE kulcs = %s', $kulcs)) > 0) {
            $kulcs = $alap . '-' . $i++;
        }

        return $kulcs;
    }

    /**
     * Sablon létrehozása vagy módosítása. A szöveg minden változása új verzió (a régi a
     * verziótáblában megmarad); a már elküldött SMS-ek szövege és sablonverziója érintetlen.
     *
     * @param array<string, mixed> $adat nev, szoveg, aktiv
     * @return array{ok: bool, id: int, uzenet: string}
     */
    public static function sablon_ment(array $adat, int $id = 0): array
    {
        global $wpdb;

        $nev    = mb_substr(trim(sanitize_text_field((string) ($adat['nev'] ?? ''))), 0, 120);
        $szoveg = trim(str_replace("\r\n", "\n", wp_strip_all_tags((string) ($adat['szoveg'] ?? ''), false)));
        $aktiv  = !empty($adat['aktiv']) ? 1 : 0;
        $hiba   = static fn (string $u): array => ['ok' => false, 'id' => $id, 'uzenet' => $u];

        if ($nev === '') {
            return $hiba('A sablon nevét add meg.');
        }

        if ($szoveg === '') {
            return $hiba('A sablon szövege nem lehet üres.');
        }

        if (mb_strlen($szoveg) > self::SZOVEG_MAX) {
            return $hiba('A sablon szövege legfeljebb ' . self::SZOVEG_MAX . ' karakter lehet.');
        }

        preg_match_all('/\{([a-z_]+)\}/', $szoveg, $m);
        $rossz = array_values(array_diff(array_unique($m[1]), array_keys(self::valtozok())));

        if ($rossz !== []) {
            return $hiba('Ismeretlen változó a szövegben: {' . implode('}, {', $rossz) . '}.');
        }

        $most = current_time('mysql');
        $user = get_current_user_id();

        if ($id <= 0) {
            $ok = $wpdb->insert(self::tabla('sablon'), [
                'kulcs' => self::egyedi_kulcs($nev), 'nev' => $nev, 'szoveg' => $szoveg, 'aktiv' => $aktiv,
                'pelda' => 0, 'verzio' => 1, 'letrehozva' => $most, 'modositva' => $most, 'modosito' => $user,
            ]);

            if ($ok === false) {
                return $hiba('A sablont nem sikerült elmenteni.');
            }

            $id = (int) $wpdb->insert_id;
            self::verzio_ir($id, 1, $nev, $szoveg);

            return ['ok' => true, 'id' => $id, 'uzenet' => 'A sablon létrejött.'];
        }

        $regi = self::sablon($id);

        if (!$regi) {
            return $hiba('Nincs ilyen sablon.');
        }

        $verzio = (int) $regi->verzio;

        if ($szoveg !== (string) $regi->szoveg || $nev !== (string) $regi->nev) {
            $verzio++;
            self::verzio_ir($id, $verzio, $nev, $szoveg);
        }

        // A mentés a minta „jóváhagyása”: onnantól élesíthető.
        $wpdb->update(self::tabla('sablon'), [
            'nev' => $nev, 'szoveg' => $szoveg, 'aktiv' => $aktiv, 'pelda' => 0,
            'verzio' => $verzio, 'modositva' => $most, 'modosito' => $user,
        ], ['id' => $id]);

        // Kikapcsolt sablonhoz kötött szabály nem küldhet: a kapcsoló a szabályokat is „leállítja”
        // (a szabály megmarad, a keresés csak aktív sablont ad vissza).
        return ['ok' => true, 'id' => $id, 'uzenet' => $verzio > (int) $regi->verzio ? 'A sablon elmentve (új verzió: ' . $verzio . '.).' : 'A sablon elmentve.'];
    }

    private static function verzio_ir(int $sablon_id, int $verzio, string $nev, string $szoveg): void
    {
        global $wpdb;

        $wpdb->insert(self::tabla('sablon_verzio'), [
            'sablon_id' => $sablon_id, 'verzio' => $verzio, 'nev' => $nev, 'szoveg' => $szoveg,
            'felhasznalo' => get_current_user_id(), 'letrehozva' => current_time('mysql'),
        ]);
    }

    /**
     * A gyári minta-sablonok: KIKAPCSOLVA, „PÉLDA – jóváhagyásra vár” jelzéssel. Egyszer kerülnek
     * be; ha valaki törölte vagy átírta őket, nem jönnek vissza.
     */
    public static function peldak_felvesz(): void
    {
        global $wpdb;

        if (get_option(self::OPT_PELDAK) !== false) {
            return;
        }

        $peldak = [
            ['felvett', 'Készülék átvéve', '{szerviz}: átvettük a(z) {eszkoz} készüléket ({szam} munkalap). Az állapotát itt követheti: {link}'],
            ['arajanlat', 'Árajánlat kész', '{szerviz}: a(z) {szam} munkalaphoz árajánlatunk készült ({osszeg}). Kérjük, jelezzen vissza, hogy elkezdhetjük-e a munkát.'],
            ['kesz', 'Elkészült a javítás', '{szerviz}: a(z) {eszkoz} javítása elkészült ({szam} munkalap), átvehető. Köszönjük!'],
            ['rendeles_megerkezett', 'Megérkezett a rendelés', '{szerviz}: megérkezett a rendelt termék ({szam}), átvehető nyitvatartási időben.'],
        ];

        $most = current_time('mysql');

        foreach ($peldak as [$kulcs, $nev, $szoveg]) {
            if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::tabla('sablon') . ' WHERE kulcs = %s', $kulcs)) > 0) {
                continue;
            }

            $wpdb->insert(self::tabla('sablon'), [
                'kulcs' => $kulcs, 'nev' => $nev, 'szoveg' => $szoveg, 'aktiv' => 0, 'pelda' => 1,
                'verzio' => 1, 'letrehozva' => $most, 'modositva' => $most, 'modosito' => 0,
            ]);

            self::verzio_ir((int) $wpdb->insert_id, 1, $nev, $szoveg);
        }

        update_option(self::OPT_PELDAK, current_time('mysql'), false);
    }

    /* =================================================================
     * Szabályok (státusz → sablon + működés)
     * ============================================================== */

    /** @return array<string, string> */
    public static function mukodesek(): array
    {
        return ['ki' => 'Nem küld', 'kerdez' => 'Rákérdez (popup)', 'auto' => 'Automatikusan küld'];
    }

    /** @return array<string, object> "terulet|allapot" => szabály */
    public static function szabalyok(): array
    {
        global $wpdb;

        $ki = [];

        foreach ((array) $wpdb->get_results('SELECT * FROM ' . self::tabla('szabaly') . ' ORDER BY id') as $sz) {
            $ki[$sz->terulet . '|' . $sz->allapot] = $sz;
        }

        return $ki;
    }

    /** Az érvényes szabály (aktív sablonnal, nem „ki” működéssel) – vagy null. */
    public static function szabaly(string $terulet, string $allapot): ?object
    {
        global $wpdb;

        $sz = $wpdb->get_row($wpdb->prepare(
            'SELECT sz.*, sa.szoveg AS sablon_szoveg, sa.verzio AS sablon_verzio FROM ' . self::tabla('szabaly') . ' sz INNER JOIN ' . self::tabla('sablon')
            . ' sa ON sa.id = sz.sablon_id WHERE sz.terulet = %s AND sz.allapot = %s AND sa.aktiv = 1 AND sz.mukodes <> %s ORDER BY sz.id LIMIT 1',
            $terulet,
            $allapot,
            'ki'
        ));

        return is_object($sz) ? $sz : null;
    }

    /**
     * A szabályok mentése egy területre (a beküldött lista felülírja a régit).
     *
     * @param array<string, array<string, mixed>> $sorok allapot => [sablon_id, mukodes, kesleltetes]
     */
    public static function szabalyok_ment(string $terulet, array $sorok): int
    {
        global $wpdb;

        $terulet = $terulet === 'rendeles' ? 'rendeles' : 'lap';
        $allapotok = $terulet === 'rendeles' && class_exists('SDH_Muhely_Eladas')
            ? SDH_Muhely_Eladas::rend_allapotok()
            : SDH_Muhely_Munkalap::allapotok();
        $db = 0;

        $wpdb->delete(self::tabla('szabaly'), ['terulet' => $terulet]);

        foreach ($sorok as $allapot => $s) {
            $allapot   = (string) $allapot;
            $sablon_id = (int) ($s['sablon_id'] ?? 0);
            $mukodes   = (string) ($s['mukodes'] ?? 'ki');

            if (!isset($allapotok[$allapot]) || $sablon_id <= 0 || self::sablon($sablon_id) === null || !isset(self::mukodesek()[$mukodes]) || $mukodes === 'ki') {
                continue;
            }

            $wpdb->insert(self::tabla('szabaly'), [
                'terulet' => $terulet, 'allapot' => $allapot, 'sablon_id' => $sablon_id, 'alap' => 1,
                'mukodes' => $mukodes, 'kesleltetes' => max(0, min(10080, (int) ($s['kesleltetes'] ?? 0))),
                'fajta' => 'mind', 'feltetel' => '',
            ]);
            $db++;
        }

        return $db;
    }

    /* =================================================================
     * Státuszváltás → döntés
     * ============================================================== */

    private static function tobb_eszkoz(object $lap): bool
    {
        return count(SDH_Muhely_Munkalap::eszkoz_bontas($lap)) > 1;
    }

    public static function lap_valtott($id, $regi, $uj): void
    {
        try {
            $lap = SDH_Muhely_Rma::munkalap((int) $id);

            if (!$lap || SDH_Muhely_Munkalap::eladas_e($lap) || self::tobb_eszkoz($lap)) {
                return;
            }

            self::kezel('lap', $lap, 0, (string) $regi, (string) $uj);
        } catch (Throwable $e) {
            self::hiba_jegyez($e);
        }
    }

    public static function eszkoz_valtott($id, $eszkoz_id, $elozo, $uj): void
    {
        try {
            $lap = SDH_Muhely_Rma::munkalap((int) $id);

            if (!$lap || SDH_Muhely_Munkalap::eladas_e($lap) || !self::tobb_eszkoz($lap)) {
                return;
            }

            self::kezel('lap', $lap, (int) $eszkoz_id, (string) $elozo, (string) $uj);
        } catch (Throwable $e) {
            self::hiba_jegyez($e);
        }
    }

    public static function rendeles_valtott($id, $regi, $uj): void
    {
        try {
            $lap = SDH_Muhely_Rma::munkalap((int) $id);

            if (!$lap || (string) ($lap->fajta ?? '') !== 'rendeles') {
                return;
            }

            self::kezel('rendeles', $lap, 0, (string) $regi, (string) $uj);
        } catch (Throwable $e) {
            self::hiba_jegyez($e);
        }
    }

    private static function hiba_jegyez(Throwable $e): void
    {
        SDH_Muhely_Sms::allapot_ment(['szabaly_hiba' => mb_substr($e->getMessage(), 0, 200), 'szabaly_hiba_ido' => current_time('mysql')]);
    }

    /**
     * A státuszváltás kezelése: szabály keresése, és ha van, döntés létrehozása (kérdez) vagy
     * azonnali sorba tétel (automata). Visszaadja a döntés azonosítóját, 0 ha nincs teendő.
     */
    public static function kezel(string $terulet, object $lap, int $eszkoz_id, string $regi, string $uj): int
    {
        global $wpdb;

        if (!SDH_Muhely_Sms::bekapcsolva() || (int) $lap->ugyfel_id <= 0 || $regi === $uj) {
            return 0;
        }

        $sz = self::szabaly($terulet, $uj);

        if (!$sz) {
            return 0;
        }

        // Ugyanarra a változásra egy nyitott kérdés elég.
        $van = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::tabla('dontes') . ' WHERE munkalap_id = %d AND eszkoz_id = %d AND terulet = %s AND allapot = %s AND lezarva IS NULL LIMIT 1',
            (int) $lap->id,
            $eszkoz_id,
            $terulet,
            $uj
        ));

        if ($van > 0) {
            return $van;
        }

        $wpdb->insert(self::tabla('dontes'), [
            'munkalap_id' => (int) $lap->id, 'eszkoz_id' => $eszkoz_id, 'terulet' => $terulet, 'allapot' => $uj,
            'elozo' => $regi, 'sablon_id' => (int) $sz->sablon_id, 'dontes' => 'fuggo',
            'felhasznalo' => get_current_user_id(), 'letrehozva' => current_time('mysql'),
        ]);

        $id = (int) $wpdb->insert_id;

        if ($id > 0 && $sz->mukodes === 'auto') {
            self::automata($id, $sz);
        }

        return $id;
    }

    /** Automata küldés – csak ha minden hiánytalan; különben a döntés nyitva marad (rákérdezés). */
    private static function automata(int $dontes_id, object $sz): void
    {
        global $wpdb;

        $d = self::dontes($dontes_id);

        if (!$d) {
            return;
        }

        $k = self::kitolt((string) $sz->sablon_szoveg, self::ertekek((int) $d->munkalap_id, (int) $d->eszkoz_id, (string) $d->terulet, (string) $d->allapot));

        if ($k['hianyzik'] !== [] || $k['ismeretlen'] !== []) {
            $wpdb->update(self::tabla('dontes'), ['ok' => 'Automata küldés nem lehetséges: hiányzó adat (' . implode(', ', $k['hianyzik']) . ').'], ['id' => $dontes_id]);

            return;
        }

        $lap = SDH_Muhely_Rma::munkalap((int) $d->munkalap_id);
        $v   = SDH_Muhely_Sms::sor_be([
            'ugyfel_id' => (int) ($lap->ugyfel_id ?? 0), 'munkalap_id' => (int) $d->munkalap_id, 'eszkoz_id' => (int) $d->eszkoz_id,
            'szoveg' => $k['szoveg'], 'kivalto' => 'auto', 'sablon_id' => (int) $sz->sablon_id, 'sablon_verzio' => (int) $sz->sablon_verzio,
            'kesleltetes' => (int) $sz->kesleltetes, 'kulcs' => 'dontes:' . $dontes_id,
        ]);

        if ($v['ok']) {
            $wpdb->update(self::tabla('dontes'), ['dontes' => 'auto', 'sms_id' => $v['id'], 'lezarva' => current_time('mysql'), 'ok' => ''], ['id' => $dontes_id]);

            return;
        }

        $wpdb->update(self::tabla('dontes'), ['ok' => mb_substr($v['uzenet'], 0, 255)], ['id' => $dontes_id]);
    }

    /* =================================================================
     * Döntések
     * ============================================================== */

    public static function dontes(int $id): ?object
    {
        global $wpdb;

        $d = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla('dontes') . ' WHERE id = %d', $id)) : null;

        return is_object($d) ? $d : null;
    }

    /** A nyitott („függő”) döntések száma. */
    public static function fuggo_db(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::tabla('dontes') . ' WHERE lezarva IS NULL');
    }

    /** @return array<int, object> */
    public static function fuggo_lista(int $limit = 100): array
    {
        global $wpdb;

        $l = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::tabla('dontes') . ' WHERE lezarva IS NULL ORDER BY id DESC LIMIT %d', $limit));

        return is_array($l) ? $l : [];
    }

    /** A belépett felhasználó legfrissebb nyitott döntése a popuphoz (vagy 0). */
    public static function friss_sajat(): int
    {
        global $wpdb;

        $hatar = wp_date('Y-m-d H:i:s', time() - self::FRISS_MP);

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::tabla('dontes') . ' WHERE lezarva IS NULL AND felhasznalo = %d AND letrehozva > %s ORDER BY id DESC LIMIT 1',
            get_current_user_id(),
            $hatar
        ));
    }

    /**
     * Válasz a „Küldjek SMS-t?” kérdésre.
     *
     * @param string $valasz kuld | nem | kesobb
     * @return array{ok: bool, uzenet: string, sms_id: int}
     */
    public static function dontes_lezar(int $id, string $valasz, string $szoveg = '', string $szam = ''): array
    {
        global $wpdb;

        $d = self::dontes($id);

        if (!$d) {
            return ['ok' => false, 'uzenet' => 'Nincs ilyen kérdés.', 'sms_id' => 0];
        }

        if ($d->lezarva !== null) {
            return ['ok' => true, 'uzenet' => 'Erről már döntöttek.', 'sms_id' => (int) $d->sms_id];
        }

        if ($valasz === 'kesobb') {
            $wpdb->update(self::tabla('dontes'), ['ok' => 'Később döntök'], ['id' => $id]);

            return ['ok' => true, 'uzenet' => 'Rendben, az SMS-döntés függőben marad.', 'sms_id' => 0];
        }

        if ($valasz === 'nem') {
            $wpdb->update(self::tabla('dontes'), ['dontes' => 'nem', 'lezarva' => current_time('mysql'), 'ok' => '', 'felhasznalo' => get_current_user_id()], ['id' => $id]);

            return ['ok' => true, 'uzenet' => 'Nem küldünk SMS-t.', 'sms_id' => 0];
        }

        if ($valasz !== 'kuld') {
            return ['ok' => false, 'uzenet' => 'Ismeretlen válasz.', 'sms_id' => 0];
        }

        $lap = SDH_Muhely_Rma::munkalap((int) $d->munkalap_id);

        if (!$lap) {
            return ['ok' => false, 'uzenet' => 'A munkalap időközben megszűnt.', 'sms_id' => 0];
        }

        $szoveg = trim($szoveg);

        if (preg_match('/\{[a-z_]+\}/', $szoveg)) {
            return ['ok' => false, 'uzenet' => 'A szövegben kitöltetlen {változó} maradt – javítsd, mielőtt elküldöd.', 'sms_id' => 0];
        }

        $sablon = self::sablon((int) $d->sablon_id);
        $v      = SDH_Muhely_Sms::sor_be([
            'ugyfel_id' => (int) $lap->ugyfel_id, 'munkalap_id' => (int) $d->munkalap_id, 'eszkoz_id' => (int) $d->eszkoz_id,
            'szoveg' => $szoveg, 'kivalto' => 'allapot', 'sablon_id' => (int) $d->sablon_id, 'sablon_verzio' => $sablon ? (int) $sablon->verzio : 0,
            'kulcs' => 'dontes:' . $id, 'szam' => $szam,
        ]);

        if (!$v['ok']) {
            return ['ok' => false, 'uzenet' => $v['uzenet'], 'sms_id' => (int) $v['id']];
        }

        $wpdb->update(self::tabla('dontes'), [
            'dontes' => 'kuldve', 'sms_id' => (int) $v['id'], 'lezarva' => current_time('mysql'), 'ok' => '', 'felhasznalo' => get_current_user_id(),
        ], ['id' => $id]);

        return ['ok' => true, 'uzenet' => $v['uzenet'], 'sms_id' => (int) $v['id']];
    }
}
