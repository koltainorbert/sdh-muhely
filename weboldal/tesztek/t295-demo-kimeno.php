<?php
/**
 * t295 – kimenő üzenetek: a levél (wp_mail) és az SMS (a műhely SMS-sora, tesztüzemben szimuláció) nem megy ki,
 * hanem a „Kimenő üzenetek” nézetbe kerül; a látogató a sajátját látja, az admin mindet; a tesztüzem kikapcsolhatatlan.
 */
require __DIR__ . '/_t29-seged.php';

global $wpdb;

$k = SDH_Demo::tabla('kimeno');

// Tesztüzem: egy későbbi „false” szűrő sem kapcsolja ki.
add_filter('sdh_muhely_tesztuzem', '__return_false', 50);
T::igaz(SDH_Muhely_Halozat::tesztuzem(), 'tesztüzem kényszerítve');
T::igaz(defined('SDH_MUHELY_TESZTUZEM') && SDH_MUHELY_TESZTUZEM, 'tesztüzem állandó is');

// E-mail: elfogva, nem megy ki.
$a = t_latogato('198.51.100.50');
$ok = wp_mail('ugyfel@pelda.hu', 'Elkészült a készüléke', "Kedves Ügyfelünk!\nA készüléke átvehető.");
T::igaz($ok === true, 'wp_mail „sikeres” (a felület a megszokott választ kapja)');
T::egyezik(0, (int) $GLOBALS['bm_t_phpmailer'], 'a levélküldő (PHPMailer) el sem indult');
$sor = $wpdb->get_row("SELECT * FROM {$k} ORDER BY id DESC LIMIT 1");
T::igaz($sor && $sor->csatorna === 'email' && $sor->cimzett === 'ugyfel@pelda.hu' && $sor->targy === 'Elkészült a készüléke' && (int) $sor->felhasznalo === $a, 'levél elfogva (címzett, tárgy, látogató)');

wp_mail(['a@pelda.hu', 'b@pelda.hu'], 'HTML-levél', '<p>Szia <strong>Anna</strong>!</p><script>alert(1)</script>', ['Content-Type: text/html; charset=UTF-8'], [__FILE__]);
$html = $wpdb->get_row("SELECT * FROM {$k} ORDER BY id DESC LIMIT 1");
T::igaz($html && (int) $html->html === 1 && $html->cimzett === 'a@pelda.hu, b@pelda.hu' && strpos((string) $html->szoveg, '[1 melléklet]') !== false, 'HTML-levél, több címzett, melléklet jelezve');

// SMS: a műhely saját SMS-sorával (tesztüzemben szimuláció) – a nézetbe kerül, a hálózatra nem.
update_option(SDH_Muhely_Sms::OPT, ['be' => true, 'szimulacio' => true], false);
$keresek = count($GLOBALS['bm_t_keresek']);
$r = SDH_Muhely_Sms::sor_be(['szam' => '+36 30 012 3456', 'szoveg' => 'Kész a javítás, átveheti. Mintaszerviz', 'kivalto' => 'kezi']);
T::igaz(!empty($r['ok']), 'SMS a sorban (' . ($r['uzenet'] ?? '') . ')');
T::igaz(SDH_Muhely_Sms::beallitas()['szimulacio'], 'SMS: szimuláció (tesztüzem)');
$allapot = SDH_Muhely_Sms::kuld_sor((int) $r['id']);
T::egyezik('szimulalt', $allapot, 'az SMS nem ment ki (szimulált)');
T::egyezik($keresek, count($GLOBALS['bm_t_keresek']), 'nem volt hálózati kérés az átjáró felé');
apply_filters('sdh_muhely_sms_azonnal', true);
SDH_Demo_Kimeno::sms_shutdown();
$sms = $wpdb->get_row("SELECT * FROM {$k} WHERE csatorna = 'sms' ORDER BY id DESC LIMIT 1");
T::igaz($sms && strpos((string) $sms->szoveg, 'Kész a javítás') !== false && strpos((string) preg_replace('/\D/', '', (string) $sms->cimzett), '300123456') !== false, 'SMS elfogva (szám, szöveg)');
T::egyezik(0, SDH_Demo_Kimeno::sms_szinkron(), 'egy SMS csak egyszer kerül át');

// A nézet: a műhely felületén, saját modulként.
T::igaz(SDH_Muhely_Modulok::egy(SDH_Demo_Kimeno::KULCS) !== null, '„Kimenő üzenetek” menüpont (műhely-modul)');
$nezet = SDH_Demo_Kimeno::html();
T::tartalmaz($nezet, 'Kimenő üzenetek', 'nézet címe');
T::tartalmaz($nezet, 'Elkészült a készüléke', 'a levél a nézetben');
T::tartalmaz($nezet, 'Kész a javítás', 'az SMS a nézetben');
T::nem_tartalmaz($nezet, '<script>alert(1)</script>', 'a levél HTML-je nem fut (escapelve)');
T::tartalmaz(SDH_Demo_Kimeno::html(null, 'sms'), 'Az SMS szövege', 'szűrés: SMS');
T::nem_tartalmaz(SDH_Demo_Kimeno::html(null, 'sms'), 'Elkészült a készüléke', 'szűrés: SMS-nél nincs levél');

// Más látogató nem látja; az admin mindet.
t_latogato('198.51.100.51');
T::nem_tartalmaz(SDH_Demo_Kimeno::html(), 'Elkészült a készüléke', 'más látogató nem látja');
T::tartalmaz(SDH_Demo_Kimeno::html(), 'Még nincs kimenő üzenet', 'üres állapot magyarázattal');
t_admin();
T::tartalmaz(SDH_Demo_Kimeno::html(), 'Elkészült a készüléke', 'az admin mindet látja');

T::vege('t295 bemutató-kimenő üzenetek');
