<?php
/**
 * t25 – eszközönkénti számla (választható): a számla ablakának eszközválasztója,
 * a kérelem, a kiállított számla eszköze, a helyi nyomtatvány sorszáma.
 *
 * A Számlázz.hu-t hamis válasz helyettesíti (pre_http_request) – valódi számla
 * nem készül, a hálózatra semmi nem megy ki.
 */

require __DIR__ . '/_keret.php';

$S = 'SDH_Muhely_Szamla';

global $wpdb;

// Agent kulcs csak a teszt idejére (az adatbázisba nem íródik).
add_filter('option_sdh_muhely_szamla', static fn ($ertek) => ['kulcs' => 'teszt-kulcs'] + (is_array($ertek) ? $ertek : []));
add_filter('default_option_sdh_muhely_szamla', static fn () => ['kulcs' => 'teszt-kulcs']);

$kuldott = [];
$sorszam = 0;

add_filter('pre_http_request', static function ($elozo, $argumentumok, $cim) use (&$kuldott, &$sorszam) {
    $kuldott[] = (string) ($argumentumok['body'] ?? '');
    $sorszam++;

    return [
        'headers'  => ['szlahu_szamlaszam' => 'TESZT-2026-' . $sorszam, 'szlahu_nettovegosszeg' => '0', 'szlahu_bruttovegosszeg' => '0'],
        'body'     => '%PDF-1.4 teszt',
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies'  => [],
        'filename' => null,
    ];
}, 10, 3);

$u  = t_ugyfel();
$e1 = t_eszkoz($u, 'Apple', 'iPhone 15');
$e2 = t_eszkoz($u, 'Samsung', 'Galaxy S23');
$id = t_munkalap($u, [$e1, $e2]);

$t1 = t_tetel($id, 'Kijelzőcsere', 30000, 0);
$t2 = t_tetel($id, 'Hátlapcsere', 9000, 0);
$t3 = t_tetel($id, 'Akkucsere', 12000, $e2);
SDH_Muhely_Tetel::ujraszamol($id);

$szam = (int) t_lap($id)->munkalap_szam;

/* --- A számla ablaka ----------------------------------------------------- */

$urlap = t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id, 'sorozat' => 'fo'])['nyers'];

T::tartalmaz($urlap, 'data-sdh-szamla-eszkozok', 'több eszköznél van eszközválasztó');
T::tartalmaz($urlap, 'name="eszkoz" value="mind" data-sdh-szamla-eszkoz checked', 'alapból minden eszköz együtt');
T::tartalmaz($urlap, 'name="eszkoz" value="0"', 'választható a fő eszköz');
T::tartalmaz($urlap, 'name="eszkoz" value="' . $e2 . '"', 'választható a csatolt eszköz');
T::igaz(preg_match('/value="' . $t1 . '" data-sdh-szamla-tetel\s+data-eszkoz="0"/', $urlap) === 1, 'a fő eszköz tétele a fő eszközé');
T::igaz(preg_match('/value="' . $t3 . '" data-sdh-szamla-tetel\s+data-eszkoz="' . $e2 . '"/', $urlap) === 1, 'a csatolt eszköz tétele az övé');
T::igaz(preg_match('/data-megj="[^"]*Samsung Galaxy S23[^"]*"/', $urlap) === 1, 'az eszköz megjegyzése a saját nevével');
T::igaz(preg_match('/value="' . $e2 . '" data-sdh-szamla-eszkoz\s+data-megj="[^"]*iPhone/', $urlap) !== 1, 'az eszköz megjegyzésében nincs a másik eszköz');
T::tartalmaz($urlap, 'data-megj-mind="', 'megvan a közös megjegyzés is');

$helyi = t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id, 'sorozat' => 'helyi'])['nyers'];
T::tartalmaz($helyi, '-' . $szam . '-2.pdf"', 'a helyi nyomtatvány eszközönként saját sorszámot kap');
T::tartalmaz($helyi, 'data-fajl-mind="', 'a közös nyomtatvány fájlneve megvan');

$id1    = t_munkalap($u, [$e1]);
t_tetel($id1, 'Szerviz', 5000, 0);
$urlap1 = t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id1, 'sorozat' => 'fo'])['nyers'];
T::nem_tartalmaz($urlap1, 'data-sdh-szamla-eszkozok', 'egy eszköznél nincs eszközválasztó');
T::tartalmaz($urlap1, 'data-sdh-szamla-tetel', 'egy eszköznél a tételek megvannak');

/* --- A sorszám ----------------------------------------------------------- */

$lap = t_lap($id);
T::egyezik($S::nyomtatvany_szam($lap) . '-2', $S::nyomtatvany_szam($lap, 2), 'az eszköz sorszáma utótag');
T::igaz(substr($S::nyomtatvany_szam($lap), -strlen((string) $szam)) === (string) $szam, 'eszköz nélkül a sorszám a régi');

/* --- Számla csak a 2. eszközről ------------------------------------------ */

$vevo = ['nev' => 'Teszt Vevő', 'orszag' => 'Magyarország', 'irsz' => '8200', 'telepules' => 'Veszprém', 'cim' => 'Teszt utca 1.', 'email' => '', 'adoszam' => ''];
$fej  = ['munkalap_id' => $id, 'sorozat' => 'fo', 'vevo' => $vevo, 'fizmod' => 'Készpénz', 'megjegyzes' => 'Teszt'];

$r = t_ajax('sdh_muhely_szamla_ment', $fej + ['eszkoz' => (string) $e2, 'tetel' => [$t3]]);

T::igaz(!empty($r['success']), 'az eszközönkénti számla kiállítható' . (isset($r['data']['uzenet']) ? ' – ' . $r['data']['uzenet'] : ''));
T::egyezik('2. Samsung Galaxy S23', (string) ($r['data']['eszkoz'] ?? ''), 'a válasz megnevezi az eszközt');

$sz = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('szamla') . ' WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1', $id));
T::egyezik($e2, (int) $sz->eszkoz_id, 'a számla a valódi eszköz-azonosítóval mentődött');
T::egyezik(12000.0, round((float) $sz->brutto), 'a számla csak az eszköz tételét tartalmazza');
T::tartalmaz((string) end($kuldott), 'Akkucsere', 'a Számlázz.hu-nak az eszköz tétele ment');
T::nem_tartalmaz((string) end($kuldott), 'Kijelzőcsere', 'a másik eszköz tétele nem ment a számlára');

$tetel = static fn (int $tid): string => (string) $wpdb->get_var($wpdb->prepare('SELECT szamla FROM ' . SDH_Muhely_Schema::tabla('munkalap_tetel') . ' WHERE id = %d', $tid));
T::egyezik((string) $sz->szamlaszam, $tetel($t3), 'a számlázott tétel megjegyezte a számla számát');
T::egyezik('', $tetel($t1), 'a másik eszköz tétele számlázatlan maradt');

// Újra megnyitva: a már számlázott tétel jelölve van.
$urlap = t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id, 'sorozat' => 'fo'])['nyers'];
T::igaz(preg_match('/value="' . $t3 . '" data-sdh-szamla-tetel\s+data-eszkoz="' . $e2 . '"\s+data-szamlazva="1"/', $urlap) === 1, 'a számlázott tétel jelölve (nem pipálódik vissza)');
T::igaz(preg_match('/value="' . $t1 . '" data-sdh-szamla-tetel\s+data-eszkoz="0"\s+data-szamlazva="0"/', $urlap) === 1, 'a számlázatlan tétel szabad');

/* --- Számla a fő eszközről, az ablakban felvett új tétellel -------------- */

$r = t_ajax('sdh_muhely_szamla_ment', $fej + [
    'eszkoz' => '0', 'tetel' => [$t1, $t2],
    'uj' => [['tipus' => 'szolgaltatas', 'megnevezes' => 'Fólia felrakás', 'mennyiseg' => '1', 'me' => 'db', 'brutto_ar' => '2000', 'afa_kulcs' => '27']],
]);

T::igaz(!empty($r['success']), 'a fő eszköz számlája kiállítható');

$sz = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('szamla') . ' WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1', $id));
T::egyezik($e1, (int) $sz->eszkoz_id, 'a fő eszköz számlája a fő eszköz valódi azonosítójával');
// A számla nettóból számol: fillérnyi eltérés lehet, forintra kerekítve egyezik.
T::egyezik(41000.0, round((float) $sz->brutto), 'a fő eszköz számlájának összege az új tétellel');

$uj = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('munkalap_tetel') . " WHERE munkalap_id = %d AND megnevezes = 'Fólia felrakás'", $id));
T::igaz(is_object($uj), 'az ablakban felvett tétel a munkalapra került');
T::egyezik(0, (int) ($uj->eszkoz_id ?? -1), 'az új tétel a fő eszközé lett');

/* --- Új tétel a csatolt eszköz számláján: az övé lesz -------------------- */

$r = t_ajax('sdh_muhely_szamla_ment', $fej + [
    'eszkoz' => (string) $e2,
    'uj' => [['tipus' => 'szolgaltatas', 'megnevezes' => 'Tok', 'mennyiseg' => '1', 'me' => 'db', 'brutto_ar' => '3000', 'afa_kulcs' => '27']],
]);
T::igaz(!empty($r['success']), 'számla csak új tétellel is kiállítható');
T::egyezik($e2, (int) $wpdb->get_var($wpdb->prepare('SELECT eszkoz_id FROM ' . SDH_Muhely_Schema::tabla('munkalap_tetel') . " WHERE munkalap_id = %d AND megnevezes = 'Tok'", $id)), 'a csatolt eszköz számláján felvett tétel az övé');
T::egyezik(15000.0, t_bontas($id)[$e2]['brutto'], 'a csatolt eszköz végösszege az új tétellel');

/* --- Minden eszköz együtt: a számla az egész lapé ------------------------ */

$id2 = t_munkalap($u, [$e1, $e2]);
$a   = t_tetel($id2, 'Szerviz A', 10000, 0);
$b   = t_tetel($id2, 'Szerviz B', 6000, $e2);
SDH_Muhely_Tetel::ujraszamol($id2);

$r = t_ajax('sdh_muhely_szamla_ment', ['munkalap_id' => $id2, 'eszkoz' => 'mind', 'tetel' => [$a, $b]] + $fej);
T::igaz(!empty($r['success']), 'a közös számla kiállítható');
T::egyezik('', (string) ($r['data']['eszkoz'] ?? ''), 'közös számlánál nincs eszköz a válaszban');

$sz = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('szamla') . ' WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1', $id2));
T::egyezik(0, (int) $sz->eszkoz_id, 'a közös számla nem kötődik eszközhöz');
T::egyezik(16000.0, round((float) $sz->brutto), 'a közös számlán minden eszköz tétele');

// Idegen eszköz-kulcs: a számla az egész lapé marad.
$id3 = t_munkalap($u, [$e1, $e2]);
$c   = t_tetel($id3, 'Szerviz C', 4000, 0);
$r   = t_ajax('sdh_muhely_szamla_ment', ['munkalap_id' => $id3, 'eszkoz' => '999999', 'tetel' => [$c]] + $fej);
T::igaz(!empty($r['success']), 'idegen eszköz-kulccsal is kiállítható');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT eszkoz_id FROM ' . SDH_Muhely_Schema::tabla('szamla') . ' WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1', $id3)), 'idegen eszköz-kulcs nem kerül a számlára');

/* --- Egy eszközös lap: minden a régi ------------------------------------- */

$d = t_tetel($id1, 'Szerviz D', 7000, 0);
$r = t_ajax('sdh_muhely_szamla_ment', ['munkalap_id' => $id1, 'tetel' => [$d]] + $fej);
T::igaz(!empty($r['success']), 'egy eszközös lap számlája kiállítható');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT eszkoz_id FROM ' . SDH_Muhely_Schema::tabla('szamla') . ' WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1', $id1)), 'egy eszközös lap számlája nem kötődik eszközhöz');

T::egyezik(6, count($kuldott), 'minden kiállítás egy hívás volt (és egy sem ment a hálózatra)');

T::vege('t25 számla-eszköz');
