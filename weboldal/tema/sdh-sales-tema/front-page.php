<?php
/**
 * Kezdőlap: a kezdőoldal szekciói; ha a kezdőlap a bloglista (nincs statikus kezdőoldal), a home sablon.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

if (get_option('show_on_front') !== 'page' || !is_page()) {
    get_template_part('home');
    return;
}

get_header();

while (have_posts()) {
    the_post();
    $sdh_id = (int) get_the_ID();

    if (sdh_tema_van_szekcio($sdh_id)) {
        sdh_tema_szekciok($sdh_id);
    } else {
        echo sdh_tema_oldalfej(sdh_tema_t(get_the_title()), (string) sdh_tema_t('{szlogen}')); // phpcs:ignore WordPress.Security.EscapeOutput
        get_template_part('sablonok/tartalom', 'oldal');
    }
}

get_footer();
