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
    public const DB_VERSION = '0.12.0';

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
        $eszkoz = self::tabla('eszkoz');
        $tac    = self::tabla('tac');
        $munkalap = self::tabla('munkalap');
        $hiba     = self::tabla('munkalap_hiba');
        $csatolmany = self::tabla('csatolmany');

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
            szallitasi_azonos tinyint(1) NOT NULL default 1,
            szallitasi_iranyitoszam varchar(10) NOT NULL default '',
            szallitasi_telepules varchar(120) NOT NULL default '',
            szallitasi_cim varchar(190) NOT NULL default '',
            telephely_azonos tinyint(1) NOT NULL default 1,
            telephely_nev varchar(190) NOT NULL default '',
            telephely_iranyitoszam varchar(10) NOT NULL default '',
            telephely_telepules varchar(120) NOT NULL default '',
            telephely_cim varchar(190) NOT NULL default '',
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

        /* -------------------------------------------------------------
         * Eszköz
         *
         * A MunkaLap „Tárgy" fogalmának megfelelője: egy konkrét
         * készülék, ami egy ügyfélhez tartozik. A munkalap mindig egy
         * eszközre hivatkozik majd – így a készülék előélete
         * (hányszor járt már nálunk, mivel) egyben látszik.
         *
         * Miért van külön tábla, miért nem a munkalapon vannak a
         * készülékadatok: ugyanaz a telefon többször is visszajöhet.
         * Ha az adatai a munkalapon ülnének, minden alkalommal újra
         * kellene gépelni az IMEI-t, és az előzmény szétesne.
         *
         * A zárkód azért kell, mert e nélkül a legtöbb javítás nem
         * tesztelhető. Belső rendszerben tároljuk, és csak az fér
         * hozzá, aki a műhelyrendszert egyáltalán használhatja.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$eszkoz} (
            id bigint(20) unsigned NOT NULL auto_increment,
            ugyfel_id bigint(20) unsigned NOT NULL default 0,
            kategoria varchar(40) NOT NULL default 'telefon',
            gyarto varchar(80) NOT NULL default '',
            tipus varchar(120) NOT NULL default '',
            megnevezes varchar(120) NOT NULL default '',
            imei varchar(40) NOT NULL default '',
            imei2 varchar(40) NOT NULL default '',
            sorozatszam varchar(60) NOT NULL default '',
            modell_szam varchar(60) NOT NULL default '',
            garancia_allapot varchar(60) NOT NULL default '',
            gyartas_datuma date NULL,
            orszag varchar(80) NOT NULL default '',
            szolgaltato varchar(80) NOT NULL default '',
            kep_url varchar(255) NOT NULL default '',
            lekerdezve datetime NULL,
            szin varchar(40) NOT NULL default '',
            zarkod varchar(60) NOT NULL default '',
            minta varchar(20) NOT NULL default '',
            tartozekok varchar(255) NOT NULL default '',
            atveteli_allapot text NULL,
            garancias tinyint(1) NOT NULL default 0,
            vasarlas_datuma date NULL,
            garancia_lejar date NULL,
            megjegyzes text NULL,
            aktiv tinyint(1) NOT NULL default 1,
            forras varchar(30) NOT NULL default 'kezi',
            kulso_azonosito varchar(40) NOT NULL default '',
            letrehozva datetime NULL,
            modositva datetime NULL,
            letrehozo bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (id),
            key ugyfel_id (ugyfel_id),
            key imei (imei),
            key sorozatszam (sorozatszam),
            key gyarto (gyarto),
            key aktiv (aktiv),
            key kulso_azonosito (kulso_azonosito)
        ) {$charset};";

        /* -------------------------------------------------------------
         * TAC – készüléktípus-adatbázis
         *
         * Az IMEI első nyolc számjegye a TAC: ez azonosítja a
         * készüléktípust. Ez a tábla a nyilvános TAC-adatbázis helyi
         * másolata, hogy IMEI beírásakor ismeretlen készüléknél is
         * kitölthessük a gyártót és a típust.
         *
         * Miért helyi másolat és nem online lekérdezés: a rendszernek
         * internet nélkül, az irodai szerveren is mennie kell. Egy
         * külső API ott az első hálózatkimaradáskor megállítaná a
         * pultot.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$tac} (
            tac char(8) NOT NULL,
            gyarto varchar(80) NOT NULL default '',
            modell varchar(60) NOT NULL default '',
            megnevezes varchar(120) NOT NULL default '',
            kep_url varchar(255) NOT NULL default '',
            forras varchar(20) NOT NULL default 'csomag',
            frissitve datetime NULL,
            PRIMARY KEY  (tac),
            key gyarto (gyarto),
            key modell (modell),
            key forras (forras)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Munkalap
         *
         * Egy ügyfél egy eszközéhez tartozó javítási ügy. A munkalap
         * nem törlődik: ha érvénytelen, az az állapota (Érvénytelen),
         * így a számozásban nem keletkezik lyuk.
         *
         * A "munkalap_szam" NULL, amíg a lap nem kapott számot: a
         * Sablon és az Árajánlat állapotú lapok még nem igazi munkalapok.
         * A szám az első számozott állapotba lépéskor generálódik. A
         * NULL-t a MySQL egyedi kulcsnál nem tekinti ütközésnek, a
         * valódi számokat viszont egyedinek tartja – két lap nem
         * kaphatja ugyanazt a számot, versenyhelyzetben sem.
         *
         * Az "allapot" a beállításokban szerkeszthető lista egy kulcsa,
         * ezért varchar, nem enum: új állapot felvételéhez nem kell
         * sémát módosítani.
         *
         * A "kulso_azonosito" a MunkaLap 3 saját kulcsa az átvételhez.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$munkalap} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_szam bigint(20) unsigned NULL,
            allapot varchar(30) NOT NULL default 'bejelentett',
            nev varchar(190) NOT NULL default '',
            ugyfel_id bigint(20) unsigned NOT NULL default 0,
            eszkoz_id bigint(20) unsigned NOT NULL default 0,
            felelos bigint(20) unsigned NOT NULL default 0,
            keszult date NULL,
            hatarido date NULL,
            megjegyzes text NULL,
            forras varchar(30) NOT NULL default 'kezi',
            kulso_azonosito varchar(40) NOT NULL default '',
            letrehozva datetime NULL,
            modositva datetime NULL,
            letrehozo bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (id),
            unique key munkalap_szam (munkalap_szam),
            key allapot (allapot),
            key ugyfel_id (ugyfel_id),
            key eszkoz_id (eszkoz_id),
            key felelos (felelos),
            key hatarido (hatarido),
            key kulso_azonosito (kulso_azonosito)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Munkalap – hibasorok
         *
         * Egy munkalapon több hiba is lehet (pl. törött kijelző és
         * gyenge akku), és mindegyiknek külön haladása van: az egyik
         * már kész, a másik alkatrészre vár. Ezért külön tábla, saját
         * állapottal – a munkalap állapota ettől független.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$hiba} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            sorrend int(11) NOT NULL default 0,
            leiras varchar(255) NOT NULL default '',
            javitas text NULL,
            allapot varchar(30) NOT NULL default 'uj',
            letrehozva datetime NULL,
            modositva datetime NULL,
            PRIMARY KEY  (id),
            key munkalap_id (munkalap_id),
            key allapot (allapot)
        ) {$charset};";

        /* ----------------------------------------------------------
         * Csatolt fájlok
         *
         * Bármelyik modulhoz tartozhat: tipus + ref_id adja a gazdát
         * (pl. 'ugyfel', 12). A fájl maga a feltöltési mappában van,
         * kitalálhatatlan néven; ide az eredeti név és a típus kerül.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$csatolmany} (
            id bigint(20) unsigned NOT NULL auto_increment,
            tipus varchar(30) NOT NULL default '',
            ref_id bigint(20) unsigned NOT NULL default 0,
            eredeti_nev varchar(190) NOT NULL default '',
            tarolt_nev varchar(40) NOT NULL default '',
            mime varchar(100) NOT NULL default '',
            meret bigint(20) unsigned NOT NULL default 0,
            feltoltve datetime NULL,
            feltolto bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (id),
            key gazda (tipus, ref_id)
        ) {$charset};";

        return $definiciok;
    }
}
