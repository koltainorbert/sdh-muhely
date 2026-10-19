<?php
/**
 * Szekció: oktatas – leckék modulonként, kvízzel. Mezők: cim, alcim, modul (a data-opciok-ban megy a JS-nek).
 * A felületet a bővítmény assets/front/oktatas.js-e rajzolja a csatolási pontra (SDH_Sales_Front::igeny('oktatas')).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-uveg s-panel s-panel--oktatas">';
echo sdh_tema_mount('oktatas', $sz, [], sdh_tema_noscript()); // phpcs:ignore WordPress.Security.EscapeOutput
echo '</div>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
