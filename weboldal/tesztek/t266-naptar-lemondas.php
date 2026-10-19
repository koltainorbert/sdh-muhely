<?php
/**
 * t266 – Lemondás és áthelyezés tokennel: hibás / hamis token, határidő (lemondas_ora), egyszeri lemondás,
 * felszabaduló sáv, lemondó levél CANCEL ICS-sel, áthelyezés (régi „atfoglalva”, új sáv, a régi felszabadul,
 * a levélben a régi időpont törlő ICS-e), admin lemondás határidő nélkül, REST /foglalas/lemond + /foglalas/adat.
 */
require __DIR__ . '/_t26-seged.php';

global $wpdb;
t26_naptar(['naptar.lemondas_ora' => 2]);
$most = t26_bp('2026-10-19 08:00');
$be   = ['tipus' => 'demo', 'nev' => 'Kis Anna', 'email' => 'anna@pelda.hu'];

$e = SDH_Sales_Naptar::foglal(['kezdet' => '2026-10-20T07:00:00Z'] + $be, $most);
T::igaz(is_array($e), 'foglalás');
T::egyezik(1, preg_match('/^\d+-[a-f0-9]{32}$/', $e['token']), 'token formátuma: id-HMAC');

// token-ellenőrzés
T::egyezik(null, SDH_Sales_Naptar::tokennel('rossz'), 'rossz token: nincs');
T::egyezik(null, SDH_Sales_Naptar::tokennel($e['id'] . '-' . str_repeat('0', 32)), 'hamis HMAC: nincs');
T::egyezik(null, SDH_Sales_Naptar::tokennel(($e['id'] + 1) . substr($e['token'], strlen((string) $e['id']))), 'más azonosító ugyanazzal a HMAC-kal: nincs');
T::igaz(SDH_Sales_Naptar::tokennel($e['token']) !== null, 'jó token');

// határidő: 2 órával előtte már nem
T::igaz(is_wp_error(SDH_Sales_Naptar::lemond($e['token'], t26_bp('2026-10-20 07:30'))), 'határidőn túl nem mondható le');

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
$ido = array_column(SDH_Sales_Naptar::savok('demo', '2026-10-20', '2026-10-20', $most)['2026-10-20'] ?? [], 'utc');
T::igaz(in_array('2026-10-20T07:00:00Z', $ido, true), 'lemondott sáv újra szabad');

// áthelyezés
$a = SDH_Sales_Naptar::foglal(['kezdet' => '2026-10-21T07:00:00Z'] + $be, $most);
$GLOBALS['t26_levelek'] = [];
$uj = SDH_Sales_Naptar::foglal(['atfoglal' => $a['token'], 'kezdet' => '2026-10-22T12:00:00Z', 'tipus' => 'support', 'nev' => 'Más', 'email' => 'mas@pelda.hu'], $most);
T::igaz(is_array($uj), 'áthelyezés sikeres');
T::egyezik('atfoglalva', (string) SDH_Sales_Naptar::egy($a['id'])['allapot'], 'régi: atfoglalva');
$uf = SDH_Sales_Naptar::egy($uj['id']);
T::igaz((string) $uf['tipus'] === 'demo' && (string) $uf['email'] === 'anna@pelda.hu' && (string) $uf['nev'] === 'Kis Anna', 'áthelyezés: típus és adatok a régiből (nem írhatók felül)');
T::egyezik('2026-10-22 12:00:00', (string) $uf['kezdet'], 'új időpont (14:00 helyi)');
$l = $GLOBALS['t26_levelek'][0] ?? [];
T::igaz(isset($l['csatolmany']['idopont.ics'], $l['csatolmany']['regi-idopont-torlese.ics']), 'áthelyezés: új ICS + régi törlő ICS');
T::tartalmaz($l['csatolmany']['regi-idopont-torlese.ics'] ?? '', 'sdh-foglalas-' . $a['id'] . '-', 'törlő ICS: a régi UID-ja');
$ido = array_column(SDH_Sales_Naptar::savok('demo', '2026-10-21', '2026-10-21', $most)['2026-10-21'] ?? [], 'utc');
T::igaz(in_array('2026-10-21T07:00:00Z', $ido, true), 'áthelyezés: régi sáv felszabadult');
T::igaz(is_wp_error(SDH_Sales_Naptar::foglal(['atfoglal' => $a['token'], 'kezdet' => '2026-10-22T13:00:00Z'], $most)), 'régi token már nem helyez át');
// a saját sávjára / mellé is áthelyezhető (önmagát nem blokkolja)
$uj2 = SDH_Sales_Naptar::foglal(['atfoglal' => $uj['token'], 'kezdet' => '2026-10-22T12:30:00Z'], $most);
T::igaz(is_array($uj2), 'áthelyezés a saját pufferén belülre');

// admin lemondás határidőn belül is
$adm = SDH_Sales_Naptar::foglal(['kezdet' => '2026-10-23T07:00:00Z'] + $be, $most);
T::igaz(is_wp_error($adm), 'okt. 23. ünnep: nem foglalható');
$adm = SDH_Sales_Naptar::foglal(['kezdet' => '2026-10-26T08:00:00Z'] + $be, $most);
$wpdb->update(SDH_Sales_Schema::t('foglalas'), ['kezdet' => gmdate('Y-m-d H:i:s', time() + 1800), 'veg' => gmdate('Y-m-d H:i:s', time() + 3600)], ['id' => $adm['id']]);
T::igaz(is_wp_error(SDH_Sales_Naptar::lemond($adm['token'])), 'ügyfél fél órával előtte nem mondhat le');
$r = t_rest('POST', '/admin/foglalasok/' . $adm['id'] . '/lemond');
T::egyezik(200, $r->get_status(), 'admin lemondás határidőn belül is');
T::igaz(in_array(t_latogato(static fn () => t_rest('POST', '/admin/foglalasok/' . $uj2['id'] . '/lemond'))->get_status(), [401, 403], true), 'admin lemondás látogatónak tilos');

// napló
T::igaz(SDH_Sales_Naplo::lista(['tipus' => 'foglalas', 'kereses' => 'Lemondva'])['ossz'] >= 2, 'lemondások naplózva');

T::vege('t266 lemondás + áthelyezés');
