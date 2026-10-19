<?php
/** t232 – XSS: szekciók, elemek, menü, ismeretlen mezők – semmilyen úton nem jut be szkript. */
require __DIR__ . '/_keret.php';

$rossz = ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<a href="javascript:alert(1)">x</a>', '<svg onload=alert(1)>', '<iframe src="//x">', '<p style="background:url(javascript:1)">s</p>'];
$tiltott = ['<script', 'onerror', 'javascript:', '<svg', '<iframe', 'onload', 'style='];

$sz = [];
foreach ($rossz as $r) {
    $sz[] = ['tipus' => 'szoveg', 'cim' => $r, 'html' => '<p>ok</p>' . $r];
    $sz[] = ['tipus' => 'hero', 'cim' => 'Cím ' . $r, 'alcim' => $r, 'gomb' => ['szoveg' => $r, 'url' => 'javascript:alert(1)'], 'kep' => $r, 'ismeretlen_mezo' => $r, 'mely' => ['a' => [$r]]];
    $sz[] = ['tipus' => 'jellemzok', 'elemek' => [['cim' => $r, 'szoveg' => $r, 'ikon' => $r]]];
}
$ki = (string) wp_json_encode(SDH_Sales_Szekcio::ervenyesit_lista($sz));
foreach ($tiltott as $t) {
    T::nem_tartalmaz(strtolower($ki), $t, 'szekció: ' . $t);
}
T::tartalmaz($ki, '<p>ok<\/p>', 'biztonságos HTML megmarad');

$p = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 't232', 'post_name' => 't232']);
$v = t_rest('POST', '/admin/oldal/' . $p, ['szekciok' => $sz]);
T::egyezik(200, $v->get_status(), 'mentés REST');
$tarolt = strtolower((string) get_post_meta($p, SDH_Sales_Tartalom::META, true));
foreach ($tiltott as $t) {
    T::nem_tartalmaz($tarolt, $t, 'tárolt: ' . $t);
}

$v = t_rest('POST', '/admin/elem', ['tipus' => 'gyik', 'adat' => ['kulcs' => 't232', 'kerdes' => $rossz[0] . 'Kérdés', 'valasz' => '<p><strong>Ok</strong></p>' . implode('', $rossz), 'kategoria' => $rossz[1], 'idegen' => $rossz[1]]]);
T::egyezik(200, $v->get_status(), 'elem mentés');
$e = strtolower((string) wp_json_encode(SDH_Sales_Tartalom::egy('gyik', 't232')));
foreach ($tiltott as $t) {
    T::nem_tartalmaz($e, $t, 'elem: ' . $t);
}
T::tartalmaz($e, '<strong>ok<\/strong>', 'válasz HTML marad');

$v = t_rest('POST', '/admin/menu/lablec', ['elemek' => [['cim' => $rossz[0] . 'Menü', 'url' => 'javascript:alert(1)', 'gyerekek' => [['cim' => $rossz[1], 'oldal' => '../<x>']]]]]);
$m = strtolower((string) wp_json_encode(SDH_Sales_Tartalom::egy('menu', 'lablec')));
foreach ($tiltott as $t) {
    T::nem_tartalmaz($m, $t, 'menü: ' . $t);
}
// Ismeretlen kulcs (pl. __proto__ / szóköz) eldobva.
$x = SDH_Sales_Szekcio::altalanos(['ok' => 1, 'rossz kulcs' => 2, '<x>' => 3]);
T::egyezik(['ok' => 1], $x, 'ismeretlen kulcsok');
T::vege('t232 XSS');
