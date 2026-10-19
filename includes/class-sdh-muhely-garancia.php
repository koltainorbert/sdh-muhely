<?php
/**
 * Javítási garancia (F1, 0.83.0).
 *
 * A lezárt javítási munkalapra a szerviz garanciát vállal: alapból a Beállítások › Javítási
 * garancia napjai, lapon felülírható (0 = nincs garancia). A garancia a lezárás napjától számít.
 *
 * Ha a készülék visszajön: a munkalap Eszköz-kártyáján jelzés áll („Garanciában: … -ig”, „N. alkalommal
 * jár itt”), és egy gombbal az új lap az eredeti GARANCIÁLIS VISSZATÉRÉSE lesz (`garancia_elozo`). Az
 * eredeti lapon látszanak a visszatérései; a riport (F5) ebből számolja a visszajövési arányt.
 * A garanciális visszatérés új lap (nem az eredeti újranyitása): a régi lap, számlája és pénztára
 * érintetlen marad, a visszatérés díja külön dönthető el (alapból garanciális = ingyenes javítás).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Garancia
{
    public const OPT     = 'sdh_muhely_garancia';
    public const NAP_MAX = 3650;

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_garancia_eszkoz', [self::class, 'ajax_eszkoz']);
    }

    private static function t(string $nev): string
    {
        return SDH_Muhely_Schema::tabla($nev);
    }

    /** @return array{be: bool, nap: int, jelzes: bool} */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        return [
            'be'     => !isset($m['be']) || !empty($m['be']),
            'nap'    => max(0, min(self::NAP_MAX, (int) ($m['nap'] ?? 180))),
            'jelzes' => !isset($m['jelzes']) || !empty($m['jelzes']),
        ];
    }

    /** A lap garanciális napjai (a lapon megadott, különben a beállítás). */
    public static function nap(object $lap): int
    {
        $sajat = $lap->garancia_nap ?? null;

        return $sajat === null || $sajat === '' ? self::beallitas()['nap'] : max(0, (int) $sajat);
    }

    /** A garancia vége (Y-m-d), vagy '' (nincs lezárva / nincs garancia / nem javítás). */
    public static function lejar(object $lap): string
    {
        $lezarva = (string) ($lap->lezarva ?? '');
        $nap     = self::nap($lap);

        if (!self::beallitas()['be'] || $lezarva === '' || $nap < 1 || (string) ($lap->fajta ?? 'javitas') !== 'javitas') {
            return '';
        }

        $d = DateTimeImmutable::createFromFormat('Y-m-d', substr($lezarva, 0, 10), wp_timezone());

        return $d ? $d->modify('+' . $nap . ' days')->format('Y-m-d') : '';
    }

    public static function ervenyes(object $lap, ?string $ma = null): bool
    {
        $vege = self::lejar($lap);

        return $vege !== '' && $vege >= ($ma ?? current_time('Y-m-d'));
    }

    /** @return array<int, object> az eszköz javítási lapjai, a legújabb elöl */
    public static function eszkoz_lapjai(int $eszkoz_id): array
    {
        global $wpdb;

        if ($eszkoz_id < 1) {
            return [];
        }

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, munkalap_szam, allapot, lezarva, keszult, garancia_nap, garancia_elozo, fajta FROM ' . self::t('munkalap')
            . " WHERE eszkoz_id = %d AND fajta = 'javitas' ORDER BY COALESCE(lezarva, keszult) DESC, id DESC",
            $eszkoz_id
        ));
    }

    /** Az eszköz legutóbbi, még érvényes garanciájú lezárt lapja (a megadott lapot kihagyva), vagy null. */
    public static function eszkoz_garancia(int $eszkoz_id, int $kiveve = 0): ?object
    {
        foreach (self::eszkoz_lapjai($eszkoz_id) as $l) {
            if ((int) $l->id === $kiveve) {
                continue;
            }

            // Visszatérésnél az eredeti lap garanciája számít (a lánc elejéé).
            if ((int) $l->garancia_elozo > 0) {
                $eredeti = self::lap((int) $l->garancia_elozo);

                if ($eredeti !== null && (int) $eredeti->id !== $kiveve && self::ervenyes($eredeti)) {
                    return $eredeti;
                }
            }

            if (self::ervenyes($l)) {
                return $l;
            }
        }

        return null;
    }

    public static function lap(int $id): ?object
    {
        global $wpdb;

        $l = $wpdb->get_row($wpdb->prepare(
            'SELECT id, munkalap_szam, allapot, lezarva, keszult, garancia_nap, garancia_elozo, fajta, eszkoz_id FROM ' . self::t('munkalap') . ' WHERE id = %d',
            $id
        ));

        return $l ?: null;
    }

    /** @return array<int, object> a lap garanciális visszatérései */
    public static function visszateresek(int $munkalap_id): array
    {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, munkalap_szam, keszult, allapot FROM ' . self::t('munkalap') . ' WHERE garancia_elozo = %d ORDER BY id',
            $munkalap_id
        ));
    }

    public static function lap_felirat(object $l): string
    {
        return (int) $l->munkalap_szam > 0 ? SDH_Muhely_Munkalap::szam_formaz($l->munkalap_szam) : '#' . (int) $l->id;
    }

    private static function datum(string $ymd): string
    {
        return $ymd === '' ? '' : str_replace('-', '.', substr($ymd, 0, 10)) . '.';
    }

    /* =================================================================
     * Munkalap: beküldött mezők, űrlap, eszközkártya
     * ============================================================== */

    /**
     * A munkalap mentésébe kerülő mezők (csak javításnál; ami nem jött, az marad).
     *
     * @return array<string, int|null>
     */
    public static function bekuldott(?object $regi, string $fajta): array
    {
        if ($fajta !== 'javitas') {
            return [];
        }

        $ki = [];

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a munkalap mentése ellenőrizte.
        if (isset($_POST['garancia_nap'])) {
            $nyers = trim(sanitize_text_field(wp_unslash((string) $_POST['garancia_nap'])));
            $ki['garancia_nap'] = $nyers === '' ? null : max(0, min(self::NAP_MAX, (int) $nyers));
        }

        if (isset($_POST['garancia_elozo'])) {
            $elozo = max(0, (int) $_POST['garancia_elozo']);

            // Csak létező javítási lapra mutathat, és magára nem.
            if ($elozo > 0 && ($elozo === (int) ($regi->id ?? 0) || self::lap($elozo) === null)) {
                $elozo = (int) ($regi->garancia_elozo ?? 0);
            }

            $ki['garancia_elozo'] = $elozo;
        }
        // phpcs:enable

        return $ki;
    }

    /** A munkalap űrlapjának „Javítási garancia” csoportja (a Dátumok alatt). */
    public static function urlap_blokk(?object $lap): string
    {
        if (!self::beallitas()['be']) {
            return '';
        }

        $alap   = self::beallitas()['nap'];
        $nap    = $lap !== null && isset($lap->garancia_nap) && $lap->garancia_nap !== null && $lap->garancia_nap !== '' ? (string) (int) $lap->garancia_nap : '';
        $elozo  = $lap !== null ? (int) ($lap->garancia_elozo ?? 0) : 0;
        $lejar  = $lap !== null ? self::lejar($lap) : '';
        $vissza = $lap !== null && !empty($lap->id) ? self::visszateresek((int) $lap->id) : [];

        ob_start();
        ?>
        <div class="sdh-ml__csoport sdh-ml-garancia" data-sdh-garancia>
            <h3 class="sdh-ml__cim">Javítási garancia</h3>
            <input type="hidden" name="garancia_elozo" value="<?php echo (int) $elozo; ?>" data-sdh-garancia-elozo-mezo>
            <div class="sdh-ig">
                <label for="garancia_nap">Garancia (nap)</label>
                <input type="number" name="garancia_nap" id="garancia_nap" min="0" max="<?php echo (int) self::NAP_MAX; ?>" step="1"
                       value="<?php echo esc_attr($nap); ?>" placeholder="<?php echo (int) $alap; ?>">
            </div>
            <?php if ($lejar !== '') : ?>
                <div class="sdh-ig sdh-ml__olvas">
                    <span class="sdh-ig__cimke">Lejár</span>
                    <span><?php echo esc_html(self::datum($lejar)); ?><?php echo self::ervenyes($lap) ? '' : ' (lejárt)'; ?></span>
                </div>
            <?php endif; ?>
            <p class="sdh-ml-garancia__elozo" data-sdh-garancia-elozo-sav<?php echo $elozo > 0 ? '' : ' hidden'; ?>>
                <?php if ($elozo > 0 && ($e = self::lap($elozo))) : ?>
                    <strong>Garanciális visszatérés</strong> – az eredeti lap: <?php echo esc_html(self::lap_felirat($e)); ?>
                    (lezárva <?php echo esc_html(self::datum((string) $e->lezarva)); ?>)
                <?php else : ?>
                    <strong>Garanciális visszatérés</strong>
                <?php endif; ?>
            </p>
            <?php if ($vissza !== []) : ?>
                <p class="sdh-ml-garancia__vissza">
                    Garanciális visszatérés: <?php echo esc_html(implode(', ', array_map(static fn (object $v): string => self::lap_felirat($v) . ' (' . self::datum((string) $v->keszult) . ')', $vissza))); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Az eszközkártya garancia-jelzése (a munkalap Eszköz lapfülén, az eszköz adatai alatt):
     * hányszor járt itt, és ha még garanciális, egy gomb a visszatérés rögzítésére.
     */
    public static function eszkoz_jelzes(int $eszkoz_id): string
    {
        if (!self::beallitas()['be'] || !self::beallitas()['jelzes'] || $eszkoz_id < 1) {
            return '';
        }

        $lapok = self::eszkoz_lapjai($eszkoz_id);

        if ($lapok === []) {
            return '';
        }

        $gar = self::eszkoz_garancia($eszkoz_id);
        $db  = count($lapok);
        $ki  = '<div class="sdh-ml-garancia-jel' . ($gar ? ' is-garancialis' : '') . '" data-sdh-garancia-jel>';
        $ki .= '<span>Ez a készülék már <strong>' . (int) $db . '</strong> munkalapon szerepelt'
            . ' (legutóbb: ' . esc_html(self::lap_felirat($lapok[0])) . ').</span>';

        if ($gar) {
            $ki .= '<span><strong>Garanciában</strong> – ' . esc_html(self::lap_felirat($gar)) . ' lap, '
                . esc_html(self::datum(self::lejar($gar))) . '-ig.</span>'
                . '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-garancia-elozo="' . (int) $gar->id . '" data-sdh-garancia-felirat="'
                . esc_attr(self::lap_felirat($gar)) . '">Garanciális visszatérésként rögzítem</button>';
        }

        return $ki . '</div>';
    }

    /** Az eszköz garancia-összegzése (az eszköz adatlapjához és az asszisztenshez). */
    public static function ajax_eszkoz(): void
    {
        check_ajax_referer('sdh_muhely_modal', '_wpnonce');

        $id  = isset($_GET['eszkoz_id']) ? (int) $_GET['eszkoz_id'] : 0;
        $gar = self::eszkoz_garancia($id);

        wp_send_json_success([
            'db'          => count(self::eszkoz_lapjai($id)),
            'garancialis' => $gar !== null,
            'lap'         => $gar ? self::lap_felirat($gar) : '',
            'lejar'       => $gar ? self::lejar($gar) : '',
            'html'        => self::eszkoz_jelzes($id),
        ]);
    }

    /* =================================================================
     * Beállítások › Javítási garancia
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();
        ?>
        <div class="sdh-doboz" id="garancia">
            <h2 class="sdh-doboz__cim">Javítási garancia</h2>
            <p class="sdh-sugo">
                A lezárt javítási munkalapra vállalt garancia. A lezárás napjától számít; a lapon egyenként felülírható
                (0 = nincs garancia). Ha a készülék a garancián belül visszajön, a munkalap Eszköz lapfülén jelzés áll,
                és egy gombbal garanciális visszatérésként rögzíthető. A jogszabályi jótállási időt ellenőrizd, és ahhoz
                igazítsd az alapértéket.
            </p>
            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="hidden" name="garancia[be_jelen]" value="1">
                    <input type="checkbox" name="garancia[be]" id="garancia_be" value="1" <?php checked($b['be']); ?>>
                    <label for="garancia_be">Javítási garancia a munkalapon (a „Javítási garancia” csoport, a lejárat a nyomtatványon, a visszatérő készülék jelzése)</label>
                </div>
                <div class="sdh-mezo">
                    <label for="garancia_alap_nap">Alap garancia (nap)</label>
                    <input type="number" name="garancia[nap]" id="garancia_alap_nap" min="0" max="<?php echo (int) self::NAP_MAX; ?>" step="1" value="<?php echo (int) $b['nap']; ?>">
                    <span class="sdh-mezo__sugo">Pl. 90 = három hónap, 180 = fél év, 365 = egy év.</span>
                </div>
                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="hidden" name="garancia[jelzes_jelen]" value="1">
                    <input type="checkbox" name="garancia[jelzes]" id="garancia_jelzes" value="1" <?php checked($b['jelzes']); ?>>
                    <label for="garancia_jelzes">Visszatérő készülék jelzése a munkalapon (hányszor járt itt, garanciában van-e)</label>
                </div>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        $be = isset($_POST['garancia']) && is_array($_POST['garancia']) ? wp_unslash($_POST['garancia']) : [];

        update_option(self::OPT, [
            'be'     => !empty($be['be']) || empty($be['be_jelen']),
            'nap'    => max(0, min(self::NAP_MAX, (int) ($be['nap'] ?? 180))),
            'jelzes' => !empty($be['jelzes']),
        ], false);
    }
}
