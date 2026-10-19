<?php
/**
 * t268 – ICS (RFC 5545): CRLF minden sor végén, nincs magányos LF, 75 oktettnél hosszabb sor nincs (UTF-8-at nem
 * vág ketté, a hajtogatott sor szóközzel kezdődik), UID egyedi és stabil, DTSTAMP UTC, DTSTART/DTEND UTC („Z”, így
 * TZID nem kell – DST-biztos), szöveg-escapelés (\, ; , újsor), VALARM, METHOD:CANCEL; titkos iCal-feed (token,
 * új token a régit érvényteleníti, lemondott = CANCELLED), ICS letöltés tokennel (text/calendar).
 */
require __DIR__ . '/_t26-seged.php';

t26_naptar();
SDH_Sales::b_ment(['ceg.nev' => 'Próba, Kft; „Árvíztűrő”', 'ceg.email' => 'info@pelda.hu', 'naptar.helyszin' => 'Online; Google Meet, link e-mailben']);
$most = t26_bp('2026-10-19 08:00');
$e    = SDH_Sales_Naptar::foglal(['tipus' => 'demo', 'kezdet' => '2026-10-26T08:00:00Z', 'nev' => 'Árvíztűrő Tükörfúrógép Kft. ügyvezetője, aki nagyon hosszú nevet adott meg', 'email' => 'anna@pelda.hu', 'megjegyzes' => "Első sor\nMásodik; sor, vessző \\ jel"], $most);
$f    = SDH_Sales_Naptar::egy($e['id']);
$ics  = SDH_Sales_Naptar::ics($f);

$ellenoriz = static function (string $ics, string $nev): void {
    T::igaz(substr($ics, -2) === "\r\n", $nev . ': CRLF a végén');
    T::egyezik(0, preg_match('/(?<!\r)\n/', $ics), $nev . ': nincs magányos LF');
    $sorok = explode("\r\n", rtrim($ics, "\r\n"));
    $max   = max(array_map('strlen', $sorok));
    T::igaz($max <= 75, $nev . ': max sorhossz ' . $max . ' ≤ 75 oktett');
    $jo = true;
    foreach ($sorok as $s) {
        $jo = $jo && mb_check_encoding($s, 'UTF-8');
    }
    T::igaz($jo, $nev . ': minden fizikai sor érvényes UTF-8 (nincs kettévágott karakter)');
    T::egyezik(preg_match_all('/^BEGIN:/m', $ics), preg_match_all('/^END:/m', $ics), $nev . ': BEGIN/END páros');
    T::igaz(strpos($ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:") === 0, $nev . ': fejléc');
};

$ellenoriz($ics, 'egy esemény');
$ki = str_replace("\r\n ", '', $ics); // visszahajtogatás (unfold)
T::tartalmaz($ki, "DTSTART:20261026T080000Z\r\n", 'DTSTART UTC (DST utáni hétfő 9:00 = 08:00Z)');
T::tartalmaz($ki, "DTEND:20261026T083000Z\r\n", 'DTEND UTC');
T::egyezik(1, preg_match('/\r\nDTSTAMP:\d{8}T\d{6}Z\r\n/', $ki), 'DTSTAMP UTC-formátum');
T::egyezik(1, preg_match('/\r\nUID:(sdh-foglalas-' . $e['id'] . '-[a-f0-9]{12}@[^\r]+)\r\n/', $ki, $m), 'UID formátum');
$uid = $m[1] ?? '';
T::tartalmaz(str_replace("\r\n ", '', SDH_Sales_Naptar::ics($f, 'CANCEL')), 'UID:' . $uid . "\r\n", 'UID stabil (lemondásnál ugyanaz)');
T::egyezik(0, preg_match('/TZID|DTSTART;/', $ki), 'nincs lebegő/TZID-es idő (UTC-vel DST-biztos)');
T::tartalmaz($ki, "METHOD:PUBLISH\r\n", 'METHOD:PUBLISH');
T::tartalmaz($ki, "STATUS:CONFIRMED\r\n", 'STATUS:CONFIRMED');
T::tartalmaz($ki, "BEGIN:VALARM\r\nACTION:DISPLAY\r\n", 'VALARM');
T::tartalmaz($ki, 'LOCATION:Online\; Google Meet\, link e-mailben', 'LOCATION escapelve');
T::tartalmaz($ki, 'ORGANIZER;CN="Próba Kft „Árvíztűrő”":mailto:info@pelda.hu', 'ORGANIZER (paraméterből tiltott jelek nélkül)');
T::egyezik('a\\\\b\\;c\\,d\\ne', SDH_Sales_Naptar::ics_szoveg("a\\b;c,d\ne"), 'szöveg-escapelés');

// hajtogatás: pontosan 75 oktett, a folytatás szóközzel
$h = SDH_Sales_Naptar::ics_sor('DESCRIPTION:' . str_repeat('ő', 60));
$hs = explode("\r\n", rtrim($h, "\r\n"));
T::igaz(strlen($hs[0]) <= 75 && strlen($hs[0]) >= 74 && $hs[1][0] === ' ', 'hajtogatás 75 oktettnél, folytatás szóközzel');
T::egyezik('DESCRIPTION:' . str_repeat('ő', 60), str_replace("\r\n ", '', rtrim($h, "\r\n")), 'hajtogatás veszteségmentes');

// CANCEL
$c = SDH_Sales_Naptar::ics($f, 'CANCEL');
$ellenoriz($c, 'lemondó');
T::tartalmaz($c, "METHOD:CANCEL\r\n", 'CANCEL: METHOD');
T::tartalmaz($c, "STATUS:CANCELLED\r\n", 'CANCEL: STATUS');
T::tartalmaz($c, "SEQUENCE:1\r\n", 'CANCEL: SEQUENCE nő');
T::nem_tartalmaz($c, 'VALARM', 'CANCEL: riasztás nélkül');

// titkos feed
$r = t_latogato(static fn () => t_rest('GET', '/naptar/ical', ['t' => 'rossz']));
T::egyezik(403, $r->get_status(), 'feed: token nélkül 403');
$url = t_rest('GET', '/admin/naptar/ical')->get_data()['url'] ?? '';
parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
T::igaz(strlen((string) ($q['t'] ?? '')) >= 30, 'feed-URL titkos tokennel');
$r = t_latogato(static fn () => t_rest('GET', '/naptar/ical', ['t' => $q['t']]));
T::egyezik(200, $r->get_status(), 'feed: jó tokennel 200');
T::tartalmaz((string) ($r->get_headers()['Content-Type'] ?? ''), 'text/calendar', 'feed: text/calendar');
$feed = (string) $r->get_data();
$ellenoriz($feed, 'feed');
T::tartalmaz(str_replace("\r\n ", '', $feed), 'X-WR-CALNAME:', 'feed: naptárnév');
T::igaz(strpos($feed, "\r\n ") !== false, 'feed: a hosszú név hajtogatva');
T::tartalmaz(str_replace("\r\n ", '', $feed), 'Első sor\nMásodik\; sor\, vessző \\\\ jel', 'feed: megjegyzés escapelve');
T::tartalmaz(str_replace("\r\n ", '', $feed), 'anna@pelda.hu', 'feed: a foglaló adatai (csak a titkos feedben)');
$e2 = SDH_Sales_Naptar::foglal(['tipus' => 'demo', 'kezdet' => '2026-10-27T08:00:00Z', 'nev' => 'Béla', 'email' => 'b@pelda.hu'], $most);
SDH_Sales_Naptar::lemond($e2['token'], $most);
$feed = str_replace("\r\n ", '', (string) t_latogato(static fn () => t_rest('GET', '/naptar/ical', ['t' => $q['t']]))->get_data());
T::egyezik(2, substr_count($feed, 'BEGIN:VEVENT'), 'feed: 2 esemény');
T::egyezik(1, substr_count($feed, 'STATUS:CANCELLED'), 'feed: a lemondott CANCELLED');
T::nem_tartalmaz($feed, 'VALARM', 'feed: riasztás nélkül');
$uj = t_rest('POST', '/admin/naptar/ical')->get_data()['url'] ?? '';
T::igaz($uj !== $url, 'új feed-cím');
T::egyezik(403, t_latogato(static fn () => t_rest('GET', '/naptar/ical', ['t' => $q['t']]))->get_status(), 'régi feed-token érvénytelen');
T::igaz(in_array(t_latogato(static fn () => t_rest('GET', '/admin/naptar/ical'))->get_status(), [401, 403], true), 'feed-cím lekérése látogatónak tilos');

// ICS letöltés a foglaló tokenjével
$r = t_latogato(static fn () => t_rest('GET', '/foglalas/ics', ['t' => $e['token']]));
T::igaz($r->get_status() === 200 && strpos((string) ($r->get_headers()['Content-Disposition'] ?? ''), 'attachment') === 0, 'ICS letöltés tokennel');
T::nem_tartalmaz((string) $r->get_data(), 'anna@pelda.hu', 'vevői ICS: nincs benne a saját adatlista (csak a cég)');
T::egyezik(404, t_latogato(static fn () => t_rest('GET', '/foglalas/ics', ['t' => 'x']))->get_status(), 'ICS rossz tokennel 404');

T::vege('t268 ICS + feed');
