<?php
/**
 * Arculat – stíluskalauz és alapértelmezett megjelenés.
 *
 * Két dolgot ad. Egyrészt egy élő kalauzt: minden szín és minden
 * komponens egy helyen, a valódi stíluslapról. Ha a felületet
 * átszínezzük, itt látszik először, mit rontottunk el – nem három hét
 * múlva, egy munkalapon.
 *
 * Másrészt a cég alapértelmezett megjelenését. A dolgozók a saját
 * gépükön felülírhatják a profilmenüben, de aki még nem állított
 * semmit, az ezt kapja. Új szerviznél ez az egyetlen hely, ahol a
 * rendszer színét be kell állítani.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Arculat
{
    public const KULCS = 'arculat';

    public static function init(): void
    {
        // Nincs saját menüpontja: a Beállítások „Logó és megjelenés" lapfüle
        // (SDH_Muhely_Beallitasok) rajzolja és menti.
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /**
     * @return array{uveg: string, betutipus: string, betutipus_egyeni: string, nagyitas: int, logo: string, jel: string, nev: string, szin_egyeni: string, sarok: string, sav: string, tema: string, szin: string, neon: string, neon_egyeni: string, neon_ero: string}
     */
    public static function beallitas(): array
    {
        $mentett = get_option('sdh_muhely_arculat', []);
        $mentett = is_array($mentett) ? $mentett : [];

        $neon = (string) ($mentett['neon'] ?? 'kiemelo');
        $ero  = (string) ($mentett['neon_ero'] ?? 'kozepes');

        $sarok = (string) ($mentett['sarok'] ?? 'normal');
        $sav   = (string) ($mentett['sav'] ?? 'vilagos');
        $egyeni = self::hex((string) ($mentett['szin_egyeni'] ?? ''));
        $szin   = (string) ($mentett['szin'] ?? '');

        // Egyéni kiemelő szín csak érvényes színkóddal él; különben az alapszín.
        if ($szin === 'egyeni' && $egyeni === '') {
            $szin = '';
        }

        $uveg      = (string) ($mentett['uveg'] ?? 'mono');
        $betutipus = (string) ($mentett['betutipus'] ?? '');

        return [
            // Üvegszín: 'duo' = két kontrasztos szín, 'mono' = egy színcsalád.
            'uveg'             => isset(self::uvegek()[$uveg]) ? $uveg : 'mono',
            // Betű: betűtípus (kulcs a betutipusok() listából), egyéni betűnév, alap nagyítás (%).
            'betutipus'        => isset(self::betutipusok()[$betutipus]) ? $betutipus : '',
            'betutipus_egyeni' => self::betunev((string) ($mentett['betutipus_egyeni'] ?? '')),
            'nagyitas'         => self::nagyitas_szukit((int) ($mentett['nagyitas'] ?? 100)),
            // A rendszer jele és neve (oldalsáv, címsor) és a logó – cégenként más, ezért beállítás.
            'logo'        => esc_url_raw((string) ($mentett['logo'] ?? '')),
            'jel'         => isset($mentett['jel']) ? (string) $mentett['jel'] : 'SDH',
            'nev'         => isset($mentett['nev']) ? (string) $mentett['nev'] : 'Műhely',
            'szin_egyeni' => $egyeni,
            'sarok'       => isset(self::sarkok()[$sarok]) ? $sarok : 'normal',
            'sav'         => isset(self::savok()[$sav]) ? $sav : 'vilagos',
            'tema'        => isset(self::temak()[(string) ($mentett['tema'] ?? '')]) ? (string) $mentett['tema'] : 'rendszer',
            // Kékfény-szűrő: a cég alapértelmezése (a dolgozó a profilmenüben a saját gépére átállíthatja).
            'kekfeny'     => self::kekfeny_szukit((int) ($mentett['kekfeny'] ?? 0)),
            'szin'        => $szin,
            // A popupok neon kerete: melyik szín világít (üres = kikapcsolva).
            'neon'        => isset(self::neonok()[$neon]) ? $neon : 'kiemelo',
            'neon_egyeni' => self::hex((string) ($mentett['neon_egyeni'] ?? '')),
            'neon_ero'    => isset(self::neon_erok()[$ero]) ? $ero : 'kozepes',
            // Területenkénti betű (0.60.1): űrlap, táblázat, rács, címsor, gomb, menü.
            'teruletek'   => self::teruletek_szukit(is_array($mentett['teruletek'] ?? null) ? $mentett['teruletek'] : []),
        ];
    }

    /* =================================================================
     * Területenkénti betűtípus és betűméret (0.60.1)
     *
     * A felület minden része a saját betűjét és méretét kaphatja. A méret a
     * `--sdh-n` változót szorozza az adott részfán belül – ez minden képpont-
     * méretet (betű, mezőmagasság, térköz) együtt mozgat, ahogy az alap
     * nagyítás is. A betűtípus a `--sdh-betutipus` változót írja felül.
     * Így NEM kell új szabály a stíluslapokba: a meglévő calc(...) kifejezések
     * magukkal viszik a változást.
     * ============================================================== */

    /**
     * A beállítható területek: kulcs => név, mire vonatkozik, CSS-választó.
     *
     * @return array<string, array{nev: string, leiras: string, valaszto: string}>
     */
    public static function teruletek(): array
    {
        return [
            'urlap' => [
                'nev'      => 'Űrlapok és popup ablakok',
                'leiras'   => 'A munkalap, az ügyfél, az eszköz és minden más felviteli ablak: mezők, címkék, lapfülek. Ezt állítsd, ha az ablakban kicsi a betű.',
                'valaszto' => '.sdh-modal__doboz, .sdh-urlap',
            ],
            'tabla' => [
                'nev'      => 'Listák és táblázatok',
                'leiras'   => 'A munkalapok, ügyfelek, termékek listája, a pénztár és a számlák táblázatai.',
                'valaszto' => '.sdh-tabla, .sdh-eladas__tabla',
            ],
            'racs' => [
                'nev'      => 'Áttekintés rácsa',
                'leiras'   => 'A kezdőképernyő munkalap-rácsa: a sorok, az oszlopfejlécek és a szűrők.',
                'valaszto' => '[data-sdh-racs]',
            ],
            'cimsor' => [
                'nev'      => 'Címsorok',
                'leiras'   => 'Az oldalak és az ablakok címe, a dobozok fejléce.',
                'valaszto' => '.sdh-fejlec__cim, .sdh-modal__cim, .sdh-doboz__cim',
            ],
            'gomb' => [
                'nev'      => 'Gombok',
                'leiras'   => 'Minden gomb felirata – a mentéstől a lapfülekig.',
                'valaszto' => '.sdh-gomb',
            ],
            'menu' => [
                'nev'      => 'Oldalmenü',
                'leiras'   => 'A bal oldali menüsáv menüpontjai.',
                'valaszto' => '#adminmenu',
            ],
        ];
    }

    /**
     * A területek beállításának megtisztítása.
     *
     * @param array<string, mixed> $nyers
     * @return array<string, array{tipus: string, egyeni: string, meret: int}>
     */
    public static function teruletek_szukit(array $nyers): array
    {
        $ki = [];

        foreach (self::teruletek() as $kulcs => $t) {
            $sor    = is_array($nyers[$kulcs] ?? null) ? $nyers[$kulcs] : [];
            $tipus  = (string) ($sor['tipus'] ?? '');
            $egyeni = self::betunev((string) ($sor['egyeni'] ?? ''));

            // 'egyeni' betűnév nélkül nem jelent semmit: ilyenkor marad az alap.
            if ($tipus === 'egyeni' && $egyeni === '') {
                $tipus = '';
            }

            $ki[$kulcs] = [
                // Üres = az alap betűtípust örökli (Betű doboz).
                'tipus'  => $tipus !== '' && isset(self::betutipusok()[$tipus]) ? $tipus : '',
                'egyeni' => $egyeni,
                'meret'  => self::terulet_meret_szukit((int) ($sor['meret'] ?? 100)),
            ];
        }

        return $ki;
    }

    /** A területi méret határai (%), ami az alap nagyítást szorozza. */
    public const TERULET_MIN   = 70;
    public const TERULET_MAX   = 180;
    public const TERULET_LEPES = 5;

    public static function terulet_meret_szukit(int $szazalek): int
    {
        $szazalek = (int) (round($szazalek / self::TERULET_LEPES) * self::TERULET_LEPES);

        return max(self::TERULET_MIN, min(self::TERULET_MAX, $szazalek));
    }

    /** Egy terület betűcsaládja CSS-be, vagy '' ha az alapot örökli. */
    public static function terulet_betucsalad(string $kulcs): string
    {
        $t = self::beallitas()['teruletek'][$kulcs] ?? null;

        if ($t === null || $t['tipus'] === '') {
            return '';
        }

        $lista = self::betutipusok();

        if ($t['tipus'] === 'egyeni') {
            return $t['egyeni'] !== '' ? '"' . $t['egyeni'] . '", ' . $lista['']['csalad'] : '';
        }

        return $lista[$t['tipus']]['csalad'] ?? '';
    }

    /** A betűnagyítás határai (%), a csúszka lépése. */
    public const NAGYITAS_MIN   = 80;
    public const NAGYITAS_MAX   = 160;
    public const NAGYITAS_LEPES = 5;

    public static function nagyitas_szukit(int $szazalek): int
    {
        $szazalek = (int) (round($szazalek / self::NAGYITAS_LEPES) * self::NAGYITAS_LEPES);

        return max(self::NAGYITAS_MIN, min(self::NAGYITAS_MAX, $szazalek));
    }

    /**
     * Az üvegszín két módja.
     *
     * @return array<string, array{nev: string, alcim: string}>
     */
    public static function uvegek(): array
    {
        return [
            'duo'  => ['nev' => 'Duó', 'alcim' => 'Kontrasztos színek'],
            'mono' => ['nev' => 'Egyszínű', 'alcim' => 'Egy színcsalád'],
        ];
    }

    /**
     * A színválasztóban kínált színek, a minta sorrendjében (a kulcs a
     * data-accent értéke). A `palaszurke` régi választás: érvényes marad, de
     * a választóban már nem szerepel.
     *
     * @return array<int, string>
     */
    public static function valaszto_szinek(): array
    {
        return ['', 'kek', 'smaragd', 'ibolya', 'rozsa', 'borostyan', 'turkiz', 'rubin'];
    }

    /**
     * Választható betűtípusok. Mind a gépen lévő (rendszer-) betűkből áll:
     * a rendszer nem tölt le betűt külső kiszolgálóról. A kulcs kerül a
     * beállításba, a `csalad` a CSS font-family értéke.
     *
     * @return array<string, array{nev: string, csalad: string}>
     */
    public static function betutipusok(): array
    {
        $rendszer = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';

        return [
            ''          => ['nev' => 'A gép betűtípusa (alap)', 'csalad' => $rendszer],
            'segoe'     => ['nev' => 'Segoe UI', 'csalad' => '"Segoe UI", "Segoe UI Variable", Tahoma, sans-serif'],
            'roboto'    => ['nev' => 'Roboto', 'csalad' => 'Roboto, "Noto Sans", "Segoe UI", Arial, sans-serif'],
            'inter'     => ['nev' => 'Inter', 'csalad' => 'Inter, "Segoe UI", Roboto, Arial, sans-serif'],
            'arial'     => ['nev' => 'Arial / Helvetica', 'csalad' => 'Arial, "Helvetica Neue", Helvetica, sans-serif'],
            'verdana'   => ['nev' => 'Verdana', 'csalad' => 'Verdana, Geneva, "DejaVu Sans", sans-serif'],
            'tahoma'    => ['nev' => 'Tahoma', 'csalad' => 'Tahoma, Geneva, Verdana, sans-serif'],
            'trebuchet' => ['nev' => 'Trebuchet MS', 'csalad' => '"Trebuchet MS", "Lucida Grande", Tahoma, sans-serif'],
            'calibri'   => ['nev' => 'Calibri', 'csalad' => 'Calibri, Carlito, Candara, "Segoe UI", sans-serif'],
            'gill'      => ['nev' => 'Gill Sans', 'csalad' => '"Gill Sans", "Gill Sans MT", Calibri, sans-serif'],
            'georgia'   => ['nev' => 'Georgia (talpas)', 'csalad' => 'Georgia, "Times New Roman", serif'],
            'times'     => ['nev' => 'Times New Roman (talpas)', 'csalad' => '"Times New Roman", Times, serif'],
            'mono'      => ['nev' => 'Írógépbetű (Consolas / Menlo)', 'csalad' => 'Consolas, Menlo, "DejaVu Sans Mono", monospace'],
            'egyeni'    => ['nev' => 'Egyéni – a gépre telepített betű neve', 'csalad' => $rendszer],
        ];
    }

    /** Betűnév a CSS-be: csak betű, szám, szóköz és kötőjel maradhat (legfeljebb 60 karakter). */
    private static function betunev(string $nev): string
    {
        return trim(mb_substr((string) preg_replace('/[^\p{L}\p{N} \-]/u', '', $nev), 0, 60));
    }

    /** A beállított betűtípus CSS font-family értéke. */
    public static function betucsalad(): string
    {
        $b     = self::beallitas();
        $lista = self::betutipusok();

        if ($b['betutipus'] === 'egyeni') {
            return $b['betutipus_egyeni'] !== '' ? '"' . $b['betutipus_egyeni'] . '", ' . $lista['']['csalad'] : $lista['']['csalad'];
        }

        return $lista[$b['betutipus']]['csalad'];
    }

    /**
     * A popupok neon keretének választható színei. A színek maguk a CSS-ben
     * vannak (`--sdh-popneon-<kulcs>` az assets/admin.css-ben); a PHP csak a
     * nevüket ismeri. Az üres kulcs: nincs neon keret. A `kiemelo` a
     * mindenkori kiemelő színt követi, az `egyeni` a megadott színkódot.
     *
     * @return array<string, string>
     */
    public static function neonok(): array
    {
        return [
            ''        => 'Kikapcsolva',
            'kiemelo' => 'A kiemelő szín',
            'cian'    => 'Cián',
            'kek'     => 'Elektromos kék',
            'lila'    => 'Lila',
            'magenta' => 'Magenta',
            'narancs' => 'Narancs',
            'lime'    => 'Lime',
            'egyeni'  => 'Egyéni szín',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function neon_erok(): array
    {
        return [
            'finom'   => 'Finom',
            'kozepes' => 'Közepes',
            'eros'    => 'Erős',
        ];
    }

    /**
     * A lekerekítés fokozatai: kulcs => [név, kis sugár, nagy sugár].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function sarkok(): array
    {
        return [
            'szogletes' => ['Szögletes', '3px', '4px'],
            'normal'    => ['Normál', '8px', '12px'],
            'kerek'     => ['Kerek', '13px', '20px'],
        ];
    }

    /**
     * Az oldalsáv (műhely-felület) stílusai.
     *
     * @return array<string, string>
     */
    public static function savok(): array
    {
        return [
            'vilagos' => 'Világos (a felület színe)',
            'sotet'   => 'Sötét',
            'szines'  => 'A kiemelő színnel kitöltve',
        ];
    }

    /** A rendszer teljes neve: jel + név (pl. „SDH Műhely") – címsor, morzsa, menü. */
    public static function rendszer_nev(): string
    {
        $b   = self::beallitas();
        $nev = trim($b['jel'] . ' ' . $b['nev']);

        return $nev !== '' ? $nev : 'Műhely';
    }

    /** A színkód három összetevője (0–255). */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /** A szín keverése feketével (negatív arány) vagy fehérrel (pozitív), -1…1. */
    private static function arnyalat(string $hex, float $arany): string
    {
        $cel = $arany < 0 ? 0 : 255;
        $a   = min(1.0, abs($arany));

        return sprintf('#%02x%02x%02x', ...array_map(static fn (int $c): int => (int) round($c + ($cel - $c) * $a), self::rgb($hex)));
    }

    /**
     * A beállításból adódó stílus: az egyéni kiemelő szín változói és a
     * lekerekítés. Mindkét felület (műhely és wp-admin) a saját stíluslapja
     * után kapja meg. Üres, ha minden az alapértelmezés.
     */
    public static function stilus_css(): string
    {
        $b   = self::beallitas();
        $css = '';

        if ($b['szin_egyeni'] !== '') {
            $hex       = $b['szin_egyeni'];
            [$r, $g, $k] = self::rgb($hex);
            // Világos kiemelő színen a fehér felirat olvashatatlan: ott sötét a felirat.
            $vilagos   = (0.299 * $r + 0.587 * $g + 0.114 * $k) > 160;

            $css .= ':root[data-accent="egyeni"]{--sdh-accent:' . $hex
                . ';--sdh-accent-sotet:' . self::arnyalat($hex, -0.18)
                . ';--sdh-accent-halvany:rgba(' . $r . ',' . $g . ',' . $k . ',.1)'
                . ';--sdh-accent-felirat:' . ($vilagos ? '#16191d' : '#ffffff')
                // Világos kiemelő szín szövegként fehér háttéren halvány volna: sötétebb árnyalat áll a helyén.
                . ($vilagos ? ';--sdh-accent-szoveg:' . self::arnyalat($hex, -0.45) : '') . '}'
                . ':root[data-theme="dark"][data-accent="egyeni"]{--sdh-accent-szoveg:' . self::arnyalat($hex, $vilagos ? 0.1 : 0.4) . '}';
        }

        if ($b['szin_egyeni'] !== '') {
            // Az egyéni szín párja a Duó üvegszínhez: a kiegészítő (szemközti) színe.
            [$r, $g, $k] = self::rgb($b['szin_egyeni']);
            $css .= ':root[data-accent="egyeni"][data-uveg="duo"]{--sdh-accent-2:' . sprintf('#%02x%02x%02x', 255 - $r, 255 - $g, 255 - $k) . '}';
        }

        if ($b['sarok'] !== 'normal') {
            $s    = self::sarkok()[$b['sarok']];
            // A lekerekítés is nő a betűnagyítással (--sdh-n), ahogy a stíluslap többi mérete.
            $css .= ':root{--sdh-r:calc(' . $s[1] . ' * var(--sdh-n));--sdh-r-nagy:calc(' . $s[2] . ' * var(--sdh-n))}';
        }

        if ($b['betutipus'] !== '') {
            $css .= ':root{--sdh-betutipus:' . self::betucsalad() . '}';
        }

        // Területenkénti betű és méret (0.60.1). A méret a --sdh-n változót
        // szorozza az adott részfán belül: a betű és a vele növő mezők,
        // térközök együtt mozdulnak. A popup a <body> alatt van, ezért a
        // változó oda is leöröklődik.
        foreach (self::teruletek() as $kulcs => $t) {
            $sor = $b['teruletek'][$kulcs] ?? null;

            if ($sor === null) {
                continue;
            }

            $darab  = [];
            $csalad = self::terulet_betucsalad($kulcs);

            if ($csalad !== '') {
                $darab[] = '--sdh-betutipus:' . $csalad;
                $darab[] = 'font-family:' . $csalad;
            }

            if ((int) $sor['meret'] !== 100) {
                $darab[] = '--sdh-n:calc(var(--sdh-n-alap, 1) * ' . number_format($sor['meret'] / 100, 2, '.', '') . ')';
            }

            if ($darab !== []) {
                $css .= $t['valaszto'] . '{' . implode(';', $darab) . '}';
            }
        }

        return $css;
    }

    /** Érvényes #rrggbb színkód, vagy üres sztring. */
    private static function hex(string $ertek): string
    {
        $ertek = strtolower(trim($ertek));

        if ($ertek !== '' && $ertek[0] !== '#') {
            $ertek = '#' . $ertek;
        }

        return preg_match('/^#[0-9a-f]{6}$/', $ertek) === 1 ? $ertek : '';
    }

    /**
     * A választható kiemelő színek. A kulcs a data-accent értéke;
     * az üres kulcs az alapértelmezett SDH piros.
     *
     * @return array<string, array{nev: string, hex: string, hex2: string}>
     */
    public static function szinek(): array
    {
        return [
            // hex = a szín a választó korongján; hex2 = a Duó párja. A felület
            // színei a CSS-ben vannak (admin.css: :root[data-accent=…]).
            ''            => ['nev' => 'Alapértelmezett', 'hex' => '#d4231d', 'hex2' => '#64748b'],
            'kek'         => ['nev' => 'Kék', 'hex' => '#2563eb', 'hex2' => '#f5b942'],
            'smaragd'     => ['nev' => 'Smaragdzöld', 'hex' => '#047857', 'hex2' => '#c084fc'],
            'ibolya'      => ['nev' => 'Ibolya', 'hex' => '#6d28d9', 'hex2' => '#a3e635'],
            'rozsa'       => ['nev' => 'Rózsaszín', 'hex' => '#db2777', 'hex2' => '#2dd4bf'],
            'borostyan'   => ['nev' => 'Borostyán', 'hex' => '#b45309', 'hex2' => '#38bdf8'],
            'turkiz'      => ['nev' => 'Türkiz', 'hex' => '#0e7490', 'hex2' => '#fb7185'],
            'rubin'       => ['nev' => 'Rubin', 'hex' => '#be123c', 'hex2' => '#22d3ee'],
            'palaszurke'  => ['nev' => 'Palaszürke', 'hex' => '#475569', 'hex2' => '#f59e0b'],
            // A cég saját színe: a színkódja az Arculat képernyőn adható meg.
            'egyeni'      => ['nev' => 'Egyéni szín', 'hex' => '', 'hex2' => ''],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function temak(): array
    {
        return [
            'rendszer' => 'A gép beállítása szerint',
            'vilagos'  => 'Mindig világos',
            'sotet'    => 'Mindig sötét',
            // Papír: nyugodt, e-könyv-olvasó-szerű felület – meleg papírszín, tintaszínű betű, fények és üveg nélkül.
            'papir'    => 'Papír (e-könyv)',
        ];
    }

    /** A kékfény-szűrő erőssége 0–100 között, ötösével (0 = kikapcsolva). */
    public static function kekfeny_szukit(int $ertek): int
    {
        return max(0, min(100, (int) (round($ertek / 5) * 5)));
    }

    /* =================================================================
     * Megjelenés-választó és betűnagyító (profilmenü, Beállítások, Áttekintés)
     * ============================================================== */

    /**
     * A megjelenés választója a minta szerint: Rendszer / Világos / Sötét, alatta
     * az Üvegszín két sora (Duó – kontrasztos színek, Egyszínű – egy színcsalád).
     *
     * $mod 'profil': a dolgozó saját gépére szól (app.js a böngészőben jegyzi meg);
     * $mod 'urlap':  a Beállítások űrlapja – a választás rejtett mezőkbe kerül
     *                (tema, szin, uveg), és a cég alapértelmezése lesz.
     */
    public static function megjelenes_valaszto(string $mod = 'profil'): string
    {
        $b      = self::beallitas();
        $urlap  = $mod === 'urlap';
        $szinek = self::szinek();
        $kulcsok = self::valaszto_szinek();

        // A cég egyéni színe csak akkor választható, ha megadták a színkódját.
        if ($b['szin_egyeni'] !== '') {
            [$r, $g, $k]            = self::rgb($b['szin_egyeni']);
            $szinek['egyeni']['hex']  = $b['szin_egyeni'];
            $szinek['egyeni']['hex2'] = sprintf('#%02x%02x%02x', 255 - $r, 255 - $g, 255 - $k);
            $szinek['egyeni']['nev']  = 'A cég színe';
            $kulcsok[]                = 'egyeni';
        }

        $ikonok = [
            'rendszer' => '<rect x="2.5" y="4" width="15" height="10" rx="1.6"/><path d="M7 17h6M10 14v3"/><path d="M10 6.5v5M10 6.5a2.5 2.5 0 0 0 0 5z" />',
            'vilagos'  => '<circle cx="10" cy="10" r="3.2"/><path d="M10 2.5v2M10 15.5v2M2.5 10h2M15.5 10h2M4.7 4.7l1.4 1.4M13.9 13.9l1.4 1.4M4.7 15.3l1.4-1.4M13.9 6.1l1.4-1.4"/>',
            'sotet'    => '<path d="M16 11.6A6.5 6.5 0 0 1 8.4 4a6.5 6.5 0 1 0 7.6 7.6z"/>',
            'papir'    => '<path d="M10 5.2C8.4 4 6.2 3.6 3.5 4v11c2.7-.4 4.9 0 6.5 1.2 1.6-1.2 3.8-1.6 6.5-1.2V4c-2.7-.4-4.9 0-6.5 1.2z"/><path d="M10 5.2v11"/>',
        ];
        $temak = ['rendszer' => 'Rendszer', 'vilagos' => 'Világos', 'sotet' => 'Sötét', 'papir' => 'Papír'];

        $html = '<div class="sdh-megj" data-sdh-megj="' . esc_attr($urlap ? 'urlap' : 'profil') . '">';

        if ($urlap) {
            $html .= '<input type="hidden" name="tema" value="' . esc_attr($b['tema']) . '" data-sdh-megj-mezo="tema">'
                . '<input type="hidden" name="szin" value="' . esc_attr($b['szin']) . '" data-sdh-megj-mezo="szin">'
                . '<input type="hidden" name="arculat_uveg" value="' . esc_attr($b['uveg']) . '" data-sdh-megj-mezo="uveg">';
        }

        $html .= '<p class="sdh-megj__cim">Megjelenés</p><div class="sdh-megj__tema" data-sdh-tema role="group" aria-label="Világos, sötét vagy papír megjelenés">';

        foreach ($temak as $kulcs => $nev) {
            $html .= '<button type="button" data-ertek="' . esc_attr($kulcs) . '" aria-pressed="' . ($urlap && $b['tema'] === $kulcs ? 'true' : 'false') . '">'
                . '<svg viewBox="0 0 20 20" aria-hidden="true">' . $ikonok[$kulcs] . '</svg><span>' . esc_html($nev) . '</span></button>';
        }

        // Kékfény-szűrő: melegebb, a szemnek nyugodtabb kép (0 = ki). A profilmenüben a dolgozó
        // saját gépére szól, a Beállításokban a cég alapértelmezése (name="arculat_kekfeny").
        $html .= '</div><p class="sdh-megj__cim">Kékfény-szűrő</p>'
            . '<div class="sdh-kekfeny-allito" data-sdh-kekfeny="' . esc_attr($urlap ? 'urlap' : 'sajat') . '">'
            . '<svg class="sdh-kekfeny-allito__ikon" viewBox="0 0 20 20" aria-hidden="true"><path d="M16 11.6A6.5 6.5 0 0 1 8.4 4a6.5 6.5 0 1 0 7.6 7.6z"/><path d="M14.5 3.2v2.6M13.2 4.5h2.6"/></svg>'
            . '<input type="range" class="sdh-nagyito__csuszka sdh-kekfeny-allito__csuszka"' . ($urlap ? ' name="arculat_kekfeny"' : '')
            . ' min="0" max="100" step="5" value="' . (int) $b['kekfeny'] . '" data-alap="' . (int) $b['kekfeny'] . '" data-sdh-kekfeny-csuszka'
            . ' aria-label="' . esc_attr($urlap ? 'A kékfény-szűrő alap erőssége (százalék)' : 'A kékfény-szűrő erőssége (százalék)') . '">'
            . '<output class="sdh-nagyito__ertek" data-sdh-kekfeny-ertek>' . ((int) $b['kekfeny'] > 0 ? (int) $b['kekfeny'] . '%' : 'Ki') . '</output>'
            . '</div>';

        $html .= '<p class="sdh-megj__cim">Üvegszín</p>';

        foreach (self::uvegek() as $uveg => $u) {
            $html .= '<div class="sdh-megj__sorfej"><strong>' . esc_html($u['nev']) . '</strong><span>' . esc_html($u['alcim']) . '</span>'
                . '<em>' . count($kulcsok) . '</em></div>'
                . '<div class="sdh-megj__racs" data-sdh-uvegszin="' . esc_attr($uveg) . '" role="group" aria-label="' . esc_attr($u['nev'] . ' – ' . $u['alcim']) . '">';

            foreach ($kulcsok as $kulcs) {
                $sz    = $szinek[$kulcs];
                $aktiv = $urlap && $b['szin'] === $kulcs && $b['uveg'] === $uveg;

                $html .= '<button type="button" class="sdh-megj__szin" data-ertek="' . esc_attr($kulcs) . '" aria-pressed="' . ($aktiv ? 'true' : 'false') . '"'
                    . ' title="' . esc_attr($sz['nev'] . ' – ' . $u['nev']) . '">'
                    . '<span class="sdh-megj__korong sdh-megj__korong--' . esc_attr($uveg) . '" style="--k1:' . esc_attr($sz['hex']) . ';--k2:' . esc_attr($sz['hex2']) . '" aria-hidden="true">'
                    . '<i class="sdh-megj__pipa"><svg viewBox="0 0 12 12"><path d="M2.6 6.3l2.3 2.3 4.5-5"/></svg></i></span>'
                    . '<span class="sdh-megj__nev">' . esc_html($sz['nev']) . '</span></button>';
            }

            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /**
     * Betűnagyító csúszka: húzás közben csak a százalék és a minta változik, a
     * felület a csúszka elengedésekor, lágy átmenettel vált méretet (app.js:
     * nagyitasAnimal) – így a csúszka nem „szalad ki" az egér alól. $mod 'sajat': a dolgozó saját gépére szól; 'urlap': a
     * Beállítások űrlapja, a cég alapértelmezése (name="arculat_nagyitas").
     */
    public static function nagyito(string $mod = 'sajat'): string
    {
        $b     = self::beallitas();
        $urlap = $mod === 'urlap';

        return '<div class="sdh-nagyito" data-sdh-nagyito="' . esc_attr($urlap ? 'urlap' : 'sajat') . '">'
            . '<button type="button" class="sdh-nagyito__a sdh-nagyito__a--kis" data-sdh-nagyito-lep="-1" title="Kisebb betű" aria-label="Kisebb betű">A</button>'
            . '<input type="range" class="sdh-nagyito__csuszka"' . ($urlap ? ' name="arculat_nagyitas"' : '')
            . ' min="' . self::NAGYITAS_MIN . '" max="' . self::NAGYITAS_MAX . '" step="' . self::NAGYITAS_LEPES . '"'
            . ' value="' . (int) $b['nagyitas'] . '" data-alap="' . (int) ($urlap ? 100 : $b['nagyitas']) . '" data-sdh-nagyito-csuszka'
            . ' aria-label="' . esc_attr($urlap ? 'Alap betűméret (százalék)' : 'Betűméret (százalék)') . '">'
            . '<button type="button" class="sdh-nagyito__a sdh-nagyito__a--nagy" data-sdh-nagyito-lep="1" title="Nagyobb betű" aria-label="Nagyobb betű">A</button>'
            . '<span class="sdh-nagyito__minta" data-sdh-nagyito-minta aria-hidden="true">Aa</span>'
            . '<output class="sdh-nagyito__ertek" data-sdh-nagyito-ertek>' . (int) $b['nagyitas'] . '%</output>'
            . '<button type="button" class="sdh-nagyito__alap" data-sdh-nagyito-alap title="Vissza az alapméretre" aria-label="Vissza az alapméretre">'
            . '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4.5 10a5.5 5.5 0 1 0 1.7-4"/><path d="M4 3.5v3h3"/></svg></button>'
            . '</div>';
    }

    /* =================================================================
     * Képernyő
     * ============================================================== */

    /** Az arculat dobozai a Beállítások „Logó és megjelenés" lapfülén (űrlap nélkül: azt a Beállítások adja). */
    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();

        ?>
                <div class="sdh-doboz" id="logo">
                    <h2 class="sdh-doboz__cim">Logó és név</h2>

                    <p class="sdh-sugo">
                        Ez látszik a műhely-felület bal felső sarkában, a böngészőfül címében és a menüben.
                        Ha töltesz fel logót, az áll a színes jel helyén; logó nélkül a jel (néhány betű) látszik.
                        A név a logó / jel mellett áll – üresen hagyva csak a logó marad.
                    </p>

                    <div class="sdh-mezok">
                        <div class="sdh-mezo sdh-mezo--szeles">
                            <label for="arculat_logo">Logó</label>
                            <div class="sdh-rma-media" data-sdh-media="image">
                                <input type="text" name="arculat_logo" id="arculat_logo"
                                       value="<?php echo esc_attr($b['logo']); ?>" placeholder="https://… – vagy töltsd fel a gombbal">
                                <button type="button" class="sdh-gomb" data-sdh-media-valaszt>Feltöltés…</button>
                                <button type="button" class="sdh-gomb" data-sdh-media-torol<?php echo $b['logo'] === '' ? ' hidden' : ''; ?>>Törlés</button>
                                <span class="sdh-rma-media__elo sdh-arculat-logo__elo" data-sdh-media-elo>
                                    <?php if ($b['logo'] !== '') : ?>
                                        <img src="<?php echo esc_url($b['logo']); ?>" alt="">
                                    <?php endif; ?>
                                </span>
                            </div>
                            <span class="sdh-mezo__sugo">Átlátszó hátterű PNG vagy SVG a legjobb; 28 képpont magasan jelenik meg, a szélessége legfeljebb 150 képpont.</span>
                        </div>

                        <div class="sdh-mezo">
                            <label for="arculat_jel">Jel (logó nélkül ez látszik)</label>
                            <input type="text" name="arculat_jel" id="arculat_jel" maxlength="4" autocomplete="off"
                                   value="<?php echo esc_attr($b['jel']); ?>">
                            <span class="sdh-mezo__sugo">Legfeljebb 4 betű.</span>
                        </div>

                        <div class="sdh-mezo">
                            <label for="arculat_nev">Név</label>
                            <input type="text" name="arculat_nev" id="arculat_nev" maxlength="30" autocomplete="off"
                                   value="<?php echo esc_attr($b['nev']); ?>">
                            <span class="sdh-mezo__sugo">A rendszer neve most: <strong><?php echo esc_html(self::rendszer_nev()); ?></strong></span>
                        </div>
                    </div>
                </div>

                <div class="sdh-doboz">
                    <h2 class="sdh-doboz__cim">Alapértelmezett megjelenés</h2>

                    <p class="sdh-sugo">
                        Ezt kapja mindenki, aki a saját gépén még nem állított be mást.
                        A dolgozók a profilmenüben felülírhatják – az a beállítás náluk
                        marad, és ez nem írja felül.
                    </p>

                    <?php // Rendszer / Világos / Sötét és az Üvegszín (Duó, Egyszínű) – ugyanaz a választó, mint a profilmenüben. ?>
                    <?php echo self::megjelenes_valaszto('urlap'); // phpcs:ignore WordPress.Security.EscapeOutput -- szűrt HTML. ?>

                    <div class="sdh-mezok">
                        <div class="sdh-mezo">
                            <label for="arculat_szin_egyeni">Egyéni kiemelő szín</label>
                            <div class="sdh-rma-szin sdh-arculat-szin">
                                <input type="color" id="arculat_szin_valaszto" aria-label="Színválasztó"
                                       value="<?php echo esc_attr($b['szin_egyeni'] !== '' ? $b['szin_egyeni'] : '#d4231d'); ?>"
                                       data-sdh-szin-cel="arculat_szin_egyeni">
                                <input type="text" name="arculat_szin_egyeni" id="arculat_szin_egyeni" maxlength="7"
                                       pattern="#?[0-9a-fA-F]{6}" placeholder="#0a84ff" autocomplete="off"
                                       value="<?php echo esc_attr($b['szin_egyeni']); ?>">
                            </div>
                            <span class="sdh-mezo__sugo">A cég saját színe. Mentés után a fenti választóban „A cég színe” néven jelenik meg.</span>
                        </div>

                        <div class="sdh-mezo">
                            <label for="arculat_sarok">Sarkok lekerekítése</label>
                            <select name="arculat_sarok" id="arculat_sarok">
                                <?php foreach (self::sarkok() as $kulcs => $s) : ?>
                                    <option value="<?php echo esc_attr($kulcs); ?>" <?php selected($b['sarok'], $kulcs); ?>><?php echo esc_html($s[0]); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sdh-mezo">
                            <label for="arculat_sav">Oldalsáv (menü) a műhely-felületen</label>
                            <select name="arculat_sav" id="arculat_sav">
                                <?php foreach (self::savok() as $kulcs => $cimke) : ?>
                                    <option value="<?php echo esc_attr($kulcs); ?>" <?php selected($b['sav'], $kulcs); ?>><?php echo esc_html($cimke); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="sdh-doboz" id="betu">
                    <h2 class="sdh-doboz__cim">Betűtípus és betűméret</h2>

                    <p class="sdh-sugo">
                        A betűtípus az egész rendszerre szól. A betűméret a cég alapértelmezése: a dolgozók az
                        Áttekintés csúszkájával (és a profilmenüben) a saját gépükön nagyobbra vagy kisebbre húzhatják.
                        A rendszer nem tölt le betűt az internetről: a listában a gépeken általában meglévő betűk
                        állnak – ha egy gépen a választott betű nincs telepítve, ott a gép saját betűje látszik.
                    </p>

                    <div class="sdh-mezok">
                        <div class="sdh-mezo">
                            <label for="arculat_betutipus">Betűtípus</label>
                            <select name="arculat_betutipus" id="arculat_betutipus" data-sdh-betutipus>
                                <?php foreach (self::betutipusok() as $kulcs => $bt) : ?>
                                    <option value="<?php echo esc_attr($kulcs); ?>" data-csalad="<?php echo esc_attr($bt['csalad']); ?>"
                                        <?php selected($b['betutipus'], $kulcs); ?>><?php echo esc_html($bt['nev']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sdh-mezo">
                            <label for="arculat_betutipus_egyeni">Egyéni betű neve</label>
                            <input type="text" name="arculat_betutipus_egyeni" id="arculat_betutipus_egyeni" maxlength="60" autocomplete="off"
                                   placeholder="pl. Montserrat" data-sdh-betutipus-egyeni
                                   value="<?php echo esc_attr($b['betutipus_egyeni']); ?>">
                            <span class="sdh-mezo__sugo">Csak az „Egyéni” választásnál számít. A betűnek telepítve kell lennie a gépen.</span>
                        </div>

                        <div class="sdh-mezo sdh-mezo--szeles">
                            <label>Alap betűméret</label>
                            <?php echo self::nagyito('urlap'); // phpcs:ignore WordPress.Security.EscapeOutput -- szűrt HTML. ?>
                            <span class="sdh-mezo__sugo">100% a rendes méret. Húzás közben a csúszka fölötti minta mutatja a méretet; elengedéskor ez a képernyő is átméreteződik – mentés után lesz a cég alapértelmezése.</span>
                        </div>
                    </div>

                    <p class="sdh-betuminta" data-sdh-betuminta>Árvíztűrő tükörfúrógép – 0123456789 – Munkalap 2026/15, fizetendő 157 700 Ft</p>
                </div>

                <div class="sdh-doboz" id="betu-teruletek">
                    <h2 class="sdh-doboz__cim">Betű területenként</h2>

                    <p class="sdh-sugo">
                        A felület részei külön betűtípust és külön méretet kaphatnak. Ez a fenti alapra épül:
                        a <strong>méret az alap betűméretet szorozza</strong> (100% = pont akkora, mint az alap),
                        a betűtípus pedig csak akkor változik, ha nem a „Mint az alap” áll benne. A méret nemcsak
                        a betűt, hanem a vele növő mezőket és térközöket is mozgatja – így a popup sem esik szét.
                        Ha egy ablakban kicsi a betű, az <strong>Űrlapok és popup ablakok</strong> sorát állítsd.
                    </p>

                    <div class="sdh-betuter">
                        <?php foreach (self::teruletek() as $kulcs => $t) : ?>
                            <?php $sor = $b['teruletek'][$kulcs]; ?>
                            <div class="sdh-betuter__sor" data-sdh-betuter="<?php echo esc_attr($kulcs); ?>">
                                <div class="sdh-betuter__nev">
                                    <strong><?php echo esc_html($t['nev']); ?></strong>
                                    <span><?php echo esc_html($t['leiras']); ?></span>
                                </div>

                                <div class="sdh-mezo">
                                    <label for="arculat_ter_<?php echo esc_attr($kulcs); ?>_tipus">Betűtípus</label>
                                    <select name="arculat_terulet[<?php echo esc_attr($kulcs); ?>][tipus]"
                                            id="arculat_ter_<?php echo esc_attr($kulcs); ?>_tipus" data-sdh-betuter-tipus>
                                        <option value="" data-csalad="" <?php selected($sor['tipus'], ''); ?>>Mint az alap</option>
                                        <?php foreach (self::betutipusok() as $bk => $bt) : ?>
                                            <?php if ($bk === '') { continue; } ?>
                                            <option value="<?php echo esc_attr($bk); ?>" data-csalad="<?php echo esc_attr($bt['csalad']); ?>"
                                                <?php selected($sor['tipus'], $bk); ?>><?php echo esc_html($bt['nev']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="sdh-mezo">
                                    <label for="arculat_ter_<?php echo esc_attr($kulcs); ?>_egyeni">Egyéni betű neve</label>
                                    <input type="text" name="arculat_terulet[<?php echo esc_attr($kulcs); ?>][egyeni]"
                                           id="arculat_ter_<?php echo esc_attr($kulcs); ?>_egyeni" maxlength="60" autocomplete="off"
                                           placeholder="csak az „Egyéni” választásnál" data-sdh-betuter-egyeni
                                           value="<?php echo esc_attr($sor['egyeni']); ?>">
                                </div>

                                <div class="sdh-mezo sdh-betuter__meret">
                                    <label for="arculat_ter_<?php echo esc_attr($kulcs); ?>_meret">Méret az alaphoz képest</label>
                                    <input type="range" name="arculat_terulet[<?php echo esc_attr($kulcs); ?>][meret]"
                                           id="arculat_ter_<?php echo esc_attr($kulcs); ?>_meret" data-sdh-betuter-meret
                                           min="<?php echo (int) self::TERULET_MIN; ?>" max="<?php echo (int) self::TERULET_MAX; ?>"
                                           step="<?php echo (int) self::TERULET_LEPES; ?>" value="<?php echo (int) $sor['meret']; ?>">
                                    <output data-sdh-betuter-ertek><?php echo (int) $sor['meret']; ?>%</output>
                                </div>

                                <p class="sdh-betuter__minta" data-sdh-betuter-minta>Munkalap 2026/15 – Samsung A50 – 157 700 Ft</p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <p class="sdh-sugo sdh-sugo--utolso">
                        A minta sor azonnal mutatja a választást; a felület a <strong>Mentés</strong> után veszi fel.
                        Ha valami félresikerült, állítsd minden sort „Mint az alap” + 100% értékre.
                    </p>
                </div>

                <div class="sdh-doboz">
                    <h2 class="sdh-doboz__cim">Popupok – neon keret</h2>

                    <p class="sdh-sugo">
                        Minden felugró ablak (munkalap, ügyfél, eszköz, választók) körül világító
                        keret: ebből látszik azonnal, melyik ablak az aktív. Itt állítod a cég
                        alapértelmezését; a dolgozók a profilmenüben a saját gépükön mást is
                        választhatnak. Az egyéni szín hatjegyű színkód, pl. <code>#00e5ff</code>.
                    </p>

                    <div class="sdh-mezok">
                        <div class="sdh-mezo">
                            <label for="neon">Kijelölő (neon) szín</label>
                            <select name="neon" id="neon" data-sdh-neon-valaszto>
                                <?php foreach (self::neonok() as $kulcs => $cimke) : ?>
                                    <option value="<?php echo esc_attr($kulcs); ?>"
                                        <?php selected($b['neon'], $kulcs); ?>>
                                        <?php echo esc_html($cimke); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sdh-mezo">
                            <label for="neon_egyeni">Egyéni színkód</label>
                            <input type="text" name="neon_egyeni" id="neon_egyeni" maxlength="7"
                                   pattern="#?[0-9a-fA-F]{6}" placeholder="#00e5ff" autocomplete="off"
                                   data-sdh-neon-egyeni
                                   value="<?php echo esc_attr($b['neon_egyeni']); ?>">
                            <span class="sdh-mezo__sugo">Csak az „Egyéni szín” választásnál számít.</span>
                        </div>

                        <div class="sdh-mezo">
                            <label for="neon_ero">Fényerő</label>
                            <select name="neon_ero" id="neon_ero" data-sdh-neon-ero-valaszto>
                                <?php foreach (self::neon_erok() as $kulcs => $cimke) : ?>
                                    <option value="<?php echo esc_attr($kulcs); ?>"
                                        <?php selected($b['neon_ero'], $kulcs); ?>>
                                        <?php echo esc_html($cimke); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="sdh-neon-minta" aria-hidden="true">
                        <div class="sdh-modal__doboz sdh-neon-minta__ablak">
                            <span class="sdh-neon-minta__gomb sdh-neon-minta__gomb--piros"></span>
                            <span class="sdh-neon-minta__gomb sdh-neon-minta__gomb--zold"></span>
                            <strong>Így néz ki egy popup</strong>
                            <span>A keret színe és fényereje mentés előtt is itt látszik.</span>
                        </div>
                    </div>
                </div>

        <?php
    }

    /* =================================================================
     * Stíluskalauz
     * ============================================================== */

    public static function kalauz(): void
    {
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Színek</h2>

            <p class="sdh-sugo">
                Minden szín CSS-változó az <code>assets/admin.css</code> tetején.
                Átszínezéshez egyetlen fájlt kell módosítani – a PHP-ban és a
                JavaScriptben nincs beégetett szín. A „Kiemelő szöveg” sötét módban
                világosabb a gombszínnél, különben a linkek olvashatatlanok lennének.
            </p>

            <div class="sdh-paletta">
                <?php
                $mintak = [
                    'Kiemelő'        => '--sdh-accent',
                    'Kiemelő sötét'  => '--sdh-accent-sotet',
                    'Kiemelő szöveg' => '--sdh-accent-szoveg',
                    'Vászon'         => '--sdh-vaszon',
                    'Felület'        => '--sdh-felulet',
                    'Felület 2'      => '--sdh-felulet-2',
                    'Keret'          => '--sdh-keret',
                    'Keret erős'     => '--sdh-keret-eros',
                    'Szöveg'         => '--sdh-szoveg',
                    'Szöveg 2'       => '--sdh-szoveg-2',
                    'Halvány'        => '--sdh-halvany',
                    'Siker'          => '--sdh-siker',
                    'Hiba'           => '--sdh-hiba',
                    'Figyelem'       => '--sdh-figyelem',
                ];

                foreach ($mintak as $nev => $valtozo) :
                    ?>
                    <div class="sdh-paletta__elem">
                        <span class="sdh-paletta__folt"
                              style="background:var(<?php echo esc_attr($valtozo); ?>)"></span>
                        <strong><?php echo esc_html($nev); ?></strong>
                        <code><?php echo esc_html($valtozo); ?></code>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Gombok és címkék</h2>

            <div class="sdh-urlap__lablec--tordel">
                <button type="button" class="sdh-gomb sdh-gomb--elsodleges">Elsődleges</button>
                <button type="button" class="sdh-gomb">Másodlagos</button>
                <button type="button" class="sdh-gomb" disabled>Letiltva</button>
                <span class="sdh-cimke">Címke</span>
                <span class="sdh-cimke sdh-cimke--inaktiv">inaktív</span>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Értesítések</h2>

            <div class="sdh-uzenet sdh-uzenet--siker">Elmentve.</div>
            <div class="sdh-uzenet sdh-uzenet--figyelem">
                Ez a 15 számjegy nem ad ki érvényes IMEI-t – nézd meg, nem gépelted-e el.
            </div>
            <div class="sdh-uzenet sdh-uzenet--hiba">
                A mentés nem sikerült. Az adatbázis visszautasította a műveletet.
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Űrlapmezők</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="kalauz-szoveg">Szövegmező</label>
                    <input type="text" id="kalauz-szoveg" value="SM-A505F/DS">
                    <span class="sdh-mezo__sugo">A mező alatti súgó ilyen.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="kalauz-lista">Legördülő</label>
                    <select id="kalauz-lista">
                        <option>Telefon</option>
                        <option>Tablet</option>
                    </select>
                </div>

                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" id="kalauz-jelolo" checked>
                    <label for="kalauz-jelolo">Jelölőnégyzet</label>
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Táblázat</h2>

            <table class="sdh-tabla">
                <thead>
                    <tr><th>Készülék</th><th>Ügyfél</th><th>IMEI</th><th>Garancia</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="sdh-tabla__nev">Samsung SM-A505F/DS
                            <div class="sdh-tabla__halvany">Galaxy A50</div></td>
                        <td>Minta Ügyfél</td>
                        <td class="sdh-tabla__halvany">357088105701076</td>
                        <td><span class="sdh-tabla__halvany">—</span></td>
                    </tr>
                    <tr>
                        <td class="sdh-tabla__nev">Apple A1660
                            <div class="sdh-tabla__halvany">iPhone 7</div></td>
                        <td>Másik Ügyfél</td>
                        <td class="sdh-tabla__halvany">F17XK2QWHG7F</td>
                        <td><span class="sdh-cimke">garanciális</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* =================================================================
     * Mentés
     * ============================================================== */

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (!isset($_POST['tema'])) {
            return;
        }

        $tema = isset($_POST['tema']) ? sanitize_key(wp_unslash($_POST['tema'])) : 'rendszer';
        $szin = isset($_POST['szin']) ? sanitize_key(wp_unslash($_POST['szin'])) : '';
        $neon = isset($_POST['neon']) ? sanitize_key(wp_unslash($_POST['neon'])) : '';
        $ero  = isset($_POST['neon_ero']) ? sanitize_key(wp_unslash($_POST['neon_ero'])) : 'kozepes';
        $hex  = self::hex(isset($_POST['neon_egyeni']) ? sanitize_text_field(wp_unslash($_POST['neon_egyeni'])) : '');

        // Egyéni szín érvényes színkód nélkül nem világíthat: akkor a kiemelő szín marad.
        if ($neon === 'egyeni' && $hex === '') {
            $neon = 'kiemelo';
        }

        $regi      = self::beallitas();
        $uveg      = isset($_POST['arculat_uveg']) ? sanitize_key(wp_unslash($_POST['arculat_uveg'])) : $regi['uveg'];
        $betutipus = isset($_POST['arculat_betutipus']) ? sanitize_key(wp_unslash($_POST['arculat_betutipus'])) : $regi['betutipus'];
        $betu_egy  = isset($_POST['arculat_betutipus_egyeni'])
            ? self::betunev(sanitize_text_field(wp_unslash($_POST['arculat_betutipus_egyeni'])))
            : $regi['betutipus_egyeni'];

        // Egyéni betűtípus csak megadott névvel választható.
        if ($betutipus === 'egyeni' && $betu_egy === '') {
            $betutipus = '';
        }

        $szin_egyeni = self::hex(isset($_POST['arculat_szin_egyeni']) ? sanitize_text_field(wp_unslash($_POST['arculat_szin_egyeni'])) : '');
        $sarok       = isset($_POST['arculat_sarok']) ? sanitize_key(wp_unslash($_POST['arculat_sarok'])) : 'normal';
        $sav         = isset($_POST['arculat_sav']) ? sanitize_key(wp_unslash($_POST['arculat_sav'])) : 'vilagos';

        // Egyéni kiemelő szín csak érvényes színkóddal választható.
        if ($szin === 'egyeni' && $szin_egyeni === '') {
            $szin = '';
        }

        update_option(
            'sdh_muhely_arculat',
            [
                'uveg'             => isset(self::uvegek()[$uveg]) ? $uveg : 'mono',
                'betutipus'        => isset(self::betutipusok()[$betutipus]) ? $betutipus : '',
                'betutipus_egyeni' => $betu_egy,
                'nagyitas'         => isset($_POST['arculat_nagyitas']) ? self::nagyitas_szukit((int) $_POST['arculat_nagyitas']) : $regi['nagyitas'],
                'logo'        => isset($_POST['arculat_logo']) ? esc_url_raw(trim((string) wp_unslash($_POST['arculat_logo']))) : '',
                'jel'         => mb_substr(sanitize_text_field(isset($_POST['arculat_jel']) ? wp_unslash($_POST['arculat_jel']) : ''), 0, 4),
                'nev'         => mb_substr(sanitize_text_field(isset($_POST['arculat_nev']) ? wp_unslash($_POST['arculat_nev']) : ''), 0, 30),
                'szin_egyeni' => $szin_egyeni,
                'sarok'       => isset(self::sarkok()[$sarok]) ? $sarok : 'normal',
                'sav'         => isset(self::savok()[$sav]) ? $sav : 'vilagos',
                'tema'        => isset(self::temak()[$tema]) ? $tema : 'rendszer',
                'kekfeny'     => isset($_POST['arculat_kekfeny']) ? self::kekfeny_szukit((int) $_POST['arculat_kekfeny']) : $regi['kekfeny'],
                'szin'        => isset(self::szinek()[$szin]) ? $szin : '',
                'neon'        => isset(self::neonok()[$neon]) ? $neon : 'kiemelo',
                'neon_egyeni' => $hex,
                'neon_ero'    => isset(self::neon_erok()[$ero]) ? $ero : 'kozepes',
                // Betű területenként (0.60.1): a tisztítás a teruletek_szukit dolga.
                'teruletek'   => self::teruletek_szukit(
                    isset($_POST['arculat_terulet']) && is_array($_POST['arculat_terulet'])
                        ? map_deep(wp_unslash($_POST['arculat_terulet']), 'sanitize_text_field')
                        : $regi['teruletek']
                ),
            ]
        );
        // phpcs:enable
    }
}
