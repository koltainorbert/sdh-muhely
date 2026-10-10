<?php
/**
 * Házipénztár – keresés, riport, statisztika, export.
 *
 * Minden lekérdezés az indexelt oszlopokon (datum, munkalap_szam,
 * szamlaszam) vagy a napi összesítőkön (sdh_penztar_nap) fut, ezért a
 * több tízezer soros múlt sem lassítja.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Penztar_Elemzes
{
    private const KERES_OLDAL = 40;

    public static function init(): void
    {
        foreach (['kereses' => 'ajax_kereses', 'riport' => 'ajax_riport', 'statisztika' => 'ajax_statisztika', 'export' => 'ajax_export'] as $nev => $fv) {
            add_action('wp_ajax_sdh_muhely_penztar_' . $nev, [self::class, $fv]);
        }
    }

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    /** @return mixed */
    private static function be(string $nev, $alap = '')
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- jog() ellenőrizte.
        $v = $_REQUEST[$nev] ?? $alap;

        return is_array($v) ? array_map('sanitize_text_field', wp_unslash($v)) : sanitize_text_field(wp_unslash((string) $v));
    }

    private static function datum(string $d, string $alap): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : $alap;
    }

    /** Időszak a kérésből: tol–ig, alapból az aktuális hónap. */
    private static function idoszak(): array
    {
        $ma  = SDH_Muhely_Penztar::ma();
        $tol = self::datum((string) self::be('tol'), substr($ma, 0, 8) . '01');
        $ig  = self::datum((string) self::be('ig'), $ma);

        if ($tol > $ig) {
            [$tol, $ig] = [$ig, $tol];
        }

        return [$tol, $ig];
    }

    /** Partnernév egységesítése (TMX Posta = TMX (Posta) = TMX - posta). */
    public static function partner_kulcs(string $nev): string
    {
        $k = mb_strtolower(trim($nev), 'UTF-8');
        $k = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $k);

        return $k;
    }

    /* =================================================================
     * Keresés
     * ============================================================== */

    /**
     * A keresőszöveg értelmezése SQL-feltétellé. Számlaszám (SD-2026-1745),
     * munkalapszám / összeg / vonalkód (csak számjegy), vagy szavak (leírás,
     * név, megjegyzés…), szavanként ÉS kapcsolattal.
     *
     * @return array{0: string, 1: array<int, mixed>, 2: string} feltétel, paraméterek, a keresés fajtája
     */
    public static function szoveg_feltetel(string $q): array
    {
        global $wpdb;

        $q = trim($q);

        if ($q === '') {
            return ['1=1', [], ''];
        }

        $tiszta = str_replace(["\u{202F}", "\u{00A0}", ' ', '.'], '', preg_replace('/\s*Ft$/iu', '', $q) ?? $q);

        // Számlaszám alak: betűk-év-sorszám (SD-2026-1745), vagy bármi kötőjeles szám.
        if (preg_match('/^[\p{L}]{1,8}[-\/ ]?\d{2,4}[-\/]\d+$/u', $q) || preg_match('/^\d{4}-\d+$/', $q)) {
            $like = '%' . $wpdb->esc_like($q) . '%';

            return ['(szamlaszam LIKE %s OR megjegyzes LIKE %s OR leiras LIKE %s)', [$like, $like, $like], 'szamla'];
        }

        if (preg_match('/^-?\d{1,13}$/', $tiszta)) {
            $d     = ltrim($tiszta, '-');
            $reszek = ['munkalap_szam = %s', "szamlaszam LIKE %s"];
            $param  = [$d, '%-' . $wpdb->esc_like($d)];

            if (strlen($d) >= 3) {
                $reszek[] = "(munkalap_szam LIKE %s)";
                $param[]  = '%' . $wpdb->esc_like($d) . '%';
            }

            if (strlen($d) >= 5) {
                $reszek[] = 'vonalkod LIKE %s';
                $param[]  = '%' . $wpdb->esc_like($d) . '%';
            }

            if ((int) $d >= 5) {
                $reszek[] = '(ABS(kp) = %d OR kartya = %d OR utalas = %d OR info_osszeg = %d)';
                array_push($param, (int) $d, (int) $d, (int) $d, (int) $d);
            }

            return ['(' . implode(' OR ', $reszek) . ')', $param, 'szam'];
        }

        $reszek = [];
        $param  = [];

        foreach (preg_split('/\s+/u', $q) ?: [] as $szo) {
            if ($szo === '') {
                continue;
            }

            $like     = '%' . $wpdb->esc_like($szo) . '%';
            $reszek[] = '(leiras LIKE %s OR nev LIKE %s OR szemely LIKE %s OR megjegyzes LIKE %s OR szamlaszam LIKE %s OR vonalkod LIKE %s OR munkalap_szam LIKE %s)';
            array_push($param, $like, $like, $like, $like, $like, $like, $like);
        }

        return [$reszek !== [] ? implode(' AND ', $reszek) : '1=1', $param, 'szoveg'];
    }

    public static function ajax_kereses(): void
    {
        global $wpdb;

        self::jog();

        $q      = mb_substr((string) self::be('q'), 0, 120);
        $oldal  = max(1, (int) self::be('oldal', '1'));
        [$felt, $param, $fajta] = self::szoveg_feltetel($q);

        $hol   = [$felt];
        $tipus = array_values(array_intersect((array) (self::be('tipus', []) ?: []), array_keys(SDH_Muhely_Penztar::TIPUSOK)));
        $tol   = self::datum((string) self::be('tol'), '');
        $ig    = self::datum((string) self::be('ig'), '');
        $mod   = (string) self::be('mod');
        $min   = (string) self::be('min') !== '' ? SDH_Muhely_Penztar::szam(self::be('min')) : null;
        $max   = (string) self::be('max') !== '' ? SDH_Muhely_Penztar::szam(self::be('max')) : null;

        if ($tipus !== []) {
            $hol[] = 'tipus IN (' . implode(',', array_fill(0, count($tipus), '%s')) . ')';
            $param = array_merge($param, $tipus);
        }

        if ($tol !== '') {
            $hol[]   = 'datum >= %s';
            $param[] = $tol;
        }

        if ($ig !== '') {
            $hol[]   = 'datum <= %s';
            $param[] = $ig;
        }

        if (in_array($mod, ['kp', 'kartya', 'utalas'], true)) {
            $hol[] = "{$mod} <> 0";
        }

        $osszeg_kif = '(ABS(kp) + kartya + utalas + info_osszeg)';

        if ($min !== null) {
            $hol[]   = "{$osszeg_kif} >= %f";
            $param[] = $min;
        }

        if ($max !== null) {
            $hol[]   = "{$osszeg_kif} <= %f";
            $param[] = $max;
        }

        if (self::be('szamla_nelkul') === '1') {
            $hol[] = "tipus = 'bevetel' AND szamlaszam = ''";
        }

        if (self::be('ml_nelkul') === '1') {
            $hol[] = "tipus = 'bevetel' AND munkalap_szam = ''";
        }

        $hol[] = self::be('torolt') === '1' ? 'torolve = 1' : 'torolve = 0';

        $t     = SDH_Muhely_Penztar::tabla();
        $where = implode(' AND ', $hol);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql_osszes = "SELECT COUNT(*) AS db, COALESCE(SUM(CASE WHEN tipus = 'bevetel' THEN kp ELSE 0 END), 0) AS kp,
                              COALESCE(SUM(kartya), 0) AS kartya, COALESCE(SUM(utalas), 0) AS utalas,
                              COALESCE(SUM(CASE WHEN tipus IN ('kivet', 'kifizetes') THEN kp ELSE 0 END), 0) AS ki
                       FROM {$t} WHERE {$where}";
        $osszes = $param !== [] ? $wpdb->get_row($wpdb->prepare($sql_osszes, $param)) : $wpdb->get_row($sql_osszes);

        $sql_sorok = "SELECT * FROM {$t} WHERE {$where} ORDER BY datum DESC, sorrend DESC, id DESC LIMIT %d OFFSET %d";
        $sorok = $wpdb->get_results($wpdb->prepare($sql_sorok, array_merge($param, [self::KERES_OLDAL, ($oldal - 1) * self::KERES_OLDAL])));
        // phpcs:enable

        $kapcsolodo = self::kapcsolodo($q, $fajta);

        // A találatok munkalapjai: ami a CRM-ben megvan, arra kattintva megnyílik.
        $ml_szamok = [];

        foreach (is_array($sorok) ? $sorok : [] as $s) {
            if (preg_match('/^\d{1,12}$/', (string) $s->munkalap_szam)) {
                $ml_szamok[] = (int) $s->munkalap_szam;
            }
        }

        wp_send_json_success([
            'q'        => $q,
            'fajta'    => $fajta,
            'tetelek'  => array_map([SDH_Muhely_Penztar::class, 'tetel_kifele'], is_array($sorok) ? $sorok : []),
            'osszes'   => (int) ($osszes->db ?? 0),
            'osszeg'   => [
                'kp'     => (float) ($osszes->kp ?? 0),
                'kartya' => (float) ($osszes->kartya ?? 0),
                'utalas' => (float) ($osszes->utalas ?? 0),
                'ki'     => (float) ($osszes->ki ?? 0),
            ],
            'oldal'    => $oldal,
            'oldalak'  => max(1, (int) ceil(((int) ($osszes->db ?? 0)) / self::KERES_OLDAL)),
            'kapcsolodo' => $kapcsolodo,
            'ml_crm'   => self::crm_munkalapok($ml_szamok),
        ]);
    }

    /** @return array<string, int> munkalapszám → CRM-azonosító (ami létezik). */
    public static function crm_munkalapok(array $szamok): array
    {
        global $wpdb;

        $szamok = array_values(array_unique(array_filter(array_map('intval', $szamok))));

        if ($szamok === []) {
            return [];
        }

        $hely  = implode(',', array_fill(0, count($szamok), '%d'));
        $sorok = $wpdb->get_results($wpdb->prepare(
            'SELECT id, munkalap_szam FROM ' . SDH_Muhely_Schema::tabla('munkalap') . " WHERE munkalap_szam IN ({$hely})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $szamok
        ));

        $ki = [];

        foreach (is_array($sorok) ? $sorok : [] as $s) {
            $ki[(string) (int) $s->munkalap_szam] = (int) $s->id;
        }

        return $ki;
    }

    /** A keresett szám munkalapja és számlái a CRM-ből (ami nincs a pénztárban, az is látszik). */
    private static function kapcsolodo(string $q, string $fajta): array
    {
        global $wpdb;

        $ki = ['munkalapok' => [], 'szamlak' => []];

        if ($fajta === '' || $fajta === 'szoveg') {
            return $ki;
        }

        $sz = SDH_Muhely_Schema::tabla('szamla');
        $mt = SDH_Muhely_Schema::tabla('munkalap');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($fajta === 'szam') {
            $d = (int) ltrim(str_replace([' ', "\u{202F}", "\u{00A0}"], '', $q), '-');
            $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$mt} WHERE munkalap_szam = %d", $d));

            if ($m) {
                $info = SDH_Muhely_Penztar::munkalap_info($m);
                $info['allapot'] = (string) $m->allapot;
                $info['fizetes_ideje'] = (string) $m->fizetes_ideje;
                $ki['munkalapok'][] = $info;
            }

            $szamlak = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, m.munkalap_szam FROM {$sz} s LEFT JOIN {$mt} m ON m.id = s.munkalap_id WHERE s.szamlaszam LIKE %s OR s.munkalap_id = %d ORDER BY s.id DESC LIMIT 10",
                '%-' . $wpdb->esc_like((string) $d),
                $m ? (int) $m->id : -1
            ));
        } else {
            $szamlak = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, m.munkalap_szam FROM {$sz} s LEFT JOIN {$mt} m ON m.id = s.munkalap_id WHERE s.szamlaszam LIKE %s ORDER BY s.id DESC LIMIT 10",
                '%' . $wpdb->esc_like($q) . '%'
            ));
        }
        // phpcs:enable

        foreach (is_array($szamlak) ? $szamlak : [] as $s) {
            $ki['szamlak'][] = [
                'id'         => (int) $s->id,
                'szamlaszam' => (string) $s->szamlaszam,
                'brutto'     => (float) $s->brutto,
                'kelt'       => (string) $s->kelt,
                'fizmod'     => (string) $s->fizmod,
                'munkalap_id' => (int) $s->munkalap_id,
                'munkalap_szam' => (int) $s->munkalap_szam > 0 ? (string) (int) $s->munkalap_szam : '',
                'kasszaban'  => self::szamla_kasszaban((string) $s->szamlaszam),
            ];
        }

        return $ki;
    }

    private static function szamla_kasszaban(string $szamlaszam): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . SDH_Muhely_Penztar::tabla() . ' WHERE torolve = 0 AND szamlaszam = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $szamlaszam
        ));
    }

    /* =================================================================
     * Riport
     * ============================================================== */

    /** @return array<int, object> */
    private static function napok(string $tol, string $ig): array
    {
        global $wpdb;

        $sorok = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SDH_Muhely_Penztar::nap_tabla() . ' WHERE datum BETWEEN %s AND %s ORDER BY datum', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $tol,
            $ig
        ));

        return is_array($sorok) ? $sorok : [];
    }

    /** Az időszak fő számai a napi összesítőkből. */
    public static function osszesit(array $napok): array
    {
        $o = [
            'kp_be' => 0.0, 'kartya' => 0.0, 'utalas' => 0.0, 'kifizetes' => 0.0, 'kivet' => 0.0, 'befizetes' => 0.0,
            'forgalom' => 0.0, 'napok' => 0, 'tetel_db' => 0, 'tobblet' => 0.0, 'hiany' => 0.0, 'elteres_nap' => 0,
            'nyito' => null, 'zaro' => null, 'szamolatlan' => 0,
        ];

        foreach ($napok as $n) {
            foreach (['kp_be', 'kartya', 'utalas', 'kifizetes', 'kivet', 'befizetes', 'forgalom'] as $k) {
                $o[$k] += (float) $n->$k;
            }

            $o['tetel_db'] += (int) $n->tetel_db;

            if ((float) $n->forgalom != 0 || (int) $n->tetel_db > 0) {
                $o['napok']++;
            }

            if ($n->elteres !== null && abs((float) $n->elteres) >= 1) {
                $o['elteres_nap']++;
                $o[(float) $n->elteres > 0 ? 'tobblet' : 'hiany'] += (float) $n->elteres;
            }

            if ($n->szamolt === null && (int) $n->tetel_db > 0) {
                $o['szamolatlan']++;
            }

            if ($o['nyito'] === null) {
                $o['nyito'] = (float) $n->nyito;
            }

            $o['zaro'] = $n->szamolt !== null ? (float) $n->szamolt : (float) $n->zaro;
        }

        foreach ($o as $k => $v) {
            if (is_float($v)) {
                $o[$k] = round($v, 2);
            }
        }

        $o['atlag'] = $o['napok'] > 0 ? round($o['forgalom'] / $o['napok']) : 0;

        return $o;
    }

    /** Kivét személyenként és kifizetés partnerenként az időszakban. */
    private static function kiadasok(string $tol, string $ig): array
    {
        global $wpdb;

        $t = SDH_Muhely_Penztar::tabla();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $kivet = $wpdb->get_results($wpdb->prepare(
            "SELECT szemely, SUM(kp) AS osszeg, COUNT(*) AS db FROM {$t} WHERE torolve = 0 AND tipus = 'kivet' AND datum BETWEEN %s AND %s GROUP BY szemely ORDER BY osszeg",
            $tol,
            $ig
        ));

        $kifiz = $wpdb->get_results($wpdb->prepare(
            "SELECT nev, leiras, kp FROM {$t} WHERE torolve = 0 AND tipus = 'kifizetes' AND datum BETWEEN %s AND %s",
            $tol,
            $ig
        ));
        // phpcs:enable

        $partnerek = [];

        foreach (is_array($kifiz) ? $kifiz : [] as $s) {
            $nev   = trim((string) $s->nev) !== '' ? trim((string) $s->nev) : trim((string) $s->leiras);
            $kulcs = self::partner_kulcs($nev);

            if (!isset($partnerek[$kulcs])) {
                $partnerek[$kulcs] = ['nev' => $nev, 'osszeg' => 0.0, 'db' => 0];
            }

            $partnerek[$kulcs]['osszeg'] += (float) $s->kp;
            $partnerek[$kulcs]['db']++;
        }

        usort($partnerek, static fn ($a, $b) => $a['osszeg'] <=> $b['osszeg']);

        return [
            'kivet'     => array_map(static fn ($s) => ['szemely' => (string) $s->szemely !== '' ? (string) $s->szemely : '(nincs megadva)', 'osszeg' => round((float) $s->osszeg, 2), 'db' => (int) $s->db], is_array($kivet) ? $kivet : []),
            'partnerek' => array_slice($partnerek, 0, 25),
        ];
    }

    public static function ajax_riport(): void
    {
        global $wpdb;

        self::jog();

        [$tol, $ig] = self::idoszak();

        $napok = self::napok($tol, $ig);
        $t     = SDH_Muhely_Penztar::tabla();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $bizonylat = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS db,
                    SUM(CASE WHEN szamlaszam = '' THEN 1 ELSE 0 END) AS szamla_nelkul_db,
                    SUM(CASE WHEN szamlaszam = '' THEN kp + kartya + utalas ELSE 0 END) AS szamla_nelkul,
                    SUM(CASE WHEN munkalap_szam = '' THEN 1 ELSE 0 END) AS ml_nelkul_db
             FROM {$t} WHERE torolve = 0 AND tipus = 'bevetel' AND datum BETWEEN %s AND %s",
            $tol,
            $ig
        ));

        wp_send_json_success([
            'tol'      => $tol,
            'ig'       => $ig,
            'osszesen' => self::osszesit($napok),
            'napok'    => array_map([SDH_Muhely_Penztar::class, 'nap_kifele'], $napok),
            'kiadasok' => self::kiadasok($tol, $ig),
            'bizonylat' => [
                'db'               => (int) ($bizonylat->db ?? 0),
                'szamla_nelkul_db' => (int) ($bizonylat->szamla_nelkul_db ?? 0),
                'szamla_nelkul'    => round((float) ($bizonylat->szamla_nelkul ?? 0), 2),
                'ml_nelkul_db'     => (int) ($bizonylat->ml_nelkul_db ?? 0),
            ],
        ]);
    }

    /* =================================================================
     * Statisztika
     * ============================================================== */

    private static function bontas_kulcs(string $datum, string $bontas): string
    {
        switch ($bontas) {
            case 'ev':
                return substr($datum, 0, 4);
            case 'honap':
                return substr($datum, 0, 7);
            case 'het':
                $ido = strtotime($datum . ' 12:00:00');

                return gmdate('o', $ido) . '-H' . gmdate('W', $ido);
            default:
                return $datum;
        }
    }

    public static function ajax_statisztika(): void
    {
        global $wpdb;

        self::jog();

        [$tol, $ig] = self::idoszak();

        $napok_szama = (int) round((strtotime($ig) - strtotime($tol)) / DAY_IN_SECONDS) + 1;
        $bontas      = (string) self::be('bontas');

        if (!in_array($bontas, ['nap', 'het', 'honap', 'ev'], true)) {
            $bontas = $napok_szama <= 45 ? 'nap' : ($napok_szama <= 200 ? 'het' : ($napok_szama <= 1100 ? 'honap' : 'ev'));
        }

        $napok = self::napok($tol, $ig);
        $ossz  = self::osszesit($napok);

        // Előző, ugyanolyan hosszú időszak – az összevetéshez.
        $e_ig  = wp_date('Y-m-d', strtotime($tol . ' 12:00:00') - DAY_IN_SECONDS);
        $e_tol = wp_date('Y-m-d', strtotime($e_ig . ' 12:00:00') - ($napok_szama - 1) * DAY_IN_SECONDS);
        $elozo = self::osszesit(self::napok($e_tol, $e_ig));

        // Idősor, a hét napjai, évek, eltérések.
        $sor     = [];
        $hetnap  = array_fill(1, 7, ['osszeg' => 0.0, 'napok' => 0]);
        $evek    = [];
        $eltero  = [];
        $legjobb = null;

        foreach ($napok as $n) {
            $k = self::bontas_kulcs((string) $n->datum, $bontas);

            if (!isset($sor[$k])) {
                $sor[$k] = ['kulcs' => $k, 'kp' => 0.0, 'kartya' => 0.0, 'utalas' => 0.0, 'kivet' => 0.0, 'kifizetes' => 0.0, 'forgalom' => 0.0, 'napok' => 0];
            }

            foreach (['kartya', 'utalas', 'kivet', 'kifizetes', 'forgalom'] as $m) {
                $sor[$k][$m] += (float) $n->$m;
            }

            $sor[$k]['kp'] += (float) $n->kp_be;

            $forg = (float) $n->forgalom;

            if ($forg != 0) {
                $sor[$k]['napok']++;
                $hn = (int) gmdate('N', strtotime((string) $n->datum . ' 12:00:00'));
                $hetnap[$hn]['osszeg'] += $forg;
                $hetnap[$hn]['napok']++;

                if ($legjobb === null || $forg > $legjobb['forgalom']) {
                    $legjobb = ['datum' => (string) $n->datum, 'forgalom' => $forg];
                }
            }

            $ev = substr((string) $n->datum, 0, 4);
            $ho = (int) substr((string) $n->datum, 5, 2);

            if (!isset($evek[$ev])) {
                $evek[$ev] = ['ev' => $ev, 'forgalom' => 0.0, 'kp' => 0.0, 'kartya' => 0.0, 'kivet' => 0.0, 'kifizetes' => 0.0, 'napok' => 0, 'honapok' => array_fill(1, 12, 0.0)];
            }

            $evek[$ev]['forgalom'] += $forg;
            $evek[$ev]['kp'] += (float) $n->kp_be;
            $evek[$ev]['kartya'] += (float) $n->kartya;
            $evek[$ev]['kivet'] += (float) $n->kivet;
            $evek[$ev]['kifizetes'] += (float) $n->kifizetes;
            $evek[$ev]['napok'] += $forg != 0 ? 1 : 0;
            $evek[$ev]['honapok'][$ho] += $forg;

            if ($n->elteres !== null && abs((float) $n->elteres) >= 1) {
                $eltero[] = ['datum' => (string) $n->datum, 'elteres' => (float) $n->elteres];
            }
        }

        usort($eltero, static fn ($a, $b) => abs($b['elteres']) <=> abs($a['elteres']));

        // Tételszintű elemzés: kategóriák, átlagos kosár, legnagyobb tételek, számlázottság, órák.
        $t = SDH_Muhely_Penztar::tabla();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $tetelek = $wpdb->get_results($wpdb->prepare(
            "SELECT id, datum, ido, leiras, nev, kp, kartya, utalas, munkalap_szam, szamlaszam, forras FROM {$t}
             WHERE torolve = 0 AND tipus = 'bevetel' AND datum BETWEEN %s AND %s",
            $tol,
            $ig
        ));

        $kategoriak = SDH_Muhely_Penztar::kategoriak();
        $kat        = [];
        $osszegek   = [];
        $orak       = array_fill(7, 13, ['osszeg' => 0.0, 'db' => 0]);
        $szamlas    = ['db' => 0, 'osszeg' => 0.0];
        $mlhez      = ['db' => 0, 'osszeg' => 0.0];
        $legnagyobb = [];

        foreach (is_array($tetelek) ? $tetelek : [] as $s) {
            $o = (float) $s->kp + (float) $s->kartya + (float) $s->utalas;

            if ($o <= 0) {
                continue;
            }

            $osszegek[] = $o;
            $leiras     = mb_strtolower((string) $s->leiras, 'UTF-8');
            $nev        = 'Egyéb';

            foreach ($kategoriak as $k) {
                foreach ($k['szavak'] as $szo) {
                    if ($szo !== '' && mb_strpos($leiras, $szo, 0, 'UTF-8') !== false) {
                        $nev = $k['nev'];
                        break 2;
                    }
                }
            }

            if (!isset($kat[$nev])) {
                $kat[$nev] = ['nev' => $nev, 'osszeg' => 0.0, 'db' => 0];
            }

            $kat[$nev]['osszeg'] += $o;
            $kat[$nev]['db']++;

            if ((string) $s->szamlaszam !== '') {
                $szamlas['db']++;
                $szamlas['osszeg'] += $o;
            }

            if ((string) $s->munkalap_szam !== '') {
                $mlhez['db']++;
                $mlhez['osszeg'] += $o;
            }

            // Óra: csak a CRM-ben rögzített tételeknél valós (az importáltaké nem ismert).
            if ((string) $s->forras !== 'import' && $s->ido) {
                $ora = (int) substr((string) $s->ido, 11, 2);

                if (isset($orak[$ora])) {
                    $orak[$ora]['osszeg'] += $o;
                    $orak[$ora]['db']++;
                }
            }

            $legnagyobb[] = ['id' => (int) $s->id, 'datum' => (string) $s->datum, 'leiras' => (string) $s->leiras, 'osszeg' => $o, 'ml' => (string) $s->munkalap_szam, 'szamla' => (string) $s->szamlaszam];
        }

        usort($legnagyobb, static fn ($a, $b) => $b['osszeg'] <=> $a['osszeg']);
        usort($kat, static fn ($a, $b) => $b['osszeg'] <=> $a['osszeg']);
        sort($osszegek);

        $db      = count($osszegek);
        $median  = $db > 0 ? ($db % 2 ? $osszegek[intdiv($db, 2)] : ($osszegek[$db / 2 - 1] + $osszegek[$db / 2]) / 2) : 0;
        $hetnevek = [1 => 'Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat', 'Vasárnap'];

        wp_send_json_success([
            'tol'      => $tol,
            'ig'       => $ig,
            'bontas'   => $bontas,
            'osszesen' => $ossz,
            'elozo'    => ['tol' => $e_tol, 'ig' => $e_ig] + $elozo,
            'sor'      => array_values($sor),
            'hetnap'   => array_map(static fn ($i) => ['nap' => $hetnevek[$i], 'atlag' => $hetnap[$i]['napok'] > 0 ? round($hetnap[$i]['osszeg'] / $hetnap[$i]['napok']) : 0, 'napok' => $hetnap[$i]['napok']], range(1, 7)),
            'evek'     => array_values(array_map(static function (array $ev): array {
                $ev['honapok'] = array_values($ev['honapok']);

                return $ev;
            }, $evek)),
            'kategoriak' => array_values($kat),
            'tetel'    => [
                'db'      => $db,
                'atlag'   => $db > 0 ? round(array_sum($osszegek) / $db) : 0,
                'median'  => round($median),
                'szamlas' => $szamlas,
                'mlhez'   => $mlhez,
            ],
            'orak'     => array_map(static fn ($o, $i) => ['ora' => $i, 'osszeg' => $o['osszeg'], 'db' => $o['db']], $orak, array_keys($orak)),
            'legnagyobb' => array_slice($legnagyobb, 0, 10),
            'legjobb'  => $legjobb,
            'eltero'   => array_slice($eltero, 0, 12),
            'kiadasok' => self::kiadasok($tol, $ig),
        ]);
    }

    /* =================================================================
     * Export (CSV – Excelben megnyitható, a régi tábla oszlopaival)
     * ============================================================== */

    public static function ajax_export(): void
    {
        global $wpdb;

        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_die('Nincs jogosultságod ehhez.', 403);
        }

        [$tol, $ig] = self::idoszak();

        $napok = [];

        foreach (self::napok($tol, $ig) as $n) {
            $napok[(string) $n->datum] = $n;
        }

        $sorok = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SDH_Muhely_Penztar::tabla() . ' WHERE torolve = 0 AND datum BETWEEN %s AND %s ORDER BY datum, sorrend, id', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $tol,
            $ig
        ));

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="penztar-' . $tol . '_' . $ig . '.csv"');

        $ki = fopen('php://output', 'w');
        fwrite($ki, "\xEF\xBB\xBF");

        $fej = ['Dátum', 'Cikkszám/munka', 'Név', 'KP', 'B.kártya', 'Utalás', 'Munkalap sorszám', 'Vonalkód', 'Kifizetés', 'KP a kasszában', 'Számlaszám', 'KP+BK össz.', 'Össz. forg.', 'Típus', 'Személy', 'Megjegyzés'];
        $szam = static fn (float $f): string => $f == 0 ? '' : (string) round($f);

        $aktualis = null;
        $zar = static function (?string $datum) use ($ki, $napok, $szam): void {
            if ($datum === null || !isset($napok[$datum])) {
                return;
            }

            $n = $napok[$datum];
            fputcsv($ki, [
                '', 'Napi összesen', '', $szam((float) $n->kp_be), $szam((float) $n->kartya), $szam((float) $n->utalas), '', '',
                $szam((float) $n->kifizetes + (float) $n->kivet + (float) $n->befizetes),
                $szam($n->szamolt !== null ? (float) $n->szamolt : (float) $n->zaro), '',
                $szam((float) $n->forgalom), $szam((float) $n->halmozott), '', '',
                $n->elteres !== null && abs((float) $n->elteres) >= 1 ? 'Eltérés: ' . round((float) $n->elteres) : '',
            ], ';');
        };

        foreach (is_array($sorok) ? $sorok : [] as $s) {
            if ($aktualis !== (string) $s->datum) {
                $zar($aktualis);
                fputcsv($ki, $fej, ';');
                $aktualis = (string) $s->datum;
            }

            $kiadas = in_array((string) $s->tipus, ['kivet', 'kifizetes', 'befizetes'], true);

            fputcsv($ki, [
                (string) $s->datum,
                (string) $s->leiras,
                (string) $s->nev,
                $kiadas ? '' : $szam((float) $s->kp),
                $szam((float) $s->kartya),
                $szam((float) $s->utalas),
                (string) $s->munkalap_szam,
                (string) $s->vonalkod,
                $kiadas ? $szam((float) $s->kp) : '',
                '',
                (string) $s->szamlaszam,
                '', '',
                SDH_Muhely_Penztar::TIPUSOK[(string) $s->tipus] ?? (string) $s->tipus,
                (string) $s->szemely,
                (string) $s->megjegyzes . ((string) $s->tipus === 'kihagyva' ? ' (' . round((float) $s->info_osszeg) . ' Ft)' : ''),
            ], ';');
        }

        $zar($aktualis);
        fclose($ki);
        exit;
    }
}
