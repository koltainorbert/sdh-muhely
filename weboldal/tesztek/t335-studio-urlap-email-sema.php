<?php
/**
 * t335 – Űrlapmezők felülírása (felirat, kötelező, látszik; a név és az e-mail zárolt), export / import;
 * e-mail-sablon előnézete még nem mentett adattal (szűrve) és próbalevél; a telepítési útmutató sémája és a
 * „lista” mezőfajta tisztítása; a menüpontos és a tartalomtáras elem-szerkesztés útjai.
 */
require __DIR__ . '/_keret.php';

delete_option('sdh_sales_urlap_felulir');

/* Űrlapok */
$r = t_rest('GET', '/admin/urlapok')->get_data();
T::igaz(isset($r['tipusok']['kapcsolat'], $r['tipusok']['ajanlat'], $r['tipusok']['partner']), 'három űrlap');
$v = t_rest('POST', '/admin/urlapok', ['tipusok' => ['kapcsolat' => [
    'nev'     => ['cimke' => 'Teljes név', 'kotelezo' => false, 'latszik' => false],
    'telefon' => ['cimke' => 'Mobil <b>szám</b>', 'kotelezo' => true],
    'ceg'     => ['latszik' => false],
    'nincs'   => ['cimke' => 'x'],
]]]);
T::egyezik(200, $v->get_status(), 'mentés');
$m = array_column(SDH_Sales_Urlap::mezok('kapcsolat'), null, 'nev');
T::egyezik('Teljes név', $m['nev']['cimke'] ?? '', 'zárolt mező felirata módosítható');
T::igaz(isset($m['nev']) && $m['nev']['kotelezo'], 'a név nem rejthető és kötelező marad');
T::egyezik('Mobil szám', $m['telefon']['cimke'], 'felirat tisztítva');
T::igaz($m['telefon']['kotelezo'], 'telefon kötelező lett');
T::igaz(!isset($m['ceg']), 'a cég mező rejtve');
T::igaz(!isset($m['nincs']), 'ismeretlen mező nem kerül be');
T::igaz(isset(array_column(SDH_Sales_Urlap::mezok('ajanlat'), null, 'nev')['ceg']), 'a másik űrlap érintetlen');
$e = SDH_Sales_Urlap::ellenoriz(SDH_Sales_Urlap::mezok('kapcsolat'), ['nev' => 'A', 'email' => 'a@example.com', 'uzenet' => 'x', 'targy' => 'Egyéb']);
T::igaz(isset($e['hibak']['telefon']), 'a kötelezővé tett mező hiánya hiba');
$ex = SDH_Sales_Exportimport::export();
T::igaz(isset($ex['urlapok']['kapcsolat']['telefon']), 'export tartalmazza');
delete_option('sdh_sales_urlap_felulir');
SDH_Sales_Exportimport::import($ex);
T::igaz(!isset(array_column(SDH_Sales_Urlap::mezok('kapcsolat'), null, 'ceg')['ceg']), 'import visszaállítja');
$n = SDH_Sales_Naplo::lista(['ref_tipus' => 'urlapok', 'db' => 1])['sorok'][0] ?? null;
T::igaz($n !== null, 'naplózva');
delete_option('sdh_sales_urlap_felulir');

/* E-mail előnézet és próbalevél */
$k = (string) array_key_first(SDH_Sales_Email::sablonok());
if ($k !== '') {
    $p = t_rest('POST', '/admin/email/elonezet', ['kulcs' => $k]);
    T::egyezik(200, $p->get_status(), 'mentett sablon előnézete');
    T::igaz(strlen($p->get_data()['html']) > 200, 'teljes levél-HTML');
    $p = t_rest('POST', '/admin/email/elonezet', ['kulcs' => $k, 'adat' => ['kulcs' => $k, 'targy' => 'Próba {nev}', 'html' => '<p>Szia {nev}!</p><script>alert(1)</script><a href="javascript:x">l</a>']]);
    $d = $p->get_data();
    T::egyezik('Próba Minta Péter', $d['targy'], 'mentetlen sablon, mintaadattal');
    T::igaz(strpos($d['html'], 'Szia Minta Péter!') !== false && strpos($d['html'], '<script') === false && strpos($d['html'], 'javascript:') === false, 'előnézet tisztítva');
    T::egyezik(null, SDH_Sales_Email::$elonezet, 'az előnézet után a felülírás visszaáll');
    $GLOBALS['sdh_t_levelek'] = [];
    $q = t_rest('POST', '/admin/email/proba', ['kulcs' => $k, 'adat' => ['kulcs' => $k, 'targy' => 'Próbalevél', 'html' => '<p>Teszt</p>']]);
    T::igaz(in_array($q->get_status(), [200, 409, 502], true), 'próbalevél: ' . $q->get_status());
    if ($q->get_status() === 200) {
        T::igaz(count($GLOBALS['sdh_t_levelek']) === 1, 'egy levél ment ki (a teszt elfogja)');
    }
}
T::egyezik(400, t_rest('POST', '/admin/email/elonezet', [])->get_status(), 'sablon nélkül 400');

/* Telepítési útmutató sémája, „lista” mező */
$s = SDH_Sales_Szekcio::elem_semak()['telepites'] ?? null;
T::igaz($s !== null, 'a telepítési lépések szerkeszthetők');
$t = SDH_Sales_Szekcio::elem_tisztit('telepites', ['kulcs' => 'Lépés Egy', 'cim' => 'Cím', 'ellenorzes' => ['Egy', ' ', '<b>Kettő</b>', str_repeat('x', 400)], 'kovetelmenyek' => ['marad'], 'kovetelmeny_tabla' => [['mi' => 'PHP', 'mennyi' => '8.1']]]);
T::egyezik('lepes-egy', $t['kulcs'], 'kulcs tisztítva');
T::egyezik(['Egy', 'Kettő', str_repeat('x', 300)], $t['ellenorzes'], 'lista: üres ki, HTML ki, hossz 300');
T::egyezik(['marad'], $t['kovetelmenyek'], 'sémán kívüli mező megmarad');
T::egyezik('PHP', $t['kovetelmeny_tabla'][0]['mi'], 'követelménytábla');
$sz = SDH_Sales_Szekcio::mezo_tisztit(['k' => 'l', 't' => 'lista', 'max' => 2], "a\nb\nc");
T::egyezik(['a', 'b'], $sz, 'lista szövegből, max');

/* A videó- és a médiamezők jelölése a sémában */
$video = array_column(SDH_Sales_Szekcio::tipusok()['video']['mezok'], null, 'k')['url'];
T::egyezik('video', $video['media'] ?? '', 'a videó címe a médiatárból is választható');

T::vege('t335 űrlapok, e-mail, séma');
