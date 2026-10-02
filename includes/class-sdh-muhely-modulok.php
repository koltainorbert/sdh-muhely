<?php
/**
 * Modul-nyilvántartás és útvonalkezelés.
 *
 * A rendszer két helyen jelenik meg: a saját frontend-felületen
 * (sdh-muhely.local/muhely/…) és a wp-adminban. A modulok egyszer írják
 * meg magukat, és itt jelentkeznek be – a két felület innen tudja meg,
 * mi létezik egyáltalán.
 *
 * Az URL-építés is itt lakik, mert ugyanaz a modulkód fut mindkét
 * helyen: a lista nem tudhatja, melyik felületen van, csak annyit
 * mond, hogy "az ügyfelek oldalára, szerkesztés nézetben, 12-es azonosítóval".
 * A kontextus eldönti, hogy ebből admin.php?page=… vagy /muhely/… lesz.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Modulok
{
    /**
     * A bejegyzett modulok.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $modulok = [];

    /** 'frontend' vagy 'admin' – melyik felület fut éppen. */
    private static string $kontextus = 'admin';

    /**
     * Modul bejegyzése.
     *
     * @param array{
     *     kulcs: string,
     *     cim: string,
     *     render: callable,
     *     sorrend?: int,
     *     keszul?: bool
     * } $modul
     */
    public static function regisztral(array $modul): void
    {
        if (empty($modul['kulcs']) || empty($modul['cim'])) {
            return;
        }

        $modul['sorrend'] = $modul['sorrend'] ?? 50;
        $modul['keszul']  = $modul['keszul'] ?? false;

        self::$modulok[$modul['kulcs']] = $modul;
    }

    /**
     * Minden bejegyzett modul, sorrendben.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function osszes(): array
    {
        $modulok = self::$modulok;

        uasort(
            $modulok,
            static fn (array $a, array $b): int => $a['sorrend'] <=> $b['sorrend']
        );

        return $modulok;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function egy(string $kulcs): ?array
    {
        return self::$modulok[$kulcs] ?? null;
    }

    /* =================================================================
     * Kontextus
     * ============================================================== */

    public static function kontextus_beallit(string $kontextus): void
    {
        self::$kontextus = $kontextus === 'frontend' ? 'frontend' : 'admin';
    }

    public static function kontextus(): string
    {
        return self::$kontextus;
    }

    public static function frontenden_vagyunk(): bool
    {
        return self::$kontextus === 'frontend';
    }

    /* =================================================================
     * URL-ek
     * ============================================================== */

    /**
     * Egy modul URL-je az éppen futó felületen.
     *
     * @param string               $kulcs       Modulkulcs, pl. 'ugyfelek'. Üres = áttekintés.
     * @param array<string, mixed> $parameterek Lekérdezési paraméterek (nezet, id, k…).
     */
    public static function url(string $kulcs = '', array $parameterek = []): string
    {
        return self::frontenden_vagyunk()
            ? self::frontend_url($kulcs, $parameterek)
            : self::admin_url($kulcs, $parameterek);
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    public static function admin_url(string $kulcs = '', array $parameterek = []): string
    {
        $slug = $kulcs === ''
            ? SDH_Muhely_Admin_UI::FOMENU
            : SDH_Muhely_Admin_UI::FOMENU . '-' . $kulcs;

        return add_query_arg(
            array_merge(['page' => $slug], $parameterek),
            admin_url('admin.php')
        );
    }

    /**
     * A frontend URL-je.
     *
     * Szép URL-eknél /muhely/ugyfelek/, egyébként ?muhely=ugyfelek.
     * A Local alapértelmezésben nem feltétlenül kapcsol szép URL-t, és
     * a rendszernek akkor is működnie kell.
     *
     * @param array<string, mixed> $parameterek
     */
    public static function frontend_url(string $kulcs = '', array $parameterek = []): string
    {
        if (get_option('permalink_structure')) {
            $ut = SDH_Muhely_Frontend::ALAP . '/' . ($kulcs !== '' ? $kulcs . '/' : '');

            return add_query_arg($parameterek, home_url('/' . ltrim($ut, '/')));
        }

        return add_query_arg(
            array_merge([SDH_Muhely_Frontend::QUERY_VAR => $kulcs !== '' ? $kulcs : 'attekintes'], $parameterek),
            home_url('/')
        );
    }

    /**
     * Az űrlapok mindkét felületről az admin-post.php-ra küldenek be.
     * Mentés után ez mondja meg, hová térjünk vissza.
     */
    public static function visszateres(string $kulcs, array $parameterek, string $kontextus): string
    {
        return $kontextus === 'frontend'
            ? self::frontend_url($kulcs, $parameterek)
            : self::admin_url($kulcs, $parameterek);
    }

    /* =================================================================
     * GET-űrlapok (kereső, szűrő)
     * ============================================================== */

    /**
     * Egy GET-es űrlap `action` címe: az URL lekérdezés nélküli része.
     *
     * A böngésző a GET-űrlap beküldésekor eldobja az action URL-jében
     * lévő lekérdezést, ezért azt rejtett mezőkben kell visszaadni –
     * lásd urlap_rejtett().
     */
    public static function urlap_cel(string $kulcs = ''): string
    {
        $url   = self::url($kulcs);
        $reszek = wp_parse_url($url);

        return sprintf(
            '%s://%s%s',
            $reszek['scheme'] ?? 'http',
            $reszek['host'] ?? '',
            $reszek['path'] ?? '/'
        );
    }

    /**
     * Azok a rejtett mezők, amik nélkül a GET-űrlap nem találna vissza
     * erre az oldalra (adminban a `page`, szép URL nélkül a `muhely`).
     */
    public static function urlap_rejtett(string $kulcs = ''): void
    {
        $url   = self::url($kulcs);
        $query = wp_parse_url($url, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return;
        }

        $parok = [];
        wp_parse_str($query, $parok);

        foreach ($parok as $nev => $ertek) {
            printf(
                '<input type="hidden" name="%s" value="%s">',
                esc_attr((string) $nev),
                esc_attr((string) $ertek)
            );
        }
    }
}
