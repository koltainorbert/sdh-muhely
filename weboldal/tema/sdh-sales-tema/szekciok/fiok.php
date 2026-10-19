<?php
/**
 * Szekció: fiok – ügyfélfiók: belépés e-mailes linkkel, licencek, rendelések, számlák. Nem gyorsítótárazható oldal.
 * A felületet a bővítmény assets/front/fiok.js-e rajzolja a csatolási pontra (SDH_Sales_Front::igeny('fiok')).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

echo sdh_tema_szekcio_nyit($sz, ['szelesseg' => 'szuk']); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-uveg s-panel s-panel--fiok">';
echo sdh_tema_mount('fiok', $sz, [], sdh_tema_noscript()); // phpcs:ignore WordPress.Security.EscapeOutput
echo '</div>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
