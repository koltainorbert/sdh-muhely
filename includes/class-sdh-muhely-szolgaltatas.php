<?php
/**
 * Szolgáltatás-törzs: a rendszer által megjegyzett szolgáltatások.
 *
 * Amit a munkalap „Szolgáltatások" lapfülén egyszer beírtak, azt a rendszer
 * megjegyzi (név, mennyiségi egység, bruttó ár, áfakulcs). A törzsnek saját
 * modulja van az oldalsávban („Szolgáltatások"): lista, új felvitel és
 * szerkesztés popupban – ugyanúgy, mint az ügyfeleknél. A munkalapon a
 * szolgáltatást választó popupból lehet kiválasztani (app.js `szolgValaszto*`).
 *
 * EGY NÉV = EGY SOR. Az azonosságot a `kulcs` adja: a név kisbetűsítve, a
 * szóközök egységesítve („Kijelző  csere" = „kijelző csere"), md5-ben. A
 * `kulcs` egyedi index, ezért két egyforma nevű szolgáltatás soha nem jöhet
 * létre – a második használat a meglévő sort frissíti (utolsó ár, számláló).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Szolgaltatas
{
    /** A modul kulcsa az URL-ekben, a menüben és az AJAX-műveletekben. */
    public const KULCS = 'szolgaltatasok';

    /** Ennyi sort kap legfeljebb a böngésző (a leggyakrabban használtakat). */
    private const LISTA_HATAR = 3000;

    /** Hány sor egy oldalon a modul listájában. */
    private const OLDAL_MERET = 50;

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Szolgáltatások',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 35,
        ]);

        // Teljes oldalas űrlap beküldése (tartalék, JS nélkül is működik).
        add_action('admin_post_sdh_muhely_szolgaltatas_mentes', [self::class, 'mentes']);

        // Popup: az űrlap lekérése és beküldése.
        add_action('wp_ajax_sdh_muhely_szolgaltatasok_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_szolgaltatasok_ment', [self::class, 'ajax_mentes']);

        // A munkalap választó popupja ebből a listából dolgozik.
        add_action('wp_ajax_sdh_muhely_szolgaltatasok', [self::class, 'ajax_lista']);
        add_action('wp_ajax_sdh_muhely_szolgaltatas_felejt', [self::class, 'ajax_felejt']);

        // Árlista: beillesztés (popup + élő előnézet) és a soronkénti árszerkesztés.
        add_action('wp_ajax_sdh_muhely_szolgaltatasarlista_urlap', [self::class, 'ajax_arlista_urlap']);
        add_action('wp_ajax_sdh_muhely_szolgaltatas_arlista_elonezet', [self::class, 'ajax_arlista_elonezet']);
        add_action('wp_ajax_sdh_muhely_szolgaltatas_arlista_ment', [self::class, 'ajax_arlista_ment']);
        add_action('wp_ajax_sdh_muhely_szolgaltatas_arak', [self::class, 'ajax_arak']);
        add_action('admin_post_sdh_muhely_szolgaltatas_arak', [self::class, 'arak_mentes']);

        // Sémafrissítés után: az egyforma nevűek összevonása, és a meglévő
        // munkalap-tételekből a törzs feltöltése (csak ha még üres).
        add_action('sdh_muhely_sema_frissult', [self::class, 'rendbetetel']);
    }

    public static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('szolgaltatas');
    }

    /* =================================================================
     * Név és kulcs
     * ============================================================== */

    /** A név tárolt alakja: szélek levágva, a belső szóközök egy szóközre. */
    public static function nev_tisztit(string $nev): string
    {
        $nev = preg_replace('/[\s\x{00a0}\x{202f}]+/u', ' ', $nev);

        return mb_substr(trim((string) $nev), 0, 255);
    }

    /**
     * Az azonosság kulcsa: két név akkor „egyforma", ha ez megegyezik.
     * Kis- és nagybetű, valamint a szóközök száma nem számít.
     */
    public static function kulcs(string $nev): string
    {
        $tiszta = self::nev_tisztit($nev);

        return $tiszta === '' ? '' : md5(mb_strtolower($tiszta, 'UTF-8'));
    }

    /* =================================================================
     * Olvasás
     * ============================================================== */

    /**
     * A megjegyzett szolgáltatások, a leggyakrabban használt elöl.
     *
     * @return array<int, object>
     */
    public static function lista(): array
    {
        global $wpdb;

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, nev, me, brutto_ar, afa_kulcs, hasznalat, kategoria, kod, ar_max, bevizsgalas FROM ' . self::tabla() .
                ' ORDER BY bevizsgalas DESC, hasznalat DESC, sorrend = 0, sorrend ASC, nev ASC LIMIT %d',
                self::LISTA_HATAR
            )
        );

        return is_array($sorok) ? $sorok : [];
    }

    public static function darab(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::tabla());
    }

    public static function nev_szerint(string $nev): ?object
    {
        global $wpdb;

        $kulcs = self::kulcs($nev);

        if ($kulcs === '') {
            return null;
        }

        $sor = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE kulcs = %s ORDER BY id ASC LIMIT 1', $kulcs)
        );

        return is_object($sor) ? $sor : null;
    }

    /* =================================================================
     * Írás
     * ============================================================== */

    /**
     * Megjegyez egy szolgáltatást. Ha ilyen nevű már van, NEM jön létre új
     * sor: a meglévő kapja meg az új árat, egységet, áfakulcsot, és nő a
     * használat-számlálója. A név írásmódja az elsőként rögzített marad.
     *
     * Nulla ár nem írja felül a már megjegyzett árat. Az árlistás sor
     * (díj, kategóriás vagy sávos árú tétel) árát sem: azt az árlista adja,
     * a munkalapon beírt egyedi ár nem írhatja át.
     *
     * @param array<string, mixed> $adat    nev, me, brutto_ar, afa_kulcs
     * @param int                  $hasznalat Ennyivel nő a számláló (0 = csak frissít).
     * @return int A törzssor azonosítója, vagy 0, ha a név üres.
     */
    public static function megjegyez(array $adat, int $hasznalat = 1): int
    {
        global $wpdb;

        $nev   = self::nev_tisztit((string) ($adat['nev'] ?? ''));
        $kulcs = self::kulcs($nev);

        if ($kulcs === '') {
            return 0;
        }

        $tabla = self::tabla();
        $most  = current_time('mysql');
        $ar    = max(0.0, round((float) ($adat['brutto_ar'] ?? 0), 2));
        $me    = mb_substr(trim((string) ($adat['me'] ?? '')), 0, 20);
        $afa   = SDH_Muhely_Tetel::afakulcs_ervenyes((string) ($adat['afa_kulcs'] ?? ''));
        $novel = max(0, $hasznalat);

        $meglevo = self::nev_szerint($nev);

        if ($meglevo === null) {
            $siker = $wpdb->insert($tabla, [
                'nev'        => $nev,
                'kulcs'      => $kulcs,
                'me'         => $me !== '' ? $me : 'db',
                'brutto_ar'  => $ar,
                'afa_kulcs'  => $afa,
                'hasznalat'  => $novel,
                'letrehozva' => $most,
                'modositva'  => $most,
            ]);

            if ($siker !== false) {
                return (int) $wpdb->insert_id;
            }

            // Közben más már felvette (egyedi kulcs): azt frissítjük.
            $meglevo = self::nev_szerint($nev);

            if ($meglevo === null) {
                return 0;
            }
        }

        $valtozas = [
            'afa_kulcs' => $afa,
            'hasznalat' => (int) $meglevo->hasznalat + $novel,
            'modositva' => $most,
        ];

        if ($ar > 0 && !self::arlistas($meglevo)) {
            $valtozas['brutto_ar'] = $ar;
        }

        if ($me !== '') {
            $valtozas['me'] = $me;
        }

        $wpdb->update($tabla, $valtozas, ['id' => (int) $meglevo->id]);

        return (int) $meglevo->id;
    }

    /** Árlistából jött / árlistán tartott sor: az árát csak az árlista írja. */
    public static function arlistas(object $sor): bool
    {
        return (int) ($sor->bevizsgalas ?? 0) === 1
            || (float) ($sor->ar_max ?? 0) > 0
            || (string) ($sor->kategoria ?? '') !== '';
    }

    /** Egy megjegyzett szolgáltatás elfelejtése. A munkalapok tételeihez nem nyúl. */
    public static function felejt(int $id): bool
    {
        global $wpdb;

        return $id > 0 && (bool) $wpdb->delete(self::tabla(), ['id' => $id]);
    }

    /* =================================================================
     * Rendbetétel: összevonás és feltöltés
     * ============================================================== */

    /**
     * Sémafrissítés után fut. Idempotens.
     */
    public static function rendbetetel(): void
    {
        self::osszevon();

        if (self::darab() === 0) {
            self::tetelekbol();
        }
    }

    /**
     * Az egyforma nevű sorok összevonása: a legrégebbi marad, a többiek
     * használata hozzáadódik, az ár a legutóbb módosítotté lesz. Az egyedi
     * kulcs miatt rendes működésben nincs mit összevonni – ez a biztonsági
     * háló (import, kézi adatbázis-módosítás, hiányzó kulcs).
     *
     * @return int Ennyi fölösleges sor törlődött.
     */
    public static function osszevon(): int
    {
        global $wpdb;

        $tabla = self::tabla();
        $sorok = $wpdb->get_results("SELECT * FROM {$tabla} ORDER BY id ASC");

        if (!is_array($sorok) || $sorok === []) {
            return 0;
        }

        $csoportok = [];

        foreach ($sorok as $sor) {
            $kulcs = self::kulcs((string) $sor->nev);

            if ($kulcs === '') {
                $wpdb->delete($tabla, ['id' => (int) $sor->id]);
                continue;
            }

            $csoportok[$kulcs][] = $sor;
        }

        $torolt = 0;

        foreach ($csoportok as $kulcs => $csoport) {
            $megmarad = $csoport[0];

            if (count($csoport) === 1) {
                if ((string) $megmarad->kulcs !== $kulcs) {
                    $wpdb->update($tabla, ['kulcs' => $kulcs], ['id' => (int) $megmarad->id]);
                }

                continue;
            }

            $hasznalat = 0;
            $utolso    = $megmarad;

            foreach ($csoport as $sor) {
                $hasznalat += (int) $sor->hasznalat;

                if ((float) $sor->brutto_ar > 0
                    && ((float) $utolso->brutto_ar <= 0 || (string) $sor->modositva >= (string) $utolso->modositva)) {
                    $utolso = $sor;
                }
            }

            // Előbb a fölöslegesek törlődnek, hogy az egyedi kulcs szabad legyen.
            foreach (array_slice($csoport, 1) as $sor) {
                if ($wpdb->delete($tabla, ['id' => (int) $sor->id])) {
                    $torolt++;
                }
            }

            $wpdb->update($tabla, [
                'kulcs'     => $kulcs,
                'hasznalat' => $hasznalat,
                'brutto_ar' => (float) $utolso->brutto_ar,
                'me'        => (string) $utolso->me !== '' ? (string) $utolso->me : 'db',
                'afa_kulcs' => (string) $utolso->afa_kulcs,
                'modositva' => current_time('mysql'),
            ], ['id' => (int) $megmarad->id]);
        }

        return $torolt;
    }

    /**
     * A törzs feltöltése a munkalapokon már szereplő szolgáltatásokból:
     * nevenként egy sor, a használat a tételek száma, az ár a legutóbbié.
     *
     * @return int Ennyi szolgáltatás került a törzsbe.
     */
    public static function tetelekbol(): int
    {
        global $wpdb;

        $tetelek = $wpdb->get_results(
            'SELECT megnevezes, me, brutto_ar, afa_kulcs FROM ' . SDH_Muhely_Tetel::tabla() .
            " WHERE tipus = 'szolgaltatas' AND megnevezes <> '' ORDER BY id ASC"
        );

        if (!is_array($tetelek)) {
            return 0;
        }

        $osszes = [];

        foreach ($tetelek as $t) {
            $kulcs = self::kulcs((string) $t->megnevezes);

            if ($kulcs === '') {
                continue;
            }

            if (!isset($osszes[$kulcs])) {
                $osszes[$kulcs] = [
                    'nev'       => (string) $t->megnevezes,
                    'me'        => (string) $t->me,
                    'brutto_ar' => 0.0,
                    'afa_kulcs' => (string) $t->afa_kulcs,
                    'db'        => 0,
                ];
            }

            $osszes[$kulcs]['db']++;
            $osszes[$kulcs]['afa_kulcs'] = (string) $t->afa_kulcs;

            if ((string) $t->me !== '') {
                $osszes[$kulcs]['me'] = (string) $t->me;
            }

            if ((float) $t->brutto_ar > 0) {
                $osszes[$kulcs]['brutto_ar'] = (float) $t->brutto_ar;
            }
        }

        $db = 0;

        foreach ($osszes as $sz) {
            if (self::megjegyez($sz, $sz['db']) > 0) {
                $db++;
            }
        }

        return $db;
    }

    /* =================================================================
     * Kézi felvitel és szerkesztés
     * ============================================================== */

    public static function egy(int $id): ?object
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id));

        return is_object($sor) ? $sor : null;
    }

    /**
     * Egy szolgáltatás mentése a törzsbe az űrlapról. Az „egy név = egy sor"
     * szabály itt is áll: ha a név egy MÁSIK meglévő sor neve, a kettő
     * összeolvad – egy marad, a használat összeadódik.
     *
     * @param array{nev: string, me: string, brutto_ar: float, afa_kulcs: string, megjegyzes: string, kategoria: string, kod: string, ar_max: float, bevizsgalas: int} $adat
     * @return array{0: int, 1: string}|null [azonosító, üzenetkulcs], vagy null adatbázishibánál.
     */
    public static function torzsbe(int $id, array $adat): ?array
    {
        global $wpdb;

        $tabla = self::tabla();
        $most  = current_time('mysql');
        $nev   = self::nev_tisztit($adat['nev']);
        $kulcs = self::kulcs($nev);

        if ($kulcs === '') {
            return null;
        }

        $sor = [
            'nev'        => $nev,
            'kulcs'      => $kulcs,
            'me'         => $adat['me'] !== '' ? mb_substr($adat['me'], 0, 20) : 'db',
            'brutto_ar'  => max(0.0, round($adat['brutto_ar'], 2)),
            'afa_kulcs'  => SDH_Muhely_Tetel::afakulcs_ervenyes($adat['afa_kulcs']),
            'megjegyzes' => $adat['megjegyzes'],
            'kategoria'  => mb_substr($adat['kategoria'], 0, 160),
            'kod'        => mb_substr($adat['kod'], 0, 20),
            'ar_max'     => $adat['ar_max'] > $adat['brutto_ar'] ? round($adat['ar_max'], 2) : 0.0,
            'bevizsgalas' => $adat['bevizsgalas'] ? 1 : 0,
            'modositva'  => $most,
        ];

        $masik  = self::nev_szerint($nev);
        $uzenet = $id > 0 ? 'szolg_mentve' : 'szolg_letrehozva';

        if ($masik !== null && (int) $masik->id !== $id) {
            $uzenet = 'szolg_osszevonva';

            if ($id > 0) {
                // Átnevezés egy már létező névre: ez a sor marad, a másik beleolvad.
                $ez = self::egy($id);

                $sor['hasznalat'] = (int) ($ez->hasznalat ?? 0) + (int) $masik->hasznalat;
                $wpdb->delete($tabla, ['id' => (int) $masik->id]);
            } else {
                // Új felvitel létező névvel: nem jön létre új sor, a meglévő frissül.
                $id = (int) $masik->id;
                // A meglévő írásmódja marad – az a név már munkalapokon is szerepelhet.
                unset($sor['nev']);
            }
        }

        if ($id > 0) {
            if ($wpdb->update($tabla, $sor, ['id' => $id]) === false) {
                return null;
            }

            return [$id, $uzenet];
        }

        $sor['hasznalat']  = 0;
        $sor['letrehozva'] = $most;

        if ($wpdb->insert($tabla, $sor) === false) {
            return null;
        }

        return [(int) $wpdb->insert_id, $uzenet];
    }

    /**
     * A beküldött űrlapmezők megtisztítva.
     *
     * @return array{nev: string, me: string, brutto_ar: float, afa_kulcs: string, megjegyzes: string, kategoria: string, kod: string, ar_max: float, bevizsgalas: int}
     */
    private static function adatok_osszeallit(): array
    {
        $szoveg = static fn (string $mezo): string => isset($_POST[$mezo]) && is_scalar($_POST[$mezo])
            ? sanitize_text_field(wp_unslash((string) $_POST[$mezo]))
            : '';

        return [
            'nev'        => self::nev_tisztit($szoveg('nev')),
            'me'         => $szoveg('me'),
            'brutto_ar'  => max(0.0, SDH_Muhely_Tetel::szam($szoveg('brutto_ar'))),
            'afa_kulcs'  => $szoveg('afa_kulcs'),
            'kategoria'  => $szoveg('kategoria'),
            'kod'        => $szoveg('kod'),
            'ar_max'     => max(0.0, SDH_Muhely_Tetel::szam($szoveg('ar_max'))),
            'bevizsgalas' => !empty($_POST['bevizsgalas']) ? 1 : 0,
            'megjegyzes' => isset($_POST['megjegyzes']) && is_scalar($_POST['megjegyzes'])
                ? sanitize_textarea_field(wp_unslash((string) $_POST['megjegyzes']))
                : '',
        ];
    }

    private static function bekuldes_kontextusa(): string
    {
        $kontextus = isset($_REQUEST['kontextus']) ? sanitize_key(wp_unslash($_REQUEST['kontextus'])) : 'admin';

        return $kontextus === 'frontend' ? 'frontend' : 'admin';
    }

    private static function kontextus_ertek(): string
    {
        return wp_doing_ajax() ? self::bekuldes_kontextusa() : SDH_Muhely_Modulok::kontextus();
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    private static function vissza(array $parameterek): string
    {
        return SDH_Muhely_Modulok::visszateres(self::KULCS, $parameterek, self::bekuldes_kontextusa());
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    private static function url(array $parameterek = []): string
    {
        return SDH_Muhely_Modulok::url(self::KULCS, $parameterek);
    }

    /** Teljes oldalas beküldés (JS nélküli tartalék). */
    public static function mentes(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_szolgaltatas_mentes', 'sdh_nonce');

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adatok = self::adatok_osszeallit();

        if ($adatok['nev'] === '') {
            wp_safe_redirect(self::vissza(array_filter([
                'nezet'  => $id > 0 ? 'szerkeszt' : 'uj',
                'id'     => $id > 0 ? $id : null,
                'uzenet' => 'szolg_hianyzo_nev',
            ])));
            exit;
        }

        $eredmeny = $id > 0 && self::egy($id) === null ? null : self::torzsbe($id, $adatok);

        wp_safe_redirect(self::vissza(['uzenet' => $eredmeny === null ? 'mentes_hiba' : $eredmeny[1]]));
        exit;
    }

    /** Popupos beküldés. */
    public static function ajax_mentes(): void
    {
        check_ajax_referer('sdh_muhely_szolgaltatas_mentes', 'sdh_nonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adatok = self::adatok_osszeallit();

        if ($adatok['nev'] === '') {
            wp_send_json_error(['uzenet' => 'A megnevezés kitöltése kötelező.']);
        }

        if ($id > 0 && self::egy($id) === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen szolgáltatás. Lehet, hogy időközben törölték.']);
        }

        $eredmeny = self::torzsbe($id, $adatok);

        if ($eredmeny === null) {
            wp_send_json_error(['uzenet' => 'Az adatbázis visszautasította a mentést.']);
        }

        $mentett = self::egy($eredmeny[0]);

        // A mezőket a munkalap választó popupja használja: a frissen felvitt
        // szolgáltatást oldalfrissítés nélkül teszi a tételsorba.
        wp_send_json_success([
            'id'         => (int) $mentett->id,
            'nev'        => (string) $mentett->nev,
            'me'         => (string) $mentett->me,
            'brutto_ar'  => (float) $mentett->brutto_ar,
            'afa_kulcs'  => (string) $mentett->afa_kulcs,
            'kategoria'  => (string) $mentett->kategoria,
            'kod'        => (string) $mentett->kod,
            'ar_max'     => (float) $mentett->ar_max,
            'dij'        => (int) $mentett->bevizsgalas,
            'osszevonva' => $eredmeny[1] === 'szolg_osszevonva',
            'vissza'     => self::vissza(['uzenet' => $eredmeny[1]]),
        ]);
    }

    /* =================================================================
     * A modul oldala: lista
     * ============================================================== */

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        $nezet = isset($_GET['nezet']) ? sanitize_key(wp_unslash($_GET['nezet'])) : 'lista';

        if ($nezet === 'uj' || $nezet === 'szerkeszt') {
            self::urlap_oldal($nezet);

            return;
        }

        if ($nezet === 'arlista') {
            self::arlista_oldal();

            return;
        }

        self::lista_oldal();
    }

    private static function penz(float $osszeg): string
    {
        return number_format($osszeg, 0, ',', "\u{00a0}") . "\u{00a0}Ft";
    }

    /** Ár kiírva: fix ár, vagy sáv („12 000 – 18 000 Ft"). */
    public static function ar_szoveg(float $ar, float $max): string
    {
        if ($ar <= 0 && $max <= 0) {
            return '—';
        }

        return $max > $ar
            ? number_format($ar, 0, ',', "\u{00a0}") . "\u{00a0}–\u{00a0}" . self::penz($max)
            : self::penz($ar);
    }

    /**
     * A kategóriák nevei (a beillesztett árlista sorrendjében).
     *
     * @return string[]
     */
    public static function kategoriak(): array
    {
        global $wpdb;

        $sorok = $wpdb->get_col(
            'SELECT kategoria FROM ' . self::tabla() . " WHERE kategoria <> '' GROUP BY kategoria ORDER BY MIN(sorrend), kategoria"
        );

        return array_map('strval', is_array($sorok) ? $sorok : []);
    }

    /**
     * A szűrő-pillek a lista fölött: Mind / Bevizsgálási díjak / Árlista.
     */
    private static function nezet_pillek(string $aktiv): void
    {
        $pillek = [
            'mind'    => ['Mind', self::url()],
            'dij'     => ['Bevizsgálási díjak', self::url(['dij' => 1])],
            'arlista' => ['Árlista', self::url(['nezet' => 'arlista'])],
        ];

        echo '<nav class="sdh-szolg-pillek" aria-label="Nézet">';

        foreach ($pillek as $kulcs => [$cimke, $url]) {
            printf(
                '<a class="sdh-szolg-pill%s" href="%s"%s>%s</a>',
                $kulcs === $aktiv ? ' is-aktiv' : '',
                esc_url($url),
                $kulcs === $aktiv ? ' aria-current="page"' : '',
                esc_html($cimke)
            );
        }

        echo '</nav>';
    }

    /** A fejléc gombjai – a lista és az árlista nézet közös. */
    private static function fejlec_gombok(): array
    {
        return [
            [
                'cimke'  => 'Árlista beillesztése',
                'url'    => self::url(['nezet' => 'arlista']),
                'adatok' => ['sdh-urlap' => 'szolgaltatasarlista', 'sdh-id' => '0'],
            ],
            [
                'cimke'      => '+ Új szolgáltatás',
                'url'        => self::url(['nezet' => 'uj']),
                'elsodleges' => true,
                'adatok'     => ['sdh-urlap' => self::KULCS, 'sdh-id' => '0'],
            ],
        ];
    }

    private static function lista_oldal(): void
    {
        global $wpdb;

        $kereses = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
        $gyakori = !empty($_GET['gyakori']);
        $dij     = !empty($_GET['dij']);
        $oldal   = isset($_GET['oldalszam']) ? max(1, (int) $_GET['oldalszam']) : 1;
        $eltolas = ($oldal - 1) * self::OLDAL_MERET;
        $tabla   = self::tabla();

        $hol     = '';
        $ertekek = [];

        $felt    = [];

        if ($kereses !== '') {
            $felt[]  = '(nev LIKE %s OR megjegyzes LIKE %s OR kategoria LIKE %s OR kod LIKE %s)';
            $minta   = '%' . $wpdb->esc_like($kereses) . '%';
            $ertekek = [$minta, $minta, $minta, $minta];
        }

        if ($dij) {
            $felt[] = 'bevizsgalas = 1';
        }

        $hol    = $felt === [] ? '' : 'WHERE ' . implode(' AND ', $felt);
        $rendez = $gyakori ? 'hasznalat DESC, nev ASC' : ($dij ? 'sorrend ASC, kategoria ASC, nev ASC' : 'nev ASC');

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- a $hol csak helyőrzőket tartalmaz.
        $osszesen = (int) ($ertekek === []
            ? $wpdb->get_var("SELECT COUNT(*) FROM {$tabla} {$hol}")
            : $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tabla} {$hol}", $ertekek)));

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tabla} {$hol} ORDER BY {$rendez} LIMIT %d OFFSET %d",
                array_merge($ertekek, [self::OLDAL_MERET, $eltolas])
            )
        );
        // phpcs:enable

        $sorok   = is_array($sorok) ? $sorok : [];
        $oldalak = max(1, (int) ceil($osszesen / self::OLDAL_MERET));
        $alap    = array_filter(['k' => $kereses !== '' ? $kereses : null, 'gyakori' => $gyakori ? 1 : null, 'dij' => $dij ? 1 : null]);

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Szolgáltatások',
                'A munkalapokon használt szolgáltatások és a bevizsgálási díjak törzse. Egy névből mindig egy van; a munkalap mentése is ide jegyzi az újakat.',
                self::fejlec_gombok()
            );
            self::nezet_pillek($dij ? 'dij' : 'mind');
            ?>

            <form method="get" class="sdh-kereso"
                  action="<?php echo esc_url(SDH_Muhely_Modulok::urlap_cel(self::KULCS)); ?>">
                <?php SDH_Muhely_Modulok::urlap_rejtett(self::KULCS); ?>

                <?php if ($gyakori) : ?>
                    <input type="hidden" name="gyakori" value="1">
                <?php endif; ?>

                <?php if ($dij) : ?>
                    <input type="hidden" name="dij" value="1">
                <?php endif; ?>

                <input type="search" name="k" value="<?php echo esc_attr($kereses); ?>"
                       placeholder="Megnevezés vagy megjegyzés…">

                <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Keresés</button>

                <?php if ($kereses !== '') : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos"
                       href="<?php echo esc_url(self::url(array_filter(['gyakori' => $gyakori ? 1 : null, 'dij' => $dij ? 1 : null]))); ?>">Szűrő törlése</a>
                <?php endif; ?>

                <a class="sdh-gomb sdh-gomb--vilagos"
                   href="<?php echo esc_url(self::url(array_filter([
                       'k'       => $kereses !== '' ? $kereses : null,
                       'gyakori' => $gyakori ? null : 1,
                       'dij'     => $dij ? 1 : null,
                   ]))); ?>">
                    <?php echo $gyakori ? 'Névsorban' : 'Leggyakoribbak elöl'; ?>
                </a>

                <span class="sdh-kereso__talalat">
                    <?php echo esc_html(number_format_i18n($osszesen)); ?> <?php echo $dij ? 'díj' : 'szolgáltatás'; ?>
                </span>
            </form>

            <table class="sdh-tabla sdh-tabla--szolg">
                <thead>
                    <tr>
                        <th>Megnevezés</th>
                        <th class="sdh-tabla__rejtheto">M.e.</th>
                        <th class="sdh-tabla__szam">Bruttó ár</th>
                        <th class="sdh-tabla__szam sdh-tabla__rejtheto">Nettó ár</th>
                        <th class="sdh-tabla__rejtheto">Áfa</th>
                        <th class="sdh-tabla__szam sdh-tabla__rejtheto" title="Hány munkalap-tételben szerepelt">Használat</th>
                        <th class="sdh-tabla__rejtheto"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($sorok === []) : ?>
                    <tr>
                        <td colspan="7" class="sdh-tabla__ures">
                            <?php if ($kereses !== '') : ?>
                                Erre a keresésre nincs találat.
                            <?php elseif ($dij) : ?>
                                Még nincs bevizsgálási díj. Az <a href="<?php echo esc_url(self::url(['nezet' => 'arlista'])); ?>"
                                   data-sdh-urlap="szolgaltatasarlista" data-sdh-id="0">Árlista beillesztése</a> gombbal
                                egyszerre felviheted a teljes díjlistát, vagy egy szolgáltatásnál bepipálhatod a „Bevizsgálási díj” jelölőt.
                            <?php else : ?>
                                Még nincs szolgáltatás.
                                <a href="<?php echo esc_url(self::url(['nezet' => 'uj'])); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="0">Vidd fel az elsőt.</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($sorok as $sor) : ?>
                        <?php
                        $szerkeszt_url = self::url(['nezet' => 'szerkeszt', 'id' => (int) $sor->id]);
                        $afa_kulcs     = SDH_Muhely_Tetel::afakulcs_ervenyes((string) $sor->afa_kulcs);
                        $brutto        = (float) $sor->brutto_ar;
                        $netto         = SDH_Muhely_Tetel::bruttobol_netto($brutto, SDH_Muhely_Tetel::afa_szazalek($afa_kulcs));
                        ?>
                        <tr>
                            <td class="sdh-tabla__nev">
                                <a href="<?php echo esc_url($szerkeszt_url); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="<?php echo (int) $sor->id; ?>">
                                    <?php echo esc_html($sor->nev); ?>
                                </a>
                                <?php if ((int) $sor->bevizsgalas === 1) : ?>
                                    <span class="sdh-szolg-dij" title="Bevizsgálási díj – a munkalapon a díjlistából választható">díj</span>
                                <?php endif; ?>
                                <?php if ((string) $sor->kategoria !== '' || (string) $sor->kod !== '') : ?>
                                    <div class="sdh-tabla__halvany">
                                        <?php echo esc_html(trim((string) $sor->kategoria . ((string) $sor->kod !== '' ? ' · ' . (string) $sor->kod : ''), ' ·')); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ((string) ($sor->megjegyzes ?? '') !== '') : ?>
                                    <div class="sdh-tabla__halvany">
                                        <?php echo esc_html(wp_trim_words((string) $sor->megjegyzes, 14, '…')); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="sdh-tabla__rejtheto"><?php echo esc_html($sor->me); ?></td>
                            <td class="sdh-tabla__szam"><?php echo esc_html(self::ar_szoveg($brutto, (float) $sor->ar_max)); ?></td>
                            <td class="sdh-tabla__szam sdh-tabla__rejtheto sdh-tabla__halvany">
                                <?php echo $brutto > 0 ? esc_html(self::penz($netto)) : '—'; ?>
                            </td>
                            <td class="sdh-tabla__rejtheto"><?php echo esc_html(is_numeric($afa_kulcs) ? $afa_kulcs . '%' : $afa_kulcs); ?></td>
                            <td class="sdh-tabla__szam sdh-tabla__halvany sdh-tabla__rejtheto"><?php echo (int) $sor->hasznalat; ?></td>
                            <td class="sdh-tabla__rejtheto">
                                <a class="sdh-gomb sdh-gomb--vilagos"
                                   href="<?php echo esc_url($szerkeszt_url); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="<?php echo (int) $sor->id; ?>">Megnyit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($oldalak > 1) : ?>
                <div class="sdh-lapozo">
                    <?php for ($i = 1; $i <= $oldalak; $i++) : ?>
                        <?php if ($i === $oldal) : ?>
                            <span class="sdh-lapozo__aktiv"><?php echo (int) $i; ?></span>
                        <?php else : ?>
                            <a href="<?php echo esc_url(self::url(array_merge($alap, ['oldalszam' => $i]))); ?>"><?php echo (int) $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =================================================================
     * Űrlap
     * ============================================================== */

    private static function alcim(?object $sz): string
    {
        return $sz === null
            ? 'Csak a megnevezés kötelező. Az ár bruttó egységár.'
            : 'Eddig ' . (int) $sz->hasznalat . ' munkalap-tételben szerepelt.';
    }

    /** Teljes oldalas változat (tartalék). */
    private static function urlap_oldal(string $nezet): void
    {
        $sz = null;

        if ($nezet === 'szerkeszt') {
            $sz = self::egy(isset($_GET['id']) ? (int) $_GET['id'] : 0);

            if ($sz === null) {
                wp_safe_redirect(self::url(['uzenet' => 'nincs_ilyen']));
                exit;
            }
        }

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                $sz === null ? 'Új szolgáltatás' : (string) $sz->nev,
                self::alcim($sz),
                [['cimke' => '← Vissza a listához', 'url' => self::url()]]
            );
            ?>

            <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_szolgaltatas_mentes">
                <?php self::urlap_belso($sz, false); ?>
            </form>
        </div>
        <?php
    }

    /** Popup változat. */
    public static function ajax_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $sz = $id > 0 ? self::egy($id) : null;

        if ($id > 0 && $sz === null) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs ilyen szolgáltatás.</div>';
            wp_die();
        }

        // Új felvitelnél a hívó előre megadhatja a nevet (a választó popup keresője).
        $nev = $sz === null && isset($_GET['nev']) ? self::nev_tisztit(sanitize_text_field(wp_unslash($_GET['nev']))) : '';

        ?>
        <h2 class="sdh-modal__cim"><?php echo esc_html($sz === null ? 'Új szolgáltatás' : (string) $sz->nev); ?></h2>
        <p class="sdh-modal__alcim"><?php echo esc_html(self::alcim($sz)); ?></p>

        <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-sdh-ajax-action="sdh_muhely_szolgaltatasok_ment">
            <input type="hidden" name="action" value="sdh_muhely_szolgaltatas_mentes">
            <?php self::urlap_belso($sz, true, $nev); ?>
        </form>
        <?php

        wp_die();
    }

    /**
     * Az űrlap belseje – a teljes oldalas és a popupos változat közös része.
     */
    private static function urlap_belso(?object $sz, bool $modal, string $nev = ''): void
    {
        $uj        = $sz === null;
        $afakulcs  = $uj ? SDH_Muhely_Tetel::alap_afakulcs() : SDH_Muhely_Tetel::afakulcs_ervenyes((string) $sz->afa_kulcs);
        $brutto    = $uj ? 0.0 : (float) $sz->brutto_ar;
        $ar_mezobe = $brutto > 0 ? rtrim(rtrim(number_format($brutto, 2, '.', ''), '0'), '.') : '';

        ?>
        <input type="hidden" name="id" value="<?php echo (int) ($sz->id ?? 0); ?>">
        <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
        <?php wp_nonce_field('sdh_muhely_szolgaltatas_mentes', 'sdh_nonce'); ?>

        <div class="sdh-ugyfelurlap sdh-szolgurlap" data-sdh-szolgurlap>
            <div class="sdh-sor">
                <input type="text" name="nev" id="szolg_nev" required maxlength="255" autocomplete="off"
                       aria-label="Megnevezés" placeholder="Megnevezés * (pl. Kijelzőcsere munkadíj)"
                       value="<?php echo esc_attr($uj ? $nev : (string) $sz->nev); ?>">
            </div>

            <div class="sdh-sor sdh-sor--ketto">
                <div class="sdh-ig">
                    <label for="szolg_brutto_ar">Bruttó ár</label>
                    <span class="sdh-szam sdh-szam--utotag">
                        <input type="number" name="brutto_ar" id="szolg_brutto_ar" min="0" step="any"
                               data-sdh-lepes="500" data-sdh-szolg-brutto placeholder="0"
                               value="<?php echo esc_attr($ar_mezobe); ?>">
                        <span class="sdh-szam__utotag" aria-hidden="true">Ft</span>
                    </span>
                </div>

                <div class="sdh-ig">
                    <label for="szolg_afa_kulcs">Áfakulcs</label>
                    <select name="afa_kulcs" id="szolg_afa_kulcs" data-sdh-szolg-afa>
                        <?php foreach (SDH_Muhely_Tetel::afakulcsok() as $kulcs => $adat) : ?>
                            <option value="<?php echo esc_attr((string) $kulcs); ?>"
                                    data-szazalek="<?php echo esc_attr((string) $adat['szazalek']); ?>"
                                    <?php selected($afakulcs, (string) $kulcs); ?>>
                                <?php echo esc_html($adat['nev']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sdh-ig">
                    <label for="szolg_me">Menny. egység</label>
                    <input type="text" name="me" id="szolg_me" maxlength="20" placeholder="db"
                           value="<?php echo esc_attr($uj ? 'db' : (string) $sz->me); ?>">
                </div>

                <div class="sdh-ig">
                    <span class="sdh-ig__cimke">Nettó ár</span>
                    <output class="sdh-szolgurlap__netto" data-sdh-szolg-netto>—</output>
                </div>
            </div>

            <?php
            $ar_max    = $uj ? 0.0 : (float) ($sz->ar_max ?? 0);
            $max_mezbe = $ar_max > 0 ? rtrim(rtrim(number_format($ar_max, 2, '.', ''), '0'), '.') : '';
            ?>
            <div class="sdh-sor sdh-sor--ketto">
                <div class="sdh-ig">
                    <label for="szolg_kategoria">Kategória</label>
                    <input type="text" name="kategoria" id="szolg_kategoria" maxlength="160" list="szolg_kategoriak"
                           placeholder="pl. Mobiltelefon" autocomplete="off"
                           value="<?php echo esc_attr($uj ? '' : (string) ($sz->kategoria ?? '')); ?>">
                    <datalist id="szolg_kategoriak">
                        <?php foreach (self::kategoriak() as $kat) : ?>
                            <option value="<?php echo esc_attr($kat); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="sdh-ig">
                    <label for="szolg_kod">Kód</label>
                    <input type="text" name="kod" id="szolg_kod" maxlength="20" placeholder="pl. 100-"
                           value="<?php echo esc_attr($uj ? '' : (string) ($sz->kod ?? '')); ?>">
                </div>

                <div class="sdh-ig">
                    <label for="szolg_ar_max" title="Sávos árnál a felső határ (pl. 12 000 – 18 000 Ft). Üresen fix ár.">Ár felső határa</label>
                    <span class="sdh-szam sdh-szam--utotag">
                        <input type="number" name="ar_max" id="szolg_ar_max" min="0" step="any"
                               data-sdh-lepes="500" placeholder="fix ár"
                               value="<?php echo esc_attr($max_mezbe); ?>">
                        <span class="sdh-szam__utotag" aria-hidden="true">Ft</span>
                    </span>
                </div>

                <div class="sdh-ig">
                    <span class="sdh-ig__cimke"></span>
                    <label class="sdh-jelolo" for="szolg_bevizsgalas"
                           title="A munkalapon a „Díj a listából…” gomb ezek közül kínál.">
                        <input type="checkbox" name="bevizsgalas" id="szolg_bevizsgalas" value="1"
                            <?php checked(!$uj && (int) ($sz->bevizsgalas ?? 0) === 1); ?>>
                        Bevizsgálási díj
                    </label>
                </div>
            </div>

            <div class="sdh-sor">
                <textarea name="megjegyzes" id="szolg_megjegyzes" rows="3" aria-label="Megjegyzés"
                          placeholder="Belső megjegyzés (mit tartalmaz, mikor adható…)"><?php
                    echo esc_textarea($uj ? '' : (string) ($sz->megjegyzes ?? ''));
                ?></textarea>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">
                    <?php echo $uj ? 'Szolgáltatás létrehozása' : 'Mentés'; ?>
                </button>

                <?php if ($modal) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                <?php else : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(self::url()); ?>">Mégsem</a>
                <?php endif; ?>

                <?php if (!$uj && $modal) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--jobbra"
                            data-sdh-szolg-torol="<?php echo (int) $sz->id; ?>"
                            data-vissza="<?php echo esc_url(self::vissza(['uzenet' => 'szolg_torolve'])); ?>"
                            title="A törzsből törli. A munkalapokon már szereplő tételek megmaradnak.">
                        Törlés
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    private static function jog_ellenorzes(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    /**
     * A megjegyzett szolgáltatások a böngészőnek. Egy sor:
     * [id, név, m.e., bruttó ár, áfakulcs, használat, kategória, kód,
     *  ár felső határa, bevizsgálási díj (0/1)].
     */
    public static function ajax_lista(): void
    {
        self::jog_ellenorzes();

        $sorok = [];

        foreach (self::lista() as $sz) {
            $sorok[] = [
                (int) $sz->id,
                (string) $sz->nev,
                (string) $sz->me,
                (float) $sz->brutto_ar,
                (string) $sz->afa_kulcs,
                (int) $sz->hasznalat,
                (string) $sz->kategoria,
                (string) $sz->kod,
                (float) $sz->ar_max,
                (int) $sz->bevizsgalas,
            ];
        }

        nocache_headers();
        wp_send_json_success(['sorok' => $sorok]);
    }

    public static function ajax_felejt(): void
    {
        self::jog_ellenorzes();

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        if (!self::felejt($id)) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen megjegyzett szolgáltatás.'], 404);
        }

        wp_send_json_success(['id' => $id]);
    }

    /* =================================================================
     * Árlista: beillesztés szövegből
     *
     * A plugin NEM hoz magával árat: a díjlista a felhasználó saját adata.
     * A szöveget (weboldalról, Wordből, Excelből másolva) a popupba kell
     * beilleszteni; a sorokat itt értelmezzük:
     *
     *   Fejléc kóddal     „Mobiltelefon: 100-"          → kategória + kód,
     *                                                    a tételei bevizsgálási díjak
     *   Fejléc kód nélkül „Kiszállási díjak"            → kategória (sima szolgáltatás);
     *                     ha „bevizsgál…" szerepel benne, a tételei díjak
     *   Tétel             „Egyszerű nyomógombos  8.000 Ft"
     *                     „Szoftver frissítés 12.000 – 18.000 Ft"  (sávos ár)
     *                     „Elsőbbségi felár (000-2) 5.000 Ft"      (kód zárójelben;
     *                                                  a felár nem díj)
     *
     * Ugyanaz a név ugyanazzal az árral egyszer kerül be; eltérő árral a
     * kategória neve kerül elé. A díjak neve mindig „Kategória – Tétel".
     * ============================================================== */

    /** Pénzösszeg a szövegben: 8.000 / 8 000 / 8000. */
    private const AR_MINTA = '\d{1,3}(?:[.,\x{a0}\x{202f} ]\d{3})+|\d+';

    private static function ar_szam(string $szoveg): float
    {
        return (float) preg_replace('/\D+/', '', $szoveg);
    }

    /**
     * A beillesztett árlista értelmezése.
     *
     * @return array{tetelek: array<int, array<string, mixed>>, kihagyott: string[]}
     */
    public static function arlista_ertelmez(string $szoveg): array
    {
        $tetelek   = [];
        $kihagyott = [];
        $kat       = '';
        $katkod    = '';
        $dijkat    = false;
        $am        = self::AR_MINTA;

        foreach (preg_split('/\r\n|\r|\n/u', $szoveg) ?: [] as $nyers) {
            $sor = trim((string) preg_replace('/[\t\x{a0}\x{202f} ]+/u', ' ', (string) $nyers));
            $sor = trim((string) preg_replace('/^[\x{2022}\x{00b7}*\x{2013}-]\s+/u', '', $sor));

            if ($sor === '') {
                continue;
            }

            // Fejléc kóddal: „Mobiltelefon: 100-" vagy „Mobiltelefon 100-".
            if (preg_match('/^(.+?)\s*:?\s+(\d{3})\s*-\s*$/u', $sor, $m)) {
                $kat    = trim($m[1], " :");
                $katkod = $m[2] . '-';
                $dijkat = true;
                continue;
            }

            // Tétel: a sor végén ár (vagy ársáv), utána Ft / HUF / ,- .
            if (preg_match('/^(.*?\S)\s*:?\s+(' . $am . ')(?:\s*(?:Ft|HUF))?(?:\s*[–—-]\s*(' . $am . '))?\s*(Ft|HUF|,-|\.-)?\.?\s*$/u', $sor, $m)
                && (($m[4] ?? '') !== '' || preg_match('/\D/', $m[2]) || self::ar_szam($m[2]) >= 1000)) {
                $nev   = trim($m[1], " :");
                $ar    = self::ar_szam($m[2]);
                $max   = isset($m[3]) && $m[3] !== '' ? self::ar_szam($m[3]) : 0.0;
                $kod   = '';
                $megj  = '';

                // Kód zárójelben: „(000-2)", „(130-)", „(000-1, bevizsgálási díjon felül)".
                $nev = (string) preg_replace_callback('/\s*\(\s*(\d{3}-\d*)\s*(?:,\s*([^()]*))?\)/u', static function (array $z) use (&$kod, &$megj): string {
                    $kod  = $kod !== '' ? $kod : $z[1];
                    $resz = trim((string) ($z[2] ?? ''), " .…");
                    $megj = $resz !== '' ? $resz : $megj;

                    return '';
                }, $nev);

                $nev = self::nev_tisztit($nev);

                if ($nev === '' || $ar <= 0) {
                    $kihagyott[] = $sor;
                    continue;
                }

                $felar = (bool) preg_match('/fel[áa]r/iu', $nev);
                $dij   = !$felar && ($dijkat || preg_match('/bevizsg[áa]l/iu', $nev));

                // Rövid, önmagában semmitmondó név („30 km", „Monitor") vagy díj: elé a kategória.
                $teljes = $nev;

                if ($kat !== '' && (($dijkat && !$felar) || preg_match('/^\d/u', $nev) || !preg_match('/\s/u', $nev))) {
                    $teljes = $kat . ' – ' . $nev;
                }

                $tetelek[] = [
                    'nev'         => mb_substr($teljes, 0, 255),
                    'rovid'       => $nev,
                    'kategoria'   => mb_substr($kat, 0, 160),
                    'kod'         => mb_substr($kod !== '' ? $kod : $katkod, 0, 20),
                    'ar'          => $ar,
                    'ar_max'      => $max > $ar ? $max : 0.0,
                    'bevizsgalas' => $dij ? 1 : 0,
                    'megjegyzes'  => $megj,
                ];
                continue;
            }

            // Fejléc kód nélkül.
            $kat    = trim((string) preg_replace('/\s*\(.*\)\s*:?$/u', '', $sor), " :");
            $kat    = $kat !== '' ? $kat : trim($sor, ' :');
            $katkod = '';
            $dijkat = (bool) preg_match('/bevizsg[áa]l/iu', $sor);
        }

        // Ugyanaz a név: azonos árral egyszer; eltérő árral a kategória kerül elé.
        $latott = [];
        $vegso  = [];

        foreach ($tetelek as $t) {
            $kulcs = self::kulcs($t['nev']);

            if (isset($latott[$kulcs])) {
                $elso = $vegso[$latott[$kulcs]];

                if (abs($elso['ar'] - $t['ar']) < 0.5 && abs($elso['ar_max'] - $t['ar_max']) < 0.5) {
                    continue;
                }

                if ($t['kategoria'] !== '' && mb_strpos($t['nev'], $t['kategoria']) !== 0) {
                    $t['nev'] = mb_substr($t['kategoria'] . ' – ' . $t['nev'], 0, 255);
                    $kulcs    = self::kulcs($t['nev']);
                }

                for ($i = 2; isset($latott[$kulcs]); $i++) {
                    $kulcs = self::kulcs($t['nev'] . ' (' . $i . ')');

                    if (!isset($latott[$kulcs])) {
                        $t['nev'] .= ' (' . $i . ')';
                    }
                }
            }

            $latott[$kulcs] = count($vegso);
            $vegso[]        = $t;
        }

        return ['tetelek' => $vegso, 'kihagyott' => $kihagyott];
    }

    /**
     * Az értelmezett árlista beírása a törzsbe: név szerint frissít vagy
     * felvesz. A használat-számláló és a megjegyzés (ha az árlistában nincs)
     * megmarad.
     *
     * @param array<int, array<string, mixed>> $tetelek
     * @param bool $dijak_ujra A listában nem szereplő díjakról lekerül a díj-jelölő.
     * @return array{uj: int, frissult: int}
     */
    public static function arlista_beir(array $tetelek, bool $dijak_ujra): array
    {
        global $wpdb;

        $tabla    = self::tabla();
        $most     = current_time('mysql');
        $uj       = 0;
        $frissult = 0;
        $dij_idk  = [];
        $sorrend  = 1;

        foreach ($tetelek as $t) {
            $nev     = self::nev_tisztit((string) $t['nev']);
            $kulcs   = self::kulcs($nev);
            $meglevo = self::nev_szerint($nev);

            if ($kulcs === '') {
                continue;
            }

            $adat = [
                'kategoria'   => (string) $t['kategoria'],
                'kod'         => (string) $t['kod'],
                'brutto_ar'   => round((float) $t['ar'], 2),
                'ar_max'      => round((float) $t['ar_max'], 2),
                'bevizsgalas' => (int) $t['bevizsgalas'],
                'sorrend'     => $sorrend++,
                'modositva'   => $most,
            ];

            if ((string) $t['megjegyzes'] !== '') {
                $adat['megjegyzes'] = (string) $t['megjegyzes'];
            }

            if ($meglevo !== null) {
                $wpdb->update($tabla, $adat, ['id' => (int) $meglevo->id]);
                $id = (int) $meglevo->id;
                $frissult++;
            } else {
                $siker = $wpdb->insert($tabla, $adat + [
                    'nev'        => $nev,
                    'kulcs'      => $kulcs,
                    'me'         => 'db',
                    'afa_kulcs'  => SDH_Muhely_Tetel::alap_afakulcs(),
                    'hasznalat'  => 0,
                    'megjegyzes' => (string) $t['megjegyzes'],
                    'letrehozva' => $most,
                ]);

                if ($siker === false) {
                    continue;
                }

                $id = (int) $wpdb->insert_id;
                $uj++;
            }

            if ((int) $t['bevizsgalas'] === 1) {
                $dij_idk[] = $id;
            }
        }

        // Új díjlista: ami a régiben díj volt, de az újban nincs, az már nem díj.
        if ($dijak_ujra && $dij_idk !== []) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- csak egész számok.
            $wpdb->query("UPDATE {$tabla} SET bevizsgalas = 0 WHERE bevizsgalas = 1 AND id NOT IN (" . implode(',', array_map('intval', $dij_idk)) . ')');
        }

        return ['uj' => $uj, 'frissult' => $frissult];
    }

    /** A beküldött árlista-szöveg. */
    private static function arlista_szoveg(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a hívó ellenőrzi.
        $szoveg = isset($_POST['szoveg']) && is_scalar($_POST['szoveg']) ? (string) wp_unslash($_POST['szoveg']) : '';

        // A sanitize_textarea_field a sortörést és a tabulátort megtartja.
        return mb_substr(sanitize_textarea_field($szoveg), 0, 200000);
    }

    /**
     * Az előnézetben kézzel átállított díj-jelölők alkalmazása.
     *
     * @param array<int, array<string, mixed>> $tetelek
     * @return array<int, array<string, mixed>>
     */
    private static function dij_jelolok(array $tetelek): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a hívó ellenőrzi.
        if (empty($_POST['elonezet_kesz'])) {
            return $tetelek;
        }

        $dijak = isset($_POST['dij']) && is_array($_POST['dij']) ? array_map('intval', wp_unslash($_POST['dij'])) : [];
        // phpcs:enable

        foreach ($tetelek as $i => $t) {
            $tetelek[$i]['bevizsgalas'] = in_array($i, $dijak, true) ? 1 : 0;
        }

        return $tetelek;
    }

    /** A popup: szövegdoboz, élő előnézet, beillesztés. */
    public static function ajax_arlista_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }

        ?>
        <h2 class="sdh-modal__cim">Árlista beillesztése</h2>
        <p class="sdh-modal__alcim">Másold be a saját árlistádat (weboldalról, Wordből, Excelből). Soronként egy tétel: megnevezés és ár.
            A „Kategória: 100-” formájú fejlécek alatti tételek bevizsgálási díjak lesznek – az előnézetben átállíthatod.</p>

        <form class="sdh-urlap sdh-arlista" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-sdh-ajax-action="sdh_muhely_szolgaltatas_arlista_ment" data-sdh-arlista>
            <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
            <?php wp_nonce_field('sdh_muhely_szolgaltatas_arlista', 'sdh_nonce'); ?>

            <div class="sdh-arlista__racs">
                <textarea name="szoveg" rows="16" required data-sdh-arlista-szoveg spellcheck="false"
                          aria-label="Az árlista szövege"
                          placeholder="Példa (a saját áraiddal):&#10;Bevizsgálási díjak:&#10;Telefon: 100-&#10;Alap bevizsgálás&#9;5.000 Ft&#10;Sürgősségi felár (000-2)&#9;2.000 Ft&#10;Egyéb szolgáltatások&#10;Szoftver frissítés&#9;3.000 – 6.000 Ft"></textarea>

                <div class="sdh-arlista__elonezet" data-sdh-arlista-elonezet aria-live="polite">
                    <p class="sdh-tabla__halvany">Az előnézet itt jelenik meg, ahogy beilleszted a szöveget.</p>
                </div>
            </div>

            <label class="sdh-jelolo">
                <input type="checkbox" name="dijak_ujra" value="1" checked>
                Ez a teljes díjlista: a benne nem szereplő régi tételekről lekerül a „Bevizsgálási díj” jelölés
            </label>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Beillesztés a szolgáltatások közé</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            </div>
        </form>
        <?php

        wp_die();
    }

    /** Élő előnézet: a szövegből értelmezett tételek táblázata (HTML). */
    public static function ajax_arlista_elonezet(): void
    {
        self::jog_ellenorzes();

        $eredmeny = self::arlista_ertelmez(self::arlista_szoveg());
        $tetelek  = $eredmeny['tetelek'];

        ob_start();

        if ($tetelek === []) {
            echo '<p class="sdh-tabla__halvany">Nem találtam árat tartalmazó sort. Soronként: megnevezés, utána az ár (pl. „Bevizsgálás 9.000 Ft”).</p>';
        } else {
            $dijdb  = count(array_filter($tetelek, static fn (array $t): bool => (int) $t['bevizsgalas'] === 1));
            $uj     = 0;
            $katnev = null;

            foreach ($tetelek as $t) {
                $uj += self::nev_szerint((string) $t['nev']) === null ? 1 : 0;
            }

            ?>
            <input type="hidden" name="elonezet_kesz" value="1">
            <p class="sdh-arlista__osszeg">
                <strong><?php echo count($tetelek); ?></strong> tétel, ebből <strong><?php echo (int) $dijdb; ?></strong> bevizsgálási díj ·
                <?php echo (int) $uj; ?> új, <?php echo count($tetelek) - $uj; ?> frissül
            </p>
            <table class="sdh-tabla sdh-arlista__tabla">
                <thead>
                    <tr><th title="Bevizsgálási díj">Díj</th><th>Megnevezés</th><th>Kód</th><th class="is-jobb">Ár</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tetelek as $i => $t) : ?>
                    <?php if ($t['kategoria'] !== $katnev) : $katnev = $t['kategoria']; ?>
                        <tr class="sdh-arlista__kat"><td colspan="4"><?php echo esc_html($katnev !== '' ? $katnev : 'Kategória nélkül'); ?></td></tr>
                    <?php endif; ?>
                    <tr>
                        <td><input type="checkbox" name="dij[]" value="<?php echo (int) $i; ?>" aria-label="Bevizsgálási díj"
                            <?php checked((int) $t['bevizsgalas'], 1); ?>></td>
                        <td>
                            <?php echo esc_html((string) $t['nev']); ?>
                            <?php if (self::nev_szerint((string) $t['nev']) === null) : ?>
                                <span class="sdh-szolg-dij sdh-szolg-dij--uj">új</span>
                            <?php endif; ?>
                            <?php if ((string) $t['megjegyzes'] !== '') : ?>
                                <div class="sdh-tabla__halvany"><?php echo esc_html((string) $t['megjegyzes']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="sdh-tabla__halvany"><?php echo esc_html((string) $t['kod']); ?></td>
                        <td class="is-jobb"><?php echo esc_html(self::ar_szoveg((float) $t['ar'], (float) $t['ar_max'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }

        if ($eredmeny['kihagyott'] !== []) {
            echo '<p class="sdh-tabla__halvany">Kihagyott sorok (nincs bennük érvényes ár): ' . esc_html(implode(' · ', array_slice($eredmeny['kihagyott'], 0, 8))) . '</p>';
        }

        wp_send_json_success(['html' => (string) ob_get_clean(), 'db' => count($tetelek)]);
    }

    /** Beillesztés: a tételek a törzsbe. */
    public static function ajax_arlista_ment(): void
    {
        check_ajax_referer('sdh_muhely_szolgaltatas_arlista', 'sdh_nonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $tetelek = self::dij_jelolok(self::arlista_ertelmez(self::arlista_szoveg())['tetelek']);

        if ($tetelek === []) {
            wp_send_json_error(['uzenet' => 'Nem találtam árat tartalmazó sort. Soronként: megnevezés, utána az ár (pl. „Bevizsgálás 9.000 Ft”).']);
        }

        $eredmeny = self::arlista_beir($tetelek, !empty($_POST['dijak_ujra']));

        wp_send_json_success($eredmeny + [
            'vissza' => self::vissza(['nezet' => 'arlista', 'uzenet' => 'arlista_betoltve']),
        ]);
    }

    /* =================================================================
     * Árlista nézet: kategóriánként, nyomtatható, az árak helyben írhatók
     * ============================================================== */

    private static function arlista_oldal(): void
    {
        global $wpdb;

        $csak_dij = !empty($_GET['dij']);
        $tabla    = self::tabla();
        $sorok    = $wpdb->get_results(
            "SELECT * FROM {$tabla}" . ($csak_dij ? ' WHERE bevizsgalas = 1' : " WHERE kategoria <> '' OR bevizsgalas = 1 OR ar_max > 0") .
            ' ORDER BY sorrend = 0, sorrend ASC, kategoria ASC, nev ASC'
        );
        $sorok    = is_array($sorok) ? $sorok : [];

        // Kategóriák a beillesztés sorrendjében.
        $csoportok = [];

        foreach ($sorok as $sor) {
            $csoportok[(string) $sor->kategoria !== '' ? (string) $sor->kategoria : 'Egyéb'][] = $sor;
        }

        ?>
        <div class="sdh-wrap sdh-arlista-oldal">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Árlista',
                'A kategóriába sorolt szolgáltatások és a bevizsgálási díjak. Az árak itt helyben átírhatók – a munkalap a díjat innen veszi.',
                self::fejlec_gombok()
            );
            self::nezet_pillek('arlista');
            ?>

            <div class="sdh-kereso sdh-arlista-oldal__eszkozok">
                <a class="sdh-gomb sdh-gomb--vilagos<?php echo $csak_dij ? ' is-aktiv' : ''; ?>"
                   href="<?php echo esc_url(self::url(array_filter(['nezet' => 'arlista', 'dij' => $csak_dij ? null : 1]))); ?>">
                    <?php echo $csak_dij ? 'Teljes árlista' : 'Csak a bevizsgálási díjak'; ?>
                </a>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" onclick="window.print()">Nyomtatás</button>
                <span class="sdh-kereso__talalat"><?php echo esc_html(number_format_i18n(count($sorok))); ?> tétel</span>
            </div>

            <?php if ($sorok === []) : ?>
                <div class="sdh-ures-doboz">
                    <p>Még nincs árlista. Az <strong>Árlista beillesztése</strong> gombbal másold be a saját díjlistádat –
                        a rendszer kategóriánként felveszi a tételeket, a „Kategória: 100-” fejlécűeket bevizsgálási díjként.</p>
                    <a class="sdh-gomb sdh-gomb--elsodleges" href="<?php echo esc_url(self::url(['nezet' => 'arlista'])); ?>"
                       data-sdh-urlap="szolgaltatasarlista" data-sdh-id="0">Árlista beillesztése</a>
                </div>
            <?php else : ?>
                <form class="sdh-arlista-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      data-sdh-arak>
                    <input type="hidden" name="action" value="sdh_muhely_szolgaltatas_arak">
                    <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
                    <?php wp_nonce_field('sdh_muhely_szolgaltatas_arak', 'sdh_nonce'); ?>

                    <?php foreach ($csoportok as $kat => $tagok) : ?>
                        <section class="sdh-arlista-csoport">
                            <h2 class="sdh-arlista-csoport__cim">
                                <?php echo esc_html((string) $kat); ?>
                                <?php
                                $kodok = array_unique(array_filter(array_map(static fn (object $s): string => (string) $s->kod, $tagok), static fn (string $k): bool => (bool) preg_match('/^\d{3}-$/', $k)));
                                if (count($kodok) === 1) {
                                    echo '<span class="sdh-arlista-csoport__kod">' . esc_html((string) reset($kodok)) . '</span>';
                                }
                                ?>
                            </h2>
                            <table class="sdh-tabla sdh-arlista-tabla">
                                <thead>
                                    <tr>
                                        <th class="sdh-arlista-tabla__nev">Megnevezés</th>
                                        <th>Kód</th>
                                        <th class="is-jobb">Ár</th>
                                        <th class="is-jobb" title="Sávos árnál a felső határ">–ig</th>
                                        <th title="Bevizsgálási díj – a munkalapon választható">Díj</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($tagok as $sor) : ?>
                                    <?php
                                    $id    = (int) $sor->id;
                                    $nev   = (string) $sor->nev;
                                    $rovid = $kat !== 'Egyéb' && mb_strpos($nev, $kat . ' – ') === 0 ? mb_substr($nev, mb_strlen($kat) + 3) : $nev;
                                    $mezo  = static fn (float $e): string => $e > 0 ? rtrim(rtrim(number_format($e, 2, '.', ''), '0'), '.') : '';
                                    ?>
                                    <tr>
                                        <td class="sdh-arlista-tabla__nev">
                                            <input type="hidden" name="arak[<?php echo $id; ?>][id]" value="<?php echo $id; ?>">
                                            <a href="<?php echo esc_url(self::url(['nezet' => 'szerkeszt', 'id' => $id])); ?>"
                                               data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>" data-sdh-id="<?php echo $id; ?>"
                                               title="<?php echo esc_attr($nev); ?>"><?php echo esc_html($rovid); ?></a>
                                            <?php if ((string) ($sor->megjegyzes ?? '') !== '') : ?>
                                                <div class="sdh-tabla__halvany"><?php echo esc_html((string) $sor->megjegyzes); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sdh-tabla__halvany"><?php echo esc_html((string) $sor->kod); ?></td>
                                        <td class="is-jobb">
                                            <span class="sdh-arlista-tabla__nyomtat"><?php echo esc_html(self::ar_szoveg((float) $sor->brutto_ar, (float) $sor->ar_max)); ?></span>
                                            <input type="number" min="0" step="any" data-sdh-lepes="500" class="sdh-arlista-tabla__ar"
                                                   name="arak[<?php echo $id; ?>][ar]" aria-label="<?php echo esc_attr($nev . ' – ár'); ?>"
                                                   value="<?php echo esc_attr($mezo((float) $sor->brutto_ar)); ?>">
                                        </td>
                                        <td class="is-jobb">
                                            <input type="number" min="0" step="any" data-sdh-lepes="500" class="sdh-arlista-tabla__ar"
                                                   name="arak[<?php echo $id; ?>][max]" placeholder="—" aria-label="<?php echo esc_attr($nev . ' – ár felső határa'); ?>"
                                                   value="<?php echo esc_attr($mezo((float) $sor->ar_max)); ?>">
                                        </td>
                                        <td>
                                            <input type="checkbox" name="arak[<?php echo $id; ?>][dij]" value="1"
                                                   aria-label="<?php echo esc_attr($nev . ' – bevizsgálási díj'); ?>"
                                                <?php checked((int) $sor->bevizsgalas, 1); ?>>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </section>
                    <?php endforeach; ?>

                    <div class="sdh-arlista-urlap__lablec">
                        <button type="submit" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-arak-ment>Árak mentése</button>
                        <span class="sdh-arlista-urlap__allapot" data-sdh-arak-allapot aria-live="polite"></span>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * A beküldött árak beírása.
     *
     * @return int Ennyi sor változott.
     */
    private static function arak_beir(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a hívó ellenőrzi.
        $arak  = isset($_POST['arak']) && is_array($_POST['arak']) ? wp_unslash($_POST['arak']) : [];
        $tabla = self::tabla();
        $db    = 0;

        foreach ($arak as $sor) {
            if (!is_array($sor) || empty($sor['id'])) {
                continue;
            }

            $id  = (int) $sor['id'];
            $ar  = max(0.0, round(SDH_Muhely_Tetel::szam(sanitize_text_field((string) ($sor['ar'] ?? ''))), 2));
            $max = max(0.0, round(SDH_Muhely_Tetel::szam(sanitize_text_field((string) ($sor['max'] ?? ''))), 2));
            $dij = !empty($sor['dij']) ? 1 : 0;
            $reg = self::egy($id);

            if ($reg === null) {
                continue;
            }

            $uj = ['brutto_ar' => $ar, 'ar_max' => $max > $ar ? $max : 0.0, 'bevizsgalas' => $dij];

            if (abs((float) $reg->brutto_ar - $uj['brutto_ar']) < 0.005
                && abs((float) $reg->ar_max - $uj['ar_max']) < 0.005
                && (int) $reg->bevizsgalas === $dij) {
                continue;
            }

            $uj['modositva'] = current_time('mysql');

            if ($wpdb->update($tabla, $uj, ['id' => $id]) !== false) {
                $db++;
            }
        }

        return $db;
    }

    /** Árak mentése (AJAX: helyben, oldalfrissítés nélkül). */
    public static function ajax_arak(): void
    {
        check_ajax_referer('sdh_muhely_szolgaltatas_arak', 'sdh_nonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $db = self::arak_beir();

        wp_send_json_success(['db' => $db]);
    }

    /** Árak mentése (teljes oldalas tartalék). */
    public static function arak_mentes(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_szolgaltatas_arak', 'sdh_nonce');

        self::arak_beir();

        wp_safe_redirect(self::vissza(['nezet' => 'arlista', 'uzenet' => 'arak_mentve']));
        exit;
    }
}
