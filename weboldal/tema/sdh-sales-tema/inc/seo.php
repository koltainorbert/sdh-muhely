<?php
/**
 * SDH Értékesítés téma – meta-tartalék: description + OG + canonical, csak ha sem a Rank Math, sem a bővítmény
 * SEO-modulja (SDH_Sales_Seo) nem írja ki (ARCHITEKTURA.md §8, §11). Forrás: `_sdh_seo_title` / `_sdh_seo_description`
 * post meta, különben a kivonat; OG-kép: kiemelt kép → assets/kepek „og”.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Más (Rank Math vagy a bővítmény SEO-modulja) írja-e a meta-adatokat. */
function sdh_tema_seo_mas(): bool
{
    return class_exists('SDH_Sales_Seo') || defined('RANK_MATH_VERSION') || class_exists('RankMath');
}

/** Cím-felülírás a `_sdh_seo_title`-ből (csak tartalékként). @param string $cim */
function sdh_tema_seo_cim($cim)
{
    if (sdh_tema_seo_mas() || !is_singular()) {
        return $cim;
    }

    $t = trim((string) get_post_meta((int) get_queried_object_id(), '_sdh_seo_title', true));

    return $t !== '' ? sdh_tema_t($t) : $cim;
}
add_filter('pre_get_document_title', 'sdh_tema_seo_cim', 15);

function sdh_tema_seo_leiras(): string
{
    if (is_singular()) {
        $id = (int) get_queried_object_id();
        $l  = trim((string) get_post_meta($id, '_sdh_seo_description', true));

        if ($l === '') {
            $p = get_post($id);
            $l = $p instanceof WP_Post ? (has_excerpt($p) ? (string) $p->post_excerpt : wp_trim_words(wp_strip_all_tags(strip_shortcodes((string) $p->post_content)), 28, '…')) : '';
        }

        return sdh_tema_t(trim($l));
    }

    if (is_front_page() || is_home()) {
        return sdh_tema_t((string) (sdh_tema_b('szlogen', '') ?: get_bloginfo('description')));
    }

    if (is_category() || is_tag()) {
        return trim(wp_strip_all_tags((string) term_description()));
    }

    return '';
}

function sdh_tema_seo_kep(): string
{
    if (is_singular() && has_post_thumbnail()) {
        $u = wp_get_attachment_image_url((int) get_post_thumbnail_id(), 'large');

        if ($u) {
            return (string) $u;
        }
    }

    [, $src] = sdh_tema_kep_adat('og');

    return $src;
}

function sdh_tema_seo_kiir(): void
{
    if (sdh_tema_seo_mas() || is_404()) {
        return;
    }

    $leiras = sdh_tema_seo_leiras();
    $kep    = sdh_tema_seo_kep();
    $url    = is_singular() ? (string) get_permalink() : (is_front_page() ? home_url('/') : '');
    $cim    = wp_get_document_title();

    if ($leiras !== '') {
        echo '<meta name="description" content="' . esc_attr(wp_html_excerpt($leiras, 300, '…')) . '">' . "\n";
    }

    $og = [
        'og:locale'      => 'hu_HU',
        'og:type'        => is_singular('post') ? 'article' : 'website',
        'og:site_name'   => sdh_tema_termeknev(),
        'og:title'       => $cim,
        'og:description' => $leiras,
        'og:url'         => $url,
        'og:image'       => $kep,
    ];

    foreach ($og as $k => $v) {
        if ($v !== '') {
            echo '<meta property="' . esc_attr($k) . '" content="' . esc_attr($v) . '">' . "\n";
        }
    }

    echo '<meta name="twitter:card" content="' . ($kep !== '' ? 'summary_large_image' : 'summary') . '">' . "\n";
}
add_action('wp_head', 'sdh_tema_seo_kiir', 1);

/** Témaszín a böngésző-UI-nak (mindkét módra). */
function sdh_tema_theme_color(): void
{
    echo '<meta name="theme-color" content="#07080c" media="(prefers-color-scheme: dark)">' . "\n";
    echo '<meta name="theme-color" content="#f4f5f9" media="(prefers-color-scheme: light)">' . "\n";
}
add_action('wp_head', 'sdh_tema_theme_color', 3);
