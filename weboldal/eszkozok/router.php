<?php
header_remove('X-Powered-By');
/**
 * Fejlesztői útválasztó a `php -S`-hez: a rejtés útvonal-álneveit (static/m, static/t, media, static, lib) a valódi
 * mappákra képezi, a valódi rendszer-útvonalakat közvetlenül 404-gyel válaszolja (mint élesben a webszerver-szabály),
 * minden mást a WordPress index.php-ja kap.
 *   PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8765 -t /home/claude/env/<n> /home/claude/sdh-muhely/weboldal/eszkozok/router.php
 *   (a WORKERS kell: az álnév-próba a szerver saját magának küld kérést – egy szálon ez 8 mp-es várakozás és „sikertelen”.)
 * A WordPress a docroot `wp/` almappájában van (siteurl/home: http://127.0.0.1:<port>/wp).
 */
$_rt_gyoker = $_SERVER['DOCUMENT_ROOT'];
$_rt_ut     = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$_rt_wp     = '/wp';

$_rt_alias = [
    $_rt_wp . '/static/m/' => $_rt_wp . '/wp-content/plugins/',
    $_rt_wp . '/static/t/' => $_rt_wp . '/wp-content/themes/',
    $_rt_wp . '/media/'    => $_rt_wp . '/wp-content/uploads/',
    $_rt_wp . '/static/'   => $_rt_wp . '/wp-content/',
    $_rt_wp . '/lib/'      => $_rt_wp . '/wp-includes/',
];

// A valódi útvonalak közvetlenül: 404 (élesben a webszerver-szabály teszi).
if (preg_match('~^' . preg_quote($_rt_wp, '~') . '/(wp-content|wp-includes)/~', $_rt_ut) || preg_match('~/(readme\.html|license\.txt|wp-config-sample\.php)$~', $_rt_ut)) {
    http_response_code(404);
    echo 'Nem található';
    return true;
}

foreach ($_rt_alias as $_rt_a => $_rt_valodi) {
    if (strpos($_rt_ut, $_rt_a) === 0) {
        $_rt_fajl = $_rt_gyoker . $_rt_valodi . substr($_rt_ut, strlen($_rt_a));

        if (is_file($_rt_fajl) && !preg_match('/\.php$/', $_rt_fajl)) {
            $_rt_tipus = [
                'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json', 'svg' => 'image/svg+xml',
                'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif',
                'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ico' => 'image/x-icon', 'gz' => 'application/gzip', 'txt' => 'text/plain',
                'pdf' => 'application/pdf', 'xml' => 'application/xml', 'map' => 'application/json',
            ][strtolower(pathinfo($_rt_fajl, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
            header('Content-Type: ' . $_rt_tipus);
            header('Cache-Control: public, max-age=3600');
            readfile($_rt_fajl);
            return true;
        }

        http_response_code(404);
        echo 'Nem található';
        return true;
    }
}

$_rt_fajl = $_rt_gyoker . $_rt_ut;

if ($_rt_ut !== '/' && is_file($_rt_fajl) && !preg_match('/\.php$/', $_rt_fajl)) {
    return false;
}

if (is_dir($_rt_fajl) && is_file(rtrim($_rt_fajl, '/') . '/index.php')) {
    $_rt_ut   = rtrim($_rt_ut, '/') . '/index.php';
    $_rt_fajl = rtrim($_rt_fajl, '/') . '/index.php';
}

if (preg_match('~\.php$~', $_rt_ut) && is_file($_rt_fajl)) {
    $_SERVER['SCRIPT_NAME'] = $_rt_ut;
    $_SERVER['SCRIPT_FILENAME'] = $_rt_fajl;
    chdir(dirname($_rt_fajl));
    require $_rt_fajl;
    return true;
}

$_SERVER['SCRIPT_NAME'] = $_rt_wp . '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_rt_gyoker . $_rt_wp . '/index.php';
chdir($_rt_gyoker . $_rt_wp);
require $_rt_gyoker . $_rt_wp . '/index.php';
return true;
