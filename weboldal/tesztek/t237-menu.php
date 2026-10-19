<?php
/** t237 – menüfa mentése: szintek (max 3), oldal / URL cél, új lap, tisztítás, a SDH_Sales_Tartalom::menu() olvassa. */
require __DIR__ . '/_keret.php';

$fa = [
    ['cim' => 'Funkciók', 'oldal' => 'funkciok', 'gyerekek' => [
        ['cim' => 'Munkalap', 'oldal' => 'funkciok/munkalap', 'gyerekek' => [['cim' => 'Mélyebb', 'url' => '/a/', 'gyerekek' => [['cim' => 'Túl mély', 'url' => '/b/']]]]],
        ['cim' => '', 'url' => '/ures/'],
    ]],
    ['cim' => 'Árak', 'url' => '/arak/'],
    ['cim' => 'Külső', 'url' => 'https://example.com/x', 'uj' => true],
    ['cim' => 'Rossz', 'url' => 'javascript:alert(1)'],
];
$v = t_rest('POST', '/admin/menu/fo', ['elemek' => $fa]);
T::egyezik(200, $v->get_status(), 'mentés');
$m = SDH_Sales_Tartalom::egy('menu', 'fo')['adat']['elemek'];
T::egyezik(4, count($m), 'felső szint');
T::egyezik('funkciok', $m[0]['oldal'], 'oldal-cél');
T::egyezik(1, count($m[0]['gyerekek']), 'üres feliratú kimaradt');
T::egyezik('funkciok/munkalap', $m[0]['gyerekek'][0]['oldal'], 'útvonal-cél');
T::igaz(!isset($m[0]['gyerekek'][0]['gyerekek'][0]['gyerekek']), 'legfeljebb 3 szint');
T::egyezik(true, $m[2]['uj'], 'új lap');
T::egyezik('', $m[3]['url'], 'javascript-cél kiszűrve');
$kesz = SDH_Sales_Tartalom::menu('fo');
T::egyezik('Árak', $kesz[1]['cim'], 'menu() olvassa');
T::tartalmaz($kesz[1]['url'], '/arak/', 'relatív URL teljes lesz');
T::egyezik(400, t_rest('POST', '/admin/menu/nincs', ['elemek' => []])->get_status(), 'ismeretlen hely');
$h = t_rest('GET', '/admin/menuk')->get_data()['helyek'];
T::egyezik(['fo', 'lablec', 'jogi'], array_keys($h), 'helyek');
T::egyezik('Funkciók', $h['fo']['elemek'][0]['cim'], 'visszaolvasás');
// Visszaállítás a naplóból
t_rest('POST', '/admin/menu/fo', ['elemek' => [['cim' => 'Csak ez', 'url' => '/']]]);
$n = SDH_Sales_Naplo::lista(['ref_tipus' => 'tartalom'])['sorok'][0];
t_rest('POST', '/admin/naplo/' . $n['id'] . '/visszaallit');
T::egyezik(4, count(SDH_Sales_Tartalom::egy('menu', 'fo')['adat']['elemek']), 'menü visszaállt');
// 80 elemes korlát
$sok = array_fill(0, 120, ['cim' => 'x', 'url' => '/']);
t_rest('POST', '/admin/menu/lablec', ['elemek' => $sok]);
T::egyezik(80, count(SDH_Sales_Tartalom::egy('menu', 'lablec')['adat']['elemek']), 'legfeljebb 80 elem');
T::vege('t237 menü');
