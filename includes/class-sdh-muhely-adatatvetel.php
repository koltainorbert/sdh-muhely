<?php
/**
 * Adatátvétel a MunkaLap 3-ból (és bármilyen táblázatból) – U1, 0.94.0.
 *
 * A régi program a táblázatait CSV / TXT / XLS(X) formában menti (modulonként: Ügyfél, Tárgy, Munkalap, Tétel,
 * Hiba). Ez az eszköz ezeket veszi át, sorrendben: ügyfelek → eszközök → munkalapok → tételek → hibák.
 *
 * 1. Feltöltés: a fájl kódolását (UTF-8, UTF-16, Windows-1250) és elválasztóját (; , tab |) maga ismeri fel, az
 *    XLSX-et is olvassa; egységes UTF-8 CSV-vé alakítja (védett mappában).
 * 2. Oszlopok párosítása: a fejlécek alapján javaslatot tesz (magyar nevekre), ami átírható.
 * 3. Próba: végigolvassa az egészet, írás nélkül – hány sor jó, hány hibás és miért, hány van már bent,
 *    a végösszegek (ellenőrző riport).
 * 4. Átvétel: 500 soros adagokban (megszakítás után folytatható). Minden sor a régi azonosítóval
 *    (`kulso_azonosito`) kerül be, `forras = munkalap3`; a kétszer futtatás nem duplikál (a meglévőt kihagyja).
 *    A zárkód / minta titkosítva (`Zarkod::tarol`). A Változásnapló adagonként egy eseményt kap.
 * 5. Visszavonás: egy átvétel minden ÚJ sora törölhető (amit frissített vagy kihagyott, ahhoz nem nyúl).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Adatatvetel
{
    public const OPT_FAJLOK = 'sdh_muhely_atvetel_fajlok';
    public const OPT_NAPLO  = 'sdh_muhely_atvetel_naplo';
    public const FORRAS     = 'munkalap3';
    public const ADAG       = 500;
    private const MAPPA     = 'sdh-atvetel';

    public const ENTITASOK = [
        'ugyfel'   => 'Ügyfelek',
        'eszkoz'   => 'Eszközök (tárgyak)',
        'munkalap' => 'Munkalapok',
        'tetel'    => 'Tételek',
        'hiba'     => 'Hibák',
    ];

    public static function init(): void
    {
        foreach (['feltolt', 'proba', 'futtat', 'visszavon'] as $m) {
            add_action('wp_ajax_sdh_muhely_atvetel_' . $m, [self::class, 'ajax_' . $m]);
        }
    }

    public static function sor_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('atvetel_sor');
    }

    /* =================================================================
     * Célmezők és fejléc-javaslatok
     * ============================================================== */

    /**
     * Entitásonként a célmezők: kulcs => [felirat, kötelező, fejléc-álnevek (ékezet nélkül, kisbetűvel)].
     *
     * @return array<string, array{0: string, 1: bool, 2: array<int, string>}>
     */
    public static function mezok(string $entitas): array
    {
        $azon = ['azonosito', 'id', 'kod', 'rekord_azonosito'];

        $m = [
            'ugyfel' => [
                'kulso_azonosito'         => ['Régi azonosító', true, array_merge($azon, ['ugyfel_azonosito', 'ugyfelkod', 'ugyfel_kod', 'ugyfel_id'])],
                'nev'                     => ['Név', true, ['nev', 'ugyfel', 'ugyfel_neve', 'ugyfelnev', 'cegnev', 'nev_cegnev', 'megnevezes']],
                'telefon'                 => ['Telefon', false, ['telefon', 'telefonszam', 'mobil', 'tel', 'telefon1', 'telefon_1', 'mobilszam']],
                'telefon2'                => ['Telefon 2', false, ['telefon2', 'telefon_2', 'mobil2', 'masodik_telefon', 'vezetekes']],
                'email'                   => ['E-mail', false, ['email', 'e_mail', 'emailcim', 'e_mail_cim']],
                'adoszam'                 => ['Adószám', false, ['adoszam', 'ado_szam']],
                'kapcsolattarto'          => ['Kapcsolattartó', false, ['kapcsolattarto']],
                'szamlazasi_iranyitoszam' => ['Irányítószám', false, ['iranyitoszam', 'irsz', 'irszam']],
                'szamlazasi_telepules'    => ['Település', false, ['telepules', 'varos', 'helyseg']],
                'szamlazasi_cim'          => ['Cím (utca, házszám)', false, ['cim', 'utca', 'utca_hazszam', 'lakcim', 'szamlazasi_cim']],
                'ugyfel_szam'             => ['Ügyfélszám', false, ['ugyfelszam', 'ugyfel_szam']],
                'kategoria'               => ['Kategória', false, ['kategoria', 'csoport']],
                'megjegyzes'              => ['Megjegyzés', false, ['megjegyzes', 'megj']],
                'letrehozva'              => ['Felvéve', false, ['letrehozva', 'rogzitve', 'felveve', 'felvetel_datuma', 'datum']],
            ],
            'eszkoz' => [
                'kulso_azonosito' => ['Régi azonosító', true, array_merge($azon, ['targy_azonosito', 'targykod', 'targy_id', 'eszkoz_azonosito'])],
                'ugyfel_ref'      => ['Ügyfél régi azonosítója', false, ['ugyfel_azonosito', 'ugyfelkod', 'ugyfel_kod', 'ugyfel_id', 'ugyfel']],
                'gyarto'          => ['Gyártó', false, ['gyarto', 'marka']],
                'tipus'           => ['Típus', false, ['tipus', 'modell', 'tipus_modell']],
                'megnevezes'      => ['Megnevezés', false, ['megnevezes', 'targy', 'eszkoz', 'nev', 'targy_neve']],
                'kategoria'       => ['Kategória', false, ['kategoria', 'csoport', 'fajta', 'targy_tipusa']],
                'imei'            => ['IMEI', false, ['imei', 'imei1', 'imei_1']],
                'imei2'           => ['IMEI 2', false, ['imei2', 'imei_2']],
                'sorozatszam'     => ['Gyári szám', false, ['sorozatszam', 'gyari_szam', 'gyariszam', 'serial', 'sn', 'sorszam_gyari']],
                'szin'            => ['Szín', false, ['szin']],
                'zarkod'          => ['Feloldó kód', false, ['zarkod', 'feloldo_kod', 'jelszo', 'pin', 'kod_jelszo', 'pin_kod']],
                'minta'           => ['Feloldó minta', false, ['minta', 'feloldo_minta']],
                'tartozekok'      => ['Tartozékok', false, ['tartozek', 'tartozekok']],
                'megjegyzes'      => ['Megjegyzés', false, ['megjegyzes', 'megj']],
            ],
            'munkalap' => [
                'kulso_azonosito'   => ['Régi azonosító', true, array_merge($azon, ['munkalap_azonosito', 'munkalap_id'])],
                'munkalap_szam'     => ['Munkalapszám', false, ['munkalapszam', 'munkalap_szama', 'munkalap_szam', 'sorszam', 'szam', 'ugyiratszam']],
                'ugyfel_ref'        => ['Ügyfél régi azonosítója', false, ['ugyfel_azonosito', 'ugyfelkod', 'ugyfel_kod', 'ugyfel_id']],
                'eszkoz_ref'        => ['Eszköz régi azonosítója', false, ['targy_azonosito', 'targykod', 'targy_id', 'eszkoz_azonosito', 'eszkoz_id']],
                'allapot'           => ['Állapot', false, ['allapot', 'statusz']],
                'keszult'           => ['Készült (felvétel)', false, ['keszult', 'felvetel', 'felvetel_datuma', 'atvetel', 'atvetel_datuma', 'datum', 'letrehozva']],
                'hatarido'          => ['Határidő', false, ['hatarido']],
                'lezarva'           => ['Lezárva / kiadva', false, ['lezarva', 'lezaras', 'lezaras_datuma', 'kiadva', 'kiadas_datuma', 'atadva']],
                'ugyfel_megjegyzes' => ['Hibaleírás / ügyfél megjegyzése', false, ['ugyfel_megjegyzes', 'hibaleiras', 'hiba_leiras', 'panasz', 'bejelentett_hiba']],
                'megjegyzes'        => ['Megjegyzés', false, ['megjegyzes', 'belso_megjegyzes', 'megj']],
                'brutto_ertek'      => ['Bruttó végösszeg', false, ['brutto', 'brutto_ertek', 'brutto_osszeg', 'osszesen', 'vegosszeg']],
                'netto_ertek'       => ['Nettó végösszeg', false, ['netto', 'netto_ertek', 'netto_osszeg']],
                'fizetett'          => ['Fizetett', false, ['fizetett', 'eloleg', 'befizetve', 'befizetett']],
                'fizetesi_mod'      => ['Fizetési mód', false, ['fizetesi_mod', 'fizetes_modja', 'fizetesimod']],
            ],
            'tetel' => [
                'kulso_azonosito' => ['Régi azonosító', false, array_merge($azon, ['tetel_azonosito', 'tetel_id'])],
                'munkalap_ref'    => ['Munkalap (régi azonosító vagy szám)', true, ['munkalap_azonosito', 'munkalap_id', 'munkalapszam', 'munkalap', 'ugyiratszam']],
                'megnevezes'      => ['Megnevezés', true, ['megnevezes', 'tetel', 'nev', 'termek', 'szolgaltatas', 'tetel_neve']],
                'tipus'           => ['Típus (termék / szolgáltatás)', false, ['tipus', 'fajta', 'tetel_tipusa']],
                'mennyiseg'       => ['Mennyiség', false, ['mennyiseg', 'menny', 'db', 'darab']],
                'me'              => ['Mennyiségi egység', false, ['me', 'mennyisegi_egyseg', 'egyseg']],
                'brutto_ar'       => ['Bruttó egységár', false, ['brutto_ar', 'brutto_egysegar', 'egysegar']],
                'brutto_ertek'    => ['Bruttó érték', false, ['brutto_ertek', 'brutto', 'osszesen', 'ertek']],
                'netto_ar'        => ['Nettó egységár', false, ['netto_ar', 'netto_egysegar']],
                'netto_ertek'     => ['Nettó érték', false, ['netto_ertek', 'netto']],
                'afa_kulcs'       => ['ÁFA-kulcs', false, ['afa', 'afakulcs', 'afa_kulcs']],
                'kedvezmeny'      => ['Kedvezmény %', false, ['kedvezmeny', 'engedmeny']],
                'cikkszam'        => ['Cikkszám', false, ['cikkszam', 'termekkod', 'cikk']],
            ],
            'hiba' => [
                'munkalap_ref' => ['Munkalap (régi azonosító vagy szám)', true, ['munkalap_azonosito', 'munkalap_id', 'munkalapszam', 'munkalap', 'ugyiratszam']],
                'leiras'       => ['Hiba', true, ['leiras', 'hiba', 'hibaleiras', 'hiba_leirasa', 'megnevezes']],
                'javitas'      => ['Javítás / megoldás', false, ['javitas', 'megoldas', 'elvegzett_munka', 'javitas_leirasa']],
                'allapot'      => ['Állapot', false, ['allapot', 'statusz']],
            ],
        ];

        return $m[$entitas] ?? [];
    }

    /** Fejléc egységes alakja: ékezet nélkül, kisbetűvel, aláhúzással. */
    public static function fejlec_norm(string $s): string
    {
        $s = strtolower(remove_accents(trim($s)));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $s), '_');
    }

    /**
     * Javasolt párosítás: célmező => oszlop sorszáma (vagy -1).
     *
     * @param array<int, string> $fejlec
     * @return array<string, int>
     */
    public static function javaslat(string $entitas, array $fejlec): array
    {
        $norm = array_map([self::class, 'fejlec_norm'], $fejlec);
        $ki   = [];
        $volt = [];

        foreach (self::mezok($entitas) as $kulcs => [, , $alnevek]) {
            $ki[$kulcs] = -1;

            foreach ($alnevek as $a) {
                $i = array_search($a, $norm, true);

                if ($i !== false && !isset($volt[$i])) {
                    $ki[$kulcs] = (int) $i;
                    $volt[$i]   = true;
                    break;
                }
            }
        }

        return $ki;
    }

    /* =================================================================
     * Fájl: kódolás, elválasztó, XLSX, egységes CSV
     * ============================================================== */

    /** UTF-8-ra alakítás: BOM, UTF-16, Windows-1250. */
    public static function utf8(string $nyers): string
    {
        if (str_starts_with($nyers, "\xEF\xBB\xBF")) {
            return substr($nyers, 3);
        }

        if (str_starts_with($nyers, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($nyers, 2), 'UTF-8', 'UTF-16LE');
        }

        if (str_starts_with($nyers, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($nyers, 2), 'UTF-8', 'UTF-16BE');
        }

        if (mb_check_encoding($nyers, 'UTF-8')) {
            return $nyers;
        }

        $k = function_exists('iconv') ? @iconv('CP1250', 'UTF-8//IGNORE', $nyers) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors

        return is_string($k) && $k !== '' ? $k : (string) mb_convert_encoding($nyers, 'UTF-8', 'ISO-8859-2');
    }

    /** Az elválasztó az első sorokból (amelyikből a legtöbb és legegyenletesebb). */
    public static function elvalaszto(string $szoveg): string
    {
        $sorok = array_slice(preg_split('/\R/', $szoveg) ?: [], 0, 10);
        $legjobb = ';';
        $pont    = -1;

        foreach ([';', ',', "\t", '|'] as $e) {
            $db = array_map(static fn (string $s): int => count(str_getcsv($s, $e, '"', '\\')), array_filter($sorok, 'strlen'));

            if ($db === []) {
                continue;
            }

            $p = min($db) > 1 ? min($db) * 10 - (max($db) - min($db)) : 0;

            if ($p > $pont) {
                $pont    = $p;
                $legjobb = $e;
            }
        }

        return $legjobb;
    }

    /**
     * XLSX első munkalapja sorokként.
     *
     * @return array<int, array<int, string>>|null
     */
    public static function xlsx_sorok(string $ut): ?array
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $z = new ZipArchive();

        if ($z->open($ut) !== true) {
            return null;
        }

        $kozos = [];
        $ss    = $z->getFromName('xl/sharedStrings.xml');

        if (is_string($ss) && $ss !== '') {
            $x = @simplexml_load_string($ss); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            if ($x) {
                foreach ($x->si as $si) {
                    if (isset($si->t)) {
                        $kozos[] = (string) $si->t;
                    } else {
                        $t = '';

                        foreach ($si->r as $r) {
                            $t .= (string) $r->t;
                        }

                        $kozos[] = $t;
                    }
                }
            }
        }

        $lap = $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();

        if (!is_string($lap)) {
            return null;
        }

        $x = @simplexml_load_string($lap); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if (!$x || !isset($x->sheetData)) {
            return null;
        }

        $sorok = [];

        foreach ($x->sheetData->row as $row) {
            $sor = [];

            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $oszlop = 0;

                foreach (str_split((string) preg_replace('/\d+/', '', $ref)) as $b) {
                    $oszlop = $oszlop * 26 + (ord($b) - 64);
                }

                $tip = (string) $c['t'];
                $v   = $tip === 'inlineStr' ? (string) $c->is->t : (string) $c->v;

                if ($tip === 's') {
                    $v = $kozos[(int) $v] ?? '';
                }

                $sor[max(0, $oszlop - 1)] = $v;
            }

            if ($sor !== []) {
                $max = max(array_keys($sor));
                $tele = [];

                for ($i = 0; $i <= $max; $i++) {
                    $tele[] = $sor[$i] ?? '';
                }

                $sorok[] = $tele;
            }
        }

        return $sorok;
    }

    private static function mappa(): string
    {
        $up = wp_upload_dir();
        $m  = trailingslashit($up['basedir']) . self::MAPPA;

        if (!is_dir($m)) {
            wp_mkdir_p($m);
        }

        if (!is_file($m . '/.htaccess')) {
            file_put_contents($m . '/.htaccess', "Require all denied\nDeny from all\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
            file_put_contents($m . '/index.php', "<?php\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        return $m;
    }

    /**
     * Egy feltöltött fájl átvétele: egységes UTF-8 CSV (pontosvessző) a védett mappában.
     *
     * @return array<string, mixed>|string a fájl adatai, vagy a hibaüzenet
     */
    public static function fajl_be(string $tmp, string $eredeti_nev, string $entitas)
    {
        if (!isset(self::ENTITASOK[$entitas])) {
            return 'Válaszd ki, mit tartalmaz a fájl.';
        }

        if (!is_file($tmp) || filesize($tmp) === 0) {
            return 'A fájl üres vagy nem olvasható.';
        }

        $kit = strtolower(pathinfo($eredeti_nev, PATHINFO_EXTENSION));

        if ($kit === 'xls') {
            return 'A régi .xls formátumot nem tudom olvasni: mentsd .xlsx-ként vagy CSV-ként (a táblázatkezelőben: Mentés másként).';
        }

        if ($kit === 'xlsx') {
            $sorok = self::xlsx_sorok($tmp);

            if ($sorok === null) {
                return 'Az XLSX-fájl nem olvasható.';
            }

            $kodolas = 'XLSX';
            $elv     = 'XLSX';
        } else {
            $szoveg  = self::utf8((string) file_get_contents($tmp)); // phpcs:ignore WordPress.WP.AlternativeFunctions
            $kodolas = mb_check_encoding((string) file_get_contents($tmp, false, null, 0, 65536), 'UTF-8') ? 'UTF-8' : 'Windows-1250 / UTF-16'; // phpcs:ignore WordPress.WP.AlternativeFunctions
            $elv     = self::elvalaszto($szoveg);
            $sorok   = [];
            $f       = fopen('php://temp', 'w+'); // phpcs:ignore WordPress.WP.AlternativeFunctions
            fwrite($f, $szoveg); // phpcs:ignore WordPress.WP.AlternativeFunctions
            rewind($f);

            while (($s = fgetcsv($f, 0, $elv, '"', '\\')) !== false) {
                if ($s !== [null]) {
                    $sorok[] = array_map(static fn ($v): string => trim((string) $v), $s);
                }
            }

            fclose($f); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        if (count($sorok) < 2) {
            return 'A fájlban nincs adat (legalább egy fejléc- és egy adatsor kell).';
        }

        $id  = strtolower(wp_generate_password(12, false, false));
        $ut  = self::mappa() . '/' . $id . '.csv';
        $out = fopen($ut, 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions

        foreach ($sorok as $s) {
            fputcsv($out, $s, ';', '"', '\\');
        }

        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions

        $fejlec = $sorok[0];
        $adat   = [
            'id'       => $id,
            'nev'      => sanitize_file_name($eredeti_nev),
            'ut'       => $ut,
            'entitas'  => $entitas,
            'sorok'    => count($sorok) - 1,
            'fejlec'   => $fejlec,
            'kodolas'  => $kodolas,
            'elvalaszto' => $elv === "\t" ? 'tabulátor' : $elv,
            'ido'      => current_time('mysql'),
        ];

        $fajlok = get_option(self::OPT_FAJLOK, []);
        $fajlok = is_array($fajlok) ? $fajlok : [];
        $fajlok[$id] = $adat;
        update_option(self::OPT_FAJLOK, $fajlok, false);

        return $adat + ['minta' => array_slice($sorok, 1, 5), 'javaslat' => self::javaslat($entitas, $fejlec)];
    }

    /** @return array<string, mixed>|null */
    public static function fajl(string $id): ?array
    {
        $f = get_option(self::OPT_FAJLOK, []);

        return is_array($f) && isset($f[$id]) && is_file((string) $f[$id]['ut']) ? $f[$id] : null;
    }

    /* =================================================================
     * Értékek értelmezése
     * ============================================================== */

    /** Dátum: 2024-03-05, 2024.03.05., 05.03.2024, Excel-sorszám → Y-m-d, különben ''. */
    public static function datum(string $v): string
    {
        $v = trim($v);

        if ($v === '') {
            return '';
        }

        if (preg_match('/^(\d{4})[.\-\/](\d{1,2})[.\-\/](\d{1,2})/', $v, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : '';
        }

        if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})/', $v, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '';
        }

        if (preg_match('/^\d{5}(\.\d+)?$/', $v) === 1) {
            return gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
        }

        return '';
    }

    /** Pénz / szám: „10 400,50 Ft” → 10400.5. */
    public static function szam(string $v): float
    {
        $v = str_replace(["\xC2\xA0", ' ', 'Ft', 'HUF', 'ft', '%'], '', trim($v));

        if ($v === '') {
            return 0.0;
        }

        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = strrpos($v, ',') > strrpos($v, '.') ? str_replace(['.', ','], ['', '.'], $v) : str_replace(',', '', $v);
        } else {
            $v = str_replace(',', '.', $v);
        }

        return is_numeric($v) ? (float) $v : 0.0;
    }

    /**
     * Régi állapot → új állapotkulcs. A saját párosítás („régi = új” soronként) elöl.
     *
     * @param array<string, string> $sajat
     */
    public static function allapot(string $regi, array $sajat = []): string
    {
        $lista = SDH_Muhely_Munkalap::allapotok();
        $n     = self::fejlec_norm($regi);

        foreach ($sajat as $r => $u) {
            if (self::fejlec_norm($r) === $n && isset($lista[$u])) {
                return $u;
            }
        }

        if (isset($lista[$n])) {
            return $n;
        }

        $jelzo = static function (string $j) use ($lista): string {
            foreach ($lista as $k => $a) {
                if (!empty($a[$j])) {
                    return (string) $k;
                }
            }

            return '';
        };

        if (preg_match('/erveny|storn|torol|visszavon/', $n) === 1 && ($k = $jelzo('ervenytelen')) !== '') {
            return $k;
        }

        if (preg_match('/lezar|kiadv|atadv|visszaad|elvitt|atvett/', $n) === 1 && ($k = SDH_Muhely_Munkalap::lezart_kulcs()) !== '') {
            return $k;
        }

        if (preg_match('/kesz|elkeszult|javitva|megjavit/', $n) === 1 && ($k = $jelzo('kesz')) !== '') {
            return $k;
        }

        if (str_contains($n, 'arajanlat') && isset($lista['arajanlat'])) {
            return 'arajanlat';
        }

        if (preg_match('/fugg|var|alkatresz/', $n) === 1 && isset($lista['fuggo'])) {
            return 'fuggo';
        }

        return SDH_Muhely_Munkalap::alap_kulcs($lista);
    }

    /** @return array<string, string> „régi = új” sorokból */
    public static function allapot_parok(string $szoveg): array
    {
        $ki = [];

        foreach (preg_split('/\R/', $szoveg) ?: [] as $s) {
            if (str_contains($s, '=')) {
                [$r, $u] = array_map('trim', explode('=', $s, 2));

                if ($r !== '' && $u !== '') {
                    $ki[$r] = sanitize_key($u);
                }
            }
        }

        return $ki;
    }

    /* =================================================================
     * Sorok feldolgozása
     * ============================================================== */

    private static function tabla(string $entitas): string
    {
        return SDH_Muhely_Schema::tabla(['ugyfel' => 'ugyfel', 'eszkoz' => 'eszkoz', 'munkalap' => 'munkalap', 'tetel' => 'munkalap_tetel', 'hiba' => 'munkalap_hiba'][$entitas]);
    }

    /** Régi azonosító → új id (gyorsítótárazva egy futáson belül). */
    private static function hivatkozas(string $entitas, string $regi): int
    {
        static $tar = [];

        global $wpdb;

        $regi = trim($regi);

        if ($regi === '') {
            return 0;
        }

        $k = $entitas . '|' . $regi;

        if (!isset($tar[$k])) {
            $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::tabla($entitas) . ' WHERE kulso_azonosito = %s LIMIT 1', $regi));

            if ($id === 0 && $entitas === 'munkalap' && ctype_digit($regi)) {
                $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::tabla('munkalap') . ' WHERE munkalap_szam = %d LIMIT 1', (int) $regi));
            }

            $tar[$k] = $id;

            if (count($tar) > 20000) {
                $tar = [];
            }
        }

        return $tar[$k];
    }

    /**
     * Egy sor átalakítása a cél-tábla soraivá. Hiba: üzenet; kihagyás (már bent van): 'meglevo'.
     *
     * @param array<int, string> $sor
     * @param array<string, int> $par célmező => oszlop
     * @param array<string, mixed> $opc allapot_parok
     * @return array<string, mixed>|string
     */
    public static function sor_adat(string $entitas, array $sor, array $par, array $opc = [])
    {
        global $wpdb;

        $v = static function (string $mezo) use ($sor, $par): string {
            $i = $par[$mezo] ?? -1;

            return $i >= 0 && isset($sor[$i]) ? trim((string) $sor[$i]) : '';
        };

        $most = current_time('mysql');

        foreach (self::mezok($entitas) as $kulcs => [$felirat, $kotelezo]) {
            if ($kotelezo && $v($kulcs) === '') {
                return 'hiányzik: ' . $felirat;
            }
        }

        $kulso = mb_substr($v('kulso_azonosito'), 0, 40);

        if ($kulso !== '' && in_array($entitas, ['ugyfel', 'eszkoz', 'munkalap', 'tetel'], true)) {
            if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::tabla($entitas) . ' WHERE kulso_azonosito = %s', $kulso)) > 0) {
                return 'meglevo';
            }
        }

        $alap = ['forras' => self::FORRAS, 'kulso_azonosito' => $kulso];

        switch ($entitas) {
            case 'ugyfel':
                $ado = mb_substr($v('adoszam'), 0, 30);

                return $alap + [
                    'nev'                     => mb_substr($v('nev'), 0, 190),
                    'tipus'                   => $ado !== '' ? 'ceg' : 'maganszemely',
                    'kapcsolattarto'          => mb_substr($v('kapcsolattarto'), 0, 190),
                    'adoszam'                 => $ado,
                    'telefon'                 => mb_substr($v('telefon'), 0, 40),
                    'telefon2'                => mb_substr($v('telefon2'), 0, 40),
                    'email'                   => is_email($v('email')) ? $v('email') : '',
                    'szamlazasi_iranyitoszam' => mb_substr($v('szamlazasi_iranyitoszam'), 0, 10),
                    'szamlazasi_telepules'    => mb_substr($v('szamlazasi_telepules'), 0, 120),
                    'szamlazasi_cim'          => mb_substr($v('szamlazasi_cim'), 0, 190),
                    'ugyfel_szam'             => mb_substr($v('ugyfel_szam'), 0, 20),
                    'kategoria'               => mb_substr($v('kategoria'), 0, 60),
                    'megjegyzes'              => $v('megjegyzes'),
                    'aktiv'                   => 1,
                    'letrehozva'              => self::datum($v('letrehozva')) !== '' ? self::datum($v('letrehozva')) . ' 00:00:00' : $most,
                    'modositva'               => $most,
                ];

            case 'eszkoz':
                $u = self::hivatkozas('ugyfel', $v('ugyfel_ref'));

                if ($v('ugyfel_ref') !== '' && $u === 0) {
                    return 'az ügyfél (' . $v('ugyfel_ref') . ') nincs meg – előbb az ügyfeleket vedd át';
                }

                $kat = self::fejlec_norm($v('kategoria'));
                $kat = $kat === '' ? 'telefon' : (preg_match('/tablet|ipad/', $kat) === 1 ? 'tablet' : (preg_match('/laptop|notebook|szamitogep|pc|macbook/', $kat) === 1 ? 'laptop' : (preg_match('/ora|watch/', $kat) === 1 ? 'ora' : (preg_match('/telefon|mobil/', $kat) === 1 ? 'telefon' : mb_substr($kat, 0, 40)))));

                return $alap + [
                    'ugyfel_id'   => $u,
                    'kategoria'   => $kat,
                    'gyarto'      => mb_substr($v('gyarto'), 0, 80),
                    'tipus'       => mb_substr($v('tipus'), 0, 120),
                    'megnevezes'  => mb_substr($v('megnevezes'), 0, 120),
                    'imei'        => mb_substr((string) preg_replace('/\s+/', '', $v('imei')), 0, 40),
                    'imei2'       => mb_substr((string) preg_replace('/\s+/', '', $v('imei2')), 0, 40),
                    'sorozatszam' => mb_substr($v('sorozatszam'), 0, 60),
                    'szin'        => mb_substr($v('szin'), 0, 40),
                    'zarkod'      => $v('zarkod') !== '' ? SDH_Muhely_Zarkod::tarol($v('zarkod')) : '',
                    'minta'       => $v('minta') !== '' ? SDH_Muhely_Zarkod::tarol($v('minta')) : '',
                    'tartozekok'  => $v('tartozekok'),
                    'megjegyzes'  => $v('megjegyzes'),
                    'aktiv'       => 1,
                    'letrehozva'  => $most,
                    'modositva'   => $most,
                ];

            case 'munkalap':
                $u = self::hivatkozas('ugyfel', $v('ugyfel_ref'));
                $e = self::hivatkozas('eszkoz', $v('eszkoz_ref'));

                if ($v('ugyfel_ref') !== '' && $u === 0) {
                    return 'az ügyfél (' . $v('ugyfel_ref') . ') nincs meg';
                }

                if ($v('eszkoz_ref') !== '' && $e === 0) {
                    return 'az eszköz (' . $v('eszkoz_ref') . ') nincs meg';
                }

                if ($u === 0 && $e > 0) {
                    $u = (int) $wpdb->get_var($wpdb->prepare('SELECT ugyfel_id FROM ' . self::tabla('eszkoz') . ' WHERE id = %d', $e));
                }

                $szam = (int) preg_replace('/\D/', '', $v('munkalap_szam'));

                if ($szam > 0 && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::tabla('munkalap') . ' WHERE munkalap_szam = %d', $szam)) > 0) {
                    return 'a ' . $szam . ' munkalapszám már foglalt';
                }

                $allapot = self::allapot($v('allapot'), (array) ($opc['allapot_parok'] ?? []));
                $zart    = !empty(SDH_Muhely_Munkalap::allapotok()[$allapot]['zart']);
                $keszult = self::datum($v('keszult'));
                $lezarva = self::datum($v('lezarva'));
                $brutto  = self::szam($v('brutto_ertek'));
                $netto   = $v('netto_ertek') !== '' ? self::szam($v('netto_ertek')) : round($brutto / 1.27, 2);

                return $alap + [
                    'munkalap_szam'     => $szam > 0 ? $szam : null,
                    'fajta'             => 'javitas',
                    'allapot'           => $allapot,
                    'ugyfel_id'         => $u,
                    'eszkoz_id'         => $e,
                    'keszult'           => $keszult !== '' ? $keszult : null,
                    'hatarido'          => self::datum($v('hatarido')) !== '' ? self::datum($v('hatarido')) : null,
                    'lezarva'           => $zart ? ($lezarva !== '' ? $lezarva : ($keszult !== '' ? $keszult : null)) : null,
                    'ugyfel_megjegyzes' => $v('ugyfel_megjegyzes'),
                    'megjegyzes'        => $v('megjegyzes'),
                    'brutto_ertek'      => $brutto,
                    'netto_ertek'       => $netto,
                    'fizetett'          => self::szam($v('fizetett')),
                    'fizetesi_mod'      => mb_substr($v('fizetesi_mod'), 0, 40),
                    'fizetve'           => $zart && $brutto > 0 && self::szam($v('fizetett')) >= $brutto ? 1 : 0,
                    'letrehozva'        => ($keszult !== '' ? $keszult : current_time('Y-m-d')) . ' 00:00:00',
                    'modositva'         => $most,
                ];

            case 'tetel':
                $m = self::hivatkozas('munkalap', $v('munkalap_ref'));

                if ($m === 0) {
                    return 'a munkalap (' . $v('munkalap_ref') . ') nincs meg – előbb a munkalapokat vedd át';
                }

                $menny = $v('mennyiseg') !== '' ? self::szam($v('mennyiseg')) : 1.0;
                $menny = $menny == 0.0 ? 1.0 : $menny; // phpcs:ignore Universal.Operators.StrictComparisons
                $bar   = self::szam($v('brutto_ar'));
                $bert  = $v('brutto_ertek') !== '' ? self::szam($v('brutto_ertek')) : round($bar * $menny, 2);
                $bar   = $bar == 0.0 && $bert != 0.0 ? round($bert / $menny, 2) : $bar; // phpcs:ignore Universal.Operators.StrictComparisons
                $afa   = $v('afa_kulcs') !== '' ? (string) preg_replace('/[^0-9A-Za-z]/', '', $v('afa_kulcs')) : '27';
                $szazalek = is_numeric($afa) ? (float) $afa : 0.0;
                $tip   = self::fejlec_norm($v('tipus'));

                return $alap + [
                    'munkalap_id'  => $m,
                    'tipus'        => preg_match('/termek|alkatresz|cikk|aru/', $tip) === 1 ? 'termek' : 'szolgaltatas',
                    'mozgas'       => 'kimeno',
                    'megnevezes'   => mb_substr($v('megnevezes'), 0, 255),
                    'mennyiseg'    => $menny,
                    'me'           => $v('me') !== '' ? mb_substr($v('me'), 0, 20) : 'db',
                    'brutto_ar'    => $bar,
                    'brutto_ertek' => $bert,
                    'netto_ar'     => $v('netto_ar') !== '' ? self::szam($v('netto_ar')) : round($bar / (1 + $szazalek / 100), 2),
                    'netto_ertek'  => $v('netto_ertek') !== '' ? self::szam($v('netto_ertek')) : round($bert / (1 + $szazalek / 100), 2),
                    'afa'          => $szazalek,
                    'afa_kulcs'    => mb_substr($afa, 0, 12),
                    'kedvezmeny'   => self::szam($v('kedvezmeny')),
                    'cikkszam'     => mb_substr($v('cikkszam'), 0, 60),
                    'letrehozva'   => $most,
                    'modositva'    => $most,
                ];

            case 'hiba':
                $m = self::hivatkozas('munkalap', $v('munkalap_ref'));

                if ($m === 0) {
                    return 'a munkalap (' . $v('munkalap_ref') . ') nincs meg – előbb a munkalapokat vedd át';
                }

                $leiras = mb_substr($v('leiras'), 0, 255);

                if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::tabla('hiba') . ' WHERE munkalap_id = %d AND leiras = %s', $m, $leiras)) > 0) {
                    return 'meglevo';
                }

                $all = self::fejlec_norm($v('allapot'));

                return [
                    'munkalap_id' => $m,
                    'leiras'      => $leiras,
                    'javitas'     => $v('javitas'),
                    'allapot'     => preg_match('/javitva|kesz|megold/', $all) === 1 ? 'javitva' : ($all === '' ? 'uj' : mb_substr($all, 0, 30)),
                    'letrehozva'  => $most,
                    'modositva'   => $most,
                ];
        }

        return 'ismeretlen fajta';
    }

    /**
     * A fájl sorai [$tol, $tol + $db) – a fejléc nélkül.
     *
     * @return array<int, array<int, string>>
     */
    private static function sorok(array $fajl, int $tol, int $db): array
    {
        $f = new SplFileObject((string) $fajl['ut'], 'r');
        $f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $f->setCsvControl(';', '"', '\\');
        $f->seek($tol + 1);

        $ki = [];

        while (!$f->eof() && count($ki) < $db) {
            $s = $f->current();

            if (is_array($s) && $s !== [null]) {
                $ki[] = array_map('strval', $s);
            }

            $f->next();
        }

        return $ki;
    }

    /**
     * Próba: végigolvasás írás nélkül. (Hivatkozásokat a már bent lévő adatokra ellenőriz.)
     *
     * @param array<string, int> $par
     * @return array<string, mixed>
     */
    public static function proba(array $fajl, array $par, array $opc = []): array
    {
        $r = ['sorok' => 0, 'jo' => 0, 'meglevo' => 0, 'hibas' => 0, 'hibak' => [], 'osszeg' => 0.0, 'allapotok' => []];
        $penz = ['munkalap' => 'brutto_ertek', 'tetel' => 'brutto_ertek'][$fajl['entitas']] ?? '';

        for ($tol = 0; ; $tol += 2000) {
            $sorok = self::sorok($fajl, $tol, 2000);

            if ($sorok === []) {
                break;
            }

            foreach ($sorok as $i => $s) {
                $r['sorok']++;
                $a = self::sor_adat((string) $fajl['entitas'], $s, $par, $opc);

                if ($a === 'meglevo') {
                    $r['meglevo']++;
                } elseif (is_string($a)) {
                    $r['hibas']++;

                    if (count($r['hibak']) < 25) {
                        $r['hibak'][] = ($tol + $i + 2) . '. sor: ' . $a;
                    }
                } else {
                    $r['jo']++;

                    if ($penz !== '') {
                        $r['osszeg'] += (float) $a[$penz];
                    }

                    if ($fajl['entitas'] === 'munkalap') {
                        $r['allapotok'][(string) $a['allapot']] = ($r['allapotok'][(string) $a['allapot']] ?? 0) + 1;
                    }
                }
            }
        }

        return $r;
    }

    /**
     * Egy adag átvétele. A futás állapota a naplóban (import_id szerint).
     *
     * @param array<string, int> $par
     * @return array<string, mixed>
     */
    public static function adag(array $fajl, array $par, string $import_id, int $tol, array $opc = []): array
    {
        global $wpdb;

        $entitas = (string) $fajl['entitas'];
        $tabla   = self::tabla($entitas);
        $sorok   = self::sorok($fajl, $tol, self::ADAG);
        $r       = ['uj' => 0, 'meglevo' => 0, 'hibas' => 0, 'hibak' => [], 'tovabb' => $tol + count($sorok), 'kesz' => count($sorok) < self::ADAG];

        $muvelet = static function () use ($sorok, $entitas, $par, $opc, $tabla, $import_id, $tol, &$r, $wpdb): void {
            foreach ($sorok as $i => $s) {
                $a = self::sor_adat($entitas, $s, $par, $opc);

                if ($a === 'meglevo') {
                    $r['meglevo']++;
                    continue;
                }

                if (is_string($a)) {
                    $r['hibas']++;

                    if (count($r['hibak']) < 10) {
                        $r['hibak'][] = ($tol + $i + 2) . '. sor: ' . $a;
                    }

                    continue;
                }

                if ($wpdb->insert($tabla, $a) === false) {
                    $r['hibas']++;
                    continue;
                }

                $id = (int) $wpdb->insert_id;
                $wpdb->insert(self::sor_tabla(), ['import_id' => $import_id, 'tabla' => $entitas, 'sor_id' => $id]);
                $r['uj']++;

                if ($entitas === 'ugyfel') {
                    if ((string) $a['ugyfel_szam'] === '') {
                        $wpdb->update($tabla, ['ugyfel_szam' => sprintf('U-%06d', $id)], ['id' => $id]);
                    }

                    SDH_Muhely_Ugyfel::kereso_frissit($id);
                }
            }
        };

        class_exists('SDH_Muhely_Valtozasnaplo') ? SDH_Muhely_Valtozasnaplo::csendben($muvelet) : $muvelet();

        $naplo = get_option(self::OPT_NAPLO, []);
        $naplo = is_array($naplo) ? $naplo : [];
        $n     = $naplo[$import_id] ?? ['id' => $import_id, 'entitas' => $entitas, 'fajl' => (string) $fajl['nev'], 'ido' => current_time('mysql'), 'uj' => 0, 'meglevo' => 0, 'hibas' => 0, 'kesz' => false, 'visszavonva' => false];
        $n['uj']      += $r['uj'];
        $n['meglevo'] += $r['meglevo'];
        $n['hibas']   += $r['hibas'];
        $n['kesz']     = $r['kesz'];
        $n['tovabb']   = $r['tovabb'];
        $naplo[$import_id] = $n;
        update_option(self::OPT_NAPLO, $naplo, false);

        if ($r['uj'] > 0 && class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('atvetel', 0, 'Adatátvétel (' . self::ENTITASOK[$entitas] . ', ' . $fajl['nev'] . '): ' . $r['uj'] . ' új sor, ' . $r['meglevo'] . ' már bent volt, ' . $r['hibas'] . ' hibás.');
        }

        return $r;
    }

    /** Egy átvétel új sorainak törlése. A törölt sorok száma. */
    public static function visszavon(string $import_id): int
    {
        global $wpdb;

        $db  = 0;
        $sor = static fn (string $e): array => array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT sor_id FROM ' . self::sor_tabla() . ' WHERE import_id = %s AND tabla = %s', $import_id, $e)));

        $muvelet = static function () use ($import_id, $sor, &$db, $wpdb): void {
            // Visszafelé: előbb a gyerekek (hiba, tétel), aztán a munkalap, eszköz, ügyfél.
            foreach (['hiba', 'tetel', 'munkalap', 'eszkoz', 'ugyfel'] as $e) {
                foreach (array_chunk($sor($e), 500) as $idk) {
                    $db += (int) $wpdb->query('DELETE FROM ' . self::tabla($e) . ' WHERE id IN (' . implode(',', $idk) . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                }
            }

            $wpdb->delete(self::sor_tabla(), ['import_id' => $import_id]);
        };

        class_exists('SDH_Muhely_Valtozasnaplo') ? SDH_Muhely_Valtozasnaplo::csendben($muvelet) : $muvelet();

        $naplo = get_option(self::OPT_NAPLO, []);

        if (is_array($naplo) && isset($naplo[$import_id])) {
            $naplo[$import_id]['visszavonva'] = true;
            update_option(self::OPT_NAPLO, $naplo, false);
        }

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('atvetel', 0, 'Adatátvétel visszavonva (' . $import_id . '): ' . $db . ' sor törölve.');
        }

        return $db;
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_atvetel', '_wpnonce');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ezt csak adminisztrátor végezheti.'], 403);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
    }

    /** @return array<string, int> */
    private static function par_be(): array
    {
        $p = isset($_POST['par']) ? json_decode((string) wp_unslash($_POST['par']), true) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        return is_array($p) ? array_map('intval', $p) : [];
    }

    /** @return array<string, mixed> */
    private static function opc_be(): array
    {
        return ['allapot_parok' => self::allapot_parok(isset($_POST['allapot_parok']) ? sanitize_textarea_field(wp_unslash($_POST['allapot_parok'])) : '')]; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    private static function fajl_post(): array
    {
        $f = self::fajl(isset($_POST['fajl']) ? sanitize_key(wp_unslash($_POST['fajl'])) : ''); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ($f === null) {
            wp_send_json_error(['uzenet' => 'A feltöltött fájl nincs meg – töltsd fel újra.']);
        }

        return $f;
    }

    public static function ajax_feltolt(): void
    {
        self::jog();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if (empty($_FILES['fajl']['tmp_name'])) {
            wp_send_json_error(['uzenet' => 'Válassz fájlt.']);
        }

        $r = self::fajl_be((string) $_FILES['fajl']['tmp_name'], (string) ($_FILES['fajl']['name'] ?? 'adat.csv'), isset($_POST['entitas']) ? sanitize_key(wp_unslash($_POST['entitas'])) : '');
        // phpcs:enable

        if (is_string($r)) {
            wp_send_json_error(['uzenet' => $r]);
        }

        $mezok = [];

        foreach (self::mezok((string) $r['entitas']) as $k => [$felirat, $kotelezo]) {
            $mezok[] = ['kulcs' => $k, 'felirat' => $felirat, 'kotelezo' => $kotelezo];
        }

        unset($r['ut']);
        wp_send_json_success($r + ['mezok' => $mezok, 'allapotok' => array_keys(SDH_Muhely_Munkalap::allapotok())]);
    }

    public static function ajax_proba(): void
    {
        self::jog();
        $f = self::fajl_post();
        wp_send_json_success(self::proba($f, self::par_be(), self::opc_be()));
    }

    public static function ajax_futtat(): void
    {
        self::jog();
        $f   = self::fajl_post();
        $imp = isset($_POST['import']) ? sanitize_key(wp_unslash($_POST['import'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $imp = $imp !== '' ? $imp : 'a' . gmdate('ymdHis') . strtolower(wp_generate_password(4, false, false));
        $tol = isset($_POST['tol']) ? max(0, (int) $_POST['tol']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        wp_send_json_success(['import' => $imp, 'osszes' => (int) $f['sorok']] + self::adag($f, self::par_be(), $imp, $tol, self::opc_be()));
    }

    public static function ajax_visszavon(): void
    {
        self::jog();
        $db = self::visszavon(isset($_POST['import']) ? sanitize_key(wp_unslash($_POST['import'])) : ''); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        wp_send_json_success(['uzenet' => 'Visszavonva: ' . $db . ' sor törölve.']);
    }

    /* =================================================================
     * Beállítások › Adatátvétel
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $naplo = get_option(self::OPT_NAPLO, []);
        $naplo = is_array($naplo) ? array_reverse($naplo, true) : [];
        ?>
        <div class="sdh-doboz sdh-adatatvetel" data-sdh-atvetel data-nonce="<?php echo esc_attr(wp_create_nonce('sdh_muhely_atvetel')); ?>">
            <h2 class="sdh-doboz__cim">Adatátvétel (MunkaLap 3 vagy táblázat)</h2>
            <p class="sdh-sugo">
                A régi program táblázatait (CSV, TXT vagy XLSX) ebben a sorrendben vedd át: <strong>1. Ügyfelek, 2. Eszközök
                (tárgyak), 3. Munkalapok, 4. Tételek, 5. Hibák</strong>. Előbb mindig a <strong>Próba</strong>: megmutatja, hány sor
                jó, mi a hiba, mennyi a végösszeg – semmit nem ír. A kétszer átvett sor nem duplázódik. Egy átvétel visszavonható.
                Élesítés előtt készíts mentést (Beállítások › Mentés).
            </p>
            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="atvetel_entitas">Mit tartalmaz a fájl?</label>
                    <select id="atvetel_entitas" data-sdh-atvetel-entitas>
                        <?php foreach (self::ENTITASOK as $k => $n) : ?>
                            <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($n); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="atvetel_fajl">Fájl (CSV, TXT, XLSX)</label>
                    <input type="file" id="atvetel_fajl" accept=".csv,.txt,.xlsx,.tsv" data-sdh-atvetel-fajl>
                </div>
            </div>
            <p><button type="button" class="sdh-gomb" data-sdh-atvetel-feltolt>Feltöltés és elemzés</button>
                <span class="sdh-mezo__sugo" data-sdh-atvetel-uzenet role="status"></span></p>

            <div data-sdh-atvetel-parositas hidden>
                <h3>Oszlopok párosítása</h3>
                <p class="sdh-sugo" data-sdh-atvetel-info></p>
                <table class="sdh-tabla"><thead><tr><th>Mező</th><th>Oszlop a fájlban</th><th>Minta</th></tr></thead><tbody data-sdh-atvetel-sorok></tbody></table>
                <div class="sdh-mezo sdh-mezo--szeles" data-sdh-atvetel-allapot hidden>
                    <label for="atvetel_allapot">Állapotok párosítása (soronként: régi állapot = új állapot kulcsa; üresen magától)</label>
                    <textarea id="atvetel_allapot" rows="3" data-sdh-atvetel-allapot-parok placeholder="Kiadva = lezart"></textarea>
                    <span class="sdh-mezo__sugo" data-sdh-atvetel-allapot-kulcsok></span>
                </div>
                <p>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-atvetel-proba>Próba (nem ír)</button>
                    <button type="button" class="sdh-gomb" data-sdh-atvetel-futtat disabled>Átvétel indítása</button>
                </p>
                <pre class="sdh-meres__ki" data-sdh-atvetel-jelentes hidden></pre>
            </div>

            <?php if ($naplo !== []) : ?>
                <h3>Eddigi átvételek</h3>
                <table class="sdh-tabla">
                    <thead><tr><th>Idő</th><th>Mit</th><th>Fájl</th><th>Új</th><th>Már bent volt</th><th>Hibás</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($naplo as $id => $n) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $n['ido']); ?></td>
                            <td><?php echo esc_html(self::ENTITASOK[$n['entitas']] ?? (string) $n['entitas']); ?></td>
                            <td><?php echo esc_html((string) $n['fajl']); ?></td>
                            <td><?php echo (int) $n['uj']; ?><?php echo empty($n['kesz']) ? ' (félbemaradt)' : ''; ?></td>
                            <td><?php echo (int) $n['meglevo']; ?></td>
                            <td><?php echo (int) $n['hibas']; ?></td>
                            <td><?php if (!empty($n['visszavonva'])) : ?>visszavonva<?php elseif ((int) $n['uj'] === 0) : ?>–<?php else : ?>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-atvetel-visszavon="<?php echo esc_attr((string) $id); ?>">Visszavonás</button><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
