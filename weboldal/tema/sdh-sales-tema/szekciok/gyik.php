<?php
/**
 * Szekció: gyik – gyakori kérdések <details>-ben, horgonnyal (id = a kérdés `anchor`-ja, pl. „gyik-<kulcs>”).
 * Adat: SDH_Sales_Gyik::szekcio($sz) (ez jelöli a FAQPage-be kerülő kérdéseket); ha az nincs, a `gyik` lista
 * kategória / kulcsok szerint. Mezők: cim, alcim, kategoria|kategoriak, kulcsok[], db|max, csoportositva (bool),
 * kereso (bool: szűrőmező a kérdések fölött).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$opc = $sz;

if (!isset($opc['max']) && isset($opc['db'])) {
    $opc['max'] = (int) $opc['db'];
}

$elemek = [];

if (class_exists('SDH_Sales_Gyik')) {
    try {
        $elemek = SDH_Sales_Gyik::szekcio($opc);
    } catch (Throwable $e) {
        $elemek = [];
    }
} else {
    foreach (sdh_tema_elem_szur(sdh_tema_lista('gyik'), $opc, 'kategoria') as $e) {
        $a        = (array) $e['adat'];
        $elemek[] = [
            'kulcs'     => (string) $e['kulcs'],
            'kategoria' => (string) ($a['kategoria'] ?? ''),
            'kerdes'    => (string) ($a['kerdes'] ?? $e['cim']),
            'valasz'    => (string) ($a['valasz'] ?? ''),
            'anchor'    => 'gyik-' . sanitize_title((string) $e['kulcs']),
        ];
    }
}

if ($elemek === []) {
    echo sdh_tema_admin_megjegyzes('gyik: nincs kérdés'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

$csop = !empty($sz['csoportositva']);
$cs   = [];

foreach ($elemek as $e) {
    $cs[$csop ? (string) ($e['kategoria'] ?? '') : ''][] = $e;
}

echo sdh_tema_szekcio_nyit($sz, ['szelesseg' => 'szuk']); // phpcs:ignore WordPress.Security.EscapeOutput

if (!empty($sz['kereso']) && count($elemek) > 6) {
    $mid = sdh_tema_azon('gyik-szuro');
    echo '<div class="s-gyik-szuro"><label class="s-sr" for="' . esc_attr($mid) . '">' . esc_html(sdh_tema__('Kérdések szűrése')) . '</label>';
    echo '<input class="s-mezo" type="search" id="' . esc_attr($mid) . '" placeholder="' . esc_attr(sdh_tema__('Szűrés a kérdésekben…')) . '" data-gyik-szuro autocomplete="off"><p class="s-sr" aria-live="polite" data-gyik-szamlalo></p></div>';
}

foreach ($cs as $kat => $lista) {
    if ($kat !== '') {
        echo '<h3 class="s-csoportcim" id="' . esc_attr('gyik-kat-' . sanitize_title(remove_accents($kat))) . '">' . esc_html(sdh_tema_t($kat)) . '</h3>';
    }

    echo '<div class="s-gyik">';

    foreach ($lista as $e) {
        $anchor = sanitize_html_class((string) ($e['anchor'] ?? ('gyik-' . ($e['kulcs'] ?? ''))));

        $tu = !empty($e['kulcs']) ? 't:gyik/' . (string) $e['kulcs'] : '';

        echo '<details class="s-harmonika" id="' . esc_attr($anchor) . '"' . sdh_tema_szerk($tu) . '>';
        echo '<summary class="s-harmonika__fej"><span class="s-harmonika__cim"' . ($tu !== '' ? sdh_tema_szerk($tu . '/kerdes') : '') . '>' . esc_html(sdh_tema_t(wp_strip_all_tags((string) ($e['kerdes'] ?? '')))) . '</span>' . sdh_tema_ikon('plusz', 's-harmonika__ikon') . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<div class="s-harmonika__test s-proza"' . ($tu !== '' ? sdh_tema_szerk($tu . '/valasz') : '') . '>' . sdh_tema_html((string) ($e['valasz'] ?? '')) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</details>';
    }

    echo '</div>';
}

echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
