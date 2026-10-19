<?php
/** t239 – élő előnézet (token, a mentetlen szekciók, csak a kérő adminnak), rendelés-részlet, lépés újra, keresés, rejtés az adminban. */
require __DIR__ . '/_keret.php';

$p = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'T239', 'post_name' => 't239']);
SDH_Sales_Tartalom::szekciok_ment($p, [['tipus' => 'hero', 'cim' => 'Mentett']]);
$r = t_rest('POST', '/admin/elonezet', ['post_id' => $p, 'szekciok' => [['tipus' => 'cta', 'cim' => 'Mentetlen <script>']]])->get_data();
T::tartalmaz($r['url'], 'elonezet=', 'előnézeti URL');
T::nem_tartalmaz($r['url'], 'sdh', 'semleges paraméter');
T::egyezik('Mentetlen', $r['szekciok'][0]['cim'], 'tisztított előnézeti szekció');
$_GET['elonezet'] = $r['token'];
SDH_Sales_Admin_Tartalom::elonezet_indit();
T::egyezik('cta', SDH_Sales_Tartalom::szekciok($p)[0]['tipus'] ?? '', 'előnézetben a mentetlen szekció él');
T::egyezik('hero', json_decode((string) get_post_field('ID', $p) ? (string) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT meta_value FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id = %d AND meta_key = %s", $p, SDH_Sales_Tartalom::META)) : '[]', true)[0]['tipus'], 'az adatbázisban a mentett marad');
SDH_Sales_Admin_Tartalom::elonezet_beallit($p, []);
unset($_GET['elonezet']);
// Más felhasználó tokennel nem lát semmit.
$uid = wp_insert_user(['user_login' => 't239-masik', 'user_pass' => wp_generate_password(), 'user_email' => 't239@example.com', 'role' => 'administrator']);
$r2 = t_rest('POST', '/admin/elonezet', ['post_id' => $p, 'szekciok' => [['tipus' => 'cta', 'cim' => 'Titkos']]])->get_data();
$elozo = get_current_user_id();
wp_set_current_user((int) $uid);
T::igaz(get_transient('sdh_sales_elonezet_' . $r2['token'])['user'] !== get_current_user_id(), 'a token a kérőé');
wp_set_current_user($elozo);

// Rendelés-részlet + lépés újra (a kiszolgálás modul jelenléte szerint).
global $wpdb;
$wpdb->insert(SDH_Sales_Schema::t('rendeles'), ['azon' => 'R-2026-239001', 'allapot' => 'fizetve', 'tipus' => 'uj', 'vevo' => '{"ceg":"T239 Kft.","email":"v@example.com"}', 'tetelek' => '[{"nev":"Pro","netto":10000}]', 'netto' => 10000, 'afa' => 2700, 'brutto' => 12700, 'email' => 'v@example.com',
    'lanc' => '{"lepesek":{"kozpont":{"allapot":"ok"},"szamla":{"allapot":"hiba","probalkozas":3,"uzenet":"Időtúllépés"}}}', 'letrehozva' => current_time('mysql', true)]);
$rid = (int) $wpdb->insert_id;
$d = t_rest('GET', '/admin/rendeles/' . $rid)->get_data();
T::egyezik('T239 Kft.', $d['vevo'], 'vevő');
T::igaz($d['lanc_hiba'], 'láncshiba jelezve');
$sz = array_values(array_filter($d['lanc'], fn ($l) => $l['kulcs'] === 'szamla'))[0];
T::egyezik(['hiba', 3, 'Időtúllépés'], [$sz['allapot'], $sz['probalkozas'], $sz['uzenet']], 'lépés adatai');
T::egyezik(1, count(t_rest('GET', '/admin/rendelesek', ['allapot' => 'hiba'])->get_data()['lista']), 'szűrés: hibás lánc');
T::egyezik(1, t_rest('GET', '/admin/attekintes')->get_data()['lanc_hiba'], 'áttekintés: hibaszám');
T::egyezik(400, t_rest('POST', '/admin/rendeles/' . $rid . '/ujra', ['lepes' => 'nincs'])->get_status(), 'ismeretlen lépés');
T::egyezik(404, t_rest('POST', '/admin/rendeles/999999/ujra', ['lepes' => 'szamla'])->get_status(), 'nincs rendelés');
T::igaz(in_array(t_rest('POST', '/admin/rendeles/' . $rid . '/ujra', ['lepes' => 'kozpont'])->get_status(), [200, 501], true), 'újra: 200 vagy „nincs bekapcsolva”');
$k = t_rest('GET', '/admin/kereses', ['q' => '239001'])->get_data()['lista'];
T::egyezik('rendeles', $k[0]['tipus'] ?? '', 'Ctrl+K: rendelés azonosítóra');
$k = t_rest('GET', '/admin/kereses', ['q' => 'v@example'])->get_data()['lista'];
T::egyezik($rid, $k[0]['id'] ?? 0, 'Ctrl+K: e-mailre');
T::egyezik('v@example.com', t_rest('GET', '/admin/vevok')->get_data()['lista'][0]['email'] ?? '', 'vevők a rendelésekből');

// Rejtés: az admin-oldal szövegei és a JS / CSS nem említik.
$f = '';
foreach (['admin.js', 'admin-tartalom.js', 'admin-beallitas.js', 'admin.css'] as $x) {
    $f .= (string) file_get_contents(SDH_SALES_DIR . 'assets/' . $x);
}
T::igaz(preg_match('/\bSDH\b|sdh[-_]|WordPress/i', $f) !== 1, 'JS / CSS semleges');
T::egyezik('ertekesites', SDH_Sales_Admin::OLDAL, 'admin-oldal slugja semleges');
T::vege('t239 előnézet + rendelés');
