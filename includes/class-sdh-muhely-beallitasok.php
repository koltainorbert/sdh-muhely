<?php
/**
 * Beállítások – a rendszer egyetlen adminfelülete.
 *
 * Minden állítható adat itt van, funkciónként külön lapfülön (pill): egy
 * lapfül = egy téma, saját Mentés gombbal. A felület a CRM-é: ugyanaz a
 * keret, ugyanazok a dobozok és popupok, és a műhely-felületen fut – innen
 * semmi nem visz át a WordPress-adminba. (A wp-adminból megnyitva ugyanez az
 * oldal jelenik meg.)
 *
 * A lapfülek tartalmát a modulok adják (`beallitas_doboz()` rajzol,
 * `beallitas_mentes()` ment); ez az osztály a keretet, a lapfüleket, a
 * lapfülenkénti mentést és a fájlfeltöltést adja. Új beállítás = új elem a
 * szakaszok() listájában. Semmi nincs beégetve a kódba: a rendszert később
 * más szervizek is használják.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Beallitasok
{
    public const KULCS = 'beallitasok';

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'      => self::KULCS,
            'cim'        => 'Beállítások',
            'render'     => [self::class, 'oldal'],
            'sorrend'    => 95,
            'csak_admin' => true,
        ]);

        // Mentés lapfülenként: AJAX-szal (helyben), JavaScript nélkül sima beküldéssel.
        add_action('wp_ajax_sdh_muhely_beallitasok_ment', [self::class, 'ajax_mentes']);
        add_action('admin_post_sdh_muhely_beallitasok', [self::class, 'mentes']);

        // Kép / videó feltöltése a médiatárba (logó, háttér) – a műhely-felületről is.
        add_action('wp_ajax_sdh_muhely_beallitasok_feltolt', [self::class, 'ajax_feltolt']);

        add_action('admin_enqueue_scripts', [self::class, 'admin_eszkozok'], 20);
    }

    public static function admin_eszkozok(): void
    {
        $oldal = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($oldal !== SDH_Muhely_Admin_UI::FOMENU . '-' . self::KULCS) {
            return;
        }

        wp_enqueue_script(
            'sdh-muhely-beallitasok',
            SDH_MUHELY_URL . 'assets/beallitasok.js',
            ['sdh-muhely-app'],
            SDH_Muhely_Admin_UI::eszkoz_verzio('assets/beallitasok.js'),
            true
        );
    }

    /* =================================================================
     * A lapfülek
     * ============================================================== */

    /**
     * A csoportok (a lapfülek sorai), sorrendben.
     *
     * @return array<string, string>
     */
    private static function csoportok(): array
    {
        return [
            'megjelenes' => 'Cég és megjelenés',
            'munkalap'   => 'Munkalap',
            'penz'       => 'Pénz és eladás',
            'kapcsolat'  => 'Kapcsolat és csapat',
            'eszkozok'   => 'Készülékadatok',
            'rendszer'   => 'Rendszer',
        ];
    }

    /**
     * Minden lapfül: kulcs => cím, csoport, rövid leírás, rajzoló, mentő.
     * `ment` nélkül a lapfülnek saját gombjai vannak (nincs közös Mentés);
     * `ujratolt`: mentés után az oldal újratölt, mert a változás az egész
     * felületet érinti (logó, színek, csempék).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function szakaszok(): array
    {
        $munkalap = static fn (string $melyik): callable => static function () use ($melyik): void {
            SDH_Muhely_Munkalap::beallitas_doboz($melyik);
        };

        $lista = [
            // --- Cég és megjelenés -------------------------------------------
            'arculat' => [
                'cim' => 'Logó és megjelenés', 'csoport' => 'megjelenes', 'ujratolt' => true,
                'leiras' => 'A rendszer logója, neve, színei, a sarkok, az oldalsáv és a popupok neon kerete.',
                'rajz' => [SDH_Muhely_Arculat::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Arculat::class, 'beallitas_mentes'],
            ],
            'csempek' => [
                'cim' => 'Áttekintés csempéi', 'csoport' => 'megjelenes',
                'leiras' => 'Melyik modul csempéje látszik az Áttekintés tetején, milyen sorrendben és felirattal.',
                'rajz' => [SDH_Muhely_Admin_UI::class, 'csempek_doboz'], 'ment' => [SDH_Muhely_Admin_UI::class, 'csempek_mentes'],
            ],
            'nyitvatartas' => [
                'cim' => 'Nyitvatartás és óra', 'csoport' => 'megjelenes',
                'leiras' => 'Az üzlet nyitvatartása naponként, és az Áttekintés órája a zárásig hátralévő idővel.',
                'rajz' => [SDH_Muhely_Nyitvatartas::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Nyitvatartas::class, 'beallitas_mentes'],
            ],
            'ugyfeloldal' => [
                'cim' => 'Ügyféloldal (RMA)', 'csoport' => 'megjelenes',
                'leiras' => 'Amit az ügyfél a QR-kód beolvasása után lát: cím, értesítés, megjelenés, nyelvek, elérhetőség.',
                'rajz' => [SDH_Muhely_Rma::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Rma::class, 'beallitas_mentes'],
            ],

            // --- Munkalap ----------------------------------------------------
            'szamozas' => [
                'cim' => 'Számozás', 'csoport' => 'munkalap',
                'leiras' => 'A munkalapszám kezdőértéke, előtagja és kitöltése.',
                'rajz' => $munkalap('szamozas'), 'ment' => [SDH_Muhely_Munkalap::class, 'beallitas_mentes'],
            ],
            'allapotok' => [
                'cim' => 'Állapotok', 'csoport' => 'munkalap',
                'leiras' => 'A munkalap állapotai: név, szín, és hogy melyik számozott, lezárt, alapértelmezett.',
                'rajz' => $munkalap('allapotok'), 'ment' => [SDH_Muhely_Munkalap::class, 'beallitas_mentes'],
            ],
            'hiba-allapotok' => [
                'cim' => 'Hibasor-állapotok', 'csoport' => 'munkalap',
                'leiras' => 'A munkalap hibasorainak saját állapotlistája.',
                'rajz' => $munkalap('hiba-allapotok'), 'ment' => [SDH_Muhely_Munkalap::class, 'beallitas_mentes'],
            ],
            'gyakori-hibak' => [
                'cim' => 'Gyakori hibák', 'csoport' => 'munkalap',
                'leiras' => 'A Hibák lapfülön egy kattintással felvehető hibák listája.',
                'rajz' => $munkalap('gyakori-hibak'), 'ment' => [SDH_Muhely_Munkalap::class, 'beallitas_mentes'],
            ],
            'gyakori-megjegyzesek' => [
                'cim' => 'Gyakori megjegyzések', 'csoport' => 'munkalap',
                'leiras' => 'A Megjegyzés lapfülön popupból választható, előre megírt megjegyzések.',
                'rajz' => $munkalap('gyakori-megjegyzesek'), 'ment' => [SDH_Muhely_Munkalap::class, 'beallitas_mentes'],
            ],

            // --- Pénz és eladás ----------------------------------------------
            'fizmodok' => [
                'cim' => 'Fizetési módok', 'csoport' => 'penz',
                'leiras' => 'A munkalapon, az árukiadáson és a rendelésen választható fizetési módok.',
                'rajz' => $munkalap('fizmodok'), 'ment' => [SDH_Muhely_Munkalap::class, 'beallitas_mentes'],
            ],
            'szamlazas' => [
                'cim' => 'Számlázás', 'csoport' => 'penz',
                'leiras' => 'Számlázz.hu-kapcsolat, számlatömbök, a helyi nyomtatvány és a kiállító adatai, a bevizsgálási díj szövege.',
                'rajz' => [SDH_Muhely_Szamla::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Szamla::class, 'beallitas_mentes'],
            ],
            'nyomtatvanyok' => [
                'cim' => 'Nyomtatványok', 'csoport' => 'penz',
                'leiras' => 'Minden nyomtatható papír: átvételi, munkalap, árajánlat, szállítólevél, bianko, pénztárzárás, listák – logó, betű, méret, szövegek, példányszám.',
                'rajz' => [SDH_Muhely_Nyomtatas::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Nyomtatas::class, 'beallitas_mentes'],
            ],
            'penztar' => [
                'cim' => 'Házipénztár', 'csoport' => 'penz',
                'leiras' => 'Személyek, címletek, fizetési módok besorolása, kategóriák, a pénztár-ajánlat.',
                'rajz' => [SDH_Muhely_Penztar::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Penztar::class, 'beallitas_mentes'],
            ],
            'eladas' => [
                'cim' => 'Árukiadás és rendelések', 'csoport' => 'penz',
                'leiras' => 'Helyszínek (üzletek), beszállítók, a rendelés állapotainak neve, az értesítő szövege, a listák mérete.',
                'rajz' => [SDH_Muhely_Eladas::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Eladas::class, 'beallitas_mentes'],
            ],

            // --- Kapcsolat és csapat -----------------------------------------
            'sms' => [
                'cim' => 'SMS-értesítések', 'csoport' => 'kapcsolat',
                'leiras' => 'Az SMS-átjáró (Android telefon), újrapróbálás, csendes órák, aláírás, önteszt és riasztás.',
                'rajz' => [SDH_Muhely_Sms::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Sms::class, 'beallitas_mentes'],
            ],
            'levelezes' => [
                'cim' => 'Levelezés', 'csoport' => 'kapcsolat',
                'leiras' => 'E-mail-fiókok (IMAP / SMTP), aláírás, jelszavas zár, a levélszűrő és a rendező ügynök.',
                'rajz' => [SDH_Muhely_Levelezes::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Levelezes::class, 'beallitas_mentes'],
            ],
            'csapat' => [
                'cim' => 'Csapat', 'csoport' => 'kapcsolat',
                'leiras' => 'Többfelhasználós csevegés vagy üzenőfal, a fal neve, a kollégák névsora.',
                'rajz' => [self::class, 'csapat_doboz'], 'ment' => [SDH_Muhely_Csapat::class, 'beallitas_mentes'],
            ],
            'naptar' => [
                'cim' => 'Naptár', 'csoport' => 'kapcsolat',
                'leiras' => 'A naptár alapnézete és órasávja, a kategóriák, az emlékeztetők, az e-mail értesítések és a napi összefoglaló.',
                'rajz' => [SDH_Muhely_Naptar::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Naptar::class, 'beallitas_mentes'],
            ],
            'asszisztens' => [
                'cim' => 'Asszisztens', 'csoport' => 'kapcsolat',
                'leiras' => 'Az AI-asszisztens: be- és kikapcsolás, neve, kérdéskeret, API-kulcs, engedélyek.',
                'rajz' => [self::class, 'asszisztens_doboz'],
            ],

            // --- Készülékadatok ----------------------------------------------
            'imei' => [
                'cim' => 'IMEI-lekérdezés', 'csoport' => 'eszkozok',
                'leiras' => 'A fizetős IMEI-szolgáltató végpontja és kulcsa, és az ingyenes ellenőrző oldalak gombjai.',
                'rajz' => [self::class, 'imei_doboz'], 'ment' => [self::class, 'imei_mentes'],
            ],
            'mockupok' => [
                'cim' => 'Mockupok', 'csoport' => 'eszkozok',
                'leiras' => 'Az eszközök mellett álló termékképek: kategória, gyártó és típus szerint; feltöltés, csere, törlés, generáló szöveg.',
                'rajz' => [SDH_Muhely_Mockup::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Mockup::class, 'beallitas_mentes'],
            ],
            'tac' => [
                'cim' => 'TAC adatbázis', 'csoport' => 'eszkozok',
                'leiras' => 'Az IMEI elejéből ez mondja meg a gyártót és a típust: állapot, betöltés.',
                'rajz' => [SDH_Muhely_Tac::class, 'oldal'],
            ],

            // --- Rendszer ----------------------------------------------------
            'mentes' => [
                'cim' => 'Mentés', 'csoport' => 'rendszer',
                'leiras' => 'A napi automatikus mentés, a megőrzött mentések száma, kézi mentés és letöltés.',
                'rajz' => [SDH_Muhely_Mentes::class, 'beallitas_doboz'], 'ment' => [SDH_Muhely_Mentes::class, 'beallitas_mentes'],
            ],
            'demo' => [
                'cim' => 'Demó adatok', 'csoport' => 'rendszer',
                'leiras' => 'Fiktív ügyfelek, készülékek, munkalapok és termékek a kipróbáláshoz.',
                'rajz' => [SDH_Muhely_Demo::class, 'doboz'],
            ],
            'kalauz' => [
                'cim' => 'Stíluskalauz', 'csoport' => 'rendszer',
                'leiras' => 'A rendszer színei és elemei egy helyen – itt látszik először, ha valami elromlik.',
                'rajz' => [SDH_Muhely_Arculat::class, 'kalauz'],
            ],
            'rendszer' => [
                'cim' => 'Névjegy', 'csoport' => 'rendszer',
                'leiras' => 'Verziók, címek, és ami internet nélkül is működik.',
                'rajz' => [self::class, 'rendszer_doboz'],
            ],
        ];

        // Az árukiadás és a rendelés közös lapfüle: akkor tűnik el, ha mindkettő ki van kapcsolva.
        if (SDH_Muhely_Modulok::ki('arukiadas') && SDH_Muhely_Modulok::ki('rendelesek')) {
            unset($lista['eladas']);
        }

        // Csak az marad, aminek a modulja be van töltve – és be van kapcsolva (Modulok menüpont).
        return array_filter($lista, static function (array $sz, string $ful): bool {
            $rajz  = $sz['rajz'];
            $modul = SDH_Muhely_Modulok::szakasz_modulja($ful);

            if ($modul !== '' && SDH_Muhely_Modulok::ki($modul)) {
                return false;
            }

            return !is_array($rajz) || !is_string($rajz[0]) || class_exists($rajz[0]);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** Egy lapfül címe a futó felületen. */
    public static function url(string $ful = '', array $tovabbi = []): string
    {
        return SDH_Muhely_Modulok::url(self::KULCS, ($ful !== '' ? ['ful' => $ful] : []) + $tovabbi);
    }

    /* =================================================================
     * Az oldal
     * ============================================================== */

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::admin_jog_ellenoriz();

        $szakaszok = self::szakaszok();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $ful = isset($_GET['ful']) ? sanitize_title(wp_unslash($_GET['ful'])) : '';
        $ful = isset($szakaszok[$ful]) ? $ful : (string) array_key_first($szakaszok);
        $sz  = $szakaszok[$ful];

        ?>
        <div class="sdh-wrap sdh-wrap--teljes sdh-beall" data-sdh-beallitasok>
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Beállítások',
                'Minden állítható adat egy helyen, témánként külön lapfülön. Minden lapfül külön ment.'
            );
            ?>

            <nav class="sdh-beall__menu" aria-label="Beállítások lapfülei">
                <?php foreach (self::csoportok() as $csoport => $csoport_nev) : ?>
                    <?php
                    $elemek = array_filter($szakaszok, static fn (array $s): bool => $s['csoport'] === $csoport);

                    if ($elemek === []) {
                        continue;
                    }
                    ?>
                    <div class="sdh-beall__sor">
                        <span class="sdh-beall__csoport"><?php echo esc_html($csoport_nev); ?></span>
                        <div class="sdh-beall__pillek">
                            <?php foreach ($elemek as $kulcs => $elem) : ?>
                                <a class="sdh-pill<?php echo $kulcs === $ful ? ' is-aktiv' : ''; ?>"
                                   href="<?php echo esc_url(self::url((string) $kulcs)); ?>"
                                   title="<?php echo esc_attr((string) $elem['leiras']); ?>"
                                   <?php echo $kulcs === $ful ? 'aria-current="page"' : ''; ?>>
                                    <span class="sdh-pill__cimke"><?php echo esc_html((string) $elem['cim']); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </nav>

            <section class="sdh-beall__tartalom" data-sdh-beall-ful="<?php echo esc_attr($ful); ?>">
                <header class="sdh-beall__fej">
                    <h2 class="sdh-beall__cim"><?php echo esc_html((string) $sz['cim']); ?></h2>
                    <p class="sdh-beall__leiras"><?php echo esc_html((string) $sz['leiras']); ?></p>
                </header>

                <?php if (!empty($sz['ment'])) : ?>
                    <form class="sdh-urlap sdh-beall__urlap" method="post" enctype="multipart/form-data"
                          action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          data-sdh-beall-urlap<?php echo !empty($sz['ujratolt']) ? ' data-ujratolt="1"' : ''; ?>>
                        <input type="hidden" name="action" value="sdh_muhely_beallitasok">
                        <input type="hidden" name="ful" value="<?php echo esc_attr($ful); ?>">
                        <input type="hidden" name="kontextus" value="<?php echo esc_attr(SDH_Muhely_Modulok::kontextus()); ?>">
                        <?php wp_nonce_field('sdh_muhely_beallitasok', 'sdh_nonce'); ?>

                        <?php call_user_func($sz['rajz']); ?>

                        <div class="sdh-urlap__lablec sdh-beall__lablec">
                            <button type="submit" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-beall-ment>Mentés</button>
                            <span class="sdh-beall__allapot" data-sdh-beall-allapot role="status" aria-live="polite"></span>
                        </div>
                    </form>
                <?php else : ?>
                    <div class="sdh-beall__sajat">
                        <?php call_user_func($sz['rajz']); ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    /* =================================================================
     * Mentés
     * ============================================================== */

    /**
     * A beküldött lapfül mentése. Visszatérés: a lapfül kulcsa, vagy hibaüzenet.
     *
     * @return array{ful: string, cim: string, ujratolt: bool}|string
     */
    private static function feldolgoz()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a hívó ellenőrizte.
        $ful       = isset($_POST['ful']) ? sanitize_title(wp_unslash($_POST['ful'])) : '';
        $szakaszok = self::szakaszok();

        if (!isset($szakaszok[$ful]) || empty($szakaszok[$ful]['ment'])) {
            return 'Ismeretlen beállítás-lapfül.';
        }

        call_user_func($szakaszok[$ful]['ment']);

        return [
            'ful'      => $ful,
            'cim'      => (string) $szakaszok[$ful]['cim'],
            'ujratolt' => !empty($szakaszok[$ful]['ujratolt']),
        ];
    }

    /** Mentés helyben (AJAX): az oldal marad, a gomb mellett megjelenik az eredmény. */
    public static function ajax_mentes(): void
    {
        check_ajax_referer('sdh_muhely_beallitasok', 'sdh_nonce');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'A beállításokhoz adminisztrátori jog kell.'], 403);
        }

        $eredmeny = self::feldolgoz();

        if (is_string($eredmeny)) {
            wp_send_json_error(['uzenet' => $eredmeny]);
        }

        wp_send_json_success($eredmeny + ['uzenet' => $eredmeny['cim'] . ': elmentve.']);
    }

    /** Mentés sima beküldéssel (JavaScript nélkül): vissza ugyanarra a lapfülre. */
    public static function mentes(): void
    {
        SDH_Muhely_Admin_UI::admin_jog_ellenoriz();
        check_admin_referer('sdh_muhely_beallitasok', 'sdh_nonce');

        $eredmeny  = self::feldolgoz();
        $kontextus = isset($_POST['kontextus']) && sanitize_key(wp_unslash($_POST['kontextus'])) === 'frontend' ? 'frontend' : 'admin';

        wp_safe_redirect(SDH_Muhely_Modulok::visszateres(
            self::KULCS,
            is_string($eredmeny) ? [] : ['ful' => $eredmeny['ful'], 'uzenet' => 'mentve'],
            $kontextus
        ));
        exit;
    }

    /* =================================================================
     * Fájlfeltöltés (logó, háttérkép, háttérvideó)
     * ============================================================== */

    /**
     * Kép vagy videó feltöltése a médiatárba; a válasz a fájl címe. A
     * műhely-felületen nincs WordPress-médiaválasztó, ezért saját, egyszerű
     * feltöltő van (beallitasok.js) – mindkét felületen ugyanaz.
     */
    public static function ajax_feltolt(): void
    {
        check_ajax_referer('sdh_muhely_beallitasok', 'sdh_nonce');

        if (!SDH_Muhely_Admin_UI::admin_e() || !current_user_can('upload_files')) {
            wp_send_json_error(['uzenet' => 'A feltöltéshez adminisztrátori jog kell.'], 403);
        }

        if (empty($_FILES['fajl']) || !is_array($_FILES['fajl']) || (int) ($_FILES['fajl']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            wp_send_json_error(['uzenet' => 'A fájl nem érkezett meg (túl nagy, vagy megszakadt a feltöltés).']);
        }

        $tipus   = isset($_POST['tipus']) && sanitize_key(wp_unslash($_POST['tipus'])) === 'video' ? 'video' : 'image';
        $engedett = $tipus === 'video'
            ? ['mp4' => 'video/mp4', 'webm' => 'video/webm']
            : ['jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $id = media_handle_upload('fajl', 0, [], ['test_form' => false, 'mimes' => $engedett]);

        if (is_wp_error($id)) {
            wp_send_json_error(['uzenet' => 'A feltöltés nem sikerült: ' . $id->get_error_message()]);
        }

        wp_send_json_success(['url' => (string) wp_get_attachment_url((int) $id), 'id' => (int) $id]);
    }

    /* =================================================================
     * Saját lapfülek
     * ============================================================== */

    /** IMEI: a fizetős szolgáltató és az ingyenes ellenőrző oldalak. */
    public static function imei_doboz(): void
    {
        $b = SDH_Muhely_Imei_Lekerdezes::beallitas();

        $ellenorzok = get_option('sdh_muhely_imei_ellenorzok', '');

        if (!is_string($ellenorzok) || trim($ellenorzok) === '') {
            $ellenorzok = SDH_Muhely_Imei_Lekerdezes::alap_ellenorzok_szovegkent();
        }

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">IMEI-szolgáltató</h2>

            <p class="sdh-sugo">
                A gyári szám, a sorozatszám, a garancia és a gyártási dátum a gyártó
                szerveréről jön – ezt fizetős szolgáltatón keresztül lehet lekérdezni
                (imeicheck.com, imeicheck.net, sickw és társaik). Írd be a végpont
                címét: az <code>{imei}</code> és a <code>{kulcs}</code> helyére a
                rendszer behelyettesít. Ha a szolgáltató fejlécben várja a kulcsot,
                azt is elküldjük <code>Authorization: Bearer</code> formában.
            </p>

            <?php
            if (class_exists('SDH_Muhely_Titok')) {
                SDH_Muhely_Titok::figyelmeztetes('sdh_muhely_imei_szolgaltato');
            }
            ?>

            <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--also-ter">
                <input type="hidden" name="imei_jelen" value="1">
                <input type="checkbox" name="aktiv" id="aktiv" value="1" <?php checked($b['aktiv']); ?>>
                <label for="aktiv">Lekérdezés bekapcsolva</label>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="url">Végpont címe</label>
                    <input type="text" name="url" id="url" value="<?php echo esc_attr($b['url']); ?>"
                           placeholder="https://pelda.hu/api/check?key={kulcs}&imei={imei}">
                    <span class="sdh-mezo__sugo">
                        A szolgáltatód dokumentációjából másold ide, a kulcsot és az
                        IMEI-t helyettesítve a kapcsos zárójeles jelölőkkel.
                    </span>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="kulcs">API-kulcs</label>
                    <?php // A kulcs soha nem kerül vissza a HTML-be: titkosítva tárolódik. ?>
                    <input type="password" name="kulcs" id="kulcs" value="" autocomplete="new-password"
                           placeholder="<?php echo esc_attr($b['kulcs'] !== '' ? '•••••••• (mentve – csak akkor írd be, ha cserélnéd)' : 'Ide másold be a kulcsot'); ?>">
                    <?php if ($b['kulcs'] !== '') : ?>
                        <span class="sdh-mezo__sugo">
                            <label class="sdh-jelolo"><input type="checkbox" name="kulcs_torol" value="1"> Kulcs törlése</label>
                            Titkosítva tárolva – a mentésfájlba is így kerül.
                        </span>
                    <?php else : ?>
                        <span class="sdh-mezo__sugo">Titkosítva tárolódik; mentés után nem jelenik meg újra.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Ingyenes ellenőrző oldalak</h2>

            <p class="sdh-sugo">
                Ezek a gombok jelennek meg az eszköz-űrlap tetején. Soronként egy:
                <code>Név | URL</code>. A gomb megnyitja az oldalt, és az IMEI-t a
                vágólapra teszi, hogy ott csak be kelljen illeszteni. Ha egy oldal
                az URL-ben is fogadja az IMEI-t, írd a címbe az <code>{imei}</code>
                jelölőt – akkor már kitöltve nyílik meg.
            </p>

            <div class="sdh-mezo sdh-mezo--szeles">
                <label for="ellenorzok">Oldalak</label>
                <textarea name="ellenorzok" id="ellenorzok" rows="4" class="sdh-kod"><?php echo esc_textarea($ellenorzok); ?></textarea>
                <span class="sdh-mezo__sugo">Üresen hagyva az alapértelmezett lista jön vissza.</span>
            </div>
        </div>
        <?php
    }

    public static function imei_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['imei_jelen'])) {
            return;
        }

        // Üres mező = a mentett kulcs marad (a tárolt, titkosított alakjában); csak a beírt új érték
        // vagy a „Kulcs törlése" változtat.
        $uj    = isset($_POST['kulcs']) && is_scalar($_POST['kulcs']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['kulcs']))) : '';
        $torol = !empty($_POST['kulcs_torol']);
        $kulcs = class_exists('SDH_Muhely_Titok')
            ? SDH_Muhely_Titok::mentendo('sdh_muhely_imei_szolgaltato', $uj, $torol)
            : ($torol ? '' : ($uj !== '' ? $uj : SDH_Muhely_Imei_Lekerdezes::beallitas()['kulcs']));

        update_option('sdh_muhely_imei_szolgaltato', [
            'url'   => isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '',
            'kulcs' => $kulcs,
            'aktiv' => !empty($_POST['aktiv']),
        ]);

        update_option(
            'sdh_muhely_imei_ellenorzok',
            isset($_POST['ellenorzok']) ? sanitize_textarea_field(wp_unslash($_POST['ellenorzok'])) : ''
        );
        // phpcs:enable
    }

    /** Csapat: a mód beállítása, és átjárás a kollégák / csoportok kezeléséhez. */
    public static function csapat_doboz(): void
    {
        SDH_Muhely_Csapat::beallitas_doboz();

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Kollégák és csoportok</h2>
            <p class="sdh-sugo">
                A kollégák felvétele, szerkesztése, letiltása és a csoportok a Csapat menüpontban vannak
                (popupban szerkeszthetők).
            </p>
            <div class="sdh-beall__gombok">
                <a class="sdh-gomb" href="<?php echo esc_url(SDH_Muhely_Modulok::url(SDH_Muhely_Csapat::KULCS, ['nezet' => 'kollegak'])); ?>">Kollégák kezelése</a>
                <a class="sdh-gomb" href="<?php echo esc_url(SDH_Muhely_Modulok::url(SDH_Muhely_Csapat::KULCS, ['nezet' => 'csoportok'])); ?>">Csoportok kezelése</a>
            </div>
        </div>
        <?php
    }

    /** Asszisztens: a saját beállítás-űrlapja (helyben ment), és átjárás a tudástárhoz. */
    public static function asszisztens_doboz(): void
    {
        // Az űrlap az asszisztensé (helyben ment); innen nyitva a mentés ide tér vissza.
        ob_start();
        SDH_Muhely_Asszisztens::beallitas_nezet();
        $urlap = (string) ob_get_clean();

        echo preg_replace('/(<form\b[^>]*data-sdh-ai-beallitas[^>]*>)/', '$1<input type="hidden" name="honnan" value="beallitasok">', $urlap, 1); // phpcs:ignore WordPress.Security.EscapeOutput -- a saját, már szűrt kimenetünk.

        ?>
        <div class="sdh-doboz" data-sdh-asszisztens-oldalak>
            <h2 class="sdh-doboz__cim">Javaslatok, tudástár és mentések</h2>
            <p class="sdh-sugo">
                Az asszisztensnek nincs külön menüpontja: innen nyílik a jóváhagyásra váró javaslatok és a napló,
                a tudástár (amit a cégről tud) és a módosítások előtt készült mentések oldala.
            </p>
            <div class="sdh-beall__gombok">
                <?php $sdh_fuggo = SDH_Muhely_Asszisztens::fuggo_db(); ?>
                <a class="sdh-gomb" href="<?php echo esc_url(SDH_Muhely_Modulok::url(SDH_Muhely_Asszisztens::KULCS)); ?>">Javaslatok és napló<?php echo $sdh_fuggo > 0 ? ' (' . (int) $sdh_fuggo . ' vár jóváhagyásra)' : ''; ?></a>
                <a class="sdh-gomb" href="<?php echo esc_url(SDH_Muhely_Modulok::url(SDH_Muhely_Asszisztens::KULCS, ['nezet' => 'tudastar'])); ?>">Tudástár</a>
                <a class="sdh-gomb" href="<?php echo esc_url(SDH_Muhely_Modulok::url(SDH_Muhely_Asszisztens::KULCS, ['nezet' => 'mentesek'])); ?>">Mentések</a>
            </div>
        </div>
        <?php
    }

    /** Névjegy: verziók, címek, és ami internet nélkül is megy. */
    public static function rendszer_doboz(): void
    {
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Verziók</h2>
            <table class="sdh-tabla sdh-tabla--keskeny">
                <tbody>
                    <tr><th>Rendszer</th><td><?php echo esc_html(SDH_Muhely_Arculat::rendszer_nev()); ?></td></tr>
                    <tr><th>Plugin verzió</th><td><code><?php echo esc_html(SDH_MUHELY_VERSION); ?></code></td></tr>
                    <tr><th>Séma verzió</th><td><code><?php echo esc_html(SDH_Muhely_Schema::DB_VERSION); ?></code></td></tr>
                    <tr><th>Műhely-felület</th><td><code><?php echo esc_html(SDH_Muhely_Modulok::frontend_url()); ?></code></td></tr>
                    <tr><th>WordPress</th><td><code><?php echo esc_html(get_bloginfo('version')); ?></code></td></tr>
                    <tr><th>PHP</th><td><code><?php echo esc_html(PHP_VERSION); ?></code></td></tr>
                </tbody>
            </table>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Ami internet nélkül is megy</h2>
            <p class="sdh-sugo sdh-sugo--utolso">
                IMEI-szolgáltató nélkül a rendszer a saját nyilvántartásából és a helyi
                TAC-adatbázisból tölti ki a gyártót, a gyári számot és a kereskedelmi
                nevet. A sorozatszámot, a garanciát és a gyártási dátumot kézzel kell
                beírni. A levelezés, a Számlázz.hu-számla és az asszisztens kér internetet –
                a felvitel, a munkalap, a pénztár és a helyi nyomtatvány enélkül is megy.
            </p>
        </div>
        <?php
    }
}
