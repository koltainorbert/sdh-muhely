<?php
/**
 * Szekció: jogi – az oldal jogi szövege (a post_content) olvasható szélességben, verzióval, hatálybalépéssel,
 * tartalomjegyzékkel és nyomtatás gombbal. Mezők: verzio, hatalyos.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$post = get_post();

if (!$post instanceof WP_Post) {
    return;
}

$tartalom = (string) apply_filters('the_content', $post->post_content); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP-szűrő
[$tartalom, $tj] = sdh_tema_tartalomjegyzek($tartalom);

$verzio   = sdh_tema_mezo($sz, 'verzio');
$hatalyos = sdh_tema_mezo($sz, 'hatalyos');

echo '<section class="s-szekcio s-jogi s-hatter--alap" id="' . esc_attr(sdh_tema_szekcio_id($sz + ['horgony' => 'jogi-szoveg'])) . '"><div class="s-kont s-jogi__racs">';
echo '<aside class="s-jogi__oldalsav">';
echo sdh_tema_tj_html($tj); // phpcs:ignore WordPress.Security.EscapeOutput
echo '</aside><div class="s-jogi__test">';

if ($verzio !== '' || $hatalyos !== '') {
    echo '<p class="s-jogi__meta">';

    if ($verzio !== '') {
        echo '<span class="s-pill">' . esc_html(sprintf(/* translators: %s: verzió */ sdh_tema__('Verzió: %s'), $verzio)) . '</span> ';
    }

    if ($hatalyos !== '') {
        $ts = strtotime($hatalyos);
        echo '<span class="s-pill">' . esc_html(sprintf(/* translators: %s: dátum */ sdh_tema__('Hatályos: %s'), $ts ? sdh_tema_datum($ts) : $hatalyos)) . '</span>';
    }

    echo ' <button type="button" class="s-gomb s-gomb--szellem s-gomb--kicsi" data-nyomtat>' . sdh_tema_ikon('nyomtato') . esc_html(sdh_tema__('Nyomtatás')) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
    echo '</p>';
}

echo '<div class="s-proza s-proza--jogi">' . $tartalom . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content
echo '</div></div></section>';
