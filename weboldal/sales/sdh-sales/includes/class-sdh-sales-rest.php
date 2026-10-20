<?php
/**
 * SDH Értékesítés – REST-segéd: útvonal-regisztráció jogszinttel és korláttal, IP-alapú rate limit, egységes hiba.
 *
 * Használat (bármelyik modul init()-jéből):
 *   SDH_Sales_Rest::ut('/ar', 'POST', [self::class, 'ar'], 'nyilvanos', ['ar', 60, 60]);
 *   SDH_Sales_Rest::ut('/admin/rendelesek', 'GET', [self::class, 'lista'], 'admin');
 * Jogszint: 'nyilvanos' | 'fiok' (bejelentkezett vevő vagy admin) | 'admin' (SDH_Sales::admin()).
 * Korlát: [név, darab, másodperc] – IP-nként (naponta forgó lenyomattal).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Rest
{
    public const NS = 'portal/v1';

    /** @var array<int, array<string, mixed>> */
    private static array $utak = [];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'regisztral']);
        add_filter('rest_post_dispatch', [self::class, 'fejlec'], 10, 3);
    }

    /**
     * @param callable                 $cb
     * @param array{0:string,1:int,2:int}|array{} $korlat
     */
    public static function ut(string $ut, string $metodus, $cb, string $jog = 'nyilvanos', array $korlat = []): void
    {
        self::$utak[] = ['ut' => $ut, 'm' => $metodus, 'cb' => $cb, 'jog' => $jog, 'korlat' => $korlat];
    }

    public static function regisztral(): void
    {
        foreach (self::$utak as $u) {
            $jog    = (string) $u['jog'];
            $korlat = (array) $u['korlat'];
            $cb     = $u['cb'];

            register_rest_route(self::NS, (string) $u['ut'], [
                'methods'             => (string) $u['m'],
                'permission_callback' => static function () use ($jog): bool {
                    if ($jog === 'admin') {
                        return SDH_Sales::admin();
                    }

                    if ($jog === 'fiok') {
                        return is_user_logged_in();
                    }

                    return true;
                },
                'callback'            => static function (WP_REST_Request $r) use ($cb, $korlat) {
                    if (count($korlat) === 3 && !self::korlat((string) $korlat[0], (int) $korlat[1], (int) $korlat[2])) {
                        return self::hiba('Túl sok kérés. Próbáld újra néhány perc múlva.', 429, 'korlat');
                    }

                    return call_user_func($cb, $r);
                },
            ]);
        }
    }

    /** A saját végpontjaink válasza soha nem kerül gyorsítótárba (Cloudflare, WP Rocket). */
    public static function fejlec($valasz, $szerver, $keres)
    {
        if ($keres instanceof WP_REST_Request && strpos((string) $keres->get_route(), '/' . self::NS) === 0 && $valasz instanceof WP_REST_Response) {
            $valasz->header('Cache-Control', 'private, no-store, max-age=0');
            $valasz->header('X-Robots-Tag', 'noindex');
        }

        return $valasz;
    }

    public static function hiba(string $uzenet, int $status = 400, string $kod = 'hiba'): WP_Error
    {
        return new WP_Error($kod, $uzenet, ['status' => $status]);
    }

    /** A korlát-sor kulcsa és lejárata. @return array{0: string, 1: string} */
    private static function korlat_kulcs(string $nev, int $mp, string $kulcs_extra = ''): array
    {
        $ablak = (int) floor(time() / max(1, $mp));

        return [
            mb_substr($nev . ':' . SDH_Sales::ip_hash_napi() . ($kulcs_extra !== '' ? ':' . md5($kulcs_extra) : '') . ':' . $ablak, 0, 120),
            gmdate('Y-m-d H:i:s', ($ablak + 1) * max(1, $mp)),
        ];
    }

    /**
     * IP-nkénti korlát: igaz, ha még belefér (és számol). `$kulcs_extra` pl. e-mail vagy munkamenet.
     * Atomi: előbb növel (INSERT … ON DUPLICATE KEY UPDATE), aztán a növelt értéket nézi – párhuzamos kérések sem lépik túl.
     */
    public static function korlat(string $nev, int $db, int $mp, string $kulcs_extra = ''): bool
    {
        if ((bool) apply_filters('sdh_sales_korlat_ki', false, $nev)) {
            return true;
        }

        global $wpdb;

        $t              = SDH_Sales_Schema::t('korlat');
        [$kulcs, $lejar] = self::korlat_kulcs($nev, $mp, $kulcs_extra);

        $wpdb->query($wpdb->prepare("INSERT INTO {$t} (kulcs, db, lejar) VALUES (%s, 1, %s) ON DUPLICATE KEY UPDATE db = db + 1", $kulcs, $lejar));

        return (int) $wpdb->get_var($wpdb->prepare("SELECT db FROM {$t} WHERE kulcs = %s", $kulcs)) <= $db;
    }

    /** Belefér-e még (nem számol). */
    public static function korlat_belefer(string $nev, int $db, int $mp, string $kulcs_extra = ''): bool
    {
        if ((bool) apply_filters('sdh_sales_korlat_ki', false, $nev)) {
            return true;
        }

        global $wpdb;

        [$kulcs] = self::korlat_kulcs($nev, $mp, $kulcs_extra);

        return (int) $wpdb->get_var($wpdb->prepare('SELECT db FROM ' . SDH_Sales_Schema::t('korlat') . ' WHERE kulcs = %s', $kulcs)) < $db;
    }

    /** Egy esemény könyvelése (nem dönt). */
    public static function korlat_novel(string $nev, int $mp, string $kulcs_extra = ''): void
    {
        if ((bool) apply_filters('sdh_sales_korlat_ki', false, $nev)) {
            return;
        }

        global $wpdb;

        [$kulcs, $lejar] = self::korlat_kulcs($nev, $mp, $kulcs_extra);
        $wpdb->query($wpdb->prepare('INSERT INTO ' . SDH_Sales_Schema::t('korlat') . ' (kulcs, db, lejar) VALUES (%s, 1, %s) ON DUPLICATE KEY UPDATE db = db + 1', $kulcs, $lejar));
    }

    /** Lejárt korlát-sorok törlése (a cron hívja). */
    public static function takarit(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Sales_Schema::t('korlat') . ' WHERE lejar < %s', gmdate('Y-m-d H:i:s')));
    }
}
