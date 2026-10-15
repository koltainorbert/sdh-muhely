<?php
/**
 * Mezőmemória és a Leggyakoribb Számla tételek listája (0.63.0).
 *
 * MEZŐMEMÓRIA: minden szövegmező megjegyzi, mit írtak bele vagy mire
 * kerestek, és legközelebb gépelés közben felajánlja (assets/emlek.js) –
 * ugyanúgy, ahogy a munkalap az ügyfél nevét. A memória közös (a csapat
 * egy fiókból is dolgozik), a mezőnként legtöbbször / legutóbb használt
 * értékek állnak elöl. Ami saját listát kínál (ügyfél-, szolgáltatás-,
 * termékválasztó, irányítószám, címzett), oda nem lép be.
 *
 * Soha nem kerül a memóriába: jelszó, kulcs, token, kód (feloldó kód,
 * zárkód, minta), kártya- és bankszámlaszám, és bármilyen 13–19 jegyű
 * csupaszám (kártyaszám lehet). Ezt a böngésző ÉS a szerver is szűri.
 *
 * LEGGYAKORIBB SZÁMLA TÉTELEK: a számla ablakában (Számlázz.hu és
 * számla-előkészítő) egy kattintással felvehető tételnevek listája,
 * ugyanott popupban szerkeszthető. A kézi tétel megnevezésénél gépelés
 * közben ezek és a korábban kézzel írt tételnevek jönnek fel.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Emlek
{
    public const OPT        = 'sdh_muhely_emlek';
    public const OPT_GYAKORI = 'sdh_muhely_szamla_gyakori';

    /** A számla kézi tételének mezőkulcsa: a tételnevek közös memóriája. */
    public const TETELNEV = 'tetelnev';

    /** A munkalapok tételneveinek (gyakoriság szerint) tárolt listája – 10 percig él (0.64). */
    public const TETELNEV_TAR = 'emlek_tetelnevek';

    /** Mezőnként legfeljebb ennyi érték marad meg (a legritkábban használtak mennek el). */
    private const MAX_KULCSONKENT = 300;

    /** Egy kérésre ennyi értéket küld a böngészőnek (az helyben szűr gépelés közben). */
    private const MAX_KULD = 80;

    /** Ilyen nevű / feliratú mező soha nem kerül a memóriába. */
    public const TITKOS = '/(jelsz|passw|kulcs|token|secret|titok|(^|[^a-z])api|(^|[^a-z])pin([^a-z]|$)|kod([^a-z]|$)|kód|minta|cvv|cvc|kartya|kártya|bankszamla|bankszámla|iban|szemelyi|személyi|adoazon|tajszam|tajszám)/iu';

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_emlek_ker', [self::class, 'ajax_ker']);
        add_action('wp_ajax_sdh_muhely_emlek_ment', [self::class, 'ajax_ment']);
        add_action('wp_ajax_sdh_muhely_emlek_torol', [self::class, 'ajax_torol']);
        add_action('wp_ajax_sdh_muhely_szamla_gyakori_ment', [self::class, 'ajax_gyakori_ment']);
    }

    private static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('mezo_emlek');
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /** Be van-e kapcsolva a mezőmemória (alap: igen). */
    public static function be(): bool
    {
        $m = get_option(self::OPT, []);

        return !is_array($m) || !isset($m['be']) || (bool) $m['be'];
    }

    /** A böngészőnek (window.SDH_MUHELY.emlek). */
    public static function js(): array
    {
        return ['be' => self::be(), 'titkos' => self::TITKOS_JS];
    }

    /** Ugyanaz a szűrő a böngészőnek (JS-regex forrás, i jelzővel). */
    public const TITKOS_JS = '(jelsz|passw|kulcs|token|secret|titok|(^|[^a-z])api|(^|[^a-z])pin([^a-z]|$)|kod([^a-z]|$)|kód|minta|cvv|cvc|kartya|kártya|bankszamla|bankszámla|iban|szemelyi|személyi|adoazon|tajszam|tajszám)';

    /* =================================================================
     * Leggyakoribb Számla tételek listája
     * ============================================================== */

    /** @return string[] */
    public static function alap_gyakori(): array
    {
        return [
            'Telefon szerviz szolgáltatás',
            'TV szerviz szolgáltatás',
            'Számítógép szerviz szolgáltatás',
            'Számítógép alaplapi javítás',
        ];
    }

    /** @return string[] */
    public static function gyakori(): array
    {
        $m = get_option(self::OPT_GYAKORI, null);

        return is_array($m) ? array_values(array_map('strval', $m)) : self::alap_gyakori();
    }

    /** @param mixed $lista */
    public static function gyakori_tisztit($lista): array
    {
        $ki    = [];
        $latott = [];

        foreach (is_array($lista) ? $lista : [] as $sor) {
            $sor = trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) $sor)) ?? '');
            $kis = mb_strtolower($sor, 'UTF-8');

            if ($sor === '' || isset($latott[$kis])) {
                continue;
            }

            $latott[$kis] = true;
            $ki[] = mb_substr($sor, 0, 190);

            if (count($ki) >= 200) {
                break;
            }
        }

        return $ki;
    }

    public static function ajax_gyakori_ment(): void
    {
        self::jog();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- gyakori_tisztit() tisztít.
        $lista = isset($_POST['lista']) ? wp_unslash($_POST['lista']) : [];
        $lista = self::gyakori_tisztit($lista);

        update_option(self::OPT_GYAKORI, $lista, false);

        wp_send_json_success(['lista' => $lista, 'uzenet' => 'Elmentve.']);
    }

    /* =================================================================
     * Mezőmemória
     * ============================================================== */

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    /** A mezőkulcs: kisbetű, szám, kettőspont, pont, kötőjel, aláhúzás, szögletes zárójel. */
    public static function kulcs_tisztit(string $kulcs): string
    {
        $kulcs = strtolower(trim($kulcs));
        $kulcs = (string) preg_replace('/\[\d+\]|\[__i__\]/', '[]', $kulcs);
        $kulcs = (string) preg_replace('/[^a-z0-9:._\-\[\]]/', '', $kulcs);

        return substr($kulcs, 0, 120);
    }

    /** Elmenthető-e az érték ebbe a mezőbe (titok és kártyaszám soha). */
    public static function mentheto(string $kulcs, string $ertek): bool
    {
        if ($kulcs === '' || preg_match(self::TITKOS, $kulcs) === 1) {
            return false;
        }

        $tiszta = trim($ertek);
        $hossz  = mb_strlen($tiszta, 'UTF-8');

        if ($hossz < 2 || $hossz > 190) {
            return false;
        }

        // Kártyaszám-gyanús: 13–19 számjegy (szóközzel, kötőjellel is).
        $szamok = (string) preg_replace('/[\s\-]/', '', $tiszta);

        return preg_match('/^\d{13,19}$/', $szamok) !== 1;
    }

    /** Egy érték rögzítése (vagy a számlálója nő). */
    public static function rogzit(string $kulcs, string $ertek): bool
    {
        global $wpdb;

        $kulcs = self::kulcs_tisztit($kulcs);
        $ertek = trim(preg_replace('/\s+/u', ' ', $ertek) ?? '');

        if (!self::be() || !self::mentheto($kulcs, $ertek)) {
            return false;
        }

        $t        = self::tabla();
        $lenyomat = md5(mb_strtolower($ertek, 'UTF-8'));
        $most     = current_time('mysql');

        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE kulcs = %s AND lenyomat = %s", $kulcs, $lenyomat)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($id > 0) {
            $wpdb->query($wpdb->prepare("UPDATE {$t} SET db = db + 1, utolso = %s, ertek = %s WHERE id = %d", $most, $ertek, $id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            return true;
        }

        $wpdb->insert($t, ['kulcs' => $kulcs, 'lenyomat' => $lenyomat, 'ertek' => $ertek, 'db' => 1, 'utolso' => $most]);

        // A mező memóriája nem nő a végtelenig: a legritkább, legrégebbi megy el.
        $db = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE kulcs = %s", $kulcs)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($db > self::MAX_KULCSONKENT) {
            $regiek = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$t} WHERE kulcs = %s ORDER BY db ASC, utolso ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $kulcs,
                $db - self::MAX_KULCSONKENT
            ));

            foreach ($regiek as $regi) {
                $wpdb->delete($t, ['id' => (int) $regi]);
            }
        }

        return true;
    }

    /**
     * A mező értékei, a leggyakoribb / legutóbbi elöl.
     *
     * @return array<int, array{e: string, f?: int}> e = érték, f = 1: a „Leggyakoribb" listáról
     */
    public static function ertekek(string $kulcs): array
    {
        global $wpdb;

        $kulcs = self::kulcs_tisztit($kulcs);
        $ki    = [];
        $latott = [];

        $felvesz = static function (string $ertek, bool $kedvenc = false) use (&$ki, &$latott): void {
            $kis = mb_strtolower(trim($ertek), 'UTF-8');

            if ($kis === '' || isset($latott[$kis])) {
                return;
            }

            $latott[$kis] = true;
            $ki[] = $kedvenc ? ['e' => $ertek, 'f' => 1] : ['e' => $ertek];
        };

        // A tételnév mezőn elöl a Leggyakoribb Számla tételek listája.
        if ($kulcs === self::TETELNEV) {
            foreach (self::gyakori() as $g) {
                $felvesz($g, true);
            }
        }

        if ($kulcs === '' || preg_match(self::TITKOS, $kulcs) === 1) {
            return $ki;
        }

        $t = self::tabla();

        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ertek FROM {$t} WHERE kulcs = %s ORDER BY db DESC, utolso DESC LIMIT %d", $kulcs, self::MAX_KULD)) as $ertek) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $felvesz((string) $ertek);
        }

        // A tételnév mezőn a munkalapokra már felvett tételnevek is (a leggyakoribbak).
        if ($kulcs === self::TETELNEV && count($ki) < self::MAX_KULD) {
            $tt = SDH_Muhely_Schema::tabla('munkalap_tetel');

            // 0.64: tárolva (10 percig): 500 ezer munkalapnál ez a teljes tételtábla csoportosítása lenne
            // minden kérésre. A gyakorisági sorrend lassan változik – a pár perces késés nem számít.
            $nevek = SDH_Muhely_Gyorsitotar::ertek(self::TETELNEV_TAR, static fn (): array => (array) $wpdb->get_col($wpdb->prepare(
                "SELECT megnevezes FROM {$tt} WHERE megnevezes <> '' GROUP BY megnevezes ORDER BY COUNT(*) DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::MAX_KULD
            )), 600, false);

            foreach ((array) $nevek as $nev) {
                if (count($ki) >= self::MAX_KULD) {
                    break;
                }

                $felvesz((string) $nev);
            }
        }

        return $ki;
    }

    public static function torol(string $kulcs, string $ertek): void
    {
        global $wpdb;

        $wpdb->delete(self::tabla(), [
            'kulcs'    => self::kulcs_tisztit($kulcs),
            'lenyomat' => md5(mb_strtolower(trim($ertek), 'UTF-8')),
        ]);
    }

    /** Az összes megjegyzett érték száma (Beállítások › Űrlapok). */
    public static function db(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::tabla()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function urit(): void
    {
        global $wpdb;

        $wpdb->query('DELETE FROM ' . self::tabla()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    public static function ajax_ker(): void
    {
        self::jog();

        $kulcs = isset($_POST['kulcs']) ? sanitize_text_field(wp_unslash((string) $_POST['kulcs'])) : '';

        wp_send_json_success(['kulcs' => self::kulcs_tisztit($kulcs), 'ertekek' => self::be() ? self::ertekek($kulcs) : []]);
    }

    /** Több érték egyszerre: tetelek[] = "kulcs\tertek". */
    public static function ajax_ment(): void
    {
        self::jog();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- soronként tisztítva.
        $sorok = isset($_POST['tetelek']) && is_array($_POST['tetelek']) ? wp_unslash($_POST['tetelek']) : [];
        $db    = 0;

        foreach (array_slice($sorok, 0, 40) as $sor) {
            $reszek = explode("\t", (string) $sor, 2);

            if (count($reszek) === 2 && self::rogzit(sanitize_text_field($reszek[0]), sanitize_text_field($reszek[1]))) {
                $db++;
            }
        }

        wp_send_json_success(['db' => $db]);
    }

    public static function ajax_torol(): void
    {
        self::jog();

        $kulcs = isset($_POST['kulcs']) ? sanitize_text_field(wp_unslash((string) $_POST['kulcs'])) : '';
        $ertek = isset($_POST['ertek']) ? sanitize_text_field(wp_unslash((string) $_POST['ertek'])) : '';

        self::torol($kulcs, $ertek);

        wp_send_json_success(['uzenet' => 'Törölve a felajánlásból.']);
    }

    /* =================================================================
     * Beállítások › Űrlapok (az Urlap_Beallitas lapfülén)
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Mezőmemória</h2>
            <p class="sdh-mezo__sugo">
                Amit egy mezőbe beírtatok vagy amire kerestetek, azt a rendszer megjegyzi, és legközelebb gépelés közben felajánlja
                (nyíllal választható, Enterrel beírható, a sor végi × kiveszi a felajánlásból). Jelszót, kulcsot, kódot, kártya- és
                bankszámlaszámot soha nem jegyez meg. Most <strong><?php echo esc_html(number_format_i18n(self::db())); ?></strong> érték van benne.
            </p>
            <input type="hidden" name="emlek_jelen" value="1">
            <label class="sdh-kapcsolo sdh-ub-kapcs">
                <input type="checkbox" name="emlek_be" value="1" <?php checked(self::be()); ?>>
                <span class="sdh-kapcsolo__csuszka" aria-hidden="true"></span>
                <span class="sdh-kapcsolo__felirat">Mezőmemória bekapcsolva</span>
            </label>
            <label class="sdh-jelolo" title="Két lépés: pipáld be, majd Mentés – csak akkor törlődik.">
                <input type="checkbox" name="emlek_urit" value="1"> Mentéskor töröld a teljes mezőmemóriát
            </label>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Leggyakoribb Számla tételek listája</h2>
            <p class="sdh-mezo__sugo">
                A számla ablakában a <strong>Gyakori tételek</strong> gombbal egy kattintással felvehető tételnevek (az ablakban is szerkeszthetők).
                Soronként egy tétel; a sorrend itt is az.
            </p>
            <textarea name="szamla_gyakori" rows="6" class="sdh-ub-gyakori"><?php echo esc_textarea(implode("\n", self::gyakori())); ?></textarea>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások ellenőrizte.
        if (isset($_POST['emlek_jelen'])) {
            update_option(self::OPT, ['be' => !empty($_POST['emlek_be'])], false);

            if (!empty($_POST['emlek_urit'])) {
                self::urit();
            }
        }

        if (isset($_POST['szamla_gyakori'])) {
            $sorok = preg_split('/\r?\n/', (string) wp_unslash($_POST['szamla_gyakori'])) ?: []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- gyakori_tisztit() tisztít.
            update_option(self::OPT_GYAKORI, self::gyakori_tisztit($sorok), false);
        }
        // phpcs:enable
    }
}
