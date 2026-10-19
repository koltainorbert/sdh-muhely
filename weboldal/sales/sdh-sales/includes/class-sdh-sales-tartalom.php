<?php
/**
 * SDH Értékesítés – tartalom-tár: típusos elemek (csomag, modul, gyik, iparág, lecke, e-mail, menü…) a `tartalom` táblában,
 * oldalak szekciói (post meta `_sdh_szekciok`), és a `data/` mappa kezdőtartalmának betöltése (csak a hiányzót).
 *
 * API (mindenki ezt használja – a tábla közvetlen írása tilos):
 *   lista(tipus, csak_aktiv = true, nyelv = 'hu')  → [ ['kulcs','cim','adat'(tömb),'sorrend','aktiv'], … ] sorrend szerint
 *   egy(tipus, kulcs, nyelv = 'hu')               → ugyanilyen tömb vagy null
 *   ment(tipus, kulcs, adat, cim?, sorrend?, aktiv?, nyelv = 'hu', szerkesztve = true) → id (naplózva, visszaállítható)
 *   torol(tipus, kulcs, nyelv = 'hu')             → bool (naplózva)
 *   szekciok(post_id) / szekciok_ment(post_id, tomb)
 *   menu('fo'|'lablec'|'jogi')                    → [ {cim, url, gyerekek[]} ]
 *   betolt(kenyszer = false)                       → a data/ tartalma (hiányzó elemek; kenyszer: a nem szerkesztetteket is frissíti)
 *   md_html(markdown)                              → biztonságos HTML (a blog / jogi / tudásbázis szövegeihez)
 * Hook: `sdh_sales_tartalom_mentve` (tipus, kulcs, nyelv) – minden mentés és törlés után.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Tartalom
{
    public const META = '_sdh_szekciok';
    public const META_FORRAS = '_sdh_forras';

    public static function init(): void
    {
        add_action('sdh_sales_aktivalas', [self::class, 'betolt_aktivalaskor']);
        add_filter('sdh_sales_visszaallit_tartalom', [self::class, 'visszaallit'], 10, 3);
        add_filter('sdh_sales_visszaallit_szekciok', [self::class, 'visszaallit_szekciok'], 10, 3);
        add_action('init', [self::class, 'meta_regisztral']);
    }

    public static function meta_regisztral(): void
    {
        register_post_meta('page', self::META, ['type' => 'string', 'single' => true, 'show_in_rest' => false, 'auth_callback' => static fn (): bool => SDH_Sales::admin()]);
    }

    /* =================================================================
     * Típusos elemek
     * ============================================================== */

    /** @param array<string, mixed> $s */
    private static function sor(array $s): array
    {
        $a = json_decode((string) ($s['adat'] ?? ''), true);

        return [
            'id'          => (int) $s['id'],
            'kulcs'       => (string) $s['kulcs'],
            'cim'         => (string) $s['cim'],
            'adat'        => is_array($a) ? $a : [],
            'sorrend'     => (int) $s['sorrend'],
            'aktiv'       => (bool) (int) $s['aktiv'],
            'szerkesztve' => (bool) (int) $s['szerkesztve'],
            'modositva'   => (string) $s['modositva'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function lista(string $tipus, bool $csak_aktiv = true, string $nyelv = 'hu'): array
    {
        global $wpdb;

        $kulcs = 'l:' . $tipus . ':' . (int) $csak_aktiv . ':' . $nyelv;
        $c     = wp_cache_get($kulcs, 'sdh_sales_tartalom');

        if (is_array($c)) {
            return $c;
        }

        $sql = 'SELECT * FROM ' . SDH_Sales_Schema::t('tartalom') . ' WHERE tipus = %s AND nyelv = %s' . ($csak_aktiv ? ' AND aktiv = 1' : '') . ' ORDER BY sorrend ASC, id ASC';
        $ki  = array_map([self::class, 'sor'], (array) $wpdb->get_results($wpdb->prepare($sql, $tipus, $nyelv), ARRAY_A));

        wp_cache_set($kulcs, $ki, 'sdh_sales_tartalom', 300);

        return $ki;
    }

    /** @return array<string, mixed>|null */
    public static function egy(string $tipus, string $kulcs, string $nyelv = 'hu'): ?array
    {
        global $wpdb;

        $s = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('tartalom') . ' WHERE tipus = %s AND kulcs = %s AND nyelv = %s', $tipus, $kulcs, $nyelv), ARRAY_A);

        return is_array($s) ? self::sor($s) : null;
    }

    /** @param array<string, mixed> $adat */
    public static function ment(string $tipus, string $kulcs, array $adat, ?string $cim = null, ?int $sorrend = null, ?bool $aktiv = null, string $nyelv = 'hu', bool $szerkesztve = true): int
    {
        global $wpdb;

        $tipus = substr(sanitize_key($tipus), 0, 30);
        $kulcs = mb_substr(trim($kulcs), 0, 120);

        if ($tipus === '' || $kulcs === '') {
            return 0;
        }

        $regi = self::egy($tipus, $kulcs, $nyelv);
        $sor  = [
            'tipus'       => $tipus,
            'kulcs'       => $kulcs,
            'nyelv'       => substr(sanitize_key($nyelv), 0, 8) ?: 'hu',
            'cim'         => mb_substr($cim ?? (string) ($adat['cim'] ?? $adat['nev'] ?? $adat['kerdes'] ?? ($regi['cim'] ?? $kulcs)), 0, 255),
            'adat'        => (string) wp_json_encode($adat, JSON_UNESCAPED_UNICODE),
            'sorrend'     => $sorrend ?? (int) ($regi['sorrend'] ?? 0),
            'aktiv'       => (int) ($aktiv ?? ($regi['aktiv'] ?? true)),
            'szerkesztve' => (int) ($szerkesztve || !empty($regi['szerkesztve'])),
            'modositva'   => current_time('mysql', true),
        ];

        if ($regi !== null) {
            $wpdb->update(SDH_Sales_Schema::t('tartalom'), $sor, ['id' => (int) $regi['id']]);
            $id = (int) $regi['id'];
        } else {
            $wpdb->insert(SDH_Sales_Schema::t('tartalom'), $sor);
            $id = (int) $wpdb->insert_id;
        }

        self::urit();
        do_action('sdh_sales_tartalom_mentve', $tipus, $kulcs, (string) $sor['nyelv']);

        if ($szerkesztve) {
            SDH_Sales_Naplo::ir('tartalom', ($regi ? 'Módosítva' : 'Új') . ': ' . $tipus . ' / ' . $kulcs, 'info', 'tartalom', $id, $regi, ['tipus' => $tipus, 'kulcs' => $kulcs, 'nyelv' => $sor['nyelv'], 'cim' => $sor['cim'], 'adat' => $adat, 'sorrend' => $sor['sorrend'], 'aktiv' => (bool) $sor['aktiv']]);
        }

        return $id;
    }

    public static function torol(string $tipus, string $kulcs, string $nyelv = 'hu'): bool
    {
        global $wpdb;

        $regi = self::egy($tipus, $kulcs, $nyelv);

        if ($regi === null) {
            return false;
        }

        $wpdb->delete(SDH_Sales_Schema::t('tartalom'), ['id' => (int) $regi['id']]);
        self::urit();
        do_action('sdh_sales_tartalom_mentve', $tipus, $kulcs, $nyelv);
        SDH_Sales_Naplo::ir('tartalom', 'Törölve: ' . $tipus . ' / ' . $kulcs, 'figyel', 'tartalom', (int) $regi['id'], $regi + ['tipus' => $tipus, 'nyelv' => $nyelv], null);

        return true;
    }

    public static function urit(): void
    {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('sdh_sales_tartalom');
        } else {
            wp_cache_flush();
        }
    }

    /**
     * Visszaállítás egy naplóbejegyzés előtti állapotra (a naplóból hívva).
     *
     * @param mixed $elotte
     */
    public static function visszaallit(bool $ok, int $id, $elotte): bool
    {
        global $wpdb;

        if (!is_array($elotte) || empty($elotte['kulcs'])) {
            // Új elem volt: visszaállítás = törlés.
            $s = $wpdb->get_row($wpdb->prepare('SELECT tipus, kulcs, nyelv FROM ' . SDH_Sales_Schema::t('tartalom') . ' WHERE id = %d', $id), ARRAY_A);

            return is_array($s) && self::torol((string) $s['tipus'], (string) $s['kulcs'], (string) $s['nyelv']);
        }

        $tipus = (string) ($elotte['tipus'] ?? '');

        if ($tipus === '') {
            $tipus = (string) $wpdb->get_var($wpdb->prepare('SELECT tipus FROM ' . SDH_Sales_Schema::t('tartalom') . ' WHERE id = %d', $id));
        }

        if ($tipus === '') {
            return false;
        }

        return self::ment($tipus, (string) $elotte['kulcs'], (array) ($elotte['adat'] ?? []), (string) ($elotte['cim'] ?? ''), (int) ($elotte['sorrend'] ?? 0), (bool) ($elotte['aktiv'] ?? true), (string) ($elotte['nyelv'] ?? 'hu')) > 0;
    }

    /* =================================================================
     * Oldalak szekciói
     * ============================================================== */

    /** @return array<int, array<string, mixed>> */
    public static function szekciok(int $post_id): array
    {
        $j = json_decode((string) get_post_meta($post_id, self::META, true), true);

        return is_array($j) ? array_values(array_filter($j, 'is_array')) : [];
    }

    /** @param array<int, array<string, mixed>> $szekciok */
    public static function szekciok_ment(int $post_id, array $szekciok, bool $naplo = true): void
    {
        $regi = self::szekciok($post_id);
        $uj   = array_values(array_filter($szekciok, static fn ($s) => is_array($s) && !empty($s['tipus'])));

        update_post_meta($post_id, self::META, wp_slash((string) wp_json_encode($uj, JSON_UNESCAPED_UNICODE)));

        if ($naplo && $regi !== $uj) {
            SDH_Sales_Naplo::ir('oldal', 'Oldal szekciói mentve: ' . get_the_title($post_id), 'info', 'szekciok', $post_id, $regi, $uj);
        }
    }

    /** @param mixed $elotte */
    public static function visszaallit_szekciok(bool $ok, int $post_id, $elotte): bool
    {
        if (!is_array($elotte) || get_post($post_id) === null) {
            return false;
        }

        self::szekciok_ment($post_id, $elotte, false);

        return true;
    }

    /* =================================================================
     * Menü
     * ============================================================== */

    /** @return array<int, array{cim: string, url: string, gyerekek: array}> */
    public static function menu(string $hely): array
    {
        $m = self::egy('menu', $hely);
        $e = is_array($m['adat']['elemek'] ?? null) ? $m['adat']['elemek'] : [];

        $fel = static function (array $elemek) use (&$fel): array {
            $ki = [];

            foreach ($elemek as $e) {
                if (!is_array($e) || empty($e['cim'])) {
                    continue;
                }

                $url = (string) ($e['url'] ?? '');

                if ($url !== '' && $url[0] === '/') {
                    $url = home_url($url);
                } elseif (!empty($e['oldal'])) {
                    $p   = get_page_by_path((string) $e['oldal']);
                    $url = $p ? (string) get_permalink($p) : home_url('/' . trim((string) $e['oldal'], '/') . '/');
                }

                $ki[] = ['cim' => SDH_Sales::t((string) $e['cim']), 'url' => $url, 'gyerekek' => $fel((array) ($e['gyerekek'] ?? []))];
            }

            return $ki;
        };

        return $fel($e);
    }

    /* =================================================================
     * Kezdőtartalom betöltése a data/ mappából
     * ============================================================== */

    public static function betolt_aktivalaskor(): void
    {
        self::betolt(false);
    }

    /**
     * @return array<string, int> mennyi elem került be típusonként
     */
    public static function betolt(bool $kenyszer = false): array
    {
        $db = [];

        $tipusos = static function (string $tipus, array $elemek, string $kulcsmezo = 'kulcs') use (&$db, $kenyszer): void {
            foreach (array_values($elemek) as $i => $e) {
                if (!is_array($e) || empty($e[$kulcsmezo])) {
                    continue;
                }

                $k    = (string) $e[$kulcsmezo];
                $regi = self::egy($tipus, $k);

                if ($regi !== null && (!$kenyszer || $regi['szerkesztve'])) {
                    continue;
                }

                self::ment($tipus, $k, $e, null, (int) ($e['sorrend'] ?? $i), $regi['aktiv'] ?? true, 'hu', false);
                $db[$tipus] = ($db[$tipus] ?? 0) + 1;
            }
        };

        $arak = SDH_Sales::adat('arak.json');

        if (is_array($arak)) {
            $tipusos('csomag', (array) ($arak['csomagok'] ?? []));
            $tipusos('modul', (array) ($arak['modulok'] ?? []));
            $tipusos('szolgaltatas', (array) ($arak['szolgaltatasok'] ?? []));
            $tipusos('kupon', (array) ($arak['kuponok'] ?? []), 'kod');
        }

        foreach (['gyik' => 'gyik.json', 'funkcio' => 'funkciok.json', 'iparag' => 'iparagak.json', 'lecke' => 'oktatas.json', 'telepites' => 'telepites.json', 'menu' => 'menuk.json', 'konkurens' => 'konkurensek.json'] as $tipus => $fajl) {
            $a = SDH_Sales::adat($fajl);

            if (is_array($a)) {
                $tipusos($tipus, isset($a['elemek']) && is_array($a['elemek']) ? $a['elemek'] : $a);
            }
        }

        foreach (glob(SDH_SALES_DIR . 'data/email/*.json') ?: [] as $f) {
            $a = json_decode((string) file_get_contents($f), true);

            if (is_array($a)) {
                $a['kulcs'] = basename($f, '.json');
                $tipusos('email', [$a]);
            }
        }

        $db['oldal'] = self::oldalak_betolt($kenyszer);
        $db['jogi']  = self::md_oldalak_betolt('jogi', $kenyszer);
        $db['blog']  = self::blog_betolt($kenyszer);

        do_action('sdh_sales_tartalom_betoltve', $db, $kenyszer);
        self::urit();

        return $db;
    }

    /** data/oldalak/<slug>.json → WP oldal + szekciók. A szülő oldalak előbb. */
    private static function oldalak_betolt(bool $kenyszer): int
    {
        $fajlok = glob(SDH_SALES_DIR . 'data/oldalak/*.json') ?: [];
        $oldal  = [];

        foreach ($fajlok as $f) {
            $a = json_decode((string) file_get_contents($f), true);

            if (is_array($a) && !empty($a['slug'])) {
                $oldal[] = $a;
            }
        }

        // Szülő nélküliek előre, a mélység szerint.
        usort($oldal, static fn ($x, $y) => substr_count((string) ($x['szulo'] ?? ''), '/') + (empty($x['szulo']) ? 0 : 1) <=> substr_count((string) ($y['szulo'] ?? ''), '/') + (empty($y['szulo']) ? 0 : 1));

        $n = 0;

        foreach ($oldal as $a) {
            $n += self::oldal_betolt($a, $kenyszer) ? 1 : 0;
        }

        $kezdo = get_page_by_path('kezdolap');

        if ($kezdo && (int) get_option('page_on_front') === 0) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', (int) $kezdo->ID);
        }

        $blog = get_page_by_path('blog');

        if ($blog && (int) get_option('page_for_posts') === 0) {
            update_option('page_for_posts', (int) $blog->ID);
        }

        return $n;
    }

    /** @param array<string, mixed> $a */
    public static function oldal_betolt(array $a, bool $kenyszer = false): bool
    {
        $slug   = sanitize_title((string) $a['slug']);
        $szulo  = !empty($a['szulo']) ? get_page_by_path((string) $a['szulo']) : null;
        $ut     = ($szulo ? get_page_uri($szulo) . '/' : '') . $slug;
        $letezo = get_page_by_path($ut);

        if ($letezo && (!$kenyszer || get_post_meta($letezo->ID, '_sdh_szerkesztve', true))) {
            return false;
        }

        $postarr = [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => wp_strip_all_tags((string) ($a['cim'] ?? $slug)),
            'post_name'    => $slug,
            'post_parent'  => $szulo ? (int) $szulo->ID : 0,
            'post_content' => (string) ($a['tartalom'] ?? ''),
            'menu_order'   => (int) ($a['sorrend'] ?? 0),
        ];

        if ($letezo) {
            $postarr['ID'] = (int) $letezo->ID;
            $id = wp_update_post($postarr, true);
        } else {
            $id = wp_insert_post($postarr, true);
        }

        if (is_wp_error($id) || (int) $id === 0) {
            return false;
        }

        $id = (int) $id;
        self::szekciok_ment($id, (array) ($a['szekciok'] ?? []), false);
        update_post_meta($id, self::META_FORRAS, 'data/oldalak/' . $slug . '.json');

        if (!empty($a['sablon'])) {
            update_post_meta($id, '_wp_page_template', sanitize_file_name((string) $a['sablon']));
        }

        self::seo_meta($id, (array) ($a['seo'] ?? []));

        return true;
    }

    /** SEO-mezők: saját meta + Rank Math meta (ha a Rank Math be van kapcsolva, azt használja). @param array<string, mixed> $seo */
    public static function seo_meta(int $id, array $seo): void
    {
        if (!empty($seo['title'])) {
            update_post_meta($id, '_sdh_seo_title', (string) $seo['title']);
            update_post_meta($id, 'rank_math_title', (string) $seo['title']);
        }

        if (!empty($seo['description'])) {
            update_post_meta($id, '_sdh_seo_description', (string) $seo['description']);
            update_post_meta($id, 'rank_math_description', (string) $seo['description']);
        }

        if (!empty($seo['kulcsszo'])) {
            update_post_meta($id, 'rank_math_focus_keyword', (string) $seo['kulcsszo']);
        }
    }

    /** data/<mappa>/<slug>.md → WP oldal (jogi oldalak a „jogi” szülő alatt). */
    private static function md_oldalak_betolt(string $mappa, bool $kenyszer): int
    {
        $n = 0;

        foreach (glob(SDH_SALES_DIR . 'data/' . $mappa . '/*.md') ?: [] as $f) {
            [$fej, $md] = self::frontmatter((string) file_get_contents($f));
            $slug       = basename($f, '.md');

            $ok = self::oldal_betolt([
                'slug'     => $slug,
                'cim'      => (string) ($fej['cim'] ?? $slug),
                'szulo'    => (string) ($fej['szulo'] ?? ''),
                'sablon'   => (string) ($fej['sablon'] ?? ''),
                'tartalom' => self::md_html($md),
                'szekciok' => [['tipus' => 'jogi', 'verzio' => (string) ($fej['verzio'] ?? ''), 'hatalyos' => (string) ($fej['hatalyos'] ?? '')]],
                'seo'      => ['title' => (string) ($fej['cim'] ?? ''), 'description' => (string) ($fej['leiras'] ?? '')],
            ], $kenyszer);

            $n += $ok ? 1 : 0;
        }

        return $n;
    }

    /** data/blog/<slug>.md → bejegyzés (kategória, címkék, SEO). */
    private static function blog_betolt(bool $kenyszer): int
    {
        $n = 0;

        foreach (glob(SDH_SALES_DIR . 'data/blog/*.md') ?: [] as $f) {
            [$fej, $md] = self::frontmatter((string) file_get_contents($f));
            $slug       = basename($f, '.md');
            $letezo     = get_page_by_path($slug, OBJECT, 'post');

            if ($letezo && (!$kenyszer || get_post_meta($letezo->ID, '_sdh_szerkesztve', true))) {
                continue;
            }

            $kat = [];

            if (!empty($fej['kategoria'])) {
                $t = term_exists((string) $fej['kategoria'], 'category') ?: wp_insert_term((string) $fej['kategoria'], 'category');
                $kat[] = is_array($t) ? (int) $t['term_id'] : (int) $t;
            }

            $postarr = [
                'post_type'     => 'post',
                'post_status'   => 'publish',
                'post_title'    => wp_strip_all_tags((string) ($fej['cim'] ?? $slug)),
                'post_name'     => $slug,
                'post_excerpt'  => (string) ($fej['leiras'] ?? ''),
                'post_content'  => self::md_html($md),
                'post_category' => array_filter($kat),
                'tags_input'    => is_array($fej['cimkek'] ?? null) ? $fej['cimkek'] : array_filter(array_map('trim', explode(',', (string) ($fej['cimkek'] ?? '')))),
            ];

            if (!empty($fej['datum']) && strtotime((string) $fej['datum'])) {
                $postarr['post_date'] = gmdate('Y-m-d H:i:s', (int) strtotime((string) $fej['datum'] . ' 09:00:00'));
            }

            if ($letezo) {
                $postarr['ID'] = (int) $letezo->ID;
            }

            $id = $letezo ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);

            if (!is_wp_error($id) && (int) $id > 0) {
                self::seo_meta((int) $id, ['title' => (string) ($fej['seo_cim'] ?? $fej['cim'] ?? ''), 'description' => (string) ($fej['leiras'] ?? ''), 'kulcsszo' => (string) ($fej['kulcsszo'] ?? '')]);
                update_post_meta((int) $id, self::META_FORRAS, 'data/blog/' . $slug . '.md');
                $n++;
            }
        }

        return $n;
    }

    /**
     * Egyszerű YAML-frontmatter (kulcs: érték, [a, b] listák).
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function frontmatter(string $s): array
    {
        $s = str_replace("\r\n", "\n", $s);

        if (strncmp($s, "---\n", 4) !== 0) {
            return [[], $s];
        }

        $veg = strpos($s, "\n---", 4);

        if ($veg === false) {
            return [[], $s];
        }

        $fej = [];

        foreach (explode("\n", substr($s, 4, $veg - 4)) as $sor) {
            if (!preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $sor, $m)) {
                continue;
            }

            $v = trim($m[2]);

            if ($v !== '' && $v[0] === '[' && substr($v, -1) === ']') {
                $v = array_values(array_filter(array_map(static fn ($x) => trim(trim($x), '"\''), explode(',', substr($v, 1, -1))), 'strlen'));
            } else {
                $v = trim($v, '"\'');
            }

            $fej[$m[1]] = $v;
        }

        return [$fej, ltrim(substr($s, $veg + 4), "-\n")];
    }

    /**
     * Markdown → HTML (címsorok, bekezdés, listák, idézet, kód, táblázat, vonal, félkövér, dőlt, kód, link, kép).
     * A kimenet wp_kses_post-on megy át.
     */
    public static function md_html(string $md): string
    {
        $md    = str_replace("\r\n", "\n", $md);
        $sorok = explode("\n", $md);
        $ki    = [];
        $i     = 0;
        $n     = count($sorok);

        $inline = static function (string $t): string {
            $t = esc_html($t);
            $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t) ?? $t;
            $t = preg_replace('/!\[([^\]]*)\]\(([^)\s]+)\)/', '<img src="$2" alt="$1" loading="lazy">', $t) ?? $t;
            $t = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function ($m) {
                $u = html_entity_decode($m[2]);
                $kulso = preg_match('#^https?://#', $u) === 1 && strpos($u, (string) wp_parse_url(home_url(), PHP_URL_HOST)) === false;

                return '<a href="' . esc_url($u) . '"' . ($kulso ? ' rel="noopener" target="_blank"' : '') . '>' . $m[1] . '</a>';
            }, $t) ?? $t;
            $t = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t) ?? $t;
            $t = preg_replace('/(?<![*\w])\*([^*\n]+)\*(?![*\w])/', '<em>$1</em>', $t) ?? $t;

            return $t;
        };

        while ($i < $n) {
            $s = $sorok[$i];

            if (trim($s) === '') {
                $i++;
                continue;
            }

            if (preg_match('/^```/', $s)) {
                $kod = [];
                $i++;

                while ($i < $n && !preg_match('/^```/', $sorok[$i])) {
                    $kod[] = $sorok[$i];
                    $i++;
                }

                $i++;
                $ki[] = '<pre><code>' . esc_html(implode("\n", $kod)) . '</code></pre>';
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $s, $m)) {
                $szint = max(2, strlen($m[1]));
                $szov  = trim($m[2]);
                $ki[]  = '<h' . $szint . ' id="' . esc_attr(sanitize_title($szov)) . '">' . $inline($szov) . '</h' . $szint . '>';
                $i++;
                continue;
            }

            if (preg_match('/^(-{3,}|\*{3,})\s*$/', $s)) {
                $ki[] = '<hr>';
                $i++;
                continue;
            }

            if (strpos($s, '|') !== false && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{3,}/', $sorok[$i + 1])) {
                $cella = static fn (string $r): array => array_map('trim', explode('|', trim(trim($r), '|')));
                $fej   = $cella($s);
                $i    += 2;
                $t     = '<div class="tablazat"><table><thead><tr>' . implode('', array_map(static fn ($c) => '<th>' . $inline($c) . '</th>', $fej)) . '</tr></thead><tbody>';

                while ($i < $n && strpos($sorok[$i], '|') !== false && trim($sorok[$i]) !== '') {
                    $t .= '<tr>' . implode('', array_map(static fn ($c) => '<td>' . $inline($c) . '</td>', $cella($sorok[$i]))) . '</tr>';
                    $i++;
                }

                $ki[] = $t . '</tbody></table></div>';
                continue;
            }

            if (preg_match('/^>\s?/', $s)) {
                $b = [];

                while ($i < $n && preg_match('/^>\s?(.*)$/', $sorok[$i], $m)) {
                    $b[] = $m[1];
                    $i++;
                }

                $ki[] = '<blockquote><p>' . $inline(implode(' ', $b)) . '</p></blockquote>';
                continue;
            }

            if (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $s, $m)) {
                $rendezett = ctype_digit(substr(trim($m[1]), 0, 1));
                $elemek    = [];

                while ($i < $n && preg_match('/^\s*([-*+]|\d+[.)])\s+(.*)$/', $sorok[$i], $mm)) {
                    $elemek[] = '<li>' . $inline($mm[2]) . '</li>';
                    $i++;
                }

                $ki[] = ($rendezett ? '<ol>' : '<ul>') . implode('', $elemek) . ($rendezett ? '</ol>' : '</ul>');
                continue;
            }

            $p = [];

            while ($i < $n && trim($sorok[$i]) !== '' && !preg_match('/^(#{1,6}\s|```|>|\s*([-*+]|\d+[.)])\s+|(-{3,}|\*{3,})\s*$)/', $sorok[$i])) {
                $p[] = trim($sorok[$i]);
                $i++;
            }

            $ki[] = '<p>' . $inline(implode(' ', $p)) . '</p>';
        }

        return wp_kses_post(implode("\n", $ki));
    }
}
