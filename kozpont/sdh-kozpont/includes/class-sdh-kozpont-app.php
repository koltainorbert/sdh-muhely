<?php
/**
 * SDH Központ – a felület: saját, teljes képernyős alkalmazás a wp-adminon belül (admin.php?page=sdh-kozpont).
 *
 * - Belépés után ez nyílik meg (Beállítások › Belépéskor a Központ), a vezérlőpultra lépve is ide visz.
 * - A felső sáv „WordPress” gombja a megszokott wp-adminba visz (onnan a felső sáv „SDH Központ” gombja hoz vissza).
 *
 * @package SDH_Kozpont
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Kozpont_App
{
    public const OLDAL = 'sdh-kozpont';
    private const SUTI = 'sdh_kp_wp';

    /** Beágyazott módban a felület rejtett haladó oldal, semleges címmel. */
    public const OLDAL_BEAGYAZVA = 'licenckozpont';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 1);

        // Beágyazva (a Portál hozza): nincs belépés utáni átirányítás, vezérlőpult-csere és admin-sáv gomb.
        if (SDH_Kozpont::beagyazott()) {
            return;
        }

        add_filter('login_redirect', [self::class, 'belepes_utan'], 100, 3);
        add_action('wp_login', [self::class, 'belepeskor'], 10, 0);
        add_action('load-index.php', [self::class, 'vezerlopult']);
        add_action('admin_bar_menu', [self::class, 'admin_sav'], 1);
        add_action('admin_head', [self::class, 'admin_sav_stilus']);
        add_action('wp_head', [self::class, 'admin_sav_stilus']);
    }

    public static function oldal_kulcs(): string
    {
        return SDH_Kozpont::beagyazott() ? self::OLDAL_BEAGYAZVA : self::OLDAL;
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::oldal_kulcs());
    }

    public static function cim(): string
    {
        return SDH_Kozpont::beagyazott() ? 'Licencközpont' : 'SDH Központ';
    }

    public static function menu(): void
    {
        if (SDH_Kozpont::beagyazott()) {
            // Rejtett oldal (nincs menüpontja) – a Portál Licencek paneljéről nyílik „Haladó licencközpont” néven.
            $hook = add_submenu_page('', self::cim(), self::cim(), 'manage_options', self::OLDAL_BEAGYAZVA, '__return_null');
        } else {
            $hook = add_menu_page('SDH Központ', 'SDH Központ', 'manage_options', self::OLDAL, '__return_null', 'dashicons-superhero-alt', 2);
        }

        if ($hook) {
            add_action('load-' . $hook, [self::class, 'oldal']);
        }
    }

    /** Belépés után a Központ (ha a kérés nem egy konkrét oldalra szólt). */
    public static function belepes_utan($cel, $kert, $user)
    {
        if (!($user instanceof WP_User) || !user_can($user, 'manage_options') || empty(SDH_Kozpont::beallitas()['belepeskor'])) {
            return $cel;
        }

        $kert = (string) $kert;

        if ($kert === '' || untrailingslashit($kert) === untrailingslashit(admin_url()) || $kert === admin_url('index.php')) {
            return self::url();
        }

        return $cel;
    }

    /** Új belépésnél újra a Központ az alapoldal. */
    public static function belepeskor(): void
    {
        if (isset($_COOKIE[self::SUTI])) {
            setcookie(self::SUTI, '', time() - 3600, ADMIN_COOKIE_PATH, (string) COOKIE_DOMAIN, is_ssl(), true);
        }
    }

    /** A vezérlőpult helyett a Központ – hacsak a „WordPress” gombbal nem kérték a wp-admint. */
    public static function vezerlopult(): void
    {
        if (!current_user_can('manage_options') || empty(SDH_Kozpont::beallitas()['belepeskor']) || isset($_COOKIE[self::SUTI]) || wp_doing_ajax()) {
            return;
        }

        wp_safe_redirect(self::url());
        exit;
    }

    public static function admin_sav(WP_Admin_Bar $sav): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $sav->add_node(['id' => 'sdh-kozpont', 'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">SDH Központ</span>', 'href' => self::url(), 'meta' => ['class' => 'sdh-kp-sav']]);
    }

    public static function admin_sav_stilus(): void
    {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }

        echo '<style>#wpadminbar .sdh-kp-sav>.ab-item{background:linear-gradient(135deg,#6d5efc,#22d3ee)!important;color:#fff!important;border-radius:999px;margin:3px 6px!important;height:26px!important;line-height:26px!important;padding:0 12px!important}'
            . '#wpadminbar .sdh-kp-sav .ab-icon:before{content:"\f338";color:#fff!important;top:3px}</style>';
    }

    /** A teljes oldal: saját HTML, a wp-admin keretei nélkül. */
    public static function oldal(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Nincs jogosultság.', 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- csak navigáció.
        if (isset($_GET['wp'])) {
            setcookie(self::SUTI, '1', 0, ADMIN_COOKIE_PATH, (string) COOKIE_DOMAIN, is_ssl(), true);
            wp_safe_redirect(admin_url('index.php'));
            exit;
        }

        if (isset($_COOKIE[self::SUTI])) {
            setcookie(self::SUTI, '', time() - 3600, ADMIN_COOKIE_PATH, (string) COOKIE_DOMAIN, is_ssl(), true);
        }

        $u     = wp_get_current_user();
        $tema  = (string) get_user_meta($u->ID, 'sdh_kozpont_tema', true);
        $alap  = defined('SDH_KOZPONT_URL') ? trailingslashit((string) SDH_KOZPONT_URL) . 'assets/' : plugins_url('assets/', SDH_KOZPONT_FILE);
        $alap  = (string) apply_filters('sdh_kozpont_eszkoz_url', $alap); // beágyazva: a Portál rejtési álnevei
        $ver   = SDH_KOZPONT_VERSION . '.' . (string) @filemtime(SDH_KOZPONT_DIR . '/assets/kozpont.js'); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $nev   = (string) ($u->first_name !== '' ? $u->first_name : $u->display_name);
        $boot  = [
            'rest'    => esc_url_raw(rest_url(SDH_Kozpont::NS . '/app/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'nev'     => $nev,
            'teljes'  => (string) $u->display_name,
            'avatar'  => get_avatar_url($u->ID, ['size' => 64]),
            'wp'      => SDH_Kozpont::beagyazott() ? SDH_Kozpont::felulet_url() : self::url() . '&wp=1',
            'wp_cim'  => SDH_Kozpont::beagyazott() ? 'Vissza a licencekhez' : 'WordPress admin',
            'cim'     => self::cim(),
            'alcim'   => SDH_Kozpont::beagyazott() ? 'Haladó nézet' : 'Vezérlőközpont',
            'kilep'   => wp_logout_url(wp_login_url()),
            'oldal'   => home_url('/'),
            'verzio'  => SDH_KOZPONT_VERSION,
            'tema'    => $tema,
        ];

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="hu" data-tema="<?php echo esc_attr($tema); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title><?php echo esc_html(self::cim()); ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%236d5efc'/%3E%3Cstop offset='1' stop-color='%2322d3ee'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='32' height='32' rx='9' fill='url(%23g)'/%3E%3Cpath d='M9 17l4 4 10-10' stroke='white' stroke-width='3' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="<?php echo esc_url($alap . 'kozpont.css?ver=' . rawurlencode($ver)); ?>">
</head>
<body class="kp">
    <div id="kp-app" class="kp-app" aria-live="polite">
        <div class="kp-indul"><div class="kp-indul__jel"></div><span><?php echo esc_html(self::cim()); ?></span></div>
    </div>
    <script>window.LK_BOOT = <?php echo wp_json_encode($boot); ?>;</script>
    <script src="<?php echo esc_url($alap . 'kozpont.js?ver=' . rawurlencode($ver)); ?>"></script>
</body>
</html>
        <?php
        exit;
    }
}
