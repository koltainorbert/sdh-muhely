<?php
/**
 * t71 – 0.77.0: zárt mód – a rendszer nem árulja el, hogy WordPress.
 *
 * - Minden idegen oldal (kezdőlap, bejegyzés, 404…) a műhelybe irányít; robots.txt mindent tilt; saját ikon.
 * - Saját bejelentkező és kilépés; a wp-login.php sima megnyitása oda visz.
 * - A REST kívülről csak a saját végpontokat adja; nincs „X-Redirect-By: WordPress”.
 */

require __DIR__ . '/_keret.php';

class T71_Atiranyit extends Exception
{
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$R     = 'SDH_Muhely_Rejtes';
$admin = get_current_user_id();

foreach (get_users(['search' => '*T71*', 'search_columns' => ['display_name']]) as $maradek) {
    wp_delete_user((int) $maradek->ID);
}

add_filter('send_auth_cookies', '__return_false');

$atiranyit = static function (callable $fv): string {
    $szuro = static function ($hely): void {
        throw new T71_Atiranyit((string) $hely);
    };
    add_filter('wp_redirect', $szuro);
    $cel = '';

    try {
        $fv();
    } catch (T71_Atiranyit $e) {
        $cel = $e->getMessage();
    }

    remove_filter('wp_redirect', $szuro);

    return $cel;
};

/* --- 1. Alap -------------------------------------------------------------------------------- */

T::egyezik(true, $R::zart(), 'a zárt mód alapból be van kapcsolva');
add_filter('sdh_muhely_zart_mod', '__return_false');
T::egyezik(false, $R::zart(), 'szűrővel (vagy a wp-config állandóval) kikapcsolható');
remove_filter('sdh_muhely_zart_mod', '__return_false');

T::egyezik(false, apply_filters('x_redirect_by', 'WordPress', 302, '/'), 'az átirányítás nem küld „X-Redirect-By: WordPress” fejlécet');

/* --- 2. Idegen oldalak ------------------------------------------------------------------------- */

global $wp_query;
T::egyezik('atiranyit', $R::dontes(), 'a kezdőlap / bejegyzés / 404 a műhelybe irányít');
T::egyezik(SDH_Muhely_Modulok::frontend_url(), $atiranyit([$R, 'kapu']), 'az átirányítás célja a műhely');

$wp_query->is_robots = true;
T::egyezik('robots', $R::dontes(), 'a robots.txt saját');
$wp_query->is_robots = false;
T::tartalmaz($R::robots(), 'Disallow: /', 'a robots.txt mindent tilt (a wp-admin említése nélkül)');
T::nem_tartalmaz($R::robots(), 'wp-', 'a robots.txt nem árulkodik');

$wp_query->is_favicon = true;
T::egyezik('ikon', $R::dontes(), 'a /favicon.ico saját ikont kap');
$wp_query->is_favicon = false;

// Szép URL nélkül a címből ismeri fel.
$_SERVER['REQUEST_URI'] = '/robots.txt';
T::egyezik('robots', $R::dontes(), 'a /robots.txt szép URL nélkül is saját');
$_SERVER['REQUEST_URI'] = '/favicon.ico';
T::egyezik('ikon', $R::dontes(), 'a /favicon.ico szép URL nélkül is saját ikon');
$_SERVER['REQUEST_URI'] = '/';

// Az árulkodó szövegfájlok (readme.html, license.txt) a gyökérből törlődnek.
foreach ($R::arulkodo_fajlok_listaja() as $f) {
    if (!is_file($f)) {
        file_put_contents($f, 'WordPress');
    }
}
T::egyezik(2, $R::arulkodo_fajlok_most(), 'a readme.html és a license.txt törlődik');
T::egyezik(false, is_file(ABSPATH . 'readme.html') || is_file(ABSPATH . 'license.txt'), 'nincsenek a gyökérben');

$svg = $R::ikon_svg();
T::tartalmaz($svg, '<svg', 'az ikon SVG');
T::tartalmaz($svg, esc_html(mb_strtoupper(mb_substr(SDH_Muhely_Arculat::beallitas()['jel'] ?: 'M', 0, 3))), 'az ikonon a rendszer jele áll');
T::tartalmaz($R::ikon_url(), SDH_Muhely_Arculat::beallitas()['logo'] !== '' ? SDH_Muhely_Arculat::beallitas()['logo'] : $R::IKON_VAR, 'az ikon címe a logó, vagy a rajzolt jel');

/* --- 3. REST ------------------------------------------------------------------------------------ */

wp_set_current_user(0);
$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/users';
$e = $R::rest_kapu(null);
T::igaz(is_wp_error($e) && (int) ($e->get_error_data()['status'] ?? 0) === 404, 'kívülről a WordPress REST-végpontjai 404-et adnak');
$GLOBALS['wp']->query_vars['rest_route'] = '/';
T::igaz(is_wp_error($R::rest_kapu(null)), 'a REST-index sem látszik');
$GLOBALS['wp']->query_vars['rest_route'] = '/sdh-muhely/v1/sms/bejovo';
T::egyezik(null, $R::rest_kapu(null), 'a saját végpont (SMS-átjáró) elérhető');
wp_set_current_user($admin);
$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/users';
T::egyezik(null, $R::rest_kapu(null), 'a fejlesztőnek minden REST-végpont elérhető');
unset($GLOBALS['wp']->query_vars['rest_route']);

/* --- 4. Bejelentkezés és kilépés címe ------------------------------------------------------------ */

$be = wp_login_url('http://localhost/?muhely=penztar');
T::tartalmaz($be, 'muhely=belepes', 'a bejelentkezés címe a saját oldal');
T::nem_tartalmaz($be, 'wp-login', 'a bejelentkezés címében nincs wp-login');
T::tartalmaz($be, 'redirect_to=', 'a visszatérési cím megmarad');
$ki = wp_logout_url();
T::tartalmaz($ki, 'muhely=kilepes', 'a kilépés címe a saját oldal');
T::tartalmaz($ki, '_wpnonce=', 'a kilépés védett (nonce)');
T::nem_tartalmaz($ki, 'wp-login', 'a kilépés címében nincs wp-login');

// A wp-login.php sima megnyitása a saját bejelentkezőre visz; a többi művelete marad.
$regi = $_REQUEST;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_REQUEST = [];
T::tartalmaz($atiranyit([$R, 'wp_login_kapu']), 'muhely=belepes', 'a wp-login.php megnyitása a saját bejelentkezőre visz');
$_REQUEST = ['action' => 'lostpassword'];
T::egyezik('', $atiranyit([$R, 'wp_login_kapu']), 'a jelszó-visszaállítás (WordPress-művelet) marad');
$_REQUEST = ['action' => 'logout'];
T::egyezik('', $atiranyit([$R, 'wp_login_kapu']), 'a WordPress kilépés-művelete marad');
$_REQUEST = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
T::egyezik('', $atiranyit([$R, 'wp_login_kapu']), 'a beküldött wp-login űrlapot nem bántja');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_REQUEST = $regi;

/* --- 5. A bejelentkező oldal ----------------------------------------------------------------------- */

wp_set_current_user(0);
$html = $R::belepo_html('', '', '');
T::tartalmaz($html, 'name="log"', 'a bejelentkezőn van név mező');
T::tartalmaz($html, 'name="pwd"', 'és jelszó mező');
T::tartalmaz($html, SDH_Muhely_Arculat::rendszer_nev(), 'a rendszer neve a címben');
T::tartalmaz($html, 'rel="icon"', 'saját ikon');
T::nem_tartalmaz($html, 'WordPress', 'a bejelentkezőn sehol nincs „WordPress”');
T::nem_tartalmaz($html, 'wp-login', 'és nincs wp-login hivatkozás');
T::nem_tartalmaz($html, 'lostpassword', 'és nincs WordPress-es jelszó-visszaállító link');

$kolleg = wp_insert_user(['user_login' => 'kolleg_t71', 'user_pass' => 'Jelszo-T71-ok!', 'role' => 'sdh_kollega', 'display_name' => 'Kolléga T71']);
T::igaz(is_int($kolleg), 'a próba-kolléga létrejött');

$r = $R::belepes_feldolgoz(['log' => 'kolleg_t71', 'pwd' => 'Jelszo-T71-ok!', '_sdh_belepes' => 'rossz']);
T::tartalmaz((string) ($r['hiba'] ?? ''), 'Lejárt', 'hibás nonce: elutasít');

$nonce = wp_create_nonce('sdh_muhely_belepes');
$r = $R::belepes_feldolgoz(['log' => 'kolleg_t71', 'pwd' => 'rossz', '_sdh_belepes' => $nonce]);
T::egyezik('Hibás felhasználónév vagy jelszó.', $r['hiba'] ?? '', 'rossz jelszó: általános hiba (WordPress-szöveg nélkül)');
T::egyezik(0, get_current_user_id(), 'rossz jelszóval nincs belépés');

$r = $R::belepes_feldolgoz(['log' => 'kolleg_t71', 'pwd' => '', '_sdh_belepes' => $nonce]);
T::tartalmaz((string) ($r['hiba'] ?? ''), 'Add meg', 'üres jelszó: kéri');

$r = $R::belepes_feldolgoz(['log' => 'kolleg_t71', 'pwd' => 'Jelszo-T71-ok!', '_sdh_belepes' => $nonce, 'redirect_to' => '']);
T::egyezik(SDH_Muhely_Modulok::frontend_url(), $r['cel'] ?? '', 'jó jelszó: a műhelybe lép');
T::egyezik((int) $kolleg, get_current_user_id(), 'be van jelentkezve');

wp_set_current_user(0);
$nonce = wp_create_nonce('sdh_muhely_belepes');
$r = $R::belepes_feldolgoz(['log' => 'kolleg_t71', 'pwd' => 'Jelszo-T71-ok!', '_sdh_belepes' => $nonce, 'redirect_to' => 'https://idegen.example/']);
T::egyezik(SDH_Muhely_Modulok::frontend_url(), $r['cel'] ?? '', 'idegen visszatérési címre nem visz');

$dolg = SDH_Muhely_Szemely::uj_dolgozo('Dolgozó T71', '1234');
wp_set_password('Dolgozo-T71-ok!', $dolg);
wp_set_current_user(0);
$nonce = wp_create_nonce('sdh_muhely_belepes');
$r = $R::belepes_feldolgoz(['log' => get_userdata($dolg)->user_login, 'pwd' => 'Dolgozo-T71-ok!', '_sdh_belepes' => $nonce]);
T::tartalmaz((string) ($r['hiba'] ?? ''), 'közös fiókon', 'a dolgozó nevével itt sem lehet belépni');

/* --- 6. A műhely oldalán is saját ikon ------------------------------------------------------------- */

wp_set_current_user($admin);
SDH_Muhely_Modulok::kontextus_beallit('frontend');
ob_start();
SDH_Muhely_Frontend::keret('Teszt', 'attekintes', static function (): void {
    echo '<p>tartalom</p>';
});
$oldal = (string) ob_get_clean();
T::tartalmaz($oldal, 'rel="icon"', 'a műhely oldalain saját ikon (nem a WordPress-logó)');

// Rendrakás.
foreach ([$kolleg, $dolg] as $id) {
    if (is_int($id) && $id > 0) {
        wp_delete_user($id);
    }
}

// 0.78.1: a bejelentkező doboz nem szorulhat a műhely rácsának keskeny oldalsáv-oszlopába.
$app_css = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.css');
T::igaz(preg_match('/body\.sdh-app\.sdh-app--uzenet\s*\{[^}]*display:\s*flex[^}]*grid-template-columns:\s*none/s', $app_css) === 1, 'a belépő / üzenet oldal felülírja a body.sdh-app rácsát (középre zárt doboz)');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'assets/admin.css'), '.sdh-mezo input[type="password"]', 'a jelszómező ugyanúgy néz ki, mint a többi mező');

T::vege('t71 zárt mód');
