<?php
/**
 * SDH Értékesítés téma – tudásbázis-fejezetek: /tudasbazis/<fejezet>/ (Szikra ide linkel a forrásaira).
 *
 * - Átírási szabály: `tudasbazis/([^/]+)/?$` → `sdh_tudas=<fejezet>`; ha ilyen útvonalú valódi WP-oldal van, az nyer.
 * - Adat: SDH_Sales_Szikra_Tudas::fejezet($kulcs) / ::fejezetek() (class_exists-szel védve – bővítmény nélkül 404).
 * - Ismeretlen fejezet → valódi 404. Ismert: tudasbazis.php sablon (TJ, morzsa, kapcsolódó leckék, előző / következő).
 * - A szabályok a téma bekapcsolásakor frissülnek (flush_rewrite_rules); verzióváltáskor egyszer újra.
 * - Meta: a dokumentumcím a fejezet címe; leírás + canonical csak ha sem a Rank Math, sem a SEO-modul nem írja.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

const SDH_TEMA_TUDAS_VAR = 'tudas_fejezet';

function sdh_tema_tudas_alap(): string
{
    return class_exists('SDH_Sales_Seo') ? (string) (SDH_Sales_Seo::utvonalak()['tudas'] ?? 'tudasbazis') : 'tudasbazis';
}

function sdh_tema_tudas_szabaly(): void
{
    $alap = preg_quote(trim(sdh_tema_tudas_alap(), '/'), '#');
    add_rewrite_rule('^' . $alap . '/([^/]+)/?$', 'index.php?' . SDH_TEMA_TUDAS_VAR . '=$matches[1]', 'top');
}
add_action('init', 'sdh_tema_tudas_szabaly');

/** @param array<int, string> $v @return array<int, string> */
function sdh_tema_tudas_qv(array $v): array
{
    $v[] = SDH_TEMA_TUDAS_VAR;

    return $v;
}
add_filter('query_vars', 'sdh_tema_tudas_qv');

/** Témaváltáskor és verzióváltáskor a szabályok frissítése. */
function sdh_tema_tudas_flush(): void
{
    sdh_tema_tudas_szabaly();
    flush_rewrite_rules(false);
    update_option('sdh_tema_rewrite', SDH_TEMA_VERSION, false);
}
add_action('after_switch_theme', 'sdh_tema_tudas_flush');

function sdh_tema_tudas_flush_ha_kell(): void
{
    if (get_option('sdh_tema_rewrite') !== SDH_TEMA_VERSION) {
        sdh_tema_tudas_flush();
    }
}
add_action('init', 'sdh_tema_tudas_flush_ha_kell', 99);

/**
 * Ha valódi oldal létezik ezen az úton (pl. a tartalom ügynök külön oldalt írt), az oldal nyer.
 *
 * @param array<string, mixed> $q
 * @return array<string, mixed>
 */
function sdh_tema_tudas_keres(array $q): array
{
    if (empty($q[SDH_TEMA_TUDAS_VAR])) {
        return $q;
    }

    $kulcs = sanitize_title((string) $q[SDH_TEMA_TUDAS_VAR]);
    $ut    = trim(sdh_tema_tudas_alap(), '/') . '/' . $kulcs;

    if (get_page_by_path($ut) instanceof WP_Post) {
        return ['pagename' => $ut];
    }

    return [SDH_TEMA_TUDAS_VAR => $kulcs];
}
add_filter('request', 'sdh_tema_tudas_keres');

/** Az aktuális kérés fejezete (kérésenként egyszer). @return array<string, mixed>|null */
function sdh_tema_tudas_fejezet(): ?array
{
    static $f = false;

    if ($f !== false) {
        return $f;
    }

    $f     = null;
    $kulcs = sanitize_title((string) get_query_var(SDH_TEMA_TUDAS_VAR, ''));

    if ($kulcs !== '' && class_exists('SDH_Sales_Szikra_Tudas')) {
        try {
            $f = SDH_Sales_Szikra_Tudas::fejezet($kulcs);
        } catch (Throwable $e) {
            $f = null;
        }
    }

    return $f;
}

function sdh_tema_tudas_e(): bool
{
    return get_query_var(SDH_TEMA_TUDAS_VAR, '') !== '';
}

/** A főlekérdezés ne kérjen bejegyzéseket, és ne legyen „blog-kezdőlap”. */
function sdh_tema_tudas_lekerdezes(WP_Query $q): void
{
    if (!$q->is_main_query() || $q->get(SDH_TEMA_TUDAS_VAR) === '') {
        return;
    }

    $q->is_home     = false;
    $q->is_archive  = false;
    $q->is_singular = false;
    $q->is_page     = false;
    $q->set('posts_per_page', 1);
    $q->set('no_found_rows', true);
}
add_action('parse_query', 'sdh_tema_tudas_lekerdezes');

/** @param mixed $posts @return mixed */
function sdh_tema_tudas_nincs_post($posts, WP_Query $q)
{
    return $q->is_main_query() && $q->get(SDH_TEMA_TUDAS_VAR) !== '' ? [] : $posts;
}
add_filter('posts_pre_query', 'sdh_tema_tudas_nincs_post', 10, 2);

/** Ismert fejezet: nem 404; ismeretlen: 404 (a WP alapkezelése). */
function sdh_tema_tudas_404(bool $kezelve, WP_Query $q): bool
{
    if (!sdh_tema_tudas_e()) {
        return $kezelve;
    }

    if (sdh_tema_tudas_fejezet() !== null) {
        status_header(200);

        return true;
    }

    $q->set_404();
    status_header(404);
    nocache_headers();

    return true;
}
add_filter('pre_handle_404', 'sdh_tema_tudas_404', 10, 2);

function sdh_tema_tudas_sablon(string $sablon): string
{
    if (sdh_tema_tudas_e() && sdh_tema_tudas_fejezet() !== null) {
        $t = locate_template('tudasbazis.php');

        return $t !== '' ? $t : $sablon;
    }

    return $sablon;
}
add_filter('template_include', 'sdh_tema_tudas_sablon');

/** @param mixed $cim @return mixed */
function sdh_tema_tudas_cim($cim)
{
    $f = sdh_tema_tudas_e() ? sdh_tema_tudas_fejezet() : null;

    if ($f === null) {
        return $cim;
    }

    return wp_strip_all_tags((string) $f['cim']) . ' – ' . sdh_tema__('Tudásbázis') . ' – ' . sdh_tema_termeknev();
}
add_filter('pre_get_document_title', 'sdh_tema_tudas_cim', 40);

function sdh_tema_tudas_fejezet_url(string $kulcs): string
{
    if (class_exists('SDH_Sales_Szikra_Tudas')) {
        return SDH_Sales_Szikra_Tudas::url($kulcs);
    }

    return home_url('/' . trim(sdh_tema_tudas_alap(), '/') . '/' . rawurlencode($kulcs) . '/');
}

/**
 * Morzsa a bővítmény SEO-moduljának (BreadcrumbList is ebből lesz).
 *
 * @param array<int, array{cim: string, url: string}> $m
 * @return array<int, array{cim: string, url: string}>
 */
function sdh_tema_tudas_morzsa_szuro(array $m): array
{
    $f = sdh_tema_tudas_e() ? sdh_tema_tudas_fejezet() : null;

    return $f === null ? $m : sdh_tema_tudas_morzsa($f);
}
add_filter('sdh_sales_morzsa', 'sdh_tema_tudas_morzsa_szuro');

/** @param array<string, mixed> $f @return array<int, array{cim: string, url: string}> */
function sdh_tema_tudas_morzsa(array $f): array
{
    $alap = trim(sdh_tema_tudas_alap(), '/');
    $p    = get_page_by_path($alap);

    return [
        ['cim' => sdh_tema__('Főoldal'), 'url' => home_url('/')],
        ['cim' => $p instanceof WP_Post ? sdh_tema_t(get_the_title($p)) : sdh_tema__('Tudásbázis'), 'url' => $p instanceof WP_Post ? (string) get_permalink($p) : home_url('/' . $alap . '/')],
        ['cim' => sdh_tema_t((string) $f['cim']), 'url' => sdh_tema_tudas_fejezet_url((string) $f['kulcs'])],
    ];
}

/** Leírás + canonical + OG, ha más nem írja (a SEO-modul ezen az úton nem talál oldalt, így nem ír leírást). */
function sdh_tema_tudas_meta(): void
{
    $f = sdh_tema_tudas_e() ? sdh_tema_tudas_fejezet() : null;

    if ($f === null || defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
        return;
    }

    $url = sdh_tema_tudas_fejezet_url((string) $f['kulcs']);
    $l   = wp_html_excerpt(wp_strip_all_tags(sdh_tema_t((string) ($f['osszefoglalo'] ?? ''))), 300, '…');

    if ($l !== '') {
        echo '<meta name="description" content="' . esc_attr($l) . '">' . "\n";
    }

    if (!class_exists('SDH_Sales_Seo') || SDH_Sales_Seo::kanonikus() === '') {
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
    }
}
add_action('wp_head', 'sdh_tema_tudas_meta', 4);

/**
 * A fejezethez kapcsolódó leckék (a lecke `modul`-ja = a fejezet kulcsa, vagy a fejezet címe a lecke címében).
 *
 * @param array<string, mixed> $f
 * @return array<int, array{cim: string, url: string, leiras: string}>
 */
function sdh_tema_tudas_leckek(array $f, int $max = 4): array
{
    if (!class_exists('SDH_Sales_Oktatas')) {
        return [];
    }

    try {
        $leckek = SDH_Sales_Oktatas::leckek();
    } catch (Throwable $e) {
        return [];
    }

    $k  = (string) $f['kulcs'];
    $kv = [$k, str_replace('-', '', $k), rtrim($k, 'ok'), rtrim($k, 'ek')];
    $ki = [];

    foreach ($leckek as $l) {
        $m = (string) ($l['modul'] ?? '');

        if ($m === '' || !in_array($m, $kv, true) && strpos($k, $m) !== 0) {
            continue;
        }

        $url  = class_exists('SDH_Sales_Seo') ? SDH_Sales_Seo::url('lecke', (string) $l['kulcs']) : '';
        $ki[] = ['cim' => (string) $l['cim'], 'url' => $url, 'leiras' => (string) ($l['cel'] ?? ''), 'perc' => (int) ($l['ido_perc'] ?? 0)];

        if (count($ki) >= $max) {
            break;
        }
    }

    return $ki;
}
