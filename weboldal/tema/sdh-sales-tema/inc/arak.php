<?php
/**
 * SDH Értékesítés téma – ár-megjelenítés (csak megjelenítés: az árat a rendelésnél mindig a szerver számolja).
 * Minden ár a tartalom-tárból (csomag, modul, szolgaltatas) jön, nettó egész forint; a bruttó az áfa-beállításból.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Csomagok kulcs szerint, adatmezőkkel (kulcs, nev…), sorrendben; $kulcsok: csak ezek, ebben a sorrendben.
 *
 * @param array<int, string> $kulcsok
 * @return array<string, array<string, mixed>>
 */
function sdh_tema_csomagok(array $kulcsok = []): array
{
    $ki = [];

    foreach (sdh_tema_lista('csomag') as $e) {
        $a          = (array) $e['adat'];
        $k          = (string) $e['kulcs'];
        $a['kulcs'] = $k;
        $a['nev']   = (string) ($a['nev'] ?? $e['cim']);
        $ki[$k]     = $a;
    }

    if ($kulcsok !== []) {
        $sor = [];

        foreach ($kulcsok as $k) {
            if (isset($ki[$k])) {
                $sor[$k] = $ki[$k];
            }
        }

        return $sor;
    }

    return $ki;
}

/** @return array<string, array<string, mixed>> modulok kulcs szerint */
function sdh_tema_modulok(): array
{
    static $m = null;

    if ($m === null) {
        $m = [];

        foreach (sdh_tema_lista('modul') as $e) {
            $a          = (array) $e['adat'];
            $a['kulcs'] = (string) $e['kulcs'];
            $a['nev']   = (string) ($a['nev'] ?? $e['cim']);
            $m[(string) $e['kulcs']] = $a;
        }
    }

    return $m;
}

/** Benne van-e a modul a csomagban (csomag.modulok – „*” = mind –, vagy modul.csomagban). @param array<string, mixed> $cs @param array<string, mixed> $mod */
function sdh_tema_benne(array $cs, array $mod): bool
{
    $l = (array) ($cs['modulok'] ?? []);

    if (in_array('*', $l, true) || in_array((string) ($mod['kulcs'] ?? ''), $l, true)) {
        return true;
    }

    return in_array((string) ($cs['kulcs'] ?? ''), (array) ($mod['csomagban'] ?? []), true);
}

/** Éves kedvezmény %-a a havi×12-höz képest (az adatból számolva; 0, ha nincs). @param array<string, mixed> $cs */
function sdh_tema_eves_kedvezmeny(array $cs): int
{
    $h = (int) ($cs['havi'] ?? 0);
    $e = (int) ($cs['eves'] ?? 0);

    if ($h <= 0 || $e <= 0 || $e >= $h * 12) {
        return 0;
    }

    return (int) round((1 - $e / ($h * 12)) * 100);
}

/**
 * Egy ár blokkja: nagy nettó + „+ áfa” + bruttó sor. $per: „/ hó”, „/ év” vagy ''.
 */
function sdh_tema_ar_blokk(int $netto, string $per, string $megj = ''): string
{
    $ki = '<p class="s-ar"><span class="s-ar__osszeg">' . esc_html(sdh_tema_ft($netto)) . '</span>';

    if ($per !== '') {
        $ki .= '<span class="s-ar__per">' . esc_html($per) . '</span>';
    }

    $ki .= '</p><p class="s-ar__afa">'
        . esc_html(sprintf(
            /* translators: 1: áfa %, 2: bruttó összeg */
            sdh_tema__('nettó, + %1$d%% áfa = %2$s bruttó'),
            sdh_tema_afa(),
            sdh_tema_ft(sdh_tema_brutto($netto))
        ))
        . '</p>';

    if ($megj !== '') {
        $ki .= '<p class="s-ar__megj">' . esc_html($megj) . '</p>';
    }

    return $ki;
}

/** A kosár / pénztár oldal URL-je (beállítás: oldal.kosar, oldal.penztar). */
function sdh_tema_kosar_url(string $mi = 'kosar'): string
{
    $slug = trim((string) sdh_tema_b('oldal.' . $mi, $mi), '/');

    return home_url(user_trailingslashit($slug !== '' ? $slug : $mi));
}
