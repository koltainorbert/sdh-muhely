<?php
/** t304 – séma Rank Math nélkül: Organization, WebSite+SearchAction, LocalBusiness, Article, BreadcrumbList, Product, HowTo, Event – kötelező mezők. */
require __DIR__ . '/_keret.php';
require __DIR__ . '/_seo-minta.php';

$ids = t_seo_minta();
T::igaz(!SDH_Sales_Seo::rank_math(), 'nincs Rank Math');

t_lap(home_url('/'));
[$b, $tip, $jo] = t_ld(t_fej());
T::igaz($jo, 'érvényes JSON-LD');
T::egyezik($tip, array_values(array_unique($tip)), 'nincs duplikált @type');
$o = t_ld_csomopont($b, 'Organization');
T::egyezik('Teszt Kft.', $o['name'] ?? '', 'Organization.name a beállításból');
T::egyezik(['https://www.facebook.com/teszt'], $o['sameAs'] ?? [], 'sameAs: csak érvényes URL');
T::egyezik('ContactPoint', $o['contactPoint']['@type'] ?? '', 'contactPoint');
T::egyezik('8200', $o['address']['postalCode'] ?? '', 'cím: irányítószám');
T::egyezik('Veszprém', $o['address']['addressLocality'] ?? '', 'cím: település');
$w = t_ld_csomopont($b, 'WebSite');
T::egyezik(SDH_Sales::termeknev(), $w['name'] ?? '', 'WebSite.name = termeknev');
T::egyezik('Munkora', SDH_Sales::termeknev(), 'terméknév-helyőrző: Munkora');
t_rejtett(t_seo_fej(), 'főoldal SEO-fej (meta + JSON-LD)');
T::egyezik(home_url('/?s={search_term_string}'), $w['potentialAction']['target']['urlTemplate'] ?? '', 'SearchAction → WP-keresés');
T::egyezik('required name=search_term_string', $w['potentialAction']['query-input'] ?? '', 'query-input');
T::igaz(!in_array('LocalBusiness', $tip, true), 'LocalBusiness alapból nincs');
$s = t_ld_csomopont($b, 'SoftwareApplication');
T::egyezik('AggregateOffer', $s['offers']['@type'] ?? '', 'SoftwareApplication: AggregateOffer');
T::egyezik(9900, $s['offers']['lowPrice'] ?? 0, 'lowPrice (nettó, havi)');
T::egyezik(19900, $s['offers']['highPrice'] ?? 0, 'highPrice');
T::egyezik('HUF', $s['offers']['priceCurrency'] ?? '', 'HUF');
T::egyezik(false, $s['offers']['offers'][0]['priceSpecification'][0]['valueAddedTaxIncluded'] ?? null, 'valueAddedTaxIncluded=false (nettó)');
T::igaz(isset($s['name'], $s['applicationCategory'], $s['operatingSystem']), 'SoftwareApplication kötelező mezők');
T::tartalmaz(implode(',', $s['featureList'] ?? []), 'Munkalapok', 'featureList');

SDH_Sales::b_ment(['seo.localbusiness' => true, 'seo.ar_brutto' => true, 'seo.geo' => '47.09, 17.91']);
t_lap(home_url('/'));
[$b, $tip] = t_ld(t_fej());
$l = t_ld_csomopont($b, 'LocalBusiness');
T::igaz($l !== null && isset($l['address'], $l['name']), 'LocalBusiness (beállítással + címmel)');
T::egyezik(47.09, $l['geo']['latitude'] ?? 0, 'geo');
T::egyezik(12573, t_ld_csomopont($b, 'SoftwareApplication')['offers']['lowPrice'] ?? 0, 'bruttó ár beállítással');
SDH_Sales::b_ment(['seo.ar_brutto' => false]);

t_lap(get_permalink($ids['csomagok/pro']));
[$b, $tip] = t_ld(t_fej());
$p = t_ld_csomopont($b, 'Product');
T::egyezik(SDH_Sales::termeknev() . ' – Pro', $p['name'] ?? '', 'Product.name');
t_rejtett(t_seo_fej(), 'csomag-oldal SEO-fej (Product JSON-LD)');
T::egyezik(19900, $p['offers']['price'] ?? 0, 'Offer.price');
T::egyezik('https://schema.org/InStock', $p['offers']['availability'] ?? '', 'Offer.availability');
T::egyezik('P1M', $p['offers']['priceSpecification'][0]['billingDuration'] ?? '', 'havi billingDuration');
T::egyezik(199000, $p['offers']['priceSpecification'][1]['price'] ?? 0, 'éves ár');
T::igaz(in_array('BreadcrumbList', $tip, true), 'BreadcrumbList');
T::egyezik(3, count(t_ld_csomopont($b, 'BreadcrumbList')['itemListElement'] ?? []), 'morzsa: 3 szint');

t_lap(get_permalink($ids['telepites']));
[$b] = t_ld(t_fej());
$h = t_ld_csomopont($b, 'HowTo');
T::egyezik(2, count($h['step'] ?? []), 'HowTo: 2 lépés (a követelmény nem lépés)');
T::egyezik('Töltsd le a csomagot', $h['step'][0]['name'] ?? '', 'HowToStep.name');
T::egyezik('PHP 8.1 vagy újabb', $h['tool'][0]['name'] ?? '', 'HowToTool a követelményből');

t_lap(get_permalink($ids['esemenyek']));
[$b] = t_ld(t_fej());
$e = t_ld_csomopont($b, 'Event');
T::igaz(isset($e['name'], $e['startDate'], $e['location'], $e['eventAttendanceMode'], $e['organizer']), 'Event kötelező + ajánlott mezők');
T::egyezik('VirtualLocation', $e['location']['@type'] ?? '', 'online esemény');
T::igaz(strtotime($e['startDate'] ?? '') !== false && str_contains($e['startDate'] ?? '', 'T10:00'), 'startDate ISO 8601');

t_lap(get_permalink($ids['blog:1']));
[$b, $tip] = t_ld(t_fej());
$a = t_ld_csomopont($b, 'BlogPosting');
T::igaz(isset($a['headline'], $a['datePublished'], $a['author'], $a['publisher']), 'Article kötelező mezők');
T::egyezik(home_url('/') . '#organization', $a['author']['@id'] ?? '', 'szerző = cég');
SDH_Sales::b_ment(['seo.szerzo' => 'Koltai Norbert']);
t_lap(get_permalink($ids['blog:1']));
[$b] = t_ld(t_fej());
T::egyezik('Person', t_ld_csomopont($b, 'BlogPosting')['author']['@type'] ?? '', 'megnevezett szerző');
T::egyezik(['Főoldal', 'Blog', 'Munkalap és nyilvántartás', 'Papír munkalap vagy digitális munkalap?'], array_column(SDH_Sales_Seo::morzsa(), 'cim'), 'morzsa(): bejegyzés');

// JSON-LD HTML-ben biztonságos.
SDH_Sales::b_ment(['termeknev' => 'X</script><b>']);
t_lap(home_url('/'));
$f = t_fej();
T::nem_tartalmaz($f, 'X</script><b>', 'JSON-LD-ben nincs nyers </script>');
[, , $jo] = t_ld($f);
T::igaz($jo, 'érvényes marad');
T::vege('t304 séma Rank Math nélkül');
