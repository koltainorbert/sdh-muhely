<?php
/**
 * Szekció: kereso – teljes oldalas kereső (a téma kereső-JS-e; JS nélkül a WordPress-keresés űrlapja).
 * Mezők: cim, alcim, helyorzo.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$id   = sdh_tema_azon('oldalkereso');
$hely = sdh_tema_t(sdh_tema_mezo($sz, 'helyorzo', sdh_tema__('Keress funkcióra, modulra, kérdésre…')));

echo sdh_tema_szekcio_nyit($sz, ['szelesseg' => 'szuk']); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<form role="search" method="get" action="' . esc_url(home_url('/')) . '" class="s-oldalkereso" data-oldalkereso>';
echo '<label class="s-sr" for="' . esc_attr($id) . '">' . esc_html(sdh_tema__('Keresés')) . '</label>';
echo '<div class="s-oldalkereso__mezo"' . sdh_tema_szerk_mezo($sz, 'helyorzo') . '>' . sdh_tema_ikon('kereso') . '<input class="s-mezo s-mezo--nagy" type="search" id="' . esc_attr($id) . '" name="s" placeholder="' . esc_attr($hely) . '" autocomplete="off" enterkeyhint="search" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="' . esc_attr($id . '-lista') . '"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
echo '<button class="s-gomb s-gomb--elsodleges" type="submit">' . esc_html(sdh_tema__('Keresés')) . '</button>';
echo '</form>';
echo '<div class="s-kereso__talalatok" id="' . esc_attr($id . '-lista') . '" role="listbox" aria-label="' . esc_attr(sdh_tema__('Találatok')) . '" data-oldalkereso-lista></div>';
echo '<p class="s-sr" aria-live="polite" data-oldalkereso-allapot></p>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
