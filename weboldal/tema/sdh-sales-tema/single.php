<?php
/**
 * Blogcikk: morzsa, cím, meta (dátum, frissítve, olvasási idő, kategória), tartalomjegyzék (h2/h3), a tartalom,
 * címkék, „Ki írta” cég-entitás doboz (a cégadat a beállításokból), kapcsolódó cikkek / oldalak, CTA.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

while (have_posts()) {
    the_post();

    $sdh_id    = (int) get_the_ID();
    $sdh_html  = (string) apply_filters('the_content', get_the_content()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
    [$sdh_html, $sdh_tj] = sdh_tema_tartalomjegyzek($sdh_html);
    $sdh_perc  = sdh_tema_olvasasi_ido($sdh_html);
    $sdh_kat   = get_the_category();
    $sdh_ceg   = sdh_tema_ceg();
    $sdh_mod   = get_the_modified_time('U') > get_the_time('U') + DAY_IN_SECONDS;
    sdh_tema_h1(true);
    ?>
	<article class="s-cikk" id="cikk">
		<header class="s-oldalfej s-cikk__fej">
			<div class="s-kont s-kont--szuk">
				<?php echo sdh_tema_morzsa_auto(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php if ($sdh_kat !== []) : ?>
					<p class="s-eyebrow"><a href="<?php echo esc_url((string) get_category_link($sdh_kat[0])); ?>"><?php echo esc_html($sdh_kat[0]->name); ?></a></p>
				<?php endif; ?>
				<h1 class="s-oldalfej__cim"><?php echo esc_html(sdh_tema_t(wp_strip_all_tags(get_the_title()))); ?></h1>
				<?php if (has_excerpt()) : ?>
					<p class="s-oldalfej__alcim"><?php echo esc_html(sdh_tema_t(wp_strip_all_tags(get_the_excerpt()))); ?></p>
				<?php endif; ?>
				<p class="s-cikk__meta">
					<span><?php echo sdh_tema_ikon('naptar'); // phpcs:ignore WordPress.Security.EscapeOutput ?><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('Y. F j.')); ?></time></span>
					<?php if ($sdh_mod) : ?>
						<span><?php echo sdh_tema_ikon('frissites'); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html(sdh_tema__('Frissítve:')); ?> <time datetime="<?php echo esc_attr(get_the_modified_date('c')); ?>"><?php echo esc_html(get_the_modified_date('Y. F j.')); ?></time></span>
					<?php endif; ?>
					<span><?php echo sdh_tema_ikon('ora'); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html(sprintf(/* translators: %d: perc */ sdh_tema__('%d perc olvasás'), $sdh_perc)); ?></span>
					<span><?php echo sdh_tema_ikon('felhasznalo'); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html($sdh_ceg['nev']); ?></span>
				</p>
			</div>
		</header>

		<?php if (has_post_thumbnail()) : ?>
			<figure class="s-kont s-kont--szuk s-cikk__kep"><?php the_post_thumbnail('large', ['fetchpriority' => 'high', 'loading' => 'eager', 'decoding' => 'sync', 'alt' => '']); ?></figure>
		<?php endif; ?>

		<div class="s-kont s-cikk__racs">
			<aside class="s-cikk__oldalsav"><?php echo sdh_tema_tj_html($sdh_tj); // phpcs:ignore WordPress.Security.EscapeOutput ?></aside>
			<div class="s-cikk__test">
				<div class="s-proza s-proza--cikk"><?php echo $sdh_html; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content ?></div>
				<?php wp_link_pages(['before' => '<nav class="s-lapozo" aria-label="' . esc_attr(sdh_tema__('Oldalak')) . '">', 'after' => '</nav>']); ?>

				<?php $sdh_cimkek = get_the_tags(); ?>
				<?php if (is_array($sdh_cimkek) && $sdh_cimkek !== []) : ?>
					<p class="s-pillsor s-cikk__cimkek"><span class="s-sr"><?php echo esc_html(sdh_tema__('Címkék:')); ?></span>
						<?php foreach ($sdh_cimkek as $sdh_c) : ?>
							<a class="s-pill s-pill--link s-pill--kicsi" href="<?php echo esc_url((string) get_tag_link($sdh_c)); ?>">#<?php echo esc_html($sdh_c->name); ?></a>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>

				<aside class="s-entitas s-uveg" aria-labelledby="entitas-cim">
					<span class="s-ikondoboz"><?php echo sdh_tema_ikon('pajzs-pipa'); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<div>
						<h2 class="s-entitas__cim" id="entitas-cim"><?php echo esc_html(sprintf(/* translators: %s: cég */ sdh_tema__('Szerző: %s'), $sdh_ceg['nev'])); ?></h2>
						<p class="s-entitas__szoveg"><?php echo esc_html(sdh_tema_t(sdh_tema__('A {termeknev} fejlesztője és forgalmazója. A cikkeinket a saját szervizes tapasztalatunk és a rendszer valós működése alapján írjuk.'))); ?></p>
						<p class="s-entitas__linkek">
							<?php $sdh_rolunk = sdh_tema_oldal_url('rolunk'); ?>
							<?php if ($sdh_rolunk !== '') : ?>
								<a href="<?php echo esc_url($sdh_rolunk); ?>"><?php echo esc_html(sdh_tema__('Rólunk')); ?></a>
							<?php endif; ?>
							<?php if ($sdh_ceg['email'] !== '' && is_email($sdh_ceg['email'])) : ?>
								<a href="<?php echo esc_url('mailto:' . $sdh_ceg['email']); ?>"><?php echo esc_html($sdh_ceg['email']); ?></a>
							<?php endif; ?>
						</p>
					</div>
				</aside>
			</div>
		</div>
	</article>
	<?php
	// Kapcsolódó: a SEO-modul linkhálója; ha nincs, azonos kategóriájú friss cikkek.
	$sdh_kap = sdh_tema_kapcsolodo('blog', (string) $sdh_id, 6, sdh_tema__('Kapcsolódó tartalmak'));

	if ($sdh_kap !== '') {
		echo '<section class="s-szekcio s-hatter--felulet"><div class="s-kont">' . $sdh_kap . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		$sdh_q = new WP_Query([
			'post_type'           => 'post',
			'posts_per_page'      => 3,
			'post__not_in'        => [$sdh_id],
			'category__in'        => wp_list_pluck($sdh_kat, 'term_id'),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		]);

		if ($sdh_q->have_posts()) {
			echo '<section class="s-szekcio s-hatter--felulet" aria-labelledby="kapcsolodo-cikkek"><div class="s-kont"><header class="s-szekcio__fej s-szekcio__fej--bal"><h2 class="s-szekcio__cim" id="kapcsolodo-cikkek">' . esc_html(sdh_tema__('Kapcsolódó cikkek')) . '</h2></header><ul class="s-racs s-racs--3" role="list">';

			while ($sdh_q->have_posts()) {
				$sdh_q->the_post();
				get_template_part('sablonok/kartya', 'cikk', ['szint' => 'h3']);
			}

			wp_reset_postdata();
			echo '</ul></div></section>';
		}
	}

	$sdh_demo = sdh_tema_oldal_url('demo') ?: (string) sdh_tema_b('demo.url', '');
	$sdh_arak = sdh_tema_oldal_url('arak');
	$sdh_cta  = [];

	if ($sdh_demo !== '') {
		$sdh_cta[] = ['szoveg' => sdh_tema__('Kipróbálom a demót'), 'url' => $sdh_demo, 'stilus' => 'elsodleges'];
	}

	if ($sdh_arak !== '') {
		$sdh_cta[] = ['szoveg' => sdh_tema__('Árak megtekintése'), 'url' => $sdh_arak, 'stilus' => 'masodlagos'];
	}

	if ($sdh_cta !== []) {
		sdh_tema_szekcio(['tipus' => 'cta', 'cim' => sdh_tema_t(sdh_tema__('Nézd meg a {termeknev} rendszert működés közben')), 'cta' => $sdh_cta], 90);
	}
}

get_footer();
