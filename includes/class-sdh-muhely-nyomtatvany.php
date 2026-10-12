<?php
/**
 * Nyomtatványok – a rajzolás (0.52).
 *
 * Itt készül minden nyomtatvány PDF-je: az adatok összeszedése és a lap
 * megrajzolása. A beállításokat (általános megjelenés, nyomtatványonkénti
 * sablon) az SDH_Muhely_Nyomtatas adja; a rajz építőelemei az
 * includes/lib/sdh-nyomtatvany-pdf.php-ben vannak.
 *
 * Egy forrás, három felhasználás: ugyanaz a PDF megy az előnézetbe, a
 * nyomtatóra, a letöltésbe és az e-mail csatolmányába.
 *
 * Példányszám: a PDF annyiszor tartalmazza a nyomtatványt, ahány példányt
 * kértek; az oldalszámozás példányonként újraindul.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Nyomtatvany
{
    /** Ennél több sor nem kerül egy listába (a PDF kezelhető marad). */
    private const LISTA_MAX = 3000;

    /* =================================================================
     * Belépési pont
     * ============================================================== */

    /**
     * @param array<string, mixed> $p  a nyomtatvány paraméterei (id, eszkoz, datum, tol, ig, szuro, minta…)
     * @return array{pdf: string, nev: string, cim: string, szam: string, oldal: int, adat: array<string, mixed>}|string  hibánál szöveg
     */
    public static function keszit(string $tipus, array $p = [], int $peldany = 1)
    {
        if (!class_exists('SDH_Muhely_Nyomtatas')) {
            return 'A nyomtatás modul nincs betöltve.';
        }

        $tipusok = SDH_Muhely_Nyomtatas::tipusok();

        if (!isset($tipusok[$tipus])) {
            return 'Ismeretlen nyomtatvány.';
        }

        $t = $tipusok[$tipus];
        $s = SDH_Muhely_Nyomtatas::sablon($tipus);
        $a = SDH_Muhely_Nyomtatas::altalanos();

        // A sablonszerkesztő előnézete: a még el nem mentett értékekkel rajzol.
        if (isset($p['_sablon']) && is_array($p['_sablon'])) {
            $s = array_merge($s, $p['_sablon']);
        }

        if (isset($p['_altalanos']) && is_array($p['_altalanos'])) {
            $a = array_merge($a, $p['_altalanos']);
        }

        $d = self::adat($tipus, $t, $p);

        if (is_string($d)) {
            return $d;
        }

        require_once SDH_MUHELY_DIR . 'includes/lib/sdh-nyomtatvany-pdf.php';

        @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        $peldany = min(9, max(1, $peldany));
        $cim     = (string) $s['cim'];
        $szam    = (string) ($d['szam'] ?? '');

        try {
            // 1. menet: hány oldal egy példány (az „Oldal 1/2" miatt).
            $proba = self::uj_pdf($t, $s, $a, $cim, $szam);
            $proba->AddPage();
            self::rajzol($proba, $tipus, $t, $s, $a, $d);
            $oldal = $proba->PageNo();
            unset($proba);

            // 2. menet: a kért példányszámmal.
            $pdf             = self::uj_pdf($t, $s, $a, $cim, $szam);
            $pdf->oldal_ossz = $oldal;

            for ($i = 1; $i <= $peldany; $i++) {
                // Előbb az új oldal: az AddPage rajzolja meg az ELŐZŐ oldal láblécét, még a régi példány számaival.
                $pdf->AddPage();
                $pdf->oldal_eltolas   = $pdf->PageNo() - 1;
                $pdf->peldany_felirat = $peldany > 1 ? $i . '. példány' : '';
                self::rajzol($pdf, $tipus, $t, $s, $a, $d);
            }

            $ki = (string) $pdf->Output('S');
        } catch (Throwable $hiba) {
            return 'A nyomtatvány nem készült el: ' . $hiba->getMessage();
        }

        $nev = sanitize_file_name(remove_accents(trim($cim . ' ' . $szam)));
        $nev = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $nev), '-');

        return [
            'pdf'   => $ki,
            'nev'   => ($nev !== '' ? $nev : 'nyomtatvany') . '.pdf',
            'cim'   => $cim,
            'szam'  => $szam,
            'oldal' => $oldal,
            'adat'  => $d,
        ];
    }

    /** @param array<string, mixed> $t */
    private static function uj_pdf(array $t, array $s, array $a, string $cim, string $szam): SDH_Muhely_Nyomtatvany_Pdf
    {
        $papir = (string) ($s['papir'] ?? '') !== '' ? (string) $s['papir'] : (string) $a['papir'];
        $papir = $papir === 'A5' ? 'A5' : 'A4';
        $fekvo = !empty($t['fekvo']) && (!isset($s['fekvo']) || !empty($s['fekvo']));

        $pdf = new SDH_Muhely_Nyomtatvany_Pdf($fekvo ? 'L' : 'P', $papir, [
            'betu'  => (string) $a['betu'],
            // A5-ön az alapméret arányosan kisebb, hogy ugyanaz a tartalom elférjen.
            'meret' => (float) $a['meret'] * ($papir === 'A5' ? 0.88 : 1.0),
            'margo' => $papir === 'A5' ? min(10.0, (float) $a['margo']) : (float) $a['margo'],
            'szin'  => SDH_Muhely_Nyomtatas::szin_rgb((string) $a['szin']),
            'lab'   => SDH_Muhely_Nyomtatas::lab_sorok($a),
        ]);

        $ceg = SDH_Muhely_Nyomtatas::ceg_sorok($a);

        $pdf->SetTitle(trim($cim . ' ' . $szam), true);
        $pdf->SetAuthor((string) ($ceg[0] ?? get_bloginfo('name')), true);
        $pdf->lab_bal = trim($cim . ' ' . $szam) . '  ·  nyomtatva: ' . wp_date('Y. m. d. H:i');

        return $pdf;
    }

    /* =================================================================
     * Segédek
     * ============================================================== */

    public static function ft(float $osszeg, bool $penznem = true): string
    {
        $o = round($osszeg);

        // A −0 ne jelenjen meg.
        return number_format($o == 0 ? 0 : $o, 0, ',', ' ') . ($penznem ? ' Ft' : '');
    }

    public static function nap(?string $iso): string
    {
        $iso = (string) $iso;

        if ($iso === '' || strncmp($iso, '0000', 4) === 0) {
            return '';
        }

        $ido = strtotime($iso);

        return $ido ? date('Y. m. d.', $ido) : '';
    }

    private static function menny(float $m, string $me): string
    {
        $szam = class_exists('SDH_Muhely_Termek') ? SDH_Muhely_Termek::menny($m) : rtrim(rtrim(number_format($m, 3, ',', ' '), '0'), ',');

        return trim($szam . ' ' . $me);
    }

    /** A {jelölők} behelyettesítése a sablonszövegekben. */
    public static function helyettesit(string $szoveg, array $d, string $cim = ''): string
    {
        if ($szoveg === '' || strpos($szoveg, '{') === false) {
            return $szoveg;
        }

        $a   = SDH_Muhely_Nyomtatas::altalanos();
        $ceg = SDH_Muhely_Nyomtatas::ceg_sorok($a);

        return strtr($szoveg, [
            '{cim}'       => $cim,
            '{szam}'      => (string) ($d['szam'] ?? ''),
            '{ugyfel}'    => (string) ($d['ugyfel_nev'] ?? ''),
            '{eszkoz}'    => (string) ($d['eszkoz_nev'] ?? ''),
            '{datum}'     => wp_date('Y. m. d.'),
            '{hatarido}'  => (string) ($d['hatarido'] ?? ''),
            '{vegosszeg}' => isset($d['brutto']) ? self::ft((float) $d['brutto']) : '',
            '{fizetendo}' => isset($d['fizetendo']) ? self::ft((float) $d['fizetendo']) : '',
            '{eloleg}'    => isset($d['fizetett']) ? self::ft((float) $d['fizetett']) : '',
            '{ervenyes}'  => (string) ($d['ervenyes'] ?? ''),
            '{ceg}'       => (string) ($ceg[0] ?? get_bloginfo('name')),
            '{allapot}'   => (string) ($d['allapot_nev'] ?? ''),
        ]);
    }

    /** A közös fejléc. @param array<int, array{0: string, 1: string}> $adatok */
    private static function fej(SDH_Muhely_Nyomtatvany_Pdf $pdf, array $a, string $cim, array $adatok): void
    {
        $pdf->fej([
            'logo'      => !empty($a['fej_logo']) ? SDH_Muhely_Nyomtatas::logo_fajl($a) : '',
            'logo_szel' => (float) $a['logo_szel'],
            'logo_hely' => (string) $a['logo_hely'],
            'ceg'       => !empty($a['fej_ceg']) ? SDH_Muhely_Nyomtatas::ceg_sorok($a) : [],
            'cim'       => $cim,
            'adatok'    => $adatok,
        ]);
    }

    private static function kelt(array $a): string
    {
        $hely = trim((string) $a['kelt_hely']);

        return 'Kelt: ' . ($hely !== '' ? $hely . ', ' : '') . wp_date('Y. m. d.');
    }

    /* =================================================================
     * Adatok
     * ============================================================== */

    /**
     * @param array<string, mixed> $t
     * @param array<string, mixed> $p
     * @return array<string, mixed>|string
     */
    private static function adat(string $tipus, array $t, array $p)
    {
        switch ((string) $t['adat']) {
            case 'lap':
                return !empty($p['minta']) ? self::minta_lap($tipus) : self::lap_adat((int) ($p['id'] ?? 0), $p);
            case 'bianko':
                return ['szam' => ''];
            case 'zaras':
                return self::zaras_adat((string) ($p['datum'] ?? ''));
            case 'riport':
                return self::riport_adat((string) ($p['tol'] ?? ''), (string) ($p['ig'] ?? ''));
            case 'ptetel':
                return self::ptetel_adat((int) ($p['id'] ?? 0), !empty($p['minta']));
            case 'lista_munkalap':
                return self::lista_munkalap_adat($p);
            case 'lista_keszlet':
                return self::lista_keszlet_adat($p);
            case 'lista_szamla':
                return self::lista_szamla_adat($p);
        }

        return 'Ehhez a nyomtatványhoz nincs adatforrás.';
    }

    /**
     * Egy munkalap (javítás, árukiadás, rendelés) minden adata a nyomtatványhoz.
     *
     * @param array<string, mixed> $p  eszkoz: csak ez az eszköz (a bontás kulcsa: 0 = a fő eszköz); '' = mind
     * @return array<string, mixed>|string
     */
    public static function lap_adat(int $id, array $p = [])
    {
        global $wpdb;

        $m = $id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $id))
            : null;

        if (!is_object($m)) {
            return 'Nincs ilyen munkalap.';
        }

        $u = (int) $m->ugyfel_id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', (int) $m->ugyfel_id))
            : null;
        $u = is_object($u) ? $u : null;

        $bontas = SDH_Muhely_Munkalap::eszkoz_bontas($m);
        $tobb   = count($bontas) > 1;
        $szuro  = isset($p['eszkoz']) && (string) $p['eszkoz'] !== '' ? (int) $p['eszkoz'] : null;

        if ($szuro !== null && $tobb) {
            $szurt = array_values(array_filter($bontas, static fn (array $b): bool => (int) $b['eszkoz_id'] === $szuro));

            if ($szurt !== []) {
                $bontas = $szurt;
            } else {
                $szuro = null;
            }
        } else {
            $szuro = null;
        }

        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $fajta     = SDH_Muhely_Munkalap::fajta_ervenyes((string) ($m->fajta ?? ''));
        $szam      = SDH_Muhely_Munkalap::szam_formaz($m->munkalap_szam);
        $szam      = $szam === '—' ? '#' . (int) $m->id : $szam;

        if ($szuro !== null) {
            // Egy eszköz a több eszközös lapról: a számok az eszközéi.
            $b       = $bontas[0];
            $szam   .= '/' . (int) $b['sorszam'];
            $netto   = (float) $b['netto'];
            $brutto  = (float) $b['brutto'];
            $fizetett = (float) $b['eloleg'];
            $fizetve = (bool) $b['fizetve'];
            $fizetendo = (float) $b['fizetendo'];
        } else {
            $netto    = (float) $m->netto_ertek;
            $brutto   = (float) $m->brutto_ertek;
            $fizetett = (float) $m->fizetett;
            $fizetve  = (int) $m->fizetve === 1;
            $fizetendo = $fizetve ? 0.0 : max(0.0, round($brutto - $fizetett, 2));
        }

        $helyszin = '';

        if ((int) ($m->helyszin_id ?? 0) > 0 && class_exists('SDH_Muhely_Eladas')) {
            $helyszin = (string) (SDH_Muhely_Eladas::torzs_nevek('helyszin')[(int) $m->helyszin_id] ?? '');
        }

        $nevek = array_values(array_filter(array_map(
            static fn (array $b): string => $b['eszkoz'] ? (string) $b['nev'] : '',
            $bontas
        )));

        return [
            'munkalap'    => $m,
            'ugyfel'      => $u,
            'ugyfel_nev'  => $u ? (string) $u->nev : (string) $m->nev,
            'ugyfel_email' => $u ? (string) $u->email : '',
            'bontas'      => $bontas,
            'tobb'        => count($bontas) > 1,
            'fajta'       => $fajta,
            'szam'        => $szam,
            'eszkoz_nev'  => implode(', ', $nevek),
            'felveve'     => self::nap((string) ($m->keszult ?: $m->letrehozva)),
            'hatarido'    => self::nap((string) $m->hatarido),
            'lezarva'     => self::nap((string) $m->lezarva),
            'felelos'     => SDH_Muhely_Munkalap::felelos_nev((int) $m->felelos),
            'allapot_nev' => (string) ($allapotok[(string) $m->allapot]['nev'] ?? $m->allapot),
            'helyszin'    => $helyszin,
            'netto'       => $netto,
            'brutto'      => $brutto,
            'fizetett'    => $fizetett,
            'fizetve'     => $fizetve,
            'fizetendo'   => $fizetendo,
            'fizmod'      => (string) $m->fizetesi_mod,
            'fizetes_ideje' => self::nap((string) $m->fizetes_ideje),
            'kedvezmeny'  => (float) $m->kedvezmeny,
            'megjegyzes'  => trim((string) $m->ugyfel_megjegyzes),
            'belso'       => trim((string) $m->megjegyzes),
        ];
    }

    /** Kitalált adatok a sablonszerkesztő próbanyomatához (nincs benne valódi ügyfél). */
    private static function minta_lap(string $tipus): array
    {
        $eladas = in_array($tipus, ['eladas', 'rendeles'], true);

        $u = (object) [
            'nev' => 'Minta Ügyfél', 'kapcsolattarto' => '', 'adoszam' => '', 'telefon' => '+36 30 000 0000', 'telefon2' => '',
            'email' => 'minta.ugyfel@pelda.hu', 'tipus' => 'maganszemely',
            'szamlazasi_iranyitoszam' => '1000', 'szamlazasi_telepules' => 'Mintaváros', 'szamlazasi_cim' => 'Példa utca 1.',
            'szallitasi_azonos' => 1, 'szallitasi_iranyitoszam' => '', 'szallitasi_telepules' => '', 'szallitasi_cim' => '',
        ];

        $e = (object) [
            'id' => 1, 'kategoria' => 'telefon', 'gyarto' => 'Minta', 'tipus' => 'Telefon X1', 'megnevezes' => '', 'imei' => '350000000000006',
            'imei2' => '', 'sorozatszam' => 'SN-000001', 'szin' => 'fekete', 'zarkod' => '1234', 'minta' => '', 'tartozekok' => 'tok, töltő',
            'atveteli_allapot' => 'A kijelző jobb alsó sarka repedt, a hátlap karcos.', 'belso_megjegyzes' => 'Belső megjegyzés az eszközről.',
            'garancias' => 0, 'garancia_lejar' => null,
        ];

        $tetel = static fn (string $tipus, string $nev, float $menny, float $ar, string $forras = 'kezi'): object => (object) [
            'tipus' => $tipus, 'mozgas' => 'kimeno', 'megnevezes' => $nev, 'mennyiseg' => $menny, 'me' => 'db', 'kedvezmeny' => 0,
            'brutto_ar' => $ar, 'brutto_ertek' => $ar * $menny, 'netto_ertek' => round($ar * $menny / 1.27, 2), 'gyari_szam' => '', 'cikkszam' => '',
            'forras' => $forras, 'afa' => 27,
        ];

        $tetelek = [
            $tetel('szolgaltatas', 'Kijelzőcsere munkadíj', 1, 12000),
            $tetel('termek', 'Kijelző – Telefon X1', 1, 28000),
            $tetel('termek', 'Kijelzővédő fólia', 2, 2500),
        ];

        $brutto = 45000.0;

        $m = (object) [
            'id' => 0, 'munkalap_szam' => 1, 'allapot' => '', 'fajta' => $tipus === 'rendeles' ? 'rendeles' : ($tipus === 'eladas' ? 'arukiadas' : 'javitas'),
            'rma_token' => '', 'rend_szam' => '', 'kedvezmeny' => 0, 'ugyfel_megjegyzes' => '', 'megjegyzes' => '',
        ];

        return [
            'munkalap'    => $m,
            'minta'       => true,
            'ugyfel'      => $u,
            'ugyfel_nev'  => 'Minta Ügyfél',
            'ugyfel_email' => '',
            'bontas'      => [[
                'eszkoz_id' => 0, 'valodi_id' => 1, 'eszkoz' => $eladas ? null : $e, 'sorszam' => 1, 'nev' => 'Minta Telefon X1', 'felirat' => '1. Minta Telefon X1',
                'hibak' => $eladas ? [] : [
                    (object) ['leiras' => 'Törött kijelző', 'javitas' => 'Kijelző cserélve, tesztelve.', 'allapot' => ''],
                    (object) ['leiras' => 'Gyorsan merül', 'javitas' => '', 'allapot' => ''],
                ],
                'tetelek' => $tetelek, 'netto' => round($brutto / 1.27, 2), 'brutto' => $brutto, 'dij' => 0.0, 'eloleg' => 5000.0, 'fizetendo' => 40000.0,
                'allapot' => '', 'kesz' => false, 'fizetve' => false, 'fizetes_ideje' => '',
            ]],
            'tobb'        => false,
            'fajta'       => (string) $m->fajta,
            'szam'        => 'MINTA-0001',
            'eszkoz_nev'  => 'Minta Telefon X1',
            'felveve'     => wp_date('Y. m. d.'),
            'hatarido'    => wp_date('Y. m. d.', time() + 3 * DAY_IN_SECONDS),
            'lezarva'     => '',
            'felelos'     => 'Minta Kolléga',
            'allapot_nev' => 'Felvéve',
            'helyszin'    => '',
            'netto'       => round($brutto / 1.27, 2),
            'brutto'      => $brutto,
            'fizetett'    => 5000.0,
            'fizetve'     => false,
            'fizetendo'   => 40000.0,
            'fizmod'      => '',
            'fizetes_ideje' => '',
            'kedvezmeny'  => 0.0,
            'megjegyzes'  => 'Az ügyfél a készülékét időpontra hozza.',
            'belso'       => 'Ez a belső megjegyzés csak a belső munkalapon látszik.',
        ];
    }

    /* =================================================================
     * Rajz – elosztó
     * ============================================================== */

    private static function rajzol(SDH_Muhely_Nyomtatvany_Pdf $pdf, string $tipus, array $t, array $s, array $a, array $d): void
    {
        switch ((string) $t['adat']) {
            case 'lap':
                self::rajz_lap($pdf, $tipus, $s, $a, $d);
                break;
            case 'bianko':
                self::rajz_bianko($pdf, $s, $a);
                break;
            case 'zaras':
                self::rajz_zaras($pdf, $s, $a, $d);
                break;
            case 'riport':
                self::rajz_riport($pdf, $s, $a, $d);
                break;
            case 'ptetel':
                self::rajz_ptetel($pdf, $s, $a, $d);
                break;
            default:
                self::rajz_lista($pdf, $s, $a, $d);
        }
    }

    /* =================================================================
     * A munkalap családja: átvételi, munkalap, belső, árajánlat,
     * szállítólevél, átadási bizonylat, megrendelés
     * ============================================================== */

    private static function rajz_lap(SDH_Muhely_Nyomtatvany_Pdf $pdf, string $tipus, array $s, array $a, array $d): void
    {
        $m      = $d['munkalap'];
        $u      = $d['ugyfel'];
        $bontas = $d['bontas'];
        $cim    = (string) $s['cim'];
        $arak   = !empty($s['arak']);
        $keskeny = $pdf->szeles() < 150;

        if ($tipus === 'arajanlat' && (int) $s['ervenyes_nap'] > 0) {
            $d['ervenyes'] = wp_date('Y. m. d.', time() + (int) $s['ervenyes_nap'] * DAY_IN_SECONDS);
        }

        // --- Fej ---------------------------------------------------------
        $adatok = [['Sorszám:', (string) $d['szam']]];

        if ((string) $d['felveve'] !== '') {
            $adatok[] = [$d['fajta'] === 'javitas' ? 'Felvéve:' : 'Dátum:', (string) $d['felveve']];
        }

        if (!empty($s['hatarido']) && (string) $d['hatarido'] !== '') {
            $adatok[] = ['Határidő:', (string) $d['hatarido']];
        }

        if (!empty($d['ervenyes'])) {
            $adatok[] = ['Érvényes:', (string) $d['ervenyes']];
        }

        if (!empty($s['allapot']) && (string) $d['allapot_nev'] !== '') {
            $adatok[] = ['Állapot:', (string) $d['allapot_nev']];
        }

        if (!empty($s['felelos']) && (string) $d['felelos'] !== '') {
            $adatok[] = ['Felelős:', (string) $d['felelos']];
        }

        if ((string) $d['helyszin'] !== '' && $d['fajta'] !== 'javitas') {
            $adatok[] = ['Helyszín:', (string) $d['helyszin']];
        }

        if ($d['fajta'] === 'rendeles' && trim((string) ($m->rend_szam ?? '')) !== '' && !empty($s['belso'])) {
            $adatok[] = ['Rendelésszám:', (string) $m->rend_szam];
        }

        self::fej($pdf, $a, $cim, $adatok);

        $pdf->bekezdes(self::helyettesit((string) $s['bevezeto'], $d, $cim));

        // --- Ügyfél ------------------------------------------------------
        if (!empty($s['ugyfel']) && ($u !== null || trim((string) $d['ugyfel_nev']) !== '')) {
            $pdf->szakasz($tipus === 'szallito' ? 'Címzett' : ($d['fajta'] === 'javitas' ? 'Ügyfél' : 'Vevő'));

            $parok = [['c' => 'Név', 'e' => (string) $d['ugyfel_nev'], 'b' => true]];

            if ($u !== null) {
                $telefon = implode(', ', array_filter([trim((string) $u->telefon), trim((string) ($u->telefon2 ?? ''))]));
                $cimsor  = SDH_Muhely_Ugyfel::cim_szoveg($u);

                $parok[] = ['c' => 'Telefon', 'e' => $telefon];
                $parok[] = ['c' => 'Cím', 'e' => $cimsor];
                $parok[] = ['c' => 'E-mail', 'e' => (string) $u->email];

                if (trim((string) ($u->adoszam ?? '')) !== '') {
                    $parok[] = ['c' => 'Adószám', 'e' => (string) $u->adoszam];
                }

                if (trim((string) ($u->kapcsolattarto ?? '')) !== '') {
                    $parok[] = ['c' => 'Kapcsolattartó', 'e' => (string) $u->kapcsolattarto];
                }

                if ($tipus === 'szallito' && (int) ($u->szallitasi_azonos ?? 1) !== 1) {
                    $szall = SDH_Muhely_Ugyfel::cim_szoveg($u, 'szallitasi');

                    if ($szall !== '') {
                        $parok[] = ['c' => 'Szállítási cím', 'e' => $szall, 'sz' => 2, 'b' => true];
                    }
                }
            }

            $pdf->parok(array_values(array_filter($parok, static fn (array $p): bool => trim((string) $p['e']) !== '')), 2);
        }

        // --- Eszközök: mindegyik a saját hibáival és tételeivel ----------
        $van_tetel = false;

        foreach ($bontas as $b) {
            $e = $b['eszkoz'];

            if (!empty($s['eszkoz']) && is_object($e)) {
                $pdf->szakasz($d['tobb'] ? (string) $b['felirat'] : 'Eszköz');
                $pdf->parok(self::eszkoz_parok($e, $s, $keskeny), $keskeny ? 2 : 3);
            } elseif ($d['tobb']) {
                $pdf->szakasz((string) $b['felirat']);
            }

            if (!empty($s['hibak']) && $b['hibak'] !== []) {
                self::hibak($pdf, $b['hibak'], $s);
            }

            if (!empty($s['tetelek'])) {
                $van_tetel = self::tetelek($pdf, $b['tetelek'], $arak, !empty($s['tervezett'])) || $van_tetel;

                // Több eszköznél eszközönként részösszeg.
                if ($d['tobb'] && $arak && !empty($s['osszesito']) && (float) $b['brutto'] != 0.0) {
                    $pdf->osszesito([['c' => 'Az eszköz tételei összesen:', 'e' => self::ft((float) $b['brutto']), 'b' => true]]);
                }
            }
        }

        // --- Összesítő ---------------------------------------------------
        if (!empty($s['osszesito']) && $arak && ($van_tetel || (float) $d['brutto'] != 0.0)) {
            $sorok = [];

            if ((float) $d['kedvezmeny'] > 0) {
                $sorok[] = ['c' => 'Kedvezmény a lapon:', 'e' => rtrim(rtrim(number_format((float) $d['kedvezmeny'], 2, ',', ''), '0'), ',') . ' %'];
            }

            $sorok[] = ['c' => 'Nettó összesen:', 'e' => self::ft((float) $d['netto'])];
            $sorok[] = ['c' => 'Áfa:', 'e' => self::ft((float) $d['brutto'] - (float) $d['netto'])];

            if ($tipus === 'arajanlat') {
                $sorok[] = ['c' => 'Az ajánlat végösszege:', 'e' => self::ft((float) $d['brutto']), 'ki' => true];
            } else {
                $sorok[] = ['c' => 'Bruttó végösszeg:', 'e' => self::ft((float) $d['brutto']), 'b' => true];

                if ((float) $d['fizetett'] > 0 && !$d['fizetve']) {
                    $sorok[] = ['c' => 'Eddig befizetve:', 'e' => self::ft((float) $d['fizetett'])];
                }

                if ($d['fizetve']) {
                    $sorok[] = ['c' => 'Fizetve' . ((string) $d['fizmod'] !== '' ? ' (' . mb_strtolower((string) $d['fizmod'], 'UTF-8') . ')' : '') . ':', 'e' => (string) $d['fizetes_ideje'] !== '' ? (string) $d['fizetes_ideje'] : 'igen'];
                }

                $sorok[] = ['c' => 'Fizetendő:', 'e' => self::ft((float) $d['fizetendo']), 'ki' => true];
            }

            $pdf->osszesito($sorok);
        } elseif (!empty($s['eloleg']) && (float) $d['fizetett'] > 0) {
            // Ár nélküli nyomtatványon (átvételi) is látszik, amit az ügyfél már befizetett.
            $pdf->osszesito([['c' => 'Befizetett előleg / díj:', 'e' => self::ft((float) $d['fizetett']), 'b' => true]]);
        }

        // --- Megjegyzések ------------------------------------------------
        if (!empty($s['megjegyzes']) && (string) $d['megjegyzes'] !== '') {
            $pdf->szakasz('Megjegyzés');
            $pdf->bekezdes((string) $d['megjegyzes']);
        }

        if (!empty($s['belso']) && (string) $d['belso'] !== '') {
            $pdf->szakasz('Belső megjegyzés');
            $pdf->bekezdes((string) $d['belso']);
        }

        // --- QR: az ügyféloldal (a javítás követése) ---------------------
        if (!empty($s['qr']) && $d['fajta'] === 'javitas') {
            self::qr_blokk($pdf, $d, (string) $s['qr_szoveg']);
        }

        // --- Tudnivalók (apró betű) --------------------------------------
        if (trim((string) $s['jogi']) !== '') {
            $pdf->szakasz(trim((string) $s['jogi_cim']) !== '' ? (string) $s['jogi_cim'] : 'Tudnivalók');
            $pdf->bekezdes(self::helyettesit((string) $s['jogi'], $d, $cim), 0.8, 'fo');
        }

        // --- Záró szöveg, kelt, aláírás ----------------------------------
        $zaro = self::helyettesit((string) $s['zaro'], $d, $cim);

        if (trim($zaro) !== '') {
            $pdf->Ln(1.5);
            $pdf->bekezdes($zaro);
        }

        if (!empty($s['alairas'])) {
            $pdf->alairasok(
                [(string) $s['alairas_bal'], (string) $s['alairas_jobb']],
                self::kelt($a),
                !empty($s['igazolvany']) ? ['Átvevő neve', 'Igazolványszám'] : [],
                !empty($s['ph'])
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function eszkoz_parok(object $e, array $s, bool $keskeny = false): array
    {
        $azonosito = implode(' / ', array_filter([
            trim((string) ($e->imei ?? '')), trim((string) ($e->imei2 ?? '')), trim((string) ($e->sorozatszam ?? '')),
        ]));

        $garancia = (int) ($e->garancias ?? 0) === 1
            ? 'Igen' . (self::nap((string) ($e->garancia_lejar ?? '')) !== '' ? ' (lejár: ' . self::nap((string) $e->garancia_lejar) . ')' : '')
            : 'Nem';

        $szin = ['c' => 'Szín', 'e' => (string) ($e->szin ?? '')];
        $imei = ['c' => 'IMEI / gyári szám', 'e' => $azonosito, 'sz' => 2];

        // Három oszlopban: megnevezés, fajta, szín | IMEI (két oszlop), garancia.
        // Keskeny lapon (A5, két oszlop): megnevezés, fajta | IMEI | szín, garancia – így nem marad üres fél sor.
        $parok = array_merge(
            [
                ['c' => 'Megnevezés', 'e' => SDH_Muhely_Eszkoz::megnevezes($e), 'b' => true],
                ['c' => 'Fajta', 'e' => SDH_Muhely_Eszkoz::kategoria_cimke((string) ($e->kategoria ?? ''))],
            ],
            $keskeny ? [$imei, $szin] : [$szin, $imei],
            [['c' => 'Garanciális', 'e' => $garancia]]
        );

        if (!empty($s['tartozek'])) {
            if (trim((string) ($e->tartozekok ?? '')) !== '') {
                $parok[] = ['c' => 'Tartozékok', 'e' => (string) $e->tartozekok, 'sz' => 3];
            }

            if (trim((string) ($e->atveteli_allapot ?? '')) !== '') {
                $parok[] = ['c' => 'Állapot átvételkor', 'e' => (string) $e->atveteli_allapot, 'sz' => 3];
            }
        }

        if (!empty($s['zarkod'])) {
            $kod = implode('  ·  ', array_filter([
                trim((string) ($e->zarkod ?? '')) !== '' ? 'kód: ' . trim((string) $e->zarkod) : '',
                trim((string) ($e->minta ?? '')) !== '' ? 'minta: ' . trim((string) $e->minta) : '',
            ]));

            if ($kod !== '') {
                $parok[] = ['c' => 'Zárkód / feloldóminta', 'e' => $kod, 'sz' => 3, 'b' => true];
            }
        }

        if (!empty($s['belso']) && trim((string) ($e->belso_megjegyzes ?? '')) !== '') {
            $parok[] = ['c' => 'Belső megjegyzés az eszközről', 'e' => (string) $e->belso_megjegyzes, 'sz' => 3];
        }

        // Ami nincs kitöltve (pl. szín), az nem foglal helyet.
        return array_values(array_filter($parok, static fn (array $p): bool => trim((string) $p['e']) !== ''));
    }

    /** @param array<int, object> $hibak */
    private static function hibak(SDH_Muhely_Nyomtatvany_Pdf $pdf, array $hibak, array $s): void
    {
        $allapotok = SDH_Muhely_Munkalap::hiba_allapotok();
        $sorok     = [];

        foreach (array_values($hibak) as $i => $h) {
            $sorok[] = [
                'c'  => [(string) ($i + 1), (string) $h->leiras, (string) ($allapotok[(string) $h->allapot]['nev'] ?? $h->allapot)],
                'al'   => !empty($s['javitas']) ? trim((string) ($h->javitas ?? '')) : '',
                'al_o' => 1,   // a javítás szövege a hiba leírása alá kerül, nem a sorszám alá
            ];
        }

        $pdf->tabla(
            array_merge(
                [['c' => '#', 'sz' => 0.06, 'ig' => 'L'], ['c' => !empty($s['javitas']) ? 'Hiba / elvégzett javítás' : 'Bejelentett hiba', 'sz' => !empty($s['hiba_allapot']) ? 0.69 : 0.94]],
                !empty($s['hiba_allapot']) ? [['c' => 'Állapot', 'sz' => 0.25, 'ig' => 'R']] : []
            ),
            $sorok
        );
    }

    /**
     * A tételek két táblában: szolgáltatások és termékek. Csak a kimenő tételek.
     *
     * @param array<int, object> $tetelek
     */
    private static function tetelek(SDH_Muhely_Nyomtatvany_Pdf $pdf, array $tetelek, bool $arak, bool $tervezett = true): bool
    {
        $volt = false;

        foreach (['szolgaltatas' => 'Szolgáltatások', 'termek' => 'Termékek'] as $tipus => $felirat) {
            $lista = array_values(array_filter($tetelek, static fn (object $t): bool => (string) $t->tipus === $tipus
                && (string) ($t->mozgas ?? 'kimeno') === 'kimeno'
                && ($tervezett || (string) ($t->allapot ?? 'teljesitett') !== 'tervezett')
                // Ár nélküli papíron a bevizsgálási díj és a levonása csak zavarna: azok pénzügyi sorok.
                && ($arak || !in_array((string) ($t->forras ?? ''), ['bevizsgalas', 'bevizsgalas_le'], true))));

            if ($lista === []) {
                continue;
            }

            $volt     = true;
            $van_kedv = (bool) array_filter($lista, static fn (object $t): bool => (float) ($t->kedvezmeny ?? 0) > 0);
            $sorok    = [];

            foreach ($lista as $t) {
                $al = implode('  ·  ', array_filter([
                    trim((string) ($t->cikkszam ?? '')) !== '' ? 'cikkszám: ' . trim((string) $t->cikkszam) : '',
                    trim((string) ($t->gyari_szam ?? '')) !== '' ? 'gyári szám: ' . trim((string) $t->gyari_szam) : '',
                ]));

                $cellak = [(string) $t->megnevezes, self::menny((float) $t->mennyiseg, (string) ($t->me ?? 'db'))];

                if ($arak) {
                    $cellak[] = self::ft((float) $t->brutto_ar, false);

                    if ($van_kedv) {
                        $cellak[] = (float) $t->kedvezmeny > 0 ? rtrim(rtrim(number_format((float) $t->kedvezmeny, 2, ',', ''), '0'), ',') . ' %' : '';
                    }

                    $cellak[] = self::ft((float) $t->brutto_ertek, false);
                }

                $sorok[] = ['c' => $cellak, 'al' => $al];
            }

            if ($arak) {
                $oszlopok = array_merge(
                    [['c' => $felirat, 'sz' => $van_kedv ? 0.46 : 0.54], ['c' => 'Menny.', 'sz' => 0.13, 'ig' => 'R'], ['c' => 'Bruttó egységár', 'sz' => 0.16, 'ig' => 'R']],
                    $van_kedv ? [['c' => 'Kedv.', 'sz' => 0.08, 'ig' => 'R']] : [],
                    [['c' => 'Bruttó érték (Ft)', 'sz' => 0.17, 'ig' => 'R']]
                );
            } else {
                $oszlopok = [['c' => $felirat, 'sz' => 0.82], ['c' => 'Menny.', 'sz' => 0.18, 'ig' => 'R']];
            }

            $pdf->Ln(0.8);
            $pdf->tabla($oszlopok, $sorok);
        }

        return $volt;
    }

    private static function qr_blokk(SDH_Muhely_Nyomtatvany_Pdf $pdf, array $d, string $szoveg): void
    {
        // Kikapcsolt Ügyfélüzenetek és RMA-oldal modulnál (Modulok) nincs ügyféloldal, így QR-kód sem.
        if (!class_exists('SDH_Muhely_Rma') || !class_exists('SDH_Muhely_Kodok') || SDH_Muhely_Modulok::ki('uzenetek')) {
            return;
        }

        $m = $d['munkalap'];

        // A próbanyomaton kitalált cím áll; valódi lapnál az ügyféloldal címe.
        $url = !empty($d['minta'])
            ? home_url('/minta-ugyfeloldal/')
            : ((int) $m->id > 0 && (int) $m->munkalap_szam > 0 ? SDH_Muhely_Rma::url(SDH_Muhely_Rma::token($m)) : '');

        if ($url === '') {
            return;
        }

        $matrix = SDH_Muhely_Kodok::qr_matrix($url);

        if ($matrix === null) {
            return;
        }

        $meret = 23.0;

        $pdf->hely($meret + 4);
        $pdf->Ln(1.5);

        $y = $pdf->GetY();
        $x = $pdf->bal();

        $pdf->qr($matrix, $x, $y, $meret);

        $tx = $x + $meret + 5;
        $tw = $pdf->szeles() - $meret - 5;

        $pdf->betu('B', 1.0);
        $pdf->tinta('fo');
        $yy = $y + 1.5;

        foreach ($pdf->tordel($szoveg !== '' ? $szoveg : 'A javítás állapota itt követhető:', $tw) as $sor) {
            $pdf->SetXY($tx, $yy);
            $pdf->Cell($tw, $pdf->sm(), $sor, 0, 0, 'L');
            $yy += $pdf->sm();
        }

        $pdf->betu('', 0.86);
        $pdf->tinta('halvany');

        foreach ($pdf->tordel($url, $tw) as $sor) {
            $pdf->SetXY($tx, $yy);
            $pdf->Cell($tw, $pdf->sm(0.86), $sor, 0, 0, 'L');
            $yy += $pdf->sm(0.86);
        }

        $pdf->SetXY($tx, $yy);
        $pdf->Cell($tw, $pdf->sm(0.86), 'Belépés: telefonszám és munkalapszám (' . $d['szam'] . ').', 0, 0, 'L');
        $pdf->tinta();
        $pdf->SetY(max($y + $meret, $yy + $pdf->sm(0.86)) + 2);
    }

    /* =================================================================
     * Bianko munkalap – kézzel kitölthető
     * ============================================================== */

    private static function rajz_bianko(SDH_Muhely_Nyomtatvany_Pdf $pdf, array $s, array $a): void
    {
        $keskeny = $pdf->szeles() < 150;

        self::fej($pdf, $a, (string) $s['cim'], [['Sorszám:', '..............................'], ['Dátum:', '..............................']]);

        $pdf->bekezdes((string) $s['bevezeto']);

        $pdf->szakasz('Ügyfél');
        $pdf->parok([
            ['c' => 'Név'], ['c' => 'Telefon'],
            ['c' => 'Cím (irányítószám, település, utca, házszám)'], ['c' => 'E-mail'],
        ], 2, true);

        $pdf->szakasz('Eszköz');
        $pdf->parok([
            ['c' => 'Gyártó, típus'], ['c' => 'Fajta'], ['c' => 'Szín'],
            ['c' => 'IMEI / gyári szám', 'sz' => 2], ['c' => 'Zárkód / minta'],
            ['c' => 'Tartozékok'], ['c' => 'Állapot átvételkor (sérülések)', 'sz' => 2],
        ], $keskeny ? 2 : 3, true);

        $ures = static fn (int $db, int $oszlop, float $magas): array => array_fill(0, max(0, $db), ['c' => array_fill(0, $oszlop, ''), 'magas' => $magas]);

        if ((int) $s['hiba_sor'] > 0) {
            $pdf->szakasz('Hibák');
            $pdf->tabla(
                [['c' => '#', 'sz' => 0.06], ['c' => 'Bejelentett hiba', 'sz' => 0.47], ['c' => 'Elvégzett javítás', 'sz' => 0.47]],
                array_map(static fn (int $i): array => ['c' => [(string) $i, '', ''], 'magas' => 7.0], range(1, (int) $s['hiba_sor'])),
                ['racs' => true]
            );
        }

        if ((int) $s['tetel_sor'] > 0) {
            $pdf->szakasz('Szolgáltatások és termékek');
            $pdf->tabla(
                [['c' => 'Megnevezés', 'sz' => 0.52], ['c' => 'Menny.', 'sz' => 0.12, 'ig' => 'R'], ['c' => 'Bruttó egységár', 'sz' => 0.18, 'ig' => 'R'], ['c' => 'Bruttó érték', 'sz' => 0.18, 'ig' => 'R']],
                array_merge($ures((int) $s['tetel_sor'], 4, 6.5), [['c' => ['Összesen', '', '', ''], 'b' => true, 'magas' => 6.5]]),
                ['racs' => true]
            );

            $pdf->parok([['c' => 'Előleg / bevizsgálási díj'], ['c' => 'Fizetendő'], ['c' => 'Határidő']], 3, true);
        }

        if ((int) $s['megj_sor'] > 0) {
            $pdf->szakasz('Megjegyzés');
            $pdf->ures_sorok((int) $s['megj_sor']);
        }

        if (trim((string) $s['jogi']) !== '') {
            $pdf->szakasz(trim((string) $s['jogi_cim']) !== '' ? (string) $s['jogi_cim'] : 'Tudnivalók');
            $pdf->bekezdes((string) $s['jogi'], 0.8);
        }

        $pdf->bekezdes((string) $s['zaro']);

        if (!empty($s['alairas'])) {
            $hely = trim((string) $a['kelt_hely']);

            $pdf->alairasok(
                [(string) $s['alairas_bal'], (string) $s['alairas_jobb']],
                'Kelt: ' . ($hely !== '' ? $hely . ', ' : '') . '20..... év ............................ hó ......... nap',
                !empty($s['igazolvany']) ? ['Átvevő neve', 'Igazolványszám'] : [],
                !empty($s['ph'])
            );
        }
    }

    /* =================================================================
     * Házipénztár: napi zárás
     * ============================================================== */

    /** @return array<string, mixed>|string */
    private static function zaras_adat(string $datum)
    {
        if (!class_exists('SDH_Muhely_Penztar')) {
            return 'A házipénztár modul nincs betöltve.';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) !== 1) {
            $datum = SDH_Muhely_Penztar::ma();
        }

        $cs = SDH_Muhely_Penztar::nap_csomag($datum);

        return [
            'szam'    => str_replace('-', '.', $datum) . '.',
            'datum'   => $datum,
            'nap'     => $cs['nap'],
            'tetelek' => $cs['tetelek'],
        ];
    }

    private static function rajz_zaras(SDH_Muhely_Nyomtatvany_Pdf $pdf, array $s, array $a, array $d): void
    {
        $n       = $d['nap'];
        $lezart  = (string) $n['allapot'] === 'lezart';
        $keskeny = $pdf->szeles() < 150;

        $adatok = [['Nap:', self::nap((string) $d['datum'])], ['Állapot:', $lezart ? 'lezárva' : 'NYITOTT (nincs lezárva)']];

        if ($lezart && (string) $n['lezarta'] !== '') {
            $adatok[] = ['Zárta:', (string) $n['lezarta'] . ((string) $n['lezarva'] !== '' ? ', ' . substr((string) $n['lezarva'], 11, 5) : '')];
        }

        self::fej($pdf, $a, (string) $s['cim'], $adatok);
        $pdf->bekezdes((string) $s['bevezeto']);

        $pdf->szakasz('Összesítő');
        $pdf->parok([
            ['c' => 'Nyitó készpénz', 'e' => self::ft((float) $n['nyito'])],
            ['c' => 'Készpénzes bevétel', 'e' => self::ft((float) $n['kp_be'])],
            ['c' => 'Befizetés a kasszába', 'e' => self::ft((float) $n['befizetes'])],
            ['c' => 'Kifizetés', 'e' => self::ft((float) $n['kifizetes'])],
            ['c' => 'Kivét', 'e' => self::ft((float) $n['kivet'])],
            ['c' => 'Várható záró készpénz', 'e' => self::ft((float) $n['zaro']), 'b' => true],
            ['c' => 'Bankkártyás bevétel', 'e' => self::ft((float) $n['kartya'])],
            ['c' => 'Átutalásos bevétel', 'e' => self::ft((float) $n['utalas'])],
            ['c' => 'Napi forgalom összesen', 'e' => self::ft((float) $n['forgalom']), 'b' => true],
        ], $keskeny ? 2 : 3);

        $pdf->osszesito([
            ['c' => 'Megszámolt készpénz:', 'e' => $n['szamolt'] !== null ? self::ft((float) $n['szamolt']) : 'nincs megszámolva', 'b' => true],
            ['c' => 'Eltérés:', 'e' => $n['elteres'] !== null ? ((float) $n['elteres'] > 0 ? '+' : '') . self::ft((float) $n['elteres']) : '–', 'ki' => true],
        ]);

        if (!empty($s['cimletek']) && is_array($n['cimletek']) && $n['cimletek'] !== []) {
            $sorok = [];
            $ossz  = 0.0;

            krsort($n['cimletek'], SORT_NUMERIC);

            foreach ($n['cimletek'] as $cimlet => $db) {
                $ertek   = (float) $cimlet * (int) $db;
                $ossz   += $ertek;
                $sorok[] = ['c' => [self::ft((float) $cimlet), (string) (int) $db . ' db', self::ft($ertek)]];
            }

            $sorok[] = ['c' => ['Összesen', '', self::ft($ossz)], 'b' => true];

            $pdf->szakasz('Címletek');
            $pdf->tabla([['c' => 'Címlet', 'sz' => 0.4], ['c' => 'Darab', 'sz' => 0.25, 'ig' => 'R'], ['c' => 'Érték', 'sz' => 0.35, 'ig' => 'R']], $sorok);
        }

        if (!empty($s['tetelek'])) {
            $pdf->szakasz('A nap tételei (' . count($d['tetelek']) . ')');

            if ($d['tetelek'] === []) {
                $pdf->bekezdes('Ezen a napon nincs pénztártétel.', 1.0, 'halvany');
            } else {
                self::ptetel_tabla($pdf, $d['tetelek'], false);
            }
        }

        if (trim((string) $n['megj']) !== '') {
            $pdf->szakasz('Megjegyzés a záráshoz');
            $pdf->bekezdes((string) $n['megj']);
        }

        $pdf->bekezdes((string) $s['zaro']);

        if (!empty($s['alairas'])) {
            $pdf->alairasok([(string) $s['alairas_bal'], (string) $s['alairas_jobb']], self::kelt($a));
        }
    }

    /**
     * Pénztártételek táblázata.
     *
     * @param array<int, array<string, mixed>> $tetelek  a tetel_kifele() alakjában
     */
    private static function ptetel_tabla(SDH_Muhely_Nyomtatvany_Pdf $pdf, array $tetelek, bool $datummal): void
    {
        $sorok = [];
        $o     = ['kp' => 0.0, 'kartya' => 0.0, 'utalas' => 0.0];

        foreach ($tetelek as $t) {
            $hiv = implode(' · ', array_filter([
                (string) $t['ml'] !== '' ? 'ML ' . $t['ml'] : '',
                (string) $t['szamla'] !== '' ? (string) $t['szamla'] : '',
            ]));

            $al = implode('  ·  ', array_filter([
                (string) $t['nev'], (string) $t['szemely'], $hiv, (string) $t['megj'],
                (float) $t['info'] != 0.0 ? 'nem került kasszába: ' . self::ft((float) $t['info']) : '',
            ]));

            $sorok[] = [
                'c' => [
                    $datummal ? self::nap((string) $t['datum']) : (string) $t['ido'],
                    (string) (SDH_Muhely_Penztar::TIPUSOK[(string) $t['tipus']] ?? $t['tipus']),
                    (string) $t['leiras'],
                    (float) $t['kp'] != 0.0 ? self::ft((float) $t['kp'], false) : '',
                    (float) $t['kartya'] != 0.0 ? self::ft((float) $t['kartya'], false) : '',
                    (float) $t['utalas'] != 0.0 ? self::ft((float) $t['utalas'], false) : '',
                ],
                'al' => '',
            ];

            // A részletek a leírás alá kerülnek (a 3. oszlop helyett az elsőhöz kötött alsor nem fér ki szépen).
            if ($al !== '') {
                $utolso = count($sorok) - 1;
                $sorok[$utolso]['c'][2] .= "\n" . $al;
            }

            foreach (array_keys($o) as $k) {
                $o[$k] += (float) $t[$k];
            }
        }

        $sorok[] = ['c' => ['', '', 'Összesen', self::ft($o['kp'], false), self::ft($o['kartya'], false), self::ft($o['utalas'], false)], 'b' => true];

        $pdf->tabla([
            ['c' => $datummal ? 'Nap' : 'Idő', 'sz' => $datummal ? 0.13 : 0.08],
            ['c' => 'Típus', 'sz' => 0.12],
            ['c' => 'Leírás', 'sz' => $datummal ? 0.39 : 0.44],
            ['c' => 'Készpénz', 'sz' => 0.12, 'ig' => 'R'],
            ['c' => 'Kártya', 'sz' => 0.12, 'ig' => 'R'],
            ['c' => 'Utalás', 'sz' => 0.12, 'ig' => 'R'],
        ], $sorok, ['meret' => 0.9]);
    }

    /* =================================================================
     * Házipénztár: időszaki jelentés
     * ============================================================== */

    /** @return array<string, mixed>|string */
    private static function riport_adat(string $tol, string $ig)
    {
        if (!class_exists('SDH_Muhely_Penztar_Elemzes') || !method_exists('SDH_Muhely_Penztar_Elemzes', 'riport_adat')) {
            return 'A házipénztár riportja nem érhető el.';
        }

        $jo = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

        if (!$jo($ig)) {
            $ig = SDH_Muhely_Penztar::ma();
        }

        if (!$jo($tol)) {
            $tol = substr($ig, 0, 8) . '01';
        }

        if ($tol > $ig) {
            [$tol, $ig] = [$ig, $tol];
        }

        return ['szam' => str_replace('-', '.', $tol) . '.–' . str_replace('-', '.', $ig) . '.'] + SDH_Muhely_Penztar_Elemzes::riport_adat($tol, $ig);
    }

    private static function rajz_riport(SDH_Muhely_Nyomtatvany_Pdf $pdf, array $s, array $a, array $d): void
    {
        $o       = $d['osszesen'];
        $keskeny = $pdf->szeles() < 150;

        self::fej($pdf, $a, (string) $s['cim'], [
            ['Időszak:', self::nap((string) $d['tol']) . ' – ' . self::nap((string) $d['ig'])],
            ['Forgalmas napok:', (string) (int) $o['napok']],
        ]);
        $pdf->bekezdes((string) $s['bevezeto']);

        $pdf->szakasz('Összesítő');
        $pdf->parok([
            ['c' => 'Készpénzes bevétel', 'e' => self::ft((float) $o['kp_be'])],
            ['c' => 'Bankkártyás bevétel', 'e' => self::ft((float) $o['kartya'])],
            ['c' => 'Átutalásos bevétel', 'e' => self::ft((float) $o['utalas'])],
            ['c' => 'Forgalom összesen', 'e' => self::ft((float) $o['forgalom']), 'b' => true],
            ['c' => 'Napi átlag', 'e' => self::ft((float) $o['atlag'])],
            ['c' => 'Tételek száma', 'e' => (string) (int) $o['tetel_db']],
            ['c' => 'Kifizetés', 'e' => self::ft((float) $o['kifizetes'])],
            ['c' => 'Kivét', 'e' => self::ft((float) $o['kivet'])],
            ['c' => 'Befizetés a kasszába', 'e' => self::ft((float) $o['befizetes'])],
            ['c' => 'Nyitó készpénz', 'e' => $o['nyito'] !== null ? self::ft((float) $o['nyito']) : '–'],
            ['c' => 'Záró készpénz', 'e' => $o['zaro'] !== null ? self::ft((float) $o['zaro']) : '–', 'b' => true],
            ['c' => 'Eltérés (többlet / hiány)', 'e' => '+' . self::ft((float) $o['tobblet']) . ' / ' . self::ft((float) $o['hiany']) . ' (' . (int) $o['elteres_nap'] . ' nap)'],
        ], $keskeny ? 2 : 3);

        $b = $d['bizonylat'];

        $pdf->bekezdes(
            'Bevételi tételek: ' . (int) $b['db'] . ' db; számlaszám nélkül: ' . (int) $b['szamla_nelkul_db'] . ' db (' . self::ft((float) $b['szamla_nelkul'])
            . '); munkalapszám nélkül: ' . (int) $b['ml_nelkul_db'] . ' db. Megszámolatlan nap: ' . (int) $o['szamolatlan'] . '.',
            0.9,
            'halvany'
        );

        if (!empty($s['napok']) && $d['napok'] !== []) {
            $sorok = [];

            foreach ($d['napok'] as $n) {
                $sorok[] = ['c' => [
                    self::nap((string) $n['datum']),
                    self::ft((float) $n['kp_be'], false), self::ft((float) $n['kartya'], false), self::ft((float) $n['utalas'], false),
                    self::ft((float) $n['kifizetes'] + (float) $n['kivet'], false),
                    self::ft($n['szamolt'] !== null ? (float) $n['szamolt'] : (float) $n['zaro'], false),
                    $n['elteres'] !== null && abs((float) $n['elteres']) >= 1 ? ((float) $n['elteres'] > 0 ? '+' : '') . self::ft((float) $n['elteres'], false) : '',
                    (string) $n['allapot'] === 'lezart' ? 'lezárva' : 'nyitott',
                ]];
            }

            $pdf->szakasz('Napok');
            $pdf->tabla([
                ['c' => 'Nap', 'sz' => 0.14], ['c' => 'Készpénz', 'sz' => 0.13, 'ig' => 'R'], ['c' => 'Kártya', 'sz' => 0.13, 'ig' => 'R'],
                ['c' => 'Utalás', 'sz' => 0.12, 'ig' => 'R'], ['c' => 'Kiadás', 'sz' => 0.12, 'ig' => 'R'], ['c' => 'Záró kp', 'sz' => 0.13, 'ig' => 'R'],
                ['c' => 'Eltérés', 'sz' => 0.11, 'ig' => 'R'], ['c' => 'Állapot', 'sz' => 0.12, 'ig' => 'R'],
            ], $sorok, ['meret' => 0.9]);
        }

        if (!empty($s['kiadasok'])) {
            if ($d['kiadasok']['kivet'] !== []) {
                $pdf->szakasz('Kivét személyenként');
                $pdf->tabla(
                    [['c' => 'Ki', 'sz' => 0.55], ['c' => 'Alkalom', 'sz' => 0.15, 'ig' => 'R'], ['c' => 'Összeg', 'sz' => 0.3, 'ig' => 'R']],
                    array_map(static fn (array $k): array => ['c' => [(string) $k['szemely'], (string) (int) $k['db'], self::ft((float) $k['osszeg'])]], $d['kiadasok']['kivet'])
                );
            }

            if ($d['kiadasok']['partnerek'] !== []) {
                $pdf->szakasz('Kifizetések partnerenként');
                $pdf->tabla(
                    [['c' => 'Partner', 'sz' => 0.55], ['c' => 'Alkalom', 'sz' => 0.15, 'ig' => 'R'], ['c' => 'Összeg', 'sz' => 0.3, 'ig' => 'R']],
                    array_map(static fn (array $k): array => ['c' => [(string) $k['nev'], (string) (int) $k['db'], self::ft((float) $k['osszeg'])]], $d['kiadasok']['partnerek'])
                );
            }
        }

        $pdf->bekezdes((string) $s['zaro']);

        if (!empty($s['alairas'])) {
            $pdf->alairasok([(string) $s['alairas_bal'], (string) $s['alairas_jobb']], self::kelt($a));
        }
    }

    /* =================================================================
     * Házipénztár: egy tétel igazolása
     * ============================================================== */

    /** @return array<string, mixed>|string */
    private static function ptetel_adat(int $id, bool $minta)
    {
        if ($minta) {
            return ['szam' => 'MINTA', 'tetel' => [
                'id' => 0, 'datum' => wp_date('Y-m-d'), 'ido' => wp_date('H:i'), 'tipus' => 'bevetel', 'leiras' => 'Kijelzőcsere – Minta Telefon X1',
                'nev' => 'Minta Ügyfél', 'szemely' => '', 'kp' => 40000.0, 'kartya' => 0.0, 'utalas' => 0.0, 'info' => 0.0, 'ml' => 'MINTA-0001',
                'szamla' => '', 'megj' => '',
            ]];
        }

        $t = class_exists('SDH_Muhely_Penztar') ? SDH_Muhely_Penztar::tetel($id) : null;

        if ($t === null || (int) $t->torolve === 1) {
            return 'Nincs ilyen pénztártétel.';
        }

        return ['szam' => str_replace('-', '', (string) $t->datum) . '/' . (int) $t->id, 'tetel' => SDH_Muhely_Penztar::tetel_kifele($t)];
    }

    private static function rajz_ptetel(SDH_Muhely_Nyomtatvany_Pdf $pdf, array $s, array $a, array $d): void
    {
        $t      = $d['tetel'];
        $osszeg = (float) $t['kp'] + (float) $t['kartya'] + (float) $t['utalas'];
        $tipus  = (string) (SDH_Muhely_Penztar::TIPUSOK[(string) $t['tipus']] ?? $t['tipus']);

        self::fej($pdf, $a, (string) $s['cim'], [
            ['Azonosító:', (string) $d['szam']],
            ['Dátum:', self::nap((string) $t['datum']) . ((string) $t['ido'] !== '' ? ' ' . $t['ido'] : '')],
        ]);
        $pdf->bekezdes((string) $s['bevezeto']);

        $pdf->szakasz($tipus);
        $pdf->parok(array_values(array_filter([
            ['c' => 'Mi történt', 'e' => (string) $t['leiras'], 'sz' => 2, 'b' => true],
            (string) $t['nev'] !== '' ? ['c' => 'Név / partner', 'e' => (string) $t['nev']] : null,
            (string) $t['szemely'] !== '' ? ['c' => 'Személy', 'e' => (string) $t['szemely']] : null,
            (string) $t['ml'] !== '' ? ['c' => 'Munkalap', 'e' => (string) $t['ml']] : null,
            (string) $t['szamla'] !== '' ? ['c' => 'Számla', 'e' => (string) $t['szamla']] : null,
            (string) $t['megj'] !== '' ? ['c' => 'Megjegyzés', 'e' => (string) $t['megj'], 'sz' => 2] : null,
        ])), 2);

        $sorok = [];

        foreach (['kp' => 'Készpénz:', 'kartya' => 'Bankkártya:', 'utalas' => 'Átutalás:'] as $k => $c) {
            if ((float) $t[$k] != 0.0) {
                $sorok[] = ['c' => $c, 'e' => self::ft((float) $t[$k])];
            }
        }

        if ((float) $t['info'] != 0.0) {
            $sorok[] = ['c' => 'Nem került kasszába:', 'e' => self::ft((float) $t['info'])];
        }

        $sorok[] = ['c' => 'Összeg:', 'e' => self::ft($osszeg != 0.0 ? $osszeg : (float) $t['info']), 'ki' => true];

        $pdf->osszesito($sorok);
        $pdf->bekezdes((string) $s['zaro']);

        if (!empty($s['alairas'])) {
            $pdf->alairasok([(string) $s['alairas_bal'], (string) $s['alairas_jobb']], self::kelt($a));
        }
    }

    /* =================================================================
     * Listák (kimutatások)
     * ============================================================== */

    /** @return array{0: string, 1: string} */
    private static function idoszak(array $p, int $alap_nap = 30): array
    {
        $jo  = static fn ($d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        $ig  = $jo($p['ig'] ?? null) ? (string) $p['ig'] : current_time('Y-m-d');
        $tol = $jo($p['tol'] ?? null) ? (string) $p['tol'] : gmdate('Y-m-d', strtotime($ig . ' -' . $alap_nap . ' days'));

        return $tol > $ig ? [$ig, $tol] : [$tol, $ig];
    }

    /** @return array<string, mixed> */
    private static function lista_munkalap_adat(array $p): array
    {
        global $wpdb;

        $szuro  = in_array(($p['szuro'] ?? ''), ['nyitott', 'lezart', 'mind', 'fizetetlen'], true) ? (string) $p['szuro'] : 'nyitott';
        $fajta  = in_array(($p['fajta'] ?? ''), ['javitas', 'arukiadas', 'rendeles'], true) ? (string) $p['fajta'] : 'javitas';
        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $zart   = SDH_Muhely_Munkalap::zart_kulcsok($allapotok);
        $m      = SDH_Muhely_Schema::tabla('munkalap');
        $u      = SDH_Muhely_Schema::tabla('ugyfel');
        $e      = SDH_Muhely_Schema::tabla('eszkoz');
        $hol    = ['m.fajta = %s'];
        $ertek  = [$fajta];
        $alcim  = SDH_Muhely_Munkalap::fajta_nev($fajta);

        if ($szuro === 'nyitott' || $szuro === 'lezart') {
            if ($zart !== []) {
                $hol[] = 'm.allapot ' . ($szuro === 'nyitott' ? 'NOT ' : '') . 'IN (' . implode(',', array_fill(0, count($zart), '%s')) . ')';
                $ertek = array_merge($ertek, $zart);
            }

            $alcim .= $szuro === 'nyitott' ? ' – nyitott lapok' : ' – lezárt lapok';
        } elseif ($szuro === 'fizetetlen') {
            $hol[]  = 'm.fizetve = 0 AND m.brutto_ertek > m.fizetett';
            $alcim .= ' – fizetetlen lapok';
        }

        // A nyitott lapok listája nem időszakos (minden nyitott lap kell); a többi a felvétel napja szerint szűr.
        if ($szuro !== 'nyitott' && $szuro !== 'fizetetlen') {
            [$tol, $ig] = self::idoszak($p);
            $hol[]  = 'DATE(COALESCE(m.keszult, m.letrehozva)) BETWEEN %s AND %s';
            $ertek[] = $tol;
            $ertek[] = $ig;
            $alcim .= ' · ' . self::nap($tol) . ' – ' . self::nap($ig);
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT m.id, m.munkalap_szam, m.allapot, m.keszult, m.letrehozva, m.hatarido, m.brutto_ertek, m.fizetett, m.fizetve, m.nev AS lap_nev,
                    u.nev AS ugyfel_nev, u.telefon, e.gyarto, e.tipus, e.megnevezes
             FROM {$m} m LEFT JOIN {$u} u ON u.id = m.ugyfel_id LEFT JOIN {$e} e ON e.id = m.eszkoz_id
             WHERE " . implode(' AND ', $hol) . ' ORDER BY m.id DESC LIMIT ' . (self::LISTA_MAX + 1),
            $ertek
        ));
        // phpcs:enable

        $sorok  = is_array($sorok) ? $sorok : [];
        $csonka = count($sorok) > self::LISTA_MAX;
        $sorok  = array_slice($sorok, 0, self::LISTA_MAX);
        $ki     = [];
        $ossz   = 0.0;
        $tart   = 0.0;

        foreach ($sorok as $s) {
            $hatra = (int) $s->fizetve === 1 ? 0.0 : max(0.0, (float) $s->brutto_ertek - (float) $s->fizetett);
            $ossz += (float) $s->brutto_ertek;
            $tart += $hatra;

            $szam = SDH_Muhely_Munkalap::szam_formaz($s->munkalap_szam);

            $ki[] = ['c' => [
                $szam === '—' ? '#' . (int) $s->id : $szam,
                self::nap((string) ($s->keszult ?: $s->letrehozva)),
                trim((string) ($s->ugyfel_nev ?? '')) !== '' ? (string) $s->ugyfel_nev : (string) $s->lap_nev,
                $fajta === 'javitas' ? ((string) ($s->gyarto ?? '') !== '' || (string) ($s->tipus ?? '') !== '' ? SDH_Muhely_Eszkoz::megnevezes($s) : '') : (class_exists('SDH_Muhely_Eladas') ? SDH_Muhely_Eladas::tetel_szoveg((int) $s->id, 3) : ''),
                (string) ($allapotok[(string) $s->allapot]['nev'] ?? $s->allapot),
                self::nap((string) $s->hatarido),
                self::ft((float) $s->brutto_ertek, false),
                $hatra > 0 ? self::ft($hatra, false) : ((int) $s->fizetve === 1 ? 'fizetve' : ''),
            ], 'al' => trim((string) ($s->telefon ?? ''))];
        }

        if ($ki !== []) {
            $ki[] = ['c' => ['', '', count($sorok) . ' lap', '', '', 'Összesen', self::ft($ossz, false), self::ft($tart, false)], 'b' => true];
        }

        return [
            'szam'     => '',
            'alcim'    => $alcim,
            'oszlopok' => [
                ['c' => 'Szám', 'sz' => 0.09], ['c' => 'Felvéve', 'sz' => 0.1], ['c' => 'Ügyfél', 'sz' => 0.2],
                ['c' => $fajta === 'javitas' ? 'Eszköz' : 'Tételek', 'sz' => 0.2], ['c' => 'Állapot', 'sz' => 0.13], ['c' => 'Határidő', 'sz' => 0.1],
                ['c' => 'Végösszeg', 'sz' => 0.09, 'ig' => 'R'], ['c' => 'Hátralék', 'sz' => 0.09, 'ig' => 'R'],
            ],
            'sorok'    => self::telefon_alsor($ki),
            'ures'     => 'Nincs a szűrésnek megfelelő lap.',
            'megj'     => $csonka ? 'A lista az első ' . self::LISTA_MAX . ' sort mutatja – szűkítsd az időszakot.' : '',
        ];
    }

    /** Az ügyfél telefonszáma a név alá kerül (a tábla alsora az első oszlophoz tartozna). */
    private static function telefon_alsor(array $sorok): array
    {
        foreach ($sorok as $i => $s) {
            if (!empty($s['al'])) {
                $sorok[$i]['c'][2] .= "\n" . $s['al'];
                $sorok[$i]['al']    = '';
            }
        }

        return $sorok;
    }

    /** @return array<string, mixed> */
    private static function lista_keszlet_adat(array $p): array
    {
        global $wpdb;

        $szuro = in_array(($p['szuro'] ?? ''), ['mind', 'fogyo', 'keszleten'], true) ? (string) $p['szuro'] : 'keszleten';
        $t     = SDH_Muhely_Schema::tabla('termek');
        $hol   = 'aktiv = 1 AND keszletkezeles = 1';

        if ($szuro === 'fogyo') {
            $hol .= ' AND keszlet <= min_keszlet';
        } elseif ($szuro === 'keszleten') {
            $hol .= ' AND keszlet <> 0';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- saját táblanév, rögzített feltétel.
        $sorok = $wpdb->get_results("SELECT * FROM {$t} WHERE {$hol} ORDER BY kategoria, megnevezes LIMIT " . (self::LISTA_MAX + 1));
        $sorok = is_array($sorok) ? $sorok : [];
        $csonka = count($sorok) > self::LISTA_MAX;
        $sorok = array_slice($sorok, 0, self::LISTA_MAX);
        $ki    = [];
        $besz  = 0.0;
        $elad  = 0.0;

        foreach ($sorok as $s) {
            $db    = (float) $s->keszlet;
            $besz += $db * (float) $s->besz_netto;
            $elad += $db * (float) $s->brutto_ar;

            $ki[] = ['c' => [
                (string) $s->megnevezes . (trim((string) $s->cikkszam) !== '' ? "\n" . 'cikkszám: ' . trim((string) $s->cikkszam) : ''),
                (string) $s->kategoria,
                self::menny($db, (string) $s->me),
                (float) $s->min_keszlet > 0 ? self::menny((float) $s->min_keszlet, '') : '',
                self::ft((float) $s->besz_netto, false),
                self::ft((float) $s->brutto_ar, false),
                self::ft($db * (float) $s->brutto_ar, false),
            ]];
        }

        if ($ki !== []) {
            $ki[] = ['c' => [count($sorok) . ' termék', '', '', '', 'beszerzési (nettó): ' . self::ft($besz, false), 'Összesen', self::ft($elad, false)], 'b' => true];
        }

        return [
            'szam'     => '',
            'alcim'    => ['mind' => 'Minden készletkezelt termék', 'fogyo' => 'Fogyóban lévő termékek (a minimum alatt vagy azon)', 'keszleten' => 'Készleten lévő termékek'][$szuro] . ' · ' . wp_date('Y. m. d.'),
            'oszlopok' => [
                ['c' => 'Termék', 'sz' => 0.34], ['c' => 'Kategória', 'sz' => 0.14], ['c' => 'Készlet', 'sz' => 0.1, 'ig' => 'R'], ['c' => 'Minimum', 'sz' => 0.08, 'ig' => 'R'],
                ['c' => 'Besz. nettó', 'sz' => 0.11, 'ig' => 'R'], ['c' => 'Eladási bruttó', 'sz' => 0.11, 'ig' => 'R'], ['c' => 'Készletérték', 'sz' => 0.12, 'ig' => 'R'],
            ],
            'sorok'    => $ki,
            'ures'     => 'Nincs a szűrésnek megfelelő termék.',
            'megj'     => $csonka ? 'A lista az első ' . self::LISTA_MAX . ' sort mutatja.' : '',
        ];
    }

    /** @return array<string, mixed> */
    private static function lista_szamla_adat(array $p): array
    {
        global $wpdb;

        [$tol, $ig] = self::idoszak($p);

        $t = SDH_Muhely_Schema::tabla('szamla');
        $u = SDH_Muhely_Schema::tabla('ugyfel');
        $m = SDH_Muhely_Schema::tabla('munkalap');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, u.nev AS ugyfel_nev, m.munkalap_szam FROM {$t} s LEFT JOIN {$u} u ON u.id = s.ugyfel_id LEFT JOIN {$m} m ON m.id = s.munkalap_id
             WHERE s.kelt BETWEEN %s AND %s ORDER BY s.kelt, s.id LIMIT " . (self::LISTA_MAX + 1),
            $tol,
            $ig
        ));
        // phpcs:enable

        $sorok  = is_array($sorok) ? $sorok : [];
        $csonka = count($sorok) > self::LISTA_MAX;
        $sorok  = array_slice($sorok, 0, self::LISTA_MAX);
        $ki     = [];
        $netto  = 0.0;
        $brutto = 0.0;

        foreach ($sorok as $s) {
            $vevo = json_decode((string) $s->vevo, true);
            $nev  = is_array($vevo) && trim((string) ($vevo['nev'] ?? '')) !== '' ? (string) $vevo['nev'] : (string) ($s->ugyfel_nev ?? '');
            $helyi = (string) $s->sorozat === 'helyi';

            if (!$helyi) {
                $netto  += (float) $s->netto;
                $brutto += (float) $s->brutto;
            }

            $ki[] = ['c' => [
                (string) $s->szamlaszam . ($helyi ? "\n" . '(helyi nyomtatvány – nem számla)' : ''),
                self::nap((string) $s->kelt),
                $nev,
                SDH_Muhely_Munkalap::szam_formaz($s->munkalap_szam),
                (string) $s->fizmod,
                self::ft((float) $s->netto, false),
                self::ft((float) $s->brutto, false),
            ]];
        }

        if ($ki !== []) {
            $ki[] = ['c' => [count($sorok) . ' bizonylat', '', '', '', 'Számlák összesen', self::ft($netto, false), self::ft($brutto, false)], 'b' => true];
        }

        return [
            'szam'     => '',
            'alcim'    => 'Kiállítás napja: ' . self::nap($tol) . ' – ' . self::nap($ig),
            'oszlopok' => [
                ['c' => 'Számlaszám', 'sz' => 0.2], ['c' => 'Kelt', 'sz' => 0.11], ['c' => 'Vevő', 'sz' => 0.27], ['c' => 'Munkalap', 'sz' => 0.1],
                ['c' => 'Fizetés', 'sz' => 0.1], ['c' => 'Nettó', 'sz' => 0.11, 'ig' => 'R'], ['c' => 'Bruttó', 'sz' => 0.11, 'ig' => 'R'],
            ],
            'sorok'    => $ki,
            'ures'     => 'Ebben az időszakban nincs kiállított számla.',
            'megj'     => $csonka ? 'A lista az első ' . self::LISTA_MAX . ' sort mutatja – szűkítsd az időszakot.' : '',
        ];
    }

    private static function rajz_lista(SDH_Muhely_Nyomtatvany_Pdf $pdf, array $s, array $a, array $d): void
    {
        self::fej($pdf, $a, (string) $s['cim'], [['Készült:', wp_date('Y. m. d. H:i')]]);

        $pdf->bekezdes((string) ($d['alcim'] ?? ''), 1.05, 'fo', 'B');
        $pdf->bekezdes((string) $s['bevezeto']);

        if ($d['sorok'] === []) {
            $pdf->bekezdes((string) $d['ures'], 1.0, 'halvany');
        } else {
            $pdf->tabla($d['oszlopok'], $d['sorok'], ['meret' => 0.88]);
        }

        $pdf->bekezdes((string) ($d['megj'] ?? ''), 0.9, 'halvany');
        $pdf->bekezdes((string) $s['zaro']);
    }
}
