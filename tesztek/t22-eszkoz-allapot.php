<?php
/**
 * t22 – eszközönkénti állapot és a lap közös állapota (több eszközös munkalap).
 */

require __DIR__ . '/_keret.php';

$M = 'SDH_Muhely_Munkalap';

/* --- Az állapotlista: jelzők és a „Részben elkészült" ------------------ */

$allapotok = $M::allapotok();
$reszben   = $M::reszben_kulcs();

T::igaz(isset($allapotok[$reszben]), 'van „részben” állapot');
T::igaz(!empty($allapotok[$reszben]['reszben']), 'a részben állapot jelzője be van állítva');
T::igaz(!empty($allapotok[$reszben]['szamozott']), 'a részben állapot számozott');
T::igaz(empty($allapotok[$reszben]['zart']), 'a részben állapot nem lezárt');
T::igaz(!empty($allapotok['elkeszult']['kesz']), 'az Elkészült „kész” jelzős');
T::igaz(!empty($allapotok['ervenytelen']['ervenytelen']), 'az Érvénytelen jelzős');

// Régi, mentett lista (jelzők nélkül): a kulcsokból pótlódnak a jelzők, a részben bekerül.
$regi = $M::allapotok_feldolgoz("nyitott | Nyitott | narancs | szamozott, alap\nelkeszult | Kész | zold | szamozott\nlezart | Lezárt | zold | szamozott, zart", '');
T::igaz(!isset($regi['reszben']), 'a feldolgozás magától nem tesz be állapotot');

/* --- A közös állapot szabályai ---------------------------------------- */

T::egyezik('nyitott', $M::kozos_allapot([0 => 'nyitott']), 'egy eszköz: a sajátja');
T::egyezik('nyitott', $M::kozos_allapot([0 => 'nyitott', 5 => 'nyitott']), 'mind ugyanaz');
T::egyezik($reszben, $M::kozos_allapot([0 => 'elkeszult', 5 => 'fuggo']), 'egyik kész, másik vár → részben');
T::egyezik($reszben, $M::kozos_allapot([0 => 'lezart', 5 => 'nyitott']), 'egyik átadva, másik nyitott → részben');
T::egyezik('elkeszult', $M::kozos_allapot([0 => 'lezart', 5 => 'elkeszult']), 'átadott + elkészült → elkészült (nem zárul le)');
T::egyezik('lezart', $M::kozos_allapot([0 => 'lezart', 5 => 'lezart']), 'mind lezárt → lezárt');
T::egyezik('fuggo', $M::kozos_allapot([0 => 'nyitott', 5 => 'fuggo']), 'egyik sem kész → a listában korábbi');
T::egyezik('lezart', $M::kozos_allapot([0 => 'lezart', 5 => 'ervenytelen']), 'az érvénytelen eszköz nem számít');
T::egyezik('ervenytelen', $M::kozos_allapot([0 => 'ervenytelen', 5 => 'ervenytelen']), 'mind érvénytelen');
T::egyezik($reszben, $M::kozos_allapot([0 => 'elkeszult', 5 => 'nyitott', 6 => 'ervenytelen']), 'három eszköz, egy érvénytelen');

/* --- Két eszközös lap: az egyik kész, a másik alkatrészre vár ---------- */

$u  = t_ugyfel();
$e1 = t_eszkoz($u, 'Apple', 'iPhone 12');
$e2 = t_eszkoz($u, 'Samsung', 'Galaxy A54');
$id = t_munkalap($u, [$e1, $e2]);

T::egyezik([0 => 'nyitott', $e2 => 'nyitott'], $M::eszkoz_allapotok(t_lap($id)), 'induláskor minden eszköz a lapét követi');
T::egyezik('', (string) t_lap($id)->fo_allapot, 'induláskor nincs külön tárolt állapot');

$v = $M::eszkoz_allapot_valt($id, $e2, 'elkeszult');

T::igaz(is_array($v), 'a csatolt eszköz állapota váltható');
T::egyezik($reszben, $v['kulcs'] ?? '', 'a válasz a közös állapotot hozza');
T::egyezik($reszben, (string) t_lap($id)->allapot, 'a lap közös állapota: részben elkészült');
T::egyezik('nyitott', (string) t_lap($id)->fo_allapot, 'a fő eszköz állapota kiíródott');
T::egyezik([0 => 'nyitott', $e2 => 'elkeszult'], $M::eszkoz_allapotok(t_lap($id)), 'eszközönként külön állapot');
T::tartalmaz((string) ($v['kesz_szoveg'] ?? ''), '1 / 2 kész: 2. Samsung Galaxy A54', 'a szöveg megmondja, melyik kész');
T::tartalmaz((string) ($v['blokk'] ?? ''), 'data-sdh-eszkoz-allapotok="' . $id . '"', 'a válasz hozza a blokkot');
T::nem_tartalmaz((string) ($v['blokk'] ?? ''), 'value="' . $reszben . '"', 'eszköznek a részben nem választható');

$b = t_bontas($id);
T::igaz(!$b[0]['kesz'] && $b[$e2]['kesz'], 'a bontás tudja, melyik eszköz kész');
T::egyezik('elkeszult', $b[$e2]['allapot'], 'a bontásban az eszköz saját állapota');

// A részben állapot kézzel nem adható (se eszköznek, se a lapnak, ha más az állapota).
T::igaz(is_string($M::eszkoz_allapot_valt($id, 0, $reszben)), 'eszköz nem kaphat részben állapotot');
T::igaz(is_string($M::eszkoz_allapot_valt($id, 999999, 'nyitott')), 'idegen eszköz elutasítva');
T::igaz(is_string($M::eszkoz_allapot_valt($id, 0, 'nincs_ilyen')), 'ismeretlen állapot elutasítva');

// Az elkészült eszközt elviszik: lezárt. A másik még nyitott → marad részben, a lap nem zárul le.
$M::eszkoz_allapot_valt($id, $e2, 'lezart');
T::egyezik($reszben, (string) t_lap($id)->allapot, 'átadott + nyitott → részben');
T::igaz(t_lap($id)->lezarva === null || (string) t_lap($id)->lezarva === '', 'a lap nincs lezárva, amíg van nyitott eszköz');

// A másik is elkészül, majd átadják: a lap lezárul.
$M::eszkoz_allapot_valt($id, 0, 'elkeszult');
T::egyezik('elkeszult', (string) t_lap($id)->allapot, 'átadott + elkészült → elkészült');
$M::eszkoz_allapot_valt($id, 0, 'lezart');
T::egyezik('lezart', (string) t_lap($id)->allapot, 'mind átadva → lezárt');
T::egyezik(current_time('Y-m-d'), substr((string) t_lap($id)->lezarva, 0, 10), 'a lezárás napja beállt');

// Újranyitás egy eszközön: a lap is újranyílik, a lezárás napja törlődik.
$M::eszkoz_allapot_valt($id, 0, 'nyitott');
T::egyezik($reszben, (string) t_lap($id)->allapot, 'egy eszköz újranyitva → részben');
T::igaz(t_lap($id)->lezarva === null || (string) t_lap($id)->lezarva === '', 'újranyitva nincs lezárási nap');

/* --- A lap állapotát átírva minden eszköz megkapja --------------------- */

$v = $M::allapot_valt($id, 'fuggo');
T::igaz(is_array($v), 'a lap állapota váltható');
T::egyezik([0 => 'fuggo', $e2 => 'fuggo'], $M::eszkoz_allapotok(t_lap($id)), 'a lap állapota minden eszközé lett');
T::tartalmaz((string) ($v['blokk'] ?? ''), 'sdh-eszall', 'a lap-szintű váltás is hozza a blokkot');
T::igaz(is_string($M::allapot_valt($id, $reszben)), 'a lapnak sem adható kézzel a részben (több eszköz)');

/* --- Állapottörténet: az eszköz váltása az eszköz azonosítójával -------- */

global $wpdb;
$naplo = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('munkalap_naplo') . ' WHERE munkalap_id = %d ORDER BY id', $id));
$eszkoz_sorok = array_values(array_filter($naplo, static fn ($n) => (int) $n->eszkoz_id > 0));

T::igaz(count($eszkoz_sorok) >= 5, 'az eszközváltások naplózódtak');
T::egyezik($e2, (int) $eszkoz_sorok[0]->eszkoz_id, 'a napló a valódi eszköz-azonosítót tárolja');
T::egyezik($e1, (int) $eszkoz_sorok[2]->eszkoz_id, 'a fő eszköznél is a valódi azonosító');
T::igaz(count(array_filter($naplo, static fn ($n) => (int) $n->eszkoz_id === 0 && (string) $n->allapot === $reszben)) >= 1, 'a közös állapot váltása is naplózódott');

/* --- Egy eszközös lap: minden a régi ----------------------------------- */

$id1 = t_munkalap($u, [$e1]);

T::egyezik('', $M::eszkoz_allapot_blokk(t_lap($id1)), 'egy eszköznél nincs blokk');
T::egyezik([0 => 'nyitott'], $M::eszkoz_allapotok(t_lap($id1)), 'egy eszköz: a lap állapota');
$v = $M::eszkoz_allapot_valt($id1, 0, 'elkeszult');
T::egyezik('elkeszult', (string) t_lap($id1)->allapot, 'egy eszköznél az eszközváltás a lapé');
T::egyezik('', (string) t_lap($id1)->fo_allapot, 'egy eszköznél nincs külön tárolt állapot');
T::igaz(is_array($M::allapot_valt($id1, $reszben)), 'egy eszközös lapnak kézzel adható a részben');

/* --- Eszköz levétele: a megmaradt eszköz állapota lesz a lapé ----------- */

$id3 = t_munkalap($u, [$e1, $e2]);
$M::eszkoz_allapot_valt($id3, 0, 'elkeszult');
T::egyezik($reszben, (string) t_lap($id3)->allapot, 'levétel előtt: részben');
$M::tovabbi_ment($id3, []);
$M::allapot_szinkron($id3);
T::egyezik('elkeszult', (string) t_lap($id3)->allapot, 'a csatolt eszköz levétele után a fő eszköz állapota a lapé');
T::egyezik('', (string) t_lap($id3)->fo_allapot, 'levétel után a külön állapot kiürül');

// Új eszköz egy részben kész lapra: alapállapottal indul.
$id4 = t_munkalap($u, [$e1, $e2]);
$M::eszkoz_allapot_valt($id4, 0, 'elkeszult');
$e3 = t_eszkoz($u, 'Xiaomi', 'Redmi 12');
$M::tovabbi_ment($id4, [$e2, $e3]);
$M::allapot_szinkron($id4);
T::egyezik('nyitott', $M::eszkoz_allapotok(t_lap($id4))[$e3], 'az új eszköz alapállapottal indul');
T::egyezik($reszben, (string) t_lap($id4)->allapot, 'új eszközzel is részben marad');

/* --- Űrlapmentés (AJAX): a lapon átírt állapot minden eszközé ----------- */

$alap = [
    'id' => $id4, 'ugyfel_id' => $u, 'eszkoz_id' => $e1, 'eszkozok_jelen' => '1',
    'tovabbi_eszkoz' => [$e2, $e3], 'keszult' => current_time('Y-m-d'), 'fizetett' => '0',
];

// Változatlan (részben) állapottal mentve az eszközök állapota megmarad.
$r = t_ajax('sdh_muhely_munkalapok_ment', $alap + ['allapot' => $reszben], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
T::igaz(!empty($r['success']), 'az űrlap menthető részben állapottal');
T::egyezik('elkeszult', $M::eszkoz_allapotok(t_lap($id4))[0], 'mentés után a fő eszköz állapota megmaradt');
T::egyezik($reszben, (string) t_lap($id4)->allapot, 'mentés után a közös állapot megmaradt');

// A lap állapotát átírva: mind megkapja.
$r = t_ajax('sdh_muhely_munkalapok_ment', $alap + ['allapot' => 'fuggo'], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
T::igaz(!empty($r['success']), 'az űrlap menthető új lap-állapottal');
T::egyezik([0 => 'fuggo', $e2 => 'fuggo', $e3 => 'fuggo'], $M::eszkoz_allapotok(t_lap($id4)), 'az űrlapon átírt állapot minden eszközé');

// Eszköz levétele az űrlapon: a közös állapot újraszámolódik.
$M::eszkoz_allapot_valt($id4, $e3, 'elkeszult');
T::egyezik($reszben, (string) t_lap($id4)->allapot, 'levétel előtt részben');
$r = t_ajax('sdh_muhely_munkalapok_ment', ['tovabbi_eszkoz' => [$e2]] + $alap + ['allapot' => $reszben], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
T::egyezik('fuggo', (string) t_lap($id4)->allapot, 'a kész eszköz levétele után a maradék állapota a közös');

// Kézzel választott részben több eszközös lapon: nem érvényesül.
$r = t_ajax('sdh_muhely_munkalapok_ment', ['tovabbi_eszkoz' => [$e2]] + $alap + ['allapot' => $reszben], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
T::egyezik('fuggo', (string) t_lap($id4)->allapot, 'a kézzel választott részben nem írja felül a közös állapotot');

/* --- AJAX-végpont -------------------------------------------------------- */

$r = t_ajax('sdh_muhely_munkalapok_eszkoz_allapot', ['id' => $id4, 'kulcs' => $e2, 'allapot' => 'elkeszult']);
T::igaz(!empty($r['success']), 'az eszköz-állapot AJAX sikeres');
T::egyezik($reszben, $r['data']['kulcs'] ?? '', 'az AJAX a közös állapotot adja vissza');
T::igaz(isset($r['data']['nev'], $r['data']['szin'], $r['data']['blokk']), 'az AJAX hozza a nevet, a színt és a blokkot');

$r = t_ajax('sdh_muhely_munkalapok_eszkoz_allapot', ['id' => $id4, 'kulcs' => $e2, 'allapot' => $reszben]);
T::igaz(empty($r['success']) && isset($r['data']['uzenet']), 'hibás állapotnál hibaüzenet jön');

T::vege('t22 eszköz-állapot');
