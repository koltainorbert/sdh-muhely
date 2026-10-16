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
    public const DB_VERSION = '0.53.0';

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

    /** A telepített séma a mostani-e (a változásnapló csak ekkor ír – a táblája ekkor létezik). */
    public static function naprakesz(): bool
    {
        return get_option(self::OPTION) === self::DB_VERSION;
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
        $ugyfel_szo = self::tabla('ugyfel_szo');
        $eszkoz = self::tabla('eszkoz');
        $tac    = self::tabla('tac');
        $munkalap = self::tabla('munkalap');
        $hiba     = self::tabla('munkalap_hiba');
        $ml_eszkoz = self::tabla('munkalap_eszkoz');
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
        $level_feladat = self::tabla('level_feladat');
        $penztar      = self::tabla('penztar');
        $penztar_nap  = self::tabla('penztar_nap');
        $penztar_naplo = self::tabla('penztar_naplo');
        $cs_besz      = self::tabla('csapat_beszelgetes');
        $cs_tag       = self::tabla('csapat_tag');
        $cs_uzenet    = self::tabla('csapat_uzenet');
        $cs_nyugta    = self::tabla('csapat_nyugta');
        $cs_csoport   = self::tabla('csapat_csoport');
        $cs_cstag     = self::tabla('csapat_csoport_tag');
        $cs_cetli     = self::tabla('csapat_cetli');
        $ai_uzenet    = self::tabla('ai_uzenet');
        $ai_muvelet   = self::tabla('ai_muvelet');
        $ai_tudas     = self::tabla('ai_tudas');
        $helyszin     = self::tabla('helyszin');
        $beszallito   = self::tabla('beszallito');
        $np_szemely   = self::tabla('naptar_szemely');
        $np_esemeny   = self::tabla('naptar_esemeny');
        $np_jelzes    = self::tabla('naptar_jelzes');
        $sms_sablon   = self::tabla('sms_sablon');
        $sms_verzio   = self::tabla('sms_sablon_verzio');
        $sms_szabaly  = self::tabla('sms_szabaly');
        $sms_uzenet   = self::tabla('sms_uzenet');
        $sms_esemeny  = self::tabla('sms_esemeny');
        $sms_dontes   = self::tabla('sms_dontes');
        $sms_onteszt  = self::tabla('sms_onteszt');
        $sms_link     = self::tabla('sms_link_esemeny');
        $sms_leir     = self::tabla('sms_leiratkozas');
        $naplo_v      = self::tabla('valtozasnaplo');
        $mezo_emlek   = self::tabla('mezo_emlek');
        $atvetel_sor  = self::tabla('atvetel_sor');

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
            sms_tilt tinyint(1) NOT NULL default 0,
            forras varchar(30) NOT NULL default 'kezi',
            kulso_azonosito varchar(40) NOT NULL default '',
            letrehozva datetime NULL,
            modositva datetime NULL,
            letrehozo bigint(20) unsigned NOT NULL default 0,
            kereso varchar(190) NOT NULL default '',
            tel_ford varchar(40) NOT NULL default '',
            cim_rendez varchar(190) NOT NULL default '',
            anonimizalva datetime NULL,
            PRIMARY KEY  (id),
            key ugyfel_szam (ugyfel_szam),
            key nev (nev),
            key telefon (telefon),
            key email (email),
            key aktiv (aktiv),
            key kulso_azonosito (kulso_azonosito),
            key kereso (kereso),
            key tel_ford (tel_ford),
            key cim_rendez (cim_rendez)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Ügyfél – szó-index (0.64)
         *
         * Az ügyfélkereső „szó eleje" lépcsője: minden ügyfél keresőszavai
         * (név, település, cím, e-mail szavai; telefonszámok több alakban;
         * ügyfélszám), kisbetűsen, ékezet nélkül. A „kezdődik vele" keresés
         * így a keresztnévre, a településre vagy a 06-os telefonra is indexről
         * megy. Származtatott adat: a SDH_Muhely_Ugyfel tölti mentéskor, és a
         * meglévő ügyfeleknél egyszer, kötegelve. A változásnapló kihagyja.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$ugyfel_szo} (
            szo varchar(40) NOT NULL default '',
            ugyfel_id bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (szo,ugyfel_id),
            key ugyfel_id (ugyfel_id)
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
            zarkod varchar(255) NOT NULL default '',
            minta varchar(255) NOT NULL default '',
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
            key imei2 (imei2),
            key sorozatszam (sorozatszam),
            key gyarto (gyarto),
            key tipus (tipus),
            key megnevezes (megnevezes),
            key kategoria (kategoria),
            key modositva (modositva),
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
         * "bevizsgalas_mod": auto / levon (javítást kér: a díj levonódik a
         * végösszegből, mínusz tételként) / marad (nem kér javítást: a díj
         * munkadíjként marad). "bevizsgalas_nev" / "_szolg": a díjlistából
         * választott díj neve és szolgáltatás-azonosítója.
         * A "megjegyzes" belső (csak a CRM-ben látszik), az
         * "ugyfel_megjegyzes" az ügyfél felé is megjelenhet.
         *
         * A "fo_kulon" az, amit a FŐ eszközre a bevizsgálási díján FELÜL
         * befizettek (előleg / részfizetés a Fizetés ablakból) – ugyanaz, mint
         * a csatolt eszköznél a munkalap_eszkoz.fizetett. Enélkül a későbbi
         * befizetés a díjat növelné, mert a fő eszköz díja az előleg maradéka:
         * fizetett − csatolt díjak − csatolt külön befizetések − fo_kulon.
         *
         * A "fizetett" mindig a ténylegesen befolyt összeg (díjak + előlegek +
         * végfizetés): kifizetett lapon a kifizetett végösszeg. A "fo_veg"
         * (csatolt eszköznél munkalap_eszkoz.veg) az, amit az eszközre a lapot
         * LEZÁRÓ fizetés könyvelt – ebből vonható vissza pontosan a fizetés,
         * ha a „Fizetve" pipát leveszik (SDH_Muhely_Munkalap::vegfizetes_visszavon).
         *
         * Több eszközös lapon minden eszköznek SAJÁT állapota és saját
         * „fizetve" jelzője van. A fő eszközé itt áll ("fo_allapot",
         * "fo_fizetve", "fo_fizetes_ideje"), a csatoltaké a munkalap_eszkoz
         * táblában. Az üres "fo_allapot" azt jelenti: a lap állapotát
         * követi (a régi lapok mind ilyenek). Az "allapot" ilyenkor a KÖZÖS
         * állapot, amely az eszközökéből adódik
         * (SDH_Muhely_Munkalap::kozos_allapot), a "fizetve" pedig akkor 1,
         * ha minden eszköz ki van fizetve. Egy eszközös lapon minden a régi.
         *
         * A "fajta" mondja meg, mi a lap: 'javitas' (a hagyományos munkalap –
         * a régi sorok mind ilyenek), 'arukiadas' (napi árukiadás: pultos
         * eladás javítás nélkül, eszköz és – ha nem kell – ügyfél nélkül) vagy
         * 'rendeles' (az ügyfélnek rendelt áru). Mindhárom ugyanúgy fizet,
         * számláz és kerül a pénztárba; a sorszámuk közös. Az árukiadás
         * "helyszin_id"-je az üzlet (helyszin tábla), a rendelés
         * "beszallito_id"-je a forrás (beszallito tábla), "rend_allapot"-a a
         * rendelés útja (SDH_Muhely_Eladas::rend_allapotok), a "rend_szam" a
         * beszállítói rendelésszám, a "rend_ertesitve" az ügyfél értesítésének ideje.
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
            fo_kulon decimal(14,2) NOT NULL default 0.00,
            fo_veg decimal(14,2) NOT NULL default 0.00,
            fo_allapot varchar(30) NOT NULL default '',
            fo_fizetve tinyint(1) NOT NULL default 0,
            fo_fizetes_ideje date NULL,
            bevizsgalasi_dij tinyint(1) NOT NULL default 0,
            bevizsgalas_mod varchar(10) NOT NULL default 'auto',
            bevizsgalas_nev varchar(255) NOT NULL default '',
            bevizsgalas_szolg bigint(20) unsigned NOT NULL default 0,
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
            fajta varchar(12) NOT NULL default 'javitas',
            helyszin_id bigint(20) unsigned NOT NULL default 0,
            beszallito_id bigint(20) unsigned NOT NULL default 0,
            rend_allapot varchar(20) NOT NULL default '',
            rend_szam varchar(60) NOT NULL default '',
            rend_ertesitve datetime NULL,
            garancia_nap int(11) NULL,
            garancia_elozo bigint(20) unsigned NOT NULL default 0,
            ajanlat_allapot varchar(12) NOT NULL default '',
            ajanlat_osszeg decimal(14,2) NOT NULL default 0.00,
            ajanlat_kuldve datetime NULL,
            ajanlat_valasz datetime NULL,
            ajanlat_megjegyzes varchar(500) NOT NULL default '',
            atvetel_alairva datetime NULL,
            atvetel_alairo varchar(120) NOT NULL default '',
            elegedettseg_kuldve datetime NULL,
            elegedettseg_csatorna varchar(10) NOT NULL default '',
            elegedettseg tinyint(1) NOT NULL default 0,
            elegedettseg_szoveg varchar(1000) NOT NULL default '',
            elegedettseg_ido datetime NULL,
            PRIMARY KEY  (id),
            unique key munkalap_szam (munkalap_szam),
            key garancia_elozo (garancia_elozo),
            key fajta (fajta),
            key fajta_szam (fajta,munkalap_szam),
            key fajta_keszult (fajta,keszult),
            key fajta_allapot (fajta,allapot),
            key fajta_rend (fajta,rend_allapot),
            key fajta_lezarva (fajta,lezarva),
            key elegedettseg_ido (elegedettseg_ido),
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
         *
         * Az "eszkoz_id" mondja meg, a lap melyik eszközének a hibája:
         * 0 = a fő eszközé (a régi sorok mind ilyenek), különben a lapra
         * csatolt további eszköz azonosítója.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$hiba} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            sorrend int(11) NOT NULL default 0,
            leiras varchar(255) NOT NULL default '',
            javitas text NULL,
            allapot varchar(30) NOT NULL default 'uj',
            eszkoz_id bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            modositva datetime NULL,
            PRIMARY KEY  (id),
            key munkalap_id (munkalap_id),
            key allapot (allapot)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Munkalap – további eszközök
         *
         * Egy munkalaphoz akárhány eszköz csatolható (pl. az ügyfél egyszerre
         * három telefont hoz be). Az ELSŐ, fő eszköz továbbra is a munkalap
         * "eszkoz_id" mezője – a rács, a számla, az RMA és a nyomtatványok
         * erre épülnek, és változatlanul működnek. Ez a tábla csak a
         * TOVÁBBI eszközöket tartja, sorrendben. Egy eszköz egy lapon
         * egyszer szerepelhet (egyedi kulcs).
         *
         * Minden eszköznek saját bevizsgálási díja van: a csatolt eszközé
         * itt áll ("bev_dij", a díjlistából választott díj neve és
         * szolgáltatás-azonosítója: "bev_nev" / "bev_szolg"). A fő eszköz
         * díja a munkalap előlegének (fizetett) maradéka a csatoltak díjai
         * után – egy eszköznél így minden a régi.
         *
         * A "fizetett" az, amit erre az eszközre a díján FELÜL külön
         * befizettek (részfizetés a Fizetés ablakból). A lap előlege
         * (munkalap.fizetett) a teljes összeg; a fő eszközre a maradék jut.
         *
         * Az "allapot" az eszköz saját állapota (üres = a lapét követi),
         * a "fizetve" / "fizetes_ideje" a saját kiegyenlítése – így az egyik
         * készülék elvihető és kifizethető, amíg a másik alkatrészre vár.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$ml_eszkoz} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            eszkoz_id bigint(20) unsigned NOT NULL default 0,
            sorrend int(11) NOT NULL default 0,
            bev_dij decimal(14,2) NOT NULL default 0.00,
            bev_nev varchar(255) NOT NULL default '',
            bev_szolg bigint(20) unsigned NOT NULL default 0,
            fizetett decimal(14,2) NOT NULL default 0.00,
            veg decimal(14,2) NOT NULL default 0.00,
            allapot varchar(30) NOT NULL default '',
            fizetve tinyint(1) NOT NULL default 0,
            fizetes_ideje date NULL,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            unique key lap_eszkoz (munkalap_id,eszkoz_id),
            key eszkoz_id (eszkoz_id)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Munkalap – tételek (szolgáltatások és termékek)
         *
         * A MunkaLap 3 „Szolgáltatások" és „Termékek" lapfülének sorai
         * egy táblában: a kettőt a "tipus" különbözteti meg, minden más
         * mezőjük közös. Az árak nettóban és bruttóban is tárolódnak,
         * mert a pultnál bruttóval dolgoznak, a bizonylat nettót kér.
         * Az "*_ertek" = egységár × mennyiség, kedvezménnyel csökkentve.
         * Az "eszkoz_id" a bevizsgálási díj soránál mondja meg, melyik
         * eszköz díja (0 = a fő eszközé); más tételnél 0.
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
            eszkoz_id bigint(20) unsigned NOT NULL default 0,
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
         * Árlista (0.37): "kategoria" (csoport, pl. „Mobiltelefon"), "kod"
         * (pl. 100, 000-2), "ar_max" (sávos árnál a felső határ; 0 = fix ár),
         * "sorrend" (a beillesztett árlista sorrendje), "bevizsgalas" = a
         * bevizsgálási díjak közül választható. A díjlista a felhasználó
         * adata (beillesztéssel kerül be) – a plugin nem hoz magával árat.
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
            kategoria varchar(160) NOT NULL default '',
            kod varchar(20) NOT NULL default '',
            bevizsgalas tinyint(1) NOT NULL default 0,
            ar_max decimal(14,2) NOT NULL default 0.00,
            sorrend int(11) NOT NULL default 0,
            letrehozva datetime NULL,
            modositva datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY kulcs (kulcs),
            key hasznalat (hasznalat),
            key bevizsgalas (bevizsgalas)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Munkalap – állapotnapló
         *
         * Minden állapotváltás egy sor: mikor, mire, ki váltotta. Az
         * ügyfél az RMA-oldalon ebből látja a javítás menetét.
         * Az "eszkoz_id" a VALÓDI eszköz-azonosító, ha a sor egy eszköz
         * saját állapotváltása (több eszközös lap); 0 = a lap közös állapota.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$naplo} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            allapot varchar(30) NOT NULL default '',
            elozo varchar(30) NOT NULL default '',
            eszkoz_id bigint(20) unsigned NOT NULL default 0,
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
         * "eszkoz_id": a VALÓDI eszköz-azonosító, ha az üzenet a lap egy
         * eszközéről szól; 0 = az egész munkalapról.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$uzenet} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            ugyfel_id bigint(20) unsigned NOT NULL default 0,
            eszkoz_id bigint(20) unsigned NOT NULL default 0,
            irany varchar(4) NOT NULL default 'ki',
            csatorna varchar(20) NOT NULL default 'crm',
            szoveg text NULL,
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            olvasva tinyint(1) NOT NULL default 0,
            email_kuldve tinyint(1) NOT NULL default 0,
            sms_id bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key munkalap_id (munkalap_id),
            key ugyfel_id (ugyfel_id),
            key olvasva (olvasva),
            key sms_id (sms_id)
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
            cel_keszlet decimal(12,3) NOT NULL default 0.000,
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
            eszkoz_id bigint(20) unsigned NOT NULL default 0,
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
         * Levelezés – a levél-feladat ügynök feladatai (0.61.0)
         *
         * Egy sor = egy levél, amire egy szabály ráillett, és amivel
         * teendő van (kinyomtatni a számlát, címkét nyomtatni…).
         * "szabaly": a szabály kulcsa; "adat": a szabály által kinyert
         * adatok JSON-ban (tételek, csatolmány sorszáma…); "allapot":
         * nyitott vagy elintezve. A levél maga a levelezőszerveren
         * marad – itt csak a teendő van nyilvántartva. Az "azonosito"
         * a message_id és a szabály lenyomata: a levél mappák között
         * mozoghat (ilyenkor új level_id-t kap), a feladat ugyanaz marad,
         * és ugyanarra a levélre nem keletkezik második feladat.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$level_feladat} (
            id bigint(20) unsigned NOT NULL auto_increment,
            azonosito char(32) NOT NULL default '',
            level_id bigint(20) unsigned NOT NULL default 0,
            message_id varchar(255) NOT NULL default '',
            fiok varchar(10) NOT NULL default '',
            szabaly varchar(40) NOT NULL default '',
            cim varchar(255) NOT NULL default '',
            felado varchar(190) NOT NULL default '',
            allapot varchar(20) NOT NULL default 'nyitott',
            adat longtext NULL,
            letrehozva datetime NULL,
            elintezve datetime NULL,
            elintezte bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (id),
            unique key azonosito (azonosito),
            key allapot (allapot),
            key szabaly (szabaly),
            key level_id (level_id)
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

        /* ----------------------------------------------------------
         * Házipénztár – tételek
         *
         * Egy sor = egy pénzmozgás (a régi Excel egy sora). "kp" előjeles:
         * a bevétel és a befizetés pozitív, a kivét és a kifizetés negatív –
         * a kassza egyenlege így egyetlen SUM. "kartya" és "utalas" nem
         * érinti a kasszát, csak a forgalmat. "tipus": bevetel, kivet,
         * kifizetes, befizetes, kihagyva (= a munkalap fizetve lett, de
         * kérésre nem került a kasszába – az egyeztetésnél ez is nyom).
         * "munkalap_szam" szöveg, mert a régi táblában több szám is állhat
         * egy sorban; a munkalaphoz kötést a szám adja (az import után
         * átvett MunkaLap 3-lapokra is). Törlés csak jelölés ("torolve").
         * "jelolt": a napló pipája (kipipálva = áthúzva, pl. egyeztetéskor
         * „ezt már megnéztem") – az összegekre nincs hatása.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$penztar} (
            id bigint(20) unsigned NOT NULL auto_increment,
            datum date NOT NULL,
            ido datetime NULL,
            tipus varchar(12) NOT NULL default 'bevetel',
            leiras varchar(255) NOT NULL default '',
            nev varchar(190) NOT NULL default '',
            szemely varchar(80) NOT NULL default '',
            kp decimal(14,2) NOT NULL default 0.00,
            kartya decimal(14,2) NOT NULL default 0.00,
            utalas decimal(14,2) NOT NULL default 0.00,
            info_osszeg decimal(14,2) NOT NULL default 0.00,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            eszkoz_id bigint(20) unsigned NOT NULL default 0,
            munkalap_szam varchar(40) NOT NULL default '',
            szamlaszam varchar(60) NOT NULL default '',
            vonalkod varchar(190) NOT NULL default '',
            megjegyzes varchar(255) NOT NULL default '',
            forras varchar(12) NOT NULL default 'kezi',
            kulso varchar(40) NOT NULL default '',
            sorrend int(11) NOT NULL default 0,
            jelolt tinyint(1) NOT NULL default 0,
            torolve tinyint(1) NOT NULL default 0,
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            modositva datetime NULL,
            PRIMARY KEY  (id),
            key datum (datum),
            key tipus (tipus),
            key munkalap_szam (munkalap_szam),
            key munkalap_id (munkalap_id),
            key szamlaszam (szamlaszam),
            key kulso (kulso)
        ) {$charset};";

        /* ----------------------------------------------------------
         * Házipénztár – napok
         *
         * Napi összesítő és zárás. A számok a tételekből jönnek
         * (SDH_Muhely_Penztar::ujraszamol), itt csak gyorsítótárként
         * állnak, hogy a napló és a riport 30 ezer sor fölött is
         * azonnali legyen. "nyito" = az előző nap záró készpénze (a
         * számolt, ha volt számolás, különben a várható); "zaro" = a
         * rendszer szerinti (várható) készpénz; "szamolt" = a kézzel
         * megszámolt (címletenként: "cimletek" JSON); "elteres" =
         * szamolt − zaro.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$penztar_nap} (
            id bigint(20) unsigned NOT NULL auto_increment,
            datum date NOT NULL,
            allapot varchar(10) NOT NULL default 'nyitott',
            nyito decimal(14,2) NOT NULL default 0.00,
            kp_be decimal(14,2) NOT NULL default 0.00,
            kartya decimal(14,2) NOT NULL default 0.00,
            utalas decimal(14,2) NOT NULL default 0.00,
            kifizetes decimal(14,2) NOT NULL default 0.00,
            kivet decimal(14,2) NOT NULL default 0.00,
            befizetes decimal(14,2) NOT NULL default 0.00,
            zaro decimal(14,2) NOT NULL default 0.00,
            szamolt decimal(14,2) NULL,
            elteres decimal(14,2) NULL,
            cimletek text NULL,
            forgalom decimal(14,2) NOT NULL default 0.00,
            halmozott decimal(16,2) NOT NULL default 0.00,
            tetel_db int(11) NOT NULL default 0,
            megjegyzes text NULL,
            lezarta bigint(20) unsigned NOT NULL default 0,
            lezarva datetime NULL,
            szamolva datetime NULL,
            PRIMARY KEY  (id),
            unique key datum (datum),
            key allapot (allapot)
        ) {$charset};";

        /* ----------------------------------------------------------
         * Házipénztár – változásnapló
         *
         * Minden felvitel, módosítás, törlés, zárás és újranyitás nyoma
         * (ki, mikor, mi volt előtte és utána) – hogy nap végén
         * visszakereshető legyen, hová lett a pénz.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$penztar_naplo} (
            id bigint(20) unsigned NOT NULL auto_increment,
            tetel_id bigint(20) unsigned NOT NULL default 0,
            datum date NULL,
            muvelet varchar(20) NOT NULL default '',
            regi text NULL,
            uj text NULL,
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            ido datetime NULL,
            PRIMARY KEY  (id),
            key tetel_id (tetel_id),
            key datum (datum)
        ) {$charset};";

        /* ----------------------------------------------------------
         * Csapat – belső üzenetek (0.38)
         *
         * Beszélgetés: "tipus" = ketto (két kolléga), egyedi (több kiválasztott
         * kolléga), csoport (egy csapat-csoport minden tagja: "csoport_id"),
         * mindenki (minden aktív kolléga). A tagság és az olvasottság a
         * csapat_tag táblában: "olvasott_id" = az utolsó látott üzenet.
         * Üzenet: "valasz_id" = melyik üzenetre válaszol; "fontos" = felugró
         * ablakban jelenik meg a címzetteknek, amíg nyugtázzák (csapat_nyugta);
         * "kituzve" = a beszélgetés tetején marad („üzenet hagyása");
         * "hivatkozas" = pl. munkalap:123. Törlés csak jelölés.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$cs_besz} (
            id bigint(20) unsigned NOT NULL auto_increment,
            tipus varchar(12) NOT NULL default 'ketto',
            nev varchar(190) NOT NULL default '',
            csoport_id bigint(20) unsigned NOT NULL default 0,
            letrehozo bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            utolso_ido datetime NULL,
            utolso_uzenet_id bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (id),
            key tipus (tipus),
            key csoport_id (csoport_id),
            key utolso_ido (utolso_ido)
        ) {$charset};";

        $definiciok[] = "CREATE TABLE {$cs_tag} (
            id bigint(20) unsigned NOT NULL auto_increment,
            beszelgetes_id bigint(20) unsigned NOT NULL default 0,
            user_id bigint(20) unsigned NOT NULL default 0,
            olvasott_id bigint(20) unsigned NOT NULL default 0,
            nemitva tinyint(1) NOT NULL default 0,
            csatlakozott datetime NULL,
            PRIMARY KEY  (id),
            unique key besz_user (beszelgetes_id,user_id),
            key user_id (user_id)
        ) {$charset};";

        $definiciok[] = "CREATE TABLE {$cs_uzenet} (
            id bigint(20) unsigned NOT NULL auto_increment,
            beszelgetes_id bigint(20) unsigned NOT NULL default 0,
            felado_id bigint(20) unsigned NOT NULL default 0,
            szoveg text NULL,
            valasz_id bigint(20) unsigned NOT NULL default 0,
            fontos tinyint(1) NOT NULL default 0,
            kituzve tinyint(1) NOT NULL default 0,
            hivatkozas varchar(60) NOT NULL default '',
            forras varchar(20) NOT NULL default 'kezi',
            letrehozva datetime NULL,
            modositva datetime NULL,
            torolve tinyint(1) NOT NULL default 0,
            PRIMARY KEY  (id),
            key beszelgetes_id (beszelgetes_id),
            key felado_id (felado_id),
            key fontos (fontos)
        ) {$charset};";

        $definiciok[] = "CREATE TABLE {$cs_nyugta} (
            id bigint(20) unsigned NOT NULL auto_increment,
            uzenet_id bigint(20) unsigned NOT NULL default 0,
            user_id bigint(20) unsigned NOT NULL default 0,
            ido datetime NULL,
            PRIMARY KEY  (id),
            unique key uzenet_user (uzenet_id,user_id),
            key user_id (user_id)
        ) {$charset};";

        $definiciok[] = "CREATE TABLE {$cs_csoport} (
            id bigint(20) unsigned NOT NULL auto_increment,
            nev varchar(120) NOT NULL default '',
            szin varchar(20) NOT NULL default '',
            leiras varchar(255) NOT NULL default '',
            aktiv tinyint(1) NOT NULL default 1,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key aktiv (aktiv)
        ) {$charset};";

        $definiciok[] = "CREATE TABLE {$cs_cstag} (
            id bigint(20) unsigned NOT NULL auto_increment,
            csoport_id bigint(20) unsigned NOT NULL default 0,
            user_id bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (id),
            unique key csoport_user (csoport_id,user_id),
            key user_id (user_id)
        ) {$charset};";

        /* ----------------------------------------------------------
         * Csapat – üzenőfal (0.39, egyfelhasználós mód)
         *
         * Cetlik a falon (mint a Windows Öntapadó jegyzetei): "kinek" a
         * kolléga neve (a Beállításokban megadott névlistából), üres =
         * mindenkinek; "kitol" ki írta; "x" / "y" a helye a fal szélességének
         * / magasságának százalékában, "z" a rétegsorrend. "kesz" = elintézve
         * (áthúzva), "torolve" = levéve a falról (archívum, visszatehető).
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$cs_cetli} (
            id bigint(20) unsigned NOT NULL auto_increment,
            szoveg text NULL,
            kinek varchar(120) NOT NULL default '',
            kitol varchar(120) NOT NULL default '',
            szin varchar(20) NOT NULL default 'sarga',
            x decimal(6,2) NOT NULL default 0.00,
            y decimal(6,2) NOT NULL default 0.00,
            z int(11) NOT NULL default 0,
            kesz tinyint(1) NOT NULL default 0,
            torolve tinyint(1) NOT NULL default 0,
            forras varchar(20) NOT NULL default 'kezi',
            letrehozo bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            modositva datetime NULL,
            PRIMARY KEY  (id),
            key torolve (torolve),
            key kinek (kinek)
        ) {$charset};";

        /* ----------------------------------------------------------
         * AI-asszisztens (0.38)
         *
         * ai_uzenet: a beszélgetés felhasználónként ("szerep": user /
         * assistant / esemeny), "ertekeles" = −1 / 0 / 1 (hüvelykujj).
         * ai_muvelet: minden, amit az asszisztens tenni akar – javaslatként
         * születik, csak emberi jóváhagyással fut le. "szint": hozzaad / atir
         * / torol; az átírás és a törlés erős megerősítést ("kod") kér.
         * "elotte" / "utana" = a rekord teljes másolata (JSON) – ebből lehet
         * visszaállítani. ai_tudas: amit megtanult (kézzel, jóváhagyott
         * javaslatból vagy a verziónaplóból).
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$ai_uzenet} (
            id bigint(20) unsigned NOT NULL auto_increment,
            user_id bigint(20) unsigned NOT NULL default 0,
            szerep varchar(12) NOT NULL default 'user',
            szoveg text NULL,
            oldal varchar(80) NOT NULL default '',
            ertekeles tinyint(4) NOT NULL default 0,
            be_token int(11) NOT NULL default 0,
            ki_token int(11) NOT NULL default 0,
            ido datetime NULL,
            PRIMARY KEY  (id),
            key user_id (user_id),
            key ido (ido)
        ) {$charset};";

        $definiciok[] = "CREATE TABLE {$ai_muvelet} (
            id bigint(20) unsigned NOT NULL auto_increment,
            user_id bigint(20) unsigned NOT NULL default 0,
            eszkoz varchar(40) NOT NULL default '',
            szint varchar(12) NOT NULL default 'hozzaad',
            leiras varchar(255) NOT NULL default '',
            parameterek text NULL,
            allapot varchar(20) NOT NULL default 'javaslat',
            kod varchar(12) NOT NULL default '',
            tabla varchar(60) NOT NULL default '',
            rekord_id bigint(20) unsigned NOT NULL default 0,
            elotte longtext NULL,
            utana longtext NULL,
            eredmeny text NULL,
            letrehozva datetime NULL,
            dontes_ido datetime NULL,
            dontes_user bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (id),
            key user_id (user_id),
            key allapot (allapot)
        ) {$charset};";

        $definiciok[] = "CREATE TABLE {$ai_tudas} (
            id bigint(20) unsigned NOT NULL auto_increment,
            cim varchar(190) NOT NULL default '',
            szoveg text NULL,
            kulcsszavak varchar(255) NOT NULL default '',
            forras varchar(20) NOT NULL default 'kezi',
            mindig tinyint(1) NOT NULL default 0,
            aktiv tinyint(1) NOT NULL default 1,
            letrehozo bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            modositva datetime NULL,
            PRIMARY KEY  (id),
            key aktiv (aktiv)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Helyszínek (üzletek, boltok) – a napi árukiadás ezekhez kötődik.
         * Egy üzletes cégnél elég egy sor (vagy egy sem): ilyenkor a
         * helyszínválasztó meg sem jelenik.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$helyszin} (
            id bigint(20) unsigned NOT NULL auto_increment,
            nev varchar(120) NOT NULL default '',
            cim varchar(255) NOT NULL default '',
            telefon varchar(40) NOT NULL default '',
            megjegyzes varchar(255) NOT NULL default '',
            alap tinyint(1) NOT NULL default 0,
            aktiv tinyint(1) NOT NULL default 1,
            sorrend int(11) NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key aktiv (aktiv)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Beszállítók – honnan jön a rendelt áru. A rendelés űrlapjáról
         * popupban szerkeszthető lista.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$beszallito} (
            id bigint(20) unsigned NOT NULL auto_increment,
            nev varchar(120) NOT NULL default '',
            weboldal varchar(255) NOT NULL default '',
            telefon varchar(40) NOT NULL default '',
            email varchar(190) NOT NULL default '',
            megjegyzes varchar(255) NOT NULL default '',
            aktiv tinyint(1) NOT NULL default 1,
            sorrend int(11) NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key aktiv (aktiv)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Naptár (0.50) – személyek
         *
         * Minden névhez saját naptár tartozik. Többfelhasználós módban a
         * fiókok (profilok) maguktól kapnak egy-egy sort ("user_id"); közös
         * fióknál (egyfelhasználós mód) a nevek kézzel vehetők fel, fiók
         * nélkül ("user_id" = 0). Az "email" az e-mail-értesítések címe.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$np_szemely} (
            id bigint(20) unsigned NOT NULL auto_increment,
            nev varchar(80) NOT NULL default '',
            szin varchar(20) NOT NULL default 'kek',
            email varchar(190) NOT NULL default '',
            user_id bigint(20) unsigned NOT NULL default 0,
            aktiv tinyint(1) NOT NULL default 1,
            sorrend int(11) NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key user_id (user_id),
            key aktiv (aktiv)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Naptár – események
         *
         * Egy sor = egy esemény vagy egy ismétlődő sorozat. Az időpontok
         * helyi idő szerintiek (a WordPress időzónája). "szemely_id" a
         * naptár gazdája, "resztvevok" a többi érintett személy
         * azonosítója vesszővel. Ismétlődés: "ismetles" (napi, heti, havi,
         * eves), "ism_koz" (hányadonként), "ism_napok" (heti: a hét napjai,
         * 1 = hétfő), "ism_veg" (meddig) vagy "ism_db" (hány alkalom),
         * "kivetelek" (kihagyott alkalmak napjai vesszővel).
         * "emlekeztetok": percek a kezdés előtt, vesszővel (0 = kezdéskor).
         * Törlés csak jelölés ("torolve").
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$np_esemeny} (
            id bigint(20) unsigned NOT NULL auto_increment,
            szemely_id bigint(20) unsigned NOT NULL default 0,
            cim varchar(190) NOT NULL default '',
            leiras text NULL,
            hely varchar(190) NOT NULL default '',
            kezdet datetime NULL,
            veg datetime NULL,
            egesz_nap tinyint(1) NOT NULL default 0,
            kategoria varchar(40) NOT NULL default '',
            szin varchar(20) NOT NULL default '',
            prioritas varchar(10) NOT NULL default 'normal',
            allapot varchar(12) NOT NULL default 'tervezett',
            ismetles varchar(10) NOT NULL default '',
            ism_koz int(11) NOT NULL default 1,
            ism_napok varchar(20) NOT NULL default '',
            ism_veg date NULL,
            ism_db int(11) NOT NULL default 0,
            kivetelek text NULL,
            emlekeztetok varchar(120) NOT NULL default '',
            ertesites tinyint(1) NOT NULL default 1,
            email tinyint(1) NOT NULL default 0,
            resztvevok varchar(255) NOT NULL default '',
            ugyfel_id bigint(20) unsigned NOT NULL default 0,
            munkalap_szam varchar(20) NOT NULL default '',
            link varchar(255) NOT NULL default '',
            privat tinyint(1) NOT NULL default 0,
            forras varchar(20) NOT NULL default '',
            foglalas_kulcs varchar(40) NOT NULL default '',
            foglalo_email varchar(190) NOT NULL default '',
            foglalo_telefon varchar(40) NOT NULL default '',
            letrehozta bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            modositva datetime NULL,
            torolve tinyint(1) NOT NULL default 0,
            PRIMARY KEY  (id),
            key szemely_kezdet (szemely_id,kezdet),
            key foglalas_kulcs (foglalas_kulcs),
            key kezdet (kezdet),
            key ismetles (ismetles)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Naptár – jelzések
         *
         * Egy sor = egy esedékessé vált értesítés egy személynek egy
         * alkalomról. "tipus": app (kártya a rendszerben – addig marad,
         * amíg kézzel ki nem nyomják: "kinyomva") vagy email ("kuldve").
         * "perc": hány perccel a kezdés előtt esedékes (0 = kezdéskor).
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$np_jelzes} (
            id bigint(20) unsigned NOT NULL auto_increment,
            esemeny_id bigint(20) unsigned NOT NULL default 0,
            szemely_id bigint(20) unsigned NOT NULL default 0,
            alkalom datetime NULL,
            tipus varchar(10) NOT NULL default 'app',
            perc int(11) NOT NULL default 0,
            esedekes datetime NULL,
            kuldve datetime NULL,
            kinyomva datetime NULL,
            PRIMARY KEY  (id),
            key esemeny (esemeny_id,szemely_id,alkalom),
            key nyitott (tipus,kinyomva)
        ) {$charset};";

        /* -------------------------------------------------------------
         * SMS (0.57) – sablonok
         *
         * "pelda" = a gyári mintasablon (jóváhagyásra vár, inaktív). Egy
         * szerkesztés új verziót ad (sms_sablon_verzio, csak hozzáfűzés); az
         * elküldött üzenet a küldéskori pontos szöveget a sms_uzenet-ben őrzi.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$sms_sablon} (
            id bigint(20) unsigned NOT NULL auto_increment,
            kulcs varchar(60) NOT NULL default '',
            nev varchar(120) NOT NULL default '',
            szoveg text NULL,
            aktiv tinyint(1) NOT NULL default 0,
            pelda tinyint(1) NOT NULL default 0,
            verzio int(11) NOT NULL default 1,
            letrehozva datetime NULL,
            modositva datetime NULL,
            modosito bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (id),
            key kulcs (kulcs),
            key aktiv (aktiv)
        ) {$charset};";

        $definiciok[] = "CREATE TABLE {$sms_verzio} (
            id bigint(20) unsigned NOT NULL auto_increment,
            sablon_id bigint(20) unsigned NOT NULL default 0,
            verzio int(11) NOT NULL default 1,
            nev varchar(120) NOT NULL default '',
            szoveg text NULL,
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key sablon_id (sablon_id,verzio)
        ) {$charset};";

        /* -------------------------------------------------------------
         * SMS – státusz → sablon szabályok
         *
         * "terulet": lap (a munkalap / eszköz állapota) vagy rendeles
         * (a rendelés útja). "mukodes": ki / kerdez (popup) / auto. Egy
         * állapothoz több sablon is tartozhat, de csak egy "alap".
         * "fajta": javitas / arukiadas / rendeles / mind. "feltetel": üres
         * vagy ar_megadva, alkatresz_hianyzik.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$sms_szabaly} (
            id bigint(20) unsigned NOT NULL auto_increment,
            terulet varchar(12) NOT NULL default 'lap',
            allapot varchar(30) NOT NULL default '',
            sablon_id bigint(20) unsigned NOT NULL default 0,
            alap tinyint(1) NOT NULL default 0,
            mukodes varchar(10) NOT NULL default 'kerdez',
            kesleltetes int(11) NOT NULL default 0,
            fajta varchar(12) NOT NULL default 'mind',
            feltetel varchar(30) NOT NULL default '',
            PRIMARY KEY  (id),
            key allapot (terulet,allapot),
            key sablon_id (sablon_id)
        ) {$charset};";

        /* -------------------------------------------------------------
         * SMS – üzenetek (kimenő ÉS bejövő), a teljes napló
         *
         * Sor nem törlődik, a küldött szöveg nem íródik felül. "irany": ki/be.
         * "tipus": ugyfel vagy onteszt (külön, hogy ne keveredjen). "kivalto":
         * auto / popup / kezi / onteszt / bejovo. "allapot": varakozik, kuldve_atjarora,
         * pending, processed, sent, delivered, failed, manualis (minden próba
         * elbukott), nincs_szam, kihagyva, fuggo, szimulalt, letiltva.
         * "kulcs": a bejövő webhook-esemény azonosítója (kétszer nem rögzül).
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$sms_uzenet} (
            id bigint(20) unsigned NOT NULL auto_increment,
            irany varchar(4) NOT NULL default 'ki',
            tipus varchar(10) NOT NULL default 'ugyfel',
            kivalto varchar(12) NOT NULL default 'kezi',
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            eszkoz_id bigint(20) unsigned NOT NULL default 0,
            ugyfel_id bigint(20) unsigned NOT NULL default 0,
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            felhasznalo_nev varchar(120) NOT NULL default '',
            szam varchar(24) NOT NULL default '',
            szam_eredeti varchar(60) NOT NULL default '',
            szam_forras varchar(12) NOT NULL default '',
            sablon_id bigint(20) unsigned NOT NULL default 0,
            sablon_verzio int(11) NOT NULL default 0,
            szoveg text NULL,
            szegmens int(11) NOT NULL default 0,
            allapot varchar(20) NOT NULL default 'varakozik',
            hiba varchar(255) NOT NULL default '',
            atjaro_id varchar(80) NOT NULL default '',
            proba int(11) NOT NULL default 0,
            utolso_proba datetime NULL,
            kovetkezo datetime NULL,
            elkuldve datetime NULL,
            kezbesitve datetime NULL,
            kulcs varchar(100) NULL,
            olvasva tinyint(1) NOT NULL default 1,
            parositva tinyint(1) NOT NULL default 1,
            hivott datetime NULL,
            hivo bigint(20) unsigned NOT NULL default 0,
            kovetes_token varchar(24) NOT NULL default '',
            megnyitva datetime NULL,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            unique key kulcs (kulcs),
            key allapot (allapot,kovetkezo),
            key munkalap_id (munkalap_id),
            key ugyfel_id (ugyfel_id),
            key atjaro_id (atjaro_id),
            key szam (szam),
            key irany (irany,olvasva)
        ) {$charset};";

        /* SMS – állapotátmenetek és események: csak hozzáfűzés. */
        $definiciok[] = "CREATE TABLE {$sms_esemeny} (
            id bigint(20) unsigned NOT NULL auto_increment,
            sms_id bigint(20) unsigned NOT NULL default 0,
            tipus varchar(12) NOT NULL default 'allapot',
            allapot varchar(20) NOT NULL default '',
            reszlet varchar(255) NOT NULL default '',
            kulcs varchar(100) NULL,
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            unique key kulcs (kulcs),
            key sms_id (sms_id)
        ) {$charset};";

        /* SMS – függő és lezárt döntések (státuszváltás után: küldök / nem küldök / később). */
        $definiciok[] = "CREATE TABLE {$sms_dontes} (
            id bigint(20) unsigned NOT NULL auto_increment,
            munkalap_id bigint(20) unsigned NOT NULL default 0,
            eszkoz_id bigint(20) unsigned NOT NULL default 0,
            terulet varchar(12) NOT NULL default 'lap',
            allapot varchar(30) NOT NULL default '',
            elozo varchar(30) NOT NULL default '',
            sablon_id bigint(20) unsigned NOT NULL default 0,
            dontes varchar(12) NOT NULL default 'fuggo',
            ok varchar(255) NOT NULL default '',
            sms_id bigint(20) unsigned NOT NULL default 0,
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            lezarva datetime NULL,
            PRIMARY KEY  (id),
            key munkalap_id (munkalap_id),
            key nyitott (lezarva)
        ) {$charset};";

        /* SMS – önteszt (külön a vevő-SMS-ektől). */
        $definiciok[] = "CREATE TABLE {$sms_onteszt} (
            id bigint(20) unsigned NOT NULL auto_increment,
            tipus varchar(12) NOT NULL default 'atjaro',
            eredmeny varchar(8) NOT NULL default 'ok',
            reszlet varchar(255) NOT NULL default '',
            sms_id bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key tipus (tipus,letrehozva)
        ) {$charset};";

        /* SMS – követő link megnyitásai (nincs IP, nincs eszközadat). */
        $definiciok[] = "CREATE TABLE {$sms_link} (
            id bigint(20) unsigned NOT NULL auto_increment,
            sms_id bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key sms_id (sms_id)
        ) {$charset};";

        /* SMS – a leiratkozás / tiltás naplója (az aktuális állapot: ugyfel.sms_tilt). */
        $definiciok[] = "CREATE TABLE {$sms_leir} (
            id bigint(20) unsigned NOT NULL auto_increment,
            ugyfel_id bigint(20) unsigned NOT NULL default 0,
            tiltva tinyint(1) NOT NULL default 1,
            ok varchar(255) NOT NULL default '',
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            key ugyfel_id (ugyfel_id)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Változásnapló (0.59) – csak hozzáfűzés
         *
         * Minden módosítás egy sor: melyik tábla melyik sora (tabla, sor_id),
         * melyik rekordhoz tartozik (fo_tipus / fo_id – pl. a tétel a
         * munkalapjához), a kapcsolt munkalap (ml_id), a mezőnkénti régi és
         * új érték (valtozas, JSON), ki (felhasznalo + a neve akkor), honnan
         * (forras: felulet / ai / idozitett / ugyfeloldal…), melyik művelettel
         * (akcio), és a sor HMAC-aláírása (hash). Egy kérés sorai ugyanazt a
         * "keres" azonosítót kapják. A sorokat a rendszer soha nem írja át és
         * nem törli (SDH_Muhely_Valtozasnaplo).
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$naplo_v} (
            id bigint(20) unsigned NOT NULL auto_increment,
            ido datetime NULL,
            keres varchar(16) NOT NULL default '',
            muvelet varchar(12) NOT NULL default '',
            tabla varchar(40) NOT NULL default '',
            sor_id bigint(20) unsigned NOT NULL default 0,
            fo_tipus varchar(30) NOT NULL default '',
            fo_id bigint(20) unsigned NOT NULL default 0,
            ml_id bigint(20) unsigned NOT NULL default 0,
            valtozas longtext NULL,
            osszegzes varchar(255) NOT NULL default '',
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            felhasznalo_nev varchar(190) NOT NULL default '',
            forras varchar(20) NOT NULL default '',
            akcio varchar(80) NOT NULL default '',
            megjegyzes varchar(255) NOT NULL default '',
            ip varchar(45) NOT NULL default '',
            hash char(64) NOT NULL default '',
            PRIMARY KEY  (id),
            key fo (fo_tipus,fo_id),
            key ml_id (ml_id),
            key tabla_sor (tabla,sor_id),
            key ido (ido),
            key keres (keres),
            key felhasznalo (felhasznalo)
        ) {$charset};";

        /* ----------------------------------------------------------
         * Mezőmemória (0.63.0)
         *
         * Amit valaki egy mezőbe beírt vagy keresett, azt a rendszer
         * megjegyzi, és legközelebb gépelés közben felajánlja. "kulcs" =
         * melyik mező (pl. „szamla:vevo[cim]", „tetelnev"); "lenyomat" a
         * kisbetűs érték md5-je (ugyanaz az érték egyszer szerepel, a "db"
         * nő). Jelszó, kulcs, kód, kártyaszám soha nem kerül ide.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$mezo_emlek} (
            id bigint(20) unsigned NOT NULL auto_increment,
            kulcs varchar(120) NOT NULL default '',
            lenyomat char(32) NOT NULL default '',
            ertek varchar(190) NOT NULL default '',
            db int(10) unsigned NOT NULL default 1,
            utolso datetime NULL,
            PRIMARY KEY  (id),
            unique key kulcs_ertek (kulcs,lenyomat),
            key kulcs_db (kulcs,db)
        ) {$charset};";

        /* -------------------------------------------------------------
         * Adatátvétel (U1, 0.94.0): melyik átvétel melyik sort hozta
         * létre – ebből vonható vissza egy átvétel.
         * ---------------------------------------------------------- */
        $definiciok[] = "CREATE TABLE {$atvetel_sor} (
            id bigint(20) unsigned NOT NULL auto_increment,
            import_id varchar(24) NOT NULL default '',
            tabla varchar(20) NOT NULL default '',
            sor_id bigint(20) unsigned NOT NULL default 0,
            PRIMARY KEY  (id),
            key import_tabla (import_id,tabla)
        ) {$charset};";

        return $definiciok;
    }
}
