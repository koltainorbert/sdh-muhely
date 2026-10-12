<?php
/**
 * t48 – Lezárt lap zárolása (0.59): a lezárt lap csak újranyitás után szerkeszthető.
 *
 * Mentés, eszköz állapota / „fizetve", fizetés, rendelés útja, AI-javaslat
 * zárolt lapon elutasítva (és naplózva); az űrlap csak olvasható (sáv,
 * jelölés); újranyitás okkal / kötelező okkal; a zárolás kikapcsolható.
 */

require __DIR__ . '/_keret.php';

$M = 'SDH_Muhely_Munkalap';
$V = 'SDH_Muhely_Valtozasnaplo';

delete_option($M::OPT_ZAROLAS);

$u  = t_ugyfel('Zárolt Zoltán');
$e1 = t_eszkoz($u, 'Apple', 'iPhone 13');
$e2 = t_eszkoz($u, 'Samsung', 'Galaxy S22');
$id = t_munkalap($u, [$e1, $e2]);
t_tetel($id, 'Kijelzőcsere', 30000);

/* --- Nyitott lap: nem zárolt -------------------------------------------- */

T::igaz(!$M::zarolva($id), 'a nyitott lap nem zárolt');
T::egyezik(null, $M::zar_ellenoriz($id), 'nyitott lapon nincs elutasítás');

$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
T::tartalmaz($urlap, 'data-sdh-naplo-rekord="munkalap:' . $id . '"', 'az űrlap jelöli a rekordot (Napló, AI)');
T::nem_tartalmaz($urlap, 'data-sdh-zarolt', 'a nyitott lap űrlapja nem zárolt');
T::nem_tartalmaz($urlap, 'data-sdh-zarsav', 'nyitott lapon nincs zárolás-sáv');
T::tartalmaz($urlap, 'data-sdh-naplo-nyit', 'az ablak alján ott a Napló gomb');
T::tartalmaz($urlap, 'data-sdh-matrica="' . $id . '"', 'az ablak alján ott a Matrica gomb');

/* --- Lezárás --------------------------------------------------------------- */

$r = $M::allapot_valt($id, 'lezart');
T::igaz(is_array($r) && !empty($r['zart']), 'a lap lezárható');
T::igaz($M::zarolva($id), 'a lezárt lap zárolt');
T::igaz($M::zarolva(t_lap($id)), 'a zárolás sorból is megállapítható');

$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
T::tartalmaz($urlap, 'data-sdh-zarolt="1"', 'a lezárt lap űrlapja zárolt');
T::tartalmaz($urlap, 'data-sdh-zarsav', 'a lezárt lapon ott a zárolás-sáv');
T::tartalmaz($urlap, 'data-sdh-ujranyit data-id="' . $id . '"', 'a sávban az Újranyitás gomb');
T::tartalmaz($urlap, 'data-sdh-ujranyit-indok', 'a sávban mező az újranyitás okának');
T::tartalmaz($urlap, 'csak olvasható', 'a sáv kiírja, hogy csak olvasható');
T::nem_tartalmaz(substr($urlap, (int) strpos($urlap, 'data-sdh-ujranyit-allapot'), 2000), 'value="lezart"', 'az újranyitás állapotai között nincs lezárt');

/* --- Zárolt lapon minden módosítás elutasítva ------------------------- */

$elotte = $V::lista(['rekord' => 'munkalap:' . $id, 'muvelet' => 'elutasitva'])['osszes'];

$r = t_ajax('sdh_muhely_munkalapok_ment', ['id' => $id, 'ugyfel_id' => $u, 'eszkoz_id' => $e1, 'allapot' => 'lezart', 'nev' => 'Átírva', 'keszult' => current_time('Y-m-d'), 'fizetett' => '0'], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
T::igaz(empty($r['success']), 'zárolt lap mentése: elutasítva');
T::tartalmaz((string) ($r['data']['uzenet'] ?? ''), 'újranyitás', 'az üzenet az újranyitásra küld');
T::egyezik('', (string) t_lap($id)->nev, 'a név nem íródott át');

$r = t_ajax('sdh_muhely_munkalapok_eszkoz_allapot', ['id' => $id, 'kulcs' => $e2, 'allapot' => 'nyitott']);
T::igaz(empty($r['success']) && !empty($r['data']['zarolt']), 'zárolt lapon az eszköz állapota nem írható');

$r = t_ajax('sdh_muhely_munkalapok_eszkoz_fizetve', ['id' => $id, 'kulcs' => $e2]);
T::igaz(empty($r['success']) && !empty($r['data']['zarolt']), 'zárolt lapon az eszköz „fizetve" jelzője nem vehető le');

$r = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '30000', 'eszkoz' => [0, $e2]]);
T::igaz(empty($r['success']) && !empty($r['data']['zarolt']), 'zárolt, ki nem fizetett lapra nem rögzíthető fizetés');
T::egyezik(0, (int) t_lap($id)->fizetve, 'a lap nem lett fizetett');

$utana = $V::lista(['rekord' => 'munkalap:' . $id, 'muvelet' => 'elutasitva'])['osszes'];
T::egyezik($elotte + 4, $utana, 'mind a négy elutasítás a Naplóba került');

// Az asszisztens sem javasolhat szerkesztést lezárt lapra.
$E = 'SDH_Muhely_Asszisztens_Eszkozok';
T::igaz(is_string($E::elokeszit('munkalap_megjegyzes', ['munkalap' => '#' . $id, 'szoveg' => 'próba'])), 'AI: megjegyzés lezárt lapra nem javasolható');
T::igaz(is_string($E::elokeszit('munkalap_hatarido', ['munkalap' => '#' . $id, 'datum' => '2030-01-01'])), 'AI: határidő lezárt lapon nem javasolható');
T::igaz(is_string($E::vegrehajt('munkalap_hatarido', ['id' => $id, 'datum' => '2030-01-01'], get_current_user_id())), 'AI: végrehajtáskor is elutasít, ha közben lezárták');
T::igaz(empty(t_lap($id)->hatarido), 'a határidő nem változott');

// Ami nem a lap tartalma, mehet: a nyomtatvány elkészül a lezárt lapról.
$ny = SDH_Muhely_Nyomtatvany::keszit('munkalap', ['id' => $id], 1);
T::igaz(is_array($ny) && str_starts_with((string) $ny['pdf'], '%PDF'), 'lezárt lapról nyomtatvány készül');

/* --- Már kifizetett lezárt lap: utólag a pénztárba írható -------------- */

$u2 = t_ugyfel('Fizetett Ferenc');
$e9 = t_eszkoz($u2, 'Nokia', 'G21');
$id2 = t_munkalap($u2, [$e9]);
t_tetel($id2, 'Akkucsere', 12000);
global $wpdb;
$wpdb->update(SDH_Muhely_Schema::tabla('munkalap'), ['fizetve' => 1, 'fizetett' => 12000], ['id' => $id2]);
$M::allapot_valt($id2, 'lezart');
$r = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id2, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '12000', 'kasszaba' => '1']);
T::igaz(!empty($r['success']), 'a kifizetett, lezárt lap utólag a pénztárba írható');

/* --- Lezárt rendelés útja ---------------------------------------------- */

$rid = SDH_Muhely_Munkalap::letrehoz(['fajta' => 'rendeles', 'allapot' => 'nyitott', 'ugyfel_id' => $u, 'rend_allapot' => 'felveve']);
$M::allapot_valt((int) $rid, 'lezart');
$r = t_ajax('sdh_muhely_rendelesek_allapot', ['id' => $rid, 'allapot' => 'megerkezett']);
T::igaz(empty($r['success']) && !empty($r['data']['zarolt']), 'lezárt rendelés útja nem írható át');

/* --- Újranyitás ------------------------------------------------------------ */

$r = t_ajax('sdh_muhely_munkalapok_ujranyit', ['id' => $id, 'allapot' => '', 'indok' => 'Az ügyfél visszahozta']);
T::igaz(!empty($r['success']), 'a lap újranyitható');
T::egyezik('nyitott', (string) t_lap($id)->allapot, 'újranyitva: az alapállapot (Nyitott)');
T::igaz(!$M::zarolva($id), 'újranyitás után nem zárolt');
T::igaz(empty(t_lap($id)->lezarva), 'az újranyitott lapnak nincs lezárási napja');

$n = $V::lista(['rekord' => 'munkalap:' . $id, 'kereses' => 'Az ügyfél visszahozta'])['osszes'];
T::igaz($n >= 2, 'az újranyitás oka a Naplóban (az állapotváltás megjegyzésében és az eseményben)');

$r = t_ajax('sdh_muhely_munkalapok_ujranyit', ['id' => $id]);
T::igaz(empty($r['success']), 'nyitott lapot nem kell újranyitni');

$r = t_ajax('sdh_muhely_munkalapok_ment', ['id' => $id, 'ugyfel_id' => $u, 'eszkoz_id' => $e1, 'allapot' => 'nyitott', 'nev' => 'Újra', 'keszult' => current_time('Y-m-d'), 'fizetett' => '0'], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
T::igaz(!empty($r['success']), 'újranyitás után menthető');
T::egyezik('Újra', (string) t_lap($id)->nev, 'a név átíródott');

// Választott állapotba is nyitható; lezártba és „részben"-be nem.
$M::allapot_valt($id, 'lezart');
$r = $M::ujranyit($id, 'fuggo', '');
T::igaz(is_array($r) && (string) t_lap($id)->allapot === 'fuggo', 'újranyitás választott állapotba (Függő)');
$M::allapot_valt($id, 'lezart');
$r = $M::ujranyit($id, 'ervenytelen', '');
T::egyezik('nyitott', (string) t_lap($id)->allapot, 'lezárt állapotot kérve az alapállapotba nyit');

/* --- Kötelező ok ---------------------------------------------------------- */

update_option($M::OPT_ZAROLAS, ['be' => true, 'indok' => true]);
$M::allapot_valt($id, 'lezart');

T::igaz(is_string($M::ujranyit($id, '', '')), 'kötelező oknál ok nélkül nem nyitható újra');
T::igaz(is_string($M::allapot_valt($id, 'nyitott')), 'kötelező oknál a listából sem nyitható újra');
T::igaz(is_array($M::allapot_valt($id, 'ervenytelen')), 'lezártból érvénytelenbe (szintén lezárt) mehet');
T::igaz(is_array($M::ujranyit($id, 'nyitott', 'Garanciális visszahozás')), 'okkal újranyitható');

$urlap = '';
$M::allapot_valt($id, 'lezart');
$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
T::tartalmaz($urlap, 'data-kotelezo="1"', 'kötelező oknál a mező jelöli');

/* --- A zárolás kikapcsolható -------------------------------------------- */

update_option($M::OPT_ZAROLAS, ['be' => false, 'indok' => false]);
T::igaz(!$M::zarolva($id), 'kikapcsolt zárolásnál a lezárt lap nem zárolt');
$r = t_ajax('sdh_muhely_munkalapok_ment', ['id' => $id, 'ugyfel_id' => $u, 'eszkoz_id' => $e1, 'allapot' => 'lezart', 'nev' => 'Zárolás nélkül', 'keszult' => current_time('Y-m-d'), 'fizetett' => '0'], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
T::igaz(!empty($r['success']), 'kikapcsolt zárolásnál a lezárt lap menthető');

/* --- Beállítások › Lezárt lapok ---------------------------------------- */

T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['zarolas']), 'van Lezárt lapok lapfül');
ob_start();
SDH_Muhely_Naplo_Felulet::zarolas_doboz();
$doboz = (string) ob_get_clean();
T::tartalmaz($doboz, 'name="zarolas_be"', 'a lapfülön a zárolás kapcsolója');
T::tartalmaz($doboz, 'name="zarolas_indok"', 'a lapfülön a kötelező ok');

$_POST = ['zarolas_jelen' => '1', 'zarolas_be' => '1'];
SDH_Muhely_Naplo_Felulet::zarolas_mentes();
$_POST = [];
T::egyezik(['be' => true, 'indok' => false], $M::zar_beallitas(), 'a lapfül mentése');

delete_option($M::OPT_ZAROLAS);
T::igaz($M::zar_beallitas()['be'], 'alapból be van kapcsolva');

T::vege('t48 lezárt lap: zárolás, elutasítás, újranyitás, kötelező ok');
