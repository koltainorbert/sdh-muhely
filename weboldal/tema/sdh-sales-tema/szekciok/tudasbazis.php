<?php
/**
 * Szekció: tudasbazis – a súgó fejezetei csoportonként (SDH_Sales_Szikra_Tudas::fejezetek()), keresőmezővel.
 * Ha az oldal URL-je /tudasbazis/<fejezet>/ alakú, vagy a szekció `fejezet` mezője ki van töltve, az adott fejezet
 * darabjait rajzolja tartalomjegyzékkel. Mezők: cim, alcim, kereso (bool, alap igaz), fejezet.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('SDH_Sales_Szikra_Tudas')) {
    echo sdh_tema_admin_megjegyzes('tudasbazis: a tudásbázis-modul nem érhető el'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

$fejezet_k = sanitize_title(sdh_tema_mezo($sz, 'fejezet', (string) get_query_var(SDH_TEMA_TUDAS_VAR, '')));
$fejezet   = null;

try {
    $fejezet  = $fejezet_k !== '' ? SDH_Sales_Szikra_Tudas::fejezet($fejezet_k) : null;
    $fejezetek = $fejezet === null ? SDH_Sales_Szikra_Tudas::fejezetek() : [];
} catch (Throwable $e) {
    $fejezetek = [];
}

if ($fejezet !== null) {
    $id = sdh_tema_szekcio_id($sz);
    echo sdh_tema_szekcio_nyit(['cim' => (string) ($fejezet['cim'] ?? ''), 'alcim' => (string) ($fejezet['osszefoglalo'] ?? '')] + $sz, ['szelesseg' => 'szuk', 'igazitas' => 'bal']); // phpcs:ignore WordPress.Security.EscapeOutput

    $tj   = [];
    $html = '';

    foreach ((array) ($fejezet['darabok'] ?? []) as $d) {
        $h     = sanitize_html_class((string) ($d['horgony'] ?? ''));
        $cim   = (string) ($d['cim'] ?? '');
        $tj[]  = ['id' => $h, 'cim' => $cim, 'szint' => 2];
        $html .= '<section class="s-tudas-darab" id="' . esc_attr($h) . '"><h2>' . esc_html($cim) . '</h2>'
            . (class_exists('SDH_Sales_Tartalom') ? SDH_Sales_Tartalom::md_html((string) ($d['szoveg'] ?? '')) : wpautop(esc_html((string) ($d['szoveg'] ?? ''))))
            . '</section>';
    }

    echo sdh_tema_tj_html($tj); // phpcs:ignore WordPress.Security.EscapeOutput
    echo '<div class="s-proza">' . wp_kses_post($html) . '</div>';
    echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

if ($fejezetek === []) {
    echo sdh_tema_admin_megjegyzes('tudasbazis: nincs fejezet'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

$cs = [];

foreach ($fejezetek as $f) {
    $cs[(string) ($f['csoport'] ?? '')][] = $f;
}

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput

if (!array_key_exists('kereso', $sz) || !empty($sz['kereso'])) {
    $mid = sdh_tema_azon('tudas-szuro');
    echo '<div class="s-gyik-szuro"><label class="s-sr" for="' . esc_attr($mid) . '">' . esc_html(sdh_tema__('Fejezetek szűrése')) . '</label>';
    echo '<input class="s-mezo" type="search" id="' . esc_attr($mid) . '" placeholder="' . esc_attr(sdh_tema__('Mit szeretnél megtudni?')) . '" data-kartya-szuro autocomplete="off"><p class="s-sr" aria-live="polite" data-gyik-szamlalo></p></div>';
}

foreach ($cs as $csnev => $lista) {
    if ($csnev !== '') {
        echo '<h3 class="s-csoportcim">' . esc_html(sdh_tema_t($csnev)) . '</h3>';
    }

    echo '<ul class="s-racs s-racs--3" role="list">';

    foreach ($lista as $f) {
        $url = (string) ($f['url'] ?? '');

        echo '<li class="s-kartya s-kartya--tudas' . ($url !== '' ? ' s-kartya--katt' : '') . '"><span class="s-ikondoboz s-ikondoboz--kicsi">' . sdh_tema_ikon('konyv') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<h4 class="s-kartya__cim">' . ($url !== '' ? '<a class="s-kartya__link" href="' . esc_url($url) . '">' . esc_html(sdh_tema_t((string) ($f['cim'] ?? ''))) . '</a>' : esc_html(sdh_tema_t((string) ($f['cim'] ?? '')))) . '</h4>';
        echo '<p class="s-kartya__szoveg">' . esc_html(wp_html_excerpt(sdh_tema_t((string) ($f['osszefoglalo'] ?? '')), 170, '…')) . '</p></li>';
    }

    echo '</ul>';
}

echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
