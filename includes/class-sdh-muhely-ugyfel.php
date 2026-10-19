<?php
/**
 * Ügyfél-modul.
 *
 * Ügyfelek listája kereséssel és lapozással, felvitel és szerkesztés
 * popupban, inaktiválás törlés helyett.
 *
 * Ugyanez a kód fut a saját műhely-felületen és a wp-adminban is. A modul
 * nem tudja, melyiken van – minden URL-t a SDH_Muhely_Modulok épít, az
 * pedig ismeri a kontextust.
 *
 * A felvitel popupban történik, hogy ne veszítsd el a listát és a
 * keresést. A teljes oldalas űrlap megmarad tartalékként: ha a
 * JavaScript nem fut, a linkek oda visznek, és a rendszer működik tovább.
 *
 * Miért nincs törlés: egy ügyfélre később munkalapok hivatkoznak. Ha az
 * ügyfél eltűnne, a munkalap története értelmezhetetlen lenne. Ezért az
 * ügyfél inaktívvá válik – kikerül a napi listából, de a régi munkalapok
 * mellett megmarad.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Ugyfel
{
    /** A központi mellett tárolt címek és mezőik (a lapfülek sorrendjében). */
    private const MASODLAGOS_CIMEK = [
        'levelezesi' => ['iranyitoszam', 'telepules', 'cim'],
        'szallitasi' => ['iranyitoszam', 'telepules', 'cim'],
        'telephely'  => ['nev', 'iranyitoszam', 'telepules', 'cim'],
    ];

    /** A modul kulcsa az URL-ekben, a menüben és az AJAX-műveletekben. */
    public const KULCS = 'ugyfelek';

    /** Hány sor egy oldalon. */
    private const OLDAL_MERET = 25;

    /** A gyorskereső oszlopainak állapota: KERESO_KESZ, ha minden meglévő sor fel van töltve. */
    public const KERESO_ALLAPOT = 'sdh_muhely_kereso_allapot';

    /** A kész feltöltés jele (ha a kereső adatai bővülnek, ez lép – a feltöltés újrafut). */
    public const KERESO_KESZ = 'kesz-2';

    /** Egy ügyfél legfeljebb ennyi keresőszót kap (név, település, cím, e-mail, telefon, ügyfélszám). */
    private const SZO_MAX = 16;

    /** A közterület jellege: a címből ezek nem lesznek keresőszavak. */
    private const KOZTERULET = [
        'utca', 'u', 'ut', 'utja', 'ter', 'tere', 'korut', 'krt', 'koz', 'sor', 'sugarut', 'fasor', 'setany', 'dulo',
        'lakotelep', 'ltp', 'hrsz', 'emelet', 'em', 'ajto', 'fszt', 'hsz', 'major', 'tanya', 'puszta', 'park', 'liget', 'rakpart', 'lejto', 'lepcso',
    ];

    /** Egy keresőszó legfeljebb ennyi karakter (a séma varchar(40)-e). */
    private const SZO_HOSSZ = 40;

    /** A háttérfeltöltés kurzora (az utolsó feldolgozott azonosító). */
    private const KERESO_KURZOR = 'sdh_muhely_kereso_utolso';

    /** Egy feltöltési köteg mérete (egy UPDATE … CASE). */
    private const KERESO_KOTEG = 500;

    /** Egy kérés legfeljebb ennyi másodpercet tölt a feltöltéssel. */
    private const KERESO_IDO = 1.0;

    /** A kereső-oszlop hossza (a séma varchar(190)-ével egyezik). */
    private const KERESO_HOSSZ = 190;

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Ügyfelek',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 10,
        ]);

        // Teljes oldalas űrlap beküldése (tartalék, JS nélkül is működik).
        add_action('admin_post_sdh_muhely_ugyfel_mentes', [self::class, 'mentes']);
        add_action('admin_post_sdh_muhely_ugyfel_allapot', [self::class, 'allapot_valtas']);

        // Popup: az űrlap lekérése és beküldése.
        add_action('wp_ajax_sdh_muhely_ugyfelek_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_ugyfelek_ment', [self::class, 'ajax_mentes']);

        // Más modulok ügyfélválasztó mezője ebből él.
        add_action('wp_ajax_sdh_muhely_ugyfelek_kereso', [self::class, 'ajax_kereso']);

        // Gyorskereső (0.64): bármelyik kódút írja az ügyfelet azonosító szerint (számla, SMS,
        // AI-eszköz, jövőbeli modul), a keresőadatai a kérés végén frissülnek.
        add_filter('query', [self::class, 'iras_figyel'], 5);
        add_action('shutdown', [self::class, 'iras_utan'], -1);

        // Gyorskereső (0.64): a meglévő sorok kötegelt feltöltése, amíg el nem készül.
        if (get_option(self::KERESO_ALLAPOT) !== self::KERESO_KESZ) {
            add_action('init', [self::class, 'kereso_hatter'], 30);
        }
    }

    /* =================================================================
     * Ügyfélválasztó – más modulok használják
     * ============================================================== */

    /**
     * Gépelésre kereső ügyfélmező.
     *
     * Legördülő helyett azért, mert az átvett állományban közel 40 ezer
     * ügyfél lesz: egy <select> ennyi elemmel használhatatlan, és a
     * böngészőt is megfogná.
     */
    public static function valaszto_mezo(int $ugyfel_id = 0, string $mezo_nev = 'ugyfel_id', string $helyorzo = ''): void
    {
        $ugyfel = $ugyfel_id > 0 ? self::egy($ugyfel_id) : null;

        ?>
        <div class="sdh-valaszto" data-sdh-valaszto="ugyfelek">
            <input type="hidden" name="<?php echo esc_attr($mezo_nev); ?>"
                   value="<?php echo (int) $ugyfel_id; ?>">
            <input type="text" class="sdh-valaszto__mezo" autocomplete="off"
                   placeholder="<?php echo esc_attr($helyorzo !== '' ? $helyorzo : 'Kezdd el írni a nevet, telefont, ügyfélszámot…'); ?>"
                   title="<?php echo esc_attr($ugyfel !== null ? trim($ugyfel->nev . ' – ' . self::cim_szoveg($ugyfel), ' –') : ''); ?>"
                   value="<?php echo esc_attr($ugyfel !== null ? $ugyfel->nev : ''); ?>">
            <ul class="sdh-valaszto__lista" hidden></ul>
        </div>
        <?php
    }

    /**
     * Az ügyfél teljes címe egy sorban: „8200 Veszprém, Kossuth utca 1.".
     *
     * Mindenhol ez megy ki, ahol az ügyfél címe látszik (ügyféllista,
     * munkalaplista, rács, választók) – sehol nem csak a település. Az
     * előtag a címfajta: szamlazasi (központi), levelezesi, szallitasi, telephely.
     */
    public static function cim_szoveg(?object $ugyfel, string $elotag = 'szamlazasi'): string
    {
        if ($ugyfel === null) {
            return '';
        }

        $elso = trim(
            trim((string) ($ugyfel->{$elotag . '_iranyitoszam'} ?? '')) . ' '
            . trim((string) ($ugyfel->{$elotag . '_telepules'} ?? ''))
        );

        return implode(
            ', ',
            array_filter(
                [$elso, trim((string) ($ugyfel->{$elotag . '_cim'} ?? ''))],
                static fn (string $s): bool => $s !== ''
            )
        );
    }

    /**
     * Az ügyfélválasztó mögötti kereső.
     *
     * Csak aktív ügyfeleket ad vissza, és legfeljebb tízet: a lista
     * nem böngészésre való, hanem arra, hogy gépelés közben megtaláld
     * a megfelelőt.
     *
     * Gyors út (0.64): az indexelt `kereso` oszlopon előbb „kezdődik vele"
     * keresés (név), aztán a telefon vége, ügyfélszám és e-mail elejére,
     * és csak ha így sincs tíz találat, a „tartalmazza" – egyetlen
     * oszlopon, nem hat oszlopon OR-ral. Amíg a meglévő sorok feltöltése
     * tart, a régi út működik.
     */
    public static function ajax_kereso(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

        if (mb_strlen($q) < 2) {
            wp_send_json_success([]);
        }

        $sorok = self::kereso_keszen() ? self::kereso_gyors($q) : self::kereso_regi($q);

        $talalatok = [];

        foreach ($sorok as $sor) {
            $reszletek = array_filter([
                $sor->telefon,
                self::cim_szoveg($sor),
                $sor->ugyfel_szam,
            ]);

            $talalatok[] = [
                'id'       => (int) $sor->id,
                'nev'      => $sor->nev,
                'reszlet'  => implode(' · ', $reszletek),
                'cim'      => self::cim_szoveg($sor),
            ];
        }

        wp_send_json_success($talalatok);
    }

    /**
     * Gyors ügyfélkeresés más modulok számára (pl. a fejléc globális keresője): ugyanaz a
     * lépcsőzetes keresés, mint az ügyfélválasztóé; a feltöltés végéig null (a hívó a régi úton megy).
     *
     * @return array<int, object>|null
     */
    public static function kereses(string $q, int $limit): ?array
    {
        return self::kereso_keszen() && mb_strlen($q) >= 2 ? self::kereso_gyors($q, $limit) : null;
    }

    /** @var array<int, true> A kérésben azonosító szerint írt ügyfelek (iras_figyel). */
    private static array $irt = [];

    private static bool $frissit_fut = false;

    /**
     * Az ügyféltábla azonosító szerinti írásának észlelése ($wpdb->update alakja:
     * UPDATE `…sdh_ugyfel` SET … WHERE `id` = 12). Csak megjegyzi; a frissítés a kérés végén fut.
     *
     * @param mixed $query
     * @return mixed
     */
    public static function iras_figyel($query)
    {
        if (self::$frissit_fut || !is_string($query) || stripos($query, 'update') === false) {
            return $query;
        }

        if (preg_match('/^\s*UPDATE\s+`?' . preg_quote(self::tabla(), '/') . '`?\s+SET\s.+\sWHERE\s+`?id`?\s*=\s*\'?(\d+)\'?\s*$/is', $query, $m) === 1) {
            self::$irt[(int) $m[1]] = true;
        }

        return $query;
    }

    /** A kérés végén: a megváltozott ügyfelek keresőadatai (legfeljebb 200 – tömeges írásnál a háttérfeltöltés dolgozik). */
    public static function iras_utan(): void
    {
        // A feltöltés közben is: ami a kurzor mögött változik, azt a háttérfeltöltés már nem nézi újra.
        if (self::$irt === []) {
            return;
        }

        foreach (array_slice(array_keys(self::$irt), 0, 200) as $id) {
            self::kereso_frissit($id);
        }

        self::$irt = [];
    }

    /** A kereső-oszlopok fel vannak-e töltve (különben a régi, lassú út megy). */
    public static function kereso_keszen(): bool
    {
        return get_option(self::KERESO_ALLAPOT) === self::KERESO_KESZ;
    }

    /**
     * Keresőszöveg: kisbetűs, ékezet nélküli.
     */
    public static function kereso_norm(string $szoveg): string
    {
        $szoveg = mb_strtolower(remove_accents($szoveg));

        return trim((string) preg_replace('/\s+/u', ' ', $szoveg));
    }

    /** Csak a számjegyek. */
    private static function szamjegyek(string $szoveg): string
    {
        return (string) preg_replace('/\D+/', '', $szoveg);
    }

    /**
     * A két származtatott oszlop értéke egy ügyfélsorból.
     *
     * kereso: név, település, cím, irányítószám, e-mail, ügyfélszám és a
     * két telefon számjegyei, egy sorban (a név elöl – az előtag-keresés
     * erre épül). tel_ford: az első telefon számjegyei megfordítva, hogy a
     * telefon VÉGÉRE is lehessen indexszel keresni.
     *
     * cim_rendez: a teljes cím pontosan úgy, ahogy a rács „Cím" oszlopa
     * kiírja és rendezi (irányítószám, település, cím, szóközzel) – indexelve,
     * hogy a rács e szerint is gyorsan rendezhessen.
     *
     * @param object|array<string, mixed> $u
     * @return array{kereso: string, tel_ford: string, cim_rendez: string}
     */
    public static function kereso_adat($u): array
    {
        $u = (array) $u;
        $m = static fn (string $k): string => trim((string) ($u[$k] ?? ''));

        $kereso = self::kereso_norm(implode(' ', array_filter([
            $m('nev'),
            $m('szamlazasi_telepules'),
            $m('szamlazasi_cim'),
            $m('szamlazasi_iranyitoszam'),
            $m('email'),
            $m('ugyfel_szam'),
            self::szamjegyek($m('telefon')),
            self::szamjegyek($m('telefon2')),
        ], static fn (string $r): bool => $r !== '')));

        return [
            'kereso'     => mb_substr($kereso, 0, self::KERESO_HOSSZ),
            'tel_ford'   => strrev(substr(self::szamjegyek($m('telefon')), 0, 40)),
            'cim_rendez' => mb_substr(self::cim_rendez_ertek($u), 0, self::KERESO_HOSSZ),
        ];
    }

    /**
     * A rács „Cím" kifejezésének (SDH_Muhely_Racs::CIM_SQL) PHP-megfelelője:
     * TRIM(CONCAT(COALESCE(irsz, ''), ' ', COALESCE(település, ''), ' ', COALESCE(cím, ''))).
     *
     * @param array<string, mixed> $u
     */
    public static function cim_rendez_ertek(array $u): string
    {
        return trim(
            (string) ($u['szamlazasi_iranyitoszam'] ?? '') . ' '
            . (string) ($u['szamlazasi_telepules'] ?? '') . ' '
            . (string) ($u['szamlazasi_cim'] ?? ''),
            ' '
        );
    }

    /**
     * Az ügyfél keresőszavai (a szó-index tartalma): a név, a település, a cím
     * és az e-mail szavai kisbetűsen, ékezet nélkül; a telefonszámok számjegyei
     * (nemzetközi, 06-os és előhívó nélküli alakban is); az ügyfélszám (és a
     * számjegyei vezető nullák nélkül). Ezek ELEJÉRE lehet indexszel keresni.
     *
     * @param object|array<string, mixed> $u
     * @return array<int, string>
     */
    public static function kereso_szavak($u): array
    {
        $u = (array) $u;
        $m = static fn (string $k): string => trim((string) ($u[$k] ?? ''));

        // Az e-mailből csak a @ előtti rész (a domain – gmail, hu – mindenkinél ugyanaz lenne).
        $email = $m('email');
        $email = str_contains($email, '@') ? strstr($email, '@', true) : $email;

        $szavak = preg_split('/[^a-z0-9]+/', self::kereso_norm(implode(' ', [$m('nev'), $m('szamlazasi_telepules'), $email])), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // A címből az utca neve (a házszám és a közterület jellege – utca, út, tér – nem keresőszó).
        foreach (preg_split('/[^a-z0-9]+/', self::kereso_norm($m('szamlazasi_cim')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $szo) {
            if (!ctype_digit($szo) && !in_array($szo, self::KOZTERULET, true)) {
                $szavak[] = $szo;
            }
        }

        foreach (['telefon', 'telefon2'] as $mezo) {
            $d = self::szamjegyek($m($mezo));

            if (strlen($d) < 6) {
                continue;
            }

            $szavak[] = $d;

            // 36 30 123 4567 → 06301234567 és 301234567; 06 30 … → 3630… és 30…
            if (str_starts_with($d, '36')) {
                $szavak[] = substr($d, 2);
                $szavak[] = '06' . substr($d, 2);
            } elseif (str_starts_with($d, '06')) {
                $szavak[] = substr($d, 2);
                $szavak[] = '36' . substr($d, 2);
            }
        }

        $szam = self::kereso_norm($m('ugyfel_szam'));

        if ($szam !== '') {
            $szavak[] = $szam;

            $jegyek = ltrim(self::szamjegyek($szam), '0');

            if ($jegyek !== '') {
                $szavak[] = $jegyek;
            }
        }

        $ki = [];

        foreach ($szavak as $szo) {
            $szo = substr($szo, 0, self::SZO_HOSSZ);

            if (strlen($szo) >= 2 && !in_array($szo, $ki, true)) {
                $ki[] = $szo;
            }
        }

        return array_slice($ki, 0, self::SZO_MAX);
    }

    /** A szó-index táblája. */
    private static function szo_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('ugyfel_szo');
    }

    /**
     * Ügyfelek szavainak cseréje a szó-indexben (egy törlés + egy beszúrás).
     *
     * @param array<int, array<int, string>> $szavak ügyfél-azonosító => szavak
     */
    private static function szavak_ir(array $szavak): void
    {
        global $wpdb;

        if ($szavak === []) {
            return;
        }

        $t      = self::szo_tabla();
        $idk    = implode(',', array_map('intval', array_keys($szavak)));
        $ertek  = [];

        foreach ($szavak as $id => $lista) {
            foreach ($lista as $szo) {
                $ertek[] = "('" . esc_sql($szo) . "'," . (int) $id . ')';
            }
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- esc_sql-lel készült értékek, egész azonosítók.
        SDH_Muhely_Valtozasnaplo::csendben(static function () use ($wpdb, $t, $idk, $ertek): void {
            $wpdb->query("DELETE FROM {$t} WHERE ugyfel_id IN ({$idk})");

            foreach (array_chunk($ertek, 1000) as $darab) {
                $wpdb->query("INSERT INTO {$t} (szo, ugyfel_id) VALUES " . implode(',', $darab));
            }
        });
        // phpcs:enable
    }

    /**
     * Egy ügyfél kereső-oszlopainak frissítése. Minden kódnak hívnia kell,
     * amely ügyfelet ír (mentés, AI-eszköz, demó, import).
     */
    public static function kereso_frissit(int $id): void
    {
        global $wpdb;

        if ($id <= 0) {
            return;
        }

        $sor = $wpdb->get_row($wpdb->prepare(
            'SELECT nev, szamlazasi_telepules, szamlazasi_cim, szamlazasi_iranyitoszam, email, ugyfel_szam, telefon, telefon2 FROM ' . self::tabla() . ' WHERE id = %d',
            $id
        ));

        if (!$sor) {
            return;
        }

        $adat = self::kereso_adat($sor);

        self::$frissit_fut = true;

        try {
            SDH_Muhely_Valtozasnaplo::csendben(static function () use ($wpdb, $adat, $id): void {
                $wpdb->update(self::tabla(), $adat, ['id' => $id]);
            });

            self::szavak_ir([$id => self::kereso_szavak($sor)]);
        } finally {
            self::$frissit_fut = false;
        }

        unset(self::$irt[$id]);
    }

    /**
     * A meglévő sorok kötegelt feltöltése. Kérésenként legfeljebb
     * KERESO_IDO másodpercet dolgozik, és ott folytatja, ahol abbahagyta –
     * 500 ezer sornál sem fut ki az időből.
     */
    public static function kereso_hatter(): void
    {
        if (self::kereso_keszen() || !current_user_can(SDH_Muhely_Admin_UI::jog())) {
            return;
        }

        if (get_transient('sdh_muhely_kereso_zar') !== false) {
            return;
        }

        set_transient('sdh_muhely_kereso_zar', 1, 30);

        global $wpdb;

        $tabla  = self::tabla();
        $kezdet = microtime(true);
        $utolso = (int) get_option(self::KERESO_KURZOR, 0);

        do {
            $sorok = $wpdb->get_results($wpdb->prepare(
                "SELECT id, nev, szamlazasi_telepules, szamlazasi_cim, szamlazasi_iranyitoszam, email, ugyfel_szam, telefon, telefon2
                 FROM {$tabla} WHERE id > %d ORDER BY id ASC LIMIT %d",
                $utolso,
                self::KERESO_KOTEG
            ));

            if (!$sorok) {
                update_option(self::KERESO_ALLAPOT, self::KERESO_KESZ);
                delete_option(self::KERESO_KURZOR);
                break;
            }

            $kereso = '';
            $ford   = '';
            $cim    = '';
            $idk    = [];
            $szavak = [];

            foreach ($sorok as $sor) {
                $adat    = self::kereso_adat($sor);
                $id      = (int) $sor->id;
                $idk[]   = $id;
                $kereso .= ' WHEN ' . $id . ' THEN ' . "'" . esc_sql($adat['kereso']) . "'";
                $ford   .= ' WHEN ' . $id . ' THEN ' . "'" . esc_sql($adat['tel_ford']) . "'";
                $cim    .= ' WHEN ' . $id . ' THEN ' . "'" . esc_sql($adat['cim_rendez']) . "'";
                $utolso  = $id;

                $szavak[$id] = self::kereso_szavak($sor);
            }

            $lista = implode(',', $idk);

            self::szavak_ir($szavak);

            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- az értékek esc_sql-lel, az azonosítók egész számok.
            SDH_Muhely_Valtozasnaplo::csendben(static function () use ($wpdb, $tabla, $kereso, $ford, $cim, $lista): void {
                $wpdb->query("UPDATE {$tabla} SET kereso = CASE id{$kereso} END, tel_ford = CASE id{$ford} END, cim_rendez = CASE id{$cim} END WHERE id IN ({$lista})");
            });
            // phpcs:enable

            update_option(self::KERESO_KURZOR, $utolso, false);
        } while ((microtime(true) - $kezdet) < self::KERESO_IDO);

        delete_transient('sdh_muhely_kereso_zar');
    }

    /**
     * Gyors keresés az indexelt oszlopokon (lásd ajax_kereso).
     *
     * @return array<int, object>
     */
    private static function kereso_gyors(string $q, int $limit = 10): array
    {
        global $wpdb;

        $tabla  = self::tabla();
        $oszlop = 'id, nev, telefon, szamlazasi_iranyitoszam, szamlazasi_telepules, szamlazasi_cim, ugyfel_szam';
        $qn     = self::kereso_norm($q);
        $qd     = preg_match('/^[\d\s+\-()\/.]+$/', $q) === 1 ? self::szamjegyek($q) : '';
        $talalat = [];

        $kovetkezo = function (string $hol, array $ertekek) use ($wpdb, $tabla, $oszlop, $limit, &$talalat): void {
            $hianyzik = $limit - count($talalat);

            if ($hianyzik <= 0) {
                return;
            }

            $mar = $talalat !== [] ? ' AND id NOT IN (' . implode(',', array_map('intval', array_keys($talalat))) . ')' : '';

            // A rendezés (ha van) a $hol végén áll: a kizárás elé kell kerülnie.
            $rend = '';

            if (preg_match('/\s(ORDER BY .+)$/', $hol, $r) === 1) {
                $rend = ' ' . $r[1];
                $hol  = substr($hol, 0, -strlen($rend));
            }

            $sql = "SELECT {$oszlop} FROM {$tabla} WHERE aktiv = 1 AND {$hol}{$mar}{$rend} LIMIT {$hianyzik}";

            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- a $hol csak helyőrzőket tartalmaz, a $mar egész számokat.
            $sorok = $wpdb->get_results($ertekek !== [] ? $wpdb->prepare($sql, $ertekek) : $sql);
            // phpcs:enable

            foreach ((array) $sorok as $sor) {
                $talalat[(int) $sor->id] = $sor;
            }
        };

        // 1. Név (a keresőszöveg eleje) – indexen, rendezve.
        $kovetkezo('kereso LIKE %s ORDER BY kereso ASC', [$wpdb->esc_like($qn) . '%']);

        // 2. Telefon vége, ügyfélszám és e-mail eleje – mind indexen.
        if ($qd !== '' && strlen($qd) >= 3) {
            $kovetkezo('tel_ford LIKE %s', [$wpdb->esc_like(strrev($qd)) . '%']);
        }

        $kovetkezo('ugyfel_szam LIKE %s', [$wpdb->esc_like($q) . '%']);
        $kovetkezo('email LIKE %s', [$wpdb->esc_like($q) . '%']);

        // 3. Szavak eleje (keresztnév, település, utca, telefon 06-tal / anélkül) – a szó-indexen.
        if (count($talalat) < $limit) {
            $idk = self::szo_kereses($qd !== '' ? $qd : $qn, $limit - count($talalat), array_keys($talalat));

            if ($idk !== []) {
                $kovetkezo('id IN (' . implode(',', $idk) . ') ORDER BY nev ASC', []);
            }
        }

        // 4. Tartalmazza – egyetlen oszlopon, és csak ha semmi nem került elő: ez az egyetlen
        //    lépés, amely az egész táblát végigolvassa (pl. szó közepe, elgépelés).
        if ($talalat === []) {
            $kovetkezo('kereso LIKE %s ORDER BY kereso ASC', ['%' . $wpdb->esc_like($qn) . '%']);

            if ($qd !== '' && strlen($qd) >= 3) {
                $kovetkezo('kereso LIKE %s ORDER BY kereso ASC', ['%' . $wpdb->esc_like($qd) . '%']);
            }
        }

        return array_values($talalat);
    }

    /**
     * Ügyfelek, akiknek MINDEN keresett szóra van ezzel kezdődő keresőszava
     * (a szó-indexen). A leghosszabb szó vezet (az a legszűkebb).
     *
     * @param array<int, int> $kiveve Ezek már megvannak.
     * @return array<int, int>
     */
    private static function szo_kereses(string $q, int $db, array $kiveve): array
    {
        global $wpdb;

        $szavak = preg_split('/[^a-z0-9]+/', self::kereso_norm($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $szavak = array_values(array_unique(array_filter($szavak, static fn (string $s): bool => strlen($s) >= 2)));

        if ($szavak === [] || $db <= 0) {
            return [];
        }

        usort($szavak, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $szavak = array_slice($szavak, 0, 4);

        $t      = self::szo_tabla();
        $ut     = self::tabla();
        $join   = '';
        $ertek  = [];

        foreach (array_slice($szavak, 1) as $i => $szo) {
            $join   .= " INNER JOIN {$t} s{$i} ON s{$i}.ugyfel_id = s.ugyfel_id AND s{$i}.szo LIKE %s";
            $ertek[] = $wpdb->esc_like(substr($szo, 0, self::SZO_HOSSZ)) . '%';
        }

        $ertek[] = $wpdb->esc_like(substr($szavak[0], 0, self::SZO_HOSSZ)) . '%';
        $kiz     = $kiveve !== [] ? ' AND s.ugyfel_id NOT IN (' . implode(',', array_map('intval', $kiveve)) . ')' : '';

        // Ugyanaz az ügyfél több szavával is illeszkedhet: bőven kérünk, és itt szűrjük egyedire.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- helyőrzők és egész számok.
        $idk = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT s.ugyfel_id FROM {$t} s{$join} INNER JOIN {$ut} u ON u.id = s.ugyfel_id AND u.aktiv = 1
             WHERE s.szo LIKE %s{$kiz} LIMIT " . ($db * 4),
            $ertek
        )));
        // phpcs:enable

        return array_slice(array_values(array_unique($idk)), 0, $db);
    }

    /**
     * A régi út (a feltöltés végéig): „tartalmazza" hat oszlopon.
     *
     * @return array<int, object>
     */
    private static function kereso_regi(string $q): array
    {
        global $wpdb;

        $minta = '%' . $wpdb->esc_like($q) . '%';
        $tabla = self::tabla();

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, nev, telefon, szamlazasi_iranyitoszam, szamlazasi_telepules, szamlazasi_cim, ugyfel_szam
                 FROM {$tabla}
                 WHERE aktiv = 1
                   AND (nev LIKE %s OR telefon LIKE %s OR telefon2 LIKE %s
                        OR email LIKE %s OR ugyfel_szam LIKE %s OR szamlazasi_cim LIKE %s)
                 ORDER BY nev ASC
                 LIMIT 10",
                $minta,
                $minta,
                $minta,
                $minta,
                $minta,
                $minta
            )
        );
    }

    private static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('ugyfel');
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    private static function url(array $parameterek = []): string
    {
        return SDH_Muhely_Modulok::url(self::KULCS, $parameterek);
    }

    private static function egy(int $id): ?object
    {
        global $wpdb;

        $sor = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id)
        );

        return $sor ?: null;
    }

    /**
     * Egy ügyfél neve az azonosítója alapján, vagy üres sztring, ha
     * nincs ilyen. Más modulok ezzel ellenőrzik, hogy a kapott
     * azonosító valódi ügyfélre mutat-e.
     */
    public static function nev(int $id): string
    {
        global $wpdb;

        if ($id <= 0) {
            return '';
        }

        $nev = $wpdb->get_var(
            $wpdb->prepare('SELECT nev FROM ' . self::tabla() . ' WHERE id = %d', $id)
        );

        return is_string($nev) ? $nev : '';
    }

    /* =================================================================
     * Útválasztás
     * ============================================================== */

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        $nezet = isset($_GET['nezet']) ? sanitize_key(wp_unslash($_GET['nezet'])) : 'lista';

        if ($nezet === 'uj' || $nezet === 'szerkeszt') {
            self::urlap_oldal($nezet);

            return;
        }

        self::lista_oldal();
    }

    /* =================================================================
     * Lista
     * ============================================================== */

    private static function lista_oldal(): void
    {
        global $wpdb;

        $kereses = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
        $inaktiv = !empty($_GET['inaktiv']);
        $oldal   = isset($_GET['oldalszam']) ? max(1, (int) $_GET['oldalszam']) : 1;
        $eltolas = ($oldal - 1) * self::OLDAL_MERET;

        $tabla = self::tabla();

        // A feltételeket külön építjük, hogy a számláló és a lekérdezés
        // ugyanazt lássa.
        $alap_felt = ['aktiv = %d'];
        $alap_ert  = [$inaktiv ? 0 : 1];
        $feltetelek = $alap_felt;
        $ertekek    = $alap_ert;

        if ($kereses !== '' && self::kereso_keszen()) {
            // Gyors út: egyetlen indexelt keresőoszlop (név, cím, e-mail, ügyfélszám, telefon számjegyei).
            $qd     = preg_match('/^[\d\s+\-()\/.]+$/', $kereses) === 1 ? self::szamjegyek($kereses) : '';
            $minta  = '%' . $wpdb->esc_like(self::kereso_norm($kereses)) . '%';

            if ($qd !== '' && strlen($qd) >= 3) {
                $feltetelek[] = '(kereso LIKE %s OR kereso LIKE %s)';
                array_push($ertekek, $minta, '%' . $wpdb->esc_like($qd) . '%');
            } else {
                $feltetelek[] = 'kereso LIKE %s';
                $ertekek[]    = $minta;
            }
        } elseif ($kereses !== '') {
            $minta = '%' . $wpdb->esc_like($kereses) . '%';

            $feltetelek[] = '(nev LIKE %s OR telefon LIKE %s OR telefon2 LIKE %s OR email LIKE %s '
                . 'OR ugyfel_szam LIKE %s OR szamlazasi_telepules LIKE %s OR szamlazasi_cim LIKE %s '
                . 'OR szamlazasi_iranyitoszam LIKE %s)';

            array_push($ertekek, $minta, $minta, $minta, $minta, $minta, $minta, $minta, $minta);
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- a $hol csak helyőrzőket tartalmaz.
        // 0.99.4: első körben specifikusan (telefon, e-mail, ügyfélszám) – ha így nincs találat, a teljes keresés.
        $szukites    = $kereses !== '' && class_exists('SDH_Muhely_Okoskereso') ? SDH_Muhely_Okoskereso::szukites('ugyfel', $kereses) : null;
        $visszaesett = false;

        if ($szukites !== null) {
            $sz_felt  = array_merge($alap_felt, [$szukites['sql']]);
            $sz_ert   = array_merge($alap_ert, $szukites['ertekek']);
            $sz_db    = SDH_Muhely_Gyorsitotar::szam($wpdb->prepare("SELECT COUNT(*) FROM {$tabla} WHERE " . implode(' AND ', $sz_felt), $sz_ert));

            if ($sz_db > 0 || !empty($szukites['veg'])) {
                $feltetelek = $sz_felt;
                $ertekek    = $sz_ert;
            } else {
                $visszaesett = true;
            }
        }

        $hol = 'WHERE ' . implode(' AND ', $feltetelek);

        // 0.64: a találatszám tárolva (lapozáskor és ugyanarra a keresésre nem számolódik újra).
        $osszesen = SDH_Muhely_Gyorsitotar::szam($wpdb->prepare("SELECT COUNT(*) FROM {$tabla} {$hol}", $ertekek));
        $oldalak  = max(1, (int) ceil($osszesen / self::OLDAL_MERET));

        // Az utolsó oldalon túl (régi link, kézzel írt oldalszám): az utolsó oldal.
        if ($oldal > $oldalak) {
            $oldal   = $oldalak;
            $eltolas = ($oldal - 1) * self::OLDAL_MERET;
        }

        // 0.99.4: halasztott összekapcsolás – előbb az oldal azonosítói a keskeny (aktiv, nev) indexről,
        // aztán csak ennek az oldalnak a teljes sorai. A 11 801. oldal így sem olvas végig 295 000 teljes sort.
        $idk = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$tabla} {$hol} ORDER BY nev ASC, id ASC LIMIT %d OFFSET %d",
            array_merge($ertekek, [self::OLDAL_MERET, $eltolas])
        )));

        $sorok = [];

        if ($idk !== []) {
            $szerint = [];

            foreach ((array) $wpdb->get_results("SELECT * FROM {$tabla} WHERE id IN (" . implode(',', $idk) . ')') as $sor) {
                $szerint[(int) $sor->id] = $sor;
            }

            foreach ($idk as $id) {
                if (isset($szerint[$id])) {
                    $sorok[] = $szerint[$id];
                }
            }
        }
        // phpcs:enable


        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Ügyfelek',
                'Magánszemélyek és cégek egy helyen. A munkalap mindig egy ügyfélre hivatkozik.',
                [
                    [
                        'cimke'      => '+ Új ügyfél',
                        'url'        => self::url(['nezet' => 'uj']),
                        'elsodleges' => true,
                        'adatok'     => ['sdh-urlap' => self::KULCS, 'sdh-id' => '0'],
                    ],
                ]
            );
            ?>

            <form method="get" class="sdh-kereso"
                  action="<?php echo esc_url(SDH_Muhely_Modulok::urlap_cel(self::KULCS)); ?>">
                <?php SDH_Muhely_Modulok::urlap_rejtett(self::KULCS); ?>

                <?php if ($inaktiv) : ?>
                    <input type="hidden" name="inaktiv" value="1">
                <?php endif; ?>

                <input type="search"
                       name="k"
                       value="<?php echo esc_attr($kereses); ?>"
                       placeholder="Név, telefon, e-mail, ügyfélszám, cím…">

                <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Keresés</button>

                <?php if ($kereses !== '') : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos"
                       href="<?php echo esc_url(self::url($inaktiv ? ['inaktiv' => 1] : [])); ?>">
                        Szűrő törlése
                    </a>
                <?php endif; ?>

                <a class="sdh-gomb sdh-gomb--vilagos"
                   href="<?php echo esc_url(self::url($inaktiv ? [] : ['inaktiv' => 1])); ?>">
                    <?php echo $inaktiv ? 'Aktív ügyfelek' : 'Inaktívak'; ?>
                </a>

                <span class="sdh-kereso__talalat">
                    <?php echo esc_html(number_format_i18n($osszesen)); ?> találat
                </span>
            </form>

            <?php echo class_exists('SDH_Muhely_Okoskereso') ? SDH_Muhely_Okoskereso::jelzes($szukites, $visszaesett, self::KULCS, array_filter(['k' => $kereses, 'inaktiv' => $inaktiv ? 1 : null])) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- a jelzes() escapel. ?>

            <table class="sdh-tabla">
                <thead>
                    <tr>
                        <th>Név</th>
                        <th>Típus</th>
                        <th>Telefon</th>
                        <th class="sdh-tabla__rejtheto">E-mail</th>
                        <th>Cím</th>
                        <th>Ügyfélszám</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($sorok === []) : ?>
                    <tr>
                        <td colspan="7" class="sdh-tabla__ures">
                            <?php if ($kereses !== '') : ?>
                                Erre a keresésre nincs találat.
                            <?php else : ?>
                                Még nincs <?php echo $inaktiv ? 'inaktív' : ''; ?> ügyfél.
                                <a href="<?php echo esc_url(self::url(['nezet' => 'uj'])); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="0">Vidd fel az elsőt.</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($sorok as $sor) : ?>
                        <?php $szerkeszt_url = self::url(['nezet' => 'szerkeszt', 'id' => (int) $sor->id]); ?>
                        <tr>
                            <td class="sdh-tabla__nev">
                                <a href="<?php echo esc_url($szerkeszt_url); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="<?php echo (int) $sor->id; ?>">
                                    <?php echo esc_html($sor->nev); ?>
                                </a>
                                <?php if ((int) $sor->aktiv === 0) : ?>
                                    <span class="sdh-cimke sdh-cimke--inaktiv">inaktív</span>
                                <?php endif; ?>
                                <?php if ($sor->kapcsolattarto !== '') : ?>
                                    <div class="sdh-tabla__halvany">
                                        <?php echo esc_html($sor->kapcsolattarto); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="sdh-cimke">
                                    <?php echo esc_html(self::tipus_cimke($sor->tipus)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($sor->telefon); ?></td>
                            <td class="sdh-tabla__rejtheto"><?php echo esc_html($sor->email); ?></td>
                            <td class="sdh-tabla__cim"><?php echo esc_html(self::cim_szoveg($sor)); ?></td>
                            <td class="sdh-tabla__halvany"><?php echo esc_html($sor->ugyfel_szam); ?></td>
                            <td>
                                <a class="sdh-gomb sdh-gomb--vilagos"
                                   href="<?php echo esc_url($szerkeszt_url); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="<?php echo (int) $sor->id; ?>">
                                    Megnyit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($oldalak > 1) : ?>
                <div class="sdh-lapozo">
                    <?php foreach (SDH_Muhely_Admin_UI::lapozo_oldalak($oldal, $oldalak) as $i) : ?>
                        <?php if ($i === 0) : ?><span class="sdh-lapozo__kihagy" aria-hidden="true">…</span><?php continue; endif; ?>
                        <?php if ($i === $oldal) : ?>
                            <span class="sdh-lapozo__aktiv"><?php echo (int) $i; ?></span>
                        <?php else : ?>
                            <?php
                            $lap_url = self::url(
                                array_filter(
                                    [
                                        'oldalszam' => $i,
                                        'k'         => $kereses !== '' ? $kereses : null,
                                        'inaktiv'   => $inaktiv ? 1 : null,
                                        'mind'      => !empty($_GET['mind']) ? 1 : null, // phpcs:ignore WordPress.Security.NonceVerification
                                    ],
                                    static fn ($ertek): bool => $ertek !== null
                                )
                            );
                            ?>
                            <a href="<?php echo esc_url($lap_url); ?>"><?php echo (int) $i; ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =================================================================
     * Űrlap – teljes oldalas változat (tartalék)
     * ============================================================== */

    private static function urlap_oldal(string $nezet): void
    {
        $ugyfel = null;

        if ($nezet === 'szerkeszt') {
            $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            $ugyfel = self::egy($id);

            if ($ugyfel === null) {
                wp_safe_redirect(self::url(['uzenet' => 'nincs_ilyen']));
                exit;
            }
        }

        $uj = $ugyfel === null;

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                $uj ? 'Új ügyfél' : $ugyfel->nev,
                $uj
                    ? 'Csak a név kötelező. A többi mezőt bármikor pótolhatod.'
                    : 'Ügyfélszám: ' . ($ugyfel->ugyfel_szam !== '' ? $ugyfel->ugyfel_szam : '—'),
                [
                    [
                        'cimke' => '← Vissza a listához',
                        'url'   => self::url(),
                    ],
                ]
            );
            ?>

            <form class="sdh-urlap" method="post" enctype="multipart/form-data"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_ugyfel_mentes">
                <?php self::urlap_belso($ugyfel, false); ?>
            </form>
        </div>
        <?php
    }

    /* =================================================================
     * Űrlap – popup változat
     * ============================================================== */

    public static function ajax_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }

        $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $ugyfel = $id > 0 ? self::egy($id) : null;

        if ($id > 0 && $ugyfel === null) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs ilyen ügyfél.</div>';
            wp_die();
        }

        $uj = $ugyfel === null;

        ?>
        <h2 class="sdh-modal__cim"><?php echo esc_html($uj ? 'Új ügyfél' : $ugyfel->nev); ?></h2>
        <p class="sdh-modal__alcim">
            <?php
            echo esc_html(
                $uj
                    ? 'Csak a név kötelező. A többi mezőt bármikor pótolhatod.'
                    : 'Ügyfélszám: ' . ($ugyfel->ugyfel_szam !== '' ? $ugyfel->ugyfel_szam : '—')
            );
            ?>
        </p>

        <form class="sdh-urlap" method="post" enctype="multipart/form-data"
              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-sdh-ajax-action="sdh_muhely_ugyfelek_ment">
            <input type="hidden" name="action" value="sdh_muhely_ugyfel_mentes">
            <?php self::urlap_belso($ugyfel, true); ?>
        </form>
        <?php

        wp_die();
    }

    /**
     * Az űrlap belseje: rejtett mezők, dobozok, lábléc.
     *
     * Ugyanez megy a teljes oldalas és a popupos változatba – egy helyen
     * írjuk meg, hogy ne csússzon szét a kettő.
     */
    private static function urlap_belso(?object $ugyfel, bool $modal): void
    {
        // Minden mezőnek van alapértéke, hogy az űrlap új és meglévő
        // ügyfélnél ugyanazt a kódot használhassa.
        $ert = static fn (string $mezo, string $alap = ''): string => $ugyfel !== null
            ? (string) ($ugyfel->{$mezo} ?? $alap)
            : $alap;

        $uj = $ugyfel === null;

        ?>
        <input type="hidden" name="id" value="<?php echo (int) ($ugyfel->id ?? 0); ?>">
        <?php SDH_Muhely_Utkozes::mezok('ugyfel', (int) ($ugyfel->id ?? 0)); ?>
        <input type="hidden" name="kontextus"
               value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
        <?php wp_nonce_field('sdh_muhely_ugyfel_mentes', 'sdh_nonce'); ?>

        <?php echo class_exists('SDH_Muhely_Anonim') ? SDH_Muhely_Anonim::sav($ugyfel) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- szűrt HTML (H8). ?>
        <div class="sdh-ugyfelurlap">
            <?php
            // Kompakt, lapfüles elrendezés a MunkaLap 3 ügyfélszerkesztője nyomán.
            // A lapfülek tisztán CSS-ből működnek (rádiógombok), ezért JS nélkül
            // is használhatók. A „Központi cím” a számlázási cím mezőit tárolja.
            ?>
            <div class="sdh-fulek">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--1" name="_ful_cim"
                       id="ful_kozponti" checked>
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--2" name="_ful_cim"
                       id="ful_levelezesi">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--3" name="_ful_cim"
                       id="ful_szallitasi">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--4" name="_ful_cim"
                       id="ful_telephely">

                <div class="sdh-fulek__sav">
                    <label for="ful_kozponti">Központi cím</label>
                    <label for="ful_levelezesi">Levelezési cím</label>
                    <label for="ful_szallitasi">Szállítási cím</label>
                    <label for="ful_telephely">Telephely</label>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--1">
                    <div class="sdh-sor">
                        <input type="text" name="nev" id="nev" required aria-label="Név"
                               placeholder="Név * (cégnél a cég neve, magánszemélynél a teljes név)"
                               value="<?php echo esc_attr($ert('nev')); ?>">
                    </div>

                    <div class="sdh-sor sdh-sor--cim" data-sdh-cimsor>
                        <input type="text" name="szamlazasi_iranyitoszam" id="szamlazasi_iranyitoszam" data-sdh-isz
                               inputmode="numeric" maxlength="10" autocomplete="off" aria-label="Irányítószám" placeholder="Isz."
                               value="<?php echo esc_attr($ert('szamlazasi_iranyitoszam')); ?>">
                        <input type="text" name="szamlazasi_telepules" id="szamlazasi_telepules" data-sdh-telepules
                               autocomplete="off" aria-label="Település" placeholder="Település"
                               value="<?php echo esc_attr($ert('szamlazasi_telepules')); ?>">
                        <input type="text" name="szamlazasi_cim" id="szamlazasi_cim"
                               aria-label="Utca, házszám" placeholder="Utca, házszám"
                               value="<?php echo esc_attr($ert('szamlazasi_cim')); ?>">
                        <input type="text" name="szamlazasi_orszag" id="szamlazasi_orszag"
                               aria-label="Ország" placeholder="Ország"
                               value="<?php echo esc_attr($ert('szamlazasi_orszag', 'Magyarország')); ?>">
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="kategoria">Kategória</label>
                            <input type="text" name="kategoria" id="kategoria"
                                   placeholder="Pl. viszonteladó, szerződéses partner"
                                   value="<?php echo esc_attr($ert('kategoria')); ?>">
                        </div>

                        <div class="sdh-ig">
                            <label for="ugyfel_szam">Ügyfélszám</label>
                            <input type="text" name="ugyfel_szam" id="ugyfel_szam"
                                   placeholder="Mentéskor generálódik"
                                   value="<?php echo esc_attr($ert('ugyfel_szam')); ?>">
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="adoszam">Adószám</label>
                            <input type="text" name="adoszam" id="adoszam"
                                   value="<?php echo esc_attr($ert('adoszam')); ?>">
                        </div>

                        <div class="sdh-ig">
                            <label for="kedvezmeny">Kedvezmény</label>
                            <span class="sdh-szam sdh-szam--utotag">
                                <input type="number" name="kedvezmeny" id="kedvezmeny"
                                       step="0.01" min="0" max="100"
                                       value="<?php echo esc_attr($ert('kedvezmeny', '0')); ?>">
                                <span class="sdh-szam__utotag" aria-hidden="true">%</span>
                            </span>
                        </div>
                    </div>

                    <div class="sdh-sor">
                        <div class="sdh-ig">
                            <span class="sdh-ig__cimke">Státusz</span>
                            <div class="sdh-szegmens" role="radiogroup" aria-label="Státusz">
                                <?php foreach (self::tipusok() as $kulcs => $cimke) : ?>
                                    <label class="sdh-szegmens__elem">
                                        <input type="radio" name="tipus"
                                               value="<?php echo esc_attr($kulcs); ?>"
                                            <?php checked($ert('tipus', 'maganszemely'), $kulcs); ?>>
                                        <span><?php echo esc_html($cimke); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="telefon">Telefon</label>
                            <input type="tel" name="telefon" id="telefon"
                                   value="<?php echo esc_attr($ert('telefon')); ?>">
                        </div>

                        <div class="sdh-ig">
                            <label for="telefon2">Telefon 2.</label>
                            <input type="tel" name="telefon2" id="telefon2"
                                   value="<?php echo esc_attr($ert('telefon2')); ?>">
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="email">E-mail</label>
                            <input type="email" name="email" id="email"
                                   value="<?php echo esc_attr($ert('email')); ?>">
                        </div>

                        <div class="sdh-ig">
                            <label for="kapcsolattarto">Kapcsolattartó</label>
                            <input type="text" name="kapcsolattarto" id="kapcsolattarto"
                                   value="<?php echo esc_attr($ert('kapcsolattarto')); ?>">
                        </div>
                    </div>
                </div>

                <?php
                self::cim_panel(2, 'levelezesi', $ert, $uj);
                self::cim_panel(3, 'szallitasi', $ert, $uj);
                self::cim_panel(4, 'telephely', $ert, $uj, true);
                ?>
            </div>

            <div class="sdh-fulek sdh-fulek--also">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--1" name="_ful_also"
                       id="ful_megjegyzes" checked>

                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--2" name="_ful_also"
                       id="ful_csatolt">

                <?php
                // Az ügyfél munkalapjai, rendelései és vásárlásai – meglévő ügyfélnél, lapfülenként.
                $ugyfel_lapok = !$uj && class_exists('SDH_Muhely_Eladas') ? SDH_Muhely_Eladas::ugyfel_lapok((int) $ugyfel->id) : [];
                $lap_fulek    = ['javitas' => 'Munkalapok', 'rendeles' => 'Rendelések', 'arukiadas' => 'Vásárlások'];
                $lap_n        = 2;
                ?>
                <?php foreach ($ugyfel_lapok as $lap_fajta => $lap_sorok) : ?>
                    <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--<?php echo (int) ++$lap_n; ?>" name="_ful_also"
                           id="ful_lapok_<?php echo esc_attr($lap_fajta); ?>">
                <?php endforeach; ?>

                <div class="sdh-fulek__sav">
                    <label for="ful_megjegyzes">Megjegyzés</label>
                    <label for="ful_csatolt">Csatolt fájlok<?php
                        $csat_db = $uj ? 0 : SDH_Muhely_Csatolmany::darab('ugyfel', (int) $ugyfel->id);
                        echo $csat_db > 0 ? ' <span class="sdh-fulek__db">' . (int) $csat_db . '</span>' : '';
                    ?></label>
                    <?php foreach ($ugyfel_lapok as $lap_fajta => $lap_sorok) : ?>
                        <label for="ful_lapok_<?php echo esc_attr($lap_fajta); ?>"><?php
                            echo esc_html($lap_fulek[$lap_fajta]);
                            echo $lap_sorok !== [] ? ' <span class="sdh-fulek__db">' . (int) count($lap_sorok) . '</span>' : '';
                        ?></label>
                    <?php endforeach; ?>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--1">
                    <textarea name="megjegyzes" id="megjegyzes" aria-label="Megjegyzés"><?php
                        echo esc_textarea($ert('megjegyzes'));
                    ?></textarea>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--2">
                    <?php SDH_Muhely_Csatolmany::panel('ugyfel', $uj ? 0 : (int) $ugyfel->id); ?>
                </div>

                <?php $lap_n = 2; ?>
                <?php foreach ($ugyfel_lapok as $lap_fajta => $lap_sorok) : ?>
                    <div class="sdh-fulek__panel sdh-fulek__panel--<?php echo (int) ++$lap_n; ?> sdh-fulek__panel--lapok">
                        <?php SDH_Muhely_Eladas::ugyfel_ful($lap_sorok, (string) $lap_fajta); ?>
                    </div>
                <?php endforeach; ?>
            </div>

        <div class="sdh-urlap__lablec">
            <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">
                <?php echo $uj ? 'Ügyfél létrehozása' : 'Mentés'; ?>
            </button>

            <?php if ($modal) : ?>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            <?php else : ?>
                <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(self::url()); ?>">Mégsem</a>
            <?php endif; ?>

            <?php if (!$uj) : ?>
                <a class="sdh-gomb sdh-gomb--vilagos"
                   href="<?php echo esc_url(
                       SDH_Muhely_Modulok::url('eszkozok', ['ugyfel_id' => (int) $ugyfel->id])
                   ); ?>">
                    Készülékei
                </a>

                <a class="sdh-gomb sdh-gomb--vilagos"
                   href="<?php echo esc_url(
                       SDH_Muhely_Modulok::url('munkalapok', ['ugyfel_id' => (int) $ugyfel->id, 'allapot' => 'mind'])
                   ); ?>">
                    Munkalapjai
                </a>

                <?php
                $allapot_url = wp_nonce_url(
                    add_query_arg(
                        [
                            'action'    => 'sdh_muhely_ugyfel_allapot',
                            'id'        => (int) $ugyfel->id,
                            'ertek'     => (int) $ugyfel->aktiv === 1 ? 0 : 1,
                            'kontextus' => self::kontextus_ertek(),
                        ],
                        admin_url('admin-post.php')
                    ),
                    'sdh_muhely_ugyfel_allapot_' . (int) $ugyfel->id
                );
                ?>
                <?php echo class_exists('SDH_Muhely_Anonim') ? SDH_Muhely_Anonim::gomb($ugyfel) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- szűrt HTML (H8). ?>
                <a class="sdh-gomb sdh-gomb--vilagos sdh-gomb--jobbra"
                   href="<?php echo esc_url($allapot_url); ?>">
                    <?php echo (int) $ugyfel->aktiv === 1 ? 'Inaktívra állít' : 'Újra aktív'; ?>
                </a>

                <?php if (class_exists('SDH_Muhely_Urlap_Email')) : ?>
                    <?php echo SDH_Muhely_Urlap_Email::gomb('ugyfel', (int) $ugyfel->id); // phpcs:ignore WordPress.Security.EscapeOutput -- a gomb() escapel. ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        </div>
        <?php
    }

    /**
     * Egy „másodlagos” cím lapfüle (levelezési, szállítási, telephely).
     *
     * Mindegyik alapból megegyezik a központi címmel; ilyenkor a mezők
     * halványak, és mentéskor üresen tárolódnak (nincs két külön igazság).
     *
     * @param callable(string, string=): string $ert
     */
    private static function cim_panel(int $sorszam, string $elotag, callable $ert, bool $uj, bool $nevmezo = false): void
    {
        $mezo = static fn (string $nev): string => $elotag . '_' . $nev;

        ?>
                <div class="sdh-fulek__panel sdh-fulek__panel--<?php echo (int) $sorszam; ?>">
                    <label class="sdh-jelolo" for="<?php echo esc_attr($mezo('azonos')); ?>">
                        <input type="checkbox" name="<?php echo esc_attr($mezo('azonos')); ?>"
                               id="<?php echo esc_attr($mezo('azonos')); ?>" value="1"
                            <?php checked($uj ? '1' : $ert($mezo('azonos'), '1'), '1'); ?>>
                        Megegyezik a központi címmel
                    </label>

                    <?php if ($nevmezo) : ?>
                        <div class="sdh-sor sdh-cimsor">
                            <input type="text" name="<?php echo esc_attr($mezo('nev')); ?>"
                                   id="<?php echo esc_attr($mezo('nev')); ?>"
                                   aria-label="Telephely neve" placeholder="Telephely neve (nem kötelező)"
                                   value="<?php echo esc_attr($ert($mezo('nev'))); ?>">
                        </div>
                    <?php endif; ?>

                    <div class="sdh-sor sdh-sor--cim3 sdh-cimsor" data-sdh-cimsor>
                        <input type="text" name="<?php echo esc_attr($mezo('iranyitoszam')); ?>"
                               id="<?php echo esc_attr($mezo('iranyitoszam')); ?>" data-sdh-isz
                               inputmode="numeric" maxlength="10" autocomplete="off" aria-label="Irányítószám" placeholder="Isz."
                               value="<?php echo esc_attr($ert($mezo('iranyitoszam'))); ?>">
                        <input type="text" name="<?php echo esc_attr($mezo('telepules')); ?>"
                               id="<?php echo esc_attr($mezo('telepules')); ?>" data-sdh-telepules
                               autocomplete="off" aria-label="Település" placeholder="Település"
                               value="<?php echo esc_attr($ert($mezo('telepules'))); ?>">
                        <input type="text" name="<?php echo esc_attr($mezo('cim')); ?>"
                               id="<?php echo esc_attr($mezo('cim')); ?>"
                               aria-label="Utca, házszám" placeholder="Utca, házszám"
                               value="<?php echo esc_attr($ert($mezo('cim'))); ?>">
                    </div>
                </div>
        <?php
    }

    /**
     * Melyik felületen készül az űrlap.
     *
     * AJAX-hívásnál a kontextus nem állapítható meg magától – a
     * JavaScript küldi el, mert ő tudja, honnan nyitották a popupot.
     */
    private static function kontextus_ertek(): string
    {
        if (wp_doing_ajax() && isset($_REQUEST['kontextus'])) {
            return sanitize_key(wp_unslash($_REQUEST['kontextus'])) === 'frontend' ? 'frontend' : 'admin';
        }

        return SDH_Muhely_Modulok::kontextus();
    }

    /* =================================================================
     * Mentés
     * ============================================================== */

    /**
     * Honnan jött a beküldés – oda is megy vissza.
     */
    private static function bekuldes_kontextusa(): string
    {
        $kontextus = isset($_REQUEST['kontextus'])
            ? sanitize_key(wp_unslash($_REQUEST['kontextus']))
            : 'admin';

        return $kontextus === 'frontend' ? 'frontend' : 'admin';
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    private static function vissza(array $parameterek): string
    {
        return SDH_Muhely_Modulok::visszateres(self::KULCS, $parameterek, self::bekuldes_kontextusa());
    }

    /**
     * A beküldött mezőkből adatbázisra kész tömb.
     *
     * @return array<string, mixed>
     */
    private static function adatok_osszeallit(): array
    {
        $szoveg = static fn (string $mezo): string => isset($_POST[$mezo])
            ? sanitize_text_field(wp_unslash($_POST[$mezo]))
            : '';

        $adatok = [
            'tipus'                   => self::tipus_ervenyes($szoveg('tipus')),
            'nev'                     => $szoveg('nev'),
            'kapcsolattarto'          => $szoveg('kapcsolattarto'),
            'adoszam'                 => $szoveg('adoszam'),
            'telefon'                 => $szoveg('telefon'),
            'telefon2'                => $szoveg('telefon2'),
            'email'                   => isset($_POST['email'])
                ? sanitize_email(wp_unslash($_POST['email']))
                : '',
            'szamlazasi_iranyitoszam' => $szoveg('szamlazasi_iranyitoszam'),
            'szamlazasi_telepules'    => $szoveg('szamlazasi_telepules'),
            'szamlazasi_cim'          => $szoveg('szamlazasi_cim'),
            'szamlazasi_orszag'       => $szoveg('szamlazasi_orszag'),
            'kategoria'               => $szoveg('kategoria'),
            'kedvezmeny'              => isset($_POST['kedvezmeny'])
                ? min(100, max(0, (float) wp_unslash($_POST['kedvezmeny'])))
                : 0,
            'megjegyzes'              => isset($_POST['megjegyzes'])
                ? sanitize_textarea_field(wp_unslash($_POST['megjegyzes']))
                : '',
            'ugyfel_szam'             => $szoveg('ugyfel_szam'),
            'modositva'               => current_time('mysql'),
        ];

        // Ha egy cím megegyezik a központival, nem tárolunk két külön
        // igazságot – a másolat idővel szétcsúszna.
        foreach (self::MASODLAGOS_CIMEK as $elotag => $mezok) {
            $azonos = !empty($_POST[$elotag . '_azonos']) ? 1 : 0;

            $adatok[$elotag . '_azonos'] = $azonos;

            foreach ($mezok as $mezo) {
                $adatok[$elotag . '_' . $mezo] = $azonos === 1 ? '' : $szoveg($elotag . '_' . $mezo);
            }
        }

        return $adatok;
    }

    /**
     * Beírja az adatbázisba. Visszatérés: [azonosító, üzenetkulcs] vagy null hibánál.
     *
     * @param array<string, mixed> $adatok
     * @return array{0: int, 1: string}|null
     */
    private static function adatbazisba(int $id, array $adatok): ?array
    {
        global $wpdb;

        if ($id > 0) {
            $eredmeny = $wpdb->update(self::tabla(), $adatok, ['id' => $id]);
            $uzenet   = 'mentve';
        } else {
            $adatok['letrehozva'] = current_time('mysql');
            $adatok['letrehozo']  = (class_exists('SDH_Muhely_Szemely') ? SDH_Muhely_Szemely::ki() : get_current_user_id());
            $adatok['aktiv']      = 1;
            $adatok['forras']     = 'kezi';

            $eredmeny = $wpdb->insert(self::tabla(), $adatok);
            $id       = (int) $wpdb->insert_id;
            $uzenet   = 'letrehozva';
        }

        if ($eredmeny === false) {
            return null;
        }

        // Ügyfélszám pótlása, ha a felhasználó nem adott meg sajátot.
        if ($id > 0 && ($adatok['ugyfel_szam'] ?? '') === '') {
            $wpdb->update(
                self::tabla(),
                ['ugyfel_szam' => sprintf('U-%06d', $id)],
                ['id' => $id]
            );
        }

        self::kereso_frissit($id);

        return [$id, $uzenet];
    }

    /** Teljes oldalas beküldés (JS nélküli tartalék). */
    public static function mentes(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_ugyfel_mentes', 'sdh_nonce');

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adatok = self::adatok_osszeallit();

        if ($adatok['nev'] === '') {
            wp_safe_redirect(
                self::vissza(
                    array_filter([
                        'nezet'  => $id > 0 ? 'szerkeszt' : 'uj',
                        'id'     => $id > 0 ? $id : null,
                        'uzenet' => 'hianyzo_nev',
                    ])
                )
            );
            exit;
        }

        $csat_hiba = SDH_Muhely_Csatolmany::ellenoriz();

        if ($csat_hiba !== null) {
            wp_safe_redirect(
                self::vissza(
                    array_filter([
                        'nezet'  => $id > 0 ? 'szerkeszt' : 'uj',
                        'id'     => $id > 0 ? $id : null,
                        'uzenet' => 'csatolmany_hiba',
                    ])
                )
            );
            exit;
        }

        $eredmeny = self::adatbazisba($id, $adatok);

        if ($eredmeny === null) {
            wp_safe_redirect(self::vissza(['uzenet' => 'mentes_hiba']));
            exit;
        }

        [$uj_id, $uzenet] = $eredmeny;

        SDH_Muhely_Csatolmany::feldolgoz('ugyfel', $uj_id);

        wp_safe_redirect(
            self::vissza(['nezet' => 'szerkeszt', 'id' => $uj_id, 'uzenet' => $uzenet])
        );
        exit;
    }

    /** Popupos beküldés. */
    public static function ajax_mentes(): void
    {
        check_ajax_referer('sdh_muhely_ugyfel_mentes', 'sdh_nonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        // Párhuzamos szerkesztés (0.70.0): ha közben más módosította az ügyfelet, a mentés nem fut le.
        $utkozes = SDH_Muhely_Utkozes::ellenoriz('ugyfel', $id);

        if ($utkozes !== null) {
            wp_send_json_error($utkozes, 409);
        }

        $szerk_naplo = SDH_Muhely_Utkozes::alap();
        $adatok      = self::adatok_osszeallit();

        if ($adatok['nev'] === '') {
            wp_send_json_error(['uzenet' => 'A név kitöltése kötelező.']);
        }

        // Hibás fájl esetén semmi sem mentődik félig.
        $csat_hiba = SDH_Muhely_Csatolmany::ellenoriz();

        if ($csat_hiba !== null) {
            wp_send_json_error(['uzenet' => $csat_hiba]);
        }

        $eredmeny = self::adatbazisba($id, $adatok);

        if ($eredmeny === null) {
            wp_send_json_error(['uzenet' => 'Az adatbázis visszautasította a mentést.']);
        }

        [$uj_id, $uzenet] = $eredmeny;

        $csat = SDH_Muhely_Csatolmany::feldolgoz('ugyfel', $uj_id);

        if ($csat['hibak'] !== []) {
            // Az ügyfél már mentve van, ezért ezt külön, érthetően jelezzük.
            wp_send_json_error([
                'uzenet' => 'Az ügyfél mentve, de néhány fájl nem: ' . implode(' ', $csat['hibak']),
            ]);
        }

        // A lista oldalára térünk vissza, hogy a változás rögtön látszódjon.
        // A nevet a munkalap-űrlap „+” gombja használja: a frissen felvitt
        // ügyfelet oldalfrissítés nélkül tölti be az ügyfélmezőbe.
        wp_send_json_success([
            'id'     => $uj_id,
            'nev'    => (string) $adatok['nev'],
            'szerk_naplo' => $szerk_naplo,
            'vissza' => self::vissza(['uzenet' => $uzenet]),
        ]);
    }

    /* =================================================================
     * Aktív / inaktív
     * ============================================================== */

    public static function allapot_valtas(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        global $wpdb;

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        check_admin_referer('sdh_muhely_ugyfel_allapot_' . $id);

        $ertek = isset($_GET['ertek']) && (int) $_GET['ertek'] === 1 ? 1 : 0;

        $wpdb->update(
            self::tabla(),
            ['aktiv' => $ertek, 'modositva' => current_time('mysql')],
            ['id' => $id]
        );

        wp_safe_redirect(
            self::vissza([
                'uzenet' => $ertek === 1 ? 'aktivalva' : 'inaktivalva',
            ])
        );
        exit;
    }

    /* =================================================================
     * Típusok
     * ============================================================== */

    /**
     * @return array<string, string>
     */
    public static function tipusok(): array
    {
        return [
            'maganszemely' => 'Magánszemély',
            'ceg'          => 'Cég',
            'kozulet'      => 'Közület',
        ];
    }

    private static function tipus_ervenyes(string $tipus): string
    {
        return isset(self::tipusok()[$tipus]) ? $tipus : 'maganszemely';
    }

    public static function tipus_cimke(string $tipus): string
    {
        return self::tipusok()[$tipus] ?? $tipus;
    }
}
