<?php
/**
 * Tudásbázis-fejezet: /tudasbazis/<fejezet>/ (inc/tudasbazis.php irányít ide; ismeretlen fejezet → 404).
 * Elrendezés: morzsa, cím, összefoglaló, oldalsáv (tartalomjegyzék + a csoport fejezetei), darabok horgonnyal
 * (Szikra ezekre linkel: #<horgony>), kapcsolódó leckék, előző / következő fejezet.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

$sdh_f = sdh_tema_tudas_fejezet();

if ($sdh_f === null) {
    // Biztonsági háló (a 404-et az inc/tudasbazis.php adja).
    get_template_part('404');
    return;
}

$sdh_fejezetek = [];

try {
    $sdh_fejezetek = class_exists('SDH_Sales_Szikra_Tudas') ? SDH_Sales_Szikra_Tudas::fejezetek() : [];
} catch (Throwable $e) {
    $sdh_fejezetek = [];
}

$sdh_kulcs = (string) $sdh_f['kulcs'];
$sdh_poz   = array_search($sdh_kulcs, array_column($sdh_fejezetek, 'kulcs'), true);
$sdh_elozo = $sdh_poz !== false && $sdh_poz > 0 ? $sdh_fejezetek[$sdh_poz - 1] : null;
$sdh_kov   = $sdh_poz !== false && isset($sdh_fejezetek[$sdh_poz + 1]) ? $sdh_fejezetek[$sdh_poz + 1] : null;
$sdh_csop  = (string) ($sdh_f['csoport'] ?? '');
$sdh_tj    = [];
$sdh_html  = '';

foreach ((array) ($sdh_f['darabok'] ?? []) as $sdh_d) {
    if (!is_array($sdh_d)) {
        continue;
    }

    $sdh_h  = sanitize_html_class((string) ($sdh_d['horgony'] ?? sanitize_title((string) ($sdh_d['cim'] ?? ''))));
    $sdh_c  = sdh_tema_t((string) ($sdh_d['cim'] ?? ''));
    $sdh_sz = sdh_tema_t((string) ($sdh_d['szoveg'] ?? ''));

    if ($sdh_c !== '') {
        $sdh_tj[] = ['id' => $sdh_h, 'cim' => $sdh_c, 'szint' => 2];
    }

    $sdh_html .= '<section class="s-tudas-darab" id="' . esc_attr($sdh_h) . '">'
        . ($sdh_c !== '' ? '<h2>' . esc_html($sdh_c) . '</h2>' : '')
        . (class_exists('SDH_Sales_Tartalom') ? SDH_Sales_Tartalom::md_html($sdh_sz) : wpautop(esc_html($sdh_sz)))
        . '</section>';
}

get_header();
sdh_tema_h1(true);
?>
<article class="s-tudas">
	<header class="s-oldalfej">
		<div class="s-kont">
			<?php echo sdh_tema_morzsa_auto(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php if ($sdh_csop !== '') : ?>
				<p class="s-eyebrow"><?php echo esc_html(sdh_tema_t($sdh_csop)); ?></p>
			<?php endif; ?>
			<h1 class="s-oldalfej__cim"><?php echo esc_html(sdh_tema_t((string) $sdh_f['cim'])); ?></h1>
			<?php if (!empty($sdh_f['osszefoglalo'])) : ?>
				<p class="s-oldalfej__alcim"><?php echo esc_html(sdh_tema_t((string) $sdh_f['osszefoglalo'])); ?></p>
			<?php endif; ?>
		</div>
	</header>
	<div class="s-kont s-tudas__racs">
		<aside class="s-tudas__oldalsav">
			<?php echo sdh_tema_tj_html($sdh_tj, sdh_tema__('Ezen az oldalon')); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php
			$sdh_tars = array_values(array_filter($sdh_fejezetek, static fn ($x) => (string) ($x['csoport'] ?? '') === $sdh_csop));

			if (count($sdh_tars) > 1) :
				?>
				<nav aria-label="<?php echo esc_attr(sprintf(/* translators: %s: csoport */ sdh_tema__('Fejezetek: %s'), sdh_tema_t($sdh_csop))); ?>">
					<p class="s-tj__cim"><?php echo esc_html(sdh_tema_t($sdh_csop)); ?></p>
					<ul class="s-fejezetlista">
						<?php foreach ($sdh_tars as $sdh_x) : ?>
							<li><a href="<?php echo esc_url((string) $sdh_x['url']); ?>"<?php echo $sdh_x['kulcs'] === $sdh_kulcs ? ' aria-current="page"' : ''; ?>><?php echo esc_html(sdh_tema_t((string) $sdh_x['cim'])); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>
		</aside>
		<div class="s-tudas__test">
			<div class="s-proza"><?php echo wp_kses_post($sdh_html); ?></div>

			<?php $sdh_leckek = sdh_tema_tudas_leckek($sdh_f); ?>
			<?php if ($sdh_leckek !== []) : ?>
				<aside class="s-kapcsolodo" aria-labelledby="tudas-leckek">
					<h2 class="s-kapcsolodo__cim" id="tudas-leckek"><?php echo esc_html(sdh_tema__('Kapcsolódó leckék')); ?></h2>
					<ul class="s-kapcsolodo__lista">
						<?php foreach ($sdh_leckek as $sdh_l) : ?>
							<?php if ($sdh_l['url'] === '') { continue; } ?>
							<li><a class="s-kapcsolodo__elem" href="<?php echo esc_url($sdh_l['url']); ?>">
								<span class="s-pill s-pill--kicsi"><?php echo esc_html($sdh_l['perc'] > 0 ? sprintf(/* translators: %d: perc */ sdh_tema__('Lecke · %d perc'), $sdh_l['perc']) : sdh_tema__('Lecke')); ?></span>
								<span class="s-kapcsolodo__nev"><?php echo esc_html($sdh_l['cim']); ?></span>
								<?php if ($sdh_l['leiras'] !== '') : ?>
									<span class="s-kapcsolodo__leiras"><?php echo esc_html($sdh_l['leiras']); ?></span>
								<?php endif; ?>
							</a></li>
						<?php endforeach; ?>
					</ul>
				</aside>
			<?php endif; ?>

			<?php if ($sdh_elozo || $sdh_kov) : ?>
				<nav class="s-tudas__lapozo" aria-label="<?php echo esc_attr(sdh_tema__('Fejezetek között')); ?>">
					<?php if ($sdh_elozo) : ?>
						<a href="<?php echo esc_url((string) $sdh_elozo['url']); ?>" rel="prev"><small><?php echo esc_html(sdh_tema__('← Előző fejezet')); ?></small><span><?php echo esc_html(sdh_tema_t((string) $sdh_elozo['cim'])); ?></span></a>
					<?php else : ?>
						<span></span>
					<?php endif; ?>
					<?php if ($sdh_kov) : ?>
						<a class="s-tudas__kov" href="<?php echo esc_url((string) $sdh_kov['url']); ?>" rel="next"><small><?php echo esc_html(sdh_tema__('Következő fejezet →')); ?></small><span><?php echo esc_html(sdh_tema_t((string) $sdh_kov['cim'])); ?></span></a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		</div>
	</div>
</article>
<?php
get_footer();
