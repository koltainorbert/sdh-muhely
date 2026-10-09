<?php
/**
 * Számlázás a Számlázz.hu Számla Agenten keresztül.
 *
 * A munkalap-ablak lábléce két gombot kap: „Számlázz.hu számla" (a fiók
 * alapértelmezett számlatömbje) és „SDH számla" (ugyanaz a Számla Agent, de
 * a beállított előtaggal – alapból SD –, így a Számlázz.hu külön, folyamatos
 * sorszámozást vezet: SD-2026-1, SD-2026-2…). Mindkét számlát a Számlázz.hu
 * állítja ki: az ő sablonjával készül, és ő jelenti a NAV felé.
 *
 * SEMMI nem megy ki magától. A gomb csak a számla ablakát nyitja meg: ott
 * látszik a vevő, tételenként kipipálható, mi kerüljön a számlára, megnézhető
 * az előnézet (PDF, számla nem készül), és csak a „Számla kiállítása" gomb
 * küldi be. A kiállított számla végleges – javítani sztornóval lehet.
 *
 * A számlára a munkalap tételei kerülnek: szolgáltatások, termékek és a
 * bevizsgálási díj. Ez utóbbi a munkalap „Bevizsgálási díj" jelölőjéből
 * lesz: bepipálva az előleg összege külön tételsorként él a munkalapon
 * („Samsung SM-A175B mobiltelefon bevizsgálási díj"), a `forras` mezője
 * `bevizsgalas` – ezt a sort a rendszer tartja karban (bevizsgalas_szinkron).
 *
 * A már számlázott tétel megjegyzi a számla számát (munkalap_tetel.szamla),
 * és a következő számlánál alapból nincs kipipálva – így a bevizsgálási díj
 * átvételkor, a javítás a végén külön is számlázható.
 *
 * A hivatalos PHP SDK helyett saját, vékony kliens beszél az Agenttel
 * (WordPress HTTP API): nincs külső függőség, és nem ír a plugin mappájába.
 * Dokumentáció: https://docs.szamlazz.hu/hu/agent/
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Szamla
{
    /** A beállítások option-neve. */
    private const OPTION = 'sdh_muhely_szamla';

    /** A számla-PDF-ek almappája a feltöltési mappában. */
    private const MAPPA = 'sdh-muhely-szamlak';

    /** A bevizsgálási díj tételsorának `forras` értéke. */
    public const BEVIZSGALAS = 'bevizsgalas';

    /** A Számla Agent címe (teszthez a SDH_MUHELY_SZAMLA_AGENT_URL állandóval átírható). */
    private const AGENT_URL = 'https://www.szamlazz.hu/szamla/';

    public static function init(): void
    {
        // A számla ablaka: űrlap, kiállítás, előnézet, PDF.
        add_action('wp_ajax_sdh_muhely_szamla_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_szamla_ment', [self::class, 'ajax_kiallit']);
        add_action('wp_ajax_sdh_muhely_szamla_elonezet', [self::class, 'ajax_elonezet']);
        add_action('wp_ajax_sdh_muhely_szamla_pdf', [self::class, 'ajax_pdf']);

        // Beállítások: a kapcsolat ellenőrzése (számla nem készül).
        add_action('wp_ajax_sdh_muhely_szamla_kapcsolat', [self::class, 'ajax_kapcsolat']);
    }

    public static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('szamla');
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    /**
     * @return array<string, mixed>
     */
    public static function beallitas(): array
    {
        $alap = [
            'kulcs'              => '',
            'eszamla'            => true,
            'elotag_fo'          => '',
            'elotag_sdh'         => 'SD',
            'gomb_sdh'           => 'SDH számla',
            'hatarido_nap'       => 8,
            'alap_fizmod'        => 'Készpénz',
            'email_kuldes'       => false,
            'megjegyzes'         => 'Munkalap: {munkalap} · {eszkoz}',
            'bevizsgalas_sablon' => '{eszkoz} {fajta} bevizsgálási díj',
        ];

        $mentett = get_option(self::OPTION, []);

        return array_merge($alap, is_array($mentett) ? array_intersect_key($mentett, $alap) : []);
    }

    public static function beallitva(): bool
    {
        return trim((string) self::beallitas()['kulcs']) !== '';
    }

    /** A Beállítások oldal doboza. A kulcs soha nem kerül vissza a HTML-be. */
    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();

        ?>
        <div class="sdh-doboz" id="szamlazas">
            <h2 class="sdh-doboz__cim">Számlázás – Számlázz.hu</h2>

            <p class="sdh-sugo">
                A munkalapról gombnyomásra készül számla a Számlázz.hu fiókodban (Számla Agent).
                A kulcsot a Számlázz.hu-n találod: jobb fent a fióknév → <em>Beállítások</em> → lap alja,
                <em>Számla Agent kulcsok</em>. A számlát mindig a Számlázz.hu állítja ki és jelenti a NAV felé;
                magától semmi nem megy ki, csak amikor a számla ablakában a „Számla kiállítása" gombra kattintasz.
            </p>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="szamla_kulcs">Számla Agent kulcs</label>
                    <input type="password" name="szamla_kulcs" id="szamla_kulcs" autocomplete="new-password"
                           placeholder="<?php echo esc_attr(self::beallitva() ? '•••••••• (mentve – csak akkor írd be, ha cserélnéd)' : 'Ide másold be a kulcsot'); ?>">
                    <?php if (self::beallitva()) : ?>
                        <span class="sdh-mezo__sugo sdh-szamla-kapcsolat">
                            <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-szamla-kapcsolat>Kapcsolat ellenőrzése</button>
                            <label class="sdh-jelolo"><input type="checkbox" name="szamla_kulcs_torol" value="1"> Kulcs törlése</label>
                            <span data-sdh-szamla-kapcsolat-ki aria-live="polite"></span>
                        </span>
                    <?php else : ?>
                        <span class="sdh-mezo__sugo">Mentés után itt ellenőrizhető a kapcsolat – számla készítése nélkül.</span>
                    <?php endif; ?>
                </div>

                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="checkbox" name="szamla_eszamla" id="szamla_eszamla" value="1" <?php checked(!empty($b['eszamla'])); ?>>
                    <label for="szamla_eszamla">E-számla (elektronikus). Kikapcsolva papír alapú, nyomtatandó számla készül.</label>
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_elotag_fo">Előtag – „Számlázz.hu számla" gomb</label>
                    <input type="text" name="szamla_elotag_fo" id="szamla_elotag_fo" maxlength="10"
                           value="<?php echo esc_attr((string) $b['elotag_fo']); ?>" placeholder="(a fiók alap számlatömbje)">
                    <span class="sdh-mezo__sugo">Üresen a fiók alapértelmezett számlatömbje. Csak a Számlázz.hu-n már felvett előtag használható.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_elotag_sdh">Előtag – második gomb</label>
                    <input type="text" name="szamla_elotag_sdh" id="szamla_elotag_sdh" maxlength="10"
                           value="<?php echo esc_attr((string) $b['elotag_sdh']); ?>">
                    <span class="sdh-mezo__sugo">
                        Ezzel az előtaggal a Számlázz.hu külön, folyamatos sorszámot vezet
                        (pl. <?php echo esc_html(((string) $b['elotag_sdh'] !== '' ? (string) $b['elotag_sdh'] : 'SD') . '-' . current_time('Y')); ?>-1, -2…).
                        Az előtagot előbb a Számlázz.hu-n is fel kell venni (Beállítások → Előtagok), különben
                        a számlát elutasítja. Üresen a második gomb nem jelenik meg.
                    </span>
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_gomb_sdh">A második gomb felirata</label>
                    <input type="text" name="szamla_gomb_sdh" id="szamla_gomb_sdh" maxlength="30"
                           value="<?php echo esc_attr((string) $b['gomb_sdh']); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_alap_fizmod">Alap fizetési mód</label>
                    <input type="text" name="szamla_alap_fizmod" id="szamla_alap_fizmod" maxlength="40"
                           value="<?php echo esc_attr((string) $b['alap_fizmod']); ?>">
                    <span class="sdh-mezo__sugo">Akkor érvényes, ha a munkalapon nincs megadva.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_hatarido_nap">Fizetési határidő (nap)</label>
                    <input type="number" name="szamla_hatarido_nap" id="szamla_hatarido_nap" min="0" max="365"
                           value="<?php echo (int) $b['hatarido_nap']; ?>">
                    <span class="sdh-mezo__sugo">Átutalásnál számít; készpénznél és kártyánál a számla napja.</span>
                </div>

                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="checkbox" name="szamla_email_kuldes" id="szamla_email_kuldes" value="1" <?php checked(!empty($b['email_kuldes'])); ?>>
                    <label for="szamla_email_kuldes">A számla ablakában alapból legyen bepipálva az „E-mail az ügyfélnek" (a Számlázz.hu küldi el a számlát).</label>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="szamla_megjegyzes">Megjegyzés a számlán</label>
                    <input type="text" name="szamla_megjegyzes" id="szamla_megjegyzes" maxlength="250"
                           value="<?php echo esc_attr((string) $b['megjegyzes']); ?>">
                    <span class="sdh-mezo__sugo">Helyőrzők: <code>{munkalap}</code>, <code>{eszkoz}</code>, <code>{imei}</code>. A számla ablakában átírható.</span>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="szamla_bevizsgalas_sablon">A bevizsgálási díj megnevezése</label>
                    <input type="text" name="szamla_bevizsgalas_sablon" id="szamla_bevizsgalas_sablon" maxlength="200"
                           value="<?php echo esc_attr((string) $b['bevizsgalas_sablon']); ?>">
                    <span class="sdh-mezo__sugo">
                        Helyőrzők: <code>{eszkoz}</code> (gyártó és típus), <code>{fajta}</code> (pl. mobiltelefon).
                        Példa: „Samsung SM-A175B mobiltelefon bevizsgálási díj".
                    </span>
                </div>
            </div>
        </div>
        <?php
    }

    /** A Beállítások mentésekor hívódik (a nonce-ot a hívó ellenőrizte). */
    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if (!isset($_POST['szamla_alap_fizmod'])) {
            return;
        }

        $szoveg = static fn (string $k, int $hossz): string => isset($_POST[$k]) && is_scalar($_POST[$k])
            ? mb_substr(sanitize_text_field(wp_unslash((string) $_POST[$k])), 0, $hossz)
            : '';
        $elotag = static fn (string $k): string => mb_substr((string) preg_replace('/[^A-Za-z0-9]/', '', $szoveg($k, 20)), 0, 10);

        $b       = self::beallitas();
        $uj_kulcs = isset($_POST['szamla_kulcs']) && is_scalar($_POST['szamla_kulcs'])
            ? trim(sanitize_text_field(wp_unslash((string) $_POST['szamla_kulcs'])))
            : '';

        if (!empty($_POST['szamla_kulcs_torol'])) {
            $b['kulcs'] = '';
        } elseif ($uj_kulcs !== '') {
            $b['kulcs'] = $uj_kulcs;
        }

        $b['eszamla']            = !empty($_POST['szamla_eszamla']);
        $b['elotag_fo']          = $elotag('szamla_elotag_fo');
        $b['elotag_sdh']         = $elotag('szamla_elotag_sdh');
        $b['gomb_sdh']           = $szoveg('szamla_gomb_sdh', 30) !== '' ? $szoveg('szamla_gomb_sdh', 30) : 'SDH számla';
        $b['hatarido_nap']       = isset($_POST['szamla_hatarido_nap']) ? min(365, max(0, (int) $_POST['szamla_hatarido_nap'])) : 8;
        $b['alap_fizmod']        = $szoveg('szamla_alap_fizmod', 40) !== '' ? $szoveg('szamla_alap_fizmod', 40) : 'Készpénz';
        $b['email_kuldes']       = !empty($_POST['szamla_email_kuldes']);
        $b['megjegyzes']         = $szoveg('szamla_megjegyzes', 250);
        $b['bevizsgalas_sablon'] = $szoveg('szamla_bevizsgalas_sablon', 200) !== ''
            ? $szoveg('szamla_bevizsgalas_sablon', 200)
            : '{eszkoz} {fajta} bevizsgálási díj';
        // phpcs:enable

        // Nem töltődik be minden oldalon (autoload = no): a kulcs csak számlázáskor kell.
        update_option(self::OPTION, $b, false);
    }

    /* =================================================================
     * Bevizsgálási díj
     * ============================================================== */

    /** Az eszköz fajtája a díj megnevezéséhez (pl. „mobiltelefon"). */
    private static function fajta(object $eszkoz): string
    {
        $kulcs = (string) ($eszkoz->kategoria ?? '');
        $nevek = SDH_Muhely_Eszkoz::kategoriak();
        $nev   = $kulcs === 'telefon' ? 'mobiltelefon' : mb_strtolower((string) ($nevek[$kulcs] ?? ''), 'UTF-8');

        return (string) apply_filters('sdh_muhely_bevizsgalas_fajta', $nev, $kulcs, $eszkoz);
    }

    /** A bevizsgálási díj tételének neve a munkalap eszközéből. */
    public static function bevizsgalas_nev(object $munkalap): string
    {
        global $wpdb;

        $eszkoz = (int) $munkalap->eszkoz_id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' WHERE id = %d', (int) $munkalap->eszkoz_id))
            : null;

        $nev = strtr((string) self::beallitas()['bevizsgalas_sablon'], [
            '{eszkoz}' => is_object($eszkoz) ? SDH_Muhely_Eszkoz::megnevezes($eszkoz) : '',
            '{fajta}'  => is_object($eszkoz) ? self::fajta($eszkoz) : '',
        ]);

        $nev = trim((string) preg_replace('/\s+/u', ' ', $nev));

        return mb_substr($nev !== '' ? $nev : 'Bevizsgálási díj', 0, 255);
    }

    /**
     * A munkalap bevizsgálásidíj-sora a jelölőhöz és az előleghez igazítva.
     * A munkalap minden mentése után fut; idempotens.
     *
     *   jelölő be + előleg > 0  → van egy `bevizsgalas` forrású szolgáltatássor,
     *                             az ára az előleg (a nevét csak létrehozáskor kapja,
     *                             utána kézzel átírható);
     *   egyébként               → a sor törlődik.
     *
     * A már számlázott díjsorhoz nem nyúl.
     */
    public static function bevizsgalas_szinkron(int $munkalap_id): void
    {
        global $wpdb;

        if ($munkalap_id <= 0) {
            return;
        }

        $tetel_tabla = SDH_Muhely_Tetel::tabla();
        $munkalap    = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $munkalap_id)
        );

        if (!is_object($munkalap)) {
            return;
        }

        $sorok = (array) $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tetel_tabla} WHERE munkalap_id = %d AND forras = %s ORDER BY id ASC", $munkalap_id, self::BEVIZSGALAS)
        );

        $dij = round((float) $munkalap->fizetett, 2);
        $be  = (int) ($munkalap->bevizsgalasi_dij ?? 0) === 1 && $dij > 0;
        $sor = null;

        // Egy díjsor lehet; a számlázott mindig megmarad.
        foreach ($sorok as $s) {
            if ((string) $s->szamla !== '') {
                $sor = $sor ?? $s;
                continue;
            }

            if ($be && $sor === null) {
                $sor = $s;
                continue;
            }

            $wpdb->delete($tetel_tabla, ['id' => (int) $s->id]);
        }

        if ($be && $sor === null) {
            SDH_Muhely_Tetel::hozzaad($munkalap_id, [
                'tipus'      => 'szolgaltatas',
                'megnevezes' => self::bevizsgalas_nev($munkalap),
                'mennyiseg'  => 1,
                'me'         => 'db',
                'afa_kulcs'  => (string) $munkalap->afakulcs,
                'brutto_ar'  => $dij,
                'sorrend'    => -1,
                'idopont'    => current_time('Y-m-d'),
                'forras'     => self::BEVIZSGALAS,
            ]);

            return; // a hozzaad() újraszámol
        }

        if ($be && $sor !== null && (string) $sor->szamla === '' && abs((float) $sor->brutto_ar - $dij) >= 0.005) {
            $afa   = (float) $sor->afa;
            $netto = SDH_Muhely_Tetel::bruttobol_netto($dij, $afa);
            $menny = (float) $sor->mennyiseg > 0 ? (float) $sor->mennyiseg : 1.0;
            $kedv  = (float) $sor->kedvezmeny;

            $wpdb->update($tetel_tabla, [
                'brutto_ar'    => $dij,
                'netto_ar'     => $netto,
                'netto_ertek'  => SDH_Muhely_Tetel::ertek($netto, $menny, $kedv),
                'brutto_ertek' => SDH_Muhely_Tetel::ertek($dij, $menny, $kedv),
                'modositva'    => current_time('mysql'),
            ], ['id' => (int) $sor->id]);
        }

        SDH_Muhely_Tetel::ujraszamol($munkalap_id);
    }

    /* =================================================================
     * Számla Agent – kliens
     * ============================================================== */

    private static function agent_url(): string
    {
        $url = defined('SDH_MUHELY_SZAMLA_AGENT_URL') ? (string) SDH_MUHELY_SZAMLA_AGENT_URL : self::AGENT_URL;

        return (string) apply_filters('sdh_muhely_szamla_agent_url', $url);
    }

    private static function x(string $szoveg): string
    {
        // XML 1.0-ban tiltott vezérlőkarakterek nélkül.
        $szoveg = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $szoveg);

        return htmlspecialchars($szoveg, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Szám az XML-be: ponttal, legfeljebb két tizedessel. */
    private static function n(float $ertek): string
    {
        $szoveg = rtrim(rtrim(number_format($ertek, 2, '.', ''), '0'), '.');

        return $szoveg === '' || $szoveg === '-0' ? '0' : $szoveg;
    }

    /**
     * Egymás utáni XML-elemek egy kulcs => érték tömbből. A null kimarad,
     * a bool „true"/„false" lesz. A sorrend számít (a séma kötött).
     *
     * @param array<string, mixed> $mezok
     */
    private static function elemek(array $mezok, string $behuzas = '    '): string
    {
        $ki = '';

        foreach ($mezok as $nev => $ertek) {
            if ($ertek === null) {
                continue;
            }

            if (is_bool($ertek)) {
                $ertek = $ertek ? 'true' : 'false';
            }

            $ki .= $behuzas . '<' . $nev . '>' . self::x((string) $ertek) . '</' . $nev . ">\n";
        }

        return $ki;
    }

    /**
     * Egy kérés az Agentnek: az XML fájlként megy, multipart űrlapmezőben.
     *
     * @return array{ok: bool, kod: string, uzenet: string, szamlaszam: string, netto: float, brutto: float, pdf: string, torzs: string}
     */
    private static function agent_kuld(string $mezo, string $xml): array
    {
        $ki = ['ok' => false, 'kod' => '', 'uzenet' => '', 'szamlaszam' => '', 'netto' => 0.0, 'brutto' => 0.0, 'pdf' => '', 'torzs' => ''];

        $hatar = '----sdhmuhely' . wp_generate_password(24, false);
        $torzs = '--' . $hatar . "\r\n"
            . 'Content-Disposition: form-data; name="' . $mezo . '"; filename="request.xml"' . "\r\n"
            . "Content-Type: text/xml\r\n\r\n"
            . $xml . "\r\n"
            . '--' . $hatar . "--\r\n";

        $valasz = wp_remote_post(self::agent_url(), [
            'timeout'     => 40,
            'redirection' => 0,
            'headers'     => ['Content-Type' => 'multipart/form-data; boundary=' . $hatar],
            'body'        => $torzs,
        ]);

        if (is_wp_error($valasz)) {
            $ki['uzenet'] = 'A Számlázz.hu nem érhető el (' . $valasz->get_error_message() . '). Számla nem készült.';

            return $ki;
        }

        $fejlec = static fn (string $nev): string => (string) wp_remote_retrieve_header($valasz, $nev);
        $http   = (int) wp_remote_retrieve_response_code($valasz);
        $test   = (string) wp_remote_retrieve_body($valasz);

        $ki['torzs']      = $test;
        $ki['kod']        = trim($fejlec('szlahu_error_code'));
        $ki['uzenet']     = trim(urldecode($fejlec('szlahu_error')));
        $ki['szamlaszam'] = trim(urldecode($fejlec('szlahu_szamlaszam')));
        $ki['netto']      = (float) $fejlec('szlahu_nettovegosszeg');
        $ki['brutto']     = (float) $fejlec('szlahu_bruttovegosszeg');

        if (strncmp($test, '%PDF', 4) === 0) {
            $ki['pdf'] = $test;
        }

        if ($ki['kod'] !== '' || $ki['uzenet'] !== '') {
            $ki['uzenet'] = $ki['uzenet'] !== '' ? $ki['uzenet'] : 'A Számlázz.hu hibát jelzett.';

            return $ki;
        }

        if ($http !== 200) {
            $ki['uzenet'] = 'A Számlázz.hu ' . $http . ' hibakóddal válaszolt. Számla nem készült.';

            return $ki;
        }

        $ki['ok'] = true;

        return $ki;
    }

    /** Hibaüzenet a felhasználónak: a Számlázz.hu szövege, a kóddal. */
    private static function hiba_szoveg(array $valasz): string
    {
        $uzenet = (string) $valasz['uzenet'];

        $sajat = [
            '3'   => 'A Számlázz.hu nem fogadta el a Számla Agent kulcsot. Ellenőrizd a Beállításokban.',
            '54'  => 'A fiókodban nincs engedélyezve az e-számla. Kapcsold ki az „E-számla" jelölőt a Beállításokban (papír alapú számla), vagy engedélyezd a Számlázz.hu-n.',
            '136' => 'A Számla Agent most nem használható ebben a fiókban (előfizetés, tartozás). Lépj be a Számlázz.hu-ra, és nézd meg az előfizetésed.',
            '202' => 'Ez a számlaszám-előtag nincs felvéve a Számlázz.hu fiókodban. Vedd fel ott: Beállítások → Előtagok, aztán próbáld újra.',
        ];

        if (isset($sajat[(string) $valasz['kod']])) {
            $uzenet = $sajat[(string) $valasz['kod']];
        }

        return $uzenet . ((string) $valasz['kod'] !== '' ? ' (Számlázz.hu hibakód: ' . $valasz['kod'] . ')' : '');
    }

    /* =================================================================
     * A számla összeállítása
     * ============================================================== */

    /** A mi áfakulcsunk a Számlázz.hu jelölésével. */
    private static function afakulcs(string $kulcs): string
    {
        $megfelelo = [
            'FOA'  => 'F.AFA',
            'KSZH' => 'K.AFA',
            'KSZM' => 'K.AFA',
            'KSZR' => 'K.AFA',
            'KSZU' => 'K.AFA',
        ];

        return (string) apply_filters('sdh_muhely_szamla_afakulcs', $megfelelo[$kulcs] ?? $kulcs, $kulcs);
    }

    /**
     * Egy tétel számlasora. A munkalapon az ár BRUTTÓ egységár; a Számlázz.hu
     * nettó egységárat kér, és ellenőrzi, hogy egységár × mennyiség = nettó
     * érték, nettó + áfa = bruttó. Ezért a nettó egységárat két tizedesre
     * kerekítjük, és abból számolunk tovább – a bruttó így néhány fillérrel
     * eltérhet a munkalapétól (forintra kerekítve jellemzően ugyanaz).
     *
     * @return array{megnevezes: string, mennyiseg: float, me: string, netto_egysegar: float, afakulcs: string, netto: float, afa: float, brutto: float}
     */
    public static function tetel_sor(object $tetel): array
    {
        $kulcs     = SDH_Muhely_Tetel::afakulcs_ervenyes((string) $tetel->afa_kulcs);
        $szazalek  = SDH_Muhely_Tetel::afa_szazalek($kulcs);
        $menny     = (float) $tetel->mennyiseg > 0 ? (float) $tetel->mennyiseg : 1.0;
        $kedv      = min(100.0, max(0.0, (float) $tetel->kedvezmeny));
        $brutto_ar = (float) $tetel->brutto_ar * (1 - $kedv / 100);
        $egysegar  = round($brutto_ar / (1 + $szazalek / 100), 2);
        $netto     = round($egysegar * $menny, 2);
        $afa       = round($netto * $szazalek / 100, 2);

        $nev = trim((string) $tetel->megnevezes);

        if ($kedv > 0) {
            $nev .= ' (' . rtrim(rtrim(number_format($kedv, 2, ',', ''), '0'), ',') . '% kedvezménnyel)';
        }

        return [
            'megnevezes'     => $nev !== '' ? $nev : ((string) $tetel->tipus === 'termek' ? 'Termék' : 'Szolgáltatás'),
            'mennyiseg'      => $menny,
            'me'             => trim((string) $tetel->me) !== '' ? (string) $tetel->me : 'db',
            'netto_egysegar' => $egysegar,
            'afakulcs'       => self::afakulcs($kulcs),
            'netto'          => $netto,
            'afa'            => $afa,
            'brutto'         => round($netto + $afa, 2),
        ];
    }

    /**
     * A munkalap számlázható tételei: a bevizsgálási díj elöl, aztán a
     * szolgáltatások, végül a termékek.
     *
     * @return array<int, object>
     */
    public static function tetelek(int $munkalap_id): array
    {
        $tetelek = array_values(array_filter(
            SDH_Muhely_Tetel::lista($munkalap_id),
            static fn (object $t): bool => (string) $t->mozgas === 'kimeno'
        ));

        $rang = static fn (object $t): int => (string) $t->forras === self::BEVIZSGALAS ? 0 : ((string) $t->tipus === 'szolgaltatas' ? 1 : 2);

        usort($tetelek, static fn (object $a, object $b): int => [$rang($a), (int) $a->sorrend, (int) $a->id] <=> [$rang($b), (int) $b->sorrend, (int) $b->id]);

        return $tetelek;
    }

    /**
     * A vevő adatai az ügyfélből, és ami hiányzik a számlához.
     *
     * @return array{adat: array<string, mixed>, hianyzik: array<int, string>}
     */
    public static function vevo(?object $ugyfel): array
    {
        if ($ugyfel === null) {
            return ['adat' => [], 'hianyzik' => ['ügyfél']];
        }

        $adoszam = trim((string) $ugyfel->adoszam);
        $ceg     = (string) $ugyfel->tipus !== 'maganszemely';

        // Magánszemélynél az „adószám" mezőben jellemzően az adóazonosító jel áll
        // (10 számjegy) – az személyes adat, számlára és a NAV felé NEM kerülhet.
        // Csak a valódi (vállalkozói) adószám megy: 12345678-1-12.
        if (!$ceg && preg_match('/^\d{8}-?\d-?\d{2}$/', $adoszam) !== 1) {
            $adoszam = '';
        }
        $orszag  = trim((string) ($ugyfel->szamlazasi_orszag ?? ''));

        $adat = [
            'nev'       => trim((string) $ugyfel->nev),
            'orszag'    => $orszag !== '' ? $orszag : 'Magyarország',
            'irsz'      => trim((string) $ugyfel->szamlazasi_iranyitoszam),
            'telepules' => trim((string) $ugyfel->szamlazasi_telepules),
            'cim'       => trim((string) $ugyfel->szamlazasi_cim),
            'email'     => is_email((string) $ugyfel->email) ? (string) $ugyfel->email : '',
            // 1 = belföldi adószámmal, 0 = nem tudjuk, -1 = nincs adószáma (magánszemély).
            'adoalany'  => $adoszam !== '' ? 1 : ($ceg ? 0 : -1),
            'adoszam'   => $adoszam,
            'telefon'   => trim((string) $ugyfel->telefon),
        ];

        // Külön levelezési cím: a számlán postázási címként jelenik meg.
        if ((int) ($ugyfel->levelezesi_azonos ?? 1) !== 1 && trim((string) $ugyfel->levelezesi_cim) !== '') {
            $adat['postazas'] = [
                'nev'       => $adat['nev'],
                'irsz'      => trim((string) $ugyfel->levelezesi_iranyitoszam),
                'telepules' => trim((string) $ugyfel->levelezesi_telepules),
                'cim'       => trim((string) $ugyfel->levelezesi_cim),
            ];
        }

        $hianyzik = [];

        foreach (['nev' => 'név', 'irsz' => 'irányítószám', 'telepules' => 'település', 'cim' => 'utca, házszám'] as $mezo => $cimke) {
            if ($adat[$mezo] === '') {
                $hianyzik[] = $cimke;
            }
        }

        return ['adat' => $adat, 'hianyzik' => $hianyzik];
    }

    /** A számla megjegyzése a beállított sablonból. */
    private static function megjegyzes(object $munkalap): string
    {
        global $wpdb;

        $eszkoz = (int) $munkalap->eszkoz_id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' WHERE id = %d', (int) $munkalap->eszkoz_id))
            : null;

        $szoveg = strtr((string) self::beallitas()['megjegyzes'], [
            '{munkalap}' => SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam),
            '{eszkoz}'   => is_object($eszkoz) ? SDH_Muhely_Eszkoz::megnevezes($eszkoz) : '',
            '{imei}'     => is_object($eszkoz) ? (string) $eszkoz->imei : '',
        ]);

        return trim((string) preg_replace('/\s*·\s*$/u', '', trim($szoveg)));
    }

    /** Az előtag a számlasorozathoz: 'fo' = Számlázz.hu számla, 'sdh' = SDH számla. */
    private static function elotag(string $sorozat): string
    {
        $b = self::beallitas();

        return (string) ($sorozat === 'sdh' ? $b['elotag_sdh'] : $b['elotag_fo']);
    }

    /**
     * A számla XML-je (xmlszamla).
     *
     * @param array<string, mixed>             $fej     kelt, teljesites, hatarido, fizmod, fizetve, megjegyzes, email, elonezet, rendeles
     * @param array<string, mixed>             $vevo    a vevo() „adat" része
     * @param array<int, array<string, mixed>> $sorok   tetel_sor() eredményei
     */
    public static function xml(string $sorozat, array $fej, array $vevo, array $sorok): string
    {
        $b      = self::beallitas();
        $elotag = self::elotag($sorozat);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<xmlszamla xmlns="http://www.szamlazz.hu/xmlszamla" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="http://www.szamlazz.hu/xmlszamla https://www.szamlazz.hu/szamla/docs/xsds/agent/xmlszamla.xsd">' . "\n";

        $xml .= "  <beallitasok>\n" . self::elemek([
            'szamlaagentkulcs' => (string) $b['kulcs'],
            'eszamla'          => !empty($b['eszamla']),
            'szamlaLetoltes'   => true,
            'valaszVerzio'     => 1,
        ]) . "  </beallitasok>\n";

        $xml .= "  <fejlec>\n" . self::elemek([
            'keltDatum'             => (string) $fej['kelt'],
            'teljesitesDatum'       => (string) $fej['teljesites'],
            'fizetesiHataridoDatum' => (string) $fej['hatarido'],
            'fizmod'                => (string) $fej['fizmod'],
            'penznem'               => 'HUF',
            'szamlaNyelve'          => 'hu',
            'megjegyzes'            => (string) $fej['megjegyzes'] !== '' ? (string) $fej['megjegyzes'] : null,
            'rendelesSzam'          => (string) ($fej['rendeles'] ?? '') !== '' ? (string) $fej['rendeles'] : null,
            'szamlaszamElotag'      => $elotag !== '' ? $elotag : null,
            'fizetve'               => !empty($fej['fizetve']),
            'elonezetpdf'           => !empty($fej['elonezet']) ? true : null,
        ]) . "  </fejlec>\n";

        $xml .= "  <elado>\n  </elado>\n";

        $postazas = is_array($vevo['postazas'] ?? null) ? $vevo['postazas'] : null;

        $xml .= "  <vevo>\n" . self::elemek([
            'nev'                => (string) $vevo['nev'],
            'orszag'             => (string) $vevo['orszag'],
            'irsz'               => (string) $vevo['irsz'],
            'telepules'          => (string) $vevo['telepules'],
            'cim'                => (string) $vevo['cim'],
            'email'              => (string) $vevo['email'] !== '' ? (string) $vevo['email'] : null,
            'sendEmail'          => !empty($fej['email']) && (string) $vevo['email'] !== '',
            'adoalany'           => (int) $vevo['adoalany'],
            'adoszam'            => (string) $vevo['adoszam'] !== '' ? (string) $vevo['adoszam'] : null,
            'postazasiNev'       => $postazas ? (string) $postazas['nev'] : null,
            'postazasiIrsz'      => $postazas ? (string) $postazas['irsz'] : null,
            'postazasiTelepules' => $postazas ? (string) $postazas['telepules'] : null,
            'postazasiCim'       => $postazas ? (string) $postazas['cim'] : null,
            'telefonszam'        => (string) $vevo['telefon'] !== '' ? (string) $vevo['telefon'] : null,
        ]) . "  </vevo>\n";

        $xml .= "  <tetelek>\n";

        foreach ($sorok as $s) {
            $xml .= "    <tetel>\n" . self::elemek([
                'megnevezes'       => (string) $s['megnevezes'],
                'mennyiseg'        => self::n((float) $s['mennyiseg']),
                'mennyisegiEgyseg' => (string) $s['me'],
                'nettoEgysegar'    => self::n((float) $s['netto_egysegar']),
                'afakulcs'         => (string) $s['afakulcs'],
                'nettoErtek'       => self::n((float) $s['netto']),
                'afaErtek'         => self::n((float) $s['afa']),
                'bruttoErtek'      => self::n((float) $s['brutto']),
            ], '      ') . "    </tetel>\n";
        }

        $xml .= "  </tetelek>\n</xmlszamla>\n";

        return $xml;
    }

    /* =================================================================
     * Olvasás
     * ============================================================== */

    /**
     * Egy munkalap kiállított számlái, a legújabb elöl.
     *
     * @return array<int, object>
     */
    public static function lista(int $munkalap_id): array
    {
        global $wpdb;

        $sorok = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE munkalap_id = %d ORDER BY id DESC', $munkalap_id)
        );

        return is_array($sorok) ? $sorok : [];
    }

    public static function pdf_url(int $szamla_id): string
    {
        return add_query_arg([
            'action'   => 'sdh_muhely_szamla_pdf',
            'id'       => $szamla_id,
            '_wpnonce' => wp_create_nonce('sdh_muhely_modal'),
        ], admin_url('admin-ajax.php'));
    }

    private static function penz(float $osszeg): string
    {
        return number_format($osszeg, 0, ',', "\u{00a0}");
    }

    /* =================================================================
     * A munkalap-ablak lábléce: gombok és a kiállított számlák
     * ============================================================== */

    /** A munkalap űrlapja hívja (csak mentett, számozott lapnál, popupban). */
    public static function munkalap_gombok(object $munkalap): void
    {
        $b       = self::beallitas();
        $szamlak = self::lista((int) $munkalap->id);

        ?>
        <span class="sdh-szamlagombok" data-sdh-szamlagombok>
            <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--szamla" data-sdh-szamla="fo"
                    title="A munkalap elmentődik, majd megnyílik a számla ablaka. Számla csak a kiállítás gombjára készül.">
                Számlázz.hu számla
            </button>

            <?php if ((string) $b['elotag_sdh'] !== '') : ?>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--szamla" data-sdh-szamla="sdh"
                        title="Ugyanaz a Számlázz.hu számla, <?php echo esc_attr((string) $b['elotag_sdh']); ?> előtagú, külön sorszámozással.">
                    <?php echo esc_html((string) $b['gomb_sdh']); ?>
                </button>
            <?php endif; ?>

            <?php foreach (array_slice($szamlak, 0, 3) as $sz) : ?>
                <a class="sdh-szamlajel" href="<?php echo esc_url(self::pdf_url((int) $sz->id)); ?>" target="_blank" rel="noopener"
                   title="<?php echo esc_attr(mysql2date('Y. m. d.', (string) $sz->kelt) . ' · ' . self::penz((float) $sz->brutto) . ' Ft · PDF megnyitása'); ?>">
                    <?php echo esc_html((string) $sz->szamlaszam); ?>
                </a>
            <?php endforeach; ?>

            <?php if (count($szamlak) > 3) : ?>
                <span class="sdh-szamlajel sdh-szamlajel--tobb" title="Összesen <?php echo (int) count($szamlak); ?> számla">+<?php echo (int) (count($szamlak) - 3); ?></span>
            <?php endif; ?>
        </span>
        <?php
    }

    /* =================================================================
     * A számla ablaka
     * ============================================================== */

    private static function jog_ellenorzes(bool $json = true): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            if ($json) {
                wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
            }

            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }
    }

    private static function munkalap(int $id): ?object
    {
        global $wpdb;

        $sor = $id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $id))
            : null;

        return is_object($sor) ? $sor : null;
    }

    private static function ugyfel(int $id): ?object
    {
        global $wpdb;

        $sor = $id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', $id))
            : null;

        return is_object($sor) ? $sor : null;
    }

    /** Készpénzes jellegű-e a fizetési mód (akkor a határidő a számla napja). */
    private static function azonnali(string $fizmod): bool
    {
        $f = mb_strtolower($fizmod, 'UTF-8');

        return $f === '' || (strpos($f, 'utal') === false && strpos($f, 'halaszt') === false);
    }

    private static function datum_mezo(string $nev, string $ertek, string $cimke): void
    {
        ?>
        <input type="text" name="<?php echo esc_attr($nev); ?>" id="szamla_<?php echo esc_attr($nev); ?>"
               class="sdh-pop sdh-pop--datum" readonly autocomplete="off" aria-label="<?php echo esc_attr($cimke); ?>"
               data-sdh-pop="datum" data-sdh-pop-adat="[]" placeholder="éééé-hh-nn"
               value="<?php echo esc_attr($ertek); ?>">
        <?php
    }

    /** A számla ablaka: vevő, tételek, fizetés – innen dönthető el, mi megy ki. */
    public static function ajax_urlap(): void
    {
        self::jog_ellenorzes(false);

        $munkalap = self::munkalap(isset($_GET['id']) ? (int) $_GET['id'] : 0);
        $sorozat  = isset($_GET['sorozat']) && sanitize_key(wp_unslash($_GET['sorozat'])) === 'sdh' ? 'sdh' : 'fo';

        if ($munkalap === null || (int) $munkalap->munkalap_szam <= 0) {
            echo '<h2 class="sdh-modal__cim">Számla</h2>'
                . '<div class="sdh-uzenet sdh-uzenet--hiba">Számla csak sorszámot kapott (számozott állapotú) munkalaphoz készíthető.</div>';
            wp_die();
        }

        $b       = self::beallitas();
        $elotag  = self::elotag($sorozat);
        $cim     = $sorozat === 'sdh' ? (string) $b['gomb_sdh'] : 'Számlázz.hu számla';
        $ugyfel  = self::ugyfel((int) $munkalap->ugyfel_id);
        $vevo    = self::vevo($ugyfel);
        $tetelek = self::tetelek((int) $munkalap->id);
        $szamlak = self::lista((int) $munkalap->id);
        $szam    = SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam);
        $fizmod  = trim((string) $munkalap->fizetesi_mod) !== '' ? (string) $munkalap->fizetesi_mod : (string) $b['alap_fizmod'];
        $modok   = array_values(array_unique(array_merge(SDH_Muhely_Munkalap::fizetesi_modok(), [$fizmod])));
        $ma      = current_time('Y-m-d');
        $hatar   = self::azonnali($fizmod) ? $ma : wp_date('Y-m-d', strtotime($ma . ' +' . (int) $b['hatarido_nap'] . ' days'));

        ?>
        <h2 class="sdh-modal__cim">
            <?php echo esc_html($cim); ?>
            <span class="sdh-modal__cim-megj">
                Munkalap <?php echo esc_html($szam); ?> ·
                <?php echo $elotag !== '' ? esc_html($elotag) . ' előtagú számlatömb' : 'a fiók alap számlatömbje'; ?>
            </span>
        </h2>
        <p class="sdh-modal__alcim">
            Itt döntöd el, mi kerül a számlára. A számlát a Számlázz.hu állítja ki és jelenti a NAV felé – a kiállítás végleges, javítani sztornóval lehet.
        </p>

        <?php if (!self::beallitva()) : ?>
            <div class="sdh-uzenet sdh-uzenet--hiba">
                Még nincs megadva a Számla Agent kulcs. Add meg itt: Beállítások → Számlázás – Számlázz.hu.
            </div>
        <?php endif; ?>

        <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-sdh-ajax-action="sdh_muhely_szamla_ment" data-sdh-szamlaurlap>
            <input type="hidden" name="munkalap_id" value="<?php echo (int) $munkalap->id; ?>">
            <input type="hidden" name="sorozat" value="<?php echo esc_attr($sorozat); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">

            <div class="sdh-ugyfelurlap sdh-szamlaurlap">
                <div class="sdh-szamlaurlap__felso">
                    <?php // --- Vevő ------------------------------------------------------- ?>
                    <fieldset class="sdh-szamlaurlap__doboz">
                        <legend>Vevő</legend>

                        <?php if ($ugyfel === null) : ?>
                            <p class="sdh-szamlaurlap__hiany">A munkalapnak nincs ügyfele.</p>
                        <?php else : ?>
                            <?php $v = $vevo['adat']; ?>
                            <p class="sdh-szamlaurlap__vevonev"><?php echo esc_html((string) $v['nev']); ?></p>
                            <p>
                                <?php echo esc_html(trim($v['irsz'] . ' ' . $v['telepules'] . ', ' . $v['cim'], ' ,')); ?>
                                <?php if ((string) $v['orszag'] !== 'Magyarország') : ?>
                                    · <?php echo esc_html((string) $v['orszag']); ?>
                                <?php endif; ?>
                            </p>
                            <p class="sdh-tabla__halvany">
                                <?php
                                echo esc_html(implode(' · ', array_filter([
                                    (string) $v['adoszam'] !== '' ? 'Adószám: ' . $v['adoszam'] : ((int) $v['adoalany'] === -1 ? 'Magánszemély' : 'Adószám nincs megadva'),
                                    (string) $v['email'],
                                    (string) $v['telefon'],
                                ])));
                                ?>
                            </p>

                            <?php if (isset($v['postazas'])) : ?>
                                <p class="sdh-tabla__halvany">
                                    Postázási cím: <?php echo esc_html($v['postazas']['irsz'] . ' ' . $v['postazas']['telepules'] . ', ' . $v['postazas']['cim']); ?>
                                </p>
                            <?php endif; ?>

                            <?php if ($vevo['hianyzik'] !== []) : ?>
                                <p class="sdh-szamlaurlap__hiany">
                                    Hiányzik a számlához: <?php echo esc_html(implode(', ', $vevo['hianyzik'])); ?>.
                                    Pótold az ügyfél adatlapján (Ügyfelek menü), aztán nyisd meg újra a számlát.
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </fieldset>

                    <?php // --- Fizetés és dátumok ------------------------------------------- ?>
                    <fieldset class="sdh-szamlaurlap__doboz">
                        <legend>Fizetés</legend>

                        <div class="sdh-ig">
                            <label for="szamla_fizmod">Fizetési mód</label>
                            <select name="fizmod" id="szamla_fizmod">
                                <?php foreach ($modok as $mod) : ?>
                                    <option value="<?php echo esc_attr($mod); ?>" <?php selected($fizmod, $mod); ?>><?php echo esc_html($mod); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sdh-ig">
                            <label for="szamla_teljesites">Teljesítés</label>
                            <?php self::datum_mezo('teljesites', $ma, 'Teljesítés dátuma'); ?>
                        </div>

                        <div class="sdh-ig">
                            <label for="szamla_hatarido">Fiz. határidő</label>
                            <?php self::datum_mezo('hatarido', $hatar, 'Fizetési határidő'); ?>
                        </div>

                        <div class="sdh-szamlaurlap__jelolok">
                            <label class="sdh-jelolo" title="A számlán „Fizetve" szerepel">
                                <input type="checkbox" name="fizetve" value="1" <?php checked((int) $munkalap->fizetve === 1 || self::azonnali($fizmod)); ?>>
                                Fizetve
                            </label>

                            <label class="sdh-jelolo" title="<?php echo esc_attr(($vevo['adat']['email'] ?? '') !== '' ? 'A Számlázz.hu elküldi a számlát az ügyfél e-mail-címére' : 'Az ügyfélnek nincs e-mail-címe'); ?>">
                                <input type="checkbox" name="email" value="1"
                                    <?php checked(!empty($b['email_kuldes']) && ($vevo['adat']['email'] ?? '') !== ''); ?>
                                    <?php disabled(($vevo['adat']['email'] ?? '') === ''); ?>>
                                E-mail az ügyfélnek
                            </label>
                        </div>
                    </fieldset>
                </div>

                <?php // --- Tételek ------------------------------------------------------- ?>
                <table class="sdh-tabla sdh-szamlaurlap__tetelek">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Megnevezés</th>
                            <th class="sdh-tabla__szam">Menny.</th>
                            <th class="sdh-tabla__szam">Nettó egységár</th>
                            <th>Áfa</th>
                            <th class="sdh-tabla__szam">Nettó</th>
                            <th class="sdh-tabla__szam">Bruttó</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($tetelek === []) : ?>
                        <tr><td colspan="7" class="sdh-tabla__ures">A munkalapon nincs tétel. Vegyél fel szolgáltatást, terméket vagy bevizsgálási díjat.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($tetelek as $t) : ?>
                        <?php
                        $s          = self::tetel_sor($t);
                        $szamlazva  = (string) $t->szamla !== '';
                        $fajta      = (string) $t->forras === self::BEVIZSGALAS ? 'Bevizsgálási díj' : ((string) $t->tipus === 'termek' ? 'Termék' : 'Szolgáltatás');
                        ?>
                        <tr class="<?php echo $szamlazva ? 'sdh-sor--inaktiv' : ''; ?>">
                            <td>
                                <input type="checkbox" name="tetel[]" value="<?php echo (int) $t->id; ?>" data-sdh-szamla-tetel
                                       data-netto="<?php echo esc_attr((string) $s['netto']); ?>" data-brutto="<?php echo esc_attr((string) $s['brutto']); ?>"
                                       aria-label="<?php echo esc_attr($s['megnevezes'] . ' a számlára'); ?>"
                                    <?php checked(!$szamlazva); ?>>
                            </td>
                            <td class="sdh-tabla__nev">
                                <?php echo esc_html((string) $s['megnevezes']); ?>
                                <span class="sdh-termval__kat"><?php echo esc_html($fajta); ?></span>
                                <?php if ($szamlazva) : ?>
                                    <span class="sdh-szamlajel" title="Ez a tétel már szerepel ezen a számlán"><?php echo esc_html((string) $t->szamla); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="sdh-tabla__szam"><?php echo esc_html(SDH_Muhely_Termek::menny((float) $s['mennyiseg']) . ' ' . $s['me']); ?></td>
                            <td class="sdh-tabla__szam"><?php echo esc_html(number_format((float) $s['netto_egysegar'], 2, ',', "\u{00a0}")); ?></td>
                            <td><?php echo esc_html(is_numeric($s['afakulcs']) ? $s['afakulcs'] . '%' : (string) $s['afakulcs']); ?></td>
                            <td class="sdh-tabla__szam"><?php echo esc_html(self::penz((float) $s['netto'])); ?></td>
                            <td class="sdh-tabla__szam"><strong><?php echo esc_html(self::penz((float) $s['brutto'])); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td></td>
                            <td class="sdh-tabla__szumma" colspan="4">A számla végösszege (a kipipált tételek)</td>
                            <td class="sdh-tabla__szam"><output data-sdh-szamla-ossz="netto">0</output></td>
                            <td class="sdh-tabla__szam"><strong><output data-sdh-szamla-ossz="brutto">0</output> Ft</strong></td>
                        </tr>
                    </tfoot>
                </table>

                <div class="sdh-ig sdh-szamlaurlap__megj">
                    <label for="szamla_megjegyzes_mezo">Megjegyzés</label>
                    <input type="text" name="megjegyzes" id="szamla_megjegyzes_mezo" maxlength="500"
                           value="<?php echo esc_attr(self::megjegyzes($munkalap)); ?>">
                </div>

                <?php if ($szamlak !== []) : ?>
                    <p class="sdh-szamlaurlap__eddigi">
                        Ehhez a munkalaphoz már készült számla:
                        <?php foreach ($szamlak as $sz) : ?>
                            <a class="sdh-szamlajel" href="<?php echo esc_url(self::pdf_url((int) $sz->id)); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) $sz->szamlaszam); ?></a>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>

                <div class="sdh-urlap__lablec">
                    <button type="submit" class="sdh-gomb sdh-gomb--elsodleges"
                        <?php disabled(!self::beallitva() || $vevo['hianyzik'] !== [] || $tetelek === []); ?>>
                        Számla kiállítása
                    </button>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-szamla-elonezet
                            title="PDF-előnézet a Számlázz.hu-tól – számla NEM készül"
                        <?php disabled(!self::beallitva() || $vevo['hianyzik'] !== [] || $tetelek === []); ?>>
                        Előnézet (PDF)
                    </button>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                </div>
            </div>
        </form>
        <?php

        wp_die();
    }

    /**
     * A beküldött számlaűrlapból a kérés adatai – a kiállítás és az előnézet közös része.
     *
     * @return array{munkalap: object, sorozat: string, fej: array<string, mixed>, vevo: array<string, mixed>, sorok: array<int, array<string, mixed>>, tetel_idk: array<int, int>}|string
     */
    private static function kerelem()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a hívó ellenőrizte.
        if (!self::beallitva()) {
            return 'Nincs megadva a Számla Agent kulcs (Beállítások → Számlázás – Számlázz.hu).';
        }

        $munkalap = self::munkalap(isset($_POST['munkalap_id']) ? (int) $_POST['munkalap_id'] : 0);

        if ($munkalap === null || (int) $munkalap->munkalap_szam <= 0) {
            return 'Számla csak sorszámot kapott munkalaphoz készíthető.';
        }

        $vevo = self::vevo(self::ugyfel((int) $munkalap->ugyfel_id));

        if ($vevo['hianyzik'] !== []) {
            return 'A vevő adatai hiányosak: ' . implode(', ', $vevo['hianyzik']) . '.';
        }

        $kert = isset($_POST['tetel']) && is_array($_POST['tetel']) ? array_map('intval', $_POST['tetel']) : [];
        $sorok = [];
        $idk   = [];

        // Csak ennek a munkalapnak a tételei kerülhetnek a számlára.
        foreach (self::tetelek((int) $munkalap->id) as $t) {
            if (in_array((int) $t->id, $kert, true)) {
                $sorok[] = self::tetel_sor($t);
                $idk[]   = (int) $t->id;
            }
        }

        if ($sorok === []) {
            return 'Pipálj ki legalább egy tételt – üres számla nem állítható ki.';
        }

        $szoveg = static fn (string $k, int $hossz): string => isset($_POST[$k]) && is_scalar($_POST[$k])
            ? mb_substr(sanitize_text_field(wp_unslash((string) $_POST[$k])), 0, $hossz)
            : '';
        $datum  = static fn (string $k, string $alap): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $szoveg($k, 10)) === 1 ? $szoveg($k, 10) : $alap;

        $ma     = current_time('Y-m-d');
        $fizmod = $szoveg('fizmod', 40) !== '' ? $szoveg('fizmod', 40) : (string) self::beallitas()['alap_fizmod'];
        $hatar  = $datum('hatarido', $ma);

        return [
            'munkalap'  => $munkalap,
            'sorozat'   => $szoveg('sorozat', 5) === 'sdh' ? 'sdh' : 'fo',
            'fej'       => [
                'kelt'       => $ma,
                'teljesites' => $datum('teljesites', $ma),
                'hatarido'   => $hatar < $ma ? $ma : $hatar,
                'fizmod'     => $fizmod,
                'fizetve'    => !empty($_POST['fizetve']),
                'email'      => !empty($_POST['email']),
                'megjegyzes' => $szoveg('megjegyzes', 500),
                'rendeles'   => SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam),
            ],
            'vevo'      => $vevo['adat'],
            'sorok'     => $sorok,
            'tetel_idk' => $idk,
        ];
        // phpcs:enable
    }

    /** Előnézet: PDF a Számlázz.hu-tól, számla NEM készül. */
    public static function ajax_elonezet(): void
    {
        self::jog_ellenorzes();

        $k = self::kerelem();

        if (!is_array($k)) {
            wp_send_json_error(['uzenet' => $k]);
        }

        $k['fej']['elonezet'] = true;
        $k['fej']['email']    = false;

        $valasz = self::agent_kuld('action-xmlagentxmlfile', self::xml($k['sorozat'], $k['fej'], $k['vevo'], $k['sorok']));

        if (!$valasz['ok'] || $valasz['pdf'] === '') {
            wp_send_json_error(['uzenet' => $valasz['ok'] ? 'A Számlázz.hu nem küldött előnézetet.' : self::hiba_szoveg($valasz)]);
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="szamla-elonezet.pdf"');
        header('Content-Length: ' . strlen($valasz['pdf']));
        echo $valasz['pdf']; // phpcs:ignore WordPress.Security.EscapeOutput -- PDF.
        exit;
    }

    /** A számla kiállítása – csak a számla ablakának „Számla kiállítása" gombjára. */
    public static function ajax_kiallit(): void
    {
        global $wpdb;

        self::jog_ellenorzes();

        $k = self::kerelem();

        if (!is_array($k)) {
            wp_send_json_error(['uzenet' => $k]);
        }

        $munkalap = $k['munkalap'];
        $valasz   = self::agent_kuld('action-xmlagentxmlfile', self::xml($k['sorozat'], $k['fej'], $k['vevo'], $k['sorok']));

        if (!$valasz['ok']) {
            wp_send_json_error(['uzenet' => self::hiba_szoveg($valasz)]);
        }

        if ($valasz['szamlaszam'] === '') {
            wp_send_json_error(['uzenet' => 'A Számlázz.hu nem adott vissza számlaszámot. Nézd meg a fiókodban, elkészült-e a számla, mielőtt újra próbálod.']);
        }

        $netto  = $valasz['netto'] > 0 ? $valasz['netto'] : array_sum(array_column($k['sorok'], 'netto'));
        $brutto = $valasz['brutto'] > 0 ? $valasz['brutto'] : array_sum(array_column($k['sorok'], 'brutto'));

        $wpdb->insert(self::tabla(), [
            'munkalap_id' => (int) $munkalap->id,
            'ugyfel_id'   => (int) $munkalap->ugyfel_id,
            'szamlaszam'  => mb_substr($valasz['szamlaszam'], 0, 60),
            'sorozat'     => $k['sorozat'],
            'netto'       => round((float) $netto, 2),
            'brutto'      => round((float) $brutto, 2),
            'fizetve'     => $k['fej']['fizetve'] ? 1 : 0,
            'fizmod'      => mb_substr((string) $k['fej']['fizmod'], 0, 40),
            'kelt'        => (string) $k['fej']['kelt'],
            'teljesites'  => (string) $k['fej']['teljesites'],
            'hatarido'    => (string) $k['fej']['hatarido'],
            'pdf'         => self::pdf_ment($valasz['pdf']),
            'tetelek'     => (string) wp_json_encode($k['sorok']),
            'vevo'        => (string) wp_json_encode($k['vevo']),
            'felhasznalo' => get_current_user_id(),
            'letrehozva'  => current_time('mysql'),
        ]);

        $szamla_id = (int) $wpdb->insert_id;

        // A számlázott tételek megjegyzik a számla számát.
        foreach ($k['tetel_idk'] as $tetel_id) {
            $wpdb->update(SDH_Muhely_Tetel::tabla(), ['szamla' => mb_substr($valasz['szamlaszam'], 0, 40)], ['id' => $tetel_id, 'munkalap_id' => (int) $munkalap->id]);
        }

        do_action('sdh_muhely_szamla_kiallitva', $szamla_id, (int) $munkalap->id, $valasz['szamlaszam']);

        wp_send_json_success([
            'id'         => $szamla_id,
            'szamlaszam' => $valasz['szamlaszam'],
            'brutto'     => round((float) $brutto, 2),
            'pdf'        => $szamla_id > 0 ? self::pdf_url($szamla_id) : '',
            'email'      => $k['fej']['email'] && (string) $k['vevo']['email'] !== '',
        ]);
    }

    /* =================================================================
     * PDF tárolás
     * ============================================================== */

    /** A tárolómappa abszolút útja; létrehozza és védi, ha még nincs. */
    private static function mappa(): string
    {
        $feltoltes = wp_upload_dir();
        $mappa     = trailingslashit($feltoltes['basedir']) . self::MAPPA;

        if (!is_dir($mappa)) {
            wp_mkdir_p($mappa);
        }

        $vedelem = [
            'index.php' => "<?php\n// Csend az aranyat ér.\n",
            '.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
        ];

        foreach ($vedelem as $nev => $tartalom) {
            if (!file_exists($mappa . '/' . $nev)) {
                file_put_contents($mappa . '/' . $nev, $tartalom);
            }
        }

        return $mappa;
    }

    /** A PDF elmentése kitalálhatatlan néven. Üres, ha nem sikerült (a számla attól még él). */
    private static function pdf_ment(string $pdf): string
    {
        if ($pdf === '') {
            return '';
        }

        $nev = bin2hex(random_bytes(16));

        return file_put_contents(self::mappa() . '/' . $nev . '.pdf', $pdf) !== false ? $nev : '';
    }

    /** A számla PDF-je: a helyi másolat, vagy – ha nincs – újra lekérve a Számlázz.hu-tól. */
    public static function ajax_pdf(): void
    {
        global $wpdb;

        self::jog_ellenorzes(false);

        $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $szamla = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id)) : null;

        if (!is_object($szamla)) {
            status_header(404);
            wp_die('Nincs ilyen számla.');
        }

        $pdf = '';

        if (preg_match('/^[a-f0-9]{32}$/', (string) $szamla->pdf) === 1) {
            $ut  = self::mappa() . '/' . $szamla->pdf . '.pdf';
            $pdf = is_readable($ut) ? (string) file_get_contents($ut) : '';
        }

        if ($pdf === '' && self::beallitva()) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<xmlszamlapdf xmlns="http://www.szamlazz.hu/xmlszamlapdf" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
                . self::elemek(['szamlaagentkulcs' => (string) self::beallitas()['kulcs'], 'szamlaszam' => (string) $szamla->szamlaszam, 'valaszVerzio' => 1], '  ')
                . "</xmlszamlapdf>\n";

            $valasz = self::agent_kuld('action-szamla_agent_pdf', $xml);
            $pdf    = $valasz['pdf'];

            if ($pdf !== '') {
                $wpdb->update(self::tabla(), ['pdf' => self::pdf_ment($pdf)], ['id' => $id]);
            }
        }

        if ($pdf === '') {
            status_header(404);
            wp_die('A számla PDF-je most nem érhető el. A Számlázz.hu fiókodban megtalálod: ' . esc_html((string) $szamla->szamlaszam));
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . sanitize_file_name((string) $szamla->szamlaszam) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Robots-Tag: noindex, nofollow');
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- PDF.
        exit;
    }

    /* =================================================================
     * Kapcsolat ellenőrzése
     * ============================================================== */

    /**
     * Számla nélkül ellenőrzi a kulcsot: egy biztosan nem létező számla adatait
     * kéri le. Ha a Számlázz.hu a belépést utasítja el (3-as kód), a kulcs rossz;
     * ha azt feleli, hogy nincs ilyen számla, a kulcs jó.
     */
    public static function ajax_kapcsolat(): void
    {
        self::jog_ellenorzes();

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['uzenet' => 'Ehhez rendszergazdai jog kell.'], 403);
        }

        if (!self::beallitva()) {
            wp_send_json_error(['uzenet' => 'Előbb mentsd el a kulcsot.']);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<xmlszamlaxml xmlns="http://www.szamlazz.hu/xmlszamlaxml" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
            . self::elemek(['szamlaagentkulcs' => (string) self::beallitas()['kulcs'], 'szamlaszam' => 'SDH-KAPCSOLAT-ELLENORZES'], '  ')
            . "</xmlszamlaxml>\n";

        $valasz = self::agent_kuld('action-szamla_agent_xml', $xml);

        if ($valasz['kod'] === '' && !$valasz['ok']) {
            wp_send_json_error(['uzenet' => $valasz['uzenet']]);
        }

        // Ezek a kódok a kapcsolat vagy a fiók hibáját jelzik; minden más válasz
        // (jellemzően „nincs ilyen számla") azt jelenti, hogy a kulcsot elfogadta.
        if (in_array($valasz['kod'], ['1', '3', '53', '57', '135', '136', '164'], true)) {
            wp_send_json_error(['uzenet' => self::hiba_szoveg($valasz)]);
        }

        wp_send_json_success([
            'uzenet' => 'A Számlázz.hu elfogadta a kulcsot – a kapcsolat él. (Számla nem készült.)',
            'valasz' => $valasz['kod'] !== '' ? $valasz['kod'] . ': ' . $valasz['uzenet'] : '',
        ]);
    }
}
