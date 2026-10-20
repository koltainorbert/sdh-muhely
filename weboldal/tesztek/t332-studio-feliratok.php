<?php
/**
 * t332 – Feliratok a Stúdióban: a téma jegyzéke csoportosítva, felülírás mentése (üres / alappal egyező = törlés),
 * tisztítás (HTML, hossz), napló + visszaállítás, export / import, az előnézet még nem mentett feliratai.
 */
require __DIR__ . '/_keret.php';

$S = 'SDH_Sales_Studio';
delete_option($S::OPT_FELIRAT);

if (!function_exists('sdh_tema_feliratok')) {
    // A téma nélkül futó teszthez egy kis jegyzék.
    function sdh_tema_feliratok(): array
    {
        return ['fejlec.demo' => ['alap' => 'Demó', 'csoport' => 'Fejléc', 'leiras' => 'Hol: fejléc'], 'lablec.jogi' => ['alap' => 'Jogi', 'csoport' => 'Lábléc', 'leiras' => '']];
    }
}

$j = $S::jegyzek();
T::igaz(count($j) >= 2, 'a jegyzék betöltődik (' . count($j) . ' kulcs)');
$k1 = (string) array_key_first($j);
$k2 = (string) array_keys($j)[1];

$r = t_rest('GET', '/admin/feliratok')->get_data();
T::igaz($r['van_jegyzek'], 'van jegyzék');
T::egyezik(count($j), array_sum(array_map(static fn ($c) => count($c['elemek']), $r['csoportok'])), 'minden kulcs egy csoportban');
T::igaz(count(array_unique(array_column($r['csoportok'], 'cim'))) === count($r['csoportok']), 'csoportnevek egyediek');

// Mentés, tisztítás.
$v = t_rest('POST', '/admin/feliratok', ['ertekek' => [$k1 => '<b>Kipróbálom</b><script>alert(1)</script>', $k2 => str_repeat('á', 800)]]);
T::egyezik(200, $v->get_status(), 'mentés');
$o = get_option($S::OPT_FELIRAT);
T::igaz(isset($o[$k1]) && strpos($o[$k1], '<') === false && strpos($o[$k1], 'Kipróbálom') !== false, 'HTML kiszűrve: ' . ($o[$k1] ?? ''));
T::egyezik(500, mb_strlen($o[$k2]), 'legfeljebb 500 karakter');
T::egyezik($o[$k1], $S::felirat($k1, 'x'), 'felirat() a felülírást adja');

// Üres és alappal egyező: törlés.
t_rest('POST', '/admin/feliratok', ['ertekek' => [$k1 => '', $k2 => $j[$k2]['alap']]]);
$o = (array) get_option($S::OPT_FELIRAT, []);
T::igaz(!isset($o[$k1]) && !isset($o[$k2]), 'üres / alap = nincs felülírás');

// Ismeretlen kulcsalak kimarad; hibás bemenet 400.
t_rest('POST', '/admin/feliratok', ['ertekek' => ['Rossz Kulcs!' => 'x', '../x' => 'y', $k1 => 'Jó']]);
$o = (array) get_option($S::OPT_FELIRAT, []);
T::egyezik([$k1 => 'Jó'], $o, 'csak az érvényes kulcs mentődik');
T::egyezik(400, t_rest('POST', '/admin/feliratok', ['ertekek' => 'nem tömb'])->get_status(), 'hibás lista: 400');

// Napló + visszaállítás.
$n = SDH_Sales_Naplo::lista(['ref_tipus' => 'feliratok', 'db' => 1])['sorok'][0] ?? null;
T::igaz($n !== null, 'naplóba került');
t_rest('POST', '/admin/feliratok', ['ertekek' => [$k1 => 'Később']]);
$n2 = SDH_Sales_Naplo::lista(['ref_tipus' => 'feliratok', 'db' => 1])['sorok'][0];
T::egyezik('', SDH_Sales_Naplo::visszaallit((int) $n2['id']), 'visszaállítás lefut');
T::egyezik('Jó', (string) (get_option($S::OPT_FELIRAT)[$k1] ?? ''), 'a korábbi felirat visszajött');

// A jegyzéken kívüli (régi) felülírás látszik, és törölhető.
update_option($S::OPT_FELIRAT, [$k1 => 'Jó', 'regi.kulcs' => 'Régi']);
$cs = array_column(t_rest('GET', '/admin/feliratok')->get_data()['csoportok'], null, 'cim');
T::igaz(isset($cs['Jegyzéken kívül']) && $cs['Jegyzéken kívül']['elemek'][0]['kulcs'] === 'regi.kulcs', 'jegyzéken kívüli felülírás látszik');
t_rest('POST', '/admin/feliratok', ['ertekek' => ['regi.kulcs' => '']]);
T::igaz(!isset(get_option($S::OPT_FELIRAT)['regi.kulcs']), 'és törölhető');

// Export / import.
$e = SDH_Sales_Exportimport::export();
T::egyezik([$k1 => 'Jó'], $e['feliratok'], 'az export tartalmazza');
update_option($S::OPT_FELIRAT, []);
$i = SDH_Sales_Exportimport::import($e);
T::igaz($i['ok'] && $i['db']['felirat'] >= 1, 'import: felirat-készlet visszatöltve');
T::egyezik('Jó', (string) (get_option($S::OPT_FELIRAT)[$k1] ?? ''), 'import után a felirat él');
$e['feliratok'] = [$k1 => '<img src=x onerror=alert(1)>Tiszta', 'Rossz!' => 'x'];
SDH_Sales_Exportimport::import($e);
T::egyezik([$k1 => 'Tiszta'], get_option($S::OPT_FELIRAT), 'import tisztít');

// Előnézet: a még nem mentett felirat csak módban él.
$S::mod_be([$k1 => 'Előnézet', 'Rossz!' => 'x']);
T::egyezik('Előnézet', $S::felirat($k1, 'alap'), 'előnézetben a nem mentett érték');
$S::mod_ki();
T::egyezik('Tiszta', $S::felirat($k1, 'alap'), 'előnézet után a mentett érték');

if (function_exists('sdh_tema_f')) {
    T::egyezik('Tiszta', sdh_tema_f($k1, (string) $j[$k1]['alap']), 'a téma sdh_tema_f is a felülírást adja');
}

delete_option($S::OPT_FELIRAT);
T::vege('t332 feliratok');
