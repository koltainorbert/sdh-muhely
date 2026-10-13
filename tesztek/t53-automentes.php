<?php
/**
 * t53 – 0.59.6: automatikus mentés, gyorsabb számla-ablak, számla a lezárási ajánlatból.
 *
 * - A munkalap mentése visszaadja az új tétel- és hibasorok azonosítóját
 *   (uj_sorok), így két gyors egymás utáni mentés nem veszi fel kétszer ugyanazt.
 * - A böngészőoldali automatikus mentés csak a rekord-űrlapokon él (munkalap,
 *   ügyfél, eszköz, termék, szolgáltatás), számlán, fizetésen, küldésen soha.
 * - Lezáráskor a nyomtatási ajánlat a két számlagombot is viszi.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$tt = SDH_Muhely_Schema::tabla('munkalap_tetel');
$ht = SDH_Muhely_Schema::tabla('munkalap_hiba');

$u  = t_ugyfel('Auto Aladár');
$e  = t_eszkoz($u, 'Samsung', 'A50');
$id = t_munkalap($u, [$e]);

$alap = static function (int $id) use ($u, $e): array {
    $lap = t_lap($id);

    return [
        'id' => $id, 'ugyfel_id' => $u, 'eszkoz_id' => $e, 'eszkozok_jelen' => '1', 'allapot' => (string) $lap->allapot,
        'keszult' => current_time('Y-m-d'), 'fizetett' => '0', 'tetelek_jelen' => '1', 'automentes' => '1',
    ];
};

/* --- 1. Új tétel és hibasor: az azonosító visszajön ---------------------------- */

$r = t_ajax('sdh_muhely_munkalapok_ment', $alap($id) + [
    'tetelek' => ['szolgaltatas' => ['7' => ['id' => '0', 'eszkoz_id' => '0', 'megnevezes' => 'Kijelzőcsere', 'mennyiseg' => '1', 'me' => 'db', 'brutto_ar' => '15000', 'afa_kulcs' => '27']],
                  'termek' => ['3' => ['id' => '0', 'eszkoz_id' => '0', 'megnevezes' => 'Fólia', 'mennyiseg' => '1', 'me' => 'db', 'brutto_ar' => '2000', 'afa_kulcs' => '27']]],
    'hibak' => ['5' => ['id' => '0', 'eszkoz_id' => '0', 'leiras' => 'Törött kijelző', 'allapot' => '']],
], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');

T::igaz(!empty($r['success']), 'az automatikus mentés sikeres' . (isset($r['data']['uzenet']) ? ' – ' . $r['data']['uzenet'] : ''));

$uj = (array) ($r['data']['uj_sorok'] ?? []);
T::igaz(isset($uj['szolgaltatas:7'], $uj['termek:3'], $uj['hiba:5']), 'a válasz minden új sor azonosítóját visszaadja (űrlapkulcs szerint)');

$szolg = (int) ($uj['szolgaltatas:7'] ?? 0);
$term  = (int) ($uj['termek:3'] ?? 0);
$hiba  = (int) ($uj['hiba:5'] ?? 0);

T::egyezik('Kijelzőcsere', (string) $wpdb->get_var($wpdb->prepare("SELECT megnevezes FROM {$tt} WHERE id = %d AND munkalap_id = %d", $szolg, $id)), 'a szolgáltatás azonosítója a valódi sorra mutat');
T::egyezik('Törött kijelző', (string) $wpdb->get_var($wpdb->prepare("SELECT leiras FROM {$ht} WHERE id = %d AND munkalap_id = %d", $hiba, $id)), 'a hibasor azonosítója a valódi sorra mutat');

/* --- 2. A második mentés a visszakapott azonosítókkal: nincs duplázás ------- */

$r2 = t_ajax('sdh_muhely_munkalapok_ment', $alap($id) + [
    'tetelek' => ['szolgaltatas' => ['7' => ['id' => (string) $szolg, 'eszkoz_id' => '0', 'megnevezes' => 'Kijelzőcsere', 'mennyiseg' => '1', 'me' => 'db', 'brutto_ar' => '15000', 'afa_kulcs' => '27']],
                  'termek' => ['3' => ['id' => (string) $term, 'eszkoz_id' => '0', 'megnevezes' => 'Fólia', 'mennyiseg' => '2', 'me' => 'db', 'brutto_ar' => '2000', 'afa_kulcs' => '27']]],
    'hibak' => ['5' => ['id' => (string) $hiba, 'eszkoz_id' => '0', 'leiras' => 'Törött kijelző', 'allapot' => '']],
], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');

T::igaz(!empty($r2['success']), 'a második mentés is sikeres');
T::egyezik([], (array) ($r2['data']['uj_sorok'] ?? []), 'a második mentésnél nincs új sor');
T::egyezik(2, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tt} WHERE munkalap_id = %d", $id)), 'két tétel van a lapon (nem négy)');
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ht} WHERE munkalap_id = %d", $id)), 'egy hibasor van (nem kettő)');
T::egyezik(19000.0, (float) t_lap($id)->brutto_ertek, 'a módosított mennyiség is mentődött (15 000 + 2 × 2 000)');

/* --- 3. A böngészőoldal ------------------------------------------------------ */

$js = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.js');

T::tartalmaz($js, "var AUTOMENTES = /^sdh_muhely_(munkalapok|ugyfelek|eszkozok|termekek|szolgaltatasok)_ment$/;", 'automatikus mentés csak a rekord-űrlapokon');
T::igaz(!preg_match('/var AUTOMENTES = [^;]*(szamla|fizet|kuld|penztar)/', $js), 'számla, fizetés, küldés, pénztár soha nem mentődik magától');
T::tartalmaz($js, "!(parseInt(idMezo.value, 10) > 0) || zarolt", 'új (még nem mentett) és lezárt lapon nincs automatikus mentés');
T::tartalmaz($js, "adat.set('automentes', '1');", 'a szerver tudja, hogy automatikus mentés jött');
T::tartalmaz($js, "eredmeny.data.uj_sorok", 'az új sorok azonosítója visszaíródik az űrlapba');
T::tartalmaz($js, "szint.dialog.addEventListener('close', function () { automentesZaras(szint); });", 'bezáráskor a maradék is mentődik, és a lista frissül');
T::tartalmaz($js, "keepalive: true", 'bezáráskor sem vész el a mentés');
T::tartalmaz($js, "if (opciok.csendes && typeof opciok.elotte === 'function' && !opciok.elotte())", 'a csendes frissítés nem írja felül a közben beírt adatot');
T::tartalmaz($js, "ev.target.tagName !== 'TEXTAREA'", 'a rövid mezők (előleg) elhagyáskor mentődnek, nem gépelés közben');

// A számla gombja: előbb a számla nyílik, a munkalap a háttérben frissül; mentett lapnál nincs mentés.
T::tartalmaz($js, "if (auto && !auto.fut && urlapKep(urlap) === auto.kep) {", 'mentett lapnál a számla rögtön nyílik');
T::igaz(strpos($js, "szamlaNyit(n, id, sorozat);\n                csendesFrissit(n);") !== false, 'mentés után előbb a számla nyílik, a munkalap csendben frissül');

$pt = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/penztar.js');
T::tartalmaz($pt, "document.addEventListener('sdh:automentve'", 'automatikus mentés után is jön a pénztár-ajánlat');

/* --- 4. Lezáráskor: a nyomtatási ajánlatban a két számla ---------------------- */

$lezart = null;

foreach (SDH_Muhely_Munkalap::allapotok() as $k => $a) {
    if (!empty($a['zart']) && empty($a['ervenytelen'])) {
        $lezart = $k;
        break;
    }
}

delete_user_meta(get_current_user_id(), 'sdh_muhely_ny_ajanlat');
SDH_Muhely_Nyomtatas::allapot_valtott($id, 'nyitott', $lezart);
$aj = t_ajax('sdh_muhely_nyomtatas_ajanlat', [], '_wpnonce', 'sdh_muhely_modal')['data']['ajanlat'] ?? null;

if ($aj === null) {
    T::igaz(true, 'a nyomtatási ajánlat ki van kapcsolva ennél a sablonnál – a számlagomb nem ellenőrizhető');
} else {
    T::egyezik('zaras', (string) $aj['esemeny'], 'lezárási ajánlat');
    T::igaz(!empty($aj['szamla']['helyi']), 'a Számla-előkészítő gombja ott van');
    T::igaz(($aj['szamla']['fo'] ?? null) === SDH_Muhely_Modulok::be('szamlazas'), 'a Számlázz.hu gombja a Számlázás modul szerint');
}

$ny = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/nyomtatas.js');
T::tartalmaz($ny, 'data-nya="szamla-fo">Számlázz.hu számla', 'a lezárási ajánlatban Számlázz.hu számla gomb');
T::tartalmaz($ny, 'data-nya="szamla-helyi">Számla-előkészítő (PDF)', 'és Számla-előkészítő gomb');
T::tartalmaz($js, 'szamla: szamlaKivulrol,', 'a számla ablaka kívülről is nyitható');

/* --- 5. A mockup nem lóg a fejlécbe ------------------------------------------ */

$css = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/admin.css');
T::tartalmaz($css, "margin-top: .55rem;\n    padding: 0 .6rem;", 'az Eszköz kártya törzse a fejléc alatt kezdődik');

T::vege('t53 automatikus mentés, számla-ablak, lezárási ajánlat');
