<?php
/** t234 – export → import ugyanazt adja (körút), titok nincs az exportban, import előtti mentés készül, verzió-ellenőrzés. */
require __DIR__ . '/_keret.php';

SDH_Sales::b_ment(['termeknev' => 'T234 Termék', 'ceg.nev' => 'Próba Kft.', 'afa' => 27]);
SDH_Sales::titkos_ment('szikra_kulcs', 'sk-titok-t234');
SDH_Sales_Tartalom::ment('csomag', 't234', ['kulcs' => 't234', 'nev' => 'Pro', 'havi' => 9900, 'modulok' => ['penztar']]);
SDH_Sales_Tartalom::ment('menu', 'fo', ['kulcs' => 'fo', 'elemek' => [['cim' => 'Árak', 'oldal' => 'arak', 'gyerekek' => [['cim' => 'Csomagok', 'url' => '/arak/#csomagok']]]]]);
$p = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'T234', 'post_name' => 't234']);
SDH_Sales_Tartalom::szekciok_ment($p, [['tipus' => 'hero', 'cim' => 'Szia', 'gomb' => ['szoveg' => 'Go', 'url' => '/x/', 'uj' => false]]]);
SDH_Sales_Exportimport::seo_ir($p, ['title' => 'SEO T', 'description' => 'SEO D', 'kulcsszo' => 'k']);

$e1 = SDH_Sales_Exportimport::export();
$j1 = (string) wp_json_encode($e1);
T::nem_tartalmaz($j1, 'sk-titok-t234', 'titok nincs az exportban');
T::egyezik('portal-export', $e1['formatum'], 'formátum');
T::nem_tartalmaz($j1 . (string) wp_json_encode(t_rest('GET', '/admin/export')->get_headers()), 'sdh-sales-export', 'semleges név');

// Változtatás, majd import → az export ugyanaz, mint előtte.
SDH_Sales::b_ment(['termeknev' => 'Más']);
SDH_Sales_Tartalom::ment('csomag', 't234', ['kulcs' => 't234', 'nev' => 'Átírt', 'havi' => 1]);
SDH_Sales_Tartalom::szekciok_ment($p, [['tipus' => 'cta', 'cim' => 'Más']]);
SDH_Sales_Exportimport::seo_ir($p, ['title' => 'Más', 'description' => '', 'kulcsszo' => '']);
$elo = count(SDH_Sales_Exportimport::mentesek());
$r = t_rest('POST', '/admin/import', ['csomag' => $e1])->get_data();
T::igaz(!empty($r['ok']), 'import ok');
T::egyezik($elo + 1, count(SDH_Sales_Exportimport::mentesek()), 'import előtti mentés készült');
T::igaz(SDH_Sales_Exportimport::mentes($r['mentes'])['beallitas']['termeknev'] === 'Más', 'a mentés az import előtti állapot');
$e2 = SDH_Sales_Exportimport::export();
unset($e1['ido'], $e2['ido']);
T::egyezik($e1['beallitas'], $e2['beallitas'], 'beállítások azonosak');
T::egyezik($e1['tartalom'], $e2['tartalom'], 'tartalom azonos');
T::egyezik($e1['oldalak'], $e2['oldalak'], 'oldalak azonosak');
T::egyezik('sk-titok-t234', SDH_Sales::titkos('szikra_kulcs'), 'a titok érintetlen');
// Másodszori import: nincs változás, nincs új naplósor a tartalomra.
$n = SDH_Sales_Naplo::lista(['tipus' => 'tartalom'])['ossz'];
$r = SDH_Sales_Exportimport::import(SDH_Sales_Exportimport::export());
T::egyezik(['beallitas' => 0, 'tartalom' => 0, 'oldal' => 0], $r['db'], 'idempotens import');
T::egyezik($n, SDH_Sales_Naplo::lista(['tipus' => 'tartalom'])['ossz'], 'nincs fölösleges napló');
// Új oldal az importból (szülővel).
$x = SDH_Sales_Exportimport::export();
$x['oldalak'][] = ['ut' => 't234/al', 'cim' => 'Aloldal', 'statusz' => 'draft', 'szekciok' => [['tipus' => 'szoveg', 'html' => '<p>A</p><script>x</script>']], 'seo' => []];
SDH_Sales_Exportimport::import($x);
$al = get_page_by_path('t234/al');
T::igaz($al && $al->post_parent === $p, 'aloldal létrejött a szülő alatt');
T::nem_tartalmaz((string) get_post_meta($al->ID, SDH_Sales_Tartalom::META, true), '<script', 'import tisztít');
// Verzió
$v = $e1;
$v['verzio'] = '2.0.0';
T::igaz(SDH_Sales_Exportimport::ellenoriz($v) !== [], 'más fő verzió elutasítva');
$v = $e1;
$v['db'] = '9.9.9';
T::igaz(SDH_Sales_Exportimport::ellenoriz($v) !== [], 'újabb séma elutasítva');
T::igaz(SDH_Sales_Exportimport::ellenoriz(['formatum' => 'x']) !== [], 'idegen fájl');
T::egyezik(422, t_rest('POST', '/admin/import', ['csomag' => ['formatum' => 'x']])->get_status(), 'REST 422');
$v = $e1;
$v['beallitas']['szamlazz.agent_kulcs'] = 'nyilt';
$v['beallitas']['rejtes.belepo'] = 'nem-jon-at';
SDH_Sales_Exportimport::import($v);
T::egyezik(null, SDH_Sales::b('szamlazz.agent_kulcs'), 'titok-kulcs importból nem jön');
T::vege('t234 export-import');
