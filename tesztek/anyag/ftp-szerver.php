<?php
/**
 * Tesztkiszolgáló: minimális, passzív módú FTP (a t66 teszt indítja).
 *
 *   php ftp-szerver.php PORT GYOKER FELHASZNALO JELSZO
 *
 * Több vezérlőkapcsolatot kezel (stream_select), az adatátvitel soronként szinkron.
 * Csak tesztre való – a telepítőbe nem kerül (tesztek/ export-ignore).
 */

[, $port, $gyoker, $felh, $jelszo] = $argv + [null, 2121, '/tmp', 'teszt', 'titok'];
$gyoker = rtrim($gyoker, '/');
$szerver = stream_socket_server('tcp://127.0.0.1:' . $port, $eno, $ems);

if (!$szerver) {
    fwrite(STDERR, "nem indul: $ems\n");
    exit(1);
}

$kliensek = [];

$ir = static function ($k, string $s): void {
    fwrite($k, $s . "\r\n");
};

$valos = static function (array $a, string $arg) use ($gyoker): string {
    $p = $arg !== '' && $arg[0] === '/' ? $arg : ($a['cwd'] . '/' . $arg);
    $reszek = [];

    foreach (explode('/', $p) as $r) {
        if ($r === '' || $r === '.') {
            continue;
        }
        if ($r === '..') {
            array_pop($reszek);
            continue;
        }
        $reszek[] = $r;
    }

    return '/' . implode('/', $reszek);
};

$adat = static function (array &$a) {
    if (empty($a['pasv'])) {
        return false;
    }
    $c = @stream_socket_accept($a['pasv'], 5);
    fclose($a['pasv']);
    $a['pasv'] = null;

    return $c;
};

while (true) {
    $olvas = [$szerver];

    foreach ($kliensek as $a) {
        $olvas[] = $a['k'];
    }

    $i = null;
    $j = null;

    if (@stream_select($olvas, $i, $j, 1) === false) {
        continue;
    }

    foreach ($olvas as $r) {
        if ($r === $szerver) {
            $k = stream_socket_accept($szerver);
            $kliensek[(int) $k] = ['k' => $k, 'cwd' => '', 'be' => false, 'user' => '', 'pasv' => null, 'rnfr' => ''];
            $ir($k, '220 teszt FTP');
            continue;
        }

        $kid = (int) $r;
        $sor = fgets($r);

        if ($sor === false) {
            fclose($r);
            unset($kliensek[$kid]);
            continue;
        }

        $sor = rtrim($sor, "\r\n");
        $cmd = strtoupper(strtok($sor, ' '));
        $arg = (string) substr($sor, strlen($cmd) + 1);
        $a   = &$kliensek[$kid];

        switch ($cmd) {
            case 'USER':
                $a['user'] = $arg;
                $ir($r, '331 jelszo?');
                break;
            case 'PASS':
                $a['be'] = ($a['user'] === $felh && $arg === $jelszo);
                $ir($r, $a['be'] ? '230 bent' : '530 hibas belepes');
                break;
            case 'SYST':
                $ir($r, '215 UNIX Type: L8');
                break;
            case 'TYPE':
            case 'MODE':
            case 'STRU':
                $ir($r, '200 ok');
                break;
            case 'PWD':
                $ir($r, '257 "' . ($a['cwd'] === '' ? '/' : $a['cwd']) . '"');
                break;
            case 'CWD':
                $v = $valos($a, $arg);
                if (is_dir($gyoker . $v)) {
                    $a['cwd'] = $v === '/' ? '' : $v;
                    $ir($r, '250 ok');
                } else {
                    $ir($r, '550 nincs ilyen mappa');
                }
                break;
            case 'MKD':
                $v = $valos($a, $arg);
                @mkdir($gyoker . $v, 0777, true);
                $ir($r, is_dir($gyoker . $v) ? '257 "' . $v . '" letrehozva' : '550 nem sikerult');
                break;
            case 'PASV':
                $p = stream_socket_server('tcp://127.0.0.1:0');
                $nev = stream_socket_get_name($p, false);
                $pp = (int) substr(strrchr($nev, ':'), 1);
                $a['pasv'] = $p;
                $ir($r, '227 Entering Passive Mode (127,0,0,1,' . intdiv($pp, 256) . ',' . ($pp % 256) . ')');
                break;
            case 'STOR':
                $v = $valos($a, $arg);
                $ir($r, '150 johet');
                $c = $adat($a);
                $f = fopen($gyoker . $v, 'wb');
                stream_copy_to_stream($c, $f);
                fclose($f);
                fclose($c);
                $ir($r, '226 kesz');
                break;
            case 'RETR':
                $v = $valos($a, $arg);
                if (!is_file($gyoker . $v)) {
                    $ir($r, '550 nincs ilyen fajl');
                    break;
                }
                $ir($r, '150 johet');
                $c = $adat($a);
                $f = fopen($gyoker . $v, 'rb');
                stream_copy_to_stream($f, $c);
                fclose($f);
                fclose($c);
                $ir($r, '226 kesz');
                break;
            case 'NLST':
            case 'LIST':
                $v = $valos($a, $arg === '' ? '.' : $arg);
                $ir($r, '150 lista');
                $c = $adat($a);
                foreach (is_dir($gyoker . $v) ? scandir($gyoker . $v) : [] as $n) {
                    if ($n !== '.' && $n !== '..') {
                        fwrite($c, $n . "\r\n");
                    }
                }
                fclose($c);
                $ir($r, '226 kesz');
                break;
            case 'SIZE':
                $v = $valos($a, $arg);
                $ir($r, is_file($gyoker . $v) ? '213 ' . filesize($gyoker . $v) : '550 nincs ilyen fajl');
                break;
            case 'MDTM':
                $v = $valos($a, $arg);
                $ir($r, is_file($gyoker . $v) ? '213 ' . gmdate('YmdHis', filemtime($gyoker . $v)) : '550 nincs ilyen fajl');
                break;
            case 'DELE':
                $v = $valos($a, $arg);
                $ir($r, is_file($gyoker . $v) && unlink($gyoker . $v) ? '250 torolve' : '550 nem sikerult');
                break;
            case 'RNFR':
                $a['rnfr'] = $valos($a, $arg);
                $ir($r, '350 jon a cel');
                break;
            case 'RNTO':
                $ok = is_file($gyoker . $a['rnfr']) && rename($gyoker . $a['rnfr'], $gyoker . $valos($a, $arg));
                $ir($r, $ok ? '250 atnevezve' : '550 nem sikerult');
                break;
            case 'QUIT':
                $ir($r, '221 viszlat');
                fclose($r);
                unset($kliensek[$kid]);
                break;
            default:
                $ir($r, '502 nem tamogatott');
        }

        unset($a);
    }
}
