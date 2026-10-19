<?php
/**
 * Szekció: iparag_racs – iparági kártyák az `iparag` elemekből (kép a manifestből: „iparag-<kulcs>”).
 * Mezők: cim, alcim, kulcsok[], db.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$elemek = sdh_tema_elem_szur(sdh_tema_lista('iparag'), $sz);

if ($elemek === []) {
    echo sdh_tema_admin_megjegyzes('iparag_racs: nincs iparág-elem'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<ul class="s-racs s-racs--3" role="list">';

foreach ($elemek as $e) {
    $a   = (array) $e['adat'];
    $k   = (string) $e['kulcs'];
    $url = sdh_tema_elem_url($e, 'iparagak');
    $nev = sdh_tema_elem_nev($e);
    $kk  = 'iparag-' . sanitize_title($k);

    echo '<li class="s-kartya s-kartya--iparag' . ($url !== '' ? ' s-kartya--katt' : '') . '">';

    if (sdh_tema_van_kep($kk)) {
        echo '<div class="s-kartya__media">' . sdh_tema_kep($kk, 'kartya', ['alt' => '', 'class' => 's-kartya__kep']) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
    } else {
        echo '<span class="s-ikondoboz">' . sdh_tema_ikon((string) ($a['ikon'] ?? 'bolt')) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    echo '<h3 class="s-kartya__cim">' . ($url !== '' ? '<a class="s-kartya__link" href="' . esc_url($url) . '">' . esc_html($nev) . '</a>' : esc_html($nev)) . '</h3>';

    $l = sdh_tema_elem_leiras($e, 180);

    if ($l !== '') {
        echo '<p class="s-kartya__szoveg">' . esc_html($l) . '</p>';
    }

    echo '</li>';
}

echo '</ul>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
