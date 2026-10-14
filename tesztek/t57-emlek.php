<?php
/**
 * t57 – 0.63.0: mezőmemória és a Leggyakoribb Számla tételek listája.
 *
 * - Rögzítés: ugyanaz az érték egyszer (a db nő), kis-nagybetűtől függetlenül;
 *   titkos mező (jelszó, kulcs, kód, minta) és kártyaszám-gyanús érték soha.
 * - Lekérés: a leggyakoribb elöl; a „tetelnev" kulcson elöl a gyakori tételek,
 *   utána a munkalapok tételnevei.
 * - Törlés egy értékre, kikapcsolt memória nem rögzít.
 * - A Leggyakoribb lista alapja, mentése (tisztítás, duplikátum), AJAX.
 * - A számla ablaka: Gyakori tételek gomb, tételnév-memória, és a fogaskerék
 *   „munkalap tételei a listában" kapcsolója (rejtett, pipa nélkül).
 * - A memória táblája nem kerül a változásnaplóba.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$EM = 'SDH_Muhely_Emlek';
$t  = SDH_Muhely_Schema::tabla('mezo_emlek');

T::igaz((bool) $wpdb->get_var("SHOW TABLES LIKE '{$t}'") || (int) $wpdb->get_var("SELECT COUNT(*) FROM sqlite_master WHERE name = '{$t}'") === 1, 'a mezőmemória táblája létezik');

delete_option($EM::OPT);
delete_option($EM::OPT_GYAKORI);
$EM::urit();

/* --- 1. Rögzítés és szűrés ---------------------------------------------------- */

T::igaz($EM::rogzit('szamla:vevo[cim]', 'Piac utca 20.'), 'sima érték rögzül');
$EM::rogzit('szamla:vevo[cim]', 'piac utca 20.');
$EM::rogzit('szamla:vevo[cim]', 'Kossuth tér 1.');
T::egyezik(2, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE kulcs = 'szamla:vevo[cim]'"), 'ugyanaz az érték (kis-nagybetű) egyszer szerepel');
T::egyezik(2, (int) $wpdb->get_var("SELECT db FROM {$t} WHERE ertek = 'piac utca 20.'"), 'a darabszám nő, az érték a legutóbb írt alak');

$e = $EM::ertekek('szamla:vevo[cim]');
T::egyezik('piac utca 20.', $e[0]['e'] ?? '', 'a leggyakoribb áll elöl');

T::igaz(!$EM::rogzit('levelezes:jelszo', 'titkos123'), 'jelszó nem rögzül');
T::igaz(!$EM::rogzit('beallitasok:api_kulcs', 'sk-abcdef'), 'kulcs nem rögzül');
T::igaz(!$EM::rogzit('eszkozok:zarkod', '1234'), 'zárkód nem rögzül');
T::igaz(!$EM::rogzit('eszkozok:minta', '1-2-3'), 'feloldó minta nem rögzül');
T::igaz(!$EM::rogzit('penztar:megjegyzes', '4111 1111 1111 1111'), 'kártyaszám-gyanús érték nem rögzül');
T::igaz(!$EM::rogzit('x:nev', 'a'), 'egy betű nem rögzül');
T::igaz($EM::rogzit('munkalapok:napi_max', 'abc'), 'a „napi" szó nem téveszti meg a titokszűrőt');
T::egyezik([], $EM::ertekek('levelezes:jelszo'), 'titkos kulcsra nem jön semmi');

T::egyezik('uj[][megnevezes]', $EM::kulcs_tisztit('uj[12][megnevezes]'), 'a sorszám nélküli kulcs');

$EM::torol('szamla:vevo[cim]', 'Kossuth TÉR 1.');
T::egyezik(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE kulcs = 'szamla:vevo[cim]'"), 'egy érték törölhető a felajánlásból');

update_option($EM::OPT, ['be' => false]);
T::igaz(!$EM::rogzit('szamla:vevo[cim]', 'Új utca 5.'), 'kikapcsolt memória nem rögzít');
delete_option($EM::OPT);

/* --- 2. AJAX ---------------------------------------------------------------- */

$r = t_ajax('sdh_muhely_emlek_ment', ['tetelek' => ["kereses:q\tSamsung A50", "kereses:q\tSamsung A50", "levelezes:jelszo\tx1234567"]]);
T::egyezik(2, (int) ($r['data']['db'] ?? -1), 'az AJAX-mentés a titkosat kihagyja');
$r = t_ajax('sdh_muhely_emlek_ker', ['kulcs' => 'kereses:q']);
T::egyezik('Samsung A50', $r['data']['ertekek'][0]['e'] ?? '', 'az AJAX-lekérés visszaadja');

/* --- 3. Leggyakoribb Számla tételek listája --------------------------------- */

T::egyezik(['Telefon szerviz szolgáltatás', 'TV szerviz szolgáltatás', 'Számítógép szerviz szolgáltatás', 'Számítógép alaplapi javítás'], $EM::gyakori(), 'az alaplista a kért négy tétel, ebben a sorrendben');

$r = t_ajax('sdh_muhely_szamla_gyakori_ment', ['lista' => ['Telefon szerviz szolgáltatás', ' telefon  szerviz szolgáltatás ', 'Laptop kijelzőcsere', '']]);
T::egyezik(['Telefon szerviz szolgáltatás', 'Laptop kijelzőcsere'], $EM::gyakori(), 'mentéskor tisztul (dupla, üres sor ki)');

$u  = t_ugyfel('Emlék Elemér');
$e1 = t_eszkoz($u, 'Samsung', 'SM-A505FN/DS');
$id = t_munkalap($u, [$e1]);
t_tetel($id, 'Akkumulátorcsere', 12000, 0);
SDH_Muhely_Tetel::ujraszamol($id);
$EM::rogzit($EM::TETELNEV, 'Adatmentés pendrive-ra');

$tn = array_map(static fn (array $x): string => $x['e'], $EM::ertekek($EM::TETELNEV));
T::egyezik('Telefon szerviz szolgáltatás', $tn[0] ?? '', 'a tételnév-felajánlásban elöl a gyakori tételek');
T::igaz(in_array('Adatmentés pendrive-ra', $tn, true), 'a kézzel írt tételnév is jön');
T::igaz(in_array('Akkumulátorcsere', $tn, true), 'a munkalapok tételnevei is jönnek');
T::igaz(!empty($EM::ertekek($EM::TETELNEV)[0]['f']), 'a gyakori tétel meg van jelölve');

/* --- 4. A számla ablaka -------------------------------------------------------- */

$UB = 'SDH_Muhely_Urlap_Beallitas';
delete_option($UB::OPT);
$UB::urit();

$sz = t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id, 'sorozat' => 'helyi'])['nyers'];
T::tartalmaz($sz, 'data-sdh-szamla-gyakori', 'a számla ablakában ott a Gyakori tételek gomb');
T::tartalmaz($sz, 'Laptop kijelzőcsere', 'a gomb a mentett listát viszi');
T::tartalmaz($sz, 'data-sdh-emlek="tetelnev"', 'a kézi tétel neve a tételnév-memóriát használja');
T::igaz(preg_match('/<tr class="[^"]*" data-sdh-szamla-sor data-sdh-ub="munkalap_tetelek">/', $sz) === 1, 'a munkalap tételei kapcsolhatók');
T::egyezik(1, preg_match_all('/data-sdh-szamla-tetel\b[^>]*checked/s', $sz), 'alapból a munkalap tétele kipipálva');

$elemek = [];
foreach ($UB::urlapok()['elokeszito']['csoportok'] as $cs) {
    $elemek = array_merge($elemek, array_keys($cs));
}
T::igaz(in_array('munkalap_tetelek', $elemek, true) && in_array('gyakori_tetelek', $elemek, true), 'a fogaskerékben ott a két új kapcsoló');

t_ajax('sdh_muhely_urlap_beall_ment', ['urlap' => 'elokeszito', 'be' => array_values(array_diff($elemek, ['munkalap_tetelek']))]);
$UB::urit();

$sz = t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id, 'sorozat' => 'helyi'])['nyers'];
T::igaz(preg_match('/data-sdh-szamla-sor data-sdh-ub="munkalap_tetelek" data-sdh-ub-ki/', $sz) === 1, 'kikapcsolva a munkalap tételei rejtettek');
T::egyezik(0, preg_match_all('/data-sdh-szamla-tetel\b[^>]*checked/s', $sz), 'és egy sincs kipipálva (nem kerülnek a nyomtatványra)');
T::igaz(preg_match('/<tr data-sdh-szamla-ures>/', $sz) === 1, 'a „nincs tétel" sor látszik');

$sz2 = t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id, 'sorozat' => 'fo'])['nyers'];
T::egyezik(1, preg_match_all('/data-sdh-szamla-tetel\b[^>]*checked/s', $sz2), 'a Számlázz.hu ablakra nincs hatással');

/* --- 5. Munkalap: kikapcsolt eszközcsatolásnál a „további eszközök" doboz sem látszik --- */

t_ajax('sdh_muhely_urlap_beall_ment', ['urlap' => 'javitas', 'be' => array_values(array_diff(array_merge(...array_map('array_keys', array_values($UB::urlapok()['javitas']['csoportok']))), ['eszkoz_csatolas']))]);
$UB::urit();
$h = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
T::igaz(preg_match('/data-sdh-tovabbi-ures[^>]*data-sdh-ub="eszkoz_csatolas" data-sdh-ub-ki/', $h) === 1, 'a „további eszközök itt jelennek meg" doboz is rejtett');

/* --- 6. Változásnapló, Beállítások ------------------------------------------- */

T::igaz(!SDH_Muhely_Valtozasnaplo::naplozott('mezo_emlek'), 'a memória nem kerül a változásnaplóba');

ob_start();
$UB::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::tartalmaz($doboz, 'Mezőmemória', 'a Beállítások › Űrlapok lapfülön ott a Mezőmemória');
T::tartalmaz($doboz, 'Leggyakoribb Számla tételek listája', 'és a Leggyakoribb Számla tételek listája');

$_POST = wp_slash(['emlek_jelen' => '1', 'emlek_be' => '1', 'emlek_urit' => '1', 'szamla_gyakori' => "A tétel\nB tétel\nA tétel"]);
$UB::beallitas_mentes();
$_POST = [];
T::egyezik(0, $EM::db(), 'a „Mentéskor töröld" kiüríti a memóriát');
T::egyezik(['A tétel', 'B tétel'], $EM::gyakori(), 'a lapfülről is menthető a lista');

delete_option($EM::OPT_GYAKORI);
delete_option($UB::OPT);
$UB::urit();

T::vege('t57 mezőmemória, Leggyakoribb Számla tételek, munkalap-tételek elrejtése');
