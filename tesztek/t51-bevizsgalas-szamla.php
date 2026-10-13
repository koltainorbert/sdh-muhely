<?php
/**
 * t51 – bevizsgálási díj után a záró számla és a pénztár a MARADÉKOT viszi.
 *
 * A bejelentett eset: két eszköz, mindkettő 8 000 Ft bevizsgálási díjat fizet
 * (külön számla + pénztártétel eszközönként), aztán eszközönként 10 000 Ft termék
 * kerül a lapra. Záráskor eszközönként 2 000 Ft számla és 2 000 Ft pénztártétel
 * kell, nem 10 000 Ft.
 *
 * A Számlázz.hu-t hamis válasz helyettesíti – valódi számla nem készül.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

add_filter('option_sdh_muhely_szamla', static fn ($ertek) => ['kulcs' => 'teszt-kulcs'] + (is_array($ertek) ? $ertek : []));
add_filter('default_option_sdh_muhely_szamla', static fn () => ['kulcs' => 'teszt-kulcs']);

$sorszam = 0;
$kuldott = [];

add_filter('pre_http_request', static function ($elozo, $argumentumok, $cim) use (&$sorszam, &$kuldott) {
    $sorszam++;
    $kuldott[] = (string) ($argumentumok['body'] ?? '');

    return [
        'headers'  => ['szlahu_szamlaszam' => 'TESZT-2026-' . $sorszam, 'szlahu_nettovegosszeg' => '0', 'szlahu_bruttovegosszeg' => '0'],
        'body'     => '%PDF-1.4 teszt',
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies'  => [],
        'filename' => null,
    ];
}, 10, 3);

$tt = SDH_Muhely_Schema::tabla('munkalap_tetel');
$pt = SDH_Muhely_Schema::tabla('penztar');
$st = SDH_Muhely_Schema::tabla('szamla');

$ment = static function (int $id, array $mezok = []): array {
    $lap     = t_lap($id);
    $csatolt = SDH_Muhely_Munkalap::tovabbi_reszletek($id);
    $post    = [
        'id' => $id, 'ugyfel_id' => (int) $lap->ugyfel_id, 'eszkoz_id' => (int) $lap->eszkoz_id, 'eszkozok_jelen' => '1',
        'allapot' => (string) $lap->allapot, 'keszult' => current_time('Y-m-d'),
        'fizetett' => (string) (float) $lap->fizetett, 'bevizsgalasi_dij' => (int) $lap->bevizsgalasi_dij === 1 ? '1' : '',
        'bevizsgalas_mod' => 'levon', 'tovabbi_eszkoz' => [], 'tovabbi_dij' => [],
    ];

    foreach ($csatolt as $r) {
        $post['tovabbi_eszkoz'][]                 = (int) $r->eszkoz_id;
        $post['tovabbi_dij'][(int) $r->eszkoz_id] = (string) (float) $r->bev_dij;
    }

    return t_ajax('sdh_muhely_munkalapok_ment', array_filter($mezok + $post, static fn ($v) => $v !== ''), 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
};

$vevo = ['nev' => 'Teszt Vevő', 'orszag' => 'Magyarország', 'irsz' => '8200', 'telepules' => 'Veszprém', 'cim' => 'Teszt utca 1.', 'email' => '', 'adoszam' => ''];
$fej  = ['sorozat' => 'fo', 'vevo' => $vevo, 'fizmod' => 'Készpénz', 'megjegyzes' => 'Teszt'];

$u  = t_ugyfel('Díj Dóra');
$e1 = t_eszkoz($u, 'Apple', 'iPhone 15');
$e2 = t_eszkoz($u, 'Samsung', 'Galaxy S23');
$id = t_munkalap($u, [$e1]);

// 1) Két eszköz, 8 000 + 8 000 Ft bevizsgálási díj.
$r = $ment($id, ['fizetett' => '16000', 'bevizsgalasi_dij' => '1', 'tovabbi_eszkoz' => [$e2], 'tovabbi_dij' => [$e2 => '8000']]);
T::igaz(!empty($r['success']), 'a két díj menthető');

$dij = static function () use ($wpdb, $tt, $id): array {
    $ki = [];

    foreach ($wpdb->get_results($wpdb->prepare("SELECT id, eszkoz_id, forras, brutto_ertek, szamla FROM {$tt} WHERE munkalap_id = %d AND forras IN ('bevizsgalas','bevizsgalas_le')", $id)) as $s) {
        $ki[(int) $s->eszkoz_id][(string) $s->forras] = $s;
    }

    return $ki;
};

$d = $dij();
T::egyezik(8000.0, (float) $d[0]['bevizsgalas']->brutto_ertek, 'a fő eszköz díja 8 000');
T::egyezik(8000.0, (float) $d[$e2]['bevizsgalas']->brutto_ertek, 'a csatolt eszköz díja 8 000');

// 2) A díj bekerül a pénztárba eszközönként (az ajánlat elfogadása).
$ajanlat = $r['data']['penztar'] ?? null;
T::egyezik([8000.0, 8000.0], array_map(static fn ($e) => (float) $e['osszeg'], $ajanlat['eszkozok'] ?? []), 'az ajánlat 8 000 + 8 000');

$r = t_ajax('sdh_muhely_penztar_eszkozonkent', [
    'munkalap_id' => $id, 'mod' => 'kp', 'eloleg' => '1', 'szamla' => '',
    'osszegek' => wp_json_encode(['0' => 8000, (string) $e2 => 8000]),
]);
T::igaz(!empty($r['success']), 'a két díj a pénztárba kerül');

// 3) Eszközönként külön díjszámla (csak a díjsor).
foreach ([0 => $d[0]['bevizsgalas']->id, $e2 => $d[$e2]['bevizsgalas']->id] as $kulcs => $tid) {
    $r = t_ajax('sdh_muhely_szamla_ment', ['munkalap_id' => $id, 'eszkoz' => (string) $kulcs, 'tetel' => [(int) $tid]] + $fej);
    T::igaz(!empty($r['success']), 'a díjszámla kiállítható (' . $kulcs . ')');
}

$szamlak = $wpdb->get_results($wpdb->prepare("SELECT eszkoz_id, brutto, szamlaszam FROM {$st} WHERE munkalap_id = %d ORDER BY id", $id));
T::egyezik([8000.0, 8000.0], array_map(static fn ($s) => round((float) $s->brutto), $szamlak), 'a két díjszámla 8 000 + 8 000');

// 4) Eszközönként 10 000 Ft termék kerül a lapra.
// Ahogy az űrlap küldi: a lap tételsorai (a levonás sora nem megy be), a két új termékkel.
$sorok = ['szolgaltatas' => [], 'termek' => []];

foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM {$tt} WHERE munkalap_id = %d AND forras <> 'bevizsgalas_le' ORDER BY id", $id)) as $s) {
    $sorok[(string) $s->tipus][] = ['id' => (int) $s->id, 'eszkoz_id' => (int) $s->eszkoz_id, 'megnevezes' => $s->megnevezes, 'mennyiseg' => '1', 'me' => 'db', 'brutto_ar' => (string) (float) $s->brutto_ar, 'afa_kulcs' => (string) $s->afa_kulcs];
}

$sorok['termek'][] = ['id' => 0, 'eszkoz_id' => 0, 'megnevezes' => 'Kijelző', 'mennyiseg' => '1', 'me' => 'db', 'brutto_ar' => '10000', 'afa_kulcs' => '27'];
$sorok['termek'][] = ['id' => 0, 'eszkoz_id' => $e2, 'megnevezes' => 'Akkumulátor', 'mennyiseg' => '1', 'me' => 'db', 'brutto_ar' => '10000', 'afa_kulcs' => '27'];
$ment($id, ['tetelek_jelen' => '1', 'tetelek' => $sorok]);

$d = $dij();
T::egyezik(-8000.0, (float) ($d[0]['bevizsgalas_le']->brutto_ertek ?? 0), 'a fő eszköz díja levonódik');
T::egyezik(-8000.0, (float) ($d[$e2]['bevizsgalas_le']->brutto_ertek ?? 0), 'a csatolt eszköz díja levonódik');

$b = t_bontas($id);
T::egyezik(2000.0, $b[0]['fizetendo'], 'a fő eszköz fizetendője 2 000');
T::egyezik(2000.0, $b[$e2]['fizetendo'], 'a csatolt eszköz fizetendője 2 000');

// 5) A záró számla ablaka: eszközönként mi van kipipálva, és mennyi az összeg.
$urlap = t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id, 'sorozat' => 'fo'])['nyers'];

preg_match_all('/<input type="checkbox" name="tetel\[\]" value="(\d+)" data-sdh-szamla-tetel\s+data-eszkoz="(\d+)"\s+data-szamlazva="([01])"\s+data-netto="([^"]*)" data-brutto="([^"]*)"/', $urlap, $m, PREG_SET_ORDER);

$kulcsok = [0, $e2];
$ablak   = [];

foreach ($m as $x) {
    $ablak[(int) $x[2]][] = ['id' => (int) $x[1], 'szamlazva' => $x[3] === '1', 'brutto' => (float) $x[5]];
}

$nevek = [0 => 'Kijelző', $e2 => 'Akkumulátor'];

foreach ($kulcsok as $k) {
    $szabad = array_values(array_filter($ablak[$k] ?? [], static fn ($s) => !$s['szamlazva']));

    T::egyezik(1, count($szabad), 'a záró számla ablakában a ' . $k . '. kulcsú eszköznek egyetlen tétele van (díj és levonás nélkül)');
    T::egyezik(2000.0, round((float) ($szabad[0]['brutto'] ?? 0)), 'és az 2 000 Ft (a termék − a már kifizetett díj)');
    T::igaz(preg_match('/value="' . (int) ($szabad[0]['id'] ?? 0) . '"[^>]*>.*?title="' . $nevek[$k] . '"/s', $urlap) === 1, 'a tétel a termék saját nevén áll: ' . $nevek[$k]);
}

T::nem_tartalmaz($urlap, 'Díj levonása', 'a záró számla ablakában nincs levonás-sor');

// 6) A záró számla kiállítása eszközönként, a pipák szerint.
foreach ($kulcsok as $k) {
    $tetelek = [];

    foreach ($ablak[$k] ?? [] as $s) {
        if (!$s['szamlazva']) {
            $tetelek[] = $s['id'];
        }
    }

    $r = t_ajax('sdh_muhely_szamla_ment', ['munkalap_id' => $id, 'eszkoz' => (string) $k, 'tetel' => $tetelek] + $fej);
    T::igaz(!empty($r['success']), 'a záró számla kiállítható (' . $k . ')');
    T::egyezik(2000.0, round((float) ($r['data']['brutto'] ?? 0)), 'a záró számla összege 2 000 (' . $k . ')');

    $xml = (string) end($kuldott);
    T::tartalmaz($xml, '<megnevezes>' . $nevek[$k] . '</megnevezes>', 'a Számlázz.hu-nak a termék neve ment (' . $k . ')');
    T::egyezik(1, substr_count($xml, '<tetel>'), 'egyetlen tétel a számlán (' . $k . ')');
    T::nem_tartalmaz($xml, 'bevizsgálási díj', 'a díj nem került a záró számlára (' . $k . ')');
    T::nem_tartalmaz($xml, 'különbözet', 'nincs „különbözet” felirat (' . $k . ')');
}

// A munkalap tételei nem változtak: a termék ára továbbra is 10 000, a levonás −8 000.
$d = $dij();
T::egyezik(-8000.0, (float) $d[0]['bevizsgalas_le']->brutto_ertek, 'a munkalapon a levonás sora megmaradt');
T::egyezik(10000.0, (float) $wpdb->get_var($wpdb->prepare("SELECT brutto_ertek FROM {$tt} WHERE munkalap_id = %d AND megnevezes = 'Kijelző'", $id)), 'a munkalapon a termék ára 10 000 maradt');

/* --- Egy eszköz, javítás nélkül: a díj munkadíjként a számlára kerül ---------- */

$id9 = t_munkalap($u, [$e1]);
$ment($id9, ['fizetett' => '8000', 'bevizsgalasi_dij' => '1']);
$urlap9 = t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id9, 'sorozat' => 'fo'])['nyers'];
T::tartalmaz($urlap9, 'bevizsgálási díj', 'javítás nélkül a díj sora a számlán marad');
T::igaz(preg_match('/data-szamlazva="0"\s+data-netto="[^"]*" data-brutto="8000(\.0+)?"/', $urlap9) === 1, 'a díj 8 000 Ft-tal számlázható');

/* --- Egy eszköz, több tétel: a díj a legnagyobb tételből vonódik le ----------- */

$id8 = t_munkalap($u, [$e1]);
$ment($id8, ['fizetett' => '8000', 'bevizsgalasi_dij' => '1']);
t_tetel($id8, 'Fólia', 3000, 0, 'termek');
t_tetel($id8, 'Kijelzőcsere', 12000, 0);
$ment($id8);
$sorok8 = array_map(static fn ($t) => [(string) $t->megnevezes, round((float) $t->brutto_ertek)], SDH_Muhely_Szamla::tetelek($id8));
T::egyezik([['Kijelzőcsere', 4000.0], ['Fólia', 3000.0]], $sorok8, 'két tétel: 12 000 − 8 000 = 4 000 a kijelzőcserén, a fólia marad 3 000');

// 7) A pénztár: a záráskor ajánlott összeg eszközönként 2 000.
$lap = t_lap($id);
$r   = t_ajax('sdh_muhely_penztar_ajanlat', ['munkalap_id' => $id]);
$aj  = $r['data']['ajanlat'] ?? null;
T::egyezik(null, $aj, 'a pénztár magától nem ajánl újabb tételt: a díj már bent van (a Fizetés ablak 2 000 Ft-ot javasol)');

/* --- A díj azonnal látszik a Szolgáltatások között (mentés nélkül) ------------ */

$app = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.js');
T::tartalmaz($app, 'bevElonezet(gyoker, esz, eszSor);', 'a számolás minden változásnál frissíti az előnézeti sorokat');
T::tartalmaz($app, "s.removeAttribute('data-sdh-tetelsor');", 'az előnézeti sor nem számít bele kétszer az összegbe');
T::tartalmaz($app, "el.removeAttribute('name');", 'és nem küldődik be');

T::vege('t51 bevizsgálás után a záró számla és a pénztár');
