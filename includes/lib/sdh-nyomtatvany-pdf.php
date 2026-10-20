<?php
/**
 * A nyomtatványok PDF-rajzolója (0.52): a tFPDF kiegészítve a
 * nyomtatványok építőelemeivel – fejléc, szakaszcím, címke–érték párok,
 * táblázat (laptöréssel és ismétlődő fejléccel), összesítő, aláírások,
 * kitöltendő vonalak, QR-kód, lábléc.
 *
 * Minden méret az alap betűméretből és a lap szélességéből számolódik,
 * ezért ugyanaz a rajz A4-en és A5-ön, kisebb és nagyobb betűvel is áll.
 * Csak PDF-készítéskor töltődik be (SDH_Muhely_Nyomtatas).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('TTFontFile')) {
    require_once __DIR__ . '/tfpdf/font/unifont/ttfonts.php';
}

if (!class_exists('tFPDF')) {
    require_once __DIR__ . '/tfpdf/tfpdf.php';
}

class SDH_Muhely_Nyomtatvany_Pdf extends tFPDF
{
    /** A csomagolt betűkészletek: kulcs => [név, normál, félkövér]. */
    public const BETUK = [
        'dejavu'           => ['DejaVu Sans keskeny', 'DejaVuSansCondensed.ttf', 'DejaVuSansCondensed-Bold.ttf'],
        'liberation-sans'  => ['Liberation Sans (Arial-szerű)', 'LiberationSans-Regular.ttf', 'LiberationSans-Bold.ttf'],
        'carlito'          => ['Carlito (Calibri-szerű)', 'Carlito-Regular.ttf', 'Carlito-Bold.ttf'],
        'liberation-serif' => ['Liberation Serif (Times-szerű)', 'LiberationSerif-Regular.ttf', 'LiberationSerif-Bold.ttf'],
    ];

    /** @var float Az alap betűméret pontban. */
    public $alap = 9.5;

    /** @var array<int, int> A kiemelő szín (RGB). */
    public $kiemelo = [201, 60, 54];

    /** @var array<int, string> A lábléc saját sorai (elérhetőség stb.). */
    public $lab_sorok = [];

    /** @var string A lábléc bal alsó szövege (mi ez, mikor készült). */
    public $lab_bal = '';

    /** @var int Ennyi oldal volt az előző példányokban (az oldalszám ettől indul újra). */
    public $oldal_eltolas = 0;

    /** @var int Egy példány oldalainak száma (0 = még nem ismert). */
    public $oldal_ossz = 0;

    /** @var string Pl. „2. példány" – üres, ha csak egy készül. */
    public $peldany_felirat = '';

    /** @var float A lábléc magassága mm-ben. */
    protected $lab_magas = 0.0;

    /** @var float */
    protected $margo = 12.0;

    /**
     * @param array{betu?: string, meret?: float, margo?: float, szin?: array<int, int>, lab?: array<int, string>} $b
     */
    public function __construct(string $tajolas, string $papir, array $b = [])
    {
        parent::__construct($tajolas === 'L' ? 'L' : 'P', 'mm', $papir === 'A5' ? 'A5' : 'A4');

        $this->alap      = min(14.0, max(6.5, (float) ($b['meret'] ?? 9.5)));
        $this->margo     = min(30.0, max(5.0, (float) ($b['margo'] ?? 12.0)));
        $this->kiemelo   = isset($b['szin']) && count($b['szin']) === 3 ? array_map('intval', $b['szin']) : $this->kiemelo;
        $this->lab_sorok = array_slice(array_values(array_filter(array_map('strval', (array) ($b['lab'] ?? [])), static fn (string $s): bool => trim($s) !== '')), 0, 4);

        $f = self::BETUK[(string) ($b['betu'] ?? 'dejavu')] ?? self::BETUK['dejavu'];

        $this->AddFont('F', '', $f[1], true);
        $this->AddFont('F', 'B', $f[2], true);

        $this->lab_magas = count($this->lab_sorok) * $this->sm(0.8) + $this->sm(0.8) + 3.5;

        $this->SetMargins($this->margo, $this->margo, $this->margo);
        $this->SetAutoPageBreak(true, $this->margo + $this->lab_magas);
        $this->SetCompression(true);
        $this->SetCreator(class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::termeknev() : 'Műhely', true);
        $this->tinta();
    }

    /* =================================================================
     * Alapok
     * ============================================================== */

    /** Sormagasság mm-ben az adott méretszorzóhoz. */
    public function sm(float $szorzo = 1.0): float
    {
        return round($this->alap * $szorzo * 0.3528 * 1.36, 2);
    }

    public function betu(string $stilus = '', float $szorzo = 1.0): void
    {
        $this->SetFont('F', $stilus === 'B' ? 'B' : '', round($this->alap * $szorzo, 1));
    }

    /** Szövegszín: fo (sötét), halvany (szürke), kiemelo, feher. */
    public function tinta(string $melyik = 'fo'): void
    {
        switch ($melyik) {
            case 'halvany':
                $this->SetTextColor(105, 105, 105);
                break;
            case 'kiemelo':
                $this->SetTextColor($this->kiemelo[0], $this->kiemelo[1], $this->kiemelo[2]);
                break;
            case 'feher':
                $this->SetTextColor(255, 255, 255);
                break;
            default:
                $this->SetTextColor(22, 22, 22);
        }
    }

    public function szeles(): float
    {
        return $this->w - $this->lMargin - $this->rMargin;
    }

    public function bal(): float
    {
        return $this->lMargin;
    }

    /** Ha a megadott magasság már nem fér ki, új oldal jön. */
    public function hely(float $mm): void
    {
        if ($this->GetY() + $mm > $this->PageBreakTrigger) {
            $this->AddPage();
        }
    }

    /** A betűkészlet csak az alap síkot (BMP) ismeri: az emoji és társai kimaradnak. */
    public static function tiszta(string $s): string
    {
        $s = (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', wp_check_invalid_utf8($s));

        return str_replace(["\u{00a0}", "\u{202f}", "\t"], ' ', $s);
    }

    /**
     * Szöveg tördelése a megadott szélességre, az AKTUÁLIS betűvel.
     *
     * @return array<int, string>
     */
    public function tordel(string $szoveg, float $w): array
    {
        $ki = [];
        $w  = max(4.0, $w);

        foreach (preg_split('/\R/u', self::tiszta($szoveg)) ?: [] as $bekezdes) {
            $bekezdes = trim((string) preg_replace('/ +/u', ' ', $bekezdes));

            if ($bekezdes === '') {
                $ki[] = '';

                continue;
            }

            $sor = '';

            foreach (explode(' ', $bekezdes) as $szo) {
                $proba = $sor === '' ? $szo : $sor . ' ' . $szo;

                if ($this->GetStringWidth($proba) <= $w) {
                    $sor = $proba;

                    continue;
                }

                if ($sor !== '') {
                    $ki[] = $sor;
                }

                // Maga a szó is hosszabb a sornál (pl. link, IMEI-sor): betűnként törik.
                while (mb_strlen($szo) > 1 && ($teljes = $this->GetStringWidth($szo)) > $w) {
                    // Becsült vágáspont a szélességek arányából (nem egyesével a végéről: az hosszú szónál lassú).
                    $hossz = mb_strlen($szo);
                    $k     = max(1, min($hossz - 1, (int) floor($hossz * $w / $teljes)));

                    while ($k > 1 && $this->GetStringWidth(mb_substr($szo, 0, $k)) > $w) {
                        $k--;
                    }

                    while ($k < $hossz - 1 && $this->GetStringWidth(mb_substr($szo, 0, $k + 1)) <= $w) {
                        $k++;
                    }

                    $ki[] = mb_substr($szo, 0, $k);
                    $szo  = mb_substr($szo, $k);
                }

                $sor = $szo;
            }

            if ($sor !== '') {
                $ki[] = $sor;
            }
        }

        // A végi üres sorok nem kellenek.
        while ($ki !== [] && end($ki) === '') {
            array_pop($ki);
        }

        return $ki === [] ? [''] : $ki;
    }

    /** Szaggatott vonal (kitöltendő hely). */
    public function pontvonal(float $x, float $y, float $w): void
    {
        $this->SetDrawColor(120, 120, 120);
        $this->SetLineWidth(0.18);
        $this->_out(sprintf('[%.3F %.3F] 0 d', 0.25 * $this->k, 0.85 * $this->k));
        $this->Line($x, $y, $x + $w, $y);
        $this->_out('[] 0 d');
    }

    /* =================================================================
     * Lábléc
     * ============================================================== */

    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- a tFPDF hívja ezen a néven.
    public function Footer()
    {
        $y = $this->h - $this->margo - $this->lab_magas + 1.6;

        $this->SetDrawColor(170, 170, 170);
        $this->SetLineWidth(0.2);
        $this->Line($this->lMargin, $y, $this->w - $this->rMargin, $y);
        $y += 1.2;

        $this->betu('', 0.8);
        $this->tinta('halvany');

        foreach ($this->lab_sorok as $sor) {
            $this->SetXY($this->lMargin, $y);
            $this->Cell($this->szeles(), $this->sm(0.8), self::tiszta($sor), 0, 0, 'C');
            $y += $this->sm(0.8);
        }

        $oldal = $this->PageNo() - $this->oldal_eltolas;
        $jobb  = 'Oldal ' . $oldal . ($this->oldal_ossz > 0 ? '/' . $this->oldal_ossz : '')
            . ($this->peldany_felirat !== '' ? '  ·  ' . $this->peldany_felirat : '');
        $jw    = $this->GetStringWidth($jobb) + 2;

        $this->SetXY($this->lMargin, $y);
        $this->Cell($this->szeles() - $jw, $this->sm(0.8), self::tiszta($this->lab_bal), 0, 0, 'L');
        $this->Cell($jw, $this->sm(0.8), $jobb, 0, 0, 'R');
        $this->tinta();
    }

    /* =================================================================
     * Fejléc
     * ============================================================== */

    /**
     * A nyomtatvány feje: logó, cégadatok, cím, a címhez tartozó adatpárok.
     *
     * @param array{logo?: string, logo_szel?: float, logo_hely?: string, ceg?: array<int, string>, cim?: string, adatok?: array<int, array{0: string, 1: string}>} $f
     */
    public function fej(array $f): void
    {
        $bal    = $this->lMargin;
        $sz     = $this->szeles();
        $y0     = $this->GetY();
        $logo   = (string) ($f['logo'] ?? '');
        $lsz    = min($sz * 0.45, max(8.0, (float) ($f['logo_szel'] ?? 30)));
        $hely   = (string) ($f['logo_hely'] ?? 'bal');
        $hely   = in_array($hely, ['bal', 'jobb', 'kozep', 'nincs'], true) ? $hely : 'bal';
        $ceg    = array_values(array_filter(array_map([self::class, 'tiszta'], (array) ($f['ceg'] ?? [])), static fn (string $s): bool => trim($s) !== ''));
        $cim    = self::tiszta((string) ($f['cim'] ?? ''));
        $adatok = (array) ($f['adatok'] ?? []);
        $lm     = 0.0;

        if ($logo !== '' && $hely !== 'nincs' && is_readable($logo)) {
            // Csak PNG és JPG jöhet: a fájl elejéből látszik, hogy valóban kép-e.
            $eleje = (string) file_get_contents($logo, false, null, 0, 8);
            $kep   = strncmp($eleje, "\x89PNG", 4) === 0 || strncmp($eleje, "\xFF\xD8", 2) === 0;
            $meret = $kep ? @getimagesize($logo) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors

            if (is_array($meret) && $meret[0] > 0) {
                $lm = $lsz * $meret[1] / $meret[0];

                // Nagyon magas logó ne tolja szét a fejlécet.
                if ($lm > 32) {
                    $lsz *= 32 / $lm;
                    $lm   = 32;
                }
            } else {
                $logo = '';
            }
        } else {
            $logo = '';
        }

        $keskeny = $sz < 150;                    // A5: a cím a cégadatok alá kerül
        $cim_w   = $keskeny ? $sz : $sz * 0.46;
        $ceg_x   = $bal;
        $ceg_w   = $keskeny ? $sz : $sz * 0.52;
        $y       = $y0;

        if ($logo !== '') {
            // A PDF-készítő nem minden képet ismer (váltottsoros vagy 16 bites PNG): hibás logó miatt
            // ne maradjon el a nyomtatvány – akkor logó nélkül készül.
            try {
                $this->Image($logo, $hely === 'kozep' ? $bal + ($sz - $lsz) / 2 : ($hely === 'jobb' ? $bal + $sz - $lsz : $bal), $y, $lsz);
            } catch (Throwable $hiba) {
                $logo = '';
                $lm   = 0.0;
            }
        }

        if ($logo !== '') {
            if ($hely === 'kozep') {
                $y += $lm + 2.5;
            } elseif ($hely === 'bal') {
                $ceg_x  = $bal + $lsz + 4;
                $ceg_w -= $lsz + 4;
            }
        }

        // Jobbra tett logónál a cím balra kerül, a cégadatok a logó alá/elé férnek.
        $cim_jobbra = $hely !== 'jobb';
        $yc         = $y;

        foreach ($ceg as $i => $sor) {
            $this->betu($i === 0 ? 'B' : '', $i === 0 ? 1.08 : 0.92);
            $this->tinta($i === 0 ? 'fo' : 'fo');

            foreach ($this->tordel($sor, max(20.0, $ceg_w)) as $resz) {
                $this->SetXY($ceg_x, $yc);
                $this->Cell($ceg_w, $this->sm($i === 0 ? 1.08 : 0.92), $resz, 0, 0, 'L');
                $yc += $this->sm($i === 0 ? 1.08 : 0.92);
            }
        }

        $yt = $keskeny ? max($yc, $y + ($hely === 'bal' ? $lm : 0)) + 2 : $y;
        $tx = $keskeny || !$cim_jobbra ? $bal : $bal + $sz - $cim_w;
        $ig = $keskeny || !$cim_jobbra ? 'L' : 'R';

        if ($hely === 'jobb' && !$keskeny) {
            // Logó jobbra: a cím a cégadatok ALÁ, balra.
            $yt = max($yc, $y + $lm) + 2;
            $cim_w = $sz;
        }

        if ($cim !== '') {
            $szorzo = 1.9;
            $nagy   = mb_strtoupper($cim, 'UTF-8');

            $this->betu('B', $szorzo);

            while ($szorzo > 1.15 && $this->GetStringWidth($nagy) > $cim_w) {
                $szorzo -= 0.05;
                $this->betu('B', $szorzo);
            }

            $this->tinta('fo');
            $this->SetXY($tx, $yt);
            $this->Cell($cim_w, $this->sm($szorzo), $nagy, 0, 0, $ig);
            $yt += $this->sm($szorzo) + 0.6;
        }

        foreach ($adatok as $par) {
            $c = self::tiszta((string) ($par[0] ?? ''));
            $e = self::tiszta((string) ($par[1] ?? ''));

            if ($c === '' && $e === '') {
                continue;
            }

            $this->betu('B', 1.0);
            $ew = $this->GetStringWidth($e) + 0.8;
            $this->betu('', 1.0);
            $cw = $this->GetStringWidth($c . ' ') + 0.8;

            $this->tinta('halvany');
            $this->SetXY($ig === 'R' ? $tx + $cim_w - $ew - $cw : $tx, $yt);
            $this->Cell($cw, $this->sm(), $c, 0, 0, 'L');
            $this->tinta('fo');
            $this->betu('B', 1.0);
            $this->Cell($ew, $this->sm(), $e, 0, 0, 'L');
            $yt += $this->sm();
        }

        $also = max($yc, $yt, $y + ($hely === 'kozep' ? 0 : $lm)) + 2.4;

        $this->SetFillColor($this->kiemelo[0], $this->kiemelo[1], $this->kiemelo[2]);
        $this->Rect($bal, $also, $sz, 0.8, 'F');
        $this->SetY($also + 3.2);
        $this->tinta();
    }

    /* =================================================================
     * Építőelemek
     * ============================================================== */

    /**
     * Képek egy sorban (F3: átvételi fotók). Elemenként „út|TÍPUS” (JPG / PNG); legfeljebb négy,
     * egyforma szélességben, legfeljebb 38 mm magasan. A hibás kép kimarad.
     *
     * @param array<int, string> $kepek
     */
    public function kepsor(array $kepek): void
    {
        $kepek = array_slice(array_values($kepek), 0, 4);

        if ($kepek === []) {
            return;
        }

        $res = 4.0;
        $w   = ($this->szeles() - $res * 3) / 4;
        $max = 38.0;

        $this->hely($max + 3);
        $y  = $this->GetY() + 1;
        $mm = 0.0;

        foreach ($kepek as $i => $kep) {
            [$ut, $tipus] = array_pad(explode('|', (string) $kep, 2), 2, 'JPG');
            $meret        = is_file($ut) ? @getimagesize($ut) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors

            if (!is_array($meret) || (int) $meret[0] < 1 || (int) $meret[1] < 1) {
                continue;
            }

            $kw = $w;
            $kh = $w * (int) $meret[1] / (int) $meret[0];

            if ($kh > $max) {
                $kh = $max;
                $kw = $max * (int) $meret[0] / (int) $meret[1];
            }

            try {
                $this->Image($ut, $this->lMargin + $i * ($w + $res), $y, $kw, $kh, $tipus === 'PNG' ? 'PNG' : 'JPG');
                $mm = max($mm, $kh);
            } catch (Throwable $e) {
                continue;
            }
        }

        $this->SetY($y + $mm + 2);
    }

    /** Szakaszcím vékony kiemelő vonallal. */
    public function szakasz(string $cim): void
    {
        $this->hely($this->sm(1.05) + 2 * $this->sm() + 5);
        $this->Ln(1.8);
        $this->betu('B', 1.05);
        $this->tinta('fo');
        $this->SetX($this->lMargin);
        $this->Cell($this->szeles(), $this->sm(1.05), self::tiszta($cim), 0, 1, 'L');

        $y = $this->GetY() + 0.3;

        $this->SetDrawColor($this->kiemelo[0], $this->kiemelo[1], $this->kiemelo[2]);
        $this->SetLineWidth(0.3);
        $this->Line($this->lMargin, $y, $this->lMargin + $this->szeles(), $y);
        $this->SetY($y + 1.3);
    }

    /**
     * Címke–érték párok rácsban: a címke kicsi és szürke, alatta az érték.
     *
     * @param array<int, array{c: string, e?: string, sz?: int, b?: bool}> $parok  sz = hány oszlopot foglal
     * @param bool $ures  kitöltendő (bianko): az érték helyén pontvonal
     */
    public function parok(array $parok, int $oszlop = 2, bool $ures = false): void
    {
        $oszlop = max(1, $oszlop);
        $w1     = $this->szeles() / $oszlop;
        $sorok  = [];
        $akt    = [];
        $hasznalt = 0;

        // Egy oldalnál hosszabb érték (ritka: nagyon hosszú megjegyzés) darabokban, teljes szélességben
        // folytatódik – különben a cella kilógna a lapról.
        if (!$ures) {
            $max_sor = max(8, (int) floor(($this->PageBreakTrigger - $this->tMargin - 30) / $this->sm(1.0)));
            $bontott = [];

            foreach ($parok as $p) {
                $this->betu(!empty($p['b']) ? 'B' : '', 1.0);
                $resz = $this->tordel((string) ($p['e'] ?? ''), $w1 * min($oszlop, max(1, (int) ($p['sz'] ?? 1))) - 3);

                if (count($resz) <= $max_sor) {
                    $bontott[] = $p;

                    continue;
                }

                foreach (array_chunk($this->tordel((string) ($p['e'] ?? ''), $w1 * $oszlop - 3), $max_sor) as $i => $darab) {
                    $bontott[] = ['c' => $i === 0 ? (string) $p['c'] : (string) $p['c'] . ' (folytatás)', 'e' => implode("\n", $darab), 'sz' => $oszlop, 'b' => !empty($p['b'])];
                }
            }

            $parok = $bontott;
        }

        foreach ($parok as $p) {
            $sz = min($oszlop, max(1, (int) ($p['sz'] ?? 1)));

            if ($hasznalt + $sz > $oszlop) {
                $sorok[]  = $akt;
                $akt      = [];
                $hasznalt = 0;
            }

            $akt[]     = ['sz' => $sz] + $p;
            $hasznalt += $sz;
        }

        if ($akt !== []) {
            $sorok[] = $akt;
        }

        $cm = $this->sm(0.76);
        $em = $this->sm(1.0);

        foreach ($sorok as $sor) {
            $cellak = [];
            $max    = 1;

            foreach ($sor as $p) {
                $w = $w1 * $p['sz'] - 3;

                $this->betu(!empty($p['b']) ? 'B' : '', 1.0);
                $reszek   = $ures ? [''] : $this->tordel((string) ($p['e'] ?? ''), $w);
                $cellak[] = [$p, $w, $reszek];
                $max      = max($max, count($reszek));
            }

            $magas = $ures ? $cm + 6.4 : $cm + $max * $em + 1.6;

            $this->hely($magas);

            $y = $this->GetY();
            $x = $this->lMargin;

            // A sor egyben van: rajzolás közben a könyvtár ne törjön oldalt cellánként.
            $this->SetAutoPageBreak(false);

            foreach ($cellak as [$p, $w, $reszek]) {
                $this->betu('', 0.76);
                $this->tinta('halvany');
                $this->SetXY($x, $y);
                $this->Cell($w, $cm, self::tiszta((string) $p['c']), 0, 0, 'L');

                if ($ures) {
                    $this->pontvonal($x, $y + $cm + 5.0, $w);
                } else {
                    $this->betu(!empty($p['b']) ? 'B' : '', 1.0);
                    $this->tinta('fo');

                    foreach ($reszek as $i => $resz) {
                        $this->SetXY($x, $y + $cm + $i * $em);
                        $this->Cell($w, $em, $resz, 0, 0, 'L');
                    }
                }

                $x += $w1 * $p['sz'];
            }

            if (!$ures) {
                $this->SetDrawColor(215, 215, 215);
                $this->SetLineWidth(0.15);
                $this->Line($this->lMargin, $y + $magas - 0.5, $this->lMargin + $this->szeles(), $y + $magas - 0.5);
            }

            $this->SetAutoPageBreak(true, $this->margo + $this->lab_magas);
            $this->SetY($y + $magas);
        }

        $this->tinta();
    }

    /**
     * Táblázat. Hosszú szöveg több sorba törik, laptörésnél a fejléc megismétlődik.
     *
     * @param array<int, array{c: string, sz: float, ig?: string}> $oszlopok  sz = a szélesség aránya
     * @param array<int, array{c: array<int, string>, al?: string, al_o?: int, b?: bool, magas?: float}> $sorok
     * @param array{zebra?: bool, racs?: bool, meret?: float} $o
     */
    public function tabla(array $oszlopok, array $sorok, array $o = []): void
    {
        $ossz  = array_sum(array_map(static fn (array $c): float => (float) $c['sz'], $oszlopok)) ?: 1.0;
        $sz    = $this->szeles();
        $w     = array_map(static fn (array $c): float => $sz * (float) $c['sz'] / $ossz, $oszlopok);
        $meret = (float) ($o['meret'] ?? 0.95);
        $sm    = $this->sm($meret);
        $zebra = !isset($o['zebra']) || !empty($o['zebra']);
        $racs  = !empty($o['racs']);
        $bal   = $this->lMargin;

        $fejlec = function () use ($oszlopok, $w, $bal, $sz, $meret): void {
            $fm = $this->sm($meret) + 1.6;
            $y  = $this->GetY();

            $this->SetFillColor(232, 232, 232);
            $this->Rect($bal, $y, $sz, $fm, 'F');
            $this->betu('B', $meret * 0.94);
            $this->tinta('fo');

            $x = $bal;

            foreach ($oszlopok as $i => $c) {
                $this->SetXY($x + 1.2, $y + 0.8);
                $this->Cell($w[$i] - 2.4, $this->sm($meret), self::tiszta((string) $c['c']), 0, 0, (string) ($c['ig'] ?? 'L'));
                $x += $w[$i];
            }

            $this->SetY($y + $fm);
        };

        $this->hely($sm * 3 + 4);
        $fejlec();

        // Egy oldalnál magasabb sor (ritka: nagyon hosszú szöveg egy cellában) több, egymást követő sorra bomlik.
        $max_sor = max(6, (int) floor(($this->PageBreakTrigger - $this->tMargin - 2 * ($sm + 1.6) - 6) / $sm));
        $bontott = [];

        foreach (array_values($sorok) as $sor) {
            $this->betu(!empty($sor['b']) ? 'B' : '', $meret);

            $oszl = [];
            $leg  = 1;
            $ao0  = min(count($oszlopok) - 1, max(0, (int) ($sor['al_o'] ?? 0)));

            foreach ($oszlopok as $i => $c) {
                $oszl[$i] = $this->tordel((string) ($sor['c'][$i] ?? ''), $w[$i] - 2.4);
                $leg      = max($leg, count($oszl[$i]));
            }

            $al_db = trim((string) ($sor['al'] ?? '')) !== '' ? count($this->tordel((string) $sor['al'], $w[$ao0] - 2.4)) : 0;

            if ($leg + $al_db <= $max_sor) {
                $bontott[] = $sor;

                continue;
            }

            // Az alsor ilyenkor a saját oszlopának szövegéhez fűződik, és a sor darabonként megy tovább.
            if ($al_db > 0) {
                $oszl[$ao0] = array_merge($oszl[$ao0], $this->tordel((string) $sor['al'], $w[$ao0] - 2.4));
                $leg        = max($leg, count($oszl[$ao0]));
            }

            for ($kezd = 0; $kezd < $leg; $kezd += $max_sor) {
                $bontott[] = [
                    'c' => array_map(static fn (array $l): string => implode("\n", array_slice($l, $kezd, $max_sor)), $oszl),
                    'b' => !empty($sor['b']),
                ];
            }
        }

        $sorok = $bontott;

        foreach (array_values($sorok) as $n => $sor) {
            $vastag = !empty($sor['b']);
            $cellak = [];
            $max    = 1;

            $this->betu($vastag ? 'B' : '', $meret);

            foreach ($oszlopok as $i => $c) {
                $reszek   = $this->tordel((string) ($sor['c'][$i] ?? ''), $w[$i] - 2.4);
                $cellak[] = $reszek;
                $max      = max($max, count($reszek));
            }

            $al_sorok = [];
            // Az alsor (kisebb, szürke megjegyzés) melyik oszlop alá kerül – alapból az első alá.
            $ao       = min(count($oszlopok) - 1, max(0, (int) ($sor['al_o'] ?? 0)));

            if (trim((string) ($sor['al'] ?? '')) !== '') {
                $this->betu('', $meret * 0.86);
                $al_sorok = $this->tordel((string) $sor['al'], $w[$ao] - 2.4);
            }

            $alm   = $this->sm($meret * 0.86);
            $magas = max((float) ($sor['magas'] ?? 0), max($max * $sm, count($cellak[$ao]) * $sm + count($al_sorok) * $alm) + 1.4);

            if ($this->GetY() + $magas > $this->PageBreakTrigger) {
                $this->AddPage();
                $fejlec();
            }

            $y = $this->GetY();

            // A sor egyben van (a laptörésről fent döntöttünk): rajzolás közben a könyvtár ne törjön oldalt.
            $this->SetAutoPageBreak(false);

            if ($zebra && !$racs && $n % 2 === 1) {
                $this->SetFillColor(246, 246, 246);
                $this->Rect($bal, $y, $sz, $magas, 'F');
            }

            $x = $bal;

            foreach ($oszlopok as $i => $c) {
                $this->betu($vastag ? 'B' : '', $meret);
                $this->tinta('fo');

                foreach ($cellak[$i] as $k => $resz) {
                    $this->SetXY($x + 1.2, $y + 0.7 + $k * $sm);
                    $this->Cell($w[$i] - 2.4, $sm, $resz, 0, 0, (string) ($c['ig'] ?? 'L'));
                }

                if ($i === $ao && $al_sorok !== []) {
                    $this->betu('', $meret * 0.86);
                    $this->tinta('halvany');

                    foreach ($al_sorok as $k => $resz) {
                        $this->SetXY($x + 1.2, $y + 0.7 + count($cellak[$ao]) * $sm + $k * $alm);
                        $this->Cell($w[$ao] - 2.4, $alm, $resz, 0, 0, 'L');
                    }
                }

                if ($racs) {
                    $this->SetDrawColor(150, 150, 150);
                    $this->SetLineWidth(0.15);
                    $this->Rect($x, $y, $w[$i], $magas);
                }

                $x += $w[$i];
            }

            if (!$racs) {
                $this->SetDrawColor(222, 222, 222);
                $this->SetLineWidth(0.12);
                $this->Line($bal, $y + $magas, $bal + $sz, $y + $magas);
            }

            $this->SetAutoPageBreak(true, $this->margo + $this->lab_magas);
            $this->SetY($y + $magas);
        }

        $this->tinta();
        $this->Ln(1.2);
    }

    /**
     * Jobbra zárt összesítő; a kiemelt sor színes sávban áll.
     *
     * @param array<int, array{c: string, e: string, ki?: bool, b?: bool}> $sorok
     */
    public function osszesito(array $sorok): void
    {
        $w  = min($this->szeles(), max(72.0, $this->szeles() * 0.46));
        $x  = $this->lMargin + $this->szeles() - $w;
        $sm = $this->sm(1.0) + 0.6;
        $km = $this->sm(1.2) + 2.2;

        $magas = 2.0;

        foreach ($sorok as $s) {
            $magas += !empty($s['ki']) ? $km + 0.8 : $sm;
        }

        $this->hely($magas);
        $this->Ln(0.8);

        foreach ($sorok as $s) {
            $y = $this->GetY();

            if (!empty($s['ki'])) {
                $this->SetFillColor($this->kiemelo[0], $this->kiemelo[1], $this->kiemelo[2]);
                $this->Rect($x, $y + 0.4, $w, $km, 'F');
                $this->tinta('feher');
                $this->betu('', 1.0);
                $this->SetXY($x + 2, $y + 0.4);
                $this->Cell($w * 0.5, $km, self::tiszta((string) $s['c']), 0, 0, 'L');
                $this->betu('B', 1.2);
                $this->SetXY($x + $w * 0.4, $y + 0.4);
                $this->Cell($w * 0.6 - 2, $km, self::tiszta((string) $s['e']), 0, 0, 'R');
                $this->SetY($y + $km + 0.8);
                $this->tinta();

                continue;
            }

            $this->betu('', 1.0);
            $this->tinta(!empty($s['b']) ? 'fo' : 'halvany');
            $this->SetXY($x + 2, $y);
            $this->Cell($w * 0.55, $sm, self::tiszta((string) $s['c']), 0, 0, 'L');
            $this->tinta('fo');
            $this->betu(!empty($s['b']) ? 'B' : '', 1.0);
            $this->SetXY($x + $w * 0.5, $y);
            $this->Cell($w * 0.5 - 2, $sm, self::tiszta((string) $s['e']), 0, 0, 'R');
            $this->SetY($y + $sm);
        }

        $this->tinta();
        $this->Ln(1.2);
    }

    /** Folyó szöveg, tördelve, laptöréssel. */
    public function bekezdes(string $szoveg, float $szorzo = 1.0, string $tinta = 'fo', string $stilus = ''): void
    {
        if (trim($szoveg) === '') {
            return;
        }

        $this->betu($stilus, $szorzo);

        $sm = $this->sm($szorzo);

        foreach ($this->tordel($szoveg, $this->szeles()) as $sor) {
            $this->hely($sm);
            $this->betu($stilus, $szorzo);
            $this->tinta($tinta);
            $this->SetX($this->lMargin);
            $this->Cell($this->szeles(), $sm, $sor, 0, 1, 'L');
        }

        $this->tinta();
        $this->Ln(1.0);
    }

    /** Üres, kitöltendő sorok (bianko). */
    public function ures_sorok(int $db, float $koz = 6.5): void
    {
        for ($i = 0; $i < $db; $i++) {
            $this->hely($koz);
            $this->pontvonal($this->lMargin, $this->GetY() + $koz - 1.2, $this->szeles());
            $this->SetY($this->GetY() + $koz);
        }

        $this->Ln(1.0);
    }

    /**
     * Kelt + aláírások.
     *
     * @param array<int, string> $feliratok  az aláírásvonalak alatti szövegek (1–3)
     * @param array<int, string> $elotte     kitöltendő sorok az aláírás fölött (pl. „Név", „Igazolványszám")
     */
    public function alairasok(array $feliratok, string $kelt = '', array $elotte = [], bool $ph = false, array $kepek = []): void
    {
        // $kepek: az aláírásvonal sorszáma (a szűrés előtti) => PNG-fájl útja – a vonal fölé rajzolt aláírás (F3).
        $kepek_eredeti = $kepek;
        $kepek         = [];
        $j             = 0;

        foreach (array_map('strval', $feliratok) as $i => $f) {
            if (trim($f) === '') {
                continue;
            }

            if (!empty($kepek_eredeti[$i]) && is_file((string) $kepek_eredeti[$i])) {
                $kepek[$j] = (string) $kepek_eredeti[$i];
            }

            $j++;
        }

        $feliratok = array_values(array_filter(array_map('strval', $feliratok), static fn (string $s): bool => trim($s) !== ''));
        $magas     = ($kelt !== '' ? $this->sm() + 2 : 0) + count($elotte) * 7.5 + ($feliratok !== [] ? 19 + $this->sm(0.85) : 0);

        $this->hely($magas + 2);
        $this->Ln(2.2);

        if ($kelt !== '') {
            $this->betu('', 1.0);
            $this->tinta('fo');
            $this->SetX($this->lMargin);
            $this->Cell($this->szeles(), $this->sm(), self::tiszta($kelt), 0, 1, 'L');
            $this->Ln(1.0);
        }

        foreach ($elotte as $cimke) {
            $y = $this->GetY();

            $this->betu('', 1.0);
            $this->tinta('fo');
            $this->SetXY($this->lMargin, $y + 2.6);

            $cw = $this->GetStringWidth(self::tiszta($cimke) . ': ') + 1;

            $this->Cell($cw, $this->sm(), self::tiszta($cimke) . ':', 0, 0, 'L');
            $this->pontvonal($this->lMargin + $cw, $y + 2.6 + $this->sm() - 0.6, min(90.0, $this->szeles() - $cw));
            $this->SetY($y + 7.5);
        }

        if ($feliratok === []) {
            return;
        }

        $n  = count($feliratok);
        $sz = $this->szeles();
        $w  = min(62.0, ($sz - ($n - 1) * 8) / $n);
        $y  = $this->GetY() + 15;

        foreach ($feliratok as $i => $felirat) {
            // Egy aláírás jobbra, kettő a két szélre, három egyenletesen.
            $x = $n === 1 ? $this->lMargin + $sz - $w : $this->lMargin + $i * ($sz - $w) / ($n - 1);

            if (isset($kepek[$i])) {
                try {
                    $this->Image($kepek[$i], $x + 2, $y - 14, 0, 13, 'PNG');
                } catch (Throwable $e) {
                    // A hibás kép nem akasztja meg a nyomtatványt: üres vonal marad.
                }
            }

            $this->SetDrawColor(60, 60, 60);
            $this->SetLineWidth(0.2);
            $this->Line($x, $y, $x + $w, $y);
            $this->betu('', 0.85);
            $this->tinta('halvany');
            $this->SetXY($x, $y + 0.8);
            $this->Cell($w, $this->sm(0.85), self::tiszta($felirat), 0, 0, 'C');
        }

        if ($ph && $n >= 2) {
            $this->betu('', 0.85);
            $this->tinta('halvany');
            $this->SetXY($this->lMargin, $y - 6);
            $this->Cell($sz, $this->sm(0.85), 'P. H.', 0, 0, 'C');
        }

        $this->tinta();
        $this->SetY($y + $this->sm(0.85) + 2.5);
    }

    /**
     * QR-kód a megadott helyre.
     *
     * @param array<int, array<int, bool>> $matrix
     */
    public function qr(array $matrix, float $x, float $y, float $meret): void
    {
        $n = count($matrix);

        if ($n === 0) {
            return;
        }

        $m = $meret / $n;

        $this->SetFillColor(0, 0, 0);

        foreach ($matrix as $r => $sor) {
            // Az egymás melletti sötét modulok egy téglalapban: kisebb fájl, nincs hajszálrés.
            $kezd = -1;

            for ($c = 0; $c <= $n; $c++) {
                $sotet = $c < $n && !empty($sor[$c]);

                if ($sotet && $kezd < 0) {
                    $kezd = $c;
                } elseif (!$sotet && $kezd >= 0) {
                    $this->Rect($x + $kezd * $m, $y + $r * $m, ($c - $kezd) * $m, $m + 0.02, 'F');
                    $kezd = -1;
                }
            }
        }
    }
}
