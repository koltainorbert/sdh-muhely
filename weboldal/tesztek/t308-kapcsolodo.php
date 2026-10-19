<?php
/** t308 – belső linkháló (kapcsolodo) és morzsa a témának. */
require __DIR__ . '/_keret.php';
require __DIR__ . '/_seo-minta.php';

$ids = t_seo_minta();
$cim = static fn (array $l): array => array_column($l, 'cim');

$k = SDH_Sales_Seo::kapcsolodo('funkcio', 'munkalapok', 10);
T::igaz(in_array('Számlázás', $cim($k), true), 'funkció → kapcsolódó funkció (funkciok.json kapcsolodo)');
T::igaz(in_array('IMEI- és TAC-keresés', $cim($k), true), 'funkció → második kapcsolódó');
T::igaz(in_array('Telefonszerviz', $cim($k), true), 'funkció → iparág (iparagak.json funkciok)');
T::igaz(in_array('Papír munkalap vagy digitális munkalap?', $cim($k), true), 'funkció → blog (címke / belső link)');
T::igaz(!in_array('Munkalapok', $cim($k), true), 'önmagát nem');
T::egyezik(count($k), count(array_unique(array_column($k, 'url'))), 'nincs ismétlődés');
$sz = array_values(array_filter($k, fn ($x) => $x['tipus'] === 'funkcio' && $x['cim'] === 'Számlázás'))[0] ?? [];
T::egyezik(get_permalink($ids['funkciok/szamlazas']), $sz['url'] ?? '', 'URL a landingre');
T::igaz(isset($sz['leiras']) && $sz['leiras'] !== '', 'leírás is');
T::egyezik(2, count(SDH_Sales_Seo::kapcsolodo('funkcio', 'munkalapok', 2)), 'max');

$k = SDH_Sales_Seo::kapcsolodo('iparag', 'telefonszerviz');
T::egyezik(['Munkalapok', 'IMEI- és TAC-keresés'], array_slice($cim($k), 0, 2), 'iparág → funkciói sorrendben');
$k = SDH_Sales_Seo::kapcsolodo('modul', 'penztar');
T::igaz(in_array('Számlázás', $cim($k), true), 'modul → funkció, amely használja');
$k = SDH_Sales_Seo::kapcsolodo('blog', (string) $ids['blog:1']);
T::igaz(in_array('Munkalapok', $cim($k), true), 'blog → funkció (belső link / címke)');
T::igaz(in_array('IMEI, sorozatszám, telefonszám', $cim($k), true), 'blog → közös címkéjű cikk');
T::igaz(!in_array('Papír munkalap vagy digitális munkalap?', $cim($k), true), 'blog: önmagát nem');
$k = SDH_Sales_Seo::kapcsolodo('gyik', 'ar-1');
T::egyezik(['Munkalapok'], array_slice($cim($k), 0, 1), 'gyik → kapcsolodo');
T::egyezik([], SDH_Sales_Seo::kapcsolodo('funkcio', 'nincs-ilyen'), 'ismeretlen → üres');

// Morzsa
t_lap(home_url('/'));
T::egyezik([], SDH_Sales_Seo::morzsa(), 'főoldal: nincs morzsa');
t_lap(get_permalink($ids['funkciok/munkalapok']));
T::egyezik(['Főoldal', 'Funkciók', 'Munkalapok'], $cim(SDH_Sales_Seo::morzsa()), 'oldal: szülőkkel');
T::egyezik(get_permalink($ids['funkciok']), SDH_Sales_Seo::morzsa()[1]['url'], 'morzsa URL');
t_lap(home_url('/?s=imei'));
T::tartalmaz(SDH_Sales_Seo::morzsa()[1]['cim'] ?? '', 'imei', 'keresés morzsa');

// URL-segéd
T::egyezik(get_permalink($ids['csomagok/pro']), SDH_Sales_Seo::url('csomag', 'pro'), 'csomag-oldal, ha van');
T::egyezik(get_permalink($ids['arak']) . '#csomag-start', SDH_Sales_Seo::url('csomag', 'start'), 'különben az árak horgonya');
T::egyezik(get_permalink($ids['arak']) . '#modul-penztar', SDH_Sales_Seo::url('modul', 'penztar'), 'modul → árak horgony');
t_lap(get_permalink($ids['funkciok/munkalapok']));
t_rejtett(SDH_Sales_Seo::morzsa(), 'morzsa');
t_rejtett(t_seo_fej(), 'funkció-oldal SEO-fej (BreadcrumbList)');
T::vege('t308 kapcsolódó + morzsa');
