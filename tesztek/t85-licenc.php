<?php
/**
 * t85 – N4 (0.93.0): licenc – a vevő oldala (SDH_Muhely_Licenc) és a központ (kozpont/sdh-kozpont) együtt.
 *
 * A központi bővítményt a teszt tölti be; a HTTP-kéréseket a WordPress saját REST-szervere szolgálja ki (nincs hálózat).
 */

require __DIR__ . '/_keret.php';
require_once dirname(__DIR__) . '/kozpont/sdh-kozpont/sdh-kozpont.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;

$L = 'SDH_Muhely_Licenc';
$K = 'SDH_Kozpont';

$K::sema();
do_action('rest_api_init', rest_get_server());

// A „központ” ugyanez a webhely: a kéréseket a REST-szerver kapja.
$kerelmek = [];
add_filter('pre_http_request', static function ($elo, array $args, string $url) use (&$kerelmek) {
    parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
    $ut = (string) ($q['rest_route'] ?? '');

    if (!str_starts_with($ut, '/sdh-kozpont/v1/')) {
        return $elo;
    }

    $kerelmek[] = $ut;
    $r = new WP_REST_Request(strtoupper((string) ($args['method'] ?? 'GET')), $ut);
    unset($q['rest_route']);
    $r->set_query_params($q);

    if (!empty($args['body'])) {
        $r->set_header('Content-Type', 'application/json');
        $r->set_body((string) $args['body']);
    }

    $v = rest_do_request($r);

    return ['headers' => [], 'body' => (string) wp_json_encode($v->get_data()), 'response' => ['code' => $v->get_status(), 'message' => ''], 'cookies' => [], 'filename' => null];
}, 1, 3);

$regi = get_option($L::OPT);
delete_option($L::OPT);
delete_option('sdh_kozpont_kiadas');

/* --- Saját példány (nincs központ) ---------------------------------------------------------------------------- */

T::egyezik('sajat', $L::allapot(), 'központ nélkül: saját példány');
T::igaz($L::frissitheto(), 'saját példány frissíthető');
T::egyezik('', $L::figyelmeztetes(), 'nincs figyelmeztetés');
T::igaz(preg_match('/^[a-f0-9\-]{36}$/', $L::peldany()) === 1, 'a példány azonosítója UUID');
T::egyezik($L::peldany(), $L::peldany(), 'és állandó');
T::egyezik(24, strlen($L::ujjlenyomat()), 'ujjlenyomat (a cím nem megy át)');

/* --- Licenc a központban, első jelentés ------------------------------------------------------------------------ */

$kulcs = $K::uj_kulcs();
T::igaz(preg_match('/^SDH-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $kulcs) === 1, 'kulcs formátuma');
$wpdb->insert($K::t('licenc'), ['kulcs' => $kulcs, 'vevo' => 'Próba Szerviz T85', 'max_peldany' => 1, 'allapot' => 'aktiv', 'uzenet' => 'Köszönjük!', 'letrehozva' => current_time('mysql')]);
$lid = (int) $wpdb->insert_id;

update_option($L::OPT, ['kozpont' => home_url('/'), 'kulcs' => strtolower($kulcs), 'peldany' => $L::peldany()]);
T::igaz($L::kozpontbol(), 'központ beállítva');
T::egyezik('meg_nem', $L::allapot(), 'még nem volt kapcsolat');
T::egyezik('', $L::jelentes(), 'jelentés sikerült');
T::egyezik('aktiv', $L::allapot(), 'licenc: aktív');
T::egyezik('Próba Szerviz T85', (string) $L::beallitas()['utolso']['vevo'], 'a vevő neve a központból');
T::igaz($L::beallitas()['kozpont_kulcs'] !== '', 'a központ kulcsa megjegyezve');
$p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $K::t('peldany') . ' WHERE peldany = %s', $L::peldany()));
T::igaz($p !== null && (int) $p->licenc_id === $lid && (string) $p->verzio === SDH_MUHELY_VERSION, 'a központ látja a példányt és a verziót');
T::egyezik($L::ujjlenyomat(), (string) ($p->ujjlenyomat ?? ''), 'és az ujjlenyomatot');
$oszlopok = array_map(static fn ($o): string => (string) $o->Field, (array) $wpdb->get_results('SHOW COLUMNS FROM ' . $K::t('peldany')));
T::igaz($oszlopok === [] || !array_intersect($oszlopok, ['domain', 'url', 'ugyfel', 'nev', 'email']), 'a központ nem tárol címet, ügyfelet, nevet');

/* --- Az aláírás ellenőrzése ------------------------------------------------------------------------------------- */

$jo = $K::alair(['peldany' => $L::peldany(), 'ido' => time(), 'allapot' => 'aktiv']);
T::igaz(is_array($L::valasz_ellenoriz($jo, $L::peldany())), 'helyes aláírás: elfogadva');
$hamis = $jo;
$hamis['adat'] = base64_encode((string) wp_json_encode(['peldany' => $L::peldany(), 'ido' => time(), 'allapot' => 'aktiv', 'x' => 1]));
T::tartalmaz((string) $L::valasz_ellenoriz($hamis, $L::peldany()), 'aláírása nem érvényes', 'megváltoztatott adat: elutasítva');
$kp = sodium_crypto_sign_keypair();
$mas = ['adat' => $jo['adat'], 'alairas' => base64_encode(sodium_crypto_sign_detached((string) base64_decode($jo['adat']), sodium_crypto_sign_secretkey($kp))), 'kozpont_kulcs' => base64_encode(sodium_crypto_sign_publickey($kp))];
T::tartalmaz((string) $L::valasz_ellenoriz($mas, $L::peldany()), 'kulcsa megváltozott', 'idegen kulcs: elutasítva');
T::tartalmaz((string) $L::valasz_ellenoriz($K::alair(['peldany' => wp_generate_uuid4(), 'ido' => time()]), $L::peldany()), 'nem ennek a példánynak', 'más példánynak szóló válasz: elutasítva');
T::tartalmaz((string) $L::valasz_ellenoriz($K::alair(['peldany' => $L::peldany(), 'ido' => time() - 3 * DAY_IN_SECONDS]), $L::peldany()), 'elavult', 'régi válasz (visszajátszás): elutasítva');

/* --- Kiadás és frissítés a központból ------------------------------------------------------------------------- */

$zip = (string) tempnam(sys_get_temp_dir(), 't85');
$z   = new ZipArchive();
$z->open($zip, ZipArchive::OVERWRITE);
$z->addFromString('sdh-muhely/sdh-muhely.php', "<?php\n/**\n * Plugin Name: SDH Műhely\n * Version:           9.9.9\n */\n");
$z->close();
T::egyezik('', $K::kiadas_be($zip), 'kiadás feltöltve');
T::egyezik('9.9.9', $K::kiadas()['verzio'], 'a verzió a zip fejlécéből');
T::tartalmaz($K::kiadas_be(__FILE__), 'zip', 'nem zip: elutasítva');
$L::jelentes();
$uj = SDH_Muhely_Frissites::uj_verzio();
T::egyezik('9.9.9', $uj['verzio'], 'a frissítés a központból jön');
T::tartalmaz(SDH_Muhely_Frissites::csomag_url(), 'sdh-kozpont%2Fv1%2Fcsomag', 'a csomag címe a központé');
T::igaz($K::csomag_szabad($kulcs, $L::peldany()), 'a csomag letölthető');
T::igaz(!$K::csomag_szabad($kulcs, wp_generate_uuid4()), 'ismeretlen példánynak nem');

/* --- Példányszám, tiltás, lejárat, kapcsolat ------------------------------------------------------------------- */

T::egyezik('tul_sok', $K::jelentes_feldolgoz(['kulcs' => $kulcs, 'peldany' => wp_generate_uuid4(), 'verzio' => '1.0'])['allapot'], 'második példány egy példányos licenccel: túl sok');
T::egyezik('ismeretlen', $K::jelentes_feldolgoz(['kulcs' => 'SDH-NINCS-ILYE-NKUL-CSXX', 'peldany' => wp_generate_uuid4()])['allapot'], 'ismeretlen kulcs');

$wpdb->update($K::t('licenc'), ['allapot' => 'tiltva'], ['id' => $lid]);
$L::jelentes();
T::egyezik('tiltva', $L::allapot(), 'letiltva');
T::igaz(!$L::frissitheto(), 'tiltva nem frissíthető');
T::tartalmaz($L::figyelmeztetes(), 'tovább működik', 'figyelmeztetés: a rendszer tovább működik');
T::tartalmaz(SDH_Muhely_Frissites::uj_verzio(true)['hiba'], 'érvényes licenccel', 'frissítés helyett hibaüzenet');
T::igaz(!$K::csomag_szabad($kulcs, $L::peldany()), 'tiltva nem tölthető le');

$wpdb->update($K::t('licenc'), ['allapot' => 'aktiv', 'lejar' => '2020-01-01'], ['id' => $lid]);
$L::jelentes();
T::egyezik('lejart', $L::allapot(), 'lejárt');
T::tartalmaz($L::figyelmeztetes(), '2020-01-01', 'a lejárat napja a figyelmeztetésben');
$wpdb->update($K::t('licenc'), ['lejar' => null], ['id' => $lid]);
$L::jelentes();
T::egyezik('aktiv', $L::allapot(), 'újra aktív');
T::egyezik('nincs_kapcsolat', $L::allapot(time() + 31 * DAY_IN_SECONDS), '30 nap kapcsolat nélkül: figyelmeztet');

/* --- Támogatási hozzáférés ------------------------------------------------------------------------------------- */

T::tartalmaz((string) $L::tamogatas_indit(5), 'időtartam', 'ismeretlen időtartam');
$t = $L::tamogatas_indit(24);
T::igaz(is_array($t), 'támogatási hozzáférés engedélyezve');
$uid = (int) ($t['user'] ?? 0);
T::igaz(user_can($uid, 'manage_options'), 'ideiglenes fejlesztői fiók');
T::igaz(!empty($t['kozpont']), 'a link elment a központba');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $K::t('tamogatas') . ' WHERE peldany = %s', $L::peldany())), 'a központban látszik');
parse_str((string) wp_parse_url((string) $t['link'], PHP_URL_QUERY), $q);
T::egyezik($uid, $L::tamogatas_token((string) ($q[$L::QV] ?? ''))
, 'a belépő link érvényes');
T::egyezik(0, $L::tamogatas_token('rossz-token-rossz-token-rossz-token-xx'), 'hamis link: nem');
T::egyezik(0, $L::tamogatas_token((string) ($q[$L::QV] ?? ''), time() + 25 * HOUR_IN_SECONDS), 'lejárt link: nem');
T::egyezik(0, $L::tamogatas_takarit(), 'még élő: nem törlődik');
T::egyezik(1, $L::tamogatas_takarit(time() + 25 * HOUR_IN_SECONDS), 'lejárat után a fiók törlődik');
T::igaz(get_userdata($uid) === false, 'a fiók nincs meg');
$t2 = $L::tamogatas_indit(2);
T::igaz($L::tamogatas_visszavon((int) $t2['user']), 'visszavonás');
T::igaz(get_userdata((int) $t2['user']) === false, 'visszavonva: törölve');

/* --- Beállítás -------------------------------------------------------------------------------------------------- */

T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['licenc']), 'Beállítások › Licenc');
ob_start();
$L::beallitas_doboz();
$h = (string) ob_get_clean();
T::tartalmaz($h, 'name="licenc[kulcs]"', 'a licenc lapfül');
T::tartalmaz($h, 'data-sdh-tamogatas-indit', 'a támogatási gomb');

@unlink($zip); // phpcs:ignore WordPress.PHP.NoSilencedErrors
$f = $K::kiadas()['fajl'];
if ($f !== '' && is_file($f)) {
    unlink($f);
}
delete_option('sdh_kozpont_kiadas');
delete_transient(SDH_Muhely_Frissites::CACHE);
$regi === false ? delete_option($L::OPT) : update_option($L::OPT, $regi);

T::vege('t85 licenc és központ (N4)');
