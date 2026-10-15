<?php
/**
 * Gyorsítótár a drága számolásokhoz (0.64.0).
 *
 * Mire való: a kezdőképernyő számai (nyitott munkalapok, ügyfelek, eszközök,
 * rendelések), a rács találatszáma és összegsora, a tételnév-lista – ezek
 * 500 ezer munkalapnál minden betöltéskor a teljes táblát olvasnák végig.
 * Itt egyszer számolódnak, és addig érvényesek, amíg az adat nem változik.
 *
 * Hogyan tudja, hogy változott: a WordPress adatbázis-rétegének `query`
 * szűrőjén minden író lekérdezés átmegy (ugyanúgy, mint a változásnaplónál).
 * Ha az a munkalapot, ügyfelet, eszközt, tételt, pénztárat vagy üzenetet
 * érinti, új „adatverzió" készül, és minden tárolt érték érvényét veszti –
 * ugyanabban a kérésben azonnal, a többiben a kérés végétől. Ami mégis a
 * WordPress megkerülésével íródna (kézi SQL), az legkésőbb az élettartam
 * (alapból 10 perc) végén frissül.
 *
 * Hol tárol: tranziensként (wp_options), kérésen belül memóriában is.
 * Tartós objektum-gyorsítótár (Redis) esetén a WordPress magától azt használja.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Gyorsitotar
{
    /** Az adatverzió option-je (a változásnapló kihagyja: „verzio"). */
    public const OPT = 'sdh_muhely_adatverzio';

    /** A tranziensek előtagja (a változásnapló kihagyja: „gyorsitotar"). */
    private const ELOTAG = 'sdh_gyorsitotar_';

    /** Alapértelmezett élettartam másodpercben. */
    public const ELET = 600;

    /** Ezeknek a tábláknak az írása érvényteleníti a tárolt értékeket. */
    private const FIGYELT = [
        'munkalap', 'munkalap_tetel', 'munkalap_eszkoz', 'munkalap_hiba', 'ugyfel', 'eszkoz',
        'penztar', 'uzenet', 'szamla', 'termek', 'szolgaltatas',
    ];

    private static ?string $verzio = null;

    private static bool $valtozott = false;

    /** @var array<string, mixed> */
    private static array $memoria = [];

    private static string $minta = '';

    public static function init(): void
    {
        add_filter('query', [self::class, 'figyel'], 1);
        add_action('shutdown', [self::class, 'ment'], 0);
    }

    /**
     * Író lekérdezés a figyelt táblákon? Akkor új adatverzió.
     *
     * @param mixed $query
     * @return mixed
     */
    public static function figyel($query)
    {
        if (!is_string($query) || $query === '' || self::$valtozott && self::$memoria === []) {
            return $query;
        }

        // Olcsó előszűrés: csak az író lekérdezések.
        if (preg_match('/^\s*(?:\/\*.*?\*\/\s*)*(insert|update|delete|replace|truncate)\b/i', $query) !== 1) {
            return $query;
        }

        if (preg_match(self::minta(), $query) === 1) {
            self::ervenytelenit();
        }

        return $query;
    }

    private static function minta(): string
    {
        if (self::$minta === '') {
            global $wpdb;

            $nevek = array_map(
                static fn (string $t): string => preg_quote($wpdb->prefix . 'sdh_' . $t, '/'),
                self::FIGYELT
            );

            self::$minta = '/^\s*(?:\/\*.*?\*\/\s*)*(?:insert\s+(?:ignore\s+)?into|update|delete\s+from|replace\s+into|truncate(?:\s+table)?)\s+`?(?:'
                . implode('|', $nevek) . ')`?(?:\s|$|;|\()/i';
        }

        return self::$minta;
    }

    /** Minden tárolt érték érvényét veszti (pl. tömeges import után kézzel is hívható). */
    public static function ervenytelenit(): void
    {
        self::$valtozott = true;
        self::$verzio    = uniqid('', true);
        self::$memoria   = [];
    }

    /** A kérés végén az új adatverzió mentése (egyetlen option-írás kérésenként). */
    public static function ment(): void
    {
        if (self::$valtozott && self::$verzio !== null) {
            update_option(self::OPT, self::$verzio, true);
            self::$valtozott = false;
            self::takarit();
        }
    }

    /**
     * A kulcs a kérésenként véletlen „%"-helyőrző nélkül: a $wpdb->prepare() a LIKE-minták %-jelét
     * kérésenként más jelsorozattal védi – e nélkül a kereső-lekérdezések kulcsa minden kérésben más lenne.
     */
    private static function kulcs_tisztit(string $kulcs): string
    {
        global $wpdb;

        return is_object($wpdb) && method_exists($wpdb, 'remove_placeholder_escape') ? $wpdb->remove_placeholder_escape($kulcs) : $kulcs;
    }

    /**
     * A tárolt érték neve: az adatverzió rövid lenyomata + a kulcs lenyomata. A verzió-előtag
     * miatt a régi verziók értékei egy mozdulattal takaríthatók (takarit).
     */
    private static function nev(string $kulcs): string
    {
        return self::ELOTAG . substr(md5(self::verzio()), 0, 8) . '_' . md5($kulcs);
    }

    /** A verziótól független (élettartamig tárolt) érték neve. */
    private static function allando_nev(string $kulcs): string
    {
        return self::ELOTAG . 'allando_' . md5($kulcs);
    }

    /**
     * A régi adatverziók tárolt értékeinek törlése (wp_options) – legfeljebb tízpercenként, a kérés
     * végén. Tartós objektum-gyorsítótárnál (Redis) nincs teendő: ott a lejárat magától takarít.
     */
    private static function takarit(): void
    {
        global $wpdb;

        if (wp_using_ext_object_cache() || get_transient('sdh_muhely_gyorsitotar_takaritas') !== false) {
            return;
        }

        set_transient('sdh_muhely_gyorsitotar_takaritas', 1, 600);

        // A minták „_" jele helyettesítő is (bármely egy betű) – ez itt nem baj, és így a MySQL és a
        // SQLite is ugyanúgy érti (a \\_ kiírás csak MySQL-en jelent szó szerinti aláhúzást).
        $elotag  = self::ELOTAG;
        $most    = self::ELOTAG . substr(md5(self::verzio()), 0, 8) . '_';
        $allando = self::ELOTAG . 'allando_';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE (option_name LIKE %s OR option_name LIKE %s)
               AND option_name NOT LIKE %s AND option_name NOT LIKE %s
               AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
            '_transient_' . $elotag . '%',
            '_transient_timeout_' . $elotag . '%',
            '_transient_' . $most . '%',
            '_transient_timeout_' . $most . '%',
            '_transient_' . $allando . '%',
            '_transient_timeout_' . $allando . '%'
        ));
        // phpcs:enable
    }

    public static function verzio(): string
    {
        if (self::$verzio === null) {
            $v = get_option(self::OPT, '');
            self::$verzio = is_string($v) && $v !== '' ? $v : '0';
        }

        return self::$verzio;
    }

    /**
     * Tárolt érték, vagy kiszámolja és eltárolja.
     *
     * @param string   $kulcs     Mi ez (a paraméterekkel együtt egyedi).
     * @param callable $szamol    A számolás.
     * @param bool     $verziohoz Igaz (alap): adatváltozáskor azonnal érvényét veszti. Hamis: csak
     *                            az élettartam végén (lassan változó statisztika, pl. tételnevek gyakorisága).
     * @return mixed
     */
    public static function ertek(string $kulcs, callable $szamol, int $elet = self::ELET, bool $verziohoz = true)
    {
        $kulcs = self::kulcs_tisztit($kulcs);

        if (!$verziohoz) {
            $teljes = self::allando_nev($kulcs);
            $tarolt = array_key_exists($teljes, self::$memoria) ? ['e' => self::$memoria[$teljes]] : get_transient($teljes);

            if (is_array($tarolt) && array_key_exists('e', $tarolt)) {
                return self::$memoria[$teljes] = $tarolt['e'];
            }

            $ertek = $szamol();
            set_transient($teljes, ['e' => $ertek], $elet);

            return self::$memoria[$teljes] = $ertek;
        }

        $teljes = self::nev($kulcs);

        if (array_key_exists($teljes, self::$memoria)) {
            return self::$memoria[$teljes];
        }

        $tarolt = get_transient($teljes);

        if (is_array($tarolt) && array_key_exists('e', $tarolt)) {
            self::$memoria[$teljes] = $tarolt['e'];

            return $tarolt['e'];
        }

        $ertek = $szamol();

        // A számolás közbeni írás (ritka) új verziót hozott: ilyenkor nem tároljuk el a régihez.
        if (self::nev($kulcs) === $teljes) {
            set_transient($teljes, ['e' => $ertek], $elet);
            self::$memoria[$teljes] = $ertek;
        }

        return $ertek;
    }

    /**
     * Egy COUNT(*)-lekérdezés eredménye tárolva (a listák találatszáma: lapozáskor ugyanaz).
     */
    public static function szam(string $sql): int
    {
        global $wpdb;

        return (int) self::ertek('szam|' . $sql, static fn (): int => (int) $wpdb->get_var($sql)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** Egy verziótól független (élettartamig tárolt) érték törlése – a következő kérés újraszámolja. */
    public static function allando_torol(string $kulcs): void
    {
        $kulcs = self::kulcs_tisztit($kulcs);
        $teljes = self::allando_nev($kulcs);

        unset(self::$memoria[$teljes]);
        delete_transient($teljes);
    }

    /**
     * A tárolt érték, ha van – számolás nélkül. $van jelzi, hogy volt-e.
     *
     * @return mixed
     */
    public static function tarolt(string $kulcs, ?bool &$van = null)
    {
        $kulcs = self::kulcs_tisztit($kulcs);
        $teljes = self::nev($kulcs);

        if (array_key_exists($teljes, self::$memoria)) {
            $van = true;

            return self::$memoria[$teljes];
        }

        $tarolt = get_transient($teljes);
        $van    = is_array($tarolt) && array_key_exists('e', $tarolt);

        if ($van) {
            self::$memoria[$teljes] = $tarolt['e'];

            return $tarolt['e'];
        }

        return null;
    }

    /**
     * Kérésen belüli tárolás (ugyanaz a számolás egy oldalon kétszer ne fusson le).
     *
     * @return mixed
     */
    public static function keresben(string $kulcs, callable $szamol)
    {
        $teljes = 'k:' . $kulcs . '|' . self::verzio();

        if (!array_key_exists($teljes, self::$memoria)) {
            self::$memoria[$teljes] = $szamol();
        }

        return self::$memoria[$teljes];
    }
}
