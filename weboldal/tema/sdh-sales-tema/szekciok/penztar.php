<?php
/**
 * Szekció: penztar – számlázási adatok, fizetési mód, rendelés leadása. Nem gyorsítótárazható oldal.
 * A felületet a bővítmény assets/front/penztar.js-e rajzolja a csatolási pontra (SDH_Sales_Front::igeny('penztar')).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-uveg s-panel s-panel--penztar">';
echo sdh_tema_mount('penztar', $sz, [], sdh_tema_noscript()); // phpcs:ignore WordPress.Security.EscapeOutput
echo '</div>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
