<?php
/**
 * Okos kereső (0.99.4).
 *
 * Egy logika minden keresőnek: a fejléc keresője, a listák keresői (Ügyfelek, Eszközök, Munkalapok,
 * Napi árukiadás, Rendelések, Termékek, Szolgáltatások) és az Áttekintés kereső- és szűrőmezői.
 *
 * 1. A beírt szöveg fajtáját felismeri: IMEI (14–17 számjegy), telefonszám, rövid szám (munkalap- vagy
 *    ügyfélszám), e-mail, kód (betű + szám, szóköz nélkül: gyári szám, cikkszám, vonalkód), márka
 *    (ismert gyártó az első szóban), vagy szöveg (név, termék).
 * 2. Gépelés közben azonnali találatok minden fajta rekordból (ügyfél, eszköz, lap, termék,
 *    szolgáltatás), a fajtának megfelelő csoport elöl: IMEI-re az eszköz, telefonra a teljes ügyfél,
 *    márkára az eszközök és termékek, névre az ügyfél, termékre a termék.
 * 3. A listák szűrésénél „első körben specifikusan”: IMEI-re csak az IMEI, telefonra csak az ügyfél
 *    telefonja – és ha így nincs találat, magától a régi, mindenre kiterjedő keresés megy.
 *
 * Minden lekérdezés indexelt (egyenlőség vagy „kezdődik vele”), legfeljebb néhány sort kér; a
 * „tartalmazza” csak akkor fut, ha az indexelt lépés semmit nem adott.
 *
 * Beállítások › Keresés és listák: minden itteni újdonság ki-be kapcsolható.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Okoskereso
{
    public const OPT = 'sdh_muhely_kereses';

    /** Gyártók, amelyeket az első szóban márkának veszünk (a saját eszközökben talált gyártók is). */
    private const MARKAK = [
        'apple', 'iphone', 'ipad', 'samsung', 'xiaomi', 'redmi', 'poco', 'huawei', 'honor', 'oneplus', 'oppo',
        'realme', 'vivo', 'motorola', 'nokia', 'sony', 'lg', 'google', 'pixel', 'asus', 'lenovo', 'acer', 'dell',
        'hp', 'msi', 'toshiba', 'zte', 'alcatel', 'tcl', 'blackview', 'doogee', 'ulefone', 'cat', 'nothing',
        'fairphone', 'microsoft', 'surface', 'garmin', 'fitbit', 'amazfit', 'jbl', 'bose', 'dji', 'gopro',
        'nintendo', 'playstation', 'xbox', 'philips', 'panasonic', 'canon', 'nikon', 'meizu', 'wiko', 'infinix',
        'tecno', 'cubot', 'oukitel', 'hama', 'baseus', 'anker', 'ugreen', 'tp-link', 'tplink',
    ];

    /** @var array<string, int>|null */
    private static ?array $cache = null;

    /** Futhat-e a „tartalmazza” (indexet nem használó) lépés az éppen keresett csoportban. */
    private static bool $draga = true;

    /** @var array<int, int>|null Az ügyfélcsoport találatai ebben a keresésben (a lapok csoportja újra felhasználja). */
    private static ?array $ugyfel_idk = null;

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_okos_kereso', [self::class, 'ajax_kereso']);
        add_action('wp_ajax_sdh_muhely_racs_javaslat', [self::class, 'ajax_racs_javaslat']);

        // A Beállítások osztálya később töltődik be: a lapfül a betöltés után jelentkezik be.
        add_action('plugins_loaded', static function (): void {
            if (class_exists('SDH_Muhely_Beallitasok')) {
                SDH_Muhely_Beallitasok::regisztral('kereses', [
                    'cim'     => 'Keresés és listák',
                    'csoport' => 'megjelenes',
                    'leiras'  => 'Azonnali találatok minden keresőben, típus szerinti (IMEI, telefon, sorszám, márka) keresés, oldalszám-ugró, dupla kattintás, szűrő-kereső, állapot pontként.',
                    'rajz'    => [self::class, 'beallitas_doboz'],
                    'ment'    => [self::class, 'beallitas_mentes'],
                ]);
            }
        });
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /** @return array<string, array{0: string, 1: string}> kulcs => [cím, súgó] */
    public static function kapcsolok(): array
    {
        return [
            'azonnali'     => ['Azonnali találatok minden keresőben', 'Gépelés közben a lista keresője is lenyíló találatokat mutat, mint a fejléc keresője (ügyfél, eszköz, lap, termék, szolgáltatás).'],
            'okos'         => ['Típus szerinti (okos) keresés', 'IMEI-re eszközt, telefonszámra ügyfelet, rövid számra munkalapot, márkára készüléket keres először; ha így nincs találat, mindenben keres.'],
            'ugro'         => ['Oldalszám-ugró mező a lapozóknál', 'A számozott lapozó mellett egy mező: beírod az oldalszámot, Enterre oda ugrik.'],
            'dupla'        => ['Dupla kattintás megnyitja a sort', 'A listák sorai (munkalapok, eszközök, árukiadás, rendelések, termékek, ügyfelek, szolgáltatások) dupla kattintásra is megnyílnak.'],
            'szuro_kereso' => ['Szöveges kereső az Áttekintés szűrőiben', 'Az Áttekintés minden szűrőjében gépelhetsz: a választós szűrők listája szűkül, a szöveges szűrők javaslatot adnak.'],
            'allapot_pont' => ['Új felhasználónak az Állapot oszlop pontként', 'Az Áttekintés Állapot oszlopa alapból csak a színes pontot mutatja (kattintásra ugyanúgy választható). Mindenki a saját rácsán is átállíthatja: Oszlopok › Állapot csak színes pont.'],
        ];
    }

    /** @return array<string, int> */
    public static function beallitas(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $alap  = ['azonnali' => 1, 'okos' => 1, 'ugro' => 1, 'dupla' => 1, 'szuro_kereso' => 1, 'allapot_pont' => 0];
        $nyers = get_option(self::OPT, []);
        $nyers = is_array($nyers) ? $nyers : [];
        $ki    = [];

        foreach ($alap as $k => $v) {
            $ki[$k] = array_key_exists($k, $nyers) ? (int) (bool) $nyers[$k] : $v;
        }

        return self::$cache = $ki;
    }

    public static function be(string $kulcs): bool
    {
        return !empty(self::beallitas()[$kulcs]);
    }

    /** Teszteknek. */
    public static function urit(): void
    {
        self::$cache = null;
    }

    /** A böngészőnek (app.js / racs.js). @return array<string, int> */
    public static function js(): array
    {
        return self::beallitas();
    }

    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Keresés és listák</h2>
            <p class="sdh-sugo">
                A keresők gépelés közben találatot adnak, és felismerik, mit írtál be: a hosszú szám IMEI vagy gyári szám,
                a telefonszám a teljes ügyfelet hozza, a márka a készülékeket és termékeket. A listák lapozója mellett
                oldalszámra lehet ugrani, a sorok dupla kattintásra nyílnak. Bármelyik kikapcsolható.
            </p>
            <input type="hidden" name="kereses_jelen" value="1">
            <div class="sdh-okos-kapcsolok">
                <?php foreach (self::kapcsolok() as $k => [$cim, $sugo]) : ?>
                    <div class="sdh-okos-kapcsolo">
                        <label class="sdh-kapcsolo" title="<?php echo esc_attr($cim); ?>">
                            <input type="checkbox" name="kereses[<?php echo esc_attr($k); ?>]" value="1" <?php checked(!empty($b[$k])); ?>>

                        </label>
                        <div>
                            <strong><?php echo esc_html($cim); ?></strong>
                            <p class="sdh-mezo__sugo"><?php echo esc_html($sugo); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások ellenőrizte.
        if (!isset($_POST['kereses_jelen'])) {
            return;
        }

        $be = isset($_POST['kereses']) && is_array($_POST['kereses']) ? wp_unslash($_POST['kereses']) : [];
        // phpcs:enable
        $ki = [];

        foreach (array_keys(self::kapcsolok()) as $k) {
            $ki[$k] = !empty($be[$k]) ? 1 : 0;
        }

        update_option(self::OPT, $ki, false);
        self::$cache = null;
    }

    /* =================================================================
     * A beírt szöveg fajtája
     * ============================================================== */

    /**
     * imei | telefon | szam | email | kod | marka | szoveg
     */
    public static function tipus(string $q): string
    {
        $q = trim($q);

        if ($q === '') {
            return 'szoveg';
        }

        if (strpos($q, '@') !== false) {
            return 'email';
        }

        if (preg_match('/^[\d\s+\-()\/.]+$/', $q) === 1) {
            $d = (string) preg_replace('/\D+/', '', $q);
            $n = strlen($d);

            if ($n >= 14 && $n <= 17) {
                return 'imei';
            }

            if ($n >= 7 && $n <= 13) {
                return 'telefon';
            }

            return $n > 0 ? 'szam' : 'szoveg';
        }

        // Betű és szám vegyesen, szóköz nélkül: gyári szám, modellszám, cikkszám.
        if (preg_match('/\s/u', $q) !== 1 && strlen($q) >= 5 && preg_match('/\d/', $q) === 1 && preg_match('/[a-z]/i', $q) === 1) {
            return 'kod';
        }

        if (self::marka($q) !== '') {
            return 'marka';
        }

        return 'szoveg';
    }

    /** A szöveg első szava ismert gyártó-e: a gyártó neve (ahogy az eszközökben áll), vagy ''. */
    public static function marka(string $q): string
    {
        $elso = mb_strtolower((string) strtok(trim($q), " \t"));

        if ($elso === '' || mb_strlen($elso) < 2) {
            return '';
        }

        foreach (self::markak() as $m) {
            if (mb_strtolower($m) === $elso) {
                return $m;
            }
        }

        return in_array($elso, self::MARKAK, true) ? $elso : '';
    }

    /** @return array<int, string> a rendszerben előforduló gyártók (tárolva) */
    public static function markak(): array
    {
        if (!class_exists('SDH_Muhely_Gyorsitotar')) {
            return [];
        }

        return (array) SDH_Muhely_Gyorsitotar::ertek('okos_markak', static function (): array {
            global $wpdb;

            $t = SDH_Muhely_Schema::tabla('eszkoz');

            // A gyártó indexén: egyedi értékek, gyorsan (legfeljebb 300).
            return array_values(array_filter(array_map('strval', (array) $wpdb->get_col(
                "SELECT DISTINCT gyarto FROM {$t} WHERE gyarto <> '' ORDER BY gyarto LIMIT 300" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ))));
        }, 6 * HOUR_IN_SECONDS, false);
    }

    public static function szamjegyek(string $q): string
    {
        return (string) preg_replace('/\D+/', '', $q);
    }

    /** Telefon vége: a tel_ford (megfordított számjegyek) eleje – 06 / 36 / +36 nélkül, az utolsó 9 jegyből. */
    public static function tel_minta(string $q): string
    {
        $d = self::szamjegyek($q);

        if (strlen($d) > 9) {
            $d = substr($d, -9);
        }

        return strrev($d);
    }

    /** A szöveg szavai (legfeljebb 4, a hosszabbak elöl). @return array<int, string> */
    public static function szavak(string $q): array
    {
        $sz = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sz = array_values(array_unique(array_filter($sz, static fn (string $s): bool => mb_strlen($s) >= 1)));

        return array_slice($sz, 0, 4);
    }

    /* =================================================================
     * Lista-szűkítés: „első körben specifikusan”
     * ============================================================== */

    /**
     * A lista keresésének specifikus feltétele, ha a beírt szöveg fajtája egyértelmű.
     * null: nincs specifikus feltétel (a lista a megszokott, mindenre kiterjedő keresést használja).
     *
     * @param string $lista ugyfel | eszkoz | munkalap | eladas | termek | szolgaltatas
     * @return array{sql: string, ertekek: array<int, mixed>, cimke: string}|null
     */
    public static function szukites(string $lista, string $q): ?array
    {
        $sz = self::szukites_nyers($lista, $q);

        // IMEI-re a pontos keresés a teljes: ha nincs találat, a „mindenben” sem hozna mást (csak lassabb lenne).
        if ($sz !== null && self::tipus($q) === 'imei') {
            $sz['veg'] = true;
        }

        return $sz;
    }

    /** @return array{sql: string, ertekek: array<int, mixed>, cimke: string}|null */
    private static function szukites_nyers(string $lista, string $q): ?array
    {
        global $wpdb;

        if (!self::be('okos') || trim($q) === '' || !empty($_GET['mind'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return null;
        }

        $tipus = self::tipus($q);
        $d     = self::szamjegyek($q);
        $u     = SDH_Muhely_Schema::tabla('ugyfel');
        $e     = SDH_Muhely_Schema::tabla('eszkoz');
        $tk    = SDH_Muhely_Schema::tabla('munkalap_eszkoz');
        $kezd  = static fn (string $s): string => $wpdb->esc_like($s) . '%';
        $tel   = class_exists('SDH_Muhely_Ugyfel') && SDH_Muhely_Ugyfel::kereso_keszen();

        // A lap / eszköz ügyfél- és eszköz-allekérdezései (munkalap, eladás).
        $lap_eszkoz = static function (string $hol, array $ertek) use ($e, $tk): array {
            return [
                "(m.eszkoz_id IN (SELECT ke.id FROM {$e} ke WHERE {$hol}) OR m.id IN (SELECT tk.munkalap_id FROM {$tk} tk INNER JOIN {$e} ke ON ke.id = tk.eszkoz_id WHERE {$hol}))",
                array_merge($ertek, $ertek),
            ];
        };

        switch ($lista) {
            case 'ugyfel':
                if ($tipus === 'telefon' && $tel) {
                    return ['sql' => 'tel_ford LIKE %s', 'ertekek' => [$kezd(self::tel_minta($q))], 'cimke' => 'telefonszám szerint'];
                }

                if ($tipus === 'telefon') {
                    return ['sql' => '(telefon LIKE %s OR telefon2 LIKE %s)', 'ertekek' => ['%' . $wpdb->esc_like(substr($d, -7)) . '%', '%' . $wpdb->esc_like(substr($d, -7)) . '%'], 'cimke' => 'telefonszám szerint'];
                }

                if ($tipus === 'email') {
                    return ['sql' => 'email LIKE %s', 'ertekek' => [$kezd(trim($q))], 'cimke' => 'e-mail szerint'];
                }

                if ($tipus === 'szam') {
                    return ['sql' => 'ugyfel_szam LIKE %s', 'ertekek' => [$kezd(trim($q))], 'cimke' => 'ügyfélszám szerint'];
                }

                return null;

            case 'eszkoz':
                if ($tipus === 'imei') {
                    return ['sql' => '(e.imei LIKE %s OR e.imei2 LIKE %s)', 'ertekek' => [$kezd($d), $kezd($d)], 'cimke' => 'IMEI szerint'];
                }

                if ($tipus === 'kod' || $tipus === 'telefon') {
                    $k = $tipus === 'telefon' ? $d : trim($q);

                    if ($tipus === 'telefon' && $tel) {
                        return [
                            'sql'     => "(e.sorozatszam LIKE %s OR e.imei LIKE %s OR e.ugyfel_id IN (SELECT ku.id FROM {$u} ku WHERE ku.tel_ford LIKE %s))",
                            'ertekek' => [$kezd($k), $kezd($k), $kezd(self::tel_minta($q))],
                            'cimke'   => 'gyári szám, IMEI vagy az ügyfél telefonja szerint',
                        ];
                    }

                    return ['sql' => '(e.sorozatszam LIKE %s OR e.modell_szam LIKE %s OR e.imei LIKE %s)', 'ertekek' => [$kezd($k), $kezd($k), $kezd($k)], 'cimke' => 'gyári szám / modellszám szerint'];
                }

                if ($tipus === 'marka') {
                    return self::marka_feltetel('e', $q);
                }

                return null;

            case 'munkalap':
            case 'eladas':
                if ($tipus === 'szam') {
                    return ['sql' => 'm.munkalap_szam = %d', 'ertekek' => [(int) $d], 'cimke' => 'sorszám szerint'];
                }

                if ($tipus === 'imei') {
                    [$sql, $ert] = $lap_eszkoz('ke.imei LIKE %s OR ke.imei2 LIKE %s', [$kezd($d), $kezd($d)]);

                    return ['sql' => $sql, 'ertekek' => $ert, 'cimke' => 'IMEI szerint'];
                }

                if ($tipus === 'kod') {
                    [$sql, $ert] = $lap_eszkoz('ke.sorozatszam LIKE %s OR ke.modell_szam LIKE %s', [$kezd(trim($q)), $kezd(trim($q))]);

                    return ['sql' => $sql, 'ertekek' => $ert, 'cimke' => 'gyári szám szerint'];
                }

                if ($tipus === 'telefon') {
                    return $tel
                        ? ['sql' => "m.ugyfel_id IN (SELECT ku.id FROM {$u} ku WHERE ku.tel_ford LIKE %s)", 'ertekek' => [$kezd(self::tel_minta($q))], 'cimke' => 'az ügyfél telefonszáma szerint']
                        : ['sql' => "m.ugyfel_id IN (SELECT ku.id FROM {$u} ku WHERE ku.telefon LIKE %s)", 'ertekek' => ['%' . $wpdb->esc_like(substr($d, -7)) . '%'], 'cimke' => 'az ügyfél telefonszáma szerint'];
                }

                if ($tipus === 'email') {
                    return ['sql' => "m.ugyfel_id IN (SELECT ku.id FROM {$u} ku WHERE ku.email LIKE %s)", 'ertekek' => [$kezd(trim($q))], 'cimke' => 'az ügyfél e-mail címe szerint'];
                }

                if ($tipus === 'marka' && $lista === 'munkalap') {
                    $f = self::marka_feltetel('ke', $q);
                    [$sql, $ert] = $lap_eszkoz($f['sql'], $f['ertekek']);

                    return ['sql' => $sql, 'ertekek' => $ert, 'cimke' => $f['cimke']];
                }

                return null;

            case 'termek':
                if ($tipus === 'kod' || $tipus === 'imei' || $tipus === 'telefon' || ($tipus === 'szam' && strlen($d) >= 4)) {
                    $k = trim($q);

                    return [
                        'sql'     => '(vonalkod LIKE %s OR cikkszam LIKE %s OR termekkod LIKE %s OR gyari_szam LIKE %s)',
                        'ertekek' => [$kezd($k), $kezd($k), $kezd($k), $kezd($k)],
                        'cimke'   => 'kód szerint (vonalkód, cikkszám, termékkód, gyári szám)',
                    ];
                }

                if ($tipus === 'marka' || $tipus === 'szoveg') {
                    return self::szavak_feltetel('megnevezes', $q, 'megnevezés szerint (minden szó)');
                }

                return null;

            case 'szolgaltatas':
                if ($tipus === 'kod' || $tipus === 'szam') {
                    return ['sql' => 'kod LIKE %s', 'ertekek' => [$kezd(trim($q))], 'cimke' => 'kód szerint'];
                }

                if ($tipus === 'marka' || $tipus === 'szoveg') {
                    return self::szavak_feltetel('nev', $q, 'megnevezés szerint (minden szó)');
                }

                return null;
        }

        return null;
    }

    /** A márka + a további szavak a típusban / megnevezésben. @return array{sql: string, ertekek: array<int, mixed>, cimke: string} */
    private static function marka_feltetel(string $al, string $q): array
    {
        global $wpdb;

        $marka  = self::marka($q);
        $tobbi  = array_slice(self::szavak($q), 1);
        $sql    = "{$al}.gyarto = %s";
        $ertek  = [$marka];

        // Az „iphone 13” is márka: az Apple készülékei, a típusban „iphone 13”.
        if (in_array(mb_strtolower($marka), ['iphone', 'ipad', 'pixel', 'redmi', 'poco', 'galaxy'], true)) {
            $sql   = "({$al}.tipus LIKE %s OR {$al}.megnevezes LIKE %s)";
            $ertek = ['%' . $wpdb->esc_like($marka) . '%', '%' . $wpdb->esc_like($marka) . '%'];
        }

        foreach ($tobbi as $szo) {
            $sql    .= " AND ({$al}.tipus LIKE %s OR {$al}.megnevezes LIKE %s OR {$al}.modell_szam LIKE %s)";
            $m       = '%' . $wpdb->esc_like($szo) . '%';
            array_push($ertek, $m, $m, $m);
        }

        return ['sql' => '(' . $sql . ')', 'ertekek' => $ertek, 'cimke' => 'márka szerint'];
    }

    /** Minden szó szerepeljen az oszlopban (sorrendtől függetlenül). @return array{sql: string, ertekek: array<int, mixed>, cimke: string}|null */
    private static function szavak_feltetel(string $oszlop, string $q, string $cimke): ?array
    {
        global $wpdb;

        $szavak = self::szavak($q);

        // Egy szónál a megszokott keresés ugyanezt adja (és több oszlopot is néz).
        if (count($szavak) < 2) {
            return null;
        }

        $sql = [];
        $ert = [];

        foreach ($szavak as $szo) {
            $sql[] = "{$oszlop} LIKE %s";
            $ert[] = '%' . $wpdb->esc_like($szo) . '%';
        }

        return ['sql' => '(' . implode(' AND ', $sql) . ')', 'ertekek' => $ert, 'cimke' => $cimke];
    }

    /**
     * A szűkítés jelzése a lista keresője alatt: mi szerint keresett, és link a „mindenben” kereséshez.
     *
     * @param array<string, mixed> $alap a lista URL-paraméterei (k, szűrők)
     */
    public static function jelzes(?array $szukites, bool $visszaesett, string $modul, array $alap): string
    {
        if ($szukites === null && !$visszaesett) {
            return '';
        }

        if ($visszaesett) {
            return '<p class="sdh-okos-jel">Pontos (' . esc_html((string) ($szukites['cimke'] ?? '')) . ') találat nem volt – mindenben kerestem.</p>';
        }

        $url = SDH_Muhely_Modulok::url($modul, array_merge($alap, ['mind' => 1]));

        return '<p class="sdh-okos-jel">Pontos találat: <strong>' . esc_html((string) $szukites['cimke']) . '</strong>. '
            . '<a href="' . esc_url($url) . '">Keresés mindenben</a></p>';
    }

    /* =================================================================
     * Azonnali találatok (minden kereső)
     * ============================================================== */

    /**
     * Csoportos találatok, a fajtának megfelelő csoport elöl; $elso: ennek a modulnak a csoportja kerül előre.
     *
     * @return array<int, array{cim: string, modul: string, talalatok: array<int, array<string, mixed>>}>
     */
    public static function keres(string $q, string $elso = '', int $db = 5): array
    {
        $q = trim($q);

        if (mb_strlen($q) < 2) {
            return [];
        }

        $tipus = self::be('okos') ? self::tipus($q) : 'szoveg';

        // Elsődleges csoportok; a másodlagosakban csak akkor keres, ha az elsődlegesek alig adtak valamit.
        $sorrend = [
            'imei'    => ['eszkoz', 'lap'],
            'telefon' => ['ugyfel', 'lap', 'eszkoz'],
            'szam'    => ['lap', 'ugyfel', 'termek', 'szolgaltatas'],
            'email'   => ['ugyfel', 'lap'],
            'kod'     => ['eszkoz', 'termek', 'szolgaltatas', 'lap'],
            'marka'   => ['eszkoz', 'termek', 'szolgaltatas', 'lap'],
            'szoveg'  => ['ugyfel', 'termek', 'szolgaltatas', 'eszkoz', 'lap'],
        ][$tipus];
        $masodlagos = [
            'imei' => ['termek', 'ugyfel'], 'telefon' => ['termek'], 'szam' => ['eszkoz'], 'email' => [],
            'kod' => ['ugyfel'], 'marka' => ['ugyfel'], 'szoveg' => [],
        ][$tipus];

        $modul_csoport = [
            'ugyfelek' => 'ugyfel', 'eszkozok' => 'eszkoz', 'munkalapok' => 'lap', 'arukiadas' => 'lap',
            'rendelesek' => 'lap', 'termekek' => 'termek', 'szolgaltatasok' => 'szolgaltatas', 'attekintes' => 'lap',
        ];

        // A modul oldalán annak a csoportja kerül előre (akkor is, ha a fajta szerint csak másodlagos lenne).
        if (isset($modul_csoport[$elso]) && in_array($modul_csoport[$elso], array_merge($sorrend, $masodlagos), true)) {
            $sorrend    = array_values(array_unique(array_merge([$modul_csoport[$elso]], $sorrend)));
            $masodlagos = array_values(array_diff($masodlagos, [$modul_csoport[$elso]]));
        }

        $ki               = [];
        $talalt           = 0;
        $sor              = 0;
        self::$ugyfel_idk = null;

        foreach (array_merge($sorrend, ['|'], $masodlagos) as $cs) {
            if ($cs === '|') {
                if ($talalt > 0) {
                    break;
                }

                continue;
            }

            if (!self::csoport_latszik($cs)) {
                continue;
            }

            // A teljes táblát olvasó „tartalmazza” csak az első két csoportban, vagy ha még alig van találat:
            // gépelés közben így sem lesz lassú 300 000 rekordnál.
            self::$draga = $sor < 2 || $talalt < 3;
            $sor++;

            $fv        = 'csoport_' . $cs;
            $talalatok = self::$fv($q, $tipus, $db);
            $talalt   += count($talalatok);

            if ($talalatok !== []) {
                $ki[] = ['cim' => self::csoport_cim($cs), 'modul' => $cs, 'talalatok' => $talalatok];
            }
        }

        self::$draga = true;

        return $ki;
    }

    private static function csoport_latszik(string $cs): bool
    {
        $modul = ['ugyfel' => 'ugyfelek', 'eszkoz' => 'eszkozok', 'lap' => 'munkalapok', 'termek' => 'termekek', 'szolgaltatas' => 'szolgaltatasok'][$cs] ?? '';

        if ($modul === '' || !class_exists('SDH_Muhely_Modulok')) {
            return true;
        }

        // Kikapcsolt modul (Modulok) vagy a szerepkörben nem elérhető: nincs csoportja.
        if (in_array($modul, ['termekek', 'szolgaltatasok', 'munkalapok'], true) && SDH_Muhely_Modulok::ki($modul)) {
            return false;
        }

        return !class_exists('SDH_Muhely_Szerepkor') || !method_exists('SDH_Muhely_Szerepkor', 'modul_engedett') || SDH_Muhely_Szerepkor::modul_engedett($modul);
    }

    private static function csoport_cim(string $cs): string
    {
        return ['ugyfel' => 'Ügyfelek', 'eszkoz' => 'Eszközök', 'lap' => 'Munkalapok és eladások', 'termek' => 'Termékek', 'szolgaltatas' => 'Szolgáltatások'][$cs] ?? $cs;
    }

    /** @return array<int, array<string, mixed>> */
    private static function csoport_ugyfel(string $q, string $tipus, int $db): array
    {
        global $wpdb;

        $u     = SDH_Muhely_Schema::tabla('ugyfel');
        $oszl  = 'id, nev, telefon, szamlazasi_iranyitoszam, szamlazasi_telepules, szamlazasi_cim, ugyfel_szam';
        $sorok = null;

        // Telefonszámra és e-mailre egyenesen az indexről (a teljes ügyfélkereső szókeresése nélkül).
        if ($tipus === 'telefon' && SDH_Muhely_Ugyfel::kereso_keszen()) {
            $sorok = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT {$oszl} FROM {$u} WHERE aktiv = 1 AND tel_ford LIKE %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->esc_like(self::tel_minta($q)) . '%',
                $db
            ));
        } elseif ($tipus === 'email') {
            $sorok = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT {$oszl} FROM {$u} WHERE aktiv = 1 AND email LIKE %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->esc_like(trim($q)) . '%',
                $db
            ));
        } elseif ($tipus === 'imei') {
            return [];
        }

        if ($sorok === null || $sorok === []) {
            $sorok = class_exists('SDH_Muhely_Ugyfel') ? SDH_Muhely_Ugyfel::kereses($q, $db) : null;
        }

        if ($sorok === null) {
            $minta = '%' . $wpdb->esc_like($q) . '%';
            $sorok = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT id, nev, telefon, szamlazasi_iranyitoszam, szamlazasi_telepules, szamlazasi_cim, ugyfel_szam FROM {$u}
                 WHERE aktiv = 1 AND (nev LIKE %s OR telefon LIKE %s OR email LIKE %s OR ugyfel_szam LIKE %s) ORDER BY nev LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $minta,
                $minta,
                $minta,
                $minta,
                $db
            ));
        }

        $ki               = [];
        self::$ugyfel_idk = array_map(static fn ($s): int => (int) $s->id, (array) $sorok);

        foreach ($sorok as $s) {
            $ki[] = [
                'cim'     => (string) $s->nev,
                'reszlet' => implode(' · ', array_filter([(string) $s->telefon, SDH_Muhely_Ugyfel::cim_szoveg($s), (string) $s->ugyfel_szam])),
                'url'     => SDH_Muhely_Modulok::url('ugyfelek', ['nezet' => 'szerkeszt', 'id' => (int) $s->id]),
                'urlap'   => 'ugyfelek',
                'id'      => (int) $s->id,
            ];
        }

        return $ki;
    }

    /** @return array<int, array<string, mixed>> */
    private static function csoport_eszkoz(string $q, string $tipus, int $db): array
    {
        global $wpdb;

        $e   = SDH_Muhely_Schema::tabla('eszkoz');
        $u   = SDH_Muhely_Schema::tabla('ugyfel');
        $d   = self::szamjegyek($q);
        $kez = static fn (string $s): string => $wpdb->esc_like($s) . '%';
        $lep = [];

        switch ($tipus) {
            case 'imei':
                $lep[] = ['e.imei = %s OR e.imei2 = %s', [$d, $d]];
                $lep[] = ['e.imei LIKE %s OR e.imei2 LIKE %s', [$kez($d), $kez($d)]];
                break;
            case 'kod':
                $lep[] = ['e.sorozatszam = %s', [trim($q)]];
                $lep[] = ['e.sorozatszam LIKE %s OR e.imei LIKE %s', [$kez(trim($q)), $kez(trim($q))]];
                break;
            case 'telefon':
                if (SDH_Muhely_Ugyfel::kereso_keszen()) {
                    $lep[] = ["e.ugyfel_id IN (SELECT ku.id FROM {$u} ku WHERE ku.tel_ford LIKE %s)", [$kez(self::tel_minta($q))]];
                }
                $lep[] = ['e.imei LIKE %s OR e.sorozatszam LIKE %s', [$kez($d), $kez($d)]];
                break;
            case 'marka':
                $f     = self::marka_feltetel('e', $q);
                $lep[] = [$f['sql'], $f['ertekek']];
                break;
            case 'szam':
                $lep[] = ['e.imei LIKE %s OR e.sorozatszam LIKE %s', [$kez($d), $kez(trim($q))]];
                break;
            default:
                $lep[] = ['e.tipus LIKE %s OR e.megnevezes LIKE %s OR e.gyarto LIKE %s', [$kez($q), $kez($q), $kez($q)]];
                $lep[] = ['e.tipus LIKE %s OR e.megnevezes LIKE %s', ['%' . $wpdb->esc_like($q) . '%', '%' . $wpdb->esc_like($q) . '%'], true];
        }

        $sorok = self::lepcsok(
            "SELECT e.id, e.gyarto, e.tipus, e.megnevezes, e.imei, e.sorozatszam, u.nev AS ugyfel_nev
             FROM {$e} e LEFT JOIN {$u} u ON u.id = e.ugyfel_id WHERE e.aktiv = 1 AND (%FELTETEL%)",
            $lep,
            $db,
            'e.id'
        );

        $ki = [];

        foreach ($sorok as $s) {
            $ki[] = [
                'cim'     => trim($s->gyarto . ' ' . $s->tipus) ?: ((string) $s->megnevezes ?: 'Névtelen eszköz'),
                'reszlet' => implode(' · ', array_filter([(string) $s->megnevezes, (string) ($s->imei ?: $s->sorozatszam), (string) $s->ugyfel_nev])),
                'url'     => SDH_Muhely_Modulok::url('eszkozok', ['nezet' => 'szerkeszt', 'id' => (int) $s->id]),
                'urlap'   => 'eszkozok',
                'id'      => (int) $s->id,
            ];
        }

        return $ki;
    }

    /** @return array<int, array<string, mixed>> */
    private static function csoport_lap(string $q, string $tipus, int $db): array
    {
        global $wpdb;

        $m   = SDH_Muhely_Schema::tabla('munkalap');
        $u   = SDH_Muhely_Schema::tabla('ugyfel');
        $e   = SDH_Muhely_Schema::tabla('eszkoz');
        $d   = self::szamjegyek($q);
        $kez = static fn (string $s): string => $wpdb->esc_like($s) . '%';
        $lep = [];

        switch ($tipus) {
            case 'szam':
                $lep[] = ['m.munkalap_szam = %d', [(int) $d]];
                $lep[] = ['m.rend_szam LIKE %s', [$kez(trim($q))], true];
                break;
            case 'imei':
                $lep[] = ["m.eszkoz_id IN (SELECT ke.id FROM {$e} ke WHERE ke.imei LIKE %s OR ke.imei2 LIKE %s)", [$kez($d), $kez($d)]];
                break;
            case 'kod':
                $lep[] = ["m.eszkoz_id IN (SELECT ke.id FROM {$e} ke WHERE ke.sorozatszam LIKE %s)", [$kez(trim($q))]];
                $lep[] = ['m.rend_szam LIKE %s', [$kez(trim($q))], true];
                break;
            case 'telefon':
                if (SDH_Muhely_Ugyfel::kereso_keszen()) {
                    $lep[] = ["m.ugyfel_id IN (SELECT ku.id FROM {$u} ku WHERE ku.tel_ford LIKE %s)", [$kez(self::tel_minta($q))]];
                }
                break;
            case 'email':
                $lep[] = ["m.ugyfel_id IN (SELECT ku.id FROM {$u} ku WHERE ku.email LIKE %s)", [$kez(trim($q))]];
                break;
            case 'marka':
                $f     = self::marka_feltetel('ke', $q);
                $lep[] = ["m.eszkoz_id IN (SELECT ke.id FROM {$e} ke WHERE {$f['sql']})", $f['ertekek']];
                break;
            default:
                // Név: az ügyfélkereső találatainak lapjai, és a lap saját neve (eladás).
                $idk = self::$ugyfel_idk ?? array_map(static fn ($s): int => (int) $s->id, (array) (SDH_Muhely_Ugyfel::kereses($q, 8) ?? []));

                if ($idk !== []) {
                    $lep[] = ['m.ugyfel_id IN (' . implode(',', $idk) . ')', []];
                }

                $lep[] = ['m.nev LIKE %s', [$kez($q)], true];
        }

        if ($lep === []) {
            return [];
        }

        $sorok = self::lepcsok(
            "SELECT m.id, m.munkalap_szam, m.fajta, m.allapot, m.keszult, m.nev, u.nev AS ugyfel_nev, e.gyarto, e.tipus
             FROM {$m} m LEFT JOIN {$u} u ON u.id = m.ugyfel_id LEFT JOIN {$e} e ON e.id = m.eszkoz_id WHERE (%FELTETEL%)",
            $lep,
            $db,
            'm.id',
            'm.id DESC'
        );

        $allapotok = class_exists('SDH_Muhely_Munkalap') ? SDH_Muhely_Munkalap::allapotok() : [];
        $ki        = [];

        foreach ($sorok as $s) {
            $fajta = (string) $s->fajta;
            $modul = $fajta === 'arukiadas' ? 'arukiadas' : ($fajta === 'rendeles' ? 'rendelesek' : 'munkalapok');
            $elo   = $fajta === 'arukiadas' ? 'Árukiadás ' : ($fajta === 'rendeles' ? 'Rendelés ' : 'Munkalap ');

            $ki[] = [
                'cim'     => $elo . (string) $s->munkalap_szam . ((string) $s->ugyfel_nev !== '' ? ' – ' . $s->ugyfel_nev : ((string) $s->nev !== '' ? ' – ' . $s->nev : '')),
                'reszlet' => implode(' · ', array_filter([
                    trim($s->gyarto . ' ' . $s->tipus),
                    (string) ($allapotok[(string) $s->allapot]['nev'] ?? $s->allapot),
                    $s->keszult ? mysql2date('Y.m.d.', (string) $s->keszult) : '',
                ])),
                'url'     => SDH_Muhely_Modulok::url($modul, ['nezet' => 'szerkeszt', 'id' => (int) $s->id]),
                // Az eladás is a munkalap ablakában nyílik (az a sorából tudja, hogy eladás).
                'urlap'   => 'munkalapok',
                'id'      => (int) $s->id,
            ];
        }

        return $ki;
    }

    /** @return array<int, array<string, mixed>> */
    private static function csoport_termek(string $q, string $tipus, int $db): array
    {
        global $wpdb;

        $t   = SDH_Muhely_Schema::tabla('termek');
        $kez = static fn (string $s): string => $wpdb->esc_like($s) . '%';
        $k   = trim($q);
        $lep = [];

        if (in_array($tipus, ['kod', 'imei', 'telefon', 'szam'], true)) {
            $lep[] = ['vonalkod = %s OR cikkszam = %s OR termekkod = %s', [$k, $k, $k]];
            $lep[] = ['vonalkod LIKE %s OR cikkszam LIKE %s OR termekkod LIKE %s', [$kez($k), $kez($k), $kez($k)]];
        }

        $lep[] = ['megnevezes LIKE %s', [$kez($k)]];

        $szavak = self::szavak($k);
        $sql    = [];
        $ert    = [];

        foreach ($szavak as $szo) {
            $sql[] = 'megnevezes LIKE %s';
            $ert[] = '%' . $wpdb->esc_like($szo) . '%';
        }

        if ($sql !== []) {
            $lep[] = [implode(' AND ', $sql), $ert, true];
        }

        $sorok = self::lepcsok("SELECT id, megnevezes, cikkszam, vonalkod, keszlet, keszletkezeles, brutto_ar, me FROM {$t} WHERE aktiv = 1 AND (%FELTETEL%)", $lep, $db, 'id');
        $ki    = [];

        foreach ($sorok as $s) {
            $ki[] = [
                'cim'     => (string) $s->megnevezes,
                'reszlet' => implode(' · ', array_filter([
                    (string) $s->cikkszam,
                    (int) $s->keszletkezeles === 1 ? 'készlet: ' . rtrim(rtrim(number_format((float) $s->keszlet, 2, ',', ' '), '0'), ',') . ' ' . $s->me : '',
                    (float) $s->brutto_ar > 0 ? number_format((float) $s->brutto_ar, 0, ',', ' ') . ' Ft' : '',
                ])),
                'url'     => SDH_Muhely_Modulok::url('termekek', ['nezet' => 'szerkeszt', 'id' => (int) $s->id]),
                'urlap'   => 'termekek',
                'id'      => (int) $s->id,
            ];
        }

        return $ki;
    }

    /** @return array<int, array<string, mixed>> */
    private static function csoport_szolgaltatas(string $q, string $tipus, int $db): array
    {
        global $wpdb;

        $t   = SDH_Muhely_Schema::tabla('szolgaltatas');
        $kez = static fn (string $s): string => $wpdb->esc_like($s) . '%';
        $k   = trim($q);
        $lep = [];

        if (in_array($tipus, ['kod', 'szam'], true)) {
            $lep[] = ['kod = %s', [$k]];
        }

        $lep[] = ['nev LIKE %s', [$kez($k)]];

        $sql = [];
        $ert = [];

        foreach (self::szavak($k) as $szo) {
            $sql[] = 'nev LIKE %s';
            $ert[] = '%' . $wpdb->esc_like($szo) . '%';
        }

        if ($sql !== []) {
            $lep[] = [implode(' AND ', $sql), $ert, true];
        }

        $sorok = self::lepcsok("SELECT id, nev, kategoria, brutto_ar, kod FROM {$t} WHERE (%FELTETEL%)", $lep, $db, 'id', 'hasznalat DESC, id DESC');
        $ki    = [];

        foreach ($sorok as $s) {
            $ki[] = [
                'cim'     => (string) $s->nev,
                'reszlet' => implode(' · ', array_filter([(string) $s->kategoria, (string) $s->kod, (float) $s->brutto_ar > 0 ? number_format((float) $s->brutto_ar, 0, ',', ' ') . ' Ft' : ''])),
                'url'     => SDH_Muhely_Modulok::url('szolgaltatasok', ['nezet' => 'szerkeszt', 'id' => (int) $s->id]),
                'urlap'   => 'szolgaltatasok',
                'id'      => (int) $s->id,
            ];
        }

        return $ki;
    }

    /**
     * Lépcsős lekérdezés: a lépések sorban, amíg $db találat nincs (a már megtaláltak kizárva).
     *
     * @param array<int, array{0: string, 1: array<int, mixed>}> $lepesek
     * @return array<int, object>
     */
    private static function lepcsok(string $sql, array $lepesek, int $db, string $id_oszlop, string $rend = ''): array
    {
        global $wpdb;

        $talalat = [];

        foreach ($lepesek as $lepes) {
            [$feltetel, $ertekek] = $lepes;
            $hiany = $db - count($talalat);

            if ($hiany <= 0) {
                break;
            }

            // A drága (a teljes táblát olvasó, „tartalmazza”) lépés csak az elöl álló csoportoknak (keres()).
            if (!empty($lepes[2]) && !self::$draga) {
                continue;
            }

            $kiz = $talalat !== [] ? ' AND ' . $id_oszlop . ' NOT IN (' . implode(',', array_map('intval', array_keys($talalat))) . ')' : '';
            $q   = str_replace('%FELTETEL%', $feltetel, $sql) . $kiz . ($rend !== '' ? ' ORDER BY ' . $rend : '') . ' LIMIT ' . (int) $hiany;

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a feltételek csak helyőrzőket és egész számokat tartalmaznak.
            $sorok = $ertekek !== [] ? $wpdb->get_results($wpdb->prepare($q, $ertekek)) : $wpdb->get_results($q);

            foreach ((array) $sorok as $s) {
                $talalat[(int) $s->id] = $s;
            }
        }

        return array_values($talalat);
    }

    public static function ajax_kereso(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $q    = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $elso = isset($_GET['elso']) ? sanitize_key(wp_unslash($_GET['elso'])) : '';

        wp_send_json_success(['tipus' => self::tipus($q), 'csoportok' => self::keres($q, $elso)]);
    }

    /* =================================================================
     * Az Áttekintés szöveges szűrőinek javaslatai
     * ============================================================== */

    /**
     * Egy rácsoszlop értékei, amelyek a beírttal kezdődnek (majd tartalmazzák) – legfeljebb 8.
     *
     * @return array<int, string>
     */
    public static function racs_javaslat(string $oszlop, string $q): array
    {
        global $wpdb;

        $q = trim($q);

        if (mb_strlen($q) < 1 || !class_exists('SDH_Muhely_Racs')) {
            return [];
        }

        $o = SDH_Muhely_Racs::oszlopok()[$oszlop] ?? null;

        if ($o === null || ($o['tipus'] ?? '') !== 'szoveg') {
            return [];
        }

        // Csak az egyszerű „tábla.oszlop” kifejezésekhez (COALESCE(x.y, '')): a forrástáblán, indexen.
        if (preg_match("/^(?:COALESCE\\()?([mue])\\.(\\w+)(?:, '')?\\)?$/", (string) $o['sql'], $t) !== 1) {
            return [];
        }

        $tabla = ['m' => 'munkalap', 'u' => 'ugyfel', 'e' => 'eszkoz'][$t[1]];
        $col   = $t[2];
        $tn    = SDH_Muhely_Schema::tabla($tabla);
        $ki    = [];

        foreach ([$wpdb->esc_like($q) . '%', '%' . $wpdb->esc_like($q) . '%'] as $minta) {
            $hiany = 8 - count($ki);

            if ($hiany <= 0) {
                break;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- az oszlop és a tábla a rács fehérlistájából jön.
            $ertekek = (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT {$col} FROM {$tn} WHERE {$col} LIKE %s LIMIT %d", $minta, 40));

            foreach ($ertekek as $e) {
                $e = trim((string) $e);

                if ($e !== '' && !in_array($e, $ki, true)) {
                    $ki[] = $e;
                }

                if (count($ki) >= 8) {
                    break;
                }
            }
        }

        natcasesort($ki);

        return array_values($ki);
    }

    public static function ajax_racs_javaslat(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $oszlop = isset($_GET['oszlop']) ? sanitize_key(wp_unslash($_GET['oszlop'])) : '';
        $q      = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

        wp_send_json_success(self::be('szuro_kereso') ? self::racs_javaslat($oszlop, $q) : []);
    }
}
