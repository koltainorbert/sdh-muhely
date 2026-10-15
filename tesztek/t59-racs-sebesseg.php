<?php
/**
 * t59 – 0.64.0: az Áttekintés (rács) és a kezdőképernyő gyorsítása – ugyanaz az eredmény.
 *
 * - Gyorsítótár: a tárolt érték addig él, amíg a figyelt táblák (munkalap, ügyfél,
 *   eszköz, tétel …) nem változnak; az írás azonnal érvényteleníti; más tábla írása nem.
 * - A kezdőképernyő számai (nyitott munkalapok, ügyfelek, eszközök) másodszorra
 *   lekérdezés nélkül jönnek, írás után frissek.
 * - Rács: a halasztott számlálás (kesobb) – a sorok azonnal, a találatszám és az
 *   összegsor külön kérésben, ugyanazzal az értékkel; a túl nagy oldalszám az utolsó oldalra áll.
 * - Rács: az index menti rendezés (ügyfél, telefon, IMEI, sorozatszám, gyártó, típus,
 *   megnevezés, cím, azonosító) oldalanként PONTOSAN ugyanazt a sorrendet adja, mint a
 *   teljes ORDER BY – egyenlő értékek, üres érték, ügyfél / eszköz nélküli lap, napi árukiadás.
 * - Rács-kereső: a táblánkénti allekérdezés ugyanannyi találatot ad, mint a régi összekapcsolt út.
 * - Listák (Munkalapok, Ügyfelek, Eszközök): ugyanannyi találat, ugyanaz a sorrend; a lapozó
 *   kilenc oldalig a régi, fölötte ablakos (első kettő, aktuális ± 2, utolsó kettő).
 * - A böngészőoldal: halasztott számlálás, rögzített popupméret, egyszeri illesztés.
 * - A munkalap pénztári összege (két indexelt rész) = a régi, OR-os összeg.
 * - A fejléc keresője a gyors ügyfélkeresőt használja; a szerverállapot (OPcache, MySQL-puffer).
 * - A műhely-felület csomagja: egy CSS + egy JS, ugyanazok a fájlok ugyanabban a sorrendben,
 *   fájlonként try-blokkban; kikapcsolva minden a régi (egyenként).
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$R  = 'SDH_Muhely_Racs';
$G  = 'SDH_Muhely_Gyorsitotar';
$ut = SDH_Muhely_Schema::tabla('ugyfel');
$et = SDH_Muhely_Schema::tabla('eszkoz');
$mt = SDH_Muhely_Schema::tabla('munkalap');
$cimke = 'zq59x';

$takarit = static function () use ($wpdb, $ut, $et, $mt, $cimke): void {
    $wpdb->query("DELETE FROM {$mt} WHERE megjegyzes LIKE '%{$cimke}%'");
    $wpdb->query("DELETE FROM {$et} WHERE kulso_azonosito = 't59'");
    $wpdb->query('DELETE FROM ' . SDH_Muhely_Schema::tabla('ugyfel_szo') . " WHERE ugyfel_id IN (SELECT id FROM {$ut} WHERE kulso_azonosito = 't59')");
    $wpdb->query("DELETE FROM {$ut} WHERE kulso_azonosito = 't59'");
};

$takarit();

/* --- 1. Gyorsítótár -------------------------------------------------------------- */

$hivas = 0;
$szamol = static function () use (&$hivas): int {
    return ++$hivas;
};

$G::ervenytelenit();
T::egyezik(1, $G::ertek('t59-proba', $szamol), 'első kérésre számol');
T::egyezik(1, $G::ertek('t59-proba', $szamol), 'másodszorra a tárolt érték jön');
$G::tarolt('t59-proba', $van);
T::igaz($van === true, 'tarolt(): megvan');

$wpdb->query("UPDATE {$mt} SET jelzes = jelzes WHERE id = 0");
T::egyezik(2, $G::ertek('t59-proba', $szamol), 'munkalap-írás után újraszámol');

update_option('sdh_t59_proba', (string) wp_rand());
T::egyezik(2, $G::ertek('t59-proba', $szamol), 'más tábla (wp_options) írása nem érvényteleníti');

$wpdb->query('UPDATE ' . SDH_Muhely_Schema::tabla('ugyfel_szo') . " SET szo = szo WHERE ugyfel_id = 0");
T::egyezik(2, $G::ertek('t59-proba', $szamol), 'a szó-index írása nem érvényteleníti');
delete_option('sdh_t59_proba');

$G::tarolt('t59-nincs', $van);
T::igaz($van === false, 'tarolt(): ami nincs, az nincs');

// Takarítás: a régi adatverzió értékei törlődnek, a mostaniak és az állandók maradnak.
$G::ertek('t59-regi', static fn (): int => 1);
$G::ertek('t59-allando', static fn (): int => 7, 600, false);
$G::ervenytelenit();
$G::ertek('t59-uj', static fn (): int => 2);
delete_transient('sdh_muhely_gyorsitotar_takaritas');
$tak = new ReflectionMethod($G, 'takarit');
$tak->setAccessible(true);
$tak->invoke(null);
$G::tarolt('t59-uj', $van_uj);
T::igaz($van_uj === true, 'takarítás: a mostani verzió értéke megmarad');
T::egyezik(7, $G::ertek('t59-allando', static fn (): int => 99, 600, false), 'takarítás: az állandó érték megmarad');
$nev_regi = (new ReflectionMethod($G, 'nev'));
$nev_regi->setAccessible(true);
T::egyezik(0, (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_sdh_gyorsitotar_%' AND option_name NOT LIKE '_transient_sdh_gyorsitotar_allando_%'
       AND option_name NOT LIKE '" . esc_sql('_transient_' . substr((string) $nev_regi->invoke(null, 'x'), 0, 25)) . "%'"
), 'takarítás: régi verziójú érték nem maradt');
$G::allando_torol('t59-allando');

/* --- 2. A kezdőképernyő számai ------------------------------------------------------ */

$G::ervenytelenit();
$elso = SDH_Muhely_Munkalap::nyitott_db();
$n0   = $wpdb->num_queries;
$masod = SDH_Muhely_Munkalap::nyitott_db();
T::egyezik($n0, $wpdb->num_queries, 'nyitott munkalapok: másodszorra lekérdezés nélkül');
SDH_Muhely_Admin_UI::aktiv_db('ugyfel');
$n1 = $wpdb->num_queries;
SDH_Muhely_Admin_UI::aktiv_db('ugyfel');
SDH_Muhely_Admin_UI::aktiv_db('eszkoz');
SDH_Muhely_Eladas::nyitott_rendeles_db();
SDH_Muhely_Eladas::erkezett_db();
SDH_Muhely_Eladas::mai_arukiadas_db();
SDH_Muhely_Rma::olvasatlan_db();
$n2 = $wpdb->num_queries;
SDH_Muhely_Admin_UI::aktiv_db('eszkoz');
SDH_Muhely_Eladas::nyitott_rendeles_db();
SDH_Muhely_Eladas::erkezett_db();
SDH_Muhely_Eladas::mai_arukiadas_db();
SDH_Muhely_Rma::olvasatlan_db();
T::egyezik($elso, $masod, 'nyitott munkalapok: ugyanaz az érték');
T::egyezik($n2, $wpdb->num_queries, 'minden kezdőképernyő-szám másodszorra lekérdezés nélkül');
T::egyezik(
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ut} WHERE aktiv = 1"),
    SDH_Muhely_Admin_UI::aktiv_db('ugyfel'),
    'aktív ügyfelek: a tárolt érték helyes'
);

/* --- 3. Tesztadat a rácshoz ------------------------------------------------------------- */

$u = static function (string $nev, array $tov = []) use ($wpdb, $ut): int {
    $wpdb->insert($ut, $tov + ['nev' => $nev, 'aktiv' => 1, 'kulso_azonosito' => 't59', 'letrehozva' => current_time('mysql')]);
    $id = (int) $wpdb->insert_id;
    SDH_Muhely_Ugyfel::kereso_frissit($id);

    return $id;
};
$e = static function (int $ugyfel, string $gyarto, string $tipus, string $kat = 'telefon', string $imei = '') use ($wpdb, $et): int {
    $wpdb->insert($et, [
        'ugyfel_id' => $ugyfel, 'gyarto' => $gyarto, 'tipus' => $tipus, 'megnevezes' => trim($gyarto . ' ' . $tipus),
        'kategoria' => $kat, 'imei' => $imei, 'sorozatszam' => $imei !== '' ? 'S' . substr($imei, -5) : '', 'aktiv' => 1,
        'kulso_azonosito' => 't59', 'letrehozva' => current_time('mysql'),
    ]);

    return (int) $wpdb->insert_id;
};
$m = static function (int $ugyfel, int $eszkoz, string $fajta = 'javitas', string $allapot = 'nyitott') use ($wpdb, $mt, $cimke): int {
    static $i = 0;
    $i++;
    $wpdb->insert($mt, [
        'ugyfel_id' => $ugyfel, 'eszkoz_id' => $eszkoz, 'fajta' => $fajta, 'allapot' => $allapot,
        'megjegyzes' => "t59 {$cimke} #{$i}", 'keszult' => gmdate('Y-m-d', time() - $i * 86400),
        'brutto_ertek' => 1000 * $i, 'fizetett' => $i % 3 === 0 ? 1000 * $i : 0, 'fizetve' => $i % 3 === 0 ? 1 : 0,
        'letrehozva' => current_time('mysql'),
    ]);

    return (int) $wpdb->insert_id;
};

$ugyfelek = [
    $u('Anna Teszt', ['telefon' => '+36301110001', 'szamlazasi_iranyitoszam' => '8200', 'szamlazasi_telepules' => 'Veszprém', 'szamlazasi_cim' => 'Fő utca 1.']),
    $u('Anna Teszt', ['telefon' => '+36301110002', 'szamlazasi_telepules' => 'Zirc']),     // egyenlő név, más ügyfél
    $u('anna teszt', ['telefon' => '+36301110003']),                                          // kis-nagybetű
    $u('Ábel Teszt', ['telefon' => '+36301110004', 'szamlazasi_iranyitoszam' => '8200']),
    $u('Zoltán Teszt', ['telefon' => '']),
    $u('', ['telefon' => '+36301110006']),                                                   // üres név
    $u('Béla Teszt', ['telefon' => '+36301110007', 'szamlazasi_cim' => 'Kossuth tér 2.']),
];
$eszkozok = [];

foreach ($ugyfelek as $k => $uid) {
    $eszkozok[] = $e($uid, ['Apple', 'Samsung', '', 'Apple', 'Xiaomi', 'Samsung', 'apple'][$k], ['iPhone 12', 'Galaxy S21', '', 'iPhone 12', 'Redmi', 'A52', 'iPad'][$k],
        ['telefon', 'telefon', '', 'tablet', 'telefon', 'szamitogep', 'tablet'][$k], $k === 2 ? '' : '35' . str_pad((string) (1000 + $k * 7), 13, '0', STR_PAD_LEFT));
}

$lapok = [];

for ($i = 0; $i < 24; $i++) {
    $k       = $i % count($ugyfelek);
    $lapok[] = $m($ugyfelek[$k], $i % 5 === 4 ? 0 : $eszkozok[$k], 'javitas', ['nyitott', 'bejelentett', 'elkeszult', 'lezart'][$i % 4]);
}

for ($i = 0; $i < 5; $i++) {
    $lapok[] = $m(0, 0, 'arukiadas', 'lezart');   // napi árukiadás: se ügyfél, se eszköz
}

$lapok[] = $m($ugyfelek[0], $eszkozok[1], 'javitas', 'fuggo');

T::egyezik(30, count($lapok), 'a rács tesztadata (30 lap)');

/* --- 4. Halasztott számlálás ------------------------------------------------------------------ */

$alap = $R::allapot_tisztit(['q' => $cimke, 'lathato' => ['szam', 'megjegyzes', 'ugyfel', 'telefon', 'imei'], 'meret' => 25]);

$G::ervenytelenit();
$a1 = $R::adat($alap, true);
T::igaz($a1['osszesen'] === null && $a1['oldalak'] === null, 'kesobb: első kérésre a számlálás nem várat');
T::egyezik(25, count($a1['sorok']), 'kesobb: a sorok így is megjönnek');

$o = $R::osszeg($alap);
T::egyezik(30, $o['osszesen'], 'az összesítés: 30 találat');
T::egyezik(2, $o['oldalak'], 'az összesítés: 2 oldal');
T::egyezik(
    (float) $wpdb->get_var("SELECT SUM(brutto_ertek) FROM {$mt} WHERE megjegyzes LIKE '%{$cimke}%' AND fajta IN ('javitas', 'arukiadas')"),
    (float) $o['osszegek']['brutto'],
    'az összegsor: a bruttó összeg egyezik'
);

$a2 = $R::adat($alap, true);
T::egyezik(30, $a2['osszesen'], 'kesobb: másodszorra a tárolt számmal jön');
T::egyezik($a1['sorok'], $a2['sorok'], 'kesobb: ugyanazok a sorok');

$a3 = $R::adat($alap);
T::egyezik($o, ['osszesen' => $a3['osszesen'], 'oldalak' => $a3['oldalak'], 'osszegek' => $a3['osszegek']], 'a régi (azonnali) út ugyanazt számolja');

$G::ervenytelenit();
$tul = $R::adat(array_merge($alap, ['oldal' => 9]), true);
T::egyezik(2, $tul['oldal'], 'kesobb: a túl nagy oldalszám az utolsó létezőre áll');
T::egyezik(30, $tul['osszesen'], 'kesobb: ilyenkor a számlálás is megjön');

/* --- 5. Index menti rendezés = teljes ORDER BY ------------------------------------------------------------- */

$feltetelek = new ReflectionMethod($R, 'feltetelek');
$forras     = new ReflectionMethod($R, 'forras_sql');
$rendez     = new ReflectionMethod($R, 'rendezes_sql');
$seta       = new ReflectionMethod($R, 'seta_idk');

foreach ([$feltetelek, $forras, $rendez, $seta] as $rm) {
    $rm->setAccessible(true);
}

$regi_ut = static function (array $all, int $meret, int $eltolas) use ($wpdb, $feltetelek, $forras, $rendez): array {
    [$hol, $ertekek] = $feltetelek->invoke(null, $all);
    $rsql = $rendez->invoke(null, $all['rendezes']);

    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        'SELECT m.id ' . $forras->invoke(null, null) . " WHERE {$hol} {$rsql} LIMIT %d OFFSET %d",
        array_merge($ertekek, [$meret, $eltolas])
    )));
};

$eltero = [];
$futott = 0;

foreach (['ugyfel', 'telefon', 'imei', 'sorozatszam', 'gyarto', 'tipus', 'megnevezes', 'cim', 'azonosito'] as $kulcs) {
    foreach (['fel', 'le'] as $irany) {
        $all = $R::allapot_tisztit([
            'q' => $cimke, 'lathato' => ['szam', 'megjegyzes'], 'meret' => 25,
            'rendezes' => [['k' => $kulcs, 'i' => $irany]],
        ]);
        [$hol, $ertekek] = $feltetelek->invoke(null, $all);

        foreach ([[25, 0], [25, 25], [25, 10], [25, 40]] as [$meret, $eltolas]) {
            $uj = $seta->invoke(null, $all, $hol, $ertekek, $meret, $eltolas);

            if ($uj === null) {
                continue; // a gyors út nem vállalta (ilyenkor a régi megy) – ez is helyes
            }

            $futott++;
            $regi = $regi_ut($all, $meret, $eltolas);

            if ($uj !== $regi) {
                $eltero[] = "{$kulcs} {$irany} @{$eltolas}: " . implode(',', $uj) . ' ≠ ' . implode(',', $regi);
            }
        }
    }
}

T::igaz($futott >= 20, "az index menti rendezés lefutott ({$futott} oldal)");
T::egyezik([], $eltero, 'index menti rendezés = teljes ORDER BY (minden oszlop, mindkét irány, több oldal)');

// Szűrővel együtt is (állapot szerint szűrve, ügyfél szerint rendezve).
$all = $R::allapot_tisztit([
    'q' => $cimke, 'lathato' => ['szam', 'megjegyzes'], 'meret' => 25,
    'rendezes' => [['k' => 'ugyfel', 'i' => 'fel']], 'szurok' => ['allapot' => ['op' => 'egyike', 'l' => ['nyitott', 'lezart']]],
]);
[$hol, $ertekek] = $feltetelek->invoke(null, $all);
T::egyezik($regi_ut($all, 25, 0), $seta->invoke(null, $all, $hol, $ertekek, 25, 0) ?? $regi_ut($all, 25, 0), 'szűrővel együtt is ugyanaz a sorrend');

// Több rendezési kulcsnál a gyors út nem vállalja (a régi megy).
$all = $R::allapot_tisztit(['q' => $cimke, 'rendezes' => [['k' => 'ugyfel', 'i' => 'fel'], ['k' => 'keszult', 'i' => 'le']]]);
[$hol, $ertekek] = $feltetelek->invoke(null, $all);
T::igaz($seta->invoke(null, $all, $hol, $ertekek, 25, 0) === null, 'több rendezési kulcsnál a régi út megy');

// A teljes adat() is ugyanazt a sorrendet adja.
$all = $R::allapot_tisztit(['q' => $cimke, 'lathato' => ['szam', 'megjegyzes'], 'meret' => 25, 'rendezes' => [['k' => 'ugyfel', 'i' => 'fel']]]);
T::egyezik($regi_ut($all, 25, 0), array_map(static fn (array $s): int => (int) $s['id'], $R::adat($all)['sorok']), 'adat(): ügyfél szerinti sorrend = régi');

/* --- 6. Rács-kereső: táblánkénti allekérdezés = régi összekapcsolt út ------------------------------------- */

foreach (['Anna', 'teszt', '3011100', 'Apple', 'iPhone', $cimke, 'Kossuth'] as $q) {
    $all = $R::allapot_tisztit(['q' => $q, 'lathato' => ['ugyfel', 'telefon', 'cim', 'gyarto', 'tipus', 'imei', 'megjegyzes']]);
    [$hol] = $feltetelek->invoke(null, $all);
    T::igaz(str_contains($hol, 'm.ugyfel_id IN (SELECT ku.id') && str_contains($hol, 'm.eszkoz_id IN (SELECT ke.id'), "kereső „{$q}”: ügyfél és eszköz allekérdezésben");

    $minta = '%' . $wpdb->esc_like($q) . '%';
    $regi  = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$mt} m LEFT JOIN {$ut} u ON u.id = m.ugyfel_id LEFT JOIN {$et} e ON e.id = m.eszkoz_id
         WHERE m.fajta IN ('javitas', 'arukiadas') AND (COALESCE(u.nev, '') LIKE %s OR COALESCE(u.telefon, '') LIKE %s
           OR TRIM(CONCAT(COALESCE(u.szamlazasi_iranyitoszam, ''), ' ', COALESCE(u.szamlazasi_telepules, ''), ' ', COALESCE(u.szamlazasi_cim, ''))) LIKE %s
           OR COALESCE(e.gyarto, '') LIKE %s OR COALESCE(e.tipus, '') LIKE %s OR COALESCE(e.imei, '') LIKE %s OR COALESCE(m.megjegyzes, '') LIKE %s
           OR EXISTS (SELECT 1 FROM " . SDH_Muhely_Schema::tabla('munkalap_eszkoz') . " tk INNER JOIN {$et} te2 ON te2.id = tk.eszkoz_id
                      WHERE tk.munkalap_id = m.id AND (te2.imei LIKE %s OR te2.imei2 LIKE %s OR te2.sorozatszam LIKE %s OR te2.tipus LIKE %s OR te2.megnevezes LIKE %s OR te2.gyarto LIKE %s)))",
        $minta, $minta, $minta, $minta, $minta, $minta, $minta, $minta, $minta, $minta, $minta, $minta, $minta
    ));
    T::egyezik($regi, $R::osszeg($all)['osszesen'], "kereső „{$q}”: ugyanannyi találat, mint a régi úton");
}

/* --- 6/b. Listák (Munkalapok, Ügyfelek, Eszközök): ugyanannyi találat, ugyanazok a sorok --------------------- */

$lista = static function (string $osztaly, array $get): string {
    $regi_get = $_GET;
    $_GET     = $get;
    $rm       = new ReflectionMethod($osztaly, 'lista_oldal');
    $rm->setAccessible(true);
    ob_start();
    $rm->invoke(null);
    $_GET = $regi_get;

    return (string) ob_get_clean();
};
$talalat = static function (string $html): int {
    return preg_match('/([\d\s\x{00A0}.,&nbsp;]+?)\s*találat/u', strip_tags(str_replace('&nbsp;', '', $html)), $m) === 1
        ? (int) preg_replace('/\D+/', '', $m[1]) : -1;
};

foreach (['Anna', 'teszt', '3011100', 'Apple', 'iPhone', '0001007'] as $q) {
    $minta = '%' . $wpdb->esc_like($q) . '%';
    $regi  = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$mt} m LEFT JOIN {$ut} u ON u.id = m.ugyfel_id LEFT JOIN {$et} e ON e.id = m.eszkoz_id
         WHERE m.fajta = 'javitas' AND (m.nev LIKE %s OR u.nev LIKE %s OR u.telefon LIKE %s OR e.imei LIKE %s OR e.sorozatszam LIKE %s
           OR e.gyarto LIKE %s OR e.tipus LIKE %s OR e.megnevezes LIKE %s
           OR EXISTS (SELECT 1 FROM " . SDH_Muhely_Schema::tabla('munkalap_eszkoz') . " tk INNER JOIN {$et} te2 ON te2.id = tk.eszkoz_id
                      WHERE tk.munkalap_id = m.id AND (te2.imei LIKE %s OR te2.imei2 LIKE %s OR te2.sorozatszam LIKE %s OR te2.tipus LIKE %s OR te2.megnevezes LIKE %s)))",
        ...array_fill(0, 13, $minta)
    ));

    if (ctype_digit($q)) {
        // Csupa számjegynél a munkalapszám pontos egyezése is találat (régen is így volt).
        $regi += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$mt} m WHERE m.fajta = 'javitas' AND m.munkalap_szam = %d AND m.id NOT IN (SELECT m2.id FROM {$mt} m2 LEFT JOIN {$ut} u ON u.id = m2.ugyfel_id LEFT JOIN {$et} e ON e.id = m2.eszkoz_id WHERE m2.nev LIKE %s OR u.nev LIKE %s OR u.telefon LIKE %s OR e.imei LIKE %s OR e.sorozatszam LIKE %s OR e.gyarto LIKE %s OR e.tipus LIKE %s OR e.megnevezes LIKE %s)", (int) $q, ...array_fill(0, 8, $minta)));
    }

    T::egyezik($regi, $talalat($lista('SDH_Muhely_Munkalap', ['k' => $q, 'allapot' => 'mind'])), "Munkalapok lista, „{$q}”: ugyanannyi találat");
}

$html_regi_sorrend = $lista('SDH_Muhely_Munkalap', ['allapot' => 'mind']);
preg_match_all('/data-sdh-id="(\d+)"\s+data-sdh-munkalap-szam/', $html_regi_sorrend, $lista_idk);
$lista_idk = array_map('intval', $lista_idk[1]);
$vart_idk  = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$mt} WHERE fajta = 'javitas' ORDER BY munkalap_szam DESC, id DESC LIMIT %d", max(1, count($lista_idk)))));
T::igaz($lista_idk !== [] && $lista_idk === $vart_idk, 'Munkalapok lista: a sorok sorrendje a régi (sorszám szerint csökkenő)');

T::egyezik(
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ut} WHERE aktiv = 1"),
    $talalat($lista('SDH_Muhely_Ugyfel', [])),
    'Ügyfelek lista: a találatszám (tárolva) helyes'
);
T::egyezik(
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$et} e LEFT JOIN {$ut} u ON u.id = e.ugyfel_id WHERE e.aktiv = 1 AND (e.gyarto LIKE %s OR e.tipus LIKE %s OR e.megnevezes LIKE %s OR e.imei LIKE %s OR e.imei2 LIKE %s OR e.sorozatszam LIKE %s OR u.nev LIKE %s)", ...array_fill(0, 7, '%Teszt%'))),
    $talalat($lista('SDH_Muhely_Eszkoz', ['k' => 'Teszt'])),
    'Eszközök lista, kereső: a találatszám helyes'
);

$sok = SDH_Muhely_Admin_UI::lapozo_oldalak(500, 20000);
T::egyezik([1, 2, 0, 498, 499, 500, 501, 502, 0, 19999, 20000], $sok, 'lapozó: 20 000 oldalnál csak a szélek és az aktuális környéke');
T::egyezik(range(1, 9), SDH_Muhely_Admin_UI::lapozo_oldalak(3, 9), 'lapozó: kilenc oldalig minden oldal (a régi alak)');
T::egyezik([1, 2, 3, 4, 5, 0, 99, 100], SDH_Muhely_Admin_UI::lapozo_oldalak(3, 100), 'lapozó: az elején');
T::egyezik([1, 2, 3, 4, 5, 6, 7], array_slice(SDH_Muhely_Admin_UI::lapozo_oldalak(5, 10), 0, 7), 'lapozó: egyetlen kihagyott oldal helyett maga az oldal');

/* --- 7. Böngészőoldal -------------------------------------------------------------------------------------- */

$racs_js = (string) file_get_contents(dirname(__DIR__) . '/assets/racs.js');
$app_js  = (string) file_get_contents(dirname(__DIR__) . '/assets/app.js');
T::tartalmaz($racs_js, "kesobb: '1'", 'racs.js: a sorok nem várnak a számlálásra');
T::tartalmaz($racs_js, "kuld('sdh_muhely_racs_osszeg'", 'racs.js: a találatszám külön kérésben jön');
T::tartalmaz($racs_js, 'function rajzolLab(', 'racs.js: az összegsor külön frissül');
T::tartalmaz($app_js, 'function popupRogzit(', 'app.js: a popup betöltés alatt a végleges méretében áll');
T::tartalmaz($app_js, "dialog.classList.contains('sdh-modal--tolt')", 'app.js: betöltés alatt nincs illesztés');
T::tartalmaz($app_js, 'sdhIllesztFigyelo.takeRecords()', 'app.js: a beillesztés után egyszeri illesztés');

/* --- 8. A munkalap pénztári összege: két indexelt rész = a régi OR-os összeg -------------------------------- */

$pt   = SDH_Muhely_Schema::tabla('penztar');
$lap1 = $lapok[0];
$lap2 = $lapok[1];
$szam1 = 'T59-' . $lap1;
$wpdb->update($mt, ['munkalap_szam' => null], ['id' => $lap1]);
$be = static function (array $sor) use ($wpdb, $pt): void {
    $wpdb->insert($pt, $sor + ['datum' => current_time('Y-m-d'), 'tipus' => 'bevetel', 'kulso' => 't59', 'letrehozva' => current_time('mysql')]);
};
$be(['munkalap_id' => $lap1, 'kp' => 1000]);                                       // azonosítóval
$be(['munkalap_id' => $lap1, 'munkalap_szam' => $szam1, 'kartya' => 2000]);         // mindkettővel (egyszer számít)
$be(['munkalap_id' => 0, 'munkalap_szam' => $szam1, 'utalas' => 4000]);             // csak számmal
$be(['munkalap_id' => $lap2, 'munkalap_szam' => $szam1, 'kp' => 8000]);             // másik lap, ugyanazzal a számmal
$be(['munkalap_id' => $lap1, 'kp' => 16000, 'torolve' => 1]);                       // törölt: nem számít
$be(['munkalap_id' => $lap1, 'tipus' => 'kihagyva', 'info_osszeg' => 32000]);       // kihagyott: az info összeg
$be(['munkalap_id' => 0, 'munkalap_szam' => $szam1, 'kp' => 64000, 'datum' => '2001-01-01']); // régi: nem számít

$regi_kassza = static function (int $id, string $szam) use ($wpdb, $pt): float {
    return round((float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(CASE WHEN tipus = 'kihagyva' THEN info_osszeg ELSE kp + kartya + utalas END), 0)
         FROM {$pt} WHERE torolve = 0 AND tipus IN ('bevetel', 'kihagyva')
           AND (munkalap_id = %d OR (munkalap_szam <> '' AND munkalap_szam = %s AND datum >= %s))",
        $id > 0 ? $id : -1, $szam !== '' ? $szam : '#', '2026-01-01'
    )), 2);
};

foreach ([[$lap1, $szam1], [$lap2, $szam1], [$lap1, ''], [0, $szam1]] as [$id_, $sz_]) {
    T::egyezik($regi_kassza($id_, $sz_), SDH_Muhely_Penztar::munkalap_kasszaban($id_, $sz_, '2026-01-03'), "pénztári összeg ({$id_}, „{$sz_}”) = a régi");
}

T::egyezik(1000.0 + 2000 + 4000 + 8000 + 32000, SDH_Muhely_Penztar::munkalap_kasszaban($lap1, $szam1, '2026-01-03'), 'pénztári összeg: a várt érték');
$wpdb->query("DELETE FROM {$pt} WHERE kulso = 't59'");

/* --- 9. Fejléc-kereső, szerverállapot, felület-csomag ----------------------------------------------------- */

$gk = t_ajax('sdh_muhely_globalis_kereso', [], '_wpnonce', 'sdh_muhely_modal', ['q' => 'Ábel Teszt']);
T::tartalmaz((string) wp_json_encode($gk['data'] ?? [], JSON_UNESCAPED_UNICODE), 'Ábel Teszt', 'a fejléc keresője megtalálja az ügyfelet (gyors út)');

$sz = SDH_Muhely_Csomag::szerver_allapot();
T::igaz(isset($sz['opcache'], $sz['puffer'], $sz['tanacs']) && in_array($sz['opcache'], ['be', 'ki'], true), 'a szerverállapot (OPcache, MySQL-puffer) kiolvasható');

$csomag_regi = get_option(SDH_Muhely_Csomag::OPT, '1');
update_option(SDH_Muhely_Csomag::OPT, '1');
ob_start();
SDH_Muhely_Frontend::keret('Teszt', 'attekintes', static function (): void {
});
$be_html = (string) ob_get_clean();
update_option(SDH_Muhely_Csomag::OPT, '0');
ob_start();
SDH_Muhely_Frontend::keret('Teszt', 'attekintes', static function (): void {
});
$ki_html = (string) ob_get_clean();
update_option(SDH_Muhely_Csomag::OPT, $csomag_regi);

preg_match_all('#assets/([a-z\-]+\.js)\?v=#', $ki_html, $kulon_js);
preg_match_all('#assets/([a-z\-]+\.css)\?v=#', $ki_html, $kulon_css);
T::igaz(count($kulon_js[1]) >= 15 && $kulon_js[1][0] === 'app.js' && in_array('racs.js', $kulon_js[1], true), 'kikapcsolva: a szkriptek egyenként, az app.js elöl');
T::igaz(count($kulon_css[1]) >= 15 && $kulon_css[1][0] === 'admin.css', 'kikapcsolva: a stíluslapok egyenként');
T::egyezik(1, preg_match_all('#<script src="[^"]+/sdh-muhely-csomag/sdh-[0-9a-f]+\.js" data-sdh-csomag data-sdh-js-v="#', $be_html), 'bekapcsolva: egyetlen szkriptcsomag');
T::egyezik(1, preg_match_all('#<link rel="stylesheet" href="[^"]+/sdh-muhely-csomag/sdh-[0-9a-f]+\.css" data-sdh-csomag data-sdh-css-v="#', $be_html), 'bekapcsolva: egyetlen stíluscsomag');
T::nem_tartalmaz($be_html, 'assets/app.js?v=', 'bekapcsolva: nincs külön app.js');

preg_match('#<script src="([^"]+/sdh-muhely-csomag/[^"]+\.js)"#', $be_html, $cs);
$feltoltes = wp_get_upload_dir();
$csomag    = (string) @file_get_contents(str_replace($feltoltes['baseurl'], $feltoltes['basedir'], $cs[1] ?? ''));
preg_match_all('#/\* ([a-z\-]+\.js) \*/\ntry \{#', $csomag, $benne);
T::egyezik($kulon_js[1], $benne[1], 'a szkriptcsomag ugyanazokat a fájlokat, ugyanabban a sorrendben tartalmazza');
T::tartalmaz($csomag, "} catch (sdhHiba) {", 'a csomagban minden fájl saját try-blokkban fut');
T::igaz(is_file($feltoltes['basedir'] . '/sdh-muhely-csomag/.htaccess'), 'a csomag mappájában gyorsítótár-szabály (Apache)');

$takarit();
$G::ervenytelenit();

T::vege('t59 rács és kezdőképernyő sebesség');
