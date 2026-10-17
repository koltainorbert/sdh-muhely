<?php
/**
 * t69 – 0.75.0: szerepkörök és jogok (H5).
 *
 * - Alapállapot: admin / kolléga (a régi teljes hozzáférés) / pultos / szerelő / pénztáros.
 * - A WordPress-adminisztrátor mindig admin; a szerep nélküli kolléga és dolgozó „kolléga".
 * - Mentés: szerepek, jogok, tiltott modulok, új szerep, törlés, személyek szerepe.
 * - Őr: a tiltott modul és a hiányzó jog végpontja elutasít (AJAX, űrlap-popup, admin-post).
 * - Láthatóság: a tiltott modulnak nincs menüpontja / oldala; body-osztályok.
 * - Közös adminisztrátori fiók + kiválasztott dolgozó: az ő szerepe számít (Beállítások sem).
 */

require __DIR__ . '/_keret.php';

class T69_Die extends Exception
{
}

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/user.php';

$Sz    = 'SDH_Muhely_Szerepkor';
$admin = get_current_user_id();

$felh = static function (string $nev, string $szerep): int {
    $id = wp_insert_user(['user_login' => $nev . '_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => $szerep, 'display_name' => $nev]);

    return is_int($id) ? $id : 0;
};

$mentes = static function (array $post) use ($Sz): void {
    $_POST = wp_slash($post + ['szerep_jelen' => '1']);
    $Sz::mentes();
    $_POST = [];
    $Sz::felejt();
    SDH_Muhely_Szemely::felejt();
};

/** Az őr egy hívásra: a kimenet (üres = átengedte). */
$or = static function (string $akcio, bool $ajax = true): string {
    t_ajax('sdh_muhely_pulzus_nincs'); // a t_ajax szűrői (wp_doing_ajax, wp_die)

    $regi     = $_REQUEST;
    $_REQUEST = ['action' => $akcio];
    $dobo     = null;

    if (!$ajax) {
        remove_all_filters('wp_doing_ajax');
        add_filter('wp_die_handler', $dobo = static fn () => static function ($uzenet): void {
            throw new T69_Die(is_string($uzenet) ? $uzenet : 'wp_die');
        });
    }

    ob_start();

    try {
        SDH_Muhely_Szerepkor::orszem();
    } catch (T_Ajax_Vege $v) {
        // elutasított
    } catch (T69_Die $v) {
        echo 'WPDIE:' . $v->getMessage();
    }

    $ki       = (string) ob_get_clean();
    $_REQUEST = $regi;
    $json     = json_decode($ki, true);

    // JSON-hiba: „üzenet|hiányzó jog” (a JSON ékezetei escape-eltek).
    if (is_array($json)) {
        $ki = (string) ($json['data']['uzenet'] ?? '') . '|' . (string) ($json['data']['jog_hiany'] ?? '');
    }

    if (!$ajax) {
        add_filter('wp_doing_ajax', '__return_true');
        remove_filter('wp_die_handler', $dobo);
    }

    return $ki;
};

// Egy korábbi, félbeszakadt futás maradványai.
foreach (get_users(['search' => '*T69*', 'search_columns' => ['display_name']]) as $maradek) {
    wp_delete_user((int) $maradek->ID);
}

delete_option($Sz::OPT);
delete_option(SDH_Muhely_Modulok::OPT_KI);
SDH_Muhely_Modulok::kapcsolok_urit();
$Sz::felejt();

/* --- 1. Alapok ------------------------------------------------------------------- */

$szerepek = $Sz::szerepek();
T::egyezik(['admin', 'kollega', 'pultos', 'szerelo', 'penztaros'], array_keys($szerepek), 'az alap szerepek: admin, kolléga, pultos, szerelő, pénztáros');
T::egyezik(true, $szerepek['admin']['zarolt'], 'az admin szerep zárolt');
T::egyezik(count($Sz::jogok()), count($szerepek['admin']['jogok']), 'az adminnak minden jog megvan');
T::egyezik([], $szerepek['kollega']['tiltott'], 'a kolléga minden modult lát (a régi állapot)');
T::egyezik(false, in_array('beallitasok', $szerepek['kollega']['jogok'], true), 'a kollégának nincs beállítás-joga');
T::egyezik(true, in_array('szamla', $szerepek['kollega']['jogok'], true) && in_array('torles', $szerepek['kollega']['jogok'], true), 'a kolléga a régi jogokat megtartja (számla, törlés)');

wp_set_current_user($admin);
T::egyezik('admin', $Sz::szerep_slug($admin), 'a WordPress-adminisztrátor admin');
T::egyezik(true, SDH_Muhely_Admin_UI::admin_e(), 'az admin_e() igaz az adminnak');
T::egyezik(true, $Sz::megvan('szamla') && $Sz::megvan('penztar_riport') && $Sz::modul_engedett('penztar'), 'az admin mindent használhat');
T::egyezik(false, strpos($Sz::body_osztalyok(), 'sdh-nem-') !== false, 'az adminnál nincs sdh-nem-* osztály');

$kolléga = $felh('Kolléga T69', 'sdh_kollega');
T::igaz($kolléga > 0, 'a kolléga-fiók létrejött');
T::egyezik('kollega', $Sz::szerep_slug($kolléga), 'a szerep nélküli kolléga „kolléga"');
wp_set_current_user($kolléga);
T::egyezik(false, SDH_Muhely_Admin_UI::admin_e(), 'a kolléga nem admin');
T::egyezik('', $or('sdh_muhely_szamla_ment'), 'a régi kolléga számlát állíthat ki (az őr átengedi)');
T::egyezik('', $or('sdh_muhely_penztar_adat'), 'a régi kolléga látja a pénztárat');

/* --- 2. Mentés: szerepek, jogok, személyek ----------------------------------------- */

wp_set_current_user($admin);
$dolgozo_id = SDH_Muhely_Szemely::uj_dolgozo('Dolgozó T69', '1234');
T::igaz($dolgozo_id > 0, 'a dolgozó létrejött');

$modulok = array_keys(SDH_Muhely_Modulok::katalogus());
$mind_kiveve = static fn (array $ki): array => array_values(array_diff($modulok, $ki));

$mentes([
    'szerep' => [
        'kollega'   => ['nev' => 'Kolléga', 'jogok' => ['penztar_riport', 'ar_atiras', 'szamla', 'torles'], 'modulok' => $modulok],
        'pultos'    => ['nev' => 'Pultos', 'jogok' => [], 'modulok' => $mind_kiveve(['penztar', 'szamlazas'])],
        'szerelo'   => ['nev' => 'Szerelő', 'jogok' => [], 'modulok' => $mind_kiveve(['penztar', 'szamlazas', 'arukiadas'])],
        'penztaros' => ['nev' => 'Pénztáros', 'jogok' => ['penztar_riport', 'szamla'], 'modulok' => $modulok],
    ],
    'szerep_uj'      => 'Ügyvezető T69',
    'szemely_szerep' => [(string) $kolléga => 'pultos', (string) $dolgozo_id => 'penztaros'],
]);

$szerepek = $Sz::szerepek();
T::igaz(isset($szerepek['ugyvezeto-t69']), 'az új szerep létrejött (azonosító a névből)');
T::egyezik($szerepek['kollega']['jogok'], $szerepek['ugyvezeto-t69']['jogok'], 'az új szerep a Kolléga jogaival indul');
T::egyezik('pultos', $Sz::szerep_slug($kolléga), 'a kolléga szerepe: pultos');
T::egyezik('penztaros', $Sz::szerep_slug($dolgozo_id), 'a dolgozó szerepe: pénztáros');
T::egyezik(['penztar', 'szamlazas'], array_values(array_intersect(['penztar', 'szamlazas'], $szerepek['pultos']['tiltott'])), 'a pultos nem látja a pénztárat és a számlázást');
T::egyezik(false, $Sz::szerep_slug($admin) !== 'admin', 'az admin szerepe nem állítható át');

$mentes(['szemely_szerep' => [(string) $admin => 'pultos']]);
T::egyezik('admin', $Sz::szerep_slug($admin), 'adminisztrátor fiókra a szerep-hozzárendelés hatástalan (nem zárhatja ki magát)');

/* --- 3. Őr: a pultos ------------------------------------------------------------------ */

wp_set_current_user($kolléga);
$Sz::felejt();
SDH_Muhely_Szemely::felejt();

T::egyezik(false, SDH_Muhely_Admin_UI::admin_e(), 'a pultos nem admin');
T::tartalmaz($or('sdh_muhely_penztar_riport'), 'nem elérhető', 'pénztárriport: a pultosnak a modul sincs');
T::tartalmaz($or('sdh_muhely_penztar_statisztika'), 'nem elérhető', 'pénztár-statisztika: elutasít');
T::tartalmaz($or('sdh_muhely_szamla_ment'), 'nem elérhető', 'számla kiállítása: a pultosnak a számlázás modul sincs');
T::tartalmaz($or('sdh_muhely_termek_torol'), 'Törlés-szerű', 'termék törlése: elutasít');
T::tartalmaz($or('sdh_muhely_penztar_torol'), 'nem elérhető', 'pénztártétel törlése: elutasít');
T::tartalmaz($or('sdh_muhely_munkalapok_ujranyit'), 'Törlés-szerű', 'lezárt munkalap újranyitása: elutasít');
T::tartalmaz($or('sdh_muhely_termekek_ment'), 'Árak és törzsek', 'termék-törzs mentése: elutasít');
T::tartalmaz($or('sdh_muhely_szolgaltatasok_ment'), 'Árak és törzsek', 'szolgáltatás-törzs mentése: elutasít');
T::tartalmaz($or('sdh_muhely_termek_mentes', false), 'WPDIE:', 'termék mentése űrlapról (admin-post): elutasít');
T::tartalmaz($or('sdh_muhely_penztar_adat'), 'nem elérhető', 'tiltott modul (Házipénztár): a végpont elutasít');
T::tartalmaz($or('sdh_muhely_penztar_urlap'), 'sdh-modal__cim', 'tiltott modul űrlapja: a popup mondja el');
T::tartalmaz($or('sdh_muhely_penztar_urlap'), 'nem elérhető', 'tiltott modul űrlapja: szöveg');
T::egyezik('', $or('sdh_muhely_penztar_fizet'), 'a munkalap Fizetés ablaka a pénztár modul nélkül is megy (kivétel)');
T::egyezik('', $or('sdh_muhely_munkalapok_ment'), 'a munkalap mentése megy');
T::egyezik('', $or('sdh_muhely_ugyfelek_ment'), 'az ügyfél mentése megy');
T::egyezik('', $or('valami_mas_plugin_akcio'), 'idegen művelethez az őr nem nyúl');

T::tartalmaz($or('sdh_muhely_termek_torol'), '|torles', 'a válasz megmondja, melyik jog hiányzik');

// H5b (0.95.0): „Tételár átírása a lapon” – a pultosnak nincs.
$ml69 = t_munkalap(t_ugyfel('Ár T69'), [t_eszkoz(t_ugyfel('Ár2 T69'), 'Apple', 'T69')]);
$wpdb->insert(SDH_Muhely_Tetel::tabla(), ['munkalap_id' => $ml69, 'tipus' => 'szolgaltatas', 'mozgas' => 'kimeno', 'megnevezes' => 'Csere T69', 'mennyiseg' => 1, 'brutto_ar' => 10000, 'brutto_ertek' => 10000, 'kedvezmeny' => 0]);
$t69 = (int) $wpdb->insert_id;
$sor69 = static fn (array $x): array => $x + ['id' => $t69, 'tipus' => 'szolgaltatas', 'megnevezes' => 'Csere T69', 'termek_id' => 0, 'brutto_ar' => 10000.0, 'kedvezmeny' => 0.0];
T::egyezik(false, $Sz::megvan('tetel_ar'), 'a pultosnak nincs tételár-joga');
T::egyezik('', SDH_Muhely_Tetel::ar_jog_hiba($ml69, [$sor69([])]), 'változatlan ár: mehet');
T::tartalmaz(SDH_Muhely_Tetel::ar_jog_hiba($ml69, [$sor69(['brutto_ar' => 8000.0])]), 'Tételár átírása', 'átírt ár: elutasítva');
T::tartalmaz(SDH_Muhely_Tetel::ar_jog_hiba($ml69, [$sor69(['kedvezmeny' => 10.0])]), 'Csere T69', 'kedvezmény: elutasítva, a tétel nevével');
$wpdb->insert(SDH_Muhely_Schema::tabla('szolgaltatas'), ['nev' => 'Törzs T69 ' . $ml69, 'brutto_ar' => 5000, 'ar_max' => 7000]);
$uj69 = static fn (float $ar): array => ['id' => 0, 'tipus' => 'szolgaltatas', 'megnevezes' => 'Törzs T69 ' . $ml69, 'termek_id' => 0, 'brutto_ar' => $ar, 'kedvezmeny' => 0.0];
T::egyezik('', SDH_Muhely_Tetel::ar_jog_hiba($ml69, [$uj69(5000.0)]), 'új tétel törzsáron: mehet');
T::egyezik('', SDH_Muhely_Tetel::ar_jog_hiba($ml69, [$uj69(6500.0)]), 'ársávon (tól–ig) belül: mehet');
T::tartalmaz(SDH_Muhely_Tetel::ar_jog_hiba($ml69, [$uj69(9000.0)]), 'Tételár', 'ársávon kívül: elutasítva');
$v69 = t_ajax('sdh_muhely_munkalapok_ment', ['id' => $ml69, 'tetelek_jelen' => '1', 'tetelek' => ['szolgaltatas' => ['a' => ['id' => $t69, 'megnevezes' => 'Csere T69', 'brutto_ar' => '1', 'mennyiseg' => '1', 'afa_kulcs' => '27']]]], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
T::igaz(empty($v69['success']) && str_contains((string) ($v69['data']['uzenet'] ?? ''), 'Tételár'), 'a munkalap mentése jog nélkül átírt árral: elutasítva');
T::egyezik(10000.0, (float) $wpdb->get_var($wpdb->prepare('SELECT brutto_ar FROM ' . SDH_Muhely_Tetel::tabla() . ' WHERE id = %d', $t69)), 'és az ár nem változott');

/* --- 4. Láthatóság --------------------------------------------------------------------- */

T::egyezik(false, isset(SDH_Muhely_Modulok::osszes()['penztar']), 'a pultosnak nincs Házipénztár menüpontja');
T::egyezik(null, SDH_Muhely_Modulok::egy('penztar'), 'a pultosnak a Házipénztár oldala nem nyílik (egy() üres)');
T::egyezik(true, isset(SDH_Muhely_Modulok::osszes()['termekek']), 'a Termékek látszik');
$osztaly = SDH_Muhely_Modul_Kapcsolo::body_osztalyok();
T::tartalmaz($osztaly, 'sdh-ki-penztar', 'body: sdh-ki-penztar a tiltott modulra');
T::tartalmaz($osztaly, 'sdh-nem-szamla', 'body: sdh-nem-szamla a hiányzó jogra');
T::egyezik(false, strpos($osztaly, 'sdh-nem-beallitasok') === false, 'body: sdh-nem-beallitasok (a kollégának nincs)');

$keret = static function (string $aktiv): string {
    SDH_Muhely_Modulok::kontextus_beallit('frontend');
    ob_start();
    SDH_Muhely_Frontend::keret('Teszt', $aktiv, static function (): void {
        echo '<p>tartalom</p>';
    });

    return (string) ob_get_clean();
};
$oldal = $keret('attekintes');
T::egyezik(false, strpos($oldal, 'Házipénztár') !== false && strpos($oldal, 'href="' . SDH_Muhely_Modulok::frontend_url('penztar')) !== false, 'az oldalsávban nincs Házipénztár-link a pultosnak');

/* --- 4b. Modulokat lát, jogot nem: a finom jogok külön --------------------------------- */

$megfigyelo = $felh('Megfigyelő T69', 'sdh_kollega');
wp_set_current_user($admin);
$mentes(['szerep_uj' => 'Megfigyelő']);
$mentes(['szemely_szerep' => [(string) $megfigyelo => 'megfigyelo']]);
$Sz::felejt();
T::egyezik('megfigyelo', $Sz::szerep_slug($megfigyelo), 'a megfigyelő szerep hozzárendelve');
$mentes(['szerep' => ['megfigyelo' => ['nev' => 'Megfigyelő', 'jogok' => [], 'modulok' => $modulok]]]);
wp_set_current_user($megfigyelo);
$Sz::felejt();

T::tartalmaz($or('sdh_muhely_penztar_riport'), 'Ehhez nincs jogod', 'pénztárriport: jog nélkül elutasít');
T::tartalmaz($or('sdh_muhely_penztar_statisztika'), 'Pénztárriport', 'pénztár-statisztika: a jog nevével');
T::tartalmaz($or('sdh_muhely_penztar_export'), '|penztar_riport', 'pénztár-export: elutasít');
T::tartalmaz($or('sdh_muhely_penztar_import_feltolt'), '|penztar_riport', 'pénztár-import: elutasít');
T::tartalmaz($or('sdh_muhely_szamla_ment'), '|szamla', 'számla kiállítása: jog nélkül elutasít');
T::tartalmaz($or('sdh_muhely_penztar_torol'), '|torles', 'pénztártétel törlése: jog nélkül elutasít');
T::tartalmaz($or('sdh_muhely_penztar_ujranyit'), '|torles', 'pénztárnap újranyitása: elutasít');
T::egyezik('', $or('sdh_muhely_szamla_elonezet'), 'a számla előnézete jog nélkül is megy');
T::egyezik('', $or('sdh_muhely_penztar_adat'), 'a pénztár napi nézete megy (modul megvan)');
T::egyezik('', $or('sdh_muhely_penztar_ment'), 'új pénztártétel rögzítése megy');

/* --- 5. Pénztáros ------------------------------------------------------------------------ */

$penztaros = $felh('Pénztáros T69', 'sdh_kollega');
wp_set_current_user($admin);
$mentes(['szemely_szerep' => [(string) $penztaros => 'penztaros']]);
wp_set_current_user($penztaros);
$Sz::felejt();

T::egyezik('', $or('sdh_muhely_penztar_riport'), 'a pénztáros látja a riportot');
T::egyezik('', $or('sdh_muhely_szamla_ment'), 'a pénztáros számlát állíthat ki');
T::tartalmaz($or('sdh_muhely_termek_torol'), 'Törlés-szerű', 'a pénztáros nem törölhet');
T::tartalmaz($or('sdh_muhely_termekek_ment'), 'Árak és törzsek', 'a pénztáros nem írhatja át a törzsárakat');
T::egyezik(true, isset(SDH_Muhely_Modulok::osszes()['penztar']), 'a pénztárosnak van Házipénztár menüpontja');

/* --- 6. Közös adminisztrátori fiók + dolgozó -------------------------------------------- */

$bolt = $felh('Bolt T69', 'administrator');
update_option(SDH_Muhely_Szemely::OPT, ['be' => true, 'pin' => false, 'fiokok' => [$bolt]]);
wp_set_current_user($bolt);
unset($_COOKIE[SDH_Muhely_Szemely::SUTI]);
SDH_Muhely_Szemely::felejt();
$Sz::felejt();

T::egyezik(true, SDH_Muhely_Admin_UI::admin_e(), 'választás előtt a közös adminisztrátori fiók admin');

SDH_Muhely_Szemely::rogzit($dolgozo_id);
$Sz::felejt();
T::egyezik($dolgozo_id, SDH_Muhely_Szemely::ki(), 'a dolgozó dolgozik a közös fiókon');
T::egyezik('penztaros', $Sz::szerep_slug(SDH_Muhely_Szemely::ki()), 'a dolgozó szerepe a pénztáros');
T::egyezik(false, SDH_Muhely_Admin_UI::admin_e(), 'a közös adminisztrátori fiókon a dolgozó nem kap beállítás-hozzáférést');
T::tartalmaz($or('sdh_muhely_termek_torol'), 'Törlés-szerű', 'a dolgozó a közös fiókon sem törölhet');
T::egyezik('', $or('sdh_muhely_penztar_riport'), 'de a pénztáros-jogait használhatja');

SDH_Muhely_Szemely::torol();
SDH_Muhely_Szemely::felejt();
$Sz::felejt();
T::egyezik(true, SDH_Muhely_Admin_UI::admin_e(), 'a személy váltása után újra admin');

/* --- 6b. Mentőöv: az adminisztrátor nem zárhatja ki magát ------------------------------- */

// A dolgozót a WP-ben adminisztrátorrá teszik: dolgozó marad (a bejelentkezési neve miatt), és admin.
$norbi = SDH_Muhely_Szemely::uj_dolgozo('Norbi T69', '4321');
wp_update_user(['ID' => $norbi, 'role' => 'administrator']);
SDH_Muhely_Szemely::felejt();
$Sz::felejt();
T::egyezik(true, SDH_Muhely_Szemely::dolgozo_e($norbi), 'a WP-ben adminisztrátorrá tett dolgozó továbbra is dolgozó');
T::egyezik(true, isset(SDH_Muhely_Szemely::dolgozok(true)[$norbi]), 'ő a „Ki vagy?" listában marad');
T::egyezik('admin', $Sz::szerep_slug($norbi), 'a szerepe admin (WP-adminisztrátor)');
wp_update_user(['ID' => $norbi, 'role' => SDH_Muhely_Szemely::SZEREP]);
SDH_Muhely_Szemely::felejt();
$Sz::felejt();
T::egyezik('kollega', $Sz::szerep_slug($norbi), 'sima dolgozóként kolléga');

// Közös adminisztrátori fiók, admin-személy nélkül: nincs választó (különben kizárná magát).
wp_set_current_user($bolt);
unset($_COOKIE[SDH_Muhely_Szemely::SUTI]);
SDH_Muhely_Szemely::felejt();
$Sz::felejt();
T::egyezik(['Dolgozó T69', 'Norbi T69'], array_values(array_map(static fn ($d) => $d['nev'], array_filter(SDH_Muhely_Szemely::dolgozok(true), static fn ($d) => in_array($d['nev'], ['Dolgozó T69', 'Norbi T69'], true)))), 'a dolgozók listája (név szerint rendezve)');
T::egyezik([], SDH_Muhely_Szemely::admin_szemelyek(), 'még nincs admin-személy');
T::egyezik(false, SDH_Muhely_Szemely::kell_valasztani(), 'admin-személy nélkül az adminisztrátori közös fiókon nincs választó');
T::egyezik(true, SDH_Muhely_Admin_UI::admin_e(), 'és a Beállítások elérhető');
T::egyezik('', $or('sdh_muhely_penztar_riport'), 'a rendszer hívásai nem állnak meg');

// Admin szerep egy dolgozónak (a Jogosultságok fülön) → a választó megjelenik, ő belép a PIN-jével.
wp_set_current_user($admin);
$mentes(['szemely_szerep' => [(string) $norbi => 'admin']]);
T::egyezik('admin', $Sz::szerep_slug($norbi), 'a dolgozó Admin szerepet kaphat');
$kolleg2 = $felh('Kolléga2 T69', 'sdh_kollega');
$mentes(['szemely_szerep' => [(string) $kolleg2 => 'admin']]);
T::egyezik('kollega', $Sz::szerep_slug($kolleg2), 'nem dolgozó kollégának Admin szerep nem adható');
wp_delete_user($kolleg2);

update_option(SDH_Muhely_Szemely::OPT, ['be' => true, 'pin' => true, 'fiokok' => [$bolt]]);
wp_set_current_user($bolt);
SDH_Muhely_Szemely::felejt();
$Sz::felejt();
T::egyezik([$norbi => 'Norbi T69'], SDH_Muhely_Szemely::admin_szemelyek(), 'most van admin-személy');
T::egyezik(true, SDH_Muhely_Szemely::kell_valasztani(), 'ezért a közös fiókon a választó megjelenik');

$v = t_ajax('sdh_muhely_ki_vagy', ['szemely' => $norbi, 'pin' => '0000']);
T::egyezik(false, !empty($v['success']), 'hibás PIN: nem lép be');
$v = t_ajax('sdh_muhely_ki_vagy', ['szemely' => $norbi, 'pin' => '4321']);
T::egyezik(true, !empty($v['success']), 'a jó PIN-nel belép');
SDH_Muhely_Szemely::felejt();
$Sz::felejt();
T::egyezik(true, SDH_Muhely_Admin_UI::admin_e(), 'az Admin szerepű dolgozóval a közös adminisztrátori fiókon a Beállítások elérhető');
SDH_Muhely_Szemely::torol();
SDH_Muhely_Szemely::felejt();

// Admin-személy PIN nélkül: a saját WordPress-jelszavával is belép.
update_user_meta($norbi, SDH_Muhely_Szemely::META_PIN, '');
wp_update_user(['ID' => $norbi, 'role' => 'administrator']);
wp_set_password('Norbi-jelszo-T69!', $norbi);
SDH_Muhely_Szemely::felejt();
$Sz::felejt();
$v = t_ajax('sdh_muhely_ki_vagy', ['szemely' => $norbi, 'pin' => 'rossz']);
T::egyezik(false, !empty($v['success']), 'WP-adminisztrátor személy: rossz jelszó nem jó');
$v = t_ajax('sdh_muhely_ki_vagy', ['szemely' => $norbi, 'pin' => 'Norbi-jelszo-T69!']);
T::egyezik(true, !empty($v['success']), 'WP-adminisztrátor személy PIN nélkül a WordPress-jelszavával belép');
SDH_Muhely_Szemely::torol();
SDH_Muhely_Szemely::felejt();

// Sima dolgozó PIN nélkül nem léphet be (a véletlen jelszavával sem).
$pnelkul = SDH_Muhely_Szemely::uj_dolgozo('PINnélkül T69', '');
$v = t_ajax('sdh_muhely_ki_vagy', ['szemely' => $pnelkul, 'pin' => '1234']);
T::egyezik(false, !empty($v['success']), 'a PIN nélküli dolgozó nem választható');

update_option(SDH_Muhely_Szemely::OPT, ['be' => true, 'pin' => false, 'fiokok' => [$bolt]]);

/* --- 7. Törlés, korlátok, kontextus ------------------------------------------------------ */

wp_set_current_user($admin);
$mentes(['szerep' => [
    'ugyvezeto-t69' => ['nev' => ''],
    'pultos'        => ['nev' => 'Pultos', 'jogok' => [], 'modulok' => $mind_kiveve(['penztar'])],
]]);
T::egyezik(false, isset($Sz::szerepek()['ugyvezeto-t69']), 'üres névvel a szerep törlődik');

$mentes(['szemely_szerep' => [(string) $kolléga => 'ugyvezeto-t69']]);
T::egyezik('kollega', $Sz::szerep_slug($kolléga), 'ismeretlen szerep: a személy kolléga marad');

T::igaz(isset($Sz::szerepek()['kollega']), 'a kolléga szerep nem törölhető');
$mentes(['szerep' => ['kollega' => ['nev' => '']]]);
T::igaz(isset($Sz::szerepek()['kollega']), 'üres névvel sem törlődik a kolléga szerep');

for ($i = 0; $i < 20; $i++) {
    $mentes(['szerep_uj' => 'Teszt ' . $i]);
}
T::igaz(count($Sz::szerepek()) <= $Sz::MAX, 'a szerepek száma korlátos (legfeljebb ' . $Sz::MAX . ')');

wp_set_current_user(0);
T::egyezik(true, $Sz::megvan('szamla') && $Sz::modul_engedett('penztar'), 'felhasználó nélküli környezetben (cron) semmi sincs korlátozva');
T::egyezik('', $Sz::body_osztalyok(), 'felhasználó nélkül nincs osztály');

// A Jogosultságok fül kirajzolja a rácsot és a személyeket.
wp_set_current_user($admin);
ob_start();
SDH_Muhely_Szemely::jogosultsagok_rajz();
$html = (string) ob_get_clean();
T::tartalmaz($html, 'Szerepkörök és jogok', 'a Jogosultságok fül mutatja a szerepköröket');
T::tartalmaz($html, 'szerep[pultos][jogok][]', 'a rács jogok jelölői megvannak');
T::tartalmaz($html, 'szemely_szerep[' . $kolléga . ']', 'a személyek szerep-választói megvannak');

// Rendrakás.
delete_option($Sz::OPT);
delete_option(SDH_Muhely_Szemely::OPT);
$Sz::felejt();
SDH_Muhely_Szemely::felejt();
require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ([$kolléga, $penztaros, $megfigyelo, $bolt, $dolgozo_id, $norbi, $pnelkul] as $id) {
    if ($id > 0) {
        wp_delete_user($id);
    }
}

T::vege('t69 szerepkörök');
