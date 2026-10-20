<?php
/**
 * t320 – A licencközpont beágyazása a Portálba (egy telepítés), 2026-10-20. Al-folyamatokban, mindhárom módban
 * (a tesztoldal saját beállításától függetlenül):
 * - beágyazott (a különálló Központ nincs bekapcsolva): fut, nincs külön felső menü / belépés utáni átirányítás /
 *   admin-sáv gomb, a haladó felület rejtett oldal semleges címmel, a riasztó levelek linkje a Portál Licencek panelje,
 *   a kiadások semleges mappába / fájlnévvel kerülnek;
 * - különálló (a Központ bővítmény is aktív): az fut, nincs dupla betöltés, megvan a saját menüje, a Portál panelje is kezeli;
 * - nincs licencközpont (a beágyazás kikapcsolva): a panelek nem jelennek meg, a REST 409.
 * Folyamaton belül: a panelek, a panel szkriptje és a haladó felület kiszolgált fájljai (nincs rendszer- / márkanév).
 */
require __DIR__ . '/_t32-seged.php';

/* --- Panelek, kiszolgált fájlok (módfüggetlen) --------------------------------------------------------------------- */

T::igaz(SDH_Sales_Licenc::kezelheto(), 'helyi módban kezelhető');
T::igaz(SDH_Sales_Licenc::beagyazott_fajl() !== '' && is_file(SDH_Sales_Licenc::beagyazott_fajl()), 'a beágyazott fájl megvan');

$p = SDH_Sales_Admin::panelek([]);
foreach (['licencek' => 'Licencek', 'telepitesek' => 'Telepítések', 'kiadasok' => 'Kiadások'] as $k => $cim) {
    T::igaz(isset($p[$k]) && $p[$k]['cim'] === $cim && $p[$k]['elerheto'], 'panel: ' . $cim);
}
T::tartalmaz((string) ($p['licencek']['js'] ?? ''), 'assets/admin-licenc.js', 'a panel szkriptje');
T::egyezik('', (string) ($p['telepitesek']['js'] ?? ''), 'a szkript egyszer töltődik');
T::igaz(is_file(SDH_SALES_DIR . 'assets/admin-licenc.js'), 'a szkript fájl megvan');
T::igaz($p['licencek']['sorrend'] > $p['vevok']['sorrend'] && $p['kiadasok']['sorrend'] < $p['oldalak']['sorrend'], 'a menüben a Vevők után, a Tartalom előtt');
$tilt = '~wordpress|\bsdh\b|sdh[-_]|[-_]sdh|/wp-(content|includes|admin|json)/~i';
T::igaz(preg_match($tilt, (string) file_get_contents(SDH_SALES_DIR . 'assets/admin-licenc.js')) !== 1, 'a panel szkriptjében nincs rendszer- / márkanév');
foreach (['kozpont.js', 'kozpont.css'] as $f) {
    T::igaz(preg_match($tilt, (string) file_get_contents(dirname(SDH_Sales_Licenc::beagyazott_fajl()) . '/assets/' . $f)) !== 1, 'a haladó felület ' . $f . ' fájljában nincs rendszer- / márkanév');
}

/* --- Al-folyamatok: beágyazott, különálló, nincs ------------------------------------------------------------------- */

$seged = wp_tempnam('t320-al.php');
file_put_contents($seged, '<?php
$mod = $argv[1];
$_SERVER["HTTP_HOST"] = "127.0.0.1:8765"; $_SERVER["REQUEST_URI"] = "/wp/";
$GLOBALS["wp_filter"]["option_active_plugins"][10][] = ["accepted_args" => 1, "function" => static function ($a) use ($mod) {
    $a = array_values(array_diff((array) $a, ["sdh-kozpont/sdh-kozpont.php"]));
    if ($mod === "kulon") { array_unshift($a, "sdh-kozpont/sdh-kozpont.php"); }
    return $a;
}];
if ($mod === "nincs") { $GLOBALS["wp_filter"]["sdh_sales_licenc_beagyaz"][10][] = ["accepted_args" => 1, "function" => "__return_false"]; }
require ' . var_export(ABSPATH . 'wp-load.php', true) . ';
require_once ABSPATH . "wp-admin/includes/plugin.php";
require_once ABSPATH . "wp-admin/includes/file.php";
$admin = get_users(["role" => "administrator", "number" => 1, "fields" => "ID"]);
wp_set_current_user((int) ($admin[0] ?? 1));
do_action("rest_api_init", rest_get_server());
$p = SDH_Sales_Admin::panelek([]);
$v = rest_do_request(new WP_REST_Request("GET", "/portal/v1/admin/licencek"));
$ki = [
    "verzio" => defined("SDH_KOZPONT_VERSION") ? SDH_KOZPONT_VERSION : "",
    "beagyazva" => defined("SDH_KOZPONT_BEAGYAZVA"),
    "osztaly" => class_exists("SDH_Kozpont"),
    "panel" => isset($p["licencek"]),
    "lista" => $v->get_status(),
];
if (class_exists("SDH_Kozpont")) {
    global $menu, $_registered_pages;
    $menu = []; $_registered_pages = [];
    SDH_Kozpont_App::menu();
    $ki += [
        "beagyazott" => SDH_Kozpont::beagyazott() && SDH_Sales_Licenc::beagyazott(),
        "login" => (bool) has_filter("login_redirect", ["SDH_Kozpont_App", "belepes_utan"]),
        "vezerlopult" => (bool) has_action("load-index.php", ["SDH_Kozpont_App", "vezerlopult"]),
        "adminsav" => (bool) has_action("admin_bar_menu", ["SDH_Kozpont_App", "admin_sav"]),
        "oldal" => SDH_Kozpont_App::oldal_kulcs(),
        "cim" => SDH_Kozpont_App::cim(),
        "felulet" => SDH_Kozpont::felulet_url(),
        "felso" => array_values(array_map(static fn ($m) => (string) ($m[2] ?? ""), (array) $menu)),
        "rejtett" => isset($_registered_pages[get_plugin_page_hookname("licenckozpont", "")]),
    ];
    if ($mod === "beagy") {
        SDH_Kozpont::sema();
        $zip = wp_tempnam("t320.zip");
        $z = new ZipArchive();
        $z->open($zip, ZipArchive::OVERWRITE);
        $z->addFromString("sdh-muhely/sdh-muhely.php", "<?php\n/**\n * Version: 9.9.320\n */\n");
        $z->close();
        $ki["kiadas_hiba"] = SDH_Kozpont::kiadas_be($zip, "stabil", "t320", "teszt");
        @unlink($zip);
        $f = SDH_Kozpont::kiadas("stabil")["fajl"];
        $ki["kiadas"] = basename(dirname($f)) . "/" . basename($f);
        $ki["kiadas_zarva"] = is_file(dirname($f) . "/.htaccess");
        if (is_file($f)) { unlink($f); }
    }
}
echo json_encode($ki);
');

$futtat = static function (string $mod) use ($seged): array {
    $ki = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($seged) . ' ' . escapeshellarg($mod) . ' 2>&1', $ki);
    $j = json_decode((string) end($ki), true);

    return is_array($j) ? $j : ['hiba' => implode("\n", array_slice($ki, -5))];
};

// Beágyazott
$b = $futtat('beagy');
T::egyezik(true, (bool) ($b['beagyazva'] ?? false), 'beágyazott: fut (SDH_KOZPONT_BEAGYAZVA) ' . ($b['hiba'] ?? ''));
T::egyezik(true, (bool) ($b['beagyazott'] ?? false), 'beágyazott: beagyazott() mindkét oldalon');
T::egyezik('2.2.0', (string) ($b['verzio'] ?? ''), 'beágyazott: licencközpont 2.2.0');
T::egyezik(false, (bool) ($b['login'] ?? true), 'beágyazott: nincs belépés utáni átirányítás');
T::egyezik(false, (bool) ($b['vezerlopult'] ?? true), 'beágyazott: nincs vezérlőpult-csere');
T::egyezik(false, (bool) ($b['adminsav'] ?? true), 'beágyazott: nincs admin-sáv gomb');
T::egyezik('licenckozpont', (string) ($b['oldal'] ?? ''), 'beágyazott: a haladó oldal semleges kulcsa');
T::egyezik('Licencközpont', (string) ($b['cim'] ?? ''), 'beágyazott: a haladó oldal címe semleges');
T::tartalmaz((string) ($b['felulet'] ?? ''), 'page=' . SDH_Sales_Admin::OLDAL . '#/licencek', 'beágyazott: riasztó levél linkje a Portál Licencek panelje');
T::igaz(!in_array('sdh-kozpont', (array) ($b['felso'] ?? ['sdh-kozpont']), true) && !in_array('licenckozpont', (array) ($b['felso'] ?? []), true), 'beágyazott: nincs külön felső menü');
T::egyezik(true, (bool) ($b['rejtett'] ?? false), 'beágyazott: a haladó oldal rejtetten regisztrálva');
T::egyezik(true, (bool) ($b['panel'] ?? false), 'beágyazott: a Licencek panel megvan');
T::egyezik(200, (int) ($b['lista'] ?? 0), 'beágyazott: a lista működik');
T::egyezik('', (string) ($b['kiadas_hiba'] ?? 'x'), 'beágyazott: kiadás feltöltve');
T::igaz(strpos((string) ($b['kiadas'] ?? ''), 'licenc-kiadas/kiadas-9.9.320-') === 0, 'beágyazott: kiadás semleges mappába / fájlnévvel (' . ($b['kiadas'] ?? '') . ')');
T::egyezik(true, (bool) ($b['kiadas_zarva'] ?? false), 'beágyazott: a kiadások mappája zárva');

// Különálló
if (is_dir(WP_PLUGIN_DIR . '/sdh-kozpont')) {
    $k = $futtat('kulon');
    T::egyezik(false, (bool) ($k['beagyazva'] ?? true), 'különálló: a bővítmény fut, nem a beágyazott ' . ($k['hiba'] ?? ''));
    T::egyezik('2.2.0', (string) ($k['verzio'] ?? ''), 'különálló: egy példány, hiba nélkül');
    T::egyezik(true, (bool) ($k['login'] ?? false), 'különálló: a saját belépési viselkedés megmarad');
    T::egyezik('sdh-kozpont', (string) ($k['oldal'] ?? ''), 'különálló: a saját oldalkulcsa');
    T::igaz(in_array('sdh-kozpont', (array) ($k['felso'] ?? []), true), 'különálló: a saját felső menüje');
    T::egyezik(true, (bool) ($k['panel'] ?? false), 'különálló: a Portál panelje is kezeli');
    T::egyezik(200, (int) ($k['lista'] ?? 0), 'különálló: a lista működik');
} else {
    T::igaz(true, 'különálló: a tesztoldalon nincs Központ-bővítmény mappa (kihagyva)');
}

// Nincs licencközpont
$n = $futtat('nincs');
T::egyezik(false, (bool) ($n['osztaly'] ?? true), 'beágyazás kikapcsolva: nincs licencközpont ' . ($n['hiba'] ?? ''));
T::egyezik(false, (bool) ($n['panel'] ?? true), 'nincs licencközpont: nincs Licencek panel');
T::egyezik(409, (int) ($n['lista'] ?? 0), 'nincs licencközpont: a REST 409');
@unlink($seged);

T::vege('t320 licencközpont beágyazva / különálló / nincs');
