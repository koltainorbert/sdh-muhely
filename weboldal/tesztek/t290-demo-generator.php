<?php
/**
 * t290 – bemutató-adat generátor: darabszámok (--db=1000), jelölés, kitalált adatok (név, @pelda.hu, telefon-tartomány),
 * determinizmus, újrafuttatás (nem duplikál), képek, munkatársak, tiltott minta az adatban.
 */
require __DIR__ . '/_t29-seged.php';

global $wpdb;

$t = static fn (string $n): string => SDH_Muhely_Schema::tabla($n);
$k = SDH_Demo_Generator::KULSO;

$vart = SDH_Demo_Generator::darabok(1000);
T::egyezik(['ugyfel' => 200, 'eszkoz' => 250, 'munkalap' => 230, 'termek' => 120, 'szolgaltatas' => 30, 'penztar' => 120, 'naptar' => 30], $vart, 'darabok(1000)');
T::egyezik(9800, array_sum(SDH_Demo_Generator::darabok(10000)), 'darabok(10000) összesen ~10 000');

$r = SDH_Demo_Generator::general(1000);
T::egyezik(980, $r['osszes'], 'összes rekord');

$db = static function () use ($wpdb, $t, $k): array {
    return [
        'ugyfel'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('ugyfel')} WHERE kulso_azonosito LIKE '{$k}u-%' AND forras = 'bemutato'"),
        'eszkoz'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('eszkoz')} WHERE kulso_azonosito LIKE '{$k}e-%'"),
        'munkalap'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('munkalap')} WHERE kulso_azonosito LIKE '{$k}l-%'"),
        'termek'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('termek')} WHERE kulso_azonosito LIKE '{$k}p-%'"),
        'szolgaltatas' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('szolgaltatas')} WHERE megjegyzes = '[bemutató]'"),
        'penztar'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('penztar')} WHERE kulso LIKE '{$k}k-%'"),
        'naptar'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('naptar_esemeny')} WHERE forras = 'bemutato'"),
    ];
};

T::egyezik($vart, $db(), 'darabszámok az adatbázisban');

$tetel = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('munkalap_tetel')} WHERE kulso_azonosito LIKE '{$k}t-%'");
$hiba  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('munkalap_hiba')} h JOIN {$t('munkalap')} m ON m.id = h.munkalap_id WHERE m.kulso_azonosito LIKE '{$k}l-%'");
T::igaz($tetel >= 230 && $tetel <= 690 && $tetel === $r['tetelek'], 'munkalap-tételek 1–3 / lap (' . $tetel . ')');
T::igaz($hiba > 100, 'hibasorok a javítási lapokon (' . $hiba . ')');
T::igaz((int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('termek')} WHERE kulso_azonosito LIKE '{$k}p-%' AND keszlet > 0") > 80, 'termékek készlettel');

// 18 hónap: a legrégebbi lap legfeljebb 548 napos, és van egészen friss is.
$legregebbi = (string) $wpdb->get_var("SELECT MIN(keszult) FROM {$t('munkalap')} WHERE kulso_azonosito LIKE '{$k}l-%'");
$legujabb   = (string) $wpdb->get_var("SELECT MAX(keszult) FROM {$t('munkalap')} WHERE kulso_azonosito LIKE '{$k}l-%'");
T::igaz(strtotime($legregebbi) >= time() - 549 * DAY_IN_SECONDS && strtotime($legujabb) >= time() - 20 * DAY_IN_SECONDS, '18 hónapra szórva (' . $legregebbi . ' – ' . $legujabb . ')');

// A végösszeg a tételek összege.
$elteres = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('munkalap')} m WHERE m.kulso_azonosito LIKE '{$k}l-%' AND ABS(m.brutto_ertek - (SELECT COALESCE(SUM(brutto_ertek), 0) FROM {$t('munkalap_tetel')} x WHERE x.munkalap_id = m.id)) > 1");
T::egyezik(0, $elteres, 'munkalap végösszeg = tételek összege');

// Kitalált elérhetőségek.
$rossz_email = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('ugyfel')} WHERE kulso_azonosito LIKE '{$k}u-%' AND email <> '' AND email NOT LIKE '%@pelda.hu'");
T::egyezik(0, $rossz_email, 'e-mail csak @pelda.hu');
$tel = (array) $wpdb->get_col("SELECT telefon FROM {$t('ugyfel')} WHERE kulso_azonosito LIKE '{$k}u-%'");
T::egyezik([], array_values(array_filter($tel, static fn ($x): bool => !preg_match('/^\+36 (20|30|70) 0\d{2} \d{4}$/', (string) $x))), 'telefon a kitalált tartományban');
T::igaz(preg_match('/^\d{15}$/', (string) $wpdb->get_var("SELECT imei FROM {$t('eszkoz')} WHERE imei <> '' LIMIT 1")) === 1, 'IMEI 15 jegyű');

// Determinizmus és újrafuttatás.
$elso = (string) $wpdb->get_var("SELECT nev FROM {$t('ugyfel')} WHERE kulso_azonosito LIKE '{$k}u-%' ORDER BY id LIMIT 1");
T::egyezik(SDH_Demo_Generator::ugyfel_adat(0), SDH_Demo_Generator::ugyfel_adat(0), 'ugyanaz a mag ugyanazt adja');
SDH_Demo_Generator::general(1000);
T::egyezik($vart, $db(), 'újrafuttatás: nem duplikál');
T::egyezik($elso, (string) $wpdb->get_var("SELECT nev FROM {$t('ugyfel')} WHERE kulso_azonosito LIKE '{$k}u-%' ORDER BY id LIMIT 1"), 'újrafuttatás: ugyanazok az emberek');

// Képek: készülékfotó (webp, a feltöltési mappában) és átvételi fotó csatolmányként.
if (SDH_Demo_Generator::kep_forras() !== '') {
    $kepes = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('eszkoz')} WHERE kulso_azonosito LIKE '{$k}e-%' AND kep_url LIKE '%/bemutato/%.webp'");
    T::igaz($kepes > 200, 'készülékfotók (' . $kepes . ')');
    $url = (string) $wpdb->get_var("SELECT kep_url FROM {$t('eszkoz')} WHERE kep_url <> '' LIMIT 1");
    T::igaz(is_file(trailingslashit(wp_upload_dir()['basedir']) . 'bemutato/' . basename($url)), 'a kép a feltöltési mappában');
    T::egyezik(23, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('csatolmany')} WHERE tipus = 'munkalap'"), 'átvételi fotók (minden 10. lap)');
} else {
    T::igaz(false, 'nincs képforrás (demo-adat/kepek)');
}

// Munkatársak (felelősök).
$munkatarsak = get_users(['login__in' => ['munkatars-bence', 'munkatars-reka', 'munkatars-adam', 'munkatars-eszter'], 'fields' => 'ID']);
T::egyezik(4, count($munkatarsak), 'négy kitalált munkatárs');
T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('munkalap')} WHERE kulso_azonosito LIKE '{$k}l-%' AND felelos NOT IN (" . implode(',', array_map('intval', $munkatarsak)) . ')'), 'a lapok felelőse munkatárs');

// Nincs tiltott minta (SDH, platform, eredet) az adatban.
$szoveg = '';

foreach (['ugyfel', 'eszkoz', 'munkalap', 'munkalap_tetel', 'munkalap_hiba', 'termek', 'szolgaltatas', 'penztar', 'naptar_esemeny', 'naptar_szemely'] as $tabla) {
    foreach ((array) $wpdb->get_results('SELECT * FROM ' . $t($tabla), ARRAY_N) as $sor) {
        $szoveg .= implode('|', array_map('strval', $sor)) . "\n";
    }
}

$szoveg = str_replace(trailingslashit(wp_upload_dir()['baseurl']), 'https://pelda.test/kepek/', $szoveg);
T::egyezik([], t_tiltott($szoveg), 'tiltott minta az adatban');

T::vege(sprintf('t290 bemutató-generátor (1000: %.2f mp)', $r['mp']));
