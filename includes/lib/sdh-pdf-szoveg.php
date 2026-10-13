<?php
/**
 * SDH Műhely – szöveg kinyerése PDF-ből (0.61.5).
 *
 * Csak OLVAS: a beszállítói számla PDF-jéből szedi ki a szöveget, hogy a
 * tételolvasó dolgozhasson vele (ebből lesz a telefonszámos tételek címkéje).
 * Semmit nem ír, nem futtat, nem tölt le – a bemenet bájtok, a kimenet szöveg.
 *
 * Mit tud:
 *   - FlateDecode-olt és tömörítetlen tartalomfolyamok,
 *   - Tj, TJ, ' és " szövegoperátorok,
 *   - Tm / Td / TD / T* alapján sortörés (a sor a tételolvasónak kell),
 *   - /ToUnicode CMap (bfchar, bfrange) – enélkül az Identity-H fontok
 *     szövege olvashatatlan volna –, fontonként külön.
 *
 * Mit NEM tud: szkennelt (képből álló) PDF, titkosított fájl, objektumfolyamba
 * (ObjStm) csomagolt szerkezet. Ilyenkor üres szöveget ad vissza, és a
 * feldolgozás a kézi beillesztésre marad – nem hibázik el semmit.
 *
 * @package SDH_Muhely
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('SDH_Muhely_Pdf_Szoveg')) {
    return;
}

final class SDH_Muhely_Pdf_Szoveg
{
    /** Ennél nagyobb fájlhoz nem nyúlunk (bájt). */
    private const MAX_MERET = 20971520;

    /** Ennyi karakternél többet nem adunk vissza. */
    private const MAX_SZOVEG = 400000;

    /** PDF-e egyáltalán? */
    public static function pdf_e(string $nyers): bool
    {
        return str_starts_with(ltrim(substr($nyers, 0, 1024)), '%PDF-');
    }

    /**
     * A PDF szöveges tartalma, soronként.
     *
     * @param string $nyers a fájl bájtjai
     */
    public static function olvas(string $nyers): string
    {
        if (!self::pdf_e($nyers) || strlen($nyers) > self::MAX_MERET || !function_exists('gzuncompress')) {
            return '';
        }

        $objektumok = self::objektumok($nyers);

        if ($objektumok === []) {
            return '';
        }

        $cmapek    = self::cmapek($objektumok);
        $fonttabla = self::fonttabla($objektumok, $cmapek);
        $ki        = [];

        foreach ($objektumok as $szam => $obj) {
            // Tartalomfolyam az, amiben szövegoperátorok vannak; a CMap-eket és
            // a betűtípusokat kihagyjuk.
            $folyam = self::folyam($obj);

            if ($folyam === '' || !str_contains($folyam, 'BT') || str_contains($folyam, 'beginbfchar') || str_contains($folyam, 'beginbfrange')) {
                continue;
            }

            $ki[] = self::folyam_szoveg($folyam, $fonttabla);

            if (mb_strlen(implode("\n", $ki)) > self::MAX_SZOVEG) {
                break;
            }
        }

        $szoveg = trim(implode("\n", array_filter($ki, static fn (string $s): bool => trim($s) !== '')));

        return mb_substr((string) preg_replace("/\n{3,}/", "\n\n", $szoveg), 0, self::MAX_SZOVEG);
    }

    /**
     * A fájl objektumai: szám => nyers tartalom.
     *
     * @return array<int, string>
     */
    private static function objektumok(string $nyers): array
    {
        $ki = [];

        if (preg_match_all('~(\d+)\s+\d+\s+obj\b(.*?)\bendobj~s', $nyers, $t, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($t as $o) {
            $ki[(int) $o[1]] = (string) $o[2];
        }

        return $ki;
    }

    /** Egy objektum kibontott tartalomfolyama ('' ha nincs, vagy nem megy). */
    private static function folyam(string $obj): string
    {
        $p = strpos($obj, 'stream');

        if ($p === false) {
            return '';
        }

        $fej  = substr($obj, 0, $p);
        $test = substr($obj, $p + 6);
        $test = (string) preg_replace('~^\r\n|^\n|^\r~', '', $test);
        $vege = strrpos($test, 'endstream');

        if ($vege !== false) {
            $test = substr($test, 0, $vege);
        }

        if (!str_contains($fej, '/Filter')) {
            return $test;
        }

        // A szűrők láncban állnak (/Filter [ /ASCII85Decode /FlateDecode ]):
        // ugyanabban a sorrendben kell visszafejteni őket.
        if (preg_match('~/Filter\s*(\[[^\]]*\]|/[A-Za-z0-9]+)~', $fej, $m) !== 1) {
            return '';
        }

        if (preg_match_all('~/([A-Za-z0-9]+)~', (string) $m[1], $sz) === false) {
            return '';
        }

        foreach ((array) $sz[1] as $szuro) {
            $test = match ((string) $szuro) {
                'FlateDecode'     => self::kibont($test),
                'ASCII85Decode'   => self::a85($test),
                'ASCIIHexDecode'  => (string) hex2bin((string) preg_replace('~[^0-9A-Fa-f]~', '', (string) strtok($test, '>'))),
                // Képszűrő vagy ismeretlen: ebben nincs szöveg.
                default           => '',
            };

            if ($test === '') {
                return '';
            }
        }

        return $test;
    }

    /** zlib vagy nyers deflate kibontása. */
    private static function kibont(string $adat): string
    {
        $ki = @gzuncompress($adat);

        if ($ki === false) {
            $ki = @gzinflate($adat);
        }

        return is_string($ki) ? $ki : '';
    }

    /** ASCII85 dekódolás (a PDF-ek gyakori szűrője). */
    private static function a85(string $adat): string
    {
        $adat = (string) preg_replace('~\s~', '', $adat);
        $adat = (string) preg_replace('~^<~', '', $adat);
        $vege = strpos($adat, '~>');

        if ($vege !== false) {
            $adat = substr($adat, 0, $vege);
        }

        $ki    = '';
        $tomb  = [];
        $hossz = strlen($adat);

        for ($i = 0; $i < $hossz; $i++) {
            $c = $adat[$i];

            if ($c === 'z' && $tomb === []) {
                $ki .= "\0\0\0\0";

                continue;
            }

            $ertek = ord($c) - 33;

            if ($ertek < 0 || $ertek > 84) {
                continue;
            }

            $tomb[] = $ertek;

            if (count($tomb) === 5) {
                $szam = 0;

                foreach ($tomb as $t) {
                    $szam = $szam * 85 + $t;
                }

                $ki  .= pack('N', $szam);
                $tomb = [];
            }
        }

        if ($tomb !== []) {
            $db = count($tomb);

            for ($i = $db; $i < 5; $i++) {
                $tomb[] = 84;
            }

            $szam = 0;

            foreach ($tomb as $t) {
                $szam = $szam * 85 + $t;
            }

            $ki .= substr(pack('N', $szam), 0, $db - 1);
        }

        return $ki;
    }

    /**
     * A fájl összes ToUnicode CMap-je: objektumszám => [kód => karakter].
     *
     * @param array<int, string> $objektumok
     * @return array<int, array<int, string>>
     */
    private static function cmapek(array $objektumok): array
    {
        $ki = [];

        foreach ($objektumok as $szam => $obj) {
            $folyam = self::folyam($obj);

            if ($folyam === '' || (!str_contains($folyam, 'beginbfchar') && !str_contains($folyam, 'beginbfrange'))) {
                continue;
            }

            $ki[$szam] = self::cmap($folyam);
        }

        return $ki;
    }

    /**
     * Egy ToUnicode CMap feldolgozása.
     *
     * @return array<int, string>
     */
    private static function cmap(string $cmap): array
    {
        $tabla = [];

        if (preg_match_all('~beginbfchar(.*?)endbfchar~s', $cmap, $t) !== false) {
            foreach ((array) $t[1] as $blokk) {
                if (preg_match_all('~<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>~', (string) $blokk, $p, PREG_SET_ORDER) === false) {
                    continue;
                }

                foreach ($p as $sor) {
                    $tabla[(int) hexdec($sor[1])] = self::utf16($sor[2]);
                }
            }
        }

        if (preg_match_all('~beginbfrange(.*?)endbfrange~s', $cmap, $t) !== false) {
            foreach ((array) $t[1] as $blokk) {
                // <tól> <ig> <első karakter>
                if (preg_match_all('~<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>~', (string) $blokk, $p, PREG_SET_ORDER) !== false) {
                    foreach ($p as $sor) {
                        $tol = (int) hexdec($sor[1]);
                        $ig  = (int) hexdec($sor[2]);
                        $uni = (int) hexdec($sor[3]);

                        for ($i = $tol; $i <= $ig && $i - $tol < 65535; $i++) {
                            $tabla[$i] = self::karakter($uni + ($i - $tol));
                        }
                    }
                }

                // <tól> <ig> [ <k1> <k2> … ]
                if (preg_match_all('~<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]~s', (string) $blokk, $p, PREG_SET_ORDER) !== false) {
                    foreach ($p as $sor) {
                        $tol = (int) hexdec($sor[1]);

                        if (preg_match_all('~<([0-9A-Fa-f]+)>~', (string) $sor[3], $e) === false) {
                            continue;
                        }

                        foreach ((array) $e[1] as $i => $hex) {
                            $tabla[$tol + (int) $i] = self::utf16((string) $hex);
                        }
                    }
                }
            }
        }

        return $tabla;
    }

    /** UTF-16BE hexa → UTF-8 karakter(ek). */
    private static function utf16(string $hex): string
    {
        $bin = (string) hex2bin(strlen($hex) % 2 === 1 ? '0' . $hex : $hex);
        $ki  = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');

        return is_string($ki) ? $ki : '';
    }

    /** Unicode kódpont → UTF-8 karakter. */
    private static function karakter(int $kod): string
    {
        $ki = @mb_convert_encoding(pack('N', $kod), 'UTF-8', 'UTF-32BE');

        return is_string($ki) ? $ki : '';
    }

    /**
     * A dokumentum fontjai: a /Tf operátorban álló név → ToUnicode tábla.
     *
     * A fontszótár állhat a lapon belül (/Font << /F1 7 0 R >>) vagy külön
     * objektumban (/Font 1 0 R) – mindkettőt megnézzük. A neveket egy közös
     * táblába gyűjtjük: a tartalomfolyam úgyis névvel hivatkozik rájuk.
     *
     * @param array<int, string> $objektumok
     * @param array<int, array<int, string>> $cmapek
     * @return array<string, array<int, string>>
     */
    private static function fonttabla(array $objektumok, array $cmapek): array
    {
        if ($cmapek === []) {
            return [];
        }

        $ki = [];

        foreach ($objektumok as $obj) {
            // A fontszótár: ami /BaseFont nélküli, de fontnév => hivatkozás párokat tart.
            $szotarak = [];

            if (preg_match_all('~/Font\s*<<(.*?)>>~s', $obj, $m) !== false) {
                foreach ((array) $m[1] as $resz) {
                    $szotarak[] = (string) $resz;
                }
            }

            if (preg_match('~/Font\s+(\d+)\s+\d+\s+R~', $obj, $r) === 1) {
                $szotarak[] = (string) ($objektumok[(int) $r[1]] ?? '');
            }

            foreach ($szotarak as $szotar) {
                if (preg_match_all('~/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R~', $szotar, $f, PREG_SET_ORDER) === false) {
                    continue;
                }

                foreach ($f as $font) {
                    $fo = $objektumok[(int) $font[2]] ?? '';

                    if ($fo === '' || preg_match('~/ToUnicode\s+(\d+)\s+\d+\s+R~', $fo, $tu) !== 1) {
                        continue;
                    }

                    $tabla = $cmapek[(int) $tu[1]] ?? [];

                    if ($tabla !== []) {
                        $ki[(string) $font[1]] = $tabla;
                    }
                }
            }
        }

        return $ki;
    }

    /**
     * Egy tartalomfolyam szövege, sortörésekkel.
     *
     * @param array<string, array<int, string>> $fontok
     */
    private static function folyam_szoveg(string $folyam, array $fontok): string
    {
        $sorok  = [];
        $sor    = '';
        $y      = null;
        $cmap   = [];
        $hossz  = strlen($folyam);
        $i      = 0;
        $verem  = [];

        while ($i < $hossz) {
            $c = $folyam[$i];

            if ($c === '(') {
                [$szoveg, $i] = self::string_olvas($folyam, $i);
                $verem[]      = ['s', $szoveg];

                continue;
            }

            if ($c === '<' && ($folyam[$i + 1] ?? '') !== '<') {
                $vege = strpos($folyam, '>', $i);

                if ($vege === false) {
                    break;
                }

                $verem[] = ['h', substr($folyam, $i + 1, $vege - $i - 1)];
                $i       = $vege + 1;

                continue;
            }

            // Operátor vagy szám.
            if (preg_match('~[A-Za-z\'"*]+|-?[\d.]+~A', $folyam, $m, 0, $i) === 1) {
                $jel = (string) $m[0];
                $i  += strlen($jel);

                switch ($jel) {
                    case 'Tf':
                        // A fontnevet a verem előző eleme hordozza (/F1 formában).
                        $elozo = array_slice($verem, -2);

                        foreach ($elozo as $e) {
                            if ($e[0] === 'n' && isset($fontok[ltrim((string) $e[1], '/')])) {
                                $cmap = $fontok[ltrim((string) $e[1], '/')];
                            }
                        }

                        $verem = [];
                        break;

                    case 'Tj':
                    case '\'':
                    case '"':
                        foreach ($verem as $e) {
                            $sor .= self::darab($e, $cmap);
                        }

                        $verem = [];

                        if ($jel !== 'Tj') {
                            $sorok[] = $sor;
                            $sor     = '';
                        }
                        break;

                    case 'TJ':
                        foreach ($verem as $e) {
                            if ($e[0] === 'f' && (float) $e[1] < -120) {
                                $sor .= ' ';

                                continue;
                            }

                            $sor .= self::darab($e, $cmap);
                        }

                        $verem = [];
                        break;

                    case 'Td':
                    case 'TD':
                    case 'Tm':
                    case 'T*':
                    case 'BT':
                    case 'ET':
                        $ujY = self::veremY($verem, $jel, $y);

                        if ($jel === 'T*' || ($ujY !== null && $y !== null && abs($ujY - $y) > 1.2)) {
                            $sorok[] = $sor;
                            $sor     = '';
                        }

                        $y     = $ujY ?? $y;
                        $verem = [];
                        break;

                    default:
                        if (preg_match('~^-?[\d.]+$~', $jel) === 1) {
                            $verem[] = ['f', $jel];
                        } else {
                            $verem = [];
                        }
                }

                continue;
            }

            if ($c === '/') {
                if (preg_match('~/[^\s/\[\]<>()]*~A', $folyam, $m, 0, $i) === 1) {
                    $verem[] = ['n', (string) $m[0]];
                    $i      += strlen((string) $m[0]);

                    continue;
                }
            }

            $i++;
        }

        if (trim($sor) !== '') {
            $sorok[] = $sor;
        }

        $sorok = array_map(static fn (string $s): string => trim((string) preg_replace('/[ \t]+/', ' ', $s)), $sorok);

        return implode("\n", array_filter($sorok, static fn (string $s): bool => $s !== ''));
    }

    /**
     * A szövegmátrix / sorugrás Y-koordinátája a veremből.
     *
     * @param array<int, array{0: string, 1: string}> $verem
     */
    private static function veremY(array $verem, string $jel, ?float $y): ?float
    {
        $szamok = [];

        foreach ($verem as $e) {
            if ($e[0] === 'f') {
                $szamok[] = (float) $e[1];
            }
        }

        if ($jel === 'Tm' && count($szamok) >= 6) {
            return $szamok[5];
        }

        if (($jel === 'Td' || $jel === 'TD') && count($szamok) >= 2) {
            return ($y ?? 0.0) + $szamok[1];
        }

        return null;
    }

    /**
     * Egy veremelem szövege.
     *
     * @param array{0: string, 1: string} $elem
     * @param array<int, string> $cmap
     */
    private static function darab(array $elem, array $cmap): string
    {
        if ($elem[0] === 's') {
            return $cmap === [] ? $elem[1] : self::cmappel($elem[1], $cmap, false);
        }

        if ($elem[0] === 'h') {
            $hex = (string) preg_replace('~[^0-9A-Fa-f]~', '', $elem[1]);

            if ($cmap !== []) {
                return self::cmappel($hex, $cmap, true);
            }

            $bin = (string) hex2bin(strlen($hex) % 2 === 1 ? $hex . '0' : $hex);

            return self::latin($bin);
        }

        return '';
    }

    /**
     * Kódok átváltása a CMap szerint. A CID-fontok két bájton kódolnak,
     * az egybájtosak egyen – a CMap kulcsai alapján döntjük el.
     *
     * @param array<int, string> $cmap
     */
    private static function cmappel(string $adat, array $cmap, bool $hexa): string
    {
        $bin = $hexa ? (string) hex2bin(strlen($adat) % 2 === 1 ? $adat . '0' : $adat) : $adat;
        $ket = max(array_keys($cmap)) > 255;
        $ki  = '';

        if ($ket) {
            for ($i = 0; $i + 1 < strlen($bin); $i += 2) {
                $kod = (ord($bin[$i]) << 8) | ord($bin[$i + 1]);
                $ki .= $cmap[$kod] ?? '';
            }

            return $ki;
        }

        for ($i = 0; $i < strlen($bin); $i++) {
            $kod = ord($bin[$i]);
            $ki .= $cmap[$kod] ?? self::latin($bin[$i]);
        }

        return $ki;
    }

    /** WinAnsi (CP1252) → UTF-8. */
    private static function latin(string $bin): string
    {
        $ki = @mb_convert_encoding($bin, 'UTF-8', 'Windows-1252');

        return is_string($ki) ? $ki : $bin;
    }

    /**
     * Egy ( … ) string beolvasása a zárójelek és az escape-ek kezelésével.
     *
     * @return array{0: string, 1: int} a szöveg és a következő pozíció
     */
    private static function string_olvas(string $folyam, int $i): array
    {
        $melyseg = 0;
        $ki      = '';
        $hossz   = strlen($folyam);

        for (; $i < $hossz; $i++) {
            $c = $folyam[$i];

            if ($c === '\\') {
                $kov = $folyam[$i + 1] ?? '';

                if (preg_match('~[0-7]~', $kov) === 1) {
                    $oktal = '';

                    for ($j = 1; $j <= 3 && preg_match('~[0-7]~', $folyam[$i + $j] ?? '') === 1; $j++) {
                        $oktal .= $folyam[$i + $j];
                    }

                    $ki .= chr((int) octdec($oktal) % 256);
                    $i  += strlen($oktal);

                    continue;
                }

                $ki .= match ($kov) {
                    'n'     => "\n",
                    'r'     => "\r",
                    't'     => "\t",
                    'b', 'f' => ' ',
                    default => $kov,
                };
                $i++;

                continue;
            }

            if ($c === '(') {
                $melyseg++;

                if ($melyseg === 1) {
                    continue;
                }
            }

            if ($c === ')') {
                $melyseg--;

                if ($melyseg === 0) {
                    return [self::latin($ki), $i + 1];
                }
            }

            $ki .= $c;
        }

        return [self::latin($ki), $hossz];
    }
}
