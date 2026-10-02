<?php
/**
 * Adatbázis-séma.
 *
 * Minden saját tábla itt születik, egy helyen. A séma verziózott: ha a
 * DB_VERSION megváltozik, a telepítő a következő admin-oldalbetöltéskor
 * magától lefut, és a dbDelta hozzáigazítja a táblákat.
 *
 * Fontos: a dbDelta válogatós. Amit nem szabad elrontani benne:
 *   - minden mező saját sorban,
 *   - a "PRIMARY KEY" után KÉT szóköz,
 *   - a kulcsszavak kisbetűvel ("key", nem "KEY") az indexeknél,
 *   - a típusok pontosan úgy írva, ahogy a MySQL visszaadja őket.
 * Ha ezek nem stimmelnek, a dbDelta minden futásnál újra meg újra
 * próbálja módosítani ugyanazt a mezőt.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Schema
{
    /**
     * A séma verziója. Ha táblát vagy mezőt módosítasz, EZT IS LÉPTESD,
     * különben a változás nem jut el a már működő telepítésekre.
     */
    public const DB_VERSION = '0.2.0';

    /** Az option neve, amiben a telepített sémaverziót tartjuk. */
    private const OPTION = 'sdh_muhely_db_version';

    public static function init(): void
    {
        // Minden admin-betöltéskor olcsó ellenőrzés: egy option-olvasás.
        add_action('admin_init', [self::class, 'frissites_ha_kell']);
    }

    /**
     * Egy tábla teljes neve a WordPress előtagjával.
     *
     * Mindig ezen keresztül hivatkozz táblára, soha ne írd be kézzel –
     * a Local és az éles telepítés előtagja eltérhet.
     */
    public static function tabla(string $nev): string
    {
        global $wpdb;

        return $wpdb->prefix . 'sdh_' . $nev;
    }

    /**
     * Lefuttatja a telepítőt, ha a tárolt verzió nem a mostani.
     */
    public static function frissites_ha_kell(): void
    {
        if (get_option(self::OPTION) === self::DB_VERSION) {
            return;
        }

        self::telepit();
    }

    /**
     * Létrehozza vagy hozzáigazítja a táblákat.
     *
     * Aktiváláskor és sémaverzió-váltáskor fut. Idempotens: bármikor
     * újrafuttatható, adatot nem veszít.
     */
    public static function telepit(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        foreach (self::tabla_definiciok($charset) as $sql) {
            dbDelta($sql);
        }

        update_option(self::OPTION, self::DB_VERSION);
    }

    /**
     * A tábladefiníciók. Új tábla = új elem ebben a tömbben.
     *
     * @return array<int, string>
     */
    private static function tabla_definiciok(string $charset): array
    {
        $ugyfel = self::tabla('ugyfel');

        $definiciok = [];

        /* -------------------------------------------------------------
         * Ügyfél
         *
         * A MunkaLap ügyfél-adatlapjának webes megfelelője. Magánszemély
         * és cég egy táblában van: a különbséget a "tipus" és a kitöltött
         * mezők adják, nem külön tábla. Így a munkalap mindig egyetlen
         * ügyfél-azonosítóra hivatkozik.
         *
         * A "kulso_azonosito" a MunkaLap 3 saját kulcsa. Az átvett
         * közel 40 ezer rekordnál ez köti össze a régi és az új adatot,
         * és ez akadályozza meg, hogy egy ismételt import duplikáljon.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$ugyfel} (
            id bigint(20) unsigned NOT NULL auto_increment,
            ugyfel_szam varchar(20) NOT NULL default '',
            tipus varchar(20) NOT NULL default 'maganszemely',
            nev varchar(190) NOT NULL default '',
            kapcsolattarto varchar(190) NOT NULL default '',
            adoszam varchar(30) NOT NULL default '',
            telefon varchar(40) NOT NULL default '',
            telefon2 varchar(40) NOT NULL default '',
            email varchar(190) NOT NULL default '',
            szamlazasi_iranyitoszam varchar(10) NOT NULL default '',
            szamlazasi_telepules varchar(120) NOT NULL default '',
            szamlazasi_cim varchar(190) NOT NULL default '',
            szamlazasi_orszag varchar(60) NOT NULL default 'Magyarország',
            levelezesi_azonos tinyint(1) NOT NULL default 1,
            levelezesi_iranyitoszam varchar(10) NOT NULL default '',
            levelezesi_telepules varchar(120) NOT NULL default '',
            levelezesi_cim varchar(190) NOT NULL default '',
            kategoria varchar(60) NOT NULL default '',
            kedvezmeny decimal(5,2) NOT NULL default 0.00,
            megjegyzes text NULL,
            aktiv tinyint(1) NOT NULL default 1,
            forras varchar(30) NOT NULL default 'kezi',
            kulso_azonosito varchar(40) NOT NULL default '',
            letrehozva datetime NULL,
            modositva datetime NULL,
            letrehozo bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (id),
            key ugyfel_szam (ugyfel_szam),
            key nev (nev),
            key telefon (telefon),
            key email (email),
            key aktiv (aktiv),
            key kulso_azonosito (kulso_azonosito)
        ) {$charset};";

        return $definiciok;
    }
}
