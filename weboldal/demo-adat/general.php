<?php
/**
 * Bemutató-adat generátor – parancssor.
 *
 *   php weboldal/demo-adat/general.php --wp=/út/wp-load.php [--db=10000] [--kepek=/út/kepek] [--pillanatkep]
 *
 * --db           összes rekord (alap 10 000: ügyfél 2 000, eszköz 2 500, munkalap 2 300 tételekkel és hibákkal,
 *                termék 1 200 készlettel, szolgáltatás 300, pénztártétel 1 200, naptár 300 – arányosan)
 * --kepek        a képek mappája (manifest.json + webp); alap: e mappa kepek/ alkönyvtára
 * --pillanatkep  utána pillanatkép is (ez lesz az éjjeli visszaállítás alapja)
 *
 * Csak a bemutató példányon! A műhely és a bemutató bővítmény legyen bekapcsolva. Kimenet: egy összegző sor + idők.
 */

if (PHP_SAPI !== 'cli') {
    exit("Csak parancssorból futtatható.\n");
}

$bm_opt = getopt('', ['wp:', 'db::', 'kepek::', 'pillanatkep']);
$bm_wp  = (string) ($bm_opt['wp'] ?? '');

if ($bm_wp === '' || !is_file($bm_wp)) {
    fwrite(STDERR, "Használat: php general.php --wp=/út/wp-load.php [--db=10000] [--kepek=/út] [--pillanatkep]\n");
    exit(2);
}

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require $bm_wp;

if (!class_exists('SDH_Demo_Generator') || !SDH_Demo::muhely_kesz()) {
    fwrite(STDERR, "A bemutató bővítmény és a műhely legyen bekapcsolva ezen a webhelyen.\n");
    exit(2);
}

$bm_admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($bm_admin[0] ?? 0));

$bm_db    = max(100, min(300000, (int) ($bm_opt['db'] ?? 10000)));
$bm_kepek = isset($bm_opt['kepek']) && $bm_opt['kepek'] !== false ? (string) $bm_opt['kepek'] : __DIR__ . '/kepek';

try {
    $bm_r = SDH_Demo_Generator::general($bm_db, $bm_kepek);
} catch (Throwable $bm_e) {
    fwrite(STDERR, 'Hiba: ' . $bm_e->getMessage() . "\n");
    exit(1);
}

$bm_reszek = [];

foreach ($bm_r['db'] as $bm_sz => $bm_n) {
    $bm_reszek[] = $bm_sz . ' ' . $bm_n;
}

printf("Generálás: %d rekord (%s; %d tétel, %d kép, %d fotó) – %.2f mp\n", $bm_r['osszes'], implode(', ', $bm_reszek), $bm_r['tetelek'], $bm_r['kepek'], $bm_r['fotok'], $bm_r['mp']);
printf("  lépések: %s\n", implode(', ', array_map(static fn ($k, $v): string => $k . ' ' . $v . ' mp', array_keys($bm_r['idok']), $bm_r['idok'])));

if (isset($bm_opt['pillanatkep'])) {
    $bm_p = SDH_Demo_Pillanatkep::keszit();
    printf("Pillanatkép: %d tábla, %d sor, %.1f KB – %.2f mp\n", $bm_p['tablak'], $bm_p['sorok'], $bm_p['meret'] / 1024, $bm_p['mp']);
}
