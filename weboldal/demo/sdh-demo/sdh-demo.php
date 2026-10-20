<?php
/**
 * Plugin Name:       Bemutató
 * Description:       Nyilvános bemutató példány a műhelyrendszerhez: kitalált adatok (generátor), éjszakai visszaállítás pillanatképből, egykattintásos ideiglenes belépés, rekordkeret, kimenő üzenetek elfogása, tiltott műveletek.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       bemutato
 *
 * CSAK A KÜLÖN BEMUTATÓ PÉLDÁNYRA (saját aldomain, saját adatbázis), soha nem éles rendszerre: a bővítmény
 * bekapcsolva mindent tesztüzembe tesz, a látogatóknak ideiglenes, korlátozott adminisztrátori fiókot ad, és
 * éjjel a pillanatképből visszaállítja a műhely összes adatát.
 *
 * Belső nevek (soha nem kerülnek kimenetbe): osztályok SDH_Demo_*, opciók sdh_demo_*, táblák {prefix}bemutato_*.
 * A kimenetben (HTML, URL, üzenet, CSS, JS) csak semleges nevek: `?bemutato=…`, `bm-*` osztályok.
 *
 * @package SDH_Demo
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SDH_DEMO_VERSION', '1.0.0');
define('SDH_DEMO_FILE', __FILE__);
define('SDH_DEMO_DIR', plugin_dir_path(__FILE__));

// A műhely tesztüzeme a legkorábban: a beállítástól és a szűrőktől függetlenül (SMS szimuláció, levél, számla, külső mentés tiltva).
if (!defined('SDH_MUHELY_TESZTUZEM')) {
    define('SDH_MUHELY_TESZTUZEM', true);
}

foreach (['', '-generator', '-pillanatkep', '-keret', '-inditas', '-kimeno', '-vedelem', '-sav', '-admin'] as $sdh_demo_resz) {
    require_once SDH_DEMO_DIR . 'includes/class-sdh-demo' . $sdh_demo_resz . '.php';
}

unset($sdh_demo_resz);

// A bővítmény a műhely előtt töltődhet be (a mappanév sorrendje): minden a plugins_loaded után indul.
SDH_Demo::korai();
add_action('plugins_loaded', [SDH_Demo::class, 'init'], 20);

register_activation_hook(__FILE__, [SDH_Demo::class, 'aktivalas']);
register_deactivation_hook(__FILE__, [SDH_Demo::class, 'deaktivalas']);
