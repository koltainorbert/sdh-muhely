<?php
/**
 * Átvételi fotók és aláírás (F3, 0.85.0).
 *
 * A javítási munkalap új „Átvétel” lapfüle:
 * - fotók a készülék állapotáról átvételkor (a csatolmány-tárolóban, `munkalap` típusként; telefonon /
 *   tableten a kamera közvetlenül nyílik);
 * - az ügyfél aláírása érintőképernyőn, ujjal vagy egérrel (PNG, `munkalap_alairas` csatolmányként) +
 *   az aláíró neve és ideje a lapon.
 * Az átvételi nyomtatványon az aláírás az „Átadó (ügyfél)” vonal fölött áll, a fotók (legfeljebb 4)
 * bélyegképként a lap alján.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Atvetel
{
    public const ALAIRAS_MAX = 400 * 1024;   // a PNG legfeljebb 400 kB
    public const FOTO_PDF    = 4;            // ennyi fotó kerül a nyomtatványra

    public static function init(): void
    {
        add_action('sdh_muhely_munkalap_mentve', [self::class, 'mentes_utan'], 10, 2);
    }

    /* =================================================================
     * Mentés
     * ============================================================== */

    /** Mentés ELŐTT: a feltöltött fotók és az aláírás rendben van-e (hibánál a lap sem mentődik). */
    public static function ellenoriz(): ?string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a munkalap mentése ellenőrizte.
        if (!empty($_FILES['csatolmany']['name']) && class_exists('SDH_Muhely_Csatolmany')) {
            $hiba = SDH_Muhely_Csatolmany::ellenoriz();

            if ($hiba !== null) {
                return $hiba;
            }
        }

        $alairas = isset($_POST['atvetel_alairas']) ? (string) wp_unslash($_POST['atvetel_alairas']) : '';
        // phpcs:enable

        if ($alairas !== '' && self::alairas_bajtok($alairas) === null) {
            return 'Az aláírás képe nem olvasható (vagy túl nagy). Töröld, és írd alá újra.';
        }

        return null;
    }

    /** data:image/png;base64,… → a PNG bájtjai, vagy null, ha nem érvényes. */
    public static function alairas_bajtok(string $data_url): ?string
    {
        if (strncmp($data_url, 'data:image/png;base64,', 22) !== 0) {
            return null;
        }

        $bajtok = base64_decode(substr($data_url, 22), true);

        if (!is_string($bajtok) || $bajtok === '' || strlen($bajtok) > self::ALAIRAS_MAX || strncmp($bajtok, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            return null;
        }

        $meret = function_exists('getimagesizefromstring') ? @getimagesizefromstring($bajtok) : [1, 1]; // phpcs:ignore WordPress.PHP.NoSilencedErrors

        return is_array($meret) && (int) $meret[0] > 0 && (int) $meret[1] > 0 && (int) $meret[0] <= 4000 && (int) $meret[1] <= 2000 ? $bajtok : null;
    }

    /** A lap mentése UTÁN (sdh_muhely_munkalap_mentve): fotók, aláírás. */
    public static function mentes_utan(int $id, $regi = null): void
    {
        global $wpdb;

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if (!isset($_POST['atvetel_jelen']) || !class_exists('SDH_Muhely_Csatolmany')) {
            return;
        }

        SDH_Muhely_Csatolmany::feldolgoz('munkalap', $id);

        $tabla   = SDH_Muhely_Schema::tabla('munkalap');
        $alairas = isset($_POST['atvetel_alairas']) ? (string) wp_unslash($_POST['atvetel_alairas']) : '';
        $nev     = isset($_POST['atvetel_alairo']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['atvetel_alairo'])), 0, 120) : '';

        if (!empty($_POST['atvetel_alairas_torol'])) {
            SDH_Muhely_Csatolmany::torol_mind('munkalap_alairas', $id);
            $wpdb->update($tabla, ['atvetel_alairva' => null, 'atvetel_alairo' => ''], ['id' => $id]);
            SDH_Muhely_Valtozasnaplo::esemeny('munkalap', $id, 'Átvételi aláírás törölve');
        }

        $bajtok = $alairas !== '' ? self::alairas_bajtok($alairas) : null;

        if ($bajtok !== null) {
            SDH_Muhely_Csatolmany::torol_mind('munkalap_alairas', $id);
            SDH_Muhely_Csatolmany::tarol_bajt('munkalap_alairas', $id, 'atveteli-alairas.png', 'image/png', $bajtok);
            $wpdb->update($tabla, ['atvetel_alairva' => current_time('mysql'), 'atvetel_alairo' => $nev], ['id' => $id]);
            SDH_Muhely_Valtozasnaplo::esemeny('munkalap', $id, 'Átvételi aláírás rögzítve' . ($nev !== '' ? ': ' . $nev : ''));
        } elseif ($nev !== '' && self::alairas($id) !== null) {
            $wpdb->update($tabla, ['atvetel_alairo' => $nev], ['id' => $id]);
        }
        // phpcs:enable
    }

    /* =================================================================
     * Lekérdezés
     * ============================================================== */

    public static function alairas(int $munkalap_id): ?object
    {
        if ($munkalap_id < 1 || !class_exists('SDH_Muhely_Csatolmany')) {
            return null;
        }

        $l = SDH_Muhely_Csatolmany::lista('munkalap_alairas', $munkalap_id);

        return $l[0] ?? null;
    }

    /** @return array<int, object> a lap képei (fotók) */
    public static function fotok(int $munkalap_id): array
    {
        if ($munkalap_id < 1 || !class_exists('SDH_Muhely_Csatolmany')) {
            return [];
        }

        return array_values(array_filter(
            SDH_Muhely_Csatolmany::lista('munkalap', $munkalap_id),
            static fn (object $s): bool => in_array((string) $s->mime, ['image/jpeg', 'image/png'], true)
        ));
    }

    /* =================================================================
     * Munkalap: az „Átvétel” lapfül
     * ============================================================== */

    public static function ful(?object $l): void
    {
        $id      = $l !== null ? (int) $l->id : 0;
        $alairas = self::alairas($id);
        ?>
        <input type="hidden" name="atvetel_jelen" value="1">
        <div class="sdh-atvetel">
            <section class="sdh-atvetel__resz">
                <h4 class="sdh-atvetel__cim">Fotók a készülék állapotáról</h4>
                <p class="sdh-mezo__sugo">Átvételkor fényképezd le a készüléket (karcok, repedések, sérülések). Telefonon és tableten a <strong>Fotó készítése</strong> gomb a kamerát nyitja. A fotók a lap mentésekor kerülnek fel.</p>
                <label class="sdh-gomb sdh-gomb--vilagos sdh-atvetel__kamera">
                    <input type="file" name="csatolmany[]" accept="image/*" capture="environment" multiple data-sdh-atvetel-kamera hidden>
                    Fotó készítése
                </label>
                <span class="sdh-mezo__sugo" data-sdh-atvetel-kamera-db></span>
                <?php if (class_exists('SDH_Muhely_Csatolmany')) { SDH_Muhely_Csatolmany::panel('munkalap', $id); } ?>
            </section>

            <section class="sdh-atvetel__resz" data-sdh-alairas>
                <h4 class="sdh-atvetel__cim">Az ügyfél aláírása</h4>
                <?php if ($alairas !== null) : ?>
                    <div class="sdh-atvetel__meglevo" data-sdh-alairas-meglevo>
                        <img src="<?php echo esc_url(SDH_Muhely_Csatolmany::url((int) $alairas->id, 'megnyit')); ?>" alt="Az ügyfél aláírása">
                        <span class="sdh-mezo__sugo">
                            Aláírta<?php echo (string) ($l->atvetel_alairo ?? '') !== '' ? ': <strong>' . esc_html((string) $l->atvetel_alairo) . '</strong>' : ''; ?>
                            <?php echo !empty($l->atvetel_alairva) ? ' – ' . esc_html(mysql2date('Y.m.d. H:i', (string) $l->atvetel_alairva)) : ''; ?>
                        </span>
                        <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-alairas-uj>Új aláírás</button>
                        <input type="hidden" name="atvetel_alairas_torol" value="" data-sdh-alairas-torol-mezo>
                    </div>
                <?php endif; ?>
                <div class="sdh-atvetel__pad"<?php echo $alairas !== null ? ' hidden' : ''; ?> data-sdh-alairas-pad>
                    <canvas class="sdh-atvetel__vaszon" data-sdh-alairas-vaszon aria-label="Aláírás helye: írj alá ujjal vagy egérrel"></canvas>
                    <div class="sdh-atvetel__lab">
                        <span class="sdh-mezo__sugo">Írj alá ujjal, tollal vagy egérrel a keretben.</span>
                        <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-alairas-torol>Törlés</button>
                    </div>
                    <input type="hidden" name="atvetel_alairas" value="" data-sdh-alairas-mezo>
                </div>
                <div class="sdh-mezo">
                    <label for="atvetel_alairo">Az aláíró neve</label>
                    <input type="text" id="atvetel_alairo" name="atvetel_alairo" maxlength="120" autocomplete="off"
                           value="<?php echo esc_attr((string) ($l->atvetel_alairo ?? '')); ?>" placeholder="pl. az ügyfél vagy a meghatalmazottja">
                </div>
                <p class="sdh-mezo__sugo">Az aláírás a lap mentésekor kerül el, és az átvételi nyomtatványon az „Átadó (ügyfél)” vonal fölött áll.</p>
            </section>
        </div>
        <?php
    }

    /** A lapfül feliratának darabszáma: fotók + aláírás. */
    public static function darab(int $id): int
    {
        if ($id < 1 || !class_exists('SDH_Muhely_Csatolmany')) {
            return 0;
        }

        return SDH_Muhely_Csatolmany::darab('munkalap', $id) + (self::alairas($id) !== null ? 1 : 0);
    }

    /* =================================================================
     * Nyomtatvány
     * ============================================================== */

    /** @return array{alairas: string, fotok: array<int, string>} a fájlok útjai a PDF-hez */
    public static function pdf_adat(int $munkalap_id): array
    {
        $a = self::alairas($munkalap_id);

        return [
            'alairas' => $a !== null ? SDH_Muhely_Csatolmany::fajl_ut($a) : '',
            'fotok'   => array_values(array_filter(array_map(
                static fn (object $f): string => SDH_Muhely_Csatolmany::fajl_ut($f) . '|' . ($f->mime === 'image/png' ? 'PNG' : 'JPG'),
                array_slice(self::fotok($munkalap_id), 0, self::FOTO_PDF)
            ), static fn (string $s): bool => $s[0] !== '|')),
        ];
    }
}
