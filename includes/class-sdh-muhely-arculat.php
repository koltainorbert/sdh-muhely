<?php
/**
 * Arculat – stíluskalauz és alapértelmezett megjelenés.
 *
 * Két dolgot ad. Egyrészt egy élő kalauzt: minden szín és minden
 * komponens egy helyen, a valódi stíluslapról. Ha a felületet
 * átszínezzük, itt látszik először, mit rontottunk el – nem három hét
 * múlva, egy munkalapon.
 *
 * Másrészt a cég alapértelmezett megjelenését. A dolgozók a saját
 * gépükön felülírhatják a profilmenüben, de aki még nem állított
 * semmit, az ezt kapja. Új szerviznél ez az egyetlen hely, ahol a
 * rendszer színét be kell állítani.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Arculat
{
    public const KULCS = 'arculat';

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'      => self::KULCS,
            'cim'        => 'Arculat',
            'render'     => [self::class, 'oldal'],
            'sorrend'    => 92,
            'csak_admin' => true,
        ]);

        add_action('admin_post_sdh_muhely_arculat', [self::class, 'mentes']);
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /**
     * @return array{tema: string, szin: string}
     */
    public static function beallitas(): array
    {
        $mentett = get_option('sdh_muhely_arculat', []);

        return [
            'tema' => (string) ($mentett['tema'] ?? 'rendszer'),
            'szin' => (string) ($mentett['szin'] ?? ''),
        ];
    }

    /**
     * A választható kiemelő színek. A kulcs a data-accent értéke;
     * az üres kulcs az alapértelmezett SDH piros.
     *
     * @return array<string, array{nev: string, hex: string}>
     */
    public static function szinek(): array
    {
        return [
            ''            => ['nev' => 'SDH piros', 'hex' => '#d4231d'],
            'kek'         => ['nev' => 'Kék', 'hex' => '#2563eb'],
            'smaragd'     => ['nev' => 'Smaragd', 'hex' => '#047857'],
            'ibolya'      => ['nev' => 'Ibolya', 'hex' => '#6d28d9'],
            'borostyan'   => ['nev' => 'Borostyán', 'hex' => '#b45309'],
            'palaszurke'  => ['nev' => 'Palaszürke', 'hex' => '#475569'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function temak(): array
    {
        return [
            'rendszer' => 'A gép beállítása szerint',
            'vilagos'  => 'Mindig világos',
            'sotet'    => 'Mindig sötét',
        ];
    }

    /* =================================================================
     * Képernyő
     * ============================================================== */

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        $b = self::beallitas();

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Arculat',
                'A rendszer színei és komponensei egy helyen. Itt látszik először, ha valami elromlik.',
                [
                    [
                        'cimke' => 'Megnyitás a műhely-felületen ↗',
                        'url'   => SDH_Muhely_Modulok::frontend_url(),
                    ],
                ]
            );
            ?>

            <form class="sdh-urlap" method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_arculat">
                <?php wp_nonce_field('sdh_muhely_arculat', 'sdh_nonce'); ?>

                <div class="sdh-doboz">
                    <h2 class="sdh-doboz__cim">Alapértelmezett megjelenés</h2>

                    <p class="sdh-sugo">
                        Ezt kapja mindenki, aki a saját gépén még nem állított be mást.
                        A dolgozók a profilmenüben felülírhatják – az a beállítás náluk
                        marad, és ez nem írja felül.
                    </p>

                    <div class="sdh-mezok">
                        <div class="sdh-mezo">
                            <label for="tema">Világos vagy sötét</label>
                            <select name="tema" id="tema">
                                <?php foreach (self::temak() as $kulcs => $cimke) : ?>
                                    <option value="<?php echo esc_attr($kulcs); ?>"
                                        <?php selected($b['tema'], $kulcs); ?>>
                                        <?php echo esc_html($cimke); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sdh-mezo">
                            <label for="szin">Kiemelő szín</label>
                            <select name="szin" id="szin">
                                <?php foreach (self::szinek() as $kulcs => $szin) : ?>
                                    <option value="<?php echo esc_attr($kulcs); ?>"
                                        <?php selected($b['szin'], $kulcs); ?>>
                                        <?php echo esc_html($szin['nev']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="sdh-urlap__lablec">
                    <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Mentés</button>
                </div>
            </form>

            <?php self::kalauz(); ?>
        </div>
        <?php
    }

    /* =================================================================
     * Stíluskalauz
     * ============================================================== */

    private static function kalauz(): void
    {
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Színek</h2>

            <p class="sdh-sugo">
                Minden szín CSS-változó az <code>assets/admin.css</code> tetején.
                Átszínezéshez egyetlen fájlt kell módosítani – a PHP-ban és a
                JavaScriptben nincs beégetett szín. A „Kiemelő szöveg” sötét módban
                világosabb a gombszínnél, különben a linkek olvashatatlanok lennének.
            </p>

            <div class="sdh-paletta">
                <?php
                $mintak = [
                    'Kiemelő'        => '--sdh-accent',
                    'Kiemelő sötét'  => '--sdh-accent-sotet',
                    'Kiemelő szöveg' => '--sdh-accent-szoveg',
                    'Vászon'         => '--sdh-vaszon',
                    'Felület'        => '--sdh-felulet',
                    'Felület 2'      => '--sdh-felulet-2',
                    'Keret'          => '--sdh-keret',
                    'Keret erős'     => '--sdh-keret-eros',
                    'Szöveg'         => '--sdh-szoveg',
                    'Szöveg 2'       => '--sdh-szoveg-2',
                    'Halvány'        => '--sdh-halvany',
                    'Siker'          => '--sdh-siker',
                    'Hiba'           => '--sdh-hiba',
                    'Figyelem'       => '--sdh-figyelem',
                ];

                foreach ($mintak as $nev => $valtozo) :
                    ?>
                    <div class="sdh-paletta__elem">
                        <span class="sdh-paletta__folt"
                              style="background:var(<?php echo esc_attr($valtozo); ?>)"></span>
                        <strong><?php echo esc_html($nev); ?></strong>
                        <code><?php echo esc_html($valtozo); ?></code>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Gombok és címkék</h2>

            <div class="sdh-urlap__lablec--tordel">
                <button type="button" class="sdh-gomb sdh-gomb--elsodleges">Elsődleges</button>
                <button type="button" class="sdh-gomb">Másodlagos</button>
                <button type="button" class="sdh-gomb" disabled>Letiltva</button>
                <span class="sdh-cimke">Címke</span>
                <span class="sdh-cimke sdh-cimke--inaktiv">inaktív</span>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Értesítések</h2>

            <div class="sdh-uzenet sdh-uzenet--siker">Elmentve.</div>
            <div class="sdh-uzenet sdh-uzenet--figyelem">
                Ez a 15 számjegy nem ad ki érvényes IMEI-t – nézd meg, nem gépelted-e el.
            </div>
            <div class="sdh-uzenet sdh-uzenet--hiba">
                A mentés nem sikerült. Az adatbázis visszautasította a műveletet.
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Űrlapmezők</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="kalauz-szoveg">Szövegmező</label>
                    <input type="text" id="kalauz-szoveg" value="SM-A505F/DS">
                    <span class="sdh-mezo__sugo">A mező alatti súgó ilyen.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="kalauz-lista">Legördülő</label>
                    <select id="kalauz-lista">
                        <option>Telefon</option>
                        <option>Tablet</option>
                    </select>
                </div>

                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" id="kalauz-jelolo" checked>
                    <label for="kalauz-jelolo">Jelölőnégyzet</label>
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Táblázat</h2>

            <table class="sdh-tabla">
                <thead>
                    <tr><th>Készülék</th><th>Ügyfél</th><th>IMEI</th><th>Garancia</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="sdh-tabla__nev">Samsung SM-A505F/DS
                            <div class="sdh-tabla__halvany">Galaxy A50</div></td>
                        <td>Minta Ügyfél</td>
                        <td class="sdh-tabla__halvany">357088105701076</td>
                        <td><span class="sdh-tabla__halvany">—</span></td>
                    </tr>
                    <tr>
                        <td class="sdh-tabla__nev">Apple A1660
                            <div class="sdh-tabla__halvany">iPhone 7</div></td>
                        <td>Másik Ügyfél</td>
                        <td class="sdh-tabla__halvany">F17XK2QWHG7F</td>
                        <td><span class="sdh-cimke">garanciális</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* =================================================================
     * Mentés
     * ============================================================== */

    public static function mentes(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_arculat', 'sdh_nonce');

        $tema = isset($_POST['tema']) ? sanitize_key(wp_unslash($_POST['tema'])) : 'rendszer';
        $szin = isset($_POST['szin']) ? sanitize_key(wp_unslash($_POST['szin'])) : '';

        update_option(
            'sdh_muhely_arculat',
            [
                'tema' => isset(self::temak()[$tema]) ? $tema : 'rendszer',
                'szin' => isset(self::szinek()[$szin]) ? $szin : '',
            ]
        );

        wp_safe_redirect(SDH_Muhely_Modulok::admin_url(self::KULCS, ['uzenet' => 'mentve']));
        exit;
    }
}
