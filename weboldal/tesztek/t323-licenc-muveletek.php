<?php
/**
 * t323 – Licencműveletek a Portál adminjából (mind naplózva a licencközpontban és a Portál naplójában):
 * hosszabbítás (egy ciklus / N hónap / dátumra / lejárat nélkül), felfüggesztés és visszaállítás, új kulcs (a webes
 * rendeléseken is), modul hozzáadása / elvétele (a „minden modul”-ból is), csomagváltás (keretekkel / csak névvel),
 * kulcs küldése e-mailben (új letöltő linkkel), letöltő link újragenerálása (a régi érvénytelen), hibás bemenetek.
 */
require __DIR__ . '/_t32-seged.php';

global $wpdb;

if (class_exists('SDH_Sales_Tartalom')) {
    SDH_Sales_Tartalom::betolt(false); // a webhely csomagjai (csomagváltáshoz)
}

$ma  = wp_date('Y-m-d');
$vid = t32_vevo('Művelet Teszt Kft.', 'muvelet@example.com');
$l   = t32_licenc($vid, ['lejar' => wp_date('Y-m-d', (int) strtotime($ma . ' +10 days')), 'ciklus' => 'eves', 'modulok' => ['uzenetek']]);
$id  = (int) $l->id;
$esemeny = static fn (): int => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('esemeny') . ' WHERE licenc_id = %d', $id));
$pnaplo  = static fn (): int => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('naplo') . " WHERE ref_tipus = 'licenc' AND ref_id = %d", $id));

/* --- Hosszabbítás ------------------------------------------------------------------------------------------------- */

$e0 = $esemeny();
$p0 = $pnaplo();
$v  = t32_muv($id, 'hosszabbit');
T::egyezik(200, $v->get_status(), 'hosszabbítás egy ciklussal: 200');
T::egyezik(wp_date('Y-m-d', (int) strtotime($l->lejar . ' +12 month')), (string) t32_l($id)->lejar, 'egy ciklus (éves): +12 hó a régi lejárattól');
T::igaz($esemeny() > $e0 && $pnaplo() > $p0, 'naplózva (licencközpont + Portál)');
T::igaz(isset($v->get_data()['licenc'], $v->get_data()['uzenet']), 'a válasz: üzenet + friss részletek');

$elotte = (string) t32_l($id)->lejar;
t32_muv($id, 'hosszabbit', ['ho' => 3]);
T::egyezik(wp_date('Y-m-d', (int) strtotime($elotte . ' +3 month')), (string) t32_l($id)->lejar, '+3 hó');

t32_muv($id, 'hosszabbit', ['lejar' => '2031-05-31']);
T::egyezik('2031-05-31', (string) t32_l($id)->lejar, 'dátumra');
T::egyezik('2031-05-31', (string) t32_l($id)->esedekes, 'az esedékesség is');
T::egyezik(400, t32_muv($id, 'hosszabbit', ['lejar' => '2031-02-30'])->get_status(), 'érvénytelen dátum: 400');
T::egyezik(400, t32_muv($id, 'hosszabbit', ['ho' => 500])->get_status(), 'túl hosszú hosszabbítás: 400');

// Lejárt licenc: a mai naptól számol
$wpdb->update(SDH_Kozpont::t('licenc'), ['lejar' => '2020-01-01'], ['id' => $id]);
t32_muv($id, 'hosszabbit', ['ho' => 1]);
T::egyezik(wp_date('Y-m-d', (int) strtotime($ma . ' +1 month')), (string) t32_l($id)->lejar, 'lejártnál a mai naptól');

t32_muv($id, 'lejarat_nelkul');
T::igaz(empty(t32_l($id)->lejar), 'lejárat nélkül');

/* --- Felfüggesztés, visszaállítás ---------------------------------------------------------------------------------- */

T::egyezik(200, t32_muv($id, 'tilt')->get_status(), 'felfüggesztés: 200');
T::egyezik('tiltva', (string) t32_l($id)->allapot, 'felfüggesztve');
T::egyezik('tiltva', t_rest('GET', '/admin/licenc/' . $id)->get_data()['licenc']['kod'], 'a részletekben is');
t32_muv($id, 'enged');
T::egyezik('aktiv', (string) t32_l($id)->allapot, 'visszaállítva');
// A módosító úton az állapot nem állítható (csak a naplózott művelettel).
t_rest('POST', '/admin/licenc/' . $id, ['allapot' => 'tiltva']);
T::egyezik('aktiv', (string) t32_l($id)->allapot, 'módosítással nem függeszthető fel');

/* --- Modulok -------------------------------------------------------------------------------------------------------- */

t32_muv($id, 'modul_be', ['modul' => 'sms']);
T::egyezik(['uzenetek', 'sms'], SDH_Kozpont::licenc_modulok(t32_l($id)), 'modul hozzáadva');
$v = t32_muv($id, 'modul_be', ['modul' => 'sms']);
T::egyezik(200, $v->get_status(), 'már benne: nem hiba');
T::tartalmaz((string) $v->get_data()['uzenet'], 'már benne', 'már benne: üzenet');
t32_muv($id, 'modul_ki', ['modul' => 'uzenetek']);
T::egyezik(['sms'], SDH_Kozpont::licenc_modulok(t32_l($id)), 'modul elvéve');
T::egyezik(400, t32_muv($id, 'modul_be', ['modul' => 'nem_letezo'])->get_status(), 'ismeretlen modul: 400');

$wpdb->update(SDH_Kozpont::t('licenc'), ['modulok' => null], ['id' => $id]);
T::egyezik('*', SDH_Kozpont::licenc_modulok(t32_l($id)), 'minden modul');
t32_muv($id, 'modul_ki', ['modul' => 'sms']);
$m = (array) SDH_Kozpont::licenc_modulok(t32_l($id));
T::igaz(!in_array('sms', $m, true) && in_array('uzenetek', $m, true) && count($m) === count(SDH_Sales_Licenc::katalogus()) - 1, 'a „minden”-ből egy elvéve: a többi egyenként marad');

/* --- Csomagváltás --------------------------------------------------------------------------------------------------- */

$cs = SDH_Sales_Licenc::csomagok();
T::igaz(count($cs) >= 2, 'a webhely csomagjai elérhetők (' . count($cs) . ')');
$start = null;
foreach ($cs as $c) {
    if ($c['kulcs'] === 'start') {
        $start = $c;
    }
}
T::igaz($start !== null, 'Start csomag');
t32_muv($id, 'csomag', ['csomag' => 'start']);
$x = t32_l($id);
T::egyezik('Start', (string) $x->csomag, 'csomagváltás: név');
T::egyezik($start['modulok'], SDH_Kozpont::licenc_modulok($x), 'csomagváltás: a csomag moduljai');
T::egyezik((int) $start['max_peldany'], (int) $x->max_peldany, 'csomagváltás: telepítésszám');
T::egyezik((int) $start['max_felhasznalo'], (int) $x->max_felhasznalo, 'csomagváltás: felhasználószám');
$wpdb->update(SDH_Kozpont::t('licenc'), ['max_peldany' => 7], ['id' => $id]);
t32_muv($id, 'csomag', ['csomag' => 'pro', 'modulok_is' => false]);
T::egyezik('Pro', (string) t32_l($id)->csomag, 'csak név: a név változott');
T::egyezik(7, (int) t32_l($id)->max_peldany, 'csak név: a keret maradt');
T::egyezik(400, t32_muv($id, 'csomag', ['csomag' => 'nincs'])->get_status(), 'ismeretlen csomag: 400');

/* --- Új kulcs (a webes rendelésen is) ------------------------------------------------------------------------------- */

$regi = (string) t32_l($id)->kulcs;
$wpdb->insert(SDH_Sales_Schema::t('rendeles'), ['azon' => 'R-2026-323001', 'allapot' => 'kiszolgalva', 'tipus' => 'uj', 'vevo' => '{}', 'tetelek' => '[]', 'netto' => 1, 'afa' => 0, 'brutto' => 1, 'email' => 'muvelet@example.com', 'licenc_kulcs' => $regi, 'letrehozva' => gmdate('Y-m-d H:i:s')]);
t32_muv($id, 'ujkulcs');
$uj = (string) t32_l($id)->kulcs;
T::igaz($uj !== $regi && preg_match('/^[A-Z0-9]{2,10}(-[A-Z2-9]{4}){4}$/', $uj) === 1, 'új kulcs (' . $uj . ')');
T::egyezik(null, SDH_Kozpont::licenc($regi), 'a régi kulcs megszűnt');
T::egyezik($uj, (string) $wpdb->get_var("SELECT licenc_kulcs FROM " . SDH_Sales_Schema::t('rendeles') . " WHERE azon = 'R-2026-323001'"), 'a webes rendelésen is az új kulcs');

/* --- Letöltő link --------------------------------------------------------------------------------------------------- */

$zip = wp_tempnam('t323.zip');
$z   = new ZipArchive();
$z->open($zip, ZipArchive::OVERWRITE);
$z->addFromString('sdh-muhely/sdh-muhely.php', "<?php\n/**\n * Version: 9.9.323\n */\n");
$z->close();
SDH_Kozpont::kiadas_be($zip, 'stabil', 't323', 'teszt');
@unlink($zip);

$tok = static fn (string $url): string => (string) (wp_parse_args((string) wp_parse_url($url, PHP_URL_QUERY))['t'] ?? wp_parse_args((string) wp_parse_url($url, PHP_URL_QUERY))[SDH_Kozpont_Kiszolgalas::LETOLTES_PARAM] ?? '');
$v1 = t32_muv($id, 'letoltes_link')->get_data();
T::igaz(!empty($v1['link']), 'letöltő link: a válaszban (' . ($v1['link'] ?? '') . ')');
T::igaz(is_array(SDH_Kozpont_Kiszolgalas::letoltes_ellenoriz($tok($v1['link']), false)), 'az új link érvényes');
$v2 = t32_muv($id, 'letoltes_link')->get_data();
T::igaz(is_string(SDH_Kozpont_Kiszolgalas::letoltes_ellenoriz($tok($v1['link']), false)), 'újrageneráláskor a régi link érvénytelen');
T::igaz(is_array(SDH_Kozpont_Kiszolgalas::letoltes_ellenoriz($tok($v2['link']), false)), 'az újabb link érvényes');
$d = t_rest('GET', '/admin/licenc/' . $id)->get_data();
T::egyezik(2, count($d['letoltesek']), 'a részletekben a linkek');
T::egyezik(1, count(array_filter($d['letoltesek'], static fn ($x) => $x['el'])), 'ebből egy érvényes');
T::egyezik('kezi', $d['letoltesek'][0]['rendeles'], 'forrás: kézi');

/* --- Kulcs küldése -------------------------------------------------------------------------------------------------- */

$GLOBALS['sdh_t_levelek'] = [];
$v = t32_muv($id, 'kuld', ['link' => true]);
T::egyezik(200, $v->get_status(), 'kulcs küldése: 200');
T::tartalmaz((string) $v->get_data()['uzenet'], 'Elküldve', 'kulcs küldése: üzenet');
T::nem_tartalmaz((string) $v->get_data()['uzenet'], 'muvelet@example.com', 'a címzett maszkolva');
$level = end($GLOBALS['sdh_t_levelek']);
T::igaz(is_array($level) && in_array('muvelet@example.com', (array) $level['to'], true), 'a levél a vevőnek ment');
$torzs = is_array($level) ? (string) $level['message'] : '';
T::tartalmaz($torzs, $uj, 'a levélben a kulcs');
T::tartalmaz($torzs, 'A telepítőcsomag letöltése', 'a levélben letöltő link');
T::igaz(preg_match('/wordpress|\bsdh\b/i', $torzs . (is_array($level) ? (string) $level['subject'] : '')) !== 1, 'a levélben nincs rendszer- / márkanév');

$GLOBALS['sdh_t_levelek'] = [];
t32_muv($id, 'kuld', ['email' => 'masik@example.com', 'link' => false]);
$level = end($GLOBALS['sdh_t_levelek']);
T::igaz(is_array($level) && in_array('masik@example.com', (array) $level['to'], true), 'megadott címzettnek');
T::nem_tartalmaz(is_array($level) ? (string) $level['message'] : '', 'A telepítőcsomag letöltése', 'link nélkül nincs letöltő link');
T::egyezik(400, t32_muv($id, 'kuld', ['email' => 'nem-email'])->get_status(), 'érvénytelen címzett: 400');
$wpdb->update(SDH_Kozpont::t('vevo'), ['email' => ''], ['id' => $vid]);
T::egyezik(400, t32_muv($id, 'kuld')->get_status(), 'a vevőnek nincs e-mailje és nincs megadva: 400');

/* --- Hibás művelet, napló ------------------------------------------------------------------------------------------- */

T::egyezik(400, t32_muv($id, 'nincs_ilyen')->get_status(), 'ismeretlen művelet: 400');
T::egyezik(404, t32_muv(999999, 'tilt')->get_status(), 'nem létező licenc: 404');
$n = t_rest('GET', '/admin/licenc/naplo', ['licenc_id' => $id])->get_data()['lista'];
T::igaz(count($n) >= 10, 'a napló a licenc műveleteivel (' . count($n) . ')');
T::igaz((bool) array_filter($n, static fn ($x) => strpos($x['szoveg'], 'Új kulcs') !== false), 'a naplóban az új kulcs');
T::igaz((bool) array_filter($n, static fn ($x) => $x['ki'] !== ''), 'a naplóban ki csinálta');

T::vege('t323 licencműveletek');
