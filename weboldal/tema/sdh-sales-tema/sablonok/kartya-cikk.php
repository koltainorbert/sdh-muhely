<?php
/**
 * Cikk-kártya (a Loop-on belül). $args['szint']: a cím szintje (alap h2).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $args
 */

if (!defined('ABSPATH')) {
    exit;
}

$sdh_szint = in_array($args['szint'] ?? 'h2', ['h2', 'h3'], true) ? (string) $args['szint'] : 'h2';
$sdh_kat   = get_the_category();
$sdh_perc  = sdh_tema_olvasasi_ido((string) get_post_field('post_content', get_the_ID()));
?>
<li class="s-kartya s-kartya--cikk s-kartya--katt">
	<?php if (has_post_thumbnail()) : ?>
		<div class="s-kartya__media"><?php the_post_thumbnail('kartya', ['class' => 's-kartya__kep', 'alt' => '', 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '(min-width: 1100px) 380px, (min-width: 640px) 50vw, 100vw']); ?></div>
	<?php endif; ?>
	<p class="s-kartya__meta">
		<?php if ($sdh_kat !== []) : ?>
			<span class="s-pill s-pill--kicsi"><?php echo esc_html($sdh_kat[0]->name); ?></span>
		<?php endif; ?>
		<time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('Y. F j.')); ?></time>
		<span aria-hidden="true">·</span>
		<span><?php echo esc_html(sprintf(/* translators: %d: perc */ sdh_tema__('%d perc olvasás'), $sdh_perc)); ?></span>
	</p>
	<<?php echo $sdh_szint; // phpcs:ignore WordPress.Security.EscapeOutput -- fehérlistás ?> class="s-kartya__cim"><a class="s-kartya__link" href="<?php the_permalink(); ?>"><?php echo esc_html(sdh_tema_t(wp_strip_all_tags(get_the_title()))); ?></a></<?php echo $sdh_szint; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<p class="s-kartya__szoveg"><?php echo esc_html(sdh_tema_t(wp_strip_all_tags(get_the_excerpt()))); ?></p>
</li>
