<?php
/**
 * RMA-matrica Brother címkenyomtatóra (0.59).
 *
 * Azonosító matrica a leadott készülékre: név, munkalapszám, eszköz típusa
 * és azonosítója, RMA-kód (és QR), hiba, feloldó minta és kód, IMEI,
 * dátum – és amit még bekapcsolnak. Mi kerül rá, milyen sorrendben,
 * felirattal, mekkora betűvel: mind a Beállítások › Címkenyomtatás lapfülön.
 *
 * Két nyomtatási mód:
 *  - **Böngésző** (bármelyik Brother: QL, PT, TD): a rendszer pontos
 *    címkeméretű PDF-et készít, és a böngésző nyomtatás-ablakát nyitja – a
 *    gépre telepített Brother illesztőprogram nyomtat. Ehhez a nyomtató
 *    beállításaiban a címke méretét egyszer be kell állítani.
 *  - **Hálózat** (hálózatos QL: QL-580N, 710W, 720NW, 810W, 820NWB, 1060N,
 *    1110NWB, 1115NWB): a szerver a Brother raszterparancsait közvetlenül a
 *    nyomtató 9100-as portjára küldi – nincs ablak, nincs illesztőprogram.
 *    A szervernek el kell érnie a nyomtatót (ugyanaz a hálózat).
 *
 * A rajzolás egy helyen dől el (elrendez): ugyanaz az elrendezés megy a
 * PDF-be, a nyomtatóraszterbe és a Beállítások élő előnézetébe (PNG).
 *
 * Sablonok (0.59.3): **rma** (munkalap), **termek** (termékcímke: név, ár,
 * cikkszám, vonalkód…), **szolgaltatas** (szolgáltatás-címke). A nyomtató és
 * a betett tekercs KÖZÖS (az RMA-matrica opciójában), a hossz, tájolás,
 * betű és az adatok sablononként állíthatók.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Matrica
{
    public const KULCS = 'matrica';

    public const OPT = 'sdh_muhely_matrica';

    /** A címkesablonok. */
    public const SABLONOK = ['rma' => 'RMA-matrica', 'rendeles' => 'Rendelés-címke', 'termek' => 'Termékcímke', 'szolgaltatas' => 'Szolgáltatás-címke', 'tetel' => 'Beszállítói tétel', 'egyedi' => 'Egyedi címke'];

    /** A közös beállítások (nyomtató és a betett tekercs): mindig az RMA-matrica opciójából. */
    public const KOZOS = ['nyomtato', 'mod', 'ip', 'port', 'cimke', 'egyedi_sz', 'egyedi_h', 'vagas'];

    /** A nyomtató felbontása (pont / hüvelyk). */
    private const DPI = 300;

    /** @var tFPDF|null A szövegszélesség méréséhez. */
    private static $mero = null;

    private static string $mero_betu = '';

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_matrica_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_matrica_pdf', [self::class, 'ajax_pdf']);
        add_action('wp_ajax_sdh_muhely_matrica_kep', [self::class, 'ajax_kep']);
        add_action('wp_ajax_sdh_muhely_matrica_nyomtat', [self::class, 'ajax_nyomtat']);
        add_action('wp_ajax_sdh_muhely_matrica_elonezet', [self::class, 'ajax_elonezet']);
        add_action('wp_ajax_sdh_muhely_matrica_proba', [self::class, 'ajax_proba']);

        // Címkék menüpont (0.61.1): innen nyílik az egyedi címke, és innen érhető el,
        // mit tartalmaznak a címkék – a nyomtató beállítása marad a Beállításokban.
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Címkék',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 86,
        ]);

        add_action('admin_enqueue_scripts', static function (): void {
            $oldal = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            if (str_starts_with($oldal, SDH_Muhely_Admin_UI::FOMENU)) {
                wp_enqueue_style('sdh-muhely-matrica', SDH_MUHELY_URL . 'assets/matrica.css', ['sdh-muhely-admin'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/matrica.css'));
            }
        }, 22);
    }

    /* =================================================================
     * Nyomtatók, címkék, mezők
     * ============================================================== */

    /**
     * A választható nyomtatók. `kozvetlen`: hálózaton közvetlenül is tud
     * nyomtatni (QL raszter, 9100-as port); `bajt`: egy rastersor bájtjai;
     * `eltolas`: a széles fej jobb oldali eltolása; `torles`: a kezdő
     * nullbájtok; `mod`: kell-e raszter módba kapcsolni; `vago`: van-e vágó.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function nyomtatok(): array
    {
        $ql  = static fn (string $nev, bool $halozat, int $torles = 200, bool $mod = true): array => ['nev' => $nev, 'kozvetlen' => $halozat, 'bajt' => 90, 'eltolas' => 0, 'torles' => $torles, 'mod' => $mod, 'vago' => true, 'szeles' => false];
        $qlw = static fn (string $nev, bool $halozat): array => ['nev' => $nev, 'kozvetlen' => $halozat, 'bajt' => 162, 'eltolas' => 44, 'torles' => 200, 'mod' => true, 'vago' => true, 'szeles' => true];
        $mas = static fn (string $nev): array => ['nev' => $nev, 'kozvetlen' => false, 'bajt' => 0, 'eltolas' => 0, 'torles' => 0, 'mod' => false, 'vago' => false, 'szeles' => false];

        return [
            'QL-820NWB'  => $ql('Brother QL-820NWB (hálózat, Wi-Fi, Bluetooth)', true, 400),
            'QL-810W'    => $ql('Brother QL-810W (Wi-Fi)', true, 400),
            'QL-800'     => $ql('Brother QL-800 (USB)', false, 400),
            'QL-720NW'   => $ql('Brother QL-720NW (hálózat, Wi-Fi)', true),
            'QL-710W'    => $ql('Brother QL-710W (Wi-Fi)', true),
            'QL-700'     => $ql('Brother QL-700 (USB)', false, 200, false),
            'QL-580N'    => $ql('Brother QL-580N (hálózat)', true),
            'QL-570'     => $ql('Brother QL-570 (USB)', false, 200, false),
            'QL-560'     => $ql('Brother QL-560 (USB)', false, 200, false),
            'QL-550'     => $ql('Brother QL-550 (USB)', false, 200, false),
            'QL-500'     => $ql('Brother QL-500 (USB)', false, 200, false),
            'QL-650TD'   => $ql('Brother QL-650TD (USB)', false),
            'QL-1115NWB' => $qlw('Brother QL-1115NWB (széles, hálózat)', true),
            'QL-1110NWB' => $qlw('Brother QL-1110NWB (széles, hálózat)', true),
            'QL-1100'    => $qlw('Brother QL-1100 (széles, USB)', false),
            'QL-1060N'   => $qlw('Brother QL-1060N (széles, hálózat)', true),
            'QL-1050'    => $qlw('Brother QL-1050 (széles, USB)', false),
            'PT-P950NW'  => $mas('Brother PT-P950NW (P-touch, TZe szalag)'),
            'PT-P900W'   => $mas('Brother PT-P900W (P-touch, TZe szalag)'),
            'PT-P750W'   => $mas('Brother PT-P750W (P-touch, TZe szalag)'),
            'PT-P700'    => $mas('Brother PT-P700 (P-touch, TZe szalag)'),
            'PT-E550W'   => $mas('Brother PT-E550W (P-touch, TZe szalag)'),
            'TD-4520DN'  => $mas('Brother TD-4520DN (címkenyomtató)'),
            'TD-4410D'   => $mas('Brother TD-4410D (címkenyomtató)'),
            'egyeb'      => $mas('Más Brother / más gyártó (csak böngészőből)'),
        ];
    }

    /**
     * A címkék (Brother DK-tekercsek és TZe-szalagok). `pont`: a nyomtatható
     * terület pontban [szélesség, hosszúság] (folyamatosnál csak szélesség);
     * `jobb`: a jobb margó pontban; `elotolas`: a folyamatos szalag előtolása.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cimkek(): array
    {
        $f = static fn (string $nev, int $sz, int $pont, int $jobb, bool $szeles = false): array => ['nev' => $nev, 'szel' => $sz, 'hossz' => 0, 'tipus' => 'folyamatos', 'pont' => [$pont, 0], 'jobb' => $jobb, 'elotolas' => 35, 'szeles' => $szeles, 'ql' => true];
        $s = static fn (string $nev, int $sz, int $h, int $pw, int $ph, int $jobb, bool $szeles = false): array => ['nev' => $nev, 'szel' => $sz, 'hossz' => $h, 'tipus' => 'stancolt', 'pont' => [$pw, $ph], 'jobb' => $jobb, 'elotolas' => 0, 'szeles' => $szeles, 'ql' => true];
        $t = static fn (string $nev, int $sz): array => ['nev' => $nev, 'szel' => $sz, 'hossz' => 0, 'tipus' => 'folyamatos', 'pont' => [0, 0], 'jobb' => 0, 'elotolas' => 0, 'szeles' => false, 'ql' => false];

        return [
            '62'      => $f('DK-22205 – 62 mm folyamatos', 62, 696, 12),
            '62x29'   => $s('DK-11209 – 62 × 29 mm (kis címke)', 62, 29, 696, 271, 12),
            '62x100'  => $s('DK-11202 – 62 × 100 mm (szállítási)', 62, 100, 696, 1109, 12),
            '29x90'   => $s('DK-11201 – 29 × 90 mm (címzés)', 29, 90, 306, 991, 6),
            '38x90'   => $s('DK-11208 – 38 × 90 mm (nagy címzés)', 38, 90, 413, 991, 12),
            '17x54'   => $s('DK-11204 – 17 × 54 mm (többcélú)', 17, 54, 165, 566, 0),
            '29'      => $f('DK-22210 – 29 mm folyamatos', 29, 306, 6),
            '38'      => $f('DK-22225 – 38 mm folyamatos', 38, 413, 12),
            '50'      => $f('DK-22223 – 50 mm folyamatos', 50, 554, 12),
            '54'      => $f('DK-N55224 – 54 mm folyamatos (nem öntapadó)', 54, 590, 0),
            '102'     => $f('DK-22243 – 102 mm folyamatos (széles QL)', 102, 1164, 12, true),
            '102x51'  => $s('DK-11240 – 102 × 51 mm (széles QL)', 102, 51, 1164, 526, 12, true),
            '102x152' => $s('DK-11241 – 102 × 152 mm (széles QL)', 102, 152, 1164, 1660, 12, true),
            'tze12'   => $t('TZe – 12 mm szalag (P-touch)', 12),
            'tze18'   => $t('TZe – 18 mm szalag (P-touch)', 18),
            'tze24'   => $t('TZe – 24 mm szalag (P-touch)', 24),
            'tze36'   => $t('TZe – 36 mm szalag (P-touch)', 36),
            'egyedi'  => ['nev' => 'Egyedi méret', 'szel' => 0, 'hossz' => 0, 'tipus' => 'egyedi', 'pont' => [0, 0], 'jobb' => 0, 'elotolas' => 0, 'szeles' => false, 'ql' => false],
        ];
    }

    /** Ismert sablon kulcsa (ismeretlenre: rma). */
    public static function sablon($s): string
    {
        $s = is_string($s) ? sanitize_key($s) : '';

        return isset(self::SABLONOK[$s]) ? $s : 'rma';
    }

    /** A sablon saját opciója. */
    public static function opt(string $sablon): string
    {
        $sablon = self::sablon($sablon);

        return $sablon === 'rma' ? self::OPT : self::OPT . '_' . $sablon;
    }

    /**
     * A címkére tehető adatok. RMA-matricán az első nyolc az alapértelmezett,
     * ebben a sorrendben; a többi bekapcsolható. A `vonalkod` és a `qr` rajz.
     *
     * @return array<string, array{cim: string, felirat: string, be: bool, meret: float, felkover: bool, sorok: int}>
     */
    public static function mezok(string $sablon = 'rma'): array
    {
        $m = static fn (string $cim, string $felirat, bool $be, float $meret = 1.0, bool $felkover = false, int $sorok = 1): array => compact('cim', 'felirat', 'be', 'meret', 'felkover', 'sorok');

        if (self::sablon($sablon) === 'termek') {
            return [
                'megnevezes' => $m('Megnevezés', '', true, 1.15, true, 2),
                'ar'         => $m('Bruttó ár', '', true, 1.9, true),
                'cikkszam'   => $m('Cikkszám', 'Cikksz.', true, 0.9),
                'vonalkod'   => $m('Vonalkód (a termék vonalkódja; ha nincs, a cikkszám)', '', true),
                'termekkod'  => $m('Termékkód', 'Kód', false, 0.9),
                'gyari_szam' => $m('Gyári szám', 'Gyári sz.', false, 0.9),
                'kategoria'  => $m('Kategória', '', false, 0.85),
                'netto'      => $m('Nettó ár', 'Nettó', false, 0.9),
                'me'         => $m('Mennyiségi egység', 'Egység', false, 0.85),
                'beszallito' => $m('Beszállító', 'Besz.', false, 0.85),
                'leiras'     => $m('Leírás', '', false, 0.85, false, 2),
                'szerviz'    => $m('A szerviz neve', '', false, 0.85, true),
                'datum'      => $m('Dátum (a nyomtatás napja)', '', false, 0.8),
                'qr'         => $m('QR-kód (a vonalkód / cikkszám értékével)', '', false),
            ];
        }

        if (self::sablon($sablon) === 'rendeles') {
            // Rendelés-címke (0.61.1): a megérkezett árura ragasztva látszik, kié és melyik lapra.
            // Ár alapból nincs rajta (az ügyfél árát a bizonylat viszi), de bekapcsolható.
            return [
                'nev'          => $m('Az ügyfél neve', '', true, 1.3, true),
                'munkalapszam' => $m('Lapszám', 'Rend.', true, 1.45, true),
                'telefon'      => $m('Az ügyfél telefonszáma', 'Tel', true, 1.0),
                'eszkoz'       => $m('Eszköz / termék megnevezése', '', true, 1.0, false, 2),
                'tetelek'      => $m('A rendelés tételei', '', true, 0.9, false, 3),
                'datum'        => $m('Dátum (felvétel napja)', '', true, 0.85),
                'hatarido'     => $m('Várható érkezés', 'Várható', false),
                'beszallito'   => $m('Beszállító', 'Beszállító', false, 0.9),
                'rend_szam'    => $m('A beszállítói rendelés száma', 'Rend.sz.', false, 0.9),
                'elolog'       => $m('Fizetett előleg', 'Előleg', false, 0.9),
                'brutto'       => $m('Bruttó végösszeg', 'Összesen', false, 1.0, true),
                'megjegyzes'   => $m('Megjegyzés (a lapról)', 'Megj', false, 0.9, false, 2),
                'szerviz'      => $m('A szerviz neve', '', false, 0.85, true),
                'vonalkod'     => $m('Vonalkód (lapszám)', '', false),
                'qr'           => $m('QR-kód az ügyféloldalra', '', false),
            ];
        }

        if (self::sablon($sablon) === 'tetel') {
            // Beszállítói tétel (0.61.0): pontosan az, ami a beszállító levelében áll a
            // tételről – ÁR NÉLKÜL, mert az nem az ügyfélre tartozik.
            return [
                'keszulek'   => $m('Készülék (amire való)', '', true, 0.9, false, 2),
                'megnevezes' => $m('Megnevezés', '', true, 1.15, true, 3),
                'azonosito'  => $m('Azonosító', 'Azonosító', true, 0.9),
                'garancia'   => $m('Garancia', 'Garancia', true, 0.9),
                'cikkszam'   => $m('Cikkszám', 'Cikkszám', true, 0.9),
                'megjegyzes' => $m('Megjegyzés (ebben áll a telefonszám)', 'Megj', true, 0.95, false, 2),
                'telefon'    => $m('Csak a telefonszám, külön sorban', 'Tel', false, 1.1, true),
                'datum'      => $m('Dátum (a nyomtatás napja)', '', false, 0.8),
                'szerviz'    => $m('A szerviz neve', '', false, 0.85, true),
                'vonalkod'   => $m('Vonalkód (a cikkszámból)', '', false),
                'qr'         => $m('QR-kód (a cikkszámból)', '', false),
            ];
        }

        if (self::sablon($sablon) === 'egyedi') {
            // Egyedi címke: a szöveget a nyomtatás ablakában írod be, nem rekordból jön.
            return [
                'sor1'     => $m('1. sor (nagy)', '', true, 1.6, true, 2),
                'sor2'     => $m('2. sor', '', true, 1.1, false, 2),
                'sor3'     => $m('3. sor', '', true, 1.0, false, 2),
                'sor4'     => $m('4. sor (apró)', '', true, 0.85, false, 2),
                'datum'    => $m('Dátum (a nyomtatás napja)', '', false, 0.8),
                'szerviz'  => $m('A szerviz neve', '', false, 0.85, true),
                'vonalkod' => $m('Vonalkód (az 1. sor értékéből)', '', false),
                'qr'       => $m('QR-kód (az 1. sor értékéből)', '', false),
            ];
        }

        if (self::sablon($sablon) === 'szolgaltatas') {
            return [
                'nev'        => $m('Megnevezés', '', true, 1.15, true, 2),
                'ar'         => $m('Bruttó ár (ársávnál: tól–ig)', '', true, 1.9, true),
                'kategoria'  => $m('Kategória', '', true, 0.85),
                'kod'        => $m('Kód', 'Kód', false, 0.9),
                'netto'      => $m('Nettó ár', 'Nettó', false, 0.9),
                'me'         => $m('Mennyiségi egység', 'Egység', false, 0.85),
                'megjegyzes' => $m('Megjegyzés', '', false, 0.85, false, 2),
                'szerviz'    => $m('A szerviz neve', '', false, 0.85, true),
                'datum'      => $m('Dátum (a nyomtatás napja)', '', false, 0.8),
                'vonalkod'   => $m('Vonalkód (a kódból; ha nincs, az azonosítóból)', '', false),
                'qr'         => $m('QR-kód (a kód értékével)', '', false),
            ];
        }

        return [
            'nev'          => $m('Név (az ügyfélé)', '', true, 1.15, true),
            'munkalapszam' => $m('Munkalapszám', 'ML', true, 1.45, true),
            'eszkoz'       => $m('Eszköz típusa és azonosítója (modellszám)', '', true, 1.0, false, 2),
            'rma'          => $m('RMA-kód (és QR-kód az ügyféloldalra)', 'RMA', true),
            'hiba'         => $m('Hiba', 'Hiba', true, 1.0, false, 3),
            'feloldas'     => $m('Feloldó minta és feloldó kód', 'Kód', true),
            'imei'         => $m('IMEI és azonosító (sorozatszám / IMEI 2)', 'IMEI', true, 0.95),
            'datum'        => $m('Dátum (felvétel napja)', '', true, 0.9),
            'telefon'      => $m('Az ügyfél telefonszáma', 'Tel', false),
            'szerviz'      => $m('A szerviz neve', '', false, 0.9, true),
            'hatarido'     => $m('Határidő', 'Határidő', false),
            'tartozekok'   => $m('Tartozékok', 'Tartozék', false, 0.9, false, 2),
            'szin'         => $m('Az eszköz színe', 'Szín', false),
            'allapot'      => $m('Átvételi állapot', 'Állapot', false, 0.9, false, 2),
            'megjegyzes'   => $m('Megjegyzés (a lapról)', 'Megj', false, 0.9, false, 2),
            'darab'        => $m('Hányadik eszköz a lapon (pl. 2/3)', '', false, 0.9),
            'vonalkod'     => $m('Vonalkód (munkalapszám)', '', false),
            'qr'           => $m('QR-kód az ügyféloldalra (külön, az RMA nélkül is)', '', false),
        ];
    }

    /** @return array<string, mixed> */
    public static function alapok(string $sablon = 'rma'): array
    {
        $sablon = self::sablon($sablon);
        $mezok  = [];

        foreach (self::mezok($sablon) as $k => $m) {
            $mezok[$k] = ['be' => $m['be'], 'felirat' => $m['felirat'], 'meret' => $m['meret'], 'felkover' => $m['felkover']];
        }

        return [
            'nyomtato'     => 'QL-820NWB',
            'mod'          => 'bongeszo',
            'ip'           => '',
            'port'         => 9100,
            'cimke'        => '62',
            'egyedi_sz'    => 62,
            'egyedi_h'     => 40,
            'hossz'        => $sablon === 'rma' ? 45 : 30,
            'tajolas'      => 'allo',
            'margo'        => 1.5,
            'betu'         => 'dejavu',
            'meret'        => 8.5,
            'peldany'      => 1,
            'eszkozonkent' => true,
            'vagas'        => true,
            'keret'        => false,
            'rma_mod'      => 'mindketto',
            'mezok'        => $mezok,
            'sablon'       => $sablon,
        ];
    }

    /**
     * A mentett beállítás az alapértékekkel kiegészítve. Termék- és
     * szolgáltatás-címkénél a nyomtató és a tekercs az RMA-matricáé.
     *
     * @return array<string, mixed>
     */
    public static function beallitas(string $sablon = 'rma'): array
    {
        $sablon = self::sablon($sablon);
        $m = get_option(self::opt($sablon), []);
        $b = self::tisztit(is_array($m) ? $m : [], $sablon);

        if ($sablon !== 'rma') {
            $kozos = self::beallitas('rma');

            foreach (self::KOZOS as $k) {
                $b[$k] = $kozos[$k];
            }
        }

        return $b;
    }

    /**
     * Beküldött / mentett beállítás megtisztítása.
     *
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    public static function tisztit(array $m, string $sablon = 'rma'): array
    {
        $a = self::alapok($sablon);
        $b = $a;

        $b['nyomtato']  = isset(self::nyomtatok()[(string) ($m['nyomtato'] ?? '')]) ? (string) $m['nyomtato'] : $a['nyomtato'];
        $b['mod']       = in_array((string) ($m['mod'] ?? ''), ['bongeszo', 'halozat'], true) ? (string) $m['mod'] : $a['mod'];
        $ip             = trim((string) ($m['ip'] ?? ''));
        $b['ip']        = $ip === '' || filter_var($ip, FILTER_VALIDATE_IP) || preg_match('/^[a-z0-9]([a-z0-9.-]{0,250}[a-z0-9])?$/i', $ip) === 1 ? $ip : '';
        $b['port']      = min(65535, max(1, (int) ($m['port'] ?? 9100)));
        $b['cimke']     = isset(self::cimkek()[(string) ($m['cimke'] ?? '')]) ? (string) $m['cimke'] : $a['cimke'];
        $b['egyedi_sz'] = min(200, max(10, (float) ($m['egyedi_sz'] ?? $a['egyedi_sz'])));
        $b['egyedi_h']  = min(300, max(10, (float) ($m['egyedi_h'] ?? $a['egyedi_h'])));
        $b['hossz']     = min(300, max(15, (float) ($m['hossz'] ?? $a['hossz'])));
        $b['tajolas']   = ($m['tajolas'] ?? '') === 'fekvo' ? 'fekvo' : 'allo';
        $b['margo']     = min(8, max(0, (float) ($m['margo'] ?? $a['margo'])));
        $b['betu']      = class_exists('SDH_Muhely_Nyomtatvany_Pdf') && isset(SDH_Muhely_Nyomtatvany_Pdf::BETUK[(string) ($m['betu'] ?? '')]) ? (string) $m['betu'] : (in_array((string) ($m['betu'] ?? ''), ['dejavu', 'liberation-sans', 'carlito', 'liberation-serif'], true) ? (string) $m['betu'] : 'dejavu');
        $b['meret']     = min(16, max(5, (float) ($m['meret'] ?? $a['meret'])));
        $b['peldany']   = min(50, max(1, (int) ($m['peldany'] ?? 1)));
        $b['rma_mod']   = in_array((string) ($m['rma_mod'] ?? ''), ['kod', 'qr', 'mindketto'], true) ? (string) $m['rma_mod'] : $a['rma_mod'];

        foreach (['eszkozonkent', 'vagas', 'keret'] as $k) {
            $b[$k] = array_key_exists($k, $m) ? !empty($m[$k]) : $a[$k];
        }

        // A mezők: a mentett sorrendben, a hiányzók (új mezők) a végén.
        $kert  = is_array($m['mezok'] ?? null) ? $m['mezok'] : [];
        $sorr  = is_array($m['sorrend'] ?? null) ? array_values(array_filter(array_map('sanitize_key', $m['sorrend']), static fn ($k) => isset($a['mezok'][$k]))) : array_keys(array_intersect_key($kert, $a['mezok']));
        $sorr  = array_values(array_unique(array_merge($sorr, array_keys($a['mezok']))));
        $mezok = [];
        // Az űrlap minden sort beküld (a sorrenddel együtt): ami ott hiányzik, az nincs bepipálva.
        $urlapbol = is_array($m['sorrend'] ?? null);

        foreach ($sorr as $k) {
            $al = $a['mezok'][$k];
            $kb = is_array($kert[$k] ?? null) ? $kert[$k] : ($urlapbol ? ['be' => false, 'felirat' => $al['felirat'], 'meret' => $al['meret'], 'felkover' => $al['felkover']] : null);

            $mezok[$k] = $kb === null ? $al : [
                'be'       => !empty($kb['be']),
                'felirat'  => mb_substr(sanitize_text_field((string) ($kb['felirat'] ?? '')), 0, 20),
                'meret'    => min(3.0, max(0.5, (float) ($kb['meret'] ?? $al['meret']))),
                'felkover' => !empty($kb['felkover']),
            ];
        }

        $b['mezok'] = $mezok;

        return $b;
    }

    /**
     * A címke fizikai mérete és a nyomtatható pontjai a beállításból.
     *
     * @param array<string, mixed> $b
     * @return array{szel: float, hossz: float, tipus: string, pont: array{0: int, 1: int}, jobb: int, elotolas: int, ql: bool, szeles: bool, nev: string}
     */
    public static function meret(array $b): array
    {
        $c = self::cimkek()[(string) $b['cimke']] ?? self::cimkek()['62'];
        $dpmm = self::DPI / 25.4;

        if ($c['tipus'] === 'egyedi') {
            $c['szel']  = (float) $b['egyedi_sz'];
            $c['hossz'] = (float) $b['egyedi_h'];
            $c['pont']  = [(int) round($c['szel'] * $dpmm), (int) round($c['hossz'] * $dpmm)];
        } elseif ($c['tipus'] === 'folyamatos') {
            $c['hossz'] = (float) $b['hossz'];

            if ($c['pont'][0] <= 0) {
                $c['pont'][0] = (int) round(($c['szel'] - 3) * $dpmm);
            }

            $c['pont'][1] = (int) round($c['hossz'] * $dpmm);
        }

        return $c;
    }

    /**
     * A címke neve a Brother illesztőprogram papírméret-listájában
     * (pl. „62mm”, „29mm x 90mm”) – ezt kell választani a nyomtatás-ablakban.
     */
    public static function papir_nev(array $b): string
    {
        $c = self::meret($b);
        $mm = static fn (float $v): string => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . 'mm';

        if ($c['tipus'] === 'stancolt' || $c['tipus'] === 'egyedi') {
            return $mm((float) $c['szel']) . ' x ' . $mm((float) $c['hossz']);
        }

        return $mm((float) $c['szel']);
    }

    /** Közvetlenül (hálózaton) nyomtatható-e ezzel a beállítással; ha nem, az ok. */
    public static function kozvetlen_hiba(array $b): ?string
    {
        $ny = self::nyomtatok()[(string) $b['nyomtato']] ?? null;
        $c  = self::cimkek()[(string) $b['cimke']] ?? null;

        if ($ny === null || empty($ny['kozvetlen'])) {
            return 'Ez a nyomtató nem tud közvetlenül hálózaton nyomtatni – állítsd a módot „Böngésző”-re (a Brother illesztőprogrammal).';
        }

        if ($c === null || empty($c['ql'])) {
            return 'Ez a címke (TZe / egyedi) csak böngészőből nyomtatható.';
        }

        if (!empty($c['szeles']) && empty($ny['szeles'])) {
            return 'A 102 mm-es címke csak a széles QL-1050/1060N/1100/1110NWB/1115NWB nyomtatókon fér el.';
        }

        if ((string) $b['ip'] === '') {
            return 'Add meg a nyomtató hálózati címét (IP) a Beállítások › Címkenyomtatás lapfülön.';
        }

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            return 'A szerver PHP-jából hiányzik a GD (FreeType) bővítmény – a közvetlen nyomtatáshoz kell. Böngészőből nyomtass.';
        }

        return null;
    }

    /* =================================================================
     * Az adatok
     * ============================================================== */

    /**
     * Egy munkalap matricáinak adatai: eszközönként egy (a beállítás szerint),
     * kulcs = az eszköz kulcsa a lapon (0 = a fő eszköz).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adatok(int $munkalap_id): array
    {
        global $wpdb;

        $ml = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $munkalap_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (!$ml) {
            return [];
        }

        $ugyfel = (int) $ml->ugyfel_id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', (int) $ml->ugyfel_id)) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            : null;

        $rma_be = class_exists('SDH_Muhely_Rma') && SDH_Muhely_Modulok::be('uzenetek');
        $token  = $rma_be ? SDH_Muhely_Rma::token($ml) : '';
        $url    = $token !== '' ? SDH_Muhely_Rma::url($token) : '';
        $szerviz = self::szerviz_nev();

        $bontas = SDH_Muhely_Munkalap::eszkoz_bontas($ml);

        if ($bontas === []) {
            $bontas = [['eszkoz_id' => 0, 'eszkoz' => null, 'hibak' => SDH_Muhely_Munkalap::hibasorok($munkalap_id), 'sorszam' => 1]];
        }

        $db = count($bontas);
        $ki = [];

        foreach (array_values($bontas) as $i => $e) {
            $esz = is_object($e['eszkoz'] ?? null) ? $e['eszkoz'] : null;
            $hibak = array_values(array_filter(array_map(static fn ($h) => trim((string) ($h->leiras ?? '')), (array) ($e['hibak'] ?? []))));

            $ki[(int) $e['eszkoz_id']] = [
                'nev'          => $ugyfel ? (string) $ugyfel->nev : (string) $ml->nev,
                'munkalapszam' => SDH_Muhely_Munkalap::szam_formaz($ml->munkalap_szam),
                'szam_nyers'   => (string) (int) $ml->munkalap_szam,
                'eszkoz'       => $esz ? trim(SDH_Muhely_Eszkoz::megnevezes($esz) . ((string) $esz->modell_szam !== '' ? ' · ' . $esz->modell_szam : '')) : '',
                'rma'          => $token !== '' ? strtoupper(substr($token, 0, 4) . '-' . substr($token, 4, 4)) : '',
                'url'          => $url,
                'hiba'         => $hibak !== [] ? implode(', ', $hibak) : (string) $ml->nev,
                'kod'          => $esz ? (string) $esz->zarkod : '',
                'minta'        => $esz ? preg_replace('/[^1-9]/', '', (string) $esz->minta) : '',
                'imei'         => $esz ? trim((string) $esz->imei . ((string) $esz->sorozatszam !== '' ? ' · SN ' . $esz->sorozatszam : ((string) $esz->imei2 !== '' ? ' · ' . $esz->imei2 : ''))) : '',
                'datum'        => SDH_Muhely_Munkalap::datum_megjelenit((string) ($ml->keszult ?: current_time('Y-m-d'))),
                'telefon'      => $ugyfel ? (string) $ugyfel->telefon : '',
                'szerviz'      => $szerviz,
                'hatarido'     => (string) $ml->hatarido !== '' ? SDH_Muhely_Munkalap::datum_megjelenit((string) $ml->hatarido) : '',
                'tartozekok'   => $esz ? (string) $esz->tartozekok : '',
                'szin'         => $esz ? (string) $esz->szin : '',
                'allapot'      => $esz ? (string) $esz->atveteli_allapot : '',
                'megjegyzes'   => (string) ($ml->ugyfel_megjegyzes ?? ''),
                'darab'        => $db > 1 ? ($i + 1) . '/' . $db : '',
            ];
        }

        // „Eszközönként külön matrica" kikapcsolva: csak a fő eszközé (sorszám nélkül).
        if (!self::beallitas()['eszkozonkent'] && count($ki) > 1) {
            $ki = array_slice($ki, 0, 1, true);
            $ki[array_key_first($ki)]['darab'] = '';
        }

        return $ki;
    }

    private static function szerviz_nev(): string
    {
        return class_exists('SDH_Muhely_Rma') ? (string) SDH_Muhely_Rma::beallitas()['szerviz_nev'] : (string) get_bloginfo('name');
    }

    private static function ma(): string
    {
        return class_exists('SDH_Muhely_Munkalap') ? SDH_Muhely_Munkalap::datum_megjelenit(current_time('Y-m-d')) : current_time('Y. m. d.');
    }

    /** Nettó ár a bruttóból (az áfakulcs lehet szöveg is, pl. AAM: akkor 0%). */
    private static function netto(float $brutto, string $afa): float
    {
        $k = is_numeric(str_replace(',', '.', $afa)) ? (float) str_replace(',', '.', $afa) : 0.0;

        return $brutto / (1 + max(0.0, $k) / 100);
    }

    private static function ft(float $osszeg): string
    {
        return number_format(round($osszeg), 0, ',', ' ') . ' Ft';
    }

    /**
     * A sablon címkéinek adatai: RMA-matricánál a lap eszközei, termék- és
     * szolgáltatás-címkénél egyetlen címke (kulcs 0). Üres, ha nincs ilyen.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lista(string $sablon, int $id): array
    {
        $sablon = self::sablon($sablon);

        if ($sablon === 'termek') {
            $a = self::termek_adat($id);

            return $a === null ? [] : [0 => $a];
        }

        if ($sablon === 'szolgaltatas') {
            $a = self::szolgaltatas_adat($id);

            return $a === null ? [] : [0 => $a];
        }

        if ($sablon === 'egyedi') {
            return [0 => self::egyedi_adat()];
        }

        if ($sablon === 'rendeles') {
            $a = self::rendeles_adat($id);

            return $a === null ? [] : [0 => $a];
        }

        if ($sablon === 'tetel') {
            return self::tetel_adatok($id);
        }

        return self::adatok($id);
    }

    /** Egy termék címkéjének adatai. @return array<string, mixed>|null */
    public static function termek_adat(int $id): ?array
    {
        global $wpdb;

        $t = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('termek') . ' WHERE id = %d', $id)) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (!$t) {
            return null;
        }

        $kod = trim((string) $t->vonalkod) !== '' ? trim((string) $t->vonalkod) : trim((string) $t->cikkszam);
        $br  = (float) $t->brutto_ar;

        return [
            'cim'            => (string) $t->megnevezes,
            'megnevezes'     => (string) $t->megnevezes,
            'ar'             => $br > 0 ? self::ft($br) : '',
            'cikkszam'       => (string) $t->cikkszam,
            'termekkod'      => (string) $t->termekkod,
            'gyari_szam'     => (string) $t->gyari_szam,
            'kategoria'      => (string) $t->kategoria,
            'netto'          => $br > 0 ? self::ft(self::netto($br, (string) $t->afa_kulcs)) : '',
            'me'             => (string) $t->me,
            'beszallito'     => (string) $t->beszallito,
            'leiras'         => trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $t->leiras)) ?? ''),
            'szerviz'        => self::szerviz_nev(),
            'datum'          => self::ma(),
            'vonalkod_ertek' => $kod,
            'url'            => $kod,
            'szam_nyers'     => (string) (int) $t->id,
            'darab'          => '',
        ];
    }

    /** Egy szolgáltatás címkéjének adatai. @return array<string, mixed>|null */
    /**
     * Egyedi címke adatai: a szöveget a nyomtatás ablakában írod be (nem rekordból jön).
     * Négy sor, soronként legfeljebb 120 karakter – ennél hosszabbat a címke úgysem bír el.
     *
     * @return array<string, mixed>
     */
    public static function egyedi_adat(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a hívó ellenőrizte.
        $nyers = isset($_REQUEST['sor']) && is_array($_REQUEST['sor']) ? array_values(wp_unslash($_REQUEST['sor'])) : [];
        $sorok = [];

        for ($i = 1; $i <= 4; $i++) {
            $ertek = sanitize_text_field((string) ($nyers[$i - 1] ?? ''));
            $sorok['sor' . $i] = mb_substr(trim(preg_replace('/\s+/u', ' ', $ertek) ?? ''), 0, 120);
        }

        $elso = $sorok['sor1'] !== '' ? $sorok['sor1'] : 'Címke';

        return $sorok + [
            'cim'            => $elso,
            'szerviz'        => self::szerviz_nev(),
            'datum'          => self::ma(),
            'vonalkod_ertek' => $elso,
            'url'            => $elso,
            'szam_nyers'     => 'egyedi',
            'darab'          => '',
        ];
    }

    /**
     * Egy rendelés címkéjének adatai (0.61.1). A megérkezett árura ragasztva
     * ettől látszik, kinek jött és melyik lapra tartozik.
     *
     * @return array<string, mixed>|null
     */
    public static function rendeles_adat(int $id): ?array
    {
        global $wpdb;

        $m = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $id)) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (!$m) {
            return null;
        }

        $ugyfel = (int) $m->ugyfel_id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', (int) $m->ugyfel_id)) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            : null;

        $eszkoz = (int) $m->eszkoz_id > 0 && class_exists('SDH_Muhely_Eszkoz')
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' WHERE id = %d', (int) $m->eszkoz_id)) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            : null;

        $szam   = SDH_Muhely_Munkalap::szam_formaz($m->munkalap_szam);
        $elolog = (float) $m->fizetett;
        $brutto = (float) $m->brutto_ertek;

        $besz = (int) $m->beszallito_id > 0 && class_exists('SDH_Muhely_Eladas')
            ? (string) (SDH_Muhely_Eladas::torzs_nevek('beszallitok')[(int) $m->beszallito_id] ?? '')
            : '';

        $token = class_exists('SDH_Muhely_Rma') && SDH_Muhely_Modulok::be('uzenetek') ? SDH_Muhely_Rma::token($m) : '';

        return [
            'cim'          => $ugyfel ? (string) $ugyfel->nev : (string) $m->nev,
            'nev'          => $ugyfel ? (string) $ugyfel->nev : (string) $m->nev,
            'munkalapszam' => $szam === '—' ? '' : $szam,
            'szam_nyers'   => (string) (int) $m->munkalap_szam,
            'telefon'      => $ugyfel ? (string) $ugyfel->telefon : '',
            'eszkoz'       => $eszkoz ? trim(SDH_Muhely_Eszkoz::megnevezes($eszkoz)) : (string) $m->nev,
            'tetelek'      => class_exists('SDH_Muhely_Eladas') ? SDH_Muhely_Eladas::tetel_szoveg($id, 4) : '',
            'datum'        => SDH_Muhely_Munkalap::datum_megjelenit((string) $m->keszult),
            'hatarido'     => (string) $m->hatarido !== '' && !str_starts_with((string) $m->hatarido, '0000')
                ? SDH_Muhely_Munkalap::datum_megjelenit((string) $m->hatarido)
                : '',
            'beszallito'   => $besz,
            'rend_szam'    => (string) $m->rend_szam,
            'elolog'       => $elolog > 0.5 ? self::ft($elolog) : '',
            'brutto'       => $brutto > 0.5 ? self::ft($brutto) : '',
            'megjegyzes'   => trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) ($m->megjegyzes ?? '')))),
            'szerviz'      => self::szerviz_nev(),
            'vonalkod_ertek' => (string) (int) $m->munkalap_szam,
            'url'          => $token !== '' && class_exists('SDH_Muhely_Rma') ? SDH_Muhely_Rma::url($token) : '',
            'darab'        => '',
        ];
    }

    /**
     * Egy levél-feladat tételei címkeadatként (0.61.0). A beszállítói levélből
     * az ügynök már kiolvasta őket; itt csak a címke mezőire fordítjuk át.
     * ÁR NEM kerül a címkére – az nem az ügyfélre tartozik.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tetel_adatok(int $feladat_id): array
    {
        if (!class_exists('SDH_Muhely_Level_Feladat')) {
            return [];
        }

        $f = SDH_Muhely_Level_Feladat::feladat($feladat_id);

        if ($f === null) {
            return [];
        }

        $ki = [];

        foreach (SDH_Muhely_Level_Feladat::tetelek($f) as $i => $t) {
            $cikkszam = (string) ($t['cikkszam'] ?? '');
            $kod      = $cikkszam !== '' ? $cikkszam : (string) ($t['azonosito'] ?? '');

            $ki[$i] = [
                'cim'            => (string) ($t['cim'] ?? ($t['megnevezes'] ?? '')),
                'keszulek'       => (string) ($t['keszulek'] ?? ''),
                'megnevezes'     => (string) ($t['megnevezes'] ?? ''),
                'azonosito'      => (string) ($t['azonosito'] ?? ''),
                'garancia'       => (string) ($t['garancia'] ?? ''),
                'cikkszam'       => $cikkszam,
                'megjegyzes'     => (string) ($t['megjegyzes'] ?? ''),
                'telefon'        => (string) ($t['telefon'] ?? ''),
                'szerviz'        => self::szerviz_nev(),
                'datum'          => self::ma(),
                'vonalkod_ertek' => $kod,
                'url'            => $kod,
                'szam_nyers'     => $kod !== '' ? $kod : 'tetel',
                'darab'          => '',
            ];
        }

        return $ki;
    }

    public static function szolgaltatas_adat(int $id): ?array
    {
        global $wpdb;

        $sz = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('szolgaltatas') . ' WHERE id = %d', $id)) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (!$sz) {
            return null;
        }

        $br  = (float) $sz->brutto_ar;
        $max = (float) $sz->ar_max;
        $kod = trim((string) $sz->kod) !== '' ? trim((string) $sz->kod) : 'SZ' . (int) $sz->id;

        if ($max > $br && $br > 0) {
            $ar = number_format(round($br), 0, ',', ' ') . ' – ' . self::ft($max);
        } else {
            $ar = $br > 0 ? self::ft($br) : '';
        }

        return [
            'cim'            => (string) $sz->nev,
            'nev'            => (string) $sz->nev,
            'ar'             => $ar,
            'kategoria'      => (string) $sz->kategoria,
            'kod'            => (string) $sz->kod,
            'netto'          => $br > 0 ? self::ft(self::netto($br, (string) $sz->afa_kulcs)) : '',
            'me'             => (string) $sz->me,
            'megjegyzes'     => trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) ($sz->megjegyzes ?? ''))) ?? ''),
            'szerviz'        => self::szerviz_nev(),
            'datum'          => self::ma(),
            'vonalkod_ertek' => $kod,
            'url'            => $kod,
            'szam_nyers'     => (string) (int) $sz->id,
            'darab'          => '',
        ];
    }

    /** Kitalált adatok az előnézethez és a próbanyomathoz. @return array<string, mixed> */
    public static function minta_adat(string $sablon = 'rma'): array
    {
        $sablon = self::sablon($sablon);

        if ($sablon === 'termek') {
            return [
                'cim' => 'Minta kijelző', 'megnevezes' => 'Minta kijelző, fekete (OLED)', 'ar' => '24 990 Ft', 'cikkszam' => 'MK-1234',
                'termekkod' => 'K-5678', 'gyari_szam' => 'GY-0001', 'kategoria' => 'Kijelzők', 'netto' => '19 677 Ft', 'me' => 'db',
                'beszallito' => 'Minta Kft.', 'leiras' => 'Gyári minőségű csere-kijelző kerettel', 'szerviz' => 'Minta Szerviz',
                'datum' => self::ma(), 'vonalkod_ertek' => '5998877665544', 'url' => '5998877665544', 'szam_nyers' => '1', 'darab' => '',
            ];
        }

        if ($sablon === 'rendeles') {
            return [
                'cim' => 'Minta Márta', 'nev' => 'Minta Márta', 'munkalapszam' => '000123', 'szam_nyers' => '123',
                'telefon' => '+36 30 123 4567', 'eszkoz' => 'Samsung Galaxy A17 · SM-A175B',
                'tetelek' => 'Kijelző (1 db), Akkumulátor (1 db)', 'datum' => self::ma(),
                'hatarido' => '2026. 10. 20.', 'beszallito' => 'Minta Kft.', 'rend_szam' => 'R-2026-0042',
                'elolog' => '5 000 Ft', 'brutto' => '24 990 Ft', 'megjegyzes' => 'Telefonon értesíteni',
                'szerviz' => 'Minta Szerviz', 'vonalkod_ertek' => '123', 'url' => home_url('/rma/3f9a12c4minta/'), 'darab' => '',
            ];
        }

        if ($sablon === 'tetel') {
            return [
                'cim' => 'Minta tétel', 'keszulek' => 'Samsung Galaxy Tab A11 WIFI (SM-X130)',
                'megnevezes' => 'Tok álló, bőr hatású (aktív FLIP, oldalra nyíló, TRIFOLD, asztali tartó) PIROS',
                'azonosito' => 'GP-177299', 'garancia' => 'Szavatosság', 'cikkszam' => '177299',
                'megjegyzes' => '8000Fizetve, 06302121235', 'telefon' => '06302121235',
                'szerviz' => 'Minta Szerviz', 'datum' => self::ma(), 'vonalkod_ertek' => '177299',
                'url' => '177299', 'szam_nyers' => '177299', 'darab' => '',
            ];
        }

        if ($sablon === 'egyedi') {
            return [
                'cim' => 'Egyedi címke', 'sor1' => 'Bontott alkatrész', 'sor2' => 'Samsung A50 – alaplap',
                'sor3' => 'Ügyfél: Minta Márta', 'sor4' => 'Nem adható ki!', 'szerviz' => 'Minta Szerviz',
                'datum' => self::ma(), 'vonalkod_ertek' => 'Bontott alkatresz', 'url' => 'Bontott alkatresz',
                'szam_nyers' => 'egyedi', 'darab' => '',
            ];
        }

        if ($sablon === 'szolgaltatas') {
            return [
                'cim' => 'Minta szolgáltatás', 'nev' => 'Kijelzőcsere (minta)', 'ar' => '12 000 – 18 000 Ft', 'kategoria' => 'Javítás',
                'kod' => 'KCS', 'netto' => '9 449 Ft', 'me' => 'db', 'megjegyzes' => 'Az ár a készüléktől függ', 'szerviz' => 'Minta Szerviz',
                'datum' => self::ma(), 'vonalkod_ertek' => 'KCS', 'url' => 'KCS', 'szam_nyers' => '1', 'darab' => '',
            ];
        }

        return [
            'nev' => 'Minta Márta', 'munkalapszam' => '000123', 'szam_nyers' => '123', 'eszkoz' => 'Samsung Galaxy A17 · SM-A175B',
            'rma' => '3F9A-12C4', 'url' => home_url('/rma/3f9a12c4minta/'), 'hiba' => 'Törött kijelző, nem töltődik',
            'kod' => '1234', 'minta' => '14789', 'imei' => '356789012345678 · SN R58N12ABCDE', 'datum' => current_time('Y. m. d.'),
            'telefon' => '+36 30 123 4567', 'szerviz' => 'Minta Szerviz', 'hatarido' => '2026. 10. 20.', 'tartozekok' => 'tok, töltő',
            'szin' => 'fekete', 'allapot' => 'karcos hátlap', 'megjegyzes' => 'Időpontra hozza', 'darab' => '1/2',
        ];
    }

    /* =================================================================
     * Elrendezés (mm-ben) – közös a PDF-nek, a rasznernek és az előnézetnek
     * ============================================================== */

    /** A szövegszélesség-mérő (tFPDF a csomagolt betűvel). */
    private static function mero(string $betu)
    {
        if (self::$mero === null || self::$mero_betu !== $betu) {
            self::tfpdf_betolt();

            $f = self::betu_fajlok($betu);
            $p = new tFPDF('P', 'mm', 'A4');
            $p->AddFont('F', '', $f[0], true);
            $p->AddFont('F', 'B', $f[1], true);
            self::$mero = $p;
            self::$mero_betu = $betu;
        }

        return self::$mero;
    }

    private static function tfpdf_betolt(): void
    {
        if (!class_exists('TTFontFile')) {
            require_once SDH_MUHELY_DIR . 'includes/lib/tfpdf/font/unifont/ttfonts.php';
        }

        if (!class_exists('tFPDF')) {
            require_once SDH_MUHELY_DIR . 'includes/lib/tfpdf/tfpdf.php';
        }
    }

    /** @return array{0: string, 1: string} A normál és a félkövér TTF fájlneve. */
    private static function betu_fajlok(string $betu): array
    {
        $l = [
            'dejavu'           => ['DejaVuSansCondensed.ttf', 'DejaVuSansCondensed-Bold.ttf'],
            'liberation-sans'  => ['LiberationSans-Regular.ttf', 'LiberationSans-Bold.ttf'],
            'carlito'          => ['Carlito-Regular.ttf', 'Carlito-Bold.ttf'],
            'liberation-serif' => ['LiberationSerif-Regular.ttf', 'LiberationSerif-Bold.ttf'],
        ];

        return $l[$betu] ?? $l['dejavu'];
    }

    private static function szel(string $szoveg, float $pt, bool $felkover, string $betu): float
    {
        $p = self::mero($betu);
        $p->SetFont('F', $felkover ? 'B' : '', $pt);

        return (float) $p->GetStringWidth($szoveg);
    }

    /** Az utolsó tordel() hívás csonkolt-e (nem fért ki minden). */
    private static bool $csonkolt = false;

    /**
     * Szöveg tördelése a megadott szélességre: az első sor elején a felirat
     * (félkövér). Visszaadja a sorokat futásokként [szöveg, félkövér].
     *
     * @return array<int, array<int, array{0: string, 1: bool}>>
     */
    private static function tordel(string $felirat, string $ertek, float $w, float $pt, bool $felkover, int $max_sor, string $betu): array
    {
        $elo   = $felirat !== '' ? $felirat . ': ' : '';
        $elo_w = $elo !== '' ? self::szel($elo, $pt, true, $betu) : 0.0;
        $szavak = preg_split('/\s+/u', trim($ertek)) ?: [];
        $sorok = [];
        $akt   = '';
        $kezd_w = $elo_w;

        foreach ($szavak as $szo) {
            $proba = $akt === '' ? $szo : $akt . ' ' . $szo;

            if (self::szel($proba, $pt, $felkover, $betu) + ($sorok === [] ? $kezd_w : 0.0) <= $w || $akt === '') {
                // Túl hosszú egyetlen szó: betűnként vágjuk.
                if ($akt === '' && self::szel($szo, $pt, $felkover, $betu) + ($sorok === [] ? $kezd_w : 0.0) > $w) {
                    $resz = '';

                    foreach (preg_split('//u', $szo, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $kar) {
                        if ($resz !== '' && self::szel($resz . $kar, $pt, $felkover, $betu) + ($sorok === [] ? $kezd_w : 0.0) > $w) {
                            $sorok[] = $resz;
                            $resz = '';
                        }

                        $resz .= $kar;
                    }

                    $akt = $resz;
                    continue;
                }

                $akt = $proba;
                continue;
            }

            $sorok[] = $akt;
            $akt = $szo;
        }

        if ($akt !== '') {
            $sorok[] = $akt;
        }

        if ($sorok === []) {
            $sorok = [''];
        }

        self::$csonkolt = count($sorok) > $max_sor;

        if (count($sorok) > $max_sor) {
            $sorok = array_slice($sorok, 0, $max_sor);
            $utso  = $sorok[$max_sor - 1];
            $hely  = $w - ($max_sor === 1 ? $kezd_w : 0.0);

            while ($utso !== '' && self::szel($utso . '…', $pt, $felkover, $betu) > $hely) {
                $utso = mb_substr($utso, 0, -1);
            }

            $sorok[$max_sor - 1] = rtrim($utso) . '…';
        }

        $ki = [];

        foreach ($sorok as $i => $s) {
            $ki[] = $i === 0 && $elo !== '' ? [[$elo, true], [$s, $felkover]] : [[$s, $felkover]];
        }

        return $ki;
    }

    /**
     * A matrica elemei mm-ben, a tartalomdoboz (cw × ch) bal felső sarkától.
     * Ha nem fér ki, a betű arányosan kisebb lesz (legfeljebb a felére).
     *
     * @param array<string, mixed> $a Az adatok (adatok() / minta_adat()).
     * @param array<string, mixed> $b A beállítás.
     * @return array{elemek: array<int, array<string, mixed>>, skala: float, levagva: bool}
     */
    public static function elrendez(array $a, array $b, float $cw, float $ch): array
    {
        $sablon = self::sablon($b['sablon'] ?? 'rma');
        $mezok  = self::mezok($sablon);
        $betu   = (string) $b['betu'];
        $be     = static fn (string $k): bool => !empty($b['mezok'][$k]['be']);

        $qr_kell = ($be('qr') || ($sablon === 'rma' && $be('rma') && in_array($b['rma_mod'], ['qr', 'mindketto'], true))) && (string) ($a['url'] ?? '') !== '';
        $qr_m    = $qr_kell ? SDH_Muhely_Kodok::qr_matrix((string) $a['url']) : null;
        $qr_kell = $qr_m !== null;
        $vk_ertek = (string) ($a['vonalkod_ertek'] ?? $a['szam_nyers'] ?? '');
        $vk       = $be('vonalkod') && $vk_ertek !== '' ? SDH_Muhely_Kodok::code128_modulok($vk_ertek) : null;
        // Termék- és szolgáltatás-címkén a vonalkód középen, alatta a kód olvashatóan.
        $vk_alatt = $sablon !== 'rma' && $vk !== null;

        $skala   = 1.0;
        $legjobb = null;

        for ($kor = 0; $kor < 12; $kor++) {
            $elemek = [];
            $qs     = $qr_kell ? min($ch * 0.62, $cw * 0.34, 22.0) : 0.0;
            $tw     = $cw - ($qr_kell ? $qs + 1.2 : 0.0);
            $bh     = $vk !== null ? min(8.0, max(4.0, $ch * 0.18)) : 0.0;
            $vpt    = max(5.0, (float) $b['meret'] * 0.8 * $skala);
            $vlh    = $vk_alatt ? $vpt * 0.3528 * 1.13 : 0.0;
            $mag    = $ch - ($vk !== null ? $bh + 0.6 + $vlh : 0.0);
            $y      = 0.0;
            $levagva = false;
            $csonka  = false;

            foreach ($b['mezok'] as $k => $mb) {
                if (empty($mb['be']) || in_array($k, ['qr', 'vonalkod'], true) || !isset($mezok[$k])) {
                    continue;
                }

                $pt  = (float) $b['meret'] * (float) $mb['meret'] * $skala;
                $lh  = $pt * 0.3528 * 1.13;
                $fb  = !empty($mb['felkover']);
                $fel = (string) $mb['felirat'];

                // A QR mellett keskenyebb a hasáb; alatta (ha a QR véget ért) teljes szélesség.
                $w = $qr_kell && $y < $qs + 0.6 ? $tw : $cw;

                if ($sablon === 'rma' && $k === 'feloldas') {
                    $kod   = (string) ($a['kod'] ?? '');
                    $minta = (string) ($a['minta'] ?? '');

                    if ($kod === '' && $minta === '') {
                        continue;
                    }

                    $g  = $minta !== '' ? min($lh * 2.6, $mag * 0.5) : 0.0;
                    $tx = $g > 0 ? $g + 1.0 : 0.0;
                    $sorok = [];

                    if ($kod !== '') {
                        $sorok = array_merge($sorok, self::tordel($fel, $kod, $w - $tx, $pt, $fb, 1, $betu));
                        $csonka = $csonka || self::$csonkolt;
                    }

                    if ($minta !== '') {
                        $sorok = array_merge($sorok, self::tordel('Minta', implode('-', str_split($minta)), $w - $tx, $pt, $fb, 1, $betu));
                        $csonka = $csonka || self::$csonkolt;
                    }

                    $blokk = max($g, count($sorok) * $lh);

                    if ($y + $blokk > $mag + 0.01) {
                        $levagva = true;
                        break;
                    }

                    if ($g > 0) {
                        $elemek[] = ['t' => 'minta', 'x' => 0.0, 'y' => $y, 'm' => $g, 'jegyek' => $minta];
                    }

                    foreach ($sorok as $i => $futasok) {
                        $elemek[] = ['t' => 'szoveg', 'x' => $tx, 'y' => $y + $i * $lh, 'pt' => $pt, 'futasok' => $futasok];
                    }

                    $y += $blokk + $lh * 0.18;
                    continue;
                }

                $ertek = (string) ($a[$k] ?? '');

                if ($sablon === 'rma' && $k === 'rma' && $b['rma_mod'] === 'qr') {
                    continue;
                }

                if (trim($ertek) === '') {
                    continue;
                }

                $sorok = self::tordel($fel, $ertek, $w, $pt, $fb, max(1, (int) $mezok[$k]['sorok']), $betu);
                $csonka = $csonka || self::$csonkolt;

                foreach ($sorok as $futasok) {
                    if ($y + $lh > $mag + 0.01) {
                        $levagva = true;
                        break 2;
                    }

                    $elemek[] = ['t' => 'szoveg', 'x' => 0.0, 'y' => $y, 'pt' => $pt, 'futasok' => $futasok];
                    $y += $lh;
                }

                $y += $lh * 0.18;
            }

            if ($qr_kell) {
                $elemek[] = ['t' => 'qr', 'x' => $cw - $qs, 'y' => 0.0, 'm' => min($qs, $mag), 'matrix' => $qr_m];
            }

            if ($vk !== null) {
                // Egy modul legfeljebb 0,5 mm: a rövid kód ne nyúljon szét az egész matricán.
                $vw = min($cw, array_sum($vk) * 0.5);
                $elemek[] = ['t' => 'vonalkod', 'x' => $vk_alatt ? ($cw - $vw) / 2 : 0.0, 'y' => $ch - $bh - $vlh, 'w' => $vw, 'h' => $bh, 'modulok' => $vk];

                if ($vk_alatt) {
                    $sor = self::tordel('', $vk_ertek, $cw, $vpt, false, 1, $betu)[0] ?? [];
                    $sw  = self::szel($vk_ertek, $vpt, false, $betu);
                    $elemek[] = ['t' => 'szoveg', 'x' => max(0.0, ($cw - $sw) / 2), 'y' => $ch - $vlh, 'pt' => $vpt, 'futasok' => $sor];
                }
            }

            // A csonkolt (…-tal végződő) sor is kisebb betűt kér – de ha a legkisebben sem fér ki, marad.
            $legjobb = ['elemek' => $elemek, 'skala' => $skala, 'levagva' => $levagva];

            if (!$levagva && (!$csonka || $skala < 0.62)) {
                break;
            }

            $skala *= 0.92;

            if ($skala < 0.5) {
                break;
            }
        }

        return $legjobb ?? ['elemek' => [], 'skala' => 1.0, 'levagva' => false];
    }

    /**
     * A tartalomdoboz a beállításból: az olvasási irányú méret (mm) és a margó.
     *
     * @param array<string, mixed> $b
     * @return array{w: float, h: float, cw: float, ch: float, m: float}
     */
    private static function doboz(array $b, bool $raszter): array
    {
        $c  = self::meret($b);
        $mm = 25.4 / self::DPI;

        // Raszternél a nyomtatható terület, PDF-nél a teljes címke a kiindulás.
        $atl = $raszter && $c['pont'][0] > 0 ? $c['pont'][0] * $mm : (float) $c['szel'];
        $hos = $raszter && $c['pont'][1] > 0 ? $c['pont'][1] * $mm : (float) $c['hossz'];

        [$w, $h] = $b['tajolas'] === 'fekvo' ? [$hos, $atl] : [$atl, $hos];
        $m = (float) $b['margo'];

        return ['w' => $w, 'h' => $h, 'cw' => max(5.0, $w - 2 * $m), 'ch' => max(5.0, $h - 2 * $m), 'm' => $m];
    }

    /* =================================================================
     * PDF
     * ============================================================== */

    /**
     * PDF a matricákból (eszközönként egy oldal, példányonként ismételve).
     *
     * @param array<int, array<string, mixed>> $lista
     * @param array<string, mixed>             $b
     */
    public static function pdf(array $lista, array $b, int $peldany = 1): string
    {
        self::tfpdf_betolt();

        $d = self::doboz($b, false);
        $f = self::betu_fajlok((string) $b['betu']);

        $pdf = new tFPDF($d['w'] > $d['h'] ? 'L' : 'P', 'mm', [$d['w'], $d['h']]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetCompression(true);
        $pdf->SetCreator('SDH Műhely', true);
        $pdf->SetTitle(self::SABLONOK[self::sablon($b['sablon'] ?? 'rma')], true);
        $pdf->AddFont('F', '', $f[0], true);
        $pdf->AddFont('F', 'B', $f[1], true);

        for ($p = 0; $p < max(1, $peldany); $p++) {
            foreach ($lista as $a) {
                $pdf->AddPage($d['w'] > $d['h'] ? 'L' : 'P', [$d['w'], $d['h']]);
                $r = self::elrendez($a, $b, $d['cw'], $d['ch']);
                self::pdf_rajzol($pdf, $r['elemek'], $d['m'], $d['m'], (string) $b['betu']);

                if (!empty($b['keret'])) {
                    $pdf->SetDrawColor(0, 0, 0);
                    $pdf->SetLineWidth(0.25);
                    $pdf->Rect(0.4, 0.4, $d['w'] - 0.8, $d['h'] - 0.8);
                }
            }
        }

        return (string) $pdf->Output('S');
    }

    /**
     * @param tFPDF                            $pdf
     * @param array<int, array<string, mixed>> $elemek
     */
    private static function pdf_rajzol($pdf, array $elemek, float $ox, float $oy, string $betu): void
    {
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);

        foreach ($elemek as $e) {
            switch ($e['t']) {
                case 'szoveg':
                    $x  = $ox + (float) $e['x'];
                    $pt = (float) $e['pt'];
                    // Alapvonal: a sor tetejétől a betűmagasság ~0,8-a.
                    $y  = $oy + (float) $e['y'] + $pt * 0.3528 * 0.86;

                    foreach ($e['futasok'] as $f) {
                        $pdf->SetFont('F', $f[1] ? 'B' : '', $pt);
                        $pdf->Text($x, $y, (string) $f[0]);
                        $x += (float) $pdf->GetStringWidth((string) $f[0]);
                    }
                    break;

                case 'qr':
                    $n = count($e['matrix']);
                    $m = (float) $e['m'] / max(1, $n);

                    foreach ($e['matrix'] as $r => $sor) {
                        $kezd = -1;

                        for ($c = 0; $c <= $n; $c++) {
                            $s = $c < $n && !empty($sor[$c]);

                            if ($s && $kezd < 0) {
                                $kezd = $c;
                            } elseif (!$s && $kezd >= 0) {
                                $pdf->Rect($ox + (float) $e['x'] + $kezd * $m, $oy + (float) $e['y'] + $r * $m, ($c - $kezd) * $m, $m + 0.01, 'F');
                                $kezd = -1;
                            }
                        }
                    }
                    break;

                case 'vonalkod':
                    $ossz = array_sum($e['modulok']);
                    $mw   = (float) $e['w'] / max(1, $ossz);
                    $x    = $ox + (float) $e['x'];

                    foreach ($e['modulok'] as $i => $sz) {
                        if ($i % 2 === 0) {
                            $pdf->Rect($x, $oy + (float) $e['y'], $sz * $mw, (float) $e['h'], 'F');
                        }

                        $x += $sz * $mw;
                    }
                    break;

                case 'minta':
                    $pontok = self::minta_pontok((float) $e['m']);
                    $pdf->SetLineWidth(max(0.2, (float) $e['m'] * 0.05));
                    $jegyek = str_split((string) $e['jegyek']);

                    for ($i = 1, $db = count($jegyek); $i < $db; $i++) {
                        $p1 = $pontok[(int) $jegyek[$i - 1]];
                        $p2 = $pontok[(int) $jegyek[$i]];
                        $pdf->Line($ox + (float) $e['x'] + $p1[0], $oy + (float) $e['y'] + $p1[1], $ox + (float) $e['x'] + $p2[0], $oy + (float) $e['y'] + $p2[1]);
                    }

                    foreach ($pontok as $n => $p) {
                        $kezdo = $jegyek !== [] && (int) $jegyek[0] === $n;
                        $rr = (float) $e['m'] * ($kezdo ? 0.085 : 0.05);
                        self::pdf_kor($pdf, $ox + (float) $e['x'] + $p[0], $oy + (float) $e['y'] + $p[1], $rr, true);

                        if ($kezdo) {
                            self::pdf_kor($pdf, $ox + (float) $e['x'] + $p[0], $oy + (float) $e['y'] + $p[1], (float) $e['m'] * 0.13, false);
                        }
                    }

                    $pdf->SetLineWidth(0.15);
                    $pdf->Rect($ox + (float) $e['x'], $oy + (float) $e['y'], (float) $e['m'], (float) $e['m']);
                    break;
            }
        }
    }

    /** Kör (kitöltve vagy körvonal) Bézier-ívekkel – a tFPDF-ben nincs kör. @param tFPDF $pdf */
    private static function pdf_kor($pdf, float $x, float $y, float $r, bool $kitolt): void
    {
        $k  = 0.5523 * $r;
        $s  = 72 / 25.4;
        $h  = $pdf->GetPageHeight();
        $pt = static fn (float $px, float $py): string => sprintf('%.3F %.3F', $px * $s, ($h - $py) * $s);

        $ut = $pt($x + $r, $y) . ' m '
            . $pt($x + $r, $y - $k) . ' ' . $pt($x + $k, $y - $r) . ' ' . $pt($x, $y - $r) . ' c '
            . $pt($x - $k, $y - $r) . ' ' . $pt($x - $r, $y - $k) . ' ' . $pt($x - $r, $y) . ' c '
            . $pt($x - $r, $y + $k) . ' ' . $pt($x - $k, $y + $r) . ' ' . $pt($x, $y + $r) . ' c '
            . $pt($x + $k, $y + $r) . ' ' . $pt($x + $r, $y + $k) . ' ' . $pt($x + $r, $y) . ' c '
            . ($kitolt ? 'f' : 'S');

        (function () use ($ut): void {
            // A tFPDF védett _out() metódusa – a körhöz nyers PDF-út kell.
            $this->_out($ut);
        })->call($pdf);
    }

    /** A 3×3 pötty helye a minta négyzetében (1–9, balról jobbra, fentről le). @return array<int, array{0: float, 1: float}> */
    private static function minta_pontok(float $m): array
    {
        $p = [];

        for ($i = 1; $i <= 9; $i++) {
            $p[$i] = [$m * (0.18 + 0.32 * (($i - 1) % 3)), $m * (0.18 + 0.32 * intdiv($i - 1, 3))];
        }

        return $p;
    }

    /* =================================================================
     * Kép (GD) – előnézet és nyomtatóraszter
     * ============================================================== */

    /**
     * A matrica képe 300 dpi-n (olvasási irányban), fekete-fehér.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return resource|\GdImage|null
     */
    public static function kep(array $a, array $b)
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            return null;
        }

        $d    = self::doboz($b, true);
        $dpmm = self::DPI / 25.4;
        $W    = max(16, (int) round($d['w'] * $dpmm));
        $H    = max(16, (int) round($d['h'] * $dpmm));
        $img  = imagecreatetruecolor($W, $H);

        if ($img === false) {
            return null;
        }

        $feher  = imagecolorallocate($img, 255, 255, 255);
        $fekete = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $W, $H, $feher);

        $r  = self::elrendez($a, $b, $d['cw'], $d['ch']);
        $ox = $d['m'];
        $oy = $d['m'];
        $f  = self::betu_fajlok((string) $b['betu']);
        $dir = SDH_MUHELY_DIR . 'includes/lib/tfpdf/font/unifont/';
        $px = static fn (float $mm): int => (int) round($mm * $dpmm);

        foreach ($r['elemek'] as $e) {
            switch ($e['t']) {
                case 'szoveg':
                    $x  = $ox + (float) $e['x'];
                    $pt = (float) $e['pt'];
                    $y  = $oy + (float) $e['y'] + $pt * 0.3528 * 0.86;

                    foreach ($e['futasok'] as $fu) {
                        // A GD 96 dpi-vel számol: 300 dpi-hez a pont × 300/96.
                        imagettftext($img, $pt * self::DPI / 96, 0, $px($x), $px($y), $fekete, $dir . ($fu[1] ? $f[1] : $f[0]), (string) $fu[0]);
                        $x += self::szel((string) $fu[0], $pt, (bool) $fu[1], (string) $b['betu']);
                    }
                    break;

                case 'qr':
                    $n = count($e['matrix']);
                    $m = (float) $e['m'] / max(1, $n);

                    foreach ($e['matrix'] as $sr => $sor) {
                        foreach ($sor as $c => $s) {
                            if ($s) {
                                imagefilledrectangle($img, $px($ox + (float) $e['x'] + $c * $m), $px($oy + (float) $e['y'] + $sr * $m), $px($ox + (float) $e['x'] + ($c + 1) * $m) - 1, $px($oy + (float) $e['y'] + ($sr + 1) * $m) - 1, $fekete);
                            }
                        }
                    }
                    break;

                case 'vonalkod':
                    $ossz = array_sum($e['modulok']);
                    $mw   = (float) $e['w'] / max(1, $ossz);
                    $x    = $ox + (float) $e['x'];

                    foreach ($e['modulok'] as $i => $sz) {
                        if ($i % 2 === 0) {
                            imagefilledrectangle($img, $px($x), $px($oy + (float) $e['y']), max($px($x), $px($x + $sz * $mw) - 1), $px($oy + (float) $e['y'] + (float) $e['h']), $fekete);
                        }

                        $x += $sz * $mw;
                    }
                    break;

                case 'minta':
                    $pontok = self::minta_pontok((float) $e['m']);
                    $jegyek = str_split((string) $e['jegyek']);
                    imagesetthickness($img, max(2, $px((float) $e['m'] * 0.05)));

                    for ($i = 1, $db = count($jegyek); $i < $db; $i++) {
                        $p1 = $pontok[(int) $jegyek[$i - 1]];
                        $p2 = $pontok[(int) $jegyek[$i]];
                        imageline($img, $px($ox + (float) $e['x'] + $p1[0]), $px($oy + (float) $e['y'] + $p1[1]), $px($ox + (float) $e['x'] + $p2[0]), $px($oy + (float) $e['y'] + $p2[1]), $fekete);
                    }

                    imagesetthickness($img, 1);

                    foreach ($pontok as $n => $p) {
                        $kezdo = $jegyek !== [] && (int) $jegyek[0] === $n;
                        $d1 = max(3, $px((float) $e['m'] * ($kezdo ? 0.17 : 0.1)));
                        imagefilledellipse($img, $px($ox + (float) $e['x'] + $p[0]), $px($oy + (float) $e['y'] + $p[1]), $d1, $d1, $fekete);

                        if ($kezdo) {
                            $d2 = max(5, $px((float) $e['m'] * 0.26));
                            imageellipse($img, $px($ox + (float) $e['x'] + $p[0]), $px($oy + (float) $e['y'] + $p[1]), $d2, $d2, $fekete);
                        }
                    }

                    imagerectangle($img, $px($ox + (float) $e['x']), $px($oy + (float) $e['y']), $px($ox + (float) $e['x'] + (float) $e['m']), $px($oy + (float) $e['y'] + (float) $e['m']), $fekete);
                    break;
            }
        }

        if (!empty($b['keret'])) {
            imagerectangle($img, 2, 2, $W - 3, $H - 3, $fekete);
        }

        return $img;
    }

    /** A matrica képe PNG-ben (előnézet), a megadott legnagyobb szélességre kicsinyítve. @param array<string, mixed> $a @param array<string, mixed> $b */
    public static function png(array $a, array $b, int $max_szel = 640): string
    {
        $img = self::kep($a, $b);

        if ($img === null) {
            return '';
        }

        $w = imagesx($img);
        $h = imagesy($img);

        if ($w > $max_szel) {
            $uj = imagescale($img, $max_szel, (int) round($h * $max_szel / $w), IMG_BICUBIC);

            if ($uj !== false) {
                imagedestroy($img);
                $img = $uj;
            }
        }

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /* =================================================================
     * Brother QL raszter (közvetlen nyomtatás)
     * ============================================================== */

    /**
     * A Brother QL raszterparancsai a matricákhoz (egy oldal = egy matrica).
     *
     * Felépítés (Brother „Raster Command Reference”): törlés (nullbájtok),
     * ESC @ (alaphelyzet), ESC i a 1 (raszter mód), oldalanként: ESC i S,
     * ESC i z (címke adatai, sorok száma), ESC i M / ESC i A (automatikus
     * vágás), ESC i K (vágás a végén), ESC i d (előtolás), a sorok
     * („g 0x00 n” + adat), végül 0x0C (következő oldal) vagy 0x1A (nyomtatás).
     *
     * @param array<int, array<string, mixed>> $lista
     * @param array<string, mixed>             $b
     */
    public static function raszter(array $lista, array $b, int $peldany = 1): string
    {
        $ny = self::nyomtatok()[(string) $b['nyomtato']];
        $c  = self::meret($b);
        $D  = (int) $ny['bajt'] * 8;
        $P  = (int) $c['pont'][0];
        $x0 = $D - $P - (int) $c['jobb'] - (int) $ny['eltolas'];

        $ki = str_repeat("\x00", (int) $ny['torles']) . "\x1B\x40";

        if (!empty($ny['mod'])) {
            $ki .= "\x1B\x69\x61\x01";
        }

        $oldalak = [];

        for ($p = 0; $p < max(1, $peldany); $p++) {
            foreach ($lista as $a) {
                $oldalak[] = $a;
            }
        }

        $db = count($oldalak);

        foreach ($oldalak as $i => $a) {
            $img = self::kep($a, $b);

            if ($img === null) {
                return '';
            }

            // Olvasási irány → a szalag iránya: fekvőnél elforgatjuk (a szélesség = a fej szélessége).
            if ($b['tajolas'] === 'fekvo') {
                $forg = imagerotate($img, 90, 0xFFFFFF);

                if ($forg !== false) {
                    imagedestroy($img);
                    $img = $forg;
                }
            }

            $w = imagesx($img);
            $h = imagesy($img);

            $ki .= "\x1B\x69\x53";

            // ESC i z: érvényes mezők (minőség, típus, szélesség, [hossz]), típus, szélesség mm, hossz mm, sorok, oldal, 0.
            $stancolt = $c['tipus'] === 'stancolt';
            $jelzo = 0x80 | 0x02 | 0x04 | 0x40 | ($stancolt ? 0x08 : 0);
            $ki .= "\x1B\x69\x7A" . chr($jelzo) . chr($stancolt ? 0x0B : 0x0A) . chr((int) $c['szel']) . chr($stancolt ? (int) $c['hossz'] : 0)
                . pack('V', $h) . chr($i === 0 ? 0 : 1) . "\x00";

            if (!empty($ny['vago'])) {
                $ki .= "\x1B\x69\x4D" . chr(!empty($b['vagas']) ? 0x40 : 0x00);
                $ki .= "\x1B\x69\x41\x01";
                $ki .= "\x1B\x69\x4B" . chr(!empty($b['vagas']) ? 0x08 : 0x00);
            }

            $ki .= "\x1B\x69\x64" . pack('v', (int) $c['elotolas']);

            // A sorok: 1 = fekete pont; a fej bitsorrendje tükrözött (az első bájt legfelső bitje a jobb szél).
            $bajt = (int) $ny['bajt'];

            for ($y = 0; $y < $h; $y++) {
                $sor = array_fill(0, $bajt, 0);

                for ($x = 0; $x < $w && $x < $P; $x++) {
                    $rgb = imagecolorat($img, $x, $y);
                    $l   = (($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114;

                    if ($l >= 140) {
                        continue;
                    }

                    $eszkoz_x = $x0 + $x;
                    $bit      = $D - 1 - $eszkoz_x;

                    if ($bit >= 0 && $bit < $D) {
                        $sor[intdiv($bit, 8)] |= 0x80 >> ($bit % 8);
                    }
                }

                $ki .= "\x67\x00" . chr($bajt);

                foreach ($sor as $o) {
                    $ki .= chr($o);
                }
            }

            imagedestroy($img);

            $ki .= $i === $db - 1 ? "\x1A" : "\x0C";
        }

        return $ki;
    }

    /** A raszter elküldése a nyomtató 9100-as (RAW) portjára. Hiba esetén a szöveg, különben null. */
    public static function kuld(string $adat, string $cim, int $port): ?string
    {
        if ($adat === '') {
            return 'Nem sikerült elkészíteni a nyomtatnivalót.';
        }

        $hiba_sz = 0;
        $hiba    = '';
        $kapcs   = @fsockopen($cim, $port, $hiba_sz, $hiba, 4.0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ($kapcs === false) {
            return sprintf('A nyomtató nem érhető el (%s:%d) – be van kapcsolva, ugyanazon a hálózaton van? (%s)', $cim, $port, $hiba !== '' ? $hiba : 'kapcsolódási hiba');
        }

        stream_set_timeout($kapcs, 10);
        $irt = 0;
        $hossz = strlen($adat);

        while ($irt < $hossz) {
            $n = fwrite($kapcs, substr($adat, $irt, 8192));

            if ($n === false || $n === 0) {
                fclose($kapcs);

                return 'A küldés megszakadt a nyomtató felé.';
            }

            $irt += $n;
        }

        fclose($kapcs);

        return null;
    }

    /* =================================================================
     * A munkalap „Matrica" gombja és a Matrica ablak
     * ============================================================== */

    /**
     * A „Matrica" / „Címke" gomb: munkalapon (rma), a termék és a
     * szolgáltatás űrlapján (termek / szolgaltatas).
     */
    public static function gomb(int $id, string $sablon = 'rma'): string
    {
        if (SDH_Muhely_Modulok::ki(self::KULCS) || $id <= 0) {
            return '';
        }

        $sablon = self::sablon($sablon);
        $cim    = [
            'rma'          => 'RMA-matrica a készülékre (Brother címkenyomtató)',
            'rendeles'     => 'Címke a megérkezett árura: kinek jött, melyik lapra (Brother címkenyomtató)',
            'termek'       => 'Termékcímke nyomtatása (Brother címkenyomtató)',
            'szolgaltatas' => 'Szolgáltatás-címke nyomtatása (Brother címkenyomtató)',
            'tetel'        => 'Címkék a beszállítói levél tételeire (Brother címkenyomtató)',
            'egyedi'       => 'Egyedi címke: amit beírsz (Brother címkenyomtató)',
        ][$sablon] ?? 'Címke nyomtatása (Brother címkenyomtató)';

        return '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-matrica-gomb" data-sdh-matrica="' . (int) $id . '"'
            . ($sablon !== 'rma' ? ' data-sdh-matrica-tipus="' . esc_attr($sablon) . '"' : '')
            . ' title="' . esc_attr($cim) . '">'
            . SDH_Muhely_Admin_UI::gomb_ikon('matrica') . '<span>' . (in_array($sablon, ['rma', 'rendeles'], true) ? 'Matrica' : 'Címke') . '</span></button>';
    }

    /** A kérés sablonja (tipus paraméter). */
    private static function kert_sablon(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a hívó ellenőrizte.
        return self::sablon(isset($_REQUEST['tipus']) ? wp_unslash((string) $_REQUEST['tipus']) : 'rma');
    }

    /** Naplóbejegyzés a nyomtatásról: a munkalapra, a termékre vagy a szolgáltatásra. */
    private static function naploz(string $sablon, int $id, string $leiras, array $adat): void
    {
        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny($sablon === 'rma' ? 'munkalap' : $sablon, $id, $leiras, $adat, 'nyomtatas');
        }
    }

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    /** A kért eszközök (kulcsok) – üresen mind. @param array<int, array<string, mixed>> $adatok @return array<int, array<string, mixed>> */
    private static function valogat(array $adatok, $kert): array
    {
        $kert = is_array($kert) ? array_map('intval', $kert) : [];

        if ($kert === []) {
            return $adatok;
        }

        return array_intersect_key($adatok, array_flip($kert));
    }

    public static function ajax_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }

        $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sablon = self::kert_sablon();
        $adatok = self::lista($sablon, $id);
        $b      = self::beallitas($sablon);
        $nev    = self::SABLONOK[$sablon];

        if ($adatok === []) {
            echo '<h2 class="sdh-modal__cim">' . esc_html($nev) . '</h2><div class="sdh-uzenet sdh-uzenet--hiba">'
                . esc_html(['rma' => 'Nincs ilyen munkalap.', 'rendeles' => 'Nincs ilyen rendelés.', 'termek' => 'Nincs ilyen termék.', 'szolgaltatas' => 'Nincs ilyen szolgáltatás.', 'egyedi' => 'A címke nem készült el.', 'tetel' => 'Ehhez a feladathoz nincs címkézhető tétel.'][$sablon]) . '</div>';
            wp_die();
        }

        $elso = reset($adatok);
        $kozvetlen = $b['mod'] === 'halozat' ? self::kozvetlen_hiba($b) : null;
        $c = self::meret($b);
        $megj = $sablon === 'rma' ? 'Munkalap ' . $elso['munkalapszam'] : ($sablon === 'egyedi' ? 'amit beírsz' : ($sablon === 'tetel' ? count($adatok) . ' tétel' : (string) ($elso['cim'] ?? '')));

        ?>
        <h2 class="sdh-modal__cim"><?php echo esc_html($nev); ?> <span class="sdh-modal__cim-megj"><?php echo esc_html($megj); ?></span></h2>

        <div class="sdh-matrica" data-sdh-matrica-ablak data-id="<?php echo (int) $id; ?>" data-tipus="<?php echo esc_attr($sablon); ?>" data-mod="<?php echo esc_attr($b['mod']); ?>">
            <p class="sdh-sugo">
                <?php echo esc_html((self::nyomtatok()[$b['nyomtato']]['nev'] ?? $b['nyomtato']) . ' · ' . $c['nev'] . ($c['tipus'] === 'folyamatos' ? ', ' . (int) $b['hossz'] . ' mm hosszú' : '') . ' · ' . ($b['tajolas'] === 'fekvo' ? 'fekvő' : 'álló')); ?>
                · <?php echo esc_html($b['mod'] === 'halozat' ? 'közvetlenül hálózaton (' . ($b['ip'] !== '' ? $b['ip'] : 'nincs IP') . ')' : 'böngészőből, a Brother illesztőprogrammal'); ?>
            </p>

            <?php if ($kozvetlen !== null) : ?>
                <div class="sdh-uzenet sdh-uzenet--hiba"><?php echo esc_html($kozvetlen); ?></div>
            <?php endif; ?>

            <?php if ($b['mod'] !== 'halozat') : ?>
                <div class="sdh-matrica__tipp">
                    <p>A nyomtatóban ennek kell lennie: <strong><?php echo esc_html($c['nev']); ?></strong>. A nyomtatás-ablakban: Papírméret <strong><?php echo esc_html(self::papir_nev($b)); ?></strong>, Margók <strong>Nincs</strong>, Méret <strong>100%</strong>, Fejlécek és láblécek <strong>ki</strong>.</p>
                    <p>Ha a Brother azt írja, hogy a címke „nem egyezik”: a Beállítások › Címkenyomtatás <em>Címke / szalag</em> mezőjében azt válaszd, amit a Brother ablak kiír (például „62mm Folyamatos szalag” → DK-22205 – 62 mm folyamatos).</p>
                </div>
            <?php endif; ?>

            <?php if ($sablon === 'egyedi') : ?>
                <?php // Egyedi címke: ide írod, ami a címkére kerül; az előnézet gépelés közben követi. ?>
                <div class="sdh-matrica__egyedi" data-sdh-matrica-egyedi>
                    <?php for ($sor = 1; $sor <= 4; $sor++) : ?>
                        <div class="sdh-mezo">
                            <label for="sdh_matrica_sor<?php echo (int) $sor; ?>"><?php
                                echo esc_html([1 => '1. sor (nagy)', 2 => '2. sor', 3 => '3. sor', 4 => '4. sor (apró)'][$sor]);
                            ?></label>
                            <input type="text" id="sdh_matrica_sor<?php echo (int) $sor; ?>" maxlength="120" autocomplete="off"
                                   data-sdh-matrica-sor="<?php echo (int) $sor; ?>"
                                   value="<?php echo esc_attr((string) ($elso['sor' . $sor] ?? '')); ?>"
                                   placeholder="<?php echo esc_attr([1 => 'pl. Bontott alkatrész', 2 => 'pl. Samsung A50 – alaplap', 3 => 'pl. Ügyfél: Kovács Béla', 4 => 'pl. Nem adható ki!'][$sor]); ?>">
                        </div>
                    <?php endfor; ?>
                    <p class="sdh-mezo__sugo">Az üres sor lemarad a címkéről. Melyik sor mekkora betűvel megy, azt a Beállítások › Címkenyomtatás › Egyedi címke lapon állítod.</p>
                </div>
            <?php endif; ?>

            <div class="sdh-matrica__lista">
                <?php foreach ($adatok as $kulcs => $a) : ?>
                    <?php $png = self::png($a, $b, 520); ?>
                    <label class="sdh-matrica__elem">
                        <span class="sdh-matrica__fej">
                            <input type="checkbox" data-sdh-matrica-eszkoz value="<?php echo (int) $kulcs; ?>" checked>
                            <span><?php
                                if ($sablon === 'rma') {
                                    echo esc_html(($a['darab'] !== '' ? $a['darab'] . ' · ' : '') . ($a['eszkoz'] !== '' ? $a['eszkoz'] : 'Eszköz nélkül'));
                                } elseif ($sablon === 'egyedi') {
                                    echo 'Előnézet';
                                } elseif ($sablon === 'tetel') {
                                    echo esc_html(trim(((string) $a['telefon'] !== '' ? $a['telefon'] . ' · ' : '') . (string) $a['cim']));
                                } else {
                                    echo esc_html((string) $a['cim']);
                                }
                            ?></span>
                        </span>
                        <?php if ($png !== '') : ?>
                            <img class="sdh-matrica__kep" alt="A címke előnézete" src="data:image/png;base64,<?php echo esc_attr(base64_encode($png)); ?>">
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="sdh-matrica__lab">
                <?php // Példányszám: saját léptető gombok (a böngésző apró nyilai helyett). ?>
                <label class="sdh-matrica__peldany">Példány
                    <button type="button" class="sdh-matrica__lep" data-sdh-matrica-lep="-1" aria-label="Eggyel kevesebb">−</button>
                    <input type="number" min="1" max="50" step="1" value="<?php echo (int) $b['peldany']; ?>" data-sdh-matrica-peldany data-sdh-szam-nincs aria-label="Példányszám">
                    <button type="button" class="sdh-matrica__lep" data-sdh-matrica-lep="1" aria-label="Eggyel több">+</button>
                </label>
                <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-matrica-nyomtat<?php echo $kozvetlen !== null ? ' disabled' : ''; ?>>Nyomtatás</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-matrica-pdf>PDF megnyitása</button>
                <?php if (SDH_Muhely_Admin_UI::admin_e()) : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(add_query_arg('matrica_sablon', $sablon, SDH_Muhely_Beallitasok::url('matrica'))); ?>">Beállítások</a>
                <?php endif; ?>
                <span class="sdh-matrica__allapot" data-sdh-matrica-allapot role="status" aria-live="polite"></span>
            </div>
        </div>
        <?php

        wp_die();
    }

    /** PDF a munkalap matricáiról (böngészős nyomtatás). */
    public static function ajax_pdf(): void
    {
        self::jog();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a jog() ellenőrizte.
        $id      = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
        $peldany = isset($_REQUEST['peldany']) ? min(50, max(1, (int) $_REQUEST['peldany'])) : 1;
        $sablon  = self::kert_sablon();
        $lista   = self::valogat(self::lista($sablon, $id), $_REQUEST['eszkoz'] ?? []);
        $nyomtat = !empty($_REQUEST['nyomtat']);
        // phpcs:enable

        if ($lista === []) {
            wp_die('Nincs ilyen tétel vagy eszköz.', '', ['response' => 404]);
        }

        $b   = self::beallitas($sablon);
        $pdf = self::pdf(array_values($lista), $b, $peldany);

        if ($nyomtat) {
            self::naploz($sablon, $id, sprintf('%s nyomtatásra küldve (böngészőből, %d db)', self::SABLONOK[$sablon], count($lista) * $peldany), ['Címke' => self::meret($b)['nev'], 'Nyomtató' => (string) $b['nyomtato']]);
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . ($sablon === 'rma' ? 'matrica' : 'cimke-' . $sablon) . '-' . sanitize_file_name((string) reset($lista)['szam_nyers']) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- PDF.
        exit;
    }

    /** Előnézeti kép egy matricáról (PNG, base64) – a Matrica ablak frissítéséhez. */
    public static function ajax_kep(): void
    {
        self::jog();

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $kulcs  = isset($_POST['kulcs']) ? (int) $_POST['kulcs'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $sablon = self::kert_sablon();
        $adatok = self::lista($sablon, $id);

        if (!isset($adatok[$kulcs])) {
            wp_send_json_error(['uzenet' => $sablon === 'rma' ? 'Nincs ilyen eszköz a lapon.' : 'Nincs ilyen tétel.']);
        }

        wp_send_json_success(['png' => base64_encode(self::png($adatok[$kulcs], self::beallitas($sablon), 520))]);
    }

    /** Közvetlen nyomtatás hálózaton (QL raszter a 9100-as portra). */
    public static function ajax_nyomtat(): void
    {
        self::jog();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a jog() ellenőrizte.
        $id      = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $peldany = isset($_POST['peldany']) ? min(50, max(1, (int) $_POST['peldany'])) : 1;
        $sablon  = self::kert_sablon();
        $lista   = self::valogat(self::lista($sablon, $id), $_POST['eszkoz'] ?? []);
        // phpcs:enable

        if ($lista === []) {
            wp_send_json_error(['uzenet' => 'Jelölj be legalább egy címkét.']);
        }

        $b = self::beallitas($sablon);
        $h = self::kozvetlen_hiba($b);

        if ($h !== null) {
            wp_send_json_error(['uzenet' => $h]);
        }

        $h = self::kuld(self::raszter(array_values($lista), $b, $peldany), (string) $b['ip'], (int) $b['port']);

        self::naploz($sablon, $id, $h === null
            ? sprintf('%s kinyomtatva (hálózaton, %d db)', self::SABLONOK[$sablon], count($lista) * $peldany)
            : self::SABLONOK[$sablon] . ' nyomtatása nem sikerült: ' . $h, ['Nyomtató' => (string) $b['nyomtato'] . ' – ' . $b['ip']]);

        if ($h !== null) {
            wp_send_json_error(['uzenet' => $h]);
        }

        wp_send_json_success(['uzenet' => sprintf('Elküldve a nyomtatóra: %d címke.', count($lista) * $peldany)]);
    }

    /* =================================================================
     * Beállítások › Címkenyomtatás
     * ============================================================== */

    /**
     * Egy sablon beküldött beállítása a Beállítások űrlapjáról: a közös rész
     * (nyomtató, tekercs) mindig a matrica[...] mezőkből, a többi a sablon
     * saját mezőiből (rma: matrica[...], a többi: matrica_<sablon>[...]).
     *
     * @return array<string, mixed>
     */
    private static function urlap_beallitas(string $sablon): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a hívó ellenőrizte.
        $kozos = isset($_POST['matrica']) && is_array($_POST['matrica']) ? wp_unslash($_POST['matrica']) : [];
        $kozos = is_array($kozos) ? $kozos : [];
        $kulcs = 'matrica_' . $sablon;
        $m     = $sablon === 'rma' ? $kozos : (isset($_POST[$kulcs]) && is_array($_POST[$kulcs]) ? wp_unslash($_POST[$kulcs]) : []);
        // phpcs:enable
        $m = is_array($m) ? $m : [];

        // A be nem pipált jelölők nem jönnek el: hiánya = kikapcsolva.
        foreach ($sablon === 'rma' ? ['eszkozonkent', 'vagas', 'keret'] : ['keret'] as $k) {
            $m[$k] = !empty($m[$k]);
        }

        if ($sablon !== 'rma') {
            foreach (self::KOZOS as $k) {
                $m[$k] = $k === 'vagas' ? !empty($kozos[$k]) : ($kozos[$k] ?? null);
            }

            $m = array_filter($m, static fn ($v) => $v !== null);
        }

        return self::tisztit($m, $sablon);
    }

    /** A Beállítások űrlapjáról beküldött (még nem mentett) beállítás. @return array<string, mixed> */
    private static function bekuldott(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a hívó ellenőrizte.
        return self::urlap_beallitas(self::sablon(isset($_POST['sablon']) ? wp_unslash((string) $_POST['sablon']) : 'rma'));
    }

    /** Élő előnézet a Beállításokban (a még el nem mentett értékekkel, kitalált adattal). */
    public static function ajax_elonezet(): void
    {
        self::jog();

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ehhez adminisztrátori jog kell.'], 403);
        }

        $b   = self::bekuldott();
        $ma  = self::minta_adat((string) $b['sablon']);
        $d   = self::doboz($b, false);
        $r   = self::elrendez($ma, $b, $d['cw'], $d['ch']);
        $png = self::png($ma, $b, 640);

        wp_send_json_success([
            'png'     => base64_encode($png),
            'meret'   => sprintf('%s × %s mm', rtrim(rtrim(number_format($d['w'], 1, ',', ''), '0'), ','), rtrim(rtrim(number_format($d['h'], 1, ',', ''), '0'), ',')),
            'levagva' => $r['levagva'],
            'skala'   => round($r['skala'] * 100),
            'kozvetlen' => $b['mod'] === 'halozat' ? self::kozvetlen_hiba($b) : null,
        ]);
    }

    /** Próbanyomat a még el nem mentett beállítással: hálózaton nyomtat, böngészős módban PDF-et ad. */
    public static function ajax_proba(): void
    {
        self::jog();

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ehhez adminisztrátori jog kell.'], 403);
        }

        $b = self::bekuldott();

        if ($b['mod'] === 'halozat') {
            $h = self::kozvetlen_hiba($b) ?? self::kuld(self::raszter([self::minta_adat((string) $b['sablon'])], $b, 1), (string) $b['ip'], (int) $b['port']);

            if ($h !== null) {
                wp_send_json_error(['uzenet' => $h]);
            }

            wp_send_json_success(['uzenet' => 'A próbacímke elment a nyomtatóra.']);
        }

        wp_send_json_success(['pdf' => base64_encode(self::pdf([self::minta_adat((string) $b['sablon'])], $b, 1))]);
    }

    /* =================================================================
     * Címkék menüpont (0.61.1)
     * ============================================================== */

    /**
     * A Címkék oldal: innen indul az egyedi címke, és itt látszik egy helyen,
     * melyik címke hol érhető el, és mi áll rajta. A nyomtató és a tartalom
     * beállítása a Beállítások › Címkenyomtatás lapon marad (egy helyen).
     */
    public static function oldal(): void
    {
        $b = self::beallitas();
        $c = self::meret($b);

        SDH_Muhely_Admin_UI::fejlec(
            'Címkék',
            'Brother címkenyomtató: egyedi címke, és ami a munkalapról, termékről, rendelésről megy.',
            [[
                'cimke'  => 'Egyedi címke',
                'ikon'   => 'matrica',
                'url'    => '#',
                'elsodleges' => true,
                'adatok' => ['sdh-matrica' => '0', 'sdh-matrica-tipus' => 'egyedi'],
            ]]
        );

        $honnan = [
            'rma'          => ['Munkalap és rendelés ablakának alján a <strong>Matrica</strong> gomb.', 'A leadott készülékre: ügyfél, lapszám, eszköz, hiba, RMA-kód.'],
            'termek'       => ['A termék ablakának alján a <strong>Címke</strong> gomb.', 'Árcímke, polccímke: megnevezés, ár, cikkszám, vonalkód.'],
            'szolgaltatas' => ['A szolgáltatás ablakának alján a <strong>Címke</strong> gomb.', 'Megnevezés, ár (ársávnál tól–ig), kategória.'],
            'tetel'        => ['Levelezés › <strong>Feladatok</strong> › Címkék.', 'A beszállítói levél tétele – ár nélkül, telefonszámmal.'],
            'egyedi'       => ['Ezen az oldalon a fenti <strong>Egyedi címke</strong> gomb.', 'Négy sor, amit beírsz. Nem kell hozzá se munkalap, se termék.'],
        ];

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">A nyomtató</h2>

            <p class="sdh-sugo">
                <strong><?php echo esc_html((string) (self::nyomtatok()[$b['nyomtato']]['nev'] ?? $b['nyomtato'])); ?></strong>
                · <?php echo esc_html((string) $c['nev']); ?>
                · <?php echo esc_html($b['mod'] === 'halozat' ? 'közvetlenül hálózaton (' . ($b['ip'] !== '' ? $b['ip'] : 'nincs IP') . ')' : 'böngészőből, a Brother illesztőprogrammal'); ?>.
                A nyomtató, a tekercs és az, hogy mi kerüljön az egyes címkékre:
                <a href="<?php echo esc_url(SDH_Muhely_Modulok::url('beallitasok', ['ful' => self::KULCS])); ?>">Beállítások › Címkenyomtatás</a>.
            </p>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Milyen címkék vannak</h2>

            <table class="sdh-tabla">
                <thead>
                    <tr><th>Címke</th><th>Honnan nyomtatod</th><th>Mi van rajta</th><th>Beállítás</th></tr>
                </thead>
                <tbody>
                    <?php foreach (self::SABLONOK as $kulcs => $nev) : ?>
                        <tr>
                            <td class="sdh-tabla__nev"><?php echo esc_html($nev); ?></td>
                            <td><?php echo wp_kses_post((string) ($honnan[$kulcs][0] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($honnan[$kulcs][1] ?? '')); ?></td>
                            <td>
                                <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(SDH_Muhely_Modulok::url('beallitasok', ['ful' => self::KULCS, 'sablon' => $kulcs])); ?>">Mi legyen rajta</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function beallitas_doboz(): void
    {
        $b  = self::beallitas();
        $ny = self::nyomtatok();
        $ck = self::cimkek();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- csak a megnyitott lapfület választja.
        $aktiv = self::sablon(isset($_GET['matrica_sablon']) ? wp_unslash((string) $_GET['matrica_sablon']) : 'rma');

        ?>
        <div class="sdh-matrica-beallitas" data-sdh-matrica-beallitas>
            <div class="sdh-doboz" data-sdh-matrica-kozos>
                <h2 class="sdh-doboz__cim">Nyomtató és címke</h2>
                <p class="sdh-sugo">
                    <strong>Böngésző</strong>: minden Brother címkenyomtatóval megy (QL, PT, TD) – a rendszer pontos méretű PDF-et ad, és a böngésző
                    nyomtatás-ablakában a Brother nyomtatót kell választani. <strong>Hálózat</strong>: a hálózatos QL nyomtatóknak a szerver közvetlenül
                    küldi a címkét (nincs ablak, nincs illesztőprogram) – ehhez a szervernek el kell érnie a nyomtatót, és a nyomtatónak állandó IP-cím kell.
                    A nyomtató és a betett tekercs <strong>közös</strong>: az RMA-matrica, a termék- és a szolgáltatás-címke is ezt használja.
                </p>
                <input type="hidden" name="matrica_jelen" value="1">
                <div class="sdh-mezok">
                    <div class="sdh-mezo">
                        <label for="matrica_nyomtato">Nyomtató típusa</label>
                        <select name="matrica[nyomtato]" id="matrica_nyomtato" data-sdh-matrica-figyel>
                            <?php foreach ($ny as $k => $n) : ?>
                                <option value="<?php echo esc_attr($k); ?>" data-kozvetlen="<?php echo $n['kozvetlen'] ? '1' : '0'; ?>" <?php selected($b['nyomtato'], $k); ?>><?php echo esc_html($n['nev']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sdh-mezo">
                        <label for="matrica_mod">Nyomtatási mód</label>
                        <select name="matrica[mod]" id="matrica_mod" data-sdh-matrica-figyel>
                            <option value="bongeszo" <?php selected($b['mod'], 'bongeszo'); ?>>Böngésző (Brother illesztőprogram, PDF)</option>
                            <option value="halozat" <?php selected($b['mod'], 'halozat'); ?>>Közvetlenül hálózaton (QL, 9100-as port)</option>
                        </select>
                    </div>
                    <div class="sdh-mezo" data-sdh-matrica-halozat>
                        <label for="matrica_ip">A nyomtató IP-címe</label>
                        <input type="text" name="matrica[ip]" id="matrica_ip" inputmode="decimal" placeholder="pl. 192.168.1.50" value="<?php echo esc_attr($b['ip']); ?>">
                    </div>
                    <div class="sdh-mezo" data-sdh-matrica-halozat>
                        <label for="matrica_port">Port</label>
                        <input type="number" name="matrica[port]" id="matrica_port" min="1" max="65535" value="<?php echo (int) $b['port']; ?>">
                    </div>
                    <div class="sdh-mezo">
                        <label for="matrica_cimke">Címke / szalag (a betett tekercs)</label>
                        <select name="matrica[cimke]" id="matrica_cimke" data-sdh-matrica-figyel>
                            <?php foreach ($ck as $k => $c) : ?>
                                <option value="<?php echo esc_attr($k); ?>" data-tipus="<?php echo esc_attr($c['tipus']); ?>" <?php selected($b['cimke'], $k); ?>><?php echo esc_html($c['nev']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sdh-sugo">A Brother nyomtatás-ablaka kiírja (például „62mm Folyamatos szalag”). Ha nem egyezik, a nyomtató nem nyomtat.</p>
                    </div>
                    <div class="sdh-mezo" data-sdh-matrica-egyedi>
                        <label for="matrica_egyedi_sz">Szélesség (mm)</label>
                        <input type="number" name="matrica[egyedi_sz]" id="matrica_egyedi_sz" min="10" max="200" step="0.5" value="<?php echo esc_attr((string) $b['egyedi_sz']); ?>" data-sdh-matrica-figyel>
                    </div>
                    <div class="sdh-mezo" data-sdh-matrica-egyedi>
                        <label for="matrica_egyedi_h">Magasság (mm)</label>
                        <input type="number" name="matrica[egyedi_h]" id="matrica_egyedi_h" min="10" max="300" step="0.5" value="<?php echo esc_attr((string) $b['egyedi_h']); ?>" data-sdh-matrica-figyel>
                    </div>
                </div>
                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" name="matrica[vagas]" id="matrica_vagas" value="1" <?php checked($b['vagas']); ?>>
                    <label for="matrica_vagas">Automatikus vágás címkénként (hálózati nyomtatásnál)</label>
                </div>
            </div>

            <div class="sdh-matrica-sablonok" role="tablist" aria-label="Címkesablon">
                <?php foreach (self::SABLONOK as $s => $nev) : ?>
                    <button type="button" class="sdh-matrica-sablonok__gomb<?php echo $s === $aktiv ? ' is-aktiv' : ''; ?>" role="tab"
                            aria-selected="<?php echo $s === $aktiv ? 'true' : 'false'; ?>" data-sdh-matrica-sablon-gomb="<?php echo esc_attr($s); ?>"><?php echo esc_html($nev); ?></button>
                <?php endforeach; ?>
            </div>

            <?php foreach (array_keys(self::SABLONOK) as $s) : ?>
                <?php self::sablon_panel($s, $s === $aktiv); ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /** Egy sablon beállításai: elrendezés, a címke adatai, élő előnézet. */
    private static function sablon_panel(string $sablon, bool $latszik): void
    {
        $b   = self::beallitas($sablon);
        $mz  = self::mezok($sablon);
        $nev = $sablon === 'rma' ? 'matrica' : 'matrica_' . $sablon;
        $idp = $nev;
        $meretek = ['0.75' => 'kicsi', '0.85' => 'kisebb', '0.9' => 'kicsit kisebb', '0.95' => 'alig kisebb', '1' => 'normál', '1.15' => 'nagyobb', '1.45' => 'nagy', '1.9' => 'óriás'];
        $cimke = ['rma' => 'matrica', 'rendeles' => 'címke', 'termek' => 'címke', 'szolgaltatas' => 'címke', 'egyedi' => 'címke', 'tetel' => 'címke'][$sablon];

        ?>
        <div class="sdh-matrica-beall" data-sdh-matrica-beall data-sablon="<?php echo esc_attr($sablon); ?>" role="tabpanel"<?php echo $latszik ? '' : ' style="display:none"'; ?>>
            <?php if ($sablon !== 'rma') : ?>
                <input type="hidden" name="<?php echo esc_attr($nev); ?>_jelen" value="1">
            <?php endif; ?>
            <div class="sdh-doboz">
                <h2 class="sdh-doboz__cim">Elrendezés – <?php echo esc_html(self::SABLONOK[$sablon]); ?></h2>
                <?php if ($sablon === 'termek') : ?>
                    <p class="sdh-sugo">A termék űrlapjának <strong>Címke</strong> gombja ezzel nyomtat (árcímke, polccímke, alkatrész-azonosító).</p>
                <?php elseif ($sablon === 'rendeles') : ?>
                    <p class="sdh-sugo">A rendelés ablakának <strong>Matrica</strong> gombja ezzel nyomtat: a megérkezett árura ragasztva látszik, kinek jött és melyik lapra. Ár alapból nincs rajta, de bekapcsolható.</p>
                <?php elseif ($sablon === 'tetel') : ?>
                    <p class="sdh-sugo">A beszállítói levél tételeinek címkéje (Levelezés › Feladatok › <strong>Címkék</strong>): a készülék, a megnevezés, az azonosító, a garancia, a cikkszám és a megjegyzés kerül rá. <strong>Ár nem</strong> – az nem az ügyfélre tartozik.</p>
                <?php elseif ($sablon === 'egyedi') : ?>
                    <p class="sdh-sugo">Az <strong>Áttekintés</strong> fejlécének <strong>Egyedi címke</strong> gombja ezzel nyomtat: a szöveget a nyomtatás ablakában írod be, itt csak azt állítod, hogy melyik sor mekkora betűvel menjen, és mi kerüljön még rá (dátum, szerviz neve, vonalkód).</p>
                <?php elseif ($sablon === 'szolgaltatas') : ?>
                    <p class="sdh-sugo">A szolgáltatás űrlapjának <strong>Címke</strong> gombja ezzel nyomtat (árcímke a pultra, kirakatba).</p>
                <?php endif; ?>
                <div class="sdh-mezok">
                    <div class="sdh-mezo" data-sdh-matrica-folyamatos>
                        <label for="<?php echo esc_attr($idp); ?>_hossz">A <?php echo esc_html($cimke); ?> hossza (folyamatos szalagon, mm)</label>
                        <input type="number" name="<?php echo esc_attr($nev); ?>[hossz]" id="<?php echo esc_attr($idp); ?>_hossz" min="15" max="300" step="1" value="<?php echo esc_attr((string) $b['hossz']); ?>" data-sdh-matrica-figyel>
                    </div>
                    <div class="sdh-mezo">
                        <label for="<?php echo esc_attr($idp); ?>_tajolas">Tájolás</label>
                        <select name="<?php echo esc_attr($nev); ?>[tajolas]" id="<?php echo esc_attr($idp); ?>_tajolas" data-sdh-matrica-figyel>
                            <option value="allo" <?php selected($b['tajolas'], 'allo'); ?>>Álló – a sorok a szalagon keresztben</option>
                            <option value="fekvo" <?php selected($b['tajolas'], 'fekvo'); ?>>Fekvő – a sorok a szalag hosszában</option>
                        </select>
                    </div>
                    <div class="sdh-mezo">
                        <label for="<?php echo esc_attr($idp); ?>_margo">Margó (mm)</label>
                        <input type="number" name="<?php echo esc_attr($nev); ?>[margo]" id="<?php echo esc_attr($idp); ?>_margo" min="0" max="8" step="0.5" value="<?php echo esc_attr((string) $b['margo']); ?>" data-sdh-matrica-figyel>
                    </div>
                    <div class="sdh-mezo">
                        <label for="<?php echo esc_attr($idp); ?>_betu">Betűtípus</label>
                        <select name="<?php echo esc_attr($nev); ?>[betu]" id="<?php echo esc_attr($idp); ?>_betu" data-sdh-matrica-figyel>
                            <?php foreach (['dejavu' => 'DejaVu Sans keskeny', 'liberation-sans' => 'Liberation Sans (Arial-szerű)', 'carlito' => 'Carlito (Calibri-szerű)', 'liberation-serif' => 'Liberation Serif (Times-szerű)'] as $k => $n) : ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($b['betu'], $k); ?>><?php echo esc_html($n); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sdh-mezo">
                        <label for="<?php echo esc_attr($idp); ?>_meret">Alap betűméret (pont)</label>
                        <input type="number" name="<?php echo esc_attr($nev); ?>[meret]" id="<?php echo esc_attr($idp); ?>_meret" min="5" max="16" step="0.5" value="<?php echo esc_attr((string) $b['meret']); ?>" data-sdh-matrica-figyel>
                    </div>
                    <div class="sdh-mezo">
                        <label for="<?php echo esc_attr($idp); ?>_peldany">Példányszám (alapérték)</label>
                        <input type="number" name="<?php echo esc_attr($nev); ?>[peldany]" id="<?php echo esc_attr($idp); ?>_peldany" min="1" max="50" step="1" value="<?php echo (int) $b['peldany']; ?>">
                    </div>
                    <?php if ($sablon === 'rma') : ?>
                        <div class="sdh-mezo">
                            <label for="matrica_rma_mod">Az RMA a matricán</label>
                            <select name="matrica[rma_mod]" id="matrica_rma_mod" data-sdh-matrica-figyel>
                                <option value="mindketto" <?php selected($b['rma_mod'], 'mindketto'); ?>>RMA-kód és QR-kód</option>
                                <option value="kod" <?php selected($b['rma_mod'], 'kod'); ?>>Csak az RMA-kód</option>
                                <option value="qr" <?php selected($b['rma_mod'], 'qr'); ?>>Csak a QR-kód</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($sablon === 'rma') : ?>
                    <div class="sdh-mezo sdh-mezo--jelolo">
                        <input type="checkbox" name="matrica[eszkozonkent]" id="matrica_eszkozonkent" value="1" <?php checked($b['eszkozonkent']); ?>>
                        <label for="matrica_eszkozonkent">Több eszközös lapon eszközönként külön matrica</label>
                    </div>
                <?php endif; ?>
                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" name="<?php echo esc_attr($nev); ?>[keret]" id="<?php echo esc_attr($idp); ?>_keret" value="1" <?php checked($b['keret']); ?> data-sdh-matrica-figyel>
                    <label for="<?php echo esc_attr($idp); ?>_keret">Vékony keret a <?php echo esc_html($cimke); ?> szélén</label>
                </div>
            </div>

            <div class="sdh-doboz">
                <h2 class="sdh-doboz__cim"><?php echo esc_html($sablon === 'rma' ? 'Mi kerüljön a matricára' : 'Mi kerüljön a címkére'); ?></h2>
                <p class="sdh-sugo">Pipáld be, ami kell, a nyilakkal állítsd a sorrendet. A felirat a sor elején áll (pl. „Cikksz.: …”); üresen csak az érték látszik. Ha nem fér ki, a rendszer arányosan kisebb betűvel írja.</p>
                <div class="sdh-matrica-mezok" data-sdh-matrica-mezok>
                    <?php foreach ($b['mezok'] as $k => $m) : ?>
                        <?php if (!isset($mz[$k])) { continue; } ?>
                        <div class="sdh-matrica-mezo<?php echo $m['be'] ? ' is-be' : ''; ?>" data-sdh-matrica-mezo="<?php echo esc_attr($k); ?>">
                            <input type="hidden" name="<?php echo esc_attr($nev); ?>[sorrend][]" value="<?php echo esc_attr($k); ?>">
                            <label class="sdh-matrica-mezo__be">
                                <input type="checkbox" name="<?php echo esc_attr($nev); ?>[mezok][<?php echo esc_attr($k); ?>][be]" value="1" <?php checked($m['be']); ?> data-sdh-matrica-figyel>
                                <span><?php echo esc_html($mz[$k]['cim']); ?></span>
                            </label>
                            <input type="text" class="sdh-matrica-mezo__felirat" name="<?php echo esc_attr($nev); ?>[mezok][<?php echo esc_attr($k); ?>][felirat]" maxlength="20" placeholder="felirat" aria-label="Felirat" value="<?php echo esc_attr($m['felirat']); ?>" data-sdh-matrica-figyel>
                            <select name="<?php echo esc_attr($nev); ?>[mezok][<?php echo esc_attr($k); ?>][meret]" aria-label="Betűméret" data-sdh-matrica-figyel>
                                <?php foreach ($meretek as $sz => $mn) : ?>
                                    <option value="<?php echo esc_attr($sz); ?>" <?php selected(abs((float) $sz - (float) $m['meret']) < 0.01); ?>><?php echo esc_html($mn); ?></option>
                                <?php endforeach; ?>
                                <?php if (!array_filter(array_keys($meretek), static fn ($sz) => abs((float) $sz - (float) $m['meret']) < 0.01)) : ?>
                                    <option value="<?php echo esc_attr((string) $m['meret']); ?>" selected><?php echo esc_html((string) $m['meret'] . '×'); ?></option>
                                <?php endif; ?>
                            </select>
                            <label class="sdh-matrica-mezo__fk" title="Félkövér">
                                <input type="checkbox" name="<?php echo esc_attr($nev); ?>[mezok][<?php echo esc_attr($k); ?>][felkover]" value="1" <?php checked($m['felkover']); ?> data-sdh-matrica-figyel><span>F</span>
                            </label>
                            <span class="sdh-matrica-mezo__nyilak">
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-matrica-fel aria-label="Feljebb">▲</button>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-matrica-le aria-label="Lejjebb">▼</button>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sdh-doboz sdh-matrica-elonezet">
                <h2 class="sdh-doboz__cim">Előnézet <span class="sdh-matrica-elonezet__meret" data-sdh-matrica-meret></span></h2>
                <p class="sdh-sugo">Kitalált adatokkal, a még el nem mentett beállításokkal. Így néz ki a nyomtató felbontásában (300 dpi, fekete-fehér).</p>
                <div class="sdh-matrica-elonezet__keret"><img alt="A címke előnézete" data-sdh-matrica-kep hidden></div>
                <p class="sdh-matrica-elonezet__jel" data-sdh-matrica-jel role="status" aria-live="polite"></p>
                <p><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-matrica-proba>Próbanyomat</button></p>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['matrica_jelen'])) {
            return;
        }

        update_option(self::OPT, self::urlap_beallitas('rma'), false);

        foreach (array_keys(self::SABLONOK) as $s) {
            if ($s !== 'rma' && !empty($_POST['matrica_' . $s . '_jelen'])) {
                // A közös részt (nyomtató, tekercs) nem tároljuk kétszer: mindig az RMA-matricáé.
                update_option(self::opt($s), array_diff_key(self::urlap_beallitas($s), array_flip(self::KOZOS)), false);
            }
        }
        // phpcs:enable
    }
}
