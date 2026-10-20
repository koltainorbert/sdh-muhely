<?php
/**
 * Szekció: modul_racs – modulkártyák a `modul` elemekből, csoportonként (csoportositva, alapból igaz).
 * Mezők: cim, alcim, kulcsok[], csoport, csoportositva, arak (bool – nettó havi ár a kártyán), db.
 *
 * @package SDH_Sales_Tema
 * @var array<string, mixed> $sz
 */

if (!defined('ABSPATH')) {
    exit;
}

$elemek = sdh_tema_elem_szur(sdh_tema_lista('modul'), $sz);

if ($elemek === []) {
    echo sdh_tema_admin_megjegyzes('modul_racs: nincs modul-elem'); // phpcs:ignore WordPress.Security.EscapeOutput
    return;
}

$csop = !array_key_exists('csoportositva', $sz) || !empty($sz['csoportositva']);
$arak = !empty($sz['arak']);
$cs   = [];

foreach ($elemek as $e) {
    $nev        = $csop ? sdh_tema_t((string) ($e['adat']['csoport'] ?? '')) : '';
    $cs[$nev][] = $e;
}

$csomagok = sdh_tema_csomagok();

echo sdh_tema_szekcio_nyit($sz); // phpcs:ignore WordPress.Security.EscapeOutput

foreach ($cs as $csnev => $lista) {
    if ($csnev !== '') {
        echo '<h3 class="s-csoportcim">' . esc_html($csnev) . '</h3>';
    }

    echo '<ul class="s-racs s-racs--3" role="list">';

    foreach ($lista as $e) {
        $a   = (array) $e['adat'];
        $k   = (string) $e['kulcs'];
        $url = sdh_tema_elem_url($e, 'modulok');
        $nev = sdh_tema_elem_nev($e);

        echo '<li class="s-kartya s-kartya--modul" id="' . esc_attr('modul-' . sanitize_title($k)) . '"' . sdh_tema_elem_szerk($e) . '>';
        echo '<div class="s-kartya__fej"><span class="s-ikondoboz s-ikondoboz--kicsi">' . sdh_tema_ikon((string) ($a['ikon'] ?? 'modul')) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<h4 class="s-kartya__cim"' . sdh_tema_elem_szerk($e, 'nev') . '>' . ($url !== '' ? '<a class="s-kartya__link" href="' . esc_url($url) . '">' . esc_html($nev) . '</a>' : esc_html($nev)) . '</h4></div>';

        $l = sdh_tema_elem_leiras($e, 200);

        if ($l !== '') {
            echo '<p class="s-kartya__szoveg"' . sdh_tema_elem_szerk($e, 'leiras') . '>' . esc_html($l) . '</p>';
        }

        $benne = [];

        foreach ($csomagok as $c) {
            if (sdh_tema_benne($c, $a + ['kulcs' => $k])) {
                $benne[] = sdh_tema_t((string) $c['nev']);
            }
        }

        echo '<div class="s-kartya__lab">';

        if ($benne !== []) {
            echo '<p class="s-pillsor"><span class="s-sr">' . esc_html(sdh_tema__('Benne van:')) . '</span>';

            foreach ($benne as $b) {
                echo '<span class="s-pill s-pill--kicsi">' . esc_html($b) . '</span>';
            }

            echo '</p>';
        }

        if ($arak && (int) ($a['havi'] ?? 0) > 0) {
            echo '<p class="s-kartya__ar"' . sdh_tema_elem_szerk($e, 'havi') . '>' . esc_html(sprintf(/* translators: %s: nettó havi ár */ sdh_tema__('külön: %s / hó + áfa'), sdh_tema_ft((int) $a['havi']))) . '</p>';
        }

        echo '</div></li>';
    }

    echo '</ul>';
}

echo sdh_tema_szekcio_zar($sz); // phpcs:ignore WordPress.Security.EscapeOutput
