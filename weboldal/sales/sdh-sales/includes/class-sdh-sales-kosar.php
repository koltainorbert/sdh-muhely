<?php
/**
 * SDH Értékesítés – kosár: a kosár a böngészőben él (localStorage, `SDH.kosar` a kozos.js-ben); a szerver itt árazza újra
 * (POST /kosar/ellenoriz), és itt regisztrálja a kosár- és pénztár-oldal front-modulját.
 *
 *   fizmodok(): array<int, array{kulcs, nev, leiras}> – a bekapcsolt fizetési módok
 *   penztar_url(array $query = []): string
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Kosar
{
    public static function init(): void
    {
        SDH_Sales_Rest::ut('/kosar/ellenoriz', 'POST', [self::class, 'rest_ellenoriz'], 'nyilvanos', ['kosar', 120, 60]);
        add_action('init', [self::class, 'front'], 6);
    }

    public static function front(): void
    {
        SDH_Sales_Front::modul('kosar', 'assets/front/kosar.js', ['kozos'], [self::class, 'kosar_adat']);
        SDH_Sales_Front::modul('penztar', 'assets/front/penztar.js', ['kozos'], [self::class, 'penztar_adat']);
    }

    public static function oldal_url(string $kulcs, string $alap, array $query = []): string
    {
        $u = home_url(user_trailingslashit((string) SDH_Sales::b('oldal.' . $kulcs, $alap)));

        return $query !== [] ? add_query_arg(array_map('rawurlencode', $query), $u) : $u;
    }

    public static function penztar_url(array $query = []): string
    {
        return self::oldal_url('penztar', 'penztar', $query);
    }

    /** @return array<int, array{kulcs: string, nev: string, leiras: string}> */
    public static function fizmodok(): array
    {
        $m = [];

        if ((bool) SDH_Sales::b('fizetes.atutalas', true)) {
            $m[] = ['kulcs' => 'atutalas', 'nev' => __('Átutalás (díjbekérő)', 'sdh-sales'), 'leiras' => __('Díjbekérőt küldünk, QR-kóddal azonnal fizethető a banki alkalmazásban.', 'sdh-sales')];
        }

        if (class_exists('SDH_Sales_Fizetes_Barion') && SDH_Sales_Fizetes_Barion::aktiv()) {
            $m[] = ['kulcs' => 'barion', 'nev' => __('Bankkártya (Barion)', 'sdh-sales'), 'leiras' => __('Biztonságos kártyás fizetés a Barion oldalán.', 'sdh-sales')];
        }

        if (class_exists('SDH_Sales_Fizetes_Simplepay') && SDH_Sales_Fizetes_Simplepay::aktiv()) {
            $m[] = ['kulcs' => 'simplepay', 'nev' => __('Bankkártya (SimplePay)', 'sdh-sales'), 'leiras' => __('Biztonságos kártyás fizetés a SimplePay oldalán.', 'sdh-sales')];
        }

        return (array) apply_filters('sdh_sales_fizmodok', $m);
    }

    /** @return array<string, mixed> */
    public static function kosar_adat(): array
    {
        return SDH_Sales_Katalogus::publikus() + ['fizmodok' => self::fizmodok(), 'b2b_felirat' => __('Rendelést csak vállalkozások és szervezetek adhatnak le.', 'sdh-sales')];
    }

    /** @return array<string, mixed> */
    public static function penztar_adat(): array
    {
        $elozo = [];
        $u     = wp_get_current_user();

        if ($u instanceof WP_User && $u->exists() && class_exists('SDH_Sales_Rendeles')) {
            $elozo = SDH_Sales_Rendeles::utolso_vevo((int) $u->ID, (string) $u->user_email);
        }

        $jogi = [];

        foreach (['aszf' => 'aszf', 'eula' => 'eula', 'adatvedelem' => 'adatvedelem'] as $k => $alap) {
            $slug     = (string) SDH_Sales::b('oldal.' . $k, $alap);
            $jogi[$k] = ['url' => home_url(user_trailingslashit($slug)), 'verzio' => class_exists('SDH_Sales_Rendeles') ? SDH_Sales_Rendeles::jogi_verzio($k) : ''];
        }

        return [
            'fizmodok' => self::fizmodok(),
            'jogi'     => $jogi,
            'vevo'     => $elozo,
            'vedelem'  => SDH_Sales_Biztonsag::front_adat(),
            'fiok_url' => self::oldal_url('fiok', 'fiok'),
            'kosar_url' => self::oldal_url('kosar', 'kosar'),
            'afa'      => SDH_Sales::afa(),
            'b2b'      => ['felirat' => __('Rendelést csak vállalkozások és szervezetek adhatnak le.', 'sdh-sales'), 'nyilatkozat' => __('Vállalkozásként / szervezetként vásárolok (nem fogyasztóként).', 'sdh-sales')],
        ];
    }

    public static function rest_ellenoriz(WP_REST_Request $r): WP_REST_Response
    {
        $tetelek = SDH_Sales_Ar::tetelek_tisztit($r->get_param('tetelek'));
        $ar      = SDH_Sales_Ar::szamol($tetelek, SDH_Sales_Ar::opciok_tisztit($r->get_param('opciok')));

        return new WP_REST_Response([
            'ok'       => $ar['hibak'] === [],
            'ar'       => $ar,
            'tetelek'  => $tetelek,
            'fizmodok' => self::fizmodok(),
        ], 200);
    }
}
