<?php
/**
 * Munkalapszoftver Clone (0.99.6) – a MunkaLap 3 teljes mentésének átvétele, minden adattal.
 *
 * Ki-be kapcsolható modul (Modulok › „Munkalapszoftver Clone”), a felülete: Beállítások › Rendszer ›
 * Munkalapszoftver Clone.
 *
 * A folyamat:
 *  1. Forrás: a MunkaLap 3 mentése (.wdg – egy ZIP, benne a program Apache Derby adatbázisa) vagy az átalakító
 *     által készített csomag (.zip). Feltöltés darabolva (nincs méretkorlát), vagy útvonal a szerveren.
 *  2. Átalakítás: a Derby-adatbázist PHP nem tudja olvasni, ezért egy kis Java-átalakító (assets/ml3/) olvassa ki –
 *     a MunkaLap 3 SAJÁT Javájával és Derby-motorjával (a programmappájában van), így semmit nem kell telepíteni.
 *     Ha a szerver nem futtathat programot, az átalakító letölthető, és a MunkaLap gépén egy kattintással elkészíti
 *     a csomagot (Atalakitas.bat), amit ide fel lehet tölteni.
 *  3. Előkészítés: a csomag kibontása, ellenőrzése (táblánként darabszám + SHA-1), a párosítási javaslat
 *     (egyedi mezők, felhasználók).
 *  4. Átvétel: táblánként, időkeretes adagokban (tranzakcióban), megszakítás után folytatható. Minden régi sor a
 *     nyers tárba kerül (100%: ami nem illik sehova, az is megmarad, böngészhető), és ha van leképezése
 *     (SDH_Muhely_Ml3_Lekepzes), új rekord lesz belőle. Az újabb mentés újraátvétele csak a változást írja.
 *  5. Zárás és ellenőrző riport: készlet újraszámolása, darabszámok és végösszegek egyeztetése.
 *  Visszavonás: minden, amit a modul létrehozott, törölhető (a meglévő rekordokhoz nem nyúl).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Ml3
{
    public const MODUL      = 'munkalapclone';
    public const OPT        = 'sdh_muhely_ml3_allapot';
    public const OPT_BEALL  = 'sdh_muhely_ml3_beallitasok';
    public const OPT_DB     = 'sdh_muhely_ml3_db_version';
    public const DB_VERZIO  = '1.0.0';
    public const IMPORT_ID  = 'ml3';
    public const FORRAS     = 'munkalap3';
    public const MAPPA      = 'sdh-muhely/munkalap3';
    public const IDOKERET   = 8.0;
    public const RESZ_MERET = 8388608; // 8 MB / feltöltött darab

    /** A táblák feldolgozási sorrendje (ami nincs itt, az a végén, ábécérendben, csak a nyers tárba). */
    public const SORREND = [
        'USERS.USER_DATA', 'USERS.USER_PRIVILEGES', 'APP.USER_GROUP', 'APP.USER_GROUP_PRIVILEGE', 'APP.CITY',
        'APP.CUSTOMER_CATEGORY', 'APP.ITEM_CATEGORY', 'APP.PROBLEM_CATEGORY', 'APP.DATA_COLUMN', 'APP.DATA_ELEMENT',
        'APP.DATA_PRIVILEGE', 'APP.ADDRESS', 'APP.CUSTOMER', 'APP.CUSTOMER_ATTACHEMENT', 'APP.THING', 'APP.THING_ATTACHEMENT',
        'APP.MASTER_ITEM', 'APP.WORKSHEET', 'APP.WORKSHEET_ATTACHEMENT', 'APP.PROBLEM', 'APP.ITEM', 'APP.EMAIL',
        'APP.EMAIL_ATTACHEMENT',
    ];

    /** A rendszer táblái a követési kulcs szerint (atvetel_sor.tabla). */
    public const KOVETETT = [
        'mozgas'     => 'termek_mozgas',
        'naplo'      => 'munkalap_naplo',
        'tetel'      => 'munkalap_tetel',
        'hiba'       => 'munkalap_hiba',
        'csatolmany' => 'csatolmany',
        'munkalap'   => 'munkalap',
        'szolg'      => 'szolgaltatas',
        'termek'     => 'termek',
        'eszkoz'     => 'eszkoz',
        'ugyfel'     => 'ugyfel',
    ];

    /** Táblák, amelyeket egészben a memóriába töltünk (kicsik, sokszor kellenek). */
    private const KICSI = ['USERS.USER_DATA', 'APP.CITY', 'APP.CUSTOMER_CATEGORY', 'APP.ITEM_CATEGORY', 'APP.DATA_COLUMN'];

    /** @var array<string, mixed>|null */
    private static ?array $allapot = null;

    /** @var array<string, array<string, int>> */
    private static array $uj_tar = [];

    /** @var array<string, array<string, array|null>> */
    private static array $nyers_tar = [];

    /** @var array<int, string> az adag után törlendő forrásfájlok */
    private static array $torlendo = [];

    /** @var array<string, array<string, string>> tábla => kulcs => lenyomat (a már bent lévő régi sorok) */
    private static array $hash_tar = [];

    /** @var array<int, array{0: string, 1: int}> a még ki nem írt követett azonosítók */
    private static array $kovet_buf = [];

    /** @var array<int, bool>|null a foglalt munkalapszámok */
    private static ?array $szam_tar = null;

    private static float $indult = 0.0;

    public static function init(): void
    {
        foreach (['feltolt_resz', 'szerver_fajl', 'lepes', 'indit', 'visszavon', 'munkafajlok', 'alaphelyzet', 'beall_ment', 'regi', 'regi_fajl', 'atalakito'] as $m) {
            add_action('wp_ajax_sdh_muhely_ml3_' . $m, [self::class, 'ajax_' . $m]);
        }
    }

    /* =================================================================
     * Tároló és állapot
     * ============================================================== */

    public static function nyers_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('ml3_nyers');
    }

    /** A modul saját táblája (a fő sémától függetlenül, hogy ki-be kapcsolható modul maradjon). */
    public static function tabla_telepit(): void
    {
        if (get_option(self::OPT_DB) === self::DB_VERZIO) {
            return;
        }

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta('CREATE TABLE ' . self::nyers_tabla() . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            tabla varchar(40) NOT NULL default '',
            kulcs varchar(64) NOT NULL default '',
            szulo varchar(64) NOT NULL default '',
            adat longtext NULL,
            hash char(32) NOT NULL default '',
            uj_tabla varchar(30) NOT NULL default '',
            uj_id bigint(20) unsigned NOT NULL default 0,
            letrehozta tinyint(1) NOT NULL default 0,
            kapcsolt varchar(255) NOT NULL default '',
            megj varchar(255) NOT NULL default '',
            atveve datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tabla_kulcs (tabla,kulcs),
            KEY tabla_szulo (tabla,szulo),
            KEY uj (uj_tabla,uj_id)
        ) " . $wpdb->get_charset_collate() . ';');

        update_option(self::OPT_DB, self::DB_VERZIO, false);
    }

    /** A modul védett mappája (uploads/sdh-muhely/munkalap3[/al]). */
    public static function mappa(string $al = ''): string
    {
        $f     = wp_upload_dir();
        $alap  = trailingslashit($f['basedir']) . self::MAPPA;
        $mappa = $al !== '' ? $alap . '/' . trim($al, '/') : $alap;

        if (!is_dir($mappa)) {
            wp_mkdir_p($mappa);
        }

        if (!file_exists($alap . '/.htaccess')) {
            file_put_contents($alap . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            file_put_contents($alap . '/index.php', "<?php\n// Csend az aranyat ér.\n");
        }

        return $mappa;
    }

    /** @return array<string, mixed> */
    public static function allapot(): array
    {
        if (self::$allapot === null) {
            $a = get_option(self::OPT, []);
            self::$allapot = is_array($a) && $a !== [] ? $a : ['fazis' => 'ures'];
        }

        return self::$allapot;
    }

    /** @param array<string, mixed> $a */
    private static function allapot_ment(array $a): void
    {
        self::$allapot = $a;
        update_option(self::OPT, $a, false);
    }

    /** Teszthez: a gyorsítótárak ürítése. */
    public static function urit(): void
    {
        self::$allapot   = null;
        self::$uj_tar    = [];
        self::$nyers_tar = [];
        self::$hash_tar  = [];
        self::$szam_tar  = null;
        self::munkalap_gyorsitotar_urit();
    }

    /** Az átvétel beállításai (párosítások, kapcsolók). @return array<string, mixed> */
    public static function beallitas(): array
    {
        return (array) (self::allapot()['beallitas'] ?? []);
    }

    /** A modul saját beállításai (MunkaLap mappa, Java). @return array<string, string> */
    public static function modul_beall(): array
    {
        $b = get_option(self::OPT_BEALL, []);

        return array_merge(['mappa' => '', 'java' => ''], is_array($b) ? array_map('strval', $b) : []);
    }

    private static function hiba(array &$a, string $uzenet): void
    {
        $a['hibak']   = array_slice(array_merge((array) ($a['hibak'] ?? []), [current_time('H:i:s') . ' ' . $uzenet]), -60);
        $a['hibak_db'] = (int) ($a['hibak_db'] ?? 0) + 1;
    }

    /* =================================================================
     * Nyers tár – hivatkozások feloldása
     * ============================================================== */

    /** A régi sor (dekódolva) a nyers tárból, vagy null. */
    public static function nyers(string $tabla, string $kulcs): ?array
    {
        global $wpdb;

        if ($kulcs === '') {
            return null;
        }

        if (in_array($tabla, self::KICSI, true) && !isset(self::$nyers_tar[$tabla])) {
            self::$nyers_tar[$tabla] = [];

            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT kulcs, adat FROM ' . self::nyers_tabla() . ' WHERE tabla = %s', $tabla)) as $s) {
                $d = json_decode((string) $s->adat, true);
                self::$nyers_tar[$tabla][(string) $s->kulcs] = is_array($d) ? $d : null;
            }
        }

        if (isset(self::$nyers_tar[$tabla]) && array_key_exists($kulcs, self::$nyers_tar[$tabla])) {
            return self::$nyers_tar[$tabla][$kulcs];
        }

        if (in_array($tabla, self::KICSI, true)) {
            return null;
        }

        $adat = $wpdb->get_var($wpdb->prepare('SELECT adat FROM ' . self::nyers_tabla() . ' WHERE tabla = %s AND kulcs = %s', $tabla, $kulcs));
        $d    = $adat !== null ? json_decode((string) $adat, true) : null;

        if (count(self::$nyers_tar[$tabla] ?? []) > 5000) {
            self::$nyers_tar[$tabla] = [];
        }

        return self::$nyers_tar[$tabla][$kulcs] = is_array($d) ? $d : null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function nyers_szulo(string $tabla, string $szulo): array
    {
        global $wpdb;

        $ki = [];

        foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT adat FROM ' . self::nyers_tabla() . ' WHERE tabla = %s AND szulo = %s ORDER BY id', $tabla, $szulo)) as $a) {
            $d = json_decode((string) $a, true);

            if (is_array($d)) {
                $ki[] = $d;
            }
        }

        return $ki;
    }

    /** A régi sorból lett új rekord azonosítója (0, ha nincs). */
    public static function uj_id(string $tabla, $kulcs): int
    {
        global $wpdb;

        $kulcs = (string) $kulcs;

        if ($kulcs === '') {
            return 0;
        }

        if (!isset(self::$uj_tar[$tabla])) {
            self::$uj_tar[$tabla] = [];

            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT kulcs, uj_id FROM ' . self::nyers_tabla() . ' WHERE tabla = %s AND uj_id > 0', $tabla)) as $s) {
                self::$uj_tar[$tabla][(string) $s->kulcs] = (int) $s->uj_id;
            }
        }

        return self::$uj_tar[$tabla][$kulcs] ?? 0;
    }

    /** Az egyedi mezők (DATA_n) nevei és típusai egy táblához: id => [nev, tipus, aktiv]. */
    public static function egyedi_mezo_nevek(string $tabla): array
    {
        return (array) (self::allapot()['egyedi'][$tabla] ?? []);
    }

    private static function kovet(string $kulcs, int $id): void
    {
        if ($id > 0) {
            self::$kovet_buf[] = [$kulcs, $id];

            if (count(self::$kovet_buf) >= 500) {
                self::kovet_ir();
            }
        }
    }

    /** A követett azonosítók kiírása (többsoros beszúrással). */
    private static function kovet_ir(): void
    {
        global $wpdb;

        if (self::$kovet_buf === []) {
            return;
        }

        $ertekek = [];

        foreach (self::$kovet_buf as [$k, $id]) {
            $ertekek[] = $wpdb->prepare('(%s, %s, %d)', self::IMPORT_ID, $k, $id);
        }

        self::$kovet_buf = [];
        $wpdb->query('INSERT INTO ' . SDH_Muhely_Schema::tabla('atvetel_sor') . ' (import_id, tabla, sor_id) VALUES ' . implode(',', $ertekek)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /* =================================================================
     * Átalakító (Java)
     * ============================================================== */

    public static function jar_ut(): string
    {
        return SDH_MUHELY_DIR . 'assets/ml3/munkalap-atalakito.jar';
    }

    private static function futtathat(): bool
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            return false;
        }

        $tiltott = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('proc_open', $tiltott, true);
    }

    /** A MunkaLap 3 programmappája: a beállított, vagy a szokásos helyek egyike. */
    public static function munkalap_mappa(): string
    {
        $b     = self::modul_beall();
        $jelok = [];

        if ($b['mappa'] !== '') {
            $jelok[] = $b['mappa'];
        }

        foreach (['ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432'] as $v) {
            $pf = getenv($v);

            if (is_string($pf) && $pf !== '') {
                $jelok[] = $pf . '\\MunkaLap\\MunkaLap-3';
                $jelok[] = $pf . '\\MunkaLap-3';
            }
        }

        $jelok = array_merge($jelok, ['C:\\MunkaLap\\MunkaLap-3', 'D:\\MunkaLap\\MunkaLap-3', 'C:\\MunkaLap-3', '/opt/MunkaLap-3']);

        foreach ($jelok as $m) {
            $m = rtrim($m, '\\/');

            if (@is_file($m . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'derby.jar') || @is_file($m . '/lib/derby.jar')) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                return $m;
            }
        }

        return '';
    }

    /**
     * A futtatáshoz kellő parancs részei, vagy a hiba oka.
     *
     * @return array{java: string, cp: string}|string
     */
    public static function atalakito()
    {
        if (!is_file(self::jar_ut())) {
            return 'Hiányzik az átalakító (assets/ml3/munkalap-atalakito.jar).';
        }

        if (!self::futtathat()) {
            return 'Ezen a szerveren nem indítható program (proc_open tiltva).';
        }

        $b     = self::modul_beall();
        $mappa = self::munkalap_mappa();
        $lib   = $mappa !== '' ? $mappa . DIRECTORY_SEPARATOR . 'lib' : '';

        if ($lib === '' || !is_file($lib . DIRECTORY_SEPARATOR . 'derby.jar')) {
            return 'Nem találom a MunkaLap 3 programmappáját (benne: lib\\derby.jar) – add meg lent.';
        }

        $java = $b['java'];

        if ($java === '') {
            foreach (['jre/win-64/bin/java.exe', 'jre/win-32/bin/java.exe', 'jre/bin/java.exe', 'jre/bin/java'] as $j) {
                if (is_file($mappa . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $j))) {
                    $java = $mappa . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $j);
                    break;
                }
            }
        }

        $java = $java !== '' ? $java : 'java';
        $cp   = [self::jar_ut(), $lib . DIRECTORY_SEPARATOR . 'derby.jar'];

        foreach (['derbyshared.jar', 'derbyLocale_hu.jar'] as $j) {
            if (is_file($lib . DIRECTORY_SEPARATOR . $j)) {
                $cp[] = $lib . DIRECTORY_SEPARATOR . $j;
            }
        }

        return ['java' => $java, 'cp' => implode(PATH_SEPARATOR, $cp)];
    }

    /** Rövid ellenőrzés: elindul-e a Java (verziószöveg vagy hiba). */
    public static function java_proba(): string
    {
        $a = self::atalakito();

        if (is_string($a)) {
            return $a;
        }

        $ki = self::parancs([$a['java'], '-version'], 20);

        return $ki['kod'] === 0 ? 'OK: ' . trim((string) strtok($ki['kimenet'], "\n")) : 'A Java nem indult el (' . $a['java'] . '): ' . mb_substr(trim($ki['kimenet']), 0, 200);
    }

    /**
     * Egy parancs futtatása, megvárva (rövid feladathoz).
     *
     * @param array<int, string> $argv
     * @return array{kod: int, kimenet: string}
     */
    private static function parancs(array $argv, int $max_mp): array
    {
        $cmd = implode(' ', array_map('escapeshellarg', $argv));
        $p   = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cso); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if (!is_resource($p)) {
            return ['kod' => -1, 'kimenet' => 'nem indítható'];
        }

        stream_set_blocking($cso[1], false);
        stream_set_blocking($cso[2], false);
        $ki    = '';
        $vege  = microtime(true) + $max_mp;

        while (microtime(true) < $vege) {
            $ki .= (string) stream_get_contents($cso[1]) . (string) stream_get_contents($cso[2]);
            $st = proc_get_status($p);

            if (!$st['running']) {
                $ki .= (string) stream_get_contents($cso[1]) . (string) stream_get_contents($cso[2]);
                fclose($cso[1]);
                fclose($cso[2]);
                proc_close($p);

                return ['kod' => (int) $st['exitcode'], 'kimenet' => $ki];
            }

            usleep(100000);
        }

        proc_terminate($p);

        return ['kod' => -2, 'kimenet' => $ki . ' (időtúllépés)'];
    }

    /** Az átalakító indítása a háttérben (a böngészőkérés nem várja meg). */
    private static function atalakito_indit(array &$a): bool
    {
        $at = self::atalakito();

        if (is_string($at)) {
            $a['fazis']  = 'atalakitora_var';
            $a['uzenet'] = $at;

            return false;
        }

        $munka   = self::mappa('munka');
        $kimenet = $munka . '/csomag.zip';
        $all     = $munka . '/atalakito.json';
        $naplo   = $munka . '/atalakito.log';
        @unlink($all); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        // Ékezetes útvonalat nem adunk át a parancssornak (Windows-on a kódlap elronthatja): előbb ide másoljuk.
        $be = (string) $a['forras']['ut'];

        if (preg_match('/[^\x20-\x7E]/', $be) === 1) {
            $masolat = $munka . '/forras.wdg';

            if (!@copy($be, $masolat)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                $a['fazis']  = 'hiba';
                $a['uzenet'] = 'A mentés nem másolható a munkamappába (kevés a hely?).';

                return false;
            }

            $be = $masolat;
        }

        $argv = [$at['java'], '-Xmx768m', '-cp', $at['cp'], 'MunkaLapAtalakito', $be, $kimenet, '--allapot', $all, '--temp', $munka];
        $cmd  = implode(' ', array_map('escapeshellarg', $argv));

        if (DIRECTORY_SEPARATOR === '\\') {
            $h = @popen('start "" /B ' . $cmd . ' > ' . escapeshellarg($naplo) . ' 2>&1', 'r'); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            if ($h === false) {
                $a['uzenet'] = 'Az átalakító nem indult el.';

                return false;
            }

            pclose($h);
        } else {
            exec('nohup ' . $cmd . ' > ' . escapeshellarg($naplo) . ' 2>&1 &'); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
        }

        $a['fazis']      = 'atalakitas';
        $a['atalakitas'] = ['indult' => time(), 'kimenet' => $kimenet, 'allapot' => $all, 'naplo' => $naplo];
        $a['uzenet']     = 'Az átalakító fut…';

        return true;
    }

    /* =================================================================
     * Forrás: feltöltés, szerveren lévő fájl
     * ============================================================== */

    /** Mi van a ZIP-ben: 'wdg' (MunkaLap mentés), 'csomag' (átalakított) vagy hibaüzenet. */
    public static function forras_tipus(string $ut): string
    {
        if (!class_exists('ZipArchive')) {
            return 'A szerveren nincs ZIP-kezelő (PHP zip).';
        }

        $z = new ZipArchive();

        if ($z->open($ut) !== true) {
            return 'A fájl nem MunkaLap 3 mentés (nem nyitható ZIP-ként).';
        }

        $tipus = '';

        if ($z->locateName('manifest.json') !== false) {
            $m     = json_decode((string) $z->getFromName('manifest.json'), true);
            $tipus = is_array($m) && ($m['formatum'] ?? '') === 'munkalap3-csomag' ? 'csomag' : '';
        } elseif ($z->locateName('database/service.properties') !== false) {
            $tipus = 'wdg';
        }

        $z->close();

        return $tipus !== '' ? $tipus : 'A ZIP-ben nincs MunkaLap 3 adatbázis (database/service.properties) és átalakított csomag sem.';
    }

    /** A forrás rögzítése (feltöltés vagy útvonal után). Hibánál üzenet, különben ''. */
    public static function forras_be(string $ut, string $nev, bool $sajat): string
    {
        $tipus = self::forras_tipus($ut);

        if (!in_array($tipus, ['wdg', 'csomag'], true)) {
            return $tipus;
        }

        $a = self::allapot();

        if (in_array((string) ($a['fazis'] ?? ''), ['atvetel', 'visszavonas'], true)) {
            return 'Egy átvétel még fut – előbb fejezd be vagy vond vissza.';
        }

        self::munka_torol(false);
        $elozo = self::allapot();

        self::allapot_ment([
            'fazis'     => $tipus === 'wdg' ? 'feltoltve' : 'kibontasra',
            'forras'    => ['ut' => $ut, 'nev' => $nev, 'meret' => (int) @filesize($ut), 'tipus' => $tipus, 'sajat' => $sajat, 'ido' => current_time('mysql')], // phpcs:ignore WordPress.PHP.NoSilencedErrors
            'csomag'    => $tipus === 'csomag' ? $ut : '',
            'korabbi'   => (array) ($elozo['korabbi'] ?? []) + (isset($elozo['eredmeny']) ? [(string) ($elozo['befejezve'] ?? time()) => $elozo['eredmeny']] : []),
            'atvett_mar' => !empty($elozo['atvett_mar']) || isset($elozo['eredmeny']),
            'beall_elotte' => $elozo['beall_elotte'] ?? null,
            'uzenet'    => $tipus === 'wdg' ? 'MunkaLap 3 mentés feltöltve.' : 'Átalakított csomag feltöltve.',
        ]);

        return '';
    }

    /** A munkafájlok törlése (feltöltés, kibontott csomag). Az átvett fájlok (fajlok/) megmaradnak. */
    public static function munka_torol(bool $forras_is = true): void
    {
        $a = self::allapot();

        foreach (['munka', 'feltoltes'] as $al) {
            self::mappa_torol(self::mappa($al));
        }

        if ($forras_is && !empty($a['forras']['sajat']) && is_file((string) $a['forras']['ut'])) {
            @unlink((string) $a['forras']['ut']); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
    }

    private static function mappa_torol(string $mappa): void
    {
        if (!is_dir($mappa) || strpos(wp_normalize_path($mappa), wp_normalize_path(self::mappa())) !== 0) {
            return;
        }

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mappa, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
    }

    /* =================================================================
     * Lépések
     * ============================================================== */

    private static function van_ido(): bool
    {
        return microtime(true) - self::$indult < self::IDOKERET;
    }

    /**
     * Egy lépés a folyamatban (időkereten belül annyi, amennyi belefér). A böngésző addig hívja, amíg
     * a válasz `tovabb` igaz.
     *
     * @return array<string, mixed>
     */
    public static function lepes(): array
    {
        self::$indult = microtime(true);
        self::tabla_telepit();

        // Egyszerre egy lépés fut (két nyitott ablak ne dolgozza fel kétszer ugyanazt).
        $zar = 'sdh_muhely_ml3_zar';

        if (!add_option($zar, time(), '', false)) {
            if (time() - (int) get_option($zar, 0) < 90) {
                return ['uzenet' => 'Egy másik ablak (vagy az előző kérés) még dolgozik – várok…'] + self::osszegzes();
            }

            update_option($zar, time(), false);
        }

        try {
            return self::lepes_zarral();
        } finally {
            delete_option($zar);
        }
    }

    /** @return array<string, mixed> */
    private static function lepes_zarral(): array
    {
        $a = self::allapot();

        switch ((string) ($a['fazis'] ?? 'ures')) {
            case 'feltoltve':
                self::atalakito_indit($a);
                self::allapot_ment($a);
                break;

            case 'atalakitas':
                self::atalakitas_figyel($a);
                self::allapot_ment($a);
                break;

            case 'kibontasra':
            case 'kibontas':
                self::kibont($a);
                self::allapot_ment($a);
                break;

            case 'ellenorzes':
                self::ellenoriz($a);
                self::allapot_ment($a);
                break;

            case 'atvetel':
                self::atvesz($a);
                break;

            case 'zaras':
                self::zar($a);
                break;

            case 'visszavonas':
                self::visszavon_lepes($a);
                break;
        }

        return self::osszegzes();
    }

    private static function atalakitas_figyel(array &$a): void
    {
        $at  = (array) ($a['atalakitas'] ?? []);
        $all = is_file((string) ($at['allapot'] ?? '')) ? json_decode((string) file_get_contents((string) $at['allapot']), true) : null;

        if (is_array($all)) {
            $a['atalakitas']['fazis'] = (string) ($all['fazis'] ?? '');
            $a['atalakitas']['kesz']  = (int) ($all['kesz'] ?? 0);
            $a['atalakitas']['osszes'] = (int) ($all['osszes'] ?? 0);
            $a['atalakitas']['tabla'] = (string) ($all['tabla'] ?? '');

            if (($all['fazis'] ?? '') === 'kesz' && is_file((string) $at['kimenet'])) {
                $a['csomag'] = (string) $at['kimenet'];
                $a['fazis']  = 'kibontasra';
                $a['uzenet'] = 'Átalakítva: ' . number_format((int) $all['kesz'], 0, ',', ' ') . ' sor.';

                return;
            }

            if (($all['fazis'] ?? '') === 'hiba') {
                $a['fazis']  = 'atalakitora_var';
                $a['uzenet'] = 'Az átalakítás nem sikerült: ' . (string) ($all['hiba'] ?? '') . ' – töltsd le az átalakítót, és futtasd a MunkaLap gépén.';

                return;
            }
        }

        // Két percen belül életjel sincs: valószínűleg el sem indult.
        if (!is_array($all) && time() - (int) ($at['indult'] ?? time()) > 120) {
            $log = is_file((string) ($at['naplo'] ?? '')) ? mb_substr(trim((string) file_get_contents((string) $at['naplo'])), 0, 300) : '';
            $a['fazis']  = 'atalakitora_var';
            $a['uzenet'] = 'Az átalakító nem indult el a szerveren' . ($log !== '' ? ': ' . $log : '.') . ' Töltsd le, és futtasd a MunkaLap gépén.';
        }
    }

    /** A csomag kibontása a munkamappába, darabokban. */
    private static function kibont(array &$a): void
    {
        $z = new ZipArchive();

        if ($z->open((string) $a['csomag']) !== true) {
            $a['fazis']  = 'hiba';
            $a['uzenet'] = 'A csomag nem nyitható meg.';

            return;
        }

        $cel = self::mappa('munka/csomag');
        $i   = (int) ($a['kibontas']['i'] ?? 0);
        $n   = $z->numFiles;
        $a['fazis'] = 'kibontas';

        for (; $i < $n && self::van_ido(); $i++) {
            $nev = (string) $z->getNameIndex($i);

            if ($nev === '' || substr($nev, -1) === '/' || strpos($nev, '..') !== false || preg_match('#^(tables|files)/[A-Za-z0-9_.\-/]+$|^manifest\.json$#', $nev) !== 1) {
                continue;
            }

            if (!$z->extractTo($cel, $nev)) {
                self::hiba($a, 'Nem bontható ki: ' . $nev);
            }
        }

        $z->close();
        $a['kibontas'] = ['i' => $i, 'n' => $n];

        if ($i >= $n) {
            $a['fazis']  = 'ellenorzes';
            $a['uzenet'] = 'Kibontva, ellenőrzés…';
        }
    }

    public static function csomag_mappa(): string
    {
        return self::mappa('munka/csomag');
    }

    /** @return array<string, mixed>|null */
    public static function manifest(): ?array
    {
        $ut = self::csomag_mappa() . '/manifest.json';
        $m  = is_file($ut) ? json_decode((string) file_get_contents($ut), true) : null;

        return is_array($m) ? $m : null;
    }

    /** Ellenőrzés (SHA-1 táblánként) és a párosítási javaslat. */
    private static function ellenoriz(array &$a): void
    {
        $m = self::manifest();

        if ($m === null) {
            $a['fazis']  = 'hiba';
            $a['uzenet'] = 'A csomagban nincs manifest.json.';

            return;
        }

        $rossz = [];

        foreach ((array) $m['tablak'] as $t) {
            $ut = self::csomag_mappa() . '/tables/' . $t['nev'] . '.jsonl';

            if (!is_file($ut)) {
                $rossz[] = $t['nev'] . ' (hiányzik)';
            } elseif ((int) $t['sorok'] > 0 && !empty($t['sha1']) && sha1_file($ut) !== $t['sha1']) {
                $rossz[] = $t['nev'] . ' (sérült)';
            }
        }

        if ($rossz !== []) {
            $a['fazis']  = 'hiba';
            $a['uzenet'] = 'A csomag hibás: ' . implode(', ', $rossz) . '. Készíts új átalakítást.';

            return;
        }

        // Egyedi mezők (DATA_COLUMN) és felhasználók (USER_DATA) – a párosításhoz.
        $egyedi = [];

        foreach (self::jsonl_sorok('APP.DATA_COLUMN') as $d) {
            $tab = strtoupper((string) ($d['TABLE_NAME'] ?? ''));
            $egyedi[$tab][(string) $d['DATA_COLUMN_ID']] = [
                'nev' => (string) ($d['NAME'] ?? ''), 'tipus' => (int) ($d['TYPE'] ?? 4), 'aktiv' => (int) ($d['ACTIVE'] ?? 1),
                'torolt' => (int) ($d['SYSTEM_TRASH'] ?? 0), 'sorrend' => (int) ($d['COLUMN_ORDER'] ?? 0),
            ];
        }

        $felh = [];

        foreach (self::jsonl_sorok('USERS.USER_DATA') as $u) {
            $felh[(string) $u['USER_ID']] = (string) ($u['NAME'] ?? '');
        }

        // A ténylegesen használt egyedi oszlopok (a régi táblák oszloplistája szerint).
        foreach ((array) $m['tablak'] as $t) {
            $rov = substr((string) $t['nev'], 4);

            if (!in_array($rov, ['CUSTOMER', 'THING', 'WORKSHEET'], true)) {
                continue;
            }

            foreach ((array) $t['oszlopok'] as $o) {
                if (preg_match('/^DATA_(\d+)$/', (string) $o[0], $mm) === 1 && !isset($egyedi[$rov][$mm[1]])) {
                    $egyedi[$rov][$mm[1]] = ['nev' => 'Egyedi mező ' . $mm[1], 'tipus' => 4, 'aktiv' => 0, 'torolt' => 1, 'sorrend' => 999];
                }
            }
        }

        $a['egyedi']      = $egyedi;
        $a['felhasznalok'] = $felh;
        $a['manifest']    = [
            'keszult' => (string) ($m['keszult'] ?? ''), 'forras' => (array) ($m['forras'] ?? []), 'osszes_sor' => (int) ($m['osszes_sor'] ?? 0),
            'fajlok' => (int) ($m['fajlok'] ?? 0), 'fajl_bajt' => (int) ($m['fajl_bajt'] ?? 0),
            'tablak' => array_map(static fn (array $t): array => ['nev' => (string) $t['nev'], 'sorok' => (int) $t['sorok'], 'fajlok' => (int) $t['fajlok'], 'kulcs' => $t['kulcs']], (array) $m['tablak']),
        ];
        $a['javaslat']    = self::javaslat($egyedi, $felh);
        $a['fazis']       = 'kesz';
        $a['uzenet']      = 'A csomag ép. Nézd át a párosítást, aztán indítsd az átvételt.';
        $a['utkozes']     = self::utkozesek();
    }

    /** @return Generator<int, array<string, mixed>> */
    public static function jsonl_sorok(string $tabla): Generator
    {
        $ut = self::csomag_mappa() . '/tables/' . $tabla . '.jsonl';

        if (!is_file($ut)) {
            return;
        }

        $f = fopen($ut, 'rb');

        while ($f !== false && ($l = fgets($f)) !== false) {
            $d = json_decode($l, true);

            if (is_array($d)) {
                yield $d;
            }
        }

        if ($f !== false) {
            fclose($f);
        }
    }

    /** @return array<string, mixed> */
    private static function javaslat(array $egyedi, array $felh): array
    {
        $mezok = [];

        foreach (['CUSTOMER', 'THING', 'WORKSHEET'] as $t) {
            foreach ((array) ($egyedi[$t] ?? []) as $id => $e) {
                $mezok[$t][(string) $id] = SDH_Muhely_Ml3_Lekepzes::mezo_javaslat($t, (string) $e['nev']);
            }
        }

        $parok    = [];
        $felelosok = class_exists('SDH_Muhely_Munkalap') ? SDH_Muhely_Munkalap::felelosok() : [];

        foreach ($felh as $id => $nev) {
            $parok[(string) $id] = 0;

            foreach ($felelosok as $uid => $unev) {
                if (SDH_Muhely_Ml3_Lekepzes::norm((string) $unev) === SDH_Muhely_Ml3_Lekepzes::norm((string) $nev)) {
                    $parok[(string) $id] = (int) $uid;
                    break;
                }
            }
        }

        return ['mezok' => $mezok, 'felhasznalok' => $parok, 'arukiadas' => true, 'kiegeszit' => true];
    }

    /** Figyelmeztetések az átvétel előtt: foglalt munkalapszámok, meglévő adatok. @return array<string, int> */
    private static function utkozesek(): array
    {
        global $wpdb;

        $m = SDH_Muhely_Schema::tabla('munkalap');

        return [
            'munkalapok'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$m}"),
            'max_szam'     => (int) $wpdb->get_var("SELECT MAX(munkalap_szam) FROM {$m}"),
            'ugyfelek'     => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('ugyfel')),
            'nyers'        => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::nyers_tabla()),
        ];
    }

    /**
     * Az átvétel indítása a választott párosítással.
     *
     * @param array<string, mixed> $beall mezok, felhasznalok, arukiadas, kiegeszit
     */
    public static function indit(array $beall): string
    {
        $a = self::allapot();

        if (($a['fazis'] ?? '') !== 'kesz') {
            return 'Előbb töltsd fel és készítsd elő a mentést.';
        }

        $jav   = (array) ($a['javaslat'] ?? []);
        $mezok = [];

        foreach ((array) ($jav['mezok'] ?? []) as $t => $lista) {
            foreach ((array) $lista as $id => $cel) {
                $kert = (string) ($beall['mezok'][$t][$id] ?? $cel);
                $mezok[$t][(string) $id] = isset(SDH_Muhely_Ml3_Lekepzes::mezo_celok((string) $t)[$kert]) ? $kert : (string) $cel;
            }
        }

        $felh = [];

        foreach ((array) ($jav['felhasznalok'] ?? []) as $id => $uid) {
            $felh[(string) $id] = max(0, (int) ($beall['felhasznalok'][$id] ?? $uid));
        }

        $a['beallitas'] = [
            'mezok'        => $mezok,
            'felhasznalok' => $felh,
            'arukiadas'    => !empty($beall['arukiadas']),
            'kiegeszit'    => !empty($beall['kiegeszit']),
        ];

        // A sorrend: a megadott, aztán minden más tábla ábécérendben.
        $van      = array_column((array) $a['manifest']['tablak'], 'sorok', 'nev');
        $sorrend  = array_values(array_filter(self::SORREND, static fn (string $t): bool => isset($van[$t])));
        $tobbi    = array_diff(array_keys($van), $sorrend);
        sort($tobbi);

        $max_regi = 0;

        foreach (self::jsonl_sorok('APP.WORKSHEET') as $w) {
            $max_regi = max($max_regi, (int) ($w['WORKSHEET_ID'] ?? 0));
        }

        $a['atvetel'] = [
            'tablak' => array_merge($sorrend, $tobbi), 'i' => 0, 'offset' => 0, 'kesz_sorok' => 0,
            'osszes' => array_sum(array_map('intval', $van)), 'stat' => [], 'indult' => time(), 'max_regi_szam' => $max_regi,
        ];
        $a['fazis']  = 'atvetel';
        $a['hibak']  = [];
        $a['hibak_db'] = 0;
        $a['uzenet'] = 'Átvétel folyamatban…';

        if (!empty($a['beallitas']['kiegeszit'])) {
            self::beallitas_kiegeszit($a);
        }

        self::$uj_tar    = [];
        self::$nyers_tar = [];
        self::allapot_ment($a);

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('atvetel', 0, 'Munkalapszoftver Clone: átvétel indult (' . (string) ($a['forras']['nev'] ?? '') . ', ' . number_format((int) $a['atvetel']['osszes'], 0, ',', ' ') . ' sor).');
        }

        return '';
    }

    /** A hiányzó fizetési módok és a „Nem javítandó” hibaállapot felvétele (a visszavonás visszaállítja). */
    private static function beallitas_kiegeszit(array &$a): void
    {
        $fiz_opt  = 'sdh_muhely_fizetesi_modok';
        $hiba_opt = 'sdh_muhely_munkalap_hiba_allapotok';

        if (!isset($a['beall_elotte'])) {
            $a['beall_elotte'] = [$fiz_opt => get_option($fiz_opt, null), $hiba_opt => get_option($hiba_opt, null)];
        }

        // Fizetési módok, amik a régi lapokon előfordulnak.
        $hasznalt = [];

        foreach (self::jsonl_sorok('APP.WORKSHEET') as $w) {
            if (isset($w['PAYMENT_TYPE']) && $w['PAYMENT_TYPE'] !== null) {
                $hasznalt[(int) $w['PAYMENT_TYPE']] = true;
            }
        }

        $lista = SDH_Muhely_Munkalap::fizetesi_modok();
        $uj    = $lista;

        foreach (array_keys($hasznalt) as $kod) {
            $nev = SDH_Muhely_Ml3_Lekepzes::fizmod($kod);

            if ($nev !== '' && !in_array($nev, $uj, true)) {
                $uj[] = $nev;
            }
        }

        if ($uj !== $lista) {
            update_option($fiz_opt, implode("\n", $uj));
        }

        $hl = SDH_Muhely_Munkalap::hiba_allapotok();

        if (!isset($hl['nem_javitando'])) {
            $mentett = (string) get_option($hiba_opt, '');
            $alap    = trim($mentett) !== '' ? $mentett : SDH_Muhely_Munkalap::alap_hiba_allapotok_szovegkent();
            update_option($hiba_opt, rtrim($alap) . "\nnem_javitando | Nem javítandó | szurke | zart");
        }

        self::munkalap_gyorsitotar_urit();
    }

    /** A munkalap-állapotlisták gyorsítótára (a kiegészített beállítás azonnal érvényes legyen). */
    private static function munkalap_gyorsitotar_urit(): void
    {
        if (class_exists('SDH_Muhely_Munkalap')) {
            Closure::bind(static function (): void {
                self::$gyorsitotar = []; // @phpstan-ignore-line
            }, null, SDH_Muhely_Munkalap::class)();
        }
    }

    /* =================================================================
     * Átvétel
     * ============================================================== */

    private static function atvesz(array $a): void
    {
        global $wpdb;

        $at = &$a['atvetel'];
        $wpdb->query('START TRANSACTION');
        self::$torlendo = [];

        $muvelet = static function () use (&$a, &$at): void {
            while (self::van_ido() && $at['i'] < count($at['tablak'])) {
                $tabla = (string) $at['tablak'][$at['i']];
                $ut    = self::csomag_mappa() . '/tables/' . $tabla . '.jsonl';
                $f     = is_file($ut) ? fopen($ut, 'rb') : false;

                if ($f === false) {
                    self::hiba($a, $tabla . ': a táblafájl hiányzik.');
                    $at['i']++;
                    $at['offset'] = 0;
                    continue;
                }

                fseek($f, (int) $at['offset']);
                $db = 0;

                while (($db++ % 25 !== 0 || self::van_ido()) && ($l = fgets($f)) !== false) {
                    $at['offset'] = ftell($f);
                    $at['kesz_sorok']++;

                    if (trim($l) === '') {
                        continue;
                    }

                    try {
                        self::sor($a, $tabla, rtrim($l, "\r\n"));
                    } catch (Throwable $e) {
                        self::stat($a, $tabla, 'hibas');
                        self::hiba($a, $tabla . ': ' . $e->getMessage());
                    }
                }

                $vege = feof($f) || fgets($f) === false;
                fclose($f);

                if ($vege) {
                    $at['i']++;
                    $at['offset'] = 0;
                }
            }
        };

        class_exists('SDH_Muhely_Valtozasnaplo') ? SDH_Muhely_Valtozasnaplo::csendben($muvelet) : $muvelet();

        if ($at['i'] >= count($at['tablak'])) {
            $a['fazis']  = 'zaras';
            $a['zaras']  = ['lepes' => 'keszlet', 'utolso' => 0];
            $a['uzenet'] = 'Zárás: készlet újraszámolása, ellenőrzés…';
        }

        unset($at);
        self::kovet_ir();
        self::allapot_ment($a);
        $wpdb->query('COMMIT');

        foreach (self::$torlendo as $ut) {
            @unlink($ut); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
    }

    private static function stat(array &$a, string $tabla, string $mi, int $n = 1): void
    {
        $a['atvetel']['stat'][$tabla][$mi] = (int) ($a['atvetel']['stat'][$tabla][$mi] ?? 0) + $n;
    }

    /** A tábla elsődleges kulcsa a sorban. */
    private static function kulcs(array $a, string $tabla, array $sor): string
    {
        static $pk = [];

        if (!isset($pk[$tabla])) {
            $pk[$tabla] = '';

            foreach ((array) $a['manifest']['tablak'] as $t) {
                if ($t['nev'] === $tabla && !empty($t['kulcs'])) {
                    $pk[$tabla] = (string) $t['kulcs'];
                }
            }
        }

        return $pk[$tabla] !== '' ? (string) ($sor[$pk[$tabla]] ?? '') : md5((string) wp_json_encode($sor));
    }

    /** A szülő (a böngészéshez és a gyerekek kereséséhez). */
    private static function szulo(string $tabla, array $sor): string
    {
        switch ($tabla) {
            case 'APP.ADDRESS':
            case 'APP.THING':
            case 'APP.WORKSHEET':
                return (string) ($sor['CUSTOMER_ID'] ?? '');
            case 'APP.ITEM':
            case 'APP.PROBLEM':
                return (string) ($sor['CONNECTION_KEY'] ?? '') !== '' ? $sor['CONNECTION_KEY'] . ':' . ($sor['CONNECTION_ID'] ?? '') : (string) ($sor['MASTER_ITEM_ID'] ?? '');
            case 'APP.CUSTOMER_ATTACHEMENT':
            case 'APP.THING_ATTACHEMENT':
            case 'APP.WORKSHEET_ATTACHEMENT':
            case 'APP.EMAIL_ATTACHEMENT':
            case 'APP.INVOICE_ATTACHEMENT':
                return (string) ($sor['CONNECTION_ID'] ?? '');
            case 'APP.EMAIL':
                return 'WORKSHEET:' . SDH_Muhely_Ml3_Lekepzes::email_munkalap($sor);
            case 'APP.INVOICE_ITEM':
            case 'APP.INVOICE_BLOCK':
            case 'APP.INVOICE_ARCHIVE':
                return (string) ($sor['INVOICE_ID'] ?? '');
            case 'APP.DATA_ELEMENT':
            case 'APP.DATA_PRIVILEGE':
                return (string) ($sor['DATA_COLUMN_ID'] ?? '');
            case 'APP.AI_MESSAGE':
            case 'APP.CHAT_MEMBER':
                return (string) ($sor['CHAT_ID'] ?? '');
            case 'USERS.USER_PRIVILEGES':
                return (string) ($sor['USER_ID'] ?? '');
            case 'APP.USER_GROUP_PRIVILEGE':
                return (string) ($sor['USER_GROUP_ID'] ?? '');
        }

        return '';
    }

    /** Egy régi sor feldolgozása: leképezés + nyers tár (egy írással). */
    private static function sor(array &$a, string $tabla, string $sor_json): void
    {
        global $wpdb;

        $sor = json_decode($sor_json, true);

        if (!is_array($sor)) {
            self::stat($a, $tabla, 'hibas');
            self::hiba($a, $tabla . ': olvashatatlan sor.');

            return;
        }

        $kulcs = mb_substr(self::kulcs($a, $tabla, $sor), 0, 64);
        $hash  = md5($sor_json);
        $nt    = self::nyers_tabla();

        // A táblában már bent lévő sorok lenyomata (kérésenként egyszer) – az első átvételnél nincs külön lekérdezés.
        if (!isset(self::$hash_tar[$tabla])) {
            self::$hash_tar = [$tabla => []];

            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT kulcs, hash FROM {$nt} WHERE tabla = %s", $tabla)) as $h) {
                self::$hash_tar[$tabla][(string) $h->kulcs] = (string) $h->hash;
            }
        }

        $volt = self::$hash_tar[$tabla][$kulcs] ?? null;

        if ($volt === $hash) {
            self::stat($a, $tabla, 'valtozatlan');

            return;
        }

        $meg   = $volt !== null ? $wpdb->get_row($wpdb->prepare("SELECT id, uj_tabla, uj_id, letrehozta, kapcsolt, adat FROM {$nt} WHERE tabla = %s AND kulcs = %s", $tabla, $kulcs)) : null;
        $elozo = $meg !== null ? json_decode((string) $meg->adat, true) : null;

        // A fájlok: a leképezés a rendszerbe teszi őket, ami nem kell sehova, az a modul tárába kerül.
        $fajlok = [];

        foreach ($sor as $o => $v) {
            if (is_array($v) && isset($v['$fajl'])) {
                $fajlok[$o] = $v;
            }
        }

        $kovetes = $meg !== null
            ? ['uj_tabla' => (string) $meg->uj_tabla, 'uj_id' => (int) $meg->uj_id, 'letrehozta' => (int) $meg->letrehozta, 'kapcsolt' => (string) $meg->kapcsolt]
            : ['uj_tabla' => '', 'uj_id' => 0, 'letrehozta' => 0, 'kapcsolt' => ''];

        try {
            $ered = self::lekepez($a, $tabla, $sor, $elozo, $kovetes, $fajlok);
        } catch (Throwable $e) {
            // A leképezés hibája nem viszi el a régi sort: az a nyers tárba így is bekerül, okkal.
            self::hiba($a, $tabla . ' #' . $kulcs . ': ' . $e->getMessage());
            $ered = ['uj_tabla' => $kovetes['uj_tabla'], 'uj_id' => $kovetes['uj_id'], 'letrehozta' => $kovetes['letrehozta'], 'kapcsolt' => $kovetes['kapcsolt'],
                'megj' => 'Hiba az átvételkor: ' . $e->getMessage(), 'eredmeny' => 'hibas', 'fajl_kesz' => []];
        }

        foreach ($fajlok as $o => $f) {
            $sor[$o]['tar'] = !empty($ered['fajl_kesz'][$o]) ? (string) $ered['fajl_kesz'][$o] : self::fajl_megoriz((string) $f['$fajl']);
        }

        $nyers = [
            'tabla' => $tabla, 'kulcs' => $kulcs, 'szulo' => mb_substr(self::szulo($tabla, $sor), 0, 64),
            'adat' => (string) wp_json_encode($sor, JSON_UNESCAPED_UNICODE), 'hash' => $hash, 'atveve' => current_time('mysql'),
            'uj_tabla' => (string) $ered['uj_tabla'], 'uj_id' => (int) $ered['uj_id'], 'letrehozta' => (int) $ered['letrehozta'],
            'kapcsolt' => mb_substr((string) $ered['kapcsolt'], 0, 255), 'megj' => mb_substr((string) $ered['megj'], 0, 255),
        ];

        if ($meg === null) {
            if ($wpdb->insert($nt, $nyers) === false) {
                self::hiba($a, $tabla . ' #' . $kulcs . ': a régi sor nem írható a tárba – ' . $wpdb->last_error);
                self::stat($a, $tabla, 'hibas');

                return;
            }
        } else {
            $wpdb->update($nt, $nyers, ['id' => (int) $meg->id]);
        }

        self::$hash_tar[$tabla][$kulcs] = $hash;

        if (isset(self::$nyers_tar[$tabla])) {
            self::$nyers_tar[$tabla][$kulcs] = $sor;
        }

        if ((int) $ered['uj_id'] > 0) {
            self::$uj_tar[$tabla][$kulcs] = (int) $ered['uj_id'];
        }

        self::stat($a, $tabla, 'sorok');
        self::stat($a, $tabla, (string) $ered['eredmeny']);
    }

    /** Egy fájl áthelyezése a csomagból a modul végleges tárába; a relatív útja. */
    private static function fajl_megoriz(string $rel): string
    {
        $rel = ltrim(str_replace(['..', '\\'], ['', '/'], $rel), '/');
        $ut  = self::csomag_mappa() . '/' . $rel;
        $cel = self::mappa('fajlok') . '/' . $rel;

        if (!is_file($ut)) {
            return is_file($cel) ? 'fajlok/' . $rel : '';
        }

        wp_mkdir_p(dirname($cel));

        if (@copy($ut, $cel)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            self::$torlendo[] = $ut;

            return 'fajlok/' . $rel;
        }

        return '';
    }

    /** Csatolmány a rendszerbe a csomag fájljából; az új csatolmány azonosítója (0, ha nem sikerült). */
    private static function csatol(string $tipus, int $ref_id, string $rel, string $nev, string $ido, int $feltolto): int
    {
        global $wpdb;

        $ut = self::csomag_mappa() . '/' . ltrim(str_replace('..', '', $rel), '/');

        if ($ref_id <= 0 || !is_file($ut)) {
            return 0;
        }

        $tarolt = bin2hex(random_bytes(16));
        $mappa  = trailingslashit(wp_upload_dir()['basedir']) . 'sdh-muhely/csatolmany';
        wp_mkdir_p($mappa);

        if (!file_exists($mappa . '/.htaccess')) {
            file_put_contents($mappa . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }

        if (!@copy($ut, $mappa . '/' . $tarolt)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return 0;
        }

        $tipusa = wp_check_filetype($nev);
        $wpdb->insert(SDH_Muhely_Schema::tabla('csatolmany'), [
            'tipus'       => $tipus,
            'ref_id'      => $ref_id,
            'eredeti_nev' => mb_substr(sanitize_file_name($nev), 0, 190),
            'tarolt_nev'  => $tarolt,
            'mime'        => (string) ($tipusa['type'] ?: 'application/octet-stream'),
            'meret'       => (int) filesize($ut),
            'feltoltve'   => $ido !== '' ? $ido : current_time('mysql'),
            'feltolto'    => $feltolto,
        ]);

        $id = (int) $wpdb->insert_id;

        if ($id <= 0) {
            @unlink($mappa . '/' . $tarolt); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return 0;
        }

        self::$torlendo[] = $ut;
        self::kovet('csatolmany', $id);

        return $id;
    }

    /**
     * Beszúrás vagy (újabb mentésnél) frissítés a rendszer táblájában.
     *
     * @param array<string, mixed> $adat
     * @param array<string, mixed> $kovetes
     * @return array{0: int, 1: string} az új azonosító és az eredmény (uj / frissitett / meglevo)
     */
    private static function ir(string $kov_kulcs, array $adat, array $kovetes, string $kulso = ''): array
    {
        global $wpdb;

        $tabla = SDH_Muhely_Schema::tabla(self::KOVETETT[$kov_kulcs]);

        if ((int) $kovetes['uj_id'] > 0 && (string) $kovetes['uj_tabla'] === $kov_kulcs) {
            if ((int) $kovetes['letrehozta'] === 1) {
                unset($adat['letrehozo']);
                $wpdb->update($tabla, $adat, ['id' => (int) $kovetes['uj_id']]);

                return [(int) $kovetes['uj_id'], 'frissitett'];
            }

            return [(int) $kovetes['uj_id'], 'meglevo'];
        }

        // A táblázatos adatátvétellel már bent lévő rekord: összekötjük, nem duplázzuk.
        if ($kulso !== '' && in_array($kov_kulcs, ['ugyfel', 'eszkoz', 'munkalap', 'termek'], true)) {
            $van = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tabla} WHERE forras = %s AND kulso_azonosito = %s LIMIT 1", self::FORRAS, $kulso));

            if ($van > 0) {
                return [-$van, 'meglevo'];
            }
        }

        if ($wpdb->insert($tabla, $adat) === false) {
            throw new RuntimeException(self::KOVETETT[$kov_kulcs] . ' beszúrása nem sikerült: ' . $wpdb->last_error);
        }

        $id = (int) $wpdb->insert_id;
        self::kovet($kov_kulcs, $id);

        return [$id, 'uj'];
    }

    /**
     * A leképezés: mi lesz a régi sorból.
     *
     * @return array{uj_tabla: string, uj_id: int, letrehozta: int, kapcsolt: string, megj: string, eredmeny: string, fajl_kesz: array<string, string>}
     */
    private static function lekepez(array &$a, string $tabla, array $sor, ?array $elozo, array $kov, array $fajlok): array
    {
        global $wpdb;

        $L    = 'SDH_Muhely_Ml3_Lekepzes';
        $ki   = ['uj_tabla' => '', 'uj_id' => 0, 'letrehozta' => 0, 'kapcsolt' => '', 'megj' => '', 'eredmeny' => 'nyers', 'fajl_kesz' => []];
        $b    = self::beallitas();
        $kesz = static function (string $kov_kulcs, array $ir) use (&$ki): void {
            [$id, $er]          = $ir;
            $ki['uj_tabla']     = $kov_kulcs;
            $ki['uj_id']        = abs($id);
            $ki['letrehozta']   = $er === 'uj' || ($er === 'frissitett') ? 1 : 0;
            $ki['eredmeny']     = $er;
        };

        switch ($tabla) {
            case 'APP.CUSTOMER':
                $adat = $L::ugyfel($sor) + ['forras' => self::FORRAS, 'kulso_azonosito' => (string) $sor['CUSTOMER_ID'], 'modositva' => current_time('mysql')];
                $kesz('ugyfel', self::ir('ugyfel', $adat, $kov, (string) $sor['CUSTOMER_ID']));

                if ($ki['eredmeny'] === 'uj') {
                    $wpdb->update(SDH_Muhely_Schema::tabla('ugyfel'), ['ugyfel_szam' => sprintf('U-%06d', $ki['uj_id'])], ['id' => $ki['uj_id']]);
                }

                if (in_array($ki['eredmeny'], ['uj', 'frissitett'], true) && class_exists('SDH_Muhely_Ugyfel')) {
                    SDH_Muhely_Ugyfel::kereso_frissit($ki['uj_id']);
                }

                return $ki;

            case 'APP.THING':
                if (!empty($b['arukiadas']) && $L::vasarlas_e($sor)) {
                    $ki['megj'] = 'Vásárlás (javítás nélküli eladás) – a lapja napi árukiadás, eszköz nélkül.';

                    return $ki;
                }

                $adat = $L::eszkoz($sor) + [
                    'ugyfel_id' => self::uj_id('APP.CUSTOMER', $sor['CUSTOMER_ID'] ?? ''), 'forras' => self::FORRAS,
                    'kulso_azonosito' => (string) $sor['THING_ID'], 'modositva' => current_time('mysql'),
                ];

                if ($ki['uj_id'] === 0 && (int) $kov['uj_id'] > 0) {
                    // Frissítésnél a mostanra kitöltött tartozékokat nem töröljük.
                    if ((string) $adat['tartozekok'] === '') {
                        unset($adat['tartozekok']);
                    }
                }

                $kesz('eszkoz', self::ir('eszkoz', $adat, $kov, (string) $sor['THING_ID']));

                return $ki;

            case 'APP.MASTER_ITEM':
                if ((int) ($sor['TYPE'] ?? 0) === 1) {
                    return self::szolgaltatas($sor, $kov, $ki);
                }

                $adat = $L::termek($sor) + ['forras' => self::FORRAS, 'kulso_azonosito' => (string) $sor['MASTER_ITEM_ID'], 'modositva' => current_time('mysql')];
                unset($adat['keszlet']);
                $adat = ['keszlet' => 0] + $adat;

                if ((int) $kov['uj_id'] > 0) {
                    unset($adat['keszlet']); // a készletet a mozgások adják
                }

                $kesz('termek', self::ir('termek', $adat, $kov, (string) $sor['MASTER_ITEM_ID']));

                return $ki;

            case 'APP.WORKSHEET':
                return self::munkalap($a, $sor, $elozo, $kov, $ki);

            case 'APP.PROBLEM':
                if ((string) ($sor['CONNECTION_KEY'] ?? '') !== 'WORKSHEET' || (int) ($sor['TYPE'] ?? 0) === 0) {
                    $ki['megj'] = (int) ($sor['TYPE'] ?? 0) === 0 ? 'Hibakatalógus (lista) eleme.' : 'Nem munkalaphoz tartozik.';

                    return $ki;
                }

                $ml = self::uj_id('APP.WORKSHEET', $sor['CONNECTION_ID'] ?? '');

                if ($ml === 0) {
                    $ki['megj'] = 'A munkalapja nincs meg.';

                    return $ki;
                }

                $kesz('hiba', self::ir('hiba', $L::hiba($sor, $ml), $kov));

                return $ki;

            case 'APP.ITEM':
                return self::tetel($a, $sor, $kov, $ki);

            case 'APP.CUSTOMER_ATTACHEMENT':
            case 'APP.THING_ATTACHEMENT':
            case 'APP.WORKSHEET_ATTACHEMENT':
                $mihez = ['APP.CUSTOMER_ATTACHEMENT' => ['APP.CUSTOMER', 'ugyfel'], 'APP.THING_ATTACHEMENT' => ['APP.THING', 'eszkoz'], 'APP.WORKSHEET_ATTACHEMENT' => ['APP.WORKSHEET', 'munkalap']][$tabla];
                $ref   = self::uj_id($mihez[0], $sor['CONNECTION_ID'] ?? '');

                if ($tabla === 'APP.THING_ATTACHEMENT' && $ref === 0) {
                    // A „Vásárlás” tárgy képe: a hozzá tartozó (árukiadás) lapra.
                    $ml_kulcs = (string) $wpdb->get_var($wpdb->prepare('SELECT kulcs FROM ' . self::nyers_tabla() . " WHERE tabla = 'APP.WORKSHEET' AND adat LIKE %s ORDER BY id DESC LIMIT 1", '%"THING_ID":' . (int) ($sor['CONNECTION_ID'] ?? 0) . ',%'));
                    $ref      = self::uj_id('APP.WORKSHEET', $ml_kulcs);
                    $mihez[1] = $ref > 0 ? 'munkalap' : $mihez[1];
                }

                return self::csatolmany_sor($sor, $kov, $ki, $fajlok, $mihez[1], $ref);

            case 'APP.EMAIL':
                $ml = self::uj_id('APP.WORKSHEET', $L::email_munkalap($sor));

                if ($ml === 0) {
                    $ki['megj'] = 'Nem munkalaphoz tartozó e-mail.';

                    return $ki;
                }

                if ((int) $kov['uj_id'] > 0) {
                    $ki = ['uj_tabla' => 'csatolmany', 'uj_id' => (int) $kov['uj_id'], 'letrehozta' => (int) $kov['letrehozta'], 'kapcsolt' => (string) $kov['kapcsolt'], 'megj' => '', 'eredmeny' => 'meglevo', 'fajl_kesz' => []];

                    return $ki;
                }

                $ido  = (string) ($L::ido($sor['TRANSFER_DATE'] ?? '') ?? $L::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? current_time('mysql'));
                $rel  = 'files/EMAIL/' . (int) $sor['EMAIL_ID'] . '.html';
                $ut   = self::csomag_mappa() . '/' . $rel;
                wp_mkdir_p(dirname($ut));
                file_put_contents($ut, $L::email_html($sor));
                $nev  = 'E-mail ' . substr($ido, 0, 10) . ' - ' . mb_substr($L::s($sor['SUBJECT'] ?? 'e-mail'), 0, 80) . '.html';
                $cs   = self::csatol('munkalap_fajl', $ml, $rel, $nev, $ido, self::felh($sor['SYSTEM_CREATOR'] ?? null));

                if ($cs > 0) {
                    $ki = ['uj_tabla' => 'csatolmany', 'uj_id' => $cs, 'letrehozta' => 1, 'kapcsolt' => 'munkalap:' . $ml, 'megj' => '', 'eredmeny' => 'uj', 'fajl_kesz' => []];
                }

                return $ki;

            case 'APP.EMAIL_ATTACHEMENT':
                $email = self::nyers('APP.EMAIL', (string) ($sor['CONNECTION_ID'] ?? ''));
                $ml    = $email !== null ? self::uj_id('APP.WORKSHEET', $L::email_munkalap($email)) : 0;

                return self::csatolmany_sor($sor, $kov, $ki, $fajlok, 'munkalap_fajl', $ml, 'E-mail melléklet - ');
        }

        return $ki;
    }

    private static function felh($regi): int
    {
        $p = (array) (self::beallitas()['felhasznalok'] ?? []);

        return $regi === null || $regi === '' ? 0 : (int) ($p[(string) $regi] ?? 0);
    }

    private static function csatolmany_sor(array $sor, array $kov, array $ki, array $fajlok, string $tipus, int $ref, string $elotag = ''): array
    {
        if ((int) $kov['uj_id'] > 0) {
            // Már átvett csatolmány: a fájl nem változik (a régi programban sem írható át).
            $ki = ['uj_tabla' => 'csatolmany', 'uj_id' => (int) $kov['uj_id'], 'letrehozta' => (int) $kov['letrehozta'], 'kapcsolt' => (string) $kov['kapcsolt'], 'megj' => '', 'eredmeny' => 'meglevo', 'fajl_kesz' => []];

            if (isset($fajlok['CONTENT'])) {
                $ki['fajl_kesz']['CONTENT'] = 'csatolmany:' . (int) $kov['uj_id'];
                self::$torlendo[] = self::csomag_mappa() . '/' . ltrim((string) $fajlok['CONTENT']['$fajl'], '/');
            }

            return $ki;
        }

        if (!isset($fajlok['CONTENT']) || $ref <= 0) {
            $ki['megj'] = $ref <= 0 ? 'A gazdarekordja nincs meg – a fájl a régi adatok közt maradt.' : 'Üres tartalom.';

            return $ki;
        }

        $ext = SDH_Muhely_Ml3_Lekepzes::s($sor['EXTENSION'] ?? '');
        $nev = $elotag . SDH_Muhely_Ml3_Lekepzes::s($sor['NAME'] ?? 'fajl') . ($ext !== '' ? '.' . strtolower($ext) : '');
        $ido = (string) (SDH_Muhely_Ml3_Lekepzes::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? current_time('mysql'));
        $id  = self::csatol($tipus, $ref, (string) $fajlok['CONTENT']['$fajl'], $nev, $ido, self::felh($sor['SYSTEM_CREATOR'] ?? null));

        if ($id <= 0) {
            $ki['megj'] = 'A fájl nem másolható.';

            return $ki;
        }

        return ['uj_tabla' => 'csatolmany', 'uj_id' => $id, 'letrehozta' => 1, 'kapcsolt' => $tipus . ':' . $ref, 'megj' => '', 'eredmeny' => 'uj', 'fajl_kesz' => ['CONTENT' => 'csatolmany:' . $id]];
    }

    private static function szolgaltatas(array $sor, array $kov, array $ki): array
    {
        global $wpdb;

        $adat  = SDH_Muhely_Ml3_Lekepzes::szolgaltatas($sor);
        $kulcs = class_exists('SDH_Muhely_Szolgaltatas') ? SDH_Muhely_Szolgaltatas::kulcs((string) $adat['nev']) : md5(mb_strtolower((string) $adat['nev']));
        $tabla = SDH_Muhely_Schema::tabla('szolgaltatas');

        if ($kulcs === '') {
            $ki['megj'] = 'Névtelen szolgáltatás.';

            return $ki;
        }

        $van = $wpdb->get_row($wpdb->prepare("SELECT id, modositva FROM {$tabla} WHERE kulcs = %s", $kulcs));

        if ($van !== null) {
            // Egyforma nevű szolgáltatásból egy marad: az újabb régi rekord ára frissíti az általunk felvettet.
            $sajat = in_array(['szolg', (int) $van->id], self::$kovet_buf, true)
                || (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('atvetel_sor') . " WHERE import_id = %s AND tabla = 'szolg' AND sor_id = %d", self::IMPORT_ID, (int) $van->id)) > 0;

            if ($sajat && (string) $adat['letrehozva'] >= (string) ($van->modositva ?? '')) {
                $wpdb->update($tabla, ['brutto_ar' => $adat['brutto_ar'], 'afa_kulcs' => $adat['afa_kulcs'], 'me' => $adat['me'], 'modositva' => $adat['letrehozva']], ['id' => (int) $van->id]);
            }

            return ['uj_tabla' => 'szolg', 'uj_id' => (int) $van->id, 'letrehozta' => 0, 'kapcsolt' => '', 'megj' => 'Egyforma nevű szolgáltatás – összevonva.', 'eredmeny' => 'osszevont', 'fajl_kesz' => []];
        }

        $adat['kulcs']     = $kulcs;
        $adat['modositva'] = $adat['letrehozva'];

        if ($wpdb->insert($tabla, $adat) === false) {
            throw new RuntimeException('szolgáltatás beszúrása nem sikerült: ' . $wpdb->last_error);
        }

        $id = (int) $wpdb->insert_id;
        self::kovet('szolg', $id);

        return ['uj_tabla' => 'szolg', 'uj_id' => $id, 'letrehozta' => 1, 'kapcsolt' => '', 'megj' => '', 'eredmeny' => 'uj', 'fajl_kesz' => []];
    }

    private static function munkalap(array &$a, array $sor, ?array $elozo, array $kov, array $ki): array
    {
        global $wpdb;

        $L      = 'SDH_Muhely_Ml3_Lekepzes';
        $b      = self::beallitas();
        $aruk   = !empty($b['arukiadas']) && self::vasarlas_targy((string) ($sor['THING_ID'] ?? ''));
        $adat   = $L::munkalap($sor, null, $aruk);
        $tabla  = SDH_Muhely_Schema::tabla('munkalap');
        $regi_szam = (int) $sor['WORKSHEET_ID'];

        $adat['ugyfel_id']       = self::uj_id('APP.CUSTOMER', $sor['CUSTOMER_ID'] ?? '');
        $adat['eszkoz_id']       = $aruk ? 0 : self::uj_id('APP.THING', $sor['THING_ID'] ?? '');
        $adat['forras']          = self::FORRAS;
        $adat['kulso_azonosito'] = (string) $regi_szam;

        if ($adat['ugyfel_id'] === 0 && $adat['eszkoz_id'] > 0) {
            $adat['ugyfel_id'] = (int) $wpdb->get_var($wpdb->prepare('SELECT ugyfel_id FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' WHERE id = %d', $adat['eszkoz_id']));
        }

        $uj = (int) $kov['uj_id'] <= 0;

        if ($uj) {
            if (self::$szam_tar === null) {
                self::$szam_tar = array_fill_keys(array_map('intval', (array) $wpdb->get_col("SELECT munkalap_szam FROM {$tabla} WHERE munkalap_szam IS NOT NULL")), true);
            }

            $foglalt = isset(self::$szam_tar[$regi_szam]);

            if ($foglalt) {
                $adat['munkalap_szam'] = max(SDH_Muhely_Munkalap::kovetkezo_szam(), (int) ($a['atvetel']['max_regi_szam'] ?? 0) + 1);
                $adat['megjegyzes']    = trim('MunkaLap 3 munkalapszám: ' . $regi_szam . " (a szám itt már foglalt volt)\n\n" . $adat['megjegyzes']);
                self::stat($a, 'APP.WORKSHEET', 'uj_szammal');
            } else {
                $adat['munkalap_szam'] = $regi_szam;
            }
        }

        $ir = self::ir('munkalap', $adat, $kov, (string) $regi_szam);
        [$id, $er] = $ir;
        $id = abs($id);

        if (isset($adat['munkalap_szam']) && self::$szam_tar !== null) {
            self::$szam_tar[(int) $adat['munkalap_szam']] = true;
        }

        $ki['uj_tabla']   = 'munkalap';
        $ki['uj_id']      = $id;
        $ki['letrehozta'] = in_array($er, ['uj', 'frissitett'], true) ? 1 : 0;
        $ki['eredmeny']   = $er;

        if ($er === 'meglevo') {
            return $ki;
        }

        // Az állapottörténet: az újabb mentésnél csak az új állapotváltások.
        $volt = [];

        if ($elozo !== null) {
            foreach ($L::allapot_tortenet($elozo) as $t) {
                $volt[$t['ido'] . '|' . $t['kod']] = true;
            }
        }

        $elozo_all = '';
        $naplo     = SDH_Muhely_Schema::tabla('munkalap_naplo');

        foreach ($L::allapot_tortenet($sor) as $t) {
            if (!isset($volt[$t['ido'] . '|' . $t['kod']])) {
                $wpdb->insert($naplo, ['munkalap_id' => $id, 'allapot' => $t['allapot'], 'elozo' => $elozo_all, 'eszkoz_id' => 0, 'felhasznalo' => $t['ki'], 'letrehozva' => $t['ido']]);
                self::kovet('naplo', (int) $wpdb->insert_id);
            }

            $elozo_all = $t['allapot'];
        }

        // Az átvett tartozékok az eszközre, ha ott még nincs (a nyitott lapokhoz: átvételi elismervény).
        $tart = $L::tartozekok($sor);

        if ($tart !== '' && (int) $adat['eszkoz_id'] > 0) {
            $wpdb->query($wpdb->prepare('UPDATE ' . SDH_Muhely_Schema::tabla('eszkoz') . " SET tartozekok = %s WHERE id = %d AND (tartozekok IS NULL OR tartozekok = '')", $tart, (int) $adat['eszkoz_id']));
        }

        return $ki;
    }

    /** A „Vásárlás” tárgyak régi azonosítói (kérésenként egyszer). */
    private static function vasarlas_targy(string $thing_id): bool
    {
        global $wpdb;

        static $tar = null;
        static $ido = 0.0;

        if ($tar === null || $ido !== self::$indult) {
            $ido = self::$indult;
            $tar = array_fill_keys(array_map('strval', (array) $wpdb->get_col('SELECT kulcs FROM ' . self::nyers_tabla() . " WHERE tabla = 'APP.THING' AND uj_id = 0 AND megj LIKE 'Vásárlás%'")), true);
        }

        return isset($tar[$thing_id]);
    }

    /**
     * A régi termék-/szolgáltatástörzs: régi azonosító => [termék-e, készletkezelt-e] (kérésenként egyszer).
     *
     * @return array{0: bool, 1: bool}|null
     */
    private static function master(string $id): ?array
    {
        global $wpdb;

        static $tar = null;
        static $ido = 0.0;

        if ($tar === null || $ido !== self::$indult) {
            $ido = self::$indult;
            $tar = [];
            $kk  = array_fill_keys(array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . SDH_Muhely_Schema::tabla('termek') . ' WHERE forras = %s AND keszletkezeles = 1', self::FORRAS))), true);

            foreach ((array) $wpdb->get_results('SELECT kulcs, uj_tabla, uj_id FROM ' . self::nyers_tabla() . " WHERE tabla = 'APP.MASTER_ITEM'") as $m) {
                $tar[(string) $m->kulcs] = [(string) $m->uj_tabla === 'termek', isset($kk[(int) $m->uj_id])];
            }
        }

        return $tar[$id] ?? null;
    }

    private static function tetel(array &$a, array $sor, array $kov, array $ki): array
    {
        global $wpdb;

        $L      = 'SDH_Muhely_Ml3_Lekepzes';
        $master = self::master((string) ($sor['MASTER_ITEM_ID'] ?? ''));
        $termek = $master !== null && $master[0] ? self::uj_id('APP.MASTER_ITEM', $sor['MASTER_ITEM_ID']) : 0;
        $kapcs  = [];
        $tet_id = 0;

        if ((string) ($sor['CONNECTION_KEY'] ?? '') === 'WORKSHEET') {
            $ml = self::uj_id('APP.WORKSHEET', $sor['CONNECTION_ID'] ?? '');

            if ($ml === 0) {
                $ki['megj'] = 'A munkalapja nincs meg.';
            } elseif ((int) ($sor['CANCELLED'] ?? 0) === 1) {
                $ki['megj'] = 'Sztornózott tétel – a régi végösszegben sem szerepel; csak a régi adatok közt.';

                if ((int) $kov['uj_id'] === 0) {
                    $wpdb->query($wpdb->prepare(
                        'UPDATE ' . SDH_Muhely_Schema::tabla('munkalap') . " SET megjegyzes = CONCAT(COALESCE(megjegyzes, ''), %s) WHERE id = %d",
                        "\nMunkaLap 3 – sztornózott tétel: " . $L::s($sor['NAME'] ?? '') . ' (' . abs($L::szam($sor['QUANTITY'] ?? 0)) . ' ' . $L::s($sor['UNIT'] ?? 'db') . ')',
                        $ml
                    ));
                }
            } else {
                $adat = $L::tetel($sor, $ml, $termek) + ['forras' => self::FORRAS, 'kulso_azonosito' => (string) $sor['ITEM_ID']];
                [$tet_id, $er] = self::ir('tetel', $adat, $kov);
                $tet_id = abs($tet_id);
                $ki = ['uj_tabla' => 'tetel', 'uj_id' => $tet_id, 'letrehozta' => in_array($er, ['uj', 'frissitett'], true) ? 1 : 0, 'kapcsolt' => '', 'megj' => '', 'eredmeny' => $er, 'fajl_kesz' => []];
            }
        } elseif ((string) ($sor['CONNECTION_KEY'] ?? '') !== '') {
            $ki['megj'] = 'Nem munkalaphoz tartozó tétel (' . $L::s($sor['CONNECTION_KEY']) . ').';
        } else {
            $ki['megj'] = 'Készletmozgás (' . ($L::IRANYOK[(int) ($sor['DIRECTION'] ?? 3)] ?? '') . ').';
        }

        // Készletmozgás: a készletkezelt termék minden nem sztornózott mozgása (a régi készlet ezekből áll össze).
        if ($termek > 0 && $master !== null && $master[1] && (int) ($sor['CANCELLED'] ?? 0) === 0) {
            if (preg_match('/mozgas:(\d+)/', (string) $kov['kapcsolt'], $m) === 1) {
                $wpdb->update(SDH_Muhely_Schema::tabla('termek_mozgas'), ['mennyiseg' => round($L::szam($sor['QUANTITY'] ?? 0), 3)], ['id' => (int) $m[1]]);
                $kapcs[] = 'mozgas:' . (int) $m[1];
            } else {
                $wpdb->insert(SDH_Muhely_Schema::tabla('termek_mozgas'), [
                    'termek_id'   => $termek,
                    'tipus'       => $L::mozgas_tipus($sor),
                    'mennyiseg'   => round($L::szam($sor['QUANTITY'] ?? 0), 3),
                    'munkalap_id' => (string) ($sor['CONNECTION_KEY'] ?? '') === 'WORKSHEET' ? self::uj_id('APP.WORKSHEET', $sor['CONNECTION_ID'] ?? '') : 0,
                    'tetel_id'    => $tet_id,
                    'megjegyzes'  => mb_substr('MunkaLap 3 – ' . ($L::IRANYOK[(int) ($sor['DIRECTION'] ?? 3)] ?? '') . ($L::s($sor['NOTES'] ?? '') !== '' && (string) ($sor['CONNECTION_KEY'] ?? '') === '' ? ': ' . $L::s($sor['NOTES']) : ''), 0, 255),
                    'felhasznalo' => self::felh($sor['SYSTEM_CREATOR'] ?? null),
                    'letrehozva'  => $L::ido($sor['TRANSACTION_TIME'] ?? '') ?? $L::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? current_time('mysql'),
                ]);
                $mid = (int) $wpdb->insert_id;
                self::kovet('mozgas', $mid);
                $kapcs[] = 'mozgas:' . $mid;
            }

            if ($ki['uj_tabla'] === '') {
                $ki['eredmeny'] = 'mozgas';
            }
        }

        $ki['kapcsolt'] = implode(',', $kapcs);

        return $ki;
    }

    /* =================================================================
     * Zárás
     * ============================================================== */

    private static function zar(array $a): void
    {
        global $wpdb;

        $z  = (array) ($a['zaras'] ?? ['lepes' => 'keszlet', 'utolso' => 0]);
        $nt = self::nyers_tabla();

        if ($z['lepes'] === 'keszlet') {
            $wpdb->query('START TRANSACTION');

            $muvelet = static function () use (&$z, &$a, $wpdb, $nt): void {
                while (self::van_ido()) {
                    $sorok = (array) $wpdb->get_results($wpdb->prepare("SELECT id, uj_id, adat FROM {$nt} WHERE tabla = 'APP.MASTER_ITEM' AND uj_tabla = 'termek' AND id > %d ORDER BY id LIMIT 200", (int) $z['utolso']));

                    if ($sorok === []) {
                        $z['lepes'] = 'riport';
                        break;
                    }

                    foreach ($sorok as $s) {
                        $z['utolso'] = (int) $s->id;
                        $m           = json_decode((string) $s->adat, true);

                        if (!is_array($m) || (int) ($m['STOCK_CONTROL'] ?? 0) !== 1) {
                            continue;
                        }

                        $most = SDH_Muhely_Termek::keszlet_ujraszamol((int) $s->uj_id);
                        $kell = round((float) ($m['QUANTITY'] ?? 0), 3);

                        if (abs($most - $kell) > 0.0005) {
                            $wpdb->insert(SDH_Muhely_Schema::tabla('termek_mozgas'), [
                                'termek_id' => (int) $s->uj_id, 'tipus' => 'korrekcio', 'mennyiseg' => round($kell - $most, 3),
                                'megjegyzes' => 'MunkaLap 3 – egyeztetés a régi készlettel', 'letrehozva' => current_time('mysql'),
                            ]);
                            self::kovet('mozgas', (int) $wpdb->insert_id);
                            SDH_Muhely_Termek::keszlet_ujraszamol((int) $s->uj_id);
                            self::stat($a, 'APP.MASTER_ITEM', 'keszlet_korrekcio');
                        }
                    }
                }
            };

            class_exists('SDH_Muhely_Valtozasnaplo') ? SDH_Muhely_Valtozasnaplo::csendben($muvelet) : $muvelet();
            self::kovet_ir();
            $a['zaras'] = $z;
            self::allapot_ment($a);
            $wpdb->query('COMMIT');

            if ($z['lepes'] !== 'riport') {
                return;
            }
        }

        $a['eredmeny']  = self::riport($a);
        $a['fazis']     = 'befejezve';
        $a['befejezve'] = time();
        $a['atvett_mar'] = true;
        $a['uzenet']    = 'Kész: az átvétel befejeződött.';

        if (class_exists('SDH_Muhely_Gyorsitotar')) {
            SDH_Muhely_Gyorsitotar::ervenytelenit();
        }

        self::allapot_ment($a);

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            $e = $a['eredmeny'];
            SDH_Muhely_Valtozasnaplo::esemeny('atvetel', 0, 'Munkalapszoftver Clone: átvétel kész – ' . number_format((int) $e['nyers'], 0, ',', ' ') . ' régi sor, '
                . (int) $e['uj']['munkalap'] . ' munkalap, ' . (int) $e['uj']['ugyfel'] . ' ügyfél, ' . (int) $e['uj']['eszkoz'] . ' eszköz, ' . (int) $e['uj']['csatolmany'] . ' fájl.');
        }
    }

    /**
     * Az ellenőrző riport: régi sorok vs. nyers tár, új rekordok táblánként, végösszegek.
     *
     * @return array<string, mixed>
     */
    public static function riport(array $a): array
    {
        global $wpdb;

        $nt     = self::nyers_tabla();
        $tablak = [];

        foreach ((array) ($a['manifest']['tablak'] ?? []) as $t) {
            $bent = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$nt} WHERE tabla = %s", $t['nev']));
            $lek  = (array) $wpdb->get_results($wpdb->prepare("SELECT uj_tabla, COUNT(*) db FROM {$nt} WHERE tabla = %s AND uj_tabla <> '' GROUP BY uj_tabla", $t['nev']));
            $tablak[$t['nev']] = [
                'regi' => (int) $t['sorok'], 'bent' => $bent,
                'lekepezve' => array_combine(array_map(static fn ($x): string => (string) $x->uj_tabla, $lek), array_map(static fn ($x): int => (int) $x->db, $lek)) ?: [],
                'stat' => (array) ($a['atvetel']['stat'][$t['nev']] ?? []),
            ];
        }

        $uj = [];

        foreach (array_keys(self::KOVETETT) as $k) {
            $uj[$k] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('atvetel_sor') . ' WHERE import_id = %s AND tabla = %s', self::IMPORT_ID, $k));
        }

        // Végösszegek: a régi lapok (nettó + áfa) és az átvett lapok bruttója.
        $regi_brutto = 0.0;

        foreach (self::jsonl_sorok('APP.WORKSHEET') as $w) {
            $regi_brutto += round((float) ($w['NET_SUM'] ?? 0) + (float) ($w['TAX_SUM'] ?? 0), 0);
        }

        $uj_brutto = (float) $wpdb->get_var($wpdb->prepare('SELECT SUM(brutto_ertek) FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE forras = %s', self::FORRAS));
        $fajl_db   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$nt} WHERE adat LIKE '%\"\$fajl\"%'");

        return [
            'ido' => current_time('mysql'), 'nyers' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$nt}"), 'regi_sorok' => (int) ($a['manifest']['osszes_sor'] ?? 0),
            'tablak' => $tablak, 'uj' => $uj, 'regi_brutto' => $regi_brutto, 'uj_brutto' => round($uj_brutto, 2),
            'fajlok_regi' => (int) ($a['manifest']['fajlok'] ?? 0), 'fajlok_bent' => $fajl_db, 'hibak_db' => (int) ($a['hibak_db'] ?? 0),
            'perc' => max(1, (int) round((time() - (int) ($a['atvetel']['indult'] ?? time())) / 60)),
        ];
    }

    /* =================================================================
     * Visszavonás
     * ============================================================== */

    public static function visszavon_indit(): string
    {
        $a = self::allapot();

        if (in_array((string) ($a['fazis'] ?? ''), ['atvetel', 'zaras'], true) === false && empty($a['atvett_mar']) && (int) self::utkozesek()['nyers'] === 0) {
            return 'Nincs mit visszavonni.';
        }

        $a['fazis']  = 'visszavonas';
        $a['visszavonas'] = ['i' => 0, 'torolt' => 0];
        $a['uzenet'] = 'Visszavonás folyamatban…';
        self::allapot_ment($a);

        return '';
    }

    private static function visszavon_lepes(array $a): void
    {
        global $wpdb;

        $kov  = SDH_Muhely_Schema::tabla('atvetel_sor');
        $v    = (array) ($a['visszavonas'] ?? ['i' => 0, 'torolt' => 0]);
        $kulcsok = array_keys(self::KOVETETT);

        $muvelet = static function () use (&$v, $kulcsok, $kov, $wpdb): void {
            while (self::van_ido() && $v['i'] < count($kulcsok)) {
                $k   = $kulcsok[$v['i']];
                $idk = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT sor_id FROM {$kov} WHERE import_id = %s AND tabla = %s LIMIT 1000", self::IMPORT_ID, $k)));

                if ($idk === []) {
                    $v['i']++;
                    continue;
                }

                $lista = implode(',', $idk);
                $tabla = SDH_Muhely_Schema::tabla(self::KOVETETT[$k]);

                if ($k === 'csatolmany') {
                    $mappa = trailingslashit(wp_upload_dir()['basedir']) . 'sdh-muhely/csatolmany/';

                    foreach ((array) $wpdb->get_col("SELECT tarolt_nev FROM {$tabla} WHERE id IN ({$lista})") as $t) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        if (preg_match('/^[a-f0-9]{32}$/', (string) $t) === 1) {
                            @unlink($mappa . $t); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                        }
                    }
                }

                if ($k === 'ugyfel') {
                    $wpdb->query('DELETE FROM ' . SDH_Muhely_Schema::tabla('ugyfel_szo') . " WHERE ugyfel_id IN ({$lista})"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                }

                $v['torolt'] += (int) $wpdb->query("DELETE FROM {$tabla} WHERE id IN ({$lista})"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query($wpdb->prepare("DELETE FROM {$kov} WHERE import_id = %s AND tabla = %s AND sor_id IN ({$lista})", self::IMPORT_ID, $k)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
        };

        class_exists('SDH_Muhely_Valtozasnaplo') ? SDH_Muhely_Valtozasnaplo::csendben($muvelet) : $muvelet();

        if ($v['i'] < count($kulcsok)) {
            $a['visszavonas'] = $v;
            self::allapot_ment($a);

            return;
        }

        // A nyers tár, a megőrzött fájlok és a kiegészített beállítások.
        $wpdb->query('DELETE FROM ' . self::nyers_tabla());
        self::mappa_torol(self::mappa('fajlok'));

        foreach ((array) ($a['beall_elotte'] ?? []) as $opt => $ertek) {
            $ertek === null ? delete_option((string) $opt) : update_option((string) $opt, $ertek);
        }

        if (class_exists('SDH_Muhely_Gyorsitotar')) {
            SDH_Muhely_Gyorsitotar::ervenytelenit();
        }

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('atvetel', 0, 'Munkalapszoftver Clone: az átvétel visszavonva (' . (int) $v['torolt'] . ' rekord törölve).');
        }

        self::munka_torol();
        self::urit();
        self::allapot_ment(['fazis' => 'ures', 'uzenet' => 'Visszavonva: ' . number_format((int) $v['torolt'], 0, ',', ' ') . ' átvett rekord törölve, a régi adatok tára üres.']);
    }

    /* =================================================================
     * Állapot a felületnek
     * ============================================================== */

    /** @return array<string, mixed> */
    public static function osszegzes(): array
    {
        $a   = self::allapot();
        $f   = (string) ($a['fazis'] ?? 'ures');
        $szazalek = 0;
        $reszlet  = '';

        if ($f === 'atalakitas') {
            $at = (array) ($a['atalakitas'] ?? []);
            $szazalek = (int) ($at['osszes'] ?? 0) > 0 ? (int) floor(100 * (int) $at['kesz'] / (int) $at['osszes']) : 0;
            $reszlet  = ['kibontas' => 'A mentés kibontása…', 'megnyitas' => 'Az adatbázis megnyitása…', 'export' => 'Kiolvasás: ' . (string) ($at['tabla'] ?? '')][(string) ($at['fazis'] ?? '')] ?? 'Indul…';
        } elseif ($f === 'kibontas' || $f === 'kibontasra') {
            $k = (array) ($a['kibontas'] ?? []);
            $szazalek = (int) ($k['n'] ?? 0) > 0 ? (int) floor(100 * (int) $k['i'] / (int) $k['n']) : 0;
            $reszlet  = 'Kibontás: ' . (int) ($k['i'] ?? 0) . ' / ' . (int) ($k['n'] ?? 0) . ' fájl';
        } elseif ($f === 'atvetel') {
            $at = (array) $a['atvetel'];
            $szazalek = (int) $at['osszes'] > 0 ? (int) floor(100 * (int) $at['kesz_sorok'] / (int) $at['osszes']) : 0;
            $mp       = max(1, time() - (int) $at['indult']);
            $hatra    = (int) $at['kesz_sorok'] > 0 ? (int) round($mp * ((int) $at['osszes'] - (int) $at['kesz_sorok']) / (int) $at['kesz_sorok']) : 0;
            $reszlet  = (string) ($at['tablak'][$at['i']] ?? '') . ' – ' . number_format((int) $at['kesz_sorok'], 0, ',', ' ') . ' / ' . number_format((int) $at['osszes'], 0, ',', ' ') . ' sor'
                . ($hatra > 0 ? ' · hátravan kb. ' . ($hatra >= 120 ? round($hatra / 60) . ' perc' : $hatra . ' mp') . ' (becslés)' : '');
        } elseif ($f === 'zaras') {
            $szazalek = 99;
            $reszlet  = 'Készlet újraszámolása, riport…';
        } elseif ($f === 'visszavonas') {
            $v = (array) ($a['visszavonas'] ?? []);
            $szazalek = (int) floor(100 * (int) ($v['i'] ?? 0) / count(self::KOVETETT));
            $reszlet  = number_format((int) ($v['torolt'] ?? 0), 0, ',', ' ') . ' rekord törölve';
        }

        return [
            'fazis'   => $f,
            'tovabb'  => in_array($f, ['feltoltve', 'atalakitas', 'kibontasra', 'kibontas', 'ellenorzes', 'atvetel', 'zaras', 'visszavonas'], true),
            'szazalek' => $szazalek,
            'reszlet' => $reszlet,
            'uzenet'  => (string) ($a['uzenet'] ?? ''),
            'hibak'   => array_slice((array) ($a['hibak'] ?? []), -5),
        ];
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_ml3', '_wpnonce');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ezt csak adminisztrátor végezheti.'], 403);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        self::tabla_telepit();
    }

    /** Darabolt feltöltés: a darabok sorban jönnek; az utolsó után a fájl a helyére kerül. */
    public static function ajax_feltolt_resz(): void
    {
        self::jog();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $azon   = isset($_POST['azon']) ? preg_replace('/[^a-z0-9]/', '', strtolower((string) wp_unslash($_POST['azon']))) : '';
        $resz   = isset($_POST['resz']) ? (int) $_POST['resz'] : -1;
        $osszes = isset($_POST['osszes']) ? (int) $_POST['osszes'] : 0;
        $nev    = isset($_POST['nev']) ? sanitize_file_name(wp_unslash($_POST['nev'])) : 'mentes.wdg';
        $tmp    = (string) ($_FILES['darab']['tmp_name'] ?? '');
        // phpcs:enable

        if ($azon === '' || $resz < 0 || $osszes < 1 || $resz >= $osszes || $tmp === '' || !is_uploaded_file($tmp)) {
            wp_send_json_error(['uzenet' => 'Hibás feltöltési darab.']);
        }

        if (!preg_match('/\.(wdg|zip)$/i', $nev)) {
            wp_send_json_error(['uzenet' => 'MunkaLap 3 mentést (.wdg) vagy átalakított csomagot (.zip) tölts fel.']);
        }

        $ut   = self::mappa('feltoltes') . '/' . $azon . '.resz';
        $meret = is_file($ut) ? (int) filesize($ut) : 0;

        if ($resz === 0 && $meret > 0) {
            @unlink($ut); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $meret = 0;
        }

        if ($resz > 0 && $meret !== $resz * self::RESZ_MERET) {
            wp_send_json_error(['uzenet' => 'A darabok sorrendje felborult – kezdd újra a feltöltést.', 'ujra' => true]);
        }

        $szabad = @disk_free_space(dirname($ut)); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if (is_float($szabad) && $szabad < 3 * self::RESZ_MERET) {
            wp_send_json_error(['uzenet' => 'Elfogyott a hely a szerver lemezén.']);
        }

        $be = fopen($tmp, 'rb');
        $ki = fopen($ut, 'ab');
        stream_copy_to_stream($be, $ki);
        fclose($be);
        fclose($ki);

        if ($resz < $osszes - 1) {
            wp_send_json_success(['resz' => $resz]);
        }

        $cel = self::mappa('feltoltes') . '/' . $azon . '-' . $nev;
        rename($ut, $cel);
        $hiba = self::forras_be($cel, $nev, true);

        if ($hiba !== '') {
            @unlink($cel); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            wp_send_json_error(['uzenet' => $hiba]);
        }

        wp_send_json_success(['kesz' => true] + self::osszegzes());
    }

    public static function ajax_szerver_fajl(): void
    {
        self::jog();
        $ut = isset($_POST['ut']) ? trim((string) wp_unslash($_POST['ut'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ($ut === '' || !preg_match('/\.(wdg|zip)$/i', $ut) || !@is_file($ut) || !@is_readable($ut)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            wp_send_json_error(['uzenet' => 'A fájl nem található vagy nem olvasható a szerveren (.wdg vagy .zip kell).']);
        }

        $hiba = self::forras_be($ut, basename(str_replace('\\', '/', $ut)), false);

        if ($hiba !== '') {
            wp_send_json_error(['uzenet' => $hiba]);
        }

        wp_send_json_success(self::osszegzes());
    }

    public static function ajax_lepes(): void
    {
        self::jog();
        wp_send_json_success(self::lepes());
    }

    public static function ajax_indit(): void
    {
        self::jog();
        $b = isset($_POST['beallitas']) ? json_decode((string) wp_unslash($_POST['beallitas']), true) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $hiba = self::indit(is_array($b) ? $b : []);

        if ($hiba !== '') {
            wp_send_json_error(['uzenet' => $hiba]);
        }

        wp_send_json_success(self::osszegzes());
    }

    public static function ajax_visszavon(): void
    {
        self::jog();
        $hiba = self::visszavon_indit();

        if ($hiba !== '') {
            wp_send_json_error(['uzenet' => $hiba]);
        }

        wp_send_json_success(self::osszegzes());
    }

    public static function ajax_munkafajlok(): void
    {
        self::jog();
        $a = self::allapot();

        if (in_array((string) ($a['fazis'] ?? ''), ['atvetel', 'zaras', 'visszavonas', 'atalakitas', 'kibontas'], true)) {
            wp_send_json_error(['uzenet' => 'Folyamat közben nem törölhető.']);
        }

        self::munka_torol();
        $a['munka_torolve'] = true;

        if (!in_array((string) ($a['fazis'] ?? ''), ['befejezve'], true)) {
            $a = ['fazis' => 'ures', 'atvett_mar' => !empty($a['atvett_mar']), 'eredmeny' => $a['eredmeny'] ?? null, 'beall_elotte' => $a['beall_elotte'] ?? null];
        }

        $a['uzenet'] = 'A munkafájlok törölve (az átvett adatok és fájlok megmaradtak).';
        self::allapot_ment($a);
        wp_send_json_success(self::osszegzes());
    }

    public static function ajax_alaphelyzet(): void
    {
        self::jog();
        $a = self::allapot();

        if (in_array((string) ($a['fazis'] ?? ''), ['atvetel', 'zaras', 'visszavonas'], true)) {
            wp_send_json_error(['uzenet' => 'Átvétel vagy visszavonás közben nem lehet újrakezdeni.']);
        }

        self::munka_torol();
        self::allapot_ment(['fazis' => 'ures', 'atvett_mar' => !empty($a['atvett_mar']), 'eredmeny' => $a['eredmeny'] ?? null, 'beall_elotte' => $a['beall_elotte'] ?? null, 'uzenet' => 'Új forrást választhatsz.']);
        wp_send_json_success(self::osszegzes());
    }

    public static function ajax_beall_ment(): void
    {
        self::jog();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        update_option(self::OPT_BEALL, [
            'mappa' => isset($_POST['mappa']) ? mb_substr(trim(sanitize_text_field(wp_unslash($_POST['mappa']))), 0, 255) : '',
            'java'  => isset($_POST['java']) ? mb_substr(trim(sanitize_text_field(wp_unslash($_POST['java']))), 0, 255) : '',
        ], false);
        // phpcs:enable

        wp_send_json_success(['uzenet' => 'Mentve. ' . self::java_proba()]);
    }

    /** A régi adatok böngészése: táblánként, kereséssel, lapozva. */
    public static function ajax_regi(): void
    {
        global $wpdb;

        self::jog();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $tabla  = isset($_POST['tabla']) ? preg_replace('/[^A-Z0-9_.]/', '', strtoupper((string) wp_unslash($_POST['tabla']))) : '';
        $keres  = isset($_POST['keres']) ? trim(sanitize_text_field(wp_unslash($_POST['keres']))) : '';
        $oldal  = isset($_POST['oldal']) ? max(1, (int) $_POST['oldal']) : 1;
        // phpcs:enable

        $nt   = self::nyers_tabla();
        $hol  = ['tabla = %s'];
        $ert  = [$tabla];

        if ($keres !== '') {
            $hol[] = '(kulcs = %s OR adat LIKE %s)';
            $ert[] = $keres;
            $ert[] = '%' . $wpdb->esc_like($keres) . '%';
        }

        $where = implode(' AND ', $hol);
        $db    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$nt} WHERE {$where}", $ert)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sorok = (array) $wpdb->get_results($wpdb->prepare("SELECT kulcs, adat, uj_tabla, uj_id, megj FROM {$nt} WHERE {$where} ORDER BY id LIMIT 25 OFFSET %d", array_merge($ert, [($oldal - 1) * 25]))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $ki = [];

        foreach ($sorok as $s) {
            $ki[] = [
                'kulcs' => (string) $s->kulcs, 'adat' => json_decode((string) $s->adat, true), 'uj' => self::uj_cimke((string) $s->uj_tabla, (int) $s->uj_id),
                'megj' => (string) $s->megj,
            ];
        }

        wp_send_json_success(['db' => $db, 'oldal' => $oldal, 'lapok' => max(1, (int) ceil($db / 25)), 'sorok' => $ki]);
    }

    /** Az új rekord olvasható megnevezése. */
    private static function uj_cimke(string $uj_tabla, int $id): string
    {
        global $wpdb;

        if ($id <= 0 || !isset(self::KOVETETT[$uj_tabla])) {
            return '';
        }

        $t = SDH_Muhely_Schema::tabla(self::KOVETETT[$uj_tabla]);

        switch ($uj_tabla) {
            case 'munkalap':
                $sz = (int) $wpdb->get_var($wpdb->prepare("SELECT munkalap_szam FROM {$t} WHERE id = %d", $id));

                return 'Munkalap ' . (class_exists('SDH_Muhely_Munkalap') ? SDH_Muhely_Munkalap::szam_formaz($sz) : (string) $sz);
            case 'ugyfel':
                return 'Ügyfél: ' . (string) $wpdb->get_var($wpdb->prepare("SELECT nev FROM {$t} WHERE id = %d", $id));
            case 'eszkoz':
                return 'Eszköz #' . $id;
            case 'csatolmany':
                return 'Fájl: ' . (string) $wpdb->get_var($wpdb->prepare("SELECT eredeti_nev FROM {$t} WHERE id = %d", $id));
        }

        return ['tetel' => 'Tétel', 'hiba' => 'Hiba', 'termek' => 'Termék', 'szolg' => 'Szolgáltatás', 'naplo' => 'Napló', 'mozgas' => 'Készletmozgás'][$uj_tabla] . ' #' . $id;
    }

    /** Egy régi fájl letöltése (a modul tárából). */
    public static function ajax_regi_fajl(): void
    {
        check_ajax_referer('sdh_muhely_ml3', '_wpnonce');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_die('Nincs jogosultság.', 403);
        }

        $rel = isset($_GET['f']) ? (string) wp_unslash($_GET['f']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rel = ltrim(str_replace(['..', '\\'], ['', '/'], $rel), '/');

        if (strpos($rel, 'fajlok/') !== 0) {
            wp_die('Ismeretlen fájl.', 404);
        }

        $ut = self::mappa() . '/' . $rel;

        if (!is_file($ut)) {
            wp_die('A fájl nincs meg.', 404);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($ut) . '"');
        header('Content-Length: ' . filesize($ut));
        readfile($ut);
        exit;
    }

    /** A kézi átalakító (ZIP: jar + Atalakitas.bat + leírás) a MunkaLap gépére. */
    public static function ajax_atalakito(): void
    {
        check_ajax_referer('sdh_muhely_ml3', '_wpnonce');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_die('Nincs jogosultság.', 403);
        }

        $ut = self::mappa('munka') . '/MunkaLap-atalakito.zip';
        $z  = new ZipArchive();
        $z->open($ut, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFile(self::jar_ut(), 'MunkaLap-atalakito/munkalap-atalakito.jar');
        $z->addFile(SDH_MUHELY_DIR . 'assets/ml3/Atalakitas.bat', 'MunkaLap-atalakito/Atalakitas.bat');
        $z->addFile(SDH_MUHELY_DIR . 'assets/ml3/OLVASSEL.txt', 'MunkaLap-atalakito/OLVASSEL.txt');
        $z->close();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="MunkaLap-atalakito.zip"');
        header('Content-Length: ' . filesize($ut));
        readfile($ut);
        @unlink($ut); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        exit;
    }

    /* =================================================================
     * Beállítások › Munkalapszoftver Clone
     * ============================================================== */

    private static function meret(int $bajt): string
    {
        return $bajt >= 1073741824 ? number_format($bajt / 1073741824, 2, ',', ' ') . ' GB' : ($bajt >= 1048576 ? number_format($bajt / 1048576, 1, ',', ' ') . ' MB' : number_format($bajt / 1024, 0, ',', ' ') . ' kB');
    }

    private static function szam(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    /** A régi táblák magyar neve és hová kerülnek. @return array{0: string, 1: string} */
    public static function tabla_leiras(string $tabla): array
    {
        $l = [
            'APP.CUSTOMER' => ['Ügyfelek', 'Ügyfelek'], 'APP.ADDRESS' => ['Ügyfélcímek', 'az ügyfelek címeibe'], 'APP.CITY' => ['Települések', 'a címekbe'],
            'APP.THING' => ['Tárgyak (eszközök)', 'Eszközök'], 'APP.WORKSHEET' => ['Munkalapok', 'Munkalapok (és Napi árukiadás) + állapottörténet'],
            'APP.PROBLEM' => ['Hibák', 'a munkalapok Hibák fülére (a hibakatalógus a régi adatok közt)'], 'APP.ITEM' => ['Tételek és készletmozgások', 'munkalap-tételek + készletmozgások'],
            'APP.MASTER_ITEM' => ['Termék- és szolgáltatástörzs', 'Termékek / Szolgáltatások (egyforma nevűek összevonva)'],
            'APP.WORKSHEET_ATTACHEMENT' => ['Munkalap-csatolmányok', 'a munkalapok képei'], 'APP.THING_ATTACHEMENT' => ['Tárgy-csatolmányok', 'az eszközök képei'],
            'APP.CUSTOMER_ATTACHEMENT' => ['Ügyfél-csatolmányok', 'az ügyfelek fájljai'], 'APP.EMAIL' => ['Elküldött e-mailek', 'a munkalap Fájlok fülére (HTML)'],
            'APP.EMAIL_ATTACHEMENT' => ['E-mail mellékletek', 'a munkalap Fájlok fülére'], 'APP.DATA_COLUMN' => ['Egyedi mezők', 'a párosításhoz'],
            'APP.CUSTOMER_CATEGORY' => ['Ügyfélkategóriák', 'az ügyfelek kategóriájába'], 'APP.ITEM_CATEGORY' => ['Termékkategóriák', 'a termékek kategóriájába'],
            'USERS.USER_DATA' => ['Felhasználók', 'a felelős / készítő párosításhoz'],
        ];

        return $l[$tabla] ?? [$tabla, 'csak a régi adatok közt (megőrizve, böngészhető)'];
    }

    public static function beallitas_doboz(): void
    {
        self::tabla_telepit();

        $a      = self::allapot();
        $f      = (string) ($a['fazis'] ?? 'ures');
        $o      = self::osszegzes();
        $mb     = self::modul_beall();
        $at     = self::atalakito();
        $nonce  = wp_create_nonce('sdh_muhely_ml3');
        $folyik = !empty($o['tovabb']);
        $lepesek = [
            1 => ['Forrás', ['ures', 'atalakitora_var', 'hiba']],
            2 => ['Előkészítés', ['feltoltve', 'atalakitas', 'kibontasra', 'kibontas', 'ellenorzes']],
            3 => ['Párosítás', ['kesz']],
            4 => ['Átvétel', ['atvetel', 'zaras', 'visszavonas']],
            5 => ['Eredmény', ['befejezve']],
        ];
        $aktiv = 1;

        foreach ($lepesek as $n => $l) {
            if (in_array($f, $l[1], true)) {
                $aktiv = $n;
            }
        }
        ?>
        <div class="sdh-doboz sdh-ml3" data-sdh-ml3 data-nonce="<?php echo esc_attr($nonce); ?>"
             data-fazis="<?php echo esc_attr($f); ?>" data-folyik="<?php echo $folyik ? '1' : '0'; ?>"
             data-resz="<?php echo (int) self::RESZ_MERET; ?>">
            <h2 class="sdh-doboz__cim">Munkalapszoftver Clone – a MunkaLap 3 teljes átvétele</h2>
            <p class="sdh-sugo">
                A MunkaLap 3 mentéséből (<strong>.wdg</strong>) minden adat átjön: ügyfelek címekkel, eszközök, munkalapok
                állapottörténettel, hibák, tételek, termékek, szolgáltatások, készlet, képek, csatolmányok, elküldött e-mailek.
                Ami egyik mezőbe sem illik, az is megmarad a <strong>régi adatok</strong> közt (lent böngészhető). Az átvétel
                visszavonható, és egy újabb mentés átvétele csak a változásokat írja be.
            </p>

            <ol class="sdh-ml3__lepesek" aria-label="Lépések">
                <?php foreach ($lepesek as $n => $l) : ?>
                    <li class="sdh-ml3__lepes<?php echo $n === $aktiv ? ' is-aktiv' : ($n < $aktiv ? ' is-kesz' : ''); ?>"><span><?php echo (int) $n; ?></span><?php echo esc_html($l[0]); ?></li>
                <?php endforeach; ?>
            </ol>

            <div class="sdh-ml3__allapot" data-sdh-ml3-allapot<?php echo $folyik ? '' : ' hidden'; ?>>
                <div class="sdh-ml3__csik"><span data-sdh-ml3-csik style="width:<?php echo (int) $o['szazalek']; ?>%"></span></div>
                <p class="sdh-ml3__reszlet"><strong data-sdh-ml3-szazalek><?php echo (int) $o['szazalek']; ?>%</strong> <span data-sdh-ml3-reszlet><?php echo esc_html((string) $o['reszlet']); ?></span></p>
                <p class="sdh-sugo">A folyamat a háttérben, adagokban halad – amíg ez az oldal nyitva van. Ha bezárod, később innen folytatódik.</p>
            </div>
            <p class="sdh-mezo__sugo" data-sdh-ml3-uzenet role="status"><?php echo esc_html((string) ($a['uzenet'] ?? '')); ?></p>
            <?php if (!empty($a['hibak'])) : ?>
                <details class="sdh-ml3__hibak"><summary>Figyelmeztetések (<?php echo (int) ($a['hibak_db'] ?? count((array) $a['hibak'])); ?>)</summary>
                    <pre class="sdh-meres__ki"><?php echo esc_html(implode("\n", array_slice((array) $a['hibak'], -30))); ?></pre></details>
            <?php endif; ?>

            <?php if (in_array($f, ['ures', 'atalakitora_var', 'hiba', 'befejezve'], true)) : ?>
                <section class="sdh-ml3__resz">
                    <h3><?php echo $f === 'befejezve' ? 'Újabb mentés átvétele (csak a változások)' : '1. A mentés'; ?></h3>
                    <div class="sdh-mezok">
                        <div class="sdh-mezo sdh-mezo--szeles">
                            <label for="ml3_fajl">MunkaLap 3 mentés (.wdg) vagy átalakított csomag (.zip)</label>
                            <input type="file" id="ml3_fajl" accept=".wdg,.zip" data-sdh-ml3-fajl>
                            <span class="sdh-mezo__sugo">A MunkaLap 3-ban: Adatbázis › Mentés. A fájl akár több GB is lehet – darabokban megy fel.</span>
                        </div>
                    </div>
                    <p><button type="button" class="sdh-gomb" data-sdh-ml3-feltolt>Feltöltés</button></p>
                    <div class="sdh-mezok">
                        <div class="sdh-mezo sdh-mezo--szeles">
                            <label for="ml3_ut">… vagy a fájl útvonala ezen a szerveren</label>
                            <input type="text" id="ml3_ut" placeholder="C:\Users\…\Desktop\MunkaLap Adatbázis (….wdg" data-sdh-ml3-ut>
                            <span class="sdh-mezo__sugo">Ha a rendszer ugyanazon a gépen fut, ahol a mentés van: nem kell feltölteni.</span>
                        </div>
                    </div>
                    <p><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ml3-szerver>Ezt a fájlt használom</button></p>
                </section>
            <?php endif; ?>

            <?php if ($f === 'atalakitora_var' || $f === 'ures' || $f === 'hiba') : ?>
                <section class="sdh-ml3__resz">
                    <h3>Átalakító</h3>
                    <p class="sdh-sugo">
                        A .wdg a MunkaLap 3 saját adatbázisát tartalmazza. Az átalakító a MunkaLap 3 <strong>saját</strong> Javájával
                        olvassa ki – ha ezen a gépen fut a MunkaLap 3, ez magától megy.
                        Állapot: <strong><?php echo esc_html(is_string($at) ? $at : 'elérhető (' . $at['java'] . ')'); ?></strong>
                    </p>
                    <div class="sdh-mezok">
                        <div class="sdh-mezo">
                            <label for="ml3_mappa">A MunkaLap 3 programmappája</label>
                            <input type="text" id="ml3_mappa" value="<?php echo esc_attr($mb['mappa']); ?>" placeholder="<?php echo esc_attr(self::munkalap_mappa() !== '' ? self::munkalap_mappa() : 'C:\MunkaLap\MunkaLap-3'); ?>" data-sdh-ml3-mappa>
                        </div>
                        <div class="sdh-mezo">
                            <label for="ml3_java">Java (üresen a MunkaLap sajátja)</label>
                            <input type="text" id="ml3_java" value="<?php echo esc_attr($mb['java']); ?>" placeholder="…\jre\win-64\bin\java.exe" data-sdh-ml3-java>
                        </div>
                    </div>
                    <p>
                        <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ml3-beall>Mentés és próba</button>
                        <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(add_query_arg(['action' => 'sdh_muhely_ml3_atalakito', '_wpnonce' => $nonce], admin_url('admin-ajax.php'))); ?>">Átalakító letöltése (a MunkaLap gépére)</a>
                    </p>
                    <p class="sdh-sugo">Kézzel: bontsd ki a letöltött ZIP-et a MunkaLap gépén, húzd a .wdg fájlt az <strong>Atalakitas.bat</strong>-ra, és a mellé kerülő <strong>…-csomag.zip</strong>-et töltsd fel fent.</p>
                </section>
            <?php endif; ?>

            <?php if ($f === 'kesz') : ?>
                <?php self::parositas_doboz($a); ?>
            <?php endif; ?>

            <?php if ($f === 'befejezve' || (!empty($a['eredmeny']) && in_array($f, ['ures'], true))) : ?>
                <?php self::eredmeny_doboz((array) $a['eredmeny']); ?>
            <?php endif; ?>

            <?php if (!in_array($f, ['atvetel', 'zaras', 'visszavonas'], true) && ($f !== 'ures' || !empty($a['forras']))) : ?>
                <p class="sdh-ml3__gombok">
                    <?php if ($f !== 'ures') : ?>
                        <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ml3-munkafajlok>Munkafájlok törlése</button>
                        <?php if ($f !== 'befejezve') : ?>
                            <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ml3-alaphelyzet>Másik mentést választok</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if (!in_array($f, ['atvetel', 'zaras', 'visszavonas'], true) && ((int) self::utkozesek()['nyers'] > 0)) : ?>
                <section class="sdh-ml3__resz">
                    <h3>Visszavonás</h3>
                    <p class="sdh-sugo">Töröl mindent, amit ez a modul létrehozott (ügyfelek, eszközök, munkalapok, tételek, fájlok…), és üríti a régi adatok tárát. A más módon felvett adatokhoz nem nyúl.</p>
                    <p><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ml3-visszavon>Az átvétel visszavonása</button></p>
                </section>
                <?php self::regi_doboz(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function parositas_doboz(array $a): void
    {
        $jav   = (array) ($a['javaslat'] ?? []);
        $man   = (array) ($a['manifest'] ?? []);
        $ut    = (array) ($a['utkozes'] ?? []);
        $felel = class_exists('SDH_Muhely_Munkalap') ? SDH_Muhely_Munkalap::felelosok() : [];
        $cimek = ['CUSTOMER' => 'Ügyfél', 'THING' => 'Tárgy (eszköz)', 'WORKSHEET' => 'Munkalap'];
        ?>
        <section class="sdh-ml3__resz" data-sdh-ml3-parositas>
            <h3>3. Mi hová kerül</h3>
            <p class="sdh-sugo">
                A mentés: <strong><?php echo esc_html((string) ($a['forras']['nev'] ?? '')); ?></strong>
                (MunkaLap <?php echo esc_html((string) ($man['forras']['applicationVersion'] ?? '?')); ?>),
                <?php echo esc_html(self::szam((int) ($man['osszes_sor'] ?? 0))); ?> sor,
                <?php echo esc_html(self::szam((int) ($man['fajlok'] ?? 0))); ?> fájl (<?php echo esc_html(self::meret((int) ($man['fajl_bajt'] ?? 0))); ?>).
                Ellenőrizve: minden tábla ép (darabszám + SHA-1).
            </p>
            <?php if ((int) ($ut['munkalapok'] ?? 0) > 0 && empty($a['atvett_mar'])) : ?>
                <p class="sdh-uzenet sdh-uzenet--figyelem">
                    A rendszerben már <?php echo (int) $ut['munkalapok']; ?> munkalap van (a legnagyobb szám: <?php echo (int) $ut['max_szam']; ?>). Az átvett lapok a régi számukat kapják;
                    ahol a szám már foglalt, ott új számot kapnak (a régi szám a belső megjegyzésbe kerül). Ha ezek demó- vagy próbaadatok, előbb töröld őket.
                </p>
            <?php endif; ?>
            <table class="sdh-tabla sdh-ml3__tablak">
                <thead><tr><th>Régi tábla</th><th>Sor</th><th>Hová kerül</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($man['tablak'] ?? []) as $t) : ?>
                    <?php if ((int) $t['sorok'] === 0) { continue; } ?>
                    <?php [$nev, $hova] = self::tabla_leiras((string) $t['nev']); ?>
                    <tr><td><?php echo esc_html($nev); ?></td><td><?php echo esc_html(self::szam((int) $t['sorok'])); ?><?php echo (int) $t['fajlok'] > 0 ? ' (' . esc_html(self::szam((int) $t['fajlok'])) . ' fájl)' : ''; ?></td><td><?php echo esc_html($hova); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3>Egyedi mezők</h3>
            <p class="sdh-sugo">A MunkaLap 3 saját mezői. A javaslat a nevük alapján készült; amit „Belső megjegyzésbe” teszel, az névvel együtt a belső megjegyzésbe kerül.</p>
            <table class="sdh-tabla">
                <thead><tr><th>Hol</th><th>Mező a MunkaLap 3-ban</th><th>Hová kerül</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($jav['mezok'] ?? []) as $t => $lista) : ?>
                    <?php foreach ((array) $lista as $id => $cel) : ?>
                        <?php $e = (array) ($a['egyedi'][$t][$id] ?? []); ?>
                        <tr>
                            <td><?php echo esc_html($cimek[$t] ?? $t); ?></td>
                            <td><?php echo esc_html((string) ($e['nev'] ?? $id)); ?><?php echo empty($e['aktiv']) ? ' <span class="sdh-mezo__sugo">(kikapcsolt mező)</span>' : ''; ?></td>
                            <td><select data-sdh-ml3-mezo="<?php echo esc_attr($t . '|' . $id); ?>" aria-label="<?php echo esc_attr((string) ($e['nev'] ?? $id)); ?>">
                                <?php foreach (SDH_Muhely_Ml3_Lekepzes::mezo_celok((string) $t) as $k => $n) : ?>
                                    <option value="<?php echo esc_attr($k); ?>"<?php selected($cel, $k); ?>><?php echo esc_html($n); ?></option>
                                <?php endforeach; ?>
                            </select></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3>Felhasználók</h3>
            <p class="sdh-sugo">Kihez kerüljenek a régi felhasználó munkalapjai (felelős, készítő, állapotváltás). Üresen: senkihez – a neve a belső megjegyzésbe kerül.</p>
            <table class="sdh-tabla">
                <thead><tr><th>A MunkaLap 3-ban</th><th>Itt</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($jav['felhasznalok'] ?? []) as $id => $uid) : ?>
                    <tr><td><?php echo esc_html((string) ($a['felhasznalok'][$id] ?? $id)); ?></td>
                        <td><select data-sdh-ml3-felh="<?php echo esc_attr((string) $id); ?>" aria-label="<?php echo esc_attr((string) ($a['felhasznalok'][$id] ?? $id)); ?>">
                            <option value="0">– senki –</option>
                            <?php foreach ($felel as $fid => $fnev) : ?>
                                <option value="<?php echo (int) $fid; ?>"<?php selected((int) $uid, (int) $fid); ?>><?php echo esc_html((string) $fnev); ?></option>
                            <?php endforeach; ?>
                        </select></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="sdh-ml3__kapcsolok">
                <label class="sdh-kapcsolo"><input type="checkbox" data-sdh-ml3-opcio="arukiadas"<?php checked(!empty($jav['arukiadas'])); ?>><span class="sdh-kapcsolo__csuszka" aria-hidden="true"></span>
                    <span>A „Vásárlás” tárgyú lapok Napi árukiadásként jöjjenek át (eszköz nélkül)</span></label>
                <label class="sdh-kapcsolo"><input type="checkbox" data-sdh-ml3-opcio="kiegeszit"<?php checked(!empty($jav['kiegeszit'])); ?>><span class="sdh-kapcsolo__csuszka" aria-hidden="true"></span>
                    <span>A hiányzó fizetési módok és a „Nem javítandó” hibaállapot felvétele a beállításokba</span></label>
            </div>
            <p class="sdh-sugo">Élesítés előtt készíts mentést (Beállítások › Mentés). Az átvétel ideje a gép sebességétől függ – a végén ellenőrző riport készül.</p>
            <p><button type="button" class="sdh-gomb" data-sdh-ml3-indit>Átvétel indítása</button></p>
        </section>
        <?php
    }

    private static function eredmeny_doboz(array $e): void
    {
        if ($e === []) {
            return;
        }

        $uj = (array) $e['uj'];
        $ok = (int) $e['nyers'] >= (int) $e['regi_sorok'];
        ?>
        <section class="sdh-ml3__resz sdh-ml3__eredmeny">
            <h3>Ellenőrző riport (<?php echo esc_html((string) $e['ido']); ?>)</h3>
            <p class="sdh-uzenet <?php echo $ok ? 'sdh-uzenet--siker' : 'sdh-uzenet--figyelem'; ?>">
                <?php echo esc_html(self::szam((int) $e['regi_sorok'])); ?> régi sorból <?php echo esc_html(self::szam((int) $e['nyers'])); ?> van a régi adatok tárában
                <?php echo $ok ? '– minden sor megvan.' : '– HIÁNYZIK ' . esc_html(self::szam((int) $e['regi_sorok'] - (int) $e['nyers'])) . ' sor (lásd a figyelmeztetéseket).'; ?>
                Fájlok: <?php echo esc_html(self::szam((int) $e['fajlok_bent'])); ?> / <?php echo esc_html(self::szam((int) $e['fajlok_regi'])); ?>.
            </p>
            <table class="sdh-tabla">
                <thead><tr><th>Létrejött</th><th>Darab</th></tr></thead>
                <tbody>
                <?php foreach (['ugyfel' => 'Ügyfél', 'eszkoz' => 'Eszköz', 'munkalap' => 'Munkalap / árukiadás', 'naplo' => 'Állapotváltás (napló)', 'hiba' => 'Hiba', 'tetel' => 'Tétel', 'termek' => 'Termék', 'szolg' => 'Szolgáltatás', 'mozgas' => 'Készletmozgás', 'csatolmany' => 'Fájl (kép, csatolmány, e-mail)'] as $k => $n) : ?>
                    <tr><td><?php echo esc_html($n); ?></td><td><?php echo esc_html(self::szam((int) ($uj[$k] ?? 0))); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="sdh-sugo">
                Munkalapok végösszege: régi <strong><?php echo esc_html(number_format((float) $e['regi_brutto'], 0, ',', ' ')); ?> Ft</strong>,
                átvett <strong><?php echo esc_html(number_format((float) $e['uj_brutto'], 0, ',', ' ')); ?> Ft</strong>
                <?php echo abs((float) $e['regi_brutto'] - (float) $e['uj_brutto']) < 1 ? '– egyezik.' : '(az eltérés a már bent lévő, összekötött lapokból adódhat).'; ?>
                Időtartam: kb. <?php echo (int) $e['perc']; ?> perc.
            </p>
            <details><summary>Táblánként</summary>
                <table class="sdh-tabla">
                    <thead><tr><th>Régi tábla</th><th>Régi</th><th>Tárban</th><th>Új / frissített / változatlan</th></tr></thead>
                    <tbody>
                    <?php foreach ((array) $e['tablak'] as $t => $s) : ?>
                        <?php $st = (array) $s['stat']; ?>
                        <tr><td><?php echo esc_html(self::tabla_leiras((string) $t)[0]); ?></td><td><?php echo esc_html(self::szam((int) $s['regi'])); ?></td>
                            <td><?php echo esc_html(self::szam((int) $s['bent'])); ?><?php echo (int) $s['bent'] < (int) $s['regi'] ? ' ⚠' : ''; ?></td>
                            <td><?php echo esc_html(self::szam((int) ($st['uj'] ?? 0)) . ' / ' . self::szam((int) ($st['frissitett'] ?? 0)) . ' / ' . self::szam((int) ($st['valtozatlan'] ?? 0))); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </section>
        <?php
    }

    private static function regi_doboz(): void
    {
        global $wpdb;

        $tablak = (array) $wpdb->get_results('SELECT tabla, COUNT(*) db FROM ' . self::nyers_tabla() . ' GROUP BY tabla ORDER BY tabla');
        ?>
        <section class="sdh-ml3__resz" data-sdh-ml3-regi>
            <h3>Régi adatok</h3>
            <p class="sdh-sugo">Minden sor a MunkaLap 3-ból, ahogy ott volt – azok is, amelyeknek itt nincs helyük (beállítások, hibakatalógus, jogosultságok…).</p>
            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="ml3_regi_tabla">Tábla</label>
                    <select id="ml3_regi_tabla" data-sdh-ml3-regi-tabla>
                        <?php foreach ($tablak as $t) : ?>
                            <option value="<?php echo esc_attr((string) $t->tabla); ?>"<?php selected((string) $t->tabla, 'APP.WORKSHEET'); ?>><?php echo esc_html(self::tabla_leiras((string) $t->tabla)[0] . ' (' . self::szam((int) $t->db) . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="ml3_regi_keres">Keresés (azonosító vagy bármilyen szöveg)</label>
                    <input type="search" id="ml3_regi_keres" data-sdh-ml3-regi-keres>
                </div>
            </div>
            <p><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ml3-regi-mutat>Mutasd</button>
                <span class="sdh-mezo__sugo" data-sdh-ml3-regi-info></span></p>
            <div class="sdh-ml3__regi" data-sdh-ml3-regi-lista
                 data-fajl-url="<?php echo esc_url(add_query_arg(['action' => 'sdh_muhely_ml3_regi_fajl', '_wpnonce' => wp_create_nonce('sdh_muhely_ml3')], admin_url('admin-ajax.php'))); ?>"></div>
        </section>
        <?php
    }
}
