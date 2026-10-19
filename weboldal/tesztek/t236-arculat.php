<?php
/** t236 – Arculat: csak érvényes, eltérő értékek; injekció (`;}</style>` stb.) elutasítva; a CSS nem tör ki a <style>-ból. */
require __DIR__ . '/_keret.php';

T::egyezik('', SDH_Sales_Arculat::css(), 'alapból üres');
$rossz = ['red;}</style><script>alert(1)</script>', '#fff;}body{display:none', 'url(javascript:alert(1))', 'expression(alert(1))', '#ff00zz', 'rgb(1,2,3);x', '@import "x"', '\\3c script', '12px;}', '</style>', 'red"><script>'];
foreach ($rossz as $r) {
    T::egyezik(null, SDH_Sales_Arculat::ervenyes('kiemelo', $r), 'szín elutasítva: ' . $r);
    T::egyezik(null, SDH_Sales_Arculat::ervenyes('r-gomb', $r), 'méret elutasítva: ' . $r);
    T::egyezik(null, SDH_Sales_Arculat::ervenyes('arnyek', $r), 'árnyék elutasítva: ' . $r);
    T::egyezik(null, SDH_Sales_Arculat::ervenyes('betu-cim', $r), 'betű elutasítva: ' . $r);
}
foreach (['#abc', '#AABBCC', 'rgba(1, 2, 3, .5)', 'hsl(250 80% 60%)', 'transparent'] as $j) {
    T::igaz(SDH_Sales_Arculat::ervenyes('kiemelo', $j) !== null, 'jó szín: ' . $j);
}
T::igaz(SDH_Sales_Arculat::ervenyes('arnyek', '0 10px 30px -10px rgba(0,0,0,.5), inset 0 1px 0 #fff') !== null, 'jó árnyék');
T::igaz(SDH_Sales_Arculat::ervenyes('betu-cim', '"Space Grotesk", Inter, sans-serif') !== null, 'jó betű');
T::igaz(SDH_Sales_Arculat::ervenyes('max', '1240px') !== null, 'jó méret');

$v = t_rest('POST', '/admin/arculat', ['ertekek' => ['kiemelo' => ['#ff0000', 'red;}</style><script>alert(1)</script>'], 'r-gomb' => '8px', 'max' => '1200px'], 'mod' => 'vilagos', 'logo' => 'javascript:alert(1)']);
T::egyezik(422, $v->get_status(), 'hibás érték → 422');
$css = SDH_Sales_Arculat::css();
T::tartalmaz($css, '--s-kiemelo:#ff0000', 'jó érték mentve');
T::tartalmaz($css, '--s-r-gomb:8px', 'méret');
T::nem_tartalmaz($css, '--s-max', 'az alappal egyező nem kerül ki');
T::nem_tartalmaz($css, '</style', 'nincs kitörés');
T::nem_tartalmaz($css, 'script', 'nincs szkript');
T::egyezik('', (string) SDH_Sales::b('arculat.logo', ''), 'javascript-logó elutasítva');
T::egyezik('vilagos', SDH_Sales_Arculat::mod(), 'alapmód');
// Kézzel beírt (pl. importált) rossz érték sem kerül ki.
SDH_Sales::b_ment(['arculat.sotet.ok' => '#0f0;}</style><script>x</script>']);
T::nem_tartalmaz(SDH_Sales_Arculat::css(), '</style', 'kézi rossz érték kiszűrve');
ob_start();
SDH_Sales_Arculat::kiir();
$h = (string) ob_get_clean();
T::egyezik(1, substr_count($h, '</style>'), 'egy <style> blokk');
T::nem_tartalmaz($h, 'sdh', 'semleges azonosító');
// Visszaállítás alapra
t_rest('POST', '/admin/arculat', ['ertekek' => ['kiemelo' => ['', ''], 'r-gomb' => '']]);
T::nem_tartalmaz(SDH_Sales_Arculat::css(), '--s-kiemelo', 'üres = alap');
// Előnézet: nem ment
$r = t_rest('POST', '/admin/arculat/elonezet', ['ertekek' => ['kiemelo' => ['#123456', '']]])->get_data();
T::tartalmaz($r['css'], '#123456', 'előnézeti CSS');
T::nem_tartalmaz(SDH_Sales_Arculat::css(), '#123456', 'előnézet nem mentett');
T::nem_tartalmaz($r['url'], 'sdh', 'előnézeti URL semleges');
T::vege('t236 arculat');
