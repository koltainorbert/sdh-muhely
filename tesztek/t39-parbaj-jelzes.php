<?php
/**
 * t39 – 0.54.0 / 0.61.1: a levél-jelzések a jobb felső sarokban állnak, a buborék
 * fölébük emelkedik; párbaj a Hiba-bogárral (beállítás, mentés, kliensadat, rajz).
 */

require __DIR__ . '/_keret.php';

$AI = 'SDH_Muhely_Asszisztens';
$assets = SDH_MUHELY_DIR . 'assets/';

/* --- Beállítás: alapból be, mentés a jelzőmezővel ---------------------------- */

delete_option('sdh_muhely_asszisztens');
T::igaz($AI::beallitas()['parbaj'] === true, 'a párbaj alapból be van kapcsolva');
T::igaz(($AI::js_adat()['parbaj'] ?? null) === true, 'a böngésző megkapja a párbaj kapcsolót');

$alap = ['be' => '1', 'nev' => 'Szikra', 'proaktiv' => '1', 'napi_max' => '300', 'humor' => '90', 'elenkseg' => '80'];

t_ajax('sdh_muhely_ai_beallit', $alap + ['parbaj_jelen' => '1']);
T::igaz($AI::beallitas()['parbaj'] === false, 'kipipálatlanul mentve kikapcsol');

t_ajax('sdh_muhely_ai_beallit', $alap);
T::igaz($AI::beallitas()['parbaj'] === false, 'régi űrlap (jelzőmező nélkül) nem nyúl hozzá');

t_ajax('sdh_muhely_ai_beallit', $alap + ['parbaj_jelen' => '1', 'parbaj' => '1']);
T::igaz($AI::beallitas()['parbaj'] === true, 'bepipálva mentve bekapcsol');

/* --- Az űrlapon ott a kapcsoló ----------------------------------------------- */

ob_start();
$AI::beallitas_nezet();
$oldal = (string) ob_get_clean();
T::tartalmaz($oldal, 'name="parbaj"', 'a Beállítások › Asszisztens űrlapon ott a párbaj kapcsoló');
T::tartalmaz($oldal, 'name="parbaj_jelen"', 'és a jelzőmező');

/* --- A böngészőoldali részek ------------------------------------------------- */

$js  = (string) file_get_contents($assets . 'asszisztens.js');
$css = (string) file_get_contents($assets . 'asszisztens.css');
$lev = (string) file_get_contents($assets . 'levelezes.js');
$lcs = (string) file_get_contents($assets . 'levelezes.css');

T::tartalmaz($js, 'CSELEKVES.push([2, parbaj])', 'a párbaj a magától végzett cselekvések között van');
T::tartalmaz($js, "if (PARBAJ)", 'csak bekapcsolva kerül közéjük');
T::tartalmaz($js, "document.querySelector('.sdh-level-toast')", 'levél-jelzés látszása közben nem kezdődik el');
T::tartalmaz($js, "addEventListener('sdh:level-toast'", 'új levél-jelzésre leáll és a buborék igazodik');
T::tartalmaz($js, 'parbajVege();', 'kattintás / gépelés / mentés leállítja');
T::tartalmaz($lev, "new CustomEvent('sdh:level-toast')", 'a levél-jelzés szól az asszisztensnek');
// 0.61.1: a jelzések a jobb FELSŐ sarokba kerültek – a figura mellől, mert ott a lap aljára csúsztak.
T::tartalmaz($lcs, '--sdh-felso-sav', 'a jelzések a fejléc alatt, a jobb felső sarokban állnak');
T::nem_tartalmaz($lcs, ':root:has(.sdh-ai) .sdh-level-toastok', 'nincs külön szabály a figura mellé');
T::tartalmaz($css, '.sdh-ai .sdh-ai-parbaj', 'a jelenet stílusa megvan');
T::tartalmaz($css, "@media (prefers-reduced-motion: reduce) {\n    .sdh-ai-parbaj", '„kevesebb mozgás” mellett nincs párbaj');

delete_option('sdh_muhely_asszisztens');

T::vege('t39 levél-jelzés helye, párbaj a Hiba-bogárral');
