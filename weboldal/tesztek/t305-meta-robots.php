<?php
/** t305 – meta-tartalék (title/description/canonical/OG/Twitter), noindex a segédoldalakon és a keresésen, oldaltérkép-szűrés, hreflang. */
require __DIR__ . '/_keret.php';
require __DIR__ . '/_seo-minta.php';

$ids = t_seo_minta();
t_lap(get_permalink($ids['arak']));
$f = t_fej();
T::tartalmaz($f, '<meta name="description" content="Csomagok és árak.">', 'description');
T::tartalmaz($f, '<link rel="canonical" href="' . get_permalink($ids['arak']) . '">', 'canonical');
T::egyezik(1, substr_count($f, 'rel="canonical"') + substr_count($f, "rel='canonical'"), 'egy canonical');
T::tartalmaz($f, '<meta property="og:title" content="Árak – ' . SDH_Sales::termeknev() . '">', 'og:title tokennel');
T::tartalmaz($f, '<meta property="og:image" content="https://example.com/og.png">', 'og:image a beállításból');
T::tartalmaz($f, '<meta name="twitter:card" content="summary_large_image">', 'twitter:card');
T::egyezik('Árak – ' . SDH_Sales::termeknev(), SDH_Sales_Seo::dokumentum_cim('x'), '<title>');
t_rejtett(t_seo_fej(), 'árak-oldal SEO-fej (meta, OG, Twitter, JSON-LD)');
T::nem_tartalmaz($f, 'noindex', 'árak indexelhető');

foreach (['kosar', 'penztar', 'fiok', 'kereses'] as $s) {
    t_lap(get_permalink($ids[$s]));
    $f = t_fej();
    T::tartalmaz($f, 'noindex', "noindex: {$s}");
    T::tartalmaz($f, 'follow', "follow: {$s}");
}
t_lap(home_url('/?s=munkalap'));
T::tartalmaz(t_fej(), 'noindex', 'noindex: keresési találatok');
t_lap(home_url('/?s=munkalap&paged=2'));
T::tartalmaz(t_fej(), 'noindex', 'noindex: lapozott keresés');
T::igaz(SDH_Sales_Seo::rm_robots(['index' => 'index'])['index'] === 'noindex', 'Rank Math robots-szűrő is');
t_lap(get_permalink($ids['funkciok/munkalapok']));
T::nem_tartalmaz(t_fej(), 'noindex', 'funkció indexelhető');
T::egyezik(['index' => 'index'], SDH_Sales_Seo::rm_robots(['index' => 'index']), 'Rank Math: normál oldal változatlan');

// Oldaltérkép
$args = SDH_Sales_Seo::sitemap_args([], 'page');
foreach (['kosar', 'penztar', 'fiok', 'kereses'] as $s) {
    T::igaz(in_array($ids[$s], $args['post__not_in'], true), "oldaltérképből kizárva: {$s}");
}
T::igaz(!in_array($ids['arak'], $args['post__not_in'], true), 'árak az oldaltérképben');
T::igaz(SDH_Sales_Seo::rm_sitemap_kizar(false, get_post($ids['kosar'])), 'Rank Math oldaltérkép: kosár kizárva');
T::igaz(SDH_Sales_Seo::sitemap_szolgaltato(new stdClass(), 'users') === false, 'szerzői oldaltérkép ki');

// hreflang: csak 1-nél több nyelvnél
t_lap(get_permalink($ids['arak']));
T::nem_tartalmaz(t_fej(), 'hreflang', 'egy nyelv → nincs hreflang');
SDH_Sales::b_ment(['nyelvek' => ['hu', 'en', 'de']]);
t_lap(get_permalink($ids['arak']));
$f = t_fej();
T::tartalmaz($f, 'hreflang="hu" href="' . get_permalink($ids['arak']) . '"', 'hreflang hu = gyökér');
T::tartalmaz($f, 'hreflang="en" href="' . home_url('/en/arak/') . '"', 'hreflang en = /en/');
T::tartalmaz($f, 'hreflang="x-default"', 'x-default');
t_rejtett(t_seo_fej(), 'SEO-fej hreflanggal');
T::vege('t305 meta + robots');
