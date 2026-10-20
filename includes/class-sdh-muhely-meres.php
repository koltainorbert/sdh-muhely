<?php
/**
 * Adatbázis-mérés CSAK OLVASVA (H10, 0.90.0; a `tools/mero-meres.php` 0.64.0 óta).
 *
 * A meglévő adatokon lefuttatja a gyakori lekérdezéseket (ügyfélkereső, rács, kezdőképernyő,
 * IMEI, riport, raktár, elégedettség), mindegyikre megmutatja az EXPLAIN-t (teljes táblaolvasás:
 * type=ALL, rendezés: filesort) és az időt (5 futás mediánja), és kiírja a MySQL / MariaDB fontos
 * beállításait. Semmit nem ír: a futás idejére egy szűrő minden író lekérdezést `SELECT 1`-re cserél.
 *
 * Indítás: Beállítások › Rendszer › Sebesség › „Mérés indítása” (csak fejlesztőnek), vagy parancssorból
 * a `tools/mero-meres.php`.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Meres
{
    /** Ennyi ms fölött „lassú” a jelzés. */
    public const LASSU_MS = 300.0;

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_meres', [self::class, 'ajax']);
    }

    /** Az író lekérdezések tiltása a mérés idejére. */
    public static function iras_tilt($q)
    {
        if (is_string($q) && preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $q) === 1) {
            return 'SELECT 1';
        }

        return $q;
    }

    private static function sqlite(): bool
    {
        global $wpdb;

        return class_exists('WP_SQLite_DB', false) && $wpdb instanceof WP_SQLite_DB;
    }

    /**
     * A mérés. Soronként a kimenet; `$lassu`-ba a 300 ms-nál lassabb lépések neve kerül.
     *
     * @param array<int, string> $lassu
     * @return array<int, string>
     */
    public static function futtat(array &$lassu = []): array
    {
        global $wpdb;

        add_filter('query', [self::class, 'iras_tilt'], PHP_INT_MAX);

        $ki     = [];
        $sqlite = self::sqlite();
        $T      = static fn (string $n): string => SDH_Muhely_Schema::tabla($n);

        $ki[] = SDH_Muhely_Arculat::termeknev() . ' mérés (csak olvas) – verzió ' . SDH_MUHELY_VERSION . ', séma ' . get_option('sdh_muhely_db_version');
        $ki[] = str_repeat('=', 90);

        if (!$sqlite) {
            $v    = $wpdb->get_row("SHOW VARIABLES LIKE 'version'");
            $b    = $wpdb->get_row("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
            $ki[] = 'Adatbázis: ' . ($v->Value ?? '?') . ', innodb_buffer_pool_size: ' . (isset($b->Value) ? round((int) $b->Value / 1048576) . ' MB' : '?');
        } else {
            $ki[] = 'Adatbázis: SQLite (tesztkörnyezet) – az EXPLAIN alakja más.';
        }

        $op   = function_exists('opcache_get_status') ? @opcache_get_status(false) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $ki[] = 'PHP ' . PHP_VERSION . ', OPcache: ' . (is_array($op) && !empty($op['opcache_enabled']) ? 'BE' : 'ki');

        foreach (['ugyfel', 'ugyfel_szo', 'eszkoz', 'munkalap', 'munkalap_tetel', 'penztar', 'uzenet'] as $t) {
            $ki[] = sprintf('  %-16s %10s sor', $t, number_format((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $T($t)), 0, ',', ' '));
        }

        $ki[] = str_repeat('=', 90);

        $meres = static function (string $nev, string $sql) use ($wpdb, $sqlite, &$ki, &$lassu): void {
            $idok = [];
            $db   = 0;

            for ($i = 0; $i < 5; $i++) {
                $t0     = microtime(true);
                $sorok  = $wpdb->get_results($sql, ARRAY_N);
                $idok[] = (microtime(true) - $t0) * 1000;
                $db     = is_array($sorok) ? count($sorok) : 0;
            }

            sort($idok);
            $jel = [];

            try {
                $terv = $sqlite
                    ? (array) $wpdb->dbh->get_pdo()->query('EXPLAIN QUERY PLAN ' . $wpdb->remove_placeholder_escape($sql))->fetchAll(PDO::FETCH_ASSOC)
                    : (array) $wpdb->get_results('EXPLAIN ' . $sql, ARRAY_A);
            } catch (Throwable $e) {
                $terv = [];
            }

            foreach ($terv as $e) {
                $jel[] = $sqlite
                    ? (string) ($e['detail'] ?? '')
                    : sprintf('%s:%s key=%s rows=%s %s', $e['table'] ?? '?', $e['type'] ?? '?', $e['key'] ?? '-', $e['rows'] ?? '?', $e['Extra'] ?? '');
            }

            $gond = preg_grep('/:ALL |filesort|SCAN (?!.*USING)|TEMP B-TREE/i', $jel) ? '  ← TELJES OLVASÁS / RENDEZÉS' : '';
            $ki[] = sprintf('%-46s %8.1f ms (min %.1f)  %d sor%s', $nev, $idok[2], $idok[0], $db, $gond);

            if ($idok[2] > self::LASSU_MS) {
                $lassu[] = trim($nev);
            }

            foreach ($jel as $j) {
                $ki[] = '      ' . substr($j, 0, 150);
            }
        };

        $php = static function (string $nev, callable $f) use (&$ki, &$lassu): void {
            $idok = [];

            for ($i = 0; $i < 3; $i++) {
                $t0 = microtime(true);
                $f();
                $idok[] = (microtime(true) - $t0) * 1000;
            }

            sort($idok);
            $ki[] = sprintf('%-46s %8.1f ms (min %.1f)', $nev, $idok[1], $idok[0]);

            if ($idok[1] > self::LASSU_MS) {
                $lassu[] = trim($nev);
            }
        };

        $ut = $T('ugyfel');
        $mt = $T('munkalap');
        $et = $T('eszkoz');

        $minta_nev = (string) $wpdb->get_var("SELECT nev FROM {$ut} WHERE aktiv = 1 AND nev <> '' ORDER BY id DESC LIMIT 1");
        $elotag    = SDH_Muhely_Ugyfel::kereso_norm(mb_substr($minta_nev, 0, 3));
        $tel       = (string) preg_replace('/\D+/', '', (string) $wpdb->get_var("SELECT telefon FROM {$ut} WHERE telefon <> '' ORDER BY id DESC LIMIT 1"));
        $tel_veg   = substr($tel, -6);

        $ki[] = "Ügyfélkereső (minta: „{$elotag}”, telefon vége „{$tel_veg}”)";
        $meres('  név eleje (kereso, index)', $wpdb->prepare("SELECT id FROM {$ut} WHERE aktiv = 1 AND kereso LIKE %s ORDER BY kereso LIMIT 10", $wpdb->esc_like($elotag) . '%'));
        $meres('  telefon vége (tel_ford, index)', $wpdb->prepare("SELECT id FROM {$ut} WHERE aktiv = 1 AND tel_ford LIKE %s LIMIT 10", $wpdb->esc_like(strrev($tel_veg)) . '%'));
        $meres('  szó eleje (szó-index)', $wpdb->prepare('SELECT s.ugyfel_id FROM ' . $T('ugyfel_szo') . " s INNER JOIN {$ut} u ON u.id = s.ugyfel_id AND u.aktiv = 1 WHERE s.szo LIKE %s LIMIT 40", $wpdb->esc_like($elotag) . '%'));
        $meres('  tartalmazza (csak ha nincs más találat)', $wpdb->prepare("SELECT id FROM {$ut} WHERE aktiv = 1 AND kereso LIKE %s ORDER BY kereso LIMIT 10", '%' . $wpdb->esc_like('qxzw') . '%'));

        $ki[] = '';
        $ki[] = 'Áttekintés (rács)';
        $meres('  1. oldal azonosítói (alapnézet)', "SELECT m.id FROM {$mt} m WHERE COALESCE(m.fajta, '') IN ('javitas', 'arukiadas') ORDER BY m.munkalap_szam DESC, m.id DESC LIMIT 50");
        $meres('  találatszám + összegsor (egyszer, utána tárolt)', "SELECT COUNT(*), SUM(m.brutto_ertek), SUM(m.fizetett) FROM {$mt} m WHERE COALESCE(m.fajta, '') IN ('javitas', 'arukiadas')");
        $meres('  szűrő: állapot', "SELECT m.id FROM {$mt} m WHERE COALESCE(m.fajta, '') IN ('javitas', 'arukiadas') AND m.allapot IN ('nyitott') ORDER BY m.munkalap_szam DESC, m.id DESC LIMIT 50");
        $meres('  rendezés: készült (azonosítók)', "SELECT m.id FROM {$mt} m WHERE COALESCE(m.fajta, '') IN ('javitas', 'arukiadas') ORDER BY m.keszult DESC, m.id DESC LIMIT 50");
        $meres('  rendezés ügyfél: értékek indexről (köteg)', "SELECT nev FROM {$ut} WHERE nev > '' ORDER BY nev ASC LIMIT 200");
        $meres('  kereső (ügyfél allekérdezésben)', $wpdb->prepare("SELECT m.id FROM {$mt} m WHERE COALESCE(m.fajta, '') IN ('javitas', 'arukiadas') AND (COALESCE(m.megjegyzes, '') LIKE %s OR m.ugyfel_id IN (SELECT ku.id FROM {$ut} ku WHERE ku.nev LIKE %s OR ku.telefon LIKE %s)) ORDER BY m.munkalap_szam DESC, m.id DESC LIMIT 50", '%' . $elotag . '%', '%' . $elotag . '%', '%' . $elotag . '%'));

        $ki[] = '';
        $ki[] = 'Kezdőképernyő számai (első számolás – utána tárolt)';
        $meres('  nyitott munkalapok', "SELECT COUNT(*) FROM {$mt} WHERE fajta = 'javitas' AND munkalap_szam IS NOT NULL AND allapot IN ('bejelentett', 'fuggo', 'nyitott', 'elkeszult', 'reszben')");
        $meres('  aktív ügyfelek', "SELECT COUNT(*) FROM {$ut} WHERE aktiv = 1");
        $meres('  eszközkategóriák', "SELECT DISTINCT kategoria FROM {$et} ORDER BY kategoria ASC LIMIT 60");

        $ki[] = '';
        $ki[] = 'Egyéb';
        $imei = (string) $wpdb->get_var("SELECT imei FROM {$et} WHERE imei <> '' ORDER BY id DESC LIMIT 1");
        $meres('  IMEI-kikeresés (imei / imei2 index)', $wpdb->prepare("SELECT e.id FROM {$et} e WHERE (e.imei = %s OR e.imei2 = %s) ORDER BY e.modositva DESC LIMIT 1", $imei, $imei));

        $ma  = current_time('Y-m-d');
        $ev  = gmdate('Y-m-d', strtotime($ma . ' -1 year'));
        $ki[] = '';
        $ki[] = 'Új funkciók (F1–F7) – a teljes feldolgozás ideje';
        $php('  Riportok: utolsó egy év', static fn () => SDH_Muhely_Riport::adatok($ev, $ma));
        $php('  Riportok: 12 hónap sávjai', static fn () => SDH_Muhely_Riport::honapok($ma));
        $php('  Raktár: foglalások nyitott lapokon', static fn () => SDH_Muhely_Raktar::foglalasok());
        $php('  Raktár: rendelési javaslat', static fn () => SDH_Muhely_Raktar::javaslat());
        $php('  Elégedettség: esedékes lapok', static fn () => SDH_Muhely_Elegedettseg::esedekesek());
        $php('  Időpontfoglalás: szabad sávok', static fn () => SDH_Muhely_Foglalas::szabad());

        $ki[] = str_repeat('=', 90);
        $ki[] = $lassu === [] ? 'Kész. Minden lépés ' . (int) self::LASSU_MS . ' ms alatt. Semmi nem íródott az adatbázisba.' : 'Kész. LASSÚ (' . (int) self::LASSU_MS . ' ms fölött): ' . implode(', ', $lassu) . '. Semmi nem íródott az adatbázisba.';

        remove_filter('query', [self::class, 'iras_tilt'], PHP_INT_MAX);

        return $ki;
    }

    public static function ajax(): void
    {
        check_ajax_referer('sdh_muhely_meres', '_wpnonce');

        if (!SDH_Muhely_Admin_UI::fejleszto_e()) {
            wp_send_json_error(['uzenet' => 'Csak fejlesztőnek.'], 403);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        $lassu = [];
        $sorok = self::futtat($lassu);

        wp_send_json_success(['szoveg' => implode("\n", $sorok), 'lassu' => $lassu]);
    }

    /** A Sebesség lapfül doboza (csak fejlesztőnek). */
    public static function doboz(): void
    {
        if (!SDH_Muhely_Admin_UI::fejleszto_e()) {
            return;
        }

        ?>
        <div class="sdh-doboz sdh-meres" data-sdh-meres data-nonce="<?php echo esc_attr(wp_create_nonce('sdh_muhely_meres')); ?>">
            <h2 class="sdh-doboz__cim">Adatbázis-mérés (csak olvas) [fejlesztő]</h2>
            <p class="sdh-sugo">
                A gyakori lekérdezéseket és az új funkciók számolásait futtatja a meglévő adatokon, és kiírja az időket,
                a MySQL beállításait és azt, hol olvas végig egy egész táblát. Semmit nem ír az adatbázisba.
                Nagy adatbázisnál fél percig is eltarthat.
            </p>
            <p><button type="button" class="sdh-gomb" data-sdh-meres-indit>Mérés indítása</button>
                <span class="sdh-mezo__sugo" data-sdh-meres-allapot role="status"></span></p>
            <pre class="sdh-meres__ki" data-sdh-meres-ki hidden></pre>
        </div>
        <?php
    }
}
