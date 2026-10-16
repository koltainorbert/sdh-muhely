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
        add_action('wp_ajax_sdh_muhely_csempe_szamok', [self::class, 'ajax_csempe_szamok']);

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

        // 0.64: az ügyfeleket az ügyfélválasztó gyors (indexelt, lépcsőzetes) keresése adja.
        $ugyfelek = SDH_Muhely_Ugyfel::kereses($q, 5) ?? $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, nev, telefon, szamlazasi_iranyitoszam, szamlazasi_telepules, szamlazasi_cim, ugyfel_szam
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

        // A COALESCE szándékos (0.64): ritka keresett szónál a módosítás indexén végigmenni lassabb, mint
        // egyszer végigolvasni a táblát (a sorrend ugyanaz: a dátum nélküli eszköz a végére kerül).
        $eszkozok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.id, e.gyarto, e.tipus, e.megnevezes, e.imei, e.sorozatszam,
                        u.nev AS ugyfel_nev
                 FROM {$eszkoz_tabla} e
                 LEFT JOIN {$ugyfel_tabla} u ON u.id = e.ugyfel_id
                 WHERE e.aktiv = 1
                   AND (e.imei LIKE %s OR e.imei2 LIKE %s OR e.sorozatszam LIKE %s
                        OR e.tipus LIKE %s OR e.megnevezes LIKE %s OR e.modell_szam LIKE %s)
                 ORDER BY COALESCE(e.modositva, '1000-01-01') DESC LIMIT 5",
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
                        array_filter([$sor->telefon, SDH_Muhely_Ugyfel::cim_szoveg($sor), $sor->ugyfel_szam])
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

        // A rendszer neve az Arculatból jön (cégenként más).
        $rendszer = class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::rendszer_nev() : 'Műhely';

        add_menu_page(
            $rendszer,
            $rendszer,
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
                // A rejtett modul oldala létezik (a Beállításokból nyílik), de nincs menüpontja.
                !empty($modul['rejtett']) ? '' : self::FOMENU,
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

        // Az Arculat beállításából adódó stílus (egyéni kiemelő szín, lekerekítés).
        if (class_exists('SDH_Muhely_Arculat') && SDH_Muhely_Arculat::stilus_css() !== '') {
            wp_add_inline_style('sdh-muhely-admin', SDH_Muhely_Arculat::stilus_css());
        }

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

        // (Az eladas.js-t a SDH_Muhely_Eladas tölti be, ugyanezekre az oldalakra.)
        // Csapat (belső üzenetek, pulzus) és az AI-asszisztens: minden saját oldalon.
        foreach (['csapat', 'asszisztens'] as $sdh_eszkoz) {
            wp_enqueue_style('sdh-muhely-' . $sdh_eszkoz, SDH_MUHELY_URL . 'assets/' . $sdh_eszkoz . '.css', ['sdh-muhely-admin'], self::eszkoz_verzio('assets/' . $sdh_eszkoz . '.css'));
            wp_enqueue_script('sdh-muhely-' . $sdh_eszkoz, SDH_MUHELY_URL . 'assets/' . $sdh_eszkoz . '.js', ['sdh-muhely-app'], self::eszkoz_verzio('assets/' . $sdh_eszkoz . '.js'), true);
        }

        // SMS (0.57): a „Küldjek SMS-t?” popup bármelyik oldalon megjelenhet státuszváltás után.
        wp_enqueue_style('sdh-muhely-sms', SDH_MUHELY_URL . 'assets/sms.css', ['sdh-muhely-admin'], self::eszkoz_verzio('assets/sms.css'));
        wp_enqueue_script('sdh-muhely-sms', SDH_MUHELY_URL . 'assets/sms.js', ['sdh-muhely-app'], self::eszkoz_verzio('assets/sms.js'), true);

        // Űrlap-beállítások (0.62.0): a fogaskerék lenyílója és az élő kapcsolás minden űrlapon.
        wp_enqueue_style('sdh-muhely-urlap-beallitas', SDH_MUHELY_URL . 'assets/urlap-beallitas.css', ['sdh-muhely-admin'], self::eszkoz_verzio('assets/urlap-beallitas.css'));
        wp_enqueue_script('sdh-muhely-urlap-beallitas', SDH_MUHELY_URL . 'assets/urlap-beallitas.js', ['sdh-muhely-app'], self::eszkoz_verzio('assets/urlap-beallitas.js'), true);

        // Mezőmemória és Gyakori tételek (0.63.0): minden oldalon.
        wp_enqueue_style('sdh-muhely-emlek', SDH_MUHELY_URL . 'assets/emlek.css', ['sdh-muhely-admin'], self::eszkoz_verzio('assets/emlek.css'));
        wp_enqueue_script('sdh-muhely-emlek', SDH_MUHELY_URL . 'assets/emlek.js', ['sdh-muhely-app'], self::eszkoz_verzio('assets/emlek.js'), true);

        // Hardveres gyorsítás (0.65.0): észlelés, figyelmeztetés, „gyenge gép" mód – minden oldalon.
        wp_enqueue_style('sdh-muhely-gyorsitas', SDH_MUHELY_URL . 'assets/gyorsitas.css', ['sdh-muhely-admin'], self::eszkoz_verzio('assets/gyorsitas.css'));
        wp_enqueue_script('sdh-muhely-gyorsitas', SDH_MUHELY_URL . 'assets/gyorsitas.js', ['sdh-muhely-app'], self::eszkoz_verzio('assets/gyorsitas.js'), true);

        // Párhuzamos szerkesztés (0.70.0): ütközés-jelzés és „X is szerkeszti" – minden oldalon (a pulzus futásidőben kéri tőle az adatot, a sorrend mindegy).
        wp_enqueue_style('sdh-muhely-utkozes', SDH_MUHELY_URL . 'assets/utkozes.css', ['sdh-muhely-admin'], self::eszkoz_verzio('assets/utkozes.css'));
        wp_enqueue_script('sdh-muhely-utkozes', SDH_MUHELY_URL . 'assets/utkozes.js', ['sdh-muhely-app'], self::eszkoz_verzio('assets/utkozes.js'), true);
        wp_enqueue_style('sdh-muhely-kapcsolo', SDH_MUHELY_URL . 'assets/kapcsolo.css', ['sdh-muhely-utkozes'], self::eszkoz_verzio('assets/kapcsolo.css'));
        wp_enqueue_script('sdh-muhely-kapcsolo', SDH_MUHELY_URL . 'assets/kapcsolo.js', ['sdh-muhely-app'], self::eszkoz_verzio('assets/kapcsolo.js'), true);
        wp_enqueue_style('sdh-muhely-szemely', SDH_MUHELY_URL . 'assets/szemely.css', ['sdh-muhely-admin'], self::eszkoz_verzio('assets/szemely.css'));
        wp_enqueue_script('sdh-muhely-szemely', SDH_MUHELY_URL . 'assets/szemely.js', ['sdh-muhely-app'], self::eszkoz_verzio('assets/szemely.js'), true);

        // Külső mentés, visszaállítás (0.73.0): Beállítások › Mentés.
        wp_enqueue_style('sdh-muhely-mentes', SDH_MUHELY_URL . 'assets/mentes.css', ['sdh-muhely-admin'], self::eszkoz_verzio('assets/mentes.css'));
        wp_enqueue_script('sdh-muhely-mentes', SDH_MUHELY_URL . 'assets/mentes.js', ['sdh-muhely-app'], self::eszkoz_verzio('assets/mentes.js'), true);
        wp_enqueue_script('sdh-muhely-frissites', SDH_MUHELY_URL . 'assets/frissites.js', ['sdh-muhely-app'], self::eszkoz_verzio('assets/frissites.js'), true);

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
            // Mezőmemória (0.63.0): be van-e kapcsolva, és a titkos mezők szűrője (emlek.js).
            'emlek'     => class_exists('SDH_Muhely_Emlek') ? SDH_Muhely_Emlek::js() : ['be' => false],
            'alapTema'  => $arculat['tema'],
            'alapSzin'  => $arculat['szin'],
            // Üvegszín (duo / mono) és az alap betűnagyítás (%), a csúszka határaival.
            'alapUveg'  => (string) ($arculat['uveg'] ?? 'mono'),
            // Kékfény-szűrő: a cég alap erőssége (0 = ki); a dolgozó a profilmenüben átállíthatja.
            'alapKekfeny' => (int) ($arculat['kekfeny'] ?? 0),
            'alapNagyitas' => (int) ($arculat['nagyitas'] ?? 100),
            'nagyitasMin'  => class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::NAGYITAS_MIN : 80,
            'nagyitasMax'  => class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::NAGYITAS_MAX : 160,
            // A popupok neon kerete (Arculat): szín, az egyéni színkód és a fényerő.
            'alapNeon'  => (string) ($arculat['neon'] ?? 'kiemelo'),
            'neonEgyeni' => (string) ($arculat['neon_egyeni'] ?? ''),
            'neonEro'   => (string) ($arculat['neon_ero'] ?? 'kozepes'),
            // Az irányítószám-lista verziója: a böngésző egy napig tárolja a
            // listát, új adatfájlnál ettől kéri le azonnal az újat.
            'iszVerzio' => self::eszkoz_verzio('data/iranyitoszam.csv.gz'),
            // Levelezés: van-e bekötött fiók (csak akkor indul az új levelek figyelése), és hol a levelező.
            'level'     => class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Modulok::be('levelezes') && SDH_Muhely_Levelezes::van_fiok(),
            'levelUrl'  => SDH_Muhely_Modulok::url('levelezes'),
            'levelRendezo' => class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Modulok::be('levelezes') && SDH_Muhely_Levelezes::rendezo_be(),
            // Csapat (0.38): adminisztrátor-e (kitűzés másnak az üzenetén), AI-asszisztens adatai.
            'admin'     => self::admin_e(),
            // A munkalap csevegőjének frissítési ideje (Beállítások › Ügyféloldal).
            'chatMs'    => class_exists('SDH_Muhely_Rma') ? 1000 * (int) SDH_Muhely_Rma::beallitas()['chat_mp'] : 8000,
            'ai'        => class_exists('SDH_Muhely_Asszisztens') && SDH_Muhely_Modulok::be('asszisztens') ? SDH_Muhely_Asszisztens::js_adat() : ['be' => false],
            // Nyomtatás: vár-e felajánlás (új lap / lezárás után), amit az oldal betöltésekor meg kell mutatni.
            'nyomtat'   => class_exists('SDH_Muhely_Nyomtatas') && SDH_Muhely_Modulok::be('nyomtatas') ? SDH_Muhely_Nyomtatas::js_adat() : ['ajanlat' => false],
            // SMS (0.57): be van-e kapcsolva (csak akkor kérdez rá a státuszváltás után), van-e függő döntés.
            'sms'       => class_exists('SDH_Muhely_Sms') && is_user_logged_in()
                ? ['be' => SDH_Muhely_Sms::bekapcsolva(), 'fuggo' => SDH_Muhely_Sms::bekapcsolva() && class_exists('SDH_Muhely_Sms_Szabaly') ? SDH_Muhely_Sms_Szabaly::fuggo_db() : 0]
                : ['be' => false, 'fuggo' => 0],
            // Modulok (0.58): a kikapcsolt fő modulok kulcsai – a szkriptek ebből tudják, mit ne kínáljanak fel.
            'modulokKi' => array_values(array_filter(array_keys(SDH_Muhely_Modulok::katalogus()), [SDH_Muhely_Modulok::class, 'ki'])),
        ];
    }

    /* =================================================================
     * Másoló gomb
     * ============================================================== */

    /**
     * Kis „másolás" gomb egy adat mellé: kattintásra a szöveg a vágólapra
     * kerül (app.js: data-sdh-masol). Üres szövegre nem ad gombot.
     *
     * Minden adatlista ezt használja (munkalap-összefoglaló, részletpanel),
     * így az IMEI, a név, a cím, a telefonszám… egy kattintással kimásolható.
     */
    public static function masolo_gomb(string $szoveg, string $cimke = ''): string
    {
        $szoveg = trim($szoveg);

        if ($szoveg === '' || $szoveg === '—') {
            return '';
        }

        $felirat = $cimke !== '' ? $cimke . ' másolása' : 'Másolás';

        return sprintf(
            '<button type="button" class="sdh-masol" data-sdh-masol="%s" title="%s" aria-label="%s">'
            . '<svg class="sdh-masol__ikon" viewBox="0 0 16 16" aria-hidden="true"><rect x="5.5" y="5.5" width="8" height="8" rx="1.6"/><path d="M10.5 5.5V4a1.5 1.5 0 0 0-1.5-1.5H4A1.5 1.5 0 0 0 2.5 4v5A1.5 1.5 0 0 0 4 10.5h1.5"/></svg>'
            . '<svg class="sdh-masol__pipa" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7"/></svg>'
            . '</button>',
            esc_attr($szoveg),
            esc_attr($felirat),
            esc_attr($felirat)
        );
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
     * A gombok lekerekített „pillek", SVG ikonnal: az ikon neve az `ikon` kulcsban
     * áll (SDH_Muhely_Frontend::ikon_rajz készlete); ha nincs megadva, a „+ …"
     * kezdetű felirat pluszjelet kap, a többi ikon nélkül marad.
     *
     * @param array<int, array{cimke: string, url: string, elsodleges?: bool, ikon?: string, adatok?: array<string, string>}> $gombok
     * @param string $kozep A cím és a gombok közé kerülő, már szűrt HTML (pl. az Áttekintés órája).
     */
    public static function fejlec(string $cim, string $alcim = '', array $gombok = [], string $kozep = ''): void
    {
        ?>
        <div class="sdh-fejlec<?php echo $kozep !== '' ? ' sdh-fejlec--kozeppel' : ''; ?>">
            <div class="sdh-fejlec__szoveg">
                <p class="sdh-fejlec__kalap"><?php echo esc_html(class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::rendszer_nev() : 'Műhely'); ?></p>
                <h1 class="sdh-fejlec__cim"><?php echo esc_html($cim); ?></h1>
                <?php if ($alcim !== '') : ?>
                    <p class="sdh-fejlec__alcim"><?php echo esc_html($alcim); ?></p>
                <?php endif; ?>
            </div>

            <?php if ($kozep !== '') : ?>
                <div class="sdh-fejlec__kozep"><?php echo $kozep; // phpcs:ignore WordPress.Security.EscapeOutput -- a hívó szűrt HTML-t ad. ?></div>
            <?php endif; ?>

            <?php if ($gombok !== []) : ?>
                <div class="sdh-fejlec__gombok">
                    <?php foreach ($gombok as $gomb) : ?>
                        <?php
                        $cimke = (string) $gomb['cimke'];
                        $plusz = strpos($cimke, '+ ') === 0;
                        $ikon  = (string) ($gomb['ikon'] ?? ($plusz ? 'plusz' : ''));
                        ?>
                        <a href="<?php echo esc_url($gomb['url']); ?>"
                           class="sdh-gomb sdh-gomb--pill<?php echo !empty($gomb['elsodleges']) ? ' sdh-gomb--elsodleges' : ''; ?>"
                           <?php
                            foreach ($gomb['adatok'] ?? [] as $nev => $ertek) {
                                printf(' data-%s="%s"', esc_attr($nev), esc_attr($ertek));
                            }
                           ?>>
                            <?php echo self::gomb_ikon($ikon); // phpcs:ignore WordPress.Security.EscapeOutput -- saját SVG. ?>
                            <span class="sdh-gomb__felirat"><?php echo esc_html($plusz ? substr($cimke, 2) : $cimke); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Egy gomb SVG ikonja (vonalas, a menü ikonjaival egy készletből). Ismeretlen
     * vagy üres névre üres szöveg: a gomb ikon nélkül marad.
     */
    public static function gomb_ikon(string $nev): string
    {
        $rajz = $nev !== '' && class_exists('SDH_Muhely_Frontend') ? SDH_Muhely_Frontend::ikon_rajz($nev, null) : '';

        return $rajz === '' ? '' : '<svg class="sdh-gomb__ikon" viewBox="0 0 20 20" aria-hidden="true">' . $rajz . '</svg>';
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
            'arukiadas_mentve'     => ['siker', 'Az árukiadás elmentve.'],
            'arukiadas_letrehozva' => ['siker', 'Az árukiadás rögzítve.'],
            'rendeles_mentve'      => ['siker', 'A rendelés elmentve.'],
            'rendeles_letrehozva'  => ['siker', 'A rendelés rögzítve.'],
            'inaktivalva'       => ['siker', 'Az ügyfél inaktívra állítva.'],
            'aktivalva'         => ['siker', 'Az ügyfél újra aktív.'],
            'hianyzo_nev'       => ['hiba',  'A név kitöltése kötelező – e nélkül nem menthető az ügyfél.'],
            'hianyzo_ugyfel'    => ['hiba',  'Válassz ügyfelet a listából – eszköz ügyfél nélkül nem vihető fel.'],
            'nincs_ilyen'       => ['hiba',  'Nincs ilyen rekord. Lehet, hogy időközben törölték.'],
            'sms_sablon_mentve' => ['siker', 'Az SMS-sablon elmentve.'],
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
        if (in_array($kulcs, ['munkalap_letrehozva', 'arukiadas_letrehozva', 'rendeles_letrehozva'], true) && isset($_GET['szam'])) {
            $szam = sanitize_text_field(wp_unslash($_GET['szam']));

            if ($szam !== '') {
                $szoveg = ($kulcs === 'munkalap_letrehozva' ? 'A munkalap létrejött.' : $szoveg) . ' Száma: ' . $szam;
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

        $munkalap_db = SDH_Muhely_Munkalap::nyitott_db();

        $ugyfel_db = self::aktiv_db('ugyfel');
        $eszkoz_db = self::aktiv_db('eszkoz');

        $gombok = [
            [
                'cimke'      => '+ Új munkalap',
                'ikon'       => 'uj-munkalap',
                'url'        => SDH_Muhely_Modulok::url('munkalapok', ['nezet' => 'uj']),
                'elsodleges' => true,
                'adatok'     => ['sdh-urlap' => 'munkalapok', 'sdh-id' => '0'],
            ],
            [
                'cimke'  => '+ Új ügyfél',
                'ikon'   => 'uj-ugyfel',
                'url'    => SDH_Muhely_Modulok::url('ugyfelek', ['nezet' => 'uj']),
                'adatok' => ['sdh-urlap' => 'ugyfelek', 'sdh-id' => '0'],
            ],
        ];

        // Új termék és új szolgáltatás a törzsbe – popupban, az Áttekintés elhagyása nélkül (eladas.js).
        if (class_exists('SDH_Muhely_Termek') && SDH_Muhely_Modulok::be('termekek')) {
            $gombok[] = [
                'cimke'  => '+ Új termék',
                'ikon'   => 'termekek',
                'url'    => SDH_Muhely_Modulok::url('termekek', ['nezet' => 'uj']),
                'adatok' => ['sdh-urlap' => 'termekek', 'sdh-id' => '0', 'sdh-marad' => 'A termék létrejött.'],
            ];
        }

        if (class_exists('SDH_Muhely_Szolgaltatas')) {
            $gombok[] = [
                'cimke'  => '+ Új szolgáltatás',
                'ikon'   => 'szolgaltatasok',
                'url'    => SDH_Muhely_Modulok::url('szolgaltatasok', ['nezet' => 'uj']),
                'adatok' => ['sdh-urlap' => 'szolgaltatasok', 'sdh-id' => '0', 'sdh-marad' => 'A szolgáltatás létrejött.'],
            ];
        }

        // Napi árukiadás és rendelés: az új lap űrlapja egy kattintásra (a listájuk a menüben és a csempén).
        if (class_exists('SDH_Muhely_Eladas') && SDH_Muhely_Modulok::be('arukiadas')) {
            $gombok[] = [
                'cimke'  => 'Napi árukiadás',
                'ikon'   => 'arukiadas',
                'url'    => SDH_Muhely_Modulok::url(SDH_Muhely_Eladas::KULCS_ARU),
                'adatok' => ['sdh-urlap' => SDH_Muhely_Eladas::KULCS_ARU, 'sdh-id' => '0'],
            ];
        }

        if (class_exists('SDH_Muhely_Eladas') && SDH_Muhely_Modulok::be('rendelesek')) {
            $gombok[] = [
                'cimke'  => 'Rendelések',
                'ikon'   => 'rendelesek',
                'url'    => SDH_Muhely_Modulok::url(SDH_Muhely_Eladas::KULCS_REND),
                'adatok' => ['sdh-urlap' => SDH_Muhely_Eladas::KULCS_REND, 'sdh-id' => '0'],
            ];
        }

        // A modulcsempék elrejtése / megjelenítése (app.js; a felirat is ott vált).
        // A csempék szerkesztése (mi látszik, sorrend, felirat) adminfeladat: a Beállításokban van.
        $gombok[] = [
            'cimke'  => 'Csempék elrejtése',
            'ikon'   => 'csempek',
            'url'    => '#',
            'adatok' => ['sdh-csempek-valt' => ''],
        ];

        if ($technikai) {
            $gombok[] = [
                'cimke' => 'Megnyitás a műhely-felületen',
                'ikon'  => 'kulso',
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
                $gombok,
                // A műhely-felületen az óra a felső fejlécben áll (minden oldalon), a betűméret a
                // profilmenüben. A wp-adminban nincs saját fejléc: ott az óra itt marad.
                $technikai && class_exists('SDH_Muhely_Nyitvatartas') ? SDH_Muhely_Nyitvatartas::ora_html() : ''
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

        if (class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Modulok::be('levelezes') && SDH_Muhely_Levelezes::van_fiok()) {
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

        if (class_exists('SDH_Muhely_Naptar')) {
            $szamok[SDH_Muhely_Naptar::KULCS] = [SDH_Muhely_Naptar::mai_db(), 'mai esemény'];
        }

        if (class_exists('SDH_Muhely_Eladas')) {
            $szamok[SDH_Muhely_Eladas::KULCS_ARU]  = [SDH_Muhely_Eladas::mai_arukiadas_db(), 'mai árukiadás'];
            $szamok[SDH_Muhely_Eladas::KULCS_REND] = [SDH_Muhely_Eladas::nyitott_rendeles_db(), 'folyamatban lévő rendelés'];
        }

        $ki = [];

        foreach (SDH_Muhely_Modulok::osszes() as $kulcs => $modul) {
            // A csak adminisztrátornak szóló modul (beállítások, karbantartás) a kollégánál nem látszik.
            if (!empty($modul['keszul']) || !empty($modul['rejtett']) || (!empty($modul['csak_admin']) && !self::admin_e())) {
                continue;
            }

            $ki[$kulcs] = [
                'modul' => (string) $modul['cim'],
                // Minden csempe a futó felületen marad: a műhely-felületről a
                // Beállítások csempéje sem visz át a wp-adminba.
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

        // Naptár: ha esedékes esemény kártyája vár kinyomásra, a csempe is jelez.
        if (isset($ki['naptar']) && class_exists('SDH_Muhely_Naptar') && SDH_Muhely_Naptar::nyitott_jelzes_db() > 0) {
            $ki['naptar']['jelez'] = true;
        }

        foreach (['uzenetek', 'csapat'] as $jelzo) {
            if (isset($ki[$jelzo]) && (int) $ki[$jelzo]['szam'] > 0) {
                $ki[$jelzo]['jelez'] = true;
            }
        }

        return self::csempek_alkalmaz($ki);
    }

    /**
     * A csempék friss számai (AJAX): mentés vagy állapotváltás után az
     * Áttekintés ebből frissíti magát, oldal-újratöltés nélkül (app.js:
     * csempekFrissit). Csak a helyben változó, olcsón számolható értékek.
     */
    /** Az aktív ügyfelek / eszközök száma – gyorsítótárazva (0.64): változáskor számolódik újra. */
    public static function aktiv_db(string $tabla): int
    {
        global $wpdb;

        $t = SDH_Muhely_Schema::tabla($tabla);

        return (int) SDH_Muhely_Gyorsitotar::ertek('aktiv_db|' . $tabla, static fn (): int => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE aktiv = 1")); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function ajax_csempe_szamok(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(self::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        global $wpdb;

        $szamok = [
            'munkalapok' => SDH_Muhely_Munkalap::nyitott_db(),
            'ugyfelek'   => self::aktiv_db('ugyfel'),
            'eszkozok'   => self::aktiv_db('eszkoz'),
        ];

        if (class_exists('SDH_Muhely_Penztar')) {
            $szamok['penztar'] = (int) SDH_Muhely_Penztar::kassza_most();
        }

        if (class_exists('SDH_Muhely_Eladas')) {
            $szamok[SDH_Muhely_Eladas::KULCS_ARU]  = SDH_Muhely_Eladas::mai_arukiadas_db();
            $szamok[SDH_Muhely_Eladas::KULCS_REND] = SDH_Muhely_Eladas::nyitott_rendeles_db();
        }

        wp_send_json_success(array_map([self::class, 'szam_szoveg'], $szamok));
    }

    /**
     * Ezres tagolású szám SIMA SZÖVEGKÉNT (JSON-ba, textContent-be). A WordPress
     * magyar nyelvi csomagjának ezres elválasztója az „&nbsp;" HTML-entitás: ez
     * HTML-be írva jó, de szövegként betűre kiírva jelenne meg („157&nbsp;700").
     */
    public static function szam_szoveg(int $n): string
    {
        return html_entity_decode(number_format_i18n($n), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
            // A rejtett modulnak (pl. Asszisztens: a Beállításokból nyílik) nincs csempéje.
            if (!empty($modul['keszul']) || !empty($modul['rejtett'])) {
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
    /**
     * A számozott lapozó oldalszámai (0.64). Legfeljebb kilenc oldalnál mind; többnél az
     * első kettő, az aktuális körüli öt és az utolsó kettő, a kihagyott részek helyén 0
     * (a lapozó ott „…"-ot ír). 500 ezer sornál így nem húszezer link kerül az oldalra.
     *
     * @return array<int, int>
     */
    public static function lapozo_oldalak(int $oldal, int $oldalak): array
    {
        if ($oldalak <= 9) {
            return $oldalak > 0 ? range(1, $oldalak) : [];
        }

        $kell = array_unique(array_filter(
            [1, 2, $oldal - 2, $oldal - 1, $oldal, $oldal + 1, $oldal + 2, $oldalak - 1, $oldalak],
            static fn (int $i): bool => $i >= 1 && $i <= $oldalak
        ));
        sort($kell);

        $ki     = [];
        $elozo  = 0;

        foreach ($kell as $i) {
            if ($i - $elozo === 2) {
                $ki[] = $i - 1; // egyetlen kihagyott oldal: inkább kiírjuk
            } elseif ($i - $elozo > 2) {
                $ki[] = 0;
            }

            $ki[]  = $i;
            $elozo = $i;
        }

        return $ki;
    }

    public static function eszkoz_verzio(string $relativ): string
    {
        $ido = @filemtime(SDH_MUHELY_DIR . $relativ);

        return SDH_MUHELY_VERSION . ($ido ? '.' . $ido : '');
    }
}
