<?php
/** t215 – CAMT.053 XML: jóváírás / terhelés, TxDtls, közlemény, banki hivatkozás, XXE-védelem, ismétlés. */
require __DIR__ . '/_t210-seged.php';
t_katalogus_betolt();

$r9  = t_rendeles([], 45000);
$r10 = t_rendeles([], 12345);
$x = strtr((string) file_get_contents(__DIR__ . '/anyag/bank/camt053.xml'), ['{O9}' => '45000.00', '{K9}' => $r9['kozlemeny'], '{O10}' => '12345', '{K10}' => 'Fizetés ' . $r10['azon']]);

$e = SDH_Sales_Bank_Import::ertelmez($x, 'kivonat.xml');
T::egyezik('camt053', $e['formatum'], 'CAMT.053 felismerve');
T::egyezik(3, count($e['tetelek']), '3 könyvelési tétel');
T::egyezik(45000, $e['tetelek'][0]['osszeg'], 'jóváírás összege');
T::egyezik(-3500, $e['tetelek'][1]['osszeg'], 'terhelés negatív');
T::egyezik('Kilencedik Kft.', $e['tetelek'][0]['partner'], 'fizető neve');
T::egyezik('CAMT-0001-TX', $e['tetelek'][0]['ref'], 'tranzakció banki hivatkozása');
T::egyezik('2026-10-19', $e['tetelek'][0]['datum'], 'könyvelési nap');
T::egyezik($r9['kozlemeny'], $e['tetelek'][0]['kozlemeny'], 'közlemény (NOTPROVIDED nélkül)');

$j = SDH_Sales_Bank_Import::feldolgoz($x, 'kivonat.xml');
T::egyezik(2, count($j['jelentes']['parositva']), '2 párosítva');
T::egyezik(['kozlemeny', 'azon'], array_column($j['jelentes']['parositva'], 'mod'), 'közlemény + rendelésazonosító');
T::egyezik(1, $j['jelentes']['kihagyva'], 'terhelés kihagyva');
T::egyezik('fizetve', SDH_Sales_Rendeles::rendeles((int) $r9['id'])['allapot'], 'rendelés fizetve');

global $wpdb;
T::egyezik('ref:CAMT-0001-TX', $wpdb->get_var($wpdb->prepare('SELECT tranzakcio FROM ' . SDH_Sales_Schema::t('fizetes') . ' WHERE rendeles_id = %d', (int) $r9['id'])), 'tranzakció-kulcs a banki hivatkozás');

$j = SDH_Sales_Bank_Import::feldolgoz($x, 'kivonat.xml');
T::egyezik(2, count($j['jelentes']['mar_feldolgozva']), 'újra: már feldolgozva');

// Másik névtér-verzió (camt.053.001.02) is megy
$j2 = SDH_Sales_Bank_Import::ertelmez(str_replace('camt.053.001.08', 'camt.053.001.02', $x));
T::egyezik(3, count($j2['tetelek']), 'camt.053.001.02 névtér is');

// XXE: külső entitás nem töltődik be
$xxe = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><Document><BkToCstmrStmt><Stmt><Ntry><Amt Ccy="HUF">1</Amt><CdtDbtInd>CRDT</CdtDbtInd><AddtlNtryInf>&x;</AddtlNtryInf></Ntry></Stmt></BkToCstmrStmt></Document>';
$j3 = SDH_Sales_Bank_Import::ertelmez($xxe);
T::igaz($j3['tetelek'] === [] && $j3['hibak'] !== [], 'DOCTYPE-os XML elutasítva (XXE)');
T::nem_tartalmaz(wp_json_encode($j3), 'root:', 'nincs fájltartalom');

$j4 = SDH_Sales_Bank_Import::ertelmez('<Document><nem');
T::igaz($j4['hibak'] !== [], 'hibás XML: hibaüzenet');

T::vege('t215 CAMT.053');
