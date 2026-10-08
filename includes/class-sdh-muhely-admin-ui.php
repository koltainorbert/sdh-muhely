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

    public static function init(): void
    {
        // Későn fut, hogy addigra minden modul bejelentkezzen.
        add_action('admin_menu', [self::class, 'menu'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'eszkozok']);
        add_action('wp_ajax_sdh_muhely_globalis_kereso', [self::class, 'ajax_kereso']);
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
     * Ki használhatja a rendszert.
     *
     * Egyelőre a WordPress adminja. Később saját szerepkör jön ide
     * (szerelő, pultos, vezető) – ezért van egy helyen és szűrhető.
     */
    public static function jog(): string
    {
        return (string) apply_filters('sdh_muhely_jogosultsag', 'manage_options');
    }

    public static function jog_ellenoriz(): void
    {
        if (!current_user_can(self::jog())) {
            wp_die(esc_html__('Nincs jogosultságod ehhez az oldalhoz.', 'sdh-muhely'));
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
                $jog,
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
                            <?php echo esc_html($gomb['cimke']); ?>
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

        if ($technikai) {
            $gombok[] = [
                'cimke' => 'Megnyitás a műhely-felületen ↗',
                'url'   => SDH_Muhely_Modulok::frontend_url(),
            ];
        }

        ?>
        <div class="sdh-wrap">
            <?php
            self::uzenet();
            self::fejlec(
                'Áttekintés',
                'A belső műhely- és ügyfélkezelő rendszer. Innen érhető el minden modul.',
                $gombok
            );
            ?>

            <div class="sdh-kartyak">
                <a class="sdh-kartya" href="<?php echo esc_url(SDH_Muhely_Modulok::url('ugyfelek')); ?>">
                    <span class="sdh-kartya__szam"><?php echo esc_html(number_format_i18n($ugyfel_db)); ?></span>
                    <span class="sdh-kartya__cimke">aktív ügyfél</span>
                </a>

                <a class="sdh-kartya" href="<?php echo esc_url(SDH_Muhely_Modulok::url('eszkozok')); ?>">
                    <span class="sdh-kartya__szam"><?php echo esc_html(number_format_i18n($eszkoz_db)); ?></span>
                    <span class="sdh-kartya__cimke">nyilvántartott eszköz</span>
                </a>

                <a class="sdh-kartya" href="<?php echo esc_url(SDH_Muhely_Modulok::url('munkalapok')); ?>">
                    <span class="sdh-kartya__szam"><?php echo esc_html(number_format_i18n($munkalap_db)); ?></span>
                    <span class="sdh-kartya__cimke">nyitott munkalap</span>
                </a>
            </div>

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
