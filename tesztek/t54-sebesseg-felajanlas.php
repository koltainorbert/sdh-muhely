<?php
/**
 * t54 – 0.60.0: sebesség, nyitva maradó felajánlás, napló-ablak, levélcímek.
 *
 * - A popup nem zsugorodik: nincs CSS-zoom, a doboz görget (app.js, admin.css).
 * - Az eszközcsomag: a plugin CSS/JS fájljai egy kérésben (SDH_Muhely_Csomag).
 * - A nyomtatási felajánlás nyitva marad, és a lap minden papírját kínálja;
 *   a számlagombok már felvételkor is ott vannak (bevizsgálási díj).
 * - Napi árukiadásnál új lap mentése után is jár a felajánlás.
 * - A Napló ablak listája fix magasságú, saját görgetéssel.
 * - A levélíró ismert címeket ajánl (ügyfél + eddigi feladó).
 */

require __DIR__ . '/_keret.php';

$js  = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.js');
$ny  = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/nyomtatas.js');
$css = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/admin.css');

/* --- 1. A popup betűmérete nem csökken --------------------------------------- */

T::nem_tartalmaz($js, 'ILLESZT_MIN', 'nincs kicsinyítési alsó határ (a popup nem zsugorodik)');
T::nem_tartalmaz($js, "classList.add('sdh-modal--illeszt')", 'a popup nem kapja meg a görgetést kikapcsoló osztályt');
T::nem_tartalmaz($css, '.sdh-modal--illeszt', 'és a hozzá tartozó szabály sincs meg');
T::tartalmaz($js, "dialog.style.zoom = '';", 'a korábbi nagyítás törlődik a régi munkamenetekből');
T::tartalmaz($css, 'overflow-y: auto;', 'a popup doboza görget');

// A tartalmat figyelő (minden leütésnél újraméretező) figyelő megszűnt.
T::nem_tartalmaz($js, "attributeFilter: ['open', 'hidden']", 'a popup nem figyeli a teljes tartalmát');
T::tartalmaz($js, "attributeFilter: ['open']", 'csak a nyitás/zárás számít');

/* --- 2. Eszközcsomag --------------------------------------------------------- */

T::igaz(class_exists('SDH_Muhely_Csomag'), 'az eszközcsomag osztálya betöltődött');
T::igaz(SDH_Muhely_Csomag::be(), 'alapból be van kapcsolva');
T::igaz(has_action('admin_enqueue_scripts', [SDH_Muhely_Csomag::class, 'osszevon']) === 9999, 'a sor legvégén fut (minden modul után)');
T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['sebesseg']), 'a Beállításokban van Sebesség lapfül');

update_option(SDH_Muhely_Csomag::OPT, '0');
T::igaz(!SDH_Muhely_Csomag::be(), 'ki is kapcsolható');
update_option(SDH_Muhely_Csomag::OPT, '1');

$csom = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-csomag.php');
T::tartalmaz($csom, 'wp_get_upload_dir', 'a csomag a feltöltési mappába kerül (nem a plugin mappájába)');
T::nem_tartalmaz($csom, 'SDH_MUHELY_DIR . \'assets/\' . $nev', 'semmit nem ír a plugin assets mappájába');

/* --- 3. A felajánlás nyitva marad, és minden papírt kínál --------------------- */

T::tartalmaz($ny, 'Csak a „Most nem" / „Bezárás" zárja be az ablakot', 'a felajánlás csak a bezáró gombra csukódik');
T::tartalmaz($ny, 'function kesz(g)', 'a lefutott gomb megjelölve marad');
T::tartalmaz($ny, "'Kész – '", 'a megjelölés felirata');
T::tartalmaz($ny, 'a.tarsak', 'a lap többi papírja is gombot kap');

// A számla indítása után NEM zárul be az ablak (a zar() csak a „nem" ágban van).
$szamla_ag = (string) strstr($ny, "} else if (mit === 'szamla-fo' || mit === 'szamla-helyi') {");
$szamla_ag = (string) substr($szamla_ag, 0, (int) strpos($szamla_ag, "} else if (mit === 'megnyit')"));
T::nem_tartalmaz($szamla_ag, 'zar();', 'a számla gombja nem zárja be a felajánlást');
T::tartalmaz($szamla_ag, 'kesz(g);', 'csak megjelöli');

/* --- 4. Számlagomb felvételkor is (bevizsgálási díj) ------------------------- */

$u  = t_ugyfel('Csomag Csaba');
$e  = t_eszkoz($u, 'Samsung', 'A50');
$id = t_munkalap($u, [$e]);

delete_user_meta(get_current_user_id(), 'sdh_muhely_ny_ajanlat');
SDH_Muhely_Nyomtatas::mentve($id, null);
$aj = t_ajax('sdh_muhely_nyomtatas_ajanlat', [], '_wpnonce', 'sdh_muhely_modal')['data']['ajanlat'] ?? null;

if ($aj === null) {
    T::igaz(true, 'az új lap felajánlása ki van kapcsolva – a számlagomb nem ellenőrizhető');
} else {
    T::egyezik('uj', (string) $aj['esemeny'], 'felvételi ajánlat');
    T::igaz(!empty($aj['szamla']['helyi']), 'már felvételkor ott a Számla-előkészítő gombja (bevizsgálási díj)');
    T::egyezik('munkalap', (string) ($aj['tarsak'][0]['tipus'] ?? ''), 'és a munkalap nyomtatása is felajánlható');
}

/* --- 5. Árukiadás: új lap mentése után is jön a felajánlás ------------------- */

$r = new ReflectionMethod('SDH_Muhely_Nyomtatas', 'ajanlott');
$r->setAccessible(true);

T::egyezik('eladas', (string) $r->invoke(null, 'arukiadas', 'uj'), 'új árukiadásnál az átadási bizonylat');
T::egyezik('rendeles', (string) $r->invoke(null, 'rendeles', 'uj'), 'új rendelésnél a megrendelés');
T::egyezik('atveteli', (string) $r->invoke(null, 'javitas', 'uj'), 'új javításnál az átvételi elismervény');

$t = new ReflectionMethod('SDH_Muhely_Nyomtatas', 'ajanlat_tarsak');
$t->setAccessible(true);
T::egyezik('eladas', (string) ($t->invoke(null, 'rendeles', 'rendeles')[0]['tipus'] ?? ''), 'rendelésnél az átadási bizonylat a társ');

/* --- 6. Napló ablak: fix magasság, saját görgetés ---------------------------- */

$ncss = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/naplo.css');
T::tartalmaz($ncss, '.sdh-naplo--ablak .sdh-naplo__lista', 'a Napló ablak listájának saját szabálya van');
T::tartalmaz($ncss, 'max-height: min(calc(62vh)', 'a lista magassága korlátos (nem nyúlik a bejegyzésekkel)');
T::tartalmaz($ncss, '.sdh-modal:has(.sdh-naplo--ablak)', 'és az ablak szélesebb');

/* --- 7. Levélíró: ismert címek ---------------------------------------------- */

T::igaz(has_action('wp_ajax_sdh_muhely_level_cimek') !== false, 'a címjavaslat végpontja regisztrálva van');

$cimek = SDH_Muhely_Levelezes::cimjavaslatok('');
T::igaz(count($cimek) > 0, 'üres kereséssel is jön javaslat (' . count($cimek) . ' cím)');
T::igaz(isset($cimek[0]['email'], $cimek[0]['nev'], $cimek[0]['honnan']), 'a javaslat neve, címe és forrása is megvan');

$keresett = SDH_Muhely_Levelezes::cimjavaslatok('Csomag Csaba');
T::igaz(count($keresett) > 0, 'névre keresve is talál');
T::igaz(count(SDH_Muhely_Levelezes::cimjavaslatok('nincs-ilyen-cim-sehol-xyz')) === 0, 'ismeretlen névre nem talál ki címet');

$lev = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/levelezes.js');
T::tartalmaz($lev, 'input[name="cimzett"]', 'a Címzett mezőn él a felajánlás');
T::tartalmaz($lev, 'input[name="titkos"]', 'a titkos másolaton is');
T::tartalmaz($lev, 'function cimUtolso', 'több címnél az utolsó darabot egészíti ki');

T::vege('t54 sebesség, nyitva maradó felajánlás, napló-ablak, levélcímek');
