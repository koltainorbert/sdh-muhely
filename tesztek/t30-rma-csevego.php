<?php
/**
 * t30 – az RMA csevegő a munkalapon (időrendben, a legfrissebb alul, lapozás
 * nélkül, frissítő végponttal), és az ügyféloldal külön Szolgáltatások és
 * Termékek füle.
 */

require __DIR__ . '/_keret.php';

$R = 'SDH_Muhely_Rma';

global $wpdb;

$u  = t_ugyfel('Csevegő Csaba');
$e  = t_eszkoz($u, 'Apple', 'iPhone 15');
$id = t_munkalap($u, [$e]);

t_tetel($id, 'Kijelzőcsere munkadíj', 25000);
t_tetel($id, 'iPhone 15 kijelző', 89000, 0, 'termek');
t_tetel($id, 'Védőfólia', 3000, 0, 'termek');
SDH_Muhely_Tetel::ujraszamol($id);

/* --- Csevegő ------------------------------------------------------------- */

$R::uzenet_ment($id, $u, 'ki', 'crm', 'ELSŐ üzenet', get_current_user_id());
$R::uzenet_ment($id, $u, 'ki', 'crm', 'MÁSODIK üzenet', get_current_user_id());
$R::uzenet_ment($id, $u, 'be', 'web', 'HARMADIK: az ügyfél válasza', 0);

$html = $R::uzenetek_html($id);

T::igaz(strpos($html, 'ELSŐ') < strpos($html, 'MÁSODIK') && strpos($html, 'MÁSODIK') < strpos($html, 'HARMADIK'), 'a szál időrendben áll: a legrégebbi felül, a legfrissebb alul');
T::egyezik(3, substr_count($html, 'class="sdh-uz '), 'minden üzenet egy buborék');
T::egyezik(2, substr_count($html, 'sdh-uz--sajat'), 'a szerviz üzenetei jobbra');
T::egyezik(1, substr_count($html, 'sdh-uz--ugyfel'), 'az ügyfélé balra');
T::egyezik(1, substr_count($html, 'sdh-uz--folytat'), 'az egymást követő, azonos feladójú üzenet csoportot alkot');
T::tartalmaz($html, '<div class="sdh-uz-nap"><span>Ma</span></div>', 'napelválasztó: Ma');
T::egyezik(1, substr_count($html, 'Új üzenetek'), 'az olvasatlan ügyfélüzenet fölött: Új üzenetek');

ob_start();
$R::munkalap_ful(t_lap($id));
$ful = (string) ob_get_clean();

T::tartalmaz($ful, 'data-sdh-chat', 'a lapfülön csevegő áll');
T::tartalmaz($ful, 'data-utolso="' . $R::utolso_uzenet_id($id) . '"', 'a csevegő tudja az utolsó üzenet azonosítóját');
T::igaz(preg_match('/data-sdh-uzenetek[^>]*data-sdh-lapoz/', $ful) !== 1, 'az üzenetszál nem lapozódik');
T::igaz(strpos($ful, 'data-sdh-uzenetek') < strpos($ful, 'data-sdh-uzenet-szoveg'), 'a beírómező a szál alatt áll');
T::tartalmaz($ful, 'Enter: küldés', 'Enter küld');
T::tartalmaz($ful, 'Csevegő Csaba', 'a fejlécben az ügyfél neve');

/* --- Frissítő végpont ----------------------------------------------------- */

$utolso = $R::utolso_uzenet_id($id);

$r = t_ajax('sdh_muhely_uzenet_szal', ['id' => $id, 'utan' => $utolso]);
T::igaz(!empty($r['success']) && $r['data']['valtozott'] === false && $r['data']['html'] === '', 'változatlan szálnál nem jön HTML');
T::egyezik(1, (int) $r['data']['szamlalo']['uj'], 'az olvasatlan számláló megmarad, amíg nem látszik');

$R::uzenet_ment($id, $u, 'be', 'web', 'NEGYEDIK: még egy kérdés', 0);

$r = t_ajax('sdh_muhely_uzenet_szal', ['id' => $id, 'utan' => $utolso, 'latszik' => '1']);
T::igaz(!empty($r['data']['valtozott']), 'új üzenetnél változott');
T::tartalmaz((string) $r['data']['html'], 'NEGYEDIK', 'a válasz hozza az új üzenetet');
T::egyezik($R::utolso_uzenet_id($id), (int) $r['data']['utolso'], 'és az új utolsó azonosítót');
T::egyezik(0, (int) $r['data']['szamlalo']['uj'], 'látszó csevegőnél az új üzenet rögtön olvasott');

$r = t_ajax('sdh_muhely_uzenet_kuld', ['id' => $id, 'szoveg' => 'ÖTÖDIK: válaszolunk']);
T::igaz(!empty($r['success']) && (int) $r['data']['utolso'] === $R::utolso_uzenet_id($id), 'a küldés válasza hozza az utolsó azonosítót');
T::igaz(strrpos((string) $r['data']['html'], 'ÖTÖDIK') > strrpos((string) $r['data']['html'], 'NEGYEDIK'), 'az elküldött üzenet a szál végére kerül');

$r = t_ajax('sdh_muhely_uzenet_szal', ['id' => 99999999, 'utan' => 0]);
T::igaz(empty($r['success']), 'nem létező lapra hiba jön');

/* --- Ügyféloldal: külön Szolgáltatások és Termékek fül -------------------- */

ob_start();
SDH_Muhely_Rma_Oldal::rma_oldal(t_lap($id), $R::ugyfel($u), true);
$oldal = (string) ob_get_clean();

T::tartalmaz($oldal, 'data-rma-ful="szolg"', 'van Szolgáltatások fül');
T::tartalmaz($oldal, 'data-rma-ful="termekek"', 'van Termékek fül');
T::nem_tartalmaz($oldal, 'data-rma-ful="tetelek"', 'a régi közös Tételek fül megszűnt');
T::tartalmaz($oldal, 'data-rma-elso="eszkoz"', 'javításnál az első fül az Eszköz');

$panel = static function (string $kulcs) use ($oldal): string {
    $tol = strpos($oldal, 'data-rma-panel="' . $kulcs . '"');
    $ig  = strpos($oldal, '</section>', (int) $tol);

    return $tol === false ? '' : substr($oldal, $tol, $ig - $tol);
};

$termekek = $panel('termekek');
$szolg    = $panel('szolg');

T::tartalmaz($termekek, 'iPhone 15 kijelző', 'a Termékek fülön ott a termék');
T::tartalmaz($termekek, 'Védőfólia', 'és a másik termék is');
T::nem_tartalmaz($termekek, 'Kijelzőcsere munkadíj', 'a Termékek fülön nincs szolgáltatás');
T::tartalmaz($szolg, 'Kijelzőcsere munkadíj', 'a Szolgáltatások fülön ott a szolgáltatás');
T::nem_tartalmaz($szolg, 'iPhone 15 kijelző', 'a Szolgáltatások fülön nincs termék');
// (Az ezres tagoló nem törő szóköz, ezért mintával.)
T::igaz(preg_match('/92\D{1,6}000/u', $termekek) === 1, 'a termékek részösszege');
T::igaz(preg_match('/117\D{1,6}000/u', $termekek) === 1, 'alatta a munkalap végösszege');
T::igaz(preg_match('/data-rma-ful="termekek"[^>]*>.*?<span class="sdh-fulek__db">2<\/span>/s', $oldal) === 1, 'a Termékek fül jelvénye: 2');

// Termék nélküli lap: a fül megvan, üres szöveggel.
$id2 = t_munkalap($u, [$e]);
ob_start();
SDH_Muhely_Rma_Oldal::rma_oldal(t_lap($id2), $R::ugyfel($u), true);
$oldal2 = (string) ob_get_clean();
T::tartalmaz($oldal2, 'Még nincs rögzített termék.', 'termék nélkül az üres szöveg áll');

T::vege('t30 RMA-csevegő');
