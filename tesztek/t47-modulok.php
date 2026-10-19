<?php
/**
 * t47 – Modulok (0.58): a fő funkciók ki- és bekapcsolása.
 *
 * Katalógus és alaprendszer; mentés, függőség; a kikapcsolt modul eltűnik a
 * menüből, a csempékből, a Beállításokból, a súgóból, az asszisztens
 * eszközei közül; az AJAX-őr elutasít (kivétel: a munkalap Fizetés ablaka);
 * a különleges esetek (pénztár, Számlázz.hu, RMA-címke, Csapat, nyomtatás,
 * SMS, mockup, IMEI, TAC); a telepítői zár. A végén minden visszakapcsol.
 */

require __DIR__ . '/_keret.php';

final class T47_Die extends RuntimeException
{
}

$Mo = 'SDH_Muhely_Modulok';
$K  = 'SDH_Muhely_Modul_Kapcsolo';

// Kiinduló állapot: minden be, nincs zár (egy korábbi, félbeszakadt futás után is).
$sms_regi = get_option(SDH_Muhely_Sms::OPT, null);
delete_option($K::OPT_ZAR);
delete_option($Mo::OPT_KI);
$Mo::kapcsolok_urit();

/** A wp_die-t (wp_send_json_error) a t_ajax kivétele állítja meg – az őrt is így hívjuk. */
$or = static function (string $akcio, bool $ajax = true): string {
    t_ajax('sdh_muhely_pulzus_nincs'); // a t_ajax szűrőinek beállítása (wp_doing_ajax, wp_die)

    $regi = $_REQUEST;
    $_REQUEST = ['action' => $akcio];

    if (!$ajax) {
        remove_all_filters('wp_doing_ajax');
        // A sima (nem AJAX) wp_die ne lépjen ki: kivétellel jön vissza ide.
        add_filter('wp_die_handler', $dobo = static fn () => static function ($uzenet): void {
            throw new T47_Die(is_string($uzenet) ? $uzenet : 'wp_die');
        });
    }

    ob_start();

    try {
        SDH_Muhely_Modul_Kapcsolo::orszem();
    } catch (T_Ajax_Vege $v) {
        // elutasított
    } catch (T47_Die $v) {
        echo 'WPDIE:' . $v->getMessage();
    }

    $ki = (string) ob_get_clean();
    $_REQUEST = $regi;

    if (!$ajax) {
        add_filter('wp_doing_ajax', '__return_true');
        remove_filter('wp_die_handler', $dobo);
    }

    return $ki;
};

$keret = static function (string $aktiv = 'attekintes'): string {
    SDH_Muhely_Modulok::kontextus_beallit('frontend');
    ob_start();
    SDH_Muhely_Frontend::keret('Teszt', $aktiv, static function (): void {
        echo '<p>tartalom</p>';
    });

    return (string) ob_get_clean();
};

$ment = static function (array $ki, string $kod = '') use ($Mo, $K): array {
    $be = array_values(array_diff(array_keys($Mo::katalogus()), $ki));
    $e  = $K::feldolgoz($be, $kod);

    if (class_exists('SDH_Muhely_Sugo')) {
        SDH_Muhely_Sugo::urit();
    }

    return $e;
};

try {
    /* --- Katalógus és alaprendszer -------------------------------------- */

    $kat = $Mo::katalogus();

    T::egyezik(17, count($kat), '17 kikapcsolható fő modul (0.99.3: Online időpontfoglalás)');
    T::egyezik(10, count($Mo::alapreszek()), '10 alaprész (mindig fut; 0.59: Napló, 0.87: Riportok)');
    T::egyezik([], array_intersect(array_keys($kat), array_keys($Mo::alapreszek())), 'alaprész nem kapcsolható ki');

    foreach ($kat as $kulcs => $m) {
        T::igaz($Mo::be($kulcs), 'alapból be van kapcsolva: ' . $kulcs);
        T::igaz((string) ($m['cim'] ?? '') !== '' && (string) ($m['leiras'] ?? '') !== '' && (string) ($m['csoport'] ?? '') !== '', 'van címe, leírása, csoportja: ' . $kulcs);

        foreach ((array) ($m['fugg'] ?? []) as $f) {
            T::igaz(isset($kat[$f]), $kulcs . ': a függősége létező modul (' . $f . ')');
        }
    }

    T::igaz($Mo::be('munkalapok') && $Mo::be('nincs-ilyen'), 'az alaprendszer és az ismeretlen kulcs mindig „be”');

    $mo = $Mo::egy('modulok');
    T::igaz($mo !== null && !empty($mo['csak_admin']), 'a Modulok menüpont be van jegyezve, csak adminisztrátornak');

    // Minden AJAX-művelet legfeljebb egy modulé, és a modulok saját végpontjai a sajátjuk.
    T::egyezik('sms', $Mo::ajax_modulja('smsdontes_urlap'), 'smsdontes_urlap → sms');
    T::egyezik('penztar', $Mo::ajax_modulja('penztar_adat'), 'penztar_adat → penztar');
    T::egyezik('', $Mo::ajax_modulja('penztar_fizet'), 'a munkalap Fizetés ablaka kivétel (penztar_fizet)');
    T::egyezik('', $Mo::ajax_modulja('penztarfizetes_urlap'), 'kivétel: penztarfizetes_urlap');
    T::egyezik('', $Mo::ajax_modulja('szamla_urlap'), 'a számla ablaka (helyi előkészítő is) nem a Számlázz.hu modulé');
    T::egyezik('szamlazas', $Mo::ajax_modulja('szamla_ment'), 'szamla_ment → szamlazas');
    T::egyezik('', $Mo::ajax_modulja('csapatkollega_ment'), 'a kollégák kezelése nem a Csapat modulé');
    T::egyezik('', $Mo::ajax_modulja('pulzus'), 'a pulzus mindenkié');
    T::egyezik('', $Mo::ajax_modulja('munkalapok_ment'), 'a munkalap az alaprendszeré');
    T::egyezik('termekek', $Mo::post_modulja('termek_mentes'), 'admin-post: termek_mentes → termekek');

    // Minden bejegyzett AJAX-művelet: ha egy modulé, az a modul létezik.
    global $wp_filter;
    $gazdatlan = [];

    foreach (array_keys($wp_filter) as $horog) {
        if (preg_match('/^wp_ajax_sdh_muhely_(.+)$/', (string) $horog, $t) === 1) {
            $m = $Mo::ajax_modulja($t[1]);

            if ($m !== '' && !isset($kat[$m])) {
                $gazdatlan[] = $t[1];
            }
        }
    }

    T::egyezik([], $gazdatlan, 'minden modulhoz rendelt AJAX-művelet létező modulé');

    /* --- Az oldal ------------------------------------------------------- */

    SDH_Muhely_Modulok::kontextus_beallit('frontend');
    ob_start();
    $K::oldal();
    $oldal = (string) ob_get_clean();

    T::tartalmaz($oldal, 'data-sdh-modulok', 'a Modulok oldal kirajzolódik');
    T::egyezik(17, substr_count($oldal, 'name="be[]"'), '17 kapcsoló');
    T::egyezik(17, substr_count($oldal, 'name="be[]" id="sdh-modul-') , 'minden kapcsolónak saját azonosítója');
    T::egyezik(17, preg_match_all('/name="be\[\]" id="[^"]+" value="[a-z]+"\s+checked/', $oldal), 'alapból mind bekapcsolva');
    T::egyezik(10, substr_count($oldal, 'sdh-modulkartya--alap'), 'az alaprendszer 10 kártyája');
    T::tartalmaz($oldal, 'Ehhez kell: Ügyfélüzenetek és RMA-oldal', 'a függőség kiírva');
    T::tartalmaz($oldal, 'value="sdh_muhely_modulok"', 'az űrlap a mentésre küld');
    T::tartalmaz($oldal, 'Telepítői zár', 'a telepítői zár doboza');
    T::nem_tartalmaz($oldal, 'name="kod"', 'zár nélkül nincs kódmező');
    T::nem_tartalmaz($oldal, 'wp-admin/admin.php', 'a műhely-felületről nem visz a wp-adminba');

    $k = $keret();
    T::tartalmaz($k, 'muhely=modulok', 'az oldalmenü alján ott a Modulok');
    T::tartalmaz($k, 'muhely=naptar', 'bekapcsolva a Naptár a menüben');
    T::nem_tartalmaz($k, 'sdh-ki-', 'minden bekapcsolva: nincs sdh-ki- body-osztály');

    /* --- Kikapcsolás: Naptár, SMS, Nyomtatványok, Mockup, IMEI, TAC, asszisztens --- */

    $e = $ment(['naptar', 'sms', 'nyomtatas', 'mockup', 'imei', 'tac', 'asszisztens']);
    T::igaz($e['ok'], 'mentés: ok');
    T::egyezik(['asszisztens', 'foglalas', 'imei', 'mockup', 'naptar', 'nyomtatas', 'sms', 'tac'], $Mo::kikapcsoltak(), 'a kikapcsoltak listája (a foglalás a Naptárral együtt)');
    T::tartalmaz($e['uzenet'], '9 modul bekapcsolva, 8 kikapcsolva', 'az üzenet a darabszámokkal');

    T::igaz($Mo::egy('naptar') === null && $Mo::egy('sms') === null, 'a kikapcsolt modul nincs bejegyezve');
    T::igaz(!isset($Mo::osszes()['naptar']), 'az összes modul között sincs');
    T::igaz($Mo::egy('asszisztens') === null, 'az asszisztens oldala sincs');

    $k = $keret();
    T::nem_tartalmaz($k, 'muhely=naptar', 'a Naptár eltűnt a menüből');
    T::nem_tartalmaz($k, 'muhely=sms"', 'az SMS eltűnt a menüből');
    T::tartalmaz($k, 'sdh-ki-naptar', 'body-osztály: sdh-ki-naptar');
    T::tartalmaz($k, 'sdh-ki-nyomtatas', 'body-osztály: sdh-ki-nyomtatas');
    T::nem_tartalmaz($k, 'sdh-fej__nyomtat', 'nincs Nyomtatás gomb a fejlécben');

    $js = SDH_Muhely_Admin_UI::js_beallitas('frontend');
    T::egyezik(['sms', 'naptar', 'foglalas', 'asszisztens', 'nyomtatas', 'imei', 'tac', 'mockup'], $js['modulokKi'], 'js_beallitas: modulokKi');
    T::igaz(empty($js['ai']['be']), 'js: az asszisztens figurája nem indul');
    T::igaz(empty($js['sms']['be']), 'js: nincs SMS-kérdés');
    T::igaz(empty($js['nyomtat']['ajanlat']), 'js: nincs nyomtatási felajánlás');

    $att = (static function (): string { ob_start(); SDH_Muhely_Admin_UI::attekintes_tartalom(false); return (string) ob_get_clean(); })();
    T::nem_tartalmaz($att, 'muhely=naptar', 'az Áttekintésen nincs Naptár csempe');
    T::tartalmaz($att, 'muhely=munkalapok', 'az Áttekintésen a munkalapok csempéje megvan');

    $sz = SDH_Muhely_Beallitasok::szakaszok();
    foreach (['sms', 'naptar', 'nyomtatvanyok', 'mockupok', 'imei', 'tac', 'asszisztens'] as $ful) {
        T::igaz(!isset($sz[$ful]), 'a Beállításokban nincs „' . $ful . '” lapfül');
    }
    T::igaz(isset($sz['szamlazas'], $sz['penztar'], $sz['csapat'], $sz['arculat']), 'a többi lapfül megvan');

    // Az őr.
    $r = $or('sdh_muhely_naptar_adat');
    T::tartalmaz($r, '"success":false', 'őr: a kikapcsolt Naptár végpontja elutasít');
    T::tartalmaz($r, 'ki van kapcsolva', 'őr: az üzenet megmondja, mi a baj');
    $r = $or('sdh_muhely_naptar_urlap');
    T::tartalmaz($r, 'Kikapcsolt modul', 'őr: az űrlap-végpont HTML-üzenetet ad a popupba');
    T::egyezik('', $or('sdh_muhely_munkalapok_ment'), 'őr: az alaprendszert nem állítja meg');
    T::egyezik('', $or('sdh_muhely_csempe_szamok'), 'őr: a közös végpontot nem állítja meg');
    T::egyezik('', $or('masik_plugin_akcio'), 'őr: más bővítmény műveletéhez nem nyúl');

    // Szolgáltatások: SMS, nyomtatás, mockup, IMEI, TAC, asszisztens.
    update_option(SDH_Muhely_Sms::OPT, array_merge((array) get_option(SDH_Muhely_Sms::OPT, []), ['be' => 1]));
    T::igaz(!SDH_Muhely_Sms::bekapcsolva(), 'SMS: a modul kikapcsolása felülírja a saját főkapcsolót');
    T::egyezik(0, (int) SDH_Muhely_Sms::feldolgoz()['kuldve'], 'SMS: a sor nem fut');
    T::egyezik('', SDH_Muhely_Nyomtatas::gomb(1), 'nyomtatás: nincs gomb');
    T::igaz(!SDH_Muhely_Nyomtatas::js_adat()['ajanlat'], 'nyomtatás: nincs felajánlás');
    T::egyezik('', SDH_Muhely_Mockup::html((object) ['kategoria' => 'telefon', 'gyarto' => 'Apple', 'tipus' => 'iPhone 13']), 'mockup: nincs kép');
    T::egyezik([], SDH_Muhely_Imei_Lekerdezes::ellenorzok(), 'IMEI: nincsenek ellenőrző oldalak');
    T::igaz(!SDH_Muhely_Imei_Lekerdezes::beallitva(), 'IMEI: nincs lekérdezés');
    T::igaz(SDH_Muhely_Tac::keres('35328711') === null, 'TAC: nincs felismerés');
    T::igaz(!SDH_Muhely_Asszisztens::beallitas()['be'], 'asszisztens: kikapcsolva');
    T::igaz(apply_filters('sdh_muhely_pulzus', [], get_current_user_id()) === [] || !isset(apply_filters('sdh_muhely_pulzus', [], get_current_user_id())['naptar']), 'pulzus: nincs naptár-adat');

    // Az eszköz űrlapján nincs IMEI-ellenőrzés.
    $u  = t_ugyfel('Modul Teszt');
    $e1 = t_eszkoz($u, 'Apple', 'iPhone 13');
    $eu = t_ajax('sdh_muhely_eszkozok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $e1])['nyers'];
    T::nem_tartalmaz($eu, 'data-sdh-beillesztes-feldolgoz', 'eszköz: nincs Gyári adatok beillesztés');
    T::tartalmaz($eu, 'name="zarkod"', 'eszköz: az űrlap többi része megvan');

    // Súgó: a kikapcsolt modul fejezete eltűnik, az asszisztens eszközei közül is.
    T::igaz(SDH_Muhely_Sugo::fejezet('naptar') === null, 'súgó: nincs Naptár fejezet');
    T::igaz(SDH_Muhely_Sugo::fejezet('sms') === null, 'súgó: nincs SMS fejezet');
    T::igaz(SDH_Muhely_Sugo::fejezet('munkalapok') !== null && SDH_Muhely_Sugo::fejezet('modulok') !== null, 'súgó: a többi (és a Modulok) megvan');
    T::igaz(!SDH_Muhely_Asszisztens_Eszkozok::elerheto('naptar_esemenyek'), 'AI: a naptár eszköze nem érhető el');
    T::nem_tartalmaz(SDH_Muhely_Asszisztens_Eszkozok::olvas('naptar_esemenyek', []), '"esemenyek"', 'AI: a naptár eszköze nem fut');
    T::igaz(SDH_Muhely_Asszisztens_Eszkozok::elerheto('kereses'), 'AI: a keresés megmarad');

    /* --- Függőség ------------------------------------------------------- */

    $e = $ment(['uzenetek']);
    T::egyezik(['rendelesek', 'sms'], (static function (array $a): array { sort($a); return $a; })($e['plusz']), 'függőség: az RMA-val a Rendelések és az SMS is kikapcsol');
    T::tartalmaz($e['uzenet'], 'Ezek is kikapcsoltak', 'függőség: az üzenet megmondja');
    T::igaz($Mo::ki('sms') && $Mo::ki('rendelesek'), 'függőség: nem futnak');

    // Az oldal: ha valaki az SMS-t bekapcsolná, de az RMA ki van kapcsolva, nem fut.
    update_option($Mo::OPT_KI, ['uzenetek']);
    $Mo::kapcsolok_urit();
    T::igaz($Mo::ki('sms'), 'függőség: a bekapcsolt SMS sem fut kikapcsolt RMA mellett');
    ob_start();
    $K::oldal();
    $oldal = (string) ob_get_clean();
    T::tartalmaz($oldal, 'Nem fut – ki van kapcsolva, amire épül', 'a kártya jelzi, hogy nem fut');
    T::tartalmaz($K::ki_szoveg('sms'), 'amire épül', 'a végpont üzenete is a függőséget nevezi meg');

    // RMA nélkül: a munkalap RMA lapfüléből „Címke” lesz, QR-kód és csevegő nélkül; az ügyféloldal 404.
    $id = t_munkalap($u, [$e1]);
    $lap = t_lap($id);
    $ml = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
    T::igaz(preg_match('~<label for="ful_m_rma" data-sdh-rma-ful(?: data-sdh-ub="ful_rma")?>Címke~', $ml) === 1, 'RMA ki: a lapfül neve „Címke”');
    T::nem_tartalmaz($ml, 'data-sdh-chat ', 'RMA ki: nincs csevegő');
    T::nem_tartalmaz($ml, 'data-sdh-rma-masol', 'RMA ki: nincs ügyféloldal-link');

    if ((int) $lap->munkalap_szam > 0) {
        T::tartalmaz($ml, 'Címke nyomtatása', 'RMA ki: a címke nyomtatható');
        $cimke = SDH_Muhely_Rma::cimke_blokk($lap, null, null);
        T::nem_tartalmaz($cimke, 'sdh-cimke__qr', 'RMA ki: a címkén nincs QR-kód');
        T::tartalmaz($cimke, 'sdh-cimke__vk', 'RMA ki: a vonalkód megvan');
    }

    $r = $or('sdh_muhely_uzenet_kuld');
    T::tartalmaz($r, '"success":false', 'RMA ki: üzenetet sem lehet küldeni');

    $ment([]);
    $ml = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
    T::igaz(preg_match('~<label for="ful_m_rma" data-sdh-rma-ful(?: data-sdh-ub="ful_rma")?>RMA~', $ml) === 1, 'RMA be: a lapfül neve újra „RMA”');
    T::tartalmaz($ml, 'data-sdh-chat ', 'RMA be: a csevegő visszajött');

    /* --- Pénztár, Számlázz.hu, Termékek, árukiadás/rendelés, Csapat ------ */

    $ment(['penztar', 'szamlazas', 'termekek', 'arukiadas', 'rendelesek', 'csapat']);

    // Pénztár ki: a Fizetés ablak megmarad, a pipa nincs, a pénztárba nem kerül semmi.
    t_tetel($id, 'Akkucsere', 15000);
    SDH_Muhely_Tetel::ujraszamol($id);
    T::egyezik('', $or('sdh_muhely_penztar_fizet'), 'pénztár ki: a Fizetés átmegy az őrön');
    T::tartalmaz($or('sdh_muhely_penztar_adat'), '"success":false', 'pénztár ki: a pénztár adatai nem');
    $fu = t_ajax('sdh_muhely_penztarfizetes_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
    T::tartalmaz($fu, 'Fizetés rögzítése', 'pénztár ki: a Fizetés ablak megvan');
    T::nem_tartalmaz($fu, 'name="kasszaba"', 'pénztár ki: nincs „Kerüljön be a házipénztárba” pipa');

    global $wpdb;
    $r = t_ajax('sdh_muhely_penztar_fizet', ['munkalap_id' => $id, 'mod' => 'kp', 'fajta' => 'teljes', 'osszeg' => '15000', 'kasszaba' => '1']);
    T::igaz(!empty($r['success']), 'pénztár ki: a fizetés rögzül');
    T::igaz((int) t_lap($id)->fizetve === 1, 'pénztár ki: a lap fizetve');
    $bev = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Muhely_Penztar::tabla() . " WHERE munkalap_id = %d AND tipus = 'bevetel'", $id));
    T::egyezik(0, $bev, 'pénztár ki: nem került bevétel a pénztárba (a pipát hiába küldték)');
    T::nem_tartalmaz((string) ($r['data']['uzenet'] ?? ''), 'a pénztárba is bekerült', 'pénztár ki: az üzenet sem állítja');

    // Számlázz.hu ki: a helyi előkészítő marad.
    if ((int) t_lap($id)->munkalap_szam > 0) {
        ob_start();
        SDH_Muhely_Szamla::munkalap_gombok(t_lap($id));
        $gombok = (string) ob_get_clean();
        T::nem_tartalmaz($gombok, 'Számlázz.hu számla', 'Számlázz.hu ki: nincs a gomb');
        T::tartalmaz($gombok, 'data-sdh-szamla="helyi"', 'Számlázz.hu ki: a helyi előkészítő gombja megvan');
        T::tartalmaz(t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id, 'sorozat' => 'fo'])['nyers'], 'ki van kapcsolva', 'Számlázz.hu ki: az ablak sem nyílik');
        T::nem_tartalmaz(t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id, 'sorozat' => 'helyi'])['nyers'], 'ki van kapcsolva', 'Számlázz.hu ki: a helyi ablak nyílik');
    }
    T::tartalmaz($or('sdh_muhely_szamla_ment'), '"success":false', 'Számlázz.hu ki: kiállítás nincs');

    // Termékek ki: a munkalapon nincs Termékek lapfül (a lapon nincs termék).
    $ml = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
    T::igaz(preg_match('~<label for="ful_m_termek" hidden[ >]~', $ml) === 1, 'termékek ki: a Termékek lapfül rejtve');
    T::tartalmaz($ml, 'data-sdh-penztar-fizetes', 'a Fizetés gomb a munkalapon megmarad');

    // Árukiadás + rendelés ki: a közös Beállítások-lapfül is eltűnik; a rácsnézetek között sincs árukiadás.
    $sz = SDH_Muhely_Beallitasok::szakaszok();
    T::igaz(!isset($sz['eladas']) && !isset($sz['penztar']), 'a Beállításokban nincs Árukiadás és rendelések, sem Házipénztár lapfül');
    $att = (static function (): string { ob_start(); SDH_Muhely_Admin_UI::attekintes_tartalom(false); return (string) ob_get_clean(); })();
    T::nem_tartalmaz($att, 'sdh-urlap="arukiadas"', 'az Áttekintésen nincs Napi árukiadás gomb');
    T::nem_tartalmaz($att, 'sdh-urlap="termekek"', 'az Áttekintésen nincs Új termék gomb');

    // Csapat ki: menüpont nincs, a kollégák kezelése megvan (adminisztrátornak).
    $cs = $Mo::egy('csapat');
    T::igaz($cs !== null && !empty($cs['rejtett']), 'csapat ki: az oldal megvan, menüpont nélkül');
    T::nem_tartalmaz($keret(), 'muhely=csapat"', 'csapat ki: nincs a menüben');
    $_GET = [];
    ob_start();
    SDH_Muhely_Csapat::oldal();
    $cso = (string) ob_get_clean();
    T::nem_tartalmaz($cso, 'Beszélgetések', 'csapat ki: nincs csevegés');
    T::tartalmaz($cso, 'Kollégák', 'csapat ki: a kollégák kezelése megvan');
    T::egyezik('', $or('sdh_muhely_csapatkollega_urlap'), 'csapat ki: a kolléga-űrlap átmegy az őrön');
    T::tartalmaz($or('sdh_muhely_csapat_kuld'), '"success":false', 'csapat ki: üzenetet küldeni nem lehet');

    // admin-post őr.
    T::tartalmaz($or('sdh_muhely_termek_mentes', false), 'WPDIE:', 'admin-post: a kikapcsolt Termékek mentése megáll');

    /* --- Telepítői zár -------------------------------------------------- */

    $ment([]);
    T::igaz(!$K::zar_feldolgoz('', 'rovid', 'rovid', false)['ok'], 'zár: a túl rövid kód nem jó');
    T::igaz(!$K::zar_feldolgoz('', 'titok123', 'titok124', false)['ok'], 'zár: a két kód nem egyezik');
    T::igaz($K::zar_feldolgoz('', 'titok123', 'titok123', false)['ok'], 'zár: beállítva');
    T::igaz($K::zarolva(), 'zár: zárolva');
    T::igaz(strpos((string) get_option($K::OPT_ZAR), 'titok123') === false, 'zár: a kód nem olvasható formában tárolódik');

    $e = $ment(['naptar'], 'rossz');
    T::igaz(!$e['ok'] && $Mo::be('naptar'), 'zár: rossz kóddal nem változik semmi');
    $e = $ment(['naptar'], 'titok123');
    T::igaz($e['ok'] && $Mo::ki('naptar'), 'zár: jó kóddal átkapcsol');

    ob_start();
    $K::oldal();
    T::tartalmaz((string) ob_get_clean(), 'name="kod"', 'zár: az oldalon kódmező');

    T::igaz(!$K::zar_feldolgoz('rossz', '', '', true)['ok'], 'zár: feloldás rossz kóddal nem megy');
    T::igaz($K::zar_feldolgoz('titok123', '', '', true)['ok'] && !$K::zarolva(), 'zár: feloldva');
} finally {
    // Minden vissza: a többi teszt bekapcsolt modulokkal fut.
    delete_option(SDH_Muhely_Modul_Kapcsolo::OPT_ZAR);
    delete_option(SDH_Muhely_Modulok::OPT_KI);

    if ($sms_regi === null) {
        delete_option(SDH_Muhely_Sms::OPT);
    } else {
        update_option(SDH_Muhely_Sms::OPT, $sms_regi);
    }
    SDH_Muhely_Modulok::kapcsolok_urit();
    SDH_Muhely_Sugo::urit();
    $_GET = [];
}

T::igaz(SDH_Muhely_Modulok::kikapcsoltak() === [], 'a teszt után minden modul újra be van kapcsolva');

T::vege('t47 modulok: ki- és bekapcsolás, őr, függőség, különleges esetek, telepítői zár');
