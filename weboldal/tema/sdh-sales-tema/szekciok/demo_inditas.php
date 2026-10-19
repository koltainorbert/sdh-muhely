<?php
/**
 * Szekció: demo_inditas – saját próbapéldány indítása. Mezők: cim, alcim, gomb_szoveg.
 * A felületet a bővítmény assets/front/demo_inditas.js-e rajzolja a csatolási pontra (SDH_Sales_Front::igeny('demo_inditas')).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

echo sdh_tema_szekcio_nyit($sz, ['szelesseg' => 'szuk']); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-uveg s-panel s-panel--demo_inditas">';
echo sdh_tema_mount('demo_inditas', $sz, ['demo' => (string) sdh_tema_b('demo.url', '')], sdh_tema_noscript()); // phpcs:ignore WordPress.Security.EscapeOutput
echo '</div>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
