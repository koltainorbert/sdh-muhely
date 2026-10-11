<?php
/**
 * t33 – 0.48: az ügyfél kártyája és szerkesztése az árukiadáson, a rendelésen
 * és a munkalapon; állapotváltás a listák sorából; a fejléc pill-gombjai
 * ikonnal; nyitvatartás és az Áttekintés órája; a Levelezés „új levél"
 * jelzése fiókonként; az Asszisztens a menü helyett a Beállításokból nyílik.
 */

require __DIR__ . '/_keret.php';

$M  = 'SDH_Muhely_Munkalap';
$E  = 'SDH_Muhely_Eladas';
$NY = 'SDH_Muhely_Nyitvatartas';
$L  = 'SDH_Muhely_Levelezes';

global $wpdb;

$ment = static fn (array $mezok): array => t_ajax('sdh_muhely_munkalapok_ment', $mezok + [
    'id' => 0, 'tetelek_jelen' => 1, 'allapot' => 'nyitott', 'keszult' => current_time('Y-m-d'),
    'lap_afakulcs' => '27', 'lap_kedvezmeny' => '0', 'fizetett' => '0',
], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');

$termek = static fn (string $nev, float $ar): array => ['megnevezes' => $nev, 'brutto_ar' => (string) $ar, 'mennyiseg' => '1', 'me' => 'db', 'afa_kulcs' => '27'];

$figy = [];
set_error_handler(static function (int $szint, string $szoveg, string $fajl, int $sor) use (&$figy): bool {
    $figy[] = basename($fajl) . ':' . $sor . ' ' . $szoveg;

    return true;
});

SDH_Muhely_Modulok::kontextus_beallit('frontend');

/* ======================================================================== */
/* 1–3. Az ügyfél kártyája és szerkesztése                                   */
/* ======================================================================== */

$u     = t_ugyfel('Kártya Kata');
$u_sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', $u));

// Új árukiadás, ügyfél nélkül: a kártya ott van, üresen, és mondja, hogy nem kötelező.
$urlap = t_ajax('sdh_muhely_arukiadas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0])['nyers'];
T::tartalmaz($urlap, 'class="sdh-ml-ugyfel" data-sdh-osszefoglalo', 'árukiadás: az ügyfél kártyája a lapfülek fölött áll');
T::tartalmaz($urlap, 'nincs ügyfél kiválasztva', 'árukiadás ügyfél nélkül: a kártya ezt mondja');
T::egyezik(1, substr_count($urlap, 'data-sdh-osszefoglalo'), 'árukiadás: egyetlen összefoglaló-hely van (a rejtett Eszköz fülön nincs)');
T::tartalmaz($urlap, 'data-sdh-ugyfel-szerk disabled', 'ügyfél nélkül a szerkesztőgomb nem kattintható');
T::nem_tartalmaz($urlap, 'Válassz eszközt', 'árukiadás: nincs eszközkártya');

// Árukiadás ügyféllel: a kártyán ott a név, a telefon, a cím – és a Szerkesztés gomb.
$r   = $ment(['fajta' => 'arukiadas', 'ugyfel_id' => $u, 'tetelek' => ['termek' => [$termek('Töltő', 5990)]]]);
$aid = (int) ($r['data']['id'] ?? 0);
T::igaz($aid > 0, 'árukiadás ügyféllel menthető');

$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $aid])['nyers'];
$kartya = (string) substr($urlap, (int) strpos($urlap, 'class="sdh-ml-ugyfel"'), 2600);
T::tartalmaz($kartya, esc_html((string) $u_sor->nev), 'árukiadás: a kártyán az ügyfél neve');
T::tartalmaz($kartya, (string) $u_sor->telefon, 'árukiadás: a kártyán az ügyfél telefonszáma');
T::tartalmaz($kartya, '8200 Veszprém, Teszt utca 1.', 'árukiadás: a kártyán a teljes cím');
T::tartalmaz($kartya, (string) $u_sor->email, 'árukiadás: a kártyán az e-mail');
T::tartalmaz($kartya, 'data-sdh-masol', 'árukiadás: az adatok mellett másoló gomb');
T::tartalmaz($kartya, 'data-sdh-ugyfel-szerk', 'árukiadás: a kártyán Szerkesztés gomb');
T::igaz(preg_match('/data-sdh-ugyfel-szerk\s+title="A kiválasztott ügyfél/', $urlap) === 1, 'árukiadás: az ügyfélmező mellett él a szerkesztőgomb');
T::igaz(preg_match('/<label for="ful_m_eszkoz" hidden>/', $urlap) === 1, 'árukiadás: az Eszköz fül továbbra is rejtve');

// Rendelés.
$r   = $ment(['fajta' => 'rendeles', 'ugyfel_id' => $u, 'rend_allapot' => 'megrendelve', 'tetelek' => ['termek' => [$termek('Kijelző', 32000)]]]);
$rid = (int) ($r['data']['id'] ?? 0);
T::igaz($rid > 0, 'rendelés menthető');

$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $rid])['nyers'];
$kartya = (string) substr($urlap, (int) strpos($urlap, 'class="sdh-ml-ugyfel"'), 2600);
T::tartalmaz($urlap, 'class="sdh-ml-ugyfel" data-sdh-osszefoglalo', 'rendelés: az ügyfél kártyája a lapfülek fölött áll');
T::tartalmaz($kartya, (string) $u_sor->telefon, 'rendelés: a kártyán az ügyfél telefonszáma');
T::tartalmaz($kartya, 'data-sdh-ugyfel-szerk', 'rendelés: a kártyán Szerkesztés gomb');
T::igaz(strpos($urlap, 'sdh-eladas-rendeles') < strpos($urlap, 'class="sdh-ml-ugyfel"'), 'rendelés: a kártya a rendelés sora alatt áll');

// Munkalap (javítás): az Eszköz fülön marad, szerkesztőgombbal.
$esz = t_eszkoz($u, 'Samsung', 'Galaxy A54');
$mid = t_munkalap($u, [$esz]);
$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $mid])['nyers'];
T::tartalmaz($urlap, 'class="sdh-fulek__panel sdh-fulek__panel--1" data-sdh-osszefoglalo', 'munkalap: az összefoglaló az Eszköz fülön van');
T::nem_tartalmaz($urlap, 'class="sdh-ml-ugyfel"', 'munkalap: nincs külön ügyfélblokk');
T::igaz(substr_count($urlap, 'data-sdh-ugyfel-szerk') === 2, 'munkalap: szerkesztőgomb az ügyfélmezőnél és az ügyfél kártyáján');
T::tartalmaz($urlap, 'Galaxy A54', 'munkalap: az eszköz kártyája megvan');

// Az összefoglaló frissítése (ügyfélváltás, szerkesztés után): eladásnál csak az ügyfél jön.
$o = t_ajax('sdh_muhely_munkalapok_osszefoglalo', [], '_wpnonce', 'sdh_muhely_modal', ['ugyfel_id' => $u, 'eszkoz_id' => 0, 'fajta' => 'rendeles'])['nyers'];
T::tartalmaz($o, (string) $u_sor->telefon, 'összefoglaló (rendelés): az ügyfél adatai');
T::nem_tartalmaz($o, 'eszközt', 'összefoglaló (rendelés): eszközről nem beszél');
T::tartalmaz($o, '<strong>Ügyfél</strong>', 'összefoglaló (rendelés): a kártya címe Ügyfél');

$o = t_ajax('sdh_muhely_munkalapok_osszefoglalo', [], '_wpnonce', 'sdh_muhely_modal', ['ugyfel_id' => $u, 'eszkoz_id' => 0, 'fajta' => 'arukiadas'])['nyers'];
T::tartalmaz($o, '<strong>Vevő</strong>', 'összefoglaló (árukiadás): a kártya címe Vevő');

$o = t_ajax('sdh_muhely_munkalapok_osszefoglalo', [], '_wpnonce', 'sdh_muhely_modal', ['ugyfel_id' => $u, 'eszkoz_id' => $esz])['nyers'];
T::tartalmaz($o, 'Galaxy A54', 'összefoglaló (munkalap): az eszköz kártyája is jön');
T::tartalmaz($o, 'data-sdh-ugyfel-szerk', 'összefoglaló (munkalap): az ügyfél kártyáján Szerkesztés gomb');

// A szerkesztés maga az ügyfél űrlapja: mentés után a válasz hozza az azonosítót és a nevet (ebből frissül a lap).
$u_urlap = t_ajax('sdh_muhely_ugyfelek_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $u])['nyers'];
T::tartalmaz($u_urlap, 'data-sdh-ajax-action="sdh_muhely_ugyfelek_ment"', 'az ügyfél űrlapja popupban nyílik');

$r = t_ajax('sdh_muhely_ugyfelek_ment', ['id' => $u, 'nev' => 'Kártya Katalin', 'telefon' => '+36301112233', 'aktiv' => 1], 'sdh_nonce', 'sdh_muhely_ugyfel_mentes');
T::igaz(!empty($r['success']) && (int) ($r['data']['id'] ?? 0) === $u && ($r['data']['nev'] ?? '') === 'Kártya Katalin', 'az ügyfél mentése visszaadja az azonosítót és az új nevet');

$o = t_ajax('sdh_muhely_munkalapok_osszefoglalo', [], '_wpnonce', 'sdh_muhely_modal', ['ugyfel_id' => $u, 'eszkoz_id' => 0, 'fajta' => 'arukiadas'])['nyers'];
T::tartalmaz($o, 'Kártya Katalin', 'szerkesztés után a kártya az új nevet mutatja');
T::tartalmaz($o, '+36301112233', 'szerkesztés után a kártya az új telefonszámot mutatja');

/* ======================================================================== */
/* 5. Állapot a lista sorából                                                */
/* ======================================================================== */

$lista = static function (callable $oldal, array $get = []): string {
    $_GET = $get;
    ob_start();
    $oldal();
    $_GET = [];

    return (string) ob_get_clean();
};

$html = $lista([$E, 'oldal_rendelesek'], ['szuro' => 'mind']);
T::igaz(preg_match('/<select data-sdh-rend-allapot data-id="' . $rid . '" data-elozo="megrendelve"/', $html) === 1, 'rendelések listája: a rendelés útja a sorban állítható');
T::tartalmaz($html, 'data-sdh-lap-allapot', 'rendelések listája: a lap állapotának helye megvan');

$e = $E::rend_allapot_valt($rid, 'megerkezett');
T::igaz(is_array($e) && $e['kulcs'] === 'megerkezett' && $e['szin'] === 'narancs', 'a rendelés útja átállítható: megérkezett');
T::egyezik('megerkezett', (string) t_lap($rid)->rend_allapot, 'az adatbázisban is megérkezett');
T::igaz(is_array($e) && $e['erkezett'] >= 1 && $e['zart'] === false, 'a válasz hozza a menü jelvényét, a lap nem zárult le');
T::igaz(is_string($E::rend_allapot_valt($rid, 'nincs-ilyen')), 'ismeretlen rendelés-állapot: hibaüzenet');
T::igaz(is_string($E::rend_allapot_valt($aid, 'atadva')), 'árukiadásnak nincs rendelés-állapota: hibaüzenet');
T::igaz(is_string($E::rend_allapot_valt($mid, 'atadva')), 'munkalapnak nincs rendelés-állapota: hibaüzenet');

$r = t_ajax('sdh_muhely_rendelesek_allapot', ['id' => $rid, 'allapot' => 'ertesitve']);
T::igaz(!empty($r['success']) && ($r['data']['nev'] ?? '') === 'Ügyfél értesítve', 'AJAX: a rendelés útja a listából állítható');
T::egyezik('', (string) (t_lap($rid)->rend_ertesitve ?? ''), 'a listás állapotváltás nem küld értesítést (az időpontja üres marad)');

// Átadva + kifizetve: a rendelés magától lezárul – a listából állítva is.
$wpdb->update(SDH_Muhely_Schema::tabla('munkalap'), ['fizetve' => 1, 'fizetett' => 32000], ['id' => $rid]);
$e = $E::rend_allapot_valt($rid, 'atadva');
T::igaz(is_array($e) && $e['zart'] === true && $e['most_zart'] === true && $e['lap_allapot'] !== '', 'átadva és kifizetve: a rendelés lezárul, a válasz jelzi');
$e = $E::rend_allapot_valt($rid, 'ertesitve');
T::igaz(is_array($e) && $e['zart'] === true && $e['most_zart'] === false, 'lezárt rendelés visszaállítása: a lap lezárt marad, de nem „most" zárult');
$E::rend_allapot_valt($rid, 'atadva');
T::igaz(!empty($M::allapotok()[(string) t_lap($rid)->allapot]['zart']), 'a lezárás az adatbázisban is megvan');

// Napi árukiadás: a lap állapota a sorból, csak az eladásnál értelmes állapotokkal.
$html = $lista([$E, 'oldal_arukiadas']);
T::igaz(preg_match('/<select data-sdh-lista-allapot\s+data-id="' . $aid . '"/', $html) === 1, 'árukiadás listája: az állapot a sorban állítható');
$sor = (string) substr($html, (int) strpos($html, 'data-id="' . $aid . '"'), 900);
T::nem_tartalmaz($sor, 'Bejelentett', 'árukiadás listája: a javítás állapotai nem választhatók');

$lezart = $M::lezart_kulcs();
$r = t_ajax('sdh_muhely_munkalapok_allapot', ['id' => $aid, 'allapot' => $lezart]);
T::igaz(!empty($r['success']) && !empty($r['data']['zart']), 'árukiadás: az állapot a listából lezártra állítható');
T::egyezik($lezart, (string) t_lap($aid)->allapot, 'árukiadás: az új állapot az adatbázisban');

/* ======================================================================== */
/* 6. Fejlécgombok: pill + SVG ikon                                          */
/* ======================================================================== */

ob_start();
SDH_Muhely_Admin_UI::attekintes_tartalom(false);
$att = (string) ob_get_clean();

$gombok = (string) substr($att, (int) strpos($att, 'sdh-fejlec__gombok'), (int) strpos($att, 'data-sdh-csempek>') - (int) strpos($att, 'sdh-fejlec__gombok'));
T::egyezik(substr_count($gombok, '<a href='), substr_count($gombok, 'sdh-gomb--pill'), 'Áttekintés: minden fejlécgomb pill');
T::egyezik(substr_count($gombok, '<a href='), substr_count($gombok, '<svg class="sdh-gomb__ikon"'), 'Áttekintés: minden fejlécgombon SVG ikon');
T::igaz(substr_count($gombok, '<a href=') >= 7, 'Áttekintés: megvan a hét gomb');
T::tartalmaz($gombok, '<span class="sdh-gomb__felirat">Új munkalap</span>', 'a felirat külön elemben áll, pluszjel nélkül');
T::nem_tartalmaz($gombok, 'sdh-plusz', 'a régi rajzolt pluszjel helyett ikon van');
T::tartalmaz($gombok, 'data-sdh-csempek-valt', 'a csempék gombja megvan');
T::igaz(SDH_Muhely_Admin_UI::gomb_ikon('nincs-ilyen-ikon') === '', 'ismeretlen ikonnévre nincs ikon');
T::igaz(strpos(SDH_Muhely_Frontend::ikon('ugyfelek'), 'sdh-sav__ikon') !== false, 'a menü ikonjai változatlanul működnek');

/* ======================================================================== */
/* 7. Nyitvatartás és óra                                                    */
/* ======================================================================== */

$regi_nyitva = get_option('sdh_muhely_nyitvatartas', null);
delete_option('sdh_muhely_nyitvatartas');

T::egyezik('09:00', $NY::ido_ervenyes('9'), 'időpont: 9 → 09:00');
T::egyezik('09:30', $NY::ido_ervenyes('9:30'), 'időpont: 9:30 → 09:30');
T::egyezik('17:05', $NY::ido_ervenyes('17.05'), 'időpont: 17.05 → 17:05');
T::egyezik('08:00', $NY::ido_ervenyes('0800'), 'időpont: 0800 → 08:00');
T::egyezik('', $NY::ido_ervenyes('25:00'), 'időpont: 25:00 érvénytelen');
T::egyezik('', $NY::ido_ervenyes('reggel'), 'időpont: szöveg érvénytelen');

T::egyezik('nincs', $NY::allapot('2026-10-12 10:00:00')['allapot'], 'nyitvatartás nélkül nincs visszaszámlálás');
T::tartalmaz($NY::ora_html(), 'data-sdh-ora', 'az óra nyitvatartás nélkül is látszik');
T::tartalmaz($NY::ora_html(), 'Nyitvatartás megadása', 'adminnak: hivatkozás a nyitvatartás megadásához');
T::nem_tartalmaz($NY::ora_html(), 'wp-admin', 'az óra hivatkozása nem visz a wp-adminba');

$bment = static fn (array $mezok): array => t_ajax('sdh_muhely_beallitasok_ment', $mezok, 'sdh_nonce', 'sdh_muhely_beallitasok');

$nyitva = [];

foreach ([1, 2, 3, 4, 5] as $nap) {
    $nyitva[$nap] = ['be' => '1', 'nyit' => '9', 'zar' => '17:00'];
}

$nyitva[6] = ['be' => '1', 'nyit' => '9:00', 'zar' => '12.00'];
$nyitva[7] = ['nyit' => '9:00', 'zar' => '12:00']; // nincs bepipálva: zárva

$r = $bment(['ful' => 'nyitvatartas', 'nyitva_jelen' => '1', 'nyitva' => $nyitva, 'nyitva_ora' => '1', 'nyitva_masodperc' => '1', 'nyitva_figyelmeztet' => '30']);
T::igaz(!empty($r['success']), 'a Nyitvatartás lapfül menthető');

$b = $NY::beallitas();
T::egyezik([1, 2, 3, 4, 5, 6], array_keys($b['napok']), 'hétfőtől szombatig nyitva, vasárnap zárva');
T::egyezik(['09:00', '17:00'], $b['napok'][1], 'a beírt időpontok óó:pp alakra kerültek');
T::egyezik(['09:00', '12:00'], $b['napok'][6], 'szombat: 9–12');

// 2026-10-12 hétfő.
$a = $NY::allapot('2026-10-12 08:15:00');
T::igaz($a['allapot'] === 'elott' && $a['perc'] === 45 && $a['szoveg'] === 'Nyitásig 45 perc', 'nyitás előtt: nyitásig 45 perc');

$a = $NY::allapot('2026-10-12 14:46:00');
T::igaz($a['allapot'] === 'nyitva' && $a['perc'] === 134 && $a['szoveg'] === 'Zárásig 2 óra 14 perc', 'nyitva: zárásig 2 óra 14 perc');
T::igaz($a['arany'] > 0.71 && $a['arany'] < 0.73, 'a nap eltelt része (8 órából 5 óra 46 perc)');

$a = $NY::allapot('2026-10-12 16:40:00');
T::igaz($a['allapot'] === 'hamar' && $a['szoveg'] === 'Zárásig 20 perc', 'zárás előtt 30 percen belül: figyelmeztet');

$a = $NY::allapot('2026-10-12 16:00:00');
T::egyezik('Zárásig 1 óra', $a['szoveg'], 'kerek óra: „1 óra"');

$a = $NY::allapot('2026-10-12 16:59:30');
T::egyezik('Zárásig 1 perc', $a['szoveg'], 'az utolsó percben: 1 perc');

$a = $NY::allapot('2026-10-12 17:00:00');
T::igaz($a['allapot'] === 'zarva' && $a['szoveg'] === 'Zárva · nyitás: holnap 09:00', 'zárás után: holnap nyit');

$a = $NY::allapot('2026-10-10 13:00:00'); // szombat délután
T::egyezik('Zárva · nyitás: hétfő 09:00', $a['szoveg'], 'szombat zárás után: hétfőn nyit');

$a = $NY::allapot('2026-10-11 10:00:00'); // vasárnap
T::egyezik('Ma zárva · nyitás: holnap 09:00', $a['szoveg'], 'vasárnap: ma zárva, holnap nyit');

$ora = $NY::ora_html();
T::tartalmaz($ora, 'data-het="{&quot;1&quot;:[&quot;09:00&quot;,&quot;17:00&quot;]', 'az óra megkapja a heti nyitvatartást');
T::tartalmaz($ora, 'data-most="' . substr(current_time('mysql'), 0, 10) . 'T', 'az óra megkapja a szerver idejét');
T::tartalmaz($ora, 'data-sdh-ora-zaras', 'az órán ott a visszaszámláló helye');
T::tartalmaz($ora, 'data-sdh-ora-mp', 'másodperc is látszik');
T::tartalmaz($att !== '' ? (static function (): string { ob_start(); SDH_Muhely_Admin_UI::attekintes_tartalom(false); return (string) ob_get_clean(); })() : '', 'data-sdh-ora', 'az óra az Áttekintés fejlécében van');

// Kikapcsolható; a másodperc is.
$bment(['ful' => 'nyitvatartas', 'nyitva_jelen' => '1', 'nyitva' => $nyitva, 'nyitva_ora' => '1', 'nyitva_figyelmeztet' => '0']);
T::nem_tartalmaz($NY::ora_html(), 'data-sdh-ora-mp', 'a másodperc kikapcsolható');
T::egyezik('nyitva', $NY::allapot('2026-10-12 16:40:00')['allapot'], 'figyelmeztetés nélkül (0 perc) nem vált színt');

$bment(['ful' => 'nyitvatartas', 'nyitva_jelen' => '1', 'nyitva' => $nyitva]);
T::egyezik('', $NY::ora_html(), 'az óra kikapcsolható');

// Fordított időpont (zárás a nyitás előtt) nem számít nyitvatartásnak.
$bment(['ful' => 'nyitvatartas', 'nyitva_jelen' => '1', 'nyitva' => [1 => ['be' => '1', 'nyit' => '17:00', 'zar' => '9:00']], 'nyitva_ora' => '1']);
T::egyezik([], $NY::beallitas()['napok'], 'a zárás nem lehet a nyitás előtt');

if ($regi_nyitva === null) {
    delete_option('sdh_muhely_nyitvatartas');
} else {
    update_option('sdh_muhely_nyitvatartas', $regi_nyitva, false);
}

/* ======================================================================== */
/* 8. Levelezés: „új levél" jelzés fiókonként                                */
/* ======================================================================== */

$regi_level = get_option('sdh_muhely_levelezes', null);
$regi_latta = get_user_meta(get_current_user_id(), 'sdh_muhely_level_latta', true);
$lt = SDH_Muhely_Schema::tabla('level');
$mt = SDH_Muhely_Schema::tabla('level_mappa');

// Két bekötött fiók (a jelszó itt csak jelölő: a szerverhez a teszt nem kapcsolódik).
$fiok = static fn (string $nev, string $email): array => ['nev' => $nev, 'email' => $email, 'felhasznalo' => '', 'jelszo' => 'v1:teszt', 'aktiv' => true, 'zar' => ''];
update_option('sdh_muhely_levelezes', ['fiokok' => ['f1' => $fiok('Szerviz', 'szerviz@example.com'), 'f2' => $fiok('Webshop', 'webshop@example.com')]], false);
delete_user_meta(get_current_user_id(), 'sdh_muhely_level_latta');

$wpdb->query("DELETE FROM {$lt} WHERE fiok IN ('f1', 'f2')"); // phpcs:ignore
$wpdb->query("DELETE FROM {$mt} WHERE fiok IN ('f1', 'f2')"); // phpcs:ignore

$mappa = [];

foreach (['f1', 'f2'] as $fk) {
    // „Most szinkronizált" mappa: az állapot-lekérdezés így nem kapcsolódik a levelezőszerverhez.
    $wpdb->insert($mt, ['fiok' => $fk, 'nyers' => 'INBOX', 'nev' => 'INBOX', 'szerep' => 'inbox', 'valaszthato' => 1, 'osszes' => 0, 'olvasatlan' => 0, 'szinkron' => current_time('mysql')]);
    $mappa[$fk] = (int) $wpdb->insert_id;
}

$uid = 100;
$level = static function (string $fk, array $tovabbi = []) use ($wpdb, $lt, &$mappa, &$uid): int {
    $wpdb->insert($lt, $tovabbi + [
        'fiok' => $fk, 'mappa_id' => $mappa[$fk], 'uid' => ++$uid, 'felado_email' => 'valaki@example.com', 'targy' => 'Teszt ' . $uid,
        'datum' => current_time('mysql'), 'olvasott' => 0, 'letrehozva' => current_time('mysql'),
    ]);

    return (int) $wpdb->insert_id;
};

$ujra = static function () use ($L): void {
    // Kérésenként egyszer számol: a teszt egy „kérésen" belül többször kérdez.
    $tar = new ReflectionProperty($L, 'uj_tar');
    $tar->setAccessible(true);
    $tar->setValue(null, null);
};

// Régóta bent álló olvasatlan levelek: az első alkalom csak a kiindulópontot rögzíti.
$level('f1');
$level('f1');
$level('f2');

T::egyezik(['f1' => 0, 'f2' => 0], $L::uj_fiokonkent(), 'első alkalom: a régi olvasatlan levél nem „új"');
T::egyezik(3, $L::olvasatlan_db() + 3, 'az olvasatlan-számláló külön él (a mappa számlálójából)');

$j = $L::menu_jelzes();
T::igaz($j['db'] === 0 && $j['alsorok'] === [] && $j['cim'] === 'Nincs új levél', 'a menü jelzése üres');

// Új levél érkezik a második fiókba.
$ujra();
$level('f2');
$level('f2');
T::egyezik(['f1' => 0, 'f2' => 2], $L::uj_fiokonkent(), 'két új levél a második fiókban');

$j = $L::menu_jelzes();
T::igaz($j['db'] === 2 && $j['szoveg'] === '2 új', 'a menü jelvénye: 2 új');
T::igaz(count($j['alsorok']) === 1 && $j['alsorok'][0]['nev'] === 'Webshop' && $j['alsorok'][0]['szoveg'] === '2 új', 'több fióknál a menü megmondja, melyikbe jött');
T::tartalmaz((string) $j['alsorok'][0]['url'], 'fiok=f2', 'a fiók sora a fiók levelezőjére visz');
T::nem_tartalmaz((string) $j['alsorok'][0]['url'], 'wp-admin', 'a fiók sora nem visz a wp-adminba');
T::egyezik('Új levél: Webshop (2)', $j['cim'], 'a jelvény súgója felsorolja a fiókokat');

// Mindkét fiókba jön; a zaj és a már elolvasott levél nem számít.
$ujra();
$level('f1');
$level('f1', ['fontossag' => 'zaj']);
$level('f1', ['olvasott' => 1]);
T::egyezik(['f1' => 1, 'f2' => 2], $L::uj_fiokonkent(), 'a zajnak jelölt és az elolvasott levél nem új');
T::egyezik(3, $L::uj_db(), 'összesen három új levél');
T::egyezik(2, count($L::menu_jelzes()['alsorok']), 'két fiók sora látszik');

// Az oldalmenü kirajzolva.
ob_start();
SDH_Muhely_Frontend::keret('Teszt', 'attekintes', static function (): void {
});
$oldal = (string) ob_get_clean();

T::igaz(preg_match('/data-sdh-jelveny="levelezes" data-sdh-jelveny-mod="uj"\s+data-db="3"[^>]*>3 új</', $oldal) === 1, 'oldalmenü: a Levelezés jelvénye „3 új"');
T::nem_tartalmaz((string) substr($oldal, (int) strpos($oldal, 'data-sdh-jelveny="levelezes"'), 300), 'olvasatlan', 'oldalmenü: a Levelezés jelvénye nem az olvasatlanokról szól');
T::igaz(preg_match('/data-sdh-alsorok="levelezes">.*?Szerviz.*?1 új.*?Webshop.*?2 új/s', $oldal) === 1, 'oldalmenü: fiókonként egy sor a Levelezés alatt');

// A fiókok a böngészőnek: az `uj` mező viszi a számot (ebből frissít a levelezes.js).
$all = t_ajax('sdh_muhely_level_allapot', ['utolso' => '0']);
$uj  = [];

foreach ((array) ($all['data']['fiokok'] ?? []) as $f) {
    $uj[(string) $f['kulcs']] = (int) ($f['uj'] ?? -1);
}

T::egyezik(['f1' => 1, 'f2' => 2], $uj, 'az állapot-lekérdezés fiókonként hozza az új levelek számát');

// Megnézte a második fiók Beérkezett mappáját: ott már nincs új, az elsőben marad.
$L::latta_jelol('f2');
T::egyezik(['f1' => 1, 'f2' => 0], $L::uj_fiokonkent(), 'a megnézett fiókban nincs több új levél');
T::igaz(count($L::menu_jelzes()['alsorok']) === 1 && $L::menu_jelzes()['alsorok'][0]['kulcs'] === 'f1', 'a menüben csak a másik fiók sora marad');
T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$lt} WHERE fiok = 'f2' AND olvasott = 1"), 'a megnézés nem jelöli olvasottnak a leveleket'); // phpcs:ignore

// Egy fióknál nincs fióksor: elég a jelvény.
update_option('sdh_muhely_levelezes', ['fiokok' => ['f1' => $fiok('Szerviz', 'szerviz@example.com')]], false);
$ujra();
$j = $L::menu_jelzes();
T::igaz($j['db'] === 1 && $j['alsorok'] === [], 'egy fióknál csak a jelvény látszik');

// Más felhasználónak a saját megnézése számít.
$masik = wp_insert_user(['user_login' => 't33_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'administrator']);
$en    = get_current_user_id();
wp_set_current_user((int) $masik);
$ujra();
T::egyezik(['f1' => 0], $L::uj_fiokonkent(), 'másik felhasználó: nála most indul a számolás');
wp_set_current_user($en);
$ujra();
T::egyezik(['f1' => 1], $L::uj_fiokonkent(), 'az első felhasználó jelzése megmaradt');

// Takarítás: a tesztfiókok és leveleik nem maradnak a tesztoldalon.
$wpdb->query("DELETE FROM {$lt} WHERE fiok IN ('f1', 'f2')"); // phpcs:ignore
$wpdb->query("DELETE FROM {$mt} WHERE fiok IN ('f1', 'f2')"); // phpcs:ignore
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user((int) $masik);

if ($regi_level === null) {
    delete_option('sdh_muhely_levelezes');
} else {
    update_option('sdh_muhely_levelezes', $regi_level, false);
}

if (is_array($regi_latta)) {
    update_user_meta($en, 'sdh_muhely_level_latta', $regi_latta);
} else {
    delete_user_meta($en, 'sdh_muhely_level_latta');
}

$ujra();

/* ======================================================================== */
/* 9. Asszisztens: nincs a menüben, a Beállításokból nyílik                   */
/* ======================================================================== */

$modul = SDH_Muhely_Modulok::egy('asszisztens');
T::igaz($modul !== null && !empty($modul['rejtett']) && !empty($modul['csak_admin']), 'az Asszisztens rejtett, csak adminisztrátornak szóló modul');

$sav = (string) substr($oldal, (int) strpos($oldal, '<aside class="sdh-sav">'), (int) strpos($oldal, '</aside>') - (int) strpos($oldal, '<aside class="sdh-sav">'));
T::nem_tartalmaz($sav, 'muhely=asszisztens', 'oldalmenü: nincs Asszisztens menüpont');
T::nem_tartalmaz($sav, '>Asszisztens<', 'oldalmenü: nincs Asszisztens felirat');
T::tartalmaz($sav, 'muhely=beallitasok', 'oldalmenü: a Beállítások megvan');
T::tartalmaz($sav, 'muhely=arukiadas', 'oldalmenü: a többi menüpont megvan');
T::nem_tartalmaz($att, 'data-sdh-kartya="asszisztens"', 'Áttekintés: nincs Asszisztens csempe');
T::igaz(!isset(SDH_Muhely_Admin_UI::csempe_beallitas()['asszisztens']), 'a csempék beállításában sincs Asszisztens');

$_GET = ['ful' => 'asszisztens'];
ob_start();
SDH_Muhely_Beallitasok::oldal();
$beall = (string) ob_get_clean();
$_GET  = [];

T::tartalmaz($beall, 'data-sdh-asszisztens-oldalak', 'Beállítások › Asszisztens: innen nyílnak az asszisztens oldalai');
T::tartalmaz($beall, 'Javaslatok és napló', 'Beállítások › Asszisztens: Javaslatok és napló');
T::tartalmaz($beall, 'nezet=tudastar', 'Beállítások › Asszisztens: Tudástár');
T::tartalmaz($beall, 'nezet=mentesek', 'Beállítások › Asszisztens: Mentések');
T::nem_tartalmaz($beall, 'wp-admin/admin.php', 'Beállítások › Asszisztens: nem visz a wp-adminba');

// Az asszisztens oldala megvan (adminnak), és visszavezet a Beállításokhoz; az oldalmenüben ilyenkor a Beállítások az aktív.
ob_start();
SDH_Muhely_Asszisztens::oldal();
$ai = (string) ob_get_clean();
T::tartalmaz($ai, 'Vissza a Beállításokhoz', 'az asszisztens oldala visszavezet a Beállításokhoz');

ob_start();
SDH_Muhely_Frontend::keret('Asszisztens', 'asszisztens', static function (): void {
});
$ai_oldal = (string) ob_get_clean();
T::igaz(preg_match('/sdh-sav__link sdh-sav__link--aktiv"\s+href="[^"]*muhely=beallitasok/', $ai_oldal) === 1, 'az asszisztens oldalán a Beállítások menüpont az aktív');

// Az asszisztens tudása is tudja, hogy nincs a menüben.
T::tartalmaz(SDH_Muhely_Asszisztens_Tudas::statikus(), 'nincs a menüben', 'az asszisztens tudja magáról, hogy a Beállításokból nyílik');

/* ======================================================================== */
/* 4. A napi árukiadás a munkalapok rácsában                                 */
/* ======================================================================== */

$R = 'SDH_Muhely_Racs';

$r      = $ment(['fajta' => 'arukiadas', 'tetelek' => ['termek' => [$termek('Üvegfólia', 3490), $termek('Tok', 4990)]]]);
$nevtel = (int) ($r['data']['id'] ?? 0); // ügyfél nélküli árukiadás
$nyitott_db = $M::nyitott_db();

$sorok = [];

foreach ($R::adat($R::allapot_tisztit(['nezet' => 'mind', 'meret' => 200]))['sorok'] as $s) {
    $sorok[(int) $s['id']] = $s;
}

T::igaz(isset($sorok[$aid], $sorok[$nevtel], $sorok[$mid]), 'rács: az árukiadás a munkalapok között áll');
T::igaz(!isset($sorok[$rid]), 'rács: a rendelés nincs a munkalapok között');
T::egyezik('Napi árukiadás', (string) $sorok[$nevtel]['azonosito'], 'rács: az árukiadás az Azonosító oszlopban „Napi árukiadás"');
T::egyezik('Üvegfólia, Tok', (string) $sorok[$nevtel]['tipus'], 'rács: a Típus oszlopban az eladott tételek');
T::egyezik('Napi árukiadás', (string) $sorok[$nevtel]['ugyfel'], 'rács: ügyfél nélkül a vevő „Napi árukiadás"');
T::egyezik('Kártya Katalin', (string) $sorok[$aid]['ugyfel'], 'rács: ügyféllel az ügyfél neve áll');
T::igaz((string) $sorok[$nevtel]['szam'] !== '' && (float) $sorok[$nevtel]['brutto'] === 8480.0, 'rács: az árukiadás sorszáma és végösszege');
T::egyezik('arukiadas', (string) $sorok[$nevtel]['_fajta'], 'rács: a sor tudja, hogy árukiadás');
T::egyezik('javitas', (string) $sorok[$mid]['_fajta'], 'rács: a munkalap sora javítás');
T::egyezik('Telefon', (string) $sorok[$mid]['azonosito'], 'rács: a munkalap Azonosítója változatlanul az eszközfajta');

// Külön nézet: csak az árukiadás, illetve csak a javítás.
$idk = static fn (array $szuro): array => array_map(
    static fn (array $s): int => (int) $s['id'],
    $R::adat($R::allapot_tisztit(['meret' => 200, 'szurok' => ['azonosito' => $szuro]]))['sorok']
);

$csak_aru = $idk(['op' => 'egyike', 'l' => [$R::ARU_KATEGORIA]]);
T::igaz(in_array($nevtel, $csak_aru, true) && in_array($aid, $csak_aru, true) && !in_array($mid, $csak_aru, true), 'rács: „Napi árukiadás" nézet – csak az árukiadás');

$csak_jav = $idk(['op' => 'nem_egyike', 'l' => [$R::ARU_KATEGORIA]]);
T::igaz(in_array($mid, $csak_jav, true) && !in_array($nevtel, $csak_jav, true), 'rács: „Csak javítás" nézet – árukiadás nélkül');

$csak_tel = $idk(['op' => 'egyike', 'l' => ['telefon']]);
T::igaz(in_array($mid, $csak_tel, true) && !in_array($nevtel, $csak_tel, true), 'rács: az eszközfajta szűrője változatlanul működik');

ob_start();
$R::megjelenit();
$racs_html = (string) ob_get_clean();
T::tartalmaz($racs_html, 'Csak jav', 'rács: a nézetek között ott a „Csak javítás"');
T::igaz(preg_match('/id(&quot;|"):(&quot;|")arukiadas(&quot;|")/', $racs_html) === 1, 'rács: a nézetek között ott a „Napi árukiadás"');

// A részletpanel az árukiadás sorára is megnyílik; a nyitott munkalapok számlálója a javításoké marad.
$r = t_ajax('sdh_muhely_racs_reszlet', ['id' => $nevtel]);
T::igaz(!empty($r['success']), 'rács: az árukiadás részletei megnyílnak');
T::tartalmaz((string) wp_json_encode($r['data'] ?? []), 'vegf', 'rács: a részletekben ott vannak a tételek');
T::egyezik($nyitott_db, $M::nyitott_db(), 'a nyitott munkalapok számlálója változatlan');

$elotte = $M::nyitott_db();
$ment(['fajta' => 'arukiadas', 'tetelek' => ['termek' => [$termek('Kábel', 1990)]]]);
T::egyezik($elotte, $M::nyitott_db(), 'az új árukiadás nem növeli a nyitott munkalapok számát');

restore_error_handler();
T::egyezik([], $figy, 'nincs PHP-figyelmeztetés');

T::vege('t33 ügyfélkártya, listás állapot, óra, új levél, asszisztens');
