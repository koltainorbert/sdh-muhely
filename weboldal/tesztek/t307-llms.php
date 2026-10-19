<?php
/** t307 – llms.txt / llms-full.txt: formátum (H1, idézet, H2 linklisták, Optional), árak a tárból, GYIK, telepítés, cache, méret. */
require __DIR__ . '/_keret.php';
require __DIR__ . '/_seo-minta.php';

$ids = t_seo_minta();
$s   = SDH_Sales_Llms::szoveg(false);
$sor = explode("\n", $s);
T::egyezik('# ' . SDH_Sales::termeknev(), $sor[0], 'első sor H1 = termeknev');
T::egyezik(1, preg_match_all('/^# /m', $s), 'egy H1');
T::igaz(strpos($sor[2] ?? '', '> ') === 0, 'összefoglaló idézetblokk a H1 után');
foreach (['Funkciók', 'Modulok', 'Árak', 'Iparágak', 'GYIK', 'Telepítés', 'Jogi', 'Optional'] as $h) {
    T::igaz(preg_match('/^## ' . preg_quote($h, '/') . '$/m', $s) === 1, "H2: {$h}");
}
T::igaz(strpos($s, '## Optional') > strpos($s, '## Jogi'), 'Optional a végén');
$linkek = preg_match_all('/^- \[[^\]]+\]\(https?:\/\/[^)\s]+\)(: .+)?$/m', $s);
$listak = preg_match_all('/^- /m', $s);
T::egyezik($listak, $linkek, 'minden listaelem [név](url): megjegyzés alakú');
T::tartalmaz($s, '- [Munkalapok](' . get_permalink($ids['funkciok/munkalapok']) . '): Digitális munkalap', 'funkció-link leírással');
T::tartalmaz($s, '(' . get_permalink($ids['gyik']) . '#gyik-ar-1)', 'GYIK-horgony');
T::tartalmaz($s, '[ÁSZF](' . get_permalink($ids['jogi/aszf']) . ')', 'jogi oldal');
T::nem_tartalmaz($s, '{termeknev}', 'tokenek cserélve');
T::nem_tartalmaz($s, '<', 'nincs HTML');

$t = SDH_Sales_Llms::szoveg(true);
T::egyezik('# ' . SDH_Sales::termeknev(), strtok($t, "\n"), 'full: H1');
T::tartalmaz($t, '| Start | 9 900 Ft | 12 573 Ft | 99 000 Ft | 125 730 Ft | 2 | 1 |', 'full: csomagár nettó + bruttó a tárból');
T::tartalmaz($t, '2 900 Ft + 27% ÁFA (bruttó 3 683 Ft) / hó', 'full: modulár');
T::tartalmaz($t, 'Telepítés**: 30 000 Ft + 27% ÁFA', 'full: szolgáltatás ára');
T::tartalmaz($t, '**Mennyibe kerül a ' . SDH_Sales::termeknev() . "?**\n\nA Start csomag havi **9 900 Ft + ÁFA**.", 'full: GYIK kérdés–válasz Markdownban');
T::tartalmaz($t, "- PHP 8.1+\n- MySQL", 'full: lista a válaszban');
T::tartalmaz($t, '[Leírás](https://example.com/x)', 'full: link Markdownban');
T::nem_tartalmaz($t, 'alert', 'full: script kiszűrve');
T::tartalmaz($t, "### Követelmények\n\n- PHP 8.1 vagy újabb", 'full: követelmények');
T::tartalmaz($t, '1. **Töltsd le a csomagot**', 'full: telepítési lépések');
T::tartalmaz($t, '- Cégnév: Teszt Kft.', 'full: cégadat');
T::nem_tartalmaz($t, '<', 'full: nincs HTML');

// Ár változik → a tárból jön, a cache érvénytelenedik.
sleep(1);
SDH_Sales_Tartalom::ment('csomag', 'start', ['kulcs' => 'start', 'nev' => 'Start', 'havi' => 11900, 'eves' => 119000, 'felhasznalo_alap' => 2, 'telephely_alap' => 1], null, 1, true, 'hu', false);
T::tartalmaz(SDH_Sales_Llms::szoveg(true), '| Start | 11 900 Ft | 15 113 Ft |', 'árváltozás után friss (cache-kulcs: tartalom-ujjlenyomat)');

// HTTP-válasz: fejlécek, ETag/304.
$v = SDH_Sales_Llms::valasz(false);
T::egyezik(200, $v['status'], '200');
T::egyezik('text/plain; charset=utf-8', $v['fejlec']['Content-Type'], 'Content-Type');
T::tartalmaz($v['fejlec']['Cache-Control'], 'max-age=3600', 'Cache-Control');
T::egyezik(304, SDH_Sales_Llms::valasz(false, $v['fejlec']['ETag'])['status'], 'ETag → 304');
T::egyezik('llms', SDH_Sales_Llms::keres_ut('/wp/llms.txt'), 'útvonal: /llms.txt');
T::egyezik('llms-full', SDH_Sales_Llms::keres_ut('/wp/llms-full.txt?x=1'), 'útvonal: /llms-full.txt');
T::egyezik('', SDH_Sales_Llms::keres_ut('/wp/valami/llms.txt'), 'csak a pontos út');

// Méretkorlát
SDH_Sales::b_ment(['seo.llms_full_max' => 2000]);
$k = SDH_Sales_Llms::general(true);
T::igaz(strlen($k) < 2300 && str_contains($k, 'korlátozott'), 'méretkorlát (' . strlen($k) . ' B)');
T::igaz(mb_check_encoding($k, 'UTF-8'), 'levágás után is érvényes UTF-8');

t_rejtett($s, 'llms.txt');
t_rejtett($t, 'llms-full.txt');
t_rejtett($v['test'] ?? '', 'llms.txt HTTP-válasz', (array) $v['fejlec']);
T::igaz(true, 'méret: llms.txt ' . strlen($s) . ' B, llms-full.txt ' . strlen($t) . ' B');
T::vege('t307 llms.txt (' . strlen($s) . ' B / full ' . strlen($t) . ' B)');
