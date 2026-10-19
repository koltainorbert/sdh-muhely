<?php
/**
 * t252 – távoli Központ: a hamis HTTP a valódi `sdh-kozpont/v1/kiszolgalas` REST-végpontot futtatja (valódi
 * aláírás-ellenőrzéssel mindkét irányban). Saját kulcspár (titkosítva), kérés aláírása, TOFU, a Központ elutasítja a
 * módosított / visszajátszott / régi kérést, a sales elutasítja a módosított / visszajátszott / régi / idegen kulcsú
 * választ; teljes lánc távoli módban; próba-végpont; magyar hibaüzenetek; 20 mp időkorlát.
 */
require __DIR__ . '/_t25-seged.php';

global $wpdb;

$KK = 'SDH_Sales_Kozpont_Kapcsolat';

/* --- Kulcspár ------------------------------------------------------------------------------------------------------ */

SDH_Sales::titkos_ment($KK::TITOK, '');
SDH_Sales::b_ment(['kozpont.sajat_nyilvanos' => '', 'kozpont.nyilvanos' => '']);
$pub = $KK::nyilvanos();
T::egyezik(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen((string) base64_decode($pub, true)), 'saját nyilvános kulcs (32 bájt)');
T::egyezik($pub, (string) SDH_Sales::b('kozpont.sajat_nyilvanos'), 'a nyilvános kulcs a beállításban');
T::egyezik($pub, $KK::nyilvanos(), 'állandó (nem generál újat)');
$nyers = (string) wp_json_encode(get_option(SDH_Sales::OPT_TITOK));
$titok = (string) base64_decode(SDH_Sales::titkos($KK::TITOK), true);
T::igaz(strlen($titok) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES && strpos($nyers, base64_encode($titok)) === false, 'a titkos kulcs titkosítva tárolva');

/* --- A „hálózat”: a kozpont.example.hu kéréseit a valódi REST-szerver kapja -------------------------------------- */

SDH_Sales::b_ment(['kozpont.mod' => 'tavoli', 'kozpont.url' => 'https://kozpont.example.hu/']);
SDH_Kozpont::beallitas_ment(['ertekesito_kulcs' => $pub, 'ertekesito_ip' => '']);

$GLOBALS['t252_keresek'] = [];
$GLOBALS['t252_babral']  = null;   // fn(array $valasz_test, array $keres_adat): array – a válasz meghamisítása
$GLOBALS['t252_args']    = [];

t_http(static function (string $url, array $args): ?array {
    if (strpos($url, 'kozpont.example.hu') === false) {
        return null;
    }

    parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
    $GLOBALS['t252_args'] = $args;

    if (!empty($GLOBALS['t252_regi']) && strpos((string) ($q['rest_route'] ?? ''), '/licenc/') === 0) {
        $GLOBALS['t252_keresek'][] = ['ut' => (string) $q['rest_route'], 'body' => ''];

        return t_valasz(404, '{"code":"rest_no_route"}');
    }
    $GLOBALS['t252_keresek'][] = ['ut' => (string) ($q['rest_route'] ?? ''), 'body' => (string) $args['body']];

    $r = new WP_REST_Request('POST', (string) ($q['rest_route'] ?? ''));
    $r->set_header('Content-Type', 'application/json');
    $r->set_body((string) $args['body']);
    $v    = rest_do_request($r);
    $data = $v->get_data();

    if (is_callable($GLOBALS['t252_babral']) && $v->get_status() === 200) {
        $be   = (array) json_decode((string) base64_decode((string) json_decode((string) $args['body'], true)['adat']), true);
        $data = ($GLOBALS['t252_babral'])((array) $data, $be);
    }

    return t_valasz($v->get_status(), (string) wp_json_encode($data));
});

/* --- Próba ------------------------------------------------------------------------------------------------------- */

$v = t_rest('POST', '/admin/kozpont/proba');
T::egyezik(200, $v->get_status(), 'próba: 200');
T::tartalmaz((string) $v->get_data()['uzenet'], 'tavoli mód', 'próba: mód az üzenetben');
T::egyezik('/licenc/v1/kiszolgalas', $GLOBALS['t252_keresek'][0]['ut'] ?? '', 'a kérés a semleges kiszolgálási végpontra ment');
T::egyezik(20, (int) ($GLOBALS['t252_args']['timeout'] ?? 0), 'időkorlát: 20 mp');
T::egyezik(base64_encode(SDH_Kozpont::kulcspar()['nyilvanos']), (string) SDH_Sales::b('kozpont.nyilvanos'), 'TOFU: a Központ kulcsa megjegyezve');
$al = t_rest('GET', '/admin/kozpont')->get_data();
T::igaz($al['mod'] === 'tavoli' && $al['sajat_nyilvanos'] === $pub && $al['kozpont_cim'] === 'https://kozpont.example.hu/', 'admin állapot: mód, saját kulcs, cím');
T::nem_tartalmaz((string) wp_json_encode($al), base64_encode($titok), 'az admin válaszban nincs titok');

$keres0 = (array) json_decode($GLOBALS['t252_keresek'][0]['body'], true);
$adat0  = (array) json_decode((string) base64_decode((string) $keres0['adat']), true);
T::igaz(sodium_crypto_sign_verify_detached((string) base64_decode((string) $keres0['alairas']), (string) base64_decode((string) $keres0['adat']), (string) base64_decode($pub)), 'a kérés a saját kulccsal aláírva');
T::igaz(abs((int) $adat0['ido'] - time()) < 5 && strlen((string) $adat0['nonce']) >= 16, 'a kérésben idő és nonce');

/* --- A Központ elutasítja: visszajátszott, módosított, régi kérés ---------------------------------------------------- */

$kozvetlen = static function (array $test): int {
    $r = new WP_REST_Request('POST', '/sdh-kozpont/v1/kiszolgalas');
    $r->set_header('Content-Type', 'application/json');
    $r->set_body((string) wp_json_encode($test));

    return rest_do_request($r)->get_status();
};
T::egyezik(401, $kozvetlen($keres0), 'Központ: visszajátszott kérés → 401');
$mod = $adat0;
$mod['muvelet'] = 'uj';
T::egyezik(401, $kozvetlen(['adat' => base64_encode((string) wp_json_encode($mod)), 'alairas' => $keres0['alairas']]), 'Központ: módosított kérés → 401');
$regi = ['muvelet' => 'proba', 'ido' => time() - 600, 'nonce' => SDH_Sales::token(24)];
$raw  = (string) wp_json_encode($regi);
T::egyezik(401, $kozvetlen(['adat' => base64_encode($raw), 'alairas' => base64_encode(sodium_crypto_sign_detached($raw, $titok))]), 'Központ: 10 perces kérés → 401');
$friss = ['muvelet' => 'proba', 'ido' => time(), 'nonce' => SDH_Sales::token(24)];
$raw   = (string) wp_json_encode($friss);
T::egyezik(200, $kozvetlen(['adat' => base64_encode($raw), 'alairas' => base64_encode(sodium_crypto_sign_detached($raw, $titok))]), 'Központ: friss, jól aláírt kérés → 200');

/* --- Teljes lánc távoli módban ------------------------------------------------------------------------------------- */

t25_kiadas('9.9.6');
$id = t25_rendeles();
t25_fizet($id);
$r = t25_r($id);
T::igaz(!empty($r['lanc']['kesz']) && (string) $r['allapot'] === 'kiszolgalva', 'távoli mód: a lánc kész');
$l = SDH_Kozpont::licenc((string) $r['licenc_kulcs']);
T::igaz($l !== null && (int) $l->id === (int) $r['kozpont_licenc_id'], 'a licenc a (távoli) Központban');
$u = t25_levelek('udvozlo', (string) $r['email']);
T::tartalmaz((string) ($u[0]['html'] ?? ''), 'https://kozpont.example.hu/', 'levél: a távoli Központ címe');
T::tartalmaz((string) ($u[0]['html'] ?? ''), '/licenc/letoltes/?t=', 'levél: letöltő link (semleges cím)');
$n = count($GLOBALS['t252_keresek']);
SDH_Sales_Kiszolgalas::indit($id);
T::egyezik($n, count($GLOBALS['t252_keresek']), 'újrafuttatás: nincs új hálózati kérés');

/* --- A sales elutasítja a hamis választ ---------------------------------------------------------------------------- */

$hiba = static function (callable $b) use ($KK): string {
    $GLOBALS['t252_babral'] = $b;
    $v = $KK::kuld(['muvelet' => 'proba']);
    $GLOBALS['t252_babral'] = null;

    return !empty($v['ok']) ? '' : (string) $v['uzenet'];
};

T::tartalmaz($hiba(static function (array $d): array {
    $a = (array) json_decode((string) base64_decode($d['adat']), true);
    $a['ok'] = true;
    $a['licenc_kulcs'] = 'SDH-HAMI-SHAM-ISHA-MIS0';
    $d['adat'] = base64_encode((string) wp_json_encode($a));

    return $d;
}), 'aláírása nem érvényes', 'módosított válasz: elutasítva');

T::tartalmaz($hiba(static function (array $d, array $be): array {
    return SDH_Kozpont::alair(['ok' => true, 'muvelet' => 'proba', 'nonce' => $be['nonce'], 'ido' => time() - 400]);
}), 'elavult', 'régi idejű válasz: elutasítva');

T::tartalmaz($hiba(static function (array $d, array $be): array {
    $kp  = sodium_crypto_sign_keypair();
    $raw = (string) wp_json_encode(['ok' => true, 'nonce' => $be['nonce'], 'ido' => time()]);

    return ['adat' => base64_encode($raw), 'alairas' => base64_encode(sodium_crypto_sign_detached($raw, sodium_crypto_sign_secretkey($kp))), 'kozpont_kulcs' => base64_encode(sodium_crypto_sign_publickey($kp))];
}), 'megváltozott', 'idegen Központ-kulcs (TOFU után): elutasítva');

T::tartalmaz($hiba(static fn (array $d): array => ['adat' => $d['adat']]), 'nincs érvényes aláírás', 'aláírás nélküli válasz: elutasítva');

// A visszajátszáshoz: egy korábbi, érvényes válasz.
$GLOBALS['t252_babral'] = static function (array $d): array {
    $GLOBALS['t252_elozo'] = $d;

    return $d;
};
$KK::kuld(['muvelet' => 'proba']);
$GLOBALS['t252_babral'] = null;
T::tartalmaz($hiba(static fn (): array => $GLOBALS['t252_elozo']), 'nem erre a kérésre', 'visszajátszás: a tárolt válasz egy másik nonce-ra szólt');
T::igaz(!empty($KK::kuld(['muvelet' => 'proba'])['ok']), 'hamisítás nélkül újra rendben');
T::egyezik(base64_encode(SDH_Kozpont::kulcspar()['nyilvanos']), (string) SDH_Sales::b('kozpont.nyilvanos'), 'a megjegyzett kulcs nem változott');

/* --- Hibák a sales oldalán: magyar üzenet, átmeneti / végleges ---------------------------------------------------- */

SDH_Kozpont::beallitas_ment(['ertekesito_kulcs' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);
$v = $KK::kuld(['muvelet' => 'proba']);
T::igaz(empty($v['ok']) && strpos((string) $v['uzenet'], 'nyilvános kulcsa') !== false && !empty($v['atmeneti']), 'rossz kulcs a Központban: 401 → érthető üzenet');
T::egyezik(502, t_rest('POST', '/admin/kozpont/proba')->get_status(), 'próba-végpont: hiba → 502');
SDH_Kozpont::beallitas_ment(['ertekesito_kulcs' => $pub]);

SDH_Sales::b_ment(['kozpont.url' => '']);
T::tartalmaz((string) $KK::kuld(['muvelet' => 'proba'])['uzenet'], 'Nincs megadva a Központ címe', 'cím nélkül: üzenet');
SDH_Sales::b_ment(['kozpont.url' => 'https://nem-elerheto.example.hu/']);
T::tartalmaz((string) $KK::kuld(['muvelet' => 'proba'])['uzenet'], 'nem érhető el', 'hálózati hiba: üzenet');
SDH_Sales::b_ment(['kozpont.url' => 'https://kozpont.example.hu/']);

$v = $KK::kuld(['muvelet' => 'megujit', 'rendeles' => 'R-T252-X', 'licenc_kulcs' => 'SDH-AAAA-AAAA-AAAA-AAAA', 'vevo' => ['email' => 'a@b.hu']]);
T::igaz(empty($v['ok']) && empty($v['atmeneti']) && strpos((string) $v['uzenet'], 'Ismeretlen licenckulcs') !== false, 'a Központ üzleti hibája: végleges, az üzenetével');

/* --- TOFU törlése, új kulcspár ------------------------------------------------------------------------------------ */

T::egyezik(200, t_rest('POST', '/admin/kozpont/kulcs_felejt')->get_status(), 'megjegyzett kulcs törlése');
T::egyezik('', (string) SDH_Sales::b('kozpont.nyilvanos'), 'törölve');
$KK::kuld(['muvelet' => 'proba']);
T::igaz((string) SDH_Sales::b('kozpont.nyilvanos') !== '', 'a következő kapcsolatkor újra megjegyezve');
$uj = t_rest('POST', '/admin/kozpont/kulcspar_uj')->get_data();
T::igaz($uj['sajat_nyilvanos'] !== $pub, 'új kulcspár');
T::tartalmaz((string) $KK::kuld(['muvelet' => 'proba'])['uzenet'], 'nyilvános kulcsa', 'a régi kulccsal a Központ elutasít');
SDH_Kozpont::beallitas_ment(['ertekesito_kulcs' => $uj['sajat_nyilvanos']]);
T::igaz(!empty($KK::kuld(['muvelet' => 'proba'])['ok']), 'az új kulcs a Központban: újra megy');

/* --- Régi Központ (nincs semleges névtér): a régi útvonalra vált ------------------------------------------------- */

$GLOBALS['t252_keresek'] = [];
$GLOBALS['t252_regi'] = true;
T::igaz(!empty($KK::kuld(['muvelet' => 'proba'])['ok']), 'régi Központ: a 404 után a régi útvonallal megy');
T::egyezik(['/licenc/v1/kiszolgalas', '/sdh-kozpont/v1/kiszolgalas'], array_column($GLOBALS['t252_keresek'], 'ut'), 'előbb a semleges, aztán a régi út');
$GLOBALS['t252_regi'] = false;

/* --- Helyi mód Központ nélkül ------------------------------------------------------------------------------------- */

SDH_Sales::b_ment(['kozpont.mod' => 'helyi']);
T::igaz($KK::helyi_elerheto(), 'helyi mód: a Központ elérhető');
T::egyezik(home_url('/'), $KK::kozpont_cim(), 'helyi mód: a Központ címe a webhely');

T::vege('t252 távoli Központ: aláírás mindkét irányban');
