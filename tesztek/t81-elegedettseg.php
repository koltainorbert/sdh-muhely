<?php
/**
 * t81 – F7 (0.89.0): elégedettségi kérdés – esedékesség, küldés, értékelés, alacsony értékelés jelzése,
 * munkalap csoport, kézi küldés, riport, beállítás.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$EL = 'SDH_Muhely_Elegedettseg';
$ML = SDH_Muhely_Schema::tabla('munkalap');

$levelek = [];
add_filter('pre_wp_mail', static function ($null, array $atts) use (&$levelek) {
    $levelek[] = ['cim' => is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'], 'targy' => (string) $atts['subject'], 'torzs' => (string) $atts['message']];

    return true;
}, 10, 2);

T::igaz(version_compare(SDH_Muhely_Schema::DB_VERSION, '0.51.0', '>='), 'sémaverzió 0.51.0');
foreach (['elegedettseg_kuldve', 'elegedettseg_csatorna', 'elegedettseg', 'elegedettseg_szoveg', 'elegedettseg_ido'] as $o) {
    T::igaz(t_oszlop($ML, $o), 'új oszlop: munkalap.' . $o);
}

$regi = get_option($EL::OPT);
$beall = static function (array $m) use ($EL): void {
    update_option($EL::OPT, $m + ['be' => 1, 'csatorna' => 'email', 'nap' => 1, 'ora' => 10, 'kuszob' => 4, 'google' => 'https://g.page/r/teszt-t81/review']);
};

$MOST = '2031-05-10 11:00:00';
$u    = t_ugyfel('Elégedett T81');
$wpdb->update(SDH_Muhely_Schema::tabla('ugyfel'), ['email' => 'elegedett-t81@example.com'], ['id' => $u]);
$e    = t_eszkoz($u, 'Samsung', 'T81');
$lezart = static function (string $nap) use ($u, $e, $wpdb, $ML): int {
    $id = t_munkalap($u, [$e]);
    $wpdb->update($ML, ['allapot' => SDH_Muhely_Munkalap::lezart_kulcs(), 'lezarva' => $nap], ['id' => $id]);

    return $id;
};

// Korábbi futások maradékai ne zavarjanak.
$wpdb->query($wpdb->prepare('UPDATE ' . $ML . ' SET elegedettseg_kuldve = %s WHERE elegedettseg_kuldve IS NULL AND lezarva >= %s', '2031-01-01 00:00:00', '2031-01-01'));

$a   = $lezart('2031-05-09'); // esedékes (1 napja)
$ma  = $lezart('2031-05-10'); // ma zárult: még nem
$reg = $lezart('2031-05-01'); // túl régi: kimarad
$ids = static fn (array $l): array => array_map(static fn ($x): int => (int) $x->id, $l);

/* --- Esedékesség ------------------------------------------------------------------------------------------- */

delete_option($EL::OPT);
T::egyezik(false, $EL::beallitas()['be'], 'alapból kikapcsolva');
T::egyezik([], $EL::esedekesek($MOST), 'kikapcsolva nincs esedékes');
$beall([]);
T::egyezik([$a], $ids($EL::esedekesek($MOST)), 'csak a tegnap lezárt lap esedékes');
T::egyezik([], $EL::esedekesek('2031-05-10 08:00:00'), 'a beállított óra előtt nem megy');
$beall(['nap' => 0]);
T::egyezik([$a, $ma], $ids($EL::esedekesek($MOST)), '0 nap: a mai is');
$beall([]);

/* --- Küldés ------------------------------------------------------------------------------------------------- */

$levelek = [];
T::egyezik(1, $EL::esedekes_kuld($MOST), 'egy kérdés ment ki');
$l = SDH_Muhely_Ajanlat::lap($a);
T::egyezik('email', (string) $l->elegedettseg_csatorna, 'e-mailben');
T::igaz(!empty($l->elegedettseg_kuldve), 'megjelölve, hogy elment');
T::egyezik('elegedett-t81@example.com', $levelek[0]['cim'] ?? '', 'az ügyfél címére');
T::tartalmaz($levelek[0]['torzs'] ?? '', 'sdh_ertekeles=' . $l->rma_token, 'benne az értékelő link');
T::tartalmaz($levelek[0]['torzs'] ?? '', 'Kedves Elégedett T81', 'a név kitöltve');
T::nem_tartalmaz(($levelek[0]['torzs'] ?? '') . ($levelek[0]['targy'] ?? ''), '{', 'nem maradt kitöltetlen helyőrző');
T::egyezik([], $EL::esedekesek($MOST), 'másodszor nem esedékes');

// Csak SMS, de nincs SMS-küldés: nem megy ki, de nem kerül újra sorra.
$b2 = $lezart('2031-05-09');
$beall(['csatorna' => 'sms']);
if (!SDH_Muhely_Sms::bekapcsolva()) {
    T::egyezik('', $EL::kuld(SDH_Muhely_Ajanlat::lap($b2)), 'csak SMS, SMS nélkül: nem ment ki');
    T::egyezik('nincs', (string) SDH_Muhely_Ajanlat::lap($b2)->elegedettseg_csatorna, '„nincs” csatornával megjelölve');
}
$beall([]);

// Anonimizált ügyfél nem kap.
$u2 = t_ugyfel('Anonim T81');
$wpdb->update(SDH_Muhely_Schema::tabla('ugyfel'), ['anonimizalva' => '2031-01-01 00:00:00'], ['id' => $u2]);
$c  = t_munkalap($u2, [t_eszkoz($u2, 'Apple', 'T81')]);
$wpdb->update($ML, ['allapot' => SDH_Muhely_Munkalap::lezart_kulcs(), 'lezarva' => '2031-05-09'], ['id' => $c]);
T::igaz(!in_array($c, $ids($EL::esedekesek($MOST)), true), 'anonimizált ügyfél nem kap kérdést');
$wpdb->update($ML, ['elegedettseg_kuldve' => '2031-01-01 00:00:00'], ['id' => $c]);

/* --- Értékelés ----------------------------------------------------------------------------------------------- */

T::egyezik(null, $EL::lap_tokennel('rossz'), 'rossz azonosítóval nincs lap');
$l = $EL::lap_tokennel((string) $l->rma_token);
T::egyezik($a, (int) ($l->id ?? 0), 'a link a lapra mutat');
T::tartalmaz($EL::ertekel($l, 0, ''), 'csillag', 'csillag nélkül nem');
$levelek = [];
T::egyezik('', $EL::ertekel($l, 5, 'Gyors és kedves'), '5 csillag mentve');
$l = SDH_Muhely_Ajanlat::lap($a);
T::egyezik(5, (int) $l->elegedettseg, 'az értékelés');
T::egyezik('Gyors és kedves', (string) $l->elegedettseg_szoveg, 'a megjegyzés');
T::egyezik(0, count($levelek), 'elégedett ügyfélnél nincs riasztó levél');
T::tartalmaz((string) $wpdb->get_var($wpdb->prepare('SELECT osszegzes FROM ' . SDH_Muhely_Valtozasnaplo::tabla() . " WHERE fo_tipus = 'munkalap' AND fo_id = %d ORDER BY id DESC LIMIT 1", $a)), '5/5', 'a naplóban');

$d = $lezart('2031-05-09');
$levelek = [];
T::egyezik('', $EL::ertekel(SDH_Muhely_Ajanlat::lap($d), 2, 'Sokáig tartott'), '2 csillag mentve');
T::egyezik(1, count($levelek), 'alacsony értékelésnél a szerviz levelet kap');
T::tartalmaz($levelek[0]['targy'] ?? '', 'Alacsony értékelés (2/5)', 'a tárgyban');
T::tartalmaz($levelek[0]['torzs'] ?? '', 'Sokáig tartott', 'benne a megjegyzés');

/* --- Munkalap csoport, kézi küldés -------------------------------------------------------------------------- */

$bl = $EL::urlap_blokk(SDH_Muhely_Ajanlat::lap($a));
T::tartalmaz($bl, '★★★★★', 'a csoportban a csillagok');
T::tartalmaz($bl, 'is-jo', 'elégedettként jelölve');
T::tartalmaz($EL::urlap_blokk(SDH_Muhely_Ajanlat::lap($d)), 'is-rossz', 'az alacsony külön jelölve');
$f = $lezart('2031-05-09');
T::tartalmaz($EL::urlap_blokk(SDH_Muhely_Ajanlat::lap($f)), 'data-sdh-elegedettseg-kuld', 'még nem küldött lapon: Kérdés küldése most');
T::tartalmaz(t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $f])['nyers'], 'data-sdh-elegedettseg=', 'a munkalap popupjában');
$levelek = [];
$v = t_ajax('sdh_muhely_elegedettseg_kuld', ['id' => $f]);
T::igaz(!empty($v['success']), 'kézi küldés');
T::tartalmaz((string) ($v['data']['html'] ?? ''), 'Kérdés elküldve', 'a csoport frissül');
T::egyezik(1, count($levelek), 'a levél kiment');
$beall(['be' => 0]);
$g = $lezart('2031-05-09');
T::egyezik('', $EL::urlap_blokk(SDH_Muhely_Ajanlat::lap($g)), 'kikapcsolva, kérdés nélkül nincs csoport');
$wpdb->update($ML, ['elegedettseg_kuldve' => '2031-01-01 00:00:00'], ['id' => $g]);
$beall([]);

/* --- Oldal ---------------------------------------------------------------------------------------------------- */

T::tartalmaz($EL::link(SDH_Muhely_Ajanlat::lap($a)), 'sdh_ertekeles=', 'az értékelő link');

/* --- Riport, beállítás ------------------------------------------------------------------------------------------ */

$o = $EL::osszesit(substr(current_time('mysql'), 0, 10), substr(current_time('mysql'), 0, 10));
T::igaz($o['db'] >= 2 && $o['jo'] >= 1, 'riport: az értékelések száma és az elégedettek');
$r = SDH_Muhely_Riport::adatok(substr(current_time('mysql'), 0, 10), substr(current_time('mysql'), 0, 10));
T::igaz(isset($r['elegedettseg']['atlag']), 'a Riportokban is');

T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['elegedettseg']), 'Beállítások › Elégedettségi kérdés');
T::egyezik('uzenetek', SDH_Muhely_Modulok::szakasz_modulja('elegedettseg'), 'az Ügyfélüzenetek modulhoz tartozik');
ob_start();
$EL::beallitas_doboz();
T::tartalmaz((string) ob_get_clean(), 'name="elegedettseg[google]"', 'a beállítás lapfül');
$_POST = ['elegedettseg' => ['be' => '1', 'csatorna' => 'sms', 'nap' => '3', 'ora' => '9', 'kuszob' => '5', 'google' => 'https://example.com/r', 'sms' => '', 'email_targy' => 'Tárgy {szerviz}', 'email' => '']];
$EL::beallitas_mentes();
$_POST = [];
$b = $EL::beallitas();
T::egyezik([true, 'sms', 3, 9, 5, 'Tárgy {szerviz}'], [$b['be'], $b['csatorna'], $b['nap'], $b['ora'], $b['kuszob'], $b['email_targy']], 'a beállítás mentése');
T::egyezik($EL::alap_szovegek()['sms'], $b['sms'], 'üres szövegnél az alapszöveg');

$regi === false ? delete_option($EL::OPT) : update_option($EL::OPT, $regi);

T::vege('t81 elégedettségi kérdés (F7)');
