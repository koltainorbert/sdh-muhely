<?php
/**
 * t292 – látogatói rekordkeret: 10 új rekord / cím / 24 óra, a 11. érthető magyar hibával áll le (a műhely AJAX-hibaútján:
 * success=false, data.uzenet, 429), szerkesztés és gyereksor közben mehet, másik cím külön keret, az admin nem korlátos,
 * a felvett rekordok azonosítója naplózva.
 */
require __DIR__ . '/_t29-seged.php';

global $wpdb;

$u  = t_latogato('198.51.100.20');
$id = [];

for ($i = 1; $i <= 10; $i++) {
    $e = t_megallit(static function () use (&$id, $i): void {
        $id[] = t_ugyfel('Keret Teszt ' . $i);
    });
    T::igaz($e === null, $i . '. új rekord mehet');
}

T::egyezik(0, SDH_Demo_Keret::maradek(), 'a keret elfogyott');

$e = t_megallit(static function (): void {
    t_ugyfel('Keret Teszt 11');
});
T::igaz($e instanceof SDH_Demo_Megallitva && $e->getCode() === 429, 'a 11. új rekord: leállítva (429)');
T::tartalmaz($e ? $e->getMessage() : '', 'legfeljebb 10 új rekord', 'érthető magyar üzenet');
T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE nev = %s', 'Keret Teszt 11')), 'a 11. nem került be');

// Más tábla is „új rekord” (eszköz), szerkesztés és gyereksor viszont mehet.
$e = t_megallit(static function () use ($wpdb): void {
    $wpdb->insert(SDH_Muhely_Schema::tabla('eszkoz'), ['ugyfel_id' => 1, 'megnevezes' => 'Teszt']);
});
T::igaz($e instanceof SDH_Demo_Megallitva, 'új eszköz is a kereten belül számít');
$e = t_megallit(static function () use ($wpdb, $id): void {
    $wpdb->update(SDH_Muhely_Schema::tabla('ugyfel'), ['nev' => 'Átírt név'], ['id' => $id[0]]);
    $wpdb->insert(SDH_Muhely_Schema::tabla('ugyfel_szo'), ['szo' => 'atirt', 'ugyfel_id' => $id[0]]);
    $wpdb->insert(SDH_Muhely_Schema::tabla('valtozasnaplo'), ['tabla' => 'ugyfel', 'rekord_id' => $id[0]]);
});
T::igaz($e === null, 'szerkesztés, szó-index, napló a keret után is mehet');

// A felvett rekordok azonosítója naplózva (a 24 órás takarításhoz).
$naplozott = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT rekord_id FROM ' . SDH_Demo::tabla('naplo') . " WHERE tipus = 'rekord' AND felhasznalo = %d ORDER BY id", $u)));
T::egyezik($id, $naplozott, 'a rekordok azonosítói naplózva');

// Másik cím: saját keret; az admin és a parancssor nem korlátos.
$masik = t_latogato('198.51.100.21');
T::egyezik(10, SDH_Demo_Keret::maradek(), 'másik cím: teljes keret');
T::igaz(t_megallit(static fn () => t_ugyfel('Másik cím')) === null, 'másik címről mehet');
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
t_admin();
T::igaz(t_megallit(static fn () => t_ugyfel('Admin rekord')) === null, 'az admin nem korlátos');
wp_set_current_user(0);
SDH_Demo_Keret::felejt();
T::igaz(t_megallit(static fn () => t_ugyfel('Parancssor')) === null, 'parancssor / ütemező nem korlátos');

// Kijelentkezett webes kérés (pl. ügyféloldal, online foglalás) ugyanúgy a cím keretét fogyasztja.
SDH_Demo_Keret::$cli_anonim = true;
SDH_Demo_Keret::felejt();
$e = t_megallit(static fn () => t_ugyfel('Névtelen'));
T::igaz($e instanceof SDH_Demo_Megallitva, 'kijelentkezett kérés is a cím keretéből (itt elfogyott)');
SDH_Demo_Keret::$cli_anonim = false;

// A keret állítható.
SDH_Demo::ment(['keret' => 12]);
wp_set_current_user($u);
SDH_Demo_Keret::felejt();
T::egyezik(2, SDH_Demo_Keret::maradek(), 'a keret a beállításból jön');
SDH_Demo::ment(['keret' => 10]);

// Az éles hibaút: a műhely AJAX-válasza (JSON, success=false, data.uzenet), 429.
$ki = t_folyamat("define('DOING_AJAX', true); \$_POST['action'] = \$_REQUEST['action'] = 'sdh_muhely_ugyfelek_ment';\nrequire '" . ABSPATH . "wp-load.php';\n"
    . "global \$wpdb; wp_set_current_user({$u}); \$_SERVER['REMOTE_ADDR'] = '198.51.100.20';\n"
    . "add_filter('wp_die_ajax_handler', static fn () => static function (\$m) { exit; });\n"
    . "\$wpdb->insert(SDH_Muhely_Schema::tabla('ugyfel'), ['nev' => 'AJAX 11']);\necho 'NEM ALLT LE';");
$j  = json_decode(trim($ki), true);
T::igaz(is_array($j) && $j['success'] === false && !empty($j['data']['bemutato']), 'AJAX: success=false JSON (' . substr(trim($ki), 0, 80) . ')');
T::tartalmaz((string) ($j['data']['uzenet'] ?? ''), 'Elérted a bemutató napi keretét', 'AJAX: data.uzenet a magyar szöveg');
T::nem_tartalmaz($ki, 'NEM ALLT LE', 'AJAX: a kérés leállt');

T::vege('t292 bemutató-keret');
