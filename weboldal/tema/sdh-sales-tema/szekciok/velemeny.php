<?php
/**
 * Szekció: velemeny – ügyfél-vélemények. Mezők: cim, velemenyek|elemek [{szoveg, nev, ceg, kep, ertekeles}].
 * Csak valódi, megadott vélemény jelenik meg (nincs kitalált minta).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$v = sdh_tema_elemek($sz, 'velemenyek') ?: sdh_tema_elemek($sz);
$v = array_values(array_filter($v, static fn ($e) => sdh_tema_mezo($e, 'szoveg') !== ''));

if ($v === []) {
    echo sdh_tema_admin_megjegyzes('velemeny: nincs vélemény'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<ul class="s-racs s-racs--' . (count($v) >= 3 ? 3 : 2) . '" role="list">';

foreach ($v as $e) {
    $ert = max(0, min(5, (int) ($e['ertekeles'] ?? 0)));

    echo '<li class="s-kartya s-velemeny"><figure>';

    if ($ert > 0) {
        echo '<p class="s-csillagok" aria-label="' . esc_attr(sprintf(/* translators: %d: 1–5 */ sdh_tema__('Értékelés: %d / 5'), $ert)) . '">';

        for ($i = 1; $i <= 5; $i++) {
            echo sdh_tema_ikon('csillag', $i <= $ert ? 's-csillag s-csillag--tele' : 's-csillag'); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '</p>';
    }

    echo '<blockquote class="s-velemeny__szoveg">' . sdh_tema_szoveg(sdh_tema_mezo($e, 'szoveg')) . '</blockquote>'; // phpcs:ignore WordPress.Security.EscapeOutput
    echo '<figcaption class="s-velemeny__ki">';
    echo sdh_tema_kep_mezo($e['kep'] ?? '', '48px', ['class' => 's-velemeny__foto', 'alt' => '', 'width' => 48, 'height' => 48]); // phpcs:ignore WordPress.Security.EscapeOutput
    echo '<span><strong>' . esc_html(sdh_tema_t(sdh_tema_mezo($e, 'nev'))) . '</strong>';

    $ceg = sdh_tema_mezo($e, 'ceg');

    if ($ceg !== '') {
        echo '<span class="s-halvany">' . esc_html(sdh_tema_t($ceg)) . '</span>';
    }

    echo '</span></figcaption></figure></li>';
}

echo '</ul>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
