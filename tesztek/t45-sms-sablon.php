<?php
/**
 * t45 – SMS sablonok, szabályok, „Küldjek SMS-t?” döntések, kézi küldés, csevegő-bekötés,
 * az SMS menüpont oldalai és a jogosultságok.
 *
 * A hamis átjárót (tesztek/sms-mock.php) a teszt maga indítja. Valódi SMS nem megy ki.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$S    = 'SDH_Muhely_Sms';
$Z    = 'SDH_Muhely_Sms_Szabaly';
$F    = 'SDH_Muhely_Sms_Felulet';
$M    = 'SDH_Muhely_Munkalap';
$port = 8093;
$fajl = sys_get_temp_dir() . '/sdh-sms-mock-t45.json';
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

// Tiszta lap.
foreach (['uzenet', 'esemeny', 'sablon', 'sablon_verzio', 'szabaly', 'dontes'] as $t) {
    $wpdb->query('DELETE FROM ' . $S::tabla($t));
}

delete_option($Z::OPT_PELDAK);
delete_option($S::OPT_ALLAPOT);
delete_option('sdh_muhely_sms_zar');
$beallit();

/* --- Minta-sablonok ------------------------------------------------------- */

$Z::peldak_felvesz();
$peldak = $Z::sablonok();
T::egyezik(4, count($peldak), 'négy minta-sablon készül');
T::egyezik(0, count(array_filter($peldak, static fn ($s) => (int) $s->aktiv === 1)), 'a minták KIKAPCSOLVA kerülnek be');
T::egyezik(4, count(array_filter($peldak, static fn ($s) => (int) $s->pelda === 1)), 'a minták „példa” jelzésűek');
$Z::peldak_felvesz();
T::egyezik(4, count($Z::sablonok()), 'a minták nem duplázódnak');
T::egyezik(4, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $S::tabla('sablon_verzio')), 'minden mintának van 1. verziója');

foreach ($peldak as $p) {
    preg_match_all('/\{([a-z_]+)\}/', (string) $p->szoveg, $m);
    T::egyezik([], array_values(array_diff($m[1], array_keys($Z::valtozok()))), 'a minta csak létező változót használ: ' . $p->kulcs);
    T::nem_tartalmaz((string) $p->szoveg, 'Kft', 'a mintában nincs cégnév: ' . $p->kulcs);
}

/* --- Sablon mentése, verziók, érvényesítés ---------------------------------- */

$kesz = null;

foreach ($peldak as $p) {
    if ($p->kulcs === 'kesz') {
        $kesz = $p;
    }
}

$v = $Z::sablon_ment(['nev' => 'Elkészült', 'szoveg' => $kesz->szoveg, 'aktiv' => 1], (int) $kesz->id);
$s = $Z::sablon((int) $kesz->id);
T::egyezik(true, $v['ok'], 'a minta jóváhagyható (mentés)');
T::egyezik(0, (int) $s->pelda, 'mentés után már nem „példa”');
T::egyezik(1, (int) $s->aktiv, 'mentés után aktív');
T::egyezik(2, (int) $s->verzio, 'átnevezés új verziót ad');
$Z::sablon_ment(['nev' => 'Elkészült', 'szoveg' => $kesz->szoveg, 'aktiv' => 1], (int) $kesz->id);
T::egyezik(2, (int) $Z::sablon((int) $kesz->id)->verzio, 'változatlan tartalom nem ad új verziót');
T::egyezik(2, count($Z::verziok((int) $kesz->id)), 'a verziótörténet 2 sor');

T::egyezik(false, $Z::sablon_ment(['nev' => '', 'szoveg' => 'x'])['ok'], 'név nélkül nem menthető');
T::egyezik(false, $Z::sablon_ment(['nev' => 'Üres', 'szoveg' => '  '])['ok'], 'üres szöveg nem menthető');
$rossz = $Z::sablon_ment(['nev' => 'Rossz', 'szoveg' => 'Szia {nincsilyen}!']);
T::egyezik(false, $rossz['ok'], 'ismeretlen változó nem menthető');
T::tartalmaz($rossz['uzenet'], '{nincsilyen}', 'a hiba megnevezi a változót');
T::egyezik(false, $Z::sablon_ment(['nev' => 'Hosszú', 'szoveg' => str_repeat('a', 481)])['ok'], '481 karakter már túl hosszú');

$egyszeru = $Z::sablon_ment(['nev' => 'Egyszerű', 'szoveg' => 'Szia {ugyfel}, kész.', 'aktiv' => 1]);
T::egyezik(true, $egyszeru['ok'], 'új sablon létrejön');
$egy_id = $egyszeru['id'];
$link_sablon = $Z::sablon_ment(['nev' => 'Linkes', 'szoveg' => 'Követés: {link} ({szam})', 'aktiv' => 1]);
$kikapcs = $Z::sablon_ment(['nev' => 'Kikapcsolt', 'szoveg' => 'Ez nem aktív.', 'aktiv' => 0]);

/* --- Kitöltés --------------------------------------------------------------- */

$u  = t_ugyfel('Sms Szabály', ['telefon' => '06 30 111 2222']);
$e1 = t_eszkoz($u, 'Apple', 'iPhone 12');
$id = t_munkalap($u, [$e1], 'nyitott');
$lap = t_lap($id);
T::igaz((int) $lap->munkalap_szam > 0, 'a teszt-munkalap kapott számot');

$ert = $Z::ertekek($id, 0, 'lap', 'elkeszult');
T::tartalmaz($ert['ugyfel'], 'Sms Szabály', 'érték: ügyfél');
T::tartalmaz($ert['eszkoz'], 'iPhone 12', 'érték: eszköz');
T::egyezik('', $ert['hatarido'] === '' ? '' : '', 'érték: határidő nélkül üres');
T::tartalmaz($ert['link'], SDH_Muhely_Rma::token(t_lap($id)), 'érték: link a laptokennel');
T::egyezik(SDH_Muhely_Munkalap::allapotok()['elkeszult']['nev'], $ert['allapot'], 'érték: az új állapot neve');

$k = $Z::kitolt((string) $kesz->szoveg, $ert);
T::egyezik([], $k['hianyzik'], 'a minta minden változója kitölthető');
T::nem_tartalmaz($k['szoveg'], '{', 'kitöltött szöveg: nem marad kapcsos zárójel');
T::tartalmaz($k['szoveg'], 'iPhone 12', 'kitöltött szöveg: eszköz');

$k = $Z::kitolt('Hiányos: {link} {hatarido} {osszeg} {valami}', $ert);
T::igaz(in_array('hatarido', $k['hianyzik'], true), 'üres változó „hiányzik”-ként jelzett');
T::egyezik(['valami'], $k['ismeretlen'], 'ismeretlen változó jelzett');

$u0 = $Z::ertekek(0, 0, 'lap', '', $u);
T::tartalmaz($u0['ugyfel'], 'Sms Szabály', 'munkalap nélkül is van ügyfélnév');
T::egyezik('', $u0['szam'] ?? '', 'munkalap nélkül nincs szám');

/* --- Szabályok -------------------------------------------------------------- */

$db = $Z::szabalyok_ment('lap', [
    'elkeszult' => ['sablon_id' => $kesz->id, 'mukodes' => 'kerdez', 'kesleltetes' => 0],
    'arajanlat' => ['sablon_id' => $link_sablon['id'], 'mukodes' => 'auto', 'kesleltetes' => 3],
    'fuggo'     => ['sablon_id' => $kikapcs['id'], 'mukodes' => 'kerdez'],
    'nincsilyen' => ['sablon_id' => $kesz->id, 'mukodes' => 'kerdez'],
    'sablon'    => ['sablon_id' => $kesz->id, 'mukodes' => 'ki'],
    'reszben'   => ['sablon_id' => 99999, 'mukodes' => 'kerdez'],
]);
T::egyezik(3, $db, 'csak az érvényes szabályok mentődnek (rossz állapot / „ki” / nemlétező sablon kimarad)');
T::igaz($Z::szabaly('lap', 'elkeszult') !== null, 'szabály: kérdez');
T::egyezik(null, $Z::szabaly('lap', 'lezart'), 'szabály nélküli állapot: nincs');
T::egyezik(null, $Z::szabaly('lap', 'fuggo'), 'kikapcsolt sablonhoz kötött szabály nem él');
T::egyezik('auto', (string) $Z::szabaly('lap', 'arajanlat')->mukodes, 'szabály: automata');

/* --- Státuszváltás → döntés ------------------------------------------------- */

$M::allapot_valt($id, 'elkeszult');
$dontes_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $S::tabla('dontes') . ' WHERE munkalap_id = %d', $id));
T::igaz($dontes_id > 0, 'státuszváltás után döntés keletkezik');
$d = $dont($dontes_id);
T::egyezik('fuggo', $d->dontes, 'a döntés függő');
T::egyezik('elkeszult', $d->allapot, 'a döntés az új állapotot őrzi');
T::egyezik((int) $kesz->id, (int) $d->sablon_id, 'a szabály sablonja');
T::egyezik(1, $Z::fuggo_db(), 'egy függő döntés');

$M::allapot_valt($id, 'nyitott');
$M::allapot_valt($id, 'elkeszult');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $S::tabla('dontes') . ' WHERE munkalap_id = %d', $id)), 'ugyanarra az állapotra nincs második nyitott kérdés');
$wpdb->query($wpdb->prepare('DELETE FROM ' . $S::tabla('dontes') . ' WHERE munkalap_id = %d AND id <> %d', $id, $dontes_id));

// A popup
$h = t_ajax('sdh_muhely_smsdontes_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $dontes_id])['nyers'];
T::tartalmaz($h, 'Küldjek SMS-t az ügyfélnek?', 'popup: cím');
T::tartalmaz($h, 'Sms Szabály', 'popup: ügyfél neve');
T::tartalmaz($h, '+36 30 111 2222', 'popup: a szám szépen formázva');
T::tartalmaz($h, 'SMS küldése', 'popup: Küldés gomb');
T::tartalmaz($h, 'Nem küldök', 'popup: Nem küldök gomb');
T::tartalmaz($h, 'Később döntök', 'popup: Később döntök gomb');
T::tartalmaz($h, 'iPhone 12', 'popup: a szöveg ki van töltve');
T::nem_tartalmaz($h, '{eszkoz}', 'popup: nem marad kitöltetlen változó');
T::tartalmaz($h, 'sdh_muhely_sms_dontes_ment', 'popup: a beküldés a döntés-műveletre megy');

$k = t_ajax('sdh_muhely_sms_dontesek', [], '_wpnonce', 'sdh_muhely_modal');
T::egyezik($dontes_id, (int) $k['data']['friss'], 'a böngésző a friss döntést kapja');
T::egyezik(1, (int) $k['data']['fuggo'], 'és a függők számát');

// Később döntök
$r = t_ajax('sdh_muhely_sms_dontes_ment', ['id' => $dontes_id, 'valasz' => 'kesobb']);
T::igaz(!empty($r['success']), 'később döntök: sikeres');
T::egyezik('fuggo', $dont($dontes_id)->dontes, 'később döntök: a döntés nyitva marad');

// Kitöltetlen változó nem megy ki
$r = t_ajax('sdh_muhely_sms_dontes_ment', ['id' => $dontes_id, 'valasz' => 'kuld', 'szoveg' => 'Szia {ugyfel}!']);
T::egyezik(false, !empty($r['success']), 'kitöltetlen {változó} nem küldhető');
T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $S::tabla('uzenet')), 'és nem került a sorba');

// Küldés
$r = t_ajax('sdh_muhely_sms_dontes_ment', ['id' => $dontes_id, 'valasz' => 'kuld', 'szoveg' => 'Kész a telefonja, átvehető.']);
T::igaz(!empty($r['success']), 'küldés: sikeres');
$d = $dont($dontes_id);
T::egyezik('kuldve', $d->dontes, 'küldés: a döntés „kuldve”');
T::igaz($d->lezarva !== null, 'küldés: lezárva');
$sms = $sor((int) $d->sms_id);
T::egyezik('allapot', $sms->kivalto, 'az SMS kiváltója: státuszváltás');
T::egyezik($id, (int) $sms->munkalap_id, 'az SMS a laphoz kötött');
T::egyezik((int) $kesz->id, (int) $sms->sablon_id, 'az SMS őrzi a sablont');
T::egyezik((int) $Z::sablon((int) $kesz->id)->verzio, (int) $sms->sablon_verzio, 'és a sablon verzióját');
T::egyezik('Kész a telefonja, átvehető.', $sms->szoveg, 'és a pontos (szerkesztett) szöveget');

$r2 = t_ajax('sdh_muhely_sms_dontes_ment', ['id' => $dontes_id, 'valasz' => 'kuld', 'szoveg' => 'Kész a telefonja, átvehető.']);
T::egyezik(1, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $S::tabla('uzenet') . ' WHERE munkalap_id = ' . $id), 'dupla kattintás nem küld második SMS-t');

$uz = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('uzenet') . ' WHERE sms_id = %d', (int) $d->sms_id));
T::igaz(is_object($uz), 'az SMS bekerült a lap üzenetszálába');
T::egyezik('sms', (string) $uz->csatorna, 'a szál csatornája: sms');
T::egyezik('ki', (string) $uz->irany, 'kimenő');
T::tartalmaz(SDH_Muhely_Rma::uzenetek_html($id), 'SMS', 'a szál jelzi az SMS-t');

$S::feldolgoz();
$l = $lista();
T::egyezik(1, count($l), 'a feldolgozó elküldte (az átjáró 1 üzenetet kapott)');
T::egyezik('Kész a telefonja, átvehető.', $l[0]['text'], 'a szerkesztett szöveg ment ki');
T::egyezik('+36301112222', $l[0]['szam'], 'a vevő 1. számára');

// Nem küldök
$M::allapot_valt($id, 'nyitott');
$M::allapot_valt($id, 'elkeszult');
$d2 = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $S::tabla('dontes') . ' WHERE munkalap_id = %d AND lezarva IS NULL', $id));
T::igaz($d2 > 0 && $d2 !== $dontes_id, 'új váltás új kérdést ad');
$r = t_ajax('sdh_muhely_sms_dontes_ment', ['id' => $d2, 'valasz' => 'nem']);
T::egyezik('nem', $dont($d2)->dontes, 'nem küldök: rögzítve');
T::egyezik(0, $Z::fuggo_db(), 'nincs függő döntés');
$S::feldolgoz();
T::egyezik(1, count($lista()), 'nem küldök: nem ment ki újabb SMS');

// Főkapcsoló KI: nincs kérdés
$beallit(['be' => false]);
$M::allapot_valt($id, 'nyitott');
$M::allapot_valt($id, 'elkeszult');
T::egyezik(0, $Z::fuggo_db(), 'főkapcsoló KI: nem keletkezik döntés');
$beallit();

/* --- Automata működés ---------------------------------------------------------- */

$u2 = t_ugyfel('Auto Aladár', ['telefon' => '06 20 333 4444']);
$e2 = t_eszkoz($u2, 'Samsung', 'S21');
$id2 = t_munkalap($u2, [$e2], 'nyitott');
$M::allapot_valt($id2, 'arajanlat');
$da = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('dontes') . ' WHERE munkalap_id = %d', $id2));
T::igaz(is_object($da), 'automata szabály: döntés keletkezik');
T::egyezik('auto', $da->dontes, 'automata: azonnal lezárt, „auto”');
$sa = $sor((int) $da->sms_id);
T::egyezik('auto', $sa->kivalto, 'automata: az SMS kiváltója „auto”');
T::tartalmaz($sa->szoveg, SDH_Muhely_Rma::token(t_lap($id2)), 'automata: a szöveg ki van töltve (link)');
T::igaz(strtotime((string) $sa->kovetkezo) >= time() + 150, 'automata: a 3 perces késleltetés érvényes');

// Hiányzó adat → nem megy automatikusan, rákérdez
$id3 = t_munkalap($u2, [$e2], 'arajanlat');
$wpdb->query($wpdb->prepare('DELETE FROM ' . $S::tabla('dontes') . ' WHERE munkalap_id = %d', $id3));
T::egyezik(0, (int) t_lap($id3)->munkalap_szam, 'számozatlan állapotú lapnak nincs száma');
$M::allapot_valt($id3, 'sablon');
$M::allapot_valt($id3, 'arajanlat');
$d3 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('dontes') . ' WHERE munkalap_id = %d', $id3));
T::igaz(is_object($d3), 'hiányos adatnál is keletkezik döntés');
T::egyezik('fuggo', $d3->dontes, 'hiányzó {szam}/{link}: nem küld automatikusan, rákérdez');
T::tartalmaz((string) $d3->ok, 'hiányzó adat', 'és megmondja, miért');
T::egyezik(0, (int) $d3->sms_id, 'nem készült SMS');

/* --- Több eszközös lap: eszközönkénti kérdés -------------------------------------- */

$u4 = t_ugyfel('Két Eszköz', ['telefon' => '06 70 555 6666']);
$ea = t_eszkoz($u4, 'Apple', 'iPad');
$eb = t_eszkoz($u4, 'Apple', 'iPhone SE');
$id4 = t_munkalap($u4, [$ea, $eb], 'nyitott');
$wpdb->query($wpdb->prepare('DELETE FROM ' . $S::tabla('dontes') . ' WHERE munkalap_id = %d', $id4));
$M::eszkoz_allapot_valt($id4, $eb, 'elkeszult');
$d4 = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $S::tabla('dontes') . ' WHERE munkalap_id = %d', $id4));
T::egyezik(1, count($d4), 'több eszköz: egy kérdés az átállított eszközről');
T::egyezik($eb, (int) $d4[0]->eszkoz_id, 'a kérdés az eszközhöz kötött');
$ert4 = $Z::ertekek($id4, $eb, 'lap', 'elkeszult');
T::tartalmaz($ert4['eszkoz'], 'iPhone SE', 'a szöveg az átállított eszköz nevét kapja');
T::nem_tartalmaz($ert4['eszkoz'], 'iPad', 'és nem a másikét');

/* --- Rendelés ---------------------------------------------------------------------- */

$Z::szabalyok_ment('rendeles', ['megerkezett' => ['sablon_id' => $egy_id, 'mukodes' => 'kerdez', 'kesleltetes' => 0]]);
$u5 = t_ugyfel('Rendelő Rita', ['telefon' => '06 30 777 8888']);
$rid = SDH_Muhely_Munkalap::letrehoz(['fajta' => 'rendeles', 'ugyfel_id' => $u5, 'allapot' => 'nyitott', 'rend_allapot' => 'felveve', 'keszult' => current_time('Y-m-d')]);
T::igaz(is_int($rid), 'teszt-rendelés létrejött');
SDH_Muhely_Eladas::rend_allapot_valt((int) $rid, 'megerkezett');
$dr = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('dontes') . ' WHERE munkalap_id = %d', (int) $rid));
T::igaz(is_object($dr), 'rendelés útja: döntés keletkezik');
T::egyezik('rendeles', (string) $dr->terulet, 'a döntés területe: rendelés');
$hr = t_ajax('sdh_muhely_smsdontes_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => (int) $dr->id])['nyers'];
T::tartalmaz($hr, 'Szia ' . $Z::ertekek((int) $rid)['ugyfel'] . ', kész.', 'a rendelés-popup szövege kitöltött');
SDH_Muhely_Eladas::rend_allapot_valt((int) $rid, 'ertesitve');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $S::tabla('dontes') . ' WHERE munkalap_id = %d', (int) $rid)), 'szabály nélküli rendelés-állapot: nincs kérdés');

/* --- Kézi küldés ------------------------------------------------------------------ */

$db_elotte = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $S::tabla('uzenet'));
$uj = t_ajax('sdh_muhely_smsuj_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['munkalap_id' => $id])['nyers'];
T::tartalmaz($uj, 'Új SMS', 'új SMS popup: cím');
T::tartalmaz($uj, '+36 30 111 2222', 'új SMS popup: az ügyfél száma');
T::tartalmaz($uj, 'data-sdh-sms-sablon', 'új SMS popup: sablonválasztó');
T::nem_tartalmaz($uj, '— szabad szöveg —</option><option value="' . $kikapcs['id'] . '"', 'kikapcsolt sablon nem választható');
$onall = t_ajax('sdh_muhely_smsuj_urlap', [], '_wpnonce', 'sdh_muhely_modal')['nyers'];
T::tartalmaz($onall, 'Önálló SMS', 'önálló SMS: cím');
T::tartalmaz($onall, 'required', 'önálló SMS: kötelező szám');

$r = t_ajax('sdh_muhely_sms_kuld', ['munkalap_id' => $id, 'szoveg' => 'Kézi üzenet.']);
T::igaz(!empty($r['success']), 'kézi SMS lapról: sikeres');
$ks = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE szoveg = %s', 'Kézi üzenet.'));
T::egyezik('kezi', $ks->kivalto, 'kézi kiváltó');
T::egyezik($u, (int) $ks->ugyfel_id, 'az ügyfél a lapról jön');

$r = t_ajax('sdh_muhely_sms_kuld', ['szam' => '06 30 999 1234', 'szoveg' => 'Önálló.']);
T::igaz(!empty($r['success']), 'önálló SMS számra: sikeres');
$os = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE szoveg = %s', 'Önálló.'));
T::egyezik('+36309991234', $os->szam, 'önálló SMS: a megadott szám');
T::egyezik(0, (int) $os->munkalap_id, 'önálló SMS: nincs laphoz kötve');

T::egyezik(false, !empty(t_ajax('sdh_muhely_sms_kuld', ['munkalap_id' => $id, 'szoveg' => ''])['success']), 'üres szöveg nem küldhető');
T::egyezik(false, !empty(t_ajax('sdh_muhely_sms_kuld', ['szoveg' => 'Szám nélkül.'])['success']), 'szám és ügyfél nélkül nem küldhető');
T::egyezik(false, !empty(t_ajax('sdh_muhely_sms_kuld', ['szam' => '0', 'szoveg' => 'Rossz szám.'])['success']), 'érvénytelen szám nem küldhető');
$rossz_szam = t_ajax('sdh_muhely_sms_kuld', ['szam' => '0', 'szoveg' => 'Rossz szám.']);
T::tartalmaz((string) ($rossz_szam['data']['uzenet'] ?? ''), 'Nincs használható telefonszám', 'érvénytelen szám: érthető hiba');
T::egyezik(false, !empty(t_ajax('sdh_muhely_sms_kuld', ['munkalap_id' => $id, 'szoveg' => str_repeat('x', 481)])['success']), '481 karakteres SMS nem küldhető');

// Sablon kitöltése a popupban
$el = t_ajax('sdh_muhely_sms_elonezet', ['sablon_id' => $egy_id, 'munkalap_id' => $id]);
T::egyezik('Szia ' . $Z::ertekek($id)['ugyfel'] . ', kész.', $el['data']['szoveg'], 'előnézet: a sablon kitöltve');
$el = t_ajax('sdh_muhely_sms_elonezet', ['sablon_id' => $kesz->id, 'ugyfel_id' => $u]);
T::tartalmaz((string) $el['data']['uzenet'], 'nem tölthető ki', 'előnézet: munkalap nélkül jelzi a hiányt');

/* --- Csevegő: „SMS-ben is” ---------------------------------------------------------- */

$db_elotte = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('uzenet') . ' WHERE munkalap_id = ' . $id);
$r = t_ajax('sdh_muhely_uzenet_kuld', ['id' => $id, 'szoveg' => 'Csevegő SMS-sel.', 'sms' => '1']);
T::igaz(!empty($r['success']), 'csevegő: üzenet elment');
T::igaz(!empty($r['data']['sms']['ok']), 'csevegő: az SMS is sorba került');
T::egyezik($db_elotte + 1, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('uzenet') . ' WHERE munkalap_id = ' . $id), 'csevegő: egyetlen szálsor készült (nincs dupla)');
$sz = $wpdb->get_row("SELECT * FROM " . SDH_Muhely_Schema::tabla('uzenet') . " WHERE szoveg = 'Csevegő SMS-sel.'");
T::egyezik('crm', (string) $sz->csatorna, 'csevegő: a sor csatornája crm marad');
T::igaz((int) $sz->sms_id > 0, 'csevegő: a szálsor az SMS-hez kötött');

$r = t_ajax('sdh_muhely_uzenet_kuld', ['id' => $id, 'szoveg' => 'Csevegő SMS nélkül.']);
T::igaz($r['data']['sms'] === null, 'csevegő: jelölő nélkül nincs SMS');
$sz2 = $wpdb->get_row("SELECT * FROM " . SDH_Muhely_Schema::tabla('uzenet') . " WHERE szoveg = 'Csevegő SMS nélkül.'");
T::egyezik(0, (int) $sz2->sms_id, 'csevegő: jelölő nélkül nincs SMS-kötés');

$r = t_ajax('sdh_muhely_uzenet_kuld', ['id' => $id, 'szoveg' => str_repeat('hosszú ', 100), 'sms' => '1']);
T::igaz(!empty($r['success']) && empty($r['data']['sms']['ok']), 'csevegő: túl hosszú SMS nem megy, de az üzenet elmegy');

$szal_html = SDH_Muhely_Rma::uzenetek_html($id);
T::tartalmaz($szal_html, 'sdh-uz__jel--sms', 'a szál SMS-jelzést mutat');

$levelek_elotte = count($levelek);
$kompozer = (string) call_user_func(static function () use ($id) {
    ob_start();
    $lap = SDH_Muhely_Rma::munkalap($id);
    $r = new ReflectionMethod('SDH_Muhely_Rma', 'sms_jelolo');
    $r->setAccessible(true);
    echo $r->invoke(null, SDH_Muhely_Rma::ugyfel((int) $lap->ugyfel_id));

    return ob_get_clean();
});
T::tartalmaz($kompozer, 'data-sdh-uzenet-sms', 'a csevegő beírójában ott az „SMS-ben is” jelölő');
T::tartalmaz($kompozer, '+36 30 111 2222', 'a jelölő mutatja a számot');
T::nem_tartalmaz($kompozer, 'checked', 'a jelölő alapból nincs bejelölve');
$beallit(['be' => false]);
T::egyezik('', (string) call_user_func(static function () use ($id) {
    $r = new ReflectionMethod('SDH_Muhely_Rma', 'sms_jelolo');
    $r->setAccessible(true);

    return $r->invoke(null, SDH_Muhely_Rma::ugyfel((int) SDH_Muhely_Rma::munkalap($id)->ugyfel_id));
}), 'SMS kikapcsolva: nincs jelölő a csevegőben');
$beallit();

/* --- Az SMS menüpont oldalai ---------------------------------------------------------- */

T::igaz(SDH_Muhely_Modulok::egy('sms') !== null, 'az SMS modul bejegyzett');
$mon = $oldal('monitor');
T::tartalmaz($mon, 'Kézi üzenet.', 'monitor: a kézi SMS látszik');
T::tartalmaz($mon, 'Kézbesítve', 'monitor: állapotcímke') ?: null;
T::tartalmaz($mon, 'Monitor', 'monitor: lapfül');
T::tartalmaz($mon, 'Bejövő SMS', 'monitor: bejövő lapfül');
T::tartalmaz($mon, 'Hívandók', 'monitor: hívandók lapfül');
T::tartalmaz($mon, 'data-sdh-id="0"', 'monitor: „Új SMS” gomb');
$szuro = $oldal('monitor', ['k' => 'Önálló']);
T::tartalmaz($szuro, 'Önálló.', 'monitor: szűrés szövegre');
T::nem_tartalmaz($szuro, 'Kézi üzenet.', 'monitor: a szűrő kizárja a többit');
$szuro = $oldal('monitor', ['allapot' => 'delivered']);
T::nem_tartalmaz($szuro, 'Kézi üzenet.', 'monitor: szűrés állapotra');

$sab = $oldal('sablonok');
T::tartalmaz($sab, 'PÉLDA – jóváhagyásra vár', 'sablonok: a minta jelölése');
T::tartalmaz($sab, 'Árajánlat kész', 'sablonok: a minta neve');
T::tartalmaz($sab, 'Mikor ajánljon SMS-t?', 'sablonok: szabályok');
T::tartalmaz($sab, 'data-sdh-sms-szabalyok', 'sablonok: szabály-űrlap');
T::tartalmaz($sab, 'Szabályok mentése', 'sablonok: mentés gomb adminnak');

$bej = $oldal('bejovo');
T::tartalmaz($bej, 'Még nem érkezett SMS', 'bejövő: üres állapot');
$don = $oldal('dontesek');
T::tartalmaz($don, 'Döntés', 'függő döntések: gomb') ?: null;

// Hívandók
$hiv_elotte = $F::hivando_db();
$wpdb->update($S::tabla('uzenet'), ['allapot' => 'manualis', 'hiba' => 'Teszt hiba'], ['id' => (int) $ks->id]);
$hiv = $oldal('hivando');
T::tartalmaz($hiv, 'Kézi üzenet.', 'hívandók: a nem kiment SMS ott van');
T::tartalmaz($hiv, 'Felhívtam', 'hívandók: Felhívtam gomb');
T::egyezik($hiv_elotte + 1, $F::hivando_db(), 'hívandók száma');
T::igaz($F::jelveny() >= 1, 'menü-jelvény: teendő van');
$r = t_ajax('sdh_muhely_sms_hivott', ['id' => (int) $ks->id]);
T::igaz(!empty($r['success']), 'Felhívtam: rögzítve');
T::egyezik($hiv_elotte, $F::hivando_db(), 'Felhívtam után csökken a hívandók száma');
T::nem_tartalmaz($oldal('hivando'), 'Kézi üzenet.', 'elintézett sor eltűnik a listából');
T::tartalmaz($oldal('hivando', ['mind' => 1]), 'Felhívva:', 'az elintézettek is megnézhetők');
T::igaz((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $S::tabla('esemeny') . " WHERE sms_id = %d AND tipus = 'hivas'", (int) $ks->id)) === 1, 'a „Felhívtam” az eseménynaplóban');

/* --- Szabályok mentése AJAX-szal, jogosultságok -------------------------------------- */

$r = t_ajax('sdh_muhely_sms_szabaly_ment', ['lap' => ['elkeszult' => ['sablon_id' => (string) $egy_id, 'mukodes' => 'auto', 'kesleltetes' => '0']]]);
T::igaz(!empty($r['success']), 'szabály mentése AJAX-szal');
T::egyezik('auto', (string) $Z::szabaly('lap', 'elkeszult')->mukodes, 'a mentett működés érvényes');
T::egyezik(null, $Z::szabaly('lap', 'arajanlat'), 'a küldött listán kívüli szabály törlődik (területenként felülírás)');

$kollega = wp_insert_user(['user_login' => 'kollega' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(), 'role' => 'editor']);
$admin   = get_current_user_id();
get_userdata($kollega)->add_cap(SDH_Muhely_Admin_UI::jog());
wp_set_current_user($kollega);

T::egyezik(false, !empty(t_ajax('sdh_muhely_sms_sablon_ment', ['nev' => 'Tiltott', 'szoveg' => 'x'])['success']), 'kolléga nem szerkeszthet sablont');
T::egyezik(false, !empty(t_ajax('sdh_muhely_sms_szabaly_ment', ['lap' => []])['success']), 'kolléga nem szerkeszthet szabályt');
T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $S::tabla('sablon') . " WHERE nev = 'Tiltott'"), 'a tiltott sablon nem íródott');
T::igaz(!empty(t_ajax('sdh_muhely_sms_kuld', ['munkalap_id' => $id, 'szoveg' => 'Kolléga küld.'])['success']), 'kolléga a normál joggal küldhet SMS-t');
$kp = $oldal('sablonok');
T::nem_tartalmaz($kp, 'Szabályok mentése', 'kollégának nincs szabálymentő gomb');
T::tartalmaz($kp, 'adminisztrátor módosíthatja', 'kollégának ezt kiírja az oldal');
$su = t_ajax('sdh_muhely_smssablon_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $egy_id])['nyers'];
T::nem_tartalmaz($su, '>Mentés</button>', 'kollégának a sablonszerkesztőben nincs Mentés');
wp_set_current_user($admin);

$su = t_ajax('sdh_muhely_smssablon_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => (int) $kesz->id])['nyers'];
T::tartalmaz($su, '>Mentés</button>', 'adminnak ott a Mentés');
T::tartalmaz($su, 'data-sdh-sms-valtozo="{ugyfel}"', 'a változók gombjai ott vannak');
T::tartalmaz($su, 'Korábbi változatok', 'a verziótörténet látszik');

$r = t_ajax('sdh_muhely_sms_sablon_ment', ['id' => $egy_id, 'nev' => 'Egyszerű', 'szoveg' => 'Szia {ugyfel}, javítva.', 'aktiv' => '1']);
T::igaz(!empty($r['success']), 'sablon mentése AJAX-szal');
T::egyezik(2, (int) $Z::sablon($egy_id)->verzio, 'szövegváltozás: új verzió');
T::egyezik('Szia Rendelő Rita, kész.', (string) $dr->id !== '' ? 'Szia Rendelő Rita, kész.' : '', 'a korábbi popup-szöveg változatlan (csak a döntés pillanatában készül)');

T::vege('t45 SMS sablonok, szabályok, döntések, kézi küldés, csevegő, menüoldalak');
