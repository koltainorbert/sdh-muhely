<?php
/** t210 – árszámítás: várt értékek + ugyanazok az esetek PHP-n és node-on (kalkulator.js) bájtra azonosan. */
require __DIR__ . '/_t210-seged.php';

$k = t_kat();
$sz = static fn (array $t, array $o = []) => SDH_Sales_Ar::szamol_tiszta($k, $t, $o);
$cs = static fn (string $c): array => ['tipus' => 'csomag', 'kulcs' => $c];
$m  = static fn (string $c): array => ['tipus' => 'modul', 'kulcs' => $c];
$s  = static fn (string $c, $db = 1): array => ['tipus' => 'szolgaltatas', 'kulcs' => $c, 'db' => $db];

// --- Várt értékek --------------------------------------------------------------------------------
$e = $sz([$cs('start')], ['ciklus' => 'havi']);
T::egyezik(9900, $e['netto'], 'havi start nettó');
T::egyezik(2673, $e['afa'], 'havi start áfa (27%)');
T::egyezik(12573, $e['brutto'], 'havi start bruttó');
T::egyezik('havi', $e['ciklus'], 'ciklus havi');

$e = $sz([$cs('start')], ['ciklus' => 'eves']);
T::egyezik(100980, $e['netto'], 'éves = havi×12×0,85');
T::egyezik(17820, $e['eves_megtakaritas'], 'éves megtakarítás');

$e = $sz([$cs('pro')], ['ciklus' => 'eves']);
T::egyezik(202980, $e['netto'], 'explicit éves ár');

$e = $sz([$cs('pro')], ['ciklus' => 'havi', 'felhasznalo' => 8, 'telephely' => 2]);
T::egyezik(19900 + 3 * 1900 + 4900, $e['netto'], 'felhasználó- és telephely-skálázás');
T::egyezik(3, $e['sorok'][1]['mennyiseg'], '3 extra felhasználó');

$e = $sz([$cs('pro')], ['ciklus' => 'eves', 'felhasznalo' => 6]);
T::egyezik(202980 + 19000, $e['netto'], 'explicit éves extra-felhasználó ár');

$e = $sz([$cs('pro')], ['ciklus' => 'havi', 'telephely' => 4]);
T::tartalmaz(implode(' ', $e['hibak']), 'legfeljebb 3 telephely', 'telephely-maximum');

$e = $sz([$cs('mini')], ['ciklus' => 'havi', 'felhasznalo' => 2]);
T::tartalmaz(implode(' ', $e['hibak']), 'legfeljebb 1 felhasználó', 'nincs extra-felhasználó');

$e = $sz([$cs('pro'), $m('imei'), $m('asszisztens')], ['ciklus' => 'havi']);
T::egyezik(19900 + 3990, $e['netto'], 'csomagban lévő modul nem számít');
T::igaz(count($e['figyelmeztetesek']) === 1, 'figyelmeztetés a csomagban lévő modulra');
T::igaz(in_array('imei', $e['modulok'], true) && in_array('asszisztens', $e['modulok'], true) && in_array('termekek', $e['modulok'], true), 'licenc-modulok: csomag + csomagban + vett');

$e = $sz([$cs('business'), $m('asszisztens')], ['ciklus' => 'havi']);
T::egyezik(34900, $e['netto'], 'minden-modulos csomag: modul ingyen');
T::egyezik(['*'], $e['modulok'], 'minden modul jel');

$e = $sz([$cs('pro'), $m('asszisztens')], ['ciklus' => 'eves']);
T::egyezik(202980 + 40000, $e['netto'], 'modul explicit éves ára');

$e = $sz([$m('sms')], ['ciklus' => 'havi']);
T::tartalmaz(implode(' ', $e['hibak']), 'csak csomaggal', 'modul csomag nélkül');

$e = $sz([$s('telepites'), $s('oktatas', 3)], ['ciklus' => 'eves']);
T::egyezik(39900 + 3 * 12900, $e['netto'], 'egyszeri szolgáltatások');
T::egyezik('egyszeri', $e['ciklus'], 'csak egyszeri tételek → egyszeri ciklus');

$e = $sz([$cs('start'), $s('support', 2)], ['ciklus' => 'eves']);
T::egyezik(100980 + 2 * 100980, $e['netto'], 'havi szolgáltatás éves díjjal');

$e = $sz([$s('karbantartas')], ['ciklus' => 'havi']);
T::egyezik(50000, $e['netto'], 'éves szolgáltatás');

$e = $sz([$cs('start'), $cs('pro')], []);
T::tartalmaz(implode(' ', $e['hibak']), 'csak egy csomag', 'két csomag');

$e = $sz([], []);
T::egyezik(['A kosár üres.'], $e['hibak'], 'üres kosár');

$e = $sz([$cs('nincs')], []);
T::tartalmaz(implode(' ', $e['hibak']), 'Ismeretlen csomag: nincs.', 'ismeretlen csomag');

$e = $sz([$cs('start')], ['ciklus' => 'heti']);
T::tartalmaz(implode(' ', $e['hibak']), 'Ismeretlen számlázási ciklus', 'rossz ciklus');

$e = $sz([$cs('start')], ['felhasznalo' => 0]);
T::tartalmaz(implode(' ', $e['hibak']), 'felhasználók száma 1 és', 'felhasználó 0');

$e = $sz([$s('oktatas', 0)], []);
T::tartalmaz(implode(' ', $e['hibak']), 'Érvénytelen mennyiség', 'mennyiség 0');

// Kuponok
$e = $sz([$cs('start')], ['ciklus' => 'havi', 'kupon' => 'sz10']);
T::egyezik(990, $e['kedvezmeny'], 'százalékos kupon (kisbetűvel is)');
T::egyezik(8910, $e['netto'], 'kuponnal nettó');
T::egyezik(['kod' => 'SZ10', 'tipus' => 'szazalek', 'ertek' => 10.0], $e['kupon'], 'kupon adat');

$e = $sz([$cs('pro'), $m('asszisztens'), $m('matrica')], ['ciklus' => 'havi', 'kupon' => 'SZ33']);
T::egyezik((int) floor((19900 + 3990 + 1333) * 0.3333 + 0.5), $e['kedvezmeny'], 'tört százalék kerekítése');
T::egyezik($e['kedvezmeny'], array_sum(array_column($e['sorok'], 'kedvezmeny')), 'a sorok kedvezménye pontosan az összes');
T::egyezik($e['netto'], array_sum(array_column($e['sorok'], 'netto')), 'sorok nettója = összes nettó');

$e = $sz([$cs('start')], ['ciklus' => 'havi', 'kupon' => 'FIX5000']);
T::egyezik(4900, $e['netto'], 'fix kupon');
$e = $sz([$cs('start')], ['ciklus' => 'havi', 'kupon' => 'NAGYFIX']);
T::egyezik(0, $e['netto'], 'fix kupon nem visz negatívba');
T::egyezik(0, $e['brutto'], 'nulla bruttó');
foreach (['LEJART' => 'lejárt', 'ELFOGYOTT' => 'elfogyott', 'JOVO' => 'még nem érvényes', 'INAKTIV' => 'Ismeretlen kuponkód', 'NINCSILYEN' => 'Ismeretlen kuponkód', 'EVESKUP' => 'csak éves díjjal'] as $kod => $szo) {
    $e = $sz([$cs('start')], ['ciklus' => 'havi', 'kupon' => $kod]);
    T::tartalmaz(implode(' ', $e['hibak']), $szo, 'kupon ' . $kod);
    T::egyezik(0, $e['kedvezmeny'], 'kupon ' . $kod . ' nem ad kedvezményt');
}
$e = $sz([$cs('start')], ['ciklus' => 'havi', 'kupon' => 'MIN50']);
T::tartalmaz(implode(' ', $e['hibak']), "50\u{00A0}000\u{00A0}Ft", 'minimum összeg');
$e = $sz([$cs('start')], ['ciklus' => 'eves', 'kupon' => 'MIN50']);
T::egyezik(3000, $e['kedvezmeny'], 'minimum fölött jó');
$e = $sz([$cs('pro'), $m('asszisztens'), $s('telepites')], ['ciklus' => 'havi', 'kupon' => 'MODUL15']);
T::egyezik((int) floor(3990 * 0.15 + 0.5), $e['kedvezmeny'], 'hatókör: modul');
$e = $sz([$cs('start'), $s('telepites')], ['ciklus' => 'havi', 'kupon' => 'PROCSAK']);
T::tartalmaz(implode(' ', $e['hibak']), 'nem érvényes', 'hatókör: csak pro');
$e = $sz([$cs('pro')], ['ciklus' => 'havi', 'felhasznalo' => 6, 'kupon' => 'PROCSAK']);
T::egyezik((int) floor((19900 + 1900) * 0.5 + 0.5), $e['kedvezmeny'], 'hatókör csomag-kulcs: az extra sorokra is');
$e = $sz([$cs('start'), $s('telepites')], ['ciklus' => 'havi', 'kupon' => 'SZOLG20']);
T::egyezik(7980, $e['kedvezmeny'], 'hatókör: szolgáltatás');
$e = $sz([$s('ingyen_s')], ['kupon' => 'SZ10']);
T::tartalmaz(implode(' ', $e['hibak']), 'nem érvényes', 'nulla összegű kosár kuponnal');

// Áfa a beállításból
$k2 = $k; $k2['afa'] = 5;
T::egyezik(495, SDH_Sales_Ar::szamol_tiszta($k2, [$cs('start')], ['ciklus' => 'havi'])['afa'], 'áfa a beállításból (5%)');

// --- PHP ↔ node tükör ---------------------------------------------------------------------------
$esetek = [];
$rng = 20261019;
$kov = static function () use (&$rng): int { $rng = ($rng * 1103515245 + 12345) & 0x7fffffff; return $rng; };
$csk = array_keys($k['csomagok']); $mk = array_keys($k['modulok']); $sk = array_keys($k['szolgaltatasok']); $kk = array_merge(array_keys($k['kuponok']), ['', '', '', 'rossz']);
for ($i = 0; $i < 300; $i++) {
    $t = [];
    if ($kov() % 6 !== 0) { $t[] = $cs($csk[$kov() % count($csk)]); }
    for ($j = $kov() % 4; $j > 0; $j--) { $t[] = $m($mk[$kov() % count($mk)]); }
    for ($j = $kov() % 3; $j > 0; $j--) { $t[] = $s($sk[$kov() % count($sk)], 1 + $kov() % 4); }
    if ($kov() % 25 === 0) { $t[] = ['tipus' => 'valami', 'kulcs' => 'x']; }
    $o = ['ciklus' => ['havi', 'eves', 'eves', 'heti'][$kov() % 4]];
    if ($kov() % 2) { $o['felhasznalo'] = $kov() % 14; }
    if ($kov() % 3 === 0) { $o['telephely'] = 1 + $kov() % 5; }
    $kp = $kk[$kov() % count($kk)];
    if ($kp !== '') { $o['kupon'] = $kov() % 2 ? strtolower($kp) : $kp; }
    if ($kov() % 9 === 0) { unset($t[0]); $t = array_values($t); $o['alap_csomag'] = $csk[$kov() % count($csk)]; $o['alap_felhasznalo'] = $kov() % 9; }
    $esetek[] = ['t' => $t, 'o' => $o];
}

$php = array_map(static fn (array $x): array => SDH_Sales_Ar::szamol_tiszta($k, $x['t'], $x['o']), $esetek);
$dir = sys_get_temp_dir() . '/t210-' . getmypid();
wp_mkdir_p($dir);
file_put_contents($dir . '/be.json', wp_json_encode(['kat' => $k, 'esetek' => $esetek], JSON_UNESCAPED_UNICODE));
file_put_contents($dir . '/fut.js', "const A=require(process.argv[2]);const d=require(process.argv[3]);console.log(JSON.stringify(d.esetek.map(x=>A.szamol(d.kat,x.t,x.o))));");
$js = dirname(__DIR__) . '/sales/sdh-sales/assets/front/kalkulator.js';
$ki = shell_exec('node ' . escapeshellarg($dir . '/fut.js') . ' ' . escapeshellarg($js) . ' ' . escapeshellarg($dir . '/be.json') . ' 2>&1');
$node = json_decode((string) $ki, true);
T::igaz(is_array($node) && count($node) === count($esetek), 'node lefutott (' . mb_substr((string) $ki, 0, 200) . ')');

$norm = static function ($x) use (&$norm) {
    if (is_array($x)) { return array_map($norm, $x); }
    return is_float($x) && floor($x) === $x ? (int) $x : $x;
};
$elter = 0; $hibas = 0; $elso = '';
foreach ($php as $i => $p) {
    $n = $node[$i] ?? null;
    $hibas += $p['hibak'] !== [] ? 1 : 0;
    if (wp_json_encode($norm($p), JSON_UNESCAPED_UNICODE) !== wp_json_encode($norm($n), JSON_UNESCAPED_UNICODE)) {
        $elter++;
        $elso = $elso ?: '#' . $i . ' ' . wp_json_encode($esetek[$i]) . "\n PHP: " . wp_json_encode($p, JSON_UNESCAPED_UNICODE) . "\n JS:  " . wp_json_encode($n, JSON_UNESCAPED_UNICODE);
    }
}
T::egyezik(0, $elter, 'PHP és JS eredmény azonos 300 esetben' . ($elso ? "\n" . $elso : ''));
T::igaz($hibas > 20 && $hibas < 280, 'az esetek között hibásak és jók is vannak (' . $hibas . ' hibás)');
array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);

T::vege('t210 ár');
