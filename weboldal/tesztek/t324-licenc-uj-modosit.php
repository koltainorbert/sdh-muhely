<?php
/**
 * t324 – Kézi licenc létrehozása és módosítása a Portál adminjából:
 * meglévő vevőnek / új vevővel, azonnali kulcsküldéssel; ellenőrzések (vevő, e-mail, modul, dátum, keretek, ciklus);
 * a módosítás csak a megadott mezőket írja (a többi, pl. modulok, jogok, állapot marad), a kulcs nem változik.
 */
require __DIR__ . '/_t32-seged.php';

global $wpdb;

$licdb = static fn (): int => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('licenc'));
$vid   = t32_vevo('Meglévő Vevő Kft.', 'meglevo@example.com');

/* --- Létrehozás ----------------------------------------------------------------------------------------------------- */

$v = t_rest('POST', '/admin/licenc', ['vevo_id' => $vid, 'csomag' => 'Pro', 'modulok' => ['uzenetek', 'sms'], 'lejar' => '2030-12-31', 'max_peldany' => 3, 'max_felhasznalo' => 8, 'ciklus' => 'havi', 'dij' => 19900, 'megjegyzes' => 'kézi']);
T::egyezik(200, $v->get_status(), 'meglévő vevőnek: 200');
$l = $v->get_data()['licenc'];
T::igaz(preg_match('/^[A-Z0-9]{2,10}(-[A-Z2-9]{4}){4}$/', $l['kulcs']) === 1, 'kulcs (' . $l['kulcs'] . ')');
T::egyezik(['Meglévő Vevő Kft.', 'Pro', ['uzenetek', 'sms'], '2030-12-31', 3, 8, 'havi', 19900, 'kézi'], [$l['vevo'], $l['csomag'], $l['modulok'], $l['lejar'], $l['max_peldany'], $l['max_felhasznalo'], $l['ciklus'], $l['dij'], $l['megjegyzes']], 'minden mező mentve');
T::egyezik(['frissites' => true, 'tamogatas' => true, 'tavoli' => true], $l['jogok'], 'alapból minden jog');
T::tartalmaz((string) $v->get_data()['uzenet'], $l['kulcs'], 'az üzenetben a kulcs');

$GLOBALS['sdh_t_levelek'] = [];
$v = t_rest('POST', '/admin/licenc', ['vevo' => ['nev' => 'Új Vevő Bt.', 'email' => 'uj@example.com', 'adoszam' => '11111111-1-11', 'kapcsolattarto' => 'Kiss Anna'], 'csomag' => 'Start', 'kuld' => true, 'link' => false]);
T::egyezik(200, $v->get_status(), 'új vevővel: 200');
$l2 = $v->get_data();
T::egyezik('Új Vevő Bt.', $l2['vevo']['nev'], 'az új vevő létrejött');
T::egyezik('11111111-1-11', $l2['vevo']['adoszam'], 'az új vevő adószáma');
T::egyezik('*', $l2['licenc']['modulok'], 'modul nélkül: minden modul');
T::tartalmaz((string) $l2['uzenet'], 'Elküldve', 'azonnal elküldve');
$level = end($GLOBALS['sdh_t_levelek']);
T::igaz(is_array($level) && in_array('uj@example.com', (array) $level['to'], true) && strpos((string) $level['message'], $l2['licenc']['kulcs']) !== false, 'a levél a kulccsal az új vevőnek');
T::tartalmaz(is_array($level) ? (string) $level['message'] : '', 'Kiss Anna', 'a levél a kapcsolattartót szólítja');

// Ellenőrzések: hibánál nem jön létre semmi
$n0 = $licdb();
$hibas = [
    'nincs vevő'           => [],
    'nem létező vevő'      => ['vevo_id' => 999999],
    'új vevő név nélkül'   => ['vevo' => ['email' => 'x@example.com']],
    'rossz e-mail'         => ['vevo' => ['nev' => 'X', 'email' => 'nem email']],
    'ismeretlen modul'     => ['vevo_id' => $vid, 'modulok' => ['uzenetek', 'kitalalt']],
    'új vevő + rossz modul' => ['vevo' => ['nev' => 'Árva Vevő Kft.'], 'modulok' => ['kitalalt']],
    'rossz dátum'          => ['vevo_id' => $vid, 'lejar' => '2030-13-01'],
    'nulla telepítés'      => ['vevo_id' => $vid, 'max_peldany' => 0],
    'túl sok felhasználó'  => ['vevo_id' => $vid, 'max_felhasznalo' => 5000],
    'ismeretlen ciklus'    => ['vevo_id' => $vid, 'ciklus' => 'hetente'],
];
foreach ($hibas as $nev => $be) {
    $s = t_rest('POST', '/admin/licenc', $be)->get_status();
    T::igaz($s === 400 || $s === 404, 'hibás (' . $nev . '): ' . $s);
}
T::egyezik($n0, $licdb(), 'hibás kérésre nem jött létre licenc');
T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . SDH_Kozpont::t('vevo') . " WHERE nev = 'X'"), 'hibás e-mailnél vevő sem jött létre');
T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . SDH_Kozpont::t('vevo') . " WHERE nev = 'Árva Vevő Kft.'"), 'hibás licencadatnál az új vevő sem jött létre');

/* --- Módosítás ------------------------------------------------------------------------------------------------------ */

$id = (int) $l['id'];
t32_muv($id, 'tilt');
$v = t_rest('POST', '/admin/licenc/' . $id, ['max_peldany' => 5, 'uzenet' => 'Hamarosan lejár', 'jogok' => ['frissites' => true, 'tamogatas' => false, 'tavoli' => true]]);
T::egyezik(200, $v->get_status(), 'módosítás: 200');
$m = $v->get_data()['licenc'];
T::egyezik(5, $m['max_peldany'], 'a megadott mező változott');
T::egyezik('Hamarosan lejár', $m['uzenet'], 'üzenet a vevőnek');
T::egyezik(false, $m['jogok']['tamogatas'], 'jog kikapcsolva');
T::egyezik(['uzenetek', 'sms'], $m['modulok'], 'a modulok maradtak');
T::egyezik('2030-12-31', $m['lejar'], 'a lejárat maradt');
T::egyezik(19900, $m['dij'], 'a díj maradt');
T::egyezik('tiltva', $m['allapot'], 'az állapot maradt (felfüggesztve)');
T::egyezik($l['kulcs'], $m['kulcs'], 'a kulcs nem változott');
T::egyezik(400, t_rest('POST', '/admin/licenc/' . $id, ['lejar' => 'holnap'])->get_status(), 'módosítás: rossz dátum 400');
T::egyezik(5, (int) t32_l($id)->max_peldany, 'hibás módosítás után változatlan');
$v = t_rest('POST', '/admin/licenc/' . $id, ['lejar' => '']);
T::igaz(empty(t32_l($id)->lejar), 'módosítás: lejárat törölhető');
T::egyezik(404, t_rest('POST', '/admin/licenc/999999', ['dij' => 1])->get_status(), 'nem létező: 404');

// Vevőválasztó
$vv = t_rest('GET', '/admin/licenc/vevok', ['q' => 'új vevő'])->get_data()['lista'];
T::egyezik(1, count($vv), 'vevőválasztó: keresés');
T::egyezik('uj@example.com', $vv[0]['email'], 'vevőválasztó: e-mail');
T::igaz(count(t_rest('GET', '/admin/licenc/vevok')->get_data()['lista']) >= 2, 'vevőválasztó: üres keresés = lista');

T::vege('t324 licenc létrehozása és módosítása');
