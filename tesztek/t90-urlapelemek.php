<?php
/**
 * t90 – Egységes űrlapelemek (0.97.0, projektszabály):
 *
 * - a kapcsoló mellett nincs „Aktív / Inaktív” felirat (sem szkript, sem stílus nem rajzolja),
 * - a kapcsolónak van saját margója (nem ér hozzá a szomszédjához),
 * - minden szövegmező, legördülő, szövegdoboz és gomb lekerekített (stílus nélküli elem is),
 * - a „Szögletes” forma sem éles sarkú,
 * - a menüben csak megnyitható pont áll: a Modulok csak a fejlesztőnek,
 * - a fejlécben minden oldalon ott a Súgó gomb.
 *
 * A böngészős mérés (összeérés, kerekítés a valódi oldalakon): tesztek/e2e/urlapelemek.py.
 */

require __DIR__ . '/_keret.php';

$d   = SDH_MUHELY_DIR;
$css = (string) file_get_contents($d . 'assets/kapcsolo.css');
$fe  = (string) file_get_contents($d . 'includes/class-sdh-muhely-frontend.php');
$au  = (string) file_get_contents($d . 'includes/class-sdh-muhely-admin-ui.php');

// 1. Nincs „Aktív / Inaktív” felirat a kapcsolók mellett.
T::igaz(!file_exists($d . 'assets/kapcsolo.js'), 'a feliratot rajzoló szkript nincs meg');
T::nem_tartalmaz($fe . $au, 'kapcsolo.js', 'és sehol nem töltődik be');
T::nem_tartalmaz($css, 'kapcs-allapot', 'a felirat stílusa sincs');
$js = '';
foreach (glob($d . 'assets/*.js') ?: [] as $f) {
    $js .= (string) file_get_contents($f);
}
T::igaz(preg_match("/textContent\\s*=\\s*[^;]*'Aktív'\\s*:\\s*'Inaktív'/u", $js) === 0, 'egyetlen szkript sem ír Aktív/Inaktív feliratot kapcsoló mellé');

// 2. Levegő a kapcsoló körül.
T::igaz(preg_match('/margin:\s*calc\(6px \* var\(--sdh-n\)\) calc\(8px \* var\(--sdh-n\)\) calc\(6px \* var\(--sdh-n\)\) 0;/', $css) === 1, 'a kapcsoló margója: 6 px fent-lent, 8 px jobbra');
T::tartalmaz($css, '+ input[type="checkbox"]', 'szöveg után álló kapcsolónál balra is van hely');

// 3. Lekerekítés.
T::tartalmaz($css, ':where(.sdh-app, .sdh-modal) :where(input:not(', 'stílus nélküli mező is a rendszer mezője (0 specificitás)');
T::igaz(substr_count($css, 'border-radius: var(--sdh-mezo-r)') >= 2, 'mezők: --sdh-mezo-r');
T::tartalmaz($css, 'border-radius: var(--sdh-gomb-r)', 'gombok: --sdh-gomb-r');
T::tartalmaz($css, 'min(var(--sdh-mezo-r), calc(15px * var(--sdh-n)))', 'szövegdoboz: legfeljebb 15 px ív');
T::tartalmaz($css, 'select[multiple]', 'többsoros lista: legfeljebb 15 px ív (nem pill)');
$forma = (string) file_get_contents($d . 'includes/class-sdh-muhely-arculat.php');
T::igaz(preg_match("/'szogletes'\s*=>\s*\['calc\((\d+)px/", $forma, $m) === 1 && (int) $m[1] >= 4, 'a legkisebb gombforma is legalább 4 px-es ív');

// 4. Menü: a Modulok csak a fejlesztőnek.
T::tartalmaz($fe, "class_exists('SDH_Muhely_Modul_Kapcsolo') && SDH_Muhely_Admin_UI::fejleszto_e()", 'a Modulok menüpont fejlesztői feltételhez kötött');
T::tartalmaz($fe, "!empty(\$modul['csak_fejleszto']) && !SDH_Muhely_Admin_UI::fejleszto_e()", 'a csak fejlesztői modul nem kerül más admin menüjébe');
T::igaz(!empty(SDH_Muhely_Modulok::egy(SDH_Muhely_Modul_Kapcsolo::KULCS)['csak_fejleszto']), 'a Modulok oldal valóban csak fejlesztői');

// 5. Súgó a fejlécben, minden oldalon.
T::tartalmaz($fe, 'sdh-fej__sugo-felirat">Súgó</span>', 'feliratos Súgó gomb a fejlécben');
T::nem_tartalmaz($fe, "class_exists('SDH_Muhely_Sugo') && \$aktiv_kulcs !== SDH_Muhely_Sugo::KULCS", 'a Súgó oldalon sem tűnik el');

T::vege('t90 egységes űrlapelemek: felirat nélküli kapcsoló, levegő, lekerekítés, menü, Súgó a fejlécben');
