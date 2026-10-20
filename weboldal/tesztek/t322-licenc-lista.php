<?php
/**
 * t322 – Licencek lista: szűrők (aktív, 30 napon belül lejár, lejárt, felfüggesztett, próba) és darabszámaik, keresés
 * (kulcs, cég, e-mail, adószám kötőjellel / nélkül, domain a telepítés jelentéséből), rendezés, lapozás, vevő-szűrő,
 * a részletek (vevő, telepítések, letöltések, rendelések, napló), és a mérés: 300 licencnél a lista ideje.
 */
require __DIR__ . '/_t32-seged.php';

$ma  = wp_date('Y-m-d');
$nap = static fn (int $n): string => wp_date('Y-m-d', (int) strtotime($ma . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));

$va = t32_vevo('Alfa Szerviz Kft.', 'info@alfa.example', '12345678-2-42');
$vb = t32_vevo('Béta Javító Bt.', 'iroda@beta.example', '87654321-1-13');
$vc = t32_vevo('Gamma Kft.', 'gamma@gamma.example');

$aktiv  = t32_licenc($va, ['lejar' => $nap(200)]);
$lejar  = t32_licenc($vb, ['lejar' => $nap(10), 'csomag' => 'Start', 'modulok' => ['uzenetek', 'tac']]);
$lejart = t32_licenc($vc, ['lejar' => $nap(-5)]);
$tiltva = t32_licenc($vc, ['lejar' => $nap(100), 'allapot' => 'tiltva']);
$proba  = t32_licenc($va, ['lejar' => $nap(20), 'proba' => 1]);
$orok   = t32_licenc($vb, ['lejar' => '']);
t32_peldany((int) $aktiv->id, ['cimke' => 'Belvárosi bolt', 'allapot' => (string) wp_json_encode(['rendszer' => ['url' => 'https://alfa-szerviz.example']])]);

$r = t_rest('GET', '/admin/licencek', ['szuro' => 'mind'])->get_data();
T::egyezik(6, (int) $r['ossz'], 'mind: 6');
T::egyezik(['mind' => 6, 'aktiv' => 4, 'lejar' => 2, 'lejart' => 1, 'tiltva' => 1, 'proba' => 1], $r['szamok'], 'darabszámok (az aktívban a hamarosan lejárók is)');

$kulcsok = static fn (array $l): array => array_map(static fn ($x) => $x['kulcs'], $l);
$sz = static fn (string $s): array => $kulcsok(t_rest('GET', '/admin/licencek', ['szuro' => $s])->get_data()['lista']);

T::egyezik(4, count($sz('aktiv')), 'szűrő aktív: 4');
T::igaz(in_array($lejar->kulcs, $sz('lejar'), true) && in_array($proba->kulcs, $sz('lejar'), true) && count($sz('lejar')) === 2, 'szűrő hamarosan lejár: a 10 és 20 napos');
T::egyezik([$lejart->kulcs], $sz('lejart'), 'szűrő lejárt');
T::egyezik([$tiltva->kulcs], $sz('tiltva'), 'szűrő felfüggesztett (a lejárat ellenére is)');
T::egyezik([$proba->kulcs], $sz('proba'), 'szűrő próba');

$q = static fn (string $s): array => $kulcsok(t_rest('GET', '/admin/licencek', ['q' => $s])->get_data()['lista']);
T::egyezik([$lejar->kulcs], $q(substr((string) $lejar->kulcs, -9)), 'keresés: kulcs részlete');
T::egyezik(2, count($q('alfa szerviz')), 'keresés: cégnév (kisbetűvel)');
T::egyezik(2, count($q('iroda@beta')), 'keresés: e-mail');
T::egyezik(2, count($q('12345678-2-42')), 'keresés: adószám kötőjellel');
T::egyezik(2, count($q('12345678242')), 'keresés: adószám kötőjel nélkül');
T::egyezik([$aktiv->kulcs], $q('alfa-szerviz.example'), 'keresés: domain a telepítés jelentéséből');
T::egyezik([$aktiv->kulcs], $q('belvárosi'), 'keresés: telepítés címkéje');
T::egyezik(0, count($q('nincs-ilyen-xyz')), 'keresés: nincs találat');

// Vevő-szűrő
T::egyezik(2, (int) t_rest('GET', '/admin/licencek', ['vevo_id' => $vc])->get_data()['ossz'], 'vevő-szűrő');

// Rendezés: lejárat szerint növekvő (a lejárat nélküli a végén), csökkenő
$l = t_rest('GET', '/admin/licencek', ['rend' => 'lejar'])->get_data()['lista'];
T::egyezik($lejart->kulcs, $l[0]['kulcs'], 'rendezés lejárat szerint: a lejárt elöl');
T::egyezik($orok->kulcs, $l[count($l) - 1]['kulcs'], 'rendezés lejárat szerint: a lejárat nélküli a végén');
$l = t_rest('GET', '/admin/licencek', ['rend' => 'lejar', 'irany' => 'csokken'])->get_data()['lista'];
T::egyezik($orok->kulcs, $l[0]['kulcs'], 'csökkenő: fordítva');
$l = t_rest('GET', '/admin/licencek', ['rend' => 'vevo'])->get_data()['lista'];
T::egyezik('Alfa Szerviz Kft.', $l[0]['vevo'], 'rendezés vevő szerint');

// Lista-elem mezői
$e = t_rest('GET', '/admin/licencek', ['q' => (string) $aktiv->kulcs])->get_data()['lista'][0];
T::egyezik('info@alfa.example', $e['vevo_email'], 'elem: vevő e-mail');
T::egyezik(1, $e['peldany_db'], 'elem: telepítésszám');
T::igaz($e['utolso_jel'] > time() - 600, 'elem: utolsó jelentkezés');
T::egyezik('aktiv', $e['kod'], 'elem: állapotkód');
T::egyezik(200, $e['lejar_nap'], 'elem: hátralévő napok');
$e2 = t_rest('GET', '/admin/licencek', ['q' => (string) $lejar->kulcs])->get_data()['lista'][0];
T::egyezik(['uzenetek', 'tac'], $e2['modulok'], 'elem: modullista');

// Részletek
$d = t_rest('GET', '/admin/licenc/' . $aktiv->id)->get_data();
T::egyezik('Alfa Szerviz Kft.', $d['vevo']['nev'], 'részletek: vevő');
T::egyezik('12345678-2-42', $d['vevo']['adoszam'], 'részletek: adószám');
T::egyezik(1, count($d['peldanyok']), 'részletek: telepítések');
T::egyezik('https://alfa-szerviz.example', $d['peldanyok'][0]['webhely'], 'részletek: a telepítés webhelye');
T::egyezik('online', $d['peldanyok'][0]['statusz'], 'részletek: a telepítés állapota');
T::igaz(isset($d['letoltesek'], $d['rendelesek'], $d['esemenyek'], $d['kozpont_rendelesek']), 'részletek: letöltések, rendelések, napló');
T::igaz(count($d['esemenyek']) >= 1, 'részletek: a létrehozás a naplóban');

// A webhely rendelése a licenchez (licenckulcs szerint)
global $wpdb;
$wpdb->insert(SDH_Sales_Schema::t('rendeles'), ['azon' => 'R-2026-322001', 'allapot' => 'kiszolgalva', 'tipus' => 'uj', 'vevo' => '{"ceg":"Alfa"}', 'tetelek' => '[]', 'netto' => 1000, 'afa' => 270, 'brutto' => 1270, 'email' => 'info@alfa.example', 'licenc_kulcs' => (string) $aktiv->kulcs, 'szamla_vegszamla' => 'E-T-2026-1', 'letrehozva' => gmdate('Y-m-d H:i:s')]);
$d = t_rest('GET', '/admin/licenc/' . $aktiv->id)->get_data();
T::egyezik('R-2026-322001', $d['rendelesek'][0]['azon'] ?? '', 'részletek: a webes rendelés a licencnél');
T::egyezik('E-T-2026-1', $d['rendelesek'][0]['vegszamla'] ?? '', 'részletek: a végszámla száma');

// Lapozás
for ($i = 0; $i < 294; $i++) {
    t32_licenc($vb, ['lejar' => $nap(300 + $i)]);
}
$t0 = microtime(true);
$r  = t_rest('GET', '/admin/licencek', ['db' => 25, 'oldal' => 3, 'rend' => 'lejar'])->get_data();
$ms = (microtime(true) - $t0) * 1000;
T::egyezik(300, (int) $r['ossz'], 'lapozás: 300 licenc');
T::egyezik(25, count($r['lista']), 'lapozás: 25 / oldal');
T::egyezik(3, (int) $r['oldal'], 'lapozás: 3. oldal');
$r2 = t_rest('GET', '/admin/licencek', ['db' => 25, 'oldal' => 99])->get_data();
T::egyezik(12, (int) $r2['oldal'], 'lapozás: túlfutó oldal → az utolsó');
T::igaz($ms < 1500, sprintf('mérés: 300 licenc listája %.0f ms (< 1500)', $ms));

T::vege(sprintf('t322 licencek lista (300 licenc: %.0f ms)', $ms));
