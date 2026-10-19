<?php
/**
 * Szekció: telepito – lépésenkénti telepítési útmutató a `telepites` elemekből (követelmények, lépések, ellenőrzőlista).
 * Elemformák (a SEO-modul HowTo-jával egyezően): lépésenként egy elem {cim, leiras|szoveg}; vagy egy elem `lepesek` listával;
 * `tipus: kovetelmeny` / `kovetelmenyek: []`; `tipus: ellenorzolista` + `elemek: []`. Mezők: cim, alcim.
 * Az ellenőrzőlista pipái a böngészőben maradnak (localStorage, felhasználónként – nem kerül a szerverre).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$kov     = [];
$lepesek = [];
$lista   = [];

foreach (sdh_tema_lista('telepites') as $e) {
    $a = (array) $e['adat'];

    foreach ((array) ($a['kovetelmenyek'] ?? []) as $k) {
        if (is_scalar($k)) {
            $kov[] = (string) $k;
        }
    }

    $t = (string) ($a['tipus'] ?? '');

    if ($t === 'kovetelmeny') {
        $kov[] = sdh_tema_elem_nev($e);
        continue;
    }

    if ($t === 'ellenorzolista') {
        foreach ((array) ($a['elemek'] ?? $a['lista'] ?? []) as $x) {
            $lista[] = is_array($x) ? (string) ($x['cim'] ?? $x['szoveg'] ?? '') : (string) $x;
        }
        continue;
    }

    if (is_array($a['lepesek'] ?? null)) {
        foreach ($a['lepesek'] as $l) {
            if (is_array($l) && !empty($l['cim'])) {
                $lepesek[] = ['cim' => (string) $l['cim'], 'szoveg' => (string) ($l['leiras'] ?? $l['szoveg'] ?? ''), 'kulcs' => sanitize_title((string) $l['cim'])];
            } elseif (is_string($l) && trim($l) !== '') {
                $lepesek[] = ['cim' => $l, 'szoveg' => '', 'kulcs' => sanitize_title($l)];
            }
        }
        continue;
    }

    $lepesek[] = ['cim' => sdh_tema_elem_nev($e), 'szoveg' => (string) ($a['leiras'] ?? $a['szoveg'] ?? ''), 'kulcs' => sanitize_title((string) $e['kulcs'])];
}

if ($lepesek === [] && $kov === [] && $lista === []) {
    echo sdh_tema_admin_megjegyzes('telepito: nincs telepítési elem'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

echo sdh_tema_szekcio_nyit($sz, ['szelesseg' => 'szuk']); // phpcs:ignore WordPress.Security.EscapeOutput

if ($kov !== []) {
    echo '<div class="s-doboz s-doboz--info"><h3 class="s-doboz__cim">' . sdh_tema_ikon('info') . esc_html(sdh_tema__('Amire szükséged lesz')) . '</h3><ul class="s-pipalista">'; // phpcs:ignore WordPress.Security.EscapeOutput

    foreach (array_unique($kov) as $k) {
        echo '<li>' . sdh_tema_ikon('pipa') . '<span>' . esc_html(sdh_tema_t($k)) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    echo '</ul></div>';
}

if ($lepesek !== []) {
    echo '<ol class="s-lepesek s-lepesek--fuggoleges">';

    foreach ($lepesek as $i => $l) {
        echo '<li class="s-lepes" id="' . esc_attr('lepes-' . $l['kulcs']) . '"><span class="s-lepes__szam" aria-hidden="true">' . (int) ($i + 1) . '</span><div class="s-lepes__test">';
        echo '<h3 class="s-lepes__cim">' . esc_html(sdh_tema_t($l['cim'])) . '</h3>';
        echo '<div class="s-lepes__szoveg s-proza">' . sdh_tema_szoveg($l['szoveg']) . '</div></div></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    echo '</ol>';
}

if ($lista !== []) {
    $lid = sdh_tema_azon('telepito-lista');
    echo '<fieldset class="s-ellenorzo" data-ellenorzo="' . esc_attr('telepito-' . get_the_ID()) . '"><legend class="s-ellenorzo__cim">' . esc_html(sdh_tema__('Ellenőrzőlista')) . '</legend>';

    foreach (array_values(array_filter($lista, 'strlen')) as $i => $x) {
        $id = $lid . '-' . $i;
        echo '<div class="s-jelolo"><input type="checkbox" class="s-jelolo__mezo" id="' . esc_attr($id) . '" value="' . (int) $i . '"><label for="' . esc_attr($id) . '">' . esc_html(sdh_tema_t($x)) . '</label></div>';
    }

    echo '</fieldset>';
}

echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
