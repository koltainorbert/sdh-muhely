<?php
/**
 * t24 – RMA: az üzenet eszközhöz köthető, az ügyféloldal eszközönként mutatja
 * az állapotot és a fizetést, a történetben ott az eszköz neve.
 */

require __DIR__ . '/_keret.php';

$M = 'SDH_Muhely_Munkalap';
$R = 'SDH_Muhely_Rma';

global $wpdb;

$uzenet = SDH_Muhely_Schema::tabla('uzenet');

$u  = t_ugyfel();
$e1 = t_eszkoz($u, 'Apple', 'iPhone 14');
$e2 = t_eszkoz($u, 'Samsung', 'Galaxy A34');
$e9 = t_eszkoz($u, 'Idegen', 'Nincs a lapon');
$id = t_munkalap($u, [$e1, $e2]);

/* --- Eszköznevek ------------------------------------------------------- */

T::egyezik([$e1 => '1. Apple iPhone 14', $e2 => '2. Samsung Galaxy A34'], $R::eszkoz_nevek($id), 'eszköznevek a valódi azonosító szerint, sorszámozva');

$id1 = t_munkalap($u, [$e1]);
T::egyezik([], $R::eszkoz_nevek($id1), 'egy eszközös lapnál nincs eszközcímke');

/* --- Üzenet mentése eszközzel ------------------------------------------ */

$a = $R::uzenet_ment($id, $u, 'ki', 'crm', 'Megjött az alkatrész a Samsunghoz.', get_current_user_id(), false, $e2);
$b = $R::uzenet_ment($id, $u, 'ki', 'crm', 'Általános üzenet.', get_current_user_id());
$c = $R::uzenet_ment($id, $u, 'ki', 'crm', 'Idegen eszközre hivatkozik.', get_current_user_id(), false, $e9);
$d = $R::uzenet_ment($id, $u, 'be', 'rma', 'Mikor lesz kész az iPhone?', 0, false, $e1);

$eszkoz = static fn (int $uid): int => (int) $wpdb->get_var($wpdb->prepare("SELECT eszkoz_id FROM {$uzenet} WHERE id = %d", $uid));

T::igaz($a > 0 && $b > 0 && $c > 0 && $d > 0, 'az üzenetek mentődtek');
T::egyezik($e2, $eszkoz($a), 'az üzenet a csatolt eszközhöz kötődik');
T::egyezik(0, $eszkoz($b), 'eszköz nélkül az egész lapról szól');
T::egyezik(0, $eszkoz($c), 'ami nem a lap eszköze, az az egész lapról szól');
T::egyezik($e1, $eszkoz($d), 'a fő eszközhöz is köthető (ügyfél üzenete)');

/* --- A szál a CRM-ben --------------------------------------------------- */

$html = $R::uzenetek_html($id);
T::tartalmaz($html, '<span class="sdh-uz__eszkoz">2. Samsung Galaxy A34</span>', 'a szálban ott az eszköz címkéje');
T::tartalmaz($html, '<span class="sdh-uz__eszkoz">1. Apple iPhone 14</span>', 'az ügyfél üzeneténél is ott a címke');
T::egyezik(2, substr_count($html, 'sdh-uz__eszkoz'), 'csak az eszközhöz kötött üzenet kap címkét');

/* --- Küldés AJAX-szal ---------------------------------------------------- */

$r = t_ajax('sdh_muhely_uzenet_kuld', ['id' => $id, 'szoveg' => 'Elkészült a Samsung, átvehető.', 'eszkoz' => $e2]);
T::igaz(!empty($r['success']), 'az üzenet AJAX-szal küldhető');
T::egyezik($e2, (int) $wpdb->get_var($wpdb->prepare("SELECT eszkoz_id FROM {$uzenet} WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1", $id)), 'az AJAX-szal küldött üzenet eszköze');
T::egyezik(3, substr_count((string) ($r['data']['html'] ?? ''), 'sdh-uz__eszkoz'), 'a válasz szálában ott az új címke');

$r = t_ajax('sdh_muhely_uzenet_kuld', ['id' => $id, 'szoveg' => 'Eszköz megadása nélkül.']);
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare("SELECT eszkoz_id FROM {$uzenet} WHERE munkalap_id = %d ORDER BY id DESC LIMIT 1", $id)), 'eszköz nélkül küldve az egész lapról szól');

/* --- Az RMA lapfül a CRM-ben -------------------------------------------- */

ob_start();
$R::munkalap_ful(t_lap($id));
$ful = (string) ob_get_clean();

T::tartalmaz($ful, 'data-sdh-uzenet-eszkoz', 'több eszköznél van eszközválasztó');
T::tartalmaz($ful, 'Az egész munkalap', 'az alap az egész munkalap');
T::tartalmaz($ful, 'value="' . $e2 . '"', 'a választóban ott a csatolt eszköz');
T::igaz(preg_match('/value="0" data-sdh-uzenet-eszkoz checked/', $ful) === 1, 'alapból az egész munkalap van kiválasztva');

ob_start();
$R::munkalap_ful(t_lap($id1));
$ful1 = (string) ob_get_clean();
T::nem_tartalmaz($ful1, 'data-sdh-uzenet-eszkoz', 'egy eszköznél nincs eszközválasztó');

// Százalékjel a névben nem töri el a kiírást.
$e5  = t_eszkoz($u, 'Teszt', '100% gyári');
$id5 = t_munkalap($u, [$e1, $e5]);
ob_start();
$R::munkalap_ful(t_lap($id5));
$ful5 = (string) ob_get_clean();
T::tartalmaz($ful5, '2. Teszt 100% gyári', 'a százalékjeles eszköznév épen jelenik meg');
T::tartalmaz($ful5, 'data-sdh-uzenet-kuld', 'a százalékjel után is megvan az űrlap vége');

/* --- Állapottörténet a CRM-ben ------------------------------------------ */

$M::eszkoz_allapot_valt($id, $e2, 'elkeszult');

ob_start();
$R::munkalap_ful(t_lap($id));
$ful = (string) ob_get_clean();
T::tartalmaz($ful, '<b class="sdh-rma-naplo__eszkoz">2. Samsung Galaxy A34:</b>', 'a történetben ott az eszköz neve');

/* --- Az ügyféloldal ------------------------------------------------------ */

t_tetel($id, 'Kijelzőcsere', 30000, 0);
t_tetel($id, 'Akkucsere', 12000, $e2);
SDH_Muhely_Tetel::ujraszamol($id);
$M::eszkoz_fizetve_ir($id, $e2, true);

$ugyfel = $R::ugyfel($u);

ob_start();
SDH_Muhely_Rma_Oldal::rma_oldal(t_lap($id), $ugyfel, true);
$oldal = (string) ob_get_clean();

T::tartalmaz($oldal, 'Részben elkészült', 'az ügyfél látja a közös állapotot');
T::tartalmaz($oldal, 'class="rma-eszall"', 'az ügyféloldalon ott az eszközönkénti állapot');
T::tartalmaz($oldal, '2. Samsung Galaxy A34', 'az ügyféloldalon ott az eszköz neve');
T::tartalmaz($oldal, 'rma-eszall__fizetve', 'az ügyfél látja, melyik eszköz van kifizetve');
T::tartalmaz($oldal, 'rma-eszall__fizetendo', 'az ügyfél látja a másik eszköz fizetendőjét');
T::tartalmaz($oldal, 'class="rma-uz-eszkoz"', 'az ügyfél megjelölheti, melyik eszközről ír');
T::tartalmaz($oldal, 'name="eszkoz" value="' . $e1 . '"', 'az ügyfél választójában ott a fő eszköz');
T::igaz(substr_count($oldal, 'rma-lista__eszkoz') >= 3, 'az üzeneteknél és a történetben ott az eszközcímke');

SDH_Muhely_Rma_Oldal::nyelv_kenyszerit('en');
ob_start();
SDH_Muhely_Rma_Oldal::rma_oldal(t_lap($id), $ugyfel, true);
$angol = (string) ob_get_clean();
T::tartalmaz($angol, 'Partly ready', 'a részben állapot angolul is megvan');
T::tartalmaz($angol, 'Which device is it about?', 'az eszközválasztó felirata angolul');
SDH_Muhely_Rma_Oldal::nyelv_kenyszerit('hu');

ob_start();
SDH_Muhely_Rma_Oldal::rma_oldal(t_lap($id1), $ugyfel, true);
$egy = (string) ob_get_clean();
T::nem_tartalmaz($egy, 'class="rma-eszall"', 'egy eszköznél nincs eszközönkénti lista');
T::nem_tartalmaz($egy, 'class="rma-uz-eszkoz"', 'egy eszköznél nincs eszközválasztó az ügyfélnek');

T::vege('t24 RMA-eszköz');
