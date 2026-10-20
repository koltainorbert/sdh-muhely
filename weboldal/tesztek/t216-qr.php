<?php
/** t216 – MNB QR: adatformátum (17 mező, 17 LF, HCT/001/1, BIC, IBAN, HUF, érvényesség), hosszkorlát, mátrix ellenőrzése dekóderrel. */
require __DIR__ . '/_t210-seged.php';
t_katalogus_betolt();

$ts = (new DateTimeImmutable('2026-10-27 23:59:59', new DateTimeZone('Europe/Budapest')))->getTimestamp();
$d  = SDH_Sales_Qr::mnb_adat(['bic' => 'OTPVHUHB', 'nev' => 'Próba Kft.', 'iban' => 'HU42 1177 3016 1111 1018 0000 0000', 'osszeg' => 12700, 'ervenyes' => $ts, 'kozlemeny' => 'RFABC1234', 'szamla' => 'R-2026-000001']);
$m  = explode("\n", $d);
T::egyezik(17, substr_count($d, "\n"), 'pontosan 17 LF');
T::egyezik("\n", substr($d, -1), 'az utolsó mező után is LF');
T::egyezik(18, count($m), '17 mező + üres vég');
T::egyezik(['HCT', '001', '1', 'OTPVHUHB', 'Próba Kft.', 'HU42117730161111101800000000', 'HUF12700', '20261027235959+1', '', 'RFABC1234', '', '', 'R-2026-000001', '', '', '', ''], array_slice($m, 0, 17), 'mezők sorrendje és tartalma');
$nyar = SDH_Sales_Qr::mnb_adat(['bic' => 'OTPVHUHB', 'nev' => 'X', 'iban' => 'HU42117730161111101800000000', 'osszeg' => 1, 'ervenyes' => (new DateTimeImmutable('2026-07-01 12:00:00', new DateTimeZone('Europe/Budapest')))->getTimestamp()]);
T::egyezik('20260701120000+2', explode("\n", $nyar)[7], 'nyári időszámítás +2');
$nul = SDH_Sales_Qr::mnb_adat(['bic' => 'OTPVHUHBXXX', 'nev' => 'X', 'iban' => 'HU42117730161111101800000000', 'osszeg' => 0]);
T::egyezik('', explode("\n", $nul)[6], 'összeg nélkül üres mező');
T::egyezik('OTPVHUHBXXX', explode("\n", $nul)[3], '11 jegyű BIC');

$hiba = static function (array $a): string { try { SDH_Sales_Qr::mnb_adat($a); return ''; } catch (InvalidArgumentException $e) { return $e->getMessage(); } };
$alap = ['bic' => 'OTPVHUHB', 'nev' => 'X', 'iban' => 'HU42117730161111101800000000'];
T::tartalmaz($hiba(['bic' => 'OTP'] + $alap), 'BIC', 'rossz BIC');
T::tartalmaz($hiba(['iban' => 'HU42117730161111101800000001'] + $alap), 'IBAN', 'rossz IBAN-ellenőrzőszám');
T::tartalmaz($hiba(['nev' => ''] + $alap), 'neve', 'név kötelező');
$hosszu = SDH_Sales_Qr::mnb_adat(['nev' => str_repeat('Ő', 60), 'kozlemeny' => str_repeat('é', 60)] + $alap);
T::igaz(strlen(explode("\n", $hosszu)[4]) <= 70 && strlen(explode("\n", $hosszu)[9]) <= 70, 'mezőhossz bájtban, UTF-8 határon vágva');
T::igaz(mb_check_encoding($hosszu, 'UTF-8'), 'érvényes UTF-8');
T::igaz(strlen($hosszu) <= 345, 'legfeljebb 345 bájt');
$sortores = SDH_Sales_Qr::mnb_adat(['kozlemeny' => "a\nb"] + $alap);
T::egyezik(17, substr_count($sortores, "\n"), 'sortörés a mezőben nem tör szerkezetet');

// Rendelésből
$r = t_rendeles();
$q = SDH_Sales_Qr::mnb((int) $r['id']);
T::tartalmaz($q, "HUF12573\n", 'rendelés QR: összeg');
T::tartalmaz($q, "\n" . $r['kozlemeny'] . "\n", 'rendelés QR: közlemény');
T::tartalmaz($q, "\nTeszt Kft.\n", 'rendelés QR: cégnév a beállításból');
SDH_Sales_Rendeles::fizetve((int) $r['id'], 'kezi', 'qr-resz', 2573);
T::tartalmaz(SDH_Sales_Qr::mnb((int) $r['id']), "HUF10000\n", 'részfizetés után a hátralék');

// Mátrix: verzió, méret, M szint; MNB-adat ≤ 13. verzió
$mx = SDH_Sales_Qr::matrix($d);
T::igaz($mx['verzio'] <= 13 && $mx['meret'] === $mx['verzio'] * 4 + 17, 'MNB QR legfeljebb 13. verzió (' . $mx['verzio'] . ')');
$mx = SDH_Sales_Qr::matrix(str_repeat('A', 330));
T::igaz($mx['verzio'] <= 13, '345 bájtos adat is belefér a 13-as verzióba (' . $mx['verzio'] . ')');
T::egyezik(14, SDH_Sales_Qr::kapacitas(1), '1-M kapacitás 14 bájt');
T::egyezik(2331, SDH_Sales_Qr::kapacitas(40), '40-M kapacitás 2331 bájt');
T::egyezik(1, SDH_Sales_Qr::matrix(str_repeat('x', 14))['verzio'], '14 bájt → 1. verzió');
T::egyezik(2, SDH_Sales_Qr::matrix(str_repeat('x', 15))['verzio'], '15 bájt → 2. verzió');

// SVG, PNG
$svg = SDH_Sales_Qr::svg($d);
T::igaz(strpos($svg, '<svg') === 0 && strpos($svg, '<script') === false, 'SVG');
$png = SDH_Sales_Qr::png($d, 4, 4);
T::igaz(strncmp($png, "\x89PNG", 4) === 0, 'PNG');

// Dekódolás független dekóderrel (OpenCV), ha elérhető
$dir = sys_get_temp_dir() . '/t216-' . getmypid();
wp_mkdir_p($dir);
$minta = ['mnb' => $d, 'rendeles' => $q, 'ascii' => 'Hello, world! 123', 'hosszu' => str_repeat('0123456789ABCDEF', 40)];
foreach ($minta as $n => $s) { file_put_contents("$dir/$n.png", SDH_Sales_Qr::png($s, 4, 4)); file_put_contents("$dir/$n.txt", $s); }
file_put_contents("$dir/dek.py", "import sys,glob\ntry:\n import cv2\nexcept Exception:\n print('NINCS'); sys.exit(0)\nd=cv2.QRCodeDetector();j=0;n=0\nfor f in sorted(glob.glob(sys.argv[1]+'/*.png')):\n n+=1;i=cv2.imread(f,0);i=cv2.resize(i,None,fx=2,fy=2,interpolation=cv2.INTER_NEAREST);i=cv2.copyMakeBorder(i,20,20,20,20,cv2.BORDER_CONSTANT,value=255)\n v,_,_=d.detectAndDecode(i)\n if v==open(f[:-4]+'.txt',encoding='utf-8').read(): j+=1\n else: print('ROSSZ',f)\nprint(j,n)\n");
$ki = trim((string) shell_exec('python3 -I ' . escapeshellarg("$dir/dek.py") . ' ' . escapeshellarg($dir) . ' 2>/dev/null'));
if ($ki === 'NINCS' || $ki === '') {
    T::igaz(true, 'dekóder nem érhető el – kihagyva');
} else {
    T::egyezik('4 4', substr($ki, -3), 'OpenCV mind a 4 kódot visszafejti (' . $ki . ')');
}
array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);


// Független ellenőrzés (2026-10-20), alacsony 8: az átutalási QR ideiglenes fájlja kitalálhatatlan, saját almappában van.
$qrm = new ReflectionMethod('SDH_Sales_Fizetes_Atutalas', 'qr_fajl');
$qrm->setAccessible(true);
$qro = t_rendeles();
$qrf = (string) $qrm->invoke(null, SDH_Sales_Rendeles::rendeles((int) $qro['id']));
T::igaz($qrf !== '' && preg_match('~/p-[0-9a-f]{24}/atutalas-qr-R-\d{4}-\d{6}\.png$~', $qrf) === 1, 'alacsony-8: véletlen nevű almappa, olvasható csatolmánynév');
if ($qrf !== '' && is_file($qrf)) {
    unlink($qrf);
    rmdir(dirname($qrf));
}

T::vege('t216 QR');
