<?php
/**
 * t61 – 0.66.0: SDH-belső 3D kísérő. Csak az includes/sdh/ mappában él (eladáskor kimarad),
 * a modell a feltöltési mappába kerül (a repóba soha), a .glb ellenőrzése, a hivatkozás
 * (CC-BY) kiolvasása, a betöltő kiírása és a three.js relatív importjai.
 */

require __DIR__ . '/_keret.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$K = 'SDH_Muhely_Sdh_3d';
$d = SDH_MUHELY_DIR . 'includes/sdh/';

T::igaz(class_exists($K), 'a 3D kísérő osztálya betöltődik');
T::igaz(is_file($d . 'class-sdh-muhely-sdh-3d.php'), 'és az includes/sdh/ mappában van (eladáskor kimarad)');
T::igaz(isset(SDH_Muhely_Sdh::modulok()['3d']), 'bejelentkezett az SDH – belső modulok közé');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-frontend.php'), "do_action('sdh_muhely_felulet_vege')", 'a műhely-felület végén ott a kiegészítők horga');

/* --- Nincs harmadik fél modellje a pluginban ------------------------------------ */

$glbk = [];
$it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SDH_MUHELY_DIR, FilesystemIterator::SKIP_DOTS));

foreach ($it as $f) {
    if (preg_match('/\.(glb|gltf|fbx|stl|obj)$/i', $f->getFilename())) {
        $glbk[] = $f->getFilename();
    }
}

T::igaz($glbk === [], 'a pluginban nincs 3D modellfájl (' . implode(', ', $glbk) . ')');

/* --- three.js: relatív importok, import map nélkül ------------------------------- */

foreach (['GLTFLoader.js', 'RoomEnvironment.js', 'BufferGeometryUtils.js'] as $v) {
    $kod = (string) file_get_contents($d . '3d/vendor/' . $v);
    T::nem_tartalmaz($kod, "from 'three'", "{$v}: nincs csupasz 'three' import");
}

T::igaz(is_file($d . '3d/vendor/three.module.min.js') && is_file($d . '3d/vendor/LICENSE-three.txt'), 'a three.js és a licence megvan');
$js = (string) file_get_contents($d . '3d/kiseret.js');
T::tartalmaz($js, "from './vendor/three.module.min.js'", 'a kísérő a helyi three.js-t tölti');
T::tartalmaz($js, "document.hidden", 'rejtett lapon nem rajzol');
T::tartalmaz($js, 'sdh-gyenge-gep', 'szoftveres rajzolásnál mozdulatlan');
T::tartalmaz($js, 'renderer.compile(scene, kamera)', 'csak kész képkockával tűnik fel');

/* --- .glb ellenőrzés ----------------------------------------------------------------- */

$glb = static function (array $json): string {
    $j = (string) wp_json_encode($json);
    $j .= str_repeat(' ', (4 - strlen($j) % 4) % 4);
    $bin = str_repeat("\0", 8);

    return 'glTF' . pack('VV', 2, 12 + 8 + strlen($j) + 8 + strlen($bin))
        . pack('V', strlen($j)) . 'JSON' . $j
        . pack('V', strlen($bin)) . "BIN\0" . $bin;
};

$tmp = wp_tempnam('t61');
file_put_contents($tmp, $glb(['asset' => ['version' => '2.0', 'extras' => ['title' => 'Próba sisak', 'author' => 'Teszt Elek', 'license' => 'CC-BY-4.0']]]));
$v = $K::glb_vizsgal($tmp);
T::igaz($v['jo'], 'érvényes .glb elfogadva');
T::egyezik('Próba sisak', $v['cim'], 'a modell címe kiolvasva');
T::tartalmaz($v['kredit'], 'Teszt Elek', 'a szerző a hivatkozásba kerül');
T::tartalmaz($v['kredit'], 'CC-BY-4.0', 'és a licenc is');

$rossz = wp_tempnam('t61r');
file_put_contents($rossz, 'nem glb fájl, csak szöveg');
T::igaz(!$K::glb_vizsgal($rossz)['jo'], 'nem .glb fájl elutasítva');

/* --- Feltöltés, több modell, szerepek, betöltő, törlés (0.68.0) ------------------------ */

delete_option($K::OPT);
$fel   = wp_get_upload_dir();
$mappa = trailingslashit($fel['basedir']) . $K::MAPPA . '/';
$uj    = $K::beallitas();
$hiba  = $K::feltolt(['name' => 'proba.glb', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], $mappa, $uj);
T::egyezik('', $hiba, 'a feltöltés sikerül');
T::igaz(strpos(realpath($mappa) ?: '', realpath(SDH_MUHELY_DIR) ?: '##') !== 0, 'a modell a feltöltési mappába kerül, nem a pluginba');
$m1 = $uj['modellek'][0];
T::igaz(preg_match('/^modell-[a-f0-9]{12}\.glb$/', $m1['fajl']) === 1, 'saját, kitalálhatatlan fájlnév');
T::tartalmaz($m1['kredit'], 'Teszt Elek', 'a hivatkozás a modellből jön');
T::egyezik('helyette', $m1['szerep'], 'az első modell Szikra helyett áll');
T::egyezik('Ez a modell már fel van töltve.', $K::feltolt(['name' => 'proba.glb', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], $mappa, $uj), 'ugyanazt kétszer nem veszi fel');

$tmp2 = wp_tempnam('t61b');
file_put_contents($tmp2, $glb(['asset' => ['version' => '2.0', 'extras' => ['title' => 'Második figura']]]));
T::egyezik('', $K::feltolt(['name' => 'masodik.glb', 'tmp_name' => $tmp2, 'size' => filesize($tmp2), 'error' => UPLOAD_ERR_OK], $mappa, $uj), 'második modell is feltölthető');
T::egyezik(2, count($uj['modellek']), 'mindkét modell megmarad');
T::egyezik('mellette', $uj['modellek'][1]['szerep'], 'a második kísérő lesz');
$m2 = $uj['modellek'][1];

$hiba2 = $K::feltolt(['name' => 'proba.txt', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], $mappa, $uj);
T::igaz($hiba2 !== '', 'nem .glb kiterjesztés elutasítva');

$uj['aktiv'] = false;
update_option($K::OPT, $uj);
wp_set_current_user(1);
ob_start();
$K::kiir();
T::egyezik('', trim((string) ob_get_clean()), 'kikapcsolva nem töltődik be semmi');

$uj['aktiv'] = true;
update_option($K::OPT, $uj);
ob_start();
$K::kiir();
$ki = (string) ob_get_clean();
T::tartalmaz($ki, 'type="module"', 'bekapcsolva modulként töltődik');
T::tartalmaz($ki, 'includes/sdh/3d/kiseret.js', 'a belső mappából');
T::tartalmaz($ki, $m1['fajl'], 'az asszisztens-modellel');
T::tartalmaz($ki, $m2['fajl'], 'és a kísérővel együtt');
T::tartalmaz($ki, '&quot;mod&quot;:&quot;helyette&quot;', 'az egyik Szikra helyett');
T::tartalmaz($ki, '&quot;mod&quot;:&quot;mellette&quot;', 'a másik mellette');
T::igaz(count($K::adat()['figurak']) === 2, 'két figura fut egyszerre');

/* --- Szikra helyett (0.66.1) --------------------------------------------------------- */

$css3 = (string) file_get_contents($d . '3d/kiseret.css');
T::tartalmaz($css3, '.sdh-ai.is-3d .sdh-ai__test', 'Szikra teste eltűnik, amint a modell kész');
T::tartalmaz($css3, '.sdh-ai.is-3d .sdh-sz-arc', 'és a rajzolt arca is');
T::nem_tartalmaz($css3, '.sdh-ai.is-3d .sdh-ai__buborek', 'a buborék megmarad');
T::igaz(preg_match('/\.sdh-ai\.is-3d \.sdh-ai__(lab|kar),[^{]*\{[^}]*visibility: hidden/s', $css3) !== 1, '3D-ben is van keze-lába (0.66.2)');
T::tartalmaz($css3, '.sdh-ai.is-3d.is-parbaj .sdh-ai__kar', 'a párbajban előjön a keze (abban a penge)');
T::tartalmaz($css3, '.sdh-ai.is-3d.is-3d-testes .sdh-ai__lab', 'egész alakos modellnél nincs rajzolt kesztyű-csizma (0.68.0)');
T::tartalmaz($js, "classList.toggle('is-3d-testes', !!B.vegtag)", 'a „saját keze-lába" beállítás szerint');
T::tartalmaz($js, 'LISTA.forEach(indit)', 'minden látható modell elindul (0.68.0)');
T::tartalmaz($js, "lista.contains('is-parbaj-viv')", 'a párbajban vív (csapások)');
T::tartalmaz($js, "lista.contains('is-setal')", 'sétánál totyog');
T::tartalmaz($js, "gyoker.classList.add('is-3d')", 'Szikra csak a kész modell után tűnik el (betöltési hibánál marad)');
T::tartalmaz($js, "getAttribute('data-allapot')", 'a modell Szikra hangulatát követi');
T::tartalmaz($js, "indexOf('is-g-')", 'és a gesztusait');

foreach (['gondolkodik', 'beszel', 'orul', 'szomoru', 'alszik', 'meglepett', 'mutat'] as $h) {
    T::tartalmaz($js, "    {$h}: {", "3D póz a(z) „{$h}” hangulathoz");
}

foreach (['porog', 'ugral', 'integet', 'bolint', 'tancol', 'razkodik', 'nyujt', 'kukucs'] as $gn) {
    T::tartalmaz($js, "case '{$gn}':", "3D mozdulat a(z) „{$gn}” gesztushoz");
}

/* --- Mentés: szerepcsere, egyediség, végtag, határok, törlés ----------------------------- */

$_POST = ['sdh_3d_jelen' => '1', 'sdh_3d_aktiv' => '1', 'sdh_3d_meret' => '500', 'sdh_3d_hely' => 'rossz', 'sdh_3d_m' => [
    $m1['fajl'] => ['szerep' => 'mellette', 'forgas' => '999', 'kredit' => 'Kézi hivatkozás'],
    $m2['fajl'] => ['szerep' => 'helyette', 'vegtag' => '1'],
]];
$K::mentes();
$b = $K::beallitas();
T::egyezik('mellette', $b['modellek'][0]['szerep'], 'a szerepek felcserélhetők (1.)');
T::egyezik('helyette', $b['modellek'][1]['szerep'], 'a szerepek felcserélhetők (2.)');
T::igaz($b['modellek'][1]['vegtag'] && !$b['modellek'][0]['vegtag'], 'a „saját keze-lába" modellenként');
T::egyezik(180, $b['modellek'][0]['forgas'], 'az alapirány ±180 fokra korlátozva');
T::egyezik('Kézi hivatkozás', $b['modellek'][0]['kredit'], 'a hivatkozás átírható');
T::egyezik(320, $b['meret'], 'a méret felső határa 320');
T::egyezik('bal', $b['hely'], 'ismeretlen hely helyett „bal"');

$_POST = ['sdh_3d_jelen' => '1', 'sdh_3d_aktiv' => '1', 'sdh_3d_m' => [
    $m1['fajl'] => ['szerep' => 'helyette'],
    $m2['fajl'] => ['szerep' => 'helyette'],
]];
$K::mentes();
$b = $K::beallitas();
T::egyezik(['helyette', 'pihen'], array_column($b['modellek'], 'szerep'), 'Szikra helyett csak egy állhat – a másik pihen');

update_option(SDH_Muhely_Sdh::OPT, '0');
ob_start();
$K::kiir();
T::egyezik('', trim((string) ob_get_clean()), 'a belső modulok kikapcsolásával a figura is eltűnik');
update_option(SDH_Muhely_Sdh::OPT, '1');

$ut = $K::modell_ut($m1['fajl']);
T::igaz($ut !== '' && is_file($ut), 'a modell megvan');
$_POST = ['sdh_3d_jelen' => '1', 'sdh_3d_aktiv' => '1', 'sdh_3d_m' => [$m1['fajl'] => ['torol' => '1']]];
$K::mentes();
$_POST = [];
$b = $K::beallitas();
T::igaz(!is_file($ut) && count($b['modellek']) === 1 && $b['modellek'][0]['fajl'] === $m2['fajl'], 'törléskor a fájl is eltűnik, a másik modell marad');

/* --- Régi (0.66) beállítás átvétele ------------------------------------------------------ */

update_option($K::OPT, ['aktiv' => true, 'mod' => 'helyette', 'fajl' => $m2['fajl'], 'nev' => 'Régi', 'kredit' => 'R', 'forgas' => 90]);
$b = $K::beallitas();
T::igaz(count($b['modellek']) === 1 && $b['modellek'][0]['szerep'] === 'helyette' && $b['modellek'][0]['forgas'] === 90, 'a régi egymodelles beállítás magától átjön');

wp_delete_file($K::modell_ut($m2['fajl']));
delete_option($K::OPT);
wp_delete_file($tmp);
wp_delete_file($tmp2);
wp_delete_file($rossz);

/* --- Súgó -------------------------------------------------------------------------- */

$sugo = (string) file_get_contents(SDH_MUHELY_DIR . 'sugo/27-sdh-belso.md');
T::tartalmaz($sugo, '## 3D figura', 'a belső fejezet leírja a 3D figurát');
T::tartalmaz($sugo, 'Szikra helyett', 'és hogy Szikra helyett is lehet');
T::tartalmaz($sugo, '**Saját keze-lába**', 'és a saját keze-lába beállítást');

T::vege('t61 SDH-belső 3D kísérő');
