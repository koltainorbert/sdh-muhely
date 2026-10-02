<?php
/**
 * Frontend-váz.
 *
 * Ez a rendszer tényleges felülete: az sdh-muhely.local/muhely/ útvonal
 * alatt egy saját, teljes képernyős alkalmazás fut. Nem használja az
 * aktív sablont, nem tölti be a téma stílusait, és nem látszik rajta a
 * WordPress – ahogy a cél volt: a WordPress legyen láthatatlan háttér.
 *
 * Belépés nélkül nincs semmi: a rendszer belső adatokat mutat, ezért a
 * be nem jelentkezett látogatót a bejelentkezésre küldi, a bejelentkezett
 * de jogosulatlan felhasználót pedig udvariasan elutasítja.
 *
 * Miért nem shortcode vagy oldal: egy WordPress-oldal a téma sablonját
 * használná, és a napi munka a téma fejlécével, menüjével, lábléce
 * között zajlana. Ez saját útvonal saját kimenettel – a sablonból semmi
 * nem szól bele.
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
     *
     * Enélkül a /muhely/ útvonal csak akkor élne, ha a plugint
     * újraaktiválod vagy elmented a Beállítások → Közvetlen hivatkozásokat.
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

        // Belépés nélkül nincs rendszer.
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::aktualis_url()));
            exit;
        }

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            self::tiltott_oldal();
            exit;
        }

        $modul = $kulcs === 'attekintes' ? null : SDH_Muhely_Modulok::egy($kulcs);

        if ($kulcs !== 'attekintes' && $modul === null) {
            status_header(404);
            self::keret(
                'Nincs ilyen oldal',
                '',
                static function (): void {
                    echo '<div class="sdh-doboz"><p>Ez a modul nem létezik. '
                        . 'Lehet, hogy elírás van az URL-ben.</p></div>';
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

    /**
     * A jelenlegi kérés teljes URL-je – a bejelentkezés utáni
     * visszairányításhoz.
     */
    private static function aktualis_url(): string
    {
        $sema = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $ut   = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';

        return esc_url_raw($sema . $host . $ut);
    }

    /* =================================================================
     * Keret
     * ============================================================== */

    /**
     * A teljes oldal: fejléc, menü, tartalom.
     *
     * @param callable $tartalom A modul megjelenítő függvénye.
     */
    public static function keret(string $cim, string $aktiv_kulcs, callable $tartalom): void
    {
        $felhasznalo = wp_get_current_user();

        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title><?php echo esc_html($cim); ?> – SDH Műhely</title>
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/admin.css?v=' . SDH_MUHELY_VERSION); ?>">
            <link rel="stylesheet"
                  href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/app.css?v=' . SDH_MUHELY_VERSION); ?>">
        </head>
        <body class="sdh-app">

        <header class="sdh-app__fejlec">
            <a class="sdh-app__logo" href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url()); ?>">
                SDH <span>Műhely</span>
            </a>

            <nav class="sdh-app__menu">
                <a class="sdh-app__link <?php echo $aktiv_kulcs === 'attekintes' ? 'sdh-app__link--aktiv' : ''; ?>"
                   href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url()); ?>">
                    Áttekintés
                </a>

                <?php foreach (SDH_Muhely_Modulok::osszes() as $kulcs => $modul) : ?>
                    <?php if (!empty($modul['keszul'])) : ?>
                        <span class="sdh-app__link sdh-app__link--keszul" title="Készül">
                            <?php echo esc_html($modul['cim']); ?>
                        </span>
                    <?php else : ?>
                        <a class="sdh-app__link <?php echo $aktiv_kulcs === $kulcs ? 'sdh-app__link--aktiv' : ''; ?>"
                           href="<?php echo esc_url(SDH_Muhely_Modulok::frontend_url((string) $kulcs)); ?>">
                            <?php echo esc_html($modul['cim']); ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <div class="sdh-app__jobb">
                <span class="sdh-app__felhasznalo">
                    <?php echo esc_html($felhasznalo->display_name ?: $felhasznalo->user_login); ?>
                </span>
                <a class="sdh-app__kilep" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">
                    Kilépés
                </a>
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
        <body class="sdh-app sdh-app--uzenet">
            <div class="sdh-app__kozep">
                <p class="sdh-fejlec__kalap">SDH Műhely</p>
                <h1>Ehhez nincs jogosultságod.</h1>
                <p>A fiókod be van jelentkezve, de nem fér hozzá a műhelyrendszerhez.
                   Szólj az adminisztrátornak.</p>
                <a class="sdh-gomb sdh-gomb--vilagos"
                   href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Kilépés</a>
            </div>
        </body>
        </html>
        <?php
    }
}
