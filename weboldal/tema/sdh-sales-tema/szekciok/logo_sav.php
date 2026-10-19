<?php
/**
 * Szekció: logo_sav – partner- / ügyfél-logók. Mezők: cim, logok [{kep, nev, url}].
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$logok = sdh_tema_elemek($sz, 'logok');

if ($logok === []) {
    echo sdh_tema_admin_megjegyzes('logo_sav: nincs logó'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

echo sdh_tema_szekcio_nyit($sz + ['cim' => ''], ['fej' => false]); // phpcs:ignore WordPress.Security.EscapeOutput

$cim = sdh_tema_mezo($sz, 'cim');

if ($cim !== '') {
    echo '<h2 class="s-logo-sav__cim">' . esc_html(sdh_tema_t($cim)) . '</h2>';
}

echo '<ul class="s-logo-sav" role="list">';

foreach ($logok as $l) {
    $nev  = sdh_tema_t(sdh_tema_mezo($l, 'nev'));
    $kep  = sdh_tema_kep_mezo($l['kep'] ?? '', '160px', ['class' => 's-logo-sav__kep'], $nev);
    $url  = sdh_tema_url(sdh_tema_mezo($l, 'url'));
    $belo = $kep !== '' ? $kep : '<span class="s-logo-sav__nev">' . esc_html($nev) . '</span>';

    if ($kep === '' && $nev === '') {
        continue;
    }

    echo '<li class="s-logo-sav__elem">' . ($url !== '' ? '<a href="' . esc_url($url) . '" rel="noopener" target="_blank" aria-label="' . esc_attr($nev) . '">' . $belo . '</a>' : $belo) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

echo '</ul>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
