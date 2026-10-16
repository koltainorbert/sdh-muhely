<?php
/**
 * SDH Műhely – tesztkeret.
 *
 * A tesztek egy VALÓDI WordPress-telepítésen futnak, és adatot írnak bele
 * (ügyfél, eszköz, munkalap, pénztártétel). Ezért csak külön tesztoldalon
 * futtathatók: az indításhoz kell az SDH_TESZT_ADATBAZIS=1 környezeti
 * változó – éles vagy munkára használt oldalon SOHA ne állítsd be.
 *
 * A WordPress helye: SDH_WP_LOAD (a wp-load.php teljes útja); ha nincs
 * megadva, a plugin mappájából felfelé keressük.
 *
 * A telepítő zipbe ez a mappa nem kerül be (.gitattributes: export-ignore).
 */

if (PHP_SAPI !== 'cli') {
    exit("Csak parancssorból futtatható.\n");
}

if (getenv('SDH_TESZT_ADATBAZIS') !== '1') {
    fwrite(STDERR, "A tesztek adatot írnak az adatbázisba. Csak tesztoldalon futtasd: SDH_TESZT_ADATBAZIS=1 php tesztek/futtat.php\n");
    exit(2);
}

$sdh_wp_load = getenv('SDH_WP_LOAD') ?: '';

if ($sdh_wp_load === '') {
    // wp-content/plugins/sdh-muhely/tesztek → négy szinttel feljebb a WordPress gyökere.
    $sdh_wp_load = dirname(__DIR__, 4) . '/wp-load.php';
}

if (!is_file($sdh_wp_load)) {
    fwrite(STDERR, "Nem találom a wp-load.php fájlt. Add meg: SDH_WP_LOAD=/út/wp-load.php\n");
    exit(2);
}

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require $sdh_wp_load;

if (!class_exists('SDH_Muhely_Munkalap')) {
    fwrite(STDERR, "Az SDH Műhely plugin nincs bekapcsolva ezen a WordPress-oldalon.\n");
    exit(2);
}

// Rendszergazdaként futunk, friss sémával.
$sdh_admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($sdh_admin[0] ?? 1));
SDH_Muhely_Schema::frissites_ha_kell();

/* -------------------------------------------------------------------- */
/* Állítások                                                             */
/* -------------------------------------------------------------------- */

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
        if (is_float($vart) || is_float($kapott)) {
            $jo = abs((float) $vart - (float) $kapott) < 0.005;
        } else {
            $jo = $vart === $kapott;
        }

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

    /** Egy összegző sor, és a kilépési kód (0 = minden zöld). */
    public static function vege(string $cim): void
    {
        $ossz = self::$ok + count(self::$hibak);

        echo $cim . ': ' . self::$ok . '/' . $ossz . (self::$hibak === [] ? ' OK' : ' HIBA') . "\n";

        foreach (self::$hibak as $hiba) {
            echo '   ✗ ' . $hiba . "\n";
        }

        exit(self::$hibak === [] ? 0 : 1);
    }
}

/* -------------------------------------------------------------------- */
/* Tesztadatok                                                           */
/* -------------------------------------------------------------------- */

/** Új ügyfél; visszaadja az azonosítóját. */
function t_ugyfel(string $nev = 'Teszt Elek', array $tovabbi = []): int
{
    global $wpdb;

    $wpdb->insert(SDH_Muhely_Schema::tabla('ugyfel'), $tovabbi + [
        'nev'                     => $nev . ' ' . wp_generate_password(5, false),
        'telefon'                 => '+3630' . wp_rand(1000000, 9999999),
        'email'                   => 'teszt' . wp_rand(1000, 99999) . '@example.com',
        'szamlazasi_iranyitoszam' => '8200',
        'szamlazasi_telepules'    => 'Veszprém',
        'szamlazasi_cim'          => 'Teszt utca 1.',
        'letrehozva'              => current_time('mysql'),
    ]);

    return (int) $wpdb->insert_id;
}

/** Van-e a táblának ilyen oszlopa – MySQL-en és SQLite-on is (a „SHOW COLUMNS … LIKE” SQLite-on nem megy). */
function t_oszlop(string $tabla, string $oszlop): bool
{
    global $wpdb;

    $oszlopok = (array) $wpdb->get_col('SHOW COLUMNS FROM ' . $tabla);

    if ($oszlopok === []) {
        $oszlopok = array_column((array) $wpdb->get_results('PRAGMA table_info(' . $tabla . ')', ARRAY_A), 'name');
    }

    return in_array($oszlop, $oszlopok, true);
}

/** Új eszköz az ügyfélnek; visszaadja az azonosítóját. */
function t_eszkoz(int $ugyfel_id, string $gyarto, string $tipus): int
{
    global $wpdb;

    $wpdb->insert(SDH_Muhely_Schema::tabla('eszkoz'), [
        'ugyfel_id'  => $ugyfel_id,
        'kategoria'  => 'telefon',
        'gyarto'     => $gyarto,
        'tipus'      => $tipus,
        'imei'       => (string) wp_rand(100000000000000, 999999999999999),
        'letrehozva' => current_time('mysql'),
    ]);

    return (int) $wpdb->insert_id;
}

/**
 * Új munkalap a megadott eszközökkel: az első a fő eszköz, a többi csatolt.
 *
 * @param array<int, int> $eszkozok
 */
function t_munkalap(int $ugyfel_id, array $eszkozok, string $allapot = 'nyitott', array $tovabbi = []): int
{
    $id = SDH_Muhely_Munkalap::letrehoz($tovabbi + [
        'allapot'   => $allapot,
        'ugyfel_id' => $ugyfel_id,
        'eszkoz_id' => (int) $eszkozok[0],
        'keszult'   => current_time('Y-m-d'),
    ]);

    if (!is_int($id)) {
        fwrite(STDERR, 'A tesztmunkalap nem jött létre: ' . $id . "\n");
        exit(2);
    }

    if (count($eszkozok) > 1) {
        SDH_Muhely_Munkalap::tovabbi_ment($id, array_slice($eszkozok, 1));
    }

    return $id;
}

/** Tétel a munkalapra; $kulcs: 0 = a fő eszköz, különben a csatolt eszköz azonosítója. */
function t_tetel(int $munkalap_id, string $nev, float $brutto, int $kulcs = 0, string $tipus = 'szolgaltatas'): int
{
    return SDH_Muhely_Tetel::hozzaad($munkalap_id, [
        'tipus'      => $tipus,
        'megnevezes' => $nev,
        'brutto_ar'  => $brutto,
        'mennyiseg'  => 1,
        'eszkoz_id'  => $kulcs,
    ]);
}

/** A munkalap friss sora az adatbázisból. */
function t_lap(int $id): object
{
    global $wpdb;

    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $id));
}

/** Az eszközbontás kulcs szerint (0 = a fő eszköz). */
function t_bontas(int $id): array
{
    $ki = [];

    foreach (SDH_Muhely_Munkalap::eszkoz_bontas(t_lap($id)) as $b) {
        $ki[(int) $b['eszkoz_id']] = $b;
    }

    return $ki;
}

/* -------------------------------------------------------------------- */
/* AJAX-hívás szimulálása                                                */
/* -------------------------------------------------------------------- */

final class T_Ajax_Vege extends Exception
{
}

/**
 * Egy wp_ajax_* művelet meghívása a megadott POST-mezőkkel.
 *
 * @param array<string, mixed> $post
 * @return array{success?: bool, data?: mixed, nyers: string}
 */
function t_ajax(string $muvelet, array $post = [], string $nonce_mezo = '_wpnonce', string $nonce_akcio = 'sdh_muhely_modal', array $get = []): array
{
    static $beallitva = false;

    if (!$beallitva) {
        add_filter('wp_doing_ajax', '__return_true');
        add_filter('wp_die_ajax_handler', static fn () => static function (): void {
            throw new T_Ajax_Vege();
        });
        $beallitva = true;
    }

    $regi_post = $_POST;
    $regi_get  = $_GET;
    $regi_req  = $_REQUEST;

    $_POST    = wp_slash($post + [$nonce_mezo => wp_create_nonce($nonce_akcio), 'action' => $muvelet]);
    $_GET     = wp_slash($get + [$nonce_mezo => wp_create_nonce($nonce_akcio)]);
    $_REQUEST = $_POST + $_GET;

    ob_start();

    try {
        do_action('wp_ajax_' . $muvelet);
    } catch (T_Ajax_Vege $vege) {
        // A kezelő befejezte a választ.
    }

    $nyers = (string) ob_get_clean();

    $_POST    = $regi_post;
    $_GET     = $regi_get;
    $_REQUEST = $regi_req;

    $json = json_decode($nyers, true);

    return (is_array($json) ? $json : []) + ['nyers' => $nyers];
}
