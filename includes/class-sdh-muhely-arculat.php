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
        // Nincs saját menüpontja: a Beállítások „Logó és megjelenés" lapfüle
        // (SDH_Muhely_Beallitasok) rajzolja és menti.
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /**
     * @return array{logo: string, jel: string, nev: string, szin_egyeni: string, sarok: string, sav: string, tema: string, szin: string, neon: string, neon_egyeni: string, neon_ero: string}
     */
    public static function beallitas(): array
    {
        $mentett = get_option('sdh_muhely_arculat', []);
        $mentett = is_array($mentett) ? $mentett : [];

        $neon = (string) ($mentett['neon'] ?? 'kiemelo');
        $ero  = (string) ($mentett['neon_ero'] ?? 'kozepes');

        $sarok = (string) ($mentett['sarok'] ?? 'normal');
        $sav   = (string) ($mentett['sav'] ?? 'vilagos');
        $egyeni = self::hex((string) ($mentett['szin_egyeni'] ?? ''));
        $szin   = (string) ($mentett['szin'] ?? '');

        // Egyéni kiemelő szín csak érvényes színkóddal él; különben az alapszín.
        if ($szin === 'egyeni' && $egyeni === '') {
            $szin = '';
        }

        return [
            // A rendszer jele és neve (oldalsáv, címsor) és a logó – cégenként más, ezért beállítás.
            'logo'        => esc_url_raw((string) ($mentett['logo'] ?? '')),
            'jel'         => isset($mentett['jel']) ? (string) $mentett['jel'] : 'SDH',
            'nev'         => isset($mentett['nev']) ? (string) $mentett['nev'] : 'Műhely',
            'szin_egyeni' => $egyeni,
            'sarok'       => isset(self::sarkok()[$sarok]) ? $sarok : 'normal',
            'sav'         => isset(self::savok()[$sav]) ? $sav : 'vilagos',
            'tema'        => (string) ($mentett['tema'] ?? 'rendszer'),
            'szin'        => $szin,
            // A popupok neon kerete: melyik szín világít (üres = kikapcsolva).
            'neon'        => isset(self::neonok()[$neon]) ? $neon : 'kiemelo',
            'neon_egyeni' => self::hex((string) ($mentett['neon_egyeni'] ?? '')),
            'neon_ero'    => isset(self::neon_erok()[$ero]) ? $ero : 'kozepes',
        ];
    }

    /**
     * A popupok neon keretének választható színei. A színek maguk a CSS-ben
     * vannak (`--sdh-popneon-<kulcs>` az assets/admin.css-ben); a PHP csak a
     * nevüket ismeri. Az üres kulcs: nincs neon keret. A `kiemelo` a
     * mindenkori kiemelő színt követi, az `egyeni` a megadott színkódot.
     *
     * @return array<string, string>
     */
    public static function neonok(): array
    {
        return [
            ''        => 'Kikapcsolva',
            'kiemelo' => 'A kiemelő szín',
            'cian'    => 'Cián',
            'kek'     => 'Elektromos kék',
            'lila'    => 'Lila',
            'magenta' => 'Magenta',
            'narancs' => 'Narancs',
            'lime'    => 'Lime',
            'egyeni'  => 'Egyéni szín',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function neon_erok(): array
    {
        return [
            'finom'   => 'Finom',
            'kozepes' => 'Közepes',
            'eros'    => 'Erős',
        ];
    }

    /**
     * A lekerekítés fokozatai: kulcs => [név, kis sugár, nagy sugár].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function sarkok(): array
    {
        return [
            'szogletes' => ['Szögletes', '3px', '4px'],
            'normal'    => ['Normál', '8px', '12px'],
            'kerek'     => ['Kerek', '13px', '20px'],
        ];
    }

    /**
     * Az oldalsáv (műhely-felület) stílusai.
     *
     * @return array<string, string>
     */
    public static function savok(): array
    {
        return [
            'vilagos' => 'Világos (a felület színe)',
            'sotet'   => 'Sötét',
            'szines'  => 'A kiemelő színnel kitöltve',
        ];
    }

    /** A rendszer teljes neve: jel + név (pl. „SDH Műhely") – címsor, morzsa, menü. */
    public static function rendszer_nev(): string
    {
        $b   = self::beallitas();
        $nev = trim($b['jel'] . ' ' . $b['nev']);

        return $nev !== '' ? $nev : 'Műhely';
    }

    /** A színkód három összetevője (0–255). */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /** A szín keverése feketével (negatív arány) vagy fehérrel (pozitív), -1…1. */
    private static function arnyalat(string $hex, float $arany): string
    {
        $cel = $arany < 0 ? 0 : 255;
        $a   = min(1.0, abs($arany));

        return sprintf('#%02x%02x%02x', ...array_map(static fn (int $c): int => (int) round($c + ($cel - $c) * $a), self::rgb($hex)));
    }

    /**
     * A beállításból adódó stílus: az egyéni kiemelő szín változói és a
     * lekerekítés. Mindkét felület (műhely és wp-admin) a saját stíluslapja
     * után kapja meg. Üres, ha minden az alapértelmezés.
     */
    public static function stilus_css(): string
    {
        $b   = self::beallitas();
        $css = '';

        if ($b['szin_egyeni'] !== '') {
            $hex       = $b['szin_egyeni'];
            [$r, $g, $k] = self::rgb($hex);
            // Világos kiemelő színen a fehér felirat olvashatatlan: ott sötét a felirat.
            $vilagos   = (0.299 * $r + 0.587 * $g + 0.114 * $k) > 160;

            $css .= ':root[data-accent="egyeni"]{--sdh-accent:' . $hex
                . ';--sdh-accent-sotet:' . self::arnyalat($hex, -0.18)
                . ';--sdh-accent-halvany:rgba(' . $r . ',' . $g . ',' . $k . ',.1)'
                . ';--sdh-accent-felirat:' . ($vilagos ? '#16191d' : '#ffffff')
                // Világos kiemelő szín szövegként fehér háttéren halvány volna: sötétebb árnyalat áll a helyén.
                . ($vilagos ? ';--sdh-accent-szoveg:' . self::arnyalat($hex, -0.45) : '') . '}'
                . ':root[data-theme="dark"][data-accent="egyeni"]{--sdh-accent-szoveg:' . self::arnyalat($hex, $vilagos ? 0.1 : 0.4) . '}';
        }

        if ($b['sarok'] !== 'normal') {
            $s    = self::sarkok()[$b['sarok']];
            $css .= ':root{--sdh-r:' . $s[1] . ';--sdh-r-nagy:' . $s[2] . '}';
        }

        return $css;
    }

    /** Érvényes #rrggbb színkód, vagy üres sztring. */
    private static function hex(string $ertek): string
    {
        $ertek = strtolower(trim($ertek));

        if ($ertek !== '' && $ertek[0] !== '#') {
            $ertek = '#' . $ertek;
        }

        return preg_match('/^#[0-9a-f]{6}$/', $ertek) === 1 ? $ertek : '';
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
            ''            => ['nev' => 'Piros (alap)', 'hex' => '#d4231d'],
            'kek'         => ['nev' => 'Kék', 'hex' => '#2563eb'],
            'smaragd'     => ['nev' => 'Smaragd', 'hex' => '#047857'],
            'ibolya'      => ['nev' => 'Ibolya', 'hex' => '#6d28d9'],
            'borostyan'   => ['nev' => 'Borostyán', 'hex' => '#b45309'],
            'palaszurke'  => ['nev' => 'Palaszürke', 'hex' => '#475569'],
            // A cég saját színe: a színkódja az Arculat képernyőn adható meg.
            'egyeni'      => ['nev' => 'Egyéni szín', 'hex' => ''],
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

    /** Az arculat dobozai a Beállítások „Logó és megjelenés" lapfülén (űrlap nélkül: azt a Beállítások adja). */
    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();

        ?>
                <div class="sdh-doboz" id="logo">
                    <h2 class="sdh-doboz__cim">Logó és név</h2>

                    <p class="sdh-sugo">
                        Ez látszik a műhely-felület bal felső sarkában, a böngészőfül címében és a menüben.
                        Ha töltesz fel logót, az áll a színes jel helyén; logó nélkül a jel (néhány betű) látszik.
                        A név a logó / jel mellett áll – üresen hagyva csak a logó marad.
                    </p>

                    <div class="sdh-mezok">
                        <div class="sdh-mezo sdh-mezo--szeles">
                            <label for="arculat_logo">Logó</label>
                            <div class="sdh-rma-media" data-sdh-media="image">
                                <input type="text" name="arculat_logo" id="arculat_logo"
                                       value="<?php echo esc_attr($b['logo']); ?>" placeholder="https://… – vagy töltsd fel a gombbal">
                                <button type="button" class="sdh-gomb" data-sdh-media-valaszt>Feltöltés…</button>
                                <button type="button" class="sdh-gomb" data-sdh-media-torol<?php echo $b['logo'] === '' ? ' hidden' : ''; ?>>Törlés</button>
                                <span class="sdh-rma-media__elo sdh-arculat-logo__elo" data-sdh-media-elo>
                                    <?php if ($b['logo'] !== '') : ?>
                                        <img src="<?php echo esc_url($b['logo']); ?>" alt="">
                                    <?php endif; ?>
                                </span>
                            </div>
                            <span class="sdh-mezo__sugo">Átlátszó hátterű PNG vagy SVG a legjobb; 28 képpont magasan jelenik meg, a szélessége legfeljebb 150 képpont.</span>
                        </div>

                        <div class="sdh-mezo">
                            <label for="arculat_jel">Jel (logó nélkül ez látszik)</label>
                            <input type="text" name="arculat_jel" id="arculat_jel" maxlength="4" autocomplete="off"
                                   value="<?php echo esc_attr($b['jel']); ?>">
                            <span class="sdh-mezo__sugo">Legfeljebb 4 betű.</span>
                        </div>

                        <div class="sdh-mezo">
                            <label for="arculat_nev">Név</label>
                            <input type="text" name="arculat_nev" id="arculat_nev" maxlength="30" autocomplete="off"
                                   value="<?php echo esc_attr($b['nev']); ?>">
                            <span class="sdh-mezo__sugo">A rendszer neve most: <strong><?php echo esc_html(self::rendszer_nev()); ?></strong></span>
                        </div>
                    </div>
                </div>

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

                        <div class="sdh-mezo">
                            <label for="arculat_szin_egyeni">Egyéni kiemelő szín</label>
                            <div class="sdh-rma-szin sdh-arculat-szin">
                                <input type="color" id="arculat_szin_valaszto" aria-label="Színválasztó"
                                       value="<?php echo esc_attr($b['szin_egyeni'] !== '' ? $b['szin_egyeni'] : '#d4231d'); ?>"
                                       data-sdh-szin-cel="arculat_szin_egyeni">
                                <input type="text" name="arculat_szin_egyeni" id="arculat_szin_egyeni" maxlength="7"
                                       pattern="#?[0-9a-fA-F]{6}" placeholder="#0a84ff" autocomplete="off"
                                       value="<?php echo esc_attr($b['szin_egyeni']); ?>">
                            </div>
                            <span class="sdh-mezo__sugo">A cég saját színe. A fenti listában az „Egyéni szín”-t választva lesz ez a kiemelő szín.</span>
                        </div>

                        <div class="sdh-mezo">
                            <label for="arculat_sarok">Sarkok lekerekítése</label>
                            <select name="arculat_sarok" id="arculat_sarok">
                                <?php foreach (self::sarkok() as $kulcs => $s) : ?>
                                    <option value="<?php echo esc_attr($kulcs); ?>" <?php selected($b['sarok'], $kulcs); ?>><?php echo esc_html($s[0]); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sdh-mezo">
                            <label for="arculat_sav">Oldalsáv (menü) a műhely-felületen</label>
                            <select name="arculat_sav" id="arculat_sav">
                                <?php foreach (self::savok() as $kulcs => $cimke) : ?>
                                    <option value="<?php echo esc_attr($kulcs); ?>" <?php selected($b['sav'], $kulcs); ?>><?php echo esc_html($cimke); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="sdh-doboz">
                    <h2 class="sdh-doboz__cim">Popupok – neon keret</h2>

                    <p class="sdh-sugo">
                        Minden felugró ablak (munkalap, ügyfél, eszköz, választók) körül világító
                        keret: ebből látszik azonnal, melyik ablak az aktív. Itt állítod a cég
                        alapértelmezését; a dolgozók a profilmenüben a saját gépükön mást is
                        választhatnak. Az egyéni szín hatjegyű színkód, pl. <code>#00e5ff</code>.
                    </p>

                    <div class="sdh-mezok">
                        <div class="sdh-mezo">
                            <label for="neon">Kijelölő (neon) szín</label>
                            <select name="neon" id="neon" data-sdh-neon-valaszto>
                                <?php foreach (self::neonok() as $kulcs => $cimke) : ?>
                                    <option value="<?php echo esc_attr($kulcs); ?>"
                                        <?php selected($b['neon'], $kulcs); ?>>
                                        <?php echo esc_html($cimke); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sdh-mezo">
                            <label for="neon_egyeni">Egyéni színkód</label>
                            <input type="text" name="neon_egyeni" id="neon_egyeni" maxlength="7"
                                   pattern="#?[0-9a-fA-F]{6}" placeholder="#00e5ff" autocomplete="off"
                                   data-sdh-neon-egyeni
                                   value="<?php echo esc_attr($b['neon_egyeni']); ?>">
                            <span class="sdh-mezo__sugo">Csak az „Egyéni szín” választásnál számít.</span>
                        </div>

                        <div class="sdh-mezo">
                            <label for="neon_ero">Fényerő</label>
                            <select name="neon_ero" id="neon_ero" data-sdh-neon-ero-valaszto>
                                <?php foreach (self::neon_erok() as $kulcs => $cimke) : ?>
                                    <option value="<?php echo esc_attr($kulcs); ?>"
                                        <?php selected($b['neon_ero'], $kulcs); ?>>
                                        <?php echo esc_html($cimke); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="sdh-neon-minta" aria-hidden="true">
                        <div class="sdh-modal__doboz sdh-neon-minta__ablak">
                            <span class="sdh-neon-minta__gomb sdh-neon-minta__gomb--piros"></span>
                            <span class="sdh-neon-minta__gomb sdh-neon-minta__gomb--zold"></span>
                            <strong>Így néz ki egy popup</strong>
                            <span>A keret színe és fényereje mentés előtt is itt látszik.</span>
                        </div>
                    </div>
                </div>

        <?php
    }

    /* =================================================================
     * Stíluskalauz
     * ============================================================== */

    public static function kalauz(): void
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

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (!isset($_POST['tema'])) {
            return;
        }

        $tema = isset($_POST['tema']) ? sanitize_key(wp_unslash($_POST['tema'])) : 'rendszer';
        $szin = isset($_POST['szin']) ? sanitize_key(wp_unslash($_POST['szin'])) : '';
        $neon = isset($_POST['neon']) ? sanitize_key(wp_unslash($_POST['neon'])) : '';
        $ero  = isset($_POST['neon_ero']) ? sanitize_key(wp_unslash($_POST['neon_ero'])) : 'kozepes';
        $hex  = self::hex(isset($_POST['neon_egyeni']) ? sanitize_text_field(wp_unslash($_POST['neon_egyeni'])) : '');

        // Egyéni szín érvényes színkód nélkül nem világíthat: akkor a kiemelő szín marad.
        if ($neon === 'egyeni' && $hex === '') {
            $neon = 'kiemelo';
        }

        $szin_egyeni = self::hex(isset($_POST['arculat_szin_egyeni']) ? sanitize_text_field(wp_unslash($_POST['arculat_szin_egyeni'])) : '');
        $sarok       = isset($_POST['arculat_sarok']) ? sanitize_key(wp_unslash($_POST['arculat_sarok'])) : 'normal';
        $sav         = isset($_POST['arculat_sav']) ? sanitize_key(wp_unslash($_POST['arculat_sav'])) : 'vilagos';

        // Egyéni kiemelő szín csak érvényes színkóddal választható.
        if ($szin === 'egyeni' && $szin_egyeni === '') {
            $szin = '';
        }

        update_option(
            'sdh_muhely_arculat',
            [
                'logo'        => isset($_POST['arculat_logo']) ? esc_url_raw(trim((string) wp_unslash($_POST['arculat_logo']))) : '',
                'jel'         => mb_substr(sanitize_text_field(isset($_POST['arculat_jel']) ? wp_unslash($_POST['arculat_jel']) : ''), 0, 4),
                'nev'         => mb_substr(sanitize_text_field(isset($_POST['arculat_nev']) ? wp_unslash($_POST['arculat_nev']) : ''), 0, 30),
                'szin_egyeni' => $szin_egyeni,
                'sarok'       => isset(self::sarkok()[$sarok]) ? $sarok : 'normal',
                'sav'         => isset(self::savok()[$sav]) ? $sav : 'vilagos',
                'tema'        => isset(self::temak()[$tema]) ? $tema : 'rendszer',
                'szin'        => isset(self::szinek()[$szin]) ? $szin : '',
                'neon'        => isset(self::neonok()[$neon]) ? $neon : 'kiemelo',
                'neon_egyeni' => $hex,
                'neon_ero'    => isset(self::neon_erok()[$ero]) ? $ero : 'kozepes',
            ]
        );
        // phpcs:enable
    }
}
