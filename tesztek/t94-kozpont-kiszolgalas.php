<?php
/**
 * t94 – SDH Központ 2.1: kiszolgálás az értékesítő webhelynek – vevő keresése / létrehozása (adószám → e-mail → név),
 * új licenc (csomag, modulok, korlátok, lejárat, díj), megújítás (+1 ciklus), bővítés (modulok, tulajdonos-ellenőrzés),
 * idempotencia (rendelésazonosító), aláírt REST-végpont (rossz aláírás, módosított adat, visszajátszás, régi időbélyeg,
 * IP-lista, ismeretlen kulcs → 401 részletek nélkül; aláírt válasz), egyszer használható letöltő link (3 letöltés,
 * 14 nap, licenchez kötve), beállítások (kulcs ellenőrzése), séma és verzió.
 *
 * A központi bővítményt a teszt tölti be; nincs hálózat.
 */

require __DIR__ . '/_keret.php';
require_once dirname(__DIR__) . '/kozpont/sdh-kozpont/sdh-kozpont.php';

global $wpdb;

$K  = 'SDH_Kozpont';
$KS = 'SDH_Kozpont_Kiszolgalas';

delete_option('sdh_kozpont_db');
$K::sema();
do_action('rest_api_init', rest_get_server());
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';

/* --- Verzió, séma ----------------------------------------------------------------------------------------------- */

T::egyezik('2.1.0', SDH_KOZPONT_VERSION, 'verzió 2.1.0');
$fej = (string) file_get_contents(dirname(__DIR__) . '/kozpont/sdh-kozpont/sdh-kozpont.php');
T::igaz(preg_match('/^\s*\*\s*Version:\s*2\.1\.0\s*$/m', $fej) === 1, 'fejléc: Version 2.1.0');
T::tartalmaz($fej, '`kiszolgalas`', 'fejléc: a kiszolgálási végpont leírva');
T::tartalmaz($fej, '`letoltes`', 'fejléc: a letöltő végpont leírva');
T::egyezik('2.1.0', (string) get_option('sdh_kozpont_db'), 'DB 2.1.0');

foreach (['rendeles', 'letoltes', 'nonce'] as $t) {
    T::igaz($wpdb->get_var('SELECT COUNT(*) FROM ' . $K::t($t)) !== null, 'új tábla: ' . $t);
}

$utak = rest_get_server()->get_routes();
T::igaz(isset($utak['/sdh-kozpont/v1/kiszolgalas'], $utak['/sdh-kozpont/v1/letoltes'], $utak['/sdh-kozpont/v1/csomag']), 'végpontok: kiszolgalas, letoltes (+ a régi csomag)');

/* --- Új licenc, vevő ---------------------------------------------------------------------------------------------- */

$sfx  = (string) wp_rand(1000, 9999);
$vevo = ['nev' => 'Kiszolgált Szerviz Kft. T94' . $sfx, 'adoszam' => '1234' . $sfx . '-2-19', 'cim' => '8200 Veszprém, Fő u. 1.', 'kapcsolattarto' => 'Kiss Anna', 'email' => 'anna' . $sfx . '@pelda.hu', 'telefon' => '+36 30 555 1234'];
$k1   = [
    'muvelet'  => 'uj',
    'rendeles' => 'R-T94-' . $sfx . '-1',
    'vevo'     => $vevo,
    'licenc'   => ['csomag' => 'Pro', 'modulok' => ['sms', 'naptar', 'penztar'], 'max_felhasznalo' => 5, 'max_peldany' => 2, 'ciklus' => 'eves', 'dij' => 240000],
];

$v1 = $KS::feldolgoz($k1);
T::igaz(!empty($v1['ok']), 'új licenc: ok (' . (string) ($v1['uzenet'] ?? '') . ')');
T::igaz(preg_match('/^SDH-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', (string) ($v1['licenc_kulcs'] ?? '')) === 1, 'licenckulcs formátuma');
$lic = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $K::t('licenc') . ' WHERE id = %d', (int) ($v1['licenc_id'] ?? 0)));
T::igaz($lic !== null, 'licenc a Központban');
T::egyezik(['sms', 'naptar', 'penztar'], $K::licenc_modulok($lic), 'modulok');
T::egyezik(5, (int) $lic->max_felhasznalo, 'max felhasználó');
T::egyezik(2, (int) $lic->max_peldany, 'max példány');
T::egyezik('eves', (string) $lic->ciklus, 'ciklus');
T::egyezik(240000, (int) $lic->dij, 'díj');
T::egyezik('Pro', (string) $lic->csomag, 'csomag');
$varhato = wp_date('Y-m-d', (int) strtotime(wp_date('Y-m-d') . ' +12 month'));
T::egyezik($varhato, (string) $lic->lejar, 'lejárat: ma + 12 hónap');
T::egyezik($varhato, (string) $lic->esedekes, 'esedékesség = lejárat');
T::egyezik($varhato, (string) $v1['lejar'], 'a válaszban a lejárat');
T::tartalmaz((string) $lic->megjegyzes, $k1['rendeles'], 'a licencnél a rendelés azonosítója');
$vsor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $K::t('vevo') . ' WHERE id = %d', (int) $v1['vevo_id']));
T::igaz($vsor !== null && (string) $vsor->nev === $vevo['nev'] && (string) $vsor->email === $vevo['email'] && (string) $vsor->adoszam === $vevo['adoszam'], 'új vevő az adataival');
T::tartalmaz((string) $vsor->cimkek, 'webshop', 'vevő címkéje: webshop');
T::igaz(strpos((string) $v1['letoltes_url'], '/sdh-kozpont/v1/letoltes') !== false && strpos((string) $v1['letoltes_url'], 't=') !== false, 'letöltő link a válaszban');

/* --- Idempotencia ------------------------------------------------------------------------------------------------- */

$licdb = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $K::t('licenc'));
$v1b   = $KS::feldolgoz($k1);
T::egyezik($v1['licenc_kulcs'], $v1b['licenc_kulcs'] ?? '', 'ismételt kérés: ugyanaz a kulcs');
T::igaz(!empty($v1b['ismetles']), 'ismételt kérés: jelölve');
T::egyezik($licdb, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $K::t('licenc')), 'nem lett új licenc');
T::igaz((string) $v1b['letoltes_url'] !== '' && $v1b['letoltes_url'] !== $v1['letoltes_url'], 'friss letöltő link (a token nincs eltárolva)');
T::nem_tartalmaz((string) $wpdb->get_var($wpdb->prepare('SELECT valasz FROM ' . $K::t('rendeles') . ' WHERE rendeles = %s', $k1['rendeles'])), 'letoltes', 'a tárolt válaszban nincs letöltő token');
T::egyezik(false, $KS::feldolgoz(['muvelet' => 'megujit'] + $k1)['ok'], 'azonos azonosító más művelettel: elutasítva');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $K::t('rendeles') . ' WHERE rendeles = %s', $k1['rendeles'])), 'egy rendelés-sor');

/* --- Vevő párosítása: adószám → e-mail → név ------------------------------------------------------------------- */

$k2 = $k1;
$k2['rendeles'] = 'R-T94-' . $sfx . '-2';
$k2['vevo']     = ['nev' => 'Egészen Más Név T94', 'adoszam' => '1234' . $sfx . '219', 'email' => 'mas' . $sfx . '@pelda.hu'];
$v2 = $KS::feldolgoz($k2);
T::egyezik((int) $v1['vevo_id'], (int) ($v2['vevo_id'] ?? 0), 'párosítás adószámmal (kötőjelek nélkül is)');
T::egyezik($vevo['nev'], (string) $wpdb->get_var($wpdb->prepare('SELECT nev FROM ' . $K::t('vevo') . ' WHERE id = %d', (int) $v1['vevo_id'])), 'a meglévő vevő nevét nem írja felül');
T::igaz((int) $v2['licenc_id'] !== (int) $v1['licenc_id'], 'új rendelés: második licenc');

$k3 = $k1;
$k3['rendeles'] = 'R-T94-' . $sfx . '-3';
$k3['vevo']     = ['nev' => 'Harmadik Név T94', 'email' => strtoupper($vevo['email'])];
T::egyezik((int) $v1['vevo_id'], (int) ($KS::feldolgoz($k3)['vevo_id'] ?? 0), 'párosítás e-maillel (kis-nagybetű mindegy)');

$k4 = $k1;
$k4['rendeles'] = 'R-T94-' . $sfx . '-4';
$k4['vevo']     = ['nev' => $vevo['nev'], 'email' => 'negyedik' . $sfx . '@pelda.hu'];
T::egyezik((int) $v1['vevo_id'], (int) ($KS::feldolgoz($k4)['vevo_id'] ?? 0), 'párosítás névvel');

$ures = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $K::t('vevo') . ' WHERE nev = %s', 'Üres Telefonú T94' . $sfx));
$uv   = $K::vevo_ment(['nev' => 'Üres Telefonú T94' . $sfx, 'email' => 'ures' . $sfx . '@pelda.hu']);
$k5   = ['muvelet' => 'uj', 'rendeles' => 'R-T94-' . $sfx . '-5', 'vevo' => ['nev' => 'x', 'email' => 'ures' . $sfx . '@pelda.hu', 'telefon' => '+36 1 234 5678'], 'licenc' => ['modulok' => '*', 'ciklus' => 'havi']];
$v5   = $KS::feldolgoz($k5);
T::igaz($ures === null && (int) $v5['vevo_id'] === (int) $uv, 'meglévő vevő e-maillel');
T::egyezik('+36 1 234 5678', (string) $wpdb->get_var($wpdb->prepare('SELECT telefon FROM ' . $K::t('vevo') . ' WHERE id = %d', (int) $uv)), 'üres mező kitöltve');
T::egyezik('*', $v5['modulok'], 'minden modul');
T::egyezik(wp_date('Y-m-d', (int) strtotime(wp_date('Y-m-d') . ' +1 month')), (string) $v5['lejar'], 'havi: ma + 1 hónap');

T::egyezik(false, $KS::feldolgoz(['muvelet' => 'uj', 'rendeles' => 'R-T94-' . $sfx . '-6', 'vevo' => ['nev' => 'E-mail nélkül'], 'licenc' => []])['ok'], 'e-mail nélkül nincs vevő');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $K::t('rendeles') . ' WHERE rendeles = %s', 'R-T94-' . $sfx . '-6')), 'sikertelen kérés: a foglalás felszabadul (újrapróbálható)');
T::egyezik(false, $KS::feldolgoz(['muvelet' => 'torol', 'rendeles' => 'R-1'])['ok'], 'ismeretlen művelet');
T::egyezik(false, $KS::feldolgoz(['muvelet' => 'uj', 'rendeles' => "R-1'; DROP"])['ok'], 'hibás rendelésazonosító');
T::igaz(!empty($KS::feldolgoz(['muvelet' => 'proba'])['ok']), 'próba művelet');

/* --- Megújítás --------------------------------------------------------------------------------------------------- */

$mk = ['muvelet' => 'megujit', 'rendeles' => 'R-T94-' . $sfx . '-M1', 'licenc_kulcs' => strtolower((string) $v1['licenc_kulcs']), 'vevo' => ['email' => $vevo['email']], 'licenc' => ['ciklus' => 'eves', 'dij' => 250000]];
$mv = $KS::feldolgoz($mk);
$uj = wp_date('Y-m-d', (int) strtotime($varhato . ' +12 month'));
T::igaz(!empty($mv['ok']), 'megújítás: ok (' . (string) ($mv['uzenet'] ?? '') . ')');
T::egyezik($uj, (string) $mv['lejar'], 'megújítás: a lejárattól + 12 hónap');
T::egyezik('', (string) $mv['letoltes_url'], 'megújításnál nincs új letöltő link');
T::egyezik(250000, (int) $wpdb->get_var($wpdb->prepare('SELECT dij FROM ' . $K::t('licenc') . ' WHERE id = %d', (int) $v1['licenc_id'])), 'megújítás: új díj');
T::egyezik($uj, (string) ($KS::feldolgoz($mk)['lejar'] ?? ''), 'megújítás ismételve: nem hosszabbít kétszer');
T::egyezik($uj, (string) $wpdb->get_var($wpdb->prepare('SELECT lejar FROM ' . $K::t('licenc') . ' WHERE id = %d', (int) $v1['licenc_id'])), 'a licenc lejárata egyszer nőtt');
$idegen = $KS::feldolgoz(['muvelet' => 'megujit', 'rendeles' => 'R-T94-' . $sfx . '-M2', 'licenc_kulcs' => (string) $v1['licenc_kulcs'], 'vevo' => ['email' => 'tamado@pelda.hu', 'adoszam' => '99999999-1-11']]);
T::egyezik(false, $idegen['ok'], 'idegen vevő nem újíthatja meg');
T::tartalmaz((string) $idegen['uzenet'], 'nem ehhez a vevőhöz', 'és megmondja, miért');
T::egyezik(false, $KS::feldolgoz(['muvelet' => 'megujit', 'rendeles' => 'R-T94-' . $sfx . '-M3', 'licenc_kulcs' => 'SDH-XXXX-XXXX-XXXX-XXXX', 'vevo' => ['email' => $vevo['email']]])['ok'], 'ismeretlen kulcs');
$m4 = $KS::feldolgoz(['muvelet' => 'megujit', 'rendeles' => 'R-T94-' . $sfx . '-M4', 'licenc_kulcs' => (string) $v1['licenc_kulcs'], 'vevo' => ['adoszam' => '1234' . $sfx . '219']]);
T::igaz(!empty($m4['ok']), 'tulajdonos adószámmal is azonosítható (második megújítás)');
$uj = wp_date('Y-m-d', (int) strtotime($uj . ' +12 month'));
T::egyezik($uj, (string) ($m4['lejar'] ?? ''), 'második megújítás: újabb + 12 hónap');

/* --- Bővítés ----------------------------------------------------------------------------------------------------- */

$bv = $KS::feldolgoz(['muvelet' => 'bovit', 'rendeles' => 'R-T94-' . $sfx . '-B1', 'licenc_kulcs' => (string) $v1['licenc_kulcs'], 'vevo' => ['email' => $vevo['email']], 'licenc' => ['modulok' => ['asszisztens', 'sms'], 'extra_felhasznalo' => 3]]);
T::igaz(!empty($bv['ok']), 'bővítés: ok');
T::egyezik(['sms', 'naptar', 'penztar', 'asszisztens'], $bv['modulok'], 'bővítés: modul hozzáadva, ismétlés nélkül');
T::egyezik(8, (int) $bv['max_felhasznalo'], 'bővítés: +3 felhasználó');
T::egyezik(2, (int) $bv['max_peldany'], 'bővítés: a példányszám marad');
T::egyezik($uj, (string) $bv['lejar'], 'bővítés: a lejárat marad');
T::egyezik(false, $KS::feldolgoz(['muvelet' => 'bovit', 'rendeles' => 'R-T94-' . $sfx . '-B2', 'licenc_kulcs' => (string) $v1['licenc_kulcs'], 'vevo' => ['email' => 'mas@pelda.hu'], 'licenc' => ['modulok' => '*']])['ok'], 'bővítés: idegen vevő elutasítva');
$bv3 = $KS::feldolgoz(['muvelet' => 'bovit', 'rendeles' => 'R-T94-' . $sfx . '-B3', 'licenc_kulcs' => (string) $v1['licenc_kulcs'], 'vevo' => ['email' => $vevo['email']], 'licenc' => ['modulok' => '*', 'max_felhasznalo' => 0]]);
T::igaz($bv3['modulok'] === '*' && (int) $bv3['max_felhasznalo'] === 0, 'bővítés: minden modul, korlátlan felhasználó');
$K::licenc_muvelet((int) $v1['licenc_id'], 'tilt');
T::egyezik(false, $KS::feldolgoz(['muvelet' => 'bovit', 'rendeles' => 'R-T94-' . $sfx . '-B4', 'licenc_kulcs' => (string) $v1['licenc_kulcs'], 'vevo' => ['email' => $vevo['email']], 'licenc' => ['modulok' => ['imei']]])['ok'], 'tiltott licenc: nem bővíthető');
$K::licenc_muvelet((int) $v1['licenc_id'], 'enged');
T::igaz((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $K::t('esemeny') . " WHERE tipus = 'kiszolgalas' AND licenc_id = %d", (int) $v1['licenc_id'])) >= 3, 'napló: kiszolgálási események');

/* --- Egyszer használható letöltés -------------------------------------------------------------------------------- */

$zip = wp_tempnam('t94.zip');
$z   = new ZipArchive();
$z->open($zip, ZipArchive::OVERWRITE);
$z->addFromString('sdh-muhely/sdh-muhely.php', "<?php\n/**\n * Plugin Name: SDH Műhely\n * Version: 9.9.4\n */\n");
$z->close();
T::egyezik('', $K::kiadas_be($zip, 'stabil', 't94', 'teszt'), 'kiadás feltöltve');

$tok = static function (string $url): string {
    parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);

    return (string) ($q['t'] ?? '');
};
$t1 = $tok((string) $v1['letoltes_url']);
$e  = $KS::letoltes_ellenoriz($t1, false);
T::igaz(is_array($e) && $e['verzio'] === '9.9.4' && is_file($e['fajl']), 'a token a licenc csatornájának kiadását adja');
T::egyezik(3, is_array($e) ? $e['maradt'] : -1, 'ellenőrzés nem számol');
for ($i = 1; $i <= 3; $i++) {
    $e = $KS::letoltes_ellenoriz($t1);
    T::igaz(is_array($e) && $e['maradt'] === 3 - $i, $i . '. letöltés');
}
T::igaz(is_string($KS::letoltes_ellenoriz($t1)), '4. letöltés: elutasítva');
T::igaz((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $K::t('esemeny') . " WHERE tipus = 'letoltes' AND licenc_id = %d", (int) $v1['licenc_id'])) === 3, 'letöltések naplózva');
T::igaz(is_string($KS::letoltes_ellenoriz('rossz-token-' . str_repeat('x', 20))), 'ismeretlen token');
T::igaz(is_string($KS::letoltes_ellenoriz("'; DROP TABLE x; --")), 'hibás formátum');
$t2 = $tok($KS::letoltes_link((int) $v1['licenc_id'], 'R-T94-LEJ', time() - 15 * DAY_IN_SECONDS));
T::igaz(is_string($KS::letoltes_ellenoriz($t2)), '14 nap után lejárt');
$t3 = $tok($KS::letoltes_link((int) $v1['licenc_id'], 'R-T94-TILT'));
$K::licenc_muvelet((int) $v1['licenc_id'], 'tilt');
T::tartalmaz((string) $KS::letoltes_ellenoriz($t3), 'licenchez', 'tiltott licenc: nincs letöltés');
$K::licenc_muvelet((int) $v1['licenc_id'], 'enged');
T::igaz(is_array($KS::letoltes_ellenoriz($t3, false)), 'újra engedve: letölthető');
T::nem_tartalmaz((string) wp_json_encode($wpdb->get_results('SELECT * FROM ' . $K::t('letoltes'))), $t3, 'a token csak lenyomatként tárolva');
$r = new WP_REST_Request('GET', '/sdh-kozpont/v1/letoltes');
$r->set_query_params(['t' => 'nincs-ilyen-token-' . str_repeat('a', 10)]);
T::egyezik(410, rest_do_request($r)->get_status(), 'REST: érvénytelen token → 410');
$r = new WP_REST_Request('GET', '/sdh-kozpont/v1/csomag');
$r->set_query_params(['kulcs' => (string) $v1['licenc_kulcs'], 'peldany' => 'nincs']);
T::egyezik(403, rest_do_request($r)->get_status(), 'a régi csomag-végpont változatlan (példány nélkül nem ad)');

/* --- Aláírt REST-végpont ----------------------------------------------------------------------------------------- */

$kp    = sodium_crypto_sign_keypair();
$titok = sodium_crypto_sign_secretkey($kp);
$nyilv = base64_encode(sodium_crypto_sign_publickey($kp));

$kuld = static function (array $adat, ?string $titkos = null, ?callable $babral = null) use ($titok): WP_REST_Response {
    $raw  = (string) wp_json_encode($adat);
    $test = ['adat' => base64_encode($raw), 'alairas' => base64_encode(sodium_crypto_sign_detached($raw, $titkos ?? $titok))];

    if ($babral !== null) {
        $test = $babral($test);
    }

    $r = new WP_REST_Request('POST', '/sdh-kozpont/v1/kiszolgalas');
    $r->set_header('Content-Type', 'application/json');
    $r->set_body((string) wp_json_encode($test));

    return rest_do_request($r);
};
$nonce = static fn (): string => bin2hex(random_bytes(16));
$keres = static fn (array $k) => $k + ['ido' => time(), 'nonce' => $nonce()];

$v = $kuld($keres(['muvelet' => 'proba']));
T::egyezik(401, $v->get_status(), 'kulcs nélkül: 401');
T::nem_tartalmaz((string) wp_json_encode($v->get_data()), 'kulcs', 'a 401 nem árul el részletet');

$b = $K::beallitas_ment(['ertekesito_kulcs' => 'nem-base64!!']);
T::egyezik('', $b['ertekesito_kulcs'], 'érvénytelen kulcs nem mentődik');
$b = $K::beallitas_ment(['ertekesito_kulcs' => chunk_split($nyilv, 10, "\n"), 'ertekesito_ip' => '203.0.113.0/24, rossz-ip, 2001:db8::/32']);
T::egyezik($nyilv, $b['ertekesito_kulcs'], 'kulcs mentve (szóközök nélkül)');
T::egyezik('203.0.113.0/24, 2001:db8::/32', $b['ertekesito_ip'], 'IP-lista tisztítva');
T::igaz($KS::ip_engedett('203.0.113.50', $b['ertekesito_ip']) && !$KS::ip_engedett('198.51.100.1', $b['ertekesito_ip']) && $KS::ip_engedett('2001:db8::1', $b['ertekesito_ip']), 'CIDR-ellenőrzés (IPv4, IPv6)');

$app = new WP_REST_Request('GET', '/sdh-kozpont/v1/app/beallitas');
$ad  = rest_do_request($app)->get_data();
T::igaz(($ad['b']['ertekesito_kulcs'] ?? '') === $nyilv && !empty($ad['kulcs']), 'a felület látja: értékesítői kulcs + a Központ kulcsa');

$n1  = $nonce();
$ok  = ['muvelet' => 'uj', 'rendeles' => 'R-T94-' . $sfx . '-REST', 'vevo' => ['nev' => 'REST Vevő T94', 'email' => 'rest' . $sfx . '@pelda.hu'], 'licenc' => ['modulok' => ['sms'], 'max_peldany' => 1], 'ido' => time(), 'nonce' => $n1];
$v   = $kuld($ok);
T::egyezik(200, $v->get_status(), 'jó aláírás: 200');
$d   = (array) $v->get_data();
$raw = (string) base64_decode((string) ($d['adat'] ?? ''), true);
T::igaz(sodium_crypto_sign_verify_detached((string) base64_decode((string) $d['alairas'], true), $raw, $K::kulcspar()['nyilvanos']), 'a válasz a Központ kulcsával aláírva');
$va  = (array) json_decode($raw, true);
T::igaz(!empty($va['ok']) && $va['nonce'] === $n1 && abs((int) $va['ido'] - time()) < 5, 'a válaszban: ok, a kérés nonce-a, idő');
T::igaz(!empty($va['licenc_kulcs']) && !empty($va['letoltes_url']), 'a válaszban: kulcs és letöltő link');

T::egyezik(401, $kuld($ok)->get_status(), 'visszajátszás (ugyanaz a nonce): 401');
T::egyezik(401, $kuld($keres(['muvelet' => 'proba', 'ido' => time() - 301])) ->get_status(), 'régi időbélyeg (−301 mp): 401');
T::egyezik(401, $kuld(['muvelet' => 'proba', 'ido' => time() + 400, 'nonce' => $nonce()])->get_status(), 'jövőbeli időbélyeg: 401');
T::egyezik(200, $kuld(['muvelet' => 'proba', 'ido' => time() - 250, 'nonce' => $nonce()])->get_status(), 'tűrésen belül (−250 mp): 200');
T::egyezik(401, $kuld(['muvelet' => 'proba', 'ido' => time()])->get_status(), 'nonce nélkül: 401');
T::egyezik(401, $kuld($keres(['muvelet' => 'proba']), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))->get_status(), 'idegen kulccsal aláírva: 401');
T::egyezik(401, $kuld($keres(['muvelet' => 'proba']), null, static function (array $t): array {
    $a = (array) json_decode((string) base64_decode($t['adat']), true);
    $a['muvelet'] = 'uj';
    $t['adat'] = base64_encode((string) wp_json_encode($a));

    return $t;
})->get_status(), 'módosított adat (aláírás után): 401');
T::egyezik(401, $kuld($keres(['muvelet' => 'proba']), null, static fn (array $t): array => ['adat' => $t['adat']])->get_status(), 'aláírás nélkül: 401');
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
T::egyezik(401, $kuld($keres(['muvelet' => 'proba']))->get_status(), 'nem engedélyezett IP: 401');
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
T::igaz((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $K::t('esemeny') . " WHERE tipus = 'kiszolgalas' AND szoveg LIKE 'Elutasított%'") >= 3, 'elutasítások a naplóban (okkal)');

// Ismételt rendelés új nonce-szal: idempotens válasz.
$ok['nonce'] = $nonce();
$ok['ido']   = time();
$v           = (array) json_decode((string) base64_decode((string) $kuld($ok)->get_data()['adat']), true);
T::igaz(!empty($v['ismetles']) && $v['licenc_kulcs'] === $va['licenc_kulcs'], 'REST: ismételt rendelés → ugyanaz a licenc');

// Takarítás: a lejárt nonce törlődik.
$KS::takarit(time() + 3600);
T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $K::t('nonce')), 'takarítás: lejárt nonce-ok törölve');

$K::beallitas_ment(['ertekesito_kulcs' => '', 'ertekesito_ip' => '']);
T::egyezik('', $K::beallitas()['ertekesito_kulcs'], 'kulcs törölhető');
@unlink($zip);

T::vege('t94 központ 2.1: kiszolgálás, aláírt végpont, letöltő link');
