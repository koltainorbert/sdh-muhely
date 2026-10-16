<?php
/**
 * Modulok – a fő funkciók ki- és bekapcsolása (0.58).
 *
 * Saját admin-menüpont (csak adminisztrátornak): modulonként egy kártya
 * kapcsolóval. A kikapcsolt modulnak nincs menüpontja, csempéje, oldala,
 * beállítás-lapfüle, és az AJAX-végpontjai elutasítanak – ezt az őr (orszem)
 * intézi egy helyen, a modulok kódjához nem kell hozzányúlni. A modulok
 * listája és a be() lekérdezés a SDH_Muhely_Modulok osztályban van, mert
 * azt minden modul eléri, már a betöltéskor.
 *
 * Telepítői zár: ha be van állítva egy kód, a modulok csak a kóddal
 * kapcsolhatók át – így az ügyfélnél telepített rendszerben az ügyfél
 * adminisztrátora sem kapcsol be magának olyan modult, amit nem rendelt meg.
 * (Ez szervezési zár, nem kódvédelem: a fájlokhoz hozzáférő ember kikerülheti.)
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Modul_Kapcsolo
{
    public const KULCS = 'modulok';

    /** A telepítői zár kódjának hash-e (wp_hash_password). Üres = nincs zár. */
    public const OPT_ZAR = 'sdh_muhely_modulok_zar';

    /** Az utolsó mentés eredménye felhasználónként (az átirányítás utáni üzenethez). */
    private const ATMENETI = 'sdh_modulok_uzenet_';

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'      => self::KULCS,
            'cim'        => 'Modulok',
            'render'     => [self::class, 'oldal'],
            'sorrend'    => 96,
            'csak_admin' => true,
            'csak_fejleszto' => true,
        ]);

        // Az őr az admin_init legelején fut: az admin-ajax.php és az admin-post.php is ezen megy át.
        add_action('admin_init', [self::class, 'orszem'], 0);

        // A kikapcsolt modulok body-osztálya (sdh-ki-<modul>) a wp-adminban is – a CSS ebből rejti a szkriptből rajzolt gombjaikat.
        add_filter('admin_body_class', static function ($osztalyok): string {
            return (string) $osztalyok . self::body_osztalyok();
        });

        add_action('admin_post_sdh_muhely_modulok', [self::class, 'mentes']);
        add_action('admin_post_sdh_muhely_modulok_zar', [self::class, 'zar_mentes']);
    }

    /* =================================================================
     * Őr: a kikapcsolt modul végpontjai elutasítanak
     * ============================================================== */

    /** A kikapcsolt modul üzenete (a függőséget is megnevezi, ha az a baj). */
    public static function ki_szoveg(string $kulcs): string
    {
        $k   = SDH_Muhely_Modulok::katalogus();
        $cim = (string) ($k[$kulcs]['cim'] ?? $kulcs);

        foreach ((array) ($k[$kulcs]['fugg'] ?? []) as $alap) {
            if (in_array((string) $alap, SDH_Muhely_Modulok::kikapcsoltak(), true) && !in_array($kulcs, SDH_Muhely_Modulok::kikapcsoltak(), true)) {
                return sprintf('A(z) „%s” modul nem fut, mert ki van kapcsolva, amire épül: „%s”. Az adminisztrátor a Modulok menüpontban kapcsolhatja be.', $cim, (string) ($k[$alap]['cim'] ?? $alap));
            }
        }

        return sprintf('A(z) „%s” modul ki van kapcsolva. Az adminisztrátor a Modulok menüpontban kapcsolhatja be.', $cim);
    }

    public static function orszem(): void
    {
        $akcio = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (strpos($akcio, 'sdh_muhely_') !== 0) {
            return;
        }

        $muvelet = substr($akcio, strlen('sdh_muhely_'));

        if (wp_doing_ajax()) {
            $modul = SDH_Muhely_Modulok::ajax_modulja($muvelet);

            if ($modul === '' || SDH_Muhely_Modulok::be($modul)) {
                return;
            }

            // Az űrlap-végpontok HTML-t adnak a popupba: ott a popup mondja el, mi a helyzet.
            if (substr($muvelet, -6) === '_urlap') {
                echo '<h2 class="sdh-modal__cim">Kikapcsolt modul</h2><div class="sdh-uzenet sdh-uzenet--hiba">'
                    . esc_html(self::ki_szoveg($modul)) . '</div>';
                wp_die();
            }

            wp_send_json_error(['uzenet' => self::ki_szoveg($modul), 'modul_ki' => $modul], 403);
        }

        // admin-post.php (sima űrlapbeküldés).
        $modul = SDH_Muhely_Modulok::post_modulja($muvelet);

        if ($modul !== '' && !SDH_Muhely_Modulok::be($modul)) {
            wp_die(esc_html(self::ki_szoveg($modul)), 'Kikapcsolt modul', ['response' => 403, 'back_link' => true]);
        }
    }

    /** „ sdh-ki-sms sdh-ki-naptar” – szóközzel kezdődik, üres, ha minden be van kapcsolva. */
    public static function body_osztalyok(): string
    {
        $ki = '';

        foreach (array_keys(SDH_Muhely_Modulok::katalogus()) as $kulcs) {
            if (SDH_Muhely_Modulok::ki($kulcs)) {
                $ki .= ' sdh-ki-' . $kulcs;
            }
        }

        // A szerepkör (H5) tiltásai: sdh-ki-<modul> és sdh-nem-<jog>.
        if (class_exists('SDH_Muhely_Szerepkor')) {
            $ki .= SDH_Muhely_Szerepkor::body_osztalyok();
        }

        return $ki;
    }

    /* =================================================================
     * Telepítői zár
     * ============================================================== */

    public static function zarolva(): bool
    {
        return (string) get_option(self::OPT_ZAR, '') !== '';
    }

    public static function kod_jo(string $kod): bool
    {
        $hash = (string) get_option(self::OPT_ZAR, '');

        return $hash === '' || ($kod !== '' && wp_check_password($kod, $hash));
    }

    /* =================================================================
     * Mentés
     * ============================================================== */

    /**
     * A kapcsolók mentése (a teszt is ezt hívja). A `be` lista a bekapcsolt
     * modulok kulcsai; ami nincs benne, az kikapcsol.
     *
     * @param array<int, string> $be
     * @return array{ok: bool, uzenet: string, ki: array<int, string>, plusz: array<int, string>}
     */
    public static function feldolgoz(array $be, string $kod = ''): array
    {
        if (!self::kod_jo($kod)) {
            return ['ok' => false, 'uzenet' => 'A telepítői kód nem jó – a modulok nem változtak.', 'ki' => SDH_Muhely_Modulok::kikapcsoltak(), 'plusz' => []];
        }

        $katalogus = SDH_Muhely_Modulok::katalogus();
        $be        = array_map('strval', $be);
        $ki        = array_values(array_diff(array_keys($katalogus), $be));
        $plusz     = SDH_Muhely_Modulok::kikapcsoltak_ment($ki);
        $ki        = SDH_Muhely_Modulok::kikapcsoltak();

        // A menü és a súgó gyorsítótára a modulok listájától függ.
        delete_transient('sdh_muhely_sugo_index');
        delete_transient('sdh_muhely_ai_statikus');

        $uzenet = $ki === []
            ? 'Elmentve: minden modul be van kapcsolva.'
            : sprintf('Elmentve: %d modul bekapcsolva, %d kikapcsolva.', count($katalogus) - count($ki), count($ki));

        if ($plusz !== []) {
            $nevek = array_map(static fn (string $k): string => '„' . (string) ($katalogus[$k]['cim'] ?? $k) . '”', $plusz);
            $uzenet .= ' Ezek is kikapcsoltak, mert arra épülnek, amit kikapcsoltál: ' . implode(', ', $nevek) . '.';
        }

        return ['ok' => true, 'uzenet' => $uzenet, 'ki' => $ki, 'plusz' => $plusz];
    }

    public static function mentes(): void
    {
        SDH_Muhely_Admin_UI::fejleszto_jog_ellenoriz();
        check_admin_referer('sdh_muhely_modulok', 'sdh_nonce');

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- fent ellenőrizve.
        $be  = isset($_POST['be']) && is_array($_POST['be']) ? array_map('sanitize_key', wp_unslash($_POST['be'])) : [];
        $kod = isset($_POST['kod']) ? (string) wp_unslash($_POST['kod']) : '';
        // phpcs:enable

        $e = self::feldolgoz($be, $kod);

        set_transient(self::ATMENETI . get_current_user_id(), ['tipus' => $e['ok'] ? 'siker' : 'hiba', 'szoveg' => $e['uzenet']], 120);

        wp_safe_redirect(self::vissza());
        exit;
    }

    /**
     * A telepítői kód beállítása, cseréje vagy törlése (a teszt is ezt hívja).
     *
     * @return array{ok: bool, uzenet: string}
     */
    public static function zar_feldolgoz(string $regi, string $uj, string $ujra, bool $torol): array
    {
        if (!self::kod_jo($regi)) {
            return ['ok' => false, 'uzenet' => 'A jelenlegi telepítői kód nem jó.'];
        }

        if ($torol) {
            delete_option(self::OPT_ZAR);

            return ['ok' => true, 'uzenet' => 'A telepítői zár feloldva: a modulokat kód nélkül lehet kapcsolni.'];
        }

        if (mb_strlen($uj) < 6) {
            return ['ok' => false, 'uzenet' => 'Az új kód legalább 6 karakter legyen.'];
        }

        if ($uj !== $ujra) {
            return ['ok' => false, 'uzenet' => 'A két új kód nem egyezik.'];
        }

        update_option(self::OPT_ZAR, wp_hash_password($uj), false);

        return ['ok' => true, 'uzenet' => 'A telepítői zár beállítva: a modulok ezentúl csak ezzel a kóddal kapcsolhatók.'];
    }

    public static function zar_mentes(): void
    {
        SDH_Muhely_Admin_UI::fejleszto_jog_ellenoriz();
        check_admin_referer('sdh_muhely_modulok_zar', 'sdh_nonce');

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- fent ellenőrizve.
        $e = self::zar_feldolgoz(
            isset($_POST['regi']) ? (string) wp_unslash($_POST['regi']) : '',
            isset($_POST['uj']) ? (string) wp_unslash($_POST['uj']) : '',
            isset($_POST['ujra']) ? (string) wp_unslash($_POST['ujra']) : '',
            !empty($_POST['torol'])
        );
        // phpcs:enable

        set_transient(self::ATMENETI . get_current_user_id(), ['tipus' => $e['ok'] ? 'siker' : 'hiba', 'szoveg' => $e['uzenet']], 120);

        wp_safe_redirect(self::vissza());
        exit;
    }

    /** Vissza a Modulok oldalra – azon a felületen, ahonnan az űrlap jött. */
    private static function vissza(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a hívó ellenőrizte.
        $kontextus = isset($_POST['kontextus']) && sanitize_key(wp_unslash((string) $_POST['kontextus'])) === 'frontend' ? 'frontend' : 'admin';

        return SDH_Muhely_Modulok::visszateres(self::KULCS, ['mentve' => time()], $kontextus);
    }

    /* =================================================================
     * Az oldal
     * ============================================================== */

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::fejleszto_jog_ellenoriz();

        $katalogus = SDH_Muhely_Modulok::katalogus();
        $ki        = SDH_Muhely_Modulok::kikapcsoltak();
        $zarolva   = self::zarolva();
        $fut       = count(array_filter(array_keys($katalogus), [SDH_Muhely_Modulok::class, 'be']));

        $csoportok = [];

        foreach ($katalogus as $kulcs => $m) {
            $csoportok[(string) $m['csoport']][$kulcs] = $m;
        }

        $uzenet = get_transient(self::ATMENETI . get_current_user_id());

        if (is_array($uzenet)) {
            delete_transient(self::ATMENETI . get_current_user_id());
        }

        ?>
        <div class="sdh-wrap sdh-wrap--teljes sdh-modulok" data-sdh-modulok>
            <?php
            SDH_Muhely_Admin_UI::fejlec(
                'Modulok',
                'A rendszer fő funkciói modulonként ki- és bekapcsolhatók. A kikapcsolt modul eltűnik a menüből, az Áttekintésről és a Beállításokból – az adatai megmaradnak, visszakapcsolva ott folytatódik minden, ahol abbamaradt.'
            );
            ?>

            <?php if (is_array($uzenet)) : ?>
                <div class="sdh-uzenet sdh-uzenet--<?php echo $uzenet['tipus'] === 'hiba' ? 'hiba' : 'siker'; ?>" role="status"><?php echo esc_html((string) $uzenet['szoveg']); ?></div>
            <?php endif; ?>

            <form class="sdh-urlap sdh-modulok__urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_modulok">
                <input type="hidden" name="kontextus" value="<?php echo esc_attr(SDH_Muhely_Modulok::kontextus()); ?>">
                <?php wp_nonce_field('sdh_muhely_modulok', 'sdh_nonce'); ?>

                <div class="sdh-modulok__osszegzo">
                    <span class="sdh-modulok__szam"><strong><?php echo (int) $fut; ?></strong> / <?php echo (int) count($katalogus); ?></span>
                    <span class="sdh-modulok__szam-cimke">fő modul fut<?php echo $zarolva ? ' · telepítői zár bekapcsolva' : ''; ?></span>
                </div>

                <?php foreach ($csoportok as $csoport => $elemek) : ?>
                    <section class="sdh-modulok__csoport">
                        <h2 class="sdh-modulok__csoportcim"><?php echo esc_html($csoport); ?></h2>
                        <div class="sdh-modulok__racs">
                            <?php foreach ($elemek as $kulcs => $m) : ?>
                                <?php
                                $sajat_be = !in_array($kulcs, $ki, true);
                                $fut_e    = SDH_Muhely_Modulok::be($kulcs);
                                $fuggok   = array_map(static fn ($a): string => (string) ($katalogus[$a]['cim'] ?? $a), (array) ($m['fugg'] ?? []));
                                $azon     = 'sdh-modul-' . $kulcs;
                                ?>
                                <label class="sdh-modulkartya<?php echo $fut_e ? ' is-be' : ''; ?><?php echo $sajat_be && !$fut_e ? ' is-var' : ''; ?>" for="<?php echo esc_attr($azon); ?>" data-sdh-modulkartya="<?php echo esc_attr($kulcs); ?>">
                                    <span class="sdh-modulkartya__fej">
                                        <span class="sdh-modulkartya__ikon"><?php echo SDH_Muhely_Frontend::ikon((string) ($m['ikon'] ?? $kulcs)); // phpcs:ignore WordPress.Security.EscapeOutput -- rögzített SVG. ?></span>
                                        <span class="sdh-modulkartya__cim"><?php echo esc_html((string) $m['cim']); ?></span>
                                        <span class="sdh-kapcsolo">
                                            <input type="checkbox" name="be[]" id="<?php echo esc_attr($azon); ?>" value="<?php echo esc_attr($kulcs); ?>" <?php checked($sajat_be); ?>>
                                            <span class="sdh-kapcsolo__csuszka" aria-hidden="true"></span>
                                            <span class="sdh-kapcsolo__felirat"><?php echo esc_html((string) $m['cim']); ?> bekapcsolva</span>
                                        </span>
                                    </span>
                                    <span class="sdh-modulkartya__leiras"><?php echo esc_html((string) $m['leiras']); ?></span>
                                    <span class="sdh-modulkartya__lab">
                                        <span class="sdh-modulkartya__allapot"><?php echo $fut_e ? 'Fut' : ($sajat_be ? 'Nem fut – ki van kapcsolva, amire épül' : 'Kikapcsolva'); ?></span>
                                        <?php if ($fuggok !== []) : ?>
                                            <span class="sdh-modulkartya__fugg">Ehhez kell: <?php echo esc_html(implode(', ', $fuggok)); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <section class="sdh-modulok__csoport">
                    <h2 class="sdh-modulok__csoportcim">Alaprendszer – mindig fut</h2>
                    <div class="sdh-modulok__racs sdh-modulok__racs--alap">
                        <?php foreach (SDH_Muhely_Modulok::alapreszek() as $kulcs => $a) : ?>
                            <div class="sdh-modulkartya sdh-modulkartya--alap is-be">
                                <span class="sdh-modulkartya__fej">
                                    <span class="sdh-modulkartya__ikon"><?php echo SDH_Muhely_Frontend::ikon($kulcs === 'mentes' ? 'mentes' : $kulcs); // phpcs:ignore WordPress.Security.EscapeOutput -- rögzített SVG. ?></span>
                                    <span class="sdh-modulkartya__cim"><?php echo esc_html($a['cim']); ?></span>
                                    <span class="sdh-cimke">Alap</span>
                                </span>
                                <span class="sdh-modulkartya__leiras"><?php echo esc_html($a['leiras']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="sdh-urlap__lablec sdh-modulok__lablec">
                    <?php if ($zarolva) : ?>
                        <span class="sdh-ig sdh-modulok__kod">
                            <label for="sdh-modulok-kod">Telepítői kód</label>
                            <input type="password" name="kod" id="sdh-modulok-kod" autocomplete="off" required>
                        </span>
                    <?php endif; ?>
                    <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Mentés</button>
                    <span class="sdh-mezo__sugo">Mentés után az oldal újratölt, és a menü már az új modulokkal jelenik meg.</span>
                </div>
            </form>

            <form class="sdh-urlap sdh-doboz sdh-modulok__zar" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_modulok_zar">
                <input type="hidden" name="kontextus" value="<?php echo esc_attr(SDH_Muhely_Modulok::kontextus()); ?>">
                <?php wp_nonce_field('sdh_muhely_modulok_zar', 'sdh_nonce'); ?>

                <h2 class="sdh-doboz__cim">Telepítői zár</h2>
                <p class="sdh-sugo">
                    <?php if ($zarolva) : ?>
                        A zár be van kapcsolva: a modulok csak a telepítői kóddal kapcsolhatók át. Itt cserélheted vagy oldhatod fel – mindkettőhöz a mostani kód kell.
                    <?php else : ?>
                        Ha a rendszert más cégnél telepíted, itt adhatsz meg egy kódot. Utána a modulok csak ezzel kapcsolhatók át – a cég saját adminisztrátora sem kapcsol be magának olyan modult, amit nem rendelt meg.
                    <?php endif; ?>
                </p>

                <div class="sdh-sor">
                    <?php if ($zarolva) : ?>
                        <div class="sdh-ig">
                            <label for="sdh-zar-regi">Mostani kód</label>
                            <input type="password" name="regi" id="sdh-zar-regi" autocomplete="off">
                        </div>
                    <?php endif; ?>
                    <div class="sdh-ig">
                        <label for="sdh-zar-uj">Új kód</label>
                        <input type="password" name="uj" id="sdh-zar-uj" autocomplete="new-password" minlength="6">
                    </div>
                    <div class="sdh-ig">
                        <label for="sdh-zar-ujra">Új kód még egyszer</label>
                        <input type="password" name="ujra" id="sdh-zar-ujra" autocomplete="new-password" minlength="6">
                    </div>
                </div>

                <div class="sdh-urlap__lablec">
                    <button type="submit" class="sdh-gomb"><?php echo $zarolva ? 'Kód cseréje' : 'Zár bekapcsolása'; ?></button>
                    <?php if ($zarolva) : ?>
                        <button type="submit" class="sdh-gomb sdh-gomb--vilagos" name="torol" value="1">Zár feloldása</button>
                    <?php endif; ?>
                    <span class="sdh-mezo__sugo">Szervezési zár, nem kódvédelem: aki a szerver fájljaihoz hozzáfér, kikerülheti.</span>
                </div>
            </form>
        </div>
        <?php
    }
}
