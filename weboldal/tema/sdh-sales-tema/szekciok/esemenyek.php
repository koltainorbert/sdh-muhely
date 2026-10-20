<?php
/**
 * Szekció: esemenyek – webináriumok / események az `esemeny` elemekből (ARCHITEKTURA.md §11). Az Event sémát a
 * bővítmény SEO-modulja adja; a horgony („esemeny-<kulcs>”) egyezik a séma URL-jével.
 * Elem: {cim|nev, kezdet, veg?, leiras?, url?, online?, helyszin?, ar?, kep?, jelentkezes_url?}.
 * Mezők: cim, alcim, kulcsok[], db, multbeli (bool: a lezajlottak is, külön listában).
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$most    = time();
$jovo    = [];
$mult    = [];

foreach (sdh_tema_elem_szur(sdh_tema_lista('esemeny'), $sz + ['db' => 0]) as $e) {
    $a = (array) $e['adat'];
    $k = strtotime((string) ($a['kezdet'] ?? ''));

    if ($k === false) {
        continue;
    }

    $v = strtotime((string) ($a['veg'] ?? '')) ?: $k + 3600;

    if ($v >= $most) {
        $jovo[] = [$k, $v, $e];
    } else {
        $mult[] = [$k, $v, $e];
    }
}

usort($jovo, static fn ($x, $y) => $x[0] <=> $y[0]);
usort($mult, static fn ($x, $y) => $y[0] <=> $x[0]);

$db = (int) ($sz['db'] ?? 0);

if ($db > 0) {
    $jovo = array_slice($jovo, 0, $db);
}

$kartya = static function (array $x): string {
    [$k, $v, $e] = $x;
    $a           = (array) $e['adat'];
    $kulcs       = sanitize_title((string) $e['kulcs']);
    $online      = !isset($a['online']) || (bool) $a['online'];
    $ar          = (int) ($a['ar'] ?? 0);
    $jel         = (string) ($a['jelentkezes_url'] ?? $a['url'] ?? '');
    $jel         = $jel !== '' ? sdh_tema_url($jel) : '';
    $mult        = $v < time();

    $ki  = '<li class="s-kartya s-esemeny' . ($mult ? ' s-esemeny--mult' : '') . '" id="' . esc_attr('esemeny-' . $kulcs) . '"' . sdh_tema_elem_szerk($e) . '>';
    $ki .= '<p class="s-esemeny__datum"><time datetime="' . esc_attr(wp_date('c', $k)) . '"><span class="s-esemeny__nap">' . esc_html(wp_date('j', $k)) . '</span><span class="s-esemeny__ho">' . esc_html(wp_date('M', $k)) . '</span></time></p>';
    $ki .= '<div class="s-esemeny__test"><h3 class="s-kartya__cim"' . sdh_tema_elem_szerk($e, 'nev') . '>' . esc_html(sdh_tema_elem_nev($e)) . '</h3>';
    $ki .= '<p class="s-esemeny__meta">' . sdh_tema_ikon('ora') . esc_html(wp_date('Y. F j., l H:i', $k) . '–' . wp_date('H:i', $v)) . '</p>';
    $ki .= '<p class="s-esemeny__meta">' . sdh_tema_ikon($online ? 'globus' : 'helyszin') . esc_html($online ? sdh_tema__('Online') : sdh_tema_t((string) ($a['helyszin'] ?? ''))) . ' · ' . esc_html($ar > 0 ? sdh_tema_ft($ar) . ' + ' . sdh_tema__('áfa') : sdh_tema__('Ingyenes')) . '</p>';

    $l = sdh_tema_elem_leiras($e, 260);

    if ($l !== '') {
        $ki .= '<p class="s-kartya__szoveg"' . sdh_tema_elem_szerk($e, 'leiras') . '>' . esc_html($l) . '</p>';
    }

    if (!$mult && $jel !== '') {
        $ki .= '<a class="s-gomb s-gomb--elsodleges s-gomb--kicsi" href="' . esc_url($jel) . '">' . esc_html(sdh_tema__('Jelentkezem')) . '<span class="s-sr"> – ' . esc_html(sdh_tema_elem_nev($e)) . '</span></a>';
    }

    return $ki . '</div></li>';
};

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput

if ($jovo === []) {
    echo '<p class="s-doboz s-doboz--info">' . sdh_tema_ikon('naptar') . esc_html(sdh_tema__('Jelenleg nincs meghirdetett esemény. Nézz vissza később, vagy kérj egyéni bemutatót.')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
} else {
    echo '<ul class="s-esemenyek" role="list">' . implode('', array_map($kartya, $jovo)) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

if (!empty($sz['multbeli']) && $mult !== []) {
    echo '<details class="s-harmonika s-harmonika--kicsi"><summary class="s-harmonika__fej">' . esc_html(sdh_tema__('Korábbi események')) . sdh_tema_ikon('nyil-le', 's-harmonika__ikon') . '</summary><div class="s-harmonika__test"><ul class="s-esemenyek" role="list">' . implode('', array_map($kartya, array_slice($mult, 0, 12))) . '</ul></div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
