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
            SDH_MUHELY_VERSION
        );

        wp_enqueue_script(
            'sdh-muhely-app',
            SDH_MUHELY_URL . 'assets/app.js',
            [],
            SDH_MUHELY_VERSION,
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
        return [
            'ajax'      => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('sdh_muhely_modal'),
            'kontextus' => $kontextus,
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
            'mentve'      => ['siker', 'Elmentve.'],
            'letrehozva'  => ['siker', 'Az ügyfél létrejött.'],
            'inaktivalva' => ['siker', 'Az ügyfél inaktívra állítva.'],
            'aktivalva'   => ['siker', 'Az ügyfél újra aktív.'],
            'hianyzo_nev' => ['hiba',  'A név kitöltése kötelező – e nélkül nem menthető az ügyfél.'],
            'nincs_ilyen' => ['hiba',  'Nincs ilyen ügyfél. Lehet, hogy időközben törölték.'],
            'mentes_hiba' => ['hiba',  'A mentés nem sikerült. Az adatbázis visszautasította a műveletet.'],
        ];

        if (!isset($uzenetek[$kulcs])) {
            return;
        }

        [$tipus, $szoveg] = $uzenetek[$kulcs];

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
        $ugyfel_db    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE aktiv = 1");

        $gombok = [
            [
                'cimke'      => '+ Új ügyfél',
                'url'        => SDH_Muhely_Modulok::url('ugyfelek', ['nezet' => 'uj']),
                'elsodleges' => true,
                'adatok'     => ['sdh-urlap' => 'ugyfelek', 'sdh-id' => '0'],
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

                <div class="sdh-kartya sdh-kartya--keszul">
                    <span class="sdh-kartya__szam">—</span>
                    <span class="sdh-kartya__cimke">eszköz · készül</span>
                </div>

                <div class="sdh-kartya sdh-kartya--keszul">
                    <span class="sdh-kartya__szam">—</span>
                    <span class="sdh-kartya__cimke">nyitott munkalap · készül</span>
                </div>
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
}
