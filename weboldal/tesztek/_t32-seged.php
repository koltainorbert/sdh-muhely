<?php
/**
 * Közös segéd a t320–t329 (licenckezelés a Portál adminjában) tesztekhez: licencközpont-ellenőrzés, vevő / licenc /
 * telepítés létrehozása közvetlenül a licencközpont tábláiba. A futtató (`t[0-9]*.php`) nem futtatja külön.
 */
require_once __DIR__ . '/_keret.php';

if (!class_exists('SDH_Kozpont') || !class_exists('SDH_Sales_Licenc')) {
    fwrite(STDERR, "A licencközpont (beágyazott vagy különálló) nem fut a tesztoldalon.\n");
    exit(2);
}

SDH_Kozpont::sema();
do_action('rest_api_init', rest_get_server());
add_filter('sdh_sales_korlat_ki', '__return_true');
SDH_Sales::b_ment(['kozpont.mod' => 'helyi', 'email.felado' => 'rendeles@forgalmazo.example', 'ceg.nev' => 'Forgalmazó Teszt Kft.']);

/** Új vevő a licencközpontban. */
function t32_vevo(string $nev, string $email = '', string $adoszam = ''): int
{
    $v = SDH_Kozpont::vevo_ment(['nev' => $nev, 'email' => $email, 'adoszam' => $adoszam, 'kapcsolattarto' => 'Kapcsolat ' . $nev], 'teszt');

    return is_int($v) ? $v : 0;
}

/** Új licenc (a licencközpont saját mentésével). @param array<string, mixed> $extra */
function t32_licenc(int $vevo_id, array $extra = []): object
{
    $id = SDH_Kozpont::licenc_ment($extra + ['vevo_id' => $vevo_id, 'csomag' => 'Pro', 'modulok' => '*', 'max_peldany' => 2, 'ciklus' => 'eves', 'dij' => 100000], 'teszt');

    global $wpdb;

    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('licenc') . ' WHERE id = %d', (int) $id));
}

/** Telepítés (példány) egy licenchez. @param array<string, mixed> $extra */
function t32_peldany(int $licenc_id, array $extra = []): int
{
    global $wpdb;

    $wpdb->insert(SDH_Kozpont::t('peldany'), $extra + [
        'licenc_id' => $licenc_id, 'peldany' => wp_generate_password(20, false, false), 'ujjlenyomat' => 'x', 'verzio' => '0.99.6',
        'elso' => SDH_Kozpont::most(), 'utolso' => SDH_Kozpont::most(), 'utolso_jel' => SDH_Kozpont::most(), 'jel_db' => 1, 'egeszseg' => 'ok',
        'allapot' => (string) wp_json_encode(['rendszer' => ['php' => '8.3', 'url' => 'https://szerviz-' . $licenc_id . '.example']]), 'tavoli' => 1,
    ]);

    return (int) $wpdb->insert_id;
}

/** A licenc friss sora. */
function t32_l(int $id): object
{
    global $wpdb;

    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('licenc') . ' WHERE id = %d', $id));
}

/** Művelet a REST-en át. @param array<string, mixed> $be */
function t32_muv(int $id, string $m, array $be = []): WP_REST_Response
{
    return t_rest('POST', '/admin/licenc/' . $id . '/muvelet', ['muvelet' => $m] + $be);
}
