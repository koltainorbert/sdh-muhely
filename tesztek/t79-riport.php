<?php
/**
 * t79 – F5 (0.87.0): Riportok – időszakok, átfutás, technikusok, javítási arány, bevétel, 12 hónap, oldal.
 *
 * Saját, jövőbeli időszakban (2031. március) dolgozik, hogy a tesztadatbázis többi lapja ne zavarjon.
 */

require __DIR__ . '/_keret.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;

$R = 'SDH_Muhely_Riport';
$T = static fn (string $t): string => SDH_Muhely_Schema::tabla($t);

/* --- Időszakok ---------------------------------------------------------------------------------------- */

T::egyezik(['2026-03-01', '2026-03-10', 'ho'], $R::idoszak('ho', '', '', '2026-03-10'), 'ez a hónap');
T::egyezik(['2026-02-01', '2026-02-28', 'elozo_ho'], $R::idoszak('elozo_ho', '', '', '2026-03-10'), 'előző hónap');
T::egyezik(['2026-02-09', '2026-03-10', '30nap'], $R::idoszak('30nap', '', '', '2026-03-10'), 'utolsó 30 nap');
T::egyezik(['2026-01-01', '2026-03-10', 'ev'], $R::idoszak('ev', '', '', '2026-03-10'), 'idén');
T::egyezik(['2026-01-05', '2026-02-07', 'egyeni'], $R::idoszak('egyeni', '2026-02-07', '2026-01-05', '2026-03-10'), 'egyéni: a fordított határok megcserélve');
T::egyezik(['2026-03-01', '2026-03-10', 'ho'], $R::idoszak('egyeni', 'x', '', '2026-03-10'), 'hibás egyéni: ez a hónap');

/* --- Adatok ------------------------------------------------------------------------------------------- */

foreach (['tech1_t79', 'tech2_t79'] as $login) {
    if ($regi = get_user_by('login', $login)) {
        wp_delete_user((int) $regi->ID);
    }
}
$wpdb->query($wpdb->prepare('UPDATE ' . $T('munkalap') . " SET lezarva = NULL, allapot = 'nyitott' WHERE lezarva >= %s", '2031-01-01'));
$wpdb->query($wpdb->prepare('UPDATE ' . $T('munkalap') . " SET keszult = %s WHERE keszult >= %s", '2026-01-01', '2031-01-01'));
$wpdb->query($wpdb->prepare('UPDATE ' . $T('munkalap') . " SET ajanlat_kuldve = NULL WHERE ajanlat_kuldve >= %s", '2031-01-01'));

$t1 = (int) wp_insert_user(['user_login' => 'tech1_t79', 'user_pass' => 'Tech1-T79-ok!', 'role' => 'sdh_kollega', 'display_name' => 'Szerelő Egy T79']);
$t2 = (int) wp_insert_user(['user_login' => 'tech2_t79', 'user_pass' => 'Tech2-T79-ok!', 'role' => 'sdh_kollega', 'display_name' => 'Szerelő Kettő T79']);
$u  = t_ugyfel('Riport T79');
$et = t_eszkoz($u, 'Samsung', 'Telefon T79');
$eb = t_eszkoz($u, 'Apple', 'Tablet T79');
$wpdb->update($T('eszkoz'), ['kategoria' => 'tablet'], ['id' => $eb]);

$lezar = static function (int $id, string $keszult, string $lezarva, int $felelos, float $brutto, string $allapot = '') use ($wpdb, $T): void {
    $wpdb->update($T('munkalap'), [
        'keszult' => $keszult, 'lezarva' => $lezarva, 'felelos' => $felelos, 'brutto_ertek' => $brutto,
        'allapot' => $allapot !== '' ? $allapot : SDH_Muhely_Munkalap::lezart_kulcs(),
    ], ['id' => $id]);
};

$a = t_munkalap($u, [$et]);
$lezar($a, '2031-03-01', '2031-03-05', $t1, 10000);
$b = t_munkalap($u, [$eb]);
$lezar($b, '2031-03-02', '2031-03-12', $t1, 20000);
$c = t_munkalap($u, [$et]);
$lezar($c, '2031-03-10', '2031-03-11', $t2, 5000);
$d = SDH_Muhely_Munkalap::letrehoz(['fajta' => 'arukiadas', 'allapot' => 'nyitott', 'ugyfel_id' => $u, 'keszult' => '2031-03-15']);
$lezar((int) $d, '2031-03-15', '2031-03-15', 0, 3000);
$e = t_munkalap($u, [$et]);
$lezar($e, '2031-03-18', '2031-03-20', $t2, 9999, 'ervenytelen');
$f = t_munkalap($u, [$et], 'nyitott', ['garancia_elozo' => $a]);
$wpdb->update($T('munkalap'), ['keszult' => '2031-03-25'], ['id' => $f]);

$wpdb->insert($T('termek'), ['megnevezes' => 'Kijelző T79', 'kategoria' => 'Kijelző T79', 'aktiv' => 1, 'keszletkezeles' => 0]);
$p = (int) $wpdb->insert_id;
$wpdb->insert(SDH_Muhely_Tetel::tabla(), ['munkalap_id' => $a, 'tipus' => 'szolgaltatas', 'mozgas' => 'kimeno', 'megnevezes' => 'Csere', 'brutto_ertek' => 8000]);
$wpdb->insert(SDH_Muhely_Tetel::tabla(), ['munkalap_id' => $a, 'tipus' => 'termek', 'mozgas' => 'kimeno', 'megnevezes' => 'Kijelző', 'termek_id' => $p, 'brutto_ertek' => 2000]);

$x = $R::adatok('2031-03-01', '2031-03-31');

T::egyezik(3, $x['javitas_db'], 'lezárt javítás: 3 (az érvénytelen és az árukiadás nem)');
T::egyezik(5.0, round((float) $x['atfutas_atlag'], 3), 'átlagos átfutás: (4 + 10 + 1) / 3 = 5 nap');
T::egyezik(4.0, (float) $x['atfutas_median'], 'medián: 4 nap');
T::egyezik(38000.0, (float) $x['bevetel'], 'bevétel: 10 000 + 20 000 + 5 000 + 3 000');
T::egyezik(35000.0, (float) $x['fajtak']['javitas']['bevetel'], 'fajtánként: javítás');
T::egyezik(3000.0, (float) $x['fajtak']['arukiadas']['bevetel'], 'fajtánként: árukiadás');
T::egyezik(1, $x['visszajott'], 'garanciában visszajött: 1 (az A lap)');
T::egyezik(round(2 / 3, 4), round((float) $x['sikeres_arany'], 4), 'sikeres javítás: 2/3');
T::egyezik(1, $x['ervenytelen'], 'érvénytelen javítási lap: 1');
T::egyezik(1, $x['vissza_idoszak'], 'garanciális visszatérés felvétele az időszakban: 1');

$te = $x['technikusok'];
T::egyezik(2, $te[$t1]['db'] ?? 0, 'Szerelő Egy: 2 lezárt');
T::egyezik(7.0, round((float) ($te[$t1]['atlag'] ?? 0), 3), 'Szerelő Egy: átlag (4 + 10) / 2 = 7 nap');
T::egyezik(30000.0, (float) ($te[$t1]['bevetel'] ?? 0), 'Szerelő Egy: 30 000 Ft');
T::egyezik(1, $te[$t1]['vissza'] ?? 0, 'Szerelő Egy: 1 visszajött');
T::egyezik(1, $te[$t2]['db'] ?? 0, 'Szerelő Kettő: 1 lezárt (az érvénytelen nem)');
T::egyezik('Szerelő Egy T79', (string) ($te[$t1]['nev'] ?? ''), 'a technikus neve');
T::egyezik($t1, (int) array_key_first($te), 'a legtöbbet javító elöl');

T::egyezik(15000.0, (float) ($x['kategoriak']['telefon']['bevetel'] ?? 0), 'eszközkategória: telefon 15 000');
T::egyezik(20000.0, (float) ($x['kategoriak']['tablet']['bevetel'] ?? 0), 'eszközkategória: tablet 20 000');
T::egyezik('tablet', (string) array_key_first($x['kategoriak']), 'a nagyobb bevétel elöl');
T::egyezik(8000.0, (float) $x['tetel']['szolgaltatas'], 'tételek: szolgáltatás 8 000');
T::egyezik(2000.0, (float) $x['tetel']['termek'], 'tételek: termék 2 000');
T::egyezik(2000.0, (float) ($x['termek_kat']['Kijelző T79'] ?? 0), 'termékkategória szerint');

// Árajánlatok (F2).
$wpdb->update($T('munkalap'), ['ajanlat_allapot' => 'elfogadva', 'ajanlat_kuldve' => '2031-03-03 10:00:00'], ['id' => $a]);
$wpdb->update($T('munkalap'), ['ajanlat_allapot' => 'elutasitva', 'ajanlat_kuldve' => '2031-03-04 10:00:00'], ['id' => $b]);
$x = $R::adatok('2031-03-01', '2031-03-31');
T::egyezik(2, $x['ajanlat']['kuldve'], 'árajánlat: 2 elküldve');
T::egyezik(1, $x['ajanlat']['elfogadva'], '1 elfogadva');
T::egyezik(1, $x['ajanlat']['elutasitva'], '1 elutasítva');

// 12 hónap.
$h = $R::honapok('2031-04-15');
T::egyezik(12, count($h), '12 hónap');
T::egyezik('2030-05', (string) array_key_first($h), 'az első: 11 hónappal korábban');
T::egyezik(3, $h['2031-03']['db'] ?? 0, 'március: 3 javítás');
T::egyezik(38000.0, (float) ($h['2031-03']['bevetel'] ?? 0), 'március: 38 000 Ft');

// Üres időszak.
$u0 = $R::adatok('2032-01-01', '2032-01-31');
T::egyezik(0, $u0['javitas_db'], 'üres időszak: 0');
T::egyezik(0.0, (float) $u0['atfutas_atlag'], 'üres időszak: átfutás 0');

/* --- Oldal, menü, súgó ---------------------------------------------------------------------------------- */

$_GET = ['idoszak' => 'egyeni', 'tol' => '2031-03-01', 'ig' => '2031-03-31'];
ob_start();
$R::oldal();
$oldal = (string) ob_get_clean();
$_GET = [];
T::tartalmaz($oldal, 'data-sdh-riport', 'a Riportok oldal');
T::tartalmaz($oldal, 'Szerelő Egy T79', 'technikusonként');
T::tartalmaz($oldal, '38 000 Ft', 'a bevétel');
T::tartalmaz($oldal, '5,0 nap', 'az átlagos átfutás');
T::tartalmaz($oldal, '66,7%', 'a sikeres javítás aránya');
T::tartalmaz($oldal, 'data-sdh-pop="datum"', 'saját dátumválasztó (nem natív)');
T::igaz(SDH_Muhely_Modulok::egy($R::KULCS) !== null, 'a Riportok menüpont be van jegyezve');
T::igaz(isset(SDH_Muhely_Modulok::alapreszek()['riportok']), 'a Riportok az alaprendszer része');

// Rendrakás: a jövőbeli lapok vissza a múltba, hogy más tesztet ne zavarjanak.
$wpdb->query($wpdb->prepare('UPDATE ' . $T('munkalap') . " SET lezarva = NULL, keszult = %s, allapot = 'nyitott', ajanlat_kuldve = NULL WHERE id IN (%d, %d, %d, %d, %d, %d)", '2026-01-01', $a, $b, $c, (int) $d, $e, $f));
wp_delete_user($t1);
wp_delete_user($t2);

T::vege('t79 riportok (F5)');
