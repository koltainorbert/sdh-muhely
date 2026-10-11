<?php
/**
 * t29 – napi árukiadás és rendelések: ugyanaz a lap eszköz nélkül. Mentés
 * (ügyfél nélkül is), sorszám, fizetés és pénztár, automatikus lezárás,
 * a rendelés útja és értesítése, törzsek (helyszínek, beszállítók), és hogy
 * a javítási munkalapok rácsába / számlálójába nem keverednek bele.
 */

require __DIR__ . '/_keret.php';

$M = 'SDH_Muhely_Munkalap';
$E = 'SDH_Muhely_Eladas';

global $wpdb;

$penztar = SDH_Muhely_Schema::tabla('penztar');
$uzenet  = SDH_Muhely_Schema::tabla('uzenet');
$ml      = SDH_Muhely_Schema::tabla('munkalap');

$ment = static fn (array $mezok): array => t_ajax('sdh_muhely_munkalapok_ment', $mezok + [
    'id' => 0, 'tetelek_jelen' => 1, 'allapot' => 'nyitott', 'keszult' => current_time('Y-m-d'),
    'lap_afakulcs' => '27', 'lap_kedvezmeny' => '0', 'fizetett' => '0',
], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');

$termek = static fn (string $nev, float $ar, float $menny = 1): array => ['megnevezes' => $nev, 'brutto_ar' => (string) $ar, 'mennyiseg' => (string) $menny, 'me' => 'db', 'afa_kulcs' => '27'];

/* --- Séma ---------------------------------------------------------------- */

T::egyezik('javitas', (string) t_lap(t_munkalap($u0 = t_ugyfel(), [t_eszkoz($u0, 'Apple', 'iPhone 12')]))->fajta, 'a régi út munkalapja javítás');
T::egyezik(['javitas', 'arukiadas', 'rendeles'], array_keys($M::fajtak()), 'a három lapfajta');

$nyitott_elotte = $M::nyitott_db();

/* --- Napi árukiadás: ügyfél és eszköz nélkül ----------------------------- */

$urlap = t_ajax('sdh_muhely_arukiadas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0])['nyers'];
T::tartalmaz($urlap, 'Új napi árukiadás', 'az új árukiadás űrlapjának címe');
T::tartalmaz($urlap, 'name="fajta" value="arukiadas"', 'az űrlap viszi a fajtát');
T::tartalmaz($urlap, 'Rögzítés és fizetés', 'van „Rögzítés és fizetés” gomb');
T::tartalmaz($urlap, $E::NEVTELEN, 'az ügyfélmező helyőrzője: Napi árukiadás');
T::igaz(preg_match('/<label for="ful_m_eszkoz" hidden>/', $urlap) === 1, 'az Eszköz fül rejtve');
T::igaz(preg_match('/<label for="ful_m_hibak" hidden>/', $urlap) === 1, 'a Hibák fül rejtve');
T::igaz(preg_match('/<label for="ful_m_rma" data-sdh-rma-ful hidden>/', $urlap) === 1, 'árukiadásnál nincs RMA fül');
T::igaz(preg_match('/id="ful_m_termek" checked/', $urlap) === 1, 'a Termékek fül nyílik');
T::igaz(preg_match('/class="sdh-eladas-rejt" hidden/', $urlap) === 1, 'a bevizsgálási díj rejtve');
T::nem_tartalmaz($urlap, 'Bejelentett', 'a javítás állapotai nem választhatók');

$r = $ment(['fajta' => 'arukiadas', 'utana_fizet' => '1', 'tetelek' => ['termek' => [$termek('USB-C kábel', 3990, 2)], 'szolgaltatas' => [['megnevezes' => 'Fóliázás', 'brutto_ar' => '2500', 'mennyiseg' => '1']]]]);
T::igaz(!empty($r['success']), 'az árukiadás ügyfél és eszköz nélkül menthető' . (isset($r['data']['uzenet']) ? ' (' . $r['data']['uzenet'] . ')' : ''));

$aid = (int) ($r['data']['id'] ?? 0);
$a   = t_lap($aid);

T::egyezik('arukiadas', (string) $a->fajta, 'a lap fajtája árukiadás');
T::egyezik(0, (int) $a->ugyfel_id, 'nincs ügyfele');
T::egyezik(0, (int) $a->eszkoz_id, 'nincs eszköze');
T::igaz((int) $a->munkalap_szam > 0, 'rögtön sorszámot kapott');
T::egyezik(10480.0, (float) $a->brutto_ertek, 'a végösszeg: 2 × 3990 + 2500');
T::egyezik('arukiadas', $r['data']['fajta'] ?? '', 'a válasz megmondja a fajtát');
T::igaz(!empty($r['data']['uj']) && !empty($r['data']['utana_fizet']), 'új lap, fizetés ablakát kérte');
T::egyezik(10480.0, (float) ($r['data']['fizetendo'] ?? 0), 'a válasz fizetendője');
T::tartalmaz((string) ($r['data']['vissza'] ?? ''), 'arukiadas', 'a mentés az árukiadás listájára tér vissza');
T::tartalmaz((string) ($r['data']['vissza'] ?? ''), 'arukiadas_letrehozva', 'saját üzenettel');
T::egyezik(0, (int) $a->bevizsgalasi_dij, 'nincs bevizsgálási díj');

// A bevizsgálási díj akkor sem kerülhet rá, ha valaki beküldi.
$r2 = $ment(['fajta' => 'arukiadas', 'bevizsgalasi_dij' => '1', 'fizetett' => '5000', 'eszkoz_id' => t_eszkoz($u0, 'Samsung', 'A54'), 'tetelek' => ['termek' => [$termek('Töltő', 6000)]]]);
$a2 = t_lap((int) $r2['data']['id']);
T::egyezik(0, (int) $a2->bevizsgalasi_dij, 'beküldve sem lesz bevizsgálási díj');
T::egyezik(0, (int) $a2->eszkoz_id, 'beküldve sem lesz eszköze');
T::egyezik(6000.0, (float) $a2->brutto_ertek, 'díjsor nélkül a végösszeg a terméké');
T::egyezik(5000.0, (float) $a2->fizetett, 'előleg lehet rajta');

// Számozatlan állapot (Árajánlat) helyett az alapállapot.
$r3 = $ment(['fajta' => 'arukiadas', 'allapot' => 'arajanlat', 'tetelek' => ['termek' => [$termek('Tok', 1000)]]]);
T::igaz((int) t_lap((int) $r3['data']['id'])->munkalap_szam > 0, 'árajánlat állapottal küldve is sorszámot kap');

/* --- Fizetés: pénztár, automatikus lezárás ------------------------------- */

$furlap = t_ajax('sdh_muhely_penztarfizetes_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $aid])['nyers'];
T::tartalmaz($furlap, $E::NEVTELEN, 'a fizetés ablakában a vevő: Napi árukiadás');

$f = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $aid, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '10480', 'kasszaba' => '1', 'nev' => $E::NEVTELEN]);
T::igaz(!empty($f['success']), 'az árukiadás kifizethető');

$a = t_lap($aid);
T::egyezik(1, (int) $a->fizetve, 'fizetve');
T::egyezik(10480.0, (float) $a->fizetett, 'a Fizetett a befolyt összeg');
T::egyezik($M::lezart_kulcs(), (string) $a->allapot, 'a kifizetett árukiadás magától lezárul');
T::igaz((string) $a->lezarva !== '', 'a lezárás napja beállt');

$pt = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$penztar} WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1", $aid));
T::igaz($pt !== null && (float) $pt->kp === 10480.0 && (string) $pt->tipus === 'bevetel', 'a pénztárba bevételként került');
T::egyezik((string) (int) $a->munkalap_szam, (string) $pt->munkalap_szam, 'a pénztártétel a lap sorszámát viseli');

// Előleg: nem zárul le.
$f = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => (int) $r3['data']['id'], 'mod' => 'kp', 'fajta' => 'eloleg', 'osszeg' => '400', 'kasszaba' => '1']);
$a3 = t_lap((int) $r3['data']['id']);
T::egyezik(0, (int) $a3->fizetve, 'előleg után nincs fizetve');
T::egyezik('nyitott', (string) $a3->allapot, 'előleg után nyitva marad');

// A lezárás kikapcsolható.
update_option('sdh_muhely_eladas', ['ertesito' => '', 'auto_lezar' => false], false);
$f = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => (int) $r3['data']['id'], 'mod' => 'kartya', 'fajta' => 'teljes', 'osszeg' => '600', 'kasszaba' => '1']);
T::egyezik('nyitott', (string) t_lap((int) $r3['data']['id'])->allapot, 'kikapcsolt lezárásnál a kifizetett lap nyitva marad');
delete_option('sdh_muhely_eladas');

/* --- Árukiadás meglévő ügyféllel ----------------------------------------- */

$u  = t_ugyfel('Vevő Vilma');
$r4 = $ment(['fajta' => 'arukiadas', 'ugyfel_id' => $u, 'tetelek' => ['termek' => [$termek('Powerbank', 9990)]]]);
T::egyezik($u, (int) t_lap((int) $r4['data']['id'])->ugyfel_id, 'az árukiadáshoz ügyfél is köthető');

/* --- Helyszínek ---------------------------------------------------------- */

$tm = static fn (string $torzs, array $post): array => t_ajax('sdh_muhely_' . $torzs . '_ment', $post, 'sdh_nonce', 'sdh_muhely_torzs_' . $torzs);

$wpdb->query('DELETE FROM ' . SDH_Muhely_Schema::tabla('helyszin'));

$h = $tm('helyszinek', ['sorok' => [['id' => 0, 'nev' => 'Belváros', 'cim' => 'Fő tér 1.', 'aktiv' => 1], ['id' => 0, 'nev' => 'Pláza', 'aktiv' => 1], ['id' => 0, 'nev' => '', 'cim' => 'név nélkül', 'aktiv' => 1]], 'alap' => '1']);
T::igaz(!empty($h['success']), 'a helyszínek menthetők');

$helyek = $E::torzs_nevek('helyszinek', true);
T::egyezik(['Belváros', 'Pláza'], array_values($helyek), 'a név nélküli új sor nem mentődik');
$plaza = (int) array_search('Pláza', $helyek, true);

$urlap = t_ajax('sdh_muhely_arukiadas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0])['nyers'];
T::tartalmaz($urlap, '<select name="helyszin_id"', 'több helyszínnél van helyszínválasztó');
T::igaz(preg_match('/<option value="' . $plaza . '"\s+selected/', $urlap) === 1, 'az alapértelmezett helyszín van kiválasztva');

$r5 = $ment(['fajta' => 'arukiadas', 'helyszin_id' => $plaza, 'tetelek' => ['termek' => [$termek('Fólia', 2000)]]]);
T::egyezik($plaza, (int) t_lap((int) $r5['data']['id'])->helyszin_id, 'a helyszín mentődik');
$r5b = $ment(['fajta' => 'arukiadas', 'helyszin_id' => 999999, 'tetelek' => ['termek' => [$termek('Fólia', 2000)]]]);
T::egyezik(0, (int) t_lap((int) $r5b['data']['id'])->helyszin_id, 'nem létező helyszín nem mentődik');

// Inaktívvá tett helyszín: a választóból kikerül, a törzsben megmarad.
$sorok = [];
foreach ($E::torzs_sorok('helyszinek') as $i => $s) {
    $sorok[] = ['id' => (int) $s->id, 'nev' => (string) $s->nev, 'aktiv' => (string) $s->nev === 'Pláza' ? 0 : 1];
}
$tm('helyszinek', ['sorok' => $sorok]);
T::egyezik(['Belváros'], array_values($E::torzs_nevek('helyszinek', true)), 'az inaktív helyszín nem választható');
T::egyezik(2, count($E::torzs_nevek('helyszinek')), 'de a törzsben megmarad');

$urlap = t_ajax('sdh_muhely_arukiadas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0])['nyers'];
T::nem_tartalmaz($urlap, '<select name="helyszin_id"', 'egy helyszínnél nincs választó (single site)');
T::tartalmaz($urlap, 'name="helyszin_id" value="', 'az egyetlen helyszín rejtve megy');

// Jogosultság: a helyszínek szerkesztése adminfeladat.
$kollega = wp_insert_user(['user_login' => 'kollega' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(), 'role' => 'editor']);
$admin   = get_current_user_id();
get_userdata($kollega)->add_cap(SDH_Muhely_Admin_UI::jog());
wp_set_current_user($kollega);
$h = $tm('helyszinek', ['sorok' => [['id' => 0, 'nev' => 'Tiltott', 'aktiv' => 1]]]);
T::igaz(empty($h['success']), 'kolléga nem szerkesztheti a helyszíneket');
$bk = $tm('beszallitok', ['sorok' => [['id' => 0, 'nev' => 'Kolléga Nagyker ' . wp_rand(100, 999), 'aktiv' => 1]]]);
T::igaz(!empty($bk['success']), 'a beszállítókat a kolléga is szerkesztheti');
wp_set_current_user($admin);
T::igaz(!in_array('Tiltott', $E::torzs_nevek('helyszinek'), true), 'a tiltott mentés nem írt');

/* --- Rendelés ------------------------------------------------------------ */

$besz = $E::torzs_nevek('beszallitok', true);
T::igaz($besz !== [], 'van beszállító a listában');
$b1 = (int) array_key_first($besz);

$urlap = t_ajax('sdh_muhely_rendelesek_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0])['nyers'];
T::tartalmaz($urlap, 'Új rendelés', 'az új rendelés űrlapjának címe');
T::tartalmaz($urlap, 'name="beszallito_id"', 'van beszállító-választó');
T::tartalmaz($urlap, 'data-sdh-beszallitok', 'a beszállítók az űrlapról szerkeszthetők');
T::tartalmaz($urlap, 'name="rend_allapot"', 'van rendelés-állapot');
T::tartalmaz($urlap, 'Várható érkezés', 'a határidő itt várható érkezés');
T::igaz(preg_match('/<label for="ful_m_rma" data-sdh-rma-ful>/', $urlap) === 1, 'rendelésnél van RMA fül');

$r = $ment(['fajta' => 'rendeles', 'beszallito_id' => $b1, 'tetelek' => ['termek' => [$termek('Tok', 4990)]]]);
T::igaz(empty($r['success']) && strpos((string) ($r['data']['uzenet'] ?? ''), 'ügyfelet') !== false, 'rendelés ügyfél nélkül nem menthető');

$r = $ment(['fajta' => 'rendeles', 'ugyfel_id' => $u, 'beszallito_id' => $b1, 'rend_allapot' => 'megrendelve', 'rend_szam' => 'R-77', 'hatarido' => gmdate('Y-m-d', time() + 3 * DAY_IN_SECONDS), 'tetelek' => ['termek' => [$termek('Tok', 4990), $termek('Üvegfólia', 3000, 2)]]]);
T::igaz(!empty($r['success']), 'a rendelés menthető');

$rid = (int) $r['data']['id'];
$l   = t_lap($rid);

T::egyezik('rendeles', (string) $l->fajta, 'a lap fajtája rendelés');
T::egyezik($b1, (int) $l->beszallito_id, 'a beszállító mentődik');
T::egyezik('megrendelve', (string) $l->rend_allapot, 'a rendelés állapota mentődik');
T::egyezik('R-77', (string) $l->rend_szam, 'a rendelésszám mentődik');
T::egyezik(10990.0, (float) $l->brutto_ertek, 'a rendelés végösszege');
T::tartalmaz((string) $r['data']['vissza'], 'rendelesek', 'a mentés a rendelések listájára tér vissza');
T::egyezik('2 db Üvegfólia', explode(', ', $E::tetel_szoveg($rid))[1] ?? '', 'a tételek rövid felsorolása mennyiséggel');

// A fajta később nem írható át.
// (tetelek_jelen nélkül: a tételekhez ez a mentés nem nyúl.)
$ment(['id' => $rid, 'fajta' => 'javitas', 'ugyfel_id' => $u, 'rend_allapot' => 'megerkezett', 'tetelek_jelen' => '']);
T::egyezik(2, count(SDH_Muhely_Tetel::lista($rid)), 'tételek nélküli mentés nem töröl tételt');
T::egyezik('rendeles', (string) t_lap($rid)->fajta, 'a fajta mentéskor nem változik');
T::egyezik('megerkezett', (string) t_lap($rid)->rend_allapot, 'a rendelés állapota átírható');
T::egyezik($b1, (int) t_lap($rid)->beszallito_id, 'a nem küldött beszállító megmarad');
T::egyezik(1, $E::erkezett_db() > 0 ? 1 : 0, 'a megérkezett rendelés jelez a menüben');

// Ismeretlen rendelés-állapot: az alap.
$rx = $ment(['fajta' => 'rendeles', 'ugyfel_id' => $u, 'rend_allapot' => 'kitalalt', 'tetelek' => ['termek' => [$termek('Kábel', 1500)]]]);
T::egyezik('felveve', (string) t_lap((int) $rx['data']['id'])->rend_allapot, 'ismeretlen rendelés-állapot helyett: felvéve');

/* --- Értesítés: megérkezett ---------------------------------------------- */

$eu = t_ajax('sdh_muhely_rendelesertesit_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $rid])['nyers'];
T::tartalmaz($eu, 'Megérkezett a megrendelt áru', 'az értesítő alapszövege');
T::tartalmaz($eu, 'Vevő Vilma', 'az értesítőben az ügyfél neve');
T::tartalmaz($eu, $M::szam_formaz($l->munkalap_szam), 'az értesítőben a rendelés száma');
T::nem_tartalmaz($eu, '{tetelek}', 'a jelölők ki vannak töltve');

$uz_elotte = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$uzenet} WHERE munkalap_id = %d", $rid));
$er = t_ajax('sdh_muhely_rendelesertesit_ment', ['id' => $rid, 'szoveg' => 'Megjött a tok, átvehető.', 'email' => '1'], 'sdh_nonce', 'sdh_muhely_rendelesertesit');
T::igaz(!empty($er['success']), 'az értesítés elküldhető');
T::egyezik($uz_elotte + 1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$uzenet} WHERE munkalap_id = %d", $rid)), 'az értesítés a rendelés üzenetei közé került');
T::egyezik('ki', (string) $wpdb->get_var($wpdb->prepare("SELECT irany FROM {$uzenet} WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1", $rid)), 'kimenő üzenetként');
T::egyezik('ertesitve', (string) t_lap($rid)->rend_allapot, 'a rendelés állapota: ügyfél értesítve');
T::igaz((string) t_lap($rid)->rend_ertesitve !== '', 'az értesítés ideje rögzült');

$er = t_ajax('sdh_muhely_rendelesertesit_ment', ['id' => $aid, 'szoveg' => 'x'], 'sdh_nonce', 'sdh_muhely_rendelesertesit');
T::igaz(empty($er['success']), 'árukiadáshoz nem küldhető rendelés-értesítés');

/* --- Rendelés: fizetés és lezárás ---------------------------------------- */

t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $rid, 'mod' => 'kartya', 'fajta' => 'teljes', 'osszeg' => (string) (float) t_lap($rid)->brutto_ertek, 'kasszaba' => '1']);
T::egyezik(1, (int) t_lap($rid)->fizetve, 'a rendelés kifizethető');
T::egyezik('nyitott', (string) t_lap($rid)->allapot, 'kifizetve, de még át nem adva: nyitva marad');

$tetelek_most = [];
foreach (SDH_Muhely_Tetel::lista($rid) as $t) {
    $tetelek_most[] = ['id' => (int) $t->id, 'megnevezes' => (string) $t->megnevezes, 'brutto_ar' => (string) (float) $t->brutto_ar, 'mennyiseg' => (string) (float) $t->mennyiseg, 'afa_kulcs' => '27'];
}

$ment(['id' => $rid, 'ugyfel_id' => $u, 'allapot' => 'nyitott', 'fizetve' => '1', 'fizetett' => (string) (float) t_lap($rid)->fizetett, 'rend_allapot' => 'atadva', 'tetelek' => ['termek' => $tetelek_most]]);
T::egyezik('atadva', (string) t_lap($rid)->rend_allapot, 'átadva');
T::egyezik($M::lezart_kulcs(), (string) t_lap($rid)->allapot, 'az átadott és kifizetett rendelés lezárul');

/* --- RMA és ügyféloldal -------------------------------------------------- */

ob_start();
SDH_Muhely_Rma_Oldal::rma_oldal(t_lap($rid), SDH_Muhely_Rma::ugyfel($u), true);
$oldal = (string) ob_get_clean();

T::nem_tartalmaz($oldal, 'data-rma-ful="eszkoz"', 'a rendelés ügyféloldalán nincs Eszköz fül');
T::nem_tartalmaz($oldal, 'data-rma-ful="hibak"', 'nincs Hibák fül');
T::tartalmaz($oldal, 'data-rma-ful="termekek"', 'van termék-fül');
T::tartalmaz($oldal, 'data-rma-elso="termekek"', 'az első fül a termékeké');
T::tartalmaz($oldal, 'Rendelés állapota', 'látszik a rendelés állapota');
T::tartalmaz($oldal, 'Üvegfólia', 'látszanak a rendelt termékek');
T::tartalmaz($oldal, 'Megjött a tok, átvehető.', 'az értesítés ott van az üzenetek között');

ob_start();
SDH_Muhely_Rma::munkalap_ful(t_lap($rid));
$ful = (string) ob_get_clean();
T::tartalmaz($ful, 'Rendelés állapota: olvassa be', 'a rendelés címkéje rendelésről szól');

/* --- A javítási munkalapok közé nem keverednek --------------------------- */

T::egyezik($nyitott_elotte, $M::nyitott_db(), 'a nyitott munkalapok számlálója nem nőtt az eladásoktól');

$racs = SDH_Muhely_Racs::adat(SDH_Muhely_Racs::allapot_tisztit(['meret' => 200]));
$racs_idk = array_map(static fn (array $s): int => (int) ($s['id'] ?? 0), $racs['sorok']);
T::igaz(!in_array($aid, $racs_idk, true) && !in_array($rid, $racs_idk, true), 'a rácsban nincs árukiadás és rendelés');
T::igaz($racs['sorok'] !== [], 'a rácsban ott vannak a javítási munkalapok');

T::igaz($E::mai_arukiadas_db() >= 5, 'a mai árukiadások számlálója');

/* --- Listaoldalak: figyelmeztetés nélkül kirajzolódnak ------------------- */

$figy = [];
set_error_handler(static function (int $szint, string $szoveg) use (&$figy): bool {
    $figy[] = $szoveg;

    return true;
});

foreach (['oldal_arukiadas' => ['USB-C kábel', $E::NEVTELEN], 'oldal_rendelesek' => ['Kábel', 'Felvéve']] as $fuggveny => $vart) {
    $_GET = ['szuro' => $fuggveny === 'oldal_rendelesek' ? 'mind' : ''];
    ob_start();
    $E::$fuggveny();
    $html = (string) ob_get_clean();

    foreach ($vart as $resz) {
        T::tartalmaz($html, $resz, $fuggveny . ' listája');
    }
}

$_GET = ['k' => 'Üvegfólia'];
ob_start();
$E::oldal_rendelesek();
$html = (string) ob_get_clean();
T::tartalmaz($html, 'R-77', 'a rendelések tételnévre is kereshetők (a lezárt is előkerül)');
$_GET = [];

ob_start();
$E::beallitas_doboz();
ob_end_clean();

restore_error_handler();
T::egyezik([], $figy, 'a listák és a beállítás-doboz PHP-figyelmeztetés nélkül futnak');

T::vege('t29 eladás');
