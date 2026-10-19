<?php
/**
 * SDH Értékesítés – SEO: strukturált adat (JSON-LD), meta-tartalék Rank Math nélkül, robots (meta + robots.txt, AI-botok),
 * oldaltérkép-szűrés, hreflang, belső linkháló („Kapcsolódó”) és morzsa a témának.
 *
 * Rank Math (class RankMath / RANK_MATH_VERSION) aktív → csak SoftwareApplication, Product+Offer, FAQPage (a Gyik-ből),
 * Event, HowTo; BreadcrumbList csak ha a Rank Math morzsája ki van kapcsolva. Meta (title, description, canonical, OG,
 * Twitter) is a Rank Mathé. Rank Math nélkül: mindez + Organization, WebSite+SearchAction, LocalBusiness (ha kérve),
 * Article (bejegyzés), BreadcrumbList, és a meta-tartalék. Az entitások @id-je egyezik a Rank Math-éval (`/#organization`,
 * `/#website`), így Rank Math mellett hivatkozunk rájuk, nem írjuk le kétszer.
 *
 * A téma API-ja:
 *   SDH_Sales_Seo::morzsa()                         → [ {cim, url} ] (főoldaltól az aktuálisig; főoldalon üres)
 *   SDH_Sales_Seo::kapcsolodo($tipus, $kulcs, $max) → [ {cim, url, tipus, leiras} ] („Kapcsolódó” blokk)
 *     $tipus: funkcio | iparag | modul | blog (kulcs = slug vagy ID) | gyik | csomag
 *   SDH_Sales_Seo::url($tipus, $kulcs)              → egy tartalom-elem nyilvános URL-je
 *
 * Beállítások (`seo.*`, mind elhagyható): ai_botok (true), ai_botok_bing (false: a Bingbot tiltása a Bing-keresőből is
 * kivenné az oldalt, ezért külön kapcsoló), sameas (lista vagy soronként egy URL), logo, og_kep, twitter (@nev),
 * localbusiness (false), localbusiness_tipus ("LocalBusiness"), nyitvatartas ("Mo-Fr 09:00-17:00"), geo ("47.09,17.91"),
 * szerzo (név; üres → a cég a szerző), szerzo_url, ar_brutto (false: nettó ár, valueAddedTaxIncluded=false),
 * noindex (slugok: ["kosar","penztar","fiok","kereses"]), wp_kereses (true), faq_schema (true).
 * A nyelvek a `nyelvek` kulcsból: hreflang csak 1-nél több nyelvnél (hu = gyökér, a többi /<nyelv>/ előtaggal).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Seo
{
    /** Az AI-botok robots.txt tokenjei (2026-10, a gyártók dokumentációja alapján). */
    public const AI_BOTOK = [
        'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
        'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai',
        'PerplexityBot', 'Perplexity-User',
        'Google-Extended', 'Applebot-Extended', 'CCBot',
    ];

    /** A Copilot a Bing indexéből dolgozik: ezek csak külön kapcsolóval tilthatók. */
    public const BING_BOTOK = ['Bingbot', 'msnbot'];

    /** @var array<string, WP_Post|null> */
    private static array $oldal_cache = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $elem_cache = [];

    public static function init(): void
    {
        add_action('wp_head', [self::class, 'meta_kiir'], 1);
        add_action('wp_head', [self::class, 'hreflang_kiir'], 2);
        add_action('wp_head', [self::class, 'graf_kiir'], 30);
        add_action('template_redirect', [self::class, 'fej_beallit'], 1);
        add_filter('pre_get_document_title', [self::class, 'dokumentum_cim'], 20);
        add_filter('wp_robots', [self::class, 'robots'], 20);
        add_filter('rank_math/frontend/robots', [self::class, 'rm_robots'], 20);
        add_filter('robots_txt', [self::class, 'robots_txt'], 100, 2);
        add_filter('wp_sitemaps_posts_query_args', [self::class, 'sitemap_args'], 10, 2);
        add_filter('wp_sitemaps_add_provider', [self::class, 'sitemap_szolgaltato'], 10, 2);
        add_filter('rank_math/sitemap/exclude_post', [self::class, 'rm_sitemap_kizar'], 10, 2);
    }

    /* =================================================================
     * Rank Math
     * ============================================================== */

    public static function rank_math(): bool
    {
        return (bool) apply_filters('sdh_sales_rank_math', class_exists('RankMath') || defined('RANK_MATH_VERSION'));
    }

    /** Rank Math morzsája be van-e kapcsolva (Általános beállítások → Morzsa). */
    public static function rank_math_morzsa(): bool
    {
        if (!self::rank_math()) {
            return false;
        }

        if (class_exists('RankMath\\Helper') && method_exists('RankMath\\Helper', 'get_settings')) {
            return (bool) call_user_func(['RankMath\\Helper', 'get_settings'], 'general.breadcrumbs');
        }

        $o = get_option('rank-math-options-general', []);

        return is_array($o) && in_array($o['breadcrumbs'] ?? 'off', ['on', true, 1, '1'], true);
    }

    /* =================================================================
     * Útvonalak, URL-ek
     * ============================================================== */

    /** @return array<string, string> tartalomtípus → szülőoldal slugja */
    public static function utvonalak(): array
    {
        return (array) apply_filters('sdh_sales_seo_utvonalak', [
            'funkcio'   => 'funkciok',
            'iparag'    => 'iparagak',
            'modul'     => 'modulok',
            'csomag'    => 'csomagok',
            'arak'      => 'arak',
            'gyik'      => 'gyik',
            'lecke'     => 'oktatas',
            'tudas'     => 'tudasbazis',
            'telepites' => 'telepites',
            'jogi'      => 'jogi',
            'esemeny'   => 'esemenyek',
            'kereses'   => 'kereses',
        ]);
    }

    public static function oldal(string $ut): ?WP_Post
    {
        $ut = trim($ut, '/');

        if ($ut === '') {
            return null;
        }

        if (!array_key_exists($ut, self::$oldal_cache)) {
            $p = get_page_by_path($ut);
            self::$oldal_cache[$ut] = ($p instanceof WP_Post && $p->post_status === 'publish') ? $p : null;
        }

        return self::$oldal_cache[$ut];
    }

    public static function urit(): void
    {
        self::$oldal_cache = [];
        self::$elem_cache  = [];
    }

    /** Oldal URL-je slug-útvonalból; ha nincs ilyen oldal, a szokásos permalink-alak. */
    public static function oldal_url(string $ut, string $horgony = ''): string
    {
        $p = self::oldal($ut);
        $u = $p ? (string) get_permalink($p) : home_url('/' . trim($ut, '/') . '/');

        return $u . ($horgony !== '' ? '#' . $horgony : '');
    }

    /**
     * Egy tartalom-elem nyilvános URL-je.
     *
     * @param array<string, mixed>|null $adat az elem adata (ha kéznél van: url / slug felülírja)
     */
    public static function url(string $tipus, string $kulcs, ?array $adat = null): string
    {
        $adat ??= (array) (self::elem($tipus, $kulcs)['adat'] ?? []);
        $ut   = self::utvonalak();

        if (!empty($adat['url']) && is_string($adat['url'])) {
            $u = $adat['url'];

            return (string) apply_filters('sdh_sales_url', $u[0] === '/' ? home_url($u) : $u, $tipus, $kulcs);
        }

        $slug = sanitize_title((string) ($adat['slug'] ?? $kulcs));

        switch ($tipus) {
            case 'funkcio':
            case 'iparag':
                $u = self::oldal_url($ut[$tipus] . '/' . $slug);
                break;
            case 'modul':
            case 'csomag':
                $u = self::oldal($ut[$tipus] . '/' . $slug) ? self::oldal_url($ut[$tipus] . '/' . $slug) : self::oldal_url($ut['arak'], $tipus . '-' . $slug);
                break;
            case 'gyik':
                $u = self::oldal_url($ut['gyik'], SDH_Sales_Gyik::anchor($kulcs));
                break;
            case 'lecke':
                $u = self::oldal_url($ut['lecke'], 'lecke-' . $slug);
                break;
            case 'tudas':
                $u = self::oldal($ut['tudas'] . '/' . $slug) ? self::oldal_url($ut['tudas'] . '/' . $slug) : self::oldal_url($ut['tudas'], $slug);
                break;
            case 'telepites':
                $u = self::oldal_url($ut['telepites'], 'lepes-' . $slug);
                break;
            case 'esemeny':
                $u = self::oldal_url($ut['esemeny'], 'esemeny-' . $slug);
                break;
            case 'blog':
                $p = ctype_digit($kulcs) ? get_post((int) $kulcs) : get_page_by_path($kulcs, OBJECT, 'post');
                $u = $p ? (string) get_permalink($p) : home_url('/' . $slug . '/');
                break;
            default:
                $u = self::oldal_url($kulcs);
        }

        return (string) apply_filters('sdh_sales_url', $u, $tipus, $kulcs);
    }

    /**
     * Tartalom-elemek kulcs szerint (kérésenként egyszer).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function elemek(string $tipus): array
    {
        if (!isset(self::$elem_cache[$tipus])) {
            $m = [];

            foreach (SDH_Sales_Tartalom::lista($tipus) as $e) {
                $m[(string) $e['kulcs']] = $e;
            }

            self::$elem_cache[$tipus] = $m;
        }

        return self::$elem_cache[$tipus];
    }

    /** @return array<string, mixed>|null */
    public static function elem(string $tipus, string $kulcs): ?array
    {
        return self::elemek($tipus)[$kulcs] ?? null;
    }

    /** Megjelenítendő név: adat.cim / nev / kerdes / a tábla cím mezője, tokenekkel. @param array<string, mixed> $e */
    public static function nev(array $e): string
    {
        $a = (array) ($e['adat'] ?? []);

        return SDH_Sales::t(trim((string) ($a['cim'] ?? $a['nev'] ?? $a['kerdes'] ?? $e['cim'] ?? $e['kulcs'] ?? '')));
    }

    /** Rövid leírás (sima szöveg). @param array<string, mixed> $e */
    public static function leiras(array $e, int $max = 0): string
    {
        $a = (array) ($e['adat'] ?? []);
        $s = '';

        foreach (['rovid', 'kivonat', 'leiras', 'alcim', 'osszefoglalo', 'valasz', 'szoveg', 'tartalom'] as $k) {
            if (!empty($a[$k]) && is_string($a[$k])) {
                $s = $a[$k];
                break;
            }
        }

        $s = self::sima(SDH_Sales::t($s));

        return $max > 0 ? self::rovidit($s, $max) : $s;
    }

    /** HTML → egysoros sima szöveg. */
    public static function sima(string $html): string
    {
        $s = wp_strip_all_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], ' ', $html));
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Szóhatáron rövidít, „…”-vel. */
    public static function rovidit(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        $r = mb_substr($s, 0, $max - 1);
        $p = mb_strrpos($r, ' ');

        if ($p !== false && $p > $max * 0.6) {
            $r = mb_substr($r, 0, $p);
        }

        return rtrim($r, " ,.;:–-") . '…';
    }

    /** @param mixed $v @return array<int, string> lista vesszővel / sortöréssel elválasztott szövegből vagy tömbből */
    public static function lista($v): array
    {
        if (is_string($v)) {
            $v = preg_split('/[,\n]+/u', $v) ?: [];
        }

        return is_array($v) ? array_values(array_filter(array_map(static fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v), 'strlen')) : [];
    }

    /* =================================================================
     * Árak (a tartalom-tárból – egyetlen forrás az árnak)
     * ============================================================== */

    /**
     * Csomagok áraival (nettó és bruttó, egész Ft).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function csomagok(): array
    {
        $ki = [];

        foreach (self::elemek('csomag') as $k => $e) {
            $a    = (array) $e['adat'];
            $havi = max(0, (int) ($a['havi'] ?? 0));
            $eves = max(0, (int) ($a['eves'] ?? 0));

            $ki[] = [
                'kulcs'        => (string) $k,
                'nev'          => self::nev($e),
                'leiras'       => self::leiras($e),
                'havi'         => $havi,
                'eves'         => $eves,
                'havi_brutto'  => SDH_Sales::brutto($havi),
                'eves_brutto'  => SDH_Sales::brutto($eves),
                'felhasznalo'  => (int) ($a['felhasznalo_alap'] ?? 0),
                'telephely'    => (int) ($a['telephely_alap'] ?? 0),
                'modulok'      => self::lista($a['modulok'] ?? []),
                'url'          => self::url('csomag', (string) $k, $a),
            ];
        }

        return $ki;
    }

    /** @return array<int, array<string, mixed>> */
    public static function modulok(): array
    {
        $ki = [];

        foreach (self::elemek('modul') as $k => $e) {
            $a    = (array) $e['adat'];
            $havi = max(0, (int) ($a['havi'] ?? 0));
            $eves = max(0, (int) ($a['eves'] ?? 0));

            $ki[] = [
                'kulcs'       => (string) $k,
                'nev'         => self::nev($e),
                'csoport'     => SDH_Sales::t((string) ($a['csoport'] ?? '')),
                'leiras'      => self::leiras($e),
                'havi'        => $havi,
                'eves'        => $eves,
                'havi_brutto' => SDH_Sales::brutto($havi),
                'eves_brutto' => SDH_Sales::brutto($eves),
                'csomagban'   => self::lista($a['csomagban'] ?? []),
                'url'         => self::url('modul', (string) $k, $a),
            ];
        }

        return $ki;
    }

    /* =================================================================
     * Az aktuális oldal besorolása
     * ============================================================== */

    /**
     * @return array{tipus: string, kulcs: string, ut: string}
     *   tipus: fooldal | arak | csomag | modul | funkcio | iparag | gyik | telepites | esemenyek | oldal | bejegyzes | kereses | egyeb
     */
    public static function kontextus(): array
    {
        if (is_front_page()) {
            return ['tipus' => 'fooldal', 'kulcs' => '', 'ut' => ''];
        }

        if (is_search()) {
            return ['tipus' => 'kereses', 'kulcs' => '', 'ut' => ''];
        }

        if (is_singular('post')) {
            return ['tipus' => 'bejegyzes', 'kulcs' => (string) get_queried_object_id(), 'ut' => ''];
        }

        if (!is_page()) {
            return ['tipus' => 'egyeb', 'kulcs' => '', 'ut' => ''];
        }

        $p  = get_queried_object();
        $ut = $p instanceof WP_Post ? get_page_uri($p) : '';
        $u  = self::utvonalak();

        if ($p instanceof WP_Post) {
            $meta = (string) get_post_meta($p->ID, '_sdh_seo_termek', true);

            if (preg_match('/^(csomag|modul):(.+)$/', $meta, $m) && self::elem($m[1], $m[2])) {
                return ['tipus' => $m[1], 'kulcs' => $m[2], 'ut' => $ut];
            }
        }

        foreach (['csomag', 'modul', 'funkcio', 'iparag'] as $t) {
            if (preg_match('#^' . preg_quote($u[$t], '#') . '/([^/]+)$#', $ut, $m) && ($t === 'funkcio' || $t === 'iparag' || self::elem($t, $m[1]))) {
                return ['tipus' => $t, 'kulcs' => $m[1], 'ut' => $ut];
            }
        }

        if (preg_match('#^' . preg_quote($u['arak'], '#') . '/([^/]+)$#', $ut, $m) && self::elem('csomag', $m[1])) {
            return ['tipus' => 'csomag', 'kulcs' => $m[1], 'ut' => $ut];
        }

        $sz = $p instanceof WP_Post ? array_column(SDH_Sales_Tartalom::szekciok($p->ID), 'tipus') : [];

        if ($ut === $u['arak']) {
            return ['tipus' => 'arak', 'kulcs' => '', 'ut' => $ut];
        }

        if ($ut === $u['gyik']) {
            return ['tipus' => 'gyik', 'kulcs' => '', 'ut' => $ut];
        }

        if ($ut === $u['telepites'] || in_array('telepito', $sz, true)) {
            return ['tipus' => 'telepites', 'kulcs' => '', 'ut' => $ut];
        }

        if ($ut === $u['esemeny'] || in_array('esemenyek', $sz, true)) {
            return ['tipus' => 'esemenyek', 'kulcs' => '', 'ut' => $ut];
        }

        return ['tipus' => 'oldal', 'kulcs' => '', 'ut' => $ut];
    }

    /* =================================================================
     * JSON-LD
     * ============================================================== */

    public static function id(string $mi): string
    {
        return home_url('/') . '#' . $mi;
    }

    /** JSON-LD script-blokk (HTML-be biztonságos: <, >, & kódolva). @param array<string, mixed> $adat */
    public static function ld_html(array $adat): string
    {
        $j = wp_json_encode($adat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        return is_string($j) ? '<script type="application/ld+json">' . $j . "</script>\n" : '';
    }

    /**
     * Az aktuális kérés sémacsomópontjai (a FAQPage nélkül – azt a Gyik írja a láblécbe, a ténylegesen megjelent kérdésekből).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function graf(): array
    {
        $rm  = self::rank_math();
        $k   = self::kontextus();
        $ki  = [];

        if (!$rm) {
            $ki[] = self::organization();
            $ki[] = self::website();

            if ((bool) SDH_Sales::b('seo.localbusiness', false) && trim((string) SDH_Sales::b('ceg.cim', '')) !== '' && $k['tipus'] === 'fooldal') {
                $ki[] = self::localbusiness();
            }
        }

        if (in_array($k['tipus'], ['fooldal', 'arak'], true)) {
            $ki[] = self::software();
        }

        if ($k['tipus'] === 'csomag' || $k['tipus'] === 'modul') {
            $p = self::termek($k['tipus'], $k['kulcs']);

            if ($p !== null) {
                $ki[] = $p;
            }
        }

        if ($k['tipus'] === 'telepites') {
            $h = self::howto();

            if ($h !== null) {
                $ki[] = $h;
            }
        }

        foreach (self::esemenyek($k) as $e) {
            $ki[] = $e;
        }

        if (!$rm && $k['tipus'] === 'bejegyzes') {
            $ki[] = self::article((int) $k['kulcs']);
        }

        if ((!$rm || !self::rank_math_morzsa()) && $k['tipus'] !== 'fooldal') {
            $b = self::breadcrumb();

            if ($b !== null) {
                $ki[] = $b;
            }
        }

        return array_values(array_filter((array) apply_filters('sdh_sales_seo_graf', $ki, $k, $rm)));
    }

    public static function graf_kiir(): void
    {
        if (is_admin() || is_feed() || is_404()) {
            return;
        }

        $g = self::graf();

        if ($g !== []) {
            echo self::ld_html(['@context' => 'https://schema.org', '@graph' => $g]); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON_HEX_TAG
        }
    }

    /** @return array<string, mixed> */
    public static function organization(): array
    {
        $o = [
            '@type' => 'Organization',
            '@id'   => self::id('organization'),
            'name'  => (string) (SDH_Sales::b('ceg.nev', '') ?: get_bloginfo('name')),
            'url'   => home_url('/'),
        ];

        $logo = self::logo();

        if ($logo !== '') {
            $o['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
        }

        foreach (['email' => 'ceg.email', 'telephone' => 'ceg.telefon', 'taxID' => 'ceg.adoszam'] as $p => $b) {
            $v = trim((string) SDH_Sales::b($b, ''));

            if ($v !== '') {
                $o[$p] = $v;
            }
        }

        $cim = self::cim();

        if ($cim !== null) {
            $o['address'] = $cim;
        }

        if (!empty($o['email']) || !empty($o['telephone'])) {
            $o['contactPoint'] = array_filter([
                '@type'             => 'ContactPoint',
                'contactType'       => 'customer support',
                'email'             => $o['email'] ?? null,
                'telephone'         => $o['telephone'] ?? null,
                'availableLanguage' => array_values(self::nyelvek()),
                'areaServed'        => 'HU',
            ]);
        }

        $same = array_values(array_filter(self::lista(SDH_Sales::b('seo.sameas', [])), [self::class, 'jo_url']));

        if ($same !== []) {
            $o['sameAs'] = $same;
        }

        return $o;
    }

    /** @return array<string, mixed> */
    public static function website(): array
    {
        return [
            '@type'           => 'WebSite',
            '@id'             => self::id('website'),
            'url'             => home_url('/'),
            'name'            => SDH_Sales::termeknev(),
            'inLanguage'      => 'hu-HU',
            'publisher'       => ['@id' => self::id('organization')],
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => home_url('/?s={search_term_string}')],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function localbusiness(): array
    {
        $tipus = preg_replace('/[^A-Za-z]/', '', (string) SDH_Sales::b('seo.localbusiness_tipus', 'LocalBusiness')) ?: 'LocalBusiness';
        $o     = self::organization();
        $l     = [
            '@type'              => $tipus,
            '@id'                => self::id('localbusiness'),
            'name'               => $o['name'],
            'url'                => home_url('/'),
            'parentOrganization' => ['@id' => self::id('organization')],
        ];

        foreach (['address', 'telephone', 'email', 'logo'] as $p) {
            if (isset($o[$p])) {
                $l[$p === 'logo' ? 'image' : $p] = $p === 'logo' ? $o['logo']['url'] : $o[$p];
            }
        }

        $ny = trim((string) SDH_Sales::b('seo.nyitvatartas', ''));

        if ($ny !== '') {
            $l['openingHours'] = self::lista($ny);
        }

        $geo = self::lista((string) SDH_Sales::b('seo.geo', ''));

        if (count($geo) === 2 && is_numeric($geo[0]) && is_numeric($geo[1])) {
            $l['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $geo[0], 'longitude' => (float) $geo[1]];
        }

        return $l;
    }

    /** @return array<string, mixed> */
    public static function software(): array
    {
        $s = [
            '@type'                  => 'SoftwareApplication',
            '@id'                    => self::id('software'),
            'name'                   => SDH_Sales::termeknev(),
            'url'                    => home_url('/'),
            'applicationCategory'    => 'BusinessApplication',
            'applicationSubCategory' => __('Szervizkezelő, munkalap- és ügyfélkezelő (CRM) szoftver', 'sdh-sales'),
            'operatingSystem'        => 'Web',
            'inLanguage'             => 'hu-HU',
            'publisher'              => ['@id' => self::id('organization')],
        ];

        $sz = trim(SDH_Sales::t((string) SDH_Sales::b('szlogen', '')));

        if ($sz !== '') {
            $s['description'] = $sz;
        }

        $f = array_slice(array_map([self::class, 'nev'], array_values(self::elemek('funkcio'))), 0, 30);

        if ($f !== []) {
            $s['featureList'] = $f;
        }

        $cs = self::csomagok();

        if ($cs !== []) {
            $brutto = (bool) SDH_Sales::b('seo.ar_brutto', false);
            $haviak = array_filter($cs, static fn ($c) => $c['havi'] > 0 || $c['eves'] === 0);
            $alap   = $haviak !== [] ? 'havi' : 'eves';
            $arak   = array_map(static fn ($c) => $brutto ? $c[$alap . '_brutto'] : $c[$alap], $cs);

            $s['offers'] = [
                '@type'         => 'AggregateOffer',
                'priceCurrency' => (string) SDH_Sales::b('penznem', 'HUF'),
                'lowPrice'      => min($arak),
                'highPrice'     => max($arak),
                'offerCount'    => count($cs),
                'offers'        => array_map(static fn ($c) => self::offer($c, false), $cs),
            ];
        }

        return $s;
    }

    /**
     * Egy ajánlat (csomag vagy modul) – ár nettóban (vagy bruttóban, ha `seo.ar_brutto`), áfa-jelzéssel.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    public static function offer(array $c, bool $teljes = true): array
    {
        $brutto = (bool) SDH_Sales::b('seo.ar_brutto', false);
        $penz   = (string) SDH_Sales::b('penznem', 'HUF');
        $spec   = [];

        foreach (['havi' => 'P1M', 'eves' => 'P1Y'] as $ciklus => $tartam) {
            if ($c[$ciklus] > 0) {
                $spec[] = [
                    '@type'                 => 'UnitPriceSpecification',
                    'price'                 => $brutto ? $c[$ciklus . '_brutto'] : $c[$ciklus],
                    'priceCurrency'         => $penz,
                    'valueAddedTaxIncluded' => $brutto,
                    'billingDuration'       => $tartam,
                    'unitText'              => $ciklus === 'havi' ? __('hónap', 'sdh-sales') : __('év', 'sdh-sales'),
                ];
            }
        }

        $ar = $spec !== [] ? $spec[0]['price'] : 0;
        $o  = [
            '@type'         => 'Offer',
            'name'          => (string) $c['nev'],
            'price'         => $ar,
            'priceCurrency' => $penz,
            'url'           => (string) $c['url'],
        ];

        if ($spec !== []) {
            $o['priceSpecification'] = count($spec) === 1 ? $spec[0] : $spec;
        }

        if ($teljes) {
            $o['availability']  = 'https://schema.org/InStock';
            $o['seller']        = ['@id' => self::id('organization')];
            $o['itemCondition'] = 'https://schema.org/NewCondition';
        }

        return $o;
    }

    /** @return array<string, mixed>|null Product + Offer egy csomag- vagy modul-oldalra */
    public static function termek(string $tipus, string $kulcs): ?array
    {
        $lista = $tipus === 'csomag' ? self::csomagok() : self::modulok();
        $c     = null;

        foreach ($lista as $x) {
            if ($x['kulcs'] === $kulcs) {
                $c = $x;
                break;
            }
        }

        if ($c === null) {
            return null;
        }

        $url = is_page() ? (string) get_permalink(get_queried_object_id()) : (string) $c['url'];
        $p   = [
            '@type'    => 'Product',
            '@id'      => $url . '#product',
            'name'     => SDH_Sales::termeknev() . ' – ' . $c['nev'],
            'sku'      => $tipus . '-' . $kulcs,
            'url'      => $url,
            'brand'    => ['@type' => 'Brand', 'name' => SDH_Sales::termeknev()],
            'category' => __('Üzleti szoftver', 'sdh-sales'),
            'offers'   => self::offer(['url' => $url] + $c),
        ];

        if ($c['leiras'] !== '') {
            $p['description'] = self::rovidit((string) $c['leiras'], 500);
        }

        $kep = self::kep();

        if ($kep !== '') {
            $p['image'] = $kep;
        }

        return $p;
    }

    /**
     * Event csomópontok (tartalomtípus `esemeny`: {cim|nev, kezdet, veg?, leiras?, url?, online?, helyszin?, ar?, kep?, oldal?}).
     * Megjelenik: az esemény-oldalon, ahol `esemenyek` szekció van, vagy ahol az esemény `oldal` mezője az oldal útja.
     *
     * @param array{tipus: string, kulcs: string, ut: string} $k
     * @return array<int, array<string, mixed>>
     */
    public static function esemenyek(array $k): array
    {
        $ki = [];

        foreach (self::elemek('esemeny') as $kulcs => $e) {
            $a      = (array) $e['adat'];
            $kezdet = strtotime((string) ($a['kezdet'] ?? ''));

            if ($kezdet === false) {
                continue;
            }

            $itt = $k['tipus'] === 'esemenyek' || ($k['ut'] !== '' && trim((string) ($a['oldal'] ?? ''), '/') === $k['ut']);

            if (!$itt) {
                continue;
            }

            $veg    = strtotime((string) ($a['veg'] ?? ''));
            $online = !isset($a['online']) || (bool) $a['online'];
            $url    = self::url('esemeny', (string) $kulcs, $a);
            $n      = [
                '@type'               => 'Event',
                '@id'                 => $url . (strpos($url, '#') === false ? '#esemeny-' . sanitize_title((string) $kulcs) : ''),
                'name'                => self::nev($e),
                'startDate'           => wp_date('c', $kezdet),
                'eventStatus'         => 'https://schema.org/EventScheduled',
                'eventAttendanceMode' => $online ? 'https://schema.org/OnlineEventAttendanceMode' : 'https://schema.org/OfflineEventAttendanceMode',
                'organizer'           => ['@id' => self::id('organization'), '@type' => 'Organization', 'name' => (string) (SDH_Sales::b('ceg.nev', '') ?: get_bloginfo('name')), 'url' => home_url('/')],
                'url'                 => $url,
                'inLanguage'          => 'hu-HU',
            ];

            if ($veg !== false && $veg > $kezdet) {
                $n['endDate'] = wp_date('c', $veg);
            }

            $l = self::leiras($e, 500);

            if ($l !== '') {
                $n['description'] = $l;
            }

            $n['location'] = $online
                ? ['@type' => 'VirtualLocation', 'url' => (string) ($a['csatlakozas_url'] ?? $url)]
                : ['@type' => 'Place', 'name' => SDH_Sales::t((string) ($a['helyszin'] ?? '')), 'address' => SDH_Sales::t((string) ($a['cim'] ?? $a['helyszin'] ?? ''))];

            $n['offers'] = [
                '@type'         => 'Offer',
                'price'         => max(0, (int) ($a['ar'] ?? 0)),
                'priceCurrency' => (string) SDH_Sales::b('penznem', 'HUF'),
                'availability'  => 'https://schema.org/InStock',
                'url'           => $url,
            ];

            if (!empty($a['kep'])) {
                $n['image'] = esc_url_raw((string) $a['kep']);
            }

            $ki[] = $n;
        }

        return $ki;
    }

    /**
     * HowTo a telepítési oldalra a `telepites` tartalomelemekből. Elemformák (mind elfogadott):
     *  – lépésenként egy elem: {cim, leiras|szoveg, sorrend}
     *  – egy elem `lepesek: [{cim, leiras}]` listával
     *  – követelmény / eszköz: elem `tipus: "kovetelmeny"` vagy `kovetelmenyek: [..]` lista
     *
     * @return array<string, mixed>|null
     */
    public static function howto(): ?array
    {
        $lepesek = [];
        $eszkoz  = [];

        foreach (self::elemek('telepites') as $e) {
            $a = (array) $e['adat'];

            foreach (self::lista($a['kovetelmenyek'] ?? []) as $k) {
                $eszkoz[] = SDH_Sales::t($k);
            }

            if (($a['tipus'] ?? '') === 'kovetelmeny') {
                $eszkoz[] = self::nev($e);
                continue;
            }

            if (($a['tipus'] ?? '') === 'ellenorzolista') {
                continue;
            }

            if (is_array($a['lepesek'] ?? null)) {
                foreach ($a['lepesek'] as $l) {
                    if (is_array($l) && !empty($l['cim'])) {
                        $lepesek[] = ['nev' => SDH_Sales::t((string) $l['cim']), 'szoveg' => self::sima(SDH_Sales::t((string) ($l['leiras'] ?? $l['szoveg'] ?? $l['cim'])))];
                    } elseif (is_string($l) && trim($l) !== '') {
                        $lepesek[] = ['nev' => SDH_Sales::t($l), 'szoveg' => SDH_Sales::t($l)];
                    }
                }
                continue;
            }

            $nev = self::nev($e);

            if ($nev !== '') {
                $lepesek[] = ['nev' => $nev, 'szoveg' => self::leiras($e) ?: $nev];
            }
        }

        if ($lepesek === []) {
            return null;
        }

        $url = is_page() ? (string) get_permalink(get_queried_object_id()) : self::oldal_url(self::utvonalak()['telepites']);
        $h   = [
            '@type'      => 'HowTo',
            '@id'        => $url . '#howto',
            'name'       => sprintf(/* translators: %s: termék neve */ __('A(z) %s telepítése', 'sdh-sales'), SDH_Sales::termeknev()),
            'inLanguage' => 'hu-HU',
            'step'       => [],
        ];

        foreach (array_values($lepesek) as $i => $l) {
            $h['step'][] = ['@type' => 'HowToStep', 'position' => $i + 1, 'name' => $l['nev'], 'text' => self::rovidit($l['szoveg'], 500), 'url' => $url . '#lepes-' . ($i + 1)];
        }

        $eszkoz = array_values(array_unique(array_filter($eszkoz)));

        if ($eszkoz !== []) {
            $h['tool'] = array_map(static fn ($t) => ['@type' => 'HowToTool', 'name' => $t], $eszkoz);
        }

        return $h;
    }

    /** @return array<string, mixed> */
    public static function article(int $id): array
    {
        $p   = get_post($id);
        $url = (string) get_permalink($id);
        $a   = [
            '@type'            => 'BlogPosting',
            '@id'              => $url . '#article',
            'headline'         => self::rovidit(SDH_Sales::t(wp_strip_all_tags(get_the_title($id))), 110),
            'url'              => $url,
            'mainEntityOfPage' => $url,
            'datePublished'    => (string) get_post_time('c', true, $id),
            'dateModified'     => (string) get_post_modified_time('c', true, $id),
            'inLanguage'       => 'hu-HU',
            'publisher'        => ['@id' => self::id('organization')],
        ];

        $l = self::meta_leiras();

        if ($l !== '') {
            $a['description'] = $l;
        }

        $szerzo = trim((string) SDH_Sales::b('seo.szerzo', ''));
        $a['author'] = $szerzo !== ''
            ? array_filter(['@type' => 'Person', 'name' => $szerzo, 'url' => (string) SDH_Sales::b('seo.szerzo_url', '') ?: null])
            : ['@id' => self::id('organization')];

        $kep = self::kep();

        if ($kep !== '') {
            $a['image'] = $kep;
        }

        $c = wp_get_post_tags($id, ['fields' => 'names']);

        if (is_array($c) && $c !== []) {
            $a['keywords'] = implode(', ', $c);
        }

        $kat = get_the_category($id);

        if ($kat !== []) {
            $a['articleSection'] = $kat[0]->name;
        }

        if ($p instanceof WP_Post) {
            $a['wordCount'] = count(preg_split('/\s+/u', trim(wp_strip_all_tags($p->post_content))) ?: []);
        }

        return $a;
    }

    /** @return array<string, mixed>|null */
    public static function breadcrumb(): ?array
    {
        $m = self::morzsa();

        if (count($m) < 2) {
            return null;
        }

        $l = [];

        foreach ($m as $i => $e) {
            $l[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $e['cim'], 'item' => $e['url']];
        }

        return ['@type' => 'BreadcrumbList', '@id' => $m[count($m) - 1]['url'] . '#breadcrumb', 'itemListElement' => $l];
    }

    /** Szintaktikailag érvényes, nyilvános http(s) URL (DNS-feloldás nélkül). */
    public static function jo_url(string $u): bool
    {
        return filter_var($u, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) wp_parse_url($u, PHP_URL_SCHEME)), ['http', 'https'], true) && strpos((string) wp_parse_url($u, PHP_URL_HOST), '.') !== false;
    }

    /** @return array<string, string>|null */
    private static function cim(): ?array
    {
        $c = trim((string) SDH_Sales::b('ceg.cim', ''));

        if ($c === '') {
            return null;
        }

        $a = ['@type' => 'PostalAddress', 'streetAddress' => $c, 'addressCountry' => 'HU'];

        // „8200 Veszprém, Fő utca 1.” alak → irányítószám + település külön.
        if (preg_match('/^\s*(\d{4})\s+([^,]+),\s*(.+)$/u', $c, $m)) {
            $a['postalCode']      = $m[1];
            $a['addressLocality'] = trim($m[2]);
            $a['streetAddress']   = trim($m[3]);
        }

        return $a;
    }

    private static function logo(): string
    {
        $l = trim((string) SDH_Sales::b('seo.logo', ''));

        if ($l === '' && ($id = (int) get_theme_mod('custom_logo')) > 0) {
            $l = (string) wp_get_attachment_image_url($id, 'full');
        }

        if ($l === '') {
            $l = (string) get_site_icon_url(512);
        }

        return $l;
    }

    /** Az oldal képe: kiemelt kép, különben a `seo.og_kep` beállítás. */
    public static function kep(): string
    {
        if (is_singular() && has_post_thumbnail(get_queried_object_id())) {
            return (string) get_the_post_thumbnail_url(get_queried_object_id(), 'full');
        }

        return trim((string) SDH_Sales::b('seo.og_kep', ''));
    }

    /* =================================================================
     * Morzsa
     * ============================================================== */

    /** @return array<int, array{cim: string, url: string}> */
    public static function morzsa(): array
    {
        if (is_front_page()) {
            return [];
        }

        $m = [['cim' => __('Főoldal', 'sdh-sales'), 'url' => home_url('/')]];

        $blog = static function () use (&$m): void {
            $b = (int) get_option('page_for_posts');

            if ($b > 0 && get_post_status($b) === 'publish') {
                $m[] = ['cim' => SDH_Sales::t(get_the_title($b)), 'url' => (string) get_permalink($b)];
            }
        };

        if (is_singular('post')) {
            $id = get_queried_object_id();
            $blog();
            $k = get_the_category($id);

            if ($k !== []) {
                $m[] = ['cim' => $k[0]->name, 'url' => (string) get_category_link($k[0])];
            }

            $m[] = ['cim' => SDH_Sales::t(wp_strip_all_tags(get_the_title($id))), 'url' => (string) get_permalink($id)];
        } elseif (is_page()) {
            $id = get_queried_object_id();

            foreach (array_reverse(get_post_ancestors($id)) as $a) {
                if ((int) $a !== (int) get_option('page_on_front')) {
                    $m[] = ['cim' => SDH_Sales::t(wp_strip_all_tags(get_the_title($a))), 'url' => (string) get_permalink($a)];
                }
            }

            $m[] = ['cim' => SDH_Sales::t(wp_strip_all_tags(get_the_title($id))), 'url' => (string) get_permalink($id)];
        } elseif (is_home()) {
            $blog();
        } elseif (is_category() || is_tag() || is_tax()) {
            $blog();
            $t = get_queried_object();

            if ($t instanceof WP_Term) {
                $m[] = ['cim' => $t->name, 'url' => (string) get_term_link($t)];
            }
        } elseif (is_search()) {
            $m[] = ['cim' => sprintf(/* translators: %s: keresett szöveg */ __('Keresés: „%s”', 'sdh-sales'), get_search_query(false)), 'url' => (string) get_search_link()];
        } elseif (is_404()) {
            $m[] = ['cim' => __('Az oldal nem található', 'sdh-sales'), 'url' => home_url(add_query_arg([]))];
        }

        return (array) apply_filters('sdh_sales_morzsa', $m);
    }

    /* =================================================================
     * Belső linkháló
     * ============================================================== */

    /**
     * Kapcsolódó belső linkek egy elemhez (a téma „Kapcsolódó” blokkjához).
     * Forrás: funkciok.json `kapcsolodo` / `modulok` / `modul`, iparagak.json `funkciok`, gyik.json `kapcsolodo`, blog-címkék
     * (címke slugja = funkció- / iparág- / modulkulcs, vagy a funkció kulcsszava) és a blogcikkek belső linkjei.
     *
     * @return array<int, array{cim: string, url: string, tipus: string, leiras: string}>
     */
    public static function kapcsolodo(string $tipus, string $kulcs, int $max = 6): array
    {
        $ki   = [];
        $volt = [$tipus . ':' . $kulcs => true];

        $add = static function (string $t, string $k) use (&$ki, &$volt): void {
            if (isset($volt[$t . ':' . $k])) {
                return;
            }

            if ($t === 'blog') {
                $p = ctype_digit($k) ? get_post((int) $k) : get_page_by_path($k, OBJECT, 'post');

                if (!$p instanceof WP_Post || $p->post_status !== 'publish') {
                    return;
                }

                $volt['blog:' . $p->ID] = $volt['blog:' . $p->post_name] = true;
                $ki[] = ['cim' => SDH_Sales::t(wp_strip_all_tags($p->post_title)), 'url' => (string) get_permalink($p), 'tipus' => 'blog', 'leiras' => self::rovidit(self::sima($p->post_excerpt !== '' ? $p->post_excerpt : $p->post_content), 140)];

                return;
            }

            $e = self::elem($t, $k);

            if ($e === null) {
                return;
            }

            $volt[$t . ':' . $k] = true;
            $ki[] = ['cim' => self::nev($e), 'url' => self::url($t, $k, (array) $e['adat']), 'tipus' => $t, 'leiras' => self::leiras($e, 140)];
        };

        // Bármelyik típusú kulcs feloldása (a gyik.json kapcsolodo mezője vegyes lehet).
        $barmi = static function (string $k) use ($add): void {
            foreach (['funkcio', 'iparag', 'modul', 'gyik', 'csomag'] as $t) {
                if (self::elem($t, $k) !== null) {
                    $add($t, $k);
                    return;
                }
            }
        };

        $e = $tipus === 'blog' ? null : self::elem($tipus, $kulcs);
        $a = (array) ($e['adat'] ?? []);

        switch ($tipus) {
            case 'funkcio':
                foreach (self::lista($a['kapcsolodo'] ?? []) as $k) {
                    $barmi($k);
                }
                foreach (self::lista($a['modulok'] ?? ($a['modul'] ?? [])) as $k) {
                    $add('modul', $k);
                }
                foreach (self::elemek('iparag') as $ik => $ie) {
                    if (in_array($kulcs, self::lista($ie['adat']['funkciok'] ?? []), true)) {
                        $add('iparag', (string) $ik);
                    }
                }
                break;
            case 'iparag':
                foreach (self::lista($a['funkciok'] ?? []) as $k) {
                    $add('funkcio', $k);
                }
                foreach (self::lista($a['modulok'] ?? []) as $k) {
                    $add('modul', $k);
                }
                foreach (self::lista($a['kapcsolodo'] ?? []) as $k) {
                    $barmi($k);
                }
                break;
            case 'modul':
                foreach (self::elemek('funkcio') as $fk => $fe) {
                    $fm = self::lista($fe['adat']['modulok'] ?? ($fe['adat']['modul'] ?? []));

                    if (in_array($kulcs, $fm, true)) {
                        $add('funkcio', (string) $fk);
                    }
                }
                foreach (self::lista($a['kapcsolodo'] ?? []) as $k) {
                    $barmi($k);
                }
                break;
            case 'gyik':
            case 'csomag':
                foreach (self::lista($a['kapcsolodo'] ?? []) as $k) {
                    $barmi($k);
                }
                foreach (self::lista($a['modulok'] ?? []) as $k) {
                    $add('modul', $k);
                }
                break;
            case 'blog':
                $p = ctype_digit($kulcs) ? get_post((int) $kulcs) : get_page_by_path($kulcs, OBJECT, 'post');

                if ($p instanceof WP_Post) {
                    $volt['blog:' . $p->ID] = $volt['blog:' . $p->post_name] = true;
                    $cimkek = wp_get_post_tags($p->ID, ['fields' => 'slugs']);
                    $cimkek = is_array($cimkek) ? $cimkek : [];

                    // A cikk belső linkjei a funkció- / iparág-oldalakra.
                    $u = self::utvonalak();

                    if (preg_match_all('#/(' . preg_quote($u['funkcio'], '#') . '|' . preg_quote($u['iparag'], '#') . ')/([a-z0-9-]+)#', $p->post_content, $mm, PREG_SET_ORDER)) {
                        foreach ($mm as $x) {
                            $add($x[1] === $u['funkcio'] ? 'funkcio' : 'iparag', $x[2]);
                        }
                    }

                    foreach ($cimkek as $c) {
                        $barmi((string) $c);
                    }

                    foreach (self::elemek('funkcio') as $fk => $fe) {
                        $szavak = array_map('sanitize_title', array_merge(self::lista($fe['adat']['kulcsszavak'] ?? []), self::lista($fe['adat']['kulcsszo'] ?? [])));

                        if (array_intersect($szavak, $cimkek) !== []) {
                            $add('funkcio', (string) $fk);
                        }
                    }

                    if ($cimkek !== []) {
                        foreach (get_posts(['post_type' => 'post', 'numberposts' => $max, 'tag_slug__in' => $cimkek, 'post__not_in' => [$p->ID], 'fields' => 'ids', 'suppress_filters' => false]) as $pid) {
                            $add('blog', (string) $pid);
                        }
                    }
                }
                break;
        }

        // Blogcikkek az elemhez: címke = kulcs / kulcsszó, vagy a cikk linkel az elem oldalára.
        if ($tipus !== 'blog' && count($ki) < $max) {
            $cimkek = [sanitize_title($kulcs)];

            foreach (array_merge(self::lista($a['kulcsszavak'] ?? []), self::lista($a['kulcsszo'] ?? [])) as $w) {
                $cimkek[] = sanitize_title($w);
            }

            $ids = get_posts(['post_type' => 'post', 'numberposts' => $max, 'tag_slug__in' => array_values(array_unique($cimkek)), 'fields' => 'ids', 'suppress_filters' => false]);

            if (in_array($tipus, ['funkcio', 'iparag'], true)) {
                global $wpdb;
                $minta = '%' . $wpdb->esc_like('/' . self::utvonalak()[$tipus] . '/' . sanitize_title($kulcs)) . '%';
                $ids   = array_merge($ids, array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_content LIKE %s ORDER BY post_date DESC LIMIT %d", $minta, $max))));
            }

            foreach (array_unique($ids) as $pid) {
                $add('blog', (string) $pid);
            }
        }

        return array_slice((array) apply_filters('sdh_sales_kapcsolodo', $ki, $tipus, $kulcs), 0, max(1, $max));
    }

    /* =================================================================
     * Meta-tartalék (Rank Math nélkül)
     * ============================================================== */

    public static function meta_cim(): string
    {
        $c = '';

        if (is_singular()) {
            $c = (string) get_post_meta(get_queried_object_id(), '_sdh_seo_title', true);
        } elseif (is_home() && (int) get_option('page_for_posts') > 0) {
            $c = (string) get_post_meta((int) get_option('page_for_posts'), '_sdh_seo_title', true);
        }

        if ($c === '' && is_front_page()) {
            $sz = (string) SDH_Sales::b('szlogen', '');
            $c  = '{termeknev}' . ($sz !== '' ? ' – ' . $sz : '');
        }

        return trim(SDH_Sales::t($c));
    }

    public static function meta_leiras(): string
    {
        $l  = '';
        $id = is_singular() ? get_queried_object_id() : (is_home() ? (int) get_option('page_for_posts') : 0);

        if ($id > 0) {
            $l = (string) get_post_meta($id, '_sdh_seo_description', true);

            if ($l === '') {
                $p = get_post($id);
                $l = $p instanceof WP_Post ? ($p->post_excerpt !== '' ? $p->post_excerpt : $p->post_content) : '';
            }
        } elseif (is_category() || is_tag()) {
            $l = term_description();
        }

        if (trim(self::sima($l)) === '' && is_front_page()) {
            $l = (string) SDH_Sales::b('szlogen', '');
        }

        return self::rovidit(self::sima(SDH_Sales::t($l)), 160);
    }

    /** @param string $cim */
    public static function dokumentum_cim($cim)
    {
        if (self::rank_math() || is_admin()) {
            return $cim;
        }

        $c = self::meta_cim();

        return $c !== '' ? $c : $cim;
    }

    public static function kanonikus(): string
    {
        if (is_search() || is_404()) {
            return '';
        }

        $lap = max(1, (int) get_query_var('paged'));

        if (is_front_page()) {
            return home_url('/');
        }

        if (is_singular()) {
            return (string) wp_get_canonical_url(get_queried_object_id());
        }

        if (is_home() || is_category() || is_tag() || is_tax()) {
            return (string) get_pagenum_link($lap, false);
        }

        return '';
    }

    public static function fej_beallit(): void
    {
        if (!self::rank_math()) {
            remove_action('wp_head', 'rel_canonical');
        }
    }

    public static function meta_kiir(): void
    {
        if (is_admin() || self::rank_math()) {
            self::bing_kiir();
            return;
        }

        remove_action('wp_head', 'rel_canonical');

        $cim   = self::meta_cim() ?: wp_get_document_title();
        $leir  = self::meta_leiras();
        $kan   = self::kanonikus();
        $kep   = self::kep();
        $cikk  = is_singular('post');
        $ki    = '';

        if ($leir !== '') {
            $ki .= '<meta name="description" content="' . esc_attr($leir) . '">' . "\n";
        }

        if ($kan !== '') {
            $ki .= '<link rel="canonical" href="' . esc_url($kan) . '">' . "\n";
        }

        $og = [
            'og:locale'    => 'hu_HU',
            'og:type'      => $cikk ? 'article' : 'website',
            'og:site_name' => SDH_Sales::termeknev(),
            'og:title'     => $cim,
            'og:description' => $leir,
            'og:url'       => $kan !== '' ? $kan : home_url(add_query_arg([])),
            'og:image'     => $kep,
        ];

        if ($cikk) {
            $og['article:published_time'] = (string) get_post_time('c', true, get_queried_object_id());
            $og['article:modified_time']  = (string) get_post_modified_time('c', true, get_queried_object_id());
        }

        foreach ($og as $p => $v) {
            if ($v !== '') {
                $ki .= '<meta property="' . esc_attr($p) . '" content="' . esc_attr($v) . '">' . "\n";
            }
        }

        $tw = [
            'twitter:card'        => $kep !== '' ? 'summary_large_image' : 'summary',
            'twitter:title'       => $cim,
            'twitter:description' => $leir,
            'twitter:image'       => $kep,
            'twitter:site'        => (string) SDH_Sales::b('seo.twitter', ''),
        ];

        foreach ($tw as $p => $v) {
            if ($v !== '') {
                $ki .= '<meta name="' . esc_attr($p) . '" content="' . esc_attr($v) . '">' . "\n";
            }
        }

        echo $ki; // phpcs:ignore WordPress.Security.EscapeOutput -- fent escapelve
        self::bing_kiir();
    }

    /** Ha az AI-botok tiltva vannak, a Bing ne használja a tartalmat a Copilot-válaszokban (a keresőben marad). */
    private static function bing_kiir(): void
    {
        if (!(bool) SDH_Sales::b('seo.ai_botok', true) && !(bool) SDH_Sales::b('seo.ai_botok_bing', false)) {
            echo '<meta name="bingbot" content="noarchive">' . "\n";
        }
    }

    /* =================================================================
     * hreflang
     * ============================================================== */

    /** @return array<int, string> */
    public static function nyelvek(): array
    {
        $n = SDH_Sales::b('nyelvek', ['hu']);
        $n = array_values(array_unique(array_filter(array_map(static fn ($x) => sanitize_key((string) $x), is_array($n) ? $n : self::lista((string) $n)))));

        return $n !== [] ? $n : ['hu'];
    }

    /**
     * hreflang-párok az aktuális oldalra (csak 1-nél több nyelvnél). Szerkezet: az első nyelv (hu) a gyökérben,
     * a többi `/<nyelv>/<ugyanaz az út>` alatt; x-default = az első nyelv.
     *
     * @return array<string, string> hreflang → URL
     */
    public static function hreflang(): array
    {
        $ny = self::nyelvek();

        if (count($ny) < 2 || is_search() || is_404()) {
            return [];
        }

        $kan = self::kanonikus() ?: home_url(add_query_arg([]));
        $ut  = ltrim((string) substr($kan, strlen(home_url('/'))), '/');
        $ki  = [];

        foreach ($ny as $i => $n) {
            $ki[$n] = $i === 0 ? $kan : home_url('/' . $n . '/' . $ut);
        }

        $ki['x-default'] = $ki[$ny[0]];

        return (array) apply_filters('sdh_sales_hreflang', $ki);
    }

    public static function hreflang_kiir(): void
    {
        foreach (self::hreflang() as $n => $u) {
            echo '<link rel="alternate" hreflang="' . esc_attr($n) . '" href="' . esc_url($u) . '">' . "\n";
        }
    }

    /* =================================================================
     * Robots: meta, robots.txt, oldaltérkép
     * ============================================================== */

    /** @return array<int, string> a nem indexelendő oldalak slugjai (első szint) */
    public static function noindex_slugok(): array
    {
        $s = self::lista(SDH_Sales::b('seo.noindex', ['kosar', 'penztar', 'fiok', 'kereses']));

        return $s !== [] ? array_map('sanitize_title', $s) : ['kosar', 'penztar', 'fiok', 'kereses'];
    }

    /** Segédoldal-e (kosár, pénztár, fiók, keresés – vagy ilyen szekciót tartalmaz). */
    public static function segedoldal(int $post_id): bool
    {
        $p = get_post($post_id);

        if (!$p instanceof WP_Post || $p->post_type !== 'page') {
            return false;
        }

        $elso = explode('/', get_page_uri($p))[0];

        if (in_array($elso, self::noindex_slugok(), true)) {
            return true;
        }

        if ((string) get_post_meta($post_id, '_sdh_noindex', true) === '1') {
            return true;
        }

        return array_intersect(array_column(SDH_Sales_Tartalom::szekciok($post_id), 'tipus'), ['kosar', 'penztar', 'fiok']) !== [];
    }

    public static function noindex_kell(): bool
    {
        // Keresés – a lapozott, üres (404-es) keresési oldal is.
        if (is_search() || (string) get_query_var('s') !== '') {
            return true;
        }

        return is_page() && self::segedoldal(get_queried_object_id());
    }

    /** @param array<string, bool|string> $r */
    public static function robots(array $r): array
    {
        if (self::noindex_kell()) {
            unset($r['index'], $r['max-image-preview'], $r['max-snippet'], $r['max-video-preview']);
            $r['noindex'] = true;
            $r['follow']  = true;
        }

        return $r;
    }

    /** @param array<string, string> $r */
    public static function rm_robots($r)
    {
        if (is_array($r) && self::noindex_kell()) {
            $r['index']  = 'noindex';
            $r['follow'] = 'follow';
        }

        return $r;
    }

    /** @return array<int, string> a robots.txt-ben tiltott utak (a webhely útjához igazítva) */
    public static function tiltott_utak(): array
    {
        $alap = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        $alap = '/' . trim($alap, '/');
        $alap = $alap === '/' ? '' : $alap;
        $ki   = [];

        foreach (['kosar', 'penztar', 'fiok'] as $s) {
            $ki[] = $alap . '/' . $s . '/';
        }

        $ki[] = $alap . '/' . rest_get_url_prefix() . '/' . SDH_Sales_Rest::NS . '/';

        return array_values(array_unique((array) apply_filters('sdh_sales_robots_tiltott', $ki)));
    }

    /**
     * Oldaltérkép-URL-ek. Rejtéssel (alap) a saját /sitemap.xml (SDH_Sales_Rejtes::sitemap; a core oldaltérképe ki van
     * kapcsolva, a címe árulkodó lenne).
     *
     * @return array<int, string>
     */
    public static function sitemapok(): array
    {
        if (self::rank_math()) {
            return [home_url('/sitemap_index.xml')];
        }

        if (class_exists('SDH_Sales_Rejtes') && SDH_Sales_Rejtes::be('aktiv')) {
            return [home_url('/sitemap.xml')];
        }

        return function_exists('wp_sitemaps_get_server') && wp_sitemaps_get_server()->sitemaps_enabled() ? [get_sitemap_url('index')] : [];
    }

    /**
     * robots.txt: a meglévő (core / Rank Math) kimenetet kiegészíti – a segédoldalak tiltása a `*` csoportba, AI-bot-csoport,
     * és a megfelelő Sitemap-sor (a régieket kiveszi, hogy ne legyen kettő).
     *
     * @param string     $ki
     * @param bool|mixed $nyilvanos
     */
    public static function robots_txt($ki, $nyilvanos = true): string
    {
        $ki = str_replace("\r\n", "\n", (string) $ki);

        if (!$nyilvanos) {
            return $ki;
        }

        $sorok = [];

        foreach (explode("\n", $ki) as $s) {
            if (stripos(trim($s), 'sitemap:') === 0) {
                continue;
            }

            $sorok[] = rtrim($s);
        }

        $ki      = trim(implode("\n", $sorok));
        $tiltott = self::tiltott_utak();
        $plusz   = implode("\n", array_map(static fn ($u) => 'Disallow: ' . $u, $tiltott));

        if (preg_match('/^User-agent:\s*\*\s*$/mi', $ki)) {
            $ki = (string) preg_replace('/^(User-agent:\s*\*\s*)$/mi', "$1\n" . $plusz, $ki, 1);
        } else {
            $ki = "User-agent: *\n" . $plusz . ($ki !== '' ? "\n" . $ki : '');
        }

        $ki .= "\n\n# AI-keresők és -asszisztensek (" . ((bool) SDH_Sales::b('seo.ai_botok', true) ? 'engedélyezve' : 'tiltva') . ")\n";

        if ((bool) SDH_Sales::b('seo.ai_botok', true)) {
            $botok = array_merge(self::AI_BOTOK, self::BING_BOTOK);
            $ki   .= implode("\n", array_map(static fn ($b) => 'User-agent: ' . $b, $botok)) . "\nAllow: /\n";
            $ki   .= implode("\n", array_map(static fn ($u) => 'Disallow: ' . $u, $tiltott)) . "\n";
        } else {
            $ki .= implode("\n", array_map(static fn ($b) => 'User-agent: ' . $b, self::AI_BOTOK)) . "\nDisallow: /\n";

            if ((bool) SDH_Sales::b('seo.ai_botok_bing', false)) {
                $ki .= "\n" . implode("\n", array_map(static fn ($b) => 'User-agent: ' . $b, self::BING_BOTOK)) . "\nDisallow: /\n";
            }
        }

        $sm = self::sitemapok();

        if ($sm !== []) {
            $ki .= "\n" . implode("\n", array_map(static fn ($u) => 'Sitemap: ' . $u, $sm)) . "\n";
        }

        return $ki;
    }

    /** @return array<int, int> a segédoldalak ID-i */
    public static function segedoldal_idk(): array
    {
        $ids = [];

        foreach (self::noindex_slugok() as $s) {
            $p = self::oldal($s);

            if ($p !== null) {
                $ids[] = (int) $p->ID;
                $ids   = array_merge($ids, array_map('intval', wp_list_pluck(get_pages(['child_of' => $p->ID, 'post_status' => 'publish']), 'ID')));
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $args */
    public static function sitemap_args(array $args, string $post_type): array
    {
        if ($post_type === 'page') {
            $args['post__not_in'] = array_values(array_unique(array_merge((array) ($args['post__not_in'] ?? []), self::segedoldal_idk())));
        }

        return $args;
    }

    /** A szerzői oldaltérkép nem kell (egy cég ír, a szerzői archívum vékony tartalom). @param mixed $sz */
    public static function sitemap_szolgaltato($sz, string $nev)
    {
        return $nev === 'users' ? false : $sz;
    }

    /** @param mixed $kizar @param mixed $post */
    public static function rm_sitemap_kizar($kizar, $post)
    {
        $id = is_object($post) && isset($post->ID) ? (int) $post->ID : (is_numeric($post) ? (int) $post : 0);

        return $id > 0 && self::segedoldal($id) ? true : $kizar;
    }
}
