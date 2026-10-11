<?php
/**
 * t31 – gyakori megjegyzések (lista, bővítés, gomb az űrlapon), az ügyfél
 * adatlapjának Munkalapok / Rendelések / Vásárlások füle, és az Arculat
 * új beállításai (logó, jel, név, egyéni kiemelő szín, lekerekítés, oldalsáv).
 */

require __DIR__ . '/_keret.php';

$M = 'SDH_Muhely_Munkalap';
$E = 'SDH_Muhely_Eladas';
$A = 'SDH_Muhely_Arculat';

global $wpdb;

/* --- Gyakori megjegyzések ------------------------------------------------ */

delete_option('sdh_muhely_gyakori_megjegyzesek');

$lista = $M::gyakori_megjegyzesek();
T::egyezik('Az ügyfél a készülékét időpontra hozza', $lista[0] ?? '', 'az első gyakori megjegyzés');
T::igaz(count($lista) >= 3, 'van alaplista');

$r = t_ajax('sdh_muhely_munkalapok_gyakori_megjegyzes', ['nev' => 'Csak hétvégén érhető el']);
T::igaz(!empty($r['success']) && in_array('Csak hétvégén érhető el', (array) $r['data']['lista'], true), 'új megjegyzés a munkalapról felvehető');
T::egyezik('Az ügyfél a készülékét időpontra hozza', $M::gyakori_megjegyzesek()[0], 'bővítés után is az első marad elöl');

$db = count($M::gyakori_megjegyzesek());
t_ajax('sdh_muhely_munkalapok_gyakori_megjegyzes', ['nev' => 'CSAK HÉTVÉGÉN érhető el']);
T::egyezik($db, count($M::gyakori_megjegyzesek()), 'ugyanaz a megjegyzés (kis- / nagybetűtől függetlenül) nem kerül fel kétszer');

$r = t_ajax('sdh_muhely_munkalapok_gyakori_megjegyzes', ['nev' => '   ']);
T::igaz(empty($r['success']), 'üres megjegyzés nem vehető fel');

$u  = t_ugyfel('Megjegyzés Margit');
$e  = t_eszkoz($u, 'Apple', 'iPhone 14');
$id = t_munkalap($u, [$e]);

foreach (['munkalapok' => $id, 'arukiadas' => 0, 'rendelesek' => 0] as $modul => $lap) {
    $urlap = t_ajax('sdh_muhely_' . $modul . '_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $lap])['nyers'];
    T::tartalmaz($urlap, 'data-sdh-gyakori-megj', $modul . ': van „Gyakori megjegyzések” gomb');
    T::tartalmaz($urlap, 'Az ügyfél a készülékét időpontra hozza', $modul . ': a gomb viszi a listát');
}

// A megjegyzés több sorral mentődik (bármelyik állapotban).
foreach (['nyitott', 'elkeszult', 'lezart'] as $allapot) {
    $r = t_ajax('sdh_muhely_munkalapok_ment', [
        'id' => $id, 'allapot' => $allapot, 'ugyfel_id' => $u, 'eszkoz_id' => $e, 'keszult' => current_time('Y-m-d'),
        'lap_afakulcs' => '27', 'fizetett' => '0',
        'ugyfel_megjegyzes' => "Az ügyfél a készülékét időpontra hozza\nSürgős javítás",
    ], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
    T::igaz(!empty($r['success']), 'a megjegyzés menthető „' . $allapot . '” állapotban');
}

T::egyezik(2, count(preg_split('/\R/', (string) t_lap($id)->ugyfel_megjegyzes)), 'a két megjegyzés két sorban mentődött');

/* --- Az ügyfél lapjai ----------------------------------------------------- */

$ment = static fn (array $mezok): array => t_ajax('sdh_muhely_munkalapok_ment', $mezok + [
    'id' => 0, 'tetelek_jelen' => 1, 'allapot' => 'nyitott', 'keszult' => current_time('Y-m-d'), 'lap_afakulcs' => '27', 'fizetett' => '0', 'ugyfel_id' => $u,
], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');

$rend = (int) $ment(['fajta' => 'rendeles', 'rend_szam' => 'UGYF-1', 'tetelek' => ['termek' => [['megnevezes' => 'Rendelt tok', 'brutto_ar' => '4000', 'mennyiseg' => '1']]]])['data']['id'];
$aru  = (int) $ment(['fajta' => 'arukiadas', 'tetelek' => ['termek' => [['megnevezes' => 'Vásárolt kábel', 'brutto_ar' => '2500', 'mennyiseg' => '2']]]])['data']['id'];
$aru2 = (int) $ment(['fajta' => 'arukiadas', 'tetelek' => ['termek' => [['megnevezes' => 'Vásárolt töltő', 'brutto_ar' => '6000', 'mennyiseg' => '1']]]])['data']['id'];

$lapok = $E::ugyfel_lapok($u);
T::egyezik(['javitas', 'rendeles', 'arukiadas'], array_keys($lapok), 'a három fajta külön csoportban');
T::egyezik([1, 1, 2], array_map('count', array_values($lapok)), 'az ügyfél lapjai fajtánként');
T::egyezik($aru2, (int) $lapok['arukiadas'][0]->id, 'a legfrissebb elöl');
T::egyezik([0, 0, 0], array_map('count', array_values($E::ugyfel_lapok(0))), 'ügyfél nélkül üres');

$ful = static function (string $fajta) use ($E, $lapok): string {
    ob_start();
    $E::ugyfel_ful($lapok[$fajta], $fajta);

    return (string) ob_get_clean();
};

$r_html = $ful('rendeles');
T::tartalmaz($r_html, 'data-sdh-lap-nyit="' . $rend . '"', 'a rendelés sora megnyitható');
T::tartalmaz($r_html, 'Rendelt tok', 'látszik, mit rendelt');
T::tartalmaz($r_html, 'UGYF-1', 'látszik a rendelésszám');
T::tartalmaz($r_html, 'Felvéve', 'látszik a rendelés állapota');
T::tartalmaz($r_html, 'még fizetendő', 'látszik a tartozás');

$a_html = $ful('arukiadas');
T::tartalmaz($a_html, 'Vásárolt kábel', 'látszik, mit vásárolt');
T::tartalmaz($a_html, '2 db', 'a vásárlások darabszáma');
T::igaz(preg_match('/11\D{1,6}000/u', $a_html) === 1, 'a vásárlások összege (5000 + 6000)');
T::tartalmaz($ful('javitas'), 'Apple iPhone 14', 'a munkalapnál az eszköz látszik');

$urlap = t_ajax('sdh_muhely_ugyfelek_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $u])['nyers'];
T::tartalmaz($urlap, 'for="ful_lapok_rendeles"', 'az ügyfél adatlapján van Rendelések fül');
T::tartalmaz($urlap, 'for="ful_lapok_arukiadas"', 'és Vásárlások fül');
T::tartalmaz($urlap, 'for="ful_lapok_javitas"', 'és Munkalapok fül');
T::tartalmaz($urlap, 'Vásárolt töltő', 'a fül tartalma az űrlapban van');
T::igaz(preg_match('/for="ful_lapok_arukiadas">\s*Vásárlások\s*<span class="sdh-fulek__db">2<\/span>/u', $urlap) === 1, 'a Vásárlások fül jelvénye: 2');

$uj_urlap = t_ajax('sdh_muhely_ugyfelek_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0])['nyers'];
T::nem_tartalmaz($uj_urlap, 'ful_lapok_', 'új ügyfélnél nincsenek lap-fülek');

$u2  = t_ugyfel('Üres Ubul');
$ures = t_ajax('sdh_muhely_ugyfelek_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $u2])['nyers'];
T::tartalmaz($ures, 'még nincs rendelése', 'rendelés nélküli ügyfélnél üres szöveg');

/* --- Arculat -------------------------------------------------------------- */

$mentett = get_option('sdh_muhely_arculat');
delete_option('sdh_muhely_arculat');

$b = $A::beallitas();
T::egyezik('', $b['logo'], 'alapból nincs logó');
T::egyezik('normal', $b['sarok'], 'alapból normál lekerekítés');
T::egyezik('vilagos', $b['sav'], 'alapból világos oldalsáv');
T::egyezik('', $A::stilus_css(), 'alapbeállításnál nincs felülíró stílus');
T::egyezik(trim($b['jel'] . ' ' . $b['nev']), $A::rendszer_nev(), 'a rendszer neve: jel + név');

update_option('sdh_muhely_arculat', ['logo' => 'https://pelda.hu/logo.png', 'jel' => 'AB', 'nev' => 'Szerviz', 'szin' => 'egyeni', 'szin_egyeni' => '#0A84FF', 'sarok' => 'kerek', 'sav' => 'szines']);

$b   = $A::beallitas();
$css = $A::stilus_css();

T::egyezik('AB Szerviz', $A::rendszer_nev(), 'a saját név érvényesül');
T::egyezik('#0a84ff', $b['szin_egyeni'], 'a színkód kisbetűs, érvényes');
T::egyezik('egyeni', $b['szin'], 'az egyéni szín a kiemelő szín');
T::tartalmaz($css, ':root[data-accent="egyeni"]{--sdh-accent:#0a84ff', 'az egyéni kiemelő szín változója');
T::tartalmaz($css, '--sdh-accent-halvany:rgba(10,132,255,.1)', 'a halvány változat');
T::tartalmaz($css, '--sdh-accent-felirat:#ffffff', 'sötét színen fehér a felirat');
T::tartalmaz($css, '--sdh-r:13px', 'a kerek lekerekítés');

update_option('sdh_muhely_arculat', ['szin' => 'egyeni', 'szin_egyeni' => '#ffe14d']);
T::tartalmaz($A::stilus_css(), '--sdh-accent-felirat:#16191d', 'világos színen sötét a felirat');

update_option('sdh_muhely_arculat', ['szin' => 'egyeni', 'szin_egyeni' => 'nem-szin;}body{display:none', 'sarok' => 'x', 'sav' => 'y']);
$b = $A::beallitas();
T::egyezik('', $b['szin_egyeni'], 'érvénytelen színkód eldobódik');
T::egyezik('', $b['szin'], 'érvényes színkód nélkül nincs egyéni kiemelő szín');
T::egyezik(['normal', 'vilagos'], [$b['sarok'], $b['sav']], 'ismeretlen érték helyett az alap');
T::egyezik('', $A::stilus_css(), 'érvénytelen bemenetből nem lesz stílus');

// A műhely-felület kerete: logó, név, oldalsáv-stílus.
update_option('sdh_muhely_arculat', ['logo' => 'https://pelda.hu/logo.png', 'jel' => 'AB', 'nev' => 'Szerviz', 'szin' => 'egyeni', 'szin_egyeni' => '#0a84ff', 'sav' => 'sotet']);

ob_start();
SDH_Muhely_Frontend::keret('Teszt', 'attekintes', static function (): void {
    echo 'TARTALOM';
});
$keret = (string) ob_get_clean();

T::tartalmaz($keret, '<title>Teszt – AB Szerviz</title>', 'a címsorban a saját név');
T::tartalmaz($keret, 'class="sdh-sav__logo" src="https://pelda.hu/logo.png"', 'az oldalsávban a logó');
T::nem_tartalmaz($keret, 'class="sdh-sav__jel"', 'logóval nincs betűjel');
T::tartalmaz($keret, 'data-sdh-sav-stilus="sotet"', 'az oldalsáv stílusa a body-n');
T::tartalmaz($keret, '<style id="sdh-arculat">', 'a felülíró stílus a fejben');
T::tartalmaz($keret, 'data-ertek="egyeni"', 'a profilmenüben választható az egyéni szín');

update_option('sdh_muhely_arculat', ['jel' => 'AB', 'nev' => 'Szerviz']);
ob_start();
SDH_Muhely_Frontend::keret('Teszt', 'attekintes', static function (): void {
});
$keret = (string) ob_get_clean();
T::tartalmaz($keret, '<span class="sdh-sav__jel">AB</span>', 'logó nélkül a jel látszik');
T::nem_tartalmaz($keret, 'data-ertek="egyeni"', 'egyéni szín nélkül nincs ilyen gomb');

if ($mentett === false) {
    delete_option('sdh_muhely_arculat');
} else {
    update_option('sdh_muhely_arculat', $mentett);
}

T::vege('t31 megjegyzés, ügyfél lapjai, arculat');
