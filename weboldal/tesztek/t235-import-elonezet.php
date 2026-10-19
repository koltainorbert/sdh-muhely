<?php
/** t235 – import előnézete: új / módosuló / változatlan elemek, beállítás-diff, oldalak; az előnézet nem ír. */
require __DIR__ . '/_keret.php';

SDH_Sales_Tartalom::ment('gyik', 't235-a', ['kulcs' => 't235-a', 'kerdes' => 'A?', 'valasz' => '<p>A</p>']);
SDH_Sales_Tartalom::ment('gyik', 't235-b', ['kulcs' => 't235-b', 'kerdes' => 'B?', 'valasz' => '<p>B</p>']);
$p = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'T235', 'post_name' => 't235']);
SDH_Sales_Tartalom::szekciok_ment($p, [['tipus' => 'hero', 'cim' => 'X']]);
$e = SDH_Sales_Exportimport::export();

foreach ($e['tartalom'] as &$t) {
    if ($t['kulcs'] === 't235-b') {
        $t['adat']['kerdes'] = 'B módosult?';
        $t['cim'] = 'B módosult?';
    }
}
unset($t);
$e['tartalom'][] = ['tipus' => 'gyik', 'kulcs' => 't235-c', 'nyelv' => 'hu', 'cim' => 'C?', 'adat' => ['kulcs' => 't235-c', 'kerdes' => 'C?'], 'sorrend' => 9, 'aktiv' => true];
$e['beallitas']['szlogen'] = 'Új szlogen';
foreach ($e['oldalak'] as &$o) {
    if ($o['ut'] === 't235') {
        $o['szekciok'][0]['cim'] = 'Y';
    }
}
unset($o);
$e['oldalak'][] = ['ut' => 't235-uj', 'cim' => 'Új', 'szekciok' => [['tipus' => 'cta', 'cim' => 'Z']], 'seo' => []];

$naplo = SDH_Sales_Naplo::lista()['ossz'];
$r = t_rest('POST', '/admin/import/elonezet', ['csomag' => $e])->get_data();
T::igaz($r['ok'], 'előnézet ok');
T::egyezik(['gyik / t235-c'], array_column($r['tartalom']['uj'], 'nev'), 'új elem');
T::egyezik(['gyik / t235-b'], array_column($r['tartalom']['modosul'], 'nev'), 'módosuló elem');
T::igaz(in_array('kerdes', $r['tartalom']['modosul'][0]['mezok'], true), 'a módosult mező neve');
T::igaz($r['tartalom']['valtozatlan'] >= 1, 'változatlan számolva');
T::egyezik(['szlogen'], array_column($r['beallitas'], 'kulcs'), 'beállítás-diff');
T::egyezik('Új szlogen', $r['beallitas'][0]['uj'], 'új érték');
T::egyezik(['t235-uj'], array_column($r['oldalak']['uj'], 'ut'), 'új oldal');
T::egyezik([['ut' => 't235', 'cim' => 'T235', 'mezok' => ['szekciók']]], $r['oldalak']['modosul'], 'módosuló oldal');
T::egyezik($naplo, SDH_Sales_Naplo::lista()['ossz'], 'az előnézet nem írt');
T::egyezik(null, SDH_Sales_Tartalom::egy('gyik', 't235-c'), 'nincs új elem');
$h = t_rest('POST', '/admin/import/elonezet', ['csomag' => '{"formatum":"x"}'])->get_data();
T::igaz(!$h['ok'] && $h['hibak'] !== [], 'hibás fájl: hibák');
$u = SDH_Sales_Exportimport::elonezet(SDH_Sales_Exportimport::export());
T::igaz($u['beallitas'] === [] && $u['tartalom']['uj'] === [] && $u['tartalom']['modosul'] === [] && $u['oldalak']['modosul'] === [], 'saját export: nincs változás');
T::vege('t235 import-előnézet');
