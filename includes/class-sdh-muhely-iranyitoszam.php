<?php
/**
 * Irányítószám–település lista.
 *
 * Az ügyfélűrlap irányítószám- és településmezője ebből tölti ki egymást:
 * irányítószámra beírja a települést, településre az irányítószámot, és
 * több lehetőségnél (pl. Budapest, vagy több településnek közös a kódja)
 * listából lehet választani.
 *
 * Az adat a data/iranyitoszam.csv.gz fájlban van (forrás és licenc:
 * data/iranyitoszam-forras.txt). A böngésző egyszer tölti le a teljes listát
 * (kb. 100 KB), utána minden keresés helyben megy – így gépelés közben nincs
 * várakozás, és a lista ugyanúgy működik lassú vagy ingadozó kapcsolaton is.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Iranyitoszam
{
    private const FAJL = 'data/iranyitoszam.csv.gz';

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_iranyitoszamok', [self::class, 'ajax_lista']);
    }

    /**
     * A teljes lista a fájlból.
     *
     * @return array{megyek: array<int, string>, sorok: array<int, array{0: string, 1: string, 2: int}>}
     */
    public static function lista(): array
    {
        $ut = SDH_MUHELY_DIR . self::FAJL;

        $ures = ['megyek' => [], 'sorok' => []];

        if (!is_readable($ut) || !function_exists('gzdecode')) {
            return $ures;
        }

        $nyers = file_get_contents($ut);
        $szoveg = $nyers !== false ? gzdecode($nyers) : false;

        if (!is_string($szoveg) || $szoveg === '') {
            return $ures;
        }

        $megyek = [];
        $sorok  = [];

        foreach (preg_split('/\R/u', $szoveg) ?: [] as $szam => $sor) {
            // Az első sor a fejléc.
            if ($szam === 0 || $sor === '') {
                continue;
            }

            $reszek = explode(';', $sor);

            if (count($reszek) < 3 || !preg_match('/^\d{4}$/', $reszek[0])) {
                continue;
            }

            $megye = array_search($reszek[2], $megyek, true);

            if ($megye === false) {
                $megyek[] = $reszek[2];
                $megye    = count($megyek) - 1;
            }

            $sorok[] = [$reszek[0], $reszek[1], (int) $megye];
        }

        return ['megyek' => $megyek, 'sorok' => $sorok];
    }

    /**
     * A teljes lista JSON-ban a böngészőnek.
     *
     * A fejléc magánjellegű gyorsítótárat enged: a lista ritkán változik,
     * a böngésző napokig nem kéri újra.
     */
    public static function ajax_lista(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $adat = self::lista();

        if ($adat['sorok'] === []) {
            wp_send_json_error(['uzenet' => 'Az irányítószám-lista nem olvasható.'], 500);
        }

        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        header('Cache-Control: private, max-age=86400');
        header_remove('Expires');
        header_remove('Pragma');

        echo wp_json_encode(['success' => true, 'data' => $adat]);
        wp_die();
    }
}
