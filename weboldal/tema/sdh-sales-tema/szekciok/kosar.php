<?php
/**
 * Szekció: kosar – a kosár tartalma (a böngészőben tárolt tételek, az árat a szerver számolja). Nem gyorsítótárazható oldal.
 * A felületet a bővítmény assets/front/kosar.js-e rajzolja a csatolási pontra (SDH_Sales_Front::igeny('kosar')).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-uveg s-panel s-panel--kosar">';
echo sdh_tema_mount('kosar', $sz, [], sdh_tema_noscript()); // phpcs:ignore WordPress.Security.EscapeOutput
echo '</div>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
