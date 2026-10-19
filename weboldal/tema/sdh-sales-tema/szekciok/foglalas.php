<?php
/**
 * Szekció: foglalas – időpontfoglalás (szabad sávok, foglalás). Mezők: cim, alcim, tipus (demo|telepites|oktatas|support).
 * Csatolás: <div data-sdh="foglalas" data-tipus="demo"> (a bővítmény assets/front/foglalas.js-e; a dátumválasztó saját).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$tipus = sanitize_key(sdh_tema_mezo($sz, 'tipus', 'demo'));
$tipus = in_array($tipus, ['demo', 'telepites', 'oktatas', 'support'], true) ? $tipus : 'demo';

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-uveg s-panel s-panel--foglalas" id="foglalas">';
echo sdh_tema_mount('foglalas', $sz, ['tipus' => $tipus], sdh_tema_noscript()); // phpcs:ignore WordPress.Security.EscapeOutput
echo '</div>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
