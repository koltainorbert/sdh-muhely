<?php
/** t301 – kereső REST: elírástűrés, ékezet-függetlenség, rangsor, korlát, válaszidő. */
require __DIR__ . '/_keret.php';
require __DIR__ . '/_seo-minta.php';

t_seo_minta();
SDH_Sales_Kereso::epit();
$elso = static function (string $q): array {
    $r = t_latogato(fn () => t_rest('GET', '/kereso', ['q' => $q]));
    return [$r->get_status(), $r->get_data()['talalatok'][0] ?? [], $r->get_data()];
};
[$s, $t] = $elso('munklap');
T::egyezik(200, $s, 'nyilvános');
T::egyezik('Munkalapok', $t['t'] ?? '', '„munklap” → Munkalapok');
[, $t] = $elso('szamlazas');
T::egyezik('Számlázás', $t['t'] ?? '', '„szamlazas” → Számlázás');
[, $t] = $elso('SZÁMLÁZÁS');
T::egyezik('Számlázás', $t['t'] ?? '', 'kis/nagybetű, ékezet');
[, $t] = $elso('szamlazs');
T::egyezik('Számlázás', $t['t'] ?? '', '„szamlazs” (kihagyott betű)');
[, $t] = $elso('telefonszervz');
T::egyezik('Telefonszerviz', $t['t'] ?? '', '„telefonszervz” → Telefonszerviz');
[, $t] = $elso('imei');
T::egyezik('funkcio', $t['k'] ?? '', 'IMEI: funkció elöl');
[, $t] = $elso('mennyibe kerul');
T::egyezik('gyik', $t['k'] ?? '', 'GYIK-kérdés megtalálva');
[, $t] = $elso('digitalis munkalap');
T::igaz(in_array($t['t'] ?? '', ['Munkalapok', 'Papír munkalap vagy digitális munkalap?'], true), 'kulcsszó-egyezés (' . ($t['t'] ?? '') . ')');
[, , $d] = $elso('xyzqw');
T::egyezik(0, $d['db'] ?? -1, 'értelmetlen szóra nincs találat');
$r = t_latogato(fn () => t_rest('GET', '/kereso', ['q' => 'a']));
T::egyezik(400, $r->get_status(), 'túl rövid → 400');
$r = t_latogato(fn () => t_rest('GET', '/kereso', ['q' => '<script>alert(1)</script>munkalap']));
T::nem_tartalmaz((string) wp_json_encode($r->get_data()), '<script', 'bemenet tisztítva');
T::igaz(str_contains((string) ($r->get_headers()['Cache-Control'] ?? ''), 'no-store'), 'nem gyorsítótárazható');

// Válaszidő (mérés): 200 elemes index, 20 lekérdezés.
$sok = [];
for ($i = 0; $i < 200; $i++) { $sok[] = ['t' => 'Elem ' . $i . ' munkalap szerviz ' . md5((string) $i), 'u' => 'http://x/' . $i, 'k' => 'blog', 's' => str_repeat('szöveg ', 20), 'w' => 'kulcs ' . $i, 'n' => '']; }
$kezd = microtime(true);
foreach (['munklap', 'szamlazas', 'telefon szerviz', 'imei', 'garancia rma'] as $q) { for ($j = 0; $j < 4; $j++) { SDH_Sales_Kereso::keres($q, 10, $sok); } }
$ms = (microtime(true) - $kezd) * 1000 / 20;
T::igaz($ms < 50, 'keresés 200 elemen < 50 ms (mért: ' . round($ms, 2) . ' ms)');

// Korlát: 60 / perc / IP.
remove_all_filters('sdh_sales_korlat_ki');
$kod = 0;
for ($i = 0; $i < 62; $i++) { $kod = t_latogato(fn () => t_rest('GET', '/kereso', ['q' => 'munkalap']))->get_status(); }
T::egyezik(429, $kod, 'rate limit → 429');
T::vege('t301 kereső REST (' . round($ms, 2) . ' ms/lekérdezés, 200 elem)');
