<?php
/**
 * t62 – 0.67.0: ha pénz kerül a kasszába, az asszisztens vigyázzba áll, bokát csattint és
 * tiszteleg, frappáns mondattal (legalább 100, véletlenszerűen). A 3D figura is.
 */

require __DIR__ . '/_keret.php';

$a    = SDH_MUHELY_DIR . 'assets/';
$app  = (string) file_get_contents($a . 'app.js');
$pt   = (string) file_get_contents($a . 'penztar.js');
$asz  = (string) file_get_contents($a . 'asszisztens.js');
$css  = (string) file_get_contents($a . 'asszisztens.css');

/* --- Az esemény: csak új pénz BE ----------------------------------------------- */

T::tartalmaz($app, 'function penzBeJelez(urlap)', 'az általános mentés jelzi a pénz beérkezését');
T::tartalmaz($app, 'penzBeJelez(urlap);', 'és minden sikeres űrlapmentésnél megnézi');
T::tartalmaz($app, "akcio === 'sdh_muhely_penztar_fizet'", 'munkalap / árukiadás / rendelés fizetése: tiszteleg');
T::tartalmaz($app, "(tipus === 'bevetel' || tipus === 'befizetes')", 'pénztári bevétel és befizetés: tiszteleg');
T::tartalmaz($app, "parseInt(id.value, 10) > 0", 'meglévő tétel szerkesztésére nem');
T::igaz(substr_count($pt, "'sdh:penz-be'") >= 3, 'a gyors felvitel és az ajánlat fizetései is jeleznek');

/* --- A tisztelgés ------------------------------------------------------------------ */

T::tartalmaz($asz, "document.addEventListener('sdh:penz-be', tiszteleg)", 'az asszisztens figyeli');
T::tartalmaz($asz, "gesztus('tiszteleg', 2800)", 'tisztelgés gesztus');
T::tartalmaz($asz, "allapot('buszke', 2800)", 'büszke tartással');

preg_match('/var TISZTELGES_SZOVEG = \[(.*?)\n    \];/s', $asz, $m);
$sorok = preg_match_all("/^\s*'[^']+',?$/m", $m[1] ?? '');
T::igaz($sorok >= 100, "legalább 100 frappáns mondat ({$sorok})");
preg_match_all("/^\s*'([^']+)',?$/m", $m[1] ?? '', $mm);
T::egyezik(count($mm[1]), count(array_unique($mm[1])), 'nincs két egyforma mondat');
T::tartalmaz($asz, 'i === tisztelgesUtolso', 'kétszer egymás után nem ugyanazt mondja');

foreach (['SDH', 'Szerviz', 'Koltai', 'Légman'] as $ceg) {
    T::nem_tartalmaz($m[1] ?? '', $ceg, "a mondatokban nincs cégadat: {$ceg}");
}

T::tartalmaz($css, '.sdh-ai.is-g-tiszteleg .sdh-ai__lab', 'vigyázzban előbújnak a lábak');
T::tartalmaz($css, '@keyframes sdh-ai-g-csatt', 'és összecsattannak (bokacsattintás)');
T::tartalmaz($css, '@keyframes sdh-ai-g-tiszteleg', 'a kéz a homlokhoz');
T::tartalmaz($css, '@keyframes sdh-ai-g-vigyazz', 'a test kihúzza magát');

/* --- 3D figura (SDH-belső) ---------------------------------------------------------- */

$d3 = SDH_MUHELY_DIR . 'includes/sdh/3d/';

if (is_dir($d3)) {
    T::tartalmaz((string) file_get_contents($d3 . 'kiseret.js'), "case 'tiszteleg':", 'a 3D figura is tiszteleg');
    T::tartalmaz((string) file_get_contents($d3 . 'kiseret.css'), '@keyframes sdh-3d-tiszteleg', 'kesztyű a sisak homlokánál');
}

/* --- Súgó ------------------------------------------------------------------------------ */

T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'sugo/10-penztar.md'), '### Miért tiszteleg az asszisztens, amikor pénzt rögzítek?', 'a pénztár súgója elmondja');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'sugo/16-asszisztens.md'), '### Miért áll vigyázzba és tiszteleg?', 'és az asszisztensé is');

T::vege('t62 tisztelgés, ha pénz kerül a kasszába');
