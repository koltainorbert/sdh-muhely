<?php
/**
 * t76 – F2 (0.84.0): online árajánlat-elfogadás.
 *
 * - Küldés a munkalapról (összeg nélkül nem), üzenet + e-mail az ügyfélnek, napló.
 * - Az ügyféloldal doboza (függő / megválaszolt), a válasz POST-ja (jel, előnézet), egyszer.
 * - Elfogadás / elutasítás: a lapon, olvasatlan üzenet + e-mail a szerviznek, napló; nem ütközés.
 * - Visszavonás, újraküldés, változott összeg, zárolt lap.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$A     = 'SDH_Muhely_Ajanlat';
$T     = static fn (string $t): string => SDH_Muhely_Schema::tabla($t);
$admin = get_current_user_id();

T::igaz(version_compare(SDH_Muhely_Schema::DB_VERSION, '0.47.0', '>='), 'sémaverzió 0.47.0');
foreach (['ajanlat_allapot', 'ajanlat_osszeg', 'ajanlat_kuldve', 'ajanlat_valasz', 'ajanlat_megjegyzes'] as $o) {
    T::igaz(t_oszlop($T('munkalap'), $o), 'új oszlop: ' . $o);
}

$level = [];
add_filter('pre_wp_mail', static function ($null, $atts) use (&$level) {
    $level[] = $atts;

    return true;
}, 10, 2);

$rma_b = get_option('sdh_muhely_rma', []);
update_option('sdh_muhely_rma', array_merge(is_array($rma_b) ? $rma_b : [], ['ertesites_email' => 'szerviz-t76@example.com']));

$email = 't76-' . wp_rand(1000, 9999) . '@example.com';
$u  = t_ugyfel('Ajánlat T76', ['email' => $email]);
$e  = t_eszkoz($u, 'Samsung', 'T76');
$ml = t_munkalap($u, [$e]);

/* --- Küldés -------------------------------------------------------------------------------------------- */

$wpdb->update($T('munkalap'), ['brutto_ertek' => 0], ['id' => $ml]);
T::tartalmaz($A::kuld($ml), 'nincs összeg', 'összeg nélkül nem küldhető');

$wpdb->update($T('munkalap'), ['brutto_ertek' => 15000], ['id' => $ml]);
$level = [];
T::egyezik('', $A::kuld($ml, $admin), 'elküldve');
$l = $A::lap($ml);
T::egyezik('var', (string) $l->ajanlat_allapot, 'állapot: válaszra vár');
T::egyezik(15000.0, (float) $l->ajanlat_osszeg, 'a küldött összeg');
T::igaz(!empty($l->ajanlat_kuldve), 'küldés ideje');
T::igaz((string) $l->rma_token !== '', 'az ügyféloldal linkje megvan');
$ki = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('uzenet') . " WHERE munkalap_id = %d AND irany = 'ki' ORDER BY id DESC LIMIT 1", $ml));
T::tartalmaz((string) ($ki->szoveg ?? ''), 'Árajánlatot küldtünk: 15 000 Ft', 'kimenő üzenet a csevegőbe');
T::igaz(count(array_filter($level, static fn ($a) => ($a['to'] ?? '') === $email)) === 1, 'e-mail az ügyfélnek');
T::tartalmaz((string) ($level[0]['message'] ?? ''), (string) SDH_Muhely_Rma::url((string) $A::lap($ml)->rma_token), 'a levélben az ügyféloldal linkje');

$blokk = $A::urlap_blokk($A::lap($ml));
T::tartalmaz($blokk, 'Válaszra vár', 'munkalap: válaszra vár');
T::tartalmaz($blokk, 'data-sdh-ajanlat-akcio="visszavon"', 'munkalap: visszavonható');
$wpdb->update($T('munkalap'), ['brutto_ertek' => 18000], ['id' => $ml]);
T::tartalmaz($A::urlap_blokk($A::lap($ml)), 'azóta változott', 'változott összeg: figyelmeztet');
$wpdb->update($T('munkalap'), ['brutto_ertek' => 15000], ['id' => $ml]);

/* --- Ügyféloldal --------------------------------------------------------------------------------------- */

$doboz = $A::rma_doboz($A::lap($ml));
T::tartalmaz($doboz, 'name="sdh_rma_muvelet" value="ajanlat"', 'ügyféloldal: az ajánlat űrlapja');
T::tartalmaz($doboz, 'value="elfogad"', 'Elfogadom gomb');
T::tartalmaz($doboz, 'value="elutasit"', 'Nem kérem gomb');
T::tartalmaz($doboz, 'name="jel"', 'védő jel');
T::igaz(preg_match('/15\s000/u', $doboz) === 1, 'az összeg');
T::nem_tartalmaz($doboz, 'name="elonezet"', 'nem előnézetben nincs előnézet-jel');

// A válasz POST-ja (a valódi útvonal: Rma::post_kezeles), munkatársi előnézetből.
$atir = static fn () => false;
add_filter('wp_redirect', $atir);
$post = new ReflectionMethod('SDH_Muhely_Rma', 'post_kezeles');
$post->setAccessible(true);
$ugyfel = SDH_Muhely_Rma::ugyfel($u);

$_POST = ['sdh_rma_muvelet' => 'ajanlat', 'valasz' => 'elfogad', 'jel' => 'rossz', 'elonezet' => '1'];
$post->invoke(null, 'ajanlat', $A::lap($ml), $ugyfel);
T::egyezik('var', (string) $A::lap($ml)->ajanlat_allapot, 'rossz jel: nem változik');

$utk_alap = SDH_Muhely_Utkozes::alap();
$level    = [];
$_POST = ['sdh_rma_muvelet' => 'ajanlat', 'valasz' => 'elfogad', 'jel' => SDH_Muhely_Rma::urlap_jel($A::lap($ml)), 'elonezet' => '1', 'megjegyzes' => 'Mehet, köszönöm!'];
$post->invoke(null, 'ajanlat', $A::lap($ml), $ugyfel);
$_POST = [];
remove_filter('wp_redirect', $atir);

$l = $A::lap($ml);
T::egyezik('elfogadva', (string) $l->ajanlat_allapot, 'elfogadva');
T::egyezik('Mehet, köszönöm!', (string) $l->ajanlat_megjegyzes, 'a megjegyzés');
T::igaz(!empty($l->ajanlat_valasz), 'a válasz ideje');
$be = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('uzenet') . " WHERE munkalap_id = %d AND irany = 'be' ORDER BY id DESC LIMIT 1", $ml));
T::tartalmaz((string) ($be->szoveg ?? ''), 'ELFOGADOM', 'bejövő üzenet a csevegőbe');
T::egyezik(0, (int) ($be->olvasva ?? 1), 'olvasatlan (jelvény a menüben)');
T::igaz(count(array_filter($level, static fn ($a) => ($a['to'] ?? '') === 'szerviz-t76@example.com')) === 1, 'e-mail a szerviznek');
T::egyezik([], SDH_Muhely_Utkozes::masok_modositasai('munkalap', $ml, $utk_alap, $admin), 'az ügyfél válasza nem ütközik a munkatárs szerkesztésével');
$naplo = (string) $wpdb->get_var($wpdb->prepare('SELECT osszegzes FROM ' . SDH_Muhely_Valtozasnaplo::tabla() . " WHERE fo_tipus = 'munkalap' AND fo_id = %d AND osszegzes LIKE %s ORDER BY id DESC LIMIT 1", $ml, '%elfogadva%'));
T::tartalmaz($naplo, 'Árajánlat elfogadva', 'a Változásnaplóban');

T::egyezik(false, $A::valasz($ml, false), 'megválaszolt ajánlatra nem lehet újra válaszolni');
T::tartalmaz($A::rma_doboz($A::lap($ml)), 'Ön elfogadta az ajánlatot', 'ügyféloldal: a válasz látszik');
T::nem_tartalmaz($A::rma_doboz($A::lap($ml)), '<form', 'és nincs több gomb');
T::tartalmaz($A::urlap_blokk($A::lap($ml)), 'Elfogadta', 'munkalap: Elfogadta');
T::tartalmaz($A::urlap_blokk($A::lap($ml)), 'Mehet, köszönöm!', 'munkalap: a megjegyzése');

/* --- Elutasítás, visszavonás ---------------------------------------------------------------------------- */

T::egyezik('', $A::kuld($ml), 'új ajánlat küldhető');
T::egyezik(true, $A::valasz($ml, false, ''), 'elutasítva');
T::egyezik('elutasitva', (string) $A::lap($ml)->ajanlat_allapot, 'állapot: elutasítva');
T::tartalmaz((string) $wpdb->get_var($wpdb->prepare('SELECT szoveg FROM ' . $T('uzenet') . " WHERE munkalap_id = %d AND irany = 'be' ORDER BY id DESC LIMIT 1", $ml)), 'NEM KÉREM', 'elutasító üzenet');

T::egyezik('Nincs függő ajánlat.', $A::visszavon($ml), 'megválaszoltat nem lehet visszavonni');
$A::kuld($ml);
T::egyezik('', $A::visszavon($ml), 'függőt vissza lehet vonni');
T::egyezik('', $A::rma_doboz($A::lap($ml)), 'visszavonva az ügyféloldalon nincs doboz');

/* --- AJAX, zárolt lap, felület ---------------------------------------------------------------------------- */

$v = t_ajax('sdh_muhely_ajanlat_kuld', ['id' => $ml]);
T::igaz(!empty($v['success']) && strpos((string) $v['data']['html'], 'Válaszra vár') !== false, 'AJAX: küldés, a frissített csoport');
$v = t_ajax('sdh_muhely_ajanlat_visszavon', ['id' => $ml]);
T::igaz(!empty($v['success']), 'AJAX: visszavonás');

$wpdb->update($T('munkalap'), ['allapot' => SDH_Muhely_Munkalap::lezart_kulcs(), 'lezarva' => current_time('Y-m-d')], ['id' => $ml]);
$v = t_ajax('sdh_muhely_ajanlat_kuld', ['id' => $ml]);
T::igaz(empty($v['success']), 'zárolt (lezárt) lapról nem küldhető');

$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ml])['nyers'];
T::tartalmaz($urlap, 'data-sdh-ajanlat="' . $ml . '"', 'a munkalap popupjában az Online árajánlat csoport');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.js'), "'sdh_muhely_ajanlat_'", 'a böngésző oldala (app.js)');

SDH_Muhely_Rma_Oldal::nyelv_kenyszerit('en');
T::egyezik('I accept', SDH_Muhely_Rma_Oldal::t('ajanlat_elfogad'), 'angolul is');
SDH_Muhely_Rma_Oldal::nyelv_kenyszerit('hu');

// Ki- és bekapcsolás (Beállítások › Online árajánlat).
T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['ajanlat']), 'Beállítások › Online árajánlat lapfül');
$_POST = ['ajanlat' => ['be_jelen' => '1']];
SDH_Muhely_Ajanlat::beallitas_mentes();
$_POST = [];
T::igaz(!SDH_Muhely_Ajanlat::elerheto(), 'kikapcsolva: nem elérhető');
T::egyezik('', SDH_Muhely_Ajanlat::urlap_blokk(SDH_Muhely_Ajanlat::lap($ml)), 'kikapcsolva: nincs csoport a munkalapon');
delete_option(SDH_Muhely_Ajanlat::OPT);
T::igaz(SDH_Muhely_Ajanlat::elerheto(), 'alapból bekapcsolva');

update_option('sdh_muhely_rma', $rma_b);

T::vege('t76 online árajánlat (F2)');
