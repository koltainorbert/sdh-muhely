<?php
/**
 * Minden teszt lefuttatása, tesztenként egy összegző sorral.
 *
 *   SDH_TESZT_ADATBAZIS=1 SDH_WP_LOAD=/út/wp-load.php php tesztek/futtat.php [szűrő]
 *
 * A szűrő a fájlnév egy része (pl. „t22"). Kilépési kód: 0, ha minden zöld.
 */

$szuro = $argv[1] ?? '';
$hibas = 0;
$ossz  = 0;

foreach (glob(__DIR__ . '/t[0-9]*.php') ?: [] as $fajl) {
    if ($szuro !== '' && strpos(basename($fajl), $szuro) === false) {
        continue;
    }

    $kimenet = [];
    $kod     = 0;

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fajl) . ' 2>&1', $kimenet, $kod);

    echo implode("\n", $kimenet) . "\n";

    $ossz++;
    $hibas += $kod === 0 ? 0 : 1;
}

echo $hibas === 0 ? "Mind zöld ({$ossz} teszt).\n" : "{$hibas} teszt hibás a(z) {$ossz}-ból.\n";

exit($hibas === 0 ? 0 : 1);
