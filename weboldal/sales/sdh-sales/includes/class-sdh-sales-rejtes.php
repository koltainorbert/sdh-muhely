<?php
/**
 * Rejtés (weboldal/REJTES.md): a látogató semmiből nem tudhatja meg, mire épül a webhely és ki áll mögötte.
 *
 * Mit csinál (mind kapcsolható a `rejtes.*` beállításokkal, alapból mind be):
 * - REST-előtag `api` (`rejtes.api`); a rendszer saját REST-útjai, a névtér-indexek, az OPTIONS (séma) és a `?rest_route=`
 *   látogatónak és vevőnek (szerkesztési jog nélkül) 404 – kivétel a saját névtér útjai és a Központ nyilvános
 *   példány-útjai (`/sdh-kozpont/v1/…`, `/licenc/v1/…`: szívverés, jelentés, csomag, támogatás, kiszolgálás, letöltés),
 *   mindkét alakban (`/api/…` és `?rest_route=`), mert a vevők Műhelye így hívja a Központot.
 * - REST-válasz: nincs `Link: …api.w.org` fejléc, `X-WP-*` fejléc a látogatónak, a CORS-listákban semleges nevek; a rendszer
 *   hibakódjai és angol üzenetei semleges magyar kódra / szövegre cserélve (`nincs`, `tiltott`, `hibas_adat`, `lejart`…).
 *   Nincs REST-link a fejben / fejlécben.
 * - Útvonal-álnevek (`rejtes.alias`): tartalom → /static/, bővítmények → /static/m/, témák → /static/t/, feltöltések → /media/,
 *   rendszer-fájlok → /lib/ (URL-szűrők + .htaccess-szabályok; nginx: dokumentacio/rejtes-uzemeltetes.md; `php -S`: eszkozok/router.php).
 * - Fej és fejléc: nincs generator / RSD / wlwmanifest / shortlink / oEmbed / emoji / speculation rules / block-library CSS /
 *   global-styles / skip-link; nincs X-Pingback, X-Redirect-By, X-Powered-By, Link: api.w.org.
 * - Belépés titkos címen (`rejtes.belepo`); a régi belépő és a vezérlőpult látogatónak 404; jelszó-visszaállítás és regisztráció zárva.
 * - 404: xmlrpc, wp-cron (ha DISABLE_WP_CRON), szerző-archívum és ?author=, a rendszer apró fájljai (opml, trackback, signup…),
 *   a beépített sitemap (helyette saját /sitemap.xml), a hozzászólások.
 * - favicon.ico: a saját ikon (soha nem a rendszer logója), e-mail feladó a beállításból, saját hibaoldal (wp_die).
 * - Kimeneti szűrő (utolsó védvonal, csak HTML-válasz a fronton): generator-meta, HTML-megjegyzések, `wp-` osztály / id,
 *   a maradék rendszer-útvonalak cseréje.
 * - Önellenőrzés: `SDH_Sales_Rejtes::ellenoriz()` (admin REST: /admin/rejtes/ellenoriz) kívülről lekéri a kényes címeket
 *   és a kezdőlapot, és felsorolja, ami még árulkodik.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Rejtes
{
    /** Tiltott minták a kimenetben (a teszt és az önellenőrzés is ezt használja). */
    public const TILTOTT = [
        '~wordpress~i', '~/wp-(content|includes|admin|json)/~i', '~wp-login\.php~i', '~xmlrpc\.php~i', '~admin-ajax\.php~i',
        '~rest_route=~i', '~\bsdh\b~i', '~sdh[-_]~i', '~[-_]sdh~i', '~api\.w\.org~i', '~<meta[^>]+name=["\']generator~i',
        '~rank\s?math~i', '~wp\s?rocket~i', '~site\s?kit~i', '~class=["\'][^"\']*\bwp-~i', '~id=["\']wp-~i', '~w\.org~i',
    ];

    public static function init(): void
    {
        if (!self::be('aktiv')) {
            return;
        }

        add_filter('rest_url_prefix', [self::class, 'rest_elotag']);
        add_filter('rest_authentication_errors', [self::class, 'rest_zar'], 99);
        add_filter('rest_pre_dispatch', [self::class, 'rest_elotte'], 5, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'rest_hiba_csere'], 99, 3);
        add_filter('rest_post_dispatch', [self::class, 'rest_valasz_csere'], 99, 3);
        add_filter('rest_pre_serve_request', [self::class, 'rest_fejlec_takarit'], 0, 4);
        add_filter('rest_exposed_cors_headers', [self::class, 'cors_kitett'], 99);
        add_filter('rest_allowed_cors_headers', [self::class, 'cors_engedett'], 99);
        add_filter('rest_jsonp_enabled', '__return_false');
        add_action('init', [self::class, 'korai'], 0);
        add_action('init', [self::class, 'fej_takarit'], 20);
        add_action('wp_enqueue_scripts', [self::class, 'front_takarit'], 999);
        add_action('wp_print_styles', [self::class, 'front_takarit'], 999);
        add_action('template_redirect', [self::class, 'template_ellenor'], 0);
        add_action('template_redirect', [self::class, 'kimenet_indit'], 1);
        add_action('send_headers', [self::class, 'fejlecek']);
        add_filter('wp_headers', [self::class, 'wp_fejlecek']);
        add_filter('x_redirect_by', '__return_false');
        add_filter('the_generator', '__return_empty_string');
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('wp_sitemaps_enabled', '__return_false');
        add_filter('wp_speculation_rules_configuration', '__return_null');
        add_filter('show_admin_bar', [self::class, 'admin_sav']);
        add_filter('body_class', [self::class, 'body_osztaly'], 999);
        add_filter('post_class', '__return_empty_array', 999);
        add_filter('wp_mail_from', [self::class, 'level_cim']);
        add_filter('wp_mail_from_name', [self::class, 'level_nev']);
        add_filter('wp_die_handler', [self::class, 'die_kezelo']);
        add_filter('robots_txt', [self::class, 'robots'], 999);
        add_filter('comments_open', '__return_false', 99);
        add_filter('pings_open', '__return_false', 99);
        add_filter('feed_links_show_comments_feed', '__return_false');
        add_action('do_faviconico', [self::class, 'favicon'], 0);
        add_filter('option_blogname', [self::class, 'webhely_nev']);
        add_filter('option_blogdescription', [self::class, 'webhely_leiras']);
        add_action('sdh_sales_aktivalas', [self::class, 'alap_tartalom_takarit']);

        // Rank Math / WP Rocket / Site Kit lábnyomai (ha jelen vannak).
        add_filter('rank_math/frontend/remove_credit_notice', '__return_true');
        add_filter('rank_math/sitemap/remove_credit', '__return_true');
        add_filter('googlesitekit_generator', '__return_false');

        // Az Apache-szabályokat a beállítás szerint mindig kiírjuk (enélkül a próba sem sikerülhet).
        if (self::be('alias')) {
            add_filter('mod_rewrite_rules', [self::class, 'htaccess']);
        }

        // Az URL-ek csak akkor kapnak álnevet, ha a webszerver bizonyítottan kiszolgálja őket (különben minden CSS / JS 404,
        // és minden hiányzó fájl a teljes rendszert futtatja – formázatlan, lassú oldal).
        add_action('admin_init', [self::class, 'alias_proba_ha_kell']);
        add_action('admin_notices', [self::class, 'alias_uzenet']);

        if (self::alias_aktiv()) {
            add_filter('content_url', [self::class, 'url_alias'], 99);
            add_filter('plugins_url', [self::class, 'url_alias'], 99);
            add_filter('includes_url', [self::class, 'url_alias'], 99);
            add_filter('script_loader_src', [self::class, 'url_alias'], 99);
            add_filter('style_loader_src', [self::class, 'url_alias'], 99);
            add_filter('theme_root_uri', [self::class, 'url_alias'], 99);
            add_filter('stylesheet_directory_uri', [self::class, 'url_alias'], 99);
            add_filter('template_directory_uri', [self::class, 'url_alias'], 99);
            add_filter('upload_dir', [self::class, 'feltoltes_alias'], 99);
            add_filter('wp_get_attachment_url', [self::class, 'url_alias'], 99);
            add_filter('wp_calculate_image_srcset', [self::class, 'srcset_alias'], 99);
        }

        if (self::be('belepo')) {
            add_filter('site_url', [self::class, 'belepo_url'], 99, 3);
            add_filter('network_site_url', [self::class, 'belepo_url'], 99, 3);
            add_filter('wp_redirect', [self::class, 'atiranyit_szuro'], 99);
            add_filter('login_headerurl', static fn (): string => home_url('/'));
            add_filter('login_headertext', static fn (): string => SDH_Sales::termeknev());
            // A belépő oldal címe („Log In ‹ … — WordPress”) semleges, magyar.
            // Csak ha a cím árulkodik – egy másik bővítmény (pl. a Műhely) saját, semleges címét nem írjuk felül.
            add_filter('login_title', static fn ($t): string => preg_match('~wordpress~i', (string) $t) === 1 ? esc_html__('Belépés', 'sdh-sales') . ' – ' . SDH_Sales::termeknev() : (string) $t, 99);
            add_action('login_enqueue_scripts', [self::class, 'belepo_stilus']);
            add_filter('login_display_language_dropdown', '__return_false');
            add_filter('allow_password_reset', '__return_false');
        }

        add_action('sdh_sales_beallitas_mentve', [self::class, 'rewrite_frissit']);
        add_action('wp_loaded', [self::class, 'belepo_kiszolgal'], 0);
        SDH_Sales_Rest::ut('/admin/rejtes/ellenoriz', 'POST', static fn () => rest_ensure_response(self::ellenoriz()), 'admin');
        add_action('init', [self::class, 'sitemap_szabaly']);
        add_filter('query_vars', static function (array $v): array {
            $v[] = 'portal_sitemap';

            return $v;
        });
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    public static function be(string $mi): bool
    {
        return (bool) SDH_Sales::b('rejtes.' . $mi, true);
    }

    public const OPT_ALIAS = 'sdh_sales_alias_proba';

    /** Az útvonal-álnevek élnek-e: be vannak kapcsolva ÉS a webszerver próbán kiszolgálta őket. */
    public static function alias_aktiv(): bool
    {
        if (!self::be('alias')) {
            return false;
        }

        $p = get_option(self::OPT_ALIAS, []);

        return is_array($p) && !empty($p['ok']);
    }

    /** Admin-oldalon legfeljebb óránként (sikertelen próba után 10 percenként) újrapróbál. */
    public static function alias_proba_ha_kell(): void
    {
        if (!self::be('alias') || wp_doing_ajax() || !current_user_can('manage_options')) {
            return;
        }

        $p = get_option(self::OPT_ALIAS, []);
        $p = is_array($p) ? $p : [];
        $koz = !empty($p['ok']) ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS;

        if ((int) ($p['ido'] ?? 0) > time() - $koz) {
            return;
        }

        self::alias_proba();
    }

    /**
     * Próba-kérés egy ismert statikus fájlra az álnév-címen (a saját közös JS-ünk). Siker: 200 és a fájl eleje egyezik.
     *
     * @return array{ok: bool, ido: int, uzenet: string, url: string}
     */
    public static function alias_proba(): array
    {
        $fajl = SDH_SALES_DIR . 'assets/front/kozos.js';
        $nyers = trailingslashit(WP_PLUGIN_URL) . dirname(plugin_basename(SDH_SALES_FILE)) . '/assets/front/kozos.js';
        $url   = self::alias_csere($nyers);
        $ki    = ['ok' => false, 'ido' => time(), 'uzenet' => '', 'url' => $url];

        if ($url === $nyers || !is_file($fajl)) {
            $ki['uzenet'] = 'Nincs mit próbálni (az álnév nem alkalmazható erre a telepítésre).';
        } else {
            $r = wp_remote_get(add_query_arg('p', (string) time(), $url), ['timeout' => 8, 'redirection' => 0, 'sslverify' => false]);

            if (is_wp_error($r)) {
                $ki['uzenet'] = 'A próba-kérés nem futott le: ' . $r->get_error_message();
            } elseif ((int) wp_remote_retrieve_response_code($r) !== 200) {
                $ki['uzenet'] = 'A webszerver ' . (int) wp_remote_retrieve_response_code($r) . ' kóddal válaszolt – hiányzik az átírási szabály.';
            } else {
                $eleje = (string) file_get_contents($fajl, false, null, 0, 200);
                $ki['ok'] = strncmp((string) wp_remote_retrieve_body($r), $eleje, strlen($eleje)) === 0;
                $ki['uzenet'] = $ki['ok'] ? 'Az útvonal-álnevek működnek.' : 'A válasz nem a várt fájl – hiányzik az átírási szabály.';
            }
        }

        $regi = get_option(self::OPT_ALIAS, []);
        update_option(self::OPT_ALIAS, $ki, true);

        // Állapotváltozáskor az Apache-szabályokat is újraírjuk (sikernél jöhet a valódi útvonalak tiltása).
        if (!is_array($regi) || !empty($regi['ok']) !== $ki['ok']) {
            flush_rewrite_rules(true);
        }

        return $ki;
    }

    public static function alias_uzenet(): void
    {
        if (!self::be('alias') || self::alias_aktiv() || !current_user_can('manage_options')) {
            return;
        }

        $p = get_option(self::OPT_ALIAS, []);
        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Rejtés – útvonal-álnevek kikapcsolva.', 'sdh-sales') . '</strong> '
            . esc_html__('A webszerver még nem szolgálja ki a semleges útvonalakat, ezért a fájlok a megszokott helyükről töltődnek. Apache-on: Beállítások › Közvetlen hivatkozások › Mentés; nginx-en (pl. Local): a location-szabályok a rejtés üzemeltetési leírásában. A beállítás után a rendszer magától újrapróbálja (10 percenként).', 'sdh-sales')
            . (is_array($p) && !empty($p['uzenet']) ? ' <em>' . esc_html((string) $p['uzenet']) . '</em>' : '') . '</p></div>';
    }

    public static function api(): string
    {
        $p = sanitize_title((string) SDH_Sales::b('rejtes.api', 'api'));

        return $p !== '' && $p !== 'wp-json' ? $p : 'api';
    }

    /** A titkos belépő címe (útvonal-szelet, perjel nélkül). Üresen: a webhely sóiból képzett, állandó cím. */
    public static function belepo_szelet(): string
    {
        $s = sanitize_title((string) SDH_Sales::b('rejtes.belepo', ''));

        return $s !== '' ? $s : 'iroda-' . substr(hash_hmac('sha256', 'belepo', wp_salt('auth')), 0, 10);
    }

    /** @var array<string, string>|null */
    private static ?array $alias_cache = null;

    /**
     * Álnév → valódi alút (a webhely gyökeréhez képest). Csak állandókból számol (a URL-szűrők rekurziója nélkül);
     * a témák és a feltöltések az alapértelmezett helyükön vannak (wp-content/themes, wp-content/uploads).
     *
     * @return array<string, string>
     */
    public static function aliasok(): array
    {
        if (self::$alias_cache !== null) {
            return self::$alias_cache;
        }

        $home = trim((string) wp_parse_url((string) get_option('home'), PHP_URL_PATH), '/');
        $rel  = static function (string $url) use ($home): string {
            $u = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');

            return $home !== '' && strpos($u . '/', $home . '/') === 0 ? trim(substr($u, strlen($home)), '/') : $u;
        };

        $tart = $rel(WP_CONTENT_URL);

        self::$alias_cache = [
            'static/m' => $rel(WP_PLUGIN_URL),
            'static/t' => $tart . '/themes',
            'media'    => $tart . '/uploads',
            'static'   => $tart,
            'lib'      => $rel(rtrim((string) get_option('siteurl'), '/') . '/' . WPINC),
        ];

        return self::$alias_cache;
    }

    /* =================================================================
     * REST
     * ============================================================== */

    public static function rest_elotag(): string
    {
        return self::api();
    }

    /** A Központ nyilvános (példány-) útjai: a vevők Műhelye és az értékesítő webhely hívja – látogatóként is elérhetők. */
    public const KOZPONT_UTAK = ['szivveres', 'jelentes', 'csomag', 'tamogatas', 'kiszolgalas', 'letoltes'];

    /** Szerkesztő / adminisztrátor (a rendszer REST-útjait használó belső felhasználó). */
    public static function belso(): bool
    {
        return current_user_can('edit_posts') || SDH_Sales::admin();
    }

    /**
     * Nyilvános-e egy REST-út (látogatónak / vevőnek): a saját névtér útjai (a névtér-index nem), a Központ példány-útjai
     * mindkét névtérben, és a `sdh_sales_rest_nyilvanos` szűrő további előtagjai (mindig `/`-rel folytatva).
     */
    public static function nyilvanos_ut(string $ut): bool
    {
        $ut = '/' . ltrim((string) preg_replace('~[?#].*$~', '', $ut), '/');

        if (preg_match('~^/(sdh-kozpont|licenc)/v1/(' . implode('|', self::KOZPONT_UTAK) . ')/?$~', $ut) === 1) {
            return true;
        }

        foreach ((array) apply_filters('sdh_sales_rest_nyilvanos', ['/' . SDH_Sales_Rest::NS]) as $e) {
            $e = '/' . trim((string) $e, '/');

            if ($e !== '/' && strpos($ut, $e . '/') === 0 && strlen($ut) > strlen($e) + 1) {
                return true;
            }
        }

        return false;
    }

    /** A kért REST-út (pretty és ?rest_route= alakból). */
    private static function kert_ut(): string
    {
        $wp = $GLOBALS['wp'] ?? null;
        $ut = is_object($wp) ? (string) ($wp->query_vars['rest_route'] ?? '') : '';

        if ($ut === '' && isset($_GET['rest_route'])) {
            $ut = (string) wp_unslash($_GET['rest_route']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }

        if ($ut === '' && isset($_SERVER['REQUEST_URI'])) {
            $ut = (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $ut = (string) preg_replace('~^.*?/' . preg_quote(self::api(), '~') . '(?=/|$)~', '', $ut);
        }

        return $ut;
    }

    /**
     * A rendszer saját REST-útjai csak belső felhasználónak; látogatónak és vevőnek 404 (mintha nem is volna).
     * (A vevő így a core `/wp/v2/users/me` útján sem írhatja át az e-mail-címét.)
     *
     * @param mixed $hiba
     * @return mixed
     */
    public static function rest_zar($hiba)
    {
        if ($hiba !== null || self::belso()) {
            return $hiba;
        }

        return self::nyilvanos_ut(self::kert_ut()) ? $hiba : new WP_Error('nincs', 'Nincs ilyen cím.', ['status' => 404]);
    }

    /**
     * Útválasztás előtt: látogatónak / vevőnek a névtér-indexek, a séma (OPTIONS) és a nem nyilvános utak 404
     * (a `?rest_route=` és a `/api/` alakra egyaránt; a hitelesítési szűrő mellett ez a névtér-indexet is fogja).
     *
     * @param mixed $eredmeny
     * @return mixed
     */
    public static function rest_elotte($eredmeny, $szerver, $keres)
    {
        // Csak a kívülről érkező (HTTP) REST-kérésre: a szerveren belüli `rest_do_request` hívásokat (előtöltés, blokkok,
        // más bővítmények) nem zárjuk el.
        if ($eredmeny !== null || !$keres instanceof WP_REST_Request || !(defined('REST_REQUEST') && REST_REQUEST) || self::belso()) {
            return $eredmeny;
        }

        if ($keres->get_method() === 'OPTIONS' || !self::nyilvanos_ut((string) $keres->get_route())) {
            return new WP_Error('nincs', 'Nincs ilyen cím.', ['status' => 404]);
        }

        return $eredmeny;
    }

    /**
     * A rendszer hibakódjai → semleges kód + magyar szöveg (null szöveg: a saját, magyar üzenet marad, csak a kód cserélődik).
     *
     * @return array{0: string, 1: ?string}|null
     */
    public static function hiba_semleges(string $kod, int $status): ?array
    {
        if ($kod !== '' && strpos($kod, 'rest_') !== 0 && stripos($kod, 'sdh') === false) {
            return null;
        }

        if (stripos($kod, 'sdh') !== false) {
            return [$status === 401 || $status === 403 ? 'tiltott' : ($status === 404 ? 'nincs' : 'hiba'), null];
        }

        $m = [
            'rest_no_route'                => ['nincs', 'Nincs ilyen cím.'],
            'rest_forbidden'               => ['tiltott', 'Ehhez nincs jogosultságod.'],
            'rest_cannot_view'             => ['tiltott', 'Ehhez nincs jogosultságod.'],
            'rest_cannot_edit'             => ['tiltott', 'Ehhez nincs jogosultságod.'],
            'rest_not_logged_in'           => ['tiltott', 'Ehhez be kell jelentkezned.'],
            'rest_cookie_invalid_nonce'    => ['lejart', 'A munkamenet lejárt – töltsd újra az oldalt.'],
            'rest_invalid_param'           => ['hibas_adat', 'Hibás adat a kérésben.'],
            'rest_missing_callback_param'  => ['hianyzo_adat', 'Hiányzó adat a kérésben.'],
            'rest_invalid_json'            => ['hibas_adat', 'Hibás adat a kérésben.'],
            'rest_invalid_handler'         => ['hiba', 'A kérés most nem teljesíthető.'],
            'rest_no_route_options'        => ['nincs', 'Nincs ilyen cím.'],
        ];

        if (isset($m[$kod])) {
            return $m[$kod];
        }

        if ($status === 404) {
            return ['nincs', 'Nincs ilyen cím.'];
        }

        return $status === 401 || $status === 403 ? ['tiltott', 'Ehhez nincs jogosultságod.'] : ['hiba', 'A kérés most nem teljesíthető.'];
    }

    /** Hibaobjektum semlegesítése (a belső felhasználó a kódot megtartja, csak a szöveg magyar). */
    private static function wp_error_csere(WP_Error $e): WP_Error
    {
        $kod    = (string) $e->get_error_code();
        $adat   = $e->get_error_data();
        $status = is_array($adat) ? (int) ($adat['status'] ?? 500) : 500;
        $u      = self::hiba_semleges($kod, $status);

        if ($u === null) {
            return $e;
        }

        $uj_adat = ['status' => $status];

        if (is_array($adat) && isset($adat['params']) && is_array($adat['params'])) {
            $uj_adat['mezok'] = array_keys($adat['params']);
        }

        return new WP_Error(self::belso() ? $kod : $u[0], $u[1] ?? $e->get_error_message(), $uj_adat);
    }

    /**
     * @param mixed $valasz
     * @return mixed
     */
    public static function rest_hiba_csere($valasz, $kezelo = null, $keres = null)
    {
        return $valasz instanceof WP_Error ? self::wp_error_csere($valasz) : $valasz;
    }

    /**
     * A kész válasz: hibatörzs semlegesítése, a rendszer-fejlécek eltávolítása a válaszobjektumból.
     *
     * @param mixed $valasz
     * @return mixed
     */
    public static function rest_valasz_csere($valasz, $szerver = null, $keres = null)
    {
        if (!$valasz instanceof WP_HTTP_Response) {
            return $valasz;
        }

        $d = $valasz->get_data();

        if ($valasz->get_status() >= 400 && is_array($d) && isset($d['code'])) {
            $u = self::hiba_semleges((string) $d['code'], $valasz->get_status());

            if ($u !== null) {
                $d['code']    = self::belso() ? (string) $d['code'] : $u[0];
                $d['message'] = $u[1] ?? (string) ($d['message'] ?? '');

                if (isset($d['data']) && is_array($d['data'])) {
                    $d['data'] = ['status' => $valasz->get_status()] + (isset($d['data']['params']) && is_array($d['data']['params']) ? ['mezok' => array_keys($d['data']['params'])] : []);
                }

                unset($d['additional_errors']);
                $valasz->set_data($d);
            }
        }

        if (!self::belso()) {
            $h = $valasz->get_headers();
            unset($h['Link'], $h['X-WP-Total'], $h['X-WP-TotalPages'], $h['X-WP-Nonce']);
            $valasz->set_headers($h);
        }

        return $valasz;
    }

    /**
     * Kiszolgálás előtt (a fejlécek még nem mentek ki): `Link: …api.w.org`, és látogatónak / vevőnek az `X-WP-*` ki.
     *
     * @param mixed $kiszolgalva
     * @return mixed
     */
    public static function rest_fejlec_takarit($kiszolgalva, $valasz = null, $keres = null, $szerver = null)
    {
        if (!headers_sent()) {
            header_remove('Link');

            if (!self::belso()) {
                foreach (['X-WP-Nonce', 'X-WP-Total', 'X-WP-TotalPages'] as $f) {
                    header_remove($f);
                }
            }

            if (self::cors_kitett([]) === []) {
                header_remove('Access-Control-Expose-Headers');
            }
        }

        return $kiszolgalva;
    }

    /** @param array<int, string> $h @return array<int, string> */
    public static function cors_kitett(array $h): array
    {
        return array_values(array_diff($h, ['X-WP-Total', 'X-WP-TotalPages', 'Link']));
    }

    /** @param array<int, string> $h @return array<int, string> */
    public static function cors_engedett(array $h): array
    {
        $h = array_values(array_diff($h, ['X-WP-Nonce']));

        return in_array('X-App-Nonce', $h, true) ? $h : array_merge($h, ['X-App-Nonce']);
    }

    /* =================================================================
     * Korai szűrés: kényes címek 404, belépő
     * ============================================================== */

    public static function korai(): void
    {
        $fajl = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $ut   = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $rel  = $home !== '' && strpos($ut, $home) === 0 ? substr($ut, strlen($home)) : $ut;

        // A ?rest_route= kerülőút látogatónak / vevőnek – kivéve a nyilvános utakat (a vevők Műhelye így hívja a Központot).
        if (self::kerulout_tiltott()) {
            self::nincs();
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            self::nincs();
        }

        if (in_array($fajl, ['wp-links-opml.php', 'wp-trackback.php', 'wp-signup.php', 'wp-activate.php', 'wp-comments-post.php', 'wp-mail.php'], true)) {
            self::nincs();
        }

        if ($fajl === 'wp-cron.php' && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON && !self::helyi_keres()) {
            self::nincs();
        }

        if (isset($_GET['author']) && !is_admin()) {
            self::nincs();
        }

        if (!self::be('belepo')) {
            return;
        }

        if ($fajl === 'wp-login.php' && !self::belepobol()) {
            // Kilépés (bejelentkezett, nonce-szal) mehet; minden más 404.
            $kilep = (string) ($_GET['action'] ?? '') === 'logout' && is_user_logged_in();

            if (!$kilep) {
                self::nincs();
            }
        }

        if (is_admin() && !is_user_logged_in() && !wp_doing_ajax() && !(defined('DOING_CRON') && DOING_CRON)) {
            self::nincs();
        }
    }

    /** A `?rest_route=` alakú kérés tiltott-e (látogató / vevő, nem nyilvános útra). */
    public static function kerulout_tiltott(): bool
    {
        if (!isset($_GET['rest_route'])) {
            return false;
        }

        if (is_user_logged_in() && self::belso()) {
            return false;
        }

        return !self::nyilvanos_ut((string) wp_unslash($_GET['rest_route'])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    }

    /** A titkos belépő: a valódi belépőt szolgálja ki ezen a címen (wp_loaded, hogy minden bővítmény betöltött). */
    public static function belepo_kiszolgal(): void
    {
        if (!self::be('belepo')) {
            return;
        }

        $ut   = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $rel  = $home !== '' && strpos($ut, $home) === 0 ? substr($ut, strlen($home)) : $ut;

        if (rtrim($rel, '/') !== '/' . self::belepo_szelet()) {
            return;
        }

        global $pagenow, $user_login, $error, $interim_login, $action;
        $pagenow    = 'wp-login.php';
        $user_login = $user_login ?? '';
        $error      = $error ?? '';
        nocache_headers();
        require_once ABSPATH . 'wp-login.php';
        exit;
    }

    private static function belepobol(): bool
    {
        $ut = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return strpos($ut, '/' . self::belepo_szelet()) !== false;
    }

    private static function helyi_keres(): bool
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return in_array($ip, ['127.0.0.1', '::1'], true) || (string) ($_SERVER['SERVER_ADDR'] ?? '-') === $ip;
    }

    /** 404 a téma hibaoldalával (vagy egy semleges oldallal), és vége. */
    public static function nincs(): void
    {
        status_header(404);
        nocache_headers();
        header_remove('X-Powered-By');

        $sablon = function_exists('get_404_template') && did_action('setup_theme') ? get_404_template() : '';

        if ($sablon !== '' && is_file($sablon) && did_action('wp_loaded')) {
            global $wp_query;

            if ($wp_query instanceof WP_Query) {
                $wp_query->set_404();
            }

            include $sablon;
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="hu"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Az oldal nem található</title>'
            . '<body style="font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:15vh auto;padding:0 1rem"><h1>Az oldal nem található</h1><p><a href="'
            . esc_url(home_url('/')) . '">Vissza a kezdőlapra</a></p></body></html>';
        exit;
    }

    /** Szerző-archívum, csatolmány-oldal, hozzászólás-feed: 404. */
    public static function template_ellenor(): void
    {
        if (is_author() || is_attachment() || is_comment_feed()) {
            self::nincs();
        }

        if ((string) get_query_var('portal_sitemap') !== '') {
            self::sitemap();
        }
    }

    /* =================================================================
     * Fej, fejlécek, front-eszközök
     * ============================================================== */

    public static function fej_takarit(): void
    {
        foreach ([
            ['wp_head', 'wp_generator'], ['wp_head', 'rsd_link'], ['wp_head', 'wlwmanifest_link'], ['wp_head', 'wp_shortlink_wp_head'],
            ['wp_head', 'rest_output_link_wp_head'], ['wp_head', 'wp_oembed_add_discovery_links'], ['wp_head', 'wp_oembed_add_host_js'],
            ['wp_head', 'print_emoji_detection_script', 7], ['wp_print_styles', 'print_emoji_styles'], ['wp_head', 'wp_resource_hints', 2],
            ['wp_head', 'adjacent_posts_rel_link_wp_head'], ['wp_head', 'feed_links_extra', 3], ['wp_head', 'wp_print_speculation_rules'],
            ['template_redirect', 'rest_output_link_header', 11], ['template_redirect', 'wp_shortlink_header', 11],
            ['wp_footer', 'the_block_template_skip_link'], ['wp_body_open', 'wp_global_styles_render_svg_filters'],
            ['wp_enqueue_scripts', 'wp_enqueue_global_styles'], ['wp_footer', 'wp_enqueue_global_styles', 1],
            ['wp_head', 'wp_enqueue_img_auto_sizes_contain_css_fix', 0], ['wp_enqueue_scripts', 'wp_enqueue_emoji_styles'],
            ['wp_footer', 'wp_enqueue_stored_styles', 1], ['wp_head', 'wp_custom_css_cb', 101],
        ] as $h) {
            remove_action($h[0], $h[1], $h[2] ?? 10);
        }

        remove_action('wp_head', 'wp_print_font_faces', 50);
        remove_action('wp_head', 'wp_print_font_faces_from_style_variations', 50);

        // Szkriptmodulok (import map, @-os csomagnevek) – a front nem használja őket.
        if (function_exists('wp_script_modules') && !is_admin()) {
            $sm = wp_script_modules();

            foreach (['wp_head' => ['print_import_map', 'print_enqueued_script_modules', 'print_script_module_preloads'], 'wp_footer' => ['print_import_map', 'print_enqueued_script_modules', 'print_script_module_preloads', 'print_script_module_data']] as $hook => $fvk) {
                foreach ($fvk as $fv) {
                    foreach ([10, 9, 11, 20, 1] as $p) {
                        remove_action($hook, [$sm, $fv], $p);
                    }
                }
            }
        }

        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('emoji_svg_url', '__return_false');
        add_filter('wp_img_tag_add_auto_sizes', '__return_false');
    }

    public static function front_takarit(): void
    {
        if (is_admin()) {
            return;
        }

        // A bejelentkezett adminnak látszó admin-sávhoz kell a saját stílusa (különben formázatlan lista a lap tetején).
        $sav = is_admin_bar_showing();

        foreach (['wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles', 'core-block-supports', 'wp-emoji-styles', 'wp-img-auto-sizes-contain', 'dashicons', 'admin-bar'] as $s) {
            if ($sav && in_array($s, ['dashicons', 'admin-bar'], true)) {
                continue;
            }

            wp_dequeue_style($s);
            wp_deregister_style($s);
        }

        foreach (['wp-embed', 'comment-reply', 'admin-bar'] as $s) {
            if ($sav && $s === 'admin-bar') {
                continue;
            }

            wp_dequeue_script($s);
        }

        if (!(bool) apply_filters('sdh_sales_jquery_kell', false)) {
            wp_dequeue_script('jquery');
            wp_dequeue_script('jquery-core');
            wp_dequeue_script('jquery-migrate');
        }
    }

    public static function fejlecek(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header_remove('X-Pingback');
        }
    }

    /** @param array<string, string> $h */
    public static function wp_fejlecek(array $h): array
    {
        unset($h['X-Pingback'], $h['x-pingback'], $h['X-Powered-By']);

        return $h;
    }

    /** @param mixed $mutat */
    public static function admin_sav($mutat): bool
    {
        return (bool) $mutat && SDH_Sales::admin();
    }

    /** Csak a téma saját (s- / tema-) osztályai maradnak a <body>-n. @param array<int, string> $o */
    public static function body_osztaly(array $o): array
    {
        return array_values(array_filter($o, static fn ($c) => preg_match('/^(s|tema|oldal|lap)-[a-z0-9-]+$/', (string) $c) === 1));
    }

    public static function level_cim(string $cim): string
    {
        $be = sanitize_email((string) SDH_Sales::b('email.felado', ''));

        if ($be !== '') {
            return $be;
        }

        if (stripos($cim, 'wordpress@') === 0) {
            $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

            return 'noreply@' . preg_replace('/^www\./', '', $host);
        }

        return $cim;
    }

    public static function level_nev(string $nev): string
    {
        $be = trim((string) SDH_Sales::b('email.felado_nev', ''));

        if ($be !== '') {
            return $be;
        }

        return stripos($nev, 'wordpress') !== false || $nev === '' ? SDH_Sales::termeknev() : $nev;
    }

    /** A front hibaoldala nem árulja el a rendszert. */
    /**
     * @param callable|string $kezelo
     * @return callable|string
     */
    public static function die_kezelo($kezelo)
    {
        return is_admin() ? $kezelo : [self::class, 'die_oldal'];
    }

    /**
     * @param string|WP_Error $uzenet
     * @param string|int      $cim
     * @param array|int       $args
     */
    public static function die_oldal($uzenet, $cim = '', $args = []): void
    {
        $args   = is_array($args) ? $args : ['response' => (int) $args];
        $status = (int) ($args['response'] ?? 500);
        $szoveg = is_wp_error($uzenet) ? $uzenet->get_error_message() : (string) $uzenet;
        $szoveg = (string) preg_replace('~wordpress~i', '', wp_strip_all_tags($szoveg));

        if (!headers_sent()) {
            status_header($status ?: 500);
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
        }

        echo '<!doctype html><html lang="hu"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hiba</title>'
            . '<body style="font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:15vh auto;padding:0 1rem"><h1>Hiba történt</h1><p>' . esc_html($szoveg !== '' ? $szoveg : 'Próbáld újra később.')
            . '</p><p><a href="' . esc_url(home_url('/')) . '">Vissza a kezdőlapra</a></p></body></html>';

        if (empty($args['exit']) || $args['exit'] !== false) {
            exit;
        }
    }

    /** A robots.txt-ből kimaradnak a rendszer útvonalai (a SEO saját sorai maradnak). */
    public static function robots(string $s): string
    {
        $sorok = array_filter(explode("\n", $s), static fn ($l) => stripos($l, 'wp-') === false && stripos($l, 'sdh') === false);
        $ki    = implode("\n", $sorok);

        if (stripos($ki, 'sitemap:') === false) {
            $ki .= "\nSitemap: " . home_url('/sitemap.xml') . "\n";
        }

        return $ki;
    }

    public static function favicon(): void
    {
        $ikon = (string) get_site_icon_url(64);

        if ($ikon !== '') {
            wp_redirect(self::url_alias($ikon), 301);
            exit;
        }

        status_header(204);
        exit;
    }

    /** A webhely neve soha nem árulkodik: ha üres vagy tiltott szót tartalmaz, a termék neve. */
    public static function webhely_nev($nev): string
    {
        $nev = (string) $nev;

        return $nev === '' || preg_match('~(sdh|wordpress)~i', $nev) ? SDH_Sales::termeknev() : $nev;
    }

    public static function webhely_leiras($s): string
    {
        $s = (string) $s;

        return preg_match('~(sdh|wordpress|just another)~i', $s) ? (string) SDH_Sales::b('szlogen', '') : $s;
    }

    /** A rendszer telepítéskori mintatartalma (Hello world!, mintaoldal, mintahozzászólás, adatvédelmi vázlat) törlődik. */
    public static function alap_tartalom_takarit(): void
    {
        foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => ['publish', 'draft'], 'name' => '', 'posts_per_page' => 20, 'post__in' => [1, 2, 3]]) as $p) {
            if (in_array($p->post_name, ['hello-world', 'sample-page', 'privacy-policy', 'adatvedelmi-iranyelvek', 'minta-oldal', 'helló-világ'], true) || get_post_meta($p->ID, '_wp_page_template', true) === 'default' && strpos((string) $p->post_content, 'wp:paragraph') !== false && (int) $p->ID <= 3) {
                wp_delete_post($p->ID, true);
            }
        }

        foreach (get_comments(['number' => 5]) as $c) {
            if ((int) $c->comment_ID === 1) {
                wp_delete_comment((int) $c->comment_ID, true);
            }
        }

        if ((int) get_option('wp_page_for_privacy_policy') > 0 && get_post((int) get_option('wp_page_for_privacy_policy')) === null) {
            update_option('wp_page_for_privacy_policy', 0);
        }
    }

    /* =================================================================
     * Útvonal-álnevek
     * ============================================================== */

    public static function url_alias(string $url): string
    {
        // Mindenhol álnév – az adminban is, mert élesben a webszerver a valódi tartalom-útvonalakat 404-gyel válaszolja.
        if ($url === '') {
            return $url;
        }

        return self::alias_csere($url);
    }

    /** Egy szövegben a valódi útvonalak cseréje az álnevekre (a leghosszabb előre). */
    public static function alias_csere(string $s): string
    {
        $home = rtrim((string) get_option('home'), '/');
        $par  = [];

        foreach (self::aliasok() as $alias => $valodi) {
            $par[$home . '/' . $valodi . '/'] = $home . '/' . $alias . '/';
            $par['/' . $valodi . '/']         = '/' . $alias . '/';
        }

        foreach ($par as $a => $b) {
            $par[str_replace('/', '\/', $a)] = str_replace('/', '\/', $b);
        }

        uksort($par, static fn ($a, $b) => strlen($b) <=> strlen($a));

        // A záró perjel nélküli „…/wp-content/uploads” (pl. wp_upload_dir) a tartalom-álnévre fut: utólag a médiára.
        return strtr(strtr($s, $par), ['/static/uploads' => '/media', '\\/static\\/uploads' => '\\/media', '/static/plugins/' => '/static/m/', '/static/themes/' => '/static/t/']);
    }

    /** @param array<string, mixed> $u */
    public static function feltoltes_alias(array $u): array
    {
        foreach (['url', 'baseurl'] as $k) {
            if (!empty($u[$k])) {
                $u[$k] = self::alias_csere((string) $u[$k]);
            }
        }

        return $u;
    }

    /** @param array<int, array<string, mixed>>|false $f */
    public static function srcset_alias($f)
    {
        if (!is_array($f)) {
            return $f;
        }

        foreach ($f as $w => $e) {
            $f[$w]['url'] = self::alias_csere((string) $e['url']);
        }

        return $f;
    }

    /** Apache: az álnevek belső átírása + a valódi útvonalak közvetlen elérése 404. */
    public static function htaccess(string $szabalyok): string
    {
        $ki = "# Rejtés: útvonal-álnevek\n<IfModule mod_rewrite.c>\nRewriteEngine On\n";

        foreach (self::aliasok() as $alias => $valodi) {
            $ki .= 'RewriteRule ^' . preg_quote($alias, '') . '/(.*)$ ' . $valodi . "/\$1 [L]\n";
        }

        // A valódi útvonalak tiltása csak akkor, ha az álnevek bizonyítottan működnek (különben az oldal szétesne).
        if (self::alias_aktiv()) {
            $ki .= "RewriteCond %{THE_REQUEST} \\s/+(wp-content|wp-includes)/ [NC]\nRewriteRule ^ - [R=404,L]\n";
        }

        $ki .= "RewriteRule ^(readme\\.html|license\\.txt|wp-config-sample\\.php|xmlrpc\\.php)$ - [R=404,L]\n</IfModule>\n";
        $ki .= "<IfModule mod_headers.c>\nHeader always unset X-Powered-By\nHeader always unset X-Pingback\n</IfModule>\n\n";

        return $ki . $szabalyok;
    }

    public static function rewrite_frissit(): void
    {
        flush_rewrite_rules(true);
        delete_option(self::OPT_ALIAS);

        if (self::be('alias') && is_admin()) {
            self::alias_proba();
        }
    }

    /* =================================================================
     * Belépő
     * ============================================================== */

    /**
     * @param string $url
     * @param string $path
     * @param string|null $scheme
     */
    public static function belepo_url($url, $path = '', $scheme = null): string
    {
        $url = (string) $url;

        if (strpos($url, 'wp-login.php') === false) {
            return $url;
        }

        return (string) preg_replace('~/?wp-login\.php~', '/' . self::belepo_szelet() . '/', $url);
    }

    public static function atiranyit_szuro(string $hely): string
    {
        return strpos($hely, 'wp-login.php') !== false ? self::belepo_url($hely) : $hely;
    }

    public static function belepo_stilus(): void
    {
        echo '<style>#login h1 a{background:none!important;text-indent:0!important;width:auto!important;height:auto!important;font:700 1.6rem system-ui;color:#111}'
            . '#backtoblog,#nav,.language-switcher,.privacy-policy-page-link{display:none!important}</style>';
    }

    /* =================================================================
     * Saját sitemap (/sitemap.xml)
     * ============================================================== */

    public static function sitemap_szabaly(): void
    {
        add_rewrite_rule('^sitemap\.xml$', 'index.php?portal_sitemap=1', 'top');
    }

    public static function sitemap(): void
    {
        $tipusok = ['page', 'post'];
        $q       = new WP_Query(['post_type' => $tipusok, 'post_status' => 'publish', 'posts_per_page' => 2000, 'no_found_rows' => true, 'orderby' => 'modified', 'order' => 'DESC']);
        $kizart  = (array) apply_filters('sdh_sales_sitemap_kizart', ['kosar', 'penztar', 'fiok', 'kereses']);

        status_header(200);
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($q->posts as $p) {
            if (in_array((string) $p->post_name, $kizart, true) || get_post_meta($p->ID, '_sdh_noindex', true)) {
                continue;
            }

            echo '<url><loc>' . esc_url((string) get_permalink($p)) . '</loc><lastmod>' . esc_html(mysql2date('Y-m-d', (string) $p->post_modified_gmt)) . "</lastmod></url>\n";
        }

        echo "</urlset>\n";
        exit;
    }

    /* =================================================================
     * Kimeneti szűrő – utolsó védvonal
     * ============================================================== */

    public static function kimenet_indit(): void
    {
        if (is_admin() || is_feed() || is_robots() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || !self::be('kimenet')) {
            return;
        }

        ob_start([self::class, 'kimenet']);
    }

    public static function kimenet(string $html): string
    {
        if ($html === '' || stripos($html, '<html') === false) {
            return $html;
        }

        // A <script>/<style>/<pre>/<textarea> tartalmát érintetlenül hagyjuk (csak az álnév-csere fut rajtuk).
        $darabok = preg_split('~(<(script|style|pre|textarea)\b[^>]*>.*?</\2>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        $ki      = '';

        for ($i = 0, $n = count($darabok); $i < $n; $i++) {
            $d = (string) $darabok[$i];

            if (preg_match('~^<(script|style|pre|textarea)\b~i', $d)) {
                // Csak a nyitó címke id/class attribútuma változik, a tartalom nem.
                $d   = (string) preg_replace_callback('~^<[^>]+>~', static fn ($m) => (string) preg_replace('~\b(id|class)=(["\'])(wp|sdh)[-_]~i', '$1=$2x-', $m[0]), $d);
                $ki .= (self::alias_aktiv() ? self::alias_csere($d) : $d);
                $i++; // a csoport neve
                continue;
            }

            $d = (string) preg_replace('~<!--(?!\[if|<!\[endif).*?-->~s', '', $d);
            $d = (string) preg_replace('~<meta[^>]+name=["\']generator["\'][^>]*>\s*~i', '', $d);
            $d = (string) preg_replace('~<link[^>]+rel=["\'](https://api\.w\.org/|EditURI|wlwmanifest|shortlink|pingback)["\'][^>]*>\s*~i', '', $d);
            $d = (string) preg_replace_callback('~\b(class|id)=(["\'])(.*?)\2~s', static function ($m) {
                $ertek = (string) preg_replace('~(^|\s)wp-~', '$1x-', $m[3]);
                $ertek = (string) preg_replace('~(^|\s)sdh[-_]~i', '$1s-', $ertek);

                return $m[1] . '=' . $m[2] . $ertek . $m[2];
            }, $d);
            $ki .= (self::alias_aktiv() ? self::alias_csere($d) : $d);
        }

        return $ki;
    }

    /* =================================================================
     * Önellenőrzés
     * ============================================================== */

    /**
     * Kívülről (sütik nélkül) lekéri a kényes címeket, és megnézi a kezdőlap HTML-jét és fejléceit.
     *
     * @return array{ok: bool, talalatok: array<int, string>, ellenorzott: int}
     */
    public static function ellenoriz(): array
    {
        $home  = rtrim(home_url('/'), '/');
        $tal   = [];
        $vart  = [
            '/wp-login.php' => 404, '/wp-admin/' => 404, '/xmlrpc.php' => 404, '/readme.html' => 404, '/license.txt' => 404,
            '/wp-json/' => 404, '/?rest_route=/' => 404, '/?author=1' => 404, '/wp-includes/js/jquery/jquery.min.js' => 404,
            '/wp-content/' => 404, '/wp-sitemap.xml' => 404, '/wp-links-opml.php' => 404, '/' . self::api() . '/wp/v2/users' => 404,
        ];

        foreach ($vart as $ut => $kod) {
            $r = wp_remote_get($home . $ut, ['redirection' => 0, 'timeout' => 10, 'sslverify' => false, 'cookies' => []]);

            if (is_wp_error($r)) {
                continue;
            }

            $k = (int) wp_remote_retrieve_response_code($r);

            if ($k !== $kod && !($kod === 404 && in_array($k, [403, 410], true))) {
                $tal[] = $ut . ' → ' . $k . ' (várt: ' . $kod . ')';
            }
        }

        foreach (['/', '/arak/', '/funkciok/', '/blog/', '/nincs-ilyen-oldal-' . wp_rand(1000, 9999) . '/'] as $ut) {
            $r = wp_remote_get($home . $ut, ['timeout' => 15, 'sslverify' => false, 'cookies' => []]);

            if (is_wp_error($r)) {
                continue;
            }

            foreach (self::vizsgal((string) wp_remote_retrieve_body($r), (array) wp_remote_retrieve_headers($r)->getAll()) as $t) {
                $tal[] = $ut . ': ' . $t;
            }
        }

        return ['ok' => $tal === [], 'talalatok' => array_values(array_unique($tal)), 'ellenorzott' => count($vart) + 5];
    }

    /**
     * Egy válasz (törzs + fejlécek) árulkodó mintái.
     *
     * @param array<string, mixed> $fejlecek
     * @return array<int, string>
     */
    public static function vizsgal(string $torzs, array $fejlecek = []): array
    {
        $ki = [];

        foreach (self::TILTOTT as $minta) {
            if (preg_match($minta, $torzs, $m, PREG_OFFSET_CAPTURE)) {
                $poz  = (int) $m[0][1];
                $ki[] = 'tartalom: ' . $minta . ' … ' . trim(preg_replace('/\s+/', ' ', substr($torzs, max(0, $poz - 40), 100)) ?? '');
            }
        }

        foreach ($fejlecek as $nev => $ertek) {
            $sor = $nev . ': ' . (is_array($ertek) ? implode(', ', $ertek) : (string) $ertek);

            if (preg_match('~(x-powered-by|x-pingback|x-redirect-by)~i', (string) $nev) || preg_match('~(wordpress|api\.w\.org|wp-json|sdh)~i', $sor)) {
                $ki[] = 'fejléc: ' . $sor;
            }
        }

        return $ki;
    }
}
