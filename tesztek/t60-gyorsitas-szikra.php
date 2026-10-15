<?php
/**
 * t60 – 0.65.0: hardveres gyorsítás (észlelés, figyelmeztetés, gyenge gép mód, házirend-fájlok),
 * a Szikra sima (GPU-s) mozgása, az új saját szereplők és jelenetek.
 */

require __DIR__ . '/_keret.php';

$assets = SDH_MUHELY_DIR . 'assets/';
$fe     = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-frontend.php');
$ui     = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-admin-ui.php');
$gjs    = (string) file_get_contents($assets . 'gyorsitas.js');
$gcss   = (string) file_get_contents($assets . 'gyorsitas.css');
$ajs    = (string) file_get_contents($assets . 'asszisztens.css');
$sjs    = (string) file_get_contents($assets . 'szikra-elet.js');
$scss   = (string) file_get_contents($assets . 'szikra-elet.css');

/* --- Hardveres gyorsítás ------------------------------------------------------ */

T::tartalmaz($fe, 'assets/gyorsitas.js', 'a műhely-felület betölti a gyorsitas.js-t');
T::tartalmaz($fe, 'assets/gyorsitas.css', 'és a gyorsitas.css-t');
T::tartalmaz($ui, 'assets/gyorsitas.js', 'a wp-admin oldalak is betöltik a gyorsitas.js-t');
T::tartalmaz($ui, 'assets/gyorsitas.css', 'és a gyorsitas.css-t');
T::tartalmaz($gjs, 'failIfMajorPerformanceCaveat', 'WebGL-próba a szoftveres megjelenítés ellen');
T::tartalmaz($gjs, 'swiftshader|llvmpipe', 'a szoftveres megjelenítőket felismeri');
T::tartalmaz($gjs, 'sdh-gyenge-gep', 'szoftveres rajzolásnál gyenge gép módba kapcsol');
T::tartalmaz($gjs, 'sdh-gpu-elrejt', 'a figyelmeztetés 7 napra elrejthető');
T::tartalmaz($gcss, 'html.sdh-gyenge-gep', 'a gyenge gép mód stílusa megvan');

ob_start();
SDH_Muhely_Csomag::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::tartalmaz($doboz, 'data-sdh-gpu-allapot', 'a Sebesség lapon ott az állapotsor');
T::tartalmaz($doboz, 'tools/hardveres-gyorsitas/', 'és a hivatkozás a kényszerítő fájlokra');

$reg = (string) file_get_contents(SDH_MUHELY_DIR . 'tools/hardveres-gyorsitas/hardveres-gyorsitas-be.reg');
T::tartalmaz($reg, 'Policies\\Google\\Chrome', 'a házirend-fájl a Chrome-ot beállítja');
T::tartalmaz($reg, 'Policies\\Microsoft\\Edge', 'és az Edge-et');
T::tartalmaz($reg, '"HardwareAccelerationModeEnabled"=dword:00000001', 'bekapcsolt értékkel');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'tools/hardveres-gyorsitas/hardveres-gyorsitas-vissza.reg'), '"HardwareAccelerationModeEnabled"=-', 'a visszavonó fájl törli az értéket');

/* --- Szikra sima mozgása ------------------------------------------------------ */

T::nem_tartalmaz($ajs, 'steps(4)', 'nincs több lépcsős (14 kép/mp) animáció az asszisztens stíluslapján');
T::nem_tartalmaz($scss, 'steps(4)', 'és a Szikra stíluslapján sem');
T::igaz(preg_match('/@keyframes sdh-ai-amoba \{[^}]*scale:[^}]*rotate:/s', $ajs) === 1, 'az amőba-hullámzás scale / rotate tulajdonságokon fut');
preg_match('/@keyframes sdh-ai-amoba \{(.*?)\n\}/s', $ajs, $kk);
T::nem_tartalmaz((string) ($kk[1] ?? 'x'), 'border-radius', 'a kulcskockákban nincs border-radius (nem rajzoltat újra)');
T::tartalmaz($ajs, 'will-change: transform, scale, rotate', 'a test saját GPU-rétegen fut');

/* --- Új szereplők és jelenetek ------------------------------------------------ */

foreach (['bence', 'lilla', 'gergo', 'zoli', 'bip', 'tanyer'] as $szereplo) {
    T::tartalmaz($sjs, "        {$szereplo}: [", "a(z) {$szereplo} rajza megvan");
}

foreach (['karton', 'urcsata'] as $jelenet) {
    T::tartalmaz($sjs, "        {$jelenet}: function (c) {", "a(z) {$jelenet} jelenet megvan");
    T::tartalmaz($sjs, "['{$jelenet}', 3]", "a(z) {$jelenet} bekerült az alkalmi jelenetek közé");
}

T::tartalmaz($sjs, "'bollywood', 'karton', 'urcsata'", 'a kalandvágyat is csillapítják');
T::tartalmaz($scss, 'sdh-sz-karton-jaras', 'a kartonfigurák járása animált');

// Nem ismert alakok neve / szövege nem szerepel (jogtisztaság).
foreach (['Stan', 'Kyle', 'Cartman', 'Kenny', 'South Park', 'Jedi', 'Yoda', 'Vader', 'Skywalker', 'Stormtrooper', 'R2-D2', 'lightsaber', 'Mandalor'] as $tilos) {
    T::nem_tartalmaz($sjs, $tilos, "nincs benne ismert név: {$tilos}");
}

/* --- Súgó ---------------------------------------------------------------------- */

$sugo = (string) file_get_contents(SDH_MUHELY_DIR . 'sugo/16-asszisztens.md');
T::tartalmaz($sugo, 'Kisvárosi srácok', 'a súgó leírja a Kisvárosi srácok jelenetet');
T::tartalmaz($sugo, 'Űrcsata', 'és az Űrcsatát');
foreach (['Bence', 'Lilla', 'Gergő', 'Csillagőr Zoli', 'Bip', 'plazmavilla', 'tányér-cirkáló'] as $nev) {
    T::tartalmaz($sugo, $nev, "a súgó (és így az AI) ismeri: {$nev}");
}
T::tartalmaz($sugo, '### Kik azok a lapos, kartonszerű gyerekek', 'GYIK: a kartonfigurás gyerekek');
T::tartalmaz($sugo, '### Ki az a narancs páncélos őr', 'GYIK: Zoli és Bip');
T::tartalmaz($sugo, 'kulcsszavak: ', 'a kulcsszó-sor megvan');
T::igaz(strpos((string) preg_replace('/^(?!kulcsszavak:).*$/m', '', $sugo), 'csillagőr zoli') !== false, 'a kulcsszavak között ott van: csillagőr zoli');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'sugo/20-hibaelharitas.md'), 'Hardveres gyorsítás (a böngészőben)', 'a hibaelhárítás fejezet tárgyalja a hardveres gyorsítást');

$ossz = trim((string) shell_exec('node --check ' . escapeshellarg($assets . 'gyorsitas.js') . ' 2>&1 && node --check ' . escapeshellarg($assets . 'szikra-elet.js') . ' 2>&1'));
T::igaz($ossz === '', 'mindkét szkript szintaktikailag hibátlan' . ($ossz !== '' ? ': ' . $ossz : ''));

/* --- Új választható figurák (0.65.2) ------------------------------------------ */

$figurak = SDH_Muhely_Asszisztens::figurak();
T::igaz(count($figurak) >= 14, 'legalább tizennégy választható figura (' . count($figurak) . ')');

foreach (['karton' => 'Kartonka', 'bojtos' => 'Bojtika', 'droid' => 'Bip', 'orszem' => 'Zoli'] as $kulcs => $nev) {
    T::igaz(isset($figurak[$kulcs]) && $figurak[$kulcs]['nev'] === $nev, "választható figura: {$nev}");
    T::tartalmaz($ajs, '.sdh-ai[data-figura="' . $kulcs . '"]', "{$nev} rajza megvan a stíluslapon");
    T::tartalmaz($sugo, '| ' . $nev . ' |', "a súgó figuratáblázatában ott van: {$nev}");
}

T::tartalmaz($sugo, '### Melyik figura?', 'a súgóban van „Melyik figura?" szakasz');
T::tartalmaz($sugo, '### Hogyan cserélem le a figurát?', 'és GYIK-válasz a cseréről');

T::vege('t60 hardveres gyorsítás, sima Szikra, új szereplők');
