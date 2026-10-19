<?php
/**
 * Semleges hibaoldal fatális hibára (a tartalommappába másolandó: wp-content/php-error.php).
 * Enélkül a rendszer saját, a platformot eláruló hibaoldala jelenne meg.
 */
if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header_remove('X-Powered-By');
}
?><!doctype html>
<html lang="hu">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Átmeneti hiba</title>
<body style="font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:15vh auto;padding:0 1rem;color:#1b1f2a;background:#fff">
<h1 style="font-size:1.6rem">Átmeneti hiba</h1>
<p>Az oldal most nem érhető el. Kérjük, próbáld újra néhány perc múlva.</p>
<p><a href="/">Vissza a kezdőlapra</a></p>
</body>
</html>
