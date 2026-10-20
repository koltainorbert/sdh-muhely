<?php
/**
 * SDH Értékesítés – adatbázis-séma (ARCHITEKTURA.md 4.). Új tábla / mező: itt, és a DB_VERSION emelése.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Schema
{
    public const DB_VERSION = '1.0.1';
    private const OPT = 'sdh_sales_db';

    public static function init(): void
    {
        add_action('plugins_loaded', [self::class, 'sema'], 5);
    }

    public static function t(string $nev): string
    {
        global $wpdb;

        return $wpdb->prefix . 'sdh_sales_' . $nev;
    }

    /** @return array<int, string> a bővítmény táblái (mentés / export / teszt) */
    public static function tablak(): array
    {
        return ['rendeles', 'fizetes', 'tartalom', 'naplo', 'lead', 'foglalas', 'korlat', 'suti', 'szikra', 'fiok_link'];
    }

    public static function sema(bool $kenyszer = false): void
    {
        if (!$kenyszer && get_option(self::OPT) === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();

        dbDelta('CREATE TABLE ' . self::t('rendeles') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            azon varchar(30) NOT NULL default '',
            allapot varchar(20) NOT NULL default 'uj',
            tipus varchar(20) NOT NULL default 'uj',
            vevo longtext NULL,
            tetelek longtext NULL,
            netto bigint(20) NOT NULL default 0,
            afa bigint(20) NOT NULL default 0,
            brutto bigint(20) NOT NULL default 0,
            fizetett bigint(20) NOT NULL default 0,
            penznem varchar(3) NOT NULL default 'HUF',
            ciklus varchar(12) NOT NULL default 'eves',
            kupon varchar(40) NOT NULL default '',
            fizmod varchar(20) NOT NULL default 'atutalas',
            kozlemeny varchar(40) NOT NULL default '',
            email varchar(190) NOT NULL default '',
            licenc_kulcs varchar(40) NOT NULL default '',
            kozpont_licenc_id bigint(20) unsigned NOT NULL default 0,
            szamla_dijbekero varchar(60) NOT NULL default '',
            szamla_vegszamla varchar(60) NOT NULL default '',
            lanc longtext NULL,
            user_id bigint(20) unsigned NOT NULL default 0,
            ip_hash varchar(32) NOT NULL default '',
            letrehozva datetime NULL,
            modositva datetime NULL,
            fizetve_ido datetime NULL,
            hatarido date NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY azon (azon),
            KEY allapot (allapot),
            KEY kozlemeny (kozlemeny),
            KEY email (email),
            KEY licenc_kulcs (licenc_kulcs)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('fizetes') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            rendeles_id bigint(20) unsigned NOT NULL default 0,
            forras varchar(20) NOT NULL default '',
            tranzakcio varchar(120) NOT NULL default '',
            osszeg bigint(20) NOT NULL default 0,
            ido datetime NULL,
            nyers longtext NULL,
            allapot varchar(20) NOT NULL default 'ok',
            csatorna varchar(20) NOT NULL default '',
            datum date NULL,
            lenyomat varchar(64) NOT NULL default '',
            parositva bigint(20) NOT NULL default 0,
            PRIMARY KEY  (id),
            UNIQUE KEY forras_tranz (forras, tranzakcio),
            KEY rendeles_id (rendeles_id),
            KEY lenyomat (lenyomat)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('tartalom') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            tipus varchar(30) NOT NULL default '',
            kulcs varchar(120) NOT NULL default '',
            nyelv varchar(8) NOT NULL default 'hu',
            cim varchar(255) NOT NULL default '',
            adat longtext NULL,
            sorrend int(11) NOT NULL default 0,
            aktiv tinyint(1) NOT NULL default 1,
            szerkesztve tinyint(1) NOT NULL default 0,
            modositva datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tkn (tipus, kulcs, nyelv),
            KEY tipus_sorrend (tipus, sorrend)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('naplo') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            ido datetime NULL,
            tipus varchar(30) NOT NULL default '',
            szint varchar(10) NOT NULL default 'info',
            szoveg varchar(500) NOT NULL default '',
            ref_tipus varchar(30) NOT NULL default '',
            ref_id bigint(20) unsigned NOT NULL default 0,
            elotte longtext NULL,
            utana longtext NULL,
            ki varchar(120) NOT NULL default '',
            PRIMARY KEY  (id),
            KEY ido (ido),
            KEY ref (ref_tipus, ref_id)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('lead') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            forras varchar(20) NOT NULL default '',
            email varchar(190) NOT NULL default '',
            adat longtext NULL,
            allapot varchar(20) NOT NULL default 'uj',
            ido datetime NULL,
            ip_hash varchar(32) NOT NULL default '',
            PRIMARY KEY  (id),
            KEY forras (forras),
            KEY email (email)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('foglalas') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            tipus varchar(20) NOT NULL default '',
            kezdet datetime NULL,
            veg datetime NULL,
            nev varchar(190) NOT NULL default '',
            email varchar(190) NOT NULL default '',
            telefon varchar(60) NOT NULL default '',
            ceg varchar(190) NOT NULL default '',
            megjegyzes text NULL,
            allapot varchar(20) NOT NULL default 'foglalt',
            token varchar(64) NOT NULL default '',
            rendeles_id bigint(20) unsigned NOT NULL default 0,
            google_id varchar(190) NOT NULL default '',
            emlekezteto_kuldve tinyint(1) NOT NULL default 0,
            ido datetime NULL,
            PRIMARY KEY  (id),
            KEY kezdet (kezdet),
            KEY token (token)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('korlat') . " (
            kulcs varchar(120) NOT NULL default '',
            db int(11) NOT NULL default 0,
            lejar datetime NULL,
            PRIMARY KEY  (kulcs),
            KEY lejar (lejar)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('suti') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            ido datetime NULL,
            azon varchar(40) NOT NULL default '',
            kategoriak varchar(255) NOT NULL default '',
            verzio varchar(20) NOT NULL default '',
            ip_hash varchar(32) NOT NULL default '',
            PRIMARY KEY  (id),
            KEY azon (azon)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('szikra') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            ido datetime NULL,
            munkamenet varchar(40) NOT NULL default '',
            szerep varchar(12) NOT NULL default '',
            szoveg mediumtext NULL,
            token_be int(11) NOT NULL default 0,
            token_ki int(11) NOT NULL default 0,
            ip_hash varchar(32) NOT NULL default '',
            PRIMARY KEY  (id),
            KEY munkamenet (munkamenet),
            KEY ido (ido)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('fiok_link') . " (
            token_hash varchar(64) NOT NULL default '',
            email varchar(190) NOT NULL default '',
            user_id bigint(20) unsigned NOT NULL default 0,
            cel varchar(255) NOT NULL default '',
            lejar datetime NULL,
            PRIMARY KEY  (token_hash),
            KEY lejar (lejar)
        ) {$c};");

        do_action('sdh_sales_sema', self::DB_VERSION);

        update_option(self::OPT, self::DB_VERSION, true);
    }
}
