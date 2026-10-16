<?php
/**
 * SDH Műhely – adatbázis-mérés CSAK OLVASVA (0.64.0; 0.90.0 óta az SDH_Muhely_Meres osztályt hívja).
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

// 0.90.0: a mérés az SDH_Muhely_Meres osztályban van (a Beállítások › Sebesség lapfülről is indítható).
$lassu = [];
echo implode("\n", SDH_Muhely_Meres::futtat($lassu)) . "\n";
exit($lassu === [] ? 0 : 1);
