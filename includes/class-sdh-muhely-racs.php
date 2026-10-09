<?php
/**
 * Munkalap-rács a kezdőképernyőn.
 *
 * A MunkaLap 3 főablakának webes megfelelője: fent az összes munkalap egy
 * rácsban (oszloponkénti szűrő, rendezés, keresés a látható oszlopokban,
 * lapozás, összesítő sor, mentett nézetek), alatta a kijelölt munkalap
 * részletei lapfüleken (Munkalap, Eszköz, Ügyfél, Hibák, Szolgáltatások,
 * Termékek, Számlák, Pénztárbizonylatok).
 *
 * Minden a szerveren szűr, rendez és lapoz: a rendszernek 40 ezer
 * munkalappal is azonnal kell válaszolnia, ezért a böngésző mindig csak
 * egy oldalnyi sort kap. A felület (assets/racs.js) csak kirajzol.
 *
 * Az oszlopok egy helyen vannak leírva (oszlopok()): innen tudja a szűrő,
 * a rendezés, a kereső és a JavaScript is, mi létezik. Új oszlop = egy új
 * elem abban a tömbben, plusz az értéke a sor_formaz() függvényben.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Racs
{
    /** A felhasználó mentett nézetei (user meta). */
    private const META_NEZETEK = 'sdh_muhely_racs_nezetek';

    /** Választható oldalméretek. */
    private const MERETEK = [25, 50, 100, 200];

    private const ALAP_MERET = 50;

    /** Legfeljebb ennyi saját nézet menthető felhasználónként. */
    private const MAX_NEZET = 30;

    /** A jelzés értékei (a `--sdh-racs-jelzes` szín az admin.css tetején). */
    private const JELZESEK = ['piros' => 'Megjelölt'];

    /**
     * A még fizetendő összeg: kiegyenlített lapnál nulla, egyébként a
     * bruttó érték mínusz a már befizetett összeg (az előleg). Negatív is
     * lehet – ha az előleg több, mint a tételek értéke –, ahogy a MunkaLap 3-ban.
     */
    private const FIZETENDO = '(CASE WHEN m.fizetve = 1 THEN 0 ELSE m.brutto_ertek - m.fizetett END)';

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_racs_adat', [self::class, 'ajax_adat']);
        add_action('wp_ajax_sdh_muhely_racs_reszlet', [self::class, 'ajax_reszlet']);
        add_action('wp_ajax_sdh_muhely_racs_nezet', [self::class, 'ajax_nezet']);
        add_action('wp_ajax_sdh_muhely_racs_jelzes', [self::class, 'ajax_jelzes']);
    }

    /* =================================================================
     * Oszlopok
     * ============================================================== */

    /**
     * A rács oszlopai, a MunkaLap 3 sorrendjében.
     *
     * Egy oszlop:
     *   cim     – a fejléc felirata;
     *   tipus   – szam | szoveg | datum | penz | halmaz (ettől függ a szűrő);
     *   sql     – a szűrés és a rendezés kifejezése;
     *   rendez  – ha a rendezés más kifejezés szerint megy (pl. név szerint);
     *   sz      – alapszélesség képpontban;
     *   alap    – látszik-e az alapnézetben;
     *   igazit  – 'jobb' a számoknál;
     *   ertekek – halmaznál a választható értékek: [érték => [nev, szin?]];
     *   egesz   – halmaznál az értékek egész számok (felelős, igen/nem).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function oszlopok(): array
    {
        static $oszlopok = null;

        if ($oszlopok !== null) {
            return $oszlopok;
        }

        $allapot_ertekek = [];

        foreach (SDH_Muhely_Munkalap::allapotok() as $kulcs => $allapot) {
            $allapot_ertekek[(string) $kulcs] = ['nev' => $allapot['nev'], 'szin' => $allapot['szin']];
        }

        $felelos_ertekek = ['0' => ['nev' => '(nincs felelős)']];

        foreach (SDH_Muhely_Munkalap::felelosok() as $id => $nev) {
            $felelos_ertekek[(string) $id] = ['nev' => $nev];
        }

        $igen_nem = ['1' => ['nev' => 'Igen'], '0' => ['nev' => 'Nem']];

        $jelzes_ertekek = [];

        foreach (self::JELZESEK as $kulcs => $nev) {
            $jelzes_ertekek[$kulcs] = ['nev' => $nev];
        }

        $jelzes_ertekek[''] = ['nev' => '(nincs jelzés)'];

        $oszlopok = [
            'szam' => [
                'cim' => 'Sorszám', 'tipus' => 'szam', 'sql' => 'm.munkalap_szam',
                'sz' => 98, 'alap' => true, 'igazit' => 'jobb',
            ],
            'jelzes' => [
                'cim' => 'Jelzés', 'tipus' => 'halmaz', 'sql' => "COALESCE(m.jelzes, '')",
                'sz' => 64, 'alap' => true, 'ertekek' => $jelzes_ertekek,
            ],
            'allapot' => [
                'cim' => 'Állapot', 'tipus' => 'halmaz', 'sql' => 'm.allapot',
                'rendez' => self::allapot_sorrend_sql(),
                'sz' => 148, 'alap' => true, 'ertekek' => $allapot_ertekek,
            ],
            'felelos' => [
                'cim' => 'Felelős', 'tipus' => 'halmaz', 'sql' => 'm.felelos',
                'rendez' => 'f.display_name', 'keres' => "COALESCE(f.display_name, '')",
                'sz' => 132, 'alap' => true, 'ertekek' => $felelos_ertekek, 'egesz' => true,
            ],
            'nev' => [
                'cim' => 'Név', 'tipus' => 'szoveg', 'sql' => "COALESCE(m.nev, '')",
                'sz' => 170, 'alap' => false,
            ],
            'azonosito' => [
                'cim' => 'Azonosító', 'tipus' => 'halmaz', 'sql' => "COALESCE(e.kategoria, '')",
                'sz' => 122, 'alap' => true, 'ertekek' => self::kategoria_ertekek(),
            ],
            'gyarto' => [
                'cim' => 'Gyártó', 'tipus' => 'szoveg', 'sql' => "COALESCE(e.gyarto, '')",
                'sz' => 100, 'alap' => true,
            ],
            'tipus' => [
                'cim' => 'Típus', 'tipus' => 'szoveg', 'sql' => "COALESCE(e.tipus, '')",
                'sz' => 150, 'alap' => true,
            ],
            'megnevezes' => [
                'cim' => 'Megnevezés', 'tipus' => 'szoveg', 'sql' => "COALESCE(e.megnevezes, '')",
                'sz' => 170, 'alap' => false,
            ],
            'garancia' => [
                'cim' => 'Garancia', 'tipus' => 'halmaz', 'sql' => 'COALESCE(e.garancias, 0)',
                'sz' => 84, 'alap' => true, 'ertekek' => $igen_nem, 'egesz' => true,
            ],
            'imei' => [
                'cim' => 'IMEI szám', 'tipus' => 'szoveg', 'sql' => "COALESCE(e.imei, '')",
                'sz' => 146, 'alap' => true,
            ],
            'sorozatszam' => [
                'cim' => 'Sorozatszám', 'tipus' => 'szoveg', 'sql' => "COALESCE(e.sorozatszam, '')",
                'sz' => 140, 'alap' => true,
            ],
            'ugyfel' => [
                'cim' => 'Ügyfél', 'tipus' => 'szoveg', 'sql' => "COALESCE(u.nev, '')",
                'sz' => 200, 'alap' => true,
            ],
            'telefon' => [
                'cim' => 'Telefonszám', 'tipus' => 'szoveg', 'sql' => "COALESCE(u.telefon, '')",
                'sz' => 136, 'alap' => true,
            ],
            'keszult' => [
                'cim' => 'Készült', 'tipus' => 'datum', 'sql' => 'm.keszult',
                'sz' => 96, 'alap' => true,
            ],
            'hatarido' => [
                'cim' => 'Határidő', 'tipus' => 'datum', 'sql' => 'm.hatarido',
                'sz' => 96, 'alap' => true,
            ],
            'lezarva' => [
                'cim' => 'Lezárva', 'tipus' => 'datum', 'sql' => 'm.lezarva',
                'sz' => 96, 'alap' => true,
            ],
            'fizetve' => [
                'cim' => 'Fizetve', 'tipus' => 'halmaz', 'sql' => 'm.fizetve',
                'sz' => 78, 'alap' => true, 'ertekek' => $igen_nem, 'egesz' => true,
            ],
            'fizetes_ideje' => [
                'cim' => 'Fizetés ideje', 'tipus' => 'datum', 'sql' => 'm.fizetes_ideje',
                'sz' => 104, 'alap' => true,
            ],
            'brutto' => [
                'cim' => 'Bruttó é.', 'tipus' => 'penz', 'sql' => 'm.brutto_ertek',
                'sz' => 96, 'alap' => true, 'igazit' => 'jobb', 'osszeg' => true,
            ],
            'fizetett' => [
                'cim' => 'Fizetett', 'tipus' => 'penz', 'sql' => 'm.fizetett',
                'sz' => 92, 'alap' => true, 'igazit' => 'jobb', 'osszeg' => true,
            ],
            'fizetendo' => [
                'cim' => 'Fizetendő', 'tipus' => 'penz', 'sql' => self::FIZETENDO,
                'sz' => 96, 'alap' => true, 'igazit' => 'jobb', 'osszeg' => true,
            ],
            'megjegyzes' => [
                'cim' => 'Belső megjegyzés', 'tipus' => 'szoveg', 'sql' => "COALESCE(m.megjegyzes, '')",
                'sz' => 230, 'alap' => true,
            ],
            'fizetesi_mod' => [
                'cim' => 'Fizetési mód', 'tipus' => 'szoveg', 'sql' => "COALESCE(m.fizetesi_mod, '')",
                'sz' => 130, 'alap' => false,
            ],
            'letrehozva' => [
                'cim' => 'Létrehozva', 'tipus' => 'datum', 'sql' => 'm.letrehozva',
                'sz' => 96, 'alap' => false,
            ],
            'modositva' => [
                'cim' => 'Módosítva', 'tipus' => 'datum', 'sql' => 'm.modositva',
                'sz' => 96, 'alap' => false,
            ],
        ];

        return $oszlopok;
    }

    /**
     * Az állapot szerinti rendezés a beállított állapotsorrendet követi,
     * nem az ábécét (CASE, hogy minden adatbázison ugyanúgy fusson).
     */
    private static function allapot_sorrend_sql(): string
    {
        $sql = 'CASE m.allapot';
        $i   = 0;

        foreach (array_keys(SDH_Muhely_Munkalap::allapotok()) as $kulcs) {
            // A kulcsok sanitize_key-en mentek át: csak a-z, 0-9, _ és - lehet bennük.
            $sql .= " WHEN '" . esc_sql((string) $kulcs) . "' THEN " . $i++;
        }

        return $sql . ' ELSE 999 END';
    }

    /**
     * Az „Azonosító" (eszközkategória) választható értékei: az ismert
     * kategóriák, és ami ezeken felül az adatbázisban ténylegesen előfordul.
     *
     * @return array<string, array{nev: string}>
     */
    private static function kategoria_ertekek(): array
    {
        global $wpdb;

        $ertekek = [];

        foreach (SDH_Muhely_Eszkoz::kategoriak() as $kulcs => $felirat) {
            $ertekek[(string) $kulcs] = ['nev' => $felirat];
        }

        $hasznalt = $wpdb->get_col(
            'SELECT DISTINCT kategoria FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' ORDER BY kategoria ASC LIMIT 60'
        );

        foreach ((array) $hasznalt as $kulcs) {
            $kulcs = (string) $kulcs;

            if ($kulcs !== '' && !isset($ertekek[$kulcs])) {
                $ertekek[$kulcs] = ['nev' => SDH_Muhely_Eszkoz::kategoria_cimke($kulcs)];
            }
        }

        $ertekek[''] = ['nev' => '(nincs eszköz)'];

        return $ertekek;
    }

    /**
     * A típusonként engedett szűrőműveletek. A feliratok a racs.js-ben vannak.
     *
     * @return array<string, array<int, string>>
     */
    private static function muveletek(): array
    {
        return [
            'szoveg' => ['tartalmaz', 'nem_tartalmaz', 'egyenlo', 'nem_egyenlo', 'kezdodik', 'vegzodik', 'ures', 'nem_ures'],
            'szam'   => ['kezdodik', 'egyenlo', 'nem_egyenlo', 'nagyobb', 'legalabb', 'kisebb', 'legfeljebb', 'kozott', 'ures', 'nem_ures'],
            'penz'   => ['egyenlo', 'nem_egyenlo', 'nagyobb', 'legalabb', 'kisebb', 'legfeljebb', 'kozott', 'ures', 'nem_ures'],
            'datum'  => ['tartalmaz', 'egyenlo', 'elott', 'utan', 'kozott', 'ma', 'tegnap', 'het', 'honap', 'utolso7', 'utolso30', 'mult', 'ures', 'nem_ures'],
            'halmaz' => ['egyike', 'nem_egyike'],
        ];
    }

    /* =================================================================
     * A kérés állapota (szűrők, rendezés, lapozás) – tisztítás
     * ============================================================== */

    /**
     * A böngészőből érkező állapot megtisztítva: csak ismert oszlop, ismert
     * művelet és korlátos hosszúságú érték marad benne. Ami ezen átmegy,
     * abból már biztonsággal építhető lekérdezés.
     *
     * @param mixed $nyers
     * @return array{oldal: int, meret: int, rendezes: array<int, array{k: string, i: string}>, szurok: array<string, array<string, mixed>>, q: string, lathato: array<int, string>}
     */
    public static function allapot_tisztit($nyers): array
    {
        $nyers     = is_array($nyers) ? $nyers : [];
        $oszlopok  = self::oszlopok();
        $muveletek = self::muveletek();

        $meret = isset($nyers['meret']) ? (int) $nyers['meret'] : self::ALAP_MERET;

        $ki = [
            'oldal'    => isset($nyers['oldal']) ? max(1, (int) $nyers['oldal']) : 1,
            'meret'    => in_array($meret, self::MERETEK, true) ? $meret : self::ALAP_MERET,
            'rendezes' => [],
            'szurok'   => [],
            'q'        => isset($nyers['q']) && is_scalar($nyers['q'])
                ? mb_substr(trim(sanitize_text_field((string) $nyers['q'])), 0, 100)
                : '',
            'lathato'  => [],
        ];

        foreach ((array) ($nyers['rendezes'] ?? []) as $elem) {
            $k = is_array($elem) && isset($elem['k']) && is_scalar($elem['k']) ? (string) $elem['k'] : '';

            if (!isset($oszlopok[$k]) || count($ki['rendezes']) >= 3) {
                continue;
            }

            $ki['rendezes'][] = ['k' => $k, 'i' => ($elem['i'] ?? '') === 'fel' ? 'fel' : 'le'];
        }

        foreach ((array) ($nyers['szurok'] ?? []) as $k => $szuro) {
            $k = (string) $k;

            if (!isset($oszlopok[$k]) || !is_array($szuro)) {
                continue;
            }

            $tipus = (string) $oszlopok[$k]['tipus'];
            $op    = isset($szuro['op']) && is_scalar($szuro['op']) ? (string) $szuro['op'] : '';

            if (!in_array($op, $muveletek[$tipus], true)) {
                continue;
            }

            $tiszta = ['op' => $op];

            foreach (['e', 'e2'] as $mezo) {
                $tiszta[$mezo] = isset($szuro[$mezo]) && is_scalar($szuro[$mezo])
                    ? mb_substr(trim(sanitize_text_field((string) $szuro[$mezo])), 0, 200)
                    : '';
            }

            $tiszta['l'] = [];

            if ($tipus === 'halmaz') {
                foreach ((array) ($szuro['l'] ?? []) as $ertek) {
                    if (!is_scalar($ertek) || count($tiszta['l']) >= 100) {
                        continue;
                    }

                    $ertek = mb_substr((string) $ertek, 0, 60);

                    // Csak a ténylegesen választható értékek maradhatnak.
                    if (isset($oszlopok[$k]['ertekek'][$ertek])) {
                        $tiszta['l'][] = $ertek;
                    }
                }

                if ($tiszta['l'] === []) {
                    continue;
                }
            }

            $ki['szurok'][$k] = $tiszta;
        }

        foreach ((array) ($nyers['lathato'] ?? []) as $k) {
            if (is_scalar($k) && isset($oszlopok[(string) $k])) {
                $ki['lathato'][] = (string) $k;
            }
        }

        return $ki;
    }

    /**
     * Egy mentett nézet elrendezése (oszlopsorrend, szélesség, láthatóság).
     *
     * @param mixed $nyers
     * @return array<int, array{k: string, sz: int, l: bool}>
     */
    private static function elrendezes_tisztit($nyers): array
    {
        $oszlopok = self::oszlopok();
        $ki       = [];
        $volt     = [];

        foreach (is_array($nyers) ? $nyers : [] as $elem) {
            $k = is_array($elem) && isset($elem['k']) && is_scalar($elem['k']) ? (string) $elem['k'] : '';

            if (!isset($oszlopok[$k]) || isset($volt[$k])) {
                continue;
            }

            $volt[$k] = true;
            $ki[]     = [
                'k'  => $k,
                'sz' => min(800, max(40, (int) ($elem['sz'] ?? $oszlopok[$k]['sz']))),
                'l'  => !empty($elem['l']),
            ];
        }

        return $ki;
    }

    /* =================================================================
     * Lekérdezés
     * ============================================================== */

    /** A szöveggé alakított dátum: 2026.10.07 – ebben keres a gépelt szűrő. */
    private static function datum_szoveg_sql(string $sql): string
    {
        return "REPLACE(SUBSTRING(COALESCE({$sql}, ''), 1, 10), '-', '.')";
    }

    /** A dátum napja ISO alakban (a dátum-idő mezőknél is). */
    private static function datum_nap_sql(string $sql): string
    {
        return "SUBSTRING(COALESCE({$sql}, ''), 1, 10)";
    }

    /** Beírt szám értelmezése: szóköz és ezres tagolás nélkül, vessző = tizedes. */
    private static function szam_ertelmez(string $szoveg): ?float
    {
        $tiszta = str_replace(["\u{00a0}", "\u{202f}", ' ', 'Ft', 'ft'], '', $szoveg);
        $tiszta = str_replace(',', '.', $tiszta);

        return is_numeric($tiszta) ? (float) $tiszta : null;
    }

    /**
     * Beírt dátum ISO alakra: 2026.10.07, 2026-10-07, 2026. 10. 07. és
     * 2026/10/07 is jó. Érvénytelen dátumnál null.
     */
    private static function datum_ertelmez(string $szoveg): ?string
    {
        if (preg_match('/^\s*(\d{4})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\s*\.?\s*$/', $szoveg, $t) !== 1) {
            return null;
        }

        if (!checkdate((int) $t[2], (int) $t[3], (int) $t[1])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $t[1], (int) $t[2], (int) $t[3]);
    }

    /**
     * Egyetlen oszlopszűrő SQL-feltétele. A helyőrzők értékei az $ertekek
     * tömb végére kerülnek. Értelmezhetetlen szűrőnél null (nem szűr).
     *
     * @param array<string, mixed> $oszlop
     * @param array<string, mixed> $szuro
     * @param array<int, mixed>    $ertekek
     */
    private static function szuro_sql(array $oszlop, array $szuro, array &$ertekek): ?string
    {
        global $wpdb;

        $sql   = (string) $oszlop['sql'];
        $tipus = (string) $oszlop['tipus'];
        $op    = (string) $szuro['op'];
        $e     = (string) ($szuro['e'] ?? '');
        $e2    = (string) ($szuro['e2'] ?? '');

        /* ---- Halmaz: több érték közül bármelyik ---- */
        if ($tipus === 'halmaz') {
            $lista = (array) ($szuro['l'] ?? []);

            if ($lista === []) {
                return null;
            }

            $egesz = !empty($oszlop['egesz']);

            foreach ($lista as $ertek) {
                $ertekek[] = $egesz ? (int) $ertek : (string) $ertek;
            }

            $helyorzok = implode(', ', array_fill(0, count($lista), $egesz ? '%d' : '%s'));

            return $sql . ($op === 'nem_egyike' ? ' NOT IN (' : ' IN (') . $helyorzok . ')';
        }

        /* ---- Szöveg ---- */
        if ($tipus === 'szoveg') {
            switch ($op) {
                case 'ures':
                    return "{$sql} = ''";
                case 'nem_ures':
                    return "{$sql} <> ''";
            }

            if ($e === '') {
                return null;
            }

            $minta = $wpdb->esc_like($e);

            switch ($op) {
                case 'tartalmaz':
                    $ertekek[] = '%' . $minta . '%';

                    return "{$sql} LIKE %s";
                case 'nem_tartalmaz':
                    $ertekek[] = '%' . $minta . '%';

                    return "{$sql} NOT LIKE %s";
                case 'kezdodik':
                    $ertekek[] = $minta . '%';

                    return "{$sql} LIKE %s";
                case 'vegzodik':
                    $ertekek[] = '%' . $minta;

                    return "{$sql} LIKE %s";
                case 'egyenlo':
                    $ertekek[] = $e;

                    return "{$sql} = %s";
                case 'nem_egyenlo':
                    $ertekek[] = $e;

                    return "{$sql} <> %s";
            }

            return null;
        }

        /* ---- Szám (munkalapszám) és pénz ---- */
        if ($tipus === 'szam' || $tipus === 'penz') {
            $penz = $tipus === 'penz';

            switch ($op) {
                case 'ures':
                    return $penz ? "{$sql} = 0" : "{$sql} IS NULL";
                case 'nem_ures':
                    return $penz ? "{$sql} <> 0" : "{$sql} IS NOT NULL";
            }

            // A munkalapszámnál a gépelés a szám elejére keres (349 → 349xx).
            if ($op === 'kezdodik') {
                $jegyek = preg_replace('/\D+/', '', $e) ?? '';

                if ($jegyek === '') {
                    return null;
                }

                $ertekek[] = $jegyek . '%';

                return "CAST({$sql} AS CHAR) LIKE %s";
            }

            $a = self::szam_ertelmez($e);

            if ($a === null) {
                return null;
            }

            $jelek = [
                'egyenlo' => '=', 'nem_egyenlo' => '<>', 'nagyobb' => '>',
                'legalabb' => '>=', 'kisebb' => '<', 'legfeljebb' => '<=',
            ];

            if (isset($jelek[$op])) {
                $ertekek[] = $a;

                return "{$sql} {$jelek[$op]} %f";
            }

            if ($op === 'kozott') {
                $b = self::szam_ertelmez($e2);

                if ($b === null) {
                    return null;
                }

                $ertekek[] = min($a, $b);
                $ertekek[] = max($a, $b);

                return "({$sql} >= %f AND {$sql} <= %f)";
            }

            return null;
        }

        /* ---- Dátum ---- */
        $nap = self::datum_nap_sql($sql);
        $ma  = current_time('Y-m-d');

        $kozott = static function (string $tol, string $ig) use ($nap, &$ertekek): string {
            $ertekek[] = $tol;
            $ertekek[] = $ig;

            return "({$nap} >= %s AND {$nap} <= %s)";
        };

        switch ($op) {
            case 'ures':
                return "({$sql} IS NULL OR {$nap} = '' OR {$nap} = '0000-00-00')";
            case 'nem_ures':
                return "({$sql} IS NOT NULL AND {$nap} <> '' AND {$nap} <> '0000-00-00')";
            case 'ma':
                return $kozott($ma, $ma);
            case 'tegnap':
                $tegnap = gmdate('Y-m-d', strtotime($ma . ' -1 day'));

                return $kozott($tegnap, $tegnap);
            case 'het':
                // Hétfőtől vasárnapig, a mai napot tartalmazó hét.
                $hetfo = gmdate('Y-m-d', strtotime($ma . ' -' . ((int) gmdate('N', strtotime($ma)) - 1) . ' day'));

                return $kozott($hetfo, gmdate('Y-m-d', strtotime($hetfo . ' +6 day')));
            case 'honap':
                return $kozott(substr($ma, 0, 8) . '01', gmdate('Y-m-t', strtotime($ma)));
            case 'utolso7':
                return $kozott(gmdate('Y-m-d', strtotime($ma . ' -6 day')), $ma);
            case 'utolso30':
                return $kozott(gmdate('Y-m-d', strtotime($ma . ' -29 day')), $ma);
            case 'mult':
                $ertekek[] = $ma;

                return "({$nap} <> '' AND {$nap} <> '0000-00-00' AND {$nap} < %s)";
        }

        if ($e === '') {
            return null;
        }

        if ($op === 'tartalmaz') {
            // Gépelés közben részdátumra is keres: „2026.10" az egész hónap.
            $ertekek[] = '%' . $wpdb->esc_like(str_replace(['-', '/', ' '], ['.', '.', ''], $e)) . '%';

            return self::datum_szoveg_sql($sql) . ' LIKE %s';
        }

        $a = self::datum_ertelmez($e);

        if ($a === null) {
            return null;
        }

        switch ($op) {
            case 'egyenlo':
                return $kozott($a, $a);
            case 'elott':
                $ertekek[] = $a;

                return "({$nap} <> '' AND {$nap} <> '0000-00-00' AND {$nap} < %s)";
            case 'utan':
                $ertekek[] = $a;

                return "{$nap} > %s";
            case 'kozott':
                $b = self::datum_ertelmez($e2);

                if ($b === null) {
                    return null;
                }

                return $kozott(min($a, $b), max($a, $b));
        }

        return null;
    }

    /**
     * A „Keresés a látható oszlopokban" feltétele: a beírt szöveg bármelyik
     * látható oszlopban előfordulhat. Halmaz-oszlopnál (állapot, felelős…)
     * a feliratban keres, és a találó értékekre szűr.
     *
     * @param array<int, string> $lathato
     * @param array<int, mixed>  $ertekek
     */
    private static function kereso_sql(string $q, array $lathato, array &$ertekek): ?string
    {
        global $wpdb;

        if ($q === '') {
            return null;
        }

        $oszlopok = self::oszlopok();
        $lathato  = $lathato !== [] ? $lathato : array_keys(array_filter(
            $oszlopok,
            static fn (array $o): bool => !empty($o['alap'])
        ));

        $minta  = '%' . $wpdb->esc_like($q) . '%';
        $kicsi  = mb_strtolower($q);
        $reszek = [];

        foreach ($lathato as $k) {
            $oszlop = $oszlopok[$k];
            $sql    = (string) $oszlop['sql'];

            switch ($oszlop['tipus']) {
                case 'szoveg':
                    $reszek[]  = "{$sql} LIKE %s";
                    $ertekek[] = $minta;
                    break;

                case 'szam':
                    if (preg_match('/^\d+$/', $q) === 1) {
                        $reszek[]  = "CAST({$sql} AS CHAR) LIKE %s";
                        $ertekek[] = $minta;
                    }
                    break;

                case 'penz':
                    $szam = self::szam_ertelmez($q);

                    if ($szam !== null) {
                        $reszek[]  = "{$sql} = %f";
                        $ertekek[] = $szam;
                    }
                    break;

                case 'datum':
                    if (preg_match('/^[\d.\-\/ ]+$/', $q) === 1) {
                        $reszek[]  = self::datum_szoveg_sql($sql) . ' LIKE %s';
                        $ertekek[] = '%' . $wpdb->esc_like(str_replace(['-', '/', ' '], ['.', '.', ''], $q)) . '%';
                    }
                    break;

                case 'halmaz':
                    $talalo = [];

                    foreach ((array) $oszlop['ertekek'] as $ertek => $adat) {
                        $nev = (string) $adat['nev'];

                        // A „(nincs …)" sorok nem keresőszavak.
                        if ($nev !== '' && $nev[0] !== '(' && mb_strpos(mb_strtolower($nev), $kicsi) !== false) {
                            $talalo[] = (string) $ertek;
                        }
                    }

                    if ($talalo !== []) {
                        $egesz = !empty($oszlop['egesz']);

                        foreach ($talalo as $ertek) {
                            $ertekek[] = $egesz ? (int) $ertek : $ertek;
                        }

                        $reszek[] = $sql . ' IN ('
                            . implode(', ', array_fill(0, count($talalo), $egesz ? '%d' : '%s')) . ')';
                    }
                    break;
            }
        }

        // Ha a keresett szó egyik látható oszlopban sem értelmezhető, nincs találat.
        return $reszek === [] ? '1 = 0' : '(' . implode(' OR ', $reszek) . ')';
    }

    /**
     * A teljes WHERE: oszlopszűrők ÉS kereső.
     *
     * Az első feltétel szándékosan helyőrzős (`1 = %d`): így a lekérdezés
     * mindig a prepare()-en megy át, akkor is, ha nincs egyetlen szűrő sem.
     *
     * @param array<string, mixed> $allapot Az allapot_tisztit() eredménye.
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function feltetelek(array $allapot): array
    {
        $oszlopok   = self::oszlopok();
        $feltetelek = ['1 = %d'];
        $ertekek    = [1];

        foreach ($allapot['szurok'] as $k => $szuro) {
            $sql = self::szuro_sql($oszlopok[$k], $szuro, $ertekek);

            if ($sql !== null) {
                $feltetelek[] = $sql;
            }
        }

        $kereso = self::kereso_sql((string) $allapot['q'], (array) $allapot['lathato'], $ertekek);

        if ($kereso !== null) {
            $feltetelek[] = $kereso;
        }

        return [implode(' AND ', $feltetelek), $ertekek];
    }

    /**
     * A FROM és a JOIN-ok: munkalap, ügyfél, eszköz, felelős.
     *
     * Ha megadjuk a feltételeket, csak az a tábla kapcsolódik, amelyikre a
     * szűrés tényleg hivatkozik: a darabszámhoz és az összesítéshez így nem
     * kell 40 ezer sorhoz fölöslegesen ügyfelet és eszközt keresni. (A
     * feltételben csak rögzített oszlopkifejezések és helyőrzők vannak, a
     * beírt érték nem – ezért az álnév keresése megbízható.)
     */
    private static function forras_sql(?string $csak_ehhez = null): string
    {
        global $wpdb;

        $kell = static fn (string $alnev): bool => $csak_ehhez === null
            || preg_match('/\\b' . $alnev . '\\./', $csak_ehhez) === 1;

        return 'FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' m'
            . ($kell('u') ? ' LEFT JOIN ' . SDH_Muhely_Schema::tabla('ugyfel') . ' u ON u.id = m.ugyfel_id' : '')
            . ($kell('e') ? ' LEFT JOIN ' . SDH_Muhely_Schema::tabla('eszkoz') . ' e ON e.id = m.eszkoz_id' : '')
            . ($kell('f') ? ' LEFT JOIN ' . $wpdb->users . ' f ON f.ID = m.felelos' : '');
    }

    /**
     * Az ORDER BY. Az üres érték mindig a végére kerül, bármelyik irányban
     * rendezünk; az utolsó kulcs az azonosító, hogy a sorrend lapozáskor
     * se változzon.
     *
     * Csökkenő rendezésnél az adatbázis az üres értéket magától a végére
     * teszi, ezért ott nem kell külön kifejezés – így az alapnézet
     * (munkalapszám szerint csökkenő) a szám indexéről olvashat.
     *
     * @param array<int, array{k: string, i: string}> $rendezes
     */
    private static function rendezes_sql(array $rendezes): string
    {
        $oszlopok = self::oszlopok();
        $reszek   = [];

        if ($rendezes === []) {
            $rendezes = [['k' => 'szam', 'i' => 'le']];
        }

        foreach ($rendezes as $elem) {
            $oszlop = $oszlopok[$elem['k']];
            $kif    = (string) ($oszlop['rendez'] ?? $oszlop['sql']);
            $irany  = $elem['i'] === 'fel' ? 'ASC' : 'DESC';

            if ($irany === 'ASC') {
                $reszek[] = "({$kif} IS NULL) ASC";
            }

            $reszek[] = "{$kif} {$irany}";
        }

        $reszek[] = 'm.id DESC';

        return 'ORDER BY ' . implode(', ', $reszek);
    }

    /**
     * Egy oldalnyi sor, a találatok száma és az összesítő sor.
     *
     * @param array<string, mixed> $allapot Az allapot_tisztit() eredménye.
     * @return array<string, mixed>
     */
    public static function adat(array $allapot): array
    {
        global $wpdb;

        [$hol, $ertekek] = self::feltetelek($allapot);

        $forras    = self::forras_sql();
        $szukitett = self::forras_sql($hol);
        $fizetendo = self::FIZETENDO;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a $hol csak helyőrzőket és rögzített oszlopkifejezéseket tartalmaz.
        $ossz = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS db,
                        COALESCE(SUM(m.brutto_ertek), 0) AS brutto,
                        COALESCE(SUM(m.fizetett), 0) AS fizetett,
                        COALESCE(SUM({$fizetendo}), 0) AS fizetendo
                 {$szukitett} WHERE {$hol}",
                $ertekek
            )
        );

        $osszesen = $ossz ? (int) $ossz->db : 0;
        $meret    = (int) $allapot['meret'];
        $oldalak  = max(1, (int) ceil($osszesen / $meret));
        $oldal    = min($oldalak, max(1, (int) $allapot['oldal']));

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.id, m.munkalap_szam, m.jelzes, m.allapot, m.nev, m.felelos, m.keszult,
                        m.hatarido, m.lezarva, m.fizetve, m.fizetes_ideje, m.brutto_ertek,
                        m.fizetett, m.fizetesi_mod, m.megjegyzes, m.letrehozva, m.modositva,
                        m.ugyfel_id, m.eszkoz_id,
                        {$fizetendo} AS fizetendo,
                        u.nev AS u_nev, u.telefon AS u_telefon,
                        e.kategoria AS e_kategoria, e.gyarto AS e_gyarto, e.tipus AS e_tipus,
                        e.megnevezes AS e_megnevezes, e.garancias AS e_garancias,
                        e.imei AS e_imei, e.sorozatszam AS e_sorozatszam,
                        f.display_name AS f_nev
                 {$forras} WHERE {$hol} " . self::rendezes_sql($allapot['rendezes']) . ' LIMIT %d OFFSET %d',
                array_merge($ertekek, [$meret, ($oldal - 1) * $meret])
            )
        );
        // phpcs:enable

        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $ma        = current_time('Y-m-d');
        $ki        = [];

        foreach ((array) $sorok as $sor) {
            $ki[] = self::sor_formaz($sor, $allapotok, $ma);
        }

        return [
            'sorok'    => $ki,
            'osszesen' => $osszesen,
            'oldal'    => $oldal,
            'oldalak'  => $oldalak,
            'meret'    => $meret,
            'osszegek' => [
                'brutto'    => $ossz ? (float) $ossz->brutto : 0.0,
                'fizetett'  => $ossz ? (float) $ossz->fizetett : 0.0,
                'fizetendo' => $ossz ? (float) $ossz->fizetendo : 0.0,
            ],
        ];
    }

    /** Dátum a rácsban: 2026.10.07 – üres, ha nincs. */
    private static function datum(?string $ertek): string
    {
        $ertek = (string) $ertek;

        if ($ertek === '' || str_starts_with($ertek, '0000')) {
            return '';
        }

        return str_replace('-', '.', substr($ertek, 0, 10));
    }

    private static function datumido(?string $ertek): string
    {
        $ertek = (string) $ertek;

        if ($ertek === '' || str_starts_with($ertek, '0000')) {
            return '';
        }

        return str_replace('-', '.', substr($ertek, 0, 10)) . ' ' . substr($ertek, 11, 5);
    }

    /**
     * Egy adatbázissor a rács soraként: oszlopkulcs => megjelenítendő érték.
     *
     * @param array<string, array<string, mixed>> $allapotok
     * @return array<string, mixed>
     */
    private static function sor_formaz(object $sor, array $allapotok, string $ma): array
    {
        $allapot   = $allapotok[(string) $sor->allapot] ?? null;
        $van_eszkoz = (int) $sor->eszkoz_id > 0 && $sor->e_kategoria !== null;
        $megjegyzes = trim((string) preg_replace('/\s+/u', ' ', (string) $sor->megjegyzes));
        $hatarido   = self::datum($sor->hatarido);

        return [
            'id'            => (int) $sor->id,
            'szam'          => (int) $sor->munkalap_szam > 0 ? SDH_Muhely_Munkalap::szam_formaz($sor->munkalap_szam) : '',
            'jelzes'        => (string) $sor->jelzes,
            'allapot'       => (string) $sor->allapot,
            'felelos'       => (string) ($sor->f_nev ?? ''),
            'nev'           => (string) $sor->nev,
            'azonosito'     => $van_eszkoz ? SDH_Muhely_Eszkoz::kategoria_cimke((string) $sor->e_kategoria) : '',
            'gyarto'        => (string) ($sor->e_gyarto ?? ''),
            'tipus'         => (string) ($sor->e_tipus ?? ''),
            'megnevezes'    => (string) ($sor->e_megnevezes ?? ''),
            'garancia'      => $van_eszkoz ? ((int) $sor->e_garancias === 1 ? 'Igen' : 'Nem') : '',
            'imei'          => (string) ($sor->e_imei ?? ''),
            'sorozatszam'   => (string) ($sor->e_sorozatszam ?? ''),
            'ugyfel'        => (string) ($sor->u_nev ?? ''),
            'telefon'       => (string) ($sor->u_telefon ?? ''),
            'keszult'       => self::datum($sor->keszult),
            'hatarido'      => $hatarido,
            'lezarva'       => self::datum($sor->lezarva),
            'fizetve'       => (int) $sor->fizetve === 1 ? 1 : 0,
            'fizetes_ideje' => self::datum($sor->fizetes_ideje),
            'brutto'        => (float) $sor->brutto_ertek,
            'fizetett'      => (float) $sor->fizetett,
            'fizetendo'     => (float) $sor->fizetendo,
            'megjegyzes'    => mb_strlen($megjegyzes) > 140 ? mb_substr($megjegyzes, 0, 140) . '…' : $megjegyzes,
            'fizetesi_mod'  => (string) ($sor->fizetesi_mod ?? ''),
            'letrehozva'    => self::datum($sor->letrehozva),
            'modositva'     => self::datum($sor->modositva),
            '_zart'         => $allapot !== null && !empty($allapot['zart']),
            '_lejart'       => $hatarido !== ''
                && str_replace('.', '-', $hatarido) < $ma
                && $allapot !== null
                && !empty($allapot['szamozott'])
                && empty($allapot['zart']),
        ];
    }

    /* =================================================================
     * Nézetek
     * ============================================================== */

    /**
     * A beépített nézetek. A szűrők az éppen beállított állapotlistából
     * épülnek, ezért átnevezett vagy új állapotnál is helyesek maradnak.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function beepitett_nezetek(): array
    {
        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $nyitott   = SDH_Muhely_Munkalap::nyitott_kulcsok($allapotok);
        $szamlalan = [];

        foreach ($allapotok as $kulcs => $allapot) {
            if (empty($allapot['szamozott'])) {
                $szamlalan[] = (string) $kulcs;
            }
        }

        $szam_le = [['k' => 'szam', 'i' => 'le']];

        $nezetek = [
            ['id' => 'mind', 'nev' => 'Minden munkalap', 'szurok' => [], 'rendezes' => $szam_le],
        ];

        if ($nyitott !== []) {
            $nezetek[] = [
                'id' => 'nyitott', 'nev' => 'Nyitott munkalapok', 'rendezes' => $szam_le,
                'szurok' => ['allapot' => ['op' => 'egyike', 'l' => $nyitott]],
            ];
        }

        if (isset($allapotok['elkeszult'])) {
            $nezetek[] = [
                'id' => 'elkeszult', 'nev' => $allapotok['elkeszult']['nev'] . ' – átvételre vár', 'rendezes' => $szam_le,
                'szurok' => ['allapot' => ['op' => 'egyike', 'l' => ['elkeszult']]],
            ];
        }

        $nezetek[] = [
            'id' => 'fizetendo', 'nev' => 'Fizetendő', 'rendezes' => [['k' => 'fizetendo', 'i' => 'le']],
            'szurok' => ['fizetendo' => ['op' => 'nagyobb', 'e' => '0']],
        ];

        if ($nyitott !== []) {
            $nezetek[] = [
                'id' => 'lejart', 'nev' => 'Lejárt határidő', 'rendezes' => [['k' => 'hatarido', 'i' => 'fel']],
                'szurok' => [
                    'hatarido' => ['op' => 'mult'],
                    'allapot'  => ['op' => 'egyike', 'l' => $nyitott],
                ],
            ];
        }

        $nezetek[] = [
            'id' => 'mai', 'nev' => 'Ma készült', 'rendezes' => $szam_le,
            'szurok' => ['keszult' => ['op' => 'ma']],
        ];

        if ($szamlalan !== []) {
            $nezetek[] = [
                'id' => 'ajanlat', 'nev' => 'Árajánlatok és sablonok', 'rendezes' => [['k' => 'letrehozva', 'i' => 'le']],
                'szurok' => ['allapot' => ['op' => 'egyike', 'l' => $szamlalan]],
            ];
        }

        return $nezetek;
    }

    /**
     * A felhasználó mentett nézetei.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sajat_nezetek(): array
    {
        $mentett = get_user_meta(get_current_user_id(), self::META_NEZETEK, true);

        return is_array($mentett) ? array_values($mentett) : [];
    }

    /** Nézet mentése vagy törlése (AJAX). A válasz a friss nézetlista. */
    public static function ajax_nezet(): void
    {
        self::ajax_vedelem();

        $muvelet = isset($_POST['muvelet']) ? sanitize_key(wp_unslash($_POST['muvelet'])) : '';
        $id      = isset($_POST['id']) ? sanitize_key(wp_unslash($_POST['id'])) : '';
        $nezetek = self::sajat_nezetek();

        if ($muvelet === 'torol') {
            $nezetek = array_values(array_filter(
                $nezetek,
                static fn (array $n): bool => ($n['id'] ?? '') !== $id
            ));
        } elseif ($muvelet === 'ment') {
            $nev = isset($_POST['nev']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['nev'])), 0, 60) : '';

            if ($nev === '') {
                wp_send_json_error(['uzenet' => 'Adj nevet a nézetnek.']);
            }

            $nyers   = self::bejovo_json('allapot');
            $allapot = self::allapot_tisztit($nyers);

            $nezet = [
                'id'       => '',
                'nev'      => $nev,
                'szurok'   => $allapot['szurok'],
                'rendezes' => $allapot['rendezes'],
                'q'        => $allapot['q'],
                'meret'    => $allapot['meret'],
                'oszlopok' => self::elrendezes_tisztit($nyers['oszlopok'] ?? []),
            ];

            $talalt = false;

            foreach ($nezetek as $i => $regi) {
                if ($id !== '' && ($regi['id'] ?? '') === $id) {
                    $nezet['id']  = $id;
                    $nezetek[$i]  = $nezet;
                    $talalt       = true;
                    break;
                }
            }

            if (!$talalt) {
                if (count($nezetek) >= self::MAX_NEZET) {
                    wp_send_json_error(['uzenet' => 'Legfeljebb ' . self::MAX_NEZET . ' saját nézet menthető. Törölj egyet, mielőtt újat mentesz.']);
                }

                $nezet['id'] = 's' . substr(md5(uniqid('', true)), 0, 10);
                $nezetek[]   = $nezet;
                $id          = $nezet['id'];
            }
        } else {
            wp_send_json_error(['uzenet' => 'Ismeretlen művelet.']);
        }

        update_user_meta(get_current_user_id(), self::META_NEZETEK, $nezetek);

        wp_send_json_success(['nezetek' => $nezetek, 'id' => $id]);
    }

    /* =================================================================
     * AJAX – közös
     * ============================================================== */

    private static function ajax_vedelem(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        // A frontend nem jár az admin_init-en, de az AJAX igen: a séma itt
        // már biztosan friss. A kontextust a hívó mondja meg.
        SDH_Muhely_Modulok::kontextus_beallit(
            isset($_REQUEST['kontextus']) && sanitize_key(wp_unslash($_REQUEST['kontextus'])) === 'frontend'
                ? 'frontend'
                : 'admin'
        );
    }

    /**
     * JSON-ként beküldött mező tömbbé. Hibás JSON-nál üres tömb.
     *
     * @return array<string, mixed>
     */
    private static function bejovo_json(string $mezo): array
    {
        if (!isset($_POST[$mezo]) || !is_string($_POST[$mezo])) {
            return [];
        }

        $nyers = wp_unslash($_POST[$mezo]);

        if (strlen($nyers) > 60000) {
            return [];
        }

        $adat = json_decode($nyers, true);

        return is_array($adat) ? $adat : [];
    }

    /** Egy oldalnyi sor (AJAX). */
    public static function ajax_adat(): void
    {
        self::ajax_vedelem();

        wp_send_json_success(self::adat(self::allapot_tisztit(self::bejovo_json('allapot'))));
    }

    /** A jelzés (zászló) be- és kikapcsolása egy munkalapon (AJAX). */
    public static function ajax_jelzes(): void
    {
        global $wpdb;

        self::ajax_vedelem();

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $jelzes = isset($_POST['jelzes']) ? sanitize_key(wp_unslash($_POST['jelzes'])) : '';

        if ($jelzes !== '' && !isset(self::JELZESEK[$jelzes])) {
            wp_send_json_error(['uzenet' => 'Ismeretlen jelzés.']);
        }

        $tabla = SDH_Muhely_Schema::tabla('munkalap');

        if ($id <= 0 || (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tabla} WHERE id = %d", $id)) === 0) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen munkalap.']);
        }

        // A jelzés nem tartalmi módosítás: a „Módosítva" dátumot nem írja át.
        if ($wpdb->update($tabla, ['jelzes' => $jelzes], ['id' => $id]) === false) {
            wp_send_json_error(['uzenet' => 'Az adatbázis visszautasította a módosítást.']);
        }

        wp_send_json_success(['id' => $id, 'jelzes' => $jelzes]);
    }

    /* =================================================================
     * A rács helye az oldalon
     * ============================================================== */

    /**
     * A rács és a részletpanel váza. A tartalmat a racs.js rajzolja ki a
     * mellékelt beállításból; JavaScript nélkül a Munkalapok oldal linkje marad.
     */
    public static function megjelenit(): void
    {
        // Biztosíték: a rács az új oszlopokra épül, ezért a sémát itt is
        // ellenőrizzük (egy option-olvasás), bárhonnan is hívják.
        SDH_Muhely_Schema::frissites_ha_kell();

        $oszlopok = [];

        foreach (self::oszlopok() as $kulcs => $oszlop) {
            $elem = [
                'k'      => $kulcs,
                'cim'    => $oszlop['cim'],
                'tipus'  => $oszlop['tipus'],
                'sz'     => $oszlop['sz'],
                'alap'   => !empty($oszlop['alap']),
                'igazit' => $oszlop['igazit'] ?? '',
                'osszeg' => !empty($oszlop['osszeg']),
            ];

            if (isset($oszlop['ertekek'])) {
                $elem['ertekek'] = [];

                foreach ($oszlop['ertekek'] as $ertek => $adat) {
                    $elem['ertekek'][] = [
                        'e'    => (string) $ertek,
                        'nev'  => $adat['nev'],
                        'szin' => $adat['szin'] ?? '',
                    ];
                }
            }

            $oszlopok[] = $elem;
        }

        $allapotok = [];

        foreach (SDH_Muhely_Munkalap::allapotok() as $kulcs => $allapot) {
            $allapotok[] = [
                'k'    => (string) $kulcs,
                'nev'  => $allapot['nev'],
                'szin' => $allapot['szin'],
                'zart' => !empty($allapot['zart']),
            ];
        }

        $beallitas = [
            'oszlopok'     => $oszlopok,
            'muveletek'    => self::muveletek(),
            'allapotok'    => $allapotok,
            'nezetek'      => self::beepitett_nezetek(),
            'sajatNezetek' => self::sajat_nezetek(),
            'meretek'      => self::MERETEK,
            'alapMeret'    => self::ALAP_MERET,
            'urlapModul'   => SDH_Muhely_Munkalap::KULCS,
            'felhasznalo'  => get_current_user_id(),
        ];

        ?>
        <div class="sdh-munkater" data-sdh-racs>
            <script type="application/json" data-sdh-racs-beallitas><?php
                // A JSON_HEX_* jelzők miatt a tartalom nem tud kilépni a script elemből.
                echo wp_json_encode($beallitas, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
            ?></script>

            <noscript>
                <p class="sdh-sugo">
                    A munkalap-rácshoz JavaScript kell. A munkalapok egyszerű listája itt érhető el:
                    <a href="<?php echo esc_url(SDH_Muhely_Modulok::url(SDH_Muhely_Munkalap::KULCS, ['allapot' => 'mind'])); ?>">Munkalapok</a>.
                </p>
            </noscript>
        </div>
        <?php
    }

    /* =================================================================
     * Részletek – a kijelölt munkalap lapfülei
     * ============================================================== */

    /** A kijelölt munkalap minden lapfüle egy válaszban (AJAX). */
    public static function ajax_reszlet(): void
    {
        global $wpdb;

        self::ajax_vedelem();

        $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

        $munkalap = $id > 0
            ? $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $id)
            )
            : null;

        if (!$munkalap) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen munkalap. Lehet, hogy időközben törölték.']);
        }

        $ugyfel = (int) $munkalap->ugyfel_id > 0
            ? $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', (int) $munkalap->ugyfel_id)
            )
            : null;

        $eszkoz = (int) $munkalap->eszkoz_id > 0
            ? $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' WHERE id = %d', (int) $munkalap->eszkoz_id)
            )
            : null;

        $hibak    = SDH_Muhely_Munkalap::hibasorok((int) $munkalap->id);
        $tetelek  = SDH_Muhely_Tetel::lista((int) $munkalap->id);
        $szolg    = array_values(array_filter($tetelek, static fn (object $t): bool => $t->tipus === 'szolgaltatas'));
        $termekek = array_values(array_filter($tetelek, static fn (object $t): bool => $t->tipus === 'termek'));

        $szam      = (int) $munkalap->munkalap_szam > 0 ? SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam) : '';
        $rma_szamlalo = SDH_Muhely_Rma::szamlalo((int) $munkalap->id);
        $szamlalo  = static fn (int $db): string => $db > 0 ? ' (' . $db . ')' : '';
        $ugyfelnev = $ugyfel ? (string) $ugyfel->nev : '';

        $fulek = [
            [
                'k'    => 'munkalap',
                'cim'  => 'Munkalap' . ($szam !== '' ? ' ' . $szam : ''),
                'html' => self::kepernyore([self::class, 'ful_munkalap'], $munkalap, $ugyfel, $eszkoz, $hibak),
            ],
            [
                'k'    => 'eszkoz',
                'cim'  => 'Eszköz' . ($eszkoz ? ' ' . (int) $eszkoz->id : ''),
                'html' => self::kepernyore([self::class, 'ful_eszkoz'], $eszkoz),
            ],
            [
                'k'    => 'ugyfel',
                'cim'  => 'Ügyfél' . ($ugyfel ? ' ' . ((string) $ugyfel->ugyfel_szam !== '' ? (string) $ugyfel->ugyfel_szam : (int) $ugyfel->id) : ''),
                'html' => self::kepernyore([self::class, 'ful_ugyfel'], $ugyfel),
            ],
            [
                'k'    => 'hibak',
                'cim'  => 'Hibák' . $szamlalo(count($hibak)),
                'html' => self::kepernyore([self::class, 'ful_hibak'], $hibak),
            ],
            [
                'k'    => 'szolgaltatasok',
                'cim'  => 'Szolgáltatások' . $szamlalo(count($szolg)),
                'html' => self::kepernyore([self::class, 'ful_tetelek'], $szolg, $ugyfelnev, 'Ehhez a munkalaphoz még nincs szolgáltatás.'),
            ],
            [
                'k'    => 'termekek',
                'cim'  => 'Termékek' . $szamlalo(count($termekek)),
                'html' => self::kepernyore([self::class, 'ful_tetelek'], $termekek, $ugyfelnev, 'Ehhez a munkalaphoz még nincs termék.'),
            ],
            [
                'k'    => 'rma',
                'cim'  => 'RMA / Üzenetek',
                // Jelvény: az üzenetek száma, piros, ha van olvasatlan (racs.js).
                'db'   => $rma_szamlalo['osszes'],
                'uj'   => $rma_szamlalo['uj'] > 0,
                'html' => self::kepernyore([SDH_Muhely_Rma::class, 'munkalap_ful'], $munkalap),
            ],
            [
                'k'    => 'szamlak',
                'cim'  => 'Számlák',
                'html' => self::kepernyore([self::class, 'ful_szamlak']),
            ],
            [
                'k'    => 'penztar',
                'cim'  => 'Pénztárbizonylatok',
                'html' => self::kepernyore([self::class, 'ful_penztar']),
            ],
        ];

        $keszitette = (int) $munkalap->letrehozo > 0 ? get_userdata((int) $munkalap->letrehozo) : false;

        $lablec = 'Munkalap' . ($szam !== '' ? ' ' . $szam : ' (még nincs száma)');

        if ($keszitette || self::datumido($munkalap->letrehozva) !== '') {
            $lablec .= ' | Készítette: ' . implode(', ', array_filter([
                $keszitette ? (string) $keszitette->display_name : '',
                self::datumido($munkalap->letrehozva),
            ]));
        }

        wp_send_json_success([
            'id'     => (int) $munkalap->id,
            'fulek'  => $fulek,
            'lablec' => $lablec,
        ]);
    }

    /**
     * Egy megjelenítő függvény kimenete szövegként.
     *
     * @param callable $fuggveny
     * @param mixed    ...$parameterek
     */
    private static function kepernyore(callable $fuggveny, ...$parameterek): string
    {
        ob_start();
        call_user_func_array($fuggveny, $parameterek);

        return (string) ob_get_clean();
    }

    /** Összeg forintban: 24 000 – ezres tagolással, tizedes nélkül. */
    private static function penz(float $osszeg): string
    {
        return number_format($osszeg, 0, ',', "\u{00a0}");
    }

    /** Mennyiség: fölösleges tizedesek nélkül (1, 2,5). */
    private static function mennyiseg(float $ertek): string
    {
        $szoveg = rtrim(rtrim(number_format($ertek, 3, ',', "\u{00a0}"), '0'), ',');

        return $szoveg === '' ? '0' : $szoveg;
    }

    private static function szazalek(float $ertek): string
    {
        return rtrim(rtrim(number_format($ertek, 2, ',', ''), '0'), ',') . '%';
    }

    /** Irányítószám, település, cím egy sorban. */
    private static function cim(?string $iranyitoszam, ?string $telepules, ?string $cim): string
    {
        $elso = trim(trim((string) $iranyitoszam) . ' ' . trim((string) $telepules));

        return implode(', ', array_filter([$elso, trim((string) $cim)], static fn (string $s): bool => $s !== ''));
    }

    /**
     * Egy „címke: érték" sor az adatlistában. Az érték már kész HTML.
     */
    private static function adatsor(string $cimke, string $ertek_html): void
    {
        printf(
            '<div class="sdh-adatlista__sor"><dt>%s:</dt><dd>%s</dd></div>',
            esc_html($cimke),
            $ertek_html !== '' ? $ertek_html : '<span class="sdh-adatlista__ures">—</span>' // phpcs:ignore WordPress.Security.EscapeOutput
        );
    }

    /** Sima szöveges érték az adatlistához (sortörésekkel). */
    private static function szoveg(?string $ertek): string
    {
        $ertek = trim((string) $ertek);

        return $ertek === '' ? '' : nl2br(esc_html($ertek));
    }

    /**
     * A lapfül bal oldali alfülei (Adatok, Megjegyzés, Csatolt fájlok) és
     * a szerkesztés gombja. A „(!)" jelzi, hogy az alfülön van tartalom.
     *
     * @param array<string, array{cim: string, jel?: bool}> $alfulek
     */
    private static function alful_sav(array $alfulek, string $modul, int $id, string $also_html = ''): void
    {
        $ikonok = [
            'adatok'     => '<rect x="3.5" y="4" width="13" height="12" rx="1.6"/><path d="M3.5 8h13M8 8v8"/>',
            'megjegyzes' => '<path d="M5 3.5h7l3 3v10H5z"/><path d="M7.5 9.5h5M7.5 12.5h5"/>',
            'csatolt'    => '<path d="M13.6 6v7.3a3.6 3.6 0 0 1-7.2 0V5.4a2.4 2.4 0 0 1 4.8 0v6.9a1.2 1.2 0 0 1-2.4 0V6.4"/>',
        ];

        echo '<div class="sdh-reszlet__oldal">';

        if ($id > 0) {
            printf(
                '<a class="sdh-reszlet__szerkeszt" href="#" data-sdh-urlap="%s" data-sdh-id="%d" title="Szerkesztés" aria-label="Szerkesztés">'
                . '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 16l.6-3.2L13.3 4.1a1.5 1.5 0 0 1 2.1 0l.5.5a1.5 1.5 0 0 1 0 2.1L7.2 15.4z"/><path d="M12 5.4l2.6 2.6"/></svg>'
                . '<span>Szerkesztés</span></a>',
                esc_attr($modul),
                (int) $id
            );
        }

        $elso = true;

        foreach ($alfulek as $kulcs => $alful) {
            printf(
                '<button type="button" class="sdh-reszlet__alful%s" data-sdh-alful="%s">'
                . '<svg viewBox="0 0 20 20" aria-hidden="true">%s</svg><span>%s%s</span></button>',
                $elso ? ' is-aktiv' : '',
                esc_attr($kulcs),
                $ikonok[$kulcs] ?? $ikonok['adatok'], // phpcs:ignore WordPress.Security.EscapeOutput
                esc_html($alful['cim']),
                !empty($alful['jel']) ? ' <b class="sdh-reszlet__jel">(!)</b>' : ''
            );

            $elso = false;
        }

        echo $also_html; // phpcs:ignore WordPress.Security.EscapeOutput -- a hívó már szűrt HTML-t ad.
        echo '</div>';
    }

    /** Üres lapfül (nincs eszköz, nincs ügyfél…). */
    private static function ures(string $szoveg): void
    {
        printf('<p class="sdh-reszlet__ures">%s</p>', esc_html($szoveg));
    }

    /**
     * Munkalap-lapfül.
     *
     * @param array<int, object> $hibak
     */
    private static function ful_munkalap(object $munkalap, ?object $ugyfel, ?object $eszkoz, array $hibak): void
    {
        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $megjegyzes = trim((string) $munkalap->megjegyzes);
        $kulso      = trim((string) ($munkalap->ugyfel_megjegyzes ?? ''));

        $brutto    = (float) $munkalap->brutto_ertek;
        $netto     = (float) $munkalap->netto_ertek;
        $fizetett  = (float) $munkalap->fizetett;
        $fizetve   = (int) $munkalap->fizetve === 1;
        // Az előleg mindig levonódik a teljes összegből.
        $fizetendo = $fizetve ? 0.0 : $brutto - $fizetett;

        $kesz = 0;
        $zart = SDH_Muhely_Munkalap::zart_kulcsok(SDH_Muhely_Munkalap::hiba_allapotok());

        foreach ($hibak as $hiba) {
            if (in_array((string) $hiba->allapot, $zart, true)) {
                $kesz++;
            }
        }

        $felelos = SDH_Muhely_Munkalap::felelos_nev((int) $munkalap->felelos);

        echo '<div class="sdh-reszlet__lap">';

        self::alful_sav(
            [
                'adatok'     => ['cim' => 'Adatok'],
                'megjegyzes' => ['cim' => 'Megjegyzés', 'jel' => $megjegyzes !== '' || $kulso !== ''],
            ],
            SDH_Muhely_Munkalap::KULCS,
            (int) $munkalap->id,
            self::fizetes_kiemeles($brutto, $fizetendo, $fizetve)
        );

        echo '<div class="sdh-reszlet__tartalom">';
        echo '<dl class="sdh-adatlista" data-sdh-alpanel="adatok">';

        self::adatsor('Állapot', SDH_Muhely_Munkalap::jelveny((string) $munkalap->allapot, $allapotok));
        self::adatsor(
            'Eszköz',
            $eszkoz
                ? esc_html(SDH_Muhely_Eszkoz::kategoria_cimke((string) $eszkoz->kategoria) . ' – ' . SDH_Muhely_Eszkoz::megnevezes($eszkoz))
                : ''
        );
        self::adatsor('Ügyfélnév', $ugyfel ? esc_html((string) $ugyfel->nev) : '');
        self::adatsor(
            'Cím',
            $ugyfel
                ? esc_html(self::cim($ugyfel->szamlazasi_iranyitoszam, $ugyfel->szamlazasi_telepules, $ugyfel->szamlazasi_cim))
                : ''
        );
        self::adatsor('Telefonszám', $ugyfel ? esc_html((string) $ugyfel->telefon) : '');
        self::adatsor('Felelős', esc_html($felelos));

        if (trim((string) $munkalap->nev) !== '') {
            self::adatsor('Név', esc_html((string) $munkalap->nev));
        }

        self::adatsor('Készült', esc_html(self::datum($munkalap->keszult)));
        self::adatsor('Határidő', esc_html(self::datum($munkalap->hatarido)));
        self::adatsor('Lezárva', esc_html(self::datum($munkalap->lezarva)));
        self::adatsor(
            'Fizetve',
            $fizetve
                ? esc_html(trim('Igen ' . self::datum($munkalap->fizetes_ideje)))
                : esc_html('Nem')
        );
        self::adatsor('Fizetési mód', esc_html((string) ($munkalap->fizetesi_mod ?? '')));
        self::adatsor('Nettó érték', esc_html(self::penz($netto) . ' Ft'));
        self::adatsor('Áfa', esc_html(self::penz($brutto - $netto) . ' Ft'));
        self::adatsor('Bruttó érték', esc_html(self::penz($brutto) . ' Ft'));
        self::adatsor('Fizetett (előleg)', esc_html(self::penz($fizetett) . ' Ft'));
        self::adatsor('Fizetendő', esc_html(self::penz($fizetendo) . ' Ft'));
        self::adatsor(
            'Hibák',
            $hibak !== [] ? esc_html($kesz . ' / ' . count($hibak) . ' kész') : ''
        );
        self::adatsor('Módosítva', esc_html(self::datumido($munkalap->modositva)));

        echo '</dl>';

        echo '<div class="sdh-reszlet__szoveg" data-sdh-alpanel="megjegyzes" hidden>';

        if ($megjegyzes === '' && $kulso === '') {
            self::ures('Ehhez a munkalaphoz nincs megjegyzés.');
        }

        if ($kulso !== '') {
            echo '<h4>Megjegyzés</h4><p>' . self::szoveg($kulso) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }

        if ($megjegyzes !== '') {
            echo '<h4>Belső megjegyzés</h4><p>' . self::szoveg($megjegyzes) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '</div></div></div>';
    }

    /**
     * A munkalap-lapfül bal sávjának alja (a Megjegyzés alatt): a bruttó
     * összeg pirossal, és ha fizetnie kell, a fizetendő összeg kiemelve.
     */
    private static function fizetes_kiemeles(float $brutto, float $fizetendo, bool $fizetve): string
    {
        if ($brutto <= 0 && $fizetendo <= 0) {
            return '';
        }

        $html = '<div class="sdh-reszlet__penz">';
        $html .= '<div class="sdh-reszlet__brutto"><span>Bruttó</span><b>' . esc_html(self::penz($brutto) . ' Ft') . '</b></div>';

        if ($fizetendo > 0) {
            $html .= '<div class="sdh-reszlet__fizetendo"><span>Fizetendő</span><b>' . esc_html(self::penz($fizetendo) . ' Ft') . '</b></div>';
        } elseif ($fizetve) {
            $html .= '<div class="sdh-reszlet__kifizetve">Kifizetve</div>';
        } elseif ($fizetendo < 0) {
            $html .= '<div class="sdh-reszlet__kifizetve">Túlfizetés: ' . esc_html(self::penz(-$fizetendo) . ' Ft') . '</div>';
        }

        return $html . '</div>';
    }

    /** Eszköz-lapfül. */
    private static function ful_eszkoz(?object $eszkoz): void
    {
        if ($eszkoz === null) {
            self::ures('Ehhez a munkalaphoz még nincs eszköz rendelve.');

            return;
        }

        $csatolt    = SDH_Muhely_Csatolmany::lista('eszkoz', (int) $eszkoz->id);
        $megjegyzes = trim((string) $eszkoz->megjegyzes);
        $belso      = trim((string) ($eszkoz->belso_megjegyzes ?? ''));

        $garancia = (int) $eszkoz->garancias === 1 ? 'Igen' : 'Nem';

        if (trim((string) $eszkoz->garancia_allapot) !== '') {
            $garancia .= ' (' . trim((string) $eszkoz->garancia_allapot) . ')';
        }

        echo '<div class="sdh-reszlet__lap">';

        self::alful_sav(
            [
                'adatok'     => ['cim' => 'Adatok'],
                'megjegyzes' => ['cim' => 'Megjegyzés', 'jel' => $megjegyzes !== '' || $belso !== ''],
                'csatolt'    => ['cim' => 'Csatolt fájlok', 'jel' => $csatolt !== []],
            ],
            SDH_Muhely_Eszkoz::KULCS,
            (int) $eszkoz->id
        );

        echo '<div class="sdh-reszlet__tartalom">';
        echo '<dl class="sdh-adatlista" data-sdh-alpanel="adatok">';

        self::adatsor('Azonosító', esc_html(SDH_Muhely_Eszkoz::kategoria_cimke((string) $eszkoz->kategoria)));
        self::adatsor('Gyártó', esc_html((string) $eszkoz->gyarto));
        self::adatsor('Típus', esc_html((string) $eszkoz->tipus));
        self::adatsor('Megnevezés', esc_html((string) $eszkoz->megnevezes));
        self::adatsor('IMEI szám', esc_html((string) $eszkoz->imei));

        if ((string) $eszkoz->imei2 !== '') {
            self::adatsor('IMEI 2', esc_html((string) $eszkoz->imei2));
        }

        self::adatsor('Sorozatszám', esc_html((string) $eszkoz->sorozatszam));
        self::adatsor('Modellszám', esc_html((string) $eszkoz->modell_szam));
        self::adatsor('Szín', esc_html((string) $eszkoz->szin));
        self::adatsor('Garancia', esc_html($garancia));
        self::adatsor('Vásárlás dátuma', esc_html(self::datum($eszkoz->vasarlas_datuma)));
        self::adatsor('Garancia lejár', esc_html(self::datum($eszkoz->garancia_lejar)));
        self::adatsor('Szolgáltató', esc_html((string) $eszkoz->szolgaltato));
        self::adatsor('Zárkód', esc_html((string) $eszkoz->zarkod));

        if ((string) $eszkoz->minta !== '') {
            self::adatsor('Feloldó minta', esc_html(implode('-', str_split((string) $eszkoz->minta))));
        }

        self::adatsor('Tartozékok', esc_html((string) $eszkoz->tartozekok));
        self::adatsor('Átvételi állapot', self::szoveg($eszkoz->atveteli_allapot));

        echo '</dl>';

        echo '<div class="sdh-reszlet__szoveg" data-sdh-alpanel="megjegyzes" hidden>';

        if ($megjegyzes === '' && $belso === '') {
            self::ures('Ehhez az eszközhöz nincs megjegyzés.');
        }

        if ($megjegyzes !== '') {
            echo '<h4>Megjegyzés</h4><p>' . self::szoveg($megjegyzes) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }

        if ($belso !== '') {
            echo '<h4>Belső megjegyzés</h4><p>' . self::szoveg($belso) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '</div>';

        self::csatolt_panel($csatolt);

        echo '</div></div>';
    }

    /** Ügyfél-lapfül. */
    private static function ful_ugyfel(?object $ugyfel): void
    {
        if ($ugyfel === null) {
            self::ures('Ehhez a munkalaphoz még nincs ügyfél rendelve.');

            return;
        }

        $csatolt    = SDH_Muhely_Csatolmany::lista('ugyfel', (int) $ugyfel->id);
        $megjegyzes = trim((string) $ugyfel->megjegyzes);
        $belso      = trim((string) $ugyfel->belso_megjegyzes);

        $kozponti = self::cim($ugyfel->szamlazasi_iranyitoszam, $ugyfel->szamlazasi_telepules, $ugyfel->szamlazasi_cim);
        $azonos   = '<span class="sdh-adatlista__ures">azonos a központi címmel</span>';

        $masodlagos = static function (string $elotag) use ($ugyfel, $azonos): string {
            if (!empty($ugyfel->{$elotag . '_azonos'})) {
                return $azonos;
            }

            $nev = $elotag === 'telephely' ? trim((string) $ugyfel->telephely_nev) : '';
            $cim = self::cim(
                $ugyfel->{$elotag . '_iranyitoszam'},
                $ugyfel->{$elotag . '_telepules'},
                $ugyfel->{$elotag . '_cim'}
            );

            return esc_html(implode(', ', array_filter([$nev, $cim], static fn (string $s): bool => $s !== '')));
        };

        echo '<div class="sdh-reszlet__lap">';

        self::alful_sav(
            [
                'adatok'     => ['cim' => 'Adatok'],
                'megjegyzes' => ['cim' => 'Megjegyzés', 'jel' => $megjegyzes !== '' || $belso !== ''],
                'csatolt'    => ['cim' => 'Csatolt fájlok', 'jel' => $csatolt !== []],
            ],
            SDH_Muhely_Ugyfel::KULCS,
            (int) $ugyfel->id
        );

        echo '<div class="sdh-reszlet__tartalom">';
        echo '<dl class="sdh-adatlista" data-sdh-alpanel="adatok">';

        self::adatsor('Státusz', esc_html((int) $ugyfel->aktiv === 1 ? 'Aktív' : 'Inaktív'));
        self::adatsor('Kategória', esc_html((string) $ugyfel->kategoria));
        self::adatsor('Típus', esc_html(SDH_Muhely_Ugyfel::tipus_cimke((string) $ugyfel->tipus)));
        self::adatsor('Név', esc_html((string) $ugyfel->nev));

        if (trim((string) $ugyfel->kapcsolattarto) !== '' && (string) $ugyfel->kapcsolattarto !== (string) $ugyfel->nev) {
            self::adatsor('Kapcsolattartó', esc_html((string) $ugyfel->kapcsolattarto));
        }

        self::adatsor('Telefonszám', esc_html(implode(', ', array_filter([(string) $ugyfel->telefon, (string) $ugyfel->telefon2]))));
        self::adatsor('E-mail', esc_html((string) $ugyfel->email));
        self::adatsor('Adószám', esc_html((string) $ugyfel->adoszam));
        self::adatsor(
            'Központi cím',
            esc_html(implode(', ', array_filter([(string) $ugyfel->nev, $kozponti], static fn (string $s): bool => $s !== '')))
        );
        self::adatsor('Számlázási cím', esc_html($kozponti));
        self::adatsor('Levelezési cím', $masodlagos('levelezesi'));
        self::adatsor('Szállítási cím', $masodlagos('szallitasi'));
        self::adatsor('Telephely', $masodlagos('telephely'));
        self::adatsor(
            'Kedvezmény',
            (float) $ugyfel->kedvezmeny > 0 ? esc_html(self::szazalek((float) $ugyfel->kedvezmeny)) : ''
        );

        echo '</dl>';

        echo '<div class="sdh-reszlet__szoveg" data-sdh-alpanel="megjegyzes" hidden>';

        if ($megjegyzes === '' && $belso === '') {
            self::ures('Ehhez az ügyfélhez nincs megjegyzés.');
        }

        if ($megjegyzes !== '') {
            echo '<h4>Megjegyzés</h4><p>' . self::szoveg($megjegyzes) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }

        if ($belso !== '') {
            echo '<h4>Belső megjegyzés</h4><p>' . self::szoveg($belso) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '</div>';

        self::csatolt_panel($csatolt);

        echo '</div></div>';
    }

    /**
     * A csatolt fájlok alfüle: a rekord meglévő fájljai megnyitható linkként.
     * Feltölteni a szerkesztő popupban lehet.
     *
     * @param array<int, object> $csatolt
     */
    private static function csatolt_panel(array $csatolt): void
    {
        $megnyithato = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

        echo '<div class="sdh-reszlet__szoveg" data-sdh-alpanel="csatolt" hidden>';

        if ($csatolt === []) {
            self::ures('Nincs csatolt fájl. Feltölteni a szerkesztésnél, a Csatolt fájlok lapfülön lehet.');
        } else {
            echo '<ul class="sdh-reszlet__fajlok">';

            foreach ($csatolt as $fajl) {
                $ext = strtolower((string) pathinfo((string) $fajl->eredeti_nev, PATHINFO_EXTENSION));

                printf(
                    '<li><a href="%s" target="_blank" rel="noopener">%s</a> <span>%s%s</span></li>',
                    esc_url(SDH_Muhely_Csatolmany::url((int) $fajl->id, in_array($ext, $megnyithato, true) ? 'megnyit' : 'letolt')),
                    esc_html((string) $fajl->eredeti_nev),
                    esc_html(size_format((int) $fajl->meret)),
                    !empty($fajl->feltoltve) ? ' · ' . esc_html(self::datumido((string) $fajl->feltoltve)) : ''
                );
            }

            echo '</ul>';
        }

        echo '</div>';
    }

    /**
     * Táblázat a részletpanel lapfülein (hibák, tételek, bizonylatok).
     *
     * @param array<string, array{cim: string, igazit?: string}> $fejlec  oszlopkulcs => fejléc
     * @param array<int, array<string, string>>                  $sorok   kész HTML-cellák oszlopkulcs szerint
     * @param array<string, string>|null                         $osszeg  az összesítő sor cellái (null: nincs ilyen sor)
     */
    private static function tabla(array $fejlec, array $sorok, ?array $osszeg, string $ures_szoveg): void
    {
        echo '<div class="sdh-reszlet__tablahely"><table class="sdh-reszlet__tabla"><thead><tr>';

        foreach ($fejlec as $oszlop) {
            printf(
                '<th%s>%s</th>',
                ($oszlop['igazit'] ?? '') === 'jobb' ? ' class="is-jobb"' : '',
                esc_html($oszlop['cim'])
            );
        }

        echo '</tr></thead><tbody>';

        if ($sorok === []) {
            printf(
                '<tr><td class="sdh-reszlet__tabla-ures" colspan="%d">%s</td></tr>',
                count($fejlec),
                esc_html($ures_szoveg)
            );
        }

        foreach ($sorok as $sor) {
            echo '<tr>';

            foreach ($fejlec as $kulcs => $oszlop) {
                printf(
                    '<td%s>%s</td>',
                    ($oszlop['igazit'] ?? '') === 'jobb' ? ' class="is-jobb"' : '',
                    $sor[$kulcs] ?? '' // phpcs:ignore WordPress.Security.EscapeOutput -- a cellák már kész, szűrt HTML-ek.
                );
            }

            echo '</tr>';
        }

        echo '</tbody>';

        if ($osszeg !== null) {
            echo '<tfoot><tr>';

            $elso = true;

            foreach ($fejlec as $kulcs => $oszlop) {
                $ertek = $osszeg[$kulcs] ?? '';

                printf(
                    '<td%s>%s</td>',
                    ($oszlop['igazit'] ?? '') === 'jobb' ? ' class="is-jobb"' : '',
                    $elso && $ertek === '' ? '<span class="sdh-reszlet__szumma">Σ</span>' : esc_html($ertek)
                );

                $elso = false;
            }

            echo '</tr></tfoot>';
        }

        echo '</table></div>';
    }

    /**
     * Hibák-lapfül.
     *
     * @param array<int, object> $hibak
     */
    private static function ful_hibak(array $hibak): void
    {
        $allapotok = SDH_Muhely_Munkalap::hiba_allapotok();
        $zart      = SDH_Muhely_Munkalap::zart_kulcsok($allapotok);
        $sorok     = [];

        foreach ($hibak as $i => $hiba) {
            $sorok[] = [
                'sorszam'    => (string) (int) $hiba->id,
                'lista'      => (string) ($i + 1),
                'tipus'      => SDH_Muhely_Munkalap::jelveny((string) $hiba->allapot, $allapotok),
                'javitva'    => in_array((string) $hiba->allapot, $zart, true) ? esc_html(self::datum($hiba->modositva)) : '',
                'megnevezes' => esc_html((string) $hiba->leiras),
                'leiras'     => esc_html((string) $hiba->javitas),
            ];
        }

        self::tabla(
            [
                'sorszam'    => ['cim' => 'Sorszám', 'igazit' => 'jobb'],
                'lista'      => ['cim' => 'Listasorsz.', 'igazit' => 'jobb'],
                'tipus'      => ['cim' => 'Típus'],
                'javitva'    => ['cim' => 'Javítva'],
                'megnevezes' => ['cim' => 'Megnevezés'],
                'leiras'     => ['cim' => 'Leírás'],
            ],
            $sorok,
            null,
            'Ehhez a munkalaphoz még nincs hibasor.'
        );
    }

    /**
     * Szolgáltatások / Termékek lapfül – a két tételtípus ugyanazt a táblát kapja.
     *
     * @param array<int, object> $tetelek
     */
    private static function ful_tetelek(array $tetelek, string $vevo, string $ures_szoveg): void
    {
        $tipusok   = SDH_Muhely_Tetel::tipusok();
        $mozgasok  = SDH_Muhely_Tetel::mozgasok();
        $allapotok = SDH_Muhely_Tetel::allapotok();

        $sorok       = [];
        $menny_ossz  = 0.0;
        $netto_ossz  = 0.0;
        $brutto_ossz = 0.0;

        foreach ($tetelek as $tetel) {
            $allapot = $allapotok[(string) $tetel->allapot] ?? ['nev' => (string) $tetel->allapot, 'szin' => 'szurke'];

            $menny_ossz  += (float) $tetel->mennyiseg;
            $netto_ossz  += (float) $tetel->netto_ertek;
            $brutto_ossz += (float) $tetel->brutto_ertek;

            $sorok[] = [
                'sorszam'    => (string) (int) $tetel->id,
                'tipus'      => esc_html($tipusok[(string) $tetel->tipus] ?? (string) $tetel->tipus),
                'mozgas'     => sprintf(
                    '<span class="sdh-mozgas sdh-mozgas--%s">%s</span>',
                    esc_attr((string) $tetel->mozgas),
                    esc_html($mozgasok[(string) $tetel->mozgas] ?? (string) $tetel->mozgas)
                ),
                'allapot'    => sprintf(
                    '<span class="sdh-allapot sdh-allapot--%s">%s</span>',
                    esc_attr($allapot['szin']),
                    esc_html($allapot['nev'])
                ),
                'idopont'    => esc_html(self::datum($tetel->idopont)),
                'szamla'     => esc_html((string) $tetel->szamla),
                'megnevezes' => esc_html((string) $tetel->megnevezes),
                'termekkod'  => esc_html((string) $tetel->termekkod),
                'cikkszam'   => esc_html((string) $tetel->cikkszam),
                'gyari_szam' => esc_html((string) $tetel->gyari_szam),
                'mennyiseg'  => esc_html(self::mennyiseg((float) $tetel->mennyiseg)),
                'me'         => esc_html((string) $tetel->me),
                'munkavegzo' => esc_html(SDH_Muhely_Munkalap::felelos_nev((int) $tetel->munkavegzo)),
                'kedvezmeny' => (float) $tetel->kedvezmeny > 0 ? esc_html(self::szazalek((float) $tetel->kedvezmeny)) : '',
                // Betűkódos kulcsnál (AAM, TAM…) a kód áll, nem a 0%.
                'afa'        => esc_html(
                    is_numeric((string) ($tetel->afa_kulcs ?? '')) || (string) ($tetel->afa_kulcs ?? '') === ''
                        ? self::szazalek((float) $tetel->afa)
                        : (string) $tetel->afa_kulcs
                ),
                'netto_ar'   => esc_html(self::penz((float) $tetel->netto_ar)),
                'brutto_ar'  => esc_html(self::penz((float) $tetel->brutto_ar)),
                'netto_e'    => esc_html(self::penz((float) $tetel->netto_ertek)),
                'brutto_e'   => esc_html(self::penz((float) $tetel->brutto_ertek)),
                'vevo'       => esc_html($vevo),
                'elado'      => esc_html((string) $tetel->elado),
            ];
        }

        self::tabla(
            [
                'sorszam'    => ['cim' => 'Sorszám', 'igazit' => 'jobb'],
                'tipus'      => ['cim' => 'Típus'],
                'mozgas'     => ['cim' => 'Mozgás'],
                'allapot'    => ['cim' => 'Állapot'],
                'idopont'    => ['cim' => 'Időpont'],
                'szamla'     => ['cim' => 'Számla'],
                'megnevezes' => ['cim' => 'Megnevezés'],
                'termekkod'  => ['cim' => 'Termékkód'],
                'cikkszam'   => ['cim' => 'Cikkszám'],
                'gyari_szam' => ['cim' => 'Gyári szám'],
                'mennyiseg'  => ['cim' => 'Menny.', 'igazit' => 'jobb'],
                'me'         => ['cim' => 'M.e.'],
                'munkavegzo' => ['cim' => 'Munkavégző'],
                'kedvezmeny' => ['cim' => 'Kedv.', 'igazit' => 'jobb'],
                'afa'        => ['cim' => 'Áfak.', 'igazit' => 'jobb'],
                'netto_ar'   => ['cim' => 'Nettó ár', 'igazit' => 'jobb'],
                'brutto_ar'  => ['cim' => 'Bruttó ár', 'igazit' => 'jobb'],
                'netto_e'    => ['cim' => 'Nettó é.', 'igazit' => 'jobb'],
                'brutto_e'   => ['cim' => 'Bruttó é.', 'igazit' => 'jobb'],
                'vevo'       => ['cim' => 'Vevő'],
                'elado'      => ['cim' => 'Eladó'],
            ],
            $sorok,
            [
                'mennyiseg' => self::mennyiseg($menny_ossz),
                'netto_e'   => self::penz($netto_ossz),
                'brutto_e'  => self::penz($brutto_ossz),
            ],
            $ures_szoveg
        );
    }

    /**
     * Számlák-lapfül. A számlázó modul még nem készült el: a lapfül a
     * MunkaLap 3 oszlopaival, üresen jelenik meg.
     */
    private static function ful_szamlak(): void
    {
        self::tabla(
            [
                'sorszam'   => ['cim' => 'Sorszám', 'igazit' => 'jobb'],
                'tipus'     => ['cim' => 'Típus'],
                'online'    => ['cim' => 'Online'],
                'cashbook'  => ['cim' => 'Cashbook'],
                'szamla'    => ['cim' => 'Számlasorsz.'],
                'ugyfel'    => ['cim' => 'Ügyfél'],
                'adoszam'   => ['cim' => 'Adószám'],
                'kelte'     => ['cim' => 'Számla kelte'],
                'hatarido'  => ['cim' => 'Határidő'],
                'fizetve'   => ['cim' => 'Fizetve'],
                'fizmod'    => ['cim' => 'Fizetési m.'],
                'netto_e'   => ['cim' => 'Nettó é.', 'igazit' => 'jobb'],
                'brutto_e'  => ['cim' => 'Bruttó é.', 'igazit' => 'jobb'],
                'fizetendo' => ['cim' => 'Fizetendő', 'igazit' => 'jobb'],
                'kapcs'     => ['cim' => 'Kapcs. rekord'],
                'kapcs_sz'  => ['cim' => 'Kapcs. sorsz.'],
            ],
            [],
            ['netto_e' => '0', 'brutto_e' => '0', 'fizetendo' => '0'],
            'Ehhez a munkalaphoz nincs számla.'
        );
    }

    /** Pénztárbizonylatok-lapfül – a pénztár modul még nem készült el. */
    private static function ful_penztar(): void
    {
        self::tabla(
            [
                'sorszam'   => ['cim' => 'Sorszám', 'igazit' => 'jobb'],
                'azonosito' => ['cim' => 'Azonosító'],
                'jelentes'  => ['cim' => 'Pénztárjelentés'],
                'datum'     => ['cim' => 'Dátum'],
                'irany'     => ['cim' => 'Irány'],
                'statusz'   => ['cim' => 'Státusz'],
                'ugyfel'    => ['cim' => 'Ügyfél'],
                'elotte'    => ['cim' => 'Egyenleg előtte', 'igazit' => 'jobb'],
                'bevetel'   => ['cim' => 'Bevétel', 'igazit' => 'jobb'],
                'kiadas'    => ['cim' => 'Kiadás', 'igazit' => 'jobb'],
                'utana'     => ['cim' => 'Egyenleg utána', 'igazit' => 'jobb'],
                'penztaros' => ['cim' => 'Pénztáros'],
                'kapcs'     => ['cim' => 'Kapcs. név'],
                'kapcs_sz'  => ['cim' => 'Kapcs. sorsz.'],
            ],
            [],
            ['bevetel' => '0', 'kiadas' => '0'],
            'Ehhez a munkalaphoz nincs pénztárbizonylat.'
        );
    }
}
