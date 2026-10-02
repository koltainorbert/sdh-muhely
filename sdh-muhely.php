<?php
/**
 * Plugin Name:       SDH Műhely
 * Plugin URI:        https://sdh.hu
 * Description:       Belső műhely- és ügyfélkezelő rendszer (CRM + munkalap) az SDH Szerviz számára. A MunkaLap desktop program webes utódja.
 * Version:           0.9.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            InfoStudio Hungary – Koltai Norbert
 * Author URI:        https://infostudio.hu
 * Text Domain:       sdh-muhely
 * Domain Path:       /languages
 *
 * -----------------------------------------------------------------
 *  Ez a fájl csak összefog: betölti a modulokat, kezeli az aktiválást.
 *  Minden tényleges működés az includes/ mappában lakik, modulonként
 *  egy osztályban. Új modul = új fájl + egy sor az alábbi listában.
 * -----------------------------------------------------------------
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit; // Közvetlen hívás tiltva.
}

/* =====================================================================
 * Alapértékek – egy helyen, hogy sehol ne kelljen útvonalat kézzel írni
 * ================================================================== */

define('SDH_MUHELY_VERSION', '0.9.0');
define('SDH_MUHELY_FILE', __FILE__);
define('SDH_MUHELY_DIR', plugin_dir_path(__FILE__));   // .../wp-content/plugins/sdh-muhely/
define('SDH_MUHELY_URL', plugin_dir_url(__FILE__));
define('SDH_MUHELY_BASENAME', plugin_basename(__FILE__));

/* =====================================================================
 * Modulok betöltése
 *
 * A sorrend számít: ami másra épül, az lentebb legyen. A séma mindig
 * első, mert minden modul a tábláira hivatkozik.
 * ================================================================== */

$sdh_muhely_modulok = [
    'includes/class-sdh-muhely-schema.php'   => 'SDH_Muhely_Schema',
    'includes/class-sdh-muhely-modulok.php'  => 'SDH_Muhely_Modulok',
    'includes/class-sdh-muhely-admin-ui.php' => 'SDH_Muhely_Admin_UI',
    'includes/class-sdh-muhely-frontend.php' => 'SDH_Muhely_Frontend',
    'includes/class-sdh-muhely-ugyfel.php'   => 'SDH_Muhely_Ugyfel',
    'includes/class-sdh-muhely-eszkoz.php'   => 'SDH_Muhely_Eszkoz',
    'includes/class-sdh-muhely-tac.php'      => 'SDH_Muhely_Tac',
];

foreach ($sdh_muhely_modulok as $sdh_muhely_fajl => $sdh_muhely_osztaly) {
    $sdh_muhely_ut = SDH_MUHELY_DIR . $sdh_muhely_fajl;

    if (!is_readable($sdh_muhely_ut)) {
        continue;
    }

    require_once $sdh_muhely_ut;

    if (method_exists($sdh_muhely_osztaly, 'init')) {
        $sdh_muhely_osztaly::init();
    }
}

unset($sdh_muhely_fajl, $sdh_muhely_osztaly, $sdh_muhely_ut);

/* =====================================================================
 * Aktiválás / deaktiválás
 * ================================================================== */

register_activation_hook(__FILE__, 'sdh_muhely_aktivalas');
register_deactivation_hook(__FILE__, 'sdh_muhely_deaktivalas');

function sdh_muhely_aktivalas(): void
{
    add_option('sdh_muhely_telepitve', current_time('mysql'));
    update_option('sdh_muhely_version', SDH_MUHELY_VERSION);

    // A táblák létrehozása. Idempotens: újraaktiválás nem veszít adatot.
    if (class_exists('SDH_Muhely_Schema')) {
        SDH_Muhely_Schema::telepit();
    }

    // A későbbi saját oldalakhoz (munkalap-nézet stb.) kelleni fog.
    flush_rewrite_rules();
}

function sdh_muhely_deaktivalas(): void
{
    flush_rewrite_rules();
}

/* =====================================================================
 * Fordítások
 * ================================================================== */

add_action('init', static function (): void {
    load_plugin_textdomain('sdh-muhely', false, dirname(SDH_MUHELY_BASENAME) . '/languages');
});
