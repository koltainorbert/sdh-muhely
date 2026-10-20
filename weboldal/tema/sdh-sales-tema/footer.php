<?php
/**
 * Lábléc: oszlopok a menu('lablec')-ből, cégadat (SDH_Sales beállítások / tokenek), jogi linkek (menu('jogi')),
 * „Süti-beállítások” (.s-suti-megnyit – a bővítmény suti.js-e nyitja), kereső-ablak váza, értesítő-terület.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

$sdh_lablec = sdh_tema_menu('lablec');
$sdh_jogi   = sdh_tema_menu('jogi');
$sdh_ceg    = sdh_tema_ceg();
?>
</main>
<footer class="s-lablec" id="lablec">
	<div class="s-kont">
		<div class="s-lablec__racs">
			<div class="s-lablec__marka">
				<a class="s-logo" href="<?php echo esc_url(home_url('/')); ?>"<?php echo sdh_tema_szerk('b:termeknev'); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					<span class="s-logo__jel" aria-hidden="true"><?php echo sdh_tema_ikon('villam'); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<span class="s-logo__nev"><?php echo esc_html(sdh_tema_termeknev()); ?></span>
				</a>
				<?php $sdh_szlogen = trim(sdh_tema_t('{szlogen}')); ?>
				<?php if ($sdh_szlogen !== '') : ?>
					<p class="s-lablec__szlogen"<?php echo sdh_tema_szerk('b:szlogen'); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html($sdh_szlogen); ?></p>
				<?php endif; ?>
				<address class="s-lablec__ceg"<?php echo sdh_tema_szerk('b:ceg'); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					<strong><?php echo esc_html($sdh_ceg['nev']); ?></strong>
					<?php if ($sdh_ceg['cim'] !== '') : ?>
						<span><?php echo esc_html($sdh_ceg['cim']); ?></span>
					<?php endif; ?>
					<?php if ($sdh_ceg['email'] !== '' && is_email($sdh_ceg['email'])) : ?>
						<a href="<?php echo esc_url('mailto:' . $sdh_ceg['email']); ?>"><?php echo esc_html($sdh_ceg['email']); ?></a>
					<?php endif; ?>
					<?php if ($sdh_ceg['telefon'] !== '') : ?>
						<a href="<?php echo esc_url('tel:' . preg_replace('/[^0-9+]/', '', $sdh_ceg['telefon'])); ?>"><?php echo esc_html($sdh_ceg['telefon']); ?></a>
					<?php endif; ?>
					<?php if ($sdh_ceg['adoszam'] !== '') : ?>
						<span><?php echo esc_html(sprintf(/* translators: %s: adószám */ sdh_tema__('Adószám: %s'), $sdh_ceg['adoszam'])); ?></span>
					<?php endif; ?>
					<?php if ($sdh_ceg['cegjegyzek'] !== '') : ?>
						<span><?php echo esc_html(sprintf(/* translators: %s: cégjegyzékszám */ sdh_tema__('Cégjegyzékszám: %s'), $sdh_ceg['cegjegyzek'])); ?></span>
					<?php endif; ?>
				</address>
			</div>

			<?php foreach ($sdh_lablec as $sdh_i => $sdh_o) : ?>
				<?php $sdh_gy = (array) $sdh_o['gyerekek']; ?>
				<nav class="s-lablec__oszlop" aria-labelledby="<?php echo esc_attr('lablec-' . $sdh_i); ?>"<?php echo sdh_tema_szerk('m:lablec/' . $sdh_i); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					<h2 class="s-lablec__cim" id="<?php echo esc_attr('lablec-' . $sdh_i); ?>">
						<?php if ($sdh_gy === [] && $sdh_o['url'] !== '') : ?>
							<a href="<?php echo esc_url($sdh_o['url']); ?>"><?php echo esc_html($sdh_o['cim']); ?></a>
						<?php else : ?>
							<?php echo esc_html($sdh_o['cim']); ?>
						<?php endif; ?>
					</h2>
					<?php if ($sdh_gy !== []) : ?>
						<ul>
							<?php foreach ($sdh_gy as $sdh_j => $sdh_g) : ?>
								<?php if ($sdh_g['url'] === '') { continue; } ?>
								<li<?php echo sdh_tema_szerk('m:lablec/' . $sdh_i . '/' . $sdh_j); // phpcs:ignore WordPress.Security.EscapeOutput ?>><a href="<?php echo esc_url($sdh_g['url']); ?>"><?php echo esc_html($sdh_g['cim']); ?></a></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</nav>
			<?php endforeach; ?>
		</div>

		<div class="s-lablec__also">
			<p class="s-lablec__copy">&copy; <?php echo esc_html(wp_date('Y') . ' ' . $sdh_ceg['nev']); ?></p>
			<nav class="s-lablec__jogi" aria-label="<?php echo esc_attr(sdh_tema__('Jogi információk')); ?>">
				<ul>
					<?php foreach ($sdh_jogi as $sdh_ji => $sdh_j) : ?>
						<?php if ($sdh_j['url'] === '') { continue; } ?>
						<li<?php echo sdh_tema_szerk('m:jogi/' . $sdh_ji); // phpcs:ignore WordPress.Security.EscapeOutput ?>><a href="<?php echo esc_url($sdh_j['url']); ?>"><?php echo esc_html($sdh_j['cim']); ?></a></li>
					<?php endforeach; ?>
					<li><button type="button" class="s-linkgomb s-suti-megnyit"<?php echo sdh_tema_szerk_felirat('Süti-beállítások'); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html(sdh_tema__('Süti-beállítások')); ?></button></li>
				</ul>
			</nav>
		</div>
	</div>
</footer>

<div class="s-kereso" id="kereso" role="dialog" aria-modal="true" aria-labelledby="kereso-cim" hidden data-kereso>
	<div class="s-kereso__hatter" data-kereso-zar></div>
	<div class="s-kereso__ablak s-uveg" role="document">
		<h2 class="s-sr" id="kereso-cim"><?php echo esc_html(sdh_tema__('Keresés')); ?></h2>
		<form class="s-kereso__urlap" role="search" action="<?php echo esc_url(home_url('/')); ?>" method="get">
			<?php echo sdh_tema_ikon('kereso', 's-kereso__ikon'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<label class="s-sr" for="kereso-mezo"><?php echo esc_html(sdh_tema__('Keresett kifejezés')); ?></label>
			<input class="s-kereso__mezo" id="kereso-mezo" name="s" type="search" autocomplete="off" spellcheck="false" enterkeyhint="search"
				role="combobox" aria-expanded="false" aria-controls="kereso-lista" aria-autocomplete="list"
				placeholder="<?php echo esc_attr(sdh_tema__('Keress funkcióra, modulra, kérdésre…')); ?>">
			<button class="s-ikongomb s-kereso__zar" type="button" data-kereso-zar><?php echo sdh_tema_ikon('x'); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="s-sr"><?php echo esc_html(sdh_tema__('Bezárás')); ?></span></button>
		</form>
		<div class="s-kereso__talalatok" id="kereso-lista" role="listbox" aria-label="<?php echo esc_attr(sdh_tema__('Találatok')); ?>"></div>
		<p class="s-kereso__lab"><span data-kereso-sugo><?php echo esc_html(sdh_tema__('↑↓ lépked · Enter megnyit · Esc bezár')); ?></span></p>
		<p class="s-sr" aria-live="polite" data-kereso-allapot></p>
	</div>
</div>
<div class="s-ertesitok" aria-live="polite" aria-atomic="false" data-ertesitok></div>
<?php wp_footer(); ?>
</body>
</html>
