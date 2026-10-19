<?php
/**
 * SDH Értékesítés – banki kivonat / értesítő beolvasása és párosítása a rendelésekhez.
 *
 * Bemenet: CSV (OTP – fejléc nélküli exportja is –, K&H, Erste, MBH, Raiffeisen, Wise, általános fejléces),
 * Windows-1250 / UTF-8 (BOM-mal vagy anélkül) felismeréssel, `;` `,` tab elválasztóval; CAMT.053 XML (ISO 20022,
 * bármely camt.053.001.xx névtér); bemásolt banki értesítő e-mail / SMS szövege.
 * Párosítás sorrendje: (1) közlemény pontosan, (2) közlemény normalizálva (szóköz, kötőjel, kisbetű, O→0 nélkül is),
 * (3) rendelésazonosító (R-2026-000123) a szövegben, (4) összeg + név (egyetlen nyitott rendelés, azonos hátralékkal és
 * hasonló cégnévvel). Csak jóváírás (bejövő, HUF) számít.
 * Rögzítés: SDH_Sales_Rendeles::fizetve($id, 'bank_import', <banki hivatkozás | 'h:'+lenyomat>, $osszeg, $nyers).
 *
 * API:
 *   feldolgoz(string $tartalom, string $fajlnev = ''): array{formatum, sorok, jelentes{parositva[], mar_feldolgozva[],
 *     nem_parosithato[], osszeg_elteres[], kihagyva}, hibak[]}
 *   ertelmez(string $tartalom, string $fajlnev = ''): array{formatum, tetelek[], hibak[]}   – párosítás nélkül
 *   level_ertelmez(string $szoveg): ?array  – egy banki értesítő (e-mail / SMS) → tétel
 *   parosit(array $tetel): array{rendeles: ?array, mod: string}
 *   osszeg(string): ?int                    – „+12 700,00 Ft” → 12700
 * Admin REST: POST /admin/bank/import {tartalom (szöveg vagy base64:…), fajlnev} · POST /admin/bank/level {szoveg}.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Bank_Import
{
    public const MAX_BAJT = 5 * 1024 * 1024;

    /** Fejléc-szinonimák (ékezet és kis/nagybetű nélkül összevetve). */
    private const OSZLOP = [
        'datum'      => ['konyveles datuma', 'konyvelesi datum', 'konyveles napja', 'tranzakcio datuma', 'teljesites datuma', 'erteknap', 'datum', 'date', 'created on', 'booking date', 'konyvelesi nap'],
        'osszeg'     => ['osszeg', 'amount', 'tranzakcio osszege', 'konyvelt osszeg', 'osszeg (huf)', 'osszeg huf'],
        'jovairas'   => ['jovairas', 'jovairas osszege', 'credit', 'bejovo'],
        'terheles'   => ['terheles', 'terheles osszege', 'debit', 'kimeno'],
        'irany'      => ['t/j', 'terheles/jovairas', 'jovairas/terheles', 'irany', 'direction', 'tipus jelzo'],
        'penznem'    => ['devizanem', 'penznem', 'deviza', 'currency', 'devizanem kod'],
        'partner'    => ['partner neve', 'ellenoldali nev', 'ellenoldali partner neve', 'ellenpartner neve', 'partner nev', 'kedvezmenyezett/fizeto neve', 'fizeto neve', 'befizeto neve', 'kuldo neve', 'payer name', 'payer', 'counterparty', 'counterparty name', 'partner', 'nev'],
        'partner_sz' => ['partner szamlaszama', 'ellenoldali szamlaszam', 'ellenszamla', 'ellenpartner szamlaszama', 'fizeto szamlaszama', 'payer account', 'partner szamlaszam', 'ellenoldali szamla'],
        'kozlemeny'  => ['kozlemeny', 'kozlemeny 1', 'kozlemeny/megjegyzes', 'megjegyzes', 'reference', 'payment reference', 'description', 'tranzakcio leirasa', 'leiras', 'kozlemeny, leiras'],
        'ref'        => ['tranzakcio azonosito', 'tranzakcioazonosito', 'banki hivatkozas', 'banki azonosito', 'referencia', 'transferwise id', 'id', 'bank reference', 'tranzakcio id', 'hivatkozasi szam'],
    ];

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/admin/bank/import', 'POST', [self::class, 'rest_import'], 'admin', ['bank_import', 20, 60]);
        SDH_Sales_Rest::ut('/admin/bank/level', 'POST', [self::class, 'rest_level'], 'admin', ['bank_level', 60, 60]);
    }

    /* =================================================================
     * Segédek
     * ============================================================== */

    public static function utf8(string $s): string
    {
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            $s = substr($s, 3);
        } elseif (strncmp($s, "\xFF\xFE", 2) === 0 || strncmp($s, "\xFE\xFF", 2) === 0) {
            $c = @iconv('UTF-16', 'UTF-8//IGNORE', $s);
            $s = is_string($c) ? $c : '';
        }

        if (!mb_check_encoding($s, 'UTF-8')) {
            $c = @iconv('WINDOWS-1250', 'UTF-8//IGNORE', $s);
            $s = is_string($c) ? $c : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-2');
        }

        return str_replace(["\r\n", "\r"], "\n", $s);
    }

    public static function ekezettelen(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u', 'ô' => 'o', 'õ' => 'o', 'û' => 'u']);
    }

    /** Pénzösszeg szövegből (egész forint, előjellel). */
    public static function osszeg(string $s): ?int
    {
        $s = trim(str_replace(["\u{00A0}", "\u{202F}", ' ', "'"], '', $s));
        $s = (string) preg_replace('/(HUF|Ft|FT|ft)\.?$/u', '', $s);
        $s = (string) preg_replace('/^(HUF|Ft)/u', '', $s);
        $neg = false;

        if ($s !== '' && ($s[0] === '-' || $s[0] === '+' || strpos($s, '−') === 0)) {
            $neg = $s[0] === '-' || strpos($s, '−') === 0;
            $s   = (string) preg_replace('/^[+\-]|^−/u', '', $s);
        }

        if (preg_match('/^\d[\d.,]*$/', $s) !== 1) {
            return null;
        }

        $pv = strrpos($s, ',');
        $pp = strrpos($s, '.');

        if ($pv !== false && $pp !== false) {
            $dec = $pv > $pp ? ',' : '.';
        } elseif ($pv !== false) {
            $dec = preg_match('/,\d{1,2}$/', $s) === 1 ? ',' : '';
        } elseif ($pp !== false) {
            $dec = preg_match('/\.\d{1,2}$/', $s) === 1 && substr_count($s, '.') === 1 ? '.' : '';
        } else {
            $dec = '';
        }

        $egesz = $dec !== '' ? substr($s, 0, (int) strrpos($s, $dec)) : $s;
        $tort  = $dec !== '' ? substr($s, (int) strrpos($s, $dec) + 1) : '';
        $egesz = (string) preg_replace('/[.,]/', '', $egesz);

        if ($egesz === '' || !ctype_digit($egesz)) {
            return null;
        }

        $n = (int) $egesz + ((int) str_pad(substr($tort, 0, 2), 2, '0') >= 50 ? 1 : 0);

        return $neg ? -$n : $n;
    }

    public static function datum(string $s): string
    {
        $s = trim($s);

        if (preg_match('/^(\d{4})[.\-\/]?\s?(\d{2})[.\-\/]?\s?(\d{2})/', $s, $m) === 1) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }

        if (preg_match('/^(\d{2})[.\-\/](\d{2})[.\-\/](\d{4})/', $s, $m) === 1) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return '';
    }

    /* =================================================================
     * CSV
     * ============================================================== */

    private static function elvalaszto(string $sor): string
    {
        $db = [';' => substr_count($sor, ';'), "\t" => substr_count($sor, "\t"), ',' => substr_count($sor, ',')];
        arsort($db);

        return (string) array_key_first($db);
    }

    /** @return array<int, array<int, string>> */
    private static function csv_sorok(string $s, string $el): array
    {
        $f = fopen('php://memory', 'r+');
        fwrite($f, $s);
        rewind($f);
        $ki = [];

        while (($r = fgetcsv($f, 0, $el, '"', '')) !== false) {
            if ($r === [null] || $r === []) {
                continue;
            }

            $ki[] = array_map(static fn ($x): string => trim((string) $x), $r);
        }

        fclose($f);

        return $ki;
    }

    /**
     * @return array{formatum: string, tetelek: array<int, array<string, mixed>>, hibak: array<int, string>}
     */
    private static function csv(string $s, string $fajlnev): array
    {
        $elso = strtok($s, "\n") ?: '';
        $el   = self::elvalaszto($elso);
        $sor  = self::csv_sorok($s, $el);
        $hib  = [];

        if ($sor === []) {
            return ['formatum' => 'csv', 'tetelek' => [], 'hibak' => ['Üres fájl.']];
        }

        // OTP fejléc nélküli export: számlaszám ; T/J ; összeg ; deviza ; könyvelés ; értéknap ; ellenszámla ; partner ; közlemény…
        if (isset($sor[0][1]) && in_array($sor[0][1], ['T', 'J'], true) && preg_match('/^\d{8}-?\d{8}(-?\d{8})?$/', str_replace(' ', '', $sor[0][0])) === 1) {
            $t = [];

            foreach ($sor as $i => $r) {
                if (count($r) < 8) {
                    continue;
                }

                $t[] = [
                    'datum'      => self::datum($r[4]),
                    'osszeg'     => ($r[1] === 'T' ? -1 : 1) * abs((int) self::osszeg($r[2])),
                    'penznem'    => strtoupper($r[3] ?: 'HUF'),
                    'partner_sz' => $r[6],
                    'partner'    => $r[7],
                    'kozlemeny'  => trim(implode(' ', array_slice($r, 8, 4))),
                    'ref'        => trim((string) ($r[12] ?? '')),
                    'sor'        => $i + 1,
                ];
            }

            return ['formatum' => 'otp', 'tetelek' => $t, 'hibak' => []];
        }

        // Fejléc keresése az első 15 sorban (a bankok egy része előtte számlaadatokat ír).
        $fej = -1;
        $map = [];

        foreach (array_slice($sor, 0, 15) as $i => $r) {
            $m = self::fejlec($r);

            if (isset($m['osszeg']) || isset($m['jovairas'])) {
                if (isset($m['kozlemeny']) || isset($m['partner'])) {
                    $fej = $i;
                    $map = $m;
                    break;
                }
            }
        }

        if ($fej < 0) {
            return ['formatum' => 'csv', 'tetelek' => [], 'hibak' => ['Nem ismerem fel az oszlopokat (összeg, közlemény / partner kell).']];
        }

        $h        = implode('|', array_map([self::class, 'ekezettelen'], $sor[$fej]));
        $formatum = 'altalanos';

        // A konkrétabb minta előbb („ellenoldali partner neve” ⊃ „partner neve”); oszlopnévre (| határral) illesztünk.
        $h = '|' . $h . '|';

        foreach (['wise' => 'transferwise id|payer name', 'raiffeisen' => 'ellenoldali partner neve', 'mbh' => 'ellenpartner neve', 'kh' => 'tranzakcio azonosito|ellenoldali nev', 'erste' => 'partner neve|partner szamlaszama'] as $b => $minta) {
            foreach (explode('|', $minta) as $x) {
                if (strpos($h, '|' . $x . '|') !== false) {
                    $formatum = $b;
                    break 2;
                }
            }
        }

        $t = [];

        foreach (array_slice($sor, $fej + 1, null, true) as $i => $r) {
            $g = static fn (string $k): string => isset($map[$k]) ? (string) ($r[$map[$k]] ?? '') : '';

            if (isset($map['osszeg'])) {
                $o = self::osszeg($g('osszeg'));

                if ($o !== null && isset($map['irany'])) {
                    $ir = self::ekezettelen($g('irany'));
                    $o  = abs($o) * (in_array($ir, ['t', 'terheles', 'debit', 'out', 'kimeno'], true) ? -1 : 1);
                }
            } else {
                $j = self::osszeg($g('jovairas'));
                $k = self::osszeg($g('terheles'));
                $o = $j !== null && $j !== 0 ? abs($j) : ($k !== null && $k !== 0 ? -abs($k) : null);
            }

            if ($o === null) {
                continue;
            }

            $t[] = [
                'datum'      => self::datum($g('datum')),
                'osszeg'     => $o,
                'penznem'    => strtoupper($g('penznem') ?: 'HUF'),
                'partner'    => $g('partner'),
                'partner_sz' => $g('partner_sz'),
                'kozlemeny'  => $g('kozlemeny'),
                'ref'        => $g('ref'),
                'sor'        => $i + 1,
            ];
        }

        if ($t === []) {
            $hib[] = 'Nincs feldolgozható tétel a fájlban.';
        }

        return ['formatum' => $formatum, 'tetelek' => $t, 'hibak' => $hib];
    }

    /** @param array<int, string> $r @return array<string, int> */
    private static function fejlec(array $r): array
    {
        $m = [];

        foreach ($r as $i => $c) {
            $c = trim((string) preg_replace('/\s+/', ' ', self::ekezettelen($c)), " \t\"'");

            foreach (self::OSZLOP as $k => $lista) {
                if (!isset($m[$k]) && in_array($c, $lista, true)) {
                    $m[$k] = $i;
                    continue 2;
                }
            }
        }

        // Ha van külön jóváírás-oszlop, az „összeg” nem kell.
        return $m;
    }

    /* =================================================================
     * CAMT.053
     * ============================================================== */

    /** @return array{formatum: string, tetelek: array<int, array<string, mixed>>, hibak: array<int, string>} */
    private static function camt(string $s): array
    {
        $elozo = libxml_use_internal_errors(true);
        $d     = new DOMDocument();
        $ok    = $d->loadXML($s, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($elozo);

        if (!$ok || $d->doctype !== null) {
            return ['formatum' => 'camt053', 'tetelek' => [], 'hibak' => ['Hibás XML.']];
        }

        $x  = new DOMXPath($d);
        $e  = static fn (string $q, ?DOMNode $n = null): string => trim((string) ($x->evaluate('string(' . $q . ')', $n)));
        $t  = [];
        $nr = 0;

        foreach ($x->query('//*[local-name()="Stmt"]/*[local-name()="Ntry"]') ?: [] as $ntry) {
            $nr++;
            $jel   = $e('./*[local-name()="CdtDbtInd"]', $ntry);
            $datum = self::datum($e('./*[local-name()="BookgDt"]/*[local-name()="Dt"]', $ntry) ?: $e('./*[local-name()="BookgDt"]/*[local-name()="DtTm"]', $ntry) ?: $e('./*[local-name()="ValDt"]/*[local-name()="Dt"]', $ntry));
            $nref  = $e('./*[local-name()="AcctSvcrRef"]', $ntry);
            $txek  = $x->query('./*[local-name()="NtryDtls"]/*[local-name()="TxDtls"]', $ntry);
            $txek  = $txek !== false && $txek->length > 0 ? iterator_to_array($txek) : [null];

            foreach ($txek as $i => $tx) {
                $amt = $tx !== null ? ($e('./*[local-name()="Amt"]', $tx) ?: $e('./*[local-name()="AmtDtls"]/*[local-name()="TxAmt"]/*[local-name()="Amt"]', $tx)) : '';
                $ccy = $tx !== null ? ($e('./*[local-name()="Amt"]/@Ccy', $tx) ?: $e('./*[local-name()="AmtDtls"]/*[local-name()="TxAmt"]/*[local-name()="Amt"]/@Ccy', $tx)) : '';

                if ($amt === '' || count($txek) === 1) {
                    $amt = $e('./*[local-name()="Amt"]', $ntry);
                    $ccy = $e('./*[local-name()="Amt"]/@Ccy', $ntry);
                }

                $tjel = $tx !== null ? ($e('./*[local-name()="CdtDbtInd"]', $tx) ?: $jel) : $jel;
                $o    = self::osszeg(str_replace('.', ',', $amt));
                $rp   = $tx !== null ? './*[local-name()="RltdPties"]/*[local-name()="Dbtr"]' : '';
                $nev  = $tx !== null ? ($e($rp . '/*[local-name()="Nm"]', $tx) ?: $e($rp . '/*[local-name()="Pty"]/*[local-name()="Nm"]', $tx)) : '';
                $iban = $tx !== null ? $e('./*[local-name()="RltdPties"]/*[local-name()="DbtrAcct"]/*[local-name()="Id"]/*[local-name()="IBAN"]', $tx) ?: $e('./*[local-name()="RltdPties"]/*[local-name()="DbtrAcct"]/*[local-name()="Id"]/*[local-name()="Othr"]/*[local-name()="Id"]', $tx) : '';
                $ustr = '';
                $ref  = '';

                if ($tx !== null) {
                    $u = [];

                    foreach ($x->query('./*[local-name()="RmtInf"]/*[local-name()="Ustrd"]', $tx) ?: [] as $n) {
                        $u[] = trim($n->textContent);
                    }

                    foreach ($x->query('./*[local-name()="RmtInf"]/*[local-name()="Strd"]//*[local-name()="Ref"]', $tx) ?: [] as $n) {
                        $u[] = trim($n->textContent);
                    }

                    $ustr = implode(' ', array_filter($u));
                    $ref  = $e('./*[local-name()="Refs"]/*[local-name()="AcctSvcrRef"]', $tx) ?: $e('./*[local-name()="Refs"]/*[local-name()="TxId"]', $tx);
                    $e2e  = $e('./*[local-name()="Refs"]/*[local-name()="EndToEndId"]', $tx);

                    if ($e2e !== '' && $e2e !== 'NOTPROVIDED') {
                        $ustr = trim($ustr . ' ' . $e2e);
                    }
                }

                if ($ustr === '') {
                    $ustr = $e('./*[local-name()="AddtlNtryInf"]', $ntry);
                }

                if ($o === null) {
                    continue;
                }

                $t[] = [
                    'datum'      => $datum,
                    'osszeg'     => $tjel === 'DBIT' ? -abs($o) : abs($o),
                    'penznem'    => strtoupper($ccy ?: 'HUF'),
                    'partner'    => $nev,
                    'partner_sz' => $iban,
                    'kozlemeny'  => $ustr,
                    'ref'        => $ref !== '' ? $ref : ($nref !== '' ? $nref . (count($txek) > 1 ? '/' . ($i + 1) : '') : ''),
                    'sor'        => $nr,
                ];
            }
        }

        return ['formatum' => 'camt053', 'tetelek' => $t, 'hibak' => $t === [] ? ['Nincs könyvelési tétel (Ntry) a kivonatban.'] : []];
    }

    /** @return array{formatum: string, tetelek: array<int, array<string, mixed>>, hibak: array<int, string>} */
    public static function ertelmez(string $tartalom, string $fajlnev = ''): array
    {
        if (strlen($tartalom) > self::MAX_BAJT) {
            return ['formatum' => '', 'tetelek' => [], 'hibak' => ['A fájl túl nagy (legfeljebb 5 MB).']];
        }

        $s = self::utf8($tartalom);

        if (preg_match('/^\s*<\?xml|^\s*<Document/i', $s) === 1 || preg_match('/\.xml$/i', $fajlnev) === 1) {
            return self::camt($s);
        }

        return self::csv($s, $fajlnev);
    }

    /* =================================================================
     * Banki értesítő (e-mail / SMS)
     * ============================================================== */

    /** @return array<string, mixed>|null */
    public static function level_ertelmez(string $szoveg): ?array
    {
        $s  = self::utf8(wp_strip_all_tags($szoveg));
        $sk = self::ekezettelen($s);

        if (preg_match('/terheles|kimeno|vasarlas|zarolas/u', $sk) === 1 && preg_match('/jovairas|bejovo|erkezett/u', $sk) !== 1) {
            return null;
        }

        $o = null;

        if (preg_match('/(?:osszeg|jovairas|jovairt osszeg|amount)[^\d+\-]{0,20}([+\-]?\s?[\d][\d\s.,\x{00A0}]*)\s*(?:HUF|Ft)?/iu', $sk, $m) === 1) {
            $o = self::osszeg($m[1]);
        }

        if ($o === null && preg_match('/([+]?\s?\d[\d\s.,\x{00A0}]*)\s*(?:HUF|Ft)\b/u', $s, $m) === 1) {
            $o = self::osszeg($m[1]);
        }

        if ($o === null || $o <= 0) {
            return null;
        }

        $mezo = static function (array $kulcsok) use ($s): string {
            foreach ($kulcsok as $k) {
                if (preg_match('/' . $k . '\s*[:=]\s*(.+)$/miu', $s, $m) === 1) {
                    return trim($m[1]);
                }
            }

            return '';
        };

        return [
            'datum'      => self::datum($mezo(['Könyvelés dátuma', 'Dátum', 'Értéknap', 'Időpont'])) ?: wp_date('Y-m-d'),
            'osszeg'     => abs($o),
            'penznem'    => preg_match('/\bEUR\b/', $s) === 1 ? 'EUR' : 'HUF',
            'partner'    => $mezo(['Partner neve', 'Partner', 'Küldő', 'Fizető neve', 'Befizető', 'Ellenoldali név', 'Kitől', 'Megbízó']),
            'partner_sz' => $mezo(['Partner számlaszáma', 'Ellenszámla', 'Számlaszám']),
            'kozlemeny'  => $mezo(['Közlemény', 'Megjegyzés', 'Reference']) ?: $s,
            'ref'        => $mezo(['Tranzakció azonosító', 'Tranzakcióazonosító', 'Hivatkozás', 'Azonosító']),
            'sor'        => 1,
        ];
    }

    /* =================================================================
     * Párosítás
     * ============================================================== */

    private static function norm(string $s): string
    {
        return strtr(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $s)), ['O' => '0']);
    }

    /** @return array<int, string> a szövegben talált közlemény-jelöltek (pontos alakban) */
    private static function kozlemenyek(string $szoveg): array
    {
        $e = SDH_Sales_Rendeles::kozlemeny_elotag();
        $s = strtoupper($szoveg);
        $k = [];

        if (preg_match_all('/' . preg_quote($e, '/') . '[' . SDH_Sales_Rendeles::KOZL_ABC . ']{7}/', $s, $m)) {
            $k = $m[0];
        }

        return array_values(array_unique(array_filter($k, [SDH_Sales_Rendeles::class, 'kozlemeny_jo'])));
    }

    /** @var array<int, array<string, mixed>>|null egy importon belüli gyorsítótár (fizetés után ürül) */
    private static ?array $nyitott = null;

    /** @return array<int, array<string, mixed>> nyitott (fizethető) rendelések */
    private static function nyitottak(): array
    {
        global $wpdb;

        if (self::$nyitott === null) {
            $ids           = (array) $wpdb->get_col('SELECT id FROM ' . SDH_Sales_Schema::t('rendeles') . " WHERE allapot IN ('dijbekero','fizetesre_var','sikertelen','uj','torolve') AND fizetett < brutto ORDER BY id DESC LIMIT 2000");
            self::$nyitott = array_values(array_filter(array_map(static fn ($i) => SDH_Sales_Rendeles::rendeles((int) $i), $ids)));
        }

        return self::$nyitott;
    }

    private static function nev_hasonlo(string $a, string $b): bool
    {
        $t = static function (string $s): array {
            $s = self::ekezettelen($s);
            $s = (string) preg_replace('/\b(kft|bt|zrt|nyrt|kkt|ev|e\.v|egyeni vallalkozo|korlatolt felelossegu tarsasag|ltd|gmbh)\b\.?/u', ' ', $s);

            return array_values(array_filter(preg_split('/[^a-z0-9]+/', $s) ?: [], static fn (string $x): bool => strlen($x) >= 3));
        };

        $x = $t($a);
        $y = $t($b);

        return $x !== [] && $y !== [] && array_intersect($x, $y) !== [];
    }

    /**
     * @param array<string, mixed> $tetel
     * @return array{rendeles: array<string, mixed>|null, mod: string}
     */
    public static function parosit(array $tetel): array
    {
        $szoveg = (string) $tetel['kozlemeny'] . ' ' . (string) ($tetel['partner'] ?? '');

        // 1. pontos közlemény
        foreach (self::kozlemenyek($szoveg) as $k) {
            $r = SDH_Sales_Rendeles::kozlemeny_szerint($k);

            if ($r !== null) {
                return ['rendeles' => $r, 'mod' => 'kozlemeny'];
            }
        }

        // 2. normalizált közlemény (szóköz / kötőjel / kisbetű / O↔0 elírás)
        $n = self::norm($szoveg);
        $e = SDH_Sales_Rendeles::kozlemeny_elotag();

        if ($n !== '' && strpos($n, self::norm($e)) !== false) {
            global $wpdb;

            $lista = (array) $wpdb->get_col('SELECT kozlemeny FROM ' . SDH_Sales_Schema::t('rendeles') . " WHERE kozlemeny <> '' ORDER BY id DESC LIMIT 5000");

            foreach ($lista as $k) {
                if (strpos($n, self::norm((string) $k)) !== false) {
                    return ['rendeles' => SDH_Sales_Rendeles::kozlemeny_szerint((string) $k), 'mod' => 'kozlemeny_normalizalt'];
                }
            }
        }

        // 3. rendelésazonosító
        if (preg_match('/R[\s\-]?(\d{4})[\s\-]?(\d{6})/i', $szoveg, $m) === 1) {
            $r = SDH_Sales_Rendeles::azon_szerint('R-' . $m[1] . '-' . $m[2]);

            if ($r !== null) {
                return ['rendeles' => $r, 'mod' => 'azon'];
            }
        }

        // 4. összeg + név – csak ha egyértelmű
        $o = (int) $tetel['osszeg'];
        $j = [];

        foreach (self::nyitottak() as $r) {
            if ((int) $r['brutto'] - (int) $r['fizetett'] === $o && self::nev_hasonlo((string) ($tetel['partner'] ?? ''), (string) ($r['vevo']['ceg'] ?? '') . ' ' . (string) ($r['vevo']['kapcsolattarto'] ?? ''))) {
                $j[] = $r;
            }
        }

        if (count($j) === 1) {
            return ['rendeles' => $j[0], 'mod' => 'osszeg_nev'];
        }

        return ['rendeles' => null, 'mod' => count($j) > 1 ? 'tobb_jelolt' : ''];
    }

    /** A tétel egyedi kulcsa: banki hivatkozás, vagy a mezők lenyomata (+ sorszám az azonos tételekhez). */
    private static function tranzakcio(array $t, int $ismetles): string
    {
        $ref = trim((string) ($t['ref'] ?? ''));

        if ($ref !== '' && strlen($ref) >= 4) {
            return 'ref:' . mb_substr($ref, 0, 100);
        }

        $alap = implode('|', [(string) $t['datum'], (int) $t['osszeg'], self::norm((string) $t['partner']), self::norm((string) ($t['partner_sz'] ?? '')), self::norm((string) $t['kozlemeny']), $ismetles]);

        return 'h:' . substr(hash('sha256', $alap), 0, 40);
    }

    /**
     * @return array<string, mixed>
     */
    public static function feldolgoz(string $tartalom, string $fajlnev = ''): array
    {
        $e = self::ertelmez($tartalom, $fajlnev);

        return self::tetelek_feldolgoz($e['tetelek'], $e['formatum'], $e['hibak']);
    }

    /**
     * @param array<int, array<string, mixed>> $tetelek
     * @param array<int, string>               $hibak
     * @return array<string, mixed>
     */
    public static function tetelek_feldolgoz(array $tetelek, string $formatum, array $hibak = []): array
    {
        $j   = ['parositva' => [], 'mar_feldolgozva' => [], 'nem_parosithato' => [], 'osszeg_elteres' => [], 'kihagyva' => 0];
        $lat = [];

        self::$nyitott = null;

        foreach ($tetelek as $t) {
            if ((int) $t['osszeg'] <= 0 || (string) $t['penznem'] !== 'HUF') {
                $j['kihagyva']++;
                continue;
            }

            $alap  = implode('|', [(string) $t['datum'], (int) $t['osszeg'], (string) $t['partner'], (string) $t['kozlemeny']]);
            $lat[$alap] = ($lat[$alap] ?? 0) + 1;
            $tr    = self::tranzakcio($t, $lat[$alap]);
            $p     = self::parosit($t);
            $sor   = [
                'sor'       => (int) ($t['sor'] ?? 0),
                'datum'     => (string) $t['datum'],
                'osszeg'    => (int) $t['osszeg'],
                'partner'   => mb_substr((string) $t['partner'], 0, 120),
                'kozlemeny' => mb_substr((string) $t['kozlemeny'], 0, 140),
                'mod'       => $p['mod'],
            ];
            $nyers = ['formatum' => $formatum, 'tetel' => $sor + ['ref' => (string) ($t['ref'] ?? '')]];

            if ($p['rendeles'] === null) {
                if (SDH_Sales_Rendeles::ismeretlen_fizetes('bank_import', $tr, (int) $t['osszeg'], $nyers)) {
                    $j['nem_parosithato'][] = $sor;
                } else {
                    $j['mar_feldolgozva'][] = $sor;
                }

                continue;
            }

            $r              = $p['rendeles'];
            $sor['rendeles'] = (string) $r['azon'];
            $hatra          = (int) $r['brutto'] - (int) $r['fizetett'];
            $uj             = SDH_Sales_Rendeles::fizetve((int) $r['id'], 'bank_import', $tr, (int) $t['osszeg'], $nyers);
            self::$nyitott  = null;

            if (!$uj) {
                $j['mar_feldolgozva'][] = $sor;
            } elseif ((int) $t['osszeg'] !== $hatra) {
                $sor['vart']           = $hatra;
                $j['osszeg_elteres'][] = $sor;
            } else {
                $j['parositva'][] = $sor;
            }
        }

        SDH_Sales_Naplo::ir('bank_import', sprintf(
            'Banki import (%s): %d párosítva, %d már feldolgozva, %d nem párosítható, %d összegeltérés, %d kihagyva.',
            $formatum,
            count($j['parositva']),
            count($j['mar_feldolgozva']),
            count($j['nem_parosithato']),
            count($j['osszeg_elteres']),
            $j['kihagyva']
        ), $j['nem_parosithato'] !== [] || $j['osszeg_elteres'] !== [] ? 'figyel' : 'info');

        return ['formatum' => $formatum, 'sorok' => count($tetelek), 'jelentes' => $j, 'hibak' => $hibak];
    }

    /* =================================================================
     * REST
     * ============================================================== */

    public static function rest_import(WP_REST_Request $q)
    {
        $fajl = $q->get_file_params()['fajl'] ?? null;
        $nev  = sanitize_file_name((string) $q->get_param('fajlnev'));

        if (is_array($fajl) && !empty($fajl['tmp_name']) && is_uploaded_file((string) $fajl['tmp_name'])) {
            if ((int) $fajl['size'] > self::MAX_BAJT) {
                return SDH_Sales_Rest::hiba(__('A fájl túl nagy (legfeljebb 5 MB).', 'sdh-sales'), 413, 'meret');
            }

            $t   = (string) file_get_contents((string) $fajl['tmp_name']);
            $nev = sanitize_file_name((string) $fajl['name']);
        } else {
            $t = (string) $q->get_param('tartalom');

            if (strncmp($t, 'base64:', 7) === 0) {
                $t = (string) base64_decode(substr($t, 7), true);
            }
        }

        if (trim($t) === '') {
            return SDH_Sales_Rest::hiba(__('Nincs feltöltött kivonat.', 'sdh-sales'), 400, 'ures');
        }

        if (!preg_match('/\.(csv|txt|xml)$/i', $nev) && $nev !== '') {
            return SDH_Sales_Rest::hiba(__('Csak CSV vagy XML (CAMT.053) kivonat tölthető fel.', 'sdh-sales'), 415, 'tipus');
        }

        return new WP_REST_Response(self::feldolgoz($t, $nev), 200);
    }

    public static function rest_level(WP_REST_Request $q)
    {
        $t = self::level_ertelmez((string) $q->get_param('szoveg'));

        if ($t === null) {
            return SDH_Sales_Rest::hiba(__('Ebben a szövegben nem találtam jóváírást (összeget).', 'sdh-sales'), 422, 'nincs');
        }

        return new WP_REST_Response(self::tetelek_feldolgoz([$t], 'ertesito'), 200);
    }
}
