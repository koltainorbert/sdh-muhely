<?php
/**
 * t70 – 0.76.0: három szint – fejlesztő, SDH adminisztrátor, a többi szerep.
 *
 * - Fejlesztő = WordPress-adminisztrátor: mindent lát, a WordPress-adminba bemegy.
 * - SDH adminisztrátor (WP-szerep `sdh_admin`): a rendszer beállításai, de a WordPress-adminból a műhelybe kerül,
 *   admin sáv nincs; a Modulok oldalt, a frissítést és a technikai adatokat nem látja.
 * - A bejelentkező képernyő nem mondja meg, hogy WordPress.
 */

require __DIR__ . '/_keret.php';

class T70_Atiranyit extends Exception
{
}

class T70_Die extends Exception
{
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$UI    = 'SDH_Muhely_Admin_UI';
$admin = get_current_user_id();

foreach (get_users(['search' => '*T70*', 'search_columns' => ['display_name']]) as $maradek) {
    wp_delete_user((int) $maradek->ID);
}

$felh = static function (string $nev, string $szerep): int {
    $id = wp_insert_user(['user_login' => 'x_' . wp_rand(10000, 99999), 'user_pass' => wp_generate_password(), 'role' => $szerep, 'display_name' => $nev]);

    return is_int($id) ? $id : 0;
};

$atiranyit = static function (callable $fv): string {
    $szuro = static function ($hely): void {
        throw new T70_Atiranyit((string) $hely);
    };
    add_filter('wp_redirect', $szuro);
    $cel = '';
    $ajax_volt = has_filter('wp_doing_ajax', '__return_true') !== false;
    remove_filter('wp_doing_ajax', '__return_true');

    try {
        $fv();
    } catch (T70_Atiranyit $e) {
        $cel = $e->getMessage();
    }

    remove_filter('wp_redirect', $szuro);

    if ($ajax_volt) {
        add_filter('wp_doing_ajax', '__return_true');
    }

    return $cel;
};

/* --- 1. A szerep és a jogok ---------------------------------------------------------------- */

$UI::jog_telepit();
$szerep = get_role($UI::ADMIN_SZEREP);
T::igaz($szerep !== null, 'az „SDH adminisztrátor" WP-szerep létezik');
T::egyezik(true, $szerep && $szerep->has_cap($UI::ADMIN_JOG) && $szerep->has_cap($UI::JOG), 'megvan a rendszer-adminisztrátori és a használati joga');
T::egyezik(false, $szerep && $szerep->has_cap('manage_options'), 'de a WordPress-adminisztráció (manage_options) nincs meg');
T::egyezik(true, get_role('administrator')->has_cap($UI::ADMIN_JOG), 'az adminisztrátor szerep (fejlesztő) a rendszer-adminisztrátori jogot is megkapta');

$sdhadmin = $felh('SDH admin T70', $UI::ADMIN_SZEREP);
$kollega  = $felh('Kolléga T70', 'sdh_kollega');

/* --- 2. Fejlesztő --------------------------------------------------------------------------- */

wp_set_current_user($admin);
SDH_Muhely_Szemely::felejt();
T::egyezik(true, $UI::fejleszto_e(), 'a WP-adminisztrátor fejlesztő');
T::egyezik(true, $UI::admin_e(), 'és a rendszernek is admin');
T::egyezik(true, current_user_can($UI::ADMIN_JOG), 'a rendszer-adminisztrátori jog a fejlesztőé is');
T::egyezik(true, $UI::admin_jog_ellenoriz() === null && $UI::fejleszto_jog_ellenoriz() === null, 'a fejlesztői ellenőrzések átengedik');
T::egyezik(true, isset(SDH_Muhely_Modulok::osszes()[SDH_Muhely_Modul_Kapcsolo::KULCS]), 'a fejlesztő látja a Modulok oldalt');
T::egyezik(true, SDH_Muhely_Csapat::admin_sav(true), 'a fejlesztőnek van admin sáv');
T::egyezik('', $atiranyit(static function (): void {
    $GLOBALS['pagenow'] = 'plugins.php';
    SDH_Muhely_Csapat::admin_kizar();
}), 'a fejlesztő a WordPress-adminban marad');

/* --- 3. SDH adminisztrátor -------------------------------------------------------------------- */

wp_set_current_user($sdhadmin);
SDH_Muhely_Szemely::felejt();
T::egyezik(false, $UI::fejleszto_e(), 'az SDH adminisztrátor nem fejlesztő');
T::egyezik(true, $UI::admin_e(), 'de a rendszernek admin (Beállítások)');
T::egyezik(true, user_can($sdhadmin, $UI::JOG), 'használhatja a rendszert');
T::egyezik(false, user_can($sdhadmin, 'manage_options'), 'a WordPress beállításaihoz nincs joga');
T::egyezik('admin', SDH_Muhely_Szerepkor::szerep_slug($sdhadmin), 'a rendszer szerepe: admin');
T::egyezik(false, SDH_Muhely_Csapat::admin_sav(true), 'nincs admin sávja');
T::egyezik(SDH_Muhely_Modulok::frontend_url(), $atiranyit(static function (): void {
    $GLOBALS['pagenow'] = 'plugins.php';
    SDH_Muhely_Csapat::admin_kizar();
}), 'a WordPress-adminból a műhelybe irányítja');
T::egyezik(SDH_Muhely_Modulok::frontend_url(), SDH_Muhely_Csapat::belepes_utan('/wp-admin/', '', get_userdata($sdhadmin)), 'belépés után a műhelybe kerül');
T::egyezik('/wp-admin/', SDH_Muhely_Csapat::belepes_utan('/wp-admin/', '', get_userdata($admin)), 'a fejlesztő a kért helyre');
T::egyezik('', $atiranyit(static function (): void {
    $GLOBALS['pagenow'] = 'admin-post.php';
    SDH_Muhely_Csapat::admin_kizar();
}), 'az admin-post.php-t átengedi (a rendszer űrlapjai miatt)');

T::egyezik(false, isset(SDH_Muhely_Modulok::osszes()[SDH_Muhely_Modul_Kapcsolo::KULCS]), 'a Modulok oldal nem látszik az adminisztrátornak');
T::egyezik(null, SDH_Muhely_Modulok::egy(SDH_Muhely_Modul_Kapcsolo::KULCS), 'a Modulok oldal nem nyílik');
T::egyezik(true, isset(SDH_Muhely_Modulok::osszes()[SDH_Muhely_Beallitasok::KULCS]), 'a Beállítások látszik');

$v = t_ajax('sdh_muhely_frissites_ellenorzes');
T::egyezik(false, !empty($v['success']), 'a frissítés ellenőrzése fejlesztői jog nélkül nem megy');
$v = t_ajax('sdh_muhely_frissites_indit');
T::egyezik(false, !empty($v['success']), 'a frissítés indítása sem');

add_filter('wp_die_handler', $dobo = static fn () => static function ($uzenet): void {
    throw new T70_Die(is_string($uzenet) ? $uzenet : 'wp_die');
});
$elutasit = false;

try {
    $UI::fejleszto_jog_ellenoriz();
} catch (T70_Die | T_Ajax_Vege $e) {
    $elutasit = true;
}

remove_filter('wp_die_handler', $dobo);
T::egyezik(true, $elutasit, 'a fejlesztői ellenőrzés az adminisztrátort elutasítja (Modulok mentése)');

ob_start();
SDH_Muhely_Beallitasok::rendszer_doboz();
$nevjegy = (string) ob_get_clean();
T::egyezik(false, strpos($nevjegy, 'data-sdh-frissites') !== false, 'a Névjegyen nincs Frissítés doboz az adminisztrátornak');
T::egyezik(false, strpos($nevjegy, '>PHP<') !== false || strpos($nevjegy, '>WordPress<') !== false, 'és nincs technikai adat (WordPress, PHP verzió)');
T::tartalmaz($nevjegy, '<th>Verzió</th>', 'a rendszer verziója látszik');
T::nem_tartalmaz($nevjegy, 'Plugin', 'a Névjegy nem írja, hogy bővítmény (0.78)');

wp_set_current_user($admin);
SDH_Muhely_Szemely::felejt();
ob_start();
SDH_Muhely_Beallitasok::rendszer_doboz();
$nevjegy = (string) ob_get_clean();
T::tartalmaz($nevjegy, 'data-sdh-frissites', 'a fejlesztő látja a Frissítés dobozt');
T::tartalmaz($nevjegy, '>PHP<', 'és a technikai adatot');

/* --- 4. Kolléga ----------------------------------------------------------------------------- */

wp_set_current_user($kollega);
SDH_Muhely_Szemely::felejt();
T::egyezik(false, $UI::admin_e() || $UI::fejleszto_e(), 'a kolléga nem admin és nem fejlesztő');
T::egyezik(SDH_Muhely_Modulok::frontend_url(), $atiranyit(static function (): void {
    $GLOBALS['pagenow'] = 'plugins.php';
    SDH_Muhely_Csapat::admin_kizar();
}), 'a kollégát is kiteszi a WordPress-adminból');

/* --- 5. Közös fiók: a dolgozó nem örökli a fejlesztői jogot ------------------------------------- */

$bolt = $felh('Bolt T70', 'administrator');
$dolg = SDH_Muhely_Szemely::uj_dolgozo('Dolgozó T70', '4455');
update_option(SDH_Muhely_Szemely::OPT, ['be' => true, 'pin' => false, 'fiokok' => [$bolt]]);
wp_set_current_user($bolt);
unset($_COOKIE[SDH_Muhely_Szemely::SUTI]);
SDH_Muhely_Szemely::felejt();
T::egyezik(true, $UI::fejleszto_e(), 'választás előtt a közös fejlesztői fiók fejlesztő (mentőöv)');
SDH_Muhely_Szemely::rogzit($dolg);
T::egyezik(false, $UI::fejleszto_e(), 'a közös fiókon kiválasztott dolgozó nem fejlesztő');
T::egyezik(SDH_Muhely_Modulok::frontend_url(), $atiranyit(static function (): void {
    $GLOBALS['pagenow'] = 'plugins.php';
    SDH_Muhely_Csapat::admin_kizar();
}), 'a WordPress-adminba nem jut be a közös fiókkal sem');
SDH_Muhely_Szemely::torol();
SDH_Muhely_Szemely::felejt();
T::egyezik(true, $UI::fejleszto_e(), 'a személy váltása után újra fejlesztő');

/* --- 5b. Csapat: az „Adminisztrátor” szerep SDH adminisztrátor, a fejlesztőhöz nem nyúlhat (0.78) ------ */

$cs_ment = static function (int $id, string $nev, string $szerep, string $allapot = 'aktiv'): array {
    $f = get_userdata($id);

    return t_ajax('sdh_muhely_csapatkollega_ment', ['id' => $id, 'nev' => $nev, 'email' => $f ? $f->user_email : '', 'login' => $f ? $f->user_login : '', 'szerep' => $szerep, 'allapot' => $allapot]);
};
$cs_kollega = $felh('Csapat kolléga T70', SDH_Muhely_Csapat::SZEREP);
wp_update_user(['ID' => $cs_kollega, 'user_email' => 'cs-kollega-t70@pelda.hu']);
$cs_fejl = $felh('Másik fejlesztő T70', 'administrator');
wp_update_user(['ID' => $cs_fejl, 'user_email' => 'cs-fejl-t70@pelda.hu']);

wp_set_current_user($sdhadmin);
SDH_Muhely_Szemely::felejt();
$r = $cs_ment($cs_kollega, 'Csapat kolléga T70', 'admin');
T::egyezik(true, !empty($r['success']), 'az SDH adminisztrátor adminná teheti a kollégát');
T::egyezik([$UI::ADMIN_SZEREP], array_values(get_userdata($cs_kollega)->roles), 'a Csapat „Adminisztrátor” szerepe SDH adminisztrátor, nem WordPress-adminisztrátor');
T::egyezik(false, user_can($cs_kollega, $UI::FEJLESZTO_JOG), 'és nem lesz belőle fejlesztő');
$cs_ment($cs_fejl, 'Másik fejlesztő T70', 'kollega', 'letiltva');
T::egyezik(['administrator'], array_values(get_userdata($cs_fejl)->roles), 'az SDH adminisztrátor a fejlesztőt nem fokozhatja le');
T::egyezik('', (string) get_user_meta($cs_fejl, 'sdh_letiltva', true), 'és nem tilthatja le');
$urlap = t_ajax('sdh_muhely_csapatkollega_urlap', ['id' => $cs_fejl]);
T::tartalmaz((string) ($urlap['data']['html'] ?? $urlap['nyers']), 'a rendszer üzemeltetőjének fiókja', 'a fejlesztő űrlapján nincs szerepváltó');
T::nem_tartalmaz((string) ($urlap['data']['html'] ?? $urlap['nyers']), 'WordPress', 'a kolléga-űrlap nem írja, hogy WordPress');

wp_set_current_user($admin);
SDH_Muhely_Szemely::felejt();
$cs_ment($cs_fejl, 'Másik fejlesztő T70', 'admin');
T::egyezik(['administrator'], array_values(get_userdata($cs_fejl)->roles), 'a fejlesztő „Adminisztrátor” mentése után is fejlesztő marad');
$cs_ment($cs_kollega, 'Csapat kolléga T70', 'kollega');
T::egyezik([SDH_Muhely_Csapat::SZEREP], array_values(get_userdata($cs_kollega)->roles), 'a kolléga visszaállítható kollégára');

foreach ([$cs_kollega, $cs_fejl] as $id) {
    wp_delete_user($id);
}

/* --- 6. Bejelentkező képernyő ----------------------------------------------------------------- */

ob_start();
$UI::belepo_arculat();
$stilus = (string) ob_get_clean();
T::tartalmaz($stilus, 'sdh-belepo', 'a bejelentkezőn a WordPress-logó el van rejtve');
T::egyezik((string) get_bloginfo('name'), apply_filters('login_headertext', 'Powered by WordPress'), 'a logó szövege a rendszer neve');
T::egyezik(home_url('/'), apply_filters('login_headerurl', 'https://wordpress.org/'), 'a logó a saját oldalra mutat');
T::egyezik((string) get_bloginfo('name'), apply_filters('login_title', 'Belépés ‹ X — WordPress', 'Belépés'), 'a lapcím nem tartalmazza a WordPress szót');

// Rendrakás.
delete_option(SDH_Muhely_Szemely::OPT);
SDH_Muhely_Szemely::felejt();

foreach ([$sdhadmin, $kollega, $bolt, $dolg] as $id) {
    if ($id > 0) {
        wp_delete_user($id);
    }
}

T::vege('t70 fejlesztő / admin szintek');
