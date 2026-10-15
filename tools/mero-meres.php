<?php
/**
 * SDH Műhely – adatbázis-mérés CSAK OLVASVA (0.64.0).
 *
 * Mit csinál: a meglévő adatokon lefuttatja a gyakori lekérdezéseket (ügyfélkereső,
 * rács, kezdőképernyő, munkalap-űrlap, IMEI), mindegyikre megmutatja az EXPLAIN-t
 * (teljes táblaolvasás: type=ALL, rendezés: filesort) és az időt (5 futás mediánja).
 * Kiírja a MySQL / MariaDB fontos beállításait is (innodb_buffer_pool_size, verzió).
 *
 * Mit NEM csinál: semmit nem ír – se adatot, se beállítást, se gyorsítótárat. Csak
 * SELECT, EXPLAIN és SHOW fut. Tesztadatot nem hoz létre.
 *
 *   php tools/mero-meres.php                          # a plugin mappájából, LocalWP „Open site shell"
 *   SDH_WP_LOAD=/út/wp-load.php php tools/mero-meres.php
 *
 * A telepítő zipbe nem kerül (.gitattributes).
 */

if (PHP_SAPI !== 'cli') {
    exit("Csak parancssorból futtatható.\n");
}

$wp_load = getenv('SDH_WP_LOAD') ?: dirname(__DIR__, 4) . '/wp-load.php';

if (!is_file($wp_load)) {
    fwrite(STDERR, "Nem találom a wp-load.php fájlt. Add meg: SDH_WP_LOAD=/út/wp-load.php\n");
    exit(2);
}

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require $wp_load;

if (!class_exists('SDH_Muhely_Schema')) {
    fwrite(STDERR, "Az SDH Műhely plugin nincs bekapcsolva.\n");
    exit(2);
}

global $wpdb;

// Biztosíték: ebben a futásban semmilyen író lekérdezés nem mehet ki.
add_filter('query', static function ($q) {
    if (is_string($q) && preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $q) === 1) {
        fwrite(STDERR, "ÍRÁS MEGAKADÁLYOZVA: " . substr($q, 0, 120) . "\n");

        return 'SELECT 1';
    }

    return $q;
}, PHP_INT_MAX);

$sqlite = class_exists('WP_SQLite_DB', false) && $wpdb instanceof WP_SQLite_DB;
$T      = static fn (string $n): string => SDH_Muhely_Schema::tabla($n);

echo "SDH Műhely mérés (csak olvas) – plugin " . SDH_MUHELY_VERSION . ', séma ' . get_option('sdh_muhely_db_version') . "\n";
echo str_repeat('=', 100) . "\n";

if (!$sqlite) {
    $v = $wpdb->get_row("SHOW VARIABLES LIKE 'version'");
    $b = $wpdb->get_row("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
    echo 'Adatbázis: ' . ($v->Value ?? '?') . ', innodb_buffer_pool_size: ' . (isset($b->Value) ? round((int) $b->Value / 1048576) . ' MB' : '?') . "\n";
} else {
    echo "Adatbázis: SQLite (tesztkörnyezet) – az EXPLAIN alakja más.\n";
}

echo 'PHP ' . PHP_VERSION . ', OPcache (ebben a parancssori futásban): ' . (function_exists('opcache_get_status') && @opcache_get_status(false) ? 'BE' : 'ki – a webszerver PHP-ját a LocalWP-ben külön nézd, lásd a súgó Sebesség részét') . "\n";

foreach (['ugyfel', 'ugyfel_szo', 'eszkoz', 'munkalap', 'munkalap_tetel', 'penztar', 'uzenet'] as $t) {
    printf("  %-16s %10s sor\n", $t, number_format((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $T($t)), 0, ',', ' '));
}

echo str_repeat('=', 100) . "\n";

/** Egy lekérdezés: EXPLAIN és idő. */
$meres = static function (string $nev, string $sql) use ($wpdb, $sqlite): void {
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

    // SQLite-on (tesztkörnyezet) a WordPress fordítója nem ismeri az EXPLAIN-t: közvetlenül kérdezzük.
    $terv = $sqlite
        ? (array) $wpdb->dbh->get_pdo()->query('EXPLAIN QUERY PLAN ' . $wpdb->remove_placeholder_escape($sql))->fetchAll(PDO::FETCH_ASSOC)
        : (array) $wpdb->get_results('EXPLAIN ' . $sql, ARRAY_A);

    foreach ($terv as $e) {
        if ($sqlite) {
            $jel[] = (string) ($e['detail'] ?? '');
            continue;
        }

        $jel[] = sprintf('%s:%s key=%s rows=%s %s', $e['table'] ?? '?', $e['type'] ?? '?', $e['key'] ?? '-', $e['rows'] ?? '?', $e['Extra'] ?? '');
    }

    $gond = preg_grep('/:ALL |filesort|SCAN (?!.*USING)|TEMP B-TREE/i', $jel) ? '  ← TELJES OLVASÁS / RENDEZÉS' : '';
    printf("%-46s %8.1f ms (min %.1f)  %d sor%s\n", $nev, $idok[2], $idok[0], $db, $gond);

    foreach ($jel as $j) {
        echo '      ' . substr($j, 0, 150) . "\n";
    }
};

$ut = $T('ugyfel');
$mt = $T('munkalap');
$et = $T('eszkoz');

// Egy valódi név eleje és egy telefonszám vége a meglévő adatokból (csak olvasva).
$minta_nev = (string) $wpdb->get_var("SELECT nev FROM {$ut} WHERE aktiv = 1 AND nev <> '' ORDER BY id DESC LIMIT 1");
$elotag    = SDH_Muhely_Ugyfel::kereso_norm(mb_substr($minta_nev, 0, 3));
$tel       = (string) preg_replace('/\D+/', '', (string) $wpdb->get_var("SELECT telefon FROM {$ut} WHERE telefon <> '' ORDER BY id DESC LIMIT 1"));
$tel_veg   = substr($tel, -6);

echo "Ügyfélkereső (minta: „{$elotag}”, telefon vége „{$tel_veg}”)\n";
$meres('  név eleje (kereso, index)', $wpdb->prepare("SELECT id FROM {$ut} WHERE aktiv = 1 AND kereso LIKE %s ORDER BY kereso LIMIT 10", $wpdb->esc_like($elotag) . '%'));
$meres('  telefon vége (tel_ford, index)', $wpdb->prepare("SELECT id FROM {$ut} WHERE aktiv = 1 AND tel_ford LIKE %s LIMIT 10", $wpdb->esc_like(strrev($tel_veg)) . '%'));
$meres('  szó eleje (szó-index)', $wpdb->prepare('SELECT s.ugyfel_id FROM ' . $T('ugyfel_szo') . " s INNER JOIN {$ut} u ON u.id = s.ugyfel_id AND u.aktiv = 1 WHERE s.szo LIKE %s LIMIT 40", $wpdb->esc_like($elotag) . '%'));
$meres('  tartalmazza (csak ha nincs más találat)', $wpdb->prepare("SELECT id FROM {$ut} WHERE aktiv = 1 AND kereso LIKE %s ORDER BY kereso LIMIT 10", '%' . $wpdb->esc_like('qxzw') . '%'));
$meres('  RÉGI út (0.63: 6 oszlop, %…%)', $wpdb->prepare("SELECT id FROM {$ut} WHERE aktiv = 1 AND (nev LIKE %s OR telefon LIKE %s OR telefon2 LIKE %s OR email LIKE %s OR ugyfel_szam LIKE %s OR szamlazasi_cim LIKE %s) ORDER BY nev LIMIT 10", ...array_fill(0, 6, '%qxzw%')));

echo "\nÁttekintés (rács)\n";
$meres('  1. oldal azonosítói (alapnézet)', "SELECT m.id FROM {$mt} m WHERE COALESCE(m.fajta, '') IN ('javitas', 'arukiadas') ORDER BY m.munkalap_szam DESC, m.id DESC LIMIT 50");
$meres('  találatszám + összegsor (egyszer, utána tárolt)', "SELECT COUNT(*), SUM(m.brutto_ertek), SUM(m.fizetett) FROM {$mt} m WHERE COALESCE(m.fajta, '') IN ('javitas', 'arukiadas')");
$meres('  szűrő: állapot', "SELECT m.id FROM {$mt} m WHERE COALESCE(m.fajta, '') IN ('javitas', 'arukiadas') AND m.allapot IN ('nyitott') ORDER BY m.munkalap_szam DESC, m.id DESC LIMIT 50");
$meres('  rendezés: készült (azonosítók)', "SELECT m.id FROM {$mt} m WHERE COALESCE(m.fajta, '') IN ('javitas', 'arukiadas') ORDER BY m.keszult DESC, m.id DESC LIMIT 50");
$meres('  rendezés ügyfél: értékek indexről (köteg)', "SELECT nev FROM {$ut} WHERE nev > '' ORDER BY nev ASC LIMIT 200");
$meres('  RÉGI rendezés ügyfél (teljes ORDER BY)', "SELECT m.id FROM {$mt} m LEFT JOIN {$ut} u ON u.id = m.ugyfel_id WHERE m.fajta IN ('javitas', 'arukiadas') ORDER BY COALESCE(u.nev, '') ASC, m.id DESC LIMIT 50");
$meres('  kereső (ügyfél allekérdezésben)', $wpdb->prepare("SELECT m.id FROM {$mt} m WHERE COALESCE(m.fajta, '') IN ('javitas', 'arukiadas') AND (COALESCE(m.megjegyzes, '') LIKE %s OR m.ugyfel_id IN (SELECT ku.id FROM {$ut} ku WHERE ku.nev LIKE %s OR ku.telefon LIKE %s)) ORDER BY m.munkalap_szam DESC, m.id DESC LIMIT 50", '%' . $elotag . '%', '%' . $elotag . '%', '%' . $elotag . '%'));

echo "\nKezdőképernyő számai (első számolás – utána tárolt)\n";
$meres('  nyitott munkalapok', "SELECT COUNT(*) FROM {$mt} WHERE fajta = 'javitas' AND munkalap_szam IS NOT NULL AND allapot IN ('bejelentett', 'fuggo', 'nyitott', 'elkeszult', 'reszben')");
$meres('  aktív ügyfelek', "SELECT COUNT(*) FROM {$ut} WHERE aktiv = 1");
$meres('  megérkezett rendelések', "SELECT COUNT(*) FROM {$mt} WHERE fajta = 'rendeles' AND rend_allapot = 'megerkezett'");
$meres('  eszközkategóriák', "SELECT DISTINCT kategoria FROM {$et} ORDER BY kategoria ASC LIMIT 60");

echo "\nEgyéb\n";
$imei = (string) $wpdb->get_var("SELECT imei FROM {$et} WHERE imei <> '' ORDER BY id DESC LIMIT 1");
$meres('  IMEI-kikeresés (imei / imei2 index)', $wpdb->prepare("SELECT e.id FROM {$et} e WHERE (e.imei = %s OR e.imei2 = %s) ORDER BY e.modositva DESC LIMIT 1", $imei, $imei));

echo str_repeat('=', 100) . "\nKész. Semmi nem íródott az adatbázisba.\n";
