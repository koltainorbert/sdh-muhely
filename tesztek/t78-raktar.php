<?php
/**
 * t78 – F4 (0.86.0): raktár – foglalás, hiány a lapon, rendelési javaslat, bevételezés, CSV.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$R = 'SDH_Muhely_Raktar';
$T = static fn (string $t): string => SDH_Muhely_Schema::tabla($t);

T::igaz(version_compare(SDH_Muhely_Schema::DB_VERSION, '0.49.0', '>='), 'sémaverzió 0.49.0');
T::igaz(t_oszlop($T('termek'), 'cel_keszlet'), 'új oszlop: termek.cel_keszlet');

$besz = 'Alkatrész Kft T78 ' . wp_rand(100, 999);
$wpdb->insert($T('termek'), [
    'megnevezes' => 'Kijelző T78', 'cikkszam' => 'KIJ-T78', 'beszallito' => $besz, 'keszletkezeles' => 1, 'min_keszlet' => 5,
    'besz_netto' => 1000, 'brutto_ar' => 5000, 'me' => 'db', 'aktiv' => 1, 'letrehozva' => current_time('mysql'),
]);
$p = (int) $wpdb->insert_id;
SDH_Muhely_Termek::mozgas($p, 2, 'nyito');
$t = SDH_Muhely_Termek::egy($p);

/* --- Javaslat ----------------------------------------------------------------------------------------- */

T::egyezik(10.0, $R::cel($t), 'célkészlet üresen: a minimum kétszerese');
T::egyezik(8.0, $R::javasolt($t), 'javasolt: a célig (10 − 2)');
$wpdb->update($T('termek'), ['cel_keszlet' => 20], ['id' => $p]);
$t = SDH_Muhely_Termek::egy($p);
T::egyezik(18.0, $R::javasolt($t), 'megadott célkészlettel: 20 − 2');
$j = $R::javaslat();
T::igaz(isset($j[$besz]) && (int) $j[$besz][0]['termek']->id === $p, 'a javaslatban, a beszállítója alatt');
T::egyezik(18000.0, (float) $j[$besz][0]['ertek'], 'becsült beszerzési érték');

$wpdb->insert($T('termek'), ['megnevezes' => 'Bőven van T78', 'keszletkezeles' => 1, 'min_keszlet' => 1, 'aktiv' => 1, 'beszallito' => $besz]);
$p2 = (int) $wpdb->insert_id;
SDH_Muhely_Termek::mozgas($p2, 50, 'nyito');
T::egyezik(0.0, $R::javasolt(SDH_Muhely_Termek::egy($p2)), 'ami a minimum fölött van, nem kell');
$wpdb->insert($T('termek'), ['megnevezes' => 'Nem készletes T78', 'keszletkezeles' => 0, 'min_keszlet' => 5, 'aktiv' => 1]);
T::egyezik(0.0, $R::javasolt(SDH_Muhely_Termek::egy((int) $wpdb->insert_id)), 'készletkezelés nélkül nincs javaslat');

/* --- Foglalás a munkalapon ------------------------------------------------------------------------------- */

$u  = t_ugyfel('Raktár T78');
$e  = t_eszkoz($u, 'Apple', 'T78');
$ml = t_munkalap($u, [$e]);
$wpdb->insert(SDH_Muhely_Tetel::tabla(), [
    'munkalap_id' => $ml, 'tipus' => 'termek', 'mozgas' => 'kimeno', 'megnevezes' => 'Kijelző T78', 'termek_id' => $p,
    'mennyiseg' => 3, 'brutto_ar' => 5000, 'brutto_ertek' => 15000, 'letrehozva' => current_time('mysql'),
]);
SDH_Muhely_Termek::munkalap_mozgasok($ml);
T::egyezik(-1.0, (float) SDH_Muhely_Termek::egy($p)->keszlet, 'a munkalap 3 db-ot levont (2 − 3 = −1)');

$f = $R::foglalasok([$p]);
T::egyezik(3.0, (float) ($f[$p]['menny'] ?? 0), 'foglalva: 3 db a nyitott lapon');
T::egyezik([$ml], array_keys($f[$p]['lapok'] ?? []), 'melyik lapon');
T::tartalmaz($R::termek_foglalas(SDH_Muhely_Termek::egy($p)), 'Foglalva nyitott munkalapokon: 3', 'a termék űrlapján a foglalás');
T::egyezik([$p], array_map(static fn ($h): int => (int) $h->id, $R::lap_hianya($ml)), 'a lap hiánya: a kijelző');
T::tartalmaz($R::munkalap_jelzes((object) ['id' => $ml]), 'Nincs elég raktáron', 'a munkalapon jelzés');
T::tartalmaz(t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ml])['nyers'], 'sdh-ml-raktar', 'a munkalap popupjában is');
T::egyezik(21.0, $R::javasolt(SDH_Muhely_Termek::egy($p)), 'a javaslat a negatív készlettel számol (20 − (−1))');

// Lezárt lapon már nem foglalás (felhasználva), de a levonás marad.
$wpdb->update($T('munkalap'), ['allapot' => SDH_Muhely_Munkalap::lezart_kulcs(), 'lezarva' => current_time('Y-m-d')], ['id' => $ml]);
SDH_Muhely_Termek::munkalap_mozgasok($ml);
T::egyezik(0.0, (float) ($R::foglalasok([$p])[$p]['menny'] ?? 0), 'lezárt lapon nincs foglalás');
T::egyezik(-1.0, (float) SDH_Muhely_Termek::egy($p)->keszlet, 'a levonás megmaradt');

/* --- Bevételezés, CSV, felület ----------------------------------------------------------------------------- */

$v = t_ajax('sdh_muhely_raktar_bevet', ['termek' => $p, 'mennyiseg' => '0']);
T::igaz(empty($v['success']), 'nulla mennyiség: elutasítva');
$v = t_ajax('sdh_muhely_raktar_bevet', ['termek' => $p, 'mennyiseg' => '21']);
T::igaz(!empty($v['success']), 'Megérkezett: bevételezve');
T::egyezik(20.0, (float) SDH_Muhely_Termek::egy($p)->keszlet, 'a készlet 20');
T::egyezik('bevet', (string) $wpdb->get_var($wpdb->prepare('SELECT tipus FROM ' . $T('termek_mozgas') . ' WHERE termek_id = %d ORDER BY id DESC LIMIT 1', $p)), 'bevételezés mozgásként');
T::egyezik(0.0, $R::javasolt(SDH_Muhely_Termek::egy($p)), 'utána nem kell rendelni');

$wpdb->update($T('termek'), ['keszlet' => 1], ['id' => $p]);
$csv = $R::csv();
T::tartalmaz($csv, "\xEF\xBB\xBF", 'CSV: UTF-8 BOM (Excel)');
T::tartalmaz($csv, 'Beszállító;Termék;Cikkszám', 'CSV: fejléc');
T::tartalmaz($csv, '"Kijelző T78";"KIJ-T78"', 'CSV: a termék sora');
SDH_Muhely_Termek::keszlet_ujraszamol($p);

$_GET = ['nezet' => 'rendeles'];
ob_start();
SDH_Muhely_Termek::oldal();
$oldal = (string) ob_get_clean();
$_GET = [];
T::tartalmaz($oldal, 'Rendelési javaslat', 'Termékek › Rendelési javaslat nézet');
T::tartalmaz($oldal, 'data-sdh-raktar', 'a javaslat tartalma');

$urlap = t_ajax('sdh_muhely_termekek_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $p])['nyers'];
T::tartalmaz($urlap, 'name="cel_keszlet"', 'a termék űrlapján a célkészlet');

T::vege('t78 raktár (F4)');
