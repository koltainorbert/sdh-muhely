<?php
/**
 * SDH Értékesítés téma – belépési pont.
 *
 * A téma csak megjelenít: az adatot (szekciók, menük, csomagok, GYIK…) az SDH Értékesítés bővítmény adja
 * (ARCHITEKTURA.md 7.). A bővítmény nélkül is fut (minden hívás class_exists-szel védett), csak üresebb.
 *
 *   inc/segedek.php  – szöveg/token, beállítás, URL, ikon, kép (manifest.json → <picture>), gombok, szekció-keret
 *   inc/alap.php     – theme supports, takarítás (emoji, oEmbed, blokk-CSS, jQuery migrate), CSS/JS betöltés, betűk
 *   inc/seo.php      – címke + meta description + OG (csak ha a Rank Math nincs bekapcsolva)
 *   inc/szekciok.php – szekció-diszpécser: sdh_tema_szekciok($post_id)
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SDH_TEMA_VERSION', '1.0.0');
define('SDH_TEMA_DIR', get_template_directory());
define('SDH_TEMA_URL', get_template_directory_uri());

require_once SDH_TEMA_DIR . '/inc/segedek.php';
require_once SDH_TEMA_DIR . '/inc/ikonok.php';
require_once SDH_TEMA_DIR . '/inc/alap.php';
require_once SDH_TEMA_DIR . '/inc/seo.php';
require_once SDH_TEMA_DIR . '/inc/szekciok.php';
require_once SDH_TEMA_DIR . '/inc/arak.php';
