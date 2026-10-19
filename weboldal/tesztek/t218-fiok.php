<?php
/** t218 – fiók: e-mailes belépő link (lenyomat, 30 perc, egyszer használható, nincs felderítés, korlát), fiók adatai, megújítás / bővítés jogosultság. */
require __DIR__ . '/_t210-seged.php';
t_katalogus_betolt();
global $wpdb;

$v  = t_vevo();
$r  = t_latogato(static fn () => t_rendeles(['vevo' => $v]));
$u  = get_user_by('email', $v['email']);
$ve = t_vedelem();

// Link kérése: ismert és ismeretlen címre ugyanaz a válasz
$GLOBALS['sdh_t_levelek'] = [];
$a = t_latogato(static fn () => t_rest('POST', '/fiok/belepes', ['email' => $v['email']] + $ve));
$b = t_latogato(static fn () => t_rest('POST', '/fiok/belepes', ['email' => 'nincs-ilyen@example.com'] + $ve));
T::egyezik($a->get_status(), $b->get_status(), 'ugyanaz a státusz');
T::egyezik($a->get_data(), $b->get_data(), 'ugyanaz a válasz (nincs felhasználó-felderítés)');
T::egyezik(1, count($GLOBALS['sdh_t_levelek']), 'csak az ismert címre ment levél');
$level = (string) $GLOBALS['sdh_t_levelek'][0]['message'];
T::igaz(preg_match('/belepes=([A-Za-z0-9_-]{30,60})/', $level, $m) === 1, 'link a levélben');
$token = $m[1];

// Csak lenyomat van tárolva
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('fiok_link') . ' WHERE token_hash = %s', $token)), 'nyers token nincs az adatbázisban');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('fiok_link') . ' WHERE token_hash = %s', hash('sha256', $token))), 'lenyomat tárolva');

// Beváltás egyszer
T::egyezik((int) $u->ID, SDH_Sales_Fiok::belep($token), 'első beváltás: belép');
T::egyezik(0, SDH_Sales_Fiok::belep($token), 'második beváltás: elutasítva (egyszer használható)');

// Lejárat
$t2 = SDH_Sales_Fiok::link_letrehoz((int) $u->ID, $v['email']);
$wpdb->update(SDH_Sales_Schema::t('fiok_link'), ['lejar' => gmdate('Y-m-d H:i:s', time() - 60)], ['token_hash' => hash('sha256', $t2)]);
T::egyezik(0, SDH_Sales_Fiok::belep($t2), 'lejárt link elutasítva');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('fiok_link') . ' WHERE token_hash = %s', hash('sha256', $t2))), 'lejárt link is törlődött');
$t3 = SDH_Sales_Fiok::link_letrehoz((int) $u->ID, $v['email']);
$lejar = (string) $wpdb->get_var($wpdb->prepare('SELECT lejar FROM ' . SDH_Sales_Schema::t('fiok_link') . ' WHERE token_hash = %s', hash('sha256', $t3)));
$perc = (strtotime($lejar . ' UTC') - time()) / 60;
T::igaz($perc > 29 && $perc <= 30.1, '30 perces érvényesség');
T::egyezik(0, SDH_Sales_Fiok::belep('rovid'), 'formátumhibás token');

// REST belépés
$v1 = t_latogato(static fn () => t_rest('POST', '/fiok/belep', ['token' => $t3]));
T::igaz($v1->get_status() === 200 && !empty($v1->get_data()['nonce']), 'REST belépés: 200 + nonce');
T::egyezik(401, t_latogato(static fn () => t_rest('POST', '/fiok/belep', ['token' => $t3]))->get_status(), 'REST: másodszor 401');

// Admin nem léphet be e-mailes linkkel
$adm = wp_get_current_user();
$GLOBALS['sdh_t_levelek'] = [];
T::igaz(!SDH_Sales_Fiok::link_kuld((string) $adm->user_email), 'adminnak nincs e-mailes belépés');

// Korlát: IP-nként 5 / 15 perc
t_korlat_ki(false);
$st = [];
for ($i = 0; $i < 7; $i++) { $st[] = t_latogato(static fn () => t_rest('POST', '/fiok/belepes', ['email' => 'x' . $i . '@example.com'] + $ve))->get_status(); }
T::egyezik([200, 200, 200, 200, 200, 429, 429], $st, 'belépő link: 5 kérés után 429');
t_korlat_ki();

// Fiók adatai
wp_set_current_user((int) $u->ID);
$f = t_rest('GET', '/fiok');
T::egyezik(200, $f->get_status(), 'GET /fiok 200');
T::egyezik($r['azon'], $f->get_data()['rendelesek'][0]['azon'] ?? '', 'fiók: rendelés');
T::igaz(array_key_exists('licencek', $f->get_data()) && array_key_exists('oktatas', $f->get_data()) && array_key_exists('egyedi', $f->get_data()), 'fiók: szakaszok');

// Licenc a fiókban + megújítás / bővítés
$wpdb->update(SDH_Sales_Schema::t('rendeles'), ['licenc_kulcs' => 'MUNKORA-AAAA-BBBB-CCCC'], ['id' => (int) $r['id']]);
SDH_Sales_Rendeles::fizetve((int) $r['id'], 'kezi', 't218-1', (int) $r['r']['brutto']);
$f = t_rest('GET', '/fiok')->get_data();
T::egyezik('MUNKORA-AAAA-BBBB-CCCC', $f['licencek'][0]['kulcs'] ?? '', 'fiók: licenc');
T::egyezik('Start', $f['licencek'][0]['csomag'] ?? '', 'fiók: licenc csomagja');

$mu = SDH_Sales_Rendeles::letrehoz(['tipus' => 'megujitas', 'licenc_kulcs' => 'MUNKORA-AAAA-BBBB-CCCC', 'opciok' => ['ciklus' => 'eves'], 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]]);
T::igaz(!empty($mu['ok']), 'megújítás saját licencre');
$mr = SDH_Sales_Rendeles::rendeles((int) $mu['id']);
T::igaz($mr['tipus'] === 'megujitas' && $mr['licenc_kulcs'] === 'MUNKORA-AAAA-BBBB-CCCC' && $mr['netto'] === 100980, 'megújítás: típus, kulcs, éves ár');
T::egyezik('Próba Szerviz Kft.', $mr['vevo']['ceg'], 'megújítás: számlázási adatok az eredetiből');

$bo = SDH_Sales_Rendeles::letrehoz(['tipus' => 'bovites', 'licenc_kulcs' => 'MUNKORA-AAAA-BBBB-CCCC', 'tetelek' => [['tipus' => 'modul', 'kulcs' => 'asszisztens']], 'opciok' => ['felhasznalo' => 3], 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]]);
T::igaz(!empty($bo['ok']), 'bővítés saját licencre');
$br = SDH_Sales_Rendeles::rendeles((int) $bo['id']);
$tip = array_column($br['tetelek'], 'tipus'); sort($tip);
T::egyezik(['felhasznalo', 'modul'], $tip, 'bővítés: csak az új modul és az extra felhasználó');
T::egyezik(3990 + 1900, $br['netto'], 'bővítés: havi ciklus az eredeti licencből');

// Más vevő licence: elutasítva
$masik = t_vevo();
t_latogato(static fn () => t_rendeles(['vevo' => $masik]));
wp_set_current_user((int) get_user_by('email', $masik['email'])->ID);
$e = SDH_Sales_Rendeles::letrehoz(['tipus' => 'megujitas', 'licenc_kulcs' => 'MUNKORA-AAAA-BBBB-CCCC', 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]]);
T::igaz(empty($e['ok']) && isset($e['hibak']['licenc_kulcs']), 'más vevő licence: elutasítva');
wp_set_current_user(0);
$e = SDH_Sales_Rendeles::letrehoz(['tipus' => 'bovites', 'licenc_kulcs' => 'MUNKORA-AAAA-BBBB-CCCC', 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]]);
T::igaz(empty($e['ok']), 'kijelentkezve: elutasítva');
T::egyezik(401, t_rest('GET', '/fiok')->get_status(), 'GET /fiok kijelentkezve 401');

T::vege('t218 fiók');
