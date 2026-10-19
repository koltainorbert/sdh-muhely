<?php
/**
 * t254 – e-mail: mind a 17 sablon (magyar, {termeknev}, nincs beégetett cégadat), tokencsere és escapelés (HTML, URL,
 * tárgy-befecskendezés), feltételes rész, ismeretlen token, szöveges rész, arculat-színek, feladó és válaszcím,
 * a tartalom-tár felülírja / kikapcsolja a sablont, szűrő elfogja, wp_mail-útvonal, napló maszkolt címmel;
 * letöltő link a levélből: 3 letöltés, utána elutasítva; 14 nap.
 */
require __DIR__ . '/_t25-seged.php';

global $wpdb;

$E = 'SDH_Sales_Email';

/* --- A sablonok ---------------------------------------------------------------------------------------------------- */

$kell = ['dijbekero', 'fizetes_megerkezett', 'udvozlo', 'idopont_ajanlat', 'foglalas_visszaigazolas', 'foglalas_emlekezteto', 'foglalas_lemondas', 'lejarat_emlekezteto', 'megujitas_kesz', 'bovites_kesz', 'fizetes_sikertelen', 'dijbekero_emlekezteto', 'fiok_belepes', 'egyedi_visszaigazolas', 'lead_visszaigazolas', 'admin_ertesites', 'lanc_hiba_admin'];
$van  = $E::sablonok();
T::egyezik([], array_values(array_diff($kell, array_keys($van))), 'mind a 17 sablon megvan');

$tiltott = ['SDH Szerviz', 'sdh.hu', 'Veszprém', 'Koltai', 'InfoStudio', 'WordPress', 'plugin', 'bővítmény', 'GitHub'];
$hibas   = [];
foreach ($kell as $k) {
    $a = (array) SDH_Sales::adat('email/' . $k . '.json');
    $s = (string) wp_json_encode($a, JSON_UNESCAPED_UNICODE);

    if (empty($a['targy']) || empty($a['html']) || empty($a['cim'])) {
        $hibas[] = $k . ': hiányos';
    }

    foreach ($tiltott as $t) {
        if (stripos($s, $t) !== false) {
            $hibas[] = $k . ': ' . $t;
        }
    }

    if (preg_match('~SDH|Munkora~', $s) === 1) {
        $hibas[] = $k . ': beégetett terméknév';
    }

    $l = $E::osszeallit($k, ['nev' => 'Teszt']);

    if ($l === null || preg_match('/\{[a-z0-9_#\/]+\}/', $l['html'] . $l['targy']) === 1) {
        $hibas[] = $k . ': maradék token';
    }

    if (substr_count($a['html'], '{#') !== substr_count($a['html'], '{/')) {
        $hibas[] = $k . ': feltételes rész párosítatlan';
    }
}
T::egyezik([], $hibas, 'sablonok: teljesek, nincs beégetett cég / termék, nincs maradék token');
foreach (['udvozlo', 'lejarat_emlekezteto', 'fiok_belepes', 'megujitas_kesz'] as $k) {
    T::tartalmaz((string) SDH_Sales::adat('email/' . $k . '.json')['targy'] . (string) SDH_Sales::adat('email/' . $k . '.json')['html'], '{termeknev}', $k . ': {termeknev} token');
}

/* --- Tokenek, escapelés -------------------------------------------------------------------------------------------- */

SDH_Sales::b_ment(['termeknev' => 'Próba CRM']);
$l = $E::osszeallit('udvozlo', [
    '{nev}'        => '<script>alert(1)</script> Béla & Társa',
    'licenc_kulcs' => 'SDH-AAAA-BBBB-CCCC-DDDD',
    'letoltes_url' => 'javascript:alert(1)',
    'rendeles'     => "R-1\r\nBcc: tamado@pelda.hu",
    'lejar'        => '',
    'csomag'       => 'Pro',
]);
T::nem_tartalmaz($l['html'], '<script>', 'HTML-token escapelve');
T::tartalmaz($l['html'], '&lt;script&gt;alert(1)&lt;/script&gt; Béla &amp; Társa', 'a név escapelve, olvashatóan');
T::nem_tartalmaz($l['html'], 'javascript:', 'veszélyes URL kiszűrve');
T::nem_tartalmaz($l['targy'], "\n", 'tárgy: nincs sortörés (fejléc-befecskendezés ellen)');
T::tartalmaz($l['targy'], 'Próba CRM', 'tárgy: {termeknev} a beállításból');
T::tartalmaz($l['html'], 'Próba CRM', 'törzs: {termeknev}');
T::nem_tartalmaz($l['html'], 'Érvényes', 'feltételes rész: üres lejárat → a sor kimarad');
T::tartalmaz($l['html'], 'Csomag', 'feltételes rész: kitöltött csomag → a sor marad');
T::tartalmaz($l['szoveg'], 'SDH-AAAA-BBBB-CCCC-DDDD', 'szöveges rész: kulcs');
T::igaz(preg_match('/<(p|a|table|td|div)[\s>]/i', $l['szoveg']) !== 1, 'szöveges rész: nincs HTML-címke');
T::tartalmaz($l['html'], '<!doctype html>', 'teljes HTML-dokumentum');
T::tartalmaz($l['html'], 'max-width:600px', 'reszponzív (max 600 px)');
T::tartalmaz($l['html'], 'style="display:inline-block;background:', 'gomb inline stílussal');
T::tartalmaz($l['html'], 'Forgalmazó Teszt Kft.', 'lábléc: cégnév a beállításból');
SDH_Sales::b_ment(['termeknev' => 'Munkora']);

$l = $E::osszeallit('lead_visszaigazolas', ['nev' => 'X', 'uzenet' => "első sor\nmásodik <b>sor</b>", 'ismeretlen' => 'y']);
T::igaz(strpos($l['html'], 'első sor<br>') !== false && strpos($l['html'], 'második &lt;b&gt;sor&lt;/b&gt;') !== false, 'többsoros érték: <br>, escapelve');
$l = $E::osszeallit('udvozlo', ['nev' => 'X', 'tetelek_html' => '<p>ok</p>']);
T::nem_tartalmaz($l['html'], '{', 'ismeretlen / hiányzó token: üres');

$sz = $E::szinek();
T::igaz(preg_match('/^#[0-9a-f]{3,8}$/i', $sz['kiemelo']) === 1, 'színek: érvényes kiemelőszín');
SDH_Sales::b_ment([SDH_Sales_Arculat::kulcs('kiemelo', 'vilagos') => '#123456']);
T::egyezik('#123456', $E::szinek()['kiemelo'], 'az Arculat színe a levélben');
T::tartalmaz((string) $E::osszeallit('udvozlo', ['nev' => 'x', 'letoltes_url' => 'https://a.hu'])['html'], '#123456', 'a gomb az arculat színével');
SDH_Sales::b_ment([SDH_Sales_Arculat::kulcs('kiemelo', 'vilagos') => 'red; background:url(x)']);
T::nem_tartalmaz((string) $E::osszeallit('udvozlo', ['nev' => 'x'])['html'], 'url(x)', 'érvénytelen szín nem kerül a levélbe');
SDH_Sales::b_ment([SDH_Sales_Arculat::kulcs('kiemelo', 'vilagos') => '']);

/* --- Küldés ---------------------------------------------------------------------------------------------------------- */

$GLOBALS['t25_levelek'] = [];
T::igaz($E::kuld('fizetes_megerkezett', 'vevo@pelda.hu', ['nev' => 'Béla', 'rendeles' => 'R-9', '_rendeles_id' => 9]), 'küldés a szűrőn át');
$k = $GLOBALS['t25_levelek'][0] ?? [];
T::egyezik('vevo@pelda.hu', $k['cimzett'] ?? '', 'címzett');
T::igaz(in_array('Reply-To: info@forgalmazo.example', (array) ($k['fejlec'] ?? []), true), 'válaszcím: a cég e-mailje');
T::egyezik(false, $E::kuld('fizetes_megerkezett', 'nem-email', []), 'érvénytelen címzett: false');
T::egyezik(false, $E::kuld('nincs_ilyen_sablon', 'vevo@pelda.hu', []), 'ismeretlen sablon: false');
$n = SDH_Sales_Naplo::lista(['tipus' => 'email', 'ref_id' => 9]);
T::igaz($n['ossz'] >= 1 && strpos((string) $n['sorok'][0]['szoveg'], 've***@pelda.hu') !== false, 'napló: maszkolt cím, rendeléshez kötve');
T::nem_tartalmaz((string) wp_json_encode(SDH_Sales_Naplo::lista(['tipus' => 'email'])), 'vevo@pelda.hu', 'a naplóban nincs teljes cím');

// A tartalom-tár felülírja, kikapcsolja.
SDH_Sales_Tartalom::ment('email', 'fizetes_megerkezett', ['targy' => 'Saját tárgy {rendeles}', 'html' => '<p>Saját szöveg, {nev}.</p>']);
T::egyezik('Saját tárgy R-7', $E::osszeallit('fizetes_megerkezett', ['rendeles' => 'R-7'])['targy'], 'a szerkesztett sablon érvényes');
SDH_Sales_Tartalom::ment('email', 'fizetes_megerkezett', ['targy' => 'x', 'html' => '<p>x</p>'], null, null, false);
T::egyezik(null, $E::osszeallit('fizetes_megerkezett', []), 'kikapcsolt sablon: nem megy ki');
SDH_Sales_Tartalom::torol('email', 'fizetes_megerkezett');
T::tartalmaz((string) $E::osszeallit('fizetes_megerkezett', ['nev' => 'x'])['targy'], 'Megérkezett', 'törlés után a gyári sablon');

// A szűrő nélkül: wp_mail, szöveges résszel, csatolmánnyal.
remove_all_filters('sdh_sales_email_kuldes');
$mail = [];
add_filter('pre_wp_mail', static function ($null, $atts) use (&$mail) {
    $mail[] = $atts;

    return true;
}, 0, 2);
$alt = '';
add_action('phpmailer_init', static function ($m) use (&$alt): void {
    $alt = (string) $m->AltBody;
}, 99);
$pdf = wp_tempnam('t254.pdf');
file_put_contents($pdf, '%PDF-1.4 t254');
T::igaz($E::kuld('udvozlo', 'b@pelda.hu', ['nev' => 'Béla', 'licenc_kulcs' => 'SDH-X'], [$pdf, '/nincs/ilyen.pdf']), 'wp_mail-útvonal');
T::egyezik([$pdf], (array) ($mail[0]['attachments'] ?? []), 'csak a létező csatolmány megy');
T::igaz(in_array('Content-Type: text/html; charset=UTF-8', (array) ($mail[0]['headers'] ?? []), true), 'HTML-levél');
@unlink($pdf);

// A phpmailer_init szöveges része (a valódi PHPMailerrel).
global $phpmailer;
if (!($phpmailer instanceof PHPMailer\PHPMailer\PHPMailer)) {
    require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
    require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
    require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
}
$pm = new PHPMailer\PHPMailer\PHPMailer(true);
$r  = new ReflectionProperty($E, 'alt');
$r->setAccessible(true);
$r->setValue(null, 'szöveges változat');
$E::phpmailer($pm);
T::egyezik('szöveges változat', $pm->AltBody, 'szöveges rész (multipart/alternative)');
$r->setValue(null, '');
$pm2 = new PHPMailer\PHPMailer\PHPMailer(true);
$E::phpmailer($pm2);
T::egyezik('', $pm2->AltBody, 'idegen levélhez nem nyúl');

/* --- Letöltő link a levélből: 3 letöltés --------------------------------------------------------------------------- */

add_filter('sdh_sales_email_kuldes', static function ($null, array $l) {
    $GLOBALS['t25_levelek'][] = $l;

    return true;
}, 10, 2);
t25_kiadas('9.9.7');
$id = t25_rendeles();
t25_fizet($id);
$u  = t25_levelek('udvozlo', (string) t25_r($id)['email']);
preg_match('/href="([^"]*letoltes[^"]*)"/', (string) ($u[0]['html'] ?? ''), $m);
$url = html_entity_decode((string) ($m[1] ?? ''));
$tok = t25_token($url);
T::igaz(strlen($tok) >= 30, 'token a levélben');
T::igaz(strpos($url, home_url('/licenc/letoltes/')) === 0, 'a link a Központ semleges letöltő címére mutat');
for ($i = 1; $i <= 3; $i++) {
    T::igaz(is_array(SDH_Kozpont_Kiszolgalas::letoltes_ellenoriz($tok)), $i . '. letöltés rendben');
}
T::igaz(is_string(SDH_Kozpont_Kiszolgalas::letoltes_ellenoriz($tok)), '4. letöltés elutasítva');
$s = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('letoltes') . ' WHERE token_hash = %s', hash('sha256', $tok)));
T::egyezik(14, (int) round((strtotime((string) $s->lejar . ' UTC') - strtotime((string) $s->letrehozva . ' UTC')) / DAY_IN_SECONDS), 'érvényesség: 14 nap');
T::igaz(is_string(SDH_Kozpont_Kiszolgalas::letoltes_ellenoriz(t25_token(SDH_Kozpont_Kiszolgalas::letoltes_link((int) t25_r($id)['kozpont_licenc_id'], 'x', time() - 14 * DAY_IN_SECONDS - 60)))), '14 nap után elutasítva');

T::vege('t254 e-mail és letöltő link');
