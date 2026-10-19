<?php
/**
 * t250 – kiszolgálási lánc, helyi Központtal: rendelés → fizetve → licenc a Központban (modulok, korlátok, lejárat,
 * díj) → végszámla (minta mód) → üdvözlő levél (kulcs, letöltő link, számla) → időpont-ajánlat → értesítés; állapot
 * `kiszolgalva`; idempotencia (kettős fizetve-hook, újrafuttatás, ismételt Központ-kérés); admin REST.
 */
require __DIR__ . '/_t25-seged.php';

global $wpdb;

t25_kiadas('9.9.5');
$kezd = microtime(true);
$id   = t25_rendeles(['opciok' => ['felhasznalo' => 7]]);
$r0   = t25_r($id);

T::egyezik([], $r0['lanc'], 'fizetés előtt nincs lánc');
$v = SDH_Sales_Kiszolgalas::indit($id);
T::igaz(!$v['ok'] && strpos($v['uzenet'], 'nincs kifizetve') !== false, 'fizetés előtt nem indul');

t25_fizet($id);
$ms = (int) round((microtime(true) - $kezd) * 1000);
$r  = t25_r($id);

/* --- A lánc -------------------------------------------------------------------------------------------------------- */

foreach (SDH_Sales_Kiszolgalas::LEPESEK as $s) {
    T::igaz(in_array((string) ($r['lanc']['lepesek'][$s]['allapot'] ?? ''), ['ok'], true), 'lépés ok: ' . $s . ' (' . (string) ($r['lanc']['lepesek'][$s]['uzenet'] ?? '') . ')');
}
T::igaz(!empty($r['lanc']['kesz']), 'a lánc kész');
T::egyezik('kiszolgalva', (string) $r['allapot'], 'rendelés: kiszolgalva');

/* --- A licenc a Központban ------------------------------------------------------------------------------------------- */

$kulcs = (string) $r['licenc_kulcs'];
T::igaz(preg_match('/^SDH-[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $kulcs) === 1, 'licenckulcs a rendelésen');
$l = SDH_Kozpont::licenc($kulcs);
T::igaz($l !== null && (int) $l->id === (int) $r['kozpont_licenc_id'], 'a Központban ugyanez a licenc (kozpont_licenc_id)');
$mod = (array) SDH_Kozpont::licenc_modulok($l);
$pro = SDH_Sales_Katalogus::csomag_modulok('pro');
T::igaz(array_diff($pro, $mod) === [] && in_array('asszisztens', $mod, true), 'modulok: a Pro csomagé + a külön vett asszisztens');
T::igaz(!in_array('csapat', $mod, true), 'nem vett modul nincs benne');
T::egyezik(7, (int) $l->max_felhasznalo, 'max felhasználó: a rendelt 7');
T::egyezik((int) (SDH_Sales_Katalogus::csomag('pro')['max_peldany'] ?? 1), (int) $l->max_peldany, 'max példány: a csomagé');
T::egyezik('eves', (string) $l->ciklus, 'ciklus');
$lejar = wp_date('Y-m-d', (int) strtotime(wp_date('Y-m-d') . ' +12 month'));
T::egyezik($lejar, (string) $l->lejar, 'lejárat: ma + 12 hónap');
$szolg = 0;
foreach (SDH_Sales_Kiszolgalas::tetelek($r) as $t) {
    $szolg += $t['tipus'] === 'szolgaltatas' ? (int) $t['netto'] : 0;
}
T::egyezik((int) $r['netto'] - $szolg, (int) $l->dij, 'díj: a licencsorok nettója (szolgáltatás nélkül)');
T::egyezik('Pro', (string) $l->csomag, 'csomag neve');
$vv = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('vevo') . ' WHERE id = %d', (int) $l->vevo_id));
T::igaz($vv !== null && strpos((string) $vv->nev, 'Próba Szerviz') === 0 && (string) $vv->kapcsolattarto === 'Kovács Béla' && (string) $vv->email === (string) $r['email'], 'vevő a Központban (cég, kapcsolattartó, e-mail)');
T::igaz(isset(SDH_Sales_Kiszolgalas::licencek()[$kulcs]) && SDH_Sales_Kiszolgalas::licencek()[$kulcs]['lejar'] === $lejar, 'a lejárat eltárolva (emlékeztetőhöz)');

/* --- Számla, levelek ------------------------------------------------------------------------------------------------- */

T::igaz(strncmp((string) $r['szamla_vegszamla'], 'MINTA-', 6) === 0, 'végszámla (minta mód)');
$u = t25_levelek('udvozlo', (string) $r['email']);
T::egyezik(1, count($u), 'egy üdvözlő levél');
$h = (string) ($u[0]['html'] ?? '');
T::tartalmaz($h, $kulcs, 'levél: licenckulcs');
T::tartalmaz($h, '/sdh-kozpont/v1/letoltes?t=', 'levél: letöltő link');
T::tartalmaz($h, esc_html(home_url('/')), 'levél: a központ címe (helyi mód: ez a webhely)');
T::tartalmaz($h, 'Kovács Béla', 'levél: név');
T::tartalmaz($h, '/szamla/', 'levél: számla-link');
T::igaz(count((array) $u[0]['csatolmany']) === 1 && substr((string) $u[0]['csatolmany'][0], -4) === '.pdf', 'levél: számla PDF csatolva');
T::tartalmaz((string) $u[0]['targy'], SDH_Sales::termeknev(), 'tárgy: terméknév');
T::nem_tartalmaz($h, '{', 'nincs maradék token');
T::tartalmaz((string) $u[0]['szoveg'], $kulcs, 'szöveges rész: kulcs');
T::igaz(in_array('From: "Forgalmazó Teszt Kft." <rendeles@forgalmazo.example>', $u[0]['fejlec'], true), 'feladó a beállításból');
T::egyezik(1, count(t25_levelek('idopont_ajanlat', (string) $r['email'])), 'időpont-ajánlat (telepítést rendelt)');
T::tartalmaz((string) (t25_levelek('idopont_ajanlat')[0]['html'] ?? ''), 'rt=', 'az ajánlat linkje a rendeléshez kötött');
$a = t25_levelek('admin_ertesites', 'norbi@forgalmazo.example');
T::egyezik(1, count($a), 'értesítés Norbinak');
T::tartalmaz((string) ($a[0]['html'] ?? ''), $kulcs, 'értesítés: kulcs');

/* --- A letöltő link működik ----------------------------------------------------------------------------------------- */

preg_match('/href="([^"]*letoltes[^"]*)"/', $h, $m);
$tok = t25_token(html_entity_decode((string) ($m[1] ?? '')));
$e   = SDH_Kozpont_Kiszolgalas::letoltes_ellenoriz($tok, false);
T::igaz(is_array($e) && $e['verzio'] === '9.9.5' && $e['licenc_id'] === (int) $l->id, 'a levél linkje a licenc kiadását adja');

/* --- Idempotencia ------------------------------------------------------------------------------------------------- */

$licdb = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('licenc'));
$levdb = count($GLOBALS['t25_levelek']);
do_action('sdh_sales_fizetve', $id);
SDH_Sales_Kiszolgalas::indit($id);
SDH_Sales_Kiszolgalas::cron(time() + 3600);
T::egyezik($licdb, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('licenc')), 'kettős fizetve / újrafuttatás: nincs új licenc');
T::egyezik($levdb, count($GLOBALS['t25_levelek']), 'nincs új levél');
T::egyezik($kulcs, (string) t25_r($id)['licenc_kulcs'], 'a kulcs nem változott');
$r2 = t25_r($id);
T::egyezik(1, (int) $r2['lanc']['lepesek']['kozpont']['probalkozas'], 'a Központ-lépés egyszer futott');

// Elveszett eredmény: a lánc újra kéri a Központtól → ugyanaz a licenc (a Központ idempotens).
$lanc = $r2['lanc'];
$lanc['lepesek']['kozpont'] = ['allapot' => 'var', 'probalkozas' => 0, 'uzenet' => '', 'ido' => '', 'kovetkezo' => 0];
$lanc['kesz'] = false;
$wpdb->update(SDH_Sales_Schema::t('rendeles'), ['lanc' => wp_json_encode($lanc), 'licenc_kulcs' => ''], ['id' => $id]);
SDH_Sales_Kiszolgalas::indit($id);
$r3 = t25_r($id);
T::egyezik($kulcs, (string) $r3['licenc_kulcs'], 'ismételt Központ-kérés: ugyanaz a kulcs');
T::tartalmaz((string) $r3['lanc']['lepesek']['kozpont']['uzenet'], 'korábbi válasza', 'és jelzi, hogy ismétlés');
T::egyezik($licdb, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('licenc')), 'akkor sem lett új licenc');
T::egyezik($levdb, count($GLOBALS['t25_levelek']), 'a kész levél-lépés nem fut újra');

// A cron más argumentummal hívva sem dől el (egy másik feliratkozó adhat argumentumot).
do_action(SDH_Sales::CRON, 'valami');
T::igaz(true, 'cron: idegen argumentum elfogadva');

/* --- Admin REST ------------------------------------------------------------------------------------------------- */

$v = t_rest('GET', '/admin/kiszolgalas/' . $id);
T::egyezik(200, $v->get_status(), 'REST állapot');
T::igaz($v->get_data()['kesz'] === true && $v->get_data()['lepesek']['kozpont']['nev'] === 'Licenc a Központban', 'REST: kész, lépésnevek');
T::nem_tartalmaz((string) wp_json_encode($v->get_data()), 'letoltes?t=', 'REST: a letöltő token nem látszik');
$v = t_rest('POST', '/admin/kiszolgalas/' . $id . '/ujra', ['lepes' => 'email']);
T::igaz($v->get_status() === 200 && $v->get_data()['ok'] === false && strpos($v->get_data()['uzenet'], 'lezárult') !== false, 'kész lépés nem indítható újra');
T::egyezik(404, t_rest('GET', '/admin/kiszolgalas/99999999')->get_status(), 'nincs ilyen rendelés: 404');
T::igaz(in_array(t_latogato(static fn () => t_rest('GET', '/admin/kiszolgalas/' . $id))->get_status(), [401, 403], true), 'látogató: nincs hozzáférés');

/* --- Teljes árszámítás-eredmény a tetelek mezőben ----------------------------------------------------------------- */

$id2 = t25_rendeles(['teljes' => true, 'tetelek' => [['tipus' => 'csomag', 'kulcs' => 'business']], 'opciok' => ['felhasznalo' => 12, 'telephely' => 3]]);
t25_fizet($id2);
$l2 = SDH_Kozpont::licenc((string) t25_r($id2)['licenc_kulcs']);
T::igaz($l2 !== null && SDH_Kozpont::licenc_modulok($l2) === '*', 'Business: minden modul');
T::egyezik(12, (int) ($l2->max_felhasznalo ?? 0), 'teljes eredményből: 12 felhasználó');
T::egyezik(0, count(t25_levelek('idopont_ajanlat', (string) t25_r($id2)['email'])), 'telepítés nélkül nincs külön ajánlat');
T::egyezik('kihagyva', (string) t25_lepes($id2, 'idopont')['allapot'], 'időpont-lépés: kihagyva');

echo '   (mérés: rendeléstől a kész láncig ' . $ms . " ms, helyi Központ, minta számla)\n";
T::vege('t250 kiszolgálás (helyi)');
