<?php
/**
 * Szekció: video – kattintásra töltődő videó (YouTube nocookie / Vimeo dnt / MP4). Előtte nincs harmadik féltől jövő kérés.
 * Mezők: cim, alcim, url, poszter (kép), leiras.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$url  = sdh_tema_url(sdh_tema_mezo($sz, 'url'));
$beag = '';
$tip  = '';

if (preg_match('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,15})#', $url, $m)) {
    $beag = 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1&rel=0';
    $tip  = 'iframe';
} elseif (preg_match('#vimeo\.com/(?:video/)?(\d{5,12})#', $url, $m)) {
    $beag = 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1&dnt=1';
    $tip  = 'iframe';
} elseif (preg_match('#\.(mp4|webm)(\?.*)?$#i', $url)) {
    $beag = $url;
    $tip  = 'video';
}

if ($beag === '') {
    echo sdh_tema_admin_megjegyzes('video: hiányzó vagy nem támogatott URL'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

$cim    = sdh_tema_t(sdh_tema_mezo($sz, 'cim', sdh_tema__('Videó')));
$poszt  = sdh_tema_kep_mezo($sz['poszter'] ?? '', 'teljes', ['class' => 's-video__poszter', 'alt' => '']);

echo sdh_tema_szekcio_nyit($sz, ['szelesseg' => 'szuk']); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-video s-uveg" data-video="' . esc_attr($tip) . '" data-src="' . esc_url($beag) . '" data-cim="' . esc_attr($cim) . '"' . sdh_tema_szerk_mezo($sz, 'url') . '>';
echo $poszt; // phpcs:ignore WordPress.Security.EscapeOutput
echo '<button class="s-video__gomb" type="button">' . sdh_tema_ikon('jatek') . '<span>' . esc_html(sdh_tema__('Videó lejátszása')) . '<span class="s-sr">: ' . esc_html($cim) . '</span></span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput

if ($tip === 'iframe') {
    echo '<p class="s-video__megj">' . esc_html(sdh_tema__('Lejátszáskor a videó a szolgáltató oldaláról töltődik be.')) . '</p>';
}

echo '<noscript><a class="s-gomb s-gomb--masodlagos" href="' . esc_url($url) . '">' . esc_html(sdh_tema__('Videó megnyitása')) . '</a></noscript>';
echo '</div>';

$leiras = sdh_tema_mezo($sz, 'leiras');

if ($leiras !== '') {
    echo '<div class="s-video__leiras"' . sdh_tema_szerk_mezo($sz, 'leiras') . '>' . sdh_tema_szoveg($leiras) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
