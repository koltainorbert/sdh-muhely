<?php
/**
 * 0.49: eszköz szerkesztése a munkalapról, az SDH számla gomb megszűnése,
 * a csempék számai entitás nélkül, betűméret és betűtípus, megjelenés és üvegszín.
 */

require __DIR__ . '/_keret.php';

/* ---- 3. A csempe-számok válaszában nincs HTML-entitás ---------------- */

global $wp_locale;

// Magyar nyelvi csomaggal a WordPress ezres elválasztója az „&nbsp;" entitás.
$regi_elv = $wp_locale->number_format['thousands_sep'];
$wp_locale->number_format['thousands_sep'] = '&nbsp;';

$valasz = t_ajax('sdh_muhely_csempe_szamok');
$szamok = (array) ($valasz['data'] ?? []);

T::igaz(!empty($valasz['success']), 'csempe-számok: sikeres válasz');
T::igaz(isset($szamok['munkalapok']), 'csempe-számok: van munkalap-szám');

foreach ($szamok as $kulcs => $szoveg) {
    T::nem_tartalmaz((string) $szoveg, '&', 'csempe-szám (' . $kulcs . ') entitás nélkül');
}

T::egyezik("157\u{00A0}700", SDH_Muhely_Admin_UI::szam_szoveg(157700), 'szam_szoveg: valódi nem törő szóköz');
T::egyezik('5', SDH_Muhely_Admin_UI::szam_szoveg(5), 'szam_szoveg: kis szám változatlan');

$wp_locale->number_format['thousands_sep'] = $regi_elv;

/* ---- 1. Eszköz szerkesztése a munkalapról ----------------------------- */

SDH_Muhely_Modulok::kontextus_beallit('frontend');

$u   = t_ugyfel('Szerkesztő Szilárd');
$e1  = t_eszkoz($u, 'Lenovo', 'L14');
$e2  = t_eszkoz($u, 'Apple', 'iPhone 17');
$ket = t_munkalap($u, [$e1, $e2]);
$egy = t_munkalap($u, [$e1]);

$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ket])['nyers'];

T::igaz(preg_match('/data-sdh-eszkoz-szerk\s+title="A kiválasztott eszköz/', $urlap) === 1, 'ceruza a fő eszköz mezője mellett, kattintható');
T::tartalmaz($urlap, 'class="sdh-ml-kartya__szerk" data-sdh-eszkoz-szerk="' . $e1 . '"', 'Szerkesztés gomb a fő eszköz kártyáján');
T::tartalmaz($urlap, 'class="sdh-ml-kartya__szerk" data-sdh-eszkoz-szerk="' . $e2 . '"', 'Szerkesztés gomb a csatolt eszköz adatlapján');
T::tartalmaz($urlap, 'sdh-ml-eszkoz__szerk" data-sdh-eszkoz-szerk="' . $e2 . '"', 'ceruza a csatolt eszköz címkéjén');
T::tartalmaz($urlap, 'data-sdh-eszkoz-szerk="__ID__"', 'a csatolás sablonjában is ott a ceruza');
T::egyezik(2, substr_count($urlap, 'data-sdh-ugyfel-szerk'), 'az ügyfél szerkesztőgombjai megmaradtak');

$uj_lap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', [])['nyers'];
T::igaz(preg_match('/data-sdh-eszkoz-szerk\s+disabled/', $uj_lap) === 1, 'új lapon (eszköz nélkül) a ceruza nem kattintható');

$aru = t_ajax('sdh_muhely_arukiadas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0])['nyers'];
T::nem_tartalmaz($aru, 'sdh-ml-kartya__szerk" data-sdh-eszkoz-szerk', 'árukiadáson nincs eszközkártya, így szerkesztőgombja sincs');

$app_js = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.js');
T::tartalmaz($app_js, 'function munkalapEszkozSzerk', 'app.js: megvan az eszközszerkesztő');
T::tartalmaz($app_js, "nyit('eszkozok', id", 'app.js: az eszköz űrlapja a lap fölött nyílik');

/* ---- 4. Több eszköz: jelölő osztály; az oldalsáv (0.59.1 óta) egy hasábos, kártyás ---- */

$urlap1 = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $egy])['nyers'];
T::igaz(preg_match('/class="sdh-ugyfelurlap sdh-munkalapurlap is-tobb-eszkoz"/', $urlap) === 1, 'több eszköznél a lap már a szerverről megkapja a jelölő osztályt');
T::igaz(preg_match('/class="sdh-ugyfelurlap sdh-munkalapurlap"\s+data-sdh-munkalap/', $urlap1) === 1, 'egy eszköznél nincs jelölő osztály');

$admin_css = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/admin.css');
T::igaz(preg_match('/\.sdh-munkalapurlap\.is-tobb-eszkoz > \.sdh-ml > \.sdh-ml__oldal \{[^}]*columns: 2/', $admin_css) === 0, 'admin.css: az oldalsáv több eszköznél sem törik két hasábra');
T::tartalmaz($admin_css, '.sdh-munkalapurlap .sdh-ml__csoport--racs {', 'admin.css: a rokon mezők egy sorban (kártyás oldalsáv)');
T::tartalmaz($urlap, 'sdh-ml__csoport sdh-ml__csoport--fizetes', 'a Fizetés csoport jelölve (díjkártya)');

/* ---- 2. Csak a Számlázz.hu számla és a számla-előkészítő --------------- */

$regi_szamla = get_option('sdh_muhely_szamla');
update_option('sdh_muhely_szamla', ['elotag_sdh' => 'SD', 'gomb_sdh' => 'SDH számla'] + (is_array($regi_szamla) ? $regi_szamla : []));

$m = t_lap($ket);
if ((int) $m->munkalap_szam <= 0) {
    global $wpdb;
    $wpdb->update(SDH_Muhely_Schema::tabla('munkalap'), ['munkalap_szam' => 990000 + $ket], ['id' => $ket]);
    $m = t_lap($ket);
}

ob_start();
SDH_Muhely_Szamla::munkalap_gombok($m);
$gombok = (string) ob_get_clean();

T::tartalmaz($gombok, 'data-sdh-szamla="fo"', 'megvan a Számlázz.hu számla gomb');
T::tartalmaz($gombok, 'data-sdh-szamla="helyi"', 'megvan a számla-előkészítő (PDF) gomb');
T::nem_tartalmaz($gombok, 'data-sdh-szamla="sdh"', 'nincs SDH számla gomb – akkor sem, ha a régi beállításban előtag áll');
T::nem_tartalmaz($gombok, 'SDH számla', 'a felirat sem jelenik meg');
T::egyezik(2, substr_count($gombok, 'data-sdh-szamla="'), 'pontosan két gomb');

$ablak = t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ket, 'sorozat' => 'sdh'])['nyers'];
T::tartalmaz($ablak, 'Számlázz.hu számla', 'a régi „sdh" sorozatra a rendes Számlázz.hu ablak nyílik');
T::nem_tartalmaz($ablak, 'SDH számla', 'a számla ablakában sincs SDH számla');

ob_start();
SDH_Muhely_Szamla::beallitas_doboz();
$szamla_beall = (string) ob_get_clean();
T::nem_tartalmaz($szamla_beall, 'szamla_elotag_sdh', 'Beállítások: nincs „második gomb" előtag');
T::nem_tartalmaz($szamla_beall, 'szamla_gomb_sdh', 'Beállítások: nincs „második gomb" felirat');

if ($regi_szamla === false) {
    delete_option('sdh_muhely_szamla');
} else {
    update_option('sdh_muhely_szamla', $regi_szamla);
}

/* ---- 5. Betűnagyítás és betűtípus -------------------------------------- */

$A         = 'SDH_Muhely_Arculat';
$regi_arc  = get_option('sdh_muhely_arculat');
$ment      = static fn (array $mezok): array => t_ajax('sdh_muhely_beallitasok_ment', $mezok, 'sdh_nonce', 'sdh_muhely_beallitasok');
$alap_mezo = ['ful' => 'arculat', 'tema' => 'rendszer', 'szin' => '', 'arculat_jel' => 'T', 'arculat_nev' => 'Teszt'];

delete_option('sdh_muhely_arculat');
$b = $A::beallitas();
T::egyezik(100, $b['nagyitas'], 'alap: 100%-os betűméret');
T::egyezik('', $b['betutipus'], 'alap: a gép betűtípusa');
T::egyezik('mono', $b['uveg'], 'alap: egyszínű üvegszín');
T::nem_tartalmaz($A::stilus_css(), '--sdh-betutipus', 'alapértéknél nincs betűtípus a stílusban');

$r = $ment($alap_mezo + ['arculat_nagyitas' => '127', 'arculat_betutipus' => 'verdana', 'arculat_uveg' => 'duo']);
T::igaz(!empty($r['success']), 'megjelenés mentése sikeres');
$b = $A::beallitas();
T::egyezik(125, $b['nagyitas'], 'a nagyítás 5%-os lépésre kerekít (127 → 125)');
T::egyezik('verdana', $b['betutipus'], 'a betűtípus elmentve');
T::egyezik('duo', $b['uveg'], 'az üvegszín módja elmentve');
T::tartalmaz($A::stilus_css(), '--sdh-betutipus:Verdana', 'a választott betűtípus a stílusba kerül');

$ment($alap_mezo + ['arculat_nagyitas' => '500', 'arculat_betutipus' => 'nincs-ilyen', 'arculat_uveg' => 'harom']);
$b = $A::beallitas();
T::egyezik($A::NAGYITAS_MAX, $b['nagyitas'], 'a nagyítás felső határa él');
T::egyezik('', $b['betutipus'], 'ismeretlen betűtípus → alap');
T::egyezik('mono', $b['uveg'], 'ismeretlen üvegszín-mód → egyszínű');

$ment($alap_mezo + ['arculat_nagyitas' => '10', 'arculat_betutipus' => 'egyeni', 'arculat_betutipus_egyeni' => 'Mont"serrat; } body{x:y']);
$b = $A::beallitas();
T::egyezik($A::NAGYITAS_MIN, $b['nagyitas'], 'a nagyítás alsó határa él');
T::egyezik('egyeni', $b['betutipus'], 'egyéni betűtípus névvel választható');
T::nem_tartalmaz($b['betutipus_egyeni'], '"', 'az egyéni betűnévből kikerül az idézőjel');
T::nem_tartalmaz($b['betutipus_egyeni'], ';', 'az egyéni betűnévből kikerül a pontosvessző');
T::nem_tartalmaz($b['betutipus_egyeni'], '{', 'az egyéni betűnévből kikerül a kapcsos zárójel');
T::tartalmaz($A::stilus_css(), '--sdh-betutipus:"Montserrat', 'az egyéni betű idézőjelben, tisztítva kerül a stílusba');

$ment($alap_mezo + ['arculat_betutipus' => 'egyeni', 'arculat_betutipus_egyeni' => '']);
T::egyezik('', $A::beallitas()['betutipus'], 'egyéni betűtípus név nélkül nem választható');

// Másik lapfül mentése (vagy régi űrlap) nem nullázza a betűt és a nagyítást.
$ment($alap_mezo + ['arculat_nagyitas' => '115', 'arculat_betutipus' => 'georgia', 'arculat_uveg' => 'duo']);
$ment($alap_mezo);
$b = $A::beallitas();
T::egyezik(115, $b['nagyitas'], 'hiányzó mezőnél a nagyítás megmarad');
T::egyezik('georgia', $b['betutipus'], 'hiányzó mezőnél a betűtípus megmarad');
T::egyezik('duo', $b['uveg'], 'hiányzó mezőnél az üvegszín módja megmarad');

$csuszka = $A::nagyito('sajat');
T::tartalmaz($csuszka, 'type="range"', 'a nagyító csúszka (range)');
T::tartalmaz($csuszka, 'min="' . $A::NAGYITAS_MIN . '" max="' . $A::NAGYITAS_MAX . '"', 'a csúszka határai a beállításból jönnek');
T::tartalmaz($csuszka, 'value="115" data-alap="115"', 'a saját csúszka a cég alapértelmezéséről indul, oda áll vissza');
T::nem_tartalmaz($csuszka, 'name=', 'a saját csúszka nem űrlapmező (a böngésző jegyzi meg)');
T::tartalmaz($A::nagyito('urlap'), 'name="arculat_nagyitas"', 'a Beállítások csúszkája űrlapmező');

ob_start();
SDH_Muhely_Admin_UI::attekintes_tartalom(false);
$attekintes = (string) ob_get_clean();
// 0.51: a betűméret a profilmenüben állítható, az Áttekintés állapotsorában nincs csúszka.
T::nem_tartalmaz($attekintes, 'data-sdh-nagyito', 'az Áttekintésen nincs betűnagyító csúszka (a profilmenüben van)');
T::tartalmaz($csuszka, 'data-sdh-nagyito-minta', 'a csúszkán ott a minta, amely húzás közben mutatja a méretet');

ob_start();
SDH_Muhely_Admin_UI::attekintes_tartalom(true);
T::nem_tartalmaz((string) ob_get_clean(), 'data-sdh-nagyito', 'a wp-admin Áttekintésén nincs csúszka (ott a WordPress mérete él)');

$js = SDH_Muhely_Admin_UI::js_beallitas('frontend');
T::egyezik(115, $js['alapNagyitas'], 'a JS megkapja az alap nagyítást');
T::egyezik('duo', $js['alapUveg'], 'a JS megkapja az üvegszín módját');

// A stíluslapok képpontméretei a nagyítás változójával szorzódnak (tools/css_nagyitas.py).
$kimaradt = [];

foreach (glob(SDH_MUHELY_DIR . 'assets/*.css') ?: [] as $css_fajl) {
    if (basename($css_fajl) === 'rma-ugyfel.css') {
        continue;
    }

    $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($css_fajl));
    // A nyomtatási blokkok mérete szándékosan nem követi a képernyő nagyítását.
    $css = (string) preg_replace('~@media print\s*\{(?:[^{}]|\{[^{}]*\})*\}~s', '', $css);

    if (preg_match_all('~font-size:\s*[\d.]+px\s*[;}]~', $css, $t)) {
        $kimaradt[] = basename($css_fajl) . ' (' . count($t[0]) . ')';
    }
}

T::egyezik([], $kimaradt, 'minden stíluslap betűmérete a nagyítással szorzódik – futtasd: python3 tools/css_nagyitas.py');
T::tartalmaz($admin_css, '--sdh-n: 1;', 'admin.css: a nagyítás alapértéke 1');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.css'), 'font-size: calc(100% * var(--sdh-n))', 'app.css: a gyökér betűmérete követi a nagyítást');
T::igaz(preg_match('~@media print\s*\{(?:[^{}]|\{[^{}]*\})*var\(--sdh-n\)~s', $admin_css) !== 1, 'a nyomtatási stílus mérete nem függ a nagyítástól');
T::nem_tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'assets/rma-ugyfel.css'), '--sdh-n', 'az ügyféloldal stíluslapja érintetlen');

/* ---- 6. Megjelenés és üvegszín a minta szerint ------------------------- */

$ment(['tema' => 'sotet', 'szin' => 'turkiz', 'arculat_uveg' => 'duo', 'arculat_szin_egyeni' => '#112233'] + $alap_mezo);
$b = $A::beallitas();
T::egyezik('sotet', $b['tema'], 'a téma elmentve');
T::egyezik('turkiz', $b['szin'], 'az új szín (Türkiz) elmenthető');

foreach (['rozsa', 'turkiz', 'rubin'] as $uj_szin) {
    T::igaz(isset($A::szinek()[$uj_szin]), 'új kiemelő szín: ' . $uj_szin);
    T::tartalmaz($admin_css, ':root[data-accent="' . $uj_szin . '"]', 'admin.css: ' . $uj_szin . ' világos módban');
    T::tartalmaz($admin_css, ':root[data-theme="dark"][data-accent="' . $uj_szin . '"]', 'admin.css: ' . $uj_szin . ' sötét módban');
    T::tartalmaz($admin_css, ':root[data-uveg="duo"][data-accent="' . $uj_szin . '"]', 'admin.css: ' . $uj_szin . ' Duó párja');
}

T::egyezik(8, count($A::valaszto_szinek()), 'a választóban nyolc szín áll, ahogy a mintán');

$v = $A::megjelenes_valaszto('urlap');
T::tartalmaz($v, 'data-sdh-megj="urlap"', 'választó a Beállítások űrlapján');
T::egyezik(4, substr_count($v, '<button type="button" data-ertek='), 'Rendszer / Világos / Sötét / Papír');
T::tartalmaz($v, '>Papír<', 'Papír gomb (0.51)');
T::tartalmaz($v, 'name="arculat_kekfeny"', 'a Beállítások kékfény-csúszkája űrlapmező (0.51)');
T::tartalmaz($v, '>Rendszer<', 'Rendszer gomb');
T::tartalmaz($v, 'data-sdh-uvegszin="duo"', 'Duó sor');
T::tartalmaz($v, 'data-sdh-uvegszin="mono"', 'Egyszínű sor');
T::tartalmaz($v, 'Kontrasztos színek', 'Duó alcíme');
T::tartalmaz($v, 'Egy színcsalád', 'Egyszínű alcíme');
T::egyezik(18, substr_count($v, 'class="sdh-megj__szin"'), 'soronként 8 szín + a cég egyéni színe');
T::tartalmaz($v, 'A cég színe', 'megadott egyéni színnél az is választható');
T::tartalmaz($v, 'name="tema" value="sotet"', 'rejtett mező: téma');
T::tartalmaz($v, 'name="szin" value="turkiz"', 'rejtett mező: szín');
T::tartalmaz($v, 'name="arculat_uveg" value="duo"', 'rejtett mező: üvegszín módja');
T::egyezik(2, substr_count($v, 'aria-pressed="true"'), 'a mentett téma és a mentett szín van kijelölve');
T::igaz(preg_match('/data-sdh-uvegszin="duo".*?data-ertek="turkiz" aria-pressed="true"/s', $v) === 1, 'a kijelölt szín a Duó sorban áll');

$vp = $A::megjelenes_valaszto('profil');
T::tartalmaz($vp, 'data-sdh-megj="profil"', 'választó a profilmenüben');
T::nem_tartalmaz($vp, 'name=', 'a profilmenü választója nem űrlap (a böngésző jegyzi meg)');
T::tartalmaz($vp, 'data-sdh-kekfeny="sajat"', 'a profilmenüben ott a kékfény-szűrő csúszkája (0.51)');
T::nem_tartalmaz($vp, 'aria-pressed="true"', 'a profilmenüben a kijelölést a böngésző állítja be');

$ment(['szin' => 'egyeni', 'arculat_szin_egyeni' => ''] + $alap_mezo);
T::egyezik('', $A::beallitas()['szin'], 'egyéni szín színkód nélkül nem választható');
T::egyezik(16, substr_count($A::megjelenes_valaszto('urlap'), 'class="sdh-megj__szin"'), 'egyéni szín nélkül soronként 8 korong');

ob_start();
$A::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::tartalmaz($doboz, 'data-sdh-megj="urlap"', 'Beállítások › Logó és megjelenés: ott a választó');
T::tartalmaz($doboz, 'name="arculat_betutipus"', 'Beállítások: betűtípus választható');
T::tartalmaz($doboz, 'name="arculat_nagyitas"', 'Beállítások: alap betűméret csúszkával');
T::nem_tartalmaz($doboz, '<select name="tema"', 'a régi téma-legördülő megszűnt');
T::nem_tartalmaz($doboz, '<select name="szin"', 'a régi szín-legördülő megszűnt');

if ($regi_arc === false) {
    delete_option('sdh_muhely_arculat');
} else {
    update_option('sdh_muhely_arculat', $regi_arc);
}

T::vege('t34 eszközszerkesztés, számla-gombok, csempe-szám, betű, szín');
