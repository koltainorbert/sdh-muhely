<?php
/**
 * Hamis SMS-átjáró a teszteknek (sms-gate.app helyi szerver API-ja szerint).
 *
 *   php -S 127.0.0.1:8091 tesztek/sms-mock.php
 *
 * Hitelesítés: Basic tesztfelh / tesztjelszo. A hívások naplója és az állapot a
 * SDH_SMS_MOCK_FAJL (alap: /tmp/sdh-sms-mock.json) fájlban él. Vezérlő végpontok:
 *   POST /_reset                       – minden törlése
 *   POST /_hiba?n=2&kod=500            – a következő n küldés hibát ad
 *   POST /_lassu?n=1&mp=4              – a következő n küldés mp másodpercig vár (de elfogadja)
 *   POST /_allapot?id=m1&state=Sent    – egy üzenet állapotának átírása
 *   GET  /_lista                       – a fogadott üzenetek
 * A 0000-ra végződő számra a küldés 400-at ad (érvénytelen szám).
 */

$fajl = getenv('SDH_SMS_MOCK_FAJL') ?: '/tmp/sdh-sms-mock.json';
$all  = is_file($fajl) ? (json_decode((string) file_get_contents($fajl), true) ?: []) : [];
$all += ['uzenetek' => [], 'hiba_db' => 0, 'hiba_kod' => 500, 'sorszam' => 0, 'lassu_db' => 0, 'lassu_mp' => 0];

$mentes = static function () use (&$all, $fajl): void {
    file_put_contents($fajl, json_encode($all));
};

$ut     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$metod  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$kuld   = static function (int $kod, $adat): void {
    http_response_code($kod);
    header('Content-Type: application/json');
    echo json_encode($adat);
    exit;
};

if (str_starts_with($ut, '/_')) {
    if ($ut === '/_reset') {
        $all = ['uzenetek' => [], 'hiba_db' => 0, 'hiba_kod' => 500, 'sorszam' => 0, 'lassu_db' => 0, 'lassu_mp' => 0];
        $mentes();
        $kuld(200, ['ok' => true]);
    }

    if ($ut === '/_hiba') {
        $all['hiba_db']  = (int) ($_GET['n'] ?? 1);
        $all['hiba_kod'] = (int) ($_GET['kod'] ?? 500);
        $mentes();
        $kuld(200, ['ok' => true]);
    }

    if ($ut === '/_lassu') {
        $all['lassu_db'] = (int) ($_GET['n'] ?? 1);
        $all['lassu_mp'] = (int) ($_GET['mp'] ?? 4);
        $mentes();
        $kuld(200, ['ok' => true]);
    }

    if ($ut === '/_allapot') {
        $id = (string) ($_GET['id'] ?? '');

        if (isset($all['uzenetek'][$id])) {
            $all['uzenetek'][$id]['state'] = (string) ($_GET['state'] ?? 'Sent');
            $mentes();
        }

        $kuld(200, ['ok' => true]);
    }

    if ($ut === '/_lista') {
        $kuld(200, array_values($all['uzenetek']));
    }

    $kuld(404, ['message' => 'nincs']);
}

// Hitelesítés
$fel = $_SERVER['PHP_AUTH_USER'] ?? '';
$jel = $_SERVER['PHP_AUTH_PW'] ?? '';

if ($fel === '' && isset($_SERVER['HTTP_AUTHORIZATION']) && str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Basic ')) {
    [$fel, $jel] = array_pad(explode(':', (string) base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)), 2), 2, '');
}

if ($fel !== 'tesztfelh' || $jel !== 'tesztjelszo') {
    $kuld(401, ['message' => 'Unauthorized']);
}

if ($ut === '/health' && $metod === 'GET') {
    $kuld(200, ['status' => 'pass', 'version' => 'mock']);
}

if ($ut === '/messages' && $metod === 'POST') {
    if ($all['hiba_db'] > 0) {
        $all['hiba_db']--;
        $mentes();
        $kuld((int) $all['hiba_kod'], ['message' => 'mock hiba']);
    }

    $t = json_decode((string) file_get_contents('php://input'), true);
    $szam = (string) ($t['phoneNumbers'][0] ?? '');

    if (!is_array($t) || !isset($t['textMessage']['text']) || $szam === '') {
        $kuld(400, ['message' => 'hibás kérés']);
    }

    if (str_ends_with($szam, '0000')) {
        $kuld(400, ['message' => 'érvénytelen szám']);
    }

    if ($all['lassu_db'] > 0) {
        $all['lassu_db']--;
        $mp = (int) $all['lassu_mp'];
        $id = 'm' . ($all['sorszam'] + 1);
        $all['sorszam']++;
        $all['uzenetek'][$id] = ['id' => $id, 'state' => 'Pending', 'text' => $t['textMessage']['text'], 'szam' => $szam, 'recipients' => [['phoneNumber' => $szam, 'state' => 'Pending']]];
        $mentes();
        sleep($mp);
        $kuld(202, ['id' => $id, 'state' => 'Pending']);
    }

    $id = 'm' . (++$all['sorszam']);
    $all['uzenetek'][$id] = [
        'id' => $id, 'deviceId' => 'dev1', 'state' => 'Pending',
        'text' => $t['textMessage']['text'], 'szam' => $szam,
        'recipients' => [['phoneNumber' => $szam, 'state' => 'Pending']],
    ];
    $mentes();
    $kuld(202, ['id' => $id, 'deviceId' => 'dev1', 'state' => 'Pending', 'recipients' => [['phoneNumber' => $szam, 'state' => 'Pending']]]);
}

if (preg_match('#^/messages/([^/]+)$#', $ut, $m) && $metod === 'GET') {
    $id = urldecode($m[1]);

    if (!isset($all['uzenetek'][$id])) {
        $kuld(404, ['message' => 'nincs ilyen üzenet']);
    }

    $u = $all['uzenetek'][$id];
    $u['recipients'][0]['state'] = $u['state'];
    $kuld(200, $u);
}

$kuld(404, ['message' => 'nincs ilyen végpont']);
