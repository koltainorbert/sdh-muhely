<?php
/**
 * t297 – tiltott műveletek a látogatónak: frissítés, modulok, külső mentés, csapattagok és szerepek, licenc, érzékeny
 * Beállítások-lapfülek, fejlesztői részek, kétlépcsős belépés, import / export / anonimizálás → 403 (az éles úton is);
 * a menüben / lapfülek között nem látszanak, a közvetlen cím visszairányít; a munka (rekordok mentése) mehet.
 */
require __DIR__ . '/_t29-seged.php';

$tiltott_ajax = [
    'frissites_ellenorzes', 'frissites_indit', 'frissites_forras', 'licenc_ellenoriz', 'tamogatas_indit', 'tavoli_valasz',
    'mentes_most', 'mentes_cel_lista', 'mentes_cel_proba', 'mentes_gdrive_indit', 'mentes_vissza', 'mentes_csomag_le',
    'csapatkollega_urlap', 'csapatkollega_ment', 'ketlepcsos_bekapcsol', 'ketlepcsos_kikapcsol', 'ketlepcsos_urlap',
    'atvetel_feltolt', 'atvetel_futtat', 'anonim_urlap', 'anonim_vegrehajt', 'penztar_import_feltolt', 'penztar_import_lepes',
    'penztar_export', 'raktar_csv', 'naplo_csv', 'tac_import', 'admin_atallit', 'meres', 'beallitasok_feltolt',
    'ai_beallit', 'ai_teszt', 'sms_parosit', 'sms_webhook_titok', 'level_teszt',
];

foreach ($tiltott_ajax as $m) {
    $d = SDH_Demo_Vedelem::dontes('sdh_muhely_' . $m, true);
    T::igaz($d !== null && $d[0] === 403, 'AJAX tiltva: ' . $m);
}

foreach (['modulok', 'modulok_zar', 'demo_betoltes', 'termek_demo'] as $m) {
    $d = SDH_Demo_Vedelem::dontes('sdh_muhely_' . $m, false);
    T::igaz($d !== null && $d[0] === 403, 'admin-post tiltva: ' . $m);
}

foreach (['add-tag', 'save-widget', 'upload-attachment', 'query-attachments', 'bemutato_general'] as $m) {
    T::igaz(SDH_Demo_Vedelem::dontes($m, true) !== null, 'nem a műhely művelete: tiltva (' . $m . ')');
}

// Beállítások: érzékeny lapfül soha; a többi alapból csak nézhető, engedéllyel menthető.
foreach (['jogosultsagok', 'adatatvetel', 'licenc', 'sebesseg', 'mentes', 'naplo', 'demo', 'rendszer', 'kalauz', 'levelezes', 'asszisztens', 'tac', 'tesztadat', 'ismeretlen-uj'] as $ful) {
    $d = SDH_Demo_Vedelem::dontes('sdh_muhely_beallitasok_ment', true, ['ful' => $ful]);
    T::igaz($d !== null && $d[1] === SDH_Demo_Vedelem::TILTOTT_UZENET, 'lapfül tiltva: ' . $ful);
    T::igaz(!SDH_Demo_Vedelem::ful_engedett($ful), 'lapfül nem nyitható: ' . $ful);
}

$d = SDH_Demo_Vedelem::dontes('sdh_muhely_beallitasok_ment', true, ['ful' => 'allapotok']);
T::igaz($d !== null && $d[1] === SDH_Demo_Vedelem::NEZET_UZENET, 'nem érzékeny lapfül: alapból csak nézhető');
T::igaz(SDH_Demo_Vedelem::dontes('sdh_muhely_urlap_beall_ment', true) !== null, 'közös űrlap-beállítás: alapból tiltva');
SDH_Demo::ment(['beallitas_mentes' => true]);
T::egyezik(null, SDH_Demo_Vedelem::dontes('sdh_muhely_beallitasok_ment', true, ['ful' => 'allapotok']), 'engedéllyel menthető');
T::egyezik(null, SDH_Demo_Vedelem::dontes('sdh_muhely_beallitasok', false, ['ful' => 'allapotok']), 'engedéllyel menthető (admin-post)');
T::igaz(SDH_Demo_Vedelem::dontes('sdh_muhely_beallitasok_ment', true, ['ful' => 'licenc']) !== null, 'engedéllyel is: licenc tiltva');
SDH_Demo::ment(['beallitas_mentes' => false]);

// A munka mehet.
foreach (['ugyfelek_ment', 'ugyfelek_urlap', 'munkalapok_ment', 'munkalapok_urlap', 'eszkozok_ment', 'termekek_ment', 'penztar_ment', 'naptar_ment', 'sms_kuld', 'uzenet_kuld', 'csapat_kuld', 'racs_adat', 'pulzus', 'okos_kereso', 'szamla_elonezet', 'nyomtatas_pdf'] as $m) {
    T::egyezik(null, SDH_Demo_Vedelem::dontes('sdh_muhely_' . $m, true), 'mehet: ' . $m);
}

T::egyezik(null, SDH_Demo_Vedelem::dontes('heartbeat', true), 'mehet: heartbeat');
T::egyezik(null, SDH_Demo_Vedelem::dontes('sdh_muhely_ugyfel_mentes', false), 'mehet (admin-post): ügyfél mentése');

// Az őr a kérésben (AJAX / admin-post / admin-oldal), látogatóként; adminnak nem.
$u = t_latogato('198.51.100.70');
add_filter('wp_doing_ajax', '__return_true');
$_REQUEST['action'] = 'sdh_muhely_frissites_indit';
$e = t_megallit([SDH_Demo_Vedelem::class, 'or']);
T::igaz($e !== null && $e->getCode() === 403, 'őr: frissítés → 403');
$_REQUEST['action'] = 'sdh_muhely_ugyfelek_ment';
T::igaz(t_megallit([SDH_Demo_Vedelem::class, 'or']) === null, 'őr: ügyfél mentése mehet');
remove_filter('wp_doing_ajax', '__return_true');
$GLOBALS['pagenow'] = 'profile.php';
$e = t_megallit([SDH_Demo_Vedelem::class, 'or']);
T::igaz($e !== null && $e->getCode() === 302, 'admin-oldal (profil) helyett a műhely');
t_admin();
$_REQUEST['action'] = 'sdh_muhely_frissites_indit';
add_filter('wp_doing_ajax', '__return_true');
T::igaz(t_megallit([SDH_Demo_Vedelem::class, 'or']) === null, 'az admint nem korlátozza');
remove_filter('wp_doing_ajax', '__return_true');
unset($_REQUEST['action']);

// Az éles út: valódi 403 JSON a műhely hibaformájában.
$ki = t_folyamat("define('DOING_AJAX', true); \$_POST['action'] = \$_REQUEST['action'] = 'sdh_muhely_frissites_ellenorzes';\nrequire '" . ABSPATH . "wp-load.php';\n"
    . "wp_set_current_user({$u}); do_action('admin_init'); echo 'NEM ALLT LE';");
$j = json_decode(trim($ki), true);
T::igaz(is_array($j) && $j['success'] === false, 'éles AJAX: success=false JSON');
T::tartalmaz((string) ($j['data']['uzenet'] ?? ''), 'a bemutatóban nem érhető el', 'éles AJAX: magyar üzenet');
T::nem_tartalmaz($ki, 'NEM ALLT LE', 'éles AJAX: leállt');
$ki = t_folyamat("define('DOING_AJAX', true); \$_POST['action'] = \$_REQUEST['action'] = 'sdh_muhely_frissites_ellenorzes';\nrequire '" . ABSPATH . "wp-load.php';\n"
    . "wp_set_current_user({$u}); register_shutdown_function(static function () { echo 'KOD=' . http_response_code() . '|'; }); do_action('admin_init');");
T::tartalmaz($ki, 'KOD=403|', 'éles AJAX: HTTP 403');

// A felület: a menüben csak a megnyitható; a tiltott címek visszairányítanak.
wp_set_current_user($u);
T::egyezik(null, SDH_Muhely_Modulok::egy('modulok'), 'Modulok menüpont nincs');
T::igaz(!current_user_can('manage_options'), 'nincs fejlesztői jog (WordPress-admin, frissítés)');
T::tartalmaz(SDH_Demo_Vedelem::visszairanyitas('beallitasok', 'licenc', ''), 'beallitasok', 'licenc lapfül → Beállítások');
T::tartalmaz(SDH_Demo_Vedelem::visszairanyitas('beallitasok', 'mentes', ''), 'beallitasok', 'mentés lapfül → Beállítások');
T::tartalmaz(SDH_Demo_Vedelem::visszairanyitas('csapat', '', 'kollegak'), 'csapat', 'Kollégák nézet → Csapat');
T::egyezik('', SDH_Demo_Vedelem::visszairanyitas('beallitasok', 'allapotok', ''), 'engedett lapfül marad');

$minta = '<div class="sdh-beall__sor"><span class="sdh-beall__csoport">Rendszer</span><div class="sdh-beall__pillek">'
    . '<a class="sdh-pill" href="https://x/?muhely=beallitasok&#038;ful=licenc" title="t"><span>Licenc</span></a>'
    . '<a class="sdh-pill" href="https://x/?muhely=beallitasok&#038;ful=mentes"><span>Mentés</span></a></div></div>'
    . '<div class="sdh-beall__sor"><span class="sdh-beall__csoport">Munkalap</span><div class="sdh-beall__pillek">'
    . '<a class="sdh-pill" href="https://x/?muhely=beallitasok&#038;ful=allapotok"><span>Állapotok</span></a></div></div>'
    . '<a class="sdh-cs-ful" href="https://x/?muhely=csapat&#038;nezet=kollegak">Kollégák</a><a class="sdh-cs-ful" href="https://x/?muhely=csapat">Beszélgetések</a>';
$szurt = SDH_Demo_Vedelem::html_szuro($minta);
T::nem_tartalmaz($szurt, 'Licenc', 'a licenc lapfül nem látszik');
T::nem_tartalmaz($szurt, 'Rendszer', 'a kiürült csoport sem');
T::tartalmaz($szurt, 'Állapotok', 'az engedett lapfül látszik');
T::nem_tartalmaz($szurt, 'Kollégák', 'a Kollégák fül nem látszik');
T::tartalmaz($szurt, 'Beszélgetések', 'a Beszélgetések fül marad');

// REST: a felhasználó-végpontok tiltva; a kétlépcsős belépés ki.
$r = rest_do_request(new WP_REST_Request('POST', '/wp/v2/users/me'));
T::igaz($r->is_error() && in_array($r->get_status(), [401, 403, 404], true), 'REST /users/me tiltva (' . $r->get_status() . ')');
T::igaz(SDH_Muhely_Ketlepcsos::ki(), 'kétlépcsős belépés ki (nem állítható)');
T::egyezik(false, (bool) apply_filters('show_admin_bar', true), 'nincs admin-sáv');

T::vege('t297 bemutató-tiltott műveletek');
