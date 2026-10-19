<?php
/**
 * t267 – Emlékeztetők: 24 órás és 1 órás egyszer-egyszer (ismételt cron nem küld újra), lemondottnak nem, múltbelinek
 * nem, rövid időn belül foglaltnál a már „lejárt” emlékeztető nem megy (foglaláskor jelölve), a levélben működő
 * kezelő link, a cron hookon (SDH_Sales::CRON) át is fut.
 */
require __DIR__ . '/_t26-seged.php';

global $wpdb;
t26_naptar(['naptar.min_ora' => 0]);
$t   = SDH_Sales_Schema::t('foglalas');
$be  = ['tipus' => 'demo', 'nev' => 'Kis Anna', 'email' => 'anna@pelda.hu'];
$most = t26_bp('2026-10-19 08:00');

$a = SDH_Sales_Naptar::foglal(['kezdet' => '2026-10-21T07:00:00Z'] + $be, $most); // szerda 9:00
$b = SDH_Sales_Naptar::foglal(['kezdet' => '2026-10-21T09:00:00Z', 'email' => 'b@pelda.hu'] + $be, $most);
SDH_Sales_Naptar::lemond($b['token'], $most);

$GLOBALS['t26_levelek'] = [];
T::egyezik(0, SDH_Sales_Naptar::emlekezteto(t26_bp('2026-10-19 12:00')), '45 órával előtte: semmi');
$n = SDH_Sales_Naptar::emlekezteto(t26_bp('2026-10-20 09:30'));
T::egyezik(1, $n, '24 órán belül: 1 emlékeztető (a lemondottnak nem)');
T::egyezik(1, (int) SDH_Sales_Naptar::egy($a['id'])['emlekezteto_kuldve'], 'jelölés: 24 órás kiment');
$l = $GLOBALS['t26_levelek'][0] ?? [];
T::tartalmaz($l['subject'] ?? '', 'Emlékeztető', '24 órás levél');
T::igaz(preg_match('/foglalas=(\d+-[a-f0-9]{32})/', (string) ($l['message'] ?? ''), $m) === 1 && SDH_Sales_Naptar::tokennel($m[1]) !== null, 'emlékeztetőben működő kezelő link');
T::igaz(SDH_Sales_Naptar::tokennel($a['token']) !== null, 'az eredeti link is érvényes marad');

T::egyezik(0, SDH_Sales_Naptar::emlekezteto(t26_bp('2026-10-20 12:00')), 'ismételt cron: nem küld újra');
T::egyezik(0, SDH_Sales_Naptar::emlekezteto(t26_bp('2026-10-21 07:00')), '2 órával előtte: semmi');
T::egyezik(1, SDH_Sales_Naptar::emlekezteto(t26_bp('2026-10-21 08:05')), '1 órán belül: 1 órás emlékeztető');
T::egyezik(2, (int) SDH_Sales_Naptar::egy($a['id'])['emlekezteto_kuldve'], 'jelölés: 1 órás is kiment');
T::egyezik(0, SDH_Sales_Naptar::emlekezteto(t26_bp('2026-10-21 08:30')), '1 órás: csak egyszer');
T::egyezik(0, SDH_Sales_Naptar::emlekezteto(t26_bp('2026-10-21 09:30')), 'kezdés után: semmi');
T::egyezik(2, count(array_filter($GLOBALS['t26_levelek'], static fn ($x) => $x['to'] === 'anna@pelda.hu')), 'összesen 2 emlékeztető-levél');

// ha a cron kimaradt, és már 1 órán belül vagyunk: csak az 1 órás megy (nem mindkettő)
$c = SDH_Sales_Naptar::foglal(['kezdet' => '2026-10-22T07:00:00Z', 'email' => 'c@pelda.hu'] + $be, $most);
$GLOBALS['t26_levelek'] = [];
T::egyezik(1, SDH_Sales_Naptar::emlekezteto(t26_bp('2026-10-22 08:30')), 'kimaradt cron: egy levél');
T::egyezik(2, (int) SDH_Sales_Naptar::egy($c['id'])['emlekezteto_kuldve'], 'kimaradt cron: rögtön 1 órás állapot');

// 20 órával előtte foglalt: a 24 órás már nem megy külön (a visszaigazolás elég), az 1 órás igen
$d = SDH_Sales_Naptar::foglal(['kezdet' => '2026-10-20T12:00:00Z', 'email' => 'd@pelda.hu'] + $be, t26_bp('2026-10-19 18:00'));
T::egyezik(1, (int) SDH_Sales_Naptar::egy($d['id'])['emlekezteto_kuldve'], 'rövid távú foglalás: 24 órás jelölve');
T::egyezik(0, SDH_Sales_Naptar::emlekezteto(t26_bp('2026-10-19 19:00')), 'rövid távú: nincs külön 24 órás');
T::egyezik(1, SDH_Sales_Naptar::emlekezteto(t26_bp('2026-10-20 13:30')), 'rövid távú: az 1 órás megy');

// cron hookon át (valós idő): egy 30 perc múlva kezdődő foglalás
$wpdb->insert($t, ['tipus' => 'demo', 'kezdet' => gmdate('Y-m-d H:i:s', time() + 1800), 'veg' => gmdate('Y-m-d H:i:s', time() + 3600), 'nev' => 'Cron', 'email' => 'cron@pelda.hu', 'allapot' => 'foglalt', 'token' => 'nonce1', 'emlekezteto_kuldve' => 0, 'ido' => gmdate('Y-m-d H:i:s')]);
$cid = (int) $wpdb->insert_id;
$GLOBALS['t26_levelek'] = [];
T::igaz(has_action(SDH_Sales::CRON, ['SDH_Sales_Naptar', 'cron']) !== false, 'SDH_Sales::CRON-ra kötve');
SDH_Sales_Naptar::cron();
SDH_Sales_Naptar::cron();
T::egyezik(1, count(array_filter($GLOBALS['t26_levelek'], static fn ($x) => $x['to'] === 'cron@pelda.hu')), 'SDH_Sales::CRON: egyszer küld');
T::egyezik(2, (int) SDH_Sales_Naptar::egy($cid)['emlekezteto_kuldve'], 'cron: 1 órás jelölés');

// Rejtés: az emlékeztető levelek.
T::igaz($GLOBALS['t26_levelek'] !== [], 'van emlékeztető levél a rejtés-ellenőrzéshez');
foreach ($GLOBALS['t26_levelek'] as $i => $l) {
    t_rejtett($l['subject'] . "\n" . $l['message'] . "\n" . implode("\n", $l['csatolmany']), 'emlékeztető #' . ($i + 1));
}
T::vege('t267 emlékeztetők');
