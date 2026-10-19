<?php
/** t303 – séma Rank Math mellett (szimulálva: RANK_MATH_VERSION): nincs Organization/WebSite/Article, nincs duplikált @type. */
require __DIR__ . '/_keret.php';
require __DIR__ . '/_seo-minta.php';

define('RANK_MATH_VERSION', '1.0.250');
$ids = t_seo_minta();
T::igaz(SDH_Sales_Seo::rank_math(), 'Rank Math érzékelve');

$tiltott = ['Organization', 'WebSite', 'Article', 'BlogPosting', 'LocalBusiness'];
SDH_Sales::b_ment(['seo.localbusiness' => true]);
$lapok = [
    'fooldal'   => [home_url('/'), ['SoftwareApplication']],
    'arak'      => [get_permalink($ids['arak']), ['SoftwareApplication']],
    'funkcio'   => [get_permalink($ids['funkciok/munkalapok']), []],
    'csomag'    => [get_permalink($ids['csomagok/pro']), ['Product']],
    'telepites' => [get_permalink($ids['telepites']), ['HowTo']],
    'esemeny'   => [get_permalink($ids['esemenyek']), ['Event']],
    'blog'      => [get_permalink($ids['blog:1']), []],
];
foreach ($lapok as $nev => [$url, $kell]) {
    t_lap($url);
    if ($nev === 'funkcio') {
        SDH_Sales_Gyik::szekcio(['kulcsok' => ['ar-1']]);
    }
    $html = t_fej() . t_lab();
    [$b, $tip, $jo] = t_ld($html);
    T::igaz($jo, "{$nev}: érvényes JSON-LD");
    T::egyezik($tip, array_values(array_unique($tip)), "{$nev}: nincs duplikált @type");
    foreach ($tiltott as $t) {
        T::igaz(!in_array($t, $tip, true), "{$nev}: nincs {$t}");
    }
    foreach ($kell as $t) {
        T::igaz(in_array($t, $tip, true), "{$nev}: van {$t}");
    }
    T::nem_tartalmaz($html, 'og:title', "{$nev}: meta a Rank Mathé");
    T::nem_tartalmaz($html, 'name="description"', "{$nev}: leírás a Rank Mathé");
}
t_lap(get_permalink($ids['funkciok/munkalapok']));
SDH_Sales_Gyik::szekcio(['kulcsok' => ['ar-1']]);
[, $tip] = t_ld(t_fej() . t_lab());
T::igaz(in_array('FAQPage', $tip, true), 'FAQPage Rank Math mellett is (a megjelent kérdésekből)');
T::igaz(in_array('BreadcrumbList', $tip, true), 'BreadcrumbList, ha a Rank Math morzsa ki van kapcsolva');
update_option('rank-math-options-general', ['breadcrumbs' => 'on']);
t_lap(get_permalink($ids['funkciok/munkalapok']));
[, $tip] = t_ld(t_fej());
T::igaz(!in_array('BreadcrumbList', $tip, true), 'nincs BreadcrumbList, ha a Rank Math morzsa be van kapcsolva');
// Az entitás-hivatkozás a Rank Math @id-jére mutat.
t_lap(home_url('/'));
[$b] = t_ld(t_fej());
T::egyezik(home_url('/') . '#organization', t_ld_csomopont($b, 'SoftwareApplication')['publisher']['@id'] ?? '', 'publisher → /#organization');
// robots.txt: Rank Math sitemap
T::tartalmaz(SDH_Sales_Seo::robots_txt("User-agent: *\nDisallow:\n", true), 'Sitemap: ' . home_url('/sitemap_index.xml'), 'Rank Math sitemap_index.xml');
T::vege('t303 séma Rank Math mellett');
