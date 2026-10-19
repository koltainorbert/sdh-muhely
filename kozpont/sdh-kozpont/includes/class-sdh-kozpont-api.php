<?php
/**
 * SDH Központ – REST: a példányok végpontjai és a Központ felületének adatai (`app/*`).
 *
 * @package SDH_Kozpont
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Kozpont_Api
{
    private const K = SDH_Kozpont::class;

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'utvonalak']);
    }

    public static function utvonalak(): void
    {
        $ns = SDH_Kozpont::NS;

        /* --- A példányoké (nyilvános, a licenckulcs azonosít) --------------------------------------------------- */

        register_rest_route($ns, '/szivveres', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => static fn (WP_REST_Request $r) => rest_ensure_response(SDH_Kozpont::alair(SDH_Kozpont::szivveres_feldolgoz((array) $r->get_json_params()))),
        ]);

        register_rest_route($ns, '/jelentes', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => static fn (WP_REST_Request $r) => rest_ensure_response(SDH_Kozpont::alair(SDH_Kozpont::jelentes_feldolgoz((array) $r->get_json_params()))),
        ]);

        register_rest_route($ns, '/csomag', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'csomag'],
        ]);

        register_rest_route($ns, '/tamogatas', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => static function (WP_REST_Request $r) {
                $hiba = SDH_Kozpont::tamogatas_rogzit((array) $r->get_json_params());

                return $hiba === '' ? rest_ensure_response(['ok' => true]) : new WP_Error('sdh_kozpont_tamogatas', $hiba, ['status' => 403]);
            },
        ]);

        /* --- A Központ felülete ---------------------------------------------------------------------------------- */

        $app = [
            ['GET', 'attekintes', 'attekintes'],
            ['GET', 'peldanyok', 'peldanyok'],
            ['GET', 'peldany/(?P<id>\d+)', 'peldany'],
            ['GET', 'peldany/(?P<id>\d+)/meres', 'meres'],
            ['POST', 'peldany/(?P<id>\d+)', 'peldany_ment'],
            ['POST', 'peldany/(?P<id>\d+)/muvelet', 'peldany_muvelet'],
            ['POST', 'peldany/(?P<id>\d+)/elfelejt', 'peldany_elfelejt'],
            ['GET', 'vevok', 'vevok'],
            ['GET', 'vevo/(?P<id>\d+)', 'vevo'],
            ['POST', 'vevo', 'vevo_ment'],
            ['POST', 'vevo/(?P<id>\d+)/jegyzet', 'vevo_jegyzet'],
            ['GET', 'licencek', 'licencek'],
            ['POST', 'licenc', 'licenc_ment'],
            ['POST', 'licenc/(?P<id>\d+)/muvelet', 'licenc_muvelet'],
            ['GET', 'kiadasok', 'kiadasok'],
            ['POST', 'kiadas', 'kiadas_feltolt'],
            ['POST', 'kiadas/(?P<id>\d+)', 'kiadas_ment'],
            ['GET', 'tamogatas', 'tamogatas'],
            ['GET', 'naplo', 'naplo'],
            ['GET', 'beallitas', 'beallitas'],
            ['POST', 'beallitas', 'beallitas_ment'],
            ['GET', 'kereses', 'kereses'],
            ['POST', 'tema', 'tema'],
        ];

        foreach ($app as [$m, $ut, $fv]) {
            register_rest_route($ns, '/app/' . $ut, [
                'methods'             => $m,
                'permission_callback' => static fn (): bool => current_user_can('manage_options'),
                'callback'            => [self::class, $fv],
            ]);
        }
    }

    /* =================================================================
     * Segédek
     * ============================================================== */

    private static function ki(): string
    {
        return (string) wp_get_current_user()->display_name;
    }

    private static function hiba(string $uzenet, int $status = 400): WP_Error
    {
        return new WP_Error('sdh_kozpont', $uzenet, ['status' => $status]);
    }

    private static function ts(?string $mysql): int
    {
        return SDH_Kozpont::ido_ts((string) $mysql);
    }

    /** @return array<string, mixed> */
    private static function snap(object $p): array
    {
        $s = json_decode((string) ($p->allapot ?? ''), true);

        return is_array($s) ? $s : [];
    }

    /**
     * A listákba kerülő példányadatok.
     *
     * @param array<int, array<int, int>> $sparkok
     * @return array<string, mixed>
     */
    private static function peldany_ki(object $p, array $sparkok = []): array
    {
        $s  = self::snap($p);
        $st = SDH_Kozpont::statusz($p);
        $pr = json_decode((string) ($p->problema ?? ''), true);
        $pr = is_array($pr) ? $pr : [];

        if ($st === 'offline') {
            array_unshift($pr, ['kod' => 'offline', 'szint' => 'hiba', 'szoveg' => 'Nem jelentkezik.']);
        }

        return [
            'id'         => (int) $p->id,
            'nev'        => SDH_Kozpont::peldany_nev($p, (object) ['vevo' => (string) ($p->vevo ?? '')]),
            'cimke'      => (string) $p->cimke,
            'peldany'    => (string) $p->peldany,
            'vevo'       => (string) ($p->vevo ?? ''),
            'vevo_id'    => (int) ($p->vevo_id ?? 0),
            'licenc_id'  => (int) $p->licenc_id,
            'kulcs'      => (string) ($p->kulcs ?? ''),
            'verzio'     => (string) $p->verzio,
            'statusz'    => $st,
            'mod'        => empty($p->utolso_jel) ? 'napi' : 'elo',
            'utolso'     => self::ts((string) ($p->utolso_jel ?: $p->utolso)),
            'elso'       => self::ts((string) $p->elso),
            'valasz_ms'  => (int) $p->valasz_ms,
            'problemak'  => $pr,
            'db_mb'      => (int) ($s['adat']['db_mb'] ?? 0),
            'lemez_mb'   => (int) ($s['lemez']['szabad_mb'] ?? -1),
            'mentes'     => (int) ($s['mentes']['utolso'] ?? 0),
            'felhasznalo' => (int) ($s['adat']['felhasznalo'] ?? 0),
            'aktiv'      => (int) ($s['aktivitas']['most'] ?? 0),
            'munkalap'   => (int) ($s['adat']['munkalap'] ?? 0),
            'munkalap_ma' => (int) ($s['adat']['munkalap_ma'] ?? 0),
            'php'        => (string) ($s['rendszer']['php'] ?? ''),
            'tavoli'     => (int) $p->tavoli,
            'elo'        => !empty($p->elo_ig) && (string) $p->elo_ig > SDH_Kozpont::most(),
            'spark'      => $sparkok[(int) $p->id] ?? [],
        ];
    }

    /**
     * Óránkénti válaszidő az elmúlt 24 órából, példányonként.
     *
     * @param array<int, int> $ids
     * @return array<int, array<int, int>>
     */
    private static function sparkok(array $ids): array
    {
        global $wpdb;

        if ($ids === []) {
            return [];
        }

        $tol   = wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $sorok = (array) $wpdb->get_results($wpdb->prepare('SELECT peldany_id, ido, valasz_ms FROM ' . SDH_Kozpont::t('meres') . ' WHERE ido >= %s ORDER BY ido', $tol));
        $gy    = [];

        foreach ($sorok as $s) {
            $o = substr((string) $s->ido, 0, 13);
            $gy[(int) $s->peldany_id][$o][] = (int) $s->valasz_ms;
        }

        $ki = [];

        foreach ($gy as $pid => $orak) {
            foreach ($orak as $v) {
                $ki[$pid][] = (int) round(array_sum($v) / max(1, count($v)));
            }
        }

        return $ki;
    }

    private static function peldany_sql(): string
    {
        return 'SELECT p.*, l.vevo, l.vevo_id, l.kulcs FROM ' . SDH_Kozpont::t('peldany') . ' p LEFT JOIN ' . SDH_Kozpont::t('licenc') . ' l ON l.id = p.licenc_id';
    }

    /** @return array<string, mixed> */
    private static function licenc_ki(object $l, int $peldany_db = 0): array
    {
        $ma = wp_date('Y-m-d');
        $al = SDH_Kozpont::licenc_allapot($l, $ma);
        $nap = !empty($l->lejar) ? (int) floor((strtotime((string) $l->lejar) - strtotime($ma)) / DAY_IN_SECONDS) : null;

        return [
            'id'              => (int) $l->id,
            'kulcs'           => (string) $l->kulcs,
            'vevo_id'         => (int) $l->vevo_id,
            'vevo'            => (string) $l->vevo,
            'vevo_azonosito'  => (string) $l->vevo_azonosito,
            'csomag'          => (string) $l->csomag,
            'modulok'         => SDH_Kozpont::licenc_modulok($l),
            'jogok'           => SDH_Kozpont::jogok($l),
            'lejar'           => (string) ($l->lejar ?? ''),
            'lejar_nap'       => $nap,
            'max_peldany'     => (int) $l->max_peldany,
            'max_felhasznalo' => (int) $l->max_felhasznalo,
            'proba'           => (int) $l->proba,
            'dij'             => (int) $l->dij,
            'ciklus'          => (string) $l->ciklus,
            'esedekes'        => (string) ($l->esedekes ?? ''),
            'csatorna'        => (string) $l->csatorna,
            'allapot'         => (string) $l->allapot,
            'tenyleges'       => $al,
            'uzenet'          => (string) $l->uzenet,
            'megjegyzes'      => (string) ($l->megjegyzes ?? ''),
            'letrehozva'      => self::ts((string) $l->letrehozva),
            'peldany_db'      => $peldany_db,
        ];
    }

    /** Havi ismétlődő bevétel a licencből. */
    private static function havi(object $l): float
    {
        if ((int) $l->proba === 1 || SDH_Kozpont::licenc_allapot($l, wp_date('Y-m-d')) !== 'aktiv') {
            return 0.0;
        }

        return match ((string) $l->ciklus) {
            'havi'  => (float) $l->dij,
            'eves'  => (float) $l->dij / 12,
            default => 0.0,
        };
    }

    /** @return array<int, array<string, mixed>> */
    private static function esemenyek(string $where = '1=1', array $args = [], int $db = 30): array
    {
        global $wpdb;

        $sql = 'SELECT e.*, v.nev AS vevo_nev, p.cimke AS p_cimke, p.peldany AS p_peldany FROM ' . SDH_Kozpont::t('esemeny') . ' e LEFT JOIN ' . SDH_Kozpont::t('vevo') . ' v ON v.id = e.vevo_id LEFT JOIN ' . SDH_Kozpont::t('peldany') . ' p ON p.id = e.peldany_id WHERE ' . $where . ' ORDER BY e.id DESC LIMIT ' . (int) $db;
        $sorok = (array) ($args === [] ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql, ...$args)));

        return array_map(static fn ($e): array => [
            'id'         => (int) $e->id,
            'ido'        => self::ts((string) $e->ido),
            'tipus'      => (string) $e->tipus,
            'szint'      => (string) $e->szint,
            'szoveg'     => (string) $e->szoveg,
            'ki'         => (string) $e->ki,
            'vevo_id'    => (int) $e->vevo_id,
            'vevo'       => (string) ($e->vevo_nev ?? ''),
            'peldany_id' => (int) $e->peldany_id,
            'peldany'    => (int) $e->peldany_id > 0 ? ((string) ($e->p_cimke ?? '') !== '' ? (string) $e->p_cimke : substr((string) ($e->p_peldany ?? ''), 0, 6)) : '',
        ], $sorok);
    }

    /* =================================================================
     * Csomag (frissítés letöltése)
     * ============================================================== */

    public static function csomag(WP_REST_Request $r)
    {
        $l = SDH_Kozpont::licenc((string) $r->get_param('kulcs'));
        $k = $l !== null ? SDH_Kozpont::kiadas_licenchez($l) : ['fajl' => '', 'verzio' => ''];
        $f = (string) $k['fajl'];

        if (!SDH_Kozpont::csomag_szabad((string) $r->get_param('kulcs'), (string) $r->get_param('peldany')) || $f === '' || !is_file($f)) {
            return new WP_Error('sdh_kozpont_tiltva', 'Nincs érvényes licenc ehhez a letöltéshez.', ['status' => 403]);
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="sdh-muhely-' . preg_replace('/[^0-9.]/', '', (string) $k['verzio']) . '.zip"');
        header('Content-Length: ' . (string) filesize($f));
        readfile($f); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    /* =================================================================
     * Áttekintés
     * ============================================================== */

    public static function attekintes(): WP_REST_Response
    {
        global $wpdb;

        $pk     = (array) $wpdb->get_results(self::peldany_sql() . ' ORDER BY p.utolso_jel DESC, p.utolso DESC');
        $sparok = self::sparkok(array_map(static fn ($p): int => (int) $p->id, $pk));
        $lista  = array_map(static fn ($p): array => self::peldany_ki($p, $sparok), $pk);

        $db = ['osszes' => count($lista), 'online' => 0, 'figyel' => 0, 'hiba' => 0, 'offline' => 0, 'napi' => 0, 'uj' => 0];
        $verziok = [];
        $valasz  = [];
        $riaszt  = [];
        $aktiv_most = 0;
        $munkalap_ma = 0;

        foreach ($lista as $p) {
            $db[$p['statusz']] = ($db[$p['statusz']] ?? 0) + 1;
            $verziok[$p['verzio'] !== '' ? $p['verzio'] : '?'] = ($verziok[$p['verzio'] !== '' ? $p['verzio'] : '?'] ?? 0) + 1;

            if (in_array($p['statusz'], ['online', 'figyel', 'hiba'], true)) {
                $aktiv_most  += $p['aktiv'];
                $munkalap_ma += $p['munkalap_ma'];

                if ($p['valasz_ms'] > 0) {
                    $valasz[] = $p['valasz_ms'];
                }
            }

            if (in_array($p['statusz'], ['figyel', 'hiba', 'offline'], true)) {
                $riaszt[] = ['id' => $p['id'], 'nev' => $p['nev'], 'vevo' => $p['vevo'], 'statusz' => $p['statusz'], 'problemak' => array_slice($p['problemak'], 0, 3), 'utolso' => $p['utolso']];
            }
        }

        uksort($verziok, static fn ($a, $b): int => version_compare((string) $b, (string) $a));
        usort($riaszt, static fn ($a, $b): int => ['hiba' => 0, 'offline' => 1, 'figyel' => 2][$a['statusz']] <=> ['hiba' => 0, 'offline' => 1, 'figyel' => 2][$b['statusz']]);

        $licencek = (array) $wpdb->get_results('SELECT * FROM ' . SDH_Kozpont::t('licenc'));
        $ma       = wp_date('Y-m-d');
        $l60      = wp_date('Y-m-d', time() + 60 * DAY_IN_SECONDS);
        $e30      = wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS);
        $ldb      = ['aktiv' => 0, 'proba' => 0, 'lejart' => 0, 'tiltva' => 0];
        $mrr      = 0.0;
        $esedekes = 0;
        $lejarok  = [];

        foreach ($licencek as $l) {
            $a = SDH_Kozpont::licenc_allapot($l, $ma);
            $ldb[$a]++;

            if ($a === 'aktiv' && (int) $l->proba === 1) {
                $ldb['proba']++;
            }

            $mrr += self::havi($l);

            if (!empty($l->esedekes) && (string) $l->esedekes >= $ma && (string) $l->esedekes <= $e30 && $a !== 'tiltva') {
                $esedekes += (int) $l->dij;
            }

            if ($a === 'aktiv' && !empty($l->lejar) && (string) $l->lejar <= $l60) {
                $lejarok[] = ['id' => (int) $l->id, 'kulcs' => (string) $l->kulcs, 'vevo' => (string) $l->vevo, 'vevo_id' => (int) $l->vevo_id, 'lejar' => (string) $l->lejar, 'nap' => (int) floor((strtotime((string) $l->lejar) - strtotime($ma)) / DAY_IN_SECONDS), 'dij' => (int) $l->dij, 'proba' => (int) $l->proba];
            }
        }

        usort($lejarok, static fn ($a, $b): int => strcmp($a['lejar'], $b['lejar']));

        // A flotta válaszideje és aktivitása óránként, 24 óra.
        $tol   = wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $sorok = (array) $wpdb->get_results($wpdb->prepare('SELECT ido, valasz_ms, aktiv, keres FROM ' . SDH_Kozpont::t('meres') . ' WHERE ido >= %s ORDER BY ido', $tol));
        $orak  = [];

        foreach ($sorok as $s) {
            $o = substr((string) $s->ido, 0, 13);
            $orak[$o]['v'][] = (int) $s->valasz_ms;
            $orak[$o]['a'][] = (int) $s->aktiv;
            $orak[$o]['k'][] = (int) $s->keres;
        }

        $flotta = [];

        for ($i = 23; $i >= 0; $i--) {
            $ts = time() - $i * HOUR_IN_SECONDS;
            $o  = wp_date('Y-m-d H', $ts);
            $v  = $orak[$o]['v'] ?? [];
            $flotta[] = [
                't' => $ts,
                'valasz' => $v !== [] ? (int) round(array_sum($v) / count($v)) : null,
                'keres'  => array_sum($orak[$o]['k'] ?? []),
                'aktiv'  => ($orak[$o]['a'] ?? []) !== [] ? (int) max($orak[$o]['a']) : 0,
            ];
        }

        $kiadas = SDH_Kozpont::kiadas();

        return rest_ensure_response([
            'rendszerek'  => $db,
            'lista'       => $lista,
            'riasztasok'  => $riaszt,
            'verziok'     => $verziok,
            'legujabb'    => $kiadas['verzio'],
            'atlag_ms'    => $valasz !== [] ? (int) round(array_sum($valasz) / count($valasz)) : 0,
            'aktiv_most'  => $aktiv_most,
            'munkalap_ma' => $munkalap_ma,
            'licencek'    => $ldb + ['osszes' => count($licencek)],
            'vevok'       => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('vevo') . " WHERE allapot <> 'volt'"),
            'mrr'         => (int) round($mrr),
            'arr'         => (int) round($mrr * 12),
            'esedekes30'  => $esedekes,
            'lejarok'     => array_slice($lejarok, 0, 8),
            'flotta'      => $flotta,
            'esemenyek'   => self::esemenyek('1=1', [], 14),
            'folyamatban' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('muvelet') . " WHERE allapot IN ('var', 'kuldve')"),
            'tamogatas'   => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Kozpont::t('tamogatas') . ' WHERE lejar > %s', SDH_Kozpont::most())),
            'ido'         => time(),
        ]);
    }

    /* =================================================================
     * Rendszerek (példányok)
     * ============================================================== */

    public static function peldanyok(): WP_REST_Response
    {
        global $wpdb;

        $pk = (array) $wpdb->get_results(self::peldany_sql() . ' ORDER BY p.utolso_jel DESC, p.utolso DESC');
        $sp = self::sparkok(array_map(static fn ($p): int => (int) $p->id, $pk));

        return rest_ensure_response(['lista' => array_map(static fn ($p): array => self::peldany_ki($p, $sp), $pk), 'legujabb' => SDH_Kozpont::kiadas()['verzio'], 'ido' => time()]);
    }

    public static function peldany(WP_REST_Request $r)
    {
        global $wpdb;

        $id = (int) $r['id'];
        $p  = $wpdb->get_row($wpdb->prepare(self::peldany_sql() . ' WHERE p.id = %d', $id));

        if ($p === null) {
            return self::hiba('Ez a rendszer nincs meg.', 404);
        }

        $l    = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('licenc') . ' WHERE id = %d', (int) $p->licenc_id));
        $v    = $l !== null ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('vevo') . ' WHERE id = %d', (int) $l->vevo_id)) : null;
        $mv   = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('muvelet') . ' WHERE peldany_id = %d ORDER BY id DESC LIMIT 30', $id));
        $tam  = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('tamogatas') . ' WHERE peldany = %s ORDER BY id DESC LIMIT 10', (string) $p->peldany));
        $most = SDH_Kozpont::most();

        return rest_ensure_response([
            'p'         => self::peldany_ki($p, self::sparkok([$id])),
            'jegyzet'   => (string) ($p->jegyzet ?? ''),
            'allapot'   => self::snap($p),
            'licenc'    => $l !== null ? self::licenc_ki($l) : null,
            'vevo'      => $v !== null ? ['id' => (int) $v->id, 'nev' => (string) $v->nev, 'kapcsolattarto' => (string) $v->kapcsolattarto, 'email' => (string) $v->email, 'telefon' => (string) $v->telefon] : null,
            'muveletek' => array_map(static fn ($m): array => [
                'id' => (int) $m->id, 'tipus' => (string) $m->tipus, 'cim' => SDH_Kozpont::MUVELETEK[(string) $m->tipus] ?? (string) $m->tipus,
                'allapot' => (string) $m->allapot, 'letrehozva' => self::ts((string) $m->letrehozva), 'kesz' => self::ts((string) $m->kesz),
                'eredmeny' => json_decode((string) ($m->eredmeny ?? ''), true) ?: null, 'ki' => (string) $m->ki,
                'parameter' => json_decode((string) ($m->parameter ?? ''), true) ?: [],
            ], $mv),
            'tamogatas' => array_map(static fn ($t): array => [
                'id' => (int) $t->id, 'link' => (string) $t->lejar > $most ? (string) $t->link : '', 'lejar' => self::ts((string) $t->lejar),
                'erkezett' => self::ts((string) $t->erkezett), 'ki' => (string) $t->ki, 'el' => (string) $t->lejar > $most,
            ], $tam),
            'esemenyek' => self::esemenyek('e.peldany_id = %d', [$id], 40),
            'katalogus' => SDH_Kozpont::katalogus(),
            'legujabb'  => $l !== null ? SDH_Kozpont::kiadas_licenchez($l)['verzio'] : '',
            'muveletek_lista' => SDH_Kozpont::MUVELETEK,
            'ido'       => time(),
        ]);
    }

    public static function meres(WP_REST_Request $r): WP_REST_Response
    {
        global $wpdb;

        $tav  = in_array((string) $r->get_param('tav'), ['1h', '24h', '7d', '30d', '90d'], true) ? (string) $r->get_param('tav') : '24h';
        $mp   = ['1h' => HOUR_IN_SECONDS, '24h' => DAY_IN_SECONDS, '7d' => 7 * DAY_IN_SECONDS, '30d' => 30 * DAY_IN_SECONDS, '90d' => 90 * DAY_IN_SECONDS][$tav];
        $tol  = wp_date('Y-m-d H:i:s', time() - $mp);
        $sorok = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('meres') . ' WHERE peldany_id = %d AND ido >= %s ORDER BY ido', (int) $r['id'], $tol));
        $mezok = ['valasz_ms', 'p95_ms', 'db_ms', 'mem_mb', 'db_mb', 'lemez_mb', 'hiba', 'aktiv', 'keres', 'munkalap'];

        // Legfeljebb 240 pont: vödrönként átlag (a hibánál, kérésnél összeg, az aktívnál maximum).
        $vodor = max(1, (int) ceil($mp / 240));
        $gy    = [];

        foreach ($sorok as $s) {
            $ts = self::ts((string) $s->ido);
            $gy[(int) floor($ts / $vodor)][] = $s;
        }

        $ki = ['t' => []];

        foreach ($mezok as $m) {
            $ki[$m] = [];
        }

        foreach ($gy as $v => $csoport) {
            $ki['t'][] = $v * $vodor;

            foreach ($mezok as $m) {
                $ertek = array_map(static fn ($s): int => (int) $s->$m, $csoport);
                $ki[$m][] = in_array($m, ['hiba', 'keres'], true) ? array_sum($ertek) : ($m === 'aktiv' ? max($ertek) : (int) round(array_sum($ertek) / count($ertek)));
            }
        }

        return rest_ensure_response($ki + ['tav' => $tav, 'tol' => time() - $mp, 'ig' => time()]);
    }

    public static function peldany_ment(WP_REST_Request $r)
    {
        global $wpdb;

        $id = (int) $r['id'];
        $p  = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('peldany') . ' WHERE id = %d', $id));

        if ($p === null) {
            return self::hiba('Ez a rendszer nincs meg.', 404);
        }

        $be   = (array) $r->get_json_params();
        $adat = [];

        if (array_key_exists('cimke', $be)) {
            $adat['cimke'] = mb_substr(sanitize_text_field((string) $be['cimke']), 0, 120);
        }

        if (array_key_exists('jegyzet', $be)) {
            $adat['jegyzet'] = sanitize_textarea_field((string) $be['jegyzet']);
        }

        if (array_key_exists('elo', $be)) {
            $perc = max(0, min(120, (int) $be['elo']));
            $adat['elo_ig'] = $perc > 0 ? wp_date('Y-m-d H:i:s', time() + $perc * MINUTE_IN_SECONDS) : null;
        }

        if ($adat !== []) {
            $wpdb->update(SDH_Kozpont::t('peldany'), $adat, ['id' => $id]);
        }

        return rest_ensure_response(['ok' => true]);
    }

    public static function peldany_muvelet(WP_REST_Request $r)
    {
        $be = (array) $r->get_json_params();
        $e  = SDH_Kozpont::muvelet_kerj((int) $r['id'], sanitize_key((string) ($be['tipus'] ?? '')), is_array($be['parameter'] ?? null) ? $be['parameter'] : [], self::ki());

        return is_int($e) ? rest_ensure_response(['ok' => true, 'id' => $e, 'uzenet' => 'Elküldve. A rendszer a következő jelentkezéskor (legfeljebb fél perc) végrehajtja.']) : self::hiba($e);
    }

    /** A példány elfelejtése: helyet szabadít fel a licencen (ha a gép újra jelentkezik, újra felveszi). */
    public static function peldany_elfelejt(WP_REST_Request $r)
    {
        global $wpdb;

        $id = (int) $r['id'];
        $p  = $wpdb->get_row($wpdb->prepare(self::peldany_sql() . ' WHERE p.id = %d', $id));

        if ($p === null) {
            return self::hiba('Ez a rendszer nincs meg.', 404);
        }

        $wpdb->delete(SDH_Kozpont::t('meres'), ['peldany_id' => $id]);
        $wpdb->delete(SDH_Kozpont::t('muvelet'), ['peldany_id' => $id]);
        $wpdb->delete(SDH_Kozpont::t('peldany'), ['id' => $id]);
        SDH_Kozpont::naplo('peldany', 'Rendszer eltávolítva a nyilvántartásból (' . SDH_Kozpont::peldany_nev($p, (object) ['vevo' => (string) $p->vevo]) . ').', 'figyel', ['vevo_id' => (int) $p->vevo_id, 'licenc_id' => (int) $p->licenc_id], self::ki());

        return rest_ensure_response(['ok' => true]);
    }

    /* =================================================================
     * Vevők
     * ============================================================== */

    public static function vevok(): WP_REST_Response
    {
        global $wpdb;

        $vk = (array) $wpdb->get_results('SELECT * FROM ' . SDH_Kozpont::t('vevo') . ' ORDER BY nev');
        $lk = (array) $wpdb->get_results('SELECT * FROM ' . SDH_Kozpont::t('licenc'));
        $pk = (array) $wpdb->get_results(self::peldany_sql());
        $ma = wp_date('Y-m-d');
        $gy = [];

        foreach ($lk as $l) {
            $gy[(int) $l->vevo_id]['l'][] = $l;
        }

        foreach ($pk as $p) {
            $gy[(int) $p->vevo_id]['p'][] = SDH_Kozpont::statusz($p);
        }

        $ki = [];

        foreach ($vk as $v) {
            $ls = $gy[(int) $v->id]['l'] ?? [];
            $ps = $gy[(int) $v->id]['p'] ?? [];
            $lejar = '';

            foreach ($ls as $l) {
                if (SDH_Kozpont::licenc_allapot($l, $ma) === 'aktiv' && !empty($l->lejar) && ($lejar === '' || (string) $l->lejar < $lejar)) {
                    $lejar = (string) $l->lejar;
                }
            }

            $rossz = count(array_filter($ps, static fn ($s): bool => in_array($s, ['hiba', 'offline'], true)));

            $ki[] = [
                'id' => (int) $v->id, 'nev' => (string) $v->nev, 'kapcsolattarto' => (string) $v->kapcsolattarto, 'email' => (string) $v->email,
                'telefon' => (string) $v->telefon, 'cimkek' => (string) $v->cimkek, 'allapot' => (string) $v->allapot,
                'licenc_db' => count($ls), 'aktiv_licenc' => count(array_filter($ls, static fn ($l): bool => SDH_Kozpont::licenc_allapot($l, $ma) === 'aktiv')),
                'peldany_db' => count($ps), 'online' => count(array_filter($ps, static fn ($s): bool => in_array($s, ['online', 'figyel', 'hiba', 'napi'], true))),
                'rossz' => $rossz, 'lejar' => $lejar, 'havi' => (int) round(array_sum(array_map([self::class, 'havi'], $ls))),
                'letrehozva' => self::ts((string) $v->letrehozva),
            ];
        }

        return rest_ensure_response(['lista' => $ki]);
    }

    public static function vevo(WP_REST_Request $r)
    {
        global $wpdb;

        $id = (int) $r['id'];
        $v  = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('vevo') . ' WHERE id = %d', $id));

        if ($v === null) {
            return self::hiba('Ez a vevő nincs meg.', 404);
        }

        $ls = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('licenc') . ' WHERE vevo_id = %d ORDER BY id DESC', $id));
        $pk = (array) $wpdb->get_results($wpdb->prepare(self::peldany_sql() . ' WHERE l.vevo_id = %d ORDER BY p.utolso_jel DESC', $id));
        $sp = self::sparkok(array_map(static fn ($p): int => (int) $p->id, $pk));
        $db = [];

        foreach ($pk as $p) {
            $db[(int) $p->licenc_id] = ($db[(int) $p->licenc_id] ?? 0) + 1;
        }

        return rest_ensure_response([
            'v' => [
                'id' => (int) $v->id, 'nev' => (string) $v->nev, 'kapcsolattarto' => (string) $v->kapcsolattarto, 'email' => (string) $v->email,
                'telefon' => (string) $v->telefon, 'cim' => (string) $v->cim, 'adoszam' => (string) $v->adoszam, 'weboldal' => (string) $v->weboldal,
                'cimkek' => (string) $v->cimkek, 'allapot' => (string) $v->allapot, 'megjegyzes' => (string) ($v->megjegyzes ?? ''),
                'letrehozva' => self::ts((string) $v->letrehozva),
            ],
            'licencek'  => array_map(static fn ($l): array => self::licenc_ki($l, $db[(int) $l->id] ?? 0), $ls),
            'peldanyok' => array_map(static fn ($p): array => self::peldany_ki($p, $sp), $pk),
            'esemenyek' => self::esemenyek('e.vevo_id = %d', [$id], 60),
            'havi'      => (int) round(array_sum(array_map([self::class, 'havi'], $ls))),
        ]);
    }

    public static function vevo_ment(WP_REST_Request $r)
    {
        $e = SDH_Kozpont::vevo_ment((array) $r->get_json_params(), self::ki());

        return is_int($e) ? rest_ensure_response(['ok' => true, 'id' => $e]) : self::hiba($e);
    }

    public static function vevo_jegyzet(WP_REST_Request $r)
    {
        $sz = trim(sanitize_textarea_field((string) ($r->get_json_params()['szoveg'] ?? '')));

        if ($sz === '') {
            return self::hiba('Írd be a jegyzetet.');
        }

        SDH_Kozpont::naplo('jegyzet', $sz, 'info', ['vevo_id' => (int) $r['id']], self::ki());

        return rest_ensure_response(['ok' => true]);
    }

    /* =================================================================
     * Licencek
     * ============================================================== */

    public static function licencek(): WP_REST_Response
    {
        global $wpdb;

        $ls = (array) $wpdb->get_results('SELECT * FROM ' . SDH_Kozpont::t('licenc') . ' ORDER BY id DESC');
        $db = [];

        foreach ((array) $wpdb->get_results('SELECT licenc_id, COUNT(*) AS db FROM ' . SDH_Kozpont::t('peldany') . ' GROUP BY licenc_id') as $s) {
            $db[(int) $s->licenc_id] = (int) $s->db;
        }

        return rest_ensure_response([
            'lista'     => array_map(static fn ($l): array => self::licenc_ki($l, $db[(int) $l->id] ?? 0), $ls),
            'vevok'     => array_map(static fn ($v): array => ['id' => (int) $v->id, 'nev' => (string) $v->nev], (array) $wpdb->get_results('SELECT id, nev FROM ' . SDH_Kozpont::t('vevo') . ' ORDER BY nev')),
            'katalogus' => SDH_Kozpont::katalogus(),
            'csomagok'  => SDH_Kozpont::beallitas()['csomagok'],
            'jogok'     => SDH_Kozpont::JOGOK,
        ]);
    }

    public static function licenc_ment(WP_REST_Request $r)
    {
        $e = SDH_Kozpont::licenc_ment((array) $r->get_json_params(), self::ki());

        if (!is_int($e)) {
            return self::hiba($e);
        }

        global $wpdb;
        $l = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('licenc') . ' WHERE id = %d', $e));

        return rest_ensure_response(['ok' => true, 'licenc' => self::licenc_ki($l)]);
    }

    public static function licenc_muvelet(WP_REST_Request $r)
    {
        $e = SDH_Kozpont::licenc_muvelet((int) $r['id'], sanitize_key((string) ($r->get_json_params()['muvelet'] ?? '')), self::ki());

        return $e === '' ? rest_ensure_response(['ok' => true]) : self::hiba($e);
    }

    /* =================================================================
     * Kiadások
     * ============================================================== */

    public static function kiadasok(): WP_REST_Response
    {
        global $wpdb;

        $vdb = [];

        foreach ((array) $wpdb->get_results('SELECT verzio, COUNT(*) AS db FROM ' . SDH_Kozpont::t('peldany') . ' GROUP BY verzio') as $s) {
            $vdb[(string) $s->verzio] = (int) $s->db;
        }

        $ks = (array) $wpdb->get_results('SELECT * FROM ' . SDH_Kozpont::t('kiadas') . ' ORDER BY id DESC LIMIT 100');

        return rest_ensure_response([
            'lista' => array_map(static fn ($k): array => [
                'id' => (int) $k->id, 'verzio' => (string) $k->verzio, 'meret' => (int) $k->meret, 'sha256' => (string) $k->sha256,
                'csatorna' => (string) $k->csatorna, 'megjegyzes' => (string) ($k->megjegyzes ?? ''), 'ido' => self::ts((string) $k->ido),
                'ki' => (string) $k->ki, 'van' => is_file((string) $k->fajl), 'peldany_db' => $vdb[(string) $k->verzio] ?? 0,
            ], $ks),
            'stabil' => SDH_Kozpont::kiadas('stabil')['verzio'],
            'teszt'  => SDH_Kozpont::kiadas('teszt')['verzio'],
            'verziok' => $vdb,
        ]);
    }

    public static function kiadas_feltolt(WP_REST_Request $r)
    {
        $f = $r->get_file_params()['zip'] ?? null;

        if (!is_array($f) || empty($f['tmp_name']) || (int) ($f['error'] ?? 1) !== 0) {
            return self::hiba('Nincs feltöltött fájl (vagy túl nagy a szervernek).');
        }

        $e = SDH_Kozpont::kiadas_be((string) $f['tmp_name'], (string) $r->get_param('csatorna'), (string) $r->get_param('megjegyzes'), self::ki());

        return $e === '' ? rest_ensure_response(['ok' => true]) : self::hiba($e);
    }

    public static function kiadas_ment(WP_REST_Request $r)
    {
        global $wpdb;

        $id = (int) $r['id'];
        $k  = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('kiadas') . ' WHERE id = %d', $id));
        $be = (array) $r->get_json_params();

        if ($k === null) {
            return self::hiba('Ez a kiadás nincs meg.', 404);
        }

        if (!empty($be['torol'])) {
            if (is_file((string) $k->fajl)) {
                wp_delete_file((string) $k->fajl);
            }

            $wpdb->delete(SDH_Kozpont::t('kiadas'), ['id' => $id]);
            SDH_Kozpont::naplo('kiadas', 'Kiadás törölve: ' . (string) $k->verzio . '.', 'figyel', [], self::ki());

            return rest_ensure_response(['ok' => true]);
        }

        $adat = [];

        if (isset($be['csatorna'])) {
            $adat['csatorna'] = (string) $be['csatorna'] === 'teszt' ? 'teszt' : 'stabil';
        }

        if (isset($be['megjegyzes'])) {
            $adat['megjegyzes'] = sanitize_textarea_field((string) $be['megjegyzes']);
        }

        if ($adat !== []) {
            $wpdb->update(SDH_Kozpont::t('kiadas'), $adat, ['id' => $id]);

            if (isset($adat['csatorna']) && $adat['csatorna'] !== (string) $k->csatorna) {
                SDH_Kozpont::naplo('kiadas', 'Kiadás ' . (string) $k->verzio . ': ' . ($adat['csatorna'] === 'stabil' ? 'kiadva mindenkinek (stabil)' : 'vissza a teszt csatornára') . '.', 'info', [], self::ki());
            }
        }

        return rest_ensure_response(['ok' => true]);
    }

    /* =================================================================
     * Támogatás, napló, keresés, beállítás
     * ============================================================== */

    public static function tamogatas(): WP_REST_Response
    {
        global $wpdb;

        $most = SDH_Kozpont::most();
        $ts   = (array) $wpdb->get_results('SELECT t.*, l.vevo, l.vevo_id, p.id AS pid, p.cimke FROM ' . SDH_Kozpont::t('tamogatas') . ' t LEFT JOIN ' . SDH_Kozpont::t('licenc') . ' l ON l.id = t.licenc_id LEFT JOIN ' . SDH_Kozpont::t('peldany') . ' p ON p.peldany = t.peldany ORDER BY t.id DESC LIMIT 100');
        $kk   = (array) $wpdb->get_results('SELECT m.*, l.vevo, p.cimke, p.peldany FROM ' . SDH_Kozpont::t('muvelet') . ' m LEFT JOIN ' . SDH_Kozpont::t('peldany') . ' p ON p.id = m.peldany_id LEFT JOIN ' . SDH_Kozpont::t('licenc') . " l ON l.id = p.licenc_id WHERE m.tipus = 'tamogatas_keres' ORDER BY m.id DESC LIMIT 30");

        return rest_ensure_response([
            'linkek' => array_map(static fn ($t): array => [
                'id' => (int) $t->id, 'vevo' => (string) ($t->vevo ?? ''), 'vevo_id' => (int) ($t->vevo_id ?? 0), 'peldany_id' => (int) ($t->pid ?? 0),
                'cimke' => (string) ($t->cimke ?? ''), 'ki' => (string) $t->ki, 'erkezett' => self::ts((string) $t->erkezett), 'lejar' => self::ts((string) $t->lejar),
                'el' => (string) $t->lejar > $most, 'link' => (string) $t->lejar > $most ? (string) $t->link : '',
            ], $ts),
            'kerelmek' => array_map(static fn ($m): array => [
                'id' => (int) $m->id, 'vevo' => (string) ($m->vevo ?? ''), 'peldany_id' => (int) $m->peldany_id, 'cimke' => (string) ($m->cimke ?? ''),
                'allapot' => (string) $m->allapot, 'letrehozva' => self::ts((string) $m->letrehozva), 'kesz' => self::ts((string) $m->kesz),
                'eredmeny' => json_decode((string) ($m->eredmeny ?? ''), true) ?: null, 'parameter' => json_decode((string) ($m->parameter ?? ''), true) ?: [],
            ], $kk),
        ]);
    }

    public static function naplo(WP_REST_Request $r): WP_REST_Response
    {
        $w    = ['1=1'];
        $args = [];

        foreach (['tipus', 'szint'] as $k) {
            $v = sanitize_key((string) $r->get_param($k));

            if ($v !== '') {
                $w[]    = 'e.' . $k . ' = %s';
                $args[] = $v;
            }
        }

        foreach (['vevo_id', 'peldany_id'] as $k) {
            if ((int) $r->get_param($k) > 0) {
                $w[]    = 'e.' . $k . ' = %d';
                $args[] = (int) $r->get_param($k);
            }
        }

        $q = trim((string) $r->get_param('q'));

        if ($q !== '') {
            global $wpdb;
            $w[]    = 'e.szoveg LIKE %s';
            $args[] = '%' . $wpdb->esc_like($q) . '%';
        }

        $elotte = (int) $r->get_param('elotte');

        if ($elotte > 0) {
            $w[]    = 'e.id < %d';
            $args[] = $elotte;
        }

        return rest_ensure_response(['lista' => self::esemenyek(implode(' AND ', $w), $args, 80)]);
    }

    public static function kereses(WP_REST_Request $r): WP_REST_Response
    {
        global $wpdb;

        $q = trim((string) $r->get_param('q'));

        if (mb_strlen($q) < 2) {
            return rest_ensure_response(['lista' => []]);
        }

        $l  = '%' . $wpdb->esc_like($q) . '%';
        $ki = [];

        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, nev, email, telefon FROM ' . SDH_Kozpont::t('vevo') . ' WHERE nev LIKE %s OR email LIKE %s OR telefon LIKE %s OR kapcsolattarto LIKE %s LIMIT 8', $l, $l, $l, $l)) as $v) {
            $ki[] = ['tipus' => 'vevo', 'id' => (int) $v->id, 'cim' => (string) $v->nev, 'al' => trim((string) $v->email . ' ' . (string) $v->telefon)];
        }

        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, kulcs, vevo, vevo_id FROM ' . SDH_Kozpont::t('licenc') . ' WHERE kulcs LIKE %s OR vevo LIKE %s OR vevo_azonosito LIKE %s LIMIT 8', $l, $l, $l)) as $x) {
            $ki[] = ['tipus' => 'licenc', 'id' => (int) $x->id, 'vevo_id' => (int) $x->vevo_id, 'cim' => (string) $x->kulcs, 'al' => (string) $x->vevo];
        }

        foreach ((array) $wpdb->get_results($wpdb->prepare(self::peldany_sql() . ' WHERE p.cimke LIKE %s OR p.peldany LIKE %s OR p.verzio LIKE %s OR l.vevo LIKE %s LIMIT 8', $l, $l, $l, $l)) as $p) {
            $ki[] = ['tipus' => 'peldany', 'id' => (int) $p->id, 'cim' => SDH_Kozpont::peldany_nev($p, (object) ['vevo' => (string) $p->vevo]), 'al' => (string) $p->verzio . ' · ' . SDH_Kozpont::statusz($p)];
        }

        return rest_ensure_response(['lista' => $ki]);
    }

    public static function beallitas(): WP_REST_Response
    {
        return rest_ensure_response([
            'b'         => SDH_Kozpont::beallitas(),
            'katalogus' => SDH_Kozpont::katalogus(),
            'kulcs'     => base64_encode(SDH_Kozpont::kulcspar()['nyilvanos']),
            'cim'       => home_url('/'),
            'verzio'    => SDH_KOZPONT_VERSION,
            'admin_email' => (string) get_option('admin_email'),
        ]);
    }

    public static function beallitas_ment(WP_REST_Request $r): WP_REST_Response
    {
        return rest_ensure_response(['ok' => true, 'b' => SDH_Kozpont::beallitas_ment((array) $r->get_json_params())]);
    }

    public static function tema(WP_REST_Request $r): WP_REST_Response
    {
        $t = (string) ($r->get_json_params()['tema'] ?? '');
        update_user_meta(get_current_user_id(), 'sdh_kozpont_tema', in_array($t, ['sotet', 'vilagos'], true) ? $t : '');

        return rest_ensure_response(['ok' => true]);
    }
}
