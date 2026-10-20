<?php
/**
 * Munkalapszoftver Clone – a MunkaLap 3 tábláinak leképezése a rendszer tábláira (0.99.6).
 *
 * Minden régi sor először a nyers tárba kerül (SDH_Muhely_Ml3::nyers_tabla()) – így semmi nem vész el –, és ha van
 * hozzá leképezés, abból új rekord lesz (vagy egy korábbi átvétel rekordja frissül). Itt csak a leképezés van: mi lesz
 * a régi sorból. A sorrendet, a nyers tárat, a hivatkozások feloldását és a visszavonhatóságot a mag (SDH_Muhely_Ml3)
 * adja.
 *
 * A MunkaLap 3 „egyedi mezői” (DATA_n oszlopok) telepítésenként mást jelentenek: a jelentésüket a DATA_COLUMN tábla
 * adja, a párosításukat a mag javasolja (és a Beállításokban átírható). Amit nem párosítottunk, az a belső
 * megjegyzésbe kerül „MunkaLap 3 – <mezőnév>: <érték>” sorként.
 *
 * Kódolt értékek (a MunkaLap 3 saját felsorolásai):
 *  - munkalap állapota: 0 Függő, 1 Nyitott, 2 Elkészült, 3 Lezárt, 4 Érvénytelen, 5 Bejelentett, 6 Árajánlat;
 *  - hiba típusa: 0 Lista (hibakatalógus), 1 Javítandó, 2 Kijavított, 3 Nem javítható, 4 Nem javítandó;
 *  - tétel iránya: 0 Nyitó, 1 Bejövő, 2 Kimenő, 3 Kézi, 4 Záró; típusa: 0 Termék, 1 Szolgáltatás;
 *  - cím típusa: 0 Központi, 1 Számlázási, 2 Levelezési, 3 Szállítási, 4 Telephely;
 *  - ügyfél adóügyi állapota: 0 Belföldi adóalany, 1 Egyéb, 2 Magánszemély;
 *  - fizetési mód: 0 Készpénz, 1 Átutalás, 2 Utánvét, 3 Bankkártya, 4 PayPal, 5 Utalvány, 6 SZÉP-kártya,
 *    7 Halasztott KP, 8 Csekk, 9 Előre utalás, 10 Barion, 11 Kompenzáció szerint, 12 Kombinált.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Ml3_Lekepzes
{
    public const ALLAPOTOK = [0 => 'fuggo', 1 => 'nyitott', 2 => 'elkeszult', 3 => 'lezart', 4 => 'ervenytelen', 5 => 'bejelentett', 6 => 'arajanlat'];

    public const ALLAPOT_NEVEK = [0 => 'Függő', 1 => 'Nyitott', 2 => 'Elkészült', 3 => 'Lezárt', 4 => 'Érvénytelen', 5 => 'Bejelentett', 6 => 'Árajánlat'];

    public const HIBA_ALLAPOTOK = [1 => 'uj', 2 => 'kesz', 3 => 'nem_javithato', 4 => 'nem_javitando'];

    public const FIZMODOK = [
        0 => 'Készpénz', 1 => 'Átutalás', 2 => 'Utánvét', 3 => 'Bankkártya', 4 => 'PayPal', 5 => 'Utalvány', 6 => 'SZÉP Kártya',
        7 => 'Halasztott KP', 8 => 'Csekk', 9 => 'Előre utalás', 10 => 'Barion', 11 => 'Kompenzáció szerint', 12 => 'Kombinált',
    ];

    public const IRANYOK = [0 => 'Nyitó', 1 => 'Bejövő', 2 => 'Kimenő', 3 => 'Kézi', 4 => 'Záró'];

    public const ORSZAGOK = [
        'HU' => 'Magyarország', 'AT' => 'Ausztria', 'SK' => 'Szlovákia', 'RO' => 'Románia', 'HR' => 'Horvátország',
        'RS' => 'Szerbia', 'SI' => 'Szlovénia', 'UA' => 'Ukrajna', 'DE' => 'Németország', 'CZ' => 'Csehország',
        'PL' => 'Lengyelország', 'NL' => 'Hollandia', 'IT' => 'Olaszország', 'FR' => 'Franciaország', 'GB' => 'Egyesült Királyság',
        'US' => 'Egyesült Államok', 'CH' => 'Svájc', 'BE' => 'Belgium', 'ES' => 'Spanyolország',
    ];

    /** Az egyedi mezők lehetséges céljai táblánként (kulcs => felirat). */
    public static function mezo_celok(string $tabla): array
    {
        $kozos = ['belso' => 'Belső megjegyzésbe (névvel)', 'kihagy' => 'Csak a régi adatok közt'];

        switch ($tabla) {
            case 'CUSTOMER':
                return [
                    'telefon' => 'Telefon', 'email' => 'E-mail', 'kapcsolattarto' => 'Kapcsolattartó', 'adoszam' => 'Adószám',
                ] + $kozos;

            case 'THING':
                return [
                    'gyarto' => 'Gyártó', 'tipus' => 'Típus', 'imei' => 'IMEI', 'sorozatszam' => 'Gyári szám', 'modell_szam' => 'Modellszám',
                    'szin' => 'Szín', 'garancias' => 'Garanciás (igen/nem)', 'garancia_allapot' => 'Garancia állapota',
                    'garancia_lejar' => 'Garancia lejárta', 'vasarlas_datuma' => 'Vásárlás dátuma', 'zarkod' => 'Feloldó kód',
                    'tartozekok' => 'Tartozékok', 'megjegyzes' => 'Megjegyzés',
                ] + $kozos;

            case 'WORKSHEET':
                return ['tartozek' => 'Átvett tartozék', 'ugyfel_megjegyzes' => 'Megjegyzés (ügyfélnek)'] + $kozos;
        }

        return $kozos;
    }

    /**
     * Javaslat egy egyedi mező céljára a neve alapján.
     */
    public static function mezo_javaslat(string $tabla, string $nev): string
    {
        $n = self::norm($nev);

        if ($tabla === 'CUSTOMER') {
            if (preg_match('/telefon|mobil|^tel\b/', $n) === 1) {
                return 'telefon';
            }

            if (preg_match('/e ?mail/', $n) === 1 && strpos($n, 'lista') === false) {
                return 'email';
            }

            if (strpos($n, 'kapcsolattarto') !== false) {
                return 'kapcsolattarto';
            }

            return 'belso';
        }

        if ($tabla === 'THING') {
            $szabalyok = [
                '/imei/'                               => 'imei',
                '/garancia (lejar|vege)/'              => 'garancia_lejar',
                '/garancia allapot/'                   => 'garancia_allapot',
                '/^garancia$|^garancialis$|^garancias$/' => 'garancias',
                '/vasarlas/'                           => 'vasarlas_datuma',
                '/gyarto|marka/'                       => 'gyarto',
                '/^tipus|modell$|^modell/'             => 'tipus',
                '/sorozatszam|gyari ?szam|serial|^sn$/' => 'sorozatszam',
                '/szin/'                               => 'szin',
                '/feloldo|jelszo|pin ?kod|zar ?kod/'   => 'zarkod',
                '/tartozek/'                           => 'tartozekok',
            ];

            foreach ($szabalyok as $minta => $cel) {
                if (preg_match($minta, $n) === 1) {
                    return $cel;
                }
            }

            return 'belso';
        }

        if ($tabla === 'WORKSHEET') {
            return 'tartozek';
        }

        return 'belso';
    }

    /** Kisbetűs, ékezet nélküli, szóközös alak (összehasonlításhoz). */
    public static function norm(string $s): string
    {
        $s = function_exists('remove_accents') ? remove_accents($s) : $s;
        $s = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $s));

        return trim($s);
    }

    /* =================================================================
     * Értékek
     * ============================================================== */

    /** '2025-05-13 15:44:00.0' → '2025-05-13 15:44:00'; üres → null. */
    public static function ido($v): ?string
    {
        $v = trim((string) $v);

        if ($v === '' || preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $v, $m) !== 1) {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v . ' 00:00:00' : null;
        }

        return $m[1] . ' ' . $m[2];
    }

    public static function nap($v): ?string
    {
        $i = self::ido($v);

        return $i === null ? null : substr($i, 0, 10);
    }

    /** Szabad szöveges dátum (pl. '2020.04.02.') → 'Y-m-d' vagy null. */
    public static function szoveg_nap($v): ?string
    {
        $v = trim((string) $v);

        if ($v === '') {
            return null;
        }

        $i = self::nap($v);

        if ($i !== null) {
            return $i;
        }

        $d = class_exists('SDH_Muhely_Adatatvetel') ? SDH_Muhely_Adatatvetel::datum($v) : '';

        return $d !== '' ? $d : null;
    }

    public static function szam($v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    public static function s($v, int $max = 0): string
    {
        $s = trim(str_replace("\0", '', (string) ($v ?? '')));

        return $max > 0 ? mb_substr($s, 0, $max) : $s;
    }

    private static function igen($v): bool
    {
        return preg_match('/^(igen|i|van|yes|y|true|1|x)$/', self::norm((string) $v)) === 1;
    }

    /** ÁFA-kulcs szövegként ('0.2700' → '27'). */
    private static function afa_kulcs($v, string $alap = '27'): string
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return $alap;
        }

        $sz = round((float) $v * 100, 2);

        return (string) (floor($sz) == $sz ? (int) $sz : $sz); // phpcs:ignore Universal.Operators.StrictComparisons
    }

    /** Szöveg + „Név: érték” sorok a végére (üres sorok nélkül). */
    private static function hozzafuz(string $szoveg, array $sorok): string
    {
        $sorok = array_values(array_filter(array_map('trim', $sorok), static fn (string $s): bool => $s !== ''));

        if ($sorok === []) {
            return $szoveg;
        }

        return trim(trim($szoveg) . "\n\n" . implode("\n", $sorok));
    }

    /* =================================================================
     * Hivatkozások
     * ============================================================== */

    private static function felhasznalo($regi): int
    {
        if ($regi === null || $regi === '') {
            return 0;
        }

        $p = (array) (SDH_Muhely_Ml3::beallitas()['felhasznalok'] ?? []);

        return (int) ($p[(string) $regi] ?? 0);
    }

    private static function felhasznalo_nev($regi): string
    {
        if ($regi === null || $regi === '') {
            return '';
        }

        $u = SDH_Muhely_Ml3::nyers('USERS.USER_DATA', (string) $regi);

        return $u !== null ? self::s($u['NAME'] ?? '') : '';
    }

    /** Egy régi ügyfél neve (a központi címéből) – a tétel eladójához, a beszállítóhoz. */
    public static function ugyfel_nev($regi): string
    {
        static $tar = [];

        $regi = (string) $regi;

        if ($regi === '') {
            return '';
        }

        if (!array_key_exists($regi, $tar)) {
            $nev = '';

            foreach (SDH_Muhely_Ml3::nyers_szulo('APP.ADDRESS', $regi) as $c) {
                if ((int) ($c['TYPE'] ?? 0) === 0 || $nev === '') {
                    $nev = self::s($c['NAME'] ?? '');
                }
            }

            if (count($tar) > 20000) {
                $tar = [];
            }

            $tar[$regi] = $nev;
        }

        return $tar[$regi];
    }

    /** Kategória (ügyfél / termék) neve az útjával: '/Alkatrész/Samsung telefon' + 'SM-A326B'. */
    private static function kategoria(string $tabla, $id, int $max): string
    {
        if ($id === null || $id === '') {
            return '';
        }

        $k = SDH_Muhely_Ml3::nyers($tabla, (string) $id);

        if ($k === null) {
            return '';
        }

        $ut = trim(self::s($k['PATH'] ?? ''), '/');

        return mb_substr(trim(($ut !== '' ? str_replace('/', ' / ', $ut) . ' / ' : '') . self::s($k['NAME'] ?? '')), 0, $max);
    }

    /** Az egyedi mezők (DATA_n) szétosztása: cél => [érték, …]; a belsőbe szánt sorok külön. */
    private static function egyedi(string $tabla, array $sor): array
    {
        $mezok = (array) (SDH_Muhely_Ml3::beallitas()['mezok'][$tabla] ?? []);
        $nevek = SDH_Muhely_Ml3::egyedi_mezo_nevek($tabla);
        $ki    = ['_belso' => []];

        foreach ($sor as $oszlop => $ertek) {
            if (strpos((string) $oszlop, 'DATA_') !== 0 || substr((string) $oszlop, -9) === '_REMINDER') {
                continue;
            }

            $ertek = is_scalar($ertek) ? trim((string) $ertek) : '';

            if ($ertek === '') {
                continue;
            }

            $id  = substr((string) $oszlop, 5);
            $nev = $nevek[$id]['nev'] ?? $oszlop;

            if (($nevek[$id]['tipus'] ?? -1) === 0) {
                // Logikai mező: 1 = igen.
                $ertek = $ertek === '1' ? 'igen' : ($ertek === '0' ? 'nem' : $ertek);
            }

            if (($nevek[$id]['tipus'] ?? -1) === 5) {
                $ertek = (string) (self::nap($ertek) ?? $ertek);
            }

            $cel = (string) ($mezok[$id] ?? self::mezo_javaslat($tabla, (string) $nev));

            if ($cel === 'kihagy') {
                continue;
            }

            if ($cel === 'belso') {
                $ki['_belso'][] = 'MunkaLap 3 – ' . $nev . ': ' . $ertek;
                continue;
            }

            $ki[$cel][] = ['nev' => (string) $nev, 'ertek' => $ertek];
        }

        return $ki;
    }

    private static function elso(array $e, string $cel): string
    {
        return isset($e[$cel][0]['ertek']) ? (string) $e[$cel][0]['ertek'] : '';
    }

    /* =================================================================
     * Ügyfél
     * ============================================================== */

    private static function cim_szoveg(array $c): string
    {
        $utca = self::s($c['STREET_ADDRESS'] ?? '');
        $utca = $utca !== '' ? $utca : self::s($c['OLD_STREET_ADDRESS'] ?? '');
        $resz = [$utca];

        if (self::s($c['SPACE_TYPE'] ?? '') !== '') {
            $resz[] = self::s($c['SPACE_TYPE']);
        }

        if (self::s($c['HOUSE'] ?? '') !== '') {
            $resz[] = self::s($c['HOUSE']);
        }

        foreach (['BUILDING' => ' ép.', 'STAIRCASE' => ' lph.', 'FLOOR' => ' em.', 'DOOR' => ' ajtó'] as $m => $utotag) {
            if (self::s($c[$m] ?? '') !== '') {
                $resz[] = self::s($c[$m]) . $utotag;
            }
        }

        return mb_substr(trim(implode(' ', array_filter($resz, static fn (string $s): bool => $s !== ''))), 0, 190);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} irányítószám, település, cím, ország */
    private static function cim(array $c): array
    {
        $varos = SDH_Muhely_Ml3::nyers('APP.CITY', (string) ($c['CITY_ID'] ?? ''));
        $orsz  = strtoupper(self::s($c['COUNTRY'] ?? ''));

        return [
            mb_substr($varos !== null ? self::s($varos['POSTCODE'] ?? '') : '', 0, 10),
            mb_substr($varos !== null ? self::s($varos['NAME'] ?? '') : '', 0, 120),
            self::cim_szoveg($c),
            $orsz === '' ? 'Magyarország' : (self::ORSZAGOK[$orsz] ?? $orsz),
        ];
    }

    /** @return array<string, mixed>|string */
    public static function ugyfel(array $sor)
    {
        $cimek = [];

        foreach (SDH_Muhely_Ml3::nyers_szulo('APP.ADDRESS', (string) $sor['CUSTOMER_ID']) as $c) {
            $cimek[(int) ($c['TYPE'] ?? 0)] = $cimek[(int) ($c['TYPE'] ?? 0)] ?? $c;
        }

        $kozponti = $cimek[0] ?? ($cimek !== [] ? reset($cimek) : null);
        $nev      = $kozponti !== null ? self::s($kozponti['NAME'] ?? '', 190) : '';
        $e        = self::egyedi('CUSTOMER', $sor);
        $belso    = $e['_belso'];

        // Telefonszámok: az első kettő a két mezőbe, a többi a belső megjegyzésbe.
        $telefonok = [];

        foreach ((array) ($e['telefon'] ?? []) as $t) {
            foreach (preg_split('/\s*[,;\/]\s*|\s{2,}/', (string) $t['ertek']) ?: [] as $egy) {
                if (trim($egy) !== '') {
                    $telefonok[] = mb_substr(trim($egy), 0, 40);
                }
            }
        }

        foreach (array_slice($telefonok, 2) as $t) {
            $belso[] = 'MunkaLap 3 – további telefon: ' . $t;
        }

        $email = '';

        foreach ((array) ($e['email'] ?? []) as $m) {
            foreach (preg_split('/[\s,;]+/', (string) $m['ertek']) ?: [] as $egy) {
                if ($email === '' && is_email($egy)) {
                    $email = $egy;
                } elseif (trim($egy) !== '') {
                    $belso[] = 'MunkaLap 3 – ' . $m['nev'] . ': ' . $egy;
                }
            }
        }

        $ado  = self::s($sor['VAT_NUMBER'] ?? '', 30);
        $ado  = $ado !== '' ? $ado : self::s(self::elso($e, 'adoszam'), 30);
        $vat  = (int) ($sor['VAT_STATE'] ?? 2);
        $tip  = $vat === 0 || ($vat === 1 && $ado !== '') ? 'ceg' : 'maganszemely';

        if (self::s($sor['EU_VAT_NUMBER'] ?? '') !== '') {
            $belso[] = 'MunkaLap 3 – közösségi adószám: ' . self::s($sor['EU_VAT_NUMBER']);
        }

        if ($vat === 1) {
            $belso[] = 'MunkaLap 3 – adóügyi állapot: Egyéb (külföldi / egyéb adóalany)';
        }

        $szaml = $cimek[1] ?? $kozponti;
        $adat  = [
            'tipus'          => $tip,
            'nev'            => $nev !== '' ? $nev : 'Névtelen ügyfél (MunkaLap ' . (int) $sor['CUSTOMER_ID'] . ')',
            'kapcsolattarto' => self::s(self::elso($e, 'kapcsolattarto'), 190),
            'adoszam'        => $ado,
            'telefon'        => $telefonok[0] ?? '',
            'telefon2'       => $telefonok[1] ?? '',
            'email'          => mb_substr($email, 0, 190),
            'kategoria'      => self::kategoria('APP.CUSTOMER_CATEGORY', $sor['CUSTOMER_CATEGORY_ID'] ?? null, 60),
            'kedvezmeny'     => round(min(100, max(0, self::szam($sor['DISCOUNT_RATE'] ?? 0) * 100)), 2),
            'megjegyzes'     => self::s($sor['NOTES'] ?? ''),
            'aktiv'          => 1,
            'letrehozva'     => self::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? current_time('mysql'),
            'letrehozo'      => self::felhasznalo($sor['SYSTEM_CREATOR'] ?? null),
        ];

        if ($szaml !== null) {
            [$adat['szamlazasi_iranyitoszam'], $adat['szamlazasi_telepules'], $adat['szamlazasi_cim'], $adat['szamlazasi_orszag']] = self::cim($szaml);
        }

        $tovabbi = [2 => 'levelezesi', 3 => 'szallitasi', 4 => 'telephely'];

        if (!isset($cimek[2]) && isset($cimek[1], $cimek[0])) {
            $cimek[2] = $cimek[0]; // a központi cím, ha külön számlázási cím is van
        }

        foreach ($tovabbi as $t => $elotag) {
            if (!isset($cimek[$t])) {
                continue;
            }

            [$irsz, $tel, $cim] = self::cim($cimek[$t]);

            if ($szaml !== null && [$irsz, $tel, $cim] === array_slice(self::cim($szaml), 0, 3)) {
                continue;
            }

            $adat[$elotag . '_azonos']       = 0;
            $adat[$elotag . '_iranyitoszam'] = $irsz;
            $adat[$elotag . '_telepules']    = $tel;
            $adat[$elotag . '_cim']          = $cim;

            if ($t === 4) {
                $adat['telephely_nev'] = self::s($cimek[$t]['NAME'] ?? '', 190);
            }
        }

        $adat['belso_megjegyzes'] = self::hozzafuz('', $belso);

        return $adat;
    }

    /* =================================================================
     * Eszköz
     * ============================================================== */

    /** A MunkaLap tárgyneve → eszközkategória kulcsa. */
    public static function eszkoz_kategoria(string $nev): string
    {
        $n = self::norm($nev);

        $szabalyok = [
            '/okosora|smartwatch|sportora|^ora$|watch/'           => 'ora',
            '/tablet|tablagep|ipad/'                             => 'tablet',
            '/telefon|mobil|iphone|okostelefon/'                 => 'telefon',
            '/laptop|notebook|macbook|netbook/'                  => 'laptop',
            '/all in one|aio/'                                   => 'aio',
            '/asztali|szamitogep|^pc$|konfiguracio/'             => 'asztali',
            '/monitor/'                                          => 'monitor',
            '/nyomtato|szkenner|scanner/'                        => 'nyomtato',
            '/router|modem|halozat|switch|wifi/'                 => 'halozat',
            '/merevlemez|ssd|hdd|pendrive|tarolo/'               => 'tarolo',
            '/eger|billentyu|webkamera|periferia/'               => 'periferia',
            '/tv box|mediaplayer|medialejatszo|tvbox/'           => 'tvbox',
            '/^tv$|televizio/'                                   => 'tv',
            '/kezi konzol|switch lite|steam deck/'               => 'kezi_konzol',
            '/konzol|playstation|xbox/'                          => 'konzol',
            '/kontroller|joystick|gamepad/'                      => 'kontroller',
            '/projektor/'                                        => 'projektor',
            '/soundbar|hazimozi/'                                => 'soundbar',
            '/hangszoro|hangfal/'                                => 'hangszoro',
            '/fejhallgato|fulhallgato|headset|airpods/'          => 'fejhallgato',
            '/autoradio|autos|menetrogzito|navigacio|gps/'      => 'auto_elektronika',
            '/radio|lemezjatszo/'                                => 'radio',
            '/ebook|e book|kindle|olvaso/'                       => 'ebook',
            '/fenykepezo/'                                       => 'fenykepezo',
            '/kamera/'                                           => 'kamera',
            '/dron/'                                             => 'drone',
            '/robot porszivo|robotporszivo|porszivo/'            => 'porszivo',
            '/mosogep|hutő|huto|mosogatogep|szaritogep/'        => 'nagygep',
            '/kavefozo|mikrohullam|turmix|kenyerpirito|kisgep|sutő|suto|fozolap/' => 'kisgep',
            '/klima|ventilator|futes|radiator/'                  => 'klima',
            '/vasalo|gozallomas/'                                => 'vasalo',
            '/hajszarito/'                                       => 'hajszarito',
            '/hajvasalo|hajformazo/'                             => 'hajformazo',
            '/borotva|szakallvago|trimmer/'                      => 'borotva',
            '/fogkefe/'                                          => 'fogkefe',
            '/roller|kerekpar|e bike|ebike|kisauto|hoverboard/'  => 'roller',
            '/powerbank|tolto/'                                  => 'powerbank',
            '/tavirányito|taviranyito/'                          => 'periferia',
            '/szerszam|furo|csavarozo/'                          => 'szerszam',
            '/lampa|vilagitas/'                                  => 'vilagitas',
            '/hangszer|szintetizator/'                           => 'hangszer',
            '/jatek|rc /'                                        => 'jatek',
            '/pénztargep|penztargep|vonalkod/'                   => 'pos',
        ];

        foreach ($szabalyok as $minta => $kulcs) {
            if (preg_match($minta, $n) === 1) {
                return $kulcs;
            }
        }

        return $n === '' ? 'telefon' : 'egyeb';
    }

    /** Vásárlás-e (a régi programban a javítás nélküli eladás „tárgya”). */
    public static function vasarlas_e(?array $thing): bool
    {
        return $thing !== null && self::norm((string) ($thing['NAME'] ?? '')) === 'vasarlas';
    }

    /** @return array<string, mixed>|string */
    public static function eszkoz(array $sor)
    {
        $e     = self::egyedi('THING', $sor);
        $belso = $e['_belso'];

        // IMEI: egy mezőben több is lehet.
        $imeik = [];

        foreach ((array) ($e['imei'] ?? []) as $i) {
            foreach (preg_split('/[^0-9A-Za-z]+/', (string) $i['ertek']) ?: [] as $egy) {
                if (strlen($egy) >= 6) {
                    $imeik[] = $egy;
                }
            }
        }

        foreach (array_slice($imeik, 2) as $i) {
            $belso[] = 'MunkaLap 3 – további IMEI: ' . $i;
        }

        $tobb = static function (array $e, string $cel, array &$belso, int $max): string {
            $ert = (array) ($e[$cel] ?? []);

            foreach (array_slice($ert, 1) as $x) {
                $belso[] = 'MunkaLap 3 – ' . $x['nev'] . ': ' . $x['ertek'];
            }

            return isset($ert[0]['ertek']) ? mb_substr((string) $ert[0]['ertek'], 0, $max) : '';
        };

        $tart = [];

        foreach ((array) ($e['tartozekok'] ?? []) as $t) {
            $tart[] = $t['ertek'];
        }

        $nev  = self::s($sor['NAME'] ?? '', 120);
        $zar  = $tobb($e, 'zarkod', $belso, 255);
        $glej = self::szoveg_nap($tobb($e, 'garancia_lejar', $belso, 40));
        $vasd = self::szoveg_nap($tobb($e, 'vasarlas_datuma', $belso, 40));
        $gar  = $tobb($e, 'garancias', $belso, 40);

        return [
            'kategoria'        => self::eszkoz_kategoria($nev),
            'megnevezes'       => $nev,
            'gyarto'           => $tobb($e, 'gyarto', $belso, 80),
            'tipus'            => $tobb($e, 'tipus', $belso, 120),
            'imei'             => mb_substr($imeik[0] ?? '', 0, 40),
            'imei2'            => mb_substr($imeik[1] ?? '', 0, 40),
            'sorozatszam'      => $tobb($e, 'sorozatszam', $belso, 60),
            'modell_szam'      => $tobb($e, 'modell_szam', $belso, 60),
            'szin'             => $tobb($e, 'szin', $belso, 40),
            'garancia_allapot' => $tobb($e, 'garancia_allapot', $belso, 60),
            'garancias'        => $gar !== '' && self::igen($gar) ? 1 : 0,
            'garancia_lejar'   => $glej,
            'vasarlas_datuma'  => $vasd,
            'zarkod'           => $zar !== '' && class_exists('SDH_Muhely_Zarkod') ? SDH_Muhely_Zarkod::tarol($zar) : '',
            'tartozekok'       => implode(', ', $tart),
            'megjegyzes'       => self::hozzafuz(self::s($sor['NOTES'] ?? ''), array_map(static fn (array $x): string => (string) $x['ertek'], (array) ($e['megjegyzes'] ?? []))),
            'belso_megjegyzes' => self::hozzafuz('', $belso),
            'aktiv'            => 1,
            'letrehozva'       => self::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? current_time('mysql'),
            'letrehozo'        => self::felhasznalo($sor['SYSTEM_CREATOR'] ?? null),
        ];
    }

    /* =================================================================
     * Termék és szolgáltatás (MASTER_ITEM)
     * ============================================================== */

    public static function brutto($netto, $afa): float
    {
        return round(self::szam($netto) * (1 + self::szam($afa)), 2);
    }

    /** @return array<string, mixed> */
    public static function termek(array $sor): array
    {
        $belso = [];

        if (self::szam($sor['COMMISSION'] ?? 0) != 0.0) { // phpcs:ignore Universal.Operators.StrictComparisons
            $belso[] = 'MunkaLap 3 – jutalék: ' . self::szam($sor['COMMISSION']);
        }

        if (self::szam($sor['WORKING_TIME'] ?? 0) != 0.0) { // phpcs:ignore Universal.Operators.StrictComparisons
            $belso[] = 'MunkaLap 3 – munkaidő: ' . self::szam($sor['WORKING_TIME']);
        }

        return [
            'megnevezes'     => self::s($sor['NAME'] ?? '', 255),
            'leiras'         => self::s($sor['DESCRIPTION'] ?? ''),
            'kategoria'      => self::kategoria('APP.ITEM_CATEGORY', $sor['CATEGORY_ID'] ?? null, 120),
            'beszallito'     => mb_substr(self::ugyfel_nev($sor['SELLER_ID'] ?? ''), 0, 190),
            'gyari_szam'     => self::s($sor['FACTORY_NUMBER'] ?? '', 60),
            'cikkszam'       => self::s($sor['ITEM_NUMBER'] ?? '', 60),
            'termekkod'      => self::s($sor['CODE'] ?? '', 60),
            'vonalkod'       => self::s($sor['BARCODE'] ?? '', 60),
            'osztaly'        => self::s($sor['CLASSIFICATION_NUMBER'] ?? '', 60),
            'keszletkezeles' => (int) ($sor['STOCK_CONTROL'] ?? 0) === 1 ? 1 : 0,
            'keszlet'        => 0,
            'min_keszlet'    => round(self::szam($sor['QUANTITY_MIN'] ?? 0), 3),
            'me'             => self::s($sor['UNIT'] ?? '', 20) !== '' ? self::s($sor['UNIT'], 20) : 'db',
            'besz_netto'     => round(self::szam($sor['COST_PRICE'] ?? 0), 2),
            'besz_afa_kulcs' => self::afa_kulcs($sor['COST_TAX_RATE'] ?? null),
            'brutto_ar'      => self::brutto($sor['SELLING_PRICE'] ?? 0, $sor['SELLING_TAX_RATE'] ?? 0.27),
            'afa_kulcs'      => self::afa_kulcs($sor['SELLING_TAX_RATE'] ?? null),
            'kedvezmeny'     => round(min(100, max(0, self::szam($sor['SELLING_DISCOUNT_RATE'] ?? 0) * 100)), 2),
            'megjegyzes'     => self::hozzafuz(self::s($sor['NOTES'] ?? ''), $belso),
            'aktiv'          => 1,
            'letrehozva'     => self::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? current_time('mysql'),
            'letrehozo'      => self::felhasznalo($sor['SYSTEM_CREATOR'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    public static function szolgaltatas(array $sor): array
    {
        $megj = self::hozzafuz(self::s($sor['DESCRIPTION'] ?? ''), [self::s($sor['NOTES'] ?? '')]);

        return [
            'nev'        => self::s($sor['NAME'] ?? '', 255),
            'me'         => self::s($sor['UNIT'] ?? '', 20) !== '' ? self::s($sor['UNIT'], 20) : 'db',
            'brutto_ar'  => self::brutto($sor['SELLING_PRICE'] ?? 0, $sor['SELLING_TAX_RATE'] ?? 0.27),
            'afa_kulcs'  => self::afa_kulcs($sor['SELLING_TAX_RATE'] ?? null),
            'megjegyzes' => $megj,
            'kategoria'  => self::kategoria('APP.ITEM_CATEGORY', $sor['CATEGORY_ID'] ?? null, 160),
            'kod'        => self::s($sor['CODE'] ?? '', 20),
            'letrehozva' => self::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? current_time('mysql'),
        ];
    }

    /* =================================================================
     * Munkalap
     * ============================================================== */

    /** A régi fizetési mód neve a rendszer listájából (vagy ahogy a régiben volt). */
    public static function fizmod($kod): string
    {
        if ($kod === null || $kod === '') {
            return '';
        }

        $nev   = self::FIZMODOK[(int) $kod] ?? ('MunkaLap fizetési mód ' . (int) $kod);
        $lista = class_exists('SDH_Muhely_Munkalap') ? SDH_Muhely_Munkalap::fizetesi_modok() : [];

        foreach ($lista as $l) {
            if (self::norm($l) === self::norm($nev) || (self::norm($nev) === 'szep kartya' && strpos(self::norm($l), 'szep') !== false)) {
                return $l;
            }
        }

        return $nev;
    }

    /** Az állapotkulcs a rendszer állapotlistájában (ha a lista egyedi, név szerint keresve). */
    public static function allapot(int $kod): string
    {
        $kulcs = self::ALLAPOTOK[$kod] ?? 'nyitott';
        $lista = SDH_Muhely_Munkalap::allapotok();

        if (isset($lista[$kulcs])) {
            return $kulcs;
        }

        return SDH_Muhely_Adatatvetel::allapot(self::ALLAPOT_NEVEK[$kod] ?? 'Nyitott');
    }

    /**
     * Az állapotváltások a régi időbélyegekből (időrendben).
     *
     * @return array<int, array{ido: string, allapot: string, ki: int}>
     */
    public static function allapot_tortenet(array $sor): array
    {
        $parok = [
            ['REPORTING_TIME', 5, 'REPORTER'], ['BID_TIME', 6, 'BIDDER'], ['PENDING_TIME', 0, 'PENDER'], ['OPENING_TIME', 1, 'OPENER'],
            ['COMPLETION_TIME', 2, 'COMPLETER'], ['CLOSING_TIME', 3, 'CLOSER'], ['CANCELLATION_TIME', 4, 'CANCELLER'],
        ];
        $ki = [];

        foreach ($parok as [$mezo, $kod, $ki_mezo]) {
            $ido = self::ido($sor[$mezo] ?? '');

            if ($ido !== null) {
                $ki[] = ['ido' => $ido, 'allapot' => self::allapot($kod), 'ki' => self::felhasznalo($sor[$ki_mezo] ?? null), 'kod' => $kod];
            }
        }

        usort($ki, static fn (array $a, array $b): int => [$a['ido'], $a['kod']] <=> [$b['ido'], $b['kod']]);

        return $ki;
    }

    /**
     * @param array<string, mixed>|null $thing a régi tárgy (eszköz) sora
     * @return array<string, mixed>
     */
    public static function munkalap(array $sor, ?array $thing, bool $arukiadas): array
    {
        $kod     = (int) ($sor['STATE'] ?? 1);
        $allapot = self::allapot($kod);
        $zart    = !empty(SDH_Muhely_Munkalap::allapotok()[$allapot]['zart']);
        $netto   = round(self::szam($sor['NET_SUM'] ?? 0), 2);
        $brutto  = round(self::szam($sor['NET_SUM'] ?? 0) + self::szam($sor['TAX_SUM'] ?? 0), 0);
        $fizetve = (int) ($sor['PAYED'] ?? 0) === 1;
        $eloleg  = round(self::szam($sor['DEPOSIT'] ?? 0), 2);
        $e       = self::egyedi('WORKSHEET', $sor);
        $belso   = [];
        $ugyfmeg = [];

        $tart = [];

        foreach ((array) ($e['tartozek'] ?? []) as $t) {
            $tart[] = $t['nev'] . (self::igen($t['ertek']) ? '' : ' (' . $t['ertek'] . ')');
        }

        if ($tart !== []) {
            $belso[] = 'Átvett tartozékok: ' . implode(', ', $tart);
        }

        foreach ((array) ($e['ugyfel_megjegyzes'] ?? []) as $t) {
            $ugyfmeg[] = $t['nev'] . ': ' . $t['ertek'];
        }

        if ($eloleg > 0) {
            $belso[] = 'MunkaLap 3 – előleg: ' . number_format($eloleg, 0, ',', ' ') . ' Ft';
        }

        if ((int) ($sor['INVOICED'] ?? 0) === 1 || self::s($sor['INVOICE_ID'] ?? '') !== '') {
            $belso[] = 'MunkaLap 3 – számlázva' . (self::s($sor['INVOICE_ID'] ?? '') !== '' ? ' (számla #' . self::s($sor['INVOICE_ID']) . ')' : '');
        }

        $ki_ido = [
            'REPORTING_TIME' => 'Bejelentve', 'BID_TIME' => 'Árajánlat', 'PENDING_TIME' => 'Függőbe téve', 'OPENING_TIME' => 'Megnyitva',
            'COMPLETION_TIME' => 'Elkészült', 'CLOSING_TIME' => 'Lezárva', 'CANCELLATION_TIME' => 'Érvénytelenítve',
        ];
        $ki_mezo = [
            'REPORTING_TIME' => 'REPORTER', 'BID_TIME' => 'BIDDER', 'PENDING_TIME' => 'PENDER', 'OPENING_TIME' => 'OPENER',
            'COMPLETION_TIME' => 'COMPLETER', 'CLOSING_TIME' => 'CLOSER', 'CANCELLATION_TIME' => 'CANCELLER',
        ];

        foreach ($ki_ido as $mezo => $felirat) {
            $ido = self::ido($sor[$mezo] ?? '');
            $nev = self::felhasznalo_nev($sor[$ki_mezo[$mezo]] ?? null);

            if ($ido !== null && $nev !== '' && self::felhasznalo($sor[$ki_mezo[$mezo]] ?? null) === 0) {
                $belso[] = 'MunkaLap 3 – ' . $felirat . ': ' . substr($ido, 0, 16) . ' (' . $nev . ')';
            }
        }

        $lezarva = null;

        if ($zart) {
            $lezarva = $kod === 4
                ? (self::nap($sor['CANCELLATION_TIME'] ?? '') ?? self::nap($sor['CLOSING_TIME'] ?? '') ?? self::nap($sor['CREATION_TIME'] ?? ''))
                : (self::nap($sor['CLOSING_TIME'] ?? '') ?? self::nap($sor['COMPLETION_TIME'] ?? '') ?? self::nap($sor['CREATION_TIME'] ?? ''));
        }

        $letrehozva = self::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? self::ido($sor['CREATION_TIME'] ?? '') ?? current_time('mysql');
        $idok       = array_filter(array_map(static fn (string $m): ?string => self::ido($sor[$m] ?? ''), array_keys($ki_ido) + [99 => 'PAYMENT_TIME']));

        return [
            'fajta'             => $arukiadas ? 'arukiadas' : 'javitas',
            'allapot'           => $allapot,
            'nev'               => self::s($sor['NAME'] ?? '', 190),
            'felelos'           => self::felhasznalo($sor['OWNER_ID'] ?? null),
            'keszult'           => self::nap($sor['CREATION_TIME'] ?? '') ?? substr($letrehozva, 0, 10),
            'hatarido'          => self::nap($sor['DEADLINE'] ?? ''),
            'lezarva'           => $lezarva,
            'fizetve'           => $fizetve ? 1 : 0,
            'fizetes_ideje'     => $fizetve ? self::nap($sor['PAYMENT_TIME'] ?? '') : null,
            'fizetesi_mod'      => mb_substr(self::fizmod($sor['PAYMENT_TYPE'] ?? null), 0, 40),
            'fizetett'          => $fizetve ? $brutto : min($eloleg, $brutto > 0 ? $brutto : $eloleg),
            'kedvezmeny'        => round(min(100, max(0, self::szam($sor['DISCOUNT_RATE'] ?? 0) * 100)), 2),
            'afakulcs'          => self::afa_kulcs($sor['TAX_RATE'] ?? null),
            'netto_ertek'       => $netto,
            'brutto_ertek'      => $brutto,
            'ugyfel_megjegyzes' => self::hozzafuz(self::s($sor['NOTES'] ?? ''), $ugyfmeg),
            'megjegyzes'        => self::hozzafuz(self::s($sor['INTERNAL_NOTES'] ?? ''), array_merge($belso, $e['_belso'])),
            'letrehozva'        => $letrehozva,
            'modositva'         => $idok !== [] ? max($idok) : $letrehozva,
            'letrehozo'         => self::felhasznalo($sor['SYSTEM_CREATOR'] ?? null),
        ];
    }

    /** A munkalap átvett tartozékai egy sorban (az eszköz Tartozékok mezőjébe, ha az üres). */
    public static function tartozekok(array $sor): string
    {
        $e    = self::egyedi('WORKSHEET', $sor);
        $tart = [];

        foreach ((array) ($e['tartozek'] ?? []) as $t) {
            $tart[] = $t['nev'] . (self::igen($t['ertek']) ? '' : ' (' . $t['ertek'] . ')');
        }

        return implode(', ', $tart);
    }

    /* =================================================================
     * Hiba és tétel
     * ============================================================== */

    /** @return array<string, mixed> */
    public static function hiba(array $sor, int $munkalap_id): array
    {
        $kulcs = self::HIBA_ALLAPOTOK[(int) ($sor['TYPE'] ?? 1)] ?? 'uj';
        $lista = SDH_Muhely_Munkalap::hiba_allapotok();

        if (!isset($lista[$kulcs])) {
            $kulcs = $kulcs === 'kesz' || $kulcs === 'nem_javitando' ? (string) (SDH_Muhely_Munkalap::zart_kulcsok($lista)[0] ?? array_key_first($lista)) : (string) array_key_first($lista);
        }

        $leiras = self::s($sor['NAME'] ?? '');
        $javit  = self::s($sor['DESCRIPTION'] ?? '');

        if (mb_strlen($leiras) > 255) {
            $javit  = self::hozzafuz($javit, ['Teljes hibaleírás: ' . $leiras]);
            $leiras = mb_substr($leiras, 0, 255);
        }

        $letre = self::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? current_time('mysql');

        return [
            'munkalap_id' => $munkalap_id,
            'sorrend'     => (int) ($sor['PROBLEM_ID'] ?? 0),
            'leiras'      => $leiras !== '' ? $leiras : '(üres hibaleírás)',
            'javitas'     => $javit,
            'allapot'     => $kulcs,
            'eszkoz_id'   => 0,
            'letrehozva'  => $letre,
            'modositva'   => self::ido($sor['SOLUTION_TIME'] ?? '') ?? $letre,
        ];
    }

    /** @return array<string, mixed> */
    public static function tetel(array $sor, int $munkalap_id, int $termek_id): array
    {
        $menny  = abs(self::szam($sor['QUANTITY'] ?? 0));
        $ar     = self::szam($sor['PRICE'] ?? 0);
        $afa    = $sor['TAX_RATE'] ?? ($sor['SELLING_TAX_RATE'] ?? null);
        $afa    = $afa === null || $afa === '' ? 0.27 : self::szam($afa);
        $kedv   = min(1.0, max(0.0, self::szam($sor['DISCOUNT_RATE'] ?? 0)));
        $netto  = $ar * $menny * (1 - $kedv);
        $tipus  = (int) ($sor['TYPE'] ?? 0) === 1 ? 'szolgaltatas' : 'termek';
        $megj   = [];

        if (self::s($sor['DESCRIPTION'] ?? '') !== '') {
            $megj[] = self::s($sor['DESCRIPTION']);
        }

        return [
            'munkalap_id'  => $munkalap_id,
            'sorrend'      => (int) ($sor['ORDERING'] ?? 0),
            'tipus'        => $tipus,
            'mozgas'       => (int) ($sor['DIRECTION'] ?? 2) === 1 ? 'bejovo' : 'kimeno',
            'allapot'      => (int) ($sor['STATE'] ?? 1) === 1 ? 'teljesitett' : 'tervezett',
            'idopont'      => self::nap($sor['TRANSACTION_TIME'] ?? '') ?? self::nap($sor['SYSTEM_CREATION_TIME'] ?? ''),
            'megnevezes'   => self::s($sor['NAME'] ?? '', 255) !== '' ? self::s($sor['NAME'], 255) : '(névtelen tétel)',
            'eszkoz_id'    => 0,
            'termek_id'    => $termek_id,
            'termekkod'    => self::s($sor['CODE'] ?? '', 60),
            'cikkszam'     => self::s($sor['ITEM_NUMBER'] ?? '', 60),
            'gyari_szam'   => self::s($sor['FACTORY_NUMBER'] ?? '', 60),
            'mennyiseg'    => round($menny, 3),
            'me'           => self::s($sor['UNIT'] ?? '', 20) !== '' ? self::s($sor['UNIT'], 20) : 'db',
            'munkavegzo'   => self::felhasznalo($sor['WORKER_ID'] ?? null),
            'kedvezmeny'   => round($kedv * 100, 2),
            'afa'          => round($afa * 100, 2),
            'afa_kulcs'    => self::afa_kulcs($afa),
            'netto_ar'     => round($ar, 2),
            'brutto_ar'    => round($ar * (1 + $afa), 2),
            'netto_ertek'  => round($netto, 2),
            'brutto_ertek' => round($netto * (1 + $afa), 2),
            'elado'        => mb_substr(self::ugyfel_nev($sor['SELLER_ID'] ?? ''), 0, 190),
            'letrehozva'   => self::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? current_time('mysql'),
            'modositva'    => self::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? current_time('mysql'),
        ];
    }

    /** A készletmozgás típusa a régi irányból. */
    public static function mozgas_tipus(array $sor): string
    {
        switch ((int) ($sor['DIRECTION'] ?? 3)) {
            case 0:
                return 'nyito';
            case 1:
                return 'bevet';
            case 2:
                return (string) ($sor['CONNECTION_KEY'] ?? '') === 'WORKSHEET' ? 'munkalap' : 'korrekcio';
        }

        return 'korrekcio';
    }

    /* =================================================================
     * E-mail
     * ============================================================== */

    /** HTML → olvasható szöveg. */
    public static function szoveg(string $html): string
    {
        $s = preg_replace('#<(br|/p|/div|/tr|/h[1-6]|/li)\b[^>]*>#i', "\n", $html) ?? $html;
        $s = html_entity_decode(wp_strip_all_tags($s, false), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace("/[ \t]+\n/", "\n", $s) ?? $s;
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;

        return trim($s);
    }

    /** Egy elküldött e-mail önálló HTML-dokumentumként (a munkalap Fájlok lapfülére). */
    public static function email_html(array $sor): string
    {
        $tipus = [1 => 'Piszkozat', 2 => 'Elküldött', 3 => 'Elküldetlen'][(int) ($sor['TYPE'] ?? 2)] ?? 'E-mail';
        $fej   = [
            'Állapot'   => $tipus,
            'Idő'       => (string) (self::ido($sor['TRANSFER_DATE'] ?? '') ?? self::ido($sor['SYSTEM_CREATION_TIME'] ?? '') ?? ''),
            'Feladó'    => self::s($sor['SENDER'] ?? ''),
            'Válaszcím' => self::s($sor['REPLY_TO'] ?? ''),
            'Címzett'   => self::s($sor['RECIPIENTS'] ?? ''),
            'Másolat'   => self::s($sor['COPY'] ?? ''),
            'Titkos másolat' => self::s($sor['BLIND_COPY'] ?? ''),
            'Tárgy'     => self::s($sor['SUBJECT'] ?? ''),
        ];
        $sorok = '';

        foreach ($fej as $k => $v) {
            if ($v !== '') {
                $sorok .= '<tr><th style="text-align:left;padding:2px 12px 2px 0">' . esc_html($k) . '</th><td>' . esc_html($v) . '</td></tr>';
            }
        }

        $tartalom = wp_kses_post((string) ($sor['CONTENT'] ?? ''));

        return '<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>' . esc_html($fej['Tárgy'] !== '' ? $fej['Tárgy'] : 'E-mail') . '</title></head>'
            . '<body style="font-family:sans-serif;max-width:860px;margin:24px auto;line-height:1.5">'
            . '<p style="color:#666">MunkaLap 3 – átvett e-mail</p><table>' . $sorok . '</table><hr>' . $tartalom . '</body></html>';
    }

    /** A levél munkalapja a tárgyból: „Munkalap (17219)”. */
    public static function email_munkalap(array $sor): string
    {
        if (strtoupper((string) ($sor['DATABASE_TABLE'] ?? '')) !== 'WORKSHEET') {
            return '';
        }

        foreach ([(string) ($sor['SUBJECT'] ?? ''), (string) ($sor['CONTENT'] ?? '')] as $s) {
            if (preg_match('/\((\d{1,9})\)/', $s, $m) === 1) {
                return $m[1];
            }
        }

        return '';
    }
}
