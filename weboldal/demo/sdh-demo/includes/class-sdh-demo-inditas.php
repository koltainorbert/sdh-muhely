<?php
/**
 * Egykattintásos indítás: `?bemutato=indit` – POST-űrlap (Cloudflare Turnstile a szerveren ellenőrizve; ha nincs
 * kulcs: rejtett mező + időbélyeg) → ideiglenes felhasználó (szerep: bemutato, 24 óra, véletlen név és jelszó, amit
 * senki nem lát), belépés, irány a műhely. Címenként legfeljebb N indítás 24 óra alatt. Opcionális közös demo / demo
 * belépés (alapból ki). Óránként: a lejárt fiókok törlése.
 *
 * A látogató jelszót, profilt, e-mail-címet nem módosíthat, jelszót nem kérhet vissza.
 *
 * @package SDH_Demo
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Demo_Inditas
{
    /** A semleges GET-paraméter: ?bemutato=indit | allapot */
    public const VALTOZO = 'bemutato';

    /** Ennyi másodpercnél gyorsabb beküldés gépnek számít (Turnstile nélkül). */
    private const MIN_MP = 2;

    private const TURNSTILE_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function init(): void
    {
        add_action('init', [self::class, 'lejarat'], 3);
        add_action('init', [self::class, 'fogad'], 20);

        add_filter('allow_password_reset', [self::class, 'jelszo_tilt'], 10, 2);
        add_filter('show_password_fields', [self::class, 'jelszo_mezo'], 10, 2);
        add_action('user_profile_update_errors', [self::class, 'profil_tilt'], 10, 3);
        add_filter('wp_pre_insert_user_data', [self::class, 'adat_zar'], 10, 4);
    }

    /* =================================================================
     * Kérés: ?bemutato=…
     * ============================================================== */

    public static function fogad(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $m = isset($_GET[self::VALTOZO]) ? sanitize_key(wp_unslash((string) $_GET[self::VALTOZO])) : '';

        if ($m === '' || wp_doing_ajax() || is_admin()) {
            return;
        }

        if ($m === 'allapot') {
            SDH_Demo_Sav::allapot_json();
        }

        if ($m !== 'indit') {
            return;
        }

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Content-Type: text/html; charset=utf-8');

        $hiba = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $be = wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification -- saját jegy + Turnstile ellenőrzi.
            $r  = self::inditas(is_array($be) ? $be : [], SDH_Demo::ip());

            if (!empty($r['ok'])) {
                self::belep((int) $r['felhasznalo']);
                wp_safe_redirect(SDH_Muhely_Modulok::frontend_url(), 303);
                exit;
            }

            status_header((int) ($r['kod'] ?? 400));
            $hiba = (string) ($r['hiba'] ?? 'A bemutató most nem indítható.');
        } elseif (SDH_Demo::demo_felhasznalo() && !self::lejart(get_current_user_id())) {
            wp_safe_redirect(SDH_Muhely_Modulok::frontend_url(), 302);
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $lejart = isset($_GET['lejart']);
        echo self::oldal_html($hiba, $lejart); // phpcs:ignore WordPress.Security.EscapeOutput -- az oldal_html escapel.
        exit;
    }

    /** Az indító oldal. */
    public static function oldal_html(string $hiba = '', bool $lejart = false): string
    {
        $kulcs = (string) SDH_Demo::b('turnstile_kulcs');
        $ts    = $kulcs !== '' && SDH_Demo::titok('turnstile_titok') !== '';
        $ora   = (int) SDH_Demo::b('elet_ora');
        $nev   = SDH_Demo::termeknev();
        $cel   = add_query_arg(self::VALTOZO, 'indit', home_url('/'));
        $fej   = $ts ? '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>' : '';

        $h  = '<p>Próbáld ki a(z) ' . esc_html($nev) . ' rendszert regisztráció nélkül: egy kattintással saját, ideiglenes fiókot kapsz '
            . 'egy kitalált szerviz adataival (ügyfelek, készülékek, munkalapok, raktár, pénztár, naptár).</p>';
        $h .= '<ul class="bm-halvany">'
            . '<li>A fiók ' . (int) $ora . ' óráig él, jelszót nem kell megadnod.</li>'
            . '<li>Egy címről naponta legfeljebb ' . (int) SDH_Demo::b('keret') . ' új rekordot vehetsz fel; szerkeszteni bármit lehet.</li>'
            . '<li>SMS, e-mail és számla nem megy ki – a „Kimenő üzenetek” oldalon látod, mit kapna az ügyfél.</li>'
            . '<li>Minden éjjel 3:00-kor az adatok visszaállnak az eredeti állapotra.</li>'
            . '</ul>';

        if ($lejart) {
            $h .= '<p class="bm-hiba" role="status">A korábbi bemutató ideje lejárt. Indíts újat!</p>';
        }

        if ($hiba !== '') {
            $h .= '<p class="bm-hiba" role="alert">' . esc_html($hiba) . '</p>';
        }

        $h .= '<form method="post" action="' . esc_url($cel) . '">'
            . '<input type="hidden" name="bm_jegy" value="' . esc_attr(self::jegy()) . '">'
            . '<div class="bm-rejtett" aria-hidden="true"><label>Weboldal <input type="text" name="weboldal" tabindex="-1" autocomplete="off"></label></div>'
            . ($ts ? '<div class="cf-turnstile" data-sitekey="' . esc_attr($kulcs) . '" data-language="hu" style="margin:.8rem 0"></div>' : '')
            . '<p><button type="submit" class="bm-gomb">Bemutató indítása</button></p>'
            . '</form>';

        if (!empty(SDH_Demo::b('demo_demo'))) {
            $h .= '<p class="bm-halvany">Közös belépés is van: felhasználónév <strong>demo</strong>, jelszó <strong>demo</strong> – a '
                . '<a href="' . esc_url(wp_login_url()) . '">bejelentkező oldalon</a>.</p>';
        }

        $h .= '<p class="bm-halvany">A bemutató kitalált adatokkal dolgozik; amit itt rögzítesz, az is törlődik.</p>';

        return SDH_Demo::oldal('Bemutató indítása', $h, $fej);
    }

    /** Időbélyeges, aláírt jegy (a túl gyors, gépi beküldés ellen). */
    public static function jegy(?int $ido = null): string
    {
        $ido = $ido ?? time();

        return $ido . '.' . substr(hash_hmac('sha256', 'bemutato-jegy|' . $ido, wp_salt('nonce')), 0, 24);
    }

    private static function jegy_jo(string $jegy, int $most): bool
    {
        $r = explode('.', $jegy);

        if (count($r) !== 2 || !ctype_digit($r[0])) {
            return false;
        }

        $ido = (int) $r[0];

        return hash_equals(self::jegy($ido), $jegy) && $most - $ido >= self::MIN_MP && $most - $ido <= HOUR_IN_SECONDS;
    }

    /**
     * Egy indítás feldolgozása (tesztelhető mag – a belépést a hívó végzi).
     *
     * @param array<string, mixed> $be
     * @return array{ok: bool, felhasznalo?: int, hiba?: string, kod?: int}
     */
    public static function inditas(array $be, string $ip, ?int $most = null): array
    {
        $most = $most ?? time();

        if (trim((string) ($be['weboldal'] ?? '')) !== '' || !self::jegy_jo((string) ($be['bm_jegy'] ?? ''), $most)) {
            return ['ok' => false, 'kod' => 400, 'hiba' => 'Az ellenőrzés nem sikerült. Töltsd újra az oldalt, és próbáld újra.'];
        }

        if (!self::turnstile_jo((string) ($be['cf-turnstile-response'] ?? ''), $ip)) {
            return ['ok' => false, 'kod' => 400, 'hiba' => 'Az emberi ellenőrzés nem sikerült. Próbáld újra.'];
        }

        $hash = SDH_Demo::ip_hash($ip);
        $max  = (int) SDH_Demo::b('max_inditas_ip');

        if (SDH_Demo::naplo_db($hash, ['inditas']) >= $max) {
            return ['ok' => false, 'kod' => 429, 'hiba' => 'Erről a címről ma már ' . $max . ' bemutatót indítottak. Holnap újra próbálhatod – vagy kérj saját próbapéldányt.'];
        }

        $id = self::letrehoz($hash, $most);

        if ($id <= 0) {
            return ['ok' => false, 'kod' => 500, 'hiba' => 'A bemutató most nem indítható. Próbáld újra néhány perc múlva.'];
        }

        SDH_Demo::naplo('inditas', $hash, '', $id, $most);

        return ['ok' => true, 'felhasznalo' => $id];
    }

    /** A Turnstile-válasz ellenőrzése a szerveren (kulcs nélkül: nincs Turnstile, a rejtett mező + jegy véd). */
    public static function turnstile_jo(string $valasz, string $ip): bool
    {
        $titok = SDH_Demo::titok('turnstile_titok');

        if ((string) SDH_Demo::b('turnstile_kulcs') === '' || $titok === '') {
            return true;
        }

        if ($valasz === '' || strlen($valasz) > 2048) {
            return false;
        }

        $r = wp_remote_post(self::TURNSTILE_URL, [
            'timeout' => 8,
            'body'    => ['secret' => $titok, 'response' => $valasz, 'remoteip' => $ip],
        ]);

        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            return false;
        }

        $j = json_decode((string) wp_remote_retrieve_body($r), true);

        return is_array($j) && !empty($j['success']);
    }

    /** Az ideiglenes felhasználó (véletlen név és jelszó – senkinek nem jelenik meg). */
    public static function letrehoz(string $ip_hash, ?int $most = null): int
    {
        $most  = $most ?? time();
        $azon  = strtolower(wp_generate_password(10, false, false));
        $szam  = random_int(1000, 9999);
        $id    = wp_insert_user([
            'user_login'   => 'bemutato-' . $azon,
            'user_pass'    => wp_generate_password(40, true, true),
            'user_email'   => 'vendeg-' . $azon . '@pelda.hu',
            'display_name' => 'Vendég ' . $szam,
            'first_name'   => 'Vendég',
            'last_name'    => (string) $szam,
            'role'         => SDH_Demo::SZEREP,
        ]);

        if (is_wp_error($id)) {
            return 0;
        }

        update_user_meta((int) $id, SDH_Demo::META_LEJAR, $most + (int) SDH_Demo::b('elet_ora') * HOUR_IN_SECONDS);
        update_user_meta((int) $id, SDH_Demo::META_IP, $ip_hash);
        update_user_meta((int) $id, 'show_admin_bar_front', 'false');

        return (int) $id;
    }

    public static function belep(int $id): void
    {
        wp_set_current_user($id);
        wp_set_auth_cookie($id, false, is_ssl());
        SDH_Demo_Keret::felejt();
    }

    public static function lejart(int $id, ?int $most = null): bool
    {
        $lejar = (int) get_user_meta($id, SDH_Demo::META_LEJAR, true);

        return $lejar > 0 && $lejar <= ($most ?? time());
    }

    /** Lejárt fiókkal érkező kérés: kiléptetés, és vissza az indítóra (AJAX-ban érthető hiba). */
    public static function lejarat(): void
    {
        $id = get_current_user_id();

        if ($id <= 0 || !SDH_Demo::demo_felhasznalo($id) || !self::lejart($id)) {
            return;
        }

        wp_logout();

        if (wp_doing_ajax()) {
            SDH_Demo::megallit(401, 'A bemutató ideje lejárt. Indíts újat a kezdőoldalon.');
        }

        if (!is_admin() && !(defined('BEMUTATO_TESZT') && BEMUTATO_TESZT)) {
            wp_safe_redirect(add_query_arg([self::VALTOZO => 'indit', 'lejart' => '1'], home_url('/')), 302);
            exit;
        }
    }

    /* =================================================================
     * Fiókvédelem
     * ============================================================== */

    /** @param bool|WP_Error $ok */
    public static function jelszo_tilt($ok, $user_id)
    {
        return SDH_Demo::demo_felhasznalo((int) $user_id) ? false : $ok;
    }

    /** @param bool $mutat */
    public static function jelszo_mezo($mutat, $user = null): bool
    {
        $id = is_object($user) && isset($user->ID) ? (int) $user->ID : get_current_user_id();

        return SDH_Demo::demo_felhasznalo($id) ? false : (bool) $mutat;
    }

    /** A profil mentése (név, e-mail, jelszó) a bemutató-fióknak tiltva. */
    public static function profil_tilt(WP_Error $hibak, bool $frissites, $user): void
    {
        if ($frissites && is_object($user) && isset($user->ID) && SDH_Demo::demo_felhasznalo((int) $user->ID)) {
            $hibak->add('bemutato', 'A bemutató-fiók adatai nem módosíthatók.');
        }
    }

    /**
     * Meglévő bemutató-fióknál a jelszó, az e-mail és a belépési név nem változhat (csak a rendszer írhatja – pl.
     * belépéskori jelszó-újrakódolás –, és az sem a látogató kérésére). @param array<string, mixed> $adat
     * @return array<string, mixed>
     */
    public static function adat_zar(array $adat, bool $frissites, $user_id, array $nyers = []): array
    {
        if (!$frissites || !$user_id || !SDH_Demo::demo_felhasznalo((int) $user_id) || !is_user_logged_in()) {
            return $adat;
        }

        if ((int) get_current_user_id() !== (int) $user_id) {
            return $adat;
        }

        $regi = get_userdata((int) $user_id);

        if ($regi instanceof WP_User) {
            $adat['user_pass']  = $regi->user_pass;
            $adat['user_email'] = $regi->user_email;
            $adat['user_login'] = $regi->user_login;
        }

        return $adat;
    }

    /* =================================================================
     * Közös demo / demo fiók és takarítás
     * ============================================================== */

    /** A közös „demo” fiók a beállítás szerint: bekapcsolva létezik (jelszó: demo), kikapcsolva nincs. */
    public static function demo_demo_szinkron(): void
    {
        $u = get_user_by('login', 'demo');

        if (empty(SDH_Demo::b('demo_demo'))) {
            if ($u instanceof WP_User && SDH_Demo::demo_felhasznalo((int) $u->ID)) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user((int) $u->ID);
            }

            return;
        }

        if ($u instanceof WP_User) {
            if (!SDH_Demo::demo_felhasznalo((int) $u->ID)) {
                return; // Más célú, valódi „demo” fiókhoz nem nyúlunk.
            }

            if (!wp_check_password('demo', $u->user_pass, (int) $u->ID)) {
                wp_set_password('demo', (int) $u->ID);
            }

            return;
        }

        $id = wp_insert_user([
            'user_login'   => 'demo',
            'user_pass'    => 'demo',
            'user_email'   => 'demo@pelda.hu',
            'display_name' => 'Bemutató',
            'role'         => SDH_Demo::SZEREP,
        ]);

        if (!is_wp_error($id)) {
            update_user_meta((int) $id, SDH_Demo::META_LEJAR, 0);
        }
    }

    /**
     * Lejárt ideiglenes fiókok törlése ($mind: minden bemutató-fiók, a közös demo kivételével).
     *
     * @return int törölt fiókok
     */
    public static function takarit(bool $mind = false, ?int $most = null): int
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        $db = 0;

        foreach (get_users(['role' => SDH_Demo::SZEREP, 'fields' => ['ID', 'user_login'], 'number' => 2000]) as $u) {
            if ($u->user_login === 'demo' && !empty(SDH_Demo::b('demo_demo'))) {
                continue;
            }

            if ($mind || self::lejart((int) $u->ID, $most)) {
                $db += wp_delete_user((int) $u->ID) ? 1 : 0;
            }
        }

        return $db;
    }
}
