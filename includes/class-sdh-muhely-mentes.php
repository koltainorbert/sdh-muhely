<?php
/**
 * Független napi mentés (0.52).
 *
 * Eddig teljes mentés csak akkor készült, amikor az asszisztens aznap
 * először módosított valamit. Mostantól minden nap készül egy – akkor is,
 * ha az asszisztenst senki nem használja:
 *
 *   1. a WordPress időzítője naponta elindítja (ha a szerver éjjel is megy);
 *   2. ha ez kimaradt (a gép éjjel ki volt kapcsolva, vagy a WP-cron tiltva
 *      van), a nap első bejelentkezett oldalbetöltése UTÁN készül el – a
 *      válasz kiküldése után, tehát a felületet nem lassítja.
 *
 * A mentés maga változatlan (SDH_Muhely_Asszisztens_Tudas::teljes_mentes):
 * minden saját tábla és beállítás, gzip-pelt JSON-sorokban, a feltöltési
 * mappa kívülről nem olvasható almappájában.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Mentes
{
    private const OPTION = 'sdh_muhely_mentes';
    private const CRON   = 'sdh_muhely_napi_mentes';
    private const ZAR    = 'sdh_muhely_mentes_fut';

    public static function init(): void
    {
        add_action('init', [self::class, 'cron_beallit']);
        add_action(self::CRON, [self::class, 'idozitett']);
        add_action(self::CRON . '_most', [self::class, 'idozitett']);
        add_action('shutdown', [self::class, 'potlas'], 99);
        add_action('wp_ajax_sdh_muhely_mentes_most', [self::class, 'ajax_most']);
    }

    /** @return array{aktiv: bool, megtart: int} */
    public static function beallitas(): array
    {
        $b = get_option(self::OPTION, []);
        $b = is_array($b) ? $b : [];

        return [
            'aktiv'   => !isset($b['aktiv']) || !empty($b['aktiv']),
            'megtart' => min(365, max(3, (int) ($b['megtart'] ?? 20))),
        ];
    }

    /** Hány mentés marad meg (a régebbiek törlődnek). */
    public static function megtartas(): int
    {
        return self::beallitas()['megtart'];
    }

    private static function motor(): bool
    {
        return class_exists('SDH_Muhely_Asszisztens_Tudas');
    }

    /** A legutóbbi mentés időpontja (0 = még nincs). */
    public static function utolso(): int
    {
        if (!self::motor()) {
            return 0;
        }

        $lista = SDH_Muhely_Asszisztens_Tudas::mentesek();

        return $lista !== [] ? (int) $lista[0]['ido'] : 0;
    }

    public static function ma_megvan(): bool
    {
        $utolso = self::utolso();

        return $utolso > 0 && wp_date('Y-m-d', $utolso) === current_time('Y-m-d');
    }

    public static function cron_beallit(): void
    {
        $aktiv = self::beallitas()['aktiv'];
        $kov   = wp_next_scheduled(self::CRON);

        if ($aktiv && !$kov) {
            wp_schedule_event(time() + 300, 'daily', self::CRON);
        } elseif (!$aktiv && $kov) {
            wp_unschedule_event($kov, self::CRON);
        }
    }

    public static function idozitett(): void
    {
        self::keszit();
    }

    /**
     * A mai mentés elkészítése, ha még nincs. Egyszerre csak egy fut (zár).
     *
     * @return array{fajl: string, meret: int, sorok: int}|string|null null = nem kellett
     */
    public static function keszit(bool $akkor_is = false)
    {
        if (!self::motor() || (!$akkor_is && (!self::beallitas()['aktiv'] || self::ma_megvan()))) {
            return null;
        }

        if (!self::zar_fog()) {
            return null;
        }

        try {
            $r = SDH_Muhely_Asszisztens_Tudas::teljes_mentes($akkor_is ? 'kezi' : 'napi');
        } catch (Throwable $hiba) {
            $r = 'A mentés megszakadt: ' . $hiba->getMessage();
        }

        delete_option(self::ZAR);
        update_option('sdh_muhely_mentes_utolso', ['ido' => current_time('mysql'), 'hiba' => is_string($r) ? $r : ''], false);

        return $r;
    }

    /**
     * Egyszerre csak egy mentés fut. Az add_option az adatbázis egyedi kulcsán múlik, ezért két
     * egyidejű kérés közül csak az egyik kapja meg a zárat. A 15 percnél régebbi zár beragadt
     * (megszakadt kérés) – azt elengedjük.
     */
    private static function zar_fog(): bool
    {
        if (add_option(self::ZAR, (string) time(), '', false)) {
            return true;
        }

        $mikor = (int) get_option(self::ZAR, 0);

        if ($mikor > 0 && $mikor < time() - 15 * MINUTE_IN_SECONDS) {
            delete_option(self::ZAR);

            return add_option(self::ZAR, (string) time(), '', false);
        }

        return false;
    }

    /** A nap első bejelentkezett kérése után: ha ma még nincs mentés, most készül. */
    public static function potlas(): void
    {
        if (!is_user_logged_in() || !self::motor() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }

        if (!class_exists('SDH_Muhely_Admin_UI') || !current_user_can(SDH_Muhely_Admin_UI::jog())) {
            return;
        }

        // Olcsó előszűrő: naponta egyszer nézzük meg a fájlokat, nem minden kérésnél.
        if (get_option('sdh_muhely_mentes_nap') === current_time('Y-m-d') || !self::beallitas()['aktiv']) {
            return;
        }

        if (get_option('sdh_muhely_db_version') === false) {
            return;
        }

        // Ha az előbb nem sikerült (megtelt lemez, nem írható mappa), egy óráig nem próbáljuk újra minden kérésnél.
        if (get_transient(self::ZAR . '_hiba')) {
            return;
        }

        ignore_user_abort(true);

        // Ahol a válasz a mentés előtt lezárható (PHP-FPM, LiteSpeed), ott a böngésző nem vár rá.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
            // Másutt (pl. Apache mod_php) a kérés megvárná a mentést: inkább külön, háttérkérésben fut le.
            if (!wp_next_scheduled(self::CRON . '_most')) {
                wp_schedule_single_event(time() - 1, self::CRON . '_most');
            }

            spawn_cron();
            // Ma többet nem próbálkozunk innen; ha a háttérkérés elmaradna, a napi időzítő pótolja.
            set_transient(self::ZAR . '_hiba', 1, HOUR_IN_SECONDS);

            return;
        }

        $r = self::keszit();

        if (is_string($r)) {
            set_transient(self::ZAR . '_hiba', 1, HOUR_IN_SECONDS);

            return;
        }

        if (self::ma_megvan()) {
            update_option('sdh_muhely_mentes_nap', current_time('Y-m-d'), false);
        }
    }

    public static function ajax_most(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!class_exists('SDH_Muhely_Admin_UI') || !SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ehhez adminisztrátori jog kell.'], 403);
        }

        $r = self::keszit(true);

        if ($r === null) {
            wp_send_json_error(['uzenet' => 'Éppen fut egy mentés – próbáld újra egy perc múlva.']);
        }

        if (is_string($r)) {
            wp_send_json_error(['uzenet' => $r]);
        }

        wp_send_json_success(['uzenet' => 'Mentés kész: ' . $r['fajl'] . ' (' . size_format($r['meret']) . ', ' . $r['sorok'] . ' sor).']);
    }

    /* =================================================================
     * Beállítások › Mentés
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $b      = self::beallitas();
        $lista  = self::motor() ? SDH_Muhely_Asszisztens_Tudas::mentesek() : [];
        $nonce  = wp_create_nonce('sdh_muhely_modal');
        $utolso = get_option('sdh_muhely_mentes_utolso', []);

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Napi automatikus mentés</h2>

            <p class="sdh-sugo">
                Minden nap készül egy teljes mentés a rendszer összes saját táblájáról és beállításáról
                (a TAC-adatbázis és a levél-gyorsítótár kivételével – azok újra előállíthatók). Ha a gép éjjel
                ki van kapcsolva, a mentés a nap első bejelentkezése után készül el, a háttérben.
                A kulcsok és jelszavak a mentésben is titkosítva állnak.
            </p>

            <?php if (is_array($utolso) && !empty($utolso['hiba'])) : ?>
                <div class="sdh-uzenet sdh-uzenet--hiba">A legutóbbi mentés nem sikerült (<?php echo esc_html((string) ($utolso['ido'] ?? '')); ?>): <?php echo esc_html((string) $utolso['hiba']); ?></div>
            <?php endif; ?>

            <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--also-ter">
                <input type="hidden" name="mentes_jelen" value="1">
                <input type="checkbox" name="mentes_aktiv" id="mentes_aktiv" value="1" <?php checked($b['aktiv']); ?>>
                <label for="mentes_aktiv">Napi mentés bekapcsolva</label>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="mentes_megtart">Megőrzött mentések száma</label>
                    <input type="number" name="mentes_megtart" id="mentes_megtart" min="3" max="365" step="1" value="<?php echo (int) $b['megtart']; ?>">
                    <span class="sdh-mezo__sugo">A legrégebbiek törlődnek. Napi egy mentésnél a 20 nagyjából három hét.</span>
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Mentések</h2>

            <p class="sdh-sugo">
                A mentések a szerveren maradnak – ha a gép lemeze meghibásodik, velük együtt vesznek el.
                Időnként tölts le egyet, és tedd másik eszközre (pendrive, felhő).
            </p>

            <p>
                <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-mentes-most
                        data-nonce="<?php echo esc_attr($nonce); ?>">Mentés most</button>
                <span class="sdh-sugo" data-sdh-mentes-uzenet aria-live="polite"></span>
            </p>

            <?php if ($lista === []) : ?>
                <p class="sdh-sugo sdh-sugo--utolso">Még nincs mentés.</p>
            <?php else : ?>
                <ul class="sdh-ai-mentesek">
                    <?php foreach (array_slice($lista, 0, 12) as $m) : ?>
                        <li>
                            <span><?php echo esc_html(wp_date('Y. m. d. H:i', $m['ido'])); ?></span>
                            <code><?php echo esc_html($m['fajl']); ?></code>
                            <span><?php echo esc_html(size_format($m['meret'])); ?></span>
                            <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(add_query_arg(['action' => 'sdh_muhely_ai_mentes_le', 'fajl' => $m['fajl'], '_wpnonce' => $nonce], admin_url('admin-ajax.php'))); ?>">Letöltés</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($lista) > 12) : ?>
                    <p class="sdh-sugo sdh-sugo--utolso">+<?php echo count($lista) - 12; ?> régebbi mentés a szerveren.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['mentes_jelen'])) {
            return;
        }

        update_option(self::OPTION, [
            'aktiv'   => !empty($_POST['mentes_aktiv']),
            'megtart' => min(365, max(3, isset($_POST['mentes_megtart']) ? (int) $_POST['mentes_megtart'] : 20)),
        ], false);
        // phpcs:enable

        delete_option('sdh_muhely_mentes_nap');
    }
}
