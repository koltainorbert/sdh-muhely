<?php
/**
 * Szikra tudásbázisának építése a Műhely súgójából (WordPress nélkül fut).
 *
 *   php weboldal/eszkozok/tudas-epit.php [--forras=/út/sugo] [--cel=/út/data/tudas] [--csendes]
 *
 * Mit hagy ki (sugo/OLVASSEL.md „Csak a fejlesztőnek” + a webhely szabálya: látogatói szövegben nincs WordPress / plugin…):
 *   - a `csak: fejleszto` fejlécű fejezeteket, és a 27-sdh-belso.md-t (az SDH Szerviz saját moduljai);
 *   - a `## Cím [fejlesztő]` szakaszokat, a `### Alcím [fejlesztő]` részeket (a következő ###-ig vagy a szakasz végéig);
 *   - a `## Túra: …` szakaszokat (CSS-szelektorok, a webhelyen értelmetlenek);
 *   - minden bekezdést / listapontot, amely elárulja a hátteret (WordPress, wp-, plugin, bővítmény, LocalWP, GitHub, git,
 *     .php) vagy belső kódnevet tartalmaz (define(, SDH_…, includes/), vagy a fejlesztő nevét bármilyen formában (SDH, sdh-…, --sdh-…).
 * A „SDH Műhely” név helyére a {termeknev} token kerül, a licenckulcs-minta előtag nélküli (az előtag a Központ beállítása).
 *
 * Kimenet: data/tudas/<kulcs>.json – {kulcs, cim, csoport, osszefoglalo, darabok:[{id, cim, horgony, szoveg, t, h}]}
 *          data/tudas/_index.json  – {verzio, forras_verzio, fejezetek:[…], darab_ossz, kihagyva:{…}}
 * A `t` mező a SDH_Sales_Szikra_Tudas::szavak() tokenjei (ugyanaz a normalizálás, mint a keresésnél), `h` a darabszám.
 */

if (PHP_SAPI !== 'cli') {
    exit("Csak parancssorból futtatható.\n");
}

$opc = getopt('', ['forras::', 'cel::', 'csendes']);
$gyoker = dirname(__DIR__, 2);
$forras = rtrim((string) ($opc['forras'] ?? $gyoker . '/sugo'), '/');
$cel    = rtrim((string) ($opc['cel'] ?? $gyoker . '/weboldal/sales/sdh-sales/data/tudas'), '/');
$csend  = isset($opc['csendes']);

if (!is_dir($forras)) {
    fwrite(STDERR, "Nincs ilyen súgómappa: {$forras}\n");
    exit(2);
}

// A tokenizáló a bővítmény osztályából jön (ABSPATH nélkül kilépne – itt csak a tiszta PHP-részét használjuk).
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once $gyoker . '/weboldal/sales/sdh-sales/includes/class-sdh-sales-szikra-tudas.php';

const SZIKRA_DARAB_MAX = 1800;
const SZIKRA_KIHAGY_FAJL = ['27-sdh-belso.md', 'OLVASSEL.md'];

/** Elárulja-e a hátteret (a Műhely SDH_Muhely_Rejtes::arulkodo() szabálya + belső kódnevek + a fejlesztő neve: weboldal/REJTES.md). */
function szikra_arulkodo(string $s): bool
{
    return preg_match('/wordpress|\bwp-|\bWP\b|plugin|(?<![\p{L}])bővítmény|localwp|local by flywheel|\.php\b|wp_|github|\bgit\b|define\(|\bSDH_[A-Z_]{3,}|includes\/|wp-config|wp-content|\[fejlesztő\]|\(fejlesztő\)|sdh/iu', $s) === 1;
}

/** @return array{0: array<string, string>, 1: string} */
function szikra_fej(string $s): array
{
    $s = str_replace("\r\n", "\n", $s);

    if (strncmp($s, "---\n", 4) !== 0 || ($v = strpos($s, "\n---", 4)) === false) {
        return [[], $s];
    }

    $fej = [];

    foreach (explode("\n", substr($s, 4, $v - 4)) as $sor) {
        if (preg_match('/^([a-z_]+):\s*(.*)$/', $sor, $m)) {
            $fej[$m[1]] = trim($m[2]);
        }
    }

    return [$fej, ltrim(substr($s, $v + 4), "-\n")];
}

/** A súgó saját jelöléseinek átírása a webhelyre. */
function szikra_jeloles(string $s): string
{
    $s = preg_replace('/\[([^\]]+)\]\((?:sugo|modul):[^)]*\)/u', '$1', $s) ?? $s;   // belső hivatkozás → felirat
    $s = preg_replace('/\[\[([^\]]+)\]\]/u', '$1', $s) ?? $s;                         // [[Enter]] → Enter
    $s = preg_replace('/^>\s*\*\*(Tipp|Figyelem|Fontos|Példa):\*\*\s*/mu', '$1: ', $s) ?? $s;
    $s = preg_replace('/^>\s?/mu', '', $s) ?? $s;
    $s = str_replace(['SDH Műhely', 'SDH műhely'], '{termeknev}', $s);
    $s = preg_replace('/\bSDH-(XXXX-XXXX-XXXX-XXXX)\b/u', '$1', $s) ?? $s;            // licenckulcs-minta előtag nélkül

    return $s;
}

/**
 * Egy szöveg „pontjai”: bekezdés, vagy egy listapont a behúzott folytatósoraival. Az árulkodó pont kimarad.
 *
 * @return array{0: string, 1: int} [szöveg, kihagyott pontok]
 */
function szikra_szur(string $s): array
{
    $pontok = [];
    $uj     = true;

    foreach (explode("\n", $s) as $sor) {
        if (trim($sor) === '') {
            $pontok[] = '';
            $uj       = true;
            continue;
        }

        $lista = preg_match('/^\s*([-*+]|\d+[.)])\s+/', $sor) === 1 && preg_match('/^\s{2,}/', $sor) !== 1;
        $tabla = strncmp(ltrim($sor), '|', 1) === 0;

        if ($uj || $lista || $tabla || preg_match('/^#{3,}\s/', $sor)) {
            $pontok[] = $sor;
        } else {
            $pontok[count($pontok) - 1] .= "\n" . $sor;
        }

        $uj = false;
    }

    $ki = 0;
    $jo = [];

    foreach ($pontok as $p) {
        if ($p !== '' && szikra_arulkodo($p)) {
            $ki++;
            continue;
        }

        $jo[] = $p;
    }

    $szoveg = preg_replace("/\n{3,}/", "\n\n", implode("\n", $jo)) ?? '';

    return [trim($szoveg), $ki];
}

/** A `### … [fejlesztő]` részek eltávolítása egy szakaszból (a következő ###-ig vagy a végéig). */
function szikra_fejleszto_alcim(string $torzs, int &$db): string
{
    $ki   = [];
    $rejt = false;

    foreach (explode("\n", $torzs) as $sor) {
        if (preg_match('/^###\s+(.*)$/u', $sor, $m)) {
            $rejt = (bool) preg_match('/\[fejlesztő\]\s*$/u', $m[1]);
            $db  += $rejt ? 1 : 0;
        }

        if (!$rejt) {
            $ki[] = $sor;
        }
    }

    return implode("\n", $ki);
}

/**
 * Hosszú szöveg darabolása ### alcímek, majd bekezdések mentén.
 *
 * @return array<int, array{cim: string, szoveg: string}>
 */
function szikra_darabol(string $cim, string $szoveg): array
{
    if (mb_strlen($szoveg) <= SZIKRA_DARAB_MAX) {
        return [['cim' => $cim, 'szoveg' => $szoveg]];
    }

    $reszek = preg_split('/\n(?=###\s)/u', $szoveg) ?: [$szoveg];
    $ki     = [];

    foreach ($reszek as $r) {
        $al = $cim;

        if (preg_match('/^###\s+(.+)$/mu', $r, $m) && strncmp(ltrim($r), '###', 3) === 0) {
            $al = $cim . ' › ' . trim($m[1]);
        }

        $buf = '';

        foreach (preg_split("/\n{2,}/", $r) ?: [] as $bek) {
            if ($buf !== '' && mb_strlen($buf) + mb_strlen($bek) > SZIKRA_DARAB_MAX) {
                $ki[] = ['cim' => $al, 'szoveg' => trim($buf)];
                $buf  = '';
            }

            $buf .= ($buf === '' ? '' : "\n\n") . $bek;
        }

        if (trim($buf) !== '') {
            $ki[] = ['cim' => $al, 'szoveg' => trim($buf)];
        }
    }

    // A túl rövid utolsó darab az előzőhöz.
    $tom = [];

    foreach ($ki as $d) {
        $n = count($tom);

        if ($n > 0 && mb_strlen($d['szoveg']) < 200 && $tom[$n - 1]['cim'] === $d['cim']) {
            $tom[$n - 1]['szoveg'] .= "\n\n" . $d['szoveg'];
        } else {
            $tom[] = $d;
        }
    }

    return $tom;
}

$fajlok    = glob($forras . '/*.md') ?: [];
$fejezetek = [];
$ossz      = 0;
$kihagy    = ['fejezet' => [], 'szakasz' => 0, 'alcim' => 0, 'pont' => 0, 'tura' => 0];
$irt       = [];
$forras_ver = '';

if (!is_dir($cel) && !mkdir($cel, 0775, true) && !is_dir($cel)) {
    fwrite(STDERR, "Nem tudom létrehozni: {$cel}\n");
    exit(2);
}

foreach ($fajlok as $f) {
    $nev = basename($f);
    [$fej, $torzs] = szikra_fej((string) file_get_contents($f));

    if ($nev === '00-sugo.md') {
        $forras_ver = (string) ($fej['verzio'] ?? '');
    }

    if (in_array($nev, SZIKRA_KIHAGY_FAJL, true) || ($fej['csak'] ?? '') === 'fejleszto' || empty($fej['kulcs'])) {
        if ($nev !== 'OLVASSEL.md') {
            $kihagy['fejezet'][] = (string) preg_replace('/sdh[-_]?/i', '', $nev); // az index is a tudásbázis része: név nélkül
        }

        continue;
    }

    $kulcs = preg_replace('/[^a-z0-9-]/', '', (string) $fej['kulcs']);
    $fcim  = szikra_jeloles((string) ($fej['cim'] ?? $kulcs));
    $szak  = preg_split('/^##\s+/mu', "\n" . $torzs) ?: [];
    $darab = [];

    foreach ($szak as $sz) {
        if (trim($sz) === '' || strpos($sz, "\n") === false) {
            continue;
        }

        [$scim, $stor] = explode("\n", $sz, 2);
        $scim          = trim($scim);

        if ($scim === '' || $stor === '' && $scim === '') {
            continue;
        }

        if (preg_match('/\[fejlesztő\]\s*$/u', $scim)) {
            $kihagy['szakasz']++;
            continue;
        }

        if (stripos($scim, 'Túra:') === 0) {
            $kihagy['tura']++;
            continue;
        }

        $stor = szikra_fejleszto_alcim($stor, $kihagy['alcim']);
        [$stor, $kip] = szikra_szur(szikra_jeloles($stor));
        $kihagy['pont'] += $kip;
        $scim = szikra_jeloles($scim);

        if (trim($stor) === '' || szikra_arulkodo($scim)) {
            continue;
        }

        $horgony = SDH_Sales_Szikra_Tudas::horgony($scim);

        // A GYIK kérdésenként külön darab (pontosabb találat).
        if (preg_match('/^GYIK\b/u', $scim)) {
            foreach (preg_split('/\n(?=###\s)/u', "\n" . $stor) ?: [] as $q) {
                if (!preg_match('/^\s*###\s+(.+?)\s*\n(.*)$/su', $q, $m) || trim($m[2]) === '') {
                    continue;
                }

                $darab[] = ['cim' => 'GYIK › ' . trim($m[1]), 'szoveg' => trim($m[2]), 'horgony' => SDH_Sales_Szikra_Tudas::horgony(trim($m[1]))];
            }

            continue;
        }

        foreach (szikra_darabol($scim, $stor) as $d) {
            $darab[] = $d + ['horgony' => $horgony];
        }
    }

    if ($darab === []) {
        continue;
    }

    $ki = [];

    foreach ($darab as $i => $d) {
        $szoveg = trim($d['szoveg']);
        $tok    = SDH_Sales_Szikra_Tudas::szavak($fcim . ' ' . $d['cim'] . ' ' . $d['cim'] . ' ' . $szoveg);
        $ki[]   = [
            'id'      => $kulcs . '#' . ($i + 1),
            'cim'     => $d['cim'],
            'horgony' => $d['horgony'],
            'szoveg'  => $szoveg,
            't'       => implode(' ', $tok),
            'h'       => count($tok),
        ];
    }

    $adat = [
        'kulcs'        => $kulcs,
        'cim'          => $fcim,
        'csoport'      => (string) ($fej['csoport'] ?? ''),
        'osszefoglalo' => szikra_jeloles((string) ($fej['osszefoglalo'] ?? '')),
        'darabok'      => $ki,
    ];

    $json = json_encode($adat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

    if (szikra_arulkodo($fcim . ' ' . $adat['osszefoglalo'] . ' ' . implode(' ', array_column($ki, 'szoveg')) . ' ' . implode(' ', array_column($ki, 'cim')))) {
        fwrite(STDERR, "HIBA: árulkodó szöveg maradt: {$nev}\n");
        exit(1);
    }

    file_put_contents($cel . '/' . $kulcs . '.json', $json);
    $irt[]       = $kulcs . '.json';
    $ossz       += count($ki);
    $fejezetek[] = ['kulcs' => $kulcs, 'cim' => $fcim, 'csoport' => $adat['csoport'], 'osszefoglalo' => $adat['osszefoglalo'], 'darab' => count($ki)];
}

// Régi, már nem létező fejezetek fájljai.
foreach (glob($cel . '/*.json') ?: [] as $f) {
    if (basename($f) !== '_index.json' && !in_array(basename($f), $irt, true)) {
        unlink($f);
    }
}

$index = [
    'verzio'        => 1,
    'forras_verzio' => $forras_ver,
    'fejezetek'     => $fejezetek,
    'darab_ossz'    => $ossz,
    'kihagyva'      => $kihagy,
];

file_put_contents($cel . '/_index.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");

if (!$csend) {
    echo 'Tudás kész: ' . count($fejezetek) . ' fejezet, ' . $ossz . ' darab; kihagyva: ' . count($kihagy['fejezet']) . ' fejezet (' . implode(', ', $kihagy['fejezet']) . '), '
        . $kihagy['szakasz'] . ' fejlesztői szakasz, ' . $kihagy['alcim'] . ' fejlesztői alcím, ' . $kihagy['tura'] . ' túra, ' . $kihagy['pont'] . " árulkodó pont.\n";
}
