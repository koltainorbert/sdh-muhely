<?php
/**
 * Keresési találatok (szerveroldal; a bővítmény a WP-keresést kiegészíti a tartalom-tár elemeivel).
 * Ha van SDH_Sales_Kereso, a tartalom-tár találatai (funkció, modul, GYIK, tudásbázis…) is megjelennek csoportosítva.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$sdh_q     = get_search_query(false);
$sdh_tar   = [];

if ($sdh_q !== '' && class_exists('SDH_Sales_Kereso')) {
    try {
        $sdh_tar = SDH_Sales_Kereso::keres($sdh_q, 12);
    } catch (Throwable $e) {
        $sdh_tar = [];
    }
}

$sdh_csop = [
    'funkcio' => sdh_tema__('Funkciók'),
    'modul'   => sdh_tema__('Modulok'),
    'iparag'  => sdh_tema__('Iparágak'),
    'gyik'    => sdh_tema__('Gyakori kérdések'),
    'tudas'   => sdh_tema__('Tudásbázis'),
    'oldal'   => sdh_tema__('Oldalak'),
    'blog'    => sdh_tema__('Blog'),
];

echo sdh_tema_oldalfej(
    $sdh_q !== '' ? sprintf(/* translators: %s: keresett szöveg */ sdh_tema__('Keresés: „%s”'), $sdh_q) : sdh_tema__('Keresés'),
    '',
    '<div class="s-oldalfej__kereso">' . get_search_form(['echo' => false]) . '</div>'
); // phpcs:ignore WordPress.Security.EscapeOutput
?>
<div class="s-szekcio s-hatter--alap">
	<div class="s-kont s-kont--szuk">
		<?php
		$sdh_lato = [];
		$sdh_db   = 0;

		if ($sdh_tar !== []) {
			$sdh_cs = [];

			foreach ($sdh_tar as $sdh_t) {
				$sdh_u = (string) ($sdh_t['u'] ?? $sdh_t['url'] ?? '');

				if ($sdh_u === '' || isset($sdh_lato[$sdh_u])) {
					continue;
				}

				$sdh_lato[$sdh_u] = true;
				$sdh_cs[(string) ($sdh_t['k'] ?? 'oldal')][] = $sdh_t;
			}

			foreach ($sdh_cs as $sdh_k => $sdh_l) {
				echo '<h2 class="s-csoportcim">' . esc_html($sdh_csop[$sdh_k] ?? ucfirst($sdh_k)) . '</h2><ul class="s-talalatlista">';

				foreach ($sdh_l as $sdh_t) {
					$sdh_db++;
					echo '<li class="s-talalat"><a class="s-talalat__link" href="' . esc_url((string) ($sdh_t['u'] ?? $sdh_t['url'] ?? '')) . '"><span class="s-talalat__cim">' . esc_html(sdh_tema_t((string) ($sdh_t['t'] ?? $sdh_t['cim'] ?? ''))) . '</span>'
						. (!empty($sdh_t['s']) ? '<span class="s-talalat__kivonat">' . esc_html(sdh_tema_t((string) $sdh_t['s'])) . '</span>' : '')
						. '</a></li>';
				}

				echo '</ul>';
			}
		}

		if (have_posts()) {
			$sdh_fej = false;

			while (have_posts()) {
				the_post();
				$sdh_u = (string) get_permalink();

				if (isset($sdh_lato[$sdh_u])) {
					continue;
				}

				if (!$sdh_fej) {
					echo '<h2 class="s-csoportcim">' . esc_html($sdh_tar !== [] ? sdh_tema__('További találatok') : sdh_tema__('Találatok')) . '</h2><ul class="s-talalatlista">';
					$sdh_fej = true;
				}

				$sdh_db++;
				echo '<li class="s-talalat"><a class="s-talalat__link" href="' . esc_url($sdh_u) . '"><span class="s-talalat__cim">' . esc_html(sdh_tema_t(wp_strip_all_tags(get_the_title()))) . '</span><span class="s-talalat__kivonat">' . esc_html(sdh_tema_t(wp_strip_all_tags(get_the_excerpt()))) . '</span></a></li>';
			}

			if ($sdh_fej) {
				echo '</ul>';
			}

			the_posts_pagination(['mid_size' => 1, 'prev_text' => esc_html(sdh_tema__('Előző')), 'next_text' => esc_html(sdh_tema__('Következő')), 'screen_reader_text' => sdh_tema__('Lapozás'), 'class' => 's-lapozo']);
		}

		if ($sdh_db === 0) {
			echo '<div class="s-doboz s-doboz--info">' . sdh_tema_ikon('info') . '<div><p><strong>' . esc_html(sdh_tema__('Nincs találat.')) . '</strong></p><p>' . esc_html(sdh_tema__('Próbáld rövidebb vagy más szóval, vagy nézd meg a népszerű oldalakat.')) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			get_template_part('sablonok/nepszeru');
		}
		?>
	</div>
</div>
<?php
get_footer();
