<?php
/**
 * t63 – 0.68.2: a számla ablakában „Bevizsgálási díj…" gomb (a díjlistából, a készülék nevével),
 * és a Termék… / Szolgáltatás… / Kézi tétel gombok megvannak.
 */

require __DIR__ . '/_keret.php';

$sz  = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-szamla.php');
$app = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.js');

foreach (['termek' => 'Termék…', 'szolgaltatas' => 'Szolgáltatás…', 'bevizsgalas' => 'Bevizsgálási díj…', 'kezi' => 'Kézi tétel'] as $f => $felirat) {
    T::tartalmaz($sz, 'data-sdh-szamla-uj="' . $f . '"', "a számla ablakában ott a „{$felirat}” gomb");
}

T::tartalmaz($sz, 'data-sdh-dijnevek=', 'a gomb tudja eszközönként a díj nevét');
T::tartalmaz($app, "if (fajta === 'bevizsgalas')", 'az app.js kezeli');
T::tartalmaz($app, 'return t.dij;', 'csak a bevizsgálási díjak közül választ');
T::tartalmaz($app, "input[name=\"eszkoz\"]:checked", 'több eszköznél az ablakban választott eszköz nevével');

// A név a munkalap eszközéből, a beállított mintával.
$u  = t_ugyfel('Díjgomb Dénes');
$e  = t_eszkoz($u, 'Samsung', 'SM-A175B');
$id = t_munkalap($u, [$e]);
$m  = t_lap($id);
$nev = SDH_Muhely_Szamla::bevizsgalas_nev($m, 'bevizsgalas_sablon', null, '{dij}', 1);
T::tartalmaz($nev, 'SM-A175B', 'a díj neve tartalmazza a készüléket (' . $nev . ')');
T::tartalmaz($nev, 'bevizsgálási díj', 'és hogy bevizsgálási díj');

T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'sugo/11-szamlazas.md'), '**Bevizsgálási díj…**', 'a súgó leírja');

T::vege('t63 bevizsgálási díj gomb a számlán');
