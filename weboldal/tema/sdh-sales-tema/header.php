<?php
/**
 * Fejléc: ugrólink, ragadós üveg-fejléc, főmenü (SDH_Sales_Tartalom::menu('fo')) hozzáférhető legördülőkkel,
 * mobilmenü, kereső-gomb (Ctrl+K), sötét / világos váltó, CTA-k (Demó, Árak).
 * Gyorsítótár-biztos: nincs felhasználófüggő kimenet (a kosár-számlálót a JS tölti a localStorage-ból).
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

$sdh_menu  = sdh_tema_menu('fo');
$sdh_demo  = sdh_tema_oldal_url('demo') ?: (string) sdh_tema_b('demo.url', '');
$sdh_arak  = sdh_tema_oldal_url('arak');
$sdh_logo  = class_exists('SDH_Sales_Arculat') ? (string) SDH_Sales_Arculat::logo('sotet') : '';
$sdh_logo2 = class_exists('SDH_Sales_Arculat') ? (string) SDH_Sales_Arculat::logo('vilagos') : '';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php wp_head(); ?>
</head>
<body class="<?php echo esc_attr(sdh_tema_body_osztaly()); ?>">
<?php wp_body_open(); ?>
<a class="s-ugro" href="#tartalom"><?php echo esc_html(sdh_tema__('Ugrás a tartalomra')); ?></a>
<header class="s-fejlec" id="fejlec" data-fejlec>
	<div class="s-kont s-fejlec__belso">
		<a class="s-logo" href="<?php echo esc_url(home_url('/')); ?>" rel="home" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: terméknév */ sdh_tema__('%s – kezdőlap'), sdh_tema_termeknev())); ?>">
			<?php if ($sdh_logo !== '') : ?>
				<img class="s-logo__kep s-logo__kep--sotet" src="<?php echo esc_url($sdh_logo); ?>" alt="" width="140" height="32" decoding="async">
				<?php if ($sdh_logo2 !== '' && $sdh_logo2 !== $sdh_logo) : ?>
					<img class="s-logo__kep s-logo__kep--vilagos" src="<?php echo esc_url($sdh_logo2); ?>" alt="" width="140" height="32" decoding="async">
				<?php endif; ?>
			<?php else : ?>
				<span class="s-logo__jel" aria-hidden="true"><?php echo sdh_tema_ikon('villam'); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<span class="s-logo__nev"><?php echo esc_html(sdh_tema_termeknev()); ?></span>
			<?php endif; ?>
		</a>

		<?php if ($sdh_menu !== []) : ?>
		<nav class="s-fomenu" id="fomenu" aria-label="<?php echo esc_attr(sdh_tema__('Főmenü')); ?>" data-fomenu>
			<ul class="s-fomenu__lista">
				<?php foreach ($sdh_menu as $sdh_i => $sdh_e) : ?>
					<?php
					$sdh_gy  = (array) $sdh_e['gyerekek'];
					$sdh_akt = $sdh_e['url'] !== '' && untrailingslashit((string) strtok($sdh_e['url'], '#?')) === untrailingslashit(home_url(add_query_arg([])));
					?>
					<li class="s-fomenu__elem<?php echo $sdh_gy !== [] ? ' s-fomenu__elem--szulo' : ''; ?>">
						<?php if ($sdh_gy !== []) : ?>
							<button class="s-fomenu__link s-fomenu__nyito" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr('almenu-' . $sdh_i); ?>">
								<?php echo esc_html($sdh_e['cim']); ?><?php echo sdh_tema_ikon('nyil-le', 's-fomenu__nyil'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</button>
							<div class="s-almenu s-uveg" id="<?php echo esc_attr('almenu-' . $sdh_i); ?>" hidden>
								<ul class="s-almenu__lista">
									<?php if ($sdh_e['url'] !== '') : ?>
										<li><a class="s-almenu__link s-almenu__link--osszes" href="<?php echo esc_url($sdh_e['url']); ?>"><?php echo esc_html(sprintf(/* translators: %s: menüpont */ sdh_tema__('%s – áttekintés'), $sdh_e['cim'])); ?></a></li>
									<?php endif; ?>
									<?php foreach ($sdh_gy as $sdh_g) : ?>
										<?php if ($sdh_g['url'] === '') { continue; } ?>
										<li><a class="s-almenu__link" href="<?php echo esc_url($sdh_g['url']); ?>"><?php echo esc_html($sdh_g['cim']); ?></a></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php else : ?>
							<a class="s-fomenu__link" href="<?php echo esc_url($sdh_e['url']); ?>"<?php echo $sdh_akt ? ' aria-current="page"' : ''; ?>><?php echo esc_html($sdh_e['cim']); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="s-fomenu__cta">
				<?php if ($sdh_arak !== '') : ?>
					<a class="s-gomb s-gomb--masodlagos" href="<?php echo esc_url($sdh_arak); ?>"><?php echo esc_html(sdh_tema__('Árak')); ?></a>
				<?php endif; ?>
				<?php if ($sdh_demo !== '') : ?>
					<a class="s-gomb s-gomb--elsodleges" href="<?php echo esc_url($sdh_demo); ?>"><?php echo esc_html(sdh_tema__('Demó')); ?></a>
				<?php endif; ?>
			</div>
		</nav>
		<?php endif; ?>

		<div class="s-fejlec__eszkozok">
			<button class="s-ikongomb s-kereso-gomb" type="button" data-kereso-nyit aria-haspopup="dialog" aria-keyshortcuts="Control+K /">
				<?php echo sdh_tema_ikon('kereso'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="s-kereso-gomb__szoveg"><?php echo esc_html(sdh_tema__('Keresés')); ?></span>
				<kbd class="s-kbd" aria-hidden="true">Ctrl K</kbd>
				<span class="s-sr"><?php echo esc_html(sdh_tema__('(Ctrl+K)')); ?></span>
			</button>
			<button class="s-ikongomb s-temavalto" type="button" data-temavalto aria-pressed="false">
				<?php echo sdh_tema_ikon('nap', 's-temavalto__nap'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo sdh_tema_ikon('hold', 's-temavalto__hold'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="s-sr" data-temavalto-felirat><?php echo esc_html(sdh_tema__('Világos mód bekapcsolása')); ?></span>
			</button>
			<?php if ($sdh_arak !== '') : ?>
				<a class="s-gomb s-gomb--masodlagos s-fejlec__cta" href="<?php echo esc_url($sdh_arak); ?>"><?php echo esc_html(sdh_tema__('Árak')); ?></a>
			<?php endif; ?>
			<?php if ($sdh_demo !== '') : ?>
				<a class="s-gomb s-gomb--elsodleges s-fejlec__cta" href="<?php echo esc_url($sdh_demo); ?>"><?php echo esc_html(sdh_tema__('Demó')); ?></a>
			<?php endif; ?>
			<?php if ($sdh_menu !== []) : ?>
				<button class="s-ikongomb s-menugomb" type="button" aria-expanded="false" aria-controls="fomenu" data-menugomb>
					<?php echo sdh_tema_ikon('menu', 's-menugomb__nyit'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo sdh_tema_ikon('x', 's-menugomb__zar'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span class="s-sr" data-menugomb-felirat><?php echo esc_html(sdh_tema__('Menü megnyitása')); ?></span>
				</button>
			<?php endif; ?>
		</div>
	</div>
</header>
<main id="tartalom" class="s-fo" tabindex="-1">
