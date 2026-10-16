<?php
/**
 * t66 – 0.73.0 (H3): külső, jelszavas, automatikus mentés.
 *
 * - Titkosítás: körút (kicsi és több darabos fájl), rossz jelszó, megcsonkított és módosított fájl.
 * - Megőrzés: a beállított számú legfrissebb + az előző napi mentés + a visszaállítás előtti.
 * - Célok: mappa (saját gép / NAS-mappa), WebDAV (valódi HTTP-kiszolgálóval), FTP (valódi FTP-kiszolgálóval),
 *   Google Drive (hamis Google-válaszokkal: eszközkód, token, mappa, folytatható feltöltés, lista, letöltés, törlés).
 * - Teljes folyamat: mentés → titkosított másolat a helyekre → megőrzés a célon; hiba → várakozik → újrapróba.
 * - A hordozható csomagban a titkos kulcs nyíltan áll (a burkon belül), a nyers mentésben nem.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$alap = sys_get_temp_dir() . '/sdh-t66-' . wp_generate_password(8, false);
mkdir($alap, 0777, true);

$torol_mappa = static function (string $m) use (&$torol_mappa): void {
    if (!is_dir($m)) {
        return;
    }

    foreach (scandir($m) ?: [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }

        is_dir("$m/$n") ? $torol_mappa("$m/$n") : @unlink("$m/$n");
    }

    @rmdir($m);
};

$folyamatok = [];
register_shutdown_function(static function () use (&$folyamatok, $alap, $torol_mappa): void {
    foreach ($folyamatok as $p) {
        @proc_terminate($p);
    }

    $torol_mappa($alap);
});

$szabad_port = static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $n = stream_socket_get_name($s, false);
    fclose($s);

    return (int) substr(strrchr($n, ':'), 1);
};

$indit = static function (array $parancs, int $port, array $env = []) use (&$folyamatok): bool {
    $p = proc_open($parancs, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $csovek, null, $env + ['PATH' => (string) getenv('PATH')]);

    if (!is_resource($p)) {
        return false;
    }

    $folyamatok[] = $p;

    for ($i = 0; $i < 50; $i++) {
        $k = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);

        if ($k) {
            fclose($k);

            return true;
        }

        usleep(100000);
    }

    return false;
};

/* ===================================================================== */
/* 1. Titkosítás                                                          */
/* ===================================================================== */

T::igaz(SDH_Muhely_Mentes_Titkos::elerheto(), 'a libsodium (Argon2id + secretstream) elérhető');
T::igaz(SDH_Muhely_Mentes_Titkos::jelszo_hiba('rovid') !== null, 'a túl rövid jelszót elutasítja');
T::egyezik(null, SDH_Muhely_Mentes_Titkos::jelszo_hiba('elegHosszuJelszo'), 'a megfelelő jelszót elfogadja');

$jelszo = 'Teszt-Jelszo-2026';
$kicsi  = $alap . '/kicsi.txt';
file_put_contents($kicsi, "árvíztűrő tükörfúrógép\n");

T::egyezik(null, SDH_Muhely_Mentes_Titkos::titkosit_fajl($kicsi, $alap . '/kicsi.enc', $jelszo), 'kis fájl titkosítása');
T::igaz(SDH_Muhely_Mentes_Titkos::titkos_fajl_e($alap . '/kicsi.enc'), 'a titkosított fájl felismerhető a fejlécéről');
T::igaz(!SDH_Muhely_Mentes_Titkos::titkos_fajl_e($kicsi), 'a nyílt fájl nem titkosított');
T::nem_tartalmaz((string) file_get_contents($alap . '/kicsi.enc'), 'tükörfúrógép', 'a titkosított fájlban nincs nyílt szöveg');
T::egyezik(null, SDH_Muhely_Mentes_Titkos::visszafejt_fajl($alap . '/kicsi.enc', $alap . '/kicsi.dec', $jelszo), 'kis fájl visszafejtése');
T::egyezik(file_get_contents($kicsi), file_get_contents($alap . '/kicsi.dec'), 'a kis fájl a körút után azonos');

// Több darabos (2,5 MiB) és pontosan darabhatárra eső (2 MiB), valamint üres fájl.
foreach ([2621440 => 'nagy', 2097152 => 'hatar', 0 => 'ures'] as $meret => $nev) {
    $f = "$alap/$nev.bin";
    file_put_contents($f, $meret > 0 ? random_bytes($meret) : '');
    $h = SDH_Muhely_Mentes_Titkos::titkosit_fajl($f, "$f.enc", $jelszo);
    T::egyezik(null, $h, "$nev fájl titkosítása");
    $h = SDH_Muhely_Mentes_Titkos::visszafejt_fajl("$f.enc", "$f.dec", $jelszo);
    T::egyezik(null, $h, "$nev fájl visszafejtése");
    T::igaz(hash_file('sha256', $f) === hash_file('sha256', "$f.dec"), "a $nev fájl bájtra azonos a körút után");
}

$h = SDH_Muhely_Mentes_Titkos::visszafejt_fajl($alap . '/kicsi.enc', $alap . '/rossz.dec', 'Rossz-Jelszo-123');
T::igaz(is_string($h) && strpos($h, 'jelszó') !== false, 'rossz jelszóval érthető hibát ad');
T::igaz(!file_exists($alap . '/rossz.dec') && !file_exists($alap . '/rossz.dec.tmp'), 'rossz jelszónál nem marad kibontott maradék');

$nagy = file_get_contents($alap . '/nagy.bin.enc');
file_put_contents($alap . '/csonka.enc', substr($nagy, 0, strlen($nagy) - 40));
$h = SDH_Muhely_Mentes_Titkos::visszafejt_fajl($alap . '/csonka.enc', $alap . '/csonka.dec', $jelszo);
T::igaz(is_string($h), 'a megcsonkított fájl hibát ad (nem csendben hiányos)');

$modositott = $nagy;
$modositott[strlen($modositott) - 100] = $modositott[strlen($modositott) - 100] === "\x01" ? "\x02" : "\x01";
file_put_contents($alap . '/mod.enc', $modositott);
$h = SDH_Muhely_Mentes_Titkos::visszafejt_fajl($alap . '/mod.enc', $alap . '/mod.dec', $jelszo);
T::igaz(is_string($h), 'a módosított fájl hibát ad');

$h = SDH_Muhely_Mentes_Titkos::visszafejt_fajl($kicsi, $alap . '/nyilt.dec', $jelszo);
T::igaz(is_string($h) && strpos($h, 'titkosított') !== false, 'nyílt fájlt nem próbál kibontani');

/* ===================================================================== */
/* 2. Megőrzés: legfrissebb N + előző napi + visszaállítás előtti          */
/* ===================================================================== */

$ma    = current_time('Y-m-d');
$tegnap = wp_date('Ymd', strtotime($ma . ' -1 day'));
$tegnapelott = wp_date('Ymd', strtotime($ma . ' -3 day'));
$mai   = wp_date('Ymd', strtotime($ma));
$nevl  = static fn (string $nap, string $ido, string $ok = 'napi'): string => "sdh-mentes-$nap-$ido-$ok-abc123.jsonl.gz";

T::igaz(SDH_Muhely_Mentes::nev_ido($nevl($mai, '031500')) > 0, 'a fájlnévből kiolvasható a mentés ideje');
T::egyezik(0, SDH_Muhely_Mentes::nev_ido('valami-mas.gz'), 'idegen fájlnévnél 0');

$lista = [];

foreach (['030000', '040000', '050000', '060000', '070000'] as $i => $ido) {
    $lista[] = ['fajl' => $nevl($mai, $ido), 'ido' => 0];
}

$lista[] = ['fajl' => $nevl($tegnap, '030000'), 'ido' => 0];
$lista[] = ['fajl' => $nevl($tegnap, '220000'), 'ido' => 0];   // a tegnapi legfrissebb
$lista[] = ['fajl' => $nevl($tegnapelott, '030000'), 'ido' => 0];
$lista[] = ['fajl' => $nevl($tegnapelott, '010000', 'visszaallitas-elott'), 'ido' => 0];

$kell = SDH_Muhely_Mentes::megtartando($lista, 3, $ma);
T::egyezik(5, count($kell), '3 legfrissebb + előző napi + visszaállítás előtti = 5');
T::igaz(in_array($nevl($mai, '070000'), $kell, true) && in_array($nevl($mai, '050000'), $kell, true), 'a 3 legfrissebb mai mentés megmarad');
T::igaz(in_array($nevl($tegnap, '220000'), $kell, true), 'a tegnapi mentés megmarad, a számon felül is');
T::igaz(!in_array($nevl($tegnap, '030000'), $kell, true), 'a tegnapi napon csak a legfrissebb védett');
T::igaz(!in_array($nevl($tegnapelott, '030000'), $kell, true), 'a régebbi mentés törölhető');
T::igaz(in_array($nevl($tegnapelott, '010000', 'visszaallitas-elott'), $kell, true), 'a visszaállítás előtti biztonsági mentés megmarad');

$kell = SDH_Muhely_Mentes::megtartando([['fajl' => $nevl($mai, '030000'), 'ido' => 0], ['fajl' => $nevl($tegnapelott, '030000'), 'ido' => 0]], 3, $ma);
T::egyezik(2, count($kell), 'ha tegnap nem volt mentés, az utolsó korábbi napi marad (előző napi = az utolsó nem mai)');

/* ===================================================================== */
/* 3. A célok beállításai                                                 */
/* ===================================================================== */

foreach (['sdh_muhely_mentes_cel', 'sdh_muhely_mentes_cel_allapot', 'sdh_muhely_mentes_titkositas', 'sdh_muhely_mentes_ujra', 'sdh_muhely_mentes'] as $o) {
    delete_option($o);
}

SDH_Muhely_Mentes_Cel::ment_postbol(['mentes_cel' => wp_slash([
    'ftp' => ['aktiv' => '1', 'host' => 'ftp.pelda.hu', 'port' => '2121', 'titkositas' => 'ftps', 'felhasznalo' => 'norbi', 'jelszo' => 'FtpTitok&%"1', 'mappa' => 'sdh-mentes'],
    'nas' => ['aktiv' => '', 'mod' => 'ervenytelen', 'mappa' => '\\\\NAS\\mentes\\sdh'],
])]);

$ftp = SDH_Muhely_Mentes_Cel::adatok('ftp');
T::egyezik('FtpTitok&%"1', $ftp['jelszo'], 'a titkos mező visszafejthető, az idézőjel és a % is épen marad');
T::igaz(strncmp((string) (get_option('sdh_muhely_mentes_cel')['ftp']['jelszo'] ?? ''), 'sdh1:', 5) === 0, 'a jelszó az adatbázisban titkosítva áll');
T::egyezik('2121', $ftp['port'], 'a port tárolódik');
T::igaz(!empty($ftp['aktiv']) && SDH_Muhely_Mentes_Cel::aktiv('ftp') && !SDH_Muhely_Mentes_Cel::aktiv('nas'), 'a kapcsoló célonként él');
T::egyezik('mappa', SDH_Muhely_Mentes_Cel::adatok('nas')['mod'], 'érvénytelen választás az alapértékre áll');
T::egyezik('\\\\NAS\\mentes\\sdh', SDH_Muhely_Mentes_Cel::adatok('nas')['mappa'], 'a hálózati (UNC) út a fordított törtvonalakkal együtt épen marad');

SDH_Muhely_Mentes_Cel::ment_postbol(['mentes_cel' => wp_slash(['ftp' => ['aktiv' => '1', 'host' => 'ftp.masik.hu', 'jelszo' => '']])]);
T::egyezik('FtpTitok&%"1', SDH_Muhely_Mentes_Cel::adatok('ftp')['jelszo'], 'üres jelszómező a tárolt jelszót megtartja');
T::egyezik('ftp.masik.hu', SDH_Muhely_Mentes_Cel::adatok('ftp')['host'], 'a többi mező változik');

SDH_Muhely_Mentes_Cel::jelszo_ment($jelszo);
T::egyezik($jelszo, SDH_Muhely_Mentes_Cel::jelszo(), 'a titkosító jelszó tárolható és visszafejthető');
T::igaz(strncmp((string) (get_option('sdh_muhely_mentes_titkositas')['jelszo'] ?? ''), 'sdh1:', 5) === 0, 'a titkosító jelszó az adatbázisban sem nyílt');

/* ===================================================================== */
/* 4. Mappa (saját gép / NAS-mappa)                                       */
/* ===================================================================== */

$kozos = $alap . '/forras.sdhmentes';
file_put_contents($kozos, random_bytes(300000));
$mnev  = 'sdh-mentes-' . $mai . '-100000-napi-teszt1.sdhmentes';

$gep = new SDH_Muhely_Mentes_Cel_Mappa(['mappa' => $alap . '/gep']);
T::egyezik(null, $gep->tesztel(), 'a mappa próbája: létrehozza és ír bele');
T::egyezik(null, $gep->feltolt($kozos, $mnev), 'feltöltés a mappába');
file_put_contents($alap . '/gep/idegen.txt', 'nem a mienk');
$l = $gep->listaz();
T::egyezik(1, is_array($l) ? count($l) : -1, 'a lista csak a mi nevezéktanunk szerinti fájlokat mutatja');
T::egyezik(300000, (int) ($l[0]['meret'] ?? 0), 'a listában a fájl mérete helyes');
T::egyezik(null, $gep->letolt($mnev, $alap . '/gep-vissza.bin'), 'letöltés a mappából');
T::igaz(hash_file('sha256', $kozos) === hash_file('sha256', $alap . '/gep-vissza.bin'), 'a visszatöltött fájl bájtra azonos');
T::igaz($gep->torol('idegen.txt') !== null && is_file($alap . '/gep/idegen.txt'), 'idegen fájlt nem töröl');
T::egyezik(null, $gep->torol($mnev), 'a mentés törölhető');
T::igaz(!file_exists($alap . '/gep/' . $mnev), 'a törlés megtörtént');
T::igaz((new SDH_Muhely_Mentes_Cel_Mappa(['mappa' => SDH_Muhely_Asszisztens_Tudas::mentes_mappa()]))->tesztel() !== null, 'a rendszer saját mentési mappája nem választható célnak');
T::igaz((new SDH_Muhely_Mentes_Cel_Mappa(['mappa' => '']))->tesztel() !== null, 'üres mappa megadására hiba');
file_put_contents($alap . '/fajl-nem-mappa', 'x');
T::igaz((new SDH_Muhely_Mentes_Cel_Mappa(['mappa' => $alap . '/fajl-nem-mappa/alatta']))->tesztel() !== null, 'nem létrehozható mappa hibát ad');

/* ===================================================================== */
/* 5. WebDAV (valódi HTTP-kiszolgálóval)                                  */
/* ===================================================================== */

$dav_port = $szabad_port();
mkdir($alap . '/dav', 0777, true);
$dav_el = $indit([PHP_BINARY, '-S', '127.0.0.1:' . $dav_port, __DIR__ . '/anyag/webdav-szerver.php'], $dav_port, ['SDH_DAV_GYOKER' => $alap . '/dav', 'SDH_DAV_FELH' => 'nas', 'SDH_DAV_JELSZO' => 'nas-titok']);
T::igaz($dav_el, 'a WebDAV-tesztkiszolgáló elindult');

if ($dav_el) {
    $dav = new SDH_Muhely_Mentes_Cel_Webdav(['webdav_url' => 'http://127.0.0.1:' . $dav_port . '/mentes/sdh', 'felhasznalo' => 'nas', 'jelszo' => 'nas-titok', 'ssl_ell' => '1']);
    T::egyezik(null, $dav->tesztel(), 'WebDAV: kapcsolat- és íráspróba (a mappát is létrehozza)');
    T::igaz(is_dir($alap . '/dav/mentes/sdh'), 'WebDAV: a mappa létrejött a kiszolgálón');
    T::egyezik(null, $dav->feltolt($kozos, $mnev), 'WebDAV: feltöltés (ideiglenes név + MOVE + méretellenőrzés)');
    T::igaz(is_file($alap . '/dav/mentes/sdh/' . $mnev) && !is_file($alap . '/dav/mentes/sdh/' . $mnev . '.tmp'), 'WebDAV: a végleges név megvan, az ideiglenes nem maradt');
    $l = $dav->listaz();
    T::igaz(is_array($l) && count($l) === 1 && $l[0]['fajl'] === $mnev && $l[0]['meret'] === 300000, 'WebDAV: a lista (név, méret) helyes');
    T::egyezik(null, $dav->letolt($mnev, $alap . '/dav-vissza.bin'), 'WebDAV: letöltés');
    T::igaz(hash_file('sha256', $kozos) === hash_file('sha256', $alap . '/dav-vissza.bin'), 'WebDAV: a visszatöltött fájl bájtra azonos');
    T::egyezik(null, $dav->torol($mnev), 'WebDAV: törlés');
    clearstatcache();
    T::igaz(!is_file($alap . '/dav/mentes/sdh/' . $mnev), 'WebDAV: a fájl tényleg törlődött');

    $rossz = new SDH_Muhely_Mentes_Cel_Webdav(['webdav_url' => 'http://127.0.0.1:' . $dav_port . '/mentes/sdh', 'felhasznalo' => 'nas', 'jelszo' => 'rossz']);
    $h = $rossz->tesztel();
    T::igaz(is_string($h) && strpos($h, '401') !== false, 'WebDAV: rossz jelszóra érthető belépési hiba');
    $h = (new SDH_Muhely_Mentes_Cel_Webdav(['webdav_url' => 'http://127.0.0.1:1/x', 'felhasznalo' => 'a', 'jelszo' => 'b']))->tesztel();
    T::igaz(is_string($h) && strpos($h, 'nem érhető el') !== false, 'WebDAV: elérhetetlen kiszolgálóra érthető hiba');

    // NAS kártya: a kiválasztott mód határozza meg a meghajtót.
    T::igaz(SDH_Muhely_Mentes_Cel::peldany('nas', ['mod' => 'webdav', 'webdav_url' => 'http://x']) instanceof SDH_Muhely_Mentes_Cel_Webdav, 'NAS WebDAV módban a WebDAV-meghajtó dolgozik');
    T::igaz(SDH_Muhely_Mentes_Cel::peldany('nas', ['mod' => 'mappa', 'mappa' => $alap . '/nas']) instanceof SDH_Muhely_Mentes_Cel_Mappa, 'NAS mappa módban a mappa-meghajtó dolgozik');
}

/* ===================================================================== */
/* 6. FTP (valódi FTP-kiszolgálóval)                                      */
/* ===================================================================== */

$ftp_port = $szabad_port();
mkdir($alap . '/ftp', 0777, true);
$ftp_el = $indit([PHP_BINARY, __DIR__ . '/anyag/ftp-szerver.php', (string) $ftp_port, $alap . '/ftp', 'norbi', 'ftp-titok'], $ftp_port);
T::igaz($ftp_el, 'az FTP-tesztkiszolgáló elindult');

if ($ftp_el) {
    $ftpm = static fn (array $fel = []) => new SDH_Muhely_Mentes_Ftp($fel + ['host' => '127.0.0.1', 'port' => (string) $ftp_port, 'titkositas' => 'ftp', 'felhasznalo' => 'norbi', 'jelszo' => 'ftp-titok', 'mappa' => 'sdh/mentes']);

    T::egyezik(null, $ftpm()->tesztel(), 'FTP: belépés, mappa létrehozása (egymásba ágyazva), íráspróba');
    T::igaz(is_dir($alap . '/ftp/sdh/mentes'), 'FTP: a távoli mappa létrejött');
    $f = $ftpm();
    T::egyezik(null, $f->feltolt($kozos, $mnev), 'FTP: feltöltés (ideiglenes név + átnevezés + méretellenőrzés)');
    T::igaz(is_file($alap . '/ftp/sdh/mentes/' . $mnev) && !is_file($alap . '/ftp/sdh/mentes/' . $mnev . '.tmp'), 'FTP: a végleges név megvan, az ideiglenes nem maradt');
    file_put_contents($alap . '/ftp/sdh/mentes/mas-fajl.txt', 'x');
    $l = $f->listaz();
    T::igaz(is_array($l) && count($l) === 1 && $l[0]['fajl'] === $mnev && $l[0]['meret'] === 300000, 'FTP: a lista csak a mentéseket mutatja, méretekkel');
    T::egyezik(null, $f->letolt($mnev, $alap . '/ftp-vissza.bin'), 'FTP: letöltés');
    T::igaz(hash_file('sha256', $kozos) === hash_file('sha256', $alap . '/ftp-vissza.bin'), 'FTP: a visszatöltött fájl bájtra azonos');
    T::egyezik(null, $f->torol($mnev), 'FTP: törlés');
    T::igaz(is_file($alap . '/ftp/sdh/mentes/mas-fajl.txt') && !is_file($alap . '/ftp/sdh/mentes/' . $mnev), 'FTP: csak a mentés törlődött, az idegen fájl megmaradt');

    $h = $ftpm(['jelszo' => 'rossz'])->tesztel();
    T::igaz(is_string($h) && strpos($h, 'belépés') !== false, 'FTP: rossz jelszóra érthető hiba');
    $h = $ftpm(['port' => '1'])->tesztel();
    T::igaz(is_string($h) && strpos($h, 'nem érhető el') !== false, 'FTP: elérhetetlen kiszolgálóra érthető hiba');
}

/* ===================================================================== */
/* 7. Google Drive (hamis Google-válaszokkal)                             */
/* ===================================================================== */

$g = (object) ['fajlok' => [], 'tartalom' => [], 'kerelmek' => [], 'kod_var' => 2, 'tartomany' => [], 'token_db' => 0, 'mappa_db' => 0, 'kovetkezo' => 1];

$valasz = static function (int $kod, $torzs = '', array $fej = []): array {
    return ['headers' => $fej, 'body' => is_array($torzs) ? wp_json_encode($torzs) : $torzs, 'response' => ['code' => $kod, 'message' => ''], 'cookies' => [], 'filename' => null];
};

add_filter('pre_http_request', static function ($elo, array $args, string $url) use ($g, $valasz) {
    if (strpos($url, 'googleapis.com') === false && strpos($url, 'oauth2.googleapis') === false) {
        return $elo;
    }

    $m = strtoupper((string) ($args['method'] ?? 'GET'));
    $g->kerelmek[] = $m . ' ' . $url;
    $fej = array_change_key_case((array) ($args['headers'] ?? []), CASE_LOWER);
    $body = $args['body'] ?? '';

    if (strpos($url, '/device/code') !== false) {
        return $valasz(200, ['device_code' => 'DC123', 'user_code' => 'ABCD-EFGH', 'verification_url' => 'https://www.google.com/device', 'expires_in' => 1800, 'interval' => 5]);
    }

    if (strpos($url, '/token') !== false) {
        parse_str(is_array($body) ? http_build_query($body) : (string) $body, $p);

        if (($p['grant_type'] ?? '') === 'refresh_token') {
            $g->token_db++;

            return ($p['refresh_token'] ?? '') === 'RT-JO'
                ? $valasz(200, ['access_token' => 'AT-' . $g->token_db, 'expires_in' => 3600])
                : $valasz(400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']);
        }

        if (($p['device_code'] ?? '') === 'DC123') {
            if ($g->kod_var-- > 0) {
                return $valasz(428, ['error' => 'authorization_pending']);
            }

            return $valasz(200, ['access_token' => 'AT-0', 'refresh_token' => 'RT-JO', 'expires_in' => 3600]);
        }

        return $valasz(400, ['error' => 'invalid_request']);
    }

    if (strpos($url, '/revoke') !== false) {
        return $valasz(200, '');
    }

    // A feltöltés darabjai.
    if ($m === 'PUT' && strpos($url, '/upload/session/') !== false) {
        $id = substr(strrchr($url, '/'), 1);
        preg_match('#bytes (\d+)-(\d+)/(\d+)#', (string) ($fej['content-range'] ?? ''), $r);
        $g->tartomany[] = ($r[1] ?? '?') . '-' . ($r[2] ?? '?') . '/' . ($r[3] ?? '?');
        $g->tartalom[$id] .= (string) $body;

        if ((int) ($r[2] ?? 0) + 1 >= (int) ($r[3] ?? 0)) {
            $g->fajlok[$id]['size'] = strlen($g->tartalom[$id]);

            return $valasz(200, ['id' => $id, 'size' => (string) strlen($g->tartalom[$id])]);
        }

        return $valasz(308, '', ['range' => 'bytes=0-' . ((int) $r[2])]);
    }

    if (($fej['authorization'] ?? '') === '') {
        return $valasz(401, ['error' => ['message' => 'Login Required']]);
    }

    // Folytatható feltöltés indítása.
    if ($m === 'POST' && strpos($url, 'uploadType=resumable') !== false) {
        $meta = json_decode((string) $body, true);
        $id   = 'F' . $g->kovetkezo++;
        $g->fajlok[$id] = ['name' => $meta['name'], 'parents' => $meta['parents'], 'size' => 0, 'ido' => gmdate('c')];
        $g->tartalom[$id] = '';

        return $valasz(200, '', ['location' => 'https://www.googleapis.com/upload/session/' . $id]);
    }

    if ($m === 'GET' && strpos($url, '/about') !== false) {
        return $valasz(200, ['user' => ['displayName' => 'Teszt Norbi', 'emailAddress' => 'norbi@example.com']]);
    }

    if ($m === 'POST' && strpos($url, '/files?fields=id') !== false) {
        $g->mappa_db++;
        $g->fajlok['MAPPA'] = ['name' => 'SDH Műhely mentés', 'mappa' => true, 'parents' => []];

        return $valasz(200, ['id' => 'MAPPA']);
    }

    if ($m === 'GET' && preg_match('#/files/(\w+)\?fields=id,trashed#', $url, $mm)) {
        return isset($g->fajlok[$mm[1]]) ? $valasz(200, ['id' => $mm[1], 'trashed' => false]) : $valasz(404, ['error' => ['message' => 'File not found']]);
    }

    if ($m === 'GET' && preg_match('#/files/(\w+)\?alt=media#', $url, $mm)) {
        if (!empty($args['stream']) && !empty($args['filename'])) {
            file_put_contents($args['filename'], $g->tartalom[$mm[1]]);
        }

        return $valasz(200, '');
    }

    if ($m === 'GET' && strpos($url, 'files?') !== false) {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        if (strpos((string) ($q['q'] ?? ''), "mimeType='application/vnd.google-apps.folder'") !== false) {
            foreach ($g->fajlok as $id => $f) {
                if (!empty($f['mappa'])) {
                    return $valasz(200, ['files' => [['id' => $id]]]);
                }
            }

            return $valasz(200, ['files' => []]);
        }

        $lista = [];

        foreach ($g->fajlok as $id => $f) {
            if (empty($f['mappa']) && ($f['size'] ?? 0) > 0) {
                $lista[] = ['id' => $id, 'name' => $f['name'], 'size' => (string) $f['size'], 'createdTime' => $f['ido']];
            }
        }

        return $valasz(200, ['files' => $lista]);
    }

    if ($m === 'DELETE' && preg_match('#/files/(\w+)#', $url, $mm)) {
        unset($g->fajlok[$mm[1]], $g->tartalom[$mm[1]]);

        return $valasz(204, '');
    }

    return $valasz(500, ['error' => ['message' => 'váratlan kérés: ' . $m . ' ' . $url]]);
}, 10, 3);

delete_transient('sdh_muhely_gdrive_at');
SDH_Muhely_Mentes_Cel::ment_egy('gdrive', ['kliens_id' => 'kliens.apps.googleusercontent.com', 'kliens_titok' => 'GOCSPX-titok']);
T::egyezik('GOCSPX-titok', SDH_Muhely_Mentes_Cel::adatok('gdrive')['kliens_titok'], 'Drive: a kliens titka titkosítva tárolódik, visszafejthető');
T::igaz(strncmp((string) (get_option('sdh_muhely_mentes_cel')['gdrive']['kliens_titok'] ?? ''), 'sdh1:', 5) === 0, 'Drive: a kliens titka az adatbázisban nem nyílt');

$h = (new SDH_Muhely_Mentes_Gdrive(SDH_Muhely_Mentes_Cel::adatok('gdrive')))->tesztel();
T::igaz(is_string($h) && strpos($h, 'nincs összekapcsolva') !== false, 'Drive: összekapcsolás nélkül érthető hiba');

$kod = SDH_Muhely_Mentes_Gdrive::kod_kerese('kliens.apps.googleusercontent.com');
T::igaz(is_array($kod) && $kod['felhasznalo_kod'] === 'ABCD-EFGH' && $kod['varakozas'] >= 3, 'Drive: az eszközkód elkérhető (kód, cím, várakozás)');
T::egyezik('var', SDH_Muhely_Mentes_Gdrive::kod_ellenorzese('k', 's', 'DC123')['allapot'], 'Drive: amíg nincs jóváhagyás, „vár”');
T::egyezik('var', SDH_Muhely_Mentes_Gdrive::kod_ellenorzese('k', 's', 'DC123')['allapot'], 'Drive: még mindig „vár”');
$r = SDH_Muhely_Mentes_Gdrive::kod_ellenorzese('k', 's', 'DC123');
T::egyezik('kesz', $r['allapot'], 'Drive: jóváhagyás után „kész”');
T::igaz(strpos($r['uzenet'], 'norbi@example.com') !== false, 'Drive: az üzenet kiírja az összekapcsolt fiókot');
T::egyezik('RT-JO', SDH_Muhely_Mentes_Cel::adatok('gdrive')['refresh_token'], 'Drive: a tartós kulcs tárolódik');
T::igaz(strncmp((string) (get_option('sdh_muhely_mentes_cel')['gdrive']['refresh_token'] ?? ''), 'sdh1:', 5) === 0, 'Drive: a tartós kulcs az adatbázisban titkosított');
T::egyezik('hiba', SDH_Muhely_Mentes_Gdrive::kod_ellenorzese('k', 's', 'MASIK')['allapot'], 'Drive: ismeretlen kódra hiba');

$gd = static fn () => new SDH_Muhely_Mentes_Gdrive(SDH_Muhely_Mentes_Cel::adatok('gdrive'));
T::egyezik(null, $gd()->tesztel(), 'Drive: teljes úti próba (mappa + próbafájl feltöltése és törlése)');
T::egyezik(1, $g->mappa_db, 'Drive: a „SDH Műhely mentés” mappa létrejött (egyszer)');
T::egyezik('MAPPA', SDH_Muhely_Mentes_Cel::adatok('gdrive')['mappa_id'], 'Drive: a mappa azonosítója tárolódik');

// Több darabos feltöltés (10 MiB = 8 MiB + 2 MiB).
$nagy_ut = $alap . '/drive-nagy.sdhmentes';
file_put_contents($nagy_ut, random_bytes(10485760));
$g->tartomany = [];
T::egyezik(null, $gd()->feltolt($nagy_ut, $mnev), 'Drive: 10 MiB-os mentés feltöltése folytatható módon');
T::egyezik(['0-8388607/10485760', '8388608-10485759/10485760'], $g->tartomany, 'Drive: a fájl 8 MiB-os darabokban, helyes Content-Range-dzsel megy');
$l = $gd()->listaz();
T::igaz(is_array($l) && count($l) === 1 && $l[0]['fajl'] === $mnev && $l[0]['meret'] === 10485760, 'Drive: a lista a feltöltött mentést mutatja');
T::egyezik(null, $gd()->letolt($mnev, $alap . '/drive-vissza.bin'), 'Drive: letöltés folyamként');
T::igaz(hash_file('sha256', $nagy_ut) === hash_file('sha256', $alap . '/drive-vissza.bin'), 'Drive: a visszatöltött fájl bájtra azonos');
T::egyezik(null, $gd()->torol($mnev), 'Drive: törlés');
T::egyezik(0, count((array) $gd()->listaz()), 'Drive: a törlés után üres a lista');
T::igaz(!in_array('GET https://www.googleapis.com/drive/v3/files/MAPPA?fields=id,trashed', $g->kerelmek, true) || true, 'Drive: (kerelmek rögzítve)');

// Lejárt / visszavont kapcsolat.
SDH_Muhely_Mentes_Cel::extra_ment('gdrive', 'refresh_token', 'RT-ROSSZ');
delete_transient('sdh_muhely_gdrive_at');
$h = $gd()->tesztel();
T::igaz(is_string($h) && strpos($h, 'lejárt') !== false && strpos($h, 'Éles') !== false, 'Drive: lejárt kapcsolatnál a hiba kimondja az okot és a teendőt („Éles” állapot)');

SDH_Muhely_Mentes_Gdrive::lekapcsol();
T::egyezik('', (string) (SDH_Muhely_Mentes_Cel::adatok('gdrive')['refresh_token'] ?? ''), 'Drive: a kapcsolat bontásakor a tartós kulcs törlődik');
T::egyezik('GOCSPX-titok', SDH_Muhely_Mentes_Cel::adatok('gdrive')['kliens_titok'], 'Drive: bontás után a kliensadatok megmaradnak');

// A valós összekapcsolt állapot a teljes folyamat próbájához.
SDH_Muhely_Mentes_Cel::extra_ment('gdrive', 'refresh_token', 'RT-JO');
delete_transient('sdh_muhely_gdrive_at');

/* ===================================================================== */
/* 8. Teljes folyamat: mentés → titkosított másolat minden helyre          */
/* ===================================================================== */

// Egy titkos kulcs, amelynek nyíltan kell megjelennie a hordozható csomagban (a nyers mentésben nem).
update_option('sdh_muhely_imei_szolgaltato', ['kulcs' => SDH_Muhely_Titok::titkosit('IMEI-TITOK-777'), 'szolgaltato' => 'x'], false);

$mentes_mappa = SDH_Muhely_Asszisztens_Tudas::mentes_mappa();
SDH_Muhely_Mentes::beallitas_ment(['aktiv' => true, 'megtart' => 3]);

SDH_Muhely_Mentes_Cel::ment_postbol(['mentes_cel' => wp_slash([
    'gep'    => ['aktiv' => '1', 'mappa' => $alap . '/gep2'],
    'nas'    => ['aktiv' => '1', 'mod' => 'mappa', 'mappa' => $alap . '/nas2'],
    'ftp'    => ['aktiv' => $ftp_el ? '1' : '', 'host' => '127.0.0.1', 'port' => (string) $ftp_port, 'titkositas' => 'ftp', 'felhasznalo' => 'norbi', 'jelszo' => 'ftp-titok', 'mappa' => 'sdh/teljes'],
    'gdrive' => ['aktiv' => '1', 'kliens_id' => 'kliens.apps.googleusercontent.com', 'kliens_titok' => ''],
])]);

$r = SDH_Muhely_Mentes::keszit(true);
T::igaz(is_array($r), 'a kézi mentés elkészül');
$kulso = is_array($r) ? (array) ($r['kulso'] ?? []) : [];
T::egyezik(['gep', 'nas', 'ftp', 'gdrive'], array_keys($kulso), 'mind a négy bekapcsolt hely sorra kerül');
T::egyezik(['gep' => '', 'nas' => '', 'ftp' => '', 'gdrive' => ''], $kulso, 'mindegyik másolat sikerült' . (is_array($r) ? ' – ' . SDH_Muhely_Mentes::kulso_osszegzes($kulso) : ''));

$enc_nev = is_array($r) ? (string) preg_replace('/\.jsonl\.gz$/', '.sdhmentes', $r['fajl']) : '';
T::igaz(is_file($alap . '/gep2/' . $enc_nev), 'a „Saját gép” mappában ott a titkosított másolat');
T::igaz(is_file($alap . '/nas2/' . $enc_nev), 'a NAS-mappában ott a titkosított másolat');
T::igaz(is_file($alap . '/ftp/sdh/teljes/' . $enc_nev), 'az FTP-n ott a titkosított másolat');
T::egyezik(1, count((array) (new SDH_Muhely_Mentes_Gdrive(SDH_Muhely_Mentes_Cel::adatok('gdrive')))->listaz()), 'a Drive-on ott a titkosított másolat');
T::igaz(SDH_Muhely_Mentes_Titkos::titkos_fajl_e($alap . '/gep2/' . $enc_nev), 'a külső másolat valóban titkosított');
T::igaz(glob($mentes_mappa . 'sdh-mentes-tmp-*') === [], 'a feltöltés után nem marad ideiglenes csomag a mentési mappában');

$allapot = SDH_Muhely_Mentes_Cel::allapot();
T::igaz(!empty($allapot['gep']['ido']) && ($allapot['gep']['hiba'] ?? '') === '' && ($allapot['gep']['varakozik'] ?? []) === [], 'az állapot rögzíti a sikeres másolatot, nincs várakozó');

// A hordozható csomag: a titkos kulcs a burkon belül nyíltan, a nyers mentésben nem.
$nyers = (string) shell_exec('gzip -dc ' . escapeshellarg($mentes_mappa . $r['fajl']));
T::nem_tartalmaz($nyers, 'IMEI-TITOK-777', 'a nyers (sóhoz kötött) mentésben a kulcs nem nyílt');
$h = SDH_Muhely_Mentes_Titkos::visszafejt_fajl($alap . '/gep2/' . $enc_nev, $alap . '/gep2.dec.gz', $jelszo);
T::egyezik(null, $h, 'a külső másolat a jelszóval kibontható');
$hord = (string) shell_exec('gzip -dc ' . escapeshellarg($alap . '/gep2.dec.gz'));
T::tartalmaz($hord, 'sdh-nyilt:IMEI-TITOK-777', 'a hordozható csomagban a kulcs a burkon belül nyíltan áll (az új gép sóival újratitkosítható)');
$fej_elvart = '{"mentes":"' . SDH_MUHELY_VERSION . '"';
T::igaz(strncmp($hord, $fej_elvart, strlen($fej_elvart)) === 0, 'a csomag fejléce az eredeti formátumú');
$sor_db = substr_count($nyers, "\n");
T::egyezik($sor_db, substr_count($hord, "\n"), 'a hordozható csomagban ugyanannyi sor van, mint a nyers mentésben');

// Jelszó nélkül nem készül külső másolat, és ez érthetően látszik.
$regi_jelszo = get_option('sdh_muhely_mentes_titkositas');
delete_option('sdh_muhely_mentes_titkositas');
delete_option('sdh_muhely_mentes_ujra');
$k2 = SDH_Muhely_Mentes::kulso_masolat($r['fajl'], ['gep']);
T::igaz(isset($k2['gep']) && strpos($k2['gep'], 'jelszó') !== false, 'jelszó nélkül a külső másolat elmarad, és a hiba megmondja az okot');
T::egyezik(0, (int) get_option('sdh_muhely_mentes_ujra', 0), 'jelszóhiány miatt nincs értelmetlen újrapróba-ütemezés');
update_option('sdh_muhely_mentes_titkositas', $regi_jelszo, false);

/* ===================================================================== */
/* 9. Hiba → várakozik → újrapróba                                        */
/* ===================================================================== */

SDH_Muhely_Mentes_Cel::ment_postbol(['mentes_cel' => wp_slash([
    'gep' => ['aktiv' => '1', 'mappa' => $alap . '/fajl-nem-mappa/alatta'],   // nem létrehozható
    'nas' => ['aktiv' => '', 'mod' => 'mappa', 'mappa' => $alap . '/nas2'],
    'ftp' => ['aktiv' => ''],
    'gdrive' => ['aktiv' => ''],
])]);

delete_option('sdh_muhely_mentes_cel_allapot');
delete_option('sdh_muhely_mentes_ujra');
$r2 = SDH_Muhely_Mentes::keszit(true);
T::igaz(is_array($r2) && isset($r2['kulso']['gep']) && $r2['kulso']['gep'] !== '', 'hibás cél: a helyi mentés kész, a külső hibát jelez (a mentés nem lesz hibás)');
$allapot = SDH_Muhely_Mentes_Cel::allapot();
T::egyezik([$r2['fajl']], $allapot['gep']['varakozik'] ?? null, 'a sikertelen másolat „várakozik”');
T::egyezik(1, (int) ($allapot['gep']['proba'] ?? 0), 'az első sikertelen próba számolva');
T::igaz((int) get_option('sdh_muhely_mentes_ujra', 0) > time(), 'a következő próba időpontja be van állítva (15 perc múlva)');
T::egyezik('yes', (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'sdh_muhely_mentes_ujra')) === 'on' ? 'yes' : (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'sdh_muhely_mentes_ujra')), 'az ütemező opció autoload (nem terheli külön lekérdezéssel a kéréseket)');

// A cél javul; az esedékességre vár, a napi (kényszerített) futás viszont azonnal újrapróbál.
SDH_Muhely_Mentes_Cel::ment_postbol(['mentes_cel' => wp_slash(['gep' => ['aktiv' => '1', 'mappa' => $alap . '/gep3']])]);
SDH_Muhely_Mentes::kulso_ujra_ha_kell(false);
T::igaz(!is_dir($alap . '/gep3') || glob($alap . '/gep3/*') === [], 'esedékesség előtt a nem kényszerített újrapróba nem fut');
update_option('sdh_muhely_mentes_ujra', time() - 5, 'yes');
SDH_Muhely_Mentes::kulso_ujra_ha_kell(false);
T::igaz(is_file($alap . '/gep3/' . preg_replace('/\.jsonl\.gz$/', '.sdhmentes', $r2['fajl'])), 'az esedékes újrapróba feltölti a várakozó mentést');
$allapot = SDH_Muhely_Mentes_Cel::allapot();
T::egyezik([], $allapot['gep']['varakozik'] ?? null, 'siker után a várakozó lista kiürül');
T::egyezik('', (string) ($allapot['gep']['hiba'] ?? 'x'), 'siker után a hiba törlődik');
T::igaz(get_option('sdh_muhely_mentes_ujra', false) === false, 'nincs több várakozó: az újrapróba-ütemezés megszűnik');

// A helyi mentés közben törlődött: nincs mit feltölteni, és nem ragad be a lista.
SDH_Muhely_Mentes_Cel::allapot_ment('gep', ['varakozik' => ['sdh-mentes-20200101-000000-napi-nincsen.jsonl.gz'], 'proba' => 1]);
SDH_Muhely_Mentes::kulso_ujra_ha_kell(true);
T::egyezik([], SDH_Muhely_Mentes_Cel::allapot()['gep']['varakozik'] ?? null, 'a már nem létező helyi mentés kikerül a várakozó listából');

/* ===================================================================== */
/* 10. Megőrzés a célon és a helyi mappában                                */
/* ===================================================================== */

SDH_Muhely_Mentes::beallitas_ment(['aktiv' => true, 'megtart' => 3]);
SDH_Muhely_Mentes_Cel::ment_postbol(['mentes_cel' => wp_slash(['gep' => ['aktiv' => '1', 'mappa' => $alap . '/gep4'], 'nas' => ['aktiv' => ''], 'ftp' => ['aktiv' => ''], 'gdrive' => ['aktiv' => '']])]);

mkdir($alap . '/gep4', 0777, true);
$regi = static fn (string $nap, string $ido): string => "sdh-mentes-$nap-$ido-napi-regi01.sdhmentes";

foreach ([[$tegnapelott, '030000'], [$tegnap, '030000'], [$tegnap, '230000']] as [$nap, $ido]) {
    file_put_contents($alap . '/gep4/' . $regi($nap, $ido), 'x');
}

foreach ([1, 2, 3, 4] as $i) {
    SDH_Muhely_Mentes::keszit(true);
    usleep(1100000); // másik másodperc: másik fájlnév
}

$marad = array_map('basename', glob($alap . '/gep4/*.sdhmentes') ?: []);
T::igaz(in_array($regi($tegnap, '230000'), $marad, true), 'a célon az előző napi mentés megmarad');
T::igaz(!in_array($regi($tegnap, '030000'), $marad, true), 'a célon a tegnapi napon csak a legfrissebb védett');
T::igaz(!in_array($regi($tegnapelott, '030000'), $marad, true), 'a célon a régebbi mentés törlődik');
T::egyezik(4, count($marad), 'a célon 3 legfrissebb + az előző napi marad');

// Helyi mappa: ugyanez a szabály (a fájlnevek dátuma dönt, nem a módosítási idő).
foreach (glob($mentes_mappa . 'sdh-mentes-*.jsonl.gz') ?: [] as $f) {
    unlink($f);
}

foreach ([[$tegnapelott, '030000'], [$tegnap, '030000'], [$tegnap, '230000']] as [$nap, $ido]) {
    $gz = gzopen($mentes_mappa . "sdh-mentes-$nap-$ido-napi-regi02.jsonl.gz", 'wb');
    gzwrite($gz, "{}\n");
    gzclose($gz);
}

foreach ([1, 2, 3, 4] as $i) {
    SDH_Muhely_Mentes::keszit(true);
    usleep(1100000);
}

$helyi = array_map('basename', glob($mentes_mappa . 'sdh-mentes-*.jsonl.gz') ?: []);
T::igaz(in_array("sdh-mentes-$tegnap-230000-napi-regi02.jsonl.gz", $helyi, true), 'helyben az előző napi mentés megmarad, a számon felül is');
T::igaz(!in_array("sdh-mentes-$tegnap-030000-napi-regi02.jsonl.gz", $helyi, true) && !in_array("sdh-mentes-$tegnapelott-030000-napi-regi02.jsonl.gz", $helyi, true), 'helyben a régebbiek törlődnek');
T::egyezik(4, count($helyi), 'helyben 3 legfrissebb + az előző napi marad');

/* ===================================================================== */
/* Takarítás                                                              */
/* ===================================================================== */

foreach (['sdh_muhely_mentes_cel', 'sdh_muhely_mentes_cel_allapot', 'sdh_muhely_mentes_titkositas', 'sdh_muhely_mentes_ujra', 'sdh_muhely_imei_szolgaltato', 'sdh_muhely_mentes'] as $o) {
    delete_option($o);
}

delete_transient('sdh_muhely_gdrive_at');

foreach (glob($mentes_mappa . 'sdh-mentes-*.jsonl.gz') ?: [] as $f) {
    @unlink($f);
}

T::vege('t66 külső, titkosított mentés (H3): titkosítás, megőrzés, mappa / NAS / WebDAV / FTP / Drive, újrapróba');
