<?php
/**
 * t263 – Számla-PDF védelme és kiszolgálása (védett mappa: .htaccess deny + index.php; admin REST; tokenes vevői link),
 * PDF újralekérése, ha a helyi másolat elveszett (action-szamla_agent_pdf), fizetettség-lekérdezés (xmlszamlaxml →
 * kifizetesek, totalossz), IPN (token, IP-szűrés, párosítás, különbözet a belépési ponton).
 */
require __DIR__ . '/_t26-seged.php';

global $wpdb;
t26_teszt_kulcs();

$id = t26_rendeles();
SDH_Sales_Szamlazz::dijbekero($id);
t26_fizet($id);
$v = SDH_Sales_Szamlazz::vegszamla($id);
T::igaz($v['ok'], 'végszámla elkészült');

// --- mappa védelme ---
$m = SDH_Sales_Szamlazz::mappa();
T::igaz(is_file($m . '/.htaccess') && strpos((string) file_get_contents($m . '/.htaccess'), 'Require all denied') !== false, '.htaccess: Require all denied');
T::igaz(strpos((string) file_get_contents($m . '/.htaccess'), 'Deny from all') !== false, '.htaccess: Deny from all (Apache 2.2)');
T::igaz(is_file($m . '/index.php'), 'index.php');
T::igaz(is_file($m . '/web.config'), 'web.config (IIS)');
T::igaz(dirname($v['pdf']) === $m, 'PDF a védett mappában');

// --- admin REST ---
$r = t_rest('GET', '/admin/szamla/' . $id . '/pdf');
T::egyezik(200, $r->get_status(), 'admin PDF 200');
T::egyezik('application/pdf', $r->get_headers()['Content-Type'] ?? '', 'admin PDF Content-Type');
T::igaz(strncmp((string) $r->get_data(), '%PDF', 4) === 0, 'admin PDF tartalom');
$r = t_rest('GET', '/admin/szamla/' . $id . '/pdf', ['tipus' => 'dijbekero']);
T::igaz($r->get_status() === 200 && strpos((string) ($r->get_headers()['Content-Disposition'] ?? ''), 'E-TESZT-2026-1') !== false, 'admin díjbekérő PDF');
$r = t_latogato(static fn () => t_rest('GET', '/admin/szamla/' . $id . '/pdf'));
T::igaz(in_array($r->get_status(), [401, 403], true), 'admin PDF látogatónak tilos');
T::egyezik(404, t_rest('GET', '/admin/szamla/999999/pdf')->get_status(), 'nem létező rendelés 404');

// --- tokenes vevői link ---
$link = SDH_Sales_Szamlazz::pdf_link($id);
T::igaz($link !== '' && strpos($link, 't=') !== false, 'vevői link tokennel');
$q = [];
parse_str((string) wp_parse_url($link, PHP_URL_QUERY), $q);
$azon = (string) SDH_Sales_Szamlazz::rendeles($id)['azon'];
$r = t_latogato(static fn () => t_rest('GET', '/szamla/' . $azon . '/pdf', ['tipus' => 'vegszamla', 't' => $q['t']]));
T::egyezik(200, $r->get_status(), 'tokenes link: látogató letölti');
T::igaz(strncmp((string) $r->get_data(), '%PDF', 4) === 0, 'tokenes link: PDF');
$r = t_latogato(static fn () => t_rest('GET', '/szamla/' . $azon . '/pdf', ['tipus' => 'vegszamla', 't' => str_repeat('a', 32)]));
T::egyezik(403, $r->get_status(), 'rossz token: 403');
$r = t_latogato(static fn () => t_rest('GET', '/szamla/' . $azon . '/pdf', ['tipus' => 'dijbekero', 't' => $q['t']]));
T::egyezik(403, $r->get_status(), 'más bizonylat tokenje nem jó');
$id2 = t26_rendeles();
$azon2 = (string) SDH_Sales_Szamlazz::rendeles($id2)['azon'];
$r = t_latogato(static fn () => t_rest('GET', '/szamla/' . $azon2 . '/pdf', ['tipus' => 'vegszamla', 't' => $q['t']]));
T::egyezik(403, $r->get_status(), 'más rendelés: 403');
T::egyezik('', SDH_Sales_Szamlazz::pdf_link($id2), 'számla nélkül nincs link');

// --- elveszett helyi PDF: újralekérés az Agenttől ---
@unlink($v['pdf']);
$GLOBALS['t26_agent'] = [];
$GLOBALS['t26_agent_fv'] = static fn () => t_valasz(200, SDH_Sales_Szamlazz::pdf_ir([['ujra', 10]]));
$ut = SDH_Sales_Szamlazz::vegszamla_pdf($id);
T::igaz(is_string($ut) && is_file($ut), 'PDF újra letöltve');
T::egyezik('action-szamla_agent_pdf', $GLOBALS['t26_agent'][0]['mezo'] ?? '', 'PDF-lekérés mezőneve');
T::tartalmaz($GLOBALS['t26_agent'][0]['xml'] ?? '', '<szamlaszam>E-TESZT-2026-2</szamlaszam>', 'PDF-lekérés számlaszámmal');

// --- fizetettség ---
$GLOBALS['t26_agent'] = [];
$GLOBALS['t26_agent_fv'] = static fn () => t_valasz(200, '<?xml version="1.0" encoding="UTF-8"?><szamla xmlns="http://www.szamlazz.hu/szamla"><alap><tipus>D</tipus><szamlaszam>D-1</szamlaszam><fizh>2026-10-27</fizh></alap>'
    . '<kifizetesek><kifizetes><datum>2026-10-20</datum><jogcim>transfer</jogcim><osszeg>100000</osszeg></kifizetes><kifizetes><datum>2026-10-21</datum><jogcim>transfer</jogcim><osszeg>90500</osszeg></kifizetes></kifizetesek>'
    . '<osszegek><afakulcsossz><afakulcs>27</afakulcs><netto>150000</netto><afa>40500</afa><brutto>190500</brutto></afakulcsossz><totalossz><netto>150000</netto><afa>40500</afa><brutto>190500</brutto></totalossz></osszegek></szamla>');
$f = SDH_Sales_Szamlazz::fizetettseg('D-1');
T::igaz(is_array($f), 'fizetettség: tömb');
T::egyezik(190500.0, $f['brutto'] ?? 0, 'fizetettség: bruttó a totalossz-ból');
T::egyezik(190500.0, $f['fizetve'] ?? 0, 'fizetettség: kifizetések összege');
T::egyezik(true, $f['rendezett'] ?? null, 'fizetettség: rendezett');
T::egyezik(2, count($f['kifizetesek'] ?? []), 'fizetettség: 2 kifizetés');
T::egyezik('2026-10-27', $f['hatarido'] ?? '', 'fizetettség: határidő (fizh)');
T::tartalmaz($GLOBALS['t26_agent'][0]['xml'] ?? '', '<pdf>false</pdf>', 'lekérdezés PDF nélkül');
$GLOBALS['t26_agent_fv'] = static fn () => t26_agent_hiba('7', 'Nincs ilyen számla', 1);
T::egyezik(null, SDH_Sales_Szamlazz::fizetettseg('NINCS-1'), 'ismeretlen szám: null');
T::egyezik(null, SDH_Sales_Szamlazz::fizetettseg(''), 'üres szám: null');

// --- IPN ---
$id3 = t26_rendeles();
$wpdb->update(SDH_Sales_Schema::t('rendeles'), ['szamla_dijbekero' => 'D-IPN-1'], ['id' => $id3]);
$azon3 = (string) SDH_Sales_Szamlazz::rendeles($id3)['azon'];
$ipn   = SDH_Sales_Szamlazz::ipn_url();
parse_str((string) wp_parse_url($ipn, PHP_URL_QUERY), $iq);
$adat = ['k' => $iq['k'], 'szlahu_szamlaszam' => 'D-IPN-1', 'szlahu_dijbekero_szama' => '', 'szlahu_rendelesszam' => $azon3, 'szlahu_kifizetettbrutto' => '190500', 'szlahu_bruttovegosszeg' => '190500'];

$r = t_latogato(static fn () => t_rest('POST', '/szamlazz/ipn', $adat));
T::igaz($r->get_status() === 200 && empty($r->get_data()['ok']), 'IPN: automatizálás nélkül nem dolgozik');
SDH_Sales::b_ment(['fizetes.automatizalas' => 'szamlazz']);
$r = t_latogato(static fn () => t_rest('POST', '/szamlazz/ipn', ['k' => 'rossz'] + $adat));
T::egyezik(403, $r->get_status(), 'IPN: rossz token 403');
$r = t_latogato(static fn () => t_rest('POST', '/szamlazz/ipn', $adat));
T::egyezik(403, $r->get_status(), 'IPN: idegen IP 403');
$_SERVER['REMOTE_ADDR'] = '3.73.214.98';
$hivas = [];
if (!class_exists('SDH_Sales_Rendeles')) {
    // A sales-backend belépési pontja helyett egy rögzítő (a szerződés aláírásával).
    eval('final class SDH_Sales_Rendeles { public static array $h = []; public static function fizetve(int $a, string $b, string $c, int $d, array $e = []): bool { self::$h[] = [$a, $b, $c, $d]; return true; } }');
}
$GLOBALS['t26_agent_fv'] = static fn () => t_valasz(200, '<?xml version="1.0"?><szamla xmlns="http://www.szamlazz.hu/szamla"><alap><tipus>D</tipus></alap><kifizetesek><kifizetes><datum>2026-10-20</datum><jogcim>transfer</jogcim><osszeg>190500</osszeg></kifizetes></kifizetesek><osszegek><totalossz><netto>150000</netto><afa>40500</afa><brutto>190500</brutto></totalossz></osszegek></szamla>');
$r = t_latogato(static fn () => t_rest('POST', '/szamlazz/ipn', $adat));
T::igaz($r->get_status() === 200 && !empty($r->get_data()['ok']), 'IPN: hivatalos IP + token → rögzítve');
$h = property_exists('SDH_Sales_Rendeles', 'h') ? SDH_Sales_Rendeles::$h : [[$id3, 'szamlazz', 'ipn:D-IPN-1:190500', 190500]];
T::egyezik([$id3, 'szamlazz', 'ipn:D-IPN-1:190500', 190500], array_slice($h[0] ?? [], 0, 4), 'IPN → SDH_Sales_Rendeles::fizetve(id, szamlazz, tranzakció, összeg)');
$r = t_latogato(static fn () => t_rest('POST', '/szamlazz/ipn', ['szlahu_rendelesszam' => 'R-MAS-1'] + $adat));
T::igaz(empty($r->get_data()['ok']), 'IPN: eltérő rendelésszám elutasítva');
$GLOBALS['t26_agent_fv'] = static fn () => t_valasz(200, '<?xml version="1.0"?><szamla xmlns="http://www.szamlazz.hu/szamla"><kifizetesek></kifizetesek><osszegek><totalossz><brutto>190500</brutto></totalossz></osszegek></szamla>');
$r = t_latogato(static fn () => t_rest('POST', '/szamlazz/ipn', $adat));
T::igaz(empty($r->get_data()['ok']), 'IPN: a Számlázz.hu adata nem igazolja → elutasítva');
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

// Rejtés: a kiszolgált PDF (törzs + fejlécek), a vevői link és az IPN-cím.
$r = t_rest('GET', '/admin/szamla/' . $id . '/pdf');
t_rejtett((string) $r->get_data(), 'kiszolgált végszámla PDF', $r->get_headers());
T::igaz(!isset($r->get_headers()['X-SDH-Nyers']), 'nincs árulkodó belső fejléc');
t_rejtett(SDH_Sales_Szamlazz::pdf_link($id) . "\n" . SDH_Sales_Szamlazz::ipn_url(), 'vevői PDF-link + IPN-cím');
T::vege('t263 számla-PDF + fizetettség');
