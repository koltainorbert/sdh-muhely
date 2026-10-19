<?php
/**
 * SDH Értékesítés – GYIK-segéd a téma `gyik` szekciójához + FAQPage séma a ténylegesen megjelent kérdésekből.
 *
 * Adat: `gyik` tartalomtípus (data/gyik.json: {kulcs, kategoria, kerdes, valasz (HTML: p/ul/li/strong/a), kapcsolodo[]}).
 *
 * A téma API-ja:
 *   SDH_Sales_Gyik::szekcio($sz)          → a szekció kérdései ({kategoria?, kategoriak?, kulcsok?, max?}), és meg is jelöli
 *                                           őket megjelentként (FAQPage). Ezt hívd a `szekciok/gyik.php` sablonból.
 *   SDH_Sales_Gyik::lista_kategoriaval()  → [ {kategoria, slug, anchor, elemek: [elem…]} ] (a teljes GYIK-oldalhoz)
 *   SDH_Sales_Gyik::lista($kategoria?)    → [elem…]
 *   SDH_Sales_Gyik::egy($kulcs)           → elem | null
 *   SDH_Sales_Gyik::anchor($kulcs)        → "gyik-<kulcs>" (az elem `id`-je az oldalon, a kereső és a llms.txt erre linkel)
 *   SDH_Sales_Gyik::megjelent($kulcs)     → jelzi, hogy a kérdés kirajzolódott (a FAQPage csak ezeket tartalmazza)
 * Elem: {kulcs, kategoria (megjelenő név), kategoria_kulcs, kerdes (sima szöveg, tokenekkel), valasz (tisztított HTML, tokenekkel), valasz_szoveg, anchor,
 *        url, kapcsolodo[]}
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Gyik
{
    /** A válasz-HTML-ben engedett elemek (a szerződés szerint p/ul/li/strong/a, + ol/em/br/code a kényelemért). */
    private const ENGEDETT = [
        'p'      => [],
        'ul'     => [],
        'ol'     => [],
        'li'     => [],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'i'      => [],
        'br'     => [],
        'code'   => [],
        'a'      => ['href' => true, 'title' => true, 'rel' => true, 'target' => true],
    ];

    /** @var array<string, bool> a kérésben kirajzolt kérdések (sorrendben) */
    private static array $megjelent = [];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    public static function init(): void
    {
        add_action('wp_footer', [self::class, 'faq_kiir'], 20);
    }

    public static function anchor(string $kulcs): string
    {
        return 'gyik-' . sanitize_title($kulcs);
    }

    /** Tisztított válasz-HTML (tokenek cserélve, csak engedett elemek, külső link rel=noopener). */
    public static function html(string $h): string
    {
        $h = (string) preg_replace('#<(script|style|iframe|object|template)\b[^>]*>.*?</\1\s*>#is', '', SDH_Sales::t($h));
        $h = wp_kses($h, self::ENGEDETT, ['http', 'https', 'mailto', 'tel']);
        $h = (string) preg_replace_callback('/<a\s([^>]*)>/i', static function (array $m): string {
            // Gyökérhez képest relatív link („/arak/”) → a webhely címével (alkönyvtáras telepítés, JSON-LD, llms.txt).
            $attr = (string) preg_replace_callback('/href=(["\'])(\/(?!\/)[^"\']*)\1/i', static fn ($x) => 'href="' . esc_url(home_url($x[2])) . '"', $m[1]);

            if (preg_match('/target=["\']_blank["\']/i', $attr) && !preg_match('/\brel=/i', $attr)) {
                $attr .= ' rel="noopener"';
            }

            return '<a ' . trim($attr) . '>';
        }, $h);

        // Sima szöveg bekezdés nélkül → egy bekezdés.
        if ($h !== '' && !preg_match('/^\s*<(p|ul|ol)\b/i', $h)) {
            $h = wpautop($h);
            $h = wp_kses($h, self::ENGEDETT, ['http', 'https', 'mailto', 'tel']);
        }

        return trim($h);
    }

    /** @param array<string, mixed> $e tartalom-sor @return array<string, mixed> */
    private static function elem(array $e): array
    {
        $a     = (array) $e['adat'];
        $kulcs = (string) $e['kulcs'];
        $v     = self::html((string) ($a['valasz'] ?? ''));

        return [
            'kulcs'         => $kulcs,
            'kategoria'     => self::kategoria_nev((string) ($a['kategoria'] ?? '')),
            'kategoria_kulcs' => sanitize_title((string) ($a['kategoria'] ?? '')) ?: 'altalanos',
            'kerdes'        => SDH_Sales_Seo::sima(SDH_Sales::t((string) ($a['kerdes'] ?? $e['cim'] ?? $kulcs))),
            'valasz'        => $v,
            'valasz_szoveg' => SDH_Sales_Seo::sima($v),
            'anchor'        => self::anchor($kulcs),
            'url'           => SDH_Sales_Seo::oldal_url(SDH_Sales_Seo::utvonalak()['gyik'], self::anchor($kulcs)),
            'kapcsolodo'    => SDH_Sales_Seo::lista($a['kapcsolodo'] ?? []),
        ];
    }

    /** @return array<string, array<string, mixed>> kulcs → elem, a tartalom-tár sorrendjében */
    private static function mind(): array
    {
        if (self::$cache === null) {
            self::$cache = [];

            foreach (SDH_Sales_Tartalom::lista('gyik') as $e) {
                $el = self::elem($e);

                if ($el['kerdes'] !== '' && $el['valasz'] !== '') {
                    self::$cache[$el['kulcs']] = $el;
                }
            }
        }

        return self::$cache;
    }

    public static function urit(): void
    {
        self::$cache = null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function lista(?string $kategoria = null): array
    {
        $l = array_values(self::mind());

        if ($kategoria !== null && $kategoria !== '') {
            $k = self::hajtott($kategoria);
            $l = array_values(array_filter($l, static fn ($e) => self::hajtott($e['kategoria']) === $k || $e['kategoria_kulcs'] === sanitize_title($kategoria) || sanitize_title($e['kategoria']) === sanitize_title($kategoria)));
        }

        return $l;
    }

    /** @return array<string, mixed>|null */
    public static function egy(string $kulcs): ?array
    {
        return self::mind()[$kulcs] ?? null;
    }

    /**
     * Kategória megjelenő neve. A gyik.json `kategoria` mezője lehet kulcs („adatbiztonsag”) vagy név („Adatbiztonság”).
     * Kulcsnál: a `gyik.kategoriak` beállítás ({kulcs: név}), különben a beépített magyar nevek, különben nagy kezdőbetű.
     */
    public static function kategoria_nev(string $k): string
    {
        $k = trim($k);

        if ($k === '') {
            return __('Általános', 'sdh-sales');
        }

        if (preg_match('/^[a-z0-9_-]+$/', $k) !== 1) {
            return SDH_Sales::t($k);
        }

        $sajat = SDH_Sales::b('gyik.kategoriak', []);

        if (is_array($sajat) && !empty($sajat[$k])) {
            return SDH_Sales::t((string) $sajat[$k]);
        }

        $nevek = [
            'altalanos'         => __('Általános', 'sdh-sales'),
            'vasarlas'          => __('Vásárlás', 'sdh-sales'),
            'arak'              => __('Árak', 'sdh-sales'),
            'licenc'            => __('Licenc', 'sdh-sales'),
            'telepites'         => __('Telepítés', 'sdh-sales'),
            'adatbiztonsag'     => __('Adatbiztonság', 'sdh-sales'),
            'adatatvetel'       => __('Adatátvétel', 'sdh-sales'),
            'modulok'           => __('Modulok', 'sdh-sales'),
            'iparagak'          => __('Iparágak', 'sdh-sales'),
            'tamogatas'         => __('Támogatás', 'sdh-sales'),
            'technikai'         => __('Technikai kérdések', 'sdh-sales'),
            'szerver'           => __('Szerver és tárhely', 'sdh-sales'),
            'offline'           => __('Offline működés', 'sdh-sales'),
            'integraciok'       => __('Integrációk', 'sdh-sales'),
            'egyedi-fejlesztes' => __('Egyedi fejlesztés', 'sdh-sales'),
            'demo'              => __('Demó', 'sdh-sales'),
            'fizetes'           => __('Fizetés', 'sdh-sales'),
            'szamlazas'         => __('Számlázás', 'sdh-sales'),
            'adatok'            => __('Adatok', 'sdh-sales'),
        ];

        return (string) apply_filters('sdh_sales_gyik_kategoria', $nevek[$k] ?? mb_convert_case(str_replace(['-', '_'], ' ', $k), MB_CASE_TITLE, 'UTF-8'), $k);
    }

    /** @return array<int, string> a kategóriák első előfordulásuk sorrendjében */
    public static function kategoriak(): array
    {
        return array_values(array_unique(array_column(self::lista(), 'kategoria')));
    }

    /** @return array<int, array{kategoria: string, slug: string, anchor: string, elemek: array<int, array<string, mixed>>}> */
    public static function lista_kategoriaval(): array
    {
        $ki = [];

        foreach (self::lista() as $e) {
            $k = $e['kategoria'];

            if (!isset($ki[$k])) {
                $slug   = sanitize_title(remove_accents($k));
                $ki[$k] = ['kategoria' => $k, 'kulcs' => $e['kategoria_kulcs'], 'slug' => $slug, 'anchor' => 'gyik-kat-' . $slug, 'elemek' => []];
            }

            $ki[$k]['elemek'][] = $e;
        }

        return array_values($ki);
    }

    /**
     * Egy `gyik` szekció kérdései. Szekció-mezők: `kulcsok` (lista – ebben a sorrendben), `kategoria` vagy `kategoriak`,
     * `max` (darab). Üres szekció = minden kérdés. A visszaadott kérdéseket megjelentként jelöli (FAQPage).
     *
     * @param array<string, mixed> $sz
     * @return array<int, array<string, mixed>>
     */
    public static function szekcio(array $sz, bool $jelol = true): array
    {
        $kulcsok = SDH_Sales_Seo::lista($sz['kulcsok'] ?? []);
        $kat     = SDH_Sales_Seo::lista($sz['kategoriak'] ?? ($sz['kategoria'] ?? []));

        if ($kulcsok !== []) {
            $l = array_values(array_filter(array_map([self::class, 'egy'], $kulcsok)));
        } elseif ($kat !== []) {
            $l = [];

            foreach ($kat as $k) {
                $l = array_merge($l, self::lista($k));
            }
        } else {
            $l = self::lista();
        }

        $max = (int) ($sz['max'] ?? 0);

        if ($max > 0) {
            $l = array_slice($l, 0, $max);
        }

        if ($jelol) {
            foreach ($l as $e) {
                self::megjelent((string) $e['kulcs']);
            }
        }

        return $l;
    }

    public static function megjelent(string $kulcs): void
    {
        if (self::egy($kulcs) !== null) {
            self::$megjelent[$kulcs] = true;
        }
    }

    /** @return array<int, string> */
    public static function megjelentek(): array
    {
        return array_keys(self::$megjelent);
    }

    public static function megjelent_urit(): void
    {
        self::$megjelent = [];
    }

    /** @return array<string, mixed>|null FAQPage a megjelent kérdésekből (null, ha nincs) */
    public static function faqpage(): ?array
    {
        if (self::$megjelent === [] || !(bool) SDH_Sales::b('seo.faq_schema', true)) {
            return null;
        }

        $url = is_singular() ? (string) get_permalink(get_queried_object_id()) : home_url(add_query_arg([]));
        $q   = [];

        foreach (array_keys(self::$megjelent) as $k) {
            $e = self::egy($k);

            if ($e === null) {
                continue;
            }

            $q[] = [
                '@type'          => 'Question',
                '@id'            => $url . '#' . $e['anchor'],
                'name'           => $e['kerdes'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $e['valasz']],
            ];
        }

        if ($q === []) {
            return null;
        }

        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', '@id' => $url . '#faq', 'url' => $url, 'inLanguage' => 'hu-HU', 'mainEntity' => $q];
    }

    public static function faq_kiir(): void
    {
        $f = self::faqpage();

        if ($f !== null) {
            echo SDH_Sales_Seo::ld_html($f); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON_HEX_TAG
        }
    }

    /** Ékezet nélküli, kisbetűs alak (összehasonlításhoz). */
    private static function hajtott(string $s): string
    {
        return strtolower(remove_accents(trim($s)));
    }
}
