<?php
/**
 * 404: kereső + népszerű linkek. (A bővítmény SEO-modulja noindexeli.)
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
sdh_tema_h1(true);
?>
<section class="s-szekcio s-404 s-hatter--alap" aria-labelledby="hiba-cim">
	<div class="s-hero__aurora" aria-hidden="true"></div>
	<div class="s-kont s-kont--szuk">
		<p class="s-404__kod" aria-hidden="true">404</p>
		<h1 class="s-oldalfej__cim" id="hiba-cim"><?php echo esc_html(sdh_tema__('Ez az oldal nem található')); ?></h1>
		<p class="s-oldalfej__alcim"><?php echo esc_html(sdh_tema__('Lehet, hogy elköltözött, vagy elgépelődött a cím. Keress rá, vagy válassz a népszerű oldalak közül.')); ?></p>
		<div class="s-404__kereso"><?php get_search_form(); ?></div>
		<?php get_template_part('sablonok/nepszeru'); ?>
		<p class="s-gombsor s-gombsor--kozep"><a class="s-gomb s-gomb--elsodleges" href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html(sdh_tema__('Vissza a kezdőlapra')); ?></a></p>
	</div>
</section>
<?php
get_footer();
