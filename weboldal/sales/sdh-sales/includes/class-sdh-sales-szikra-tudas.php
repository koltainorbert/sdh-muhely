<?php
/**
 * SDH Értékesítés – Szikra tudásbázisa: magyar szövegnormalizálás (ékezet, toldalék), BM25-keresés.
 *
 * Források:
 *   - statikus: data/tudas/*.json (építő: weboldal/eszkozok/tudas-epit.php a Műhely sugo/ mappájából, fejlesztői részek nélkül)
 *   - futásidőben: a tartalom-tár `gyik`, `csomag`, `modul`, `szolgaltatas`, `funkcio`, `iparag` elemei (árak a tárból).
 *
 * API:
 *   SDH_Sales_Szikra_Tudas::keres(string $kerdes, int $k = 6): [ {id, cim, url, szoveg, forras, pont}, … ]
 *   SDH_Sales_Szikra_Tudas::szavak(string $s): string[]      – normalizált, tövezett szavak (ismétlődéssel)
 *   SDH_Sales_Szikra_Tudas::fejezetek(): [ {kulcs, cim, csoport, osszefoglalo, darab, url} ]  (téma / kereső)
 *   SDH_Sales_Szikra_Tudas::fejezet(string $kulcs): ?array    – egy fejezet darabjai (tudásbázis-oldalhoz)
 *
 * A szövegfeldolgozó rész (normal, szavak, to) WordPress nélkül is fut – az építő szkript is ezt használja,
 * így a tárolt tokenek és a kérdés tokenjei garantáltan ugyanúgy készülnek.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Szikra_Tudas
{
    public const K1 = 1.2;
    public const B  = 0.75;

    /** A tartalom-tár típusai, amelyek a tudásba kerülnek. */
    public const TIPUSOK = ['gyik', 'csomag', 'modul', 'szolgaltatas', 'funkcio', 'iparag'];

    private const EKEZET = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
        'ô' => 'o', 'õ' => 'o', 'û' => 'u', 'ũ' => 'u', 'ä' => 'a', 'à' => 'a', 'è' => 'e', 'ë' => 'e', 'ï' => 'i',
        'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss',
    ];

    /** Töltelékszavak (ékezet nélkül). */
    private const TOLTELEK = 'a az egy es is hogy mi mit van nem meg de ha ez azt mint vagy kell lehet milyen hogyan miert mikor hol ki '
        . 'mely melyik mar csak most itt ott igen sem ezt arra erre amit ami aki akkor mert mennyi tudod tudsz kerem szia hello koszi '
        . 'koszonom nekem engem en te ti ok vagyok lesz volt the and or of to in for is are what how can do does with on at be it '
        . 'el fel ra be ki le at at ezek azok ilyen olyan nagyon mar meg minden mindig sok kicsit valami barmi';

    /** Toldalékok (ékezet nélkül) – a leghosszabb előre kerül futáskor. */
    private const TOLDALEK = [
        'jaitok', 'jeitek', 'ainak', 'einek', 'jainak', 'jeinek', 'okkal', 'ekkel', 'akkal', 'okban', 'ekben', 'akban', 'oknak',
        'eknek', 'aknak', 'okrol', 'ekrol', 'akrol', 'okhoz', 'ekhez', 'akhoz', 'okbol', 'ekbol', 'akbol', 'oktol', 'ektol',
        'aktol', 'okon', 'eken', 'akon', 'okat', 'eket', 'akat', 'otok', 'etek', 'jaik', 'jeik', 'unk', 'ink', 'juk', 'ait',
        'eit', 'val', 'vel', 'ban', 'ben', 'bol', 'rol', 'tol', 'nak', 'nek', 'hoz', 'hez', 'kor', 'ert', 'ig', 'ra', 're',
        'ba', 'be', 'on', 'en', 'ot', 'et', 'at', 'ok', 'ek', 'ak', 'ja', 'je', 'uk', 'ai', 'ei', 'om', 'em', 'am', 'od',
        'ed', 'ad', 'as', 'es', 'ni', 't', 'k',
    ];

    /** Rokon szavak és kivételek (tövezés előtt): a köznyelvi alak → a súgó szava. */
    private const ROKON = [
        'ar' => 'ar', 'ara' => 'ar', 'arak' => 'ar', 'arat' => 'ar', 'arai' => 'ar', 'arakat' => 'ar', 'arban' => 'ar', 'aron' => 'ar',
        'kassza' => 'penztar', 'kasszat' => 'penztar', 'kasszazar' => 'penztarzar', 'vevo' => 'ugyfel', 'vevok' => 'ugyfel',
        'vevot' => 'ugyfel', 'kliens' => 'ugyfel', 'customer' => 'ugyfel', 'telefonom' => 'telefon', 'keszulek' => 'eszkoz',
        'keszuleket' => 'eszkoz', 'keszulekek' => 'eszkoz', 'szamlat' => 'szamla', 'szamlak' => 'szamla', 'invoice' => 'szamla',
        'price' => 'ar', 'prices' => 'ar', 'pricing' => 'ar', 'package' => 'csomag', 'packages' => 'csomag', 'backup' => 'mentes',
        'dij' => 'dij', 'dija' => 'dij', 'dijat' => 'dij', 'dijak' => 'dij', 'mennyibe' => 'ar', 'kerul' => 'ar', 'koltseg' => 'ar',
        'felhasznalo' => 'felhasznalo', 'felhasznalok' => 'felhasznalo', 'felhasznalot' => 'felhasznalo', 'user' => 'felhasznalo',
        'users' => 'felhasznalo', 'munkalap' => 'munkalap', 'workorder' => 'munkalap',
    ];

    /** @var array<int, string>|null */
    private static ?array $toldalek = null;

    /** @var array<string, bool>|null */
    private static ?array $toltelek = null;

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $statikus = null;

    /* =================================================================
     * Szövegfeldolgozás (WordPress nélkül is)
     * ============================================================== */

    /** Kisbetű + ékezet nélkül. */
    public static function normal(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');

        return strtr($s, self::EKEZET);
    }

    /** Egyszerű magyar tövezés: a gyakori toldalékok levágása (legfeljebb két kör), legalább 3 betűs tő marad. */
    public static function to(string $w): string
    {
        if (isset(self::ROKON[$w])) {
            return self::ROKON[$w];
        }

        if (self::$toldalek === null) {
            $t = self::TOLDALEK;
            usort($t, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            self::$toldalek = $t;
        }

        if (ctype_digit($w)) {
            return $w;
        }

        for ($kor = 0; $kor < 2; $kor++) {
            $volt = false;

            foreach (self::$toldalek as $t) {
                $lt = strlen($t);

                if (strlen($w) - $lt >= 3 && substr($w, -$lt) === $t) {
                    $w    = substr($w, 0, -$lt);
                    $volt = true;
                    break;
                }
            }

            if (!$volt) {
                break;
            }

            if (isset(self::ROKON[$w])) {
                return self::ROKON[$w];
            }
        }

        return $w;
    }

    /**
     * Normalizált, tövezett szavak (ismétlődéssel, a sorrend megmarad).
     *
     * @return array<int, string>
     */
    public static function szavak(string $s): array
    {
        if (self::$toltelek === null) {
            self::$toltelek = array_fill_keys(explode(' ', self::TOLTELEK), true);
        }

        $s  = self::normal(strip_tags(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $ki = [];

        foreach (preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            if (strlen($w) < 2 || isset(self::$toltelek[$w]) || strlen($w) > 40) {
                continue;
            }

            $ki[] = self::to($w);
        }

        return $ki;
    }

    /** Horgony (URL-részlet) egy címből: ékezet nélkül, kötőjelekkel. */
    public static function horgony(string $cim): string
    {
        $h = preg_replace('/[^a-z0-9]+/', '-', self::normal($cim)) ?? '';

        return trim(substr($h, 0, 80), '-');
    }

    /* =================================================================
     * Források
     * ============================================================== */

    public static function mappa(): string
    {
        return SDH_SALES_DIR . 'data/tudas/';
    }

    /** @return array<string, mixed> az építő által írt _index.json */
    public static function index(): array
    {
        $f = self::mappa() . '_index.json';
        $a = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;

        return is_array($a) ? $a : [];
    }

    /** A súgó-alapú tudás egy fejezetének URL-je (a beállítás mintája szerint). */
    public static function url(string $fejezet, string $horgony = ''): string
    {
        $minta = (string) SDH_Sales::b('szikra.tudas_url', SDH_Sales_Szikra::ALAP['szikra.tudas_url']);
        $ut    = str_replace('{fejezet}', rawurlencode($fejezet), $minta);
        $url   = preg_match('#^https?://#', $ut) ? $ut : home_url('/' . ltrim($ut, '/'));

        return (string) apply_filters('sdh_sales_szikra_tudas_url', $url . ($horgony !== '' ? '#' . $horgony : ''), $fejezet, $horgony);
    }

    /**
     * A statikus darabok (minden fejezet), kérésenként egyszer betöltve.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function statikus(): array
    {
        if (self::$statikus !== null) {
            return self::$statikus;
        }

        $ki = [];

        foreach (glob(self::mappa() . '*.json') ?: [] as $f) {
            if (basename($f)[0] === '_') {
                continue;
            }

            $a = json_decode((string) file_get_contents($f), true);

            if (!is_array($a) || !is_array($a['darabok'] ?? null)) {
                continue;
            }

            foreach ($a['darabok'] as $d) {
                if (!is_array($d) || empty($d['t'])) {
                    continue;
                }

                $ki[] = [
                    'id'      => (string) $d['id'],
                    'cim'     => (string) ($a['cim'] ?? '') . ' › ' . (string) ($d['cim'] ?? ''),
                    'fejezet' => (string) ($a['kulcs'] ?? ''),
                    'horgony' => (string) ($d['horgony'] ?? ''),
                    'szoveg'  => (string) ($d['szoveg'] ?? ''),
                    't'       => ' ' . (string) $d['t'] . ' ',
                    'h'       => max(1, (int) ($d['h'] ?? 1)),
                    'forras'  => 'sugo',
                ];
            }
        }

        self::$statikus = $ki;

        return $ki;
    }

    public static function urit(): void
    {
        self::$statikus = null;
        delete_transient('sdh_sales_szikra_dinamikus');
    }

    /** @return array<int, array<string, mixed>> */
    public static function fejezetek(): array
    {
        $ki = [];

        foreach ((array) (self::index()['fejezetek'] ?? []) as $f) {
            if (is_array($f) && !empty($f['kulcs'])) {
                $ki[] = $f + ['url' => self::url((string) $f['kulcs'])];
            }
        }

        return $ki;
    }

    /** @return array<string, mixed>|null */
    public static function fejezet(string $kulcs): ?array
    {
        foreach (glob(self::mappa() . '*.json') ?: [] as $f) {
            if (basename($f)[0] === '_') {
                continue;
            }

            $a = json_decode((string) file_get_contents($f), true);

            if (is_array($a) && ($a['kulcs'] ?? '') === $kulcs) {
                foreach ($a['darabok'] as $i => $d) {
                    unset($a['darabok'][$i]['t'], $a['darabok'][$i]['h']);
                    $a['darabok'][$i]['szoveg'] = SDH_Sales::t((string) $d['szoveg']);
                }

                return SDH_Sales::t_tomb($a);
            }
        }

        return null;
    }

    /**
     * A tartalom-tár elemei kereshető darabként (GYIK, csomagok árakkal, modulok, szolgáltatások, funkciók, iparágak).
     * A gyorsítótár kulcsa a tartalom lenyomata (+ áfa, terméknév, kedvezmény, webcím) – bármilyen változásra magától frissül.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function dinamikus(): array
    {
        $listak = [];

        foreach (self::TIPUSOK as $tipus) {
            $listak[$tipus] = SDH_Sales_Tartalom::lista($tipus);
        }

        $ver = md5((string) wp_json_encode($listak) . '|' . SDH_Sales::afa() . '|' . SDH_Sales::termeknev() . '|' . (string) SDH_Sales::b('eves_kedvezmeny', 0) . '|' . home_url());
        $c   = get_transient('sdh_sales_szikra_dinamikus');

        if (is_array($c) && ($c['v'] ?? '') === $ver && is_array($c['d'] ?? null)) {
            return $c['d'];
        }

        $ki = [];

        foreach ($listak as $tipus => $lista) {
            foreach ($lista as $e) {
                $d = self::elem_darab($tipus, $e);

                if ($d !== null) {
                    $ki[] = $d;
                }
            }
        }

        set_transient('sdh_sales_szikra_dinamikus', ['v' => $ver, 'd' => $ki], HOUR_IN_SECONDS);

        return $ki;
    }

    /** Egy ár olvasható alakban (nettó + bruttó), vagy null, ha nincs megadva. */
    public static function ar_szoveg(int $netto, string $utotag = ''): string
    {
        return SDH_Sales::ft($netto) . ' + áfa (bruttó ' . SDH_Sales::ft(SDH_Sales::brutto($netto)) . ')' . $utotag;
    }

    /**
     * @param array<string, mixed> $e tartalom-elem
     * @return array<string, mixed>|null
     */
    private static function elem_darab(string $tipus, array $e): ?array
    {
        $a     = (array) $e['adat'];
        $kulcs = (string) $e['kulcs'];
        $cim   = SDH_Sales::t((string) ($a['nev'] ?? $a['kerdes'] ?? $a['cim'] ?? $e['cim']));
        $sor   = [];
        $url   = '';

        switch ($tipus) {
            case 'gyik':
                $sor[] = 'Kérdés: ' . $cim;
                $sor[] = 'Válasz: ' . self::sima((string) ($a['valasz'] ?? ''));
                $url   = self::oldal_url((string) SDH_Sales::b('szikra.gyik_url', SDH_Sales_Szikra::ALAP['szikra.gyik_url'])) . '#' . sanitize_title($kulcs);
                $cim   = 'GYIK › ' . $cim;
                break;

            case 'csomag':
                $sor[] = 'Csomag: ' . $cim . ' (kulcs: ' . $kulcs . ')';
                $sor[] = self::sima((string) ($a['leiras'] ?? ''));
                $havi  = (int) ($a['havi'] ?? 0);
                $eves  = (int) ($a['eves'] ?? 0);

                if ($havi > 0 || $eves > 0) {
                    $sor[] = 'Ár: ' . ($havi > 0 ? self::ar_szoveg($havi, ' / hó') : '') . ($havi > 0 && $eves > 0 ? '; ' : '') . ($eves > 0 ? self::ar_szoveg($eves, ' / év') : '') . '.';
                } else {
                    $sor[] = 'Ár: nincs megadva a webhelyen – pontos árat a csomag_ajanlo eszköz vagy egy kolléga ad.';
                }

                $sor[] = 'Benne foglalt felhasználók: ' . (int) ($a['felhasznalo_alap'] ?? 0) . ((int) ($a['felhasznalo_extra_havi'] ?? 0) > 0 ? ', további felhasználó: ' . self::ar_szoveg((int) $a['felhasznalo_extra_havi'], ' / hó') : '') . '.';
                $sor[] = 'Benne foglalt telephelyek: ' . (int) ($a['telephely_alap'] ?? 0) . ((int) ($a['telephely_extra_havi'] ?? 0) > 0 ? ', további telephely: ' . self::ar_szoveg((int) $a['telephely_extra_havi'], ' / hó') : '') . '.';
                $mod   = $a['modulok'] ?? [];
                $sor[] = 'Modulok: ' . (is_array($mod) ? implode(', ', array_map('strval', $mod)) : (string) $mod) . '.';
                $url   = self::oldal_url((string) SDH_Sales::b('szikra.arak_url', SDH_Sales_Szikra::ALAP['szikra.arak_url'])) . '#csomag-' . sanitize_title($kulcs);
                $cim   = 'Csomag › ' . $cim;
                break;

            case 'modul':
                $sor[] = 'Modul: ' . $cim . ' (kulcs: ' . $kulcs . ')' . (!empty($a['csoport']) ? ', csoport: ' . (string) $a['csoport'] : '');
                $sor[] = self::sima((string) ($a['leiras'] ?? ''));
                $cs    = (array) ($a['csomagban'] ?? []);

                if ($cs !== []) {
                    $sor[] = 'Ezekben a csomagokban benne van: ' . implode(', ', array_map('strval', $cs)) . '.';
                }

                if ((int) ($a['havi'] ?? 0) > 0) {
                    $sor[] = 'Külön megvéve: ' . self::ar_szoveg((int) $a['havi'], ' / hó') . '.';
                }

                $url = self::oldal_url((string) SDH_Sales::b('szikra.arak_url', SDH_Sales_Szikra::ALAP['szikra.arak_url'])) . '#modul-' . sanitize_title($kulcs);
                $cim = 'Modul › ' . $cim;
                break;

            case 'szolgaltatas':
                $sor[] = 'Szolgáltatás: ' . $cim . ' (' . (string) ($a['tipus'] ?? '') . ')';
                $sor[] = self::sima((string) ($a['leiras'] ?? ''));

                if ((int) ($a['ar'] ?? 0) > 0) {
                    $sor[] = 'Ár: ' . self::ar_szoveg((int) $a['ar'], ($a['tipus'] ?? '') === 'havi' ? ' / hó' : '') . '.';
                }

                if (is_array($a['sla'] ?? null)) {
                    $sor[] = 'Válaszidő: ' . (int) ($a['sla']['valaszido_ora'] ?? 0) . ' óra, munkaidő: ' . (string) ($a['sla']['munkaido'] ?? '') . '.';
                }

                $url = self::oldal_url((string) SDH_Sales::b('szikra.arak_url', SDH_Sales_Szikra::ALAP['szikra.arak_url'])) . '#szolgaltatas-' . sanitize_title($kulcs);
                $cim = 'Szolgáltatás › ' . $cim;
                break;

            default: // funkcio, iparag
                $sor[] = $cim;
                $sor[] = self::lapit($a, 2400);
                $url   = self::oldal_url(($tipus === 'funkcio' ? 'funkciok/' : 'iparagak/') . sanitize_title($kulcs) . '/');
                $cim   = ($tipus === 'funkcio' ? 'Funkció › ' : 'Iparág › ') . $cim;
                break;
        }

        $szoveg = trim(SDH_Sales::t(implode("\n", array_filter($sor, static fn ($s) => trim((string) $s) !== ''))));

        if ($szoveg === '') {
            return null;
        }

        $t = self::szavak($cim . ' ' . $cim . ' ' . $szoveg);

        return [
            'id'     => $tipus . ':' . $kulcs,
            'cim'    => $cim,
            'url'    => (string) apply_filters('sdh_sales_szikra_url', $url, $tipus, $kulcs),
            'szoveg' => $szoveg,
            't'      => ' ' . implode(' ', $t) . ' ',
            'h'      => max(1, count($t)),
            'forras' => $tipus,
        ];
    }

    /** Oldal-URL egy útból: ha van ilyen oldal, a permalinkje; különben a home_url alatti út. */
    public static function oldal_url(string $ut): string
    {
        if (preg_match('#^https?://#', $ut)) {
            return $ut;
        }

        $ut = trim($ut, '/');
        $p  = $ut !== '' ? get_page_by_path($ut) : null;

        return $p ? (string) get_permalink($p) : home_url('/' . ($ut !== '' ? $ut . '/' : ''));
    }

    /** HTML → sima szöveg (listapontokkal). */
    public static function sima(string $html): string
    {
        $html = preg_replace('#<li[^>]*>#i', "\n- ", $html) ?? $html;
        $html = preg_replace('#</(p|div|h[1-6]|ul|ol)>#i', "\n", $html) ?? $html;
        $s    = html_entity_decode(wp_strip_all_tags($html, false), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $s) ?? $s) ?? $s);
    }

    /** Egy tömb szöveges mezői egymás után (funkció / iparág adataihoz, ismeretlen sémára is). @param mixed $a */
    private static function lapit($a, int $max): string
    {
        $ki  = [];
        $fel = static function ($v, string $k = '') use (&$fel, &$ki): void {
            if (is_string($v)) {
                if ($v !== '' && !preg_match('#^(https?://|/|\#|[a-z0-9_-]+$)#i', $v) && !in_array($k, ['kulcs', 'ikon', 'kep', 'url', 'slug', 'szulo', 'sablon', 'tipus'], true)) {
                    $ki[] = self::sima($v);
                }
            } elseif (is_array($v)) {
                foreach ($v as $kk => $vv) {
                    $fel($vv, is_string($kk) ? $kk : $k);
                }
            }
        };
        $fel($a);

        return mb_substr(implode("\n", array_unique($ki)), 0, $max);
    }

    /* =================================================================
     * Keresés (BM25)
     * ============================================================== */

    /**
     * @param array<int, array<string, mixed>>|null $dokumentumok teszthez / más forráshoz; null = statikus + dinamikus
     * @return array<int, array{id: string, cim: string, url: string, szoveg: string, forras: string, pont: float}>
     */
    public static function keres(string $kerdes, int $k = 6, ?array $dokumentumok = null): array
    {
        $q = array_values(array_unique(self::szavak($kerdes)));

        if ($q === []) {
            return [];
        }

        $docs = $dokumentumok ?? array_merge(self::statikus(), self::dinamikus());
        $n    = count($docs);

        if ($n === 0) {
            return [];
        }

        $hossz = 0;
        $df    = array_fill_keys($q, 0);
        $tf    = [];

        foreach ($docs as $i => $d) {
            $hossz += (int) $d['h'];

            foreach ($q as $w) {
                $c = substr_count((string) $d['t'], ' ' . $w . ' ');

                if ($c > 0) {
                    $tf[$i][$w] = $c;
                    $df[$w]++;
                }
            }
        }

        $atlag = max(1.0, $hossz / $n);
        $pont  = [];

        foreach ($tf as $i => $szavak) {
            $p   = 0.0;
            $len = (int) $docs[$i]['h'];

            foreach ($szavak as $w => $c) {
                $idf = log(1 + ($n - $df[$w] + 0.5) / ($df[$w] + 0.5));
                $p  += $idf * ($c * (self::K1 + 1)) / ($c + self::K1 * (1 - self::B + self::B * $len / $atlag));
            }

            // Kis jutalom, ha a kérdés több szava is benne van (a rövid, pontos találat előre kerül).
            $p *= 1 + 0.15 * (count($szavak) - 1);
            $pont[$i] = $p;
        }

        arsort($pont);
        $ki = [];

        foreach (array_slice($pont, 0, max(1, $k), true) as $i => $p) {
            $d    = $docs[$i];
            $ki[] = [
                'id'     => (string) $d['id'],
                'cim'    => SDH_Sales::t((string) $d['cim']),
                'url'    => isset($d['url']) ? (string) $d['url'] : self::url((string) ($d['fejezet'] ?? ''), (string) ($d['horgony'] ?? '')),
                'szoveg' => SDH_Sales::t((string) $d['szoveg']),
                'forras' => (string) $d['forras'],
                'pont'   => round($p, 4),
            ];
        }

        return $ki;
    }
}
