<?php
/**
 * Minden teszt (t2NN-*.php) lefuttatása, tesztenként egy összegző sorral. Kilépési kód: 0, ha minden zöld.
 *   SDH_TESZT_ADATBAZIS=1 SDH_WP_LOAD=/út/wp-load.php php weboldal/tesztek/futtat.php [szűrő]
 * Tiszta adatbázis: SDH_TESZT_DB=/út/sales.sqlite SDH_TESZT_ALAP=/út/alap.sqlite (minden teszt előtt visszaáll).
 */
$szuro = $argv[1] ?? '';
$hibas = 0;
$ossz  = 0;
$db    = (string) getenv('SDH_TESZT_DB');
$alap  = (string) getenv('SDH_TESZT_ALAP');
$tiszt = $db !== '' && $alap !== '' && is_file($db);

if ($tiszt && !is_file($alap)) {
    copy($db, $alap);
}

$vissza = static function () use ($db, $alap): void {
    foreach (['-wal', '-shm', '-journal'] as $v) {
        if (is_file($db . $v)) {
            unlink($db . $v);
        }
    }
    copy($alap, $db);
};

foreach (glob(__DIR__ . '/t[0-9]*.php') ?: [] as $f) {
    if ($szuro !== '' && strpos(basename($f), $szuro) === false) {
        continue;
    }
    if ($tiszt) {
        $vissza();
    }
    $ki = [];
    $kod = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1', $ki, $kod);
    echo implode("\n", $ki) . "\n";
    $ossz++;
    $hibas += $kod === 0 ? 0 : 1;
}

if ($tiszt) {
    $vissza();
}

echo $hibas === 0 ? "Mind zöld ({$ossz} teszt)\n" : "{$hibas} teszt hibás a(z) {$ossz}-ból\n";
exit($hibas === 0 ? 0 : 1);
