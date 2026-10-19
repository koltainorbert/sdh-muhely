<?php
/**
 * Szekció: ar_tablazat – a csomagok tételes összevetése (díjak, keretek, modulok csoportonként).
 * Mezők: cim, alcim, csomagok[] (üres: mind), csak_kulonbseg (bool). Vízszintesen görgethető tábla, ragadós első oszlop.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$csomagok = sdh_tema_csomagok(sdh_tema_szovegek($sz, 'csomagok'));

if ($csomagok === []) {
    echo sdh_tema_admin_megjegyzes('ar_tablazat: nincs csomag'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

$kul    = !empty($sz['csak_kulonbseg']);
$igen   = '<span class="s-jel s-jel--igen">' . sdh_tema_ikon('pipa') . '<span class="s-sr">' . esc_html(sdh_tema__('Igen')) . '</span></span>';
$nem    = '<span class="s-jel s-jel--nem">' . sdh_tema_ikon('minusz') . '<span class="s-sr">' . esc_html(sdh_tema__('Nem')) . '</span></span>';
$ft     = static fn (int $n): string => $n > 0 ? esc_html(sdh_tema_ft($n)) : '<span class="s-halvany">—</span>';
$db     = static fn (int $n): string => $n > 0 ? esc_html((string) $n) : '<span class="s-halvany">—</span>';
$sorok  = [];

$sorok[] = ['csoport', sdh_tema__('Díjak (nettó)')];
$sorok[] = [sdh_tema__('Havi díj'), array_map(static fn ($c) => $ft((int) ($c['havi'] ?? 0)), $csomagok)];
$sorok[] = [sdh_tema__('Éves díj'), array_map(static fn ($c) => $ft((int) ($c['eves'] ?? 0)), $csomagok)];
$sorok[] = ['csoport', sdh_tema__('Keretek')];
$sorok[] = [sdh_tema__('Felhasználó (benne)'), array_map(static fn ($c) => $db((int) ($c['felhasznalo_alap'] ?? 0)), $csomagok)];
$sorok[] = [sdh_tema__('Extra felhasználó / hó'), array_map(static fn ($c) => $ft((int) ($c['felhasznalo_extra_havi'] ?? 0)), $csomagok)];
$sorok[] = [sdh_tema__('Telephely (benne)'), array_map(static fn ($c) => $db((int) ($c['telephely_alap'] ?? 0)), $csomagok)];
$sorok[] = [sdh_tema__('Extra telephely / hó'), array_map(static fn ($c) => $ft((int) ($c['telephely_extra_havi'] ?? 0)), $csomagok)];
$sorok[] = [sdh_tema__('Telepíthető példány'), array_map(static fn ($c) => $db((int) ($c['max_peldany'] ?? 0)), $csomagok)];

$cs = [];

foreach (sdh_tema_modulok() as $m) {
    $cs[sdh_tema_t((string) ($m['csoport'] ?? sdh_tema__('Modulok')))][] = $m;
}

foreach ($cs as $csnev => $mods) {
    $sorok[] = ['csoport', $csnev];

    foreach ($mods as $m) {
        $sorok[] = [sdh_tema_t((string) $m['nev']), array_map(static fn ($c) => sdh_tema_benne($c, $m) ? $igen : $nem, $csomagok)];
    }
}

if ($kul) {
    $sorok = array_values(array_filter($sorok, static fn ($s) => $s[0] === 'csoport' || count(array_unique($s[1])) > 1));
    // Üresen maradt csoportcímek ki.
    $sorok = array_values(array_filter($sorok, static function ($s, $i) use ($sorok) {
        return $s[0] !== 'csoport' || (isset($sorok[$i + 1]) && $sorok[$i + 1][0] !== 'csoport');
    }, ARRAY_FILTER_USE_BOTH));
}

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput
echo '<div class="s-tabla-gorgeto" tabindex="0" role="region" aria-label="' . esc_attr(sdh_tema__('Csomagok összehasonlítása (vízszintesen görgethető)')) . '">';
echo '<table class="s-tabla s-tabla--osszevet"><caption class="s-sr">' . esc_html(sdh_tema__('Csomagok összehasonlítása')) . '</caption>';
echo '<thead><tr><th scope="col" class="s-tabla__elso">' . esc_html(sdh_tema__('Szempont')) . '</th>';

foreach ($csomagok as $c) {
    echo '<th scope="col"' . (!empty($c['kiemelt']) ? ' class="s-tabla__kiemelt"' : '') . '>' . esc_html(sdh_tema_t((string) $c['nev'])) . '</th>';
}

echo '</tr></thead><tbody>';

foreach ($sorok as $s) {
    if ($s[0] === 'csoport') {
        echo '<tr class="s-tabla__csoport"><th scope="colgroup" colspan="' . (count($csomagok) + 1) . '">' . esc_html($s[1]) . '</th></tr>';
        continue;
    }

    echo '<tr><th scope="row" class="s-tabla__elso">' . esc_html($s[0]) . '</th>';

    foreach (array_values($s[1]) as $i => $cella) {
        echo '<td>' . $cella . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fent escapelve
    }

    echo '</tr>';
}

echo '</tbody></table></div>';
echo '<p class="s-tabla__megj">' . esc_html(sprintf(/* translators: %d: áfa */ sdh_tema__('Az árak nettók, + %d%% áfa.'), sdh_tema_afa())) . '</p>';
echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
