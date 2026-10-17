<?php
/**
 * SDH – belső modulok listája. CSAK az SDH Szervizé: a vevői csomagból az
 * egész includes/sdh/ mappa kimarad (tools/vevo-csomag.sh), így a fő
 * betöltőben sem marad róla nyom. A sorrend számít.
 *
 * @return array<string, string> fájl (a bővítmény gyökeréhez képest) => osztály
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'includes/sdh/class-sdh-muhely-sdh.php'         => 'SDH_Muhely_Sdh',
    'includes/sdh/class-sdh-muhely-sdh-gigatel.php' => 'SDH_Muhely_Sdh_Gigatel',
    // 3D kísérő (0.66.0): saját feltöltésű .glb modell Szikra mellett (three.js, GPU).
    'includes/sdh/class-sdh-muhely-sdh-3d.php'      => 'SDH_Muhely_Sdh_3d',
];
