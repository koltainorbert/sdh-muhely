<?php
/**
 * SDH Értékesítés – mag: beállítások, titkok, tokenek (termeknev, cégadat), IP-lenyomat, pénz, aktiválás, cron.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales
{
    public const OPT       = 'sdh_sales_beallitas';
    public const OPT_TITOK = 'sdh_sales_titkok';
    public const CRON      = 'sdh_sales_cron';
    public const JOG       = 'sdh_sales_admin';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public static function init(): void
    {
        add_filter('cron_schedules', [self::class, 'utemezes']);
        add_action('init', [self::class, 'cron_beallit']);
        add_action('init', [self::class, 'nyelv'], 1);

        // A {termeknev} és a többi token a bejegyzések / oldalak szövegében, címében, kivonatában, a dokumentumcímben
        // és a SEO-mezőkben is cserélődik (a blog és a jogi oldalak tokenekkel készülnek).
        foreach (['the_content', 'the_title', 'the_excerpt', 'get_the_excerpt', 'single_post_title', 'widget_text', 'wp_get_attachment_caption', 'the_content_feed', 'the_excerpt_rss', 'the_title_rss'] as $sz) {
            add_filter($sz, [self::class, 'szuro_t'], 99);
        }

        add_filter('document_title_parts', [self::class, 't_tomb'], 99);
        add_filter('rank_math/frontend/title', [self::class, 'szuro_t'], 99);
        add_filter('rank_math/frontend/description', [self::class, 'szuro_t'], 99);
        add_filter('rank_math/opengraph/facebook/og_title', [self::class, 'szuro_t'], 99);
        add_filter('rank_math/opengraph/facebook/og_description', [self::class, 'szuro_t'], 99);
    }

    /** Szűrő-alak: bármilyen bemenetre (a nem szöveget érintetlenül adja vissza). @param mixed $s @return mixed */
    public static function szuro_t($s)
    {
        return is_string($s) ? self::t($s) : $s;
    }

    public static function nyelv(): void
    {
        load_plugin_textdomain('sdh-sales', false, dirname(plugin_basename(SDH_SALES_FILE)) . '/languages');
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    /** @return array<string, mixed> */
    public static function alap(): array
    {
        $a = self::adat('beallitas-alap.json');

        return is_array($a) ? $a : [];
    }

    /** @return array<string, mixed> a teljes (lapos, pontozott kulcsú) beállítás */
    public static function mind(): array
    {
        if (self::$cache === null) {
            $m = get_option(self::OPT, []);
            self::$cache = (is_array($m) ? $m : []) + self::alap();
        }

        return self::$cache;
    }

    /**
     * Egy beállítás. Pontozott kulcs („ceg.nev”); ha a kulcs csoport („fizetes.barion”), a csoport tömbként jön.
     *
     * @param mixed $alap
     * @return mixed
     */
    public static function b(string $kulcs, $alap = null)
    {
        $m = self::mind();

        if (array_key_exists($kulcs, $m)) {
            return $m[$kulcs];
        }

        $elo = $kulcs . '.';
        $cs  = [];

        foreach ($m as $k => $v) {
            if (strncmp((string) $k, $elo, strlen($elo)) === 0) {
                $cs[substr((string) $k, strlen($elo))] = $v;
            }
        }

        return $cs !== [] ? $cs : $alap;
    }

    /**
     * Beállítások mentése (csak a megadott kulcsok). Naplózza a változást (régi → új), titkot nem.
     *
     * @param array<string, mixed> $uj
     */
    public static function b_ment(array $uj, string $ok = 'beallitas'): void
    {
        $regi = get_option(self::OPT, []);
        $regi = is_array($regi) ? $regi : [];
        $m    = $regi;
        $el   = [];
        $ut   = [];

        foreach ($uj as $k => $v) {
            $k = (string) $k;

            if (preg_match('/^[a-z0-9_.]{1,80}$/', $k) !== 1) {
                continue;
            }

            $elotte = $regi[$k] ?? (self::alap()[$k] ?? null);

            if ($elotte === $v) {
                continue;
            }

            $m[$k]  = $v;
            $el[$k] = $elotte;
            $ut[$k] = $v;
        }

        if ($ut === []) {
            return;
        }

        update_option(self::OPT, $m, true);
        self::$cache = null;
        do_action('sdh_sales_beallitas_mentve', array_keys($ut));

        if (class_exists('SDH_Sales_Naplo')) {
            SDH_Sales_Naplo::ir($ok, 'Beállítás módosítva: ' . implode(', ', array_keys($ut)), 'info', 'beallitas', 0, $el, $ut);
        }
    }

    /** A teljes beállítás visszaírása (visszaállításhoz / importhoz). @param array<string, mixed> $m */
    public static function b_felulir(array $m): void
    {
        update_option(self::OPT, $m, true);
        self::$cache = null;
    }

    public static function urit_cache(): void
    {
        self::$cache = null;
    }

    /* =================================================================
     * Titkok (sodium secretbox, kulcs a wp-config sóiból)
     * ============================================================== */

    private static function titok_kulcs(): string
    {
        $alap = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '') . 'sdh-sales';

        return sodium_crypto_generichash($alap, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function titkos_ment(string $nev, string $ertek): void
    {
        $t = get_option(self::OPT_TITOK, []);
        $t = is_array($t) ? $t : [];

        if ($ertek === '') {
            unset($t[$nev]);
        } else {
            $n       = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $t[$nev] = 'ss1:' . base64_encode($n . sodium_crypto_secretbox($ertek, $n, self::titok_kulcs()));
        }

        update_option(self::OPT_TITOK, $t, false);

        if (class_exists('SDH_Sales_Naplo')) {
            SDH_Sales_Naplo::ir('beallitas', 'Titok ' . ($ertek === '' ? 'törölve' : 'beállítva') . ': ' . $nev, 'info');
        }
    }

    public static function titkos(string $nev): string
    {
        $t = get_option(self::OPT_TITOK, []);
        $v = is_array($t) ? (string) ($t[$nev] ?? '') : '';

        if (strncmp($v, 'ss1:', 4) !== 0) {
            return '';
        }

        $b = base64_decode(substr($v, 4), true);

        if ($b === false || strlen($b) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $ki = sodium_crypto_secretbox_open(substr($b, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($b, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::titok_kulcs());

        return $ki === false ? '' : $ki;
    }

    public static function van_titok(string $nev): bool
    {
        return self::titkos($nev) !== '';
    }

    /* =================================================================
     * Tokenek: {termeknev}, {ceg}, …
     * ============================================================== */

    public static function termeknev(): string
    {
        $n = trim((string) self::b('termeknev', ''));

        return $n !== '' ? $n : 'Munkora';
    }

    /** @return array<string, string> */
    public static function tokenek(): array
    {
        $t = [
            '{termeknev}'       => self::termeknev(),
            '{termeknev_rovid}' => (string) (self::b('termeknev_rovid', '') ?: self::termeknev()),
            '{szlogen}'         => (string) self::b('szlogen', ''),
            '{ceg}'             => (string) self::b('ceg.nev', ''),
            '{ceg_email}'       => (string) self::b('ceg.email', ''),
            '{ceg_telefon}'     => (string) self::b('ceg.telefon', ''),
            '{ceg_cim}'         => (string) self::b('ceg.cim', ''),
            '{ceg_adoszam}'     => (string) self::b('ceg.adoszam', ''),
            '{ceg_cegjegyzek}'  => (string) self::b('ceg.cegjegyzek', ''),
            '{ceg_kepviselo}'   => (string) self::b('ceg.kepviselo', ''),
            '{webhely}'         => home_url('/'),
            '{demo_url}'        => (string) self::b('demo.url', ''),
            '{afa}'             => (string) self::afa(),
            '{ev}'              => wp_date('Y'),
        ];

        return (array) apply_filters('sdh_sales_tokenek', $t);
    }

    /** Tokenek cseréje egy szövegben (a látogatói szövegekben mindig ezen át). */
    public static function t(string $s, array $extra = []): string
    {
        if (strpos($s, '{') === false) {
            return $s;
        }

        return strtr($s, $extra + self::tokenek());
    }

    /** Tokenek cseréje egy tömb minden szövegében (rekurzívan). @param mixed $a @return mixed */
    public static function t_tomb($a)
    {
        if (is_string($a)) {
            return self::t($a);
        }

        if (is_array($a)) {
            foreach ($a as $k => $v) {
                $a[$k] = self::t_tomb($v);
            }
        }

        return $a;
    }

    /* =================================================================
     * Pénz, áfa
     * ============================================================== */

    public static function afa(): int
    {
        return max(0, min(100, (int) self::b('afa', 27)));
    }

    public static function brutto(int $netto): int
    {
        return (int) round($netto * (100 + self::afa()) / 100);
    }

    public static function ft(int $osszeg): string
    {
        return number_format($osszeg, 0, ',', "\u{00A0}") . "\u{00A0}Ft";
    }

    /* =================================================================
     * IP, lenyomat
     * ============================================================== */

    public static function ip(): string
    {
        $ip = '';

        // Cloudflare mögött a valódi cím ebben áll (csak ha a kérés tényleg a Cloudflare-től jön, azt a webszerver dönti el).
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && (bool) self::b('cloudflare', true)) {
            $ip = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = (string) $_SERVER['REMOTE_ADDR'];
        }

        $ip = (string) apply_filters('sdh_sales_ip', $ip);

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /** Állandó sózott lenyomat (naplókhoz). */
    public static function ip_hash(?string $ip = null): string
    {
        return substr(hash_hmac('sha256', $ip ?? self::ip(), wp_salt('auth') . 'sdh-ip'), 0, 32);
    }

    /** Naponta forgó lenyomat (napi korlátokhoz – másnap nem köthető össze). */
    public static function ip_hash_napi(?string $ip = null): string
    {
        return substr(hash_hmac('sha256', ($ip ?? self::ip()) . '|' . gmdate('Y-m-d'), wp_salt('nonce') . 'sdh-ip-nap'), 0, 32);
    }

    /* =================================================================
     * Adatfájlok
     * ============================================================== */

    /** A `data/` alatti JSON. @return mixed */
    public static function adat(string $fajl)
    {
        $f = SDH_SALES_DIR . 'data/' . ltrim($fajl, '/');

        if (!is_file($f) || strpos(realpath($f) ?: '', realpath(SDH_SALES_DIR . 'data') ?: '-') !== 0) {
            return null;
        }

        return json_decode((string) file_get_contents($f), true);
    }

    /* =================================================================
     * Jog
     * ============================================================== */

    public static function admin(): bool
    {
        return current_user_can('manage_options') || current_user_can(self::JOG);
    }

    /* =================================================================
     * Aktiválás, cron
     * ============================================================== */

    public static function aktivalas(): void
    {
        SDH_Sales_Schema::sema(true);

        $r = get_role('administrator');

        if ($r !== null) {
            $r->add_cap(self::JOG);
        }

        if (get_role('sdh_vevo') === null) {
            add_role('sdh_vevo', 'Vevő', ['read' => true]);
        }

        do_action('sdh_sales_aktivalas');

        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 60, 'sdh_sales_5perc', self::CRON);
        }

        flush_rewrite_rules();
    }

    public static function kikapcsolas(): void
    {
        wp_clear_scheduled_hook(self::CRON);
        flush_rewrite_rules();
    }

    /** @param array<string, array{interval: int, display: string}> $s */
    public static function utemezes(array $s): array
    {
        $s['sdh_sales_5perc'] = ['interval' => 300, 'display' => 'SDH Értékesítés – 5 perc'];

        return $s;
    }

    public static function cron_beallit(): void
    {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 60, 'sdh_sales_5perc', self::CRON);
        }
    }

    /** Egyedi, olvasható azonosító (pl. R-2026-000123) – a sorszámot a hívó adja. */
    public static function azon(string $elotag, int $szam): string
    {
        return $elotag . '-' . wp_date('Y') . '-' . str_pad((string) $szam, 6, '0', STR_PAD_LEFT);
    }

    /** Biztonságos véletlen token (URL-be). */
    public static function token(int $bajt = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bajt)), '+/', '-_'), '=');
    }
}
