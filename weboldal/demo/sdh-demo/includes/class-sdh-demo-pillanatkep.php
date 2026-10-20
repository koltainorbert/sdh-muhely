<?php
/**
 * Pillanatkép és visszaállítás: a műhely összes táblája ({prefix}sdh_…, az értékesítő / központ / bemutató táblái
 * nélkül), a műhely beállításai (sdh_… opciók, a verziójelzők nélkül) és a bemutató alapfelhasználói (a generált
 * munkatársak: sor + minden meta) egy tömörített (gzip, soronként JSON) fájlba, védett mappába.
 *
 * - keszit(): táblánként kötegekben olvas (azonosító szerinti lapozás), folyamatosan ír – nagy adatnál sincs memóriagond.
 * - visszaallit(): egy tranzakcióban (SQLite-on közvetlen PDO, MySQL / MariaDB-n $wpdb) táblánként ürít és kötegelt,
 *   többsoros INSERT-tel tölt vissza; a pillanatkép óta létrejött táblák kiürülnek, az azóta bejött oszlopok
 *   alapértéket kapnak. Utána: a látogatói fiókok törlése, a kimenő üzenetek és a keret-napló ürítése, a látogatói
 *   csatolmányfájlok (amelyekre már nem mutat sor) törlése, gyorsítótár.
 *
 * Éjjel 03:00-kor (Europe/Budapest) magától fut (SDH_Demo::ejjeli), és az admin gombjáról is.
 *
 * @package SDH_Demo
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Demo_Pillanatkep
{
    /** Ennyi sor egy olvasási kötegben. */
    private const OLVAS = 2000;

    /** Ennyi sor egy INSERT-ben (MySQL: a max_allowed_packet miatt kisebb). */
    private const IR_SQLITE = 500;
    private const IR_MYSQL  = 250;

    /** A visszaállításkor meghagyott (verzió- és telepítési) opciók. */
    private const OPCIO_MARAD = [
        'sdh_muhely_version', 'sdh_muhely_db_version', 'sdh_muhely_rewrite_verzio', 'sdh_muhely_jog_telepitve', 'sdh_muhely_telepitve',
    ];

    /* =================================================================
     * Környezet
     * ============================================================== */

    /** A védett mappa (a webről nem olvasható; a valódi telepítésnél a webgyökéren kívülre is tehető). */
    public static function mappa(): string
    {
        $m = defined('BEMUTATO_PILLANATKEP_MAPPA') ? (string) BEMUTATO_PILLANATKEP_MAPPA : WP_CONTENT_DIR . '/bemutato-pillanatkep';
        $m = untrailingslashit($m);

        if (!is_dir($m)) {
            wp_mkdir_p($m);
        }

        $vedelem = [
            'index.php'  => "<?php\n// Csend.\n",
            '.htaccess'  => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];

        foreach ($vedelem as $nev => $tartalom) {
            if (!file_exists($m . '/' . $nev)) {
                file_put_contents($m . '/' . $nev, $tartalom);
            }
        }

        return $m;
    }

    public static function van(): bool
    {
        $f = (string) (SDH_Demo::allapot()['pillanatkep']['fajl'] ?? '');

        return $f !== '' && is_readable($f);
    }

    public static function pdo(): ?PDO
    {
        global $wpdb;

        // SQLite-integráció: a régi fordító get_pdo(), az új (MySQL-on-SQLite) meghajtó get_connection()->get_pdo().
        $dbh = $wpdb->dbh ?? null;
        $p   = null;

        if (is_object($dbh) && method_exists($dbh, 'get_pdo')) {
            $p = $dbh->get_pdo();
        } elseif (is_object($dbh) && method_exists($dbh, 'get_connection')) {
            $c = $dbh->get_connection();
            $p = is_object($c) && method_exists($c, 'get_pdo') ? $c->get_pdo() : null;
        }

        if ($p instanceof PDO) {
            $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $p;
        }

        return null;
    }

    /** A mentendő táblák teljes neve. @return array<int, string> */
    public static function tablak(): array
    {
        global $wpdb;

        $e   = $wpdb->prefix . 'sdh_';
        $pdo = self::pdo();
        $osszes = $pdo !== null
            ? $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN)
            : $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($e) . '%'));
        $ki  = [];

        foreach ((array) $osszes as $t) {
            $t = (string) $t;

            if (strpos($t, $e) !== 0) {
                continue;
            }

            $r = substr($t, strlen($e));

            if (strpos($r, 'sales_') === 0 || strpos($r, 'kozpont_') === 0 || strpos($r, 'demo_') === 0) {
                continue;
            }

            $ki[] = $t;
        }

        sort($ki);

        return $ki;
    }

    /** @return array<int, string> */
    private static function oszlopok(string $tabla): array
    {
        global $wpdb;

        $pdo = self::pdo();

        if ($pdo !== null) {
            return array_map(static fn (array $r): string => (string) $r['name'], $pdo->query('PRAGMA table_info(' . self::azon($tabla) . ')')->fetchAll(PDO::FETCH_ASSOC));
        }

        return array_map('strval', (array) $wpdb->get_col('SHOW COLUMNS FROM `' . esc_sql($tabla) . '`'));
    }

    private static function azon(string $nev): string
    {
        return '`' . str_replace('`', '', $nev) . '`';
    }

    /** @return array<int, array<int, mixed>> */
    private static function sorok(string $sql): array
    {
        global $wpdb;

        $pdo = self::pdo();

        return $pdo !== null ? $pdo->query($sql)->fetchAll(PDO::FETCH_NUM) : (array) $wpdb->get_results($sql, ARRAY_N); // phpcs:ignore
    }

    private static function fut(string $sql): void
    {
        global $wpdb;

        $pdo = self::pdo();

        if ($pdo !== null) {
            $pdo->exec($sql);

            return;
        }

        if ($wpdb->query($sql) === false) { // phpcs:ignore
            throw new RuntimeException('Adatbázis-hiba: ' . (string) $wpdb->last_error);
        }
    }

    /** @param mixed $v */
    private static function ertek($v): string
    {
        if ($v === null) {
            return 'NULL';
        }

        $pdo = self::pdo();

        return $pdo !== null ? (string) $pdo->quote((string) $v) : "'" . esc_sql((string) $v) . "'";
    }

    private static function sor_json(array $adat): string
    {
        return (string) json_encode($adat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /** A mentendő opciók. @return array<int, array<int, string>> */
    private static function opciok(): array
    {
        global $wpdb;

        $sorok = (array) $wpdb->get_results(
            "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'sdh%'",
            ARRAY_N
        );

        return array_values(array_filter($sorok, static fn (array $r): bool => self::opcio_mentendo((string) $r[0])));
    }

    private static function opcio_mentendo(string $nev): bool
    {
        if (strpos($nev, 'sdh_') !== 0 || in_array($nev, self::OPCIO_MARAD, true)) {
            return false;
        }

        foreach (['sdh_sales', 'sdh_kozpont', 'sdh_demo', 'sdh_tesztadat'] as $e) {
            if (strpos($nev, $e) === 0) {
                return false;
            }
        }

        return true;
    }

    /** A pillanatképbe kerülő felhasználók: minden nem adminisztrátor, nem látogató fiók (a generált munkatársak). @return array<int, int> */
    private static function felhasznalok(): array
    {
        $ki = [];

        foreach (get_users(['fields' => 'all', 'number' => 500]) as $u) {
            $szerepek = (array) $u->roles;

            if (in_array('administrator', $szerepek, true) || in_array(SDH_Demo::SZEREP, $szerepek, true)) {
                continue;
            }

            $ki[] = (int) $u->ID;
        }

        return $ki;
    }

    /* =================================================================
     * Pillanatkép
     * ============================================================== */

    /** @return array{fajl: string, meret: int, mp: float, tablak: int, sorok: int} */
    public static function keszit(): array
    {
        global $wpdb;

        $kezd = microtime(true);
        @set_time_limit(600); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        $mappa = self::mappa();
        $fajl  = $mappa . '/pillanatkep-' . gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(8, false)) . '.jsonl.gz';
        $tmp   = $fajl . '.tmp';
        $gz    = gzopen($tmp, 'wb6');

        if ($gz === false) {
            throw new RuntimeException('A pillanatkép fájlja nem írható: ' . $mappa);
        }

        $sorok = 0;
        $maxok = [];
        $tablak = self::tablak();

        try {
            foreach ($tablak as $t) {
                $o   = self::oszlopok($t);
                $rov = substr($t, strlen($wpdb->prefix . 'sdh_'));
                gzwrite($gz, self::sor_json(['t' => $rov, 'o' => $o]));
                $idx = array_search('id', $o, true);

                if ($idx !== false) {
                    $utso = 0;

                    do {
                        $adag = self::sorok('SELECT * FROM ' . self::azon($t) . ' WHERE id > ' . (int) $utso . ' ORDER BY id LIMIT ' . self::OLVAS);

                        foreach ($adag as $r) {
                            gzwrite($gz, self::sor_json(array_values($r)));
                            $utso = (int) $r[$idx];
                        }

                        $sorok += count($adag);
                    } while (count($adag) === self::OLVAS);

                    $maxok[$rov] = $utso;
                } else {
                    $tol = 0;

                    do {
                        $adag = self::sorok('SELECT * FROM ' . self::azon($t) . ' LIMIT ' . self::OLVAS . ' OFFSET ' . (int) $tol);

                        foreach ($adag as $r) {
                            gzwrite($gz, self::sor_json(array_values($r)));
                        }

                        $tol   += count($adag);
                        $sorok += count($adag);
                    } while (count($adag) === self::OLVAS);
                }
            }

            gzwrite($gz, self::sor_json(['opciok' => 1]));

            foreach (self::opciok() as $r) {
                gzwrite($gz, self::sor_json($r));
            }

            gzwrite($gz, self::sor_json(['felhasznalok' => 1]));

            foreach (self::felhasznalok() as $id) {
                $u = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->users} WHERE ID = %d", $id), ARRAY_A);
                $m = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $id), ARRAY_N);

                if (is_array($u)) {
                    gzwrite($gz, self::sor_json(['u' => $u, 'm' => (array) $m]));
                }
            }
        } finally {
            gzclose($gz);
        }

        rename($tmp, $fajl);
        @chmod($fajl, 0600); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        // A régi pillanatkép törlése (egy marad).
        $regi = (string) (SDH_Demo::allapot()['pillanatkep']['fajl'] ?? '');

        if ($regi !== '' && $regi !== $fajl && is_file($regi) && strpos(realpath($regi) ?: '', realpath($mappa) ?: "\0") === 0) {
            unlink($regi);
        }

        $ki = [
            'fajl'   => $fajl,
            'meret'  => (int) filesize($fajl),
            'mp'     => round(microtime(true) - $kezd, 3),
            'tablak' => count($tablak),
            'sorok'  => $sorok,
            'ido'    => time(),
        ];

        SDH_Demo::allapot_ment(['pillanatkep' => $ki, 'maxok' => $maxok]);

        return $ki;
    }

    /* =================================================================
     * Visszaállítás
     * ============================================================== */

    /** @return array{mp: float, sorok: int, tablak: int, fiokok: int, fajlok: int} */
    public static function visszaallit(?string $fajl = null): array
    {
        global $wpdb;

        $fajl = $fajl ?? (string) (SDH_Demo::allapot()['pillanatkep']['fajl'] ?? '');

        if ($fajl === '' || !is_readable($fajl)) {
            throw new RuntimeException('Nincs pillanatkép – előbb készíts egyet (Eszközök › Bemutató).');
        }

        $kezd = microtime(true);
        @set_time_limit(600); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        ignore_user_abort(true);

        $pdo  = self::pdo();
        $ir   = $pdo !== null ? self::IR_SQLITE : self::IR_MYSQL;
        $e    = $wpdb->prefix . 'sdh_';
        $most = array_flip(self::tablak());
        $kesz = [];
        $stat = ['sorok' => 0, 'tablak' => 0];

        $munka = static function () use ($fajl, $pdo, $ir, $e, $most, &$kesz, &$stat, $wpdb): void {
            $gz = gzopen($fajl, 'rb');

            if ($gz === false) {
                throw new RuntimeException('A pillanatkép nem olvasható.');
            }

            $resz   = 'tabla';
            $tabla  = '';
            $oszlop = [];
            $hasznal = [];
            $puffer = [];
            $opciok = [];
            $felh   = [];

            $urit = static function () use (&$puffer, &$tabla, &$oszlop, &$hasznal): void {
                if ($puffer === [] || $tabla === '') {
                    $puffer = [];

                    return;
                }

                $nevek = array_map(static fn (int $i): string => self::azon($oszlop[$i]), $hasznal);
                self::fut('INSERT INTO ' . self::azon($tabla) . ' (' . implode(',', $nevek) . ') VALUES ' . implode(',', $puffer));
                $puffer = [];
            };

            try {
                while (($sor = gzgets($gz)) !== false) {
                    $sor = rtrim($sor, "\n");

                    if ($sor === '') {
                        continue;
                    }

                    $d = json_decode($sor, true);

                    if (!is_array($d)) {
                        continue;
                    }

                    if (isset($d['t'], $d['o'])) {
                        $urit();
                        $tabla = $e . (string) $d['t'];
                        $resz  = 'tabla';

                        if (!isset($most[$tabla])) {
                            $tabla = '';
                            continue;
                        }

                        $oszlop  = array_map('strval', (array) $d['o']);
                        $van     = array_flip(self::oszlopok($tabla));
                        $hasznal = array_keys(array_filter($oszlop, static fn (string $o): bool => isset($van[$o])));
                        self::fut('DELETE FROM ' . self::azon($tabla));
                        $kesz[$tabla] = true;
                        $stat['tablak']++;
                        continue;
                    }

                    if (isset($d['opciok'])) {
                        $urit();
                        $resz = 'opciok';
                        continue;
                    }

                    if (isset($d['felhasznalok'])) {
                        $urit();
                        $resz = 'felhasznalok';
                        continue;
                    }

                    if ($resz === 'tabla' && $tabla !== '') {
                        $puffer[] = '(' . implode(',', array_map(static fn (int $i) => self::ertek($d[$i] ?? null), $hasznal)) . ')';
                        $stat['sorok']++;

                        if (count($puffer) >= $ir) {
                            $urit();
                        }
                    } elseif ($resz === 'opciok' && isset($d[0])) {
                        $opciok[] = $d;
                    } elseif ($resz === 'felhasznalok' && isset($d['u'])) {
                        $felh[] = $d;
                    }
                }

                $urit();
            } finally {
                gzclose($gz);
            }

            // A pillanatkép óta létrejött táblák: üresen.
            foreach (array_keys($most) as $t) {
                if (!isset($kesz[$t])) {
                    self::fut('DELETE FROM ' . self::azon($t));
                }
            }

            // Opciók: a mostaniak helyett a pillanatkép szerintiek.
            // (LIKE-ban nincs escape: SQLite-on a \\_ nem működne – a pontos szűrés PHP-ban.)
            $jelen = array_map('strval', (array) $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sdh%' OR option_name LIKE '%transient%sdh%'"));
            $torol = array_values(array_filter($jelen, static fn (string $n): bool => self::opcio_mentendo($n)
                || strpos($n, '_transient_sdh_') === 0 || strpos($n, '_transient_timeout_sdh_') === 0));

            foreach (array_chunk($torol, 200) as $darab) {
                self::fut("DELETE FROM {$wpdb->options} WHERE option_name IN (" . implode(',', array_map([self::class, 'ertek'], $darab)) . ')');
            }

            foreach (array_chunk($opciok, 100) as $darab) {
                $ertekek = [];

                foreach ($darab as $o) {
                    if (self::opcio_mentendo((string) $o[0])) {
                        $ertekek[] = '(' . self::ertek($o[0]) . ',' . self::ertek($o[1]) . ',' . self::ertek($o[2] ?? 'off') . ')';
                    }
                }

                if ($ertekek !== []) {
                    self::fut("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode(',', $ertekek));
                }
            }

            // Az alapfelhasználók (a generált munkatársak) – sor és meta, ahogy a pillanatképben áll.
            foreach ($felh as $f) {
                $u  = (array) $f['u'];
                $id = (int) ($u['ID'] ?? 0);

                if ($id <= 0) {
                    continue;
                }

                $oszl = array_keys($u);
                self::fut("DELETE FROM {$wpdb->users} WHERE ID = " . $id);
                self::fut("INSERT INTO {$wpdb->users} (" . implode(',', array_map([self::class, 'azon'], $oszl)) . ') VALUES ('
                    . implode(',', array_map([self::class, 'ertek'], array_values($u))) . ')');
                self::fut("DELETE FROM {$wpdb->usermeta} WHERE user_id = " . $id);

                foreach (array_chunk((array) $f['m'], 100) as $darab) {
                    self::fut("INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES " . implode(',', array_map(
                        static fn (array $m): string => '(' . $id . ',' . self::ertek($m[0]) . ',' . self::ertek($m[1]) . ')',
                        $darab
                    )));
                }
            }

            // A látogatók nyomai: kimenő üzenetek, keret-számlálás (az indítások száma marad – az napi korlát).
            self::fut('DELETE FROM ' . self::azon(SDH_Demo::tabla('kimeno')));
            self::fut('DELETE FROM ' . self::azon(SDH_Demo::tabla('naplo')) . " WHERE tipus IN ('rekord', 'iras')");
        };

        $csendes = static function () use ($munka): void {
            class_exists('SDH_Muhely_Valtozasnaplo') ? SDH_Muhely_Valtozasnaplo::csendben($munka) : $munka();
        };

        SDH_Demo_Keret::csendben(static function () use ($pdo, $csendes): void {
            if ($pdo !== null) {
                $pdo->beginTransaction();

                try {
                    $csendes();
                    $pdo->commit();
                } catch (Throwable $h) {
                    $pdo->rollBack();
                    throw $h;
                }

                return;
            }

            self::fut('START TRANSACTION');

            try {
                $csendes();
                self::fut('COMMIT');
            } catch (Throwable $h) {
                self::fut('ROLLBACK');
                throw $h;
            }
        });

        wp_cache_flush();

        // Látogatói fiókok (a közös demo kivételével), a közös fiók a beállítás szerint, látogatói fájlok, gyorsítótár.
        $fiokok = SDH_Demo_Inditas::takarit(true);
        SDH_Demo_Inditas::demo_demo_szinkron();
        $fajlok = self::arva_fajlok();

        if (class_exists('SDH_Muhely_Gyorsitotar')) {
            SDH_Muhely_Gyorsitotar::ervenytelenit();
        }

        if (class_exists('SDH_Muhely_Modulok')) {
            SDH_Muhely_Modulok::kapcsolok_urit();
        }

        $ki = [
            'mp'     => round(microtime(true) - $kezd, 3),
            'sorok'  => (int) $stat['sorok'],
            'tablak' => (int) $stat['tablak'],
            'fiokok' => $fiokok,
            'fajlok' => $fajlok,
            'ido'    => time(),
        ];

        SDH_Demo::allapot_ment(['visszaallitas' => $ki]);

        return $ki;
    }

    /**
     * A műhely csatolmány-mappájában (uploads/<…>/csatolmany) azok a fájlok, amelyekre már nem mutat sor (a látogatók
     * feltöltései a visszaállítás után). A mappa útját a csatolmány-tábla egy soráról kérdezzük – a műhely
     * fájlnév-ellenőrzésével (32 hexa).
     */
    public static function arva_fajlok(): int
    {
        global $wpdb;

        if (!class_exists('SDH_Muhely_Csatolmany')) {
            return 0;
        }

        $mappa = trailingslashit(wp_upload_dir()['basedir']) . 'sdh-muhely/csatolmany';

        if (!is_dir($mappa)) {
            return 0;
        }

        $tarolt = array_flip(array_map('strval', (array) $wpdb->get_col('SELECT tarolt_nev FROM ' . SDH_Muhely_Schema::tabla('csatolmany'))));
        $db     = 0;

        foreach ((array) scandir($mappa) as $nev) {
            $nev = (string) $nev;

            if (preg_match('/^[a-f0-9]{32}$/', $nev) === 1 && !isset($tarolt[$nev]) && is_file($mappa . '/' . $nev)) {
                $db += unlink($mappa . '/' . $nev) ? 1 : 0;
            }
        }

        return $db;
    }
}
