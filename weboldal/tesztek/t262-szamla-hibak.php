<?php
/**
 * t262 – Számlázz.hu hibakezelés: v2 XML-hiba (hibakod/hibauzenet), v1 „[ERR]” + szlahu_* fejlécek, hálózati hiba,
 * HTTP-hiba, sajátos üzenetek (3, 202), hiányzó vevőadat (nincs kérés), legfeljebb 5 automatikus próba, kézi
 * újraindítás nulláz, duplikált rendelésszám (71/152) → a meglévő bizonylat visszakeresése, napló, zár.
 */
require __DIR__ . '/_t26-seged.php';

global $wpdb;
t26_teszt_kulcs();

// v2 hiba
$GLOBALS['t26_agent_fv'] = static fn () => t26_agent_hiba('3', 'Sikertelen bejelentkezés.');
$id = t26_rendeles();
$e  = SDH_Sales_Szamlazz::dijbekero($id);
T::igaz(!$e['ok'], 'v2 hiba: nem ok');
T::tartalmaz($e['uzenet'], 'Számla Agent kulcsot', 'v2 hiba 3: saját magyarázat');
T::tartalmaz($e['uzenet'], '(Számlázz.hu hibakód: 3)', 'v2 hiba: kód');
T::egyezik('', (string) SDH_Sales_Szamlazz::rendeles($id)['szamla_dijbekero'], 'hibánál nem ment számlaszámot');
$n = SDH_Sales_Naplo::lista(['ref_tipus' => 'rendeles', 'ref_id' => $id, 'szint' => 'hiba']);
T::igaz($n['ossz'] >= 1, 'hiba naplózva');

// v1 [ERR] + fejlécek
$GLOBALS['t26_agent_fv'] = static fn () => t26_agent_hiba('202', 'Hibás előtag', 1);
$id = t26_rendeles();
$e  = SDH_Sales_Szamlazz::dijbekero($id);
T::tartalmaz($e['uzenet'], 'előtag nincs felvéve', 'v1 hiba 202: saját magyarázat');

// csak [ERR] törzs, fejléc nélkül
$GLOBALS['t26_agent_fv'] = static fn () => t_valasz(200, "[ERR] Ismeretlen hiba történt.\n----------\nnyom");
$v = SDH_Sales_Szamlazz::agent_kuld('action-xmlagentxmlfile', '<x/>');
T::igaz(!$v['ok'] && $v['uzenet'] === 'Ismeretlen hiba történt.', '[ERR] törzs értelmezése');

// v1 siker szöveges törzzsel
$GLOBALS['t26_agent_fv'] = static fn () => t_valasz(200, 'xmlagentresponse=DONE;E-ABC-2026-12');
$v = SDH_Sales_Szamlazz::agent_kuld('action-xmlagentxmlfile', '<x/>');
T::igaz($v['ok'] && $v['szamlaszam'] === 'E-ABC-2026-12', 'DONE;számlaszám értelmezése');

// v1 PDF-törzs, számlaszám fejlécben (URL-kódolt)
$GLOBALS['t26_agent_fv'] = static fn () => t_valasz(200, SDH_Sales_Szamlazz::pdf_ir([['x', 10]]), ['szlahu_szamlaszam' => rawurlencode('E-ÁB/2026 3')]);
$v = SDH_Sales_Szamlazz::agent_kuld('action-xmlagentxmlfile', '<x/>');
T::igaz($v['ok'] && $v['szamlaszam'] === 'E-ÁB/2026 3' && strncmp($v['pdf'], '%PDF', 4) === 0, 'PDF-törzs + URL-kódolt fejléc');

// HTTP 500 törzs nélkül
$GLOBALS['t26_agent_fv'] = static fn () => t_valasz(500, '');
$v = SDH_Sales_Szamlazz::agent_kuld('action-xmlagentxmlfile', '<x/>');
T::igaz(!$v['ok'] && strpos($v['uzenet'], '500') !== false, 'HTTP 500');

// hálózati hiba
$GLOBALS['t26_agent_fv'] = null;
add_filter('pre_http_request', static function ($pre, $args, $url) {
    return !empty($GLOBALS['t26_halozat_le']) && strpos((string) $url, 'szamlazz.hu') !== false ? new WP_Error('http_request_failed', 'cURL error 28: timeout') : $pre;
}, 5, 3);
$GLOBALS['t26_halozat_le'] = true;
$v = SDH_Sales_Szamlazz::agent_kuld('action-xmlagentxmlfile', '<x/>');
T::igaz(!$v['ok'] && strpos($v['uzenet'], 'nem érhető el') !== false, 'hálózati hiba');

// legfeljebb 5 automatikus próba
$id = t26_rendeles();
$GLOBALS['t26_agent'] = [];
for ($i = 0; $i < 7; $i++) {
    SDH_Sales_Szamlazz::dijbekero($id);
}
T::egyezik(5, count($GLOBALS['t26_agent']), 'legfeljebb 5 próba (Számlázz.hu ajánlás)');
$e = SDH_Sales_Szamlazz::dijbekero($id);
T::tartalmaz($e['uzenet'], 'kézzel', '6. próba: kézi indítást kér');
$GLOBALS['t26_halozat_le'] = false;
$GLOBALS['t26_agent_fv'] = static fn ($m, $x, $n) => t26_agent_ok('E-KEZI-1');
$v = t_rest('POST', '/admin/szamla/' . $id . '/ujra', ['tipus' => 'dijbekero']);
T::egyezik(200, $v->get_status(), 'kézi újraindítás (admin REST) sikeres');
T::egyezik('E-KEZI-1', (string) ($v->get_data()['szamlaszam'] ?? ''), 'kézi újra: számlaszám');

// hiányzó vevőadat: nincs Agent-kérés
$GLOBALS['t26_agent'] = [];
$id = t26_rendeles(['vevo' => wp_json_encode(['nev' => 'Hiányos Kft.', 'email' => 'h@pelda.hu'])]);
$e  = SDH_Sales_Szamlazz::dijbekero($id);
T::igaz(!$e['ok'] && strpos($e['uzenet'], 'irányítószám') !== false, 'hiányzó vevőadat felsorolva');
T::egyezik(0, count($GLOBALS['t26_agent']), 'hiányos vevő: nincs kérés');

// duplikált rendelésszám (71): a meglévő bizonylat visszakeresése külső azonosítóval
$GLOBALS['t26_agent'] = [];
$GLOBALS['t26_agent_fv'] = static function (string $mezo, string $xml) {
    if ($mezo === 'action-xmlagentxmlfile') {
        return t26_agent_hiba('71', 'Már létező rendelésszám');
    }
    if ($mezo === 'action-szamla_agent_xml' && strpos($xml, '<szamlaKulsoAzon>') !== false) {
        return t_valasz(200, '<?xml version="1.0" encoding="UTF-8"?><szamla xmlns="http://www.szamlazz.hu/szamla"><alap><szamlaszam>E-MEGLEVO-9</szamlaszam><tipus>D</tipus></alap></szamla>');
    }
    return t_valasz(500, '');
};
$id = t26_rendeles();
$e  = SDH_Sales_Szamlazz::dijbekero($id);
T::igaz($e['ok'] && $e['szamlaszam'] === 'E-MEGLEVO-9', 'duplikátum (71): meglévő bizonylat kapcsolva');
T::egyezik('action-szamla_agent_xml', $GLOBALS['t26_agent'][1]['mezo'] ?? '', 'lekérdezés mezőneve');
T::tartalmaz($GLOBALS['t26_agent'][1]['xml'] ?? '', 'xmlns="http://www.szamlazz.hu/xmlszamlaxml"', 'lekérdezés névtere');
T::tartalmaz($GLOBALS['t26_agent'][1]['xml'] ?? '', '-d</szamlaKulsoAzon>', 'lekérdezés a külső azonosítóval');

// zár: ha egy másik folyamat éppen számláz, nem küld
add_filter('sdh_sales_zar_proba', static fn () => 1);
$id = t26_rendeles();
$wpdb->insert(SDH_Sales_Schema::t('korlat'), ['kulcs' => 'zar:szamla:' . $id, 'db' => 1, 'lejar' => gmdate('Y-m-d H:i:s', time() + 60)]);
$GLOBALS['t26_agent'] = [];
$e = SDH_Sales_Szamlazz::dijbekero($id);
T::igaz(!$e['ok'] && count($GLOBALS['t26_agent']) === 0, 'zár: párhuzamos számlázás kizárva');

// admin REST: látogatónak tilos
$v = t_latogato(static fn () => t_rest('POST', '/admin/szamla/' . $id . '/ujra'));
T::igaz(in_array($v->get_status(), [401, 403], true), 'admin/ujra látogatónak tilos');
$v = t_latogato(static fn () => t_rest('POST', '/admin/szamlazz/proba'));
T::igaz(in_array($v->get_status(), [401, 403], true), 'admin/szamlazz/proba látogatónak tilos');

// kapcsolatpróba: „nincs ilyen számla” = jó kulcs, 3 = rossz kulcs
$GLOBALS['t26_agent_fv'] = static fn () => t26_agent_hiba('7', 'Nincs ilyen számla', 1);
$v = t_rest('POST', '/admin/szamlazz/proba');
T::igaz($v->get_status() === 200 && strpos((string) ($v->get_data()['ipn_url'] ?? ''), 'k=') !== false, 'próba: jó kulcs + IPN-cím');
$GLOBALS['t26_agent_fv'] = static fn () => t26_agent_hiba('3', 'Sikertelen bejelentkezés', 1);
$v = t_rest('POST', '/admin/szamlazz/proba');
T::egyezik(422, $v->get_status(), 'próba: rossz kulcs');

// Rejtés: a hibaválaszok (REST) és a hibakódok.
t_rejtett(t_rest('POST', '/admin/szamlazz/proba'), 'Agent-próba REST-válasza');
t_rejtett(t_latogato(static fn () => t_rest('GET', '/szamla/NINCS-1/pdf', ['tipus' => 'vegszamla', 't' => str_repeat('a', 32)])), 'vevői PDF hibaválasz');
T::vege('t262 számla-hibák');
