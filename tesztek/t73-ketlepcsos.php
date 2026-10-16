<?php
/**
 * t73 – H7 (0.81.0): kétlépcsős belépés (TOTP).
 *
 * - TOTP az RFC 6238 vektoraival, base32, ablak, egyszer használható kód.
 * - Szabály: választható / adminoknak / mindenkinek / kikapcsolva; vészkapcsoló.
 * - Belépés a saját bejelentkezőn: jelszó → kód (vagy beállítás QR-ral) → belépés; tartalék kód,
 *   rossz kód, kísérlet-korlát, zárolás; minden más belépési út elutasítva.
 * - Megbízható gép (süti), visszavonás; a saját fiók popupja (AJAX), admin visszaállítás, beállítás.
 */

require __DIR__ . '/_keret.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$K     = 'SDH_Muhely_Ketlepcsos';
$R     = 'SDH_Muhely_Rejtes';
$admin = get_current_user_id();

add_filter('send_auth_cookies', '__return_false');
delete_option($K::OPT);

foreach (['kolleg_t73', 'admin_t73'] as $login) {
    $regi = get_user_by('login', $login);

    if ($regi) {
        wp_delete_user((int) $regi->ID);
    }
}

/* --- 1. TOTP ------------------------------------------------------------------------------------ */

$rfc = $K::base32_kodol('12345678901234567890');
T::egyezik('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $rfc, 'base32 kódolás (RFC 4648)');
T::egyezik('12345678901234567890', $K::base32_dekodol($rfc), 'base32 vissza');
T::egyezik('12345678901234567890', $K::base32_dekodol(strtolower(chunk_split($rfc, 4, ' '))), 'base32 vissza szóközzel, kisbetűvel');
T::egyezik('287082', $K::kod($rfc, intdiv(59, 30)), 'RFC 6238: T=59 → 287082');
T::egyezik('081804', $K::kod($rfc, intdiv(1111111109, 30)), 'RFC 6238: T=1111111109 → 081804');
T::egyezik('005924', $K::kod($rfc, intdiv(1234567890, 30)), 'RFC 6238: T=1234567890 → 005924');
T::egyezik('279037', $K::kod($rfc, intdiv(2000000000, 30)), 'RFC 6238: T=2000000000 → 279037');

$titok = $K::uj_titok();
T::egyezik(32, strlen($titok), 'új titok: 160 bit = 32 base32 jel');
T::igaz($titok !== $K::uj_titok(), 'minden titok más');

$most = $K::most_lepes();
T::egyezik($most, $K::illik($titok, $K::kod($titok, $most)), 'az aktuális kód illik');
T::egyezik($most - 1, $K::illik($titok, $K::kod($titok, $most - 1)), 'az előző (30 mp) is illik – óraeltérés');
T::egyezik($most + 1, $K::illik($titok, $K::kod($titok, $most + 1)), 'a következő is illik');
T::egyezik(null, $K::illik($titok, $K::kod($titok, $most - 3)), 'a régi kód nem illik');
T::egyezik(null, $K::illik($titok, $K::kod($titok, $most), $most), 'egyszer felhasznált lépés kódja másodszor nem jó');
T::egyezik($most, $K::illik($titok, substr($K::kod($titok, $most), 0, 3) . ' ' . substr($K::kod($titok, $most), 3)), 'szóközzel beírt kód is jó');
T::egyezik(null, $K::illik($titok, '12345'), 'rövid kód nem jó');
T::egyezik(null, $K::illik('', '123456'), 'titok nélkül nincs kód');
$uri = $K::otpauth($titok, wp_get_current_user());
T::tartalmaz($uri, 'otpauth://totp/', 'otpauth cím');
T::tartalmaz($uri, 'secret=' . $titok, 'benne a titok');
T::tartalmaz($uri, 'issuer=' . rawurlencode(SDH_Muhely_Arculat::rendszer_nev()), 'kiadó: a rendszer neve (nem beégetett cégnév)');
T::igaz(SDH_Muhely_Kodok::qr_svg($uri) !== '', 'az otpauth cím QR-kódba fér');

/* --- 2. Fiókok és szabály ------------------------------------------------------------------------ */

$kolleg = (int) wp_insert_user(['user_login' => 'kolleg_t73', 'user_pass' => 'Jelszo-T73-ok!', 'role' => 'sdh_kollega', 'display_name' => 'Kolléga T73']);
$adm    = (int) wp_insert_user(['user_login' => 'admin_t73', 'user_pass' => 'Jelszo-T73-adm!', 'role' => 'sdh_admin', 'display_name' => 'Admin T73']);
T::igaz($kolleg > 0 && $adm > 0, 'próbafiókok');
$uk = get_userdata($kolleg);
$ua = get_userdata($adm);

T::egyezik('valaszthato', $K::beallitas()['szabaly'], 'alap szabály: választható');
T::egyezik(false, $K::kell($uk), 'választható, nincs beállítva: nem kell kód');
T::egyezik(true, $K::admin_felhasznalo($ua), 'az SDH adminisztrátor adminnak számít');
T::egyezik(false, $K::admin_felhasznalo($uk), 'a kolléga nem');

update_option($K::OPT, ['szabaly' => 'admin', 'megbizhato_nap' => 30]);
T::egyezik(true, $K::kotelezo($ua), '„adminoknak kötelező”: adminnak kötelező');
T::egyezik(false, $K::kotelezo($uk), '„adminoknak kötelező”: kollégának nem');
update_option($K::OPT, ['szabaly' => 'mindenki', 'megbizhato_nap' => 30]);
T::egyezik(true, $K::kotelezo($uk), '„mindenkinek kötelező”: a kollégának is');
update_option($K::OPT, ['szabaly' => 'nincs-ilyen', 'megbizhato_nap' => 999]);
T::egyezik(['szabaly' => 'valaszthato', 'megbizhato_nap' => $K::MEGBIZHATO_MAX], $K::beallitas(), 'hibás beállítás → választható, legfeljebb 90 nap');
delete_option($K::OPT);

/* --- 3. Bekapcsolás, tárolás ---------------------------------------------------------------------- */

$kt     = $K::uj_titok();
$kodok  = $K::bekapcsol($kolleg, $kt, 0);
T::egyezik($K::TARTALEK_DB, count($kodok), '10 tartalék kód');
T::igaz((bool) preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $kodok[0]), 'tartalék kód alakja: XXXX-XXXX');
T::egyezik(10, count(array_unique($kodok)), 'a tartalék kódok különböznek');
$nyers = (string) get_user_meta($kolleg, $K::META_TITOK, true);
T::igaz(SDH_Muhely_Titok::titkos_e($nyers) || !SDH_Muhely_Titok::elerheto(), 'a titok titkosítva áll az adatbázisban');
T::nem_tartalmaz($nyers, $kt, 'a nyílt titok nincs az adatbázisban');
T::egyezik($kt, $K::titok($kolleg), 'visszafejtve ugyanaz');
T::nem_tartalmaz(serialize(get_user_meta($kolleg, $K::META_TARTALEK, true)), str_replace('-', '', $kodok[0]), 'a tartalék kódoknak csak a lenyomata tárolva');
T::egyezik(true, $K::kell($uk), 'bekapcsolva: kell a kód');

/* --- 4. Belépés a saját bejelentkezőn ------------------------------------------------------------- */

wp_set_current_user(0);
$nonce = wp_create_nonce('sdh_muhely_belepes');
$r     = $R::belepes_feldolgoz(['log' => 'kolleg_t73', 'pwd' => 'Jelszo-T73-ok!', '_sdh_belepes' => $nonce, 'redirect_to' => '']);
T::egyezik('kod', $r['lepes'] ?? '', 'jó jelszó után: a kód lépése');
T::igaz(isset($r['jegy']) && strlen((string) $r['jegy']) === 48, 'a függő belépés jegye');
T::egyezik(0, get_current_user_id(), 'a jelszó után még nincs belépve');
$jegy = (string) $r['jegy'];

$html = $R::belepo_html('', '', '', null, false, $r);
T::tartalmaz($html, 'name="kod"', 'a bejelentkezőn a kód mezője');
T::tartalmaz($html, 'autocomplete="one-time-code"', 'a telefon fel tudja ajánlani a kódot');
T::tartalmaz($html, 'name="sdh_2fa_jegy" value="' . $jegy . '"', 'a jegy rejtett mezőben');
T::tartalmaz($html, 'name="megbizhato"', 'megbízható gép jelölő');
T::nem_tartalmaz($html, 'name="pwd"', 'a második lépésben nincs jelszó mező');
T::nem_tartalmaz($html, 'WordPress', 'a második lépés sem árulkodik');

$n2 = wp_create_nonce('sdh_muhely_2fa');
$r  = $R::kuldes(['sdh_2fa_jegy' => $jegy, '_sdh_2fa' => $n2, 'kod' => '000000', 'redirect_to' => '']);
T::egyezik('kod', $r['lepes'] ?? '', 'rossz kód: marad a kód lépése');
T::tartalmaz((string) ($r['hiba'] ?? ''), 'Hibás', 'rossz kód: hibaüzenet');
T::egyezik(0, get_current_user_id(), 'rossz kóddal nincs belépés');

$r = $R::kuldes(['sdh_2fa_jegy' => $jegy, '_sdh_2fa' => 'rossz', 'kod' => $K::kod($kt, $K::most_lepes())]);
T::igaz(!empty($r['elolrol']), 'hibás nonce: elölről');

$r = $R::kuldes(['sdh_2fa_jegy' => $jegy, '_sdh_2fa' => $n2, 'kod' => $K::kod($kt, $K::most_lepes())]);
T::egyezik(SDH_Muhely_Modulok::frontend_url(), $r['cel'] ?? '', 'jó kód: belépés, a műhelybe');
T::egyezik($kolleg, get_current_user_id(), 'be van jelentkezve');
T::egyezik(null, $K::fuggo($jegy), 'a jegy felhasználva (nem lehet újra)');

// Ugyanaz a kód még egyszer (új belépésben) nem jó.
wp_set_current_user(0);
$r = $R::belepes_feldolgoz(['log' => 'kolleg_t73', 'pwd' => 'Jelszo-T73-ok!', '_sdh_belepes' => wp_create_nonce('sdh_muhely_belepes')]);
$jegy = (string) ($r['jegy'] ?? '');
$r = $R::kuldes(['sdh_2fa_jegy' => $jegy, '_sdh_2fa' => wp_create_nonce('sdh_muhely_2fa'), 'kod' => $K::kod($kt, $K::most_lepes())]);
T::igaz(!isset($r['cel']), 'a már felhasznált kód nem enged be másodszor');

// Tartalék kód.
$r = $R::kuldes(['sdh_2fa_jegy' => $jegy, '_sdh_2fa' => wp_create_nonce('sdh_muhely_2fa'), 'kod' => strtolower($kodok[3])]);
T::igaz(isset($r['cel']), 'tartalék kóddal (kisbetűvel is) be lehet lépni');
T::egyezik('tartalek', $r['mod'] ?? '', 'a mód: tartalék');
T::egyezik(9, $K::tartalek_db($kolleg), 'a tartalék kód elfogyott: 9 maradt');

wp_set_current_user(0);
$r = $R::belepes_feldolgoz(['log' => 'kolleg_t73', 'pwd' => 'Jelszo-T73-ok!', '_sdh_belepes' => wp_create_nonce('sdh_muhely_belepes')]);
$jegy = (string) ($r['jegy'] ?? '');
$r = $R::kuldes(['sdh_2fa_jegy' => $jegy, '_sdh_2fa' => wp_create_nonce('sdh_muhely_2fa'), 'kod' => $kodok[3]]);
T::igaz(!isset($r['cel']), 'ugyanaz a tartalék kód másodszor nem jó');

// Kísérlet-korlát: 5 rossz kód után elölről.
for ($i = 0; $i < 4; $i++) {
    $r = $R::kuldes(['sdh_2fa_jegy' => $jegy, '_sdh_2fa' => wp_create_nonce('sdh_muhely_2fa'), 'kod' => '111111']);
}
T::igaz(!empty($r['elolrol']), '5 rossz kód után a jegy érvénytelen: elölről');
T::egyezik(null, $K::fuggo($jegy), 'a jegy törölve');

// Fiók-zárolás: 10 rossz kód negyedórára lezár (a jó kódot sem fogadja).
delete_transient('sdh_2fa_hiba_' . $kolleg);
for ($i = 0; $i < $K::ZAR_MAX; $i++) {
    $K::ellenoriz($kolleg, '222222');
}
T::egyezik(true, $K::zarolva($kolleg), '10 rossz kód: zárolva');
delete_user_meta($kolleg, $K::META_LEPES);
T::tartalmaz($K::ellenoriz($kolleg, $K::kod($kt, $K::most_lepes() + 1)), 'Túl sok', 'zárolva a jó kód sem megy');
delete_transient('sdh_2fa_hiba_' . $kolleg);

/* --- 5. Más belépési utak ------------------------------------------------------------------------- */

wp_set_current_user(0);
$e = wp_authenticate('kolleg_t73', 'Jelszo-T73-ok!');
T::igaz(is_wp_error($e) && $e->get_error_code() === 'sdh_ketlepcsos', 'wp_authenticate (pl. wp-login.php beküldése) elutasít, ha kód kell');
$e = wp_signon(['user_login' => 'kolleg_t73', 'user_password' => 'Jelszo-T73-ok!'], false);
T::igaz(is_wp_error($e), 'wp_signon sem enged be kód nélkül');
T::egyezik(0, get_current_user_id(), 'nincs belépve');
$e = wp_authenticate('admin_t73', 'Jelszo-T73-adm!');
T::igaz($e instanceof WP_User, 'akinek nem kell kód, annak a többi út marad');
$r = $R::belepes_feldolgoz(['log' => 'kolleg_t73', 'pwd' => 'rossz', '_sdh_belepes' => wp_create_nonce('sdh_muhely_belepes')]);
T::egyezik('Hibás felhasználónév vagy jelszó.', $r['hiba'] ?? '', 'rossz jelszó: nincs kód lépés, általános hiba');

/* --- 6. Megbízható gép ---------------------------------------------------------------------------- */

$suti = $K::suti_ertek($kolleg, time() + 3600);
T::egyezik(true, $K::megbizhato($kolleg, $suti), 'aláírt süti: megbízható gép');
T::egyezik(false, $K::megbizhato($kolleg, $kolleg . '|' . (time() + 3600) . '|hamis'), 'hamis aláírás: nem');
T::egyezik(false, $K::megbizhato($kolleg, $K::suti_ertek($kolleg, time() - 10)), 'lejárt süti: nem');
T::egyezik(false, $K::megbizhato($adm, $suti), 'más fiók sütije: nem');
$_COOKIE[$K::SUTI . '_' . $kolleg] = $suti;
T::egyezik(null, $K::fuggo_indit($uk, false, ''), 'megbízható gépen a jelszó után nem kér kódot');
$K::gepek_visszavon($kolleg);
T::egyezik(false, $K::megbizhato($kolleg), '„gépek visszavonása” után a régi süti nem jó');
$_COOKIE[$K::SUTI . '_' . $kolleg] = $K::suti_ertek($kolleg, time() + 3600);
update_option($K::OPT, ['szabaly' => 'valaszthato', 'megbizhato_nap' => 0]);
T::egyezik(false, $K::megbizhato($kolleg), '0 nap: a megbízható gép ki van kapcsolva');
delete_option($K::OPT);
unset($_COOKIE[$K::SUTI . '_' . $kolleg]);

/* --- 7. Kötelező, még nincs beállítva: beállítás a belépéskor ------------------------------------ */

update_option($K::OPT, ['szabaly' => 'admin', 'megbizhato_nap' => 30]);
wp_set_current_user(0);
$r = $R::belepes_feldolgoz(['log' => 'admin_t73', 'pwd' => 'Jelszo-T73-adm!', '_sdh_belepes' => wp_create_nonce('sdh_muhely_belepes'), 'redirect_to' => '']);
T::egyezik('beallitas', $r['lepes'] ?? '', 'kötelező, nincs kódja: a beállítás lépése');
$f    = $K::fuggo((string) $r['jegy']);
$html = $R::belepo_html('', '', '', null, false, $r);
T::tartalmaz($html, '<svg', 'a beállításnál QR-kód');
T::tartalmaz($html, $K::csoportos((string) $f['t']), 'és a kulcs kézi beíráshoz');
T::egyezik(false, $K::aktiv($adm), 'még nincs bekapcsolva');

$r2 = $R::kuldes(['sdh_2fa_jegy' => (string) $r['jegy'], '_sdh_2fa' => wp_create_nonce('sdh_muhely_2fa'), 'kod' => '123456']);
T::egyezik('beallitas', $r2['lepes'] ?? '', 'rossz kód: marad a beállítás');
$r2 = $R::kuldes(['sdh_2fa_jegy' => (string) $r['jegy'], '_sdh_2fa' => wp_create_nonce('sdh_muhely_2fa'), 'kod' => $K::kod((string) $f['t'], $K::most_lepes()), 'megbizhato' => '1']);
T::egyezik(10, count($r2['tartalek'] ?? []), 'beállítva: 10 tartalék kód a képernyőn');
T::egyezik($adm, get_current_user_id(), 'és belépett');
T::egyezik(true, $K::aktiv($adm), 'bekapcsolva');
$html = $R::belepo_html('', '', '', null, false, ['lepes' => 'tartalek', 'tartalek' => $r2['tartalek'], 'cel' => $r2['cel']]);
T::tartalmaz($html, $r2['tartalek'][0], 'a tartalék kódok egyszer megjelennek');
T::tartalmaz($html, 'href="' . esc_url($r2['cel']) . '"', 'Tovább gomb a műhelybe');

// Kikapcsolt szabály: senkinek nem kell, akkor sem, ha bekapcsolta.
update_option($K::OPT, ['szabaly' => 'ki', 'megbizhato_nap' => 30]);
T::egyezik(false, $K::kell($uk), 'kikapcsolva: senkinek nem kell kód');
T::egyezik('', $K::profil_sor(), 'kikapcsolva: a profilmenüben nincs sora');
delete_option($K::OPT);

/* --- 8. A saját fiók popupja (AJAX) --------------------------------------------------------------- */

wp_set_current_user($kolleg);
T::tartalmaz($K::profil_sor(), 'data-sdh-ketlepcsos-nyit', 'a profilmenüben a sora');
T::tartalmaz($K::profil_sor(), 'bekapcsolva', 'és mutatja az állapotot');
$K::kikapcsol($kolleg);
T::egyezik(false, $K::aktiv($kolleg), 'kikapcsolva');

$p = t_ajax('sdh_muhely_ketlepcsos_urlap');
T::tartalmaz($p['nyers'], 'data-sdh-ketlepcsos-kod', 'popup: kódmező');
T::tartalmaz($p['nyers'], '<svg', 'popup: QR-kód');
T::tartalmaz($p['nyers'], 'data-sdh-ketlepcsos-akcio="bekapcsol"', 'popup: Bekapcsolás gomb');
$uj = (string) get_transient('sdh_2fa_uj_' . $kolleg);
T::igaz(strlen($uj) === 32, 'a popup új titka átmenetileg tárolva');

$v = t_ajax('sdh_muhely_ketlepcsos_bekapcsol', ['kod' => '999999']);
T::igaz(empty($v['success']), 'popup: rossz kóddal nem kapcsol be');
$v = t_ajax('sdh_muhely_ketlepcsos_bekapcsol', ['kod' => $K::kod($uj, $K::most_lepes())]);
T::igaz(!empty($v['success']) && count($v['data']['tartalek'] ?? []) === 10, 'popup: bekapcsolva, 10 tartalék kód');
T::egyezik(true, $K::aktiv($kolleg), 'bekapcsolva a fióknál');

$p = t_ajax('sdh_muhely_ketlepcsos_urlap');
T::tartalmaz($p['nyers'], 'Bekapcsolva', 'popup: az állapot');
T::tartalmaz($p['nyers'], 'data-sdh-ketlepcsos-akcio="kikapcsol"', 'választhatónál kikapcsolható');

$v = t_ajax('sdh_muhely_ketlepcsos_tartalek', ['kod' => '000000']);
T::igaz(empty($v['success']), 'új tartalék kód csak érvényes kóddal');
$v = t_ajax('sdh_muhely_ketlepcsos_tartalek', ['kod' => $K::kod($uj, $K::most_lepes() + 1)]);
T::igaz(!empty($v['success']) && count($v['data']['tartalek'] ?? []) === 10, 'új tartalék kódok');

update_option($K::OPT, ['szabaly' => 'mindenki', 'megbizhato_nap' => 30]);
$p = t_ajax('sdh_muhely_ketlepcsos_urlap');
T::nem_tartalmaz($p['nyers'], 'data-sdh-ketlepcsos-akcio="kikapcsol"', 'kötelezőnél nincs Kikapcsolás gomb');
$v = t_ajax('sdh_muhely_ketlepcsos_kikapcsol', ['kod' => $kodok[0]]);
T::igaz(empty($v['success']), 'kötelezőnél kódokkal sem kapcsolható ki');
delete_option($K::OPT);

/* --- 9. Admin: visszaállítás, beállítás ----------------------------------------------------------- */

$v = t_ajax('sdh_muhely_ketlepcsos_visszaallit', ['felhasznalo' => $adm], '_wpnonce', 'sdh_muhely_ketlepcsos_visszaallit');
T::igaz(empty($v['success']), 'kolléga nem állíthatja vissza más kódját');
T::egyezik(true, $K::aktiv($adm), 'az admin kódja megmaradt');

wp_set_current_user($admin);
$v = t_ajax('sdh_muhely_ketlepcsos_visszaallit', ['felhasznalo' => $kolleg], '_wpnonce', 'sdh_muhely_ketlepcsos_visszaallit');
T::igaz(!empty($v['success']), 'az adminisztrátor visszaállítja');
T::egyezik(false, $K::aktiv($kolleg), 'a kolléga kódja törölve');
T::egyezik(0, $K::tartalek_db($kolleg), 'a tartalék kódjai is');

ob_start();
SDH_Muhely_Szemely::jogosultsagok_rajz();
$doboz = (string) ob_get_clean();
T::tartalmaz($doboz, 'name="ketlepcsos[szabaly]"', 'Jogosultságok fül: a szabály');
T::tartalmaz($doboz, 'name="ketlepcsos[megbizhato_nap]"', 'és a megbízható gép napjai');
T::tartalmaz($doboz, 'data-sdh-ketlepcsos-visszaallit="' . $adm . '"', 'a bekapcsolt fiók mellett Visszaállítás');

$_POST['ketlepcsos'] = ['szabaly' => 'admin', 'megbizhato_nap' => '14'];
$K::beallitas_mentes();
unset($_POST['ketlepcsos']);
T::egyezik(['szabaly' => 'admin', 'megbizhato_nap' => 14], $K::beallitas(), 'a beállítás mentve');

T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'assets/szemely.js'), 'sdh_muhely_ketlepcsos_', 'a böngésző oldala (szemely.js)');

/* --- 10. Vészkapcsoló ------------------------------------------------------------------------------ */

define('SDH_MUHELY_KETLEPCSOS_KI', true);
T::egyezik(true, $K::ki(), 'a vészkapcsoló kikapcsolja');
T::egyezik(false, $K::kell($ua), 'vészkapcsolóval nem kell kód');

// Rendrakás.
delete_option($K::OPT);
wp_delete_user($kolleg);
wp_delete_user($adm);

T::vege('t73 kétlépcsős belépés (H7)');
