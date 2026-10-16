<?php
/**
 * Tesztkiszolgáló: egy NAS-t utánzó, minimális WebDAV (a t66 teszt indítja).
 *
 *   SDH_DAV_GYOKER=/ut SDH_DAV_FELH=teszt SDH_DAV_JELSZO=titok php -S 127.0.0.1:PORT webdav-szerver.php
 *
 * Tud: MKCOL, PUT, GET, DELETE, MOVE, PROPFIND (Depth: 1). Alap hitelesítés kötelező.
 * Csak tesztre való – a telepítőbe nem kerül (tesztek/ export-ignore).
 */

$gyoker = rtrim((string) getenv('SDH_DAV_GYOKER'), '/');
$felh   = (string) getenv('SDH_DAV_FELH');
$jelszo = (string) getenv('SDH_DAV_JELSZO');

if (($_SERVER['PHP_AUTH_USER'] ?? '') !== $felh || ($_SERVER['PHP_AUTH_PW'] ?? '') !== $jelszo) {
    header('WWW-Authenticate: Basic realm="teszt"');
    http_response_code(401);
    exit;
}

$ut = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (strpos($ut, '..') !== false) {
    http_response_code(400);
    exit;
}

$fajl   = $gyoker . $ut;
$metodus = $_SERVER['REQUEST_METHOD'];

switch ($metodus) {
    case 'MKCOL':
        if (file_exists($fajl)) {
            http_response_code(405);
        } else {
            @mkdir($fajl, 0777, true);
            http_response_code(201);
        }
        break;

    case 'PUT':
        if (!is_dir(dirname($fajl))) {
            http_response_code(409);
            break;
        }
        $be = fopen('php://input', 'rb');
        $ki = fopen($fajl, 'wb');
        stream_copy_to_stream($be, $ki);
        fclose($ki);
        http_response_code(201);
        break;

    case 'GET':
        if (!is_file($fajl)) {
            http_response_code(404);
            break;
        }
        header('Content-Length: ' . filesize($fajl));
        readfile($fajl);
        break;

    case 'DELETE':
        if (!file_exists($fajl)) {
            http_response_code(404);
            break;
        }
        unlink($fajl);
        http_response_code(204);
        break;

    case 'MOVE':
        $cel = rawurldecode((string) parse_url((string) ($_SERVER['HTTP_DESTINATION'] ?? ''), PHP_URL_PATH));
        if (!is_file($fajl) || $cel === '') {
            http_response_code(404);
            break;
        }
        rename($fajl, $gyoker . $cel);
        http_response_code(201);
        break;

    case 'PROPFIND':
        if (!is_dir($fajl)) {
            http_response_code(404);
            break;
        }
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">';
        echo '<d:response><d:href>' . htmlspecialchars($ut) . '</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>';
        foreach (scandir($fajl) as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $f = $fajl . '/' . $n;
            echo '<d:response><d:href>' . htmlspecialchars(rtrim($ut, '/') . '/' . rawurlencode($n)) . '</d:href><d:propstat><d:prop>';
            echo is_dir($f) ? '<d:resourcetype><d:collection/></d:resourcetype>' : '<d:resourcetype/><d:getcontentlength>' . filesize($f) . '</d:getcontentlength><d:getlastmodified>' . gmdate('D, d M Y H:i:s', filemtime($f)) . ' GMT</d:getlastmodified>';
            echo '</d:prop></d:propstat></d:response>';
        }
        echo '</d:multistatus>';
        break;

    default:
        http_response_code(405);
}
