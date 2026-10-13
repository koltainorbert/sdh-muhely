<?php
/**
 * t52 – 0.59.4: Szikra élete. Beállítások (Szikra élete, Altesti humor), a
 * kliensadat, a betöltés, és a szikra-elet.js / .css tartalma: mimikák,
 * jelenetek, szükségletek, leállítás kattintásra, „kevesebb mozgás".
 * A mimikák pontos számát a böngészőoldali kód adja (node-dal mérve: 441).
 */

require __DIR__ . '/_keret.php';

$AI     = 'SDH_Muhely_Asszisztens';
$assets = SDH_MUHELY_DIR . 'assets/';

/* --- Beállítás: alapból be, mentés a jelzőmezővel ---------------------------- */

delete_option('sdh_muhely_asszisztens');
T::igaz($AI::beallitas()['elet'] === true, 'Szikra élete alapból be van kapcsolva');
T::igaz($AI::beallitas()['altesti'] === true, 'az altesti humor alapból be van kapcsolva');
T::igaz(($AI::js_adat()['elet'] ?? null) === true && ($AI::js_adat()['altesti'] ?? null) === true, 'a böngésző megkapja mindkét kapcsolót');

$alap = ['be' => '1', 'nev' => 'Szikra', 'proaktiv' => '1', 'napi_max' => '300', 'humor' => '90', 'elenkseg' => '80'];

t_ajax('sdh_muhely_ai_beallit', $alap + ['elet_jelen' => '1', 'elet' => '1']);
T::igaz($AI::beallitas()['elet'] === true && $AI::beallitas()['altesti'] === false, 'csak az élet bepipálva: az altesti humor kikapcsol');

t_ajax('sdh_muhely_ai_beallit', $alap + ['elet_jelen' => '1']);
T::igaz($AI::beallitas()['elet'] === false, 'kipipálatlanul mentve az élet kikapcsol');

t_ajax('sdh_muhely_ai_beallit', $alap);
T::igaz($AI::beallitas()['elet'] === false, 'régi űrlap (jelzőmező nélkül) nem nyúl hozzá');

t_ajax('sdh_muhely_ai_beallit', $alap + ['elet_jelen' => '1', 'elet' => '1', 'altesti' => '1']);
T::igaz($AI::beallitas()['elet'] === true && $AI::beallitas()['altesti'] === true, 'bepipálva mindkettő visszakapcsol');

ob_start();
$AI::beallitas_nezet();
$oldal = (string) ob_get_clean();
T::tartalmaz($oldal, 'name="elet"', 'az űrlapon ott a Szikra élete kapcsoló');
T::tartalmaz($oldal, 'name="altesti"', 'és az Altesti humor kapcsoló');
T::tartalmaz($oldal, 'name="elet_jelen"', 'és a jelzőmező');

/* --- Betöltés ---------------------------------------------------------------- */

$fe = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-frontend.php');
T::tartalmaz($fe, "assets/szikra-elet.js", 'a szikra-elet.js betöltődik');
T::tartalmaz($fe, "assets/szikra-elet.css", 'a szikra-elet.css betöltődik');
T::igaz(strpos($fe, 'szikra-elet.js') > strpos($fe, "assets/asszisztens.js"), 'az asszisztens.js után töltődik');

/* --- A figura felülete ------------------------------------------------------- */

$asz = (string) file_get_contents($assets . 'asszisztens.js');
T::tartalmaz($asz, 'window.SDH_SZIKRA = {', 'az asszisztens.js kiadja a figura felületét');
T::tartalmaz($asz, "!gyoker.classList.contains('is-jelenet')", 'jelenet közben a figura nem szabad');
T::tartalmaz($asz, "new CustomEvent('sdh:szikra-kesz')", 'és szól, amikor kész');

/* --- szikra-elet.js ---------------------------------------------------------- */

$js  = (string) file_get_contents($assets . 'szikra-elet.js');
$css = (string) file_get_contents($assets . 'szikra-elet.css');

T::tartalmaz($js, "A.elet === false", 'kikapcsolt élet mellett semmi nem indul');
T::tartalmaz($js, "var ALTESTI = A.altesti !== false;", 'az altesti humor kapcsolója');

preg_match('/var CSALADOK = \[(.*?)\];/s', $js, $c);
preg_match('/var ARNYALATOK = \[(.*?)\];/s', $js, $a);
$csaladok  = preg_match_all("/\['[^']+', '[a-z_]+', '[a-z_]+', '[a-z_]+', '[a-z_]*'\]/u", $c[1] ?? '');
$arnyalatok = preg_match_all("/\['[^']*', \{/u", $a[1] ?? '');
T::igaz($csaladok >= 50, 'legalább 50 alap-érzelem (' . $csaladok . ')');
T::igaz($csaladok * $arnyalatok >= 300 * 1.3, 'az árnyalatokkal bőven 300 fölött (' . $csaladok . ' × ' . $arnyalatok . ', ismétlés és tiltott párosítás nélkül 441)');

foreach (['csirke', 'eger', 'madar', 'pityu', 'kodbaro', 'pisi', 'kaki', 'fing', 'budos', 'tusszent', 'csuklik', 'eves', 'ivas', 'csajozas', 'baratno', 'motor', 'auto', 'edzes', 'telefon', 'zene', 'vakarozik'] as $j) {
    T::igaz(preg_match('/\n        ' . $j . ': function \(c\) \{/', $js) === 1, 'jelenet: ' . $j);
}

T::tartalmaz($js, "var ALTESTI_JELENETEK = ['pisi', 'kaki', 'fing', 'budos'];", 'az altesti jelenetek külön kapcsolhatók');
T::tartalmaz($js, 'return ALTESTI || ALTESTI_JELENETEK.indexOf(j[0]) === -1;', 'kikapcsolva nem kerülnek a sorba');
T::tartalmaz($js, 'var NOVEKEDES = { ehseg:', 'szükségletek');
T::tartalmaz($js, "window.localStorage.setItem(TAR", 'a szükségletek megmaradnak oldalváltáskor');
T::tartalmaz($js, "['keydown', 'click', 'pointerdown'].forEach", 'kattintás / gépelés leállítja a jelenetet');
T::tartalmaz($js, 'return Date.now() - utolsoAktiv > 8000;', 'munka közben nem kezd jelenetbe');
T::tartalmaz($js, "if (fut || !S || S.nyugodt || !S.szabad())", 'csak szabad figurával, „kevesebb mozgás” nélkül');
T::tartalmaz($css, "@media (prefers-reduced-motion: reduce) {\n    .sdh-ai .sdh-sz-szinpad", '„kevesebb mozgás” mellett nincs színpad');
T::tartalmaz($css, '.sdh-ai.is-mimika .sdh-ai__arc', 'a mimika a megszokott arc helyére kerül');

// Saját szereplők: ismert, jogvédett figura neve nem szerepel.
foreach (['kenny', 'vader', 'south park', 'star wars'] as $tilos) {
    T::igaz(stripos($js . $css, $tilos) === false, 'nincs benne: ' . $tilos);
}

/* --- Súgó -------------------------------------------------------------------- */

$sugo = (string) file_get_contents(SDH_MUHELY_DIR . 'sugo/16-asszisztens.md');
T::tartalmaz($sugo, '### Szikra élete', 'a súgóban van fejezet');
T::tartalmaz($sugo, '| **Altesti humor** |', 'a beállítás leírása is');

T::vege('t52 Szikra élete');
