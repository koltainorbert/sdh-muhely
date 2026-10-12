<?php
/**
 * t41 – 0.55.3: a „Bekerüljön a házipénztárba?” ajánlat megmutatja, mennyi
 * van már a pénztárban.
 *
 * A bejelentett eset: ML 13, két eszköz, mindkettőn 10 000 Ft bevizsgálási díj
 * (előleg 20 000). Az ajánlat 12 000 Ft volt (2 000 + 10 000), mert a fő eszköz
 * korábbi, 8 000 Ft-os díját már beírták a pénztárba. Ez helyes számítás, de a
 * popup nem mondta meg, miért kevesebb – most a `vart` és a `kasszaban` is megy.
 */

require __DIR__ . '/_keret.php';

$lezart = null;

foreach (SDH_Muhely_Munkalap::allapotok() as $k => $a) {
    if (!empty($a['zart']) && empty($a['ervenytelen'])) {
        $lezart = $k;
        break;
    }
}

$ment = static function (int $id, int $u, int $e1, string $fizetett, array $tovabbi, string $allapot): array {
    return t_ajax('sdh_muhely_munkalapok_ment', [
        'id' => $id, 'ugyfel_id' => $u, 'eszkoz_id' => $e1, 'eszkozok_jelen' => '1',
        'allapot' => $allapot, 'keszult' => current_time('Y-m-d'), 'fizetett' => $fizetett,
        'bevizsgalasi_dij' => '1', 'bevizsgalas_mod' => 'levon',
        'tovabbi_eszkoz' => array_keys($tovabbi), 'tovabbi_dij' => $tovabbi,
    ], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
};

/* --- Tiszta eset: egy mentéssel két díj -------------------------------------- */

$u  = t_ugyfel('Ajánlat Anna');
$e1 = t_eszkoz($u, 'Samsung', 'SM-A505FN/DS');
$e2 = t_eszkoz($u, 'Motorola', 'Moto G');
$id = t_munkalap($u, [$e1]);

$p = $ment($id, $u, $e1, '20000', [$e2 => '10000'], $lezart)['data']['penztar'] ?? null;
T::egyezik(20000.0, (float) ($p['osszeg'] ?? 0), 'tiszta eset: 20 000 Ft-ot ajánl');
T::egyezik([10000.0, 10000.0], array_map(static fn ($e) => (float) $e['osszeg'], $p['eszkozok'] ?? []), 'tiszta eset: 10 000 + 10 000 Ft');
T::egyezik(0.0, (float) ($p['kasszaban'] ?? -1), 'tiszta eset: a pénztárban még nincs semmi');

/* --- A bejelentett eset: előbb 8 000 Ft díj be a pénztárba, aztán 10 000 + 10 000 */

$u  = t_ugyfel('Ajánlat Béla');
$e1 = t_eszkoz($u, 'Samsung', 'SM-A505FN/DS');
$e2 = t_eszkoz($u, 'Motorola', 'Moto G');
$id = t_munkalap($u, [$e1]);

$p1 = $ment($id, $u, $e1, '8000', [], 'nyitott')['data']['penztar'] ?? null;
T::egyezik(8000.0, (float) ($p1['osszeg'] ?? 0), 'első mentés: a 8 000 Ft-os díjat ajánlja');

$lap = t_lap($id);
$r   = t_ajax('sdh_muhely_penztar_gyors', [
    'tipus' => 'bevetel', 'leiras' => 'Előleg – díj', 'nev' => 'Ajánlat Béla', 'kp' => '8000', 'kartya' => '0', 'utalas' => '0',
    'ml' => (string) (int) $lap->munkalap_szam, 'szamla' => '', 'megerositve' => '1',
]);
T::igaz(!empty($r['success']), 'a 8 000 Ft bekerül a pénztárba');

$p2 = $ment($id, $u, $e1, '20000', [$e2 => '10000'], $lezart)['data']['penztar'] ?? null;

T::egyezik(12000.0, (float) ($p2['osszeg'] ?? 0), 'második mentés: csak a különbözetet (12 000) ajánlja');
T::egyezik([2000.0, 10000.0], array_map(static fn ($e) => (float) $e['osszeg'], $p2['eszkozok'] ?? []), 'eszközönként: 2 000 + 10 000 Ft (a fő eszköz 8 000-e már bent van)');
T::egyezik(20000.0, (float) ($p2['vart'] ?? 0), 'az ajánlat viszi, mennyi járna összesen (20 000)');
T::egyezik(8000.0, (float) ($p2['kasszaban'] ?? 0), 'az ajánlat viszi, mennyi van már a pénztárban (8 000)');
T::egyezik([10000.0, 10000.0], array_map(static fn ($e) => (float) ($e['vart'] ?? 0), $p2['eszkozok'] ?? []), 'eszközönként is: mennyi járna');
T::egyezik([8000.0, 0.0], array_map(static fn ($e) => (float) $e['kasszaban'], $p2['eszkozok'] ?? []), 'eszközönként is: mennyi van már bent');

/* --- A popup kiírja ---------------------------------------------------------- */

$js = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/penztar.js');
T::tartalmaz($js, "' · már a pénztárban: '", 'a popup kiírja, mennyi van már a pénztárban');
T::tartalmaz($js, 'sdh-pt-ajanlat__eszkoz-bent', 'eszközönként is kiírja');

T::vege('t41 pénztár-ajánlat: látszik, mennyi van már a pénztárban');
