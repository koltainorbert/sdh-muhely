<?php
/**
 * Riportok (F5, 0.87.0): átfutási idő, technikusonkénti teljesítmény, javítási arány, bevétel kategóriánként.
 *
 * Egy menüpont (adminisztrátornak), időszak-választóval (ez a hónap, előző hónap, utolsó 30 nap, idén, egyéni).
 * Minden szám a lezárt lapokból jön (a lezárás napja számít): a lezárt és nem érvénytelen javítás „kész”.
 * - Átfutás: a felvétel (készült) és a lezárás között eltelt napok – átlag és medián.
 * - Javítási arány: a lezárt javítások közül hány NEM jött vissza garanciában (F1), és hány lap lett érvénytelen.
 * - Technikusonként (felelős): lezárt lapok, átlagos átfutás, bevétel, garanciális visszatérések.
 * - Bevétel: fajtánként (javítás, árukiadás, rendelés), az eszköz kategóriája szerint, tételtípus és termékkategória szerint.
 * - Árajánlatok (F2): elküldött, elfogadott, elutasított.
 * - Az utolsó 12 hónap: lezárt javítások és bevétel havonta.
 *
 * A számolás PHP-ben fut (a dátumkülönbség így MySQL-en és SQLite-on is ugyanaz).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Riport
{
    public const KULCS = 'riportok';

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'      => self::KULCS,
            'cim'        => 'Riportok',
            'render'     => [self::class, 'oldal'],
            'sorrend'    => 92,
            'csak_admin' => true,
        ]);
    }

    private static function t(string $nev): string
    {
        return SDH_Muhely_Schema::tabla($nev);
    }

    /* =================================================================
     * Időszak
     * ============================================================== */

    /** @return array<string, string> */
    public static function idoszakok(): array
    {
        return [
            'ho'       => 'Ez a hónap',
            'elozo_ho' => 'Előző hónap',
            '30nap'    => 'Utolsó 30 nap',
            'ev'       => 'Idén',
            'egyeni'   => 'Egyéni',
        ];
    }

    /**
     * Az időszak határai (Y-m-d, mindkettő benne).
     *
     * @return array{0: string, 1: string, 2: string}  tól, ig, kulcs
     */
    public static function idoszak(string $kulcs, string $tol = '', string $ig = '', ?string $ma = null): array
    {
        $ma  = $ma ?? current_time('Y-m-d');
        $nap = DateTimeImmutable::createFromFormat('Y-m-d', $ma, wp_timezone()) ?: new DateTimeImmutable('now', wp_timezone());

        switch ($kulcs) {
            case 'elozo_ho':
                $e = $nap->modify('first day of last month');

                return [$e->format('Y-m-01'), $e->format('Y-m-t'), $kulcs];
            case '30nap':
                return [$nap->modify('-29 days')->format('Y-m-d'), $ma, $kulcs];
            case 'ev':
                return [$nap->format('Y-01-01'), $ma, $kulcs];
            case 'egyeni':
                $ok = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

                if ($ok($tol) && $ok($ig)) {
                    return $tol <= $ig ? [$tol, $ig, $kulcs] : [$ig, $tol, $kulcs];
                }

                return [$nap->format('Y-m-01'), $ma, 'ho'];
            default:
                return [$nap->format('Y-m-01'), $ma, 'ho'];
        }
    }

    /* =================================================================
     * Számolás
     * ============================================================== */

    /** A lezárt (kész) állapotok – az érvénytelen nélkül. @return array<int, string> */
    private static function kesz_kulcsok(): array
    {
        $lista = SDH_Muhely_Munkalap::allapotok();

        return array_values(array_filter(SDH_Muhely_Munkalap::zart_kulcsok($lista), static fn (string $k): bool => empty($lista[$k]['ervenytelen']) && $k !== 'ervenytelen'));
    }

    private static function napok(string $tol, string $ig): ?int
    {
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', substr($tol, 0, 10));
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', substr($ig, 0, 10));

        return $a && $b ? max(0, (int) $a->diff($b)->days * ($b >= $a ? 1 : -1)) : null;
    }

    private static function median(array $szamok): float
    {
        if ($szamok === []) {
            return 0.0;
        }

        sort($szamok);
        $n = count($szamok);

        return $n % 2 ? (float) $szamok[intdiv($n, 2)] : ($szamok[$n / 2 - 1] + $szamok[$n / 2]) / 2;
    }

    /**
     * A riport minden száma az időszakra.
     *
     * @return array<string, mixed>
     */
    public static function adatok(string $tol, string $ig): array
    {
        global $wpdb;

        $kesz = self::kesz_kulcsok();
        $kesz = $kesz === [] ? ['lezart'] : $kesz;
        $ml   = self::t('munkalap');
        $hely = implode(',', array_fill(0, count($kesz), '%s'));

        // A lezárt lapok az időszakban (minden fajta).
        $lapok = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT m.id, m.fajta, m.keszult, m.lezarva, m.felelos, m.brutto_ertek, m.garancia_elozo, e.kategoria AS eszkoz_kat
               FROM {$ml} m LEFT JOIN " . self::t('eszkoz') . " e ON e.id = m.eszkoz_id
              WHERE m.allapot IN ({$hely}) AND m.lezarva >= %s AND m.lezarva <= %s",
            array_merge($kesz, [$tol, $ig])
        )); // phpcs:ignore WordPress.DB.PreparedSQL

        $javitas  = array_values(array_filter($lapok, static fn (object $l): bool => (string) $l->fajta === 'javitas'));
        $atfutasok = [];
        $fajtak   = [];
        $techn    = [];
        $kat      = [];

        foreach ($lapok as $l) {
            $f = (string) $l->fajta;
            $fajtak[$f] = $fajtak[$f] ?? ['db' => 0, 'bevetel' => 0.0];
            $fajtak[$f]['db']++;
            $fajtak[$f]['bevetel'] += (float) $l->brutto_ertek;
        }

        $jav_idk = array_map(static fn (object $l): int => (int) $l->id, $javitas);

        // Garanciális visszatérések: az időszakban lezárt javításokra mutató későbbi lapok (bármikor).
        $vissza_lapra = [];

        if ($jav_idk !== []) {
            foreach (array_chunk($jav_idk, 500) as $resz) {
                $h = implode(',', array_fill(0, count($resz), '%d'));

                foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT garancia_elozo, COUNT(*) AS db FROM {$ml} WHERE garancia_elozo IN ({$h}) GROUP BY garancia_elozo", $resz)) as $v) { // phpcs:ignore WordPress.DB.PreparedSQL
                    $vissza_lapra[(int) $v->garancia_elozo] = (int) $v->db;
                }
            }
        }

        foreach ($javitas as $l) {
            $nap = self::napok((string) $l->keszult, (string) $l->lezarva);

            if ($nap !== null) {
                $atfutasok[] = $nap;
            }

            $fid = (int) $l->felelos;
            $techn[$fid] = $techn[$fid] ?? ['db' => 0, 'napok' => [], 'bevetel' => 0.0, 'vissza' => 0];
            $techn[$fid]['db']++;
            $techn[$fid]['bevetel'] += (float) $l->brutto_ertek;
            $techn[$fid]['vissza']  += isset($vissza_lapra[(int) $l->id]) ? 1 : 0;

            if ($nap !== null) {
                $techn[$fid]['napok'][] = $nap;
            }

            $k = (string) $l->eszkoz_kat !== '' ? (string) $l->eszkoz_kat : 'egyeb';
            $kat[$k] = $kat[$k] ?? ['db' => 0, 'bevetel' => 0.0];
            $kat[$k]['db']++;
            $kat[$k]['bevetel'] += (float) $l->brutto_ertek;
        }

        foreach ($techn as $fid => $t) {
            $techn[$fid]['nev']     = $fid > 0 ? SDH_Muhely_Munkalap::felelos_nev($fid) : '(nincs felelős)';
            $techn[$fid]['atlag']   = $t['napok'] !== [] ? array_sum($t['napok']) / count($t['napok']) : 0.0;
            $techn[$fid]['vissza_arany'] = $t['db'] > 0 ? $t['vissza'] / $t['db'] : 0.0;
            unset($techn[$fid]['napok']);
        }

        uasort($techn, static fn (array $a, array $b): int => $b['db'] <=> $a['db']);
        uasort($kat, static fn (array $a, array $b): int => $b['bevetel'] <=> $a['bevetel']);

        // Tételek: szolgáltatás / termék, és termékkategória szerint (a lezárt lapokról).
        $tetel = ['szolgaltatas' => 0.0, 'termek' => 0.0];
        $tkat  = [];
        $idk   = array_map(static fn (object $l): int => (int) $l->id, $lapok);

        foreach (array_chunk($idk, 500) as $resz) {
            $h = implode(',', array_fill(0, count($resz), '%d'));
            $sorok = (array) $wpdb->get_results($wpdb->prepare(
                'SELECT t.tipus, t.brutto_ertek, p.kategoria FROM ' . SDH_Muhely_Tetel::tabla() . ' t LEFT JOIN ' . self::t('termek') . " p ON p.id = t.termek_id
                  WHERE t.munkalap_id IN ({$h}) AND t.mozgas = 'kimeno'",
                $resz
            )); // phpcs:ignore WordPress.DB.PreparedSQL

            foreach ($sorok as $s) {
                $tip = (string) $s->tipus === 'termek' ? 'termek' : 'szolgaltatas';
                $tetel[$tip] += (float) $s->brutto_ertek;

                if ($tip === 'termek') {
                    $k = trim((string) $s->kategoria) !== '' ? trim((string) $s->kategoria) : '(kategória nélkül)';
                    $tkat[$k] = ($tkat[$k] ?? 0.0) + (float) $s->brutto_ertek;
                }
            }
        }

        arsort($tkat);

        // Érvénytelen lapok (a javítások közül) és a garanciális visszatérések az időszakban (felvétel szerint).
        $ervenytelen = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ml} WHERE fajta = 'javitas' AND allapot = 'ervenytelen' AND lezarva >= %s AND lezarva <= %s",
            $tol,
            $ig
        ));
        $vissza_idoszak = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ml} WHERE fajta = 'javitas' AND garancia_elozo > 0 AND keszult >= %s AND keszult <= %s",
            $tol,
            $ig
        ));

        $visszajott = count(array_filter($javitas, static fn (object $l): bool => isset($vissza_lapra[(int) $l->id])));
        $jav_db     = count($javitas);

        // Árajánlatok (F2) az időszakban.
        $ajanlat = (array) $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS kuldve,
                    SUM(CASE WHEN ajanlat_allapot = 'elfogadva' THEN 1 ELSE 0 END) AS elfogadva,
                    SUM(CASE WHEN ajanlat_allapot = 'elutasitva' THEN 1 ELSE 0 END) AS elutasitva,
                    SUM(CASE WHEN ajanlat_allapot = 'var' THEN 1 ELSE 0 END) AS var
               FROM {$ml} WHERE ajanlat_kuldve >= %s AND ajanlat_kuldve <= %s",
            $tol . ' 00:00:00',
            $ig . ' 23:59:59'
        ), ARRAY_A);

        return [
            'tol'           => $tol,
            'ig'            => $ig,
            'javitas_db'    => $jav_db,
            'atfutas_atlag' => $atfutasok !== [] ? array_sum($atfutasok) / count($atfutasok) : 0.0,
            'atfutas_median' => self::median($atfutasok),
            'atfutas_db'    => count($atfutasok),
            'sikeres_arany' => $jav_db > 0 ? ($jav_db - $visszajott) / $jav_db : 0.0,
            'visszajott'    => $visszajott,
            'vissza_idoszak' => $vissza_idoszak,
            'ervenytelen'   => $ervenytelen,
            'bevetel'       => array_sum(array_column($fajtak, 'bevetel')),
            'fajtak'        => $fajtak,
            'technikusok'   => $techn,
            'kategoriak'    => $kat,
            'tetel'         => $tetel,
            'termek_kat'    => $tkat,
            'ajanlat'       => array_map('intval', $ajanlat + ['kuldve' => 0, 'elfogadva' => 0, 'elutasitva' => 0, 'var' => 0]),
            // F7 (0.89.0): az időszakban beérkezett ügyfél-értékelések.
            'elegedettseg'  => class_exists('SDH_Muhely_Elegedettseg') ? SDH_Muhely_Elegedettseg::osszesit($tol, $ig) : ['db' => 0, 'atlag' => 0.0, 'jo' => 0],
        ];
    }

    /**
     * Az utolsó 12 hónap: lezárt javítások és bevétel (minden fajta) havonta.
     *
     * @return array<string, array{db: int, bevetel: float}> 'Y-m' => …
     */
    public static function honapok(?string $ma = null): array
    {
        global $wpdb;

        $ma   = $ma ?? current_time('Y-m-d');
        $veg  = DateTimeImmutable::createFromFormat('!Y-m-d', $ma) ?: new DateTimeImmutable('today');
        $kezd = $veg->modify('first day of this month')->modify('-11 months');
        $ki   = [];

        for ($i = 0; $i < 12; $i++) {
            $ki[$kezd->modify('+' . $i . ' months')->format('Y-m')] = ['db' => 0, 'bevetel' => 0.0];
        }

        $kesz = self::kesz_kulcsok();
        $kesz = $kesz === [] ? ['lezart'] : $kesz;
        $hely = implode(',', array_fill(0, count($kesz), '%s'));

        $sorok = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT fajta, lezarva, brutto_ertek FROM ' . self::t('munkalap') . " WHERE allapot IN ({$hely}) AND lezarva >= %s AND lezarva <= %s",
            array_merge($kesz, [$kezd->format('Y-m-d'), $ma])
        )); // phpcs:ignore WordPress.DB.PreparedSQL

        foreach ($sorok as $s) {
            $h = substr((string) $s->lezarva, 0, 7);

            if (!isset($ki[$h])) {
                continue;
            }

            $ki[$h]['bevetel'] += (float) $s->brutto_ertek;

            if ((string) $s->fajta === 'javitas') {
                $ki[$h]['db']++;
            }
        }

        return $ki;
    }

    /* =================================================================
     * Oldal
     * ============================================================== */

    private static function ft(float $o): string
    {
        return number_format($o, 0, ',', ' ') . ' Ft';
    }

    private static function szazalek(float $a): string
    {
        return number_format($a * 100, 1, ',', '') . '%';
    }

    private static function nap(float $n): string
    {
        return number_format($n, 1, ',', '') . ' nap';
    }

    private static function fajta_nev(string $f): string
    {
        return ['javitas' => 'Javítás', 'arukiadas' => 'Árukiadás', 'rendeles' => 'Rendelés'][$f] ?? $f;
    }

    /** Vízszintes sáv (a legnagyobbhoz mérve). */
    private static function sav(float $ertek, float $max): string
    {
        $w = $max > 0 ? max(0.0, min(100.0, $ertek / $max * 100)) : 0.0;

        return '<span class="sdh-riport__sav" aria-hidden="true"><span style="width:' . esc_attr(number_format($w, 1, '.', '')) . '%"></span></span>';
    }

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::admin_jog_ellenoriz();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $kulcs = isset($_GET['idoszak']) ? sanitize_key(wp_unslash($_GET['idoszak'])) : 'ho';
        $tol   = isset($_GET['tol']) ? sanitize_text_field(wp_unslash($_GET['tol'])) : '';
        $ig    = isset($_GET['ig']) ? sanitize_text_field(wp_unslash($_GET['ig'])) : '';
        // phpcs:enable

        [$tol, $ig, $kulcs] = self::idoszak($kulcs, $tol, $ig);
        $d       = self::adatok($tol, $ig);
        $honapok = self::honapok();
        $kat_nev = static fn (string $k): string => class_exists('SDH_Muhely_Eszkoz') && $k !== 'egyeb' ? SDH_Muhely_Eszkoz::kategoria_cimke($k) : 'Egyéb / nincs megadva';
        ?>
        <div class="sdh-wrap sdh-riport" data-sdh-riport>
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec('Riportok', 'Átfutási idő, technikusonkénti teljesítmény, javítási arány és bevétel – a lezárt lapokból, a lezárás napja szerint.');
            ?>

            <form class="sdh-riport__szuro" method="get" action="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS)); ?>">
                <?php foreach (['page', 'muhely'] as $rejtett) : ?>
                    <?php if (isset($_GET[$rejtett])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                        <input type="hidden" name="<?php echo esc_attr($rejtett); ?>" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_GET[$rejtett]))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="sdh-riport__pillek" role="group" aria-label="Időszak">
                    <?php foreach (self::idoszakok() as $k => $nev) : ?>
                        <?php if ($k === 'egyeni') { continue; } ?>
                        <a class="sdh-pill<?php echo $kulcs === $k ? ' is-aktiv' : ''; ?>" href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['idoszak' => $k])); ?>"><span class="sdh-pill__cimke"><?php echo esc_html($nev); ?></span></a>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="idoszak" value="egyeni">
                <label class="sdh-riport__datum">Tól
                    <input type="text" name="tol" class="sdh-pop sdh-pop--datum" readonly data-sdh-pop="datum" data-sdh-pop-adat="[]" value="<?php echo esc_attr($tol); ?>">
                </label>
                <label class="sdh-riport__datum">Ig
                    <input type="text" name="ig" class="sdh-pop sdh-pop--datum" readonly data-sdh-pop="datum" data-sdh-pop-adat="[]" value="<?php echo esc_attr($ig); ?>">
                </label>
                <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Mutasd</button>
            </form>

            <p class="sdh-riport__idoszak"><?php echo esc_html(str_replace('-', '.', $tol) . '. – ' . str_replace('-', '.', $ig) . '.'); ?></p>

            <div class="sdh-riport__csempek">
                <div class="sdh-riport__csempe"><span>Lezárt javítás</span><strong><?php echo (int) $d['javitas_db']; ?></strong></div>
                <div class="sdh-riport__csempe"><span>Átlagos átfutás</span><strong><?php echo esc_html(self::nap((float) $d['atfutas_atlag'])); ?></strong><em>medián: <?php echo esc_html(self::nap((float) $d['atfutas_median'])); ?></em></div>
                <div class="sdh-riport__csempe"><span>Sikeres javítás</span><strong><?php echo esc_html(self::szazalek((float) $d['sikeres_arany'])); ?></strong><em><?php echo (int) $d['visszajott']; ?> jött vissza garanciában</em></div>
                <div class="sdh-riport__csempe"><span>Bevétel (bruttó)</span><strong><?php echo esc_html(self::ft((float) $d['bevetel'])); ?></strong><em>a lezárt lapok végösszege</em></div>
                <div class="sdh-riport__csempe"><span>Árajánlat</span><strong><?php echo (int) $d['ajanlat']['elfogadva']; ?> / <?php echo (int) $d['ajanlat']['kuldve']; ?></strong><em>elfogadva / elküldve, <?php echo (int) $d['ajanlat']['elutasitva']; ?> elutasítva</em></div>
                <div class="sdh-riport__csempe"><span>Elégedettség</span><strong><?php echo $d['elegedettseg']['db'] > 0 ? esc_html(number_format_i18n((float) $d['elegedettseg']['atlag'], 1)) . ' / 5' : '–'; ?></strong><em><?php echo (int) $d['elegedettseg']['db']; ?> értékelés, <?php echo (int) $d['elegedettseg']['jo']; ?> elégedett</em></div>
            </div>

            <div class="sdh-riport__racs">
                <section class="sdh-doboz">
                    <h2 class="sdh-doboz__cim">Technikusonként</h2>
                    <?php if ($d['technikusok'] === []) : ?>
                        <p class="sdh-sugo">Ebben az időszakban nem zárult le javítás.</p>
                    <?php else : ?>
                        <?php $max = max(array_column($d['technikusok'], 'db')); ?>
                        <table class="sdh-tabla">
                            <thead><tr><th>Felelős</th><th class="is-jobb">Lezárt</th><th></th><th class="is-jobb">Átl. átfutás</th><th class="is-jobb">Bevétel</th><th class="is-jobb">Visszajött</th></tr></thead>
                            <tbody>
                                <?php foreach ($d['technikusok'] as $t) : ?>
                                    <tr>
                                        <td><?php echo esc_html((string) $t['nev']); ?></td>
                                        <td class="is-jobb"><?php echo (int) $t['db']; ?></td>
                                        <td class="sdh-riport__savcella"><?php echo self::sav((float) $t['db'], (float) $max); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                        <td class="is-jobb"><?php echo esc_html(self::nap((float) $t['atlag'])); ?></td>
                                        <td class="is-jobb"><?php echo esc_html(self::ft((float) $t['bevetel'])); ?></td>
                                        <td class="is-jobb"><?php echo (int) $t['vissza']; ?> (<?php echo esc_html(self::szazalek((float) $t['vissza_arany'])); ?>)</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <section class="sdh-doboz">
                    <h2 class="sdh-doboz__cim">Bevétel</h2>
                    <?php $max = $d['fajtak'] !== [] ? max(array_column($d['fajtak'], 'bevetel')) : 0; ?>
                    <table class="sdh-tabla">
                        <thead><tr><th>Fajta</th><th class="is-jobb">Lap</th><th></th><th class="is-jobb">Bevétel</th></tr></thead>
                        <tbody>
                            <?php foreach ($d['fajtak'] as $f => $x) : ?>
                                <tr><td><?php echo esc_html(self::fajta_nev((string) $f)); ?></td><td class="is-jobb"><?php echo (int) $x['db']; ?></td><td class="sdh-riport__savcella"><?php echo self::sav((float) $x['bevetel'], (float) $max); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td class="is-jobb"><?php echo esc_html(self::ft((float) $x['bevetel'])); ?></td></tr>
                            <?php endforeach; ?>
                            <tr class="sdh-riport__osszeg"><td>Összesen</td><td></td><td></td><td class="is-jobb"><?php echo esc_html(self::ft((float) $d['bevetel'])); ?></td></tr>
                        </tbody>
                    </table>
                    <h3 class="sdh-riport__alcim">Tételek szerint</h3>
                    <table class="sdh-tabla">
                        <tbody>
                            <tr><td>Szolgáltatások</td><td class="is-jobb"><?php echo esc_html(self::ft((float) $d['tetel']['szolgaltatas'])); ?></td></tr>
                            <tr><td>Termékek</td><td class="is-jobb"><?php echo esc_html(self::ft((float) $d['tetel']['termek'])); ?></td></tr>
                            <?php foreach (array_slice($d['termek_kat'], 0, 8, true) as $k => $o) : ?>
                                <tr class="sdh-riport__al"><td>– <?php echo esc_html((string) $k); ?></td><td class="is-jobb"><?php echo esc_html(self::ft((float) $o)); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section class="sdh-doboz">
                    <h2 class="sdh-doboz__cim">Javítás eszközkategóriánként</h2>
                    <?php if ($d['kategoriak'] === []) : ?>
                        <p class="sdh-sugo">Ebben az időszakban nem zárult le javítás.</p>
                    <?php else : ?>
                        <?php $max = max(array_column($d['kategoriak'], 'bevetel')); ?>
                        <table class="sdh-tabla">
                            <thead><tr><th>Kategória</th><th class="is-jobb">Lap</th><th></th><th class="is-jobb">Bevétel</th></tr></thead>
                            <tbody>
                                <?php foreach ($d['kategoriak'] as $k => $x) : ?>
                                    <tr><td><?php echo esc_html($kat_nev((string) $k)); ?></td><td class="is-jobb"><?php echo (int) $x['db']; ?></td><td class="sdh-riport__savcella"><?php echo self::sav((float) $x['bevetel'], (float) $max); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td class="is-jobb"><?php echo esc_html(self::ft((float) $x['bevetel'])); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <p class="sdh-mezo__sugo">Érvénytelenné tett javítási lap az időszakban: <?php echo (int) $d['ervenytelen']; ?>. Garanciális visszatérés (felvétel szerint): <?php echo (int) $d['vissza_idoszak']; ?>.</p>
                </section>

                <section class="sdh-doboz">
                    <h2 class="sdh-doboz__cim">Az utolsó 12 hónap</h2>
                    <?php $max = max(array_column($honapok, 'bevetel') ?: [0]); ?>
                    <table class="sdh-tabla">
                        <thead><tr><th>Hónap</th><th class="is-jobb">Javítás</th><th></th><th class="is-jobb">Bevétel</th></tr></thead>
                        <tbody>
                            <?php foreach ($honapok as $h => $x) : ?>
                                <tr><td><?php echo esc_html(str_replace('-', '.', $h) . '.'); ?></td><td class="is-jobb"><?php echo (int) $x['db']; ?></td><td class="sdh-riport__savcella"><?php echo self::sav((float) $x['bevetel'], (float) $max); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td class="is-jobb"><?php echo esc_html(self::ft((float) $x['bevetel'])); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
        <?php
    }
}
