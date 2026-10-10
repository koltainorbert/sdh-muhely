<?php
/**
 * E-mail (MIME) feldolgozó tiszta PHP-ben.
 *
 * A nyers levélből kiolvassa a fejléceket (RFC 2047 kódolással), a szöveges
 * és a HTML törzset (bármilyen karakterkészletből UTF-8-ra), valamint a
 * csatolmányokat. Csonka levelet is elvisel: a levéllista csak a levél
 * elejét tölti le, abból készül a kivonat.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Mime
{
    /** Ennél mélyebbre nem megyünk a többrészes levelekben (védelem a rosszindulatú levél ellen). */
    private const MELYSEG_MAX = 12;

    /**
     * Levél feldolgozása.
     *
     * @return array{
     *     fejlec: array<string, string>,
     *     szoveg: string,
     *     html: string,
     *     csatolmanyok: array<int, array{nev: string, tipus: string, meret: int, cid: string, beagyazott: bool, tartalom: string}>
     * }
     */
    public static function feldolgoz(string $nyers): array
    {
        $ki = ['fejlec' => [], 'szoveg' => '', 'html' => '', 'csatolmanyok' => []];

        [$fej, $torzs] = self::kettevag($nyers);
        $fejlecek      = self::fejlecek($fej);

        foreach ($fejlecek as $nev => $ertekek) {
            $ki['fejlec'][$nev] = self::fejlec_dekodol($ertekek[0]);
        }

        // A References fejlécből minden példány kell (ritkán több is van).
        if (isset($fejlecek['references'])) {
            $ki['fejlec']['references'] = trim(implode(' ', $fejlecek['references']));
        }

        self::resz($fejlecek, $torzs, $ki, 0);

        return $ki;
    }

    /**
     * Csak a fejlécek (a levéllistához).
     *
     * @return array<string, string>
     */
    public static function fejlec(string $nyers): array
    {
        [$fej] = self::kettevag($nyers);
        $ki    = [];

        foreach (self::fejlecek($fej) as $nev => $ertekek) {
            $ki[$nev] = self::fejlec_dekodol($ertekek[0]);
        }

        return $ki;
    }

    /* =================================================================
     * Szerkezet
     * ============================================================== */

    /** @return array{0: string, 1: string} fejléc, törzs */
    private static function kettevag(string $nyers): array
    {
        if (preg_match('/\r?\n\r?\n/', $nyers, $m, PREG_OFFSET_CAPTURE) === 1) {
            return [substr($nyers, 0, $m[0][1]), substr($nyers, $m[0][1] + strlen($m[0][0]))];
        }

        return [$nyers, ''];
    }

    /**
     * Fejlécsorok: a folytatósorok összefűzve, a nevek kisbetűvel.
     *
     * @return array<string, array<int, string>>
     */
    private static function fejlecek(string $fej): array
    {
        $ki   = [];
        $fej  = (string) preg_replace('/\r?\n[ \t]+/', ' ', $fej);

        foreach (preg_split('/\r?\n/', $fej) ?: [] as $sor) {
            $poz = strpos($sor, ':');

            if ($poz === false || $poz === 0) {
                continue;
            }

            $ki[strtolower(trim(substr($sor, 0, $poz)))][] = trim(substr($sor, $poz + 1));
        }

        return $ki;
    }

    /**
     * Egy MIME-rész feldolgozása (rekurzívan a többrészes levelekben).
     *
     * @param array<string, array<int, string>> $fejlecek
     * @param array<string, mixed>              $ki
     */
    private static function resz(array $fejlecek, string $torzs, array &$ki, int $melyseg): void
    {
        [$tipus, $parameterek] = self::parameteres($fejlecek['content-type'][0] ?? 'text/plain');
        [$diszp, $diszp_par]   = self::parameteres($fejlecek['content-disposition'][0] ?? '');

        $tipus    = $tipus !== '' ? $tipus : 'text/plain';
        $kodolas  = strtolower(trim($fejlecek['content-transfer-encoding'][0] ?? '7bit'));
        $fajlnev  = (string) ($diszp_par['filename'] ?? $parameterek['name'] ?? '');
        $cid      = trim((string) ($fejlecek['content-id'][0] ?? ''), "<> \t");

        if (strncmp($tipus, 'multipart/', 10) === 0 && $melyseg < self::MELYSEG_MAX) {
            $hatar = (string) ($parameterek['boundary'] ?? '');

            if ($hatar !== '') {
                foreach (self::darabol($torzs, $hatar) as $darab) {
                    [$fej, $belso] = self::kettevag($darab);
                    self::resz(self::fejlecek($fej), $belso, $ki, $melyseg + 1);
                }

                return;
            }
        }

        $csatolmany = $diszp === 'attachment' || ($fajlnev !== '' && $diszp !== 'inline') || ($fajlnev !== '' && strncmp($tipus, 'text/', 5) !== 0);

        if (!$csatolmany && ($tipus === 'text/plain' || $tipus === 'text/html')) {
            $szoveg = self::utf8(self::atvitel_dekodol($torzs, $kodolas), (string) ($parameterek['charset'] ?? ''));

            // Több szöveges rész (pl. továbbított levél) egymás után kerül.
            if ($tipus === 'text/html') {
                $ki['html'] .= ($ki['html'] !== '' ? '<hr>' : '') . $szoveg;
            } else {
                $ki['szoveg'] .= ($ki['szoveg'] !== '' ? "\n\n" : '') . $szoveg;
            }

            return;
        }

        // Beágyazott levél (továbbítás mellékletként): .eml csatolmány.
        if ($tipus === 'message/rfc822' && $fajlnev === '') {
            $fajlnev = 'tovabbitott-level.eml';
        }

        // Minden más: csatolmány vagy a HTML-be ágyazott kép.
        if ($tipus !== 'text/plain' && $tipus !== 'text/html' || $csatolmany) {
            $tartalom = self::atvitel_dekodol($torzs, $kodolas);

            $ki['csatolmanyok'][] = [
                'nev'        => $fajlnev !== '' ? $fajlnev : self::alap_nev($tipus, count($ki['csatolmanyok']) + 1),
                'tipus'      => $tipus,
                'meret'      => strlen($tartalom),
                'cid'        => $cid,
                'beagyazott' => $cid !== '' && $diszp !== 'attachment' && strncmp($tipus, 'image/', 6) === 0,
                'tartalom'   => $tartalom,
            ];
        }
    }

    /**
     * Többrészes törzs darabjai a határoló mentén. Csonka levélnél (nincs
     * záró határoló) az utolsó darab addig tart, ameddig a levél.
     *
     * @return array<int, string>
     */
    private static function darabol(string $torzs, string $hatar): array
    {
        // A határoló utáni sortörést nem „fogyasztjuk el": egymást követő határolóknál
        // (beágyazott többrészes levél vége + a külső következő része) az a következő előtagja.
        $reszek = preg_split('/(?:^|\r?\n)--' . preg_quote($hatar, '/') . '(--|)[ \t]*(?=\r?\n|$)/', $torzs, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (!is_array($reszek) || count($reszek) < 2) {
            return [];
        }

        $ki = [];

        // $reszek: [bevezető, (-- vagy ''), 1. darab, (--…), 2. darab, …] – a záró „--" után már nincs darab.
        for ($i = 2; $i < count($reszek); $i += 2) {
            if (($reszek[$i - 1] ?? '') === '--') {
                break;
            }

            $darab = (string) preg_replace('/^\r?\n/', '', $reszek[$i]);

            if (trim($darab) !== '') {
                $ki[] = $darab;
            }
        }

        return $ki;
    }

    private static function alap_nev(string $tipus, int $n): string
    {
        $kiterjesztes = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
            'text/calendar' => 'ics', 'message/rfc822' => 'eml', 'text/plain' => 'txt', 'text/html' => 'html',
        ];

        return 'csatolmany-' . $n . '.' . ($kiterjesztes[$tipus] ?? 'bin');
    }

    /* =================================================================
     * Kódolások
     * ============================================================== */

    private static function atvitel_dekodol(string $adat, string $kodolas): string
    {
        if ($kodolas === 'base64') {
            // Csonka levélnél az utolsó, félbemaradt négyes csoport elhagyható.
            $tiszta = (string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $adat);
            $tiszta = rtrim($tiszta, '=');
            $tiszta = substr($tiszta, 0, strlen($tiszta) - (strlen($tiszta) % 4 === 1 ? 1 : 0));
            $ki     = base64_decode($tiszta, false);

            return $ki === false ? '' : $ki;
        }

        if ($kodolas === 'quoted-printable') {
            return quoted_printable_decode($adat);
        }

        return $adat;
    }

    /** Szöveg UTF-8-ra a megadott karakterkészletből (a magyar levelek gyakran ISO-8859-2 vagy Windows-1250). */
    public static function utf8(string $szoveg, string $karakterkeszlet): string
    {
        $kk = strtolower(trim($karakterkeszlet, " \t\"'"));

        if ($kk === '' || $kk === 'utf-8' || $kk === 'utf8' || $kk === 'us-ascii' || $kk === 'ascii') {
            return self::ervenyes_utf8($szoveg);
        }

        $alnev = ['windows-1250' => 'CP1250', 'cp-1250' => 'CP1250', 'x-cp1250' => 'CP1250', 'latin2' => 'ISO-8859-2', 'iso8859-2' => 'ISO-8859-2', 'latin1' => 'ISO-8859-1', 'ks_c_5601-1987' => 'CP949'];
        $nev   = $alnev[$kk] ?? strtoupper($kk);

        if (function_exists('iconv')) {
            $ki = @iconv($nev, 'UTF-8//IGNORE', $szoveg);

            if (is_string($ki) && ($ki !== '' || $szoveg === '')) {
                return $ki;
            }
        }

        if (function_exists('mb_convert_encoding')) {
            try {
                $ki = @mb_convert_encoding($szoveg, 'UTF-8', $nev);

                if (is_string($ki)) {
                    return $ki;
                }
            } catch (\Throwable $hiba) {
                // Ismeretlen karakterkészlet: marad az érvényesített eredeti.
            }
        }

        return self::ervenyes_utf8($szoveg);
    }

    /** Érvénytelen bájtok nélkül (hibás levelek); ha nem UTF-8, közép-európai kódlapként értelmezzük. */
    private static function ervenyes_utf8(string $szoveg): string
    {
        if (mb_check_encoding($szoveg, 'UTF-8')) {
            return $szoveg;
        }

        $ki = function_exists('iconv') ? @iconv('CP1250', 'UTF-8//IGNORE', $szoveg) : false;

        return is_string($ki) ? $ki : (string) mb_convert_encoding($szoveg, 'UTF-8', 'UTF-8');
    }

    /** Fejlécérték dekódolása (=?UTF-8?B?…?=, =?iso-8859-2?Q?…?=). */
    public static function fejlec_dekodol(string $ertek): string
    {
        // Két kódolt szó között a szóköz nem számít.
        $ertek = (string) preg_replace('/(\?=)\s+(=\?)/', '$1$2', $ertek);

        $ki = preg_replace_callback('/=\?([^?\s]+)\?([bBqQ])\?([^?]*)\?=/', static function (array $m): string {
            $adat = strtoupper($m[2]) === 'B'
                ? (string) base64_decode($m[3], false)
                : quoted_printable_decode(str_replace('_', ' ', $m[3]));

            // A karakterkészlet után nyelvi jelölő is állhat (utf-8*hu).
            return self::utf8($adat, (string) preg_replace('/\*.*$/', '', $m[1]));
        }, $ertek);

        return self::ervenyes_utf8(trim((string) preg_replace('/\s+/', ' ', is_string($ki) ? $ki : $ertek)));
    }

    /**
     * „típus; kulcs=érték; …" fejléc szétbontása (RFC 2231 folytatásokkal és kódolással).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function parameteres(string $ertek): array
    {
        $reszek = self::vag($ertek, ';');
        $tipus  = strtolower(trim((string) array_shift($reszek)));
        $nyers  = [];

        foreach ($reszek as $resz) {
            $poz = strpos($resz, '=');

            if ($poz === false) {
                continue;
            }

            $kulcs = strtolower(trim(substr($resz, 0, $poz)));
            $adat  = trim(substr($resz, $poz + 1));

            if (strlen($adat) >= 2 && $adat[0] === '"' && substr($adat, -1) === '"') {
                $adat = stripcslashes(substr($adat, 1, -1));
            }

            $nyers[$kulcs] = $adat;
        }

        $ki = [];

        foreach ($nyers as $kulcs => $adat) {
            // name*0*=…; name*1*=… (folytatás) és name*=utf-8''… (kódolt)
            if (preg_match('/^([^*]+)\*(\d+)?(\*)?$/', $kulcs, $m) === 1) {
                $alap = $m[1];

                if (isset($ki[$alap . "\x00kesz"])) {
                    continue;
                }

                $osszes   = '';
                $kodolt   = false;

                if (($m[2] ?? '') === '') {
                    $osszes = $adat;
                    $kodolt = true;
                } else {
                    for ($i = 0; isset($nyers[$alap . '*' . $i]) || isset($nyers[$alap . '*' . $i . '*']); $i++) {
                        if (isset($nyers[$alap . '*' . $i . '*'])) {
                            $osszes .= $nyers[$alap . '*' . $i . '*'];
                            $kodolt  = true;
                        } else {
                            $osszes .= $nyers[$alap . '*' . $i];
                        }
                    }
                }

                if ($kodolt && preg_match("/^([^']*)'[^']*'(.*)$/s", $osszes, $r) === 1) {
                    $osszes = self::utf8(rawurldecode($r[2]), $r[1]);
                } elseif ($kodolt) {
                    $osszes = rawurldecode($osszes);
                }

                $ki[$alap]              = $osszes;
                $ki[$alap . "\x00kesz"] = '1';

                continue;
            }

            if (!isset($ki[$kulcs])) {
                $ki[$kulcs] = self::fejlec_dekodol($adat);
            }
        }

        foreach (array_keys($ki) as $kulcs) {
            if (substr((string) $kulcs, -5) === "\x00kesz") {
                unset($ki[$kulcs]);
            }
        }

        return [$tipus, $ki];
    }

    /**
     * Szétvágás elválasztó mentén, az idézőjelen belüli elválasztókat kihagyva.
     *
     * @return array<int, string>
     */
    private static function vag(string $szoveg, string $elvalaszto): array
    {
        $ki      = [];
        $akt     = '';
        $idezet  = false;
        $zarojel = 0;
        $hossz   = strlen($szoveg);

        for ($i = 0; $i < $hossz; $i++) {
            $c = $szoveg[$i];

            if ($c === '\\' && $idezet && $i + 1 < $hossz) {
                $akt .= $c . $szoveg[++$i];

                continue;
            }

            if ($c === '"') {
                $idezet = !$idezet;
            } elseif (!$idezet && $c === '<') {
                $zarojel++;
            } elseif (!$idezet && $c === '>' && $zarojel > 0) {
                $zarojel--;
            } elseif (!$idezet && $zarojel === 0 && $c === $elvalaszto) {
                $ki[] = $akt;
                $akt  = '';

                continue;
            }

            $akt .= $c;
        }

        $ki[] = $akt;

        return $ki;
    }

    /* =================================================================
     * Címek, szöveg
     * ============================================================== */

    /**
     * Címlista („Név <a@b.hu>, c@d.hu") szétbontása. A bemenet már dekódolt fejléc.
     *
     * @return array<int, array{nev: string, email: string}>
     */
    public static function cimek(string $fejlec): array
    {
        $ki = [];

        foreach (self::vag($fejlec, ',') as $darab) {
            $darab = trim($darab);

            if ($darab === '') {
                continue;
            }

            $nev   = '';
            $email = '';

            if (preg_match('/^(.*)<([^<>]*)>\s*$/s', $darab, $m) === 1) {
                $nev   = trim($m[1]);
                $email = trim($m[2]);
            } else {
                $email = $darab;
            }

            if (strlen($nev) >= 2 && $nev[0] === '"' && substr($nev, -1) === '"') {
                $nev = stripcslashes(substr($nev, 1, -1));
            }

            // Megjegyzés a cím után: a@b.hu (Név)
            if ($nev === '' && preg_match('/^(\S+@\S+)\s*\((.*)\)$/', $email, $m) === 1) {
                $email = $m[1];
                $nev   = $m[2];
            }

            $email = strtolower(trim($email, " \t<>\"';"));

            if ($email === '' || strpos($email, '@') === false) {
                continue;
            }

            $ki[] = ['nev' => trim($nev), 'email' => $email];
        }

        return $ki;
    }

    /** HTML levélből olvasható szöveg (kivonathoz, kereséshez, az ügynöknek). */
    public static function html_szoveg(string $html): string
    {
        $html = (string) preg_replace('~<(script|style|head|title)\b[^>]*>.*?</\1>~is', ' ', $html);
        $html = (string) preg_replace('~<!--.*?-->~s', ' ', $html);
        $html = (string) preg_replace('~<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>~i', "\n", $html);
        $szoveg = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $szoveg = str_replace(["\u{00a0}", "\u{200b}", "\u{200c}", "\u{feff}", "\u{034f}"], ' ', $szoveg);
        $szoveg = (string) preg_replace('/[ \t]+/u', ' ', $szoveg);
        $szoveg = (string) preg_replace('/\s*\n\s*/u', "\n", $szoveg);

        return trim($szoveg);
    }

    /** A levél olvasható szövege: a szöveges rész, annak híján a HTML-ből. */
    public static function olvashato(array $level): string
    {
        $szoveg = trim((string) ($level['szoveg'] ?? ''));

        return $szoveg !== '' ? $szoveg : self::html_szoveg((string) ($level['html'] ?? ''));
    }

    /** Rövid kivonat a levéllistába: idézett sorok és üres sorok nélkül. */
    public static function kivonat(string $szoveg, int $hossz = 240): string
    {
        $sorok = [];

        foreach (preg_split('/\r?\n/', $szoveg) ?: [] as $sor) {
            $sor = trim($sor);

            if ($sor === '' || $sor[0] === '>') {
                continue;
            }

            $sorok[] = $sor;
        }

        $egyben = (string) preg_replace('/\s+/u', ' ', implode(' ', $sorok));

        return mb_strlen($egyben, 'UTF-8') > $hossz ? rtrim(mb_substr($egyben, 0, $hossz - 1, 'UTF-8')) . '…' : $egyben;
    }
}
