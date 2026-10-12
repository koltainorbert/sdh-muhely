<?php
/**
 * t23 – eszközönkénti „fizetve": részfizetés a Fizetés ablakból, a lap és az
 * eszközök jelzőinek összehangolása, a jelző levétele.
 */

require __DIR__ . '/_keret.php';

$M = 'SDH_Muhely_Munkalap';

global $wpdb;

$penztar = SDH_Muhely_Schema::tabla('penztar');
$kapcs   = SDH_Muhely_Schema::tabla('munkalap_eszkoz');

$u  = t_ugyfel();
$e1 = t_eszkoz($u, 'Apple', 'iPhone 13');
$e2 = t_eszkoz($u, 'Samsung', 'Galaxy S21');
$id = t_munkalap($u, [$e1, $e2]);

t_tetel($id, 'Kijelzőcsere', 30000, 0);
t_tetel($id, 'Akkucsere', 12000, $e2);
SDH_Muhely_Tetel::ujraszamol($id);

$b = t_bontas($id);
T::egyezik(30000.0, $b[0]['fizetendo'], 'a fő eszköz fizetendője');
T::egyezik(12000.0, $b[$e2]['fizetendo'], 'a csatolt eszköz fizetendője');
T::igaz(!$b[0]['fizetve'] && !$b[$e2]['fizetve'], 'induláskor egyik eszköz sincs fizetve');
T::egyezik(42000.0, (float) t_lap($id)->brutto_ertek, 'a lap végösszege');

/* --- Fizetés ablak: a már kifizetett eszköz nem pipálható ---------------- */

$urlap = t_ajax('sdh_muhely_penztarfizetes_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
T::tartalmaz($urlap, 'name="eszkoz[]" value="0"', 'a Fizetés ablakban ott a fő eszköz');
T::tartalmaz($urlap, 'name="eszkoz[]" value="' . $e2 . '"', 'a Fizetés ablakban ott a csatolt eszköz');
T::nem_tartalmaz($urlap, 'disabled', 'induláskor minden eszköz pipálható');

/* --- Csak a 2. eszközt fizetik ki (elviszik), az 1. még vár -------------- */

$elotte = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$penztar} WHERE munkalap_id = %d", $id));

$r = t_ajax('sdh_muhely_penztar_fizet', [
    'munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '12000',
    'eszkoz' => [$e2], 'kasszaba' => '1', 'nev' => 'Teszt Elek',
]);

T::igaz(!empty($r['success']), 'a részfizetés rögzíthető');
T::tartalmaz((string) ($r['data']['uzenet'] ?? ''), 'Fizetve: 2. Samsung Galaxy S21', 'a visszajelzés megnevezi a kifizetett eszközt');

$lap = t_lap($id);
$b   = t_bontas($id);

T::egyezik(0, (int) $lap->fizetve, 'a lap nincs fizetve, amíg a másik eszköz nincs');
T::egyezik(12000.0, (float) $lap->fizetett, 'a befizetett összeg a lap előlegébe került');
T::igaz($b[$e2]['fizetve'], 'a kifizetett eszköz fizetve');
T::egyezik(current_time('Y-m-d'), $b[$e2]['fizetes_ideje'], 'az eszköz fizetési napja ma');
T::egyezik(0.0, $b[$e2]['fizetendo'], 'a kifizetett eszköznek nincs fizetendője');
T::igaz(!$b[0]['fizetve'], 'a másik eszköz nincs fizetve');
T::egyezik(30000.0, $b[0]['fizetendo'], 'a másik eszköz fizetendője változatlan');
T::egyezik(30000.0, (float) $lap->brutto_ertek - (float) $lap->fizetett, 'a lap fizetendője = a ki nem fizetett eszközé');

$sor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$penztar} WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1", $id));
T::egyezik($elotte + 1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$penztar} WHERE munkalap_id = %d", $id)), 'egy pénztártétel keletkezett');
T::egyezik($e2, (int) $sor->eszkoz_id, 'a pénztártétel az eszközhöz kötődik');
T::egyezik(12000.0, (float) $sor->kp, 'a pénztártétel összege');
T::igaz(stripos((string) $sor->leiras, 'előleg') === false, 'a teljesen kifizetett eszköz tétele nem „Előleg”');

// A blokk és a Fizetés ablak is mutatja.
$blokk = $M::eszkoz_allapot_blokk(t_lap($id));
T::tartalmaz($blokk, 'is-fizetve', 'a blokk jelzi a kifizetett eszközt');
T::tartalmaz($blokk, 'data-sdh-eszkoz-fizetve-vissza', 'a jelző levehető, amíg a lap nincs egészben fizetve');
T::tartalmaz($blokk, 'fizetendő <b>30', 'a blokk mutatja a másik eszköz fizetendőjét');

$urlap = t_ajax('sdh_muhely_penztarfizetes_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
T::tartalmaz($urlap, 'class="is-fizetve"', 'a Fizetés ablak jelzi a kifizetett eszközt');
T::igaz(preg_match('/value="' . $e2 . '"[^>]*disabled/s', $urlap) === 1, 'a kifizetett eszköz nem pipálható újra');

// A kifizetett eszközt újra beküldve nem fizethető ki még egyszer.
$r = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '12000', 'eszkoz' => [$e2], 'kasszaba' => '1']);
T::igaz(empty($r['success']), 'a már kifizetett eszköz nem fizethető újra');
T::egyezik(12000.0, (float) t_lap($id)->fizetett, 'az elutasított fizetés nem növelte az előleget');

/* --- A jelző levétele ---------------------------------------------------- */

$r = t_ajax('sdh_muhely_munkalapok_eszkoz_fizetve', ['id' => $id, 'kulcs' => $e2]);
T::igaz(!empty($r['success']), 'a „fizetve” jelző levehető');
T::igaz(!t_bontas($id)[$e2]['fizetve'], 'levétel után az eszköz nincs fizetve');
T::egyezik(12000.0, (float) t_lap($id)->fizetett, 'a levétel a befizetett összeghez nem nyúl');

$M::eszkoz_fizetve_ir($id, $e2, true);
T::igaz(t_bontas($id)[$e2]['fizetve'], 'a jelző visszatehető');

/* --- A másik eszközt is kifizetik: a lap is fizetve lesz ----------------- */

$r = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kartya', 'fajta' => 'eloleg', 'osszeg' => '30000', 'eszkoz' => [0], 'kasszaba' => '1']);
T::igaz(!empty($r['success']), 'a másik eszköz is kifizethető');

$lap = t_lap($id);
$b   = t_bontas($id);

T::igaz($b[0]['fizetve'] && $b[$e2]['fizetve'], 'mindkét eszköz fizetve');
T::egyezik(1, (int) $lap->fizetve, 'ha minden eszköz ki van fizetve, a lap is fizetve');
T::egyezik(current_time('Y-m-d'), substr((string) $lap->fizetes_ideje, 0, 10), 'a lap fizetési napja beállt');
T::nem_tartalmaz($M::eszkoz_allapot_blokk($lap), 'data-sdh-eszkoz-fizetve-vissza', 'egészben fizetett lapon az eszközről nem vehető le a jelző');
T::igaz(is_string($M::eszkoz_fizetve_ir($id, 0, false)), 'egészben fizetett lapon a levétel elutasítva');

/* --- Teljes fizetés egyben: minden eszköz fizetve ------------------------ */

$id2 = t_munkalap($u, [$e1, $e2]);
t_tetel($id2, 'Szerviz', 10000, 0);
t_tetel($id2, 'Szerviz', 5000, $e2);
SDH_Muhely_Tetel::ujraszamol($id2);

$r = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id2, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '15000', 'eszkoz' => [0, $e2], 'kasszaba' => '1']);
T::igaz(!empty($r['success']), 'a teljes fizetés rögzíthető');
T::egyezik(1, (int) t_lap($id2)->fizetve, 'teljes fizetésnél a lap fizetve');
T::egyezik(1, (int) t_lap($id2)->fo_fizetve, 'teljes fizetésnél a fő eszköz jelzője is beáll');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare("SELECT fizetve FROM {$kapcs} WHERE munkalap_id = %d AND eszkoz_id = %d", $id2, $e2)), 'teljes fizetésnél a csatolt eszköz jelzője is beáll');
T::egyezik(2, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$penztar} WHERE munkalap_id = %d", $id2)), 'eszközönként külön pénztártétel');

/* --- Űrlap: a lap „Fizetve" pipája minden eszközre érvényes -------------- */

$id3 = t_munkalap($u, [$e1, $e2]);
t_tetel($id3, 'Szerviz', 8000, 0);
t_tetel($id3, 'Szerviz', 6000, $e2);
SDH_Muhely_Tetel::ujraszamol($id3);

$alap = [
    'id' => $id3, 'ugyfel_id' => $u, 'eszkoz_id' => $e1, 'eszkozok_jelen' => '1', 'tovabbi_eszkoz' => [$e2],
    'allapot' => 'nyitott', 'keszult' => current_time('Y-m-d'), 'fizetett' => '0',
];

t_ajax('sdh_muhely_munkalapok_ment', $alap + ['fizetve' => '1'], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
$b = t_bontas($id3);
T::egyezik(1, (int) t_lap($id3)->fizetve, 'az űrlapon bepipált „Fizetve” mentődik');
T::igaz($b[0]['fizetve'] && $b[$e2]['fizetve'], 'kifizetett lapon minden eszköz fizetve');
T::egyezik(1, (int) t_lap($id3)->fo_fizetve, 'a fő eszköz jelzője követi a lapét');

t_ajax('sdh_muhely_munkalapok_ment', $alap, 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
$b = t_bontas($id3);
T::egyezik(0, (int) t_lap($id3)->fizetve, 'a pipa levehető a lapról');
T::igaz(!$b[0]['fizetve'] && !$b[$e2]['fizetve'], 'a lapról levett pipa az eszközökről is lekerül');
T::egyezik(8000.0, $b[0]['fizetendo'], 'levétel után újra van fizetendő');

/* --- Egy eszközös lap: minden a régi ------------------------------------- */

$id4 = t_munkalap($u, [$e1]);
t_tetel($id4, 'Szerviz', 9000, 0);
SDH_Muhely_Tetel::ujraszamol($id4);

T::igaz(is_string($M::eszkoz_fizetve_ir($id4, 0, true)), 'egy eszközös lapon nincs eszközönkénti jelző');
$r = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id4, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '9000', 'kasszaba' => '1']);
T::igaz(!empty($r['success']), 'egy eszközös lap fizetése működik');
T::egyezik(1, (int) t_lap($id4)->fizetve, 'egy eszközös lap fizetve');
T::egyezik(0.0, t_bontas($id4)[0]['fizetendo'], 'egy eszközös kifizetett lapon nincs fizetendő');

$r = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => t_munkalap($u, [$e1]), 'mod' => 'kp', 'fajta' => 'eloleg', 'osszeg' => '3000', 'kasszaba' => '1']);
T::igaz(!empty($r['success']), 'egy eszközös lapra előleg rögzíthető');

/* --- Eszköz levétele: a régi eszköz-jelző nem teszi fizetetté a lapot ---- */

$id5 = t_munkalap($u, [$e1, $e2]);
t_tetel($id5, 'Szerviz', 8000, 0);
t_tetel($id5, 'Szerviz', 6000, $e2);
SDH_Muhely_Tetel::ujraszamol($id5);

t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id5, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '8000', 'eszkoz' => [0], 'kasszaba' => '1']);
T::egyezik(1, (int) t_lap($id5)->fo_fizetve, 'a fő eszköz külön ki van fizetve');

// A csatolt eszköz lekerül a lapról (a tétele a fő eszközre száll).
t_ajax('sdh_muhely_munkalapok_ment', [
    'id' => $id5, 'ugyfel_id' => $u, 'eszkoz_id' => $e1, 'eszkozok_jelen' => '1',
    'allapot' => 'nyitott', 'keszult' => current_time('Y-m-d'), 'fizetett' => '8000',
], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');

T::egyezik(0, (int) t_lap($id5)->fizetve, 'az eszköz levétele nem teszi fizetetté a lapot');
T::egyezik(0, (int) t_lap($id5)->fo_fizetve, 'egy eszközös lapon nem marad eszköz-jelző');
T::egyezik(6000.0, t_bontas($id5)[0]['fizetendo'], 'a levett eszköz tétele fizetendő marad');

T::vege('t23 eszköz-fizetve');
