<?php
/**
 * Template Name: Jogi szöveg
 *
 * Jogi oldal (ÁSZF, adatkezelés, süti-tájékoztató…): olvasható szélesség, tartalomjegyzék, nyomtatás.
 * Ha az oldalnak van `jogi` szekciója (verzió, hatályosság), azt rajzolja; különben a tartalmat ugyanígy.
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

    $sdh_jogi = null;

    foreach (sdh_tema_szekcio_adat($sdh_id) as $sdh_s) {
        if (($sdh_s['tipus'] ?? '') === 'jogi') {
            $sdh_jogi = $sdh_s;
            break;
        }
    }

    sdh_tema_szekcio($sdh_jogi ?? ['tipus' => 'jogi'], 0);
}

get_footer();
