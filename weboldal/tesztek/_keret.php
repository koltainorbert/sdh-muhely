<?php
/**
 * SDH Értékesítés – tesztkeret (a Műhely tesztkeretének mintájára).
 * Csak tesztoldalon: SDH_TESZT_ADATBAZIS=1 SDH_WP_LOAD=/út/wp-load.php php weboldal/tesztek/futtat.php [szűrő]
 * A hálózat hamis: minden külső HTTP-kérés a `$GLOBALS['sdh_t_http']` kezelőin megy át (t_http()), a többi hibát ad.
 */

if (PHP_SAPI !== 'cli') {
    exit("Csak parancssorból futtatható.\n");
}

if (getenv('SDH_TESZT_ADATBAZIS') !== '1') {
    fwrite(STDERR, "A tesztek adatot írnak. Csak tesztoldalon: SDH_TESZT_ADATBAZIS=1\n");
    exit(2);
}

$sdh_wp_load = getenv('SDH_WP_LOAD') ?: '/home/claude/env/site/wp/wp-load.php';

if (!is_file($sdh_wp_load)) {
    fwrite(STDERR, "Nem találom a wp-load.php fájlt: SDH_WP_LOAD=/út/wp-load.php\n");
    exit(2);
}

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8765';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/wp/';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.7';

/** @var array<int, callable> hamis HTTP-kezelők: fn(string $url, array $args): ?array (null = nem az enyém) */
$GLOBALS['sdh_t_http']   = [];
/** @var array<int, array{url: string, args: array}> minden kimenő kérés */
$GLOBALS['sdh_t_keresek'] = [];
/** @var array<int, array> a „kiküldött” levelek (wp_mail nem megy ki) */
$GLOBALS['sdh_t_levelek'] = [];

require $sdh_wp_load;

add_filter('pre_http_request', static function ($pre, $args, $url) {
    $GLOBALS['sdh_t_keresek'][] = ['url' => (string) $url, 'args' => (array) $args];

    foreach ($GLOBALS['sdh_t_http'] as $k) {
        $v = $k((string) $url, (array) $args);

        if (is_array($v)) {
            return $v + ['headers' => [], 'cookies' => [], 'filename' => null];
        }
    }

    return new WP_Error('sdh_teszt_halozat', 'Teszt: a hálózat tiltva (' . $url . ')');
}, 1, 3);

add_filter('pre_wp_mail', static function ($null, $atts) {
    $GLOBALS['sdh_t_levelek'][] = $atts;

    return true;
}, 1, 2);

add_filter('sdh_muhely_halozat_kenyszer', static fn (): string => 'online');

if (!class_exists('SDH_Sales')) {
    fwrite(STDERR, "Az SDH Értékesítés bővítmény nincs bekapcsolva a tesztoldalon.\n");
    exit(2);
}

$sdh_admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($sdh_admin[0] ?? 1));
SDH_Sales_Schema::sema(true);

final class T
{
    public static int $ok = 0;
    /** @var array<int, string> */
    public static array $hibak = [];

    public static function igaz($feltetel, string $nev): void
    {
        if ($feltetel) {
            self::$ok++;
            return;
        }
        self::$hibak[] = $nev;
    }

    public static function egyezik($vart, $kapott, string $nev): void
    {
        $jo = (is_float($vart) || is_float($kapott)) ? abs((float) $vart - (float) $kapott) < 0.005 : $vart === $kapott;
        if ($jo) {
            self::$ok++;
            return;
        }
        self::$hibak[] = $nev . ' – várt: ' . var_export($vart, true) . ', kapott: ' . var_export($kapott, true);
    }

    public static function tartalmaz(string $szoveg, string $resz, string $nev): void
    {
        self::igaz(strpos($szoveg, $resz) !== false, $nev . ' – nincs benne: „' . $resz . '”');
    }

    public static function nem_tartalmaz(string $szoveg, string $resz, string $nev): void
    {
        self::igaz(strpos($szoveg, $resz) === false, $nev . ' – benne van: „' . $resz . '”');
    }

    public static function vege(string $cim): void
    {
        $ossz = self::$ok + count(self::$hibak);
        echo $cim . ': ' . self::$ok . '/' . $ossz . (self::$hibak === [] ? ' OK' : ' HIBA') . "\n";
        foreach (array_slice(self::$hibak, 0, 30) as $h) {
            echo '   ✗ ' . $h . "\n";
        }
        exit(self::$hibak === [] ? 0 : 1);
    }
}

/** Hamis HTTP-kezelő felvétele. @param callable $k fn(string $url, array $args): ?array */
function t_http(callable $k): void
{
    $GLOBALS['sdh_t_http'][] = $k;
}

/** Hamis HTTP-válasz. */
function t_valasz(int $kod, string $test, array $fejlec = []): array
{
    return ['response' => ['code' => $kod, 'message' => 'OK'], 'body' => $test, 'headers' => $fejlec];
}

/** Belső REST-hívás (a valódi útválasztón át, jogosultsággal együtt). */
function t_rest(string $metodus, string $ut, array $adat = []): WP_REST_Response
{
    $r = new WP_REST_Request($metodus, '/' . SDH_Sales_Rest::NS . $ut);
    if ($metodus === 'GET') {
        $r->set_query_params($adat);
    } else {
        $r->set_header('content-type', 'application/json');
        $r->set_body((string) wp_json_encode($adat));
    }
    return rest_do_request($r);
}

/** Látogatóként (kijelentkezve) futtat. */
function t_latogato(callable $fv)
{
    $elozo = get_current_user_id();
    wp_set_current_user(0);
    try {
        return $fv();
    } finally {
        wp_set_current_user($elozo);
    }
}
