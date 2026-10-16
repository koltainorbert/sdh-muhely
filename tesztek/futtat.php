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

/*
 * Tiszta adatbázis minden tesztnél (H9, 0.82.1) – SQLite-os tesztoldalon:
 *   SDH_TESZT_DB=/út/wp-content/database/teszt.sqlite  SDH_TESZT_ALAP=/út/alap.sqlite
 * Az első futás az alapot a mostani adatbázisból menti (ezért friss telepítés után indítsd);
 * utána minden tesztfájl előtt az alap kerül vissza – a tesztek nem hagynak nyomot egymásnak.
 */
$db_fajl  = (string) getenv('SDH_TESZT_DB');
$alap     = (string) getenv('SDH_TESZT_ALAP');
$tisztit  = $db_fajl !== '' && $alap !== '' && is_file($db_fajl);

if ($tisztit && !is_file($alap)) {
    copy($db_fajl, $alap);
    echo "Az alap adatbázis elmentve: {$alap}\n";
}

$visszaallit = static function () use ($db_fajl, $alap): void {
    foreach (['-wal', '-shm', '-journal'] as $vege) {
        if (is_file($db_fajl . $vege)) {
            unlink($db_fajl . $vege);
        }
    }

    copy($alap, $db_fajl);
};

foreach (glob(__DIR__ . '/t[0-9]*.php') ?: [] as $fajl) {
    if ($szuro !== '' && strpos(basename($fajl), $szuro) === false) {
        continue;
    }

    if ($tisztit) {
        $visszaallit();
    }

    $kimenet = [];
    $kod     = 0;

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fajl) . ' 2>&1', $kimenet, $kod);

    echo implode("\n", $kimenet) . "\n";

    $ossz++;
    $hibas += $kod === 0 ? 0 : 1;
}

if ($tisztit) {
    $visszaallit();
}

echo $hibas === 0 ? "Mind zöld ({$ossz} teszt)" : "{$hibas} teszt hibás a(z) {$ossz}-ból";
echo $tisztit ? ", minden teszt tiszta adatbázisról.\n" : ".\n";

exit($hibas === 0 ? 0 : 1);
