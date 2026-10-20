<?php
/**
 * t325 – Telepítések és kiadások a Portál adminjából (a licencközpont logikájával):
 * telepítés-lista szűrőkkel (jelentkezik, nem jelentkezik, gondban, régi verzió) és kereséssel, részletek, címke / jegyzet,
 * távoli művelet kérése (és ismeretlen típus elutasítva), leválasztás; kiadás feltöltése (stabil / teszt), csatornaváltás,
 * törlés; a kiadás nélküli állapot jelzése a letöltő linknél.
 */
require __DIR__ . '/_t32-seged.php';

global $wpdb;

$vid = t32_vevo('Telepítés Teszt Kft.', 'telep@example.com');
$l   = t32_licenc($vid, ['max_peldany' => 5]);
$id  = (int) $l->id;

// Kiadás nélkül: a letöltő link elkészül, de figyelmeztet
$v = t32_muv($id, 'letoltes_link');
T::tartalmaz((string) $v->get_data()['uzenet'], 'még nincs feltöltött kiadás', 'kiadás nélkül: figyelmeztetés');

/* --- Kiadások -------------------------------------------------------------------------------------------------------- */

$zipol = static function (string $verzio): string {
    $zip = wp_tempnam('t325.zip');
    $z   = new ZipArchive();
    $z->open($zip, ZipArchive::OVERWRITE);
    $z->addFromString('sdh-muhely/sdh-muhely.php', "<?php\n/**\n * Version: {$verzio}\n */\n");
    $z->close();

    return $zip;
};
$feltolt = static function (string $zip, string $csatorna, string $megj = ''): WP_REST_Response {
    $r = new WP_REST_Request('POST', '/' . SDH_Sales_Rest::NS . '/admin/licenc/kiadas');
    $r->set_file_params(['zip' => ['name' => 'k.zip', 'type' => 'application/zip', 'tmp_name' => $zip, 'error' => 0, 'size' => filesize($zip)]]);
    $r->set_body_params(['csatorna' => $csatorna, 'megjegyzes' => $megj]);

    return rest_do_request($r);
};

$z1 = $zipol('9.9.1');
T::egyezik(200, $feltolt($z1, 'stabil', 'első')->get_status(), 'kiadás feltöltése (stabil): 200');
$z2 = $zipol('9.9.2');
T::egyezik(200, $feltolt($z2, 'teszt', 'előzetes')->get_status(), 'kiadás feltöltése (teszt): 200');
$rossz = wp_tempnam('t325-rossz.zip');
file_put_contents($rossz, 'nem zip');
T::egyezik(400, $feltolt($rossz, 'stabil')->get_status(), 'nem zip: 400');
@unlink($z1);
@unlink($z2);
@unlink($rossz);
$r = new WP_REST_Request('POST', '/' . SDH_Sales_Rest::NS . '/admin/licenc/kiadas');
T::egyezik(400, rest_do_request($r)->get_status(), 'fájl nélkül: 400');

$k = t_rest('GET', '/admin/licenc/kiadasok')->get_data();
T::egyezik('9.9.1', $k['stabil'], 'stabil: 9.9.1');
T::egyezik('9.9.2', $k['teszt'], 'teszt: a legújabb (9.9.2)');
T::egyezik(2, count($k['lista']), 'két kiadás');
$teszt_id = (int) $k['lista'][0]['id'];
T::egyezik('előzetes', $k['lista'][0]['megjegyzes'], 'a megjegyzés mentve');

T::egyezik(200, t_rest('POST', '/admin/licenc/kiadas/' . $teszt_id, ['csatorna' => 'stabil'])->get_status(), 'kiadás mindenkinek');
T::egyezik('9.9.2', t_rest('GET', '/admin/licenc/kiadasok')->get_data()['stabil'], 'a stabil most 9.9.2');
T::egyezik('9.9.2', t_rest('GET', '/admin/licenc/' . $id)->get_data()['kiadas'], 'a licenc letöltése a 9.9.2');
$fajl = (string) $wpdb->get_var($wpdb->prepare('SELECT fajl FROM ' . SDH_Kozpont::t('kiadas') . ' WHERE id = %d', $teszt_id));
T::egyezik(200, t_rest('POST', '/admin/licenc/kiadas/' . $teszt_id, ['torol' => true])->get_status(), 'kiadás törlése');
T::igaz(!is_file($fajl), 'a fájl is törölve');
T::egyezik('9.9.1', t_rest('GET', '/admin/licenc/kiadasok')->get_data()['stabil'], 'törlés után vissza 9.9.1');
T::egyezik(404, t_rest('POST', '/admin/licenc/kiadas/999999', ['csatorna' => 'teszt'])->get_status(), 'nem létező kiadás: 404');

/* --- Telepítések ----------------------------------------------------------------------------------------------------- */

$regi_ido = wp_date('Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS);
$p_ok     = t32_peldany($id, ['cimke' => 'Fő bolt', 'verzio' => '9.9.1']);
$p_off    = t32_peldany($id, ['verzio' => '9.9.0', 'utolso_jel' => $regi_ido, 'utolso' => $regi_ido]);
$p_hiba   = t32_peldany($id, ['verzio' => '9.9.1', 'egeszseg' => 'hiba', 'problema' => (string) wp_json_encode([['kod' => 'mentes', 'szint' => 'hiba', 'szoveg' => 'Még nem készült mentés.']])]);

$r = t_rest('GET', '/admin/licenc/peldanyok')->get_data();
T::egyezik(3, (int) $r['ossz'], 'telepítések: 3');
T::egyezik(['mind' => 3, 'online' => 2, 'offline' => 1, 'hiba' => 2, 'regi' => 1], $r['szamok'], 'darabszámok');
$sz = static fn (string $s): array => array_map(static fn ($x) => $x['id'], t_rest('GET', '/admin/licenc/peldanyok', ['szuro' => $s])->get_data()['lista']);
T::egyezik([$p_off], $sz('offline'), 'szűrő: nem jelentkezik');
T::egyezik([$p_off], $sz('regi'), 'szűrő: régi verzió');
T::igaz(in_array($p_hiba, $sz('hiba'), true) && in_array($p_off, $sz('hiba'), true), 'szűrő: gondban (hibás + nem jelentkezik)');
T::egyezik([$p_ok], array_map(static fn ($x) => $x['id'], t_rest('GET', '/admin/licenc/peldanyok', ['q' => 'fő bolt'])->get_data()['lista']), 'keresés: címke');
T::egyezik(3, (int) t_rest('GET', '/admin/licenc/peldanyok', ['q' => (string) $l->kulcs])->get_data()['ossz'], 'keresés: licenckulcs');
T::egyezik(3, (int) t_rest('GET', '/admin/licenc/peldanyok', ['licenc_id' => $id])->get_data()['ossz'], 'licenc-szűrő');

$d = t_rest('GET', '/admin/licenc/peldany/' . $p_hiba)->get_data();
T::egyezik('hiba', $d['p']['statusz'], 'részletek: állapot');
T::egyezik((string) $l->kulcs, $d['licenc']['kulcs'], 'részletek: licenc');
T::igaz(isset($d['muveletek_lista']['diagnosztika']), 'részletek: a távoli műveletek listája');
T::egyezik(404, t_rest('GET', '/admin/licenc/peldany/999999')->get_status(), 'nem létező telepítés: 404');

T::egyezik(200, t_rest('POST', '/admin/licenc/peldany/' . $p_ok, ['cimke' => 'Belváros', 'jegyzet' => 'emeleten'])->get_status(), 'címke / jegyzet: 200');
T::egyezik('Belváros', (string) $wpdb->get_var($wpdb->prepare('SELECT cimke FROM ' . SDH_Kozpont::t('peldany') . ' WHERE id = %d', $p_ok)), 'címke mentve');

$v = t_rest('POST', '/admin/licenc/peldany/' . $p_ok . '/muvelet', ['tipus' => 'diagnosztika']);
T::egyezik(200, $v->get_status(), 'távoli művelet kérése: 200');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('muvelet') . " WHERE peldany_id = %d AND tipus = 'diagnosztika'", $p_ok)), 'a művelet sorba állt');
T::egyezik(400, t_rest('POST', '/admin/licenc/peldany/' . $p_ok . '/muvelet', ['tipus' => 'rm_rf'])->get_status(), 'ismeretlen művelet: 400');
$v = t_rest('POST', '/admin/licenc/peldany/' . $p_ok . '/muvelet', ['tipus' => 'uzenet', 'parameter' => ['szoveg' => 'Ma 18-kor frissítünk.', 'szint' => 'info']]);
T::egyezik(200, $v->get_status(), 'üzenet a vevőnek: 200');

T::egyezik(200, t_rest('POST', '/admin/licenc/peldany/' . $p_off . '/levalaszt')->get_status(), 'leválasztás: 200');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('peldany') . ' WHERE id = %d', $p_off)), 'a telepítés leválasztva');
T::egyezik(2, (int) t_rest('GET', '/admin/licenc/' . $id)->get_data()['licenc']['peldany_db'], 'a licencen felszabadult a hely');
T::igaz((bool) array_filter(t_rest('GET', '/admin/licenc/naplo', ['licenc_id' => $id])->get_data()['lista'], static fn ($x) => strpos($x['szoveg'], 'eltávolítva') !== false), 'leválasztás naplózva');

T::vege('t325 telepítések és kiadások');
