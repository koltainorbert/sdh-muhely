<?php
/**
 * t311 – Rejtés a REST-ben (független ellenőrzés M-3, M-4, K-7 javításai, 2026-10-20):
 * - a nyilvános utak listája (saját névtér útjai igen, a névtér-index nem; a Központ példány-útjai mindkét névtérben),
 * - a rendszer hibakódjai / angol üzenetei semleges magyar kódra és szövegre cserélve, a `Link: …api.w.org`, `X-WP-*`
 *   fejlécek és a CORS-listák rendszer-nevei nélkül,
 * - `?rest_route=` látogatónak csak a nyilvános utakra (a vevők Műhelye így hívja a Központot – szívverés, csomag),
 * - élő HTTP (php -S + a fejlesztői útválasztó): admin-végpont, rossz metódus, névtér-index, gyökér, OPTIONS, core REST,
 *   egy szimulált Műhely-szívverés `?rest_route=/sdh-kozpont/v1/szivveres` és `/api/licenc/v1/szivveres` alakban.
 */
require __DIR__ . '/_keret.php';
require_once __DIR__ . '/_seo-minta.php';

$R = 'SDH_Sales_Rejtes';

/* --- Nyilvános utak ------------------------------------------------------------------------------------------------ */

foreach (['/portal/v1/ar' => true, '/portal/v1/rendeles/R-2026-000001/allapot' => true, '/portal/v1' => false, '/portal/v1/' => false, '/' => false, '' => false,
    '/wp/v2/users' => false, '/wp/v2/users/me' => false, '/sdh-kozpont/v1/szivveres' => true, '/sdh-kozpont/v1/csomag' => true, '/licenc/v1/szivveres' => true,
    '/licenc/v1/kiszolgalas' => true, '/licenc/v1/letoltes' => true, '/sdh-kozpont/v1/app/beallitas' => false, '/sdh-kozpont/v1' => false, '/licenc/v1' => false,
    '/portal/v1x/ar' => false, '/sdh-kozpont/v1/szivveres/x' => false] as $ut => $vart) {
    T::egyezik($vart, $R::nyilvanos_ut($ut), 'nyilvános út: ' . ($ut === '' ? '(üres)' : $ut));
}

/* --- ?rest_route= (korai szűrés) ---------------------------------------------------------------------------------- */

wp_set_current_user(0);
foreach (['/sdh-kozpont/v1/szivveres' => false, '/licenc/v1/csomag' => false, '/portal/v1/ar' => false, '/wp/v2/users' => true, '/portal/v1' => true, '/' => true, '/sdh-kozpont/v1/app/beallitas' => true] as $ut => $tilt) {
    $_GET['rest_route'] = $ut;
    T::egyezik($tilt, $R::kerulout_tiltott(), '?rest_route=' . $ut . ' látogatónak ' . ($tilt ? '404' : 'átmegy'));
}
unset($_GET['rest_route']);
T::egyezik(false, $R::kerulout_tiltott(), '?rest_route= nélkül nincs tiltás');

/* --- rest_zar (hitelesítési szűrő) -------------------------------------------------------------------------------- */

foreach (['/wp/api/portal/v1' => 404, '/wp/api/' => 404, '/wp/api/wp/v2/users' => 404, '/wp/api/sdh-kozpont/v1/app/beallitas' => 404, '/wp/api/licenc/v1/szivveres' => 0, '/wp/api/portal/v1/ar' => 0] as $uri => $kod) {
    $_SERVER['REQUEST_URI'] = $uri;
    $z = $R::rest_zar(null);
    T::egyezik($kod, is_wp_error($z) ? (int) ($z->get_error_data()['status'] ?? 0) : 0, 'rest_zar látogatónak: ' . $uri);
}
$_SERVER['REQUEST_URI'] = '/wp/';

/* --- Hibák és fejlécek semlegesítése ------------------------------------------------------------------------------- */

T::egyezik(['tiltott', 'Ehhez nincs jogosultságod.'], $R::hiba_semleges('rest_forbidden', 401), 'rest_forbidden → tiltott');
T::egyezik(['nincs', 'Nincs ilyen cím.'], $R::hiba_semleges('rest_no_route', 404), 'rest_no_route → nincs');
T::egyezik(['lejart', 'A munkamenet lejárt – töltsd újra az oldalt.'], $R::hiba_semleges('rest_cookie_invalid_nonce', 403), 'rest_cookie_invalid_nonce → lejart');
T::egyezik('tiltott', $R::hiba_semleges('sdh_kozpont_tiltva', 403)[0] ?? '', 'sdh… kód → semleges (a saját üzenet marad)');
T::egyezik(null, $R::hiba_semleges('nincs', 404), 'saját semleges kód: változatlan');

$v = new WP_REST_Response(['code' => 'rest_forbidden', 'message' => 'Sorry, you are not allowed to do that.', 'data' => ['status' => 401]], 401);
$v->header('Link', '<http://x/wp/api/>; rel="https://api.w.org/"');
$v->header('X-WP-Total', '3');
$v = $R::rest_valasz_csere($v);
T::egyezik(['code' => 'tiltott', 'message' => 'Ehhez nincs jogosultságod.', 'data' => ['status' => 401]], $v->get_data(), 'válasz: semleges kód + magyar üzenet');
T::igaz(!isset($v->get_headers()['Link']) && !isset($v->get_headers()['X-WP-Total']), 'válasz: Link / X-WP-Total fejléc nélkül');
$v = $R::rest_hiba_csere(new WP_Error('rest_invalid_param', 'Invalid parameter(s): kod', ['status' => 400, 'params' => ['kod' => 'x']]));
T::igaz($v instanceof WP_Error && $v->get_error_code() === 'hibas_adat' && $v->get_error_message() === 'Hibás adat a kérésben.' && ($v->get_error_data()['mezok'] ?? []) === ['kod'], 'callback-hiba: hibas_adat + a mező neve');
T::egyezik([], $R::cors_kitett(['X-WP-Total', 'X-WP-TotalPages', 'Link']), 'CORS: kitett fejlécek rendszer-nevek nélkül');
$eng = $R::cors_engedett(['Authorization', 'X-WP-Nonce', 'Content-Type']);
T::igaz(!in_array('X-WP-Nonce', $eng, true) && in_array('X-App-Nonce', $eng, true), 'CORS: X-WP-Nonce helyett X-App-Nonce');

/* --- Élő HTTP ------------------------------------------------------------------------------------------------------ */

// A Központ: egy licenc, amelyre a szimulált Műhely szívverést küld.
$KP = class_exists('SDH_Kozpont');
$kulcs = '';

if ($KP) {
    SDH_Kozpont::sema();
    $vid = SDH_Kozpont::vevo_ment(['nev' => 'Szívverés Teszt Kft. T311', 'email' => 't311-' . wp_rand(1000, 9999) . '@pelda.hu']);
    $lid = is_int($vid) ? SDH_Kozpont::licenc_ment(['vevo_id' => $vid, 'csomag' => 'Pro', 'modulok' => '*', 'max_peldany' => 3, 'ciklus' => 'eves', 'lejar' => wp_date('Y-m-d', time() + 300 * DAY_IN_SECONDS)]) : 0;
    $kulcs = is_int($lid) ? (string) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT kulcs FROM ' . SDH_Kozpont::t('licenc') . ' WHERE id = %d', $lid)) : '';
}

flush_rewrite_rules(false);
$gyoker = dirname(ABSPATH);
$sock   = stream_socket_server('tcp://127.0.0.1:0');
$port   = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
update_option('home', 'http://127.0.0.1:' . $port . '/wp');
update_option('siteurl', 'http://127.0.0.1:' . $port . '/wp');
$napl = sys_get_temp_dir() . '/t311-' . getmypid() . '.log';
$pr   = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $gyoker, dirname(__DIR__) . '/eszkozok/router.php'], [1 => ['file', $napl, 'a'], 2 => ['file', $napl, 'a']], $cs);
usleep(700000);

/** @return array{0: int, 1: string, 2: string} állapotkód, törzs, fejlécek */
$kerj = static function (string $metodus, string $ut, ?array $json = null) use ($port): array {
    $o = ['method' => $metodus, 'ignore_errors' => true, 'timeout' => 20];

    if ($json !== null) {
        $o['header']  = "Content-Type: application/json\r\n";
        $o['content'] = (string) wp_json_encode($json);
    }

    $t = @file_get_contents('http://127.0.0.1:' . $port . $ut, false, stream_context_create(['http' => $o]));
    $f = implode("\n", $http_response_header ?? []);
    preg_match('~^HTTP/\S+\s+(\d{3})~', $f, $m);

    return [(int) ($m[1] ?? 0), (string) $t, $f];
};

$api = '/wp/' . $R::api();
[$k, $t, $f] = $kerj('GET', $api . '/portal/v1/admin/kozpont');
$d = json_decode($t, true);
T::egyezik(401, $k, 'élő: admin-végpont látogatónak 401');
T::egyezik('tiltott', (string) ($d['code'] ?? ''), 'élő: semleges hibakód (nem rest_forbidden)');
T::egyezik('Ehhez nincs jogosultságod.', (string) ($d['message'] ?? ''), 'élő: magyar hibaüzenet');
T::igaz(stripos($f, 'api.w.org') === false && stripos($f, "\nLink:") === false, 'élő: nincs Link / api.w.org fejléc');
T::igaz(stripos($f, 'X-WP-') === false, 'élő: nincs X-WP-* (sem a CORS-listákban)');
t_rejtett($t, 'élő: admin-végpont hibája', ['nyers' => $f]);

[$k, $t, $f] = $kerj('POST', $api . '/portal/v1/rendeles/R-2026-000001/allapot');
T::igaz($k === 404 && (json_decode($t, true)['code'] ?? '') === 'nincs' && stripos($t, 'No route') === false, 'élő: rossz metódus → 404 „nincs”, magyarul');
t_rejtett($t, 'élő: rossz metódus', ['nyers' => $f]);

foreach ([$api . '/portal/v1' => 'névtér-index', $api . '/' => 'REST-gyökér', $api . '/wp/v2/users' => 'core REST', '/wp/?rest_route=/wp/v2/users' => 'core REST ?rest_route=', '/wp/?rest_route=/' => 'gyökér ?rest_route=', $api . '/sdh-kozpont/v1/app/beallitas' => 'Központ-felület látogatónak'] as $ut => $nev) {
    [$k, $t, $f] = $kerj('GET', $ut);
    T::egyezik(404, $k, 'élő: ' . $nev . ' → 404');
    T::igaz(stripos($t, '/portal/v1/admin') === false && stripos($t, 'namespace') === false, 'élő: ' . $nev . ' – nincs útvonal-lista');
}

[$k, $t] = $kerj('OPTIONS', $api . '/portal/v1/ar');
T::igaz($k === 404 && stripos($t, 'schema') === false, 'élő: OPTIONS (séma) látogatónak 404');

[$k, $t, $f] = $kerj('POST', $api . '/portal/v1/ar', ['tetelek' => [], 'opciok' => ['ciklus' => 'havi']]);
T::egyezik(200, $k, 'élő: a saját nyilvános végpont működik');
T::igaz(stripos($f, 'api.w.org') === false, 'élő: a sikeres válaszban sincs api.w.org');

// Szimulált Műhely-szívverés: a vevők Műhelye `?rest_route=/sdh-kozpont/v1/…` alakban hív (class-sdh-muhely-licenc.php).
if ($KP && $kulcs !== '') {
    $szv = ['kulcs' => $kulcs, 'peldany' => wp_generate_uuid4(), 'ujjlenyomat' => substr(hash('sha256', 't311'), 0, 24), 'verzio' => '0.99.4', 'allapot' => [], 'meres' => [], 'eredmenyek' => []];
    [$k, $t, $f] = $kerj('POST', '/wp/?rest_route=/sdh-kozpont/v1/szivveres', $szv);
    $d = json_decode($t, true);
    T::egyezik(200, $k, 'M-4: Műhely-szívverés ?rest_route=/sdh-kozpont/v1/szivveres → 200 (nem 404)');
    T::igaz(is_array($d) && isset($d['adat'], $d['alairas']), 'M-4: aláírt Központ-válasz');
    $ki = is_array($d) ? json_decode((string) base64_decode((string) $d['adat'], true), true) : null;
    T::egyezik('aktiv', (string) ($ki['allapot'] ?? ''), 'M-4: a licenc állapota a válaszban: aktív');
    [$k, $t] = $kerj('POST', $api . '/licenc/v1/szivveres', $szv);
    T::igaz($k === 200 && isset(json_decode($t, true)['alairas']), 'K-7: szívverés a semleges /' . $R::api() . '/licenc/v1/szivveres úton is');
    [$k, $t] = $kerj('POST', '/wp/?rest_route=/licenc/v1/jelentes', $szv);
    T::egyezik(200, $k, 'M-4: jelentés ?rest_route=/licenc/v1/jelentes → 200');
    [$k, $t, $f] = $kerj('GET', '/wp/?rest_route=/sdh-kozpont/v1/csomag&kulcs=' . rawurlencode($kulcs) . '&peldany=nincs');
    T::igaz($k === 403 && (json_decode($t, true)['code'] ?? '') === 'tiltva', 'M-4: frissítő csomag ?rest_route= alakban: eléri a Központot (403 semleges kóddal, nem 404)');
    t_rejtett($t, 'élő: csomag-hiba', ['nyers' => $f]);
} else {
    T::igaz(false, 'A Központ nincs bekapcsolva a tesztoldalon – a szívverés-próba nem futott');
}

proc_terminate($pr);
proc_close($pr);
@unlink($napl);
update_option('home', 'http://127.0.0.1:8765/wp');
update_option('siteurl', 'http://127.0.0.1:8765/wp');

T::vege('t311 rejtés a REST-ben');
