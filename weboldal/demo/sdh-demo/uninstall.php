<?php
/**
 * Eltávolításkor: a bemutató saját táblái, beállításai, szerepe és a pillanatkép-fájlok. A műhely adataihoz nem nyúl
 * (a bemutató-példány adatbázisa úgyis eldobható; a jelölt generált sorokat a generátor törlése viszi).
 *
 * @package SDH_Demo
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$sdh_demo_allapot = get_option('sdh_demo_allapot', []);
$sdh_demo_fajl    = is_array($sdh_demo_allapot) ? (string) ($sdh_demo_allapot['pillanatkep']['fajl'] ?? '') : '';

if ($sdh_demo_fajl !== '' && is_file($sdh_demo_fajl) && preg_match('/pillanatkep-[0-9\-]+-[a-z0-9]+\.jsonl\.gz$/', $sdh_demo_fajl)) {
    unlink($sdh_demo_fajl);
}

foreach (['naplo', 'kimeno'] as $sdh_demo_t) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'bemutato_' . $sdh_demo_t); // phpcs:ignore
}

foreach (['sdh_demo_beallitas', 'sdh_demo_allapot', 'sdh_demo_db'] as $sdh_demo_o) {
    delete_option($sdh_demo_o);
}

wp_clear_scheduled_hook('sdh_demo_ejjeli');
wp_clear_scheduled_hook('sdh_demo_orankenti');
remove_role('bemutato');
