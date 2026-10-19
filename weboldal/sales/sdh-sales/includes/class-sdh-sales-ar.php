<?php
/**
 * SDH Értékesítés – árszámítás (ARCHITEKTURA.md 5.1). A szerver ára számít; az assets/front/kalkulator.js
 * `SDHAr.szamol()` ugyanezt a szabályt tükrözi (a t210 teszt ugyanazokat az eseteket PHP-n és node-on is lefuttatja).
 *
 *   szamol(array $tetelek, array $opciok): array          – a katalógusból és a beállításokból (kupon-használattal)
 *   szamol_tiszta(array $kat, array $tetelek, array $opciok): array – tiszta függvény (nincs DB), a JS tükre
 *
 * Tétel: {tipus: 'csomag'|'modul'|'szolgaltatas', kulcs, db?}. Opciók: ciklus ('havi'|'eves'), felhasznalo, telephely,
 * kupon, alap_csomag (bővítésnél a meglévő licenc csomagja), alap_felhasznalo, alap_telephely (bővítésnél a meglévő keret).
 * Eredmény: {sorok[], ciklus, csomag, modulok[], felhasznalo, telephely, max_peldany, netto_lista, kedvezmeny, netto,
 * afa, afa_kulcs, brutto, eves_megtakaritas, kupon, hibak[], figyelmeztetesek[]} – minden összeg egész forint, nettó.
 *
 * Kerekítés (mindkét oldalon azonos): éves ár = havi × 12 × (100 − éves kedvezmény) / 100, félre felfelé, egységenként;
 * százalékos kupon a jogosult nettóra félre felfelé, sorokra a legnagyobb maradék módszerével szétosztva;
 * áfa = nettó × kulcs / 100 félre felfelé; bruttó = nettó + áfa.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Ar
{
    public const MAX_FELHASZNALO = 1000;
    public const MAX_TELEPHELY   = 100;
    public const MAX_DB          = 1000;

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/ar', 'POST', [self::class, 'rest_ar'], 'nyilvanos', ['ar', 120, 60]);
    }

    /**
     * Ár a valódi katalógusból.
     *
     * @param array<int, mixed>    $tetelek
     * @param array<string, mixed> $opciok
     * @return array<string, mixed>
     */
    public static function szamol(array $tetelek, array $opciok = []): array
    {
        $kat = SDH_Sales_Katalogus::kat();
        $kod = strtoupper(trim((string) ($opciok['kupon'] ?? '')));

        if ($kod !== '') {
            $k = SDH_Sales_Katalogus::kupon($kod);

            if ($k !== null) {
                $k['hasznalva']   = self::kupon_hasznalat($kod, (int) ($opciok['_kiveve_rendeles'] ?? 0));
                $kat['kuponok']   = [$kod => $k];
            }
        }

        return self::szamol_tiszta($kat, $tetelek, $opciok);
    }

    /** Hány élő rendelés használta a kupont (törölt / sikertelen nem számít). */
    public static function kupon_hasznalat(string $kod, int $kiveve = 0): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('rendeles') . " WHERE kupon = %s AND allapot NOT IN ('torolve','sikertelen') AND id <> %d",
            strtoupper($kod),
            $kiveve
        ));
    }

    /** Egész kerekítés félre felfelé: a / b (a ≥ 0, b > 0). */
    public static function kerek(int $a, int $b): int
    {
        return intdiv(2 * $a + $b, 2 * $b);
    }

    /** Lebegőpontos érték kerekítése félre felfelé (a JS `Math.floor(x + 0.5)` tükre). */
    private static function kerekf(float $x): int
    {
        return (int) floor($x + 0.5);
    }

    /** Éves egységár: explicit „eves”, különben havi × 12 a kedvezménnyel. */
    public static function eves(int $havi, ?int $explicit, int $kedvezmeny): int
    {
        if ($explicit !== null && $explicit > 0) {
            return $explicit;
        }

        return self::kerek($havi * 12 * (100 - max(0, min(100, $kedvezmeny))), 100);
    }

    private static function ft(int $n): string
    {
        return number_format($n, 0, ',', "\u{00A0}") . "\u{00A0}Ft";
    }

    /** @param mixed $v */
    private static function egesz($v): ?int
    {
        if (is_int($v)) {
            return $v;
        }

        if (is_float($v) && floor($v) === $v) {
            return (int) $v;
        }

        if (is_string($v) && preg_match('/^-?\d{1,9}$/', trim($v)) === 1) {
            return (int) trim($v);
        }

        return null;
    }

    /**
     * A tiszta számítás. $kat: {csomagok:{k:adat}, modulok:{k:adat}, szolgaltatasok:{k:adat}, kuponok?:{KOD:adat+hasznalva},
     * eves_kedvezmeny, afa, ma ('Y-m-d')}.
     *
     * @param array<string, mixed> $kat
     * @param array<int, mixed>    $tetelek
     * @param array<string, mixed> $opciok
     * @return array<string, mixed>
     */
    public static function szamol_tiszta(array $kat, array $tetelek, array $opciok = []): array
    {
        $csomagok = (array) ($kat['csomagok'] ?? []);
        $modulok  = (array) ($kat['modulok'] ?? []);
        $szolg    = (array) ($kat['szolgaltatasok'] ?? []);
        $ek       = (int) ($kat['eves_kedvezmeny'] ?? 0);
        $afa_k    = max(0, min(100, (int) ($kat['afa'] ?? 27)));
        $ma       = (string) ($kat['ma'] ?? gmdate('Y-m-d'));
        $hibak    = [];
        $figy     = [];

        $ciklus = (string) ($opciok['ciklus'] ?? 'eves');

        if (!in_array($ciklus, ['havi', 'eves'], true)) {
            $hibak[] = 'Ismeretlen számlázási ciklus. Válassz havi vagy éves díjat.';
            $ciklus  = 'eves';
        }

        // --- Tételek szétválogatása ---------------------------------------
        $csomag_k = null;
        $mod_k    = [];
        $szolg_db = [];

        foreach ($tetelek as $t) {
            if (!is_array($t)) {
                $hibak[] = 'Érvénytelen tétel a kosárban.';
                continue;
            }

            $tipus = (string) ($t['tipus'] ?? '');
            $k     = (string) ($t['kulcs'] ?? '');

            if ($tipus === 'csomag') {
                if (!isset($csomagok[$k]) || !is_array($csomagok[$k])) {
                    $hibak[] = 'Ismeretlen csomag: ' . $k . '.';
                } elseif ($csomag_k !== null && $csomag_k !== $k) {
                    $hibak[] = 'Egy rendelésben csak egy csomag lehet.';
                } else {
                    $csomag_k = $k;
                }
            } elseif ($tipus === 'modul') {
                if (!isset($modulok[$k]) || !is_array($modulok[$k])) {
                    $hibak[] = 'Ismeretlen modul: ' . $k . '.';
                } elseif (in_array($k, $mod_k, true)) {
                    $figy[] = 'A(z) ' . (string) ($modulok[$k]['nev'] ?? $k) . ' modul kétszer szerepelt, egyszer számoltuk.';
                } else {
                    $mod_k[] = $k;
                }
            } elseif ($tipus === 'szolgaltatas') {
                $db = self::egesz($t['db'] ?? 1);

                if (!isset($szolg[$k]) || !is_array($szolg[$k])) {
                    $hibak[] = 'Ismeretlen szolgáltatás: ' . $k . '.';
                } elseif ($db === null || $db < 1 || $db > self::MAX_DB) {
                    $hibak[] = 'Érvénytelen mennyiség: ' . (string) ($szolg[$k]['nev'] ?? $k) . '.';
                } else {
                    $szolg_db[$k] = ($szolg_db[$k] ?? 0) + $db;
                }
            } else {
                $hibak[] = 'Ismeretlen tételtípus a kosárban.';
            }
        }

        $alap_csomag = $csomag_k ?? (string) ($opciok['alap_csomag'] ?? '');
        $alap_c      = $alap_csomag !== '' && isset($csomagok[$alap_csomag]) && is_array($csomagok[$alap_csomag]) ? $csomagok[$alap_csomag] : null;

        if ($csomag_k === null && $alap_csomag !== '' && $alap_c === null) {
            $hibak[] = 'Ismeretlen csomag: ' . $alap_csomag . '.';
        }

        if ($mod_k !== [] && $alap_c === null) {
            $hibak[] = 'Kiegészítő modul csak csomaggal együtt rendelhető.';
        }

        if ($csomag_k === null && $mod_k === [] && $szolg_db === [] && $hibak === []) {
            $hibak[] = 'A kosár üres.';
        }

        // --- A csomag moduljai --------------------------------------------
        $benne = [];

        if ($alap_c !== null) {
            $benne = array_values(array_map('strval', (array) ($alap_c['modulok'] ?? [])));

            foreach ($modulok as $mk => $m) {
                if (is_array($m) && in_array($alap_csomag, array_map('strval', (array) ($m['csomagban'] ?? [])), true) && !in_array((string) $mk, $benne, true)) {
                    $benne[] = (string) $mk;
                }
            }
        }

        $minden = in_array('*', $benne, true);

        // --- Sorok ----------------------------------------------------------
        $sorok = [];
        $megt  = 0;
        $ar    = static function (array $e, string $havi_mezo, string $eves_mezo) use ($ciklus, $ek, &$megt): array {
            $havi = max(0, (int) ($e[$havi_mezo] ?? 0));
            $ex   = isset($e[$eves_mezo]) && $e[$eves_mezo] !== null ? (int) $e[$eves_mezo] : null;

            if ($ciklus === 'havi') {
                return [$havi, 'havi'];
            }

            $ev    = self::eves($havi, $ex, $ek);
            $megt += max(0, $havi * 12 - $ev);

            return [$ev, 'eves'];
        };

        $felh = null;
        $telh = null;
        $maxp = 0;

        if ($alap_c !== null) {
            $f_alap = max(1, (int) ($alap_c['felhasznalo_alap'] ?? 1));
            $t_alap = max(1, (int) ($alap_c['telephely_alap'] ?? 1));
            $maxp   = max(0, (int) ($alap_c['max_peldany'] ?? 1));

            $felh = array_key_exists('felhasznalo', $opciok) && $opciok['felhasznalo'] !== null && $opciok['felhasznalo'] !== '' ? self::egesz($opciok['felhasznalo']) : $f_alap;
            $telh = array_key_exists('telephely', $opciok) && $opciok['telephely'] !== null && $opciok['telephely'] !== '' ? self::egesz($opciok['telephely']) : $t_alap;

            if ($felh === null || $felh < 1 || $felh > self::MAX_FELHASZNALO) {
                $hibak[] = 'A felhasználók száma 1 és ' . self::MAX_FELHASZNALO . ' között lehet.';
                $felh    = $f_alap;
            }

            if ($telh === null || $telh < 1 || $telh > self::MAX_TELEPHELY) {
                $hibak[] = 'A telephelyek száma 1 és ' . self::MAX_TELEPHELY . ' között lehet.';
                $telh    = $t_alap;
            }

            $cnev = (string) ($alap_c['nev'] ?? $alap_csomag);

            foreach ([['felhasznalo', $felh, $f_alap, 'felhasználó'], ['telephely', $telh, $t_alap, 'telephely']] as [$mezo, $kert, $alap, $szo]) {
                $max = isset($alap_c[$mezo . '_max']) ? (int) $alap_c[$mezo . '_max'] : 0;

                if ($max > 0 && $kert > $max) {
                    $hibak[] = 'A(z) ' . $cnev . ' csomagban legfeljebb ' . $max . ' ' . $szo . ' lehet. Válassz nagyobb csomagot.';
                } elseif ($kert > $alap && (!array_key_exists($mezo . '_extra_havi', $alap_c) || $alap_c[$mezo . '_extra_havi'] === null)) {
                    $hibak[] = 'A(z) ' . $cnev . ' csomagban legfeljebb ' . $alap . ' ' . $szo . ' lehet. Válassz nagyobb csomagot.';
                }
            }

            if ($csomag_k !== null) {
                [$egys, $c] = $ar($alap_c, 'havi', 'eves');
                $sorok[]    = ['tipus' => 'csomag', 'kulcs' => $csomag_k, 'nev' => $cnev . ' csomag', 'mennyiseg' => 1, 'egysegar' => $egys, 'netto_lista' => $egys, 'ciklus' => $c];
                $f_kezd     = $f_alap;
                $t_kezd     = $t_alap;
            } else {
                // Bővítés: a meglévő keret fölötti rész.
                $f_kezd = max($f_alap, (int) ($opciok['alap_felhasznalo'] ?? $f_alap));
                $t_kezd = max($t_alap, (int) ($opciok['alap_telephely'] ?? $t_alap));
            }

            foreach ([['felhasznalo', $felh, $f_kezd, 'További felhasználó'], ['telephely', $telh, $t_kezd, 'További telephely']] as [$mezo, $kert, $kezd, $cimke]) {
                $extra = $kert - $kezd;

                if ($extra > 0 && array_key_exists($mezo . '_extra_havi', $alap_c) && $alap_c[$mezo . '_extra_havi'] !== null) {
                    [$egys, $c] = $ar(['h' => (int) $alap_c[$mezo . '_extra_havi'], 'e' => $alap_c[$mezo . '_extra_eves'] ?? null], 'h', 'e');
                    $megt      += $c === 'eves' ? max(0, ((int) $alap_c[$mezo . '_extra_havi'] * 12 - $egys)) * ($extra - 1) : 0;
                    $sorok[]    = ['tipus' => $mezo, 'kulcs' => $alap_csomag, 'nev' => $cimke . ' (' . $cnev . ')', 'mennyiseg' => $extra, 'egysegar' => $egys, 'netto_lista' => $egys * $extra, 'ciklus' => $c];
                }
            }
        }

        foreach ($mod_k as $mk) {
            $m = (array) $modulok[$mk];

            if ($minden || in_array($mk, $benne, true)) {
                $figy[] = 'A(z) ' . (string) ($m['nev'] ?? $mk) . ' modul már benne van a csomagban, nem számoltuk fel.';
                continue;
            }

            [$egys, $c] = $ar($m, 'havi', 'eves');
            $sorok[]    = ['tipus' => 'modul', 'kulcs' => $mk, 'nev' => (string) ($m['nev'] ?? $mk) . ' modul', 'mennyiseg' => 1, 'egysegar' => $egys, 'netto_lista' => $egys, 'ciklus' => $c];
        }

        foreach ($szolg_db as $sk => $db) {
            $s  = (array) $szolg[$sk];
            $st = (string) ($s['tipus'] ?? 'egyszeri');
            $a  = max(0, (int) ($s['ar'] ?? 0));

            if ($st === 'havi') {
                [$egys, $c] = $ar(['h' => $a, 'e' => $s['eves'] ?? null], 'h', 'e');
                $megt      += $c === 'eves' ? max(0, $a * 12 - $egys) * ($db - 1) : 0;
            } elseif ($st === 'eves') {
                [$egys, $c] = [$a, 'eves'];
            } else {
                [$egys, $c] = [$a, 'egyszeri'];
            }

            $sorok[] = ['tipus' => 'szolgaltatas', 'kulcs' => $sk, 'nev' => (string) ($s['nev'] ?? $sk), 'mennyiseg' => $db, 'egysegar' => $egys, 'netto_lista' => $egys * $db, 'ciklus' => $c];
        }

        $lista = 0;

        foreach ($sorok as $i => $s) {
            $sorok[$i]['kedvezmeny'] = 0;
            $sorok[$i]['netto']      = $s['netto_lista'];
            $lista                  += $s['netto_lista'];
        }

        // --- Kupon ----------------------------------------------------------
        $kod        = strtoupper(trim((string) ($opciok['kupon'] ?? '')));
        $kupon_ki   = null;
        $kedvezmeny = 0;

        if ($kod !== '') {
            $kp = ((array) ($kat['kuponok'] ?? []))[$kod] ?? null;
            $kh = '';

            if (!is_array($kp) || (array_key_exists('aktiv', $kp) && !$kp['aktiv'])) {
                $kh = 'Ismeretlen kuponkód: ' . $kod . '.';
            } elseif (!empty($kp['lejar']) && strcmp($ma, substr((string) $kp['lejar'], 0, 10)) > 0) {
                $kh = 'A(z) ' . $kod . ' kupon lejárt.';
            } elseif (!empty($kp['kezd']) && strcmp($ma, substr((string) $kp['kezd'], 0, 10)) < 0) {
                $kh = 'A(z) ' . $kod . ' kupon még nem érvényes.';
            } elseif (isset($kp['max']) && $kp['max'] !== null && (int) $kp['max'] > 0 && (int) ($kp['hasznalva'] ?? 0) >= (int) $kp['max']) {
                $kh = 'A(z) ' . $kod . ' kupon elfogyott.';
            } elseif (!empty($kp['ciklus']) && (string) $kp['ciklus'] !== $ciklus) {
                $kh = 'A(z) ' . $kod . ' kupon csak ' . ((string) $kp['ciklus'] === 'eves' ? 'éves' : 'havi') . ' díjjal használható.';
            } elseif ((int) ($kp['min'] ?? 0) > 0 && $lista < (int) $kp['min']) {
                $kh = 'A(z) ' . $kod . ' kupon legalább ' . self::ft((int) $kp['min']) . ' nettó rendelési értéktől használható.';
            }

            $jogos = [];

            if ($kh === '') {
                $hat = $kp['hatokor'] ?? 'mind';

                foreach ($sorok as $i => $s) {
                    $fo = in_array($s['tipus'], ['felhasznalo', 'telephely'], true) ? 'csomag' : $s['tipus'];

                    if ($hat === 'mind' || $hat === '' || $hat === null
                        || (is_string($hat) && $hat === $fo)
                        || (is_array($hat) && (in_array($s['kulcs'], array_map('strval', $hat), true) || in_array($fo, array_map('strval', $hat), true)))) {
                        if ($s['netto_lista'] > 0) {
                            $jogos[] = $i;
                        }
                    }
                }

                if ($jogos === []) {
                    $kh = 'A(z) ' . $kod . ' kupon erre a kosárra nem érvényes.';
                }
            }

            if ($kh !== '') {
                $hibak[] = $kh;
            } else {
                $E = 0;

                foreach ($jogos as $i) {
                    $E += $sorok[$i]['netto_lista'];
                }

                $tip = (string) ($kp['tipus'] ?? 'szazalek');
                $ert = (float) ($kp['ertek'] ?? 0);

                if ($tip === 'fix' || $tip === 'osszeg') {
                    $D = min($E, max(0, (int) $ert));
                } else {
                    $D = min($E, max(0, self::kerekf($E * max(0.0, min(100.0, $ert)) / 100)));
                }

                // Legnagyobb maradék: a sorok kedvezménye pontosan D.
                $resz = [];
                $ossz = 0;

                foreach ($jogos as $i) {
                    $sz       = $D * $sorok[$i]['netto_lista'];
                    $alap     = intdiv($sz, $E);
                    $resz[$i] = [$alap, $sz % $E];
                    $ossz    += $alap;
                }

                $maradt = $D - $ossz;
                $sorr   = $jogos;
                usort($sorr, static fn (int $a, int $b): int => $resz[$b][1] <=> $resz[$a][1] ?: $a <=> $b);

                foreach ($sorr as $n => $i) {
                    $k                       = $resz[$i][0] + ($n < $maradt ? 1 : 0);
                    $sorok[$i]['kedvezmeny'] = $k;
                    $sorok[$i]['netto']      = $sorok[$i]['netto_lista'] - $k;
                }

                $kedvezmeny = $D;
                $kupon_ki   = ['kod' => $kod, 'tipus' => ($tip === 'osszeg' ? 'fix' : $tip), 'ertek' => $tip === 'fix' || $tip === 'osszeg' ? (int) $ert : $ert];
            }
        }

        // --- Összesítés ------------------------------------------------------
        $netto = $lista - $kedvezmeny;
        $afa   = self::kerek($netto * $afa_k, 100);
        $van_ism = false;

        foreach ($sorok as $s) {
            if ($s['ciklus'] !== 'egyszeri') {
                $van_ism = true;
            }
        }

        $licenc_mod = $alap_c === null ? [] : ($minden ? ['*'] : array_values(array_unique(array_merge($benne, $mod_k))));

        return [
            'sorok'             => array_values($sorok),
            'ciklus'            => $van_ism ? $ciklus : 'egyszeri',
            'csomag'            => $csomag_k,
            'modulok'           => $licenc_mod,
            'felhasznalo'       => $felh,
            'telephely'         => $telh,
            'max_peldany'       => $maxp,
            'netto_lista'       => $lista,
            'kedvezmeny'        => $kedvezmeny,
            'netto'             => $netto,
            'afa'               => $afa,
            'afa_kulcs'         => $afa_k,
            'brutto'            => $netto + $afa,
            'eves_megtakaritas' => $ciklus === 'eves' ? $megt : 0,
            'kupon'             => $kupon_ki,
            'hibak'             => array_values(array_unique($hibak)),
            'figyelmeztetesek'  => array_values(array_unique($figy)),
        ];
    }

    /**
     * A beérkező kosár tisztítása (REST): legfeljebb 60 tétel, csak ismert mezők.
     *
     * @param mixed $be
     * @return array<int, array<string, mixed>>
     */
    public static function tetelek_tisztit($be): array
    {
        $ki = [];

        foreach (array_slice(is_array($be) ? array_values($be) : [], 0, 60) as $t) {
            if (!is_array($t)) {
                continue;
            }

            $ki[] = [
                'tipus' => sanitize_key((string) ($t['tipus'] ?? '')),
                'kulcs' => mb_substr(sanitize_text_field((string) ($t['kulcs'] ?? '')), 0, 60),
                'db'    => isset($t['db']) ? (int) $t['db'] : 1,
            ];
        }

        return $ki;
    }

    /**
     * @param mixed $be
     * @return array<string, mixed>
     */
    public static function opciok_tisztit($be): array
    {
        $be = is_array($be) ? $be : [];
        $ki = ['ciklus' => sanitize_key((string) ($be['ciklus'] ?? 'eves'))];

        foreach (['felhasznalo', 'telephely'] as $k) {
            if (isset($be[$k]) && $be[$k] !== '') {
                $ki[$k] = is_numeric($be[$k]) ? (int) $be[$k] : -1;
            }
        }

        if (!empty($be['kupon'])) {
            $ki['kupon'] = mb_substr(strtoupper(sanitize_text_field((string) $be['kupon'])), 0, 40);
        }

        return $ki;
    }

    /** POST /ar – nyilvános, korlátozott. Törzs: {tetelek[], opciok{}}. */
    public static function rest_ar(WP_REST_Request $r): WP_REST_Response
    {
        $e = self::szamol(self::tetelek_tisztit($r->get_param('tetelek')), self::opciok_tisztit($r->get_param('opciok')));

        return new WP_REST_Response($e, 200);
    }
}
