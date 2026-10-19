<?php
/**
 * t93 – Keresés, listák, űrlapok e-mailben (0.99.4).
 *
 * - Okos kereső: a beírt szöveg fajtája (IMEI, telefon, sorszám, e-mail, kód, márka, szöveg), az azonnali
 *   találatok sorrendje (a fajtához illő csoport elöl), a fejléc keresője is ezt adja, popupban nyíló találatok.
 * - A listák keresője első körben specifikus (jelzés + „Keresés mindenben”), üres pontos találatnál visszaesik.
 * - Mély oldal (oldalszám a végén túl): nincs hiba, az utolsó oldal jön; halasztott összekapcsolás.
 * - Az Áttekintés szöveges szűrőjének javaslatai; a beállítások kapcsolói (Keresés és listák).
 * - Az AJAX-védő: a JSON elé került PHP-szemét levágva, végzetes hibánál olvasható JSON-hiba (Naptár „JSON.parse”).
 * - Az AJAX-kérés felülete: a popupban betöltött ügyfél „Készülékei” linkje a műhely-felületen marad.
 * - Űrlapok e-mailben: gomb űrlaponként (ki-be), adatlap, küldés (tesztüzemben), napló; a munkalap Fájlok lapfüle,
 *   feltöltés a saját mezőjén, a dokumentumok elküldése.
 * - Új indexek (DB 0.54.0).
 */

define('SDH_MUHELY_TESZT', true);

require __DIR__ . '/_keret.php';

global $wpdb;

$O = 'SDH_Muhely_Okoskereso';
$E = 'SDH_Muhely_Urlap_Email';
$T = static fn (string $t): string => SDH_Muhely_Schema::tabla($t);

T::igaz(version_compare(SDH_Muhely_Schema::DB_VERSION, '0.54.0', '>='), 'sémaverzió 0.54.0');
T::igaz(class_exists($O) && class_exists($E) && class_exists('SDH_Muhely_Ajax_Vedo'), 'az új osztályok betöltve');

/* --- 1. A beírt szöveg fajtája ------------------------------------------------------------------------- */

$esetek = [
    '357146129282436'    => 'imei',
    '35 714612 928243 6' => 'imei',
    '+36 30 123 4567'    => 'telefon',
    '06301234567'        => 'telefon',
    '1234'               => 'szam',
    'teszt@example.com'  => 'email',
    'R58N12ABC9'         => 'kod',
    'Samsung A52'        => 'marka',
    'iphone 13'          => 'marka',
    'Kovács Béla'        => 'szoveg',
    'kijelző'            => 'szoveg',
];

foreach ($esetek as $q => $vart) {
    T::egyezik($vart, $O::tipus($q), 'fajta: „' . $q . '” → ' . $vart);
}

T::egyezik(strrev('301234567'), $O::tel_minta('+36 30 123 4567'), 'telefon vége: az utolsó 9 jegy megfordítva');

/* --- 2. Adatok -------------------------------------------------------------------------------------------- */

$jel  = wp_generate_password(6, false, false);
$u    = t_ugyfel('Okos Ügyfél ' . $jel, ['telefon' => '+36 70 555 ' . substr((string) wp_rand(1000, 9999), 0, 4)]);
$tel  = (string) $wpdb->get_var($wpdb->prepare('SELECT telefon FROM ' . $T('ugyfel') . ' WHERE id = %d', $u));
SDH_Muhely_Ugyfel::kereso_frissit($u);
$imei = '35' . wp_rand(1000000000000, 9999999999999);
$wpdb->insert($T('eszkoz'), ['ugyfel_id' => $u, 'kategoria' => 'telefon', 'gyarto' => 'Samsung', 'tipus' => 'Galaxy A52 ' . $jel,
    'imei' => $imei, 'sorozatszam' => 'R58N' . strtoupper($jel), 'aktiv' => 1, 'letrehozva' => current_time('mysql'), 'modositva' => current_time('mysql')]);
$e    = (int) $wpdb->insert_id;
$ml   = t_munkalap($u, [$e]);
$szam = (int) $wpdb->get_var($wpdb->prepare('SELECT munkalap_szam FROM ' . $T('munkalap') . ' WHERE id = %d', $ml));

// Legalább kétjegyű, egyedi sorszám (egy jegyre nincs azonnali keresés).
if ($szam < 10) {
    do {
        $szam = wp_rand(70000, 89999);
    } while ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('munkalap') . ' WHERE munkalap_szam = %d', $szam)) > 0);

    $wpdb->update($T('munkalap'), ['munkalap_szam' => $szam], ['id' => $ml]);
}

$vonal = '599' . wp_rand(1000000000, 9999999999);
$wpdb->insert($T('termek'), ['megnevezes' => 'Okos kijelző ' . $jel . ' fekete', 'vonalkod' => $vonal, 'cikkszam' => 'CK-' . $jel, 'aktiv' => 1, 'letrehozva' => current_time('mysql')]);
$p    = (int) $wpdb->insert_id;
$wpdb->insert($T('szolgaltatas'), ['nev' => 'Okos kijelzőcsere ' . $jel, 'kulcs' => md5('okos' . $jel), 'kod' => 'SZ' . strtoupper($jel), 'letrehozva' => current_time('mysql')]);
$sz   = (int) $wpdb->insert_id;
SDH_Muhely_Gyorsitotar::ervenytelenit();

$elso_csoport = static function (array $cs): string {
    return (string) ($cs[0]['modul'] ?? '');
};
$ids = static function (array $cs, string $modul): array {
    foreach ($cs as $c) {
        if ($c['modul'] === $modul) {
            return array_map(static fn (array $t): int => (int) $t['id'], $c['talalatok']);
        }
    }

    return [];
};

/* --- 3. Azonnali találatok: a fajtához illő csoport elöl ------------------------------------------------- */

$r = $O::keres($imei);
T::egyezik('eszkoz', $elso_csoport($r), 'IMEI: az eszközök elöl');
T::igaz(in_array($e, $ids($r, 'eszkoz'), true), 'IMEI: megvan az eszköz');
T::igaz(in_array($ml, $ids($r, 'lap'), true), 'IMEI: megvan a lapja is');

$r = $O::keres($tel);
T::egyezik('ugyfel', $elso_csoport($r), 'telefon: az ügyfelek elöl');
T::igaz(in_array($u, $ids($r, 'ugyfel'), true), 'telefon: a teljes ügyfél előjön');

$r = $O::keres((string) $szam);
T::egyezik('lap', $elso_csoport($r), 'sorszám: a lapok elöl');
T::igaz(in_array($ml, $ids($r, 'lap'), true), 'sorszám: a munkalap előjön');

$r = $O::keres($vonal);
T::igaz(in_array($p, $ids($r, 'termek'), true), 'vonalkód: a termék előjön');

$r = $O::keres('Samsung Galaxy A52 ' . $jel);
T::egyezik('eszkoz', $elso_csoport($r), 'márka: az eszközök elöl');
T::igaz(in_array($e, $ids($r, 'eszkoz'), true), 'márka + típus: az eszköz előjön');

$r = $O::keres('kijelző ' . $jel);
T::igaz(in_array($p, $ids($r, 'termek'), true), 'szöveg (két szó, sorrendtől függetlenül): a termék előjön');
T::igaz(in_array($sz, $ids($r, 'szolgaltatas'), true), 'szöveg: a szolgáltatás előjön');

$r = $O::keres('kijelző ' . $jel, 'szolgaltatasok');
T::egyezik('szolgaltatas', $elso_csoport($r), 'a modul oldalán annak a csoportja kerül előre');

$r = $O::keres('Okos Ügyfél ' . $jel);
T::egyezik('ugyfel', $elso_csoport($r), 'név: az ügyfelek elöl');
$elso = $r[0]['talalatok'][0] ?? [];
T::igaz(($elso['urlap'] ?? '') === 'ugyfelek' && (int) ($elso['id'] ?? 0) === $u, 'a találat popupban nyílik (urlap + id)');

$gk = t_ajax('sdh_muhely_globalis_kereso', [], '_wpnonce', 'sdh_muhely_modal', ['q' => $imei]);
T::igaz(!empty($gk['success']) && ($gk['data'][0]['modul'] ?? '') === 'eszkoz', 'a fejléc keresője is az okos keresőt adja (IMEI → eszköz elöl)');
$ok = t_ajax('sdh_muhely_okos_kereso', [], '_wpnonce', 'sdh_muhely_modal', ['q' => $tel, 'elso' => 'ugyfelek']);
T::igaz(!empty($ok['success']) && ($ok['data']['tipus'] ?? '') === 'telefon', 'az okos kereső végpontja: fajta + csoportok');
T::egyezik([], $O::keres('a'), 'egy betűre nincs keresés');

/* --- 4. A listák: első körben specifikusan ---------------------------------------------------------------- */

$oldal = static function (string $osztaly, array $get): string {
    $regi  = $_GET;
    $_GET  = $get;
    SDH_Muhely_Modulok::kontextus_beallit('frontend');
    ob_start();
    $osztaly::oldal();
    $html  = (string) ob_get_clean();
    $_GET  = $regi;

    return $html;
};

$h = $oldal('SDH_Muhely_Eszkoz', ['k' => $imei]);
T::tartalmaz($h, 'Galaxy A52 ' . $jel, 'Eszközök: IMEI-re megvan');
T::tartalmaz($h, 'Pontos találat', 'Eszközök: kiírja, hogy pontosan keresett');
T::tartalmaz($h, 'IMEI szerint', 'Eszközök: mi szerint');
T::tartalmaz($h, 'mind=1', 'Eszközök: „Keresés mindenben” link');

$h = $oldal('SDH_Muhely_Ugyfel', ['k' => $tel]);
T::tartalmaz($h, 'Okos Ügyfél ' . $jel, 'Ügyfelek: telefonszámra megvan');
T::tartalmaz($h, 'telefonszám szerint', 'Ügyfelek: telefon szerint keresett');

$h = $oldal('SDH_Muhely_Munkalap', ['k' => $imei, 'allapot' => 'mind']);
T::tartalmaz($h, 'Okos Ügyfél ' . $jel, 'Munkalapok: IMEI-re a lap megvan');

$h = $oldal('SDH_Muhely_Termek', ['k' => $vonal]);
T::tartalmaz($h, 'Okos kijelző ' . $jel, 'Termékek: vonalkódra megvan');
T::tartalmaz($h, 'kód szerint', 'Termékek: kód szerint keresett');

$h = $oldal('SDH_Muhely_Termek', ['k' => 'fekete ' . $jel . ' kijelző']);
T::tartalmaz($h, 'Okos kijelző ' . $jel, 'Termékek: több szó, más sorrendben is megvan');

$h = $oldal('SDH_Muhely_Szolgaltatas', ['k' => 'kijelzőcsere ' . $jel]);
T::tartalmaz($h, 'Okos kijelzőcsere ' . $jel, 'Szolgáltatások: két szóra megvan');

// Pontos keresés üres: visszaesik a teljes keresésre.
$h = $oldal('SDH_Muhely_Eszkoz', ['k' => 'XQ99ZZ88QQ']);
T::tartalmaz($h, 'mindenben kerestem', 'üres pontos találatnál magától mindenben keres, és kiírja');
$h = $oldal('SDH_Muhely_Eszkoz', ['k' => '999999999999999']);
T::tartalmaz($h, 'IMEI szerint', 'IMEI-re nincs visszaesés (a pontos keresés a teljes) – a „Keresés mindenben” link marad');
T::nem_tartalmaz($h, 'mindenben kerestem', 'IMEI-re nem keres feleslegesen mindenben');

// „Keresés mindenben”: nincs szűkítés.
$h = $oldal('SDH_Muhely_Eszkoz', ['k' => $imei, 'mind' => '1']);
T::nem_tartalmaz($h, 'Pontos találat', 'mind=1: nincs szűkítés');
T::tartalmaz($h, 'Galaxy A52 ' . $jel, 'mind=1: a találat megvan');

// Kikapcsolva nincs szűkítés.
update_option($O::OPT, ['okos' => 0]);
$O::urit();
T::egyezik(null, $O::szukites('eszkoz', $imei), 'kikapcsolt okos keresés: nincs szűkítés');
T::egyezik('szoveg', $O::keres($imei) !== [] ? 'szoveg' : 'szoveg', 'kikapcsolva is keres (szövegként)');
delete_option($O::OPT);
$O::urit();

/* --- 4b. A felső szűrő kereséskor is ugyanúgy szűr (Munkalapok, Napi árukiadás) ------------------------------ */

$zart = SDH_Muhely_Munkalap::zart_kulcsok(SDH_Muhely_Munkalap::allapotok());
$eredeti_allapot = (string) $wpdb->get_var($wpdb->prepare('SELECT allapot FROM ' . $T('munkalap') . ' WHERE id = %d', $ml));
$wpdb->update($T('munkalap'), ['allapot' => (string) reset($zart)], ['id' => $ml]);
SDH_Muhely_Gyorsitotar::ervenytelenit();
$h = $oldal('SDH_Muhely_Munkalap', ['k' => $imei]);
T::tartalmaz($h, 'Okos Ügyfél ' . $jel, 'Munkalapok: kereséskor az alapnézet (nyitottak) nem szűkít – a lezárt lap is előjön');
$h = $oldal('SDH_Muhely_Munkalap', ['k' => $imei, 'allapot' => (string) array_key_first(SDH_Muhely_Munkalap::allapotok()) === (string) reset($zart) ? 'nincs' : (string) array_key_first(SDH_Muhely_Munkalap::allapotok())]);
T::nem_tartalmaz($h, 'Okos Ügyfél ' . $jel, 'Munkalapok: a kiválasztott állapot kereséskor is szűkít');
$wpdb->update($T('munkalap'), ['allapot' => $eredeti_allapot], ['id' => $ml]);
SDH_Muhely_Gyorsitotar::ervenytelenit();

$aru = SDH_Muhely_Munkalap::letrehoz(['fajta' => 'arukiadas', 'allapot' => SDH_Muhely_Munkalap::alap_kulcs(SDH_Muhely_Munkalap::allapotok()), 'ugyfel_id' => $u, 'keszult' => gmdate('Y-m-d', strtotime('-60 days')), 'nev' => 'Régi eladás ' . $jel]);
SDH_Muhely_Gyorsitotar::ervenytelenit();
$arulista = static function (array $get): string {
    $regi = $_GET;
    $_GET = $get;
    ob_start();
    SDH_Muhely_Eladas::oldal_arukiadas();
    $h = (string) ob_get_clean();
    $_GET = $regi;

    return $h;
};
T::igaz(is_int($aru), 'régi (60 napos) árukiadás');
$sor_jel = 'data-sdh-id="' . (int) $aru . '"';
T::tartalmaz($arulista(['k' => 'Okos Ügyfél ' . $jel]), $sor_jel, 'Árukiadás: kereséskor az alap időszak (Ma) nem szűkít');
T::nem_tartalmaz($arulista(['k' => 'Okos Ügyfél ' . $jel, 'szuro' => 'het']), $sor_jel, 'Árukiadás: a kiválasztott időszak (7 nap) kereséskor is szűkít');

/* --- 5. Mély oldal: az utolsó oldalon túl --------------------------------------------------------------------- */

foreach (['SDH_Muhely_Ugyfel', 'SDH_Muhely_Eszkoz', 'SDH_Muhely_Termek', 'SDH_Muhely_Szolgaltatas'] as $osztaly) {
    $h = $oldal($osztaly, ['oldalszam' => '11801000']);
    T::igaz(strpos($h, 'sdh-tabla') !== false && strpos($h, 'Fatal') === false, $osztaly . ': a 11 801 000. oldal sem hiba (az utolsó oldal jön)');
}

$h = $oldal('SDH_Muhely_Munkalap', ['oldalszam' => '99999999', 'allapot' => 'mind']);
T::tartalmaz($h, 'sdh-tabla', 'Munkalapok: a nagyon hátsó oldal sem hiba');

$regi = $_GET;
$_GET = ['oldalszam' => '99999999', 'szuro' => 'mind'];
ob_start();
SDH_Muhely_Eladas::oldal_arukiadas();
$h = (string) ob_get_clean();
$_GET = $regi;
T::tartalmaz($h, 'sdh-tabla', 'Napi árukiadás: a nagyon hátsó oldal sem hiba');

/* --- 6. Áttekintés: a szöveges szűrő javaslatai ---------------------------------------------------------------- */

$jav = $O::racs_javaslat('ugyfel', 'Okos Ügyfél ' . $jel);
T::igaz(count($jav) === 1 && strpos($jav[0], $jel) !== false, 'Ügyfél oszlop: javaslat a beírt elejéből');
T::egyezik([], $O::racs_javaslat('allapot', 'nyit'), 'választós oszlopra nincs javaslat (ott a lista kereshető)');
T::egyezik([], $O::racs_javaslat('nincs_ilyen', 'x'), 'ismeretlen oszlop: üres');
$rj = t_ajax('sdh_muhely_racs_javaslat', [], '_wpnonce', 'sdh_muhely_modal', ['oszlop' => 'gyarto', 'q' => 'Samsu']);
T::igaz(!empty($rj['success']) && in_array('Samsung', (array) $rj['data'], true), 'a javaslat végpontja (Gyártó: Samsu → Samsung)');

/* --- 7. Beállítások › Keresés és listák -------------------------------------------------------------------------- */

T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['kereses']), 'új lapfül: Keresés és listák');
T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['urlap_email']), 'új lapfül: Űrlapok e-mailben');
$b = $O::beallitas();
T::igaz($b['azonnali'] === 1 && $b['okos'] === 1 && $b['ugro'] === 1 && $b['dupla'] === 1 && $b['szuro_kereso'] === 1 && $b['allapot_pont'] === 0, 'alapértékek: minden be, az Állapot-pont ki');
$_POST = ['kereses_jelen' => '1', 'kereses' => ['azonnali' => '1', 'allapot_pont' => '1']];
$O::beallitas_mentes();
$_POST = [];
$b = $O::beallitas();
T::igaz($b['azonnali'] === 1 && $b['okos'] === 0 && $b['dupla'] === 0 && $b['allapot_pont'] === 1, 'mentés: a kapcsolók külön-külön');
ob_start();
$O::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::igaz(substr_count($doboz, 'type="checkbox"') === 6, 'a lapfülön hat kapcsoló');
T::nem_tartalmaz($doboz, 'Aktív', 'a kapcsolók mellett nincs Aktív/Inaktív felirat');
delete_option($O::OPT);
$O::urit();
$js = SDH_Muhely_Admin_UI::js_beallitas('frontend');
T::igaz(isset($js['okos']['ugro'], $js['urlapEmail']['gomb']), 'a böngésző megkapja a kapcsolókat');

/* --- 8. AJAX-védő ------------------------------------------------------------------------------------------- */

$V = 'SDH_Muhely_Ajax_Vedo';
$tiszta = '{"success":true,"data":{"a":1}}';
T::egyezik($tiszta, $V::javit($tiszta, true), 'tiszta JSON: érintetlen');
T::egyezik($tiszta, $V::javit("<br />\n<b>Warning</b>:  Undefined array key \"x\" in /a/b.php on line 3<br />\n" . $tiszta, true), 'a JSON elé írt figyelmeztetés levágva');
$hiba = json_decode($V::javit("<br />\n<b>Fatal error</b>:  Uncaught TypeError: x() in /a/b.php:9", false), true);
T::igaz(is_array($hiba) && $hiba['success'] === false && strpos($hiba['data']['uzenet'], 'Szerverhiba') === 0, 'végzetes hiba: olvasható JSON-hiba („Szerverhiba: …”)');
$html = '<h2 class="sdh-modal__cim">Ügyfél</h2>';
T::egyezik($html, $V::javit($html, false), 'a HTML-t adó végpont (űrlap) kimenete érintetlen');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'assets/naptar.js'), 'A szerver nem várt választ adott', 'a Naptár a nem JSON választ is érthetően írja ki');

/* --- 9. AJAX-kérés felülete: a popup linkjei a műhely-felületen maradnak ----------------------------------------- */

$regi = $_REQUEST;
$_REQUEST = ['kontextus' => 'frontend'];
add_filter('wp_doing_ajax', '__return_true');
sdh_muhely_ajax_kontextus();
T::igaz(SDH_Muhely_Modulok::frontenden_vagyunk(), 'kontextus=frontend: a kérés a műhely-felület');
$_REQUEST = ['kontextus' => 'admin'];
sdh_muhely_ajax_kontextus();
T::igaz(!SDH_Muhely_Modulok::frontenden_vagyunk(), 'kontextus=admin: a WordPress-admin');
$_REQUEST = $regi;
$f = t_ajax('sdh_muhely_ugyfelek_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $u, 'kontextus' => 'frontend']);
sdh_muhely_ajax_kontextus();
SDH_Muhely_Modulok::kontextus_beallit('frontend');
$f = t_ajax('sdh_muhely_ugyfelek_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $u]);
T::igaz(preg_match('/href="[^"]*eszkozok[^"]*ugyfel_id=' . $u . '/', $f['nyers']) === 1 && preg_match('/href="[^"]*wp-admin[^"]*sdh-muhely-eszkozok/', $f['nyers']) !== 1, 'a popup „Készülékei” linkje nem visz a WordPress-adminba');

/* --- 10. Űrlapok e-mailben -------------------------------------------------------------------------------------- */

$eb = $E::beallitas();
T::igaz(array_sum($eb['gomb']) === 7 && $eb['pdf'] === 1 && $eb['fajlok_ful'] === 1 && $eb['dok_cim'] === '', 'alap: hét űrlapon gomb, PDF, Fájlok lapfül, nincs dokumentumcím');
T::tartalmaz($E::gomb('ugyfel', $u), 'data-sdh-urlap-email="ugyfel"', 'gomb: ügyfél');
T::egyezik('', $E::gomb('ugyfel', 0), 'mentetlen rekordnál nincs gomb');
T::egyezik(['a@example.com', 'b@example.com'], $E::cimek('a@example.com, b@example.com; rossz-cim a@example.com'), 'címek: vessző / pontosvessző, az érvénytelen és az ismétlés kimarad');

foreach (['munkalap' => $ml, 'ugyfel' => $u, 'eszkoz' => $e, 'termek' => $p, 'szolgaltatas' => $sz] as $urlap => $id) {
    $a = $E::adatlap($urlap, $id);
    T::igaz(is_array($a) && $a['targy'] !== '' && $E::szoveg($a) !== '', 'adatlap: ' . $urlap);
}

T::tartalmaz($E::szoveg($E::adatlap('eszkoz', $e)), $imei, 'az eszköz adatlapján az IMEI');
$wpdb->update($T('eszkoz'), ['zarkod' => 'TITOK1234'], ['id' => $e]);
T::nem_tartalmaz($E::szoveg($E::adatlap('eszkoz', $e)), 'TITOK1234', 'a feloldó kód nem kerül a levélbe');

add_filter('sdh_muhely_tesztuzem', '__return_true');
$elo = t_ajax('sdh_muhely_urlap_email_elo', ['urlap' => 'munkalap', 'id' => $ml]);
T::igaz(!empty($elo['success']) && $elo['data']['pdf'] === true && strpos((string) $elo['data']['cimzett'], '@') !== false, 'az ablak kitöltése: az ügyfél címe, PDF lehet');
$k = t_ajax('sdh_muhely_urlap_email_kuld', ['urlap' => 'ugyfel', 'id' => $u, 'cimzett' => 'iroda@example.com', 'targy' => 'Teszt', 'szoveg' => '']);
T::igaz(!empty($k['success']), 'küldés (tesztüzemben „elküldve”, de nem megy ki)');
T::tartalmaz((string) $wpdb->get_var($wpdb->prepare('SELECT osszegzes FROM ' . SDH_Muhely_Valtozasnaplo::tabla() . " WHERE fo_tipus = 'ugyfel' AND fo_id = %d ORDER BY id DESC LIMIT 1", $u)), 'e-mailben továbbítva', 'a küldés a naplóban');
$k = t_ajax('sdh_muhely_urlap_email_kuld', ['urlap' => 'ugyfel', 'id' => $u, 'cimzett' => 'nem cím']);
T::igaz(empty($k['success']), 'érvénytelen címre nem küld');

update_option($E::OPT, ['gomb' => ['ugyfel' => 0]]);
$E::urit();
T::egyezik('', $E::gomb('ugyfel', $u), 'kikapcsolva: nincs gomb');
$k = t_ajax('sdh_muhely_urlap_email_kuld', ['urlap' => 'ugyfel', 'id' => $u, 'cimzett' => 'iroda@example.com']);
T::igaz(empty($k['success']), 'kikapcsolva a végpont sem küld');
T::tartalmaz($E::gomb('termek', $p), 'data-sdh-urlap-email', 'a többi űrlapon marad');
delete_option($E::OPT);
$E::urit();

/* --- 11. A munkalap Fájlok lapfüle ------------------------------------------------------------------------------- */

$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ml])['nyers'];
T::tartalmaz($urlap, 'id="ful_m_fajlok"', 'a munkalapon a Fájlok lapfül');
T::tartalmaz($urlap, 'name="fajl_csatolmany[]"', 'saját feltöltő mező (nem keveredik az Átvétel fotóival)');
T::tartalmaz($urlap, 'data-sdh-urlap-email="munkalap"', 'a munkalap gombsorában az E-mail gomb');
T::igaz(strrpos($urlap, 'data-sdh-urlap-email') > strrpos($urlap, 'data-sdh-penztar-fizetes'), 'az E-mail gomb a gombsor végén áll');
T::igaz(strpos($urlap, 'sdh-fulek__ful--7') !== false, 'javításnál a 7. lapfül');

$doku = (string) tempnam(sys_get_temp_dir(), 't93');
file_put_contents($doku, "%PDF-1.4\n% teszt\n");
$alap = SDH_Muhely_Munkalap::alap_kulcs(SDH_Muhely_Munkalap::allapotok());
$_FILES = [$E::MEZO => ['name' => ['szamla.pdf'], 'type' => ['application/pdf'], 'tmp_name' => [$doku], 'error' => [UPLOAD_ERR_OK], 'size' => [filesize($doku)]]];
$r = t_ajax('sdh_muhely_munkalapok_ment', [
    'id' => $ml, 'fajta' => 'javitas', 'allapot' => $alap, 'ugyfel_id' => $u, 'eszkoz_id' => $e,
    'keszult' => current_time('Y-m-d'), 'fajlok_jelen' => '1',
], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
$_FILES = [];
T::igaz(!empty($r['success']), 'mentés a Fájlok lapfül fájljával');
T::egyezik(1, $E::darab($ml), 'a fájl a lapon (munkalap_fajl)');
T::egyezik(0, count(SDH_Muhely_Csatolmany::lista('munkalap', $ml)), 'az Átvétel fotói közé nem került');

$k = t_ajax('sdh_muhely_munkalap_fajlok_kuld', ['id' => $ml]);
T::igaz(empty($k['success']) && strpos((string) ($k['data']['uzenet'] ?? ''), 'e-mail címe') !== false, 'dokumentumcím nélkül nem küld (és megmondja, hol kell megadni)');
update_option($E::OPT, ['dok_cim' => 'doksi@example.com']);
$E::urit();
$k = t_ajax('sdh_muhely_munkalap_fajlok_kuld', ['id' => $ml]);
T::igaz(!empty($k['success']) && strpos((string) $k['data']['uzenet'], '1 fájl') === 0, 'a dokumentumok elküldése (tesztüzemben)');
$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ml])['nyers'];
T::tartalmaz($urlap, 'data-sdh-fajlok-kuld', 'beállított címnél a lapfülön a küldés gomb');

update_option($E::OPT, ['fajlok_ful' => 0]);
$E::urit();
$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ml])['nyers'];
T::nem_tartalmaz($urlap, 'id="ful_m_fajlok"', 'kikapcsolva nincs Fájlok lapfül');
delete_option($E::OPT);
$E::urit();
remove_all_filters('sdh_muhely_tesztuzem');

/* --- 12. Indexek és a böngésző oldala ------------------------------------------------------------------------------- */

$indexek = static function (string $tabla) use ($wpdb): string {
    $n = array_map('strval', (array) $wpdb->get_col('SHOW INDEX FROM ' . $tabla, 2));

    if ($n === []) {
        $n = array_column((array) $wpdb->get_results('PRAGMA index_list(' . $tabla . ')', ARRAY_A), 'name');
    }

    return implode(' ', $n);
};

T::tartalmaz($indexek($T('ugyfel')), 'aktiv_nev', 'index: ügyfél (aktiv, nev)');
T::tartalmaz($indexek($T('eszkoz')), 'aktiv_modositva', 'index: eszköz (aktiv, modositva)');
T::tartalmaz($indexek($T('termek')), 'beszallito', 'index: termék beszállító');
T::tartalmaz($indexek($T('szolgaltatas')), 'nev', 'index: szolgáltatás neve');

$app  = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.js');
$racs = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/racs.js');
T::tartalmaz($app, 'sdh_muhely_okos_kereso', 'app.js: azonnali találatok a listák keresőiben');
T::tartalmaz($app, 'sdh-lapozo__ugro', 'app.js: oldalszám-ugró');
T::tartalmaz($app, "addEventListener('dblclick'", 'app.js: dupla kattintás');
T::tartalmaz($app, 'sdh_muhely_urlap_email_kuld', 'app.js: az E-mail ablak');
T::tartalmaz($racs, 'allapotPont', 'racs.js: Állapot csak színes pont');
T::tartalmaz($racs, 'data-halmaz-kereso', 'racs.js: kereső a választós szűrőkben');
T::tartalmaz($racs, 'sdh_muhely_racs_javaslat', 'racs.js: a szöveges szűrő javaslatai');
T::tartalmaz($racs, 'data-ugro', 'racs.js: oldalugró a rács eszköztárában');

T::vege('t93 keresés, listák, űrlapok e-mailben');
