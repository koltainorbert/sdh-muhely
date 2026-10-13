<?php
/**
 * t50 – RMA-matrica Brother címkenyomtatóra (0.59).
 *
 * Beállítás (alapok, tisztítás, sorrend), adatok eszközönként, elrendezés
 * (kicsinyítés, ha nem fér ki), PDF (oldalak = matricák × példány), előnézeti
 * kép, Brother QL raszter (parancsok, sorhossz, szélesség), közvetlen
 * nyomtatás egy helyi „nyomtatóra" (TCP), a Matrica ablak és gomb, az
 * előnézet a Beállításokban, a modul ki- és bekapcsolása, a Napló;
 * 0.59.3: termék- és szolgáltatás-címke (közös nyomtató és tekercs).
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$M = 'SDH_Muhely_Matrica';
delete_option($M::OPT);

/* --- Beállítás --------------------------------------------------------------- */

$b = $M::beallitas();
$alap8 = ['nev', 'munkalapszam', 'eszkoz', 'rma', 'hiba', 'feloldas', 'imei', 'datum'];
T::egyezik($alap8, array_slice(array_keys(array_filter($b['mezok'], static fn ($m) => $m['be'])), 0, 8), 'alapból a kért nyolc adat, a kért sorrendben');
T::egyezik(8, count(array_filter($b['mezok'], static fn ($m) => $m['be'])), 'alapból pontosan nyolc bekapcsolva');
T::egyezik('bongeszo', $b['mod'], 'alap mód: böngésző');
T::igaz(count($M::nyomtatok()) >= 20 && count($M::cimkek()) >= 15, 'nyomtatók és címkék listája');

$t = $M::tisztit(['nyomtato' => 'nincsilyen', 'mod' => 'x', 'ip' => 'rossz cím!', 'port' => '999999', 'cimke' => '29x90', 'meret' => '99', 'sorrend' => ['imei', 'nev'], 'mezok' => ['imei' => ['be' => '1', 'felirat' => 'IMEI<b>', 'meret' => '9']]]);
T::egyezik('QL-820NWB', $t['nyomtato'], 'ismeretlen nyomtató: alapérték');
T::egyezik('bongeszo', $t['mod'], 'ismeretlen mód: böngésző');
T::egyezik('', $t['ip'], 'hibás IP: üres');
T::egyezik(65535, $t['port'], 'port a határon belül');
T::egyezik(16.0, (float) $t['meret'], 'betűméret a határon belül');
T::egyezik(['imei', 'nev'], array_slice(array_keys($t['mezok']), 0, 2), 'a beküldött sorrend');
T::egyezik(3.0, (float) $t['mezok']['imei']['meret'], 'a mezőméret a határon belül');
T::nem_tartalmaz($t['mezok']['imei']['felirat'], '<', 'a felirat tisztítva');
T::igaz(!$t['mezok']['nev']['be'], 'a nem pipált mező kikapcsol');

/* --- Adatok ------------------------------------------------------------------- */

$u  = t_ugyfel('Matrica Mária');
$e1 = t_eszkoz($u, 'Samsung', 'Galaxy A17');
$e2 = t_eszkoz($u, 'Apple', 'iPhone 12');
$wpdb->update(SDH_Muhely_Schema::tabla('eszkoz'), ['zarkod' => '2468', 'minta' => '1-4-7-8', 'sorozatszam' => 'SN123', 'modell_szam' => 'SM-A175B'], ['id' => $e1]);
$id = t_munkalap($u, [$e1, $e2]);
$wpdb->insert(SDH_Muhely_Schema::tabla('munkalap_hiba'), ['munkalap_id' => $id, 'eszkoz_id' => 0, 'leiras' => 'Törött kijelző', 'allapot' => 'uj']);
$wpdb->insert(SDH_Muhely_Schema::tabla('munkalap_hiba'), ['munkalap_id' => $id, 'eszkoz_id' => $e2, 'leiras' => 'Nem tölt', 'allapot' => 'uj']);

$a = $M::adatok($id);
T::egyezik(2, count($a), 'két eszköz: két matrica');
T::tartalmaz((string) $a[0]['nev'], 'Matrica Mária', 'név');
T::egyezik(SDH_Muhely_Munkalap::szam_formaz(t_lap($id)->munkalap_szam), $a[0]['munkalapszam'], 'munkalapszám');
T::egyezik('Samsung Galaxy A17 · SM-A175B', $a[0]['eszkoz'], 'eszköz típusa és azonosítója');
T::egyezik('Törött kijelző', $a[0]['hiba'], 'a fő eszköz hibája');
T::egyezik('Nem tölt', $a[$e2]['hiba'], 'a második eszköz saját hibája');
T::egyezik('2468', $a[0]['kod'], 'feloldó kód');
T::egyezik('1478', $a[0]['minta'], 'feloldó minta');
T::tartalmaz($a[0]['imei'], 'SN SN123', 'IMEI és sorozatszám');
T::igaz(preg_match('/^[0-9A-F]{4}-[0-9A-F]{4}$/', (string) $a[0]['rma']) === 1, 'rövid RMA-kód');
T::igaz((string) $a[0]['url'] !== '', 'QR: az ügyféloldal címe');
T::egyezik('1/2', $a[0]['darab'], 'sorszám: 1/2');

update_option($M::OPT, $M::tisztit(['eszkozonkent' => false] + $M::alapok()));
T::egyezik(1, count($M::adatok($id)), 'eszközönként kikapcsolva: csak a fő eszköz');
delete_option($M::OPT);

/* --- Elrendezés ------------------------------------------------------------- */

$minta = $M::minta_adat();
$r = $M::elrendez($minta, $b, 59.0, 42.0);
$tipusok = array_count_values(array_map(static fn ($e) => $e['t'], $r['elemek']));
T::igaz(!$r['levagva'], 'alapbeállítással kifér (62 mm, 45 mm)');
T::egyezik(1, $tipusok['qr'] ?? 0, 'van QR-kód');
T::egyezik(1, $tipusok['minta'] ?? 0, 'van feloldó minta rajz');
T::igaz(($tipusok['szoveg'] ?? 0) >= 9, 'a szöveges sorok');
$szoveg = wp_json_encode($r['elemek'], JSON_UNESCAPED_UNICODE);
foreach (['Minta Márta', '000123', 'SM-A175B', '3F9A-12C4', 'Törött kijelző', '1234', '1-4-7-8-9', '356789012345678'] as $resz) {
    T::tartalmaz($szoveg, $resz, 'a matricán: ' . $resz);
}

$kicsi = $M::elrendez($minta, $b, 15.0, 12.0);
T::igaz($kicsi['skala'] < 1.0, 'kis címkén kicsinyít (' . round($kicsi['skala'] * 100) . '%)');
T::igaz($kicsi['levagva'], 'apró címkén jelzi, hogy nem fér ki minden');

$csak = $b;
foreach ($csak['mezok'] as $k => $m) {
    $csak['mezok'][$k]['be'] = $k === 'nev';
}
$csak['mezok']['vonalkod']['be'] = true;
$r = $M::elrendez($minta, $csak, 50.0, 25.0);
$tipusok = array_count_values(array_map(static fn ($e) => $e['t'], $r['elemek']));
T::egyezik(['szoveg' => 1, 'vonalkod' => 1], $tipusok, 'csak a bekapcsolt adatok (név + vonalkód)');

$modulok = SDH_Muhely_Kodok::code128_modulok('123456');
T::egyezik(11 * 5 + 13, array_sum($modulok), 'Code 128: a modulok összege (start + 3 pár + ellenőrző + stop)');

/* --- PDF és kép ------------------------------------------------------------- */

$pdf = $M::pdf([$a[0], $a[$e2]], $b, 2);
T::igaz(str_starts_with($pdf, '%PDF'), 'PDF készül');
T::egyezik(4, preg_match_all('/\/Type\s*\/Page\b/', $pdf), 'oldalak: 2 matrica × 2 példány');
T::igaz(preg_match('~/MediaBox \[0(\.00)? 0(\.00)? 175\.75 127\.56\]~', $pdf) === 1, 'a lap mérete a címkéé (62 × 45 mm)');

$png = $M::png($minta, $b, 400);
T::igaz(str_starts_with($png, "\x89PNG"), 'előnézeti kép (PNG)');
$kep = imagecreatefromstring($png);
T::egyezik(400, imagesx($kep), 'a kép a kért szélességre kicsinyítve');

/* --- Brother QL raszter ----------------------------------------------------- */

$bq = $M::tisztit(['nyomtato' => 'QL-820NWB', 'mod' => 'halozat', 'ip' => '127.0.0.1', 'cimke' => '62x29'] + $M::alapok());
$ra = $M::raszter([$minta], $bq, 1);
T::egyezik(str_repeat("\x00", 400) . "\x1B\x40\x1B\x69\x61\x01", substr($ra, 0, 406), 'kezdés: 400 nullbájt, alaphelyzet, raszter mód');
$p = strpos($ra, "\x1B\x69\x7A");
T::igaz($p !== false, 'ESC i z (a címke adatai)');
T::egyezik("\x0B\x3E\x1D", substr($ra, $p + 4, 3), 'stancolt címke, 62 × 29 mm');
$sorok = unpack('V', substr($ra, $p + 7, 4))[1];
T::egyezik(271, $sorok, 'a sorok száma = a nyomtatható hossz (271 pont)');
T::egyezik($sorok, substr_count($ra, "\x67\x00\x5A"), 'minden sor „g 0x00 90" fejjel');
T::egyezik("\x1A", substr($ra, -1), 'a végén: nyomtatás (0x1A)');
T::igaz(strpos($ra, "\x1B\x69\x4D\x40") !== false, 'automatikus vágás be');

$ra2 = $M::raszter([$minta, $minta], $M::tisztit(['cimke' => '62'] + $bq), 1);
T::egyezik(1, substr_count($ra2, "\x0C\x1B\x69\x53"), 'két matrica: köztük lapdobás (0x0C)');
$p = strpos($ra2, "\x1B\x69\x7A");
T::egyezik("\x0A\x3E\x00", substr($ra2, $p + 4, 3), 'folyamatos 62 mm-es szalag');

$szeles = $M::tisztit(['nyomtato' => 'QL-1110NWB', 'cimke' => '102x51'] + $bq);
$ra3 = $M::raszter([$minta], $szeles, 1);
T::igaz(substr_count($ra3, "\x67\x00\xA2") > 400, 'széles QL: 162 bájtos sorok');
T::egyezik(str_repeat("\x00", 200) . "\x1B\x40", substr($ra3, 0, 202), 'széles QL: 200 nullbájt');

T::egyezik(null, $M::kozvetlen_hiba($bq), 'QL-820NWB + 62×29 + IP: közvetlenül nyomtatható');
T::igaz(is_string($M::kozvetlen_hiba(['nyomtato' => 'PT-P750W'] + $bq)), 'P-touch: csak böngészőből');
T::igaz(is_string($M::kozvetlen_hiba(['ip' => ''] + $bq)), 'IP nélkül nem');
T::igaz(is_string($M::kozvetlen_hiba(['cimke' => '102'] + $bq)), '102 mm keskeny QL-en nem');
T::igaz(is_string($M::kozvetlen_hiba(['nyomtato' => 'QL-800'] + $bq)), 'USB-s QL: csak böngészőből');

/* --- Közvetlen nyomtatás egy helyi „nyomtatóra" ----------------------------- */

$szerver = @stream_socket_server('tcp://127.0.0.1:0', $hsz, $hszoveg);
T::igaz($szerver !== false, 'helyi próbanyomtató indul');

if ($szerver !== false) {
    $port = (int) substr((string) stream_socket_get_name($szerver, false), strrpos((string) stream_socket_get_name($szerver, false), ':') + 1);

    update_option($M::OPT, $M::tisztit(['mod' => 'halozat', 'ip' => '127.0.0.1', 'port' => $port, 'cimke' => '62x29'] + $M::alapok()));
    $r = t_ajax('sdh_muhely_matrica_nyomtat', ['id' => $id, 'eszkoz' => [0], 'peldany' => 1]);
    T::igaz(!empty($r['success']), 'közvetlen nyomtatás: elküldve');

    $kapcs = @stream_socket_accept($szerver, 2);
    $kapott = '';

    if ($kapcs) {
        stream_set_timeout($kapcs, 2);

        while (!feof($kapcs)) {
            $d = fread($kapcs, 65536);

            if ($d === false || $d === '') {
                break;
            }

            $kapott .= $d;
        }

        fclose($kapcs);
    }

    T::igaz(strlen($kapott) > 20000 && substr($kapott, -1) === "\x1A", 'a nyomtató megkapta a teljes rasztert (' . strlen($kapott) . ' bájt)');
    fclose($szerver);

    $l = SDH_Muhely_Valtozasnaplo::lista(['rekord' => 'munkalap:' . $id, 'muvelet' => 'nyomtatas'])['sorok'];
    T::igaz($l !== [] && str_contains((string) $l[0]->osszegzes, 'RMA-matrica kinyomtatva'), 'a nyomtatás a lap Naplójában');

    update_option($M::OPT, $M::tisztit(['mod' => 'halozat', 'ip' => '127.0.0.1', 'port' => $port] + $M::alapok()));
    $r = t_ajax('sdh_muhely_matrica_nyomtat', ['id' => $id, 'eszkoz' => [0]]);
    T::igaz(empty($r['success']) && str_contains((string) ($r['data']['uzenet'] ?? ''), 'nem érhető el'), 'zárt port: érthető hibaüzenet');
}

delete_option($M::OPT);

/* --- A Matrica ablak és a gomb ---------------------------------------------- */

$h = t_ajax('sdh_muhely_matrica_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
T::tartalmaz($h, 'data-sdh-matrica-ablak', 'a Matrica ablak');
T::egyezik(2, substr_count($h, 'data-sdh-matrica-eszkoz'), 'eszközönként egy választó');
T::egyezik(2, substr_count($h, 'data:image/png;base64,'), 'eszközönként egy előnézet');
T::tartalmaz($h, 'data-sdh-matrica-nyomtat', 'Nyomtatás gomb');
T::tartalmaz($h, 'sdh-matrica__tipp', 'böngészős módban tipp: tekercs és papírméret');
T::egyezik('62mm', $M::papir_nev($M::tisztit(['cimke' => '62'])), 'papírméret: 62 mm folyamatos');
T::egyezik('29mm x 90mm', $M::papir_nev($M::tisztit(['cimke' => '29x90'])), 'papírméret: 29 × 90 mm stancolt');
T::egyezik('62.5mm x 40mm', $M::papir_nev($M::tisztit(['cimke' => 'egyedi', 'egyedi_sz' => '62.5', 'egyedi_h' => '40'])), 'papírméret: egyedi');
T::tartalmaz($M::gomb($id), 'data-sdh-matrica="' . $id . '"', 'a munkalap Matrica gombja');

$r = t_ajax('sdh_muhely_matrica_kep', ['id' => $id, 'kulcs' => $e2]);
T::igaz(!empty($r['success']) && str_starts_with((string) base64_decode((string) $r['data']['png']), "\x89PNG"), 'egy eszköz előnézete külön');

$r = t_ajax('sdh_muhely_matrica_elonezet', ['matrica' => ['cimke' => '29x90', 'tajolas' => 'fekvo', 'sorrend' => $alap8, 'mezok' => ['nev' => ['be' => '1', 'meret' => '1'], 'datum' => ['be' => '1', 'meret' => '1']]]]);
T::igaz(!empty($r['success']) && (string) ($r['data']['meret'] ?? '') === '90 × 29 mm', 'élő előnézet a Beállításokban: fekvő 29 × 90');
$r = t_ajax('sdh_muhely_matrica_proba', ['matrica' => ['mod' => 'bongeszo']]);
T::igaz(!empty($r['success']) && str_starts_with((string) base64_decode((string) $r['data']['pdf']), '%PDF'), 'próbanyomat böngészős módban: PDF');

/* --- Beállítások lapfül ------------------------------------------------------ */

T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['matrica']), 'van RMA-matrica lapfül');
ob_start();
$M::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::egyezik(count($M::mezok()) + count($M::mezok('termek')) + count($M::mezok('szolgaltatas')), substr_count($doboz, 'data-sdh-matrica-mezo='), 'minden sablon minden adatának saját sora');
T::egyezik(3, substr_count($doboz, 'data-sdh-matrica-sablon-gomb='), 'sablonválasztó: RMA, termék, szolgáltatás');
T::egyezik(1, substr_count($doboz, 'id="matrica_cimke"'), 'a tekercs egyszer, közösen');
T::tartalmaz($doboz, 'data-sdh-matrica-fel', 'sorrend: nyilak');
T::tartalmaz($doboz, 'data-sdh-matrica-proba', 'Próbanyomat gomb');
T::nem_tartalmaz($doboz, 'type="date"', 'nincs natív dátummező');

$_POST = ['matrica_jelen' => '1', 'matrica' => ['nyomtato' => 'QL-810W', 'cimke' => '62', 'hossz' => '50', 'sorrend' => array_merge(['datum'], array_diff(array_keys($M::mezok()), ['datum'])), 'mezok' => ['datum' => ['be' => '1', 'felirat' => 'Felvéve', 'meret' => '1', 'felkover' => '1'], 'nev' => ['be' => '1', 'meret' => '1.15']]]];
$M::beallitas_mentes();
$_POST = [];
$m = $M::beallitas();
T::egyezik('QL-810W', $m['nyomtato'], 'mentés: nyomtató');
T::egyezik('datum', array_key_first($m['mezok']), 'mentés: sorrend');
T::egyezik('Felvéve', $m['mezok']['datum']['felirat'], 'mentés: felirat');
T::igaz(!$m['mezok']['hiba']['be'] && !$m['vagas'], 'mentés: a nem pipált kikapcsol');
delete_option($M::OPT);


/* --- Termék- és szolgáltatás-címke (0.59.3) ------------------------------------- */

delete_option($M::opt('termek'));
delete_option($M::opt('szolgaltatas'));
T::egyezik('sdh_muhely_matrica_termek', $M::opt('termek'), 'termékcímke: saját opció');
T::egyezik('rma', $M::sablon('nincsilyen'), 'ismeretlen sablon: rma');
$bt = $M::beallitas('termek');
T::egyezik(['megnevezes', 'ar', 'cikkszam', 'vonalkod'], array_keys(array_filter($bt['mezok'], static fn ($m) => $m['be'])), 'termékcímke alapból: név, ár, cikkszám, vonalkód');
T::egyezik(['nev', 'ar', 'kategoria'], array_keys(array_filter($M::beallitas('szolgaltatas')['mezok'], static fn ($m) => $m['be'])), 'szolgáltatás-címke alapból: név, ár, kategória');
update_option($M::OPT, $M::tisztit(['nyomtato' => 'QL-700', 'cimke' => '29x90']));
T::igaz($M::beallitas('termek')['nyomtato'] === 'QL-700' && $M::beallitas('termek')['cimke'] === '29x90', 'a nyomtató és a tekercs közös (az RMA-matricáé)');
update_option($M::opt('termek'), ['cimke' => '62', 'nyomtato' => 'QL-810W', 'hossz' => 25]);
T::igaz($M::beallitas('termek')['cimke'] === '29x90' && (int) $M::beallitas('termek')['hossz'] === 25, 'a sablon saját tekercse nem érvényes, a hossza igen');
delete_option($M::OPT);
delete_option($M::opt('termek'));

$wpdb->insert(SDH_Muhely_Schema::tabla('termek'), ['megnevezes' => 'Teszt akkumulátor', 'cikkszam' => 'AK-77', 'vonalkod' => '5990000000017', 'brutto_ar' => 12700, 'afa_kulcs' => '27', 'me' => 'db', 'aktiv' => 1]);
$tid = (int) $wpdb->insert_id;
$wpdb->insert(SDH_Muhely_Schema::tabla('termek'), ['megnevezes' => 'Kód nélküli', 'cikkszam' => 'CSAK-CIKK', 'vonalkod' => '', 'brutto_ar' => 0, 'aktiv' => 1]);
$tid2 = (int) $wpdb->insert_id;
$wpdb->insert(SDH_Muhely_Schema::tabla('szolgaltatas'), ['nev' => 'Teszt kijelzőcsere', 'kulcs' => md5('t50-szolg-' . microtime()), 'brutto_ar' => 12000, 'ar_max' => 18000, 'afa_kulcs' => '27', 'kod' => 'TKC', 'kategoria' => 'Javítás']);
$sid = (int) $wpdb->insert_id;

$ta = $M::termek_adat($tid);
T::egyezik('12 700 Ft', $ta['ar'], 'termék: bruttó ár');
T::egyezik('10 000 Ft', $ta['netto'], 'termék: nettó ár (27%)');
T::egyezik('5990000000017', $ta['vonalkod_ertek'], 'termék: a vonalkód a termék vonalkódja');
T::egyezik('CSAK-CIKK', $M::termek_adat($tid2)['vonalkod_ertek'], 'vonalkód nélkül: a cikkszám');
T::egyezik('', $M::termek_adat($tid2)['ar'], 'ár nélkül nincs ársor');
T::egyezik(null, $M::termek_adat(999999), 'nincs ilyen termék');
$sa = $M::szolgaltatas_adat($sid);
T::egyezik('12 000 – 18 000 Ft', $sa['ar'], 'szolgáltatás: ársáv');
T::egyezik('TKC', $sa['vonalkod_ertek'], 'szolgáltatás: a kód');
T::egyezik(1, count($M::lista('termek', $tid)), 'termékcímke: egy címke');
T::egyezik([], $M::lista('szolgaltatas', 999999), 'nincs ilyen szolgáltatás: üres');

$bt = $M::beallitas('termek');
$d  = (function () use ($M, $bt) { return (new ReflectionMethod($M, 'doboz'))->invoke(null, $bt, false); })();
$r  = $M::elrendez($ta, $bt, $d['cw'], $d['ch']);
$tipusok = array_column($r['elemek'], 't');
T::igaz(in_array('vonalkod', $tipusok, true), 'termékcímke: van vonalkód');
$vk = array_values(array_filter($r['elemek'], static fn ($e) => $e['t'] === 'vonalkod'))[0];
T::igaz(abs(($vk['x'] * 2 + $vk['w']) - $d['cw']) < 0.01, 'a vonalkód középen');
$szovegek = implode(' | ', array_map(static fn ($e) => implode('', array_column($e['futasok'], 0)), array_filter($r['elemek'], static fn ($e) => $e['t'] === 'szoveg')));
T::tartalmaz($szovegek, '12 700 Ft', 'a címkén az ár');
T::tartalmaz($szovegek, 'Teszt akkumulátor', 'a címkén a név');
T::tartalmaz($szovegek, '5990000000017', 'a vonalkód alatt olvashatóan a kód');
T::igaz(!$r['levagva'], 'a termékcímke kifér');

$pdf = $M::pdf([$ta], $bt, 3);
T::egyezik(3, preg_match_all('/\/Type\s*\/Page\b/', $pdf), 'termékcímke PDF: 3 példány = 3 oldal');
T::tartalmaz($M::gomb($tid, 'termek'), 'data-sdh-matrica-tipus="termek"', 'a termék űrlapjának Címke gombja');
T::tartalmaz($M::gomb($sid, 'szolgaltatas'), '<span>Címke</span>', 'a szolgáltatás űrlapjának Címke gombja');
T::nem_tartalmaz($M::gomb($id), 'data-sdh-matrica-tipus', 'a munkalap gombja változatlan');

$h = t_ajax('sdh_muhely_matrica_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $tid, 'tipus' => 'termek'])['nyers'];
T::tartalmaz($h, 'data-tipus="termek"', 'termékcímke ablak');
T::tartalmaz($h, 'Teszt akkumulátor', 'az ablakban a termék neve');
T::egyezik(1, substr_count($h, 'data:image/png;base64,'), 'egy előnézet');
$h = t_ajax('sdh_muhely_matrica_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 999999, 'tipus' => 'szolgaltatas'])['nyers'];
T::tartalmaz($h, 'Nincs ilyen szolgáltatás', 'nem létező szolgáltatás: hibaüzenet');

$r = t_ajax('sdh_muhely_matrica_elonezet', ['sablon' => 'szolgaltatas', 'matrica' => ['cimke' => '62', 'hossz' => '40'], 'matrica_szolgaltatas' => ['hossz' => '28', 'sorrend' => array_keys($M::mezok('szolgaltatas')), 'mezok' => ['nev' => ['be' => '1', 'meret' => '1.15'], 'ar' => ['be' => '1', 'meret' => '1.9']]]]);
T::igaz(!empty($r['success']) && $r['data']['png'] !== '', 'szolgáltatás-címke élő előnézet');
T::tartalmaz((string) $r['data']['meret'], '× 28 mm', 'az előnézet a sablon saját hosszával');

$_POST = ['matrica_jelen' => '1', 'matrica' => ['nyomtato' => 'QL-700', 'cimke' => '62', 'hossz' => '45', 'sorrend' => array_keys($M::mezok())],
    'matrica_termek_jelen' => '1', 'matrica_termek' => ['hossz' => '22', 'cimke' => '29x90', 'sorrend' => ['ar', 'megnevezes'], 'mezok' => ['ar' => ['be' => '1', 'meret' => '1.9'], 'megnevezes' => ['be' => '1', 'meret' => '1']]]];
$M::beallitas_mentes();
$_POST = [];
$bt = $M::beallitas('termek');
T::igaz((int) $bt['hossz'] === 22 && $bt['cimke'] === '62', 'mentés: a termékcímke hossza saját, a tekercs közös');
T::egyezik('ar', array_key_first($bt['mezok']), 'mentés: a termékcímke sorrendje');
T::igaz(!$bt['mezok']['cikkszam']['be'], 'mentés: a nem pipált kikapcsol');
T::igaz(!array_key_exists('nyomtato', (array) get_option($M::opt('termek'))), 'a közös részt a sablon nem tárolja');
delete_option($M::OPT);
delete_option($M::opt('termek'));

$wpdb->delete(SDH_Muhely_Schema::tabla('termek'), ['id' => $tid]);
$wpdb->delete(SDH_Muhely_Schema::tabla('termek'), ['id' => $tid2]);
$wpdb->delete(SDH_Muhely_Schema::tabla('szolgaltatas'), ['id' => $sid]);

/* --- Modul ki / be ----------------------------------------------------------- */

$katalogus = SDH_Muhely_Modulok::katalogus();
T::igaz(isset($katalogus['matrica']), 'a Modulok között ott az RMA-matrica');
update_option(SDH_Muhely_Modulok::OPT_KI, ['matrica']);
SDH_Muhely_Modulok::kapcsolok_urit();
T::egyezik('', $M::gomb($id), 'kikapcsolva: nincs Matrica gomb');
T::igaz(!isset(SDH_Muhely_Beallitasok::szakaszok()['matrica']), 'kikapcsolva: nincs lapfül');
$_REQUEST['action'] = 'sdh_muhely_matrica_urlap';
ob_start();
try {
    SDH_Muhely_Modul_Kapcsolo::orszem();
} catch (T_Ajax_Vege $v) {
    // a válasz kész
}
$o = (string) ob_get_clean();
unset($_REQUEST['action']);
T::tartalmaz($o, 'Kikapcsolt modul', 'kikapcsolva: a végpont elutasít');
update_option(SDH_Muhely_Modulok::OPT_KI, []);
SDH_Muhely_Modulok::kapcsolok_urit();
T::igaz($M::gomb($id) !== '', 'visszakapcsolva: újra van gomb');

T::vege('t50 RMA-matrica: beállítás, adatok, elrendezés, PDF, raszter, hálózati nyomtatás, ablak, modul');
