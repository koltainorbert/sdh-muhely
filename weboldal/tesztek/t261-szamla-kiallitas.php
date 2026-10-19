<?php
/**
 * t261 – Számlázz.hu kiállítás: díjbekérő a rendelés hookjára, végszámla a díjbekérőből, idempotencia (egy kérés),
 * tesztüzem tesztkulccsal („MINTA”), minta mód kulcs nélkül (helyi MINTA-PDF, nincs hálózat), kikapcsolt állapot,
 * automatikus kapcsoló, PDF a védett mappában.
 */
require __DIR__ . '/_t26-seged.php';

global $wpdb;

// --- minta mód: tesztüzem, kulcs nélkül ---
SDH_Sales::b_ment(['szamlazz.teszt' => true]);
SDH_Sales::titkos_ment('szamlazz_teszt_kulcs', '');
SDH_Sales::titkos_ment('szamlazz_agent_kulcs', '');
T::egyezik('minta', SDH_Sales_Szamlazz::mod(), 'mód: minta (kulcs nélkül)');

$id = t26_rendeles();
do_action('sdh_sales_rendeles_letrehozva', $id);
$r = SDH_Sales_Szamlazz::rendeles($id);
T::igaz(strncmp((string) $r['szamla_dijbekero'], 'MINTA-D-', 8) === 0, 'hook: minta-díjbekérő száma');
T::egyezik(0, count($GLOBALS['t26_agent']), 'minta: nincs hálózati kérés');
$ut = SDH_Sales_Szamlazz::dijbekero_pdf($id);
T::igaz(is_string($ut) && is_file($ut), 'minta-díjbekérő PDF megvan');
$pdf = (string) file_get_contents((string) $ut);
T::igaz(strncmp($pdf, '%PDF-1.4', 8) === 0 && substr(rtrim($pdf), -5) === '%%EOF', 'PDF fej/láb');
T::tartalmaz($pdf, '(MINTA)', 'PDF: MINTA vízjel');
T::tartalmaz($pdf, "D\xCDJBEK\xC9R", 'PDF: DÍJBEKÉRŐ cím (WinAnsi)');
preg_match('/startxref\n(\d+)/', $pdf, $m);
T::egyezik('xref', substr($pdf, (int) ($m[1] ?? 0), 4), 'PDF: startxref a helyes bájtra mutat');
preg_match_all('/(\d{10}) 00000 n /', $pdf, $mm);
$jo = true;
foreach ($mm[1] as $i => $hol) {
    $jo = $jo && substr($pdf, (int) $hol, strlen(($i + 1) . ' 0 obj')) === ($i + 1) . ' 0 obj';
}
T::igaz($jo && count($mm[1]) === 6, 'PDF: xref eltolások pontosak');

$v = SDH_Sales_Szamlazz::vegszamla($id);
T::igaz(!$v['ok'] && strpos($v['uzenet'], 'nincs kifizetve') !== false, 'végszámla fizetés előtt: nem');
t26_fizet($id);
$v = SDH_Sales_Szamlazz::vegszamla($id);
T::igaz($v['ok'] && $v['szamlaszam'] === 'MINTA-' . $r['azon'], 'minta-végszámla');
T::igaz(is_file($v['pdf']) && strpos((string) file_get_contents($v['pdf']), "(SZ\xC1MLA)") !== false, 'minta-végszámla PDF');
T::igaz(strpos((string) file_get_contents($v['pdf']), 'FIZETVE') !== false, 'PDF: FIZETVE');

// --- tesztüzem tesztkulccsal: a hamis Agent felel ---
t26_teszt_kulcs();
T::egyezik('teszt', SDH_Sales_Szamlazz::mod(), 'mód: teszt');
$GLOBALS['t26_agent'] = [];
$id = t26_rendeles();
$kesz = [];
add_action('sdh_sales_szamla_kesz', static function ($rid, $tipus, $sz) use (&$kesz): void { $kesz[] = $tipus . ':' . $sz; }, 10, 3);

do_action('sdh_sales_rendeles_letrehozva', $id);
$r = SDH_Sales_Szamlazz::rendeles($id);
T::egyezik('E-TESZT-2026-1', (string) $r['szamla_dijbekero'], 'díjbekérő száma a válaszból');
T::egyezik(1, count($GLOBALS['t26_agent']), 'egy Agent-kérés');
T::egyezik('action-xmlagentxmlfile', $GLOBALS['t26_agent'][0]['mezo'] ?? '', 'multipart mezőnév');
T::tartalmaz($GLOBALS['t26_agent'][0]['xml'] ?? '', 'MINTA', 'tesztkulcsnál is MINTA a megjegyzésben');
$ut = SDH_Sales_Szamlazz::dijbekero_pdf($id);
T::igaz(is_string($ut) && strpos($ut, '/uploads/' . SDH_Sales_Szamlazz::MAPPA . '/') !== false, 'PDF a védett mappában');
T::igaz(preg_match('/[a-f0-9]{40}\.pdf$/', (string) $ut) === 1, 'kitalálhatatlan fájlnév');

// ismételt hook / hívás: nincs új kérés
do_action('sdh_sales_rendeles_letrehozva', $id);
$d2 = SDH_Sales_Szamlazz::dijbekero($id);
T::igaz($d2['ok'] && $d2['szamlaszam'] === 'E-TESZT-2026-1', 'díjbekérő idempotens');
T::egyezik(1, count($GLOBALS['t26_agent']), 'idempotens: nincs új Agent-kérés');

// végszámla
t26_fizet($id);
$v = SDH_Sales_Szamlazz::vegszamla($id);
T::igaz($v['ok'] && $v['szamlaszam'] === 'E-TESZT-2026-2', 'végszámla kész');
T::tartalmaz($GLOBALS['t26_agent'][1]['xml'] ?? '', '<dijbekeroSzamlaszam>E-TESZT-2026-1</dijbekeroSzamlaszam>', 'végszámla a díjbekérőre hivatkozik');
T::tartalmaz($GLOBALS['t26_agent'][1]['xml'] ?? '', '<fizetve>true</fizetve>', 'végszámla fizetve');
$v2 = SDH_Sales_Szamlazz::vegszamla($id);
T::igaz($v2['ok'] && $v2['szamlaszam'] === 'E-TESZT-2026-2' && is_file($v2['pdf']), 'végszámla idempotens, PDF megvan');
T::egyezik(2, count($GLOBALS['t26_agent']), 'végszámla: összesen 2 Agent-kérés');
T::egyezik(['dijbekero:E-TESZT-2026-1', 'vegszamla:E-TESZT-2026-2'], $kesz, 'sdh_sales_szamla_kesz hook egyszer-egyszer');
T::egyezik('E-TESZT-2026-2', (string) $wpdb->get_var($wpdb->prepare('SELECT szamla_vegszamla FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE id = %d', $id)), 'szamla_vegszamla mentve');

// kártyás rendelésnél nincs díjbekérő
$id3 = t26_rendeles(['fizmod' => 'barion']);
do_action('sdh_sales_rendeles_letrehozva', $id3);
T::egyezik('', (string) SDH_Sales_Szamlazz::rendeles($id3)['szamla_dijbekero'], 'kártyás: nincs díjbekérő');
// díjbekérő nélküli végszámla: nincs dijbekeroSzamlaszam
t26_fizet($id3);
$GLOBALS['t26_agent'] = [];
$v3 = SDH_Sales_Szamlazz::vegszamla($id3);
T::igaz($v3['ok'] && strpos($GLOBALS['t26_agent'][0]['xml'] ?? '', 'dijbekeroSzamlaszam') === false, 'díjbekérő nélküli végszámla');

// éles mód
SDH_Sales::b_ment(['szamlazz.teszt' => false]);
T::egyezik('ki', SDH_Sales_Szamlazz::mod(), 'éles kulcs nélkül: ki');
$id4 = t26_rendeles();
do_action('sdh_sales_rendeles_letrehozva', $id4);
T::egyezik('', (string) SDH_Sales_Szamlazz::rendeles($id4)['szamla_dijbekero'], 'kikapcsolva: nincs díjbekérő');
SDH_Sales::titkos_ment('szamlazz_agent_kulcs', 'eleskulcs999');
T::egyezik('eles', SDH_Sales_Szamlazz::mod(), 'mód: éles');
$GLOBALS['t26_agent'] = [];
SDH_Sales_Szamlazz::dijbekero($id4);
T::nem_tartalmaz($GLOBALS['t26_agent'][0]['xml'] ?? 'MINTA', 'MINTA', 'éles: nincs MINTA');
T::tartalmaz($GLOBALS['t26_agent'][0]['xml'] ?? '', '<szamlaagentkulcs>eleskulcs999</szamlaagentkulcs>', 'éles kulcs megy');

// automatikus kapcsoló (Norbi döntése: alapból igaz)
T::igaz(SDH_Sales_Szamlazz::automatikus(), 'automatikus alapból igaz');
SDH_Sales::b_ment(['szamlazz.automatikus' => false]);
T::igaz(!SDH_Sales_Szamlazz::automatikus(), 'automatikus kikapcsolható');

// a titok nem szivárog a REST-válaszokba
$v = t_rest('POST', '/admin/szamla/' . $id4 . '/ujra', ['tipus' => 'dijbekero']);
T::nem_tartalmaz((string) wp_json_encode($v->get_data()), 'eleskulcs999', 'kulcs nincs a REST-válaszban');

// Rejtés: a PDF-ek, a fájlútvonal (uploads/portal-szamla) és az Agentnek ment kérések.
T::egyezik('portal-szamla', SDH_Sales_Szamlazz::MAPPA, 'PDF-mappa semleges néven');
t_rejtett((string) file_get_contents((string) SDH_Sales_Szamlazz::dijbekero_pdf($id)), 'díjbekérő PDF');
T::tartalmaz((string) $ut, '/uploads/portal-szamla/', 'PDF a semleges nevű feltöltési mappában');
t_rejtett(basename((string) $ut), 'PDF fájlnév');
t_rejtett(implode("\n", array_column($GLOBALS['t26_agent'], 'xml')), 'Agent-kérések XML-je');
T::vege('t261 számla-kiállítás');
