<?php
/**
 * t91 – Tesztüzem és az SDH Tesztadat kiegészítő (0.98.0).
 *
 * Tesztüzem (SDH_Muhely_Halozat::tesztuzem):
 *  - alapból ki; az `sdh_muhely_tesztuzem` szűrő bekapcsolja,
 *  - SMS: szimuláció (nem megy ki), wp_mail: „elküldve”, de nem megy ki,
 *  - Számlázz.hu és Google (Drive) kérés tiltva, más külső cím nem,
 *  - külső mentés (Drive / FTP / NAS) nem fut, napi automatikus mentés nincs,
 *  - a felületen „Tesztüzem” sáv.
 *
 * Tesztadat-kiegészítő (tesztadat/sdh-tesztadat):
 *  - 1000-es generálás: minden szakaszban pontosan 1000, a tételek és hibák a munkalapokhoz kötve,
 *  - kb. 85% magyar / 15% külföldi név, az e-mail-címek csak a fenntartott example.* tartományban,
 *  - minden sor jelölt, a törlés UTÁN a darabszámok pontosan a generálás előtti értékek (a saját adathoz nem nyúl),
 *  - megszakítás után folytatható (a „hol tart” az adatbázisból jön).
 */

require __DIR__ . '/_keret.php';

global $wpdb;

/* --- 1. Tesztüzem ------------------------------------------------------------- */

T::igaz(!SDH_Muhely_Halozat::tesztuzem(), 'alapból nincs tesztüzem');
T::igaz(empty(SDH_Muhely_Sms::beallitas()['szimulacio']) || !empty(get_option(SDH_Muhely_Sms::OPT, [])['szimulacio']), 'alapból az SMS nem szimuláció (ha nincs úgy beállítva)');

add_filter('sdh_muhely_tesztuzem', '__return_true');

T::igaz(SDH_Muhely_Halozat::tesztuzem(), 'a szűrő bekapcsolja');
T::igaz(!empty(SDH_Muhely_Sms::beallitas()['szimulacio']), 'tesztüzemben az SMS csak szimuláció');

$kuldott = 0;
add_action('phpmailer_init', static function () use (&$kuldott): void {
    $kuldott++;
});
T::igaz(wp_mail('valaki@example.com', 'Próba', 'Szöveg') === true, 'wp_mail „sikeres”');
T::egyezik(0, $kuldott, 'de a levél nem indult el (a PHPMailer nem is készült el)');

$v = SDH_Muhely_Halozat::kerelem_elott(false, [], 'https://www.szamlazz.hu/szamla/');
T::igaz(is_wp_error($v) && $v->get_error_code() === 'sdh_tesztuzem', 'Számlázz.hu tiltva');
$v = SDH_Muhely_Halozat::kerelem_elott(false, [], 'https://www.googleapis.com/upload/drive/v3/files');
T::igaz(is_wp_error($v) && $v->get_error_code() === 'sdh_tesztuzem', 'Google Drive tiltva');
add_filter('sdh_muhely_halozat_kenyszer', static fn (): string => 'online', 99);
T::egyezik(false, SDH_Muhely_Halozat::kerelem_elott(false, [], 'https://api.example.com/x'), 'más külső cím nem tiltott');
T::egyezik([], SDH_Muhely_Mentes::kulso_masolat('nincs-ilyen.jsonl.gz'), 'külső mentés nem fut');

$fe = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-frontend.php');
T::tartalmaz($fe, 'sdh-tesztuzem-jel', 'a felületen tesztüzem-sáv');
$lv = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-levelezes.php');
T::tartalmaz($lv, "Tesztüzem: ezen a próbapéldányon levél nem megy ki.", 'a saját postafiókból sem megy levél');

/* --- 2. Tesztadat-kiegészítő ----------------------------------------------------- */

$fajl = SDH_MUHELY_DIR . 'tesztadat/sdh-tesztadat/sdh-tesztadat.php';
T::igaz(is_file($fajl), 'a kiegészítő a forrásban van');
require_once $fajl;
T::igaz(SDH_Tesztadat::muhely_kesz(), 'az SDH Műhely megfelel neki');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . '.gitattributes'), '/tesztadat', 'a telepítő zipbe nem kerül');

$tablak = ['ugyfel', 'ugyfel_szo', 'eszkoz', 'termek', 'termek_mozgas', 'szolgaltatas', 'munkalap', 'munkalap_tetel', 'munkalap_hiba', 'penztar'];
$szamol = static function () use ($wpdb, $tablak): array {
    $o = [];
    foreach ($tablak as $t) {
        $o[$t] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla($t));
    }
    return $o;
};

delete_option(SDH_Tesztadat::OPT);
$elotte = $szamol();

// Megszakítás utánzása: egy rövid adag, majd folytatás.
$j = SDH_Tesztadat::lepes(1000, 0.0);
T::igaz(!$j['kesz'], 'rövid időkeretnél nem végez egyszerre (adagol)');
$n = 0;
while (!$j['kesz'] && $n++ < 200) {
    $j = SDH_Tesztadat::lepes();
}
T::igaz($j['kesz'], 'folytatással befejeződik');
T::egyezik(100.0, (float) $j['szazalek'], '100%');

$utana = $szamol();
foreach (['ugyfel', 'eszkoz', 'termek', 'szolgaltatas', 'munkalap', 'penztar', 'munkalap_hiba'] as $t) {
    T::egyezik(1000, $utana[$t] - $elotte[$t], $t . ': pontosan 1000 új sor');
}
$tetel = $utana['munkalap_tetel'] - $elotte['munkalap_tetel'];
T::igaz($tetel >= 1000 && $tetel <= 3000, 'tételek: munkalaponként 1–3 (' . $tetel . ')');
T::igaz($utana['ugyfel_szo'] > $elotte['ugyfel_szo'], 'az ügyfélkereső szó-indexe is elkészült');

$a  = SDH_Tesztadat::allapot();
$U  = SDH_Muhely_Schema::tabla('ugyfel');
$tol = (int) $a['alap']['ugyfel'];
$ki  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$U} WHERE id >= {$tol} AND (telefon NOT LIKE '+36%' OR szamlazasi_orszag <> 'Magyarország')");
$kulfoldi = 0;
for ($i = 0; $i < 1000; $i++) {
    $kulfoldi += SDH_Tesztadat::ugyfel_adat($i)['kulfoldi'] === '1' ? 1 : 0;
}
T::igaz($kulfoldi >= 110 && $kulfoldi <= 190, 'külföldi arány 15% körül (' . ($kulfoldi / 10) . '%)');
T::igaz($ki > 0, 'van külföldi címmel / számmal felvett ügyfél');
$rossz_mail = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$U} WHERE id >= {$tol} AND email <> '' AND email NOT LIKE '%@example.com' AND email NOT LIKE '%@example.net' AND email NOT LIKE '%@example.org'");
T::egyezik(0, $rossz_mail, 'minden e-mail-cím fenntartott (kézbesíthetetlen) tartományban');
$jel = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$U} WHERE id >= {$tol} AND forras = 'sdhteszt' AND kulso_azonosito LIKE 'sdhteszt-u-%'");
T::egyezik(1000, $jel, 'minden ügyfél jelölt');

$M = SDH_Muhely_Schema::tabla('munkalap');
$arva = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$M} m WHERE m.id >= " . (int) $a['alap']['munkalap'] . " AND m.ugyfel_id > 0 AND NOT EXISTS (SELECT 1 FROM {$U} u WHERE u.id = m.ugyfel_id)");
T::egyezik(0, $arva, 'minden munkalap létező ügyfélre mutat');
T::egyezik(1, count(SDH_Muhely_Munkalap::hibasorok((int) $a['alap']['munkalap'])), 'a munkalap hibasora a rendszer saját olvasójával jön');

// Törlés adagokban – utána pontosan a kiinduló állapot.
$n = 0;
do {
    $t = SDH_Tesztadat::torles_lepes(0.5);
} while (empty($t['kesz']) && $n++ < 500);
T::igaz(!empty($t['kesz']), 'a törlés befejeződik');
T::egyezik($elotte, $szamol(), 'törlés után minden tábla pontosan a generálás előtti darabszámon (saját adathoz nem nyúlt)');
T::egyezik([], SDH_Tesztadat::allapot(), 'az állapot is törlődött');

// Megszakadt, majd elölről indított generálás (az állapot elveszett): a törlés a jelölés alapján így is mindent megtalál.
$elotte2 = $szamol();
SDH_Tesztadat::lepes(1000, 0.0);
SDH_Tesztadat::lepes(null, 0.3);
delete_option(SDH_Tesztadat::OPT);
T::igaz(SDH_Tesztadat::van_tesztadat(), 'állapot nélkül is felismeri a maradék tesztadatot');
$n = 0;
do {
    $t = SDH_Tesztadat::torles_lepes(0.5);
} while (empty($t['kesz']) && $n++ < 500);
T::egyezik($elotte2, $szamol(), 'állapot nélkül is pontosan a kiinduló darabszámokra takarít');
T::igaz(!SDH_Tesztadat::van_tesztadat(), 'nem maradt tesztadat');

T::vege('t91 tesztüzem + tesztadat-kiegészítő: semmi nem megy ki, 1000-es generálás, arány, jelölés, pontos törlés');
