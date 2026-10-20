<?php
/** t330 – Stúdió REST: minden új út csak adminnak (látogató és szerkesztő 401/403), az admin eléri; a panel-nyilvántartás. */
require __DIR__ . '/_keret.php';

$oid = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'T330 oldal']);
$cid = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'T330 cikk']);
$aid = wp_insert_post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'T330 kép', 'post_mime_type' => 'image/png']);

$utak = [
    ['GET', '/admin/feliratok'], ['POST', '/admin/feliratok'],
    ['POST', '/admin/oldal/' . $oid . '/vazlat'], ['POST', '/admin/oldal/' . $oid . '/vazlat/elvet'], ['GET', '/admin/oldal/' . $oid . '/reviziok'],
    ['POST', '/admin/oldal/' . $oid . '/masol'], ['POST', '/admin/oldal/' . $oid . '/kuka'], ['POST', '/admin/oldal/' . $oid . '/vissza'], ['POST', '/admin/oldal/' . $oid . '/kezdolap'],
    ['GET', '/admin/blog'], ['POST', '/admin/blog'], ['GET', '/admin/blog/kategoriak'], ['POST', '/admin/blog/kategoria'],
    ['GET', '/admin/blog/' . $cid], ['POST', '/admin/blog/' . $cid], ['POST', '/admin/blog/' . $cid . '/kuka'], ['POST', '/admin/blog/' . $cid . '/vissza'],
    ['GET', '/admin/media'], ['POST', '/admin/media/' . $aid],
    ['GET', '/admin/kuka'], ['POST', '/admin/kuka/' . $cid . '/vegleges'],
    ['POST', '/admin/email/elonezet'], ['POST', '/admin/email/proba'],
    ['GET', '/admin/urlapok'], ['POST', '/admin/urlapok'],
];

foreach ($utak as [$m, $u]) {
    $k = t_latogato(fn () => t_rest($m, $u)->get_status());
    T::igaz(in_array($k, [401, 403], true), 'látogató tiltva: ' . $m . ' ' . $u . ' (' . $k . ')');
}

$szerk = wp_insert_user(['user_login' => 't330-szerk', 'user_pass' => wp_generate_password(), 'user_email' => 't330@example.com', 'role' => 'editor']);
$elozo = get_current_user_id();
wp_set_current_user((int) $szerk);
foreach ($utak as [$m, $u]) {
    $k = t_rest($m, $u)->get_status();
    T::igaz(in_array($k, [401, 403], true), 'szerkesztő tiltva: ' . $m . ' ' . $u . ' (' . $k . ')');
}
wp_set_current_user($elozo);

// Admin: az olvasó utak 200-at adnak.
foreach ([['GET', '/admin/feliratok'], ['GET', '/admin/oldal/' . $oid . '/reviziok'], ['GET', '/admin/blog'], ['GET', '/admin/blog/kategoriak'], ['GET', '/admin/blog/' . $cid], ['GET', '/admin/media'], ['GET', '/admin/kuka'], ['GET', '/admin/urlapok']] as [$m, $u]) {
    T::egyezik(200, t_rest($m, $u)->get_status(), 'admin eléri: ' . $u);
}

// Nem létező azonosítók: 404 (nem 500).
foreach ([['POST', '/admin/oldal/999999/vazlat', ['szekciok' => []]], ['POST', '/admin/oldal/999999/masol'], ['GET', '/admin/blog/999999'], ['POST', '/admin/media/999999'], ['POST', '/admin/kuka/999999/vegleges'], ['POST', '/admin/oldal/' . $cid . '/kuka']] as $x) {
    T::egyezik(404, t_rest($x[0], $x[1], $x[2] ?? [])->get_status(), 'nincs ilyen: ' . $x[1]);
}

// Panel-nyilvántartás: a tartalomtár-panelek ismert elemtípusra, a beállítás-nézetek ismert csoportra mutatnak.
$p     = SDH_Sales_Admin::panelek();
$semak = SDH_Sales_Szekcio::elem_semak();
$csop  = SDH_Sales_Admin_Beallitas::csoportok();
foreach ($p as $k => $x) {
    foreach ($x['tipusok'] as $t) {
        T::igaz(isset($semak[$t]), "panel {$k}: ismert elemtípus {$t}");
    }
    foreach ($x['beallitas'] as $c) {
        T::igaz(isset($csop[$c]), "panel {$k}: ismert beállítás-csoport {$c}");
    }
}
$minden_tipus = array_merge(...array_values(array_map(static fn ($x) => $x['tipusok'], $p)));
foreach (array_keys($semak) as $t) {
    T::igaz(in_array($t, $minden_tipus, true), "minden elemtípusnak van panelje: {$t}");
}
T::igaz(isset($p['elemek']) && $p['elemek']['menu'] === false, 'a régi Elemek-cím él, de a menüben nem látszik');
$sajat = ['', 'Értékesítés', 'Tartalom', 'Megjelenés', 'Kommunikáció', 'Rendszer'];
T::egyezik($sajat, array_values(array_intersect(array_unique(array_column(array_filter($p, static fn ($x) => $x['menu']), 'csoport')), $sajat)), 'csoportok sorrendje');

// A Stúdió fájljai megvannak és semlegesek (nincs SDH / WordPress).
foreach (array_merge(SDH_Sales_Admin::STUDIO_JS, ['studio.css']) as $f) {
    $t = (string) @file_get_contents(SDH_SALES_DIR . 'assets/studio/' . $f);
    T::igaz($t !== '', 'fájl megvan: ' . $f);
    T::igaz(preg_match('/\bSDH\b|sdh[-_]|WordPress/i', $t) !== 1, 'semleges: ' . $f);
}

T::vege('t330 Stúdió jogosultság');
