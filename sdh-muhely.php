<?php
/**
 * Plugin Name:       SDH Műhely
 * Plugin URI:        https://sdh.hu
 * Description:        Belső műhely- és ügyfélkezelő rendszer (CRM + munkalap) az SDH Szerviz számára. A MunkaLap desktop program webes utódja.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            InfoStudio Hungary – Koltai Norbert
 * Author URI:        https://infostudio.hu
 * Text Domain:       sdh-muhely
 * Domain Path:       /languages
 *
 * -----------------------------------------------------------------
 *  Ez a plugin a belső rendszer váza. Egyelőre egyetlen dolgot tud:
 *  életjelet ad egy admin nyitóképernyőn, és megmutatja, hogy a
 *  környezet (Local + Git) rendben felállt. A tényleges modulok
 *  (ügyfél, eszköz, munkalap, hiba, tétel, raktár…) innen épülnek rá.
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

define('SDH_MUHELY_VERSION', '0.1.0');
define('SDH_MUHELY_FILE', __FILE__);
define('SDH_MUHELY_DIR', plugin_dir_path(__FILE__));   // .../wp-content/plugins/sdh-muhely/
define('SDH_MUHELY_URL', plugin_dir_url(__FILE__));
define('SDH_MUHELY_BASENAME', plugin_basename(__FILE__));

/* =====================================================================
 * Modulok betöltése
 *
 * Minden új modul kap egy fájlt az includes/ mappában, és ide egy sort.
 * A sorrend számít: ami másra épül, az lentebb legyen.
 * ================================================================== */

$sdh_muhely_modulok = [
    // 'includes/class-sdh-muhely-schema.php',   // adatbázis-táblák
    // 'includes/class-sdh-muhely-admin-ui.php',  // közös admin arculat
    // 'includes/class-sdh-muhely-ugyfel.php',    // ügyfelek
    // 'includes/class-sdh-muhely-munkalap.php',  // munkalapok
];

foreach ($sdh_muhely_modulok as $sdh_muhely_fajl) {
    $sdh_muhely_ut = SDH_MUHELY_DIR . $sdh_muhely_fajl;
    if (is_readable($sdh_muhely_ut)) {
        require_once $sdh_muhely_ut;
    }
}

/* =====================================================================
 * Aktiválás / deaktiválás
 *
 * Aktiváláskor eltesszük a verziót, hogy később a frissítéseket
 * (pl. új adatbázis-tábla) ehhez tudjuk igazítani.
 * ================================================================== */

register_activation_hook(__FILE__, 'sdh_muhely_aktivalas');
register_deactivation_hook(__FILE__, 'sdh_muhely_deaktivalas');

function sdh_muhely_aktivalas(): void
{
    add_option('sdh_muhely_version', SDH_MUHELY_VERSION);
    add_option('sdh_muhely_telepitve', current_time('mysql'));

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

/* =====================================================================
 * Admin menü – egyelőre egyetlen nyitóképernyő
 * ================================================================== */

add_action('admin_menu', static function (): void {
    add_menu_page(
        'SDH Műhely',
        'SDH Műhely',
        'manage_options',
        'sdh-muhely',
        'sdh_muhely_render_dashboard',
        'dashicons-hammer',
        3
    );
});

/**
 * A nyitóképernyő.
 *
 * Nem dísz: ez a környezet életjele. Ha ezt látod a Localban, akkor a
 * plugin betöltődött, a Git-körrel pedig átért a másik gépre is.
 */
function sdh_muhely_render_dashboard(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Nincs jogosultságod ehhez az oldalhoz.');
    }

    $telepitve = get_option('sdh_muhely_telepitve', '—');

    $tenyek = [
        'Plugin verzió'     => SDH_MUHELY_VERSION,
        'WordPress'         => get_bloginfo('version'),
        'PHP'               => PHP_VERSION,
        'Telepítve'         => $telepitve,
        'Plugin mappa'      => SDH_MUHELY_DIR,
    ];

    ?>
    <div class="wrap">
        <div style="max-width:760px;margin-top:1.5rem;padding:1.6rem 1.8rem;
                    background:linear-gradient(135deg,#15181d 0%,#23272e 100%);
                    border-radius:14px;color:#edeff2">
            <p style="margin:0 0 .4rem;font-size:11px;font-weight:700;letter-spacing:.14em;
                      text-transform:uppercase;color:#d4231d">SDH&nbsp;Műhely</p>
            <h1 style="margin:0;font-size:26px;color:#fff;letter-spacing:-.02em">
                A környezet él. 🛠️
            </h1>
            <p style="margin:.6rem 0 0;font-size:14px;color:#aeb6c2;line-height:1.6;max-width:52em">
                Ez a plugin váza. Innen épül fel lépésről lépésre a belső rendszer:
                ügyfél, eszköz, munkalap, hiba, tétel, raktár. Ha ezt a képernyőt
                látod mindkét gépen, a Local + Git munkafolyamat kész.
            </p>
        </div>

        <table class="widefat striped" style="max-width:760px;margin-top:1.4rem">
            <tbody>
            <?php foreach ($tenyek as $cimke => $ertek) : ?>
                <tr>
                    <td style="width:14rem;font-weight:600"><?php echo esc_html($cimke); ?></td>
                    <td><code><?php echo esc_html((string) $ertek); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p style="max-width:760px;margin-top:1.2rem;color:#646970;font-size:13px;line-height:1.6">
            Következő lépés: a közös admin arculat és az adatbázis-séma átemelése,
            majd az első modul – az ügyfelek. A MunkaLap desktop program
            adatmodellje adja a vázat.
        </p>
    </div>
    <?php
}
