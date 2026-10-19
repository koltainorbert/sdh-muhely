<?php
/**
 * Telemetria (0.99.0) – a központnak küldött állapotkép és mérések. Csak központhoz kötött példányon fut.
 *
 * Mit küld: műszaki adatot (verziók, szerver, sebesség, memória, adatbázis- és lemezméret, mentés, ütemező,
 * végzetes hibák helye, bekapcsolt bővítmények, modulok) és darabszámokat (munkalap, ügyfél, felhasználó).
 * Mit NEM küld: az ügyfelek személyes adatát (név, telefon, e-mail, cím), a munkalapok tartalmát, a forgalom összegét.
 * A hibaüzenetekből az e-mail-címek és a hosszabb számsorok kimaszkolva mennek.
 *
 * A kérések ideje egy kis naplófájlba kerül (feltöltések/sdh-muhely-tel/), nem az adatbázisba – így a mérés
 * nem lassít. A szívverés kiolvassa és üríti.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Telemetria
{
    /** Autoload kapcsoló: van-e központ (a kérésenkénti mérés csak ekkor fut). */
    public const OPT_BE    = 'sdh_muhely_tel_be';
    public const OPT_HIBA  = 'sdh_muhely_tel_hiba';
    public const OPT_AKTIV = 'sdh_muhely_tel_aktiv';
    private const TR_FELT  = 'sdh_muhely_tel_feltoltes';

    /** Ennyi ms felett lassú egy kérés. */
    public const LASSU_MS = 1000;

    private static ?float $kezdet = null;

    public static function init(): void
    {
        if (!get_option(self::OPT_BE)) {
            return;
        }

        self::$kezdet = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
        register_shutdown_function([self::class, 'leallas']);
    }

    public static function bekapcsol(bool $be): void
    {
        if ((bool) get_option(self::OPT_BE) !== $be) {
            update_option(self::OPT_BE, $be ? 1 : 0, true);
        }
    }

    /* =================================================================
     * Kérésenként: idő, felhasználó, végzetes hiba
     * ============================================================== */

    public static function leallas(): void
    {
        $hiba = error_get_last();

        if (is_array($hiba) && in_array((int) $hiba['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            self::hiba_rogzit((int) $hiba['type'], (string) $hiba['message'], (string) $hiba['file'], (int) $hiba['line']);
        }

        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI) || PHP_SAPI === 'cli' || self::$kezdet === null) {
            return;
        }

        $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        // Csak a bejelentkezett munka számít (a nyilvános RMA- és foglalóoldal nem).
        if ($uid <= 0) {
            return;
        }

        $ms  = (int) round((microtime(true) - self::$kezdet) * 1000);
        $mem = (int) round(memory_get_peak_usage(true) / 1048576);
        $f   = self::naplofajl();

        if ($f !== '') {
            @file_put_contents($f, time() . ',' . $uid . ',' . $ms . ',' . $mem . "\n", FILE_APPEND | LOCK_EX); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        }
    }

    private static function naplofajl(): string
    {
        $up = wp_upload_dir(null, false);

        if (!empty($up['error']) || empty($up['basedir'])) {
            return '';
        }

        $mappa = trailingslashit((string) $up['basedir']) . 'sdh-muhely-tel';

        if (!is_dir($mappa)) {
            wp_mkdir_p($mappa);
            @file_put_contents($mappa . '/.htaccess', "Require all denied\nDeny from all\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
            @file_put_contents($mappa . '/index.php', "<?php\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        }

        return $mappa . '/keresek.log';
    }

    /** Végzetes hiba: helye és (maszkolt) üzenete, aláírásonként számlálva, legfeljebb 20 különböző. */
    public static function hiba_rogzit(int $tipus, string $uzenet, string $fajl, int $sor): void
    {
        $nevek = [E_ERROR => 'Végzetes hiba', E_PARSE => 'Szintaktikai hiba', E_CORE_ERROR => 'PHP-maghiba', E_COMPILE_ERROR => 'Fordítási hiba', E_USER_ERROR => 'Felhasználói hiba'];
        $fajl  = str_replace(['\\', wp_normalize_path(ABSPATH)], ['/', ''], wp_normalize_path($fajl));
        $sig   = substr(md5($tipus . '|' . $fajl . '|' . $sor), 0, 12);
        $l     = get_option(self::OPT_HIBA, []);
        $l     = is_array($l) ? $l : [];

        $l[$sig] = [
            'tipus'  => $nevek[$tipus] ?? 'Hiba',
            'uzenet' => self::maszkol(strtok($uzenet, "\n") ?: ''),
            'fajl'   => mb_substr($fajl, -160),
            'sor'    => $sor,
            'db'     => (int) ($l[$sig]['db'] ?? 0) + 1,
            'uj'     => (int) ($l[$sig]['uj'] ?? 0) + 1,
            'utolso' => time(),
        ];

        uasort($l, static fn ($a, $b): int => (int) $b['utolso'] <=> (int) $a['utolso']);
        update_option(self::OPT_HIBA, array_slice($l, 0, 20, true), false);
    }

    /** E-mail, telefonszám-szerű számsor, hosszú azonosító kimaszkolva. */
    public static function maszkol(string $s): string
    {
        $s = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[e-mail]', $s);
        $s = (string) preg_replace('/\+?\d[\d \-\/]{5,}\d/', '[szám]', $s);
        $s = (string) preg_replace("/'[^']{30,}'/", "'…'", $s);

        return mb_substr($s, 0, 300);
    }

    /* =================================================================
     * Szívveréskor: a kérésnapló összesítése
     * ============================================================== */

    /**
     * A naplófájl kiolvasása és ürítése.
     *
     * @return array{atlag_ms: int, p95_ms: int, max_ms: int, keres: int, lassu: int, mem_mb: int, felhasznalok: array<int, int>}
     */
    public static function keresek_osszesit(bool $urit = true): array
    {
        $f   = self::naplofajl();
        $ido = [];
        $mem = 0;
        $fh  = [];

        if ($f !== '' && is_file($f)) {
            $h = @fopen($f, 'c+'); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

            if ($h) {
                flock($h, LOCK_EX);

                while (($sor = fgets($h)) !== false) {
                    $r = explode(',', trim($sor));

                    if (count($r) === 4) {
                        $ido[] = (int) $r[2];
                        $mem   = max($mem, (int) $r[3]);
                        $fh[(int) $r[1]] = max((int) ($fh[(int) $r[1]] ?? 0), (int) $r[0]);
                    }
                }

                if ($urit) {
                    ftruncate($h, 0);
                }

                flock($h, LOCK_UN);
                fclose($h); // phpcs:ignore WordPress.WP.AlternativeFunctions
            }
        }

        sort($ido);
        $n = count($ido);

        return [
            'atlag_ms'     => $n ? (int) round(array_sum($ido) / $n) : 0,
            'p95_ms'       => $n ? (int) $ido[(int) min($n - 1, floor($n * 0.95))] : 0,
            'max_ms'       => $n ? (int) end($ido) : 0,
            'keres'        => $n,
            'lassu'        => count(array_filter($ido, static fn (int $x): bool => $x > self::LASSU_MS)),
            'mem_mb'       => $mem,
            'felhasznalok' => $fh,
        ];
    }

    /** Az aktív felhasználók: 15 perc és ma (a szívverések között megőrizve). */
    private static function aktivitas(array $friss): array
    {
        $m = get_option(self::OPT_AKTIV, []);
        $m = is_array($m) ? $m : [];

        foreach ($friss as $uid => $ts) {
            $m[(int) $uid] = max((int) ($m[(int) $uid] ?? 0), (int) $ts);
        }

        $ma_eleje = (int) strtotime(current_time('Y-m-d') . ' 00:00:00') - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS);
        $m        = array_filter($m, static fn (int $ts): bool => $ts >= min($ma_eleje, time() - DAY_IN_SECONDS));
        update_option(self::OPT_AKTIV, $m, false);

        return [
            'most' => count(array_filter($m, static fn (int $ts): bool => $ts >= time() - 15 * MINUTE_IN_SECONDS)),
            'ma'   => count(array_filter($m, static fn (int $ts): bool => $ts >= $ma_eleje)),
        ];
    }

    /** Adatbázis-próba: három jellemző lekérdezés ideje (ms). */
    private static function db_proba(): int
    {
        global $wpdb;

        $t = microtime(true);
        $m = SDH_Muhely_Schema::tabla('munkalap');
        $wpdb->get_var("SELECT COUNT(*) FROM {$m}"); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->get_var("SELECT COUNT(*) FROM {$m} WHERE lezarva IS NULL"); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->get_results("SELECT id FROM {$m} ORDER BY id DESC LIMIT 50"); // phpcs:ignore WordPress.DB.PreparedSQL

        return (int) round((microtime(true) - $t) * 1000);
    }

    /** Az adatbázis mérete MB-ban (SQLite: a fájl; MySQL: a táblák). */
    private static function db_mb(): int
    {
        global $wpdb;

        if (defined('DB_ENGINE') && DB_ENGINE === 'sqlite') {
            $f = defined('FQDB') ? FQDB : (defined('DB_DIR') && defined('DB_FILE') ? DB_DIR . DB_FILE : '');

            return $f !== '' && is_file($f) ? (int) round(filesize($f) / 1048576) : 0;
        }

        $b = (float) $wpdb->get_var($wpdb->prepare('SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s', DB_NAME));

        return (int) round($b / 1048576);
    }

    /** A feltöltések mérete (naponta egyszer számolva, mert lassú lehet). */
    private static function feltoltes_mb(): int
    {
        $t = get_transient(self::TR_FELT);

        if ($t !== false) {
            return (int) $t;
        }

        $up = wp_upload_dir(null, false);
        $b  = 0;
        $db = 0;

        if (!empty($up['basedir']) && is_dir((string) $up['basedir'])) {
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $up['basedir'], FilesystemIterator::SKIP_DOTS));

                foreach ($it as $f) {
                    if (++$db > 200000) {
                        break;
                    }

                    if ($f->isFile()) {
                        $b += $f->getSize();
                    }
                }
            } catch (Throwable $x) {
                $b = 0;
            }
        }

        $mb = (int) round($b / 1048576);
        set_transient(self::TR_FELT, $mb, DAY_IN_SECONDS);

        return $mb;
    }

    private static function szam(string $sql): int
    {
        global $wpdb;

        $r = $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL

        return (int) $r;
    }

    /* =================================================================
     * Az állapotkép
     * ============================================================== */

    /**
     * @return array{allapot: array<string, mixed>, meres: array<string, int>}
     */
    public static function osszeallit(bool $urit = true): array
    {
        global $wpdb;

        $k    = self::keresek_osszesit($urit);
        $akt  = self::aktivitas($k['felhasznalok']);
        $dbms = self::db_proba();
        $dbmb = self::db_mb();
        $ma   = current_time('Y-m-d');
        $m    = SDH_Muhely_Schema::tabla('munkalap');
        $u    = SDH_Muhely_Schema::tabla('ugyfel');
        $e    = SDH_Muhely_Schema::tabla('eszkoz');
        $t    = SDH_Muhely_Schema::tabla('termek');

        $szabad = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $ossz   = function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors

        $cron = function_exists('_get_cron_array') ? (array) _get_cron_array() : [];
        $kesik = 0;
        $legregebbi = 0;

        foreach (array_keys($cron) as $ts) {
            if ((int) $ts < time() - 60) {
                $kesik += count((array) $cron[$ts]);
                $legregebbi = max($legregebbi, (int) floor((time() - (int) $ts) / 60));
            }
        }

        $opcache = false;

        if (function_exists('opcache_get_status')) {
            $s = @opcache_get_status(false); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $opcache = is_array($s) && !empty($s['opcache_enabled']);
        }

        $motor = defined('DB_ENGINE') && DB_ENGINE === 'sqlite' ? 'SQLite' : (stripos((string) $wpdb->db_server_info(), 'mariadb') !== false ? 'MariaDB' : 'MySQL');
        $memo  = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));

        $katalogus = [];
        $be = [];
        $ki = [];

        foreach (SDH_Muhely_Modulok::katalogus() as $kulcs => $mod) {
            $katalogus[] = ['k' => $kulcs, 'c' => (string) $mod['cim'], 'cs' => (string) $mod['csoport']];
            SDH_Muhely_Modulok::be($kulcs) ? $be[] = $kulcs : $ki[] = $kulcs;
        }

        $bov = [];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $minden = get_plugins();

        foreach ((array) get_option('active_plugins', []) as $p) {
            if (isset($minden[$p])) {
                $bov[] = ['n' => (string) $minden[$p]['Name'], 'v' => (string) $minden[$p]['Version']];
            }
        }

        $hibak = get_option(self::OPT_HIBA, []);
        $hibak = is_array($hibak) ? $hibak : [];
        $uj_hiba = 0;

        foreach ($hibak as $sig => $h) {
            $uj_hiba += (int) ($h['uj'] ?? 0);
            $hibak[$sig]['uj'] = 0;
        }

        if ($urit && $uj_hiba > 0) {
            update_option(self::OPT_HIBA, $hibak, false);
        }

        $mentes_utolso = class_exists('SDH_Muhely_Mentes') ? SDH_Muhely_Mentes::utolso() : 0;
        $mentes_info   = get_option('sdh_muhely_mentes_utolso', []);
        $mentesek      = class_exists('SDH_Muhely_Asszisztens_Tudas') ? SDH_Muhely_Asszisztens_Tudas::mentesek() : [];

        $cpu = 0;

        if (is_readable('/proc/cpuinfo')) {
            $cpu = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor'); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        } elseif (getenv('NUMBER_OF_PROCESSORS')) {
            $cpu = (int) getenv('NUMBER_OF_PROCESSORS');
        }

        $adat = [
            'munkalap'         => self::szam("SELECT COUNT(*) FROM {$m} WHERE fajta = 'javitas'"),
            'munkalap_nyitott' => self::szam("SELECT COUNT(*) FROM {$m} WHERE fajta = 'javitas' AND lezarva IS NULL"),
            'munkalap_ma'      => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$m} WHERE fajta = 'javitas' AND keszult = %s", $ma)), // phpcs:ignore WordPress.DB.PreparedSQL
            'munkalap_30'      => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$m} WHERE fajta = 'javitas' AND keszult >= %s", wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS))), // phpcs:ignore WordPress.DB.PreparedSQL
            'ugyfel'           => self::szam("SELECT COUNT(*) FROM {$u}"),
            'eszkoz'           => self::szam("SELECT COUNT(*) FROM {$e}"),
            'termek'           => self::szam("SELECT COUNT(*) FROM {$t}"),
            'felhasznalo'      => (int) (count_users()['total_users'] ?? 0),
            'db_mb'            => $dbmb,
            'feltoltes_mb'     => self::feltoltes_mb(),
        ];

        $allapot = [
            'rendszer' => [
                'php'            => PHP_VERSION,
                'wp'             => get_bloginfo('version'),
                'os'             => PHP_OS_FAMILY . ' ' . php_uname('r'),
                'szerver'        => mb_substr(sanitize_text_field((string) ($_SERVER['SERVER_SOFTWARE'] ?? '')), 0, 80),
                'sapi'           => PHP_SAPI,
                'db_motor'       => $motor,
                'db_verzio'      => (string) $wpdb->db_version(),
                'memoria_mb'     => $memo > 0 ? (int) round($memo / 1048576) : 0,
                'max_exec'       => (int) ini_get('max_execution_time'),
                'feltoltes_mb'   => (int) round(wp_max_upload_size() / 1048576),
                'opcache'        => $opcache,
                'https'          => str_starts_with((string) home_url(), 'https://'),
                'idozona'        => wp_timezone_string(),
                'nyelv'          => get_locale(),
                'cron_tiltva'    => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'debug_kijelzes' => defined('WP_DEBUG') && WP_DEBUG && (!defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY),
                'multisite'      => is_multisite(),
                'cpu'            => $cpu,
                'terheles'       => function_exists('sys_getloadavg') && is_array(@sys_getloadavg()) ? round((float) sys_getloadavg()[0], 2) : null, // phpcs:ignore WordPress.PHP.NoSilencedErrors
            ],
            'muhely' => [
                'verzio'         => SDH_MUHELY_VERSION,
                'db_verzio'      => (string) get_option('sdh_muhely_db_version', ''),
                'tesztuzem'      => class_exists('SDH_Muhely_Halozat') && SDH_Muhely_Halozat::tesztuzem(),
                'git'            => class_exists('SDH_Muhely_Frissites') && SDH_Muhely_Frissites::git_peldany(),
                'ketlepcsos'     => class_exists('SDH_Muhely_Ketlepcsos') ? (string) SDH_Muhely_Ketlepcsos::beallitas()['szabaly'] : '',
                'tavoli'         => SDH_Muhely_Tavoli::engedelyezve(),
                'licenc_allapot' => SDH_Muhely_Licenc::allapot(),
                'modulok_be'     => $be,
                'modulok_ki'     => $ki,
                'katalogus'      => $katalogus,
            ],
            'adat'     => $adat,
            'mentes'   => [
                'utolso'     => $mentes_utolso,
                'db'         => count($mentesek),
                'meret_mb'   => $mentesek !== [] ? round((int) $mentesek[0]['meret'] / 1048576, 1) : 0,
                'napi'       => class_exists('SDH_Muhely_Mentes') && !empty(SDH_Muhely_Mentes::beallitas()['aktiv']),
                'megtartas'  => class_exists('SDH_Muhely_Mentes') ? SDH_Muhely_Mentes::megtartas() : 0,
                'kulso_hiba' => is_array($mentes_info) ? self::maszkol((string) ($mentes_info['hiba'] ?? '')) : '',
            ],
            'halozat'  => class_exists('SDH_Muhely_Halozat') ? ['online' => SDH_Muhely_Halozat::online(), 'ellenorizve' => (int) (SDH_Muhely_Halozat::allapot()['ido'] ?? 0)] : [],
            'cron'     => ['osszes' => array_sum(array_map('count', $cron)), 'kesik' => $kesik, 'kesik_perc' => $legregebbi, 'kovetkezo' => (int) (array_keys($cron)[0] ?? 0)],
            'lemez'    => ['szabad_mb' => $szabad !== false ? (int) round($szabad / 1048576) : -1, 'ossz_mb' => $ossz !== false ? (int) round($ossz / 1048576) : -1],
            'sebesseg' => ['atlag_ms' => $k['atlag_ms'], 'p95_ms' => $k['p95_ms'], 'max_ms' => $k['max_ms'], 'keres' => $k['keres'], 'lassu' => $k['lassu'], 'db_ms' => $dbms, 'mem_mb' => $k['mem_mb']],
            'aktivitas' => $akt,
            'hibak'    => array_values(array_map(static fn (array $h): array => array_diff_key($h, ['uj' => 1]), $hibak)),
            'bovitmenyek' => $bov,
        ];

        return [
            'allapot' => $allapot,
            'meres'   => [
                'valasz_ms'   => $k['atlag_ms'],
                'p95_ms'      => $k['p95_ms'],
                'db_ms'       => $dbms,
                'mem_mb'      => $k['mem_mb'],
                'db_mb'       => $dbmb,
                'lemez_mb'    => $szabad !== false ? (int) round($szabad / 1048576) : 0,
                'hiba'        => $uj_hiba,
                'aktiv'       => $akt['most'],
                'keres'       => $k['keres'],
                'munkalap_ma' => $adat['munkalap_ma'],
            ],
        ];
    }
}
