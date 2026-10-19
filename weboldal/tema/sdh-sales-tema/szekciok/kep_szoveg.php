<?php
/**
 * Szekció: kep_szoveg – kép az egyik, szöveg a másik oldalon. Mezők: eyebrow|cimke, cim, html|szoveg, kep, kep_alt,
 * kep_oldal (jobb|bal), gomb, cta[], pontok [{szoveg}].
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$oldal = sdh_tema_mezo($sz, 'kep_oldal', 'jobb') === 'bal' ? 'bal' : 'jobb';
$kep   = sdh_tema_kep_mezo($sz['kep'] ?? '', 'fel', ['class' => 's-kepszoveg__img'], sdh_tema_mezo($sz, 'kep_alt'));
$id    = sdh_tema_szekcio_id($sz);

echo sdh_tema_szekcio_nyit($sz, ['fej' => false, 'osztaly' => 's-kepszoveg--' . $oldal . ($kep === '' ? ' s-kepszoveg--kepnelkul' : '')]); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-kepszoveg"><div class="s-kepszoveg__szoveg">';
echo sdh_tema_szekcio_fej($sz, $id . '-cim', 'h2', 'bal'); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-proza">' . sdh_tema_szoveg(sdh_tema_mezo($sz, 'html', sdh_tema_mezo($sz, 'szoveg'))) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput

$pontok = sdh_tema_elemek($sz, 'pontok');

if ($pontok !== []) {
    echo '<ul class="s-pipalista">';

    foreach ($pontok as $p) {
        echo '<li>' . sdh_tema_ikon('pipa') . '<span>' . esc_html(sdh_tema_t(sdh_tema_mezo($p, 'szoveg'))) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    echo '</ul>';
}

echo sdh_tema_gombok(sdh_tema_cta($sz)); // phpcs:ignore WordPress.Security.EscapeOutput
echo '</div>';

if ($kep !== '') {
    echo '<figure class="s-kepszoveg__kep s-uveg">' . $kep . '</figure>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

echo '</div>';
echo sdh_tema_szekcio_zar($sz, false); // phpcs:ignore WordPress.Security.EscapeOutput
