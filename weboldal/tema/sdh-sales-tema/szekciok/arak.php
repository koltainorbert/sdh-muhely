<?php
/**
 * Szekció: arak – csomagok árkártyái havi / éves váltóval. Mezők: cim, alcim, csomagok[] (üres: mind),
 * alap_ciklus (eves|havi), ciklus_valto (bool, alap igaz), megjegyzes.
 * Minden szám a tartalom-tárból (csomag) jön, nettó; a bruttó az áfa-beállításból. A váltó JS nélkül is működik
 * (rádiógombok + CSS :has), a JS csak a `data-ciklus`-t frissíti a régebbi böngészőknek.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$csomagok = sdh_tema_csomagok(sdh_tema_szovegek($sz, 'csomagok'));

if ($csomagok === []) {
    echo sdh_tema_admin_megjegyzes('arak: nincs csomag'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

$ciklus = sdh_tema_mezo($sz, 'alap_ciklus', 'eves') === 'havi' ? 'havi' : 'eves';
$valto  = !array_key_exists('ciklus_valto', $sz) || !empty($sz['ciklus_valto']);
$modul  = sdh_tema_modulok();
$nev    = sdh_tema_azon('ciklus');
$max_k  = 0;

foreach ($csomagok as $c) {
    $max_k = max($max_k, sdh_tema_eves_kedvezmeny($c));
}

echo sdh_tema_szekcio_nyit($sz, ['osztaly' => 's-arak']); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-arak__doboz" data-ciklus="' . esc_attr($ciklus) . '">';

if ($valto) {
    echo '<fieldset class="s-szegmens" data-ciklus-valto><legend class="s-sr">' . esc_html(sdh_tema__('Számlázási ciklus')) . '</legend>';

    foreach (['havi' => sdh_tema__('Havi'), 'eves' => sdh_tema__('Éves')] as $c => $felirat) {
        $id = $nev . '-' . $c;
        echo '<input class="s-szegmens__radio" type="radio" name="' . esc_attr($nev) . '" id="' . esc_attr($id) . '" value="' . esc_attr($c) . '"' . checked($ciklus, $c, false) . '>';
        echo '<label class="s-szegmens__cimke" for="' . esc_attr($id) . '">' . esc_html($felirat);

        if ($c === 'eves' && $max_k > 0) {
            echo ' <span class="s-pill s-pill--ok s-pill--kicsi">' . esc_html(sprintf(/* translators: %d: kedvezmény % */ sdh_tema__('akár −%d%%'), $max_k)) . '</span>';
        }

        echo '</label>';
    }

    echo '</fieldset>';
}

echo '<ul class="s-racs s-racs--arak s-racs--' . (int) min(4, count($csomagok)) . '" role="list">';

foreach ($csomagok as $k => $c) {
    $kiemelt = !empty($c['kiemelt']);
    $havi    = (int) ($c['havi'] ?? 0);
    $eves    = (int) ($c['eves'] ?? 0);
    $ked     = sdh_tema_eves_kedvezmeny($c);
    $cimid   = sdh_tema_azon('csomag-' . $k);

    echo '<li class="s-kartya s-arkartya' . ($kiemelt ? ' s-arkartya--kiemelt' : '') . '" id="' . esc_attr('csomag-' . sanitize_title((string) $k)) . '" aria-labelledby="' . esc_attr($cimid) . '">';

    if ($kiemelt) {
        echo '<p class="s-arkartya__szalag"><span class="s-pill s-pill--neon">' . esc_html(sdh_tema__('Legnépszerűbb')) . '</span></p>';
    }

    echo '<h3 class="s-arkartya__nev" id="' . esc_attr($cimid) . '">' . esc_html(sdh_tema_t((string) $c['nev'])) . '</h3>';

    if (!empty($c['leiras'])) {
        echo '<p class="s-arkartya__leiras">' . esc_html(sdh_tema_t((string) $c['leiras'])) . '</p>';
    }

    if ($havi <= 0 && $eves <= 0) {
        echo '<div class="s-arkartya__ar"><p class="s-ar"><span class="s-ar__osszeg s-ar__osszeg--szoveg">' . esc_html(sdh_tema__('Egyedi ajánlat')) . '</span></p><p class="s-ar__afa">' . esc_html(sdh_tema__('Az igényeid alapján árazzuk.')) . '</p></div>';
    } else {
        echo '<div class="s-arkartya__ar s-ar--havi">' . sdh_tema_ar_blokk($havi, sdh_tema__('/ hó')) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput

        $megj = $eves > 0 ? sprintf(/* translators: %s: havi átlag */ sdh_tema__('havonta átlagosan %s'), sdh_tema_ft((int) round($eves / 12))) : '';

        if ($ked > 0) {
            $megj .= ' · ' . sprintf(/* translators: %d: % */ sdh_tema__('%d%% megtakarítás'), $ked);
        }

        echo '<div class="s-arkartya__ar s-ar--eves">' . sdh_tema_ar_blokk($eves > 0 ? $eves : $havi * 12, sdh_tema__('/ év'), $megj) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    // Keretek: felhasználó, telephely, példány.
    $sorok = [];
    $fa    = (int) ($c['felhasznalo_alap'] ?? 0);
    $ta    = (int) ($c['telephely_alap'] ?? 0);
    $mp    = (int) ($c['max_peldany'] ?? 0);

    if ($fa > 0) {
        $sorok[] = sprintf(/* translators: %d: felhasználó */ sdh_tema__('%d felhasználó'), $fa) . ((int) ($c['felhasznalo_extra_havi'] ?? 0) > 0 ? ' · ' . sprintf(/* translators: %s: ár */ sdh_tema__('+ %s / fő / hó'), sdh_tema_ft((int) $c['felhasznalo_extra_havi'])) : '');
    }

    if ($ta > 0) {
        $sorok[] = sprintf(/* translators: %d: telephely */ sdh_tema__('%d telephely'), $ta) . ((int) ($c['telephely_extra_havi'] ?? 0) > 0 ? ' · ' . sprintf(/* translators: %s: ár */ sdh_tema__('+ %s / telephely / hó'), sdh_tema_ft((int) $c['telephely_extra_havi'])) : '');
    }

    if ($mp > 0) {
        $sorok[] = sprintf(/* translators: %d: példány */ sdh_tema__('%d telepíthető példány'), $mp);
    }

    $mods = (array) ($c['modulok'] ?? []);

    if (in_array('*', $mods, true)) {
        $sorok[] = sdh_tema__('Minden modul benne van');
        $nevek   = [];
    } else {
        $nevek = [];

        foreach ($modul as $mk => $m) {
            if (sdh_tema_benne($c, $m)) {
                $nevek[] = sdh_tema_t((string) $m['nev']);
            }
        }
    }

    echo '<ul class="s-pipalista">';

    foreach ($sorok as $s) {
        echo '<li>' . sdh_tema_ikon('pipa') . '<span>' . esc_html($s) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    foreach (array_slice($nevek, 0, 7) as $n) {
        echo '<li>' . sdh_tema_ikon('pipa') . '<span>' . esc_html($n) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    if (count($nevek) > 7) {
        echo '<li class="s-pipalista__tobb">' . sdh_tema_ikon('plusz') . '<span>' . esc_html(sprintf(/* translators: %d: db */ sdh_tema__('még %d modul'), count($nevek) - 7)) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    echo '</ul>';

    $gomb = sdh_tema_t((string) ($c['cta'] ?? '')) ?: sdh_tema__('Megveszem');
    $url  = add_query_arg(['csomag' => rawurlencode((string) $k)], sdh_tema_kosar_url('kosar'));

    echo '<a class="s-gomb ' . ($kiemelt ? 's-gomb--elsodleges' : 's-gomb--masodlagos') . ' s-gomb--teljes" href="' . esc_url($url) . '" data-sdh-kosarba="' . sdh_tema_json_attr(['csomag' => (string) $k]) . '" data-ciklus-link>'
        . esc_html($gomb) . '<span class="s-sr"> – ' . esc_html(sdh_tema_t((string) $c['nev'])) . '</span></a>';
    echo '</li>';
}

echo '</ul></div>';

$megj = sdh_tema_mezo($sz, 'megjegyzes');

echo '<p class="s-arak__megj">' . esc_html(sprintf(/* translators: %d: áfa % */ sdh_tema__('Minden ár nettó, a %d%% áfát a számla tartalmazza.'), sdh_tema_afa())) . ($megj !== '' ? ' ' . esc_html(sdh_tema_t($megj)) : '') . '</p>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
