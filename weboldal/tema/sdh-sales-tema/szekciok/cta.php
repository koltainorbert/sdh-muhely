<?php
/**
 * Szekció: cta – kiemelt cselekvésre hívó sáv. Mezők: eyebrow, cim, szoveg|alcim, gomb, gomb2, cta[], stilus (atmenet|sima).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$stilus = sdh_tema_mezo($sz, 'stilus', 'atmenet') === 'sima' ? 'sima' : 'atmenet';
$id     = sdh_tema_szekcio_id($sz);

echo sdh_tema_szekcio_nyit($sz, ['fej' => false]); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-cta s-cta--' . esc_attr($stilus) . '">';
echo sdh_tema_szekcio_fej(['cim' => sdh_tema_mezo($sz, 'cim'), sdh_tema_eyebrow_mezo($sz) => sdh_tema_eyebrow($sz), 'alcim' => sdh_tema_mezo($sz, 'alcim', sdh_tema_mezo($sz, 'szoveg')), '_alcim_mezo' => sdh_tema_mezo($sz, 'alcim') !== '' ? 'alcim' : 'szoveg', '_post' => $sz['_post'] ?? 0, '_i' => $sz['_i'] ?? 0], $id . '-cim'); // phpcs:ignore WordPress.Security.EscapeOutput
echo sdh_tema_gombok(sdh_tema_cta($sz), 's-gombsor--kozep'); // phpcs:ignore WordPress.Security.EscapeOutput
echo '</div>';
echo sdh_tema_szekcio_zar($sz, false); // phpcs:ignore WordPress.Security.EscapeOutput
