<?php
/**
 * t32 – Beállítások: egy oldal, témánként külön lapfül, lapfülenkénti mentés.
 * A műhely-felületről semmi nem visz át a wp-adminba; az új állítható
 * paraméterek (csevegő-frissítés, listaméret, a rendelés állapotainak neve).
 */

require __DIR__ . '/_keret.php';

$B = 'SDH_Muhely_Beallitasok';
$M = 'SDH_Muhely_Munkalap';

$ment = static fn (array $mezok): array => t_ajax('sdh_muhely_beallitasok_ment', $mezok, 'sdh_nonce', 'sdh_muhely_beallitasok');

/* --- A lapfülek ---------------------------------------------------------- */

$szakaszok = $B::szakaszok();

T::igaz(count($szakaszok) >= 20, 'legalább húsz lapfül van');

foreach (['arculat', 'csempek', 'ugyfeloldal', 'szamozas', 'allapotok', 'hiba-allapotok', 'gyakori-hibak', 'gyakori-megjegyzesek',
    'fizmodok', 'szamlazas', 'penztar', 'eladas', 'levelezes', 'csapat', 'asszisztens', 'imei', 'tac', 'demo', 'kalauz', 'rendszer'] as $kulcs) {
    T::igaz(isset($szakaszok[$kulcs]), 'van „' . $kulcs . '” lapfül');
}

// Minden lapfül kirajzolódik, PHP-figyelmeztetés nélkül, és nem mutat a wp-adminba.
SDH_Muhely_Modulok::kontextus_beallit('frontend');

$figy = [];
set_error_handler(static function (int $szint, string $szoveg, string $fajl, int $sor) use (&$figy): bool {
    $figy[] = basename($fajl) . ':' . $sor . ' ' . $szoveg;

    return true;
});

foreach (array_keys($szakaszok) as $kulcs) {
    $_GET = ['ful' => $kulcs];
    ob_start();
    $B::oldal();
    $html = (string) ob_get_clean();

    T::tartalmaz($html, 'data-sdh-beall-ful="' . $kulcs . '"', $kulcs . ': a kért lapfül nyílik');
    T::igaz(strpos($html, 'class="sdh-doboz"') !== false || strpos($html, 'class="sdh-doboz ') !== false || strpos($html, 'sdh-doboz"') !== false, $kulcs . ': van tartalma');
    T::egyezik(!empty($szakaszok[$kulcs]['ment']), strpos($html, 'data-sdh-beall-ment') !== false, $kulcs . ': Mentés gomb pontosan ott van, ahol van mit menteni');
    T::igaz(preg_match('#href="[^"]*wp-admin/admin\.php#', $html) !== 1, $kulcs . ': nincs wp-adminba mutató link');
    T::egyezik(1, substr_count($html, 'aria-current="page"'), $kulcs . ': egy aktív pill');
}

$_GET = ['ful' => 'nincs-ilyen'];
ob_start();
$B::oldal();
$html = (string) ob_get_clean();
T::tartalmaz($html, 'data-sdh-beall-ful="arculat"', 'ismeretlen lapfül helyett az első nyílik');
$_GET = [];

restore_error_handler();
T::egyezik([], array_values(array_unique($figy)), 'a lapfülek PHP-figyelmeztetés nélkül rajzolódnak ki');

T::tartalmaz($B::url('penztar'), 'muhely=beallitasok', 'a műhely-felületen a lapfül címe a műhely-felületé');
T::tartalmaz($B::url('penztar'), 'ful=penztar', 'a cím viszi a lapfület');

/* --- Nincs külön Arculat / TAC menüpont; a csempe sem visz az adminba ---- */

T::igaz(SDH_Muhely_Modulok::egy('arculat') === null && SDH_Muhely_Modulok::egy('tac') === null, 'az Arculat és a TAC nem külön menüpont');
T::igaz(SDH_Muhely_Modulok::egy('beallitasok') !== null, 'a Beállítások modul megvan');

ob_start();
SDH_Muhely_Admin_UI::attekintes_tartalom(false);
$att = (string) ob_get_clean();
T::igaz(preg_match('#data-sdh-kartya="beallitasok"#', $att) === 1, 'az Áttekintésen van Beállítások csempe');
T::igaz(preg_match('#href="[^"]*wp-admin/admin\.php#', $att) !== 1, 'az Áttekintésről (műhely-felület) semmi nem mutat a wp-adminba');

ob_start();
SDH_Muhely_Frontend::keret('Teszt', 'beallitasok', static function (): void {
});
$keret = (string) ob_get_clean();
T::igaz(preg_match('#class="sdh-sav__link[^"]*"\s+href="[^"]*wp-admin#', $keret) !== 1, 'az oldalmenüben nincs wp-adminba mutató link');
T::tartalmaz($keret, 'muhely=beallitasok', 'az oldalmenü alján a Beállítások a műhely-felületre mutat');
T::tartalmaz($keret, 'beallitasok.js', 'a Beállítások oldalon betöltődik a saját szkript');
T::nem_tartalmaz($keret, 'Karbantartás', 'a wp-adminba vivő „Karbantartás” link megszűnt');

/* --- Lapfülenkénti mentés: a többi beállítás érintetlen ------------------ */

$opciok = ['sdh_muhely_munkalap_allapotok', 'sdh_muhely_munkalap_hiba_allapotok', 'sdh_muhely_fizetesi_modok', 'sdh_muhely_gyakori_hibak', 'sdh_muhely_munkalap_szamozas', 'sdh_muhely_rma', 'sdh_muhely_eladas', 'sdh_muhely_imei_szolgaltato'];
$elotte = [];

foreach ($opciok as $o) {
    $elotte[$o] = get_option($o, null);
}

update_option('sdh_muhely_munkalap_allapotok', $M::alap_allapotok_szovegkent());
update_option('sdh_muhely_fizetesi_modok', "Készpénz\nBankkártya\nTESZTMÓD");

$r = $ment(['ful' => 'szamozas', 'mu_kovetkezo' => '1', 'mu_elotag' => 'T-', 'mu_szamjegy' => '5']);
T::igaz(!empty($r['success']), 'a Számozás lapfül menthető');
T::egyezik('T-', $M::szamozas()['elotag'], 'az előtag mentődött');
T::egyezik(5, $M::szamozas()['szamjegy'], 'a kitöltés mentődött');
T::egyezik($M::alap_allapotok_szovegkent(), (string) get_option('sdh_muhely_munkalap_allapotok'), 'a Számozás mentése nem nyúlt az állapotlistához');
T::tartalmaz((string) get_option('sdh_muhely_fizetesi_modok'), 'TESZTMÓD', 'és a fizetési módokhoz sem');

$r = $ment(['ful' => 'fizmodok', 'mu_fizetesi_modok' => "Készpénz\nÁtutalás"]);
T::egyezik(['Készpénz', 'Átutalás'], $M::fizetesi_modok(), 'a Fizetési módok lapfül menthető');
T::egyezik('T-', $M::szamozas()['elotag'], 'a Fizetési módok mentése nem nyúlt a számozáshoz');

$r = $ment(['ful' => 'gyakori-megjegyzesek', 'mu_gyakori_megjegyzesek' => "Első megjegyzés\nMásodik"]);
T::egyezik(['Első megjegyzés', 'Második'], $M::gyakori_megjegyzesek(), 'a Gyakori megjegyzések lapfül menthető');
delete_option('sdh_muhely_gyakori_megjegyzesek');

$r = $ment(['ful' => 'nincs-ilyen']);
T::igaz(empty($r['success']), 'ismeretlen lapfül nem menthető');
$r = $ment(['ful' => 'tac']);
T::igaz(empty($r['success']), 'mentés nélküli lapfülre nincs mentés');

/* --- Új állítható paraméterek -------------------------------------------- */

$r = $ment(['ful' => 'ugyfeloldal', 'rma_szerviz_nev' => 'Teszt Szerviz', 'rma_chat_mp' => '15', 'rma_nyelvek' => ['hu']]);
T::egyezik(15, SDH_Muhely_Rma::beallitas()['chat_mp'], 'a csevegő frissítési ideje állítható');
T::egyezik(15000, SDH_Muhely_Admin_UI::js_beallitas('frontend')['chatMs'], 'és eljut a felületre');
$ment(['ful' => 'ugyfeloldal', 'rma_chat_mp' => '1']);
T::egyezik(3, SDH_Muhely_Rma::beallitas()['chat_mp'], 'három másodpercnél sűrűbb nem lehet');

$r = $ment(['ful' => 'eladas', 'eladas_jelen' => '1', 'eladas_auto_lezar' => '1', 'eladas_oldal_meret' => '40',
    'eladas_rend_nevek' => ['megerkezett' => 'Megjött', 'kitalalt' => 'X', 'atadva' => '']]);
$e = SDH_Muhely_Eladas::beallitas();
T::egyezik(40, $e['oldal_meret'], 'a lista oldalmérete állítható');
T::egyezik(['megerkezett' => 'Megjött'], $e['rend_nevek'], 'a rendelés-állapot neve átírható (ismeretlen kulcs és üres név nélkül)');
T::egyezik('Megjött', SDH_Muhely_Eladas::rend_allapotok()['megerkezett']['nev'], 'az új név érvényesül');
T::egyezik('Átadva', SDH_Muhely_Eladas::rend_allapotok()['atadva']['nev'], 'a többi az alapnevét viseli');
T::egyezik(['felveve', 'megrendelve', 'megerkezett', 'ertesitve', 'atadva'], array_keys(SDH_Muhely_Eladas::rend_allapotok()), 'a kulcsok és a sorrend adott');
T::tartalmaz(SDH_Muhely_Eladas::rend_jelveny('megerkezett'), 'Megjött', 'a jelvény az új nevet írja');

$r = $ment(['ful' => 'imei', 'imei_jelen' => '1', 'aktiv' => '1', 'url' => 'https://pelda.hu/api?imei={imei}', 'kulcs' => 'abc']);
T::egyezik('abc', SDH_Muhely_Imei_Lekerdezes::beallitas()['kulcs'], 'az IMEI lapfül menthető');

/* --- Jogosultság: kolléga nem menthet, nem tölthet fel -------------------- */

$kollega = wp_insert_user(['user_login' => 'beall' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(), 'role' => 'editor']);
$admin   = get_current_user_id();
get_userdata($kollega)->add_cap(SDH_Muhely_Admin_UI::jog());
wp_set_current_user($kollega);

$r = $ment(['ful' => 'szamozas', 'mu_kovetkezo' => '1', 'mu_elotag' => 'ROSSZ-']);
T::igaz(empty($r['success']), 'kolléga nem menthet beállítást');
$r = t_ajax('sdh_muhely_beallitasok_feltolt', ['tipus' => 'image'], 'sdh_nonce', 'sdh_muhely_beallitasok');
T::igaz(empty($r['success']), 'kolléga nem tölthet fel');

wp_set_current_user($admin);
T::egyezik('T-', $M::szamozas()['elotag'], 'a tiltott mentés nem írt');

$r = t_ajax('sdh_muhely_beallitasok_feltolt', ['tipus' => 'image'], 'sdh_nonce', 'sdh_muhely_beallitasok');
T::igaz(empty($r['success']) && isset($r['data']['uzenet']), 'fájl nélküli feltöltésre hibaüzenet jön');

/* --- Visszaállítás -------------------------------------------------------- */

foreach ($elotte as $o => $ertek) {
    if ($ertek === null) {
        delete_option($o);
    } else {
        update_option($o, $ertek);
    }
}

T::vege('t32 beállítások');
