<?php
/**
 * t63 – 0.69.0: összevont szervizsor a számlán. A tételek neve helyett eszközönként egy sor
 * („Telefon szerviz szolgáltatás", „TV szerviz szolgáltatás") a fizetendő összeggel; a fajta
 * az eszköz kategóriájából (és a beállított rövidebb névből) jön. Beállítás + ablak-kapcsoló.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$Sz      = 'SDH_Muhely_Szamla';
$kerelem = new ReflectionMethod($Sz, 'kerelem');
$kerelem->setAccessible(true);
$regi_b  = get_option('sdh_muhely_szamla', []);

/* --- A fajta neve az eszköz kategóriájából ------------------------------------------ */

T::egyezik('Telefon szerviz szolgáltatás', $Sz::osszevont_nev((object) ['kategoria' => 'telefon']), 'telefon → „Telefon szerviz szolgáltatás"');
T::egyezik('TV szerviz szolgáltatás', $Sz::osszevont_nev((object) ['kategoria' => 'tv']), 'televízió → a rövidebb név: „TV szerviz szolgáltatás"');
T::egyezik('Konzol szerviz szolgáltatás', $Sz::osszevont_nev((object) ['kategoria' => 'konzol']), 'játékkonzol → „Konzol szerviz szolgáltatás"');
T::egyezik('Laptop szerviz szolgáltatás', $Sz::osszevont_nev((object) ['kategoria' => 'laptop']), 'laptop → „Laptop szerviz szolgáltatás"');
T::egyezik('Okosóra szerviz szolgáltatás', $Sz::osszevont_nev((object) ['kategoria' => 'ora']), '„Okosóra / sportóra" → a beállított rövid név');
T::egyezik('Szerviz szolgáltatás', $Sz::osszevont_nev(null), 'eszköz nélkül: „Szerviz szolgáltatás"');

$kat = array_key_first(array_diff_key(SDH_Muhely_Eszkoz::kategoriak(), ['telefon' => 1, 'tv' => 1, 'konzol' => 1, 'ora' => 1]));
T::igaz($kat !== null && strpos($Sz::osszevont_nev((object) ['kategoria' => $kat]), 'szerviz szolgáltatás') !== false, 'bármely kategóriára működik (' . $kat . ')');

/* --- Két eszközös munkalap: telefon + TV ------------------------------------------------- */

$u  = t_ugyfel('Összevont Olga');
$e1 = t_eszkoz($u, 'Samsung', 'Galaxy A17');
$e2 = t_eszkoz($u, 'LG', '55UR78');
$wpdb->update(SDH_Muhely_Schema::tabla('eszkoz'), ['kategoria' => 'tv'], ['id' => $e2]);
$id = t_munkalap($u, [$e1, $e2]);
$t1 = t_tetel($id, 'Kijelző csere Galaxy A17', 30000, 0);
$t2 = t_tetel($id, 'Akkumulátor', 8000, 0, 'termek');
$t3 = t_tetel($id, 'Háttérvilágítás javítás', 25000, $e2);

$alap_post = [
    'munkalap_id' => (string) $id,
    'vevo'        => ['nev' => 'Összevont Olga'],
    'tetel'       => [(string) $t1, (string) $t2, (string) $t3],
    'megj'        => [$t1 => 'IMEI 1234'],
];

// Kikapcsolva: a tételek a saját nevükön.
$_POST = $alap_post + ['osszevon_jelen' => '1'];
$k = $kerelem->invoke(null, false);
T::igaz(is_array($k) && count($k['sorok']) === 3, 'kikapcsolva a három tétel a saját nevén' . (is_string($k) ? ' – ' . $k : ''));

// Bekapcsolva (az ablak kapcsolójával): eszközönként egy sor.
$_POST = $alap_post + ['osszevon_jelen' => '1', 'osszevon' => '1'];
$k = $kerelem->invoke(null, false);
T::igaz(is_array($k), 'bekapcsolva is összeáll a kérés' . (is_string($k) ? ' – ' . $k : ''));
$sorok = is_array($k) ? $k['sorok'] : [];
T::egyezik(['Telefon szerviz szolgáltatás', 'TV szerviz szolgáltatás'], array_column($sorok, 'megnevezes'), 'két sor: telefon és TV');
T::egyezik(38000.0, (float) ($sorok[0]['brutto'] ?? 0), 'a telefon sora a két tétel összege (38 000 Ft)');
T::egyezik(25000.0, (float) ($sorok[1]['brutto'] ?? 0), 'a TV sora a saját tétele (25 000 Ft)');
T::egyezik(1.0, (float) ($sorok[0]['mennyiseg'] ?? 0), '1 db');
T::igaz(abs((float) $sorok[0]['netto'] + (float) $sorok[0]['afa'] - (float) $sorok[0]['brutto']) < 0.01, 'nettó + áfa = bruttó');
T::egyezik('IMEI 1234', (string) ($sorok[0]['megjegyzes'] ?? ''), 'a tétel megjegyzése a sorra kerül');
T::igaz(!in_array('Kijelző csere Galaxy A17', array_column($sorok, 'megnevezes'), true), 'a tétel neve nem kerül a számlára');
$idk = array_map('intval', $k['tetel_idk'] ?? []);
sort($idk);
T::egyezik([$t1, $t2, $t3], $idk, 'a munkalap tételei ugyanúgy számlázottnak jelölődnek');

$pdf = $Sz::nyomtatvany_pdf($k);
T::igaz(strpos($pdf, '%PDF') === 0, 'a számla-előkészítő PDF is elkészül összevonva');

// Csak a TV kipipálva.
$_POST = ['tetel' => [(string) $t3]] + $alap_post + ['osszevon_jelen' => '1', 'osszevon' => '1'];
$k = $kerelem->invoke(null, false);
T::egyezik(['TV szerviz szolgáltatás'], array_column(is_array($k) ? $k['sorok'] : [], 'megnevezes'), 'csak a kipipált tételek eszköze kerül rá');

// Kézi tétel az ablakból: a fő eszközé.
$_POST = $alap_post + ['osszevon_jelen' => '1', 'osszevon' => '1', 'uj' => [['megnevezes' => 'Tisztítás', 'brutto_ar' => '2000', 'mennyiseg' => '1']]];
$k = $kerelem->invoke(null, false);
T::egyezik(40000.0, (float) (is_array($k) ? $k['sorok'][0]['brutto'] : 0), 'a kézi tétel összege a fő eszköz sorába kerül');

/* --- Beállítás: alapállás, név minta, rövid nevek -------------------------------------- */

$b = $Sz::beallitas();
T::igaz($b['osszevont'] === false, 'alapból kikapcsolva');
$b['osszevont']       = true;
$b['osszevont_minta'] = '{eszkoz} javítás';
update_option('sdh_muhely_szamla', $b);
$_POST = $alap_post; // régi ablak: nincs kapcsoló → a beállítás dönt
$k = $kerelem->invoke(null, false);
T::egyezik(['Telefon javítás', 'TV javítás'], array_column(is_array($k) ? $k['sorok'] : [], 'megnevezes'), 'a beállítás alapján, a saját névmintával');

ob_start();
$Sz::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::tartalmaz($doboz, 'name="szamla_osszevont"', 'a Számlázás beállításaiban ott a kapcsoló');
T::tartalmaz($doboz, 'name="szamla_osszevont_minta"', 'és a sor nevének mintája');
T::tartalmaz($doboz, 'name="szamla_osszevont_nevek"', 'és a rövidebb nevek');

$_POST = ['szamla_alap_fizmod' => 'Készpénz', 'szamla_osszevont' => '1', 'szamla_osszevont_minta' => '', 'szamla_osszevont_nevek' => "Televízió=Tévé"];
$Sz::beallitas_mentes();
$b = $Sz::beallitas();
T::igaz($b['osszevont'] === true, 'a kapcsoló menthető');
T::egyezik('{eszkoz} szerviz szolgáltatás', $b['osszevont_minta'], 'üres minta helyett az alap');
T::egyezik('Tévé szerviz szolgáltatás', $Sz::osszevont_nev((object) ['kategoria' => 'tv']), 'a rövid nevek átírhatók');

/* --- A számla ablaka ----------------------------------------------------------------------- */

$sz = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-szamla.php');
T::tartalmaz($sz, 'name="osszevon" value="1" data-sdh-szamla-osszevon', 'a számla ablakában számlánként átkapcsolható');
T::tartalmaz($sz, 'name="osszevon_jelen" value="1"', 'és a kapcsoló jelzőmezője');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'sugo/11-szamlazas.md'), 'Összevont szervizsor', 'a súgó leírja');

update_option('sdh_muhely_szamla', $regi_b);
$_POST = [];

T::vege('t63 összevont szervizsor a számlán');
