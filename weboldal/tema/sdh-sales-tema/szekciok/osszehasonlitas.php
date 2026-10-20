<?php
/**
 * Szekció: osszehasonlitas – összevetés más rendszerekkel.
 *  a) `sorok` [{szempont, mi, mas}] megadva → háromoszlopos tábla (szabad szöveg).
 *  b) különben mátrix a `konkurens` elemekből (konkurensek[] – üres: mind, legfeljebb `db`, alap 5): induló ár,
 *     magyar felület, Számlázz.hu, internet nélküli működés, saját szerver, AI, kétlépcsős belépés, GDPR-anonimizálás,
 *     ingyenes kipróbálás. A saját oszlop: a Műhely meglévő funkciói (README-ben igazolt) + a legolcsóbb csomag ára.
 *     Forrás és ellenőrzés dátuma a konkurens adatából.
 * Mezők: cim, alcim, konkurensek[], sorok[], megjegyzes, db.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$sorok = sdh_tema_elemek($sz, 'sorok');
$jel   = static function ($v): string {
    if ($v === true || $v === 'igen' || $v === 1) {
        return '<span class="s-jel s-jel--igen">' . sdh_tema_ikon('pipa') . '<span class="s-sr">' . esc_html(sdh_tema__('Igen')) . '</span></span>';
    }

    if ($v === false || $v === 'nem' || $v === 0) {
        return '<span class="s-jel s-jel--nem">' . sdh_tema_ikon('x') . '<span class="s-sr">' . esc_html(sdh_tema__('Nem')) . '</span></span>';
    }

    if ($v === null || $v === '' || $v === 'ismeretlen') {
        return '<span class="s-halvany" title="' . esc_attr(sdh_tema__('Nyilvános forrásból nem derül ki')) . '">?</span>';
    }

    return esc_html(sdh_tema_t((string) $v));
};

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput

if ($sorok !== []) {
    echo '<div class="s-tabla-gorgeto" tabindex="0" role="region" aria-label="' . esc_attr(sdh_tema__('Összehasonlítás')) . '"><table class="s-tabla s-tabla--osszevet">';
    echo '<thead><tr><th scope="col" class="s-tabla__elso">' . esc_html(sdh_tema__('Szempont')) . '</th><th scope="col" class="s-tabla__kiemelt">' . esc_html(sdh_tema_termeknev()) . '</th><th scope="col">' . esc_html(sdh_tema__('Más rendszerek')) . '</th></tr></thead><tbody>';

    foreach ($sorok as $s) {
        $ei = (int) $s['_ei'];
        echo '<tr' . sdh_tema_szerk_mezo($sz, 'sorok', $ei) . '><th scope="row" class="s-tabla__elso"' . sdh_tema_szerk_mezo($sz, 'sorok', $ei, 'szempont') . '>' . esc_html(sdh_tema_t(sdh_tema_mezo($s, 'szempont'))) . '</th><td class="s-tabla__kiemelt"' . sdh_tema_szerk_mezo($sz, 'sorok', $ei, 'mi') . '>' . $jel(sdh_tema_mezo($s, 'mi')) . '</td><td' . sdh_tema_szerk_mezo($sz, 'sorok', $ei, 'mas') . '>' . $jel(sdh_tema_mezo($s, 'mas')) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    echo '</tbody></table></div>';
} else {
    $kon = sdh_tema_elem_szur(sdh_tema_lista('konkurens'), $sz + ['db' => (int) ($sz['db'] ?? 0) ?: 5]);

    if ($kon === []) {
        echo sdh_tema_admin_megjegyzes('osszehasonlitas: nincs konkurens-elem'); // phpcs:ignore WordPress.Security.EscapeOutput
    } else {
        $legolcsobb = 0;

        foreach (sdh_tema_csomagok() as $c) {
            $h = (int) ($c['havi'] ?? 0);

            if ($h > 0 && ($legolcsobb === 0 || $h < $legolcsobb)) {
                $legolcsobb = $h;
            }
        }

        $szempontok = [
            'ar_tol_huf'     => [sdh_tema__('Induló havi díj (nettó)'), $legolcsobb > 0 ? sdh_tema_ft($legolcsobb) : null],
            'magyar_nyelv'   => [sdh_tema__('Magyar felület'), true],
            'szamlazz_hu'    => [sdh_tema__('Számlázz.hu-kapcsolat'), true],
            'offline'        => [sdh_tema__('Internet nélkül is működik'), true],
            'sajat_szerver'  => [sdh_tema__('Saját szerveren / tárhelyen fut'), true],
            'ai'             => [sdh_tema__('AI-asszisztens'), true],
            'ketlepcsos'     => [sdh_tema__('Kétlépcsős belépés'), true],
            'gdpr_anonim'    => [sdh_tema__('GDPR-anonimizálás'), true],
            'ingyenes_proba' => [sdh_tema__('Kipróbálható vásárlás előtt'), true],
        ];

        echo '<div class="s-tabla-gorgeto" tabindex="0" role="region" aria-label="' . esc_attr(sdh_tema__('Összehasonlítás más rendszerekkel (vízszintesen görgethető)')) . '"><table class="s-tabla s-tabla--osszevet">';
        echo '<thead><tr><th scope="col" class="s-tabla__elso">' . esc_html(sdh_tema__('Szempont')) . '</th><th scope="col" class="s-tabla__kiemelt">' . esc_html(sdh_tema_termeknev()) . '</th>';

        foreach ($kon as $k) {
            $a   = (array) $k['adat'];
            $nev = sdh_tema_elem_nev($k);
            echo '<th scope="col"' . sdh_tema_elem_szerk($k, 'nev') . '>' . esc_html($nev) . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($szempontok as $mezo => [$felirat, $mi]) {
            echo '<tr><th scope="row" class="s-tabla__elso">' . esc_html($felirat) . '</th><td class="s-tabla__kiemelt">' . $jel($mi) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput

            foreach ($kon as $k) {
                $v = $k['adat'][$mezo] ?? null;

                if ($mezo === 'ar_tol_huf') {
                    $v = is_numeric($v) && (int) $v > 0 ? sdh_tema_ft((int) $v) : ((string) ($k['adat']['ar'] ?? '') ?: null);
                }

                echo '<td>' . $jel($v) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }

            echo '</tr>';
        }

        echo '</tbody></table></div>';

        // Források (átláthatóság): a konkurensek oldala + ellenőrzés dátuma.
        echo '<details class="s-harmonika s-harmonika--kicsi"><summary class="s-harmonika__fej">' . esc_html(sdh_tema__('Források és megjegyzések')) . sdh_tema_ikon('nyil-le', 's-harmonika__ikon') . '</summary><div class="s-harmonika__test"><ul class="s-forraslista">'; // phpcs:ignore WordPress.Security.EscapeOutput

        foreach ($kon as $k) {
            $a   = (array) $k['adat'];
            $src = (string) ($a['forras_url'] ?? $a['url'] ?? '');
            echo '<li><strong>' . esc_html(sdh_tema_elem_nev($k)) . '</strong>';

            if (!empty($a['megjegyzes'])) {
                echo ' – ' . esc_html(wp_html_excerpt(sdh_tema_t((string) $a['megjegyzes']), 260, '…'));
            }

            if ($src !== '' && preg_match('#^https?://#', $src)) {
                echo ' <a href="' . esc_url($src) . '" rel="noopener nofollow" target="_blank">' . esc_html(sdh_tema__('forrás')) . '</a>';
            }

            if (!empty($a['ellenorizve'])) {
                echo ' <span class="s-halvany">(' . esc_html(sprintf(/* translators: %s: dátum */ sdh_tema__('ellenőrizve: %s'), (string) $a['ellenorizve'])) . ')</span>';
            }

            echo '</li>';
        }

        echo '</ul></div></details>';
    }
}

$megj = sdh_tema_mezo($sz, 'megjegyzes');

if ($megj !== '') {
    echo '<p class="s-tabla__megj"' . sdh_tema_szerk_mezo($sz, 'megjegyzes') . '>' . esc_html(sdh_tema_t($megj)) . '</p>';
}

echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
