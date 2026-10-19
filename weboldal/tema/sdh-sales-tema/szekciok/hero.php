<?php
/**
 * Szekció: hero – nagy nyitó. Mezők: cimke|eyebrow, cim, alcim, gomb, gomb2, cta[], kep (manifest-kulcs / médiatár / URL),
 * kep_alt, elrendezes (kozep|bal|kep_jobb), pontok [{szoveg}]. Az oldal első hero-ja viszi a H1-et.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$elr = sdh_tema_mezo($sz, 'elrendezes', 'kep_jobb');
$elr = in_array($elr, ['kozep', 'bal', 'kep_jobb'], true) ? $elr : 'kep_jobb';
$kep = sdh_tema_mezo($sz, 'kep');

if ($kep === '' && $elr === 'kep_jobb' && (int) ($sz['_i'] ?? 0) === 0 && sdh_tema_van_kep('hero')) {
    $kep = 'hero';
}

if ($kep === '' && $elr === 'kep_jobb') {
    $elr = 'bal';
}

$h1    = !sdh_tema_h1();
$szint = $h1 ? 'h1' : 'h2';
sdh_tema_h1(true);

$id    = sdh_tema_szekcio_id($sz);
$cimid = $id . '-cim';
$pont  = sdh_tema_elemek($sz, 'pontok');

echo '<section id="' . esc_attr($id) . '" class="' . esc_attr('s-szekcio s-hero s-hero--' . $elr . ' s-hatter--' . sdh_tema_hatter($sz)) . '" aria-labelledby="' . esc_attr($cimid) . '">';
echo '<div class="s-hero__aurora" aria-hidden="true"></div>';
echo '<div class="s-kont s-hero__racs">';
echo '<div class="s-hero__szoveg">';

$eb = sdh_tema_eyebrow($sz);

if ($eb !== '') {
    echo '<p class="s-eyebrow s-eyebrow--pill">' . sdh_tema_ikon('szikra') . esc_html(sdh_tema_t($eb)) . '</p>';
}

echo '<' . $szint . ' id="' . esc_attr($cimid) . '" class="s-hero__cim">' . esc_html(sdh_tema_t(sdh_tema_mezo($sz, 'cim', sdh_tema_termeknev()))) . '</' . $szint . '>';

$alcim = sdh_tema_mezo($sz, 'alcim');

if ($alcim !== '') {
    echo '<p class="s-hero__alcim">' . esc_html(sdh_tema_t($alcim)) . '</p>';
}

echo sdh_tema_gombok(sdh_tema_cta($sz), 's-hero__gombok'); // phpcs:ignore WordPress.Security.EscapeOutput -- escapelve

if ($pont !== []) {
    echo '<ul class="s-hero__pontok">';

    foreach ($pont as $p) {
        $t = sdh_tema_mezo($p, 'szoveg');

        if ($t !== '') {
            echo '<li>' . sdh_tema_ikon('pipa') . '<span>' . esc_html(sdh_tema_t($t)) . '</span></li>';
        }
    }

    echo '</ul>';
}

echo '</div>';

if ($kep !== '' && $elr !== 'kozep') {
    echo '<div class="s-hero__kep s-uveg">' . sdh_tema_kep_mezo($kep, 'hero', ['class' => 's-hero__img'], sdh_tema_mezo($sz, 'kep_alt')) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

echo '</div></section>';
