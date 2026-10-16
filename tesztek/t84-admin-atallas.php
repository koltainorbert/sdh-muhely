<?php
/**
 * t84 – N6 (0.92.0): régi adminná tett kolléga átállítása SDH adminisztrátorra.
 */

require __DIR__ . '/_keret.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$A = 'SDH_Muhely_Admin_Atallas';

if ($r = get_user_by('login', 'regiadmin_t84')) {
    wp_delete_user((int) $r->ID);
}
$id = (int) wp_insert_user(['user_login' => 'regiadmin_t84', 'user_pass' => 'Regi-Admin-T84!', 'role' => 'administrator', 'display_name' => 'Régi Admin T84']);

T::igaz(in_array($id, array_map(static fn ($u): int => (int) $u->ID, $A::jeloltek()), true), 'a jelöltek között');
T::igaz(!in_array(get_current_user_id(), array_map(static fn ($u): int => (int) $u->ID, $A::jeloltek()), true), 'saját maga nincs a listán');
T::tartalmaz($A::atallit(get_current_user_id()), 'Saját magadat', 'saját magát nem');
T::tartalmaz($A::atallit(999999), 'nem WordPress-adminisztrátor', 'nem létező / nem admin');

ob_start();
SDH_Muhely_Szemely::jogosultsagok_rajz();
T::tartalmaz((string) ob_get_clean(), 'data-sdh-atallit="' . $id . '"', 'a Jogosultságok lapon a gomb');

$v = t_ajax('sdh_muhely_admin_atallit', ['user' => $id], '_wpnonce', 'sdh_muhely_admin_atallit');
T::igaz(!empty($v['success']), 'átállítva');
$u = get_userdata($id);
T::egyezik([SDH_Muhely_Admin_UI::ADMIN_SZEREP], array_values((array) $u->roles), 'a szerep: SDH adminisztrátor');
T::igaz(user_can($id, SDH_Muhely_Admin_UI::ADMIN_JOG), 'a rendszer adminisztrátori joga megmaradt');
T::igaz(!user_can($id, SDH_Muhely_Admin_UI::FEJLESZTO_JOG), 'a WordPress-admin joga elveszett');
T::tartalmaz($A::atallit($id), 'nem WordPress-adminisztrátor', 'másodszor nem');

wp_delete_user($id);

T::vege('t84 admin-átállítás (N6)');
