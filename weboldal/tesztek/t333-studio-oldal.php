<?php
/**
 * t333 – Oldalépítő háttér: piszkozat (mentés, betöltés, eldobás, közzétételkor törlődik), revíziók, oldal-beállítások
 * (slug, szülő – körkörös tiltva, sablon, sorrend, SEO + noindex + kanonikus + megosztási kép, törzsszöveg kses),
 * másolás, kuka (a kezdőlap nem), visszaállítás, kezdőlap (csak közzétett), XSS a piszkozatban.
 */
require __DIR__ . '/_keret.php';

$S  = 'SDH_Sales_Studio';
$id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'T333', 'post_name' => 't333']);
SDH_Sales_Tartalom::szekciok_ment($id, [['tipus' => 'hero', 'cim' => 'Eredeti']]);

/* Piszkozat */
$v = t_rest('POST', "/admin/oldal/{$id}/vazlat", ['szekciok' => [['tipus' => 'hero', 'cim' => 'Piszkozat<script>alert(1)</script>'], ['tipus' => 'nincs-ilyen']]]);
T::egyezik(200, $v->get_status(), 'piszkozat mentve');
$o = t_rest('GET', "/admin/oldal/{$id}")->get_data();
T::igaz(is_array($o['vazlat']) && count($o['vazlat']['szekciok']) === 1, 'a piszkozat tisztítva visszajön (ismeretlen típus kimarad)');
T::igaz(strpos(wp_json_encode($o['vazlat']), '<script') === false, 'piszkozat: nincs szkript');
T::egyezik('Eredeti', $o['szekciok'][0]['cim'], 'a közzétett változat érintetlen');
T::igaz($o['vazlat']['ido'] > 0 && $o['vazlat']['ki'] !== '', 'piszkozat ideje és szerzője');
$l = t_rest('GET', '/admin/oldalak')->get_data()['lista'];
T::igaz((bool) (array_column($l, 'piszkozat', 'id')[$id] ?? false), 'a listában jelölve: mentetlen piszkozat');
T::egyezik(400, t_rest('POST', "/admin/oldal/{$id}/vazlat", ['szekciok' => 'x'])->get_status(), 'hibás piszkozat: 400');

t_rest('POST', "/admin/oldal/{$id}/vazlat/elvet");
T::egyezik(null, t_rest('GET', "/admin/oldal/{$id}")->get_data()['vazlat'], 'piszkozat eldobva');

t_rest('POST', "/admin/oldal/{$id}/vazlat", ['szekciok' => [['tipus' => 'hero', 'cim' => 'Új']]]);
$m = t_rest('POST', "/admin/oldal/{$id}", ['szekciok' => [['tipus' => 'hero', 'cim' => 'Közzétett új']]]);
T::egyezik(200, $m->get_status(), 'közzététel');
T::egyezik(null, $m->get_data()['vazlat'], 'közzétételkor a piszkozat törlődik');

/* Revíziók */
t_rest('POST', "/admin/oldal/{$id}", ['szekciok' => [['tipus' => 'hero', 'cim' => 'Harmadik']]]);
$rv = t_rest('GET', "/admin/oldal/{$id}/reviziok")->get_data();
T::igaz($rv['ossz'] >= 2, 'revíziók listája (' . $rv['ossz'] . ')');
$n = t_rest('GET', '/admin/naplo/' . $rv['lista'][0]['id'])->get_data();
T::egyezik('Harmadik', $n['utana'][0]['cim'] ?? '', 'a revízió tartalma (utána)');
T::egyezik('Közzétett új', $n['elotte'][0]['cim'] ?? '', 'a revízió tartalma (előtte)');

/* Oldal-beállítások */
$szulo = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'T333 szülő', 'post_name' => 't333-szulo']);
$v = t_rest('POST', "/admin/oldal/{$id}", ['slug' => 'T333 Új Út', 'szulo' => $szulo, 'sorrend' => 7, 'sablon' => '', 'tartalom' => '<p>Törzs</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
    'seo' => ['title' => 'Cím', 'description' => 'Leírás', 'kulcsszo' => 'k', 'noindex' => true, 'kanonikus' => '/masik-oldal/', 'og_kep' => '']]);
T::egyezik(200, $v->get_status(), 'beállítások mentve');
$p = get_post($id);
T::egyezik('t333-uj-ut', $p->post_name, 'slug tisztítva');
T::egyezik($szulo, (int) $p->post_parent, 'szülő');
T::egyezik(7, (int) $p->menu_order, 'sorrend');
T::igaz(strpos($p->post_content, '<script') === false && strpos($p->post_content, 'javascript:') === false && strpos($p->post_content, 'Törzs') !== false, 'törzsszöveg tisztítva');
T::egyezik('1', get_post_meta($id, '_sdh_noindex', true), 'noindex');
T::egyezik(home_url('/masik-oldal/'), get_post_meta($id, $S::META_KANON, true), 'kanonikus (relatívból teljes)');
T::egyezik(home_url('/masik-oldal/'), wp_get_canonical_url($id), 'a kanonikus a kimenetben is él');
$d = $v->get_data();
T::igaz($d['seo']['noindex'] === true && $d['seo']['kanonikus'] !== '', 'visszaolvasás');
T::egyezik(422, t_rest('POST', "/admin/oldal/{$szulo}", ['szulo' => $id])->get_status(), 'körkörös szülő tiltva');
T::egyezik(422, t_rest('POST', "/admin/oldal/{$id}", ['szulo' => $id])->get_status(), 'önmaga szülője tiltva');
T::egyezik(422, t_rest('POST', "/admin/oldal/{$id}", ['sablon' => '../../evil.php'])->get_status(), 'ismeretlen sablon tiltva');
T::egyezik(422, t_rest('POST', "/admin/oldal/{$id}", ['seo' => ['title' => 'x', 'og_kep' => 'https://pelda.hu/idegen.png']])->get_status(), 'megosztási kép csak médiatárból');
t_rest('POST', "/admin/oldal/{$id}", ['seo' => ['title' => 'Cím', 'noindex' => false, 'kanonikus' => 'javascript:alert(1)']]);
T::egyezik('', get_post_meta($id, '_sdh_noindex', true), 'noindex ki');
T::egyezik('', get_post_meta($id, $S::META_KANON, true), 'rossz kanonikus: törlés');

/* Másolás */
$c = t_rest('POST', "/admin/oldal/{$id}/masol");
T::egyezik(201, $c->get_status(), 'másolat');
$mid = (int) $c->get_data()['id'];
T::egyezik('draft', get_post_status($mid), 'a másolat vázlat');
T::egyezik(SDH_Sales_Tartalom::szekciok($id), SDH_Sales_Tartalom::szekciok($mid), 'a szekciók is másolódtak');
T::igaz(strpos(get_the_title($mid), '(másolat)') !== false, 'a cím jelzi a másolatot');

/* Kezdőlap */
T::egyezik(409, t_rest('POST', "/admin/oldal/{$mid}/kezdolap")->get_status(), 'vázlat nem lehet kezdőlap');
T::egyezik(200, t_rest('POST', "/admin/oldal/{$id}/kezdolap")->get_status(), 'közzétett lehet kezdőlap');
T::egyezik($id, (int) get_option('page_on_front'), 'kezdőlap beállítva');
T::egyezik(409, t_rest('POST', "/admin/oldal/{$id}/kuka")->get_status(), 'a kezdőlap nem tehető kukába');

/* Kuka és vissza */
T::egyezik(200, t_rest('POST', "/admin/oldal/{$mid}/kuka")->get_status(), 'kukába');
T::egyezik('trash', get_post_status($mid), 'a kukában');
$kl = array_column(t_rest('GET', '/admin/oldalak', ['kuka' => 1])->get_data()['lista'], 'id');
T::igaz(in_array($mid, $kl, true), 'a kuka-lista mutatja');
T::igaz(!in_array($mid, array_column(t_rest('GET', '/admin/oldalak')->get_data()['lista'], 'id'), true), 'a rendes lista nem');
T::egyezik(200, t_rest('POST', "/admin/oldal/{$mid}/vissza")->get_status(), 'visszaállítás');
T::igaz(get_post_status($mid) !== 'trash', 'kikerült a kukából');
T::egyezik(404, t_rest('POST', "/admin/oldal/{$mid}/vissza")->get_status(), 'nem kukás elem visszaállítása: 404');
t_rest('POST', "/admin/oldal/{$mid}/kuka");
T::egyezik(200, t_rest('POST', "/admin/kuka/{$mid}/vegleges")->get_status(), 'végleges törlés a kukából');
T::egyezik(null, get_post($mid), 'véglegesen törölve');
T::egyezik(404, t_rest('POST', "/admin/kuka/{$id}/vegleges")->get_status(), 'nem kukás oldal véglegesen nem törölhető');

T::vege('t333 oldalépítő háttér');
