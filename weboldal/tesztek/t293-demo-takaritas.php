<?php
/**
 * t293 – 24 órás takarítás: a látogató rekordjai (gyereksorokkal) 24 óra után törlődnek, a generált alapadat nem;
 * a lejárt ideiglenes fiókok törlődnek, a még élők és a közös demo fiók maradnak; a 48 óránál régebbi naplósorok mennek.
 */
require __DIR__ . '/_t29-seged.php';

global $wpdb;

$t = static fn (string $n): string => SDH_Muhely_Schema::tabla($n);

SDH_Demo_Generator::general(1000);
SDH_Demo_Pillanatkep::keszit();
$alap_ugyfel = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('ugyfel')}");
$alap_lap    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('munkalap')}");

// A látogató: két ügyfél és egy munkalap tétellel.
$u   = t_latogato('198.51.100.30');
$u1  = t_ugyfel('Látogató Egy');
$u2  = t_ugyfel('Látogató Kettő');
$wpdb->insert($t('munkalap'), ['munkalap_szam' => 999999, 'ugyfel_id' => $u1, 'allapot' => 'nyitott', 'letrehozva' => current_time('mysql')]);
$lap = (int) $wpdb->insert_id;
$wpdb->insert($t('munkalap_tetel'), ['munkalap_id' => $lap, 'sorrend' => 0, 'megnevezes' => 'Látogatói tétel']);
$wpdb->get_var('SELECT 1');
t_admin();

T::egyezik($alap_ugyfel + 2, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('ugyfel')}"), 'a látogató rekordjai bekerültek');

// 23 óra után még marad.
T::egyezik(0, SDH_Demo_Keret::takarit(time() + 23 * HOUR_IN_SECONDS), '23 óra után még nincs törlés');

// 24 óra után: törlődik, gyereksorokkal; a generált adat érintetlen.
$db = SDH_Demo_Keret::takarit(time() + DAY_IN_SECONDS + 60);
T::egyezik(3, $db, '24 óra után: 3 látogatói rekord törölve');
T::egyezik($alap_ugyfel, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('ugyfel')}"), 'ügyfelek: az alapadat maradt');
T::egyezik($alap_lap, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t('munkalap')}"), 'munkalapok: az alapadat maradt');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t('munkalap_tetel')} WHERE munkalap_id = %d", $lap)), 'a lap tétele is törölve');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t('ugyfel_szo')} WHERE ugyfel_id IN (%d, %d)", $u1, $u2)), 'az ügyfelek szó-indexe is törölve');
T::egyezik(0, SDH_Demo_Keret::takarit(time() + DAY_IN_SECONDS + 120), 'másodszor nincs mit törölni');

// Csak a pillanatkép azonosítója fölött: egy alapadatra mutató (régi) naplósor nem töröl.
$regi = (int) $wpdb->get_var("SELECT MIN(id) FROM {$t('ugyfel')}");
SDH_Demo::naplo('rekord', SDH_Demo::ip_hash('198.51.100.31'), 'ugyfel', 0, time() - 2 * HOUR_IN_SECONDS);
$wpdb->update(SDH_Demo::tabla('naplo'), ['rekord_id' => $regi], ['id' => (int) $wpdb->insert_id]);
SDH_Demo_Keret::takarit(time() + DAY_IN_SECONDS);
T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t('ugyfel')} WHERE id = %d", $regi)), 'alapadat soha nem törlődik');

// 48 óránál régebbi naplósorok törlődnek.
SDH_Demo_Keret::takarit(time() + 3 * DAY_IN_SECONDS);
T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Demo::tabla('naplo') . " WHERE tipus = 'rekord'"), 'régi naplósorok törölve');

// Lejárt fiókok.
$el    = t_latogato('198.51.100.32');
$lejar = t_latogato('198.51.100.33');
update_user_meta($lejar, SDH_Demo::META_LEJAR, time() - 1);
SDH_Demo::ment(['demo_demo' => true]);
SDH_Demo_Inditas::demo_demo_szinkron();
t_admin();
$torolt = SDH_Demo_Inditas::takarit();
T::igaz($torolt >= 1 && get_userdata($lejar) === false, 'lejárt fiók törölve');
T::igaz(get_userdata($el) instanceof WP_User, 'élő fiók marad');
T::igaz(get_user_by('login', 'demo') instanceof WP_User, 'a közös demo fiók marad');
T::igaz(get_userdata($u) instanceof WP_User, 'a 24 órán belüli látogató marad');

// Az óránkénti feladat (ütemező) ugyanezt futtatja.
update_user_meta($el, SDH_Demo::META_LEJAR, time() - 1);
do_action(SDH_Demo::CRON_ORANKENT);
T::igaz(get_userdata($el) === false, 'óránkénti ütemezett takarítás');
T::igaz((bool) wp_next_scheduled(SDH_Demo::CRON_ORANKENT) || (SDH_Demo::cron_beallit() === null && wp_next_scheduled(SDH_Demo::CRON_ORANKENT)), 'óránkénti feladat ütemezve');

// A tesztkörnyezetben a pillanatkép-fájl ne maradjon meg (az adatbázis visszaáll).
@unlink((string) (SDH_Demo::allapot()['pillanatkep']['fajl'] ?? ''));

T::vege('t293 bemutató-takarítás (24 óra)');
