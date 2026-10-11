<?php
/**
 * t28 – a „Fizetett" mindig a ténylegesen befolyt összeg; a „Fizetve" pipa
 * levétele és a fizetés visszavonása.
 */

require __DIR__ . '/_keret.php';

$M = 'SDH_Muhely_Munkalap';

global $wpdb;

$tetel_tabla = SDH_Muhely_Schema::tabla('munkalap_tetel');

$dij = static function (int $id, int $kulcs) use ($wpdb, $tetel_tabla): float {
    return (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(brutto_ertek), 0) FROM {$tetel_tabla} WHERE munkalap_id = %d AND forras = 'bevizsgalas' AND eszkoz_id = %d", $id, $kulcs));
};

$ment = static function (int $id, array $mezok = []): array {
    $lap  = t_lap($id);
    $post = [
        'id' => $id, 'ugyfel_id' => (int) $lap->ugyfel_id, 'eszkoz_id' => (int) $lap->eszkoz_id, 'eszkozok_jelen' => '1',
        'allapot' => (string) $lap->allapot, 'keszult' => current_time('Y-m-d'),
        'fizetett' => (string) (float) $lap->fizetett, 'bevizsgalasi_dij' => (int) $lap->bevizsgalasi_dij === 1 ? '1' : '',
        'bevizsgalas_mod' => 'levon', 'tovabbi_eszkoz' => [], 'tovabbi_dij' => [],
        'fizetve' => (int) $lap->fizetve === 1 ? '1' : '',
    ];

    foreach (SDH_Muhely_Munkalap::tovabbi_reszletek($id) as $r) {
        $post['tovabbi_eszkoz'][]                 = (int) $r->eszkoz_id;
        $post['tovabbi_dij'][(int) $r->eszkoz_id] = (string) (float) $r->bev_dij;
    }

    return t_ajax('sdh_muhely_munkalapok_ment', array_filter($mezok + $post, static fn ($v) => $v !== ''), 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
};

/** A bejelentett lap: két eszköz, 1 000 Ft díj és 10 000 Ft munka mindkettőn. */
$uj_lap = static function () use ($ment): array {
    $u  = t_ugyfel('Fizető Ferenc');
    $e1 = t_eszkoz($u, 'Lenovo', 'L14');
    $e2 = t_eszkoz($u, 'Apple', 'iPhone 17');
    $id = t_munkalap($u, [$e1, $e2]);

    $ment($id, ['fizetett' => '2000', 'bevizsgalasi_dij' => '1', 'tovabbi_eszkoz' => [$e2], 'tovabbi_dij' => [$e2 => '1000']]);
    t_tetel($id, 'Vírusirtás', 10000, 0);
    t_tetel($id, 'Vírusirtás', 10000, $e2);
    $ment($id);

    return [$id, $e1, $e2];
};

/* --- 1) Egyben fizetik ki a lapot --------------------------------------- */

[$id, $e1, $e2] = $uj_lap();

T::egyezik(2000.0, (float) t_lap($id)->fizetett, 'fizetés előtt a Fizetett a két díj');

$r = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '18000', 'eszkoz' => [0, $e2], 'kasszaba' => '1']);
T::igaz(!empty($r['success']), 'a teljes fizetés rögzíthető');

$lap = t_lap($id);
$b   = t_bontas($id);

T::egyezik(1, (int) $lap->fizetve, 'a lap fizetve');
T::egyezik(20000.0, (float) $lap->fizetett, 'a Fizetett a teljes kifizetett összeg: 20 000 Ft');
T::egyezik((float) $lap->brutto_ertek, (float) $lap->fizetett, 'kifizetett lapon Fizetett = végösszeg');
T::egyezik(10000.0, $b[0]['eloleg'], 'a Lenovóra 10 000 Ft folyt be');
T::egyezik(10000.0, $b[$e2]['eloleg'], 'az iPhone-ra 10 000 Ft folyt be');
T::egyezik(1000.0, $dij($id, 0), 'a Lenovo díja 1 000 Ft maradt');
T::egyezik(1000.0, $dij($id, $e2), 'az iPhone díja 1 000 Ft maradt');
T::egyezik(9000.0, (float) $lap->fo_veg, 'a lezáró fizetés Lenovo-része feljegyezve');

$blokk = $M::eszkoz_allapot_blokk($lap);
T::egyezik(2, substr_count($blokk, "Fizetve 10\u{00a0}000\u{00a0}Ft"), 'a blokk eszközönként mutatja a befizetett 10 000 Ft-ot');

// A már fizetett lapot utólag pénztárba írva a Fizetett nem nő tovább.
t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '5000', 'eszkoz' => [0, $e2], 'kasszaba' => '1']);
T::egyezik(20000.0, (float) t_lap($id)->fizetett, 'a fizetett lap újbóli pénztárba írása nem számol kétszer');

// Újramentve semmi nem változik.
$ment($id);
T::egyezik(20000.0, (float) t_lap($id)->fizetett, 'újramentés után is 20 000 Ft');
T::egyezik(1000.0, $dij($id, 0), 'újramentés után is 1 000 Ft a díj');

/* --- 2) A pipa levétele a fizetés visszavonásával ------------------------ */

$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
T::tartalmaz($urlap, 'data-fizetve-mentett="1"', 'az űrlap tudja, hogy a lap fizetve van');
T::tartalmaz($urlap, 'data-eloleg-mentett="20000"', 'az űrlap ismeri a befizetett összeget');
T::tartalmaz($urlap, 'data-veg-ossz="18000"', 'az űrlap ismeri a lezáró fizetés összegét');
T::tartalmaz($urlap, 'data-kasszaban="23000"', 'az űrlap ismeri, mennyi van a pénztárban');
T::tartalmaz($urlap, 'data-sdh-fizetes-vissza', 'megvan a visszavonás rejtett mezője');

$ment($id, ['fizetve' => '', 'fizetes_vissza' => '1']);

$lap = t_lap($id);
$b   = t_bontas($id);

T::egyezik(0, (int) $lap->fizetve, 'visszavonás után a lap nincs fizetve');
T::egyezik(2000.0, (float) $lap->fizetett, 'visszavonás után a Fizetett újra a két díj');
T::egyezik(9000.0, $b[0]['fizetendo'], 'visszavonás után a Lenovo fizetendője visszajött');
T::egyezik(9000.0, $b[$e2]['fizetendo'], 'visszavonás után az iPhone fizetendője visszajött');
T::egyezik(1000.0, $dij($id, 0), 'visszavonás után is 1 000 Ft a Lenovo díja');
T::egyezik(1000.0, $dij($id, $e2), 'visszavonás után is 1 000 Ft az iPhone díja');
T::igaz(!$b[0]['fizetve'] && !$b[$e2]['fizetve'], 'visszavonás után egyik eszköz sincs fizetve');
T::egyezik(0.0, (float) $lap->fo_veg, 'a lezáró fizetés feljegyzése kiürült');

/* --- 3) Eszközönként fizetve, majd a lezáró fizetés visszavonása --------- */

[$id, $e1, $e2] = $uj_lap();

t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '9000', 'eszkoz' => [0], 'kasszaba' => '1']);
T::egyezik(11000.0, (float) t_lap($id)->fizetett, 'az első eszköz után befolyt: 2 000 díj + 9 000');
T::egyezik(10000.0, t_bontas($id)[0]['eloleg'], 'ebből a Lenovóra 10 000 Ft');
T::egyezik(1000.0, t_bontas($id)[$e2]['eloleg'], 'és az iPhone-ra 1 000 Ft (a díja)');
T::tartalmaz($M::eszkoz_allapot_blokk(t_lap($id)), "befizetve 1\u{00a0}000\u{00a0}Ft · fizetendő 9\u{00a0}000\u{00a0}Ft", 'a blokk mutatja, mennyi folyt be a még nyitott eszközre');

t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '9000', 'eszkoz' => [$e2], 'kasszaba' => '1']);
T::egyezik(20000.0, (float) t_lap($id)->fizetett, 'a második eszköz után a Fizetett 20 000 Ft');
T::egyezik(1, (int) t_lap($id)->fizetve, 'a lap fizetve');

// A lezáró (iPhone) fizetés visszavonása: a Lenovo fizetve marad, az iPhone újra fizetendő.
$ment($id, ['fizetve' => '', 'fizetes_vissza' => '1']);

$lap = t_lap($id);
$b   = t_bontas($id);

T::egyezik(11000.0, (float) $lap->fizetett, 'csak a lezáró 9 000 Ft ment vissza');
T::egyezik(0.0, $b[0]['fizetendo'], 'a korábban kifizetett Lenovo fizetendője 0 maradt');
T::egyezik(9000.0, $b[$e2]['fizetendo'], 'az iPhone fizetendője visszajött');
T::igaz($b[0]['fizetve'], 'a ténylegesen kifizetett Lenovo fizetve maradt');
T::igaz(!$b[$e2]['fizetve'], 'az iPhone már nincs fizetve');
T::egyezik(0, (int) $lap->fizetve, 'a lap nincs fizetve');
T::egyezik(1000.0, $dij($id, 0) , 'a díjak a visszavonás után is épek');

/* --- 4) Csak a pipa levétele (visszavonás nélkül) ------------------------ */

[$id, $e1, $e2] = $uj_lap();
t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '18000', 'eszkoz' => [0, $e2], 'kasszaba' => '1']);
$ment($id, ['fizetve' => '']);

$lap = t_lap($id);
T::egyezik(0, (int) $lap->fizetve, 'a pipa visszavonás nélkül is levehető');
T::egyezik(20000.0, (float) $lap->fizetett, 'visszavonás nélkül a befizetett összeg megmarad');
T::egyezik(0.0, t_bontas($id)[0]['fizetendo'], 'ezért a fizetendő 0 marad');
T::egyezik(1000.0, $dij($id, 0), 'a díj ilyenkor sem változik');

// Utólag kézzel visszaírt előleg (a díjakra): a fizetendő visszajön, a díjak maradnak.
$ment($id, ['fizetett' => '2000']);
$b = t_bontas($id);
T::egyezik(9000.0, $b[0]['fizetendo'], 'kézzel visszaírt előleg: a Lenovo fizetendője visszajött');
T::egyezik(9000.0, $b[$e2]['fizetendo'], 'kézzel visszaírt előleg: az iPhone fizetendője visszajött');
T::egyezik(1000.0, $dij($id, 0), 'kézzel visszaírt előleg: a Lenovo díja ép');
T::egyezik(1000.0, $dij($id, $e2), 'kézzel visszaírt előleg: az iPhone díja ép');

/* --- 5) Kézzel bepipált „Fizetve" (Fizetés ablak nélkül) ----------------- */

[$id, $e1, $e2] = $uj_lap();
$ment($id, ['fizetve' => '1']);

$lap = t_lap($id);
T::egyezik(1, (int) $lap->fizetve, 'a pipa kézzel is betehető');
T::egyezik(20000.0, (float) $lap->fizetett, 'kézzel bepipálva is a teljes összeg a Fizetett');
T::egyezik(1000.0, $dij($id, 0), 'kézzel bepipálva is 1 000 Ft a díj');
T::egyezik(18000.0, (float) $lap->fo_veg + (float) $wpdb->get_var($wpdb->prepare('SELECT SUM(veg) FROM ' . SDH_Muhely_Schema::tabla('munkalap_eszkoz') . ' WHERE munkalap_id = %d', $id)), 'a kézi lezárás összege is feljegyezve');

$ment($id, ['fizetve' => '', 'fizetes_vissza' => '1']);
T::egyezik(2000.0, (float) t_lap($id)->fizetett, 'a kézi lezárás is visszavonható');
T::egyezik(9000.0, t_bontas($id)[$e2]['fizetendo'], 'a visszavonás után visszajön a fizetendő');

/* --- 6) Egy eszközös lap ------------------------------------------------- */

$u  = t_ugyfel();
$e  = t_eszkoz($u, 'Samsung', 'Galaxy S24');
$id = t_munkalap($u, [$e]);
$ment($id, ['fizetett' => '1000', 'bevizsgalasi_dij' => '1']);
t_tetel($id, 'Kijelzőcsere', 30000, 0);
$ment($id);

t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'eloleg', 'osszeg' => '10000', 'kasszaba' => '1']);
t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kartya', 'fajta' => 'teljes', 'osszeg' => '19000', 'kasszaba' => '1']);

$lap = t_lap($id);
T::egyezik(30000.0, (float) $lap->fizetett, 'egy eszköz: díj + előleg + végfizetés = 30 000 Ft');
T::egyezik(30000.0, (float) $lap->brutto_ertek, 'egy eszköz: a végösszeg 30 000 Ft');
T::egyezik(1000.0, $dij($id, 0), 'egy eszköz: a díj 1 000 Ft maradt');

$ment($id, ['fizetve' => '', 'fizetes_vissza' => '1']);
T::egyezik(11000.0, (float) t_lap($id)->fizetett, 'egy eszköz: csak a végfizetés megy vissza, az előleg marad');
T::egyezik(19000.0, t_bontas($id)[0]['fizetendo'], 'egy eszköz: a fizetendő visszajött');
T::egyezik(1000.0, $dij($id, 0), 'egy eszköz: a díj a visszavonás után is 1 000 Ft');

/* --- 7) Régi kifizetett lapok egyszeri pótlása --------------------------- */

[$id, $e1, $e2] = $uj_lap();
$wpdb->update(SDH_Muhely_Schema::tabla('munkalap'), ['fizetve' => 1, 'fizetes_ideje' => current_time('Y-m-d')], ['id' => $id]);
T::egyezik(2000.0, (float) t_lap($id)->fizetett, 'régi állapot: fizetve, de a Fizetett csak az előleg');

delete_option('sdh_muhely_fizetett_potlas');
$M::fizetett_potlas();
T::egyezik(20000.0, (float) t_lap($id)->fizetett, 'a pótlás után a Fizetett a teljes összeg');
T::egyezik(1000.0, $dij($id, 0), 'a pótlás a díjhoz nem nyúl');
SDH_Muhely_Szamla::bevizsgalas_szinkron($id);
T::egyezik(1000.0, $dij($id, 0), 'a pótlás után a díj-szinkron is 1 000 Ft-ot ad');
$M::fizetett_potlas();
T::egyezik(20000.0, (float) t_lap($id)->fizetett, 'a pótlás csak egyszer fut');

T::vege('t28 fizetett összeg');
