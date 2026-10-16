<?php
/**
 * t75 – F1 (0.83.0): javítási garancia.
 *
 * - Lejárat a lezárástól (alap napok / lapon felülírva / 0 = nincs), csak javításnál.
 * - Visszatérő készülék: eszközkártya-jelzés, garanciában lévő eredeti lap, láncban az eredetié.
 * - Garanciális visszatérés: a mentett mező, ellenőrzés, az eredeti lap visszatérései.
 * - Űrlap, nyomtatvány, Beállítások.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$G = 'SDH_Muhely_Garancia';
$T = static fn (string $t): string => SDH_Muhely_Schema::tabla($t);
delete_option($G::OPT);

T::igaz(version_compare(SDH_Muhely_Schema::DB_VERSION, '0.46.0', '>='), 'sémaverzió 0.46.0');
T::igaz(t_oszlop($T('munkalap'), 'garancia_nap') && t_oszlop($T('munkalap'), 'garancia_elozo'), 'új oszlopok: garancia_nap, garancia_elozo');
T::egyezik(['nap' => 180, 'jelzes' => true], $G::beallitas(), 'alap: 180 nap, jelzés be');

/* --- Lejárat ---------------------------------------------------------------------------------------- */

$lap = (object) ['lezarva' => '2026-01-01', 'garancia_nap' => null, 'fajta' => 'javitas'];
T::egyezik('2026-06-30', $G::lejar($lap), '180 nap a lezárástól');
T::egyezik(true, $G::ervenyes($lap, '2026-06-30'), 'az utolsó napon még érvényes');
T::egyezik(false, $G::ervenyes($lap, '2026-07-01'), 'utána lejárt');
T::egyezik('2026-01-31', $G::lejar((object) ['lezarva' => '2026-01-01', 'garancia_nap' => 30, 'fajta' => 'javitas']), 'a lapon megadott 30 nap');
T::egyezik('', $G::lejar((object) ['lezarva' => '2026-01-01', 'garancia_nap' => 0, 'fajta' => 'javitas']), '0 nap: nincs garancia');
T::egyezik('', $G::lejar((object) ['lezarva' => null, 'garancia_nap' => null, 'fajta' => 'javitas']), 'nyitott lapnak még nincs lejárata');
T::egyezik('', $G::lejar((object) ['lezarva' => '2026-01-01', 'garancia_nap' => null, 'fajta' => 'arukiadas']), 'eladásnak nincs javítási garanciája');
update_option($G::OPT, ['nap' => 90, 'jelzes' => true]);
T::egyezik('2026-04-01', $G::lejar($lap), 'a beállítás (90 nap) a lap saját értéke nélkül');
delete_option($G::OPT);

/* --- Visszatérő készülék --------------------------------------------------------------------------- */

$u  = t_ugyfel('Garancia T75');
$e  = t_eszkoz($u, 'Apple', 'iPhone T75');
$m1 = t_munkalap($u, [$e]);
$tegnapelott = wp_date('Y-m-d', strtotime(current_time('Y-m-d')) - 10 * DAY_IN_SECONDS);
$wpdb->update($T('munkalap'), ['allapot' => SDH_Muhely_Munkalap::lezart_kulcs(), 'lezarva' => $tegnapelott], ['id' => $m1]);

$g = $G::eszkoz_garancia($e);
T::egyezik($m1, (int) ($g->id ?? 0), 'a 10 napja lezárt lap garanciában van');
T::egyezik(null, $G::eszkoz_garancia($e, $m1), 'a saját lapját kihagyva nincs más');

$jel = $G::eszkoz_jelzes($e);
T::tartalmaz($jel, 'Garanciában', 'eszközkártya: „Garanciában”');
T::tartalmaz($jel, 'data-sdh-garancia-elozo="' . $m1 . '"', 'eszközkártya: a visszatérés gombja az eredeti lappal');
T::tartalmaz($jel, '<strong>1</strong> munkalapon', 'eszközkártya: hányszor járt itt');

update_option($G::OPT, ['nap' => 180, 'jelzes' => false]);
T::egyezik('', $G::eszkoz_jelzes($e), 'jelzés kikapcsolva: nincs');
delete_option($G::OPT);

$wpdb->update($T('munkalap'), ['garancia_nap' => 5], ['id' => $m1]);
T::egyezik(null, $G::eszkoz_garancia($e), '5 napos garancia 10 nap után: lejárt');
T::nem_tartalmaz($G::eszkoz_jelzes($e), 'Garanciában', 'lejárt garanciánál nincs gomb');
$wpdb->update($T('munkalap'), ['garancia_nap' => null], ['id' => $m1]);

/* --- Garanciális visszatérés -------------------------------------------------------------------------- */

$m2 = t_munkalap($u, [$e], 'nyitott', ['garancia_elozo' => $m1]);
T::egyezik($m1, (int) $wpdb->get_var($wpdb->prepare('SELECT garancia_elozo FROM ' . $T('munkalap') . ' WHERE id = %d', $m2)), 'a visszatérés lapja az eredetire mutat');
T::egyezik([$m2], array_map(static fn ($v): int => (int) $v->id, $G::visszateresek($m1)), 'az eredeti lap visszatérései');
T::egyezik($m1, (int) ($G::eszkoz_garancia($e, $m2)->id ?? 0), 'a visszatérés lapjáról nézve az eredeti garanciája számít');
T::tartalmaz($G::eszkoz_jelzes($e), '<strong>2</strong> munkalapon', 'második alkalom');

// Lánc: a visszatérés is lezárul – a garancia akkor is az eredetié.
$wpdb->update($T('munkalap'), ['allapot' => SDH_Muhely_Munkalap::lezart_kulcs(), 'lezarva' => current_time('Y-m-d')], ['id' => $m2]);
T::egyezik($m1, (int) ($G::eszkoz_garancia($e)->id ?? 0), 'láncban: az eredeti lap garanciája (nem hosszabbodik)');

// A beküldött mezők.
$_POST = ['garancia_nap' => '', 'garancia_elozo' => (string) $m1];
T::egyezik(['garancia_nap' => null, 'garancia_elozo' => $m1], $G::bekuldott(null, 'javitas'), 'üres nap = alap; az előzmény elfogadva');
$_POST = ['garancia_nap' => '30', 'garancia_elozo' => '999999999'];
T::egyezik(['garancia_nap' => 30, 'garancia_elozo' => 0], $G::bekuldott(null, 'javitas'), 'nem létező előzmény: 0');
$_POST = ['garancia_elozo' => (string) $m2];
T::egyezik(['garancia_elozo' => $m1], $G::bekuldott((object) ['id' => $m2, 'garancia_elozo' => $m1], 'javitas'), 'magára nem mutathat: marad a régi');
$_POST = ['garancia_nap' => '99999'];
T::egyezik(['garancia_nap' => $G::NAP_MAX], $G::bekuldott(null, 'javitas'), 'a napok felső korlátja');
T::egyezik([], $G::bekuldott(null, 'arukiadas'), 'eladásnál nincs garanciamező');
$_POST = [];

/* --- Felület ------------------------------------------------------------------------------------------ */

$l1 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('munkalap') . ' WHERE id = %d', $m1));
$blokk = $G::urlap_blokk($l1);
T::tartalmaz($blokk, 'name="garancia_nap"', 'űrlap: a napok mezője');
T::tartalmaz($blokk, 'placeholder="180"', 'üresen az alap látszik');
T::tartalmaz($blokk, 'Lejár', 'lezárt lapnál a lejárat');
T::tartalmaz($blokk, 'Garanciális visszatérés: ' . $G::lap_felirat((object) ['id' => $m2, 'munkalap_szam' => $wpdb->get_var($wpdb->prepare('SELECT munkalap_szam FROM ' . $T('munkalap') . ' WHERE id = %d', $m2))]), 'az eredeti lapon a visszatérései');
$l2 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('munkalap') . ' WHERE id = %d', $m2));
T::tartalmaz($G::urlap_blokk($l2), '<strong>Garanciális visszatérés</strong> – az eredeti lap', 'a visszatérés lapján az eredeti');
T::tartalmaz($G::urlap_blokk($l2), 'name="garancia_elozo" value="' . $m1 . '"', 'a rejtett előzmény mező');

$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $m1])['nyers'];
T::tartalmaz($urlap, 'data-sdh-garancia', 'a munkalap popupjában a garancia csoport');
$ossz = t_ajax('sdh_muhely_munkalapok_osszefoglalo', [], '_wpnonce', 'sdh_muhely_modal', ['ugyfel_id' => $u, 'eszkoz_id' => $e])['nyers'];
T::tartalmaz($ossz, 'data-sdh-garancia-jel', 'az Eszköz lapfül (AJAX) a garancia-jelzéssel');
$v = t_ajax('sdh_muhely_garancia_eszkoz', [], '_wpnonce', 'sdh_muhely_modal', ['eszkoz_id' => $e]);
T::igaz(!empty($v['success']) && $v['data']['garancialis'] === true && (int) $v['data']['db'] === 2, 'AJAX: az eszköz garanciája és a darabszám');

// Mentés az űrlapról: a garancia mezői megmaradnak.
$alap_allapot = SDH_Muhely_Munkalap::alap_kulcs(SDH_Muhely_Munkalap::allapotok());
$wpdb->update($T('munkalap'), ['allapot' => $alap_allapot, 'lezarva' => null], ['id' => $m2]);
$r = t_ajax('sdh_muhely_munkalapok_ment', [
    'id' => $m2, 'fajta' => 'javitas', 'allapot' => $alap_allapot, 'ugyfel_id' => $u, 'eszkoz_id' => $e,
    'garancia_nap' => '45', 'garancia_elozo' => (string) $m1, 'tetelek_jelen' => '1', 'keszult' => current_time('Y-m-d'),
], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
$l2 = $wpdb->get_row($wpdb->prepare('SELECT garancia_nap, garancia_elozo FROM ' . $T('munkalap') . ' WHERE id = %d', $m2));
T::igaz(!empty($r['success']), 'űrlapmentés sikerült' . (empty($r['success']) ? ': ' . substr($r['nyers'], 0, 200) : ''));
T::egyezik(45, (int) $l2->garancia_nap, 'űrlapmentés: a lap napjai');
T::egyezik($m1, (int) $l2->garancia_elozo, 'űrlapmentés: az előzmény marad');

// Nyomtatvány.
$adat = SDH_Muhely_Nyomtatvany::lap_adat($m1);
T::igaz(is_array($adat) && strpos((string) $adat['garancia'], 'nap, ') !== false, 'nyomtatvány: „Garancia: N nap, … -ig”');
T::egyezik('', (string) (SDH_Muhely_Nyomtatvany::lap_adat(t_munkalap($u, [$e]))['garancia'] ?? 'x'), 'nyitott lapon nincs garancia-sor');

// Beállítások.
T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['garancia']), 'Beállítások › Javítási garancia lapfül');
$_POST = ['garancia' => ['nap' => '365', 'jelzes_jelen' => '1']];
$G::beallitas_mentes();
$_POST = [];
T::egyezik(['nap' => 365, 'jelzes' => false], $G::beallitas(), 'mentés: 365 nap, jelzés ki (a pipa nélkül)');
delete_option($G::OPT);

T::vege('t75 javítási garancia (F1)');
