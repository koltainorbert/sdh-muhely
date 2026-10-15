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

/* --- Feltöltés, betöltő, törlés -------------------------------------------------------- */

delete_option($K::OPT);
$fel   = wp_get_upload_dir();
$mappa = trailingslashit($fel['basedir']) . $K::MAPPA . '/';
$uj    = $K::beallitas();
$hiba  = $K::feltolt(['name' => 'proba.glb', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], $mappa, $uj);
T::egyezik('', $hiba, 'a feltöltés sikerül');
T::igaz(strpos(realpath($mappa) ?: '', realpath(SDH_MUHELY_DIR) ?: '##') !== 0, 'a modell a feltöltési mappába kerül, nem a pluginba');
T::igaz(preg_match('/^modell-[a-f0-9]{12}\.glb$/', $uj['fajl']) === 1, 'saját, kitalálhatatlan fájlnév');
T::tartalmaz($uj['kredit'], 'Teszt Elek', 'üres hivatkozás helyére a modellből jön');

$hiba2 = $K::feltolt(['name' => 'proba.txt', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], $mappa, $uj);
T::igaz($hiba2 !== '', 'nem .glb kiterjesztés elutasítva');

update_option($K::OPT, $uj + ['aktiv' => false]);
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
T::tartalmaz($ki, $uj['fajl'], 'a feltöltött modellel');
T::tartalmaz($ki, '&quot;mod&quot;:&quot;helyette&quot;', 'alapból Szikra helyett: ő maga az asszisztens');

/* --- Szikra helyett (0.66.1) --------------------------------------------------------- */

$css3 = (string) file_get_contents($d . '3d/kiseret.css');
T::tartalmaz($css3, '.sdh-ai.is-3d .sdh-ai__test', 'Szikra teste eltűnik, amint a modell kész');
T::tartalmaz($css3, '.sdh-ai.is-3d .sdh-sz-arc', 'és a rajzolt arca is');
T::nem_tartalmaz($css3, '.sdh-ai.is-3d .sdh-ai__buborek', 'a buborék megmarad');
T::tartalmaz($js, "gyoker.classList.add('is-3d')", 'Szikra csak a kész modell után tűnik el (betöltési hibánál marad)');
T::tartalmaz($js, "getAttribute('data-allapot')", 'a modell Szikra hangulatát követi');
T::tartalmaz($js, "indexOf('is-g-')", 'és a gesztusait');

foreach (['gondolkodik', 'beszel', 'orul', 'szomoru', 'alszik', 'meglepett', 'mutat'] as $h) {
    T::tartalmaz($js, "    {$h}: {", "3D póz a(z) „{$h}” hangulathoz");
}

foreach (['porog', 'ugral', 'integet', 'bolint', 'tancol', 'razkodik', 'nyujt', 'kukucs'] as $gn) {
    T::tartalmaz($js, "case '{$gn}':", "3D mozdulat a(z) „{$gn}” gesztushoz");
}

$_POST = ['sdh_3d_jelen' => '1', 'sdh_3d_aktiv' => '1', 'sdh_3d_mod' => 'mellette'];
$K::mentes();
T::egyezik('mellette', $K::beallitas()['mod'], 'kísérőnek is beállítható');
$_POST = ['sdh_3d_jelen' => '1', 'sdh_3d_aktiv' => '1', 'sdh_3d_mod' => 'barmi'];
$K::mentes();
$_POST = [];
T::egyezik('helyette', $K::beallitas()['mod'], 'ismeretlen szerep helyett „helyette”');
update_option($K::OPT, array_merge($K::beallitas(), $uj, ['aktiv' => true]));

update_option(SDH_Muhely_Sdh::OPT, '0');
ob_start();
$K::kiir();
T::egyezik('', trim((string) ob_get_clean()), 'a belső modulok kikapcsolásával a kísérő is eltűnik');
update_option(SDH_Muhely_Sdh::OPT, '1');

$ut = $K::modell_ut();
T::igaz($ut !== '' && is_file($ut), 'a modell megvan');
$_POST = ['sdh_3d_jelen' => '1', 'sdh_3d_torol' => '1', 'sdh_3d_aktiv' => '1', 'sdh_3d_meret' => '500', 'sdh_3d_hely' => 'rossz', 'sdh_3d_forgas' => '999'];
$K::mentes();
$_POST = [];
T::igaz(!is_file($ut) && $K::beallitas()['fajl'] === '', 'törléskor a fájl is eltűnik');
T::egyezik(320, $K::beallitas()['meret'], 'a méret felső határa 320');
T::egyezik('bal', $K::beallitas()['hely'], 'ismeretlen hely helyett „bal"');
T::egyezik(180, $K::beallitas()['forgas'], 'az alapirány ±180 fokra korlátozva');

delete_option($K::OPT);
wp_delete_file($tmp);
wp_delete_file($rossz);

/* --- Súgó -------------------------------------------------------------------------- */

$sugo = (string) file_get_contents(SDH_MUHELY_DIR . 'sugo/27-sdh-belso.md');
T::tartalmaz($sugo, '## 3D figura', 'a belső fejezet leírja a 3D figurát');
T::tartalmaz($sugo, 'Szikra helyett', 'és hogy Szikra helyett is lehet');

T::vege('t61 SDH-belső 3D kísérő');
