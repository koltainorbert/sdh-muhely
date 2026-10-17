<?php
/**
 * GDPR: ügyfél anonimizálása törlési kérésre (H8, 0.82.0).
 *
 * Ügyfelet nem törlünk (a munkalapok, számlák, pénztártételek rá hivatkoznak, és a számviteli
 * bizonylatokat a törvény szerint meg kell őrizni). Törlési kérésre a SZEMÉLYES adatok tűnnek el:
 * név, kapcsolattartó, adószám, telefonok, e-mail, minden cím, a megjegyzések; a kereső szavai;
 * az ügyféloldal (RMA) linkjei; a készülékek feloldó kódja / mintája; a mezőmemóriában tárolt
 * értékei; választhatóan az RMA-üzenetek és SMS-ek szövege, az SMS-ek telefonszáma maszkolva.
 *
 * Ami MARAD: az ügyfél sora (azonosító, ügyfélszám, a MunkaLap 3 kulcsa – hogy egy újabb import
 * ne hozza vissza), a munkalapok, a számlák, a pénztár; a Változásnapló régi bejegyzései (a napló
 * nem írható át). Maga az anonimizálás mezőszintű naplózás nélkül fut (különben a napló újra
 * leírná a törölt adatokat); egyetlen esemény kerül a naplóba, személyes adat nélkül.
 *
 * Visszaigazolás: e-mail az ügyfélnek a régi címére (ha kéri), és az ügyfél lapján egy
 * igazoló sor (mikor, milyen kérésre, ki hajtotta végre).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Anonim
{
    public const NONCE  = 'sdh_muhely_anonim';
    public const JELZES = 'Anonimizálva (GDPR)';

    /** A kiürítendő ügyfélmezők (szöveg). */
    private const MEZOK = [
        'kapcsolattarto', 'adoszam', 'telefon', 'telefon2', 'email',
        'szamlazasi_iranyitoszam', 'szamlazasi_telepules', 'szamlazasi_cim',
        'levelezesi_iranyitoszam', 'levelezesi_telepules', 'levelezesi_cim',
        'szallitasi_iranyitoszam', 'szallitasi_telepules', 'szallitasi_cim',
        'telephely_nev', 'telephely_iranyitoszam', 'telephely_telepules', 'telephely_cim',
        'kereso', 'tel_ford', 'cim_rendez',
    ];

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_anonim_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_anonim_vegrehajt', [self::class, 'ajax_vegrehajt']);
    }

    private static function t(string $nev): string
    {
        global $wpdb;

        return $wpdb->prefix . 'sdh_' . $nev;
    }

    /** Jogosult-e: adminisztrátor, a „Törlés-szerű műveletek” joggal. */
    public static function jogos(): bool
    {
        return SDH_Muhely_Admin_UI::admin_e()
            && (!class_exists('SDH_Muhely_Szerepkor') || SDH_Muhely_Szerepkor::megvan('torles'));
    }

    public static function ugyfel(int $id): ?object
    {
        global $wpdb;

        $sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('ugyfel') . ' WHERE id = %d', $id));

        return $sor ?: null;
    }

    public static function anonim_e(?object $u): bool
    {
        return $u !== null && !empty($u->anonimizalva);
    }

    /** Mi kapcsolódik az ügyfélhez (a megerősítő ablakba): munkalap, számla, üzenet, SMS. @return array<string, int> */
    public static function kapcsolodo(int $id): array
    {
        global $wpdb;

        $db = static fn (string $tabla): int => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t($tabla) . ' WHERE ugyfel_id = %d', $id));

        return [
            'munkalap' => $db('munkalap'),
            'szamla'   => $db('szamla'),
            'uzenet'   => $db('uzenet'),
            'sms'      => $db('sms_uzenet'),
            'eszkoz'   => $db('eszkoz'),
        ];
    }

    /** Telefonszám maszkolva: csak az utolsó két számjegy marad. */
    public static function maszk(string $szam): string
    {
        $jegyek = (string) preg_replace('/\D/', '', $szam);

        return $jegyek === '' ? '' : '***' . substr($jegyek, -2);
    }

    /**
     * Az anonimizálás. Visszaad: [ok, üzenet, visszaigazolás elküldve].
     *
     * @param array{keres: string, uzenetek?: bool, visszaigazolas?: bool} $opciok
     * @return array{ok: bool, uzenet: string, email: bool}
     */
    public static function vegrehajt(int $id, array $opciok): array
    {
        global $wpdb;

        $u = self::ugyfel($id);

        if ($u === null) {
            return ['ok' => false, 'uzenet' => 'Nincs ilyen ügyfél.', 'email' => false];
        }

        if (self::anonim_e($u)) {
            return ['ok' => false, 'uzenet' => 'Ez az ügyfél már anonimizálva van.', 'email' => false];
        }

        $keres = trim(mb_substr(sanitize_text_field($opciok['keres'] ?? ''), 0, 200));

        if ($keres === '') {
            return ['ok' => false, 'uzenet' => 'Írd be, mikor és hogyan kérte az ügyfél a törlést.', 'email' => false];
        }

        $uzenetek  = !empty($opciok['uzenetek']);
        $regi      = (array) $u;
        $most      = current_time('mysql');
        $ki        = class_exists('SDH_Muhely_Szemely') ? SDH_Muhely_Szemely::nev() : wp_get_current_user()->display_name;
        $szam      = (string) ($u->ugyfel_szam !== '' ? $u->ugyfel_szam : '#' . $id);

        // A visszaigazolás még a régi címre megy (utána a cím már nincs meg).
        $email = false;

        if (!empty($opciok['visszaigazolas']) && is_email((string) $u->email)) {
            $email = self::visszaigazolas((string) $u->email, $keres);
        }

        $igazolas = self::JELZES . ': ' . wp_date('Y. m. d. H:i', strtotime($most) ?: time())
            . ' – kérés: ' . $keres . ' – végrehajtotta: ' . $ki
            . ($email ? ' – visszaigazolás e-mailben elküldve' : '');

        // A régi értékek a mezőmemóriából is kimennek (név, telefon, e-mail, cím).
        $regi_ertekek = array_values(array_filter(array_map(
            static fn ($k): string => trim((string) ($regi[$k] ?? '')),
            ['nev', 'kapcsolattarto', 'telefon', 'telefon2', 'email', 'szamlazasi_cim', 'levelezesi_cim', 'szallitasi_cim', 'telephely_cim', 'adoszam']
        ), static fn (string $e): bool => mb_strlen($e) >= 3));

        SDH_Muhely_Valtozasnaplo::csendben(static function () use ($wpdb, $id, $szam, $igazolas, $most, $uzenetek, $regi_ertekek): void {
            $adat = array_fill_keys(self::MEZOK, '');
            $adat += [
                'nev'              => 'Anonimizált ügyfél (' . $szam . ')',
                'megjegyzes'       => null,
                'belso_megjegyzes' => $igazolas,
                'aktiv'            => 0,
                'sms_tilt'         => 1,
                'anonimizalva'     => $most,
                'modositva'        => $most,
            ];

            $wpdb->update(self::t('ugyfel'), $adat, ['id' => $id]);
            $wpdb->delete(self::t('ugyfel_szo'), ['ugyfel_id' => $id]);

            // Az ügyféloldal (RMA) linkjei megszűnnek.
            $wpdb->update(self::t('munkalap'), ['rma_token' => ''], ['ugyfel_id' => $id]);

            // A készülékek feloldó kódja és mintája.
            $wpdb->update(self::t('eszkoz'), ['zarkod' => '', 'minta' => ''], ['ugyfel_id' => $id]);

            // SMS: a szám maszkolva mindig; a szöveg és az RMA-üzenetek szövege, ha kérték.
            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, szam, szam_eredeti FROM ' . self::t('sms_uzenet') . ' WHERE ugyfel_id = %d', $id)) as $sms) {
                $wpdb->update(
                    self::t('sms_uzenet'),
                    ['szam' => self::maszk((string) $sms->szam), 'szam_eredeti' => self::maszk((string) $sms->szam_eredeti), 'kovetes_token' => '']
                        + ($uzenetek ? ['szoveg' => '(anonimizálva)'] : []),
                    ['id' => (int) $sms->id]
                );
            }

            if ($uzenetek) {
                $wpdb->update(self::t('uzenet'), ['szoveg' => '(anonimizálva)'], ['ugyfel_id' => $id]);
            }

            // 0.95.0 (H8b): az ügyfélhez kötött naptárbejegyzések (pl. online foglalás) címe, leírása és elérhetősége.
            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id FROM ' . self::t('naptar_esemeny') . ' WHERE ugyfel_id = %d', $id)) as $np) {
                $wpdb->update(self::t('naptar_esemeny'), [
                    'cim'             => 'Anonimizált ügyfél (' . $szam . ')',
                    'leiras'          => '(anonimizálva)',
                    'foglalo_email'   => '',
                    'foglalo_telefon' => '',
                ], ['id' => (int) $np->id]);
            }

            // Az elégedettségi megjegyzés (az ügyfél saját szövege) a munkalapjain.
            $wpdb->query($wpdb->prepare('UPDATE ' . self::t('munkalap') . " SET elegedettseg_szoveg = '' WHERE ugyfel_id = %d AND elegedettseg_szoveg <> ''", $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            if ($regi_ertekek !== []) {
                $hely = implode(',', array_fill(0, count($regi_ertekek), '%s'));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('mezo_emlek') . " WHERE ertek IN ({$hely})", ...$regi_ertekek)); // phpcs:ignore WordPress.DB.PreparedSQL
            }
        });

        if (class_exists('SDH_Muhely_Ugyfel')) {
            SDH_Muhely_Ugyfel::kereso_frissit($id);
        }

        if (class_exists('SDH_Muhely_Gyorsitotar') && method_exists('SDH_Muhely_Gyorsitotar', 'ervenytelenit')) {
            SDH_Muhely_Gyorsitotar::ervenytelenit();
        }

        // Egyetlen naplósor, személyes adat nélkül.
        SDH_Muhely_Valtozasnaplo::esemeny('ugyfel', $id, 'Ügyfél anonimizálva (GDPR) – ' . $szam . ' – kérés: ' . $keres
            . ($uzenetek ? ' – az üzenetek szövege is' : '') . ($email ? ' – visszaigazolás elküldve' : ''));

        return ['ok' => true, 'uzenet' => 'Az ügyfél személyes adatai törölve.', 'email' => $email];
    }

    /** A visszaigazoló levél (a cég neve az ügyféloldal beállításából – nincs a kódban). */
    public static function visszaigazolas(string $cim, string $keres): bool
    {
        $ceg = class_exists('SDH_Muhely_Rma') ? (string) (SDH_Muhely_Rma::beallitas()['szerviz_nev'] ?? '') : '';
        $ceg = $ceg !== '' ? $ceg : (string) get_bloginfo('name');

        $szoveg = "Tisztelt Ügyfelünk!\n\n"
            . 'Kérésére (' . $keres . ') a mai napon nyilvántartásunkból töröltük a személyes adatait: nevét, telefonszámát, '
            . "e-mail címét, címeit és a hozzájuk tartozó megjegyzéseket. A javításokról kiállított számlákat és bizonylatokat a számviteli "
            . "jogszabályok miatt a megőrzési idő végéig meg kell őriznünk.\n\n"
            . "Erre a levélre ezért ez az utolsó e-mail, amit a címére küldünk.\n\n"
            . "Üdvözlettel:\n" . $ceg . "\n";

        return (bool) wp_mail($cim, 'Személyes adatai törlése – visszaigazolás', $szoveg);
    }

    /* =================================================================
     * Felület
     * ============================================================== */

    /** Az ügyfél űrlapjának gombja (csak jogosultnak, és csak ha még nincs anonimizálva). */
    public static function gomb(object $u): string
    {
        if (!self::jogos() || self::anonim_e($u)) {
            return '';
        }

        return '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-urlap="anonim" data-sdh-id="' . (int) $u->id . '">Anonimizálás (GDPR)</button>';
    }

    /** Az anonimizált ügyfél lapjának sávja. */
    public static function sav(?object $u): string
    {
        if (!self::anonim_e($u)) {
            return '';
        }

        return '<div class="sdh-uzenet sdh-uzenet--figyelem sdh-anonim-sav">' . esc_html((string) $u->belso_megjegyzes) . '</div>';
    }

    public static function ajax_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal', '_wpnonce');

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $u  = self::ugyfel($id);

        echo '<h2 class="sdh-modal__cim">Ügyfél anonimizálása (GDPR)</h2>';

        if (!self::jogos()) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Ehhez adminisztrátori jog és a „Törlés-szerű műveletek” jog kell.</div>';
            wp_die();
        }

        if ($u === null || self::anonim_e($u)) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">' . ($u === null ? 'Nincs ilyen ügyfél.' : 'Ez az ügyfél már anonimizálva van.') . '</div>';
            wp_die();
        }

        $k = self::kapcsolodo($id);
        ?>
        <form class="sdh-urlap sdh-anonim" data-sdh-ajax-action="sdh_muhely_anonim_vegrehajt">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <input type="hidden" name="kontextus" value="<?php echo esc_attr(isset($_GET['kontextus']) && sanitize_key(wp_unslash($_GET['kontextus'])) === 'frontend' ? 'frontend' : 'admin'); ?>">
            <?php wp_nonce_field(self::NONCE, 'sdh_nonce'); ?>

            <p class="sdh-sugo">
                <strong><?php echo esc_html((string) $u->nev); ?></strong> (<?php echo esc_html((string) $u->ugyfel_szam); ?>) személyes adatai
                <strong>véglegesen</strong> törlődnek. Ez nem vonható vissza.
            </p>

            <div class="sdh-anonim__ket">
                <div>
                    <h3 class="sdh-anonim__cim">Törlődik</h3>
                    <ul class="sdh-anonim__lista">
                        <li>név, kapcsolattartó, adószám</li>
                        <li>telefonszámok, e-mail cím</li>
                        <li>minden cím (számlázási, levelezési, szállítási, telephely)</li>
                        <li>megjegyzések, a kereső szavai</li>
                        <li>az ügyféloldal (QR-kód) linkjei</li>
                        <li>a készülékek feloldó kódja és mintája (<?php echo (int) $k['eszkoz']; ?> készülék)</li>
                        <li>az SMS-ek telefonszáma (maszkolva)</li>
                    </ul>
                </div>
                <div>
                    <h3 class="sdh-anonim__cim">Megmarad</h3>
                    <ul class="sdh-anonim__lista">
                        <li><?php echo (int) $k['munkalap']; ?> munkalap (a javítások, árak, tételek)</li>
                        <li><?php echo (int) $k['szamla']; ?> számla és a pénztártételek – a számviteli megőrzés miatt</li>
                        <li>a készülékek (típus, IMEI) – a garancia miatt</li>
                        <li>a Változásnapló régi bejegyzései (a napló nem írható át)</li>
                    </ul>
                </div>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="anonim_keres">A kérés: mikor és hogyan érkezett *</label>
                    <input type="text" id="anonim_keres" name="keres" maxlength="200" required placeholder="pl. e-mailben, 2026. 10. 16.">
                </div>
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" id="anonim_uzenetek" name="uzenetek" value="1" checked>
                <label for="anonim_uzenetek">Az ügyféllel váltott üzenetek szövege is (<?php echo (int) $k['uzenet']; ?> RMA-üzenet, <?php echo (int) $k['sms']; ?> SMS)</label>
            </div>

            <?php if (is_email((string) $u->email)) : ?>
                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" id="anonim_visszaigazolas" name="visszaigazolas" value="1" checked>
                    <label for="anonim_visszaigazolas">Visszaigazolás e-mailben az ügyfélnek: <?php echo esc_html((string) $u->email); ?></label>
                </div>
            <?php else : ?>
                <p class="sdh-mezo__sugo">Nincs e-mail címe: a visszaigazolást más úton add meg (pl. szóban, levélben). Az igazolás az ügyfél lapján megmarad.</p>
            <?php endif; ?>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" id="anonim_ertem" name="ertem" value="1" required>
                <label for="anonim_ertem">Megértettem: a személyes adatok véglegesen törlődnek.</label>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Anonimizálás</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            </div>
        </form>
        <?php
        wp_die();
    }

    public static function ajax_vegrehajt(): void
    {
        check_ajax_referer(self::NONCE, 'sdh_nonce');

        if (!self::jogos()) {
            wp_send_json_error(['uzenet' => 'Ehhez adminisztrátori jog és a „Törlés-szerű műveletek” jog kell.'], 403);
        }

        if (empty($_POST['ertem'])) {
            wp_send_json_error(['uzenet' => 'Pipáld be, hogy megértetted: nem vonható vissza.']);
        }

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $r  = self::vegrehajt($id, [
            'keres'          => isset($_POST['keres']) ? (string) wp_unslash($_POST['keres']) : '',
            'uzenetek'       => !empty($_POST['uzenetek']),
            'visszaigazolas' => !empty($_POST['visszaigazolas']),
        ]);

        if (!$r['ok']) {
            wp_send_json_error(['uzenet' => $r['uzenet']]);
        }

        $kontextus = isset($_POST['kontextus']) && sanitize_key(wp_unslash($_POST['kontextus'])) === 'frontend' ? 'frontend' : 'admin';

        wp_send_json_success([
            'uzenet' => $r['uzenet'],
            'email'  => $r['email'],
            'vissza' => SDH_Muhely_Modulok::visszateres('ugyfelek', ['uzenet' => 'anonimizalva'], $kontextus),
        ]);
    }
}
