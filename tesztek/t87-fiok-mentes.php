<?php
/**
 * t87 – H3b (0.95.0): a fiókok is a mentésben – és visszaállításkor a hiányzók visszajönnek (ugyanazzal az azonosítóval).
 */

require __DIR__ . '/_keret.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;

$AT = 'SDH_Muhely_Asszisztens_Tudas';
$MV = 'SDH_Muhely_Mentes_Vissza';
$r  = (string) wp_rand(10000, 99999);

$id = (int) wp_insert_user(['user_login' => 'fiok_t87_' . $r, 'user_pass' => 'Jelszo-T87-ok!', 'user_email' => "fiok{$r}@example.com", 'role' => 'sdh_kollega', 'display_name' => 'Fiók T87']);
update_user_meta($id, SDH_Muhely_Szerepkor::META, 'pultos');
update_user_meta($id, 'sdh_titulus', 'Pultos');
update_user_meta($id, SDH_Muhely_Ketlepcsos::META_TITOK, 'titkos-t87');
$tam = (int) wp_insert_user(['user_login' => 'tam_t87_' . $r, 'user_pass' => wp_generate_password(), 'role' => 'administrator']);
update_user_meta($tam, 'sdh_muhely_tamogatas', ['lejar' => time() + 60]);

$lista = $AT::mentendo_fiokok();
$f     = null;
foreach ($lista as $x) {
    if ($x['id'] === $id) {
        $f = $x;
    }
}
T::igaz($f !== null, 'a fiók a mentendők között');
T::egyezik(['sdh_kollega'], $f['szerepek'] ?? [], 'a szerepe');
T::egyezik('pultos', $f['meta'][SDH_Muhely_Szerepkor::META] ?? '', 'a rendszerbeli szerepe (meta)');
T::igaz(!isset($f['meta'][SDH_Muhely_Ketlepcsos::META_TITOK]), 'a kétlépcsős titok NINCS a mentésben');
T::igaz(str_starts_with((string) ($f['pass'] ?? ''), '$'), 'csak a jelszó lenyomata');
T::igaz(!in_array($tam, array_map(static fn ($x): int => $x['id'], $lista), true), 'az ideiglenes támogatási fiók nincs a mentésben');

$m = $AT::teljes_mentes('t87');
T::igaz(is_array($m), 'teljes mentés kész');
$tartalom = (string) gzdecode((string) file_get_contents($AT::mentes_mappa() . $m['fajl']));
T::tartalmaz($tartalom, '"login":"fiok_t87_' . $r . '"', 'a mentésfájlban a fiók');
T::nem_tartalmaz($tartalom, 'titkos-t87', 'a 2FA-titok nincs a fájlban');
@unlink($AT::mentes_mappa() . $m['fajl']); // phpcs:ignore WordPress.PHP.NoSilencedErrors

// Visszaállítás: a fiók törölve volt → ugyanazzal az azonosítóval jön vissza.
wp_delete_user($id);
T::egyezik('uj', $MV::fiok_vissza($f), 'hiányzó fiók: létrejön');
$u = get_userdata($id);
T::igaz($u !== false && $u->user_login === 'fiok_t87_' . $r, 'ugyanazzal az azonosítóval');
T::igaz(wp_check_password('Jelszo-T87-ok!', (string) $u->user_pass, $id), 'a régi jelszóval beléphet');
T::egyezik(['sdh_kollega'], array_values((array) $u->roles), 'a szerepe visszajött');
T::egyezik('pultos', (string) get_user_meta($id, SDH_Muhely_Szerepkor::META, true), 'a rendszerbeli szerepe is');
T::egyezik('megvolt', $MV::fiok_vissza($f), 'meglévő fiókhoz nem nyúl');

// Foglalt azonosító: új azonosítóval jön létre.
$f2 = $f;
$f2['login'] = 'fiok2_t87_' . $r;
$f2['email'] = "fiok2{$r}@example.com";
T::egyezik('mas_azon', $MV::fiok_vissza($f2), 'foglalt azonosító: másik azonosítóval');

wp_delete_user($id);
wp_delete_user((int) get_user_by('login', 'fiok2_t87_' . $r)->ID);
wp_delete_user($tam);

T::vege('t87 fiókok a mentésben (H3b)');
