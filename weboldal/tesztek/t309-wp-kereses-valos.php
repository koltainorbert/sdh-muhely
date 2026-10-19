<?php
/** t309 – WordPress-keresés a tartalom-tárral + élő HTTP (llms.txt, robots.txt, index-fájl) + a valós data/ tartalom, ha már létezik. */
require __DIR__ . '/_keret.php';
require __DIR__ . '/_seo-minta.php';

$ids = t_seo_minta();
SDH_Sales_Kereso::epit();

// WP-keresés: „munklap” (elírás) a core-keresőben nem talál semmit, a tartalom-tárral a funkció-oldal megjön.
$q = new WP_Query(['s' => 'munklap', 'post_type' => ['post', 'page']]);
T::igaz(in_array($ids['funkciok/munkalapok'], wp_list_pluck($q->posts, 'ID'), true), 'WP-keresés: elírt szóra a landing');
$q = new WP_Query(['s' => 'IMEI', 'post_type' => ['post', 'page']]);
T::igaz($q->found_posts >= 1, 'WP-keresés: normál találat megmarad');
t_lap(home_url('/?s=kosar'));
T::igaz(!in_array($ids['kosar'], wp_list_pluck($GLOBALS['wp_query']->posts, 'ID'), true), 'segédoldal nincs a keresésben');
t_lap(home_url('/?s=szamlazas'));
T::egyezik($ids['funkciok/szamlazas'], (int) ($GLOBALS['wp_query']->posts[0]->ID ?? 0), 'ékezet nélkül: Számlázás elöl');

// Élő HTTP a beépített szerveren (a saját másolat, saját port).
$gyoker = dirname(ABSPATH);
$port   = 8791;
update_option('home', 'http://127.0.0.1:' . $port . '/wp');
update_option('siteurl', 'http://127.0.0.1:' . $port . '/wp');
$napl = sys_get_temp_dir() . '/t309-' . getmypid() . '.log';
$rout = sys_get_temp_dir() . '/t309-router-' . getmypid() . '.php';
file_put_contents($rout, '<?php $f = ' . var_export($gyoker, true) . ' . parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if (is_file($f) && substr($f, -4) !== ".php") { return false; } chdir(' . var_export(rtrim(ABSPATH, '/'), true) . '); require "index.php";');
$pr   = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $gyoker, $rout], [1 => ['file', $napl, 'a'], 2 => ['file', $napl, 'a']], $cs);
usleep(600000);
$kerj = static function (string $ut) use ($port): array {
    $c = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 15]]);
    $t = @file_get_contents('http://127.0.0.1:' . $port . $ut, false, $c);
    return [(string) $t, implode("\n", $http_response_header ?? [])];
};
[$t, $f] = $kerj('/wp/llms.txt');
T::tartalmaz($f, '200', 'élő /llms.txt: 200');
T::tartalmaz($f, 'text/plain; charset=utf-8', 'élő: Content-Type');
T::igaz(str_starts_with($t, '# SDH CRM'), 'élő: H1');
[$t, $f] = $kerj('/wp/llms-full.txt');
T::tartalmaz($t, '9 900 Ft', 'élő llms-full: ár');
// A WP csak gyökérbe telepítve szolgálja ki a /robots.txt-t; alkönyvtárban a ?robots=1 ugyanazt a kimenetet adja.
[$t, $f] = $kerj('/wp/?robots=1');
T::tartalmaz($t, 'User-agent: ClaudeBot', 'élő robots.txt: AI-botok');
T::tartalmaz($t, 'Disallow: /wp/kosar/', 'élő robots.txt: kosár tiltva');
[$t, $f] = $kerj((string) wp_parse_url(SDH_Sales_Kereso::index_url(), PHP_URL_PATH));
T::igaz(is_array(json_decode($t, true)), 'élő: index-fájl elérhető');
[$t] = $kerj('/wp/kosar/');
T::tartalmaz($t, 'noindex', 'élő: kosár noindex');
proc_terminate($pr);
proc_close($pr);
@unlink($napl);
@unlink($rout);
update_option('home', 'http://127.0.0.1:8765/wp');
update_option('siteurl', 'http://127.0.0.1:8765/wp');

// Valós tartalom (data/): ha a tartalom ügynökök már írtak, a betöltés után is érvényes minden kimenet.
$adat = SDH_SALES_DIR . 'data/';
$van  = is_file($adat . 'funkciok.json') || is_file($adat . 'gyik.json') || glob($adat . 'oldalak/*.json');
if ($van) {
    SDH_Sales_Tartalom::betolt(false);
    SDH_Sales_Seo::urit();
    $st = SDH_Sales_Kereso::epit();
    T::igaz($st['ok'] && $st['db'] > 0, 'valós: index (' . $st['db'] . ' elem, ' . $st['meret'] . ' B, gz ' . $st['meret_gz'] . ' B)');
    T::igaz($st['meret_gz'] < 150000, 'valós: gz < 150 KB');
    $l = SDH_Sales_Llms::general(false);
    T::igaz(str_starts_with($l, '# ') && str_contains($l, "\n> "), 'valós: llms.txt formátum (' . strlen($l) . ' B)');
    $lf = SDH_Sales_Llms::general(true);
    T::nem_tartalmaz($lf, '<script', 'valós: llms-full tiszta (' . strlen($lf) . ' B)');
    foreach (SDH_Sales_Gyik::lista() as $g) {
        if (preg_match('/<(?!\/?(p|ul|ol|li|strong|b|em|i|br|code|a)\b)/i', $g['valasz'])) {
            T::igaz(false, 'valós GYIK: nem engedett elem: ' . $g['kulcs']);
        }
    }
    T::igaz(count(SDH_Sales_Gyik::lista()) >= 0, 'valós GYIK: ' . count(SDH_Sales_Gyik::lista()) . ' kérdés');
    $kezd = microtime(true);
    foreach (['munklap', 'szamlazas', 'imei', 'garancia', 'telefonszerviz'] as $q) { SDH_Sales_Kereso::keres($q, 10); }
    T::igaz(true, 'valós: keresés ' . round((microtime(true) - $kezd) * 200, 2) . ' ms/lekérdezés');
} else {
    T::igaz(true, 'valós tartalom még nincs (data/ üres) – kihagyva');
}
T::vege('t309 WP-keresés + élő HTTP + valós tartalom');
