<?php
/**
 * t27 – a bevizsgálási díj nem nőhet a későbbi befizetéstől.
 *
 * A bejelentett hiba: két eszköz, mindkettőn 1 000 Ft díj és 10 000 Ft
 * szolgáltatás. Az első eszköz 9 000 Ft-os kifizetése után a díjsora
 * 1 000-ről 10 000 Ft-ra nőtt, mert a fő eszköz díja „az előleg maradéka" volt.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$tetel_tabla = SDH_Muhely_Schema::tabla('munkalap_tetel');
$penztar     = SDH_Muhely_Schema::tabla('penztar');

/** A lap díj- és levonássorai: [eszköz-kulcs][forrás] => bruttó. */
$dijsorok = static function (int $id) use ($wpdb, $tetel_tabla): array {
    $ki = [];

    foreach ($wpdb->get_results($wpdb->prepare("SELECT eszkoz_id, forras, brutto_ertek FROM {$tetel_tabla} WHERE munkalap_id = %d AND forras IN ('bevizsgalas', 'bevizsgalas_le')", $id)) as $s) {
        $ki[(int) $s->eszkoz_id][(string) $s->forras] = (float) $s->brutto_ertek;
    }

    return $ki;
};

/** Az űrlap mentése a lap mostani adataival (csak a megadott mezők térnek el). */
$ment = static function (int $id, array $mezok = []) use ($wpdb): array {
    $lap     = t_lap($id);
    $csatolt = SDH_Muhely_Munkalap::tovabbi_reszletek($id);
    $post    = [
        'id' => $id, 'ugyfel_id' => (int) $lap->ugyfel_id, 'eszkoz_id' => (int) $lap->eszkoz_id, 'eszkozok_jelen' => '1',
        'allapot' => (string) $lap->allapot, 'keszult' => current_time('Y-m-d'),
        'fizetett' => (string) (float) $lap->fizetett, 'bevizsgalasi_dij' => (int) $lap->bevizsgalasi_dij === 1 ? '1' : '',
        'bevizsgalas_mod' => 'levon', 'tovabbi_eszkoz' => [], 'tovabbi_dij' => [],
    ];

    if ((int) $lap->fizetve === 1) {
        $post['fizetve'] = '1';
    }

    foreach ($csatolt as $r) {
        $post['tovabbi_eszkoz'][]                 = (int) $r->eszkoz_id;
        $post['tovabbi_dij'][(int) $r->eszkoz_id] = (string) (float) $r->bev_dij;
    }

    return t_ajax('sdh_muhely_munkalapok_ment', array_filter($mezok + $post, static fn ($v) => $v !== ''), 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
};

/* ===================================================================== */
/* A bejelentett eset: Lenovo L14 + iPhone 17, 1 000 Ft díj, 10 000 Ft munka */
/* ===================================================================== */

$u  = t_ugyfel('Koltai Teszt');
$e1 = t_eszkoz($u, 'Lenovo', 'L14');
$e2 = t_eszkoz($u, 'Apple', 'iPhone 17');
$id = t_munkalap($u, [$e1, $e2]);

// 1) Mindkét eszköz 1 000 Ft bevizsgálási díjat fizet (előleg 2 000, jelölő be) – pénztárba nem kerül.
$r = $ment($id, ['fizetett' => '2000', 'bevizsgalasi_dij' => '1', 'tovabbi_eszkoz' => [$e2], 'tovabbi_dij' => [$e2 => '1000']]);
T::igaz(!empty($r['success']), 'a díjak menthetők');

$d = $dijsorok($id);
T::egyezik(1000.0, $d[0]['bevizsgalas'] ?? 0.0, 'a Lenovo díja 1 000 Ft');
T::egyezik(1000.0, $d[$e2]['bevizsgalas'] ?? 0.0, 'az iPhone díja 1 000 Ft');
T::igaz(!isset($d[0]['bevizsgalas_le']) && !isset($d[$e2]['bevizsgalas_le']), 'javítás nélkül nincs levonás');
T::egyezik(2000.0, (float) t_lap($id)->brutto_ertek, 'javítás nélkül a végösszeg a két díj');
T::egyezik(0.0, (float) t_lap($id)->brutto_ertek - (float) t_lap($id)->fizetett, 'a díjak ki vannak fizetve');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$penztar} WHERE munkalap_id = %d", $id)), 'a díj mentése nem ír a pénztárba');

// 2) Mindkét eszközre 10 000 Ft vírusirtás.
t_tetel($id, 'Vírusirtás', 10000, 0);
t_tetel($id, 'Vírusirtás', 10000, $e2);
$ment($id);

$d = $dijsorok($id);
$b = t_bontas($id);

T::egyezik(1000.0, $d[0]['bevizsgalas'], 'a Lenovo díja maradt 1 000 Ft');
T::egyezik(-1000.0, $d[0]['bevizsgalas_le'] ?? 0.0, 'a Lenovo díja levonódik a javításból');
T::egyezik(-1000.0, $d[$e2]['bevizsgalas_le'] ?? 0.0, 'az iPhone díja levonódik a javításból');
T::egyezik(10000.0, $b[0]['brutto'], 'a Lenovo végösszege 10 000 Ft (a díj beszámít)');
T::egyezik(10000.0, $b[$e2]['brutto'], 'az iPhone végösszege 10 000 Ft');
T::egyezik(9000.0, $b[0]['fizetendo'], 'a Lenovo után 9 000 Ft a fizetendő');
T::egyezik(9000.0, $b[$e2]['fizetendo'], 'az iPhone után 9 000 Ft a fizetendő');
T::egyezik(20000.0, (float) t_lap($id)->brutto_ertek, 'a lap végösszege 20 000 Ft');
T::egyezik(18000.0, (float) t_lap($id)->brutto_ertek - (float) t_lap($id)->fizetett, 'a lap fizetendője 18 000 Ft');

// 3) A Lenovót kifizetik (9 000 Ft), az iPhone még marad.
$r = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '9000', 'eszkoz' => [0], 'kasszaba' => '1']);
T::igaz(!empty($r['success']), 'a Lenovo kifizethető');

$d   = $dijsorok($id);
$b   = t_bontas($id);
$lap = t_lap($id);

T::egyezik(1000.0, $d[0]['bevizsgalas'], 'A HIBA: a Lenovo díja a fizetés után is 1 000 Ft (nem 10 000)');
T::egyezik(-1000.0, $d[0]['bevizsgalas_le'], 'a Lenovo levonása a fizetés után is −1 000 Ft');
T::egyezik(1000.0, $d[$e2]['bevizsgalas'], 'az iPhone díja változatlan');
T::egyezik(20000.0, (float) $lap->brutto_ertek, 'a végösszeg a fizetés után is 20 000 Ft');
T::egyezik(11000.0, (float) $lap->fizetett, 'befizetve eddig: 2 000 díj + 9 000');
T::egyezik(9000.0, (float) $lap->fo_kulon, 'a 9 000 Ft a fő eszköz külön befizetése, nem díj');
T::egyezik(9000.0, (float) $lap->brutto_ertek - (float) $lap->fizetett, 'a lapon még 9 000 Ft a fizetendő');
T::egyezik(0.0, $b[0]['fizetendo'], 'a Lenovo után nincs több fizetendő');
T::egyezik(10000.0, $b[0]['brutto'], 'a Lenovo végösszege változatlanul 10 000 Ft');
T::egyezik(9000.0, $b[$e2]['fizetendo'], 'az iPhone után továbbra is 9 000 Ft');
T::igaz($b[0]['fizetve'] && !$b[$e2]['fizetve'], 'a Lenovo fizetve, az iPhone nem');
T::egyezik(0, (int) $lap->fizetve, 'a lap még nincs fizetve');

$sor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$penztar} WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1", $id));
T::egyezik(9000.0, (float) $sor->kp, 'a pénztárba 9 000 Ft került');
T::egyezik($e1, (int) $sor->eszkoz_id, 'a pénztártétel a Lenovóé');
T::igaz(stripos((string) $sor->leiras, 'előleg') === false, 'a kifizetett eszköz tétele nem „Előleg”');

// A lapot újra megnyitva és elmentve sem változik semmi (az űrlap az előleget hozza vissza).
$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
T::tartalmaz($urlap, 'data-fo-kulon="9000"', 'az űrlap ismeri a fő eszköz külön befizetését');
$ment($id);
$d = $dijsorok($id);
T::egyezik(1000.0, $d[0]['bevizsgalas'], 'újramentés után is 1 000 Ft a Lenovo díja');
T::egyezik(20000.0, (float) t_lap($id)->brutto_ertek, 'újramentés után is 20 000 Ft a végösszeg');

// 4) Az iPhone-t is kifizetik.
$r = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '9000', 'eszkoz' => [$e2], 'kasszaba' => '1']);
T::igaz(!empty($r['success']), 'az iPhone kifizethető');

$d   = $dijsorok($id);
$lap = t_lap($id);

T::egyezik(1, (int) $lap->fizetve, 'mindkét eszköz kifizetve: a lap fizetve');
T::egyezik(20000.0, (float) $lap->fizetett, 'A KÉRÉS: a végén a Fizetett 20 000 Ft (nem 11 000)');
T::egyezik(10000.0, t_bontas($id)[0]['eloleg'], 'a Lenovóra befizetve összesen 10 000 Ft');
T::egyezik(10000.0, t_bontas($id)[$e2]['eloleg'], 'az iPhone-ra befizetve összesen 10 000 Ft');
T::egyezik(1000.0, $d[0]['bevizsgalas'], 'a végén is 1 000 Ft a Lenovo díja');
T::egyezik(1000.0, $d[$e2]['bevizsgalas'], 'a végén is 1 000 Ft az iPhone díja');
T::egyezik(20000.0, (float) $lap->brutto_ertek, 'a végén is 20 000 Ft a végösszeg');
T::egyezik(18000.0, (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(kp + kartya + utalas) FROM {$penztar} WHERE munkalap_id = %d AND torolve = 0", $id)), 'a pénztárban 9 000 + 9 000 Ft');
T::egyezik(2, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$penztar} WHERE munkalap_id = %d AND torolve = 0", $id)), 'két pénztártétel, eszközönként egy');

$ment($id);
T::egyezik(1000.0, $dijsorok($id)[0]['bevizsgalas'], 'a kifizetett lap újramentése sem növeli a díjat');

/* ===================================================================== */
/* Fordított sorrend: előbb a csatolt eszközt fizetik ki                   */
/* ===================================================================== */

$id2 = t_munkalap($u, [$e1, $e2]);
$ment($id2, ['fizetett' => '2000', 'bevizsgalasi_dij' => '1', 'tovabbi_eszkoz' => [$e2], 'tovabbi_dij' => [$e2 => '1000']]);
t_tetel($id2, 'Vírusirtás', 10000, 0);
t_tetel($id2, 'Vírusirtás', 10000, $e2);
$ment($id2);

t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id2, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '9000', 'eszkoz' => [$e2], 'kasszaba' => '1']);
$d = $dijsorok($id2);
T::egyezik(1000.0, $d[0]['bevizsgalas'], 'a csatolt eszköz kifizetése nem változtat a fő eszköz díján');
T::egyezik(1000.0, $d[$e2]['bevizsgalas'], 'a csatolt eszköz díja a saját kifizetése után is 1 000 Ft');
T::egyezik(9000.0, t_bontas($id2)[0]['fizetendo'], 'a fő eszköz után 9 000 Ft maradt');

// Mindkettőre egyszerre előleg (4 000 Ft, a fizetendők arányában: 2 000 + 2 000… itt 4 000 a fő eszközre).
t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id2, 'mod' => 'kp', 'fajta' => 'eloleg', 'osszeg' => '4000', 'eszkoz' => [0], 'kasszaba' => '1']);
$d = $dijsorok($id2);
T::egyezik(1000.0, $d[0]['bevizsgalas'], 'az előleg (részfizetés) nem növeli a fő eszköz díját');
T::egyezik(5000.0, t_bontas($id2)[0]['fizetendo'], 'az előleg csökkenti a fő eszköz fizetendőjét');
T::egyezik(20000.0, (float) t_lap($id2)->brutto_ertek, 'az előleg nem változtat a végösszegen');

/* ===================================================================== */
/* Egy eszközös lap: ugyanez a hiba ott is megvolt                         */
/* ===================================================================== */

$id3 = t_munkalap($u, [$e1]);
$ment($id3, ['fizetett' => '1000', 'bevizsgalasi_dij' => '1']);
t_tetel($id3, 'Vírusirtás', 10000, 0);
$ment($id3);

$d = $dijsorok($id3);
T::egyezik(1000.0, $d[0]['bevizsgalas'], 'egy eszköz: a díj 1 000 Ft');
T::egyezik(10000.0, (float) t_lap($id3)->brutto_ertek, 'egy eszköz: a végösszeg 10 000 Ft');

t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id3, 'mod' => 'kp', 'fajta' => 'eloleg', 'osszeg' => '5000', 'kasszaba' => '1']);
$d = $dijsorok($id3);
T::egyezik(1000.0, $d[0]['bevizsgalas'], 'egy eszköz: az előleg nem növeli a díjat');
T::egyezik(-1000.0, $d[0]['bevizsgalas_le'], 'egy eszköz: a levonás −1 000 Ft marad');
T::egyezik(10000.0, (float) t_lap($id3)->brutto_ertek, 'egy eszköz: a végösszeg az előleg után is 10 000 Ft');
T::egyezik(6000.0, (float) t_lap($id3)->fizetett, 'egy eszköz: befizetve 1 000 díj + 5 000 előleg');
T::egyezik(4000.0, t_bontas($id3)[0]['fizetendo'], 'egy eszköz: 4 000 Ft a fizetendő');

$ment($id3);
T::egyezik(1000.0, $dijsorok($id3)[0]['bevizsgalas'], 'egy eszköz: újramentés után is 1 000 Ft a díj');

t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id3, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '4000', 'kasszaba' => '1']);
T::egyezik(1, (int) t_lap($id3)->fizetve, 'egy eszköz: a végén fizetve');
T::egyezik(1000.0, $dijsorok($id3)[0]['bevizsgalas'], 'egy eszköz: a végén is 1 000 Ft a díj');
T::egyezik(9000.0, (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(kp + kartya + utalas) FROM {$penztar} WHERE munkalap_id = %d AND torolve = 0", $id3)), 'egy eszköz: a pénztárban 5 000 + 4 000 Ft');

/* ===================================================================== */
/* Javítás nélkül a díj munkadíjként marad; az előleg lejjebb írása        */
/* ===================================================================== */

$id4 = t_munkalap($u, [$e1]);
$ment($id4, ['fizetett' => '1000', 'bevizsgalasi_dij' => '1']);
T::egyezik(1000.0, (float) t_lap($id4)->brutto_ertek, 'javítás nélkül a díj a végösszeg');
T::egyezik(0.0, t_bontas($id4)[0]['fizetendo'], 'javítás nélkül nincs több fizetendő');

// Az űrlapon a díj átírható (az előleg mező a díj): 1 000 → 2 500.
$ment($id4, ['fizetett' => '2500']);
T::egyezik(2500.0, $dijsorok($id4)[0]['bevizsgalas'], 'a díj az űrlapon átírható');

// Külön befizetés után az előleget lejjebb írva előbb a külön befizetés fogy, a díj marad.
t_tetel($id4, 'Javítás', 20000, 0);
t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id4, 'mod' => 'kp', 'fajta' => 'eloleg', 'osszeg' => '6000', 'kasszaba' => '1']);
T::egyezik(2500.0, $dijsorok($id4)[0]['bevizsgalas'], 'külön befizetés után a díj 2 500 Ft');
$ment($id4, ['fizetett' => '3000']);
T::egyezik(500.0, (float) t_lap($id4)->fo_kulon, 'a lejjebb írt előleg a külön befizetésből fogy');
T::egyezik(2500.0, $dijsorok($id4)[0]['bevizsgalas'], 'a lejjebb írt előleg a díjat nem bántja, amíg van külön befizetés');
$ment($id4, ['fizetett' => '1000']);
T::egyezik(0.0, (float) t_lap($id4)->fo_kulon, 'a külön befizetés elfogyott');
T::egyezik(1000.0, $dijsorok($id4)[0]['bevizsgalas'], 'ezután már a díj csökken');
$ment($id4, ['fizetett' => '0']);
T::igaz(!isset($dijsorok($id4)[0]['bevizsgalas']), 'előleg nélkül nincs díjsor (nem lesz negatív)');

T::vege('t27 bevizsgálási díj');
