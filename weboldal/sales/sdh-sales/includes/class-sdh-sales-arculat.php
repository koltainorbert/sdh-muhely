<?php
/**
 * SDH Értékesítés – Arculat: a téma CSS-változóinak (ARCHITEKTURA.md §7) felülírása az adminból, logó, favicon, alapmód.
 *
 * - Beállításkulcsok: `arculat.<token>` (a kötőjel helyett aláhúzás: `--s-kiemelo-2` → `arculat.kiemelo_2`); a színeknek
 *   és az árnyéknak módonként külön értéke van: `arculat.sotet.<token>` / `arculat.vilagos.<token>`.
 *   `arculat.mod` = „rendszer” | „sotet” | „vilagos” (alapmód), `arculat.logo`, `arculat.logo_vilagos`, `arculat.favicon`.
 * - `css()` csak a megváltoztatott (az alaptól eltérő), érvényes értékeket írja ki – minden kérésnél ugyanaz (nincs kérésenkénti
 *   adat), így gyorsítótárazható. A kiírás előtt is újra ellenőriz (import / kézi átírás ellen).
 * - Az alapértékek a téma `assets/css/tokenek.css`-éből jönnek (ha van), különben a beépített alapból.
 * - A téma: `SDH_Sales_Arculat::logo('sotet'|'vilagos')`, `::mod()`; a `<html>` `data-tema` attribútumát rögzített alapmódnál a
 *   bővítmény teszi ki (`language_attributes` szűrő) – a látogató váltója ezt felülírhatja.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Arculat
{
    /** @var array<string, array{0: string, 1: string}>|null */
    private static ?array $alap_cache = null;

    /** @var array<string, string>|null az admin élő előnézetének (még nem mentett) értékei erre a kérésre */
    private static ?array $elonezet = null;

    public static function init(): void
    {
        add_action('wp_head', [self::class, 'kiir'], 99);
        add_action('wp_head', [self::class, 'favicon'], 2);
        add_filter('language_attributes', [self::class, 'html_attr'], 20);
    }

    /* =================================================================
     * Tokenek
     * ============================================================== */

    /**
     * A tokenek: név (a `--s-` utáni rész) → [fajta, címke, csoport]. Fajta: szin | meret | arnyek | betu.
     * A szín és az árnyék módonként (sötét / világos) külön állítható.
     *
     * @return array<string, array{fajta: string, cimke: string, csoport: string}>
     */
    public static function tokenek(): array
    {
        $t = [
            'bg'          => ['szin', 'Háttér', 'Színek'],
            'felulet'     => ['szin', 'Felület (kártyák)', 'Színek'],
            'uveg'        => ['szin', 'Üveg (áttetsző felület)', 'Színek'],
            'szoveg'      => ['szin', 'Szöveg', 'Színek'],
            'halvany'     => ['szin', 'Halvány szöveg', 'Színek'],
            'vonal'       => ['szin', 'Vonal, keret', 'Színek'],
            'kiemelo'     => ['szin', 'Kiemelő szín', 'Színek'],
            'kiemelo-2'   => ['szin', 'Második kiemelő', 'Színek'],
            'neon'        => ['szin', 'Neon (fény, ragyogás)', 'Színek'],
            'ok'          => ['szin', 'Rendben (zöld)', 'Állapotszínek'],
            'figyel'      => ['szin', 'Figyelmeztetés (sárga)', 'Állapotszínek'],
            'hiba'        => ['szin', 'Hiba (piros)', 'Állapotszínek'],
            'arnyek'      => ['arnyek', 'Árnyék', 'Formák'],
            'r-mezo'      => ['meret', 'Mező íve', 'Formák'],
            'r-gomb'      => ['meret', 'Gomb íve', 'Formák'],
            'r-pill'      => ['meret', 'Pill íve', 'Formák'],
            'r-kartya'    => ['meret', 'Kártya íve', 'Formák'],
            'max'         => ['meret', 'Tartalom szélessége', 'Formák'],
            'betu-cim'    => ['betu', 'Címek betűje', 'Betűk'],
            'betu-szoveg' => ['betu', 'Szöveg betűje', 'Betűk'],
            'betu-kod'    => ['betu', 'Kód betűje', 'Betűk'],
        ];

        $ki = [];

        foreach ($t as $k => [$f, $c, $cs]) {
            $ki[$k] = ['fajta' => $f, 'cimke' => $c, 'csoport' => $cs];
        }

        return $ki;
    }

    /** Módonként külön állítható-e. */
    public static function modos(string $token): bool
    {
        $f = self::tokenek()[$token]['fajta'] ?? '';

        return $f === 'szin' || $f === 'arnyek';
    }

    /** Beállításkulcs egy tokenhez (`kiemelo-2`, `vilagos` → `arculat.vilagos.kiemelo_2`). */
    public static function kulcs(string $token, string $mod = ''): string
    {
        $k = str_replace('-', '_', $token);

        return 'arculat.' . ($mod !== '' && self::modos($token) ? $mod . '.' : '') . $k;
    }

    /** @return array<string, string> választható betűkészletek (CSS font-family verem) – a téma bővítheti */
    public static function betuk(): array
    {
        $b = [
            'Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' => 'Inter / rendszer',
            'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif' => 'Rendszer (sans)',
            '"Space Grotesk", Inter, ui-sans-serif, system-ui, sans-serif' => 'Space Grotesk',
            '"Plus Jakarta Sans", Inter, ui-sans-serif, system-ui, sans-serif' => 'Plus Jakarta Sans',
            'Georgia, "Times New Roman", serif' => 'Georgia (talpas)',
            '"JetBrains Mono", ui-monospace, "SF Mono", Menlo, Consolas, monospace' => 'JetBrains Mono',
            'ui-monospace, "SF Mono", Menlo, Consolas, monospace' => 'Rendszer (mono)',
        ];

        return (array) apply_filters('sdh_sales_arculat_betuk', $b);
    }

    /* =================================================================
     * Alapértékek
     * ============================================================== */

    /** @return array<string, array{0: string, 1: string}> token → [sötét, világos] alapérték */
    public static function alapok(): array
    {
        if (self::$alap_cache !== null) {
            return self::$alap_cache;
        }

        $a = [
            'bg'          => ['#07080c', '#f5f6fa'],
            'felulet'     => ['#11131b', '#ffffff'],
            'uveg'        => ['rgba(22, 25, 36, .62)', 'rgba(255, 255, 255, .72)'],
            'szoveg'      => ['#eef0f6', '#0f1222'],
            'halvany'     => ['#a3a9bb', '#4b5168'],
            'vonal'       => ['rgba(255, 255, 255, .08)', 'rgba(15, 23, 42, .1)'],
            'kiemelo'     => ['#7c6cff', '#6d5efc'],
            'kiemelo-2'   => ['#22d3ee', '#0891b2'],
            'neon'        => ['#a78bfa', '#7c3aed'],
            'ok'          => ['#34d399', '#059669'],
            'figyel'      => ['#fbbf24', '#d97706'],
            'hiba'        => ['#fb7185', '#e11d48'],
            'arnyek'      => ['0 20px 50px -20px rgba(0, 0, 0, .7)', '0 14px 40px -18px rgba(15, 23, 42, .18)'],
            'r-mezo'      => ['12px', '12px'],
            'r-gomb'      => ['12px', '12px'],
            'r-pill'      => ['999px', '999px'],
            'r-kartya'    => ['18px', '18px'],
            'max'         => ['1200px', '1200px'],
            'betu-cim'    => ['Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', ''],
            'betu-szoveg' => ['Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', ''],
            'betu-kod'    => ['"JetBrains Mono", ui-monospace, "SF Mono", Menlo, Consolas, monospace', ''],
        ];

        foreach (self::tema_tokenek() as $tok => $par) {
            if (!isset($a[$tok])) {
                continue;
            }

            if ($par[0] !== '' && self::ervenyes($tok, $par[0]) !== null) {
                $a[$tok][0] = (string) self::ervenyes($tok, $par[0]);
            }

            if ($par[1] !== '' && self::ervenyes($tok, $par[1]) !== null) {
                $a[$tok][1] = (string) self::ervenyes($tok, $par[1]);
            }
        }

        foreach ($a as $tok => $par) {
            if ($par[1] === '') {
                $a[$tok][1] = $par[0];
            }
        }

        self::$alap_cache = $a;

        return $a;
    }

    /**
     * A téma tokenek.css-ének értékei: az első `:root {…}` blokk a sötét (alap), a `[data-tema="vilagos"]` blokk a világos.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tema_tokenek(): array
    {
        $f = (string) apply_filters('sdh_sales_arculat_tokenek_css', get_template_directory() . '/assets/css/tokenek.css');

        if ($f === '' || !is_file($f) || filesize($f) > 200000) {
            return [];
        }

        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($f));
        $ki  = [];

        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $blokk, PREG_SET_ORDER)) {
            foreach ($blokk as $b) {
                $sel = trim($b[1]);
                $i   = null;

                if (strpos($sel, 'vilagos') !== false) {
                    $i = 1;
                } elseif (preg_match('/^:root\s*(,|$)/', $sel) === 1 || strpos($sel, 'sotet') !== false) {
                    $i = 0;
                }

                if ($i === null || strpos($sel, '@media') !== false) {
                    continue;
                }

                if (preg_match_all('/--s-([a-z0-9-]+)\s*:\s*([^;]+);?/', $b[2], $m, PREG_SET_ORDER)) {
                    foreach ($m as $d) {
                        $ki[$d[1]] = $ki[$d[1]] ?? ['', ''];

                        if ($ki[$d[1]][$i] === '') {
                            $ki[$d[1]][$i] = trim($d[2]);
                        }
                    }
                }
            }
        }

        return $ki;
    }

    /* =================================================================
     * Ellenőrzés
     * ============================================================== */

    /** Érvényes érték a token fajtája szerint (normalizálva) vagy null. Nincs benne `;`, `{`, `}`, `<`, `\`, `@`, `url(`. */
    public static function ervenyes(string $token, string $v): ?string
    {
        $v = trim($v);
        $f = self::tokenek()[$token]['fajta'] ?? '';

        if ($v === '' || strlen($v) > 300 || preg_match('/[;{}<>\\\\@]/', $v) === 1 || stripos($v, 'url(') !== false || stripos($v, 'expression') !== false) {
            return null;
        }

        if ($f !== 'betu' && preg_match('/["\']/', $v) === 1) {
            return null;
        }

        switch ($f) {
            case 'szin':
                return self::szin($v);

            case 'meret':
                return preg_match('/^(0|\d{1,4}(\.\d{1,3})?(px|rem|em|%|ch|vw))$/', $v) === 1 ? $v : null;

            case 'arnyek':
                if ($v === 'none') {
                    return $v;
                }

                $reszek = self::vesszo_bont($v);

                if (count($reszek) > 4) {
                    return null;
                }

                foreach ($reszek as $r) {
                    $sz = '';

                    if (preg_match('/(#[0-9a-fA-F]{3,8}|(?:rgba?|hsla?)\([^()]*\))\s*$/', $r, $m) === 1) {
                        $sz = $m[1];

                        if (self::szin($sz) === null) {
                            return null;
                        }

                        $r = trim(substr($r, 0, -strlen($m[0])));
                    }

                    if (preg_match('/^(inset\s+)?(-?\d{1,3}(\.\d{1,2})?(px|rem|em)?\s*){2,4}$/', $r) !== 1) {
                        return null;
                    }
                }

                return $v;

            case 'betu':
                if (strlen($v) > 200 || preg_match('/^[A-Za-z0-9 ,"\'\-]+$/', $v) !== 1 || substr_count($v, '"') % 2 === 1 || substr_count($v, "'") % 2 === 1) {
                    return null;
                }

                return $v;
        }

        return null;
    }

    /** Szín: #rgb(a), #rrggbb(aa), rgb()/rgba()/hsl()/hsla() számokkal, transparent. */
    public static function szin(string $v): ?string
    {
        $v = trim($v);

        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) === 1) {
            return strtolower($v);
        }

        if ($v === 'transparent') {
            return $v;
        }

        $szam = '-?\d{1,3}(\.\d{1,3})?%?|\.\d{1,3}';

        if (preg_match('/^(rgba?|hsla?)\(\s*(' . $szam . ')(deg)?\s*[, ]\s*(' . $szam . ')\s*[, ]\s*(' . $szam . ')\s*([,\/]\s*(' . $szam . ')\s*)?\)$/i', $v) === 1) {
            return $v;
        }

        return null;
    }

    /** @return array<int, string> vesszőnél bont (a zárójeleken kívül) */
    private static function vesszo_bont(string $v): array
    {
        $ki = [];
        $m  = 0;
        $b  = '';

        foreach (str_split($v) as $c) {
            if ($c === '(') {
                $m++;
            } elseif ($c === ')') {
                $m--;
            }

            if ($c === ',' && $m === 0) {
                $ki[] = trim($b);
                $b    = '';
                continue;
            }

            $b .= $c;
        }

        $ki[] = trim($b);

        return array_values(array_filter($ki, 'strlen'));
    }

    /* =================================================================
     * Mentés, kiolvasás
     * ============================================================== */

    /** @return array<string, mixed> a jelenlegi állapot az adminnak */
    public static function allapot(): array
    {
        $tok = [];
        $alap = self::alapok();

        foreach (self::tokenek() as $k => $m) {
            $tok[$k] = $m + [
                'modos'   => self::modos($k),
                'alap'    => $alap[$k],
                'ertek'   => self::modos($k)
                    ? [(string) SDH_Sales::b(self::kulcs($k, 'sotet'), ''), (string) SDH_Sales::b(self::kulcs($k, 'vilagos'), '')]
                    : [(string) SDH_Sales::b(self::kulcs($k), ''), ''],
            ];
        }

        return [
            'tokenek' => $tok,
            'betuk'   => self::betuk(),
            'mod'     => self::mod(),
            'logo'    => (string) SDH_Sales::b('arculat.logo', ''),
            'logo_vilagos' => (string) SDH_Sales::b('arculat.logo_vilagos', ''),
            'favicon' => (string) SDH_Sales::b('arculat.favicon', ''),
            'tema_tokenek' => self::tema_tokenek() !== [],
        ];
    }

    /**
     * Mentés: `ertekek` = {token: [sötét, világos] | érték}, + mod, logo, logo_vilagos, favicon. Az üres érték = alap.
     *
     * @param array<string, mixed> $be
     * @return array{mentve: int, hibas: array<int, string>}
     */
    public static function ment(array $be): array
    {
        $uj    = [];
        $hibas = [];
        $alap  = self::alapok();

        foreach ((array) ($be['ertekek'] ?? []) as $tok => $v) {
            $tok = (string) $tok;

            if (!isset(self::tokenek()[$tok])) {
                continue;
            }

            $par = self::modos($tok) ? (is_array($v) ? array_values($v) + ['', ''] : [(string) $v, '']) : [is_array($v) ? (string) ($v[0] ?? '') : (string) $v];

            foreach ($par as $i => $x) {
                $x   = trim((string) $x);
                $mod = self::modos($tok) ? ($i === 0 ? 'sotet' : 'vilagos') : '';
                $k   = self::kulcs($tok, $mod);

                if ($x === '') {
                    $uj[$k] = '';
                    continue;
                }

                $e = self::ervenyes($tok, $x);

                if ($e === null) {
                    $hibas[] = $tok . ($mod !== '' ? ' (' . ($mod === 'sotet' ? 'sötét' : 'világos') . ')' : '');
                    continue;
                }

                // Az alappal egyező érték nem felülírás.
                $uj[$k] = $e === $alap[$tok][$i] ? '' : $e;
            }
        }

        if (isset($be['mod'])) {
            $uj['arculat.mod'] = in_array($be['mod'], ['rendszer', 'sotet', 'vilagos'], true) ? (string) $be['mod'] : 'rendszer';
        }

        foreach (['logo', 'logo_vilagos', 'favicon'] as $k) {
            if (array_key_exists($k, $be)) {
                $u = SDH_Sales_Szekcio::url((string) $be[$k]);
                $uj['arculat.' . $k] = $u;
            }
        }

        // Üres = törlés (a beállításból kivesszük, így az alap él).
        $torol = array_keys(array_filter($uj, static fn ($v) => $v === ''));
        $ment  = array_filter($uj, static fn ($v) => $v !== '');

        if ($ment !== []) {
            SDH_Sales::b_ment($ment, 'arculat');
        }

        if ($torol !== []) {
            $m  = get_option(SDH_Sales::OPT, []);
            $m  = is_array($m) ? $m : [];
            $el = [];

            foreach ($torol as $k) {
                if (array_key_exists($k, $m)) {
                    $el[$k] = $m[$k];
                    unset($m[$k]);
                }
            }

            if ($el !== []) {
                SDH_Sales::b_felulir($m);
                SDH_Sales_Naplo::ir('arculat', 'Arculat visszaállítva az alapra: ' . implode(', ', array_keys($el)), 'info', 'beallitas', 0, $el, array_fill_keys(array_keys($el), null));
            }
        }

        return ['mentve' => count($ment) + count($torol), 'hibas' => $hibas];
    }

    public static function mod(): string
    {
        $m = (string) SDH_Sales::b('arculat.mod', 'rendszer');

        return in_array($m, ['rendszer', 'sotet', 'vilagos'], true) ? $m : 'rendszer';
    }

    /** A logó URL-je az adott módhoz (világosnál a világos logó, ha van). */
    public static function logo(string $mod = 'sotet'): string
    {
        $v = $mod === 'vilagos' ? (string) SDH_Sales::b('arculat.logo_vilagos', '') : '';

        return $v !== '' ? $v : (string) SDH_Sales::b('arculat.logo', '');
    }

    /* =================================================================
     * Kiírás
     * ============================================================== */

    /**
     * A `:root` felülírás (csak az eltérő, érvényes értékek). Üres, ha nincs eltérés.
     * `$ertekek` (beállításkulcs → érték) az előnézethez: a még nem mentett értékek.
     *
     * @param array<string, mixed>|null $ertekek
     */
    public static function css(?array $ertekek = null): string
    {
        $kozos = [];
        $sotet = [];
        $vil   = [];
        $olvas = static fn (string $k): string => $ertekek !== null ? (string) ($ertekek[$k] ?? '') : (string) SDH_Sales::b($k, '');

        foreach (self::tokenek() as $tok => $m) {
            if (self::modos($tok)) {
                foreach (['sotet', 'vilagos'] as $mod) {
                    $v = self::ervenyes($tok, $olvas(self::kulcs($tok, $mod)));

                    if ($v !== null) {
                        if ($mod === 'sotet') {
                            $sotet[] = '--s-' . $tok . ':' . $v;
                        } else {
                            $vil[] = '--s-' . $tok . ':' . $v;
                        }
                    }
                }
                continue;
            }

            $v = self::ervenyes($tok, $olvas(self::kulcs($tok)));

            if ($v !== null) {
                $kozos[] = '--s-' . $tok . ':' . $v;
            }
        }

        $css = '';

        if ($kozos !== []) {
            $css .= ':root{' . implode(';', $kozos) . '}';
        }

        if ($sotet !== []) {
            $css .= ':root,:root[data-tema="sotet"]{' . implode(';', $sotet) . '}';
        }

        if ($vil !== []) {
            $css .= ':root[data-tema="vilagos"]{' . implode(';', $vil) . '}';
            $css .= '@media (prefers-color-scheme: light){:root:not([data-tema]){' . implode(';', $vil) . '}}';
        }

        // Végső védőháló: semmi, ami kitörhetne a <style>-ból.
        return str_ireplace(['</', '<!--', '-->'], '', $css);
    }

    /**
     * Az admin-űrlap értékeiből (`ertekek` = {token: [sötét, világos] | érték}) beállításkulcs → érték térkép (előnézethez).
     *
     * @param array<string, mixed> $be
     * @return array<string, string>
     */
    public static function terkep(array $be): array
    {
        $ki = [];

        foreach ((array) ($be['ertekek'] ?? []) as $tok => $v) {
            $tok = (string) $tok;

            if (!isset(self::tokenek()[$tok])) {
                continue;
            }

            if (self::modos($tok)) {
                $par = is_array($v) ? array_values($v) + ['', ''] : [(string) $v, ''];
                $ki[self::kulcs($tok, 'sotet')]   = (string) $par[0];
                $ki[self::kulcs($tok, 'vilagos')] = (string) $par[1];
            } else {
                $ki[self::kulcs($tok)] = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
            }
        }

        return $ki;
    }

    /** Az előnézeti értékek bekapcsolása erre a kérésre (az admin előnézeti tokenje alapján). @param array<string, string> $terkep */
    public static function elonezet(array $terkep): void
    {
        self::$elonezet = $terkep;
    }

    public static function kiir(): void
    {
        $css = self::css(self::$elonezet);

        if ($css !== '') {
            echo '<style id="sdh-sales-arculat">' . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- érvényesített tokenek.
        }
    }

    public static function favicon(): void
    {
        $f = (string) SDH_Sales::b('arculat.favicon', '');

        if ($f === '' || has_site_icon()) {
            return;
        }

        echo '<link rel="icon" href="' . esc_url($f) . '">' . "\n";
    }

    public static function html_attr(string $a): string
    {
        if (is_admin()) {
            return $a;
        }

        $m = self::mod();

        if ($m !== 'rendszer' && strpos($a, 'data-tema') === false) {
            $a .= ' data-tema="' . esc_attr($m) . '"';
        }

        return $a;
    }
}
