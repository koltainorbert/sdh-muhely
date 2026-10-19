<?php
/**
 * Segéd a t240-hez (a futtató nem futtatja külön): egy teljes oldalkérés a téma sablonjaival, külön folyamatban.
 *   php _t240-oldal.php <wp-load.php> </út/?param> [nincs_plugin]
 * Kimenet: a HTML, majd egy „\n<<<T240>>>” jelölő után JSON: {hibak: [PHP-figyelmeztetések], status: HTTP-kód}.
 * `nincs_plugin`: az értékesítő bővítmény kikapcsolva (a téma védelmeinek próbája) – a téma ekkor is kötelezően aktív.
 */

if (PHP_SAPI !== 'cli' || $argc < 3) {
    exit(2);
}

$t240_load = $argv[1];
$t240_ut   = $argv[2];
$t240_np   = ($argv[3] ?? '') === 'nincs_plugin';

$GLOBALS['t240_hibak'] = [];

$t240_url = (string) parse_url($t240_ut, PHP_URL_PATH);
$_SERVER['HTTP_HOST']       = '127.0.0.1:8847';
$_SERVER['SERVER_NAME']     = '127.0.0.1';
$_SERVER['SERVER_PORT']     = '8847';
$_SERVER['REQUEST_URI']     = '/wp' . $t240_ut;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
$_SERVER['SCRIPT_NAME']     = '/wp/index.php';
$_SERVER['PHP_SELF']        = '/wp/index.php';
parse_str((string) parse_url($t240_ut, PHP_URL_QUERY), $_GET);

// Korai horgok (a WP előre feltöltött horgonyai), mielőtt bármi betöltődne.
$t240_horgok = [
    'muplugins_loaded' => [0 => [['function' => static function (): void {
        set_error_handler(static function (int $n, string $s, string $f, int $l): bool {
            // Csak a téma (és ami a sablonból fut) figyelmeztetései számítanak; a „deprecated” is.
            $GLOBALS['t240_hibak'][] = $n . ': ' . $s . ' @ ' . $f . ':' . $l;

            return true;
        });
    }, 'accepted_args' => 0]]],
    'pre_option_template'   => [99 => [['function' => static fn () => 'sdh-sales-tema', 'accepted_args' => 0]]],
    'pre_option_stylesheet' => [99 => [['function' => static fn () => 'sdh-sales-tema', 'accepted_args' => 0]]],
    'wp_redirect'           => [99 => [['function' => static fn () => false, 'accepted_args' => 0]]],
    // A tesztoldal saját címe (home) eltérhet a kérés hostjától: a kanonikus átirányítás ne nyelje el az oldalt.
    'redirect_canonical'    => [99 => [['function' => static fn () => false, 'accepted_args' => 0]]],
];

if ($t240_np) {
    $t240_horgok['option_active_plugins'] = [99 => [['function' => static fn ($p) => array_values(array_filter((array) $p, static fn ($x) => strpos((string) $x, 'sdh-sales') === false)), 'accepted_args' => 1]]];
}

$GLOBALS['wp_filter'] = $t240_horgok;

register_shutdown_function(static function (): void {
    $e = error_get_last();

    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $GLOBALS['t240_hibak'][] = 'FATAL: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line'];
    }

    echo "\n<<<T240>>>" . json_encode(['hibak' => $GLOBALS['t240_hibak'], 'status' => function_exists('http_response_code') ? http_response_code() : 0], JSON_UNESCAPED_UNICODE);
});

define('WP_USE_THEMES', true);
require dirname($t240_load) . '/wp-blog-header.php';
