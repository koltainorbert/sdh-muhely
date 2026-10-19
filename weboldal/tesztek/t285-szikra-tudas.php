<?php
/** t285 – Szikra tudásbázis: építés fejlesztői részek nélkül, magyar normalizálás, BM25 (ékezettel / nélkül), tartalom-tár. */
require __DIR__ . '/_keret.php';

/** Rejtés (weboldal/REJTES.md): a kimenetben egyik SDH_Sales_Rejtes::TILTOTT minta sem lehet. */
$rejtett = static function ($kimenet, string $nev, array $fejlecek = []): void {
    if ($kimenet instanceof WP_REST_Response) {
        $fejlecek += $kimenet->get_headers();
        $kimenet   = $kimenet->get_data();
    }
    $s = is_string($kimenet) ? $kimenet : (string) wp_json_encode($kimenet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $t = SDH_Sales_Rejtes::vizsgal($s, $fejlecek);
    T::igaz($t === [] && $s !== '', 'rejtés: ' . $nev . ($t !== [] ? ' – ' . implode(' | ', array_slice($t, 0, 3)) : ($s === '' ? ' – üres kimenet' : '')));
};

$mappa = SDH_Sales_Szikra_Tudas::mappa();
$gyoker = dirname(__DIR__, 2);

// 1. Építés egy ideiglenes mappába (a forrás a valódi súgó), és a kész data/tudas ellenőrzése.
$tmp = sys_get_temp_dir() . '/t285-' . wp_rand();
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/eszkozok/tudas-epit.php') . ' --cel=' . escapeshellarg($tmp) . ' --csendes 2>&1', $ki, $kod);
T::egyezik(0, $kod, 'építő lefut (' . implode(' ', $ki) . ')');
$idx = json_decode((string) @file_get_contents($tmp . '/_index.json'), true);
T::igaz(is_array($idx) && $idx['darab_ossz'] > 500, 'építés: sok darab (' . (int) ($idx['darab_ossz'] ?? 0) . ')');
T::igaz(in_array('27-belso.md', (array) ($idx['kihagyva']['fejezet'] ?? []), true), 'kihagyva: a fejlesztő belső fejezete (27-…-belso.md, az indexben név nélkül)');
T::igaz(in_array('21-telepites.md', (array) ($idx['kihagyva']['fejezet'] ?? []), true), 'kihagyva: csak fejlesztőnek szóló fejezet (21-telepites)');
T::igaz(in_array('24-modulok.md', (array) ($idx['kihagyva']['fejezet'] ?? []), true), 'kihagyva: csak fejlesztőnek szóló fejezet (24-modulok)');
T::igaz((int) ($idx['kihagyva']['alcim'] ?? 0) >= 5, 'kihagyva: [fejlesztő] alcímek (' . (int) ($idx['kihagyva']['alcim'] ?? 0) . ')');
T::igaz(!is_file($tmp . '/sdh-belso.json') && !is_file($tmp . '/telepites.json') && !is_file($tmp . '/modulok.json'), 'nincs fájl a kihagyott fejezetekhez');

foreach ([$tmp, $mappa] as $m) {
    $osszes = '';
    foreach (glob($m . '/*.json') ?: [] as $f) {
        $osszes .= (string) file_get_contents($f);
    }
    T::igaz(strlen($osszes) > 100000, 'van tudás: ' . basename($m));
    T::igaz(preg_match('/wordpress/i', $osszes) !== 1, 'nincs „WordPress” a tudásban: ' . basename($m));
    T::nem_tartalmaz($osszes, '[fejlesztő]', 'nincs „[fejlesztő]” jel: ' . basename($m));
    T::igaz(preg_match('/\bplugin|github|wp-config|wp-admin|SDH_MUHELY_|Gigatel/iu', $osszes) !== 1, 'nincs plugin / GitHub / belső kódnév / SDH-belső: ' . basename($m));
    T::nem_tartalmaz($osszes, 'SDH Műhely', 'a terméknév token, nem beégetve: ' . basename($m));
    T::nem_tartalmaz($osszes, 'Túra:', 'túra-szakaszok nélkül: ' . basename($m));
    T::igaz(preg_match('/sdh/i', $osszes) !== 1, 'nincs „SDH” semmilyen formában (index is): ' . basename($m));
    $rejtett($osszes, 'tudásbázis (' . basename($m) . ')');
}
T::nem_tartalmaz((string) file_get_contents($mappa . '/munkalapok.json'), 'Végső mentőöv', 'a [fejlesztő] alcím tartalma sincs benne');
T::tartalmaz((string) file_get_contents($mappa . '/munkalapok.json'), 'Szerepkörök és jogok', 'a jelölés utáni szakasz megmaradt');
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

// 2. Normalizálás, tövezés.
T::egyezik(SDH_Sales_Szikra_Tudas::szavak('Pénztár zárása'), SDH_Sales_Szikra_Tudas::szavak('penztar zarasa'), 'ékezet nélkül ugyanaz');
T::egyezik(SDH_Sales_Szikra_Tudas::szavak('munkalapokat'), SDH_Sales_Szikra_Tudas::szavak('munkalap'), 'tövezés: munkalapokat → munkalap');
T::egyezik(SDH_Sales_Szikra_Tudas::szavak('ügyfeleknek'), SDH_Sales_Szikra_Tudas::szavak('ügyfél'), 'tövezés: ügyfeleknek → ügyfél');
T::egyezik(['penztar'], SDH_Sales_Szikra_Tudas::szavak('kassza'), 'rokon szó: kassza → pénztár');
T::egyezik([], SDH_Sales_Szikra_Tudas::szavak('hogy mi az és'), 'töltelékszavak kiesnek');

// 3. BM25 a valódi tudáson – ékezettel és ékezet nélkül ugyanaz a találat.
$probak = [
    'hogyan zárom a kasszát'            => '/pénztár|kassza|zár/iu',
    'hogyan zarom a kasszat'            => '/pénztár|kassza|zár/iu',
    'biztonsági mentés Google Drive-ra' => '/Google Drive/u',
    'biztonsagi mentes google drive'    => '/Google Drive/u',
    'javítási garancia visszatérés'     => '/garancia/iu',
    'online időpontfoglalás'            => '/foglal/iu',
    'IMEI keresés'                      => '/IMEI/u',
    'kétlépcsős belépés telefonnal'     => '/Kétlépcsős/u',
];
foreach ($probak as $q => $minta) {
    $r = SDH_Sales_Szikra_Tudas::keres($q, 3);
    T::igaz($r !== [] && preg_match($minta, $r[0]['cim'] . ' ' . $r[0]['szoveg']) === 1, 'BM25: „' . $q . '” → ' . ($r[0]['cim'] ?? '–'));
}
$a = SDH_Sales_Szikra_Tudas::keres('pénztárzárás nyomtatása', 3);
$b = SDH_Sales_Szikra_Tudas::keres('penztarzaras nyomtatasa', 3);
T::egyezik(array_column($a, 'id'), array_column($b, 'id'), 'ékezetes és ékezet nélküli kérdés: azonos találatok');
T::igaz(isset($a[0]['url']) && strpos($a[0]['url'], home_url()) === 0 && strpos($a[0]['url'], '/tudasbazis/') !== false, 'forrás-URL a saját webhelyen');
T::egyezik([], SDH_Sales_Szikra_Tudas::keres('qwzx ykvb', 3), 'értelmetlen kérdésre nincs találat');

// 4. Kis, kézi korpusz: a BM25 sorrendje (ritka szó erősebb, hosszra normalizál).
$d = static fn (string $id, string $s): array => ['id' => $id, 'cim' => $id, 'url' => home_url('/' . $id . '/'), 'szoveg' => $s, 't' => ' ' . implode(' ', SDH_Sales_Szikra_Tudas::szavak($s)) . ' ', 'h' => count(SDH_Sales_Szikra_Tudas::szavak($s)), 'forras' => 'teszt'];
$korpusz = [
    $d('a', 'A munkalap a javítás teljes története.'),
    $d('b', 'Az SMS-értesítés az ügyfélnek megy, amikor a munkalap állapota változik.'),
    $d('c', 'Számla a Számlázz.hu-val: a munkalapról egy kattintással.'),
    $d('d', 'A munkalap munkalap munkalap munkalap munkalap munkalap munkalap munkalap ' . str_repeat('töltelékszöveg hosszú ', 60)),
];
$r = SDH_Sales_Szikra_Tudas::keres('SMS értesítés ügyfeleknek', 2, $korpusz);
T::egyezik('b', $r[0]['id'] ?? '', 'BM25: a ritka szavakat tartalmazó darab nyer');
$r = SDH_Sales_Szikra_Tudas::keres('munkalap', 4, $korpusz);
T::igaz(($r[0]['id'] ?? '') !== 'd', 'BM25: a hosszú, ismétlő darab nem nyer (hossz-normalizálás)');

// 5. Futásidejű tudás a tartalom-tárból (GYIK, csomag árral, modul).
SDH_Sales_Tartalom::ment('gyik', 't285-gyik', ['kerdes' => 'Átvihetem a régi MunkaLap adataimat a {termeknev} rendszerbe?', 'valasz' => '<p>Igen, CSV és XLSX importtal.</p>']);
SDH_Sales_Tartalom::ment('csomag', 't285-pro', ['nev' => 'Próba Pro', 'leiras' => 'Közepes szervizeknek.', 'havi' => 12000, 'eves' => 120000, 'felhasznalo_alap' => 5, 'telephely_alap' => 1, 'modulok' => ['penztar']]);
SDH_Sales_Tartalom::ment('modul', 't285-modul', ['nev' => 'Zebracsík-modul', 'leiras' => 'Csak a teszthez.', 'havi' => 2500, 'csomagban' => []]);
SDH_Sales_Szikra_Tudas::urit();
$r = SDH_Sales_Szikra_Tudas::keres('Átvihetem a régi MunkaLap adataimat?', 5);
T::igaz(in_array('gyik:t285-gyik', array_column($r, 'id'), true), 'GYIK a tudásban');
T::igaz(strpos(implode(' ', array_column($r, 'szoveg')), '{termeknev}') === false, 'a tokenek cserélve a találatban');
$r = SDH_Sales_Szikra_Tudas::keres('Próba Pro csomag ára', 3);
T::egyezik('csomag:t285-pro', $r[0]['id'] ?? '', 'csomag a tudásban');
T::tartalmaz((string) ($r[0]['szoveg'] ?? ''), SDH_Sales::ft(12000), 'a csomag ára a tárból (nettó)');
T::tartalmaz((string) ($r[0]['szoveg'] ?? ''), SDH_Sales::ft(SDH_Sales::brutto(12000)), 'és bruttóban, a beállított áfával');
SDH_Sales_Tartalom::ment('csomag', 't285-pro', ['nev' => 'Próba Pro', 'havi' => 15000, 'felhasznalo_alap' => 5, 'modulok' => []]);
$r = SDH_Sales_Szikra_Tudas::keres('Próba Pro csomag ára', 1);
T::tartalmaz((string) ($r[0]['szoveg'] ?? ''), SDH_Sales::ft(15000), 'árváltozás azonnal látszik (gyorsítótár-verzió)');
T::igaz(SDH_Sales_Szikra_Tudas::keres('zebracsik', 1)[0]['id'] === 'modul:t285-modul', 'modul a tudásban (ékezet nélkül is)');

$rejtett(SDH_Sales_Szikra_Tudas::keres('mentés licenckulcs adminisztrátor csomag', 10), 'tudás-találatok (a modellnek átadott szöveg)');
T::vege('t285 szikra-tudás');
