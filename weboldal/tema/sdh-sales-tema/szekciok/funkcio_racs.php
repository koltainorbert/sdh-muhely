<?php
/**
 * Szekció: funkcio_racs – funkciókártyák a `funkcio` elemekből. Mezők: cim, alcim, kulcsok[], csoport, db, gomb.
 * Kártya: ikon, cím, rövid leírás, link a funkció aloldalára (ha van), kép a manifestből („funkcio-<kulcs>”, ha van és `kepek` igaz).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$elemek = sdh_tema_elem_szur(sdh_tema_lista('funkcio'), $sz);

if ($elemek === []) {
    echo sdh_tema_admin_megjegyzes('funkcio_racs: nincs funkció-elem'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

$kepek = !empty($sz['kepek']);

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<ul class="s-racs s-racs--3" role="list">';

foreach ($elemek as $e) {
    $a   = (array) $e['adat'];
    $k   = (string) $e['kulcs'];
    $url = sdh_tema_elem_url($e, 'funkciok');
    $nev = sdh_tema_elem_nev($e);
    $kep = $kepek && sdh_tema_van_kep('funkcio-' . $k) ? sdh_tema_kep('funkcio-' . $k, 'kartya', ['alt' => '', 'class' => 's-kartya__kep']) : '';

    echo '<li class="s-kartya s-kartya--funkcio' . ($url !== '' ? ' s-kartya--katt' : '') . '" id="' . esc_attr('funkcio-' . sanitize_title($k)) . '"' . sdh_tema_elem_szerk($e) . '>';
    echo $kep; // phpcs:ignore WordPress.Security.EscapeOutput
    echo '<span class="s-ikondoboz">' . sdh_tema_ikon((string) ($a['ikon'] ?? 'munkalap')) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
    echo '<h3 class="s-kartya__cim"' . sdh_tema_elem_szerk($e, 'nev') . '>' . ($url !== '' ? '<a class="s-kartya__link" href="' . esc_url($url) . '">' . esc_html($nev) . '</a>' : esc_html($nev)) . '</h3>';

    $l = sdh_tema_elem_leiras($e, 180);

    if ($l !== '') {
        echo '<p class="s-kartya__szoveg"' . sdh_tema_elem_szerk($e, 'leiras') . '>' . esc_html($l) . '</p>';
    }

    if ($url !== '') {
        echo '<span class="s-kartya__tovabb" aria-hidden="true">' . esc_html(sdh_tema__('Részletek')) . sdh_tema_ikon('nyil-jobb') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    echo '</li>';
}

echo '</ul>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
