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
T::egyezik(['kozlemeny'], array_column($e['jelentes']['parositva'], 'mod'), 'Erste: közlemény szerint fizetve');
T::egyezik(['osszeg_nev'], array_column($e['jelentes']['jovahagyasra_var'], 'mod'), 'Erste: összeg + név csak javaslat (kézi jóváhagyásra vár)');

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


/* =====================================================================================================================
 * Független ellenőrzés javításai (2026-10-20): KR-1 kettős könyvelés, M-5 téves párosítás
 * ================================================================================================================== */
global $wpdb;
$tf      = SDH_Sales_Schema::t('fizetes');
$admin_l = static fn (): array => array_values(array_filter($GLOBALS['sdh_t_levelek'], static fn ($l) => in_array('norbi@example.com', (array) $l['to'], true)));
$ma      = wp_date('Y.m.d');
$csv     = static fn (string $sorok): string => "Könyvelés dátuma;Összeg;Devizanem;Partner neve;Közlemény;Tranzakció azonosító\n" . $sorok;

// KR-1/a: részfizetés értesítőből, majd ugyanaz a tétel a kivonatban (banki azonosítóval) → nem számít kétszer
$o = t_rendeles([], 20000);
$GLOBALS['t_fizetve'] = [];
$ert = SDH_Sales_Bank_Import::level_ertelmez("Jóváírás érkezett\nÖsszeg: 10 000 Ft\nPartner: Próba Szerviz Kft.\nKözlemény: " . $o['kozlemeny']);
T::egyezik('', $ert['datum'], 'KR-1: dátum nélküli értesítő dátuma üres (nem a mai nap)');
SDH_Sales_Bank_Import::tetelek_feldolgoz([$ert], 'ertesito');
$e = SDH_Sales_Bank_Import::feldolgoz($csv($ma . ';10000;HUF;Próba Szerviz Kft.;' . $o['kozlemeny'] . ";TX-778899\n"), 'kivonat.csv');
$x = SDH_Sales_Rendeles::rendeles((int) $o['id']);
T::egyezik(10000, (int) $x['fizetett'], 'KR-1: értesítő + kivonat ugyanarról: fizetett 10 000 (nem 20 000)');
T::egyezik('dijbekero', (string) $x['allapot'], 'KR-1: részfizetésből nem lesz „fizetve”');
T::egyezik(0, $GLOBALS['t_fizetve'][(int) $o['id']] ?? 0, 'KR-1: részfizetés nem indít kiszolgálást');
T::igaz(!empty($e['jelentes']['mar_feldolgozva'][0]['masik_csatorna']), 'KR-1: a kivonat tétele „már feldolgozva (másik csatornából)”');
T::egyezik('duplikat', (string) $wpdb->get_var($wpdb->prepare("SELECT allapot FROM {$tf} WHERE tranzakcio = %s", 'ref:TX-778899')), 'KR-1: a kivonat tétele duplikátumként rögzítve (nem könyvelve)');
// … a második, valódi 10 000 (más banki azonosítóval) már számít → fizetve, a hook egyszer
SDH_Sales_Bank_Import::feldolgoz($csv($ma . ';10000;HUF;Próba Szerviz Kft.;' . $o['kozlemeny'] . ";TX-778900\n"), 'kivonat2.csv');
$x = SDH_Sales_Rendeles::rendeles((int) $o['id']);
T::igaz((int) $x['fizetett'] === 20000 && (string) $x['allapot'] === 'fizetve', 'KR-1: a második valódi részlet után fizetve (20 000)');
T::egyezik(1, $GLOBALS['t_fizetve'][(int) $o['id']] ?? 0, 'KR-1: a fizetve-hook pontosan egyszer');

// KR-1/b: teljes fizetés értesítőből, majd kivonatból → nincs „Túlfizetés … Visszautalás” levél, nincs dupla összeg
$o = t_rendeles([], 12573);
$GLOBALS['sdh_t_levelek'] = [];
$ert = SDH_Sales_Bank_Import::level_ertelmez("Jóváírás\nÖsszeg: 12 573 Ft\nKözlemény: " . $o['kozlemeny']);
SDH_Sales_Bank_Import::tetelek_feldolgoz([$ert], 'ertesito');
SDH_Sales_Bank_Import::feldolgoz($csv($ma . ';12573;HUF;Próba Szerviz Kft.;' . $o['kozlemeny'] . ';TX-' . wp_rand() . "\n"), 'k.csv');
$x = SDH_Sales_Rendeles::rendeles((int) $o['id']);
T::egyezik(12573, (int) $x['fizetett'], 'KR-1: teljes fizetés két csatornán: fizetett = bruttó (nem a kétszerese)');
T::igaz(array_filter($admin_l(), static fn ($l) => stripos((string) $l['subject'] . (string) $l['message'], 'visszautal') !== false) === [], 'KR-1: nincs „visszautalás szükséges” levél');
// ugyanaz az értesítő másnap újra bemásolva (dátummező nélkül) → már feldolgozva
$j = SDH_Sales_Bank_Import::tetelek_feldolgoz([SDH_Sales_Bank_Import::level_ertelmez("Jóváírás\nÖsszeg: 12 573 Ft\nKözlemény: " . $o['kozlemeny'])], 'ertesito');
T::egyezik(1, count($j['jelentes']['mar_feldolgozva']), 'KR-1: újra bemásolt értesítő: már feldolgozva');
// ugyanaz a kivonat más formátumban (banki azonosító nélkül, azonos nap / összeg / partner / közlemény) → már feldolgozva
$j = SDH_Sales_Bank_Import::feldolgoz("Dátum;Összeg;Partner neve;Közlemény\n" . $ma . ';12573;Próba Szerviz Kft.;' . $o['kozlemeny'] . "\n", 'masik.csv');
T::egyezik(1, count($j['jelentes']['mar_feldolgozva']), 'KR-1: ugyanaz a kivonat más formátumban: már feldolgozva');
T::egyezik(12573, (int) SDH_Sales_Rendeles::rendeles((int) $o['id'])['fizetett'], 'KR-1: a fizetett összeg változatlan');
// kifizetett rendelésre később érkező, más napi tétel → nem könyvelődik, „ellenőrizendő” + levél (nem visszautalás)
$GLOBALS['sdh_t_levelek'] = [];
$j = SDH_Sales_Bank_Import::feldolgoz($csv(wp_date('Y.m.d', time() + 3 * DAY_IN_SECONDS) . ';12573;HUF;Próba Szerviz Kft.;' . $o['kozlemeny'] . ";TX-KESOBBI-1\n"), 'k3.csv');
T::egyezik(1, count($j['jelentes']['ellenorizendo']), 'KR-1: kifizetett rendelésre újabb tétel: ellenőrizendő');
T::egyezik(12573, (int) SDH_Sales_Rendeles::rendeles((int) $o['id'])['fizetett'], 'KR-1: az ellenőrizendő tétel nem könyvelődik');
T::igaz(count($admin_l()) === 1 && stripos((string) $admin_l()[0]['subject'], 'Ellenőrizendő') !== false, 'KR-1: egy „Ellenőrizendő befizetés” levél');

// KR-1/c: Számlázz.hu IPN (halmozott) és kézi „Fizetve”, utána a kivonat → nem számít kétszer
$o = t_rendeles([], 30000);
SDH_Sales_Rendeles::fizetve((int) $o['id'], 'szamlazz', 'ipn:D-T214:30000', 30000);
SDH_Sales_Bank_Import::feldolgoz($csv($ma . ';10000;HUF;Próba Szerviz Kft.;' . $o['kozlemeny'] . ";TX-IPN-1\n" . $ma . ';20000;HUF;Próba Szerviz Kft.;' . $o['kozlemeny'] . ";TX-IPN-2\n"), 'ipn.csv');
T::egyezik(30000, (int) SDH_Sales_Rendeles::rendeles((int) $o['id'])['fizetett'], 'KR-1: IPN (halmozott 30 000) + kivonat (10 000 + 20 000): fizetett 30 000');
$o = t_rendeles([], 15000);
SDH_Sales_Rendeles::fizetve((int) $o['id'], 'kezi', 'kezi-admin-' . time(), 15000);
SDH_Sales_Bank_Import::feldolgoz($csv($ma . ';15000;HUF;Próba Szerviz Kft.;' . $o['kozlemeny'] . ";TX-KEZI-1\n"), 'kezi.csv');
T::egyezik(15000, (int) SDH_Sales_Rendeles::rendeles((int) $o['id'])['fizetett'], 'KR-1: kézi „Fizetve” + kivonat: fizetett 15 000');

// M-5/a: összeg + közös általános szó („Szerviz”) más cégtől → nem párosít, nem fizet
$m = t_rendeles(['vevo' => t_vevo(['ceg' => 'Mobil Szerviz Bt.', 'kapcsolattarto' => 'Tóth Anna'])], 31338);
$j = SDH_Sales_Bank_Import::tetelek_feldolgoz([['datum' => wp_date('Y-m-d'), 'osszeg' => 31338, 'penznem' => 'HUF', 'partner' => 'Kovács Szerviz Kft.', 'partner_sz' => '', 'kozlemeny' => 'számla 2026/117', 'ref' => 'TX-P4-' . wp_rand(), 'sor' => 1]], 'csv');
T::igaz($j['jelentes']['parositva'] === [] && $j['jelentes']['jovahagyasra_var'] === [], 'M-5: „Kovács Szerviz” utalása nem párosul a „Mobil Szerviz” rendeléssel');
T::egyezik('dijbekero', (string) SDH_Sales_Rendeles::rendeles((int) $m['id'])['allapot'], 'M-5: a rendelés nem lett fizetve');
T::igaz(!SDH_Sales_Bank_Import::nev_hasonlo('Tóth Anna', 'Mobil Szerviz Bt.'), 'M-5: a kapcsolattartó neve nem számít');
// M-5/b: erős névegyezés + összeg → csak javaslat (kézi jóváhagyás), a rendelés nem fizetett, nincs hook
$m = t_rendeles(['vevo' => t_vevo(['ceg' => 'Gyöngyvirág Mobil Kft.'])], 27777);
$GLOBALS['t_fizetve'] = [];
$j = SDH_Sales_Bank_Import::tetelek_feldolgoz([['datum' => wp_date('Y-m-d'), 'osszeg' => 27777, 'penznem' => 'HUF', 'partner' => 'GYÖNGYVIRÁG MOBIL KFT', 'partner_sz' => '', 'kozlemeny' => '-', 'ref' => 'TX-JAV-' . wp_rand(), 'sor' => 1]], 'csv');
T::egyezik(1, count($j['jelentes']['jovahagyasra_var']), 'M-5: összeg + név: „kézi jóváhagyásra vár”');
$x = SDH_Sales_Rendeles::rendeles((int) $m['id']);
T::igaz((int) $x['fizetett'] === 0 && (string) $x['allapot'] === 'dijbekero' && empty($GLOBALS['t_fizetve'][(int) $m['id']]), 'M-5: javaslatból nincs fizetés és kiszolgálás');
$fid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tf} WHERE rendeles_id = %d AND allapot = 'jovahagyasra_var'", (int) $m['id']));
T::igaz($fid > 0, 'M-5: a javaslat a rendelésen látszik (fizetés-sor jóváhagyásra vár)');
$lista = t_rest('GET', '/admin/bank/fuggo')->get_data();
T::igaz(in_array($fid, array_column((array) $lista, 'id'), true), 'M-5: a függő tételek listáján (admin REST)');
T::egyezik(401, t_latogato(static fn () => t_rest('GET', '/admin/bank/fuggo'))->get_status(), 'M-5: a függő lista csak adminnak');
$v = t_rest('POST', '/admin/bank/fizetes/' . $fid . '/jovahagy', []);
T::igaz($v->get_status() === 200 && (string) SDH_Sales_Rendeles::rendeles((int) $m['id'])['allapot'] === 'fizetve', 'M-5: admin jóváhagyás után fizetve');
T::egyezik(409, t_rest('POST', '/admin/bank/fizetes/' . $fid . '/jovahagy', [])->get_status(), 'M-5: másodszor nem hagyható jóvá');
// elutasítás
$m2 = t_rendeles(['vevo' => t_vevo(['ceg' => 'Napraforgó Telefon Kft.'])], 26666);
SDH_Sales_Bank_Import::tetelek_feldolgoz([['datum' => wp_date('Y-m-d'), 'osszeg' => 26666, 'penznem' => 'HUF', 'partner' => 'Napraforgó Telefon Kft', 'partner_sz' => '', 'kozlemeny' => '-', 'ref' => 'TX-ELU-' . wp_rand(), 'sor' => 1]], 'csv');
$fid2 = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tf} WHERE rendeles_id = %d AND allapot = 'jovahagyasra_var'", (int) $m2['id']));
T::igaz(t_rest('POST', '/admin/bank/fizetes/' . $fid2 . '/elutasit', [])->get_status() === 200 && (string) SDH_Sales_Rendeles::rendeles((int) $m2['id'])['allapot'] === 'dijbekero', 'M-5: elutasítva – a rendelés nyitott marad');
// M-5/c: rendelésazonosító csak pontos szóként
$c = t_rendeles([], 15000);
$szam = substr((string) $c['azon'], 7);
$t5 = ['datum' => wp_date('Y-m-d'), 'osszeg' => 500, 'penznem' => 'HUF', 'partner' => 'Valaki Más', 'partner_sz' => '', 'ref' => 'x', 'sor' => 1];
T::egyezik(null, SDH_Sales_Bank_Import::parosit($t5 + ['kozlemeny' => 'Megrendelés nr ' . wp_date('Y') . ' ' . $szam . ' (saját belső szám)'])['rendeles'], 'M-5: „nr 2026 000004” nem rendelésazonosító');
T::egyezik(null, SDH_Sales_Bank_Import::parosit($t5 + ['kozlemeny' => 'R' . wp_date('Y') . $szam])['rendeles'], 'M-5: kötőjel nélkül nem rendelésazonosító');
T::egyezik(null, SDH_Sales_Bank_Import::parosit($t5 + ['kozlemeny' => 'XR-' . wp_date('Y') . '-' . $szam . '9'])['rendeles'], 'M-5: hosszabb szám része nem rendelésazonosító');
T::egyezik('azon', SDH_Sales_Bank_Import::parosit($t5 + ['kozlemeny' => 'Rendelés: ' . $c['azon'] . '.'])['mod'], 'M-5: pontos „R-ÉÉÉÉ-NNNNNN” szó: párosít');
T::egyezik('tobb_jelolt', SDH_Sales_Bank_Import::parosit($t5 + ['kozlemeny' => $c['azon'] . ' és ' . $az[1]])['mod'], 'M-5: két különböző azonosító: nem egyértelmű');
$rossz = substr((string) $k[1], 0, -1) . ((substr((string) $k[1], -1) === 'A') ? 'C' : 'A');
T::egyezik(null, SDH_Sales_Bank_Import::parosit($t5 + ['kozlemeny' => $rossz])['rendeles'], 'M-5: rossz ellenőrző karakterű közlemény nem párosít');

T::vege('t214 banki import');
