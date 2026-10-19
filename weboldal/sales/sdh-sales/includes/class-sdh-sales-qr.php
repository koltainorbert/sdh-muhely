<?php
/**
 * SDH Értékesítés – QR-kód: MNB azonnali fizetési QR (HCT) adat + saját, tiszta PHP QR-kódoló (bájt mód, M hibajavítás,
 * 1–40. verzió, automatikus maszkválasztás) → SVG és PNG (GD).
 *
 * Adatformátum forrása: MNB „Útmutató az azonnali fizetési rendszerhez kapcsolódó QR-kód alapú adatbeviteli megoldáshoz”
 * (Guidelines on the QR Code Data Entry Solution Applicable in the Instant Payment System), 2019-07-12, QR-verziószám „001”:
 * https://www.mnb.hu/letoltes/qr-kod-utmutato-20190712-en.pdf (ellenőrizve: 2026-10-19; az MNB 2024-es 8/2024. ajánlása és a
 * 2025-ös EAM technikai kézikönyv a QR-adattartalmat nem módosítja, a 35/2017. MNB rendelet 6. mellékletére hivatkozik).
 * Szabályok: 17 mező fix sorrendben, mindegyik után LF (az utolsó után is, tehát pontosan 17 LF), UTF-8 (karakterkészlet „1”),
 * azonosító „HCT” (átutalási megbízás – a kedvezményezett állítja elő), verzió „001”, BIC (8 vagy 11), név ≤ 70,
 * IBAN ≤ 28, összeg „HUF” + egész forint (≤ 12 számjegy, vezető nullák nélkül, ahogy a referenciamegvalósítások),
 * érvényesség „ÉÉÉÉHHNNóóppmm+Z” helyi (budapesti) időben, Z = UTC-eltérés órában, közlemény ≤ 70, max. 345 bájt,
 * hibajavítás M, legfeljebb 13. verzió (65×65 modul).
 *
 * API:
 *   mnb_adat(array $a): string   – a, b, c: bic, nev, iban, osszeg (Ft), ervenyes (unix ts), kozlemeny, szamla?, vevo?, belso?
 *   mnb(int $rendeles_id): string – a rendelés átutalási QR-adata a cégadatokból (ceg.bic, ceg.nev, ceg.iban)
 *   matrix(string $adat, int $min_verzio = 1): array{meret, verzio, maszk, modulok: bool[][]}
 *   svg(string $adat, int $modul = 4, int $keret = 4): string
 *   png(string $adat, int $modul = 6, int $keret = 4): string (PNG bájtok; GD kell)
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Qr
{
    public const MNB_MAX_BAJT   = 345;
    public const MNB_MAX_VERZIO = 13;

    /**
     * M szint, verziónként: [blokkonkénti hibajavító kódszó, g1 blokk, g1 adat, g2 blokk, g2 adat] (ISO/IEC 18004 7. táblázat).
     */
    private const M = [
        1 => [10, 1, 16, 0, 0], 2 => [16, 1, 28, 0, 0], 3 => [26, 1, 44, 0, 0], 4 => [18, 2, 32, 0, 0],
        5 => [24, 2, 43, 0, 0], 6 => [16, 4, 27, 0, 0], 7 => [18, 4, 31, 0, 0], 8 => [22, 2, 38, 2, 39],
        9 => [22, 3, 36, 2, 37], 10 => [26, 4, 43, 1, 44], 11 => [30, 1, 50, 4, 51], 12 => [22, 6, 36, 2, 37],
        13 => [22, 8, 37, 1, 38], 14 => [24, 4, 40, 5, 41], 15 => [24, 5, 41, 5, 42], 16 => [28, 7, 45, 3, 46],
        17 => [28, 10, 46, 1, 47], 18 => [26, 9, 43, 4, 44], 19 => [26, 3, 44, 11, 45], 20 => [26, 3, 41, 13, 42],
        21 => [26, 17, 42, 0, 0], 22 => [28, 17, 46, 0, 0], 23 => [28, 4, 47, 14, 48], 24 => [28, 6, 45, 14, 46],
        25 => [28, 8, 47, 13, 48], 26 => [28, 19, 46, 4, 47], 27 => [28, 22, 45, 3, 46], 28 => [28, 3, 45, 23, 46],
        29 => [28, 21, 45, 7, 46], 30 => [28, 19, 47, 10, 48], 31 => [28, 2, 46, 29, 47], 32 => [28, 10, 46, 23, 47],
        33 => [28, 14, 46, 21, 47], 34 => [28, 14, 46, 23, 47], 35 => [28, 12, 47, 26, 48], 36 => [28, 6, 47, 34, 48],
        37 => [28, 29, 46, 14, 47], 38 => [28, 13, 46, 32, 47], 39 => [28, 40, 47, 7, 48], 40 => [28, 18, 47, 31, 48],
    ];

    /** @var array<int, int>|null */
    private static ?array $exp = null;
    /** @var array<int, int>|null */
    private static ?array $log = null;

    public static function init(): void
    {
    }

    /* =================================================================
     * MNB adattartalom
     * ============================================================== */

    /** Szövegmező tisztítása: sortörés, vezérlőkarakter ki; hossz bájtban a mező korlátjáig (UTF-8 határon). */
    private static function mezo(string $s, int $max): string
    {
        $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s));
        $s = (string) preg_replace('/\s{2,}/u', ' ', $s);

        while (strlen($s) > $max) {
            $s = mb_substr($s, 0, mb_strlen($s) - 1);
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $a
     */
    public static function mnb_adat(array $a): string
    {
        $bic  = strtoupper((string) preg_replace('/\s+/', '', (string) ($a['bic'] ?? '')));
        $iban = strtoupper((string) preg_replace('/[\s-]+/', '', (string) ($a['iban'] ?? '')));

        if (preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) !== 1) {
            throw new InvalidArgumentException('Érvénytelen BIC: a cég BIC-kódja hiányzik vagy hibás.');
        }

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,24}$/', $iban) !== 1 || !self::iban_jo($iban)) {
            throw new InvalidArgumentException('Érvénytelen IBAN: a cég IBAN-ja hiányzik vagy hibás.');
        }

        $nev = self::mezo((string) ($a['nev'] ?? ''), 70);

        if ($nev === '') {
            throw new InvalidArgumentException('A kedvezményezett neve hiányzik.');
        }

        $osszeg = (int) ($a['osszeg'] ?? 0);

        if ($osszeg < 0 || $osszeg > 999999999999) {
            throw new InvalidArgumentException('Érvénytelen összeg.');
        }

        $ts   = (int) ($a['ervenyes'] ?? (time() + 8 * DAY_IN_SECONDS));
        $zona = new DateTimeZone('Europe/Budapest');
        $d    = (new DateTimeImmutable('@' . $ts))->setTimezone($zona);
        $elt  = (int) round($zona->getOffset($d) / 3600);

        $mezok = [
            'HCT',
            '001',
            '1',
            $bic,
            $nev,
            $iban,
            $osszeg > 0 ? 'HUF' . $osszeg : '',
            $d->format('YmdHis') . '+' . $elt,
            self::mezo((string) ($a['helyzet'] ?? ''), 4),
            self::mezo((string) ($a['kozlemeny'] ?? ''), 70),
            '',
            '',
            self::mezo((string) ($a['szamla'] ?? ''), 35),
            self::mezo((string) ($a['vevo'] ?? ''), 35),
            self::mezo((string) ($a['belso'] ?? ''), 35),
            '',
            '',
        ];

        $s = implode("\n", $mezok) . "\n";

        if (strlen($s) > self::MNB_MAX_BAJT) {
            throw new InvalidArgumentException('A QR-adat túl hosszú (' . strlen($s) . ' bájt, legfeljebb ' . self::MNB_MAX_BAJT . ').');
        }

        return $s;
    }

    public static function iban_jo(string $iban): bool
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', $iban));
        $atr  = substr($iban, 4) . substr($iban, 0, 4);
        $szam = '';

        foreach (str_split($atr) as $c) {
            $szam .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
        }

        $m = 0;

        foreach (str_split($szam, 7) as $d) {
            $m = (int) (($m . $d) % 97);
        }

        return $m === 1;
    }

    /** A rendelés átutalási QR-adata (cégadatokkal a beállításból). Üres, ha nincs meg minden adat. */
    public static function mnb(int $rendeles_id): string
    {
        global $wpdb;

        $r = $wpdb->get_row($wpdb->prepare('SELECT azon, brutto, fizetett, kozlemeny, hatarido, email FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE id = %d', $rendeles_id), ARRAY_A);

        if (!is_array($r)) {
            return '';
        }

        $hatarido = (string) ($r['hatarido'] ?? '');
        $ts       = $hatarido !== '' ? (int) (new DateTimeImmutable($hatarido . ' 23:59:59', new DateTimeZone('Europe/Budapest')))->getTimestamp() : time() + 8 * DAY_IN_SECONDS;

        try {
            return self::mnb_adat([
                'bic'       => (string) SDH_Sales::b('ceg.bic', ''),
                'nev'       => (string) SDH_Sales::b('ceg.nev', ''),
                'iban'      => (string) SDH_Sales::b('ceg.iban', ''),
                'osszeg'    => max(0, (int) $r['brutto'] - (int) $r['fizetett']),
                'ervenyes'  => max($ts, time() + HOUR_IN_SECONDS),
                'kozlemeny' => (string) $r['kozlemeny'],
                'szamla'    => (string) $r['azon'],
            ]);
        } catch (InvalidArgumentException $e) {
            return '';
        }
    }

    /* =================================================================
     * Reed–Solomon (GF(256), 0x11D)
     * ============================================================== */

    private static function gf(): void
    {
        if (self::$exp !== null) {
            return;
        }

        $exp = [];
        $log = array_fill(0, 256, 0);
        $x   = 1;

        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x     <<= 1;

            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }

        self::$exp = $exp;
        self::$log = $log;
    }

    private static function szor(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** @return array<int, int> a generátor-polinom együtthatói (a vezető 1 nélkül) */
    private static function generator(int $fok): array
    {
        self::gf();
        $g = [1];

        for ($i = 0; $i < $fok; $i++) {
            $uj = array_fill(0, count($g) + 1, 0);

            foreach ($g as $j => $c) {
                $uj[$j]     ^= $c;
                $uj[$j + 1] ^= self::szor($c, self::$exp[$i]);
            }

            $g = $uj;
        }

        return array_slice($g, 1);
    }

    /**
     * @param array<int, int> $adat
     * @param array<int, int> $gen
     * @return array<int, int>
     */
    private static function rs(array $adat, array $gen): array
    {
        $n   = count($gen);
        $mar = array_fill(0, $n, 0);

        foreach ($adat as $b) {
            $f = $b ^ $mar[0];
            array_shift($mar);
            $mar[] = 0;

            if ($f !== 0) {
                for ($i = 0; $i < $n; $i++) {
                    $mar[$i] ^= self::szor($gen[$i], $f);
                }
            }
        }

        return $mar;
    }

    /* =================================================================
     * Kódolás
     * ============================================================== */

    public static function adat_kodszo(int $v): int
    {
        $t = self::M[$v];

        return $t[1] * $t[2] + $t[3] * $t[4];
    }

    /** Bájt módú kapacitás (ECI nélkül). */
    public static function kapacitas(int $v): int
    {
        return intdiv(self::adat_kodszo($v) * 8 - 4 - ($v < 10 ? 8 : 16), 8);
    }

    /**
     * A QR-mátrix.
     *
     * @return array{meret: int, verzio: int, maszk: int, modulok: array<int, array<int, bool>>}
     */
    public static function matrix(string $adat, int $min_verzio = 1): array
    {
        $eci  = preg_match('/[\x80-\xFF]/', $adat) === 1;
        $hossz = strlen($adat);
        $verzio = 0;

        for ($v = max(1, $min_verzio); $v <= 40; $v++) {
            $bitek = ($eci ? 12 : 0) + 4 + ($v < 10 ? 8 : 16) + 8 * $hossz;

            if ($bitek <= self::adat_kodszo($v) * 8) {
                $verzio = $v;
                break;
            }
        }

        if ($verzio === 0) {
            throw new InvalidArgumentException('Az adat túl hosszú a QR-kódhoz.');
        }

        // Bitfolyam.
        $b   = [];
        $tesz = static function (int $ertek, int $db) use (&$b): void {
            for ($i = $db - 1; $i >= 0; $i--) {
                $b[] = ($ertek >> $i) & 1;
            }
        };

        if ($eci) {
            $tesz(0b0111, 4);
            $tesz(26, 8); // ECI 26 = UTF-8
        }

        $tesz(0b0100, 4);
        $tesz($hossz, $verzio < 10 ? 8 : 16);

        for ($i = 0; $i < $hossz; $i++) {
            $tesz(ord($adat[$i]), 8);
        }

        $kap = self::adat_kodszo($verzio) * 8;
        $tesz(0, min(4, $kap - count($b)));

        while (count($b) % 8 !== 0) {
            $b[] = 0;
        }

        $kodszo = [];

        foreach (array_chunk($b, 8) as $o) {
            $kodszo[] = (int) bindec(implode('', $o));
        }

        for ($p = 0xEC; count($kodszo) < self::adat_kodszo($verzio); $p ^= 0xEC ^ 0x11) {
            $kodszo[] = $p;
        }

        // Blokkok + hibajavítás, összefésülés.
        [$ec, $g1, $d1, $g2, $d2] = self::M[$verzio];
        $gen    = self::generator($ec);
        $blokk  = [];
        $hj     = [];
        $poz    = 0;

        for ($i = 0; $i < $g1 + $g2; $i++) {
            $n        = $i < $g1 ? $d1 : $d2;
            $blokk[]  = array_slice($kodszo, $poz, $n);
            $hj[]     = self::rs($blokk[$i], $gen);
            $poz     += $n;
        }

        $vegso = [];
        $maxd  = max($d1, $d2);

        for ($j = 0; $j < $maxd; $j++) {
            foreach ($blokk as $bl) {
                if ($j < count($bl)) {
                    $vegso[] = $bl[$j];
                }
            }
        }

        for ($j = 0; $j < $ec; $j++) {
            foreach ($hj as $h) {
                $vegso[] = $h[$j];
            }
        }

        // Mátrix: funkciós minták, adat, maszk.
        $meret = $verzio * 4 + 17;
        $m     = array_fill(0, $meret, array_fill(0, $meret, false));
        $fn    = array_fill(0, $meret, array_fill(0, $meret, false));

        self::funkcios($m, $fn, $verzio, $meret);
        self::adat_rajz($m, $fn, $vegso, $meret);

        $legjobb = -1;
        $buntetes = PHP_INT_MAX;
        $ered    = $m;

        for ($mk = 0; $mk < 8; $mk++) {
            $p = $m;
            self::maszk($p, $fn, $mk, $meret);
            self::formatum($p, $mk, $meret);
            $pont = self::pontoz($p, $meret);

            if ($pont < $buntetes) {
                $buntetes = $pont;
                $legjobb  = $mk;
                $ered     = $p;
            }
        }

        return ['meret' => $meret, 'verzio' => $verzio, 'maszk' => $legjobb, 'modulok' => $ered];
    }

    /** @return array<int, int> */
    private static function igazito_poz(int $v): array
    {
        if ($v === 1) {
            return [];
        }

        $db    = intdiv($v, 7) + 2;
        $lepes = $v === 32 ? 26 : (int) ceil(($v * 4 + 4) / ($db * 2 - 2)) * 2;
        $ki    = [6];

        for ($p = $v * 4 + 17 - 7; count($ki) < $db; $p -= $lepes) {
            array_splice($ki, 1, 0, [$p]);
        }

        return $ki;
    }

    /**
     * @param array<int, array<int, bool>> $m
     * @param array<int, array<int, bool>> $fn
     */
    private static function funkcios(array &$m, array &$fn, int $v, int $meret): void
    {
        $all = static function (int $x, int $y, bool $sotet) use (&$m, &$fn): void {
            $m[$y][$x]  = $sotet;
            $fn[$y][$x] = true;
        };

        // Időzítő minták.
        for ($i = 0; $i < $meret; $i++) {
            $all(6, $i, $i % 2 === 0);
            $all($i, 6, $i % 2 === 0);
        }

        // Kereső minták + elválasztó.
        foreach ([[3, 3], [$meret - 4, 3], [3, $meret - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;

                    if ($x >= 0 && $x < $meret && $y >= 0 && $y < $meret) {
                        $d = max(abs($dx), abs($dy));
                        $all($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }

        // Igazító minták.
        $poz = self::igazito_poz($v);
        $n   = count($poz);

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $all($poz[$i] + $dx, $poz[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        // Formátum helye (később írjuk) + a sötét modul.
        self::formatum($m, 0, $meret, $fn);

        // Verzióinformáció (7+).
        if ($v >= 7) {
            $r = $v;

            for ($i = 0; $i < 12; $i++) {
                $r = ($r << 1) ^ (($r >> 11) * 0x1F25);
            }

            $bitek = ($v << 12) | $r;

            for ($i = 0; $i < 18; $i++) {
                $s = (($bitek >> $i) & 1) === 1;
                $a = $meret - 11 + $i % 3;
                $b = intdiv($i, 3);
                $all($a, $b, $s);
                $all($b, $a, $s);
            }
        }
    }

    /**
     * Formátum-bitek (M szint = 00) a maszkkal. Ha $fn adott, a helyeket funkciósnak jelöli.
     *
     * @param array<int, array<int, bool>>      $m
     * @param array<int, array<int, bool>>|null $fn
     */
    private static function formatum(array &$m, int $maszk, int $meret, ?array &$fn = null): void
    {
        $adat = (0 << 3) | $maszk;
        $r    = $adat;

        for ($i = 0; $i < 10; $i++) {
            $r = ($r << 1) ^ (($r >> 9) * 0x537);
        }

        $bitek = (($adat << 10) | $r) ^ 0x5412;
        $bit   = static fn (int $i): bool => (($bitek >> $i) & 1) === 1;
        $all   = static function (int $x, int $y, bool $s) use (&$m, &$fn): void {
            $m[$y][$x] = $s;

            if ($fn !== null) {
                $fn[$y][$x] = true;
            }
        };

        for ($i = 0; $i <= 5; $i++) {
            $all(8, $i, $bit($i));
        }

        $all(8, 7, $bit(6));
        $all(8, 8, $bit(7));
        $all(7, 8, $bit(8));

        for ($i = 9; $i < 15; $i++) {
            $all(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $all($meret - 1 - $i, 8, $bit($i));
        }

        for ($i = 8; $i < 15; $i++) {
            $all(8, $meret - 15 + $i, $bit($i));
        }

        $all(8, $meret - 8, true);
    }

    /**
     * @param array<int, array<int, bool>> $m
     * @param array<int, array<int, bool>> $fn
     * @param array<int, int>              $kodszo
     */
    private static function adat_rajz(array &$m, array $fn, array $kodszo, int $meret): void
    {
        $i     = 0;
        $osszb = count($kodszo) * 8;

        for ($jobb = $meret - 1; $jobb >= 1; $jobb -= 2) {
            if ($jobb === 6) {
                $jobb = 5;
            }

            for ($vert = 0; $vert < $meret; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x   = $jobb - $j;
                    $fel = (($jobb + 1) & 2) === 0;
                    $y   = $fel ? $meret - 1 - $vert : $vert;

                    if (!$fn[$y][$x] && $i < $osszb) {
                        $m[$y][$x] = ((($kodszo[$i >> 3] >> (7 - ($i & 7))) & 1) === 1);
                        $i++;
                    }
                }
            }
        }
    }

    /**
     * @param array<int, array<int, bool>> $m
     * @param array<int, array<int, bool>> $fn
     */
    private static function maszk(array &$m, array $fn, int $mk, int $meret): void
    {
        for ($y = 0; $y < $meret; $y++) {
            for ($x = 0; $x < $meret; $x++) {
                if ($fn[$y][$x]) {
                    continue;
                }

                switch ($mk) {
                    case 0: $f = ($x + $y) % 2 === 0; break;
                    case 1: $f = $y % 2 === 0; break;
                    case 2: $f = $x % 3 === 0; break;
                    case 3: $f = ($x + $y) % 3 === 0; break;
                    case 4: $f = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                    case 5: $f = ($x * $y) % 2 + ($x * $y) % 3 === 0; break;
                    case 6: $f = (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0; break;
                    default: $f = (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0;
                }

                if ($f) {
                    $m[$y][$x] = !$m[$y][$x];
                }
            }
        }
    }

    /** ISO/IEC 18004 büntetőpontok (N1=3, N2=3, N3=40, N4=10). @param array<int, array<int, bool>> $m */
    private static function pontoz(array $m, int $meret): int
    {
        $p     = 0;
        $sotet = 0;

        for ($tengely = 0; $tengely < 2; $tengely++) {
            for ($a = 0; $a < $meret; $a++) {
                $sor = '';

                for ($c = 0; $c < $meret; $c++) {
                    $s    = $tengely === 0 ? $m[$a][$c] : $m[$c][$a];
                    $sor .= $s ? '1' : '0';
                }

                // N1: 5+ azonos egymás után.
                if (preg_match_all('/0{5,}|1{5,}/', $sor, $t)) {
                    foreach ($t[0] as $fut) {
                        $p += 3 + strlen($fut) - 5;
                    }
                }

                // N3: 1011101 négy világossal valamelyik oldalon (a kereten kívül világos).
                $k = '0000' . $sor . '0000';
                $p += 40 * (preg_match_all('/(?=00001011101)/', $k) + preg_match_all('/(?=10111010000)/', $k));
            }
        }

        // N2: 2×2 azonos blokkok.
        for ($y = 0; $y < $meret - 1; $y++) {
            for ($x = 0; $x < $meret - 1; $x++) {
                $c = $m[$y][$x];

                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $p += 3;
                }
            }
        }

        foreach ($m as $sor) {
            foreach ($sor as $s) {
                $sotet += $s ? 1 : 0;
            }
        }

        $ossz = $meret * $meret;
        $k    = (int) (abs($sotet * 20 - $ossz * 10) / $ossz);

        return $p + $k * 10;
    }

    /* =================================================================
     * Kimenet
     * ============================================================== */

    public static function svg(string $adat, int $modul = 4, int $keret = 4, string $cim = ''): string
    {
        $q = self::matrix($adat);
        $n = $q['meret'] + 2 * $keret;
        $u = '';

        foreach ($q['modulok'] as $y => $sor) {
            foreach ($sor as $x => $s) {
                if ($s) {
                    $u .= 'M' . ($x + $keret) . ' ' . ($y + $keret) . 'h1v1h-1z';
                }
            }
        }

        $px = $n * max(1, $modul);

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" width="' . $px . '" height="' . $px . '" shape-rendering="crispEdges" role="img"'
            . ($cim !== '' ? ' aria-label="' . htmlspecialchars($cim, ENT_QUOTES) . '"' : '') . '>'
            . '<rect width="' . $n . '" height="' . $n . '" fill="#fff"/><path fill="#000" d="' . $u . '"/></svg>';
    }

    /** PNG bájtok (GD). */
    public static function png(string $adat, int $modul = 6, int $keret = 4): string
    {
        if (!function_exists('imagecreate')) {
            throw new RuntimeException('A PNG-hez a GD bővítmény kell.');
        }

        $q   = self::matrix($adat);
        $mod = max(1, min(40, $modul));
        $n   = ($q['meret'] + 2 * $keret) * $mod;
        $kep = imagecreate($n, $n);
        $f   = imagecolorallocate($kep, 255, 255, 255);
        $s   = imagecolorallocate($kep, 0, 0, 0);

        imagefilledrectangle($kep, 0, 0, $n - 1, $n - 1, $f);

        foreach ($q['modulok'] as $y => $sor) {
            foreach ($sor as $x => $sot) {
                if ($sot) {
                    $x0 = ($x + $keret) * $mod;
                    $y0 = ($y + $keret) * $mod;
                    imagefilledrectangle($kep, $x0, $y0, $x0 + $mod - 1, $y0 + $mod - 1, $s);
                }
            }
        }

        ob_start();
        imagepng($kep, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($kep);

        return $png;
    }

    /** SVG data-URI (img src-hez: a CSP img-src data: engedi). */
    public static function svg_uri(string $adat): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($adat, 4, 4));
    }
}
