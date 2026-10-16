<?php
/**
 * A bejelentkező oldal testreszabása (H6b, 0.80.0).
 *
 * A saját bejelentkező (/muhely/belepes/, SDH_Muhely_Rejtes) minden eleme az adminból
 * állítható: logó és mérete, háttér (a rendszer alapja, szín, átmenet, kép, videó),
 * a doboz színe, üveghatása, neon fénye és helye, a szövegek, a betűtípus és a gomb
 * színe. Ami üres, az a rendszer Arculatát követi – új telepítésen semmit nem kell
 * állítani. Mobilon (és ha a gép kéri a mozgás csökkentését) a videó helyett a kép áll.
 *
 * Beállítások › Bejelentkező oldal – élő előnézettel (a mentés előtt is a valódi
 * oldalt mutatja: ugyanaz a PHP rajzolja, mint a bejelentkezőt).
 *
 * A beállítás egy opcióban (`sdh_muhely_belepo`) áll, ezért a mentés és az
 * Export / Import magától viszi. Cégadat nincs a kódban: minden alapérték semleges.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Belepo
{
    public const OPT = 'sdh_muhely_belepo';

    public const LOGO_MIN  = 24;
    public const LOGO_MAX  = 160;
    public const LOGO_ALAP = 42;

    public const SOTET_MAX    = 85;
    public const ATLATSZO_MAX = 90;
    public const ELMOSAS_MAX  = 40;

    public const ELFELEJTETT_ALAP = 'Elfelejtett jelszó: szólj az adminisztrátornak.';
    public const GOMB_ALAP        = 'Belépés';

    /** Ennyi pixeltől szélesebb képernyőn jön a videó (alatta a kép). */
    public const MOBIL_HATAR = 700;

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_belepo_elonezet', [self::class, 'ajax_elonezet']);
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /** @return array<string, mixed> */
    public static function alapok(): array
    {
        return [
            // Logó és szövegek
            'logo'             => '',          // üres = az Arculat logója (ha az sincs: a jel)
            'logo_meret'       => self::LOGO_ALAP,
            'fej'              => 'sor',       // sor = a logó a cím mellett, felul = a cím fölött, középen
            'cim'              => '',          // üres = a rendszer neve
            'alcim'            => '',
            'elfelejtett'      => self::ELFELEJTETT_ALAP, // üres = nem látszik
            'gomb_szoveg'      => self::GOMB_ALAP,
            // Háttér
            'hatter'           => 'alap',      // alap | szin | atmenet | kep | video
            'hatter_szin'      => '',          // üres = a rendszer vászonszíne (átmenetnél: a kiemelő szín)
            'hatter_szin2'     => '',          // az átmenet másik színe; üres = a vászonszín
            'atmenet_irany'    => 135,
            'hatter_kep'       => '',
            'hatter_video'     => '',
            'sotetites'        => 30,          // kép / videó fölött, %
            // Doboz
            'hely'             => 'kozep',     // kozep | bal | jobb
            'doboz_szin'       => '',          // üres = a felület színe (világos / sötét szerint)
            'szoveg_szin'      => '',          // üres = magától (a doboz színéhez)
            'uveg'             => false,
            'atlatszo'         => 25,          // üveghatásnál a doboz átlátszósága, %
            'elmosas'          => 14,          // üveghatásnál a háttér elmosása, px
            'neon'             => 'arculat',   // arculat = mint a popupoké; '' = nincs; vagy egy neon szín
            'neon_szin'        => '',
            'neon_ero'         => '',          // üres = mint a popupoké
            // Betű és gomb
            'betutipus'        => '',          // üres = mint a rendszerben (Arculat)
            'betutipus_egyeni' => '',
            'gomb_szin'        => '',          // üres = a kiemelő szín
        ];
    }

    /** @return array<string, string> */
    public static function hatterek(): array
    {
        return [
            'alap'    => 'A rendszer háttere',
            'szin'    => 'Egyszínű',
            'atmenet' => 'Színátmenet',
            'kep'     => 'Kép',
            'video'   => 'Videó',
        ];
    }

    /** @return array<string, string> */
    public static function helyek(): array
    {
        return ['kozep' => 'Középen', 'bal' => 'Bal oldalt', 'jobb' => 'Jobb oldalt'];
    }

    /** @return array<string, string> */
    public static function fejek(): array
    {
        return ['sor' => 'A logó a cím mellett', 'felul' => 'A logó a cím fölött, középen'];
    }

    /** A doboz neon fénye: „mint a popupoké” + az Arculat neon színei. @return array<string, string> */
    public static function neonok(): array
    {
        return ['arculat' => 'Mint a popupoké (Logó és megjelenés)'] + SDH_Muhely_Arculat::neonok();
    }

    /** @return array<string, string> */
    public static function neon_erok(): array
    {
        return ['' => 'Mint a popupoké'] + SDH_Muhely_Arculat::neon_erok();
    }

    /**
     * Betűtípusok: üres = mint a rendszerben; `gep` = a gép betűje; a többi az Arculat listája.
     *
     * @return array<string, array{nev: string, csalad: string}>
     */
    public static function betutipusok(): array
    {
        $lista = ['' => ['nev' => 'Mint a rendszerben (Logó és megjelenés)', 'csalad' => '']];

        foreach (SDH_Muhely_Arculat::betutipusok() as $kulcs => $bt) {
            $lista[$kulcs === '' ? 'gep' : $kulcs] = $bt;
        }

        return $lista;
    }

    /** A mentett beállítás, a hiányzó kulcsok az alapértékkel. @return array<string, mixed> */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);

        return self::szukit(is_array($m) ? $m : []);
    }

    /**
     * Nyers (mentett vagy beküldött) adatból érvényes beállítás. A beküldött űrlapnál a ki nem
     * pipált „Üveghatás” hiányzik – az ugyanúgy „ki”, mint a mentett hamis érték.
     *
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    public static function szukit(array $m): array
    {
        $a = self::alapok();
        $b = array_merge($a, array_intersect_key($m, $a));

        $kozott = static fn ($ertek, int $min, int $max): int => max($min, min($max, (int) $ertek));
        $egyik  = static fn ($ertek, array $lista, string $alap): string => isset($lista[(string) $ertek]) ? (string) $ertek : $alap;
        $szoveg = static fn ($ertek, int $hossz): string => mb_substr(trim(sanitize_text_field((string) $ertek)), 0, $hossz);
        $cim    = static fn ($ertek): string => esc_url_raw(trim((string) $ertek));

        $r = [
            'logo'             => $cim($b['logo']),
            'logo_meret'       => $kozott($b['logo_meret'], self::LOGO_MIN, self::LOGO_MAX),
            'fej'              => $egyik($b['fej'], self::fejek(), 'sor'),
            'cim'              => $szoveg($b['cim'], 60),
            'alcim'            => $szoveg($b['alcim'], 140),
            'elfelejtett'      => $szoveg($b['elfelejtett'], 160),
            'gomb_szoveg'      => $szoveg($b['gomb_szoveg'], 30),
            'hatter'           => $egyik($b['hatter'], self::hatterek(), 'alap'),
            'hatter_szin'      => self::hex((string) $b['hatter_szin']),
            'hatter_szin2'     => self::hex((string) $b['hatter_szin2']),
            'atmenet_irany'    => (int) (round($kozott($b['atmenet_irany'], 0, 360) / 15) * 15) % 360,
            'hatter_kep'       => $cim($b['hatter_kep']),
            'hatter_video'     => $cim($b['hatter_video']),
            'sotetites'        => $kozott($b['sotetites'], 0, self::SOTET_MAX),
            'hely'             => $egyik($b['hely'], self::helyek(), 'kozep'),
            'doboz_szin'       => self::hex((string) $b['doboz_szin']),
            'szoveg_szin'      => self::hex((string) $b['szoveg_szin']),
            'uveg'             => !empty($b['uveg']),
            'atlatszo'         => $kozott($b['atlatszo'], 0, self::ATLATSZO_MAX),
            'elmosas'          => $kozott($b['elmosas'], 0, self::ELMOSAS_MAX),
            'neon'             => $egyik($b['neon'], self::neonok(), 'arculat'),
            'neon_szin'        => self::hex((string) $b['neon_szin']),
            'neon_ero'         => $egyik($b['neon_ero'], self::neon_erok(), ''),
            'betutipus'        => $egyik($b['betutipus'], self::betutipusok(), ''),
            'betutipus_egyeni' => trim(mb_substr((string) preg_replace('/[^\p{L}\p{N} \-]/u', '', (string) $b['betutipus_egyeni']), 0, 60)),
            'gomb_szin'        => self::hex((string) $b['gomb_szin']),
        ];

        // A gomb felirat nélkül nem maradhat.
        if ($r['gomb_szoveg'] === '') {
            $r['gomb_szoveg'] = self::GOMB_ALAP;
        }

        // Egyéni neon csak színkóddal, egyéni betű csak névvel él.
        if ($r['neon'] === 'egyeni' && $r['neon_szin'] === '') {
            $r['neon'] = 'arculat';
        }

        if ($r['betutipus'] === 'egyeni' && $r['betutipus_egyeni'] === '') {
            $r['betutipus'] = '';
        }

        // Kép / videó nélkül a háttér a rendszeré marad (a videóhoz elég a videó: a kép mobilon kell).
        if (($r['hatter'] === 'kep' && $r['hatter_kep'] === '') || ($r['hatter'] === 'video' && $r['hatter_video'] === '')) {
            $r['hatter'] = 'alap';
        }

        return $r;
    }

    /* =================================================================
     * Amit a bejelentkező oldal kap
     * ============================================================== */

    /** A logó címe: a saját, különben az Arculaté (üres: a jel látszik). */
    public static function logo(array $b): string
    {
        return (string) $b['logo'] !== '' ? (string) $b['logo'] : (string) SDH_Muhely_Arculat::beallitas()['logo'];
    }

    public static function cim(array $b): string
    {
        return (string) $b['cim'] !== '' ? (string) $b['cim'] : SDH_Muhely_Arculat::rendszer_nev();
    }

    /**
     * A neon: [kulcs, erősség, egyéni szín] – a „mint a popupoké” az Arculatból. Üres kulcs: nincs.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function neon(array $b): array
    {
        $arc = SDH_Muhely_Arculat::beallitas();

        if ($b['neon'] === 'arculat') {
            $kulcs = (string) $arc['neon'];
            $szin  = (string) $arc['neon_egyeni'];
        } else {
            $kulcs = (string) $b['neon'];
            $szin  = (string) $b['neon_szin'];
        }

        $ero = (string) $b['neon_ero'] !== '' ? (string) $b['neon_ero'] : (string) $arc['neon_ero'];

        return [$kulcs, $ero, $kulcs === 'egyeni' ? $szin : ''];
    }

    /** A <html> elem attribútumai (neon), szűrve. */
    public static function html_attr(array $b): string
    {
        [$kulcs, $ero] = self::neon($b);

        return $kulcs === '' ? '' : ' data-neon="' . esc_attr($kulcs) . '" data-neon-ero="' . esc_attr($ero) . '"';
    }

    /** A <body> attribútumai (hely, háttér, fej, üveg), szűrve. */
    public static function body_attr(array $b): string
    {
        return ' data-hely="' . esc_attr((string) $b['hely']) . '"'
            . ' data-hatter="' . esc_attr((string) $b['hatter']) . '"'
            . ' data-fej="' . esc_attr((string) $b['fej']) . '"'
            . (!empty($b['uveg']) ? ' data-uveg-doboz="1"' : '');
    }

    /**
     * A beállításból adódó stílus: CSS-változók (a szabályok az app.css-ben vannak). Csak
     * ellenőrzött színkód, szám és szűrt cím kerül bele.
     */
    public static function css(array $b): string
    {
        $gyoker = ['--belepo-logo:' . (int) $b['logo_meret'] . 'px'];
        $css    = '';

        // Háttér.
        $alap = $b['hatter_szin'] !== '' ? $b['hatter_szin'] : 'var(--sdh-vaszon)';

        switch ($b['hatter']) {
            case 'szin':
                $gyoker[] = '--belepo-hatter:' . $alap;
                break;

            case 'atmenet':
                $egy      = $b['hatter_szin'] !== '' ? $b['hatter_szin'] : 'var(--sdh-accent)';
                $ketto    = $b['hatter_szin2'] !== '' ? $b['hatter_szin2'] : 'var(--sdh-vaszon)';
                $gyoker[] = '--belepo-hatter:linear-gradient(' . (int) $b['atmenet_irany'] . 'deg,' . $egy . ',' . $ketto . ')';
                break;

            case 'kep':
            case 'video':
                $gyoker[] = '--belepo-hatter:' . $alap;
                $gyoker[] = '--belepo-sotet:' . number_format((int) $b['sotetites'] / 100, 2, '.', '');

                if ($b['hatter_kep'] !== '') {
                    // <style>-ban nincs HTML-kódolás: nyers, szűrt cím (az esc_url_raw idézőjelet, < jelet nem hagy).
                    $gyoker[] = '--belepo-kep:url("' . esc_url_raw($b['hatter_kep']) . '")';
                }
                break;
        }

        // A doboz: szín, átlátszóság, elmosás.
        $fedes = !empty($b['uveg']) ? 100 - (int) $b['atlatszo'] : 100;

        if ($b['doboz_szin'] !== '') {
            [$r, $g, $k] = self::rgb($b['doboz_szin']);
            $gyoker[]    = '--belepo-doboz:rgba(' . $r . ',' . $g . ',' . $k . ',' . number_format($fedes / 100, 2, '.', '') . ')';
        } elseif ($fedes < 100) {
            $gyoker[] = '--belepo-doboz:color-mix(in srgb, var(--sdh-felulet) ' . $fedes . '%, transparent)';
        }

        if (!empty($b['uveg'])) {
            $gyoker[] = '--belepo-elmosas:' . (int) $b['elmosas'] . 'px';
        }

        // Szöveg: a megadott szín, egyéni dobozszínnél különben a dobozhoz illő (világos / sötét).
        $szoveg = $b['szoveg_szin'] !== '' ? $b['szoveg_szin'] : ($b['doboz_szin'] !== '' ? self::felirat($b['doboz_szin']) : '');

        if ($szoveg !== '') {
            [$r, $g, $k] = self::rgb($szoveg);
            $doboz       = ['--belepo-cimke:' . $szoveg, '--belepo-halvany:rgba(' . $r . ',' . $g . ',' . $k . ',.72)'];

            // Egyéni dobozszínnél a mezők is a dobozhoz igazodnak (különben fehér szöveg fehér mezőben).
            if ($b['doboz_szin'] !== '') {
                array_push(
                    $doboz,
                    '--sdh-felulet:' . self::kever($b['doboz_szin'], $szoveg, 0.08),
                    '--sdh-szoveg:' . $szoveg,
                    '--sdh-keret:rgba(' . $r . ',' . $g . ',' . $k . ',.18)',
                    '--sdh-keret-eros:rgba(' . $r . ',' . $g . ',' . $k . ',.32)'
                );
            }

            $css .= 'body.sdh-belepo .sdh-belepes{' . implode(';', $doboz) . '}';
        }

        // Gomb: saját kiemelő szín csak a gombon.
        if ($b['gomb_szin'] !== '') {
            $css .= 'body.sdh-belepo .sdh-belepes__gomb{--sdh-accent:' . $b['gomb_szin']
                . ';--sdh-accent-sotet:' . self::kever($b['gomb_szin'], '#000000', 0.18)
                . ';--sdh-accent-felirat:' . self::felirat($b['gomb_szin']) . '}';
        }

        // Betűtípus.
        $csalad = self::betucsalad($b);

        if ($csalad !== '') {
            $gyoker[] = '--sdh-betutipus:' . $csalad;
            $css     .= 'body.sdh-belepo{font-family:' . $csalad . '}';
        }

        // Egyéni neon szín.
        [, , $neon] = self::neon($b);

        if ($neon !== '') {
            $gyoker[] = '--sdh-popneon-egyeni:' . $neon;
        }

        return ':root{' . implode(';', $gyoker) . '}' . $css;
    }

    /** A választott betű CSS font-family értéke; üres = mint a rendszerben. */
    public static function betucsalad(array $b): string
    {
        $kulcs = (string) $b['betutipus'];

        if ($kulcs === '') {
            return '';
        }

        $lista = self::betutipusok();

        if ($kulcs === 'egyeni') {
            return '"' . $b['betutipus_egyeni'] . '", ' . $lista['gep']['csalad'];
        }

        return (string) ($lista[$kulcs]['csalad'] ?? '');
    }

    /* =================================================================
     * Színsegédek
     * ============================================================== */

    /** Érvényes #rrggbb (a #rgb is), vagy üres. */
    public static function hex(string $ertek): string
    {
        $ertek = strtolower(trim($ertek));

        if ($ertek !== '' && $ertek[0] !== '#') {
            $ertek = '#' . $ertek;
        }

        if (preg_match('/^#[0-9a-f]{3}$/', $ertek) === 1) {
            $ertek = '#' . $ertek[1] . $ertek[1] . $ertek[2] . $ertek[2] . $ertek[3] . $ertek[3];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $ertek) === 1 ? $ertek : '';
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /** Az egyik szín a másik felé keverve (0 = az első, 1 = a második). */
    private static function kever(string $egy, string $ketto, float $arany): string
    {
        $a = self::rgb($egy);
        $b = self::rgb($ketto);

        return sprintf('#%02x%02x%02x', ...array_map(static fn (int $x, int $y): int => (int) round($x + ($y - $x) * $arany), $a, $b));
    }

    /** A színen olvasható felirat: sötét a világoson, fehér a sötéten. */
    public static function felirat(string $hex): string
    {
        [$r, $g, $k] = self::rgb($hex);

        return (0.299 * $r + 0.587 * $g + 0.114 * $k) > 160 ? '#16191d' : '#ffffff';
    }

    /* =================================================================
     * Beállítások › Bejelentkező oldal
     * ============================================================== */

    private static function szin_mezo(string $kulcs, string $cimke, string $ertek, string $alap, string $sugo): void
    {
        $id = 'belepo_' . $kulcs;
        ?>
        <div class="sdh-mezo">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($cimke); ?></label>
            <div class="sdh-rma-szin sdh-arculat-szin">
                <input type="color" aria-label="Színválasztó" value="<?php echo esc_attr($ertek !== '' ? $ertek : $alap); ?>"
                       data-sdh-szin-cel="<?php echo esc_attr($id); ?>">
                <input type="text" name="belepo[<?php echo esc_attr($kulcs); ?>]" id="<?php echo esc_attr($id); ?>" maxlength="7"
                       pattern="#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?" placeholder="alap" autocomplete="off"
                       value="<?php echo esc_attr($ertek); ?>">
            </div>
            <span class="sdh-mezo__sugo"><?php echo esc_html($sugo); ?></span>
        </div>
        <?php
    }

    private static function media_mezo(string $kulcs, string $cimke, string $ertek, string $tipus, string $sugo): void
    {
        $id = 'belepo_' . $kulcs;
        ?>
        <div class="sdh-mezo sdh-mezo--szeles">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($cimke); ?></label>
            <div class="sdh-rma-media" data-sdh-media="<?php echo esc_attr($tipus); ?>">
                <input type="text" name="belepo[<?php echo esc_attr($kulcs); ?>]" id="<?php echo esc_attr($id); ?>"
                       value="<?php echo esc_attr($ertek); ?>" placeholder="https://… – vagy töltsd fel a gombbal">
                <button type="button" class="sdh-gomb" data-sdh-media-valaszt>Feltöltés…</button>
                <button type="button" class="sdh-gomb" data-sdh-media-torol<?php echo $ertek === '' ? ' hidden' : ''; ?>>Törlés</button>
                <span class="sdh-rma-media__elo" data-sdh-media-elo>
                    <?php if ($ertek !== '' && $tipus === 'image') : ?>
                        <img src="<?php echo esc_url($ertek); ?>" alt="">
                    <?php elseif ($ertek !== '' && $tipus === 'video') : ?>
                        <video src="<?php echo esc_url($ertek); ?>" muted playsinline preload="metadata"></video>
                    <?php endif; ?>
                </span>
            </div>
            <span class="sdh-mezo__sugo"><?php echo esc_html($sugo); ?></span>
        </div>
        <?php
    }

    /** @param array<string, string> $lista */
    private static function valaszto(string $kulcs, string $cimke, string $ertek, array $lista, string $sugo = ''): void
    {
        $id = 'belepo_' . $kulcs;
        ?>
        <div class="sdh-mezo">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($cimke); ?></label>
            <select name="belepo[<?php echo esc_attr($kulcs); ?>]" id="<?php echo esc_attr($id); ?>">
                <?php foreach ($lista as $k => $nev) : ?>
                    <option value="<?php echo esc_attr((string) $k); ?>" <?php selected($ertek, (string) $k); ?>><?php echo esc_html($nev); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($sugo !== '') : ?>
                <span class="sdh-mezo__sugo"><?php echo esc_html($sugo); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function csuszka(string $kulcs, string $cimke, int $ertek, int $min, int $max, int $lepes, string $egyseg, string $sugo = ''): void
    {
        $id = 'belepo_' . $kulcs;
        ?>
        <div class="sdh-mezo">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($cimke); ?>: <output data-sdh-kimenet="<?php echo esc_attr($id); ?>"><?php echo (int) $ertek; ?></output><?php echo esc_html($egyseg); ?></label>
            <input type="range" name="belepo[<?php echo esc_attr($kulcs); ?>]" id="<?php echo esc_attr($id); ?>"
                   min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>" step="<?php echo (int) $lepes; ?>" value="<?php echo (int) $ertek; ?>">
            <?php if ($sugo !== '') : ?>
                <span class="sdh-mezo__sugo"><?php echo esc_html($sugo); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function szoveg_mezo(string $kulcs, string $cimke, string $ertek, int $hossz, string $hely, string $sugo): void
    {
        $id = 'belepo_' . $kulcs;
        ?>
        <div class="sdh-mezo sdh-mezo--szeles">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($cimke); ?></label>
            <input type="text" name="belepo[<?php echo esc_attr($kulcs); ?>]" id="<?php echo esc_attr($id); ?>" maxlength="<?php echo (int) $hossz; ?>"
                   autocomplete="off" placeholder="<?php echo esc_attr($hely); ?>" value="<?php echo esc_attr($ertek); ?>">
            <span class="sdh-mezo__sugo"><?php echo esc_html($sugo); ?></span>
        </div>
        <?php
    }

    /** A lapfül tartalma (űrlap nélkül: azt a Beállítások adja). */
    public static function beallitas_doboz(): void
    {
        $b   = self::beallitas();
        $arc = SDH_Muhely_Arculat::beallitas();

        $betuk = [];
        foreach (self::betutipusok() as $k => $bt) {
            $betuk[$k] = $bt['nev'];
        }

        $nyitott = class_exists('SDH_Muhely_Rejtes') && !SDH_Muhely_Rejtes::zart();
        ?>
        <div class="sdh-belepo-beall">
            <div class="sdh-belepo-beall__mezok">
                <?php if ($nyitott) : ?>
                    <p class="sdh-uzenet sdh-uzenet--figyelem">
                        A zárt mód most ki van kapcsolva: a belépés nem ezen az oldalon történik. A beállítás
                        megmarad, és a zárt mód visszakapcsolásakor ez az oldal jelenik meg.
                    </p>
                <?php endif; ?>

                <div class="sdh-doboz" id="belepo-logo">
                    <h2 class="sdh-doboz__cim">Logó és szövegek</h2>

                    <p class="sdh-sugo">
                        Ami üres, az a <strong>Logó és megjelenés</strong> lapfül szerint látszik (a rendszer logója, neve).
                        A jobb oldali előnézet mentés nélkül is mutatja a változást.
                    </p>

                    <div class="sdh-mezok">
                        <?php self::media_mezo('logo', 'Logó a bejelentkezőn', (string) $b['logo'], 'image', 'Üresen: a rendszer logója' . ((string) $arc['logo'] === '' ? ' (most nincs: a jel látszik).' : '.')); ?>
                        <?php self::csuszka('logo_meret', 'Logó magassága', (int) $b['logo_meret'], self::LOGO_MIN, self::LOGO_MAX, 2, ' px', 'A logó (vagy a jel) magassága; a szélessége ehhez igazodik.'); ?>
                        <?php self::valaszto('fej', 'Logó és cím elrendezése', (string) $b['fej'], self::fejek()); ?>
                        <?php self::szoveg_mezo('cim', 'Címsor', (string) $b['cim'], 60, SDH_Muhely_Arculat::rendszer_nev(), 'Üresen: a rendszer neve.'); ?>
                        <?php self::szoveg_mezo('alcim', 'Alcím', (string) $b['alcim'], 140, 'pl. Jó munkát!', 'A címsor alatt, kisebb betűvel. Üresen nem látszik.'); ?>
                        <?php self::szoveg_mezo('elfelejtett', 'Az „Elfelejtett jelszó” sor', (string) $b['elfelejtett'], 160, self::ELFELEJTETT_ALAP, 'A doboz alján. Üresen nem látszik.'); ?>
                        <?php self::szoveg_mezo('gomb_szoveg', 'A gomb felirata', (string) $b['gomb_szoveg'], 30, self::GOMB_ALAP, 'Üresen: „' . self::GOMB_ALAP . '”.'); ?>
                    </div>
                </div>

                <div class="sdh-doboz" id="belepo-hatter">
                    <h2 class="sdh-doboz__cim">Háttér</h2>

                    <div class="sdh-mezok">
                        <div class="sdh-mezo sdh-mezo--szeles">
                            <label>Háttér</label>
                            <div class="sdh-rma-valaszto">
                                <?php foreach (self::hatterek() as $k => $nev) : ?>
                                    <label><input type="radio" name="belepo[hatter]" value="<?php echo esc_attr($k); ?>" <?php checked($b['hatter'], $k); ?>> <?php echo esc_html($nev); ?></label>
                                <?php endforeach; ?>
                            </div>
                            <span class="sdh-mezo__sugo">A rendszer háttere: ugyanaz, mint a műhely-felületen (világos / sötét szerint).</span>
                        </div>

                        <?php self::szin_mezo('hatter_szin', 'Háttérszín', (string) $b['hatter_szin'], '#1b1f24', 'Egyszínűnél ez; átmenetnél az első szín (üresen: a kiemelő szín); képnél és videónál ez látszik, amíg betölt.'); ?>
                        <?php self::szin_mezo('hatter_szin2', 'Az átmenet második színe', (string) $b['hatter_szin2'], '#0f1115', 'Üresen: a felület háttérszíne.'); ?>
                        <?php self::csuszka('atmenet_irany', 'Az átmenet iránya', (int) $b['atmenet_irany'], 0, 345, 15, '°'); ?>
                        <?php self::csuszka('sotetites', 'Kép / videó sötétítése', (int) $b['sotetites'], 0, self::SOTET_MAX, 5, '%', 'Világos képen így olvasható marad a doboz körüli rész.'); ?>
                        <?php self::media_mezo('hatter_kep', 'Háttérkép', (string) $b['hatter_kep'], 'image', 'JPG vagy WebP, legalább 1600 px széles. Videós háttérnél ez áll mobilon (és amíg a videó betölt).'); ?>
                        <?php self::media_mezo('hatter_video', 'Háttérvideó', (string) $b['hatter_video'], 'video', 'MP4 (H.264) vagy WebM, hang nélkül ismétlődik. Mobilon nem töltődik le: ott a háttérkép áll. Rövid, kis fájlt válassz (néhány MB).'); ?>
                    </div>
                </div>

                <div class="sdh-doboz" id="belepo-doboz">
                    <h2 class="sdh-doboz__cim">A belépő doboz</h2>

                    <div class="sdh-mezok">
                        <?php self::valaszto('hely', 'A doboz helye', (string) $b['hely'], self::helyek(), 'Mobilon mindig középen.'); ?>
                        <?php self::szin_mezo('doboz_szin', 'A doboz színe', (string) $b['doboz_szin'], '#ffffff', 'Üresen: a felület színe (világos / sötét szerint).'); ?>
                        <?php self::szin_mezo('szoveg_szin', 'A szöveg színe', (string) $b['szoveg_szin'], '#16191d', 'Üresen: magától – a doboz színén olvasható.'); ?>

                        <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                            <input type="checkbox" name="belepo[uveg]" id="belepo_uveg" value="1" <?php checked(!empty($b['uveg'])); ?>>
                            <label for="belepo_uveg">Üveghatás (áttetsző doboz, mögötte elmosott háttér)</label>
                        </div>

                        <?php self::csuszka('atlatszo', 'Átlátszóság', (int) $b['atlatszo'], 0, self::ATLATSZO_MAX, 5, '%', 'Csak üveghatással.'); ?>
                        <?php self::csuszka('elmosas', 'Elmosás', (int) $b['elmosas'], 0, self::ELMOSAS_MAX, 2, ' px', 'Csak üveghatással.'); ?>
                        <?php self::valaszto('neon', 'Neon fény a doboz körül', (string) $b['neon'], self::neonok()); ?>
                        <?php self::szin_mezo('neon_szin', 'Egyéni neon szín', (string) $b['neon_szin'], '#00e5ff', 'Csak az „Egyéni szín” választásnál.'); ?>
                        <?php self::valaszto('neon_ero', 'A neon fényereje', (string) $b['neon_ero'], self::neon_erok()); ?>
                    </div>
                </div>

                <div class="sdh-doboz" id="belepo-betu">
                    <h2 class="sdh-doboz__cim">Betű és gomb</h2>

                    <div class="sdh-mezok">
                        <?php self::valaszto('betutipus', 'Betűtípus', (string) $b['betutipus'], $betuk, 'Csak a gépen meglévő betű: a rendszer nem tölt le betűt az internetről.'); ?>
                        <?php self::szoveg_mezo('betutipus_egyeni', 'Egyéni betű neve', (string) $b['betutipus_egyeni'], 60, 'pl. Montserrat', 'Csak az „Egyéni” választásnál számít; a betűnek telepítve kell lennie a gépen.'); ?>
                        <?php self::szin_mezo('gomb_szin', 'A gomb színe', (string) $b['gomb_szin'], '#d4231d', 'Üresen: a kiemelő szín. A felirat magától fehér vagy sötét.'); ?>
                    </div>
                </div>
            </div>

            <aside class="sdh-belepo-beall__elonezet" aria-label="Élő előnézet">
                <div class="sdh-doboz sdh-belepo-elonezet" data-sdh-belepo-elonezet data-mod="asztal">
                    <div class="sdh-belepo-elonezet__fej">
                        <h2 class="sdh-doboz__cim">Élő előnézet</h2>
                        <div class="sdh-belepo-elonezet__mod" role="group" aria-label="Képernyő">
                            <button type="button" class="sdh-pill is-aktiv" data-sdh-belepo-mod="asztal" aria-pressed="true">Asztali gép</button>
                            <button type="button" class="sdh-pill" data-sdh-belepo-mod="mobil" aria-pressed="false">Mobil</button>
                        </div>
                    </div>
                    <div class="sdh-belepo-elonezet__keret" data-sdh-belepo-keret>
                        <iframe class="sdh-belepo-elonezet__lap" title="A bejelentkező oldal előnézete" tabindex="-1"
                                data-sdh-belepo-lap srcdoc="<?php echo esc_attr(SDH_Muhely_Rejtes::belepo_html('', '', '', $b, true)); ?>"></iframe>
                    </div>
                    <p class="sdh-mezo__sugo" data-sdh-belepo-allapot>A változás azonnal látszik; a bejelentkezőn a <strong>Mentés</strong> után.</p>
                </div>
            </aside>
        </div>
        <?php
    }

    /** A lapfül mentése (a Beállítások ellenőrizte a jogot és a nonce-ot). */
    public static function beallitas_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        $nyers = isset($_POST['belepo']) && is_array($_POST['belepo']) ? wp_unslash($_POST['belepo']) : [];

        update_option(self::OPT, self::szukit(is_array($nyers) ? $nyers : []), false);
    }

    /** Élő előnézet: a beküldött (még nem mentett) űrlapból a teljes bejelentkező oldal. */
    public static function ajax_elonezet(): void
    {
        check_ajax_referer('sdh_muhely_beallitasok', 'sdh_nonce');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'A beállításokhoz adminisztrátori jog kell.'], 403);
        }

        $nyers = isset($_POST['belepo']) && is_array($_POST['belepo']) ? wp_unslash($_POST['belepo']) : [];

        wp_send_json_success(['html' => SDH_Muhely_Rejtes::belepo_html('', '', '', self::szukit(is_array($nyers) ? $nyers : []), true)]);
    }
}
