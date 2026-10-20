<?php
/**
 * t321 – A licenckezelés REST-útjainak jogosultsága és a távoli mód:
 * - látogató és sima felhasználó minden úton elutasítva (a végrehajtás előtt – nincs mellékhatás),
 * - a Portál adminja (manage_options vagy a saját képesség) eléri,
 * - távoli licencközpont-módban a lista / műveletek 409-cel állnak (a licenceket a távoli központ kezeli),
 *   az állapot ezt mondja meg a panelnek.
 */
require __DIR__ . '/_t32-seged.php';

$vid = t32_vevo('Jog Teszt Kft.', 'jog@example.com');
$l   = t32_licenc($vid);
$pid = t32_peldany((int) $l->id);

$utak = [
    ['GET', '/admin/licenc/allapot', []],
    ['GET', '/admin/licencek', []],
    ['GET', '/admin/licenc/' . $l->id, []],
    ['POST', '/admin/licenc', ['vevo_id' => $vid]],
    ['POST', '/admin/licenc/' . $l->id, ['csomag' => 'Hack']],
    ['POST', '/admin/licenc/' . $l->id . '/muvelet', ['muvelet' => 'tilt']],
    ['GET', '/admin/licenc/vevok', []],
    ['GET', '/admin/licenc/peldanyok', []],
    ['GET', '/admin/licenc/peldany/' . $pid, []],
    ['POST', '/admin/licenc/peldany/' . $pid, ['cimke' => 'x']],
    ['POST', '/admin/licenc/peldany/' . $pid . '/muvelet', ['tipus' => 'diagnosztika']],
    ['POST', '/admin/licenc/peldany/' . $pid . '/levalaszt', []],
    ['GET', '/admin/licenc/kiadasok', []],
    ['POST', '/admin/licenc/kiadas', []],
    ['POST', '/admin/licenc/kiadas/1', ['torol' => true]],
    ['GET', '/admin/licenc/naplo', []],
];

$sima = wp_insert_user(['user_login' => 't321_sima_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 't321_' . wp_rand() . '@example.com']);
$admin = get_current_user_id();

foreach ($utak as [$m, $ut, $adat]) {
    $s = t_latogato(static fn () => t_rest($m, $ut, $adat)->get_status());
    T::igaz(in_array($s, [401, 403], true), 'látogató: ' . $m . ' ' . $ut . ' → ' . $s);

    wp_set_current_user((int) $sima);
    $s = t_rest($m, $ut, $adat)->get_status();
    T::igaz(in_array($s, [401, 403], true), 'előfizető: ' . $m . ' ' . $ut . ' → ' . $s);
    wp_set_current_user($admin);
}

$friss = t32_l((int) $l->id);
T::egyezik('aktiv', (string) $friss->allapot, 'az elutasított kérések nem változtattak (nincs felfüggesztés)');
T::egyezik('Pro', (string) $friss->csomag, 'az elutasított kérések nem változtattak (csomag)');
global $wpdb;
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('peldany') . ' WHERE id = %d', $pid)), 'az elutasított leválasztás nem futott');

// A Portál saját admin-képessége (nem WP-admin) is eléri.
$ert = wp_insert_user(['user_login' => 't321_ert_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 't321e_' . wp_rand() . '@example.com']);
(new WP_User((int) $ert))->add_cap(SDH_Sales::JOG);
wp_set_current_user((int) $ert);
T::egyezik(200, t_rest('GET', '/admin/licencek')->get_status(), 'a Portál admin-képességgel: lista 200');
wp_set_current_user($admin);

// Admin: minden olvasó út 200
foreach (['/admin/licenc/allapot', '/admin/licencek', '/admin/licenc/' . $l->id, '/admin/licenc/vevok', '/admin/licenc/peldanyok', '/admin/licenc/peldany/' . $pid, '/admin/licenc/kiadasok', '/admin/licenc/naplo'] as $ut) {
    T::egyezik(200, t_rest('GET', $ut)->get_status(), 'admin: GET ' . $ut);
}
T::egyezik(404, t_rest('GET', '/admin/licenc/999999')->get_status(), 'nem létező licenc: 404');

/* --- Távoli mód ---------------------------------------------------------------------------------------------------- */

SDH_Sales::b_ment(['kozpont.mod' => 'tavoli', 'kozpont.url' => 'https://kozpont.example/']);
$a = t_rest('GET', '/admin/licenc/allapot')->get_data();
T::egyezik('tavoli', (string) $a['mod'], 'távoli mód: az állapot jelzi');
T::egyezik(false, (bool) $a['kezelheto'], 'távoli mód: itt nem kezelhető');
T::tartalmaz((string) $a['kozpont_cim'], 'kozpont.example', 'távoli mód: a távoli központ címe a panelnek');
T::igaz(!isset($a['szamok']), 'távoli mód: nincs helyi licencadat');
foreach ([['GET', '/admin/licencek', []], ['POST', '/admin/licenc/' . $l->id . '/muvelet', ['muvelet' => 'tilt']], ['GET', '/admin/licenc/peldanyok', []]] as [$m, $ut, $adat]) {
    $v = t_rest($m, $ut, $adat);
    T::egyezik(409, $v->get_status(), 'távoli mód: ' . $m . ' ' . $ut . ' → 409');
}
T::tartalmaz((string) (t_rest('GET', '/admin/licencek')->get_data()['message'] ?? ''), 'távoli', 'távoli mód: érthető hibaüzenet');
T::egyezik('aktiv', (string) t32_l((int) $l->id)->allapot, 'távoli mód: a művelet nem futott');
SDH_Sales::b_ment(['kozpont.mod' => 'helyi']);
T::egyezik(200, t_rest('GET', '/admin/licencek')->get_status(), 'vissza helyi módba: újra kezelhető');

T::vege('t321 licenckezelés – jogosultság, távoli mód');
