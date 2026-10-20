<?php
/**
 * Plugin Name:       SDH Értékesítés
 * Description:       Értékesítő webhely: csomagok és modulok, kosár, fizetés (átutalás + QR, kártya), automatikus licenc-kiszolgálás a licencközponton át, számla, időpontfoglalás, GYIK, tudásbázis, AI-asszisztens, szerkeszthető tartalom.
 * Version:           1.0.1
 * Requires PHP:      8.1
 * Requires at least: 6.4
 * Text Domain:       sdh-sales
 * Domain Path:       /languages
 *
 * Betöltés: az `includes/class-sdh-sales-*.php` fájlok mind betöltődnek (a mag előre), és minden osztály `init()`-je fut,
 * ha van. Új modul = új fájl, a betöltőhöz nem kell nyúlni. Szerződés: weboldal/ARCHITEKTURA.md.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SDH_SALES_VERSION', '1.0.1');
define('SDH_SALES_FILE', __FILE__);
define('SDH_SALES_DIR', plugin_dir_path(__FILE__));
define('SDH_SALES_URL', plugin_dir_url(__FILE__));

/** A mag: ezek töltődnek elsőként, ebben a sorrendben. */
$sdh_sales_mag = ['', '-schema', '-naplo', '-rest'];

// A fájlnév előtagja a fejlesztői tárolóban `class-sdh-sales`, a semleges csomagban `class-p` (eszkozok/csomagol.sh).
$sdh_sales_elotag = is_file(SDH_SALES_DIR . 'includes/class-p.php') ? 'class-p' : 'class-sdh-sales';

foreach ($sdh_sales_mag as $sdh_sales_v) {
    require_once SDH_SALES_DIR . 'includes/' . $sdh_sales_elotag . $sdh_sales_v . '.php';
}

$sdh_sales_osztalyok = ['SDH_Sales', 'SDH_Sales_Schema', 'SDH_Sales_Naplo', 'SDH_Sales_Rest'];

foreach (glob(SDH_SALES_DIR . 'includes/' . $sdh_sales_elotag . '-*.php') ?: [] as $sdh_sales_f) {
    $sdh_sales_n = substr(basename($sdh_sales_f, '.php'), strlen($sdh_sales_elotag . '-'));

    if (in_array('-' . $sdh_sales_n, $sdh_sales_mag, true)) {
        continue;
    }

    require_once $sdh_sales_f;
    $sdh_sales_osztalyok[] = 'SDH_Sales_' . implode('_', array_map('ucfirst', explode('-', $sdh_sales_n)));
}

foreach ($sdh_sales_osztalyok as $sdh_sales_o) {
    if (class_exists($sdh_sales_o) && method_exists($sdh_sales_o, 'init')) {
        $sdh_sales_o::init();
    }
}

unset($sdh_sales_elotag, $sdh_sales_mag, $sdh_sales_v, $sdh_sales_f, $sdh_sales_n, $sdh_sales_o, $sdh_sales_osztalyok);

register_activation_hook(__FILE__, ['SDH_Sales', 'aktivalas']);
register_deactivation_hook(__FILE__, ['SDH_Sales', 'kikapcsolas']);
