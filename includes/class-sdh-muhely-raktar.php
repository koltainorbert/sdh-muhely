<?php
/**
 * Raktár (F4, 0.86.0): foglalás, minimumkészlet, rendelési javaslat.
 *
 * A munkalapra tett termék már most levonódik a készletből (SDH_Muhely_Termek::munkalap_mozgasok). Erre épül:
 * - **Foglalt**: a még NYITOTT (nem lezárt) munkalapokon álló mennyiség – ez van „lefoglalva” a javításra.
 *   A termék űrlapján és a rendelési javaslatban látszik, melyik lapokon.
 * - **Hiány a lapon**: ha egy lap terméke miatt a készlet nulla alá ment, a munkalapon jelzés áll.
 * - **Rendelési javaslat** (Termékek › Rendelési javaslat): ami a minimum alatt van (vagy negatív), beszállítónként
 *   csoportosítva, javasolt mennyiséggel (a célkészletig), becsült beszerzési értékkel; CSV-be menthető, a
 *   beszállítónak másolható szöveggel, és soronként „Megérkezett” – bevételezés egy kattintással.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Raktar
{
    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_raktar_bevet', [self::class, 'ajax_bevet']);
        add_action('wp_ajax_sdh_muhely_raktar_csv', [self::class, 'ajax_csv']);
    }

    private static function t(string $nev): string
    {
        return SDH_Muhely_Schema::tabla($nev);
    }

    /** A lezárt (nem fogyasztó-foglaló) állapotok kulcsai. @return array<int, string> */
    private static function zart_kulcsok(): array
    {
        return SDH_Muhely_Munkalap::zart_kulcsok(SDH_Muhely_Munkalap::allapotok());
    }

    /**
     * Foglalások: termék => [mennyiség, lapok]. Nyitott (nem lezárt, nem érvénytelen) lapok kimenő terméktételei.
     *
     * @param array<int, int>|null $termek_idk csak ezek (null = mind)
     * @return array<int, array{menny: float, lapok: array<int, array{id: int, szam: string, menny: float}>}>
     */
    public static function foglalasok(?array $termek_idk = null): array
    {
        global $wpdb;

        $zart = self::zart_kulcsok();
        $zart[] = 'ervenytelen';
        $hely_z = implode(',', array_fill(0, count($zart), '%s'));
        $sql    = 'SELECT t.termek_id, t.mennyiseg, m.id AS lap_id, m.munkalap_szam
                     FROM ' . SDH_Muhely_Tetel::tabla() . ' t
               INNER JOIN ' . self::t('munkalap') . " m ON m.id = t.munkalap_id
                    WHERE t.tipus = 'termek' AND t.mozgas = 'kimeno' AND t.termek_id > 0
                      AND m.munkalap_szam IS NOT NULL AND m.allapot NOT IN ({$hely_z})";
        $args   = $zart;

        if ($termek_idk !== null) {
            $termek_idk = array_values(array_filter(array_map('intval', $termek_idk)));

            if ($termek_idk === []) {
                return [];
            }

            $sql .= ' AND t.termek_id IN (' . implode(',', array_fill(0, count($termek_idk), '%d')) . ')';
            $args = array_merge($args, $termek_idk);
        }

        $ki = [];

        foreach ((array) $wpdb->get_results($wpdb->prepare($sql, $args)) as $s) { // phpcs:ignore WordPress.DB.PreparedSQL
            $tid = (int) $s->termek_id;
            $ki[$tid] = $ki[$tid] ?? ['menny' => 0.0, 'lapok' => []];
            $ki[$tid]['menny'] += (float) $s->mennyiseg;
            $lap = (int) $s->lap_id;

            if (!isset($ki[$tid]['lapok'][$lap])) {
                $ki[$tid]['lapok'][$lap] = ['id' => $lap, 'szam' => SDH_Muhely_Munkalap::szam_formaz($s->munkalap_szam), 'menny' => 0.0];
            }

            $ki[$tid]['lapok'][$lap]['menny'] += (float) $s->mennyiseg;
        }

        return $ki;
    }

    /** A rendelés utáni célkészlet: a megadott, különben a minimum kétszerese (legalább 1). */
    public static function cel(object $t): float
    {
        $cel = (float) ($t->cel_keszlet ?? 0);

        if ($cel > 0) {
            return $cel;
        }

        $min = (float) $t->min_keszlet;

        return $min > 0 ? $min * 2 : 1.0;
    }

    /** Javasolt rendelési mennyiség (egész egységre felfelé kerekítve), 0 = nem kell. */
    public static function javasolt(object $t): float
    {
        $keszlet = (float) $t->keszlet;
        $min     = (float) $t->min_keszlet;

        if (!((int) $t->keszletkezeles === 1 && (int) $t->aktiv === 1 && ($keszlet < 0 || ($min > 0 && $keszlet < $min) || ($min <= 0 && $keszlet <= 0 && (float) ($t->cel_keszlet ?? 0) > 0)))) {
            return 0.0;
        }

        return max(0.0, ceil(self::cel($t) - $keszlet));
    }

    /**
     * Hány terméket kell rendelni (a Termékek lista fülének száma) – a foglalások nélkül (azok csak a javaslat
     * kijelzéséhez kellenek), és tárolva, amíg a termékek nem változnak (0.99.4: 300 000 terméknél ez lassította a listát).
     */
    public static function javaslat_db(): int
    {
        global $wpdb;

        // Ugyanaz, mint a javasolt() > 0, egy számlálással: a célkészlet (cel()) nagyobb a készletnél.
        return (int) SDH_Muhely_Gyorsitotar::ertek('raktar_javaslat_db', static function () use ($wpdb): int {
            return (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM ' . self::t('termek') . ' WHERE aktiv = 1 AND keszletkezeles = 1
                   AND (keszlet < 0 OR (min_keszlet > 0 AND keszlet < min_keszlet) OR (keszlet <= 0 AND cel_keszlet > 0))
                   AND (CASE WHEN cel_keszlet > 0 THEN cel_keszlet WHEN min_keszlet > 0 THEN min_keszlet * 2 ELSE 1 END) > keszlet'
            );
        });
    }

    /**
     * A rendelési javaslat, beszállítónként.
     *
     * @return array<string, array<int, array<string, mixed>>> beszállító => sorok
     */
    public static function javaslat(): array
    {
        global $wpdb;

        $sorok = (array) $wpdb->get_results(
            'SELECT * FROM ' . self::t('termek') . ' WHERE aktiv = 1 AND keszletkezeles = 1
               AND (keszlet < 0 OR (min_keszlet > 0 AND keszlet < min_keszlet) OR (keszlet <= 0 AND cel_keszlet > 0))
             ORDER BY beszallito, megnevezes'
        );

        $fogl = self::foglalasok(array_map(static fn (object $t): int => (int) $t->id, $sorok));
        $ki   = [];

        foreach ($sorok as $t) {
            $menny = self::javasolt($t);

            if ($menny <= 0) {
                continue;
            }

            $besz       = trim((string) $t->beszallito) !== '' ? trim((string) $t->beszallito) : '(nincs megadva beszállító)';
            $ki[$besz][] = [
                'termek'  => $t,
                'menny'   => $menny,
                'foglalt' => (float) ($fogl[(int) $t->id]['menny'] ?? 0),
                'lapok'   => array_values($fogl[(int) $t->id]['lapok'] ?? []),
                'ertek'   => round($menny * (float) $t->besz_netto, 2),
            ];
        }

        return $ki;
    }

    /**
     * A munkalap termékei közül, amelyeknél a készlet nulla alá ment (nincs elég a raktárban).
     *
     * @return array<int, object>
     */
    public static function lap_hianya(int $munkalap_id): array
    {
        global $wpdb;

        if ($munkalap_id < 1) {
            return [];
        }

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT DISTINCT p.id, p.megnevezes, p.keszlet, p.me FROM ' . SDH_Muhely_Tetel::tabla() . ' t
             INNER JOIN ' . self::t('termek') . " p ON p.id = t.termek_id
             WHERE t.munkalap_id = %d AND t.tipus = 'termek' AND t.mozgas = 'kimeno' AND p.keszletkezeles = 1 AND p.keszlet < 0",
            $munkalap_id
        ));
    }

    /* =================================================================
     * Felület
     * ============================================================== */

    private static function menny(float $m): string
    {
        return class_exists('SDH_Muhely_Termek') ? SDH_Muhely_Termek::menny($m) : (string) $m;
    }

    private static function ft(float $o): string
    {
        return number_format($o, 0, ',', ' ') . ' Ft';
    }

    /** A munkalap jelzése (bal oldalt), ha valamelyik terméke nincs raktáron. */
    public static function munkalap_jelzes(?object $l): string
    {
        $hiany = $l !== null && !empty($l->id) ? self::lap_hianya((int) $l->id) : [];

        if ($hiany === []) {
            return '';
        }

        $ki = '<div class="sdh-ml__csoport sdh-ml-raktar"><h3 class="sdh-ml__cim">Raktár</h3><p class="sdh-ml-raktar__hiany">Nincs elég raktáron:</p><ul>';

        foreach ($hiany as $h) {
            $ki .= '<li>' . esc_html((string) $h->megnevezes) . ' <span>(' . esc_html(self::menny((float) $h->keszlet) . ' ' . (string) $h->me) . ')</span></li>';
        }

        return $ki . '</ul><p class="sdh-mezo__sugo">Rendelni kell: Termékek › Rendelési javaslat.</p></div>';
    }

    /** A termék űrlapjának foglalás-sora. */
    public static function termek_foglalas(?object $t): string
    {
        if ($t === null || empty($t->id) || (int) $t->keszletkezeles !== 1) {
            return '';
        }

        $f = self::foglalasok([(int) $t->id])[(int) $t->id] ?? null;

        if ($f === null) {
            return '<span class="sdh-termekurlap__sugo">Nyitott munkalapon nincs lefoglalva.</span>';
        }

        $lapok = implode(', ', array_map(static fn (array $l): string => $l['szam'] . ' (' . self::menny($l['menny']) . ')', array_slice(array_values($f['lapok']), 0, 8)));

        return '<span class="sdh-termekurlap__sugo"><strong>Foglalva nyitott munkalapokon: ' . esc_html(self::menny($f['menny']) . ' ' . (string) $t->me) . '</strong> – ' . esc_html($lapok)
            . (count($f['lapok']) > 8 ? ' …' : '') . '</span>';
    }

    /** Termékek › Rendelési javaslat. */
    public static function javaslat_oldal(): void
    {
        $javaslat = self::javaslat();
        $nonce    = wp_create_nonce('sdh_muhely_modal');
        $csv      = add_query_arg(['action' => 'sdh_muhely_raktar_csv', '_wpnonce' => $nonce], admin_url('admin-ajax.php'));
        ?>
        <div class="sdh-raktar" data-sdh-raktar>
            <p class="sdh-sugo">
                Ami a minimális készlet alatt van (vagy elfogyott a munkalapok miatt), beszállítónként. A javasolt mennyiség a
                <strong>célkészletig</strong> tölt fel (a termék űrlapján állítható; üresen a minimum kétszerese). A „Foglalt” a még
                nyitott munkalapokon álló mennyiség. Ha megérkezett, a <strong>Megérkezett</strong> gomb bevételezi.
            </p>
            <?php if ($javaslat === []) : ?>
                <div class="sdh-uzenet sdh-uzenet--siker">Most semmit nem kell rendelni.</div>
            <?php else : ?>
                <p><a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url($csv); ?>">Letöltés (CSV, Excelhez)</a></p>
                <?php foreach ($javaslat as $besz => $sorok) : ?>
                    <?php
                    $osszes  = array_sum(array_column($sorok, 'ertek'));
                    $szoveg  = "Rendelés\n" . implode("\n", array_map(static function (array $s): string {
                        $t = $s['termek'];

                        return '- ' . $t->megnevezes . ((string) $t->cikkszam !== '' ? ' (' . $t->cikkszam . ')' : '') . ': ' . self::menny((float) $s['menny']) . ' ' . $t->me;
                    }, $sorok));
                    ?>
                    <section class="sdh-doboz sdh-raktar__besz">
                        <h2 class="sdh-doboz__cim"><?php echo esc_html($besz); ?> <span class="sdh-raktar__ossz">≈ <?php echo esc_html(self::ft($osszes)); ?> nettó</span></h2>
                        <table class="sdh-tabla">
                            <thead><tr><th>Termék</th><th>Cikkszám</th><th class="is-jobb">Készlet</th><th class="is-jobb">Min.</th><th class="is-jobb">Foglalt</th><th class="is-jobb">Rendelni</th><th class="is-jobb">Érték (nettó)</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($sorok as $s) : ?>
                                    <?php $t = $s['termek']; ?>
                                    <tr data-sdh-raktar-sor="<?php echo (int) $t->id; ?>">
                                        <td><a href="#" data-sdh-urlap="termekek" data-sdh-id="<?php echo (int) $t->id; ?>"><?php echo esc_html((string) $t->megnevezes); ?></a></td>
                                        <td><?php echo esc_html((string) $t->cikkszam); ?></td>
                                        <td class="is-jobb<?php echo (float) $t->keszlet < 0 ? ' is-hiba' : ''; ?>" data-sdh-raktar-keszlet><?php echo esc_html(self::menny((float) $t->keszlet) . ' ' . (string) $t->me); ?></td>
                                        <td class="is-jobb"><?php echo esc_html(self::menny((float) $t->min_keszlet)); ?></td>
                                        <td class="is-jobb" title="<?php echo esc_attr(implode(', ', array_column($s['lapok'], 'szam'))); ?>"><?php echo esc_html(self::menny((float) $s['foglalt'])); ?></td>
                                        <td class="is-jobb"><input type="text" inputmode="decimal" class="sdh-raktar__menny" value="<?php echo esc_attr(self::menny((float) $s['menny'])); ?>" data-sdh-raktar-menny aria-label="Mennyiség"></td>
                                        <td class="is-jobb"><?php echo esc_html(self::ft((float) $s['ertek'])); ?></td>
                                        <td><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-raktar-bevet="<?php echo (int) $t->id; ?>">Megérkezett</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <details class="sdh-raktar__szoveg">
                            <summary>Szöveg a beszállítónak (másoláshoz)</summary>
                            <textarea readonly rows="<?php echo (int) min(12, count($sorok) + 2); ?>"><?php echo esc_textarea($szoveg); ?></textarea>
                        </details>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** „Megérkezett”: bevételezés a megadott mennyiséggel. */
    public static function ajax_bevet(): void
    {
        check_ajax_referer('sdh_muhely_modal', '_wpnonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id    = isset($_POST['termek']) ? (int) $_POST['termek'] : 0;
        $menny = isset($_POST['mennyiseg']) ? (float) str_replace([',', ' '], ['.', ''], sanitize_text_field(wp_unslash((string) $_POST['mennyiseg']))) : 0.0;
        $t     = SDH_Muhely_Termek::egy($id);

        if ($t === null || $menny <= 0 || $menny > 100000) {
            wp_send_json_error(['uzenet' => 'Adj meg érvényes mennyiséget.']);
        }

        SDH_Muhely_Termek::mozgas($id, $menny, 'bevet', 'Rendelés megérkezett (rendelési javaslat)');
        $t = SDH_Muhely_Termek::egy($id);

        wp_send_json_success(['keszlet' => self::menny((float) $t->keszlet) . ' ' . (string) $t->me, 'uzenet' => 'Bevételezve.']);
    }

    /** A rendelési javaslat CSV-ben (Excel: pontosvessző, UTF-8 BOM). */
    public static function csv(): string
    {
        $ki = "\xEF\xBB\xBF" . implode(';', ['Beszállító', 'Termék', 'Cikkszám', 'Termékkód', 'Készlet', 'Min. készlet', 'Foglalt', 'Rendelni', 'M.e.', 'Beszerzési nettó', 'Érték (nettó)']) . "\r\n";
        $mezo = static fn ($v): string => '"' . str_replace('"', '""', (string) $v) . '"';
        $szam = static fn (float $v): string => str_replace('.', ',', rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.'));

        foreach (self::javaslat() as $besz => $sorok) {
            foreach ($sorok as $s) {
                $t   = $s['termek'];
                $ki .= implode(';', [
                    $mezo($besz), $mezo($t->megnevezes), $mezo($t->cikkszam), $mezo($t->termekkod),
                    $szam((float) $t->keszlet), $szam((float) $t->min_keszlet), $szam((float) $s['foglalt']), $szam((float) $s['menny']),
                    $mezo($t->me), $szam((float) $t->besz_netto), $szam((float) $s['ertek']),
                ]) . "\r\n";
            }
        }

        return $ki;
    }

    public static function ajax_csv(): void
    {
        check_ajax_referer('sdh_muhely_modal', '_wpnonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_die('Nincs jogosultságod ehhez.', '', ['response' => 403]);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rendelesi-javaslat-' . current_time('Y-m-d') . '.csv"');
        echo self::csv(); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }
}
