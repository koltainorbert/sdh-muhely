<?php
/** t214 – banki import: OTP / K&H / Erste / Wise / MBH / Raiffeisen CSV (Windows-1250 és UTF-8), párosítási módok, jelentés, ismétlés, értesítő e-mail. */
require __DIR__ . '/_t210-seged.php';
t_katalogus_betolt();

$A = __DIR__ . '/anyag/bank/';
$o = []; $k = []; $az = [];
for ($i = 1; $i <= 11; $i++) {
    $r = t_rendeles(['vevo' => t_vevo(['ceg' => ['', 'Próba Szerviz Kft.', 'Másik Bt.', 'Harmadik Szerviz Kft.', 'Negyedik Kft.', 'Ötödik Kft.', 'Hatodik Szerviz Kft.', 'Hetedik Kft.', 'Nyolcadik Kft.', 'Kilencedik Kft.', 'Tizedik Kft.', 'Tizenegyedik Kft.'][$i]])], 10000 + $i * 1111);
    $o[$i] = 10000 + $i * 1111; $k[$i] = $r['kozlemeny']; $az[$i] = $r['azon']; $id[$i] = (int) $r['id'];
}
$tolt = static function (string $f, bool $cp = false) use ($A, $o, $k, $az): string {
    $s = (string) file_get_contents($A . $f);
    $s = $cp ? (string) iconv('WINDOWS-1250', 'UTF-8', $s) : $s;
    $csere = [];
    foreach ($o as $i => $x) { $csere['{O' . $i . '}'] = (string) $x; $csere['{K' . $i . '}'] = $k[$i]; $csere['{AZ' . $i . '}'] = $az[$i]; }
    // Normalizált közlemény: kisbetűs, szóközzel tagolt
    $csere['{K2N}'] = strtolower(substr($k[2], 0, 4) . ' ' . substr($k[2], 4, 3) . '-' . substr($k[2], 7));
    $s = strtr($s, $csere);
    return $cp ? (string) iconv('UTF-8', 'WINDOWS-1250', $s) : $s;
};

// Kódolásfelismerés
T::egyezik('Árvíztűrő', SDH_Sales_Bank_Import::utf8((string) iconv('UTF-8', 'WINDOWS-1250', 'Árvíztűrő')), 'Windows-1250 → UTF-8');
T::egyezik('Árvíztűrő', SDH_Sales_Bank_Import::utf8("\xEF\xBB\xBFÁrvíztűrő"), 'UTF-8 BOM levágva');
foreach (['12 700,00' => 12700, '+12.700,50 Ft' => 12701, '-1 234' => -1234, '12,700.00' => 12700, '15000' => 15000, '1.234.567' => 1234567, 'abc' => null, "12\u{00A0}700 HUF" => 12700] as $be => $v) {
    T::egyezik($v, SDH_Sales_Bank_Import::osszeg((string) $be), 'összeg: ' . $be);
}

// OTP (fejléc nélküli, Windows-1250): pontos közlemény + normalizált közlemény, terhelés kihagyva
$e = SDH_Sales_Bank_Import::feldolgoz($tolt('otp.csv', true), 'otp.csv');
T::egyezik('otp', $e['formatum'], 'OTP formátum felismerve');
T::egyezik(2, count($e['jelentes']['parositva']), 'OTP: 2 párosítva');
T::egyezik('kozlemeny', $e['jelentes']['parositva'][0]['mod'], 'OTP: pontos közlemény');
T::egyezik('kozlemeny_normalizalt', $e['jelentes']['parositva'][1]['mod'], 'OTP: normalizált közlemény (kisbetű, szóköz, kötőjel)');
T::egyezik(1, $e['jelentes']['kihagyva'], 'OTP: terhelés kihagyva');
T::egyezik('PRÓBA SZERVIZ KFT.', $e['jelentes']['parositva'][0]['partner'], 'OTP: ékezetes név helyesen');
T::egyezik('fizetve', SDH_Sales_Rendeles::rendeles($id[1])['allapot'], 'OTP: rendelés fizetve');

// Ugyanaz a fájl újra: minden „már feldolgozva”
$e = SDH_Sales_Bank_Import::feldolgoz($tolt('otp.csv', true), 'otp.csv');
T::egyezik(2, count($e['jelentes']['mar_feldolgozva']), 'OTP újra: 2 már feldolgozva');
T::egyezik(0, count($e['jelentes']['parositva']), 'OTP újra: 0 új');

// K&H (fejléc előtti sorok, Windows-1250): rendelésazonosító a közleményben
$e = SDH_Sales_Bank_Import::feldolgoz($tolt('kh.csv', true), 'kh.csv');
T::egyezik('kh', $e['formatum'], 'K&H formátum');
T::egyezik('azon', $e['jelentes']['parositva'][0]['mod'] ?? '', 'K&H: rendelésazonosító szerint');
T::egyezik(1, $e['jelentes']['kihagyva'], 'K&H: kártyás vásárlás kihagyva');

// Erste (UTF-8 BOM, vessző): pontos + összeg+név
$e = SDH_Sales_Bank_Import::feldolgoz($tolt('erste.csv'), 'erste.csv');
T::egyezik('erste', $e['formatum'], 'Erste formátum');
T::egyezik(['kozlemeny', 'osszeg_nev'], array_column($e['jelentes']['parositva'], 'mod'), 'Erste: közlemény + összeg/név');

// Wise: HUF párosítva, EUR kihagyva
$e = SDH_Sales_Bank_Import::feldolgoz($tolt('wise.csv'), 'wise.csv');
T::egyezik('wise', $e['formatum'], 'Wise formátum');
T::egyezik(1, count($e['jelentes']['parositva']), 'Wise: 1 párosítva');
T::egyezik(1, $e['jelentes']['kihagyva'], 'Wise: EUR kihagyva');

// MBH: külön jóváírás / terhelés oszlop
$e = SDH_Sales_Bank_Import::feldolgoz($tolt('mbh.csv', true), 'mbh.csv');
T::egyezik('mbh', $e['formatum'], 'MBH formátum');
T::egyezik(1, count($e['jelentes']['parositva']), 'MBH: 1 párosítva');
T::egyezik(1, $e['jelentes']['kihagyva'], 'MBH: díj kihagyva');

// Raiffeisen: T/J oszlop
$e = SDH_Sales_Bank_Import::feldolgoz($tolt('raiffeisen.csv', true), 'raiffeisen.csv');
T::egyezik('raiffeisen', $e['formatum'], 'Raiffeisen formátum');
T::egyezik(1, count($e['jelentes']['parositva']), 'Raiffeisen: 1 párosítva');

// Összegeltérés: kevesebb jött
$r = t_rendeles([], 20000);
$e = SDH_Sales_Bank_Import::feldolgoz("Dátum;Összeg;Partner neve;Közlemény\n2026.10.19;15000;Valaki Kft;" . $r['kozlemeny'] . "\n");
T::egyezik(1, count($e['jelentes']['osszeg_elteres']), 'összegeltérés jelentve');
T::egyezik(20000, $e['jelentes']['osszeg_elteres'][0]['vart'] ?? 0, 'várt összeg a jelentésben');
T::egyezik('dijbekero', SDH_Sales_Rendeles::rendeles((int) $r['id'])['allapot'], 'részfizetés: nyitott marad');

// Nem párosítható + azonos tételek kétszer egy fájlban (két külön befizetés)
$e = SDH_Sales_Bank_Import::feldolgoz("Dátum;Összeg;Partner neve;Közlemény\n2026.10.19;777;Ismeretlen Bt;semmi\n2026.10.19;777;Ismeretlen Bt;semmi\n");
T::egyezik(2, count($e['jelentes']['nem_parosithato']), 'két azonos, nem párosítható tétel külön');
$e = SDH_Sales_Bank_Import::feldolgoz("Dátum;Összeg;Partner neve;Közlemény\n2026.10.19;777;Ismeretlen Bt;semmi\n2026.10.19;777;Ismeretlen Bt;semmi\n");
T::egyezik(2, count($e['jelentes']['mar_feldolgozva']), 'újra beolvasva: már feldolgozva');

// Összeg+név nem egyértelmű → nem párosít
t_rendeles(['vevo' => t_vevo(['ceg' => 'Ikertestvér Kft.'])], 31337);
t_rendeles(['vevo' => t_vevo(['ceg' => 'Ikertestvér Kft.'])], 31337);
$e = SDH_Sales_Bank_Import::feldolgoz("Dátum;Összeg;Partner neve;Közlemény\n2026.10.20;31337;IKERTESTVÉR KFT;-\n");
T::egyezik('tobb_jelolt', $e['jelentes']['nem_parosithato'][0]['mod'] ?? '', 'két jelölt: nem párosít');

// Felismerhetetlen fájl
$e = SDH_Sales_Bank_Import::feldolgoz("a;b;c\n1;2;3\n");
T::igaz($e['hibak'] !== [], 'ismeretlen oszlopok: hibaüzenet');

// Banki értesítő (bemásolt e-mail) – REST
$szoveg = $tolt('ertesito.txt');
$v = t_rest('POST', '/admin/bank/level', ['szoveg' => $szoveg]);
T::egyezik(200, $v->get_status(), 'értesítő REST 200');
T::egyezik(1, count($v->get_data()['jelentes']['parositva'] ?? []), 'értesítő párosítva');
T::egyezik(1, count(t_rest('POST', '/admin/bank/level', ['szoveg' => $szoveg])->get_data()['jelentes']['mar_feldolgozva']), 'értesítő másodszor: már feldolgozva');
T::egyezik(422, t_rest('POST', '/admin/bank/level', ['szoveg' => 'Kártyás vásárlás: -4 990 Ft, TESCO'])->get_status(), 'terhelésről szóló értesítő: 422');
T::egyezik(401, t_latogato(static fn () => t_rest('POST', '/admin/bank/level', ['szoveg' => $szoveg]))->get_status(), 'értesítő: csak admin');

// Import REST (base64)
$v = t_rest('POST', '/admin/bank/import', ['tartalom' => 'base64:' . base64_encode($tolt('otp.csv', true)), 'fajlnev' => 'otp.csv']);
T::egyezik(200, $v->get_status(), 'import REST 200');
T::egyezik(415, t_rest('POST', '/admin/bank/import', ['tartalom' => 'x', 'fajlnev' => 'a.exe'])->get_status(), 'rossz kiterjesztés 415');
T::igaz(SDH_Sales_Naplo::lista(['tipus' => 'bank_import'])['ossz'] >= 5, 'importok naplózva');

T::vege('t214 banki import');
