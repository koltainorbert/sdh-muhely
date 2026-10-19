<?php
/**
 * SDH Értékesítés – kereső: statikus JSON-index a böngészős keresőnek, szerveroldali tartalék (`GET /kereso?q=`),
 * és a WordPress-keresés kiegészítése a tartalom-tár elemeivel.
 *
 * Index (ARCHITEKTURA.md 7.): `uploads/sdh-sales/kereso-index.json` (+ `.json.gz` előtömörítve a gzip_static-nak),
 *   `{"v": <hash>, "ido": <unix>, "elemek": [{t, u, k, s, w, n}, …]}`
 *   t = cím, u = URL, k = funkcio|modul|iparag|gyik|tudas|blog|oldal, s = kivonat (≤ 160 karakter),
 *   w = kulcsszavak (szóközzel), n = ékezet nélküli kisbetűs „cím + kulcsszavak” (a böngészős egyezéshez).
 * API:
 *   SDH_Sales_Kereso::index_url()        → az index URL-je `?v=<hash>` paraméterrel (ha nincs index, előbb felépíti)
 *   SDH_Sales_Kereso::epit()             → felépíti és kiírja; visszaadja a statisztikát {db, meret, meret_gz, hash, ms}
 *   SDH_Sales_Kereso::elemek()           → az index elemei (építés nélkül, a tartalomból)
 *   SDH_Sales_Kereso::keres($q, $max)    → rangsorolt találatok (elírástűrő: trigram + Levenshtein a címen és kulcsszavakon)
 *   SDH_Sales_Kereso::hajt($s)           → ékezet nélküli kisbetűs alak (a JS-oldali normalizálás párja)
 *   SDH_Sales_Kereso::allapot()          → a tartalom ujjlenyomata (változásfigyeléshez, a llms.txt is ezt használja)
 * Újraépítés: `sdh_sales_tartalom_betoltve` (azonnal), `save_post` / törlés / beállítás-mentés / `sdh_sales_tartalom_mentve`
 * (késleltetve, 30 mp, egy futás), és a 5 perces cronban ujjlenyomat-összevetés (a `SDH_Sales_Tartalom::ment()` jelenleg
 * nem küld hookot – így a szerkesztés legkésőbb 5 percen belül látszik).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Kereso
{
    public const OPT       = 'sdh_sales_kereso';
    public const ESEMENY   = 'sdh_sales_kereso_epit';
    public const FAJL      = 'kereso-index.json';
    public const KIVONAT   = 160;

    /** @var array<int, array<string, mixed>>|null az aktuális kérésben betöltött index (a REST-hez) */
    private static ?array $betoltott = null;

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/kereso', 'GET', [self::class, 'rest'], 'nyilvanos', ['kereso', 60, 60]);

        add_action('sdh_sales_tartalom_betoltve', [self::class, 'epit_most'], 20);
        add_action('sdh_sales_tartalom_mentve', [self::class, 'jelol']);
        add_action('save_post', [self::class, 'post_mentve'], 20, 2);
        add_action('deleted_post', [self::class, 'jelol']);
        add_action('trashed_post', [self::class, 'jelol']);
        add_action('update_option_' . SDH_Sales::OPT, [self::class, 'jelol']);
        add_action(self::ESEMENY, [self::class, 'epit_most']);
        add_action(SDH_Sales::CRON, [self::class, 'ellenoriz']);

        add_action('pre_get_posts', [self::class, 'wp_kereses_lekerdezes']);
        add_filter('posts_search', [self::class, 'wp_kereses_where'], 20, 2);
        add_filter('posts_search_orderby', [self::class, 'wp_kereses_sorrend'], 20, 2);
    }

    /* =================================================================
     * Normalizálás
     * ============================================================== */

    /** Ékezet nélküli, kisbetűs, egyszerű szóközös alak (a magyar ő/ű is). */
    public static function hajt(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u', 'ä' => 'a', 'ô' => 'o', 'õ' => 'o', 'û' => 'u']);
        $s = remove_accents($s);
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim($s);
    }

    /** @return array<int, string> egyedi szavak (≥ 2 karakter) */
    public static function szavak(string $hajtott): array
    {
        return array_values(array_unique(array_filter(explode(' ', $hajtott), static fn ($w) => strlen($w) >= 2)));
    }

    /* =================================================================
     * Index-elemek
     * ============================================================== */

    /**
     * @param array<int, string>|string $w
     * @return array{t: string, u: string, k: string, s: string, w: string, n: string}
     */
    private static function tetel(string $t, string $u, string $k, string $s, $w): array
    {
        $t  = SDH_Sales_Seo::rovidit(SDH_Sales_Seo::sima($t), 120);
        $s  = SDH_Sales_Seo::rovidit(SDH_Sales_Seo::sima($s), self::KIVONAT);
        $w  = is_array($w) ? $w : SDH_Sales_Seo::lista($w);
        $w  = array_values(array_unique(array_filter(array_map(static fn ($x) => SDH_Sales_Seo::sima(SDH_Sales::t((string) $x)), $w), 'strlen')));
        $ws = SDH_Sales_Seo::rovidit(implode(' ', $w), 200);

        return ['t' => $t, 'u' => $u, 'k' => $k, 's' => $s, 'w' => $ws, 'n' => self::hajt($t . ' ' . $ws)];
    }

    /** @param array<string, mixed> $a @return array<int, string> */
    private static function kulcsszavak(array $a): array
    {
        $w = [];

        foreach (['kulcsszavak', 'kulcsszo', 'cimkek', 'szinonimak', 'csoport', 'kategoria'] as $m) {
            $w = array_merge($w, SDH_Sales_Seo::lista($a[$m] ?? []));
        }

        return $w;
    }

    /** @return array<int, array{t: string, u: string, k: string, s: string, w: string, n: string}> */
    public static function elemek(): array
    {
        SDH_Sales_Seo::urit();
        SDH_Sales_Gyik::urit();

        $ki  = [];
        $url = [];

        $tesz = static function (array $e) use (&$ki, &$url): void {
            if ($e['t'] === '' || $e['u'] === '') {
                return;
            }

            $kulcs = $e['k'] . '|' . $e['u'];

            if (isset($url[$kulcs])) {
                return;
            }

            $url[$kulcs] = true;
            $ki[]        = $e;
        };

        // Funkciók, iparágak, modulok (a tartalom-tárból, a landingek URL-jével).
        foreach (['funkcio', 'iparag', 'modul'] as $tipus) {
            foreach (SDH_Sales_Seo::elemek($tipus) as $k => $e) {
                $a = (array) $e['adat'];
                $w = self::kulcsszavak($a);

                if ($tipus === 'modul') {
                    $w[] = (string) $k;
                }

                $tesz(self::tetel(SDH_Sales_Seo::nev($e), SDH_Sales_Seo::url($tipus, (string) $k, $a), $tipus, SDH_Sales_Seo::leiras($e), $w));
            }
        }

        // Csomagok → „oldal” (az árak oldalra mutat).
        foreach (SDH_Sales_Seo::csomagok() as $c) {
            $tesz(self::tetel(sprintf(/* translators: %s: csomag neve */ __('%s csomag', 'sdh-sales'), $c['nev']), (string) $c['url'], 'oldal', (string) $c['leiras'], [__('ár', 'sdh-sales'), __('csomag', 'sdh-sales'), __('előfizetés', 'sdh-sales')]));
        }

        // GYIK.
        foreach (SDH_Sales_Gyik::lista() as $g) {
            $tesz(self::tetel((string) $g['kerdes'], (string) $g['url'], 'gyik', (string) $g['valasz_szoveg'], array_merge([(string) $g['kategoria']], self::kulcsszavak((array) (SDH_Sales_Seo::elem('gyik', (string) $g['kulcs'])['adat'] ?? [])))));
        }

        // Oktatási leckék → „tudas”.
        foreach (SDH_Sales_Seo::elemek('lecke') as $k => $e) {
            $a = (array) $e['adat'];
            $tesz(self::tetel(SDH_Sales_Seo::nev($e), SDH_Sales_Seo::url('lecke', (string) $k, $a), 'tudas', SDH_Sales_Seo::leiras($e), array_merge(self::kulcsszavak($a), SDH_Sales_Seo::lista($a['modul'] ?? []))));
        }

        // Oldalak (a segédoldalak nélkül) és blogbejegyzések.
        $tudas = SDH_Sales_Seo::utvonalak()['tudas'];
        $kiz   = SDH_Sales_Seo::segedoldal_idk();
        $posts = get_posts([
            'post_type'        => ['page', 'post'],
            'post_status'      => 'publish',
            'numberposts'      => 2000,
            'orderby'          => 'menu_order date',
            'order'            => 'ASC',
            'exclude'          => $kiz,
            'suppress_filters' => true,
            'has_password'     => false,
        ]);

        $tipus_url = [];

        foreach ($ki as $e) {
            $tipus_url[untrailingslashit($e['u'])] = true;
        }

        foreach ($posts as $p) {
            if (!$p instanceof WP_Post || SDH_Sales_Seo::segedoldal((int) $p->ID) || (string) get_post_meta($p->ID, '_sdh_noindex', true) === '1') {
                continue;
            }

            $u = (string) get_permalink($p);

            // A funkció / iparág landing már bent van a tartalom-tárból (szebb kivonattal).
            if (isset($tipus_url[untrailingslashit($u)])) {
                continue;
            }

            $leir = (string) get_post_meta($p->ID, '_sdh_seo_description', true);

            if ($leir === '') {
                $leir = $p->post_excerpt !== '' ? $p->post_excerpt : ($p->post_content !== '' ? $p->post_content : self::szekcio_szoveg((int) $p->ID));
            }

            $w = array_merge(SDH_Sales_Seo::lista((string) get_post_meta($p->ID, 'rank_math_focus_keyword', true)));

            if ($p->post_type === 'post') {
                $c = wp_get_post_tags($p->ID, ['fields' => 'names']);
                $w = array_merge($w, is_array($c) ? $c : [], wp_list_pluck(get_the_category($p->ID), 'name'));
                $k = 'blog';
            } else {
                $k = strpos(get_page_uri($p), $tudas . '/') === 0 || get_page_uri($p) === $tudas ? 'tudas' : 'oldal';
            }

            $tesz(self::tetel(SDH_Sales::t($p->post_title), $u, $k, SDH_Sales::t($leir), $w));
        }

        return (array) apply_filters('sdh_sales_kereso_elemek', $ki);
    }

    /** Egy oldal szekcióinak első érdemi szövege (kivonatnak, ha nincs leírás). */
    private static function szekcio_szoveg(int $post_id): string
    {
        foreach (SDH_Sales_Tartalom::szekciok($post_id) as $sz) {
            foreach (['alcim', 'leiras', 'szoveg', 'html', 'bevezeto'] as $m) {
                if (!empty($sz[$m]) && is_string($sz[$m])) {
                    return SDH_Sales::t($sz[$m]);
                }
            }
        }

        return '';
    }

    /* =================================================================
     * Építés, fájl, verzió
     * ============================================================== */

    /** @return array{dir: string, url: string} */
    public static function hely(): array
    {
        $u = wp_upload_dir(null, false);

        return ['dir' => trailingslashit((string) $u['basedir']) . 'sdh-sales/', 'url' => trailingslashit((string) $u['baseurl']) . 'sdh-sales/'];
    }

    /** A tartalom ujjlenyomata: tartalom-tár + közzétett bejegyzések/oldalak + beállítások. */
    public static function allapot(): string
    {
        global $wpdb;

        $t = (array) $wpdb->get_row('SELECT MAX(modositva) AS m, COUNT(*) AS db, SUM(aktiv) AS a FROM ' . SDH_Sales_Schema::t('tartalom'), ARRAY_A);
        $p = (array) $wpdb->get_row("SELECT MAX(post_modified_gmt) AS m, COUNT(*) AS db, SUM(ID) AS s FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page')", ARRAY_A);

        return md5(implode('|', [$t['m'] ?? '', $t['db'] ?? 0, $t['a'] ?? 0, $p['m'] ?? '', $p['db'] ?? 0, $p['s'] ?? 0, md5((string) wp_json_encode(SDH_Sales::mind())), home_url('/'), SDH_SALES_VERSION]));
    }

    /** @return array<string, mixed> */
    public static function info(): array
    {
        $o = get_option(self::OPT, []);

        return is_array($o) ? $o : [];
    }

    /** @return array{db: int, meret: int, meret_gz: int, hash: string, ms: float, ok: bool} */
    public static function epit(): array
    {
        $kezd   = microtime(true);
        $allap  = self::allapot();
        $elemek = self::elemek();
        $hash   = substr(md5((string) wp_json_encode($elemek, JSON_UNESCAPED_UNICODE)), 0, 12);
        $json   = (string) wp_json_encode(['v' => $hash, 'ido' => time(), 'elemek' => $elemek], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $h      = self::hely();
        $ok     = wp_mkdir_p($h['dir']);
        $gz     = function_exists('gzencode') ? (string) gzencode($json, 9) : '';

        if ($ok) {
            $ok = self::ir($h['dir'] . self::FAJL, $json);

            if ($gz !== '') {
                self::ir($h['dir'] . self::FAJL . '.gz', $gz);
            }
        }

        $stat = [
            'db'       => count($elemek),
            'meret'    => strlen($json),
            'meret_gz' => strlen($gz),
            'hash'     => $hash,
            'ms'       => round((microtime(true) - $kezd) * 1000, 1),
            'ok'       => $ok,
        ];

        update_option(self::OPT, $stat + ['allapot' => $allap, 'ido' => time(), 'piszkos' => false], false);
        self::$betoltott = $elemek;

        do_action('sdh_sales_kereso_epult', $stat);

        return $stat;
    }

    /** Atomikus írás (ideiglenes fájl + átnevezés), hogy a böngésző soha ne kapjon félkész JSON-t. */
    private static function ir(string $fajl, string $tartalom): bool
    {
        $tmp = $fajl . '.' . wp_generate_password(6, false) . '.tmp';

        if (file_put_contents($tmp, $tartalom, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tmp, $fajl)) {
            @unlink($tmp);

            return false;
        }

        @chmod($fajl, 0644);

        return true;
    }

    public static function epit_most(): void
    {
        self::epit();
    }

    /** Változás jelzése: egyetlen késleltetett újraépítés (30 mp), sok egymás utáni mentésnél is csak egy. */
    public static function jelol(): void
    {
        $o            = self::info();
        $o['piszkos'] = true;
        update_option(self::OPT, $o, false);

        if (!wp_next_scheduled(self::ESEMENY)) {
            wp_schedule_single_event(time() + 30, self::ESEMENY);
        }
    }

    /** @param int $id @param WP_Post $p */
    public static function post_mentve($id, $p): void
    {
        if (!$p instanceof WP_Post || wp_is_post_revision($p) || wp_is_post_autosave($p) || !in_array($p->post_type, ['post', 'page'], true)) {
            return;
        }

        self::jelol();
    }

    /** Cron: ha a tartalom ujjlenyomata változott (pl. a tartalom-tár szerkesztése), újraépít. */
    public static function ellenoriz(): bool
    {
        $o = self::info();
        $h = self::hely();

        if (!empty($o['piszkos']) || ($o['allapot'] ?? '') !== self::allapot() || !is_file($h['dir'] . self::FAJL)) {
            self::epit();

            return true;
        }

        return false;
    }

    public static function index_url(): string
    {
        $o = self::info();
        $h = self::hely();

        if (empty($o['hash']) || !is_file($h['dir'] . self::FAJL)) {
            $o = self::epit();
        }

        return add_query_arg('v', rawurlencode((string) $o['hash']), $h['url'] . self::FAJL);
    }

    /** @return array<int, array<string, mixed>> */
    private static function betolt(): array
    {
        if (self::$betoltott !== null) {
            return self::$betoltott;
        }

        $f = self::hely()['dir'] . self::FAJL;
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;

        if (!is_array($j) || !is_array($j['elemek'] ?? null)) {
            self::epit();

            return (array) self::$betoltott;
        }

        return self::$betoltott = $j['elemek'];
    }

    /* =================================================================
     * Keresés (szerveroldali tartalék)
     * ============================================================== */

    /** @return array<string, int> trigramok (szóhatár-jelölővel) */
    private static function trigram(string $w): array
    {
        $w = ' ' . $w . ' ';
        $t = [];

        for ($i = 0, $n = strlen($w) - 2; $i < $n; $i++) {
            $t[substr($w, $i, 3)] = 1;
        }

        return $t;
    }

    /** Egy keresőszó és egy szó egyezésének pontja (0 = nincs). */
    private static function szo_pont(string $q, string $w): float
    {
        if ($q === $w) {
            return 10.0;
        }

        $lq = strlen($q);
        $lw = strlen($w);

        if ($lq >= 2 && strncmp($w, $q, $lq) === 0) {
            return 8.0 - min(2.0, ($lw - $lq) / 4);
        }

        if ($lq >= 3 && strpos($w, $q) !== false) {
            return 5.5;
        }

        if ($lq < 4) {
            return 0.0;
        }

        $tures = $lq >= 8 ? 2 : 1;

        // Elírás a szó elején (a keresett szó hosszával egyező / ±1 előtagon) – pl. „munklap” → „munkalapok”.
        $legjobb = PHP_INT_MAX;

        foreach ([$lq - 1, $lq, $lq + 1] as $hossz) {
            if ($hossz >= 3 && $hossz <= $lw) {
                $legjobb = min($legjobb, levenshtein($q, substr($w, 0, $hossz)));
            }
        }

        if ($legjobb <= $tures) {
            return ($lw <= $lq + 1 ? 6.5 : 6.0) - 1.5 * $legjobb;
        }

        // Trigram-hasonlóság (a keresett szó trigramjainak hány %-a van meg a szóban).
        $tq  = self::trigram($q);
        $tw  = self::trigram($w);
        $koz = count(array_intersect_key($tq, $tw));
        $sim = $koz / max(1, count($tq));

        return $sim >= 0.5 ? 4.0 * $sim : 0.0;
    }

    /**
     * Rangsorolt keresés az indexben. Minden keresőszóra a legjobb egyező szó pontja számít (cím ×2, kulcsszó ×1.5,
     * kivonat ×0.6); a nem egyező szó büntet (részleges találat hátrébb kerül).
     *
     * @param array<int, array<string, mixed>>|null $elemek
     * @return array<int, array{t: string, u: string, k: string, s: string, pont: float}>
     */
    public static function keres(string $q, int $max = 10, ?array $elemek = null): array
    {
        $qh = self::hajt($q);
        $qs = self::szavak($qh);

        if ($qs === []) {
            return [];
        }

        $qs     = array_slice($qs, 0, 8);
        $elemek ??= self::betolt();
        $ki     = [];
        $suly   = ['funkcio' => 1.15, 'modul' => 1.1, 'iparag' => 1.05, 'oldal' => 1.0, 'gyik' => 0.95, 'tudas' => 0.9, 'blog' => 0.85];

        foreach ($elemek as $e) {
            $th = self::hajt((string) $e['t']);
            $tw = self::szavak($th);
            $ww = self::szavak(self::hajt((string) ($e['w'] ?? '')));
            $sw = null;
            $p  = 0.0;
            $hi = 0;

            foreach ($qs as $q) {
                $b = 0.0;

                foreach ($tw as $w) {
                    $b = max($b, 2.0 * self::szo_pont($q, $w));
                }

                foreach ($ww as $w) {
                    $b = max($b, 1.5 * self::szo_pont($q, $w));
                }

                if ($b < 8.0) {
                    $sw ??= self::szavak(self::hajt((string) ($e['s'] ?? '')));

                    foreach ($sw as $w) {
                        $b = max($b, 0.6 * self::szo_pont($q, $w));
                    }
                }

                if ($b <= 0.0) {
                    $hi++;
                }

                $p += $b;
            }

            if ($p <= 0.0 || $hi >= max(1, count($qs))) {
                continue;
            }

            // Teljes kifejezés a címben: plusz pont; hiányzó szavak: büntetés.
            if (count($qs) > 1 && strpos($th, $qh) !== false) {
                $p += 8.0;
            }

            $p = ($p / count($qs)) * ($hi > 0 ? 0.45 : 1.0) * ($suly[$e['k']] ?? 1.0);

            $ki[] = ['t' => (string) $e['t'], 'u' => (string) $e['u'], 'k' => (string) $e['k'], 's' => (string) $e['s'], 'pont' => round($p, 2)];
        }

        usort($ki, static fn ($a, $b) => $b['pont'] <=> $a['pont'] ?: strlen($a['t']) <=> strlen($b['t']));

        return array_slice($ki, 0, max(1, min(50, $max)));
    }

    public static function rest(WP_REST_Request $r)
    {
        $q = trim(sanitize_text_field((string) $r->get_param('q')));

        if (mb_strlen($q) < 2) {
            return SDH_Sales_Rest::hiba(__('Írj be legalább 2 karaktert.', 'sdh-sales'), 400, 'sdh_kereso_rovid');
        }

        $q     = mb_substr($q, 0, 100);
        $max   = max(1, min(30, (int) ($r->get_param('max') ?: 10)));
        $kezd  = microtime(true);
        $talal = self::keres($q, $max);

        return new WP_REST_Response([
            'q'          => $q,
            'talalatok'  => $talal,
            'db'         => count($talal),
            'ms'         => round((microtime(true) - $kezd) * 1000, 2),
            'index'      => self::index_url(),
        ], 200);
    }

    /* =================================================================
     * WordPress-keresés: a tartalom-tár találatai is
     * ============================================================== */

    public static function wp_kereses_lekerdezes(WP_Query $q): void
    {
        if (is_admin() || !$q->is_main_query() || !$q->is_search() || !(bool) SDH_Sales::b('seo.wp_kereses', true)) {
            return;
        }

        $q->set('post_type', ['post', 'page']);
        $q->set('post__not_in', array_merge((array) $q->get('post__not_in'), SDH_Sales_Seo::segedoldal_idk()));
    }

    /** @var array<int, int> a tartalom-tár találataihoz tartozó bejegyzés-/oldal-ID-k (rangsorban) */
    private static array $wp_idk = [];

    /**
     * A tartalom-tár (és a szekciókba írt szövegek) találatai: a hozzájuk tartozó oldalak is bekerülnek a WP-keresésbe.
     *
     * @return array<int, int>
     */
    public static function wp_idk(string $q): array
    {
        $ids = [];

        foreach (self::keres($q, 30) as $t) {
            $u  = (string) strtok($t['u'], '#');
            $id = url_to_postid($u);

            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @param string $where @param WP_Query $q */
    public static function wp_kereses_where($where, $q)
    {
        if (!$q instanceof WP_Query || !$q->is_search() || $where === '' || !(bool) SDH_Sales::b('seo.wp_kereses', true) || (is_admin() && !wp_doing_ajax())) {
            return $where;
        }

        global $wpdb;

        self::$wp_idk = self::wp_idk((string) $q->get('s'));

        if (self::$wp_idk === []) {
            return $where;
        }

        $in = implode(',', array_map('intval', self::$wp_idk));

        // A core feltétele: " AND ((…)) " → " AND (((…)) OR ID IN (…)) "
        $belso = (string) preg_replace('/^\s*AND\s*/i', '', $where);

        return ' AND ((' . $belso . ") OR {$wpdb->posts}.ID IN ({$in})) ";
    }

    /** @param string $orderby @param WP_Query $q */
    public static function wp_kereses_sorrend($orderby, $q)
    {
        if (!$q instanceof WP_Query || !$q->is_search() || self::$wp_idk === []) {
            return $orderby;
        }

        global $wpdb;

        $eset = 'CASE';

        foreach (array_slice(self::$wp_idk, 0, 30) as $i => $id) {
            $eset .= ' WHEN ' . $wpdb->posts . '.ID = ' . (int) $id . ' THEN ' . (int) $i;
        }

        $eset .= ' ELSE 1000 END ASC';

        return $eset . ($orderby !== '' ? ', ' . $orderby : '');
    }
}
