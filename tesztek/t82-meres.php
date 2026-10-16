<?php
/**
 * t82 – H10 (0.90.0): adatbázis-mérés csak olvasva – a böngészőből is (Beállítások › Sebesség).
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$M = 'SDH_Muhely_Meres';

T::egyezik('SELECT 1', $M::iras_tilt('INSERT INTO x VALUES (1)'), 'író lekérdezés tiltva');
T::egyezik('SELECT 1', $M::iras_tilt('  update x set a = 1'), 'kisbetűs, szóközös is');
T::egyezik('SELECT id FROM x', $M::iras_tilt('SELECT id FROM x'), 'olvasó lekérdezés marad');

$elotte = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('valtozasnaplo'));
$lassu  = [];
$sorok  = $M::futtat($lassu);
$szoveg = implode("\n", $sorok);
T::tartalmaz($szoveg, 'SDH Műhely mérés (csak olvas)', 'a mérés lefut');
T::tartalmaz($szoveg, 'Riportok: utolsó egy év', 'az új funkciók is');
T::tartalmaz($szoveg, 'Semmi nem íródott az adatbázisba', 'záró sor');
T::egyezik($elotte, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('valtozasnaplo')), 'semmi nem íródott');
T::igaz(!has_filter('query', [$M, 'iras_tilt']), 'a tiltás a mérés után lekerül');
$wpdb->query('SELECT 1');

$v = t_ajax('sdh_muhely_meres', [], '_wpnonce', 'sdh_muhely_meres');
T::igaz(!empty($v['success']) && str_contains((string) ($v['data']['szoveg'] ?? ''), 'Kész.'), 'AJAX: fejlesztőnek fut');

ob_start();
SDH_Muhely_Csomag::beallitas_doboz();
T::tartalmaz((string) ob_get_clean(), 'data-sdh-meres-indit', 'a Sebesség lapfülön a gomb');

T::vege('t82 adatbázis-mérés (H10)');
