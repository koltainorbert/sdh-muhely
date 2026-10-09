<?php
/**
 * QR-kód és vonalkód SVG-ben.
 *
 * Mindkettő a szerveren készül, külső szolgáltatás és JavaScript nélkül,
 * így offline is működik, nyomtatáskor éles marad, és a CRM bármelyik
 * részébe (részletpanel, címke, RMA-oldal) ugyanúgy beilleszthető.
 *
 *  - QR: a Kazuhiko Arase-féle kódoló (MIT, includes/lib/qrcode.php),
 *    M szintű hibajavítással – egy kis karcolás mellett is leolvasható.
 *  - Vonalkód: Code 128 (B készlet, csupa számjegynél a tömörebb C).
 *    Minden kézi vonalkódolvasó ismeri, a munkalapszám előtagját is viszi.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Kodok
{
    /**
     * A Code 128 mintái (vonal-köz szélességek), 0–105 + stop (106).
     */
    private const C128 = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;
    private const START_C = 105;
    private const STOP    = 106;

    /* =================================================================
     * QR-kód
     * ============================================================== */

    /**
     * A QR-kód moduljai (true = sötét), vagy null, ha nem kódolható.
     *
     * @return array<int, array<int, bool>>|null
     */
    public static function qr_matrix(string $szoveg): ?array
    {
        if ($szoveg === '') {
            return null;
        }

        require_once SDH_MUHELY_DIR . 'includes/lib/qrcode.php';

        try {
            $qr = \SDH_Muhely\Qr\QRCode::getMinimumQRCode($szoveg, \SDH_Muhely\Qr\QR_ERROR_CORRECT_LEVEL_M);
        } catch (\Throwable $e) {
            return null;
        }

        $n   = $qr->getModuleCount();
        $sor = [];

        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $sor[$r][$c] = (bool) $qr->isDark($r, $c);
            }
        }

        return $sor;
    }

    /**
     * QR-kód SVG-ben. A méret CSS-ből jön (viewBox), a csendes zóna 4 modul.
     */
    public static function qr_svg(string $szoveg, string $osztaly = 'sdh-qr'): string
    {
        $m = self::qr_matrix($szoveg);

        if ($m === null) {
            return '';
        }

        $n    = count($m);
        $zona = 4;
        $meret = $n + 2 * $zona;
        $ut   = '';

        // Soronként összevont vízszintes szakaszok: kisebb SVG, nincs hajszálrés.
        foreach ($m as $r => $sor) {
            $c = 0;

            while ($c < $n) {
                if (!$sor[$c]) {
                    $c++;
                    continue;
                }

                $kezd = $c;

                while ($c < $n && $sor[$c]) {
                    $c++;
                }

                $ut .= 'M' . ($kezd + $zona) . ' ' . ($r + $zona) . 'h' . ($c - $kezd) . 'v1h-' . ($c - $kezd) . 'z';
            }
        }

        return sprintf(
            '<svg class="%s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" shape-rendering="crispEdges" role="img" aria-label="QR-kód">'
            . '<rect width="%d" height="%d" fill="#fff"/><path d="%s" fill="#000"/></svg>',
            esc_attr($osztaly),
            $meret,
            $meret,
            $meret,
            $meret,
            $ut
        );
    }

    /* =================================================================
     * Vonalkód (Code 128)
     * ============================================================== */

    /**
     * A kódolt értékek sorozata (start + adat + ellenőrző + stop),
     * vagy null, ha a szöveg nem kódolható (ékezet, vezérlőkarakter).
     *
     * @return array<int, int>|null
     */
    public static function code128_ertekek(string $szoveg): ?array
    {
        if ($szoveg === '' || preg_match('/^[\x20-\x7E]+$/', $szoveg) !== 1) {
            return null;
        }

        $ertekek = [];

        if (preg_match('/^\d+$/', $szoveg) === 1 && strlen($szoveg) % 2 === 0 && strlen($szoveg) >= 2) {
            $ertekek[] = self::START_C;

            foreach (str_split($szoveg, 2) as $par) {
                $ertekek[] = (int) $par;
            }
        } else {
            $ertekek[] = self::START_B;

            foreach (str_split($szoveg) as $karakter) {
                $ertekek[] = ord($karakter) - 32;
            }
        }

        $osszeg = $ertekek[0];

        for ($i = 1, $db = count($ertekek); $i < $db; $i++) {
            $osszeg += $ertekek[$i] * $i;
        }

        $ertekek[] = $osszeg % 103;
        $ertekek[] = self::STOP;

        return $ertekek;
    }

    /**
     * Code 128 vonalkód SVG-ben, alatta (kérésre) a szöveggel.
     */
    public static function vonalkod_svg(string $szoveg, bool $felirat = true, string $osztaly = 'sdh-vonalkod'): string
    {
        $ertekek = self::code128_ertekek($szoveg);

        if ($ertekek === null) {
            return '';
        }

        $zona  = 10;          // csendes zóna mindkét oldalon (modulban)
        $magas = 50;
        $x     = $zona;
        $ut    = '';

        foreach ($ertekek as $ertek) {
            $minta = self::C128[$ertek];

            foreach (str_split($minta) as $i => $szel) {
                $szel = (int) $szel;

                if ($i % 2 === 0) {
                    $ut .= 'M' . $x . ' 0h' . $szel . 'v' . $magas . 'h-' . $szel . 'z';
                }

                $x += $szel;
            }
        }

        $szeles = $x + $zona;
        $teljes = $felirat ? $magas + 14 : $magas;

        $szoveg_elem = $felirat
            ? sprintf(
                '<text x="%d" y="%d" text-anchor="middle" font-family="ui-monospace,Menlo,Consolas,monospace" font-size="12" fill="#000">%s</text>',
                (int) round($szeles / 2),
                $magas + 12,
                esc_html($szoveg)
            )
            : '';

        return sprintf(
            '<svg class="%s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" shape-rendering="crispEdges" role="img" aria-label="%s">'
            . '<rect width="%d" height="%d" fill="#fff"/><path d="%s" fill="#000"/>%s</svg>',
            esc_attr($osztaly),
            $szeles,
            $teljes,
            esc_attr('Vonalkód: ' . $szoveg),
            $szeles,
            $teljes,
            $ut,
            $szoveg_elem
        );
    }
}
