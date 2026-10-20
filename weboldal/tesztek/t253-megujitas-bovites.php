<?php
/**
 * t253 – megújítás és bővítés a láncon át (`tipus=megujitas|bovites`, `licenc_kulcs`), lejárat előtti emlékeztetők
 * (30 / 7 / 1 nap, egyszer küszöbönként, megújító link /fiok/?megujit=<kulcs>, havi licencnél 7 / 1), kézi
 * hosszabbítás után nincs téves levél, egyszeri licencnek nincs emlékeztető.
 */
require __DIR__ . '/_t25-seged.php';

global $wpdb;

/* --- Új licenc ------------------------------------------------------------------------------------------------------ */

$email = 'megujito' . wp_rand(1000, 9999) . '@pelda.hu';
$id    = t25_rendeles(['email' => $email, 'tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start']]]);
t25_fizet($id);
$kulcs = (string) t25_r($id)['licenc_kulcs'];
$l     = SDH_Kozpont::licenc($kulcs);
$lejar = (string) $l->lejar;
T::igaz($l !== null && $lejar !== '', 'alap licenc (Start) kész');
$start_mod = (array) SDH_Kozpont::licenc_modulok($l);
T::igaz(!in_array('sms', $start_mod, true), 'a Start-ban nincs SMS');

/* --- Megújítás ----------------------------------------------------------------------------------------------------- */

$licdb = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('licenc'));
$mid   = t25_rendeles(['email' => $email, 'tipus' => 'megujitas', 'licenc_kulcs' => $kulcs, 'tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start']], 'vevo' => ['ceg' => 'Más Cégnév Kft.', 'adoszam' => '']]);
t25_fizet($mid);
$mr = t25_r($mid);
$uj = wp_date('Y-m-d', (int) strtotime($lejar . ' +12 month'));
T::igaz(!empty($mr['lanc']['kesz']), 'megújítás: a lánc kész (' . (string) ($mr['lanc']['lepesek']['kozpont']['uzenet'] ?? '') . ')');
T::egyezik($uj, (string) SDH_Kozpont::licenc($kulcs)->lejar, 'a Központban: + 12 hónap a régi lejárattól');
T::egyezik($licdb, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('licenc')), 'nincs új licenc');
T::egyezik($kulcs, (string) $mr['licenc_kulcs'], 'ugyanaz a kulcs a rendelésen');
$mk = t25_levelek('megujitas_kesz', $email);
T::egyezik(1, count($mk), 'megújítás-kész levél');
T::tartalmaz((string) ($mk[0]['html'] ?? ''), SDH_Sales_Kiszolgalas::datum($uj), 'a levélben az új lejárat');
T::nem_tartalmaz((string) ($mk[0]['html'] ?? ''), 'letoltes?t=', 'megújításnál nincs letöltő link');
T::egyezik(0, count(t25_levelek('udvozlo', $email)) - 1, 'nem ment új üdvözlő levél');
T::egyezik($uj, SDH_Sales_Kiszolgalas::licencek()[$kulcs]['lejar'] ?? '', 'a tárolt lejárat frissült');
do_action('sdh_sales_fizetve', $mid);
T::egyezik($uj, (string) SDH_Kozpont::licenc($kulcs)->lejar, 'kettős fizetve: nem hosszabbít kétszer');

/* --- Bővítés: modul + felhasználó --------------------------------------------------------------------------------- */

$f0  = (int) SDH_Kozpont::licenc($kulcs)->max_felhasznalo;
$bid = t25_rendeles(['email' => $email, 'tipus' => 'bovites', 'licenc_kulcs' => $kulcs, 'tetelek' => [['tipus' => 'modul', 'kulcs' => 'sms'], ['tipus' => 'modul', 'kulcs' => 'naptar']]]);
// A bővítő rendelés extra felhasználói sora (ahogy az árszámítás bővítésnél adja).
$r = t25_r($bid);
$t = SDH_Sales_Kiszolgalas::tetelek($r);
$t[] = ['tipus' => 'felhasznalo', 'kulcs' => 'start', 'mennyiseg' => 2, 'netto' => 1000];
$wpdb->update(SDH_Sales_Schema::t('rendeles'), ['tetelek' => wp_json_encode($t)], ['id' => $bid]);
t25_fizet($bid);
$br = t25_r($bid);
$l2 = SDH_Kozpont::licenc($kulcs);
$m2 = (array) SDH_Kozpont::licenc_modulok($l2);
T::igaz(!empty($br['lanc']['kesz']), 'bővítés: a lánc kész (' . (string) ($br['lanc']['lepesek']['kozpont']['uzenet'] ?? '') . ')');
T::igaz(in_array('sms', $m2, true) && in_array('naptar', $m2, true), 'az új modulok a licencben');
T::igaz(array_diff($start_mod, $m2) === [], 'a régi modulok megmaradtak');
T::egyezik($f0 + 2, (int) $l2->max_felhasznalo, 'felhasználó: + 2');
T::egyezik($uj, (string) $l2->lejar, 'a lejárat nem változott');
T::egyezik('Start', (string) $l2->csomag, 'a csomag neve nem változott');
$bk = t25_levelek('bovites_kesz', $email);
T::egyezik(1, count($bk), 'bővítés-kész levél');
T::tartalmaz((string) ($bk[0]['html'] ?? ''), 'SMS', 'a levélben az új modul neve');

// Idegen vevő nem bővítheti más licencét.
$iid = t25_rendeles(['email' => 'idegen' . wp_rand(100, 999) . '@pelda.hu', 'tipus' => 'bovites', 'licenc_kulcs' => $kulcs, 'tetelek' => [['tipus' => 'modul', 'kulcs' => 'asszisztens']]]);
t25_fizet($iid);
T::igaz(t25_lepes($iid, 'kozpont')['allapot'] === 'hiba' && strpos((string) t25_lepes($iid, 'kozpont')['uzenet'], 'nem ehhez a vevőhöz') !== false, 'idegen vevő bővítése: elutasítva');
T::igaz(!in_array('asszisztens', (array) SDH_Kozpont::licenc_modulok(SDH_Kozpont::licenc($kulcs)), true), 'a licenc érintetlen');

/* --- Lejárat előtti emlékeztetők ----------------------------------------------------------------------------------- */

$ts  = static fn (string $nap): int => (int) strtotime($nap . ' 10:00:00 UTC');
$lev = static fn (): array => t25_levelek('lejarat_emlekezteto', $email);

T::egyezik(0, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($uj . ' -40 day')))), '40 nappal előtte: semmi');
T::egyezik(1, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($uj . ' -30 day')))), '30 nappal előtte: levél');
$e = $lev()[0] ?? [];
T::tartalmaz((string) ($e['html'] ?? ''), esc_url(home_url('/fiok/?megujit=' . rawurlencode($kulcs))), 'megújító link: /fiok/?megujit=<kulcs>');
T::tartalmaz((string) ($e['targy'] ?? ''), '30 nap', 'tárgy: 30 nap');
T::tartalmaz((string) ($e['html'] ?? ''), $kulcs, 'a levélben a kulcs');
T::egyezik(0, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($uj . ' -29 day')))), 'másnap: nem ismétli');
T::egyezik(1, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($uj . ' -7 day')))), '7 nappal előtte: levél');
T::egyezik(0, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($uj . ' -5 day')))), '5 nappal előtte: semmi');
T::egyezik(1, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($uj . ' -1 day')))), '1 nappal előtte: levél');
T::egyezik(0, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($uj . ' +1 day')))), 'lejárat után: semmi');
T::egyezik(3, count($lev()), 'összesen 3 emlékeztető');

// Kimaradt nap: csak a legközelebbi küszöb megy ki (nem három levél egyszerre).
$id2 = t25_rendeles(['email' => 'kimarad' . wp_rand(100, 999) . '@pelda.hu', 'tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start']]]);
t25_fizet($id2);
$k2 = (string) t25_r($id2)['licenc_kulcs'];
$j2 = (string) SDH_Kozpont::licenc($k2)->lejar;
T::egyezik(1, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($j2 . ' -3 day')))), 'először 3 nappal előtte: egy levél (7 napos)');
T::egyezik(0, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($j2 . ' -2 day')))), 'utána a 7 napos nem ismétlődik');

// Kézi hosszabbítás a Központban (helyi mód): nincs téves levél, az új lejárathoz igazodik.
SDH_Kozpont::licenc_muvelet((int) SDH_Kozpont::licenc($k2)->id, 'megujit');
$j3 = (string) SDH_Kozpont::licenc($k2)->lejar;
T::egyezik(0, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($j2 . ' -1 day')))), 'kézzel megújítva: a régi lejáratra nincs levél');
T::egyezik($j3, SDH_Sales_Kiszolgalas::licencek()[$k2]['lejar'], 'a tárolt lejárat a Központéhoz igazodott');

// Tiltott licenc: nincs emlékeztető.
SDH_Kozpont::licenc_muvelet((int) SDH_Kozpont::licenc($k2)->id, 'tilt');
T::egyezik(0, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($j3 . ' -1 day')))), 'tiltott licenc: nincs emlékeztető');

// Havi licenc: 7 és 1 nap (30 nem).
$id3 = t25_rendeles(['email' => 'havi' . wp_rand(100, 999) . '@pelda.hu', 'ciklus' => 'havi', 'tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start']]]);
t25_fizet($id3);
$k3 = (string) t25_r($id3)['licenc_kulcs'];
$j4 = (string) SDH_Kozpont::licenc($k3)->lejar;
T::egyezik(wp_date('Y-m-d', (int) strtotime(wp_date('Y-m-d') . ' +1 month')), $j4, 'havi licenc: + 1 hónap');
T::egyezik(0, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($j4 . ' -20 day')))), 'havi: 20 nappal előtte semmi');
T::egyezik(1, SDH_Sales_Kiszolgalas::emlekeztetok($ts(wp_date('Y-m-d', strtotime($j4 . ' -7 day')))), 'havi: 7 nappal előtte levél');

// A cron naponta egyszer futtatja (transient).
delete_transient('sdh_sales_lejarat_nap');
$elotte = count($GLOBALS['t25_levelek']);
SDH_Sales_Kiszolgalas::cron($ts(wp_date('Y-m-d', strtotime($j4 . ' -1 day'))));
T::egyezik($elotte + 1, count($GLOBALS['t25_levelek']), 'cron: a napi emlékeztető kiment');
SDH_Sales_Kiszolgalas::cron($ts(wp_date('Y-m-d', strtotime($j4 . ' -1 day'))) + 300);
T::egyezik($elotte + 1, count($GLOBALS['t25_levelek']), 'cron 5 perc múlva: nem fut újra');


// Független ellenőrzés (2026-10-20), alacsony 11: a lejárat-emlékeztető nem írja felül a közben tárolt új licencet.
$opt = 'sdh_sales_licenc_lejar';
$elotte_m = get_option($opt, []);
$kozben   = 'MUNKORA-KOZB-' . wp_rand(1000, 9999);
$egyszer  = false;
add_filter('sdh_sales_email_kuldes', static function ($null, array $l) use ($opt, $kozben, &$egyszer) {
    if (!$egyszer && ($l['sablon'] ?? '') === 'lejarat_emlekezteto') {
        $egyszer = true;
        $m = get_option($opt, []);
        $m[$kozben] = ['lejar' => '2099-01-01', 'ciklus' => 'eves', 'csomag' => 'Pro', 'email' => 'kozben@pelda.hu', 'rendeles_id' => 0, 'user_id' => 0, 'kuldve' => []];
        update_option($opt, $m, false);
    }

    return $null;
}, 5, 2);
$lm = get_option($opt, []);
$lk = 'MUNKORA-EML-' . wp_rand(1000, 9999);
$lm[$lk] = ['lejar' => wp_date('Y-m-d', time() + 2 * DAY_IN_SECONDS), 'ciklus' => 'eves', 'csomag' => 'Pro', 'email' => 'eml@pelda.hu', 'nev' => 'Eml', 'rendeles_id' => 0, 'user_id' => 0, 'kuldve' => []];
update_option($opt, $lm, false);
SDH_Sales::b_ment(['kozpont.mod' => 'tavoli']);
SDH_Sales_Kiszolgalas::emlekeztetok();
SDH_Sales::b_ment(['kozpont.mod' => 'helyi']);
wp_cache_delete($opt, 'options');
$utana = get_option($opt, []);
T::igaz($egyszer && isset($utana[$kozben]), 'alacsony-11: a levelezés közben tárolt licenc megmaradt');
T::igaz(!empty($utana[$lk]['kuldve']), 'alacsony-11: az emlékeztető jele is mentve');

// alacsony 12: bővítésnél a licencben már meglévő modul nem számolódik fel újra
$ado12 = (static function (string $h): string { $s = 0; foreach ([9, 7, 3, 1, 9, 7, 3] as $i => $w) { $s += (int) $h[$i] * $w; } return $h . ((10 - $s % 10) % 10) . '-2-19'; })('7654321');
$tulaj = wp_insert_user(['user_login' => 'bovito' . wp_rand(), 'user_email' => 'bovito' . wp_rand() . '@pelda.hu', 'user_pass' => wp_generate_password(), 'role' => 'sdh_vevo']);
$bid12 = t25_rendeles(['email' => (string) get_userdata((int) $tulaj)->user_email, 'tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start'], ['tipus' => 'modul', 'kulcs' => 'sms']]]);
$GLOBALS['wpdb']->update(SDH_Sales_Schema::t('rendeles'), ['user_id' => (int) $tulaj, 'licenc_kulcs' => 'MUNKORA-B12-' . $bid12, 'allapot' => 'kiszolgalva', 'vevo' => (string) wp_json_encode(['ceg' => 'Bővítő Kft.', 'adoszam' => '', 'email' => (string) get_userdata((int) $tulaj)->user_email, '_opciok' => ['csomag' => 'start', 'extra_modulok' => ['sms'], 'ciklus' => 'havi']])], ['id' => $bid12]);
wp_set_current_user((int) $tulaj);
$b12 = SDH_Sales_Rendeles::letrehoz(['tipus' => 'bovites', 'licenc_kulcs' => 'MUNKORA-B12-' . $bid12, 'tetelek' => [['tipus' => 'modul', 'kulcs' => 'sms'], ['tipus' => 'modul', 'kulcs' => 'naptar']], 'vevo' => ['ceg' => 'Bővítő Kft.', 'adoszam' => $ado12, 'kapcsolattarto' => 'Bő Vítő', 'email' => (string) get_userdata((int) $tulaj)->user_email, 'telefon' => '+36 30 123 4567', 'irsz' => '8200', 'telepules' => 'Veszprém', 'utca' => 'Fő utca 1.'], 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]]);
wp_set_current_user(1);
$tet12 = !empty($b12['ok']) ? array_column((array) SDH_Sales_Rendeles::rendeles((int) $b12['id'])['tetelek'], 'kulcs') : ['hiba: ' . wp_json_encode($b12['hibak'] ?? [], JSON_UNESCAPED_UNICODE)];
T::egyezik(['naptar'], $tet12, 'alacsony-12: bővítés: a már meglévő SMS modul nem számolódik fel újra');

T::vege('t253 megújítás, bővítés, lejárat-emlékeztető');
