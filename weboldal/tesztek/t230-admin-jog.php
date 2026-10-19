<?php
/** t230 – admin REST: látogató és nem admin felhasználó 403/401, admin 200; a menü csak adminnak. */
require __DIR__ . '/_keret.php';

$utak = [['GET', '/admin/attekintes'], ['GET', '/admin/rendelesek'], ['GET', '/admin/vevok'], ['GET', '/admin/leadek'], ['GET', '/admin/naplo'],
    ['GET', '/admin/oldalak'], ['GET', '/admin/elemek'], ['GET', '/admin/menuk'], ['GET', '/admin/beallitasok'], ['GET', '/admin/arculat'],
    ['GET', '/admin/export'], ['POST', '/admin/import'], ['POST', '/admin/titok'], ['POST', '/admin/elonezet'], ['POST', '/admin/tartalom/betolt']];

foreach ($utak as [$m, $u]) {
    T::egyezik(200 <= t_rest($m, $u)->get_status() && t_rest($m, $u)->get_status() < 500 ? 'ok' : 'hiba', 'ok', 'admin eléri: ' . $u);
}

foreach ($utak as [$m, $u]) {
    $k = t_latogato(fn () => t_rest($m, $u)->get_status());
    T::igaz(in_array($k, [401, 403], true), 'látogató tiltva: ' . $u . ' (' . $k . ')');
}

$uid = wp_insert_user(['user_login' => 't230-szerk', 'user_pass' => wp_generate_password(), 'user_email' => 't230@example.com', 'role' => 'editor']);
$elozo = get_current_user_id();
wp_set_current_user((int) $uid);
foreach ($utak as [$m, $u]) {
    T::egyezik(403, t_rest($m, $u)->get_status(), 'szerkesztő tiltva: ' . $u);
}
T::igaz(!SDH_Sales::admin(), 'szerkesztő nem admin');
wp_set_current_user($elozo);

// A boot-adat és a panelek: csak elérhető panel kerül a menübe; idegen panel szűrővel.
add_filter('sdh_sales_admin_panelek', static function (array $p): array {
    $p['proba'] = ['cim' => 'Próba', 'ikon' => 'info', 'csoport' => 'Egyéb', 'sorrend' => 900, 'elerheto' => false];

    return $p;
});
$p = SDH_Sales_Admin::panelek(['/admin/attekintes']);
T::igaz(isset($p['proba']) && $p['proba']['elerheto'] === false, 'panel-szűrő');
T::igaz($p['foglalasok']['elerheto'] === false, 'foglalás végpont nélkül nem elérhető');
T::igaz(SDH_Sales_Admin::panelek(['/admin/foglalasok'])['foglalasok']['elerheto'], 'foglalás végponttal elérhető');
T::vege('t230 admin jog');
