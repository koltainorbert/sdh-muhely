<?php
/**
 * t74 – H8 (0.82.0): ügyfél anonimizálása (GDPR).
 *
 * - Mi törlődik (név, elérhetőség, címek, megjegyzés, kereső, RMA-link, zárkód, SMS-szám, mezőmemória,
 *   választhatóan az üzenetek szövege) és mi marad (munkalap, számla, eszköz, ügyfélszám, MunkaLap-kulcs).
 * - A Változásnapló nem kapja meg újra a törölt adatokat; egy esemény kerül bele.
 * - Visszaigazoló e-mail, igazoló sor az ügyfél lapján; jog, kötelező mezők, kétszer nem futhat.
 */

require __DIR__ . '/_keret.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;

$A     = 'SDH_Muhely_Anonim';
$admin = get_current_user_id();
$T     = static fn (string $t): string => SDH_Muhely_Schema::tabla($t);

T::igaz(version_compare(SDH_Muhely_Schema::DB_VERSION, '0.45.0', '>='), 'sémaverzió 0.45.0');
T::igaz(in_array('anonimizalva', (array) $wpdb->get_col('SHOW COLUMNS FROM ' . $T('ugyfel')), true) || in_array('anonimizalva', array_column((array) $wpdb->get_results('PRAGMA table_info(' . $T('ugyfel') . ')', ARRAY_A), 'name'), true), 'új oszlop: ugyfel.anonimizalva');

/* --- Előkészítés: egy ügyfél minden kapcsolódó adattal ------------------------------------------- */

$telefon = '+36301234' . wp_rand(100, 999);
$email   = 'gdpr' . wp_rand(1000, 99999) . '@example.com';
$nev     = 'Gdpr Géza ' . wp_generate_password(4, false);
$u       = t_ugyfel($nev, [
    'nev' => $nev, 'telefon' => $telefon, 'telefon2' => '+36201112233', 'email' => $email, 'kapcsolattarto' => 'Gdpr Gizella',
    'levelezesi_cim' => 'Titkos utca 7.', 'megjegyzes' => 'A kapukód 1234', 'belso_megjegyzes' => 'Mindig késik', 'kulso_azonosito' => 'ML3-T74',
    'ugyfel_szam' => 'U-T74' . wp_rand(100, 999),
]);
SDH_Muhely_Ugyfel::kereso_frissit($u);
$e  = t_eszkoz($u, 'Samsung', 'SM-T74');
$wpdb->update($T('eszkoz'), ['zarkod' => SDH_Muhely_Zarkod::tarol('4321'), 'minta' => SDH_Muhely_Zarkod::tarol('1-2-3')], ['id' => $e]);
T::igaz((string) $wpdb->get_var($wpdb->prepare('SELECT zarkod FROM ' . $T('eszkoz') . ' WHERE id = %d', $e)) !== '', 'előtte: van feloldó kód');
$ml = t_munkalap($u, [$e]);
$wpdb->update($T('munkalap'), ['rma_token' => 'tokenT74' . wp_rand(1000, 9999)], ['id' => $ml]);
$wpdb->insert($T('uzenet'), ['munkalap_id' => $ml, 'ugyfel_id' => $u, 'irany' => 'be', 'szoveg' => 'Itt Géza, hívjanak a ' . $telefon . ' számon', 'letrehozva' => current_time('mysql')]);
$wpdb->insert($T('sms_uzenet'), ['munkalap_id' => $ml, 'ugyfel_id' => $u, 'szam' => $telefon, 'szam_eredeti' => $telefon, 'szoveg' => 'Kész a telefonja, Géza', 'kovetes_token' => 'kovT74', 'letrehozva' => current_time('mysql')]);
$wpdb->insert($T('mezo_emlek'), ['kulcs' => 'ugyfel.nev', 'lenyomat' => md5('t74' . $nev . wp_rand()), 'ertek' => $nev, 'utolso' => current_time('mysql')]);
$wpdb->insert($T('mezo_emlek'), ['kulcs' => 'munkalap.megjegyzes', 'lenyomat' => md5('t74masik' . wp_rand()), 'ertek' => 'Nem személyes T74', 'utolso' => current_time('mysql')]);

T::igaz((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('ugyfel_szo') . ' WHERE ugyfel_id = %d', $u)) > 0, 'előtte: van keresőszava');
$k = $A::kapcsolodo($u);
T::egyezik(1, $k['munkalap'], 'kapcsolódó: 1 munkalap');
T::egyezik(1, $k['uzenet'], 'kapcsolódó: 1 RMA-üzenet');
T::egyezik(1, $k['sms'], 'kapcsolódó: 1 SMS');
T::egyezik('***' . substr($telefon, -2), $A::maszk($telefon), 'maszk: csak az utolsó két számjegy');

/* --- Felület és jog ----------------------------------------------------------------------------- */

$ugyfel = $A::ugyfel($u);
T::egyezik(true, $A::jogos(), 'az adminisztrátor jogosult');
T::tartalmaz($A::gomb($ugyfel), 'data-sdh-urlap="anonim"', 'az ügyfél űrlapján Anonimizálás gomb');
T::egyezik('', $A::sav($ugyfel), 'még nincs igazoló sáv');

$p = t_ajax('sdh_muhely_anonim_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $u]);
T::tartalmaz($p['nyers'], 'data-sdh-ajax-action="sdh_muhely_anonim_vegrehajt"', 'popup: a megerősítő űrlap');
T::tartalmaz($p['nyers'], 'name="keres"', 'popup: a kérés mezője (kötelező)');
T::tartalmaz($p['nyers'], 'name="ertem"', 'popup: „Megértettem” jelölő');
T::tartalmaz($p['nyers'], esc_html($email), 'popup: visszaigazolás az ügyfél e-mail címére');
T::tartalmaz($p['nyers'], 'Megmarad', 'popup: mi marad meg');

$kolleg = (int) wp_insert_user(['user_login' => 'kolleg_t74', 'user_pass' => 'Jelszo-T74-ok!', 'role' => 'sdh_kollega', 'display_name' => 'Kolléga T74']);
wp_set_current_user($kolleg);
T::egyezik('', $A::gomb($ugyfel), 'kollégának nincs gomb');
$v = t_ajax('sdh_muhely_anonim_vegrehajt', ['id' => $u, 'keres' => 'x', 'ertem' => '1'], 'sdh_nonce', $A::NONCE);
T::igaz(empty($v['success']), 'kolléga nem anonimizálhat');
wp_set_current_user($admin);
wp_delete_user($kolleg);

$v = t_ajax('sdh_muhely_anonim_vegrehajt', ['id' => $u, 'keres' => 'e-mailben, 2026. 10. 16.'], 'sdh_nonce', $A::NONCE);
T::igaz(empty($v['success']), '„Megértettem” nélkül nem fut');
$v = t_ajax('sdh_muhely_anonim_vegrehajt', ['id' => $u, 'keres' => '  ', 'ertem' => '1'], 'sdh_nonce', $A::NONCE);
T::igaz(empty($v['success']), 'a kérés leírása nélkül nem fut');
T::egyezik($nev, (string) $A::ugyfel($u)->nev, 'az elutasított kérés után minden a régi');

/* --- Végrehajtás ------------------------------------------------------------------------------------ */

$level = [];
add_filter('pre_wp_mail', static function ($null, $atts) use (&$level) {
    $level[] = $atts;

    return true;
}, 10, 2);

$naplo_elotte = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . $T('valtozasnaplo'));
$v = t_ajax('sdh_muhely_anonim_vegrehajt', ['id' => $u, 'keres' => 'e-mailben, 2026. 10. 16.', 'ertem' => '1', 'uzenetek' => '1', 'visszaigazolas' => '1', 'kontextus' => 'frontend'], 'sdh_nonce', $A::NONCE);
T::igaz(!empty($v['success']), 'anonimizálva');
T::tartalmaz((string) ($v['data']['vissza'] ?? ''), 'uzenet=anonimizalva', 'vissza az ügyféllistára, üzenettel');
T::egyezik(true, (bool) ($v['data']['email'] ?? false), 'visszaigazolás elküldve');
T::egyezik(1, count($level), 'egy levél ment ki');
T::egyezik($email, (string) ($level[0]['to'] ?? ''), 'a régi e-mail címre');
T::tartalmaz((string) ($level[0]['message'] ?? ''), 'töröltük a személyes adatait', 'a levél szövege');
T::tartalmaz((string) ($level[0]['message'] ?? ''), (string) SDH_Muhely_Rma::beallitas()['szerviz_nev'], 'aláírás: a beállított szerviznév (nincs a kódban)');

$a = $A::ugyfel($u);
T::tartalmaz((string) $a->nev, 'Anonimizált ügyfél', 'a név helyén: Anonimizált ügyfél');
T::tartalmaz((string) $a->nev, (string) $ugyfel->ugyfel_szam, 'az ügyfélszám marad (a hivatkozásokhoz)');
foreach (['telefon', 'telefon2', 'email', 'kapcsolattarto', 'szamlazasi_cim', 'szamlazasi_telepules', 'levelezesi_cim', 'tel_ford'] as $m) {
    T::egyezik('', (string) $a->{$m}, 'törölve: ' . $m);
}
T::igaz(empty($a->megjegyzes), 'törölve: megjegyzés');
T::nem_tartalmaz((string) $a->belso_megjegyzes, 'késik', 'a belső megjegyzés helyén az igazolás');
T::tartalmaz((string) $a->belso_megjegyzes, 'kérés: e-mailben, 2026. 10. 16.', 'az igazolásban a kérés');
T::tartalmaz((string) $a->belso_megjegyzes, 'visszaigazolás e-mailben elküldve', 'és a visszaigazolás');
T::egyezik('ML3-T74', (string) $a->kulso_azonosito, 'a MunkaLap 3 kulcsa marad (újraimport nem hozza vissza)');
T::egyezik(0, (int) $a->aktiv, 'inaktív');
T::egyezik(1, (int) $a->sms_tilt, 'SMS tiltva');
T::igaz(!empty($a->anonimizalva), 'anonimizálás ideje rögzítve');
T::nem_tartalmaz((string) $a->kereso, 'geza', 'a keresőszöveg csak az új (anonim) nevet tartalmazza');
T::nem_tartalmaz(implode(' ', (array) $wpdb->get_col($wpdb->prepare('SELECT szo FROM ' . $T('ugyfel_szo') . ' WHERE ugyfel_id = %d', $u))), 'geza', 'a régi keresőszavak törölve');
T::igaz(!in_array($u, array_map('intval', array_column((array) SDH_Muhely_Ugyfel::kereses(substr($telefon, -7), 10), 'id')), true), 'a régi telefonszámra nem található');

T::egyezik('', (string) $wpdb->get_var($wpdb->prepare('SELECT rma_token FROM ' . $T('munkalap') . ' WHERE id = %d', $ml)), 'az ügyféloldal linkje megszűnt');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('munkalap') . ' WHERE id = %d AND ugyfel_id = %d', $ml, $u)), 'a munkalap megmaradt, az ügyfélhez kötve');
T::egyezik('', (string) $wpdb->get_var($wpdb->prepare('SELECT zarkod FROM ' . $T('eszkoz') . ' WHERE id = %d', $e)), 'a feloldó kód törölve');
T::egyezik('', (string) $wpdb->get_var($wpdb->prepare('SELECT minta FROM ' . $T('eszkoz') . ' WHERE id = %d', $e)), 'a minta törölve');
T::egyezik('SM-T74', (string) $wpdb->get_var($wpdb->prepare('SELECT tipus FROM ' . $T('eszkoz') . ' WHERE id = %d', $e)), 'az eszköz (típus, IMEI) megmarad');
T::egyezik('(anonimizálva)', (string) $wpdb->get_var($wpdb->prepare('SELECT szoveg FROM ' . $T('uzenet') . ' WHERE ugyfel_id = %d', $u)), 'az RMA-üzenet szövege törölve');
$sms = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('sms_uzenet') . ' WHERE ugyfel_id = %d', $u));
T::egyezik('***' . substr($telefon, -2), (string) $sms->szam, 'az SMS száma maszkolva');
T::egyezik('***' . substr($telefon, -2), (string) $sms->szam_eredeti, 'az eredeti szám is');
T::egyezik('(anonimizálva)', (string) $sms->szoveg, 'az SMS szövege törölve');
T::egyezik('', (string) $sms->kovetes_token, 'az SMS követő linkje megszűnt');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('mezo_emlek') . ' WHERE ertek = %s', $nev)), 'a mezőmemóriából a neve kiment');
T::igaz((int) $wpdb->get_var("SELECT COUNT(*) FROM " . $T('mezo_emlek') . " WHERE ertek = 'Nem személyes T74'") >= 1, 'a többi emlék marad');

// A napló: egy esemény, és a törölt adatok nem kerültek bele újra.
$uj_naplo = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $T('valtozasnaplo') . ' WHERE id > %d', $naplo_elotte));
$osszes   = wp_json_encode($uj_naplo, JSON_UNESCAPED_UNICODE);
T::igaz(count($uj_naplo) >= 1, 'a naplóban van bejegyzés');
T::tartalmaz((string) $osszes, 'anonimizálva (GDPR)', 'a napló eseménye');
T::nem_tartalmaz((string) $osszes, substr($telefon, 3), 'a naplóba nem került be a telefonszám');
T::nem_tartalmaz((string) $osszes, $email, 'sem az e-mail cím');
T::nem_tartalmaz((string) $osszes, 'Titkos utca', 'sem a cím');
T::nem_tartalmaz((string) $osszes, explode(' ', $nev)[2] ?? 'xx', 'sem a név');

T::tartalmaz($A::sav($a), 'Anonimizálva (GDPR)', 'az ügyfél lapján az igazoló sáv');
T::egyezik('', $A::gomb($a), 'anonimizált ügyfélnél nincs gomb');
$r = $A::vegrehajt($u, ['keres' => 'újra']);
T::egyezik(false, $r['ok'], 'kétszer nem futhat');

// E-mail nélkül: nincs levél, az igazolás így is megmarad.
$level = [];
$u2 = t_ugyfel('Email Nelkul T74', ['email' => '']);
$r  = $A::vegrehajt($u2, ['keres' => 'személyesen', 'visszaigazolas' => true]);
T::egyezik(true, $r['ok'] && !$r['email'], 'e-mail cím nélkül: anonimizálva, levél nélkül');
T::egyezik(0, count($level), 'nem ment ki levél');
T::nem_tartalmaz((string) $A::ugyfel($u2)->belso_megjegyzes, 'elküldve', 'az igazolás szerint nem ment visszaigazolás');

// Üzenetek nélkül: az RMA-üzenet szövege megmarad, az SMS száma akkor is maszkolva.
$u3 = t_ugyfel('Uzenet Marad T74');
$wpdb->insert($T('uzenet'), ['ugyfel_id' => $u3, 'irany' => 'ki', 'szoveg' => 'Ajánlat: 20 000 Ft', 'letrehozva' => current_time('mysql')]);
$wpdb->insert($T('sms_uzenet'), ['ugyfel_id' => $u3, 'szam' => '+36309998877', 'szoveg' => 'Ajánlat T74', 'letrehozva' => current_time('mysql')]);
$A::vegrehajt($u3, ['keres' => 'telefonon', 'uzenetek' => false]);
T::egyezik('Ajánlat: 20 000 Ft', (string) $wpdb->get_var($wpdb->prepare('SELECT szoveg FROM ' . $T('uzenet') . ' WHERE ugyfel_id = %d', $u3)), 'üzenetek nélkül: a szöveg marad');
T::egyezik('***77', (string) $wpdb->get_var($wpdb->prepare('SELECT szam FROM ' . $T('sms_uzenet') . ' WHERE ugyfel_id = %d', $u3)), 'az SMS száma akkor is maszkolva');

// Az ügyfélűrlap: sáv, és nincs gomb.
$html = t_ajax('sdh_muhely_ugyfelek_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $u])['nyers'];
T::tartalmaz($html, 'sdh-anonim-sav', 'az ügyfél popupjában az igazoló sáv');
T::nem_tartalmaz($html, 'data-sdh-urlap="anonim"', 'és nincs Anonimizálás gomb');
$html = t_ajax('sdh_muhely_ugyfelek_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => t_ugyfel('Gomb Van T74')])['nyers'];
T::tartalmaz($html, 'data-sdh-urlap="anonim"', 'normál ügyfélnél a gomb ott van');

T::vege('t74 GDPR anonimizálás (H8)');
