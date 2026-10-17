<?php
/**
 * Eszköz-mockupok (0.56.0).
 *
 * Minden eszköz mellett egy termékkép (mockup) áll: a munkalapon, a részlet-
 * panelen és az eszközlistában. A kép a készülék kategóriájából (telefon,
 * tablet, laptop…) – vagy egy szűkebb szabályból (gyártó, típus) – adódik.
 *
 * A gyári készlet az assets/mockup/ mappában van (Magnific-kal készült,
 * márkajel nélküli termékfotók). Az adminisztrátor a Beállítások › Mockupok
 * lapfülön cserélheti, bővítheti és törölheti őket; minden képnek ott a
 * generáló szövege (prompt) is, hogy ugyanabban a stílusban újat készíthessen.
 *
 * Opció: sdh_muhely_mockupok = ['lista' => [...], 'sablon' => string]
 *   lista elem: id, nev, kategoriak (kulcsok), gyarto, modell (vesszővel
 *   elválasztott részletek), leiras (angol tárgy a prompthoz), kep (URL).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Mockup
{
    public const OPT = 'sdh_muhely_mockupok';
    public const MAX = 80;

    /** A gyári generáló szöveg; a {eszkoz} helyére kerül a mockup leírása. */
    public const ALAP_SABLON = 'Professional e-commerce product photo of a single generic unbranded {eszkoz}, no logos, no text, three-quarter view, soft diffused studio lighting, subtle soft shadow, plain pure white background, centered, entire product fully visible with generous margin, photorealistic, high detail';

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $gyorsitotar = null;

    public static function init(): void
    {
        // A lista gyorsítótára az opció minden változásakor ürül (új, módosított, törölt).
        $urit = static function (): void {
            self::$gyorsitotar = null;
        };

        add_action('add_option_' . self::OPT, $urit);
        add_action('update_option_' . self::OPT, $urit);
        add_action('delete_option_' . self::OPT, $urit);
    }

    /* =================================================================
     * Gyári készlet
     * ============================================================== */

    /**
     * kulcs => [név, kategóriák, angol leírás a prompthoz]
     *
     * @return array<string, array{0: string, 1: array<int, string>, 2: string}>
     */
    private static function gyari_adatok(): array
    {
        return [
            'telefon'     => ['Telefon', ['telefon'], 'modern smartphone, thin bezels, dark glossy screen with a subtle abstract blue-violet gradient wallpaper, premium aluminium frame'],
            'tablet'      => ['Tablet', ['tablet', 'ebook'], 'modern tablet computer, thin bezels, dark screen with a subtle abstract blue-violet gradient wallpaper'],
            'ora'         => ['Okosóra', ['ora'], 'smartwatch with a black silicone sport band, dark watch face with a simple abstract glowing ring'],
            'fejhallgato' => ['Fejhallgató', ['fejhallgato', 'vr'], 'pair of wireless over-ear headphones in matte graphite'],
            'laptop'      => ['Laptop', ['laptop'], 'modern slim laptop, lid open, dark screen with a subtle abstract blue-violet gradient wallpaper'],
            'asztali'     => ['Asztali gép', ['asztali', 'alkatresz', 'ups', 'tarolo'], 'desktop PC tower case in matte black with a tempered glass side panel'],
            'aio'         => ['All-in-one gép', ['aio'], 'slim all-in-one desktop computer on a metal stand, dark screen with a subtle abstract blue-violet gradient wallpaper'],
            'monitor'     => ['Monitor', ['monitor', 'periferia'], 'computer monitor with thin bezels on a simple stand, dark screen with a subtle abstract blue-violet gradient wallpaper'],
            'nyomtato'    => ['Nyomtató', ['nyomtato', 'pos'], 'compact office inkjet printer in white and grey'],
            'halozat'     => ['Router', ['halozat', 'okosotthon'], 'wifi router with three antennas in matte black'],
            'konzol'      => ['Játékkonzol', ['konzol', 'kontroller', 'tvbox'], 'home video game console in matte black standing upright with one wireless gamepad beside it, original design not resembling any existing brand'],
            'kezi_konzol' => ['Kézi konzol', ['kezi_konzol', 'jatek'], 'handheld game console with a wide screen and controls on both sides, original design not resembling any existing brand'],
            'tv'          => ['Televízió', ['tv', 'projektor'], 'flat screen television with very thin bezels on a slim pedestal stand, dark screen with a subtle abstract blue-violet gradient'],
            'fenykepezo'  => ['Fényképezőgép', ['fenykepezo', 'kamera', 'biz_kamera'], 'mirrorless digital camera in black with a lens attached'],
            'drone'       => ['Drón', ['drone'], 'compact quadcopter camera drone in dark grey with folding arms extended'],
            'hangszoro'   => ['Hangszóró', ['hangszoro', 'hifi', 'soundbar', 'radio', 'hangszer'], 'portable cylindrical bluetooth speaker with fabric cover in dark grey'],
            'porszivo'    => ['Robotporszívó', ['porszivo'], 'round robot vacuum cleaner in matte white with a lidar dome on top'],
            'roller'      => ['Elektromos roller', ['roller'], 'foldable electric kick scooter in matte black'],
            'kisgep'      => ['Háztartási kisgép', ['kisgep', 'nagygep', 'klima', 'vasalo', 'hajszarito', 'hajformazo', 'borotva', 'fogkefe'], 'automatic espresso coffee machine in brushed steel and black'],
            'egyeb'       => ['Egyéb eszköz', [], 'small electronic gadget box in matte dark grey with a few ports and one status LED, next to a neatly coiled black cable'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function gyari(): array
    {
        $ki = [];

        foreach (self::gyari_adatok() as $kulcs => [$nev, $kategoriak, $leiras]) {
            $ki[] = [
                'id'         => 'gy-' . $kulcs,
                'nev'        => $nev,
                'kategoriak' => $kategoriak,
                'gyarto'     => '',
                'modell'     => '',
                'leiras'     => $leiras,
                'kep'        => self::gyari_kep($kulcs),
            ];
        }

        return $ki;
    }

    /** A gyári kép címe (a csomagban), vagy üres szöveg, ha nincs ilyen fájl. */
    public static function gyari_kep(string $kulcs): string
    {
        $kulcs = sanitize_key($kulcs);

        return is_readable(SDH_MUHELY_DIR . 'assets/mockup/' . $kulcs . '.webp')
            ? SDH_MUHELY_URL . 'assets/mockup/' . $kulcs . '.webp'
            : '';
    }

    /* =================================================================
     * Tárolás
     * ============================================================== */

    /** @return array{lista: array<int, array<string, mixed>>, sablon: string, egyedi: bool} */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        $egyedi = isset($m['lista']) && is_array($m['lista']);

        return [
            'lista'  => $egyedi ? self::tisztit($m['lista']) : self::gyari(),
            'sablon' => isset($m['sablon']) && trim((string) $m['sablon']) !== '' ? (string) $m['sablon'] : self::ALAP_SABLON,
            'egyedi' => $egyedi,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function lista(): array
    {
        if (self::$gyorsitotar === null) {
            self::$gyorsitotar = self::beallitas()['lista'];
        }

        return self::$gyorsitotar;
    }

    /**
     * @param array<int|string, mixed> $nyers
     * @return array<int, array<string, mixed>>
     */
    public static function tisztit(array $nyers): array
    {
        $ervenyes = array_keys(SDH_Muhely_Eszkoz::kategoriak());
        $ki       = [];
        $idk      = [];

        foreach ($nyers as $elem) {
            if (!is_array($elem) || count($ki) >= self::MAX) {
                continue;
            }

            $nev = mb_substr(trim(sanitize_text_field((string) ($elem['nev'] ?? ''))), 0, 60);
            $kep = trim((string) ($elem['kep'] ?? ''));
            $kep = $kep !== '' ? esc_url_raw($kep, ['http', 'https']) : '';

            if ($nev === '' && $kep === '') {
                continue;
            }

            $id = sanitize_key((string) ($elem['id'] ?? ''));

            if ($id === '' || isset($idk[$id])) {
                $id = 'm-' . substr(md5($nev . $kep . count($ki) . microtime()), 0, 10);
            }

            $idk[$id] = true;
            $kategoriak = array_values(array_unique(array_intersect(
                array_map('sanitize_key', array_map('strval', (array) ($elem['kategoriak'] ?? []))),
                $ervenyes
            )));

            $ki[] = [
                'id'         => $id,
                'nev'        => $nev !== '' ? $nev : 'Mockup',
                'kategoriak' => $kategoriak,
                'gyarto'     => mb_substr(trim(sanitize_text_field((string) ($elem['gyarto'] ?? ''))), 0, 80),
                'modell'     => mb_substr(trim(sanitize_text_field((string) ($elem['modell'] ?? ''))), 0, 200),
                'leiras'     => mb_substr(trim(sanitize_textarea_field((string) ($elem['leiras'] ?? ''))), 0, 600),
                'kep'        => $kep,
            ];
        }

        return $ki;
    }

    /* =================================================================
     * Kiválasztás
     * ============================================================== */

    /**
     * Az eszközhöz illő kép címe.
     *
     * Sorrend: (1) gyártóra / típusra szabott mockup; (2) az eszköz saját
     * fényképe (IMEI-lekérdezésből vagy kézzel; SVG-rajz nem számít); (3) a
     * kategóriája mockupja; (4) az „Egyéb" mockup (amelyiknek nincs
     * kategóriája és szabálya). Ha egyik sincs: üres szöveg.
     */
    public static function kep(?object $eszkoz): string
    {
        // Kikapcsolt Eszköz-mockupok modul (Modulok): nincs termékkép.
        if ($eszkoz === null || SDH_Muhely_Modulok::ki('mockup')) {
            return '';
        }

        $kategoria = (string) ($eszkoz->kategoria ?? '');
        $gyarto    = mb_strtolower(trim((string) ($eszkoz->gyarto ?? '')));
        $tipus     = mb_strtolower(trim((string) ($eszkoz->tipus ?? '') . ' ' . (string) ($eszkoz->megnevezes ?? '') . ' ' . (string) ($eszkoz->modell_szam ?? '')));
        $legjobb   = null;
        $pont_max  = 0;
        $tartalek  = '';

        foreach (self::lista() as $m) {
            if ((string) $m['kep'] === '') {
                continue;
            }

            $szabaly_gyarto = mb_strtolower((string) $m['gyarto']);
            $szabaly_modell = array_filter(array_map(static fn (string $r): string => mb_strtolower(trim($r)), explode(',', (string) $m['modell'])));
            $kat            = (array) $m['kategoriak'];

            if ($szabaly_gyarto === '' && $szabaly_modell === [] && $kat === []) {
                $tartalek = $tartalek !== '' ? $tartalek : (string) $m['kep'];
                continue;
            }

            if ($szabaly_gyarto !== '' && $szabaly_gyarto !== $gyarto) {
                continue;
            }

            $modell_talalat = false;

            foreach ($szabaly_modell as $resz) {
                if ($resz !== '' && $tipus !== '' && mb_strpos($tipus, $resz) !== false) {
                    $modell_talalat = true;
                    break;
                }
            }

            if ($szabaly_modell !== [] && !$modell_talalat) {
                continue;
            }

            // Ha a mockupnak van kategóriája, csak arra a kategóriára illik (gyártó-szabállyal együtt is).
            if ($kat !== [] && !in_array($kategoria, $kat, true)) {
                continue;
            }

            $pont = ($modell_talalat ? 8 : 0) + ($szabaly_gyarto !== '' ? 4 : 0) + (in_array($kategoria, $kat, true) ? 1 : 0);

            if ($pont > $pont_max) {
                $pont_max = $pont;
                $legjobb  = $m;
            }
        }

        // Szabott (gyártó / típus) mockup: az mindent visz.
        if ($legjobb !== null && $pont_max >= 4) {
            return (string) $legjobb['kep'];
        }

        $sajat = trim((string) ($eszkoz->kep_url ?? ''));

        if ($sajat !== '' && preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $sajat) === 1) {
            return $sajat;
        }

        return $legjobb !== null ? (string) $legjobb['kep'] : $tartalek;
    }

    /**
     * A kép HTML-je keretben. $meret: s (40 px), m (64 px), l (112 px).
     * Kép nélkül is van keret (a kategória rövid jelével), hogy a sorok ne ugráljanak.
     */
    public static function html(?object $eszkoz, string $meret = 'm', string $tovabbi_osztaly = ''): string
    {
        if (SDH_Muhely_Modulok::ki('mockup')) {
            return '';
        }

        $meret = in_array($meret, ['s', 'm', 'l'], true) ? $meret : 'm';
        $kep   = self::kep($eszkoz);
        $nev   = $eszkoz !== null && class_exists('SDH_Muhely_Eszkoz') ? SDH_Muhely_Eszkoz::megnevezes($eszkoz) : '';

        if ($kep === '') {
            return '<span class="sdh-mockup sdh-mockup--' . $meret . ' sdh-mockup--ures ' . esc_attr($tovabbi_osztaly) . '" aria-hidden="true"></span>';
        }

        return sprintf(
            '<span class="sdh-mockup sdh-mockup--%s %s"><img src="%s" alt="%s" loading="lazy" decoding="async"></span>',
            $meret,
            esc_attr($tovabbi_osztaly),
            esc_url($kep),
            esc_attr($nev)
        );
    }

    /** A teljes generáló szöveg egy mockuphoz. */
    public static function prompt(array $mockup, ?string $sablon = null): string
    {
        $sablon = $sablon ?? self::beallitas()['sablon'];
        $leiras = trim((string) ($mockup['leiras'] ?? ''));

        if ($leiras === '') {
            $leiras = mb_strtolower((string) ($mockup['nev'] ?? 'device'));
        }

        return str_replace('{eszkoz}', $leiras, $sablon);
    }

    /* =================================================================
     * Beállítások › Mockupok
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $b          = self::beallitas();
        $csoportok  = SDH_Muhely_Eszkoz::kategoria_csoportok();

        ?>
        <input type="hidden" name="mockup_jelen" value="1">

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Hogyan működik?</h2>
            <p class="sdh-sugo">
                Minden eszköz mellett termékkép áll (munkalap, részletpanel, eszközlista). A rendszer a készülék
                <strong>kategóriájából</strong> választ képet; ha egy mockupnál megadsz <strong>gyártót</strong> vagy
                <strong>típusrészletet</strong>, az elsőbbséget kap (pl. gyártó „Apple”, típus „iPhone” → saját iPhone-kép).
                Ha az eszköznek saját fényképe van (IMEI-lekérdezésből), az a kategóriaképnél előrébb áll.
                Amelyik mockupnak se kategóriája, se szabálya nincs, az a tartalék („Egyéb”).
            </p>
            <p class="sdh-sugo sdh-sugo--utolso">
                Új kép: töltsd fel a <strong>Feltöltés…</strong> gombbal, vagy illessz be egy képcímet (pl. a Magnificből
                kimásolt linket) – mentéskor a rendszer letölti a saját képei közé, így a kép akkor is megmarad,
                ha a külső link lejár. Ugyanilyen stílusú képhez másold ki a mockup <strong>generáló szövegét</strong>,
                és a Magnificben vedd le a hátterét (Remove background), hogy átlátszó legyen.
            </p>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Generáló szöveg (prompt) sablonja</h2>
            <div class="sdh-mezo sdh-mezo--szeles">
                <label for="mockup_sablon">Sablon – a <code>{eszkoz}</code> helyére a mockup leírása kerül</label>
                <textarea name="mockup_sablon" id="mockup_sablon" rows="3" data-sdh-mockup-sablon><?php echo esc_textarea($b['sablon']); ?></textarea>
                <span class="sdh-mezo__sugo">Ez adja az egységes stílust (fehér háttér, stúdiófény, márkajel nélkül). Üresen hagyva a gyári sablon áll vissza.</span>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Mockupok</h2>

            <div class="sdh-mockup-lista" data-sdh-mockup-lista>
                <?php foreach ($b['lista'] as $i => $m) : ?>
                    <?php self::sor((string) $i, $m, $csoportok, $b['sablon']); ?>
                <?php endforeach; ?>
            </div>

            <template data-sdh-mockup-sablon-sor><?php self::sor('__I__', ['id' => '', 'nev' => '', 'kategoriak' => [], 'gyarto' => '', 'modell' => '', 'leiras' => '', 'kep' => ''], $csoportok, $b['sablon']); ?></template>

            <div class="sdh-mockup-gombok">
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-mockup-uj><span class="sdh-plusz" aria-hidden="true"></span>Új mockup</button>
                <label class="sdh-jelolo" title="Mentéskor a lista helyére a gyári 20 kép kerül vissza (a feltöltött képek megmaradnak).">
                    <input type="checkbox" name="mockup_gyari" value="1"> Gyári készlet visszaállítása mentéskor
                </label>
            </div>
        </div>
        <?php
    }

    /**
     * Egy mockup sora a Beállításokban.
     *
     * @param array<string, mixed>                $m
     * @param array<string, array<string, string>> $csoportok
     */
    private static function sor(string $i, array $m, array $csoportok, string $sablon): void
    {
        $nev  = 'mockup[' . $i . ']';
        $kat  = (array) ($m['kategoriak'] ?? []);
        $kep  = (string) ($m['kep'] ?? '');
        $pr   = self::prompt($m, $sablon);

        ?>
        <div class="sdh-mockup-sor" data-sdh-mockup-sor>
            <input type="hidden" name="<?php echo esc_attr($nev); ?>[id]" value="<?php echo esc_attr((string) ($m['id'] ?? '')); ?>">

            <div class="sdh-mockup-sor__kep sdh-rma-media" data-sdh-media="image">
                <span class="sdh-mockup sdh-mockup--l sdh-mockup-sor__elo" data-sdh-media-elo>
                    <?php if ($kep !== '') : ?>
                        <img src="<?php echo esc_url($kep); ?>" alt="">
                    <?php endif; ?>
                </span>
                <input type="text" name="<?php echo esc_attr($nev); ?>[kep]" value="<?php echo esc_attr($kep); ?>"
                       placeholder="Képcím – vagy töltsd fel" aria-label="A kép címe">
                <span class="sdh-mockup-sor__kepgombok">
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-media-valaszt>Feltöltés…</button>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-media-torol<?php echo $kep === '' ? ' hidden' : ''; ?>>Kép törlése</button>
                </span>
            </div>

            <div class="sdh-mockup-sor__adat">
                <div class="sdh-mezok">
                    <div class="sdh-mezo">
                        <label>Név</label>
                        <input type="text" name="<?php echo esc_attr($nev); ?>[nev]" maxlength="60" value="<?php echo esc_attr((string) ($m['nev'] ?? '')); ?>" placeholder="pl. Telefon">
                    </div>
                    <div class="sdh-mezo">
                        <label>Gyártó (nem kötelező)</label>
                        <input type="text" name="<?php echo esc_attr($nev); ?>[gyarto]" maxlength="80" value="<?php echo esc_attr((string) ($m['gyarto'] ?? '')); ?>" placeholder="pl. Apple">
                    </div>
                    <div class="sdh-mezo">
                        <label>Típus tartalmazza (nem kötelező)</label>
                        <input type="text" name="<?php echo esc_attr($nev); ?>[modell]" maxlength="200" value="<?php echo esc_attr((string) ($m['modell'] ?? '')); ?>" placeholder="pl. iPhone, Galaxy S">
                    </div>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label>Kategóriák</label>
                    <select name="<?php echo esc_attr($nev); ?>[kategoriak][]" multiple size="5" class="sdh-mockup-sor__kat">
                        <?php foreach ($csoportok as $cs_nev => $elemek) : ?>
                            <optgroup label="<?php echo esc_attr($cs_nev); ?>">
                                <?php foreach ($elemek as $k => $felirat) : ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected(in_array($k, $kat, true)); ?>><?php echo esc_html($felirat); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <span class="sdh-mezo__sugo">Ctrl / Cmd lenyomva több is választható. Üresen (és szabály nélkül) ez a tartalék kép.</span>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label>Leírás a generáló szöveghez (angolul)</label>
                    <input type="text" name="<?php echo esc_attr($nev); ?>[leiras]" maxlength="600" value="<?php echo esc_attr((string) ($m['leiras'] ?? '')); ?>" data-sdh-mockup-leiras placeholder="pl. modern smartphone with thin bezels">
                </div>

                <details class="sdh-mockup-sor__prompt">
                    <summary>Generáló szöveg (prompt)</summary>
                    <textarea readonly rows="3" data-sdh-mockup-prompt><?php echo esc_textarea($pr); ?></textarea>
                    <span class="sdh-mockup-sor__promptgombok">
                        <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-mockup-masol>Másolás</button>
                        <a class="sdh-gomb sdh-gomb--vilagos" href="https://www.magnific.com/app" target="_blank" rel="noopener">Megnyitás a Magnificben</a>
                    </span>
                </details>
            </div>

            <button type="button" class="sdh-mockup-sor__torol" data-sdh-mockup-torol title="A mockup törlése a listából (mentéskor)" aria-label="A mockup törlése">&times;</button>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['mockup_jelen'])) {
            return;
        }

        $sablon = isset($_POST['mockup_sablon']) ? trim(sanitize_textarea_field(wp_unslash((string) $_POST['mockup_sablon']))) : '';

        if (!empty($_POST['mockup_gyari'])) {
            update_option(self::OPT, ['sablon' => $sablon], false);

            return;
        }

        $nyers = isset($_POST['mockup']) && is_array($_POST['mockup']) ? wp_unslash($_POST['mockup']) : [];
        $lista = self::tisztit(array_values($nyers));

        // A külső képcím (pl. Magnific-link) letöltődik a médiatárba: a külső link lejárhat.
        foreach ($lista as $i => $m) {
            if ((string) $m['kep'] !== '' && !self::helyi($m['kep'])) {
                $helyi = self::letolt((string) $m['kep'], (string) $m['nev']);

                if ($helyi !== '') {
                    $lista[$i]['kep'] = $helyi;
                }
            }
        }

        update_option(self::OPT, ['lista' => $lista, 'sablon' => $sablon], false);
        // phpcs:enable
    }

    /** Saját (a site-on lévő) kép-e: a plugin mappája vagy a feltöltések. */
    public static function helyi(string $url): bool
    {
        $alap = [
            (string) wp_parse_url(home_url(), PHP_URL_HOST),
        ];
        $host = (string) wp_parse_url($url, PHP_URL_HOST);

        return $host === '' || in_array($host, $alap, true);
    }

    /** Külső kép letöltése a médiatárba; a válasz a helyi cím (vagy üres szöveg). */
    private static function letolt(string $url, string $nev): string
    {
        if (!current_user_can('upload_files') || stripos($url, 'https://') !== 0) {
            return '';
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url($url, 60);

        if (is_wp_error($tmp)) {
            return '';
        }

        $tipus = (string) (wp_get_image_mime($tmp) ?: '');
        $kit   = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$tipus] ?? '';

        if ($kit === '') {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return '';
        }

        $id = media_handle_sideload(['name' => 'mockup-' . sanitize_title($nev !== '' ? $nev : 'kep') . '.' . $kit, 'tmp_name' => $tmp], 0);

        if (is_wp_error($id)) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return '';
        }

        return (string) wp_get_attachment_url((int) $id);
    }
}
