<?php
/**
 * t64 – 0.70.0: párhuzamos szerkesztés védelme (H1).
 *
 * - Az űrlap alapot (szerk_naplo) kap; ha közben MÁS belépett felhasználó módosította a lapot /
 *   ügyfelet / eszközt, a mentés nem fut le (409), és a válasz megmondja, ki és mikor.
 * - A saját módosítás, az esemény (nyomtatás, SMS) és a rendszer írása nem ütközés.
 * - „Felülírom" döntésre a mentés lefut, a válasz új alapot ad.
 * - A „X is szerkeszti" a pulzusból jön, lejár, és nem kerül a Változásnaplóba.
 * - A böngészőoldal: ütközés-sáv kétlépcsős gombokkal, új alap mentés után, pulzus-adat.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$naplo = SDH_Muhely_Valtozasnaplo::tabla();
$tt    = SDH_Muhely_Schema::tabla('munkalap_tetel');

$admin_a = get_current_user_id();
$admin_b = wp_insert_user(['user_login' => 'gabor_t64_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'administrator', 'display_name' => 'Gábor Teszt']);
T::igaz(is_int($admin_b) && $admin_b > 0, 'a második felhasználó létrejött');

$u  = t_ugyfel('Ütköző Ubul');
$e  = t_eszkoz($u, 'Samsung', 'A52');
$id = t_munkalap($u, [$e]);

$alap_adat = static function (int $id) use ($u, $e): array {
    $lap = t_lap($id);

    return [
        'id' => $id, 'ugyfel_id' => $u, 'eszkoz_id' => $e, 'eszkozok_jelen' => '1', 'allapot' => (string) $lap->allapot,
        'keszult' => current_time('Y-m-d'), 'fizetett' => '0', 'tetelek_jelen' => '1', 'automentes' => '1',
    ];
};

$tetel = static function (string $nev, string $ar, string $id_ = '0'): array {
    return ['tetelek' => ['szolgaltatas' => ['7' => ['id' => $id_, 'eszkoz_id' => '0', 'megnevezes' => $nev, 'mennyiseg' => '1', 'me' => 'db', 'brutto_ar' => $ar, 'afa_kulcs' => '27']]]];
};

$ment = static function (int $id, array $extra) use ($alap_adat): array {
    return t_ajax('sdh_muhely_munkalapok_ment', $alap_adat($id) + $extra, 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
};

/* --- 1. Az űrlap alapot kap --------------------------------------------------- */

wp_set_current_user($admin_a);

ob_start();
SDH_Muhely_Utkozes::mezok('munkalap', $id);
$mezok = (string) ob_get_clean();

T::tartalmaz($mezok, 'name="szerk_naplo"', 'az űrlap hordozza az alapot');
T::tartalmaz($mezok, 'data-sdh-szerk="munkalap:' . $id . '"', 'az alap a rekordhoz kötött (típus:azonosító)');
T::tartalmaz($mezok, 'name="szerk_felulir" value="0"', 'a felülírás jelzője alapból kikapcsolt');

ob_start();
SDH_Muhely_Utkozes::mezok('munkalap', 0);
T::egyezik('', trim((string) ob_get_clean()), 'új (még nem mentett) rekordnál nincs mit védeni');

T::egyezik(false, SDH_Muhely_Utkozes::tipus_ervenyes('termek'), 'ismeretlen rekordtípus nem védett');

$alap0 = SDH_Muhely_Utkozes::alap();

/* --- 2. Más módosít: a mentés ütközik ----------------------------------------- */

wp_set_current_user($admin_b);
$rb = $ment($id, $tetel('Gábor tétele', '5000'));
T::igaz(!empty($rb['success']), 'Gábor mentése (nincs alapja: nincs ellenőrzés) sikeres' . (isset($rb['data']['uzenet']) ? ' – ' . $rb['data']['uzenet'] : ''));

wp_set_current_user($admin_a);
$ra = $ment($id, $tetel('Az én tételem', '7000') + ['szerk_naplo' => (string) $alap0, 'szerk_felulir' => '0']);

T::igaz(empty($ra['success']), 'a mentés NEM fut le, ha közben más módosította');
T::igaz(!empty($ra['data']['utkozes']), 'a válasz ütközést jelez');
T::igaz(in_array('Gábor Teszt', (array) ($ra['data']['kik'] ?? []), true), 'a válasz megnevezi, ki módosított');
T::igaz(!empty($ra['data']['ido']) && preg_match('/^\d{2}:\d{2}$/', (string) $ra['data']['ido']) === 1, 'a válasz megmondja, mikor (ó:p)');
T::tartalmaz((string) ($ra['data']['uzenet'] ?? ''), 'Gábor Teszt módosította', 'az üzenet olvasható magyarul');
T::igaz(isset($ra['data']['szerk_naplo']) && (int) $ra['data']['szerk_naplo'] > $alap0, 'a válasz a frissített alapot is adja');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tt} WHERE munkalap_id = %d", $id)), 'az ütköző mentés semmit nem írt (csak Gábor tétele van a lapon)');

/* --- 3. „Az enyémet mentsd": felülírás ---------------------------------------- */

$rf = $ment($id, $tetel('Az én tételem', '7000') + ['szerk_naplo' => (string) $alap0, 'szerk_felulir' => '1']);
T::igaz(!empty($rf['success']), 'felülírás döntésre a mentés lefut' . (isset($rf['data']['uzenet']) ? ' – ' . $rf['data']['uzenet'] : ''));
T::igaz(isset($rf['data']['szerk_naplo']) && (int) $rf['data']['szerk_naplo'] >= $alap0, 'a sikeres mentés új alapot ad vissza');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tt} WHERE munkalap_id = %d", $id)), 'a felülírás után a lapon a saját tételem van (a másiké lecserélődött)');
T::egyezik('Az én tételem', (string) $wpdb->get_var($wpdb->prepare("SELECT megnevezes FROM {$tt} WHERE munkalap_id = %d", $id)), 'a saját tétel mentődött');

/* --- 4. A saját módosítás nem ütközés ----------------------------------------- */

$uj_alap = (int) $rf['data']['szerk_naplo'];
$sajat_id = (int) ($rf['data']['uj_sorok']['szolgaltatas:7'] ?? 0);
$r2 = $ment($id, $tetel('Az én tételem', '7500', (string) $sajat_id) + ['szerk_naplo' => (string) $uj_alap, 'szerk_felulir' => '0']);
T::igaz(!empty($r2['success']), 'a saját előző mentésem nem számít ütközésnek' . (isset($r2['data']['uzenet']) ? ' – ' . $r2['data']['uzenet'] : ''));

$r3 = $ment($id, $tetel('Az én tételem', '7600', (string) $sajat_id) + ['szerk_naplo' => (string) $r2['data']['szerk_naplo'], 'szerk_felulir' => '0']);
T::igaz(!empty($r3['success']), 'egymás utáni saját mentések (automentés) végig zöldek');

/* --- 5. Ami nem mezőmódosítás, az nem ütközés ---------------------------------- */

$alap_e = SDH_Muhely_Utkozes::alap();

wp_set_current_user($admin_b);
SDH_Muhely_Valtozasnaplo::esemeny('munkalap', $id, 'Teszt esemény (pl. nyomtatás)');

wp_set_current_user($admin_a);
T::egyezik([], SDH_Muhely_Utkozes::masok_modositasai('munkalap', $id, $alap_e, $admin_a), 'az esemény (nyomtatás, SMS) nem ütközés');

wp_set_current_user(0);
$wpdb->update(SDH_Muhely_Schema::tabla('munkalap'), ['megjegyzes' => 'rendszer írta'], ['id' => $id]);
$wpdb->get_var('SELECT 1'); // a napló a következő lekérdezésnél ír: még a rendszer nevében
wp_set_current_user($admin_a);
T::egyezik([], SDH_Muhely_Utkozes::masok_modositasai('munkalap', $id, $alap_e, $admin_a), 'a rendszer / ügyfél (nem belépett) írása nem ütközés');

/* --- 6. Ügyfél és eszköz ------------------------------------------------------- */

$alap_u = SDH_Muhely_Utkozes::alap();

wp_set_current_user($admin_b);
$rub = t_ajax('sdh_muhely_ugyfelek_ment', ['id' => $u, 'nev' => 'Ütköző Ubul', 'telefon' => '+36301110000', 'aktiv' => 1], 'sdh_nonce', 'sdh_muhely_ugyfel_mentes');
T::igaz(!empty($rub['success']), 'Gábor átírja az ügyfél telefonját');

wp_set_current_user($admin_a);
$rua = t_ajax('sdh_muhely_ugyfelek_ment', ['id' => $u, 'nev' => 'Ütköző Ubul', 'telefon' => '+36302220000', 'aktiv' => 1, 'szerk_naplo' => (string) $alap_u, 'szerk_felulir' => '0'], 'sdh_nonce', 'sdh_muhely_ugyfel_mentes');
T::igaz(empty($rua['success']) && !empty($rua['data']['utkozes']), 'az ügyfél mentése is ütközik');

$ruf = t_ajax('sdh_muhely_ugyfelek_ment', ['id' => $u, 'nev' => 'Ütköző Ubul', 'telefon' => '+36302220000', 'aktiv' => 1, 'szerk_naplo' => (string) $alap_u, 'szerk_felulir' => '1'], 'sdh_nonce', 'sdh_muhely_ugyfel_mentes');
T::igaz(!empty($ruf['success']) && isset($ruf['data']['szerk_naplo']), 'az ügyfél felülírása lefut, új alapot ad');

// Egy másik ügyfél változása nem érinti ezt az ügyfelet.
$u2 = t_ugyfel('Más Mátyás');
wp_set_current_user($admin_b);
$alap_m = SDH_Muhely_Utkozes::alap();
t_ajax('sdh_muhely_ugyfelek_ment', ['id' => $u2, 'nev' => 'Más Mátyás', 'telefon' => '+36309998888', 'aktiv' => 1], 'sdh_nonce', 'sdh_muhely_ugyfel_mentes');
wp_set_current_user($admin_a);
T::egyezik([], SDH_Muhely_Utkozes::masok_modositasai('ugyfel', $u, $alap_m, $admin_a), 'más ügyfél módosítása nem ütközés');

$alap_k = SDH_Muhely_Utkozes::alap();
wp_set_current_user($admin_b);
$wpdb->update(SDH_Muhely_Schema::tabla('eszkoz'), ['sorozatszam' => 'T64-' . wp_rand(100, 999)], ['id' => $e]);
$wpdb->get_var('SELECT 1'); // a napló a következő lekérdezésnél ír: még Gábor nevében
wp_set_current_user($admin_a);
T::igaz(count(SDH_Muhely_Utkozes::masok_modositasai('eszkoz', $e, $alap_k, $admin_a)) >= 1, 'az eszköz módosítását is észleli');

/* --- 7. „X is szerkeszti" a pulzusból ------------------------------------------ */

$kulcs = 'munkalap:' . $id;
$naplo_elotte = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$naplo}");

$regi_req = $_REQUEST;

wp_set_current_user($admin_b);
$_REQUEST['szerk'] = $kulcs . ',ugyfel:' . $u . ',x:1,munkalap:abc,eszkoz:0';
$pb = SDH_Muhely_Utkozes::pulzus([], $admin_b);
T::egyezik([], (array) $pb['szerkeszti'], 'egyedül nyitva tartva nincs mit jelezni');

wp_set_current_user($admin_a);
$_REQUEST['szerk'] = $kulcs;
$pa = SDH_Muhely_Utkozes::pulzus([], $admin_a);
T::egyezik(['Gábor Teszt'], (array) ($pa['szerkeszti']->{$kulcs} ?? []), 'a második szerkesztő látja, hogy Gábor is nyitva tartja');
T::igaz(!isset($pa['szerkeszti']->{'ugyfel:' . $u}), 'amit nem tartok nyitva, azt nem kapom meg');

wp_set_current_user($admin_b);
$_REQUEST['szerk'] = $kulcs;
$pb2 = SDH_Muhely_Utkozes::pulzus([], $admin_b);
T::igaz(!empty($pb2['szerkeszti']->{$kulcs}) && !in_array('Gábor Teszt', (array) $pb2['szerkeszti']->{$kulcs}, true), 'a saját nevemet nem mutatja');

// Érvénytelen kulcsok nem kerülnek tárolásba.
T::egyezik(false, get_transient('sdh_muhely_szerk_pulzus_x_1'), 'érvénytelen típus nem tárolódik');

// Bezárás: a másik azonnal nem látja.
$_REQUEST['szerk'] = '';
$_REQUEST['szerk_el'] = $kulcs;
SDH_Muhely_Utkozes::pulzus([], $admin_b);

wp_set_current_user($admin_a);
$_REQUEST['szerk'] = $kulcs;
unset($_REQUEST['szerk_el']);
$pa2 = SDH_Muhely_Utkozes::pulzus([], $admin_a);
T::egyezik([], (array) $pa2['szerkeszti'], 'bezárás után a jelzés eltűnik');

// Lejárat: az elavult pulzus nem számít.
set_transient('sdh_muhely_szerk_pulzus_munkalap_' . $id, [$admin_b => time() - SDH_Muhely_Utkozes::PULZUS_TTL - 5, $admin_a => time()], 300);
$pa3 = SDH_Muhely_Utkozes::pulzus([], $admin_a);
T::egyezik([], (array) $pa3['szerkeszti'], 'a lejárt (elhagyott) szerkesztés nem látszik');

$_REQUEST = $regi_req;

// A jelenlét-adat nem zajt ír a Változásnaplóba.
T::egyezik($naplo_elotte, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$naplo}"), 'a pulzus nem kerül a Változásnaplóba');

// Nem azonosított felhasználónál a szűrő nem csinál semmit.
T::egyezik(['a' => 1], SDH_Muhely_Utkozes::pulzus(['a' => 1], 0), 'azonosítatlan kérésnél a pulzus válasza érintetlen');

/* --- 8. A böngészőoldal ---------------------------------------------------------- */

$js  = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/utkozes.js');
$app = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.js');
$cs  = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/csapat.js');
$css = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/utkozes.css');

T::tartalmaz($js, "addEventListener('sdh:utkozes'", 'utkozes.js: az ütközés-eseményre sávot rajzol');
T::tartalmaz($js, "addEventListener('sdh:automentve'", 'utkozes.js: mentés után új alap, a sáv eltűnik');
T::tartalmaz($js, "addEventListener('sdh:pulzus'", 'utkozes.js: a pulzusból jelzi, ki szerkeszti még');
T::tartalmaz($js, "addEventListener('sdh:urlap-betoltve'", 'utkozes.js: megnyitáskor azonnali pulzus');
T::tartalmaz($js, 'Biztosan? A változtatásaim elvesznek', 'a frissítés kétlépcsős (elvész, amit beírtam)');
T::tartalmaz($js, 'Biztosan? A másik módosítása felülíródik', 'a felülírás kétlépcsős');
T::tartalmaz($js, "m.value = '1';", 'a felülírás jelzőt állít, és a megszokott úton ment újra');
T::tartalmaz($js, 'requestSubmit', 'gombos beküldésnél a felülírás újra beküld');
T::nem_tartalmaz($js, 'confirm(', 'nincs böngésző-confirm');
T::nem_tartalmaz($js, 'innerHTML', 'a nevek adatbázisból jönnek: csak textContent');
T::tartalmaz($js, 'window.SDH_UTKOZES = {', 'a pulzus innen kéri a nyitott űrlapokat');

T::tartalmaz($app, 'function utkozesJelez(urlap, adat, forras, n)', 'app.js: ütközés-esemény küldése');
T::tartalmaz($app, "utkozesJelez(a.urlap, eredmeny.data, 'auto', n);", 'app.js: az automentés ütközése nem csendes felülírás');
T::tartalmaz($app, "utkozesJelez(urlap, eredmeny.data, 'kuldes', n);", 'app.js: a Mentés gomb ütközése is a sávot hozza');
T::tartalmaz($app, "id: a.id, urlap: a.urlap", 'app.js: az automentés-esemény viszi az űrlapot (új alap)');
T::tartalmaz($cs, 'SDH_UTKOZES.pulzusAdat', 'csapat.js: a pulzus viszi a nyitott űrlapokat');

T::tartalmaz($css, '.sdh-szerk-jelzo', 'CSS: „X is szerkeszti" jelzés');
T::tartalmaz($css, 'var(--sdh-figyelem)', 'CSS: színek változóból');
T::igaz(preg_match('/#[0-9a-f]{3,8}\b/i', $css) === 0, 'CSS: nincs beégetett szín');

$fe = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-frontend.php');
$ad = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-admin-ui.php');
T::tartalmaz($fe, "'assets/utkozes.css'", 'frontend: CSS betöltve');
T::tartalmaz($fe, "'assets/utkozes.js'", 'frontend: JS betöltve');
T::tartalmaz($ad, "assets/utkozes.css", 'admin-ui: CSS betöltve');
T::tartalmaz($ad, "assets/utkozes.js", 'admin-ui: JS betöltve');

foreach (['munkalap', 'ugyfel', 'eszkoz'] as $osztaly) {
    $src = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-' . $osztaly . '.php');
    T::tartalmaz($src, "SDH_Muhely_Utkozes::ellenoriz('" . $osztaly . "', \$id)", $osztaly . ': a mentés előtt ellenőriz');
    T::tartalmaz($src, "SDH_Muhely_Utkozes::mezok('" . $osztaly . "'", $osztaly . ': az űrlap hordozza az alapot');
    T::tartalmaz($src, "'szerk_naplo' => \$szerk_naplo", $osztaly . ': a válasz új alapot ad');
}

T::egyezik(409, (int) preg_match_all('/wp_send_json_error\(\$utkozes, 409\)/', (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-munkalap.php') . (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-ugyfel.php') . (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-eszkoz.php')) === 3 ? 409 : 0, 'mindhárom végpont 409-cel válaszol ütközéskor');

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($admin_b);

T::vege('t64 párhuzamos szerkesztés (H1)');
