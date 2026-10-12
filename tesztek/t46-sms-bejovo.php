<?php
/**
 * t46 – bejövő SMS: webhook-kulcs, fogadás, párosítás, kétszeres jelzés, leiratkozás,
 * állapotjelzés, kézi hozzárendelés.
 *
 * A hamis átjárót (tesztek/sms-mock.php) a teszt maga indítja. Valódi SMS nem megy ki.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$S    = 'SDH_Muhely_Sms';
$Z    = 'SDH_Muhely_Sms_Szabaly';
$F    = 'SDH_Muhely_Sms_Felulet';
$M    = 'SDH_Muhely_Munkalap';
$port = 8094;
$fajl = sys_get_temp_dir() . '/sdh-sms-mock-t46.json';
@unlink($fajl);

$folyamat = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/sms-mock.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $csovek,
    null,
    ['SDH_SMS_MOCK_FAJL' => $fajl] + getenv()
);

register_shutdown_function(static function () use ($folyamat): void {
    if (is_resource($folyamat)) {
        proc_terminate($folyamat);
    }
});

for ($i = 0; $i < 40; $i++) {
    $ink = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);

    if ($ink) {
        fclose($ink);
        break;
    }

    usleep(100000);
}

$mock = static function (string $ut) use ($port): void {
    wp_remote_post('http://127.0.0.1:' . $port . $ut, ['timeout' => 12]);
};
$lista = static function () use ($port): array {
    $j = json_decode((string) wp_remote_retrieve_body(wp_remote_get('http://127.0.0.1:' . $port . '/_lista', ['timeout' => 12])), true);

    return is_array($j) ? $j : [];
};
$mock('/_reset');

add_filter('sdh_muhely_sms_azonnal', '__return_false');
$levelek = [];
add_filter('pre_wp_mail', static function ($r, $atts) use (&$levelek) {
    $levelek[] = $atts;

    return true;
}, 10, 2);

$beallit = static function (array $felul = []) use ($S, $port): void {
    update_option($S::OPT, array_merge($S::alapok(), [
        'be' => true, 'mod' => 'helyi', 'cim' => '127.0.0.1', 'port' => $port, 'felhasznalo' => 'tesztfelh',
        'jelszo' => SDH_Muhely_Titok::titkosit('tesztjelszo'), 'idotullepes' => 5, 'csendes_be' => false,
    ], $felul));
};
$sor  = static fn (int $id): object => $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', $id));
$dont = static fn (int $id): object => $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('dontes') . ' WHERE id = %d', $id));
$oldal = static function (string $nezet, array $get = []) use ($F): string {
    $regi = $_GET;
    $_GET = ['nezet' => $nezet] + $get;
    ob_start();
    $F::oldal();
    $h = (string) ob_get_clean();
    $_GET = $regi;

    return $h;
};


$B = 'SDH_Muhely_Sms_Bejovo';
foreach (['uzenet', 'esemeny', 'sablon', 'sablon_verzio', 'szabaly', 'dontes'] as $t) {
    $wpdb->query('DELETE FROM ' . $S::tabla($t));
}
delete_option($S::OPT_ALLAPOT);

delete_option($B::OPT_TITOK);
$beallit();

$kuld = static function (array $adat, ?string $kulcs = null, bool $json = true) use ($B): WP_REST_Response {
    rest_get_server();
    $k = new WP_REST_Request('POST', '/sdh-muhely/v1/sms/bejovo');

    if ($kulcs !== null) {
        $k->set_query_params(['k' => $kulcs]);
    }

    $k->set_header('content-type', 'application/json');
    $k->set_body($json ? (string) wp_json_encode($adat) : 'nem json');

    return rest_do_request($k);
};
$be = static fn (string $szam, string $szoveg, string $azon, string $mikor = '2026-10-12T10:15:00+02:00'): array => [
    'event' => 'sms:received', 'id' => 'wh-' . $azon,
    'payload' => ['messageId' => $azon, 'message' => $szoveg, 'phoneNumber' => $szam, 'receivedAt' => $mikor],
];
$tab_uz = SDH_Muhely_Schema::tabla('uzenet');

/* --- Kulcs és útvonal ----------------------------------------------------- */

$kulcs = $B::titok();
T::igaz(strlen($kulcs) >= 24, 'a webhook-kulcs hosszú');
T::egyezik($kulcs, $B::titok(), 'a kulcs stabil');
T::tartalmaz($B::url(), 'k=' . $kulcs, 'a webhook-cím tartalmazza a kulcsot');
T::tartalmaz(rawurldecode($B::url()), 'sdh-muhely/v1/sms/bejovo', 'a webhook-cím az útvonalra mutat');

$u = t_ugyfel('Bejövő Béla', ['telefon' => '06 30 111 2222', 'telefon2' => '+36 20 555 1234']);
$e = t_eszkoz($u, 'Apple', 'iPhone 13');
$id = t_munkalap($u, [$e], 'nyitott');
$ures = $wpdb->get_var('SELECT COUNT(*) FROM ' . $S::tabla('uzenet'));

T::egyezik(401, $kuld($be('+36301112222', 'Szia', 'a1'))->get_status(), 'kulcs nélkül 401');
T::egyezik(401, $kuld($be('+36301112222', 'Szia', 'a1'), 'rossz-kulcs')->get_status(), 'rossz kulccsal 401');
T::egyezik((int) $ures, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $S::tabla('uzenet')), 'jogosulatlan kérés nem ír semmit');
T::egyezik(0, $B::utolso(), 'jogosulatlan kérés nem számít életjelnek');
T::egyezik(400, $kuld([], $kulcs, false)->get_status(), 'nem JSON → 400');

/* --- Fogadás és párosítás --------------------------------------------------- */

$levelek = [];
$r = $kuld($be('+36301112222', 'Mikor lesz kész a telefonom?', 'm1'), $kulcs);
T::egyezik(200, $r->get_status(), 'érvényes jelzés → 200');
T::igaz($B::utolso() > time() - 10, 'életjel rögzítve');
$d = $r->get_data();
T::igaz(!empty($d['parositva']), 'az ismert szám a nyitott lapra párosul');
T::egyezik($id, (int) $d['munkalap_id'], 'a megfelelő lapra');
$sor = $sor_be = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', (int) $d['sms_id']));
T::egyezik('be', $sor->irany, 'bejövő irány');
T::egyezik('bejovo', $sor->kivalto, 'kiváltó: bejövő');
T::egyezik('Mikor lesz kész a telefonom?', $sor->szoveg, 'a szöveg szó szerint megvan');
T::egyezik('+36301112222', $sor->szam, 'a feladó egységes alakban');
T::egyezik($u, (int) $sor->ugyfel_id, 'ügyfél felismerve');
T::egyezik(0, (int) $sor->olvasva, 'olvasatlan');
T::tartalmaz((string) $sor->elkuldve, '2026-10-12', 'a telefon szerinti időpont megmarad');
$uz = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tab_uz} WHERE sms_id = %d", (int) $sor->id));
T::igaz(is_object($uz), 'bekerült a munkalap üzenetszálába');
T::egyezik('sms', (string) $uz->csatorna, 'szál: csatorna sms');
T::egyezik('be', (string) $uz->irany, 'szál: bejövő');
T::egyezik($id, (int) $uz->munkalap_id, 'szál: a laphoz kötve');
T::igaz(count($levelek) >= 1, 'a szervizes e-mailben értesül');
T::tartalmaz(SDH_Muhely_Rma::uzenetek_html($id), 'Mikor lesz kész a telefonom?', 'a lap üzenetei között látszik');

// Kétszeres jelzés
$r = $kuld($be('+36301112222', 'Mikor lesz kész a telefonom?', 'm1'), $kulcs);
T::egyezik(200, $r->get_status(), 'ismételt jelzés is 200');
T::egyezik(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $S::tabla('uzenet') . " WHERE irany = 'be'"), 'ugyanaz a messageId kétszer nem rögzül');
T::egyezik(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tab_uz} WHERE sms_id = " . (int) $sor->id), 'és a szálban sem duplázódik');

// Azonosító nélküli jelzés: tartalom alapján azonosított
$r = $kuld(['event' => 'sms:received', 'payload' => ['message' => 'Azonosító nélkül', 'phoneNumber' => '06301112222']], $kulcs);
$r2 = $kuld(['event' => 'sms:received', 'payload' => ['message' => 'Azonosító nélkül', 'phoneNumber' => '06301112222']], $kulcs);
T::egyezik(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $S::tabla('uzenet') . " WHERE szoveg = 'Azonosító nélkül'"), 'azonosító nélkül is egyszer rögzül');

// Második telefonmező
$r = $kuld($be('+36205551234', 'A másik számomról írok', 'm2'), $kulcs);
T::egyezik($u, (int) $wpdb->get_var($wpdb->prepare('SELECT ugyfel_id FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', (int) $r->get_data()['sms_id'])), 'a 2. telefonmező számát is felismeri');

// Nem szám formátum a tárolt mezőben (szóközök, kötőjel) – ugyanarra a számra
$u_b = t_ugyfel('Formátum Feri', ['telefon' => '+36 (70) 222-3344']);
$r = $kuld($be('06702223344', 'Formátum', 'm3'), $kulcs);
T::egyezik($u_b, (int) $wpdb->get_var($wpdb->prepare('SELECT ugyfel_id FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', (int) $r->get_data()['sms_id'])), 'eltérő formázású tárolt szám is egyezik');
T::egyezik(false, !empty($r->get_data()['parositva']), 'nyitott munkalap nélkül nem párosul laphoz');

/* --- Ismeretlen szám, kézi hozzárendelés ------------------------------------------- */

$levelek = [];
$r = $kuld($be('+36309998877', 'Ki ez? Hova hívjak?', 'm4'), $kulcs);
$d = $r->get_data();
T::egyezik(false, $d['parositva'], 'ismeretlen szám: párosítatlan');
$ism = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', (int) $d['sms_id']));
T::egyezik(0, (int) $ism->parositva, 'a napló is párosítatlannak jelzi');
T::egyezik(0, (int) $ism->ugyfel_id, 'nincs ügyfél');
T::igaz(count($levelek) >= 1, 'párosítatlan SMS-ről is megy értesítés');
T::tartalmaz($levelek[0]['subject'] ?? '', 'ismeretlen szám', 'az e-mail tárgya jelzi');

$szam_lap = (int) t_lap($id)->munkalap_szam;
$r = t_ajax('sdh_muhely_sms_parosit', ['id' => (int) $ism->id, 'munkalap_szam' => (string) $szam_lap]);
T::igaz(!empty($r['success']), 'kézi hozzárendelés sikeres');
$ism = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', (int) $ism->id));
T::egyezik(1, (int) $ism->parositva, 'hozzárendelés után párosított');
T::egyezik($id, (int) $ism->munkalap_id, 'a megadott laphoz');
T::igaz($wpdb->get_var("SELECT COUNT(*) FROM {$tab_uz} WHERE sms_id = " . (int) $ism->id) == 1, 'a szálban megjelent');
T::egyezik(false, !empty(t_ajax('sdh_muhely_sms_parosit', ['id' => (int) $ism->id, 'munkalap_szam' => (string) $szam_lap])['success']), 'másodszor nem rendelhető hozzá');
T::egyezik(false, !empty(t_ajax('sdh_muhely_sms_parosit', ['id' => 999999, 'munkalap_szam' => '1'])['success']), 'nemlétező SMS nem rendelhető');
T::egyezik(false, !empty(t_ajax('sdh_muhely_sms_parosit', ['id' => (int) $ism->id, 'munkalap_szam' => '99999999'])['success']), 'nemlétező munkalapszám elutasítva');

/* --- Lezárt lap nem párosul ------------------------------------------------------------ */

$zart = '';

foreach (SDH_Muhely_Munkalap::allapotok() as $k => $a) {
    if (!empty($a['zart']) && empty($a['ervenytelen'])) {
        $zart = (string) $k;
        break;
    }
}

T::igaz($zart !== '', 'van lezáró állapot a tesztben');
$u_z = t_ugyfel('Lezárt Lajos', ['telefon' => '06 31 444 5566']);
$ez  = t_eszkoz($u_z, 'Samsung', 'A52');
$lz  = t_munkalap($u_z, [$ez], $zart);
$r = $kuld($be('+36314445566', 'Köszönöm!', 'm5'), $kulcs);
T::egyezik(false, !empty($r->get_data()['parositva']), 'lezárt munkalapra nem párosul');
T::egyezik($u_z, (int) $wpdb->get_var($wpdb->prepare('SELECT ugyfel_id FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', (int) $r->get_data()['sms_id'])), 'de az ügyfél felismerve');

/* --- Leiratkozás ---------------------------------------------------------------------- */

$kuld($be('+36301112222', 'STOP.', 'm6'), $kulcs);
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT sms_tilt FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', $u)), 'STOP: az ügyfél tiltólistára kerül');
$tilt = $S::sor_be(['ugyfel_id' => $u, 'szoveg' => 'Automata', 'kivalto' => 'auto', 'kulcs' => 'teszt-tilt']);
T::egyezik('letiltva', $tilt['allapot'], 'tiltott ügyfélnek az automata SMS nem megy');
$kez = $S::sor_be(['ugyfel_id' => $u, 'szoveg' => 'Kézi marad', 'kivalto' => 'kezi', 'kulcs' => 'teszt-kezi']);
T::igaz($kez['ok'] && $kez['allapot'] !== 'letiltva', 'a kézi SMS továbbra is küldhető');
$wpdb->update(SDH_Muhely_Schema::tabla('ugyfel'), ['sms_tilt' => 0], ['id' => $u]);
$kuld($be('+36301112222', 'Nem leiratkozás, csak a stop szó benne van: megállt a gép', 'm7'), $kulcs);
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT sms_tilt FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', $u)), 'hosszabb mondat nem leiratkozás');

/* --- Hiányos adat, kikapcsolt modul ------------------------------------------------------ */

T::egyezik(400, $kuld(['event' => 'sms:received', 'payload' => ['message' => 'Szám nélkül']], $kulcs)->get_status(), 'feladó nélkül 400');
T::egyezik(400, $kuld(['event' => 'sms:received', 'payload' => ['phoneNumber' => '+36301112222', 'message' => '  ']], $kulcs)->get_status(), 'üres szöveg 400');
T::egyezik(200, $kuld(['event' => 'sms:failed', 'payload' => ['messageId' => 'x']], $kulcs)->get_status(), 'ismeretlen esemény: nyugtázva');

$db = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $S::tabla('uzenet') . " WHERE irany = 'be'");
$beallit(['be' => false]);
$r = $kuld($be('+36301112222', 'Kikapcsolva', 'm8'), $kulcs);
T::egyezik(200, $r->get_status(), 'kikapcsolt modul: 200 (az app ne ismételgessen)');
T::egyezik($db, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $S::tabla('uzenet') . " WHERE irany = 'be'"), 'kikapcsolt modul: nem rögzít');
$beallit();

/* --- Kimenő SMS állapotjelzése ---------------------------------------------------------------- */

$u_k = t_ugyfel('Kimenő Kata', ['telefon' => '06 30 123 4567']);
$k = $S::sor_be(['ugyfel_id' => $u_k, 'szoveg' => 'Jelzéses', 'kivalto' => 'kezi', 'kulcs' => 'teszt-jelzes']);
$S::feldolgoz();
$ki = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', $k['id']));
T::igaz((string) $ki->atjaro_id !== '', 'a kimenő SMS kapott átjáró-azonosítót');
$r = $kuld(['event' => 'sms:delivered', 'payload' => ['messageId' => $ki->atjaro_id]], $kulcs);
T::egyezik('delivered', $wpdb->get_var($wpdb->prepare('SELECT allapot FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', $k['id'])), 'sms:delivered → kézbesítve');
T::igaz($wpdb->get_var($wpdb->prepare('SELECT kezbesitve FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', $k['id'])) !== null, 'a kézbesítés ideje rögzítve');
$kuld(['event' => 'sms:sent', 'payload' => ['messageId' => $ki->atjaro_id]], $kulcs);
T::egyezik('delivered', $wpdb->get_var($wpdb->prepare('SELECT allapot FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', $k['id'])), 'egy elkésett sms:sent nem viszi vissza az állapotot');
T::egyezik(200, $kuld(['event' => 'sms:delivered', 'payload' => ['messageId' => 'nincs-ilyen']], $kulcs)->get_status(), 'ismeretlen messageId: nyugtázva');

/* --- Felület ---------------------------------------------------------------------------------- */

$bej = $oldal('bejovo');
T::tartalmaz($bej, 'Mikor lesz kész a telefonom?', 'bejövő nézet: az SMS látszik');
T::tartalmaz($bej, 'Bejövő Béla', 'bejövő nézet: ügyfélnév');
T::tartalmaz($bej, 'sdh-sms-webhook-url', 'adminnak ott a webhook-cím');
T::tartalmaz($bej, 'k=' . $kulcs, 'és benne a kulcs');
T::tartalmaz($bej, 'data-sdh-sms-parosit', 'a párosítatlanhoz van hozzárendelő űrlap');
T::tartalmaz($bej, 'Párosítatlan', 'párosítatlan jelzés');
T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $S::tabla('uzenet') . " WHERE irany = 'be' AND olvasva = 0 AND parositva = 1"), 'a megnyitott nézet után a párosítottak olvasottak');
T::igaz((int) $wpdb->get_var("SELECT COUNT(*) FROM " . $S::tabla('uzenet') . " WHERE irany = 'be' AND olvasva = 0 AND parositva = 0") >= 1, 'a párosítatlanok olvasatlanok maradnak');

$kollega = wp_insert_user(['user_login' => 'kollega' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(), 'role' => 'editor']);
$admin   = get_current_user_id();
get_userdata($kollega)->add_cap(SDH_Muhely_Admin_UI::jog());
wp_set_current_user($kollega);
$kp = $oldal('bejovo');
T::nem_tartalmaz($kp, 'k=' . $kulcs, 'kollégának a webhook-kulcs nem látszik');
T::egyezik(false, !empty(t_ajax('sdh_muhely_sms_webhook_titok', [])['success']), 'kolléga nem kérhet új kulcsot');
wp_set_current_user($admin);

/* --- Kulcscsere ---------------------------------------------------------------------------------- */

$r = t_ajax('sdh_muhely_sms_webhook_titok', []);
T::igaz(!empty($r['success']), 'új kulcs kérése adminnak működik');
$uj_kulcs = $B::titok();
T::igaz($uj_kulcs !== $kulcs, 'a kulcs megváltozott');
T::egyezik(401, $kuld($be('+36301112222', 'Régi kulccsal', 'm9'), $kulcs)->get_status(), 'a régi kulcs érvénytelen');
T::egyezik(200, $kuld($be('+36301112222', 'Új kulccsal', 'm10'), $uj_kulcs)->get_status(), 'az új kulcs jó');

// Kulcs fejlécben is elfogadott
rest_get_server();
$k2 = new WP_REST_Request('POST', '/sdh-muhely/v1/sms/bejovo');
$k2->set_header('content-type', 'application/json');
$k2->set_header('authorization', 'Bearer ' . $uj_kulcs);
$k2->set_body((string) wp_json_encode($be('+36301112222', 'Fejléces', 'm11')));
T::egyezik(200, rest_do_request($k2)->get_status(), 'a kulcs Bearer-fejlécben is elfogadott');

T::vege('t46 bejövő SMS: webhook, párosítás, leiratkozás, állapotjelzés');
