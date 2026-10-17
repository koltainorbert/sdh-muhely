<?php
/**
 * t89 – Eladhatóság: a vevői csomagba kerülő fájlokban nincs cégadat (0.96.1).
 *
 * - A vevőnek kiadott fájlok (minden, kivéve includes/sdh/, tools/, tesztek/,
 *   kozpont/, README.md és a 27-es súgófejezet) nem tartalmazzák az SDH
 *   nevét, címét, adószámát, bankszámláját, postafiókjait, tárolóját, beszállítóit.
 * - A cégre szabott kezdőértékek (kiállító, postafiókok, minta-beszállítók,
 *   frissítési tároló) csak az includes/sdh/data/ alól jönnek.
 * - A vevő-csomagoló szkript kihagyja a belső részeket.
 */

require __DIR__ . '/_keret.php';

$gyoker = rtrim(SDH_MUHELY_DIR, '/');
$tiltott = ['Haszkovó', '26234524', '10918001', 'sdhszerviz', 'smartdevicehungary', 'SMART DEVICE HUNGARY', 'koltainorbert', 'Gigatel', '237-5702', 'SDH Szerviz', 'sdh.hu'];
$kihagy  = ['includes/sdh/', 'tools/', 'tesztek/', 'kozpont/', '.git/', 'node_modules/', 'README.md', 'sugo/27-sdh-belso.md'];

$talalat = [];
$db      = 0;
$it      = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($gyoker, FilesystemIterator::SKIP_DOTS));

foreach ($it as $f) {
    $rel = ltrim(substr((string) $f->getPathname(), strlen($gyoker)), '/');

    foreach ($kihagy as $k) {
        if (strpos($rel, $k) === 0) {
            continue 2;
        }
    }

    if (!preg_match('/\.(php|js|css|md|json|txt|html|svg)$/', $rel) || $f->getSize() > 2000000) {
        continue;
    }

    $db++;
    $tart = (string) file_get_contents((string) $f->getPathname());

    foreach ($tiltott as $t) {
        if (stripos($tart, $t) !== false) {
            $talalat[] = $rel . ': ' . $t;
        }
    }
}

T::igaz($db > 150, 'a vizsgált vevői fájlok száma (' . $db . ')');
T::egyezik([], $talalat, 'a vevői fájlokban nincs cégadat');

// Kezdőértékek: az SDH-példányban az előbeállításból jönnek.
T::egyezik('SMART DEVICE HUNGARY Kft.', (string) (sdh_muhely_kezdo_adat('kiallito.json')['kiallito_nev'] ?? ''), 'kiállító az includes/sdh/data alól');
T::egyezik('sdhszerviz@gmail.com', (string) (sdh_muhely_kezdo_adat('levelezes.json')['fiokok']['f1']['email'] ?? ''), 'postafiók az includes/sdh/data alól');
T::egyezik(['Sini', 'CHS', 'Gigatel'], sdh_muhely_kezdo_adat('beszallitok.json')['nevek'] ?? [], 'minta-beszállítók az includes/sdh/data alól');
T::egyezik([], sdh_muhely_kezdo_adat('nincs-ilyen.json'), 'hiányzó előbeállítás → üres');
T::egyezik([], sdh_muhely_kezdo_adat('../../wp-config.php'), 'a fájlnévvel nem lehet kilépni a mappából');
T::igaz(!file_exists($gyoker . '/data/kiallito.json') && !file_exists($gyoker . '/data/levelezes.json'), 'a data/ alatt nincs cégre szabott fájl');

// A csomagoló szkript kihagyja a belső részeket.
$sh = (string) file_get_contents($gyoker . '/tools/vevo-csomag.sh');
foreach (["'sdh-muhely/includes/sdh/'", "'sdh-muhely/tools/'", "'sdh-muhely/README.md'", "'sdh-muhely/sugo/27-sdh-belso.md'"] as $r) {
    T::tartalmaz($sh, $r, 'a vevő-csomagból kimarad: ' . $r);
}

T::vege('t89 eladhatóság: a vevői fájlokban nincs cégadat, a kezdőértékek az SDH-előbeállításból jönnek');
