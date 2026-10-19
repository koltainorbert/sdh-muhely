<?php
/**
 * Oldal: a szekciók (SDH_Sales_Tartalom::szekciok); szekció nélkül a szerkesztett tartalom olvasható szélességben.
 * Szekciós oldalon, ha nincs hero, kap egy oldalfejet (cím + morzsa); funkció- / iparág-aloldal végén „Kapcsolódó” blokk.
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

    if (sdh_tema_van_szekcio($sdh_id)) {
        $sdh_sz   = sdh_tema_szekcio_adat($sdh_id);
        $sdh_elso = '';

        foreach ($sdh_sz as $sdh_s) {
            if (empty($sdh_s['rejtett']) && !empty($sdh_s['tipus'])) {
                $sdh_elso = (string) $sdh_s['tipus'];
                break;
            }
        }

        if ($sdh_elso !== 'hero') {
            echo sdh_tema_oldalfej(sdh_tema_t(get_the_title())); // phpcs:ignore WordPress.Security.EscapeOutput
        } else {
            echo '<div class="s-kont s-morzsa-sav">' . sdh_tema_morzsa_auto() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }

        sdh_tema_szekciok($sdh_id);

        [$sdh_t, $sdh_k] = sdh_tema_oldal_elem($sdh_id);

        if ($sdh_t !== '') {
            $sdh_kap = sdh_tema_kapcsolodo($sdh_t, $sdh_k);

            if ($sdh_kap !== '') {
                echo '<section class="s-szekcio s-szekcio--kapcsolodo s-hatter--alap"><div class="s-kont">' . $sdh_kap . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }
        }
    } else {
        echo sdh_tema_oldalfej(sdh_tema_t(get_the_title())); // phpcs:ignore WordPress.Security.EscapeOutput
        get_template_part('sablonok/tartalom', 'oldal');
    }
}

get_footer();
