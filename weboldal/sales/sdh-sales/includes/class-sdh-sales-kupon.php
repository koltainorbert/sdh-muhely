<?php
/**
 * SDH Értékesítés – kupon ellenőrzése egy kosárra (POST /kupon). A kuponok a tartalom-tárban (`kupon` típus, az admin
 * szerkeszti); mezők: kod, tipus ('szazalek'|'fix'), ertek, lejar ('Y-m-d'|null), kezd?, max (felhasználások)|null,
 * min (nettó Ft)?, hatokor ('mind'|'csomag'|'modul'|'szolgaltatas'| [kulcsok/típusok]), ciklus? ('havi'|'eves'), aktiv?.
 * A kuponlista soha nem kerül a frontra; a kód kitalálását a szigorú korlát fékezi.
 *
 *   ellenoriz(string $kod, array $tetelek, array $opciok): array{ok, kod, kedvezmeny, uzenet, ar}
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Kupon
{
    public static function init(): void
    {
        SDH_Sales_Rest::ut('/kupon', 'POST', [self::class, 'rest'], 'nyilvanos', ['kupon', 20, 600]);
    }

    /**
     * @param array<int, mixed>    $tetelek
     * @param array<string, mixed> $opciok
     * @return array{ok: bool, kod: string, kedvezmeny: int, uzenet: string, ar: array<string, mixed>}
     */
    public static function ellenoriz(string $kod, array $tetelek, array $opciok = []): array
    {
        $kod = strtoupper(trim($kod));

        if ($kod === '' || preg_match('/^[A-Z0-9_-]{2,40}$/', $kod) !== 1) {
            $ar = SDH_Sales_Ar::szamol($tetelek, array_diff_key($opciok, ['kupon' => 1]));

            return ['ok' => false, 'kod' => $kod, 'kedvezmeny' => 0, 'uzenet' => 'Érvénytelen kuponkód.', 'ar' => $ar];
        }

        $alap = SDH_Sales_Ar::szamol($tetelek, array_diff_key($opciok, ['kupon' => 1]));
        $ar   = SDH_Sales_Ar::szamol($tetelek, ['kupon' => $kod] + $opciok);
        $sajat = array_values(array_diff($ar['hibak'], $alap['hibak']));

        if ($ar['kupon'] === null) {
            SDH_Sales_Naplo::ir('kupon', 'Sikertelen kuponpróba: ' . $kod, 'info');

            return ['ok' => false, 'kod' => $kod, 'kedvezmeny' => 0, 'uzenet' => $sajat[0] ?? 'A kupon erre a kosárra nem érvényes.', 'ar' => $alap];
        }

        return ['ok' => true, 'kod' => $kod, 'kedvezmeny' => (int) $ar['kedvezmeny'], 'uzenet' => 'A kupon érvényes: ' . SDH_Sales::ft((int) $ar['kedvezmeny']) . ' kedvezmény.', 'ar' => $ar];
    }

    public static function rest(WP_REST_Request $r): WP_REST_Response
    {
        $o = SDH_Sales_Ar::opciok_tisztit($r->get_param('opciok'));
        unset($o['kupon']);

        return new WP_REST_Response(self::ellenoriz(
            sanitize_text_field((string) $r->get_param('kod')),
            SDH_Sales_Ar::tetelek_tisztit($r->get_param('tetelek')),
            $o
        ), 200);
    }
}
