<?php
/**
 * Egy oldal szerkesztett tartalma (szekció nélkül), olvasható szélességben, oldalakra bontással.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="s-szekcio s-hatter--alap">
	<div class="s-kont s-kont--szuk">
		<div class="s-proza">
			<?php
			the_content();
			wp_link_pages(['before' => '<nav class="s-lapozo" aria-label="' . esc_attr(sdh_tema__('Oldalak')) . '">', 'after' => '</nav>']);
			?>
		</div>
	</div>
</div>
