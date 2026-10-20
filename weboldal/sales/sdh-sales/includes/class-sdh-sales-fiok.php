<?php
/**
 * SDH Értékesítés – ügyfélfiók jelszó nélkül: e-mailes belépő link (magic link) és a fiók adatai.
 *
 * Belépés: POST /fiok/belepes {email} → ha van ilyen vevő, levél egy 30 percig érvényes, egyszer használható linkkel
 * (a token csak lenyomatként – SHA-256 – áll a `fiok_link` táblában). A válasz MINDIG ugyanaz (nincs felhasználó-felderítés),
 * IP-nként és címenként korlátozva. A link: <fiók-oldal>?belepes=<token> → GET /fiok/belep?token= (a fiok.js hívja)
 * → bejelentkeztet (süti), a token azonnal törlődik.
 * Fiók: GET /fiok → rendelések (állapot, összeg, számla-linkek, fizetési adatok), licencek (lejárat, megújítás / bővítés),
 * letöltések (a kiszolgálási láncból), foglalások, egyedi fejlesztési igények, oktatás (`sdh_sales_fiok_oktatas` szűrő).
 * POST /fiok/kilepes. Megújítás / bővítés rendelése: POST /rendeles {tipus: 'megujitas'|'bovites', licenc_kulcs}.
 * A rendelések, licencek és letöltések a fiókhoz (user_id) kötöttek. A vevő (felhasználó-kezelési jog nélkül) a saját
 * e-mail-címét / felhasználónevét / jelszavát semmilyen úton (core REST, profil-oldal, más bővítmény) nem módosíthatja –
 * ezt csak az adminisztrátor teheti (`email_vedelem`, `rest_vedelem`, `profil_hiba`).
 *
 *   link_kuld(string $email): bool          – igaz, ha ment ki levél (a REST-válasz ettől függetlenül egyforma)
 *   link_letrehoz(int $user_id, string $email, string $cel = ''): string – a token (teszt, admin)
 *   belep(string $token): int               – user_id (0 = érvénytelen / lejárt / már használt)
 *   adat(int $user_id): array               – a fiók teljes adata
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Fiok
{
    public const ERVENY_PERC = 30;

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/fiok/belepes', 'POST', [self::class, 'rest_belepes'], 'nyilvanos', ['fiok_belepes', 5, 900]);
        SDH_Sales_Rest::ut('/fiok/belep', 'POST', [self::class, 'rest_belep'], 'nyilvanos', ['fiok_belep', 20, 900]);
        SDH_Sales_Rest::ut('/fiok', 'GET', [self::class, 'rest_fiok'], 'fiok', ['fiok', 120, 60]);
        SDH_Sales_Rest::ut('/fiok/kilepes', 'POST', [self::class, 'rest_kilepes'], 'fiok', ['fiok_kilepes', 20, 60]);
        add_action('init', [self::class, 'front'], 6);
        add_action(SDH_Sales::CRON, [self::class, 'takarit'], 30, 0);
        add_filter('show_admin_bar', [self::class, 'admin_sav'], 99);
        add_filter('wp_pre_insert_user_data', [self::class, 'email_vedelem'], 99, 4);
        add_filter('rest_pre_dispatch', [self::class, 'rest_vedelem'], 4, 3);
        add_action('user_profile_update_errors', [self::class, 'profil_hiba'], 10, 3);
        add_filter('send_email_change_email', [self::class, 'csere_level'], 10, 3);
    }

    /** Módosíthatja-e a bejelentkezett felhasználó a fiókok azonosító adatait (e-mail, név, jelszó). */
    public static function fiok_szerkeszthet(): bool
    {
        return current_user_can('edit_users') || current_user_can('manage_options');
    }

    /**
     * Utolsó védvonal: bármely úton (REST, profil, más kód) érkező e-mail-csere a nem adminisztrátor saját fiókján
     * visszaáll a régi címre. Rendszerfolyamat (nincs bejelentkezett felhasználó) és adminisztrátor nem érintett.
     *
     * @param array<string, mixed> $adat
     * @return array<string, mixed>
     */
    public static function email_vedelem($adat, $frissit = false, $user_id = null, $nyers = [])
    {
        if (!is_array($adat) || !$frissit || (int) $user_id <= 0 || get_current_user_id() === 0 || self::fiok_szerkeszthet()) {
            return $adat;
        }

        $regi = get_userdata((int) $user_id);

        if (!$regi instanceof WP_User) {
            return $adat;
        }

        if (isset($adat['user_email']) && strtolower((string) $adat['user_email']) !== strtolower((string) $regi->user_email)) {
            SDH_Sales_Naplo::ir('fiok', 'Elutasított e-mail-csere a vevői fiókon (#' . (int) $user_id . ').', 'figyel', 'user', (int) $user_id);
            $adat['user_email'] = (string) $regi->user_email;
        }

        if (isset($adat['user_login']) && (string) $adat['user_login'] !== (string) $regi->user_login) {
            $adat['user_login'] = (string) $regi->user_login;
        }

        return $adat;
    }

    /**
     * Core REST: a felhasználó-végpontok írása (e-mail, név, jelszó…) nem adminisztrátornak tiltott – a belső
     * `rest_do_request` hívásokra is (ott a hitelesítési szűrő nem fut).
     *
     * @param mixed $eredmeny
     * @return mixed
     */
    public static function rest_vedelem($eredmeny, $szerver, $keres)
    {
        if ($eredmeny !== null || !$keres instanceof WP_REST_Request || self::fiok_szerkeszthet()) {
            return $eredmeny;
        }

        if (preg_match('~^/wp/v2/users(/|$)~', (string) $keres->get_route()) === 1 && $keres->get_method() !== 'GET') {
            return new WP_Error('tiltott', __('A fiók adatait ügyfélszolgálatunk módosítja – írj nekünk.', 'sdh-sales'), ['status' => 403]);
        }

        return $eredmeny;
    }

    /** Profil-oldal: e-mail-csere nem adminisztrátornak hibaüzenettel. @param WP_Error $hibak */
    public static function profil_hiba($hibak, $frissit = false, $user = null): void
    {
        if (!$frissit || !is_object($user) || empty($user->ID) || self::fiok_szerkeszthet() || !$hibak instanceof WP_Error) {
            return;
        }

        $regi = get_userdata((int) $user->ID);

        if ($regi instanceof WP_User && isset($user->user_email) && strtolower((string) $user->user_email) !== strtolower((string) $regi->user_email)) {
            $hibak->add('email', __('Az e-mail-címet ügyfélszolgálatunk módosítja – írj nekünk.', 'sdh-sales'));
        }
    }

    /** E-mail-csere értesítő: nem adminisztrátor cseréje nem történik meg, így levél sem megy. @param mixed $kuld */
    public static function csere_level($kuld, $regi = [], $uj = [])
    {
        return self::fiok_szerkeszthet() ? $kuld : false;
    }

    public static function front(): void
    {
        SDH_Sales_Front::modul('fiok', 'assets/front/fiok.js', ['kozos'], static fn (): array => [
            'belepve'  => is_user_logged_in(),
            'vedelem'  => SDH_Sales_Biztonsag::front_adat(),
            'perc'     => self::ERVENY_PERC,
            'penztar'  => SDH_Sales_Kosar::penztar_url(),
            'kosar'    => SDH_Sales_Kosar::oldal_url('kosar', 'kosar'),
            'egyedi'   => home_url(user_trailingslashit((string) SDH_Sales::b('oldal.egyedi', 'egyedi-fejlesztes'))),
        ]);
    }

    /** A vevő ne lássa a rendszer felső sávját. */
    public static function admin_sav(bool $l): bool
    {
        return $l && SDH_Sales::admin();
    }

    public static function fiok_url(array $query = []): string
    {
        return SDH_Sales_Kosar::oldal_url('fiok', 'fiok', $query);
    }

    /* =================================================================
     * Belépő link
     * ============================================================== */

    public static function lenyomat(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function link_letrehoz(int $user_id, string $email, string $cel = ''): string
    {
        global $wpdb;

        $token = SDH_Sales::token(32);
        $wpdb->insert(SDH_Sales_Schema::t('fiok_link'), [
            'token_hash' => self::lenyomat($token),
            'email'      => strtolower($email),
            'user_id'    => $user_id,
            'cel'        => mb_substr($cel, 0, 255),
            'lejar'      => gmdate('Y-m-d H:i:s', time() + self::ERVENY_PERC * MINUTE_IN_SECONDS),
        ]);

        return $token;
    }

    /** Vevő-e (vásárolt vagy vevői fiókja van). */
    private static function vevo(string $email): ?WP_User
    {
        global $wpdb;

        $u = get_user_by('email', $email);

        if (!$u instanceof WP_User) {
            return null;
        }

        if (in_array('sdh_vevo', (array) $u->roles, true)) {
            return $u;
        }

        $van = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE user_id = %d OR (user_id = 0 AND email = %s)', (int) $u->ID, strtolower($email)));

        // Adminisztrátor e-mailes linkkel nem léphet be (az ő belépése jelszó + kétlépcsős kód).
        return $van > 0 && !user_can($u, 'manage_options') && !user_can($u, SDH_Sales::JOG) ? $u : null;
    }

    public static function link_kuld(string $email, string $cel = ''): bool
    {
        $email = strtolower(sanitize_email($email));

        if (!is_email($email)) {
            return false;
        }

        $u = self::vevo($email);

        if ($u === null) {
            SDH_Sales_Naplo::ir('fiok', 'Belépő link kérése ismeretlen címre.', 'info');

            return false;
        }

        $token = self::link_letrehoz((int) $u->ID, $email, $cel);
        $url   = self::fiok_url(['belepes' => $token]);

        return SDH_Sales_Fizetes_Atutalas::level('fiok_belepes', $email, [
            '_targy'        => __('Belépés a fiókodba', 'sdh-sales'),
            'nev'           => (string) ($u->first_name ?: $u->display_name),
            'belepes_url'   => $url,
            'ervenyes_perc' => (string) self::ERVENY_PERC,
        ]);
    }

    /** A token beváltása: egyszer, lejárat előtt. Visszaad: user_id vagy 0. */
    public static function belep(string $token): int
    {
        global $wpdb;

        if (preg_match('/^[A-Za-z0-9_-]{30,60}$/', $token) !== 1) {
            return 0;
        }

        $t = SDH_Sales_Schema::t('fiok_link');
        $h = self::lenyomat($token);
        $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE token_hash = %s", $h), ARRAY_A);

        if (!is_array($s)) {
            return 0;
        }

        // Egyszer használható: a törlés dönt (két párhuzamos beváltásból csak egy töröl sort).
        $torolt = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE token_hash = %s", $h));

        if ($torolt !== 1 || strtotime((string) $s['lejar'] . ' UTC') < time()) {
            return 0;
        }

        $u = get_userdata((int) $s['user_id']);

        if (!$u instanceof WP_User || strtolower((string) $u->user_email) !== strtolower((string) $s['email'])) {
            return 0;
        }

        return (int) $u->ID;
    }

    public static function takarit(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Sales_Schema::t('fiok_link') . ' WHERE lejar < %s', gmdate('Y-m-d H:i:s')));
    }

    /* =================================================================
     * Fiók adatai
     * ============================================================== */

    /** @return array<string, mixed> */
    public static function adat(int $uid): array
    {
        global $wpdb;

        $u     = get_userdata($uid);
        $email = $u instanceof WP_User ? strtolower((string) $u->user_email) : '';
        $t     = SDH_Sales_Schema::t('rendeles');
        // A rendelések a fiókhoz (user_id) kötöttek; e-mail szerint csak a fiók nélküli (user_id = 0) régi rendelések.
        $ids   = (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$t} WHERE user_id = %d OR (user_id = 0 AND email = %s) ORDER BY id DESC LIMIT 200", $uid, $email));
        $rend  = [];
        $lic   = [];
        $let   = [];

        foreach ($ids as $id) {
            $r = SDH_Sales_Rendeles::rendeles((int) $id);

            if ($r === null) {
                continue;
            }

            $ny            = SDH_Sales_Rendeles::nyilvanos($r);
            $ny['statusz'] = SDH_Sales_Rendeles::statusz_url($r);
            unset($ny['atutalas']['qr_svg']);
            $rend[]        = $ny;

            if ((string) $r['licenc_kulcs'] !== '' && in_array((string) $r['allapot'], ['fizetve', 'kiszolgalva'], true) && !isset($lic[(string) $r['licenc_kulcs']])) {
                $lic[(string) $r['licenc_kulcs']] = $r;
            }

            $k = (array) ($r['lanc']['lepesek']['kozpont']['eredmeny'] ?? []);

            if (!empty($k['letoltes_url']) && in_array((string) $r['allapot'], ['fizetve', 'kiszolgalva'], true)) {
                $let[] = ['rendeles' => (string) $r['azon'], 'url' => esc_url_raw((string) $k['letoltes_url']), 'cim' => sprintf(__('Telepítőcsomag – %s', 'sdh-sales'), (string) $r['azon'])];
            }
        }

        $tar      = class_exists('SDH_Sales_Kiszolgalas') && method_exists('SDH_Sales_Kiszolgalas', 'licencek') ? SDH_Sales_Kiszolgalas::licencek() : [];
        $licencek = [];
        $ma       = wp_date('Y-m-d');

        foreach ($lic as $kulcs => $r) {
            $kulcs = (string) $kulcs;
            $kr    = SDH_Sales_Rendeles::licenc_keret($kulcs, (array) (SDH_Sales_Rendeles::licenc_sajat($kulcs, $uid) ?? $r));
            $lejar = (string) ($tar[$kulcs]['lejar'] ?? $kr['lejar']);
            $c     = $kr['csomag'] !== '' ? SDH_Sales_Katalogus::csomag($kr['csomag']) : null;

            $licencek[] = [
                'kulcs'       => $kulcs,
                'csomag'      => (string) ($c['nev'] ?? $kr['csomag']),
                'csomag_kulcs' => $kr['csomag'],
                'modulok'     => SDH_Sales_Katalogus::csomag_modulok($kr['csomag']) === ['*'] ? ['*'] : array_values(array_unique(array_merge(SDH_Sales_Katalogus::csomag_modulok($kr['csomag']), $kr['modulok']))),
                'felhasznalo' => $kr['felhasznalo'],
                'telephely'   => $kr['telephely'],
                'ciklus'      => $kr['ciklus'],
                'lejar'       => $lejar,
                'lejart'      => $lejar !== '' && strcmp($lejar, $ma) < 0,
                'hamarosan'   => $lejar !== '' && strcmp($lejar, wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS)) <= 0,
            ];
        }

        $foglalas = [];

        if ($email !== '') {
            $ft = SDH_Sales_Schema::t('foglalas');

            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT tipus, kezdet, veg, allapot FROM {$ft} WHERE email = %s ORDER BY kezdet DESC LIMIT 50", $email), ARRAY_A) as $f) {
                $foglalas[] = ['tipus' => (string) $f['tipus'], 'kezdet' => (string) $f['kezdet'], 'veg' => (string) $f['veg'], 'allapot' => (string) $f['allapot']];
            }
        }

        return [
            'nev'        => $u instanceof WP_User ? (string) ($u->first_name ?: $u->display_name) : '',
            'email'      => $email,
            'rendelesek' => $rend,
            'licencek'   => $licencek,
            'letoltesek' => $let,
            'foglalasok' => $foglalas,
            'egyedi'     => class_exists('SDH_Sales_Egyedi') ? SDH_Sales_Egyedi::fiok_lista($email) : [],
            'oktatas'    => (array) apply_filters('sdh_sales_fiok_oktatas', [], $uid),
            'modulok'    => array_values(array_map(static fn (array $m): array => ['kulcs' => (string) $m['kulcs'], 'nev' => (string) ($m['nev'] ?? $m['kulcs']), 'havi' => (int) ($m['havi'] ?? 0)], SDH_Sales_Katalogus::modulok())),
        ];
    }

    /* =================================================================
     * REST
     * ============================================================== */

    public static function rest_belepes(WP_REST_Request $q): WP_REST_Response
    {
        $email = strtolower(sanitize_email((string) $q->get_param('email')));
        $v     = SDH_Sales_Biztonsag::ellenoriz($q);

        if (is_email($email) && !is_wp_error($v) && SDH_Sales_Rest::korlat('fiok_belepes_cim', 3, 3600, $email)) {
            self::link_kuld($email, sanitize_text_field((string) $q->get_param('cel')));
        }

        // Mindig ugyanaz a válasz: nem derül ki, van-e ilyen vevő.
        return new WP_REST_Response(['ok' => true, 'uzenet' => sprintf(__('Ha ezzel a címmel van fiókod, %d percen belül megérkezik a belépő link. Nézd meg a levélszemét mappát is.', 'sdh-sales'), 5)], 200);
    }

    public static function rest_belep(WP_REST_Request $q)
    {
        $uid = self::belep((string) $q->get_param('token'));

        if ($uid === 0) {
            return SDH_Sales_Rest::hiba(__('A belépő link lejárt vagy már felhasználtad. Kérj újat.', 'sdh-sales'), 401, 'link');
        }

        wp_set_current_user($uid);

        if (!headers_sent()) {
            wp_set_auth_cookie($uid, false, is_ssl());
        }

        SDH_Sales_Naplo::ir('fiok', 'Belépés e-mailes linkkel (#' . $uid . ').', 'info', 'user', $uid);

        return new WP_REST_Response(['ok' => true, 'nonce' => wp_create_nonce('wp_rest')], 200);
    }

    public static function rest_fiok(): WP_REST_Response
    {
        return new WP_REST_Response(self::adat(get_current_user_id()), 200);
    }

    public static function rest_kilepes(): WP_REST_Response
    {
        wp_logout();

        return new WP_REST_Response(['ok' => true], 200);
    }
}
