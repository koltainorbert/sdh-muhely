<?php
/**
 * Online árajánlat-elfogadás (F2, 0.84.0).
 *
 * A munkalapról a munkatárs egy gombbal elküldi az árajánlatot (a lap mostani végösszegét): az ügyfél
 * üzenetet kap (az ügyféloldal csevegőjébe, és e-mailben, ha van címe), az ügyféloldalon pedig az
 * összegzés tetején megjelenik az ajánlat két gombbal: **Elfogadom** / **Nem kérem** (megjegyzéssel).
 * A válasz a lapra kerül, üzenetként a munkalap csevegőjébe (olvasatlanként, a szerviznek e-mailben is),
 * és a Változásnaplóba. Az ügyfél írása nem számít párhuzamos szerkesztésnek (nincs belépett felhasználója).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Ajanlat
{
    public const MEGJ_HOSSZ = 500;

    public const OPT = 'sdh_muhely_ajanlat';

    /** @return array{be: bool} */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        return ['be' => !isset($m['be']) || !empty($m['be'])];
    }

    /* =================================================================
     * Beállítások › Online árajánlat
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();
        ?>
        <div class="sdh-doboz" id="ajanlat">
            <h2 class="sdh-doboz__cim">Online árajánlat</h2>
            <p class="sdh-sugo">
                A munkalapról egy gombbal elküldhető az árajánlat (a lap végösszege): az ügyfél az ügyféloldalon elfogadja vagy
                elutasítja, és erről értesítés jön. Kikapcsolva az „Online árajánlat” csoport eltűnik a munkalapról; a már
                elküldött ajánlatok válasza megmarad.
            </p>
            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="hidden" name="ajanlat[be_jelen]" value="1">
                    <input type="checkbox" name="ajanlat[be]" id="ajanlat_be" value="1" <?php checked($b['be']); ?>>
                    <label for="ajanlat_be">Online árajánlat a munkalapon</label>
                </div>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        $be = isset($_POST['ajanlat']) && is_array($_POST['ajanlat']) ? wp_unslash($_POST['ajanlat']) : [];

        update_option(self::OPT, ['be' => !empty($be['be']) || empty($be['be_jelen'])], false);
    }

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_ajanlat_kuld', [self::class, 'ajax_kuld']);
        add_action('wp_ajax_sdh_muhely_ajanlat_visszavon', [self::class, 'ajax_visszavon']);
    }

    private static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('munkalap');
    }

    public static function lap(int $id): ?object
    {
        global $wpdb;

        $l = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id));

        return $l ?: null;
    }

    private static function ft(float $osszeg): string
    {
        return number_format($osszeg, 0, ',', ' ') . ' Ft';
    }

    /** Elérhető-e (be van kapcsolva, és kell hozzá az ügyféloldal modul). */
    public static function elerheto(): bool
    {
        return self::beallitas()['be'] && class_exists('SDH_Muhely_Rma') && !(class_exists('SDH_Muhely_Modulok') && SDH_Muhely_Modulok::ki('uzenetek'));
    }

    /**
     * Az ajánlat elküldése: a lap mostani végösszege. Visszaad: hibaüzenet vagy ''.
     */
    public static function kuld(int $id, int $felhasznalo = 0): string
    {
        global $wpdb;

        $l = self::lap($id);

        if ($l === null) {
            return 'Nincs ilyen munkalap.';
        }

        if ((float) $l->brutto_ertek <= 0) {
            return 'A lapon még nincs összeg: előbb vedd fel a tételeket, és mentsd a lapot.';
        }

        if ((int) $l->ugyfel_id < 1) {
            return 'A lapnak nincs ügyfele.';
        }

        $most = current_time('mysql');

        $wpdb->update(self::tabla(), [
            'ajanlat_allapot'    => 'var',
            'ajanlat_osszeg'     => round((float) $l->brutto_ertek, 2),
            'ajanlat_kuldve'     => $most,
            'ajanlat_valasz'     => null,
            'ajanlat_megjegyzes' => '',
        ], ['id' => $id]);

        // Az ügyfélnek: üzenet a csevegőbe (e-mailben is, ha van címe) – a linkkel együtt.
        SDH_Muhely_Rma::token($l);
        $szoveg = 'Árajánlatot küldtünk: ' . self::ft((float) $l->brutto_ertek) . ' (bruttó). A tételeket és az összeget az ügyféloldalon '
            . 'nézheti meg, és ott el is fogadhatja vagy elutasíthatja.';
        SDH_Muhely_Rma::uzenet_ment($id, (int) $l->ugyfel_id, 'ki', 'crm', $szoveg, $felhasznalo, true);

        SDH_Muhely_Valtozasnaplo::esemeny('munkalap', $id, 'Árajánlat elküldve az ügyfélnek: ' . self::ft((float) $l->brutto_ertek));

        return '';
    }

    public static function visszavon(int $id): string
    {
        global $wpdb;

        $l = self::lap($id);

        if ($l === null || (string) $l->ajanlat_allapot !== 'var') {
            return 'Nincs függő ajánlat.';
        }

        $wpdb->update(self::tabla(), ['ajanlat_allapot' => ''], ['id' => $id]);
        SDH_Muhely_Valtozasnaplo::esemeny('munkalap', $id, 'Árajánlat visszavonva');

        return '';
    }

    /**
     * Az ügyfél válasza (az ügyféloldalról). Csak függő ajánlatra; egyszer.
     */
    public static function valasz(int $id, bool $elfogad, string $megjegyzes = ''): bool
    {
        global $wpdb;

        $l = self::lap($id);

        if ($l === null || (string) $l->ajanlat_allapot !== 'var') {
            return false;
        }

        $megjegyzes = mb_substr(trim(sanitize_textarea_field($megjegyzes)), 0, self::MEGJ_HOSSZ);

        $wpdb->update(self::tabla(), [
            'ajanlat_allapot'    => $elfogad ? 'elfogadva' : 'elutasitva',
            'ajanlat_valasz'     => current_time('mysql'),
            'ajanlat_megjegyzes' => $megjegyzes,
        ], ['id' => $id]);

        $szoveg = ($elfogad ? 'Az árajánlatot ELFOGADOM' : 'Az árajánlatot NEM KÉREM') . ' (' . self::ft((float) $l->ajanlat_osszeg) . ').'
            . ($megjegyzes !== '' ? "\nMegjegyzés: " . $megjegyzes : '');

        // A szerviznek: olvasatlan üzenet a csevegőben (és e-mail a beállított címre).
        SDH_Muhely_Rma::uzenet_ment($id, (int) $l->ugyfel_id, 'be', 'rma', $szoveg, 0);
        SDH_Muhely_Valtozasnaplo::esemeny('munkalap', $id, 'Árajánlat ' . ($elfogad ? 'elfogadva' : 'elutasítva') . ' az ügyféloldalon: ' . self::ft((float) $l->ajanlat_osszeg)
            . ($megjegyzes !== '' ? ' – „' . mb_substr($megjegyzes, 0, 120) . '”' : ''));

        return true;
    }

    /* =================================================================
     * Munkalap (munkatárs)
     * ============================================================== */

    /** A munkalap űrlapjának „Online árajánlat” csoportja. */
    public static function urlap_blokk(?object $l): string
    {
        if ($l === null || empty($l->id) || !self::elerheto()) {
            return '';
        }

        $a      = (string) ($l->ajanlat_allapot ?? '');
        $osszeg = (float) ($l->ajanlat_osszeg ?? 0);
        $ido    = static fn ($v): string => $v ? mysql2date('Y.m.d. H:i', (string) $v) : '';

        ob_start();
        ?>
        <div class="sdh-ml__csoport sdh-ml-ajanlat" data-sdh-ajanlat="<?php echo (int) $l->id; ?>">
            <h3 class="sdh-ml__cim">Online árajánlat</h3>
            <?php if ($a === 'var') : ?>
                <p class="sdh-ml-ajanlat__allapot is-var">Elküldve <?php echo esc_html($ido($l->ajanlat_kuldve)); ?> – <strong><?php echo esc_html(self::ft($osszeg)); ?></strong>. Válaszra vár.</p>
                <?php if (abs((float) $l->brutto_ertek - $osszeg) > 0.5) : ?>
                    <p class="sdh-ml-ajanlat__figyel">A lap összege azóta változott (<?php echo esc_html(self::ft((float) $l->brutto_ertek)); ?>): küldd újra.</p>
                <?php endif; ?>
                <p class="sdh-ml-ajanlat__gombok">
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ajanlat-akcio="kuld">Újraküldés</button>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ajanlat-akcio="visszavon">Visszavonás</button>
                </p>
            <?php elseif ($a === 'elfogadva' || $a === 'elutasitva') : ?>
                <p class="sdh-ml-ajanlat__allapot is-<?php echo esc_attr($a); ?>">
                    <strong><?php echo $a === 'elfogadva' ? 'Elfogadta' : 'Elutasította'; ?></strong> az ügyfél <?php echo esc_html($ido($l->ajanlat_valasz)); ?>
                    (<?php echo esc_html(self::ft($osszeg)); ?>).
                </p>
                <?php if ((string) $l->ajanlat_megjegyzes !== '') : ?>
                    <p class="sdh-ml-ajanlat__megj">„<?php echo esc_html((string) $l->ajanlat_megjegyzes); ?>”</p>
                <?php endif; ?>
                <p class="sdh-ml-ajanlat__gombok">
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ajanlat-akcio="kuld">Új ajánlat küldése</button>
                </p>
            <?php else : ?>
                <p class="sdh-mezo__sugo">A lap mostani végösszegét küldi el az ügyfélnek: az ügyféloldalon egy gombbal elfogadja vagy elutasítja. Előtte mentsd a lapot.</p>
                <p class="sdh-ml-ajanlat__gombok">
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ajanlat-akcio="kuld">Árajánlat küldése az ügyfélnek</button>
                </p>
            <?php endif; ?>
            <span class="sdh-mezo__sugo" data-sdh-ajanlat-uzenet role="status"></span>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function ajax_eleje(): int
    {
        check_ajax_referer('sdh_muhely_modal', '_wpnonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        if (class_exists('SDH_Muhely_Munkalap') && ($zar = SDH_Muhely_Munkalap::zar_ellenoriz($id, 'árajánlat')) !== null) {
            wp_send_json_error(['uzenet' => $zar]);
        }

        return $id;
    }

    public static function ajax_kuld(): void
    {
        $id   = self::ajax_eleje();
        $hiba = self::kuld($id, class_exists('SDH_Muhely_Szemely') ? SDH_Muhely_Szemely::ki() : get_current_user_id());

        if ($hiba !== '') {
            wp_send_json_error(['uzenet' => $hiba]);
        }

        wp_send_json_success(['html' => self::urlap_blokk(self::lap($id)), 'uzenet' => 'Elküldve az ügyfélnek.']);
    }

    public static function ajax_visszavon(): void
    {
        $id   = self::ajax_eleje();
        $hiba = self::visszavon($id);

        if ($hiba !== '') {
            wp_send_json_error(['uzenet' => $hiba]);
        }

        wp_send_json_success(['html' => self::urlap_blokk(self::lap($id)), 'uzenet' => 'Visszavonva.']);
    }

    /* =================================================================
     * Ügyféloldal
     * ============================================================== */

    /** Az ügyféloldal ajánlat-doboza (az összegzés tetején). */
    public static function rma_doboz(object $l, bool $elonezet = false): string
    {
        $a = (string) ($l->ajanlat_allapot ?? '');

        if ($a === '') {
            return '';
        }

        $t   = static fn (string $k): string => SDH_Muhely_Rma_Oldal::t($k);
        $oss = SDH_Muhely_Rma_Oldal::penz((float) $l->ajanlat_osszeg);

        ob_start();
        ?>
        <div class="rma-csoport rma-ajanlat rma-ajanlat--<?php echo esc_attr($a); ?>" id="ajanlat">
            <h3 class="sdh-ml__cim"><?php echo esc_html($t('ajanlat_cim')); ?></h3>
            <div class="rma-ajanlat__osszeg"><span><?php echo esc_html($t('ajanlat_osszeg')); ?></span><b><?php echo esc_html($oss); ?></b></div>
            <?php if ($a === 'var') : ?>
                <p class="rma-halvany"><?php echo esc_html($t('ajanlat_szoveg')); ?></p>
                <form method="post" class="rma-ajanlat__urlap" action="<?php echo esc_url(SDH_Muhely_Rma::vissza_cim($l)); ?>">
                    <input type="hidden" name="sdh_rma_muvelet" value="ajanlat">
                    <input type="hidden" name="jel" value="<?php echo esc_attr(SDH_Muhely_Rma::urlap_jel($l)); ?>">
                    <?php if ($elonezet) : ?>
                        <input type="hidden" name="elonezet" value="1">
                    <?php endif; ?>
                    <label class="rma-ajanlat__cimke" for="rma-ajanlat-megj"><?php echo esc_html($t('ajanlat_megj')); ?></label>
                    <textarea id="rma-ajanlat-megj" name="megjegyzes" rows="2" maxlength="<?php echo (int) self::MEGJ_HOSSZ; ?>"></textarea>
                    <div class="rma-ajanlat__gombok">
                        <button type="submit" name="valasz" value="elfogad" class="sdh-gomb sdh-gomb--elsodleges"><?php echo esc_html($t('ajanlat_elfogad')); ?></button>
                        <button type="submit" name="valasz" value="elutasit" class="sdh-gomb sdh-gomb--vilagos"><?php echo esc_html($t('ajanlat_elutasit')); ?></button>
                    </div>
                </form>
            <?php else : ?>
                <p class="rma-ajanlat__valasz"><?php echo esc_html($t($a === 'elfogadva' ? 'ajanlat_elfogadva' : 'ajanlat_elutasitva') . ' · ' . SDH_Muhely_Rma_Oldal::ido((string) $l->ajanlat_valasz)); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
