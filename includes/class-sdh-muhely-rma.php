<?php
/**
 * RMA – QR-kód, ügyféloldal, levelezés, állapotnapló.
 *
 * Minden sorszámos munkalap kap egy titkos, véletlen azonosítót (rma_token).
 * A QR-kód erre a címre mutat: /rma/<token>/. Az oldal zárva nyílik meg;
 * az ügyfél a telefonszámával (06301234567) és a munkalap sorszámával oldja
 * fel. Az ügyféloldal megjelenítése az SDH_Muhely_Rma_Oldal osztályban van,
 * ez az osztály az adatot, a biztonságot és a CRM-oldali részeket adja:
 *
 *  - útvonal, feloldás (aláírt süti), próbálkozási korlát, POST-ok,
 *  - állapotnapló (minden állapotváltás egy sor),
 *  - üzenetek és e-mail értesítések,
 *  - CRM: az „RMA" lapfül (munkalap-ablak és Áttekintés részletpanel ugyanazzal
 *    a tartalommal), a címke, az Üzenetek postafiók, az olvasatlan-jelvények,
 *  - a Beállítások „Ügyféloldal" dobozai.
 *
 * Biztonság: a token 96 bites véletlen; a feloldáshoz telefonszám és sorszám
 * is kell; a próbálkozás IP-nként és munkalaponként korlátozott; a feloldott
 * állapotot aláírt süti tartja, ami a telefonszám változásakor érvénytelen.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Rma
{
    /** Az útvonal: /rma/<token>. */
    public const ALAP = 'rma';

    /** Query var szép URL nélkül: /?sdh_rma=<token>. */
    public const QUERY_VAR = 'sdh_rma';

    /** A nyomtatható címke: /?sdh_muhely_cimke=<munkalap id>. */
    public const CIMKE_VAR = 'sdh_muhely_cimke';

    /** A postafiók modulkulcsa (oldalmenü: Üzenetek). */
    public const KULCS = 'uzenetek';

    /** Beállítások optionje. */
    private const OPT = 'sdh_muhely_rma';

    /** Feloldási próbák: ennyi / ablak / IP + munkalap. */
    private const PROBA_MAX   = 8;
    private const PROBA_ABLAK = 15 * MINUTE_IN_SECONDS;

    /** Ügyfélüzenetek: ennyi / óra / munkalap. */
    private const UZENET_MAX = 10;

    /** Üzenet leghosszabb hossza (karakter). */
    public const UZENET_HOSSZ = 3000;

    /** A süti élettartama. */
    private const SUTI_ELET = 90 * DAY_IN_SECONDS;

    /** Postafiók: ennyi beszélgetés egy oldalon. */
    private const POSTA_OLDAL = 50;

    public static function init(): void
    {
        add_action('init', [self::class, 'szabalyok']);
        add_filter('query_vars', [self::class, 'query_vars']);
        add_filter('request', [self::class, 'utvonal_tartalek'], 1);
        add_action('template_redirect', [self::class, 'fogadas'], 0);

        add_action('sdh_muhely_munkalap_allapot', [self::class, 'naplo_ir'], 10, 3);
        add_action('sdh_muhely_sema_frissult', [self::class, 'naplo_potlas']);

        add_action('wp_ajax_sdh_muhely_uzenet_kuld', [self::class, 'ajax_uzenet_kuld']);
        add_action('wp_ajax_sdh_muhely_uzenet_olvasva', [self::class, 'ajax_olvasva']);
        add_action('wp_ajax_sdh_muhely_uzenet_allapot', [self::class, 'ajax_allapot']);

        add_action('admin_enqueue_scripts', [self::class, 'admin_eszkozok']);
        // A wp-admin menüjében is látszódjon az olvasatlan üzenetek száma.
        add_action('admin_menu', [self::class, 'admin_menu_jelveny'], 99);

        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Üzenetek',
            'render'  => [self::class, 'posta_oldal'],
            'sorrend' => 32,
            'jelveny' => [self::class, 'olvasatlan_db'],
        ]);
    }

    /* =================================================================
     * Táblák és adatok
     * ============================================================== */

    private static function munkalap_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('munkalap');
    }

    private static function naplo_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('munkalap_naplo');
    }

    private static function uzenet_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('uzenet');
    }

    public static function munkalap(int $id): ?object
    {
        global $wpdb;

        $sor = $id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::munkalap_tabla() . ' WHERE id = %d', $id))
            : null;

        return $sor ?: null;
    }

    public static function ugyfel(int $id): ?object
    {
        global $wpdb;

        $sor = $id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', $id))
            : null;

        return $sor ?: null;
    }

    public static function eszkoz(int $id): ?object
    {
        global $wpdb;

        $sor = $id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' WHERE id = %d', $id))
            : null;

        return $sor ?: null;
    }

    private static function munkalap_tokennel(string $token): ?object
    {
        global $wpdb;

        $sor = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::munkalap_tabla() . ' WHERE rma_token = %s AND rma_token <> %s LIMIT 1', $token, '')
        );

        return $sor ?: null;
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    /** A beállítások alapértékei. */
    private static function alapok(): array
    {
        return [
            // Cím és értesítés
            'nyilvanos_cim'   => '',
            'szerviz_nev'     => '',
            'ertesites_email' => '',
            'ugyfel_email'    => true,
            // Megjelenés
            'logo'            => '',
            'hatter_tipus'    => 'alap',      // alap | szin | kep | video
            'hatter_szin'     => '#1b1f24',
            'hatter_kep'      => '',
            'hatter_video'    => '',
            'sotetites'       => 35,          // a háttér sötétítése, %
            'uveg'            => true,        // áttetsző, elmosott ablak a háttér fölött
            'kiemelo'         => '',          // üres = a CRM kiemelő színe
            'tema'            => 'rendszer',  // rendszer | vilagos | sotet
            'nyelv'           => 'auto',      // auto | hu | en | de
            'nyelvek'         => ['hu', 'en', 'de'],
            // Elérhetőség
            'cim'             => '',
            'telefon'         => '',
            'email'           => '',
            'nyitvatartas'    => '',
            'weboldal'        => '',
            'terkep'          => '',
            'egyeb'           => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        // 0.26.0: egyetlen „elérhetőség" szövegmező volt – az „Egyéb" mezőbe költözik.
        if (!isset($m['egyeb']) && !empty($m['elerhetoseg'])) {
            $m['egyeb'] = (string) $m['elerhetoseg'];
        }

        $b = array_merge(self::alapok(), array_intersect_key($m, self::alapok()));

        $b['szerviz_nev']     = (string) $b['szerviz_nev'] !== '' ? (string) $b['szerviz_nev'] : (string) get_bloginfo('name');
        $b['ertesites_email'] = (string) $b['ertesites_email'] !== '' ? (string) $b['ertesites_email'] : (string) get_option('admin_email');
        $b['ugyfel_email']    = (bool) $b['ugyfel_email'];
        $b['uveg']            = (bool) $b['uveg'];
        $b['sotetites']       = max(0, min(85, (int) $b['sotetites']));
        $b['nyelvek']         = array_values(array_intersect(['hu', 'en', 'de'], (array) $b['nyelvek']));

        if ($b['nyelvek'] === []) {
            $b['nyelvek'] = ['hu'];
        }

        return $b;
    }

    /** Hexa színkód ellenőrzése (#abc vagy #aabbcc), különben üres. */
    private static function szin(string $ertek): string
    {
        $ertek = trim($ertek);

        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $ertek) === 1 ? strtolower($ertek) : '';
    }

    /** A wp-admin Beállítások oldalán: médiaválasztó és a hozzá tartozó szkript. */
    public static function admin_eszkozok(): void
    {
        $oldal = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($oldal !== SDH_Muhely_Admin_UI::FOMENU . '-' . SDH_Muhely_Beallitasok::KULCS) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script(
            'sdh-muhely-rma-beallitas',
            SDH_MUHELY_URL . 'assets/rma-beallitas.js',
            ['jquery'],
            SDH_Muhely_Admin_UI::eszkoz_verzio('assets/rma-beallitas.js'),
            true
        );
    }

    /** Médiamező: URL + Kiválasztás gomb + kis előnézet. */
    private static function media_mezo(string $nev, string $cimke, string $ertek, string $tipus, string $sugo = ''): void
    {
        ?>
        <div class="sdh-mezo sdh-mezo--szeles">
            <label for="<?php echo esc_attr($nev); ?>"><?php echo esc_html($cimke); ?></label>
            <div class="sdh-rma-media" data-sdh-media="<?php echo esc_attr($tipus); ?>">
                <input type="text" name="<?php echo esc_attr($nev); ?>" id="<?php echo esc_attr($nev); ?>"
                       value="<?php echo esc_attr($ertek); ?>" placeholder="https://…">
                <button type="button" class="sdh-gomb" data-sdh-media-valaszt>Kiválasztás…</button>
                <button type="button" class="sdh-gomb" data-sdh-media-torol<?php echo $ertek === '' ? ' hidden' : ''; ?>>Törlés</button>
                <span class="sdh-rma-media__elo" data-sdh-media-elo>
                    <?php if ($ertek !== '' && $tipus === 'image') : ?>
                        <img src="<?php echo esc_url($ertek); ?>" alt="">
                    <?php elseif ($ertek !== '' && $tipus === 'video') : ?>
                        <video src="<?php echo esc_url($ertek); ?>" muted playsinline preload="metadata"></video>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($sugo !== '') : ?>
                <span class="sdh-mezo__sugo"><?php echo esc_html($sugo); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    /** A beállítások dobozai (a Beállítások képernyő hívja). */
    public static function beallitas_doboz(): void
    {
        $b       = self::beallitas();
        $elonezet = self::elonezet_cim();

        ?>
        <style>
            .sdh-rma-media { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
            .sdh-rma-media input { flex: 1 1 320px; min-width: 0; }
            .sdh-rma-media [hidden] { display: none; }
            .sdh-rma-media__elo img, .sdh-rma-media__elo video { display: block; max-height: 44px; max-width: 120px; border-radius: 6px; border: 1px solid var(--sdh-keret); }
            .sdh-rma-szin { display: flex; align-items: center; gap: 8px; }
            .sdh-rma-szin input[type="color"] { width: 44px; height: 34px; padding: 2px; border: 1px solid var(--sdh-keret-eros); border-radius: 7px; background: var(--sdh-felulet); }
            .sdh-rma-valaszto { display: inline-flex; flex-wrap: wrap; gap: 4px; }
            .sdh-rma-valaszto label { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; border: 1px solid var(--sdh-keret-eros); border-radius: 7px; background: var(--sdh-felulet); cursor: pointer; font-weight: 500; }
            .sdh-rma-valaszto input { margin: 0; }
            .sdh-rma-valaszto label:has(input:checked) { border-color: var(--sdh-accent); box-shadow: inset 0 0 0 1px var(--sdh-accent); }
            .sdh-rma-elonezet { display: flex; flex-wrap: wrap; gap: 6px; margin-top: .6rem; }
        </style>

        <div class="sdh-doboz" id="ugyfeloldal">
            <h2 class="sdh-doboz__cim">Ügyféloldal (RMA) – cím és értesítés</h2>

            <p class="sdh-sugo">
                Minden munkalap kap egy QR-kódot. Az ügyfél a telefonjával beolvassa, a telefonszámával
                és a munkalap sorszámával belép, és látja a javítás állapotát, a fizetendő összeget és az
                üzeneteket – válaszolni is tud. A QR-kód csak akkor működik az ügyfél telefonján, ha ez a
                rendszer <strong>az internetről elérhető</strong>; ha nem, add meg alább azt a nyilvános
                címet, ahol elérhető.
            </p>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="rma_nyilvanos_cim">Nyilvános cím</label>
                    <input type="text" name="rma_nyilvanos_cim" id="rma_nyilvanos_cim"
                           value="<?php echo esc_attr((string) $b['nyilvanos_cim']); ?>"
                           placeholder="<?php echo esc_attr(self::alap_cim()); ?>">
                    <span class="sdh-mezo__sugo">
                        Üresen hagyva: <code><?php echo esc_html(self::alap_cim()); ?></code>. A cím végére kerül az
                        azonosító; ha máshová kell, írd a címbe a <code>{token}</code> jelölőt.
                    </span>
                </div>

                <div class="sdh-mezo">
                    <label for="rma_szerviz_nev">Szerviz neve</label>
                    <input type="text" name="rma_szerviz_nev" id="rma_szerviz_nev"
                           value="<?php echo esc_attr((string) $b['szerviz_nev']); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="rma_ertesites_email">Ügyfélüzenetek ide érkeznek (e-mail)</label>
                    <input type="email" name="rma_ertesites_email" id="rma_ertesites_email"
                           value="<?php echo esc_attr((string) $b['ertesites_email']); ?>">
                </div>
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="rma_ugyfel_email" id="rma_ugyfel_email" value="1"
                    <?php checked($b['ugyfel_email']); ?>>
                <label for="rma_ugyfel_email">Új üzenetnél alapból e-mail értesítés az ügyfélnek (ha van e-mail címe)</label>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Ügyféloldal – megjelenés</h2>

            <p class="sdh-sugo">
                Az ügyféloldal a CRM formanyelvét használja: egy ablak a háttér fölött, bal oldalt az
                állapot és az összeg, jobbra lapfülek. Itt adható meg a logó, a háttér (szín, kép vagy
                videó), a kiemelő szín, az alapértelmezett világos/sötét mód és a nyelvek.
            </p>

            <div class="sdh-mezok">
                <?php self::media_mezo('rma_logo', 'Logó', (string) $b['logo'], 'image', 'PNG vagy SVG, átlátszó háttérrel. Az ablak fejlécében jelenik meg, legfeljebb 40 px magasan.'); ?>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label>Háttér</label>
                    <div class="sdh-rma-valaszto">
                        <?php foreach (['alap' => 'Alap (a kiemelő színből)', 'szin' => 'Egyszínű', 'kep' => 'Kép', 'video' => 'Videó'] as $k => $f) : ?>
                            <label><input type="radio" name="rma_hatter_tipus" value="<?php echo esc_attr($k); ?>" <?php checked($b['hatter_tipus'], $k); ?>> <?php echo esc_html($f); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="sdh-mezo">
                    <label for="rma_hatter_szin">Háttérszín</label>
                    <div class="sdh-rma-szin">
                        <input type="color" id="rma_hatter_szin" name="rma_hatter_szin" value="<?php echo esc_attr(self::szin((string) $b['hatter_szin']) ?: '#1b1f24'); ?>">
                        <span class="sdh-mezo__sugo">„Egyszínű" háttérnél, és amíg a kép vagy videó betölt.</span>
                    </div>
                </div>

                <div class="sdh-mezo">
                    <label for="rma_sotetites">Háttér sötétítése: <output data-sdh-kimenet="rma_sotetites"><?php echo (int) $b['sotetites']; ?></output>%</label>
                    <input type="range" id="rma_sotetites" name="rma_sotetites" min="0" max="85" step="5" value="<?php echo (int) $b['sotetites']; ?>">
                </div>

                <?php self::media_mezo('rma_hatter_kep', 'Háttérkép', (string) $b['hatter_kep'], 'image', 'Videós háttérnél ez a kép látszik, amíg a videó betölt (és ha az ügyfél kikapcsolta a mozgó tartalmat).'); ?>
                <?php self::media_mezo('rma_hatter_video', 'Háttérvideó', (string) $b['hatter_video'], 'video', 'MP4 (H.264) vagy WebM, hang nélkül ismétlődik. Rövid, kis méretű (néhány MB) videót válassz – mobilon is ez töltődik le.'); ?>

                <div class="sdh-mezo">
                    <label for="rma_kiemelo">Kiemelő szín</label>
                    <div class="sdh-rma-szin">
                        <input type="color" id="rma_kiemelo_valaszto" value="<?php echo esc_attr(self::szin((string) $b['kiemelo']) ?: '#d4231d'); ?>" data-sdh-szin-cel="rma_kiemelo">
                        <input type="text" id="rma_kiemelo" name="rma_kiemelo" value="<?php echo esc_attr((string) $b['kiemelo']); ?>" placeholder="a CRM színe" style="max-width:9rem">
                    </div>
                    <span class="sdh-mezo__sugo">Gombok, kiemelések. Üresen: a CRM Arculat színe.</span>
                </div>

                <div class="sdh-mezo">
                    <label>Alapértelmezett mód</label>
                    <div class="sdh-rma-valaszto">
                        <?php foreach (['rendszer' => 'A telefon beállítása', 'vilagos' => 'Világos', 'sotet' => 'Sötét'] as $k => $f) : ?>
                            <label><input type="radio" name="rma_tema" value="<?php echo esc_attr($k); ?>" <?php checked($b['tema'], $k); ?>> <?php echo esc_html($f); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <span class="sdh-mezo__sugo">Az ügyfél a fejlécben át tudja váltani.</span>
                </div>

                <div class="sdh-mezo">
                    <label>Nyelvek</label>
                    <div class="sdh-rma-valaszto">
                        <?php foreach (['hu' => 'Magyar', 'en' => 'English', 'de' => 'Deutsch'] as $k => $f) : ?>
                            <label><input type="checkbox" name="rma_nyelvek[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, $b['nyelvek'], true)); ?>> <?php echo esc_html($f); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="sdh-mezo">
                    <label>Alapnyelv</label>
                    <div class="sdh-rma-valaszto">
                        <?php foreach (['auto' => 'A telefon nyelve', 'hu' => 'Magyar', 'en' => 'English', 'de' => 'Deutsch'] as $k => $f) : ?>
                            <label><input type="radio" name="rma_nyelv" value="<?php echo esc_attr($k); ?>" <?php checked($b['nyelv'], $k); ?>> <?php echo esc_html($f); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="rma_uveg" id="rma_uveg" value="1" <?php checked($b['uveg']); ?>>
                <label for="rma_uveg">Áttetsző, elmosott ablak a háttér fölött (üveghatás)</label>
            </div>

            <div class="sdh-rma-elonezet">
                <?php if ($elonezet !== '') : ?>
                    <a class="sdh-gomb" href="<?php echo esc_url(add_query_arg('elonezet', 'zar', $elonezet)); ?>" target="_blank" rel="noopener">Előnézet: belépés</a>
                    <a class="sdh-gomb" href="<?php echo esc_url(add_query_arg('elonezet', '1', $elonezet)); ?>" target="_blank" rel="noopener">Előnézet: munkalap</a>
                    <span class="sdh-mezo__sugo">Mentés után a legutóbbi sorszámos munkalappal.</span>
                <?php else : ?>
                    <span class="sdh-mezo__sugo">Előnézet akkor érhető el, ha van legalább egy sorszámos munkalap.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Ügyféloldal – elérhetőség</h2>

            <p class="sdh-sugo">Az ügyféloldal „Elérhetőség" lapfülén és a gyorsgombokon (Hívás, E-mail, Útvonal) jelenik meg.</p>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="rma_cim">Cím</label>
                    <input type="text" name="rma_cim" id="rma_cim" value="<?php echo esc_attr((string) $b['cim']); ?>" placeholder="8200 Veszprém, …">
                </div>
                <div class="sdh-mezo">
                    <label for="rma_telefon">Telefon</label>
                    <input type="tel" name="rma_telefon" id="rma_telefon" value="<?php echo esc_attr((string) $b['telefon']); ?>" placeholder="+36 …">
                </div>
                <div class="sdh-mezo">
                    <label for="rma_email">E-mail</label>
                    <input type="email" name="rma_email" id="rma_email" value="<?php echo esc_attr((string) $b['email']); ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="rma_weboldal">Weboldal</label>
                    <input type="text" name="rma_weboldal" id="rma_weboldal" value="<?php echo esc_attr((string) $b['weboldal']); ?>" placeholder="https://…">
                </div>
                <div class="sdh-mezo">
                    <label for="rma_terkep">Térkép link</label>
                    <input type="text" name="rma_terkep" id="rma_terkep" value="<?php echo esc_attr((string) $b['terkep']); ?>" placeholder="üresen: a címből készül">
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="rma_nyitvatartas">Nyitvatartás</label>
                    <textarea name="rma_nyitvatartas" id="rma_nyitvatartas" rows="3" placeholder="H–P: 9–17&#10;Szo: 9–12"><?php echo esc_textarea((string) $b['nyitvatartas']); ?></textarea>
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="rma_egyeb">Egyéb tudnivaló</label>
                    <textarea name="rma_egyeb" id="rma_egyeb" rows="2"><?php echo esc_textarea((string) $b['egyeb']); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    /** A beállítások mentése (a Beállítások mentése hívja; jog és nonce már ellenőrzött). */
    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $szoveg = static fn (string $k): string => isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : '';
        $hosszu = static fn (string $k): string => isset($_POST[$k]) ? sanitize_textarea_field(wp_unslash($_POST[$k])) : '';
        $url    = static fn (string $k): string => isset($_POST[$k]) ? esc_url_raw(trim(wp_unslash($_POST[$k]))) : '';
        $egyik  = static fn (string $k, array $lehet, string $alap): string => in_array($szoveg($k), $lehet, true) ? $szoveg($k) : $alap;

        $cim = trim($szoveg('rma_nyilvanos_cim'));

        if ($cim !== '' && !preg_match('#^https?://#i', $cim)) {
            $cim = 'https://' . $cim;
        }

        $nyelvek = isset($_POST['rma_nyelvek']) && is_array($_POST['rma_nyelvek'])
            ? array_values(array_intersect(['hu', 'en', 'de'], array_map('sanitize_key', wp_unslash($_POST['rma_nyelvek']))))
            : ['hu'];

        update_option(self::OPT, [
            'nyilvanos_cim'   => $cim,
            'szerviz_nev'     => $szoveg('rma_szerviz_nev'),
            'ertesites_email' => isset($_POST['rma_ertesites_email']) ? sanitize_email(wp_unslash($_POST['rma_ertesites_email'])) : '',
            'ugyfel_email'    => !empty($_POST['rma_ugyfel_email']),
            'logo'            => $url('rma_logo'),
            'hatter_tipus'    => $egyik('rma_hatter_tipus', ['alap', 'szin', 'kep', 'video'], 'alap'),
            'hatter_szin'     => self::szin($szoveg('rma_hatter_szin')) ?: '#1b1f24',
            'hatter_kep'      => $url('rma_hatter_kep'),
            'hatter_video'    => $url('rma_hatter_video'),
            'sotetites'       => max(0, min(85, (int) $szoveg('rma_sotetites'))),
            'uveg'            => !empty($_POST['rma_uveg']),
            'kiemelo'         => self::szin($szoveg('rma_kiemelo')),
            'tema'            => $egyik('rma_tema', ['rendszer', 'vilagos', 'sotet'], 'rendszer'),
            'nyelv'           => $egyik('rma_nyelv', ['auto', 'hu', 'en', 'de'], 'auto'),
            'nyelvek'         => $nyelvek !== [] ? $nyelvek : ['hu'],
            'cim'             => $szoveg('rma_cim'),
            'telefon'         => $szoveg('rma_telefon'),
            'email'           => isset($_POST['rma_email']) ? sanitize_email(wp_unslash($_POST['rma_email'])) : '',
            'nyitvatartas'    => $hosszu('rma_nyitvatartas'),
            'weboldal'        => $url('rma_weboldal'),
            'terkep'          => $url('rma_terkep'),
            'egyeb'           => $hosszu('rma_egyeb'),
        ]);
        // phpcs:enable
    }

    /** A legutóbbi sorszámos munkalap helyi ügyféloldal-címe (előnézethez), vagy ''. */
    private static function elonezet_cim(): string
    {
        global $wpdb;

        $id = (int) $wpdb->get_var('SELECT id FROM ' . self::munkalap_tabla() . ' WHERE munkalap_szam > 0 ORDER BY id DESC LIMIT 1');
        $ml = $id > 0 ? self::munkalap($id) : null;

        return $ml ? self::helyi_url(self::token($ml)) : '';
    }

    /* =================================================================
     * Token és címek
     * ============================================================== */

    /** Új, véletlen azonosító (24 hexa karakter = 96 bit). */
    public static function uj_token(): string
    {
        return bin2hex(random_bytes(12));
    }

    /** A munkalap tokenje; ha még nincs, most kap (és el is mentjük). */
    public static function token(object $munkalap): string
    {
        global $wpdb;

        $token = (string) ($munkalap->rma_token ?? '');

        if ($token !== '') {
            return $token;
        }

        $token = self::uj_token();

        $wpdb->update(self::munkalap_tabla(), ['rma_token' => $token], ['id' => (int) $munkalap->id]);
        $munkalap->rma_token = $token;

        return $token;
    }

    /** A gyári cím (a saját WordPress alatt), tokenhely nélkül. */
    private static function alap_cim(): string
    {
        return get_option('permalink_structure')
            ? home_url('/' . self::ALAP . '/')
            : home_url('/?' . self::QUERY_VAR . '=');
    }

    /** Az ügyféloldal címe a saját WordPress alatt (előnézet, munkatársi link). */
    public static function helyi_url(string $token): string
    {
        return self::alap_cim() . $token . (get_option('permalink_structure') ? '/' : '');
    }

    /** Az ügyféloldal nyilvános címe – ez kerül a QR-kódba. */
    public static function url(string $token): string
    {
        $alap = (string) self::beallitas()['nyilvanos_cim'];

        if ($alap === '') {
            return self::helyi_url($token);
        }

        if (str_contains($alap, '{token}')) {
            return str_replace('{token}', $token, $alap);
        }

        if (str_ends_with($alap, '=')) {
            return $alap . $token;
        }

        return rtrim($alap, '/') . '/' . $token . '/';
    }

    /** A nyomtatható címke címe (belépéshez kötött). */
    public static function cimke_url(int $munkalap_id): string
    {
        return add_query_arg(self::CIMKE_VAR, $munkalap_id, home_url('/'));
    }

    /* =================================================================
     * Útvonal
     * ============================================================== */

    public static function szabalyok(): void
    {
        add_rewrite_rule(
            '^' . self::ALAP . '/([A-Za-z0-9]{8,40})/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function query_vars(array $vars): array
    {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    /**
     * A /rma/<token> cím akkor is működjön, ha a rewrite szabály kiesett.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public static function utvonal_tartalek(array $vars): array
    {
        if (!empty($vars[self::QUERY_VAR])) {
            return $vars;
        }

        $token = self::token_a_cimbol(
            isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '',
            (string) wp_parse_url(home_url('/'), PHP_URL_PATH)
        );

        return $token === null ? $vars : [self::QUERY_VAR => $token];
    }

    /** A kért címből a token, vagy null, ha a cím nem az RMA-oldalé. */
    public static function token_a_cimbol(string $keres, string $alap_ut): ?string
    {
        $ut      = (string) parse_url($keres, PHP_URL_PATH);
        $alap_ut = '/' . trim($alap_ut, '/');
        $alap_ut = $alap_ut === '/' ? '' : $alap_ut;

        if ($alap_ut !== '' && !str_starts_with($ut, $alap_ut . '/')) {
            return null;
        }

        $ut = substr($ut, strlen($alap_ut));

        if (preg_match('#^/' . preg_quote(self::ALAP, '#') . '/([A-Za-z0-9]{8,40})/?$#', $ut, $t) !== 1) {
            return null;
        }

        return $t[1];
    }

    /** Munkatárs nézi-e (belépve, jogosultsággal). */
    public static function munkatars(): bool
    {
        return is_user_logged_in() && current_user_can(SDH_Muhely_Admin_UI::jog());
    }

    /** A kérés kezelése: ügyféloldal vagy nyomtatható címke. */
    public static function fogadas(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (isset($_GET[self::CIMKE_VAR])) {
            self::cimke_oldal((int) $_GET[self::CIMKE_VAR]);
            exit;
        }
        // phpcs:enable

        $token = get_query_var(self::QUERY_VAR);

        if ($token === '' || $token === null) {
            return;
        }

        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $token) ?? '';

        // A frontend nem jár az admin_init-en: a sémafrissítés itt is fusson.
        SDH_Muhely_Schema::frissites_ha_kell();

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        header('X-Frame-Options: SAMEORIGIN', true);

        SDH_Muhely_Rma_Oldal::nyelv_beallit();

        $munkalap = $token !== '' ? self::munkalap_tokennel($token) : null;

        if ($munkalap === null || (int) $munkalap->munkalap_szam <= 0) {
            status_header(404);
            SDH_Muhely_Rma_Oldal::ismeretlen();
            exit;
        }

        $ugyfel = self::ugyfel((int) $munkalap->ugyfel_id);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $muvelet = isset($_POST['sdh_rma_muvelet']) ? sanitize_key(wp_unslash($_POST['sdh_rma_muvelet'])) : '';

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && $muvelet !== '') {
            self::post_kezeles($muvelet, $munkalap, $ugyfel);
            exit;
        }

        status_header(200);

        // Munkatársi előnézet: a munkatárs belépés nélkül látja (vagy a zárt nézetet).
        $elonezet = isset($_GET['elonezet']) && self::munkatars() // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key(wp_unslash($_GET['elonezet'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';

        if ($elonezet === 'zar' || ($elonezet === '' && !self::feloldva($munkalap, $ugyfel))) {
            SDH_Muhely_Rma_Oldal::zar_oldal($munkalap, $elonezet !== '');
            exit;
        }

        SDH_Muhely_Rma_Oldal::rma_oldal($munkalap, $ugyfel, $elonezet !== '');
        exit;
    }

    /* =================================================================
     * Feloldás (telefonszám + sorszám)
     * ============================================================== */

    /**
     * Telefonszám egységes alakja: csak számjegy, belföldi 06-os formában.
     * +36 30 400 4636 → 06304004636; 0036… → 06…
     */
    public static function telefon_norm(string $telefon): string
    {
        $d = preg_replace('/\D/', '', $telefon) ?? '';

        if (str_starts_with($d, '0036')) {
            $d = '06' . substr($d, 4);
        } elseif (str_starts_with($d, '36') && strlen($d) >= 10) {
            $d = '06' . substr($d, 2);
        }

        return $d;
    }

    /** Egyezik-e a beírt telefonszám és sorszám a munkalappal. */
    public static function egyezik(object $munkalap, ?object $ugyfel, string $telefon, string $sorszam): bool
    {
        if ($ugyfel === null) {
            return false;
        }

        $szam = preg_replace('/\D/', '', $sorszam) ?? '';

        if ($szam === '' || (int) $szam !== (int) $munkalap->munkalap_szam) {
            return false;
        }

        $be = self::telefon_norm($telefon);

        if (strlen($be) < 8) {
            return false;
        }

        foreach ([(string) $ugyfel->telefon, (string) ($ugyfel->telefon2 ?? '')] as $sajat) {
            $sajat = self::telefon_norm($sajat);

            if ($sajat !== '' && hash_equals($sajat, $be)) {
                return true;
            }
        }

        return false;
    }

    private static function suti_nev(object $munkalap): string
    {
        return 'sdh_rma_' . substr((string) $munkalap->rma_token, 0, 12);
    }

    /** A feloldott állapot aláírása: a telefonszám változásakor érvénytelen. */
    private static function alairas(object $munkalap, ?object $ugyfel): string
    {
        $adat = implode('|', [
            'rma',
            (string) $munkalap->rma_token,
            (int) $munkalap->munkalap_szam,
            (int) $munkalap->ugyfel_id,
            $ugyfel ? self::telefon_norm((string) $ugyfel->telefon) : '',
            $ugyfel ? self::telefon_norm((string) ($ugyfel->telefon2 ?? '')) : '',
        ]);

        return hash_hmac('sha256', $adat, wp_salt('auth'));
    }

    private static function feloldva(object $munkalap, ?object $ugyfel): bool
    {
        if ($ugyfel === null) {
            return false;
        }

        $nev = self::suti_nev($munkalap);

        return isset($_COOKIE[$nev])
            && hash_equals(self::alairas($munkalap, $ugyfel), (string) wp_unslash($_COOKIE[$nev]));
    }

    public static function suti_beallit(string $nev, string $ertek, int $lejar): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie($nev, $ertek, [
            'expires'  => $lejar,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function proba_kulcs(object $munkalap): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return 'sdh_rma_p_' . md5($ip . '|' . (int) $munkalap->id);
    }

    /** Az ügyfél űrlapjának aláírt jele (CSRF ellen). */
    public static function urlap_jel(object $munkalap): string
    {
        return hash_hmac('sha256', 'valasz|' . (string) $munkalap->rma_token, wp_salt('nonce'));
    }

    /** A visszairányítás célja: ugyanaz a cím, relatív útvonallal (proxy mögött is jó). */
    public static function vissza_cim(object $munkalap): string
    {
        $ut = (string) strtok(isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/', '?');

        if (self::token_a_cimbol($ut, (string) wp_parse_url(home_url('/'), PHP_URL_PATH)) === null) {
            return add_query_arg(self::QUERY_VAR, (string) $munkalap->rma_token, $ut);
        }

        return $ut;
    }

    /** Az ügyfél POST-jai: belépés, válasz, kilépés. Mindig átirányít (PRG). */
    private static function post_kezeles(string $muvelet, object $munkalap, ?object $ugyfel): void
    {
        $vissza = self::vissza_cim($munkalap);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ($muvelet === 'belep') {
            $kulcs = self::proba_kulcs($munkalap);
            $db    = (int) get_transient($kulcs);

            if ($db >= self::PROBA_MAX) {
                wp_safe_redirect(add_query_arg('h', 'sok', $vissza));
                return;
            }

            $telefon = isset($_POST['telefon']) ? sanitize_text_field(wp_unslash($_POST['telefon'])) : '';
            $sorszam = isset($_POST['sorszam']) ? sanitize_text_field(wp_unslash($_POST['sorszam'])) : '';

            if (!self::egyezik($munkalap, $ugyfel, $telefon, $sorszam)) {
                set_transient($kulcs, $db + 1, self::PROBA_ABLAK);
                wp_safe_redirect(add_query_arg('h', 'rossz', $vissza));
                return;
            }

            delete_transient($kulcs);
            self::suti_beallit(self::suti_nev($munkalap), self::alairas($munkalap, $ugyfel), time() + self::SUTI_ELET);
            wp_safe_redirect($vissza);
            return;
        }

        if ($muvelet === 'kilep') {
            self::suti_beallit(self::suti_nev($munkalap), '', time() - DAY_IN_SECONDS);
            wp_safe_redirect($vissza);
            return;
        }

        if ($muvelet === 'valasz') {
            if (!self::feloldva($munkalap, $ugyfel)) {
                wp_safe_redirect($vissza);
                return;
            }

            $jel = isset($_POST['jel']) ? (string) wp_unslash($_POST['jel']) : '';

            if (!hash_equals(self::urlap_jel($munkalap), $jel)) {
                wp_safe_redirect(add_query_arg('h', 'lejart', $vissza) . '#uzenetek');
                return;
            }

            $szoveg = isset($_POST['szoveg']) ? trim(sanitize_textarea_field(wp_unslash($_POST['szoveg']))) : '';
            $szoveg = mb_substr($szoveg, 0, self::UZENET_HOSSZ);

            if ($szoveg === '') {
                wp_safe_redirect(add_query_arg('h', 'ures', $vissza) . '#uzenetek');
                return;
            }

            if (self::friss_ugyfeluzenetek((int) $munkalap->id) >= self::UZENET_MAX) {
                wp_safe_redirect(add_query_arg('h', 'sokuzenet', $vissza) . '#uzenetek');
                return;
            }

            self::uzenet_ment((int) $munkalap->id, (int) $munkalap->ugyfel_id, 'be', 'rma', $szoveg, 0);
            wp_safe_redirect(add_query_arg('ok', 'kuldve', $vissza) . '#uzenetek');
            return;
        }
        // phpcs:enable

        wp_safe_redirect($vissza);
    }

    /* =================================================================
     * Állapotnapló
     * ============================================================== */

    /** Állapotváltás naplózása (a Munkalap modul eseménye hívja). */
    public static function naplo_ir(int $munkalap_id, string $elozo, string $uj): void
    {
        global $wpdb;

        if ($munkalap_id <= 0 || $uj === '') {
            return;
        }

        $wpdb->insert(self::naplo_tabla(), [
            'munkalap_id' => $munkalap_id,
            'allapot'     => $uj,
            'elozo'       => $elozo,
            'felhasznalo' => get_current_user_id(),
            'letrehozva'  => current_time('mysql'),
        ]);
    }

    /**
     * Sémafrissítéskor: a napló nélküli munkalapok kapnak egy kezdősort
     * (a mostani állapot, a létrehozás idejével).
     */
    public static function naplo_potlas(): void
    {
        global $wpdb;

        $m = self::munkalap_tabla();
        $n = self::naplo_tabla();

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$n} (munkalap_id, allapot, elozo, felhasznalo, letrehozva)
                 SELECT m.id, m.allapot, '', m.letrehozo, COALESCE(m.letrehozva, m.modositva, %s)
                 FROM {$m} m
                 LEFT JOIN {$n} n ON n.munkalap_id = m.id
                 WHERE n.id IS NULL",
                current_time('mysql')
            )
        );
    }

    /**
     * @return array<int, object> A legfrissebb elöl.
     */
    public static function naplo(int $munkalap_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::naplo_tabla() . ' WHERE munkalap_id = %d ORDER BY letrehozva DESC, id DESC', $munkalap_id)
        ) ?: [];
    }

    /* =================================================================
     * Üzenetek
     * ============================================================== */

    /**
     * @return array<int, object> Időrendben (a legrégebbi elöl).
     */
    public static function uzenetek(int $munkalap_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::uzenet_tabla() . ' WHERE munkalap_id = %d ORDER BY letrehozva ASC, id ASC', $munkalap_id)
        ) ?: [];
    }

    /**
     * Üzenetszámlálók egy munkalapra.
     *
     * @return array{osszes: int, uj: int}
     */
    public static function szamlalo(int $munkalap_id): array
    {
        global $wpdb;

        $sor = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS osszes, SUM(CASE WHEN irany = 'be' AND olvasva = 0 THEN 1 ELSE 0 END) AS uj
                 FROM " . self::uzenet_tabla() . ' WHERE munkalap_id = %d',
                $munkalap_id
            )
        );

        return [
            'osszes' => $sor ? (int) $sor->osszes : 0,
            'uj'     => $sor ? (int) $sor->uj : 0,
        ];
    }

    /** Az összes olvasatlan ügyfélüzenet (az oldalmenü jelvénye). */
    public static function olvasatlan_db(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::uzenet_tabla() . " WHERE irany = 'be' AND olvasva = 0"
        );
    }

    private static function friss_ugyfeluzenetek(int $munkalap_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::uzenet_tabla() . " WHERE munkalap_id = %d AND irany = 'be' AND letrehozva >= %s",
                $munkalap_id,
                gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - HOUR_IN_SECONDS)
            )
        );
    }

    /**
     * Üzenet mentése és e-mail értesítés.
     *
     * Bejövő (ügyfél) üzenetnél a szerviz kap e-mailt; kimenőnél az ügyfél,
     * ha $email_ugyfelnek igaz és van érvényes e-mail címe.
     *
     * @return int Az új üzenet azonosítója (0 = hiba).
     */
    public static function uzenet_ment(int $munkalap_id, int $ugyfel_id, string $irany, string $csatorna, string $szoveg, int $felhasznalo, bool $email_ugyfelnek = false): int
    {
        global $wpdb;

        $irany = $irany === 'be' ? 'be' : 'ki';

        $ok = $wpdb->insert(self::uzenet_tabla(), [
            'munkalap_id'  => $munkalap_id,
            'ugyfel_id'    => $ugyfel_id,
            'irany'        => $irany,
            'csatorna'     => $csatorna,
            'szoveg'       => $szoveg,
            'felhasznalo'  => $felhasznalo,
            'olvasva'      => $irany === 'ki' ? 1 : 0,
            'email_kuldve' => 0,
            'letrehozva'   => current_time('mysql'),
        ]);

        if ($ok === false) {
            return 0;
        }

        $id       = (int) $wpdb->insert_id;
        $munkalap = self::munkalap($munkalap_id);
        $ugyfel   = self::ugyfel($ugyfel_id);
        $kuldve   = false;

        if ($munkalap !== null) {
            $kuldve = $irany === 'be'
                ? self::email_szerviznek($munkalap, $ugyfel, $szoveg)
                : ($email_ugyfelnek && self::email_ugyfelnek($munkalap, $ugyfel, $szoveg));
        }

        if ($kuldve) {
            $wpdb->update(self::uzenet_tabla(), ['email_kuldve' => 1], ['id' => $id]);
        }

        return $id;
    }

    private static function email_szerviznek(object $munkalap, ?object $ugyfel, string $szoveg): bool
    {
        $b   = self::beallitas();
        $cel = sanitize_email((string) $b['ertesites_email']);

        if ($cel === '' || !is_email($cel)) {
            return false;
        }

        $szam = SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam);
        $nev  = $ugyfel ? (string) $ugyfel->nev : '';

        $torzs = "Új üzenet érkezett az ügyféloldalról.\n\n"
            . 'Munkalap: ' . $szam . "\n"
            . 'Ügyfél: ' . $nev . ($ugyfel && $ugyfel->telefon !== '' ? ' (' . $ugyfel->telefon . ')' : '') . "\n\n"
            . "Üzenet:\n" . $szoveg . "\n\n"
            . 'A CRM-ben: ' . SDH_Muhely_Modulok::frontend_url(self::KULCS) . "\n";

        $fejlec = [];

        if ($ugyfel && is_email((string) $ugyfel->email)) {
            $fejlec[] = 'Reply-To: ' . self::fejlec_nev($nev) . ' <' . $ugyfel->email . '>';
        }

        return (bool) wp_mail($cel, '[SDH Műhely] Ügyfélüzenet – munkalap ' . $szam . ($nev !== '' ? ' – ' . $nev : ''), $torzs, $fejlec);
    }

    private static function email_ugyfelnek(object $munkalap, ?object $ugyfel, string $szoveg): bool
    {
        if ($ugyfel === null || !is_email((string) $ugyfel->email)) {
            return false;
        }

        $b    = self::beallitas();
        $szam = SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam);

        $elerhetoseg = array_filter([(string) $b['cim'], (string) $b['telefon'], (string) $b['email'], (string) $b['weboldal']]);

        $torzs = 'Kedves ' . (string) $ugyfel->nev . "!\n\n"
            . 'Üzenetet küldtünk a(z) ' . $szam . " számú munkalapjával kapcsolatban:\n\n"
            . $szoveg . "\n\n"
            . "A javítás állapotát megnézheti és válaszolhat itt:\n"
            . self::url(self::token($munkalap)) . "\n"
            . "(Belépés: telefonszám és munkalapszám.)\n\n"
            . $b['szerviz_nev']
            . ($elerhetoseg !== [] ? "\n" . implode("\n", $elerhetoseg) : '');

        $fejlec = [];
        $valasz = sanitize_email((string) $b['ertesites_email']);

        if ($valasz !== '' && is_email($valasz)) {
            $fejlec[] = 'Reply-To: ' . self::fejlec_nev((string) $b['szerviz_nev']) . ' <' . $valasz . '>';
        }

        return (bool) wp_mail((string) $ugyfel->email, $b['szerviz_nev'] . ' – üzenet a(z) ' . $szam . ' munkalaphoz', $torzs, $fejlec);
    }

    /** Név e-mail fejlécbe: sortörés és idézőjel nélkül. */
    private static function fejlec_nev(string $nev): string
    {
        $nev = trim(str_replace(["\r", "\n", '"', '<', '>'], ' ', $nev));

        return $nev === '' ? '' : '"' . $nev . '"';
    }

    private static function ajax_jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    /** Üzenet küldése a CRM-ből (AJAX). */
    public static function ajax_uzenet_kuld(): void
    {
        self::ajax_jog();

        $id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $szoveg   = isset($_POST['szoveg']) ? trim(sanitize_textarea_field(wp_unslash($_POST['szoveg']))) : '';
        $email    = !empty($_POST['email']);
        $munkalap = self::munkalap($id);

        if ($munkalap === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen munkalap.']);
        }

        if ($szoveg === '') {
            wp_send_json_error(['uzenet' => 'Írd be az üzenetet.']);
        }

        $uj = self::uzenet_ment($id, (int) $munkalap->ugyfel_id, 'ki', 'crm', mb_substr($szoveg, 0, self::UZENET_HOSSZ * 3), get_current_user_id(), $email);

        if ($uj <= 0) {
            wp_send_json_error(['uzenet' => 'Az adatbázis visszautasította az üzenetet.']);
        }

        self::olvasottra((int) $munkalap->id);

        global $wpdb;
        $kuldve = (int) $wpdb->get_var($wpdb->prepare('SELECT email_kuldve FROM ' . self::uzenet_tabla() . ' WHERE id = %d', $uj)) === 1;

        wp_send_json_success([
            'html'      => self::uzenetek_html((int) $munkalap->id, 'crm'),
            'email'     => $kuldve,
            'szamlalo'  => self::szamlalo((int) $munkalap->id),
            'olvasatlan' => self::olvasatlan_db(),
        ]);
    }

    /** A bejövő üzenetek olvasottra állítása (AJAX, a lapfül megnyitásakor). */
    public static function ajax_olvasva(): void
    {
        self::ajax_jog();

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        self::olvasottra($id);

        wp_send_json_success(['szamlalo' => self::szamlalo($id), 'olvasatlan' => self::olvasatlan_db()]);
    }

    /** Az olvasatlan üzenetek száma (AJAX, az oldalmenü jelvényének frissítéséhez). */
    public static function ajax_allapot(): void
    {
        self::ajax_jog();

        wp_send_json_success(['olvasatlan' => self::olvasatlan_db()]);
    }

    private static function olvasottra(int $munkalap_id): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::uzenet_tabla() . " SET olvasva = 1 WHERE munkalap_id = %d AND irany = 'be' AND olvasva = 0",
                $munkalap_id
            )
        );
    }

    /**
     * Az üzenetszál HTML-je a CRM-ben (a legfrissebb elöl, lapozható).
     */
    public static function uzenetek_html(int $munkalap_id, string $nezet = 'crm'): string
    {
        $uzenetek = array_reverse(self::uzenetek($munkalap_id));

        if ($uzenetek === []) {
            return '<p class="sdh-uz-ures">Még nincs üzenet ehhez a munkalaphoz.</p>';
        }

        $html = '';

        foreach ($uzenetek as $u) {
            if ($u->irany === 'ki') {
                $f  = (int) $u->felhasznalo > 0 ? get_userdata((int) $u->felhasznalo) : false;
                $ki = $f ? (string) $f->display_name : 'Szerviz';
            } else {
                $ki = 'Ügyfél';
            }

            $jelek = [];

            if ($u->irany === 'be' && (int) $u->olvasva === 0) {
                $jelek[] = '<b class="sdh-uz__uj">új</b>';
            }

            if ((int) $u->email_kuldve === 1) {
                $jelek[] = '<span>e-mail</span>';
            }

            $html .= sprintf(
                '<div class="sdh-uz%s"><div class="sdh-uz__fej"><b>%s</b> <span>%s</span>%s</div><div class="sdh-uz__szoveg">%s</div></div>',
                $u->irany === 'ki' ? ' sdh-uz--sajat' : ' sdh-uz--ugyfel',
                esc_html($ki),
                esc_html(SDH_Muhely_Munkalap::datumido_megjelenit((string) $u->letrehozva)),
                $jelek !== [] ? ' ' . implode(' ', $jelek) : '',
                nl2br(esc_html((string) $u->szoveg))
            );
        }

        return $html;
    }

    /* =================================================================
     * CRM: címke-blokk és az RMA lapfül
     * ============================================================== */

    /**
     * A címke tartalma (QR + szerviz + sorszám + ügyfél + vonalkód) –
     * ugyanaz a nyomtatott címkén, az Áttekintésben és a munkalap-ablakban.
     */
    public static function cimke_blokk(object $munkalap, ?object $ugyfel, ?object $eszkoz): string
    {
        $szam = SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam);
        $url  = self::url(self::token($munkalap));
        $b    = self::beallitas();

        $sorok = '';

        if ($ugyfel) {
            $sorok .= '<div class="sdh-cimke__sor">' . esc_html((string) $ugyfel->nev) . '</div>';
        }

        if ($eszkoz) {
            $sorok .= '<div class="sdh-cimke__sor">' . esc_html(SDH_Muhely_Eszkoz::megnevezes($eszkoz)) . '</div>';
        }

        $atveve = SDH_Muhely_Munkalap::datum_megjelenit((string) $munkalap->keszult);

        if ($atveve !== '') {
            $sorok .= '<div class="sdh-cimke__sor">Átvéve: ' . esc_html($atveve) . '</div>';
        }

        return '<div class="sdh-cimke">'
            . '<div class="sdh-cimke__qr">' . SDH_Muhely_Kodok::qr_svg($url) . '</div>'
            . '<div class="sdh-cimke__adat">'
            . '<div class="sdh-cimke__ceg">' . esc_html((string) $b['szerviz_nev']) . '</div>'
            . '<div class="sdh-cimke__szam">' . esc_html($szam) . '</div>'
            . $sorok
            . '</div>'
            . '<div class="sdh-cimke__vk">' . SDH_Muhely_Kodok::vonalkod_svg($szam, false, 'sdh-vonalkod', true)
            . '<div class="sdh-cimke__vkszam">' . esc_html($szam) . '</div></div>'
            . '<div class="sdh-cimke__sugo">Javítás állapota: olvassa be a QR-kódot, és lépjen be a telefonszámával és a munkalap sorszámával.</div>'
            . '</div>';
    }

    /** Az RMA lapfül jelvénye: üzenetszám, piros, ha van olvasatlan. */
    public static function ful_jelveny(int $munkalap_id, string $osztaly = 'sdh-fulek__db'): string
    {
        $sz = self::szamlalo($munkalap_id);

        return sprintf(
            '<span class="%s%s" data-sdh-db="rma"%s>%d</span>',
            esc_attr($osztaly),
            $sz['uj'] > 0 ? ' is-uj' : '',
            $sz['osszes'] > 0 ? '' : ' hidden',
            $sz['osszes']
        );
    }

    /**
     * Az RMA lapfül tartalma. Ugyanez a munkalap-ablakban (Termékek mellett)
     * és az Áttekintés részletpanelén. Űrlapon belül is áll, ezért nincs
     * benne <form>: a küldést az rma.js intézi gombnyomásra.
     */
    public static function munkalap_ful(?object $munkalap): void
    {
        if ($munkalap === null || (int) $munkalap->id <= 0) {
            echo '<p class="sdh-rma__ures">Az RMA (QR-kód, ügyféloldal, üzenetek) a munkalap mentése után érhető el.</p>';

            return;
        }

        $id        = (int) $munkalap->id;
        $szamozott = (int) $munkalap->munkalap_szam > 0;
        $ugyfel    = self::ugyfel((int) $munkalap->ugyfel_id);
        $eszkoz    = self::eszkoz((int) $munkalap->eszkoz_id);
        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $b         = self::beallitas();
        $van_email = $ugyfel && is_email((string) $ugyfel->email);

        echo '<div class="sdh-rma" data-sdh-rma="' . $id . '">';

        // --- Címke ---------------------------------------------------------
        echo '<div class="sdh-rma__kodok">';

        if ($szamozott) {
            $token = self::token($munkalap);

            echo self::cimke_blokk($munkalap, $ugyfel, $eszkoz); // phpcs:ignore WordPress.Security.EscapeOutput
            printf(
                '<div class="sdh-rma__gombok">'
                . '<a class="sdh-gomb" href="%s" target="_blank" rel="noopener">Címke nyomtatása</a>'
                . '<a class="sdh-gomb" href="%s" target="_blank" rel="noopener">Ügyféloldal</a>'
                . '<button type="button" class="sdh-gomb" data-sdh-rma-masol="%s">Link másolása</button>'
                . '</div>',
                esc_url(self::cimke_url($id)),
                esc_url(add_query_arg('elonezet', '1', self::helyi_url($token))),
                esc_attr(self::url($token))
            );
        } else {
            echo '<p class="sdh-rma__ures">A QR-kód és a vonalkód akkor készül el, amikor a munkalap sorszámot kap (számozott állapotba kerül).</p>';
        }

        echo '</div>';

        // --- Üzenetek ------------------------------------------------------
        echo '<div class="sdh-rma__uzenetek"><div class="sdh-rma__cim">Üzenetek az ügyféllel</div>';

        if ($ugyfel !== null) {
            printf(
                '<div class="sdh-uz-urlap" data-sdh-uzenet-urlap data-id="%d">'
                . '<textarea rows="2" data-sdh-uzenet-szoveg aria-label="Üzenet az ügyfélnek" placeholder="Üzenet az ügyfélnek – az ügyféloldalon látja, és válaszolhat rá (Ctrl+Enter: küldés)"></textarea>'
                . '<div class="sdh-uz-urlap__lab">'
                . '<label class="sdh-uz-urlap__jelolo"><input type="checkbox" data-sdh-uzenet-email value="1"%s%s> %s</label>'
                . '<button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-uzenet-kuld>Küldés</button></div>'
                . '<p class="sdh-uz-urlap__hiba" data-sdh-uzenet-hiba hidden></p></div>',
                $id,
                $van_email && $b['ugyfel_email'] ? ' checked' : '',
                $van_email ? '' : ' disabled',
                esc_html($van_email ? 'E-mail is: ' . $ugyfel->email : 'E-mail is – az ügyfélnek nincs e-mail címe')
            );
        } else {
            echo '<p class="sdh-rma__ures">Üzenetet ügyfélhez rendelt munkalapon lehet küldeni.</p>';
        }

        echo '<div class="sdh-uz-szal" data-sdh-uzenetek data-sdh-lapoz="3">' . self::uzenetek_html($id) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</div>';

        // --- Állapottörténet -----------------------------------------------
        echo '<div class="sdh-rma__naplo"><div class="sdh-rma__cim">Állapottörténet</div>';
        $naplo = self::naplo($id);

        if ($naplo === []) {
            echo '<p class="sdh-rma__ures">Még nincs bejegyzés.</p>';
        } else {
            echo '<ol class="sdh-rma-naplo" data-sdh-lapoz="6">';

            foreach ($naplo as $sor) {
                $f  = (int) $sor->felhasznalo > 0 ? get_userdata((int) $sor->felhasznalo) : false;

                printf(
                    '<li>%s <span>%s%s</span></li>',
                    SDH_Muhely_Munkalap::jelveny((string) $sor->allapot, $allapotok), // phpcs:ignore WordPress.Security.EscapeOutput
                    esc_html(SDH_Muhely_Munkalap::datumido_megjelenit((string) $sor->letrehozva)),
                    $f ? esc_html(' · ' . $f->display_name) : ''
                );
            }

            echo '</ol>';
        }

        echo '</div></div>';
    }

    /* =================================================================
     * Olvasatlan-jelvény a wp-admin menüben
     * ============================================================== */

    public static function admin_menu_jelveny(): void
    {
        global $menu, $submenu;

        $db = self::olvasatlan_db();

        if ($db <= 0) {
            return;
        }

        $jel = ' <span class="awaiting-mod count-' . $db . '"><span class="pending-count">' . $db . '</span></span>';

        foreach ((array) $menu as $i => $elem) {
            if (($elem[2] ?? '') === SDH_Muhely_Admin_UI::FOMENU) {
                $menu[$i][0] .= $jel; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            }
        }

        foreach ((array) ($submenu[SDH_Muhely_Admin_UI::FOMENU] ?? []) as $i => $elem) {
            if (($elem[2] ?? '') === SDH_Muhely_Admin_UI::FOMENU . '-' . self::KULCS) {
                $submenu[SDH_Muhely_Admin_UI::FOMENU][$i][0] .= $jel; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            }
        }
    }

    /* =================================================================
     * Üzenetek – postafiók (oldalmenü)
     * ============================================================== */

    public static function posta_oldal(): void
    {
        global $wpdb;

        $u        = self::uzenet_tabla();
        $csak_uj  = !isset($_GET['mind']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $oldal    = isset($_GET['oldalszam']) ? max(1, (int) $_GET['oldalszam']) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $olvasatlan = self::olvasatlan_db();

        // Olvasatlan nélkül az „Összes" nézet nyíljon.
        if ($csak_uj && $olvasatlan === 0) {
            $csak_uj = false;
        }

        $szuro = $csak_uj ? "HAVING SUM(CASE WHEN irany = 'be' AND olvasva = 0 THEN 1 ELSE 0 END) > 0" : '';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $osszes = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT munkalap_id FROM {$u} GROUP BY munkalap_id {$szuro}) t");

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT munkalap_id, COUNT(*) AS db, MAX(id) AS utolso_id, MAX(letrehozva) AS utolso,
                        SUM(CASE WHEN irany = 'be' AND olvasva = 0 THEN 1 ELSE 0 END) AS uj
                 FROM {$u} GROUP BY munkalap_id {$szuro}
                 ORDER BY utolso DESC, utolso_id DESC LIMIT %d OFFSET %d",
                self::POSTA_OLDAL,
                ($oldal - 1) * self::POSTA_OLDAL
            )
        ) ?: [];
        // phpcs:enable

        $ml_idk   = array_map(static fn (object $s): int => (int) $s->munkalap_id, $sorok);
        $utolsok  = [];
        $lapok    = [];
        $ugyfelek = [];
        $eszkozok = [];

        if ($sorok !== []) {
            $idk = implode(',', array_map(static fn (object $s): int => (int) $s->utolso_id, $sorok));

            foreach ($wpdb->get_results("SELECT * FROM {$u} WHERE id IN ({$idk})") ?: [] as $x) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $utolsok[(int) $x->munkalap_id] = $x;
            }

            $ml_lista = implode(',', $ml_idk);

            foreach ($wpdb->get_results('SELECT * FROM ' . self::munkalap_tabla() . " WHERE id IN ({$ml_lista})") ?: [] as $x) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $lapok[(int) $x->id] = $x;
            }

            $u_idk = array_filter(array_map(static fn (object $m): int => (int) $m->ugyfel_id, $lapok));
            $e_idk = array_filter(array_map(static fn (object $m): int => (int) $m->eszkoz_id, $lapok));

            if ($u_idk !== []) {
                foreach ($wpdb->get_results('SELECT * FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id IN (' . implode(',', $u_idk) . ')') ?: [] as $x) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $ugyfelek[(int) $x->id] = $x;
                }
            }

            if ($e_idk !== []) {
                foreach ($wpdb->get_results('SELECT * FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' WHERE id IN (' . implode(',', $e_idk) . ')') ?: [] as $x) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $eszkozok[(int) $x->id] = $x;
                }
            }
        }

        $oldalak   = max(1, (int) ceil($osszes / self::POSTA_OLDAL));
        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $url       = static fn (array $p = []): string => SDH_Muhely_Modulok::url(self::KULCS, $p);

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::fejlec(
                'Üzenetek',
                'Az ügyfelek üzenetei munkalaponként. Sorra kattintva a munkalap RMA lapfüle nyílik meg – ott válaszolhatsz.'
            );
            ?>

            <div class="sdh-kereso">
                <a class="sdh-gomb<?php echo $csak_uj ? ' sdh-gomb--elsodleges' : ''; ?>" href="<?php echo esc_url($url()); ?>">
                    Olvasatlan <span class="sdh-fulek__db<?php echo $olvasatlan > 0 ? ' is-uj' : ''; ?>"><?php echo (int) $olvasatlan; ?></span>
                </a>
                <a class="sdh-gomb<?php echo $csak_uj ? '' : ' sdh-gomb--elsodleges'; ?>" href="<?php echo esc_url($url(['mind' => 1])); ?>">Összes beszélgetés</a>
                <span class="sdh-kereso__talalat"><?php echo esc_html(number_format_i18n($osszes)); ?> munkalap</span>
            </div>

            <table class="sdh-tabla sdh-posta">
                <thead>
                    <tr>
                        <th>Munkalap</th>
                        <th>Ügyfél</th>
                        <th class="sdh-tabla__rejtheto">Eszköz</th>
                        <th>Utolsó üzenet</th>
                        <th>Időpont</th>
                        <th class="sdh-tabla__szam">Üzenet</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($sorok === []) : ?>
                    <tr><td colspan="6" class="sdh-tabla__ures"><?php echo esc_html($csak_uj ? 'Nincs olvasatlan üzenet.' : 'Még nincs üzenet.'); ?></td></tr>
                <?php endif; ?>

                <?php foreach ($sorok as $s) :
                    $mid   = (int) $s->munkalap_id;
                    $ml    = $lapok[$mid] ?? null;
                    $ut    = $utolsok[$mid] ?? null;
                    $ugy   = $ml ? ($ugyfelek[(int) $ml->ugyfel_id] ?? null) : null;
                    $esz   = $ml ? ($eszkozok[(int) $ml->eszkoz_id] ?? null) : null;
                    $uj    = (int) $s->uj;
                    $szov  = $ut ? wp_html_excerpt((string) $ut->szoveg, 90, '…') : '';
                    ?>
                    <tr class="sdh-posta__sor<?php echo $uj > 0 ? ' is-uj' : ''; ?>"
                        data-sdh-urlap="munkalapok" data-sdh-id="<?php echo $mid; ?>" data-sdh-ful="rma"
                        data-sdh-posta="<?php echo $mid; ?>" tabindex="0">
                        <td>
                            <b><?php echo esc_html($ml ? SDH_Muhely_Munkalap::szam_formaz($ml->munkalap_szam) : '#' . $mid); ?></b>
                            <?php if ($ml) { echo ' ' . SDH_Muhely_Munkalap::jelveny((string) $ml->allapot, $allapotok); } // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        </td>
                        <td><?php echo esc_html($ugy ? (string) $ugy->nev : '—'); ?></td>
                        <td class="sdh-tabla__rejtheto sdh-tabla__halvany"><?php echo esc_html($esz ? SDH_Muhely_Eszkoz::megnevezes($esz) : '—'); ?></td>
                        <td class="sdh-posta__szoveg">
                            <span class="sdh-posta__ki"><?php echo esc_html($ut && $ut->irany === 'be' ? 'Ügyfél:' : 'Szerviz:'); ?></span>
                            <?php echo esc_html($szov); ?>
                        </td>
                        <td class="sdh-tabla__halvany"><?php echo esc_html(SDH_Muhely_Munkalap::datumido_megjelenit((string) $s->utolso)); ?></td>
                        <td class="sdh-tabla__szam">
                            <span class="sdh-fulek__db<?php echo $uj > 0 ? ' is-uj' : ''; ?>" title="<?php echo esc_attr($uj > 0 ? $uj . ' olvasatlan' : 'nincs olvasatlan'); ?>">
                                <?php echo $uj > 0 ? (int) $uj . ' új' : (int) $s->db; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($oldalak > 1) : ?>
                <div class="sdh-lapozas">
                    <?php for ($i = 1; $i <= $oldalak; $i++) : ?>
                        <a class="sdh-gomb<?php echo $i === $oldal ? ' sdh-gomb--elsodleges' : ''; ?>"
                           href="<?php echo esc_url($url(array_filter(['mind' => $csak_uj ? null : 1, 'oldalszam' => $i]))); ?>"><?php echo (int) $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =================================================================
     * Nyomtatható címke (belépéshez kötött)
     * ============================================================== */

    private static function cimke_oldal(int $id): void
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(add_query_arg(self::CIMKE_VAR, $id, home_url('/'))));
            exit;
        }

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_die('Nincs jogosultságod ehhez.', '', ['response' => 403]);
        }

        SDH_Muhely_Schema::frissites_ha_kell();

        $munkalap = self::munkalap($id);

        if ($munkalap === null || (int) $munkalap->munkalap_szam <= 0) {
            wp_die('Ennek a munkalapnak még nincs sorszáma, ezért címke sem nyomtatható.', '', ['response' => 404]);
        }

        $ugyfel = self::ugyfel((int) $munkalap->ugyfel_id);
        $eszkoz = self::eszkoz((int) $munkalap->eszkoz_id);
        $szam   = SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam);

        nocache_headers();
        status_header(200);

        ?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>Címke – <?php echo esc_html($szam); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/admin.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/admin.css')); ?>">
<style>
    @page { size: auto; margin: 8mm; }
    body { margin: 0; font: 13px/1.35 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #000; background: #fff; }
    .eszkoztar { padding: 12px 16px; background: #f3f3f3; border-bottom: 1px solid #ddd; display: flex; gap: 8px; align-items: center; }
    .eszkoztar button { font: inherit; padding: 6px 14px; border: 1px solid #999; border-radius: 6px; background: #fff; cursor: pointer; }
    .sdh-cimke { width: 92mm; margin: 12px; border: 1px dashed #999; padding: 4mm; }
    @media print { .eszkoztar { display: none; } .sdh-cimke { margin: 0; border: 0; } }
</style>
</head>
<body>
<div class="eszkoztar">
    <button type="button" onclick="window.print()">Nyomtatás</button>
    <span>Munkalap <?php echo esc_html($szam); ?> – címke (QR + vonalkód)</span>
</div>
<?php echo self::cimke_blokk($munkalap, $ugyfel, $eszkoz); // phpcs:ignore WordPress.Security.EscapeOutput ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
</body>
</html>
        <?php
    }
}
