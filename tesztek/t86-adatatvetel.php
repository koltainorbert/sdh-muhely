<?php
/**
 * t86 – U1 (0.94.0): adatátvétel – kódolás, elválasztó, XLSX, párosítás, próba, átvétel, ismétlés, visszavonás.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$A = 'SDH_Muhely_Adatatvetel';
$T = static fn (string $t): string => SDH_Muhely_Schema::tabla($t);
$r = (string) wp_rand(100000, 999999);
$szam0 = (int) ('9' . $r);

T::igaz(version_compare(SDH_Muhely_Schema::DB_VERSION, '0.53.0', '>='), 'sémaverzió 0.53.0');

/* --- Értékek ----------------------------------------------------------------------------------------------- */

T::egyezik('2019-03-05', $A::datum('2019.03.05.'), 'dátum: pontokkal');
T::egyezik('2019-03-05', $A::datum('05.03.2019'), 'dátum: nap.hó.év');
T::egyezik('2019-03-05', $A::datum('2019-03-05 14:22'), 'dátum: idővel');
T::egyezik('2019-03-05', $A::datum('43529'), 'dátum: Excel-sorszám');
T::egyezik('', $A::datum('2019.02.30'), 'hibás dátum: üres');
T::egyezik(10400.5, $A::szam('10 400,50 Ft'), 'pénz: szóköz, vessző, Ft');
T::egyezik(1234.5, $A::szam('1.234,50'), 'pénz: ezres pont');
T::egyezik(1234.5, $A::szam('1,234.50'), 'pénz: angol alak');
T::egyezik(SDH_Muhely_Munkalap::lezart_kulcs(), $A::allapot('Kiadva'), 'állapot: Kiadva → lezárt');
T::egyezik('fuggo', $A::allapot('Alkatrészre vár'), 'állapot: alkatrészre vár → függő');
T::egyezik(SDH_Muhely_Munkalap::alap_kulcs(SDH_Muhely_Munkalap::allapotok()), $A::allapot('Valami más'), 'ismeretlen: az alapállapot');
T::egyezik('arajanlat', $A::allapot('Egyedi', ['Egyedi' => 'arajanlat']), 'saját párosítás');

/* --- 1. Ügyfelek: Windows-1250, pontosvessző ---------------------------------------------------------------- */

$dir = sys_get_temp_dir();
$csv = "Azonosító;Név;Telefonszám;E-mail;Irányítószám;Település;Cím;Adószám\n"
    . "U{$r}1;Árvíztűrő Tükörfúrógép {$r};+36 30 111 {$r};arviz{$r}@example.com;8200;Veszprém;Fő utca 1.;\n"
    . "U{$r}2;Kovács Kft {$r};06-30-222-{$r};;8200;Veszprém;Kossuth u. 2.;12345678-2-19\n"
    . "U{$r}3;;06301234567;;;;;\n";
$f1 = $dir . "/t86-ugyfel-{$r}.csv";
file_put_contents($f1, iconv('UTF-8', 'CP1250', $csv));
$b1 = $A::fajl_be($f1, 'ugyfel.csv', 'ugyfel');
T::igaz(is_array($b1), 'ügyfél-fájl beolvasva');
T::egyezik(3, $b1['sorok'] ?? 0, '3 adatsor');
T::egyezik(';', $b1['elvalaszto'] ?? '', 'elválasztó: pontosvessző');
T::egyezik('Árvíztűrő Tükörfúrógép ' . $r, $b1['minta'][0][1] ?? '', 'Windows-1250 → UTF-8 (ékezetek)');
$p1 = $b1['javaslat'];
T::egyezik(0, $p1['kulso_azonosito'], 'javaslat: Azonosító → régi azonosító');
T::egyezik(1, $p1['nev'], 'javaslat: Név');
T::egyezik(2, $p1['telefon'], 'javaslat: Telefonszám');
T::egyezik(7, $p1['adoszam'], 'javaslat: Adószám');
$fa1 = $A::fajl((string) $b1['id']);
$pr  = $A::proba($fa1, $p1);
T::egyezik([3, 2, 1], [$pr['sorok'], $pr['jo'], $pr['hibas']], 'próba: 3 sor, 2 jó, 1 hibás');
T::tartalmaz(implode(' ', $pr['hibak']), '4. sor: hiányzik: Név', 'a hiba sora és oka');
$elotte = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $T('ugyfel'));
T::egyezik($elotte, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $T('ugyfel')), 'a próba nem ír');
$i1 = 'a' . $r . 'u';
$ad = $A::adag($fa1, $p1, $i1, 0);
T::egyezik([2, 0, 1, true], [$ad['uj'], $ad['meglevo'], $ad['hibas'], $ad['kesz']], 'átvétel: 2 új, 1 hibás, kész');
$u1 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('ugyfel') . ' WHERE kulso_azonosito = %s', "U{$r}1"));
T::egyezik('munkalap3', (string) $u1->forras, 'forrás: munkalap3');
T::igaz(str_starts_with((string) $u1->ugyfel_szam, 'U-'), 'ügyfélszám kiosztva');
T::igaz((string) $u1->kereso !== '', 'a kereső-index feltöltve');
T::egyezik('ceg', (string) $wpdb->get_var($wpdb->prepare('SELECT tipus FROM ' . $T('ugyfel') . ' WHERE kulso_azonosito = %s', "U{$r}2")), 'adószámmal: cég');
$ad2 = $A::adag($fa1, $p1, 'a' . $r . 'x', 0);
T::egyezik([0, 2], [$ad2['uj'], $ad2['meglevo']], 'ismételt átvétel: nem duplikál');

/* --- 2. Eszközök: UTF-8, tabulátor ------------------------------------------------------------------------- */

$f2 = $dir . "/t86-eszkoz-{$r}.txt";
file_put_contents($f2, "\xEF\xBB\xBFTárgy azonosító\tÜgyfél azonosító\tMárka\tTípus\tIMEI\tFeloldó kód\n"
    . "E{$r}1\tU{$r}1\tApple\tiPhone 13\t35 1234 5678 9012\t1234\n"
    . "E{$r}2\tU{$r}9\tSamsung\tA52\t\t\n");
$b2 = $A::fajl_be($f2, 'targy.txt', 'eszkoz');
T::egyezik('tabulátor', $b2['elvalaszto'] ?? '', 'elválasztó: tabulátor');
T::egyezik(4, $b2['javaslat']['imei'], 'javaslat: IMEI');
T::egyezik(5, $b2['javaslat']['zarkod'], 'javaslat: Feloldó kód');
$ad = $A::adag($A::fajl((string) $b2['id']), $b2['javaslat'], 'a' . $r . 'e', 0);
T::egyezik([1, 1], [$ad['uj'], $ad['hibas']], 'eszköz: 1 új, 1 hibás (ismeretlen ügyfél)');
T::tartalmaz(implode(' ', $ad['hibak']), 'előbb az ügyfeleket', 'érthető hiba');
$e1 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('eszkoz') . ' WHERE kulso_azonosito = %s', "E{$r}1"));
T::egyezik((int) $u1->id, (int) $e1->ugyfel_id, 'az eszköz az ügyfélhez kötve');
T::egyezik('351234567890 12', substr((string) $e1->imei, 0, 12) . ' ' . substr((string) $e1->imei, 12), 'IMEI szóközök nélkül');
T::igaz(SDH_Muhely_Zarkod::olvas((string) $e1->zarkod) === '1234' && (string) $e1->zarkod !== '1234', 'a feloldó kód titkosítva');

/* --- 3. Munkalapok: XLSX ------------------------------------------------------------------------------------ */

$cella = static function (array $sor, int $n): string {
    $x = '<row r="' . $n . '">';

    foreach (array_values($sor) as $i => $v) {
        $x .= '<c r="' . chr(65 + $i) . $n . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $v, ENT_XML1) . '</t></is></c>';
    }

    return $x . '</row>';
};
$sorok = [
    ['Azonosító', 'Munkalapszám', 'Ügyfél azonosító', 'Tárgy azonosító', 'Állapot', 'Felvétel dátuma', 'Kiadva', 'Bruttó', 'Hibaleírás'],
    ["M{$r}1", (string) $szam0, "U{$r}1", "E{$r}1", 'Kiadva', '2019.03.05.', '2019.03.08.', '10 400', 'Nem tölt'],
    ["M{$r}2", (string) ($szam0 + 1), "U{$r}2", '', 'Javítás alatt', '2019.04.01.', '', '5000', ''],
];
$xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
foreach ($sorok as $i => $s) {
    $xml .= $cella($s, $i + 1);
}
$xml .= '</sheetData></worksheet>';
$f3 = $dir . "/t86-munkalap-{$r}.xlsx";
$z  = new ZipArchive();
$z->open($f3, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('xl/worksheets/sheet1.xml', $xml);
$z->close();
$b3 = $A::fajl_be($f3, 'munkalap.xlsx', 'munkalap');
T::egyezik('XLSX', $b3['kodolas'] ?? '', 'XLSX olvasva');
T::egyezik(2, $b3['sorok'] ?? 0, '2 munkalap');
$p3 = $b3['javaslat'];
T::egyezik(1, $p3['munkalap_szam'], 'javaslat: Munkalapszám');
T::egyezik(6, $p3['lezarva'], 'javaslat: Kiadva → lezárva');
$fa3 = $A::fajl((string) $b3['id']);
$pr = $A::proba($fa3, $p3);
T::egyezik(15400.0, (float) $pr['osszeg'], 'próba: bruttó összesen 15 400');
$ad = $A::adag($fa3, $p3, 'a' . $r . 'm', 0);
T::egyezik(2, $ad['uj'], '2 munkalap átvéve');
$m1 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('munkalap') . ' WHERE kulso_azonosito = %s', "M{$r}1"));
T::egyezik([SDH_Muhely_Munkalap::lezart_kulcs(), '2019-03-05', '2019-03-08', 10400.0, (int) $e1->id], [(string) $m1->allapot, (string) $m1->keszult, (string) $m1->lezarva, (float) $m1->brutto_ertek, (int) $m1->eszkoz_id], 'a munkalap adatai');
T::egyezik('Nem tölt', (string) $m1->ugyfel_megjegyzes, 'a hibaleírás');
T::igaz(SDH_Muhely_Munkalap::kovetkezo_szam() > $szam0 + 1, 'a következő munkalapszám az átvettek után jön');

/* --- 4. Tételek és 5. hibák -------------------------------------------------------------------------------- */

$f4 = $dir . "/t86-tetel-{$r}.csv";
file_put_contents($f4, "Munkalap;Megnevezés;Típus;Mennyiség;Bruttó egységár;ÁFA\n{$szam0};Kijelzőcsere;Szolgáltatás;1;8000;27\n{$szam0};Kijelző;Termék;2;1 200;27\n");
$b4 = $A::fajl_be($f4, 'tetel.csv', 'tetel');
$ad = $A::adag($A::fajl((string) $b4['id']), $b4['javaslat'], 'a' . $r . 't', 0);
T::egyezik(2, $ad['uj'], '2 tétel (a munkalapot a száma alapján is megtalálja)');
$tt = $wpdb->get_results($wpdb->prepare('SELECT tipus, brutto_ertek FROM ' . SDH_Muhely_Tetel::tabla() . ' WHERE munkalap_id = %d ORDER BY id', (int) $m1->id));
T::egyezik([['szolgaltatas', 8000.0], ['termek', 2400.0]], array_map(static fn ($t): array => [(string) $t->tipus, (float) $t->brutto_ertek], $tt), 'típus és érték (2 × 1200)');

$f5 = $dir . "/t86-hiba-{$r}.csv";
file_put_contents($f5, "Munkalap azonosító,Hiba,Javítás\nM{$r}1,Nem tölt,Töltőcsatlakozó csere\nM{$r}1,Nem tölt,Töltőcsatlakozó csere\n");
$b5 = $A::fajl_be($f5, 'hiba.csv', 'hiba');
T::egyezik(',', $b5['elvalaszto'] ?? '', 'elválasztó: vessző');
$ad = $A::adag($A::fajl((string) $b5['id']), $b5['javaslat'], 'a' . $r . 'h', 0);
T::egyezik([1, 1], [$ad['uj'], $ad['meglevo']], 'hiba: az azonos sor nem duplázódik');

/* --- Visszavonás ------------------------------------------------------------------------------------------- */

T::egyezik(2, $A::visszavon('a' . $r . 't'), 'tételek visszavonva');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Muhely_Tetel::tabla() . ' WHERE munkalap_id = %d', (int) $m1->id)), 'a tételek törölve');
$A::visszavon('a' . $r . 'h');
$A::visszavon('a' . $r . 'm');
$A::visszavon('a' . $r . 'e');
$A::visszavon($i1);
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('ugyfel') . ' WHERE kulso_azonosito LIKE %s', 'U' . $r . '%')), 'az ügyfelek is törölve');
$n = get_option($A::OPT_NAPLO);
T::igaz(!empty($n['a' . $r . 'm']['visszavonva']), 'a naplóban: visszavonva');

/* --- Felület, súgó ------------------------------------------------------------------------------------------ */

T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['adatatvetel']), 'Beállítások › Adatátvétel');
ob_start();
$A::beallitas_doboz();
T::tartalmaz((string) ob_get_clean(), 'data-sdh-atvetel-feltolt', 'a lapfül');
$_FILES = ['fajl' => ['name' => 'u.csv', 'type' => 'text/csv', 'tmp_name' => $f1, 'error' => 0, 'size' => filesize($f1)]];
$v = t_ajax('sdh_muhely_atvetel_feltolt', ['entitas' => 'ugyfel'], '_wpnonce', 'sdh_muhely_atvetel');
$_FILES = [];
T::igaz(!empty($v['success']) && count($v['data']['mezok'] ?? []) > 5 && !isset($v['data']['ut']), 'AJAX feltöltés (a szerver útvonala nem megy ki)');

foreach ([$f1, $f2, $f3, $f4, $f5] as $f) {
    @unlink($f); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
foreach ((array) get_option($A::OPT_FAJLOK, []) as $fa) {
    @unlink((string) $fa['ut']); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
delete_option($A::OPT_FAJLOK);

T::vege('t86 adatátvétel (U1)');
