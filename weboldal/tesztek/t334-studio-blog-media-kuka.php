<?php
/**
 * t334 – Blog (új cikk, mentés: cím, slug, tartalom kses, kivonat, kategória, címke, dátum + ütemezés, SEO, kiemelt kép
 * csak médiatárból), kuka / vissza / végleges; médiatár (lista, szűrés, alt mentése); a Kuka panel a törölt
 * tartalom-elemet mutatja és a naplóból visszaállítható.
 */
require __DIR__ . '/_keret.php';

/* Blog */
T::egyezik(400, t_rest('POST', '/admin/blog', ['cim' => ''])->get_status(), 'cím nélkül nem');
$r = t_rest('POST', '/admin/blog', ['cim' => 'T334 cikk']);
T::egyezik(201, $r->get_status(), 'új cikk');
$id = (int) $r->get_data()['id'];
T::egyezik('draft', get_post_status($id), 'vázlatként');

$kat = t_rest('POST', '/admin/blog/kategoria', ['nev' => 'T334 kategória'])->get_data()['id'];
T::igaz($kat > 0, 'új kategória');
T::egyezik($kat, t_rest('POST', '/admin/blog/kategoria', ['nev' => 'T334 kategória'])->get_data()['id'], 'meglévő kategória nem duplikálódik');

$v = t_rest('POST', "/admin/blog/{$id}", [
    'cim' => 'T334 – mentett cím', 'slug' => 'T334 Útvonal', 'kivonat' => "Rövid\nkivonat <b>x</b>",
    'tartalom' => '<h2>Alcím</h2><p>Szöveg</p><script>alert(1)</script><img src=x onerror=alert(1)>', 'statusz' => 'publish',
    'nap' => '2026-01-05', 'ora' => '10:30', 'kategoriak' => [$kat, 999999], 'cimkek' => 'egy, kettő, <b>három</b>',
    'seo' => ['title' => 'SEO cím', 'description' => 'SEO leírás', 'kulcsszo' => 'kulcs', 'noindex' => true],
]);
T::egyezik(200, $v->get_status(), 'cikk mentve');
$d = $v->get_data();
$p = get_post($id);
T::egyezik('T334 – mentett cím', $p->post_title, 'cím');
T::egyezik('t334-utvonal', $p->post_name, 'slug');
T::igaz(strpos($p->post_content, '<script') === false && strpos($p->post_content, 'onerror') === false && strpos($p->post_content, '<h2>Alcím</h2>') !== false, 'tartalom tisztítva');
T::igaz(strpos($p->post_excerpt, '<b>') === false, 'kivonat szövegként');
T::egyezik('publish', $p->post_status, 'közzétéve (múltbeli dátum)');
T::egyezik('2026-01-05', $d['nap'], 'dátum');
T::egyezik('10:30', $d['ora'], 'időpont');
T::egyezik([$kat], $d['kategoria_id'], 'csak létező kategória');
T::egyezik(['egy', 'három', 'kettő'], (static function ($x) { sort($x); return $x; })(array_map('trim', explode(',', $d['cimkek']))), 'címkék tisztítva');
T::egyezik('SEO cím', $d['seo']['title'], 'SEO');
T::igaz($d['seo']['noindex'], 'noindex');

$v = t_rest('POST', "/admin/blog/{$id}", ['statusz' => 'publish', 'nap' => gmdate('Y-m-d', time() + 20 * DAY_IN_SECONDS), 'ora' => '09:00']);
T::egyezik('future', $v->get_data()['statusz'], 'jövőbeli dátum: ütemezett');
T::egyezik(422, t_rest('POST', "/admin/blog/{$id}", ['nap' => '2026-13-45', 'ora' => '25:99'])->get_status(), 'hibás dátum: 422');
T::egyezik(422, t_rest('POST', "/admin/blog/{$id}", ['cim' => ''])->get_status(), 'üres cím: 422');
T::egyezik(422, t_rest('POST', "/admin/blog/{$id}", ['kep' => 'https://idegen.example/kep.png'])->get_status(), 'kiemelt kép csak a médiatárból');

$l = t_rest('GET', '/admin/blog', ['q' => 'mentett cím'])->get_data();
T::egyezik($id, $l['lista'][0]['id'] ?? 0, 'keresés a cikkek között');
T::igaz(in_array($id, array_column(t_rest('GET', '/admin/blog', ['kategoria' => $kat])->get_data()['lista'], 'id'), true), 'kategória-szűrő');

T::egyezik(200, t_rest('POST', "/admin/blog/{$id}/kuka")->get_status(), 'cikk a kukába');
T::igaz(in_array($id, array_column(t_rest('GET', '/admin/kuka')->get_data()['cikkek'], 'id'), true), 'a Kuka mutatja');
T::egyezik(200, t_rest('POST', "/admin/blog/{$id}/vissza")->get_status(), 'cikk vissza');
T::igaz(get_post_status($id) !== 'trash', 'nincs a kukában');
$pid = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'oldal']);
T::egyezik(404, t_rest('GET', "/admin/blog/{$pid}")->get_status(), 'oldal nem cikk');

/* Médiatár */
$aid = (int) wp_insert_attachment(['post_title' => 'T334 kép', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], '/nincs/t334.png');
$vid = (int) wp_insert_attachment(['post_title' => 'T334 videó', 'post_mime_type' => 'video/mp4', 'post_status' => 'inherit'], '/nincs/t334.mp4');
$m = t_rest('GET', '/admin/media', ['tipus' => 'kep'])->get_data();
T::igaz(in_array($aid, array_column($m['lista'], 'id'), true) && !in_array($vid, array_column($m['lista'], 'id'), true), 'képszűrő');
T::igaz(in_array($vid, array_column(t_rest('GET', '/admin/media', ['tipus' => 'video'])->get_data()['lista'], 'id'), true), 'videószűrő');
$s = t_rest('POST', "/admin/media/{$aid}", ['alt' => 'Leírás <script>x</script>', 'cim' => 'Új cím', 'felirat' => 'Aláírás']);
T::egyezik(200, $s->get_status(), 'médiaelem mentve');
T::igaz(strpos($s->get_data()['alt'], '<') === false && strpos($s->get_data()['alt'], 'Leírás') === 0, 'alt tisztítva');
T::egyezik('Új cím', get_the_title($aid), 'cím');
T::egyezik(404, t_rest('POST', "/admin/media/{$pid}")->get_status(), 'nem médiaelem: 404');

/* Kuka: törölt tartalom-elem visszaállítása */
SDH_Sales_Tartalom::ment('gyik', 't334-kerdes', ['kulcs' => 't334-kerdes', 'kerdes' => 'T334?', 'valasz' => 'Igen.'], 'T334?');
t_rest('POST', '/admin/elem/torol', ['tipus' => 'gyik', 'kulcs' => 't334-kerdes']);
$k = t_rest('GET', '/admin/kuka')->get_data();
$e = array_values(array_filter($k['elemek'], static fn ($x) => $x['kulcs'] === 't334-kerdes'))[0] ?? null;
T::igaz($e !== null && $e['tipus'] === 'gyik', 'a törölt GYIK-elem a kukában');
T::egyezik(200, t_rest('POST', '/admin/naplo/' . $e['naplo'] . '/visszaallit')->get_status(), 'visszaállítás a naplóból');
T::igaz(SDH_Sales_Tartalom::egy('gyik', 't334-kerdes') !== null, 'az elem visszajött');
T::igaz(!in_array('t334-kerdes', array_column(t_rest('GET', '/admin/kuka')->get_data()['elemek'], 'kulcs'), true), 'és eltűnt a kukából');

T::vege('t334 blog, média, kuka');
