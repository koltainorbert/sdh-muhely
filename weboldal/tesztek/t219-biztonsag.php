<?php
/** t219 – biztonság: CSP kérésenkénti nonce (fejléc + script-tag), biztonsági fejlécek, gyorsítótár-kizárás, 429, Turnstile, mézesbödön, TOTP + tartalékkódok. */
require __DIR__ . '/_t210-seged.php';

// --- CSP szöveg és nonce ------------------------------------------------------------------------
SDH_Sales::b_ment(['biztonsag.csp' => 'enforce']);
$n1  = SDH_Sales_Biztonsag::nonce();
$csp = SDH_Sales_Biztonsag::csp();
T::tartalmaz($csp, "script-src 'self' 'nonce-" . $n1 . "'", 'CSP: script-src a nonce-szal');
T::tartalmaz($csp, "object-src 'none'", 'CSP: object-src none');
T::tartalmaz($csp, "frame-ancestors 'self'", 'CSP: frame-ancestors');
T::nem_tartalmaz($csp, "'unsafe-eval'", 'CSP: nincs unsafe-eval');
T::nem_tartalmaz($csp, "script-src 'self' 'unsafe-inline'", 'CSP: nincs unsafe-inline szkript');
add_filter('sdh_sales_csp', static function (array $i): array { $i['connect-src'][] = 'https://api.example.com'; $i['rossz;'] = ['x;y']; return $i; });
T::tartalmaz(SDH_Sales_Biztonsag::csp(), 'https://api.example.com', 'sdh_sales_csp szűrő: forrás hozzáadható');
T::nem_tartalmaz(SDH_Sales_Biztonsag::csp(), 'x;y', 'szűrő: befecskendezés kiszűrve');
$h = SDH_Sales_Biztonsag::fejlec_lista();
T::igaz(isset($h['Content-Security-Policy']) && !isset($h['Content-Security-Policy-Report-Only']), 'enforce mód: CSP fejléc');
foreach (['X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'strict-origin-when-cross-origin'] as $k => $e) {
    T::egyezik($e, $h[$k] ?? '', 'fejléc: ' . $k);
}
T::tartalmaz($h['Permissions-Policy'] ?? '', 'camera=()', 'Permissions-Policy');
SDH_Sales::b_ment(['biztonsag.csp' => 'report']);
$h = SDH_Sales_Biztonsag::fejlec_lista();
T::igaz(isset($h['Content-Security-Policy-Report-Only']) && !isset($h['Content-Security-Policy']), 'report-only mód');
SDH_Sales::b_ment(['biztonsag.csp' => 'ki']);
T::igaz(!isset(SDH_Sales_Biztonsag::fejlec_lista()['Content-Security-Policy-Report-Only']), 'kikapcsolt CSP');
SDH_Sales::b_ment(['biztonsag.csp' => 'enforce']);

// --- Valódi HTTP-kérés: a fejléc nonce-a = a script-tagek nonce-a, kérésenként más -----------------
$E    = dirname((string) getenv('SDH_WP_LOAD'));
$gyok = dirname($E);
$port = 18000 + getmypid() % 1000;
$regi = [get_option('home'), get_option('siteurl')];
update_option('home', 'http://127.0.0.1:' . $port . '/wp');
update_option('siteurl', 'http://127.0.0.1:' . $port . '/wp');
$oldal = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'T219 kosár', 'post_name' => 't219-kosar', 'post_content' => '[t219]']);
update_option('t219_oldal', $oldal);
$mu = WP_CONTENT_DIR . '/mu-plugins';
wp_mkdir_p($mu);
file_put_contents($mu . '/t219.php', "<?php\nadd_action('wp', function(){ if (is_page((int) get_option('t219_oldal'))) { SDH_Sales_Front::igeny('kalkulator'); } });\nadd_action('wp_footer', function(){ wp_print_inline_script_tag('window.t219=1;'); }, 1);\n");
$srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $gyok], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $cso);
usleep(700000);
$lek = static function (string $url): array {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    $v = (string) curl_exec($c);
    $hm = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $fej = substr($v, 0, $hm);
    $bl  = preg_split("/\r\n\r\n(?=HTTP\/)/", trim($fej)) ?: [$fej];
    return [(string) end($bl), substr($v, $hm)];
};
[$f1, $t1] = $lek(get_permalink($oldal));
[$f2] = $lek(get_permalink($oldal));
proc_terminate($srv);
proc_close($srv);
unlink($mu . '/t219.php');
wp_delete_post($oldal, true);
update_option('home', $regi[0]);
update_option('siteurl', $regi[1]);

T::igaz(preg_match("/Content-Security-Policy: [^\r\n]*'nonce-([A-Za-z0-9_-]{20,})'/", $f1, $m1) === 1, 'HTTP: CSP fejléc nonce-szal (' . mb_substr($f1, 0, 120) . ')');
preg_match("/Content-Security-Policy: [^\r\n]*'nonce-([A-Za-z0-9_-]{20,})'/", $f2, $m2);
T::igaz(isset($m1[1], $m2[1]) && $m1[1] !== $m2[1], 'HTTP: kérésenként új nonce');
T::tartalmaz($t1, 'nonce="' . ($m1[1] ?? '?') . '"', 'HTTP: a script-tagen ugyanaz a nonce');
// Adatblokk (application/ld+json, application/json) nem fut, a CSP nem vonatkozik rá.
preg_match_all('/<script(?![^>]*nonce=)(?![^>]*type=["\']application\/(ld\+)?json)[^>]*>/i', $t1, $nn);
T::igaz($nn[0] === [], 'HTTP: minden script-tagen van nonce (' . implode(' ', array_slice($nn[0], 0, 3)) . ')');
T::tartalmaz($f1, 'X-Content-Type-Options: nosniff', 'HTTP: nosniff');

// --- Gyorsítótár-kizárás ------------------------------------------------------------------------
T::igaz(in_array('/kosar/', SDH_Sales_Biztonsag::nem_cache_utak(), true) && in_array('/fiok/', SDH_Sales_Biztonsag::nem_cache_utak(), true), 'nem gyorsítótárazható utak');
$rk = SDH_Sales_Biztonsag::rocket_kizar([]);
T::igaz(in_array('/penztar/(.*)', $rk, true), 'WP Rocket kizárás: pénztár');
T::igaz((bool) preg_grep('~/' . preg_quote(SDH_Sales_Rest::NS, '~') . '/~', $rk), 'WP Rocket kizárás: REST');
$_SERVER['REQUEST_URI'] = wp_parse_url(home_url('/kosar/'), PHP_URL_PATH);
SDH_Sales_Biztonsag::cache_kizaras();
T::igaz(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE, 'DONOTCACHEPAGE a kosár oldalon');
$rq = new WP_REST_Request('POST', '/' . SDH_Sales_Rest::NS . '/ar');
$v  = apply_filters('rest_post_dispatch', rest_do_request($rq), rest_get_server(), $rq);
T::egyezik('private, no-store, max-age=0', $v->get_headers()['Cache-Control'] ?? '', 'REST: no-store');

// --- 429 --------------------------------------------------------------------------------------
t_korlat_ki(false);
$st = [];
for ($i = 0; $i < 12; $i++) { $st[] = t_latogato(static fn () => t_rest('POST', '/lead', ['tipus' => 'kapcsolat']))->get_status(); }
T::egyezik(6, count(array_filter($st, static fn ($s) => $s !== 429)), '/lead: 6 kérés fér bele');
T::egyezik(429, end($st), '/lead: utána 429');
T::tartalmaz((string) t_latogato(static fn () => t_rest('POST', '/lead', []))->get_data()['message'], 'Túl sok kérés', '429 magyar üzenet');
t_korlat_ki();

// --- Robotszűrés --------------------------------------------------------------------------------
$q = static function (array $p): WP_REST_Request { $r = new WP_REST_Request('POST', '/x'); foreach ($p as $k => $v) { $r->set_param($k, $v); } return $r; };
T::igaz(SDH_Sales_Biztonsag::ellenoriz($q(t_vedelem())) === true, 'jó időbélyeg: átmegy');
T::igaz(is_wp_error(SDH_Sales_Biztonsag::ellenoriz($q(['_t' => SDH_Sales_Biztonsag::urlap_token(time())]))), 'túl gyors kitöltés: elutasítva');
T::igaz(is_wp_error(SDH_Sales_Biztonsag::ellenoriz($q(['_t' => (time() - 10) . '.0123456789abcdef0123']))), 'hamis időbélyeg: elutasítva');
T::igaz(is_wp_error(SDH_Sales_Biztonsag::ellenoriz($q(array_merge(t_vedelem(), ['weboldal_cim' => 'x'])))), 'mézesbödön: elutasítva');
T::igaz(is_wp_error(SDH_Sales_Biztonsag::ellenoriz($q([]))), 'mezők nélkül: elutasítva');

SDH_Sales::b_ment(['turnstile.kulcs' => '1x00000000000000000000AA']);
SDH_Sales::titkos_ment('turnstile_titok', '1x0000000000000000000000000000000AA');
$ts = null;
t_http(static function (string $url, array $a) use (&$ts): ?array {
    if (strpos($url, 'challenges.cloudflare.com/turnstile/v0/siteverify') === false) { return null; }
    $GLOBALS['t_ts'] = $a['body'];
    return $ts === 'le' ? null : t_valasz(200, (string) wp_json_encode(['success' => $ts === 'ok', 'error-codes' => $ts === 'ok' ? [] : ['invalid-input-response']]));
});
$ts = 'ok';
T::igaz(SDH_Sales_Biztonsag::ellenoriz($q(['turnstile' => 'XXXX.DUMMY.TOKEN'])) === true, 'Turnstile: siker');
T::egyezik('1x0000000000000000000000000000000AA', $GLOBALS['t_ts']['secret'] ?? '', 'Turnstile: titok a szerveroldali kérésben');
$ts = 'rossz';
T::igaz(is_wp_error(SDH_Sales_Biztonsag::ellenoriz($q(['turnstile' => 'XXXX']))), 'Turnstile: elutasítás');
T::igaz(is_wp_error(SDH_Sales_Biztonsag::ellenoriz($q([]))), 'Turnstile: token nélkül');
$ts = 'le';
T::igaz(SDH_Sales_Biztonsag::ellenoriz($q(['turnstile' => 'XXXX'] + t_vedelem())) === true, 'Turnstile nem érhető el: a tartalék dönt');
T::nem_tartalmaz(wp_json_encode(SDH_Sales_Biztonsag::front_adat()), '1x0000000000000000000000000000000AA', 'Turnstile-titok nincs a front adatában');
SDH_Sales::b_ment(['turnstile.kulcs' => '']);

// --- TOTP (RFC 6238 B függelék: SHA1, titok „12345678901234567890”) ------------------------------
$b32 = SDH_Sales_Biztonsag::base32('12345678901234567890');
T::egyezik('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $b32, 'base32');
T::egyezik('12345678901234567890', SDH_Sales_Biztonsag::base32_vissza($b32), 'base32 vissza');
foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $ido => $kod) {
    T::egyezik($kod, SDH_Sales_Biztonsag::totp($b32, intdiv($ido, 30)), 'RFC 6238 vektor @' . $ido);
}
T::egyezik(intdiv(1234567890, 30), SDH_Sales_Biztonsag::totp_illik($b32, '005924', 0, 1234567890), 'illeszkedés');
T::egyezik(intdiv(1234567890, 30), SDH_Sales_Biztonsag::totp_illik($b32, '005924', 0, 1234567890 + 30), '±1 lépés');
T::egyezik(null, SDH_Sales_Biztonsag::totp_illik($b32, '005924', 0, 1234567890 + 90), '3 lépés múlva már nem');
T::egyezik(null, SDH_Sales_Biztonsag::totp_illik($b32, '005924', intdiv(1234567890, 30), 1234567890), 'már használt lépés nem jó');

$uid = get_current_user_id();
$tit = SDH_Sales_Biztonsag::base32(random_bytes(20));
$kodok = SDH_Sales_Biztonsag::bekapcsol($uid, $tit);
T::egyezik(10, count($kodok), '10 tartalékkód');
T::igaz(SDH_Sales_Biztonsag::aktiv($uid), 'bekapcsolva');
T::nem_tartalmaz(wp_json_encode(get_user_meta($uid)), $tit, 'a TOTP-titok nem nyíltan a meta-ban');
T::nem_tartalmaz(wp_json_encode(get_user_meta($uid)), str_replace('-', '', $kodok[0]), 'a tartalékkód csak lenyomatként');
T::egyezik('', SDH_Sales_Biztonsag::kod_ellenoriz($uid, SDH_Sales_Biztonsag::totp($tit, intdiv(time(), 30))), 'aktuális kód jó');
T::igaz(SDH_Sales_Biztonsag::kod_ellenoriz($uid, SDH_Sales_Biztonsag::totp($tit, intdiv(time(), 30))) !== '', 'ugyanaz a kód másodszor nem');
T::egyezik('', SDH_Sales_Biztonsag::kod_ellenoriz($uid, strtolower($kodok[3])), 'tartalékkód jó (kisbetűvel is)');
T::igaz(SDH_Sales_Biztonsag::kod_ellenoriz($uid, $kodok[3]) !== '', 'tartalékkód egyszer');
T::egyezik(9, count((array) get_user_meta($uid, SDH_Sales_Biztonsag::META_TARTALEK, true)), '9 tartalékkód maradt');
T::tartalmaz(SDH_Sales_Biztonsag::kod_ellenoriz($uid, '000000'), 'Hibás', 'rossz kód magyar hibával');

// Belépési kapu
$_POST = ['log' => 'admin', 'pwd' => 'x'];
$u = wp_get_current_user();
T::igaz(is_wp_error(SDH_Sales_Biztonsag::login_kapu($u)), 'belépés kód nélkül: elutasítva');
$_POST['kod_2fa'] = SDH_Sales_Biztonsag::totp($tit, intdiv(time(), 30) + 1);
T::igaz(SDH_Sales_Biztonsag::login_kapu($u) instanceof WP_User, 'belépés jó kóddal');
$_POST = [];
SDH_Sales::b_ment(['biztonsag.2fa_admin_kotelezo' => true]);
T::igaz(SDH_Sales_Biztonsag::kotelezo($u), 'kötelező adminnak (beállítás)');
SDH_Sales_Biztonsag::kikapcsol($uid);
T::igaz(!SDH_Sales_Biztonsag::aktiv($uid), 'kikapcsolva');

T::vege('t219 biztonság');
