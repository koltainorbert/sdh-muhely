<?php
/**
 * t326 – A portal-<v>.zip tartalmazza a licencközpontot (egy telepítés), semleges nevekkel:
 * `portal/licenc/kozpont.php` (bővítmény-fejléc nélkül), `licenc/includes/class-k*.php`, zárt includes, a kiszolgált
 * fájlokban (css / js) nincs rendszer- / márkanév, a fájlnevekben nincs „sdh”, minden PHP-fájl hibátlan.
 */
require __DIR__ . '/_t32-seged.php';

$gyoker = dirname(__DIR__);
$ki     = wp_tempnam('t326') . '-d';
wp_mkdir_p($ki);

$kimenet = [];
$kod     = 0;
exec('sh ' . escapeshellarg($gyoker . '/eszkozok/csomagol.sh') . ' ' . escapeshellarg($ki) . ' 2>&1', $kimenet, $kod);
T::egyezik(0, $kod, 'csomagol.sh: OK (' . implode(' | ', array_slice($kimenet, -2)) . ')');

$zip = glob($ki . '/portal-*.zip')[0] ?? '';
T::igaz($zip !== '' && is_file($zip), 'portal zip elkészült');

$z = new ZipArchive();
$z->open($zip);
$nevek = [];

for ($i = 0; $i < $z->numFiles; $i++) {
    $nevek[] = (string) $z->getNameIndex($i);
}

foreach (['portal/licenc/kozpont.php', 'portal/licenc/includes/class-k.php', 'portal/licenc/includes/class-k-api.php', 'portal/licenc/includes/class-k-app.php', 'portal/licenc/includes/class-k-kiszolgalas.php', 'portal/licenc/includes/.htaccess', 'portal/licenc/assets/kozpont.js', 'portal/licenc/assets/kozpont.css', 'portal/assets/admin-licenc.js', 'portal/includes/class-p-licenc.php', 'portal/data/email/licenc_kulcs.json'] as $f) {
    T::igaz(in_array($f, $nevek, true), 'a csomagban: ' . $f);
}

T::egyezik([], array_values(array_filter($nevek, static fn ($n) => stripos($n, 'sdh') !== false)), 'nincs „sdh” a fájlnevekben');

$fo = (string) $z->getFromName('portal/licenc/kozpont.php');
T::igaz(preg_match('/^\s*\*\s*Plugin Name:/m', $fo) !== 1, 'a beágyazott fő fájl nem bővítmény-fejléces');
T::tartalmaz($fo, "'class-k'", 'a fő fájl a semleges előtagot ismeri');
T::tartalmaz((string) $z->getFromName('portal/licenc/includes/.htaccess'), 'Require all denied', 'a licencközpont includes mappája zárva');

$tilt = '~wordpress|/wp-(content|includes|admin|json)/|wp-login\.php|admin-ajax\.php|\bsdh\b|sdh[-_]|[-_]sdh|api\.w\.org~i';
foreach ($nevek as $n) {
    if (preg_match('~^portal/(licenc/.*|assets/admin-licenc)\.(css|js|json|svg|txt|html)$~', $n) === 1) {
        T::igaz(preg_match($tilt, (string) $z->getFromName($n)) !== 1, 'kiszolgált fájl tiszta: ' . $n);
    }
}

// Minden beágyazott PHP-fájl hibátlan
$hibas = [];
foreach ($nevek as $n) {
    if (substr($n, -4) === '.php' && strpos($n, 'portal/licenc/') === 0) {
        $tmp = wp_tempnam('t326.php');
        file_put_contents($tmp, (string) $z->getFromName($n));
        $o = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $o, $c);
        if ($c !== 0) {
            $hibas[] = $n;
        }
        @unlink($tmp);
    }
}
T::egyezik([], $hibas, 'a beágyazott PHP-fájlok szintaktikailag hibátlanok');
$z->close();

// Takarítás
array_map('unlink', glob($ki . '/*.zip') ?: []);
@rmdir($ki);

T::vege('t326 licencközpont a Portál csomagjában');
