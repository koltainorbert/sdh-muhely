<?php
/**
 * t58 – 0.64.0: gyors ügyfélkereső.
 *
 * - A keresőszöveg: kisbetűs, ékezet nélküli; telefon számjegyei; fordított telefon.
 * - Séma: kereso / tel_ford oszlop és index.
 * - Mentéskor és AI-módosításkor frissül; a változásnapló nem látja (csendben).
 * - A meglévő sorok kötegelt feltöltése (több kéréssel is végigér, a végén „kesz").
 * - Gyors út = régi út (ha legfeljebb tíz a találat): név eleje / belseje, ékezet,
 *   telefon eleje / vége / része, e-mail, ügyfélszám; legfeljebb tíz sor; inaktív nem jön.
 * - Szó-index: keresztnév, település, utca, 06-os / +36-os telefon, ügyfélszám számjegyei;
 *   több szó együtt (mindegyikre kell egyező szó); e-mail-domain, házszám, „utca" nem keresőszó.
 * - A „tartalmazza" (teljes olvasás) csak akkor fut, ha más úton nincs találat.
 * - cim_rendez: a rács „Cím" kifejezésével azonos érték.
 * - Az ügyféllista keresése a gyors úton is ugyanazt találja.
 * - A böngészőoldal: 100 ms késleltetés, megszakítás, ugyanarra a szövegre azonnali lista,
 *   az összefoglaló nem töltődik újra minden leütésre.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$U  = 'SDH_Muhely_Ugyfel';
$t  = SDH_Muhely_Schema::tabla('ugyfel');
$cimke = 'qxz58';

/* --- Takarítás a futás elejére és végére -------------------------------------- */

$takarit = static function () use ($wpdb, $t, $cimke): void {
    $wpdb->query('DELETE FROM ' . SDH_Muhely_Schema::tabla('ugyfel_szo') . " WHERE ugyfel_id IN (SELECT id FROM {$t} WHERE kulso_azonosito = 't58' OR nev LIKE '%{$cimke}%')");
    $wpdb->query("DELETE FROM {$t} WHERE kulso_azonosito = 't58' OR nev LIKE '%{$cimke}%'");
};

$takarit();

/* --- 1. A keresőszöveg ----------------------------------------------------------- */

$a = $U::kereso_adat([
    'nev' => 'Kovács Árpád Őrmező', 'szamlazasi_telepules' => 'Veszprém', 'szamlazasi_cim' => 'Kossuth utca 1.',
    'szamlazasi_iranyitoszam' => '8200', 'email' => 'Arpad@Example.com', 'ugyfel_szam' => 'U-000123',
    'telefon' => '+36 30 123-4567', 'telefon2' => '06 (20) 765 4321',
]);
T::tartalmaz($a['kereso'], 'kovacs arpad ormezo', 'a név kisbetűs és ékezet nélküli');
T::egyezik(0, strpos($a['kereso'], 'kovacs'), 'a név áll elöl (az előtag-keresés erre épül)');
T::tartalmaz($a['kereso'], 'arpad@example.com', 'az e-mail kisbetűs');
T::tartalmaz($a['kereso'], 'u-000123', 'az ügyfélszám benne van');
T::tartalmaz($a['kereso'], '36301234567', 'az első telefon számjegyei');
T::tartalmaz($a['kereso'], '06207654321', 'a második telefon számjegyei');
T::egyezik('76543210363', $a['tel_ford'], 'a fordított telefon a számjegyek megfordítottja');
T::igaz(mb_strlen($U::kereso_adat(['nev' => str_repeat('a', 400)])['kereso']) <= 190, 'a keresőszöveg legfeljebb 190 karakter');

/* --- 2. Séma ------------------------------------------------------------------------ */

$oszlopok = array_map(static fn ($o) => is_object($o) ? ($o->Field ?? $o->name ?? '') : (string) $o, (array) $wpdb->get_results("SHOW COLUMNS FROM {$t}"));
T::igaz(in_array('kereso', $oszlopok, true) && in_array('tel_ford', $oszlopok, true), 'a kereso és a tel_ford oszlop létezik');

$kulcsok = [];

foreach ((array) $wpdb->get_results("SHOW INDEX FROM {$t}", ARRAY_A) as $k) {
    $kulcsok[] = (string) ($k['Key_name'] ?? $k['key_name'] ?? '');
}

T::igaz(in_array('kereso', $kulcsok, true) && in_array('tel_ford', $kulcsok, true), 'a kereso és a tel_ford indexelt');
T::igaz(version_compare(SDH_Muhely_Schema::DB_VERSION, '0.43.0', '>='), 'a séma verziója emelve');

/* --- 3. Mentés és napló ----------------------------------------------------------- */

$beszur = static function (array $mezok) use ($wpdb, $t): int {
    $wpdb->insert($t, $mezok + [
        'aktiv' => 1, 'forras' => 'kezi', 'kulso_azonosito' => 't58', 'letrehozva' => current_time('mysql'),
    ]);

    return (int) $wpdb->insert_id;
};

$id1 = $beszur([
    'nev' => "Árpád {$cimke} Kovács", 'telefon' => '+36 30 555 7788', 'email' => "{$cimke}mail@example.com",
    'szamlazasi_telepules' => 'Veszprém', 'szamlazasi_cim' => 'Piac utca 20.', 'ugyfel_szam' => 'U-9158001',
]);
$id2 = $beszur(['nev' => "Béla {$cimke} Nagy", 'telefon' => '06 20 111 2233', 'szamlazasi_telepules' => 'Balatonfüred', 'ugyfel_szam' => 'U-9158002']);
$id3 = $beszur(['nev' => "Zoltán {$cimke}", 'telefon' => '+36 70 999 0000', 'ugyfel_szam' => 'U-9158003']);
$id4 = $beszur(['nev' => "Inaktív {$cimke}", 'telefon' => '+36 70 888 0000', 'aktiv' => 0, 'ugyfel_szam' => 'U-9158004']);

$naplo_t = SDH_Muhely_Schema::tabla(SDH_Muhely_Valtozasnaplo::TABLA);
$naplo_elott = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$naplo_t}");

foreach ([$id1, $id2, $id3, $id4] as $id) {
    $U::kereso_frissit($id);
}

T::egyezik(
    $naplo_elott,
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$naplo_t}"),
    'a kereső-oszlopok írása nem kerül a változásnaplóba'
);

$sor1 = $wpdb->get_row("SELECT kereso, tel_ford FROM {$t} WHERE id = {$id1}");
T::tartalmaz((string) $sor1->kereso, 'arpad qxz58 kovacs', 'a mentett keresőszöveg a névvel kezdődik');
T::egyezik('88775550363', (string) $sor1->tel_ford, 'a fordított telefon mentve');

// Bármelyik kódút azonosító szerinti írása (pl. számla, AI-eszköz) a kérés végén frissít.
$wpdb->update($t, ['nev' => "Árpád {$cimke} Szabó"], ['id' => $id1]);
$U::iras_utan();
T::tartalmaz((string) $wpdb->get_var("SELECT kereso FROM {$t} WHERE id = {$id1}"), 'szabo', 'átírás után a keresőszöveg is új (a kérés végén, magától)');

/* --- 4. Kötegelt feltöltés ----------------------------------------------------------- */

$tomeg = 1250;
$wpdb->query('START TRANSACTION');

for ($i = 1; $i <= $tomeg; $i++) {
    $wpdb->insert($t, [
        'nev' => sprintf('Tömeg %s %04d', $cimke, $i), 'telefon' => '+3620' . (3000000 + $i), 'aktiv' => 1,
        'kulso_azonosito' => 't58', 'letrehozva' => current_time('mysql'),
    ]);
}

$wpdb->query('COMMIT');

delete_option($U::KERESO_ALLAPOT);
delete_transient('sdh_muhely_kereso_zar');

// A feltöltés a tesztsorok előttről indul (egy nagy, mérő-adatos tesztadatbázisban se kelljen végigmennie mindenen).
update_option('sdh_muhely_kereso_utolso', (int) $wpdb->get_var("SELECT MIN(id) FROM {$t} WHERE kulso_azonosito = 't58'") - 1, false);

T::igaz(!$U::kereso_keszen(), 'a feltöltés előtt a gyors út nem él');

$korok = 0;

while (!$U::kereso_keszen() && $korok < 400) {
    $U::kereso_hatter();
    $korok++;
}

T::igaz($U::kereso_keszen(), 'a háttérfeltöltés végigér, és „kesz"-re áll (' . $korok . ' kérés)');
T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE kulso_azonosito = 't58' AND kereso = ''"), 'a tesztsorok mind fel vannak töltve');
T::tartalmaz((string) $wpdb->get_var("SELECT kereso FROM {$t} WHERE nev = 'Tömeg {$cimke} 0777'"), 'tomeg qxz58 0777', 'a feltöltött keresőszöveg ékezet nélküli');
T::igaz(get_option('sdh_muhely_kereso_utolso') === false, 'a kurzor a végén törlődik');

/* --- 5. Gyors út = régi út -------------------------------------------------------------- */

$gyors = new ReflectionMethod($U, 'kereso_gyors');
$regi  = new ReflectionMethod($U, 'kereso_regi');
$gyors->setAccessible(true);
$regi->setAccessible(true);

$idk = static fn (array $sorok): array => array_map(static fn ($s) => (int) $s->id, $sorok);
$rendez = static function (array $x): array {
    sort($x);

    return $x;
};

// Ékezetes keresés csak a gyors úton egyezik az ékezet nélkülivel (a régi út SQLite-on ASCII-érzékeny).
$kerdesek = [
    "arpad {$cimke}" => [$id1],
    "Árpád {$cimke}" => [$id1],
    "{$cimke}mail"   => [$id1],
    'U-9158002'      => [$id2],
    '9158003'        => [$id3],
    '5557788'        => [$id1],
    '30 555 77'      => [$id1],
    '2233'           => [$id2],
    "Balatonf {$cimke}" => [$id2],
];

foreach ($kerdesek as $q => $vart) {
    $kapott = $idk($gyors->invoke(null, (string) $q));
    T::igaz(
        count(array_intersect($vart, $kapott)) === count($vart),
        "gyors keresés „{$q}”: megvan a várt ügyfél (kapott: " . implode(',', $kapott) . ')'
    );
}

// Név belseje és előtagja: a gyors és a régi út azonos halmazt ad (a találat ≤ 10).
foreach (["Béla {$cimke}", "{$cimke} Nagy", 'Zoltán qx'] as $q) {
    $g = $rendez($idk($gyors->invoke(null, $q)));
    $r = $rendez($idk($regi->invoke(null, $q)));
    T::igaz($r !== [] && array_values(array_intersect($g, $r)) === $r, "gyors ⊇ régi: „{$q}”");
}

T::igaz(!in_array($id4, $idk($gyors->invoke(null, "Inaktív {$cimke}")), true), 'az inaktív ügyfél nem jön a keresőben');
T::egyezik(10, count($gyors->invoke(null, "Tömeg {$cimke}")), 'legfeljebb tíz találat');
T::egyezik(1, count($gyors->invoke(null, "Tömeg {$cimke} 0777")), 'pontos név egy találat');

/* --- 5/b. Szó-index ------------------------------------------------------------------ */

$szavak = $U::kereso_szavak([
    'nev' => 'Kovács Árpád', 'szamlazasi_telepules' => 'Balatonfüred', 'szamlazasi_cim' => 'Petőfi Sándor utca 12/B.',
    'email' => 'kovacs.arpad77@gmail.com', 'telefon' => '+36 30 123 4567', 'telefon2' => '06 20 765 4321', 'ugyfel_szam' => 'U-000123',
]);
foreach (['kovacs', 'arpad', 'balatonfured', 'petofi', 'sandor', 'arpad77', '36301234567', '301234567', '06301234567', '06207654321', '207654321', '36207654321', 'u-000123', '123'] as $v) {
    T::igaz(in_array($v, $szavak, true), "keresőszó: {$v}");
}
foreach (['utca', '12', 'gmail', 'com'] as $v) {
    T::igaz(!in_array($v, $szavak, true), "nem keresőszó: {$v}");
}

$szo_t = SDH_Muhely_Schema::tabla('ugyfel_szo');
T::igaz((int) $wpdb->get_var("SELECT COUNT(*) FROM {$szo_t} WHERE ugyfel_id = {$id2}") >= 4, 'a mentett ügyfél szavai a szó-indexben');
T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$szo_t} WHERE ugyfel_id = {$id1} AND szo = 'kovacs'"), 'átíráskor a régi szavak törlődnek');
T::igaz((int) $wpdb->get_var("SELECT COUNT(*) FROM {$szo_t} WHERE ugyfel_id = {$id1} AND szo = 'szabo'") === 1, 'átíráskor az új szavak bekerülnek');

$wpdb->update($t, ['szamlazasi_telepules' => 'Zirc', 'szamlazasi_iranyitoszam' => '8420', 'szamlazasi_cim' => 'Rákóczi tér 3.'], ['id' => $id3]);
$U::kereso_frissit($id3);
T::igaz(in_array($id3, $idk($gyors->invoke(null, "Zoltán {$cimke} zirc")), true), 'több szó: név + település');
T::igaz(in_array($id3, $idk($gyors->invoke(null, "rakoczi zolt {$cimke}")), true), 'több szó: utca + keresztnév eleje (ékezet nélkül)');
T::igaz(!in_array($id2, $idk($gyors->invoke(null, "Béla {$cimke} zirc")), true), 'több szó: mindegyikre kell egyező szó');
T::igaz(in_array($id1, $idk($gyors->invoke(null, '06305557788')), true), 'telefon 06-tal');
T::igaz(in_array($id1, $idk($gyors->invoke(null, '30 555 7788')), true), 'telefon előhívó nélkül, szóközzel');
T::egyezik('8420 Zirc Rákóczi tér 3.', (string) $wpdb->get_var("SELECT cim_rendez FROM {$t} WHERE id = {$id3}"), 'cim_rendez = irányítószám település cím');
T::egyezik('', $U::cim_rendez_ertek([]), 'üres cím: üres cim_rendez');

// A „tartalmazza" csak találat nélkül fut: szó közepére is talál, ha más nincs.
T::igaz(in_array($id2, $idk($gyors->invoke(null, 'xz58 nag')), true) || in_array($id2, $idk($gyors->invoke(null, 'la qxz58')), true), 'szó közepe: a tartalmazza-lépcső talál');

$_GET['q']       = "Béla {$cimke}";
$_REQUEST['_wpnonce'] = wp_create_nonce('sdh_muhely_modal');
$_GET['_wpnonce'] = $_REQUEST['_wpnonce'];
add_filter('wp_die_ajax_handler', static fn () => static function (): void { throw new RuntimeException('vege'); });
add_filter('wp_doing_ajax', '__return_true');
ob_start();

try {
    $U::ajax_kereso();
} catch (RuntimeException $e) {
    // a wp_send_json a végén kilép
}

$json = json_decode((string) ob_get_clean(), true);
T::igaz(is_array($json) && !empty($json['success']) && isset($json['data'][0]['reszlet']), 'az AJAX-válasz a megszokott alakú');
T::egyezik($id2, (int) ($json['data'][0]['id'] ?? 0), 'az AJAX-válasz a várt ügyfelet adja');

/* --- 6. Az ügyféllista keresése ------------------------------------------------------------ */

$_GET['k'] = "{$cimke}mail";
unset($_GET['oldalszam'], $_GET['inaktiv']);
$lista = new ReflectionMethod($U, 'lista_oldal');
$lista->setAccessible(true);
ob_start();
$lista->invoke(null);
$html = (string) ob_get_clean();
T::tartalmaz($html, "{$cimke} Szabó", 'a lista a gyors úton megtalálja az e-mail alapján');
T::nem_tartalmaz($html, "Béla {$cimke}", 'a lista nem hoz fölösleget');

$_GET['k'] = '30 555 77';
ob_start();
$lista->invoke(null);
T::tartalmaz((string) ob_get_clean(), "{$cimke} Szabó", 'a lista telefonrészletre is keres');

/* --- 7. A böngészőoldal ---------------------------------------------------------------------- */

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/app.js');
T::tartalmaz($js, 'VALASZTO_KESLELTETES = 100', 'a gépelés-késleltetés 100 ms');
T::tartalmaz($js, 'new AbortController()', 'a régi kérés megszakad');
T::tartalmaz($js, "VALASZTO_MIN = { ugyfelek: 3 }", 'az ügyfélkeresés 3 betűtől indul');
T::tartalmaz($js, 'function valasztoTarolt', 'ugyanarra a szövegre a megkapott lista jön vissza');
T::tartalmaz($js, 'var VALASZTO_SZUNET = 150;', 'lassú gépelésnél (szünet után) a kérés azonnal megy');
T::tartalmaz($js, "if (volt !== '0') {\n            munkalapEszkozFrissit(doboz, '0');", 'az összefoglaló nem töltődik újra minden leütésre');

$takarit();
update_option($U::KERESO_ALLAPOT, $U::KERESO_KESZ);

T::vege('t58 gyors ügyfélkereső');
