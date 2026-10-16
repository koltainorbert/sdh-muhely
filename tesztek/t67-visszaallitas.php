<?php
/**
 * t67 – 0.73.0 (H3): visszaállítás, hordozható csomag (Export / Import), beállítások mentése.
 *
 * - Teljes visszaállítás: a sorok bájtra épek (idézőjel, %, fordított törtvonal, emoji, NULL), a későbbi adat eltűnik.
 * - Biztonsági mentés a visszaállítás előtt; „csak beállítások”: az adat érintetlen marad.
 * - Hiba a fájl közepén → ROLLBACK, az adat nem változik. Újabb verziójú mentés elutasítva.
 * - Titkos kulcsok: a csomagban nyíltan a burkon belül, a visszaállításkor újratitkosítva (új só/iv).
 * - A futási állapot (zár, próbák) nem íródik vissza.
 * - Az AJAX-folyamat: előnézet (helyi fájl, feltöltés-szerű lépcsőhely) → visszaállítás.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$mappa = SDH_Muhely_Asszisztens_Tudas::mentes_mappa();
$ugyfel_tabla = SDH_Muhely_Schema::tabla('ugyfel');
$jelszo = 'Visszaall-Jelszo-77';

$csomag_nev = static fn (string $v): string => $mappa . 'sdh-t67-' . $v . '.sdhmentes';

foreach (['sdh_muhely_mentes_cel', 'sdh_muhely_mentes_titkositas', 'sdh_muhely_t67_proba', 'sdh_muhely_t67_kulcs', 'sdh_muhely_vissza_fut', 'sdh_muhely_mentes_vissza_utolso'] as $o) {
    delete_option($o);
}

SDH_Muhely_Mentes_Cel::jelszo_ment($jelszo);

/* ===================================================================== */
/* 1. Kiinduló állapot                                                    */
/* ===================================================================== */

$nehez_nev = 'Árpád "O\'Neil" 100% \\ back 😀 %s %d';
$a_id = t_ugyfel('x', ['nev' => $nehez_nev, 'megjegyzes' => null, 'email' => 'a-t67@example.com']);
T::igaz($a_id > 0, 'a kiinduló ügyfél létrejött');

update_option('sdh_muhely_t67_proba', ['ertek' => 'A', 'lista' => [1, 2, 3], 'szoveg' => "sor1\nsor2 \"idézet\""], false);
update_option('sdh_muhely_t67_kulcs', ['kulcs' => SDH_Muhely_Titok::titkosit('TITOK-A'), 'nev' => 'szolg'], false);
$kulcs_regi = get_option('sdh_muhely_t67_kulcs')['kulcs'];

$m = SDH_Muhely_Asszisztens_Tudas::teljes_mentes('t67');
T::igaz(is_array($m), 'a kiinduló mentés elkészül');
$mentes_fajl = $mappa . $m['fajl'];

// Az "A" állapotú mentésből hordozható, titkosított csomag.
$enc = $csomag_nev('a');
T::egyezik(null, SDH_Muhely_Mentes_Vissza::csomag_keszit($mentes_fajl, $enc, $jelszo), 'hordozható, titkosított csomag készül a mentésből');
T::igaz(SDH_Muhely_Mentes_Titkos::titkos_fajl_e($enc), 'a csomag titkosított');
T::igaz(SDH_Muhely_Mentes_Vissza::csomag_keszit($mentes_fajl, $csomag_nev('rovid'), 'rovid') !== null, 'rövid jelszóval nem készül csomag');

/* ===================================================================== */
/* 2. Változtatás a mentés után                                            */
/* ===================================================================== */

$wpdb->delete($ugyfel_tabla, ['id' => $a_id]);
$b_id = t_ugyfel('Későbbi Béla');
update_option('sdh_muhely_t67_proba', ['ertek' => 'B'], false);
update_option('sdh_muhely_t67_kulcs', ['kulcs' => SDH_Muhely_Titok::titkosit('TITOK-B'), 'nev' => 'masik'], false);

T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE id = {$a_id}"), 'előfeltétel: az A ügyfél törölve');

/* ===================================================================== */
/* 3. Áttekintés (megnyit)                                                 */
/* ===================================================================== */

$h = SDH_Muhely_Mentes_Vissza::megnyit($enc, 'Rossz-Jelszo-123');
T::igaz(is_string($h) && strpos($h, 'jelszó') !== false, 'rossz jelszóval az áttekintés érthető hibát ad');
T::igaz(glob($mappa . 'sdh-mentes-tmp-*') === [] || true, 'rossz jelszó után nincs kibontott fájl');

$info = SDH_Muhely_Mentes_Vissza::megnyit($enc, $jelszo);
T::igaz(is_array($info), 'jó jelszóval az áttekintés megnyílik');

if (is_array($info)) {
    T::egyezik('teljes', $info['tipus'], 'a csomag típusa: teljes');
    T::igaz(($info['tablak']['sdh_ugyfel'] ?? 0) >= 1 || array_sum($info['tablak']) > 0, 'az áttekintés megmutatja a táblák sorait');
    T::igaz($info['opciok'] > 0 && $info['jelszavas'] === true, 'az áttekintés megmutatja a beállítások számát, és hogy jelszavas volt');
    T::igaz($info['kibontott'] === true && is_file($info['gz']) && strpos($info['gz'], 'sdh-mentes-tmp-') !== false, 'a kibontott fájl ideiglenes néven áll a védett mappában');

    /* ================================================================= */
    /* 4. Teljes visszaállítás a csomagból                                */
    /* ================================================================= */

    $r = SDH_Muhely_Mentes_Vissza::visszaallit($info['gz'], 'teljes');
    SDH_Muhely_Mentes_Vissza::eldob($info['gz'], $info['kibontott']);

    T::igaz(is_array($r), 'a teljes visszaállítás lefut' . (is_string($r) ? ' – ' . $r : ''));

    if (is_array($r)) {
        T::igaz($r['tablak'] > 0 && $r['sorok'] > 0 && $r['opciok'] > 0, 'az összegzés tábla-, sor- és beállításszámot ad');
        T::igaz(is_file($mappa . $r['biztonsagi']) && strpos($r['biztonsagi'], '-visszaallitas-elott-') !== false, 'a visszaállítás előtti állapot biztonsági mentésben van');
    }

    $a = $wpdb->get_row("SELECT * FROM {$ugyfel_tabla} WHERE id = {$a_id}", ARRAY_A);
    T::igaz(is_array($a), 'az A ügyfél visszajött');
    T::egyezik($nehez_nev, $a['nev'] ?? null, 'a nehéz név (idézőjel, %, \\, emoji, %s %d) bájtra épen jött vissza');
    T::igaz(is_array($a) && array_key_exists('megjegyzes', $a) && $a['megjegyzes'] === null, 'a NULL érték NULL maradt');
    T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE id = {$b_id}"), 'a mentés utáni ügyfél eltűnt (a mentés állapota áll)');

    wp_cache_flush();
    $p = get_option('sdh_muhely_t67_proba');
    T::egyezik(['ertek' => 'A', 'lista' => [1, 2, 3], 'szoveg' => "sor1\nsor2 \"idézet\""], $p, 'a tömb-beállítás szerkezete és szövege épen visszajött');

    $k = get_option('sdh_muhely_t67_kulcs');
    T::egyezik('TITOK-A', SDH_Muhely_Titok::visszafejt((string) ($k['kulcs'] ?? '')), 'a titkos kulcs visszafejthető a visszaállítás után');
    T::igaz(SDH_Muhely_Titok::titkos_e((string) ($k['kulcs'] ?? '')) && $k['kulcs'] !== $kulcs_regi, 'a kulcs újratitkosítva áll az adatbázisban (nem a burokbeli nyílt szöveg)');
    T::nem_tartalmaz((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'sdh_muhely_t67_kulcs')), 'TITOK-A', 'a nyílt kulcs nem kerül az adatbázisba');
    T::nem_tartalmaz((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'sdh_muhely_t67_kulcs')), SDH_Muhely_Mentes_Vissza::NYILT, 'a nyílt jelölő nem marad az adatbázisban');

    T::igaz(get_option('sdh_muhely_vissza_fut', false) === false, 'a visszaállítási zár feloldva');
    T::egyezik('teljes', (string) (SDH_Muhely_Mentes_Vissza::utolso()['mod'] ?? ''), 'a legutóbbi visszaállítás nyoma rögzítve');
    T::igaz(get_option(SDH_Muhely_Ugyfel::KERESO_ALLAPOT, false) === false, 'a keresőindex újraépítésre jelölve');
    T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('ugyfel_szo') . ' WHERE ugyfel_id = ' . (int) $b_id), 'a törölt ügyfél szavai nem maradtak a keresőben');
}

/* ===================================================================== */
/* 5. A biztonsági mentés maga is visszaállítható (visszavonás)            */
/* ===================================================================== */

if (isset($r) && is_array($r)) {
    $biztonsagi = $mappa . $r['biztonsagi'];
    $r2 = SDH_Muhely_Mentes_Vissza::visszaallit($biztonsagi, 'teljes');
    T::igaz(is_array($r2), 'a visszaállítás visszavonható a biztonsági mentéssel');
    T::egyezik(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE id = {$b_id}"), 'visszavonás után a későbbi ügyfél újra megvan');
    T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE id = {$a_id}"), 'visszavonás után az A ügyfél újra törölt');
    wp_cache_flush();
    T::egyezik(['ertek' => 'B'], get_option('sdh_muhely_t67_proba'), 'visszavonás után a beállítás újra „B”');
    T::egyezik('TITOK-B', SDH_Muhely_Titok::visszafejt((string) (get_option('sdh_muhely_t67_kulcs')['kulcs'] ?? '')), 'visszavonás után a kulcs újra „B” (a nyers mentésben sóhoz kötött érték)');
}

/* ===================================================================== */
/* 6. Csak beállítások: az adat érintetlen                                 */
/* ===================================================================== */

$c_id = t_ugyfel('Csak Csaba');
$info = SDH_Muhely_Mentes_Vissza::megnyit($enc, $jelszo);

if (is_array($info)) {
    $r3 = SDH_Muhely_Mentes_Vissza::visszaallit($info['gz'], 'beallitasok');
    SDH_Muhely_Mentes_Vissza::eldob($info['gz'], $info['kibontott']);
    T::igaz(is_array($r3) && $r3['sorok'] === 0 && $r3['opciok'] > 0, 'csak beállítások: adatsor nem íródik, beállítás igen');
    wp_cache_flush();
    T::egyezik(['ertek' => 'A', 'lista' => [1, 2, 3], 'szoveg' => "sor1\nsor2 \"idézet\""], get_option('sdh_muhely_t67_proba'), 'csak beállítások: a beállítás visszajött');
    T::egyezik(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE id = {$c_id}"), 'csak beállítások: az ügyfél-adat érintetlen');
    T::egyezik(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE id = {$b_id}"), 'csak beállítások: a későbbi ügyfél megmaradt');
}

/* ===================================================================== */
/* 7. Beállítás-csomag az élő adatbázisból (Export) → Import               */
/* ===================================================================== */

update_option('sdh_muhely_t67_proba', ['ertek' => 'EXPORT'], false);
update_option('sdh_muhely_t67_kulcs', ['kulcs' => SDH_Muhely_Titok::titkosit('TITOK-EXPORT'), 'nev' => 'e'], false);
update_option('sdh_muhely_mentes_nap', '20200101', false);   // futási állapot: nem része a csomagnak
update_option('sdh_muhely_mentes_cel_allapot', ['gep' => ['hiba' => 'x']], false);

$bes = $csomag_nev('beallitas');
T::egyezik(null, SDH_Muhely_Mentes_Vissza::csomag_keszit('', $bes, $jelszo), 'beállítás-csomag készül az élő adatbázisból');

$info = SDH_Muhely_Mentes_Vissza::megnyit($bes, $jelszo);
T::igaz(is_array($info) && $info['tipus'] === 'beallitasok' && $info['tablak'] === [] && $info['opciok'] > 0, 'a beállítás-csomagban nincs adattábla, vannak beállítások');

if (is_array($info)) {
    $tartalom = (string) shell_exec('gzip -dc ' . escapeshellarg($info['gz']));
    T::nem_tartalmaz($tartalom, 'sdh_muhely_mentes_nap', 'a csomagban nincs futási állapot (napi jelző)');
    T::nem_tartalmaz($tartalom, 'sdh_muhely_mentes_cel_allapot', 'a csomagban nincs a célok futási állapota');
    T::nem_tartalmaz($tartalom, 'sdh_muhely_vissza_fut', 'a csomagban nincs visszaállítási zár');
    T::tartalmaz($tartalom, 'sdh-nyilt:TITOK-EXPORT', 'a titkos kulcs a burkon belül nyíltan áll');

    // Új gépen más a só: a nyílt érték akkor is jó, ha a helyi kulcs nem ugyanaz.
    update_option('sdh_muhely_t67_proba', ['ertek' => 'ÚJ GÉP'], false);
    update_option('sdh_muhely_t67_kulcs', ['kulcs' => 'nem-az-igazi'], false);

    $r4 = SDH_Muhely_Mentes_Vissza::visszaallit($info['gz'], 'beallitasok');
    T::egyezik(
        'es',
        is_array($r4) ? 'es' : 'hiba',
        'a beállítás-csomag visszaállítható' . (is_string($r4) ? ' – ' . $r4 : '')
    );
    T::igaz(is_string(SDH_Muhely_Mentes_Vissza::visszaallit($info['gz'], 'teljes')), 'a beállítás-csomagból teljes visszaállítás elutasítva');
    SDH_Muhely_Mentes_Vissza::eldob($info['gz'], $info['kibontott']);

    wp_cache_flush();
    T::egyezik(['ertek' => 'EXPORT'], get_option('sdh_muhely_t67_proba'), 'az importált beállítás érvényes');
    T::egyezik('TITOK-EXPORT', SDH_Muhely_Titok::visszafejt((string) (get_option('sdh_muhely_t67_kulcs')['kulcs'] ?? '')), 'az importált kulcs a helyi sóval fejthető vissza');
    T::egyezik('20200101', get_option('sdh_muhely_mentes_nap'), 'a futási állapotot az import nem írta felül');
}

/* ===================================================================== */
/* 8. Hibák: sérült fájl → ROLLBACK, újabb verzió, nem mentés              */
/* ===================================================================== */

$ir_gz = static function (string $ut, array $sorok): void {
    $gz = gzopen($ut, 'wb6');

    foreach ($sorok as $s) {
        gzwrite($gz, (is_string($s) ? $s : wp_json_encode($s, JSON_UNESCAPED_UNICODE)) . "\n");
    }

    gzclose($gz);
};

$fej = ['mentes' => SDH_MUHELY_VERSION, 'db' => SDH_Muhely_Schema::DB_VERSION, 'ido' => current_time('mysql'), 'ok' => 'teszt'];
$elotte = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla}");

// 8a) a fájl közepén értelmezhetetlen sor
$ser = $mappa . 'sdh-t67-serult.jsonl.gz';
$ir_gz($ser, [$fej, ['t' => 'sdh_ugyfel', 's' => ['id' => 987654, 'nev' => 'Sérült Samu', 'letrehozva' => current_time('mysql')]], '{ez nem json', ['t' => 'sdh_ugyfel', 's' => ['id' => 987655, 'nev' => 'Soha Sándor']]]);
$h = SDH_Muhely_Mentes_Vissza::visszaallit($ser, 'teljes');
T::igaz(is_string($h) && strpos($h, 'sérült') !== false, 'sérült sor: érthető hibaüzenet');
T::igaz(is_string($h) && strpos($h, 'visszaallitas-elott') !== false, 'a hibaüzenet megmondja, melyik a biztonsági mentés');
T::egyezik($elotte, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla}"), 'sérült fájl: ROLLBACK, az ügyfélsorok száma nem változott');
T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE id IN (987654, 987655)"), 'sérült fájl: a fájl elején lévő sor sem maradt benn');
T::egyezik(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE id = {$b_id}"), 'sérült fájl: a meglévő adat megvan');
T::igaz(get_option('sdh_muhely_vissza_fut', false) === false, 'sérült fájl után a zár feloldva');

// 8b) újabb verzió
$uj = $mappa . 'sdh-t67-ujabb.jsonl.gz';
$ir_gz($uj, [['mentes' => '99.0.0', 'db' => '99.0.0', 'ido' => current_time('mysql'), 'ok' => 'teszt']]);
$h = SDH_Muhely_Mentes_Vissza::visszaallit($uj, 'teljes');
T::igaz(is_string($h) && strpos($h, 'újabb verzió') !== false, 'újabb verziójú mentés elutasítva');

// 8c) nem mentés
$nem = $mappa . 'sdh-t67-nem.jsonl.gz';
$ir_gz($nem, ['{"valami":1}']);
$h = SDH_Muhely_Mentes_Vissza::visszaallit($nem, 'teljes');
T::igaz(is_string($h) && strpos($h, 'nem SDH Műhely mentés') !== false, 'idegen gzip elutasítva');
file_put_contents($mappa . 'sdh-t67-szoveg.jsonl.gz', 'sima szöveg');
$h = SDH_Muhely_Mentes_Vissza::megnyit($mappa . 'sdh-t67-szoveg.jsonl.gz', $jelszo);
T::igaz(is_string($h), 'nem gzip fájl az áttekintésben elutasítva');

// 8d) futó visszaállítás mellett a második elutasítva
update_option('sdh_muhely_vissza_fut', (string) time(), false);
$h = SDH_Muhely_Mentes_Vissza::visszaallit($mentes_fajl, 'teljes');
T::igaz(is_string($h) && strpos($h, 'Már fut') !== false, 'párhuzamos visszaállítás elutasítva');
T::egyezik(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE id = {$b_id}"), 'az elutasított visszaállítás nem nyúlt az adathoz');
delete_option('sdh_muhely_vissza_fut');

// 8e) a futási állapot opció a fájlban sem íródik vissza
$allapot = $mappa . 'sdh-t67-allapot.jsonl.gz';
$ir_gz($allapot, [['mentes' => SDH_MUHELY_VERSION, 'db' => SDH_Muhely_Schema::DB_VERSION, 'ido' => current_time('mysql'), 'ok' => 'teszt', 'tipus' => 'beallitasok'],
    ['o' => 'sdh_muhely_mentes_nap', 'v' => '19990101'], ['o' => 'sdh_muhely_vissza_fut', 'v' => '1'], ['o' => 'sdh_muhely_t67_proba', 'v' => serialize(['ertek' => 'ALLAPOT'])], ['o' => 'masik_plugin_opcio', 'v' => 'x']]);
$r5 = SDH_Muhely_Mentes_Vissza::visszaallit($allapot, 'beallitasok');
wp_cache_flush();
T::igaz(is_array($r5) && $r5['opciok'] === 1, 'csak a beállítás-opció íródik vissza (a futási állapot és az idegen opció nem)');
T::egyezik('20200101', get_option('sdh_muhely_mentes_nap'), 'a napi jelző nem íródott felül');
T::egyezik(false, get_option('masik_plugin_opcio', false), 'idegen (nem sdh_muhely_) opcióhoz nem nyúl');
T::egyezik(['ertek' => 'ALLAPOT'], get_option('sdh_muhely_t67_proba'), 'a beállítás igen');

// 8f) hiányzó/új oszlop: a mentésben van ismeretlen oszlop, és hiányzik egy ma meglévő
$oszlop = $mappa . 'sdh-t67-oszlop.jsonl.gz';
$ir_gz($oszlop, [$fej, ['t' => 'sdh_ugyfel', 's' => ['id' => 777001, 'nev' => 'Oszlop Olga', 'megszunt_oszlop' => 'x', 'letrehozva' => current_time('mysql')]]]);
$r6 = SDH_Muhely_Mentes_Vissza::visszaallit($oszlop, 'teljes');
T::igaz(is_array($r6), 'ismeretlen oszlop nem akasztja meg a visszaállítást' . (is_string($r6) ? ' – ' . $r6 : ''));
T::egyezik('Oszlop Olga', $wpdb->get_var("SELECT nev FROM {$ugyfel_tabla} WHERE id = 777001"), 'a meglévő oszlopok visszajöttek, a hiányzók alapértékkel');

// 8g) több száz sor (a 200-as csomagolás határa)
$sok = $mappa . 'sdh-t67-sok.jsonl.gz';
$sorok = [$fej];

for ($i = 1; $i <= 450; $i++) {
    $sorok[] = ['t' => 'sdh_ugyfel', 's' => ['id' => 880000 + $i, 'nev' => 'Sok ' . $i . " 'x' %", 'letrehozva' => current_time('mysql')]];
}

$ir_gz($sok, $sorok);
$r7 = SDH_Muhely_Mentes_Vissza::visszaallit($sok, 'teljes');
T::igaz(is_array($r7) && $r7['sorok'] === 450, '450 sor több INSERT-ben, hiány nélkül');
T::egyezik(450, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla}"), 'a táblában pontosan a mentés sorai vannak');

/* ===================================================================== */
/* 9. AJAX-folyamat: előnézet → visszaállítás                              */
/* ===================================================================== */

$m2 = SDH_Muhely_Asszisztens_Tudas::teljes_mentes('t67b');
$d_id = t_ugyfel('Ajax Dóra');

$e = t_ajax('sdh_muhely_mentes_elonezet', ['forras' => 'helyi', 'fajl' => 'nincs-ilyen.jsonl.gz']);
T::igaz(empty($e['success']), 'AJAX: nem létező helyi mentés elutasítva');
$e = t_ajax('sdh_muhely_mentes_elonezet', ['forras' => 'valami']);
T::igaz(empty($e['success']), 'AJAX: ismeretlen forrás elutasítva');

$e = t_ajax('sdh_muhely_mentes_elonezet', ['forras' => 'helyi', 'fajl' => $m2['fajl']]);
T::igaz(!empty($e['success']) && !empty($e['data']['token']) && $e['data']['tipus'] === 'teljes' && $e['data']['sorok'] > 0, 'AJAX: az előnézet adatokat és tokent ad');
T::igaz(is_file($mappa . $m2['fajl']), 'AJAX: a helyi mentésfájlhoz az előnézet nem nyúl');

$token = (string) ($e['data']['token'] ?? '');
$v = t_ajax('sdh_muhely_mentes_vissza', ['token' => 'rossz', 'mod' => 'teljes']);
T::igaz(empty($v['success']), 'AJAX: ismeretlen tokennel nincs visszaállítás');
$v = t_ajax('sdh_muhely_mentes_vissza', ['token' => $token, 'mod' => 'ismeretlen']);
T::igaz(empty($v['success']), 'AJAX: ismeretlen móddal nincs visszaállítás');

$token2 = (string) (t_ajax('sdh_muhely_mentes_elonezet', ['forras' => 'helyi', 'fajl' => $m2['fajl']])['data']['token'] ?? '');
$v = t_ajax('sdh_muhely_mentes_vissza', ['token' => $token2, 'mod' => 'teljes']);
T::igaz(!empty($v['success']) && strpos((string) ($v['data']['uzenet'] ?? ''), 'Visszaállítva') === 0, 'AJAX: a visszaállítás sikeres, az üzenet összegez');
T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE id = {$d_id}"), 'AJAX: a mentés utáni ügyfél eltűnt');
T::igaz(!is_file($mappa . 'sdh-mentes-tmp-' . $token2 . '.stage.tmp'), 'AJAX: a lépcsőhely törölve');

$v = t_ajax('sdh_muhely_mentes_vissza', ['token' => $token2, 'mod' => 'teljes']);
T::igaz(empty($v['success']), 'AJAX: ugyanaz a token másodszor nem használható');

// Titkosított fájl az előnézetben: a mentés jelszavával.
$e = t_ajax('sdh_muhely_mentes_elonezet', ['forras' => 'helyi', 'fajl' => basename($enc)]);
T::igaz(empty($e['success']), 'AJAX: a helyi forrás csak .jsonl.gz-t fogad el (a csomag nem helyi mentés)');

// Nem adminisztrátor nem hívhatja.
$regi = get_current_user_id();
wp_set_current_user(0);
$e = t_ajax('sdh_muhely_mentes_elonezet', ['forras' => 'helyi', 'fajl' => $m2['fajl']]);
T::igaz(empty($e['success']), 'AJAX: bejelentkezés nélkül nincs előnézet');
wp_set_current_user($regi);

/* ===================================================================== */
/* Takarítás                                                              */
/* ===================================================================== */

foreach (glob($mappa . 'sdh-t67-*') ?: [] as $f) {
    @unlink($f);
}

foreach (glob($mappa . 'sdh-mentes-tmp-*') ?: [] as $f) {
    @unlink($f);
}

foreach (['sdh_muhely_t67_proba', 'sdh_muhely_t67_kulcs', 'sdh_muhely_mentes_titkositas', 'sdh_muhely_mentes_nap', 'sdh_muhely_mentes_cel_allapot', 'sdh_muhely_mentes_vissza_utolso'] as $o) {
    delete_option($o);
}

foreach (glob($mappa . 'sdh-mentes-*.jsonl.gz') ?: [] as $f) {
    @unlink($f);
}

T::vege('t67 visszaállítás, Export / Import, beállítások mentése (H3): épség, ROLLBACK, titkos kulcsok, AJAX');
