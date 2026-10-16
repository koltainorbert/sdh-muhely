<?php
/**
 * Zárt mód: a rendszer nem árulja el, mi fut alatta (csak a fejlesztő tudja).
 *
 * A telepítés csak a műhelyé, ezért:
 * - minden „idegen” oldal (kezdőlap, bejegyzés, 404, hírcsatorna, oldaltérkép…) a műhelybe
 *   irányít – kijelentkezve onnan a saját bejelentkező oldalra;
 * - saját bejelentkező (/muhely/belepes/) és kilépés (/muhely/kilepes/); a wp-login.php
 *   sima megnyitása is ide visz (a jelszó-visszaállító és a kilépés WordPress-művelete marad);
 * - saját ikon (favicon) a WordPress-logó helyett; robots.txt: „mindent tilt”;
 * - a REST API kívülről csak a saját végpontjainkat adja (SMS-átjáró), az XML-RPC nem válaszol;
 * - az átirányítás nem küld „X-Redirect-By: WordPress” fejlécet.
 *
 * Kikapcsolás (fejlesztőnek): define('SDH_MUHELY_NYITOTT_OLDAL', true); a wp-config.php-ban,
 * vagy a `sdh_muhely_zart_mod` szűrő.
 *
 * Ami így is látszik: a forráskódban a stíluslapok és szkriptek útvonala – ezt csak fejlesztő
 * ismeri fel, és a webszerver statikus fájljai (pl. readme.html) – ezek a szerver dolga.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Rejtes
{
    public const KIKAPCSOLO = 'SDH_MUHELY_NYITOTT_OLDAL';
    public const IKON_VAR   = 'sdh_ikon';
    public const BELEPES    = 'belepes';
    public const KILEPES    = 'kilepes';
    private const NONCE     = 'sdh_muhely_belepes';

    /** A saját REST-végpontjaink eleje (kívülről csak ez érhető el). */
    private const REST_SAJAT = '/sdh-muhely/';

    public static function init(): void
    {
        add_action('init', [self::class, 'korai'], 1);
        add_filter('x_redirect_by', '__return_false');

        if (!self::zart()) {
            return;
        }

        add_action('template_redirect', [self::class, 'kapu'], 5);
        add_action('do_faviconico', [self::class, 'ikon_kuld']);
        add_filter('wp_sitemaps_enabled', '__return_false');
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('rest_authentication_errors', [self::class, 'rest_kapu'], 99);
        add_filter('login_url', [self::class, 'belepes_url'], 20, 3);
        add_filter('logout_url', [self::class, 'kilepes_url'], 20, 2);
        add_action('login_init', [self::class, 'wp_login_kapu'], 1);
        add_action('login_head', [self::class, 'ikon_link']);

        foreach (['wp_generator', 'rsd_link', 'wlwmanifest_link', 'rest_output_link_wp_head', 'wp_oembed_add_discovery_links'] as $fv) {
            remove_action('wp_head', $fv);
        }

        remove_action('template_redirect', 'rest_output_link_header', 11);

        // A WordPress gyökerében álló, árulkodó szövegfájlok (a frissítés visszateszi őket: naponta egyszer újra törli).
        add_action('admin_init', [self::class, 'arulkodo_fajlok']);
        add_action('upgrader_process_complete', [self::class, 'arulkodo_fajlok_most']);
    }

    /** @return array<int, string> */
    public static function arulkodo_fajlok_listaja(): array
    {
        return [ABSPATH . 'readme.html', ABSPATH . 'license.txt'];
    }

    public static function arulkodo_fajlok(): void
    {
        if (get_transient('sdh_muhely_arulkodo_fajlok')) {
            return;
        }

        set_transient('sdh_muhely_arulkodo_fajlok', 1, DAY_IN_SECONDS);
        self::arulkodo_fajlok_most();
    }

    /** Visszaad: hány fájlt törölt. */
    public static function arulkodo_fajlok_most(): int
    {
        $db = 0;

        foreach (self::arulkodo_fajlok_listaja() as $fajl) {
            if (is_file($fajl) && is_writable($fajl) && @unlink($fajl)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                $db++;
            }
        }

        return $db;
    }

    /* ------------------------------------------------------------------
     * Szöveg a nem fejlesztőnek (asszisztens, súgó): nincs benne a háttér
     * ------------------------------------------------------------------ */

    /**
     * Elárulja-e a szöveg, mi fut a rendszer alatt (WordPress, wp-admin, plugin, LocalWP…).
     * A súgó-teszt (t36) is ezzel nézi a nem fejlesztőnek látszó fejezeteket.
     */
    public static function arulkodo(string $szoveg): bool
    {
        return preg_match('/wordpress|\bwp-|\bWP\b|plugin|(?<![\p{L}])bővítmény|localwp|local by flywheel|\.php\b|wp_|github|\bgit\b/iu', $szoveg) === 1;
    }

    /**
     * A nem fejlesztőnek szánt szövegből kimaradnak az árulkodó pontok (a fejlesztő mindent kap).
     * Egy pont: egy nem behúzott sor és az utána következő behúzott folytatósorai – így a
     * verziónapló több soros felsorolás-pontja egészében marad ki, nem csonkán.
     */
    public static function semlegesit(string $szoveg, ?bool $fejleszto = null): string
    {
        $fejleszto = $fejleszto ?? (class_exists('SDH_Muhely_Admin_UI') && SDH_Muhely_Admin_UI::fejleszto_e());

        if ($fejleszto || !self::arulkodo($szoveg)) {
            return $szoveg;
        }

        $pontok = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $szoveg)) as $sor) {
            if ($pontok !== [] && $sor !== '' && ($sor[0] === ' ' || $sor[0] === "\t")) {
                $pontok[count($pontok) - 1] .= "\n" . $sor;
            } else {
                $pontok[] = $sor;
            }
        }

        return trim(implode("\n", array_filter($pontok, static fn (string $p): bool => !self::arulkodo($p))));
    }

    /** Be van-e kapcsolva a zárt mód. */
    public static function zart(): bool
    {
        $zart = !(defined(self::KIKAPCSOLO) && constant(self::KIKAPCSOLO));

        return (bool) apply_filters('sdh_muhely_zart_mod', $zart);
    }

    /* ------------------------------------------------------------------
     * Korai teendők: ikon, XML-RPC
     * ------------------------------------------------------------------ */

    public static function korai(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET[self::IKON_VAR])) {
            self::ikon_kuld();
        }

        if (self::zart() && defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Nincs ilyen oldal.';
            exit;
        }
    }

    /* ------------------------------------------------------------------
     * Kapu: minden idegen oldal a műhelybe
     * ------------------------------------------------------------------ */

    /**
     * Mit kell tenni ezzel a (nem a műhelyhez tartozó) kéréssel.
     *
     * @return string 'robots' | 'ikon' | 'atiranyit' | '' (nincs teendő)
     */
    public static function dontes(): string
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return '';
        }

        // Szép URL nélkül a WordPress nem ismeri fel a /robots.txt-t és a /favicon.ico-t: a címből is.
        $ut = strtolower((string) parse_url(isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '', PHP_URL_PATH));

        if ((function_exists('is_robots') && is_robots()) || str_ends_with($ut, '/robots.txt')) {
            return 'robots';
        }

        if ((function_exists('is_favicon') && is_favicon()) || str_ends_with($ut, '/favicon.ico')) {
            return 'ikon';
        }

        return 'atiranyit';
    }

    public static function kapu(): void
    {
        switch (self::dontes()) {
            case 'robots':
                header('Content-Type: text/plain; charset=utf-8');
                echo self::robots(); // phpcs:ignore WordPress.Security.EscapeOutput
                exit;
            case 'ikon':
                self::ikon_kuld();
                // ikon_kuld() kilép.
            case 'atiranyit':
                nocache_headers();
                wp_safe_redirect(SDH_Muhely_Modulok::frontend_url(), 302);
                exit;
        }
    }

    public static function robots(): string
    {
        return "User-agent: *\nDisallow: /\n";
    }

    /* ------------------------------------------------------------------
     * Ikon
     * ------------------------------------------------------------------ */

    /** Az ikon címe: a feltöltött logó, különben a rendszer jeléből rajzolt kép. */
    public static function ikon_url(): string
    {
        $logo = class_exists('SDH_Muhely_Arculat') ? (string) SDH_Muhely_Arculat::beallitas()['logo'] : '';

        return $logo !== '' ? $logo : add_query_arg(self::IKON_VAR, '1', home_url('/'));
    }

    public static function ikon_link(): void
    {
        echo '<link rel="icon" href="' . esc_url(self::ikon_url()) . '">' . "\n";
    }

    /** A rendszer jeléből (pl. „SDH”) rajzolt kerek ikon. */
    public static function ikon_svg(): string
    {
        $jel = class_exists('SDH_Muhely_Arculat') ? (string) SDH_Muhely_Arculat::beallitas()['jel'] : '';
        $jel = mb_strtoupper(mb_substr(trim($jel) !== '' ? trim($jel) : 'M', 0, 3));
        $meret = mb_strlen($jel) >= 3 ? 22 : (mb_strlen($jel) === 2 ? 28 : 36);

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<rect width="64" height="64" rx="14" fill="#1f2328"/>'
            . '<text x="32" y="33" text-anchor="middle" dominant-baseline="middle" font-family="Arial, Helvetica, sans-serif" font-weight="700" font-size="' . $meret . '" fill="#ffffff">'
            . esc_html($jel) . '</text></svg>';
    }

    public static function ikon_kuld(): void
    {
        $logo = class_exists('SDH_Muhely_Arculat') ? (string) SDH_Muhely_Arculat::beallitas()['logo'] : '';

        // A feltöltött logó a saját címén él: a /favicon.ico oda irányít (fejléc nélkül).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($logo !== '' && !isset($_GET[self::IKON_VAR])) {
            wp_safe_redirect($logo, 302);
            exit;
        }

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo self::ikon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /* ------------------------------------------------------------------
     * REST
     * ------------------------------------------------------------------ */

    /**
     * Kívülről (nem fejlesztőként) csak a saját végpontjaink érhetők el.
     *
     * @param mixed $eredmeny
     * @return mixed
     */
    public static function rest_kapu($eredmeny)
    {
        if ($eredmeny !== null && $eredmeny !== true) {
            return $eredmeny;
        }

        if (class_exists('SDH_Muhely_Admin_UI') && SDH_Muhely_Admin_UI::fejleszto_e()) {
            return $eredmeny;
        }

        $ut = isset($GLOBALS['wp']->query_vars['rest_route']) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';

        if ($ut !== '' && str_starts_with($ut, self::REST_SAJAT)) {
            return $eredmeny;
        }

        return new WP_Error('nincs', 'Nincs ilyen oldal.', ['status' => 404]);
    }

    /* ------------------------------------------------------------------
     * Belépés, kilépés
     * ------------------------------------------------------------------ */

    /**
     * @param string $url
     * @param string $vissza
     */
    public static function belepes_url($url, $vissza = '', $ujra = false): string
    {
        return SDH_Muhely_Modulok::frontend_url(self::BELEPES, (string) $vissza !== '' ? ['redirect_to' => rawurlencode((string) $vissza)] : []);
    }

    /**
     * @param string $url
     * @param string $vissza
     */
    public static function kilepes_url($url, $vissza = ''): string
    {
        return SDH_Muhely_Modulok::frontend_url(self::KILEPES, ['_wpnonce' => wp_create_nonce('log-out')]);
    }

    /** A wp-login.php sima megnyitása a saját bejelentkezőre visz (a többi műveletét nem bántjuk). */
    public static function wp_login_kapu(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $muvelet = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : 'login';

        if ($muvelet !== 'login' || isset($_REQUEST['interim-login']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            return;
        }

        $vissza = isset($_REQUEST['redirect_to']) ? (string) wp_unslash($_REQUEST['redirect_to']) : '';
        // phpcs:enable

        wp_safe_redirect(self::belepes_url('', $vissza), 302);
        exit;
    }

    /** Hová menjen belépés után (a „login_redirect” szűrővel, mint a WordPress-belépésnél). */
    private static function cel(string $kert, WP_User $felhasznalo): string
    {
        $alap = SDH_Muhely_Modulok::frontend_url();
        $kert = $kert !== '' ? wp_validate_redirect($kert, $alap) : $alap;

        return (string) apply_filters('login_redirect', $kert, $kert, $felhasznalo);
    }

    /**
     * A bejelentkező űrlap feldolgozása.
     *
     * @param array<string, mixed> $be
     * @return array{cel?: string, hiba?: string}
     */
    public static function belepes_feldolgoz(array $be): array
    {
        if (!wp_verify_nonce((string) ($be['_sdh_belepes'] ?? ''), self::NONCE)) {
            return ['hiba' => 'Lejárt az oldal. Töltsd újra, és próbáld újra.'];
        }

        $nev    = trim(sanitize_user((string) ($be['log'] ?? '')));
        $jelszo = (string) ($be['pwd'] ?? '');

        if ($nev === '' || $jelszo === '') {
            return ['hiba' => 'Add meg a neved és a jelszavad.'];
        }

        $felhasznalo = wp_signon([
            'user_login'    => $nev,
            'user_password' => $jelszo,
            'remember'      => !empty($be['rememberme']),
        ], is_ssl());

        if (is_wp_error($felhasznalo)) {
            // A WordPress hibaszövege linket és árulkodó szavakat tartalmazna: saját, általános üzenet.
            return ['hiba' => $felhasznalo->get_error_code() === 'sdh_dolgozo'
                ? 'Ezzel a névvel nem lehet belépni: a közös fiókon kell kiválasztani.'
                : 'Hibás felhasználónév vagy jelszó.'];
        }

        wp_set_current_user((int) $felhasznalo->ID);

        return ['cel' => self::cel((string) ($be['redirect_to'] ?? ''), $felhasznalo)];
    }

    /** A /muhely/belepes/ és /muhely/kilepes/ kiszolgálása (a Frontend hívja, a belépés-ellenőrzés előtt). */
    public static function fogad(string $kulcs): void
    {
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);

        if ($kulcs === self::KILEPES) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $nonce = isset($_GET['_wpnonce']) ? (string) wp_unslash($_GET['_wpnonce']) : '';

            if (is_user_logged_in() && wp_verify_nonce($nonce, 'log-out')) {
                wp_logout();
            }

            wp_safe_redirect(self::belepes_url(''), 302);
            exit;
        }

        // phpcs:disable WordPress.Security.NonceVerification
        $vissza = isset($_REQUEST['redirect_to']) ? (string) wp_unslash($_REQUEST['redirect_to']) : '';

        if (is_user_logged_in()) {
            wp_safe_redirect(self::cel($vissza, wp_get_current_user()), 302);
            exit;
        }

        $hiba = '';
        $nev  = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $be = wp_unslash($_POST);
            $be = is_array($be) ? $be : [];
            $be['redirect_to'] = $vissza;
            $nev = sanitize_user((string) ($be['log'] ?? ''));
            $r   = self::belepes_feldolgoz($be);

            if (isset($r['cel'])) {
                wp_safe_redirect($r['cel'], 302);
                exit;
            }

            $hiba = (string) ($r['hiba'] ?? '');
        }
        // phpcs:enable

        echo self::belepo_html($hiba, $nev, $vissza); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /** A bejelentkező oldal (önálló, a rendszer arculatával – sehol nincs benne a WordPress). */
    public static function belepo_html(string $hiba = '', string $nev = '', string $vissza = ''): string
    {
        $rendszer = class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::rendszer_nev() : '';
        $arc      = class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::beallitas() : ['logo' => '', 'jel' => ''];
        $cim      = SDH_Muhely_Modulok::frontend_url(self::BELEPES, $vissza !== '' ? ['redirect_to' => rawurlencode($vissza)] : []);
        $v        = static fn (string $f): string => SDH_MUHELY_URL . $f . '?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio($f);

        ob_start();
        ?>
        <!doctype html>
        <html lang="hu">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title><?php echo esc_html('Belépés – ' . $rendszer); ?></title>
            <link rel="icon" href="<?php echo esc_url(self::ikon_url()); ?>">
            <link rel="stylesheet" href="<?php echo esc_url($v('assets/admin.css')); ?>">
            <link rel="stylesheet" href="<?php echo esc_url($v('assets/app.css')); ?>">
        </head>
        <body class="sdh-app sdh-app--uzenet">
            <div class="sdh-app__kozep sdh-belepes">
                <form class="sdh-belepes__doboz" method="post" action="<?php echo esc_url($cim); ?>" autocomplete="on">
                    <div class="sdh-belepes__fej">
                        <?php if ((string) $arc['logo'] !== '') : ?>
                            <img class="sdh-belepes__logo" src="<?php echo esc_url((string) $arc['logo']); ?>" alt="">
                        <?php else : ?>
                            <span class="sdh-belepes__jel"><?php echo esc_html((string) $arc['jel']); ?></span>
                        <?php endif; ?>
                        <h1 class="sdh-belepes__cim"><?php echo esc_html($rendszer); ?></h1>
                    </div>

                    <?php if ($hiba !== '') : ?>
                        <div class="sdh-uzenet sdh-uzenet--hiba" role="alert"><?php echo esc_html($hiba); ?></div>
                    <?php endif; ?>

                    <div class="sdh-mezo">
                        <label for="sdh-belepes-nev">Felhasználónév vagy e-mail</label>
                        <input type="text" id="sdh-belepes-nev" name="log" value="<?php echo esc_attr($nev); ?>" autocomplete="username" required autofocus>
                    </div>
                    <div class="sdh-mezo">
                        <label for="sdh-belepes-jelszo">Jelszó</label>
                        <input type="password" id="sdh-belepes-jelszo" name="pwd" value="" autocomplete="current-password" required>
                    </div>
                    <div class="sdh-mezo sdh-mezo--jelolo">
                        <input type="checkbox" id="sdh-belepes-marad" name="rememberme" value="1">
                        <label for="sdh-belepes-marad">Maradjak bejelentkezve</label>
                    </div>
                    <?php wp_nonce_field(self::NONCE, '_sdh_belepes', false); ?>
                    <button type="submit" class="sdh-gomb sdh-gomb--elsodleges sdh-belepes__gomb">Belépés</button>
                    <p class="sdh-sugo sdh-belepes__lab">Elfelejtett jelszó: szólj az adminisztrátornak.</p>
                </form>
            </div>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
    }
}
