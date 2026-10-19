<?php
/**
 * t269 – Google Naptár-küldés (alapból ki; OAuth refresh token titokként; új foglalás felküldve, lemondott törölve,
 * 404 a törlésnél is rendben, token-hiba naplózva) és a front-szerződés (shortcode → data-sdh="foglalas", a modul
 * szkriptje + adatai csak igény esetén, a JS-ben nincs jQuery / natív dátummező / confirm / alert / beégetett szín).
 */
require __DIR__ . '/_t26-seged.php';

global $wpdb;
t26_naptar(['naptar.min_ora' => 0]);

// --- Google ---
T::igaz(!SDH_Sales_Naptar::google_be(), 'Google alapból ki');
T::egyezik(0, SDH_Sales_Naptar::google_szinkron(), 'kikapcsolva: nincs szinkron');
$k = static fn () => array_filter($GLOBALS['sdh_t_keresek'], static fn ($x) => strpos($x['url'], 'google') !== false);
T::egyezik(0, count($k()), 'kikapcsolva: nincs Google-kérés');

SDH_Sales::b_ment(['naptar.google.be' => true, 'naptar.google.client_id' => 'abc.apps.googleusercontent.com', 'naptar.google.naptar_id' => 'iroda@pelda.hu']);
T::igaz(!SDH_Sales_Naptar::google_be(), 'titkok nélkül ki');
SDH_Sales::titkos_ment('google_client_secret', 'titok-1');
SDH_Sales::titkos_ment('google_refresh_token', 'refresh-1');
T::igaz(SDH_Sales_Naptar::google_be(), 'bekapcsolva + titkok → be');

$g = [];
t_http(static function (string $url, array $a) use (&$g): ?array {
    if (strpos($url, 'oauth2.googleapis.com/token') !== false) {
        $g[] = ['token', $a['body']['refresh_token'] ?? ''];
        return t_valasz(200, (string) wp_json_encode(['access_token' => 'at-1', 'expires_in' => 3600]));
    }
    if (strpos($url, 'googleapis.com/calendar/v3/calendars/') !== false) {
        $g[] = [$a['method'] ?? 'GET', $url, $a['headers']['Authorization'] ?? '', (string) ($a['body'] ?? '')];
        if (($a['method'] ?? '') === 'POST') {
            return t_valasz(200, (string) wp_json_encode(['id' => 'gev' . count($g)]));
        }
        if (($a['method'] ?? '') === 'DELETE') {
            return t_valasz(strpos($url, 'gone') !== false ? 410 : 204, '');
        }
        return t_valasz(200, '{"items":[]}');
    }
    return null;
});

$most = time();
$tol  = (new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')))->modify('+2 days')->format('Y-m-d');
$sav  = array_values(SDH_Sales_Naptar::savok('demo', $tol, (new DateTimeImmutable($tol))->modify('+10 days')->format('Y-m-d')));
$e    = SDH_Sales_Naptar::foglal(['tipus' => 'demo', 'kezdet' => $sav[0][0]['utc'], 'nev' => 'Kis Anna', 'email' => 'anna@pelda.hu', 'ceg' => 'Próba'], $most);
T::igaz(is_array($e), 'foglalás');
T::egyezik(1, SDH_Sales_Naptar::google_szinkron(), 'egy esemény felküldve');
T::egyezik(['token', 'refresh-1'], $g[0] ?? [], 'refresh token → access token');
T::egyezik('POST', $g[1][0] ?? '', 'esemény POST');
T::tartalmaz($g[1][1] ?? '', '/calendars/iroda%40pelda.hu/events', 'a beállított naptárba');
T::egyezik('Bearer at-1', $g[1][2] ?? '', 'Bearer token');
$b = json_decode($g[1][3] ?? '', true);
T::igaz(($b['start']['dateTime'] ?? '') === $sav[0][0]['utc'] && ($b['start']['timeZone'] ?? '') === 'Europe/Budapest', 'esemény kezdete UTC + időzóna');
T::tartalmaz((string) ($b['summary'] ?? ''), 'Kis Anna', 'esemény címe');
T::egyezik('gev2', (string) SDH_Sales_Naptar::egy($e['id'])['google_id'], 'google_id mentve');
T::egyezik(0, SDH_Sales_Naptar::google_szinkron(), 'ismételt szinkron: semmi új');
T::egyezik(1, count(array_filter($g, static fn ($x) => $x[0] === 'token')), 'access token gyorsítótárban');

SDH_Sales_Naptar::lemond($e['token'], $most);
T::egyezik(1, SDH_Sales_Naptar::google_szinkron(), 'lemondott: törölve a Google-ból');
T::igaz(($g[count($g) - 1][0] ?? '') === 'DELETE' && strpos($g[count($g) - 1][1] ?? '', '/events/gev2') !== false, 'DELETE az esemény azonosítójával');
T::egyezik('', (string) SDH_Sales_Naptar::egy($e['id'])['google_id'], 'google_id törölve');
$wpdb->insert(SDH_Sales_Schema::t('foglalas'), ['tipus' => 'demo', 'kezdet' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS), 'veg' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS + 1800), 'nev' => 'G', 'email' => 'g@pelda.hu', 'allapot' => 'lemondva', 'token' => 'n', 'google_id' => 'gone1', 'ido' => gmdate('Y-m-d H:i:s')]);
T::egyezik(1, SDH_Sales_Naptar::google_szinkron(), '410 (már törölt) is rendben');
$r = t_rest('POST', '/admin/naptar/google');
T::egyezik(200, $r->get_status(), 'admin Google-próba');

// --- front ---
$html = do_shortcode('[sdh_foglalas tipus="support"]');
T::tartalmaz($html, 'data-sdh="foglalas"', 'shortcode: DOM-szerződés');
T::tartalmaz($html, 'data-tipus="support"', 'shortcode: típus');
T::igaz(SDH_Sales_Front::kell('foglalas'), 'shortcode igényli a modult');
ob_start();
SDH_Sales_Front::kiir();
ob_end_clean();
T::igaz(wp_script_is('sdh-sales-foglalas', 'enqueued'), 'foglalas.js betöltve');
$inline = implode('', (array) wp_scripts()->get_data('sdh-sales-foglalas', 'before'));
T::tartalmaz($inline, 'window.SDH_FOGLALAS=', 'adat: window.SDH_FOGLALAS');
T::tartalmaz($inline, '"tipusok"', 'adat: típusok');
T::nem_tartalmaz($inline, '{termeknev}', 'adat: token cserélve');

$js = (string) file_get_contents(SDH_SALES_DIR . 'assets/front/foglalas.js');
$kod = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $js); // megjegyzések nélkül
T::egyezik(0, preg_match('/jQuery|\$\(/', $kod), 'JS: jQuery nélkül');
T::egyezik(0, preg_match('/type["\']?\s*[:=]\s*["\']date/', $kod), 'JS: nincs natív dátummező');
T::egyezik(0, preg_match('/\b(confirm|alert)\s*\(/', $kod), 'JS: nincs confirm()/alert()');
T::egyezik(0, preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(/', $kod), 'JS: nincs beégetett szín');
T::tartalmaz($js, "'H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'", 'JS: hétfővel kezdődő magyar hét');
T::tartalmaz($js, "role: 'grid'", 'JS: rács-szerep (akadálymentes)');
T::tartalmaz($js, 'ArrowDown', 'JS: billentyűzetes navigáció');
T::tartalmaz($js, "'aria-live': 'polite'", 'JS: élő régió');

T::vege('t269 Google + front');
