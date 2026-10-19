<?php
/**
 * SDH Értékesítés – szekció- és elem-sémák az adminnak (ARCHITEKTURA.md §7), és a sémák szerinti tisztítás.
 *
 * Egy séma mezőlista: [{k: kulcs, t: fajta, c: cimke, s?: súgó, o?: opciók, al?: almezők, tipus?: tartalom-típus,
 * min?, max?, penz?: bool, kotelezo?: bool, fel?: bool (fél szélesség), ful?: lapfül}].
 * Mezőfajták: szoveg, hosszu, html (tisztított richtext), kep (URL, WP médiatárból), ikon, ismetlo (almezőkkel),
 * valaszto, kapcsolo, link ({szoveg, url, uj}), szin, szam, url, datum (ÉÉÉÉ-HH-NN), tobb / egy (tartalom-kulcsok).
 *
 * Tisztítás (`ervenyesit`): az ismert mezőket a fajtájuk szerint, az ismeretlen (más ügynök által írt) mezőket
 * általánosan – szöveg: wp_kses a biztonságos címkékkel – tisztítja, így semmilyen út nem visz be szkriptet.
 * Bővítés: `sdh_sales_szekcio_tipusok`, `sdh_sales_elem_semak` szűrő.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Szekcio
{
    /** A richtext mezők engedett címkéi. */
    private const HTML = [
        'p'          => [],
        'br'         => [],
        'strong'     => [],
        'b'          => [],
        'em'         => [],
        'i'          => [],
        'u'          => [],
        's'          => [],
        'code'       => [],
        'mark'       => [],
        'small'      => [],
        'sub'        => [],
        'sup'        => [],
        'h2'         => ['id' => true],
        'h3'         => ['id' => true],
        'h4'         => ['id' => true],
        'ul'         => [],
        'ol'         => ['start' => true],
        'li'         => [],
        'blockquote' => [],
        'hr'         => [],
        'a'          => ['href' => true, 'title' => true, 'rel' => true, 'target' => true],
        'img'        => ['src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true],
        'table'      => [],
        'thead'      => [],
        'tbody'      => [],
        'tr'         => [],
        'th'         => ['scope' => true],
        'td'         => ['colspan' => true, 'rowspan' => true],
        'figure'     => [],
        'figcaption' => [],
        'span'       => [],
    ];

    /** Ismeretlen mezők szövegének engedett (szűkebb) címkéi. */
    private const HTML_SZUK = [
        'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'ul' => [], 'ol' => [], 'li' => [], 'code' => [],
        'a' => ['href' => true, 'title' => true, 'rel' => true, 'target' => true],
    ];

    /** @var array<string, array>|null */
    private static ?array $tipus_cache = null;

    public static function init(): void
    {
    }

    /* =================================================================
     * Ikonok (a téma ugyanezeket a kulcsokat rajzolja; az admin is mutatja)
     * ============================================================== */

    /** @return array<string, string> kulcs → SVG-útvonal (24×24, stroke) */
    public static function ikonok(): array
    {
        $i = [
            'villam'     => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
            'pajzs'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'munkalap'   => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 12h6M9 16h6"/>',
            'ugyfel'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'telefon'    => '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>',
            'szerszam'   => '<path d="M14.7 6.3a4 4 0 0 0 5 5L21 13l-8 8-3-3 8-8-1.3-1.3a4 4 0 0 0-5-5z"/><path d="m3 21 6-6"/>',
            'naptar'     => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'sms'        => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'email'      => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
            'szamla'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
            'penz'       => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
            'kassza'     => '<rect x="3" y="10" width="18" height="11" rx="2"/><path d="M7 10V5h10v5M7 14h2M11 14h2M15 14h2M7 17h10"/>',
            'raktar'     => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
            'nyomtato'   => '<path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
            'cimke'      => '<path d="M20.59 13.41 13.42 20.6a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><path d="M7 7h.01"/>',
            'grafikon'   => '<path d="M3 3v18h18"/><path d="m7 15 4-4 4 4 6-6"/>',
            'kereses'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'felho'      => '<path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.78A6 6 0 0 0 4 12a4 4 0 0 0 1 7.87z"/>',
            'lakat'      => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'kulcs'      => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
            'mentes'     => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
            'frissites'  => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
            'wifi_ki'    => '<path d="m2 2 20 20M8.5 16.4a5 5 0 0 1 7 0M2 8.8a15 15 0 0 1 4.2-2.6M10.7 5.1A15 15 0 0 1 22 8.8M5 12.9a10 10 0 0 1 5.2-2.7M16.8 11.7a10 10 0 0 1 2.2 1.2M12 20h.01"/>',
            'robot'      => '<rect x="3" y="8" width="18" height="12" rx="3"/><path d="M12 8V4M8 4h8M9 14h.01M15 14h.01"/>',
            'szikra'     => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3z"/>',
            'csillag'    => '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
            'pipa'       => '<path d="M20 6 9 17l-5-5"/>',
            'ora'        => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'sebesseg'   => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
            'csapat'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
            'bolt'       => '<path d="M3 9h18l-1.5-5h-15z"/><path d="M4 9v11h16V9M9 20v-6h6v6"/>',
            'vonalkod'   => '<path d="M3 5v14M7 5v14M11 5v14M14 5v14M18 5v14M21 5v14"/>',
            'garancia'   => '<circle cx="12" cy="8" r="6"/><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"/>',
            'alairas'    => '<path d="M3 17c3-3 5-8 8-8s0 8 3 8 3-3 5-3M3 21h18"/>',
            'kamera'     => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3"/>',
            'gdpr'       => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            'beallitas'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-2.82 1.17V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 3.6 14H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 14 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'globusz'    => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
            'tamogatas'  => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><path d="m4.93 4.93 4.24 4.24M14.83 14.83l4.24 4.24M14.83 9.17l4.24-4.24M4.93 19.07l4.24-4.24"/>',
            'konyv'      => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V2H6.5A2.5 2.5 0 0 0 4 4.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
            'raketa'     => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/>',
        ];

        return (array) apply_filters('sdh_sales_ikonok', $i);
    }

    /* =================================================================
     * Szekció-sémák
     * ============================================================== */

    /** @return array<string, array{cim: string, ikon: string, csoport: string, leiras: string, mezok: array}> */
    public static function tipusok(): array
    {
        if (self::$tipus_cache !== null) {
            return self::$tipus_cache;
        }

        $cim    = ['k' => 'cim', 't' => 'szoveg', 'c' => 'Cím', 'max' => 200];
        $alcim  = ['k' => 'alcim', 't' => 'hosszu', 'c' => 'Alcím', 'max' => 600];
        $gomb   = static fn (string $k = 'gomb', string $c = 'Gomb'): array => ['k' => $k, 't' => 'link', 'c' => $c];
        $kulcsok = static fn (string $tipus, string $c, string $s = 'Üresen: mind, a tartalom sorrendjében.'): array => ['k' => 'kulcsok', 't' => 'tobb', 'tipus' => $tipus, 'c' => $c, 's' => $s];

        $t = [
            'hero' => ['Nyitó (hero)', 'raketa', 'Fejléc', 'Nagy cím, alcím, gombok, kép.', [
                ['k' => 'cimke', 't' => 'szoveg', 'c' => 'Kis felirat a cím fölött', 'max' => 80, 'fel' => true],
                ['k' => 'elrendezes', 't' => 'valaszto', 'c' => 'Elrendezés', 'o' => ['kozep' => 'Középre', 'bal' => 'Balra', 'kep_jobb' => 'Kép jobbra'], 'alap' => 'kep_jobb', 'fel' => true],
                $cim + ['kotelezo' => true],
                $alcim,
                $gomb('gomb', 'Fő gomb'),
                $gomb('gomb2', 'Második gomb'),
                ['k' => 'kep', 't' => 'kep', 'c' => 'Kép', 'fel' => true],
                ['k' => 'kep_alt', 't' => 'szoveg', 'c' => 'Kép leírása (alt)', 'max' => 200, 'fel' => true],
                ['k' => 'pontok', 't' => 'ismetlo', 'c' => 'Bizalmi pontok', 'max' => 6, 'al' => [['k' => 'szoveg', 't' => 'szoveg', 'c' => 'Szöveg', 'max' => 120]]],
            ]],
            'logo_sav' => ['Logósáv', 'bolt', 'Bizalom', 'Partnerek, ügyfelek logói.', [
                $cim,
                ['k' => 'logok', 't' => 'ismetlo', 'c' => 'Logók', 'max' => 24, 'al' => [
                    ['k' => 'kep', 't' => 'kep', 'c' => 'Logó'], ['k' => 'nev', 't' => 'szoveg', 'c' => 'Név', 'max' => 80], ['k' => 'url', 't' => 'url', 'c' => 'Hivatkozás'],
                ]],
            ]],
            'jellemzok' => ['Jellemzők (ikonrács)', 'villam', 'Tartalom', 'Ikon + cím + rövid szöveg rácsban.', [
                $cim, $alcim,
                ['k' => 'oszlop', 't' => 'valaszto', 'c' => 'Oszlopok', 'o' => ['2' => '2', '3' => '3', '4' => '4'], 'alap' => '3', 'fel' => true],
                ['k' => 'elemek', 't' => 'ismetlo', 'c' => 'Elemek', 'max' => 24, 'al' => [
                    ['k' => 'ikon', 't' => 'ikon', 'c' => 'Ikon'], ['k' => 'cim', 't' => 'szoveg', 'c' => 'Cím', 'max' => 120], ['k' => 'szoveg', 't' => 'hosszu', 'c' => 'Szöveg', 'max' => 600],
                ]],
            ]],
            'funkcio_racs' => ['Funkciórács', 'munkalap', 'Termék', 'Funkciók kártyái (a Funkció elemekből).', [
                $cim, $alcim, $kulcsok('funkcio', 'Funkciók'), ['k' => 'db', 't' => 'szam', 'c' => 'Legfeljebb ennyi', 'min' => 0, 'max' => 100, 'fel' => true], $gomb('gomb', 'Gomb alul'),
            ]],
            'modul_racs' => ['Modulrács', 'raktar', 'Termék', 'A modulok kártyái csoportonként.', [
                $cim, $alcim, $kulcsok('modul', 'Modulok'), ['k' => 'csoportositva', 't' => 'kapcsolo', 'c' => 'Csoportonként', 'alap' => true],
            ]],
            'iparag_racs' => ['Iparágrács', 'bolt', 'Termék', 'Iparági megoldások kártyái.', [
                $cim, $alcim, $kulcsok('iparag', 'Iparágak'),
            ]],
            'arak' => ['Árkártyák', 'penz', 'Értékesítés', 'Csomagok árkártyái havi / éves váltóval.', [
                $cim, $alcim, ['k' => 'csomagok', 't' => 'tobb', 'tipus' => 'csomag', 'c' => 'Csomagok', 's' => 'Üresen: minden aktív csomag.'],
                ['k' => 'alap_ciklus', 't' => 'valaszto', 'c' => 'Alap számlázási ciklus', 'o' => ['eves' => 'Éves', 'havi' => 'Havi'], 'alap' => 'eves', 'fel' => true],
                ['k' => 'ciklus_valto', 't' => 'kapcsolo', 'c' => 'Havi / éves váltó', 'alap' => true, 'fel' => true],
                ['k' => 'megjegyzes', 't' => 'hosszu', 'c' => 'Megjegyzés a kártyák alatt', 'max' => 400],
            ]],
            'ar_tablazat' => ['Ártáblázat (összehasonlítás)', 'szamla', 'Értékesítés', 'A csomagok tételes összevetése.', [
                $cim, ['k' => 'csomagok', 't' => 'tobb', 'tipus' => 'csomag', 'c' => 'Csomagok', 's' => 'Üresen: minden aktív csomag.'],
                ['k' => 'csak_kulonbseg', 't' => 'kapcsolo', 'c' => 'Csak a különbségek', 'alap' => false],
            ]],
            'kalkulator' => ['Árkalkulátor', 'kassza', 'Értékesítés', 'Interaktív ár-összeállító (kosárba tehető).', [
                $cim, $alcim, ['k' => 'alap_csomag', 't' => 'egy', 'tipus' => 'csomag', 'c' => 'Alapból kiválasztott csomag', 'fel' => true],
            ]],
            'osszehasonlitas' => ['Összehasonlítás (vs. konkurens)', 'grafikon', 'Értékesítés', 'Szempontonkénti összevetés más rendszerekkel.', [
                $cim, $alcim, ['k' => 'konkurensek', 't' => 'tobb', 'tipus' => 'konkurens', 'c' => 'Konkurensek'],
                ['k' => 'sorok', 't' => 'ismetlo', 'c' => 'Szempontok', 'max' => 40, 'al' => [
                    ['k' => 'szempont', 't' => 'szoveg', 'c' => 'Szempont', 'max' => 120], ['k' => 'mi', 't' => 'szoveg', 'c' => 'Nálunk', 'max' => 120], ['k' => 'mas', 't' => 'szoveg', 'c' => 'Máshol', 'max' => 120],
                ]],
                ['k' => 'megjegyzes', 't' => 'hosszu', 'c' => 'Forrás / megjegyzés', 'max' => 400],
            ]],
            'lepesek' => ['Lépések', 'frissites', 'Tartalom', 'Számozott folyamat (pl. így indulsz).', [
                $cim, $alcim, ['k' => 'lepesek', 't' => 'ismetlo', 'c' => 'Lépések', 'max' => 12, 'al' => [
                    ['k' => 'ikon', 't' => 'ikon', 'c' => 'Ikon'], ['k' => 'cim', 't' => 'szoveg', 'c' => 'Cím', 'max' => 120], ['k' => 'szoveg', 't' => 'hosszu', 'c' => 'Szöveg', 'max' => 600],
                ]],
            ]],
            'szoveg' => ['Szöveg', 'szamla', 'Tartalom', 'Formázott szövegblokk.', [
                $cim, ['k' => 'html', 't' => 'html', 'c' => 'Szöveg'],
                ['k' => 'szelesseg', 't' => 'valaszto', 'c' => 'Szélesség', 'o' => ['keskeny' => 'Keskeny', 'normal' => 'Normál', 'szeles' => 'Széles'], 'alap' => 'normal', 'fel' => true],
            ]],
            'kep_szoveg' => ['Kép + szöveg', 'kamera', 'Tartalom', 'Kép az egyik, szöveg a másik oldalon.', [
                $cim, ['k' => 'html', 't' => 'html', 'c' => 'Szöveg'],
                ['k' => 'kep', 't' => 'kep', 'c' => 'Kép', 'fel' => true], ['k' => 'kep_alt', 't' => 'szoveg', 'c' => 'Kép leírása (alt)', 'max' => 200, 'fel' => true],
                ['k' => 'kep_oldal', 't' => 'valaszto', 'c' => 'Kép oldala', 'o' => ['jobb' => 'Jobbra', 'bal' => 'Balra'], 'alap' => 'jobb', 'fel' => true],
                $gomb(),
            ]],
            'video' => ['Videó', 'kamera', 'Tartalom', 'Beágyazott videó (kattintásra töltődik).', [
                $cim, ['k' => 'url', 't' => 'url', 'c' => 'Videó címe (YouTube / Vimeo / MP4)', 'kotelezo' => true],
                ['k' => 'poszter', 't' => 'kep', 'c' => 'Előnézeti kép'], ['k' => 'leiras', 't' => 'hosszu', 'c' => 'Leírás', 'max' => 600],
            ]],
            'velemeny' => ['Vélemények', 'csillag', 'Bizalom', 'Ügyfél-vélemények.', [
                $cim, ['k' => 'velemenyek', 't' => 'ismetlo', 'c' => 'Vélemények', 'max' => 24, 'al' => [
                    ['k' => 'szoveg', 't' => 'hosszu', 'c' => 'Vélemény', 'max' => 800], ['k' => 'nev', 't' => 'szoveg', 'c' => 'Név', 'max' => 80],
                    ['k' => 'ceg', 't' => 'szoveg', 'c' => 'Cég / szerep', 'max' => 120], ['k' => 'kep', 't' => 'kep', 'c' => 'Fotó'],
                    ['k' => 'ertekeles', 't' => 'szam', 'c' => 'Értékelés (1–5)', 'min' => 1, 'max' => 5],
                ]],
            ]],
            'szamok' => ['Számok', 'grafikon', 'Bizalom', 'Nagy számok rövid felirattal.', [
                $cim, ['k' => 'szamok', 't' => 'ismetlo', 'c' => 'Számok', 'max' => 8, 'al' => [
                    ['k' => 'ertek', 't' => 'szoveg', 'c' => 'Érték', 'max' => 30], ['k' => 'cimke', 't' => 'szoveg', 'c' => 'Felirat', 'max' => 120], ['k' => 'ikon', 't' => 'ikon', 'c' => 'Ikon'],
                ]],
            ]],
            'gyik' => ['GYIK', 'tamogatas', 'Tartalom', 'Gyakori kérdések (kategória vagy kulcsok szerint).', [
                $cim, ['k' => 'kategoria', 't' => 'szoveg', 'c' => 'Kategória', 'max' => 80, 'fel' => true],
                ['k' => 'db', 't' => 'szam', 'c' => 'Legfeljebb ennyi', 'min' => 0, 'max' => 200, 'fel' => true],
                $kulcsok('gyik', 'Kérdések', 'Üresen: a kategória kérdései.'),
            ]],
            'cta' => ['Cselekvésre hívás', 'raketa', 'Értékesítés', 'Kiemelt sáv gombokkal.', [
                $cim + ['kotelezo' => true], ['k' => 'szoveg', 't' => 'hosszu', 'c' => 'Szöveg', 'max' => 600], $gomb('gomb', 'Fő gomb'), $gomb('gomb2', 'Második gomb'),
                ['k' => 'stilus', 't' => 'valaszto', 'c' => 'Stílus', 'o' => ['atmenet' => 'Színátmenet', 'sima' => 'Sima'], 'alap' => 'atmenet', 'fel' => true],
            ]],
            'urlap' => ['Űrlap', 'email', 'Kapcsolat', 'Kapcsolat, ajánlatkérés, egyedi igény, partner.', [
                $cim, $alcim, ['k' => 'urlap', 't' => 'valaszto', 'c' => 'Űrlap', 'o' => ['kapcsolat' => 'Kapcsolat', 'ajanlat' => 'Ajánlatkérés', 'egyedi' => 'Egyedi igény', 'partner' => 'Partner'], 'alap' => 'kapcsolat', 'fel' => true],
                ['k' => 'siker', 't' => 'hosszu', 'c' => 'Köszönő szöveg', 'max' => 400],
            ]],
            'foglalas' => ['Időpontfoglalás', 'naptar', 'Kapcsolat', 'Szabad sávok, foglalás.', [
                $cim, $alcim, ['k' => 'tipus', 't' => 'valaszto', 'c' => 'Időpont fajtája', 'o' => ['demo' => 'Bemutató', 'telepites' => 'Telepítés', 'oktatas' => 'Oktatás', 'support' => 'Support'], 'alap' => 'demo', 'fel' => true],
            ]],
            'demo_inditas' => ['Demó indítása', 'raketa', 'Értékesítés', 'Saját próbapéldány indítása.', [
                $cim, $alcim, ['k' => 'gomb_szoveg', 't' => 'szoveg', 'c' => 'Gomb felirata', 'max' => 60, 'fel' => true],
            ]],
            'blog_lista' => ['Bloglista', 'konyv', 'Tartalom', 'A legutóbbi cikkek.', [
                $cim, ['k' => 'db', 't' => 'szam', 'c' => 'Cikkek száma', 'min' => 1, 'max' => 24, 'alap' => 3, 'fel' => true],
                ['k' => 'kategoria', 't' => 'szoveg', 'c' => 'Kategória (üresen: mind)', 'max' => 80, 'fel' => true], $gomb('gomb', 'Gomb alul'),
            ]],
            'tudasbazis' => ['Tudásbázis', 'konyv', 'Súgó', 'Súgócikkek kereséssel.', [
                $cim, $alcim, ['k' => 'kereso', 't' => 'kapcsolo', 'c' => 'Keresőmező', 'alap' => true],
            ]],
            'statusz' => ['Rendszerállapot', 'sebesseg', 'Súgó', 'Szolgáltatások állapota.', [$cim, $alcim]],
            'jogi' => ['Jogi szöveg', 'gdpr', 'Jogi', 'Az oldal jogi szövege verzióval.', [
                ['k' => 'verzio', 't' => 'szoveg', 'c' => 'Verzió', 'max' => 30, 'fel' => true], ['k' => 'hatalyos', 't' => 'datum', 'c' => 'Hatályos', 'fel' => true],
            ]],
            'kereso' => ['Kereső', 'kereses', 'Súgó', 'Teljes oldalas kereső.', [
                $cim, $alcim, ['k' => 'helyorzo', 't' => 'szoveg', 'c' => 'Mező szövege', 'max' => 120],
            ]],
            'fiok' => ['Ügyfélfiók', 'ugyfel', 'Vásárlás', 'Belépés e-mailes linkkel, licencek, számlák.', [$cim]],
            'kosar' => ['Kosár', 'kassza', 'Vásárlás', 'A kosár tartalma.', [$cim]],
            'penztar' => ['Pénztár', 'penz', 'Vásárlás', 'Számlázási adatok, fizetés.', [$cim]],
            'oktatas' => ['Oktatás', 'konyv', 'Súgó', 'Leckék modulonként, kvízzel.', [
                $cim, $alcim, ['k' => 'modul', 't' => 'egy', 'tipus' => 'modul', 'c' => 'Csak ez a modul', 'fel' => true],
            ]],
            'telepito' => ['Telepítési varázsló', 'szerszam', 'Súgó', 'Lépésenkénti telepítési útmutató.', [$cim, $alcim]],
        ];

        $ki = [];

        foreach ($t as $k => [$c, $ikon, $cs, $l, $mezok]) {
            $ki[$k] = ['cim' => $c, 'ikon' => $ikon, 'csoport' => $cs, 'leiras' => $l, 'mezok' => array_merge($mezok, self::kozos_mezok())];
        }

        self::$tipus_cache = (array) apply_filters('sdh_sales_szekcio_tipusok', $ki);

        return self::$tipus_cache;
    }

    /** @return array<int, array<string, mixed>> minden szekció közös mezői (a „Megjelenés” lapfülön) */
    private static function kozos_mezok(): array
    {
        return [
            ['k' => 'horgony', 't' => 'szoveg', 'c' => 'Horgony (#azonosító)', 's' => 'Belső linkhez, pl. #arak', 'max' => 40, 'ful' => 'Megjelenés', 'fel' => true],
            ['k' => 'hatter', 't' => 'valaszto', 'c' => 'Háttér', 'o' => ['' => 'Alap', 'felulet' => 'Felület', 'kiemelt' => 'Kiemelt', 'atmenet' => 'Színátmenet'], 'ful' => 'Megjelenés', 'fel' => true],
            ['k' => 'terkoz', 't' => 'valaszto', 'c' => 'Térköz', 'o' => ['' => 'Normál', 'kicsi' => 'Kicsi', 'nagy' => 'Nagy'], 'ful' => 'Megjelenés', 'fel' => true],
            ['k' => 'rejtett', 't' => 'kapcsolo', 'c' => 'Rejtett', 'rejtett_mezo' => true],
        ];
    }

    /** Az új szekció kezdőadata a séma alapértékeiből. @return array<string, mixed> */
    public static function uj(string $tipus): array
    {
        $s = self::tipusok()[$tipus] ?? null;

        if ($s === null) {
            return [];
        }

        $a = ['tipus' => $tipus];

        foreach ($s['mezok'] as $m) {
            if (array_key_exists('alap', $m)) {
                $a[$m['k']] = $m['alap'];
            }
        }

        if (isset($a['cim']) === false && self::mezo($s['mezok'], 'cim') !== null) {
            $a['cim'] = $s['cim'];
        }

        return $a;
    }

    /* =================================================================
     * Elem-sémák (Tartalom › Elemek)
     * ============================================================== */

    /**
     * @return array<string, array{cim: string, egyes: string, ikon: string, kulcsmezo: string, cimmezo: string, mezok: array}>
     */
    public static function elem_semak(): array
    {
        $kulcs = ['k' => 'kulcs', 't' => 'szoveg', 'c' => 'Kulcs', 's' => 'Kisbetű, szám, kötőjel – mentés után nem változik.', 'kotelezo' => true, 'max' => 80, 'fel' => true, 'kulcs' => true];
        $penz  = static fn (string $k, string $c, string $ful = 'Árak'): array => ['k' => $k, 't' => 'szam', 'c' => $c, 'penz' => true, 'min' => 0, 'max' => 100000000, 'fel' => true, 'ful' => $ful];
        $db    = static fn (string $k, string $c, string $ful = 'Árak'): array => ['k' => $k, 't' => 'szam', 'c' => $c, 'min' => 0, 'max' => 100000, 'fel' => true, 'ful' => $ful];

        $s = [
            'csomag' => ['Csomagok', 'Csomag', 'penz', 'kulcs', 'nev', [
                $kulcs, ['k' => 'nev', 't' => 'szoveg', 'c' => 'Név', 'kotelezo' => true, 'max' => 80, 'fel' => true],
                ['k' => 'leiras', 't' => 'hosszu', 'c' => 'Leírás', 'max' => 600],
                ['k' => 'cta', 't' => 'szoveg', 'c' => 'Gomb felirata', 'max' => 40, 'fel' => true],
                ['k' => 'kiemelt', 't' => 'kapcsolo', 'c' => 'Kiemelt csomag', 'fel' => true],
                $penz('havi', 'Havi díj (nettó)'), $penz('eves', 'Éves díj (nettó)'),
                $db('felhasznalo_alap', 'Benne foglalt felhasználó'), $penz('felhasznalo_extra_havi', 'Extra felhasználó / hó (nettó)'),
                $db('telephely_alap', 'Benne foglalt telephely'), $penz('telephely_extra_havi', 'Extra telephely / hó (nettó)'),
                $db('max_peldany', 'Telepíthető példány'),
                ['k' => 'modulok', 't' => 'tobb', 'tipus' => 'modul', 'c' => 'Benne foglalt modulok', 'ful' => 'Modulok'],
            ]],
            'modul' => ['Modulok', 'Modul', 'raktar', 'kulcs', 'nev', [
                $kulcs + ['s' => 'A Műhely modulkulcsa (a licenc ezt engedélyezi).'], ['k' => 'nev', 't' => 'szoveg', 'c' => 'Név', 'kotelezo' => true, 'max' => 80, 'fel' => true],
                ['k' => 'csoport', 't' => 'szoveg', 'c' => 'Csoport', 'max' => 60, 'fel' => true], ['k' => 'ikon', 't' => 'ikon', 'c' => 'Ikon', 'fel' => true],
                ['k' => 'leiras', 't' => 'hosszu', 'c' => 'Leírás', 'max' => 800],
                $penz('havi', 'Havi díj (nettó)'), $penz('eves', 'Éves díj (nettó)'),
                ['k' => 'csomagban', 't' => 'tobb', 'tipus' => 'csomag', 'c' => 'Ezekben a csomagokban benne van', 'ful' => 'Árak'],
            ]],
            'szolgaltatas' => ['Szolgáltatások', 'Szolgáltatás', 'szerszam', 'kulcs', 'nev', [
                $kulcs, ['k' => 'nev', 't' => 'szoveg', 'c' => 'Név', 'kotelezo' => true, 'max' => 80, 'fel' => true],
                ['k' => 'tipus', 't' => 'valaszto', 'c' => 'Díjazás', 'o' => ['egyszeri' => 'Egyszeri', 'havi' => 'Havi', 'eves' => 'Éves'], 'alap' => 'egyszeri', 'fel' => true],
                $penz('ar', 'Ár (nettó)', ''),
                ['k' => 'leiras', 't' => 'hosszu', 'c' => 'Leírás', 'max' => 800],
                ['k' => 'sla.valaszido_ora', 't' => 'szam', 'c' => 'Válaszidő (óra)', 'min' => 0, 'max' => 720, 'fel' => true, 'ful' => 'SLA'],
                ['k' => 'sla.munkaido', 't' => 'szoveg', 'c' => 'Munkaidő', 'max' => 60, 'fel' => true, 'ful' => 'SLA'],
            ]],
            'kupon' => ['Kuponok', 'Kupon', 'cimke', 'kod', 'kod', [
                ['k' => 'kod', 't' => 'szoveg', 'c' => 'Kód', 'kotelezo' => true, 'max' => 40, 'fel' => true, 'kulcs' => true, 'nagybetu' => true],
                ['k' => 'tipus', 't' => 'valaszto', 'c' => 'Kedvezmény', 'o' => ['szazalek' => 'Százalék', 'osszeg' => 'Összeg (nettó Ft)'], 'alap' => 'szazalek', 'fel' => true],
                ['k' => 'ertek', 't' => 'szam', 'c' => 'Érték', 'min' => 0, 'max' => 10000000, 'fel' => true],
                ['k' => 'max', 't' => 'szam', 'c' => 'Felhasználható (db, 0 = korlátlan)', 'min' => 0, 'max' => 1000000, 'fel' => true],
                ['k' => 'lejar', 't' => 'datum', 'c' => 'Lejárat', 'fel' => true],
                ['k' => 'leiras', 't' => 'hosszu', 'c' => 'Belső megjegyzés', 'max' => 400],
            ]],
            'gyik' => ['GYIK', 'Kérdés', 'tamogatas', 'kulcs', 'kerdes', [
                $kulcs, ['k' => 'kategoria', 't' => 'szoveg', 'c' => 'Kategória', 'max' => 80, 'fel' => true],
                ['k' => 'kerdes', 't' => 'szoveg', 'c' => 'Kérdés', 'kotelezo' => true, 'max' => 300],
                ['k' => 'valasz', 't' => 'html', 'c' => 'Válasz', 'szuk' => true],
                ['k' => 'kapcsolodo', 't' => 'tobb', 'tipus' => 'gyik', 'c' => 'Kapcsolódó kérdések', 'ful' => 'Kapcsolódó'],
            ]],
            'funkcio' => ['Funkciók', 'Funkció', 'munkalap', 'kulcs', 'cim', [
                $kulcs, ['k' => 'cim', 't' => 'szoveg', 'c' => 'Cím', 'kotelezo' => true, 'max' => 120, 'fel' => true],
                ['k' => 'ikon', 't' => 'ikon', 'c' => 'Ikon', 'fel' => true], ['k' => 'modul', 't' => 'egy', 'tipus' => 'modul', 'c' => 'Modul', 'fel' => true],
                ['k' => 'rovid', 't' => 'hosszu', 'c' => 'Rövid leírás (kártya)', 'max' => 300],
                ['k' => 'leiras', 't' => 'html', 'c' => 'Részletes leírás', 'ful' => 'Leírás'],
            ]],
            'iparag' => ['Iparágak', 'Iparág', 'bolt', 'kulcs', 'cim', [
                $kulcs, ['k' => 'cim', 't' => 'szoveg', 'c' => 'Cím', 'kotelezo' => true, 'max' => 120, 'fel' => true],
                ['k' => 'ikon', 't' => 'ikon', 'c' => 'Ikon', 'fel' => true], ['k' => 'rovid', 't' => 'hosszu', 'c' => 'Rövid leírás (kártya)', 'max' => 300],
                ['k' => 'leiras', 't' => 'html', 'c' => 'Részletes leírás', 'ful' => 'Leírás'],
            ]],
            'lecke' => ['Leckék', 'Lecke', 'konyv', 'kulcs', 'cim', [
                $kulcs, ['k' => 'cim', 't' => 'szoveg', 'c' => 'Cím', 'kotelezo' => true, 'max' => 160, 'fel' => true],
                ['k' => 'modul', 't' => 'egy', 'tipus' => 'modul', 'c' => 'Modul', 'fel' => true], ['k' => 'video', 't' => 'url', 'c' => 'Videó', 'fel' => true],
                ['k' => 'html', 't' => 'html', 'c' => 'Tananyag', 'ful' => 'Tananyag'],
                ['k' => 'kviz', 't' => 'ismetlo', 'c' => 'Kvízkérdések', 'max' => 20, 'ful' => 'Kvíz', 'al' => [
                    ['k' => 'kerdes', 't' => 'szoveg', 'c' => 'Kérdés', 'max' => 300], ['k' => 'valaszok', 't' => 'hosszu', 'c' => 'Válaszok (soronként egy)', 'max' => 800],
                    ['k' => 'helyes', 't' => 'szam', 'c' => 'Helyes válasz sorszáma', 'min' => 1, 'max' => 10],
                ]],
            ]],
            'email' => ['E-mail sablonok', 'E-mail sablon', 'email', 'kulcs', 'targy', [
                $kulcs, ['k' => 'targy', 't' => 'szoveg', 'c' => 'Tárgy', 'kotelezo' => true, 'max' => 200, 's' => 'Tokenek: {nev}, {rendeles}, {licenc_kulcs}, {letoltes_url}, {termeknev}…'],
                ['k' => 'html', 't' => 'html', 'c' => 'Levél szövege'],
            ]],
            'konkurens' => ['Konkurensek', 'Konkurens', 'grafikon', 'kulcs', 'nev', [
                $kulcs, ['k' => 'nev', 't' => 'szoveg', 'c' => 'Név', 'kotelezo' => true, 'max' => 80, 'fel' => true],
                ['k' => 'url', 't' => 'url', 'c' => 'Webhely', 'fel' => true], ['k' => 'ar', 't' => 'szoveg', 'c' => 'Ár (szövegesen)', 'max' => 120, 'fel' => true],
                ['k' => 'leiras', 't' => 'hosszu', 'c' => 'Leírás', 'max' => 800],
                ['k' => 'jellemzok', 't' => 'ismetlo', 'c' => 'Jellemzők', 'max' => 40, 'ful' => 'Jellemzők', 'al' => [
                    ['k' => 'szempont', 't' => 'szoveg', 'c' => 'Szempont', 'max' => 120], ['k' => 'ertek', 't' => 'szoveg', 'c' => 'Érték', 'max' => 120],
                ]],
            ]],
        ];

        $ki = [];

        foreach ($s as $k => [$cim, $egyes, $ikon, $kulcsmezo, $cimmezo, $mezok]) {
            $ki[$k] = ['cim' => $cim, 'egyes' => $egyes, 'ikon' => $ikon, 'kulcsmezo' => $kulcsmezo, 'cimmezo' => $cimmezo, 'mezok' => $mezok];
        }

        return (array) apply_filters('sdh_sales_elem_semak', $ki);
    }

    /* =================================================================
     * Tisztítás
     * ============================================================== */

    /**
     * Egy szekció tisztítása a típusa sémája szerint. Ismeretlen típus: null.
     *
     * @param array<string, mixed> $sz
     * @return array<string, mixed>|null
     */
    public static function ervenyesit(array $sz): ?array
    {
        $tipus = sanitize_key((string) ($sz['tipus'] ?? ''));
        $s     = self::tipusok()[$tipus] ?? null;

        if ($s === null) {
            return null;
        }

        $ki = ['tipus' => $tipus] + self::mezok_tisztit($s['mezok'], $sz);

        if (empty($ki['rejtett'])) {
            unset($ki['rejtett']);
        }

        return $ki;
    }

    /**
     * Szekciólista tisztítása (ismeretlen típus kimarad, legfeljebb 80 szekció).
     *
     * @param array<int, mixed> $lista
     * @return array<int, array<string, mixed>>
     */
    public static function ervenyesit_lista(array $lista): array
    {
        $ki = [];

        foreach (array_slice(array_values($lista), 0, 80) as $sz) {
            if (is_array($sz)) {
                $t = self::ervenyesit($sz);

                if ($t !== null) {
                    $ki[] = $t;
                }
            }
        }

        return $ki;
    }

    /**
     * Elem tisztítása a séma szerint; a sémán kívüli (más által írt) mezők általános tisztítással megmaradnak.
     *
     * @param array<string, mixed> $adat
     * @return array<string, mixed>
     */
    public static function elem_tisztit(string $tipus, array $adat): array
    {
        $s = self::elem_semak()[$tipus] ?? null;

        if ($s === null) {
            return self::altalanos($adat);
        }

        return self::mezok_tisztit($s['mezok'], $adat);
    }

    /**
     * @param array<int, array<string, mixed>> $mezok
     * @param array<string, mixed>             $adat
     * @return array<string, mixed>
     */
    public static function mezok_tisztit(array $mezok, array $adat): array
    {
        $ki     = [];
        $ismert = [];

        foreach ($mezok as $m) {
            $k   = (string) $m['k'];
            $gyo = explode('.', $k)[0];
            $ismert[$gyo] = true;
            $v   = self::kiolvas($adat, $k);

            if ($v === null) {
                if (!empty($m['kotelezo']) && isset($m['alap'])) {
                    self::beir($ki, $k, $m['alap']);
                }
                continue;
            }

            self::beir($ki, $k, self::mezo_tisztit($m, $v));
        }

        foreach ($adat as $k => $v) {
            $k = (string) $k;

            if ($k === 'tipus' || isset($ismert[$k]) || preg_match('/^[a-z0-9_]{1,40}$/', $k) !== 1) {
                continue;
            }

            $ki[$k] = self::altalanos($v);
        }

        // A pontozott (csoportos) mezők ismeretlen testvérei is megmaradnak (pl. sla.egyeb).
        foreach ($ismert as $gyo => $_) {
            if (isset($adat[$gyo]) && is_array($adat[$gyo]) && isset($ki[$gyo]) && is_array($ki[$gyo]) && !array_is_list($adat[$gyo])) {
                foreach ($adat[$gyo] as $kk => $vv) {
                    if (!array_key_exists($kk, $ki[$gyo]) && preg_match('/^[a-z0-9_]{1,40}$/', (string) $kk) === 1) {
                        $ki[$gyo][$kk] = self::altalanos($vv);
                    }
                }
            }
        }

        return $ki;
    }

    /**
     * Egy mező tisztítása a fajtája szerint.
     *
     * @param array<string, mixed> $m
     * @param mixed                $v
     * @return mixed
     */
    public static function mezo_tisztit(array $m, $v)
    {
        $max = isset($m['max']) ? (int) $m['max'] : 0;

        switch ((string) $m['t']) {
            case 'szoveg':
                $s = sanitize_text_field(is_scalar($v) ? (string) $v : '');
                $s = $max > 0 ? mb_substr($s, 0, $max) : mb_substr($s, 0, 1000);

                if (!empty($m['kulcs'])) {
                    $s = !empty($m['nagybetu']) ? strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $s) ?? '') : sanitize_title($s);
                }

                if ((string) $m['k'] === 'horgony') {
                    $s = sanitize_title($s);
                }

                return $s;

            case 'hosszu':
                $s = sanitize_textarea_field(is_scalar($v) ? (string) $v : '');

                return mb_substr($s, 0, $max > 0 ? $max : 5000);

            case 'html':
                return self::html(is_scalar($v) ? (string) $v : '', !empty($m['szuk']));

            case 'kep':
            case 'url':
                if (is_array($v)) {
                    $v = (string) ($v['url'] ?? '');
                }

                return self::url(is_scalar($v) ? (string) $v : '');

            case 'ikon':
                $k = sanitize_key(is_scalar($v) ? (string) $v : '');

                return isset(self::ikonok()[$k]) ? $k : '';

            case 'valaszto':
                $o = array_map('strval', array_keys((array) ($m['o'] ?? [])));
                $s = is_scalar($v) ? (string) $v : '';

                return in_array($s, $o, true) ? $s : (string) ($m['alap'] ?? ($o[0] ?? ''));

            case 'kapcsolo':
                return filter_var($v, FILTER_VALIDATE_BOOLEAN);

            case 'szam':
                $n = is_numeric($v) ? (int) round((float) $v) : (int) ($m['alap'] ?? 0);

                if (isset($m['min'])) {
                    $n = max((int) $m['min'], $n);
                }

                if (isset($m['max'])) {
                    $n = min((int) $m['max'], $n);
                }

                return $n;

            case 'datum':
                $s = is_scalar($v) ? (string) $v : '';

                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $d) === 1 && checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
                    return $s;
                }

                return null;

            case 'szin':
                $s = is_scalar($v) ? trim((string) $v) : '';

                return preg_match('/^#[0-9a-fA-F]{3,8}$/', $s) === 1 ? strtolower($s) : '';

            case 'link':
                if (is_string($v)) {
                    $v = ['url' => $v];
                }

                $v = is_array($v) ? $v : [];

                return [
                    'szoveg' => mb_substr(sanitize_text_field((string) ($v['szoveg'] ?? '')), 0, 80),
                    'url'    => self::url((string) ($v['url'] ?? '')),
                    'uj'     => !empty($v['uj']),
                ];

            case 'tobb':
                $v = is_array($v) ? $v : (is_string($v) && $v !== '' ? explode(',', $v) : []);
                $l = [];

                foreach ($v as $x) {
                    if (is_scalar($x)) {
                        $x = mb_substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $x) ?? '', 0, 120);

                        if ($x !== '' && !in_array($x, $l, true)) {
                            $l[] = $x;
                        }
                    }
                }

                return array_slice($l, 0, 200);

            case 'egy':
                return mb_substr(preg_replace('/[^A-Za-z0-9_\-]/', '', is_scalar($v) ? (string) $v : '') ?? '', 0, 120);

            case 'ismetlo':
                $l = [];

                foreach (array_slice(is_array($v) ? array_values($v) : [], 0, $max > 0 ? $max : 50) as $sor) {
                    if (is_array($sor)) {
                        $l[] = self::mezok_tisztit((array) ($m['al'] ?? []), $sor);
                    }
                }

                return $l;
        }

        return self::altalanos($v);
    }

    /** Biztonságos HTML (richtext). */
    public static function html(string $s, bool $szuk = false): string
    {
        $s = wp_kses($s, $szuk ? self::HTML_SZUK : self::HTML, ['http', 'https', 'mailto', 'tel']);

        // A target=_blank linkek kapjanak rel=noopener-t.
        $s = (string) preg_replace_callback('/<a\s([^>]*target="_blank"[^>]*)>/i', static function ($m) {
            return stripos($m[1], 'rel=') === false ? '<a ' . $m[1] . ' rel="noopener">' : $m[0];
        }, $s);

        return trim($s);
    }

    /** URL: http(s), mailto, tel, relatív (/…) vagy horgony (#…); minden más üres. */
    public static function url(string $u): string
    {
        $u = trim($u);

        if ($u === '') {
            return '';
        }

        // Szóköz, idézőjel, kacsacsőr: ez nem cím (és nem engedjük, hogy esc_url „javítsa”).
        if (preg_match('/[\s<>"\'`\\\\]/', $u) === 1) {
            return '';
        }

        // Séma nélküli, nem relatív szöveg csak akkor cím, ha domainnel kezdődik.
        if (preg_match('#^([a-z][a-z0-9+.-]*:|/|\#)#i', $u) !== 1 && preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+(/|$)#i', $u) !== 1) {
            return '';
        }

        if ($u[0] === '#') {
            return '#' . sanitize_title(substr($u, 1));
        }

        if ($u[0] === '/' && (strlen($u) === 1 || $u[1] !== '/')) {
            return '/' . ltrim((string) preg_replace('/[^A-Za-z0-9\-._~\/?#\[\]@!$&()*+,;=%]/', '', $u), '/');
        }

        return esc_url_raw($u, ['http', 'https', 'mailto', 'tel']);
    }

    /**
     * Sémán kívüli érték általános tisztítása (rekurzív).
     *
     * @param mixed $v
     * @return mixed
     */
    public static function altalanos($v, int $mely = 0)
    {
        if ($mely > 6) {
            return null;
        }

        if (is_string($v)) {
            return strpos($v, '<') !== false ? self::html($v, true) : sanitize_textarea_field($v);
        }

        if (is_bool($v) || is_int($v) || $v === null) {
            return $v;
        }

        if (is_float($v)) {
            return is_finite($v) ? $v : 0;
        }

        if (is_array($v)) {
            $ki = [];

            foreach (array_slice($v, 0, 500, true) as $k => $x) {
                $kk = is_int($k) ? $k : (preg_match('/^[A-Za-z0-9_\-]{1,60}$/', (string) $k) === 1 ? (string) $k : null);

                if ($kk !== null) {
                    $ki[$kk] = self::altalanos($x, $mely + 1);
                }
            }

            return $ki;
        }

        return null;
    }

    /* =================================================================
     * Segédek
     * ============================================================== */

    /** @param array<int, array<string, mixed>> $mezok */
    private static function mezo(array $mezok, string $k): ?array
    {
        foreach ($mezok as $m) {
            if ($m['k'] === $k) {
                return $m;
            }
        }

        return null;
    }

    /** Pontozott kulcs kiolvasása („sla.munkaido”). @param array<string, mixed> $a @return mixed */
    private static function kiolvas(array $a, string $k)
    {
        if (array_key_exists($k, $a)) {
            return $a[$k];
        }

        $r = $a;

        foreach (explode('.', $k) as $d) {
            if (!is_array($r) || !array_key_exists($d, $r)) {
                return null;
            }

            $r = $r[$d];
        }

        return $r;
    }

    /** @param array<string, mixed> $a @param mixed $v */
    private static function beir(array &$a, string $k, $v): void
    {
        if ($v === null) {
            return;
        }

        $r = &$a;
        $d = explode('.', $k);

        foreach (array_slice($d, 0, -1) as $x) {
            if (!isset($r[$x]) || !is_array($r[$x])) {
                $r[$x] = [];
            }

            $r = &$r[$x];
        }

        $r[end($d)] = $v;
    }
}
