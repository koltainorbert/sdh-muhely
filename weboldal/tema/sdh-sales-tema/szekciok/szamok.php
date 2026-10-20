<?php
/**
 * Szekció: szamok – nagy számok felirattal. Mezők: cim, szamok|elemek [{ertek, cimke, ikon}].
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$lk  = sdh_tema_elemek($sz, 'szamok') !== [] ? 'szamok' : 'elemek';
$sz_ = sdh_tema_elemek($sz, $lk);

if ($sz_ === []) {
    echo sdh_tema_admin_megjegyzes('szamok: nincs elem'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<dl class="s-szamok">';

foreach ($sz_ as $e) {
    $ikon = sdh_tema_mezo($e, 'ikon');

    $ei = (int) $e['_ei'];

    echo '<div class="s-szam"' . sdh_tema_szerk_mezo($sz, $lk, $ei) . '>';
    echo '<dt class="s-szam__cimke"' . sdh_tema_szerk_mezo($sz, $lk, $ei, 'cimke') . '>' . ($ikon !== '' ? sdh_tema_ikon($ikon) : '') . esc_html(sdh_tema_t(sdh_tema_mezo($e, 'cimke'))) . '</dt>'; // phpcs:ignore WordPress.Security.EscapeOutput
    echo '<dd class="s-szam__ertek"' . sdh_tema_szerk_mezo($sz, $lk, $ei, 'ertek') . '>' . esc_html(sdh_tema_t(sdh_tema_mezo($e, 'ertek'))) . '</dd>';
    echo '</div>';
}

echo '</dl>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
