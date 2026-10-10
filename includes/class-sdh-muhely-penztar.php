<?php
/**
 * Házipénztár – a napi kassza (a régi „Zárás" Excel-tábla utódja).
 *
 * Egy tétel = egy pénzmozgás: bevétel (KP / bankkártya / utalás), kivét
 * (mindig negatív, névvel), kifizetés (beszállító, posta…), befizetés
 * (váltópénz). A kassza egyenlege a KP-oszlop összege; a kártya és az
 * utalás csak a forgalomban látszik.
 *
 * Napok: minden nap automatikusan nyílik (a nyitó összeg az előző nap
 * záró készpénze – a megszámolt, ha volt számolás). A „KP" lapon a
 * kasszát címletenként megszámolod, a rendszer összeveti a várható
 * összeggel, és zárja a napot. Az összesítők a sdh_penztar_nap táblában
 * állnak (gyorsítótár), ezért a teljes napló és a statisztika is azonnali.
 *
 * Kapcsolatok: a munkalap Fizetés gombja és a „Fizetve" pipa felajánlja a
 * pénztárba írást; a kiállított számla száma magától beíródik a munkalap
 * pénztártételeibe. Az egyeztető ügynök (SDH_Muhely_Penztar_Ugynok) a
 * munkalapok és a számlák alapján keresi, mi hiányzik vagy mi van rosszul.
 *
 * Semmi nem törlődik véglegesen: a törlés jelölés, minden változás
 * a változásnaplóba kerül (ki, mikor, mi volt előtte).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Penztar
{
    public const KULCS = 'penztar';

    private const OPTION = 'sdh_muhely_penztar';

    /** A tételtípusok magyar neve. */
    public const TIPUSOK = [
        'bevetel'   => 'Bevétel',
        'kivet'     => 'Kivét',
        'kifizetes' => 'Kifizetés',
        'befizetes' => 'Befizetés',
        'kihagyva'  => 'Nem került kasszába',
    ];

    /** A napló egy oldalán ennyi nap. */
    private const NAPLO_NAP = 7;

    public static function init(): void
    {
        $muveletek = [
            'adat'       => 'ajax_adat',
            'naplo'      => 'ajax_naplo',
            'ment'       => 'ajax_ment',
            'gyors'      => 'ajax_gyors',
            'torol'      => 'ajax_torol',
            'szamolas'   => 'ajax_szamolas',
            'ujranyit'   => 'ajax_ujranyit',
            'munkalap'   => 'ajax_munkalap',
            'fizet'      => 'ajax_fizet',
            'kihagy'     => 'ajax_kihagy',
            'ajanlat'    => 'ajax_ajanlat',
            'valtozasok' => 'ajax_valtozasok',
            'javit'      => 'ajax_javit',
            'jelol'      => 'ajax_jelol',
        ];

        foreach ($muveletek as $nev => $fuggveny) {
            add_action('wp_ajax_sdh_muhely_penztar_' . $nev, [self::class, $fuggveny]);
        }

        // Az app.js popupja „sdh_muhely_<modul>_urlap" néven kéri az űrlapot.
        add_action('wp_ajax_sdh_muhely_penztar_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_penztarfizetes_urlap', [self::class, 'ajax_fizetes_urlap']);

        add_action('admin_enqueue_scripts', [self::class, 'admin_eszkozok'], 21);
        add_action('sdh_muhely_szamla_kiallitva', [self::class, 'szamla_kiallitva'], 10, 3);

        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Pénztár',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 31,
        ]);
    }

    /* =================================================================
     * Táblák, beállítás, segédek
     * ============================================================== */

    public static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('penztar');
    }

    public static function nap_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('penztar_nap');
    }

    public static function naplo_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('penztar_naplo');
    }

    /**
     * @return array{szemelyek: array<int, array{nev: string, alias: array<int, string>}>, cimletek: array<int, int>, kp_modok: array<int, string>, kartya_modok: array<int, string>, ajanlat: bool, kategoriak: string}
     */
    public static function beallitas(): array
    {
        $b = get_option(self::OPTION, []);
        $b = is_array($b) ? $b : [];

        $szemely_szoveg = isset($b['szemelyek']) && is_string($b['szemelyek']) && trim($b['szemelyek']) !== ''
            ? $b['szemelyek']
            : "Koltai Norbert | Norbi, Norbert, Koltai\nLégman Péter | Peti, Péter, Légman";

        $szemelyek = [];

        foreach (preg_split('/\R/u', $szemely_szoveg) ?: [] as $sor) {
            $reszek = array_map('trim', explode('|', $sor, 2));
            $nev    = mb_substr(sanitize_text_field($reszek[0] ?? ''), 0, 80);

            if ($nev === '') {
                continue;
            }

            $alias = [];

            foreach (explode(',', (string) ($reszek[1] ?? '')) as $a) {
                $a = trim(sanitize_text_field($a));

                if ($a !== '') {
                    $alias[] = $a;
                }
            }

            $szemelyek[] = ['nev' => $nev, 'alias' => $alias];
        }

        $cimletek = [];

        foreach (preg_split('/[\s,;]+/', (string) ($b['cimletek'] ?? '')) ?: [] as $c) {
            $c = (int) $c;

            if ($c > 0 && !in_array($c, $cimletek, true)) {
                $cimletek[] = $c;
            }
        }

        if ($cimletek === []) {
            $cimletek = [20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5];
        }

        rsort($cimletek);

        $lista = static function ($ertek, string $alap): array {
            $szoveg = is_string($ertek) && trim($ertek) !== '' ? $ertek : $alap;
            $ki     = [];

            foreach (preg_split('/\R|,/u', $szoveg) ?: [] as $s) {
                $s = trim(sanitize_text_field($s));

                if ($s !== '') {
                    $ki[] = $s;
                }
            }

            return $ki;
        };

        return [
            'szemelyek'    => $szemelyek,
            'szemely_szoveg' => $szemely_szoveg,
            'cimletek'     => $cimletek,
            'kp_modok'     => $lista($b['kp_modok'] ?? '', "Készpénz\nHalasztott KP"),
            'kartya_modok' => $lista($b['kartya_modok'] ?? '', "Bankkártya\nSZÉP Kártya"),
            'ajanlat'      => !isset($b['ajanlat']) || !empty($b['ajanlat']),
            'kategoriak'   => isset($b['kategoriak']) && is_string($b['kategoriak']) && trim($b['kategoriak']) !== ''
                ? $b['kategoriak']
                : self::alap_kategoriak(),
        ];
    }

    /** A statisztika bevételi kategóriái: „Név: kulcsszó, kulcsszó" – az első egyező nyer. */
    public static function alap_kategoriak(): string
    {
        return implode("\n", [
            'Bevizsgálás: bevizsg',
            'Kijelzőcsere: kijelz, lcd, display, üveg csere, panel csere',
            'Akkumulátor: akku, akkum',
            'Töltés, csatlakozó: töltő, tolto, usb, csatlakoz, aljzat',
            'Szoftver, adatmentés: szoftver, frissít, beállít, norton, vírus, adatment, reset',
            'Tok, fólia, üveg: tok, fólia, folia, üveg, uveg',
            'Kábel, kiegészítő: kábel, kabel, adapter, fülhallgató, memóriakártya, pendrive, elem',
            'Készülékeladás: telefon sm-, készülék, mobiltelefon',
            'Háztartási gép: porszív, mosógép, mosogep, porzsák, szűrő, hepa',
            'TV, szórakoztató: tv, televízió, távirányító, taviranyito',
            'Javítás (egyéb): javít, csere, szerv',
        ]);
    }

    /** @return array<int, array{nev: string, szavak: array<int, string>}> */
    public static function kategoriak(): array
    {
        $ki = [];

        foreach (preg_split('/\R/u', self::beallitas()['kategoriak']) ?: [] as $sor) {
            $reszek = explode(':', $sor, 2);
            $nev    = trim((string) $reszek[0]);

            if ($nev === '' || !isset($reszek[1])) {
                continue;
            }

            $szavak = array_values(array_filter(array_map(
                static fn ($s) => mb_strtolower(trim($s), 'UTF-8'),
                explode(',', $reszek[1])
            )));

            if ($szavak !== []) {
                $ki[] = ['nev' => mb_substr($nev, 0, 60), 'szavak' => $szavak];
            }
        }

        return $ki;
    }

    public static function ma(): string
    {
        return current_time('Y-m-d');
    }

    public static function penz(float $osszeg): string
    {
        $kerek = round($osszeg);

        return ($kerek < 0 ? '−' : '') . number_format(abs($kerek), 0, ',', "\u{202F}") . "\u{00A0}Ft";
    }

    /** Szövegből pénzösszeg: „12 000", „12.000 Ft", „-5000" mind jó. */
    public static function szam($ertek): float
    {
        if (is_int($ertek) || is_float($ertek)) {
            return (float) $ertek;
        }

        $s = str_replace(["\u{202F}", "\u{00A0}", ' ', 'Ft', 'ft', 'HUF'], '', (string) $ertek);
        $s = str_replace(['−', '–'], '-', $s);

        // Ezres pont (12.000) vagy tizedesvessző (12,5).
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
        }

        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : 0.0;
    }

    private static function datum_ervenyes(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    /** A fizetési mód melyik oszlopba kerül: kp, kartya vagy utalas. */
    public static function mod_oszlop(string $fizetesi_mod): string
    {
        $b = self::beallitas();
        $f = mb_strtolower(trim($fizetesi_mod), 'UTF-8');

        if ($f === '') {
            return 'kp';
        }

        foreach ($b['kp_modok'] as $m) {
            if (mb_strtolower($m, 'UTF-8') === $f) {
                return 'kp';
            }
        }

        foreach ($b['kartya_modok'] as $m) {
            if (mb_strtolower($m, 'UTF-8') === $f) {
                return 'kartya';
            }
        }

        if (strpos($f, 'kártya') !== false || strpos($f, 'kartya') !== false) {
            return 'kartya';
        }

        if (strpos($f, 'készpénz') !== false || $f === 'kp') {
            return 'kp';
        }

        return 'utalas';
    }

    /** A szöveg melyik kivét-személyre utal (név vagy becenév), különben üres. */
    public static function szemely_felismer(string $szoveg): string
    {
        $s = mb_strtolower($szoveg, 'UTF-8');

        foreach (self::beallitas()['szemelyek'] as $sz) {
            foreach (array_merge([$sz['nev']], $sz['alias']) as $nev) {
                $n = mb_strtolower($nev, 'UTF-8');

                if ($n !== '' && preg_match('/(^|[^\p{L}])' . preg_quote($n, '/') . '/u', $s)) {
                    return $sz['nev'];
                }
            }
        }

        return '';
    }

    private static function jog(bool $json = true): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            if ($json) {
                wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
            }

            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }
    }

    /** @return mixed */
    private static function post(string $nev, $alap = '')
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a hívó (jog()) ellenőrizte.
        return isset($_POST[$nev]) ? wp_unslash($_POST[$nev]) : $alap;
    }

    private static function post_szoveg(string $nev, int $max = 255): string
    {
        $v = self::post($nev, '');

        return is_scalar($v) ? mb_substr(trim(sanitize_text_field((string) $v)), 0, $max) : '';
    }

    private static function felhasznalo_nev(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $u = get_userdata($id);

        return $u ? (string) ($u->display_name ?: $u->user_login) : '';
    }

    /* =================================================================
     * Tételek
     * ============================================================== */

    public static function tetel(int $id): ?object
    {
        global $wpdb;

        $sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_object($sor) ? $sor : null;
    }

    /** Egy tétel a böngészőnek. */
    public static function tetel_kifele(object $t): array
    {
        return [
            'id'      => (int) $t->id,
            'datum'   => (string) $t->datum,
            'ido'     => $t->ido ? substr((string) $t->ido, 11, 5) : '',
            'tipus'   => (string) $t->tipus,
            'leiras'  => (string) $t->leiras,
            'nev'     => (string) $t->nev,
            'szemely' => (string) $t->szemely,
            'kp'      => (float) $t->kp,
            'kartya'  => (float) $t->kartya,
            'utalas'  => (float) $t->utalas,
            'info'    => (float) $t->info_osszeg,
            'ml'      => (string) $t->munkalap_szam,
            'ml_id'   => (int) $t->munkalap_id,
            'szamla'  => (string) $t->szamlaszam,
            'vonalkod' => (string) $t->vonalkod,
            'megj'    => (string) $t->megjegyzes,
            'forras'  => (string) $t->forras,
            'jelolt'  => (int) ($t->jelolt ?? 0) === 1,
            'torolve' => (int) $t->torolve === 1,
        ];
    }

    private static function naploz(int $tetel_id, ?string $datum, string $muvelet, $regi, $uj): void
    {
        global $wpdb;

        $wpdb->insert(self::naplo_tabla(), [
            'tetel_id'    => $tetel_id,
            'datum'       => $datum,
            'muvelet'     => mb_substr($muvelet, 0, 20),
            'regi'        => $regi === null ? null : (string) wp_json_encode($regi, JSON_UNESCAPED_UNICODE),
            'uj'          => $uj === null ? null : (string) wp_json_encode($uj, JSON_UNESCAPED_UNICODE),
            'felhasznalo' => get_current_user_id(),
            'ido'         => current_time('mysql'),
        ]);
    }

    /**
     * Egy tétel adatai a bevitelből, ellenőrizve. A kivét és a kifizetés
     * összege mindig negatív a kp oszlopban, akármilyen előjellel írták be.
     *
     * @param array<string, mixed> $be
     * @return array<string, mixed>|string Hiba esetén a hibaüzenet.
     */
    public static function adat_ellenoriz(array $be)
    {
        $tipus = (string) ($be['tipus'] ?? 'bevetel');

        if (!isset(self::TIPUSOK[$tipus])) {
            $tipus = 'bevetel';
        }

        $szoveg = static fn (string $k, int $max) => mb_substr(trim(sanitize_text_field((string) ($be[$k] ?? ''))), 0, $max);

        $datum = (string) ($be['datum'] ?? '');
        $datum = self::datum_ervenyes($datum) ? $datum : self::ma();

        if ($datum > self::ma()) {
            return 'Jövőbeli napra nem lehet tételt írni.';
        }

        $kp     = round(self::szam($be['kp'] ?? 0), 2);
        $kartya = round(self::szam($be['kartya'] ?? 0), 2);
        $utalas = round(self::szam($be['utalas'] ?? 0), 2);
        $info   = round(self::szam($be['info'] ?? 0), 2);

        $adat = [
            'datum'         => $datum,
            'tipus'         => $tipus,
            'leiras'        => $szoveg('leiras', 255),
            'nev'           => $szoveg('nev', 190),
            'szemely'       => $szoveg('szemely', 80),
            'kp'            => $kp,
            'kartya'        => $kartya,
            'utalas'        => $utalas,
            'info_osszeg'   => $info,
            'munkalap_szam' => preg_replace('/\s+/', ' ', $szoveg('ml', 40)),
            'szamlaszam'    => $szoveg('szamla', 60),
            'vonalkod'      => $szoveg('vonalkod', 190),
            'megjegyzes'    => $szoveg('megj', 255),
        ];

        if ($tipus === 'kivet' || $tipus === 'kifizetes') {
            $osszeg = abs($kp) > 0 ? abs($kp) : abs($kartya + $utalas);

            if ($osszeg <= 0) {
                return 'Add meg az összeget.';
            }

            $adat['kp']     = -$osszeg;
            $adat['kartya'] = 0;
            $adat['utalas'] = 0;
        } elseif ($tipus === 'befizetes') {
            if (abs($kp) <= 0) {
                return 'Add meg a befizetett összeget.';
            }

            $adat['kp'] = abs($kp);
            $adat['kartya'] = 0;
            $adat['utalas'] = 0;
        } elseif ($tipus === 'bevetel') {
            if ($kp == 0 && $kartya == 0 && $utalas == 0) {
                return 'Írj be összeget (KP, kártya vagy utalás).';
            }
        }

        if ($tipus === 'kivet') {
            $nevek = array_column(self::beallitas()['szemelyek'], 'nev');

            if ($adat['szemely'] === '') {
                $adat['szemely'] = self::szemely_felismer($adat['leiras'] . ' ' . $adat['nev']);
            }

            if ($adat['szemely'] === '' || ($nevek !== [] && !in_array($adat['szemely'], $nevek, true))) {
                return 'A kivétnél válaszd ki, ki vette ki a pénzt.';
            }

            $adat['nev'] = $adat['szemely'];

            if ($adat['leiras'] === '') {
                $adat['leiras'] = 'Pénz kivét';
            }
        } else {
            $adat['szemely'] = $tipus === 'kifizetes' ? $adat['szemely'] : '';
        }

        if ($tipus === 'kifizetes' && $adat['leiras'] === '' && $adat['nev'] !== '') {
            $adat['leiras'] = $adat['nev'];
        }

        if ($adat['leiras'] === '') {
            return 'Írd be, mi történt (leírás).';
        }

        // A munkalaphoz kötés: ha a szám pontosan egy munkalapé, annak azonosítója is megmarad.
        $adat['munkalap_id'] = self::munkalap_id_szambol((string) $adat['munkalap_szam']);

        return $adat;
    }

    private static function munkalap_id_szambol(string $szam): int
    {
        global $wpdb;

        if (!preg_match('/^\d{1,12}$/', $szam)) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE munkalap_szam = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            (int) $szam
        ));
    }

    /**
     * Tétel mentése (új vagy módosítás) + naplózás + a napok újraszámolása.
     *
     * @param array<string, mixed> $adat adat_ellenoriz() kimenete
     * @return int A tétel azonosítója.
     */
    public static function ment(array $adat, int $id = 0, string $forras = 'kezi'): int
    {
        global $wpdb;

        $most = current_time('mysql');

        if ($id > 0) {
            $regi = self::tetel($id);

            if ($regi === null) {
                return 0;
            }

            $adat['modositva'] = $most;
            $wpdb->update(self::tabla(), $adat, ['id' => $id]);
            self::naploz($id, (string) $adat['datum'], 'modositas', self::tetel_kifele($regi), self::tetel_kifele((object) array_merge((array) $regi, $adat)));

            $tol = min((string) $regi->datum, (string) $adat['datum']);
        } else {
            self::nap_biztosit((string) $adat['datum']);

            $adat += [
                'ido'         => $adat['datum'] === self::ma() ? $most : $adat['datum'] . ' 12:00:00',
                'forras'      => $forras,
                'sorrend'     => 900000 + (int) current_time('G') * 3600 + (int) current_time('i') * 60 + (int) current_time('s'),
                'felhasznalo' => get_current_user_id(),
                'letrehozva'  => $most,
                'modositva'   => $most,
            ];

            $wpdb->insert(self::tabla(), $adat);
            $id = (int) $wpdb->insert_id;
            self::naploz($id, (string) $adat['datum'], 'felvitel', null, self::tetel_kifele((object) (['id' => $id, 'torolve' => 0] + $adat)));

            $tol = (string) $adat['datum'];
        }

        self::ujraszamol($tol);

        return $id;
    }

    /* =================================================================
     * Napok
     * ============================================================== */

    public static function nap(string $datum): ?object
    {
        global $wpdb;

        $sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::nap_tabla() . ' WHERE datum = %s', $datum)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_object($sor) ? $sor : null;
    }

    /** A nap sora létezik (a mai nap magától nyílik). */
    public static function nap_biztosit(string $datum): void
    {
        global $wpdb;

        if (self::nap($datum) !== null) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::nap_tabla() . " (datum, allapot) VALUES (%s, 'nyitott')", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $datum
        ));

        self::ujraszamol($datum);
    }

    /**
     * A napi összesítők újraszámolása a megadott naptól előre (a nyitó
     * egyenleg láncszerűen öröklődik). Csak a változott sorokat írja.
     */
    public static function ujraszamol(string $tol): void
    {
        global $wpdb;

        $nt = self::nap_tabla();
        $tt = self::tabla();

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $elozo = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$nt} WHERE datum < %s ORDER BY datum DESC LIMIT 1", $tol));

        $nyito     = $elozo ? ($elozo->szamolt !== null ? (float) $elozo->szamolt : (float) $elozo->zaro) : 0.0;
        $halmozott = $elozo ? (float) $elozo->halmozott : 0.0;

        $osszesitok = $wpdb->get_results($wpdb->prepare(
            "SELECT datum,
                    SUM(CASE WHEN tipus = 'bevetel' THEN kp ELSE 0 END) AS kp_be,
                    SUM(CASE WHEN tipus = 'bevetel' THEN kartya ELSE 0 END) AS kartya,
                    SUM(CASE WHEN tipus = 'bevetel' THEN utalas ELSE 0 END) AS utalas,
                    SUM(CASE WHEN tipus = 'kifizetes' THEN kp ELSE 0 END) AS kifizetes,
                    SUM(CASE WHEN tipus = 'kivet' THEN kp ELSE 0 END) AS kivet,
                    SUM(CASE WHEN tipus = 'befizetes' THEN kp ELSE 0 END) AS befizetes,
                    SUM(CASE WHEN tipus <> 'kihagyva' THEN 1 ELSE 0 END) AS db
             FROM {$tt} WHERE torolve = 0 AND datum >= %s GROUP BY datum",
            $tol
        ));
        $osszesitok = array_column(is_array($osszesitok) ? $osszesitok : [], null, 'datum');

        $napok = [];

        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$nt} WHERE datum >= %s", $tol)) as $n) {
            $napok[(string) $n->datum] = $n;
        }
        // phpcs:enable

        $datumok = array_unique(array_merge(array_keys($osszesitok), array_keys($napok)));
        sort($datumok);

        $irando = [];

        foreach ($datumok as $datum) {
            $o = $osszesitok[$datum] ?? null;
            $n = $napok[$datum] ?? null;

            $kp_be     = $o ? round((float) $o->kp_be, 2) : 0.0;
            $kartya    = $o ? round((float) $o->kartya, 2) : 0.0;
            $utalas    = $o ? round((float) $o->utalas, 2) : 0.0;
            $kifizetes = $o ? round((float) $o->kifizetes, 2) : 0.0;
            $kivet     = $o ? round((float) $o->kivet, 2) : 0.0;
            $befizetes = $o ? round((float) $o->befizetes, 2) : 0.0;
            $zaro      = round($nyito + $kp_be + $kifizetes + $kivet + $befizetes, 2);
            $forgalom  = round($kp_be + $kartya + $utalas, 2);
            $halmozott = round($halmozott + $forgalom, 2);
            $szamolt   = $n && $n->szamolt !== null ? (float) $n->szamolt : null;

            $uj = [
                'nyito'     => round($nyito, 2),
                'kp_be'     => $kp_be,
                'kartya'    => $kartya,
                'utalas'    => $utalas,
                'kifizetes' => $kifizetes,
                'kivet'     => $kivet,
                'befizetes' => $befizetes,
                'zaro'      => $zaro,
                'elteres'   => $szamolt !== null ? round($szamolt - $zaro, 2) : null,
                'forgalom'  => $forgalom,
                'halmozott' => $halmozott,
                'tetel_db'  => $o ? (int) $o->db : 0,
            ];

            $valtozott = $n === null;

            foreach ($n !== null ? $uj : [] as $k => $v) {
                $regi = $n->$k;

                if ($v === null ? $regi !== null : ($regi === null || abs((float) $regi - (float) $v) > 0.004)) {
                    $valtozott = true;
                    break;
                }
            }

            if ($valtozott) {
                // Új nap a tételekből: a múltbeli (pl. utólag beírt) nap nyitva marad, amíg valaki le nem zárja.
                $irando[] = ['datum' => $datum, 'allapot' => $n !== null ? (string) $n->allapot : 'nyitott'] + $uj;
            }

            $nyito = $szamolt !== null ? $szamolt : $zaro;
        }

        self::napok_ir($irando);
    }

    /**
     * A megváltozott napi összesítők kiírása. Kevés napnál soronként; sok napnál
     * (import, régi tétel javítása) 300-as csomagokban, egyetlen
     * INSERT … ON DUPLICATE KEY UPDATE-tel – így 2000+ nap is pár lekérdezés.
     *
     * @param array<int, array<string, mixed>> $sorok
     */
    private static function napok_ir(array $sorok): void
    {
        global $wpdb;

        if ($sorok === []) {
            return;
        }

        $nt = self::nap_tabla();

        if (count($sorok) <= 10) {
            foreach ($sorok as $sor) {
                $datum = (string) $sor['datum'];
                $van   = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$nt} WHERE datum = %s", $datum)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

                if ($van > 0) {
                    unset($sor['datum'], $sor['allapot']);
                    $wpdb->update($nt, $sor, ['id' => $van]);
                } else {
                    $wpdb->insert($nt, $sor);
                }
            }

            return;
        }

        $oszlopok = ['datum', 'allapot', 'nyito', 'kp_be', 'kartya', 'utalas', 'kifizetes', 'kivet', 'befizetes', 'zaro', 'elteres', 'forgalom', 'halmozott', 'tetel_db'];
        $frissul  = array_slice($oszlopok, 2);
        $utotag   = ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($o) => "{$o} = VALUES({$o})", $frissul));

        foreach (array_chunk($sorok, 300) as $csomag) {
            $ertekek = [];

            foreach ($csomag as $sor) {
                $mezok = [$wpdb->prepare('%s', (string) $sor['datum']), $wpdb->prepare('%s', (string) $sor['allapot'])];

                foreach ($frissul as $o) {
                    $mezok[] = $sor[$o] === null ? 'NULL' : ($o === 'tetel_db' ? (string) (int) $sor[$o] : sprintf('%.2F', (float) $sor[$o]));
                }

                $ertekek[] = '(' . implode(', ', $mezok) . ')';
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("INSERT INTO {$nt} (" . implode(', ', $oszlopok) . ') VALUES ' . implode(', ', $ertekek) . $utotag);
        }
    }

    /** Egy nap a böngészőnek. */
    public static function nap_kifele(?object $n, string $datum = ''): array
    {
        if ($n === null) {
            return [
                'datum' => $datum, 'allapot' => 'nyitott', 'nyito' => 0, 'kp_be' => 0, 'kartya' => 0, 'utalas' => 0,
                'kifizetes' => 0, 'kivet' => 0, 'befizetes' => 0, 'zaro' => 0, 'szamolt' => null, 'elteres' => null,
                'cimletek' => [], 'forgalom' => 0, 'halmozott' => 0, 'db' => 0, 'lezarta' => '', 'lezarva' => '', 'megj' => '',
                'szamolva' => '', 'import' => false,
            ];
        }

        $cimletek = json_decode((string) $n->cimletek, true);

        return [
            'datum'     => (string) $n->datum,
            'allapot'   => (string) $n->allapot,
            'nyito'     => (float) $n->nyito,
            'kp_be'     => (float) $n->kp_be,
            'kartya'    => (float) $n->kartya,
            'utalas'    => (float) $n->utalas,
            'kifizetes' => (float) $n->kifizetes,
            'kivet'     => (float) $n->kivet,
            'befizetes' => (float) $n->befizetes,
            'zaro'      => (float) $n->zaro,
            'szamolt'   => $n->szamolt !== null ? (float) $n->szamolt : null,
            'elteres'   => $n->elteres !== null ? (float) $n->elteres : null,
            'cimletek'  => is_array($cimletek) ? $cimletek : [],
            'forgalom'  => (float) $n->forgalom,
            'halmozott' => (float) $n->halmozott,
            'db'        => (int) $n->tetel_db,
            'lezarta'   => self::felhasznalo_nev((int) $n->lezarta),
            'lezarva'   => (string) $n->lezarva,
            'szamolva'  => (string) $n->szamolva,
            'megj'      => (string) $n->megjegyzes,
            'import'    => (string) $n->cimletek === 'import',
        ];
    }

    /** @return array<int, array<string, mixed>> Egy nap tételei, rögzítési sorrendben. */
    public static function nap_tetelei(string $datum, bool $toroltekkel = false): array
    {
        global $wpdb;

        $sorok = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::tabla() . ' WHERE datum = %s' . ($toroltekkel ? '' : ' AND torolve = 0') . ' ORDER BY sorrend, id', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $datum
        ));

        return array_map([self::class, 'tetel_kifele'], is_array($sorok) ? $sorok : []);
    }

    /** A lezáratlan múltbeli napok (a mai előtt), a legújabb elöl. */
    private static function lezaratlan_napok(): array
    {
        global $wpdb;

        $sorok = $wpdb->get_col($wpdb->prepare(
            'SELECT datum FROM ' . self::nap_tabla() . " WHERE allapot <> 'lezart' AND datum < %s AND tetel_db > 0 ORDER BY datum DESC LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::ma()
        ));

        return array_map('strval', is_array($sorok) ? $sorok : []);
    }

    /** Egy nap teljes csomagja a felületnek. */
    public static function nap_csomag(string $datum): array
    {
        if ($datum === self::ma()) {
            self::nap_biztosit($datum);
        }

        return [
            'nap'    => self::nap_kifele(self::nap($datum), $datum),
            'tetelek' => self::nap_tetelei($datum),
            'torolt' => self::torolt_db($datum),
        ];
    }

    private static function torolt_db(string $datum): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::tabla() . ' WHERE datum = %s AND torolve = 1', $datum)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** Az induló adatok (az oldal HTML-jébe ágyazva – nincs külön első kérés). */
    public static function indulo(): array
    {
        $b = self::beallitas();

        return [
            'ma'        => self::ma(),
            'csomag'    => self::nap_csomag(self::ma()),
            'lezaratlan' => self::lezaratlan_napok(),
            'szemelyek' => array_column($b['szemelyek'], 'nev'),
            'cimletek'  => $b['cimletek'],
            'tipusok'   => self::TIPUSOK,
            'van_adat'  => self::van_adat(),
            'ai'        => self::ai_beallitas() !== null,
        ];
    }

    private static function van_adat(): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var('SELECT id FROM ' . self::tabla() . ' LIMIT 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** A Claude API kulcsa és modellje (a Levelezés beállításából), ha van. */
    public static function ai_beallitas(): ?array
    {
        if (!class_exists('SDH_Muhely_Levelezes') || !method_exists('SDH_Muhely_Levelezes', 'ai_kulcs')) {
            return null;
        }

        $ai = SDH_Muhely_Levelezes::ai_kulcs();

        return is_array($ai) && (string) ($ai['kulcs'] ?? '') !== '' ? $ai : null;
    }

    /* =================================================================
     * AJAX – adatok
     * ============================================================== */

    /** Egy nap csomagja (Ma lap, KP lap, napváltás). */
    public static function ajax_adat(): void
    {
        self::jog();

        $datum = self::post_szoveg('datum', 10);
        $datum = self::datum_ervenyes($datum) ? $datum : self::ma();

        wp_send_json_success(self::nap_csomag($datum) + ['lezaratlan' => self::lezaratlan_napok()]);
    }

    /**
     * A napló egy oldala: napok (újabb elöl) a tételeikkel – mint az Excel, csak lapozva.
     * Szűrők: ig (dátumra ugrás), csak eltérés, csak lezáratlan.
     */
    public static function ajax_naplo(): void
    {
        global $wpdb;

        self::jog();

        $oldal = max(1, (int) self::post('oldal', 1));
        $db    = min(31, max(1, (int) self::post('db', self::NAPLO_NAP)));
        $ugras = self::post_szoveg('ugras', 10);
        $szuro = self::post_szoveg('szuro', 20);

        $nt = self::nap_tabla();
        $felt = 'tetel_db > 0 OR szamolt IS NOT NULL';

        if ($szuro === 'elteres') {
            $felt = '(elteres IS NOT NULL AND ABS(elteres) >= 1)';
        } elseif ($szuro === 'nyitott') {
            $felt = "(allapot <> 'lezart' AND tetel_db > 0)";
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $osszes = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$nt} WHERE {$felt}");

        if (self::datum_ervenyes($ugras)) {
            $elotte = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$nt} WHERE ({$felt}) AND datum > %s", $ugras));
            $oldal  = (int) floor($elotte / $db) + 1;
        }

        $napok = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$nt} WHERE {$felt} ORDER BY datum DESC LIMIT %d OFFSET %d",
            $db,
            ($oldal - 1) * $db
        ));

        $ki = [];

        if (is_array($napok) && $napok !== []) {
            $datumok = array_map(static fn ($n) => (string) $n->datum, $napok);
            $hely    = implode(',', array_fill(0, count($datumok), '%s'));
            $sorok   = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . self::tabla() . " WHERE torolve = 0 AND datum IN ({$hely}) ORDER BY datum, sorrend, id",
                $datumok
            ));
            // phpcs:enable

            $tetelek = [];

            foreach (is_array($sorok) ? $sorok : [] as $s) {
                $tetelek[(string) $s->datum][] = self::tetel_kifele($s);
            }

            foreach ($napok as $n) {
                $ki[] = ['nap' => self::nap_kifele($n), 'tetelek' => $tetelek[(string) $n->datum] ?? []];
            }
        }

        wp_send_json_success([
            'napok'  => $ki,
            'oldal'  => $oldal,
            'oldalak' => max(1, (int) ceil($osszes / $db)),
            'osszes' => $osszes,
        ]);
    }

    /* =================================================================
     * AJAX – tétel felvitele, módosítása, törlése
     * ============================================================== */

    /** A popupos űrlap beküldése (app.js). */
    public static function ajax_ment(): void
    {
        self::jog();

        $id   = (int) self::post('id', 0);
        $be   = [];

        foreach (['tipus', 'datum', 'leiras', 'nev', 'szemely', 'kp', 'kartya', 'utalas', 'ml', 'szamla', 'vonalkod', 'megj'] as $k) {
            $v = self::post($k, '');
            $be[$k] = is_scalar($v) ? (string) $v : '';
        }

        // Bevételnél a mód választóból: egyetlen összeg + mód, vagy vegyes (KP + kártya).
        if ($be['tipus'] === 'bevetel' && self::post_szoveg('mod', 10) !== '' && self::post_szoveg('mod', 10) !== 'vegyes') {
            $osszeg = self::szam(self::post('osszeg', '0'));
            $mod    = self::post_szoveg('mod', 10);
            $be['kp'] = $mod === 'kp' ? (string) $osszeg : '0';
            $be['kartya'] = $mod === 'kartya' ? (string) $osszeg : '0';
            $be['utalas'] = $mod === 'utalas' ? (string) $osszeg : '0';
        } elseif (in_array($be['tipus'], ['kivet', 'kifizetes', 'befizetes'], true)) {
            $be['kp'] = (string) self::szam(self::post('osszeg', '0'));
        }

        $adat = self::adat_ellenoriz($be);

        if (is_string($adat)) {
            wp_send_json_error(['uzenet' => $adat]);
        }

        if ($id > 0 && self::tetel($id) === null) {
            wp_send_json_error(['uzenet' => 'Ez a tétel már nem létezik.']);
        }

        $uj_id = self::ment($adat, $id);

        wp_send_json_success([
            'id'    => $uj_id,
            'datum' => (string) $adat['datum'],
            'tetel' => ($t = self::tetel($uj_id)) ? self::tetel_kifele($t) : null,
            'nap'   => self::nap_kifele(self::nap((string) $adat['datum'])),
            'vissza' => SDH_Muhely_Modulok::url(self::KULCS),
        ]);
    }

    /** A „Ma" lap gyorsbeviteli sora (Enterre ment). */
    public static function ajax_gyors(): void
    {
        self::jog();

        $be = [];

        foreach (['tipus', 'leiras', 'nev', 'szemely', 'kp', 'kartya', 'utalas', 'ml', 'szamla', 'vonalkod', 'megj', 'datum'] as $k) {
            $v = self::post($k, '');
            $be[$k] = is_scalar($v) ? (string) $v : '';
        }

        $adat = self::adat_ellenoriz($be);

        if (is_string($adat)) {
            wp_send_json_error(['uzenet' => $adat]);
        }

        // Kettős rögzítés figyelmeztetés: ugyanaz a munkalap vagy számla már bent van.
        if (self::post('megerositve', '') !== '1') {
            $dupla = self::dupla_keres($adat);

            if ($dupla !== null) {
                wp_send_json_error(['uzenet' => $dupla, 'dupla' => true]);
            }
        }

        $id = self::ment($adat);

        wp_send_json_success(self::nap_csomag((string) $adat['datum']) + ['id' => $id]);
    }

    /** Van-e már tétel ugyanerre a munkalapra vagy számlára (bevételnél). */
    private static function dupla_keres(array $adat): ?string
    {
        global $wpdb;

        if ($adat['tipus'] !== 'bevetel') {
            return null;
        }

        $t = self::tabla();

        if ((string) $adat['munkalap_szam'] !== '') {
            $volt = $wpdb->get_row($wpdb->prepare(
                "SELECT datum, kp, kartya, utalas FROM {$t} WHERE torolve = 0 AND tipus = 'bevetel' AND munkalap_szam = %s AND datum >= %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $adat['munkalap_szam'],
                wp_date('Y-m-d', strtotime(self::ma() . ' -180 days'))
            ));

            if ($volt) {
                return sprintf(
                    'A %s munkalapra már van pénztártétel (%s, %s). Biztosan még egyszer beírod?',
                    $adat['munkalap_szam'],
                    (string) $volt->datum,
                    self::penz((float) $volt->kp + (float) $volt->kartya + (float) $volt->utalas)
                );
            }
        }

        if ((string) $adat['szamlaszam'] !== '') {
            $volt = $wpdb->get_row($wpdb->prepare(
                "SELECT datum, leiras FROM {$t} WHERE torolve = 0 AND tipus = 'bevetel' AND szamlaszam = %s AND datum = %s AND leiras = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $adat['szamlaszam'],
                $adat['datum'],
                $adat['leiras']
            ));

            if ($volt) {
                return sprintf('A %s számla ugyanezzel a leírással ma már szerepel. Biztosan még egyszer beírod?', $adat['szamlaszam']);
            }
        }

        return null;
    }

    public static function ajax_torol(): void
    {
        global $wpdb;

        self::jog();

        $id = (int) self::post('id', 0);
        $t  = self::tetel($id);

        if ($t === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen tétel.']);
        }

        $vissza = self::post('vissza', '') === '1';

        $wpdb->update(self::tabla(), ['torolve' => $vissza ? 0 : 1, 'modositva' => current_time('mysql')], ['id' => $id]);
        self::naploz($id, (string) $t->datum, $vissza ? 'visszaallitas' : 'torles', self::tetel_kifele($t), null);
        self::ujraszamol((string) $t->datum);

        wp_send_json_success(self::nap_csomag((string) $t->datum));
    }

    /**
     * A napló pipája: kipipált (áthúzott) sor – pl. egyeztetéskor „ezt már
     * megnéztem / megvan". Az összegekhez nem nyúl, ezért újraszámolás sincs.
     */
    public static function ajax_jelol(): void
    {
        global $wpdb;

        self::jog();

        $idk = self::post('idk', []);
        $idk = is_array($idk) && $idk !== [] ? array_values(array_filter(array_map('intval', $idk))) : [(int) self::post('id', 0)];
        $idk = array_slice(array_filter($idk), 0, 500);

        if ($idk === []) {
            wp_send_json_error(['uzenet' => 'Nincs kijelölt tétel.']);
        }

        $ertek = self::post('ertek', '1') === '1' ? 1 : 0;
        $hely  = implode(',', array_fill(0, count($idk), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare('UPDATE ' . self::tabla() . " SET jelolt = %d WHERE id IN ({$hely})", array_merge([$ertek], $idk)));

        wp_send_json_success(['idk' => $idk, 'jelolt' => $ertek === 1]);
    }

    /**
     * Az ügynök egy kattintásos javításai (csak ezek): mód csere (KP ↔ kártya),
     * számlaszám beírása, kivét személye, munkalapszám beírása.
     */
    public static function ajax_javit(): void
    {
        self::jog();

        $muvelet = self::post_szoveg('muvelet', 20);
        $idk     = self::post('idk', []);
        $idk     = is_array($idk) && $idk !== [] ? array_map('intval', $idk) : [(int) self::post('id', 0)];
        $ertek   = self::post_szoveg('ertek', 60);
        $kesz    = 0;

        foreach ($idk as $id) {
            $t = self::tetel($id);

            if ($t === null || (int) $t->torolve === 1) {
                continue;
            }

            $uj = (array) $t;

            if ($muvelet === 'kartyara' && $t->tipus === 'bevetel') {
                $uj['kartya'] = (float) $t->kartya + (float) $t->kp;
                $uj['kp']     = 0;
            } elseif ($muvelet === 'kpra' && $t->tipus === 'bevetel') {
                $uj['kp']     = (float) $t->kp + (float) $t->kartya;
                $uj['kartya'] = 0;
            } elseif ($muvelet === 'szamla' && $ertek !== '') {
                $uj['szamlaszam'] = $ertek;
            } elseif ($muvelet === 'szemely' && $t->tipus === 'kivet' && in_array($ertek, array_column(self::beallitas()['szemelyek'], 'nev'), true)) {
                $uj['szemely'] = $ertek;
                $uj['nev']     = $ertek;
            } elseif ($muvelet === 'ml' && preg_match('/^\d{1,12}$/', $ertek)) {
                $uj['munkalap_szam'] = $ertek;
                $uj['munkalap_id']   = self::munkalap_id_szambol($ertek);
            } else {
                continue;
            }

            $valtozas = array_intersect_key($uj, array_flip(['kp', 'kartya', 'szamlaszam', 'szemely', 'nev', 'munkalap_szam', 'munkalap_id']));
            self::ment(array_merge($valtozas, ['datum' => (string) $t->datum]), $id);
            $kesz++;
        }

        wp_send_json_success(['kesz' => $kesz]);
    }

    /* =================================================================
     * AJAX – számolás, zárás, újranyitás
     * ============================================================== */

    public static function ajax_szamolas(): void
    {
        global $wpdb;

        self::jog();

        $datum = self::post_szoveg('datum', 10);

        if (!self::datum_ervenyes($datum) || $datum > self::ma()) {
            wp_send_json_error(['uzenet' => 'Érvénytelen nap.']);
        }

        self::nap_biztosit($datum);

        $nyers    = json_decode((string) self::post('cimletek', '{}'), true);
        $cimletek = [];
        $osszeg   = 0.0;

        foreach (self::beallitas()['cimletek'] as $c) {
            $db = is_array($nyers) ? max(0, (int) ($nyers[(string) $c] ?? 0)) : 0;

            if ($db > 0) {
                $cimletek[(string) $c] = $db;
                $osszeg += $c * $db;
            }
        }

        $lezar = self::post('lezar', '') === '1';
        $megj  = mb_substr(sanitize_textarea_field((string) self::post('megj', '')), 0, 1000);
        $regi  = self::nap($datum);

        $adat = [
            'szamolt'    => round($osszeg, 2),
            'cimletek'   => (string) wp_json_encode($cimletek),
            'szamolva'   => current_time('mysql'),
            'megjegyzes' => $megj,
        ];

        if ($lezar) {
            $adat['allapot'] = 'lezart';
            $adat['lezarta'] = get_current_user_id();
            $adat['lezarva'] = current_time('mysql');
        }

        $wpdb->update(self::nap_tabla(), $adat, ['datum' => $datum]);
        self::ujraszamol($datum);

        $nap = self::nap($datum);

        self::naploz(0, $datum, $lezar ? 'zaras' : 'szamolas', $regi ? self::nap_kifele($regi) : null, $nap ? self::nap_kifele($nap) : null);

        // Ha a zárás eltéréssel ment, az ügynök azonnal megnézi, mi lehet az oka.
        $egyeztetes = null;

        if ($nap && $nap->elteres !== null && abs((float) $nap->elteres) >= 1 && class_exists('SDH_Muhely_Penztar_Ugynok')) {
            $egyeztetes = SDH_Muhely_Penztar_Ugynok::egyeztet($datum);
        }

        wp_send_json_success(self::nap_csomag($datum) + ['egyeztetes' => $egyeztetes, 'lezaratlan' => self::lezaratlan_napok()]);
    }

    public static function ajax_ujranyit(): void
    {
        global $wpdb;

        self::jog();

        $datum = self::post_szoveg('datum', 10);
        $nap   = self::datum_ervenyes($datum) ? self::nap($datum) : null;

        if ($nap === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen nap.']);
        }

        $wpdb->update(self::nap_tabla(), ['allapot' => 'nyitott'], ['datum' => $datum]);
        self::naploz(0, $datum, 'ujranyitas', self::nap_kifele($nap), null);

        wp_send_json_success(self::nap_csomag($datum) + ['lezaratlan' => self::lezaratlan_napok()]);
    }

    /** Egy nap változásnaplója (ki mit írt be, módosított, törölt). */
    public static function ajax_valtozasok(): void
    {
        global $wpdb;

        self::jog();

        $datum = self::post_szoveg('datum', 10);

        if (!self::datum_ervenyes($datum)) {
            wp_send_json_error(['uzenet' => 'Érvénytelen nap.']);
        }

        $sorok = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::naplo_tabla() . ' WHERE datum = %s ORDER BY id DESC LIMIT 200', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $datum
        ));

        $ki = [];

        foreach (is_array($sorok) ? $sorok : [] as $s) {
            $ki[] = [
                'muvelet' => (string) $s->muvelet,
                'ido'     => (string) $s->ido,
                'ki'      => self::felhasznalo_nev((int) $s->felhasznalo),
                'regi'    => json_decode((string) $s->regi, true),
                'uj'      => json_decode((string) $s->uj, true),
            ];
        }

        wp_send_json_success(['valtozasok' => $ki]);
    }

    /* =================================================================
     * Munkalap-kapcsolat
     * ============================================================== */

    /** @return object|null A munkalap a számával (vagy azonosítójával). */
    private static function munkalap(int $id = 0, string $szam = ''): ?object
    {
        global $wpdb;

        $t = SDH_Muhely_Schema::tabla('munkalap');

        if ($id > 0) {
            $sor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } elseif (preg_match('/^\d{1,12}$/', $szam)) {
            $sor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE munkalap_szam = %d", (int) $szam)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $sor = null;
        }

        return is_object($sor) ? $sor : null;
    }

    /**
     * Minden, ami egy munkalapból a pénztárba kell: név, leírás, összegek,
     * a legutóbbi számla száma, és amennyi már a pénztárban van.
     */
    public static function munkalap_info(object $m): array
    {
        global $wpdb;

        $ugyfel = (int) $m->ugyfel_id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT nev FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', (int) $m->ugyfel_id)) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : null;
        $eszkoz = (int) $m->eszkoz_id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT gyarto, tipus, megnevezes FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' WHERE id = %d', (int) $m->eszkoz_id)) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : null;
        $tetelek = $wpdb->get_col($wpdb->prepare(
            'SELECT megnevezes FROM ' . SDH_Muhely_Schema::tabla('munkalap_tetel') . " WHERE munkalap_id = %d AND megnevezes <> '' ORDER BY sorrend, id LIMIT 4", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            (int) $m->id
        ));
        $szamla = $wpdb->get_var($wpdb->prepare(
            'SELECT szamlaszam FROM ' . SDH_Muhely_Schema::tabla('szamla') . ' WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            (int) $m->id
        ));

        $eszkoz_nev = $eszkoz ? trim((string) $eszkoz->gyarto . ' ' . ((string) $eszkoz->megnevezes !== '' ? (string) $eszkoz->megnevezes : (string) $eszkoz->tipus)) : '';
        $munka      = is_array($tetelek) && $tetelek !== [] ? implode(', ', array_map('strval', $tetelek)) : (string) $m->nev;
        $leiras     = trim($eszkoz_nev . ($munka !== '' ? ' – ' . $munka : ''), ' –');

        $szam    = (int) $m->munkalap_szam > 0 ? (string) (int) $m->munkalap_szam : '';
        $kassza  = self::munkalap_kasszaban((int) $m->id, $szam, (string) ($m->letrehozva ?? ''));
        $brutto  = (float) $m->brutto_ertek;
        $fizetett = (float) $m->fizetett;

        return [
            'id'        => (int) $m->id,
            'szam'      => $szam,
            'nev'       => $ugyfel ? (string) $ugyfel->nev : '',
            'leiras'    => mb_substr($leiras !== '' ? $leiras : 'Munkalap ' . $szam, 0, 255),
            'brutto'    => $brutto,
            'eloleg'    => $fizetett,
            'fizetve'   => (int) $m->fizetve === 1,
            'fizetendo' => (int) $m->fizetve === 1 ? 0.0 : round($brutto - $fizetett, 2),
            'mod'       => self::mod_oszlop((string) $m->fizetesi_mod),
            'fizetesi_mod' => (string) $m->fizetesi_mod,
            'szamla'    => is_string($szamla) ? $szamla : '',
            'kasszaban' => $kassza,
        ];
    }

    /** Ennyi van már a pénztárban erre a munkalapra (a „nem került kasszába" jelölésekkel együtt). */
    public static function munkalap_kasszaban(int $id, string $szam, string $tol = ''): float
    {
        global $wpdb;

        $t = self::tabla();

        // A szám szerinti egyezésnél csak a munkalap felvétele utáni tételek számítanak:
        // a régi táblában ugyanez a szám egy régebbi lapé is lehetett.
        if ($tol === '' && $id > 0) {
            $tol = (string) $wpdb->get_var($wpdb->prepare('SELECT letrehozva FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $tol = preg_match('/^\d{4}-\d{2}-\d{2}/', $tol) ? wp_date('Y-m-d', strtotime(substr($tol, 0, 10) . ' 12:00:00') - 2 * DAY_IN_SECONDS) : '1970-01-01';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return round((float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN tipus = 'kihagyva' THEN info_osszeg ELSE kp + kartya + utalas END), 0)
             FROM {$t} WHERE torolve = 0 AND tipus IN ('bevetel', 'kihagyva')
               AND (munkalap_id = %d OR (munkalap_szam <> '' AND munkalap_szam = %s AND datum >= %s))",
            $id > 0 ? $id : -1,
            $szam !== '' ? $szam : '#',
            $tol
        )), 2);
    }

    /** Munkalapszám alapján a felvitel kitöltése (gépelés közben). */
    public static function ajax_munkalap(): void
    {
        self::jog();

        $m = self::munkalap((int) self::post('id', 0), self::post_szoveg('szam', 20));

        if ($m === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen munkalap a CRM-ben.']);
        }

        wp_send_json_success(self::munkalap_info($m));
    }

    /**
     * A munkalap mentése után: ha most lett „Fizetve", vagy nőtt az előleg,
     * és a különbözet még nincs a pénztárban → ajánlat a popuphoz.
     */
    public static function munkalap_ajanlat(int $id, ?object $regi): ?array
    {
        if (!self::beallitas()['ajanlat']) {
            return null;
        }

        $m = self::munkalap($id);

        if ($m === null) {
            return null;
        }

        $most_fizetve = (int) $m->fizetve === 1 && ($regi === null || (int) $regi->fizetve !== 1);
        $eloleg_nott  = (float) $m->fizetett > ($regi !== null ? (float) $regi->fizetett : 0.0) + 0.5;

        if (!$most_fizetve && !$eloleg_nott) {
            return null;
        }

        $info   = self::munkalap_info($m);
        $vart   = $most_fizetve ? $info['brutto'] : $info['eloleg'];
        $hianyz = round($vart - $info['kasszaban'], 2);

        if ($hianyz < 1) {
            return null;
        }

        return $info + [
            'osszeg' => $hianyz,
            'eloleg_mod' => !$most_fizetve,
        ];
    }

    /** A számla kiállítása után: a munkalap számla nélküli pénztártételei megkapják a számát. */
    public static function szamla_kiallitva(int $szamla_id, int $munkalap_id, string $szamlaszam): void
    {
        global $wpdb;

        $m = self::munkalap($munkalap_id);

        if ($m === null || $szamlaszam === '') {
            return;
        }

        $szam = (int) $m->munkalap_szam > 0 ? (string) (int) $m->munkalap_szam : '#';
        $t    = self::tabla();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE torolve = 0 AND tipus = 'bevetel' AND szamlaszam = '' AND (munkalap_id = %d OR munkalap_szam = %s) AND datum >= %s",
            $munkalap_id,
            $szam,
            wp_date('Y-m-d', strtotime(self::ma() . ' -60 days'))
        ));

        foreach (is_array($sorok) ? $sorok : [] as $s) {
            $wpdb->update($t, ['szamlaszam' => mb_substr($szamlaszam, 0, 60), 'modositva' => current_time('mysql')], ['id' => (int) $s->id]);
            self::naploz((int) $s->id, (string) $s->datum, 'szamlaszam', ['szamla' => ''], ['szamla' => $szamlaszam, 'szamla_id' => $szamla_id]);
        }
    }

    /**
     * Számla után (app.js „sdh:szamla-kesz"): ha a munkalaphoz még nincs pénztártétel,
     * és a számla azonnali fizetésű, ajánlat; ha van, jelzi, hogy a szám beíródott.
     */
    public static function ajax_ajanlat(): void
    {
        global $wpdb;

        self::jog();

        $m = self::munkalap((int) self::post('munkalap_id', 0));

        if ($m === null) {
            wp_send_json_success(['ajanlat' => null]);
        }

        $info   = self::munkalap_info($m);
        $szamla = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . SDH_Muhely_Schema::tabla('szamla') . ' WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            (int) $m->id
        ));

        if (!$szamla) {
            wp_send_json_success(['ajanlat' => null]);
        }

        $mod = self::mod_oszlop((string) $szamla->fizmod);

        if ($info['kasszaban'] >= 1) {
            wp_send_json_success(['ajanlat' => null, 'beirva' => (string) $szamla->szamlaszam]);
        }

        if (!self::beallitas()['ajanlat'] || $mod === 'utalas') {
            wp_send_json_success(['ajanlat' => null]);
        }

        wp_send_json_success(['ajanlat' => array_merge($info, [
            'osszeg' => round(max(0.0, (float) $szamla->brutto - $info['kasszaban']), 2),
            'mod'    => $mod,
            'szamla' => (string) $szamla->szamlaszam,
            'eloleg_mod' => false,
        ])]);
    }

    /** „Nem kell a kasszába": nyoma marad, hogy a munkalap fizetése szándékosan nem került be. */
    public static function ajax_kihagy(): void
    {
        self::jog();

        $m = self::munkalap((int) self::post('munkalap_id', 0));

        if ($m === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen munkalap.']);
        }

        $info = self::munkalap_info($m);
        $ok   = self::post_szoveg('ok', 200);

        self::ment([
            'datum'         => self::ma(),
            'tipus'         => 'kihagyva',
            'leiras'        => $info['leiras'],
            'nev'           => $info['nev'],
            'szemely'       => '',
            'kp'            => 0,
            'kartya'        => 0,
            'utalas'        => 0,
            'info_osszeg'   => round(self::szam(self::post('osszeg', '0')), 2),
            'munkalap_szam' => $info['szam'],
            'munkalap_id'   => $info['id'],
            'szamlaszam'    => $info['szamla'],
            'vonalkod'      => '',
            'megjegyzes'    => $ok !== '' ? $ok : 'Kérésre nem került a kasszába.',
        ], 0, 'munkalap');

        wp_send_json_success(['kesz' => true]);
    }

    /* =================================================================
     * Popupok (app.js nyit())
     * ============================================================== */

    /** Pill-csoport (natív select helyett). */
    private static function pillek(string $nev, array $elemek, string $aktualis, string $extra = ''): void
    {
        echo '<div class="sdh-pt-pillek" role="radiogroup" data-sdh-pt-pillek="' . esc_attr($nev) . '"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput

        foreach ($elemek as $ertek => $cimke) {
            printf(
                '<label class="sdh-pt-pill%4$s"><input type="radio" name="%1$s" value="%2$s"%3$s><span>%5$s</span></label>',
                esc_attr($nev),
                esc_attr((string) $ertek),
                checked($aktualis, (string) $ertek, false),
                $aktualis === (string) $ertek ? ' is-aktiv' : '',
                esc_html($cimke)
            );
        }

        echo '</div>';
    }

    private static function osszeg_mezo(string $nev, string $id, float $ertek, string $cimke, bool $kotelezo = false, string $extra = ''): void
    {
        ?>
        <div class="sdh-ig">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($cimke); ?><?php echo $kotelezo ? ' <span class="sdh-kotelezo">*</span>' : ''; ?></label>
            <span class="sdh-szam sdh-szam--utotag">
                <input type="text" inputmode="decimal" name="<?php echo esc_attr($nev); ?>" id="<?php echo esc_attr($id); ?>" autocomplete="off"
                       class="sdh-pt-osszeg" value="<?php echo $ertek != 0 ? esc_attr((string) round(abs($ertek), 2)) : ''; ?>"<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
                <span class="sdh-szam__utotag" aria-hidden="true">Ft</span>
            </span>
        </div>
        <?php
    }

    /** A legtöbbször fizetett partnerek (a kifizetés gyorsválasztója). */
    private static function partnerek(): array
    {
        global $wpdb;

        $sorok = $wpdb->get_col(
            'SELECT nev FROM ' . self::tabla() . " WHERE tipus = 'kifizetes' AND torolve = 0 AND nev <> '' AND datum >= '" . esc_sql(wp_date('Y-m-d', strtotime('-400 days'))) . "' GROUP BY nev ORDER BY COUNT(*) DESC LIMIT 10" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        return array_map('strval', is_array($sorok) ? $sorok : []);
    }

    /**
     * Tétel felvitele / módosítása. Paraméterek (GET): id, tipus, és előtöltés
     * (leiras, nev, osszeg, mod, ml, szamla, datum) – az ügynök és a munkalap innen nyit.
     */
    public static function ajax_urlap(): void
    {
        self::jog(false);

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $g = static fn (string $k, int $max = 255): string => isset($_GET[$k]) ? mb_substr(sanitize_text_field(wp_unslash((string) $_GET[$k])), 0, $max) : '';
        // phpcs:enable

        $id = (int) $g('id', 12);
        $t  = $id > 0 ? self::tetel($id) : null;

        if ($id > 0 && $t === null) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Ez a tétel már nem létezik.</div>';
            wp_die();
        }

        $b = self::beallitas();

        $tipus  = $t ? (string) $t->tipus : ($g('tipus', 12) !== '' && isset(self::TIPUSOK[$g('tipus', 12)]) ? $g('tipus', 12) : 'bevetel');
        $tipus  = $tipus === 'kihagyva' ? 'bevetel' : $tipus;
        $datum  = $t ? (string) $t->datum : (self::datum_ervenyes($g('datum', 10)) ? $g('datum', 10) : self::ma());
        $kp     = $t ? (float) $t->kp : 0.0;
        $kartya = $t ? (float) $t->kartya : 0.0;
        $utalas = $t ? (float) $t->utalas : 0.0;
        $mod    = $g('mod', 10);

        if ($t === null && $g('osszeg', 20) !== '') {
            $o = self::szam($g('osszeg', 20));
            $mod = in_array($mod, ['kp', 'kartya', 'utalas'], true) ? $mod : 'kp';
            $kp = $mod === 'kp' ? $o : 0.0;
            $kartya = $mod === 'kartya' ? $o : 0.0;
            $utalas = $mod === 'utalas' ? $o : 0.0;
        }

        $nemnulla = ($kp != 0 ? 1 : 0) + ($kartya != 0 ? 1 : 0) + ($utalas != 0 ? 1 : 0);
        $mod      = $nemnulla > 1 ? 'vegyes' : ($kartya != 0 ? 'kartya' : ($utalas != 0 ? 'utalas' : 'kp'));
        $osszeg   = $tipus === 'bevetel' ? ($mod === 'vegyes' ? 0.0 : $kp + $kartya + $utalas) : abs($kp);

        $leiras  = $t ? (string) $t->leiras : $g('leiras');
        $nev     = $t ? (string) $t->nev : $g('nev', 190);
        $szemely = $t ? (string) $t->szemely : $g('szemely', 80);
        $ml      = $t ? (string) $t->munkalap_szam : $g('ml', 40);
        $szamla  = $t ? (string) $t->szamlaszam : $g('szamla', 60);
        $vonal   = $t ? (string) $t->vonalkod : '';
        $megj    = $t ? (string) $t->megjegyzes : $g('megj');
        $nap     = self::nap($datum);
        $lezart  = $nap !== null && (string) $nap->allapot === 'lezart';

        ?>
        <form class="sdh-urlap sdh-pt-urlap" data-sdh-ajax-action="sdh_muhely_penztar_ment" data-sdh-pt-urlap data-tipus="<?php echo esc_attr($tipus); ?>" novalidate>
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">

            <h2 class="sdh-modal__cim"><?php echo $t ? 'Pénztártétel módosítása' : 'Új pénztártétel'; ?></h2>

            <?php if ($lezart) : ?>
                <div class="sdh-uzenet sdh-uzenet--figyelem">Ez a nap (<?php echo esc_html($datum); ?>) már le van zárva. A változás naplózódik, és a nap várható összege és eltérése újraszámolódik.</div>
            <?php endif; ?>

            <?php self::pillek('tipus', ['bevetel' => 'Bevétel', 'kivet' => 'Kivét', 'kifizetes' => 'Kifizetés', 'befizetes' => 'Befizetés'], $tipus, ' data-sdh-pt-tipus'); ?>

            <div class="sdh-pt-urlap__racs">
                <div class="sdh-pt-urlap__oszlop">
                    <div class="sdh-ig" data-sdh-pt-csak="bevetel kifizetes befizetes">
                        <label for="pt_leiras">Mi történt <span class="sdh-kotelezo">*</span></label>
                        <input type="text" name="leiras" id="pt_leiras" maxlength="255" value="<?php echo esc_attr($leiras); ?>"
                               placeholder="pl. Samsung A54 kijelzőcsere, Tok + fólia, TMX posta…" autocomplete="off">
                    </div>

                    <div class="sdh-ig" data-sdh-pt-csak="bevetel">
                        <label for="pt_nev">Ügyfél neve</label>
                        <input type="text" name="nev" id="pt_nev" maxlength="190" value="<?php echo esc_attr($nev); ?>" autocomplete="off">
                    </div>

                    <div class="sdh-ig" data-sdh-pt-csak="kifizetes">
                        <label for="pt_partner">Kinek</label>
                        <input type="text" name="nev" id="pt_partner" maxlength="190" value="<?php echo esc_attr($nev); ?>" autocomplete="off" placeholder="Beszállító, futár, szerelő…">
                        <?php $partnerek = self::partnerek(); ?>
                        <?php if ($partnerek !== []) : ?>
                            <div class="sdh-pt-gyors" aria-label="Gyakori partnerek">
                                <?php foreach ($partnerek as $p) : ?>
                                    <button type="button" class="sdh-pt-cimke" data-sdh-pt-partner="<?php echo esc_attr($p); ?>"><?php echo esc_html($p); ?></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="sdh-ig" data-sdh-pt-csak="kivet">
                        <span class="sdh-ig__cimke">Ki vette ki <span class="sdh-kotelezo">*</span></span>
                        <?php self::pillek('szemely', array_combine(array_column($b['szemelyek'], 'nev'), array_column($b['szemelyek'], 'nev')) ?: [], $szemely); ?>
                    </div>

                    <div class="sdh-ig" data-sdh-pt-csak="bevetel">
                        <span class="sdh-ig__cimke">Fizetési mód</span>
                        <?php self::pillek('mod', ['kp' => 'Készpénz', 'kartya' => 'Bankkártya', 'utalas' => 'Utalás', 'vegyes' => 'Vegyes'], $mod, ' data-sdh-pt-mod'); ?>
                    </div>

                    <div data-sdh-pt-egy>
                        <?php self::osszeg_mezo('osszeg', 'pt_osszeg', $osszeg, 'Összeg', true); ?>
                    </div>

                    <div class="sdh-pt-urlap__vegyes" data-sdh-pt-vegyes>
                        <?php self::osszeg_mezo('kp', 'pt_kp', $kp, 'Készpénz'); ?>
                        <?php self::osszeg_mezo('kartya', 'pt_kartya', $kartya, 'Bankkártya'); ?>
                        <?php self::osszeg_mezo('utalas', 'pt_utalas', $utalas, 'Utalás'); ?>
                    </div>
                </div>

                <div class="sdh-pt-urlap__oszlop">
                    <div class="sdh-ig" data-sdh-pt-csak="bevetel">
                        <label for="pt_ml">Munkalap sorszám</label>
                        <input type="text" name="ml" id="pt_ml" maxlength="40" value="<?php echo esc_attr($ml); ?>" inputmode="numeric" autocomplete="off" data-sdh-pt-ml>
                        <span class="sdh-mezo__sugo" data-sdh-pt-ml-info></span>
                    </div>

                    <div class="sdh-ig" data-sdh-pt-csak="bevetel kifizetes">
                        <label for="pt_szamla">Számlaszám</label>
                        <input type="text" name="szamla" id="pt_szamla" maxlength="60" value="<?php echo esc_attr($szamla); ?>" autocomplete="off" placeholder="SD-<?php echo esc_attr(wp_date('Y')); ?>-…">
                    </div>

                    <div class="sdh-ig" data-sdh-pt-csak="bevetel">
                        <label for="pt_vonalkod">Vonalkód / IMEI</label>
                        <input type="text" name="vonalkod" id="pt_vonalkod" maxlength="190" value="<?php echo esc_attr($vonal); ?>" autocomplete="off">
                    </div>

                    <div class="sdh-ig">
                        <label for="pt_datum">Nap</label>
                        <input type="text" name="datum" id="pt_datum" class="sdh-pop sdh-pop--datum" readonly autocomplete="off"
                               data-sdh-pop="datum" data-sdh-pop-adat="[]" value="<?php echo esc_attr($datum); ?>">
                    </div>

                    <div class="sdh-ig">
                        <label for="pt_megj">Megjegyzés</label>
                        <input type="text" name="megj" id="pt_megj" maxlength="255" value="<?php echo esc_attr($megj); ?>" autocomplete="off">
                    </div>
                </div>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges"><?php echo $t ? 'Mentés' : 'Rögzítés'; ?></button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                <?php if ($t) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-pt-veszely" data-sdh-pt-torol="<?php echo (int) $t->id; ?>">Törlés</button>
                    <span class="sdh-mezo__sugo">Rögzítette: <?php echo esc_html(self::felhasznalo_nev((int) $t->felhasznalo) ?: ((string) $t->forras === 'import' ? 'import' : '—')); ?><?php echo $t->ido ? ', ' . esc_html((string) $t->ido) : ''; ?></span>
                <?php endif; ?>
            </div>
        </form>
        <?php
        wp_die();
    }

    /**
     * A munkalap „Fizetés" ablaka: a fizetendő összeg, a mód, és hogy a
     * pénztárba is bekerüljön-e. Előleg is innen rögzíthető.
     */
    public static function ajax_fizetes_urlap(): void
    {
        self::jog(false);

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $m  = self::munkalap($id);

        if ($m === null) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Előbb mentsd el a munkalapot.</div>';
            wp_die();
        }

        $info   = self::munkalap_info($m);
        $hatra  = round(($info['fizetve'] ? $info['brutto'] : $info['brutto'] - $info['eloleg']), 2);
        $mar    = $info['kasszaban'];
        $javasolt = $info['fizetve'] ? max(0.0, round($info['brutto'] - $mar, 2)) : max(0.0, $hatra);

        ?>
        <form class="sdh-urlap sdh-pt-urlap sdh-pt-fizetes" data-sdh-ajax-action="sdh_muhely_penztar_fizet" data-sdh-pt-urlap data-tipus="bevetel" novalidate>
            <input type="hidden" name="munkalap_id" value="<?php echo (int) $info['id']; ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">

            <h2 class="sdh-modal__cim">Fizetés<?php echo $info['szam'] !== '' ? ' – ' . esc_html(SDH_Muhely_Munkalap::szam_formaz($info['szam'])) : ''; ?></h2>

            <div class="sdh-pt-fizetes__fej">
                <div><span>Munkalap értéke</span><strong><?php echo esc_html(self::penz($info['brutto'])); ?></strong></div>
                <div><span>Előleg</span><strong><?php echo esc_html(self::penz($info['eloleg'])); ?></strong></div>
                <div><span>Pénztárban eddig</span><strong><?php echo esc_html(self::penz($mar)); ?></strong></div>
                <div class="sdh-pt-fizetes__fo"><span><?php echo $info['fizetve'] ? 'Fizetve' : 'Fizetendő'; ?></span><strong><?php echo esc_html(self::penz($info['fizetve'] ? 0 : $hatra)); ?></strong></div>
            </div>

            <?php self::pillek('fajta', ['teljes' => 'Teljes fizetés – lezárom', 'eloleg' => 'Előleg / részfizetés'], 'teljes'); ?>

            <div class="sdh-pt-urlap__racs">
                <div class="sdh-pt-urlap__oszlop">
                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Fizetési mód</span>
                        <?php self::pillek('mod', ['kp' => 'Készpénz', 'kartya' => 'Bankkártya', 'utalas' => 'Utalás', 'vegyes' => 'Vegyes'], $info['mod'], ' data-sdh-pt-mod'); ?>
                    </div>

                    <div data-sdh-pt-egy>
                        <?php self::osszeg_mezo('osszeg', 'ptf_osszeg', $javasolt, 'Kapott összeg', true); ?>
                    </div>

                    <div class="sdh-pt-urlap__vegyes" data-sdh-pt-vegyes>
                        <?php self::osszeg_mezo('kp', 'ptf_kp', 0, 'Készpénz'); ?>
                        <?php self::osszeg_mezo('kartya', 'ptf_kartya', 0, 'Bankkártya'); ?>
                    </div>

                    <label class="sdh-jelolo sdh-pt-fizetes__pipa">
                        <input type="checkbox" name="kasszaba" value="1" checked> Kerüljön be a házipénztárba
                    </label>
                </div>

                <div class="sdh-pt-urlap__oszlop">
                    <div class="sdh-ig">
                        <label for="ptf_nev">Név</label>
                        <input type="text" name="nev" id="ptf_nev" maxlength="190" value="<?php echo esc_attr($info['nev']); ?>" autocomplete="off">
                    </div>
                    <div class="sdh-ig">
                        <label for="ptf_leiras">Mi történt</label>
                        <input type="text" name="leiras" id="ptf_leiras" maxlength="255" value="<?php echo esc_attr($info['leiras']); ?>" autocomplete="off">
                    </div>
                    <div class="sdh-ig">
                        <label for="ptf_szamla">Számlaszám</label>
                        <input type="text" name="szamla" id="ptf_szamla" maxlength="60" value="<?php echo esc_attr($info['szamla']); ?>" autocomplete="off" placeholder="A számla kiállítása után magától beíródik">
                    </div>
                </div>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Fizetés rögzítése</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            </div>
        </form>
        <?php
        wp_die();
    }

    /**
     * A fizetés rögzítése: a munkalap fizetési adatai + (ha kérték) a pénztártétel.
     * Kasszába nem kért fizetésnél „nem került kasszába" jelölés marad.
     */
    public static function ajax_fizet(): void
    {
        global $wpdb;

        self::jog();

        $m = self::munkalap((int) self::post('munkalap_id', 0));

        if ($m === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen munkalap.']);
        }

        $info  = self::munkalap_info($m);
        $mod   = self::post_szoveg('mod', 10);
        $mod   = in_array($mod, ['kp', 'kartya', 'utalas', 'vegyes'], true) ? $mod : 'kp';
        $fajta = self::post_szoveg('fajta', 10) === 'eloleg' ? 'eloleg' : 'teljes';

        if ($mod === 'vegyes') {
            $kp     = abs(self::szam(self::post('kp', '0')));
            $kartya = abs(self::szam(self::post('kartya', '0')));
            $utalas = 0.0;
        } else {
            $o      = abs(self::szam(self::post('osszeg', '0')));
            $kp     = $mod === 'kp' ? $o : 0.0;
            $kartya = $mod === 'kartya' ? $o : 0.0;
            $utalas = $mod === 'utalas' ? $o : 0.0;
        }

        $osszeg = round($kp + $kartya + $utalas, 2);

        if ($osszeg <= 0 && !($fajta === 'teljes' && $info['fizetendo'] <= 0)) {
            wp_send_json_error(['uzenet' => 'Add meg a kapott összeget.']);
        }

        // A munkalap fizetési adatai: a mód neve a munkalap listájából (ha ott van ilyen).
        $mod_nev = ['kp' => 'Készpénz', 'kartya' => 'Bankkártya', 'utalas' => 'Átutalás', 'vegyes' => 'Kombinált'][$mod];

        if (self::mod_oszlop((string) $m->fizetesi_mod) === $mod && (string) $m->fizetesi_mod !== '') {
            $mod_nev = (string) $m->fizetesi_mod;
        }

        $frissit = ['modositva' => current_time('mysql')];

        if ($fajta === 'teljes') {
            $frissit += ['fizetve' => 1, 'fizetes_ideje' => self::ma(), 'fizetesi_mod' => mb_substr($mod_nev, 0, 40)];
        } else {
            $frissit += ['fizetett' => round((float) $m->fizetett + $osszeg, 2), 'fizetesi_mod' => (string) $m->fizetesi_mod !== '' ? (string) $m->fizetesi_mod : mb_substr($mod_nev, 0, 40)];
        }

        $wpdb->update(SDH_Muhely_Schema::tabla('munkalap'), $frissit, ['id' => (int) $m->id]);

        if (class_exists('SDH_Muhely_Szamla') && method_exists('SDH_Muhely_Szamla', 'bevizsgalas_szinkron')) {
            SDH_Muhely_Szamla::bevizsgalas_szinkron((int) $m->id);
        }

        $leiras = self::post_szoveg('leiras', 255);
        $leiras = $leiras !== '' ? $leiras : $info['leiras'];

        if ($fajta === 'eloleg' && stripos($leiras, 'előleg') === false) {
            $leiras = 'Előleg – ' . $leiras;
        }

        $tetel_id = 0;

        if ($osszeg > 0) {
            $adat = [
                'datum'         => self::ma(),
                'tipus'         => self::post('kasszaba', '') === '1' ? 'bevetel' : 'kihagyva',
                'leiras'        => mb_substr($leiras, 0, 255),
                'nev'           => self::post_szoveg('nev', 190),
                'szemely'       => '',
                'kp'            => 0,
                'kartya'        => 0,
                'utalas'        => 0,
                'info_osszeg'   => 0,
                'munkalap_szam' => $info['szam'],
                'munkalap_id'   => $info['id'],
                'szamlaszam'    => self::post_szoveg('szamla', 60),
                'vonalkod'      => '',
                'megjegyzes'    => '',
            ];

            if ($adat['tipus'] === 'bevetel') {
                $adat['kp'] = $kp;
                $adat['kartya'] = $kartya;
                $adat['utalas'] = $utalas;
            } else {
                $adat['info_osszeg'] = $osszeg;
                $adat['megjegyzes']  = 'Fizetéskor kérésre nem került a kasszába.';
            }

            $tetel_id = self::ment($adat, 0, 'munkalap');
        }

        wp_send_json_success([
            'munkalap_id' => (int) $m->id,
            'tetel_id'    => $tetel_id,
            'uzenet'      => $osszeg > 0
                ? ($fajta === 'eloleg' ? 'Előleg rögzítve: ' : 'Fizetve: ') . self::penz($osszeg) . (self::post('kasszaba', '') === '1' ? ' – a pénztárba is bekerült.' : '.')
                : 'A munkalap fizetettre állítva.',
        ]);
    }

    /* =================================================================
     * Beállítások doboz
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();
        $mentett = get_option(self::OPTION, []);
        $mentett = is_array($mentett) ? $mentett : [];

        ?>
        <div class="sdh-doboz" id="penztar">
            <h2 class="sdh-doboz__cim">Házipénztár</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="penztar_szemelyek">Kivétre jogosultak</label>
                    <textarea name="penztar[szemelyek]" id="penztar_szemelyek" rows="3" class="sdh-kod"><?php echo esc_textarea($b['szemely_szoveg']); ?></textarea>
                    <span class="sdh-mezo__sugo">Soronként egy: <code>Teljes név | becenév, becenév</code>. A pénzkivétnél csak közülük lehet választani; a becenevekből ismeri fel az importált sorokat.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="penztar_cimletek">Címletek</label>
                    <input type="text" name="penztar[cimletek]" id="penztar_cimletek" value="<?php echo esc_attr(implode(', ', $b['cimletek'])); ?>">
                    <span class="sdh-mezo__sugo">A kassza megszámolásához (Ft).</span>
                </div>

                <div class="sdh-mezo">
                    <label for="penztar_kp_modok">Készpénzes fizetési módok</label>
                    <input type="text" name="penztar[kp_modok]" id="penztar_kp_modok" value="<?php echo esc_attr(implode(', ', $b['kp_modok'])); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="penztar_kartya_modok">Kártyás fizetési módok</label>
                    <input type="text" name="penztar[kartya_modok]" id="penztar_kartya_modok" value="<?php echo esc_attr(implode(', ', $b['kartya_modok'])); ?>">
                    <span class="sdh-mezo__sugo">A munkalap többi fizetési módja „utalás" oszlopba kerül (nem érinti a kasszát).</span>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="penztar_kategoriak">Statisztikai kategóriák</label>
                    <textarea name="penztar[kategoriak]" id="penztar_kategoriak" rows="6" class="sdh-kod"><?php echo esc_textarea((string) ($mentett['kategoriak'] ?? '') !== '' ? (string) $mentett['kategoriak'] : self::alap_kategoriak()); ?></textarea>
                    <span class="sdh-mezo__sugo">Soronként: <code>Kategória: kulcsszó, kulcsszó</code>. A bevétel leírásában az első egyező sor nyer. Üresen hagyva az alap lista jön vissza.</span>
                </div>
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="hidden" name="penztar[ajanlat]" value="0">
                <input type="checkbox" name="penztar[ajanlat]" id="penztar_ajanlat" value="1" <?php checked($b['ajanlat']); ?>>
                <label for="penztar_ajanlat">Munkalap fizetésekor ajánlja fel a pénztárba írást</label>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        $be = isset($_POST['penztar']) && is_array($_POST['penztar']) ? wp_unslash($_POST['penztar']) : null;

        if ($be === null) {
            return;
        }

        $szoveg = static fn (string $k) => isset($be[$k]) && is_scalar($be[$k]) ? sanitize_textarea_field((string) $be[$k]) : '';

        update_option(self::OPTION, [
            'szemelyek'    => $szoveg('szemelyek'),
            'cimletek'     => $szoveg('cimletek'),
            'kp_modok'     => $szoveg('kp_modok'),
            'kartya_modok' => $szoveg('kartya_modok'),
            'kategoriak'   => trim($szoveg('kategoriak')) === trim(self::alap_kategoriak()) ? '' : $szoveg('kategoriak'),
            'ajanlat'      => !empty($be['ajanlat']),
        ], false);
    }

    /* =================================================================
     * Áttekintés csempe
     * ============================================================== */

    /** A kasszában most várható készpénz (a mai nap záró összege). */
    public static function kassza_most(): int
    {
        $nap = self::nap(self::ma());

        if ($nap === null) {
            global $wpdb;

            $elozo = $wpdb->get_row($wpdb->prepare('SELECT szamolt, zaro FROM ' . self::nap_tabla() . ' WHERE datum < %s ORDER BY datum DESC LIMIT 1', self::ma())); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            return $elozo ? (int) round($elozo->szamolt !== null ? (float) $elozo->szamolt : (float) $elozo->zaro) : 0;
        }

        return (int) round((float) $nap->zaro);
    }

    /* =================================================================
     * Az oldal
     * ============================================================== */

    public static function admin_eszkozok(): void
    {
        $oldal = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!str_starts_with($oldal, SDH_Muhely_Admin_UI::FOMENU)) {
            return;
        }

        wp_enqueue_style('sdh-muhely-penztar', SDH_MUHELY_URL . 'assets/penztar.css', ['sdh-muhely-admin'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/penztar.css'));
        // Minden CRM-oldalon kell: a munkalap Fizetés gombja és ajánlata bárhol felbukkanhat.
        wp_enqueue_script('sdh-muhely-penztar', SDH_MUHELY_URL . 'assets/penztar.js', ['sdh-muhely-app'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/penztar.js'), true);
    }

    public static function oldal(): void
    {
        if (current_time('Y-m-d') !== '') {
            self::nap_biztosit(self::ma());
        }

        ?>
        <div class="sdh-wrap sdh-pt-oldal">
            <div class="sdh-pt" data-sdh-penztar
                 data-indulo="<?php echo esc_attr((string) wp_json_encode(self::indulo())); ?>"
                 data-munkalap-url="<?php echo esc_attr(SDH_Muhely_Modulok::url('munkalapok')); ?>">
                <noscript><div class="sdh-uzenet sdh-uzenet--hiba">A pénztárhoz JavaScript kell.</div></noscript>
            </div>
        </div>
        <?php
    }
}
