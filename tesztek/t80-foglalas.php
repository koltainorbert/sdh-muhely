<?php
/**
 * t80 – F6 (0.88.0): online időpontfoglalás – szabad időpontok, foglalás, védelem, lemondás, levelek, oldal.
 *
 * Saját, jövőbeli időszakban (2031. március) és saját naptárban dolgozik.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$F = 'SDH_Muhely_Foglalas';
$N = 'SDH_Muhely_Naptar';
$E = SDH_Muhely_Schema::tabla('naptar_esemeny');

$levelek = [];
add_filter('pre_wp_mail', static function ($null, array $atts) use (&$levelek) {
    $levelek[] = ['cim' => is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'], 'targy' => (string) $atts['subject'], 'torzs' => (string) $atts['message']];

    return true;
}, 10, 2);

T::igaz(version_compare(SDH_Muhely_Schema::DB_VERSION, '0.50.0', '>='), 'sémaverzió 0.50.0');
foreach (['forras', 'foglalas_kulcs', 'foglalo_email', 'foglalo_telefon'] as $o) {
    T::igaz(t_oszlop($E, $o), 'új oszlop: naptar_esemeny.' . $o);
}

$regi_opt = get_option($F::OPT);
$szid     = $N::szemely_ment(0, ['nev' => 'Foglalás T80 ' . wp_rand(1000, 9999), 'email' => 'szerviz-t80@example.com', 'szin' => 'zold']);
T::igaz(is_int($szid), 'saját naptár a teszthez');
$wpdb->query($wpdb->prepare('DELETE FROM ' . $E . ' WHERE szemely_id = %d', $szid));

$beall = static function (array $m) use ($F, $szid): void {
    update_option($F::OPT, $m + [
        'be' => 1, 'szemely_id' => $szid, 'napok' => [1, 2, 3, 4, 5], 'nyit' => '09:00', 'zar' => '12:00',
        'hossz' => 60, 'parhuzam' => 1, 'elore_ora' => 2, 'max_nap' => 7,
    ]);
};
$beall([]);

$MOST = '2031-03-03 08:00:00'; // hétfő

/* --- Szabad időpontok ---------------------------------------------------------------------------------- */

$sz = $F::szabad($MOST);
T::egyezik(['10:00', '11:00'], $sz['2031-03-03'] ?? [], 'hétfő: 2 óra előre → 10 és 11 (9 már nem)');
T::egyezik(['09:00', '10:00', '11:00'], $sz['2031-03-04'] ?? [], 'kedd: 9, 10, 11 (a 12-es zárás utáni nem)');
T::igaz(!isset($sz['2031-03-08']) && !isset($sz['2031-03-09']), 'hétvége nem foglalható');
T::igaz(isset($sz['2031-03-10']) && !isset($sz['2031-03-11']), 'legfeljebb 7 napra előre');

// Más esemény a naptárban foglal; az egész napos nem.
$ev = static fn (array $x) => $N::ment(0, $x + ['szemely_id' => $szid, 'cim' => 'Foglalt T80']);
$ev(['kezdet_datum' => '2031-03-05', 'kezdet_ora' => '10', 'kezdet_perc' => '15', 'veg_ora' => '10', 'veg_perc' => '45']);
$ev(['kezdet_datum' => '2031-03-06', 'egesz_nap' => 1]);
$ev(['kezdet_datum' => '2031-03-04', 'kezdet_ora' => '09', 'kezdet_perc' => '00', 'veg_ora' => '10', 'veg_perc' => '00', 'ismetles' => 'heti', 'ism_napok' => '2']);
$sz = $F::szabad($MOST);
T::egyezik(['09:00', '11:00'], $sz['2031-03-05'] ?? [], 'szerda: a 10:15-ös esemény a 10-es sávot foglalja');
T::egyezik(['09:00', '10:00', '11:00'], $sz['2031-03-06'] ?? [], 'csütörtök: az egész napos esemény nem foglal');
T::egyezik(['10:00', '11:00'], $sz['2031-03-04'] ?? [], 'kedd: a hetente ismétlődő esemény foglal');
$beall(['parhuzam' => 2]);
T::egyezik(['09:00', '10:00', '11:00'], $F::szabad($MOST)['2031-03-05'] ?? [], 'két ügyfél egyszerre: a 10-es sáv még szabad');
$beall([]);

/* --- Foglalás --------------------------------------------------------------------------------------------- */

$u = t_ugyfel('Foglaló T80');
$vegz = (string) wp_rand(1000, 9999);
$wpdb->update(SDH_Muhely_Schema::tabla('ugyfel'), ['telefon' => '+36 30 555 ' . $vegz], ['id' => $u]);

$jo = ['nev' => 'Foglaló T80', 'telefon' => '06 30 555-' . $vegz, 'email' => 'ugyfel-t80@example.com', 'idopont' => '2031-03-05 11:00',
    'eszkoz' => 'iPhone 13', 'hiba' => 'Nem tölt', 'hozzajarul' => '1'];

T::egyezik('Adja meg a nevét.', $F::foglal(['nev' => ''] + $jo, $MOST, false), 'név nélkül nem');
T::egyezik('Adja meg a telefonszámát.', $F::foglal(['telefon' => '12'] + $jo, $MOST, false), 'telefon nélkül nem');
T::egyezik('A foglaláshoz fogadja el az adatkezelést.', $F::foglal(['hozzajarul' => ''] + $jo, $MOST, false), 'hozzájárulás nélkül nem');
T::egyezik('Válasszon időpontot.', $F::foglal(['idopont' => 'holnap'] + $jo, $MOST, false), 'időpont nélkül nem');
T::tartalmaz((string) $F::foglal(['idopont' => '2031-03-05 10:00'] + $jo, $MOST, false), 'közben lefoglalták', 'foglalt időpontra nem');
T::tartalmaz((string) $F::foglal(['idopont' => '2031-03-08 10:00'] + $jo, $MOST, false), 'nem foglalható', 'hétvégére nem');
$beall(['email_kotelezo' => 1]);
T::tartalmaz((string) $F::foglal(['email' => ''] + $jo, $MOST, false), 'e-mail-címét', 'kötelező e-mail nélkül nem');
$beall([]);

$levelek = [];
$id = $F::foglal($jo, $MOST, false);
T::igaz(is_int($id), 'a foglalás sikerült');
$e = $N::esemeny((int) $id);
T::egyezik('online', (string) $e->forras, 'forrás: online');
T::egyezik('2031-03-05 11:00:00', (string) $e->kezdet, 'kezdés');
T::egyezik('2031-03-05 12:00:00', (string) $e->veg, 'vége: +60 perc');
T::egyezik($szid, (int) $e->szemely_id, 'a beállított naptárba');
T::egyezik($u, (int) $e->ugyfel_id, 'a meglévő ügyfélhez kötve (telefonszám alapján)');
T::tartalmaz((string) $e->cim, 'iPhone 13', 'a címben a készülék');
T::tartalmaz((string) $e->leiras, 'Nem tölt', 'a leírásban a hiba');
T::igaz(strlen((string) $e->foglalas_kulcs) >= 16, 'lemondó kulcs');
T::egyezik(['09:00'], $F::szabad($MOST)['2031-03-05'] ?? [], 'a 11-es sáv már foglalt (a 10-est más esemény foglalja)');
T::tartalmaz((string) $F::foglal($jo, $MOST, false), 'közben lefoglalták', 'ugyanarra az időpontra másodszor nem');

T::egyezik(2, count($levelek), 'két levél: ügyfélnek és szerviznek');
T::egyezik('ugyfel-t80@example.com', $levelek[0]['cim'] ?? '', 'visszaigazolás az ügyfélnek');
T::tartalmaz($levelek[0]['torzs'] ?? '', 'k=' . $e->foglalas_kulcs, 'benne a lemondó link');
T::tartalmaz($levelek[0]['torzs'] ?? '', '2031. márc. 5. (szerda) 11:00', 'benne az időpont');
T::egyezik('szerviz-t80@example.com', $levelek[1]['cim'] ?? '', 'értesítés a naptár gazdájának');
T::tartalmaz($levelek[1]['targy'] ?? '', 'Új online foglalás', 'a tárgyban');

/* --- Védelem ---------------------------------------------------------------------------------------------- */

$jo2 = ['idopont' => '2031-03-06 09:00'] + $jo;
T::tartalmaz((string) $F::foglal(['weboldal_cim' => 'spam', 't' => $F::ido_jel(time() - 10)] + $jo2, $MOST), 'nem sikerült', 'csapdamező kitöltve: elutasítva');
T::tartalmaz((string) $F::foglal(['t' => $F::ido_jel(time())] + $jo2, $MOST), 'túl gyorsan', 'azonnali beküldés: elutasítva');
T::tartalmaz((string) $F::foglal(['t' => time() - 10 . '.hamis'] + $jo2, $MOST), 'lejárt', 'hamis időbélyeg: elutasítva');
$_SERVER['REMOTE_ADDR'] = '203.0.113.80';
set_transient('sdh_fogl_' . substr(wp_hash('ip|203.0.113.80'), 0, 20), 5, HOUR_IN_SECONDS);
T::tartalmaz((string) $F::foglal(['t' => $F::ido_jel(time() - 10)] + $jo2, $MOST), 'Túl sok foglalás', 'óránként legfeljebb 5 egy címről');
delete_transient('sdh_fogl_' . substr(wp_hash('ip|203.0.113.80'), 0, 20));
$id2 = $F::foglal(['t' => $F::ido_jel(time() - 10), 'email' => ''] + $jo2, $MOST);
T::igaz(is_int($id2), 'helyes időbélyeggel, e-mail nélkül is megy');
T::egyezik(1, (int) get_transient('sdh_fogl_' . substr(wp_hash('ip|203.0.113.80'), 0, 20)), 'a címenkénti számláló nőtt');

/* --- Lemondás --------------------------------------------------------------------------------------------- */

$levelek = [];
T::igaz($F::kulccsal('rovid') === null, 'rövid kulccsal nincs foglalás');
T::igaz($F::lemond((string) $e->foglalas_kulcs, $MOST), 'lemondás');
T::egyezik('elmaradt', (string) $N::esemeny((int) $id)->allapot, 'az esemény Elmaradt lett');
T::tartalmaz((string) $N::esemeny((int) $id)->leiras, 'online lemondta', 'a leírásban');
T::igaz(in_array('11:00', $F::szabad($MOST)['2031-03-05'] ?? [], true), 'az időpont újra szabad');
T::egyezik(1, count($levelek), 'a szerviz levelet kap');
T::tartalmaz($levelek[0]['targy'] ?? '', 'Lemondott', 'a tárgyban');
T::igaz(!$F::lemond((string) $e->foglalas_kulcs, $MOST), 'másodszor nem mondható le');
$e2 = $N::esemeny((int) $id2);
T::igaz(!$F::lemondhato($e2, '2031-03-07 08:00:00'), 'elmúlt időpont nem mondható le');

/* --- Kikapcsolva, oldal, beállítás ---------------------------------------------------------------------------- */

$beall(['be' => 0]);
T::tartalmaz((string) $F::foglal(['idopont' => '2031-03-06 10:00'] + $jo, $MOST, false), 'nem elérhető', 'kikapcsolva nem foglalható');
ob_start();
$F::oldal();
$ki = (string) ob_get_clean();
T::tartalmaz($ki, 'jelenleg nem elérhető', 'kikapcsolva az oldal jelzi');

$beall(['max_nap' => 90, 'nyit' => '00:00', 'zar' => '23:59', 'napok' => [1, 2, 3, 4, 5, 6, 7], 'elore_ora' => 0, 'szolgaltatasok' => "Kijelzőcsere\nAkkumulátor"]);
ob_start();
$F::oldal('Teszt hiba T80', ['nev' => 'Vissza T80']);
$o = (string) ob_get_clean();
T::tartalmaz($o, 'data-rma-foglalas', 'a foglalóűrlap');
T::tartalmaz($o, 'data-rma-fogl-nap=', 'napválasztó');
T::tartalmaz($o, 'name="idopont"', 'időpontválasztó');
T::tartalmaz($o, 'name="weboldal_cim"', 'csapdamező');
T::tartalmaz($o, 'Kijelzőcsere', 'a választható témák');
T::tartalmaz($o, 'Teszt hiba T80', 'a hibaüzenet');
T::tartalmaz($o, 'value="Vissza T80"', 'a beírt adat megmarad');
T::nem_tartalmaz($o, 'type="date"', 'nincs natív dátummező');
T::nem_tartalmaz($o, 'WordPress', 'az ügyféloldalon nincs WordPress');

T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['foglalas']), 'Beállítások › Online időpontfoglalás');
T::egyezik('naptar', SDH_Muhely_Modulok::szakasz_modulja('foglalas'), 'a Naptár modulhoz tartozik');
T::tartalmaz($F::url(), 'sdh_foglalas=1', 'a foglalóoldal címe');
T::tartalmaz($F::beagyazo(), '<iframe', 'beágyazó kód');
ob_start();
$F::beallitas_doboz();
T::tartalmaz((string) ob_get_clean(), 'name="foglalas[hossz]"', 'a beállítás lapfül');

$_POST = ['foglalas' => ['be' => '1', 'szemely_id' => (string) $szid, 'napok' => ['2', '4'], 'nyit_ora' => '08', 'nyit_perc' => '30', 'zar_ora' => '16', 'zar_perc' => '00', 'hossz' => '45', 'parhuzam' => '3', 'elore_ora' => '1', 'max_nap' => '14', 'szolgaltatasok' => "A\n\nB"]];
$F::beallitas_mentes();
$_POST = [];
$b = $F::beallitas();
T::egyezik([true, [2, 4], '08:30', '16:00', 45, 3, ['A', 'B']], [$b['be'], $b['napok'], $b['nyit'], $b['zar'], $b['hossz'], $b['parhuzam'], $b['szolgaltatasok']], 'a beállítás mentése');

// A visszaigazoló levél útja (bekötött levelezőfiók nélkül a szerver levélküldője) és az eredmény naplója.
T::egyezik('', $F::kuldo(), 'levelezőfiók nélkül: a szerver levélküldője');
$lv = get_option($F::OPT . '_level');
T::igaz(is_array($lv) && !empty($lv['ido']) && array_key_exists('ok', $lv), 'az utolsó levél eredménye a Beállításokban látszik');
ob_start();
$F::beallitas_doboz();
T::tartalmaz((string) ob_get_clean(), 'name="foglalas[kuldo]"', 'Beállítások: honnan menjen a visszaigazolás');
ob_start();
$F::oldal();
$lap = (string) ob_get_clean();
T::tartalmaz($lap, 'rma-fogl__panel--ido', 'foglalóoldal: széles, kéthasábos elrendezés (időpont)');
T::tartalmaz($lap, 'rma-fogl__panel--adat', 'foglalóoldal: széles, kéthasábos elrendezés (adatok)');

// Rendrakás.
$wpdb->query($wpdb->prepare('DELETE FROM ' . $E . ' WHERE szemely_id = %d', $szid));
$wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Muhely_Schema::tabla('naptar_szemely') . ' WHERE id = %d', $szid));
$regi_opt === false ? delete_option($F::OPT) : update_option($F::OPT, $regi_opt);

T::vege('t80 online időpontfoglalás (F6)');
