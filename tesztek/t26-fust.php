<?php
/**
 * t26 – füstteszt: a több eszközös munkalap felületei hiba nélkül kirajzolódnak
 * (űrlap, rács, részletpanel, beállítások, asszisztens), és az egy eszközös
 * lapon minden a régi.
 */

require __DIR__ . '/_keret.php';

$M = 'SDH_Muhely_Munkalap';

global $wpdb;

// PHP-figyelmeztetés sem lehet: minden notice/warning hibának számít.
$php_hibak = [];
set_error_handler(static function (int $szint, string $uzenet, string $fajl, int $sor) use (&$php_hibak): bool {
    if (strpos($fajl, 'sdh-muhely') !== false) {
        $php_hibak[] = basename($fajl) . ':' . $sor . ' ' . $uzenet;
    }

    return false;
});

$reszben = $M::reszben_kulcs();

$u  = t_ugyfel('Füst Ferenc');
$e1 = t_eszkoz($u, 'Apple', 'iPhone 11');
$e2 = t_eszkoz($u, 'Huawei', 'P30');
$id = t_munkalap($u, [$e1, $e2]);
$egy = t_munkalap($u, [$e1]);

t_tetel($id, 'Kijelzőcsere', 25000, 0);
t_tetel($id, 'Töltőcsatlakozó', 11000, $e2);
SDH_Muhely_Tetel::ujraszamol($id);
t_tetel($egy, 'Szerviz', 5000, 0);
SDH_Muhely_Tetel::ujraszamol($egy);

/* --- Munkalap-űrlap ------------------------------------------------------ */

$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];

T::igaz(strlen($urlap) > 5000, 'a több eszközös űrlap kirajzolódik');
T::tartalmaz($urlap, 'Közös állapot', 'több eszköznél a lap állapota „Közös állapot”');
// 0.56.0: az űrlapon az eszközönkénti állapot az Eszközök panel kártyáin áll (egy választó eszközönként).
T::egyezik(2, substr_count($urlap, 'data-sdh-eszkoz-allapot data-id="' . $id . '"'), 'az űrlapon minden eszköz kártyáján ott az állapotválasztó');
T::egyezik(2, substr_count($urlap, 'data-sdh-eszkoz-allapot '), 'eszközönként egy állapotválasztó');
T::igaz(preg_match('/<select name="allapot".*?<\/select>/s', $urlap, $m) === 1, 'megvan a lap állapotválasztója');
T::nem_tartalmaz($m[0] ?? '', 'value="' . $reszben . '"', 'a részben nem választható kézzel, amíg nem az a lap állapota');

$M::eszkoz_allapot_valt($id, $e2, 'elkeszult');
$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
preg_match('/<select name="allapot".*?<\/select>/s', $urlap, $m);
T::igaz(preg_match('/value="' . $reszben . '"[^>]*selected/s', $m[0] ?? '') === 1, 'részben kész lapon a választó a részben állapoton áll');
T::tartalmaz($urlap, '1 / 2 kész: 2. Huawei P30', 'az űrlapon látszik, melyik eszköz kész');

$urlap1 = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $egy])['nyers'];
T::igaz(strlen($urlap1) > 5000, 'az egy eszközös űrlap kirajzolódik');
T::nem_tartalmaz($urlap1, 'data-sdh-eszkoz-allapot ', 'egy eszköznél nincs eszközönkénti állapotválasztó');
T::nem_tartalmaz($urlap1, 'Közös állapot', 'egy eszköznél a felirat „Állapot”');
preg_match('/<select name="allapot".*?<\/select>/s', $urlap1, $m1);
T::tartalmaz($m1[0] ?? '', 'value="' . $reszben . '"', 'egy eszköznél a részben kézzel választható');

$uj = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', [])['nyers'];
T::igaz(strlen($uj) > 5000, 'az új munkalap űrlapja kirajzolódik');
T::nem_tartalmaz($uj, 'data-sdh-eszkoz-allapot ', 'új lapon nincs eszközönkénti állapotválasztó');

/* --- Rács ---------------------------------------------------------------- */

$r = t_ajax('sdh_muhely_racs_adat', ['allapot' => wp_json_encode(['nezet' => 'mind', 'meret' => 100])]);
T::igaz(!empty($r['success']) && !empty($r['data']['sorok']), 'a rács adatot ad');

$sorok = [];

foreach ((array) ($r['data']['sorok'] ?? []) as $sor) {
    $sorok[(int) $sor['id']] = $sor;
}

T::igaz(isset($sorok[$id], $sorok[$egy]), 'a tesztlapok ott vannak a rácsban');
T::egyezik($reszben, $sorok[$id]['allapot'] ?? '', 'a rácsban a közös állapot áll');
T::egyezik('1/2', $sorok[$id]['_kesz'] ?? '', 'a rács mutatja, hány eszköz kész');
T::tartalmaz((string) ($sorok[$id]['_kesz_szoveg'] ?? ''), '2. Huawei P30', 'a rács megmondja, melyik eszköz kész');
T::igaz(!isset($sorok[$egy]['_kesz']), 'egy eszközös sornál nincs kész-jelzés');
T::egyezik(36000.0, (float) ($sorok[$id]['brutto'] ?? 0), 'a rácsban a lap végösszege');

// A „kész, átvételre vár" nézetben a részben kész lap is ott van.
$r = t_ajax('sdh_muhely_racs_adat', ['allapot' => wp_json_encode(['nezet' => 'elkeszult', 'meret' => 100, 'szurok' => ['allapot' => ['op' => 'egyike', 'l' => ['elkeszult', $reszben]]]])]);
T::igaz(in_array($id, array_map(static fn ($s) => (int) $s['id'], (array) ($r['data']['sorok'] ?? [])), true), 'a részben kész lap benne van az átvételre váró nézetben');

/* --- Részletpanel -------------------------------------------------------- */

$r = t_ajax('sdh_muhely_racs_reszlet', ['id' => $id]);
$panel = wp_json_encode($r['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
T::igaz(!empty($r['success']), 'a részletpanel betölt');
T::tartalmaz((string) $panel, 'sdh-reszlet__eszkozok', 'a részletpanelen ott az eszközönkénti blokk (kártyasor az adatok fölött)');
T::tartalmaz((string) $panel, 'data-sdh-eszkoz-allapot ', 'a részletpanelről is állítható az eszköz állapota');

$r = t_ajax('sdh_muhely_racs_reszlet', ['id' => $egy]);
T::igaz(!empty($r['success']), 'az egy eszközös részletpanel betölt');
T::nem_tartalmaz((string) wp_json_encode($r['data'] ?? []), 'sdh-adatlista__sor--eszall', 'egy eszköznél nincs eszközönkénti sor');

/* --- Állapotváltás a listából (AJAX) ------------------------------------- */

$r = t_ajax('sdh_muhely_munkalapok_allapot', ['id' => $egy, 'allapot' => 'elkeszult']);
T::igaz(!empty($r['success']), 'egy eszközös lap állapota váltható a listából');
T::egyezik('elkeszult', (string) t_lap($egy)->allapot, 'az egy eszközös lap állapota átállt');
T::egyezik('', (string) ($r['data']['blokk'] ?? 'x'), 'egy eszköznél üres blokk jön vissza');

$r = t_ajax('sdh_muhely_munkalapok_allapot', ['id' => $egy, 'allapot' => 'lezart']);
T::egyezik(current_time('Y-m-d'), substr((string) t_lap($egy)->lezarva, 0, 10), 'a lezárás napja beáll');
T::igaz(!empty($r['data']['zart']), 'a válasz jelzi, hogy a lap lezárt');

/* --- Beállítások és a mentett állapotlista pótlása ----------------------- */

ob_start();
$M::beallitas_dobozok();
$doboz = (string) ob_get_clean();
T::tartalmaz($doboz, 'reszben', 'a Beállítások állapotlistájában ott a részben');
T::tartalmaz($doboz, '<code>kesz</code>', 'a súgó leírja az új jelzőket');

$opt   = 'sdh_muhely_munkalap_allapotok';
$elozo = get_option($opt, null);

update_option($opt, "nyitott | Nyitott | narancs | szamozott, alap\nelkeszult | Kész van | zold | szamozott\nlezart | Lezárt | zold | szamozott, zart");
$M::reszben_potlas();
$sorok_opt = preg_split('/\R/', (string) get_option($opt));
T::egyezik(4, count($sorok_opt), 'a mentett listába bekerült egy sor');
T::igaz(strpos((string) ($sorok_opt[2] ?? ''), 'reszben |') === 0, 'a részben az elkészült után áll');
$M::reszben_potlas();
T::egyezik(4, count(preg_split('/\R/', (string) get_option($opt))), 'a pótlás újrafuttatva nem duplikál');

if ($elozo === null) {
    delete_option($opt);
} else {
    update_option($opt, $elozo);
}

/* --- Asszisztens: eszközönként állapot és fizetve ------------------------ */

$adat = json_decode(SDH_Muhely_Asszisztens_Eszkozok::olvas('munkalap_adatok', ['munkalap' => (string) (int) t_lap($id)->munkalap_szam]), true);

T::igaz(is_array($adat) && count($adat['eszkozonkent'] ?? []) === 2, 'az asszisztens eszközönként látja a lapot');
T::egyezik('Elkészült', (string) ($adat['eszkozonkent'][1]['allapot'] ?? ''), 'az asszisztens látja az eszköz saját állapotát');
T::igaz(array_key_exists('fizetve', $adat['eszkozonkent'][0] ?? []), 'az asszisztens látja az eszköz fizetését');

/* --- Nincs PHP-figyelmeztetés -------------------------------------------- */

T::egyezik([], array_values(array_unique($php_hibak)), 'nincs PHP-figyelmeztetés a plugin kódjából');

T::vege('t26 füst');
