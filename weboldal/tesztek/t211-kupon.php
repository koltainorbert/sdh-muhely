<?php
/** t211 – kupon a valódi tárból: REST /kupon, /ar, /kosar/ellenoriz; felhasználási keret a rendelésekből; nincs kuponlista a fronton. */
require __DIR__ . '/_t210-seged.php';
t_katalogus_betolt();

$t = [['tipus' => 'csomag', 'kulcs' => 'start']];
$v = t_latogato(static fn () => t_rest('POST', '/kupon', ['kod' => 'sz10', 'tetelek' => $t, 'opciok' => ['ciklus' => 'havi']]));
T::egyezik(200, $v->get_status(), 'REST /kupon 200');
T::igaz($v->get_data()['ok'] === true && $v->get_data()['kedvezmeny'] === 990, 'érvényes kupon: 990 Ft');
T::tartalmaz($v->get_data()['uzenet'], 'kedvezmény', 'magyar üzenet');

$v = t_latogato(static fn () => t_rest('POST', '/kupon', ['kod' => 'LEJART', 'tetelek' => $t, 'opciok' => ['ciklus' => 'havi']]));
T::igaz($v->get_data()['ok'] === false, 'lejárt kupon elutasítva');
T::tartalmaz($v->get_data()['uzenet'], 'lejárt', 'lejárt üzenet');
T::egyezik(12573, $v->get_data()['ar']['brutto'], 'elutasított kuponnál a kupon nélküli ár jön vissza');

$v = t_latogato(static fn () => t_rest('POST', '/kupon', ['kod' => '<script>', 'tetelek' => $t]));
T::igaz($v->get_data()['ok'] === false, 'szemét kód elutasítva');

// Felhasználási keret: ELFOGYOTT max 3, a tárban 0 használat → 3 rendelés után elfogy.
for ($i = 0; $i < 3; $i++) {
    $r = t_rendeles(['opciok' => ['ciklus' => 'havi', 'kupon' => 'ELFOGYOTT']]);
    T::igaz(!empty($r['ok']), 'kuponos rendelés ' . ($i + 1));
}
T::egyezik(3, SDH_Sales_Ar::kupon_hasznalat('ELFOGYOTT'), 'felhasználás számlálva');
$a = SDH_Sales_Ar::szamol($t, ['ciklus' => 'havi', 'kupon' => 'ELFOGYOTT']);
T::tartalmaz(implode(' ', $a['hibak']), 'elfogyott', 'a 4. már nem kapja');
$r4 = SDH_Sales_Rendeles::letrehoz(['tetelek' => $t, 'opciok' => ['ciklus' => 'havi', 'kupon' => 'ELFOGYOTT'], 'vevo' => t_vevo(), 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]]);
T::igaz(empty($r4['ok']) && strpos((string) ($r4['hibak']['kosar'] ?? ''), 'elfogyott') !== false, 'rendelés elutasítva elfogyott kuponnal');
// Törölt rendelés felszabadítja.
SDH_Sales_Rendeles::allapot_valt((int) $r['id'], 'torolve');
T::egyezik(2, SDH_Sales_Ar::kupon_hasznalat('ELFOGYOTT'), 'törölt rendelés nem számít');

// Lejárat a mai dátummal szemben (valódi tár).
SDH_Sales_Tartalom::ment('kupon', 'HOLNAP', ['kod' => 'HOLNAP', 'tipus' => 'szazalek', 'ertek' => 10, 'lejar' => wp_date('Y-m-d', time() + DAY_IN_SECONDS)]);
SDH_Sales_Tartalom::ment('kupon', 'TEGNAP', ['kod' => 'TEGNAP', 'tipus' => 'szazalek', 'ertek' => 10, 'lejar' => wp_date('Y-m-d', time() - DAY_IN_SECONDS)]);
SDH_Sales_Tartalom::ment('kupon', 'MAIG', ['kod' => 'MAIG', 'tipus' => 'szazalek', 'ertek' => 10, 'lejar' => wp_date('Y-m-d')]);
T::egyezik(990, SDH_Sales_Ar::szamol($t, ['ciklus' => 'havi', 'kupon' => 'HOLNAP'])['kedvezmeny'], 'holnap lejáró: jó');
T::egyezik(990, SDH_Sales_Ar::szamol($t, ['ciklus' => 'havi', 'kupon' => 'MAIG'])['kedvezmeny'], 'ma lejáró: még jó');
T::egyezik(0, SDH_Sales_Ar::szamol($t, ['ciklus' => 'havi', 'kupon' => 'TEGNAP'])['kedvezmeny'], 'tegnap lejárt: nem jó');

// /ar és /kosar/ellenoriz REST
$v = t_latogato(static fn () => t_rest('POST', '/ar', ['tetelek' => $t, 'opciok' => ['ciklus' => 'eves', 'kupon' => 'SZ10']]));
T::egyezik(100980 - 10098, $v->get_data()['netto'], 'REST /ar kuponnal');
$v = t_latogato(static fn () => t_rest('POST', '/kosar/ellenoriz', ['tetelek' => array_merge($t, [['tipus' => 'modul', 'kulcs' => 'asszisztens']]), 'opciok' => ['ciklus' => 'havi']]));
T::igaz($v->get_data()['ok'] === true && $v->get_data()['ar']['netto'] === 9900 + 3990, 'REST /kosar/ellenoriz újraáraz');
T::igaz(count($v->get_data()['fizmodok']) >= 1 && $v->get_data()['fizmodok'][0]['kulcs'] === 'atutalas', 'fizetési módok: átutalás');

// A front adata nem tartalmaz kuponokat.
$pub = wp_json_encode(SDH_Sales_Katalogus::publikus());
T::nem_tartalmaz($pub, 'ELFOGYOTT', 'nincs kuponlista a front adatában');
T::nem_tartalmaz($pub, 'kuponok', 'nincs kuponok kulcs');

T::vege('t211 kupon');
