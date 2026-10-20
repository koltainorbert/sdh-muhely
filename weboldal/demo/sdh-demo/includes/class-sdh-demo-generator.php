<?php
/**
 * Bemutató-adat generátor: kb. 10 000 kitalált rekord egy képzelt javítószerviz 18 hónapjáról.
 *
 * Alapméret (--db=10000): ügyfél 2 000, eszköz 2 500, munkalap 2 300 (tételekkel és hibasorokkal), termék 1 200
 * (nyitókészlettel), szolgáltatás 300, pénztártétel 1 200, naptár 300 – a kért összméret arányában.
 *
 * - A műhely saját kódútjait használja, ahogy a műhely tesztadat-generátora: ugyanazok az oszlopok, a műhely
 *   keresőadata (SDH_Muhely_Ugyfel::kereso_adat / kereso_szavak), a szolgáltatás kulcsa, a munkalap- és rendelési
 *   állapotok a beállításokból, SQLite-on közvetlen PDO, MySQL / MariaDB-n $wpdb, kötegelt többsoros INSERT.
 * - Determinisztikus: soronként rögzített véletlenmag – ugyanaz a méret ugyanazokat az embereket, készülékeket adja.
 * - Kitalált: nevek a gyakori magyar nevekből, telefonszám a nem kiosztott „0”-val kezdődő előfizetői tartományban
 *   (+36 20/30/70 0xx xxxx), e-mail csak @pelda.hu, adószám a nem létező 0000…-tartományban, valódi irányítószám +
 *   település, kitalált utcaszám. Semmi nem utal az eszköz valódi tulajdonosára vagy a fejlesztőre.
 * - Jelölt: forras = 'bemutato', kulso_azonosito = 'bm-…' (szolgáltatásnál a megjegyzés: [bemutató]); újrafuttatáskor
 *   előbb a korábbi jelölt sorok törlődnek, más adathoz nem nyúl.
 * - Képek: a készülékek saját fényképe (eszköz kep_url) a demo-adat/kepek/manifest.json-ból, kategória szerint
 *   (telefon, tablet, laptop, konzol, okosóra), és minden tizedik munkalaphoz egy átvételi fotó (csatolmány).
 *
 * @package SDH_Demo
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Demo_Generator
{
    public const JEL  = 'bemutato';
    public const KULSO = 'bm-';
    public const MEGJ = '[bemutató]';

    /** Az alapméret (10 000) szakaszai. */
    public const ALAP = [
        'ugyfel' => 2000, 'eszkoz' => 2500, 'munkalap' => 2300, 'termek' => 1200, 'szolgaltatas' => 300, 'penztar' => 1200, 'naptar' => 300,
    ];

    /** A bemutató időtávja: 18 hónap. */
    private const NAPOK = 548;

    /** A manifest kategóriája => a műhely eszköz-kategóriája. */
    private const KEP_KATEGORIA = ['telefon' => 'telefon', 'tablet' => 'tablet', 'laptop' => 'laptop', 'konzol' => 'konzol', 'ora' => 'ora'];

    /** @var array<string, mixed>|null */
    private static ?array $tar = null;

    /* =================================================================
     * Futtatás
     * ============================================================== */

    /** @return array<string, int> szakasz => darab */
    public static function darabok(int $osszes): array
    {
        $arany = max(1, $osszes) / 10000;
        $ki    = [];

        foreach (self::ALAP as $sz => $n) {
            $ki[$sz] = max(1, (int) round($n * $arany));
        }

        return $ki;
    }

    /**
     * A teljes generálás. @return array{db: array<string, int>, osszes: int, mp: float, kepek: int, fotok: int, tetelek: int}
     */
    public static function general(int $osszes = 10000, ?string $kep_mappa = null): array
    {
        $kezd = microtime(true);
        @set_time_limit(900); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        $db = self::darabok($osszes);
        $ki = [];

        $munka = static function () use ($db, $kep_mappa, &$ki): void {
            $ido = [];
            $t   = microtime(true);
            $lep = static function (string $nev) use (&$ido, &$t): void {
                $ido[$nev] = round(microtime(true) - $t, 2);
                $t         = microtime(true);
            };

            self::torol();
            $lep('torles');

            $felelosok = self::munkatarsak();
            self::beallitasok();
            self::tar();
            SDH_Muhely_Munkalap::allapotok();
            SDH_Muhely_Munkalap::hiba_allapotok();

            $kepek = self::kepek($kep_mappa);
            $a     = self::alapok();
            $lep('elokeszites');

            $termekek = self::gen_termek($a['termek'], $db['termek']);
            $szolg    = self::gen_szolgaltatas($a['szolgaltatas'], $db['szolgaltatas']);
            $lep('termek_szolgaltatas');
            $ugyfelek = self::gen_ugyfel($a['ugyfel'], $db['ugyfel']);
            $lep('ugyfel');
            $eszkozok = self::gen_eszkoz($a['eszkoz'], $db['eszkoz'], $a['ugyfel'], $db['ugyfel'], $kepek);
            $lep('eszkoz');
            $lapok    = self::gen_munkalap($a, $db, $felelosok['felhasznalok'], $eszkozok, $ugyfelek, $termekek, $szolg);
            $lep('munkalap');
            self::gen_penztar($a['penztar'], $db['penztar'], $lapok['fizetett']);
            self::gen_naptar($a['naptar'], $db['naptar'], $felelosok['szemelyek'], $ugyfelek, $a['ugyfel'], $lapok['nyitott']);
            $lep('penztar_naptar');

            $ki = ['kepek' => array_sum(array_map('count', $kepek['fajlok'])), 'tetelek' => $lapok['tetelek'], 'lapok' => $lapok['azon'], 'idok' => $ido];
        };

        $fotok = 0;
        $minden = static function () use ($munka, &$ki, &$fotok, $kep_mappa): void {
            $munka();
            $t     = microtime(true);
            $fotok = self::atveteli_fotok((array) $ki['lapok'], $kep_mappa);
            $ki['idok']['fotok'] = round(microtime(true) - $t, 2);
        };

        SDH_Demo_Keret::csendben(static function () use ($minden): void {
            class_exists('SDH_Muhely_Valtozasnaplo') ? SDH_Muhely_Valtozasnaplo::csendben($minden) : $minden();
        });

        $t     = microtime(true);
        self::befejez();
        $ki['idok']['befejezes'] = round(microtime(true) - $t, 2);

        $eredmeny = [
            'db'      => $db,
            'osszes'  => array_sum($db),
            'mp'      => round(microtime(true) - $kezd, 2),
            'kepek'   => (int) $ki['kepek'],
            'fotok'   => $fotok,
            'tetelek' => (int) $ki['tetelek'],
            'idok'    => (array) $ki['idok'],
        ];

        SDH_Demo::allapot_ment(['generalas' => $eredmeny + ['ido' => time()]]);

        return $eredmeny;
    }

    /* =================================================================
     * Adatbázis-segédek (a műhely tesztadat-generátorának útja)
     * ============================================================== */

    private static function t(string $nev): string
    {
        return SDH_Muhely_Schema::tabla($nev);
    }

    private static function pdo(): ?PDO
    {
        return SDH_Demo_Pillanatkep::pdo();
    }

    private static function fut(string $sql): void
    {
        global $wpdb;

        $pdo = self::pdo();

        if ($pdo !== null) {
            $pdo->exec($sql);

            return;
        }

        if ($wpdb->query($sql) === false) { // phpcs:ignore
            throw new RuntimeException('Adatbázis-hiba: ' . (string) $wpdb->last_error);
        }
    }

    /** Egy szakasz egy tranzakcióban, többsoros INSERT-ekkel. @param array<int, string> $sorok */
    private static function beir(string $tabla, string $oszlopok, array $sorok, int $koteg = 500): void
    {
        if ($sorok === []) {
            return;
        }

        $pdo = self::pdo();
        $pdo !== null ? $pdo->beginTransaction() : self::fut('START TRANSACTION');

        try {
            foreach (array_chunk($sorok, $koteg) as $d) {
                self::fut('INSERT INTO ' . self::t($tabla) . ' (' . $oszlopok . ') VALUES ' . implode(',', $d));
            }

            $pdo !== null ? $pdo->commit() : self::fut('COMMIT');
        } catch (Throwable $e) {
            $pdo !== null ? $pdo->rollBack() : self::fut('ROLLBACK');
            throw $e;
        }
    }

    private static function q(string $s): string
    {
        $pdo = self::pdo();

        return $pdo !== null ? (string) $pdo->quote($s) : "'" . esc_sql($s) . "'";
    }

    private static function n(float $f): string
    {
        return number_format($f, 2, '.', '');
    }

    /** Determinisztikus véletlen: ugyanaz a sor mindig ugyanazt adja. */
    private static function mag(string $sz, int $i): void
    {
        mt_srand(crc32('bemutato|' . $sz) ^ ($i * 2654435761 % 4294967291));
    }

    /** @param array<int, mixed> $l @return mixed */
    private static function v(array $l)
    {
        return $l[mt_rand(0, count($l) - 1)];
    }

    private static function nap(int $napja, string $ora = ''): string
    {
        $d = wp_date('Y-m-d', time() - $napja * DAY_IN_SECONDS);

        return $ora === '' ? $d : $d . ' ' . $ora;
    }

    /** Több friss, kevesebb régi (18 hónap). */
    private static function napja(): int
    {
        return (int) floor(self::NAPOK * (1 - sqrt(mt_rand(0, 1000000) / 1000000)));
    }

    private static function ido(): string
    {
        return sprintf('%02d:%02d:00', mt_rand(8, 17), mt_rand(0, 59));
    }

    private static function ascii(string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '.', strtolower(remove_accents($s))), '.');
    }

    /** Kitalált mobilszám: a „0”-val kezdődő előfizetői tartomány (nem kiosztott). */
    public static function telefon(): string
    {
        return '+36 ' . self::v(['20', '30', '70']) . ' 0' . sprintf('%02d', mt_rand(0, 99)) . ' ' . sprintf('%04d', mt_rand(0, 9999));
    }

    /** 14 számjegy + Luhn-ellenőrzőjegy (érvényes formájú, kitalált IMEI). */
    public static function imei(string $torzs): string
    {
        $osszeg = 0;

        foreach (str_split(strrev($torzs)) as $i => $c) {
            $d       = (int) $c * ($i % 2 === 0 ? 2 : 1);
            $osszeg += $d > 9 ? $d - 9 : $d;
        }

        return $torzs . (string) ((10 - $osszeg % 10) % 10);
    }

    /** Az azonosító-tartományok: a mostani legnagyobb + 1. @return array<string, int> */
    private static function alapok(): array
    {
        global $wpdb;

        $a = [];

        foreach (['ugyfel', 'eszkoz', 'termek', 'szolgaltatas', 'munkalap', 'penztar', 'naptar_esemeny'] as $t) {
            $a[$t === 'naptar_esemeny' ? 'naptar' : $t] = 1 + (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . self::t($t));
        }

        // Árva gyereksorok fölé (korábban törölt rekordok szó-indexe, tételei).
        $arva = [
            'ugyfel'   => [['ugyfel_szo', 'ugyfel_id'], ['eszkoz', 'ugyfel_id'], ['munkalap', 'ugyfel_id']],
            'eszkoz'   => [['munkalap', 'eszkoz_id']],
            'termek'   => [['termek_mozgas', 'termek_id'], ['munkalap_tetel', 'termek_id']],
            'munkalap' => [['munkalap_tetel', 'munkalap_id'], ['munkalap_hiba', 'munkalap_id'], ['munkalap_naplo', 'munkalap_id'], ['uzenet', 'munkalap_id'], ['sms_uzenet', 'munkalap_id'], ['penztar', 'munkalap_id']],
        ];

        foreach ($arva as $sz => $lista) {
            foreach ($lista as [$tabla, $oszlop]) {
                $a[$sz] = max($a[$sz], 1 + (int) $wpdb->get_var('SELECT COALESCE(MAX(' . $oszlop . '), 0) FROM ' . self::t($tabla)));
            }
        }

        $a['lapszam'] = 1 + (int) $wpdb->get_var('SELECT COALESCE(MAX(munkalap_szam), 0) FROM ' . self::t('munkalap'));

        return $a;
    }

    /* =================================================================
     * Kitalált adatforrások
     * ============================================================== */

    /** @return array<string, mixed> */
    private static function tar(): array
    {
        if (self::$tar !== null) {
            return self::$tar;
        }

        $t = [];
        $t['vnev']   = ['Nagy', 'Kovács', 'Tóth', 'Szabó', 'Horváth', 'Varga', 'Kiss', 'Molnár', 'Németh', 'Farkas', 'Balogh', 'Papp', 'Takács', 'Juhász', 'Lakatos', 'Mészáros', 'Oláh', 'Simon', 'Rácz', 'Fekete', 'Szilágyi', 'Török', 'Fehér', 'Balázs', 'Gál', 'Kis', 'Szűcs', 'Kocsis', 'Pintér', 'Fodor', 'Szalai', 'Sipos', 'Magyar', 'Lukács', 'Gulyás', 'Biró', 'Király', 'Katona', 'László', 'Jakab', 'Bogdán', 'Hegedűs', 'Illés', 'Vincze', 'Bodnár', 'Sándor', 'Antal', 'Pál', 'Somogyi', 'Fülöp', 'Bakos', 'Bálint', 'Boros', 'Csizmadia', 'Deák', 'Dudás', 'Erdős', 'Fazekas', 'Gergely', 'Hajdu', 'Halász', 'Hegyi', 'Jónás', 'Kelemen', 'Kozma', 'Kun', 'Lengyel', 'Lőrincz', 'Major', 'Márton', 'Nemes', 'Orbán', 'Pásztor', 'Péter', 'Pataki', 'Soós', 'Szekeres', 'Szőke', 'Tamás', 'Tímár', 'Vass', 'Veres', 'Vida', 'Zsigmond', 'Barta', 'Benkő', 'Bognár', 'Dobos', 'Gyenes', 'Kántor'];
        $t['knev_f'] = ['László', 'István', 'József', 'János', 'Zoltán', 'Sándor', 'Gábor', 'Ferenc', 'Attila', 'Péter', 'Tamás', 'Zsolt', 'Tibor', 'András', 'Csaba', 'Imre', 'Lajos', 'György', 'Balázs', 'Gyula', 'Mihály', 'Róbert', 'Dániel', 'Ádám', 'Krisztián', 'Bence', 'Máté', 'Dávid', 'Levente', 'Gergő', 'Márk', 'Kristóf', 'Milán', 'Bálint', 'Botond', 'Olivér', 'Dominik', 'Marcell', 'Barnabás', 'Benedek', 'Zalán', 'Áron', 'Hunor'];
        $t['knev_n'] = ['Mária', 'Erzsébet', 'Katalin', 'Éva', 'Ilona', 'Anna', 'Zsuzsanna', 'Margit', 'Judit', 'Ágnes', 'Andrea', 'Erika', 'Krisztina', 'Edit', 'Gabriella', 'Szilvia', 'Anita', 'Mónika', 'Tímea', 'Eszter', 'Viktória', 'Nikolett', 'Réka', 'Dóra', 'Petra', 'Fanni', 'Lilla', 'Boglárka', 'Hanna', 'Zsófia', 'Luca', 'Jázmin', 'Lili', 'Emma', 'Zoé', 'Laura', 'Kitti', 'Noémi', 'Bianka', 'Orsolya', 'Rita', 'Csilla', 'Beáta', 'Enikő'];
        $t['utca']   = ['Kossuth Lajos', 'Petőfi Sándor', 'Széchenyi István', 'Ady Endre', 'Rákóczi Ferenc', 'Arany János', 'Dózsa György', 'Jókai Mór', 'Kodály Zoltán', 'Bartók Béla', 'Fő', 'Templom', 'Iskola', 'Hunyadi János', 'Béke', 'Táncsics Mihály', 'Deák Ferenc', 'Erkel Ferenc', 'Vörösmarty', 'Kölcsey', 'Batthyány', 'Liszt Ferenc', 'Móricz Zsigmond', 'Radnóti Miklós', 'József Attila', 'Babits Mihály', 'Rózsa', 'Ibolya', 'Tulipán', 'Akácfa', 'Diófa', 'Hársfa', 'Nyárfa', 'Kert', 'Mező', 'Malom', 'Vasút', 'Állomás', 'Ipar', 'Szőlőhegy', 'Napsugár', 'Csillag', 'Sport', 'Ifjúság'];
        $t['kozt']   = ['utca', 'utca', 'utca', 'utca', 'út', 'tér', 'köz', 'sor', 'körút'];
        $t['cegforma'] = ['Kft.', 'Kft.', 'Kft.', 'Bt.', 'Zrt.', 'e.v.'];
        $t['cegszo'] = ['Szerviz', 'Kereskedelmi', 'Informatika', 'Építő', 'Logisztika', 'Tervező', 'Mobil', 'Elektro', 'Agrár', 'Vendéglátó', 'Pannon', 'Dunamenti', 'Tisza', 'Mátra', 'Digitális', 'Iroda'];

        $irsz = [];
        $fajl = defined('SDH_MUHELY_DIR') ? SDH_MUHELY_DIR . 'data/iranyitoszam.csv.gz' : '';

        if ($fajl !== '' && is_readable($fajl)) {
            foreach ((array) gzfile($fajl) as $n => $sor) {
                $r = explode(';', trim((string) $sor));

                // (Egy település kimarad: a bemutató semmilyen eredettörténetre ne emlékeztessen.)
                if ($n === 0 || count($r) < 2 || !preg_match('/^\d{4}$/', $r[0]) || $r[1] === 'Veszprém') {
                    continue;
                }

                $irsz[] = [$r[0], $r[1]];
            }
        }

        $t['irsz'] = $irsz ?: [['1011', 'Budapest'], ['6720', 'Szeged'], ['4024', 'Debrecen'], ['9021', 'Győr'], ['7621', 'Pécs']];

        $t['gyarto'] = [
            ['Apple', 'telefon', ['iPhone 11', 'iPhone 12', 'iPhone 12 mini', 'iPhone 13', 'iPhone 13 Pro', 'iPhone 14', 'iPhone 14 Pro Max', 'iPhone 15', 'iPhone 15 Pro', 'iPhone SE (2022)']],
            ['Apple', 'tablet', ['iPad (9. gen.)', 'iPad Air (5. gen.)', 'iPad Pro 11"', 'iPad mini 6']],
            ['Apple', 'laptop', ['MacBook Air M1', 'MacBook Air M2', 'MacBook Pro 14"']],
            ['Samsung', 'telefon', ['Galaxy S21', 'Galaxy S22', 'Galaxy S23', 'Galaxy S23 Ultra', 'Galaxy S24', 'Galaxy A14', 'Galaxy A34', 'Galaxy A54', 'Galaxy Z Flip5']],
            ['Samsung', 'tablet', ['Galaxy Tab A8', 'Galaxy Tab S8', 'Galaxy Tab S9']],
            ['Xiaomi', 'telefon', ['Redmi Note 11', 'Redmi Note 12', 'Redmi Note 13 Pro', 'Poco X5', 'Xiaomi 13T']],
            ['Huawei', 'telefon', ['P30 Lite', 'P40', 'Nova 9']],
            ['Google', 'telefon', ['Pixel 6a', 'Pixel 7', 'Pixel 8 Pro']],
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
        $t['tartozek'] = ['', '', 'töltőkábel', 'tok', 'töltő és kábel', 'SIM-tű', 'doboz', 'tok, fólia'];
        $t['allapot'] = ['Karcos hátlap', 'Kopott sarkok', 'Törött kijelző', 'Hibátlan külső', 'Repedt hátlap', 'Enyhén horpadt keret', ''];

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
        $t['beszallito'] = ['Mobil Alkatrész Kft.', 'Pannon Parts Zrt.', 'Euro GSM Nagyker', 'TechSupply Bt.', 'Dunamenti Elektro Kft.', 'Kelet Import Kft.'];
        $t['hiba'] = ['Nem kapcsol be', 'Betört a kijelző', 'Gyorsan merül az akkumulátor', 'Nem tölt', 'Vízbe esett', 'Nem működik a kamera', 'Recseg a hangszóró', 'Nem hallanak hívás közben', 'Lefagy, újraindul', 'Nem kapcsolódik a wifihez', 'Melegszik', 'Nem ismeri fel a SIM-kártyát', 'Szellemérintés a kijelzőn', 'Nem működik a Face ID', 'Elfelejtett jelszó / zár', 'Billentyűk nem működnek', 'Zajos ventilátor', 'Nincs kép a monitoron', 'Kontroller drift', 'Adatmentés kell'];
        $t['megj'] = ['', '', '', 'Sürgős, munkához kell.', 'Hívjuk, ha elkészült.', 'Árajánlatot kér javítás előtt.', 'Garanciális ügy lehet – számla nála.', 'Az adatokra vigyázni kell.', 'Délután 4 után érhető el.'];

        return self::$tar = $t;
    }

    /** @return array<string, string> egy kitalált ügyfél */
    public static function ugyfel_adat(int $i): array
    {
        $t = self::tar();
        self::mag('ugyfel', $i);

        $ceg  = mt_rand(1, 100) <= 10;
        $no   = mt_rand(0, 1) === 1;
        $kn   = self::v($no ? $t['knev_n'] : $t['knev_f']);
        $nev  = self::v($t['vnev']) . ' ' . $kn . (mt_rand(1, 100) <= 6 ? ' ' . self::v($no ? $t['knev_n'] : $t['knev_f']) : '');
        $hely = self::v($t['irsz']);
        $cim  = self::v($t['utca']) . ' ' . self::v($t['kozt']) . ' ' . mt_rand(1, 140) . '.' . (mt_rand(1, 100) <= 25 ? ' ' . mt_rand(1, 10) . '/' . mt_rand(1, 20) . '.' : '');
        $tel  = self::telefon();
        $kapcs = '';

        if ($ceg) {
            $kapcs = $nev;
            $nev   = self::v($t['vnev']) . ' ' . self::v($t['cegszo']) . ' ' . self::v($t['cegforma']);
        }

        return [
            'tipus'                   => $ceg ? 'ceg' : 'maganszemely',
            'nev'                     => mb_substr($nev, 0, 190),
            'kapcsolattarto'          => $kapcs,
            'adoszam'                 => $ceg ? sprintf('0000%04d-%d-%02d', mt_rand(0, 9999), mt_rand(1, 5), mt_rand(2, 44)) : '',
            'telefon'                 => $tel,
            'telefon2'                => mt_rand(1, 100) <= 12 ? self::telefon() : '',
            'email'                   => mt_rand(1, 100) <= 85 ? self::ascii($nev) . mt_rand(1, 99) . '@pelda.hu' : '',
            'szamlazasi_iranyitoszam' => (string) $hely[0],
            'szamlazasi_telepules'    => (string) $hely[1],
            'szamlazasi_cim'          => $cim,
            'szamlazasi_orszag'       => 'Magyarország',
            'kategoria'               => mt_rand(1, 100) <= 12 ? self::v(['Törzsvásárló', 'Viszonteladó', 'Szerződéses partner', 'Közület']) : '',
            'kedvezmeny'              => mt_rand(1, 100) <= 8 ? (string) self::v([3, 5, 10, 15]) : '0',
            'aktiv'                   => mt_rand(1, 80) === 1 ? '0' : '1',
        ];
    }

    /* =================================================================
     * Szakaszok
     * ============================================================== */

    /** @return array<int, array{nev: string, brutto: float, id: int}> */
    private static function gen_termek(int $alap, int $db): array
    {
        $t      = self::tar();
        $sorok  = [];
        $mozgas = [];
        $ki     = [];

        for ($k = 0; $k < $db; $k++) {
            self::mag('termek', $k);
            $id  = $alap + $k;
            $tp  = self::v($t['termek']);
            $gy  = self::v($t['gyarto']);
            $ar  = (float) mt_rand((int) ($tp[3] * 0.4), (int) $tp[3]);
            $br  = round($ar / 10) * 10;
            $nev = $tp[0] . ' – ' . $gy[0] . ' ' . self::v($gy[2]) . ' (' . self::v($t['szin']) . ')';
            $kes = mt_rand(0, 25);
            $ki[] = ['nev' => mb_substr($nev, 0, 255), 'brutto' => $br, 'id' => $id];

            $sorok[] = '(' . implode(',', [
                $id, self::q($ki[$k]['nev']), self::q($tp[1]), self::q(self::v($t['beszallito'])),
                self::q('CS-' . strtoupper(substr(md5('bm-c' . $k), 0, 8))), self::q('TK' . str_pad((string) $id, 6, '0', STR_PAD_LEFT)),
                self::q((string) (2000000000000 + $k)), 1, $kes, mt_rand(0, 3), self::n(round(max((float) $tp[2], $ar * 0.45) / 1.27, 2)), self::n($br),
                1, self::q(self::JEL), self::q(self::KULSO . 'p-' . $id), self::q(self::nap(mt_rand(30, self::NAPOK), '09:00:00')),
            ]) . ')';

            if ($kes > 0) {
                $mozgas[] = '(' . $id . ",'nyito'," . $kes . ',' . self::q('Nyitókészlet') . ',' . self::q(self::nap(self::NAPOK, '08:00:00')) . ')';
            }
        }

        self::beir('termek', 'id, megnevezes, kategoria, beszallito, cikkszam, termekkod, vonalkod, keszletkezeles, keszlet, min_keszlet, besz_netto, brutto_ar, aktiv, forras, kulso_azonosito, letrehozva', $sorok);
        self::beir('termek_mozgas', 'termek_id, tipus, mennyiseg, megjegyzes, letrehozva', $mozgas);

        return $ki;
    }

    /** @return array<int, array{nev: string, brutto: float}> */
    private static function gen_szolgaltatas(int $alap, int $db): array
    {
        $t     = self::tar();
        $sorok = [];
        $ki    = [];

        for ($k = 0; $k < $db; $k++) {
            self::mag('szolg', $k);
            $s   = self::v($t['szolg']);
            $gy  = self::v($t['gyarto']);
            $ar  = (float) (round(mt_rand($s[1], $s[2]) / 100) * 100);
            $nev = $s[0] . ' – ' . $gy[0] . ' ' . self::v($gy[2]);

            // Ugyanaz a név kétszer ne legyen (a kulcs egyedi a műhelyben): sorszám a végére, ha kell.
            $nev .= isset($ki[$nev]) ? ' (' . ($k + 1) . ')' : '';
            $ki[$nev] = ['nev' => $nev, 'brutto' => $ar];

            $sorok[] = '(' . implode(',', [
                $alap + $k, self::q($nev), self::q(SDH_Muhely_Szolgaltatas::kulcs($nev)), self::n($ar),
                self::n(mt_rand(1, 100) <= 15 ? $ar + round(mt_rand(2000, 15000) / 100) * 100 : 0.0),
                self::q(ucfirst((string) $gy[1])), self::q(self::MEGJ), str_starts_with($nev, 'Bevizsgálási díj') ? 1 : 0, mt_rand(0, 400),
                self::q(self::nap(mt_rand(30, self::NAPOK), '09:00:00')),
            ]) . ')';
        }

        self::beir('szolgaltatas', 'id, nev, kulcs, brutto_ar, ar_max, kategoria, megjegyzes, bevizsgalas, hasznalat, letrehozva', $sorok);

        return array_values($ki);
    }

    /** @return array<int, string> az ügyfelek neve (index szerint) */
    private static function gen_ugyfel(int $alap, int $db): array
    {
        $sorok  = [];
        $szavak = [];
        $nevek  = [];

        for ($k = 0; $k < $db; $k++) {
            $id = $alap + $k;
            $u  = self::ugyfel_adat($k);
            $u['ugyfel_szam'] = sprintf('U-%06d', $id);
            $kx = SDH_Muhely_Ugyfel::kereso_adat($u);
            $nevek[$k] = $u['nev'];
            self::mag('ugyfel-ido', $k);

            $sorok[] = '(' . implode(',', [
                $id, self::q($u['ugyfel_szam']), self::q($u['tipus']), self::q($u['nev']), self::q($u['kapcsolattarto']), self::q($u['adoszam']),
                self::q($u['telefon']), self::q($u['telefon2']), self::q($u['email']), self::q($u['szamlazasi_iranyitoszam']), self::q($u['szamlazasi_telepules']),
                self::q($u['szamlazasi_cim']), self::q($u['szamlazasi_orszag']), self::q($u['kategoria']), $u['kedvezmeny'], (int) $u['aktiv'],
                self::q(self::JEL), self::q(self::KULSO . 'u-' . $id), self::q(self::nap(self::napja(), self::ido())),
                self::q($kx['kereso']), self::q($kx['tel_ford']), self::q($kx['cim_rendez']),
            ]) . ')';

            foreach (SDH_Muhely_Ugyfel::kereso_szavak($u) as $szo) {
                $szavak[(string) $szo . '|' . $id] = '(' . self::q((string) $szo) . ',' . $id . ')';
            }
        }

        self::fut('DELETE FROM ' . self::t('ugyfel_szo') . ' WHERE ugyfel_id >= ' . $alap . ' AND ugyfel_id < ' . ($alap + $db));
        self::beir('ugyfel', 'id, ugyfel_szam, tipus, nev, kapcsolattarto, adoszam, telefon, telefon2, email, szamlazasi_iranyitoszam, szamlazasi_telepules, szamlazasi_cim, szamlazasi_orszag, kategoria, kedvezmeny, aktiv, forras, kulso_azonosito, letrehozva, kereso, tel_ford, cim_rendez', $sorok);
        self::beir('ugyfel_szo', 'szo, ugyfel_id', array_values($szavak), 1000);

        return $nevek;
    }

    /**
     * @param array{fajlok: array<string, array<int, string>>, url: string} $kepek
     * @return array<int, array{ugyfel: int, nev: string, kategoria: string}> eszköz index => tulajdonos index, megnevezés
     */
    private static function gen_eszkoz(int $alap, int $db, int $ualap, int $udb, array $kepek): array
    {
        $t     = self::tar();
        $sorok = [];
        $ki    = [];

        for ($k = 0; $k < $db; $k++) {
            self::mag('eszkoz', $k);
            $gy   = self::v($t['gyarto']);
            $tip  = self::v($gy[2]);
            $kat  = (string) $gy[1];
            $imei = in_array($kat, ['telefon', 'tablet'], true) ? self::imei('35' . sprintf('%06d', mt_rand(0, 999999)) . sprintf('%06d', mt_rand(0, 999999))) : '';
            // Az első ügyfelek mind kapnak készüléket, a többi eszköz véletlen ügyfélhez (van, akinek több is van).
            $u    = $k < $udb ? $k : mt_rand(0, $udb - 1);
            $lista = $kepek['fajlok'][$kat] ?? [];
            $kep  = $lista !== [] ? $kepek['url'] . '/' . $lista[$k % count($lista)] : '';
            $ki[$k] = ['ugyfel' => $u, 'nev' => $gy[0] . ' ' . $tip, 'kategoria' => $kat];

            $sorok[] = '(' . implode(',', [
                $alap + $k, $ualap + $u, self::q($kat), self::q($gy[0]), self::q($tip), self::q($gy[0] . ' ' . $tip),
                self::q($imei), self::q(strtoupper(substr(md5('bm-sz' . $k), 0, 11))), self::q(self::v($t['szin'])), mt_rand(1, 100) <= 20 ? 1 : 0,
                self::q(self::v($t['tartozek'])), self::q(self::v($t['allapot'])), self::q($kep),
                1, self::q(self::JEL), self::q(self::KULSO . 'e-' . ($alap + $k)), self::q(self::nap(self::napja(), '10:00:00')),
            ]) . ')';
        }

        self::beir('eszkoz', 'id, ugyfel_id, kategoria, gyarto, tipus, megnevezes, imei, sorozatszam, szin, garancias, tartozekok, atveteli_allapot, kep_url, aktiv, forras, kulso_azonosito, letrehozva', $sorok);

        return $ki;
    }

    /**
     * Munkalapok tételekkel és hibasorokkal (a végösszeg a tételek összege).
     *
     * @param array<string, int> $a
     * @param array<string, int> $db
     * @param array<int, int>    $felelosok
     * @param array<int, array{ugyfel: int, nev: string, kategoria: string}> $eszkozok
     * @param array<int, string> $ugyfelek
     * @param array<int, array{nev: string, brutto: float, id: int}> $termekek
     * @param array<int, array{nev: string, brutto: float}> $szolg
     * @return array{fizetett: array<int, array<string, mixed>>, nyitott: array<int, int>, tetelek: int, azon: array<int, int>}
     */
    private static function gen_munkalap(array $a, array $db, array $felelosok, array $eszkozok, array $ugyfelek, array $termekek, array $szolg): array
    {
        $t       = self::tar();
        $allapot = SDH_Muhely_Munkalap::allapotok();
        $zart    = array_keys(array_filter($allapot, static fn ($x): bool => !empty($x['zart'])));
        $nyitott = array_values(array_diff(array_keys($allapot), $zart)) ?: array_keys($allapot);
        $zart    = $zart ?: array_keys($allapot);
        $rend    = class_exists('SDH_Muhely_Eladas') ? array_keys(SDH_Muhely_Eladas::rend_allapotok()) : ['felveve'];
        $hall    = array_keys(SDH_Muhely_Munkalap::hiba_allapotok()) ?: ['uj'];
        $modok   = ['Készpénz', 'Bankkártya', 'Átutalás'];
        $lapok   = [];
        $tetelek = [];
        $hibak   = [];
        $fiz     = [];
        $nyit    = [];
        $azon    = [];
        $felelosok = $felelosok ?: [get_current_user_id()];

        for ($k = 0; $k < $db['munkalap']; $k++) {
            self::mag('munkalap', $k);
            $id     = $a['munkalap'] + $k;
            $r      = mt_rand(1, 100);
            $fajta  = $r <= 80 ? 'javitas' : ($r <= 93 ? 'arukiadas' : 'rendeles');
            $napja  = self::napja();
            $lezart = $napja > 14 ? mt_rand(1, 100) <= 92 : mt_rand(1, 100) <= 30;
            $all    = $lezart ? self::v($zart) : self::v($nyitott);
            $ei     = mt_rand(0, count($eszkozok) - 1);
            $esz    = $eszkozok[$ei];
            $ui     = (int) $esz['ugyfel'];
            $keszult = self::nap($napja);
            $n      = mt_rand(1, 3);
            $brutto = 0.0;

            for ($j = 0; $j < $n; $j++) {
                $termek = $fajta !== 'javitas' || mt_rand(1, 100) <= 30;

                if ($termek && $termekek !== []) {
                    $p = $termekek[mt_rand(0, count($termekek) - 1)];
                    $nv = $p['nev'];
                    $br = (float) $p['brutto'];
                    $tid = (int) $p['id'];
                } else {
                    $s   = $szolg[mt_rand(0, count($szolg) - 1)];
                    $nv  = $s['nev'];
                    $br  = (float) $s['brutto'];
                    $tid = 0;
                    $termek = false;
                }

                $brutto += $br;
                $ne      = round($br / 1.27, 2);
                $tetelek[] = '(' . implode(',', [
                    $id, $j, self::q($termek ? 'termek' : 'szolgaltatas'), self::q('teljesitett'), self::q(mb_substr($nv, 0, 255)),
                    $tid, self::n($ne), self::n($br), self::n($ne), self::n($br), self::q(self::JEL), self::q(self::KULSO . 't-' . $id . '-' . $j),
                ]) . ')';
            }

            if ($fajta === 'javitas') {
                $hibak[] = '(' . $id . ',0,' . self::q(self::v($t['hiba'])) . ',' . self::q($lezart ? (string) end($hall) : (string) self::v($hall)) . ')';
            }

            $fizetve = $lezart && mt_rand(1, 100) <= 95;
            $fizido  = $fizetve ? self::nap(max(0, $napja - mt_rand(0, 9))) : '';
            $mod     = $fizetve ? (string) self::v($modok) : '';

            if ($fizetve) {
                $fiz[] = ['id' => $id, 'szam' => $a['lapszam'] + $k, 'datum' => $fizido, 'osszeg' => $brutto, 'mod' => $mod, 'nev' => $ugyfelek[$ui] ?? ''];
            } elseif (!$lezart) {
                $nyit[] = $a['lapszam'] + $k;
            }

            if ($k % 10 === 0) {
                $azon[] = $id;
            }

            $lapok[] = '(' . implode(',', [
                $id, $a['lapszam'] + $k, self::q((string) $all), self::q((string) self::v(['Pultnál felvéve', 'Telefonon egyeztetve', 'Online foglalás', 'Futár hozta', ''])),
                $a['ugyfel'] + $ui, $fajta === 'arukiadas' && mt_rand(0, 1) ? 0 : $a['eszkoz'] + $ei, (int) self::v($felelosok),
                self::q($keszult), self::q(self::nap(max(0, $napja - mt_rand(1, 10)))),
                $lezart ? self::q(self::nap(max(0, $napja - mt_rand(0, 9)))) : 'NULL', $fizetve ? 1 : 0, $fizetve ? self::q($fizido) : 'NULL',
                self::q($mod), $fizetve ? self::n($brutto) : '0.00', self::n(round($brutto / 1.27, 2)), self::n($brutto),
                self::q((string) self::v($t['megj'])),
                self::q($fajta), self::q($fajta === 'rendeles' ? (string) self::v($rend) : ''), self::q(substr(hash_hmac('sha256', 'rma' . $id, wp_salt('nonce')), 0, 24)),
                self::q(self::JEL), self::q(self::KULSO . 'l-' . $id), self::q($keszult . ' ' . self::ido()),
            ]) . ')';
        }

        self::beir('munkalap', 'id, munkalap_szam, allapot, nev, ugyfel_id, eszkoz_id, felelos, keszult, hatarido, lezarva, fizetve, fizetes_ideje, fizetesi_mod, fizetett, netto_ertek, brutto_ertek, megjegyzes, fajta, rend_allapot, rma_token, forras, kulso_azonosito, letrehozva', $lapok, 400);
        self::beir('munkalap_hiba', 'munkalap_id, sorrend, leiras, allapot', $hibak, 1000);
        self::beir('munkalap_tetel', 'munkalap_id, sorrend, tipus, allapot, megnevezes, termek_id, netto_ar, brutto_ar, netto_ertek, brutto_ertek, forras, kulso_azonosito', $tetelek, 500);

        return ['fizetett' => $fiz, 'nyitott' => $nyit, 'tetelek' => count($tetelek), 'azon' => $azon];
    }

    /** @param array<int, array<string, mixed>> $fizetett */
    private static function gen_penztar(int $alap, int $db, array $fizetett): void
    {
        $sorok = [];
        $bev   = min(count($fizetett), (int) round($db * 0.85));
        $kiad  = $db - $bev;

        // A legfrissebb fizetett lapok bevételként (ahogy a pénztárban rögzülnének).
        usort($fizetett, static fn (array $x, array $y): int => strcmp((string) $y['datum'], (string) $x['datum']));

        for ($k = 0; $k < $bev; $k++) {
            self::mag('penztar', $k);
            $f = $fizetett[$k];
            $o = (float) $f['osszeg'];
            $sorok[] = '(' . implode(',', [
                $alap + $k, self::q((string) $f['datum']), self::q($f['datum'] . ' ' . self::ido()), self::q('bevetel'), self::q('Munkalap fizetés'),
                self::q((string) $f['nev']), $f['mod'] === 'Készpénz' ? self::n($o) : '0', $f['mod'] === 'Bankkártya' ? self::n($o) : '0', $f['mod'] === 'Átutalás' ? self::n($o) : '0',
                (int) $f['id'], self::q((string) $f['szam']), self::q(self::JEL), self::q(self::KULSO . 'k-' . ($alap + $k)), self::q($f['datum'] . ' 12:00:00'),
            ]) . ')';
        }

        for ($k = 0; $k < $kiad; $k++) {
            self::mag('penztar-ki', $k);
            $d = self::nap(self::napja());
            $o = mt_rand(5, 300) * 100;
            $i = $alap + $bev + $k;
            $sorok[] = '(' . implode(',', [
                $i, self::q($d), self::q($d . ' ' . self::ido()), self::q('kiadas'),
                self::q((string) self::v(['Alkatrész beszerzés', 'Irodaszer', 'Futárdíj', 'Takarítószer', 'Szerszám', 'Csomagolóanyag'])), self::q(''),
                mt_rand(0, 1) ? self::n($o) : '0', '0', '0', 0, self::q(''), self::q(self::JEL), self::q(self::KULSO . 'k-' . $i), self::q($d . ' 12:00:00'),
            ]) . ')';
        }

        self::beir('penztar', 'id, datum, ido, tipus, leiras, nev, kp, kartya, utalas, munkalap_id, munkalap_szam, forras, kulso, letrehozva', $sorok);
    }

    /**
     * @param array<int, int>    $szemelyek naptár-személyek
     * @param array<int, string> $ugyfelek
     * @param array<int, int>    $nyitott nyitott munkalapok száma
     */
    private static function gen_naptar(int $alap, int $db, array $szemelyek, array $ugyfelek, int $ualap, array $nyitott): void
    {
        $szinek = array_keys(SDH_Muhely_Naptar::SZINEK);
        $sorok  = [];
        $szemelyek = $szemelyek ?: [0];
        $uszam  = max(1, count($ugyfelek));

        for ($k = 0; $k < $db; $k++) {
            self::mag('naptar', $k);
            $nap   = mt_rand(-45, 45);
            $ora   = mt_rand(8, 16);
            $perc  = self::v([0, 15, 30, 45]);
            $hossz = self::v([15, 30, 30, 60, 60, 90, 120]);
            $kezd  = wp_date('Y-m-d', time() + $nap * DAY_IN_SECONDS) . sprintf(' %02d:%02d:00', $ora, $perc);
            $veg   = gmdate('Y-m-d H:i:s', strtotime($kezd . ' UTC') + $hossz * 60);
            $ui    = mt_rand(0, $uszam - 1);
            $tipus = mt_rand(1, 6);
            $lap   = $nyitott !== [] && $tipus <= 2 ? (string) self::v($nyitott) : '';
            $nev   = $ugyfelek[$ui] ?? '';
            $cim   = match ($tipus) {
                1       => 'Átadás: ' . $nev,
                2       => 'Visszahívás: ' . $nev,
                3       => 'Alkatrész érkezik – ' . self::v(self::tar()['beszallito']),
                4       => (string) self::v(['Heti csapatmegbeszélés', 'Leltár', 'Pénztárzárás ellenőrzése', 'Műhelytakarítás']),
                5       => 'Helyszíni javítás: ' . $nev,
                default => (string) self::v(['Oktatás: új alkatrész-szállító', 'Szerszámkalibrálás', 'Szabadság']),
            };
            $all   = $nap < 0 ? (mt_rand(1, 10) <= 9 ? 'kesz' : 'elmaradt') : ($nap === 0 ? 'folyamatban' : 'tervezett');
            // Ügyfélhez kötött esemény helyett az ügyfél neve a címben: a műhely 0.99.5 Naptára az ügyfélhez kötött
            // eseménynél privát metódust hív (SDH_Muhely_Ugyfel::egy) – végzetes hiba. A javítás után az ugyfel_id
            // oszlopba $ualap + $ui mehet (az 1., 2. és 5. típusnál).

            $sorok[] = '(' . implode(',', [
                $alap + $k, (int) self::v($szemelyek), self::q(mb_substr($cim, 0, 190)), self::q(''), self::q($tipus === 5 ? 'Helyszín: az ügyfél címe' : 'Műhely'),
                self::q($kezd), self::q($veg), $tipus === 6 && mt_rand(0, 1) ? 1 : 0, self::q((string) self::v($szinek)), self::q(mt_rand(1, 10) === 1 ? 'fontos' : 'normal'),
                self::q($all), 0, self::q($lap), self::q(self::JEL), self::q($kezd),
            ]) . ')';
        }

        self::beir('naptar_esemeny', 'id, szemely_id, cim, leiras, hely, kezdet, veg, egesz_nap, szin, prioritas, allapot, ugyfel_id, munkalap_szam, forras, letrehozva', $sorok);
    }

    /* =================================================================
     * Képek
     * ============================================================== */

    /** A képmappa: konstans, a bővítmény saját kepek/ mappája, vagy a tároló demo-adat/kepek mappája. */
    public static function kep_forras(?string $mappa = null): string
    {
        $jeloltek = array_filter([
            $mappa,
            defined('BEMUTATO_KEPEK') ? (string) BEMUTATO_KEPEK : null,
            SDH_DEMO_DIR . 'kepek',
            dirname(SDH_DEMO_DIR, 2) . '/demo-adat/kepek',
        ]);

        foreach ($jeloltek as $m) {
            if (is_readable(untrailingslashit((string) $m) . '/manifest.json')) {
                return untrailingslashit((string) $m);
            }
        }

        return '';
    }

    /**
     * A készülékképek átmásolása a feltöltési mappába (uploads/bemutato/), kategóriánként.
     *
     * @return array{fajlok: array<string, array<int, string>>, url: string}
     */
    private static function kepek(?string $mappa): array
    {
        $forras = self::kep_forras($mappa);
        $up     = wp_upload_dir();
        $cel    = trailingslashit($up['basedir']) . 'bemutato';
        $url    = trailingslashit($up['baseurl']) . 'bemutato';
        $ki     = ['fajlok' => [], 'url' => $url];

        if ($forras === '') {
            return $ki;
        }

        $man = json_decode((string) file_get_contents($forras . '/manifest.json'), true);

        if (!is_array($man)) {
            return $ki;
        }

        wp_mkdir_p($cel);
        ksort($man);

        foreach ($man as $elem) {
            $kat  = self::KEP_KATEGORIA[(string) ($elem['kategoria'] ?? '')] ?? '';
            $fajl = (string) (($elem['valtozatok']['webp'] ?? [])[0] ?? '');

            // Csak a műhely által megjelenített formátum (webp), csak egyszerű fájlnév.
            if ($kat === '' || !preg_match('/^[a-z0-9\-]+\.webp$/', $fajl) || !is_readable($forras . '/' . $fajl)) {
                continue;
            }

            if (!is_file($cel . '/' . $fajl)) {
                copy($forras . '/' . $fajl, $cel . '/' . $fajl);
            }

            $ki['fajlok'][$kat][] = $fajl;
        }

        return $ki;
    }

    /** Minden tizedik munkalaphoz egy átvételi fotó (a műhely csatolmány-tárolásával). @param array<int, int> $lapok */
    private static function atveteli_fotok(array $lapok, ?string $mappa): int
    {
        $forras = self::kep_forras($mappa);

        if ($forras === '' || !class_exists('SDH_Muhely_Csatolmany') || $lapok === []) {
            return 0;
        }

        $kepek = glob($forras . '/{telefon,tablet,laptop}-*.webp', GLOB_BRACE) ?: [];
        sort($kepek);

        if ($kepek === []) {
            return 0;
        }

        $db = 0;

        SDH_Demo_Keret::csendben(static function () use ($lapok, $kepek, &$db): void {
            foreach ($lapok as $i => $lap) {
                $f = $kepek[$i % count($kepek)];
                $db += SDH_Muhely_Csatolmany::tarol_bajt('munkalap', (int) $lap, 'atvetel-' . ($i + 1) . '.webp', 'image/webp', (string) file_get_contents($f)) > 0 ? 1 : 0;
            }
        });

        return $db;
    }

    /* =================================================================
     * Munkatársak, beállítások
     * ============================================================== */

    /** Négy kitalált munkatárs (felelősök) és a naptár-személyeik. @return array{felhasznalok: array<int, int>, szemelyek: array<int, int>} */
    private static function munkatarsak(): array
    {
        global $wpdb;

        $lista = [
            ['munkatars-bence', 'Kovács Bence', 'kek'],
            ['munkatars-reka', 'Szabó Réka', 'zold'],
            ['munkatars-adam', 'Tóth Ádám', 'narancs'],
            ['munkatars-eszter', 'Molnár Eszter', 'lila'],
        ];
        $szerep = class_exists('SDH_Muhely_Csapat') ? SDH_Muhely_Csapat::SZEREP : 'subscriber';
        $ki     = ['felhasznalok' => [], 'szemelyek' => []];
        $np     = self::t('naptar_szemely');

        foreach ($lista as $i => [$login, $nev, $szin]) {
            $u = get_user_by('login', $login);

            if (!$u instanceof WP_User) {
                [$vn, $kn] = explode(' ', $nev, 2);
                $id = wp_insert_user([
                    'user_login'   => $login,
                    'user_pass'    => wp_generate_password(40, true, true),
                    'user_email'   => $login . '@pelda.hu',
                    'display_name' => $nev,
                    'first_name'   => $kn,
                    'last_name'    => $vn,
                    'role'         => $szerep,
                ]);

                if (is_wp_error($id)) {
                    continue;
                }

                $u = get_user_by('id', (int) $id);
            }

            $ki['felhasznalok'][] = (int) $u->ID;
            $sz = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$np} WHERE user_id = %d", (int) $u->ID));

            if ($sz === 0) {
                $wpdb->insert($np, ['nev' => $nev, 'szin' => $szin, 'email' => $login . '@pelda.hu', 'user_id' => (int) $u->ID, 'aktiv' => 1, 'sorrend' => $i, 'letrehozva' => current_time('mysql')]);
                $sz = (int) $wpdb->insert_id;
            }

            $ki['szemelyek'][] = $sz;
        }

        return $ki;
    }

    /** A bemutató kezdő beállításai: kitalált cégadatok, SMS szimulációval, kétlépcsős ki. Csak az üres mezőket tölti. */
    private static function beallitasok(): void
    {
        $szamla = get_option('sdh_muhely_szamla', []);
        $szamla = is_array($szamla) ? $szamla : [];
        $ceg    = [
            'kiallito_nev'     => 'Mintaszerviz Kft.',
            'kiallito_cim1'    => '1234 Mintaváros, Fő utca 1.',
            'kiallito_cim2'    => 'Nyitva: H–P 9–17',
            'kiallito_adoszam' => '00000000-0-00',
            'bank_nev'         => 'Mintabank',
            'bankszamla'       => '00000000-00000000-00000000',
            'levelezes'        => 'szerviz@pelda.hu',
            'telefon'          => '+36 30 000 0000',
        ];

        foreach ($ceg as $k => $v) {
            if (trim((string) ($szamla[$k] ?? '')) === '') {
                $szamla[$k] = $v;
            }
        }

        // A kényszerített mezőket (kulcs, előtagok) a bemutató szűrője adja – ide a nyers opció kerül.
        remove_filter('option_sdh_muhely_szamla', [SDH_Demo::class, 'szamla_opcio']);
        update_option('sdh_muhely_szamla', $szamla);
        add_filter('option_sdh_muhely_szamla', [SDH_Demo::class, 'szamla_opcio']);

        if (class_exists('SDH_Muhely_Sms')) {
            $sms = get_option(SDH_Muhely_Sms::OPT, []);
            $sms = is_array($sms) ? $sms : [];
            update_option(SDH_Muhely_Sms::OPT, array_merge($sms, ['be' => true, 'szimulacio' => true]), false);
        }

        update_option('sdh_muhely_ketlepcsos', ['szabaly' => 'ki']);
    }

    /* =================================================================
     * Törlés (csak a jelölt sorok)
     * ============================================================== */

    /** A korábban generált (jelölt) sorok törlése, a gyereksoraikkal. @return int törölt sorok */
    public static function torol(): int
    {
        global $wpdb;

        $k  = self::KULSO;
        $db = 0;
        $lepesek = [
            ['penztar', "kulso LIKE '{$k}k-%'"],
            ['munkalap_tetel', "munkalap_id IN (SELECT id FROM " . self::t('munkalap') . " WHERE kulso_azonosito LIKE '{$k}l-%')"],
            ['munkalap_hiba', "munkalap_id IN (SELECT id FROM " . self::t('munkalap') . " WHERE kulso_azonosito LIKE '{$k}l-%')"],
            ['munkalap_naplo', "munkalap_id IN (SELECT id FROM " . self::t('munkalap') . " WHERE kulso_azonosito LIKE '{$k}l-%')"],
            ['uzenet', "munkalap_id IN (SELECT id FROM " . self::t('munkalap') . " WHERE kulso_azonosito LIKE '{$k}l-%')"],
            ['sms_uzenet', "munkalap_id IN (SELECT id FROM " . self::t('munkalap') . " WHERE kulso_azonosito LIKE '{$k}l-%')"],
            ['munkalap', "kulso_azonosito LIKE '{$k}l-%'"],
            ['termek_mozgas', "termek_id IN (SELECT id FROM " . self::t('termek') . " WHERE kulso_azonosito LIKE '{$k}p-%')"],
            ['termek', "kulso_azonosito LIKE '{$k}p-%'"],
            ['szolgaltatas', "megjegyzes = '" . self::MEGJ . "'"],
            ['eszkoz', "kulso_azonosito LIKE '{$k}e-%'"],
            ['ugyfel_szo', "ugyfel_id IN (SELECT id FROM " . self::t('ugyfel') . " WHERE kulso_azonosito LIKE '{$k}u-%')"],
            ['ugyfel', "kulso_azonosito LIKE '{$k}u-%'"],
            ['naptar_esemeny', "forras = '" . self::JEL . "'"],
        ];

        // A generált lapok csatolmányai: a fájlok (a műhely csatolmány-mappájában, 32 hexa nevűek) és a sorok.
        $lapfelt = "tipus = 'munkalap' AND ref_id IN (SELECT id FROM " . self::t('munkalap') . " WHERE kulso_azonosito LIKE '{$k}l-%')";
        $mappa   = trailingslashit(wp_upload_dir()['basedir']) . 'sdh-muhely/csatolmany/';

        foreach ((array) $wpdb->get_col('SELECT tarolt_nev FROM ' . self::t('csatolmany') . ' WHERE ' . $lapfelt) as $nev) {
            if (preg_match('/^[a-f0-9]{32}$/', (string) $nev) === 1 && is_file($mappa . $nev)) {
                unlink($mappa . $nev);
            }
        }

        array_unshift($lepesek, ['csatolmany', $lapfelt]);

        $pdo = self::pdo();

        foreach ($lepesek as [$tabla, $feltetel]) {
            $sql = 'DELETE FROM ' . self::t($tabla) . ' WHERE ' . $feltetel;
            $db += $pdo !== null ? (int) $pdo->exec($sql) : (int) $wpdb->query($sql); // phpcs:ignore
        }

        return $db;
    }

    private static function befejez(): void
    {
        global $wpdb;

        foreach (['ugyfel', 'eszkoz', 'termek', 'szolgaltatas', 'munkalap', 'munkalap_tetel', 'penztar', 'naptar_esemeny'] as $t) {
            $wpdb->query((self::pdo() !== null ? 'ANALYZE ' : 'ANALYZE TABLE ') . self::t($t)); // phpcs:ignore
        }

        if (class_exists('SDH_Muhely_Gyorsitotar')) {
            SDH_Muhely_Gyorsitotar::ervenytelenit();
        }
    }
}
