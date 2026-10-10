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
    public const DB_VERSION = '0.22.0';

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

        // A modulok itt pótolhatják, amit az új oszlopok megkívánnak
        // (pl. a lezárás dátuma a régi lezárt lapokon, demó munkalapok).
        do_action('sdh_muhely_sema_frissult', self::DB_VERSION);
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
        $tetel    = self::tabla('munkalap_tetel');
        $csatolmany = self::tabla('csatolmany');
        $szolgaltatas = self::tabla('szolgaltatas');
        $naplo        = self::tabla('munkalap_naplo');
        $uzenet       = self::tabla('uzenet');
        $termek       = self::tabla('termek');
        $termek_mozgas = self::tabla('termek_mozgas');
        $szamla       = self::tabla('szamla');
        $level        = self::tabla('level');
        $level_mappa  = self::tabla('level_mappa');

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
            belso_megjegyzes text NULL,
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
            tartozekok text NULL,
            belso_megjegyzes text NULL,
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
         *
         * A "lezarva" a lezárt állapotba lépés napja. A "netto_ertek" és
         * a "brutto_ertek" a tételek összege, ide másolva: a kezdőképernyő
         * rácsa így 40 ezer lapnál is egy táblából rendez és szűr. Ezeket
         * mindig a SDH_Muhely_Tetel::ujraszamol() írja, kézzel soha.
         * A "fizetett" a már befizetett összeg, azaz az előleg: ennyivel
         * kevesebb a fizetendő. A "fizetve" jelzi, hogy a lap ki van
         * egyenlítve; a "fizetesi_mod" a beállításokban szerkeszthető lista
         * egy eleme (a neve tárolódik). A "kedvezmeny" és az "afakulcs" a
         * lap tételeinek alapértéke (új tételsor ezzel indul).
         * A "bevizsgalasi_dij" jelzi, hogy az előleg bevizsgálási díj: ilyenkor
         * külön tételsorként él a lapon (SDH_Muhely_Szamla::bevizsgalas_szinkron).
         * A "megjegyzes" belső (csak a CRM-ben látszik), az
         * "ugyfel_megjegyzes" az ügyfél felé is megjelenhet.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$munkalap} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_szam bigint(20) unsigned NULL,
            allapot varchar(30) NOT NULL default 'bejelentett',
            jelzes varchar(20) NOT NULL default '',
            nev varchar(190) NOT NULL default '',
            ugyfel_id bigint(20) unsigned NOT NULL default 0,
            eszkoz_id bigint(20) unsigned NOT NULL default 0,
            felelos bigint(20) unsigned NOT NULL default 0,
            keszult date NULL,
            hatarido date NULL,
            lezarva date NULL,
            fizetve tinyint(1) NOT NULL default 0,
            fizetes_ideje date NULL,
            fizetesi_mod varchar(40) NOT NULL default '',
            fizetett decimal(14,2) NOT NULL default 0.00,
            bevizsgalasi_dij tinyint(1) NOT NULL default 0,
            kedvezmeny decimal(5,2) NOT NULL default 0.00,
            afakulcs varchar(12) NOT NULL default '27',
            netto_ertek decimal(14,2) NOT NULL default 0.00,
            brutto_ertek decimal(14,2) NOT NULL default 0.00,
            megjegyzes text NULL,
            ugyfel_megjegyzes text NULL,
            forras varchar(30) NOT NULL default 'kezi',
            kulso_azonosito varchar(40) NOT NULL default '',
            letrehozva datetime NULL,
            modositva datetime NULL,
            letrehozo bigint(20) unsigned NOT NULL default 0,
            rma_token varchar(40) NOT NULL default '',
            PRIMARY KEY  (id),
            unique key munkalap_szam (munkalap_szam),
            key rma_token (rma_token),
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

        /* -------------------------------------------------------------
         * Munkalap – tételek (szolgáltatások és termékek)
         *
         * A MunkaLap 3 „Szolgáltatások" és „Termékek" lapfülének sorai
         * egy táblában: a kettőt a "tipus" különbözteti meg, minden más
         * mezőjük közös. Az árak nettóban és bruttóban is tárolódnak,
         * mert a pultnál bruttóval dolgoznak, a bizonylat nettót kér.
         * Az "*_ertek" = egységár × mennyiség, kedvezménnyel csökkentve.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$tetel} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            sorrend int(11) NOT NULL default 0,
            tipus varchar(20) NOT NULL default 'szolgaltatas',
            mozgas varchar(20) NOT NULL default 'kimeno',
            allapot varchar(30) NOT NULL default 'teljesitett',
            idopont date NULL,
            szamla varchar(40) NOT NULL default '',
            megnevezes varchar(255) NOT NULL default '',
            termek_id bigint(20) unsigned NOT NULL default 0,
            termekkod varchar(60) NOT NULL default '',
            cikkszam varchar(60) NOT NULL default '',
            gyari_szam varchar(60) NOT NULL default '',
            mennyiseg decimal(12,3) NOT NULL default 1.000,
            me varchar(20) NOT NULL default 'db',
            munkavegzo bigint(20) unsigned NOT NULL default 0,
            kedvezmeny decimal(5,2) NOT NULL default 0.00,
            afa decimal(5,2) NOT NULL default 27.00,
            afa_kulcs varchar(12) NOT NULL default '27',
            netto_ar decimal(14,2) NOT NULL default 0.00,
            brutto_ar decimal(14,2) NOT NULL default 0.00,
            netto_ertek decimal(14,2) NOT NULL default 0.00,
            brutto_ertek decimal(14,2) NOT NULL default 0.00,
            elado varchar(190) NOT NULL default '',
            forras varchar(30) NOT NULL default 'kezi',
            kulso_azonosito varchar(40) NOT NULL default '',
            letrehozva datetime NULL,
            modositva datetime NULL,
            PRIMARY KEY  (id),
            key munkalap_id (munkalap_id),
            key tipus (tipus),
            key termek_id (termek_id),
            key kulso_azonosito (kulso_azonosito)
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

        /* ----------------------------------------------------------
         * Szolgáltatás-törzs
         *
         * A munkalapokon használt szolgáltatások, amelyeket a rendszer
         * megjegyez és gépeléskor felkínál. Egy név = egy sor: a "kulcs"
         * a név kisbetűs, szóköz-egységesített alakjának md5-je, egyedi
         * indexszel – két egyforma nevű sor nem jöhet létre.
         * A "hasznalat" azt számolja, hány tételsorban szerepelt; a lista
         * e szerint rendez. Az ár az utoljára használt bruttó egységár.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$szolgaltatas} (
            id bigint(20) unsigned NOT NULL auto_increment,
            nev varchar(255) NOT NULL default '',
            kulcs char(32) NOT NULL default '',
            me varchar(20) NOT NULL default 'db',
            brutto_ar decimal(14,2) NOT NULL default 0.00,
            afa_kulcs varchar(12) NOT NULL default '27',
            hasznalat int(11) NOT NULL default 0,
            megjegyzes text NULL,
            letrehozva datetime NULL,
            modositva datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY kulcs (kulcs),
            key hasznalat (hasznalat)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Munkalap – állapotnapló
         *
         * Minden állapotváltás egy sor: mikor, mire, ki váltotta. Az
         * ügyfél az RMA-oldalon ebből látja a javítás menetét.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$naplo} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            allapot varchar(30) NOT NULL default '',
            elozo varchar(30) NOT NULL default '',
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key munkalap_id (munkalap_id)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Üzenetek (levelezés az ügyféllel)
         *
         * Egy munkalaphoz tartozó üzenetváltás. "irany": ki = a szerviz
         * írta az ügyfélnek, be = az ügyfél írta (RMA-oldalról, később
         * e-mailből). "csatorna": rma / crm / email – honnan jött.
         * "olvasva": a bejövő üzenetet a CRM-ben megnézték-e.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$uzenet} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            ugyfel_id bigint(20) unsigned NOT NULL default 0,
            irany varchar(4) NOT NULL default 'ki',
            csatorna varchar(20) NOT NULL default 'crm',
            szoveg text NULL,
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            olvasva tinyint(1) NOT NULL default 0,
            email_kuldve tinyint(1) NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key munkalap_id (munkalap_id),
            key ugyfel_id (ugyfel_id),
            key olvasva (olvasva)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Terméktörzs (készlet)
         *
         * A MunkaLap 3 „Tétel" ablakának megfelelője: alkatrészek és
         * eladható termékek. Az eladási ár BRUTTÓ egységár (mint a
         * munkalap tételeinél), a beszerzési ár nettó; mindkettőnek saját
         * áfakulcsa van. A "keszlet" a mozgások összege, ide másolva,
         * hogy a lista egy táblából rendezzen és szűrjön – kézzel soha
         * nem írjuk, mindig a SDH_Muhely_Termek::keszlet_ujraszamol().
         * A "keszletkezeles" nélküli terméknek nincs készletmozgása.
         * A terméket nem töröljük, ha már szerepelt munkalapon: inaktív lesz.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$termek} (
            id bigint(20) unsigned NOT NULL auto_increment,
            megnevezes varchar(255) NOT NULL default '',
            leiras text NULL,
            kategoria varchar(120) NOT NULL default '',
            beszallito varchar(190) NOT NULL default '',
            gyari_szam varchar(60) NOT NULL default '',
            cikkszam varchar(60) NOT NULL default '',
            termekkod varchar(60) NOT NULL default '',
            vonalkod varchar(60) NOT NULL default '',
            osztaly varchar(60) NOT NULL default '',
            keszletkezeles tinyint(1) NOT NULL default 1,
            keszlet decimal(12,3) NOT NULL default 0.000,
            min_keszlet decimal(12,3) NOT NULL default 0.000,
            me varchar(20) NOT NULL default 'db',
            besz_netto decimal(14,2) NOT NULL default 0.00,
            besz_afa_kulcs varchar(12) NOT NULL default '27',
            brutto_ar decimal(14,2) NOT NULL default 0.00,
            afa_kulcs varchar(12) NOT NULL default '27',
            kedvezmeny decimal(5,2) NOT NULL default 0.00,
            konyvelve date NULL,
            megjegyzes text NULL,
            aktiv tinyint(1) NOT NULL default 1,
            forras varchar(30) NOT NULL default 'kezi',
            kulso_azonosito varchar(40) NOT NULL default '',
            letrehozva datetime NULL,
            modositva datetime NULL,
            letrehozo bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (id),
            key megnevezes (megnevezes(120)),
            key kategoria (kategoria),
            key cikkszam (cikkszam),
            key termekkod (termekkod),
            key vonalkod (vonalkod),
            key aktiv (aktiv),
            key kulso_azonosito (kulso_azonosito)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Termék – készletmozgások
         *
         * Minden készletváltozás egy sor, előjeles mennyiséggel:
         * nyito / bevet / korrekcio kézi mozgás, munkalap = egy munkalap
         * terméktétele (ekkor a "tetel_id" azonosítja, tételenként
         * legfeljebb egy sor van). A termék készlete e sorok összege.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$termek_mozgas} (
            id bigint(20) unsigned NOT NULL auto_increment,
            termek_id bigint(20) unsigned NOT NULL default 0,
            tipus varchar(20) NOT NULL default 'korrekcio',
            mennyiseg decimal(12,3) NOT NULL default 0.000,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            tetel_id bigint(20) unsigned NOT NULL default 0,
            megjegyzes varchar(255) NOT NULL default '',
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key termek_id (termek_id),
            key munkalap_id (munkalap_id),
            key tetel_id (tetel_id)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Számlák
         *
         * A Számlázz.hu által kiállított számlák nyilvántartása: melyik
         * munkalaphoz, milyen számon, mekkora összeggel készült. A számla
         * maga a Számlázz.hu-nál él (ő állítja ki és jelenti a NAV felé);
         * itt a száma, a PDF helyi másolatának neve és a beküldött tételek
         * pillanatképe van. "sorozat": fo = a fiók alap számlatömbje,
         * sdh = a beállított előtagú (SD-…) számlatömb.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$szamla} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            ugyfel_id bigint(20) unsigned NOT NULL default 0,
            szamlaszam varchar(60) NOT NULL default '',
            sorozat varchar(10) NOT NULL default 'fo',
            netto decimal(14,2) NOT NULL default 0.00,
            brutto decimal(14,2) NOT NULL default 0.00,
            fizetve tinyint(1) NOT NULL default 0,
            fizmod varchar(40) NOT NULL default '',
            kelt date NULL,
            teljesites date NULL,
            hatarido date NULL,
            pdf varchar(40) NOT NULL default '',
            tetelek longtext NULL,
            vevo text NULL,
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key munkalap_id (munkalap_id),
            key ugyfel_id (ugyfel_id),
            key szamlaszam (szamlaszam)
        ) {$charset};";

        /* ----------------------------------------------------------
         * Levelezés – a postafiókok mappái
         *
         * A levelek a levelezőszerveren (Gmail) élnek; itt csak a
         * gyorsítótáruk van, hogy a lista azonnal megjelenjen. "nyers":
         * a mappa neve a szerver kódolásában (módosított UTF-7) – ezzel
         * szólítjuk meg; "nev": ugyanez olvashatóan. "szerep": inbox,
         * sent, drafts, trash, junk, all, flagged, important vagy üres
         * (saját címke). "min_uid"–"max_uid": eddig a tartományig van
         * hiánytalanul letöltve a mappa; UIDVALIDITY-váltásnál ürül.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$level_mappa} (
            id bigint(20) unsigned NOT NULL auto_increment,
            fiok varchar(10) NOT NULL default '',
            nyers varchar(255) NOT NULL default '',
            nev varchar(255) NOT NULL default '',
            szerep varchar(20) NOT NULL default '',
            elvalaszto varchar(4) NOT NULL default '/',
            valaszthato tinyint(1) NOT NULL default 1,
            uidvalidity bigint(20) unsigned NOT NULL default 0,
            min_uid bigint(20) unsigned NOT NULL default 0,
            max_uid bigint(20) unsigned NOT NULL default 0,
            osszes int(11) NOT NULL default 0,
            olvasatlan int(11) NOT NULL default 0,
            szinkron datetime NULL,
            PRIMARY KEY  (id),
            key fiok (fiok),
            key szerep (szerep)
        ) {$charset};";

        /* ----------------------------------------------------------
         * Levelezés – a levelek fejadatai (gyorsítótár)
         *
         * Egy sor = egy levél egy mappában (a Gmailnél ugyanaz a levél
         * több címke alatt is megjelenik – az külön sor). A levél törzse
         * és a csatolmányok NINCSENEK itt: megnyitáskor a szerverről
         * jönnek. "fontossag" / "fontos_ok" / "ugynok": a fontos-levél
         * ügynök besorolása (azonnal, ma, raer, zaj) és indoka;
         * "elintezve": a CRM-ben lezárt jelzés (a postafiókot nem érinti).
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$level} (
            id bigint(20) unsigned NOT NULL auto_increment,
            fiok varchar(10) NOT NULL default '',
            mappa_id bigint(20) unsigned NOT NULL default 0,
            uid bigint(20) unsigned NOT NULL default 0,
            message_id varchar(255) NOT NULL default '',
            felado_nev varchar(190) NOT NULL default '',
            felado_email varchar(190) NOT NULL default '',
            cimzettek text NULL,
            targy varchar(255) NOT NULL default '',
            kivonat varchar(400) NOT NULL default '',
            datum datetime NULL,
            meret int(11) NOT NULL default 0,
            olvasott tinyint(1) NOT NULL default 0,
            csillag tinyint(1) NOT NULL default 0,
            valaszolt tinyint(1) NOT NULL default 0,
            piszkozat tinyint(1) NOT NULL default 0,
            csatolmany tinyint(1) NOT NULL default 0,
            fontossag varchar(10) NOT NULL default '',
            fontos_ok varchar(255) NOT NULL default '',
            ugynok varchar(10) NOT NULL default '',
            elintezve tinyint(1) NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            unique key mappa_uid (mappa_id,uid),
            key fiok_fontossag (fiok,fontossag),
            key felado_email (felado_email)
        ) {$charset};";

        return $definiciok;
    }
}
