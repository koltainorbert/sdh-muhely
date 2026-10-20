<?php
/**
 * Súgó: bemutató, dokumentáció, kérdés–válasz (GYIK), oldalankénti túra (0.51).
 *
 * A tartalom NEM a kódban van, hanem a bővítmény `sugo/` mappájának
 * fejezeteiben (NN-kulcs.md; a formátumot a sugo/OLVASSEL.md írja le). Egy
 * fejezet: fejléc + `##` szakaszok, benne `## GYIK` (### kérdésekkel) és
 * `## Túra: <modul>` (az oldal végigvezetésének lépései). Ebből épül:
 *
 *  - a Súgó menüpont (Bemutató, Dokumentáció, GYIK, Újdonságok, keresés),
 *  - az asszisztens túrája („Vezess végig ezen az oldalon") – asszisztens.js,
 *  - az asszisztens tudása: a tartalomjegyzék a fix rendszerüzenetben ül, a
 *    kérdéshez illő szakaszok és kérdés–válaszok kérdésenként mennek mellé
 *    (SDH_Muhely_Asszisztens_Tudas), és a `sugo_kereses` / `sugo_olvas`
 *    eszközzel maga is utánanézhet.
 *
 * Így a dokumentáció, a GYIK, a túra és az asszisztens tudása EGY forrásból
 * él: ha a fejezet frissül, mind a négy frissül. A tesztek/t36-sugo.php
 * elbukik, ha egy modulnak, egy Beállítások-lapfülnek vagy az asszisztens
 * egy eszközének nincs fejezete, vagy ha a súgó verziója lemaradt.
 *
 * A cég saját kérdés–válaszai (Súgó › GYIK › „Saját kérdés–válasz") az
 * asszisztens tudástárába kerülnek (forras = gyik) – ezek cégadatok, ezért
 * az adatbázisban élnek, nem a kódban.
 *
 * Fejlesztői tartalom (0.78): a `csak: fejleszto` fejlécű fejezet, a
 * `## Cím [fejlesztő]` szakasz, a `### Kérdés [fejlesztő]` kérdés–válasz és a
 * szakaszon belüli `### Alcím [fejlesztő]` rész csak a fejlesztőnek
 * (SDH_Muhely_Admin_UI::fejleszto_e()) létezik – a többieknek a súgóból, a
 * keresőből és az asszisztens tudásából is hiányzik. Az ilyen fejezetre mutató
 * hivatkozás nekik sima szöveg.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Sugo
{
    public const KULCS = 'sugo';

    private const OPT_ERTEKELES = 'sdh_muhely_sugo_ertekeles';
    private const OPT_KERESESEK = 'sdh_muhely_sugo_keresesek';
    private const META_SZAVAZAT = 'sdh_muhely_sugo_szavazat';
    private const GYIK_OLDAL    = 12;
    private const UJDONSAG_OLDAL = 3;

    /** A csak fejlesztőnek szóló szakasz / kérdés jele a címsor végén. */
    public const FEJLESZTO_JEL = '[fejlesztő]';

    /** A fejezetek csoportjai, ebben a sorrendben. */
    private const CSOPORTOK = ['Kezdés', 'Napi munka', 'Pénz', 'Kapcsolat', 'Csapat és idő', 'Rendszer'];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $fejezetek = null;

    /** A beolvasott fejezetek a fejlesztőnek készültek-e. */
    private static bool $fejezetek_fejleszto = false;

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Súgó',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 80,
        ]);

        foreach (['sugo_keres' => 'ajax_keres', 'sugo_ertekel' => 'ajax_ertekel', 'sugogyik_urlap' => 'ajax_gyik_urlap', 'sugogyik_ment' => 'ajax_gyik_ment'] as $nev => $fuggveny) {
            add_action('wp_ajax_sdh_muhely_' . $nev, [self::class, $fuggveny]);
        }

        add_action('admin_enqueue_scripts', [self::class, 'admin_eszkozok'], 20);
    }

    public static function admin_eszkozok(): void
    {
        if (!wp_script_is('sdh-muhely-app', 'enqueued')) {
            return;
        }

        wp_enqueue_style('sdh-muhely-sugo', SDH_MUHELY_URL . 'assets/sugo.css', ['sdh-muhely-admin'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/sugo.css'));
        wp_enqueue_script('sdh-muhely-sugo', SDH_MUHELY_URL . 'assets/sugo.js', ['sdh-muhely-app'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/sugo.js'), true);
    }

    /* =================================================================
     * A fejezetek beolvasása
     * ============================================================== */

    public static function mappa(): string
    {
        return SDH_MUHELY_DIR . 'sugo/';
    }

    /**
     * A fejezetekben használható nevek (0.99.5) – a Beállítások › Logó és megjelenés › Termék neve szerint.
     *
     * @return array<string, string>
     */
    public static function tokenek(): array
    {
        if (!class_exists('SDH_Muhely_Arculat')) {
            return ['{termeknev}' => 'a rendszer', '{rovidnev}' => 'rendszer', '{kozpontnev}' => 'Licencközpont'];
        }

        return [
            '{termeknev}'  => SDH_Muhely_Arculat::termeknev(),
            '{rovidnev}'   => SDH_Muhely_Arculat::rovidnev(),
            '{kozpontnev}' => SDH_Muhely_Arculat::kozpontnev(),
        ];
    }

    /** A fejezetfájlok (NN-kulcs.md), sorrendben. */
    public static function fajlok(): array
    {
        $fajlok = glob(self::mappa() . '[0-9][0-9]-*.md') ?: [];
        sort($fajlok);

        return $fajlok;
    }

    /** A szakasz horgonya a címéből (ékezet nélkül, kötőjellel). */
    public static function horgony(string $cim): string
    {
        $h = strtolower(remove_accents($cim));
        $h = trim((string) preg_replace('/[^a-z0-9]+/', '-', $h), '-');

        return $h !== '' ? mb_substr($h, 0, 80) : 'szakasz';
    }

    /** A jelenlegi felhasználó a fejlesztő-e (neki látszik a fejlesztői tartalom). */
    public static function fejleszto(): bool
    {
        return class_exists('SDH_Muhely_Admin_UI') && SDH_Muhely_Admin_UI::fejleszto_e();
    }

    /**
     * A címsor fejlesztői jele: a jel nélküli cím, és hogy volt-e jel.
     *
     * @return array{0: string, 1: bool}
     */
    private static function jel(string $cim): array
    {
        $cim = trim($cim);
        $n   = strlen(self::FEJLESZTO_JEL);

        if ($n > 0 && substr($cim, -$n) === self::FEJLESZTO_JEL) {
            return [rtrim(substr($cim, 0, -$n)), true];
        }

        return [$cim, false];
    }

    /**
     * A szakasz törzséből a `### … [fejlesztő]` részek (a következő ### vagy
     * a szakasz végéig) kimaradnak, ha nem a fejlesztő olvas; neki a jel lekerül.
     *
     * @param array<int, string> $sorok
     * @return array<int, string>
     */
    private static function fejlesztoi_alszakaszok(array $sorok, bool $fejleszto): array
    {
        $ki     = [];
        $kihagy = false;
        $kodban = false;

        foreach ($sorok as $sor) {
            if (strpos($sor, '```') === 0) {
                $kodban = !$kodban;
            }

            if (!$kodban && strpos($sor, '### ') === 0) {
                [$cim, $jeles] = self::jel(substr($sor, 4));
                $kihagy        = $jeles && !$fejleszto;

                if ($jeles && $fejleszto) {
                    $sor = '### ' . $cim . ' (fejlesztő)';
                }
            }

            if (!$kihagy) {
                $ki[] = $sor;
            }
        }

        return $ki;
    }

    /**
     * Egy fejezetfájl feldolgozása.
     *
     * @param bool|null $fejleszto A fejlesztői részek is kellenek-e (null: a jelenlegi felhasználó szerint).
     * @return array<string, mixed>|null
     */
    public static function fajl_olvas(string $ut, ?bool $fejleszto = null): ?array
    {
        $fejleszto = $fejleszto ?? self::fejleszto();
        $nyers = is_readable($ut) ? (string) file_get_contents($ut) : '';
        $nyers = str_replace(["\r\n", "\r"], "\n", $nyers);
        // A termék neve (0.99.5): a fejezetekben {termeknev}, {rovidnev}, {kozpontnev} áll, itt kap értéket.
        $nyers = strtr($nyers, self::tokenek());

        if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $nyers, $m) !== 1) {
            return null;
        }

        $fej = [];

        foreach (explode("\n", $m[1]) as $sor) {
            if (strpos($sor, ':') !== false) {
                [$k, $v] = explode(':', $sor, 2);
                $fej[trim($k)] = trim($v);
            }
        }

        $kulcs = sanitize_key((string) ($fej['kulcs'] ?? ''));

        if ($kulcs === '') {
            return null;
        }

        $lista = static fn (string $s): array => array_values(array_filter(array_map('sanitize_key', array_map('trim', explode(',', $s)))));

        $f = [
            'kulcs'        => $kulcs,
            'fajl'         => basename($ut),
            'sorrend'      => (int) basename($ut),
            'cim'          => (string) ($fej['cim'] ?? $kulcs),
            'csoport'      => in_array((string) ($fej['csoport'] ?? ''), self::CSOPORTOK, true) ? (string) $fej['csoport'] : 'Rendszer',
            'modulok'      => $lista((string) ($fej['modul'] ?? '')),
            'lapfulek'     => $lista((string) ($fej['szakaszok'] ?? '')),
            'kulcsszavak'  => (string) ($fej['kulcsszavak'] ?? ''),
            'osszefoglalo' => (string) ($fej['osszefoglalo'] ?? ''),
            'verzio'       => (string) ($fej['verzio'] ?? ''),
            'csak_fejleszto' => in_array(sanitize_key(remove_accents((string) ($fej['csak'] ?? ''))), ['fejleszto'], true),
            'szakaszok'    => [],
            'gyik'         => [],
            'turak'        => [],
            'utvonal'      => [],
        ];

        // A törzs `## ` szakaszokra bontva (a kódblokkon belüli ## nem számít).
        $reszek  = [];
        $aktualis = null;
        $kodban  = false;

        foreach (explode("\n", $m[2]) as $sor) {
            if (strpos($sor, '```') === 0) {
                $kodban = !$kodban;
            }

            if (!$kodban && strpos($sor, '## ') === 0) {
                $reszek[] = ['cim' => trim(substr($sor, 3)), 'sorok' => []];
                $aktualis = count($reszek) - 1;
                continue;
            }

            if ($aktualis !== null) {
                $reszek[$aktualis]['sorok'][] = $sor;
            }
        }

        $horgonyok = [];

        foreach ($reszek as $r) {
            [$cim, $jeles] = self::jel((string) $r['cim']);

            // A fejlesztői szakasz a többieknek nem létezik.
            if ($jeles && !$fejleszto) {
                continue;
            }

            $torzs = trim(implode("\n", $cim === 'GYIK' ? $r['sorok'] : self::fejlesztoi_alszakaszok($r['sorok'], $fejleszto)));

            if ($cim === 'GYIK') {
                $kerdes = null;
                $valasz = [];
                $fjel   = false;
                $zar    = static function () use (&$f, &$kerdes, &$valasz, &$fjel, $kulcs, $fejleszto): void {
                    if ($kerdes !== null && trim(implode("\n", $valasz)) !== '' && (!$fjel || $fejleszto)) {
                        $f['gyik'][] = [
                            'id'        => $kulcs . '-' . (count($f['gyik']) + 1),
                            'kerdes'    => $kerdes,
                            'valasz'    => trim(implode("\n", $valasz)),
                            'fejleszto' => $fjel,
                        ];
                    }

                    $kerdes = null;
                    $valasz = [];
                    $fjel   = false;
                };

                foreach ($r['sorok'] as $sor) {
                    if (strpos($sor, '### ') === 0) {
                        $zar();
                        [$kerdes, $fjel] = self::jel(substr($sor, 4));
                    } elseif ($kerdes !== null) {
                        $valasz[] = $sor;
                    }
                }

                $zar();
                continue;
            }

            if (preg_match('/^Túra:\s*([a-z0-9_-]+)\s*$/u', $cim, $t) === 1) {
                foreach ($r['sorok'] as $sor) {
                    // - `szelektor` | Cím | Szöveg
                    if (preg_match('/^- `([^`]+)`\s*\|\s*([^|]+?)\s*\|\s*(.+)$/u', trim($sor), $l) === 1) {
                        $f['turak'][$t[1]][] = ['szel' => trim($l[1]), 'cim' => trim($l[2]), 'szoveg' => trim($l[3])];
                    }
                }

                continue;
            }

            if ($cim === 'Útvonal') {
                foreach ($r['sorok'] as $sor) {
                    // - modulkulcs | fejezetkulcs | Cím | Szöveg
                    $d = array_map('trim', explode('|', ltrim(trim($sor), '- ')));

                    if (count($d) === 4 && strpos(trim($sor), '- ') === 0) {
                        $f['utvonal'][] = ['modul' => sanitize_key($d[0]), 'fejezet' => sanitize_key($d[1]), 'cim' => $d[2], 'szoveg' => $d[3]];
                    }
                }

                continue;
            }

            // Egyedi horgony fejezeten belül (azonos címnél sorszámmal).
            $h = self::horgony($cim);
            $n = 1;

            while (isset($horgonyok[$h])) {
                $h = self::horgony($cim) . '-' . (++$n);
            }

            $horgonyok[$h] = true;

            $f['szakaszok'][] = ['id' => $kulcs . '#' . $h, 'horgony' => $h, 'cim' => $cim, 'torzs' => $torzs, 'fejleszto' => $jeles];
        }

        return $f;
    }

    /**
     * Minden fejezet, kulcs szerint, a fájlok sorrendjében.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fejezetek(): array
    {
        $fejleszto = self::fejleszto();

        // A fejlesztő mást lát, mint a többiek: szerepváltás után újraolvasunk.
        if (self::$fejezetek !== null && self::$fejezetek_fejleszto === $fejleszto) {
            return self::$fejezetek;
        }

        self::$fejezetek           = [];
        self::$fejezetek_fejleszto = $fejleszto;

        foreach (self::fajlok() as $ut) {
            $f = self::fajl_olvas($ut, $fejleszto);

            // A csak fejlesztőnek szóló fejezet (fejléc: `csak: fejleszto`) a többieknek nem létezik.
            if ($f !== null && !empty($f['csak_fejleszto']) && !$fejleszto) {
                continue;
            }

            if ($f !== null && self::fejezet_el((array) $f['modulok'])) {
                self::$fejezetek[(string) $f['kulcs']] = $f;
            }
        }

        return self::$fejezetek;
    }

    /**
     * Látszik-e a fejezet: a kikapcsolt modul (Modulok menüpont) fejezete eltűnik
     * a súgóból és az asszisztens tudásából – ha minden menüpontja kikapcsolt modulé.
     *
     * @param array<int, mixed> $modulok A fejezet fejlécének `modul:` sora.
     */
    private static function fejezet_el(array $modulok): bool
    {
        if ($modulok === []) {
            return true;
        }

        foreach ($modulok as $modul) {
            if (SDH_Muhely_Modulok::be((string) $modul)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    public static function fejezet(string $kulcs): ?array
    {
        return self::fejezetek()[$kulcs] ?? null;
    }

    /** Teszthez: a beolvasott fejezetek elfelejtése. */
    public static function urit(): void
    {
        self::$fejezetek = null;
        delete_transient('sdh_muhely_sugo_index');
        delete_transient('sdh_muhely_sugo_index_f');
    }

    /** A súgó verziója (a 00-sugo.md fejlécének `verzio` sora). */
    public static function verzio(): string
    {
        $f = self::fejezet('sugo');

        return $f !== null ? (string) $f['verzio'] : '';
    }

    /**
     * Könnyű tartalomjegyzék (gyorsítótárból): az oldalankénti túrákhoz és a
     * modul → fejezet megfeleltetéshez nem kell minden oldalbetöltéskor
     * minden fejezetet újra feldolgozni.
     *
     * @return array{modul: array<string, string>, turak: array<string, array<int, array<string, string>>>, cimek: array<string, string>}
     */
    public static function index(): array
    {
        $ujj = '';

        foreach (self::fajlok() as $ut) {
            $ujj .= basename($ut) . ':' . (int) @filemtime($ut) . ':' . (int) @filesize($ut) . '|'; // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        // A kikapcsolt modulok is benne vannak: átkapcsolás után a tartalomjegyzék újraépül.
        $ujj = md5($ujj . SDH_MUHELY_VERSION . '|' . implode('|', self::tokenek()) . '|' . implode(',', SDH_Muhely_Modulok::kikapcsoltak()) . '|' . implode(',', SDH_Muhely_Modulok::licenc_tiltott()));
        // A fejlesztő több fejezetet lát: neki külön tartalomjegyzék jár (a kettő nem írja felül egymást).
        $tkulcs = 'sdh_muhely_sugo_index' . (self::fejleszto() ? '_f' : '');
        $c      = get_transient($tkulcs);

        if (is_array($c) && ($c['u'] ?? '') === $ujj && is_array($c['i'] ?? null)) {
            return $c['i'];
        }

        $i = ['modul' => [], 'turak' => [], 'cimek' => []];

        foreach (self::fejezetek() as $kulcs => $f) {
            $i['cimek'][$kulcs] = (string) $f['cim'];

            foreach ((array) $f['modulok'] as $modul) {
                $i['modul'][$modul] = $i['modul'][$modul] ?? $kulcs;
            }

            foreach ((array) $f['turak'] as $modul => $lepesek) {
                $i['turak'][$modul] = $lepesek;
            }
        }

        set_transient($tkulcs, ['u' => $ujj, 'i' => $i], DAY_IN_SECONDS);

        return $i;
    }

    /** Melyik fejezet írja le a menüpontot (üres, ha egyik sem). */
    public static function modul_fejezet(string $modul): string
    {
        return (string) (self::index()['modul'][$modul] ?? '');
    }

    /**
     * Egy oldal túrájának lépései.
     *
     * @return array<int, array{szel: string, cim: string, szoveg: string}>
     */
    public static function tura(string $modul): array
    {
        return (array) (self::index()['turak'][$modul] ?? []);
    }

    /* =================================================================
     * Kérdés–válasz
     * ============================================================== */

    /**
     * A cég saját kérdés–válaszai (az asszisztens tudástárából, forras = gyik).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sajat_gyik(bool $csak_aktiv = true): array
    {
        global $wpdb;

        if (!class_exists('SDH_Muhely_Asszisztens_Tudas')) {
            return [];
        }

        $t = SDH_Muhely_Asszisztens_Tudas::tabla();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a táblanév saját.
        $sorok = $wpdb->get_results("SELECT id, cim, szoveg, kulcsszavak, aktiv FROM {$t} WHERE forras = 'gyik'" . ($csak_aktiv ? ' AND aktiv = 1' : '') . ' ORDER BY id DESC LIMIT 300');
        $ki    = [];

        foreach ((array) $sorok as $r) {
            $ki[] = [
                'id'           => 's' . (int) $r->id,
                'tudas_id'     => (int) $r->id,
                'fejezet'      => 'sajat',
                'fejezet_cim'  => 'Saját kérdések',
                'kerdes'       => (string) $r->cim,
                'valasz'       => (string) $r->szoveg,
                'kulcsszavak'  => (string) $r->kulcsszavak,
                'aktiv'        => (int) $r->aktiv === 1,
                'sajat'        => true,
            ];
        }

        return $ki;
    }

    /**
     * Minden kérdés–válasz: a cég sajátjai elöl, utánuk a fejezeteké.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function gyik(): array
    {
        $ki = self::sajat_gyik();

        foreach (self::fejezetek() as $kulcs => $f) {
            foreach ((array) $f['gyik'] as $g) {
                $ki[] = $g + ['fejezet' => $kulcs, 'fejezet_cim' => (string) $f['cim'], 'kulcsszavak' => (string) $f['kulcsszavak'], 'sajat' => false];
            }
        }

        return $ki;
    }

    /* =================================================================
     * Keresés
     * ============================================================== */

    /** Köznyelvi szavak, amelyekre a rendszer szava felel (ékezet nélkül; szó => rokon szavak). */
    private const ROKON = [
        'kassza'    => ['penztar', 'hazipenztar', 'zaras'],
        'penztar'   => ['kassza', 'hazipenztar'],
        'vevo'      => ['ugyfel'],
        'kliens'    => ['ugyfel'],
        'partner'   => ['ugyfel'],
        'telefon'   => ['eszkoz', 'keszulek'],
        'mobil'     => ['eszkoz', 'keszulek'],
        'keszulek'  => ['eszkoz'],
        'gep'       => ['eszkoz'],
        'szerviz'   => ['javitas', 'munkalap'],
        'javitas'   => ['munkalap'],
        'lap'       => ['munkalap'],
        'email'     => ['level', 'levelezes'],
        'mail'      => ['level', 'levelezes'],
        'level'     => ['levelezes'],
        'chat'      => ['csevego', 'uzenet', 'csapat'],
        'uzenet'    => ['csevego', 'rma'],
        'bolt'      => ['arukiadas', 'helyszin'],
        'eladas'    => ['arukiadas'],
        'vasarlas'  => ['arukiadas'],
        'rendeles'  => ['rendelesek', 'beszallito'],
        'szamla'    => ['szamlazas', 'szamlazz'],
        'nyugta'    => ['szamla', 'elokeszito'],
        'ar'        => ['arlista', 'dij', 'brutto'],
        'dij'       => ['bevizsgalasi', 'ar'],
        'eloleg'    => ['fizetett', 'fizetes'],
        'fizetes'   => ['fizetve', 'eloleg', 'penztar'],
        'hatarido'  => ['naptar', 'esemeny'],
        'idopont'   => ['naptar', 'esemeny'],
        'emlekezteto' => ['naptar', 'jelzes', 'kartya'],
        'kollega'   => ['csapat', 'felhasznalo'],
        'dolgozo'   => ['kollega', 'csapat'],
        'jogosultsag' => ['adminisztrator', 'kollega', 'szerep'],
        'jelszo'    => ['belepes', 'alkalmazasjelszo'],
        'backup'    => ['mentes'],
        'biztonsagi' => ['mentes'],
        'szin'      => ['megjelenes', 'arculat', 'tema'],
        'sotet'     => ['megjelenes', 'tema'],
        'betu'      => ['betumeret', 'betutipus', 'nagyitas'],
        'nagyitas'  => ['betumeret'],
        'logo'      => ['arculat', 'megjelenes'],
        'robot'     => ['asszisztens'],
        'ai'        => ['asszisztens'],
        'mesterseges' => ['asszisztens'],
        'qr'        => ['cimke', 'ugyfeloldal', 'rma'],
        'cimke'     => ['qr', 'nyomtatas'],
        'imei'      => ['eszkoz', 'tac'],
        'keszlet'   => ['termek', 'raktar'],
        'raktar'    => ['keszlet', 'termek'],
        'alkatresz' => ['termek'],
        'frissites' => ['telepites', 'verzio'],
        'hiba'      => ['hibaelharitas'],
        'torles'    => ['torol', 'ervenytelen'],
    ];

    /** A kérdés szavai, a rokon szavakkal kiegészítve (szó => súly). */
    private static function kerdes_szavak(string $q): array
    {
        $ki = [];

        foreach (SDH_Muhely_Asszisztens_Tudas::szavak($q) as $w) {
            $ki[$w] = 1.0;

            foreach (self::ROKON as $szo => $rokonok) {
                // A toldalékos alak is számít („kasszát" → kassza).
                if ($w === $szo || (strlen($szo) >= 4 && strpos($w, $szo) === 0)) {
                    foreach ($rokonok as $r) {
                        $ki[$r] = max($ki[$r] ?? 0, .6);
                    }
                }
            }
        }

        return $ki;
    }

    /** Szöveg szavakra bontva, szóközzel határolva (a pontozáshoz). */
    private static function szosor(string $szoveg): string
    {
        return ' ' . implode(' ', SDH_Muhely_Asszisztens_Tudas::szavak(self::sima($szoveg))) . ' ';
    }

    /** Egyezés: teljes szó 2 pont, szótő (az első 4–6 betű) 1 pont, a kérdésszó súlyával szorozva. */
    private static function pont(array $kerdes, string $szosor, float $suly): float
    {
        $p = 0.0;

        foreach ($kerdes as $w => $ws) {
            $w = (string) $w;

            if (strpos($szosor, ' ' . $w . ' ') !== false) {
                $p += 2 * $suly * $ws;
            } elseif (strpos($szosor, ' ' . substr($w, 0, max(4, min(6, strlen($w) - 2)))) !== false) {
                $p += $suly * $ws;
            }
        }

        return $p;
    }

    /**
     * Keresés a dokumentációban és a kérdés–válaszok között.
     *
     * @param string $mit 'mind', 'szakasz' vagy 'gyik'
     * @return array<int, array<string, mixed>> találatok pont szerint: tipus, id, cim, fejezet, fejezet_cim, torzs, pont
     */
    public static function keres(string $q, int $max = 10, string $mit = 'mind'): array
    {
        $kerdes = self::kerdes_szavak($q);

        if ($kerdes === []) {
            return [];
        }

        $talalat = [];

        if ($mit !== 'gyik') {
            foreach (self::fejezetek() as $kulcs => $f) {
                $fej = self::pont($kerdes, self::szosor($f['cim'] . ' ' . $f['kulcsszavak']), 1);

                foreach ((array) $f['szakaszok'] as $s) {
                    $p = self::pont($kerdes, self::szosor((string) $s['cim']), 4) + self::pont($kerdes, self::szosor((string) $s['torzs']), 1);

                    if ($p > 0) {
                        $talalat[] = ['tipus' => 'szakasz', 'id' => (string) $s['id'], 'cim' => (string) $s['cim'], 'fejezet' => $kulcs, 'fejezet_cim' => (string) $f['cim'], 'torzs' => (string) $s['torzs'], 'pont' => $p + $fej];
                    }
                }
            }
        }

        if ($mit !== 'szakasz') {
            foreach (self::gyik() as $g) {
                $p = self::pont($kerdes, self::szosor((string) $g['kerdes']), 5) + self::pont($kerdes, self::szosor((string) $g['valasz']), 1);

                if ($p > 0) {
                    $p += self::pont($kerdes, self::szosor((string) $g['fejezet_cim'] . ' ' . (string) $g['kulcsszavak']), 1) + (!empty($g['sajat']) ? 2 : 0);
                    $talalat[] = ['tipus' => 'gyik', 'id' => (string) $g['id'], 'cim' => (string) $g['kerdes'], 'fejezet' => (string) $g['fejezet'], 'fejezet_cim' => (string) $g['fejezet_cim'], 'torzs' => (string) $g['valasz'], 'pont' => $p];
                }
            }
        }

        usort($talalat, static fn (array $a, array $b): int => $b['pont'] <=> $a['pont']);

        // Gyenge találat (egyetlen szótő-egyezés a törzsben) ne szennyezze a listát, ha van jobb.
        if ($talalat !== [] && $talalat[0]['pont'] >= 6) {
            $kuszob  = $talalat[0]['pont'] * .22;
            $talalat = array_values(array_filter($talalat, static fn (array $t): bool => $t['pont'] >= $kuszob));
        }

        return array_slice($talalat, 0, max(1, $max));
    }

    /** Egy találat címe az oldalon (a műhely-felületen, illetve a wp-adminban). */
    public static function talalat_url(array $t): string
    {
        if ($t['tipus'] === 'gyik') {
            return SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'gyik', 'kerdes' => (string) $t['id']]) . '#gyik-' . rawurlencode((string) $t['id']);
        }

        [$fejezet, $horgony] = array_pad(explode('#', (string) $t['id'], 2), 2, '');

        return SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'dokumentacio', 'fejezet' => $fejezet]) . ($horgony !== '' ? '#' . $horgony : '');
    }

    /** Rövid részlet a törzsből a keresett szavak környékéről. */
    public static function reszlet(string $torzs, string $q, int $hossz = 220): string
    {
        $sima = trim((string) preg_replace('/\s+/u', ' ', self::sima($torzs)));

        if (mb_strlen($sima) <= $hossz) {
            return $sima;
        }

        $also = mb_strtolower(remove_accents($sima));
        $hol  = -1;

        foreach (array_keys(self::kerdes_szavak($q)) as $w) {
            $h = mb_strpos($also, substr((string) $w, 0, max(4, strlen((string) $w) - 2)));

            if ($h !== false && ($hol < 0 || $h < $hol)) {
                $hol = (int) $h;
            }
        }

        $tol = max(0, ($hol < 0 ? 0 : $hol) - 60);

        return ($tol > 0 ? '… ' : '') . trim(mb_substr($sima, $tol, $hossz)) . ' …';
    }

    /* =================================================================
     * Szöveg: jelölések nélkül (az asszisztensnek, a részlethez) és HTML-ként
     * ============================================================== */

    /** A jelölések nélküli, olvasható szöveg. */
    public static function sima(string $md): string
    {
        $s = (string) preg_replace('/```.*?```/s', ' ', $md);
        $s = (string) preg_replace('/\[([^\]]+)\]\((?:sugo|modul|https?):[^)]*\)/u', '$1', $s);
        $s = (string) preg_replace('/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/m', '', $s);
        $s = (string) preg_replace('/^(#{2,4}\s+|>\s?|\s*[-*]\s+)/m', '', $s);
        $s = str_replace(['**', '`', '[[', ']]', '|'], ['', '', '', '', ' '], $s);
        $s = (string) preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '$1', $s);

        return trim($s);
    }

    /** Sorközi jelölések (a szöveg már HTML-biztos). */
    private static function sorkozi(string $s): string
    {
        $kodok = [];

        // `kód`: a tartalma érintetlen marad.
        $s = (string) preg_replace_callback('/`([^`]+)`/u', static function (array $m) use (&$kodok): string {
            $kodok[] = '<code>' . $m[1] . '</code>';

            return "\x01" . (count($kodok) - 1) . "\x02";
        }, $s);

        // [felirat](sugo:fejezet#horgony), [felirat](modul:kulcs), [felirat](https://…)
        $s = (string) preg_replace_callback('/\[([^\]]+)\]\(((?:sugo|modul):[a-z0-9_\-#]*|https?:\/\/[^\s)]+)\)/u', static function (array $m): string {
            $cel = $m[2];

            if (strpos($cel, 'sugo:') === 0) {
                [$fejezet, $horgony] = array_pad(explode('#', substr($cel, 5), 2), 2, '');

                // A más (pl. a fejlesztő) számára fenntartott fejezetre mutató hivatkozás sima szöveg.
                if ($fejezet !== '' && self::fejezet(sanitize_key($fejezet)) === null) {
                    return $m[1];
                }

                $url = $fejezet === ''
                    ? SDH_Muhely_Modulok::url(self::KULCS)
                    : SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'dokumentacio', 'fejezet' => sanitize_key($fejezet)]) . ($horgony !== '' ? '#' . sanitize_key($horgony) : '');

                return '<a class="sdh-sugo-link" href="' . esc_url($url) . '">' . $m[1] . '</a>';
            }

            if (strpos($cel, 'modul:') === 0) {
                $modul = sanitize_key(substr($cel, 6));

                return '<a class="sdh-sugo-modul" href="' . esc_url(SDH_Muhely_Modulok::url($modul === 'attekintes' ? '' : $modul)) . '">' . $m[1] . '</a>';
            }

            return '<a href="' . esc_url(html_entity_decode($cel, ENT_QUOTES)) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
        }, $s);

        $s = (string) preg_replace('/\[\[([^\]\n]{1,24})\]\]/u', '<kbd>$1</kbd>', $s);
        $s = (string) preg_replace('/\*\*([^*\n]+(?:\*[^*\n]+)*?)\*\*/u', '<strong>$1</strong>', $s);
        $s = (string) preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '<em>$1</em>', $s);

        return (string) preg_replace_callback('/\x01(\d+)\x02/', static fn (array $m): string => $kodok[(int) $m[1]] ?? '', $s);
    }

    /**
     * A fejezetek jelölésnyelve HTML-ként. A bemenetet előbb HTML-biztossá
     * teszi, és csak a saját jelöléseiből épít címkét – nyers HTML nem megy át.
     */
    public static function md(string $md): string
    {
        $sorok = explode("\n", str_replace(["\r\n", "\r"], "\n", $md));
        $html  = '';
        $n     = count($sorok);
        $i     = 0;
        $b     = static fn (string $s): string => self::sorkozi(esc_html($s));

        while ($i < $n) {
            $sor = rtrim($sorok[$i]);

            if (trim($sor) === '') {
                $i++;
                continue;
            }

            // Kódblokk
            if (strpos($sor, '```') === 0) {
                $kod = [];
                $i++;

                while ($i < $n && strpos($sorok[$i], '```') !== 0) {
                    $kod[] = $sorok[$i];
                    $i++;
                }

                $i++;
                $html .= '<pre class="sdh-sugo-kod"><code>' . esc_html(implode("\n", $kod)) . '</code></pre>';
                continue;
            }

            // Alcím
            if (preg_match('/^(#{3,4})\s+(.+)$/u', $sor, $m) === 1) {
                $szint = strlen($m[1]);
                $html .= '<h' . $szint . ' class="sdh-sugo-alcim">' . $b($m[2]) . '</h' . $szint . '>';
                $i++;
                continue;
            }

            // Kiemelt doboz
            if (strpos($sor, '>') === 0) {
                $resz = [];

                while ($i < $n && strpos($sorok[$i], '>') === 0) {
                    $resz[] = ltrim(substr($sorok[$i], 1));
                    $i++;
                }

                $szoveg = implode(' ', $resz);
                $fajta  = 'tipp';

                if (preg_match('/^\*\*(Tipp|Figyelem|Fontos|Példa):\*\*/u', $szoveg, $m) === 1) {
                    $fajta = ['Tipp' => 'tipp', 'Figyelem' => 'figyelem', 'Fontos' => 'fontos', 'Példa' => 'pelda'][$m[1]];
                }

                $html .= '<div class="sdh-sugo-doboz sdh-sugo-doboz--' . $fajta . '"><p>' . $b($szoveg) . '</p></div>';
                continue;
            }

            // Táblázat
            if (strpos(ltrim($sor), '|') === 0 && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{2,}/', $sorok[$i + 1]) === 1) {
                $cellak = static fn (string $s): array => array_map('trim', explode('|', trim(trim($s), '|')));
                $fej    = $cellak($sor);
                $i     += 2;
                $html  .= '<div class="sdh-sugo-tabla"><table><thead><tr>';

                foreach ($fej as $c) {
                    $html .= '<th>' . $b($c) . '</th>';
                }

                $html .= '</tr></thead><tbody>';

                while ($i < $n && strpos(ltrim($sorok[$i]), '|') === 0) {
                    $html .= '<tr>';

                    // A `kód`-ban álló függőleges vonal nem cellahatár.
                    $vedett = (string) preg_replace_callback('/`[^`]*`/', static fn (array $m): string => str_replace('|', "\x03", $m[0]), $sorok[$i]);

                    foreach ($cellak($vedett) as $c) {
                        $html .= '<td>' . $b(str_replace("\x03", '|', $c)) . '</td>';
                    }

                    $html .= '</tr>';
                    $i++;
                }

                $html .= '</tbody></table></div>';
                continue;
            }

            // Felsorolás és számozott lista (egy szint behúzással)
            if (preg_match('/^(\s*)([-*]|\d+\.)\s+(.+)$/u', $sor, $m) === 1) {
                $szamozott = ctype_digit($m[2][0]);
                $html     .= $szamozott ? '<ol>' : '<ul>';
                $nyitott   = false;
                $belso     = '';

                while ($i < $n && preg_match('/^(\s*)([-*]|\d+\.)\s+(.+)$/u', rtrim($sorok[$i]), $l) === 1) {
                    if (strlen($l[1]) >= 2) {
                        // Beágyazott szint
                        $bsz = ctype_digit($l[2][0]);

                        if ($belso === '') {
                            $belso = $bsz ? 'ol' : 'ul';
                            $html .= '<' . $belso . '>';
                        }

                        $html .= '<li>' . $b($l[3]) . '</li>';
                    } else {
                        if ($belso !== '') {
                            $html .= '</' . $belso . '>';
                            $belso = '';
                        }

                        if ($nyitott) {
                            $html .= '</li>';
                        }

                        $html   .= '<li>' . $b($l[3]);
                        $nyitott = true;
                    }

                    $i++;

                    // Folytatósor (behúzott, nem listaelem) az elemhez tartozik.
                    while ($i < $n && trim($sorok[$i]) !== '' && preg_match('/^\s{2,}(?![-*]\s|\d+\.\s)(.+)$/u', $sorok[$i], $f) === 1) {
                        $html .= ' ' . $b(trim($f[1]));
                        $i++;
                    }
                }

                if ($belso !== '') {
                    $html .= '</' . $belso . '>';
                }

                $html .= ($nyitott ? '</li>' : '') . ($szamozott ? '</ol>' : '</ul>');
                continue;
            }

            // Bekezdés
            $bek = [];

            while ($i < $n && trim($sorok[$i]) !== '' && preg_match('/^(#{3,4}\s|>|```|\s*([-*]|\d+\.)\s)/u', $sorok[$i]) !== 1 && !(strpos(ltrim($sorok[$i]), '|') === 0 && $bek === [])) {
                $bek[] = trim($sorok[$i]);
                $i++;
            }

            if ($bek === []) {
                // Biztonsági lépés: ismeretlen sor ne akassza meg a ciklust.
                $bek[] = trim($sorok[$i]);
                $i++;
            }

            $html .= '<p>' . $b(implode(' ', $bek)) . '</p>';
        }

        return $html;
    }

    /* =================================================================
     * Az asszisztensnek
     * ============================================================== */

    /** A súgó tartalomjegyzéke a fix rendszerüzenetbe: fejezetek, összefoglaló, szakaszcímek. */
    public static function ai_tartalom(): string
    {
        $t = '';

        foreach (self::fejezetek() as $kulcs => $f) {
            $cimek = array_map(static fn (array $s): string => (string) $s['cim'], (array) $f['szakaszok']);
            $t    .= '- ' . $kulcs . ' – ' . $f['cim'] . ((array) $f['modulok'] !== [] ? ' [menüpont: ' . implode(', ', (array) $f['modulok']) . ']' : '')
                . ': ' . $f['osszefoglalo'] . ' Szakaszok: ' . implode('; ', $cimek) . '. (' . count((array) $f['gyik']) . " kérdés–válasz)\n";
        }

        return $t;
    }

    /**
     * A kérdéshez illő súgórészek a kérdésenkénti rendszerüzenetbe.
     *
     * @param string $oldal A jelenlegi oldal modulkulcsa: annak a fejezete előnyt kap.
     */
    public static function ai_reszek(string $kerdes, string $oldal = '', int $max_szakasz = 4, int $max_gyik = 4): string
    {
        $t         = '';
        $fejezet   = $oldal !== '' ? self::modul_fejezet($oldal) : '';
        $szakaszok = self::keres($kerdes, 14, 'szakasz');
        $gyik      = self::keres($kerdes, 14, 'gyik');

        // Az oldal saját fejezete kis előnyt kap: a „hogyan csinálom ezt" többnyire arra vonatkozik.
        if ($fejezet !== '') {
            foreach ([&$szakaszok, &$gyik] as &$lista) {
                foreach ($lista as &$x) {
                    if ($x['fejezet'] === $fejezet) {
                        $x['pont'] *= 1.35;
                    }
                }

                unset($x);
                usort($lista, static fn (array $a, array $b): int => $b['pont'] <=> $a['pont']);
            }

            unset($lista);
        }

        foreach (array_slice($szakaszok, 0, $max_szakasz) as $s) {
            $t .= '[' . $s['id'] . '] ' . $s['fejezet_cim'] . ' › ' . $s['cim'] . ":\n" . mb_substr(self::sima((string) $s['torzs']), 0, 2600) . "\n\n";
        }

        foreach (array_slice($gyik, 0, $max_gyik) as $g) {
            $t .= 'K: ' . $g['cim'] . "\nV: " . mb_substr(self::sima((string) $g['torzs']), 0, 1100) . "\n\n";
        }

        if ($fejezet !== '' && ($f = self::fejezet($fejezet)) !== null) {
            $t = 'EZ AZ OLDAL A SÚGÓBAN: ' . $f['cim'] . ' (' . $fejezet . ') – ' . $f['osszefoglalo']
                . (self::tura($oldal) !== [] ? ' Van hozzá oldaltúra (' . count(self::tura($oldal)) . ' lépés): a `tura` eszközzel indíthatod.' : '') . "\n\n" . $t;
        }

        return trim($t);
    }

    /* =================================================================
     * Értékelés és a megválaszolatlan keresések
     * ============================================================== */

    /** @return array<string, array{jo: int, nem: int}> */
    public static function ertekelesek(): array
    {
        $e = get_option(self::OPT_ERTEKELES, []);

        return is_array($e) ? $e : [];
    }

    /** A találat nélküli keresés feljegyzése (az utolsó 60 különböző kifejezés). */
    public static function kereses_naploz(string $q): void
    {
        $q = mb_strtolower(trim(mb_substr($q, 0, 120)));

        if (mb_strlen($q) < 4) {
            return;
        }

        $l = get_option(self::OPT_KERESESEK, []);
        $l = is_array($l) ? $l : [];

        $l[$q] = ['db' => (int) ($l[$q]['db'] ?? 0) + 1, 'ido' => current_time('mysql')];

        uasort($l, static fn (array $a, array $b): int => strcmp((string) $b['ido'], (string) $a['ido']));
        update_option(self::OPT_KERESESEK, array_slice($l, 0, 60, true), false);
    }

    /** @return array<string, array{db: int, ido: string}> */
    public static function megvalaszolatlan(): array
    {
        $l = get_option(self::OPT_KERESESEK, []);

        return is_array($l) ? $l : [];
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    private static function p(string $k, int $max = 255): string
    {
        // phpcs:disable WordPress.Security.NonceVerification -- a hívó ellenőrzi.
        $v = $_POST[$k] ?? ($_GET[$k] ?? '');
        // phpcs:enable

        return is_scalar($v) ? mb_substr(sanitize_text_field(wp_unslash((string) $v)), 0, $max) : '';
    }

    /** Keresés gépelés közben: a legjobb találatok rövid részlettel. */
    public static function ajax_keres(): void
    {
        self::jog();

        if (class_exists('SDH_Muhely_Csapat')) {
            SDH_Muhely_Csapat::kontextus_be();
        }

        $q  = self::p('q', 160);
        $ki = [];

        foreach (self::keres($q, 8) as $t) {
            $ki[] = [
                'tipus'   => $t['tipus'] === 'gyik' ? 'Kérdés–válasz' : 'Dokumentáció',
                'cim'     => (string) $t['cim'],
                'fejezet' => (string) $t['fejezet_cim'],
                'reszlet' => self::reszlet((string) $t['torzs'], $q, 150),
                'url'     => self::talalat_url($t),
            ];
        }

        wp_send_json_success([
            'q'        => $q,
            'talalatok' => $ki,
            'mind'     => SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'kereses', 'q' => $q]),
        ]);
    }

    /** „Hasznos volt?" – felhasználónként egy szavazat kérdésenként (átírható). */
    public static function ajax_ertekel(): void
    {
        self::jog();

        $id    = preg_replace('/[^a-z0-9_\-#]/', '', self::p('id', 120));
        $ertek = (int) self::p('ertek', 3) > 0 ? 1 : -1;

        if ($id === '') {
            wp_send_json_error(['uzenet' => 'Hiányzó azonosító.']);
        }

        $user    = get_current_user_id();
        $sajat   = get_user_meta($user, self::META_SZAVAZAT, true);
        $sajat   = is_array($sajat) ? $sajat : [];
        $regi    = (int) ($sajat[$id] ?? 0);
        $e       = self::ertekelesek();
        $e[$id]  = ['jo' => (int) ($e[$id]['jo'] ?? 0), 'nem' => (int) ($e[$id]['nem'] ?? 0)];

        if ($regi === 1) {
            $e[$id]['jo'] = max(0, $e[$id]['jo'] - 1);
        } elseif ($regi === -1) {
            $e[$id]['nem'] = max(0, $e[$id]['nem'] - 1);
        }

        $e[$id][$ertek === 1 ? 'jo' : 'nem']++;
        $sajat[$id] = $ertek;

        update_option(self::OPT_ERTEKELES, $e, false);
        update_user_meta($user, self::META_SZAVAZAT, array_slice($sajat, -400, null, true));

        wp_send_json_success(['id' => $id, 'ertek' => $ertek, 'jo' => $e[$id]['jo'], 'nem' => $e[$id]['nem']]);
    }

    /** Saját kérdés–válasz űrlapja (adminisztrátornak, popupban). */
    public static function ajax_gyik_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Saját kérdés–választ adminisztrátor vehet fel.</div>';
            wp_die();
        }

        global $wpdb;

        $id = (int) self::p('id', 20);
        $r  = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Asszisztens_Tudas::tabla() . " WHERE id = %d AND forras = 'gyik'", $id)) : null;
        // Megválaszolatlan keresésből nyitva: a kérdés előre kitöltve.
        $elore = $r ? '' : self::p('kerdes', 190);

        ?>
        <form class="sdh-urlap sdh-cs-urlap" data-sdh-ajax-action="sdh_muhely_sugogyik_ment" novalidate>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <?php SDH_Muhely_Csapat::kontextus_mezo(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <input type="hidden" name="eredeti" value="<?php echo esc_attr($elore); ?>">
            <h2 class="sdh-modal__cim"><?php echo $r ? 'Saját kérdés–válasz szerkesztése' : 'Új saját kérdés–válasz'; ?></h2>

            <p class="sdh-sugo">Ide a cég saját szabályai kerülhetnek (garancia, árképzés, kinek mit szabad). A Súgó kérdés–válaszai között jelenik meg, és az asszisztens is ebből felel.</p>

            <div class="sdh-ig">
                <label for="sg_kerdes">Kérdés <span class="sdh-kotelezo">*</span></label>
                <input type="text" name="kerdes" id="sg_kerdes" maxlength="190" value="<?php echo esc_attr($r ? (string) $r->cim : $elore); ?>" placeholder="pl. Mennyi garanciát adunk kijelzőcserére?" autocomplete="off">
            </div>
            <div class="sdh-ig">
                <label for="sg_valasz">Válasz <span class="sdh-kotelezo">*</span></label>
                <textarea name="valasz" id="sg_valasz" rows="8" maxlength="8000"><?php echo esc_textarea($r ? (string) $r->szoveg : ''); ?></textarea>
                <span class="sdh-mezo__sugo">Használható: **félkövér**, felsorolás (- jellel), számozott lista (1. 2. 3.).</span>
            </div>
            <div class="sdh-ig">
                <label for="sg_kulcs">Kulcsszavak</label>
                <input type="text" name="kulcsszavak" id="sg_kulcs" maxlength="255" value="<?php echo esc_attr($r ? (string) $r->kulcsszavak : ''); ?>" placeholder="vesszővel: garancia, kijelző, csere" autocomplete="off">
            </div>
            <div class="sdh-cs-kapcsolok">
                <label class="sdh-cs-kapcs"><input type="checkbox" name="aktiv" value="1"<?php checked(!$r || (int) $r->aktiv); ?>><span class="sdh-cs-kapcs__sin"></span><span><strong>Látszik</strong> – kikapcsolva megmarad, de se a Súgó, se az asszisztens nem használja</span></label>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Mentés</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            </div>
        </form>
        <?php
        wp_die();
    }

    public static function ajax_gyik_ment(): void
    {
        self::jog();

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ehhez adminisztrátori jog kell.'], 403);
        }

        SDH_Muhely_Csapat::kontextus_be();

        global $wpdb;

        $id     = (int) self::p('id', 20);
        $kerdes = self::p('kerdes', 190);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a jog() ellenőrizte.
        $valasz = isset($_POST['valasz']) ? mb_substr(trim(sanitize_textarea_field(wp_unslash((string) $_POST['valasz']))), 0, 8000) : '';

        if ($kerdes === '' || $valasz === '') {
            wp_send_json_error(['uzenet' => 'A kérdés és a válasz is kell.']);
        }

        $t    = SDH_Muhely_Asszisztens_Tudas::tabla();
        $adat = ['cim' => $kerdes, 'szoveg' => $valasz, 'kulcsszavak' => self::p('kulcsszavak', 255), 'aktiv' => self::p('aktiv', 2) === '1' ? 1 : 0, 'modositva' => current_time('mysql')];

        if ($id > 0) {
            $wpdb->update($t, $adat, ['id' => $id, 'forras' => 'gyik']);
        } else {
            $wpdb->insert($t, $adat + ['forras' => 'gyik', 'mindig' => 0, 'letrehozo' => get_current_user_id(), 'letrehozva' => current_time('mysql')]);
            $id = (int) $wpdb->insert_id;
        }

        // Ami megválaszolatlan keresésből született, lekerül a listáról.
        $l = self::megvalaszolatlan();

        foreach ([mb_strtolower(self::p('eredeti', 190)), mb_strtolower($kerdes)] as $k) {
            unset($l[$k]);
        }

        update_option(self::OPT_KERESESEK, $l, false);

        wp_send_json_success(['id' => $id, 'vissza' => SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'gyik', 'fejezet' => 'sajat', 'uzenet' => 'mentve'])]);
    }

    /* =================================================================
     * Az oldal
     * ============================================================== */

    /** A csak fejlesztőnek látszó rész jelvénye (ugyanaz a címke, mint a „Saját” kérdés–válaszé). */
    private static function fejleszto_jelveny(): string
    {
        return ' <span class="sdh-cs-cimke sdh-cs-cimke--admin" title="Ezt csak a fejlesztő látja">Csak fejlesztő</span>';
    }

    private static function ikon(string $nev): string
    {
        $rajzok = [
            'nyil'    => '<path d="M7.5 4.5 13 10l-5.5 5.5"/>',
            'vissza'  => '<path d="M12.5 4.5 7 10l5.5 5.5"/>',
            'tura'    => '<circle cx="10" cy="10" r="6.8"/><path d="m12.9 7.1-1.7 4.1-4.1 1.7 1.7-4.1z"/>',
            'konyv'   => '<path d="M10 5.2C8.4 4 6.2 3.6 3.5 4v11c2.7-.4 4.9 0 6.5 1.2 1.6-1.2 3.8-1.6 6.5-1.2V4c-2.7-.4-4.9 0-6.5 1.2z"/><path d="M10 5.2v11"/>',
            'kerdes'  => '<circle cx="10" cy="10" r="6.8"/><path d="M8 8.1a2.1 2.1 0 1 1 2.9 1.9c-.6.3-.9.7-.9 1.3"/><path d="M10 13.8v.1"/>',
            'jo'      => '<path d="M6.5 9.2 9.3 3.5c1.2 0 2 .9 2 2.1v2.2h3.3c.9 0 1.6.8 1.4 1.7l-1 5.1c-.1.7-.7 1.2-1.4 1.2H6.5"/><path d="M3.5 9.2h3v6.6h-3z"/>',
            'nem'     => '<path d="M13.5 10.8 10.7 16.5c-1.2 0-2-.9-2-2.1v-2.2H5.4c-.9 0-1.6-.8-1.4-1.7l1-5.1c.1-.7.7-1.2 1.4-1.2h7.1"/><path d="M16.5 10.8h-3V4.2h3z"/>',
            'nyomtat' => '<path d="M6 7V3.5h8V7"/><rect x="3.5" y="7" width="13" height="6.5" rx="1.4"/><path d="M6 11.5h8v5H6z"/>',
            'kereso'  => '<circle cx="9" cy="9" r="5.2"/><path d="m13 13 3.8 3.8"/>',
            'szikra'  => '<path d="M10 2.8 11.7 8l5.3 2-5.3 2L10 17.2 8.3 12 3 10l5.3-2z"/>',
            'plusz'   => '<path d="M10 4.5v11M4.5 10h11"/>',
        ];

        return '<svg class="sdh-sugo-ikon" viewBox="0 0 20 20" aria-hidden="true">' . ($rajzok[$nev] ?? '') . '</svg>';
    }

    /** @return array<string, array<int, array<string, mixed>>> csoport => fejezetek */
    private static function csoportositva(): array
    {
        $ki = array_fill_keys(self::CSOPORTOK, []);

        foreach (self::fejezetek() as $f) {
            if ((string) $f['kulcs'] === 'sugo') {
                continue; // A súgó saját leírása a Bemutató alján áll.
            }

            $ki[(string) $f['csoport']][] = $f;
        }

        return array_filter($ki);
    }

    private static function lap(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return max(1, isset($_GET['lap']) ? (int) $_GET['lap'] : 1);
    }

    /** @param array<string, mixed> $param */
    private static function lapozo(int $lap, int $osszes, int $egy, array $param): void
    {
        $oldalak = (int) ceil($osszes / $egy);

        if ($oldalak <= 1) {
            return;
        }

        echo '<nav class="sdh-cs-lapozo sdh-cs-lapozo--lap" aria-label="Lapozás">';

        if ($lap > 1) {
            printf('<a class="sdh-gomb sdh-lapnyil" href="%s" aria-label="Előző oldal">%s</a>', esc_url(SDH_Muhely_Modulok::url(self::KULCS, $param + ['lap' => $lap - 1])), self::ikon('vissza')); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        printf('<span>%d / %d</span>', (int) $lap, (int) $oldalak);

        if ($lap < $oldalak) {
            printf('<a class="sdh-gomb sdh-lapnyil" href="%s" aria-label="Következő oldal">%s</a>', esc_url(SDH_Muhely_Modulok::url(self::KULCS, $param + ['lap' => $lap + 1])), self::ikon('nyil')); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '</nav>';
    }

    /** Van-e az oldalnak megnyitható menüpontja (és látja-e a felhasználó). */
    private static function modul_nyithato(string $modul): bool
    {
        if ($modul === 'attekintes') {
            return true;
        }

        $m = SDH_Muhely_Modulok::egy($modul);

        return $m !== null && (empty($m['csak_admin']) || SDH_Muhely_Admin_UI::admin_e());
    }

    /** A fejezet gombjai: megnyitás és túra (ha van hozzá menüpont). */
    private static function fejezet_gombok(array $f, bool $leiras = false): string
    {
        $html = '';

        if ($leiras) {
            $html .= '<a class="sdh-gomb sdh-gomb--pill sdh-gomb--vilagos" href="' . esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'dokumentacio', 'fejezet' => (string) $f['kulcs']])) . '">'
                . self::ikon('konyv') . '<span class="sdh-gomb__felirat">Leírás</span></a>';
        }

        foreach ((array) $f['modulok'] as $modul) {
            if (!self::modul_nyithato((string) $modul)) {
                continue;
            }

            $cel = (string) $modul === 'attekintes' ? '' : (string) $modul;

            if (isset($f['turak'][$modul]) && SDH_Muhely_Modulok::frontenden_vagyunk()) {
                $html .= '<a class="sdh-gomb sdh-gomb--pill sdh-gomb--vilagos" href="' . esc_url(SDH_Muhely_Modulok::url($cel, ['tura' => 1])) . '" title="Megnyitja az oldalt, és lépésről lépésre végigvezet rajta">'
                    . self::ikon('tura') . '<span class="sdh-gomb__felirat">Vezess végig</span></a>';
            }

            $html .= '<a class="sdh-gomb sdh-gomb--pill sdh-gomb--vilagos" href="' . esc_url(SDH_Muhely_Modulok::url($cel)) . '">'
                . self::ikon('nyil') . '<span class="sdh-gomb__felirat">Megnyitás</span></a>';

            break; // Egy fejezet egy menüponthoz ad gombot.
        }

        return $html;
    }

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- csak megjelenítés.
        $nezet = isset($_GET['nezet']) ? sanitize_key(wp_unslash($_GET['nezet'])) : 'bemutato';
        $q     = isset($_GET['q']) ? mb_substr(sanitize_text_field(wp_unslash($_GET['q'])), 0, 160) : '';
        // phpcs:enable

        $fulek = ['bemutato' => 'Bemutató', 'dokumentacio' => 'Dokumentáció', 'gyik' => 'Kérdés–válasz', 'ujdonsagok' => 'Újdonságok'];
        $nezet = isset($fulek[$nezet]) || $nezet === 'kereses' ? $nezet : 'bemutato';
        $admin = SDH_Muhely_Admin_UI::admin_e();

        $gyik_db    = count(self::gyik());
        $fejezet_db = count(self::fejezetek());
        $ai_nev     = class_exists('SDH_Muhely_Asszisztens') ? SDH_Muhely_Asszisztens::beallitas()['nev'] : 'Asszisztens';

        ?>
        <div class="sdh-wrap sdh-wrap--teljes sdh-cs sdh-sugo-oldal" data-sdh-sugo>
            <?php
            SDH_Muhely_Admin_UI::uzenet();

            $gombok = [['cimke' => 'Kérdezd meg: ' . $ai_nev, 'url' => '#', 'elsodleges' => true, 'ikon' => 'asszisztens', 'adatok' => ['sdh-ai-nyit' => '1']]];

            if ($admin && $nezet === 'gyik') {
                $gombok[] = ['cimke' => '+ Saját kérdés–válasz', 'url' => '#', 'adatok' => ['sdh-urlap' => 'sugogyik', 'sdh-id' => '0']];
            }

            SDH_Muhely_Admin_UI::fejlec(
                'Súgó',
                'Bemutató, részletes dokumentáció és kérdés–válasz. Mindig a rendszer mostani állapotát írja le (' . SDH_MUHELY_VERSION . '), és ebből tanul az asszisztens is.',
                $gombok
            );
            ?>

            <form method="get" class="sdh-sugo-kereso" action="<?php echo esc_url(SDH_Muhely_Modulok::urlap_cel(self::KULCS)); ?>" role="search" data-sdh-sugo-kereso>
                <?php SDH_Muhely_Modulok::urlap_rejtett(self::KULCS); ?>
                <input type="hidden" name="nezet" value="kereses">
                <?php echo self::ikon('kereso'); // phpcs:ignore WordPress.Security.EscapeOutput -- saját SVG. ?>
                <input type="search" name="q" value="<?php echo esc_attr($q); ?>" autocomplete="off" maxlength="160"
                       placeholder="Keress bármire: „hogyan zárom a kasszát”, „bevizsgálási díj”, „QR-kód”…"
                       aria-label="Keresés a súgóban" data-sdh-sugo-mezo>
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges sdh-gomb--pill">Keresés</button>
                <div class="sdh-sugo-kereso__lista" data-sdh-sugo-lista hidden></div>
            </form>

            <nav class="sdh-cs-fulek" aria-label="A súgó részei">
                <?php
                foreach ($fulek as $k => $c) {
                    printf(
                        '<a class="sdh-cs-ful%s" href="%s">%s%s</a>',
                        $k === $nezet ? ' is-aktiv' : '',
                        esc_url(SDH_Muhely_Modulok::url(self::KULCS, $k === 'bemutato' ? [] : ['nezet' => $k])),
                        esc_html($c),
                        $k === 'gyik' ? ' <span class="sdh-sugo-db">' . (int) $gyik_db . '</span>' : ($k === 'dokumentacio' ? ' <span class="sdh-sugo-db">' . (int) $fejezet_db . '</span>' : '')
                    );
                }
                ?>
            </nav>

            <?php
            if ($nezet === 'kereses') {
                self::kereses_nezet($q, $ai_nev, $admin);
            } elseif ($nezet === 'dokumentacio') {
                self::dokumentacio_nezet();
            } elseif ($nezet === 'gyik') {
                self::gyik_nezet($ai_nev, $admin);
            } elseif ($nezet === 'ujdonsagok') {
                self::ujdonsagok_nezet();
            } else {
                self::bemutato_nezet($ai_nev, $gyik_db, $fejezet_db);
            }
            ?>
        </div>
        <?php
    }

    /* ---- Bemutató ---- */

    private static function bemutato_nezet(string $ai_nev, int $gyik_db, int $fejezet_db): void
    {
        $sugo    = self::fejezet('sugo');
        $utvonal = $sugo !== null ? (array) $sugo['utvonal'] : [];
        $tura_db = count(self::index()['turak']);

        ?>
        <section class="sdh-sugo-hos">
            <div class="sdh-sugo-hos__szoveg">
                <h2>Ismerd meg a rendszert – a saját tempódban</h2>
                <p>Három út közül választhatsz: <strong>végigvezetlek</strong> az oldalakon, <strong>elolvasod</strong> a részletes leírást, vagy egyszerűen <strong>megkérdezed</strong>, ami épp kell.</p>
                <div class="sdh-sugo-hos__gombok">
                    <?php if (SDH_Muhely_Modulok::frontenden_vagyunk()) : ?>
                        <a class="sdh-gomb sdh-gomb--elsodleges sdh-gomb--pill" href="<?php echo esc_url(SDH_Muhely_Modulok::url('', ['tura' => 1])); ?>"><?php echo self::ikon('tura'); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="sdh-gomb__felirat">Kezdjük az Áttekintéssel</span></a>
                    <?php endif; ?>
                    <a class="sdh-gomb sdh-gomb--pill" href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'dokumentacio', 'fejezet' => 'elso-lepesek'])); ?>"><?php echo self::ikon('konyv'); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="sdh-gomb__felirat">Első lépések</span></a>
                    <a class="sdh-gomb sdh-gomb--pill" href="#" data-sdh-ai-nyit="1"><?php echo self::ikon('szikra'); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="sdh-gomb__felirat">Kérdezd meg: <?php echo esc_html($ai_nev); ?></span></a>
                </div>
            </div>
            <dl class="sdh-sugo-hos__szamok">
                <div><dt><?php echo (int) $fejezet_db; ?></dt><dd>fejezet</dd></div>
                <div><dt><?php echo (int) $gyik_db; ?></dt><dd>kérdés–válasz</dd></div>
                <div><dt><?php echo (int) $tura_db; ?></dt><dd>oldaltúra</dd></div>
            </dl>
        </section>

        <?php if ($utvonal !== []) : ?>
            <h2 class="sdh-sugo-cim">Az első napod – ebben a sorrendben</h2>
            <ol class="sdh-sugo-ut">
                <?php foreach ($utvonal as $i => $l) : ?>
                    <?php
                    $f     = self::fejezet((string) $l['fejezet']);
                    $modul = (string) $l['modul'];
                    ?>
                    <li class="sdh-sugo-ut__lepes">
                        <span class="sdh-sugo-ut__szam" aria-hidden="true"><?php echo (int) $i + 1; ?></span>
                        <h3><?php echo esc_html((string) $l['cim']); ?></h3>
                        <p><?php echo esc_html((string) $l['szoveg']); ?></p>
                        <div class="sdh-sugo-ut__gombok">
                            <?php if ($f !== null) : ?>
                                <a class="sdh-sugo-link" href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'dokumentacio', 'fejezet' => (string) $l['fejezet']])); ?>">Leírás</a>
                            <?php endif; ?>
                            <?php if ($modul !== '' && self::modul_nyithato($modul) && self::tura($modul) !== [] && SDH_Muhely_Modulok::frontenden_vagyunk()) : ?>
                                <a class="sdh-sugo-link" href="<?php echo esc_url(SDH_Muhely_Modulok::url($modul === 'attekintes' ? '' : $modul, ['tura' => 1])); ?>">Vezess végig</a>
                            <?php elseif ($modul !== '' && self::modul_nyithato($modul)) : ?>
                                <a class="sdh-sugo-link" href="<?php echo esc_url(SDH_Muhely_Modulok::url($modul === 'attekintes' ? '' : $modul)); ?>">Megnyitás</a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <h2 class="sdh-sugo-cim">A rendszer térképe</h2>
        <?php foreach (self::csoportositva() as $csoport => $fejezetek) : ?>
            <h3 class="sdh-sugo-csoport"><?php echo esc_html((string) $csoport); ?></h3>
            <div class="sdh-sugo-terkep">
                <?php foreach ($fejezetek as $f) : ?>
                    <article class="sdh-sugo-kartya">
                        <h4><a href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'dokumentacio', 'fejezet' => (string) $f['kulcs']])); ?>"><?php echo esc_html((string) $f['cim']); ?></a></h4>
                        <p><?php echo esc_html((string) $f['osszefoglalo']); ?></p>
                        <p class="sdh-sugo-kartya__meta"><?php echo (int) count((array) $f['szakaszok']); ?> szakasz · <?php echo (int) count((array) $f['gyik']); ?> kérdés</p>
                        <div class="sdh-sugo-kartya__gombok"><?php echo self::fejezet_gombok($f, true); // phpcs:ignore WordPress.Security.EscapeOutput -- szűrt HTML. ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($sugo !== null) : ?>
            <h2 class="sdh-sugo-cim">A súgó használata</h2>
            <div class="sdh-doboz sdh-sugo-torzs">
                <?php
                foreach ((array) $sugo['szakaszok'] as $s) {
                    echo '<h3 class="sdh-sugo-alcim">' . esc_html((string) $s['cim']) . '</h3>' . self::md((string) $s['torzs']); // phpcs:ignore WordPress.Security.EscapeOutput -- az md() szűrt HTML-t ad.
                }
                ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /* ---- Dokumentáció ---- */

    private static function dokumentacio_nezet(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kulcs    = isset($_GET['fejezet']) ? sanitize_key(wp_unslash($_GET['fejezet'])) : '';
        $osszes   = self::fejezetek();
        $sorrend  = array_values(array_filter(array_keys($osszes), static fn (string $k): bool => $k !== 'sugo'));
        $f        = $osszes[$kulcs] ?? ($osszes['elso-lepesek'] ?? reset($osszes));

        if (!is_array($f)) {
            echo '<div class="sdh-doboz"><p class="sdh-sugo sdh-sugo--utolso">A súgó fejezetei nem találhatók (a bővítmény sugo mappája üres).</p></div>';

            return;
        }

        $kulcs = (string) $f['kulcs'];
        $hol   = array_search($kulcs, $sorrend, true);
        $elozo = $hol !== false && $hol > 0 ? $osszes[$sorrend[$hol - 1]] : null;
        $kovetkezo = $hol !== false && $hol < count($sorrend) - 1 ? $osszes[$sorrend[$hol + 1]] : null;

        ?>
        <div class="sdh-sugo-doku">
            <nav class="sdh-sugo-tj" aria-label="Fejezetek">
                <?php foreach (self::csoportositva() as $csoport => $fejezetek) : ?>
                    <p class="sdh-sugo-tj__csoport"><?php echo esc_html((string) $csoport); ?></p>
                    <?php foreach ($fejezetek as $x) : ?>
                        <a class="sdh-sugo-tj__fejezet<?php echo (string) $x['kulcs'] === $kulcs ? ' is-aktiv' : ''; ?>"
                           href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'dokumentacio', 'fejezet' => (string) $x['kulcs']])); ?>"><?php echo esc_html((string) $x['cim']); ?></a>
                        <?php if ((string) $x['kulcs'] === $kulcs) : ?>
                            <div class="sdh-sugo-tj__szakaszok">
                                <?php foreach ((array) $x['szakaszok'] as $s) : ?>
                                    <a href="#<?php echo esc_attr((string) $s['horgony']); ?>"><?php echo esc_html((string) $s['cim']); ?></a>
                                <?php endforeach; ?>
                                <?php if ((array) $x['gyik'] !== []) : ?>
                                    <a href="#gyik">Kérdés–válasz (<?php echo (int) count((array) $x['gyik']); ?>)</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>

            <article class="sdh-sugo-fejezet">
                <header class="sdh-sugo-fejezet__fej">
                    <p class="sdh-sugo-fejezet__kalap"><?php echo esc_html((string) $f['csoport']); ?><?php echo !empty($f['csak_fejleszto']) ? self::fejleszto_jelveny() : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
                    <h2><?php echo esc_html((string) $f['cim']); ?></h2>
                    <p class="sdh-sugo-fejezet__ossz"><?php echo esc_html((string) $f['osszefoglalo']); ?></p>
                    <div class="sdh-sugo-fejezet__gombok">
                        <?php echo self::fejezet_gombok($f); // phpcs:ignore WordPress.Security.EscapeOutput -- szűrt HTML. ?>
                        <button type="button" class="sdh-gomb sdh-gomb--pill sdh-gomb--vilagos" data-sdh-sugo-nyomtat><?php echo self::ikon('nyomtat'); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="sdh-gomb__felirat">Nyomtatás</span></button>
                    </div>
                </header>

                <div class="sdh-sugo-torzs">
                    <?php foreach ((array) $f['szakaszok'] as $s) : ?>
                        <section id="<?php echo esc_attr((string) $s['horgony']); ?>" class="sdh-sugo-szakasz">
                            <h3 class="sdh-sugo-szakasz__cim"><a href="#<?php echo esc_attr((string) $s['horgony']); ?>" aria-label="Hivatkozás erre a szakaszra">#</a><?php echo esc_html((string) $s['cim']); ?><?php echo !empty($s['fejleszto']) ? self::fejleszto_jelveny() : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></h3>
                            <?php echo self::md((string) $s['torzs']); // phpcs:ignore WordPress.Security.EscapeOutput -- az md() szűrt HTML-t ad. ?>
                        </section>
                    <?php endforeach; ?>

                    <?php if ((array) $f['gyik'] !== []) : ?>
                        <section id="gyik" class="sdh-sugo-szakasz">
                            <h3 class="sdh-sugo-szakasz__cim"><a href="#gyik" aria-label="Hivatkozás erre a szakaszra">#</a>Kérdés–válasz</h3>
                            <div class="sdh-sugo-gyik">
                                <?php
                                foreach ((array) $f['gyik'] as $g) {
                                    self::gyik_elem($g + ['fejezet' => $kulcs, 'fejezet_cim' => (string) $f['cim'], 'sajat' => false], false, false);
                                }
                                ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>

                <nav class="sdh-sugo-lepteto" aria-label="Előző és következő fejezet">
                    <?php if ($elozo !== null) : ?>
                        <a class="sdh-sugo-lepteto__e" href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'dokumentacio', 'fejezet' => (string) $elozo['kulcs']])); ?>"><?php echo self::ikon('vissza'); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><small>Előző</small><?php echo esc_html((string) $elozo['cim']); ?></span></a>
                    <?php else : ?>
                        <span></span>
                    <?php endif; ?>
                    <?php if ($kovetkezo !== null) : ?>
                        <a class="sdh-sugo-lepteto__k" href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'dokumentacio', 'fejezet' => (string) $kovetkezo['kulcs']])); ?>"><span><small>Következő</small><?php echo esc_html((string) $kovetkezo['cim']); ?></span><?php echo self::ikon('nyil'); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
                    <?php endif; ?>
                </nav>
            </article>
        </div>
        <?php
    }

    /* ---- Kérdés–válasz ---- */

    /**
     * Egy kérdés–válasz, lenyitható elemként.
     *
     * @param array<string, mixed> $g
     */
    private static function gyik_elem(array $g, bool $nyitva, bool $fejezet_link = true): void
    {
        $id      = (string) $g['id'];
        $e       = self::ertekelesek()[$id] ?? ['jo' => 0, 'nem' => 0];
        $szavazat = get_user_meta(get_current_user_id(), self::META_SZAVAZAT, true);
        $enyem   = is_array($szavazat) ? (int) ($szavazat[$id] ?? 0) : 0;
        $f       = !empty($g['sajat']) ? null : self::fejezet((string) $g['fejezet']);

        ?>
        <details class="sdh-sugo-kv<?php echo !empty($g['sajat']) ? ' sdh-sugo-kv--sajat' : ''; ?>" id="gyik-<?php echo esc_attr($id); ?>"<?php echo $nyitva ? ' open' : ''; ?>>
            <summary>
                <span class="sdh-sugo-kv__kerdes"><?php echo esc_html((string) $g['kerdes']); ?></span>
                <?php if (!empty($g['sajat'])) : ?>
                    <span class="sdh-cs-cimke sdh-cs-cimke--admin">Saját</span>
                <?php endif; ?>
                <?php echo !empty($g['fejleszto']) ? self::fejleszto_jelveny() : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php if (isset($g['aktiv']) && !$g['aktiv']) : ?>
                    <span class="sdh-cs-cimke sdh-cs-cimke--tilt">Rejtve</span>
                <?php endif; ?>
            </summary>
            <div class="sdh-sugo-kv__valasz sdh-sugo-torzs">
                <?php echo self::md((string) $g['valasz']); // phpcs:ignore WordPress.Security.EscapeOutput -- az md() szűrt HTML-t ad. ?>
            </div>
            <footer class="sdh-sugo-kv__lab" data-sdh-sugo-ertekel="<?php echo esc_attr($id); ?>">
                <span class="sdh-sugo-kv__hasznos">Segített?</span>
                <button type="button" class="sdh-sugo-kv__szavaz<?php echo $enyem === 1 ? ' is-aktiv' : ''; ?>" data-ertek="1" aria-pressed="<?php echo $enyem === 1 ? 'true' : 'false'; ?>" title="Igen, segített"><?php echo self::ikon('jo'); // phpcs:ignore WordPress.Security.EscapeOutput ?><b data-sdh-sugo-jo><?php echo (int) $e['jo'] > 0 ? (int) $e['jo'] : ''; ?></b></button>
                <button type="button" class="sdh-sugo-kv__szavaz<?php echo $enyem === -1 ? ' is-aktiv' : ''; ?>" data-ertek="-1" aria-pressed="<?php echo $enyem === -1 ? 'true' : 'false'; ?>" title="Nem, még kérdésem van"><?php echo self::ikon('nem'); // phpcs:ignore WordPress.Security.EscapeOutput ?><b data-sdh-sugo-nem><?php echo (int) $e['nem'] > 0 ? (int) $e['nem'] : ''; ?></b></button>
                <a class="sdh-sugo-link" href="#" data-sdh-ai-nyit="1" data-sdh-ai-kerdes="<?php echo esc_attr((string) $g['kerdes']); ?>">Tovább kérdezek</a>
                <?php if ($fejezet_link && $f !== null) : ?>
                    <a class="sdh-sugo-link" href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'dokumentacio', 'fejezet' => (string) $g['fejezet']])); ?>">Fejezet: <?php echo esc_html((string) $g['fejezet_cim']); ?></a>
                <?php endif; ?>
                <?php if (!empty($g['sajat']) && SDH_Muhely_Admin_UI::admin_e()) : ?>
                    <a class="sdh-sugo-link" href="#" data-sdh-urlap="sugogyik" data-sdh-id="<?php echo (int) $g['tudas_id']; ?>">Szerkesztés</a>
                <?php endif; ?>
            </footer>
        </details>
        <?php
    }

    private static function gyik_nezet(string $ai_nev, bool $admin): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- csak megjelenítés.
        $szuro  = isset($_GET['fejezet']) ? sanitize_key(wp_unslash($_GET['fejezet'])) : '';
        $nyitva = isset($_GET['kerdes']) ? preg_replace('/[^a-z0-9_\-]/', '', (string) wp_unslash($_GET['kerdes'])) : '';
        // phpcs:enable

        // Az adminisztrátor a rejtett saját kérdéseket is látja (szerkesztéshez).
        $mind = $admin ? array_merge(self::sajat_gyik(false), array_values(array_filter(self::gyik(), static fn (array $g): bool => empty($g['sajat'])))) : self::gyik();
        $db   = [];

        foreach ($mind as $g) {
            $db[(string) $g['fejezet']] = ($db[(string) $g['fejezet']] ?? 0) + 1;
        }

        // Közvetlen hivatkozás egy kérdésre: a saját fejezetére szűrve, azon a lapon nyílik, ahol áll.
        if ($nyitva !== '' && $szuro === '') {
            foreach ($mind as $g) {
                if ((string) $g['id'] === $nyitva) {
                    $szuro = (string) $g['fejezet'];
                    break;
                }
            }
        }

        $lista = $szuro !== '' ? array_values(array_filter($mind, static fn (array $g): bool => (string) $g['fejezet'] === $szuro)) : $mind;
        $lap   = self::lap();

        if ($nyitva !== '') {
            foreach ($lista as $i => $g) {
                if ((string) $g['id'] === $nyitva) {
                    $lap = (int) floor($i / self::GYIK_OLDAL) + 1;
                    break;
                }
            }
        }

        $lap = min($lap, max(1, (int) ceil(count($lista) / self::GYIK_OLDAL)));

        ?>
        <div class="sdh-sugo-szurok" role="group" aria-label="Szűrés fejezetre">
            <a class="sdh-sugo-chip<?php echo $szuro === '' ? ' is-aktiv' : ''; ?>" href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'gyik'])); ?>">Mind <b><?php echo (int) count($mind); ?></b></a>
            <?php if (isset($db['sajat'])) : ?>
                <a class="sdh-sugo-chip sdh-sugo-chip--sajat<?php echo $szuro === 'sajat' ? ' is-aktiv' : ''; ?>" href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'gyik', 'fejezet' => 'sajat'])); ?>">Saját kérdések <b><?php echo (int) $db['sajat']; ?></b></a>
            <?php endif; ?>
            <?php foreach (self::fejezetek() as $k => $f) : ?>
                <?php if (isset($db[$k])) : ?>
                    <a class="sdh-sugo-chip<?php echo $szuro === $k ? ' is-aktiv' : ''; ?>" href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'gyik', 'fejezet' => (string) $k])); ?>"><?php echo esc_html((string) $f['cim']); ?> <b><?php echo (int) $db[$k]; ?></b></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($lista === []) : ?>
            <div class="sdh-doboz"><p class="sdh-sugo sdh-sugo--utolso">Ebben a csoportban nincs kérdés.</p></div>
        <?php else : ?>
            <div class="sdh-sugo-gyik">
                <?php
                foreach (array_slice($lista, ($lap - 1) * self::GYIK_OLDAL, self::GYIK_OLDAL) as $g) {
                    self::gyik_elem($g, (string) $g['id'] === $nyitva);
                }
                ?>
            </div>
            <?php self::lapozo($lap, count($lista), self::GYIK_OLDAL, ['nezet' => 'gyik'] + ($szuro !== '' ? ['fejezet' => $szuro] : [])); ?>
        <?php endif; ?>

        <div class="sdh-sugo-nincs">
            <?php echo self::ikon('szikra'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <p><strong>Nincs itt, amit keresel?</strong> Írd be a keresőbe, vagy kérdezd meg <?php echo esc_html($ai_nev); ?>-t: a teljes dokumentációból és a rendszer mostani adataiból felel.</p>
            <a class="sdh-gomb sdh-gomb--pill" href="#" data-sdh-ai-nyit="1">Megkérdezem</a>
        </div>

        <?php
        if ($admin) {
            self::megvalaszolatlan_doboz();
        }
    }

    /** Adminisztrátornak: amire a keresők nem kaptak találatot – ebből lehet saját kérdés–válasz. */
    private static function megvalaszolatlan_doboz(): void
    {
        $l = self::megvalaszolatlan();

        if ($l === []) {
            return;
        }

        ?>
        <div class="sdh-doboz sdh-sugo-hiany">
            <h2 class="sdh-doboz__cim">Amire nem volt találat</h2>
            <p class="sdh-sugo">Ezekre kerestek a kollégák, de a súgó nem adott választ. Ha a cég saját szabályáról van szó, írd meg a választ – attól kezdve a Súgó és az asszisztens is tudja.</p>
            <ul class="sdh-sugo-hiany__lista">
                <?php foreach (array_slice($l, 0, 12, true) as $q => $adat) : ?>
                    <li>
                        <span><?php echo esc_html((string) $q); ?></span>
                        <small><?php echo (int) $adat['db']; ?>× · <?php echo esc_html(mysql2date('m. d.', (string) $adat['ido'])); ?></small>
                        <a class="sdh-gomb sdh-gomb--vilagos" href="#" data-sdh-urlap="sugogyik" data-sdh-id="0" data-sdh-param-kerdes="<?php echo esc_attr((string) $q); ?>">Megválaszolom</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /* ---- Keresés ---- */

    private static function kiemel(string $szoveg, string $q): string
    {
        $html = esc_html($szoveg);

        foreach (SDH_Muhely_Asszisztens_Tudas::szavak($q) as $w) {
            $to = substr((string) $w, 0, max(4, strlen((string) $w) - 2));

            // Ékezetfüggetlen egyezés: a tő betűi helyén bármelyik ékezetes változat állhat.
            $minta = '';

            foreach (str_split($to) as $c) {
                $minta .= ['a' => '[aá]', 'e' => '[eé]', 'i' => '[ií]', 'o' => '[oóöő]', 'u' => '[uúüű]'][$c] ?? preg_quote($c, '/');
            }

            $html = (string) preg_replace('/(?<![\p{L}&])(' . $minta . '[\p{L}\d]*)/iu', '<mark>$1</mark>', $html);
        }

        return $html;
    }

    private static function kereses_nezet(string $q, string $ai_nev, bool $admin): void
    {
        $talalatok = $q !== '' ? self::keres($q, 24) : [];

        if ($q !== '' && $talalatok === []) {
            self::kereses_naploz($q);
        }

        ?>
        <h2 class="sdh-sugo-cim">
            <?php echo $q === '' ? 'Írd be, mit keresel' : esc_html(count($talalatok) . ' találat erre: „' . $q . '”'); ?>
        </h2>

        <?php if ($q !== '' && $talalatok === []) : ?>
            <div class="sdh-sugo-nincs">
                <?php echo self::ikon('szikra'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <p><strong>Erre nincs kész válasz a súgóban.</strong> Próbáld más szóval, vagy kérdezd meg <?php echo esc_html($ai_nev); ?>-t – ő a rendszer mostani adataiból is tud felelni.<?php echo $admin ? '' : ' A keresést feljegyeztem, az adminisztrátor látni fogja.'; ?></p>
                <a class="sdh-gomb sdh-gomb--elsodleges sdh-gomb--pill" href="#" data-sdh-ai-nyit="1" data-sdh-ai-kerdes="<?php echo esc_attr($q); ?>">Megkérdezem</a>
                <?php if ($admin) : ?>
                    <a class="sdh-gomb sdh-gomb--pill" href="#" data-sdh-urlap="sugogyik" data-sdh-id="0" data-sdh-param-kerdes="<?php echo esc_attr($q); ?>">Saját választ írok rá</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="sdh-sugo-talalatok">
            <?php foreach ($talalatok as $t) : ?>
                <a class="sdh-sugo-talalat" href="<?php echo esc_url(self::talalat_url($t)); ?>">
                    <span class="sdh-cs-cimke<?php echo $t['tipus'] === 'gyik' ? ' sdh-cs-cimke--admin' : ''; ?>"><?php echo $t['tipus'] === 'gyik' ? 'Kérdés–válasz' : 'Dokumentáció'; ?></span>
                    <strong><?php echo self::kiemel((string) $t['cim'], $q); // phpcs:ignore WordPress.Security.EscapeOutput -- a kiemel() szűr. ?></strong>
                    <small><?php echo esc_html((string) $t['fejezet_cim']); ?></small>
                    <p><?php echo self::kiemel(self::reszlet((string) $t['torzs'], $q, 260), $q); // phpcs:ignore WordPress.Security.EscapeOutput -- a kiemel() szűr. ?></p>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /* ---- Újdonságok ---- */

    private static function ujdonsagok_nezet(): void
    {
        $verziok = class_exists('SDH_Muhely_Asszisztens_Tudas') ? SDH_Muhely_Asszisztens_Tudas::readme()['verziok'] : [];
        $lap     = self::lap();
        $lap     = min($lap, max(1, (int) ceil(count($verziok) / self::UJDONSAG_OLDAL)));

        if ($verziok === []) {
            echo '<div class="sdh-doboz"><p class="sdh-sugo sdh-sugo--utolso">A verziónapló nem olvasható.</p></div>';

            return;
        }

        echo '<p class="sdh-sugo">Minden verzió minden változása, a legújabb elöl. A jelenlegi verzió: <strong>' . esc_html(SDH_MUHELY_VERSION) . '</strong>.</p>';

        foreach (array_slice($verziok, ($lap - 1) * self::UJDONSAG_OLDAL, self::UJDONSAG_OLDAL, true) as $v => $szoveg) {
            // A nem fejlesztő a műszaki háttérről szóló pontokat nem látja.
            $szoveg = class_exists('SDH_Muhely_Rejtes') ? SDH_Muhely_Rejtes::semlegesit((string) $szoveg) : (string) $szoveg;

            if (trim($szoveg) === '') {
                $szoveg = '*Ebben a verzióban a háttér változott, a felületen nincs újdonság.*';
            }

            echo '<article class="sdh-doboz sdh-sugo-verzio"><h2 class="sdh-doboz__cim">' . esc_html((string) $v)
                . ((string) strtok((string) $v, ' ') === SDH_MUHELY_VERSION ? ' <span class="sdh-cs-cimke sdh-cs-cimke--admin">ez fut most</span>' : '')
                . '</h2><div class="sdh-sugo-torzs">' . self::md((string) $szoveg) . '</div></article>'; // phpcs:ignore WordPress.Security.EscapeOutput -- az md() szűrt HTML-t ad.
        }

        self::lapozo($lap, count($verziok), self::UJDONSAG_OLDAL, ['nezet' => 'ujdonsagok']);
    }
}
