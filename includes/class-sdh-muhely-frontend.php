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

    /** Ha ezt léptetjük, a rewrite szabályok újraíródnak. */
    private const REWRITE_VERZIO = '1';

    public static function init(): void
    {
        add_action('init', [self::class, 'szabalyok']);
        add_filter('query_vars', [self::class, 'query_vars']);
        add_action('admin_init', [self::class, 'szabalyok_frissitese']);
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
        if (get_option('sdh_muhely_rewrite_verzio') === self::REWRITE_VERZIO) {
            return;
        }

        self::szabalyok();
        flush_rewrite_rules(false);
        update_option('sdh_muhely_rewrite_verzio', self::REWRITE_VERZIO);
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
                    echo '<div class="sdh-wrap"><div class="sdh-doboz"><p style="margin:0">'
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
                   sötét módban ne villanjon fel a világos felület. */
                (function () {
                    try {
                        var t = localStorage.getItem('sdh-tema') || 'rendszer';
                        var sotet = t === 'sotet' ||
                            (t === 'rendszer' &&
                             window.matchMedia('(prefers-color-scheme: dark)').matches);
                        document.documentElement.setAttribute('data-theme', sotet ? 'dark' : 'light');

                        var sz = localStorage.getItem('sdh-szin');
                        if (sz) { document.documentElement.setAttribute('data-accent', sz); }

                        var s = localStorage.getItem('sdh-sav');
                        if (s === 'csukva') { document.documentElement.dataset.savInit = 'csukva'; }
                    } catch (e) {}
                }());
            </script>

            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/admin.css?v=' . SDH_MUHELY_VERSION); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/app.css?v=' . SDH_MUHELY_VERSION); ?>">
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
                    <a class="sdh-sav__link <?php echo $aktiv_kulcs === $kulcs ? 'sdh-sav__link--aktiv' : ''; ?>"
                       href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url((string) $kulcs)); ?>">
                        <?php echo self::ikon((string) $kulcs); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <span class="sdh-sav__felirat"><?php echo esc_html($modul['cim']); ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>

            <span class="sdh-sav__link sdh-sav__link--keszul" title="Készül">
                <?php echo self::ikon('munkalapok'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <span class="sdh-sav__felirat">Munkalapok</span>
            </span>

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
                            <button type="button" class="sdh-szin" data-ertek=""
                                    style="background:#d4231d" aria-label="SDH piros"></button>
                            <button type="button" class="sdh-szin" data-ertek="kek"
                                    style="background:#2563eb" aria-label="Kék"></button>
                            <button type="button" class="sdh-szin" data-ertek="smaragd"
                                    style="background:#047857" aria-label="Smaragd"></button>
                            <button type="button" class="sdh-szin" data-ertek="ibolya"
                                    style="background:#6d28d9" aria-label="Ibolya"></button>
                            <button type="button" class="sdh-szin" data-ertek="borostyan"
                                    style="background:#b45309" aria-label="Borostyán"></button>
                            <button type="button" class="sdh-szin" data-ertek="palaszurke"
                                    style="background:#475569" aria-label="Palaszürke"></button>
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
        <script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/app.js?v=' . SDH_MUHELY_VERSION); ?>"></script>

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
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/admin.css?v=' . SDH_MUHELY_VERSION); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/app.css?v=' . SDH_MUHELY_VERSION); ?>">
        </head>
        <body class="sdh-app sdh-app--uzenet" style="display:flex">
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
