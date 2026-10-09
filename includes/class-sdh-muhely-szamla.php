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
 * HELYI NYOMTATVÁNY (API nélkül): a harmadik gomb a munkalap tételeiből
 * helyben készít letölthető, nyomtatható PDF-et a szerviz saját logójával és
 * adataival – ez az a papír, amit a kolléga a kész termék mellé tesz, és
 * amiből később a számla készül. NEM számla: a címe választható
 * (Számla-előkészítő, Díjbekérő, Elszámolás, Átadási bizonylat), rajta áll,
 * hogy nem minősül számlának, és a sorszáma (előtag-év-munkalapszám) saját
 * előtagot kap, amely nem egyezhet a számlatömbök előtagjával – így nem
 * téveszthető össze a NAV felé jelentett számlákkal. Offline is működik.
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

        // Helyi nyomtatvány (számla-előkészítő): PDF a szerveren, API nélkül.
        add_action('wp_ajax_sdh_muhely_szamla_nyomtatvany', [self::class, 'ajax_nyomtatvany']);

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
            // Helyi nyomtatvány
            'nyomt_cim'          => 'elokeszito',
            'nyomt_elotag'       => 'SDE',
            'logo'               => '',
            'kiallito_nev'       => '',
            'kiallito_cim1'      => '',
            'kiallito_cim2'      => '',
            'kiallito_adoszam'   => '',
            'bank_nev'           => '',
            'bankszamla'         => '',
            'levelezes'          => '',
            'telefon'            => '',
        ];

        // A kiállító kezdőértékei adatfájlból jönnek (nem a kódból), és a Beállításokban átírhatók.
        $fajl   = SDH_MUHELY_DIR . 'data/kiallito.json';
        $kezdo  = is_readable($fajl) ? json_decode((string) file_get_contents($fajl), true) : [];
        $alap   = array_merge($alap, is_array($kezdo) ? array_intersect_key(array_map('strval', $kezdo), $alap) : []);
        $mentett = get_option(self::OPTION, []);

        return array_merge($alap, is_array($mentett) ? array_intersect_key($mentett, $alap) : []);
    }

    /**
     * A helyi nyomtatvány választható címei. Szándékosan nincs köztük „Számla":
     * ez a bizonylat nem számla, és nem is nézhet ki annak.
     *
     * @return array<string, string> kulcs => cím
     */
    public static function nyomtatvany_cimek(): array
    {
        return [
            'elokeszito' => 'Számla-előkészítő',
            'dijbekero'  => 'Díjbekérő',
            'elszamolas' => 'Elszámolás',
            'atadas'     => 'Átadási bizonylat',
        ];
    }

    public static function nyomtatvany_cim(): string
    {
        $cimek = self::nyomtatvany_cimek();

        return $cimek[(string) self::beallitas()['nyomt_cim']] ?? $cimek['elokeszito'];
    }

    /** A helyi nyomtatvány sorszáma: előtag-év-munkalapszám. */
    public static function nyomtatvany_szam(object $munkalap): string
    {
        return self::beallitas()['nyomt_elotag'] . '-' . current_time('Y') . '-' . (int) $munkalap->munkalap_szam;
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

            <h3 class="sdh-doboz__alcim">Helyi nyomtatvány (API nélkül)</h3>

            <p class="sdh-sugo">
                A munkalap harmadik gombja helyben készít letölthető, nyomtatható PDF-et a tételekből, a szerviz
                logójával és adataival – internet és Számlázz.hu nélkül. Ez az a papír, ami a kész termék mellé kerül,
                és amiből később a számla készül. <strong>Nem számla</strong>: ez rajta is áll, és a sorszáma saját előtagot kap.
            </p>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="szamla_nyomt_cim">A nyomtatvány címe</label>
                    <select name="szamla_nyomt_cim" id="szamla_nyomt_cim">
                        <?php foreach (self::nyomtatvany_cimek() as $kulcs => $cim) : ?>
                            <option value="<?php echo esc_attr($kulcs); ?>" <?php selected((string) $b['nyomt_cim'], $kulcs); ?>><?php echo esc_html($cim); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sdh-mezo__sugo">Ez a gomb felirata is a munkalapon.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_nyomt_elotag">Sorszám-előtag</label>
                    <input type="text" name="szamla_nyomt_elotag" id="szamla_nyomt_elotag" maxlength="6"
                           value="<?php echo esc_attr((string) $b['nyomt_elotag']); ?>">
                    <span class="sdh-mezo__sugo">
                        A sorszám: előtag-év-munkalapszám (pl. <?php echo esc_html($b['nyomt_elotag'] . '-' . current_time('Y')); ?>-1747).
                        Nem egyezhet a számlatömbök előtagjával, hogy a nyomtatvány ne legyen összetéveszthető a számlákkal.
                    </span>
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_kiallito_nev">Kiállító neve</label>
                    <input type="text" name="szamla_kiallito_nev" id="szamla_kiallito_nev" maxlength="120" value="<?php echo esc_attr((string) $b['kiallito_nev']); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_kiallito_adoszam">Adószám</label>
                    <input type="text" name="szamla_kiallito_adoszam" id="szamla_kiallito_adoszam" maxlength="60" value="<?php echo esc_attr((string) $b['kiallito_adoszam']); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_kiallito_cim1">Cím – irányítószám, település</label>
                    <input type="text" name="szamla_kiallito_cim1" id="szamla_kiallito_cim1" maxlength="120" value="<?php echo esc_attr((string) $b['kiallito_cim1']); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_kiallito_cim2">Cím – utca, házszám</label>
                    <input type="text" name="szamla_kiallito_cim2" id="szamla_kiallito_cim2" maxlength="120" value="<?php echo esc_attr((string) $b['kiallito_cim2']); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_bank_nev">Bank neve</label>
                    <input type="text" name="szamla_bank_nev" id="szamla_bank_nev" maxlength="80" value="<?php echo esc_attr((string) $b['bank_nev']); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_bankszamla">Bankszámlaszám</label>
                    <input type="text" name="szamla_bankszamla" id="szamla_bankszamla" maxlength="60" value="<?php echo esc_attr((string) $b['bankszamla']); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_levelezes">Levelezési cím (lábléc)</label>
                    <input type="text" name="szamla_levelezes" id="szamla_levelezes" maxlength="160" value="<?php echo esc_attr((string) $b['levelezes']); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="szamla_telefon">Telefon (lábléc)</label>
                    <input type="text" name="szamla_telefon" id="szamla_telefon" maxlength="60" value="<?php echo esc_attr((string) $b['telefon']); ?>">
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="szamla_logo">Logó a nyomtatványon</label>
                    <input type="text" name="szamla_logo" id="szamla_logo" maxlength="300" value="<?php echo esc_attr((string) $b['logo']); ?>"
                           placeholder="(a beépített logó)">
                    <span class="sdh-mezo__sugo">
                        Üresen a pluginnal szállított logó. Másikhoz töltsd fel a Médiatárba (PNG vagy JPG), és másold ide a fájl címét.
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

        // Helyi nyomtatvány
        $b['nyomt_cim'] = isset(self::nyomtatvany_cimek()[$szoveg('szamla_nyomt_cim', 20)]) ? $szoveg('szamla_nyomt_cim', 20) : 'elokeszito';

        // Az előtag nem egyezhet a számlatömbökével: a nyomtatvány sorszáma ne
        // legyen összetéveszthető egy valódi számla számával.
        $nyomt_elotag = strtoupper(mb_substr($elotag('szamla_nyomt_elotag'), 0, 6));

        if ($nyomt_elotag === '' || in_array($nyomt_elotag, array_map('strtoupper', [(string) $b['elotag_fo'], (string) $b['elotag_sdh']]), true)) {
            $nyomt_elotag = ($nyomt_elotag === '' ? 'SD' : mb_substr($nyomt_elotag, 0, 5)) . 'E';
        }

        $b['nyomt_elotag'] = $nyomt_elotag;
        $b['logo']         = isset($_POST['szamla_logo']) ? esc_url_raw(trim((string) wp_unslash($_POST['szamla_logo']))) : '';

        foreach (['kiallito_nev' => 120, 'kiallito_cim1' => 120, 'kiallito_cim2' => 120, 'kiallito_adoszam' => 60, 'bank_nev' => 80, 'bankszamla' => 60, 'levelezes' => 160, 'telefon' => 60] as $mezo => $hossz) {
            if (isset($_POST['szamla_' . $mezo])) {
                $b[$mezo] = $szoveg('szamla_' . $mezo, $hossz);
            }
        }
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

            <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-szamla="helyi"
                    title="Letölthető, nyomtatható PDF a tételekből – helyben készül, internet és Számlázz.hu nélkül. Nem számla.">
                <?php echo esc_html(self::nyomtatvany_cim()); ?> (PDF)
            </button>

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
        $kert     = isset($_GET['sorozat']) ? sanitize_key(wp_unslash($_GET['sorozat'])) : 'fo';
        $sorozat  = in_array($kert, ['sdh', 'helyi'], true) ? $kert : 'fo';
        $helyi    = $sorozat === 'helyi';

        if ($munkalap === null || (int) $munkalap->munkalap_szam <= 0) {
            echo '<h2 class="sdh-modal__cim">Számla</h2>'
                . '<div class="sdh-uzenet sdh-uzenet--hiba">Számla csak sorszámot kapott (számozott állapotú) munkalaphoz készíthető.</div>';
            wp_die();
        }

        $b       = self::beallitas();
        $elotag  = self::elotag($sorozat);
        $cim     = $helyi ? self::nyomtatvany_cim() : ($sorozat === 'sdh' ? (string) $b['gomb_sdh'] : 'Számlázz.hu számla');
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
                <?php
                if ($helyi) {
                    echo 'sorszám: ' . esc_html(self::nyomtatvany_szam($munkalap));
                } else {
                    echo $elotag !== '' ? esc_html($elotag) . ' előtagú számlatömb' : 'a fiók alap számlatömbje';
                }
                ?>
            </span>
        </h2>
        <p class="sdh-modal__alcim">
            <?php if ($helyi) : ?>
                Letölthető, nyomtatható PDF a kipipált tételekből – helyben készül, internet nélkül is. Nem számla: ez a papír megy a kész termék mellé, a számla ebből készül.
            <?php else : ?>
                Itt döntöd el, mi kerül a számlára. A számlát a Számlázz.hu állítja ki és jelenti a NAV felé – a kiállítás végleges, javítani sztornóval lehet.
            <?php endif; ?>
        </p>

        <?php if (!$helyi && !self::beallitva()) : ?>
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
                                    Hiányzik a vevő adataiból: <?php echo esc_html(implode(', ', $vevo['hianyzik'])); ?>.
                                    <?php echo $helyi ? 'A nyomtatvány így is elkészül; a számlához pótolni kell az ügyfél adatlapján.' : 'Pótold az ügyfél adatlapján (Ügyfelek menü), aztán nyisd meg újra a számlát.'; ?>
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

                        <div class="sdh-szamlaurlap__jelolok"<?php echo $helyi ? ' hidden' : ''; ?>>
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
                            <td class="sdh-tabla__szumma" colspan="4">Végösszeg (a kipipált tételek)</td>
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
                    <?php if ($helyi) : ?>
                        <?php // Nincs beküldés: a PDF a szerveren készül, és új lapon nyílik (app.js szamlaPdf). ?>
                        <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-szamla-kuld data-sdh-szamla-nyomtat
                                data-fajlnev="<?php echo esc_attr(self::nyomtatvany_szam($munkalap) . '.pdf'); ?>"
                            <?php disabled($ugyfel === null || $tetelek === []); ?>>
                            PDF megnyitása
                        </button>
                        <span class="sdh-szamlaurlap__letoltes" data-sdh-szamla-letoltes></span>
                    <?php else : ?>
                        <button type="submit" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-szamla-kuld
                            <?php disabled(!self::beallitva() || $vevo['hianyzik'] !== [] || $tetelek === []); ?>>
                            Számla kiállítása
                        </button>
                        <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-szamla-elonezet
                                title="PDF-előnézet a Számlázz.hu-tól – számla NEM készül"
                            <?php disabled(!self::beallitva() || $vevo['hianyzik'] !== [] || $tetelek === []); ?>>
                            Előnézet (PDF)
                        </button>
                    <?php endif; ?>
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
    private static function kerelem(bool $agent = true)
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a hívó ellenőrizte.
        if ($agent && !self::beallitva()) {
            return 'Nincs megadva a Számla Agent kulcs (Beállítások → Számlázás – Számlázz.hu).';
        }

        $munkalap = self::munkalap(isset($_POST['munkalap_id']) ? (int) $_POST['munkalap_id'] : 0);

        if ($munkalap === null || (int) $munkalap->munkalap_szam <= 0) {
            return 'Számla csak sorszámot kapott munkalaphoz készíthető.';
        }

        $vevo = self::vevo(self::ugyfel((int) $munkalap->ugyfel_id));

        // A helyi nyomtatvány hiányos címmel is elkészül (a helyszínen nincs mindig meg minden adat).
        if ($agent ? $vevo['hianyzik'] !== [] : $vevo['adat'] === []) {
            return 'A vevő adatai hiányosak: ' . implode(', ', $vevo['hianyzik']) . '.';
        }

        $kert = isset($_POST['tetel']) && is_array($_POST['tetel']) ? array_map('intval', $_POST['tetel']) : [];
        $sorok = [];
        $idk   = [];

        // Csak ennek a munkalapnak a tételei kerülhetnek a számlára.
        foreach (self::tetelek((int) $munkalap->id) as $t) {
            if (in_array((int) $t->id, $kert, true)) {
                $sorok[] = self::tetel_sor($t) + ['brutto_egysegar' => (float) $t->brutto_ar * (1 - min(100.0, max(0.0, (float) $t->kedvezmeny)) / 100)];
                $idk[]   = (int) $t->id;
            }
        }

        if ($sorok === []) {
            return 'Pipálj ki legalább egy tételt.';
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
            'sorozat'   => in_array($szoveg('sorozat', 5), ['sdh', 'helyi'], true) ? $szoveg('sorozat', 5) : 'fo',
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

        if ($k['sorozat'] === 'helyi') {
            wp_send_json_error(['uzenet' => 'A helyi nyomtatvány nem számla – a „PDF megnyitása" gombbal készül.']);
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
     * Helyi nyomtatvány (számla-előkészítő) – PDF, API nélkül
     * ============================================================== */

    /** A logó helyi fájlja: a beállított médiafájl, különben a pluginnal szállított. */
    private static function logo_fajl(): string
    {
        $url = trim((string) self::beallitas()['logo']);

        if ($url !== '') {
            $feltoltes = wp_upload_dir();
            $alap      = trailingslashit((string) $feltoltes['baseurl']);

            // Csak a saját feltöltési mappából olvasunk – távoli címet a PDF-készítő nem tölt le.
            if (strpos($url, $alap) === 0) {
                $ut = realpath(trailingslashit((string) $feltoltes['basedir']) . ltrim(rawurldecode(substr($url, strlen($alap))), '/'));

                if ($ut !== false && strpos($ut, (string) realpath((string) $feltoltes['basedir'])) === 0
                    && is_readable($ut) && preg_match('/\.(png|jpe?g)$/i', $ut) === 1) {
                    return $ut;
                }
            }
        }

        $beepitett = SDH_MUHELY_DIR . 'assets/logo-nyomtatvany.png';

        return is_readable($beepitett) ? $beepitett : '';
    }

    /** Forintösszeg a nyomtatványra: egész, ezres tagolással (sima szóközzel – a betűkészlet miatt). */
    private static function ft(float $osszeg): string
    {
        return number_format(round($osszeg), 0, ',', ' ');
    }

    /**
     * A nyomtatvány sorai egész forintra: a bruttó a munkalap szerinti (az a
     * mérvadó, azt fizeti az ügyfél), a nettó és az áfa abból adódik.
     *
     * @param array<int, array<string, mixed>> $sorok tetel_sor() eredményei, `brutto_egysegar`-ral
     * @return array{sorok: array<int, array<string, mixed>>, netto: float, afa: float, brutto: float, kulcsok: array<string, float>}
     */
    public static function nyomtatvany_sorok(array $sorok): array
    {
        $ki      = [];
        $ossz    = ['netto' => 0.0, 'afa' => 0.0, 'brutto' => 0.0];
        $kulcsok = [];

        foreach ($sorok as $s) {
            $szazalek = is_numeric($s['afakulcs']) ? (float) $s['afakulcs'] : 0.0;
            $menny    = (float) $s['mennyiseg'];
            $brutto   = round((float) ($s['brutto_egysegar'] ?? 0) * $menny);
            $netto    = round($brutto / (1 + $szazalek / 100));
            $afa      = $brutto - $netto;
            $kulcs    = is_numeric($s['afakulcs']) ? $s['afakulcs'] . '%' : (string) $s['afakulcs'];

            $ki[] = [
                'megnevezes' => (string) $s['megnevezes'],
                'mennyiseg'  => SDH_Muhely_Termek::menny($menny) . ' ' . $s['me'],
                'egysegar'   => $menny > 0 ? round($netto / $menny) : $netto,
                'netto'      => $netto,
                'kulcs'      => $kulcs,
                'afa'        => $afa,
                'brutto'     => $brutto,
            ];

            $ossz['netto']  += $netto;
            $ossz['afa']    += $afa;
            $ossz['brutto'] += $brutto;
            $kulcsok[$kulcs] = ($kulcsok[$kulcs] ?? 0.0) + $afa;
        }

        return ['sorok' => $ki, 'netto' => $ossz['netto'], 'afa' => $ossz['afa'], 'brutto' => $ossz['brutto'], 'kulcsok' => $kulcsok];
    }

    /**
     * A nyomtatvány PDF-je. Elrendezés: fent a logó, a kiállító és a bank; a
     * cím és a sorszám; a vevő és a fizetési adatok; a tételek táblázata; a
     * végösszeg; a láblécben az elérhetőség. Minden oldalon ott áll, hogy nem számla.
     *
     * @param array<string, mixed> $k A kerelem(false) eredménye.
     */
    public static function nyomtatvany_pdf(array $k): string
    {
        require_once SDH_MUHELY_DIR . 'includes/lib/sdh-pdf.php';

        $b       = self::beallitas();
        $cim     = self::nyomtatvany_cim();
        $szam    = self::nyomtatvany_szam($k['munkalap']);
        $t       = self::nyomtatvany_sorok($k['sorok']);
        $vevo    = $k['vevo'];
        $fej     = $k['fej'];
        $bal     = 12.4;
        $jobb    = 198.0;
        $szeles  = $jobb - $bal;
        $szin    = [201, 60, 54];     // kiemelő (a logó pirosának tompított árnyalata)
        $datum   = static fn (string $iso): string => str_replace('-', '.', $iso) . '.';
        $kiallito = (string) $b['kiallito_nev'] !== '' ? (string) $b['kiallito_nev'] : get_bloginfo('name');

        $pdf = new SDH_Muhely_Pdf('P', 'mm', 'A4');
        $pdf->SetCompression(true);
        $pdf->SetTitle($cim . ' ' . $szam, true);
        $pdf->SetAuthor($kiallito, true);
        $pdf->SetCreator('SDH Műhely', true);
        $pdf->AddFont('DejaVu', '', 'DejaVuSansCondensed.ttf', true);
        $pdf->AddFont('DejaVu', 'B', 'DejaVuSansCondensed-Bold.ttf', true);
        $pdf->SetMargins($bal, 18, 210 - $jobb);
        $pdf->SetAutoPageBreak(true, 34);
        $pdf->AliasNbPages();
        $pdf->sdh_lab = array_values(array_filter([
            (string) $b['levelezes'] !== '' ? 'Levelezés: ' . $b['levelezes'] : '',
            (string) $b['telefon'] !== '' ? 'Telefon: ' . $b['telefon'] : '',
        ]));
        $pdf->sdh_keszitette = 'A bizonylatot készítette: ' . rtrim($kiallito, '. ') . '. – Nem minősül számlának.';
        $pdf->AddPage();
        $pdf->SetTextColor(20, 20, 20);

        // --- Fej: logó, kiállító, bank ---------------------------------------
        $logo = self::logo_fajl();

        if ($logo !== '') {
            $pdf->Image($logo, $bal, 18.5, 32);
        }

        $sor = static function (float $x, float $y, array $reszek) use ($pdf): void {
            $pdf->SetXY($x, $y);

            foreach ($reszek as [$stilus, $szoveg]) {
                $pdf->SetFont('DejaVu', $stilus, 9);
                $pdf->Cell($pdf->GetStringWidth($szoveg) + 0.6, 4.3, $szoveg, 0, 0, 'L');
            }
        };

        $y = 18.2;

        foreach (array_filter([
            [['B', $kiallito]],
            (string) $b['kiallito_cim1'] !== '' ? [['', (string) $b['kiallito_cim1']]] : null,
            (string) $b['kiallito_cim2'] !== '' ? [['', (string) $b['kiallito_cim2']]] : null,
            (string) $b['kiallito_adoszam'] !== '' ? [['B', 'Adószám:'], ['', ' ' . $b['kiallito_adoszam']]] : null,
        ]) as $reszek) {
            $sor(56.5, $y, $reszek);
            $y += 4.3;
        }

        $y = 18.2;

        foreach (array_filter([
            (string) $b['bank_nev'] !== '' ? [['B', 'Bank neve:'], ['', ' ' . $b['bank_nev']]] : null,
            (string) $b['bankszamla'] !== '' ? [['B', 'Bankszámlaszám:']] : null,
            (string) $b['bankszamla'] !== '' ? [['', (string) $b['bankszamla']]] : null,
        ]) as $reszek) {
            $sor(138.5, $y, $reszek);
            $y += 4.3;
        }

        // --- Cím, vonal, sorszám ---------------------------------------------
        $pdf->SetFont('DejaVu', 'B', 19);
        $pdf->SetXY($bal, 41.5);
        $pdf->Cell($szeles, 9, mb_strtoupper($cim, 'UTF-8'), 0, 0, 'R');

        $pdf->SetFillColor($szin[0], $szin[1], $szin[2]);
        $pdf->Rect($bal, 52.6, $szeles, 0.9, 'F');

        $pdf->SetFont('DejaVu', '', 9.5);
        $pdf->SetXY($bal, 54.6);
        $pdf->Cell($szeles, 5, 'Sorszám: ' . $szam, 0, 0, 'R');

        // --- Vevő --------------------------------------------------------------
        $pdf->SetFont('DejaVu', '', 12.5);
        $pdf->SetXY($bal, 74.5);
        $pdf->Cell(100, 6, 'VEVŐ:', 0, 0, 'L');

        $y = 80.6;
        $pdf->SetFont('DejaVu', 'B', 9.5);

        foreach (array_filter([
            (string) ($vevo['nev'] ?? ''),
            trim(($vevo['irsz'] ?? '') . ' ' . ($vevo['telepules'] ?? '')),
            (string) ($vevo['cim'] ?? ''),
        ]) as $szoveg) {
            $pdf->SetXY($bal, $y);
            $pdf->Cell(105, 5, $szoveg, 0, 0, 'L');
            $y += 5;
        }

        if ((string) ($vevo['adoszam'] ?? '') !== '') {
            $pdf->SetXY($bal, $y);
            $pdf->SetFont('DejaVu', 'B', 9.5);
            $pdf->Cell($pdf->GetStringWidth('Adószám:') + 1.2, 5, 'Adószám:', 0, 0, 'L');
            $pdf->SetFont('DejaVu', '', 9.5);
            $pdf->Cell(60, 5, (string) $vevo['adoszam'], 0, 0, 'L');
        }

        // --- Fizetési adatok ---------------------------------------------------
        $fx = 122.0;
        $fw = $jobb - $fx;
        $y  = 75.6;

        foreach ([
            ['Fizetési mód:', mb_strtolower((string) $fej['fizmod'], 'UTF-8')],
            ['Teljesítés dátuma:', $datum((string) $fej['teljesites'])],
            ['Kiállítás dátuma:', $datum((string) $fej['kelt'])],
        ] as [$cimke, $ertek]) {
            $pdf->SetFont('DejaVu', '', 9.5);
            $pdf->SetXY($fx, $y);
            $pdf->Cell($fw / 2, 5.2, $cimke, 0, 0, 'L');
            $pdf->SetFont('DejaVu', 'B', 9.5);
            $pdf->Cell($fw / 2, 5.2, $ertek, 0, 0, 'R');
            $y += 5.2;
        }

        $pdf->SetFillColor($szin[0], $szin[1], $szin[2]);
        $pdf->Rect($fx, $y + 0.3, $fw, 6.6, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('DejaVu', '', 9.5);
        $pdf->SetXY($fx + 1.4, $y + 0.3);
        $pdf->Cell($fw / 2, 6.6, 'Fizetési határidő:', 0, 0, 'L');
        $pdf->SetFont('DejaVu', 'B', 10.5);
        $pdf->SetXY($fx + $fw / 2, $y + 0.3);
        $pdf->Cell($fw / 2 - 1.4, 6.6, $datum((string) $fej['hatarido']), 0, 0, 'R');
        $pdf->SetTextColor(20, 20, 20);

        // --- Tételek -----------------------------------------------------------
        // Oszlopok: megnevezés, mennyiség, egységár, nettó, áfa, áfaérték, bruttó.
        $oszlop = [66.6, 18, 24, 24, 14, 20, 19];
        $igazit = ['L', 'R', 'R', 'R', 'R', 'R', 'R'];
        $fejlec = ['Megnevezés', 'Menny.', 'Egységár', 'Nettó ár', 'Áfa', 'Áfaérték', 'Bruttó ár'];

        $tablafej = static function () use ($pdf, $oszlop, $igazit, $fejlec, $bal, $szeles, $szin): void {
            $pdf->SetFont('DejaVu', 'B', 9);
            $pdf->SetX($bal);

            foreach ($fejlec as $i => $nev) {
                $pdf->Cell($oszlop[$i], 6, $nev, 0, 0, $igazit[$i]);
            }

            $pdf->Ln(6.4);
            $pdf->SetFillColor($szin[0], $szin[1], $szin[2]);
            $pdf->Rect($bal, $pdf->GetY() - 0.5, $szeles, 0.35, 'F');
        };

        $pdf->SetY(113);
        $tablafej();

        foreach ($t['sorok'] as $i => $s) {
            $pdf->SetFont('DejaVu', '', 9);

            // A hosszú megnevezés több sorba törik; a sor magassága ehhez igazodik.
            $sorok_szama = max(1, count(self::tordel($pdf, (string) $s['megnevezes'], $oszlop[0] - 4)));
            $magas       = 5.2 * $sorok_szama + 0.8;

            if ($pdf->GetY() + $magas > 297 - 36) {
                $pdf->AddPage();
                $pdf->SetY(22);
                $tablafej();
                $pdf->SetFont('DejaVu', '', 9);
            }

            $y0 = $pdf->GetY();

            if ($i % 2 === 0) {
                $pdf->SetFillColor(236, 236, 236);
                $pdf->Rect($bal, $y0, $szeles, $magas, 'F');
            }

            $pdf->SetXY($bal + 2, $y0 + 0.4);
            $pdf->MultiCell($oszlop[0] - 4, 5.2, (string) $s['megnevezes'], 0, 'L');

            $x = $bal + $oszlop[0];

            foreach ([$s['mennyiseg'], self::ft((float) $s['egysegar']), self::ft((float) $s['netto']), $s['kulcs'], self::ft((float) $s['afa']), self::ft((float) $s['brutto'])] as $j => $ertek) {
                $pdf->SetXY($x, $y0 + 0.4);
                $pdf->Cell($oszlop[$j + 1] - ($j === 5 ? 2 : 0), 5.2, (string) $ertek, 0, 0, 'R');
                $x += $oszlop[$j + 1];
            }

            $pdf->SetY($y0 + $magas);
        }

        // --- Összesen ------------------------------------------------------------
        if ($pdf->GetY() > 297 - 78) {
            $pdf->AddPage();
            $pdf->SetY(22);
        }

        $y = $pdf->GetY() + 3;
        $pdf->SetFont('DejaVu', 'B', 9.5);
        $pdf->SetXY($bal, $y);
        $pdf->Cell($oszlop[0] + $oszlop[1] + $oszlop[2], 5.5, 'Összesen:', 0, 0, 'L');
        $pdf->Cell($oszlop[3], 5.5, self::ft($t['netto']), 0, 0, 'R');
        $pdf->Cell($oszlop[4], 5.5, '', 0, 0);
        $pdf->Cell($oszlop[5], 5.5, self::ft($t['afa']), 0, 0, 'R');
        $pdf->Cell($oszlop[6] - 2, 5.5, self::ft($t['brutto']), 0, 0, 'R');
        $y += 6;

        $pdf->SetFont('DejaVu', '', 7.5);
        $pdf->SetTextColor(95, 95, 95);

        foreach ($t['kulcsok'] as $kulcs => $afa) {
            $pdf->SetXY($bal, $y);
            $pdf->Cell($oszlop[0] + $oszlop[1] + $oszlop[2] + $oszlop[3] + $oszlop[4], 4, 'áfa ' . str_replace('%', ' %', (string) $kulcs) . ':', 0, 0, 'R');
            $pdf->Cell($oszlop[5], 4, self::ft((float) $afa), 0, 0, 'R');
            $y += 4;
        }

        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('DejaVu', '', 14);
        $pdf->SetXY($bal, $y + 9);
        $pdf->Cell($szeles - 1, 7, 'Fizetendő összesen:', 0, 0, 'R');
        $pdf->SetFont('DejaVu', 'B', 15);
        $pdf->SetTextColor($szin[0], $szin[1], $szin[2]);
        $pdf->SetXY($bal, $y + 17);
        $pdf->Cell($szeles - 1, 8, self::ft($t['brutto']) . ' Ft', 0, 0, 'R');

        // Jól láthatóan: ez nem számla.
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('DejaVu', 'B', 8.5);
        $pdf->SetXY($bal, $y + 27);
        $pdf->Cell($szeles - 1, 4.4, 'Ez a bizonylat nem minősül számlának – a számlát külön állítjuk ki.', 0, 0, 'R');

        $megjegyzes = trim((string) $fej['megjegyzes']);

        if ($megjegyzes !== '') {
            $pdf->SetFont('DejaVu', '', 8.5);
            $pdf->SetXY($bal, $y + 36);
            $pdf->MultiCell($szeles, 4.4, $megjegyzes, 0, 'L');
        }

        return (string) $pdf->Output('S');
    }

    /**
     * Szöveg tördelése adott szélességre (a sormagasság kiszámításához).
     *
     * @return array<int, string>
     */
    private static function tordel(object $pdf, string $szoveg, float $szelesseg): array
    {
        $sorok = [];
        $sor   = '';

        foreach (preg_split('/\s+/u', trim($szoveg)) ?: [] as $szo) {
            $proba = $sor === '' ? $szo : $sor . ' ' . $szo;

            if ($sor !== '' && $pdf->GetStringWidth($proba) > $szelesseg - 2) {
                $sorok[] = $sor;
                $sor     = $szo;
            } else {
                $sor = $proba;
            }
        }

        if ($sor !== '') {
            $sorok[] = $sor;
        }

        return $sorok;
    }

    /** A helyi nyomtatvány PDF-je a számlaűrlapból. Semmilyen külső kérés nem megy ki. */
    public static function ajax_nyomtatvany(): void
    {
        self::jog_ellenorzes();

        $k = self::kerelem(false);

        if (!is_array($k)) {
            wp_send_json_error(['uzenet' => $k]);
        }

        try {
            $pdf = self::nyomtatvany_pdf($k);
        } catch (\Throwable $hiba) {
            wp_send_json_error(['uzenet' => 'A PDF nem készült el: ' . $hiba->getMessage()]);
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . sanitize_file_name(self::nyomtatvany_szam($k['munkalap'])) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- PDF.
        exit;
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
