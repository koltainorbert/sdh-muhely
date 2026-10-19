<?php
/** t233 – szekciók mentése REST-en → napló → visszaállítás REST-en; kötelező mező; elem és lead visszaállítása. */
require __DIR__ . '/_keret.php';

$p = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 't233', 'post_name' => 't233']);
$a = [['tipus' => 'hero', 'cim' => 'Első'], ['tipus' => 'cta', 'cim' => 'Hívás']];
$b = [['tipus' => 'cta', 'cim' => 'Hívás'], ['tipus' => 'szoveg', 'cim' => 'Új', 'html' => '<p>x</p>']];
T::egyezik(200, t_rest('POST', '/admin/oldal/' . $p, ['szekciok' => $a])->get_status(), 'A mentve');
T::egyezik(200, t_rest('POST', '/admin/oldal/' . $p, ['szekciok' => $b])->get_status(), 'B mentve');
T::egyezik('szoveg', SDH_Sales_Tartalom::szekciok($p)[1]['tipus'], 'B él');
$n = SDH_Sales_Naplo::lista(['ref_tipus' => 'szekciok', 'ref_id' => $p]);
$v = t_rest('POST', '/admin/naplo/' . $n['sorok'][0]['id'] . '/visszaallit');
T::egyezik(200, $v->get_status(), 'visszaállítás REST');
T::egyezik(['hero', 'cta'], array_column(SDH_Sales_Tartalom::szekciok($p), 'tipus'), 'A visszaállt');
$d = t_rest('GET', '/admin/naplo/' . $n['sorok'][0]['id'])->get_data();
T::egyezik('Első', $d['elotte'][0]['cim'] ?? '', 'diff előtte');
T::egyezik(422, t_rest('POST', '/admin/oldal/' . $p, ['szekciok' => [['tipus' => 'cta', 'cim' => '']]])->get_status(), 'kötelező mező 422');
T::egyezik(['hero', 'cta'], array_column(SDH_Sales_Tartalom::szekciok($p), 'tipus'), 'hibás mentés nem írt');
// SEO
t_rest('POST', '/admin/oldal/' . $p, ['seo' => ['title' => 'Cím <b>x</b>', 'description' => 'Leírás', 'kulcsszo' => 'kulcs']]);
T::egyezik('Cím x', get_post_meta($p, '_sdh_seo_title', true), 'SEO title tisztítva');
t_rest('POST', '/admin/oldal/' . $p, ['seo' => ['title' => '', 'description' => 'Leírás', 'kulcsszo' => '']]);
T::egyezik('', get_post_meta($p, '_sdh_seo_title', true), 'SEO title törölhető');

// Elem: mentés, módosítás, kulcs nem változik, visszaállítás.
t_rest('POST', '/admin/elem', ['tipus' => 'csomag', 'adat' => ['kulcs' => 't233', 'nev' => 'Egy', 'havi' => 1000]]);
T::egyezik(409, t_rest('POST', '/admin/elem', ['tipus' => 'csomag', 'adat' => ['kulcs' => 't233', 'nev' => 'Dupla']])->get_status(), 'ütköző kulcs');
t_rest('POST', '/admin/elem', ['tipus' => 'csomag', 'eredeti' => 't233', 'adat' => ['kulcs' => 'mas-kulcs', 'nev' => 'Kettő', 'havi' => 2000]]);
T::egyezik('Kettő', SDH_Sales_Tartalom::egy('csomag', 't233')['adat']['nev'], 'módosítva, kulcs megmaradt');
T::egyezik(null, SDH_Sales_Tartalom::egy('csomag', 'mas-kulcs'), 'nincs új kulcs');
$n = SDH_Sales_Naplo::lista(['ref_tipus' => 'tartalom']);
t_rest('POST', '/admin/naplo/' . $n['sorok'][0]['id'] . '/visszaallit');
T::egyezik('Egy', SDH_Sales_Tartalom::egy('csomag', 't233')['adat']['nev'], 'elem visszaállt');
// Sorrend
t_rest('POST', '/admin/elem', ['tipus' => 'csomag', 'adat' => ['kulcs' => 't233b', 'nev' => 'B']]);
t_rest('POST', '/admin/elemek/sorrend', ['tipus' => 'csomag', 'kulcsok' => ['t233b', 't233']]);
T::igaz(SDH_Sales_Tartalom::egy('csomag', 't233b')['sorrend'] < SDH_Sales_Tartalom::egy('csomag', 't233')['sorrend'], 'sorrend');

// Lead állapot + visszaállítás
global $wpdb;
$wpdb->insert(SDH_Sales_Schema::t('lead'), ['forras' => 'kapcsolat', 'email' => 'a@example.com', 'adat' => '{"nev":"A"}', 'allapot' => 'uj', 'ido' => current_time('mysql', true)]);
$lid = (int) $wpdb->insert_id;
T::egyezik(200, t_rest('POST', '/admin/lead/' . $lid, ['allapot' => 'nyert', 'megjegyzes' => 'jó'])->get_status(), 'lead állapot');
T::egyezik(400, t_rest('POST', '/admin/lead/' . $lid, ['allapot' => 'xx'])->get_status(), 'rossz állapot');
$n = SDH_Sales_Naplo::lista(['ref_tipus' => 'lead', 'ref_id' => $lid]);
t_rest('POST', '/admin/naplo/' . $n['sorok'][0]['id'] . '/visszaallit');
T::egyezik('uj', $wpdb->get_var($wpdb->prepare('SELECT allapot FROM ' . SDH_Sales_Schema::t('lead') . ' WHERE id = %d', $lid)), 'lead visszaállt');
T::vege('t233 mentés + visszaállítás');
