<?php
/**
 * Szekció: szoveg – formázott szövegblokk. Mezők: cim, html (vagy szoveg), szelesseg (keskeny|normal|szeles).
 * A HTML a bővítményben tisztítva, itt még egyszer wp_kses_post-on megy át.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$html = sdh_tema_mezo($sz, 'html', sdh_tema_mezo($sz, 'szoveg'));
$szel = sdh_tema_mezo($sz, 'szelesseg', 'keskeny');

echo sdh_tema_szekcio_nyit($sz, ['szelesseg' => $szel === 'normal' ? '' : $szel, 'igazitas' => 'bal']); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-proza"' . sdh_tema_szerk_mezo($sz, sdh_tema_mezo($sz, 'html') !== '' ? 'html' : 'szoveg') . '>' . sdh_tema_szoveg($html) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
