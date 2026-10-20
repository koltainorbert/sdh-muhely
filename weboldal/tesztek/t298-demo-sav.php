<?php
/**
 * t298 – a „BEMUTATÓ” sáv: a műhely felületén (felulet_vege), akadálymentesen (régió, élő terület), visszaszámlálással
 * a 03:00-s visszaállításig, a hátralévő kerettel (mentés után csökken), link a Kimenő üzenetekre és az értékesítő
 * webhelyre; kijelentkezve / más felhasználónak nincs.
 */
require __DIR__ . '/_t29-seged.php';

$u = t_latogato('198.51.100.80');

ob_start();
do_action('sdh_muhely_felulet_vege');
$html = (string) ob_get_clean();

T::tartalmaz($html, '>BEMUTATÓ<', 'a sáv a műhely felületének végén');
T::tartalmaz($html, 'role="region"', 'régió');
T::tartalmaz($html, 'aria-label="Bemutató tájékoztató"', 'akadálymentes címke');
T::tartalmaz($html, 'aria-live="polite"', 'a keret változása felolvasva');
T::tartalmaz($html, 'Még <b data-bm-keret>10</b> / 10 új rekord ma', 'teljes keret');
T::tartalmaz($html, 'Visszaállítás 03:00-kor', 'a visszaállítás ideje');
T::igaz(preg_match('/még <b data-bm-hatra>(\d+ óra )?\d+ perc<\/b>/', $html) === 1, 'visszaszámlálás');
T::tartalmaz($html, esc_url(SDH_Muhely_Modulok::frontend_url(SDH_Demo_Kimeno::KULCS)), 'link: Kimenő üzenetek');
T::nem_tartalmaz($html, 'Megrendelem', 'értékesítő link csak beállítva');
T::tartalmaz($html, 'bemutato=allapot', 'frissítés a semleges állapot-címről');
T::tartalmaz($html, '@media print', 'nyomtatásban nem látszik');

$a = SDH_Demo_Sav::adatok();
$tz = new DateTimeZone('Europe/Budapest');
T::egyezik('03:00', (new DateTimeImmutable('@' . $a['visszaallitas']))->setTimezone($tz)->format('H:i'), 'a következő visszaállítás 03:00 (Budapest)');
T::igaz($a['visszaallitas'] > time() && $a['visszaallitas'] <= time() + DAY_IN_SECONDS + HOUR_IN_SECONDS, '24 órán belül');
T::igaz(abs($a['lejar'] - (time() + DAY_IN_SECONDS)) < 60, 'a fiók lejárata az adatokban');

// Mentés után a keret csökken.
t_ugyfel('Sáv Teszt 1');
t_ugyfel('Sáv Teszt 2');
t_ugyfel('Sáv Teszt 3');
SDH_Demo_Keret::felejt();
T::egyezik(7, SDH_Demo_Sav::adatok()['keret'], 'három mentés után 7');
T::tartalmaz(SDH_Demo_Sav::html(), 'Még <b data-bm-keret>7</b> / 10', 'a sávban is 7');

// Értékesítő webhely.
SDH_Demo::ment(['ertekesito_url' => 'https://ertekesito.pelda.hu/arak/']);
$h = SDH_Demo_Sav::html();
T::tartalmaz($h, 'href="https://ertekesito.pelda.hu/arak/"', 'értékesítő link');
T::tartalmaz($h, 'rel="noopener"', 'új lapon, biztonságosan');

// Kifogyott keret: kiemelve.
for ($i = 0; $i < 7; $i++) {
    t_ugyfel('Sáv Teszt +' . $i);
}

SDH_Demo_Keret::felejt();
T::tartalmaz(SDH_Demo_Sav::html(), 'bm-sav__keret--nulla', 'elfogyott keret kiemelve');

// Kijelentkezve és sima munkatársnak nincs sáv.
wp_set_current_user(0);
ob_start();
SDH_Demo_Sav::kiir();
T::egyezik('', (string) ob_get_clean(), 'kijelentkezve nincs sáv');
$munkatars = wp_insert_user(['user_login' => 'sav-munkatars', 'user_pass' => wp_generate_password(), 'user_email' => 'sav@pelda.hu', 'role' => 'subscriber']);
wp_set_current_user((int) $munkatars);
ob_start();
SDH_Demo_Sav::kiir();
T::egyezik('', (string) ob_get_clean(), 'nem bemutató-fióknak nincs sáv');

T::vege('t298 bemutató-sáv');
