<?php
/**
 * Téma-építés (fejlesztői eszköz, parancssorból): a CSS és a JS tömörítése az assets/dist/ alá.
 *   php bin/epit.php
 * - assets/dist/kritikus.min.css = tokenek.css + kritikus.css (a fejbe ágyazva); kritikus-proza.min.css = + proza.css (szöveges oldalak)
 * - assets/dist/tema.min.css     = sdh_tema_css_forrasok() sorrendjében (nem blokkolva)
 * - assets/dist/<nev>.min.js     = tema, kereso, datum
 * Tömörítő: esbuild, ha elérhető (ESBUILD környezeti változó vagy a PATH), különben a beépített biztonságos CSS-tömörítő,
 * a JS pedig változatlanul másolódik. A téma futásidőben a forrásra vált, ha a dist régebbi a forrásnál.
 * Kiírja a méreteket (nyers / gzip).
 *
 * @package SDH_Sales_Tema
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$gy   = dirname(__DIR__);
$css  = $gy . '/assets/css/';
$js   = $gy . '/assets/js/';
$dist = $gy . '/assets/dist/';

if (!is_dir($dist) && !mkdir($dist, 0755, true) && !is_dir($dist)) {
    fwrite(STDERR, "Nem hozható létre: {$dist}\n");
    exit(1);
}

// A CSS-forrássorrend az alap.php-ból (egy igazságforrás).
$alap = (string) file_get_contents($gy . '/inc/alap.php');
preg_match("/function sdh_tema_css_forrasok\(\): array\s*\{\s*return \[([^\]]+)\]/", $alap, $m);
$forrasok = array_map(static fn ($x) => trim($x, " '\""), explode(',', $m[1] ?? ''));

$esbuild = getenv('ESBUILD') ?: trim((string) shell_exec('command -v esbuild 2>/dev/null'));

if ($esbuild === '' && is_file('/opt/npm-tools/node_modules/.bin/esbuild')) {
    $esbuild = '/opt/npm-tools/node_modules/.bin/esbuild';
}

$tomorit_css = static function (string $s) use ($esbuild): string {
    if ($esbuild !== '') {
        $tmp = tempnam(sys_get_temp_dir(), 'css');
        file_put_contents($tmp, $s);
        $ki = shell_exec(escapeshellarg($esbuild) . ' --loader=css --minify --log-level=error < ' . escapeshellarg($tmp));
        unlink($tmp);

        if (is_string($ki) && $ki !== '') {
            return trim($ki);
        }
    }

    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    $s = (string) preg_replace('/\s+/', ' ', $s);
    $s = (string) preg_replace('/\s*([{};,>])\s*/', '$1', $s);

    return trim(str_replace(';}', '}', $s));
};

$tomorit_js = static function (string $f) use ($esbuild): string {
    if ($esbuild !== '') {
        $ki = shell_exec(escapeshellarg($esbuild) . ' ' . escapeshellarg($f) . ' --minify --target=es2019 --log-level=error');

        if (is_string($ki) && $ki !== '') {
            return $ki;
        }
    }

    return (string) file_get_contents($f);
};

$meret = static function (string $nev, string $tart): void {
    printf("  %-22s %7d B  (gzip %6d B)\n", $nev, strlen($tart), strlen((string) gzencode($tart, 9)));
};

echo "CSS:\n";
$k = $tomorit_css(file_get_contents($css . 'tokenek.css') . "\n" . file_get_contents($css . 'kritikus.css'));
file_put_contents($dist . 'kritikus.min.css', $k);
$meret('kritikus.min.css', $k);
$kp = $tomorit_css(file_get_contents($css . 'tokenek.css') . "\n" . file_get_contents($css . 'kritikus.css') . "\n" . file_get_contents($css . 'proza.css'));
file_put_contents($dist . 'kritikus-proza.min.css', $kp);
$meret('kritikus-proza.min.css', $kp);

$t = '';

foreach ($forrasok as $n) {
    if (is_file($css . $n . '.css')) {
        $t .= file_get_contents($css . $n . '.css') . "\n";
    }
}

$t = $tomorit_css($t);
file_put_contents($dist . 'tema.min.css', $t);
$meret('tema.min.css', $t);

echo "JS:\n";

foreach (['tema', 'kereso', 'datum'] as $n) {
    $j = $tomorit_js($js . $n . '.js');
    file_put_contents($dist . $n . '.min.js', $j);
    $meret($n . '.min.js', $j);
}

echo 'Tömörítő: ' . ($esbuild !== '' ? 'esbuild' : 'beépített (JS tömörítés nélkül)') . "\n";
