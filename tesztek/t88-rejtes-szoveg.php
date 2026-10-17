<?php
/**
 * t88 (0.95.0): a nem fejlesztő adminisztrátor a Beállítások egyetlen lapfülén és a menüpontok oldalán sem lát olyan
 * szót, ami elárulná, mi fut a rendszer alatt (WordPress, plugin, bővítmény, wp-admin, GitHub, médiatár).
 */

require __DIR__ . '/_keret.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$id = (int) wp_insert_user(['user_login' => 'rejt_t88_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(), 'role' => SDH_Muhely_Admin_UI::ADMIN_SZEREP]);
$en = get_current_user_id();
wp_set_current_user($id);
SDH_Muhely_Modulok::kontextus_beallit('frontend');

$tiltott = '/\b(WordPress|plugin|bővítmény|wp-admin|GitHub|médiatár)/iu';
$szoveg  = static fn (string $h): string => html_entity_decode(wp_strip_all_tags((string) preg_replace('#<(script|style|code)\b.*?</\1>#si', '', $h)));
$talalat = [];

foreach (array_keys(SDH_Muhely_Beallitasok::szakaszok()) as $ful) {
    $_GET = ['ful' => $ful];
    ob_start();
    SDH_Muhely_Beallitasok::oldal();
    if (preg_match($tiltott, $szoveg((string) ob_get_clean()), $m)) {
        $talalat[] = 'Beállítások › ' . $ful . ': ' . $m[0];
    }
}

foreach (SDH_Muhely_Modulok::osszes() as $k => $m) {
    if (empty($m['render']) || !is_callable($m['render'])) {
        continue;
    }

    $_GET = [];
    ob_start();
    call_user_func($m['render']);
    if (preg_match($tiltott, $szoveg((string) ob_get_clean()), $mm)) {
        $talalat[] = 'Menüpont ' . $k . ': ' . $mm[0];
    }
}
$_GET = [];

T::egyezik([], $talalat, 'nincs árulkodó szó a Beállításokban és a menüpontok oldalán');
T::igaz(is_file(SDH_MUHELY_DIR . '.htaccess') && str_contains((string) file_get_contents(SDH_MUHELY_DIR . '.htaccess'), 'md|txt'), 'a leírások kívülről nem olvashatók (.htaccess)');

wp_set_current_user($en);
wp_delete_user($id);

T::vege('t88 nem árulkodik (szövegek)');
