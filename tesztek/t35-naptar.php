<?php
/**
 * t35 – 0.50: Naptár. Nevenkénti naptárak (közös fiók és profilonként),
 * az esemény mentése, ismétlődés, a nézetek adatai, áthelyezés, törlés,
 * a rendszerbeli kártyák (addig maradnak, amíg ki nem nyomják), e-mail
 * értesítés, napi összefoglaló, privát esemény, beállítások.
 */

require __DIR__ . '/_keret.php';

$N = 'SDH_Muhely_Naptar';

global $wpdb;

$figy = [];
set_error_handler(static function (int $szint, string $szoveg, string $fajl, int $sor) use (&$figy): bool {
    if (!(error_reporting() & $szint)) {
        return true; // @-tal elnémított hiba (pl. gyorsítótár törlése): nem számít
    }
    if (strpos($fajl, 'sdh-muhely') !== false) {
        $figy[] = basename($fajl) . ':' . $sor . ' ' . $szoveg;
    }

    return true;
});

SDH_Muhely_Modulok::kontextus_beallit('frontend');

// A kiküldött levelek ide gyűlnek – a hálózatra semmi nem megy ki.
$levelek = [];
add_filter('pre_wp_mail', static function ($null, array $atts) use (&$levelek) {
    $levelek[] = ['cim' => is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'], 'targy' => (string) $atts['subject'], 'torzs' => (string) $atts['message']];

    return true;
}, 10, 2);

$regi_csapat = get_option('sdh_muhely_csapat');
$regi_naptar = get_option('sdh_muhely_naptar');

$urit = static function () use ($wpdb, $N): void {
    foreach (['szemely', 'esemeny', 'jelzes'] as $t) {
        $wpdb->query('DELETE FROM ' . SDH_Muhely_Schema::tabla('naptar_' . $t));
    }

    delete_option('sdh_muhely_naptar_napi');
    $N::szemelyek_frissit();
};

$tz   = wp_timezone();
$most = new DateTimeImmutable(current_time('mysql'), $tz);
$nap  = static fn (int $d): string => $most->modify(($d >= 0 ? '+' : '') . $d . ' days')->format('Y-m-d');
$ido  = static fn (string $d, string $k, string $v, ?string $vd = null): array => [
    'kezdet_datum' => $d, 'veg_datum' => $vd ?? $d,
    'kezdet_ora' => substr($k, 0, 2), 'kezdet_perc' => substr($k, 3, 2), 'veg_ora' => substr($v, 0, 2), 'veg_perc' => substr($v, 3, 2),
];
$alk = static fn (object $e, string $tol, string $ig): array => array_map(static fn (array $a): string => $a[0], $N::alkalmak($e, $tol . ' 00:00:00', $ig . ' 00:00:00'));
$esemeny_sor = static fn (int $id): object => $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('naptar_esemeny') . ' WHERE id = %d', $id));

/* ======================================================================== */
/* 1. Séma, modul, oldal                                                     */
/* ======================================================================== */

T::igaz(version_compare(SDH_Muhely_Schema::DB_VERSION, '0.36.0', '>='), 'sémaverzió legalább 0.36.0');

foreach (['szemely', 'esemeny', 'jelzes'] as $t) {
    $tabla = SDH_Muhely_Schema::tabla('naptar_' . $t);
    T::igaz($wpdb->get_var("SHOW TABLES LIKE '{$tabla}'") === $tabla, 'megvan a tábla: naptar_' . $t);
}

$modul = SDH_Muhely_Modulok::egy('naptar');
T::igaz($modul !== null && $modul['cim'] === 'Naptár', 'a Naptár modul be van jegyezve');
T::igaz(SDH_Muhely_Frontend::ikon_rajz('naptar', null) !== '', 'van saját menüikonja');
T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['naptar']), 'Beállítások: van Naptár lapfül');
T::egyezik(12, count($N::NEZETEK), 'tizenkét nézet');

/* ======================================================================== */
/* 2. Közös fiók (egyfelhasználós mód): nevek, mindegyikhez saját naptár      */
/* ======================================================================== */

update_option('sdh_muhely_csapat', ['mod' => 'single', 'fal_nev' => 'Üzenőfal', 'nevek' => "Norbi\nPéter"]);
$urit();

T::igaz($N::egyszeru(), 'közös fiókos mód');
$sz = array_values($N::szemelyek());
T::egyezik(['Norbi', 'Péter'], array_column($sz, 'nev'), 'az első megnyitáskor a Csapat névlistájából lesznek a naptárak');
T::egyezik([0, 0], array_column($sz, 'user_id'), 'a neveknek nincs fiókjuk');
T::egyezik(0, $N::sajat_szemely_id(), 'közös fióknál nincs „saját" naptár');

$dia = $N::szemely_ment(0, ['nev' => 'Dia', 'email' => 'dia@example.com', 'szin' => 'lila']);
T::igaz(is_int($dia) && $dia > 0, 'új név felvehető (7.1) – saját naptárral');
T::egyezik(3, count($N::szemelyek()), 'három név, három naptár');
T::egyezik('Ilyen nevű naptár már van.', $N::szemely_ment(0, ['nev' => 'dia']), 'azonos név kétszer nem vehető fel');
T::egyezik('Add meg a nevet.', $N::szemely_ment(0, ['nev' => '  ']), 'név nélkül nincs naptár');
T::egyezik('Ez nem érvényes e-mail-cím.', $N::szemely_ment(0, ['nev' => 'Hibás', 'email' => 'nem-cim']), 'hibás e-mail-cím elutasítva');
T::igaz($N::szemelyek()[$dia]['sorrend'] > 0, 'az új név a lista végére kerül');

$marco = $N::szemely_ment(0, ['nev' => 'Marco']);
$N::szemely_ment($marco, ['nev' => 'Marco']); // aktív jelölő nélkül: inaktív lesz
T::igaz(!isset($N::szemelyek()[$marco]), 'az inaktív név eltűnik a listából');
T::igaz(isset($N::szemelyek(true)[$marco]), '…de megmarad (az eseményeivel együtt)');

$r = t_ajax('sdh_muhely_naptarszemely_ment', ['id' => 0, 'nev' => 'Műhely', 'szin' => 'turkiz', 'aktiv' => 1]);
T::igaz(!empty($r['success']), 'név felvétele a felületről');
$urlap = t_ajax('sdh_muhely_naptarszemely_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0])['nyers'];
T::tartalmaz($urlap, 'data-sdh-ajax-action="sdh_muhely_naptarszemely_ment"', 'a név űrlapja popupban nyílik');

[$norbi, $peter] = [$sz[0]['id'], $sz[1]['id']];

/* ======================================================================== */
/* 3. Esemény mentése                                                        */
/* ======================================================================== */

T::egyezik('Add meg az esemény megnevezését.', $N::ment(0, ['cim' => '', 'szemely_id' => $norbi] + $ido($nap(1), '10:00', '11:00')), 'megnevezés nélkül nincs esemény');
T::egyezik('Válaszd ki, kinek a naptárába kerüljön.', $N::ment(0, ['cim' => 'X', 'szemely_id' => 999999] + $ido($nap(1), '10:00', '11:00')), 'ismeretlen név elutasítva');
T::egyezik('Add meg a kezdés napját.', $N::ment(0, ['cim' => 'X', 'szemely_id' => $norbi, 'kezdet_datum' => '2026-13-45']), 'hibás dátum elutasítva');

$e1_mezok = [
    'cim' => 'Megbeszélés', 'szemely_id' => $norbi, 'resztvevok' => [$peter, $norbi, 999], 'hely' => 'Tárgyaló', 'leiras' => "Első sor\nMásodik sor",
    'kategoria' => 'megbeszeles', 'prioritas' => 'fontos', 'allapot' => 'tervezett', 'emlekeztetok' => [60, 15, 7], 'ertesites' => 1, 'email' => 1,
    'munkalap_szam' => '2026/15', 'link' => 'https://example.com/x', 'szin' => 'lila', 'privat' => 1,
] + $ido($nap(1), '10:00', '11:30');
$e1 = $N::ment(0, $e1_mezok);

T::igaz(is_int($e1) && $e1 > 0, 'esemény elmentve');
$s = $esemeny_sor($e1);
T::egyezik($nap(1) . ' 10:00:00', (string) $s->kezdet, 'kezdet');
T::egyezik($nap(1) . ' 11:30:00', (string) $s->veg, 'vég');
T::egyezik((string) $peter, (string) $s->resztvevok, 'résztvevők: csak létező név, a gazda nélkül');
T::egyezik('15,60', (string) $s->emlekeztetok, 'emlékeztetők: csak a listából, sorban');
T::egyezik('megbeszeles', (string) $s->kategoria, 'kategória');
T::egyezik('fontos', (string) $s->prioritas, 'fontosság');
T::egyezik('lila', (string) $s->szin, 'saját szín');
T::egyezik('2026/15', (string) $s->munkalap_szam, 'munkalap száma');
T::egyezik(0, (int) $s->privat, 'közös fióknál nincs privát esemény');
T::tartalmaz((string) $s->leiras, "Első sor\nMásodik sor", 'a leírás sortörése megmarad');

// Ügyfélhez kötött esemény a nézetben (0.99.5: a nézet egy belső függvényt hívott → végzetes hiba).
$e_ugyfel = t_ugyfel('Naptár Ügyfél');
$e_u      = $N::ment(0, ['cim' => 'Ügyfél-időpont', 'szemely_id' => $norbi] + $ido($nap(2), '09:00', '09:30'));
$wpdb->update(SDH_Muhely_Schema::tabla('naptar_esemeny'), ['ugyfel_id' => $e_ugyfel], ['id' => $e_u]);
$nezet = $N::adat($nap(2), $nap(3));
$talalt = array_values(array_filter((array) ($nezet['esemenyek'] ?? []), static fn ($a) => (int) ($a['id'] ?? 0) === (int) $e_u));
T::igaz($talalt !== [] && strpos((string) ($talalt[0]['ugyfel'] ?? ''), 'Naptár Ügyfél') === 0, 'ügyfélhez kötött esemény: a nézetben az ügyfél neve');
$wpdb->delete(SDH_Muhely_Schema::tabla('naptar_esemeny'), ['id' => $e_u]);

$e2 = $N::ment(0, ['cim' => 'Szabadság', 'szemely_id' => $peter, 'egesz_nap' => 1, 'kezdet_datum' => $nap(3), 'veg_datum' => $nap(5)]);
$s2 = $esemeny_sor($e2);
T::egyezik($nap(3) . ' 00:00:00', (string) $s2->kezdet, 'egész nap: a nap elejétől');
T::egyezik($nap(5) . ' 23:59:59', (string) $s2->veg, 'egész nap: az utolsó nap végéig');

$e3 = $N::ment(0, ['cim' => 'Rossz vég', 'szemely_id' => $norbi] + $ido($nap(1), '14:00', '13:00'));
T::egyezik($nap(1) . ' 15:00:00', (string) $esemeny_sor($e3)->veg, 'a kezdet előtti vég helyett egy órás esemény');

$r = t_ajax('sdh_muhely_naptar_ment', ['id' => 0, 'cim' => 'Felületről', 'szemely_id' => $norbi, 'kontextus' => 'frontend'] + $ido($nap(2), '09:00', '09:30'));
T::igaz(!empty($r['success']) && (int) $r['data']['id'] > 0, 'mentés a felületről');
T::tartalmaz((string) $r['data']['vissza'], 'naptar', 'mentés után a naptárhoz tér vissza');
$e4 = (int) $r['data']['id'];

T::egyezik($e1, $N::ment($e1, ['cim' => 'Megbeszélés (átírva)'] + $e1_mezok), 'módosítás');
T::egyezik('Megbeszélés (átírva)', (string) $esemeny_sor($e1)->cim, 'a módosítás elmentve');

$urlap = t_ajax('sdh_muhely_naptar_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $e1])['nyers'];
T::tartalmaz($urlap, 'data-sdh-ajax-action="sdh_muhely_naptar_ment"', 'az esemény űrlapja popupban nyílik');
T::tartalmaz($urlap, 'data-sdh-pop="datum"', 'a dátum a rendszer saját választójával megy (nem natív)');
T::nem_tartalmaz($urlap, 'type="date"', 'nincs natív dátummező');
T::nem_tartalmaz($urlap, 'type="time"', 'nincs natív időmező');
T::tartalmaz($urlap, 'name="ismetles"', 'ismétlődés állítható');
T::tartalmaz($urlap, 'name="emlekeztetok[]"', 'több emlékeztető választható');
T::tartalmaz($urlap, 'name="email"', 'e-mail értesítés megjelölhető (7.3)');
T::tartalmaz($urlap, 'name="ertesites"', 'rendszerbeli értesítés kapcsolható (7.4)');
T::tartalmaz($urlap, 'data-sdh-np-torol="mind"', 'törölhető');
T::nem_tartalmaz($urlap, 'name="privat"', 'közös fióknál nincs privát jelölő');

$uj_urlap = t_ajax('sdh_muhely_naptar_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0, 'datum' => $nap(4), 'ido' => '13:30', 'szemely' => $peter])['nyers'];
T::tartalmaz($uj_urlap, 'value="' . $nap(4) . '"', 'új esemény: a rács cellájának napja előtöltve');
T::igaz(preg_match('/name="kezdet_ora".*?<option value="13" selected/s', $uj_urlap) === 1, 'új esemény: a kattintott időpont előtöltve');
T::igaz(preg_match('/name="szemely_id" value="' . $peter . '" checked/', $uj_urlap) === 1, 'új esemény: a kattintott név naptára kijelölve');

/* ======================================================================== */
/* 4. Ismétlődés                                                             */
/* ======================================================================== */

$ism = static fn (array $mezok): object => (object) ($mezok + [
    'kezdet' => '2026-01-05 09:00:00', 'veg' => '2026-01-05 10:00:00', 'ismetles' => '', 'ism_koz' => 1, 'ism_napok' => '', 'ism_veg' => null, 'ism_db' => 0, 'kivetelek' => '',
]);

T::egyezik(['2026-01-05 09:00:00'], $alk($ism([]), '2026-01-01', '2026-02-01'), 'nem ismétlődő: egy alkalom');
T::egyezik([], $alk($ism([]), '2026-02-01', '2026-03-01'), 'nem ismétlődő: az időszakon kívül nincs');

$napi = $alk($ism(['ismetles' => 'napi', 'ism_koz' => 2]), '2026-01-05', '2026-01-12');
T::egyezik(['2026-01-05 09:00:00', '2026-01-07 09:00:00', '2026-01-09 09:00:00', '2026-01-11 09:00:00'], $napi, 'napi, minden második nap');

T::egyezik(3, count($alk($ism(['ismetles' => 'napi', 'ism_db' => 3]), '2026-01-01', '2026-12-31')), 'alkalomszám: pontosan három');
T::egyezik(['2026-01-05 09:00:00', '2026-01-06 09:00:00'], $alk($ism(['ismetles' => 'napi', 'ism_veg' => '2026-01-06']), '2026-01-01', '2026-12-31'), 'végdátumig (a végnap is beleszámít)');
T::egyezik(['2026-01-05 09:00:00', '2026-01-07 09:00:00'], $alk($ism(['ismetles' => 'napi', 'ism_db' => 3, 'kivetelek' => '2026-01-06']), '2026-01-01', '2026-12-31'), 'kihagyott alkalom nem jelenik meg (de beleszámít a darabba)');

// 2026-01-05 hétfő.
$heti = $alk($ism(['ismetles' => 'heti', 'ism_napok' => '1,3,5']), '2026-01-05', '2026-01-19');
T::egyezik(['2026-01-05', '2026-01-07', '2026-01-09', '2026-01-12', '2026-01-14', '2026-01-16'], array_map(static fn (string $a): string => substr($a, 0, 10), $heti), 'heti: hétfő, szerda, péntek');
T::egyezik(['2026-01-05', '2026-01-19'], array_map(static fn (string $a): string => substr($a, 0, 10), $alk($ism(['ismetles' => 'heti', 'ism_koz' => 2]), '2026-01-01', '2026-02-01')), 'kéthetente, a kezdés napján');
T::egyezik(['2026-01-07', '2026-01-12'], array_map(static fn (string $a): string => substr($a, 0, 10), $alk($ism(['kezdet' => '2026-01-07 09:00:00', 'veg' => '2026-01-07 10:00:00', 'ismetles' => 'heti', 'ism_napok' => '1,3']), '2026-01-01', '2026-01-14')), 'heti: a kezdés előtti nap az első héten kimarad');

$havi = $alk($ism(['kezdet' => '2026-01-31 08:00:00', 'veg' => '2026-01-31 09:00:00', 'ismetles' => 'havi']), '2026-01-01', '2026-06-01');
T::egyezik(['2026-01-31', '2026-03-31', '2026-05-31'], array_map(static fn (string $a): string => substr($a, 0, 10), $havi), 'havi, 31-én: a rövidebb hónapok kimaradnak');

$eves = $alk($ism(['kezdet' => '2024-02-29 08:00:00', 'veg' => '2024-02-29 09:00:00', 'ismetles' => 'eves']), '2024-01-01', '2029-01-01');
T::egyezik(['2024-02-29', '2028-02-29'], array_map(static fn (string $a): string => substr($a, 0, 10), $eves), 'éves, február 29.: csak szökőévben');

$tavoli = $alk($ism(['kezdet' => '2015-03-02 09:00:00', 'veg' => '2015-03-02 10:00:00', 'ismetles' => 'napi']), '2026-06-01', '2026-06-04');
T::egyezik(['2026-06-01 09:00:00', '2026-06-02 09:00:00', '2026-06-03 09:00:00'], $tavoli, 'régi sorozat: a távoli időszak alkalmai is megvannak');
T::egyezik(['2026-06-01', '2026-06-08'], array_map(static fn (string $a): string => substr($a, 0, 10), $alk($ism(['kezdet' => '2015-03-02 09:00:00', 'veg' => '2015-03-02 10:00:00', 'ismetles' => 'heti']), '2026-06-01', '2026-06-15')), 'régi heti sorozat a hét napján marad');
T::egyezik(['2026-06-02'], array_map(static fn (string $a): string => substr($a, 0, 10), $alk($ism(['kezdet' => '2015-03-02 09:00:00', 'veg' => '2015-03-02 10:00:00', 'ismetles' => 'havi']), '2026-06-01', '2026-07-01')), 'régi havi sorozat a hónap napján marad');

$tobbnapos = $N::alkalmak($ism(['kezdet' => '2026-01-05 22:00:00', 'veg' => '2026-01-06 02:00:00', 'ismetles' => 'napi']), '2026-01-08 00:00:00', '2026-01-09 00:00:00');
T::egyezik(['2026-01-07 22:00:00', '2026-01-08 22:00:00'], array_column($tobbnapos, 0), 'éjfélen átnyúló alkalom az előző napról is belelóg');
T::egyezik('2026-01-08 02:00:00', $tobbnapos[0][1], 'az alkalom hossza megmarad');

$heti_id = $N::ment(0, ['cim' => 'Heti megbeszélés', 'szemely_id' => $norbi, 'ismetles' => 'heti', 'ism_napok' => ['2', '4', '9'], 'ism_koz' => 1, 'ism_mod' => 'db', 'ism_db' => '6'] + $ido($nap(0), '08:00', '08:30'));
$hs = $esemeny_sor($heti_id);
T::egyezik('heti', (string) $hs->ismetles, 'ismétlődő esemény elmentve');
T::egyezik('2,4', (string) $hs->ism_napok, 'a hét napjai: csak 1–7');
T::egyezik(6, (int) $hs->ism_db, 'alkalomszám elmentve');
T::igaz($hs->ism_veg === null, 'alkalomszámnál nincs végdátum');

/* ======================================================================== */
/* 5. A nézetek adatai                                                       */
/* ======================================================================== */

$r = t_ajax('sdh_muhely_naptar_adat', ['tol' => $nap(0), 'ig' => $nap(7)]);
T::igaz(!empty($r['success']), 'a nézetek adatai lekérhetők');
$d = (array) $r['data'];
$cimek = array_column($d['esemenyek'], 'cim');
T::igaz(in_array('Megbeszélés (átírva)', $cimek, true) && in_array('Szabadság', $cimek, true), 'az időszak eseményei megjönnek');
T::igaz(count($d['szemelyek']) >= 3, 'a nevek megjönnek');
T::igaz(isset($d['kategoriak']['megbeszeles']), 'a kategóriák megjönnek');
T::igaz(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', (string) $d['most']) === 1, 'a szerver ideje megjön (a visszaszámláló ebből dolgozik – 7.8)');

$elso = null;

foreach ($d['esemenyek'] as $x) {
    if ((int) $x['id'] === $e1) {
        $elso = $x;
    }
}

T::egyezik($nap(1) . 'T10:00', (string) $elso['k'], 'alkalom kezdete');
T::egyezik('lila', (string) $elso['szin'], 'a saját szín elsőbbséget élvez');
T::igaz($elso['email'] === true, 'látszik, hogy e-mail megy róla');
T::igaz($elso['szerk'] === true, 'közös fióknál minden szerkeszthető');
T::egyezik([$peter], $elso['resz'], 'a résztvevők megjönnek');

$csak_peter = (array) t_ajax('sdh_muhely_naptar_adat', ['tol' => $nap(0), 'ig' => $nap(7), 'szemelyek' => (string) $peter])['data'];
$pc = array_column($csak_peter['esemenyek'], 'cim');
T::igaz(in_array('Szabadság', $pc, true), 'névre szűrve: a saját naptára');
T::igaz(in_array('Megbeszélés (átírva)', $pc, true), 'névre szűrve: ahol résztvevő, az is');
T::igaz(!in_array('Rossz vég', $pc, true), 'névre szűrve: másé nem');

T::igaz(empty(t_ajax('sdh_muhely_naptar_adat', ['tol' => $nap(3), 'ig' => $nap(1)])['success']), 'fordított időszak elutasítva');
T::igaz(count($d['kovetkezo']) >= 1 && $d['kovetkezo'][0]['k'] >= str_replace(' ', 'T', substr(current_time('mysql'), 0, 16)) || $d['kovetkezo'][0]['v'] >= str_replace(' ', 'T', substr(current_time('mysql'), 0, 16)), 'a következő események (visszaszámláláshoz) megjönnek');

$regi = $N::ment(0, ['cim' => 'Lemaradt feladat', 'szemely_id' => $norbi] + $ido($nap(-3), '10:00', '11:00'));
$ma_adat = $N::adat($nap(0), $nap(2), [], true);
T::igaz(in_array('Lemaradt feladat', array_column($ma_adat['lemaradt'], 'cim'), true), '„Ma": a lejárt, le nem zárt esemény a lemaradtak között');
T::igaz(isset($ma_adat['levelek']) && is_array($ma_adat['levelek']), '„Ma": a fontos levelek listája megvan');

t_ajax('sdh_muhely_naptar_allapot', ['id' => $regi, 'allapot' => 'kesz']);
T::egyezik('kesz', (string) $esemeny_sor($regi)->allapot, 'gyors állapotváltás (Tábla nézet, „Kész" pipa)');
T::igaz(!in_array('Lemaradt feladat', array_column($N::adat($nap(0), $nap(2), [], true)['lemaradt'], 'cim'), true), 'a kész esemény már nem lemaradt');

/* ======================================================================== */
/* 6. Áthelyezés húzással, törlés                                            */
/* ======================================================================== */

$r = t_ajax('sdh_muhely_naptar_athelyez', ['id' => $e4, 'kezdet' => $nap(4) . 'T15:15', 'veg' => $nap(4) . 'T16:00', 'szemely' => $peter]);
T::igaz(!empty($r['success']), 'esemény áthelyezve');
$s4 = $esemeny_sor($e4);
T::egyezik($nap(4) . ' 15:15:00', (string) $s4->kezdet, 'új kezdet');
T::egyezik($nap(4) . ' 16:00:00', (string) $s4->veg, 'új vég (átméretezve)');
T::egyezik($peter, (int) $s4->szemely_id, 'másik név naptárába húzva');

T::igaz(empty(t_ajax('sdh_muhely_naptar_athelyez', ['id' => $heti_id, 'kezdet' => $nap(4) . 'T15:15', 'veg' => $nap(4) . 'T16:00'])['success']), 'ismétlődő sorozat húzással nem mozdítható');
T::igaz(empty(t_ajax('sdh_muhely_naptar_athelyez', ['id' => $e4, 'kezdet' => 'holnap', 'veg' => 'holnap'])['success']), 'hibás időpont elutasítva');

$elotte = count($N::alkalmak($esemeny_sor($heti_id), $nap(0) . ' 00:00:00', $nap(40) . ' 00:00:00'));
$elso_alk = substr($N::alkalmak($esemeny_sor($heti_id), $nap(0) . ' 00:00:00', $nap(40) . ' 00:00:00')[0][0], 0, 10);
t_ajax('sdh_muhely_naptar_torol', ['id' => $heti_id, 'mod' => 'alkalom', 'alkalom' => $elso_alk]);
T::egyezik($elotte - 1, count($N::alkalmak($esemeny_sor($heti_id), $nap(0) . ' 00:00:00', $nap(40) . ' 00:00:00')), 'ismétlődőnél egyetlen alkalom is törölhető');
T::egyezik(0, (int) $esemeny_sor($heti_id)->torolve, '…a sorozat megmarad');

t_ajax('sdh_muhely_naptar_torol', ['id' => $e3, 'mod' => 'mind']);
T::egyezik(1, (int) $esemeny_sor($e3)->torolve, 'törlés: jelölés (az adat megmarad)');
T::igaz($N::esemeny($e3) === null, 'a törölt esemény nem látszik');

/* ======================================================================== */
/* 7. Kártya a rendszerben: addig marad, amíg ki nem nyomják (7.4)            */
/* ======================================================================== */

$urit();
update_option('sdh_muhely_csapat', ['mod' => 'single', 'fal_nev' => 'Üzenőfal', 'nevek' => "Norbi\nPéter"]);
$N::szemelyek_frissit();
[$norbi, $peter] = array_column(array_values($N::szemelyek()), 'id');
$N::szemely_ment($norbi, ['nev' => 'Norbi', 'email' => 'norbi@example.com', 'aktiv' => 1]);

$T0     = $most->modify('+3 hours')->setTime((int) $most->modify('+3 hours')->format('H'), 0);
$ekkor  = static fn (int $perc): string => $T0->modify(($perc >= 0 ? '+' : '') . $perc . ' minutes')->format('Y-m-d H:i:s');
$jelzett = $N::ment(0, ['cim' => 'Visszahívás', 'szemely_id' => $norbi, 'resztvevok' => [$peter], 'emlekeztetok' => [15], 'ertesites' => 1]
    + $ido($T0->format('Y-m-d'), $T0->format('H:i'), $T0->modify('+30 minutes')->format('H:i'), $T0->modify('+30 minutes')->format('Y-m-d')));

T::egyezik(0, $N::jelzesek_general($ekkor(-20)), '20 perccel előtte még nincs kártya');
T::egyezik([], $N::nyitott_jelzesek(), '…és a lista üres');

T::egyezik(2, $N::jelzesek_general($ekkor(-10)), 'az emlékeztető idején kártya készül a gazdának és a résztvevőnek');
$nyitott = $N::nyitott_jelzesek();
T::egyezik(2, count($nyitott), 'közös fióknál mindkét név kártyája látszik');
T::egyezik('Visszahívás', (string) $nyitott[0]['cim'], 'a kártyán az esemény neve');
T::egyezik(['Norbi', 'Péter'], array_column($nyitott, 'szemely'), 'a kártyán a név, akinek szól');
T::egyezik($T0->format('Y-m-d\TH:i'), (string) $nyitott[0]['k'], 'a kártyán a kezdés ideje (visszaszámláláshoz)');
T::egyezik(2, $N::nyitott_jelzes_db(), 'a menü jelvénye a nyitott kártyák száma');

T::egyezik(0, $N::jelzesek_general($ekkor(-5)), 'újrafuttatva nem készül másodpéldány');
T::egyezik(2, count($N::nyitott_jelzesek()), 'a kártya magától nem tűnik el');

$N::jelzesek_general($ekkor(1));
$nyitott = $N::nyitott_jelzesek();
T::egyezik(2, count($nyitott), 'kezdéskor új kártya lép a régi helyére – alkalmanként és nevenként egy');
T::egyezik([0, 0], array_column($nyitott, 'perc'), 'a nyitott kártya a kezdéskori');

$N::jelzesek_general($ekkor(60 * 30));
T::egyezik(2, count($N::nyitott_jelzesek()), 'másfél nap múlva is ott van: csak kézzel nyomható ki');

$r = t_ajax('sdh_muhely_naptar_kinyom', ['id' => $nyitott[0]['id']]);
T::igaz(!empty($r['success']) && (int) $r['data']['db'] === 1, 'egy kártya kinyomva');
$N::jelzesek_general($ekkor(5));
T::egyezik(1, count($N::nyitott_jelzesek()), 'a kinyomott kártya nem jön vissza');

$r = t_ajax('sdh_muhely_naptar_kinyom', ['mind' => '1']);
T::egyezik(0, (int) $r['data']['db'], '„Mind kinyomása"');

$pulzus = apply_filters('sdh_muhely_pulzus', ['olvasatlan' => 0], get_current_user_id());
T::igaz(isset($pulzus['naptar']['jelzesek']) && is_array($pulzus['naptar']['jelzesek']), 'a pulzus viszi a kártyákat (minden oldalon megjelennek)');
T::igaz(isset($pulzus['naptar']['most']), 'a pulzus viszi a szerver idejét');

// Kész esemény: nincs kártya; értesítés nélkül: nincs kártya; utólag felvett: nincs kártya.
$kesz = $N::ment(0, ['cim' => 'Kész már', 'szemely_id' => $norbi, 'allapot' => 'kesz', 'ertesites' => 1] + $ido($T0->format('Y-m-d'), $T0->format('H:i'), $T0->modify('+30 minutes')->format('H:i'), $T0->modify('+30 minutes')->format('Y-m-d')));
$nema = $N::ment(0, ['cim' => 'Néma', 'szemely_id' => $norbi] + $ido($T0->format('Y-m-d'), $T0->format('H:i'), $T0->modify('+30 minutes')->format('H:i'), $T0->modify('+30 minutes')->format('Y-m-d')));
$utolag = $N::ment(0, ['cim' => 'Utólag felvett', 'szemely_id' => $norbi, 'ertesites' => 1] + $ido($nap(-1), '08:00', '09:00'));
$N::jelzesek_general($ekkor(10));
T::egyezik([], array_column($N::nyitott_jelzesek(), 'cim'), 'kész, értesítés nélküli és utólag felvett eseményről nincs kártya');

// Lezárva a nyitott kártya is eltűnik.
$zarando = $N::ment(0, ['cim' => 'Zárandó', 'szemely_id' => $norbi, 'ertesites' => 1] + $ido($T0->format('Y-m-d'), $T0->format('H:i'), $T0->modify('+30 minutes')->format('H:i'), $T0->modify('+30 minutes')->format('Y-m-d')));
$N::jelzesek_general($ekkor(2));
T::egyezik(['Zárandó'], array_column($N::nyitott_jelzesek(), 'cim'), 'esedékes esemény kártyája');
t_ajax('sdh_muhely_naptar_allapot', ['id' => $zarando, 'allapot' => 'kesz']);
T::egyezik([], $N::nyitott_jelzesek(), 'késznek jelölve a kártyája is lekerül');

/* ======================================================================== */
/* 8. E-mail értesítés (7.3)                                                 */
/* ======================================================================== */

$wpdb->query('DELETE FROM ' . SDH_Muhely_Schema::tabla('naptar_jelzes'));
$wpdb->query('DELETE FROM ' . SDH_Muhely_Schema::tabla('naptar_esemeny'));
update_option('sdh_muhely_naptar', ['email_be' => 1, 'kozos_email' => '']);
$levelek = [];

$mikor  = $ido($T0->format('Y-m-d'), $T0->format('H:i'), $T0->modify('+30 minutes')->format('H:i'), $T0->modify('+30 minutes')->format('Y-m-d'));
$jelolt = $N::ment(0, ['cim' => 'Megjelölt esemény', 'szemely_id' => $norbi, 'resztvevok' => [$peter], 'email' => 1, 'emlekeztetok' => [60, 15], 'hely' => 'Iroda', 'leiras' => 'Hozd a papírokat.'] + $mikor);
$sima   = $N::ment(0, ['cim' => 'Jelöletlen esemény', 'szemely_id' => $norbi, 'emlekeztetok' => [15]] + $mikor);

$N::jelzesek_general($ekkor(-90));
T::egyezik(0, $N::levelek_kuld($ekkor(-90)), 'az első emlékeztető előtt nem megy levél');

$N::jelzesek_general($ekkor(-50));
T::egyezik(1, $N::levelek_kuld($ekkor(-50)), 'a megjelölt eseményről levél megy az első emlékeztető idején');
T::egyezik('norbi@example.com', (string) $levelek[0]['cim'], 'a naptár gazdájának címére');
T::tartalmaz((string) $levelek[0]['targy'], 'Megjelölt esemény', 'a tárgyban az esemény neve');
T::tartalmaz((string) $levelek[0]['torzs'], 'Hol: Iroda', 'a levélben a helyszín');
T::tartalmaz((string) $levelek[0]['torzs'], 'Hozd a papírokat.', 'a levélben a leírás');
T::tartalmaz((string) $levelek[0]['torzs'], 'Résztvevők: Péter', 'a levélben a résztvevők');
T::tartalmaz((string) $levelek[0]['torzs'], 'muhely=naptar', 'a levélben link a naptárhoz');
T::nem_tartalmaz(implode(' ', array_column($levelek, 'targy')), 'Jelöletlen', 'jelöletlen eseményről nem megy levél');

$N::jelzesek_general($ekkor(-10));
T::egyezik(0, $N::levelek_kuld($ekkor(-10)), 'egy alkalomról egy levél megy – a második emlékeztetőnél nem megy újra');
T::egyezik(1, count($levelek), 'Péternek nincs címe és közös cím sincs: neki nem ment levél');

// „Nagyon fontos": jelölés nélkül is megy; a közös cím a cím nélküli névé.
update_option('sdh_muhely_naptar', ['email_be' => 1, 'kozos_email' => 'iroda@example.com']);
$levelek = [];
$kritikus = $N::ment(0, ['cim' => 'Szerver leállás', 'szemely_id' => $peter, 'prioritas' => 'kritikus'] + $mikor);
$N::jelzesek_general($ekkor(1));
T::egyezik(1, $N::levelek_kuld($ekkor(1)), 'a „Nagyon fontos" eseményről jelölés nélkül is megy levél');
T::egyezik('iroda@example.com', (string) $levelek[0]['cim'], 'cím nélküli névnél a közös értesítő címre');
T::tartalmaz((string) $levelek[0]['targy'], 'NAGYON FONTOS', 'a tárgy jelzi a fontosságot');

// Kikapcsolva semmi nem megy.
update_option('sdh_muhely_naptar', ['email_be' => 0, 'kozos_email' => 'iroda@example.com']);
$levelek = [];
$N::ment(0, ['cim' => 'Kikapcsolt levél', 'szemely_id' => $norbi, 'email' => 1] + $mikor);
$N::jelzesek_general($ekkor(2));
$N::levelek_kuld($ekkor(2));
T::egyezik([], $levelek, 'kikapcsolt e-mail értesítésnél nem megy levél');

/* ---- Napi összefoglaló ---- */

update_option('sdh_muhely_naptar', ['email_be' => 1, 'kozos_email' => 'iroda@example.com', 'napi_be' => 1, 'napi_ora' => '07:30']);
delete_option('sdh_muhely_naptar_napi');
$levelek = [];
$ma      = $most->format('Y-m-d');
$N::ment(0, ['cim' => 'Mai teendő', 'szemely_id' => $norbi, 'prioritas' => 'fontos'] + $ido($ma, '16:00', '17:00'));

T::egyezik(0, $N::napi_osszefoglalo($ma . ' 06:00:00'), 'az összefoglaló a beállított idő előtt nem megy');
$db = $N::napi_osszefoglalo($ma . ' 07:31:00');
T::egyezik(2, $db, 'összefoglaló: mindenki a saját címére (Norbi) és a közös címre (Péter)');
$norbi_level = array_values(array_filter($levelek, static fn (array $l): bool => $l['cim'] === 'norbi@example.com'))[0] ?? ['targy' => '', 'torzs' => ''];
T::tartalmaz((string) $norbi_level['targy'], 'Napi összefoglaló', 'az összefoglaló tárgya');
T::tartalmaz((string) $norbi_level['torzs'], '16:00–17:00  Mai teendő', 'benne a nap eseménye az időpontjával');
T::tartalmaz((string) $norbi_level['torzs'], '[fontos]', 'benne a fontosság');
T::egyezik(0, $N::napi_osszefoglalo($ma . ' 09:00:00'), 'naponta csak egyszer megy');

update_option('sdh_muhely_naptar', ['email_be' => 1, 'napi_be' => 1, 'napi_ora' => '07:30', 'napi_cimzettek' => "fonok@example.com, nem-cim\nmasik@example.com"]);
delete_option('sdh_muhely_naptar_napi');
$levelek = [];
T::egyezik(2, $N::napi_osszefoglalo($ma . ' 08:00:00'), 'megadott címzetteknél csak az érvényes címekre megy');
T::tartalmaz((string) $levelek[0]['torzs'], 'NORBI', 'a megadott címzettek mindenki naptárát megkapják');
T::tartalmaz((string) $levelek[0]['torzs'], 'PÉTER', '…a másik névét is');

/* ======================================================================== */
/* 9. Többfelhasználós mód: profilonként egy naptár, privát esemény (7.6)     */
/* ======================================================================== */

update_option('sdh_muhely_csapat', ['mod' => 'multi', 'fal_nev' => 'Üzenőfal', 'nevek' => '']);
$urit();

$kollega_login = 'np_teszt_' . wp_generate_password(6, false);
$kollega       = wp_insert_user(['user_login' => $kollega_login, 'user_pass' => wp_generate_password(20), 'user_email' => $kollega_login . '@example.com', 'display_name' => 'Teszt Kolléga', 'role' => SDH_Muhely_Csapat::SZEREP]);
$admin         = get_current_user_id();

T::igaz(!$N::egyszeru(), 'többfelhasználós mód');
$szemelyek = $N::szemelyek();
$fiokok    = array_filter(array_column($szemelyek, 'user_id'));
T::egyezik(count($fiokok), count(array_unique($fiokok)), 'profilonként pontosan egy naptár');
T::igaz(in_array((int) $kollega, $fiokok, true) && in_array($admin, $fiokok, true), 'minden fiók magától kap naptárat');

$N::szemelyek_frissit();
T::egyezik(count($szemelyek), count($N::szemelyek()), 'újra megnyitva nem készül másodpéldány');

$sajat = $N::sajat_szemely_id();
T::igaz($sajat > 0 && $szemelyek[$sajat]['user_id'] === $admin, 'a bejelentkezett fiók saját naptára megvan');
T::egyezik($kollega_login . '@example.com', $N::szemely_email($szemelyek[$N::sajat_szemely_id((int) $kollega)]), 'az értesítés a fiók e-mail-címére megy');

$extra = $N::szemely_ment(0, ['nev' => 'Kiszállás']);
T::igaz(is_int($extra), 'többfelhasználós módban is felvehető fiók nélküli név');

$titkos = $N::ment(0, ['cim' => 'Titkos ügy', 'szemely_id' => $sajat, 'privat' => 1, 'hely' => 'Valahol'] + $ido($nap(1), '10:00', '11:00'));
$nyilt  = $N::ment(0, ['cim' => 'Nyílt ügy', 'szemely_id' => $sajat] + $ido($nap(1), '12:00', '13:00'));
T::egyezik(1, (int) $esemeny_sor($titkos)->privat, 'többfelhasználós módban van privát esemény');

// Kártya: mindenki csak a sajátját látja.
$N::ment(0, ['cim' => 'Admin kártyája', 'szemely_id' => $sajat, 'ertesites' => 1] + $mikor);
$N::jelzesek_general($ekkor(1));
T::egyezik(['Admin kártyája'], array_column($N::nyitott_jelzesek(), 'cim'), 'a gazda látja a saját kártyáját');

wp_set_current_user((int) $kollega);
$N::szemelyek_frissit();

T::egyezik([], $N::nyitott_jelzesek(), 'a kolléga nem látja más kártyáját');
T::igaz(!SDH_Muhely_Admin_UI::admin_e(), 'a kolléga nem adminisztrátor');

$lat = [];

foreach ($N::adat($nap(0), $nap(3))['esemenyek'] as $x) {
    $lat[(int) $x['id']] = $x;
}

T::egyezik('Foglalt', (string) $lat[$titkos]['cim'], 'más privát eseményéből csak annyi látszik: Foglalt');
T::egyezik('', (string) $lat[$titkos]['hely'], '…a részletei nem');
T::igaz($lat[$titkos]['szerk'] === false, '…és nem szerkeszthető');
T::egyezik('Nyílt ügy', (string) $lat[$nyilt]['cim'], 'a nem privát esemény látszik');
T::igaz($lat[$nyilt]['szerk'] === false, 'más naptárának eseményét a kolléga nem szerkesztheti');
T::igaz(is_string($N::ment($nyilt, ['cim' => 'Átírnám', 'szemely_id' => $sajat] + $ido($nap(1), '12:00', '13:00'))), 'más eseményének módosítása elutasítva');
T::igaz(empty(t_ajax('sdh_muhely_naptar_torol', ['id' => $nyilt, 'mod' => 'mind'])['success']), 'más eseményének törlése elutasítva');
T::tartalmaz(t_ajax('sdh_muhely_naptar_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $titkos])['nyers'], 'privát eseménye', 'a privát esemény űrlapja nem nyílik meg másnak');
T::igaz(empty(t_ajax('sdh_muhely_naptarszemely_ment', ['id' => 0, 'nev' => 'Kolléga neve'])['success']), 'többfelhasználós módban a neveket csak az adminisztrátor szerkesztheti');

$sajat_k = $N::sajat_szemely_id();
$kollege_esemeny = $N::ment(0, ['cim' => 'A kolléga dolga', 'szemely_id' => $sajat_k] + $ido($nap(1), '15:00', '16:00'));
T::igaz(is_int($kollege_esemeny), 'a kolléga a saját naptárába vehet fel eseményt');
T::igaz(is_int($N::ment($kollege_esemeny, ['cim' => 'A kolléga dolga (átírva)', 'szemely_id' => $sajat_k] + $ido($nap(1), '15:00', '16:00'))), '…és módosíthatja');

wp_set_current_user($admin);
$N::szemelyek_frissit();
T::igaz(is_int($N::ment($kollege_esemeny, ['cim' => 'Admin átírta', 'szemely_id' => $sajat_k] + $ido($nap(1), '15:00', '16:00'))), 'az adminisztrátor bárki eseményét módosíthatja');

// Letiltott fiók naptára eltűnik a listából.
update_user_meta((int) $kollega, 'sdh_letiltva', '1');
$N::szemelyek_frissit();
T::igaz(!in_array((int) $kollega, array_column($N::szemelyek(), 'user_id'), true), 'letiltott fiók naptára nem látszik');

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user((int) $kollega);

/* ======================================================================== */
/* 10. Beállítások, oldal, csempe, fájlok                                    */
/* ======================================================================== */

$r = t_ajax('sdh_muhely_beallitasok_ment', [
    'ful' => 'naptar', 'naptar_jelen' => '1', 'naptar_alap_nezet' => 'honap', 'naptar_ora_tol' => '6', 'naptar_ora_ig' => '22',
    'naptar_alap_emlekezteto' => ['30', '99'], 'naptar_kategoriak' => "Szerviz | zold\nVisszahívás | nincs-ilyen\n",
    'naptar_email_be' => '1', 'naptar_kozos_email' => 'kozos@example.com', 'naptar_napi_be' => '1', 'naptar_napi_ora' => '06:30', 'naptar_napi_cimzettek' => 'a@example.com; rossz',
], 'sdh_nonce', 'sdh_muhely_beallitasok');
T::igaz(!empty($r['success']), 'Beállítások › Naptár mentése');
$b = $N::beallitas();
T::egyezik('honap', $b['alap_nezet'], 'alapnézet');
T::egyezik([6, 22], [$b['ora_tol'], $b['ora_ig']], 'a nap látható órasávja');
T::egyezik([30], $b['alap_emlekezteto'], 'alap emlékeztető: csak a listából');
T::egyezik(['szerviz' => ['nev' => 'Szerviz', 'szin' => 'zold'], 'visszahivas' => ['nev' => 'Visszahívás', 'szin' => 'kek']], $b['kategoriak'], 'saját kategóriák; ismeretlen szín helyett kék');
T::egyezik('kozos@example.com', $b['kozos_email'], 'közös értesítő cím');
T::egyezik(['a@example.com'], $b['napi_cimzettek'], 'az összefoglaló címzettjei: csak érvényes cím');
T::egyezik('06:30', $b['napi_ora'], 'az összefoglaló ideje');

update_option('sdh_muhely_naptar', ['ora_tol' => 20, 'ora_ig' => 5, 'alap_nezet' => 'nincs', 'kategoriak' => '']);
$b = $N::beallitas();
T::igaz($b['ora_ig'] >= $b['ora_tol'] + 2, 'a nap vége mindig a kezdete után áll');
T::egyezik('het', $b['alap_nezet'], 'ismeretlen nézet helyett heti');
T::igaz(count($b['kategoriak']) >= 6, 'üres kategórialistánál az alaplista él');

ob_start();
$N::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::tartalmaz($doboz, 'name="naptar_kategoriak"', 'Beállítások: kategóriák szerkeszthetők');
T::tartalmaz($doboz, 'name="naptar_napi_be"', 'Beállítások: napi összefoglaló kapcsolható');

ob_start();
$N::oldal();
$oldal = (string) ob_get_clean();
T::tartalmaz($oldal, 'data-sdh-naptar=', 'a Naptár oldala kirajzolódik');
T::tartalmaz($oldal, 'data-sdh-np-uj', 'van „Új esemény" gomb');
T::nem_tartalmaz($oldal, 'wp-admin/admin.php', 'a naptárból semmi nem visz a wp-adminba');

ob_start();
SDH_Muhely_Admin_UI::attekintes_tartalom(false);
T::tartalmaz((string) ob_get_clean(), 'data-sdh-kartya="naptar"', 'az Áttekintésen van Naptár csempe');
T::igaz($N::mai_db() >= 0, 'a csempe száma kiszámolható');

$js = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/naptar.js');

foreach (['nezetMa', 'nezetNapok', 'nezetHonap', 'nezetNegyedev', 'nezetEv', 'nezetNapirend', 'nezetIdovonal', 'nezetSzemelyek', 'nezetTabla'] as $fuggveny) {
    T::tartalmaz($js, 'function ' . $fuggveny . '(', 'naptar.js: ' . $fuggveny);
}

T::tartalmaz($js, "addEventListener('sdh:pulzus'", 'naptar.js: a kártyák a pulzusból jönnek (minden oldalon)');
T::tartalmaz($js, 'data-np-kinyom', 'naptar.js: a kártya kézzel nyomható ki');
T::tartalmaz($js, 'function vissza(', 'naptar.js: visszaszámláló');
T::nem_tartalmaz($js, 'confirm(', 'naptar.js: nincs böngésző-felugró kérdés');
T::nem_tartalmaz($js, 'overflow-y', 'naptar.js: nincs görgetősáv');
T::nem_tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'assets/naptar.css'), 'overflow: auto', 'naptar.css: nincs görgetősáv');
T::nem_tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-naptar.php'), 'SDH Szerviz', 'a naptár kódjában nincs cégadat');

T::egyezik([], $figy, 'PHP-figyelmeztetés nélkül fut');

/* ---- Takarítás ---- */

$urit();

if ($regi_csapat === false) {
    delete_option('sdh_muhely_csapat');
} else {
    update_option('sdh_muhely_csapat', $regi_csapat);
}

if ($regi_naptar === false) {
    delete_option('sdh_muhely_naptar');
} else {
    update_option('sdh_muhely_naptar', $regi_naptar);
}

T::vege('t35 naptár: nevek, események, ismétlődés, nézetek, kártyák, e-mail, privát');
