<?php
/**
 * Frontend-váz.
 *
 * Ez a rendszer tényleges felülete: az sdh-muhely.local/muhely/ útvonal
 * alatt egy saját, teljes képernyős alkalmazás fut. Nem használja az
 * aktív sablont, nem tölti be a téma stílusait, és nem látszik rajta a
 * WordPress – ahogy a cél volt: a WordPress legyen láthatatlan háttér.
 *
 * Az elrendezés klasszikus CRM-váz: bal oldalt összecsukható menü,
 * felül morzsamenü, globális kereső és profilmenü, középen a tartalom.
 *
 * Belépés nélkül nincs semmi: a rendszer belső adatokat mutat, ezért a
 * be nem jelentkezett látogatót a bejelentkezésre küldi, a bejelentkezett
 * de jogosulatlan felhasználót pedig udvariasan elutasítja.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Frontend
{
    /** Az útvonal alapja: sdh-muhely.local/muhely/… */
    public const ALAP = 'muhely';

    /** A query var, ami szép URL nélkül is működik. */
    public const QUERY_VAR = 'muhely';

    /**
     * A rewrite szabályok akkor íródnak újra, ha ez eltér a mentettől.
     *
     * A plugin verziójához kötjük: minden frissítés után az első kérésnél
     * újraíródnak, így nem kell kézzel a Közvetlen hivatkozásokat menteni.
     */
    private const REWRITE_VERZIO_ELOTAG = 'v';

    public static function init(): void
    {
        add_action('init', [self::class, 'szabalyok']);
        add_filter('query_vars', [self::class, 'query_vars']);
        // Tartalék útvonal-felismerés: a /muhely/… cím akkor is működik, ha a
        // WordPress tárolt szabálylistájából a mi szabályunk kiesett (pl. egy
        // félbemaradt core-frissítés vagy más bővítmény újraírta a listát).
        add_filter('request', [self::class, 'utvonal_tartalek'], 1);
        // Nem csak adminban: ha a szabály kiesik, a /muhely/ 404-et adna, és
        // admin-oldalt épp az nyitna meg, aki a felületre nem jut be.
        add_action('init', [self::class, 'szabalyok_frissitese'], 99);
        add_action('template_redirect', [self::class, 'fogadas'], 1);
    }

    /* =================================================================
     * Útvonal
     * ============================================================== */

    public static function szabalyok(): void
    {
        add_rewrite_rule(
            '^' . self::ALAP . '/?$',
            'index.php?' . self::QUERY_VAR . '=attekintes',
            'top'
        );

        add_rewrite_rule(
            '^' . self::ALAP . '/([^/]+)/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    /**
     * Ha a rewrite szabály nem illeszkedett, a címet magunk ismerjük fel.
     *
     * A WordPress a nem illeszkedő címre 404-et jelölne; itt ezt a jelölést
     * eldobjuk, és a saját útvonalunkat állítjuk be helyette. Így a felület
     * elérhetősége nem függ a szabálylista épségétől.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public static function utvonal_tartalek(array $vars): array
    {
        if (!empty($vars[self::QUERY_VAR])) {
            return $vars;
        }

        $kulcs = self::kulcs_a_cimbol(
            isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '',
            (string) wp_parse_url(home_url('/'), PHP_URL_PATH)
        );

        return $kulcs === null ? $vars : [self::QUERY_VAR => $kulcs];
    }

    /**
     * A kért címből a modulkulcs, vagy null, ha a cím nem a miénk.
     *
     * Külön függvény, hogy WordPress nélkül is tesztelhető legyen.
     * Az alkönyvtárba telepített WordPresst (pl. /wp/muhely/) is kezeli.
     */
    public static function kulcs_a_cimbol(string $keres, string $alap_ut): ?string
    {
        $ut = (string) parse_url($keres, PHP_URL_PATH);
        $alap_ut = '/' . trim($alap_ut, '/');
        $alap_ut = $alap_ut === '/' ? '' : $alap_ut;

        if ($alap_ut !== '' && !str_starts_with($ut, $alap_ut . '/')) {
            return null;
        }

        $ut = substr($ut, strlen($alap_ut));

        if (preg_match('#^/' . preg_quote(self::ALAP, '#') . '(?:/([^/]+))?/?$#', $ut, $talalat) !== 1) {
            return null;
        }

        $kulcs = isset($talalat[1]) ? strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $talalat[1]) ?? '') : '';

        return $kulcs === '' ? 'attekintes' : $kulcs;
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function query_vars(array $vars): array
    {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    /**
     * A rewrite szabályok egyszeri frissítése.
     */
    public static function szabalyok_frissitese(): void
    {
        $cel = self::REWRITE_VERZIO_ELOTAG . SDH_MUHELY_VERSION;

        // Kétszeres védelem: a mentett verzió egyezik, és a szabály tényleg
        // ott van a WordPress listájában. Ha a lista máshol íródott felül,
        // a szabály hiánya is újraíratja.
        // A pihenő megakadályozza, hogy egy váratlanul mindig hiányzó
        // szabály minden kérésnél újraírja a listát: óránként egyszer próbál.
        if (
            get_option('sdh_muhely_rewrite_verzio') === $cel
            && (self::szabaly_megvan() || get_transient('sdh_muhely_rewrite_pihen'))
        ) {
            return;
        }

        self::szabalyok();
        flush_rewrite_rules(false);
        update_option('sdh_muhely_rewrite_verzio', $cel);
        set_transient('sdh_muhely_rewrite_pihen', 1, HOUR_IN_SECONDS);
    }

    /** Benne van-e a /muhely/ szabály a tárolt szabálylistában. */
    private static function szabaly_megvan(): bool
    {
        $szabalyok = get_option('rewrite_rules');

        // Szép URL nélkül nincs szabálylista, a ?muhely= link úgyis megy.
        if (!is_array($szabalyok)) {
            return true;
        }

        return isset($szabalyok['^' . self::ALAP . '/?$']);
    }

    /* =================================================================
     * Megjelenítés
     * ============================================================== */

    public static function fogadas(): void
    {
        $kulcs = get_query_var(self::QUERY_VAR);

        if ($kulcs === '' || $kulcs === null) {
            return;
        }

        $kulcs = sanitize_key((string) $kulcs);

        SDH_Muhely_Modulok::kontextus_beallit('frontend');

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::aktualis_url()));
            exit;
        }

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            self::tiltott_oldal();
            exit;
        }

        // A frontend nem jár az admin_init-en, ahol a sémafrissítés különben
        // lefut: itt pótoljuk, még a tartalom előtt, hogy fájlcsere után az
        // első megnyitott oldal már az új oszlopokkal dolgozzon.
        SDH_Muhely_Schema::frissites_ha_kell();

        $modul = $kulcs === 'attekintes' ? null : SDH_Muhely_Modulok::egy($kulcs);

        // A csak adminisztrátornak szóló modul (Beállítások) is a műhely-felületen
        // fut – a wp-adminba innen semmi nem visz át –, de kolléga nem nyithatja meg.
        if ($modul !== null && !empty($modul['csak_admin']) && !SDH_Muhely_Admin_UI::admin_e()) {
            self::tiltott_oldal();
            exit;
        }

        if ($kulcs !== 'attekintes' && $modul === null) {
            status_header(404);
            self::keret(
                'Nincs ilyen oldal',
                '',
                static function (): void {
                    echo '<div class="sdh-wrap"><div class="sdh-doboz"><p class="sdh-sugo sdh-sugo--utolso">'
                        . 'Ez a modul nem létezik. Nézd meg az URL-t, vagy válassz '
                        . 'a bal oldali menüből.</p></div></div>';
                }
            );
            exit;
        }

        status_header(200);
        nocache_headers();

        if ($modul === null) {
            self::keret(
                'Áttekintés',
                'attekintes',
                static function (): void {
                    SDH_Muhely_Admin_UI::attekintes_tartalom(false);
                }
            );
        } else {
            self::keret((string) $modul['cim'], $kulcs, $modul['render']);
        }

        exit;
    }

    private static function aktualis_url(): string
    {
        $sema = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $ut   = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';

        return esc_url_raw($sema . $host . $ut);
    }

    /* =================================================================
     * Ikonok
     * ============================================================== */

    /**
     * Vonalas ikonok a menühöz.
     *
     * Saját rajz, nem ikonkészlet: egyrészt nincs külső függőség, ami
     * internet nélkül elakadna, másrészt így mind egy vonalvastagságon
     * van, és nem kell három készletből válogatni.
     */
    public static function ikon(string $nev): string
    {
        return '<svg class="sdh-sav__ikon" viewBox="0 0 20 20" aria-hidden="true">' . self::ikon_rajz($nev) . '</svg>';
    }

    /**
     * Egy ikon rajza (az <svg> belseje, 20×20-as vásznon). A menü, a csempék és
     * a gombok (SDH_Muhely_Admin_UI::gomb_ikon) ugyanabból a készletből rajzolnak.
     * Ismeretlen névre – ha nincs megadva tartalék – a munkalap ikonja jön.
     */
    public static function ikon_rajz(string $nev, ?string $tartalek = 'munkalapok'): string
    {
        $rajzok = [
            'attekintes'   => '<path d="M3 10.5 10 4l7 6.5"/><path d="M5 9.5V16h10V9.5"/>',
            'ugyfelek'     => '<circle cx="7.5" cy="7" r="2.6"/><path d="M3 16c0-2.5 2-4.2 4.5-4.2S12 13.5 12 16"/>'
                . '<path d="M13.2 5.1a2.4 2.4 0 0 1 0 4.3"/><path d="M14 11.9c1.8.4 3 1.9 3 4.1"/>',
            'eszkozok'     => '<rect x="6" y="2.5" width="8" height="15" rx="1.8"/><path d="M8.6 15.2h2.8"/>',
            'munkalapok'   => '<rect x="4" y="3" width="12" height="14" rx="1.8"/><path d="M7 7.5h6M7 10.5h6M7 13.5h3.5"/>',
            'arukiadas'    => '<path d="M5.2 7h9.6l.9 9.2H4.3z"/><path d="M7.6 9.2V6.4a2.4 2.4 0 0 1 4.8 0v2.8"/>',
            'rendelesek'   => '<path d="M2.5 5h8.8v8.4H2.5z"/><path d="M11.3 8h3.3l2.9 2.7v2.7h-6.2"/><circle cx="5.9" cy="14.4" r="1.6"/><circle cx="14.1" cy="14.4" r="1.6"/>',
            'uzenetek'     => '<path d="M3.5 5.5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9l-3.5 3v-3a2 2 0 0 1-2-2z"/><path d="M7 7.6h6M7 10.2h4"/>',
            'levelezes'    => '<rect x="2.8" y="4.5" width="14.4" height="11" rx="1.8"/><path d="M3.4 5.6 10 10.8l6.6-5.2"/>',
            'penztar'      => '<rect x="2.5" y="5.5" width="15" height="9" rx="1.6"/><circle cx="10" cy="10" r="2.1"/><path d="M5.2 8.2v3.6M14.8 8.2v3.6"/>',
            'szolgaltatasok' => '<path d="M12.9 3.1a3.7 3.7 0 0 0-4.5 4.8L3.5 12.8a1.7 1.7 0 0 0 2.4 2.4l4.9-4.9a3.7 3.7 0 0 0 4.8-4.5l-2.3 2.3-1.9-.5-.5-1.9z"/>',
            'termekek'     => '<path d="M10 2.8 16.5 6v8L10 17.2 3.5 14V6z"/><path d="M3.5 6 10 9.2 16.5 6M10 9.2v8M6.7 4.4l6.6 3.2"/>',
            'tac'          => '<ellipse cx="10" cy="5.2" rx="6" ry="2.4"/>'
                . '<path d="M4 5.2v9.6c0 1.3 2.7 2.4 6 2.4s6-1.1 6-2.4V5.2"/><path d="M4 10c0 1.3 2.7 2.4 6 2.4s6-1.1 6-2.4"/>',
            'naptar'       => '<rect x="3" y="4.2" width="14" height="12.8" rx="1.9"/><path d="M3 8.4h14M6.8 2.6v3.2M13.2 2.6v3.2"/>'
                . '<path d="M6.6 11.4h1.6M9.2 11.4h1.6M11.8 11.4h1.6M6.6 14h1.6M9.2 14h1.6"/>',
            'csapat'       => '<path d="M2.8 6.5a2 2 0 0 1 2-2h6.4a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H7.6L5 14.6v-2.1h-.2a2 2 0 0 1-2-2z"/>'
                . '<path d="M13.2 8.2h2a2 2 0 0 1 2 2v3.6a2 2 0 0 1-2 2h-.2v2l-2.6-2h-2.6a2 2 0 0 1-1.6-.8"/>',
            'asszisztens'  => '<path d="M10 3.2c3.6 0 6.3 2.4 6.3 5.9 0 3.6-2.7 6.2-6.3 6.2-1.1 0-2.1-.2-3-.6L3.8 16l.9-2.9C3.9 12 3.7 10.6 3.7 9.1c0-3.5 2.7-5.9 6.3-5.9z"/>'
                . '<circle cx="7.8" cy="9" r=".9"/><circle cx="12.2" cy="9" r=".9"/><path d="M8.3 11.6c1 .7 2.4.7 3.4 0"/>',
            'beallitasok'  => '<circle cx="10" cy="10" r="2.5"/>'
                . '<path d="M10 2.6v2M10 15.4v2M17.4 10h-2M4.6 10h-2M15.2 4.8l-1.4 1.4M6.2 13.8l-1.4 1.4M15.2 15.2l-1.4-1.4M6.2 6.2 4.8 4.8"/>',

            'sugo'         => '<circle cx="10" cy="10" r="6.8"/><path d="M8 8.1a2.1 2.1 0 1 1 2.9 1.9c-.6.3-.9.7-.9 1.3"/><path d="M10 13.8v.1"/>',

            // --- Gombok (nem modulok) ---
            'plusz'        => '<path d="M10 4.2v11.6M4.2 10h11.6"/>',
            'uj-munkalap'  => '<path d="M14.5 8.6V4.8A1.8 1.8 0 0 0 12.7 3H5.8A1.8 1.8 0 0 0 4 4.8v10.4A1.8 1.8 0 0 0 5.8 17h3.4"/>'
                . '<path d="M7 7h4.6M7 10h2.6"/><path d="M14.4 11.4v6M11.4 14.4h6"/>',
            'uj-ugyfel'    => '<circle cx="8" cy="6.8" r="2.9"/><path d="M2.8 16.4c0-2.8 2.3-4.8 5.2-4.8 1 0 1.9.2 2.7.6"/>'
                . '<path d="M14.8 11.4v6M11.8 14.4h6"/>',
            'csempek'      => '<rect x="3.4" y="3.4" width="5.6" height="5.6" rx="1.4"/><rect x="11" y="3.4" width="5.6" height="5.6" rx="1.4"/>'
                . '<rect x="3.4" y="11" width="5.6" height="5.6" rx="1.4"/><rect x="11" y="11" width="5.6" height="5.6" rx="1.4"/>',
            'ceruza'       => '<path d="M3.8 16.2l.8-3.4 8.3-8.3a1.7 1.7 0 0 1 2.4 0l.2.2a1.7 1.7 0 0 1 0 2.4l-8.3 8.3z"/><path d="M11.6 5.8l2.6 2.6"/>',
            'ora'          => '<circle cx="10" cy="10" r="6.8"/><path d="M10 6.2V10l2.6 1.7"/>',
            'kulso'        => '<path d="M8.2 4.6H5.4a1.8 1.8 0 0 0-1.8 1.8v8.2a1.8 1.8 0 0 0 1.8 1.8h8.2a1.8 1.8 0 0 0 1.8-1.8v-2.8"/><path d="M11.4 3.6h5v5M16.2 3.8 9.6 10.4"/>',
            'kereses'      => '<circle cx="9" cy="9" r="5.2"/><path d="M13 13l3.6 3.6"/>',
            'lista'        => '<path d="M6.8 5.5h9.4M6.8 10h9.4M6.8 14.5h9.4"/><circle cx="3.9" cy="5.5" r=".7"/><circle cx="3.9" cy="10" r=".7"/><circle cx="3.9" cy="14.5" r=".7"/>',
            'helyszin'     => '<path d="M10 17.2s5.2-4.7 5.2-8.7a5.2 5.2 0 0 0-10.4 0c0 4 5.2 8.7 5.2 8.7z"/><circle cx="10" cy="8.4" r="1.9"/>',
        ];

        if (isset($rajzok[$nev])) {
            return $rajzok[$nev];
        }

        return $tartalek !== null && isset($rajzok[$tartalek]) ? $rajzok[$tartalek] : '';
    }

    /* =================================================================
     * Keret
     * ============================================================== */

    /**
     * A teljes oldal: oldalsáv, fejléc, tartalom.
     *
     * @param callable $tartalom A modul megjelenítő függvénye.
     */
    public static function keret(string $cim, string $aktiv_kulcs, callable $tartalom): void
    {
        $felhasznalo = wp_get_current_user();
        $nev         = $felhasznalo->display_name ?: $felhasznalo->user_login;
        $kezdo       = mb_strtoupper(mb_substr($nev, 0, 1));

        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title><?php echo esc_html($cim . ' – ' . SDH_Muhely_Arculat::rendszer_nev()); ?></title>

            <script>
                /* A mentett megjelenés még a stílusok előtt beáll, hogy
                   sötét módban ne villanjon fel a világos felület.
                   Sorrend: amit a dolgozó állított a saját gépén, azután
                   a cég alapértelmezése. */
                (function () {
                    <?php
                    $sdh_arculat = class_exists('SDH_Muhely_Arculat')
                        ? SDH_Muhely_Arculat::beallitas()
                        : ['tema' => 'rendszer', 'szin' => '', 'uveg' => 'mono', 'nagyitas' => 100, 'kekfeny' => 0];
                    ?>
                    var alapKek = <?php echo (int) ($sdh_arculat['kekfeny'] ?? 0); ?>;
                    var alapTema = <?php echo wp_json_encode($sdh_arculat['tema']); ?>;
                    var alapSzin = <?php echo wp_json_encode($sdh_arculat['szin']); ?>;
                    var alapUveg = <?php echo wp_json_encode((string) ($sdh_arculat['uveg'] ?? 'mono')); ?>;
                    var alapNagy = <?php echo (int) ($sdh_arculat['nagyitas'] ?? 100); ?>;

                    try {
                        var t = localStorage.getItem('sdh-tema') || alapTema;
                        var sotet = t === 'sotet' ||
                            (t === 'rendszer' &&
                             window.matchMedia('(prefers-color-scheme: dark)').matches);
                        document.documentElement.setAttribute('data-theme', sotet ? 'dark' : 'light');

                        // Papír megjelenés (e-könyv-szerű) és a kékfény-szűrő: szintén a festés előtt.
                        if (t === 'papir') { document.documentElement.setAttribute('data-papir', '1'); }

                        var kf = localStorage.getItem('sdh-kekfeny');
                        kf = kf === null ? alapKek : (parseInt(kf, 10) || 0);
                        if (kf > 0) { document.documentElement.setAttribute('data-kekfeny', String(Math.min(100, kf))); }

                        var sz = localStorage.getItem('sdh-szin');
                        sz = sz === null ? alapSzin : sz;
                        if (sz) { document.documentElement.setAttribute('data-accent', sz); }

                        // Üvegszín (duó / egyszínű) és a betűnagyítás: szintén a festés előtt.
                        var uv = localStorage.getItem('sdh-uveg') || alapUveg;
                        document.documentElement.setAttribute('data-uveg', uv === 'duo' ? 'duo' : 'mono');

                        var n = parseInt(localStorage.getItem('sdh-nagyitas'), 10) || alapNagy;
                        n = Math.max(<?php echo (int) SDH_Muhely_Arculat::NAGYITAS_MIN; ?>, Math.min(<?php echo (int) SDH_Muhely_Arculat::NAGYITAS_MAX; ?>, n));
                        document.documentElement.style.setProperty('--sdh-n', String(n / 100));
                    } catch (e) {
                        if (alapTema === 'papir') { document.documentElement.setAttribute('data-papir', '1'); }
                        if (alapKek > 0) { document.documentElement.setAttribute('data-kekfeny', String(alapKek)); }
                        document.documentElement.setAttribute('data-uveg', alapUveg === 'duo' ? 'duo' : 'mono');
                        document.documentElement.style.setProperty('--sdh-n', String(alapNagy / 100));
                    }
                }());
            </script>

            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/admin.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/admin.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/app.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/app.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/levelezes.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/levelezes.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/penztar.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/penztar.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/csapat.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/csapat.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/naptar.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/naptar.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/asszisztens.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/asszisztens.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/sugo.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/sugo.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/nyomtatas.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/nyomtatas.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/sms.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/sms.css')); ?>">
            <?php if ($aktiv_kulcs === 'attekintes') : ?>
                <link rel="stylesheet"
                      href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/racs.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/racs.css')); ?>">
            <?php endif; ?>
            <?php // Az Arculat beállításából adódó stílus (egyéni kiemelő szín, lekerekítés) – a stíluslapok után. ?>
            <?php if (SDH_Muhely_Arculat::stilus_css() !== '') : ?>
                <style id="sdh-arculat"><?php echo SDH_Muhely_Arculat::stilus_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- csak ellenőrzött színkódok és rögzített értékek. ?></style>
            <?php endif; ?>
        </head>
        <?php $sdh_arc = SDH_Muhely_Arculat::beallitas(); ?>
        <body class="sdh-app" data-sdh-modul="<?php echo esc_attr($aktiv_kulcs); ?>" data-sdh-sav-stilus="<?php echo esc_attr($sdh_arc['sav']); ?>">

        <?php
        // Kékfény-szűrő: a gyökérelem ezt a színszűrőt kapja (admin.css: :root[data-kekfeny]). A kék
        // csatornát erősen, a zöldet enyhén veszi vissza; az erősséget az app.js (kekfenyAlkalmaz) írja be.
        ?>
        <svg class="sdh-kekfeny-szuro" width="0" height="0" aria-hidden="true" focusable="false">
            <filter id="sdh-kekfeny-szuro" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 1 0"/>
            </filter>
        </svg>
        <script>
            /* A szűrő erőssége még a kirajzolás előtt beáll, hogy az oldal ne villanjon fel hidegen. */
            (function () {
                var k = (parseInt(document.documentElement.getAttribute('data-kekfeny'), 10) || 0) / 100;
                var m = document.querySelector('#sdh-kekfeny-szuro feColorMatrix');

                if (m && k > 0) {
                    m.setAttribute('values', '1 0 0 0 0  0 ' + (1 - .22 * k).toFixed(3) + ' 0 0 0  0 0 ' + (1 - .62 * k).toFixed(3) + ' 0 0  0 0 0 1 0');
                }
            }());
        </script>

        <aside class="sdh-sav">
            <a class="sdh-sav__fej" href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url()); ?>" title="<?php echo esc_attr(SDH_Muhely_Arculat::rendszer_nev()); ?>">
                <?php // Logó (Arculat), enélkül a színes jel néhány betűvel; mellette a név. ?>
                <?php if ($sdh_arc['logo'] !== '') : ?>
                    <img class="sdh-sav__logo" src="<?php echo esc_url($sdh_arc['logo']); ?>" alt="<?php echo esc_attr(SDH_Muhely_Arculat::rendszer_nev()); ?>">
                <?php elseif ($sdh_arc['jel'] !== '') : ?>
                    <span class="sdh-sav__jel"><?php echo esc_html($sdh_arc['jel']); ?></span>
                <?php endif; ?>
                <?php if ($sdh_arc['nev'] !== '') : ?>
                    <span class="sdh-sav__nev"><?php echo esc_html($sdh_arc['nev']); ?></span>
                <?php endif; ?>
            </a>

            <a class="sdh-sav__link <?php echo $aktiv_kulcs === 'attekintes' ? 'sdh-sav__link--aktiv' : ''; ?>"
               href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url()); ?>">
                <?php echo self::ikon('attekintes'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <span class="sdh-sav__felirat">Áttekintés</span>
            </a>

            <p class="sdh-sav__cim">Nyilvántartás</p>

            <?php foreach (SDH_Muhely_Modulok::osszes() as $kulcs => $modul) : ?>
                <?php if (!empty($modul['csak_admin'])) { continue; } ?>

                <?php if (!empty($modul['keszul'])) : ?>
                    <span class="sdh-sav__link sdh-sav__link--keszul" title="Készül">
                        <?php echo self::ikon((string) $kulcs); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <span class="sdh-sav__felirat"><?php echo esc_html($modul['cim']); ?></span>
                    </span>
                <?php else : ?>
                    <?php
                    $sdh_jelveny = isset($modul['jelveny']) && is_callable($modul['jelveny']) ? (int) call_user_func($modul['jelveny']) : null;
                    // „Új" jelzés (pl. Levelezés): nem darabszámot mutat arról, mi van bent, hanem azt,
                    // hogy érkezett valami – és több forrásnál (postafióknál) azt is, hová.
                    $sdh_jelzes  = isset($modul['menu_jelzes']) && is_callable($modul['menu_jelzes']) ? (array) call_user_func($modul['menu_jelzes']) : null;
                    ?>
                    <a class="sdh-sav__link <?php echo $aktiv_kulcs === $kulcs ? 'sdh-sav__link--aktiv' : ''; ?>"
                       href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url((string) $kulcs)); ?>">
                        <?php echo self::ikon((string) $kulcs); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <span class="sdh-sav__felirat"><?php echo esc_html($modul['cim']); ?></span>
                        <?php if ($sdh_jelzes !== null) : ?>
                            <span class="sdh-sav__jelveny sdh-sav__jelveny--uj" data-sdh-jelveny="<?php echo esc_attr((string) $kulcs); ?>" data-sdh-jelveny-mod="uj"
                                  data-db="<?php echo (int) $sdh_jelzes['db']; ?>" title="<?php echo esc_attr((string) $sdh_jelzes['cim']); ?>"<?php echo (int) $sdh_jelzes['db'] > 0 ? '' : ' hidden'; ?>><?php echo esc_html((string) $sdh_jelzes['szoveg']); ?></span>
                        <?php elseif ($sdh_jelveny !== null) : ?>
                            <span class="sdh-sav__jelveny" data-sdh-jelveny="<?php echo esc_attr((string) $kulcs); ?>"
                                  data-db="<?php echo (int) $sdh_jelveny; ?>" title="<?php echo esc_attr((string) (int) $sdh_jelveny); ?> olvasatlan"<?php echo $sdh_jelveny > 0 ? '' : ' hidden'; ?>><?php echo $sdh_jelveny > 999 ? '999+' : (int) $sdh_jelveny; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php if ($sdh_jelzes !== null) : ?>
                        <?php // Forrásonként (postafiókonként) egy sor – csak ott, ahol van új. A levelezes.js helyben frissíti. ?>
                        <div class="sdh-sav__alsorok" data-sdh-alsorok="<?php echo esc_attr((string) $kulcs); ?>"<?php echo $sdh_jelzes['alsorok'] === [] ? ' hidden' : ''; ?>>
                            <?php foreach ((array) $sdh_jelzes['alsorok'] as $sdh_alsor) : ?>
                                <a class="sdh-sav__alsor" href="<?php echo esc_url((string) $sdh_alsor['url']); ?>" title="<?php echo esc_attr((string) $sdh_alsor['cim']); ?>">
                                    <span class="sdh-sav__alsor-nev"><?php echo esc_html((string) $sdh_alsor['nev']); ?></span>
                                    <b class="sdh-sav__alsor-db"><?php echo esc_html((string) $sdh_alsor['szoveg']); ?></b>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php // A Beállítások (adminisztrátornak) a műhely-felületen nyílik: innen semmi nem visz a wp-adminba. ?>
            <?php if (SDH_Muhely_Admin_UI::admin_e() && class_exists('SDH_Muhely_Beallitasok')) : ?>
            <div class="sdh-sav__also">
                <?php $sdh_aktiv_modul = SDH_Muhely_Modulok::egy($aktiv_kulcs); ?>
                <a class="sdh-sav__link <?php echo $aktiv_kulcs === SDH_Muhely_Beallitasok::KULCS || ($sdh_aktiv_modul !== null && !empty($sdh_aktiv_modul['rejtett'])) ? 'sdh-sav__link--aktiv' : ''; ?>"
                   href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url(SDH_Muhely_Beallitasok::KULCS)); ?>">
                    <?php echo self::ikon('beallitasok'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <span class="sdh-sav__felirat">Beállítások</span>
                </a>
            </div>
            <?php endif; ?>
        </aside>

        <header class="sdh-fej">
            <button type="button" class="sdh-fej__ikongomb" data-sdh-sav
                    aria-label="Menü összecsukása">
                <svg viewBox="0 0 20 20"><path d="M3 5h14M3 10h14M3 15h14"/></svg>
            </button>

            <nav class="sdh-fej__morzsa" aria-label="Hol vagyok">
                <a href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url()); ?>"><?php echo esc_html(SDH_Muhely_Arculat::rendszer_nev()); ?></a>
                <span>/</span>
                <strong><?php echo esc_html($cim); ?></strong>
            </nav>

            <div class="sdh-kutat" data-sdh-kutat>
                <input type="search" class="sdh-kutat__mezo" autocomplete="off"
                       placeholder="Ügyfél, telefon, IMEI, gyári szám…"
                       aria-label="Keresés az egész rendszerben">
                <span class="sdh-kutat__jel">/</span>
                <ul class="sdh-kutat__lista" hidden></ul>
            </div>

            <div class="sdh-fej__jobb">
                <?php
                // Az óra és a zárásig hátralévő idő minden oldalon a fejlécben áll (Beállítások › Nyitvatartás és óra).
                if (class_exists('SDH_Muhely_Nyitvatartas')) {
                    echo SDH_Muhely_Nyitvatartas::ora_html('fej'); // phpcs:ignore WordPress.Security.EscapeOutput -- szűrt HTML.
                }

                // Nyomtatás: bianko munkalap, pénztárzárás, pénztárjelentés, listák – egy helyről.
                if (class_exists('SDH_Muhely_Nyomtatas')) {
                    echo '<button type="button" class="sdh-fej__ikongomb sdh-fej__nyomtat" data-sdh-nyomtat="0" title="Nyomtatás: üres munkalap, pénztárzárás, listák" aria-label="Nyomtatás">'
                        . '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 7V3h9v4"/><rect x="3" y="7" width="14" height="7" rx="1.6"/><path d="M5.5 12h9v5h-9z"/></svg></button>';
                }

                // Súgó ehhez az oldalhoz: az oldalt leíró fejezet (ha nincs ilyen, a Súgó kezdőlapja).
                if (class_exists('SDH_Muhely_Sugo') && $aktiv_kulcs !== SDH_Muhely_Sugo::KULCS) {
                    $sdh_sugo_fejezet = SDH_Muhely_Sugo::modul_fejezet((string) $aktiv_kulcs);
                    printf(
                        '<a class="sdh-fej__ikongomb sdh-fej__sugo" href="%s" title="%s" aria-label="%s">%s</a>',
                        esc_url(SDH_Muhely_Modulok::frontend_url(SDH_Muhely_Sugo::KULCS, $sdh_sugo_fejezet !== '' ? ['nezet' => 'dokumentacio', 'fejezet' => $sdh_sugo_fejezet] : [])),
                        esc_attr($sdh_sugo_fejezet !== '' ? 'Súgó ehhez az oldalhoz' : 'Súgó'),
                        esc_attr($sdh_sugo_fejezet !== '' ? 'Súgó ehhez az oldalhoz' : 'Súgó'),
                        '<svg viewBox="0 0 20 20" aria-hidden="true">' . self::ikon_rajz('sugo', null) . '</svg>' // phpcs:ignore WordPress.Security.EscapeOutput -- saját SVG.
                    );
                }
                ?>
                <div class="sdh-profil" data-sdh-profil>
                    <button type="button" class="sdh-profil__gomb" aria-expanded="false">
                        <span class="sdh-profil__kezdo"><?php echo esc_html($kezdo); ?></span>
                        <span><?php echo esc_html($nev); ?></span>
                    </button>

                    <div class="sdh-profil__panel" hidden>
                        <p class="sdh-profil__nev">
                            <?php echo esc_html($nev); ?>
                            <span><?php echo esc_html($felhasznalo->user_email); ?></span>
                        </p>

                        <?php // Megjelenés (Rendszer / Világos / Sötét) és Üvegszín (Duó, Egyszínű) – a dolgozó saját gépére szól. ?>
                        <?php echo SDH_Muhely_Arculat::megjelenes_valaszto('profil'); // phpcs:ignore WordPress.Security.EscapeOutput -- szűrt HTML. ?>

                        <p class="sdh-profil__cim">Betűméret</p>
                        <?php echo SDH_Muhely_Arculat::nagyito('sajat'); // phpcs:ignore WordPress.Security.EscapeOutput -- szűrt HTML. ?>

                        <p class="sdh-profil__cim">Popup neon keret</p>
                        <div class="sdh-szinek" data-sdh-neon>
                            <?php foreach (SDH_Muhely_Arculat::neonok() as $sdh_neon_kulcs => $sdh_neon_nev) : ?>
                                <?php
                                // Az egyéni szín csak akkor választható, ha az Arculatban megadták a kódját.
                                if ($sdh_neon_kulcs === 'egyeni' && SDH_Muhely_Arculat::beallitas()['neon_egyeni'] === '') {
                                    continue;
                                }
                                ?>
                                <button type="button"
                                        class="sdh-szin sdh-szin--neon sdh-szin--neon-<?php echo esc_attr($sdh_neon_kulcs !== '' ? $sdh_neon_kulcs : 'ki'); ?>"
                                        data-ertek="<?php echo esc_attr($sdh_neon_kulcs); ?>"
                                        title="<?php echo esc_attr($sdh_neon_nev); ?>"
                                        aria-label="<?php echo esc_attr($sdh_neon_nev); ?>"></button>
                            <?php endforeach; ?>
                        </div>

                        <a class="sdh-profil__kilep"
                           href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Kijelentkezés</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="sdh-app__torzs">
            <?php call_user_func($tartalom); ?>
        </main>

        <script>
            window.SDH_MUHELY = <?php
                echo wp_json_encode(SDH_Muhely_Admin_UI::js_beallitas('frontend'));
            ?>;
        </script>
        <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/app.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/app.js')); ?>"></script>
        <?php if ($aktiv_kulcs === 'attekintes') : ?>
            <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/racs.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/racs.js')); ?>"></script>
        <?php endif; ?>
        <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/rma.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/rma.js')); ?>"></script>
        <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/levelezes.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/levelezes.js')); ?>"></script>
        <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/penztar.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/penztar.js')); ?>"></script>
        <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/eladas.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/eladas.js')); ?>"></script>
        <?php // Nyomtatás: a Nyomtatás ablak és a felajánlás bármelyik oldalon megjelenhet. ?>
        <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/nyomtatas.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/nyomtatas.js')); ?>"></script>
        <?php // SMS: a „Küldjek SMS-t?” popup státuszváltás után, bármelyik oldalon. ?>
        <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/sms.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/sms.js')); ?>"></script>
        <?php if ($aktiv_kulcs === 'beallitasok') : ?>
            <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/beallitasok.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/beallitasok.js')); ?>"></script>
        <?php endif; ?>
        <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/csapat.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/csapat.js')); ?>"></script>
        <?php // Naptár: az esedékes események kártyái minden oldalon megjelennek (a pulzusból), ezért mindenhol betölt. ?>
        <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/naptar.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/naptar.js')); ?>"></script>
        <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/asszisztens.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/asszisztens.js')); ?>"></script>
        <?php if ($aktiv_kulcs === 'sugo') : ?>
            <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/sugo.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/sugo.js')); ?>"></script>
        <?php endif; ?>

        </body>
        </html>
        <?php
    }

    /* =================================================================
     * Elutasítás
     * ============================================================== */

    private static function tiltott_oldal(): void
    {
        status_header(403);

        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title><?php echo esc_html("Nincs jogosultság – " . SDH_Muhely_Arculat::rendszer_nev()); ?></title>
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/admin.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/admin.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/app.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/app.css')); ?>">
        </head>
        <body class="sdh-app sdh-app--uzenet" >
            <div class="sdh-app__kozep">
                <h1>Ehhez nincs jogosultságod.</h1>
                <p>A fiókod be van jelentkezve, de nem fér hozzá a műhelyrendszerhez.
                   Szólj az adminisztrátornak.</p>
                <a class="sdh-gomb"
                   href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Kijelentkezés</a>
            </div>
        </body>
        </html>
        <?php
    }
}
