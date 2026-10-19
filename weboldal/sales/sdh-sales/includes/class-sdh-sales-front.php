<?php
/**
 * SDH Értékesítés – front-oldali szkriptek nyilvántartása: csak azon az oldalon töltődik egy modul JS-e, ahol kell.
 *
 * Modul regisztrálása (a modul init()-jéből):
 *   SDH_Sales_Front::modul('kalkulator', 'assets/front/kalkulator.js', ['kozos'], ['csomagok' => …]);
 * Igény jelzése (a téma szekció-sablonja vagy egy shortcode):
 *   SDH_Sales_Front::igeny('kalkulator');
 * A `kozos` modul (assets/front/kozos.js) mindig az első: fetch-segéd (`window.APP.api(ut, adat)`), kétlépcsős gomb,
 * kosár-tár (`window.APP.kosar`), értesítő (`window.APP.uzen()`). Rejtés: a handle `p-<modul>`, a változó `window.APP_<MODUL>`
 * (a kimenetben nem lehet „sdh” / „wp” – lásd weboldal/REJTES.md).
 * A szkriptek `defer`-rel, a lábban; az adat `window.SDH_<MODUL>` alatt (wp_add_inline_script, CSP-nonce-szal).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Front
{
    /** @var array<string, array{fajl: string, fugg: array<int, string>, adat: array<string, mixed>|callable|null, css: string}> */
    private static array $modulok = [];

    /** @var array<string, bool> */
    private static array $igeny = [];

    public static function init(): void
    {
        add_action('init', [self::class, 'kozos_regisztral'], 5);
        add_action('wp_footer', [self::class, 'kiir'], 5);
    }

    public static function kozos_regisztral(): void
    {
        self::modul('kozos', 'assets/front/kozos.js', [], static fn (): array => [
            'rest'  => esc_url_raw(rest_url(SDH_Sales_Rest::NS)),
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'afa'   => SDH_Sales::afa(),
            'nyelv' => determine_locale(),
            'szoveg' => [
                'biztos' => __('Biztos? Kattints újra.', 'sdh-sales'),
                'hiba'   => __('Valami nem sikerült. Próbáld újra.', 'sdh-sales'),
            ],
        ]);
    }

    /**
     * @param array<int, string>                  $fugg
     * @param array<string, mixed>|callable|null $adat tömb vagy lusta függvény (csak igény esetén fut)
     */
    public static function modul(string $nev, string $fajl, array $fugg = ['kozos'], $adat = null, string $css = ''): void
    {
        self::$modulok[$nev] = ['fajl' => $fajl, 'fugg' => $fugg, 'adat' => $adat, 'css' => $css];
    }

    public static function igeny(string $nev): void
    {
        self::$igeny[$nev] = true;

        // Ha a fejléc még nem ment ki, a CSS a fejbe kerül; különben a lábba.
        $m = self::$modulok[$nev] ?? null;

        if ($m !== null && $m['css'] !== '' && !did_action('wp_head')) {
            wp_enqueue_style('p-' . $nev, SDH_SALES_URL . $m['css'], [], SDH_SALES_VERSION);
        }
    }

    public static function kell(string $nev): bool
    {
        return isset(self::$igeny[$nev]);
    }

    /** @return array<int, string> */
    public static function igenyelt(): array
    {
        return array_keys(self::$igeny);
    }

    public static function kiir(): void
    {
        if (self::$igeny === []) {
            return;
        }

        $sor = [];
        $lat = [];

        $fel = static function (string $n) use (&$fel, &$sor, &$lat): void {
            if (isset($lat[$n]) || !isset(self::$modulok[$n])) {
                return;
            }

            $lat[$n] = true;

            foreach (self::$modulok[$n]['fugg'] as $f) {
                $fel($f);
            }

            $sor[] = $n;
        };

        $fel('kozos');

        foreach (array_keys(self::$igeny) as $n) {
            $fel($n);
        }

        foreach ($sor as $n) {
            $m      = self::$modulok[$n];
            $handle = 'p-' . $n;
            $fugg   = array_map(static fn (string $f): string => 'p-' . $f, array_values(array_filter($m['fugg'], static fn ($f) => isset(self::$modulok[$f]))));

            if (!is_file(SDH_SALES_DIR . $m['fajl'])) {
                continue;
            }

            wp_enqueue_script($handle, SDH_SALES_URL . $m['fajl'], $fugg, SDH_SALES_VERSION . '-' . (string) filemtime(SDH_SALES_DIR . $m['fajl']), ['in_footer' => true, 'strategy' => 'defer']);

            if ($m['css'] !== '' && !wp_style_is($handle, 'enqueued')) {
                wp_enqueue_style($handle, SDH_SALES_URL . $m['css'], [], SDH_SALES_VERSION);
            }

            $adat = is_callable($m['adat']) ? call_user_func($m['adat']) : $m['adat'];

            if (is_array($adat)) {
                wp_add_inline_script($handle, 'window.APP_' . strtoupper(str_replace('-', '_', $n)) . '=' . wp_json_encode(SDH_Sales::t_tomb($adat), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';', 'before');
            }
        }
    }
}
