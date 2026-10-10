<?php
/**
 * Közös arculat.
 *
 * A rendszer két felületen jelenik meg – a saját frontendjén és a
 * wp-adminban –, és mindkettő ezeket az építőelemeket használja:
 * oldalfejléc, értesítés, jogosultság-ellenőrzés, áttekintő tartalom.
 *
 * A modulok nem regisztrálnak menüt maguknak: a SDH_Muhely_Modulok
 * nyilvántartásába jelentkeznek be, és az admin-menü abból épül fel.
 * Így egy modul megírásakor nem kell tudni, hány felületen fog látszani.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Admin_UI
{
    /** A főmenü slugja. Minden almenü ez alá kerül. */
    public const FOMENU = 'sdh-muhely';

    /**
     * A rendszer használatának joga (0.38). Az adminisztrátor szerepkör
     * megkapja, és a „Kolléga" szerepkör (SDH_Muhely_Csapat) is ezt kapja.
     */
    public const JOG = 'sdh_muhely_hasznal';

    /** Beállítások, karbantartás, kollégák kezelése: csak adminisztrátor. */
    public const ADMIN_JOG = 'manage_options';

    public static function init(): void
    {
        // Későn fut, hogy addigra minden modul bejelentkezzen.
        add_action('admin_menu', [self::class, 'menu'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'eszkozok']);
        add_action('wp_ajax_sdh_muhely_globalis_kereso', [self::class, 'ajax_kereso']);

        // Minden saját AJAX-válasz megmondja, melyik CSS/JS verzió a friss, így a
        // már megnyitott oldal észreveszi, ha közben fájlcsere történt.
        add_action('admin_init', [self::class, 'verzio_fejlec'], 1);

        // Aki adminisztrátor, az mindig használhatja a rendszert (akkor is,
        // ha a szerepkörébe valamiért nem íródott be a jog); a letiltott
        // kollégától a jog elvész.
        add_filter('user_has_cap', [self::class, 'jog_szuro'], 10, 4);
        add_action('init', [self::class, 'jog_telepit'], 5);
    }

    /**
     * @param array<string, bool> $osszes
     * @param array<int, string>  $kert
     * @param array<int, mixed>   $args
     * @return array<string, bool>
     */
    public static function jog_szuro(array $osszes, array $kert, array $args, $felhasznalo): array
    {
        if (!in_array(self::JOG, $kert, true)) {
            return $osszes;
        }

        if (!empty($osszes[self::ADMIN_JOG])) {
            $osszes[self::JOG] = true;
        }

        $id = is_object($felhasznalo) && isset($felhasznalo->ID) ? (int) $felhasznalo->ID : 0;

        if ($id > 0 && get_user_meta($id, 'sdh_letiltva', true) === '1') {
            $osszes[self::JOG] = false;
        }

        return $osszes;
    }

    /** Egyszer: az adminisztrátor szerepkör megkapja a használat jogát (a felhasználólisták miatt). */
    public static function jog_telepit(): void
    {
        if (get_option('sdh_muhely_jog_telepitve') === self::JOG) {
            return;
        }

        $szerep = get_role('administrator');

        if ($szerep !== null && !$szerep->has_cap(self::JOG)) {
            $szerep->add_cap(self::JOG);
        }

        update_option('sdh_muhely_jog_telepitve', self::JOG);
    }

    /**
     * `X-SDH-Verzio: <css>|<js>` fejléc a saját AJAX-kérésekre. Az app.js ebből
     * tudja, hogy az oldal elavult stíluslappal / szkripttel fut-e.
     */
    public static function verzio_fejlec(): void
    {
        if (!wp_doing_ajax() || headers_sent()) {
            return;
        }

        $akcio = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';

        if (strpos($akcio, 'sdh_muhely_') !== 0) {
            return;
        }

        header('X-SDH-Verzio: ' . self::eszkoz_verzio('assets/admin.css') . '|' . self::eszkoz_verzio('assets/app.js'));
    }

    /* =================================================================
     * Globális kereső
     * ============================================================== */

    /**
     * A fejlécben lévő kereső: egyszerre néz ügyfelet és eszközt.
     *
     * A pultnál ez a leggyakoribb mozdulat – az ügyfél mond egy nevet,
     * egy telefonszámot vagy egy IMEI-t, és abból kell eljutni a
     * rekordhoz. Ezért nem modulonként külön keresünk.
     */
    public static function ajax_kereso(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(self::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        global $wpdb;

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

        if (mb_strlen($q) < 2) {
            wp_send_json_success([]);
        }

        // A kereső a frontendről jön, de adminból is hívható – a
        // találatok oda mutassanak, ahonnan kerestek.
        SDH_Muhely_Modulok::kontextus_beallit(
            isset($_GET['kontextus']) && sanitize_key(wp_unslash($_GET['kontextus'])) === 'admin'
                ? 'admin'
                : 'frontend'
        );

        $minta = '%' . $wpdb->esc_like($q) . '%';

        $ugyfel_tabla = SDH_Muhely_Schema::tabla('ugyfel');
        $eszkoz_tabla = SDH_Muhely_Schema::tabla('eszkoz');

        $ugyfelek = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, nev, telefon, szamlazasi_telepules, ugyfel_szam
                 FROM {$ugyfel_tabla}
                 WHERE aktiv = 1
                   AND (nev LIKE %s OR telefon LIKE %s OR telefon2 LIKE %s
                        OR email LIKE %s OR ugyfel_szam LIKE %s)
                 ORDER BY nev ASC LIMIT 5",
                $minta,
                $minta,
                $minta,
                $minta,
                $minta
            )
        );

        $eszkozok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.id, e.gyarto, e.tipus, e.megnevezes, e.imei, e.sorozatszam,
                        u.nev AS ugyfel_nev
                 FROM {$eszkoz_tabla} e
                 LEFT JOIN {$ugyfel_tabla} u ON u.id = e.ugyfel_id
                 WHERE e.aktiv = 1
                   AND (e.imei LIKE %s OR e.imei2 LIKE %s OR e.sorozatszam LIKE %s
                        OR e.tipus LIKE %s OR e.megnevezes LIKE %s OR e.modell_szam LIKE %s)
                 ORDER BY e.modositva DESC LIMIT 5",
                $minta,
                $minta,
                $minta,
                $minta,
                $minta,
                $minta
            )
        );

        $csoportok = [];

        if ($ugyfelek !== []) {
            $talalatok = [];

            foreach ($ugyfelek as $sor) {
                $talalatok[] = [
                    'cim'     => $sor->nev,
                    'reszlet' => implode(
                        ' · ',
                        array_filter([$sor->telefon, $sor->szamlazasi_telepules, $sor->ugyfel_szam])
                    ),
                    'url'     => SDH_Muhely_Modulok::url(
                        SDH_Muhely_Ugyfel::KULCS,
                        ['nezet' => 'szerkeszt', 'id' => (int) $sor->id]
                    ),
                ];
            }

            $csoportok[] = ['cim' => 'Ügyfelek', 'talalatok' => $talalatok];
        }

        if ($eszkozok !== []) {
            $talalatok = [];

            foreach ($eszkozok as $sor) {
                $talalatok[] = [
                    'cim'     => trim($sor->gyarto . ' ' . $sor->tipus) ?: 'Névtelen eszköz',
                    'reszlet' => implode(
                        ' · ',
                        array_filter([$sor->megnevezes, $sor->imei ?: $sor->sorozatszam, $sor->ugyfel_nev])
                    ),
                    'url'     => SDH_Muhely_Modulok::url(
                        SDH_Muhely_Eszkoz::KULCS,
                        ['nezet' => 'szerkeszt', 'id' => (int) $sor->id]
                    ),
                ];
            }

            $csoportok[] = ['cim' => 'Eszközök', 'talalatok' => $talalatok];
        }

        wp_send_json_success($csoportok);
    }

    /**
     * Ki használhatja a rendszert: az adminisztrátor és a kolléga (0.38).
     * Szűrhető, ha később finomabb szerepkörök jönnek.
     */
    public static function jog(): string
    {
        return (string) apply_filters('sdh_muhely_jogosultsag', self::JOG);
    }

    public static function jog_ellenoriz(): void
    {
        if (!current_user_can(self::jog())) {
            wp_die(esc_html__('Nincs jogosultságod ehhez az oldalhoz.', 'sdh-muhely'));
        }
    }

    /** Beállítások és karbantartás: csak adminisztrátor. */
    public static function admin_e(): bool
    {
        return current_user_can(self::ADMIN_JOG);
    }

    public static function admin_jog_ellenoriz(): void
    {
        if (!self::admin_e()) {
            wp_die(esc_html__('Ehhez az oldalhoz adminisztrátori jog kell.', 'sdh-muhely'));
        }
    }

    /**
     * Régi hívásokhoz megtartott URL-segéd.
     *
     * Új kódban a SDH_Muhely_Modulok::url() a helyes, mert az tudja,
     * melyik felületen vagyunk.
     *
     * @param array<string, mixed> $parameterek
     */
    public static function url(string $slug, array $parameterek = []): string
    {
        return add_query_arg(
            array_merge(['page' => $slug], $parameterek),
            admin_url('admin.php')
        );
    }

    /* =================================================================
     * Menü
     * ============================================================== */

    public static function menu(): void
    {
        $jog = self::jog();

        add_menu_page(
            'SDH Műhely',
            'SDH Műhely',
            $jog,
            self::FOMENU,
            [self::class, 'admin_attekintes'],
            'dashicons-hammer',
            3
        );

        add_submenu_page(
            self::FOMENU,
            'Áttekintés',
            'Áttekintés',
            $jog,
            self::FOMENU,
            [self::class, 'admin_attekintes']
        );

        foreach (SDH_Muhely_Modulok::osszes() as $kulcs => $modul) {
            if (!empty($modul['keszul'])) {
                continue;
            }

            $render = $modul['render'];

            add_submenu_page(
                self::FOMENU,
                (string) $modul['cim'],
                (string) $modul['cim'],
                !empty($modul['csak_admin']) ? self::ADMIN_JOG : $jog,
                self::FOMENU . '-' . $kulcs,
                static function () use ($render): void {
                    self::jog_ellenoriz();
                    SDH_Muhely_Modulok::kontextus_beallit('admin');

                    echo '<div class="wrap">';
                    call_user_func($render);
                    echo '</div>';
                }
            );
        }
    }

    /* =================================================================
     * Eszközök (CSS)
     * ============================================================== */

    public static function eszkozok(string $hook): void
    {
        $oldal = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if (!str_starts_with($oldal, self::FOMENU)) {
            return;
        }

        wp_enqueue_style(
            'sdh-muhely-admin',
            SDH_MUHELY_URL . 'assets/admin.css',
            [],
            self::eszkoz_verzio('assets/admin.css')
        );

        wp_enqueue_script(
            'sdh-muhely-app',
            SDH_MUHELY_URL . 'assets/app.js',
            [],
            self::eszkoz_verzio('assets/app.js'),
            true
        );

        wp_add_inline_script(
            'sdh-muhely-app',
            'window.SDH_MUHELY = ' . wp_json_encode(self::js_beallitas('admin')) . ';',
            'before'
        );

        // A munkalap-rács csak a kezdőképernyőn él.
        if ($oldal === self::FOMENU) {
            wp_enqueue_style(
                'sdh-muhely-racs',
                SDH_MUHELY_URL . 'assets/racs.css',
                ['sdh-muhely-admin'],
                self::eszkoz_verzio('assets/racs.css')
            );

            wp_enqueue_script(
                'sdh-muhely-racs',
                SDH_MUHELY_URL . 'assets/racs.js',
                ['sdh-muhely-app'],
                self::eszkoz_verzio('assets/racs.js'),
                true
            );
        }

        // Csapat (belső üzenetek, pulzus) és az AI-asszisztens: minden saját oldalon.
        foreach (['csapat', 'asszisztens'] as $sdh_eszkoz) {
            wp_enqueue_style('sdh-muhely-' . $sdh_eszkoz, SDH_MUHELY_URL . 'assets/' . $sdh_eszkoz . '.css', ['sdh-muhely-admin'], self::eszkoz_verzio('assets/' . $sdh_eszkoz . '.css'));
            wp_enqueue_script('sdh-muhely-' . $sdh_eszkoz, SDH_MUHELY_URL . 'assets/' . $sdh_eszkoz . '.js', ['sdh-muhely-app'], self::eszkoz_verzio('assets/' . $sdh_eszkoz . '.js'), true);
        }

        // RMA (üzenetküldés, lapozás, olvasatlan-jelvény): minden oldalon,
        // mert a munkalap-popup bárhol megnyílhat.
        wp_enqueue_script(
            'sdh-muhely-rma',
            SDH_MUHELY_URL . 'assets/rma.js',
            ['sdh-muhely-app'],
            self::eszkoz_verzio('assets/rma.js'),
            true
        );
    }

    /**
     * A JavaScriptnek átadott beállítások.
     *
     * Egy helyen, mert a két felület ugyanazt az app.js-t használja, csak
     * más kontextussal.
     *
     * @return array<string, string>
     */
    public static function js_beallitas(string $kontextus): array
    {
        $arculat = class_exists('SDH_Muhely_Arculat')
            ? SDH_Muhely_Arculat::beallitas()
            : ['tema' => 'rendszer', 'szin' => ''];

        return [
            'ajax'      => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('sdh_muhely_modal'),
            'kontextus' => $kontextus,
            'alapTema'  => $arculat['tema'],
            'alapSzin'  => $arculat['szin'],
            // Az irányítószám-lista verziója: a böngésző egy napig tárolja a
            // listát, új adatfájlnál ettől kéri le azonnal az újat.
            'iszVerzio' => self::eszkoz_verzio('data/iranyitoszam.csv.gz'),
            // Levelezés: van-e bekötött fiók (csak akkor indul az új levelek figyelése), és hol a levelező.
            'level'     => class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Levelezes::van_fiok(),
            'levelUrl'  => SDH_Muhely_Modulok::url('levelezes'),
            'levelRendezo' => class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Levelezes::rendezo_be(),
            // Csapat (0.38): adminisztrátor-e (kitűzés másnak az üzenetén), AI-asszisztens adatai.
            'admin'     => self::admin_e(),
            'ai'        => class_exists('SDH_Muhely_Asszisztens') ? SDH_Muhely_Asszisztens::js_adat() : ['be' => false],
        ];
    }

    /* =================================================================
     * Oldalváz
     * ============================================================== */

    /**
     * Az oldal fejléce: cím, alcím, jobbra igazított gombok.
     *
     * Egy gomb kaphat `adatok` kulcsot is: abból data-* attribútumok
     * lesznek. Így tud egy gomb popupot nyitni, miközben a href-je
     * JavaScript nélkül is működő oldalra mutat.
     *
     * @param array<int, array{cimke: string, url: string, elsodleges?: bool, adatok?: array<string, string>}> $gombok
     */
    public static function fejlec(string $cim, string $alcim = '', array $gombok = []): void
    {
        ?>
        <div class="sdh-fejlec">
            <div class="sdh-fejlec__szoveg">
                <p class="sdh-fejlec__kalap">SDH Műhely</p>
                <h1 class="sdh-fejlec__cim"><?php echo esc_html($cim); ?></h1>
                <?php if ($alcim !== '') : ?>
                    <p class="sdh-fejlec__alcim"><?php echo esc_html($alcim); ?></p>
                <?php endif; ?>
            </div>

            <?php if ($gombok !== []) : ?>
                <div class="sdh-fejlec__gombok">
                    <?php foreach ($gombok as $gomb) : ?>
                        <a href="<?php echo esc_url($gomb['url']); ?>"
                           class="sdh-gomb <?php echo !empty($gomb['elsodleges']) ? 'sdh-gomb--elsodleges' : ''; ?>"
                           <?php
                            foreach ($gomb['adatok'] ?? [] as $nev => $ertek) {
                                printf(' data-%s="%s"', esc_attr($nev), esc_attr($ertek));
                            }
                           ?>>
                            <?php
                            // A „+ …" kezdetű felirat pluszjele rajzolt ikon (admin.css: .sdh-plusz), hogy pontosan középen üljön.
                            if (strpos((string) $gomb['cimke'], '+ ') === 0) {
                                echo '<span class="sdh-plusz" aria-hidden="true"></span>' . esc_html(substr((string) $gomb['cimke'], 2));
                            } else {
                                echo esc_html($gomb['cimke']);
                            }
                            ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Értesítés megjelenítése az `uzenet` lekérdezési paraméter alapján.
     *
     * A mentés után átirányítunk, és az üzenet kulcsát URL-ben hozzuk át –
     * így a frissítés nem küldi be újra az űrlapot.
     */
    public static function uzenet(): void
    {
        $kulcs = isset($_GET['uzenet']) ? sanitize_key(wp_unslash($_GET['uzenet'])) : '';

        if ($kulcs === '') {
            return;
        }

        $uzenetek = [
            'mentve'            => ['siker', 'Elmentve.'],
            'letrehozva'        => ['siker', 'Az ügyfél létrejött.'],
            'letrehozva_eszkoz' => ['siker', 'Az eszköz létrejött.'],
            'munkalap_mentve'      => ['siker', 'A munkalap elmentve.'],
            'munkalap_letrehozva'  => ['siker', 'A munkalap létrejött.'],
            'munkalap_hiba'        => ['hiba',  'A munkalap nem menthető.'],
            'inaktivalva'       => ['siker', 'Az ügyfél inaktívra állítva.'],
            'aktivalva'         => ['siker', 'Az ügyfél újra aktív.'],
            'hianyzo_nev'       => ['hiba',  'A név kitöltése kötelező – e nélkül nem menthető az ügyfél.'],
            'hianyzo_ugyfel'    => ['hiba',  'Válassz ügyfelet a listából – eszköz ügyfél nélkül nem vihető fel.'],
            'nincs_ilyen'       => ['hiba',  'Nincs ilyen rekord. Lehet, hogy időközben törölték.'],
            'mentes_hiba'       => ['hiba',  'A mentés nem sikerült. Az adatbázis visszautasította a műveletet.'],
            'demo_betoltve'     => ['siker', 'A demó adatok betöltve.'],
            'szolg_mentve'      => ['siker', 'A szolgáltatás elmentve.'],
            'szolg_letrehozva'  => ['siker', 'A szolgáltatás létrejött.'],
            'szolg_osszevonva'  => ['siker', 'Ilyen nevű szolgáltatás már volt – a kettőt összevontam, egy maradt.'],
            'szolg_torolve'     => ['siker', 'A szolgáltatás törölve a törzsből. A munkalapok tételei megmaradtak.'],
            'szolg_hianyzo_nev' => ['hiba',  'A megnevezés kitöltése kötelező.'],
            'arlista_betoltve'  => ['siker', 'Az árlista betöltve: az új tételek létrejöttek, a meglévők ára frissült.'],
            'arak_mentve'       => ['siker', 'Az árak elmentve.'],
            'termek_mentve'     => ['siker', 'A termék elmentve.'],
            'termek_letrehozva' => ['siker', 'A termék létrejött.'],
            'termek_torolve'    => ['siker', 'A termék törölve.'],
            'termek_inaktiv'    => ['siker', 'A termék már szerepelt munkalapon, ezért nem törlődött: inaktív lett.'],
            'termek_demo'       => ['siker', 'A 20 mintatermék betöltve.'],
            'termek_hiba'       => ['hiba',  'A termék nem menthető: a megnevezés kötelező.'],
            'csatolmany_hiba'   => ['hiba',  'A csatolt fájl nem fogadható el (túl nagy, nem engedélyezett típus vagy sérült fájl). Semmi nem mentődött.'],
        ];

        if (!isset($uzenetek[$kulcs])) {
            return;
        }

        // A munkalap hibaüzenete szabad szöveg (pl. melyik mező hiányzik),
        // ezért külön paraméterben jön – kiírás előtt tisztítjuk.
        if ($kulcs === 'munkalap_hiba') {
            $hiba = isset($_GET['hiba']) ? sanitize_text_field(wp_unslash($_GET['hiba'])) : '';

            printf(
                '<div class="sdh-uzenet sdh-uzenet--hiba">%s</div>',
                esc_html($hiba !== '' ? $hiba : 'A munkalap nem menthető.')
            );

            return;
        }

        [$tipus, $szoveg] = $uzenetek[$kulcs];

        if ($kulcs === 'demo_betoltve') {
            $ugyfel   = isset($_GET['ugyfel']) ? (int) $_GET['ugyfel'] : 0;
            $eszkoz   = isset($_GET['eszkoz']) ? (int) $_GET['eszkoz'] : 0;
            $munkalap = isset($_GET['munkalap']) ? (int) $_GET['munkalap'] : 0;
            $szoveg   = ($ugyfel + $eszkoz + $munkalap) > 0
                ? sprintf('Demó adatok betöltve: %d új ügyfél, %d új eszköz, %d új munkalap.', $ugyfel, $eszkoz, $munkalap)
                : 'A demó adatok már mind megvannak, semmi nem jött létre újra.';
        }

        // Új munkalapnál a kiosztott számot is kiírjuk – a pultnál erre van
        // szükség, hogy ráírják a készülék tasakjára.
        if ($kulcs === 'munkalap_letrehozva' && isset($_GET['szam'])) {
            $szam = sanitize_text_field(wp_unslash($_GET['szam']));

            if ($szam !== '') {
                $szoveg = 'A munkalap létrejött. Száma: ' . $szam;
            }
        }

        printf(
            '<div class="sdh-uzenet sdh-uzenet--%s">%s</div>',
            esc_attr($tipus),
            esc_html($szoveg)
        );
    }

    /* =================================================================
     * Áttekintés
     * ============================================================== */

    /** A wp-admines változat: kontextus beállítása, majd a közös tartalom. */
    public static function admin_attekintes(): void
    {
        self::jog_ellenoriz();
        SDH_Muhely_Modulok::kontextus_beallit('admin');

        echo '<div class="wrap">';
        self::attekintes_tartalom(true);
        echo '</div>';
    }

    /**
     * Az áttekintő képernyő tartalma. Mindkét felület ezt használja.
     *
     * @param bool $technikai Mutassa-e a verzió-táblázatot (adminban igen).
     */
    public static function attekintes_tartalom(bool $technikai = false): void
    {
        global $wpdb;

        $ugyfel_tabla = SDH_Muhely_Schema::tabla('ugyfel');
        $eszkoz_tabla = SDH_Muhely_Schema::tabla('eszkoz');

        $munkalap_db = SDH_Muhely_Munkalap::nyitott_db();

        $ugyfel_db = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE aktiv = 1");
        $eszkoz_db = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$eszkoz_tabla} WHERE aktiv = 1");

        $gombok = [
            [
                'cimke'      => '+ Új munkalap',
                'url'        => SDH_Muhely_Modulok::url('munkalapok', ['nezet' => 'uj']),
                'elsodleges' => true,
                'adatok'     => ['sdh-urlap' => 'munkalapok', 'sdh-id' => '0'],
            ],
            [
                'cimke'  => '+ Új ügyfél',
                'url'    => SDH_Muhely_Modulok::url('ugyfelek', ['nezet' => 'uj']),
                'adatok' => ['sdh-urlap' => 'ugyfelek', 'sdh-id' => '0'],
            ],
        ];

        // A modulcsempék elrejtése / megjelenítése (app.js; a felirat is ott vált).
        $gombok[] = [
            'cimke'  => 'Csempék elrejtése',
            'url'    => '#',
            'adatok' => ['sdh-csempek-valt' => ''],
        ];

        // A csempék szerkesztése (mi látszik, sorrend, felirat) – a Beállításokban.
        if (current_user_can('manage_options')) {
            $gombok[] = [
                'cimke' => 'Csempék szerkesztése',
                'url'   => SDH_Muhely_Modulok::admin_url('beallitasok') . '#csempek',
            ];
        }

        if ($technikai) {
            $gombok[] = [
                'cimke' => 'Megnyitás a műhely-felületen ↗',
                'url'   => SDH_Muhely_Modulok::frontend_url(),
            ];
        }

        ?>
        <div class="sdh-wrap sdh-wrap--teljes">
            <?php
            self::uzenet();
            self::fejlec(
                'Áttekintés',
                'A belső műhely- és ügyfélkezelő rendszer. Innen érhető el minden modul.',
                $gombok
            );
            ?>

            <?php // A modulcsempék: minden menüpont egy kattintásra, a legfontosabb számmal. A fejléc gombjával elrejthetők. ?>
            <div class="sdh-kartyak sdh-kartyak--modulok" data-sdh-csempek>
                <?php foreach (self::attekintes_kartyak($ugyfel_db, $eszkoz_db, $munkalap_db) as $kulcs => $k) : ?>
                    <a class="sdh-kartya<?php echo !empty($k['jelez']) ? ' sdh-kartya--jelez' : ''; ?>" href="<?php echo esc_url($k['url']); ?>"
                       data-sdh-kartya="<?php echo esc_attr((string) $kulcs); ?>" title="<?php echo esc_attr($k['modul']); ?>">
                        <span class="sdh-kartya__fej">
                            <?php echo SDH_Muhely_Frontend::ikon((string) $kulcs); // phpcs:ignore WordPress.Security.EscapeOutput -- saját SVG. ?>
                            <span class="sdh-kartya__modul"><?php echo esc_html($k['modul']); ?></span>
                        </span>
                        <?php if ($k['szam'] !== null) : ?>
                            <span class="sdh-kartya__szam"><?php echo esc_html(number_format_i18n((int) $k['szam'])); ?></span>
                            <span class="sdh-kartya__cimke">
                                <?php echo esc_html($k['cimke']); ?>
                                <?php if (isset($k['azonnal'])) : ?>
                                    <strong class="sdh-kartya__azonnal" data-sdh-kartya-azonnal<?php echo (int) $k['azonnal'] > 0 ? '' : ' hidden'; ?>><?php echo (int) $k['azonnal']; ?> azonnali</strong>
                                <?php endif; ?>
                            </span>
                        <?php else : ?>
                            <span class="sdh-kartya__cimke sdh-kartya__cimke--csak"><?php echo esc_html($k['cimke']); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <script>
                /* A csempék elrejtése böngészőnként megmarad; még a kirajzolás előtt áll be, hogy ne villanjon. */
                (function () {
                    try {
                        if (localStorage.getItem('sdh-csempek') === 'rejtve') {
                            document.currentScript.previousElementSibling.hidden = true;
                        }
                    } catch (e) {}
                }());
            </script>

            <?php
            // Az összes munkalap rácsa és a kijelölt lap részletei (MunkaLap 3 főablak).
            if (class_exists('SDH_Muhely_Racs')) {
                SDH_Muhely_Racs::megjelenit();
            }
            ?>

            <?php if ($technikai) : ?>
                <table class="sdh-tabla sdh-tabla--keskeny">
                    <tbody>
                        <tr><th>Plugin verzió</th><td><code><?php echo esc_html(SDH_MUHELY_VERSION); ?></code></td></tr>
                        <tr><th>Séma verzió</th><td><code><?php echo esc_html(SDH_Muhely_Schema::DB_VERSION); ?></code></td></tr>
                        <tr><th>Műhely-felület</th><td><code><?php
                            echo esc_html(SDH_Muhely_Modulok::frontend_url());
                        ?></code></td></tr>
                        <tr><th>WordPress</th><td><code><?php echo esc_html(get_bloginfo('version')); ?></code></td></tr>
                        <tr><th>PHP</th><td><code><?php echo esc_html(PHP_VERSION); ?></code></td></tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Az Áttekintés modulcsempéi: minden bejegyzett modul, a legfontosabb számával
     * (ha van ilyen). A sorrend az oldalmenüé.
     *
     * @return array<string, array{modul: string, url: string, szam: ?int, cimke: string, azonnal?: int, jelez?: bool}>
     */
    private static function attekintes_kartyak(int $ugyfel_db, int $eszkoz_db, int $munkalap_db): array
    {
        $szamok = [
            'ugyfelek'   => [$ugyfel_db, 'aktív ügyfél'],
            'eszkozok'   => [$eszkoz_db, 'nyilvántartott eszköz'],
            'munkalapok' => [$munkalap_db, 'nyitott munkalap'],
        ];

        if (class_exists('SDH_Muhely_Rma')) {
            $szamok['uzenetek'] = [SDH_Muhely_Rma::olvasatlan_db(), 'olvasatlan üzenet'];
        }

        if (class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Levelezes::van_fiok()) {
            $szamok['levelezes'] = [SDH_Muhely_Levelezes::olvasatlan_db(), 'olvasatlan levél'];
        }

        if (class_exists('SDH_Muhely_Penztar')) {
            $szamok['penztar'] = [SDH_Muhely_Penztar::kassza_most(), 'Ft a kasszában (várható)'];
        }

        if (class_exists('SDH_Muhely_Szolgaltatas')) {
            $szamok['szolgaltatasok'] = [SDH_Muhely_Szolgaltatas::darab(), 'szolgáltatás'];
        }

        if (class_exists('SDH_Muhely_Termek')) {
            $szamok['termekek'] = [SDH_Muhely_Termek::darab(), 'termék'];
        }

        if (class_exists('SDH_Muhely_Csapat')) {
            $szamok['csapat'] = [SDH_Muhely_Csapat::olvasatlan_db(), 'olvasatlan csapatüzenet'];
        }

        if (class_exists('SDH_Muhely_Asszisztens')) {
            $szamok['asszisztens'] = [SDH_Muhely_Asszisztens::fuggo_db(), 'jóváhagyásra váró javaslat'];
        }

        $ki = [];

        foreach (SDH_Muhely_Modulok::osszes() as $kulcs => $modul) {
            // A csak adminisztrátornak szóló modul (beállítások, karbantartás) a kollégánál nem látszik.
            if (!empty($modul['keszul']) || (!empty($modul['csak_admin']) && !self::admin_e())) {
                continue;
            }

            $ki[$kulcs] = [
                'modul' => (string) $modul['cim'],
                'url'   => SDH_Muhely_Modulok::url((string) $kulcs),
                'szam'  => isset($szamok[$kulcs]) ? (int) $szamok[$kulcs][0] : null,
                'cimke' => isset($szamok[$kulcs]) ? $szamok[$kulcs][1] : 'megnyitás',
            ];
        }

        if (isset($ki['levelezes']) && $ki['levelezes']['szam'] !== null) {
            $azonnal = SDH_Muhely_Levelezes::azonnal_db();

            $ki['levelezes']['azonnal'] = $azonnal;
            $ki['levelezes']['jelez']   = $azonnal > 0;
        }

        foreach (['uzenetek', 'csapat', 'asszisztens'] as $jelzo) {
            if (isset($ki[$jelzo]) && (int) $ki[$jelzo]['szam'] > 0) {
                $ki[$jelzo]['jelez'] = true;
            }
        }

        return self::csempek_alkalmaz($ki);
    }

    /* =================================================================
     * Az Áttekintés csempéinek beállítása (Beállítások → Áttekintés csempéi)
     * ============================================================== */

    private const OPT_CSEMPEK = 'sdh_muhely_csempek';

    /**
     * A mentett csempe-beállítás modulonként: látszik, sorrend, saját cím és
     * alsó felirat, látszik-e a szám. Ami nincs beállítva, az alapértéket kapja.
     *
     * @return array<string, array{latszik: bool, sorrend: int, cim: string, cimke: string, szam: bool}>
     */
    public static function csempe_beallitas(): array
    {
        $mentett = get_option(self::OPT_CSEMPEK, []);
        $mentett = is_array($mentett) ? $mentett : [];
        $ki      = [];
        $i       = 0;

        foreach (SDH_Muhely_Modulok::osszes() as $kulcs => $modul) {
            if (!empty($modul['keszul'])) {
                continue;
            }

            $i++;
            $m = is_array($mentett[$kulcs] ?? null) ? $mentett[$kulcs] : [];

            $ki[(string) $kulcs] = [
                'latszik' => !isset($m['latszik']) || !empty($m['latszik']),
                'sorrend' => isset($m['sorrend']) ? (int) $m['sorrend'] : $i * 10,
                'cim'     => isset($m['cim']) ? (string) $m['cim'] : '',
                'cimke'   => isset($m['cimke']) ? (string) $m['cimke'] : '',
                'szam'    => !isset($m['szam']) || !empty($m['szam']),
            ];
        }

        return $ki;
    }

    /**
     * @param array<string, array<string, mixed>> $kartyak
     * @return array<string, array<string, mixed>>
     */
    private static function csempek_alkalmaz(array $kartyak): array
    {
        $b = self::csempe_beallitas();

        foreach ($kartyak as $kulcs => $k) {
            $c = $b[$kulcs] ?? null;

            if ($c === null) {
                continue;
            }

            if (!$c['latszik']) {
                unset($kartyak[$kulcs]);
                continue;
            }

            if ($c['cim'] !== '') {
                $kartyak[$kulcs]['modul'] = $c['cim'];
            }

            if ($c['cimke'] !== '') {
                $kartyak[$kulcs]['cimke'] = $c['cimke'];
            }

            if (!$c['szam']) {
                $kartyak[$kulcs]['szam'] = null;
                unset($kartyak[$kulcs]['azonnal']);
            }
        }

        uksort($kartyak, static fn ($a, $c) => ($b[$a]['sorrend'] ?? 999) <=> ($b[$c]['sorrend'] ?? 999));

        return $kartyak;
    }

    public static function csempek_doboz(): void
    {
        $b = self::csempe_beallitas();
        $modulok = SDH_Muhely_Modulok::osszes();

        uksort($b, static fn ($x, $y) => $b[$x]['sorrend'] <=> $b[$y]['sorrend']);

        ?>
        <div class="sdh-doboz" id="csempek">
            <h2 class="sdh-doboz__cim">Áttekintés csempéi</h2>
            <p class="sdh-sugo">
                Melyik modul csempéje látszik az Áttekintés tetején, milyen sorrendben és milyen felirattal.
                A sorrendet a nyilakkal állítod: a sor feljebb vagy lejjebb kerül.
                Üresen hagyott cím vagy felirat = az alapértelmezett.
            </p>

            <table class="sdh-tabla sdh-csempe-tabla" data-sdh-csempe-tabla>
                <thead>
                    <tr>
                        <th>Látszik</th>
                        <th>Sorrend</th>
                        <th>Modul</th>
                        <th>Cím a csempén</th>
                        <th>Alsó felirat</th>
                        <th>Szám</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($b as $kulcs => $c) : ?>
                        <?php $nev = 'csempek[' . $kulcs . ']'; ?>
                        <tr data-sdh-csempe-sor>
                            <td>
                                <input type="hidden" name="<?php echo esc_attr($nev); ?>[latszik]" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($nev); ?>[latszik]" value="1" <?php checked($c['latszik']); ?>
                                       aria-label="<?php echo esc_attr((string) ($modulok[$kulcs]['cim'] ?? $kulcs)); ?> látszik">
                            </td>
                            <td class="sdh-csempe-tabla__sorrend">
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-csempe-lep="-1" aria-label="Feljebb"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4.5 12.5 10 7l5.5 5.5"/></svg></button>
                                <input type="hidden" name="<?php echo esc_attr($nev); ?>[sorrend]" value="<?php echo (int) $c['sorrend']; ?>" data-sdh-csempe-sorrend>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-csempe-lep="1" aria-label="Lejjebb"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4.5 7.5 10 13l5.5-5.5"/></svg></button>
                            </td>
                            <td><strong><?php echo esc_html((string) ($modulok[$kulcs]['cim'] ?? $kulcs)); ?></strong></td>
                            <td><input type="text" name="<?php echo esc_attr($nev); ?>[cim]" value="<?php echo esc_attr($c['cim']); ?>" maxlength="40"
                                       placeholder="<?php echo esc_attr((string) ($modulok[$kulcs]['cim'] ?? '')); ?>"></td>
                            <td><input type="text" name="<?php echo esc_attr($nev); ?>[cimke]" value="<?php echo esc_attr($c['cimke']); ?>" maxlength="60"
                                       placeholder="alapértelmezett"></td>
                            <td>
                                <input type="hidden" name="<?php echo esc_attr($nev); ?>[szam]" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($nev); ?>[szam]" value="1" <?php checked($c['szam']); ?> aria-label="Szám látszik">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="csempek_alap" id="csempek_alap" value="1">
                <label for="csempek_alap">Mindent vissza az alapértelmezésre</label>
            </div>

            <script>
                /* A nyilak a sort mozgatják, és újraszámozzák a sorrendet (10, 20, 30…). */
                (function () {
                    var tabla = document.querySelector('[data-sdh-csempe-tabla]');

                    if (!tabla) { return; }

                    tabla.addEventListener('click', function (e) {
                        var g = e.target.closest('[data-sdh-csempe-lep]');

                        if (!g) { return; }

                        var sor = g.closest('tr');
                        var irany = parseInt(g.getAttribute('data-sdh-csempe-lep'), 10);
                        var szomszed = irany < 0 ? sor.previousElementSibling : sor.nextElementSibling;

                        if (szomszed) {
                            sor.parentNode.insertBefore(sor, irany < 0 ? szomszed : szomszed.nextElementSibling);
                        }

                        Array.prototype.forEach.call(tabla.querySelectorAll('[data-sdh-csempe-sorrend]'), function (m, i) {
                            m.value = (i + 1) * 10;
                        });
                    });
                }());
            </script>
        </div>
        <?php
    }

    public static function csempek_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (!empty($_POST['csempek_alap'])) {
            delete_option(self::OPT_CSEMPEK);

            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $be = isset($_POST['csempek']) && is_array($_POST['csempek']) ? wp_unslash($_POST['csempek']) : null;

        if ($be === null) {
            return;
        }

        $ki = [];

        foreach (self::csempe_beallitas() as $kulcs => $alap) {
            $m = is_array($be[$kulcs] ?? null) ? $be[$kulcs] : [];

            $ki[$kulcs] = [
                'latszik' => !empty($m['latszik']),
                'sorrend' => isset($m['sorrend']) ? (int) $m['sorrend'] : $alap['sorrend'],
                'cim'     => mb_substr(sanitize_text_field((string) ($m['cim'] ?? '')), 0, 40),
                'cimke'   => mb_substr(sanitize_text_field((string) ($m['cimke'] ?? '')), 0, 60),
                'szam'    => !empty($m['szam']),
            ];
        }

        update_option(self::OPT_CSEMPEK, $ki, false);
    }

    /**
     * A CSS/JS fájl verziója a böngésző-gyorsítótárhoz: plugin-verzió + a fájl
     * módosítási ideje. Így minden fájlcsere után az első betöltés már az újat
     * kéri, akkor is, ha a PHP-gyorsítótár (OPcache) még a régi verziószámot adná.
     */
    public static function eszkoz_verzio(string $relativ): string
    {
        $ido = @filemtime(SDH_MUHELY_DIR . $relativ);

        return SDH_MUHELY_VERSION . ($ido ? '.' . $ido : '');
    }
}
