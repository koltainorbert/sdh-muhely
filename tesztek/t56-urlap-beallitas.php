<?php
/**
 * t56 – 0.62.0: űrlap-beállítások (fogaskerék minden űrlapon).
 *
 * - A munkalap, a rendelés, a napi árukiadás és a két számla ablakában ott a
 *   fogaskerék (adminnak), a címsor előtt, az űrlapon KÍVÜL.
 * - Kikapcsolt elem: data-sdh-ub-ki (rejtett), a mezője benne marad és mentődik.
 * - Kikapcsolt állapot: nem választható; ami a lapon áll és az alapállapot, mindig az.
 * - Számla: kikapcsolt „tételek magától" → pipa nélkül nyílnak; tétel-megjegyzés
 *   és Megjegyzés üresen; a két ablak (Számlázz.hu / előkészítő) külön állítható.
 * - Tárolás egy opcióban, csak a kikapcsolt elemek; a mentés más űrlaphoz nem nyúl;
 *   Beállítások › Űrlapok lapfül ment; nem admin nem menthet.
 */

require __DIR__ . '/_keret.php';

$UB = 'SDH_Muhely_Urlap_Beallitas';

delete_option($UB::OPT);
$UB::urit();

$u  = t_ugyfel('Fogas Kerek');
$e1 = t_eszkoz($u, 'Samsung', 'SM-A505FN/DS');
$e2 = t_eszkoz($u, 'Motorola', 'Moto G');
$id = t_munkalap($u, [$e1, $e2]);

t_tetel($id, 'Kijelzőcsere', 30000, 0);
t_tetel($id, 'Akkucsere', 12000, $e2);
SDH_Muhely_Tetel::ujraszamol($id);

$lap   = static fn (int $id): string => t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
$szaml = static fn (int $id, string $s): string => t_ajax('sdh_muhely_szamla_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id, 'sorozat' => $s])['nyers'];

/* --- 1. Alapállapot: minden látszik, fogaskerék mindenhol ------------------- */

$h = $lap($id);
T::tartalmaz($h, 'data-sdh-ub-nyit="javitas"', 'a munkalapon ott a fogaskerék');
T::igaz(strpos($h, 'data-sdh-ub-nyit') < strpos($h, '<form'), 'a fogaskerék és a panel az űrlapon kívül áll (nem megy be a lap mentésébe)');
T::nem_tartalmaz($h, 'data-sdh-ub-ki', 'alapból semmi nincs elrejtve');
T::tartalmaz($h, 'data-sdh-ub="bevizsgalas"', 'a bevizsgálási díj kapcsolható');
T::tartalmaz($h, 'data-sdh-ub="ful_hibak"', 'a Hibák lapfül kapcsolható');
T::tartalmaz($h, 'data-sdh-ub="gomb_szamlazz"', 'a Számlázz.hu gomb kapcsolható');

$ark = t_ajax('sdh_muhely_arukiadas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0])['nyers'];
T::tartalmaz($ark, 'data-sdh-ub-nyit="arukiadas"', 'a napi árukiadáson saját fogaskerék');
T::nem_tartalmaz($ark, 'data-sdh-ub="bevizsgalas"', 'árukiadásnál nincs bevizsgálási kapcsoló');

$ren = t_ajax('sdh_muhely_rendelesek_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0])['nyers'];
T::tartalmaz($ren, 'data-sdh-ub-nyit="rendeles"', 'a rendelésen saját fogaskerék');
T::tartalmaz($ren, 'data-sdh-ub="rendeles_sor"', 'a „Hol tart" sor kapcsolható');

$sz = $szaml($id, 'fo');
T::tartalmaz($sz, 'data-sdh-ub-nyit="szamla"', 'a Számlázz.hu ablakban ott a fogaskerék');
T::igaz(preg_match('/data-sdh-szamla-auto\s+checked/', $sz) === 1, 'a „munkalap tételei a számlán" kapcsoló alapból be');
T::egyezik(2, preg_match_all('/data-sdh-szamla-tetel\b[^>]*checked/s', $sz), 'alapból mindkét tétel kipipálva');
T::tartalmaz($sz, 'IMEI', 'alapból a tétel-megjegyzésben ott az eszköz');

$el = $szaml($id, 'helyi');
T::tartalmaz($el, 'data-sdh-ub-nyit="elokeszito"', 'a számla-előkészítőn saját fogaskerék');

/* --- 2. Mentés a fogaskerékből ----------------------------------------------- */

$elemek_be = static function (string $urlap, array $ki) use ($UB): array {
    $minden = [];

    foreach ($UB::urlapok()[$urlap]['csoportok'] as $cs) {
        $minden = array_merge($minden, array_keys($cs));
    }

    return array_values(array_diff($minden, $ki));
};

$allapotok = SDH_Muhely_Munkalap::allapotok();
$alap      = SDH_Muhely_Munkalap::alap_kulcs($allapotok);
$jelen     = (string) t_lap($id)->allapot;
$rejtendo  = '';

foreach ($allapotok as $k => $a) {
    if ((string) $k !== $alap && (string) $k !== $jelen && empty($a['reszben'])) {
        $rejtendo = (string) $k;
        break;
    }
}

$osszes_all = array_map('strval', array_keys($allapotok));

$r = t_ajax('sdh_muhely_urlap_beall_ment', [
    'urlap'      => 'javitas',
    'be'         => $elemek_be('javitas', ['bevizsgalas', 'ful_hibak', 'felelos']),
    'allapot_be' => array_values(array_diff($osszes_all, [$rejtendo, $jelen, $alap])), // a jelen és az alap is „kikapcsolva" jön
]);

T::igaz(!empty($r['success']), 'a fogaskerék mentése sikeres');
$UB::urit();

$b = $UB::beallitas()['javitas'];
sort($b['ki']);
T::egyezik(['bevizsgalas', 'felelos', 'ful_hibak'], $b['ki'], 'csak a kikapcsolt elemek tárolódnak');
T::igaz(in_array($rejtendo, $b['allapot_ki'], true), 'a kikapcsolt állapot tárolódik');
T::igaz(!in_array($alap, $b['allapot_ki'], true), 'az alapállapot nem kapcsolható ki');
T::igaz($UB::be('rendeles', 'felelos'), 'a többi űrlapra nincs hatással');

$h = $lap($id);
T::igaz(preg_match('/data-sdh-ub="bevizsgalas" data-sdh-ub-ki/', $h) === 1, 'a bevizsgálási díj rejtett');
T::igaz(preg_match('/<label for="ful_m_hibak"[^>]*data-sdh-ub-ki/', $h) === 1, 'a Hibák lapfül rejtett');
T::tartalmaz($h, 'name="felelos"', 'a rejtett Felelős mező benne marad (az értéke mentődik)');
T::tartalmaz($h, 'name="bevizsgalasi_dij"', 'a rejtett díjmező is benne marad');
T::igaz(preg_match('/data-sdh-ub-allapot="' . preg_quote($rejtendo, '/') . '" hidden disabled/', $h) === 1, 'a kikapcsolt állapot nem választható');
T::igaz(preg_match('/data-sdh-ub-allapot="' . preg_quote($jelen, '/') . '"\s+data-szin/', $h) === 1, 'ami a lapon áll, az választható marad');

/* --- 3. Számla: a tételek nem kerülnek rá magától --------------------------- */

t_ajax('sdh_muhely_urlap_beall_ment', [
    'urlap' => 'szamla',
    'be'    => $elemek_be('szamla', ['auto_tetelek', 'auto_tetel_megj', 'auto_megjegyzes']),
]);
$UB::urit();

$sz = $szaml($id, 'fo');
T::egyezik(0, preg_match_all('/data-sdh-szamla-tetel\b[^>]*checked/s', $sz), 'kikapcsolva egy tétel sincs kipipálva');
T::tartalmaz($sz, 'data-sdh-ub-auto="0"', 'az ablak tudja, hogy kézi a kitöltés');
T::igaz(preg_match('/<input type="checkbox" data-sdh-szamla-auto\s*>/', $sz) === 1, 'a gyorskapcsoló kikapcsolt állásban nyílik');
T::igaz(preg_match('/name="megjegyzes" id="szamla_megjegyzes_mezo" maxlength="500"\s+value=""/', $sz) === 1, 'a Megjegyzés üresen nyílik');
T::nem_tartalmaz($sz, 'IMEI: ', 'a tétel-megjegyzés üres');
T::tartalmaz($sz, 'data-sdh-szamla-uj="kezi"', 'a Kézi tétel gomb megvan');

$el = $szaml($id, 'helyi');
T::igaz(preg_match_all('/data-sdh-szamla-tetel\b[^>]*checked/s', $el) === 2, 'a számla-előkészítő külön állítható: ott marad magától');

/* --- 4. Beállítások › Űrlapok lapfül ----------------------------------------- */

T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['urlapok']), 'van Űrlapok lapfül a Beállításokban');

ob_start();
$UB::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::tartalmaz($doboz, 'name="ub[elokeszito][be][]"', 'a lapfülön mind az öt űrlap kapcsolói ott vannak');

$_POST = wp_slash(['ub' => ['elokeszito' => ['jelen' => 'elokeszito', 'be' => $elemek_be('elokeszito', ['auto_tetelek'])]]]);
$UB::beallitas_mentes();
$_POST = [];
$UB::urit();

T::igaz(!$UB::be('elokeszito', 'auto_tetelek'), 'a lapfül menti az előkészítő beállítását');
T::igaz(!$UB::be('szamla', 'auto_tetelek'), 'a lapfül mentése a be nem küldött űrlaphoz nem nyúl');

/* --- 5. Jogosultság ----------------------------------------------------------- */

$kollega = wp_insert_user(['user_login' => 'ub_kollega_' . wp_rand(1000, 9999), 'user_pass' => 'x' . wp_rand(), 'role' => 'editor']);
$admin   = get_current_user_id();

if (is_int($kollega)) {
    wp_set_current_user($kollega);
    $r = t_ajax('sdh_muhely_urlap_beall_ment', ['urlap' => 'javitas', 'be' => []]);
    T::igaz(empty($r['success']), 'nem admin nem menthet');
    T::egyezik('', $UB::gomb('javitas'), 'nem admin nem látja a fogaskereket');
    wp_set_current_user($admin);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($kollega);
}

$UB::urit();
T::igaz(!$UB::be('javitas', 'bevizsgalas'), 'a kolléga próbálkozása után is az admin beállítása él');

delete_option($UB::OPT);
$UB::urit();

T::vege('t56 űrlap-beállítások (fogaskerék), számla tételei magától vagy kézzel');
