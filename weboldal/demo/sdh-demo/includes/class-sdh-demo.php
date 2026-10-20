<?php
/**
 * A bemutató magja: beállítások, táblák, a látogatói szerep, a hálózati kapu (semmi nem megy ki), a számlázás
 * mintára kényszerítése, az ütemezett feladatok (éjjeli visszaállítás 03:00 Europe/Budapest, óránkénti takarítás)
 * és a közös leállító (érthető magyar hibaüzenet AJAX-ban és oldalon).
 *
 * @package SDH_Demo
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Leállítás tesztben (a kérés helyett kivétel: a tesztek a kódot és az üzenetet nézik). */
final class SDH_Demo_Megallitva extends RuntimeException
{
}

final class SDH_Demo
{
    public const OPT      = 'sdh_demo_beallitas';
    public const ALLAPOT  = 'sdh_demo_allapot';
    public const OPT_DB   = 'sdh_demo_db';
    public const DB_VERZIO = '1.0.0';

    /** A látogató WordPress-szerepe (belső név, a felületen „Bemutató”). */
    public const SZEREP     = 'bemutato';
    public const META_LEJAR = 'bemutato_lejar';
    public const META_IP    = 'bemutato_ip';

    public const CRON_EJJEL    = 'sdh_demo_ejjeli';
    public const CRON_ORANKENT = 'sdh_demo_orankenti';
    public const IDOZONA       = 'Europe/Budapest';

    public const HALOZAT_UZENET = 'A bemutatóból külső szolgáltatás nem érhető el (SMS, e-mail, számla, frissítés, mentés). A saját rendszeredben ez működik.';
    public const SZAMLA_UZENET  = 'A bemutatóban valódi számla nem készül: a számlázás a saját rendszeredben, a saját Számlázz.hu-kulcsoddal működik. A számla előnézete és a helyi nyomtatvány itt is kipróbálható.';

    /** @var array<string, mixed>|null */
    private static ?array $b = null;

    /** @var array<string, string> a Számlázz.hu-beállítás kényszerített mezői (kérésenként egyszer titkosítva) */
    private static ?array $szamla = null;

    /* =================================================================
     * Indulás
     * ============================================================== */

    /** A bővítmény betöltésekor (a műhely előtt is lehet): a szűrők, amelyeknek a legkorábban élniük kell. */
    public static function korai(): void
    {
        // Tesztüzem a beállítástól függetlenül: SMS szimuláció, levél / számla / Google-mentés tiltva.
        add_filter('sdh_muhely_tesztuzem', '__return_true', PHP_INT_MAX);

        // Kimenő levél: elfogás (a „Kimenő üzenetek” nézetbe), soha nem megy ki.
        add_filter('pre_wp_mail', [SDH_Demo_Kimeno::class, 'level'], 0, 2);

        // Hálózat: kifelé csak a Turnstile-ellenőrzés (és tesztkulccsal a Számlázz.hu teszt-fiókja) mehet.
        add_filter('pre_http_request', [self::class, 'http_kapu'], 0, 3);

        // A látogatói rekordkeret: a {prefix}sdh_… táblákba írt új sorok számlálása / tiltása.
        add_filter('query', [SDH_Demo_Keret::class, 'szuro'], 1);

        // Kétlépcsős belépés a bemutatóban nincs (a látogató nem állíthat be, és nem kérjük tőle).
        add_filter('option_sdh_muhely_ketlepcsos', [self::class, 'ketlepcsos_ki']);
        add_filter('default_option_sdh_muhely_ketlepcsos', [self::class, 'ketlepcsos_ki']);

        // Számlázás: kulcs csak a bemutató tesztkulcsa, minden előtag „MINTA”, a vevő nem kap levelet.
        add_filter('option_sdh_muhely_szamla', [self::class, 'szamla_opcio']);
        add_filter('default_option_sdh_muhely_szamla', [self::class, 'szamla_opcio']);
    }

    public static function init(): void
    {
        if (!self::muhely_kesz()) {
            add_action('admin_notices', static function (): void {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p><strong>Bemutató:</strong> a műhelyrendszer (0.99.5+) nincs bekapcsolva – a bemutató így nem működik.</p></div>';
                }
            });

            return;
        }

        add_action('init', [self::class, 'telepit_ha_kell'], 6);
        add_action('init', [self::class, 'cron_beallit'], 30);
        add_action(self::CRON_EJJEL, [self::class, 'ejjeli']);
        add_action(self::CRON_ORANKENT, [self::class, 'orankenti']);

        SDH_Demo_Keret::init();
        SDH_Demo_Inditas::init();
        SDH_Demo_Kimeno::init();
        SDH_Demo_Vedelem::init();
        SDH_Demo_Sav::init();
        SDH_Demo_Admin::init();
    }

    public static function muhely_kesz(): bool
    {
        return class_exists('SDH_Muhely_Schema') && class_exists('SDH_Muhely_Modulok') && class_exists('SDH_Muhely_Halozat');
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    /** @return array<string, mixed> */
    public static function alapok(): array
    {
        return [
            'ertekesito_url'     => '',     // az értékesítő webhely (a sávon „Megrendelem”)
            'turnstile_kulcs'    => '',     // Cloudflare Turnstile – webhelykulcs (nyilvános)
            'turnstile_titok'    => '',     // titkos kulcs (titkosítva tárolva)
            'demo_demo'          => false,  // közös demo / demo belépés (alapból ki)
            'max_inditas_ip'     => 5,      // egy címről ennyi bemutató indítható 24 óra alatt
            'keret'              => 10,     // új rekord / cím / 24 óra
            'iras_max'           => 300,    // bármilyen írás / cím / 24 óra (árasztás ellen)
            'elet_ora'           => 24,     // az ideiglenes fiók élettartama
            'beallitas_mentes'   => false,  // a látogató mentheti-e a (nem érzékeny) beállításokat
            'szamlazz_teszt_kulcs' => '',   // Számlázz.hu TESZTFIÓK Agent-kulcsa (titkosítva); üres = semmi nem megy ki
        ];
    }

    /** @return array<string, mixed> */
    public static function beallitas(): array
    {
        if (self::$b === null) {
            $m       = get_option(self::OPT, []);
            self::$b = array_merge(self::alapok(), is_array($m) ? array_intersect_key($m, self::alapok()) : []);
        }

        return self::$b;
    }

    /** @return mixed */
    public static function b(string $kulcs)
    {
        return self::beallitas()[$kulcs] ?? (self::alapok()[$kulcs] ?? null);
    }

    /** @param array<string, mixed> $uj */
    public static function ment(array $uj): void
    {
        $b = array_merge(self::beallitas(), array_intersect_key($uj, self::alapok()));

        $b['ertekesito_url']   = esc_url_raw((string) $b['ertekesito_url'], ['https', 'http']);
        $b['turnstile_kulcs']  = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $b['turnstile_kulcs']);
        $b['demo_demo']        = !empty($b['demo_demo']);
        $b['beallitas_mentes'] = !empty($b['beallitas_mentes']);
        $b['max_inditas_ip']   = max(1, min(100, (int) $b['max_inditas_ip']));
        $b['keret']            = max(1, min(1000, (int) $b['keret']));
        $b['iras_max']         = max(20, min(10000, (int) $b['iras_max']));
        $b['elet_ora']         = max(1, min(168, (int) $b['elet_ora']));

        update_option(self::OPT, $b, false);
        self::$b      = null;
        self::$szamla = null;
    }

    /** Titok mentése (a műhely titkosításával, ha elérhető). Üres érték: a régi marad. */
    public static function titok_ment(string $kulcs, string $nyilt, bool $torol = false): void
    {
        $nyilt = trim($nyilt);

        if ($torol) {
            self::ment([$kulcs => '']);

            return;
        }

        if ($nyilt === '') {
            return;
        }

        self::ment([$kulcs => class_exists('SDH_Muhely_Titok') ? SDH_Muhely_Titok::titkosit($nyilt) : $nyilt]);
    }

    public static function titok(string $kulcs): string
    {
        $t = (string) self::b($kulcs);

        return $t !== '' && class_exists('SDH_Muhely_Titok') ? (string) SDH_Muhely_Titok::visszafejt($t) : $t;
    }

    /** @return array<string, mixed> */
    public static function allapot(): array
    {
        $a = get_option(self::ALLAPOT, []);

        return is_array($a) ? $a : [];
    }

    /** @param array<string, mixed> $mezok */
    public static function allapot_ment(array $mezok): void
    {
        update_option(self::ALLAPOT, array_merge(self::allapot(), $mezok), false);
    }

    /** Teszthez: a kérésen belüli tárolt beállítás eldobása. */
    public static function felejt(): void
    {
        self::$b      = null;
        self::$szamla = null;
    }

    /* =================================================================
     * Táblák, szerep
     * ============================================================== */

    public static function tabla(string $nev): string
    {
        global $wpdb;

        return $wpdb->prefix . 'bemutato_' . $nev;
    }

    public static function telepit_ha_kell(): void
    {
        if (get_option(self::OPT_DB) !== self::DB_VERZIO || get_role(self::SZEREP) === null) {
            self::telepit();
        }
    }

    public static function telepit(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $cs     = $wpdb->get_charset_collate();
        $naplo  = self::tabla('naplo');
        $kimeno = self::tabla('kimeno');

        dbDelta("CREATE TABLE {$naplo} (
            id bigint(20) unsigned NOT NULL auto_increment,
            ip_hash char(32) NOT NULL default '',
            tipus varchar(12) NOT NULL default '',
            tabla varchar(40) NOT NULL default '',
            rekord_id bigint(20) unsigned NOT NULL default 0,
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            ido bigint(20) NOT NULL default 0,
            torolve tinyint(1) NOT NULL default 0,
            PRIMARY KEY  (id),
            key ip_tipus_ido (ip_hash,tipus,ido),
            key tipus_ido (tipus,ido)
        ) {$cs};");

        dbDelta("CREATE TABLE {$kimeno} (
            id bigint(20) unsigned NOT NULL auto_increment,
            csatorna varchar(8) NOT NULL default 'email',
            cimzett varchar(255) NOT NULL default '',
            targy varchar(255) NOT NULL default '',
            szoveg mediumtext NULL,
            html tinyint(1) NOT NULL default 0,
            felhasznalo bigint(20) unsigned NOT NULL default 0,
            forras_id bigint(20) unsigned NOT NULL default 0,
            ido bigint(20) NOT NULL default 0,
            PRIMARY KEY  (id),
            key felhasznalo (felhasznalo,id),
            key csatorna_forras (csatorna,forras_id)
        ) {$cs};");

        // A látogató: a műhely használata és adminisztrátori (beállítás-megtekintő) joga – WordPress-jog semmi más.
        if (get_role(self::SZEREP) !== null) {
            remove_role(self::SZEREP);
        }

        $jogok = ['read' => true];

        if (class_exists('SDH_Muhely_Admin_UI')) {
            $jogok[SDH_Muhely_Admin_UI::JOG]       = true;
            $jogok[SDH_Muhely_Admin_UI::ADMIN_JOG] = true;
        }

        add_role(self::SZEREP, 'Bemutató', $jogok);
        update_option(self::OPT_DB, self::DB_VERZIO, false);
    }

    /** A látogatói szerep jogai (a tesztek ellenőrzik, hogy ennél több nincs). @return array<int, string> */
    public static function szerep_jogai(): array
    {
        $r = get_role(self::SZEREP);

        return $r ? array_keys(array_filter($r->capabilities)) : [];
    }

    public static function demo_felhasznalo(?int $id = null): bool
    {
        $id = $id ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0);

        if ($id <= 0) {
            return false;
        }

        $u = get_userdata($id);

        return $u instanceof WP_User && in_array(self::SZEREP, (array) $u->roles, true);
    }

    /* =================================================================
     * Látogató azonosítása (IP – csak kivonatként tárolva)
     * ============================================================== */

    public static function ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        // Proxy / CDN mögött (pl. Cloudflare): a webszerver állítsa a valódi REMOTE_ADDR-t, vagy ez a szűrő.
        $ip = (string) apply_filters('sdh_demo_ip', $ip);

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function ip_hash(?string $ip = null): string
    {
        return substr(hash_hmac('sha256', $ip ?? self::ip(), wp_salt('auth') . 'bemutato'), 0, 32);
    }

    /** Egy napló-sor (indítás, rekord, írás). */
    public static function naplo(string $tipus, string $ip_hash, string $tabla = '', int $felhasznalo = 0, ?int $ido = null): int
    {
        global $wpdb;

        $wpdb->insert(self::tabla('naplo'), [
            'ip_hash'     => $ip_hash,
            'tipus'       => $tipus,
            'tabla'       => $tabla,
            'felhasznalo' => $felhasznalo,
            'ido'         => $ido ?? time(),
        ]);

        return (int) $wpdb->insert_id;
    }

    /** Hány ilyen sor van a címhez az elmúlt $mp másodpercben. @param array<int, string> $tipusok */
    public static function naplo_db(string $ip_hash, array $tipusok, int $mp = DAY_IN_SECONDS): int
    {
        global $wpdb;

        $hely = implode(',', array_fill(0, count($tipusok), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::tabla('naplo') . " WHERE ip_hash = %s AND tipus IN ({$hely}) AND ido > %d",
            array_merge([$ip_hash], $tipusok, [time() - $mp])
        ));
    }

    /* =================================================================
     * Leállítás érthető üzenettel
     * ============================================================== */

    /**
     * A kérés leállítása. AJAX-ban a műhely hibaútján (JSON: success=false, data.uzenet – a felület kiírja); a popupba
     * HTML-t váró végpontnál rövid HTML; egyébként saját, semleges oldal. Tesztben kivétel.
     */
    public static function megallit(int $kod, string $uzenet): void
    {
        if (defined('BEMUTATO_TESZT') && BEMUTATO_TESZT) {
            throw new SDH_Demo_Megallitva($uzenet, $kod);
        }

        nocache_headers();

        if (wp_doing_ajax()) {
            $akcio = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

            if (substr($akcio, -6) === '_urlap') {
                status_header($kod);
                echo '<h2>Bemutató</h2><div class="bm-hiba" role="alert" style="padding:.8rem 1rem;border-radius:10px;border:1px solid #c0392b;background:rgba(192,57,43,.08)">'
                    . esc_html($uzenet) . '</div>';
                exit;
            }

            wp_send_json_error(['uzenet' => $uzenet, 'bemutato' => true], $kod);
        }

        status_header($kod);
        header('Content-Type: text/html; charset=utf-8');
        $vissza = class_exists('SDH_Muhely_Modulok') ? SDH_Muhely_Modulok::frontend_url() : home_url('/');
        echo self::oldal('Bemutató', '<p>' . esc_html($uzenet) . '</p><p><a class="bm-gomb" href="' . esc_url($vissza) . '">Vissza a rendszerbe</a></p>'); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /** A termék neve (a műhely beállításából – soha nincs beégetve). */
    public static function termeknev(): string
    {
        return class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::termeknev() : 'Bemutató';
    }

    /**
     * Önálló, semleges oldal (indító, elutasítás). Színek CSS-változókban, sötét módban is olvasható.
     * $tartalom: már escapelt HTML.
     */
    public static function oldal(string $cim, string $tartalom, string $fej_extra = ''): string
    {
        $nev = self::termeknev();

        return '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . esc_html($cim . ' – ' . $nev) . '</title>'
            . '<style>'
            . ':root{--bm-bg:#f4f5f7;--bm-lap:#fff;--bm-szoveg:#1d2330;--bm-halvany:#5b6475;--bm-vonal:#d5d9e2;--bm-kiemelo:#1f5fd6;--bm-kiemelo-szoveg:#fff;--bm-hiba:#b42318;--bm-r:14px}'
            . '@media (prefers-color-scheme:dark){:root{--bm-bg:#11141a;--bm-lap:#1a1f29;--bm-szoveg:#eef1f6;--bm-halvany:#a9b2c3;--bm-vonal:#323a49;--bm-kiemelo:#6ea0ff;--bm-kiemelo-szoveg:#0b1020;--bm-hiba:#ff8a80}}'
            . '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bm-bg);color:var(--bm-szoveg);font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:16px}'
            . '.bm-lap{width:100%;max-width:520px;background:var(--bm-lap);border:1px solid var(--bm-vonal);border-radius:var(--bm-r);padding:28px}'
            . '.bm-jel{display:inline-block;font-weight:700;letter-spacing:.08em;font-size:.78rem;padding:.25rem .7rem;border-radius:999px;background:#f5b301;color:#1d1a10}'
            . 'h1{font-size:1.45rem;margin:.7rem 0 .4rem}p{margin:.5rem 0}.bm-halvany{color:var(--bm-halvany);font-size:.92rem}'
            . '.bm-gomb{display:inline-block;border:0;border-radius:999px;padding:.75rem 1.4rem;font:inherit;font-weight:600;background:var(--bm-kiemelo);color:var(--bm-kiemelo-szoveg);cursor:pointer;text-decoration:none}'
            . '.bm-gomb:focus-visible,a:focus-visible{outline:3px solid var(--bm-kiemelo);outline-offset:3px}'
            . '.bm-hiba{color:var(--bm-hiba);border:1px solid currentColor;border-radius:10px;padding:.6rem .9rem}'
            . '.bm-rejtett{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}'
            . 'ul{padding-left:1.2rem}'
            . '</style>' . $fej_extra . '</head><body><main class="bm-lap"><span class="bm-jel">BEMUTATÓ</span>'
            . '<h1>' . esc_html($cim) . '</h1>' . $tartalom . '</main></body></html>';
    }

    /* =================================================================
     * Hálózati kapu
     * ============================================================== */

    /**
     * Kifelé semmi nem megy (központ, telemetria, frissítés, IMEI, AI, Google, SMS-átjáró, LAN-címek), kivéve:
     * a saját webhely, a Turnstile ellenőrzése, és – csak beállított tesztkulccsal – a Számlázz.hu teszt-fiókja.
     *
     * @param false|array|WP_Error $elozo
     * @param array<string, mixed> $args
     * @return false|array|WP_Error
     */
    public static function http_kapu($elozo, $args, $url)
    {
        if ($elozo !== false || !is_string($url)) {
            return $elozo;
        }

        $host  = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $sajat = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

        if ($host === '' || $host === $sajat) {
            return $elozo;
        }

        if ($host === 'challenges.cloudflare.com') {
            return $elozo;
        }

        if ($host === 'szamlazz.hu' || str_ends_with($host, '.szamlazz.hu')) {
            return self::szamlazz((array) $args, $url);
        }

        return new WP_Error('bemutato_halozat', self::HALOZAT_UZENET);
    }

    /**
     * Számlázz.hu: tesztkulcs nélkül tiltva; tesztkulccsal csak az a kérés megy ki, amelyikben a tesztkulcs áll
     * (a műhely tesztüzeme a szamlazz.hu-t egyébként tiltaná – ezért itt, a szűrők előtt küldjük el).
     *
     * @param array<string, mixed> $args
     * @return array|WP_Error
     */
    private static function szamlazz(array $args, string $url)
    {
        $kulcs = self::titok('szamlazz_teszt_kulcs');
        $torzs = is_string($args['body'] ?? null) ? (string) $args['body'] : (string) wp_json_encode($args['body'] ?? '');

        if ($kulcs === '' || strpos($torzs, $kulcs) === false) {
            return new WP_Error('bemutato_szamla', self::SZAMLA_UZENET);
        }

        $kuldo = apply_filters('sdh_demo_szamlazz_kuldo', null);

        if (is_callable($kuldo)) {
            return $kuldo($url, $args);
        }

        try {
            $v = \WpOrg\Requests\Requests::request(
                $url,
                (array) ($args['headers'] ?? []),
                $torzs,
                \WpOrg\Requests\Requests::POST,
                ['timeout' => (float) ($args['timeout'] ?? 40), 'follow_redirects' => false, 'verify' => ABSPATH . WPINC . '/certificates/ca-bundle.crt']
            );
            $http = new WP_HTTP_Requests_Response($v);
            $ki   = $http->to_array();
            $ki['http_response'] = $http;

            return $ki;
        } catch (Throwable $e) {
            return new WP_Error('bemutato_szamla', 'A Számlázz.hu teszt-fiókja most nem érhető el.');
        }
    }

    /**
     * @param mixed $v
     * @return array<string, mixed>
     */
    public static function szamla_opcio($v): array
    {
        $v = is_array($v) ? $v : [];

        if (self::$szamla === null) {
            $kulcs        = self::titok('szamlazz_teszt_kulcs');
            self::$szamla = [
                'kulcs'        => $kulcs !== '' && class_exists('SDH_Muhely_Titok') ? (string) SDH_Muhely_Titok::titkosit($kulcs) : $kulcs,
                'elotag_fo'    => 'MINTA',
                'elotag_sdh'   => 'MINTA',
                'nyomt_elotag' => 'MINTA',
            ];
        }

        $v                 = array_merge($v, self::$szamla);
        $v['email_kuldes'] = false;

        return $v;
    }

    /**
     * @param mixed $v
     * @return array<string, mixed>
     */
    public static function ketlepcsos_ki($v): array
    {
        return array_merge(is_array($v) ? $v : [], ['szabaly' => 'ki']);
    }

    /* =================================================================
     * Ütemezés
     * ============================================================== */

    /** A következő 03:00 (Europe/Budapest), nyári / téli időszámítással helyesen. */
    public static function kovetkezo_hajnal(?int $most = null): int
    {
        $most = $most ?? time();
        $tz   = new DateTimeZone(self::IDOZONA);
        $itt  = (new DateTimeImmutable('@' . $most))->setTimezone($tz);
        $cel  = $itt->setTime(3, 0);

        if ($cel->getTimestamp() <= $most) {
            $cel = $itt->modify('+1 day')->setTime(3, 0);
        }

        return $cel->getTimestamp();
    }

    public static function cron_beallit(): void
    {
        if (!wp_next_scheduled(self::CRON_ORANKENT)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_ORANKENT);
        }

        if (!wp_next_scheduled(self::CRON_EJJEL)) {
            wp_schedule_single_event(self::kovetkezo_hajnal(), self::CRON_EJJEL);
        }
    }

    /** A következő visszaállítás ideje (a sáv visszaszámlálójához). */
    public static function kovetkezo_visszaallitas(): int
    {
        $t = wp_next_scheduled(self::CRON_EJJEL);

        return $t ? (int) $t : self::kovetkezo_hajnal();
    }

    /** Éjjel 03:00: visszaállítás a pillanatképből, utána a következő éjszaka ütemezése. */
    public static function ejjeli(): void
    {
        try {
            if (SDH_Demo_Pillanatkep::van()) {
                SDH_Demo_Pillanatkep::visszaallit();
            } else {
                SDH_Demo_Inditas::takarit(true);
            }
        } finally {
            wp_clear_scheduled_hook(self::CRON_EJJEL);
            wp_schedule_single_event(self::kovetkezo_hajnal(time() + 60), self::CRON_EJJEL);
        }
    }

    /** Óránként: lejárt látogatói fiókok és 24 óránál régebbi látogatói rekordok törlése, régi naplósorok. */
    public static function orankenti(): void
    {
        SDH_Demo_Inditas::takarit();
        SDH_Demo_Keret::takarit();
        SDH_Demo_Kimeno::sms_szinkron();
    }

    /* =================================================================
     * Aktiválás
     * ============================================================== */

    public static function aktivalas(): void
    {
        if (self::muhely_kesz()) {
            self::telepit();
        }
    }

    public static function deaktivalas(): void
    {
        wp_clear_scheduled_hook(self::CRON_EJJEL);
        wp_clear_scheduled_hook(self::CRON_ORANKENT);
    }
}
