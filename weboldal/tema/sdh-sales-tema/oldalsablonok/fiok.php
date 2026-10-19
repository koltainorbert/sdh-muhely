<?php
/**
 * Template Name: Ügyfélfiók
 *
 * Váz: belépés e-mailes linkkel, licencek, rendelések, számlák. Az oldal nem gyorsítótárazható (a bővítmény küldi a fejléceket); a HTML-ben nincs felhasználói adat –
 * a felületet a bővítmény JS-e rajzolja a csatolási pontra. Ha az oldalnak vannak szekciói, azok is megjelennek.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

while (have_posts()) {
    the_post();
    $sdh_id = (int) get_the_ID();
    echo sdh_tema_oldalfej(sdh_tema_t(get_the_title())); // phpcs:ignore WordPress.Security.EscapeOutput

    if (sdh_tema_van_tipus($sdh_id, 'fiok')) {
        sdh_tema_szekciok($sdh_id);
    } else {
        sdh_tema_szekcio(['tipus' => 'fiok', 'hatter' => 'alap'], 0);
        sdh_tema_szekciok($sdh_id);
    }
}

get_footer();
