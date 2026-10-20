<?php
/**
 * Szekció: lepesek – számozott folyamat. Mezők: cim, alcim, lepesek|elemek [{ikon, cim, szoveg}].
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$lk      = sdh_tema_elemek($sz, 'lepesek') !== [] ? 'lepesek' : 'elemek';
$lepesek = sdh_tema_elemek($sz, $lk);

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<ol class="s-lepesek">';

foreach ($lepesek as $i => $l) {
    $ei = (int) $l['_ei'];

    echo '<li class="s-lepes"' . sdh_tema_szerk_mezo($sz, $lk, $ei) . '><span class="s-lepes__szam" aria-hidden="true">' . (int) ($i + 1) . '</span><div class="s-lepes__test">';

    $ikon = sdh_tema_mezo($l, 'ikon');

    if ($ikon !== '') {
        echo sdh_tema_ikon($ikon, 's-lepes__ikon'); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    $cim = sdh_tema_mezo($l, 'cim');

    if ($cim !== '') {
        echo '<h3 class="s-lepes__cim"' . sdh_tema_szerk_mezo($sz, $lk, $ei, 'cim') . '>' . esc_html(sdh_tema_t($cim)) . '</h3>';
    }

    echo '<div class="s-lepes__szoveg"' . sdh_tema_szerk_mezo($sz, $lk, $ei, 'szoveg') . '>' . sdh_tema_szoveg(sdh_tema_mezo($l, 'szoveg')) . '</div></div></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

echo '</ol>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
