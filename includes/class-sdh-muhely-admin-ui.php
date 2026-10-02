<?php
/**
 * Közös admin arculat.
 *
 * Itt születik a menüszerkezet és az a néhány segédfüggvény, amire minden
 * modulnak szüksége van: jogosultság-ellenőrzés, oldalfejléc, értesítések,
 * a saját CSS betöltése. A modulok nem regisztrálnak menüt maguknak –
 * ide jelentkeznek be az `sdh_muhely_menupontok` szűrőn keresztül.
 *
 * Miért így: a cél az, hogy a napi munka ne a wp-admin alapértelmezett
 * képernyőin menjen. Ezért minden oldal ugyanazt a vázat kapja, és a
 * WordPress sallangjából annyi látszik, amennyi muszáj.
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
        add_action('admin_menu', [self::class, 'menu'], 9);
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

    /**
     * Megállítja a kérést, ha a belépett felhasználónak nincs jogosultsága.
     */
    public static function jog_ellenoriz(): void
    {
        if (!current_user_can(self::jog())) {
            wp_die(esc_html__('Nincs jogosultságod ehhez az oldalhoz.', 'sdh-muhely'));
        }
    }

    /**
     * Egy admin-oldal URL-je a pluginon belül.
     *
     * @param string               $slug   Az oldal slugja (pl. 'sdh-muhely-ugyfelek').
     * @param array<string, mixed> $parameterek További lekérdezési paraméterek.
     */
    public static function url(string $slug, array $parameterek = []): string
    {
        $parameterek = array_merge(['page' => $slug], $parameterek);

        return add_query_arg($parameterek, admin_url('admin.php'));
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
            [self::class, 'attekintes'],
            'dashicons-hammer',
            3
        );

        // Az első almenü ugyanaz az oldal, csak beszédesebb névvel.
        add_submenu_page(
            self::FOMENU,
            'Áttekintés',
            'Áttekintés',
            $jog,
            self::FOMENU,
            [self::class, 'attekintes']
        );

        /**
         * A modulok itt jelentkeznek be egy-egy menüponttal.
         *
         * Egy elem: [
         *   'slug'    => 'sdh-muhely-ugyfelek',
         *   'cim'     => 'Ügyfelek',
         *   'callback'=> callable,
         *   'sorrend' => 10,
         * ]
         *
         * @var array<int, array<string, mixed>> $menupontok
         */
        $menupontok = apply_filters('sdh_muhely_menupontok', []);

        usort(
            $menupontok,
            static fn (array $a, array $b): int => ($a['sorrend'] ?? 50) <=> ($b['sorrend'] ?? 50)
        );

        foreach ($menupontok as $pont) {
            if (empty($pont['slug']) || empty($pont['callback'])) {
                continue;
            }

            add_submenu_page(
                self::FOMENU,
                (string) ($pont['cim'] ?? $pont['slug']),
                (string) ($pont['cim'] ?? $pont['slug']),
                $jog,
                (string) $pont['slug'],
                $pont['callback']
            );
        }
    }

    /* =================================================================
     * Eszközök (CSS)
     * ============================================================== */

    /**
     * A saját CSS csak a plugin oldalain töltődik be – a többi
     * admin-képernyőt nem piszkáljuk.
     */
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
    }

    /* =================================================================
     * Oldalváz
     * ============================================================== */

    /**
     * Az oldal fejléce: cím, alcím, jobbra igazított gombok.
     *
     * @param array<int, array{cimke: string, url: string, elsodleges?: bool}> $gombok
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
                           class="sdh-gomb <?php echo !empty($gomb['elsodleges']) ? 'sdh-gomb--elsodleges' : ''; ?>">
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
            'mentve'       => ['siker', 'Elmentve.'],
            'letrehozva'   => ['siker', 'Az ügyfél létrejött.'],
            'inaktivalva'  => ['siker', 'Az ügyfél inaktívra állítva.'],
            'aktivalva'    => ['siker', 'Az ügyfél újra aktív.'],
            'hianyzo_nev'  => ['hiba',  'A név kitöltése kötelező – e nélkül nem menthető az ügyfél.'],
            'nincs_ilyen'  => ['hiba',  'Nincs ilyen ügyfél. Lehet, hogy időközben törölték.'],
            'mentes_hiba'  => ['hiba',  'A mentés nem sikerült. Az adatbázis visszautasította a műveletet.'],
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
     * Áttekintő képernyő
     * ============================================================== */

    public static function attekintes(): void
    {
        self::jog_ellenoriz();

        global $wpdb;

        $ugyfel_tabla = SDH_Muhely_Schema::tabla('ugyfel');
        $ugyfel_db    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ugyfel_tabla} WHERE aktiv = 1");

        ?>
        <div class="wrap sdh-wrap">
            <?php
            self::uzenet();
            self::fejlec(
                'Áttekintés',
                'A belső műhely- és ügyfélkezelő rendszer. Innen érhető el minden modul.'
            );
            ?>

            <div class="sdh-kartyak">
                <a class="sdh-kartya" href="<?php echo esc_url(self::url('sdh-muhely-ugyfelek')); ?>">
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

            <table class="sdh-tabla sdh-tabla--keskeny">
                <tbody>
                    <tr><th>Plugin verzió</th><td><code><?php echo esc_html(SDH_MUHELY_VERSION); ?></code></td></tr>
                    <tr><th>Séma verzió</th><td><code><?php echo esc_html(SDH_Muhely_Schema::DB_VERSION); ?></code></td></tr>
                    <tr><th>WordPress</th><td><code><?php echo esc_html(get_bloginfo('version')); ?></code></td></tr>
                    <tr><th>PHP</th><td><code><?php echo esc_html(PHP_VERSION); ?></code></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
