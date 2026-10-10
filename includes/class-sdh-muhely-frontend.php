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

        // A csak adminos modulok (karbantartás, beállítás) nem részei a
        // napi felületnek.
        if ($modul !== null && !empty($modul['csak_admin'])) {
            $modul = null;
            $kulcs = 'ismeretlen';
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
        $rajzok = [
            'attekintes'   => '<path d="M3 10.5 10 4l7 6.5"/><path d="M5 9.5V16h10V9.5"/>',
            'ugyfelek'     => '<circle cx="7.5" cy="7" r="2.6"/><path d="M3 16c0-2.5 2-4.2 4.5-4.2S12 13.5 12 16"/>'
                . '<path d="M13.2 5.1a2.4 2.4 0 0 1 0 4.3"/><path d="M14 11.9c1.8.4 3 1.9 3 4.1"/>',
            'eszkozok'     => '<rect x="6" y="2.5" width="8" height="15" rx="1.8"/><path d="M8.6 15.2h2.8"/>',
            'munkalapok'   => '<rect x="4" y="3" width="12" height="14" rx="1.8"/><path d="M7 7.5h6M7 10.5h6M7 13.5h3.5"/>',
            'uzenetek'     => '<path d="M3.5 5.5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9l-3.5 3v-3a2 2 0 0 1-2-2z"/><path d="M7 7.6h6M7 10.2h4"/>',
            'levelezes'    => '<rect x="2.8" y="4.5" width="14.4" height="11" rx="1.8"/><path d="M3.4 5.6 10 10.8l6.6-5.2"/>',
            'szolgaltatasok' => '<path d="M12.9 3.1a3.7 3.7 0 0 0-4.5 4.8L3.5 12.8a1.7 1.7 0 0 0 2.4 2.4l4.9-4.9a3.7 3.7 0 0 0 4.8-4.5l-2.3 2.3-1.9-.5-.5-1.9z"/>',
            'termekek'     => '<path d="M10 2.8 16.5 6v8L10 17.2 3.5 14V6z"/><path d="M3.5 6 10 9.2 16.5 6M10 9.2v8M6.7 4.4l6.6 3.2"/>',
            'tac'          => '<ellipse cx="10" cy="5.2" rx="6" ry="2.4"/>'
                . '<path d="M4 5.2v9.6c0 1.3 2.7 2.4 6 2.4s6-1.1 6-2.4V5.2"/><path d="M4 10c0 1.3 2.7 2.4 6 2.4s6-1.1 6-2.4"/>',
            'beallitasok'  => '<circle cx="10" cy="10" r="2.5"/>'
                . '<path d="M10 2.6v2M10 15.4v2M17.4 10h-2M4.6 10h-2M15.2 4.8l-1.4 1.4M6.2 13.8l-1.4 1.4M15.2 15.2l-1.4-1.4M6.2 6.2 4.8 4.8"/>',
        ];

        $rajz = $rajzok[$nev] ?? $rajzok['munkalapok'];

        return '<svg class="sdh-sav__ikon" viewBox="0 0 20 20" aria-hidden="true">' . $rajz . '</svg>';
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
            <title><?php echo esc_html($cim); ?> – SDH Műhely</title>

            <script>
                /* A mentett megjelenés még a stílusok előtt beáll, hogy
                   sötét módban ne villanjon fel a világos felület.
                   Sorrend: amit a dolgozó állított a saját gépén, azután
                   a cég alapértelmezése. */
                (function () {
                    <?php
                    $sdh_arculat = class_exists('SDH_Muhely_Arculat')
                        ? SDH_Muhely_Arculat::beallitas()
                        : ['tema' => 'rendszer', 'szin' => ''];
                    ?>
                    var alapTema = <?php echo wp_json_encode($sdh_arculat['tema']); ?>;
                    var alapSzin = <?php echo wp_json_encode($sdh_arculat['szin']); ?>;

                    try {
                        var t = localStorage.getItem('sdh-tema') || alapTema;
                        var sotet = t === 'sotet' ||
                            (t === 'rendszer' &&
                             window.matchMedia('(prefers-color-scheme: dark)').matches);
                        document.documentElement.setAttribute('data-theme', sotet ? 'dark' : 'light');

                        var sz = localStorage.getItem('sdh-szin');
                        sz = sz === null ? alapSzin : sz;
                        if (sz) { document.documentElement.setAttribute('data-accent', sz); }
                    } catch (e) {}
                }());
            </script>

            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/admin.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/admin.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/app.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/app.css')); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/levelezes.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/levelezes.css')); ?>">
            <?php if ($aktiv_kulcs === 'attekintes') : ?>
                <link rel="stylesheet"
                      href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/racs.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/racs.css')); ?>">
            <?php endif; ?>
        </head>
        <body class="sdh-app">

        <aside class="sdh-sav">
            <a class="sdh-sav__fej" href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url()); ?>">
                <span class="sdh-sav__jel">SDH</span>
                <span class="sdh-sav__nev">Műhely</span>
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
                    <?php $sdh_jelveny = isset($modul['jelveny']) && is_callable($modul['jelveny']) ? (int) call_user_func($modul['jelveny']) : null; ?>
                    <a class="sdh-sav__link <?php echo $aktiv_kulcs === $kulcs ? 'sdh-sav__link--aktiv' : ''; ?>"
                       href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url((string) $kulcs)); ?>">
                        <?php echo self::ikon((string) $kulcs); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <span class="sdh-sav__felirat"><?php echo esc_html($modul['cim']); ?></span>
                        <?php if ($sdh_jelveny !== null) : ?>
                            <span class="sdh-sav__jelveny" data-sdh-jelveny="<?php echo esc_attr((string) $kulcs); ?>"
                                  title="Olvasatlan"<?php echo $sdh_jelveny > 0 ? '' : ' hidden'; ?>><?php echo (int) $sdh_jelveny; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="sdh-sav__also">
                <a class="sdh-sav__link" href="<?php echo esc_url(admin_url('admin.php?page=sdh-muhely')); ?>">
                    <?php echo self::ikon('beallitasok'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <span class="sdh-sav__felirat">Karbantartás</span>
                </a>
            </div>
        </aside>

        <header class="sdh-fej">
            <button type="button" class="sdh-fej__ikongomb" data-sdh-sav
                    aria-label="Menü összecsukása">
                <svg viewBox="0 0 20 20"><path d="M3 5h14M3 10h14M3 15h14"/></svg>
            </button>

            <nav class="sdh-fej__morzsa" aria-label="Hol vagyok">
                <a href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url()); ?>">SDH Műhely</a>
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

                        <p class="sdh-profil__cim">Megjelenés</p>
                        <div class="sdh-valto" data-sdh-tema>
                            <button type="button" data-ertek="rendszer">Rendszer</button>
                            <button type="button" data-ertek="vilagos">Világos</button>
                            <button type="button" data-ertek="sotet">Sötét</button>
                        </div>

                        <p class="sdh-profil__cim">Kiemelő szín</p>
                        <div class="sdh-szinek" data-sdh-szin>
                            <button type="button" class="sdh-szin sdh-szin--alap" data-ertek="" aria-label="SDH piros"></button>
                            <button type="button" class="sdh-szin sdh-szin--kek" data-ertek="kek" aria-label="Kék"></button>
                            <button type="button" class="sdh-szin sdh-szin--smaragd" data-ertek="smaragd" aria-label="Smaragd"></button>
                            <button type="button" class="sdh-szin sdh-szin--ibolya" data-ertek="ibolya" aria-label="Ibolya"></button>
                            <button type="button" class="sdh-szin sdh-szin--borostyan" data-ertek="borostyan" aria-label="Borostyán"></button>
                            <button type="button" class="sdh-szin sdh-szin--palaszurke" data-ertek="palaszurke" aria-label="Palaszürke"></button>
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
            <title>Nincs jogosultság – SDH Műhely</title>
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
