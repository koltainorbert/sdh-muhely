<?php
/**
 * t265 – Foglalás: érvényes foglalás (UTC-ben tárolva), dupla foglalás kizárva (egymás után és két párhuzamos
 * folyamatból), ellenőrzések (név, e-mail, csapdamező, nem létező / foglalt / múltbeli sáv), rendeléshez kötött
 * típus csak ajánlat-linkkel (ajanlat_link), előtöltés, visszaigazoló levél ICS-csatolmánnyal, értesítés Norbinak,
 * REST (201, kezelő link, ICS-link), admin lista token nélkül.
 */
require __DIR__ . '/_t26-seged.php';

global $wpdb;
t26_naptar();
SDH_Sales::b_ment(['email.ertesites' => 'norbi@pelda.hu', 'ceg.nev' => 'Próba Kft.']);

$most = t26_bp('2026-10-19 08:00');
$k    = '2026-10-20T07:00:00Z'; // kedd 9:00 Budapest
$be   = ['tipus' => 'demo', 'kezdet' => $k, 'nev' => 'Kis Anna', 'email' => 'anna@pelda.hu', 'telefon' => '+36 30 123 4567', 'ceg' => 'Próba Szerviz', 'megjegyzes' => 'Két telephely'];

$GLOBALS['t26_levelek'] = [];
$e = SDH_Sales_Naptar::foglal($be, $most);
T::igaz(is_array($e) && $e['id'] > 0, 'foglalás sikeres');
$f = SDH_Sales_Naptar::egy($e['id']);
T::egyezik('2026-10-20 07:00:00', (string) $f['kezdet'], 'kezdet UTC-ben');
T::egyezik('2026-10-20 07:30:00', (string) $f['veg'], 'vég UTC-ben (30 perc)');
T::egyezik('foglalt', (string) $f['allapot'], 'állapot foglalt');
T::igaz((string) $f['token'] !== '' && strpos($e['token'], (string) $f['token']) === false, 'a kezelő token nincs nyíltan az adatbázisban');
T::egyezik(0, (int) $f['emlekezteto_kuldve'], 'emlékeztető még nem ment');

$level = $GLOBALS['t26_levelek'][0] ?? [];
T::egyezik('anna@pelda.hu', $level['to'] ?? '', 'visszaigazolás a foglalónak');
T::tartalmaz($level['subject'] ?? '', '2026. október 20. (kedd) 9:00', 'tárgy: helyi idő, magyarul');
T::tartalmaz($level['message'] ?? '', 'foglalas=' . rawurlencode($e['token']), 'levélben a lemondó / áthelyező link');
T::igaz(isset($level['csatolmany']['idopont.ics']) && strpos($level['csatolmany']['idopont.ics'], 'BEGIN:VCALENDAR') === 0, 'ICS csatolva');
T::egyezik('norbi@pelda.hu', $GLOBALS['t26_levelek'][1]['to'] ?? '', 'értesítés Norbinak');
T::tartalmaz($GLOBALS['t26_levelek'][1]['message'] ?? '', 'Két telephely', 'értesítésben a megjegyzés');

// dupla foglalás – ugyanaz és átfedő (puffer) sáv
$e2 = SDH_Sales_Naptar::foglal(['email' => 'b@pelda.hu', 'nev' => 'Béla'] + $be, $most);
T::igaz(is_wp_error($e2) && $e2->get_error_data()['status'] === 409, 'ugyanaz a sáv: 409');
$e3 = SDH_Sales_Naptar::foglal(['kezdet' => '2026-10-20T07:30:00Z', 'email' => 'c@pelda.hu'] + $be, $most);
T::igaz(is_wp_error($e3), 'puffer miatt átfedő sáv: elutasítva');
$e4 = SDH_Sales_Naptar::foglal(['kezdet' => '2026-10-20T08:00:00Z', 'email' => 'd@pelda.hu'] + $be, $most);
T::igaz(is_array($e4), 'puffer utáni sáv: foglalható');
T::egyezik(2, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . SDH_Sales_Schema::t('foglalas') . " WHERE allapot = 'foglalt'"), '2 élő foglalás');

// két párhuzamos folyamat ugyanarra a sávra: pontosan egy nyer
$kod = <<<'PHP'
<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:8765'; $_SERVER['REQUEST_URI'] = '/wp/'; $_SERVER['REMOTE_ADDR'] = '203.0.113.' . $argv[2];
require getenv('SDH_WP_LOAD');
add_filter('sdh_sales_korlat_ki', '__return_true');
add_filter('pre_wp_mail', '__return_true');
$cel = (float) $argv[3];
while (microtime(true) < $cel) { usleep(1000); }
$e = SDH_Sales_Naptar::foglal(['tipus' => 'demo', 'kezdet' => '2026-10-21T07:00:00Z', 'nev' => 'P' . $argv[2], 'email' => 'p' . $argv[2] . '@pelda.hu'], (int) $argv[1]);
echo is_array($e) ? 'OK' : 'HIBA:' . $e->get_error_data()['status'];
PHP;
$fajl = tempnam(sys_get_temp_dir(), 't265') . '.php';
file_put_contents($fajl, $kod);
$cel = microtime(true) + 1.5;
$p   = [];
for ($i = 1; $i <= 4; $i++) {
    $p[$i] = proc_open([PHP_BINARY, $fajl, (string) $most, (string) $i, (string) $cel], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cso[$i]);
}
$ki = [];
for ($i = 1; $i <= 4; $i++) {
    $ki[] = trim((string) stream_get_contents($cso[$i][1]));
    proc_close($p[$i]);
}
@unlink($fajl);
$nyert = count(array_filter($ki, static fn ($x) => $x === 'OK'));
T::egyezik(1, $nyert, 'párhuzamos foglalás: pontosan 1 nyer (' . implode(',', $ki) . ')');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . SDH_Sales_Schema::t('foglalas') . " WHERE allapot = 'foglalt' AND kezdet = %s", '2026-10-21 07:00:00')), 'párhuzamos: egy sor az adatbázisban');
T::egyezik(3, count(array_filter($ki, static fn ($x) => in_array($x, ['HIBA:409', 'HIBA:503'], true))), 'párhuzamos: a többi szabályos elutasítás');

// ellenőrzések
$hiba = static fn (array $x) => SDH_Sales_Naptar::foglal($x + $be, $most);
T::igaz(is_wp_error($hiba(['nev' => 'A', 'kezdet' => '2026-10-22T07:00:00Z'])), 'túl rövid név');
T::igaz(is_wp_error($hiba(['email' => 'nem-email', 'kezdet' => '2026-10-22T07:00:00Z'])), 'rossz e-mail');
T::igaz(is_wp_error($hiba(['weboldal' => 'http://spam', 'kezdet' => '2026-10-22T07:00:00Z'])), 'csapdamező');
T::igaz(is_wp_error($hiba(['kezdet' => '2026-10-22T07:10:00Z'])), 'rácson kívüli időpont');
T::igaz(is_wp_error($hiba(['kezdet' => '2026-10-24T07:00:00Z'])), 'hétvége');
T::igaz(is_wp_error($hiba(['kezdet' => '2026-10-19T09:00:00Z'])), '12 órán belüli');
T::igaz(is_wp_error($hiba(['kezdet' => 'holnap'])), 'értelmetlen kezdet');
T::igaz(is_wp_error($hiba(['tipus' => 'nincs', 'kezdet' => '2026-10-22T07:00:00Z'])), 'ismeretlen típus');

// rendeléshez kötött típus (telepítés): csak ajánlat-linkkel
$tel = ['tipus' => 'telepites', 'kezdet' => '2026-10-22T07:00:00Z'] + $be;
$e5  = SDH_Sales_Naptar::foglal($tel, $most);
T::igaz(is_wp_error($e5) && $e5->get_error_data()['status'] === 403, 'telepítés link nélkül: 403');
$rid  = t26_rendeles();
$link = SDH_Sales_Naptar::ajanlat_link($rid, 'telepites');
$q    = [];
parse_str((string) wp_parse_url($link, PHP_URL_QUERY), $q);
T::igaz(($q['tipus'] ?? '') === 'telepites' && strlen((string) ($q['rt'] ?? '')) === 24 && strpos($link, '#foglalas') !== false, 'ajanlat_link: tipus + rendeles + rt');
$e6 = SDH_Sales_Naptar::foglal($tel + ['rendeles' => $q['rendeles'], 'rt' => 'x' . substr($q['rt'], 1)], $most);
T::igaz(is_wp_error($e6), 'hamis rt: elutasítva');
$e7 = SDH_Sales_Naptar::foglal($tel + ['rendeles' => $q['rendeles'], 'rt' => $q['rt']], $most);
T::igaz(is_array($e7), 'telepítés ajánlat-linkkel: foglalva');
T::egyezik($rid, (int) SDH_Sales_Naptar::egy($e7['id'])['rendeles_id'], 'foglalás a rendeléshez kötve');
T::egyezik('2026-10-22 08:30:00', (string) SDH_Sales_Naptar::egy($e7['id'])['veg'], 'telepítés 90 perc');
$r = t_latogato(static fn () => t_rest('GET', '/foglalas/elotolt', ['tipus' => 'telepites', 'rendeles' => $q['rendeles'], 'rt' => $q['rt']]));
T::igaz($r->get_status() === 200 && ($r->get_data()['email'] ?? '') === 'anna@pelda.hu' && ($r->get_data()['ceg'] ?? '') === 'Próba Szerviz Kft.', 'előtöltés a rendelésből');
T::egyezik(404, t_latogato(static fn () => t_rest('GET', '/foglalas/elotolt', ['tipus' => 'oktatas', 'rendeles' => $q['rendeles'], 'rt' => $q['rt']]))->get_status(), 'előtöltés: más típus tokenje nem jó');

// ajanlat_kuld: idopont_ajanlat levél
$GLOBALS['t26_levelek'] = [];
T::igaz(SDH_Sales_Naptar::ajanlat_kuld($rid, 'telepites'), 'ajánlat-levél elküldve');
T::tartalmaz($GLOBALS['t26_levelek'][0]['message'] ?? '', 'rt=' . $q['rt'], 'ajánlat-levélben a link');

// REST (valós idő): első szabad sáv a jövő héten
$tol  = (new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')))->modify('+2 days')->format('Y-m-d');
$ig   = (new DateTimeImmutable($tol))->modify('+14 days')->format('Y-m-d');
$sav  = array_values(SDH_Sales_Naptar::savok('support', $tol, $ig))[0][0] ?? null;
T::igaz($sav !== null, 'van szabad support-sáv');
$r = t_latogato(static fn () => t_rest('POST', '/foglalas', ['tipus' => 'support', 'kezdet' => $sav['utc'], 'nev' => 'Rest Elek', 'email' => 'rest@pelda.hu']));
T::egyezik(201, $r->get_status(), 'REST foglalás 201');
T::igaz(strpos((string) ($r->get_data()['kezelo_url'] ?? ''), 'foglalas=') !== false && strpos((string) ($r->get_data()['ics_url'] ?? ''), '/foglalas/ics') !== false, 'REST: kezelő + ICS link');
$r2 = t_latogato(static fn () => t_rest('POST', '/foglalas', ['tipus' => 'support', 'kezdet' => $sav['utc'], 'nev' => 'Más Ember', 'email' => 'mas@pelda.hu']));
T::egyezik(409, $r2->get_status(), 'REST dupla: 409');

// admin lista token nélkül
$a = t_rest('GET', '/admin/foglalasok', ['allapot' => 'foglalt']);
T::igaz($a->get_status() === 200 && ($a->get_data()['ossz'] ?? 0) >= 4, 'admin lista');
T::igaz(!array_key_exists('token', $a->get_data()['sorok'][0] ?? ['token' => 1]), 'admin lista: token nincs benne');
T::igaz(in_array(t_latogato(static fn () => t_rest('GET', '/admin/foglalasok'))->get_status(), [401, 403], true), 'admin lista látogatónak tilos');

T::vege('t265 foglalás');
