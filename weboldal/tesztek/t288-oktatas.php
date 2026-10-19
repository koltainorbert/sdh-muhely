<?php
/** t288 – oktató mód: leckék a tárból, kvíz szerveroldali értékeléssel, haladás API (user meta), fiók-szűrő, tanúsítvány + ellenőrző kód, Szikra oktató mód. */
require __DIR__ . '/_keret.php';

/** Rejtés (weboldal/REJTES.md): a kimenetben egyik SDH_Sales_Rejtes::TILTOTT minta sem lehet. */
$rejtett = static function ($kimenet, string $nev, array $fejlecek = []): void {
    if ($kimenet instanceof WP_REST_Response) {
        $fejlecek += $kimenet->get_headers();
        $kimenet   = $kimenet->get_data();
    }
    $s = is_string($kimenet) ? $kimenet : (string) wp_json_encode($kimenet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $t = SDH_Sales_Rejtes::vizsgal($s, $fejlecek);
    T::igaz($t === [] && $s !== '', 'rejtés: ' . $nev . ($t !== [] ? ' – ' . implode(' | ', array_slice($t, 0, 3)) : ($s === '' ? ' – üres kimenet' : '')));
};

// Leckék (a data/oktatas.json sémája szerint: {kulcs, modul, cim, cel, lepesek[], kviz[], ido_perc}).
SDH_Sales_Tartalom::ment('lecke', 't288-a', [
    'kulcs' => 't288-a', 'modul' => 't288kurzus', 'cim' => 'Első munkalap a {termeknev}-ben', 'cel' => 'Felveszel egy munkalapot.', 'ido_perc' => 5,
    'lepesek' => [['cim' => 'Új munkalap', 'szoveg' => 'Kattints a <strong>+ Új munkalap</strong> gombra.<script>x</script>'], 'Válaszd ki az ügyfelet.', ['cim' => 'Mentés', 'szoveg' => 'Kattints a **Munkalap létrehozása** gombra.', 'tipp' => 'A szám a mentés után látszik.']],
    'kviz'    => [
        ['kerdes' => 'Hol veszel fel új munkalapot?', 'valaszok' => ['Beállítások', '+ Új munkalap gomb', 'Napló'], 'helyes' => 1, 'magyarazat' => 'A + Új munkalap gomb nyitja.'],
        ['kerdes' => 'Mi kötelező?', 'valaszok' => ['Ügyfél', 'Eszköz', 'Kedvenc szín'], 'helyes' => [0, 1], 'magyarazat' => 'Ügyfél és eszköz kell.'],
    ],
]);
SDH_Sales_Tartalom::ment('lecke', 't288-b', [
    'kulcs' => 't288-b', 'modul' => 't288kurzus', 'cim' => 'Pénztárzárás', 'lepesek' => ['Számold meg a kasszát.', 'Zárd a napot.'],
    'kviz'  => [['kerdes' => 'Mikor zársz?', 'opciok' => ['Nap végén', 'Soha'], 'helyes' => 'Nap végén', 'magyarazat' => 'Minden nap végén.']],
]);
SDH_Sales_Tartalom::ment('lecke', 't288-ures', ['kulcs' => 't288-ures', 'modul' => 't288kurzus', 'cim' => 'Üres', 'lepesek' => []]);

$l = SDH_Sales_Oktatas::lecke('t288-a');
T::igaz($l !== null && count($l['lepesek']) === 3, 'lecke: három lépés (szöveg és objektum vegyesen)');
T::nem_tartalmaz((string) $l['cim'], '{termeknev}', 'tokenek cserélve');
T::egyezik(null, SDH_Sales_Oktatas::lecke('t288-ures'), 'lépés nélküli lecke kimarad');
T::egyezik([0], SDH_Sales_Oktatas::lecke('t288-b')['kviz'][0]['helyes'], 'helyes válasz szövegként is megadható');
$k = SDH_Sales_Oktatas::kurzusok()['t288kurzus'] ?? null;
T::egyezik(['t288-a', 't288-b'], $k['leckek'] ?? [], 'kurzus = a modul leckéi');
$ny = (string) wp_json_encode(SDH_Sales_Oktatas::nyilvanos());
T::igaz(strpos($ny, '"helyes"') === false && strpos($ny, 'magyarazat') === false, 'a kliens nem kapja meg a helyes választ és a magyarázatot előre');
T::igaz(strpos($ny, '"tobb":true') !== false, 'többválaszos kérdés jelölve');
T::tartalmaz(SDH_Sales_Oktatas::lepes_szoveg('t288-a', 2), 'Lépés 3/3: Mentés', 'Szikrának: a lépés szövege');
T::tartalmaz(SDH_Sales_Oktatas::lepes_szoveg('t288-a', 99), 'Lépés 3/3', 'túlindexelt lépés a végére szorítva');

// Kvíz REST (látogatóként).
$r = t_latogato(static fn () => t_rest('POST', '/oktatas/kviz', ['lecke' => 't288-a', 'valaszok' => [0, [0]]]));
$d = $r->get_data();
T::egyezik(200, $r->get_status(), 'kvíz 200');
T::egyezik([false, false], $d['helyes'], 'rossz válaszok');
T::igaz($d['sikeres'] === false && $d['igazolas'] === '', 'sikertelen: nincs igazolás');
T::egyezik('A + Új munkalap gomb nyitja.', $d['magyarazat'][0], 'magyarázat visszajön');
$r = t_latogato(static fn () => t_rest('POST', '/oktatas/kviz', ['lecke' => 't288-a', 'valaszok' => [1, [1, 0]]]));
$d = $r->get_data();
T::igaz($d['sikeres'] === true && $d['pont'] === 2, 'sikeres kvíz (többválaszos sorrendtől független)');
$ig_a = (string) $d['igazolas'];
T::igaz(SDH_Sales_Oktatas::igazolas_jo('t288-a', $ig_a), 'igazolás érvényes');
T::igaz(!SDH_Sales_Oktatas::igazolas_jo('t288-b', $ig_a), 'más leckére nem érvényes');
T::igaz(!SDH_Sales_Oktatas::igazolas_jo('t288-a', substr($ig_a, 0, -1) . (substr($ig_a, -1) === 'a' ? 'b' : 'a')), 'hamisított igazolás érvénytelen');
T::igaz(!SDH_Sales_Oktatas::igazolas_jo('t288-a', 't288-a.0.2.' . substr($ig_a, -20)), 'pontszám nem írható át');
T::egyezik(404, t_latogato(static fn () => t_rest('POST', '/oktatas/kviz', ['lecke' => 'nincs-ilyen', 'valaszok' => []]))->get_status(), 'ismeretlen lecke → 404');
$ig_b = (string) t_latogato(static fn () => t_rest('POST', '/oktatas/kviz', ['lecke' => 't288-b', 'valaszok' => [0]]))->get_data()['igazolas'];

// Tanúsítvány (látogató, igazolásokkal).
$r = t_latogato(static fn () => t_rest('POST', '/oktatas/tanusitvany', ['kurzus' => 't288kurzus', 'nev' => 'Minta Márta', 'igazolasok' => ['t288-a' => $ig_a]]));
T::egyezik(400, $r->get_status(), 'hiányzó kvíz → nincs tanúsítvány');
T::tartalmaz((string) $r->get_data()['message'], 'Pénztárzárás', 'megnevezi a hiányzó leckét');
T::egyezik(400, t_latogato(static fn () => t_rest('POST', '/oktatas/tanusitvany', ['kurzus' => 't288kurzus', 'nev' => 'X', 'igazolasok' => ['t288-a' => $ig_a, 't288-b' => $ig_b]]))->get_status(), 'név nélkül nincs tanúsítvány');
$r = t_latogato(static fn () => t_rest('POST', '/oktatas/tanusitvany', ['kurzus' => 't288kurzus', 'nev' => 'Minta Márta', 'igazolasok' => ['t288-a' => $ig_a, 't288-b' => $ig_b]]));
T::egyezik(200, $r->get_status(), 'tanúsítvány kiállítva');
$kod = (string) $r->get_data()['kod'];
T::igaz(preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $kod) === 1, 'olvasható ellenőrző kód');
$e = t_latogato(static fn () => t_rest('GET', '/oktatas/tanusitvany', ['kod' => strtolower(str_replace('-', '', $kod))]))->get_data();
T::igaz($e['ervenyes'] === true && $e['nev'] === 'Minta Márta' && $e['datum'] === current_time('Y-m-d'), 'ellenőrzés: név, dátum (kötőjel és kisbetű nélkül is)');
T::tartalmaz((string) $e['kurzus'], 'T288kurzus', 'ellenőrzés: kurzus');
T::igaz(t_latogato(static fn () => t_rest('GET', '/oktatas/tanusitvany', ['kod' => 'AAAA-BBBB-CCCC']))->get_data()['ervenyes'] === false, 'ismeretlen kód érvénytelen');
$h = SDH_Sales_Oktatas::tanusitvany_html(SDH_Sales_Oktatas::ellenoriz($kod));
foreach (['Minta Márta', $kod, 'Tanúsítvány', SDH_Sales::termeknev(), 'window.print()'] as $x) {
    T::tartalmaz($h, $x, 'nyomtatható tanúsítvány: ' . $x);
}
T::tartalmaz(SDH_Sales_Oktatas::tanusitvany_html(null), 'Érvénytelen', 'érvénytelen kód oldala');
T::igaz(preg_match('/#[0-9a-f]{3,6}\b/i', preg_replace('/:root\{[^}]*\}/', '', $h) ?? '') !== 1, 'tanúsítvány: szín csak változóból (az alapérték a :root-on)');

// Haladás API (bejelentkezett vevő).
$uid = wp_insert_user(['user_login' => 't288vevo' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 't288' . wp_rand() . '@example.com', 'role' => 'sdh_vevo']);
T::egyezik(401, t_latogato(static fn () => t_rest('GET', '/oktatas/haladas'))->get_status(), 'haladás: kijelentkezve nem érhető el');
$elozo = get_current_user_id();
wp_set_current_user((int) $uid);
T::egyezik([], t_rest('GET', '/oktatas/haladas')->get_data()['haladas'], 'üres haladás');
$r = t_rest('POST', '/oktatas/haladas', ['haladas' => ['t288-a' => ['lepes' => 1], 'nincs-ilyen' => ['lepes' => 3], 't288-b' => ['lepes' => 99, 'kviz' => ['pont' => 1, 'ossz' => 1, 'igazolas' => 'hamis.1.1.0000']]]]);
$hh = $r->get_data()['haladas'];
T::egyezik(1, $hh['t288-a']['lepes'] ?? -1, 'lépés mentve');
T::igaz(!isset($hh['nincs-ilyen']), 'ismeretlen lecke kimarad');
T::egyezik(1, $hh['t288-b']['lepes'] ?? -1, 'lépésszám a lecke hosszára szorítva');
T::egyezik('', $hh['t288-b']['kviz']['igazolas'] ?? 'x', 'hamis igazolás nem tárolódik');
t_rest('POST', '/oktatas/haladas', ['haladas' => ['t288-a' => ['lepes' => 0]]]);
T::egyezik(1, SDH_Sales_Oktatas::haladas((int) $uid)['t288-a']['lepes'], 'összefésülés: a nagyobb haladás marad');
t_rest('POST', '/oktatas/kviz', ['lecke' => 't288-a', 'valaszok' => [1, [0, 1]]]);
$hh = SDH_Sales_Oktatas::haladas((int) $uid);
T::igaz(!empty($hh['t288-a']['kesz']) && SDH_Sales_Oktatas::igazolas_jo('t288-a', (string) $hh['t288-a']['kviz']['igazolas']), 'bejelentkezve a sikeres kvíz a fiókba kerül');
t_rest('POST', '/oktatas/haladas', ['haladas' => ['t288-b' => ['kesz' => true, 'kviz' => ['pont' => 1, 'ossz' => 1, 'igazolas' => $ig_b]]]]);
// Tanúsítvány a fiókból: az igazolásokat a szerver a user metából veszi.
$r = t_rest('POST', '/oktatas/tanusitvany', ['kurzus' => 't288kurzus', 'nev' => 'Vevő Vilma']);
T::egyezik(200, $r->get_status(), 'tanúsítvány a fiók haladásából');
$f = apply_filters('sdh_sales_fiok_oktatas', [], (int) $uid);
$kk = array_values(array_filter($f['kurzusok'] ?? [], static fn ($x) => $x['modul'] === 't288kurzus'))[0] ?? [];
T::igaz(($kk['kesz'] ?? 0) === 2 && ($kk['ossz'] ?? 0) === 2 && ($kk['szazalek'] ?? 0) === 100, 'fiók-szűrő: kurzus-haladás');
T::egyezik($r->get_data()['kod'], $f['tanusitvanyok'][0]['kod'] ?? '', 'fiók-szűrő: tanúsítvány');
wp_set_current_user($elozo);

// Szikra oktató módban a lecke lépését kapja (határolt <lecke> blokk).
SDH_Sales::titkos_ment(SDH_Sales_Szikra::TITOK, 'sk-ant-teszt-0123456789abcdef');
SDH_Sales::b_ment(['szikra.aktiv' => true, 'szikra.havi_keret' => 50.0]);
add_filter('sdh_sales_korlat_ki', '__return_true');
$test = null;
t_http(static function (string $url, array $args) use (&$test): ?array {
    if (strpos($url, 'api.anthropic.com') === false) { return null; }
    $test = json_decode((string) $args['body'], true);
    return t_valasz(200, (string) wp_json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Így megy.']], 'usage' => []]));
});
$r = t_latogato(static fn () => t_rest('POST', '/szikra', ['munkamenet' => 'okt' . wp_generate_password(20, false), 'uzenet' => 'Magyarázd el ezt a lépést.', 'mod' => 'oktato', 'lecke' => 't288-a', 'lepes' => 2]));
T::egyezik(200, $r->get_status(), 'Szikra oktató mód: 200');
$s = implode("\n", array_column((array) ($test['system'] ?? []), 'text'));
T::igaz(preg_match('#<lecke>\nLecke: .*Lépés 3/3: Mentés.*</lecke>#su', $s) === 1, 'a lecke aktuális lépése határolt blokkban');
SDH_Sales::titkos_ment(SDH_Sales_Szikra::TITOK, '');

// Front.
$js = SDH_Sales_Oktatas::js_adat();
T::igaz(count(array_filter($js['leckek'], static fn ($x) => strpos($x['kulcs'], 't288') === 0)) === 2, 'front: leckék');
$f = (string) file_get_contents(SDH_SALES_DIR . 'assets/front/oktatas.js');
T::tartalmaz($f, 'localStorage', 'látogatói haladás localStorage-ban');
T::tartalmaz($f, '/haladas', 'bejelentkezve a REST-tel szinkronizál');
T::igaz(preg_match('/\b(alert|confirm)\s*\(/', $f) !== 1 && strpos($f, 'jQuery') === false, 'nincs alert/confirm/jQuery');
T::tartalmaz(SDH_Sales_Oktatas::shortcode(['modul' => 't288kurzus']), 'data-modul="oktatas"', 'csatolási pont');
T::tartalmaz($f, 'window.APP_OKTATAS', 'adat: window.APP_OKTATAS');
T::tartalmaz($f, 'window.APP.szikra', 'Szikra a közös APP-objektumon');
T::tartalmaz(SDH_Sales_Oktatas::tanusitvany_url('AAAA-BBBB-CCCC'), '?tanusitvany=', 'tanúsítvány-link semleges paraméterrel');
// Rejtés: JS, adat, shortcode, tanúsítvány-oldal, REST-válaszok.
$rejtett($f, 'oktatas.js tartalma');
$rejtett($js, 'APP_OKTATAS adat');
$rejtett(SDH_Sales_Oktatas::shortcode(['modul' => 't288kurzus']) . do_shortcode('[oktatas modul="t288kurzus"]'), 'shortcode HTML');
$rejtett($h, 'nyomtatható tanúsítvány');
$rejtett(SDH_Sales_Oktatas::tanusitvany_html(null), 'érvénytelen tanúsítvány oldala');
$rejtett(t_latogato(static fn () => t_rest('POST', '/oktatas/kviz', ['lecke' => 't288-a', 'valaszok' => [1, [1, 0]]])), 'kvíz REST-válasz');
$rejtett(t_latogato(static fn () => t_rest('POST', '/oktatas/kviz', ['lecke' => 'nincs-ilyen', 'valaszok' => []])), 'kvíz hibaválasz');
$rejtett(t_latogato(static fn () => t_rest('GET', '/oktatas/tanusitvany', ['kod' => $kod])), 'tanúsítvány-ellenőrzés REST');

T::vege('t288 oktatás');
