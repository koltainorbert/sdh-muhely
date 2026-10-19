<?php
/**
 * Bloglista (a bejegyzések oldala) és archívumok közös sablonja: kategóriaszűrő pillek, kártyarács, lapozó.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$sdh_cim   = sdh_tema__('Blog');
$sdh_alcim = '';

if (is_home() && (int) get_option('page_for_posts') > 0) {
    $sdh_cim = sdh_tema_t(get_the_title((int) get_option('page_for_posts')));
} elseif (is_archive()) {
    $sdh_cim   = wp_strip_all_tags(get_the_archive_title());
    $sdh_alcim = wp_strip_all_tags(get_the_archive_description());
}

$sdh_katok = get_categories(['hide_empty' => true, 'number' => 12, 'orderby' => 'count', 'order' => 'DESC']);
$sdh_szuro = '';

if (count($sdh_katok) > 1) {
    $sdh_akt   = is_category() ? (int) get_queried_object_id() : 0;
    $sdh_blog  = (int) get_option('page_for_posts') > 0 ? (string) get_permalink((int) get_option('page_for_posts')) : home_url('/');
    $sdh_szuro = '<nav class="s-pillsor s-pillsor--szuro" aria-label="' . esc_attr(sdh_tema__('Kategóriák')) . '"><a class="s-pill s-pill--link" href="' . esc_url($sdh_blog) . '"' . ($sdh_akt === 0 ? ' aria-current="page"' : '') . '>' . esc_html(sdh_tema__('Mind')) . '</a>';

    foreach ($sdh_katok as $sdh_k) {
        $sdh_szuro .= '<a class="s-pill s-pill--link" href="' . esc_url((string) get_category_link($sdh_k)) . '"' . ($sdh_akt === (int) $sdh_k->term_id ? ' aria-current="page"' : '') . '>' . esc_html($sdh_k->name) . '</a>';
    }

    $sdh_szuro .= '</nav>';
}

echo sdh_tema_oldalfej($sdh_cim, $sdh_alcim, $sdh_szuro); // phpcs:ignore WordPress.Security.EscapeOutput
?>
<div class="s-szekcio s-hatter--alap">
	<div class="s-kont">
		<?php if (have_posts()) : ?>
			<ul class="s-racs s-racs--3" role="list">
				<?php
				while (have_posts()) {
					the_post();
					get_template_part('sablonok/kartya', 'cikk', ['szint' => 'h2']);
				}
				?>
			</ul>
			<?php
			the_posts_pagination([
				'mid_size'           => 1,
				'prev_text'          => esc_html(sdh_tema__('Előző')),
				'next_text'          => esc_html(sdh_tema__('Következő')),
				'screen_reader_text' => sdh_tema__('Lapozás'),
				'class'              => 's-lapozo',
			]);
			?>
		<?php else : ?>
			<p class="s-doboz s-doboz--info"><?php echo esc_html(sdh_tema__('Még nincs itt cikk.')); ?></p>
		<?php endif; ?>
	</div>
</div>
<?php
get_footer();
