<?php
/**
 * Szekció: urlap – kapcsolat | ajanlat | egyedi | partner. Mezők: cim, alcim, urlap, siker.
 * A mezőket és a küldést a bővítmény JS-e adja (`data-sdh="urlap"`, `data-urlap="<fajta>"`); az egyedi igényfelmérő
 * külön modul (`egyedi`). JS nélkül: a céges e-mail-cím.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$fajta = sanitize_key(sdh_tema_mezo($sz, 'urlap', 'kapcsolat'));
$fajta = in_array($fajta, ['kapcsolat', 'ajanlat', 'egyedi', 'partner'], true) ? $fajta : 'kapcsolat';
$modul = $fajta === 'egyedi' ? 'egyedi' : 'urlap';

echo sdh_tema_szekcio_nyit($sz, ['szelesseg' => 'szuk']); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-uveg s-panel s-panel--urlap" id="' . esc_attr('urlap-' . $fajta) . '">';
echo sdh_tema_mount($modul, $sz, ['urlap' => $fajta], sdh_tema_noscript()); // phpcs:ignore WordPress.Security.EscapeOutput
echo '</div>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
