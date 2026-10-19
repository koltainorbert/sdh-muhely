<?php
/** t306 – robots.txt: tiltott utak, AI-botok engedve / tiltva a beállítás szerint, Bing külön, sitemap-sor egyszer. */
require __DIR__ . '/_keret.php';

$core = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: " . home_url('/wp-sitemap.xml') . "\n";
$r = apply_filters('robots_txt', $core, true);
foreach (['/kosar/', '/penztar/', '/fiok/', '/wp-json/sdh-sales/'] as $u) {
    T::igaz(preg_match('#^Disallow: /?[^\n]*' . preg_quote($u, '#') . '$#m', $r) === 1, "tiltva: {$u}");
}
T::tartalmaz($r, 'Disallow: /wp-admin/', 'a core sorai megmaradnak');
T::egyezik(1, substr_count($r, 'Sitemap:'), 'egy Sitemap-sor');
T::tartalmaz($r, 'Sitemap: ' . home_url('/wp-sitemap.xml'), 'WP-oldaltérkép (Rank Math nélkül)');
T::igaz(strpos($r, 'Disallow: ' . (string) wp_parse_url(home_url('/kosar/'), PHP_URL_PATH)) < strpos($r, '# AI'), 'tiltás a * csoportban');
foreach (array_merge(SDH_Sales_Seo::AI_BOTOK, ['Bingbot', 'msnbot']) as $b) {
    T::tartalmaz($r, 'User-agent: ' . $b . "\n", "AI-bot listázva: {$b}");
}
$ai = substr($r, (int) strpos($r, '# AI'));
T::tartalmaz($ai, "Allow: /\n", 'AI-botok engedve (alap)');
T::nem_tartalmaz($ai, "Disallow: /\n", 'nincs teljes tiltás');

SDH_Sales::b_ment(['seo.ai_botok' => false]);
$r  = apply_filters('robots_txt', $core, true);
$ai = substr($r, (int) strpos($r, '# AI'));
T::tartalmaz($ai, "User-agent: CCBot\nDisallow: /\n", 'AI-botok tiltva');
T::tartalmaz($ai, 'User-agent: GPTBot', 'GPTBot a tiltó csoportban');
T::nem_tartalmaz($ai, 'Bingbot', 'Bingbot nem tiltva (Bing-kereső marad)');
T::nem_tartalmaz($ai, 'Allow: /', 'nincs Allow');
SDH_Sales::b_ment(['seo.ai_botok_bing' => true]);
$r = apply_filters('robots_txt', $core, true);
T::tartalmaz($r, "User-agent: Bingbot\nUser-agent: msnbot\nDisallow: /\n", 'Bingbot külön kapcsolóval tiltható');
T::egyezik($core, apply_filters('robots_txt', $core, false), 'nem nyilvános webhely: érintetlen');
$r = SDH_Sales_Seo::robots_txt('', true);
T::tartalmaz($r, "User-agent: *\nDisallow:", 'üres bemenetből is * csoport');
T::vege('t306 robots.txt');
