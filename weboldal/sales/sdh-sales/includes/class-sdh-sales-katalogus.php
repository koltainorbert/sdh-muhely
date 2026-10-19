<?php
/**
 * SDH Értékesítés – katalógus: csomagok, modulok, szolgáltatások, kuponok a tartalom-tárból (data/arak.json → `tartalom`).
 *
 * Nyilvános API:
 *   csomagok() / modulok() / szolgaltatasok()   → [kulcs => adat] (aktívak, sorrendben)
 *   csomag(k) / modul(k) / szolgaltatas(k)      → adat vagy null
 *   csomag_modulok(csomag)                     → a csomagban lévő modulkulcsok, vagy ['*'] (minden modul)
 *   benne(csomag, modul)                       → bool
 *   kupon(kod)                                 → kupon-adat (kod nagybetűs) vagy null
 *   kat()                                      → a tiszta árszámítás bemenete (SDH_Sales_Ar::szamol_tiszta)
 *   publikus()                                 → a front (kalkulátor, kosár) adata – kupon NINCS benne
 * A modulkulcsok a Műhely modulkulcsai (MUHELY_MODULOK), így a licenc a megvett modulokat engedélyezi.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Katalogus
{
    /** A Műhely modulkulcsai (SDH_Kozpont::KATALOGUS_ALAP / SDH_Muhely_Modulok::katalogus()). */
    public const MUHELY_MODULOK = [
        'termekek', 'arukiadas', 'rendelesek', 'penztar', 'szamlazas', 'uzenetek', 'sms', 'levelezes', 'csapat',
        'naptar', 'foglalas', 'asszisztens', 'nyomtatas', 'imei', 'tac', 'matrica', 'mockup',
    ];

    public static function init(): void
    {
        add_action('init', [self::class, 'front'], 6);
    }

    public static function front(): void
    {
        SDH_Sales_Front::modul('kalkulator', 'assets/front/kalkulator.js', ['kozos'], [self::class, 'publikus']);
    }

    /** @return array<string, array<string, mixed>> */
    private static function tipus(string $tipus): array
    {
        $ki = [];

        foreach (SDH_Sales_Tartalom::lista($tipus) as $e) {
            $a          = (array) $e['adat'];
            $k          = (string) ($a['kulcs'] ?? $e['kulcs']);
            $a['kulcs'] = $k;
            $a['nev']   = (string) ($a['nev'] ?? $e['cim']);
            $ki[$k]     = $a;
        }

        return $ki;
    }

    /** @return array<string, array<string, mixed>> */
    public static function csomagok(): array
    {
        return self::tipus('csomag');
    }

    /** @return array<string, array<string, mixed>> */
    public static function modulok(): array
    {
        return self::tipus('modul');
    }

    /** @return array<string, array<string, mixed>> */
    public static function szolgaltatasok(): array
    {
        return self::tipus('szolgaltatas');
    }

    public static function csomag(string $k): ?array
    {
        return self::csomagok()[$k] ?? null;
    }

    public static function modul(string $k): ?array
    {
        return self::modulok()[$k] ?? null;
    }

    public static function szolgaltatas(string $k): ?array
    {
        return self::szolgaltatasok()[$k] ?? null;
    }

    /** @return array<int, string> a csomag moduljai; ['*'] = minden modul */
    public static function csomag_modulok(string $csomag): array
    {
        $c = self::csomag($csomag);

        if ($c === null) {
            return [];
        }

        $m = array_values(array_map('strval', (array) ($c['modulok'] ?? [])));

        if (in_array('*', $m, true)) {
            return ['*'];
        }

        foreach (self::modulok() as $k => $mod) {
            if (in_array($csomag, array_map('strval', (array) ($mod['csomagban'] ?? [])), true)) {
                $m[] = $k;
            }
        }

        return array_values(array_unique($m));
    }

    public static function benne(string $csomag, string $modul): bool
    {
        $m = self::csomag_modulok($csomag);

        return in_array('*', $m, true) || in_array($modul, $m, true);
    }

    /** @return array<string, mixed>|null */
    public static function kupon(string $kod): ?array
    {
        $kod = strtoupper(trim($kod));

        if ($kod === '' || preg_match('/^[A-Z0-9_-]{2,40}$/', $kod) !== 1) {
            return null;
        }

        foreach (SDH_Sales_Tartalom::lista('kupon') as $e) {
            $a = (array) $e['adat'];

            if (strtoupper((string) ($a['kod'] ?? $e['kulcs'])) === $kod) {
                $a['kod'] = $kod;

                return $a;
            }
        }

        return null;
    }

    /** A tiszta árszámítás katalógusa (kupon nélkül – azt a hívó teszi bele). @return array<string, mixed> */
    public static function kat(): array
    {
        return [
            'csomagok'        => self::csomagok(),
            'modulok'         => self::modulok(),
            'szolgaltatasok'  => self::szolgaltatasok(),
            'eves_kedvezmeny' => (int) SDH_Sales::b('eves_kedvezmeny', 0),
            'afa'             => SDH_Sales::afa(),
            'ma'              => wp_date('Y-m-d'),
        ];
    }

    /** A front adata (kalkulátor, kosár, pénztár). Kuponlista soha nem megy ki. @return array<string, mixed> */
    public static function publikus(): array
    {
        $k = self::kat();
        $t = static fn (array $l): array => array_values(array_map(static function (array $e): array {
            unset($e['belso'], $e['megjegyzes_belso']);

            return $e;
        }, $l));

        return [
            'csomagok'        => $t($k['csomagok']),
            'modulok'         => $t($k['modulok']),
            'szolgaltatasok'  => $t($k['szolgaltatasok']),
            'eves_kedvezmeny' => $k['eves_kedvezmeny'],
            'afa'             => $k['afa'],
            'ma'              => $k['ma'],
            'kosar_url'       => home_url(user_trailingslashit((string) SDH_Sales::b('oldal.kosar', 'kosar'))),
            'penztar_url'     => home_url(user_trailingslashit((string) SDH_Sales::b('oldal.penztar', 'penztar'))),
        ];
    }
}
