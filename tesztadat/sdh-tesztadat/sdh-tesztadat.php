<?php
/**
 * Plugin Name:       SDH Tesztadat
 * Description:       Tömeges, kitalált próbaadat az SDH Műhelyhez (alapból 300 000 ügyfél, eszköz, termék, szolgáltatás, munkalap és pénztártétel). Bekapcsolva TESZTÜZEM: SMS, e-mail, számla és külső mentés nem megy ki. Kikapcsoláskor minden általa létrehozott adatot töröl – a többihez nem nyúl.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  sdh-muhely
 * Author:            InfoStudio Hungary – Koltai Norbert
 * Text Domain:       sdh-tesztadat
 *
 * CSAK PRÓBAPÉLDÁNYRA (pl. a LocalWP-oldal klónjára), soha nem az éles rendszerre.
 *
 * Működés:
 *  - Beállítások › Rendszer › Tesztadat: méret választása, „Generálás indítása” (kétlépcsős gomb). A böngésző
 *    rövid (kb. 4 mp-es) adagokban hívja a szervert, a sáv mutatja, hol tart. Megszakadás után a Folytatás
 *    onnan megy tovább, ahol abbamaradt (a szerver minden adagnál az adatbázisból számolja ki, hol tart).
 *  - Minden sor jelölt: forras = 'sdhteszt' + kulso_azonosito = 'sdhteszt-…' (szolgáltatásnál a megjegyzés),
 *    és az azonosítója a generáláskor lefoglalt tartományban van. A törlés csak a kettő metszetét viszi el.
 *  - Kikapcsoláskor (vagy a „Tesztadat törlése” gombbal, adagokban, sávval) minden saját sor törlődik, a
 *    munkalapokhoz később kapcsolt sorokkal együtt (hibák, napló, üzenetek, SMS-ek, készletmozgások).
 *  - Bekapcsolva tesztüzem (SDH Műhely 0.98.0+): `sdh_muhely_tesztuzem` szűrő → SMS csak szimuláció, e-mail nem
 *    megy ki, Számlázz.hu és Google (Drive) tiltva, nincs napi automatikus mentés, a felületen „Tesztüzem” sáv.
 *  - Emberek: 85% kitalált magyar név (magyar címmel), 15% külföldi (fele külföldi címmel). E-mail-címek csak
 *    a fenntartott example.com / .net / .org tartományban – ezek soha nem kézbesíthetők.
 *
 * @package SDH_Tesztadat
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Tesztadat
{
    public const VERZIO = '1.0.0';
    public const OPT    = 'sdh_tesztadat_allapot';
    public const JEL    = 'sdhteszt';
    public const MEGJ   = '[sdh-tesztadat]';
    public const MERETEK = [1000, 10000, 100000, 300000];

    /** Egy kérés legfeljebb ennyi másodpercig dolgozik (utána a böngésző újra hív). */
    private const IDOKERET = 4.0;

    /** Egy INSERT ennyi sort visz (munkalapnál a tételek miatt kevesebbet). */
    private const KOTEG = 1000;

    /** @var array<string, array<int, mixed>>|null */
    private static ?array $tar = null;

    public static function init(): void
    {
        // Tesztüzem: amíg ez a kiegészítő be van kapcsolva, semmi nem megy ki valódi embernek / szolgáltatásnak.
        add_filter('sdh_muhely_tesztuzem', '__return_true');

        add_action('init', [self::class, 'lapful'], 40);
        add_action('wp_ajax_sdh_tesztadat_lepes', [self::class, 'ajax_lepes']);
        add_action('wp_ajax_sdh_tesztadat_torles', [self::class, 'ajax_torles']);
        add_action('admin_notices', [self::class, 'admin_jelzes']);
    }

    /* =================================================================
     * Környezet
     * ============================================================== */

    public static function muhely_kesz(): bool
    {
        return class_exists('SDH_Muhely_Schema') && class_exists('SDH_Muhely_Halozat') && method_exists('SDH_Muhely_Halozat', 'tesztuzem');
    }

    private static function t(string $nev): string
    {
        return SDH_Muhely_Schema::tabla($nev);
    }

    /** @return array<string, mixed> */
    public static function allapot(): array
    {
        $a = get_option(self::OPT, []);

        return is_array($a) ? $a : [];
    }

    private static function allapot_ment(array $a): void
    {
        update_option(self::OPT, $a, false);
    }

    private static function jogos(): bool
    {
        return current_user_can('manage_options') || (class_exists('SDH_Muhely_Admin_UI') && SDH_Muhely_Admin_UI::admin_e());
    }

    /**
     * SQLite-on (konténeres tesztkörnyezet) közvetlenül PDO-n írunk – a WordPress SQLite-fordítója ezer soros
     * INSERT-nél másodperceket vesz el. MySQL / MariaDB: a megszokott $wpdb.
     */
    private static function fut(string $sql): void
    {
        global $wpdb;

        $pdo = self::pdo();

        if ($pdo !== null) {
            $pdo->exec($sql);

            return;
        }

        if ($wpdb->query($sql) === false) {
            throw new RuntimeException('Adatbázis-hiba: ' . (string) $wpdb->last_error);
        }
    }

    private static function pdo(): ?PDO
    {
        global $wpdb;

        $p  = null;
        $dh = $wpdb->dbh ?? null;

        if (is_object($dh) && method_exists($dh, 'get_pdo')) {
            $p = $dh->get_pdo();
        } elseif (is_object($dh) && method_exists($dh, 'get_connection')) {
            // Újabb SQLite-illesztő: a PDO a kapcsolat-objektumon át érhető el.
            $k = $dh->get_connection();
            $p = is_object($k) && method_exists($k, 'get_pdo') ? $k->get_pdo() : null;
        }

        if ($p instanceof PDO) {
            $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $p;
        }

        return null;
    }

    private static function tranzakcio(callable $f): void
    {
        $pdo = self::pdo();
        $pdo !== null ? $pdo->beginTransaction() : self::fut('START TRANSACTION');

        try {
            SDH_Muhely_Valtozasnaplo::csendben($f);
            $pdo !== null ? $pdo->commit() : self::fut('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo !== null ? $pdo->rollBack() : self::fut('ROLLBACK');
            } catch (Throwable $e2) {
                unset($e2);
            }

            throw $e;
        }
    }

    /** Idézőjeles SQL-szöveg: SQLite-on a PDO saját idézése (ott a \\' nem érvényes), MySQL-en esc_sql. */
    private static function q(string $s): string
    {
        $pdo = self::pdo();

        return $pdo !== null ? (string) $pdo->quote($s) : "'" . esc_sql($s) . "'";
    }

    private static function szam_sql(float $f): string
    {
        return number_format($f, 2, '.', '');
    }

    /* =================================================================
     * Szakaszok
     * ============================================================== */

    /** @return array<string, array{cim: string, tabla: string}> */
    public static function szakaszok(): array
    {
        return [
            'ugyfel'       => ['cim' => 'Ügyfelek', 'tabla' => 'ugyfel'],
            'eszkoz'       => ['cim' => 'Eszközök', 'tabla' => 'eszkoz'],
            'termek'       => ['cim' => 'Termékek', 'tabla' => 'termek'],
            'szolgaltatas' => ['cim' => 'Szolgáltatások', 'tabla' => 'szolgaltatas'],
            'munkalap'     => ['cim' => 'Munkalapok (javítás, árukiadás, rendelés)', 'tabla' => 'munkalap'],
            'tetel'        => ['cim' => 'Munkalap-tételek és hibák', 'tabla' => 'munkalap_tetel'],
            'penztar'      => ['cim' => 'Pénztártételek', 'tabla' => 'penztar'],
        ];
    }

    /** A szakasz kész darabszáma – mindig az adatbázisból (így a megszakadt kérés után is pontos). */
    public static function kesz_db(string $sz, array $a): int
    {
        global $wpdb;

        $alap = (int) ($a['alap'][$sz] ?? 0);

        if ($alap <= 0) {
            return 0;
        }

        if ($sz === 'tetel') {
            $l   = (int) ($a['alap']['munkalap'] ?? 0);
            $max = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(munkalap_id) FROM ' . self::t('munkalap_tetel') . ' WHERE munkalap_id >= %d', $l));

            return $max >= $l ? $max - $l + 1 : 0;
        }

        $max = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM ' . self::t(self::szakaszok()[$sz]['tabla']) . ' WHERE id >= %d', $alap));

        return $max >= $alap ? min((int) $a['cel'], $max - $alap + 1) : 0;
    }

    /* =================================================================
     * Indítás és egy adag
     * ============================================================== */

    /** Az azonosító-tartományok lefoglalása (a mostani legnagyobb + 1). */
    private static function indit(int $cel): array
    {
        global $wpdb;

        $alap = [];

        foreach (self::szakaszok() as $sz => $d) {
            if ($sz === 'tetel') {
                $alap[$sz] = 1;
                continue;
            }

            $alap[$sz] = 1 + (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . self::t($d['tabla']));
        }

        // A kapcsolt táblákban maradhat „árva” sor korábban törölt rekordokra (pl. ügyfél szó-indexe, munkalap tétele):
        // a tartomány ezek fölött kezdődjön, különben ütközne, és a törlés is hozzájuk nyúlna.
        $arva = [
            'ugyfel'   => [['ugyfel_szo', 'ugyfel_id'], ['eszkoz', 'ugyfel_id'], ['munkalap', 'ugyfel_id']],
            'eszkoz'   => [['munkalap', 'eszkoz_id'], ['munkalap_eszkoz', 'eszkoz_id']],
            'termek'   => [['termek_mozgas', 'termek_id'], ['munkalap_tetel', 'termek_id']],
            'munkalap' => [['munkalap_tetel', 'munkalap_id'], ['munkalap_hiba', 'munkalap_id'], ['munkalap_eszkoz', 'munkalap_id'], ['munkalap_naplo', 'munkalap_id'], ['uzenet', 'munkalap_id'], ['sms_uzenet', 'munkalap_id'], ['termek_mozgas', 'munkalap_id'], ['penztar', 'munkalap_id']],
        ];

        foreach ($arva as $sz => $lista) {
            foreach ($lista as [$tabla, $oszlop]) {
                $alap[$sz] = max($alap[$sz], 1 + (int) $wpdb->get_var('SELECT COALESCE(MAX(' . $oszlop . '), 0) FROM ' . self::t($tabla)));
            }
        }

        $felhasznalok = array_map('intval', get_users(['fields' => 'ID', 'number' => 25, 'orderby' => 'ID']));

        $a = [
            'cel'       => $cel,
            'alap'      => $alap,
            'lapszam'   => 1 + (int) $wpdb->get_var('SELECT COALESCE(MAX(munkalap_szam), 0) FROM ' . self::t('munkalap')),
            'felelosok' => $felhasznalok ?: [get_current_user_id()],
            'kezdet'    => time(),
            'veg'       => 0,
            'szakasz'   => 'ugyfel',
            'verzio'    => self::VERZIO,
        ];

        self::allapot_ment($a);

        return $a;
    }

    /**
     * Egy adag munka (legfeljebb IDOKERET másodperc).
     *
     * @return array<string, mixed> a felületnek: hol tart
     */
    public static function lepes(?int $cel = null, float $idokeret = self::IDOKERET): array
    {
        $a = self::allapot();

        if (get_option(self::OPT_TORLES)) {
            throw new RuntimeException('Törlés van folyamatban – előbb fejezd be a „Tesztadat törlése” gombbal.');
        }

        if (($a['cel'] ?? 0) <= 0) {
            if ($cel === null) {
                return self::jelentes($a);
            }

            $a = self::indit($cel);
        }

        $kezd = microtime(true);
        @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        // A beállításokból olvasott listák előre: a tranzakción belül már ne kelljen a beállításokhoz nyúlni.
        self::tar();
        SDH_Muhely_Munkalap::allapotok();
        SDH_Muhely_Munkalap::hiba_allapotok();

        if (class_exists('SDH_Muhely_Eladas')) {
            SDH_Muhely_Eladas::rend_allapotok();
        }

        foreach (array_keys(self::szakaszok()) as $sz) {
            $kesz = self::kesz_db($sz, $a);

            while ($kesz < (int) $a['cel']) {
                if (microtime(true) - $kezd > $idokeret) {
                    $a['szakasz'] = $sz;
                    self::allapot_ment($a);

                    return self::jelentes($a);
                }

                $db = min($sz === 'munkalap' || $sz === 'tetel' ? 500 : self::KOTEG, (int) $a['cel'] - $kesz);
                $gen = 'gen_' . $sz;
                self::tranzakcio(static function () use ($gen, $a, $kesz, $db): void {
                    self::$gen($a, $kesz, $db);
                });
                $kesz += $db;
            }
        }

        if (empty($a['veg'])) {
            self::befejez($a);
        }

        return self::jelentes(self::allapot());
    }

    private static function befejez(array $a): void
    {
        global $wpdb;

        foreach (self::szakaszok() as $d) {
            $wpdb->query((self::pdo() !== null ? 'ANALYZE ' : 'ANALYZE TABLE ') . self::t($d['tabla'])); // phpcs:ignore
        }

        if (class_exists('SDH_Muhely_Gyorsitotar')) {
            SDH_Muhely_Gyorsitotar::ervenytelenit();
        }

        $a['veg']     = time();
        $a['szakasz'] = '';
        self::allapot_ment($a);
    }

    /** @return array<string, mixed> */
    public static function jelentes(array $a): array
    {
        $cel  = (int) ($a['cel'] ?? 0);
        $sorok = [];
        $ossz = 0;

        foreach (self::szakaszok() as $sz => $d) {
            $k = $cel > 0 ? self::kesz_db($sz, $a) : 0;
            $ossz += $k;
            $sorok[] = ['kulcs' => $sz, 'cim' => $d['cim'], 'kesz' => $k, 'cel' => $cel];
        }

        $teljes   = $cel * count(self::szakaszok());
        $arany    = $teljes > 0 ? $ossz / $teljes : 0.0;
        $eltelt   = $cel > 0 ? max(0, (int) (($a['veg'] ?: time()) - (int) ($a['kezdet'] ?? time()))) : 0;
        $hatra    = ($arany > 0.01 && $arany < 1) ? (int) round($eltelt / $arany - $eltelt) : 0;

        return [
            'cel'      => $cel,
            'sorok'    => $sorok,
            'szazalek' => round($arany * 100, 1),
            'eltelt'   => $eltelt,
            'hatra'    => $hatra,
            'kesz'     => $cel > 0 && !empty($a['veg']),
            'torles'   => (bool) get_option(self::OPT_TORLES),
        ];
    }

    /* =================================================================
     * Adatforrások (kitalált nevek, valódi irányítószámok)
     * ============================================================== */

    /** @return array<string, array<int, mixed>> */
    private static function tar(): array
    {
        if (self::$tar !== null) {
            return self::$tar;
        }

        $t = [];

        $t['vnev'] = ['Nagy', 'Kovács', 'Tóth', 'Szabó', 'Horváth', 'Varga', 'Kiss', 'Molnár', 'Németh', 'Farkas', 'Balogh', 'Papp', 'Takács', 'Juhász', 'Lakatos', 'Mészáros', 'Oláh', 'Simon', 'Rácz', 'Fekete', 'Szilágyi', 'Török', 'Fehér', 'Balázs', 'Gál', 'Kis', 'Szűcs', 'Kocsis', 'Pintér', 'Fodor', 'Orsós', 'Szalai', 'Sipos', 'Magyar', 'Lukács', 'Gulyás', 'Biró', 'Király', 'Katona', 'László', 'Jakab', 'Bogdán', 'Hegedűs', 'Illés', 'Vincze', 'Bodnár', 'Sándor', 'Antal', 'Pál', 'Somogyi', 'Fülöp', 'Bakos', 'Bálint', 'Boros', 'Csizmadia', 'Deák', 'Dudás', 'Erdős', 'Fazekas', 'Gergely', 'Hajdu', 'Halász', 'Hegyi', 'Hollósi', 'Jónás', 'Kelemen', 'Kozma', 'Kun', 'Lengyel', 'Lőrincz', 'Major', 'Márton', 'Mohácsi', 'Nemes', 'Orbán', 'Pásztor', 'Péter', 'Pataki', 'Rostás', 'Sárközi', 'Soós', 'Szekeres', 'Szénási', 'Szőke', 'Tamás', 'Tímár', 'Vass', 'Veres', 'Vida', 'Zsigmond', 'Barta', 'Benkő', 'Bognár', 'Czakó', 'Dobos', 'Égető', 'Füredi', 'Gyenes', 'Hámori', 'Kántor'];
        $t['knev_f'] = ['László', 'István', 'József', 'János', 'Zoltán', 'Sándor', 'Gábor', 'Ferenc', 'Attila', 'Péter', 'Tamás', 'Zsolt', 'Tibor', 'András', 'Csaba', 'Imre', 'Lajos', 'György', 'Balázs', 'Gyula', 'Mihály', 'Róbert', 'Norbert', 'Dániel', 'Ádám', 'Krisztián', 'Bence', 'Máté', 'Dávid', 'Levente', 'Gergő', 'Márk', 'Kristóf', 'Milán', 'Bálint', 'Botond', 'Olivér', 'Dominik', 'Marcell', 'Barnabás', 'Benedek', 'Zalán', 'Noel', 'Áron', 'Hunor'];
        $t['knev_n'] = ['Mária', 'Erzsébet', 'Katalin', 'Éva', 'Ilona', 'Anna', 'Zsuzsanna', 'Margit', 'Judit', 'Ágnes', 'Andrea', 'Erika', 'Krisztina', 'Edit', 'Gabriella', 'Szilvia', 'Anita', 'Mónika', 'Tímea', 'Eszter', 'Viktória', 'Nikolett', 'Réka', 'Dóra', 'Petra', 'Fanni', 'Lilla', 'Boglárka', 'Hanna', 'Zsófia', 'Luca', 'Jázmin', 'Lili', 'Emma', 'Zoé', 'Laura', 'Kitti', 'Noémi', 'Bianka', 'Orsolya', 'Henrietta', 'Rita', 'Csilla', 'Beáta', 'Enikő'];
        $t['utca'] = ['Kossuth Lajos', 'Petőfi Sándor', 'Széchenyi István', 'Ady Endre', 'Rákóczi Ferenc', 'Arany János', 'Dózsa György', 'Jókai Mór', 'Szent István', 'Kodály Zoltán', 'Bartók Béla', 'Fő', 'Templom', 'Iskola', 'Hunyadi János', 'Béke', 'Táncsics Mihály', 'Bajcsy-Zsilinszky', 'Damjanich', 'Deák Ferenc', 'Erkel Ferenc', 'Vörösmarty', 'Kölcsey', 'Batthyány', 'Liszt Ferenc', 'Móricz Zsigmond', 'Radnóti Miklós', 'József Attila', 'Babits Mihály', 'Mátyás király', 'Rózsa', 'Ibolya', 'Tulipán', 'Akácfa', 'Diófa', 'Hársfa', 'Nyárfa', 'Kert', 'Mező', 'Malom', 'Vasút', 'Állomás', 'Gyár', 'Ipar', 'Szőlőhegy', 'Napsugár', 'Hold', 'Csillag', 'Sport', 'Ifjúság'];
        $t['kozterulet'] = ['utca', 'utca', 'utca', 'utca', 'út', 'tér', 'köz', 'sor', 'körút', 'dűlő'];
        $t['cegforma'] = ['Kft.', 'Kft.', 'Kft.', 'Bt.', 'Zrt.', 'E.V.'];
        $t['cegszo'] = ['Szerviz', 'Trade', 'Informatika', 'Építő', 'Logisztika', 'Kereskedelmi', 'Tervező', 'Mobil', 'Elektro', 'Agrár', 'Vendéglátó', 'Pannon', 'Dunántúli', 'Balaton', 'Bakony', 'Alföld', 'Tisza', 'Mátra', 'Digitális', 'Smart'];

        // Külföldiek: [ország (magyarul), előhívó, keresztnevek, vezetéknevek, városok [irsz, név], vezetéknév elöl?, utcák]
        $t['kulfold'] = [
            ['Németország', '+49', ['Lukas', 'Jonas', 'Maximilian', 'Felix', 'Paul', 'Anna', 'Lena', 'Sophie', 'Marie', 'Hannah', 'Leon', 'Mia'], ['Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner', 'Becker', 'Hoffmann', 'Schulz', 'Koch', 'Richter'], [['10115', 'Berlin'], ['80331', 'München'], ['20095', 'Hamburg'], ['50667', 'Köln'], ['70173', 'Stuttgart']], false, ['Hauptstraße', 'Bahnhofstraße', 'Gartenstraße', 'Schulstraße', 'Lindenweg']],
            ['Ausztria', '+43', ['Tobias', 'Florian', 'David', 'Lisa', 'Julia', 'Katharina', 'Elias', 'Laura'], ['Gruber', 'Huber', 'Bauer', 'Wagner', 'Pichler', 'Steiner', 'Moser', 'Mayer'], [['1010', 'Wien'], ['8010', 'Graz'], ['4020', 'Linz'], ['5020', 'Salzburg']], false, ['Hauptplatz', 'Kirchengasse', 'Mariahilfer Straße', 'Landstraße']],
            ['Románia', '+40', ['Andrei', 'Mihai', 'Ionuț', 'Alexandru', 'Elena', 'Ioana', 'Maria', 'Andreea', 'Cristian', 'Gabriela'], ['Popescu', 'Ionescu', 'Popa', 'Dumitru', 'Stan', 'Stoica', 'Gheorghe', 'Rusu', 'Munteanu', 'Matei'], [['400001', 'Cluj-Napoca'], ['300001', 'Timișoara'], ['410001', 'Oradea'], ['010011', 'București']], false, ['Strada Principală', 'Strada Republicii', 'Bulevardul Unirii', 'Strada Mihai Eminescu']],
            ['Szlovákia', '+421', ['Ján', 'Peter', 'Martin', 'Tomáš', 'Lucia', 'Katarína', 'Zuzana', 'Jana'], ['Novák', 'Kováč', 'Hudák', 'Polák', 'Krajčír', 'Lukáč', 'Šimko', 'Urban'], [['81101', 'Bratislava'], ['04001', 'Košice'], ['94901', 'Nitra'], ['94501', 'Komárno']], false, ['Hlavná', 'Školská', 'Dunajská', 'Mierová']],
            ['Ukrajna', '+380', ['Oleksandr', 'Andrii', 'Dmytro', 'Serhii', 'Olena', 'Iryna', 'Nataliia', 'Oksana'], ['Shevchenko', 'Kovalenko', 'Bondarenko', 'Tkachenko', 'Kravchenko', 'Oliinyk', 'Melnyk', 'Boiko'], [['01001', 'Kyiv'], ['79000', 'Lviv'], ['88000', 'Uzhhorod'], ['90200', 'Berehove']], false, ['vul. Shevchenka', 'vul. Hrushevskoho', 'prosp. Svobody', 'vul. Franka']],
            ['Szerbia', '+381', ['Marko', 'Nikola', 'Stefan', 'Luka', 'Ana', 'Jelena', 'Milica', 'Jovana'], ['Jovanović', 'Petrović', 'Nikolić', 'Marković', 'Đorđević', 'Stojanović', 'Ilić', 'Pavlović'], [['11000', 'Beograd'], ['21000', 'Novi Sad'], ['24000', 'Subotica']], false, ['Bulevar oslobođenja', 'Knez Mihailova', 'Zmaj Jovina', 'Kralja Petra']],
            ['Horvátország', '+385', ['Ivan', 'Josip', 'Luka', 'Petra', 'Ivana', 'Marija'], ['Horvat', 'Kovačević', 'Babić', 'Marić', 'Novak', 'Jurić'], [['10000', 'Zagreb'], ['21000', 'Split'], ['31000', 'Osijek']], false, ['Ilica', 'Vukovarska', 'Ulica kralja Zvonimira', 'Savska cesta']],
            ['Egyesült Királyság', '+44', ['James', 'Oliver', 'Jack', 'Harry', 'Emily', 'Olivia', 'Sarah', 'Charlotte'], ['Smith', 'Jones', 'Taylor', 'Brown', 'Williams', 'Wilson', 'Evans', 'Thomas'], [['SW1A 1AA', 'London'], ['M1 1AE', 'Manchester'], ['B1 1AA', 'Birmingham'], ['EH1 1YZ', 'Edinburgh']], false, ['High Street', 'Station Road', 'Church Lane', 'Victoria Road']],
            ['Olaszország', '+39', ['Luca', 'Marco', 'Matteo', 'Alessandro', 'Giulia', 'Francesca', 'Chiara', 'Sofia'], ['Rossi', 'Russo', 'Ferrari', 'Esposito', 'Bianchi', 'Romano', 'Colombo', 'Ricci'], [['00118', 'Roma'], ['20121', 'Milano'], ['10121', 'Torino'], ['34121', 'Trieste']], false, ['Via Roma', 'Via Garibaldi', 'Corso Italia', 'Via Dante']],
            ['Lengyelország', '+48', ['Piotr', 'Krzysztof', 'Tomasz', 'Paweł', 'Anna', 'Katarzyna', 'Agnieszka', 'Magdalena'], ['Nowak', 'Kowalski', 'Wiśniewski', 'Wójcik', 'Kowalczyk', 'Kamiński', 'Lewandowski', 'Zieliński'], [['00-001', 'Warszawa'], ['30-001', 'Kraków'], ['50-001', 'Wrocław']], false, ['ul. Długa', 'ul. Polna', 'ul. Kościuszki', 'ul. Mickiewicza']],
            ['Kína', '+86', ['Wei', 'Fang', 'Jing', 'Lei', 'Min', 'Yan', 'Hao', 'Xin'], ['Wang', 'Li', 'Zhang', 'Liu', 'Chen', 'Yang', 'Huang', 'Zhao'], [['100000', 'Beijing'], ['200000', 'Shanghai']], true, ['Chang\'an Avenue', 'Nanjing Road', 'Renmin Road']],
            ['Vietnám', '+84', ['Anh', 'Minh', 'Linh', 'Huong', 'Tuan', 'Thu', 'Duc', 'Lan'], ['Nguyen', 'Tran', 'Le', 'Pham', 'Hoang', 'Vu', 'Dang', 'Bui'], [['100000', 'Hà Nội'], ['700000', 'Hồ Chí Minh-város']], true, ['Đường Lê Lợi', 'Phố Huế', 'Đường Trần Hưng Đạo']],
        ];

        // Valódi irányítószámok és települések (az SDH Műhely saját adatfájljából).
        $irsz = [];
        $fajl = defined('SDH_MUHELY_DIR') ? SDH_MUHELY_DIR . 'data/iranyitoszam.csv.gz' : '';

        if ($fajl !== '' && is_readable($fajl)) {
            foreach ((array) gzfile($fajl) as $n => $sor) {
                $r = explode(';', trim((string) $sor));

                if ($n === 0 || count($r) < 2 || !preg_match('/^\d{4}$/', $r[0])) {
                    continue;
                }

                $irsz[] = [$r[0], $r[1]];
            }
        }

        $t['irsz'] = $irsz ?: [['1011', 'Budapest'], ['8200', 'Veszprém'], ['6720', 'Szeged'], ['4024', 'Debrecen'], ['9021', 'Győr']];

        $t['gyarto'] = [
            ['Apple', 'telefon', ['iPhone 11', 'iPhone 12', 'iPhone 12 mini', 'iPhone 13', 'iPhone 13 Pro', 'iPhone 14', 'iPhone 14 Pro Max', 'iPhone 15', 'iPhone 15 Pro', 'iPhone SE (2022)']],
            ['Apple', 'tablet', ['iPad (9. gen.)', 'iPad Air (5. gen.)', 'iPad Pro 11"', 'iPad mini 6']],
            ['Apple', 'laptop', ['MacBook Air M1', 'MacBook Air M2', 'MacBook Pro 14"']],
            ['Samsung', 'telefon', ['Galaxy S21', 'Galaxy S22', 'Galaxy S23', 'Galaxy S23 Ultra', 'Galaxy S24', 'Galaxy A14', 'Galaxy A34', 'Galaxy A54', 'Galaxy Z Flip5', 'Galaxy Z Fold5']],
            ['Samsung', 'tablet', ['Galaxy Tab A8', 'Galaxy Tab S8', 'Galaxy Tab S9']],
            ['Xiaomi', 'telefon', ['Redmi Note 11', 'Redmi Note 12', 'Redmi Note 13 Pro', 'Poco X5', 'Xiaomi 13T']],
            ['Huawei', 'telefon', ['P30 Lite', 'P40', 'Nova 9', 'Mate 20']],
            ['Google', 'telefon', ['Pixel 6a', 'Pixel 7', 'Pixel 8 Pro']],
            ['OnePlus', 'telefon', ['OnePlus 9', 'OnePlus 11', 'Nord 3']],
            ['Motorola', 'telefon', ['Moto G54', 'Edge 40']],
            ['Lenovo', 'laptop', ['ThinkPad E14', 'ThinkPad T14', 'IdeaPad 3', 'Legion 5']],
            ['HP', 'laptop', ['Pavilion 15', 'ProBook 450 G9', 'EliteBook 840']],
            ['Dell', 'laptop', ['Inspiron 15', 'Latitude 5420', 'XPS 13']],
            ['Asus', 'laptop', ['VivoBook 15', 'ZenBook 14', 'ROG Strix G15']],
            ['Acer', 'laptop', ['Aspire 5', 'Swift 3', 'Nitro 5']],
            ['Sony', 'konzol', ['PlayStation 5', 'PlayStation 4 Pro']],
            ['Microsoft', 'konzol', ['Xbox Series X', 'Xbox Series S']],
            ['Nintendo', 'konzol', ['Switch OLED', 'Switch Lite']],
            ['Apple', 'ora', ['Watch Series 8', 'Watch SE']],
            ['Samsung', 'ora', ['Galaxy Watch6', 'Galaxy Watch5']],
        ];
        $t['szin'] = ['fekete', 'fehér', 'ezüst', 'arany', 'kék', 'piros', 'zöld', 'lila', 'szürke', 'grafit'];

        $t['szolg'] = [
            ['Kijelzőcsere', 18000, 89000], ['Akkumulátorcsere', 9900, 29900], ['Töltőcsatlakozó csere', 8900, 19900], ['Hátlap csere', 7900, 34900],
            ['Hátlapi kamera csere', 12900, 49900], ['Előlapi kamera csere', 9900, 29900], ['Hangszóró csere', 6900, 16900], ['Mikrofon csere', 6900, 16900],
            ['Vízkár tisztítás', 9900, 24900], ['Alaplapi javítás', 19900, 79900], ['Szoftveres javítás', 4900, 12900], ['Adatmentés', 9900, 59900],
            ['Bevizsgálási díj', 3900, 9900], ['Billentyűzet csere', 14900, 39900], ['Ventilátor tisztítás', 7900, 14900], ['SSD csere és rendszertelepítés', 19900, 49900],
            ['Zsanér javítás', 12900, 29900], ['Kártyaolvasó csere', 6900, 14900], ['Face ID javítás', 29900, 59900], ['Gombok javítása', 6900, 14900],
            ['HDMI-port csere', 14900, 29900], ['Joy-Con kar javítás', 5900, 12900], ['Rezgőmotor csere', 5900, 11900], ['Antenna javítás', 9900, 19900],
        ];
        $t['termek'] = [
            ['Kijelző', 'Alkatrész', 9000, 72000], ['Akkumulátor', 'Alkatrész', 3500, 19000], ['Töltőcsatlakozó flex', 'Alkatrész', 1500, 6000], ['Hátlap', 'Alkatrész', 2500, 18000],
            ['Kamera modul', 'Alkatrész', 4000, 32000], ['Hangszóró', 'Alkatrész', 1200, 5000], ['Edzett üveg fólia', 'Kiegészítő', 900, 4990], ['Szilikon tok', 'Kiegészítő', 1500, 6990],
            ['Flip tok', 'Kiegészítő', 2500, 9990], ['USB-C töltőkábel 1 m', 'Kiegészítő', 900, 3990], ['Gyorstöltő adapter 25 W', 'Kiegészítő', 2900, 9990], ['Vezeték nélküli töltő', 'Kiegészítő', 4900, 14990],
            ['Bluetooth fülhallgató', 'Kiegészítő', 6900, 39990], ['Autós tartó', 'Kiegészítő', 1900, 7990], ['Memóriakártya 128 GB', 'Tároló', 3900, 12990], ['SSD 512 GB', 'Tároló', 12900, 34990],
            ['Billentyűzet', 'Alkatrész', 6900, 29990], ['Hűtőventilátor', 'Alkatrész', 3900, 12990], ['Hővezető paszta', 'Fogyóanyag', 990, 3990], ['Ragasztócsík készlet', 'Fogyóanyag', 490, 1990],
        ];
        $t['beszallito'] = ['Mobil Alkatrész Kft.', 'Pannon Parts Zrt.', 'Euro GSM Nagyker', 'TechSupply Bt.', 'Danubia Elektro Kft.', 'Kelet Import Kft.'];
        $t['hiba'] = ['Nem kapcsol be', 'Betört a kijelző', 'Gyorsan merül az akkumulátor', 'Nem tölt', 'Vízbe esett', 'Nem működik a kamera', 'Recseg a hangszóró', 'Nem hallanak hívás közben', 'Lefagy, újraindul', 'Nem kapcsolódik a wifihez', 'Melegszik', 'Nem ismeri fel a SIM-kártyát', 'Szellemérintés a kijelzőn', 'Nem működik a Face ID', 'Elfelejtett jelszó / zár', 'Billentyűk nem működnek', 'Zajos ventilátor', 'Nincs kép a monitoron', 'Kontroller drift', 'Adatmentés kell'];

        return self::$tar = $t;
    }

    /** Determinisztikus véletlen: ugyanaz a sor mindig ugyanazt adja (újraindításnál is). */
    private static function mag(string $sz, int $i): void
    {
        mt_srand(crc32($sz) ^ ($i * 2654435761 % 4294967291));
    }

    private static function v(array $l)
    {
        return $l[mt_rand(0, count($l) - 1)];
    }

    private static function datum(int $napja): string
    {
        return gmdate('Y-m-d', time() - $napja * 86400);
    }

    /** Ékezet nélküli, kisbetűs, csak betű-szám-pont (e-mail-címhez). */
    private static function ascii(string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '.', strtolower(remove_accents($s))), '.');
    }

    /* =================================================================
     * Generátorok (egy köteg = egy tranzakció)
     * ============================================================== */

    /** @return array<string, string> egy kitalált ügyfél mezői */
    public static function ugyfel_adat(int $i): array
    {
        $t = self::tar();
        self::mag('ugyfel', $i);

        $kulfoldi = mt_rand(1, 100) <= 15;
        $ceg      = !$kulfoldi && mt_rand(1, 100) <= 10;
        $orszag   = 'Magyarország';

        if ($kulfoldi) {
            $k    = self::v($t['kulfold']);
            $kn   = self::v($k[2]);
            $vn   = self::v($k[3]);
            $nev  = $k[5] ? $vn . ' ' . $kn : $kn . ' ' . $vn;
            $kint = mt_rand(0, 1) === 1;

            if ($kint) {
                $varos  = self::v($k[4]);
                $orszag = $k[0];
                $irsz   = (string) $varos[0];
                $telep  = (string) $varos[1];
                $cim    = self::v($k[6]) . ' ' . mt_rand(1, 180);
                $tel    = $k[1] . ' ' . mt_rand(100, 999) . ' ' . mt_rand(100, 999) . ' ' . mt_rand(1000, 9999);
            } else {
                $hely  = self::v($t['irsz']);
                $irsz  = (string) $hely[0];
                $telep = (string) $hely[1];
                $cim   = self::v($t['utca']) . ' ' . self::v($t['kozterulet']) . ' ' . mt_rand(1, 140) . '.';
                $tel   = mt_rand(0, 1) ? '+36 ' . self::v(['20', '30', '70']) . ' ' . mt_rand(100, 999) . ' ' . mt_rand(1000, 9999) : $k[1] . ' ' . mt_rand(100, 999) . ' ' . mt_rand(100, 999) . ' ' . mt_rand(1000, 9999);
            }

            $kapcs = '';
        } else {
            $no    = mt_rand(0, 1) === 1;
            $kn    = self::v($no ? $t['knev_n'] : $t['knev_f']);
            $nev   = self::v($t['vnev']) . ' ' . $kn . (mt_rand(1, 100) <= 8 ? ' ' . self::v($no ? $t['knev_n'] : $t['knev_f']) : '');
            $hely  = self::v($t['irsz']);
            $irsz  = (string) $hely[0];
            $telep = (string) $hely[1];
            $cim   = self::v($t['utca']) . ' ' . self::v($t['kozterulet']) . ' ' . mt_rand(1, 140) . '.' . (mt_rand(1, 100) <= 30 ? ' ' . mt_rand(1, 10) . '/' . mt_rand(1, 20) . '.' : '');
            $tel   = '+36 ' . self::v(['20', '30', '70', '30', '20', '31', '50']) . ' ' . mt_rand(100, 999) . ' ' . mt_rand(1000, 9999);
            $kapcs = '';

            if ($ceg) {
                $kapcs = $nev;
                $nev   = self::v($t['vnev']) . ' ' . self::v($t['cegszo']) . ' ' . self::v($t['cegforma']);
            }
        }

        $email = mt_rand(1, 100) <= 85
            ? self::ascii($ceg ? $nev : $nev) . mt_rand(1, 999) . '@' . self::v(['example.com', 'example.net', 'example.org'])
            : '';

        return [
            'tipus'                   => $ceg ? 'ceg' : 'maganszemely',
            'nev'                     => mb_substr($nev, 0, 190),
            'kapcsolattarto'          => $kapcs,
            'adoszam'                 => $ceg ? sprintf('%08d-%d-%02d', mt_rand(10000000, 32999999), mt_rand(1, 5), mt_rand(2, 44)) : '',
            'telefon'                 => $tel,
            'telefon2'                => mt_rand(1, 100) <= 15 ? '+36 ' . self::v(['20', '30', '70']) . ' ' . mt_rand(100, 999) . ' ' . mt_rand(1000, 9999) : '',
            'email'                   => $email,
            'szamlazasi_iranyitoszam' => mb_substr($irsz, 0, 10),
            'szamlazasi_telepules'    => $telep,
            'szamlazasi_cim'          => $cim,
            'szamlazasi_orszag'       => $orszag,
            'kategoria'               => mt_rand(1, 100) <= 12 ? self::v(['Törzsvásárló', 'Viszonteladó', 'Szerződéses partner', 'Közület']) : '',
            'kedvezmeny'              => mt_rand(1, 100) <= 8 ? (string) self::v([3, 5, 10, 15]) : '0',
            'aktiv'                   => mt_rand(1, 60) === 1 ? '0' : '1',
            'kulfoldi'                => $kulfoldi ? '1' : '0',
        ];
    }

    private static function gen_ugyfel(array $a, int $tol, int $db): void
    {
        $alap  = (int) $a['alap']['ugyfel'];
        $sorok = [];
        $szavak = [];

        for ($k = $tol; $k < $tol + $db; $k++) {
            $id = $alap + $k;
            $u  = self::ugyfel_adat($k);
            $u['ugyfel_szam'] = sprintf('U-%06d', $id);
            $kx = SDH_Muhely_Ugyfel::kereso_adat($u);

            $sorok[] = '(' . implode(',', [
                $id, self::q($u['ugyfel_szam']), self::q($u['tipus']), self::q($u['nev']), self::q($u['kapcsolattarto']), self::q($u['adoszam']),
                self::q($u['telefon']), self::q($u['telefon2']), self::q($u['email']), self::q($u['szamlazasi_iranyitoszam']), self::q($u['szamlazasi_telepules']),
                self::q($u['szamlazasi_cim']), self::q($u['szamlazasi_orszag']), self::q($u['kategoria']), $u['kedvezmeny'], (int) $u['aktiv'],
                self::q(self::JEL), self::q(self::JEL . '-u-' . $id), self::q(self::datum(mt_rand(1, 2200)) . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 17), mt_rand(0, 59))),
                self::q($kx['kereso']), self::q($kx['tel_ford']), self::q($kx['cim_rendez']),
            ]) . ')';

            foreach (SDH_Muhely_Ugyfel::kereso_szavak($u) as $szo) {
                $szavak[] = '(' . self::q((string) $szo) . ',' . $id . ')';
            }
        }

        // Biztonság: a köteg azonosítóira nem maradhat szó-index (ütközne).
        self::fut('DELETE FROM ' . self::t('ugyfel_szo') . ' WHERE ugyfel_id >= ' . ($alap + $tol) . ' AND ugyfel_id < ' . ($alap + $tol + $db));
        self::fut('INSERT INTO ' . self::t('ugyfel') . ' (id, ugyfel_szam, tipus, nev, kapcsolattarto, adoszam, telefon, telefon2, email, szamlazasi_iranyitoszam, szamlazasi_telepules, szamlazasi_cim, szamlazasi_orszag, kategoria, kedvezmeny, aktiv, forras, kulso_azonosito, letrehozva, kereso, tel_ford, cim_rendez) VALUES ' . implode(',', $sorok));

        foreach (array_chunk(array_unique($szavak), 2000) as $darab) {
            self::fut('INSERT INTO ' . self::t('ugyfel_szo') . ' (szo, ugyfel_id) VALUES ' . implode(',', $darab));
        }
    }

    private static function gen_eszkoz(array $a, int $tol, int $db): void
    {
        $t     = self::tar();
        $alap  = (int) $a['alap']['eszkoz'];
        $ualap = (int) $a['alap']['ugyfel'];
        $cel   = (int) $a['cel'];
        $sorok = [];

        for ($k = $tol; $k < $tol + $db; $k++) {
            self::mag('eszkoz', $k);
            $gy   = self::v($t['gyarto']);
            $tip  = self::v($gy[2]);
            $imei = in_array($gy[1], ['telefon', 'tablet'], true) ? (string) mt_rand(350000000000000, 359999999999999) : '';

            $sorok[] = '(' . implode(',', [
                $alap + $k, $ualap + (($k * 7919) % $cel), self::q($gy[1]), self::q($gy[0]), self::q($tip), self::q($gy[0] . ' ' . $tip),
                self::q($imei), self::q(strtoupper(substr(md5('sz' . $k), 0, 11))), self::q(self::v($t['szin'])), mt_rand(1, 100) <= 20 ? 1 : 0,
                1, self::q(self::JEL), self::q(self::JEL . '-e-' . ($alap + $k)), self::q(self::datum(mt_rand(1, 2200)) . ' 10:00:00'),
            ]) . ')';
        }

        self::fut('INSERT INTO ' . self::t('eszkoz') . ' (id, ugyfel_id, kategoria, gyarto, tipus, megnevezes, imei, sorozatszam, szin, garancias, aktiv, forras, kulso_azonosito, letrehozva) VALUES ' . implode(',', $sorok));
    }

    /** @return array{nev: string, kategoria: string, besz: float, brutto: float} */
    public static function termek_adat(int $i, int $azon = 0): array
    {
        $t = self::tar();
        self::mag('termek', $i);
        $tp = self::v($t['termek']);
        $gy = self::v($t['gyarto']);
        $ar = (float) mt_rand((int) ($tp[3] * 0.4), (int) $tp[3]);

        return [
            'nev'       => $tp[0] . ' – ' . $gy[0] . ' ' . self::v($gy[2]) . ' (' . self::v($t['szin']) . ', T' . ($azon ?: $i) . ')',
            'kategoria' => $tp[1],
            'besz'      => round(max((float) $tp[2], $ar * 0.45) / 1.27, 2),
            'brutto'    => round($ar / 10) * 10,
        ];
    }

    private static function gen_termek(array $a, int $tol, int $db): void
    {
        $t      = self::tar();
        $alap   = (int) $a['alap']['termek'];
        $sorok  = [];
        $mozgas = [];

        for ($k = $tol; $k < $tol + $db; $k++) {
            $id  = $alap + $k;
            $p   = self::termek_adat($k, $id);
            $kes = mt_rand(0, 25);
            self::mag('termek-k', $k);

            $sorok[] = '(' . implode(',', [
                $id, self::q(mb_substr($p['nev'], 0, 255)), self::q($p['kategoria']), self::q(self::v($t['beszallito'])),
                self::q('CS-' . strtoupper(substr(md5('c' . $k), 0, 8))), self::q('TK' . str_pad((string) $id, 8, '0', STR_PAD_LEFT)),
                self::q((string) (5990000000000 + $k)), 1, $kes, mt_rand(0, 3), self::szam_sql($p['besz']), self::szam_sql($p['brutto']),
                1, self::q(self::JEL), self::q(self::JEL . '-p-' . $id), self::q(self::datum(mt_rand(1, 1500)) . ' 09:00:00'),
            ]) . ')';

            if ($kes > 0) {
                $mozgas[] = '(' . $id . ",'nyito'," . $kes . ',' . self::q(self::MEGJ) . ',' . self::q(self::datum(1500) . ' 08:00:00') . ')';
            }
        }

        self::fut('INSERT INTO ' . self::t('termek') . ' (id, megnevezes, kategoria, beszallito, cikkszam, termekkod, vonalkod, keszletkezeles, keszlet, min_keszlet, besz_netto, brutto_ar, aktiv, forras, kulso_azonosito, letrehozva) VALUES ' . implode(',', $sorok));

        if ($mozgas !== []) {
            self::fut('INSERT INTO ' . self::t('termek_mozgas') . ' (termek_id, tipus, mennyiseg, megjegyzes, letrehozva) VALUES ' . implode(',', $mozgas));
        }
    }

    /** @return array{nev: string, brutto: float, max: float, kategoria: string} */
    public static function szolg_adat(int $i, int $azon = 0): array
    {
        $t = self::tar();
        self::mag('szolg', $i);
        $s  = self::v($t['szolg']);
        $gy = self::v($t['gyarto']);
        $ar = round(mt_rand($s[1], $s[2]) / 100) * 100;

        return [
            'nev'       => $s[0] . ' – ' . $gy[0] . ' ' . self::v($gy[2]) . ' (SZ' . ($azon ?: $i) . ')',
            'brutto'    => (float) $ar,
            'max'       => mt_rand(1, 100) <= 15 ? (float) ($ar + round(mt_rand(2000, 15000) / 100) * 100) : 0.0,
            'kategoria' => ucfirst((string) $gy[1]),
        ];
    }

    private static function gen_szolgaltatas(array $a, int $tol, int $db): void
    {
        $alap  = (int) $a['alap']['szolgaltatas'];
        $sorok = [];

        for ($k = $tol; $k < $tol + $db; $k++) {
            $s = self::szolg_adat($k, $alap + $k);

            $sorok[] = '(' . implode(',', [
                $alap + $k, self::q($s['nev']), self::q(SDH_Muhely_Szolgaltatas::kulcs($s['nev'])), self::szam_sql($s['brutto']), self::szam_sql($s['max']),
                self::q($s['kategoria']), self::q(self::MEGJ), str_starts_with($s['nev'], 'Bevizsgálási díj') ? 1 : 0, mt_rand(0, 500), self::q(self::datum(mt_rand(1, 1500)) . ' 09:00:00'),
            ]) . ')';
        }

        self::fut('INSERT INTO ' . self::t('szolgaltatas') . ' (id, nev, kulcs, brutto_ar, ar_max, kategoria, megjegyzes, bevizsgalas, hasznalat, letrehozva) VALUES ' . implode(',', $sorok));
    }

    private static function gen_munkalap(array $a, int $tol, int $db): void
    {
        $alap    = (int) $a['alap']['munkalap'];
        $ualap   = (int) $a['alap']['ugyfel'];
        $ealap   = (int) $a['alap']['eszkoz'];
        $cel     = (int) $a['cel'];
        $felelos = array_values(array_map('intval', (array) ($a['felelosok'] ?? [0])));
        $allapot = array_keys(SDH_Muhely_Munkalap::allapotok());
        $zart    = array_keys(array_filter(SDH_Muhely_Munkalap::allapotok(), static fn ($x): bool => !empty($x['zart'])));
        $nyitott = array_values(array_diff($allapot, $zart)) ?: $allapot;
        $rend    = class_exists('SDH_Muhely_Eladas') ? array_keys(SDH_Muhely_Eladas::rend_allapotok()) : ['felveve'];
        $modok   = ['Készpénz', 'Bankkártya', 'Átutalás'];
        $sorok   = [];

        for ($k = $tol; $k < $tol + $db; $k++) {
            self::mag('munkalap', $k);
            $id    = $alap + $k;
            $r     = mt_rand(1, 100);
            $fajta = $r <= 75 ? 'javitas' : ($r <= 92 ? 'arukiadas' : 'rendeles');
            $napja = (int) floor(2190 * (1 - sqrt(mt_rand(0, 1000000) / 1000000)));   // több friss lap, kevesebb régi
            $lezart = $napja > 14 ? mt_rand(1, 100) <= 93 : mt_rand(1, 100) <= 35;
            $all   = $lezart ? self::v($zart ?: $allapot) : self::v($nyitott);
            $esz   = $ealap + (($k * 104729) % $cel);
            $ugy   = $ualap + ((($esz - $ealap) * 7919) % $cel);
            $brutto = (float) (round(mt_rand(30, 1500) / 1) * 100);
            $netto = round($brutto / 1.27, 2);
            $fiz   = $lezart && mt_rand(1, 100) <= 95;
            $keszult = self::datum($napja);

            $sorok[] = '(' . implode(',', [
                $id, (int) $a['lapszam'] + $k, self::q($all), self::q(self::v(['Pultnál felvéve', 'Telefonon egyeztetve', 'Online foglalás', 'Futár hozta', ''])),
                $ugy, $fajta === 'arukiadas' && mt_rand(0, 1) ? 0 : $esz, self::v($felelos), self::q($keszult), self::q(self::datum(max(0, $napja - mt_rand(1, 10)))),
                $lezart ? self::q(self::datum(max(0, $napja - mt_rand(0, 9)))) : 'NULL', $fiz ? 1 : 0, $fiz ? self::q(self::datum(max(0, $napja - mt_rand(0, 9)))) : 'NULL',
                self::q($fiz ? self::v($modok) : ''), $fiz ? self::szam_sql($brutto) : '0.00', self::szam_sql($netto), self::szam_sql($brutto),
                self::q($fajta), self::q($fajta === 'rendeles' ? self::v($rend) : ''), self::q(substr(md5('rma' . $id . wp_salt('nonce')), 0, 24)),
                self::q(self::JEL), self::q(self::JEL . '-l-' . $id), self::q($keszult . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 17), mt_rand(0, 59))),
            ]) . ')';
        }

        self::fut('INSERT INTO ' . self::t('munkalap') . ' (id, munkalap_szam, allapot, nev, ugyfel_id, eszkoz_id, felelos, keszult, hatarido, lezarva, fizetve, fizetes_ideje, fizetesi_mod, fizetett, netto_ertek, brutto_ertek, fajta, rend_allapot, rma_token, forras, kulso_azonosito, letrehozva) VALUES ' . implode(',', $sorok));
    }

    private static function gen_tetel(array $a, int $tol, int $db): void
    {
        $t      = self::tar();
        $lalap  = (int) $a['alap']['munkalap'];
        $palap  = (int) $a['alap']['termek'];
        $salap  = (int) $a['alap']['szolgaltatas'];
        $cel    = (int) $a['cel'];
        $hall   = array_keys(SDH_Muhely_Munkalap::hiba_allapotok());
        $tetel  = [];
        $hiba   = [];

        for ($k = $tol; $k < $tol + $db; $k++) {
            self::mag('tetel', $k);
            $lap = $lalap + $k;
            $n   = mt_rand(1, 3);

            for ($j = 0; $j < $n; $j++) {
                $termek = mt_rand(1, 100) <= 30;
                $x      = ($k * 31 + $j * 7) % $cel;

                if ($termek) {
                    $p  = self::termek_adat($x, $palap + $x);
                    $nv = $p['nev'];
                    $br = $p['brutto'];
                } else {
                    $s  = self::szolg_adat($x, $salap + $x);
                    $nv = $s['nev'];
                    $br = $s['brutto'];
                }

                $ne = round($br / 1.27, 2);
                $tetel[] = '(' . implode(',', [
                    $lap, $j, self::q($termek ? 'termek' : 'szolgaltatas'), self::q('teljesitett'), self::q(mb_substr($nv, 0, 255)),
                    $termek ? $palap + $x : 0, self::szam_sql($ne), self::szam_sql($br), self::szam_sql($ne), self::szam_sql($br),
                    self::q(self::JEL), self::q(self::JEL . '-t-' . $lap . '-' . $j),
                ]) . ')';
            }

            $hiba[] = '(' . $lap . ',0,' . self::q(self::v($t['hiba'])) . ',' . self::q($hall !== [] ? (string) self::v($hall) : 'uj') . ')';
        }

        // A hibák előbb: a „hol tart” a tételek legnagyobb munkalap-azonosítójából számol (a köteg egy tranzakció).
        self::fut('INSERT INTO ' . self::t('munkalap_hiba') . ' (munkalap_id, sorrend, leiras, allapot) VALUES ' . implode(',', $hiba));
        self::fut('INSERT INTO ' . self::t('munkalap_tetel') . ' (munkalap_id, sorrend, tipus, allapot, megnevezes, termek_id, netto_ar, brutto_ar, netto_ertek, brutto_ertek, forras, kulso_azonosito) VALUES ' . implode(',', $tetel));
    }

    private static function gen_penztar(array $a, int $tol, int $db): void
    {
        $alap  = (int) $a['alap']['penztar'];
        $lalap = (int) $a['alap']['munkalap'];
        $cel   = (int) $a['cel'];
        $sorok = [];

        for ($k = $tol; $k < $tol + $db; $k++) {
            self::mag('penztar', $k);
            $lap    = ($k * 31) % $cel;
            $napja  = (int) floor(2190 * (1 - sqrt(mt_rand(0, 1000000) / 1000000)));
            $osszeg = mt_rand(20, 900) * 100;
            $kiadas = mt_rand(1, 100) <= 12;
            $mod    = mt_rand(1, 100);
            $datum  = self::datum($napja);

            $sorok[] = '(' . implode(',', [
                $alap + $k, self::q($datum), self::q($datum . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 17), mt_rand(0, 59))),
                self::q($kiadas ? 'kiadas' : 'bevetel'), self::q($kiadas ? self::v(['Alkatrész beszerzés', 'Irodaszer', 'Futárdíj', 'Takarítószer']) : 'Munkalap fizetés'),
                self::q($kiadas ? '' : 'Tesztügyfél'), $mod <= 55 ? $osszeg : 0, $mod > 55 && $mod <= 90 ? $osszeg : 0, $mod > 90 ? $osszeg : 0,
                $kiadas ? 0 : $lalap + $lap, self::q($kiadas ? '' : (string) ((int) $a['lapszam'] + $lap)), self::q(self::JEL), self::q(self::JEL . '-k-' . ($alap + $k)), self::q($datum . ' 12:00:00'),
            ]) . ')';
        }

        self::fut('INSERT INTO ' . self::t('penztar') . ' (id, datum, ido, tipus, leiras, nev, kp, kartya, utalas, munkalap_id, munkalap_szam, forras, kulso, letrehozva) VALUES ' . implode(',', $sorok));
    }

    /* =================================================================
     * Törlés (csak a saját, jelölt sorok)
     * ============================================================== */

    public const OPT_TORLES = 'sdh_tesztadat_torles';

    /** A jelölő feltétel táblánként (a saját sorok felismerése). @return array<string, string> */
    private static function jelolok(): array
    {
        $j = self::JEL;

        return [
            'ugyfel'         => "kulso_azonosito LIKE '{$j}-u-%'",
            'eszkoz'         => "kulso_azonosito LIKE '{$j}-e-%'",
            'termek'         => "kulso_azonosito LIKE '{$j}-p-%'",
            'szolgaltatas'   => "megjegyzes = '" . self::MEGJ . "'",
            'munkalap'       => "kulso_azonosito LIKE '{$j}-l-%'",
            'munkalap_tetel' => "kulso_azonosito LIKE '{$j}-t-%'",
            'penztar'        => "kulso LIKE '{$j}-k-%'",
        ];
    }

    /**
     * Törlési lépések sorrendben: [törlendő tábla, a kapcsolat oszlopa ('' = a tábla saját jelölt sorai), szülő tábla].
     * A gyerek-táblákból csak a JELÖLT szülőkhöz tartozó sorok törlődnek (pl. a próbamunkalap hibái, naplója,
     * később ráírt üzenetei) – a generálás állapota nélkül is (pl. megszakadt, újrakezdett generálás után).
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function torles_lepesek(): array
    {
        return [
            ['penztar', '', 'penztar'],
            ['penztar', 'munkalap_id', 'munkalap'],
            ['munkalap_tetel', 'munkalap_id', 'munkalap'],
            ['munkalap_tetel', '', 'munkalap_tetel'],
            ['munkalap_hiba', 'munkalap_id', 'munkalap'],
            ['munkalap_eszkoz', 'munkalap_id', 'munkalap'],
            ['munkalap_naplo', 'munkalap_id', 'munkalap'],
            ['uzenet', 'munkalap_id', 'munkalap'],
            ['sms_uzenet', 'munkalap_id', 'munkalap'],
            ['termek_mozgas', 'munkalap_id', 'munkalap'],
            ['munkalap', '', 'munkalap'],
            ['termek_mozgas', 'termek_id', 'termek'],
            ['termek', '', 'termek'],
            ['szolgaltatas', '', 'szolgaltatas'],
            ['eszkoz', '', 'eszkoz'],
            ['ugyfel_szo', 'ugyfel_id', 'ugyfel'],
            ['ugyfel', '', 'ugyfel'],
        ];
    }

    /** Van-e még jelölt (generált) sor az adatbázisban. */
    public static function van_tesztadat(): bool
    {
        global $wpdb;

        foreach (self::jelolok() as $tabla => $feltetel) {
            if ($wpdb->get_var('SELECT id FROM ' . self::t($tabla) . " WHERE {$feltetel} LIMIT 1") !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Egy adag törlés (legfeljebb $idokeret mp), azonosító-ablakokban. A szülő tábla jelölt sorainak legkisebb és
     * legnagyobb azonosítója adja a tartományt; ablakonként csak a jelölt szülőkhöz tartozó sorok törlődnek.
     *
     * @return array<string, mixed>
     */
    public static function torles_lepes(float $idokeret = self::IDOKERET): array
    {
        global $wpdb;

        $p       = get_option(self::OPT_TORLES, []);
        $p       = is_array($p) && isset($p['lepes']) ? $p : ['lepes' => 0, 'tol' => null, 'ig' => 0];
        $lepesek = self::torles_lepesek();
        $jel     = self::jelolok();
        $kezd    = microtime(true);
        $ablak   = 5000;

        @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        while ((int) $p['lepes'] < count($lepesek)) {
            [$tabla, $kapcs, $szulo] = $lepesek[(int) $p['lepes']];
            $sz = self::t($szulo);

            if ($p['tol'] === null) {
                $r = $wpdb->get_row("SELECT MIN(id) AS a, MAX(id) AS b FROM {$sz} WHERE {$jel[$szulo]}");
                $p['tol'] = $r && $r->a !== null ? (int) $r->a : 0;
                $p['ig']  = $r && $r->b !== null ? (int) $r->b : -1;
                $p['a']   = (int) $p['tol'];
            }

            if ((int) $p['tol'] > (int) $p['ig']) {
                $p = ['lepes' => (int) $p['lepes'] + 1, 'tol' => null, 'ig' => 0];
                update_option(self::OPT_TORLES, $p, false);
                continue;
            }

            if (microtime(true) - $kezd > $idokeret) {
                break;
            }

            $tol = (int) $p['tol'];
            $veg = $tol + $ablak;
            $t   = self::t($tabla);
            $sql = $kapcs === ''
                ? "DELETE FROM {$t} WHERE id >= {$tol} AND id < {$veg} AND ({$jel[$szulo]})"
                : "DELETE FROM {$t} WHERE {$kapcs} IN (SELECT id FROM {$sz} WHERE id >= {$tol} AND id < {$veg} AND ({$jel[$szulo]}))";

            SDH_Muhely_Valtozasnaplo::csendben(static function () use ($sql): void {
                self::fut($sql);
            });

            $p['tol'] = $veg;
            update_option(self::OPT_TORLES, $p, false);
        }

        if ((int) $p['lepes'] >= count($lepesek)) {
            delete_option(self::OPT_TORLES);
            delete_option(self::OPT);

            if (class_exists('SDH_Muhely_Gyorsitotar')) {
                SDH_Muhely_Gyorsitotar::ervenytelenit();
            }

            return ['kesz' => true, 'szazalek' => 100];
        }

        $l    = (int) $p['lepes'];
        $a0   = (int) ($p['a'] ?? 0);
        $resz = $p['tol'] !== null ? min(1, max(0, ((int) $p['tol'] - $a0) / max(1, (int) $p['ig'] - $a0 + 1))) : 0;

        return ['kesz' => false, 'szazalek' => round(($l + $resz) / count($lepesek) * 100, 1), 'lepes' => $lepesek[$l][0]];
    }

    /** Kikapcsoláskor: minden saját sor törlése, időkorlát nélkül. */
    public static function kikapcsolas(): void
    {
        if (!self::muhely_kesz()) {
            return;
        }

        @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        ignore_user_abort(true);

        for ($i = 0; $i < 100000; $i++) {
            if (!empty(self::torles_lepes(60.0)['kesz'])) {
                break;
            }
        }
    }

    /* =================================================================
     * Felület: Beállítások › Rendszer › Tesztadat
     * ============================================================== */

    public static function lapful(): void
    {
        if (!class_exists('SDH_Muhely_Beallitasok') || !method_exists('SDH_Muhely_Beallitasok', 'regisztral')) {
            return;
        }

        SDH_Muhely_Beallitasok::regisztral('tesztadat', [
            'cim'     => 'Tesztadat',
            'csoport' => 'rendszer',
            'leiras'  => 'Tömeges, kitalált próbaadat terheléses teszthez – és egy gombnyomásra törlés. Csak próbapéldányon.',
            'rajz'    => [self::class, 'doboz'],
        ]);
    }

    public static function admin_jelzes(): void
    {
        if (!self::muhely_kesz() && current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p><strong>SDH Tesztadat:</strong> az SDH Műhely 0.98.0 vagy újabb kell hozzá (bekapcsolva).</p></div>';
        }
    }

    public static function doboz(): void
    {
        global $wpdb;

        $a      = self::allapot();
        $j      = self::jelentes($a);
        $valodi = [
            'ügyfél'   => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::t('ugyfel') . ' WHERE NOT (' . self::jelolok()['ugyfel'] . ')'),
            'munkalap' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::t('munkalap') . ' WHERE NOT (' . self::jelolok()['munkalap'] . ')'),
        ];
        $nonce  = wp_create_nonce('sdh_tesztadat');
        $van    = self::van_tesztadat();
        $ajax   = admin_url('admin-ajax.php');
        ?>
        <div class="sdh-doboz sdh-tesztadat" data-sdh-tesztadat data-ajax="<?php echo esc_url($ajax); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            <h2 class="sdh-doboz__cim">Tesztadat</h2>

            <div class="sdh-uzenet sdh-uzenet--figyelem">
                <strong>Csak próbapéldányon!</strong> Amíg ez a kiegészítő be van kapcsolva, a rendszer tesztüzemben fut:
                SMS csak szimuláció, e-mail, számla és külső mentés nem megy ki. Ebben a példányban most
                <?php echo esc_html(number_format_i18n($valodi['ügyfél'])); ?> saját ügyfél és
                <?php echo esc_html(number_format_i18n($valodi['munkalap'])); ?> saját munkalap van – ezekhez a törlés nem nyúl.
            </div>

            <p class="sdh-sugo">
                Kitalált emberek (85% magyar, 15% külföldi – fele külföldi címmel), termékek, szolgáltatások, munkalapok
                (javítás, árukiadás, rendelés, 1–3 tétellel és hibával) és pénztártételek, az elmúlt hat évre szétosztva.
                Az e-mail-címek a fenntartott <code>example.com</code> tartományban vannak, ezért soha nem kézbesíthetők.
            </p>

            <div class="sdh-mezok" data-sdh-td-indit<?php echo (int) ($j['cel'] ?? 0) > 0 ? ' hidden' : ''; ?>>
                <div class="sdh-mezo">
                    <label for="sdh-td-meret">Mennyit mindenből</label>
                    <select id="sdh-td-meret" data-sdh-td-meret>
                        <?php foreach (self::MERETEK as $m) : ?>
                            <option value="<?php echo (int) $m; ?>"<?php selected($m, 300000); ?>><?php echo esc_html(number_format_i18n($m)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="sdh-tesztadat__sav" data-sdh-td-sav<?php echo (int) ($j['cel'] ?? 0) > 0 ? '' : ' hidden'; ?>>
                <div class="sdh-tesztadat__csik"><span data-sdh-td-csik style="width:<?php echo esc_attr((string) $j['szazalek']); ?>%"></span></div>
                <p class="sdh-sugo" data-sdh-td-szoveg><?php echo esc_html(self::szoveg($j)); ?></p>
                <table class="sdh-tabla" data-sdh-td-tabla>
                    <tbody>
                        <?php foreach ($j['sorok'] as $s) : ?>
                            <tr data-kulcs="<?php echo esc_attr($s['kulcs']); ?>"><td><?php echo esc_html($s['cim']); ?></td><td class="sdh-szam-oszlop"><?php echo esc_html(number_format_i18n($s['kesz']) . ' / ' . number_format_i18n($s['cel'])); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="sdh-gombsor">
                <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-td-gen data-ketlepcsos="Biztosan? Generálás indul"><?php echo (int) ($j['cel'] ?? 0) > 0 && !$j['kesz'] ? 'Folytatás' : 'Generálás indítása'; ?></button>
                <button type="button" class="sdh-gomb" data-sdh-td-torol data-ketlepcsos="Biztosan? Minden tesztadat törlődik"<?php echo (int) ($j['cel'] ?? 0) > 0 || $van ? '' : ' hidden'; ?>><?php echo $j['torles'] ? 'Törlés folytatása' : 'Tesztadat törlése'; ?></button>
            </div>
            <p class="sdh-sugo sdh-sugo--utolso">A generálás közben az oldal maradjon nyitva. Ha megszakad, a <strong>Folytatás</strong> onnan megy tovább. A kiegészítő kikapcsolásakor is minden tesztadat törlődik.</p>
        </div>
        <style>
            .sdh-tesztadat__csik { height: calc(14px * var(--sdh-n)); margin: .6rem 0; border-radius: calc(999px * var(--sdh-n)); background: var(--sdh-felulet-2); overflow: hidden; }
            .sdh-tesztadat__csik span { display: block; height: 100%; border-radius: inherit; background: var(--sdh-accent); transition: width .4s; }
            .sdh-tesztadat .sdh-gombsor { display: flex; flex-wrap: wrap; gap: calc(8px * var(--sdh-n)); margin-top: .8rem; }
            .sdh-tesztadat .sdh-szam-oszlop { text-align: right; font-variant-numeric: tabular-nums; }
        </style>
        <script>
        (function () {
            var d = document.querySelector('[data-sdh-tesztadat]');
            if (!d || d.getAttribute('data-kesz')) { return; }
            d.setAttribute('data-kesz', '1');
            var gen = d.querySelector('[data-sdh-td-gen]'), tor = d.querySelector('[data-sdh-td-torol]');
            var sav = d.querySelector('[data-sdh-td-sav]'), csik = d.querySelector('[data-sdh-td-csik]'), szoveg = d.querySelector('[data-sdh-td-szoveg]');
            var fut = false;

            function ido(mp) { mp = Math.max(0, mp | 0); var p = Math.floor(mp / 60), s = mp % 60; return p > 0 ? p + ' perc ' + s + ' mp' : s + ' mp'; }
            function szam(n) { return Number(n).toLocaleString('hu-HU'); }

            function kerdez(muvelet, extra) {
                var fd = new FormData();
                fd.set('action', muvelet); fd.set('_wpnonce', d.getAttribute('data-nonce'));
                Object.keys(extra || {}).forEach(function (k) { fd.set(k, extra[k]); });
                return fetch(d.getAttribute('data-ajax'), {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); });
            }

            function mutat(j) {
                sav.hidden = false;
                csik.style.width = (j.szazalek || 0) + '%';
                (j.sorok || []).forEach(function (s) {
                    var tr = d.querySelector('tr[data-kulcs="' + s.kulcs + '"] td:last-child');
                    if (tr) { tr.textContent = szam(s.kesz) + ' / ' + szam(s.cel); }
                });
                szoveg.textContent = j.kesz ? ('Kész: ' + szam(j.cel) + ' mindenből, ' + ido(j.eltelt) + ' alatt.')
                    : ((j.szazalek || 0) + '% · eltelt ' + ido(j.eltelt) + (j.hatra ? ' · még kb. ' + ido(j.hatra) : ''));
            }

            function ketlepcso(gomb, tovabb) {
                if (gomb.getAttribute('data-elesitve') !== '1') {
                    gomb.setAttribute('data-elesitve', '1');
                    gomb.setAttribute('data-eredeti', gomb.textContent);
                    gomb.textContent = gomb.getAttribute('data-ketlepcsos');
                    setTimeout(function () { if (gomb.getAttribute('data-elesitve') === '1') { gomb.setAttribute('data-elesitve', '0'); gomb.textContent = gomb.getAttribute('data-eredeti'); } }, 4000);
                    return;
                }
                gomb.setAttribute('data-elesitve', '0');
                gomb.textContent = gomb.getAttribute('data-eredeti');
                tovabb();
            }

            function generalas(elso) {
                kerdez('sdh_tesztadat_lepes', elso ? {cel: d.querySelector('[data-sdh-td-meret]').value} : {}).then(function (v) {
                    if (!v || !v.success) { fut = false; gen.disabled = false; szoveg.textContent = 'Hiba: ' + ((v && v.data && v.data.uzenet) || 'ismeretlen') + ' – a Folytatás onnan megy tovább.'; gen.textContent = 'Folytatás'; return; }
                    mutat(v.data);
                    if (v.data.kesz) { fut = false; gen.disabled = false; gen.textContent = 'Generálás indítása'; gen.hidden = true; tor.hidden = false; return; }
                    generalas(false);
                }).catch(function () { setTimeout(function () { generalas(false); }, 3000); });
            }

            function torles() {
                kerdez('sdh_tesztadat_torles').then(function (v) {
                    if (!v || !v.success) { fut = false; tor.disabled = false; szoveg.textContent = 'Hiba a törlésnél – nyomd meg újra.'; return; }
                    csik.style.width = (100 - (v.data.szazalek || 0)) + '%';
                    szoveg.textContent = v.data.kesz ? 'Minden tesztadat törölve.' : 'Törlés: ' + (v.data.szazalek || 0) + '%';
                    if (v.data.kesz) { fut = false; setTimeout(function () { location.reload(); }, 1200); return; }
                    torles();
                }).catch(function () { setTimeout(torles, 3000); });
            }

            gen.addEventListener('click', function () {
                if (fut) { return; }
                ketlepcso(gen, function () { fut = true; gen.disabled = true; d.querySelector('[data-sdh-td-indit]').hidden = true; sav.hidden = false; generalas(true); });
            });
            tor.addEventListener('click', function () {
                if (fut) { return; }
                ketlepcso(tor, function () { fut = true; tor.disabled = true; gen.disabled = true; torles(); });
            });
        }());
        </script>
        <?php
    }

    /** @param array<string, mixed> $j */
    private static function szoveg(array $j): string
    {
        if ((int) ($j['cel'] ?? 0) === 0) {
            return '';
        }

        return $j['kesz'] ? 'Kész: ' . number_format_i18n((int) $j['cel']) . ' mindenből.' : $j['szazalek'] . '% – megszakadt, a Folytatás onnan megy tovább.';
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    public static function ajax_lepes(): void
    {
        check_ajax_referer('sdh_tesztadat');

        if (!self::jogos() || !self::muhely_kesz()) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultság, vagy nincs bekapcsolva az SDH Műhely 0.98.0+.'], 403);
        }

        $cel = isset($_POST['cel']) ? (int) $_POST['cel'] : 0;
        $cel = in_array($cel, self::MERETEK, true) ? $cel : null;

        try {
            wp_send_json_success(self::lepes($cel));
        } catch (Throwable $e) {
            wp_send_json_error(['uzenet' => $e->getMessage()]);
        }
    }

    public static function ajax_torles(): void
    {
        check_ajax_referer('sdh_tesztadat');

        if (!self::jogos() || !self::muhely_kesz()) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultság.'], 403);
        }

        try {
            wp_send_json_success(self::torles_lepes());
        } catch (Throwable $e) {
            wp_send_json_error(['uzenet' => $e->getMessage()]);
        }
    }
}

SDH_Tesztadat::init();
register_deactivation_hook(__FILE__, [SDH_Tesztadat::class, 'kikapcsolas']);
