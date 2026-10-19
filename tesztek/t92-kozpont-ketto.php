<?php
/**
 * t92 – 0.99.0 / SDH Központ 2.0: szívverés és állapotkép, adatvédelem, licencelt modulok és jogok, távoli műveletek
 * (végrehajtás, eredmény, nyugta, visszajátszás, lejárat, engedély), támogatási kérés, álló rendszer, régi példány,
 * egészségszabályok, a Központ felületének REST-végpontjai és a belépés utáni átirányítás.
 *
 * A központi bővítményt a teszt tölti be; a HTTP-kéréseket a WordPress saját REST-szervere szolgálja ki (nincs hálózat).
 */

require __DIR__ . '/_keret.php';
require_once dirname(__DIR__) . '/kozpont/sdh-kozpont/sdh-kozpont.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;

$L  = 'SDH_Muhely_Licenc';
$K  = 'SDH_Kozpont';
$TV = 'SDH_Muhely_Tavoli';
$TE = 'SDH_Muhely_Telemetria';

$K::sema();
do_action('rest_api_init', rest_get_server());

add_filter('pre_http_request', static function ($elo, array $args, string $url) {
    parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
    $ut = (string) ($q['rest_route'] ?? '');

    if (!str_starts_with($ut, '/sdh-kozpont/v1/')) {
        return $elo;
    }

    $r = new WP_REST_Request(strtoupper((string) ($args['method'] ?? 'GET')), $ut);
    unset($q['rest_route']);
    $r->set_query_params($q);

    if (!empty($args['body'])) {
        $r->set_header('Content-Type', 'application/json');
        $r->set_body((string) $args['body']);
    }

    $v = rest_do_request($r);

    return ['headers' => [], 'body' => (string) wp_json_encode($v->get_data()), 'response' => ['code' => $v->get_status(), 'message' => ''], 'cookies' => [], 'filename' => null];
}, 1, 3);

wp_set_current_user(1);
delete_option($L::OPT);
delete_option($TV::OPT);

$app = static function (string $metod, string $ut, array $adat = [], array $query = []) {
    $r = new WP_REST_Request($metod, '/sdh-kozpont/v1/app/' . $ut);
    $r->set_query_params($query);

    if ($adat !== []) {
        $r->set_header('Content-Type', 'application/json');
        $r->set_body((string) wp_json_encode($adat));
    }

    return rest_do_request($r);
};

/* --- Vevő és licenc a központban ------------------------------------------------------------------------------- */

$vid = $K::vevo_ment(['nev' => 'Próba Szerviz T92', 'email' => 'vevo@pelda.hu', 'telefon' => '+36 30 111 2222', 'cimkek' => 'Veszprém, kiemelt', 'allapot' => 'aktiv'], 'teszt');
T::igaz(is_int($vid) && $vid > 0, 'vevő létrehozva');
T::egyezik('A vevő neve kötelező.', $K::vevo_ment(['nev' => '']), 'név nélkül nem');

$lid = $K::licenc_ment(['vevo_id' => $vid, 'csomag' => 'Alap', 'modulok' => ['nyomtatas', 'uzenetek'], 'max_peldany' => 2, 'max_felhasznalo' => 50, 'dij' => 120000, 'ciklus' => 'eves', 'lejar' => '2099-12-31'], 'teszt');
T::igaz(is_int($lid) && $lid > 0, 'licenc létrehozva');
$lic = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $K::t('licenc') . ' WHERE id = %d', $lid));
T::egyezik(['nyomtatas', 'uzenetek'], $K::licenc_modulok($lic), 'a licenc moduljai');
T::egyezik(['frissites' => true, 'tamogatas' => true, 'tavoli' => true], $K::jogok($lic), 'alapból minden jog');
T::egyezik('Válassz vevőt (vagy adj meg újat).', $K::licenc_ment(['vevo_id' => 0]), 'vevő nélkül nem');
$l2 = $K::licenc_ment(['vevo_nev' => 'Új Vevő Licenccel T92', 'lejar' => '2026-02-30']);
T::igaz(is_int($l2), 'licenc új vevővel egy lépésben');
T::igaz($wpdb->get_var($wpdb->prepare('SELECT lejar FROM ' . $K::t('licenc') . ' WHERE id = %d', $l2)) === null, 'érvénytelen dátum: nincs lejárat');

/* --- Szívverés --------------------------------------------------------------------------------------------------- */

$titkos_nev = 'Titkos Ügyfél Kovács T92';
$wpdb->insert(SDH_Muhely_Schema::tabla('ugyfel'), ['nev' => $titkos_nev, 'telefon' => '06301239876', 'email' => 'titkos.ugyfel@pelda.hu']);

update_option($L::OPT, ['kozpont' => home_url('/'), 'kulcs' => (string) $lic->kulcs, 'peldany' => $L::peldany()]);
$TE::bekapcsol(true);
T::egyezik('', $L::szivveres(), 'szívverés sikerült');
T::egyezik('aktiv', $L::allapot(), 'licenc: aktív');

$p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $K::t('peldany') . ' WHERE peldany = %s', $L::peldany()));
T::igaz($p !== null && !empty($p->utolso_jel), 'a központ látja, utolsó jel rögzítve');
$pid = (int) ($p->id ?? 0);
$s   = json_decode((string) ($p->allapot ?? ''), true);
T::igaz(is_array($s) && isset($s['rendszer']['php'], $s['muhely']['verzio'], $s['adat']['munkalap'], $s['sebesseg'], $s['lemez'], $s['mentes'], $s['cron']), 'állapotkép: szerver, műhely, adat, sebesség, lemez, mentés, ütemező');
T::egyezik(SDH_MUHELY_VERSION, (string) ($s['muhely']['verzio'] ?? ''), 'a verzió az állapotképben');
T::igaz((int) ($s['adat']['ugyfel'] ?? 0) >= 1, 'darabszám: ügyfél');
$json = (string) $p->allapot;
T::nem_tartalmaz($json, $titkos_nev, 'ügyfél neve nem megy át');
T::nem_tartalmaz($json, '06301239876', 'ügyfél telefonja nem megy át');
T::nem_tartalmaz($json, 'titkos.ugyfel@pelda.hu', 'ügyfél e-mailje nem megy át');
T::nem_tartalmaz($json, (string) get_option('admin_email'), 'az adminisztrátor e-mailje sem');
T::igaz((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $K::t('meres') . ' WHERE peldany_id = %d', $pid)) === 1, 'mérés rögzítve');
T::igaz(isset($K::katalogus()['sms']['cim']), 'a modulkatalógus a példánytól');
T::igaz(in_array((string) $p->egeszseg, ['ok', 'figyel', 'hiba'], true), 'egészség kiszámolva');
T::igaz(in_array($K::statusz($p), ['online', 'figyel', 'hiba'], true), 'állapot: jelentkezik');
$oszlopok = array_map(static fn ($o): string => (string) $o->Field, (array) $wpdb->get_results('SHOW COLUMNS FROM ' . $K::t('peldany')));
T::igaz($oszlopok === [] || !array_intersect($oszlopok, ['domain', 'url', 'ugyfel', 'nev', 'email']), 'a példánytábla nem tárol címet, ügyfelet, nevet');

/* --- Licencelt modulok és jogok ---------------------------------------------------------------------------------- */

SDH_Muhely_Modulok::kapcsolok_urit();
T::igaz(in_array('sms', $L::tiltott_modulok(), true) && !in_array('nyomtatas', $L::tiltott_modulok(), true), 'a licenc szerint tiltott modulok');
T::igaz(!SDH_Muhely_Modulok::be('sms'), 'SMS nem fut (nincs a licencben)');
T::igaz(SDH_Muhely_Modulok::be('nyomtatas'), 'nyomtatás fut');
T::igaz(!in_array('sms', SDH_Muhely_Modulok::kikapcsoltak(), true), 'a saját kikapcsolás-lista érintetlen');

$K::licenc_ment(['id' => $lid, 'vevo_id' => $vid, 'modulok' => '*', 'jogok' => ['frissites' => 1, 'tamogatas' => 0, 'tavoli' => 1], 'max_peldany' => 2, 'lejar' => '2099-12-31']);
$L::szivveres();
T::egyezik([], $L::tiltott_modulok(), 'minden modul: nincs tiltott');
T::igaz(SDH_Muhely_Modulok::be('sms'), 'SMS újra fut');
T::tartalmaz((string) $L::tamogatas_indit(2), 'nem tartalmaz támogatást', 'támogatás nélküli licenc: nincs belépő link');
T::igaz(!$L::jogok()['tamogatas'] && $L::jogok()['tavoli'], 'a jogok a vevőnél');
$extra_u = wp_insert_user(['user_login' => 't92felh' . wp_rand(100, 999), 'user_pass' => wp_generate_password(), 'user_email' => 't92felh' . wp_rand(1000, 9999) . '@pelda.hu', 'role' => 'subscriber']);
$K::licenc_ment(['id' => $lid, 'vevo_id' => $vid, 'modulok' => '*', 'max_peldany' => 2, 'max_felhasznalo' => 1, 'lejar' => '2099-12-31']);
$L::szivveres();
T::tartalmaz($L::figyelmeztetes(), 'felhasználóra szól', 'felhasználószám túllépve: figyelmeztetés');
$K::licenc_ment(['id' => $lid, 'vevo_id' => $vid, 'modulok' => '*', 'max_peldany' => 2, 'max_felhasznalo' => 0, 'lejar' => '2099-12-31']);
$L::szivveres();

/* --- Távoli műveletek --------------------------------------------------------------------------------------------- */

$mid = $K::muvelet_kerj($pid, 'gyorsitotar', [], 'teszt');
T::igaz(is_int($mid), 'művelet sorba állítva');
T::igaz(is_string($K::muvelet_kerj($pid, 'rm_rf', [])), 'ismeretlen művelet: elutasítva');
$L::szivveres();
$e = array_column($TV::eredmenyek(), null, 'id');
T::igaz(!empty($e[$mid]['ok']), 'a vevőnél lefutott, eredmény vár');
$L::szivveres();
$m = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $K::t('muvelet') . ' WHERE id = %d', $mid));
T::egyezik('kesz', (string) ($m->allapot ?? ''), 'a központban: kész');
T::igaz(!isset(array_column($TV::eredmenyek(), null, 'id')[$mid]), 'nyugta után a vevőnél törlődik');
T::egyezik(0, $TV::vegrehajt([['id' => $mid, 'tipus' => 'gyorsitotar']], $L::jogok()), 'ugyanaz a művelet nem fut le kétszer');
$TV::vegrehajt([['id' => 999001, 'tipus' => 'halozat', 'lejar' => time() - 10]], $L::jogok());
T::tartalmaz((string) (array_column($TV::eredmenyek(), null, 'id')[999001]['uzenet'] ?? ''), 'Lejárt', 'lejárt művelet nem fut');
$TV::vegrehajt([['id' => 999002, 'tipus' => 'ismeretlen_uj']], $L::jogok());
T::tartalmaz((string) (array_column($TV::eredmenyek(), null, 'id')[999002]['uzenet'] ?? ''), 'nem ismeri', 'újabb művelettípus: tisztességes hiba');
$TV::nyugtaz([999001, 999002]);

// Üzenet a vevőnek.
$K::muvelet_kerj($pid, 'uzenet', ['szoveg' => 'Ma este frissítünk, T92.', 'szint' => 'figyel']);
$L::szivveres();
T::egyezik('Ma este frissítünk, T92.', (string) ($TV::adat()['uzenet']['szoveg'] ?? ''), 'üzenet a vevőnél');
ob_start();
$TV::savok();
$sav = (string) ob_get_clean();
T::tartalmaz($sav, 'Ma este frissítünk', 'a sáv az adminisztrátornak');
$K::muvelet_kerj($pid, 'uzenet_torol');
$L::szivveres();
T::igaz($TV::adat()['uzenet'] === null, 'üzenet levéve');

// A vevő kikapcsolja a távoli karbantartást.
$TV::engedely_ment(false);
$L::szivveres();
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT tavoli FROM ' . $K::t('peldany') . ' WHERE id = %d', $pid)), 'a központ látja: kikapcsolva');
$h = $K::muvelet_kerj($pid, 'halozat');
$L::szivveres();
$L::szivveres();
$m = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $K::t('muvelet') . ' WHERE id = %d', (int) $h));
T::egyezik('hiba', (string) ($m->allapot ?? ''), 'kikapcsolt karbantartás: nem fut');
T::tartalmaz((string) ($m->eredmeny ?? ''), 'kikapcsolta', 'és megmondja, miért');
$TV::engedely_ment(true);

// Licenc jog nélkül.
$K::licenc_ment(['id' => $lid, 'vevo_id' => $vid, 'modulok' => '*', 'jogok' => ['frissites' => 1, 'tamogatas' => 1, 'tavoli' => 0], 'max_peldany' => 2, 'lejar' => '2099-12-31']);
T::tartalmaz((string) $K::muvelet_kerj($pid, 'mentes'), 'nem tartalmaz távoli', 'jog nélkül a központ sem küldi');
T::igaz(is_int($K::muvelet_kerj($pid, 'uzenet', ['szoveg' => 'x'])), 'üzenet jog nélkül is mehet');
$K::licenc_ment(['id' => $lid, 'vevo_id' => $vid, 'modulok' => '*', 'max_peldany' => 2, 'lejar' => '2099-12-31', 'dij' => 120000, 'ciklus' => 'eves']);
if (is_int($extra_u)) {
    wp_delete_user($extra_u);
}

// Támogatási kérés: a vevő egy gombbal engedi.
$tk = $K::muvelet_kerj($pid, 'tamogatas_keres', ['ora' => 2, 'indok' => 'nyomtatási hiba T92']);
$L::szivveres();
T::igaz($TV::keres() !== null && (int) $TV::keres()['ora'] === 2, 'a kérés a vevőnél');
ob_start();
$TV::savok();
T::tartalmaz((string) ob_get_clean(), 'data-sdh-tavoli-valasz="1"', 'Engedélyezem gomb');
$v = $TV::valasz(true);
T::igaz($v['ok'] && !empty($v['link']), 'engedélyezve: belépő link');
$L::szivveres();
$L::szivveres();
T::egyezik('kesz', (string) $wpdb->get_var($wpdb->prepare('SELECT allapot FROM ' . $K::t('muvelet') . ' WHERE id = %d', (int) $tk)), 'a kérés a központban: kész');
T::igaz((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $K::t('tamogatas') . ' WHERE peldany = %s AND lejar > %s', $L::peldany(), current_time('mysql'))) >= 1, 'a link a központban');
T::egyezik(false, $TV::valasz(true)['ok'], 'ugyanaz a kérés másodszor: már nem érvényes');
$tk2 = $K::muvelet_kerj($pid, 'tamogatas_keres', ['ora' => 24]);
$L::szivveres();
$TV::valasz(false);
$L::szivveres();
T::egyezik('hiba', (string) $wpdb->get_var($wpdb->prepare('SELECT allapot FROM ' . $K::t('muvelet') . ' WHERE id = %d', (int) $tk2)), 'elutasított kérés');
foreach ($L::tamogatasok() as $t) {
    $L::tamogatas_visszavon($t['user']);
}

/* --- Álló rendszer, régi példány, egészség ------------------------------------------------------------------------ */

$K::idozitett(time() + 20 * MINUTE_IN_SECONDS);
$p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $K::t('peldany') . ' WHERE id = %d', $pid));
T::egyezik(1, (int) $p->offline_jelezve, '15 perc csend: nem jelentkezik');
T::egyezik('offline', $K::statusz($p, time() + 20 * MINUTE_IN_SECONDS), 'állapot: áll');
T::igaz((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $K::t('esemeny') . " WHERE peldany_id = %d AND tipus = 'riasztas' AND szoveg LIKE %s", $pid, '%Nem jelentkezik%')) === 1, 'riasztás a naplóban (egyszer)');
$K::idozitett(time() + 25 * MINUTE_IN_SECONDS);
T::igaz((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $K::t('esemeny') . " WHERE peldany_id = %d AND szoveg LIKE %s", $pid, '%Nem jelentkezik%')) === 1, 'nem ismétli');
$L::szivveres();
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT offline_jelezve FROM ' . $K::t('peldany') . ' WHERE id = %d', $pid)), 'újra jelentkezik');

$regi = wp_generate_uuid4();
$K::jelentes_feldolgoz(['kulcs' => (string) $lic->kulcs, 'peldany' => $regi, 'verzio' => '0.95.0']);
$rp = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $K::t('peldany') . ' WHERE peldany = %s', $regi));
T::egyezik('napi', $K::statusz($rp), 'régi (napi jelentős) példány');
T::igaz(is_string($K::muvelet_kerj((int) $rp->id, 'mentes')), 'régi példánynak nincs távoli művelet');

$eg = $K::egeszseg(['mentes' => ['utolso' => 0], 'muhely' => ['tesztuzem' => true], 'sebesseg' => ['atlag_ms' => 5000], 'lemez' => ['szabad_mb' => 100], 'hibak' => [['db' => 2, 'utolso' => time()]]], null);
$kodok = array_column($eg['problemak'], 'szint', 'kod');
T::egyezik('hiba', $eg['szint'], 'egészség: hiba');
T::igaz(($kodok['mentes'] ?? '') === 'hiba' && ($kodok['lassu'] ?? '') === 'hiba' && ($kodok['lemez'] ?? '') === 'hiba' && ($kodok['hiba'] ?? '') === 'hiba' && ($kodok['tesztuzem'] ?? '') === 'figyel', 'mentés, lassúság, lemez, végzetes hiba, tesztüzem');
T::egyezik('ok', $K::egeszseg(['mentes' => ['utolso' => time() - 3600], 'sebesseg' => ['atlag_ms' => 200], 'lemez' => ['szabad_mb' => 50000]], null)['szint'], 'egészséges rendszer: rendben');

/* --- Telemetria ------------------------------------------------------------------------------------------------------- */

T::egyezik('Hiba: [e-mail], hívd: [szám]', $TE::maszkol('Hiba: kovacs.bela@pelda.hu, hívd: +36 30 123 4567'), 'hibaüzenetben e-mail és szám maszkolva');
$TE::hiba_rogzit(E_ERROR, "Uncaught Error: valami a@b.hu\n#0 stack", ABSPATH . 'wp-content/plugins/x/y.php', 12);
$TE::hiba_rogzit(E_ERROR, 'Uncaught Error: valami', ABSPATH . 'wp-content/plugins/x/y.php', 12);
$hb = array_values((array) get_option($TE::OPT_HIBA));
T::igaz(count($hb) >= 1 && (int) $hb[0]['db'] === 2 && $hb[0]['fajl'] === 'wp-content/plugins/x/y.php', 'végzetes hiba: helye és száma (a gép útvonala nélkül)');
$o = $TE::osszeallit();
T::egyezik(2, (int) $o['meres']['hiba'], 'az új hibák a mérésben');
T::egyezik(0, (int) $TE::osszeallit()['meres']['hiba'], 'egyszer számolva');
delete_option($TE::OPT_HIBA);

/* --- A Központ felülete (REST) ---------------------------------------------------------------------------------------- */

$r = $app('GET', 'attekintes');
T::egyezik(200, $r->get_status(), 'áttekintés');
$d = $r->get_data();
T::igaz((int) $d['rendszerek']['osszes'] >= 2 && isset($d['flotta'][23], $d['licencek']['aktiv']), 'áttekintés: rendszerek, flotta, licencek');
T::igaz((int) $d['mrr'] >= 10000, 'havi bevétel az éves díjból (' . (int) $d['mrr'] . ' Ft)');
$r = $app('GET', 'peldany/' . $pid);
T::igaz($r->get_status() === 200 && isset($r->get_data()['allapot']['rendszer'], $r->get_data()['muveletek'][0]), 'rendszer adatlap: állapot és műveletek');
$r = $app('GET', 'peldany/' . $pid . '/meres', [], ['tav' => '24h']);
T::igaz($r->get_status() === 200 && count($r->get_data()['t']) >= 1 && count($r->get_data()['t']) === count($r->get_data()['valasz_ms']), 'mérési sor');
T::egyezik(200, $app('POST', 'peldany/' . $pid, ['cimke' => 'Pult gép T92', 'elo' => 15])->get_status(), 'elnevezés + élő követés');
T::egyezik('Pult gép T92', (string) $wpdb->get_var($wpdb->prepare('SELECT cimke FROM ' . $K::t('peldany') . ' WHERE id = %d', $pid)), 'elnevezés mentve');
$L::szivveres();
T::egyezik(SDH_Kozpont::INTERVALLUM_ELO, (int) ($K::szivveres_feldolgoz(['kulcs' => (string) $lic->kulcs, 'peldany' => $L::peldany(), 'allapot' => ['muhely' => ['tavoli' => true]]])['intervallum'] ?? 0), 'élő követésben gyakoribb szívverés');
T::igaz(count($app('GET', 'vevok')->get_data()['lista']) >= 2, 'vevőlista');
T::igaz(count($app('GET', 'vevo/' . $vid)->get_data()['esemenyek']) >= 1, 'vevő idővonala');
T::egyezik(200, $app('POST', 'vevo/' . $vid . '/jegyzet', ['szoveg' => 'Felhívtam, T92'])->get_status(), 'jegyzet');
T::igaz(count($app('GET', 'licencek')->get_data()['lista']) >= 2, 'licenclista');
T::igaz(count($app('GET', 'kereses', [], ['q' => 'T92'])->get_data()['lista']) >= 2, 'keresés');
T::igaz(count($app('GET', 'naplo', [], ['tipus' => 'riasztas'])->get_data()['lista']) >= 1, 'napló szűrve');
$b = $app('POST', 'beallitas', ['offline_perc' => 30, 'csomagok' => [['nev' => 'Pro T92', 'modulok' => ['sms', 'naptar'], 'max_peldany' => 3]]])->get_data();
T::igaz((int) $b['b']['offline_perc'] === 30 && $b['b']['csomagok'][0]['nev'] === 'Pro T92', 'beállítások és csomagok');
T::egyezik(200, $app('POST', 'licenc/' . $lid . '/muvelet', ['muvelet' => 'megujit'])->get_status(), 'megújítás');
wp_set_current_user(0);
T::igaz(in_array($app('GET', 'attekintes')->get_status(), [401, 403], true), 'kijelentkezve: nincs hozzáférés');
wp_set_current_user(1);

/* --- Belépés után a Központ --------------------------------------------------------------------------------------------- */

$admin = get_userdata(1);
T::tartalmaz((string) SDH_Kozpont_App::belepes_utan(admin_url(), '', $admin), 'page=sdh-kozpont', 'belépés után a Központ nyílik meg');
T::egyezik(admin_url('plugins.php'), SDH_Kozpont_App::belepes_utan(admin_url('plugins.php'), admin_url('plugins.php'), $admin), 'konkrét oldalra kért belépés: oda megy');

delete_option($L::OPT);
delete_option($TV::OPT);
$TE::bekapcsol(false);

T::vege('t92 központ 2.0: szívverés, jogok, távoli műveletek');
