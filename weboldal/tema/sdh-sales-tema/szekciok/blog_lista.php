<?php
/**
 * Szekció: blog_lista – a legutóbbi cikkek (WP_Query). Mezők: cim, alcim, db (alap 3), kategoria (név vagy slug), gomb.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$db  = max(1, min(24, (int) ($sz['db'] ?? 3) ?: 3));
$arg = ['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $db, 'ignore_sticky_posts' => true, 'no_found_rows' => true];
$kat = sdh_tema_mezo($sz, 'kategoria');

if ($kat !== '') {
    $t = get_term_by('slug', sanitize_title($kat), 'category') ?: get_term_by('name', $kat, 'category');

    if ($t instanceof WP_Term) {
        $arg['cat'] = (int) $t->term_id;
    }
}

if (is_singular('post')) {
    $arg['post__not_in'] = [(int) get_queried_object_id()];
}

$q = new WP_Query($arg);

if (!$q->have_posts()) {
    echo sdh_tema_admin_megjegyzes('blog_lista: nincs cikk'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<ul class="s-racs s-racs--3" role="list">';

while ($q->have_posts()) {
    $q->the_post();
    get_template_part('sablonok/kartya', 'cikk', ['szint' => 'h3']);
}

wp_reset_postdata();

echo '</ul>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
