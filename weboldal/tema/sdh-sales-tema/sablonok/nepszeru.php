<?php
/**
 * Népszerű linkek (a főmenü elemei és gyermekei, legfeljebb 8) – 404 és üres keresés.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

$sdh_l = [];

foreach (sdh_tema_menu('fo') as $sdh_e) {
    if ($sdh_e['url'] !== '') {
        $sdh_l[$sdh_e['url']] = $sdh_e['cim'];
    }

    foreach ((array) $sdh_e['gyerekek'] as $sdh_g) {
        if ($sdh_g['url'] !== '' && count($sdh_l) < 8) {
            $sdh_l[$sdh_g['url']] = $sdh_g['cim'];
        }
    }
}

foreach (['arak' => sdh_tema__('Árak'), 'gyik' => sdh_tema__('Gyakori kérdések'), 'kapcsolat' => sdh_tema__('Kapcsolat'), 'blog' => sdh_tema__('Blog')] as $sdh_ut => $sdh_c) {
    $sdh_u = sdh_tema_oldal_url($sdh_ut);

    if ($sdh_u !== '' && !isset($sdh_l[$sdh_u])) {
        $sdh_l[$sdh_u] = $sdh_c;
    }
}

$sdh_l = array_slice($sdh_l, 0, 8, true);

if ($sdh_l === []) {
    return;
}
?>
<nav class="s-nepszeru" aria-labelledby="nepszeru-cim">
	<h2 class="s-csoportcim" id="nepszeru-cim"><?php echo esc_html(sdh_tema__('Népszerű oldalak')); ?></h2>
	<ul class="s-racs s-racs--2" role="list">
		<?php foreach ($sdh_l as $sdh_u => $sdh_c) : ?>
			<li><a class="s-kartya s-kartya--link" href="<?php echo esc_url($sdh_u); ?>"><span><?php echo esc_html($sdh_c); ?></span><?php echo sdh_tema_ikon('nyil-jobb'); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></li>
		<?php endforeach; ?>
	</ul>
</nav>
