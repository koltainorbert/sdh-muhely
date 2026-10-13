<?php
/**
 * t55 – 0.61.0: levél-feladat ügynök, SDH-belső keret, tételcímke, figurák.
 *
 * - A szabály a feladóra és a tárgyra illeszkedik, a levél szövegére SOHA.
 * - A Gigatel-olvasó csak azokat a tételeket adja vissza, amelyekhez
 *   telefonszám tartozik; árat egyik mezőbe sem tesz.
 * - A feladat létrejön, nyitott marad, és elintézhető.
 * - A tételcímke mezői pontosan a levélben álló adatok (ár nélkül).
 * - Az SDH-belső rész egy mappa (includes/sdh/) – eladáskor törölhető.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$LF = 'SDH_Muhely_Level_Feladat';
$G  = 'SDH_Muhely_Sdh_Gigatel';

/* --- 1. Az SDH-belső keret --------------------------------------------------- */

T::igaz(class_exists('SDH_Muhely_Sdh'), 'az SDH-belső keret betöltődött');
T::igaz(is_dir(SDH_MUHELY_DIR . 'includes/sdh'), 'a belső rész külön mappában van (eladáskor törölhető)');
T::igaz(SDH_Muhely_Sdh::be(), 'alapból fut');
T::igaz(isset(SDH_Muhely_Sdh::modulok()['gigatel']), 'a Gigatel belső modulként jelentkezett be');

SDH_Muhely_Sdh::lapfulek();
$szakaszok = SDH_Muhely_Beallitasok::szakaszok();
T::igaz(isset($szakaszok['sdh']), 'van „SDH-modulok" lapfül');
T::egyezik('sdh', (string) ($szakaszok['sdh']['csoport'] ?? ''), 'a saját csoportjában');
T::igaz(isset($szakaszok['sdh_gigatel']), 'és a Gigatel lapja is');

// A belső rész semmilyen általános osztályt nem tesz kötelezővé: az ügynök nélküle is él.
$altalanos = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-level-feladat.php');
T::nem_tartalmaz($altalanos, 'Gigatel', 'az általános ügynökben nincs Gigatel-utalás');
T::nem_tartalmaz($altalanos, 'SDH_Muhely_Sdh', 'és nem függ a belső résztől');

/* --- 2. Szabályillesztés ----------------------------------------------------- */

$G::szabaly_frissit();
$sz = $LF::szabalyok()[$G::SZABALY] ?? null;

T::igaz(is_array($sz), 'a Gigatel-szabály bekerült az ügynökbe');
T::egyezik('Gigatel számla', (string) $sz['tesz']['mappa'], 'a cél mappa a beállításból jön');

$sor = [
    'fiok' => 'f1', 'felado_email' => 'webaruhaz@gigatel.hu', 'felado_nev' => 'Gigatel webáruház',
    'targy' => 'ESB202626208 elektronikus számlája elkészült', 'csatolmany' => 1,
    'message_id' => '<t55-1@gigatel.hu>', 'uid' => 11,
];

T::igaz($LF::illik($sz, $sor), 'a napi Gigatel-levélre ráillik');
T::igaz(!$LF::illik($sz, ['fiok' => 'f1', 'felado_email' => 'hirlevel@mas.hu', 'felado_nev' => 'Más', 'targy' => 'számlája elkészült', 'csatolmany' => 1]), 'más feladóra nem');
T::igaz(!$LF::illik($sz, array_merge($sor, ['targy' => 'Heti ajánlat'])), 'más tárgyra nem');
T::igaz(!$LF::illik(['aktiv' => true, 'fiok' => '', 'ha' => ['felado' => '', 'targy' => '', 'csatolmany' => ''], 'tesz' => []], $sor), 'feltétel nélküli szabály soha nem illik (nem fut rá mindenre)');

// A levél SZÖVEGE nem számít az illeszkedésbe: a levél nem tudja magát beajánlani.
$altalanos_illik = (string) strstr($altalanos, 'public static function illik');
$altalanos_illik = (string) substr($altalanos_illik, 0, (int) strpos($altalanos_illik, 'public static function uj_level'));
T::nem_tartalmaz($altalanos_illik, 'szoveg', 'az illesztés a levél szövegét nem nézi');

/* --- 3. Tételolvasó: csak a telefonszámos tételek, ár nélkül ------------------ */

$szoveg = "Samsung Galaxy Tab A11 WIFI (SM-X130)\n"
    . "Tok álló, bőr hatású (aktív FLIP, oldalra nyíló, TRIFOLD, asztali tartó) PIROS\n"
    . "Azonosító: GP-177299\nGarancia: Szavatosság\nCikkszám: 177299\n"
    . "Megjegyzés: 8000Fizetve, 06302121235\n1 db Készleten\nNettó egységár: 3 999 Ft\n3 999 Ft\n\n"
    . "Apple iPhone Duo\nSAMSUNG hálózati töltő Type-C aljzat (25W, GaN) FEKETE\n"
    . "Azonosító: EP-T2510XBEGEU\nGarancia: Szavatosság\nCikkszám: 149559\n1 db Készleten\nNettó egységár: 4 289 Ft\n\n"
    . "Apple iPhone Duo\nSAMSUNG hálózati töltő Type-C aljzat (25W, GaN) FEHÉR\n"
    . "Azonosító: EP-T2510XWEGEU\nGarancia: Szavatosság\nCikkszám: 149560\n"
    . "Megjegyzés: Rendelte: +36 70 123 4567\n2 db Készleten\n";

$tetelek = $G::tetelek($szoveg);

T::egyezik(2, count($tetelek), 'csak a telefonszámos tételek (a harmadik kimarad)');
T::egyezik('Samsung Galaxy Tab A11 WIFI (SM-X130)', (string) $tetelek[0]['keszulek'], 'a készülék sora');
T::egyezik('GP-177299', (string) $tetelek[0]['azonosito'], 'azonosító');
T::egyezik('Szavatosság', (string) $tetelek[0]['garancia'], 'garancia');
T::egyezik('177299', (string) $tetelek[0]['cikkszam'], 'cikkszám');
T::egyezik('06302121235', (string) $tetelek[0]['telefon'], 'a megjegyzésből a telefonszám');
T::egyezik('+36701234567', (string) $tetelek[1]['telefon'], 'a szóközös, +36-os alak is');

foreach ($tetelek as $t) {
    T::igaz(!in_array('ar', array_keys($t), true) && strpos(implode(' ', $t), ' Ft') === false, 'a tételben nincs ár (' . $t['cikkszam'] . ')');
}

T::egyezik('', $G::telefon('8000 Fizetve'), 'a nem telefonszám nem telefonszám');

/* --- 4. Feladat: létrejön, nyitott, elintézhető ------------------------------- */

$wpdb->query('DELETE FROM ' . $LF::tabla() . " WHERE szabaly = 'gigatel'");

$level = ['csatolmanyok' => [
    0 => ['nev' => 'ESB202626208.pdf', 'tipus' => 'application/pdf', 'meret' => 9000, 'beagyazott' => false],
    1 => ['nev' => 'logo.png', 'tipus' => 'image/png', 'meret' => 300, 'beagyazott' => true],
]];

$LF::uj_level(4711, 7, $sor, $szoveg, $level);
$LF::uj_level(4711, 7, $sor, $szoveg, $level); // ugyanaz a levél másodszor: nem lesz két feladat

$nyitott = $LF::feladatok(true, 10);
T::egyezik(1, count($nyitott), 'egy levél – egy feladat (a második nem duplázza)');

$f = $nyitott[0];
$a = $LF::adat($f);

T::egyezik('Gigatel számla', (string) $f->cim, 'a feladat neve a szabályé');
T::egyezik(2, count($LF::tetelek($f)), 'a tételek elmentve');
T::egyezik(1, count((array) $a['csatolmanyok']), 'csak a PDF melléklet (a beágyazott kép nem)');
T::egyezik('ESB202626208.pdf', (string) $a['csatolmanyok'][0]['nev'], 'a számla fájlneve');
T::egyezik(['Gigatel számla' => [4711]], $LF::mozgatando('f1', 7), 'a levél a Gigatel számla mappába kerül');
T::egyezik([], $LF::mozgatando('f1', 7), 'a mozgatás csak egyszer hajtódik végre');

T::egyezik(1, $LF::nyitott_db(), 'egy nyitott feladat (erről emlékeztet)');
T::igaz($LF::allapot_ir((int) $f->id, 'elintezve'), 'elintézhető');
T::egyezik(0, $LF::nyitott_db(), 'utána nincs nyitott');
T::igaz($LF::allapot_ir((int) $f->id, 'nyitott'), 'és újranyitható');

ob_start();
$LF::ablak(false);
$ablak = (string) ob_get_clean();

T::tartalmaz($ablak, 'Számla nyomtatása', 'az ablakban ott a számla nyomtatása');
T::tartalmaz($ablak, 'data-sdh-matrica-tipus="tetel"', 'és a címke gomb');
T::tartalmaz($ablak, '06302121235', 'a tételnél látszik a telefonszám');

/* --- 5. A tételcímke: pontosan a levél adatai, ár nélkül ---------------------- */

$M = 'SDH_Muhely_Matrica';
T::igaz(isset($M::SABLONOK['tetel']), 'van Beszállítói tétel címkesablon');

$mezok = $M::mezok('tetel');
foreach (['keszulek', 'megnevezes', 'azonosito', 'garancia', 'cikkszam', 'megjegyzes'] as $k) {
    T::igaz(!empty($mezok[$k]['be']), 'a címkén alapból ott a(z) ' . $k);
}

T::igaz(!isset($mezok['ar']) && !isset($mezok['netto']), 'ár NINCS a címke mezői között');

$cimke = $M::tetel_adatok((int) $f->id);
T::egyezik(2, count($cimke), 'a feladat két tétele címkézhető');
T::egyezik('177299', (string) $cimke[0]['cikkszam'], 'a címke a tétel cikkszámát viszi');
T::igaz(strpos(implode(' ', array_map('strval', $cimke[0])), ' Ft') === false, 'a címke adatai közt nincs ár');

/* --- 6. Tíz figura ----------------------------------------------------------- */

$figurak = SDH_Muhely_Asszisztens::figurak();
T::igaz(count($figurak) >= 10, 'legalább tíz választható figura (' . count($figurak) . ')');
T::igaz(isset($figurak['szikra']), 'köztük a klasszikus Szikra');

$css = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/asszisztens.css');

foreach (array_keys($figurak) as $fk) {
    if ($fk === 'szikra') {
        continue; // az alapfigura az alapszabályt használja
    }

    T::tartalmaz($css, '.sdh-ai[data-figura="' . $fk . '"]', 'a(z) „' . $figurak[$fk]['nev'] . '" figurának van rajza');
}

$ajs = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/asszisztens.js');
T::tartalmaz($ajs, "setAttribute('data-figura'", 'a választott figura a felületre is kikerül');

/* --- 7. Nem megy vissza az időben (0.61.1) ----------------------------------- */

T::egyezik(gmdate('Y-m-d', (int) strtotime(current_time('Y-m-d') . ' -1 day')), $LF::tegnap(), 'az új szabály a tegnapi naptól figyel');

$sz_tol = $LF::szabalyok()[$G::SZABALY];
T::igaz((string) $sz_tol['tol'] !== '', 'a Gigatel-szabálynak van kezdőnapja');

$wpdb->query('DELETE FROM ' . $LF::tabla() . " WHERE message_id IN ('<t55-regi@g.hu>','<t55-mai@g.hu>')");

$regi_sor = ['fiok' => 'f1', 'felado_email' => 'web@gigatel.hu', 'felado_nev' => 'Gigatel', 'targy' => 'ESB1 elektronikus számlája elkészült',
    'csatolmany' => 1, 'message_id' => '<t55-regi@g.hu>', 'uid' => 1, 'datum' => '2020-01-01 10:00:00'];

$LF::uj_level(5101, 7, $regi_sor, $szoveg, $level);
T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $LF::tabla() . " WHERE message_id = '<t55-regi@g.hu>'"), 'a kezdőnap előtti levélre NEM nyit feladatot');

$mai_sor = array_merge($regi_sor, ['message_id' => '<t55-mai@g.hu>', 'datum' => current_time('mysql')]);
$LF::uj_level(5102, 7, $mai_sor, $szoveg, $level);
T::egyezik(1, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $LF::tabla() . " WHERE message_id = '<t55-mai@g.hu>'"), 'a mai levélre igen');
$LF::mozgatando('f1', 7);

/* --- 8. A tételolvasó HTML-levélből is dolgozik ------------------------------- */

require_once SDH_MUHELY_DIR . 'includes/lib/sdh-mime.php';

$html = '<table><tr><td><div>Samsung Galaxy Tab A11 WIFI (SM-X130)</div><strong>Tok álló PIROS</strong>'
    . '<div>Azonosító: GP-177299</div><div>Garancia: Szavatosság</div><div>Cikkszám: 177299</div>'
    . '<div>Megjegyzés: 8000Fizetve, 06302121235</div></td><td>1 db Készleten<br>Nettó egységár: 3 999 Ft<br>3 999 Ft</td></tr></table>';

$html_tetelek = $G::tetelek(SDH_Muhely_Mime::html_szoveg($html));

T::egyezik(1, count($html_tetelek), 'HTML-levélből is kijön a tétel');
T::egyezik('177299', (string) $html_tetelek[0]['cikkszam'], 'a cikkszámmal');
T::egyezik('Tok álló PIROS', (string) $html_tetelek[0]['megnevezes'], 'és a megnevezéssel (az ár nem lóg bele)');
T::igaz(strpos(implode(' ', $html_tetelek[0]), 'Ft') === false, 'ár sehol a tételben');

/* --- 9. Rendelés-címke, Címkék menüpont, toast helye (0.61.1) ----------------- */

T::igaz(isset($M::SABLONOK['rendeles']), 'van Rendelés-címke sablon');
$rm = $M::mezok('rendeles');
T::igaz(!empty($rm['nev']['be']) && !empty($rm['munkalapszam']['be']) && !empty($rm['telefon']['be']), 'alapból a név, a lapszám és a telefon van rajta');
T::igaz(empty($rm['brutto']['be']) && empty($rm['elolog']['be']), 'ár alapból nincs rajta (de bekapcsolható)');

$ml = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-munkalap.php');
T::tartalmaz($ml, "\$fajta === 'rendeles' ? 'rendeles' : 'rma'", 'a rendelés a saját címkesablonjával nyomtat');

T::igaz(isset(SDH_Muhely_Modulok::osszes()['matrica']), 'a Címkék külön menüpont');

$aui = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-admin-ui.php');
T::nem_tartalmaz($aui, "sdh-matrica-tipus' => 'egyedi'", 'az Áttekintés fejlécéből eltűnt az Egyedi címke');

$lcss = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/levelezes.css');
T::tartalmaz($lcss, '--sdh-felso-sav', 'a jelzések a jobb felső sarokban állnak (nem csúsznak a lap aljára)');
T::nem_tartalmaz($lcss, ':root:has(.sdh-ai) .sdh-level-toastok', 'és nincs külön szabály a figura mellé');

$rcss = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/rma-ugyfel.css');
T::tartalmaz($rcss, 'border-radius: 999px', 'az ügyféloldali fülek pillek');
T::nem_tartalmaz($rcss, 'border-radius: 8px 8px 0 0', 'a régi lapfül-forma eltűnt');

/* --- 10. Globális gombforma: pill, üveg, fénykeret (0.61.2) ------------------ */

$A = 'SDH_Muhely_Arculat';

T::egyezik('pill', (string) $A::beallitas()['gombforma'], 'a gombok alapból pillek (oválisak)');
T::igaz(isset($A::gombformak()['lagy'], $A::gombformak()['szogletes']), 'a régi formák is választhatók');
T::igaz($A::beallitas()['uveghatas'], 'az üveghatás alapból be van kapcsolva');
T::igaz(isset($A::gombfenyek()[(string) $A::beallitas()['gombfeny']]), 'és van érvényes fénykeret-fokozat');

// Az alapállapot a stíluslapban áll, ezért nem kerül inline CSS-be; csak az
// attól való eltérés megy ki.
$arculat_regi = get_option('sdh_muhely_arculat');

update_option('sdh_muhely_arculat', ['gombforma' => 'szogletes', 'uveghatas' => 0, 'gombfeny' => '']);
$acss = $A::stilus_css();

T::tartalmaz($acss, '--sdh-gomb-r:calc(2px', 'a szögletes forma CSS-változóban megy ki');
T::tartalmaz($acss, '--sdh-uvegero:0', 'a kikapcsolt üveghatás is');
T::tartalmaz($acss, '--sdh-gombfeny:0', 'és a kikapcsolt fénykeret');

update_option('sdh_muhely_arculat', is_array($arculat_regi) ? $arculat_regi : []);
T::nem_tartalmaz($A::stilus_css(), '--sdh-gomb-r', 'alapállapotban nincs felesleges inline CSS');

$admincss = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/admin.css');
T::tartalmaz($admincss, 'border-radius: var(--sdh-gomb-r', 'a gombok a változóból veszik a kerekítést');
T::tartalmaz($admincss, 'backdrop-filter', 'van üveghatás (elmosott háttér)');

ob_start();
$A::beallitas_doboz();
$doboz = (string) ob_get_clean();

T::tartalmaz($doboz, 'arculat_gombforma', 'a Beállításokban állítható a forma');
T::tartalmaz($doboz, 'arculat_uveghatas', 'az üveghatás');
T::tartalmaz($doboz, 'arculat_gombfeny', 'és a fénykeret');
T::tartalmaz($doboz, 'sdh-gombminta', 'mintasor mutatja, mi lesz belőle');

/* --- 11. Címkeablak: saját léptető a példányszámnál (0.61.2) ------------------ */

$mcss = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/matrica.css');
T::tartalmaz($mcss, '.sdh-matrica__lep', 'a példányszámnak saját −/+ gombja van');

$mphp = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-matrica.php');
T::tartalmaz($mphp, 'data-sdh-szam-nincs', 'és a mező kimarad az automata kis nyilakból');

$appjs = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.js');
T::tartalmaz($appjs, "hasAttribute('data-sdh-szam-nincs')", 'a szám-mező bekötője tiszteletben tartja a jelzőt');
$mjs = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/matrica.js');
T::tartalmaz($mjs, 'data-sdh-matrica-lep', 'a léptető gombok be vannak kötve');

/* --- 12. Ügyféloldal: piros számok, teljes képernyő, fordítás (0.61.2) -------- */

T::tartalmaz($rcss, '.sdh-fulek__db', 'a pilleken számjelvény áll');
T::tartalmaz($rcss, '--rma-n', 'nagy monitoron magától nagyobb lesz');
T::tartalmaz($rcss, 'is-teljes', 'és van teljes képernyős mód');
T::tartalmaz($rcss, '@media (max-width: 420px)', 'keskeny telefonra külön szabály');

$rphp = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-rma-oldal.php');
T::tartalmaz($rphp, 'data-rma-teljes', 'a fejlécben ott a teljes képernyő gomb');
T::tartalmaz($rphp, 'function tetel_nev', 'a rendszer készítette tételnevek fordítva mennek ki');

/* --- 13. Számla linkből, és a kezdőnap takarítása (0.61.2) -------------------- */

$linkes = ['html' => '<p>Számlája elkészült. <a href="https://szamla.gigatel.hu/e/ESB202626208">Számla letöltése</a>'
    . ' <a href="https://mas.hu/hirlevel">Leiratkozás</a></p>'];

$lk = $LF::linkek($linkes, 'gigatel.hu');
T::egyezik(1, count($lk), 'a levélből kijön a számla linkje (a más oldalra mutató nem)');
T::tartalmaz((string) $lk[0]['url'], 'szamla.gigatel.hu', 'a beszállító saját címére mutat');
T::egyezik([], $LF::linkek($linkes, ''), 'minta nélkül nem szed ki linket');

$wpdb->query('DELETE FROM ' . $LF::tabla() . " WHERE message_id = '<t55-takarit@g.hu>'");

$takarit_sor = array_merge($regi_sor, ['message_id' => '<t55-takarit@g.hu>', 'datum' => current_time('mysql')]);
$LF::uj_level(5103, 7, $takarit_sor, $szoveg, $level);
T::egyezik(1, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $LF::tabla() . " WHERE message_id = '<t55-takarit@g.hu>'"), 'a mai levélre feladat nyílt');

$holnap = gmdate('Y-m-d', (int) strtotime(current_time('Y-m-d') . ' +1 day'));
T::igaz($LF::takarit($G::SZABALY, $holnap) >= 1, 'a kezdőnap előrehozása kitakarítja a régebbi nyitott feladatokat');
T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $LF::tabla() . " WHERE message_id = '<t55-takarit@g.hu>'"), 'a feladat eltűnt');

/* --- 14. Az eszközcsomag a módosított fájlt is kiszolgálja (0.61.2) ----------- */

$csomag = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-csomag.php');
T::tartalmaz($csomag, 'filemtime($fajl)', 'a csomag kulcsa a fájlok módosítási idejét is nézi (nem ragad be régi CSS)');

T::vege('t55 levél-feladat ügynök, SDH-belső, címkék, ügyféloldal, gombok');
