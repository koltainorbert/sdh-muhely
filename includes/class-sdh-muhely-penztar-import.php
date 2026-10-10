<?php
/**
 * Házipénztár – import a régi Excel-táblából (vagy CSV-ből).
 *
 * A régi „Zárás" munkafüzet szerkezete: napi blokkok, mindegyik egy
 * fejléc-sorral (Dátum | cikkszám/munka | KP | B.kártya | Munkalap sorszám |
 * Vonalkód | Kifizetés | KP a kasszában | Számlaszám | …), a tételekkel és
 * egy összesítő sorral. A beolvasó ezt a szerkezetet ismeri fel:
 *   - a fejléc- és az összesítő sorokat átugorja (az összesítőt a rendszer
 *     maga számolja), de ha az összesítő „KP a kasszában" cellájába kézzel
 *     írt szám áll (nem képlet), az a nap megszámolt záró összege lesz;
 *   - a „Kifizetés" negatív összegből kivét (ha a szöveg kivétet mond, a
 *     becenévből a személyt is felismeri) vagy kifizetés lesz;
 *   - a hibás dátumot (pl. „20262.06.02") az előző sor dátuma pótolja;
 *   - a félrecsúszott számlaszámot (a „KP a kasszában" oszlopban) visszateszi.
 *
 * XLSX: saját, függőség nélküli olvasó (ZipArchive + XMLReader, soronként
 * streamelve – a 10 MB-os munkalap sem kerül egyben a memóriába).
 *
 * Az import kétlépcsős: feltöltés → előnézet (mi kerülne be, mennyi van már
 * bent) → beírás 2500 soros adagokban, folyamatjelzővel. Minden sornak
 * tartalom alapú kulcsa van, így az újra feltöltött (bővült) táblából csak
 * az új sorok kerülnek be. Visszavonható.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Penztar_Import
{
    private const MAPPA = 'sdh-muhely-penztar';

    private const ADAG = 2500;

    private const MAX_MERET = 60 * 1024 * 1024;

    public static function init(): void
    {
        foreach (['import_feltolt' => 'ajax_feltolt', 'import_lepes' => 'ajax_lepes', 'import_visszavon' => 'ajax_visszavon'] as $nev => $fv) {
            add_action('wp_ajax_sdh_muhely_penztar_' . $nev, [self::class, $fv]);
        }
    }

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        wp_raise_memory_limit('admin');

        // Végzetes hiba (időkorlát, memória) esetén is olvasható üzenet menjen a felületre, ne csak egy „500".
        register_shutdown_function(static function (): void {
            $h = error_get_last();

            if (!is_array($h) || !in_array((int) $h['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            if (!headers_sent()) {
                status_header(200);
                header('Content-Type: application/json; charset=utf-8');
            }

            echo wp_json_encode([
                'success' => false,
                'data'    => ['uzenet' => 'Szerverhiba az import közben: ' . wp_strip_all_tags((string) $h['message']) . ' – próbáld újra: a már bent lévő sorok nem kerülnek be kétszer.'],
            ]);
        });
    }

    /** A védett munkamappa (uploads alatt, kívülről nem olvasható). */
    private static function mappa(): string
    {
        $up  = wp_upload_dir();
        $dir = trailingslashit((string) $up['basedir']) . self::MAPPA;

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); // phpcs:ignore
            @file_put_contents($dir . '/index.php', "<?php // Csend.\n"); // phpcs:ignore
        }

        return $dir;
    }

    private static function token_fajl(string $token): string
    {
        return self::mappa() . '/import-' . $token . '.json';
    }

    /* =================================================================
     * XLSX olvasó
     * ============================================================== */

    /** Oszlopbetű → index (A = 0). */
    private static function oszlop(string $cella): int
    {
        $betuk = preg_replace('/\d+/', '', $cella) ?? '';
        $n     = 0;

        for ($i = 0; $i < strlen($betuk); $i++) {
            $n = $n * 26 + (ord($betuk[$i]) - 64);
        }

        return $n - 1;
    }

    /**
     * A munkafüzet sorai: sorszám → [oszlopindex → ['v' => érték, 'f' => képlet-e]].
     * A legnagyobb munkalapot olvassa (a „KP" számoló lapot nem).
     *
     * @return \Generator<int, array<int, array{v: mixed, f: bool}>>
     */
    public static function xlsx_sorok(string $fajl): \Generator
    {
        if (!class_exists('ZipArchive') || !class_exists('XMLReader')) {
            throw new RuntimeException('A szerveren hiányzik a zip vagy az XMLReader PHP-bővítmény. Mentsd a táblát CSV-be (pontosvesszővel), és azt töltsd fel.');
        }

        $zip = new ZipArchive();

        if ($zip->open($fajl) !== true) {
            throw new RuntimeException('A fájl nem olvasható Excel-munkafüzetként (.xlsx).');
        }

        // A munkalapok és fájljaik.
        $rels = [];
        $rx   = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));

        if ($rx) {
            foreach ($rx->Relationship as $r) {
                $rels[(string) $r['Id']] = ltrim(str_replace('/xl/', '', (string) $r['Target']), '/');
            }
        }

        $wb    = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $lap   = '';
        $meret = -1;

        if ($wb) {
            foreach ($wb->sheets->sheet as $s) {
                $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $ut  = 'xl/' . ($rels[$rid] ?? '');
                $st  = $zip->statName($ut);

                if ($st && (int) $st['size'] > $meret) {
                    $meret = (int) $st['size'];
                    $lap   = $ut;
                }
            }
        }

        if ($lap === '') {
            $lap = 'xl/worksheets/sheet1.xml';
        }

        // Megosztott szövegek.
        $szovegek = [];
        $ss       = $zip->getFromName('xl/sharedStrings.xml');

        if (is_string($ss) && $ss !== '') {
            $xr = new XMLReader();
            $xr->XML($ss, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);
            $akt = null;

            while ($xr->read()) {
                if ($xr->nodeType === XMLReader::ELEMENT && $xr->localName === 'si') {
                    $akt = '';
                } elseif ($xr->nodeType === XMLReader::ELEMENT && $xr->localName === 't' && $akt !== null) {
                    $akt .= $xr->readString();
                } elseif ($xr->nodeType === XMLReader::ELEMENT && $xr->localName === 'rPh') {
                    $xr->next(); // fonetikus segédszöveg: nem kell
                } elseif ($xr->nodeType === XMLReader::END_ELEMENT && $xr->localName === 'si') {
                    $szovegek[] = (string) $akt;
                    $akt = null;
                }
            }

            $xr->close();
            unset($ss);
        }

        $zip->close();

        // A munkalap streamelve, közvetlenül a zipből.
        $xr = new XMLReader();

        if (!@$xr->open('zip://' . $fajl . '#' . $lap, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $zip = new ZipArchive();
            $zip->open($fajl);
            $xr->XML((string) $zip->getFromName($lap), 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);
            $zip->close();
        }

        $sor = [];
        $sorszam = 0;

        while ($xr->read()) {
            if ($xr->nodeType === XMLReader::ELEMENT && $xr->localName === 'row') {
                $sor     = [];
                $sorszam = (int) $xr->getAttribute('r');

                if ($xr->isEmptyElement) {
                    continue;
                }
            } elseif ($xr->nodeType === XMLReader::ELEMENT && $xr->localName === 'c') {
                $r     = (string) $xr->getAttribute('r');
                $tipus = (string) $xr->getAttribute('t');
                $ures  = $xr->isEmptyElement;
                $ertek = null;
                $keplet = false;

                if (!$ures) {
                    $melyseg = $xr->depth;

                    while ($xr->read() && !($xr->nodeType === XMLReader::END_ELEMENT && $xr->localName === 'c' && $xr->depth === $melyseg)) {
                        if ($xr->nodeType !== XMLReader::ELEMENT) {
                            continue;
                        }

                        if ($xr->localName === 'f') {
                            $keplet = true;
                        } elseif ($xr->localName === 'v') {
                            $ertek = $xr->readString();
                        } elseif ($xr->localName === 't') {
                            $ertek = ($ertek ?? '') . $xr->readString();
                        }
                    }
                }

                if ($ertek === null || $ertek === '') {
                    continue;
                }

                if ($tipus === 's') {
                    $ertek = $szovegek[(int) $ertek] ?? '';
                } elseif ($tipus === 'b') {
                    $ertek = $ertek === '1';
                } elseif ($tipus === '' || $tipus === 'n') {
                    $ertek = is_numeric($ertek) ? (float) $ertek : $ertek;
                }

                $oszlop = self::oszlop($r);

                if ($oszlop >= 0 && $oszlop < 12) {
                    $sor[$oszlop] = ['v' => $ertek, 'f' => $keplet];
                }
            } elseif ($xr->nodeType === XMLReader::END_ELEMENT && $xr->localName === 'row') {
                if ($sor !== []) {
                    yield $sorszam => $sor;
                }
            }
        }

        $xr->close();
    }

    /**
     * CSV (pontosvessző vagy vessző): ugyanazok az oszlopok, mint a régi táblában.
     *
     * @return \Generator<int, array<int, array{v: mixed, f: bool}>>
     */
    public static function csv_sorok(string $fajl): \Generator
    {
        $fh = fopen($fajl, 'r');

        if (!$fh) {
            throw new RuntimeException('A fájl nem olvasható.');
        }

        $elso = (string) fgets($fh);
        $elv  = substr_count($elso, ';') >= substr_count($elso, ',') ? ';' : ',';
        rewind($fh);

        $n = 0;

        while (($cellak = fgetcsv($fh, 0, $elv)) !== false) {
            $n++;
            $sor = [];

            foreach ($cellak as $i => $c) {
                $c = trim((string) $c);

                if ($n === 1 && $i === 0) {
                    $c = preg_replace('/^\xEF\xBB\xBF/', '', $c) ?? $c;
                }

                if ($c === '' || $i >= 12) {
                    continue;
                }

                $szam = str_replace([' ', "\u{00A0}"], '', $c);
                $sor[$i] = ['v' => preg_match('/^-?\d+([.,]\d+)?$/', $szam) ? (float) str_replace(',', '.', $szam) : $c, 'f' => strpos($c, '=') === 0];
            }

            if ($sor !== []) {
                yield $n => $sor;
            }
        }

        fclose($fh);
    }

    /* =================================================================
     * Értelmezés
     * ============================================================== */

    private static function datum_ertelmez($v): string
    {
        if (is_float($v) || is_int($v)) {
            // Excel-sorszám (1900-as rendszer): 25569 = 1970-01-01.
            if ($v > 20000 && $v < 80000) {
                return gmdate('Y-m-d', (int) round(($v - 25569) * 86400));
            }

            return '';
        }

        $s = trim((string) $v);

        if (preg_match('/^(\d{4})[.\-\/ ]+(\d{1,2})[.\-\/ ]+(\d{1,2})/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        return '';
    }

    /** A cella értéke (képletnél a tárolt eredmény). */
    private static function cella(array $sor, int $i)
    {
        return $sor[$i]['v'] ?? null;
    }

    private static function szoveg($v, int $max): string
    {
        if ($v === null || is_bool($v)) {
            return '';
        }

        if (is_float($v)) {
            $v = floor($v) == $v ? sprintf('%.0f', $v) : (string) $v;
        }

        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v) ?? ''), 0, $max);
    }

    private static function osszeg($v): float
    {
        if ($v === null || is_bool($v)) {
            return 0.0;
        }

        if (is_float($v) || is_int($v)) {
            return round((float) $v, 2);
        }

        return round(SDH_Muhely_Penztar::szam((string) $v), 2);
    }

    private static function szamla_alak(string $s): bool
    {
        return (bool) preg_match('/^[\p{L}]{1,8}[-\/ ]?\d{2,4}[-\/]\d+$/u', $s);
    }

    /**
     * A beolvasott sorokból pénztártételek.
     *
     * @return array{sorok: array<int, array<string, mixed>>, zarasok: array<string, float>, nyito: float, figyelmeztetes: array<int, string>}
     */
    public static function ertelmez(iterable $forras): array
    {
        $sorok    = [];
        $zarasok  = [];
        $nyito    = 0.0;
        $datum    = '';
        $blokk    = [];
        $hibas_dt = 0;
        $fejlec_volt = false;
        $elofordul = [];

        foreach ($forras as $sorszam => $sor) {
            $a = $sor[0]['v'] ?? null;
            $b = self::szoveg($sor[1]['v'] ?? null, 255);

            if (is_string($a) && mb_strtolower(trim($a), 'UTF-8') === 'dátum') {
                $fejlec_volt = true;
                $blokk = [];
                continue;
            }

            // Az első fejléc előtti „KP a kasszában" szám: a kezdő egyenleg.
            if (!$fejlec_volt) {
                $h = self::cella($sor, 7);

                if (is_float($h) && empty($sor[7]['f'])) {
                    $nyito = (float) $h;
                }

                continue;
            }

            $kp     = self::osszeg(self::cella($sor, 2));
            $kartya = self::osszeg(self::cella($sor, 3));
            $kifiz  = self::osszeg(self::cella($sor, 6));
            $ml     = self::szoveg(self::cella($sor, 4), 40);
            $vonal  = self::szoveg(self::cella($sor, 5), 190);
            $szamla = isset($sor[8]) && !$sor[8]['f'] ? self::szoveg($sor[8]['v'], 60) : '';
            $h      = $sor[7] ?? null;

            // Összesítő sor: nincs leírás. A „KP a kasszában" (képlet eredménye vagy kézzel
            // beírt szám) a régi tábla záró egyenlege – ez lesz a nap záró összege.
            if ($b === '' && ($a === null || $a === '')) {
                if ($h !== null && is_float($h['v']) && $blokk !== []) {
                    $zarasok[end($blokk)] = (float) $h['v'];
                }

                continue;
            }

            if ($b === '' && $kp == 0 && $kartya == 0 && $kifiz == 0) {
                continue; // előre kitöltött dátum, üres sor
            }

            $d = self::datum_ertelmez($a);

            if ($d === '') {
                if ($a !== null && $a !== '') {
                    $hibas_dt++;
                }

                $d = $datum;
            }

            if ($d === '') {
                continue;
            }

            // A dátum nem mehet vissza egy blokkon belül nagyot (elgépelt év): ilyenkor az előző sor dátuma.
            if ($datum !== '' && ($d > SDH_Muhely_Penztar::ma() || $d < '2000-01-01')) {
                $hibas_dt++;
                $d = $datum;
            }

            $datum   = $d;
            $blokk[] = $d;

            $megj = '';

            // A félrecsúszott számlaszám (H oszlop), és a számlaszám-oszlopba írt megjegyzés.
            if ($h !== null && !$h['f'] && is_string($h['v'])) {
                $hs = self::szoveg($h['v'], 60);

                if ($szamla === '' && self::szamla_alak($hs)) {
                    $szamla = $hs;
                } elseif ($hs !== '' && $hs !== ',') {
                    $megj = $hs;
                }
            }

            if ($szamla !== '' && !self::szamla_alak($szamla) && preg_match('/\s/u', $szamla)) {
                $megj   = trim($megj . ' ' . $szamla);
                $szamla = '';
            }

            if (strpos($szamla, '=') === 0 || $szamla === ',') {
                $szamla = '';
            }

            $alap = [
                'datum' => $d, 'leiras' => $b !== '' ? $b : '(leírás nélkül)', 'nev' => '', 'szemely' => '',
                'kp' => 0.0, 'kartya' => 0.0, 'utalas' => 0.0, 'ml' => $ml, 'szamla' => $szamla,
                'vonalkod' => $vonal, 'megj' => mb_substr($megj, 0, 255), 'sorrend' => (int) $sorszam,
            ];

            if ($kp != 0 || $kartya != 0) {
                $sorok[] = ['tipus' => 'bevetel', 'kp' => $kp, 'kartya' => $kartya] + $alap;
            }

            if ($kifiz != 0) {
                $tetel = $alap;

                if ($kifiz > 0) {
                    $tetel['tipus'] = 'befizetes';
                } elseif (preg_match('/kiv[eé]t|kivét|kivett/iu', $b)) {
                    $szemely = SDH_Muhely_Penztar::szemely_felismer($b);
                    $tetel['tipus']   = $szemely !== '' ? 'kivet' : 'kifizetes';
                    $tetel['szemely'] = $szemely;
                    $tetel['nev']     = $szemely !== '' ? $szemely : mb_substr($b, 0, 190);
                } else {
                    $tetel['tipus'] = 'kifizetes';
                    $tetel['nev']   = mb_substr($b, 0, 190);
                }

                $tetel['kp']      = $kifiz;
                $tetel['sorrend'] = (int) $sorszam;
                $sorok[]          = $tetel;
            }
        }

        // Tartalom alapú kulcs: ugyanaz a sor újra feltöltve nem kerül be kétszer.
        foreach ($sorok as $i => $s) {
            $alap = implode('|', [$s['datum'], $s['tipus'], $s['leiras'], round($s['kp']), round($s['kartya']), $s['ml'], $s['szamla'], $s['vonalkod']]);
            $elofordul[$alap] = ($elofordul[$alap] ?? 0) + 1;
            $sorok[$i]['kulso'] = 'x' . substr(md5($alap . '#' . $elofordul[$alap]), 0, 31);
        }

        $figy = [];

        if ($hibas_dt > 0) {
            $figy[] = sprintf('%d sorban a dátum nem volt értelmezhető – ezek az előző sor dátumát kapták.', $hibas_dt);
        }

        return ['sorok' => $sorok, 'zarasok' => $zarasok, 'nyito' => $nyito, 'figyelmeztetes' => $figy];
    }

    /** @return array<string, bool> A már bent lévő import-kulcsok. */
    private static function meglevo_kulcsok(array $kulcsok): array
    {
        global $wpdb;

        $ki = [];

        foreach (array_chunk($kulcsok, 800) as $adag) {
            $hely = implode(',', array_fill(0, count($adag), '%s'));
            $van  = $wpdb->get_col($wpdb->prepare('SELECT kulso FROM ' . SDH_Muhely_Penztar::tabla() . " WHERE kulso IN ({$hely})", $adag)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            foreach (is_array($van) ? $van : [] as $k) {
                $ki[(string) $k] = true;
            }
        }

        return $ki;
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    public static function ajax_feltolt(): void
    {
        self::jog();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $f = $_FILES['fajl'] ?? null;

        if (!is_array($f) || (int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            wp_send_json_error(['uzenet' => 'A fájl nem érkezett meg. Lehet, hogy nagyobb, mint amit a szerver enged (upload_max_filesize).']);
        }

        if ((int) $f['size'] > self::MAX_MERET) {
            wp_send_json_error(['uzenet' => 'A fájl túl nagy (legfeljebb 60 MB).']);
        }

        $nev = strtolower((string) $f['name']);
        $ext = pathinfo($nev, PATHINFO_EXTENSION);

        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            wp_send_json_error(['uzenet' => 'Csak .xlsx vagy .csv fájl tölthető fel. (A régi .xls-t Excelben mentsd el .xlsx-ként.)']);
        }

        $kezdes = microtime(true);

        try {
            $eredmeny = self::ertelmez($ext === 'xlsx' ? self::xlsx_sorok((string) $f['tmp_name']) : self::csv_sorok((string) $f['tmp_name']));
        } catch (Throwable $e) {
            wp_send_json_error(['uzenet' => $e->getMessage()]);
        }

        $sorok = $eredmeny['sorok'];

        if ($sorok === []) {
            wp_send_json_error(['uzenet' => 'Nem találtam tételsort. A táblában a „Dátum" fejlécnek az A oszlopban kell állnia.']);
        }

        $token = wp_generate_password(24, false);
        file_put_contents(self::token_fajl($token), (string) wp_json_encode($eredmeny, JSON_UNESCAPED_UNICODE));

        $bent = self::meglevo_kulcsok(array_column($sorok, 'kulso'));
        $o    = ['kp' => 0.0, 'kartya' => 0.0, 'kifizetes' => 0.0, 'kivet' => 0.0, 'befizetes' => 0.0];
        $napok = [];
        $szemelyek = [];

        foreach ($sorok as $s) {
            $napok[$s['datum']] = true;

            if ($s['tipus'] === 'bevetel') {
                $o['kp'] += $s['kp'];
                $o['kartya'] += $s['kartya'];
            } else {
                $o[$s['tipus']] += $s['kp'];
            }

            if ($s['tipus'] === 'kivet') {
                $szemelyek[$s['szemely']] = ($szemelyek[$s['szemely']] ?? 0) + $s['kp'];
            }
        }

        $datumok = array_keys($napok);
        sort($datumok);

        wp_send_json_success([
            'token'     => $token,
            'fajl'      => sanitize_file_name((string) $f['name']),
            'db'        => count($sorok),
            'uj'        => count($sorok) - count($bent),
            'mar_bent'  => count($bent),
            'napok'     => count($datumok),
            'tol'       => $datumok[0] ?? '',
            'ig'        => end($datumok) ?: '',
            'osszeg'    => array_map(static fn ($v) => round($v, 2), $o),
            'kivet'     => $szemelyek,
            'zarasok'   => count($eredmeny['zarasok']),
            'nyito'     => $eredmeny['nyito'],
            'figyelmeztetes' => $eredmeny['figyelmeztetes'],
            'minta'     => array_merge(array_slice($sorok, 0, 5), array_slice($sorok, -5)),
            'mp'        => round(microtime(true) - $kezdes, 2),
            'van_import' => self::van_import(),
        ]);
    }

    private static function van_import(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Muhely_Penztar::tabla() . " WHERE forras = 'import'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** Az összes importált adat törlése (a CRM-ben rögzített tételek maradnak). */
    private static function import_torol(): string
    {
        global $wpdb;

        $t  = SDH_Muhely_Penztar::tabla();
        $nt = SDH_Muhely_Penztar::nap_tabla();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $elso = (string) $wpdb->get_var("SELECT MIN(datum) FROM {$t} WHERE forras = 'import'");
        $wpdb->query("DELETE FROM {$t} WHERE forras = 'import'");
        $wpdb->query("DELETE FROM {$nt} WHERE cimletek = 'import'");
        // phpcs:enable

        return $elso;
    }

    public static function ajax_lepes(): void
    {
        global $wpdb;

        self::jog();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $token = isset($_POST['token']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_POST['token'])) : '';
        $tol   = isset($_POST['tol']) ? max(0, (int) $_POST['tol']) : 0;
        $csere = !empty($_POST['csere']);
        // phpcs:enable

        $fajl = $token !== '' ? self::token_fajl((string) $token) : '';

        if ($fajl === '' || !is_readable($fajl)) {
            wp_send_json_error(['uzenet' => 'Az import lejárt vagy már lefutott. Töltsd fel újra a fájlt.']);
        }

        $adat  = json_decode((string) file_get_contents($fajl), true);
        $sorok = is_array($adat['sorok'] ?? null) ? $adat['sorok'] : [];

        if ($tol === 0 && $csere) {
            self::import_torol();
        }

        $adag  = array_slice($sorok, $tol, self::ADAG);
        $bent  = self::meglevo_kulcsok(array_column($adag, 'kulso'));
        $most  = current_time('mysql');
        $user  = get_current_user_id();
        $ertek = [];
        $beirva = 0;
        $t     = SDH_Muhely_Penztar::tabla();

        foreach ($adag as $s) {
            if (isset($bent[$s['kulso']])) {
                continue;
            }

            $ertek[] = $wpdb->prepare(
                '(%s, %s, %s, %s, %s, %s, %f, %f, %f, %s, %s, %s, %s, %s, %s, %d, %d, %s, %s)',
                $s['datum'], $s['datum'] . ' 12:00:00', $s['tipus'], $s['leiras'], $s['nev'], $s['szemely'],
                $s['kp'], $s['kartya'], $s['utalas'], $s['ml'], $s['szamla'], $s['vonalkod'], $s['megj'],
                'import', $s['kulso'], (int) $s['sorrend'], $user, $most, $most
            );
            $beirva++;

            if (count($ertek) >= 400) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("INSERT INTO {$t} (datum, ido, tipus, leiras, nev, szemely, kp, kartya, utalas, munkalap_szam, szamlaszam, vonalkod, megjegyzes, forras, kulso, sorrend, felhasznalo, letrehozva, modositva) VALUES " . implode(',', $ertek));
                $ertek = [];
            }
        }

        if ($ertek !== []) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("INSERT INTO {$t} (datum, ido, tipus, leiras, nev, szemely, kp, kartya, utalas, munkalap_szam, szamlaszam, vonalkod, megjegyzes, forras, kulso, sorrend, felhasznalo, letrehozva, modositva) VALUES " . implode(',', $ertek));
        }

        // A sorok után a zárás KÜLÖN kérésben fut (tol = sorok száma), hogy egyik kérés se fusson ki az időből.
        if ($tol < count($sorok)) {
            wp_send_json_success([
                'kesz'      => false,
                'kovetkezo' => min(count($sorok), $tol + self::ADAG),
                'db'        => count($sorok),
                'beirva'    => $beirva,
                'fazis'     => $tol + self::ADAG >= count($sorok) ? 'zaras' : 'sorok',
            ]);
        }

        // Utolsó lépés: kezdő egyenleg, napok lezárása, megszámolt zárások, újraszámolás.
        $elso = (string) $wpdb->get_var("SELECT MIN(datum) FROM {$t} WHERE forras = 'import'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($elso !== '' && (float) ($adat['nyito'] ?? 0) != 0) {
            $kulcs = 'xnyito' . substr(md5((string) $adat['nyito']), 0, 20);

            if (self::meglevo_kulcsok([$kulcs]) === []) {
                $wpdb->insert($t, [
                    'datum' => $elso, 'ido' => $elso . ' 00:00:00', 'tipus' => 'befizetes', 'leiras' => 'Nyitó egyenleg (a régi táblából)',
                    'kp' => round((float) $adat['nyito'], 2), 'forras' => 'import', 'kulso' => $kulcs, 'sorrend' => 0,
                    'felhasznalo' => $user, 'letrehozva' => $most, 'modositva' => $most,
                ]);
            }
        }

        self::napok_lezar($elso, is_array($adat['zarasok'] ?? null) ? $adat['zarasok'] : []);

        @unlink($fajl); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        wp_send_json_success([
            'kesz'   => true,
            'db'     => count($sorok),
            'beirva' => $beirva,
            'osszes' => self::van_import(),
        ]);
    }

    /**
     * Az importált napok: a múltbeliek lezártak (a régi táblában már zárva
     * voltak), a régi tábla „KP a kasszában" összege a nap záró (számolt) összege.
     */
    private static function napok_lezar(string $elso, array $zarasok): void
    {
        global $wpdb;

        if ($elso === '') {
            return;
        }

        $nt   = SDH_Muhely_Penztar::nap_tabla();
        $t    = SDH_Muhely_Penztar::tabla();
        $ma   = SDH_Muhely_Penztar::ma();
        $megj = 'Záró összeg a régi táblából („KP a kasszában").';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $datumok = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT datum FROM {$t} WHERE forras = 'import' AND datum < %s", $ma));

        // Amit a CRM-ben már kezeltek (megszámolták / lezárták), azt a napot az import nem írja felül.
        $kezelt = array_flip(array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT datum FROM {$nt} WHERE datum >= %s AND (lezarta > 0 OR szamolva IS NOT NULL OR (cimletek IS NOT NULL AND cimletek NOT IN ('', 'import')))",
            $elso
        ))));

        // Egyetlen csomagos írás: a nap lezárt, importált, záró összege a régi tábla „KP a kasszában" értéke.
        // (Egy félbemaradt korábbi import „nyitott" napjait is rendbe teszi.)
        $ertekek = [];

        foreach ((array) $datumok as $d) {
            $d = (string) $d;

            if (isset($kezelt[$d])) {
                continue;
            }

            $van       = isset($zarasok[$d]);
            $ertekek[] = $wpdb->prepare('(%s, %s, %s, ', $d, 'lezart', 'import')
                . ($van ? sprintf('%.2F', (float) $zarasok[$d]) : 'NULL')
                . $wpdb->prepare(', %s)', $van ? $megj : '');
        }

        foreach (array_chunk($ertekek, 400) as $csomag) {
            $wpdb->query(
                "INSERT INTO {$nt} (datum, allapot, cimletek, szamolt, megjegyzes) VALUES " . implode(', ', $csomag)
                . ' ON DUPLICATE KEY UPDATE allapot = VALUES(allapot), cimletek = VALUES(cimletek), szamolt = VALUES(szamolt), megjegyzes = VALUES(megjegyzes)'
            );
        }
        // phpcs:enable

        // Egyetlen újraszámolás (csomagokban írja ki a napokat).
        SDH_Muhely_Penztar::ujraszamol($elso);
    }

    public static function ajax_visszavon(): void
    {
        self::jog();

        $elso = self::import_torol();

        if ($elso !== '') {
            SDH_Muhely_Penztar::ujraszamol($elso);
        }

        wp_send_json_success(['kesz' => true]);
    }
}
