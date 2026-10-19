<?php
/**
 * t306 – robots.txt: tiltott utak, AI-botok engedve / tiltva a beállítás szerint, Bing külön, sitemap-sor egyszer.
 * Rejtés: a rendszer-útvonalak (wp-admin, wp-json …) sorai kimaradnak (SDH_Sales_Rejtes::robots, 999), a Sitemap-sor a
 * saját /sitemap.xml (a core oldaltérképe ki van kapcsolva); a kimenetben egyik TILTOTT minta sem lehet.
 */
require __DIR__ . '/_keret.php';
require __DIR__ . '/_seo-minta.php';

$core = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: " . home_url('/wp-sitemap.xml') . "\n";
$r = apply_filters('robots_txt', $core, true);
foreach (['/kosar/', '/penztar/', '/fiok/', '/' . rest_get_url_prefix() . '/' . SDH_Sales_Rest::NS . '/'] as $u) {
    T::igaz(preg_match('#^Disallow: /?[^\n]*' . preg_quote($u, '#') . '$#m', $r) === 1, "tiltva: {$u}");
}
T::egyezik('api', rest_get_url_prefix(), 'REST-előtag: api');
T::nem_tartalmaz($r, 'wp-admin', 'a core rendszer-útvonalai kimaradnak');
T::nem_tartalmaz($r, 'wp-sitemap', 'nincs core oldaltérkép');
T::egyezik(1, substr_count($r, 'Sitemap:'), 'egy Sitemap-sor');
T::tartalmaz($r, 'Sitemap: ' . home_url('/sitemap.xml'), 'saját oldaltérkép (/sitemap.xml)');
t_rejtett($r, 'robots.txt (alap)');
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
t_rejtett($r, 'robots.txt (AI-botok tiltva)');
$nn = apply_filters('robots_txt', $core, false);
T::igaz(strpos($nn, 'User-agent: GPTBot') === false && strpos($nn, '/kosar/') === false, 'nem nyilvános webhely: a SEO nem bővíti');
t_rejtett($nn, 'robots.txt (nem nyilvános webhely)');
$r = SDH_Sales_Seo::robots_txt('', true);
T::tartalmaz($r, "User-agent: *\nDisallow:", 'üres bemenetből is * csoport');
T::vege('t306 robots.txt');
