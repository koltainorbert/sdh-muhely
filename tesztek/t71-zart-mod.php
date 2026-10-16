<?php
/**
 * t71 – 0.77.0: zárt mód – a rendszer nem árulja el, hogy WordPress.
 *
 * - Minden idegen oldal (kezdőlap, bejegyzés, 404…) a műhelybe irányít; robots.txt mindent tilt; saját ikon.
 * - Saját bejelentkező és kilépés; a wp-login.php sima megnyitása oda visz.
 * - A REST kívülről csak a saját végpontokat adja; nincs „X-Redirect-By: WordPress”.
 * - 0.80.0 (H6b): a bejelentkező oldal testreszabása – szűkítés, mentés, kinézet, videó / mobil, élő előnézet.
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

/* --- 7. H6b (0.80.0): a bejelentkező oldal testreszabása ------------------------------------------- */

$Bl = 'SDH_Muhely_Belepo';
delete_option($Bl::OPT);
wp_set_current_user($admin);

// Alap: minden az Arculatból, a régi szövegekkel.
$alap = $Bl::beallitas();
T::egyezik($Bl::alapok(), $alap, 'alapállapotban a beállítás az alapértékek');
$html = $R::belepo_html('', '', '');
T::tartalmaz($html, esc_html(SDH_Muhely_Arculat::rendszer_nev()), 'alap: a címsor a rendszer neve');
T::tartalmaz($html, esc_html($Bl::ELFELEJTETT_ALAP), 'alap: az „Elfelejtett jelszó” sor a régi szöveggel');
T::tartalmaz($html, '>' . $Bl::GOMB_ALAP . '</button>', 'alap: a gomb felirata „Belépés”');
T::tartalmaz($html, 'data-hatter="alap"', 'alap: a rendszer háttere');
T::tartalmaz($html, 'data-hely="kozep"', 'alap: a doboz középen');
T::tartalmaz($html, '--belepo-logo:42px', 'alap: a logó 42 px magas');
T::tartalmaz($html, "localStorage.getItem('sdh-tema')", 'a bejelentkező követi a gép / a cég világos-sötét beállítását');
$arc_neon = (string) SDH_Muhely_Arculat::beallitas()['neon'];
T::igaz($arc_neon === '' ? strpos($html, 'data-neon=') === false : strpos($html, 'data-neon="' . $arc_neon . '"') !== false, 'alap: a neon fény a popupokéval egyezik (Arculat)');
T::nem_tartalmaz($html, 'sdh-belepo-hatter', 'alap: nincs kép- / videóréteg');
T::tartalmaz($html, 'name="_sdh_belepes"', 'a valódi bejelentkezőn van nonce');
T::tartalmaz($html, 'autofocus', 'a valódi bejelentkezőn a névmező kapja a fókuszt');

// Szűkítés: hibás, idegen, túl nagy értékek.
$sz = $Bl::szukit([
    'logo_meret' => 999, 'hatter' => 'nincs-ilyen', 'hatter_szin' => '#zzz', 'hely' => 'fent', 'fej' => 'x',
    'neon' => 'egyeni', 'neon_szin' => '', 'betutipus' => 'egyeni', 'betutipus_egyeni' => '', 'gomb_szoveg' => '   ',
    'sotetites' => 300, 'atlatszo' => -5, 'elmosas' => 99, 'atmenet_irany' => 359, 'cim' => '<script>alert(1)</script>Cím',
    'hatter_kep' => 'javascript:alert(1)', 'idegen_kulcs' => 'x', 'gomb_szin' => 'abc',
]);
T::egyezik(SDH_Muhely_Belepo::LOGO_MAX, $sz['logo_meret'], 'a logó mérete a felső korlátnál áll meg');
T::egyezik('alap', $sz['hatter'], 'ismeretlen háttér → a rendszer háttere');
T::egyezik('', $sz['hatter_szin'], 'hibás színkód → üres (alap)');
T::egyezik('#aabbcc', $sz['gomb_szin'], 'a rövid színkód (#abc) kibővül');
T::egyezik('kozep', $sz['hely'], 'ismeretlen hely → középen');
T::egyezik('sor', $sz['fej'], 'ismeretlen elrendezés → a logó a cím mellett');
T::egyezik('arculat', $sz['neon'], 'egyéni neon színkód nélkül → mint a popupoké');
T::egyezik('', $sz['betutipus'], 'egyéni betű név nélkül → mint a rendszerben');
T::egyezik($Bl::GOMB_ALAP, $sz['gomb_szoveg'], 'üres gombfelirat → „Belépés”');
T::egyezik($Bl::SOTET_MAX, $sz['sotetites'], 'a sötétítés legfeljebb 85%');
T::egyezik(0, $sz['atlatszo'], 'az átlátszóság nem negatív');
T::egyezik($Bl::ELMOSAS_MAX, $sz['elmosas'], 'az elmosás legfeljebb 40 px');
T::egyezik(0, $sz['atmenet_irany'], 'az átmenet iránya 15°-os lépésben (359° → 0°)');
T::nem_tartalmaz($sz['cim'], '<', 'a címsorban nincs HTML');
T::egyezik('', $sz['hatter_kep'], 'javascript: cím nem lehet háttérkép');
T::igaz(!isset($sz['idegen_kulcs']), 'idegen kulcs nem kerül a beállításba');
T::egyezik('alap', $Bl::szukit(['hatter' => 'video', 'hatter_video' => ''])['hatter'], 'videós háttér videó nélkül → a rendszer háttere');
T::egyezik('kep', $Bl::szukit(['hatter' => 'kep', 'hatter_kep' => 'https://pelda.example/k.jpg'])['hatter'], 'képes háttér képpel megmarad');

// A css(): idézőjelet nem lehet becsempészni a kép címébe; a színek ellenőrzöttek.
$css = $Bl::css($Bl::szukit(['hatter' => 'kep', 'hatter_kep' => 'https://pelda.example/a"b);}x{color:red.jpg']));
T::igaz(substr_count($css, '"') === 2, 'a háttérkép címéből az idézőjel kimarad (nem törhet ki a CSS-ből)');
T::nem_tartalmaz($Bl::css($Bl::szukit(['hatter' => 'kep', 'hatter_kep' => 'https://pelda.example/a.jpg?x=1&y=2'])), '&#038;', 'a <style>-ba a cím nyersen (HTML-kódolás nélkül) kerül');
$css = $Bl::css($Bl::szukit(['doboz_szin' => '#ffffff', 'gomb_szin' => '#111111', 'uveg' => '1', 'atlatszo' => 40]));
T::tartalmaz($css, '--belepo-doboz:rgba(255,255,255,0.60)', 'üveghatásnál a doboz színe átlátszó (40% → 0,60)');
T::tartalmaz($css, '--belepo-cimke:#16191d', 'világos dobozon a szöveg magától sötét');
T::tartalmaz($css, '--sdh-accent-felirat:#ffffff', 'sötét gombon a felirat fehér');
T::tartalmaz($Bl::css($Bl::szukit(['hatter' => 'atmenet', 'hatter_szin' => '#ff0000', 'hatter_szin2' => '#0000ff', 'atmenet_irany' => 90])), 'linear-gradient(90deg,#ff0000,#0000ff)', 'színátmenet a két színnel és az iránnyal');
T::tartalmaz($Bl::css($Bl::szukit(['hatter' => 'atmenet'])), 'linear-gradient(135deg,var(--sdh-accent),var(--sdh-vaszon))', 'színátmenet szín nélkül: a kiemelő színből (Arculat)');
T::nem_tartalmaz($Bl::css($Bl::alapok()), '--belepo-doboz', 'alapállapotban a doboz a felület színe (nincs felülírás)');

// Mentés a Beállításokból (a lapfül), és amit a bejelentkező ebből mutat.
$szakaszok = SDH_Muhely_Beallitasok::szakaszok();
T::igaz(isset($szakaszok['belepes']) && $szakaszok['belepes']['csoport'] === 'megjelenes', 'Beállítások › Bejelentkező oldal lapfül a „Cég és megjelenés” sorban');
$_POST['belepo'] = wp_slash([
    'logo' => 'https://pelda.example/logo.png', 'logo_meret' => '80', 'fej' => 'felul', 'cim' => 'Teszt Szerviz', 'alcim' => 'Jó munkát!',
    'elfelejtett' => '', 'gomb_szoveg' => 'Mehet', 'hatter' => 'video', 'hatter_video' => 'https://pelda.example/h.mp4',
    'hatter_kep' => 'https://pelda.example/h.jpg', 'sotetites' => '40', 'hely' => 'jobb', 'doboz_szin' => '#101820', 'uveg' => '1',
    'atlatszo' => '30', 'elmosas' => '20', 'neon' => 'cian', 'neon_ero' => 'eros', 'betutipus' => 'georgia', 'gomb_szin' => '#00aa55',
]);
$Bl::beallitas_mentes();
unset($_POST['belepo']);
$b = $Bl::beallitas();
T::egyezik('Teszt Szerviz', $b['cim'], 'mentve: címsor');
T::egyezik(true, $b['uveg'], 'mentve: üveghatás');
T::egyezik('', $b['elfelejtett'], 'mentve: az üresre állított „Elfelejtett” sor üres marad');

$html = $R::belepo_html('', '', '');
T::tartalmaz($html, '>Teszt Szerviz</h1>', 'a bejelentkezőn a saját címsor');
T::tartalmaz($html, 'sdh-belepes__alcim">Jó munkát!', 'az alcím látszik');
T::nem_tartalmaz($html, 'sdh-belepes__lab', 'üres „Elfelejtett” sor: nem látszik');
T::tartalmaz($html, '>Mehet</button>', 'a gomb saját felirata');
T::tartalmaz($html, 'src="https://pelda.example/logo.png"', 'a saját logó');
T::tartalmaz($html, '--belepo-logo:80px', 'a logó beállított magassága');
T::tartalmaz($html, 'data-fej="felul"', 'a logó a cím fölött');
T::tartalmaz($html, 'data-hely="jobb"', 'a doboz jobb oldalt');
T::tartalmaz($html, 'data-uveg-doboz="1"', 'üveghatás a dobozon');
T::tartalmaz($html, 'data-neon="cian" data-neon-ero="eros"', 'saját neon szín és fényerő');
T::tartalmaz($html, 'data-src="https://pelda.example/h.mp4"', 'a háttérvideó');
T::igaz(preg_match('/<video[^>]*\ssrc=/', $html) === 0, 'a videó címe nem rögtön src: mobilon nem töltődik le');
T::tartalmaz($html, 'poster="https://pelda.example/h.jpg"', 'a videó helyén (és betöltésig) a háttérkép');
T::tartalmaz($html, '(min-width: ' . ($Bl::MOBIL_HATAR + 1) . 'px) and (prefers-reduced-motion: no-preference)', 'a videó csak széles képernyőn és mozgás-tiltás nélkül indul');
T::tartalmaz($html, '--belepo-sotet:0.40', 'a kép / videó sötétítése');
T::tartalmaz($html, 'Georgia', 'a saját betűtípus');
T::tartalmaz($html, '--sdh-accent:#00aa55', 'a gomb saját színe');
T::nem_tartalmaz($html, 'WordPress', 'testreszabva sincs „WordPress” a bejelentkezőn');

// Élő előnézet: a valódi oldal, de nem küldhető be és nem lop fókuszt.
$elo = $R::belepo_html('', '', '', $b, true);
T::nem_tartalmaz($elo, '_sdh_belepes', 'előnézetben nincs nonce');
T::nem_tartalmaz($elo, 'autofocus', 'előnézetben nincs autofocus (a Beállítások mezője marad fókuszban)');
T::tartalmaz($elo, 'type="button" class="sdh-gomb sdh-gomb--elsodleges sdh-belepes__gomb"', 'előnézetben a gomb nem küld be');

$r = t_ajax('sdh_muhely_belepo_elonezet', ['belepo' => ['cim' => 'Előnézeti cím', 'hatter' => 'szin', 'hatter_szin' => '#334455']], 'sdh_nonce', 'sdh_muhely_beallitasok');
T::igaz(!empty($r['success']), 'AJAX-előnézet: sikeres');
T::tartalmaz((string) ($r['data']['html'] ?? ''), '>Előnézeti cím</h1>', 'AJAX-előnézet: a még nem mentett címsor');
T::tartalmaz((string) ($r['data']['html'] ?? ''), '--belepo-hatter:#334455', 'AJAX-előnézet: a még nem mentett háttérszín');
T::egyezik('Teszt Szerviz', $Bl::beallitas()['cim'], 'az előnézet nem ment');

$kolleg2 = wp_insert_user(['user_login' => 'kolleg_t71b', 'user_pass' => 'Jelszo-T71b-ok!', 'role' => 'sdh_kollega', 'display_name' => 'Kolléga T71b']);
wp_set_current_user((int) $kolleg2);
$r = t_ajax('sdh_muhely_belepo_elonezet', ['belepo' => ['cim' => 'x']], 'sdh_nonce', 'sdh_muhely_beallitasok');
T::igaz(empty($r['success']), 'AJAX-előnézet: kollégának nem jár');
wp_set_current_user($admin);
wp_delete_user((int) $kolleg2);

// A lapfül: mezők és előnézet.
ob_start();
$Bl::beallitas_doboz();
$doboz = (string) ob_get_clean();
foreach (['logo', 'logo_meret', 'cim', 'alcim', 'elfelejtett', 'gomb_szoveg', 'hatter', 'hatter_szin', 'hatter_kep', 'hatter_video', 'doboz_szin', 'uveg', 'neon', 'betutipus', 'gomb_szin', 'hely'] as $mezo) {
    T::tartalmaz($doboz, 'name="belepo[' . $mezo . ']"', 'a lapfülön állítható: ' . $mezo);
}
T::tartalmaz($doboz, 'data-sdh-belepo-elonezet', 'a lapfülön élő előnézet');
T::tartalmaz($doboz, 'srcdoc=', 'az előnézet JavaScript nélkül is a mentett állapotot mutatja');
T::tartalmaz($doboz, 'data-sdh-belepo-mod="mobil"', 'az előnézet mobilnézetre váltható');
T::nem_tartalmaz(wp_strip_all_tags($doboz), 'WordPress', 'a lapfül szövege nem árulkodik');

// A stílus: a doboz helye, mobilon a videó helyett kép.
$app_css = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.css');
T::tartalmaz($app_css, 'body.sdh-belepo[data-hely="jobb"]', 'a stíluslapban a doboz helye');
T::igaz(preg_match('/@media \(max-width: 700px\), \(prefers-reduced-motion: reduce\)\s*\{\s*\.sdh-belepo-hatter__video\s*\{\s*display: none/', $app_css) === 1, 'mobilon (és mozgás-tiltásnál) a videó rejtve');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'assets/beallitasok.js'), 'sdh_muhely_belepo_elonezet', 'a Beállítások szkriptje frissíti az előnézetet');

delete_option($Bl::OPT);
T::egyezik($Bl::alapok(), $Bl::beallitas(), 'rendrakás: vissza az alapra');

T::vege('t71 zárt mód');
