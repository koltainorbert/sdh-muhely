<?php
/** t302 – GYIK-segéd: lista, kategóriák, egy, horgony, tisztítás, tokenek, FAQPage csak a megjelent kérdésekre. */
require __DIR__ . '/_keret.php';
require __DIR__ . '/_seo-minta.php';

$ids = t_seo_minta();
T::egyezik(5, count(SDH_Sales_Gyik::lista()), 'lista');
$k = SDH_Sales_Gyik::lista_kategoriaval();
T::egyezik(['Árak', 'Telepítés', 'Adatok'], array_column($k, 'kategoria'), 'kategóriák sorrendben');
T::egyezik('gyik-kat-arak', $k[0]['anchor'], 'kategória-horgony');
T::egyezik(2, count($k[0]['elemek']), 'Árak: 2 kérdés');
T::egyezik(2, count(SDH_Sales_Gyik::lista('arak')), 'kategória-szűrés ékezet nélkül');
$e = SDH_Sales_Gyik::egy('ar-1');
T::egyezik('Mennyibe kerül a SDH CRM?', $e['kerdes'] ?? '', 'token a kérdésben');
T::egyezik('gyik-ar-1', SDH_Sales_Gyik::anchor('ar-1'), 'horgony');
T::tartalmaz($e['url'] ?? '', '/gyik/#gyik-ar-1', 'URL horgonnyal');
T::igaz(SDH_Sales_Gyik::egy('nincs') === null, 'ismeretlen kulcs → null');
$x = SDH_Sales_Gyik::egy('xss-1')['valasz'] ?? '';
T::nem_tartalmaz($x, '<script', 'script kiszűrve');
T::nem_tartalmaz($x, 'alert', 'script tartalma is');
T::nem_tartalmaz($x, 'onerror', 'eseménykezelő kiszűrve');
T::nem_tartalmaz($x, '<img', 'kép nem engedett');
T::tartalmaz(SDH_Sales_Gyik::egy('adat-1')['valasz'], 'rel="noopener"', 'külső link noopener');
T::tartalmaz(SDH_Sales_Gyik::egy('tel-1')['valasz'], '<li>PHP 8.1+</li>', 'lista megmarad');

// FAQPage: csak a megjelentekből.
t_lap(get_permalink($ids['funkciok/munkalapok']));
T::nem_tartalmaz(t_lab(), 'FAQPage', 'nincs megjelent kérdés → nincs FAQPage');
$sz = SDH_Sales_Gyik::szekcio(['kulcsok' => ['tel-1', 'ar-1', 'nincs']]);
T::egyezik(['tel-1', 'ar-1'], array_column($sz, 'kulcs'), 'szekció: kulcsok sorrendje, ismeretlen kihagyva');
[$b, $tip, $jo] = t_ld(t_lab());
T::igaz($jo, 'érvényes JSON');
$f = t_ld_csomopont($b, 'FAQPage');
T::egyezik(2, count($f['mainEntity'] ?? []), 'FAQPage: 2 kérdés');
T::egyezik(['Mi kell a telepítéshez?', 'Mennyibe kerül a SDH CRM?'], array_column($f['mainEntity'] ?? [], 'name'), 'csak a megjelentek');
T::egyezik('Answer', $f['mainEntity'][0]['acceptedAnswer']['@type'] ?? '', 'Answer');
T::egyezik(1, count(array_keys($tip, 'FAQPage')), 'egy FAQPage');
SDH_Sales_Gyik::megjelent_urit();
SDH_Sales_Gyik::szekcio(['kategoria' => 'Adatok', 'max' => 1]);
T::egyezik(['adat-1'], SDH_Sales_Gyik::megjelentek(), 'kategória + max');
SDH_Sales_Gyik::megjelent('ar-2');
SDH_Sales_Gyik::megjelent('ar-2');
SDH_Sales_Gyik::megjelent('nincs-ilyen');
T::egyezik(['adat-1', 'ar-2'], SDH_Sales_Gyik::megjelentek(), 'megjelent(): egyszer, ismeretlen nem');
SDH_Sales::b_ment(['seo.faq_schema' => false]);
T::igaz(SDH_Sales_Gyik::faqpage() === null, 'kikapcsolható');
SDH_Sales::b_ment(['seo.faq_schema' => true]);
SDH_Sales_Gyik::megjelent_urit();
SDH_Sales_Gyik::szekcio([]);
T::egyezik(5, count(SDH_Sales_Gyik::faqpage()['mainEntity'] ?? []), 'üres szekció = minden kérdés');
T::vege('t302 GYIK');
