<?php
/**
 * A bemutató tesztjeinek közös kerete (t290–t299). A bemutató-példányon fut (műhely + „Bemutató” bővítmény, az
 * értékesítő bővítmény nélkül), ezért nem a _keret.php-t tölti. A leállítás (SDH_Demo::megallit) tesztben kivétel.
 *
 *   SDH_TESZT_ADATBAZIS=1 SDH_WP_LOAD=/home/claude/env/demo/wp/wp-load.php \
 *   SDH_TESZT_DB=/home/claude/env/demo/wp/wp-content/database/sales.sqlite SDH_TESZT_ALAP=/home/claude/env/demo/alap.sqlite \
 *   php weboldal/tesztek/futtat.php t29
 */

if (PHP_SAPI !== 'cli') {
    exit("Csak parancssorból futtatható.\n");
}

if (getenv('SDH_TESZT_ADATBAZIS') !== '1') {
    fwrite(STDERR, "A tesztek adatot írnak. Csak tesztoldalon: SDH_TESZT_ADATBAZIS=1\n");
    exit(2);
}

define('BEMUTATO_TESZT', true);

$bm_t_load = getenv('SDH_WP_LOAD') ?: '/home/claude/env/demo/wp/wp-load.php';

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8793';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/wp/';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.7';

/** @var array<int, callable> hamis HTTP-kezelők */
$GLOBALS['bm_t_http']    = [];
/** @var array<int, array{url: string, args: array}> minden kimenő kérés, ami a bemutató kapuján átjutott */
$GLOBALS['bm_t_keresek'] = [];
$GLOBALS['bm_t_phpmailer'] = 0;

require $bm_t_load;

add_filter('pre_http_request', static function ($pre, $args, $url) {
    if ($pre !== false) {
        return $pre;
    }

    $GLOBALS['bm_t_keresek'][] = ['url' => (string) $url, 'args' => (array) $args];

    foreach ($GLOBALS['bm_t_http'] as $k) {
        $v = $k((string) $url, (array) $args);

        if (is_array($v)) {
            return $v + ['headers' => [], 'cookies' => [], 'filename' => null];
        }
    }

    return new WP_Error('teszt_halozat', 'Teszt: a hálózat tiltva (' . $url . ')');
}, 1, 3);

add_action('phpmailer_init', static function (): void {
    $GLOBALS['bm_t_phpmailer']++;
});

add_filter('sdh_muhely_halozat_kenyszer', static fn (): string => 'online');

if (!class_exists('SDH_Demo') || !SDH_Demo::muhely_kesz()) {
    fwrite(STDERR, "A bemutató bővítmény vagy a műhely nincs bekapcsolva a tesztoldalon.\n");
    exit(2);
}

$bm_t_admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
define('BM_T_ADMIN', (int) ($bm_t_admin[0] ?? 1));
wp_set_current_user(BM_T_ADMIN);
SDH_Demo::telepit_ha_kell();

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
        if ($vart === $kapott) {
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

function t_http(callable $k): void
{
    $GLOBALS['bm_t_http'][] = $k;
}

function t_valasz(int $kod, string $test): array
{
    return ['response' => ['code' => $kod, 'message' => 'OK'], 'body' => $test, 'headers' => []];
}

/** Új bemutató-látogató (a valódi létrehozóval), és belépve vele. */
function t_latogato(string $ip = '203.0.113.7'): int
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    $id = SDH_Demo_Inditas::letrehoz(SDH_Demo::ip_hash($ip));
    wp_set_current_user($id);
    SDH_Demo_Keret::felejt();

    return $id;
}

function t_admin(): void
{
    wp_set_current_user(BM_T_ADMIN);
    SDH_Demo_Keret::felejt();
}

/** Lefuttatja; ha a bemutató leállítaná a kérést, a kivételt adja vissza. */
function t_megallit(callable $f): ?SDH_Demo_Megallitva
{
    try {
        $f();
    } catch (SDH_Demo_Megallitva $e) {
        return $e;
    }

    return null;
}

/** Egy új ügyfél a műhely adatrétegén át (ahogy a mentés írja). */
function t_ugyfel(string $nev): int
{
    global $wpdb;

    $wpdb->insert(SDH_Muhely_Schema::tabla('ugyfel'), ['nev' => $nev, 'tipus' => 'maganszemely', 'telefon' => '+36 30 000 0000', 'letrehozva' => current_time('mysql')]);
    $id = (int) $wpdb->insert_id;
    $wpdb->get_var('SELECT 1');

    return $id;
}

/** Egy PHP-folyamat a teszt nélküli (éles) leállítási úttal: a kimenet a valódi válasz. */
function t_folyamat(string $kod): string
{
    $fajl = tempnam(sys_get_temp_dir(), 'bmt') . '.php';
    file_put_contents($fajl, "<?php\n\$_SERVER['HTTP_HOST']='127.0.0.1:8793'; \$_SERVER['REQUEST_URI']='/wp/'; \$_SERVER['REMOTE_ADDR']='198.51.100.9';\n"
        . $kod);
    $ki = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fajl) . ' 2>/dev/null');
    unlink($fajl);

    return $ki;
}

/** A kimenet tiltott mintái (REJTES.md) – a tesztkörnyezet saját címe nélkül. @return array<int, string> találatok */
function t_tiltott(string $szoveg): array
{
    $szoveg = str_replace([home_url(), site_url(), esc_url(home_url()), wp_json_encode(home_url())], 'https://pelda.test', $szoveg);
    $szoveg = str_replace(trim((string) wp_json_encode(home_url()), '"'), 'https://pelda.test', $szoveg);
    $mintak = [
        '/sdh/i', '/wordpress/i', '/\bwp[-_]/i', '/\bWP\b/', '/wp-(content|includes|admin|json|login|cron)/i', '/admin-ajax|xmlrpc|rest_route/i',
        '/plugin|bővítmény/i', '/Gutenberg|WooCommerce|Elementor|Bricks|Yoast|Rank Math/i', '/Veszprém/', '/InfoStudio|Koltai/i',
    ];
    $ki = [];

    foreach ($mintak as $m) {
        if (preg_match($m, $szoveg, $t)) {
            $ki[] = $m . ' → ' . $t[0];
        }
    }

    return $ki;
}
