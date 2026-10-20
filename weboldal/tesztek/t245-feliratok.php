<?php
/**
 * t245 – a téma feliratai és a kattintható szerkesztés jelölése (a Stúdió szerződése):
 * - a téma minden sdh_tema__('…') literálja szerepel az inc/feliratok.php jegyzékében (kulcs, alap, csoport, leírás),
 * - sdh_tema_f(): felülírás a `sdh_sales_feliratok` opcióból (HTML nélkül, tokencserével), üres → alap;
 *   sdh_tema__() a jegyzékbeli szövegnél ugyanígy; a JS-nek adott feliratok is,
 * - sdh_tema_szerk(): Stúdió nélkül üres; a jelölés útjai (o:/f:/b:/m:/t:) a szekciókon, listaelemeken, gombokon,
 *   feliratokon, menüpontokon és tartalomtár-elemeken,
 * - a jelölés nem kerül a mount-opciók közé, és a szekciók kimenete jelölés nélkül változatlan.
 */
require __DIR__ . '/_keret.php';

$tema_dir = get_theme_root() . '/sdh-sales-tema';
switch_theme('sdh-sales-tema');

if (!function_exists('sdh_tema_szekcio')) {
    require_once $tema_dir . '/functions.php';
    do_action('after_setup_theme');
}

SDH_Sales_Tartalom::betolt(false);
delete_option('sdh_sales_feliratok');

/* --- Jegyzék ↔ a téma literáljai ---------------------------------------------------------------------------------- */

$j = sdh_tema_feliratok();
T::igaz(count($j) >= 150, 'a jegyzékben legalább 150 felirat van (' . count($j) . ')');

$alapok = [];

foreach ($j as $k => $f) {
    T::igaz(preg_match('/^[a-z0-9]+\.[a-z0-9_]+$/', (string) $k) === 1, 'kulcsforma: ' . $k);
    T::igaz(($f['alap'] ?? '') !== '' && ($f['csoport'] ?? '') !== '' && ($f['leiras'] ?? '') !== '', 'teljes sor: ' . $k);
    $alapok[(string) $f['alap']] = true;
}

$hiany = [];
$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tema_dir, FilesystemIterator::SKIP_DOTS));

foreach ($it as $fajl) {
    if ($fajl->getExtension() !== 'php' || strpos($fajl->getPathname(), '/bin/') !== false || basename($fajl->getPathname()) === 'feliratok.php') {
        continue;
    }

    $t = token_get_all((string) file_get_contents($fajl->getPathname()));

    foreach ($t as $i => $tok) {
        if (!is_array($tok) || $tok[0] !== T_STRING || $tok[1] !== 'sdh_tema__') {
            continue;
        }

        $n = $i + 1;

        while (is_array($t[$n]) && $t[$n][0] === T_WHITESPACE) {
            $n++;
        }

        if ($t[$n] !== '(') {
            continue;
        }

        $n++;

        while (is_array($t[$n]) && $t[$n][0] === T_WHITESPACE) {
            $n++;
        }

        if (is_array($t[$n]) && $t[$n][0] === T_CONSTANT_ENCAPSED_STRING) {
            $s = stripcslashes(substr($t[$n][1], 1, -1));

            if ($t[$n][1][0] === "'") {
                $s = str_replace(["\\'", '\\\\'], ["'", '\\'], substr($t[$n][1], 1, -1));
            }

            if (!isset($alapok[$s])) {
                $hiany[] = basename($fajl->getPathname()) . ': ' . $s;
            }
        }
    }
}

T::egyezik([], $hiany, 'minden sdh_tema__() literál szerepel a feliratjegyzékben');

/* --- Felülírás ------------------------------------------------------------------------------------------------------ */

$k_arak = sdh_tema_felirat_kulcs('Árak');
T::igaz($k_arak !== '', 'az „Árak” felirat kulcsa megvan');
T::egyezik('Árak', sdh_tema__('Árak'), 'felülírás nélkül az alapszöveg');

update_option('sdh_sales_feliratok', [$k_arak => 'Díjak <b>{termeknev}</b>', 'nincs.ilyen' => 'x', sdh_tema_felirat_kulcs('Demó') => '   ']);
T::egyezik('Díjak ' . SDH_Sales::termeknev(), sdh_tema__('Árak'), 'felülírás: HTML nélkül, tokencserével');
T::egyezik('Díjak ' . SDH_Sales::termeknev(), sdh_tema_f($k_arak, 'Árak'), 'sdh_tema_f ugyanazt adja');
T::egyezik('Demó', sdh_tema__('Demó'), 'csak szóköz = alap');
T::egyezik('Ismeretlen szöveg', sdh_tema__('Ismeretlen szöveg'), 'jegyzéken kívüli szöveg változatlan');
T::egyezik('Valami', sdh_tema_f('nincs.ilyen.kulcs', 'Valami'), 'ismeretlen kulcs → alap');

update_option('sdh_sales_feliratok', [sdh_tema_felirat_kulcs('Menü megnyitása') => 'Navigáció']);
wp_scripts()->registered = [];
sdh_tema_szkriptek();
$inline = implode('', (array) (wp_scripts()->get_data('p-tema', 'before') ?: []));
T::tartalmaz($inline, 'Navigáció', 'a JS-nek adott feliratok is felülírhatók');
delete_option('sdh_sales_feliratok');

/* --- Szerkesztési jelölés ------------------------------------------------------------------------------------------- */

$kimenet = static function (array $sz, int $i = 0): string {
    ob_start();
    sdh_tema_szekcio($sz, $i);

    return (string) ob_get_clean();
};

$hero = ['tipus' => 'hero', 'cim' => 'Cím', 'alcim' => 'Alcím', 'eyebrow' => 'Felirat', 'elrendezes' => 'bal',
    'pontok' => [['szoveg' => 'P1'], ['szoveg' => 'P2']], 'cta' => [['szoveg' => 'Gomb', 'url' => '/arak/']], '_post' => 12];

if (!class_exists('SDH_Sales_Studio')) {
    T::egyezik('', sdh_tema_szerk('o:1/0/cim'), 'Stúdió nélkül nincs jelölés');
}

remove_all_filters('sdh_tema_szerk_attr');
add_filter('sdh_tema_szerk_attr', static fn ($a, $ut) => 'data-szerk="' . esc_attr($ut) . '"', 10, 2);

T::egyezik(' data-szerk="o:1/0/cim"', sdh_tema_szerk('o:1/0/cim'), 'a jelölés vezető szóközzel jön');

$h = $kimenet($hero, 3);

foreach (['o:12/3"', 'o:12/3/cim"', 'o:12/3/alcim"', 'o:12/3/eyebrow"', 'o:12/3/pontok/0/szoveg"', 'o:12/3/pontok/1/szoveg"', 'o:12/3/cta/0"'] as $ut) {
    T::tartalmaz($h, 'data-szerk="' . $ut, 'hero: ' . rtrim($ut, '"'));
}

$sz = ['tipus' => 'jellemzok', 'cim' => 'J', 'elemek' => [['cim' => 'A', 'szoveg' => 'a'], 'nem-tomb', ['cim' => 'B', 'szoveg' => 'b']], '_post' => 7];
$h  = $kimenet($sz, 1);
T::tartalmaz($h, 'data-szerk="o:7/1/elemek/2/cim"', 'listaelem: az eredeti sorszám marad (nem-tömb elem kihagyva)');
T::tartalmaz($h, 'data-szerk="o:7/1/elemek/0/szoveg"', 'listaelem szövege');

$sz = ['tipus' => 'cta', 'cim' => 'C', 'szoveg' => 'Szöveg', 'gomb' => ['szoveg' => 'Mehet', 'url' => '/demo/'], '_post' => 7];
$h  = $kimenet($sz, 2);
T::tartalmaz($h, 'data-szerk="o:7/2/szoveg"', 'cta: az alcím a „szoveg” mezőből jön');
T::tartalmaz($h, 'data-szerk="o:7/2/gomb"', 'cta: a gomb-mező');

$h = $kimenet(['tipus' => 'arak', 'cim' => 'Árak', '_post' => 9], 0);
$cs = array_key_first(sdh_tema_csomagok());
T::tartalmaz($h, 'data-szerk="t:csomag/' . $cs . '/nev"', 'árkártya: tartalomtár-út');
T::tartalmaz($h, 'data-szerk="f:' . sdh_tema_felirat_kulcs('Minden ár nettó, a %d%% áfát a számla tartalmazza.') . '"', 'árak megjegyzés: felirat-út');

$h = $kimenet(['tipus' => 'funkcio_racs', 'cim' => 'F', 'db' => 2, '_post' => 9], 1);
T::igaz(preg_match('#data-szerk="t:funkcio/[a-z0-9_-]+/(nev|cim)"#', $h) === 1, 'funkciórács: elemnév');

$h = $kimenet(['tipus' => 'gyik', 'cim' => 'GY', 'db' => 2, '_post' => 9], 1);
T::igaz(preg_match('#data-szerk="t:gyik/[^"/]+/kerdes"#', $h) === 1, 'GYIK: kérdés');

// Fejléc / lábléc: menüpontok, logó, feliratok.
ob_start();
get_header();
get_footer();
$keret = (string) ob_get_clean();

T::tartalmaz($keret, 'data-szerk="m:fo/0"', 'főmenü-pont');
T::tartalmaz($keret, 'data-szerk="b:termeknev"', 'logó / terméknév');
T::tartalmaz($keret, 'data-szerk="f:' . sdh_tema_felirat_kulcs('Süti-beállítások') . '"', 'lábléc felirat');
T::igaz(preg_match('#data-szerk="m:(lablec|jogi)/\d+#', $keret) === 1, 'lábléc menüpont');

// A mount-opciókba nem kerül a belső mező.
$h = $kimenet(['tipus' => 'urlap', 'cim' => 'U', '_post' => 5], 0);
T::nem_tartalmaz(html_entity_decode($h), '"_post"', 'a _post nem kerül a mount-opciókba');

/* --- Jelölés nélkül a kimenet azonos ---------------------------------------------------------------------------- */

remove_all_filters('sdh_tema_szerk_attr');
add_filter('sdh_tema_szerk_attr', static fn () => '', 10, 2);
$tiszta = $kimenet($hero, 3);
T::nem_tartalmaz($tiszta, 'data-szerk', 'üres jelölésnél nincs attribútum');
T::nem_tartalmaz($tiszta, '  >', 'üres jelölésnél nincs fölösleges szóköz');

T::vege('t245 feliratok + szerkesztési jelölés');
