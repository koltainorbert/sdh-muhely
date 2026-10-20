<?php
/**
 * Szekció: statusz – szolgáltatások állapota. Mezők: cim, alcim, elemek [{nev, allapot (ok|figyel|hiba|karbantartas), megj}],
 * frissitve (szöveg). Az állapotot az admin adja meg (nincs élő mérés a fronton → gyorsítótárazható); ha a bővítmény
 * `sdh_tema_statusz` szűrőn át élő adatot ad, azt mutatja.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$elemek = (array) apply_filters('sdh_tema_statusz', sdh_tema_elemek($sz), $sz);
$cimkek = [
    'ok'           => [sdh_tema__('Működik'), 'pipa'],
    'figyel'       => [sdh_tema__('Lassulás'), 'figyelem'],
    'hiba'         => [sdh_tema__('Üzemzavar'), 'x'],
    'karbantartas' => [sdh_tema__('Karbantartás'), 'beallitas'],
];

echo sdh_tema_szekcio_nyit($sz, ['szelesseg' => 'szuk']); // phpcs:ignore WordPress.Security.EscapeOutput

if ($elemek === []) {
    echo '<p class="s-doboz s-doboz--info">' . sdh_tema_ikon('info') . esc_html(sdh_tema__('Jelenleg nincs bejelentett üzemzavar vagy karbantartás.')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
} else {
    $mind_ok = true;

    foreach ($elemek as $e) {
        $mind_ok = $mind_ok && (($e['allapot'] ?? 'ok') === 'ok');
    }

    echo '<p class="s-statusz__osszes s-statusz--' . ($mind_ok ? 'ok' : 'figyel') . '" role="status">' . sdh_tema_ikon($mind_ok ? 'pipa' : 'figyelem') . esc_html($mind_ok ? sdh_tema__('Minden rendszer működik') : sdh_tema__('Részleges fennakadás')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
    echo '<ul class="s-statusz">';

    foreach ($elemek as $e) {
        if (!is_array($e)) {
            continue;
        }

        $a = (string) ($e['allapot'] ?? 'ok');
        $a = isset($cimkek[$a]) ? $a : 'ok';

        $ei = isset($e['_ei']) ? (int) $e['_ei'] : null;

        echo '<li class="s-statusz__sor"' . ($ei !== null ? sdh_tema_szerk_mezo($sz, 'elemek', $ei) : '') . '><span class="s-statusz__nev">' . esc_html(sdh_tema_t((string) ($e['nev'] ?? ''))) . '</span>';
        echo '<span class="s-pill s-pill--' . esc_attr($a) . '">' . sdh_tema_ikon($cimkek[$a][1]) . esc_html($cimkek[$a][0]) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput

        if (!empty($e['megj'])) {
            echo '<span class="s-statusz__megj">' . esc_html(sdh_tema_t((string) $e['megj'])) . '</span>';
        }

        echo '</li>';
    }

    echo '</ul>';
}

$fr = sdh_tema_mezo($sz, 'frissitve');

if ($fr !== '') {
    echo '<p class="s-halvany s-statusz__ido"' . sdh_tema_szerk_mezo($sz, 'frissitve') . '>' . esc_html(sprintf(/* translators: %s: idő */ sdh_tema__('Frissítve: %s'), $fr)) . '</p>';
}

echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
