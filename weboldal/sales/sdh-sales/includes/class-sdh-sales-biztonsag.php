<?php
/**
 * SDH Értékesítés – biztonság: robotszűrés (Cloudflare Turnstile, tartalék: mézesbödön + kitöltési idő), CSP kérésenkénti
 * nonce-szal, biztonsági fejlécek, gyorsítótár-kizárás (ARCHITEKTURA.md 8.), titokszűrő a REST-kimeneten, és beépített
 * TOTP kétlépcsős belépés az adminoknak (RFC 6238, 10 tartalékkód; ötlet: a Műhely SDH_Muhely_Ketlepcsos osztálya).
 *
 * API (más modulok hívják):
 *   ellenoriz(WP_REST_Request $r): true|WP_Error   – minden nyilvános űrlap-végpont elején (rendelés, lead, egyedi, belépés)
 *   front_adat(): array{turnstile, hp, t}          – az űrlapos front-modulok adata (kulcs, mézesbödön-mező, időbélyeg-token)
 *   urlap_token(?int $ido = null): string          – aláírt kitöltés-kezdeti időbélyeg
 *   nonce(): string                                – a kérés CSP-nonce-a (saját <script> taghez)
 *   nem_cache(): void                              – a kérés ne kerüljön gyorsítótárba (kosár, pénztár, fiók…)
 * Szűrők: `sdh_sales_csp` (array irányelv => források[]) – más modul így ad hozzá forrást (pl. Szikra, süti, naptár);
 *         `sdh_sales_nem_cache_utak` (array útvonal-előtagok).
 * Beállítás: `biztonsag.csp` ('enforce' | 'report' | 'ki', alap 'report'), `biztonsag.hsts` (bool), `turnstile.kulcs`
 * (nyilvános), titok: `turnstile_titok`; `biztonsag.2fa_admin_kotelezo` (bool), `biztonsag.min_mp` (alap 3).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Biztonsag
{
    public const HP_MEZO       = 'weboldal_cim';
    public const TURNSTILE_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const META_2FA      = 'sdh_sales_2fa';
    public const META_TARTALEK = 'sdh_sales_2fa_tartalek';
    public const META_LEPES    = 'sdh_sales_2fa_lepes';

    private static string $nonce = '';

    public static function init(): void
    {
        // A front a REST-nonce-ot semleges fejlécben küldi (X-App-Nonce); a hitelesítés a megszokott helyen keresi.
        if (!empty($_SERVER['HTTP_X_APP_NONCE']) && empty($_SERVER['HTTP_X_WP_NONCE'])) {
            $_SERVER['HTTP_X_WP_NONCE'] = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_X_APP_NONCE']));
        }

        add_filter('wp_script_attributes', [self::class, 'script_nonce']);
        add_filter('wp_inline_script_attributes', [self::class, 'script_nonce']);
        add_action('send_headers', [self::class, 'fejlecek']);
        add_action('template_redirect', [self::class, 'cache_kizaras'], 1);
        add_filter('rocket_cache_reject_uri', [self::class, 'rocket_kizar']);
        add_filter('rest_post_dispatch', [self::class, 'rest_titokszuro'], 99, 3);

        // Kétlépcsős belépés az adminoknak.
        add_action('login_form', [self::class, 'login_mezo']);
        add_filter('authenticate', [self::class, 'login_kapu'], 60, 3);
        add_action('show_user_profile', [self::class, 'profil']);
        add_action('personal_options_update', [self::class, 'profil_ment']);
        add_action('admin_init', [self::class, 'kotelezo_kapu']);
        add_action('admin_notices', [self::class, 'kotelezo_uzenet']);
    }

    /* =================================================================
     * Robotszűrés
     * ============================================================== */

    public static function urlap_token(?int $ido = null): string
    {
        $ido = $ido ?? time();

        return $ido . '.' . substr(hash_hmac('sha256', 'urlap|' . $ido, wp_salt('nonce') . 'sdh-urlap'), 0, 20);
    }

    /** @return array{turnstile: string, hp: string, t: string} */
    public static function front_adat(): array
    {
        return [
            'turnstile' => self::turnstile_be() ? (string) SDH_Sales::b('turnstile.kulcs', '') : '',
            'hp'        => self::HP_MEZO,
            't'         => self::urlap_token(),
        ];
    }

    public static function turnstile_be(): bool
    {
        return trim((string) SDH_Sales::b('turnstile.kulcs', '')) !== '' && SDH_Sales::titkos('turnstile_titok') !== '';
    }

    /**
     * Nyilvános űrlap ellenőrzése. Mezők: `weboldal_cim` (mézesbödön, üres kell), `_t` (urlap_token), Turnstile esetén
     * `turnstile` vagy `cf-turnstile-response`.
     *
     * @return true|WP_Error
     */
    public static function ellenoriz(WP_REST_Request $r)
    {
        $alt = new WP_Error('robot', __('Az űrlapot nem sikerült ellenőrizni. Frissítsd az oldalt, és próbáld újra.', 'sdh-sales'), ['status' => 400]);

        if (trim((string) $r->get_param(self::HP_MEZO)) !== '') {
            SDH_Sales_Naplo::ir('biztonsag', 'Robotgyanús beküldés (mézesbödön): ' . (string) $r->get_route(), 'figyel');

            return $alt;
        }

        if (self::turnstile_be()) {
            $v = (string) ($r->get_param('turnstile') ?: $r->get_param('cf-turnstile-response'));

            if ($v === '' || strlen($v) > 2048) {
                return $alt;
            }

            $valasz = wp_remote_post(self::TURNSTILE_URL, [
                'timeout' => 8,
                'body'    => ['secret' => SDH_Sales::titkos('turnstile_titok'), 'response' => $v, 'remoteip' => SDH_Sales::ip()],
            ]);

            if (!is_wp_error($valasz) && (int) wp_remote_retrieve_response_code($valasz) === 200) {
                $j = json_decode((string) wp_remote_retrieve_body($valasz), true);

                if (is_array($j) && !empty($j['success'])) {
                    return true;
                }

                SDH_Sales_Naplo::ir('biztonsag', 'Turnstile elutasította a beküldést: ' . implode(', ', array_map('strval', (array) ($j['error-codes'] ?? []))), 'figyel');

                return $alt;
            }

            // A Cloudflare nem érhető el: a tartalék szabály dönt (az űrlap ne álljon le miatta).
            SDH_Sales_Naplo::ir('biztonsag', 'Turnstile nem érhető el – a tartalék (kitöltési idő) ellenőrzés döntött.', 'figyel');
        }

        $t = (string) $r->get_param('_t');

        if (preg_match('/^(\d{9,11})\.([0-9a-f]{20})$/', $t, $m) !== 1 || !hash_equals(self::urlap_token((int) $m[1]), $t)) {
            return $alt;
        }

        $kor = time() - (int) $m[1];

        if ($kor < max(0, (int) SDH_Sales::b('biztonsag.min_mp', 3))) {
            SDH_Sales_Naplo::ir('biztonsag', 'Robotgyanús beküldés (túl gyors): ' . (string) $r->get_route(), 'figyel');

            return $alt;
        }

        if ($kor > 30 * DAY_IN_SECONDS) {
            return $alt;
        }

        return true;
    }

    /* =================================================================
     * CSP, fejlécek
     * ============================================================== */

    public static function nonce(): string
    {
        if (self::$nonce === '') {
            self::$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        }

        return self::$nonce;
    }

    private static function front_keres(): bool
    {
        return !is_admin() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST) && !(defined('WP_CLI') && WP_CLI)
            && strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), 'wp-login.php') === false;
    }

    /** @param array<string, mixed> $a */
    public static function script_nonce(array $a): array
    {
        if (self::csp_mod() !== 'ki' && self::front_keres()) {
            $a['nonce'] = self::nonce();
        }

        return $a;
    }

    public static function csp_mod(): string
    {
        $m = (string) SDH_Sales::b('biztonsag.csp', 'report');

        return in_array($m, ['enforce', 'report', 'ki'], true) ? $m : 'report';
    }

    /** A CSP szöveg (a fejléchez; tesztben közvetlenül is hívható). */
    public static function csp(): string
    {
        $n = "'nonce-" . self::nonce() . "'";
        $i = [
            'default-src'     => ["'self'"],
            'script-src'      => ["'self'", $n, 'https://challenges.cloudflare.com'],
            'style-src'       => ["'self'", "'unsafe-inline'"],
            'img-src'         => ["'self'", 'data:', 'blob:', 'https:'],
            'font-src'        => ["'self'", 'data:'],
            'connect-src'     => ["'self'", 'https://challenges.cloudflare.com'],
            'frame-src'       => ["'self'", 'https://challenges.cloudflare.com'],
            'media-src'       => ["'self'", 'blob:'],
            'worker-src'      => ["'self'", 'blob:'],
            'object-src'      => ["'none'"],
            'base-uri'        => ["'self'"],
            'form-action'     => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        $i = (array) apply_filters('sdh_sales_csp', $i, self::nonce());
        $r = [];

        foreach ($i as $k => $f) {
            $k = strtolower((string) preg_replace('/[^a-z-]/i', '', (string) $k));

            if ($k === '') {
                continue;
            }

            $f   = array_values(array_unique(array_filter(array_map(static fn ($x): string => (string) preg_replace('/[;\r\n,]/', '', (string) $x), (array) $f))));
            $r[] = trim($k . ' ' . implode(' ', $f));
        }

        return implode('; ', $r);
    }

    /** @return array<string, string> a front biztonsági fejlécei (név => érték) */
    public static function fejlec_lista(): array
    {
        $h = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(), usb=(), payment=(self), interest-cohort=()',
            'X-Frame-Options'        => 'SAMEORIGIN',
        ];

        $m = self::csp_mod();

        if ($m === 'enforce') {
            $h['Content-Security-Policy'] = self::csp();
        } elseif ($m === 'report') {
            $h['Content-Security-Policy-Report-Only'] = self::csp();
        }

        if ((bool) SDH_Sales::b('biztonsag.hsts', false) && is_ssl()) {
            $h['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $h;
    }

    public static function fejlecek(): void
    {
        if (!self::front_keres() || headers_sent()) {
            return;
        }

        foreach (self::fejlec_lista() as $k => $v) {
            header($k . ': ' . $v);
        }
    }

    /* =================================================================
     * Gyorsítótár-kizárás
     * ============================================================== */

    /** @return array<int, string> */
    public static function nem_cache_utak(): array
    {
        $u = [];

        foreach (['kosar' => 'kosar', 'penztar' => 'penztar', 'fiok' => 'fiok', 'demo' => 'demo', 'rendeles' => 'rendeles'] as $k => $alap) {
            $u[] = '/' . trim((string) SDH_Sales::b('oldal.' . $k, $alap), '/') . '/';
        }

        return array_values(array_unique((array) apply_filters('sdh_sales_nem_cache_utak', $u)));
    }

    public static function nem_cache(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        if (!headers_sent()) {
            nocache_headers();
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        }
    }

    public static function cache_kizaras(): void
    {
        $ut = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $ut = '/' . ltrim(substr($ut, strlen((string) wp_parse_url(home_url('/'), PHP_URL_PATH)) - 1), '/');

        foreach (self::nem_cache_utak() as $e) {
            if (strpos(trailingslashit($ut), $e) === 0) {
                self::nem_cache();

                return;
            }
        }
    }

    /** @param array<int, string> $uri */
    public static function rocket_kizar($uri): array
    {
        $uri = is_array($uri) ? $uri : [];

        foreach (self::nem_cache_utak() as $e) {
            $uri[] = $e . '(.*)';
        }

        $uri[] = '/' . rest_get_url_prefix() . '/' . SDH_Sales_Rest::NS . '/(.*)';

        return array_values(array_unique($uri));
    }

    /* =================================================================
     * REST titokszűrő (védőháló: titok soha nem megy ki a saját végpontjainkon)
     * ============================================================== */

    /** @return mixed */
    public static function rest_titokszuro($valasz, $szerver, $keres)
    {
        if (!$keres instanceof WP_REST_Request || strpos((string) $keres->get_route(), '/' . SDH_Sales_Rest::NS) !== 0 || !$valasz instanceof WP_REST_Response) {
            return $valasz;
        }

        $adat = $valasz->get_data();

        if (!is_array($adat)) {
            return $valasz;
        }

        $valasz->set_data(self::szur($adat, self::titkok()));

        return $valasz;
    }

    /** @return array<int, string> a tárolt titkok nyílt értékei (legalább 8 karakter) */
    private static function titkok(): array
    {
        $t  = get_option(SDH_Sales::OPT_TITOK, []);
        $ki = [];

        foreach (array_keys(is_array($t) ? $t : []) as $nev) {
            $v = SDH_Sales::titkos((string) $nev);

            if (strlen($v) >= 8) {
                $ki[] = $v;
            }
        }

        return $ki;
    }

    /**
     * @param array<mixed>        $a
     * @param array<int, string>  $titkok
     * @return array<mixed>
     */
    public static function szur(array $a, array $titkok): array
    {
        foreach ($a as $k => $v) {
            if (is_string($k) && preg_match('/(^|_)(titok|secret|poskey|pos_key|jelszo|password|agent_kulcs|api_kulcs|token_hash)$/i', $k) === 1) {
                unset($a[$k]);
                continue;
            }

            if (is_array($v)) {
                $a[$k] = self::szur($v, $titkok);
            } elseif (is_string($v) && $titkok !== []) {
                foreach ($titkok as $t) {
                    if (strpos($v, $t) !== false) {
                        $a[$k] = str_replace($t, '***', $v);
                        $v     = $a[$k];
                    }
                }
            }
        }

        return $a;
    }

    /* =================================================================
     * TOTP (RFC 6238, SHA1, 6 jegy, 30 mp, ±1 lépés)
     * ============================================================== */

    public static function base32(string $bin): string
    {
        $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bit = '';

        foreach (str_split($bin) as $c) {
            $bit .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }

        $ki = '';

        foreach (str_split($bit, 5) as $d) {
            $ki .= $abc[bindec(str_pad($d, 5, '0'))];
        }

        return $ki;
    }

    public static function base32_vissza(string $s): string
    {
        $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $s   = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $s));
        $bit = '';

        foreach (str_split($s) as $c) {
            $p = strpos($abc, $c);

            if ($p !== false) {
                $bit .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
            }
        }

        $ki = '';

        foreach (str_split($bit, 8) as $b) {
            if (strlen($b) === 8) {
                $ki .= chr((int) bindec($b));
            }
        }

        return $ki;
    }

    public static function totp(string $titok_b32, int $lepes): string
    {
        $h = hash_hmac('sha1', pack('N2', ($lepes >> 32) & 0xffffffff, $lepes & 0xffffffff), self::base32_vissza($titok_b32), true);
        $o = ord($h[19]) & 0x0f;
        $n = (((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3])) % 1000000;

        return str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /** Illeszkedő lépés (± 1), vagy null. Az $utolso lépésig nem fogad el (egy kód egyszer). */
    public static function totp_illik(string $titok_b32, string $kod, int $utolso = 0, ?int $ido = null): ?int
    {
        $kod = (string) preg_replace('/\D/', '', $kod);

        if (strlen($kod) !== 6 || $titok_b32 === '') {
            return null;
        }

        $most = intdiv($ido ?? time(), 30);

        for ($d = -1; $d <= 1; $d++) {
            if ($most + $d > $utolso && hash_equals(self::totp($titok_b32, $most + $d), $kod)) {
                return $most + $d;
            }
        }

        return null;
    }

    public static function muhely_intezi(): bool
    {
        // Ha a Műhely is fut ugyanitt a saját kétlépcsős belépésével, az a gazda – ne kérjünk kétszer kódot.
        return class_exists('SDH_Muhely_Ketlepcsos') && method_exists('SDH_Muhely_Ketlepcsos', 'ki') && !SDH_Muhely_Ketlepcsos::ki();
    }

    private static function titok_nev(int $uid): string
    {
        return '2fa_' . $uid;
    }

    public static function aktiv(int $uid): bool
    {
        return (bool) get_user_meta($uid, self::META_2FA, true) && SDH_Sales::titkos(self::titok_nev($uid)) !== '';
    }

    public static function kotelezo(WP_User $u): bool
    {
        return !self::muhely_intezi() && (bool) SDH_Sales::b('biztonsag.2fa_admin_kotelezo', false) && (user_can($u, 'manage_options') || user_can($u, SDH_Sales::JOG));
    }

    /** @return array<int, string> az új tartalékkódok (csak most látszanak) */
    public static function bekapcsol(int $uid, string $titok_b32, int $lepes = 0): array
    {
        SDH_Sales::titkos_ment(self::titok_nev($uid), $titok_b32);
        update_user_meta($uid, self::META_2FA, 1);
        update_user_meta($uid, self::META_LEPES, $lepes);

        return self::tartalek_uj($uid);
    }

    public static function kikapcsol(int $uid): void
    {
        SDH_Sales::titkos_ment(self::titok_nev($uid), '');
        delete_user_meta($uid, self::META_2FA);
        delete_user_meta($uid, self::META_TARTALEK);
        delete_user_meta($uid, self::META_LEPES);
        SDH_Sales_Naplo::ir('biztonsag', 'Kétlépcsős belépés kikapcsolva (#' . $uid . ').', 'figyel', 'user', $uid);
    }

    private static function tartalek_lenyomat(string $kod): string
    {
        return hash_hmac('sha256', strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $kod)), wp_salt('auth') . 'sdh-2fa');
    }

    /** @return array<int, string> */
    public static function tartalek_uj(int $uid): array
    {
        $kodok = [];
        $lny   = [];

        for ($i = 0; $i < 10; $i++) {
            $k       = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
            $kodok[] = substr($k, 0, 5) . '-' . substr($k, 5);
            $lny[]   = self::tartalek_lenyomat($k);
        }

        update_user_meta($uid, self::META_TARTALEK, $lny);

        return $kodok;
    }

    /** Kód ellenőrzése (TOTP vagy tartalékkód). Üres szöveg = jó, különben a hiba. */
    public static function kod_ellenoriz(int $uid, string $kod): string
    {
        if (!SDH_Sales_Rest::korlat('2fa', 10, 900, (string) $uid)) {
            return __('Túl sok hibás kód. Próbáld újra 15 perc múlva.', 'sdh-sales');
        }

        $titok  = SDH_Sales::titkos(self::titok_nev($uid));
        $utolso = (int) get_user_meta($uid, self::META_LEPES, true);
        $l      = self::totp_illik($titok, $kod, $utolso);

        if ($l !== null) {
            update_user_meta($uid, self::META_LEPES, $l);

            return '';
        }

        $lny   = self::tartalek_lenyomat($kod);
        $lista = (array) get_user_meta($uid, self::META_TARTALEK, true);

        foreach ($lista as $i => $h) {
            if (is_string($h) && hash_equals($h, $lny)) {
                unset($lista[$i]);
                update_user_meta($uid, self::META_TARTALEK, array_values($lista));
                SDH_Sales_Naplo::ir('biztonsag', 'Tartalékkóddal lépett be (#' . $uid . '), maradt: ' . count($lista) . '.', 'figyel', 'user', $uid);

                return '';
            }
        }

        return __('Hibás kétlépcsős kód.', 'sdh-sales');
    }

    public static function login_mezo(): void
    {
        if (self::muhely_intezi()) {
            return;
        }

        echo '<p><label for="kod_2fa">' . esc_html__('Kétlépcsős kód (ha be van kapcsolva)', 'sdh-sales') . '</label>'
            . '<input type="text" name="kod_2fa" id="kod_2fa" class="input" inputmode="numeric" autocomplete="one-time-code" size="20"></p>';
    }

    /**
     * @param WP_User|WP_Error|null $u
     * @return WP_User|WP_Error|null
     */
    public static function login_kapu($u, $nev = '', $jelszo = '')
    {
        if (!$u instanceof WP_User || self::muhely_intezi() || !self::aktiv((int) $u->ID)) {
            return $u;
        }

        // Csak a jelszavas űrlapra (alkalmazásjelszó / REST hitelesítés nem ide fut).
        if (!isset($_POST['log'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return $u;
        }

        $kod  = sanitize_text_field(wp_unslash((string) ($_POST['kod_2fa'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification
        $hiba = $kod === '' ? __('Add meg a kétlépcsős kódot is.', 'sdh-sales') : self::kod_ellenoriz((int) $u->ID, $kod);

        return $hiba === '' ? $u : new WP_Error('kod_2fa', $hiba);
    }

    public static function kotelezo_kapu(): void
    {
        $u = wp_get_current_user();

        if (!$u->exists() || wp_doing_ajax() || !self::kotelezo($u) || self::aktiv((int) $u->ID)) {
            return;
        }

        global $pagenow;

        if ($pagenow !== 'profile.php') {
            wp_safe_redirect(admin_url('profile.php#ketlepcsos'));
            exit;
        }
    }

    public static function kotelezo_uzenet(): void
    {
        $u = wp_get_current_user();

        if ($u->exists() && self::kotelezo($u) && !self::aktiv((int) $u->ID)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Az adminisztrátoroknak kötelező a kétlépcsős belépés. Állítsd be lent, a „Kétlépcsős belépés” résznél.', 'sdh-sales') . '</p></div>';
        }
    }

    public static function profil(WP_User $u): void
    {
        if (self::muhely_intezi() || !(user_can($u, 'manage_options') || user_can($u, SDH_Sales::JOG))) {
            return;
        }

        $uid = (int) $u->ID;
        echo '<h2 id="ketlepcsos">' . esc_html__('Kétlépcsős belépés', 'sdh-sales') . '</h2><table class="form-table" role="presentation"><tr><th>' . esc_html__('Állapot', 'sdh-sales') . '</th><td>';
        wp_nonce_field('sdh_sales_2fa', '_k2fa');

        $uj = get_transient('sdh_sales_2fa_kodok_' . $uid);

        if (is_array($uj)) {
            delete_transient('sdh_sales_2fa_kodok_' . $uid);
            echo '<p><strong>' . esc_html__('Tartalékkódjaid (csak most látszanak, írd fel őket):', 'sdh-sales') . '</strong></p><pre>' . esc_html(implode("\n", $uj)) . '</pre>';
        }

        if (self::aktiv($uid)) {
            $db = count((array) get_user_meta($uid, self::META_TARTALEK, true));
            echo '<p>' . esc_html(sprintf(__('Bekapcsolva. Fel nem használt tartalékkód: %d.', 'sdh-sales'), $db)) . '</p>';
            echo '<p><label><input type="checkbox" name="k2fa_uj_tartalek" value="1"> ' . esc_html__('Új tartalékkódok', 'sdh-sales') . '</label></p>';
            echo '<p><label><input type="checkbox" name="k2fa_ki" value="1"> ' . esc_html__('Kikapcsolás', 'sdh-sales') . '</label></p>';
            echo '<p><label for="k2fa_megerosit">' . esc_html__('A változtatáshoz add meg az aktuális kódot:', 'sdh-sales') . '</label> <input type="text" id="k2fa_megerosit" name="k2fa_megerosit" inputmode="numeric" autocomplete="one-time-code"></p>';
        } else {
            $titok = get_transient('sdh_sales_2fa_uj_' . $uid);

            if (!is_string($titok) || $titok === '') {
                $titok = self::base32(random_bytes(20));
                set_transient('sdh_sales_2fa_uj_' . $uid, $titok, HOUR_IN_SECONDS);
            }

            $kiado = SDH_Sales::termeknev();
            $url   = 'otpauth://totp/' . rawurlencode($kiado) . ':' . rawurlencode((string) $u->user_login) . '?secret=' . $titok . '&issuer=' . rawurlencode($kiado) . '&algorithm=SHA1&digits=6&period=30';

            echo '<p>' . esc_html__('Olvasd be a kódot egy hitelesítő alkalmazással (Google Authenticator, Microsoft Authenticator, Aegis…), majd írd be az első kódot.', 'sdh-sales') . '</p>';
            echo '<img alt="' . esc_attr__('QR-kód a hitelesítő alkalmazáshoz', 'sdh-sales') . '" width="200" height="200" src="' . esc_attr(SDH_Sales_Qr::svg_uri($url)) . '">';
            echo '<p><code>' . esc_html(trim(implode(' ', str_split($titok, 4)))) . '</code></p>';
            echo '<p><label for="k2fa_kod_be">' . esc_html__('Az első kód:', 'sdh-sales') . '</label> <input type="text" id="k2fa_kod_be" name="k2fa_kod_be" inputmode="numeric" autocomplete="one-time-code"></p>';
        }

        echo '</td></tr></table>';
    }

    public static function profil_ment(int $uid): void
    {
        if (!isset($_POST['_k2fa']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_k2fa'])), 'sdh_sales_2fa') || get_current_user_id() !== $uid) {
            return;
        }

        $p = static fn (string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));

        if (self::aktiv($uid)) {
            if (($p('k2fa_ki') === '1' || $p('k2fa_uj_tartalek') === '1') && self::kod_ellenoriz($uid, $p('k2fa_megerosit')) === '') {
                if ($p('k2fa_ki') === '1') {
                    self::kikapcsol($uid);
                } else {
                    set_transient('sdh_sales_2fa_kodok_' . $uid, self::tartalek_uj($uid), 10 * MINUTE_IN_SECONDS);
                }
            }

            return;
        }

        $titok = (string) get_transient('sdh_sales_2fa_uj_' . $uid);
        $l     = $titok !== '' ? self::totp_illik($titok, $p('k2fa_kod_be')) : null;

        if ($l !== null) {
            delete_transient('sdh_sales_2fa_uj_' . $uid);
            set_transient('sdh_sales_2fa_kodok_' . $uid, self::bekapcsol($uid, $titok, $l), 10 * MINUTE_IN_SECONDS);
            SDH_Sales_Naplo::ir('biztonsag', 'Kétlépcsős belépés bekapcsolva (#' . $uid . ').', 'siker', 'user', $uid);
        }
    }
}
