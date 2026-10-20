<?php
/** t212 – rendelés: adószám-ellenőrző jegy, számlázási adatok, B2B-nyilatkozat, ÁSZF/EULA, azonosító, közlemény, vevői fiók, levél, REST. */
require __DIR__ . '/_t210-seged.php';
t_katalogus_betolt();

// Adószám
T::igaz(SDH_Sales_Rendeles::adoszam_jo(t_adoszam()), 'érvényes adószám');
T::igaz(SDH_Sales_Rendeles::adoszam_jo(str_replace('-', '', t_adoszam())), 'kötőjel nélkül is');
T::igaz(SDH_Sales_Rendeles::adoszam_jo('10773381-2-44'), 'valós formátumú adószám (ellenőrző jegy jó)');
$rossz = t_adoszam(); $rossz[7] = (string) (((int) $rossz[7] + 1) % 10);
T::igaz(!SDH_Sales_Rendeles::adoszam_jo($rossz), 'rossz ellenőrző jegy');
T::igaz(!SDH_Sales_Rendeles::adoszam_jo(t_adoszam('1234567', '7')), 'rossz áfakód (7)');
T::igaz(!SDH_Sales_Rendeles::adoszam_jo(t_adoszam('1234567', '2', '99')), 'rossz megyekód (99)');
T::igaz(!SDH_Sales_Rendeles::adoszam_jo('1234-5678'), 'rövid');
T::igaz(SDH_Sales_Rendeles::eu_adoszam_jo('ATU12345678') && SDH_Sales_Rendeles::eu_adoszam_jo('DE123456789') && !SDH_Sales_Rendeles::eu_adoszam_jo('DE12345'), 'EU adószám formátum');

// Vevő-ellenőrzés
$e = SDH_Sales_Rendeles::vevo_ellenoriz(['ceg' => '', 'adoszam' => $rossz, 'email' => 'x', 'telefon' => '12', 'irsz' => '82', 'telepules' => '', 'utca' => '', 'kapcsolattarto' => '']);
foreach (['ceg', 'adoszam', 'email', 'telefon', 'irsz', 'telepules', 'utca', 'kapcsolattarto'] as $m) {
    T::igaz(isset($e['hibak'][$m]), 'hibás mező jelölve: ' . $m);
}
T::tartalmaz($e['hibak']['adoszam'], 'hibás', 'magyar adószám-hibaüzenet');
$e = SDH_Sales_Rendeles::vevo_ellenoriz(t_vevo(['orszag' => 'AT', 'adoszam' => '', 'eu_adoszam' => 'ATU12345678', 'irsz' => '1010']));
T::egyezik([], $e['hibak'], 'osztrák cég EU-adószámmal jó');
$e = SDH_Sales_Rendeles::vevo_ellenoriz(t_vevo(['orszag' => 'AT', 'adoszam' => '', 'eu_adoszam' => '']));
T::igaz(isset($e['hibak']['eu_adoszam']), 'külföldi cég EU-adószám nélkül hibás');
$e = SDH_Sales_Rendeles::vevo_ellenoriz(t_vevo(['eu_adoszam' => 'HU99999999']));
T::igaz(isset($e['hibak']['eu_adoszam']), 'HU közösségi adószám nem egyezik az adószámmal');
$e = SDH_Sales_Rendeles::vevo_ellenoriz(t_vevo(['adoszam' => str_replace('-', '', t_adoszam())]));
T::egyezik(t_adoszam(), $e['vevo']['adoszam'], 'adószám formázva');

// B2B + ÁSZF + EULA
$alap = ['tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start']], 'opciok' => ['ciklus' => 'havi'], 'vevo' => t_vevo(), 'fizmod' => 'atutalas'];
$e = SDH_Sales_Rendeles::letrehoz($alap + ['elfogad' => ['aszf' => 1, 'eula' => 1]]);
T::igaz(empty($e['ok']) && isset($e['hibak']['elfogad_vallalkozas']), 'B2B-nyilatkozat nélkül elutasítva');
T::tartalmaz((string) ($e['hibak']['elfogad_vallalkozas'] ?? ''), 'csak vállalkozások és szervezetek', 'B2B hibaüzenet');
$e = SDH_Sales_Rendeles::letrehoz($alap + ['elfogad' => ['vallalkozas' => 1, 'eula' => 1]]);
T::igaz(isset($e['hibak']['elfogad_aszf']), 'ÁSZF nélkül elutasítva');
$e = SDH_Sales_Rendeles::letrehoz($alap + ['elfogad' => ['vallalkozas' => 1, 'aszf' => 1]]);
T::igaz(isset($e['hibak']['elfogad_eula']), 'EULA nélkül elutasítva');
$e = SDH_Sales_Rendeles::letrehoz(['vevo' => t_vevo(['ceg' => '']), 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]] + $alap);
T::igaz(isset($e['hibak']['ceg']), 'cégnév kötelező');
$e = SDH_Sales_Rendeles::letrehoz(['fizmod' => 'barion', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]] + $alap);
T::igaz(isset($e['hibak']['fizmod']), 'kikapcsolt fizetési mód elutasítva');
$e = SDH_Sales_Rendeles::letrehoz(['vart_brutto' => 1, 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]] + $alap);
T::igaz(!empty($e['arvaltozas']), 'árváltozás jelzése');

// Sikeres rendelés
$GLOBALS['sdh_t_levelek'] = [];
$vevo = t_vevo();
$r = t_latogato(static fn () => t_rendeles(['vevo' => $vevo]));
T::igaz(!empty($r['ok']), 'rendelés létrejött');
T::igaz(preg_match('/^R-\d{4}-\d{6}$/', $r['azon']) === 1, 'azonosító R-ÉÉÉÉ-NNNNNN');
T::egyezik('dijbekero', $r['r']['allapot'], 'állapot díjbekérő');
T::igaz(SDH_Sales_Rendeles::kozlemeny_jo($r['kozlemeny']), 'közlemény ellenőrző jeggyel');
$hibas = substr($r['kozlemeny'], 0, -1) . (substr($r['kozlemeny'], -1) === 'A' ? 'C' : 'A');
T::igaz(!SDH_Sales_Rendeles::kozlemeny_jo($hibas), 'elgépelt közlemény felismerve');
T::egyezik(wp_date('Y-m-d', time() + 8 * DAY_IN_SECONDS), $r['r']['hatarido'], 'határidő a beállításból (8 nap)');
T::egyezik(12573, $r['r']['brutto'], 'bruttó a szerver árából');
T::egyezik('1.0', $r['r']['vevo']['_jogi']['aszf'], 'ÁSZF-verzió naplózva');
T::igaz($r['r']['vevo']['_jogi']['vallalkozas'] === true, 'B2B-nyilatkozat naplózva');
$u = get_user_by('email', $vevo['email']);
T::igaz($u instanceof WP_User && in_array('sdh_vevo', $u->roles, true), 'vevői fiók (sdh_vevo)');
T::egyezik((int) $u->ID, $r['r']['user_id'], 'rendelés a fiókhoz kötve');
$l = t_levelek($vevo['email']);
T::igaz(count($l) === 1, 'díjbekérő levél ment');
T::tartalmaz((string) ($l[0]['message'] ?? ''), $r['kozlemeny'], 'levélben a közlemény');
T::igaz(count((array) ($l[0]['attachments'] ?? [])) >= 1, 'QR-kép csatolva');
$egy = []; for ($i = 0; $i < 30; $i++) { $egy[] = t_rendeles()['kozlemeny']; }
T::egyezik(30, count(array_unique($egy)), '30 rendelés: 30 különböző közlemény');

// Létrehozás hookja
$hiv = 0; add_action('sdh_sales_rendeles_letrehozva', static function () use (&$hiv) { $hiv++; });
t_rendeles();
T::egyezik(1, $hiv, 'sdh_sales_rendeles_letrehozva egyszer');

// REST: robotszűrés, 201, nincs belső id
$be = ['tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start']], 'opciok' => ['ciklus' => 'havi'], 'vevo' => t_vevo(), 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => true, 'aszf' => true, 'eula' => true]];
$v = t_latogato(static fn () => t_rest('POST', '/rendeles', $be));
T::egyezik(400, $v->get_status(), 'REST: robotszűrő mezők nélkül 400');
$v = t_latogato(static fn () => t_rest('POST', '/rendeles', $be + t_vedelem()));
T::egyezik(201, $v->get_status(), 'REST: 201');
T::igaz(!isset($v->get_data()['id']) && isset($v->get_data()['statusz_url']), 'REST: nincs belső id, van állapot-link');
$v = t_latogato(static fn () => t_rest('POST', '/rendeles', ['vevo' => t_vevo(['adoszam' => $rossz])] + $be + t_vedelem()));
T::igaz($v->get_status() === 400 && isset($v->get_data()['hibak']['adoszam']), 'REST: hibás adószám mezőhibával');
$v = t_latogato(static fn () => t_rest('POST', '/rendeles', ['weboldal_cim' => 'http://spam'] + $be + t_vedelem()));
T::egyezik(400, $v->get_status(), 'REST: mézesbödön');

// Szolgáltatás-rendelés: EULA nem kell
$e = SDH_Sales_Rendeles::letrehoz(['tetelek' => [['tipus' => 'szolgaltatas', 'kulcs' => 'oktatas', 'db' => 2]], 'vevo' => t_vevo(), 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1]]);
T::igaz(!empty($e['ok']), 'szolgáltatás EULA nélkül');
T::egyezik('szolgaltatas', SDH_Sales_Rendeles::rendeles((int) $e['id'])['tipus'], 'típus: szolgáltatás');

// Állapotoldal
$v = t_latogato(static fn () => t_rest('GET', '/rendeles/' . $r['azon'] . '/allapot', ['t' => $r['token']]));
T::egyezik(200, $v->get_status(), 'állapot: jó tokennel 200');
T::egyezik($r['kozlemeny'], $v->get_data()['atutalas']['kozlemeny'] ?? '', 'állapot: átutalási adatok');
T::tartalmaz((string) ($v->get_data()['atutalas']['qr_svg'] ?? ''), '<svg', 'állapot: QR SVG');
$v = t_latogato(static fn () => t_rest('GET', '/rendeles/' . $r['azon'] . '/allapot', ['t' => str_repeat('a', 32)]));
T::egyezik(404, $v->get_status(), 'állapot: rossz tokennel 404');
$v = t_latogato(static fn () => t_rest('GET', '/rendeles/' . $r['azon'] . '/qr', ['t' => $r['token']]));
T::igaz($v->get_status() === 200 && strncmp((string) $v->get_data(), "\x89PNG", 4) === 0 && SDH_Sales_Rendeles::nyers_e($v), 'QR PNG végpont');


// Független ellenőrzés (2026-10-20), K-1: a 0 Ft-os (nem kifejezetten ingyenes) kosár ajánlatkérés – nincs „fizetve”,
// nincs kiszolgálás, nincs vevői fiók; a 100%-os kupon és a katalógusban `ingyenes: true` tétel továbbra is kiszolgálható.
global $wpdb;
$GLOBALS['sdh_t_levelek'] = [];
$GLOBALS['t_fizetve']     = [];
$em = 'ingyen' . wp_rand(1000, 99999) . '@example.com';
$v  = t_latogato(static fn () => t_rest('POST', '/rendeles', t_vedelem() + ['tipus' => 'szolgaltatas', 'tetelek' => [['tipus' => 'szolgaltatas', 'kulcs' => 'ingyen_s']], 'opciok' => ['ciklus' => 'havi'], 'vevo' => t_vevo(['email' => $em]), 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1]]));
$az = (string) ($v->get_data()['azon'] ?? '');
$ra = SDH_Sales_Rendeles::azon_szerint($az);
T::egyezik('ajanlat', (string) ($v->get_data()['allapot'] ?? ''), 'K-1: 0 Ft-os szolgáltatás: ajánlatkérés (nem „fizetve”)');
T::igaz($ra !== null && (string) $ra['allapot'] === 'ajanlat' && (string) $ra['tipus'] === 'ajanlat', 'K-1: a rendelés állapota / típusa ajánlatkérés');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('fizetes') . ' WHERE rendeles_id = %d', (int) ($ra['id'] ?? 0))), 'K-1: nincs „ingyenes” befizetés-sor');
T::igaz(empty($GLOBALS['t_fizetve'][(int) ($ra['id'] ?? 0)]), 'K-1: nincs fizetve-hook / kiszolgálás');
T::igaz(!get_user_by('email', $em), 'K-1: nem jön létre vevői fiók');
T::egyezik([], t_levelek($em), 'K-1: a vevő nem kap „Megérkezett a fizetés” levelet');
T::igaz((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('lead') . " WHERE forras = 'ajanlat' AND email = %s", $em)) === 1, 'K-1: érdeklődésként (lead) rögzítve');
T::igaz(count(t_levelek('norbi@example.com')) === 1, 'K-1: a forgalmazó levelet kap az ajánlatkérésről');
SDH_Sales_Tartalom::ment('szolgaltatas', 'ingyen_ok', ['kulcs' => 'ingyen_ok', 'nev' => 'Ingyenes bevezető', 'tipus' => 'egyszeri', 'ar' => 0, 'ingyenes' => true], null, 98, true, 'hu', false);
SDH_Sales_Tartalom::urit();
$ik = t_rendeles(['tipus' => 'szolgaltatas', 'tetelek' => [['tipus' => 'szolgaltatas', 'kulcs' => 'ingyen_ok']], 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1]]);
T::egyezik('fizetve', (string) ($ik['allapot'] ?? ''), 'K-1: kifejezetten ingyenes (`ingyenes: true`) tétel: kiszolgálható');

T::vege('t212 rendelés');
