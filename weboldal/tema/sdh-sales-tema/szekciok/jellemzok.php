<?php
/**
 * Szekció: jellemzok – ikon + cím + szöveg rács. Mezők: cim, alcim, oszlop (2|3|4), elemek [{ikon, cim, szoveg, url?}].
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$elemek = sdh_tema_elemek($sz);
$oszlop = max(2, min(4, (int) sdh_tema_mezo($sz, 'oszlop', '3')));

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<ul class="s-racs s-racs--' . (int) $oszlop . '" role="list">';

foreach ($elemek as $e) {
    $cim = sdh_tema_t(sdh_tema_mezo($e, 'cim'));
    $url = sdh_tema_url(sdh_tema_mezo($e, 'url'));

    $ei = (int) $e['_ei'];

    echo '<li class="s-kartya s-kartya--jellemzo"' . sdh_tema_szerk_mezo($sz, 'elemek', $ei) . '>';
    echo '<span class="s-ikondoboz"' . sdh_tema_szerk_mezo($sz, 'elemek', $ei, 'ikon') . '>' . sdh_tema_ikon(sdh_tema_mezo($e, 'ikon', 'pipa')) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput

    if ($cim !== '') {
        echo '<h3 class="s-kartya__cim"' . sdh_tema_szerk_mezo($sz, 'elemek', $ei, 'cim') . '>' . ($url !== '' ? '<a class="s-kartya__link" href="' . esc_url($url) . '">' . esc_html($cim) . '</a>' : esc_html($cim)) . '</h3>';
    }

    $sz_ = sdh_tema_mezo($e, 'szoveg');

    if ($sz_ !== '') {
        echo '<div class="s-kartya__szoveg"' . sdh_tema_szerk_mezo($sz, 'elemek', $ei, 'szoveg') . '>' . sdh_tema_szoveg($sz_) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    echo '</li>';
}

echo '</ul>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
