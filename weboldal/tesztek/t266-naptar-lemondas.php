<?php
/**
 * t266 – Lemondás és áthelyezés tokennel: hibás / hamis token, határidő (lemondas_ora), egyszeri lemondás,
 * felszabaduló sáv, lemondó levél CANCEL ICS-sel, áthelyezés (régi „atfoglalva”, új sáv, a régi felszabadul,
 * a levélben a régi időpont törlő ICS-e), admin lemondás határidő nélkül, REST /foglalas/lemond + /foglalas/adat.
 */
require __DIR__ . '/_t26-seged.php';

global $wpdb;
t26_naptar(['naptar.lemondas_ora' => 2]);

// Relatív dátumok (a teszt bármely napon fut): az alap egy hétfő legalább 7 nap múlva, amelynek a keddje, szerdája,
// csütörtöke, péntekje és a következő hétfője nem ünnepnap. A helyi időből számolt UTC a nyári / téli időt is követi.
$tz  = new DateTimeZone('Europe/Budapest');
$H   = (new DateTimeImmutable('today', $tz))->modify('+7 days');
while ($H->format('N') !== '1' || array_filter([1, 2, 3, 4, 7], static fn (int $d): bool => SDH_Sales_Naptar::zarolt_nap($H->modify('+' . $d . ' days')->format('Y-m-d'))) !== []) {
    $H = $H->modify('+1 day');
}
$nap = static fn (int $d): string => $H->modify('+' . $d . ' days')->format('Y-m-d');
$utc = static fn (int $d, string $hm): string => (new DateTimeImmutable($nap($d) . ' ' . $hm, $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$dbi = static fn (int $d, string $hm): string => (new DateTimeImmutable($nap($d) . ' ' . $hm, $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$most = t26_bp($nap(0) . ' 08:00');
$be   = ['tipus' => 'demo', 'nev' => 'Kis Anna', 'email' => 'anna@pelda.hu'];

$e = SDH_Sales_Naptar::foglal(['kezdet' => $utc(1, '09:00')] + $be, $most);
T::igaz(is_array($e), 'foglalás');
T::egyezik(1, preg_match('/^\d+-[a-f0-9]{32}$/', $e['token']), 'token formátuma: id-HMAC');

// token-ellenőrzés
T::egyezik(null, SDH_Sales_Naptar::tokennel('rossz'), 'rossz token: nincs');
T::egyezik(null, SDH_Sales_Naptar::tokennel($e['id'] . '-' . str_repeat('0', 32)), 'hamis HMAC: nincs');
T::egyezik(null, SDH_Sales_Naptar::tokennel(($e['id'] + 1) . substr($e['token'], strlen((string) $e['id']))), 'más azonosító ugyanazzal a HMAC-kal: nincs');
T::igaz(SDH_Sales_Naptar::tokennel($e['token']) !== null, 'jó token');

// határidő: 2 órával előtte már nem
T::igaz(is_wp_error(SDH_Sales_Naptar::lemond($e['token'], t26_bp($nap(1) . ' 07:30'))), 'határidőn túl nem mondható le');

// REST /foglalas/adat
$r = t_latogato(static fn () => t_rest('GET', '/foglalas/adat', ['t' => $e['token']]));
T::igaz($r->get_status() === 200 && ($r->get_data()['lemondhato'] ?? false) === true && ($r->get_data()['nev'] ?? '') === 'Kis Anna', 'REST adat');
T::igaz(!isset($r->get_data()['email']), 'REST adat: e-mail nincs kiadva');
T::egyezik(404, t_latogato(static fn () => t_rest('GET', '/foglalas/adat', ['t' => 'x']))->get_status(), 'REST adat: rossz token 404');

// lemondás REST-en
$GLOBALS['t26_levelek'] = [];
$r = t_latogato(static fn () => t_rest('POST', '/foglalas/lemond', ['token' => $e['token']]));
T::egyezik(200, $r->get_status(), 'REST lemondás 200');
T::egyezik('lemondva', (string) SDH_Sales_Naptar::egy($e['id'])['allapot'], 'állapot lemondva');
$l = $GLOBALS['t26_levelek'][0] ?? [];
T::tartalmaz($l['subject'] ?? '', 'Lemondva', 'lemondó levél');
T::tartalmaz($l['csatolmany']['lemondas.ics'] ?? '', "METHOD:CANCEL\r\n", 'lemondó ICS: METHOD:CANCEL');
T::tartalmaz($l['csatolmany']['lemondas.ics'] ?? '', "STATUS:CANCELLED\r\n", 'lemondó ICS: STATUS:CANCELLED');
$r = t_latogato(static fn () => t_rest('POST', '/foglalas/lemond', ['token' => $e['token']]));
T::egyezik(409, $r->get_status(), 'második lemondás: 409');
$ido = array_column(SDH_Sales_Naptar::savok('demo', $nap(1), $nap(1), $most)[$nap(1)] ?? [], 'utc');
T::igaz(in_array($utc(1, '09:00'), $ido, true), 'lemondott sáv újra szabad');

// áthelyezés
$a = SDH_Sales_Naptar::foglal(['kezdet' => $utc(2, '09:00')] + $be, $most);
$GLOBALS['t26_levelek'] = [];
$uj = SDH_Sales_Naptar::foglal(['atfoglal' => $a['token'], 'kezdet' => $utc(3, '14:00'), 'tipus' => 'support', 'nev' => 'Más', 'email' => 'mas@pelda.hu'], $most);
T::igaz(is_array($uj), 'áthelyezés sikeres');
T::egyezik('atfoglalva', (string) SDH_Sales_Naptar::egy($a['id'])['allapot'], 'régi: atfoglalva');
$uf = SDH_Sales_Naptar::egy($uj['id']);
T::igaz((string) $uf['tipus'] === 'demo' && (string) $uf['email'] === 'anna@pelda.hu' && (string) $uf['nev'] === 'Kis Anna', 'áthelyezés: típus és adatok a régiből (nem írhatók felül)');
T::egyezik($dbi(3, '14:00'), (string) $uf['kezdet'], 'új időpont (14:00 helyi)');
$l = $GLOBALS['t26_levelek'][0] ?? [];
T::igaz(isset($l['csatolmany']['idopont.ics'], $l['csatolmany']['regi-idopont-torlese.ics']), 'áthelyezés: új ICS + régi törlő ICS');
T::tartalmaz($l['csatolmany']['regi-idopont-torlese.ics'] ?? '', 'UID:foglalas-' . $a['id'] . '-', 'törlő ICS: a régi UID-ja');
$ido = array_column(SDH_Sales_Naptar::savok('demo', $nap(2), $nap(2), $most)[$nap(2)] ?? [], 'utc');
T::igaz(in_array($utc(2, '09:00'), $ido, true), 'áthelyezés: régi sáv felszabadult');
T::igaz(is_wp_error(SDH_Sales_Naptar::foglal(['atfoglal' => $a['token'], 'kezdet' => $utc(3, '15:00')], $most)), 'régi token már nem helyez át');
// a saját sávjára / mellé is áthelyezhető (önmagát nem blokkolja)
$uj2 = SDH_Sales_Naptar::foglal(['atfoglal' => $uj['token'], 'kezdet' => $utc(3, '14:30')], $most);
T::igaz(is_array($uj2), 'áthelyezés a saját pufferén belülre');

// admin lemondás határidőn belül is
T::igaz(isset(SDH_Sales_Naptar::unnepnapok((int) $H->format('Y'))[$H->format('Y') . '-10-23']), 'okt. 23. az ünnepnapok között');
$unnep = $nap(4);
add_filter('sdh_sales_unnepnapok', static fn (array $u): array => $u + [$unnep => true]);
$adm = SDH_Sales_Naptar::foglal(['kezdet' => $utc(4, '09:00')] + $be, $most);
T::igaz(is_wp_error($adm), 'ünnepnap (pénteki): nem foglalható');
$adm = SDH_Sales_Naptar::foglal(['kezdet' => $utc(7, '09:00')] + $be, $most);
$wpdb->update(SDH_Sales_Schema::t('foglalas'), ['kezdet' => gmdate('Y-m-d H:i:s', time() + 1800), 'veg' => gmdate('Y-m-d H:i:s', time() + 3600)], ['id' => $adm['id']]);
T::igaz(is_wp_error(SDH_Sales_Naptar::lemond($adm['token'])), 'ügyfél fél órával előtte nem mondhat le');
$r = t_rest('POST', '/admin/foglalasok/' . $adm['id'] . '/lemond');
T::egyezik(200, $r->get_status(), 'admin lemondás határidőn belül is');
T::igaz(in_array(t_latogato(static fn () => t_rest('POST', '/admin/foglalasok/' . $uj2['id'] . '/lemond'))->get_status(), [401, 403], true), 'admin lemondás látogatónak tilos');

// napló
T::igaz(SDH_Sales_Naplo::lista(['tipus' => 'foglalas', 'kereses' => 'Lemondva'])['ossz'] >= 2, 'lemondások naplózva');

// Rejtés: a lemondás / áthelyezés levelei és REST-válaszai.
foreach ($GLOBALS['t26_levelek'] as $i => $l) {
    t_rejtett($l['subject'] . "\n" . $l['message'] . "\n" . implode("\n", $l['csatolmany']), 'levél #' . ($i + 1));
}
t_rejtett(t_latogato(static fn () => t_rest('GET', '/foglalas/adat', ['t' => $uj['token']])), 'REST foglalás-adat');
t_rejtett(t_latogato(static fn () => t_rest('POST', '/foglalas/lemond', ['token' => 'nincs-ilyen'])), 'REST lemondás hibaválasz');
T::vege('t266 lemondás + áthelyezés');
