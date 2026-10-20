<?php
/**
 * SDH Értékesítés – banki kivonat / értesítő beolvasása és párosítása a rendelésekhez.
 *
 * Bemenet: CSV (OTP – fejléc nélküli exportja is –, K&H, Erste, MBH, Raiffeisen, Wise, általános fejléces),
 * Windows-1250 / UTF-8 (BOM-mal vagy anélkül) felismeréssel, `;` `,` tab elválasztóval; CAMT.053 XML (ISO 20022,
 * bármely camt.053.001.xx névtér); bemásolt banki értesítő e-mail / SMS szövege.
 * Párosítás sorrendje: (1) közlemény pontosan (ellenőrző karakterrel), (2) közlemény normalizálva (szóköz, kötőjel,
 * kisbetű), (3) rendelésazonosító pontos szóként (`R-2026-000123`, kötőjelekkel; több különböző azonosító = nem egyértelmű),
 * (4) összeg + név (egyetlen nyitott rendelés, azonos hátralékkal és erősen hasonló cégnévvel, általános szavak nélkül) –
 * ez CSAK javaslat: „kézi jóváhagyásra vár” sor lesz a rendelésen (nem fizet, nem indít kiszolgálást), az adminisztrátor
 * hagyja jóvá (POST /admin/bank/fizetes/{id}/jovahagy). Csak jóváírás (bejövő, HUF) számít.
 * Kettős könyvelés ellen (ugyanaz a jóváírás értesítőből + kivonatból + Számlázz.hu IPN-ből + kézi „Fizetve”-ből):
 *   - lenyomat: összeg + partner (számlaszám, különben név) + közlemény-kód (különben a normalizált közlemény) + sorszám;
 *     azonos lenyomat és azonos (vagy hiányzó) könyvelési nap → ugyanaz a tétel („már feldolgozva”);
 *   - csatornák közti párosítás: ugyanarra a rendelésre, ±7 napon belül, MÁS csatornából (értesítő / kivonat / Számlázz.hu /
 *     kézi) már rögzített, még nem párosított azonos összeg (Számlázz.hu-nál a halmozott összeg) → ugyanaz a jóváírás:
 *     `duplikat` sor, a pénz nem számít kétszer;
 *   - a már kifizetett rendelésre érkező további tétel nem könyvelődik automatikusan: `ellenorizendo` sor + levél.
 * Az értesítőben hiányzó dátum üres marad (soha nem „ma”), így ugyanaz az értesítő másnap bemásolva is ugyanaz a tétel.
 * Rögzítés: SDH_Sales_Rendeles::fizetve($id, 'bank_import', <banki hivatkozás | 'h:'+lenyomat>, $osszeg, $nyers).
 *
 * API:
 *   feldolgoz(string $tartalom, string $fajlnev = ''): array{formatum, sorok, jelentes{parositva[], mar_feldolgozva[],
 *     nem_parosithato[], osszeg_elteres[], kihagyva}, hibak[]}
 *   ertelmez(string $tartalom, string $fajlnev = ''): array{formatum, tetelek[], hibak[]}   – párosítás nélkül
 *   level_ertelmez(string $szoveg): ?array  – egy banki értesítő (e-mail / SMS) → tétel
 *   parosit(array $tetel): array{rendeles: ?array, mod: string}
 *   osszeg(string): ?int                    – „+12 700,00 Ft” → 12700
 *   lenyomat(array $tetel, int $n): string  – csatornafüggetlen lenyomat
 *   jovahagy(int $fizetes_id, int $rendeles_id = 0): array{ok, uzenet} · elutasit(int $fizetes_id): bool
 * Admin REST: POST /admin/bank/import {tartalom (szöveg vagy base64:…), fajlnev} · POST /admin/bank/level {szoveg}
 *   · GET /admin/bank/fuggo (jóváhagyásra váró / ellenőrizendő / ismeretlen tételek) · POST /admin/bank/fizetes/{id}/jovahagy
 *   {rendeles_id?} · POST /admin/bank/fizetes/{id}/elutasit.
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
        SDH_Sales_Rest::ut('/admin/bank/fuggo', 'GET', [self::class, 'rest_fuggo'], 'admin');
        SDH_Sales_Rest::ut('/admin/bank/fizetes/(?P<id>\d+)/jovahagy', 'POST', [self::class, 'rest_jovahagy'], 'admin', ['bank_jovahagy', 60, 60]);
        SDH_Sales_Rest::ut('/admin/bank/fizetes/(?P<id>\d+)/elutasit', 'POST', [self::class, 'rest_elutasit'], 'admin', ['bank_jovahagy', 60, 60]);
    }

    /** A párosítás ablaka (nap) a csatornák között. */
    public const ABLAK_NAP = 7;

    /** Csatornák, amelyek ugyanazt a banki jóváírást jelenthetik. */
    private const BANKI_CSATORNA = ['ertesito', 'kivonat', 'szamlazz', 'kezi'];

    /** Cégnevekben gyakori, megkülönböztetésre alkalmatlan szavak (az összeg+név javaslatból kimaradnak). */
    private const ALTALANOS_SZO = [
        'szerviz', 'service', 'mobil', 'mobile', 'gsm', 'telefon', 'phone', 'javito', 'javitas', 'repair', 'muhely', 'bolt',
        'shop', 'kereskedelmi', 'szolgaltato', 'tanacsado', 'informatika', 'informatikai', 'elektronika', 'elektronikai',
        'computer', 'szamitastechnika', 'szamitastechnikai', 'hungary', 'magyarorszag', 'magyar', 'group', 'center', 'centrum',
        'trade', 'kozpont', 'iroda', 'studio', 'plusz', 'plus', 'budapest', 'es', 'and', 'the', 'kiss', 'nagy', 'kovacs',
        'toth', 'szabo', 'horvath', 'varga', 'molnar', 'nemeth', 'farkas', 'balogh', 'papp', 'takacs', 'juhasz', 'lakatos',
    ];

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

    /**
     * Oszlopok felismerése. Ha egy mezőre több oszlop is illik (pl. Wise: „Description” és „Payment Reference”), a
     * szinonimalistában előrébb álló (konkrétabb) nyer.
     *
     * @param array<int, string> $r @return array<string, int>
     */
    private static function fejlec(array $r): array
    {
        $m   = [];
        $rang = [];
        $fog = [];

        foreach ($r as $i => $c) {
            $c = trim((string) preg_replace('/\s+/', ' ', self::ekezettelen($c)), " \t\"'");

            foreach (self::OSZLOP as $k => $lista) {
                $p = array_search($c, $lista, true);

                if ($p !== false && !isset($fog[$i]) && (!isset($rang[$k]) || $p < $rang[$k])) {
                    if (isset($m[$k])) {
                        unset($fog[$m[$k]]);
                    }

                    $m[$k]    = $i;
                    $rang[$k] = (int) $p;
                    $fog[$i]  = true;
                    break;
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

        // Az összes ismert címke: egysoros értesítőben („Partner: X. Közlemény: Y”) az érték a következő címkéig tart.
        $cimkek = ['Könyvelés dátuma', 'Dátum', 'Értéknap', 'Időpont', 'Partner neve', 'Partner számlaszáma', 'Partner', 'Küldő',
            'Fizető neve', 'Befizető', 'Ellenoldali név', 'Kitől', 'Megbízó', 'Ellenszámla', 'Számlaszám', 'Közlemény', 'Megjegyzés',
            'Reference', 'Tranzakció azonosító', 'Tranzakcióazonosító', 'Hivatkozás', 'Azonosító', 'Összeg', 'Egyenleg'];
        $kov    = '(?=[ \t]*[.,;]?[ \t]*(?:' . implode('|', array_map(static fn ($c) => preg_quote($c, '/'), $cimkek)) . ')[ \t]*[:=]|$)';

        $mezo = static function (array $kulcsok) use ($s, $kov): string {
            foreach ($kulcsok as $k) {
                if (preg_match('/(?<![\p{L}])' . $k . '\s*[:=]\s*(.+?)' . $kov . '/miu', $s, $m) === 1) {
                    return trim($m[1], " \t,;");
                }
            }

            return '';
        };

        return [
            // Hiányzó dátum: üres (nem a mai nap) – így a másnap újra bemásolt értesítő is ugyanaz a tétel.
            'datum'      => self::datum($mezo(['Könyvelés dátuma', 'Dátum', 'Értéknap', 'Időpont'])),
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

    /**
     * Erős névhasonlóság: a cégnév megkülönböztető szavainak (általános szavak, cégforma nélkül) legalább 2/3-a
     * szerepel a befizető nevében, és van köztük legalább 4 betűs. A kapcsolattartó neve nem számít.
     */
    public static function nev_hasonlo(string $partner, string $ceg): bool
    {
        $t = static function (string $s): array {
            $s = self::ekezettelen($s);
            $s = (string) preg_replace('/\b(kft|bt|zrt|nyrt|kkt|ev|e\.v|egyeni vallalkozo|korlatolt felelossegu tarsasag|ltd|gmbh)\b\.?/u', ' ', $s);

            return array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', $s) ?: [], static fn (string $x): bool => strlen($x) >= 3 && !in_array($x, self::ALTALANOS_SZO, true))));
        };

        $p = $t($partner);
        $c = $t($ceg);

        if ($p === [] || $c === []) {
            return false;
        }

        $kozos = array_intersect($c, $p);

        return count($kozos) * 3 >= count($c) * 2 && array_filter($kozos, static fn (string $x): bool => strlen($x) >= 4) !== [];
    }

    /**
     * @param array<string, mixed> $tetel
     * @return array{rendeles: array<string, mixed>|null, mod: string, javaslat?: bool}
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

        // 3. rendelésazonosító – csak pontos szóként (R-ÉÉÉÉ-NNNNNN, kötőjelekkel), és csak ha egyetlen azonosító szerepel
        if (preg_match_all('/(?<![A-Za-z0-9])R-(\d{4})-(\d{6})(?![0-9])/', strtoupper((string) $tetel['kozlemeny']), $m) > 0) {
            $azonok = array_values(array_unique(array_map(static fn (string $e, string $n): string => 'R-' . $e . '-' . $n, $m[1], $m[2])));

            if (count($azonok) > 1) {
                return ['rendeles' => null, 'mod' => 'tobb_jelolt'];
            }

            $r = SDH_Sales_Rendeles::azon_szerint($azonok[0]);

            if ($r !== null) {
                return ['rendeles' => $r, 'mod' => 'azon'];
            }
        }

        // 4. összeg + név – csak javaslat (kézi jóváhagyás), és csak ha egyértelmű
        $o = (int) $tetel['osszeg'];
        $j = [];

        foreach (self::nyitottak() as $r) {
            if ((int) $r['brutto'] - (int) $r['fizetett'] === $o && self::nev_hasonlo((string) ($tetel['partner'] ?? ''), (string) ($r['vevo']['ceg'] ?? ''))) {
                $j[] = $r;
            }
        }

        if (count($j) === 1) {
            return ['rendeles' => $j[0], 'mod' => 'osszeg_nev', 'javaslat' => true];
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

    /** A partner azonosítója: a számlaszám (belföldi alakra hozva), ha van, különben a normalizált név. */
    private static function partner_azon(array $t): string
    {
        $d = (string) preg_replace('/\D/', '', (string) ($t['partner_sz'] ?? ''));

        if (strlen($d) >= 16) {
            $d = substr($d, -24);
            $d = strlen($d) === 24 && substr($d, -8) === '00000000' ? substr($d, 0, 16) : $d;

            return 'sz:' . $d;
        }

        return 'n:' . self::norm((string) ($t['partner'] ?? ''));
    }

    /** A közlemény azonosítója: a benne talált közlemény-kód vagy rendelésazonosító, különben a normalizált szöveg. */
    private static function kozlemeny_azon(string $k): string
    {
        $kodok = self::kozlemenyek($k);

        if ($kodok !== []) {
            return 'k:' . $kodok[0];
        }

        if (preg_match('/(?<![A-Za-z0-9])R-\d{4}-\d{6}(?![0-9])/', strtoupper($k), $m) === 1) {
            return 'a:' . $m[0];
        }

        return 't:' . substr(self::norm($k), 0, 140);
    }

    /**
     * Csatornafüggetlen lenyomat (könyvelési nap nélkül – azt külön vetjük össze): összeg + partner + közlemény + sorszám.
     *
     * @param array<string, mixed> $t
     */
    public static function lenyomat(array $t, int $n = 1): string
    {
        return hash('sha256', implode('|', [(int) $t['osszeg'], self::partner_azon($t), self::kozlemeny_azon((string) $t['kozlemeny']), max(1, $n)]));
    }

    /**
     * Ugyanez a tétel rögzítve van-e már: azonos lenyomat, és (a) mindkettőnek van könyvelési napja, az azonos, és a banki
     * azonosítójuk nem különbözik (ugyanaz a kivonat másik formátumban / újra beolvasva), vagy (b) mindkettő értesítő, és
     * ±ABLAK_NAP napon belül jött (dátum nélkül újra bemásolt értesítő). A csatornák közti egyezést a `csatorna_par()` dönti.
     *
     * @param array<string, mixed> $t
     */
    private static function mar_rogzitett(string $lenyomat, array $t, string $csatorna): ?int
    {
        global $wpdb;

        $sorok = (array) $wpdb->get_results($wpdb->prepare('SELECT id, datum, csatorna, nyers FROM ' . SDH_Sales_Schema::t('fizetes') . ' WHERE lenyomat = %s ORDER BY id ASC LIMIT 50', $lenyomat), ARRAY_A);
        $d     = (string) $t['datum'];
        $ref   = trim((string) ($t['ref'] ?? ''));
        $nap   = $d !== '' ? $d : wp_date('Y-m-d');

        foreach ($sorok as $s) {
            $ny   = json_decode((string) $s['nyers'], true);
            $sd   = (string) ($ny['tetel']['datum'] ?? '');
            $sref = trim((string) ($ny['tetel']['ref'] ?? ''));

            if ($d !== '' && $sd !== '') {
                if ($d === $sd && ($ref === '' || $sref === '' || $ref === $sref)) {
                    return (int) $s['id'];
                }

                continue;
            }

            if ($csatorna === 'ertesito' && (string) $s['csatorna'] === 'ertesito'
                && abs(strtotime($nap . ' 12:00:00 UTC') - strtotime((string) ($s['datum'] ?: $nap) . ' 12:00:00 UTC')) <= self::ABLAK_NAP * DAY_IN_SECONDS) {
                return (int) $s['id'];
            }
        }

        return null;
    }

    /**
     * Csatornák közti párosítás: ugyanarra a rendelésre más csatornából már rögzített, még nem párosított jóváírás,
     * amely ugyanezt a pénzt jelentheti. Siker: a párosított sor(ok) azonosítói (a `parositva` növelve); különben [].
     *
     * @return array<int, int>
     */
    private static function csatorna_par(int $rendeles_id, string $csatorna, int $osszeg, string $datum): array
    {
        global $wpdb;

        $tf    = SDH_Sales_Schema::t('fizetes');
        $nap   = $datum !== '' ? $datum : wp_date('Y-m-d');
        $tol   = wp_date('Y-m-d', (int) strtotime($nap . ' 12:00:00 UTC') - self::ABLAK_NAP * DAY_IN_SECONDS);
        $ig    = wp_date('Y-m-d', (int) strtotime($nap . ' 12:00:00 UTC') + self::ABLAK_NAP * DAY_IN_SECONDS);
        $cs    = "'" . implode("','", self::BANKI_CSATORNA) . "'";
        $sorok = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, osszeg, parositva, csatorna FROM {$tf} WHERE rendeles_id = %d AND allapot IN ('ok','tulfizetes') AND csatorna IN ({$cs}) AND csatorna <> %s AND osszeg > parositva AND datum >= %s AND datum <= %s ORDER BY id ASC",
            $rendeles_id,
            $csatorna,
            $tol,
            $ig
        ), ARRAY_A);

        // Pontos összeg egy sorban.
        foreach ($sorok as $s) {
            if ((int) $s['osszeg'] - (int) $s['parositva'] === $osszeg) {
                $wpdb->query($wpdb->prepare("UPDATE {$tf} SET parositva = parositva + %d WHERE id = %d AND osszeg - parositva >= %d", $osszeg, (int) $s['id'], $osszeg));

                return [(int) $s['id']];
            }
        }

        // Számlázz.hu: az IPN a díjbekérőn halmozott összeget hozza – több banki tételt is fedhet.
        $szl = array_values(array_filter($sorok, static fn (array $s): bool => (string) $s['csatorna'] === 'szamlazz'));

        if ($szl !== [] && array_sum(array_map(static fn (array $s): int => (int) $s['osszeg'] - (int) $s['parositva'], $szl)) >= $osszeg) {
            $marad = $osszeg;
            $ids   = [];

            foreach ($szl as $s) {
                $x = min($marad, (int) $s['osszeg'] - (int) $s['parositva']);

                if ($x > 0) {
                    $wpdb->query($wpdb->prepare("UPDATE {$tf} SET parositva = parositva + %d WHERE id = %d", $x, (int) $s['id']));
                    $ids[]  = (int) $s['id'];
                    $marad -= $x;
                }

                if ($marad <= 0) {
                    break;
                }
            }

            return $ids;
        }

        return [];
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
        $j   = ['parositva' => [], 'mar_feldolgozva' => [], 'nem_parosithato' => [], 'osszeg_elteres' => [], 'jovahagyasra_var' => [], 'ellenorizendo' => [], 'kihagyva' => 0];
        $lat = [];
        $csatorna = $formatum === 'ertesito' ? 'ertesito' : 'kivonat';

        self::$nyitott = null;

        foreach ($tetelek as $t) {
            if ((int) $t['osszeg'] <= 0 || (string) $t['penznem'] !== 'HUF') {
                $j['kihagyva']++;
                continue;
            }

            $t['datum'] = (string) ($t['datum'] ?? '');
            $alap  = implode('|', [(string) $t['datum'], (int) $t['osszeg'], (string) $t['partner'], (string) $t['kozlemeny']]);
            $lat[$alap] = ($lat[$alap] ?? 0) + 1;
            $tr    = self::tranzakcio($t, $lat[$alap]);
            $len   = self::lenyomat($t, $lat[$alap]);
            $sor   = [
                'sor'       => (int) ($t['sor'] ?? 0),
                'datum'     => (string) $t['datum'],
                'osszeg'    => (int) $t['osszeg'],
                'partner'   => mb_substr((string) $t['partner'], 0, 120),
                'kozlemeny' => mb_substr((string) $t['kozlemeny'], 0, 140),
                'mod'       => '',
            ];
            $nyers = ['formatum' => $formatum, 'tetel' => $sor + ['ref' => (string) ($t['ref'] ?? '')], '_csatorna' => $csatorna, '_datum' => $t['datum'], '_lenyomat' => $len];

            // Ugyanez a tétel már rögzítve (pl. ugyanaz a kivonat CSV-ben és CAMT-ban, vagy kétszer bemásolt értesítő).
            $mar = self::mar_rogzitett($len, $t, $csatorna);

            if ($mar !== null) {
                $j['mar_feldolgozva'][] = $sor + ['azonos' => $mar];
                continue;
            }

            $p                       = self::parosit($t);
            $sor['mod']              = $p['mod'];
            $nyers['tetel']['mod']   = $p['mod'];

            if ($p['rendeles'] === null) {
                if (SDH_Sales_Rendeles::ismeretlen_fizetes('bank_import', $tr, (int) $t['osszeg'], $nyers)) {
                    $j['nem_parosithato'][] = $sor;
                } else {
                    $j['mar_feldolgozva'][] = $sor;
                }

                continue;
            }

            $r               = $p['rendeles'];
            $rid             = (int) $r['id'];
            $sor['rendeles'] = (string) $r['azon'];
            $hatra           = (int) $r['brutto'] - (int) $r['fizetett'];

            // 4. mód (összeg + név): csak javaslat – a rendelésen „kézi jóváhagyásra vár”, nem fizet, nem indít semmit.
            if (!empty($p['javaslat'])) {
                if (SDH_Sales_Rendeles::ismeretlen_fizetes('bank_import', $tr, (int) $t['osszeg'], $nyers + ['javaslat' => (string) $r['azon']], $rid, 'jovahagyasra_var')) {
                    $j['jovahagyasra_var'][] = $sor;
                } else {
                    $j['mar_feldolgozva'][] = $sor;
                }

                continue;
            }

            // Ugyanez a pénz más csatornából már megjött (értesítő ↔ kivonat ↔ Számlázz.hu ↔ kézi).
            $par = self::csatorna_par($rid, $csatorna, (int) $t['osszeg'], (string) $t['datum']);

            if ($par !== []) {
                SDH_Sales_Rendeles::ismeretlen_fizetes('bank_import', $tr, (int) $t['osszeg'], $nyers + ['ugyanaz_mint' => $par], $rid, 'duplikat');
                $j['mar_feldolgozva'][] = $sor + ['azonos' => $par[0], 'masik_csatorna' => true];
                continue;
            }

            // Kifizetett / nem fizethető rendelésre érkező újabb tétel: nem könyveljük automatikusan.
            if ($hatra <= 0 || !in_array((string) $r['allapot'], ['uj', 'dijbekero', 'fizetesre_var', 'sikertelen', 'torolve'], true)) {
                if (SDH_Sales_Rendeles::ismeretlen_fizetes('bank_import', $tr, (int) $t['osszeg'], $nyers, $rid, 'ellenorizendo')) {
                    $j['ellenorizendo'][] = $sor;
                    SDH_Sales_Rendeles::admin_level('Ellenőrizendő befizetés: ' . (string) $r['azon'], 'A(z) ' . (string) $r['azon'] . ' rendelés (' . SDH_Sales_Rendeles::allapot_szoveg((string) $r['allapot']) . ') már rendezett, de a banki importban újabb ' . SDH_Sales::ft((int) $t['osszeg']) . " jóváírás áll rá.\nNem könyveltük. Ellenőrizd a bankszámlán: ha valóban újabb utalás, hagyd jóvá a vezérlőpulton (Banki import › Függő tételek); ha ugyanaz a tétel, utasítsd el.");
                } else {
                    $j['mar_feldolgozva'][] = $sor;
                }

                continue;
            }

            $uj            = SDH_Sales_Rendeles::fizetve($rid, 'bank_import', $tr, (int) $t['osszeg'], $nyers);
            self::$nyitott = null;

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
            'Banki import (%s): %d párosítva, %d már feldolgozva, %d nem párosítható, %d összegeltérés, %d jóváhagyásra vár, %d ellenőrizendő, %d kihagyva.',
            $formatum,
            count($j['parositva']),
            count($j['mar_feldolgozva']),
            count($j['nem_parosithato']),
            count($j['osszeg_elteres']),
            count($j['jovahagyasra_var']),
            count($j['ellenorizendo']),
            $j['kihagyva']
        ), $j['nem_parosithato'] !== [] || $j['osszeg_elteres'] !== [] || $j['jovahagyasra_var'] !== [] || $j['ellenorizendo'] !== [] ? 'figyel' : 'info');

        return ['formatum' => $formatum, 'sorok' => count($tetelek), 'jelentes' => $j, 'hibak' => $hibak];
    }

    /* =================================================================
     * Függő tételek: kézi jóváhagyás / elutasítás
     * ============================================================== */

    /** @return array<string, mixed>|null */
    private static function fizetes_sor(int $id): ?array
    {
        global $wpdb;

        $s = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('fizetes') . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($s) ? $s : null;
    }

    /**
     * Függő (jóváhagyásra váró / ellenőrizendő / ismeretlen) tétel könyvelése egy rendelésre a fizetés egyetlen belépési
     * pontján át. `$rendeles_id` = 0: a sorban javasolt rendelés.
     *
     * @return array{ok: bool, uzenet: string}
     */
    public static function jovahagy(int $fizetes_id, int $rendeles_id = 0): array
    {
        global $wpdb;

        $s = self::fizetes_sor($fizetes_id);

        if ($s === null || !in_array((string) $s['allapot'], ['jovahagyasra_var', 'ellenorizendo', 'ismeretlen'], true)) {
            return ['ok' => false, 'uzenet' => __('Nincs ilyen függő tétel.', 'sdh-sales')];
        }

        $rid = $rendeles_id > 0 ? $rendeles_id : (int) $s['rendeles_id'];

        if ($rid <= 0 || SDH_Sales_Rendeles::rendeles($rid) === null) {
            return ['ok' => false, 'uzenet' => __('Válaszd ki, melyik rendeléshez tartozik a befizetés.', 'sdh-sales')];
        }

        // A sor helyére a valódi befizetés kerül (ugyanazzal a forrás + tranzakció kulccsal – az import így sem rögzíti újra).
        $n = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Sales_Schema::t('fizetes') . ' WHERE id = %d AND allapot = %s', $fizetes_id, (string) $s['allapot']));

        if ($n !== 1) {
            return ['ok' => false, 'uzenet' => __('A tételt közben már feldolgozták.', 'sdh-sales')];
        }

        $nyers = json_decode((string) $s['nyers'], true);
        $nyers = (is_array($nyers) ? $nyers : []) + ['jovahagyta' => get_current_user_id(), '_csatorna' => (string) $s['csatorna'], '_datum' => (string) $s['datum'], '_lenyomat' => (string) $s['lenyomat']];
        $ok    = SDH_Sales_Rendeles::fizetve($rid, (string) $s['forras'], (string) $s['tranzakcio'], (int) $s['osszeg'], $nyers);

        SDH_Sales_Naplo::ir('bank_import', 'Függő befizetés jóváhagyva: ' . SDH_Sales::ft((int) $s['osszeg']) . ' → #' . $rid . '.', 'info', 'rendeles', $rid);

        return ['ok' => $ok, 'uzenet' => $ok ? __('A befizetés rögzítve.', 'sdh-sales') : __('A befizetés nem rögzíthető.', 'sdh-sales')];
    }

    public static function elutasit(int $fizetes_id): bool
    {
        global $wpdb;

        $n = (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . SDH_Sales_Schema::t('fizetes') . " SET allapot = 'elutasitva' WHERE id = %d AND allapot IN ('jovahagyasra_var','ellenorizendo','ismeretlen')",
            $fizetes_id
        ));

        if ($n === 1) {
            SDH_Sales_Naplo::ir('bank_import', 'Függő befizetés elutasítva (#' . $fizetes_id . ').', 'info', 'fizetes', $fizetes_id);
        }

        return $n === 1;
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

    public static function rest_fuggo(): WP_REST_Response
    {
        global $wpdb;

        $tf    = SDH_Sales_Schema::t('fizetes');
        $tr    = SDH_Sales_Schema::t('rendeles');
        $sorok = (array) $wpdb->get_results("SELECT f.id, f.rendeles_id, f.forras, f.osszeg, f.ido, f.allapot, f.csatorna, f.datum, f.nyers, r.azon FROM {$tf} f LEFT JOIN {$tr} r ON r.id = f.rendeles_id WHERE f.allapot IN ('jovahagyasra_var','ellenorizendo','ismeretlen') ORDER BY f.id DESC LIMIT 200", ARRAY_A);

        return new WP_REST_Response(array_map(static function (array $s): array {
            $ny = json_decode((string) $s['nyers'], true);

            return [
                'id'       => (int) $s['id'],
                'allapot'  => (string) $s['allapot'],
                'rendeles' => (string) ($s['azon'] ?? ''),
                'rendeles_id' => (int) $s['rendeles_id'],
                'osszeg'   => (int) $s['osszeg'],
                'datum'    => (string) ($s['datum'] ?? ''),
                'csatorna' => (string) $s['csatorna'],
                'partner'  => (string) ($ny['tetel']['partner'] ?? ''),
                'kozlemeny' => (string) ($ny['tetel']['kozlemeny'] ?? ''),
                'mod'      => (string) ($ny['tetel']['mod'] ?? ''),
            ];
        }, $sorok), 200);
    }

    public static function rest_jovahagy(WP_REST_Request $q)
    {
        $v = self::jovahagy((int) $q['id'], (int) $q->get_param('rendeles_id'));

        return new WP_REST_Response($v, $v['ok'] ? 200 : 409);
    }

    public static function rest_elutasit(WP_REST_Request $q)
    {
        $ok = self::elutasit((int) $q['id']);

        return new WP_REST_Response(['ok' => $ok], $ok ? 200 : 409);
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
