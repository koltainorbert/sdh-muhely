<?php
/**
 * Szekció: kalkulator – interaktív ár-összeállító (a bővítmény assets/front/kalkulator.js-e csatol rá).
 * Mezők: cim, alcim, alap_csomag. JS nélkül: link az árakhoz és a kapcsolatfelvételhez.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-uveg s-panel">';
echo sdh_tema_mount('kalkulator', $sz, ['afa' => (string) sdh_tema_afa()], sdh_tema_noscript()); // phpcs:ignore WordPress.Security.EscapeOutput
echo '</div>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
