<?php
/**
 * t299 – a bemutató kimenetében nincs tiltott minta (REJTES.md): sem SDH, sem a platform neve, útvonalai, azonosítói –
 * az indító oldalon (Turnstile-lal és nélküle, hibával, demo / demo-val), a sávon, a Kimenő üzenetek nézetben, a
 * leállító oldalon, minden üzenetben, az éles AJAX-hibákban és az állapot-JSON-ban. Saját azonosítók: bm-*, ?bemutato=.
 */
require __DIR__ . '/_t29-seged.php';

$kimenetek = [];

// Indító oldal – minden változatban.
wp_set_current_user(0);
$kimenetek['indító'] = SDH_Demo_Inditas::oldal_html();
$kimenetek['indító hibával'] = SDH_Demo_Inditas::oldal_html('Az ellenőrzés nem sikerült. Töltsd újra az oldalt, és próbáld újra.', true);
SDH_Demo::ment(['turnstile_kulcs' => '0x4AAAAAAAteszt', 'demo_demo' => true]);
SDH_Demo::titok_ment('turnstile_titok', 'titok');
$kimenetek['indító Turnstile + demo'] = SDH_Demo_Inditas::oldal_html();
T::igaz(preg_match('#muhely[/=]belepes#', $kimenetek['indító Turnstile + demo']) === 1, 'a demo / demo a saját (semleges) bejelentkezőre visz');

// Hibaüzenetek (indítás).
$ip = '203.0.113.99';
foreach ([['bm_jegy' => 'x'], ['bm_jegy' => SDH_Demo_Inditas::jegy(time() - 5)]] as $be) {
    $kimenetek['indítás hiba ' . count($kimenetek)] = (string) (SDH_Demo_Inditas::inditas($be, $ip)['hiba'] ?? '');
}

SDH_Demo::ment(['turnstile_kulcs' => '', 'max_inditas_ip' => 1]);
SDH_Demo_Inditas::inditas(['bm_jegy' => SDH_Demo_Inditas::jegy(time() - 5)], $ip);
$kimenetek['indítás korlát'] = (string) (SDH_Demo_Inditas::inditas(['bm_jegy' => SDH_Demo_Inditas::jegy(time() - 5)], $ip)['hiba'] ?? '');
T::tartalmaz($kimenetek['indítás korlát'], 'Erről a címről', 'korlát-üzenet (ellenőrzés)');

// Sáv, kimenő üzenetek, állapot.
$u = t_latogato('203.0.113.98');
SDH_Demo::ment(['ertekesito_url' => 'https://ertekesito.pelda.hu/']);
wp_mail('ugyfel@pelda.hu', 'Árajánlat', 'Az árajánlat: 12 990 Ft');
ob_start();
do_action('sdh_muhely_felulet_vege');
$kimenetek['sáv'] = (string) ob_get_clean();
$kimenetek['kimenő nézet'] = SDH_Demo_Kimeno::html();
$kimenetek['kimenő üres'] = SDH_Demo_Kimeno::html(null, 'sms');
$kimenetek['állapot JSON'] = (string) wp_json_encode(SDH_Demo_Sav::adatok());

// Üzenetek és leállító oldal.
$kimenetek['üzenetek'] = implode("\n", [
    SDH_Demo_Vedelem::TILTOTT_UZENET, SDH_Demo_Vedelem::NEZET_UZENET, SDH_Demo_Keret::uzenet(), SDH_Demo::HALOZAT_UZENET, SDH_Demo::SZAMLA_UZENET,
]);
$kimenetek['leállító oldal'] = SDH_Demo::oldal('Bemutató', '<p>' . esc_html(SDH_Demo_Keret::uzenet()) . '</p>');

// Éles AJAX-hibák (JSON) – keret, tiltott művelet, popup-űrlap.
$alap = "require '" . ABSPATH . "wp-load.php';\nwp_set_current_user({$u});\n";
$kimenetek['AJAX tiltás'] = t_folyamat("define('DOING_AJAX', true); \$_POST['action'] = \$_REQUEST['action'] = 'sdh_muhely_licenc_ellenoriz';\n" . $alap . "do_action('admin_init');");
$kimenetek['AJAX popup tiltás'] = t_folyamat("define('DOING_AJAX', true); \$_POST['action'] = \$_REQUEST['action'] = 'sdh_muhely_csapatkollega_urlap';\n" . $alap . "do_action('admin_init');");
$kimenetek['AJAX keret'] = t_folyamat("define('DOING_AJAX', true); \$_POST['action'] = \$_REQUEST['action'] = 'sdh_muhely_ugyfelek_ment';\n" . $alap
    . "SDH_Demo::ment(['keret' => 1]); for (\$i = 0; \$i < 3; \$i++) { \$GLOBALS['wpdb']->insert(SDH_Muhely_Schema::tabla('ugyfel'), ['nev' => 'x' . \$i]); \$GLOBALS['wpdb']->get_var('SELECT 1'); }");
$kimenetek['nem-AJAX keret oldal'] = t_folyamat($alap . "SDH_Demo::ment(['keret' => 1]); for (\$i = 0; \$i < 3; \$i++) { \$GLOBALS['wpdb']->insert(SDH_Muhely_Schema::tabla('ugyfel'), ['nev' => 'y' . \$i]); \$GLOBALS['wpdb']->get_var('SELECT 1'); }");

T::tartalmaz($kimenetek['AJAX popup tiltás'], 'class="bm-hiba"', 'popupban saját, semleges HTML');
T::tartalmaz((string) (json_decode(trim($kimenetek['AJAX keret']), true)['data']['uzenet'] ?? ''), 'Elérted a bemutató napi keretét', 'AJAX keret-üzenet');
T::tartalmaz($kimenetek['nem-AJAX keret oldal'], '<span class="bm-jel">BEMUTATÓ</span>', 'nem-AJAX: saját leállító oldal');

foreach ($kimenetek as $nev => $szoveg) {
    T::igaz(trim($szoveg) !== '', 'van kimenet: ' . $nev);
    T::egyezik([], t_tiltott($szoveg), 'tiltott minta: ' . $nev);
}

// Saját azonosítók: csak bm-* osztály / data-bm-*, a lekérdezés-változó semleges, süti nincs.
preg_match_all('/class="([^"]+)"/', $kimenetek['sáv'] . $kimenetek['kimenő nézet'] . $kimenetek['indító'], $o);
$osztalyok = array_unique(preg_split('/\s+/', implode(' ', $o[1])));
T::egyezik([], array_values(array_filter($osztalyok, static fn (string $c): bool => $c !== '' && strpos($c, 'bm-') !== 0 && $c !== 'cf-turnstile')), 'minden osztály bm-*');
T::egyezik(0, preg_match('/data-(?!bm-|sitekey|language)[a-z]/', $kimenetek['sáv'] . $kimenetek['indító']), 'minden data-attribútum data-bm-*');
T::igaz(!in_array('Set-Cookie', array_map(static fn ($h) => strtok((string) $h, ':'), headers_list()), true), 'a bemutató nem állít sütit');

T::vege('t299 bemutató-tiltott minták');
