<?php
/** t231 – szekció-sémák: minden §7-es típus megvan, a mezőfajták érvényesek, a tisztítás típushelyes. */
require __DIR__ . '/_keret.php';

$kell = ['hero', 'logo_sav', 'jellemzok', 'funkcio_racs', 'modul_racs', 'iparag_racs', 'arak', 'ar_tablazat', 'kalkulator', 'osszehasonlitas',
    'lepesek', 'szoveg', 'kep_szoveg', 'video', 'velemeny', 'szamok', 'gyik', 'cta', 'urlap', 'foglalas', 'demo_inditas', 'blog_lista', 'tudasbazis',
    'statusz', 'jogi', 'kereso', 'fiok', 'kosar', 'penztar', 'oktatas', 'telepito'];
$t = SDH_Sales_Szekcio::tipusok();
foreach ($kell as $k) {
    T::igaz(isset($t[$k]), 'szekciótípus: ' . $k);
}
$fajtak = ['szoveg', 'hosszu', 'html', 'kep', 'ikon', 'ismetlo', 'valaszto', 'kapcsolo', 'link', 'szin', 'szam', 'url', 'datum', 'tobb', 'egy'];
$rossz = [];
$bejar = static function (array $mezok) use (&$bejar, &$rossz, $fajtak): void {
    foreach ($mezok as $m) {
        if (!in_array($m['t'], $fajtak, true)) {
            $rossz[] = $m['k'] . ':' . $m['t'];
        }
        if ($m['t'] === 'ismetlo') {
            $bejar($m['al']);
        }
    }
};
foreach ($t as $s) {
    $bejar($s['mezok']);
}
foreach (SDH_Sales_Szekcio::elem_semak() as $s) {
    $bejar($s['mezok']);
}
T::egyezik([], $rossz, 'minden mezőfajta ismert');
foreach (['csomag', 'modul', 'szolgaltatas', 'kupon', 'gyik', 'funkcio', 'iparag', 'lecke', 'email', 'konkurens'] as $k) {
    T::igaz(isset(SDH_Sales_Szekcio::elem_semak()[$k]), 'elemséma: ' . $k);
}

$x = SDH_Sales_Szekcio::ervenyesit(['tipus' => 'hero', 'cim' => str_repeat('a', 500), 'elrendezes' => 'rossz', 'kep' => 'javascript:alert(1)',
    'gomb' => ['szoveg' => 'Ok', 'url' => '/arak/', 'uj' => '1'], 'pontok' => [['szoveg' => 'egy'], 'nem tömb'], 'horgony' => 'Árak Lent', 'rejtett' => false]);
T::egyezik(200, mb_strlen($x['cim']), 'max hossz');
T::egyezik('kep_jobb', $x['elrendezes'], 'ismeretlen opció → alap');
T::egyezik('', $x['kep'], 'javascript: kép kiszűrve');
T::egyezik(['szoveg' => 'Ok', 'url' => '/arak/', 'uj' => true], $x['gomb'], 'link');
T::egyezik([['szoveg' => 'egy']], $x['pontok'], 'ismétlő');
T::egyezik('arak-lent', $x['horgony'], 'horgony');
T::igaz(!isset($x['rejtett']), 'hamis rejtett elhagyva');
T::egyezik(null, SDH_Sales_Szekcio::ervenyesit(['tipus' => 'nincsilyen']), 'ismeretlen típus');
$s = SDH_Sales_Szekcio::ervenyesit(['tipus' => 'blog_lista', 'db' => 999]);
T::egyezik(24, $s['db'], 'szám max');
$j = SDH_Sales_Szekcio::ervenyesit(['tipus' => 'jogi', 'hatalyos' => '2026-02-30']);
T::igaz(!isset($j['hatalyos']), 'érvénytelen dátum');
$i = SDH_Sales_Szekcio::ervenyesit(['tipus' => 'jellemzok', 'elemek' => [['ikon' => 'pajzs'], ['ikon' => '<svg>']]]);
T::egyezik(['pajzs', ''], array_column($i['elemek'], 'ikon'), 'ikon fehérlista');
$e = SDH_Sales_Szekcio::elem_tisztit('szolgaltatas', ['kulcs' => 'Telepítés Plusz', 'nev' => 'T', 'ar' => '12.6', 'sla' => ['valaszido_ora' => 5000, 'munkaido' => 'H–P', 'egyeb' => 'x']]);
T::egyezik('telepites-plusz', $e['kulcs'], 'kulcs slug');
T::egyezik(13, $e['ar'], 'egész ár');
T::egyezik(['valaszido_ora' => 720, 'munkaido' => 'H–P', 'egyeb' => 'x'], $e['sla'], 'pontozott mező + ismeretlen testvér');
T::vege('t231 szekció-séma');
