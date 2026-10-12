<?php
/**
 * t43 – 0.57.0 / 1. lépés: SMS alapok.
 *
 * Séma, beállítások (a jelszó titkosítva), átjáró-kliens hamis átjáróval
 * (tesztek/sms-mock.php), számnormalizálás, SMS-szegmensek, csak hozzáfűzős
 * napló, ügyfél-számok sorrendje (1. mező, majd 2.), teszt SMS.
 *
 * A hamis átjárót a teszt maga indítja (php -S 127.0.0.1:8091). Valódi SMS nem megy ki.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$S    = 'SDH_Muhely_Sms';
$port = 8091;
$fajl = sys_get_temp_dir() . '/sdh-sms-mock-t43.json';
@unlink($fajl);

/* --- A hamis átjáró indítása ---------------------------------------------- */

$folyamat = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/sms-mock.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $csovek,
    null,
    ['SDH_SMS_MOCK_FAJL' => $fajl] + getenv()
);

register_shutdown_function(static function () use ($folyamat): void {
    if (is_resource($folyamat)) {
        proc_terminate($folyamat);
    }
});

for ($i = 0; $i < 40; $i++) {
    $ink = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);

    if ($ink) {
        fclose($ink);
        break;
    }

    usleep(100000);
}

$mock = static function (string $ut) use ($port): void {
    wp_remote_post('http://127.0.0.1:' . $port . $ut, ['timeout' => 5]);
};

$mock('/_reset');

/* --- Séma ----------------------------------------------------------------- */

T::egyezik('0.36.0', SDH_Muhely_Schema::DB_VERSION, 'sémaverzió 0.36.0');

foreach (['sablon', 'sablon_verzio', 'szabaly', 'uzenet', 'esemeny', 'dontes', 'onteszt', 'link_esemeny', 'leiratkozas'] as $t) {
    $n = SDH_Muhely_Schema::tabla('sms_' . $t);
    T::igaz($wpdb->get_var("SHOW TABLES LIKE '{$n}'") === $n, 'tábla: sms_' . $t);
}

T::igaz(count($wpdb->get_results('SHOW COLUMNS FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . " LIKE 'sms_tilt'")) === 1, 'ügyfél.sms_tilt oszlop');
T::igaz(count($wpdb->get_results('SHOW COLUMNS FROM ' . SDH_Muhely_Schema::tabla('uzenet') . " LIKE 'sms_id'")) === 1, 'üzenet.sms_id oszlop');

/* --- Beállítások: alapok, mentés, titkosított jelszó ----------------------- */

delete_option($S::OPT);
delete_option($S::OPT_ALLAPOT);

$b = $S::beallitas();
T::egyezik(false, $b['be'], 'új telepítésen a főkapcsoló KI');
T::egyezik('', $b['cim'], 'nincs beégetett cím');
T::egyezik('', $b['felhasznalo'], 'nincs beégetett felhasználónév');
T::egyezik('', $b['onteszt_szam'], 'nincs beégetett teszt-szám');
T::egyezik('+36', $b['orszagkod'], 'alap országkód +36');

$_POST = [
    'sms_jelen' => '1', 'sms_be' => '1', 'sms_mod' => 'helyi', 'sms_cim' => '127.0.0.1', 'sms_port' => (string) $port,
    'sms_felhasznalo' => 'tesztfelh', 'sms_jelszo' => 'tesztjelszo', 'sms_idotullepes' => '5',
    'sms_ujra_db' => '2', 'sms_ujra_perc' => '3', 'sms_csendes_be' => '1', 'sms_csendes_tol' => '22:30', 'sms_csendes_ig' => '07:15',
    'sms_alairas' => 'Teszt Szerviz', 'sms_orszagkod' => '+36', 'sms_health_perc' => '2', 'sms_onteszt_be' => '1',
    'sms_onteszt_ido' => '09:05', 'sms_onteszt_szam' => '06 30 111 2222', 'sms_riasztas_email' => 'riaszt@example.com', 'sms_kihagyott_perc' => '45',
];
$S::beallitas_mentes();

$b = $S::beallitas();
T::egyezik(true, $b['be'], 'mentés: főkapcsoló be');
T::egyezik('127.0.0.1', $b['cim'], 'mentés: cím');
T::egyezik($port, $b['port'], 'mentés: port');
T::egyezik('22:30', $b['csendes_tol'], 'mentés: csendes órák kezdete');
T::egyezik('07:15', $b['csendes_ig'], 'mentés: csendes órák vége');
T::egyezik(2, $b['ujra_db'], 'mentés: újrapróbálkozások');
T::egyezik('Teszt Szerviz', $b['alairas'], 'mentés: aláírás');
T::egyezik('riaszt@example.com', $b['riasztas_email'], 'mentés: riasztás e-mail');

$nyers = (string) get_option($S::OPT)['jelszo'];
T::igaz(SDH_Muhely_Titok::titkos_e($nyers), 'a jelszó titkosítva tárolódik (sdh1:)');
T::nem_tartalmaz(wp_json_encode(get_option($S::OPT)), 'tesztjelszo', 'a jelszó nincs nyíltan az opcióban');

// Jelszó nélküli mentés: a régi marad.
$_POST['sms_jelszo'] = '';
$S::beallitas_mentes();
T::egyezik($nyers, (string) get_option($S::OPT)['jelszo'], 'üres jelszómező: a mentett jelszó marad');

// Hibás értékek: javítva mentődnek.
$_POST['sms_csendes_tol'] = '25:99';
$_POST['sms_port'] = '99999';
$_POST['sms_mod'] = 'valami';
$_POST['sms_cim'] = 'javascript://x';
$S::beallitas_mentes();
$b = $S::beallitas();
T::egyezik('22:30', $b['csendes_tol'], 'hibás időpont: a régi marad');
T::egyezik(65535, $b['port'], 'a port a határra vágva');
T::egyezik('helyi', $b['mod'], 'ismeretlen üzemmód → helyi');
T::egyezik('', $b['cim'], 'nem http(s) cím nem mentődik');

$_POST['sms_cim'] = '127.0.0.1';
$_POST['sms_port'] = (string) $port;
$_POST['sms_mod'] = 'helyi';
$_POST['sms_csendes_tol'] = '22:30';
$S::beallitas_mentes();

/* --- Átjáró-kliens a hamis átjáróval ---------------------------------------- */

$h = $S::health();
T::egyezik(true, $h['ok'], 'kapcsolat-teszt: az átjáró elérhető');

$v = $S::atjaro_kuld('+36301112222', 'Szia, ez teszt.');
T::egyezik(true, $v['ok'], 'küldés elfogadva');
T::egyezik('m1', $v['id'], 'az átjáró üzenetazonosítója megvan');
T::egyezik('pending', $v['allapot'], 'kezdő állapot: pending');

$a = $S::atjaro_allapot('m1');
T::egyezik('pending', $a['allapot'], 'lekérdezett állapot: pending');
$mock('/_allapot?id=m1&state=Delivered');
$a = $S::atjaro_allapot('m1');
T::egyezik('delivered', $a['allapot'], 'lekérdezett állapot: delivered');

$a = $S::atjaro_allapot('nincs');
T::egyezik(false, $a['ok'], 'ismeretlen üzenet: hiba');

$v = $S::atjaro_kuld('+36300000000', 'érvénytelen számra');
T::egyezik(false, $v['ok'], 'az átjáró elutasítja az érvénytelen számot');
T::egyezik('kerelem', $v['tipus'], 'a hiba típusa: kérés');

$mock('/_hiba?n=1&kod=500');
$v = $S::atjaro_kuld('+36301112222', 'szerverhiba');
T::egyezik('atjaro_hiba', $v['tipus'], '5xx: átjáró-hiba (újrapróbálható)');
$v = $S::atjaro_kuld('+36301112222', 'ez már megy');
T::egyezik(true, $v['ok'], 'a hiba elmúltával a küldés megy');

// Rossz jelszó.
$_POST['sms_jelszo'] = 'rossz';
$S::beallitas_mentes();
$v = $S::atjaro_kuld('+36301112222', 'rossz jelszóval');
T::egyezik('hitelesites', $v['tipus'], 'rossz jelszó: hitelesítési hiba');
T::nem_tartalmaz($v['hiba'], 'rossz', 'a hibaüzenet nem tartalmazza a jelszót');
$_POST['sms_jelszo'] = 'tesztjelszo';
$S::beallitas_mentes();
T::egyezik(true, $S::health()['ok'], 'a jelszó visszaállítva: újra elérhető');

// Nem elérhető átjáró (zárt port).
$_POST['sms_port'] = '1';
$S::beallitas_mentes();
$v = $S::atjaro_kuld('+36301112222', 'nincs szerver');
T::egyezik('elerhetetlen', $v['tipus'], 'zárt port: elérhetetlen');
T::tartalmaz($v['hiba'], 'Local Server kapcsolója Online-e', 'érthető magyar hibaüzenet (helyi mód)');
T::nem_tartalmaz($v['hiba'], 'tesztjelszo', 'a hibaüzenet nem tartalmazza a jelszót (elérhetetlen)');
$_POST['sms_port'] = (string) $port;
$S::beallitas_mentes();

// Cím nélkül.
update_option($S::OPT, ['cim' => ''] + get_option($S::OPT));
$v = $S::atjaro_kuld('+36301112222', 'nincs cím');
T::egyezik('nincs_cim', $v['tipus'], 'cím nélkül: nincs_cim');
$_POST['sms_cim'] = '127.0.0.1';
$S::beallitas_mentes();

/* --- Telefonszám ----------------------------------------------------------- */

foreach ([
    ['06 30 400 4636', '+36304004636'], ['+36-30-400-4636', '+36304004636'], ['0036304004636', '+36304004636'],
    ['36304004636', '+36304004636'], ['(06) 70/123-4567', '+36701234567'], ['+4915112345678', '+4915112345678'],
] as [$be, $ki]) {
    $n = $S::szam_normalizal($be);
    T::egyezik($ki, $n['szam'], 'normalizálás: ' . $be);
    T::egyezik($be, $n['eredeti'], 'az eredeti szám megmarad: ' . $be);
}

foreach (['' => 'ures', '0' => 'ures', '000' => 'ures', 'abc' => 'nem_szam', '06301' => 'rovid', '06 88 123 456' => 'nem_mobil', '06 40 123 4567' => 'nem_mobil'] as $be => $kod) {
    $n = $S::szam_normalizal((string) $be);
    T::egyezik(false, $n['ok'], 'érvénytelen szám: „' . $be . '”');
    T::egyezik($kod, $n['kod'], 'az ok: „' . $be . '” → ' . $kod);
}

T::egyezik('Nincs érvényes telefonszám – SMS nem küldhető.', $S::szam_normalizal('0')['uzenet'], 'a „0” üzenete');

/* --- SMS-szegmensek ---------------------------------------------------------- */

T::egyezik(1, $S::szegmensek(str_repeat('a', 160))['szegmens'], '160 GSM-7 karakter = 1 SMS');
T::egyezik(2, $S::szegmensek(str_repeat('a', 161))['szegmens'], '161 GSM-7 karakter = 2 SMS');
T::egyezik('UCS-2', $S::szegmensek('Javítás kész')['kodolas'], 'ékezetes (í) szöveg UCS-2');
T::egyezik(1, $S::szegmensek(str_repeat('á', 70))['szegmens'], '70 UCS-2 karakter = 1 SMS');
T::egyezik(2, $S::szegmensek(str_repeat('á', 71))['szegmens'], '71 UCS-2 karakter = 2 SMS');
T::egyezik(3, $S::szegmensek(str_repeat('ő', 135))['szegmens'], '135 UCS-2 karakter = 3 SMS (67 / szegmens)');

/* --- Ügyfél számai: 1. mező, majd 2. ---------------------------------------- */

$u1 = t_ugyfel('Számos', ['telefon' => '06 30 123 4567', 'telefon2' => '06 20 765 4321']);
$sz = $S::ugyfel_szamai($u1);
T::egyezik(2, count($sz['szamok']), 'két szám az ügyfélnél');
T::egyezik('+36301234567', $sz['szamok'][0]['szam'], 'az 1. telefonmező van elöl');
T::egyezik('ugyfel1', $sz['szamok'][0]['forras'], 'a forrás: ugyfel1');
T::egyezik('+36207654321', $sz['szamok'][1]['szam'], 'a 2. telefonmező a tartalék');

$u2 = t_ugyfel('Dupla', ['telefon' => '06 30 123 4567', 'telefon2' => '+36 30 123 4567']);
T::egyezik(1, count($S::ugyfel_szamai($u2)['szamok']), 'ugyanaz a szám kétszer: egyszer szerepel');

$u3 = t_ugyfel('Rossz egyes', ['telefon' => '0', 'telefon2' => '06 70 111 2233']);
$sz = $S::ugyfel_szamai($u3);
T::egyezik(false, $sz['szamok'][0]['ok'], 'érvénytelen 1. szám jelezve');
T::egyezik(true, $sz['szamok'][1]['ok'], 'érvényes 2. szám mellette');

$u4 = t_ugyfel('Tiltott', ['telefon' => '06 30 123 4567']);
$wpdb->update(SDH_Muhely_Schema::tabla('ugyfel'), ['sms_tilt' => 1], ['id' => $u4]);
T::egyezik(true, $S::ugyfel_szamai($u4)['tilt'], 'az SMS-tiltás jelölő olvasható');
T::egyezik(false, $S::ugyfel_szamai(0)['tilt'], 'nincs ügyfél: nincs tiltás');

/* --- Napló: csak hozzáfűzés --------------------------------------------------- */

$id = $S::naplo_uj(['ugyfel_id' => $u1, 'szam' => '+36301234567', 'szam_eredeti' => '06 30 123 4567', 'szam_forras' => 'ugyfel1', 'szoveg' => 'Elkészült a javítás.', 'kivalto' => 'popup']);
T::igaz($id > 0, 'a napló sor létrejön');

$sor = $wpdb->get_row('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE id = ' . (int) $id);
T::egyezik('varakozik', $sor->allapot, 'kezdő állapot: varakozik');
T::egyezik('Elkészült a javítás.', $sor->szoveg, 'a pontos szöveg eltárolva');
T::egyezik(1, (int) $sor->szegmens, 'szegmensszám a naplóban');
T::egyezik('06 30 123 4567', $sor->szam_eredeti, 'az eredeti szám a naplóban');
T::igaz((int) $sor->felhasznalo > 0 && $sor->felhasznalo_nev !== '', 'a küldő felhasználó neve a naplóban');

$S::allapot_ir($id, 'pending', 'Átadva.', ['atjaro_id' => 'm9', 'proba' => 1]);
$S::allapot_ir($id, 'sent', 'Kiment.');
$S::allapot_ir($id, 'delivered', 'Kézbesítve.', ['kezbesitve' => current_time('mysql')], 'w-evt-1');

$sor = $wpdb->get_row('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE id = ' . (int) $id);
T::egyezik('delivered', $sor->allapot, 'a jelenlegi állapot: delivered');
T::egyezik('Elkészült a javítás.', $sor->szoveg, 'a szöveg az állapotváltások után sem változik');

$esemenyek = $wpdb->get_col($wpdb->prepare('SELECT allapot FROM ' . $S::tabla('esemeny') . ' WHERE sms_id = %d ORDER BY id', $id));
T::egyezik(['varakozik', 'pending', 'sent', 'delivered'], $esemenyek, 'minden átmenet külön eseménysor, időrendben');

T::egyezik(false, $S::esemeny($id, 'allapot', 'delivered', 'dupla', 'w-evt-1'), 'ugyanaz az esemény (kulcs) kétszer nem rögzül');
T::egyezik(4, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $S::tabla('esemeny') . ' WHERE sms_id = %d', $id)), 'az eseménysorok száma nem nőtt');

// Bejövő üzenet egyedi kulccsal.
$be1 = $S::naplo_uj(['irany' => 'be', 'kivalto' => 'bejovo', 'szam' => '+36301234567', 'szoveg' => 'Köszönöm!', 'allapot' => 'fogadva', 'olvasva' => 0, 'kulcs' => 'in-1']);
T::igaz($be1 > 0, 'bejövő SMS naplózva');

$suppress = $wpdb->suppress_errors(true);
$be2 = $S::naplo_uj(['irany' => 'be', 'kivalto' => 'bejovo', 'szam' => '+36301234567', 'szoveg' => 'Köszönöm!', 'allapot' => 'fogadva', 'olvasva' => 0, 'kulcs' => 'in-1']);
$wpdb->suppress_errors($suppress);
T::egyezik(0, $be2, 'ugyanaz a bejövő esemény kétszer nem duplázódik');

// Állapotok csak előre lépnek.
T::egyezik(false, $S::allapot_javul('delivered', 'sent'), 'elkésett „sent” nem írja felül a „delivered”-et');
T::egyezik(true, $S::allapot_javul('pending', 'delivered'), 'pending → delivered előre lép');
T::egyezik(false, $S::allapot_javul('delivered', 'failed'), 'kézbesített SMS nem lesz sikertelen');

/* --- Teszt SMS ---------------------------------------------------------------- */

$mock('/_reset');
$r = $S::teszt_kuldes('', false);
T::egyezik(true, $r['ok'], 'teszt SMS: a beállított teszt-számra megy');

$sor = $wpdb->get_row('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE id = ' . (int) $r['id']);
T::egyezik('onteszt', $sor->tipus, 'a teszt SMS külön típus (onteszt)');
T::egyezik('+36301112222', $sor->szam, 'a teszt-szám normalizálva');
T::igaz($sor->atjaro_id !== '', 'a teszt SMS átjáró-azonosítót kapott');

$lista = json_decode((string) wp_remote_retrieve_body(wp_remote_get('http://127.0.0.1:' . $port . '/_lista')), true);
T::egyezik(1, count((array) $lista), 'a hamis átjáró egy üzenetet kapott');

$r = $S::teszt_kuldes('0', false);
T::egyezik(false, $r['ok'], 'teszt SMS „0” számra nem megy');
T::egyezik(1, count((array) json_decode((string) wp_remote_retrieve_body(wp_remote_get('http://127.0.0.1:' . $port . '/_lista')), true)), 'az átjáró nem kapott újabb üzenetet');

// Szimulációs mód: nem küld, csak naplóz.
$_POST['sms_szimulacio'] = '1';
$S::beallitas_mentes();
$r = $S::teszt_kuldes('', false);
T::egyezik(true, $r['ok'], 'szimuláció: a teszt „sikeres”');
T::tartalmaz($r['uzenet'], 'Szimulációs mód', 'szimuláció: jelzi, hogy nem küldött');
$sor = $wpdb->get_row('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE id = ' . (int) $r['id']);
T::egyezik('szimulalt', $sor->allapot, 'szimuláció: naplózva, állapot szimulalt');
T::egyezik(1, count((array) json_decode((string) wp_remote_retrieve_body(wp_remote_get('http://127.0.0.1:' . $port . '/_lista')), true)), 'szimuláció: az átjáró nem kapott újabb üzenetet');

// A Beállítások oldalon megvan a lapfül, és nem jelenik meg a jelszó.
$szak = SDH_Muhely_Beallitasok::szakaszok();
T::igaz(isset($szak['sms']), 'a Beállítások › SMS lapfül létezik');

ob_start();
$S::beallitas_doboz();
$html = (string) ob_get_clean();
T::nem_tartalmaz($html, 'tesztjelszo', 'az oldal HTML-je nem tartalmazza a jelszót');
T::tartalmaz($html, 'data-sdh-sms-akcio="sdh_muhely_sms_kapcsolat"', 'kapcsolat-teszt gomb');
T::tartalmaz($html, 'data-sdh-sms-akcio="sdh_muhely_sms_teszt"', 'teszt SMS gomb');

// Takarítás.
delete_option($S::OPT);
delete_option($S::OPT_ALLAPOT);
@unlink($fajl);

T::vege('t43 SMS alapok: séma, beállítások, átjáró-kliens, számok, szegmensek, napló, teszt SMS');
