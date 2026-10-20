<?php
/** t220 – igényfelmérő (egyedi): validálás, becslés-vázlat, fájlfeltöltés védett mappába, fiók-lista, értesítők; lead-űrlapok. */
require __DIR__ . '/_t210-seged.php';
t_katalogus_betolt();
global $wpdb;

$l = SDH_Sales_Egyedi::lepesek();
$o = static fn (string $n): array => array_values(array_filter(array_merge(...array_map(static fn ($x) => $x['mezok'], $l)), static fn ($m) => $m['nev'] === $n))[0]['opciok'];
$jo = [
    'iparag' => $o('iparag')[0], 'meret' => $o('meret')[1], 'telephely' => '2',
    'folyamatok' => [$o('folyamatok')[0], $o('folyamatok')[1], $o('folyamatok')[6]], 'integraciok' => [$o('integraciok')[0], $o('integraciok')[3]],
    'leiras' => 'Szeretnénk a beszállítói rendeléseket automatikusan átvenni és a munkalaphoz kötni.', 'hatarido' => $o('hatarido')[1], 'keret' => $o('keret')[1],
    'nev' => 'Nagy Anna', 'ceg' => 'Anna Szerviz Kft.', 'email' => 'anna@example.com', 'telefon' => '+36 20 555 1234', 'nda' => '1', 'adatkezeles' => '1',
];

T::igaz(count($l) === 4, '4 lépés');

// Validálás
$e = SDH_Sales_Egyedi::ellenoriz(['folyamatok' => ['Nem létező'], 'iparag' => 'Hamis'] + $jo);
T::igaz(isset($e['hibak']['folyamatok']) && isset($e['hibak']['iparag']), 'ismeretlen opciók elutasítva');
$e = SDH_Sales_Egyedi::ellenoriz(array_merge($jo, ['email' => 'rossz', 'leiras' => '']));
T::igaz(isset($e['hibak']['email']) && isset($e['hibak']['leiras']), 'kötelező mezők, e-mail');
$e = SDH_Sales_Egyedi::ellenoriz($jo);
T::egyezik([], $e['hibak'], 'jó adat');
T::igaz($e['adat']['nda'] === true, 'NDA-kérés');

// Becslés-vázlat
$v = SDH_Sales_Egyedi::vazlat($e['adat']);
T::igaz($v['becsles'] === true && strpos($v['cimke'], 'BECSLÉS') === 0, 'BECSLÉS jelölés');
T::igaz($v['ora_min'] > 0 && $v['ora_max'] > $v['ora_min'], 'óra-sáv');
T::egyezik($v['ora_min'] * $v['oradij'], $v['ar_min'], 'ár = óra × óradíj');
T::egyezik(14900, $v['oradij'], 'óradíj: a beállítás alapértéke, ha nincs modulfejlesztés-szolgáltatás');
SDH_Sales_Tartalom::ment('szolgaltatas', 'modulfejlesztes', ['kulcs' => 'modulfejlesztes', 'nev' => 'Fejlesztés', 'tipus' => 'egyszeri', 'ar' => 16000]);
T::egyezik(16000, SDH_Sales_Egyedi::vazlat($e['adat'])['oradij'], 'óradíj a modulfejlesztés szolgáltatás árából');
T::igaz(count($v['tetelek']) === 1 + 3 + 2 + 1, 'tételek: felmérés + 3 folyamat + 2 integráció + átadás');
$kicsi = SDH_Sales_Egyedi::vazlat(['folyamatok' => [$o('folyamatok')[0]], 'meret' => $o('meret')[0], 'telephely' => 1]);
T::igaz($kicsi['ora_max'] < $v['ora_max'], 'kisebb igény → kisebb becslés');
T::tartalmaz(implode(' ', $v['osszefoglalo']), 'NDA', 'összefoglaló: NDA');

// Fájl-ellenőrzés
$dir = sys_get_temp_dir() . '/t220-' . getmypid();
wp_mkdir_p($dir);
$f = static function (string $nev, string $tart) use ($dir): array { file_put_contents("$dir/$nev", $tart); return ['name' => $nev, 'tmp_name' => "$dir/$nev", 'error' => 0, 'size' => strlen($tart), 'type' => 'x']; };
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
T::igaz(SDH_Sales_Egyedi::fajl_ellenoriz($f('minta.pdf', $pdf))['ok'], 'PDF elfogadva');
T::igaz(!SDH_Sales_Egyedi::fajl_ellenoriz($f('x.php', '<?php echo 1;'))['ok'], 'PHP elutasítva');
T::igaz(!SDH_Sales_Egyedi::fajl_ellenoriz($f('kep.php.png', 'x'))['ok'], 'kettős kiterjesztés elutasítva');
T::igaz(!SDH_Sales_Egyedi::fajl_ellenoriz($f('hamis.pdf', '<?php system($_GET[1]);'))['ok'], 'PDF-nek álcázott PHP elutasítva');
T::igaz(!SDH_Sales_Egyedi::fajl_ellenoriz($f('rajz.svg', '<svg/>'))['ok'], 'SVG elutasítva');
// Független ellenőrzés (2026-10-20), alacsony 10: tartalom szerinti típus nélkül (nincs fileinfo) a fájl nem fogadható el.
add_filter('sdh_sales_feltoltes_mime', '__return_empty_string');
T::igaz(!SDH_Sales_Egyedi::fajl_ellenoriz($f('minta2.pdf', $pdf))['ok'], 'alacsony-10: MIME-ellenőrzés nélkül a PDF sem megy át');
remove_filter('sdh_sales_feltoltes_mime', '__return_empty_string');
T::igaz(!SDH_Sales_Egyedi::fajl_ellenoriz(['size' => SDH_Sales_Egyedi::MAX_FAJL_BAJT + 1] + $f('nagy.txt', 'x'))['ok'], 'túl nagy elutasítva');

// REST beküldés fájllal
$GLOBALS['sdh_t_levelek'] = [];
$q = new WP_REST_Request('POST', '/' . SDH_Sales_Rest::NS . '/egyedi');
$q->set_body_params($jo + t_vedelem());
$q->set_file_params(['fajlok' => ['name' => ['minta.pdf'], 'type' => ['application/pdf'], 'tmp_name' => ["$dir/minta.pdf"], 'error' => [0], 'size' => [strlen($pdf)]]]);
$f('minta.pdf', $pdf);
$r = t_latogato(static fn () => rest_do_request($q));
T::egyezik(201, $r->get_status(), 'REST /egyedi 201');
$d = $r->get_data();
T::igaz(preg_match('/^E-\d{4}-\d{5}$/', (string) $d['azon']) === 1, 'azonosító E-ÉÉÉÉ-NNNNN');
T::egyezik(1, $d['fajlok'], '1 fájl mentve');
$lead = $wpdb->get_row("SELECT * FROM " . SDH_Sales_Schema::t('lead') . " WHERE forras = 'egyedi' ORDER BY id DESC LIMIT 1", ARRAY_A);
$la = json_decode((string) $lead['adat'], true);
T::egyezik('anna@example.com', $lead['email'], 'lead e-mail');
T::igaz(!empty($la['vazlat']['ora_min']), 'lead: vázlat mentve');
$mf = SDH_Sales_Egyedi::mappa() . '/' . $la['fajlok'][0]['fajl'];
T::igaz(is_file($mf), 'fájl a védett mappában');
T::tartalmaz(SDH_Sales_Egyedi::mappa(), '/portal/egyedi', 'semleges mappanév (uploads/portal)');
T::igaz(is_file(SDH_Sales_Egyedi::mappa() . '/.htaccess') && is_file(SDH_Sales_Egyedi::mappa() . '/index.php'), 'mappa közvetlenül nem elérhető');
T::igaz(preg_match('/^\d+-[0-9a-f]{24}\.pdf$/', $la['fajlok'][0]['fajl']) === 1, 'kitalálhatatlan fájlnév');
T::egyezik(2, count($GLOBALS['sdh_t_levelek']), 'admin-értesítés + visszaigazolás');
$fl = t_rest('GET', '/admin/egyedi/' . (int) $lead['id'] . '/fajl/0');
T::igaz($fl->get_status() === 200 && strpos((string) $fl->get_data(), '%PDF') === 0, 'admin letöltés');
T::egyezik(401, t_latogato(static fn () => t_rest('GET', '/admin/egyedi/' . (int) $lead['id'] . '/fajl/0'))->get_status(), 'látogató nem tölthet le');

// Hibás fájl → 400, nincs lead
$n0 = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('lead'));
$q2 = new WP_REST_Request('POST', '/' . SDH_Sales_Rest::NS . '/egyedi');
$q2->set_body_params($jo + t_vedelem());
$f('rossz.pdf', '<?php x');
$q2->set_file_params(['fajlok' => ['name' => ['rossz.pdf'], 'type' => [''], 'tmp_name' => ["$dir/rossz.pdf"], 'error' => [0], 'size' => [7]]]);
T::egyezik(400, t_latogato(static fn () => rest_do_request($q2))->get_status(), 'rossz fájl: 400');
T::egyezik($n0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('lead')), 'rossz fájlnál nincs lead');
T::egyezik(400, t_latogato(static fn () => t_rest('POST', '/egyedi', array_merge($jo, ['adatkezeles' => ''], t_vedelem())))->get_status(), 'adatkezelés elfogadása kötelező');

// Fiókban
$fl = SDH_Sales_Egyedi::fiok_lista('anna@example.com');
T::egyezik($d['azon'], $fl[0]['azon'] ?? '', 'fiók: igény listázva');
T::egyezik('Beérkezett', $fl[0]['allapot_szoveg'] ?? '', 'fiók: állapot');
T::igaz(isset($fl[0]['becsles']['ar_min']), 'fiók: becslés');

// Lead-űrlapok
$GLOBALS['sdh_t_levelek'] = [];
$tema = SDH_Sales_Urlap::mezok('kapcsolat')[4]['opciok'][1];
$v = t_latogato(static fn () => t_rest('POST', '/lead', ['tipus' => 'kapcsolat', 'nev' => 'Kovács Béla', 'email' => 'bela@example.com', 'tema' => $tema, 'uzenet' => 'Bemutatót kérnék.', 'adatkezeles' => '1'] + t_vedelem()));
T::egyezik(201, $v->get_status(), 'kapcsolat 201');
T::egyezik(2, count($GLOBALS['sdh_t_levelek']), 'kapcsolat: értesítés + visszaigazolás');
T::egyezik('kapcsolat', $wpdb->get_var('SELECT forras FROM ' . SDH_Sales_Schema::t('lead') . ' ORDER BY id DESC LIMIT 1'), 'lead forrás');
$v = t_latogato(static fn () => t_rest('POST', '/lead', ['tipus' => 'ajanlat', 'nev' => 'X Y', 'email' => 'x@example.com', 'adatkezeles' => '1'] + t_vedelem()));
T::igaz($v->get_status() === 400 && isset($v->get_data()['hibak']['ceg'], $v->get_data()['hibak']['telephely']), 'ajánlatkérés: kötelező mezők');
$v = t_latogato(static fn () => t_rest('POST', '/lead', ['tipus' => 'partner', 'nev' => 'P', 'email' => 'p@example.com', 'ceg' => 'P Kft', 'partner_tipus' => 'hamis', 'uzenet' => 'x', 'adatkezeles' => '1'] + t_vedelem()));
T::igaz(isset($v->get_data()['hibak']['partner_tipus']), 'partner: listán kívüli érték');
T::egyezik(400, t_latogato(static fn () => t_rest('POST', '/lead', ['tipus' => 'hack'] + t_vedelem()))->get_status(), 'ismeretlen űrlap');

array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);
T::vege('t220 egyedi + lead');
