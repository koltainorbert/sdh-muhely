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


// Független ellenőrzés (2026-10-20), K-2: a kupont csak a kifizetett rendelés és az élő foglalás (24 óra) fogyasztja; a
// keret atomi; vevőnkénti korlát; a sikertelen kuponpróbák közös IP-kerete az /ar-on és a /kosar/ellenoriz-en is.
global $wpdb;
$tr = SDH_Sales_Schema::t('rendeles');
SDH_Sales_Tartalom::ment('kupon', 'KETTO', ['kod' => 'KETTO', 'tipus' => 'szazalek', 'ertek' => 10, 'lejar' => null, 'max' => 2], null, 99, true, 'hu', false);
SDH_Sales_Tartalom::urit();
$k1 = t_rendeles(['opciok' => ['ciklus' => 'havi', 'kupon' => 'KETTO']]);
$k2 = t_rendeles(['opciok' => ['ciklus' => 'havi', 'kupon' => 'KETTO']]);
$h  = SDH_Sales_Rendeles::letrehoz(['tipus' => 'uj', 'tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start']], 'opciok' => ['ciklus' => 'havi', 'kupon' => 'KETTO'], 'vevo' => t_vevo(), 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]]);
T::igaz(empty($h['ok']), 'K-2: élő foglalásoknál (2/2) a kupon elfogyott');
$wpdb->query($wpdb->prepare("UPDATE {$tr} SET letrehozva = %s WHERE id IN (%d, %d)", gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS), (int) $k1['id'], (int) $k2['id']));
$h = SDH_Sales_Rendeles::letrehoz(['tipus' => 'uj', 'tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start']], 'opciok' => ['ciklus' => 'havi', 'kupon' => 'KETTO'], 'vevo' => t_vevo(), 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]]);
T::igaz(!empty($h['ok']), 'K-2: 24 óránál régebbi fizetetlen rendelés nem fogyasztja a kupont');
SDH_Sales_Rendeles::fizetve((int) $k1['id'], 'kezi', 'kezi-kup-1', (int) $k1['r']['brutto']);
T::egyezik(2, SDH_Sales_Ar::kupon_hasznalat('KETTO'), 'K-2: felhasználás = 1 kifizetett + 1 élő foglalás');
// atomi keret: a később beszúrt rendelés kiesik, ha a keret közben elfogyott
SDH_Sales_Tartalom::ment('kupon', 'EGY', ['kod' => 'EGY', 'tipus' => 'szazalek', 'ertek' => 10, 'lejar' => null, 'max' => 1], null, 99, true, 'hu', false);
SDH_Sales_Tartalom::urit();
$wpdb->insert($tr, ['azon' => 'TMP-K2A', 'allapot' => 'dijbekero', 'kupon' => 'EGY', 'letrehozva' => current_time('mysql', true)]);
$ka = (int) $wpdb->insert_id;
$wpdb->insert($tr, ['azon' => 'TMP-K2B', 'allapot' => 'dijbekero', 'kupon' => 'EGY', 'letrehozva' => current_time('mysql', true)]);
$kb = (int) $wpdb->insert_id;
T::igaz(SDH_Sales_Kupon::foglal('EGY', $ka) && !SDH_Sales_Kupon::foglal('EGY', $kb), 'K-2: párhuzamos foglalás: csak az első fér bele');
// vevőnkénti korlát
SDH_Sales_Tartalom::ment('kupon', 'VEVO1', ['kod' => 'VEVO1', 'tipus' => 'szazalek', 'ertek' => 10, 'lejar' => null, 'max' => null, 'vevonkent' => 1], null, 99, true, 'hu', false);
SDH_Sales_Tartalom::urit();
$ve = 'kupvevo' . wp_rand(1000, 99999) . '@example.com';
t_rendeles(['opciok' => ['ciklus' => 'havi', 'kupon' => 'VEVO1'], 'vevo' => t_vevo(['email' => $ve])]);
$h = SDH_Sales_Rendeles::letrehoz(['tipus' => 'uj', 'tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start']], 'opciok' => ['ciklus' => 'havi', 'kupon' => 'VEVO1'], 'vevo' => t_vevo(['email' => $ve]), 'fizmod' => 'atutalas', 'elfogad' => ['vallalkozas' => 1, 'aszf' => 1, 'eula' => 1]]);
T::igaz(empty($h['ok']) && strpos((string) ($h['hibak']['kosar'] ?? ''), 'már felhasználtad') !== false, 'K-2: vevőnkénti korlát (`vevonkent`)');
// sikertelen kuponpróbák közös kerete: 10 rossz kód után az /ar sem értékel (a jó kódot sem) – korlátokkal
t_korlat_ki(false);
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand(10, 250);
for ($i = 0; $i < 10; $i++) {
    t_latogato(static fn () => t_rest('POST', '/ar', ['tetelek' => $t, 'opciok' => ['ciklus' => 'havi', 'kupon' => 'ROSSZ' . $i]]));
}
$v = t_latogato(static fn () => t_rest('POST', '/ar', ['tetelek' => $t, 'opciok' => ['ciklus' => 'havi', 'kupon' => 'SZ10']]))->get_data();
T::igaz(($v['kupon'] ?? null) === null && in_array(SDH_Sales_Kupon::tul_sok_szoveg(), (array) $v['hibak'], true), 'K-2: 10 sikertelen próba után az /ar sem értékel kupont');
$v = t_latogato(static fn () => t_rest('POST', '/kosar/ellenoriz', ['tetelek' => $t, 'opciok' => ['ciklus' => 'havi', 'kupon' => 'SZ10']]))->get_data();
T::igaz(($v['ar']['kupon'] ?? null) === null, 'K-2: … és a /kosar/ellenoriz sem');
T::egyezik(SDH_Sales_Kupon::tul_sok_szoveg(), (string) (t_latogato(static fn () => t_rest('POST', '/kupon', ['kod' => 'SZ10', 'tetelek' => $t, 'opciok' => ['ciklus' => 'havi']]))->get_data()['uzenet'] ?? ''), 'K-2: … és a /kupon sem');
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand(10, 250) . '';
t_korlat_ki(true);

T::vege('t211 kupon');
