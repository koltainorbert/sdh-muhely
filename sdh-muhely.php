<?php
/**
 * Plugin Name:       SDH Műhely
 * Plugin URI:        https://sdh.hu
 * Description:       Belső műhely- és ügyfélkezelő rendszer (CRM + munkalap) az SDH Szerviz számára. A MunkaLap desktop program webes utódja.
 * Version:           0.95.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            InfoStudio Hungary – Koltai Norbert
 * Author URI:        https://infostudio.hu
 * Text Domain:       sdh-muhely
 * Domain Path:       /languages
 *
 * -----------------------------------------------------------------
 *  Ez a fájl csak összefog: betölti a modulokat, kezeli az aktiválást.
 *  Minden tényleges működés az includes/ mappában lakik, modulonként
 *  egy osztályban. Új modul = új fájl + egy sor az alábbi listában.
 * -----------------------------------------------------------------
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit; // Közvetlen hívás tiltva.
}

/* =====================================================================
 * Alapértékek – egy helyen, hogy sehol ne kelljen útvonalat kézzel írni
 * ================================================================== */

define('SDH_MUHELY_VERSION', '0.95.1');
define('SDH_MUHELY_FILE', __FILE__);
define('SDH_MUHELY_DIR', plugin_dir_path(__FILE__));   // .../wp-content/plugins/sdh-muhely/
define('SDH_MUHELY_URL', plugin_dir_url(__FILE__));
define('SDH_MUHELY_BASENAME', plugin_basename(__FILE__));

/* =====================================================================
 * Modulok betöltése
 *
 * A sorrend számít: ami másra épül, az lentebb legyen. A séma mindig
 * első, mert minden modul a tábláira hivatkozik.
 * ================================================================== */

$sdh_muhely_modulok = [
    'includes/class-sdh-muhely-schema.php'   => 'SDH_Muhely_Schema',
    'includes/class-sdh-muhely-modulok.php'  => 'SDH_Muhely_Modulok',
    // Változásnapló (0.59): minden módosítás mezőszinten – a többi modul ELŐTT, hogy semmi ne maradjon ki.
    'includes/class-sdh-muhely-valtozasnaplo.php' => 'SDH_Muhely_Valtozasnaplo',
    // Gyorsítótár (0.64): a drága számolások (darabszámok, összegek) addig érvényesek, amíg az adat nem változik.
    'includes/class-sdh-muhely-gyorsitotar.php' => 'SDH_Muhely_Gyorsitotar',
    // Titkok (kulcsok) titkosított tárolása – a kulcsot használó modulok előtt.
    'includes/class-sdh-muhely-titok.php'    => 'SDH_Muhely_Titok',
    'includes/class-sdh-muhely-admin-ui.php' => 'SDH_Muhely_Admin_UI',
    'includes/class-sdh-muhely-frontend.php' => 'SDH_Muhely_Frontend',
    'includes/class-sdh-muhely-iranyitoszam.php' => 'SDH_Muhely_Iranyitoszam',
    'includes/class-sdh-muhely-csatolmany.php'   => 'SDH_Muhely_Csatolmany',
    'includes/class-sdh-muhely-ugyfel.php'   => 'SDH_Muhely_Ugyfel',
    'includes/class-sdh-muhely-eszkoz.php'   => 'SDH_Muhely_Eszkoz',
    'includes/class-sdh-muhely-munkalap.php' => 'SDH_Muhely_Munkalap',
    'includes/class-sdh-muhely-tetel.php'    => 'SDH_Muhely_Tetel',
    // F1 (0.83.0): javítási garancia – lejárat, visszatérő készülék, garanciális visszatérés.
    'includes/class-sdh-muhely-garancia.php' => 'SDH_Muhely_Garancia',
    // F3 (0.85.0): átvételi fotók és aláírás (érintőképernyő) – a munkalap Átvétel lapfüle, a nyomtatványon is.
    'includes/class-sdh-muhely-atvetel.php'  => 'SDH_Muhely_Atvetel',
    'includes/class-sdh-muhely-szolgaltatas.php' => 'SDH_Muhely_Szolgaltatas',
    'includes/class-sdh-muhely-termek.php'   => 'SDH_Muhely_Termek',
    // F4 (0.86.0): raktár – foglalás nyitott lapokon, hiány a lapon, rendelési javaslat beszállítónként.
    'includes/class-sdh-muhely-raktar.php'   => 'SDH_Muhely_Raktar',
    'includes/class-sdh-muhely-szamla.php'   => 'SDH_Muhely_Szamla',
    'includes/class-sdh-muhely-penztar.php'  => 'SDH_Muhely_Penztar',
    'includes/class-sdh-muhely-penztar-elemzes.php' => 'SDH_Muhely_Penztar_Elemzes',
    'includes/class-sdh-muhely-penztar-ugynok.php'  => 'SDH_Muhely_Penztar_Ugynok',
    'includes/class-sdh-muhely-penztar-import.php'  => 'SDH_Muhely_Penztar_Import',
    'includes/class-sdh-muhely-racs.php'     => 'SDH_Muhely_Racs',
    'includes/class-sdh-muhely-kodok.php'    => 'SDH_Muhely_Kodok',
    'includes/class-sdh-muhely-rma.php'      => 'SDH_Muhely_Rma',
    'includes/class-sdh-muhely-rma-oldal.php' => 'SDH_Muhely_Rma_Oldal',
    // F2 (0.84.0): online árajánlat-elfogadás az ügyféloldalon.
    'includes/class-sdh-muhely-ajanlat.php'  => 'SDH_Muhely_Ajanlat',
    'includes/class-sdh-muhely-eladas.php'   => 'SDH_Muhely_Eladas',
    'includes/class-sdh-muhely-levelezes.php' => 'SDH_Muhely_Levelezes',
    // SMS-értesítések (0.57): Android SMS Gateway, sor, napló, sablonok, popup, bejövő SMS.
    'includes/class-sdh-muhely-sms.php'      => 'SDH_Muhely_Sms',
    'includes/class-sdh-muhely-sms-szabaly.php' => 'SDH_Muhely_Sms_Szabaly',
    'includes/class-sdh-muhely-sms-felulet.php' => 'SDH_Muhely_Sms_Felulet',
    'includes/class-sdh-muhely-sms-bejovo.php' => 'SDH_Muhely_Sms_Bejovo',
    'includes/class-sdh-muhely-tac.php'      => 'SDH_Muhely_Tac',
    // Eszköz-mockupok (0.56): termékkép minden eszköz mellett; Beállítások › Mockupok.
    'includes/class-sdh-muhely-mockup.php'   => 'SDH_Muhely_Mockup',
    'includes/class-sdh-muhely-imei-lekerdezes.php' => 'SDH_Muhely_Imei_Lekerdezes',
    'includes/class-sdh-muhely-nyitvatartas.php'    => 'SDH_Muhely_Nyitvatartas',
    'includes/class-sdh-muhely-beallitasok.php'     => 'SDH_Muhely_Beallitasok',
    // Űrlap-beállítások (0.62.0): fogaskerék minden űrlapon – mi látszódjon, magától kerüljenek-e a tételek a számlára.
    'includes/class-sdh-muhely-urlap-beallitas.php' => 'SDH_Muhely_Urlap_Beallitas',
    // Mezőmemória és a Leggyakoribb Számla tételek listája (0.63.0).
    'includes/class-sdh-muhely-emlek.php'           => 'SDH_Muhely_Emlek',
    // Napló menüpont, a rekordok Napló ablaka, Beállítások › Változásnapló / Lezárt lapok (0.59).
    'includes/class-sdh-muhely-naplo-felulet.php'   => 'SDH_Muhely_Naplo_Felulet',
    // F5 (0.87.0): Riportok – átfutás, technikusok, javítási arány, bevétel.
    'includes/class-sdh-muhely-riport.php'          => 'SDH_Muhely_Riport',
    // H10 (0.90.0): adatbázis-mérés csak olvasva, a Sebesség lapfülről is.
    'includes/class-sdh-muhely-meres.php'           => 'SDH_Muhely_Meres',
    // H12 (0.91.0): netkimaradás – gyors hiba a hosszú várakozás helyett, „Nincs internet” jelzés.
    'includes/class-sdh-muhely-halozat.php'         => 'SDH_Muhely_Halozat',
    // N6 (0.92.0): a régi adminná tett kollégák átállítása SDH adminisztrátorra (fejlesztőnek).
    'includes/class-sdh-muhely-admin-atallas.php'   => 'SDH_Muhely_Admin_Atallas',
    // N4 (0.93.0): licenc, napi jelentés a központnak (aláírt válasz), frissítés a központból, támogatási hozzáférés.
    'includes/class-sdh-muhely-licenc.php'          => 'SDH_Muhely_Licenc',
    // U1 (0.94.0): adatátvétel a MunkaLap 3-ból / táblázatból – próba, adagos átvétel, visszavonás.
    'includes/class-sdh-muhely-adatatvetel.php'     => 'SDH_Muhely_Adatatvetel',
    // Párhuzamos szerkesztés (0.70.0): „közben módosította" és „X is szerkeszti" – a Változásnaplóra és a pulzusra épül.
    'includes/class-sdh-muhely-utkozes.php'         => 'SDH_Muhely_Utkozes',
    'includes/class-sdh-muhely-szemely.php'         => 'SDH_Muhely_Szemely',
    'includes/class-sdh-muhely-szerepkor.php'       => 'SDH_Muhely_Szerepkor',
    // H6 (0.79.0): feloldó kód / minta titkosítva, megnézés jogkötelesen és naplózva.
    'includes/class-sdh-muhely-zarkod.php'          => 'SDH_Muhely_Zarkod',
    // H7 (0.81.0): kétlépcsős belépés (TOTP + tartalék kódok, megbízható gép).
    'includes/class-sdh-muhely-ketlepcsos.php'      => 'SDH_Muhely_Ketlepcsos',
    'includes/class-sdh-muhely-rejtes.php'          => 'SDH_Muhely_Rejtes',
    // Brother RMA-matrica (0.59): címkenyomtató, böngészőből (PDF) vagy közvetlenül hálózaton.
    'includes/class-sdh-muhely-matrica.php'         => 'SDH_Muhely_Matrica',
    // Modulok (0.58): a fő funkciók ki- és bekapcsolása, telepítői zár; az AJAX-őr.
    'includes/class-sdh-muhely-modul-kapcsolo.php'  => 'SDH_Muhely_Modul_Kapcsolo',
    'includes/class-sdh-muhely-demo.php'            => 'SDH_Muhely_Demo',
    'includes/class-sdh-muhely-arculat.php'         => 'SDH_Muhely_Arculat',
    // H6b (0.80.0): a bejelentkező oldal testreszabása (Beállítások › Bejelentkező oldal), élő előnézettel.
    'includes/class-sdh-muhely-belepo.php'          => 'SDH_Muhely_Belepo',
    'includes/class-sdh-muhely-csapat.php'          => 'SDH_Muhely_Csapat',
    // H8 (0.82.0): GDPR – ügyfél anonimizálása törlési kérésre (visszaigazolással).
    'includes/class-sdh-muhely-anonim.php'          => 'SDH_Muhely_Anonim',
    'includes/class-sdh-muhely-naptar.php'          => 'SDH_Muhely_Naptar',
    // F6 (0.88.0): online időpontfoglalás a weboldalról a naptárba.
    'includes/class-sdh-muhely-foglalas.php'        => 'SDH_Muhely_Foglalas',
    // F7 (0.89.0): elégedettségi kérdés a lezárt javítás után, Google-értékelés kérése.
    'includes/class-sdh-muhely-elegedettseg.php'    => 'SDH_Muhely_Elegedettseg',
    'includes/class-sdh-muhely-asszisztens-tudas.php'    => 'SDH_Muhely_Asszisztens_Tudas',
    'includes/class-sdh-muhely-asszisztens-eszkozok.php' => 'SDH_Muhely_Asszisztens_Eszkozok',
    'includes/class-sdh-muhely-asszisztens.php'     => 'SDH_Muhely_Asszisztens',
    // Nyomtatás (0.52): nyomtatványok PDF-ben – előnézet, nyomtatás, mentés, e-mail; sablonok a Beállításokban.
    'includes/class-sdh-muhely-nyomtatvany.php'     => 'SDH_Muhely_Nyomtatvany',
    'includes/class-sdh-muhely-nyomtatas.php'       => 'SDH_Muhely_Nyomtatas',
    // Független napi mentés (az asszisztenstől függetlenül).
    // H3 (0.73.0): külső mentés – titkosítás, célok (mappa, NAS, FTP, Google Drive), visszaállítás, felület.
    'includes/class-sdh-muhely-mentes-titkos.php'   => 'SDH_Muhely_Mentes_Titkos',
    'includes/class-sdh-muhely-mentes-cel.php'      => 'SDH_Muhely_Mentes_Cel',
    'includes/class-sdh-muhely-mentes-ftp.php'      => 'SDH_Muhely_Mentes_Ftp',
    'includes/class-sdh-muhely-mentes-gdrive.php'   => 'SDH_Muhely_Mentes_Gdrive',
    'includes/class-sdh-muhely-mentes-vissza.php'   => 'SDH_Muhely_Mentes_Vissza',
    'includes/class-sdh-muhely-mentes-felulet.php'  => 'SDH_Muhely_Mentes_Felulet',
    'includes/class-sdh-muhely-mentes.php'          => 'SDH_Muhely_Mentes',
    'includes/class-sdh-muhely-frissites.php'       => 'SDH_Muhely_Frissites',
    // Súgó: bemutató, dokumentáció, kérdés–válasz, túrák – a sugo/ mappa fejezeteiből.
    'includes/class-sdh-muhely-sugo.php'            => 'SDH_Muhely_Sugo',
    // Levél-feladat ügynök (0.61.0): visszatérő levelekből teendő – mappa, nyomtatás, címke.
    'includes/class-sdh-muhely-level-feladat.php'   => 'SDH_Muhely_Level_Feladat',
    // Eszközcsomag (0.60.0): a plugin CSS/JS fájljai egy kérésben – a sor végén, mindenki után.
    'includes/class-sdh-muhely-csomag.php'          => 'SDH_Muhely_Csomag',

    /* -----------------------------------------------------------------
     * SDH – belső modulok. CSAK az SDH Szervizé: eladáskor az
     * `includes/sdh/` mappa törlésével nyomtalanul eltűnik (a betöltő a
     * hiányzó fájlt átlépi). Ide SEMMI általános képesség nem kerülhet.
     * -------------------------------------------------------------- */
    'includes/sdh/class-sdh-muhely-sdh.php'         => 'SDH_Muhely_Sdh',
    'includes/sdh/class-sdh-muhely-sdh-gigatel.php' => 'SDH_Muhely_Sdh_Gigatel',
    // 3D kísérő (0.66.0): saját feltöltésű .glb modell Szikra mellett (three.js, GPU).
    'includes/sdh/class-sdh-muhely-sdh-3d.php'      => 'SDH_Muhely_Sdh_3d',
];

foreach ($sdh_muhely_modulok as $sdh_muhely_fajl => $sdh_muhely_osztaly) {
    $sdh_muhely_ut = SDH_MUHELY_DIR . $sdh_muhely_fajl;

    if (!is_readable($sdh_muhely_ut)) {
        continue;
    }

    require_once $sdh_muhely_ut;

    if (method_exists($sdh_muhely_osztaly, 'init')) {
        $sdh_muhely_osztaly::init();
    }
}

unset($sdh_muhely_fajl, $sdh_muhely_osztaly, $sdh_muhely_ut);

/* =====================================================================
 * Aktiválás / deaktiválás
 * ================================================================== */

register_activation_hook(__FILE__, 'sdh_muhely_aktivalas');
register_deactivation_hook(__FILE__, 'sdh_muhely_deaktivalas');

function sdh_muhely_aktivalas(): void
{
    add_option('sdh_muhely_telepitve', current_time('mysql'));
    update_option('sdh_muhely_version', SDH_MUHELY_VERSION);

    // A táblák létrehozása. Idempotens: újraaktiválás nem veszít adatot.
    if (class_exists('SDH_Muhely_Schema')) {
        SDH_Muhely_Schema::telepit();
    }

    // A későbbi saját oldalakhoz (munkalap-nézet stb.) kelleni fog.
    flush_rewrite_rules();
}

function sdh_muhely_deaktivalas(): void
{
    flush_rewrite_rules();
}

/* =====================================================================
 * Fordítások
 * ================================================================== */

add_action('init', static function (): void {
    load_plugin_textdomain('sdh-muhely', false, dirname(SDH_MUHELY_BASENAME) . '/languages');
});
