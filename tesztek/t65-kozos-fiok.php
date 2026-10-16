<?php
/**
 * t65 – 0.72.0: közös fiók „Ki vagy?" választó (H2) és az egységes kapcsoló.
 *
 * - Beállítások › Jogosultságok: ki/be kapcsolható, a PIN külön is; közös fiókok, dolgozók (név + PIN).
 * - A dolgozó belépni nem tud; a felelős listában szerepel.
 * - Választás nélkül a közös fiókon a rendszer AJAX-hívásai nem futnak (a választó takarja a felületet).
 * - PIN: hibás → elutasít, 5 hibás próba után vár; jó → a választás rögzül (böngészőnként).
 * - A Naplóba, a pénztárba, az új lap felelősébe a kiválasztott dolgozó kerül; a H1 ütközés személyre szól.
 * - Kapcsoló: minden be/ki jelölő zöld/piros, „Aktív / Inaktív" felirattal (táblázatban nem).
 */

require __DIR__ . '/_keret.php';

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/user.php';

$naplo = SDH_Muhely_Valtozasnaplo::tabla();
$admin = get_current_user_id();
$bolt  = wp_insert_user(['user_login' => 'bolt_t65_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'sdh_kollega', 'display_name' => 'Bolt Közös']);
T::igaz(is_int($bolt) && $bolt > 0, 'a közös fiók létrejött');

$beall = static function (array $post): void {
    $_POST = wp_slash($post + ['kivagy_jelen' => '1']);
    SDH_Muhely_Szemely::beallitas_mentes();
    $_POST = [];
    SDH_Muhely_Szemely::felejt();
};

/* --- 1. Alapállapot: kikapcsolva, semmi nem változik --------------------------- */

delete_option(SDH_Muhely_Szemely::OPT);
$b = SDH_Muhely_Szemely::beallitas();
T::egyezik(false, $b['be'], 'alapból kikapcsolt');
T::egyezik(true, $b['pin'], 'a PIN alapból kell');
wp_set_current_user($bolt);
T::egyezik(false, SDH_Muhely_Szemely::kozos(), 'kikapcsolva nincs közös fiók');
T::egyezik($bolt, SDH_Muhely_Szemely::ki(), 'kikapcsolva a belépett felhasználó dolgozik');

/* --- 2. Beállítás: dolgozók felvétele, közös fiók -------------------------------- */

wp_set_current_user($admin);
$beall([
    'kivagy_be' => '1', 'kivagy_pin' => '1', 'kivagy_fiok' => [(string) $bolt],
    'dolgozo_uj' => [['nev' => 'Gábor T65', 'pin' => '1234'], ['nev' => 'Anna T65', 'pin' => '12'], ['nev' => '', 'pin' => '9999']],
]);

$b = SDH_Muhely_Szemely::beallitas();
T::igaz($b['be'] && $b['pin'] && $b['fiokok'] === [$bolt], 'a beállítás mentődött (be, PIN, közös fiók)');

$dolgozok = SDH_Muhely_Szemely::dolgozok();
$nevek    = array_column($dolgozok, 'nev');
T::igaz(in_array('Gábor T65', $nevek, true) && in_array('Anna T65', $nevek, true), 'a két új dolgozó felkerült (üres név nem)');
$gabor = (int) array_search('Gábor T65', array_combine(array_keys($dolgozok), $nevek), true);
$anna  = (int) array_search('Anna T65', array_combine(array_keys($dolgozok), $nevek), true);
T::egyezik(true, $dolgozok[$gabor]['pin'], 'Gábor PIN-je beállt');
T::egyezik(false, $dolgozok[$anna]['pin'], 'a 2 jegyű PIN nem érvényes (4–8 számjegy kell)');
T::nem_tartalmaz((string) get_user_meta($gabor, SDH_Muhely_Szemely::META_PIN, true), '1234', 'a PIN csak lenyomatként tárolódik');

$u = get_userdata($gabor);
T::igaz(in_array(SDH_Muhely_Szemely::SZEREP, (array) $u->roles, true), 'a dolgozó saját, jog nélküli szerepet kap');
T::egyezik(false, user_can($gabor, SDH_Muhely_Admin_UI::jog()), 'a dolgozónak nincs joga a rendszerhez (belépni sem tud vele)');
T::igaz(is_wp_error(SDH_Muhely_Szemely::belepes_tiltas($u)), 'a dolgozó nevével a belépés tiltott');
T::igaz(isset(SDH_Muhely_Munkalap::felelosok()[$gabor]), 'a dolgozó választható felelősnek');

/* --- 3. A közös fiókon választás nélkül megáll a rendszer ------------------------ */

wp_set_current_user($bolt);
unset($_COOKIE[SDH_Muhely_Szemely::SUTI]);
SDH_Muhely_Szemely::felejt();
T::egyezik(true, SDH_Muhely_Szemely::kell_valasztani(), 'a közös fiókon választani kell');

add_action('wp_ajax_sdh_muhely_t65_proba', static function (): void {
    wp_send_json_success(['futott' => true]);
});
add_action('wp_ajax_sdh_muhely_t65_proba', [SDH_Muhely_Szemely::class, 'ajax_kapu'], 0);
$v = t_ajax('sdh_muhely_t65_proba');
T::igaz(empty($v['success']) && !empty($v['data']['ki_vagy']), 'választás nélkül a rendszer hívása nem fut');

ob_start();
SDH_Muhely_Szemely::valaszto();
$html = (string) ob_get_clean();
T::tartalmaz($html, 'data-sdh-kivagy', 'a „Ki vagy?” választó kirajzolódik');
T::tartalmaz($html, 'Gábor T65', 'a dolgozók neve a választón');
T::tartalmaz($html, 'data-sdh-kivagy-pin', 'PIN mező, mert a PIN be van kapcsolva');

/* --- 4. PIN ---------------------------------------------------------------------- */

$v = t_ajax('sdh_muhely_ki_vagy', ['szemely' => (string) $gabor, 'pin' => '0000']);
T::igaz(empty($v['success']) && ($v['data']['uzenet'] ?? '') === 'Hibás PIN.', 'hibás PIN elutasítva');
T::egyezik(0, SDH_Muhely_Szemely::aktiv_id(), 'hibás PIN után nincs választás');

$v = t_ajax('sdh_muhely_ki_vagy', ['szemely' => (string) $anna, 'pin' => '']);
T::tartalmaz((string) ($v['data']['uzenet'] ?? ''), 'nincs PIN', 'PIN nélküli dolgozónál útmutatás (az admin adja meg)');

for ($n = 0; $n < 5; $n++) {
    t_ajax('sdh_muhely_ki_vagy', ['szemely' => (string) $gabor, 'pin' => '0000']);
}
$v = t_ajax('sdh_muhely_ki_vagy', ['szemely' => (string) $gabor, 'pin' => '1234']);
T::tartalmaz((string) ($v['data']['uzenet'] ?? ''), 'Túl sok', '5 hibás próba után a jó PIN sem megy egy ideig');
delete_transient('sdh_muhely_kivagy_proba_' . $bolt . '_' . $gabor);

$v = t_ajax('sdh_muhely_ki_vagy', ['szemely' => (string) $gabor, 'pin' => '1234']);
T::igaz(!empty($v['success']), 'jó PIN: a választás sikerült');
SDH_Muhely_Szemely::felejt();
T::egyezik($gabor, SDH_Muhely_Szemely::aktiv_id(), 'a választás rögzült ebben a böngészőben');
T::egyezik($gabor, SDH_Muhely_Szemely::ki(), 'a munka Gábor nevére kerül');
T::egyezik(false, SDH_Muhely_Szemely::kell_valasztani(), 'választás után nem kér újra');
T::tartalmaz(SDH_Muhely_Szemely::profil_sor(), 'Személy váltása', 'a profilmenüben váltható');

$v = t_ajax('sdh_muhely_t65_proba');
T::igaz(!empty($v['success']), 'választás után a rendszer hívásai futnak');

/* --- 5. Napló, felelős, H1 -------------------------------------------------------- */

$ugyfel = t_ugyfel('Közös Kálmán');
$lap    = t_munkalap($ugyfel, [t_eszkoz($ugyfel, 'Apple', 'iPhone 13')]);
$wpdb->update(SDH_Muhely_Schema::tabla('munkalap'), ['megjegyzes' => 'T65 módosítás'], ['id' => $lap]);
$wpdb->get_var('SELECT 1');
$sor = $wpdb->get_row("SELECT felhasznalo, felhasznalo_nev FROM {$naplo} WHERE fo_tipus = 'munkalap' AND fo_id = {$lap} ORDER BY id DESC LIMIT 1");
T::egyezik($gabor, (int) ($sor->felhasznalo ?? 0), 'a Naplóban a kiválasztott dolgozó azonosítója áll');
T::egyezik('Gábor T65', (string) ($sor->felhasznalo_nev ?? ''), 'a Naplóban a dolgozó neve áll');

$alap = SDH_Muhely_Utkozes::alap();
T::egyezik([], SDH_Muhely_Utkozes::masok_modositasai('munkalap', $lap, $alap - 5, $gabor), 'a saját módosítás nem ütközés');
T::igaz(SDH_Muhely_Utkozes::masok_modositasai('munkalap', $lap, $alap - 5, $anna) !== [], 'más dolgozónak (ugyanazon a fiókon) ütközés');

$p = SDH_Muhely_Utkozes::pulzus([], $bolt);
T::igaz(is_array($p), 'a pulzus a dolgozó nevén fut');

/* --- 6. Váltás, inaktív dolgozó, PIN kikapcsolva ---------------------------------- */

$v = t_ajax('sdh_muhely_ki_vagy_ki');
T::igaz(!empty($v['success']), 'Személy váltása: a választás törlődik');
SDH_Muhely_Szemely::felejt();
T::egyezik(0, SDH_Muhely_Szemely::aktiv_id(), 'váltás után újra választani kell');

wp_set_current_user($admin);
$beall(['kivagy_be' => '1', 'kivagy_fiok' => [(string) $bolt], 'dolgozo' => [(string) $anna => ['nev' => 'Anna T65', 'pin' => '', 'jelen' => '1']]]);
T::egyezik(false, SDH_Muhely_Szemely::beallitas()['pin'], 'a PIN kikapcsolható');
T::igaz(!isset(SDH_Muhely_Szemely::dolgozok(true)[$anna]), 'inaktívra állított dolgozó nem választható');

wp_set_current_user($bolt);
$v = t_ajax('sdh_muhely_ki_vagy', ['szemely' => (string) $anna]);
T::igaz(empty($v['success']), 'inaktív dolgozó nem választható');
$v = t_ajax('sdh_muhely_ki_vagy', ['szemely' => (string) $gabor]);
T::igaz(!empty($v['success']), 'PIN nélkül elég a név');

ob_start();
SDH_Muhely_Szemely::torol();
SDH_Muhely_Szemely::valaszto();
T::nem_tartalmaz((string) ob_get_clean(), 'data-sdh-kivagy-pin', 'PIN kikapcsolva nincs PIN mező');

wp_set_current_user($admin);
$beall([]);
wp_set_current_user($bolt);
T::egyezik(false, SDH_Muhely_Szemely::kell_valasztani(), 'a választó kikapcsolható');

/* --- 7. Beállítások › Jogosultságok fül ------------------------------------------- */

wp_set_current_user($admin);
ob_start();
SDH_Muhely_Szemely::jogosultsagok_rajz();
$ful = (string) ob_get_clean();
T::tartalmaz($ful, 'name="zar_munkalap"', 'a fülön a zárolás beállítása');
T::tartalmaz($ful, 'name="kivagy_be"', 'a fülön a „Ki vagy?” kapcsolója');
T::tartalmaz($ful, 'name="kivagy_pin"', 'a fülön a PIN kapcsolója');
T::tartalmaz($ful, 'dolgozo_uj[0][nev]', 'új dolgozó felvehető');

/* --- 8. Egységes kapcsoló --------------------------------------------------------- */

$css = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/kapcsolo.css');
$js  = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/kapcsolo.js');
T::tartalmaz($css, 'var(--sdh-siker)', 'bekapcsolva zöld');
T::tartalmaz($css, 'var(--sdh-hiba)', 'kikapcsolva piros');
T::tartalmaz($css, ':not(table input)', 'táblázatban (sorkijelölés) marad a jelölőnégyzet');
T::tartalmaz($js, "'Aktív'", 'felirat: Aktív');
T::tartalmaz($js, "'Inaktív'", 'felirat: Inaktív');
T::tartalmaz($js, 'MutationObserver', 'a később megnyíló popupokban is');
T::nem_tartalmaz($js . (string) file_get_contents(SDH_MUHELY_DIR . 'assets/szemely.js'), 'innerHTML', 'nincs innerHTML');
$fe = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-frontend.php');
$au = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-admin-ui.php');
foreach (['assets/kapcsolo.css', 'assets/kapcsolo.js', 'assets/szemely.css', 'assets/szemely.js'] as $f) {
    T::igaz(str_contains($fe, $f) && str_contains($au, $f), $f . ' mindkét felületen betöltődik');
}

/* --- Takarítás ------------------------------------------------------------------- */

delete_option(SDH_Muhely_Szemely::OPT);
wp_delete_user($gabor);
wp_delete_user($anna);
wp_delete_user($bolt);
unset($_COOKIE[SDH_Muhely_Szemely::SUTI]);
SDH_Muhely_Szemely::felejt();
wp_set_current_user($admin);

T::vege('t65 közös fiók (H2) + egységes kapcsoló');
