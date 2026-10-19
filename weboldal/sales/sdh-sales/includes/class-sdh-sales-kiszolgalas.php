<?php
/**
 * SDH Értékesítés – kiszolgálási lánc (ARCHITEKTURA.md §5.4): fizetett rendelésből licenc, végszámla, üdvözlő levél,
 * időpont-ajánlat, értesítés – lépésenként, idempotensen, újrapróbával; lejárat előtti emlékeztetők.
 *
 * API:
 *   indit(int $rendeles_id): array          – a `sdh_sales_fizetve` hookra fut; többször hívva sem csinál semmit kétszer
 *   lepes_ujra(int $rendeles_id, string $lepes): array – adminisztrátori újraindítás (hibás / váró lépés; a sikeres nem fut újra)
 *   allapot(int $rendeles_id): ?array       – {rendeles, allapot, tipus, kesz, lepesek{…}} (a letöltő link nélkül)
 *   cron($most = null): void                – SDH_Sales::CRON (0 argumentummal regisztrálva; nem int argumentum = most): esedékes újrapróbák + napi lejárat-emlékeztetők
 *   emlekeztetok(?int $most = null): int    – a lejárat előtti levelek (30 / 7 / 1 nap; havi licencnél 7 / 1)
 *   licencek(): array                       – a Központ válaszaiból tárolt licencek (kulcs → lejárat, e-mail, …)
 * Admin REST: GET /admin/kiszolgalas/(?P<id>\d+), POST /admin/kiszolgalas/(?P<id>\d+)/ujra {lepes}.
 *
 * `rendeles.lanc` (JSON): {v:1, indult, frissitve, kesz: bool, lepesek: {kozpont|szamla|email|idopont|ertesites:
 *   {allapot: var|ok|hiba|kihagyva, probalkozas, uzenet, ido, kovetkezo (unix, 0 = most), kezi?: true, eredmeny?: {…}}}}
 *   - `var`: még nem futott, vagy újrapróbára vár (`kovetkezo`); `kezi`: adminisztrátori indításra vár (nem próbálja magától).
 *   - `hiba`: végleges (MAX_PROBA után, vagy nem átmeneti hiba) – levél megy az `email.ertesites` címre.
 *   - Kritikus lépés (kozpont, email) hibájánál a lánc megáll; a többinél továbbmegy. A `szamla` az első SZAMLA_VAR
 *     próbálkozásig megvárja az üdvözlő levél (hogy a számla is mellé kerüljön), utána a levél nélküle megy.
 * A rendelés állapota `kiszolgalva` lesz, amint a licenc és az üdvözlő levél kész.
 *
 * Tételek (`rendeles.tetelek`, a sales-backend `SDH_Sales_Ar::szamol()` sorai): {tipus: csomag|modul|szolgaltatas|
 *   felhasznalo|telephely, kulcs, mennyiseg?, netto?, felhasznalo?, telephely?}. A licenc adatai: a csomag (tartalom-tár)
 *   moduljai + a `csomagban` jelölt modulok + a külön vett modulok; felhasználó / példány a csomag alapja + a vett extra.
 *   Felülírható: `sdh_sales_kiszolgalas_licenc` szűrő ($licenc, $rendeles).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Kiszolgalas
{
    public const LEPESEK = ['kozpont', 'szamla', 'email', 'idopont', 'ertesites'];
    public const LEPES_NEV = [
        'kozpont'   => 'Licenc a Központban',
        'szamla'    => 'Végszámla',
        'email'     => 'Üdvözlő levél',
        'idopont'   => 'Időpont-ajánlat',
        'ertesites' => 'Értesítés',
    ];

    /** Kritikus lépés hibájánál a lánc megáll. */
    private const KRITIKUS = ['kozpont', 'email'];

    /** Újrapróba-várakozás (perc) a sikertelen próbálkozások száma szerint; utána mindig az utolsó. */
    public const VARAKOZAS = [1, 5, 15, 60, 240];
    public const MAX_PROBA = 8;

    /** Ennyi sikertelen számlapróbáig vár rá az üdvözlő levél. */
    public const SZAMLA_VAR = 3;

    /** Lejárat előtti emlékeztetők (nap). */
    public const EMLEKEZTETO = [30, 7, 1];

    private const OPT_LICENC = 'sdh_sales_licenc_lejar';
    private const HONAPOK    = [1 => 'január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];

    public static function init(): void
    {
        add_action('sdh_sales_fizetve', [self::class, 'fizetve_hook'], 20, 1);
        add_action(SDH_Sales::CRON, [self::class, 'cron'], 10, 0);

        SDH_Sales_Rest::ut('/admin/kiszolgalas/(?P<id>\d+)', 'GET', [self::class, 'rest_allapot'], 'admin');
        SDH_Sales_Rest::ut('/admin/kiszolgalas/(?P<id>\d+)/ujra', 'POST', [self::class, 'rest_ujra'], 'admin', ['kiszolgalas_ujra', 30, 60]);
    }

    /** @param mixed $rendeles_id */
    public static function fizetve_hook($rendeles_id): void
    {
        self::indit((int) $rendeles_id);
    }

    /* =================================================================
     * Rendelés
     * ============================================================== */

    /** @return array<string, mixed>|null a sor, a vevo / tetelek / lanc tömbként */
    public static function rendeles(int $id): ?array
    {
        global $wpdb;

        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE id = %d', $id), ARRAY_A);

        if (!is_array($r)) {
            return null;
        }

        foreach (['vevo', 'tetelek', 'lanc'] as $k) {
            $d     = json_decode((string) ($r[$k] ?? ''), true);
            $r[$k] = is_array($d) ? $d : [];
        }

        return $r;
    }

    /** @param array<string, mixed> $r */
    private static function fizetett(array $r): bool
    {
        return in_array((string) $r['allapot'], ['fizetve', 'kiszolgalva'], true)
            || ((int) $r['brutto'] > 0 && (int) ($r['fizetett'] ?? 0) >= (int) $r['brutto']);
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function lanc_uj(array $r): array
    {
        $l = ['v' => 1, 'indult' => gmdate('Y-m-d H:i:s'), 'frissitve' => gmdate('Y-m-d H:i:s'), 'kesz' => false, 'lepesek' => []];

        foreach (self::LEPESEK as $s) {
            $l['lepesek'][$s] = ['allapot' => 'var', 'probalkozas' => 0, 'uzenet' => '', 'ido' => '', 'kovetkezo' => 0];
        }

        return $l;
    }

    /** @param array<string, mixed> $lanc */
    private static function lanc_ment(int $id, array $lanc, array $extra = []): void
    {
        global $wpdb;

        $lanc['frissitve'] = gmdate('Y-m-d H:i:s');
        $wpdb->update(SDH_Sales_Schema::t('rendeles'), ['lanc' => (string) wp_json_encode($lanc, JSON_UNESCAPED_UNICODE), 'modositva' => current_time('mysql', true)] + $extra, ['id' => $id]);
    }

    /* =================================================================
     * Zár (a korlat tábla elsődleges kulcsa – párhuzamos futás ellen)
     * ============================================================== */

    private static function zar(int $id, int $mp = 120): bool
    {
        global $wpdb;

        $t = SDH_Sales_Schema::t('korlat');
        $k = 'zar:kiszolgalas:' . $id;
        $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE kulcs = %s AND lejar < %s", $k, gmdate('Y-m-d H:i:s')));
        $elnyom = $wpdb->suppress_errors(true);
        $ok     = $wpdb->insert($t, ['kulcs' => $k, 'db' => 1, 'lejar' => gmdate('Y-m-d H:i:s', time() + $mp)]);
        $wpdb->suppress_errors($elnyom);

        return (bool) $ok;
    }

    private static function elenged(int $id): void
    {
        global $wpdb;

        $wpdb->delete(SDH_Sales_Schema::t('korlat'), ['kulcs' => 'zar:kiszolgalas:' . $id]);
    }

    /* =================================================================
     * Lépésgép
     * ============================================================== */

    /**
     * A lánc indítása / folytatása. Már kész lépés nem fut újra; váró lépés csak ha esedékes.
     *
     * @return array{ok: bool, uzenet: string, lanc?: array<string, mixed>}
     */
    public static function indit(int $rendeles_id, ?int $most = null, string $kezi = ''): array
    {
        $r = $rendeles_id > 0 ? self::rendeles($rendeles_id) : null;

        if ($r === null) {
            return ['ok' => false, 'uzenet' => 'Nincs ilyen rendelés.'];
        }

        if (!self::fizetett($r)) {
            return ['ok' => false, 'uzenet' => 'A rendelés még nincs kifizetve – a kiszolgálás a fizetés után indul.'];
        }

        if (!self::zar($rendeles_id)) {
            return ['ok' => false, 'uzenet' => 'Ezt a rendelést most egy másik folyamat szolgálja ki.'];
        }

        try {
            return self::futtat($rendeles_id, $most ?? time(), $kezi);
        } finally {
            self::elenged($rendeles_id);
        }
    }

    /** @return array{ok: bool, uzenet: string, lanc: array<string, mixed>} */
    private static function futtat(int $id, int $most, string $kezi): array
    {
        $r    = (array) self::rendeles($id);
        $lanc = $r['lanc'];

        if (empty($lanc['lepesek']) || !is_array($lanc['lepesek'])) {
            $lanc = self::lanc_uj($r);
            SDH_Sales_Naplo::ir('kiszolgalas', 'Kiszolgálás indul: ' . (string) $r['azon'] . '.', 'info', 'rendeles', $id);
        }

        foreach (self::LEPESEK as $s) {
            $lanc['lepesek'][$s] = (array) ($lanc['lepesek'][$s] ?? []) + ['allapot' => 'var', 'probalkozas' => 0, 'uzenet' => '', 'ido' => '', 'kovetkezo' => 0];
        }

        $allj = false;

        foreach (self::LEPESEK as $s) {
            $l = $lanc['lepesek'][$s];

            if (in_array($l['allapot'], ['ok', 'kihagyva'], true)) {
                continue;
            }

            if ($l['allapot'] === 'hiba' && $kezi !== $s) {
                if (in_array($s, self::KRITIKUS, true)) {
                    $allj = true;
                    break;
                }

                continue;
            }

            if (!empty($l['kezi']) && $kezi !== $s) {
                continue;
            }

            if ((int) $l['kovetkezo'] > $most && $kezi !== $s) {
                if (self::blokkol($s, $l)) {
                    $allj = true;
                    break;
                }

                continue;
            }

            $e = self::lepes($s, $r, $lanc, $kezi === $s);
            $l = self::eredmeny_beir($s, $l, $e, $most, $r);
            $lanc['lepesek'][$s] = $l;

            if ($l['allapot'] === 'ok') {
                $r = (array) self::rendeles($id) ?: $r;
            }

            self::lanc_ment($id, $lanc);

            if ($l['allapot'] === 'ok' || $l['allapot'] === 'kihagyva') {
                continue;
            }

            if ($l['allapot'] === 'hiba') {
                self::hiba_ertesit($r, $s, $l);

                if (in_array($s, self::KRITIKUS, true)) {
                    $allj = true;
                    break;
                }

                continue;
            }

            if (!empty($l['kezi'])) {
                continue;
            }

            if (self::blokkol($s, $l)) {
                $allj = true;
                break;
            }
        }

        $kesz  = !$allj;
        $vegso = true;

        foreach ($lanc['lepesek'] as $l) {
            if (!in_array($l['allapot'], ['ok', 'kihagyva', 'hiba'], true)) {
                $vegso = false;
            }
        }

        $extra = [];
        $kiszolgalva = $lanc['lepesek']['kozpont']['allapot'] !== 'var' && $lanc['lepesek']['kozpont']['allapot'] !== 'hiba'
            && $lanc['lepesek']['email']['allapot'] === 'ok';

        if ($kiszolgalva && (string) $r['allapot'] === 'fizetve') {
            $extra['allapot'] = 'kiszolgalva';
            SDH_Sales_Naplo::ir('kiszolgalas', 'Rendelés kiszolgálva: ' . (string) $r['azon'] . '.', 'siker', 'rendeles', $id, ['allapot' => 'fizetve'], ['allapot' => 'kiszolgalva']);
            do_action('sdh_sales_kiszolgalva', $id);
        }

        $lanc['kesz'] = $kesz && $vegso;
        self::lanc_ment($id, $lanc, $extra);

        $uz = $lanc['kesz'] ? 'A kiszolgálás kész.' : ($allj ? 'A kiszolgálás folyamatban (újrapróbára vagy beavatkozásra vár).' : 'A kiszolgálás folyamatban.');

        return ['ok' => !$allj, 'uzenet' => $uz, 'lanc' => $lanc];
    }

    /** Megállítja-e a láncot ez a (váró / újrapróbára váró) lépés. @param array<string, mixed> $l */
    private static function blokkol(string $s, array $l): bool
    {
        if (in_array($s, self::KRITIKUS, true)) {
            return true;
        }

        return $s === 'szamla' && (int) $l['probalkozas'] < self::SZAMLA_VAR;
    }

    /**
     * @param array<string, mixed> $l régi lépésállapot
     * @param array<string, mixed> $e a lépés eredménye: {allapot: ok|kihagyva|hiba|ujra|kezi, uzenet, eredmeny?}
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function eredmeny_beir(string $s, array $l, array $e, int $most, array $r): array
    {
        $a  = (string) $e['allapot'];
        $id = (int) $r['id'];
        $l['ido']    = gmdate('Y-m-d H:i:s', $most);
        $l['uzenet'] = mb_substr((string) ($e['uzenet'] ?? ''), 0, 500);
        unset($l['kezi']);

        if (isset($e['eredmeny']) && is_array($e['eredmeny'])) {
            $l['eredmeny'] = $e['eredmeny'];
        }

        if ($a === 'kezi') {
            $l['allapot']   = 'var';
            $l['kezi']      = true;
            $l['kovetkezo'] = 0;
            SDH_Sales_Naplo::ir('kiszolgalas', self::LEPES_NEV[$s] . ' – adminisztrátorra vár (' . (string) $r['azon'] . '): ' . $l['uzenet'], 'figyel', 'rendeles', $id);

            return $l;
        }

        if ($a === 'ok' || $a === 'kihagyva') {
            $l['allapot']     = $a;
            $l['probalkozas'] = (int) $l['probalkozas'] + ($a === 'ok' ? 1 : 0);
            $l['kovetkezo']   = 0;
            SDH_Sales_Naplo::ir('kiszolgalas', self::LEPES_NEV[$s] . ($a === 'ok' ? ' – kész' : ' – kihagyva') . ' (' . (string) $r['azon'] . ')' . ($l['uzenet'] !== '' ? ': ' . $l['uzenet'] : '.'), $a === 'ok' ? 'siker' : 'info', 'rendeles', $id);

            return $l;
        }

        $l['probalkozas'] = (int) $l['probalkozas'] + 1;

        if ($a === 'hiba' || $l['probalkozas'] >= self::MAX_PROBA) {
            $l['allapot']   = 'hiba';
            $l['kovetkezo'] = 0;
            SDH_Sales_Naplo::ir('kiszolgalas', self::LEPES_NEV[$s] . ' – HIBA (' . (string) $r['azon'] . ', ' . (int) $l['probalkozas'] . '. próba): ' . $l['uzenet'], 'hiba', 'rendeles', $id);

            return $l;
        }

        $perc           = self::VARAKOZAS[min($l['probalkozas'] - 1, count(self::VARAKOZAS) - 1)];
        $l['allapot']   = 'var';
        $l['kovetkezo'] = $most + $perc * 60;
        SDH_Sales_Naplo::ir('kiszolgalas', self::LEPES_NEV[$s] . ' – nem sikerült (' . (string) $r['azon'] . ', ' . (int) $l['probalkozas'] . '. próba), újra ' . $perc . ' perc múlva: ' . $l['uzenet'], 'figyel', 'rendeles', $id);

        return $l;
    }

    /**
     * Egy lépés futtatása.
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed> $lanc
     * @return array<string, mixed> {allapot: ok|kihagyva|hiba|ujra|kezi, uzenet, eredmeny?}
     */
    private static function lepes(string $s, array $r, array $lanc, bool $kezi): array
    {
        try {
            switch ($s) {
                case 'kozpont':
                    return self::lepes_kozpont($r);
                case 'szamla':
                    return self::lepes_szamla($r, $kezi);
                case 'email':
                    return self::lepes_email($r, $lanc);
                case 'idopont':
                    return self::lepes_idopont($r);
                case 'ertesites':
                    return self::lepes_ertesites($r, $lanc);
            }
        } catch (Throwable $e) {
            return ['allapot' => 'ujra', 'uzenet' => 'Belső hiba: ' . mb_substr($e->getMessage(), 0, 300)];
        }

        return ['allapot' => 'hiba', 'uzenet' => 'Ismeretlen lépés.'];
    }

    /* =================================================================
     * 1. Központ
     * ============================================================== */

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function vevo_adat(array $r): array
    {
        $v = is_array($r['vevo'] ?? null) ? $r['vevo'] : [];
        $s = static fn ($x): string => is_scalar($x) ? trim((string) $x) : '';

        $cim = $v['cim'] ?? '';

        if (is_array($cim)) {
            $cim = trim(implode(' ', array_filter([$s($cim['irsz'] ?? $cim['iranyitoszam'] ?? ''), $s($cim['telepules'] ?? $cim['varos'] ?? '')])) . ', ' . $s($cim['cim'] ?? $cim['utca'] ?? ''), ', ');
        } else {
            $cim = $s($cim);

            if ($cim === '' && ($s($v['telepules'] ?? '') !== '' || $s($v['irsz'] ?? '') !== '')) {
                $cim = trim($s($v['irsz'] ?? '') . ' ' . $s($v['telepules'] ?? '') . ', ' . $s($v['utca'] ?? ''), ', ');
            }
        }

        $kapcs = $s($v['kapcsolattarto'] ?? '') ?: $s($v['nev'] ?? '');

        return [
            'nev'            => $s($v['ceg'] ?? '') ?: ($s($v['cegnev'] ?? '') ?: ($s($v['nev'] ?? '') ?: $kapcs)),
            'adoszam'        => $s($v['adoszam'] ?? ''),
            'cim'            => $cim,
            'kapcsolattarto' => $kapcs,
            'email'          => sanitize_email($s($r['email'] ?? '') ?: $s($v['email'] ?? '')),
            'telefon'        => $s($v['telefon'] ?? ''),
        ];
    }

    /**
     * A licenc paraméterei a rendelés tételeiből.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function licenc_adat(array $r): array
    {
        $tetelek = self::tetelek($r);
        $csomag  = '';
        $cs_adat = [];
        $mod     = [];
        $minden  = false;
        $felh    = null;
        $peld    = null;
        $extra_f = 0;
        $extra_p = 0;
        $dij     = 0;
        $van_dij = false;

        foreach ($tetelek as $t) {
            if (!is_array($t)) {
                continue;
            }

            $tip   = sanitize_key((string) ($t['tipus'] ?? ''));
            $kulcs = sanitize_key((string) ($t['kulcs'] ?? ''));
            $menny = max(1, (int) ($t['mennyiseg'] ?? $t['db'] ?? 1));

            if ($tip === '' && $kulcs !== '' && class_exists('SDH_Sales_Tartalom')) {
                $tip = SDH_Sales_Tartalom::egy('csomag', $kulcs) !== null ? 'csomag' : (SDH_Sales_Tartalom::egy('modul', $kulcs) !== null ? 'modul' : 'szolgaltatas');
            }

            if ($tip !== 'szolgaltatas' && isset($t['netto']) && is_numeric($t['netto'])) {
                $dij    += (int) $t['netto'];
                $van_dij = true;
            }

            switch ($tip) {
                case 'csomag':
                    $csomag  = $kulcs;
                    $cs_adat = class_exists('SDH_Sales_Tartalom') ? (array) (SDH_Sales_Tartalom::egy('csomag', $kulcs)['adat'] ?? []) : [];
                    $felh    = isset($t['felhasznalo']) ? (int) $t['felhasznalo'] : $felh;
                    $peld    = isset($t['telephely']) ? (int) $t['telephely'] : (isset($t['max_peldany']) ? (int) $t['max_peldany'] : $peld);
                    break;
                case 'modul':
                    if ($kulcs !== '') {
                        $mod[] = $kulcs;
                    }
                    break;
                case 'felhasznalo':
                    $extra_f += $menny;
                    break;
                case 'telephely':
                    $extra_p += $menny;
                    break;
            }
        }

        if ($csomag !== '' && class_exists('SDH_Sales_Katalogus') && method_exists('SDH_Sales_Katalogus', 'csomag_modulok')) {
            $cm = SDH_Sales_Katalogus::csomag_modulok($csomag);

            if (in_array('*', $cm, true)) {
                $minden = true;
            } else {
                $mod = array_merge($mod, array_map('sanitize_key', $cm));
            }
        } elseif ($csomag !== '') {
            $cm = $cs_adat['modulok'] ?? [];

            if ($cm === '*' || (is_array($cm) && in_array('*', $cm, true))) {
                $minden = true;
            } else {
                $mod = array_merge($mod, array_map('sanitize_key', (array) $cm));
            }

            if (class_exists('SDH_Sales_Tartalom')) {
                foreach (SDH_Sales_Tartalom::lista('modul', false) as $m) {
                    if (in_array($csomag, (array) ($m['adat']['csomagban'] ?? []), true)) {
                        $mod[] = sanitize_key((string) $m['kulcs']);
                    }
                }
            }
        }

        // A teljes árszámítás-eredmény (ha így tárolták) már a végső keretet adja: azt használjuk, az extra sorok nélkül.
        $osszes = (array) ($r['tetelek'] ?? []);

        if ($csomag !== '' && isset($osszes['sorok'], $osszes['felhasznalo'])) {
            $felh    = (int) $osszes['felhasznalo'];
            $peld    = isset($osszes['max_peldany']) && (int) $osszes['max_peldany'] > 0 ? (int) $osszes['max_peldany'] : $peld;
            $extra_f = 0;
            $extra_p = 0;
        }

        $felh = $felh ?? (isset($cs_adat['felhasznalo_alap']) ? (int) $cs_adat['felhasznalo_alap'] : 0);
        $peld = $peld ?? (int) ($cs_adat['max_peldany'] ?? $cs_adat['telephely_alap'] ?? 1);

        $l = [
            'csomag'          => (string) ($cs_adat['nev'] ?? $csomag),
            'csomag_kulcs'    => $csomag,
            'modulok'         => $minden ? '*' : array_values(array_unique(array_filter($mod))),
            'max_felhasznalo' => $felh > 0 ? $felh + $extra_f : 0,
            'max_peldany'     => max(1, $peld + $extra_p),
            'ciklus'          => in_array((string) $r['ciklus'], ['havi', 'eves', 'egyszeri'], true) ? (string) $r['ciklus'] : 'eves',
            'dij'             => max(0, $van_dij ? $dij : (int) $r['netto']),
            'extra_felhasznalo' => $extra_f,
            'extra_peldany'     => $extra_p,
        ];

        return (array) apply_filters('sdh_sales_kiszolgalas_licenc', $l, $r);
    }

    /**
     * A rendelés tételsorai: a `tetelek` mező lehet sorlista, vagy a teljes `SDH_Sales_Ar::szamol()` eredmény (`sorok`).
     *
     * @param array<string, mixed> $r
     * @return array<int, mixed>
     */
    public static function tetelek(array $r): array
    {
        $t = (array) ($r['tetelek'] ?? []);

        return isset($t['sorok']) && is_array($t['sorok']) ? array_values($t['sorok']) : array_values($t);
    }

    /** @param array<string, mixed> $r */
    private static function muvelet(array $r): string
    {
        return ['uj' => 'uj', 'megujitas' => 'megujit', 'bovites' => 'bovit'][(string) $r['tipus']] ?? '';
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function lepes_kozpont(array $r): array
    {
        global $wpdb;

        $m = self::muvelet($r);

        if ($m === '') {
            return ['allapot' => 'kihagyva', 'uzenet' => 'Szolgáltatás-rendelés: nincs licenc.'];
        }

        $lic = self::licenc_adat($r);

        if ($m === 'uj' && $lic['csomag_kulcs'] === '' && $lic['modulok'] === []) {
            return ['allapot' => 'hiba', 'uzenet' => 'A rendelésben nincs csomag vagy modul – nem tudom, milyen licenc kell.'];
        }

        if ($m !== 'uj' && (string) $r['licenc_kulcs'] === '') {
            return ['allapot' => 'hiba', 'uzenet' => 'Megújítás / bővítés licenckulcs nélkül.'];
        }

        $kl = array_diff_key($lic, ['csomag_kulcs' => 1, 'extra_felhasznalo' => 1, 'extra_peldany' => 1]);

        if ($m === 'bovit' && $lic['csomag_kulcs'] === '') {
            // Csak modul / extra hely: a meglévő korlátokhoz adódik, a többi nem változik.
            $kl = ['modulok' => $lic['modulok'], 'extra_felhasznalo' => (int) $lic['extra_felhasznalo'], 'extra_peldany' => (int) $lic['extra_peldany'], 'dij' => (int) $lic['dij']];
        } elseif ($m === 'megujit') {
            $kl = ['ciklus' => $lic['ciklus'], 'dij' => (int) $lic['dij']];
        }

        $keres = [
            'muvelet'  => $m,
            'rendeles' => (string) $r['azon'],
            'vevo'     => self::vevo_adat($r),
            'licenc'   => $kl,
        ];

        if ($m !== 'uj') {
            $keres['licenc_kulcs'] = (string) $r['licenc_kulcs'];
        }

        $v = SDH_Sales_Kozpont_Kapcsolat::kuld($keres);

        if (empty($v['ok'])) {
            return ['allapot' => !empty($v['atmeneti']) ? 'ujra' : 'hiba', 'uzenet' => (string) ($v['uzenet'] ?? 'A Központ hibát jelzett.')];
        }

        $kulcs = strtoupper(sanitize_text_field((string) ($v['licenc_kulcs'] ?? '')));

        if (preg_match('/^[A-Z0-9]{2,10}(-[A-Z0-9]{4}){4}$/', $kulcs) !== 1) {
            return ['allapot' => 'ujra', 'uzenet' => 'A Központ nem adott érvényes licenckulcsot.'];
        }

        $e = [
            'licenc_kulcs'    => $kulcs,
            'licenc_id'       => (int) ($v['licenc_id'] ?? 0),
            'vevo_id'         => (int) ($v['vevo_id'] ?? 0),
            'lejar'           => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($v['lejar'] ?? '')) === 1 ? (string) $v['lejar'] : '',
            'letoltes_url'    => esc_url_raw((string) ($v['letoltes_url'] ?? '')),
            'csomag'          => sanitize_text_field((string) ($v['csomag'] ?? $lic['csomag'])),
            'modulok'         => ($v['modulok'] ?? $lic['modulok']) === '*' ? '*' : array_values(array_map('sanitize_key', (array) ($v['modulok'] ?? $lic['modulok']))),
            'max_felhasznalo' => (int) ($v['max_felhasznalo'] ?? $lic['max_felhasznalo']),
            'max_peldany'     => (int) ($v['max_peldany'] ?? $lic['max_peldany']),
            'ciklus'          => sanitize_key((string) ($v['ciklus'] ?? $lic['ciklus'])),
            'uj_modulok'      => $m === 'bovit' ? ($lic['modulok'] === '*' ? '*' : $lic['modulok']) : [],
            'ismetles'        => !empty($v['ismetles']),
        ];

        $wpdb->update(SDH_Sales_Schema::t('rendeles'), ['licenc_kulcs' => $kulcs, 'kozpont_licenc_id' => $e['licenc_id']], ['id' => (int) $r['id']]);
        self::licenc_tarol($kulcs, $e, $r);

        $mi = ['uj' => 'Új licenc', 'megujit' => 'Megújítva', 'bovit' => 'Bővítve'][$m];

        return ['allapot' => 'ok', 'uzenet' => $mi . ': ' . $kulcs . ($e['lejar'] !== '' ? ', lejár ' . $e['lejar'] : '') . ($e['ismetles'] ? ' (a Központ korábbi válasza)' : '') . '.', 'eredmeny' => $e];
    }

    /* =================================================================
     * 2. Végszámla
     * ============================================================== */

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function lepes_szamla(array $r, bool $kezi): array
    {
        if (!class_exists('SDH_Sales_Szamlazz') || !method_exists('SDH_Sales_Szamlazz', 'vegszamla')) {
            return ['allapot' => 'kihagyva', 'uzenet' => 'A számlázó modul nincs telepítve.'];
        }

        if ((int) $r['brutto'] <= 0) {
            return ['allapot' => 'kihagyva', 'uzenet' => '0 Ft-os rendelés: nincs számla.'];
        }

        if ((string) $r['szamla_vegszamla'] !== '') {
            return ['allapot' => 'ok', 'uzenet' => 'Végszámla: ' . (string) $r['szamla_vegszamla'] . '.', 'eredmeny' => ['szamlaszam' => (string) $r['szamla_vegszamla']]];
        }

        if (method_exists('SDH_Sales_Szamlazz', 'bekotve') && !SDH_Sales_Szamlazz::bekotve()) {
            return ['allapot' => 'kihagyva', 'uzenet' => 'A Számlázz.hu nincs bekötve (Beállítások › Számlázás) – a számlát kézzel kell kiállítani.'];
        }

        $auto = method_exists('SDH_Sales_Szamlazz', 'automatikus')
            ? SDH_Sales_Szamlazz::automatikus()
            : in_array(SDH_Sales::b('szamlazz.automatikus', true), [true, 1, '1', 'true', 'igen'], true);

        if (!$auto && !$kezi) {
            return ['allapot' => 'kezi', 'uzenet' => 'Az automatikus számlázás ki van kapcsolva: a végszámla az adminisztrátor indítására vár.'];
        }

        $v = (array) SDH_Sales_Szamlazz::vegszamla((int) $r['id'], $kezi);

        if (empty($v['ok']) || (string) ($v['szamlaszam'] ?? '') === '') {
            return ['allapot' => 'ujra', 'uzenet' => (string) ($v['uzenet'] ?? 'A végszámla nem készült el.')];
        }

        return ['allapot' => 'ok', 'uzenet' => 'Végszámla: ' . (string) $v['szamlaszam'] . '.', 'eredmeny' => ['szamlaszam' => (string) $v['szamlaszam']]];
    }

    /* =================================================================
     * 3. Üdvözlő levél
     * ============================================================== */

    /** „2027. október 19” (pont nélkül, hogy a „-ig” rag mögé illeszkedjen). */
    public static function datum(string $ymd): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) !== 1) {
            return '';
        }

        return $m[1] . '. ' . self::HONAPOK[(int) $m[2]] . ' ' . (int) $m[3];
    }

    /** @param array<string, mixed> $r */
    private static function cimzett(array $r): string
    {
        $e = (string) self::vevo_adat($r)['email'];

        return is_email($e) ? $e : '';
    }

    /** @param string|array<int, string> $m */
    private static function modul_nevek($m): string
    {
        if ($m === '*') {
            return __('minden modul', 'sdh-sales');
        }

        $nev = [];

        foreach ((array) $m as $k) {
            $t     = class_exists('SDH_Sales_Tartalom') ? SDH_Sales_Tartalom::egy('modul', (string) $k) : null;
            $nev[] = $t !== null && (string) $t['cim'] !== '' ? (string) $t['cim'] : (string) $k;
        }

        return implode(', ', $nev);
    }

    private static function telepites_url(): string
    {
        $u = trim((string) SDH_Sales::b('kiszolgalas.telepites_url', ''));

        if ($u !== '') {
            return esc_url_raw($u);
        }

        $p = get_page_by_path('telepites');

        return $p instanceof WP_Post ? (string) get_permalink($p) : '';
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $lanc
     * @return array<string, mixed>
     */
    private static function lepes_email(array $r, array $lanc): array
    {
        $cim = self::cimzett($r);

        if ($cim === '') {
            return ['allapot' => 'hiba', 'uzenet' => 'A rendelésben nincs érvényes e-mail-cím.'];
        }

        $k  = (array) ($lanc['lepesek']['kozpont']['eredmeny'] ?? []);
        $v  = self::vevo_adat($r);
        $id = (int) $r['id'];
        $sablon = ['uj' => 'udvozlo', 'megujitas' => 'megujitas_kesz', 'bovites' => 'bovites_kesz'][(string) $r['tipus']] ?? 'fizetes_megerkezett';

        $adat = [
            '_rendeles_id'  => $id,
            'nev'           => $v['kapcsolattarto'] !== '' ? $v['kapcsolattarto'] : $v['nev'],
            'vevo_ceg'      => $v['nev'],
            'rendeles'      => (string) $r['azon'],
            'osszeg'        => SDH_Sales::ft((int) $r['brutto']),
            'licenc_kulcs'  => (string) ($k['licenc_kulcs'] ?? $r['licenc_kulcs']),
            'letoltes_url'  => (string) ($k['letoltes_url'] ?? ''),
            'lejar'         => self::datum((string) ($k['lejar'] ?? '')),
            'csomag'        => (string) ($k['csomag'] ?? ''),
            'modulok'       => isset($k['modulok']) ? self::modul_nevek($k['modulok']) : '',
            'uj_modulok'    => !empty($k['uj_modulok']) ? self::modul_nevek($k['uj_modulok']) : '',
            'kozpont_url'   => self::muvelet($r) !== '' ? SDH_Sales_Kozpont_Kapcsolat::kozpont_cim() : '',
            'fiok_url'      => home_url('/fiok/'),
            'telepites_url' => (string) $r['tipus'] === 'uj' ? self::telepites_url() : '',
            'idopont_url'   => (string) $r['tipus'] === 'uj' && class_exists('SDH_Sales_Naptar') && method_exists('SDH_Sales_Naptar', 'ajanlat_link') ? SDH_Sales_Naptar::ajanlat_link($id, 'telepites') : '',
            'szamla_url'    => '',
        ];

        $csatol = [];

        if ((string) $r['szamla_vegszamla'] !== '' && class_exists('SDH_Sales_Szamlazz')) {
            if (method_exists('SDH_Sales_Szamlazz', 'pdf_link')) {
                $adat['szamla_url'] = SDH_Sales_Szamlazz::pdf_link($id, 'vegszamla');
            }

            if (method_exists('SDH_Sales_Szamlazz', 'vegszamla_pdf')) {
                $pdf = (string) SDH_Sales_Szamlazz::vegszamla_pdf($id);

                if ($pdf !== '' && is_readable($pdf)) {
                    $csatol[] = $pdf;
                }
            }
        }

        if (!SDH_Sales_Email::kuld($sablon, $cim, $adat, $csatol)) {
            return ['allapot' => 'ujra', 'uzenet' => 'A levél nem ment ki (' . $sablon . ').'];
        }

        return ['allapot' => 'ok', 'uzenet' => 'Elküldve: ' . $sablon . ' → ' . SDH_Sales_Email::maszk($cim) . ($csatol !== [] ? ' (számla csatolva)' : '') . '.', 'eredmeny' => ['sablon' => $sablon, 'szamla' => $csatol !== []]];
    }

    /* =================================================================
     * 4. Időpont-ajánlat
     * ============================================================== */

    /** @param array<string, mixed> $r @return array<int, string> a rendelt, időpontot kívánó szolgáltatások típusai */
    private static function idopont_tipusok(array $r): array
    {
        $ki = [];

        foreach (self::tetelek($r) as $t) {
            $k = is_array($t) ? sanitize_key((string) ($t['kulcs'] ?? '')) : '';

            foreach (['telepites', 'oktatas', 'support'] as $tip) {
                if ($k === $tip || strpos($k, $tip . '_') === 0) {
                    $ki[] = $tip;
                }
            }
        }

        return array_values(array_unique($ki));
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function lepes_idopont(array $r): array
    {
        if (!class_exists('SDH_Sales_Naptar') || !method_exists('SDH_Sales_Naptar', 'ajanlat_link')) {
            return ['allapot' => 'kihagyva', 'uzenet' => 'Az időpontfoglaló modul nincs telepítve.'];
        }

        $tip = self::idopont_tipusok($r);

        if ($tip === []) {
            return ['allapot' => 'kihagyva', 'uzenet' => 'Nem rendelt telepítést / oktatást – a foglaló link az üdvözlő levélben van.'];
        }

        $cim = self::cimzett($r);

        if ($cim === '') {
            return ['allapot' => 'hiba', 'uzenet' => 'Nincs érvényes e-mail-cím.'];
        }

        $v      = self::vevo_adat($r);
        $linkek = [];
        $hiba   = [];

        foreach ($tip as $t) {
            $link = SDH_Sales_Naptar::ajanlat_link((int) $r['id'], $t);

            if ($link === '') {
                $hiba[] = $t;
                continue;
            }

            $nev = $t;

            if (method_exists('SDH_Sales_Naptar', 'tipusok')) {
                $nev = (string) (SDH_Sales_Naptar::tipusok()[$t]['nev'] ?? $t);
            }

            $ok = SDH_Sales_Email::kuld('idopont_ajanlat', $cim, [
                '_rendeles_id' => (int) $r['id'],
                'nev'          => $v['kapcsolattarto'] !== '' ? $v['kapcsolattarto'] : $v['nev'],
                'rendeles'     => (string) $r['azon'],
                'tipus'        => $nev,
                'foglalas_url' => $link,
            ]);

            if ($ok) {
                $linkek[$t] = $link;
            } else {
                $hiba[] = $t;
            }
        }

        if ($hiba !== []) {
            return ['allapot' => 'ujra', 'uzenet' => 'Az időpont-ajánlat nem ment ki: ' . implode(', ', $hiba) . '.'];
        }

        return ['allapot' => 'ok', 'uzenet' => 'Időpont-ajánlat elküldve: ' . implode(', ', array_keys($linkek)) . '.', 'eredmeny' => ['tipusok' => array_keys($linkek)]];
    }

    /* =================================================================
     * 5. Értesítés (Norbinak)
     * ============================================================== */

    private static function admin_cim(): string
    {
        $c = sanitize_email((string) SDH_Sales::b('email.ertesites', ''));

        return is_email($c) ? $c : '';
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $lanc
     * @return array<string, mixed>
     */
    private static function lepes_ertesites(array $r, array $lanc): array
    {
        $cim = self::admin_cim();

        if ($cim === '') {
            return ['allapot' => 'kihagyva', 'uzenet' => 'Nincs megadva értesítési cím (email.ertesites).'];
        }

        $v = self::vevo_adat($r);
        $k = (array) ($lanc['lepesek']['kozpont']['eredmeny'] ?? []);
        $l = [];

        foreach (self::LEPESEK as $s) {
            if ($s !== 'ertesites') {
                $l[] = self::LEPES_NEV[$s] . ': ' . ['ok' => 'kész', 'kihagyva' => 'kihagyva', 'hiba' => 'HIBA', 'var' => 'vár'][(string) ($lanc['lepesek'][$s]['allapot'] ?? 'var')];
            }
        }

        $ok = SDH_Sales_Email::kuld('admin_ertesites', $cim, [
            '_rendeles_id' => (int) $r['id'],
            'rendeles'     => (string) $r['azon'],
            'tipus'        => ['uj' => 'új vevő', 'megujitas' => 'megújítás', 'bovites' => 'bővítés', 'szolgaltatas' => 'szolgáltatás'][(string) $r['tipus']] ?? (string) $r['tipus'],
            'vevo_nev'     => $v['nev'],
            'vevo_email'   => $v['email'],
            'vevo_telefon' => $v['telefon'],
            'osszeg'       => SDH_Sales::ft((int) $r['brutto']),
            'licenc_kulcs' => (string) ($k['licenc_kulcs'] ?? $r['licenc_kulcs']),
            'lejar'        => self::datum((string) ($k['lejar'] ?? '')),
            'szamlaszam'   => (string) $r['szamla_vegszamla'],
            'lanc'         => implode(' · ', $l),
            'admin_url'    => admin_url('admin.php?page=sdh-sales#/rendeles/' . (int) $r['id']),
        ]);

        return $ok ? ['allapot' => 'ok', 'uzenet' => 'Értesítés elküldve.'] : ['allapot' => 'ujra', 'uzenet' => 'Az értesítő levél nem ment ki.'];
    }

    /**
     * Végleges hiba: levél a forgalmazónak (egyszer lépésenként).
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed> $l
     */
    private static function hiba_ertesit(array $r, string $s, array $l): void
    {
        $cim = self::admin_cim() ?: sanitize_email((string) get_option('admin_email'));
        $v   = self::vevo_adat($r);

        if (!is_email($cim)) {
            return;
        }

        SDH_Sales_Email::kuld('lanc_hiba_admin', $cim, [
            '_rendeles_id' => (int) $r['id'],
            'rendeles'     => (string) $r['azon'],
            'lepes'        => self::LEPES_NEV[$s] ?? $s,
            'uzenet'       => (string) $l['uzenet'],
            'probalkozas'  => (string) (int) $l['probalkozas'],
            'vevo_nev'     => $v['nev'],
            'vevo_email'   => $v['email'],
            'admin_url'    => admin_url('admin.php?page=sdh-sales#/rendeles/' . (int) $r['id']),
        ]);
    }

    /* =================================================================
     * Admin: újraindítás, állapot
     * ============================================================== */

    /** @return array{ok: bool, uzenet: string, lanc?: array<string, mixed>} */
    public static function lepes_ujra(int $rendeles_id, string $lepes): array
    {
        if (!in_array($lepes, self::LEPESEK, true)) {
            return ['ok' => false, 'uzenet' => 'Ismeretlen lépés.'];
        }

        $r = self::rendeles($rendeles_id);

        if ($r === null) {
            return ['ok' => false, 'uzenet' => 'Nincs ilyen rendelés.'];
        }

        $l = (array) ($r['lanc']['lepesek'][$lepes] ?? []);

        if (in_array((string) ($l['allapot'] ?? ''), ['ok', 'kihagyva'], true) && !($lepes === 'szamla' && (string) $l['allapot'] === 'kihagyva')) {
            return ['ok' => false, 'uzenet' => 'Ez a lépés már lezárult (' . (string) $l['allapot'] . ') – nem fut újra.'];
        }

        if ($l !== []) {
            $r['lanc']['lepesek'][$lepes] = ['allapot' => 'var', 'probalkozas' => 0, 'kovetkezo' => 0] + $l;
            unset($r['lanc']['lepesek'][$lepes]['kezi']);
            $r['lanc']['kesz'] = false;
            self::lanc_ment($rendeles_id, $r['lanc']);
        }

        SDH_Sales_Naplo::ir('kiszolgalas', 'Lépés kézi újraindítása: ' . (self::LEPES_NEV[$lepes] ?? $lepes) . ' (' . (string) $r['azon'] . ').', 'info', 'rendeles', $rendeles_id);

        $v = self::indit($rendeles_id, null, $lepes);
        $a = self::allapot($rendeles_id);
        $u = (array) ($a['lepesek'][$lepes] ?? []);

        return ['ok' => in_array((string) ($u['allapot'] ?? ''), ['ok', 'kihagyva'], true), 'uzenet' => (string) ($u['uzenet'] ?? $v['uzenet'])] + (isset($v['lanc']) ? ['lanc' => $a] : []);
    }

    /** @return array<string, mixed>|null */
    public static function allapot(int $rendeles_id): ?array
    {
        $r = self::rendeles($rendeles_id);

        if ($r === null) {
            return null;
        }

        $lep = [];

        foreach (self::LEPESEK as $s) {
            $l = (array) ($r['lanc']['lepesek'][$s] ?? ['allapot' => 'var', 'probalkozas' => 0, 'uzenet' => '', 'ido' => '', 'kovetkezo' => 0]);

            if (isset($l['eredmeny']['letoltes_url'])) {
                $l['eredmeny']['letoltes_url'] = $l['eredmeny']['letoltes_url'] !== '' ? '(elküldve)' : '';
            }

            $l['nev'] = self::LEPES_NEV[$s];
            $lep[$s]  = $l;
        }

        return [
            'rendeles'     => (string) $r['azon'],
            'allapot'      => (string) $r['allapot'],
            'tipus'        => (string) $r['tipus'],
            'licenc_kulcs' => (string) $r['licenc_kulcs'],
            'indult'       => (string) ($r['lanc']['indult'] ?? ''),
            'kesz'         => !empty($r['lanc']['kesz']),
            'lepesek'      => $lep,
        ];
    }

    /** @return WP_REST_Response|WP_Error */
    public static function rest_allapot(WP_REST_Request $q)
    {
        $a = self::allapot((int) $q['id']);

        return $a === null ? SDH_Sales_Rest::hiba('Nincs ilyen rendelés.', 404) : rest_ensure_response($a);
    }

    /** @return WP_REST_Response|WP_Error */
    public static function rest_ujra(WP_REST_Request $q)
    {
        $p = (array) $q->get_json_params();
        $v = self::lepes_ujra((int) $q['id'], sanitize_key((string) ($p['lepes'] ?? $q->get_param('lepes') ?? '')));

        if (!$v['ok'] && strpos($v['uzenet'], 'Nincs ilyen') === 0) {
            return SDH_Sales_Rest::hiba($v['uzenet'], 404);
        }

        return rest_ensure_response(['ok' => $v['ok'], 'uzenet' => $v['uzenet'], 'allapot' => self::allapot((int) $q['id'])]);
    }

    /* =================================================================
     * Cron: újrapróba + lejárat-emlékeztetők
     * ============================================================== */

    /** @param mixed $most unix idő (teszthez); bármi más (pl. egy másik feliratkozó argumentuma) = most */
    public static function cron($most = null): void
    {
        global $wpdb;

        $most = is_int($most) && $most > 0 ? $most : time();
        $ids  = (array) $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . SDH_Sales_Schema::t('rendeles') . " WHERE allapot IN ('fizetve', 'kiszolgalva') AND (lanc IS NULL OR lanc = '' OR lanc NOT LIKE %s) AND (fizetve_ido IS NULL OR fizetve_ido > %s) ORDER BY id ASC LIMIT 50",
            '%"kesz":true%',
            gmdate('Y-m-d H:i:s', $most - 45 * DAY_IN_SECONDS)
        ));

        foreach ($ids as $id) {
            $r = self::rendeles((int) $id);

            if ($r === null || !self::esedekes($r, $most)) {
                continue;
            }

            self::indit((int) $id, $most);
        }

        if (get_transient('sdh_sales_lejarat_nap') === false) {
            set_transient('sdh_sales_lejarat_nap', 1, 6 * HOUR_IN_SECONDS);
            self::emlekeztetok($most);
        }
    }

    /** Van-e most futtatható lépés. @param array<string, mixed> $r */
    private static function esedekes(array $r, int $most): bool
    {
        if (empty($r['lanc']['lepesek'])) {
            return true;
        }

        foreach (self::LEPESEK as $s) {
            $l = (array) ($r['lanc']['lepesek'][$s] ?? []);

            if (($l['allapot'] ?? 'var') === 'var' && empty($l['kezi']) && (int) ($l['kovetkezo'] ?? 0) <= $most) {
                return true;
            }
        }

        return false;
    }

    /* =================================================================
     * Licencek és lejárat
     * ============================================================== */

    /** @return array<string, array<string, mixed>> */
    public static function licencek(): array
    {
        $m = get_option(self::OPT_LICENC, []);

        return is_array($m) ? $m : [];
    }

    /**
     * @param array<string, mixed> $e a Központ ellenőrzött eredménye
     * @param array<string, mixed> $r
     */
    private static function licenc_tarol(string $kulcs, array $e, array $r): void
    {
        $m    = self::licencek();
        $regi = (array) ($m[$kulcs] ?? []);
        $v    = self::vevo_adat($r);

        $m[$kulcs] = [
            'lejar'       => (string) $e['lejar'],
            'ciklus'      => (string) $e['ciklus'],
            'csomag'      => (string) $e['csomag'],
            'email'       => $v['email'] !== '' ? $v['email'] : (string) ($regi['email'] ?? ''),
            'nev'         => $v['kapcsolattarto'] !== '' ? $v['kapcsolattarto'] : $v['nev'],
            'rendeles_id' => (int) $r['id'],
            'user_id'     => (int) ($r['user_id'] ?? 0),
            'kuldve'      => (string) ($regi['lejar'] ?? '') === (string) $e['lejar'] ? (array) ($regi['kuldve'] ?? []) : [],
        ];

        update_option(self::OPT_LICENC, $m, false);
    }

    public static function megujitas_url(string $kulcs): string
    {
        return add_query_arg('megujit', rawurlencode($kulcs), home_url('/fiok/'));
    }

    /** A lejárat előtti levelek. Visszaad: hány ment ki. */
    public static function emlekeztetok(?int $most = null): int
    {
        $most = $most ?? time();
        $ma   = wp_date('Y-m-d', $most);
        $m    = self::licencek();
        $db   = 0;
        $valt = false;

        foreach ($m as $kulcs => $l) {
            $lejar = (string) ($l['lejar'] ?? '');

            // Helyi Központnál a friss lejárat onnan (ha kézzel hosszabbították, ne küldjünk tévesen).
            if (SDH_Sales_Kozpont_Kapcsolat::mod() === 'helyi' && class_exists('SDH_Kozpont') && method_exists('SDH_Kozpont', 'licenc')) {
                $kl = SDH_Kozpont::licenc((string) $kulcs);

                if ($kl === null || (string) $kl->allapot === 'tiltva') {
                    continue;
                }

                if ((string) ($kl->lejar ?? '') !== $lejar) {
                    $lejar             = (string) ($kl->lejar ?? '');
                    $m[$kulcs]['lejar'] = $lejar;
                    $m[$kulcs]['kuldve'] = [];
                    $valt              = true;
                }
            }

            if ($lejar === '' || (string) ($l['ciklus'] ?? '') === 'egyszeri' || !is_email((string) ($l['email'] ?? ''))) {
                continue;
            }

            $nap = (int) round((strtotime($lejar . ' 00:00:00 UTC') - strtotime($ma . ' 00:00:00 UTC')) / DAY_IN_SECONDS);

            if ($nap < 0) {
                continue;
            }

            $kuszob = (string) ($l['ciklus'] ?? '') === 'havi' ? [7, 1] : self::EMLEKEZTETO;
            $cel    = null;

            foreach ($kuszob as $k) {
                if ($nap <= $k) {
                    $cel = $k;
                }
            }

            $jel = $lejar . ':' . (string) $cel;

            if ($cel === null || in_array($jel, (array) ($m[$kulcs]['kuldve'] ?? []), true)) {
                continue;
            }

            $ok = SDH_Sales_Email::kuld('lejarat_emlekezteto', (string) $l['email'], [
                '_rendeles_id'  => (int) ($l['rendeles_id'] ?? 0),
                'nev'           => (string) ($l['nev'] ?? ''),
                'licenc_kulcs'  => (string) $kulcs,
                'csomag'        => (string) ($l['csomag'] ?? ''),
                'lejar'         => self::datum($lejar),
                'nap'           => (string) max(1, $nap),
                'megujitas_url' => self::megujitas_url((string) $kulcs),
            ]);

            if ($ok) {
                $m[$kulcs]['kuldve'][] = $jel;
                $valt                  = true;
                $db++;
            }
        }

        if ($valt) {
            update_option(self::OPT_LICENC, $m, false);
        }

        return $db;
    }
}
