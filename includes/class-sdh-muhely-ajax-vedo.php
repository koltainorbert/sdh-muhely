<?php
/**
 * AJAX-védő (0.99.4).
 *
 * Ha egy SDH-végpont futása közben a PHP figyelmeztetést, értesítést vagy hibát ír ki (pl. bekapcsolt
 * WP_DEBUG mellett egy „Warning: Undefined array key”), az a JSON-válasz ELÉ kerül, és a böngésző
 * „JSON.parse: unexpected character” hibát mutat – a felület (pl. a Naptár) be sem töltődik.
 *
 * A védő a sdh_muhely_* AJAX-kérések kimenetét pufferben gyűjti, és a végén:
 *  - ha a válasz JSON, de előtte szemét áll, a szemetet levágja (és a hibanaplóba írja);
 *  - ha végzetes PHP-hiba miatt egyáltalán nincs JSON, olvasható JSON-hibát ad helyette
 *    („Szerverhiba: …” az első sorával), így a felület megmondja, mi a baj.
 * A HTML-t adó végpontok (…_urlap, …_nyomtat stb.) kimenete érintetlen marad, csak a JSON-t javítjuk.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Ajax_Vedo
{
    private static bool $fut = false;

    public static function init(): void
    {
        if (!self::kell()) {
            return;
        }

        self::$fut = true;
        ob_start([self::class, 'szur']);
    }

    /** Csak a saját AJAX-kéréseink (sdh_muhely_*), és csak ha nincs kikapcsolva. */
    private static function kell(): bool
    {
        if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) {
            return false;
        }

        $akcio = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if (strpos($akcio, 'sdh_muhely_') !== 0 && strpos($akcio, 'sdh_tesztadat_') !== 0) {
            return false;
        }

        return !(defined('SDH_MUHELY_AJAX_VEDO_KI') && SDH_MUHELY_AJAX_VEDO_KI);
    }

    /**
     * A puffer szűrője: a kimenet végleges formája.
     */
    public static function szur(string $kimenet, int $fazis = PHP_OUTPUT_HANDLER_END): string
    {
        return self::javit($kimenet, self::json_fejlec());
    }

    /** A válasz fejléce szerint JSON-e (wp_send_json beállítja). */
    private static function json_fejlec(): bool
    {
        foreach (headers_list() as $fej) {
            if (stripos($fej, 'content-type:') === 0 && stripos($fej, 'json') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tisztán tesztelhető mag: a kimenetből a JSON-válasz.
     *
     * @param bool $json_fejlec A válasz JSON-nak jelölt-e.
     */
    public static function javit(string $kimenet, bool $json_fejlec): string
    {
        $eleje = ltrim($kimenet);

        if ($eleje === '' || $eleje[0] === '{' || $eleje[0] === '[') {
            return $kimenet;
        }

        // A JSON-válasz a szemét után: a {"success" kezdetű rész.
        $hol = strpos($kimenet, '{"success"');

        if ($hol !== false) {
            $json = substr($kimenet, $hol);

            if (json_decode($json) !== null || json_last_error() === JSON_ERROR_NONE) {
                self::naploz(substr($kimenet, 0, $hol));

                return $json;
            }
        }

        // Nincs JSON, de PHP-hiba látszik (végzetes hiba / kivétel): olvasható JSON-hiba.
        if ($json_fejlec || self::php_hiba($kimenet)) {
            self::naploz($kimenet);

            if (!headers_sent()) {
                header('Content-Type: application/json; charset=' . get_option('blog_charset', 'UTF-8'));
            }

            return (string) wp_json_encode([
                'success' => false,
                'data'    => ['uzenet' => 'Szerverhiba: ' . self::elso_sor($kimenet), 'szerverhiba' => true],
            ]);
        }

        return $kimenet;
    }

    /** PHP-hibaüzenet van-e a kimenetben (a WordPress „kritikus hiba” oldala is). */
    private static function php_hiba(string $s): bool
    {
        return preg_match('/(Fatal error|Parse error|Uncaught|Warning|Notice|Deprecated|critical error|kritikus hiba)/i', $s) === 1;
    }

    /** A hiba első értelmes sora, HTML nélkül, röviden. */
    public static function elso_sor(string $s): string
    {
        $szoveg = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($s)));

        if ($szoveg === '') {
            return 'üres válasz.';
        }

        return function_exists('mb_substr') ? mb_substr($szoveg, 0, 300) : substr($szoveg, 0, 300);
    }

    private static function naploz(string $szemet): void
    {
        $szemet = trim($szemet);

        if ($szemet === '') {
            return;
        }

        $akcio = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        error_log('[SDH Műhely] AJAX-kimenet javítva (' . $akcio . '): ' . self::elso_sor($szemet)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
    }
}
