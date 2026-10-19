<?php
/**
 * t264 – Szabad sávok: munkaidő (H–P 9–17, Europe/Budapest), nyári időszámítás vége (2026-10-25) körüli hét,
 * típusonkénti hossz és puffer, zárolt napok (egy nap és tartomány), ünnepnapok (okt. 23., húsvét), minimális
 * előzetes idő, max. napok előre, kapacitás, REST (cache-tiltó fejléc, ismeretlen típus).
 */
require __DIR__ . '/_t26-seged.php';

global $wpdb;
t26_naptar();

$most = t26_bp('2026-10-19 08:00'); // hétfő reggel
$s    = SDH_Sales_Naptar::savok('demo', '2026-10-19', '2026-11-01', $most);

// munkaidő: demo 30 perc, lépés 30 → 9:00 … 16:30 = 16 sáv
T::igaz(!isset($s['2026-10-19']), 'ma (12 órán belül) nincs sáv');
T::egyezik(16, count($s['2026-10-20'] ?? []), 'kedd: 16 sáv (9:00–16:30)');
T::egyezik('09:00', $s['2026-10-20'][0]['ido'] ?? '', 'első sáv 9:00');
T::egyezik('16:30', end($s['2026-10-20'])['ido'] ?? '', 'utolsó sáv 16:30 (vége 17:00)');
T::igaz(!isset($s['2026-10-24']) && !isset($s['2026-10-25']), 'hétvége kimarad');
T::igaz(!isset($s['2026-10-23']), 'okt. 23. (nemzeti ünnep) kimarad');

// DST: péntek előtte CEST (+02:00), hétfő utána CET (+01:00) – a falióra-idő ugyanaz
T::egyezik('2026-10-22T07:00:00Z', $s['2026-10-22'][0]['utc'] ?? '', 'DST előtt: 9:00 = 07:00Z');
T::egyezik('2026-10-26T08:00:00Z', $s['2026-10-26'][0]['utc'] ?? '', 'DST után: 9:00 = 08:00Z');
T::egyezik('2026-10-26T09:00:00+01:00', $s['2026-10-26'][0]['kezdet'] ?? '', 'DST után: helyi eltolás +01:00');
T::egyezik('2026-10-22T09:00:00+02:00', $s['2026-10-22'][0]['kezdet'] ?? '', 'DST előtt: helyi eltolás +02:00');
T::egyezik(16, count($s['2026-10-26'] ?? []), 'DST utáni hétfő: 16 sáv');
T::egyezik('16:30', end($s['2026-10-26'])['ido'] ?? '', 'DST után: utolsó sáv 16:30');

// hossz: telepítés 90 perc → 9:00 … 15:30 = 14 sáv
$t = SDH_Sales_Naptar::savok('telepites', '2026-10-20', '2026-10-20', $most);
T::egyezik(14, count($t['2026-10-20'] ?? []), 'telepítés 90 perc: 14 sáv');
T::egyezik('17:00', end($t['2026-10-20'])['veg'] ?? '', 'telepítés: utolsó vége 17:00');

// minimális előzetes idő
$s2 = SDH_Sales_Naptar::savok('demo', '2026-10-20', '2026-10-20', t26_bp('2026-10-19 22:30'));
T::egyezik('10:30', $s2['2026-10-20'][0]['ido'] ?? '', '12 óra előzetes: 22:30 után első 10:30');

// puffer: egy 10:00–10:30 demó (puffer 15) → 9:30 és 10:30 is kiesik (9:30–10:00 + 15 perc átfed), 9:00 marad, 11:00 marad
$wpdb->insert(SDH_Sales_Schema::t('foglalas'), ['tipus' => 'demo', 'kezdet' => gmdate('Y-m-d H:i:s', t26_bp('2026-10-20 10:00')), 'veg' => gmdate('Y-m-d H:i:s', t26_bp('2026-10-20 10:30')), 'nev' => 'X', 'email' => 'x@pelda.hu', 'allapot' => 'foglalt', 'token' => 'n1', 'ido' => gmdate('Y-m-d H:i:s')]);
$ido = array_column(SDH_Sales_Naptar::savok('demo', '2026-10-20', '2026-10-20', $most)['2026-10-20'] ?? [], 'ido');
T::igaz(in_array('09:00', $ido, true) && !in_array('09:30', $ido, true) && !in_array('10:00', $ido, true) && !in_array('10:30', $ido, true) && in_array('11:00', $ido, true), 'puffer 15 perc mindkét oldalon');
// a nagyobb puffer számít: telepítés (puffer 30) a demó (puffer 15) után 15 perces lépéssel → 10:45 nem, 11:00 igen
t26_naptar(['naptar.lepes' => 15]);
$ido = array_column(SDH_Sales_Naptar::savok('telepites', '2026-10-20', '2026-10-20', $most)['2026-10-20'] ?? [], 'ido');
T::igaz(!in_array('10:45', $ido, true) && in_array('11:00', $ido, true) && !in_array('08:00', $ido, true), 'puffer: a nagyobb puffer érvényes (30)');
t26_naptar();
// lemondott foglalás nem foglal
$wpdb->update(SDH_Sales_Schema::t('foglalas'), ['allapot' => 'lemondva'], ['token' => 'n1']);
$ido = array_column(SDH_Sales_Naptar::savok('demo', '2026-10-20', '2026-10-20', $most)['2026-10-20'] ?? [], 'ido');
T::igaz(in_array('10:00', $ido, true), 'lemondott foglalás felszabadít');

// kapacitás 2: egy foglalás mellett még szabad
$wpdb->update(SDH_Sales_Schema::t('foglalas'), ['allapot' => 'foglalt'], ['token' => 'n1']);
t26_naptar(['naptar.kapacitas' => 2]);
$ido = array_column(SDH_Sales_Naptar::savok('demo', '2026-10-20', '2026-10-20', $most)['2026-10-20'] ?? [], 'ido');
T::igaz(in_array('10:00', $ido, true), 'kapacitás 2: átfedő sáv szabad');
t26_naptar();

// zárolt napok: egy nap + tartomány
t26_naptar(['naptar.zarolt' => ['2026-10-21', '2026-10-27..2026-10-29']]);
$s3 = SDH_Sales_Naptar::savok('demo', '2026-10-19', '2026-10-31', $most);
T::igaz(!isset($s3['2026-10-21']), 'zárolt nap');
T::igaz(!isset($s3['2026-10-27']) && !isset($s3['2026-10-28']) && !isset($s3['2026-10-29']), 'zárolt tartomány');
T::igaz(isset($s3['2026-10-26']) && isset($s3['2026-10-30']), 'tartomány szélei mellett szabad');
t26_naptar(['naptar.zarolt' => "2026-10-22\n2026-10-30"]);
$s3 = SDH_Sales_Naptar::savok('demo', '2026-10-19', '2026-10-31', $most);
T::igaz(!isset($s3['2026-10-22']) && !isset($s3['2026-10-30']), 'zárolt napok szövegként (soronként)');
t26_naptar();

// ünnepnapok
$u = SDH_Sales_Naptar::unnepnapok(2027);
T::igaz(isset($u['2027-03-26'], $u['2027-03-29'], $u['2027-05-17']), '2027: nagypéntek, húsvéthétfő, pünkösdhétfő');
T::igaz(isset(SDH_Sales_Naptar::unnepnapok(2026)['2026-04-06']), '2026: húsvéthétfő ápr. 6.');

// max napok előre
t26_naptar(['naptar.max_nap' => 10]);
$s4 = SDH_Sales_Naptar::savok('demo', '2026-10-19', '2026-12-31', $most);
T::igaz(max(array_keys($s4)) <= '2026-10-29', 'max 10 nap előre');
t26_naptar();

// múltbeli időszak: semmi
T::egyezik([], SDH_Sales_Naptar::savok('demo', '2026-01-01', '2026-01-31', $most), 'múlt: üres');
T::egyezik([], SDH_Sales_Naptar::savok('nincs', '2026-10-20', '2026-10-21', $most), 'ismeretlen típus: üres');
T::egyezik([], SDH_Sales_Naptar::savok('demo', 'x', 'y', $most), 'rossz dátum: üres');

// típus kikapcsolása
SDH_Sales::b_ment(['naptar.tipus.support' => ['aktiv' => false]]);
T::igaz(!isset(SDH_Sales_Naptar::tipusok()['support']), 'típus kikapcsolható');
SDH_Sales::b_ment(['naptar.tipus.support' => []]);

// REST
$r = t_latogato(static fn () => t_rest('GET', '/foglalas/savok', ['tipus' => 'demo', 'tol' => gmdate('Y-m-d'), 'ig' => gmdate('Y-m-d', time() + 14 * DAY_IN_SECONDS)]));
T::egyezik(200, $r->get_status(), 'REST savok 200');
$r = rest_get_server()->dispatch((static function () {
    $q = new WP_REST_Request('GET', '/sdh-sales/v1/foglalas/savok');
    $q->set_query_params(['tipus' => 'demo']);
    return $q;
})());
$r = apply_filters('rest_post_dispatch', $r, rest_get_server(), (static function () { $q = new WP_REST_Request('GET', '/sdh-sales/v1/foglalas/savok'); $q->set_route('/sdh-sales/v1/foglalas/savok'); return $q; })());
T::tartalmaz((string) ($r->get_headers()['Cache-Control'] ?? ''), 'no-store', 'REST savok: no-store (cache-biztos)');
T::egyezik('Europe/Budapest', (string) ($r->get_data()['idozona'] ?? ''), 'REST: időzóna');
$r = t_latogato(static fn () => t_rest('GET', '/foglalas/savok', ['tipus' => 'nincs']));
T::egyezik(400, $r->get_status(), 'REST savok: ismeretlen típus 400');

T::vege('t264 naptár-sávok');
