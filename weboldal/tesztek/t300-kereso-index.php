<?php
/** t300 – kereső-index: tartalom (minden típus), {t,u,k,s,w,n} formátum, ékezet-hajtás, méret, fájl + gz, verziós URL, újraépítés. */
require __DIR__ . '/_keret.php';
require __DIR__ . '/_seo-minta.php';

$ids = t_seo_minta();
$st  = SDH_Sales_Kereso::epit();
T::igaz($st['ok'], 'index kiírva');
$h    = SDH_Sales_Kereso::hely();
$f    = $h['dir'] . SDH_Sales_Kereso::FAJL;
$json = json_decode((string) file_get_contents($f), true);
T::igaz(is_array($json) && is_array($json['elemek'] ?? null), 'érvényes JSON');
T::egyezik($st['hash'], $json['v'] ?? '', 'hash a fájlban');
T::igaz(is_file($f . '.gz') && gzdecode((string) file_get_contents($f . '.gz')) === file_get_contents($f), 'gz-változat azonos');

$k = array_count_values(array_column($json['elemek'], 'k'));
foreach (['funkcio', 'modul', 'iparag', 'gyik', 'tudas', 'blog', 'oldal'] as $t) {
    T::igaz(($k[$t] ?? 0) > 0, "típus az indexben: {$t}");
}
$hibas = 0;
foreach ($json['elemek'] as $e) {
    $hibas += (array_keys($e) === ['t', 'u', 'k', 's', 'w', 'n'] && $e['t'] !== '' && strpos($e['u'], 'http') === 0 && mb_strlen($e['s']) <= 160) ? 0 : 1;
}
T::egyezik(0, $hibas, 'minden elem {t,u,k,s,w,n}, kivonat ≤ 160');
$fk = array_values(array_filter($json['elemek'], fn ($e) => $e['k'] === 'funkcio' && $e['t'] === 'Számlázás'))[0] ?? [];
T::egyezik(get_permalink($ids['funkciok/szamlazas']), $fk['u'] ?? '', 'funkció URL = landing');
T::tartalmaz($fk['n'] ?? '', 'szamlazas', 'n ékezet nélkül');
T::egyezik($fk['n'] ?? '', strtolower($fk['n'] ?? 'X'), 'n kisbetűs');
T::egyezik('arvizturo tukorfurogep', SDH_Sales_Kereso::hajt('ÁRVÍZTŰRŐ tükörfúrógép!'), 'hajtás ő/ű');
$gy = array_values(array_filter($json['elemek'], fn ($e) => $e['k'] === 'gyik'))[0] ?? [];
T::tartalmaz($gy['u'] ?? '', '/gyik/#gyik-', 'GYIK horgony');
T::nem_tartalmaz((string) file_get_contents($f), '{termeknev}', 'tokenek cserélve');
T::nem_tartalmaz((string) file_get_contents($f), '<script', 'nincs HTML');
$u = array_column($json['elemek'], 'u');
foreach (['kosar', 'penztar', 'fiok', 'kereses'] as $s) {
    T::igaz(!in_array(get_permalink($ids[$s]), $u, true), "segédoldal nincs az indexben: {$s}");
}
T::igaz($st['meret'] < 20000 && $st['meret_gz'] < $st['meret'] / 2, 'kicsi (minta: ' . $st['meret'] . ' B, gz ' . $st['meret_gz'] . ' B)');

$url = SDH_Sales_Kereso::index_url();
T::tartalmaz($url, '/portal/kereso-index.json?v=' . $st['hash'], 'index_url verzióval (semleges mappa)');
T::tartalmaz($h['dir'], '/uploads/portal/', 'index a semleges nevű feltöltési mappában');
$regi = trailingslashit((string) wp_upload_dir(null, false)['basedir']) . 'sdh-sales/';
wp_mkdir_p($regi);
file_put_contents($regi . SDH_Sales_Kereso::FAJL, '{}');
SDH_Sales_Kereso::epit();
T::igaz(!is_file($regi . SDH_Sales_Kereso::FAJL) && !is_dir($regi), 'a régi (árulkodó) index-mappa törölve');
t_rejtett($url, 'index URL (kiszolgált cím)');

// Újraépítés: a tartalom-tár mentése nem küld hookot → a cron ujjlenyomat-ellenőrzése veszi észre.
T::igaz(!SDH_Sales_Kereso::ellenoriz(), 'változás nélkül nincs újraépítés');
sleep(1);
SDH_Sales_Tartalom::ment('funkcio', 'rma', ['kulcs' => 'rma', 'cim' => 'RMA-ügyintézés', 'rovid' => 'Garanciális ügyek.'], null, 9, true, 'hu', false);
T::igaz(SDH_Sales_Kereso::ellenoriz(), 'tartalom-tár változás → újraépít (cron)');
T::tartalmaz((string) file_get_contents($f), 'RMA-ügyintézés', 'új elem az indexben');
T::igaz(SDH_Sales_Kereso::index_url() !== $url, 'új hash az URL-ben');
// save_post → késleltetett (debounce) esemény, csak egy.
wp_clear_scheduled_hook(SDH_Sales_Kereso::ESEMENY);
wp_update_post(['ID' => $ids['arak'], 'post_excerpt' => 'x']);
wp_update_post(['ID' => $ids['gyik'], 'post_excerpt' => 'y']);
$db = 0;
foreach ((array) _get_cron_array() as $ts => $hk) { $db += isset($hk[SDH_Sales_Kereso::ESEMENY]) ? count($hk[SDH_Sales_Kereso::ESEMENY]) : 0; }
T::egyezik(1, $db, 'save_post: egy késleltetett újraépítés');
// betöltés hook → azonnal
do_action('sdh_sales_tartalom_betoltve', [], false);
T::igaz(empty(SDH_Sales_Kereso::info()['piszkos']), 'tartalom_betoltve → azonnal');
t_rejtett((string) file_get_contents($f), 'keresőindex (JSON)');
T::vege('t300 kereső-index (' . $st['db'] . ' elem, ' . $st['meret'] . ' B, gz ' . $st['meret_gz'] . ' B, ' . $st['ms'] . ' ms)');
