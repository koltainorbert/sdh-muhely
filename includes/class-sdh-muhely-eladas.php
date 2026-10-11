<?php
/**
 * Eladás: napi árukiadás és rendelések.
 *
 * Mindkettő ugyanaz a lap, mint a munkalap (ugyanaz a tábla, a "fajta" oszlop
 * különbözteti meg), csak javítás nélkül: nincs eszköz, nincs hiba, nincs
 * bevizsgálási díj. Ezért a fizetés (előleggel is), a számla, a pénztár és az
 * RMA változatlanul működik rajtuk – az űrlapot a SDH_Muhely_Munkalap rajzolja,
 * ez az osztály azt adja hozzá, ami az eladásé:
 *
 *  - Napi árukiadás: pultos eladás (termék és / vagy szolgáltatás). A vevő
 *    alapból „Napi árukiadás" (ügyfél nélkül), de választható meglévő ügyfél
 *    vagy felvihető új. Több üzletnél helyszínhez kötve; egy üzletnél a
 *    helyszínválasztó meg sem jelenik. Nincs RMA.
 *  - Rendelések: az ügyfélnek rendelt áru. Mindig ügyfélhez kötve, a forrás
 *    (beszállító) szerkeszthető listából, a rendelés útja külön állapotban
 *    (felvéve → megrendelve → megérkezett → ügyfél értesítve → átadva). Van
 *    RMA: ha megjött az áru, egy gombbal megy az értesítés az ügyfélnek.
 *
 * A helyszínek és a beszállítók listája popupban szerkeszthető; semmilyen
 * cégadat nincs a kódban.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Eladas
{
    public const KULCS_ARU  = 'arukiadas';
    public const KULCS_REND = 'rendelesek';

    /** A vevő neve, ha az árukiadáshoz nem tartozik ügyfél. */
    public const NEVTELEN = 'Napi árukiadás';

    private const OPT          = 'sdh_muhely_eladas';
    private const OPT_MINTA    = 'sdh_muhely_beszallito_minta';
    private const OLDAL_MERET  = 25;
    private const META_HELYSZIN = 'sdh_muhely_helyszin';

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS_ARU,
            'cim'     => 'Napi árukiadás',
            'render'  => [self::class, 'oldal_arukiadas'],
            'sorrend' => 31,
        ]);

        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS_REND,
            'cim'     => 'Rendelések',
            'render'  => [self::class, 'oldal_rendelesek'],
            'sorrend' => 31,
            'jelveny' => [self::class, 'erkezett_db'],
        ]);

        // Az új / meglévő lap űrlapja – ugyanaz, mint a munkalapé, a saját fajtájával.
        add_action('wp_ajax_sdh_muhely_' . self::KULCS_ARU . '_urlap', static function (): void {
            SDH_Muhely_Munkalap::ajax_urlap_fajta('arukiadas');
        });
        add_action('wp_ajax_sdh_muhely_' . self::KULCS_REND . '_urlap', static function (): void {
            SDH_Muhely_Munkalap::ajax_urlap_fajta('rendeles');
        });

        // Törzsek (helyszínek, beszállítók): szerkesztés popupban.
        foreach (array_keys(self::torzsek()) as $torzs) {
            add_action('wp_ajax_sdh_muhely_' . $torzs . '_urlap', static function () use ($torzs): void {
                self::ajax_torzs_urlap($torzs);
            });
            add_action('wp_ajax_sdh_muhely_' . $torzs . '_ment', static function () use ($torzs): void {
                self::ajax_torzs_ment($torzs);
            });
        }

        // Rendelés: „megérkezett" értesítés az ügyfélnek.
        add_action('wp_ajax_sdh_muhely_rendelesertesit_urlap', [self::class, 'ajax_ertesit_urlap']);
        add_action('wp_ajax_sdh_muhely_rendelesertesit_ment', [self::class, 'ajax_ertesit_ment']);

        // A kifizetett árukiadás / átadott és kifizetett rendelés magától lezárul.
        add_action('sdh_muhely_munkalap_fizetes', [self::class, 'lezar_ha_kesz']);
        add_action('sdh_muhely_munkalap_mentve', [self::class, 'lezar_ha_kesz']);

        // Sémafrissítés után egyszer: néhány mintabeszállító, hogy a lista ne üresen induljon.
        add_action('sdh_muhely_sema_frissult', [self::class, 'mintak'], 30);

        add_action('admin_enqueue_scripts', [self::class, 'admin_eszkozok'], 20);
    }

    public static function admin_eszkozok(): void
    {
        if (!wp_script_is('sdh-muhely-app', 'enqueued')) {
            return;
        }

        wp_enqueue_script(
            'sdh-muhely-eladas',
            SDH_MUHELY_URL . 'assets/eladas.js',
            ['sdh-muhely-app'],
            SDH_Muhely_Admin_UI::eszkoz_verzio('assets/eladas.js'),
            true
        );
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /**
     * @return array{ertesito: string, auto_lezar: bool}
     */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        $ertesito = isset($m['ertesito']) ? trim((string) $m['ertesito']) : '';

        return [
            'ertesito'   => $ertesito !== '' ? $ertesito : self::alap_ertesito(),
            'auto_lezar' => !isset($m['auto_lezar']) || !empty($m['auto_lezar']),
        ];
    }

    /** Az értesítő alapszövege. Jelölők: {nev}, {sorszam}, {tetelek}, {szerviz}. */
    public static function alap_ertesito(): string
    {
        return "Kedves {nev}!\n\nMegérkezett a megrendelt áru ({tetelek}), átvehető nálunk. A rendelés száma: {sorszam}.\n\nÜdvözlettel: {szerviz}";
    }

    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();

        ?>
        <div class="sdh-doboz" id="eladas">
            <h2 class="sdh-doboz__cim">Napi árukiadás és rendelések</h2>
            <p class="sdh-sugo">
                A <strong>helyszínek</strong> az üzletek, ahol árut adtok ki. Egy üzletnél nem kell felvenni semmit
                (vagy elég egy): ilyenkor a helyszínválasztó meg sem jelenik az árukiadáson. A
                <strong>beszállítók</strong> a rendelések forrásai – a rendelés űrlapjáról is szerkeszthetők.
            </p>

            <div class="sdh-eladas-torzsgombok">
                <a class="sdh-gomb" href="#" data-sdh-urlap="helyszinek" data-sdh-id="0">Helyszínek szerkesztése (<?php echo (int) count(self::torzs_sorok('helyszinek', true)); ?>)</a>
                <a class="sdh-gomb" href="#" data-sdh-urlap="beszallitok" data-sdh-id="0">Beszállítók szerkesztése (<?php echo (int) count(self::torzs_sorok('beszallitok', true)); ?>)</a>
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="hidden" name="eladas_jelen" value="1">
                <input type="checkbox" name="eladas_auto_lezar" id="eladas_auto_lezar" value="1" <?php checked($b['auto_lezar']); ?>>
                <label for="eladas_auto_lezar">A kifizetett napi árukiadás és az átadott, kifizetett rendelés magától lezárul</label>
            </div>

            <div class="sdh-mezo sdh-mezo--szeles">
                <label for="eladas_ertesito">Értesítő szövege, ha megérkezett a rendelt áru</label>
                <textarea name="eladas_ertesito" id="eladas_ertesito" rows="5"><?php echo esc_textarea($b['ertesito']); ?></textarea>
                <span class="sdh-mezo__sugo">
                    Küldés előtt a rendelésnél még átírható. Jelölők: <code>{nev}</code> az ügyfél neve, <code>{sorszam}</code> a rendelés száma,
                    <code>{tetelek}</code> a rendelt tételek, <code>{szerviz}</code> a cég neve (RMA-beállítás). Üresen hagyva az alapszöveg jön vissza.
                </span>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['eladas_jelen'])) {
            return;
        }

        update_option(self::OPT, [
            'ertesito'   => isset($_POST['eladas_ertesito']) ? mb_substr(sanitize_textarea_field(wp_unslash($_POST['eladas_ertesito'])), 0, 1500) : '',
            'auto_lezar' => !empty($_POST['eladas_auto_lezar']),
        ], false);
        // phpcs:enable
    }

    /* =================================================================
     * Törzsek: helyszínek és beszállítók
     * ============================================================== */

    /**
     * A két szerkeszthető lista leírása: tábla, cím, mezők (név => [felirat, hossz, helyőrző]).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function torzsek(): array
    {
        return [
            'helyszinek' => [
                'tabla'  => 'helyszin',
                'cim'    => 'Helyszínek',
                'egy'    => 'helyszín',
                'sugo'   => 'Az üzletek, boltok, ahol árut adtok ki. Egy üzletnél a lista üresen is hagyható. Törölni nem lehet (a régi eladások hivatkoznak rá) – ami megszűnt, annál vedd ki az „Aktív" pipát.',
                'mezok'  => [
                    'nev'        => ['Név', 120, 'pl. Belvárosi üzlet'],
                    'cim'        => ['Cím', 255, 'irányítószám, település, utca'],
                    'telefon'    => ['Telefon', 40, ''],
                    'megjegyzes' => ['Megjegyzés', 255, ''],
                ],
                'alap'   => true,
                'admin'  => true,
                'modul'  => self::KULCS_ARU,
            ],
            'beszallitok' => [
                'tabla'  => 'beszallito',
                'cim'    => 'Beszállítók',
                'egy'    => 'beszállító',
                'sugo'   => 'Honnan rendelitek az árut. Törölni nem lehet (a régi rendelések hivatkoznak rá) – akitől már nem rendeltek, annál vedd ki az „Aktív" pipát.',
                'mezok'  => [
                    'nev'        => ['Név', 120, 'pl. a nagyker neve'],
                    'weboldal'   => ['Weboldal', 255, 'https://…'],
                    'telefon'    => ['Telefon', 40, ''],
                    'email'      => ['E-mail', 190, ''],
                    'megjegyzes' => ['Megjegyzés', 255, 'pl. kapcsolattartó, ügyfélszám'],
                ],
                'alap'   => false,
                'admin'  => false,
                'modul'  => self::KULCS_REND,
            ],
        ];
    }

    /**
     * Egy törzs sorai, sorrendben.
     *
     * @return array<int, object>
     */
    public static function torzs_sorok(string $torzs, bool $csak_aktiv = false): array
    {
        global $wpdb;

        $t = self::torzsek()[$torzs] ?? null;

        if ($t === null) {
            return [];
        }

        $tabla = SDH_Muhely_Schema::tabla((string) $t['tabla']);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a táblanév saját.
        $sorok = $wpdb->get_results("SELECT * FROM {$tabla}" . ($csak_aktiv ? ' WHERE aktiv = 1' : '') . ' ORDER BY aktiv DESC, sorrend ASC, id ASC');

        return is_array($sorok) ? $sorok : [];
    }

    /** @return array<int, string> azonosító => név */
    public static function torzs_nevek(string $torzs, bool $csak_aktiv = false): array
    {
        $ki = [];

        foreach (self::torzs_sorok($torzs, $csak_aktiv) as $sor) {
            $ki[(int) $sor->id] = (string) $sor->nev;
        }

        return $ki;
    }

    /** A törzset szerkesztheti-e a belépett felhasználó. */
    private static function torzs_jog(array $t): bool
    {
        return current_user_can(SDH_Muhely_Admin_UI::jog()) && (empty($t['admin']) || SDH_Muhely_Admin_UI::admin_e());
    }

    /** A mintabeszállítók egyszeri felvétele üres listába (a telepítő futtatja). */
    public static function mintak(): void
    {
        global $wpdb;

        if (get_option(self::OPT_MINTA)) {
            return;
        }

        update_option(self::OPT_MINTA, 1, false);

        $tabla = SDH_Muhely_Schema::tabla('beszallito');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$tabla}") > 0) {
            return;
        }

        foreach (['Sini', 'CHS', 'Gigatel'] as $i => $nev) {
            $wpdb->insert($tabla, [
                'nev'        => $nev,
                'aktiv'      => 1,
                'sorrend'    => ($i + 1) * 10,
                'megjegyzes' => 'Minta – átírható',
                'letrehozva' => current_time('mysql'),
            ]);
        }
    }

    public static function ajax_torzs_urlap(string $torzs): void
    {
        check_ajax_referer('sdh_muhely_modal');

        $t = self::torzsek()[$torzs] ?? null;

        if ($t === null || !self::torzs_jog($t)) {
            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">' . esc_html(!empty($t['admin']) ? 'Ezt a listát csak adminisztrátor szerkesztheti.' : 'Nincs jogosultságod ehhez.') . '</div>';
            wp_die();
        }

        $sorok = self::torzs_sorok($torzs);
        $mezok = (array) $t['mezok'];

        ?>
        <h2 class="sdh-modal__cim"><?php echo esc_html((string) $t['cim']); ?></h2>

        <form class="sdh-urlap sdh-torzs" method="post" data-sdh-ajax-action="sdh_muhely_<?php echo esc_attr($torzs); ?>_ment">
            <?php wp_nonce_field('sdh_muhely_torzs_' . $torzs, 'sdh_nonce'); ?>
            <input type="hidden" name="kontextus" value="<?php echo esc_attr(isset($_REQUEST['kontextus']) && sanitize_key(wp_unslash($_REQUEST['kontextus'])) === 'frontend' ? 'frontend' : 'admin'); ?>">

            <p class="sdh-sugo"><?php echo esc_html((string) $t['sugo']); ?></p>

            <div class="sdh-torzs__tabla" style="--sdh-torzs-oszlop: <?php echo (int) count($mezok); ?>">
                <div class="sdh-torzs__fej" aria-hidden="true">
                    <?php foreach ($mezok as $m) : ?>
                        <span><?php echo esc_html((string) $m[0]); ?></span>
                    <?php endforeach; ?>
                    <?php if (!empty($t['alap'])) : ?>
                        <span title="Az új árukiadás ezzel a helyszínnel indul">Alap</span>
                    <?php endif; ?>
                    <span>Aktív</span>
                </div>

                <div class="sdh-torzs__sorok" data-sdh-torzs-sorok data-kovetkezo="<?php echo (int) count($sorok); ?>">
                    <?php foreach ($sorok as $i => $sor) : ?>
                        <?php self::torzs_sor((string) $i, $sor, $t); ?>
                    <?php endforeach; ?>
                    <?php if ($sorok === []) : ?>
                        <?php self::torzs_sor('0', null, $t); ?>
                    <?php endif; ?>
                </div>

                <template data-sdh-torzs-sablon><?php self::torzs_sor('__I__', null, $t); ?></template>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Mentés</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-torzs-uj>
                    <span class="sdh-plusz" aria-hidden="true"></span>Új <?php echo esc_html((string) $t['egy']); ?>
                </button>
                <span class="sdh-mezo__sugo">Az üresen hagyott nevű új sor nem mentődik.</span>
            </div>
        </form>
        <?php

        wp_die();
    }

    /** A törzs-szerkesztő egy sora. */
    private static function torzs_sor(string $index, ?object $sor, array $t): void
    {
        $elotag = 'sorok[' . $index . ']';
        $aktiv  = $sor === null || (int) $sor->aktiv === 1;

        ?>
        <div class="sdh-torzs__sor<?php echo $aktiv ? '' : ' is-inaktiv'; ?>" data-sdh-torzs-sor>
            <input type="hidden" name="<?php echo esc_attr($elotag); ?>[id]" value="<?php echo (int) ($sor->id ?? 0); ?>">
            <?php foreach ((array) $t['mezok'] as $nev => $m) : ?>
                <input type="text" name="<?php echo esc_attr($elotag . '[' . $nev . ']'); ?>"
                       maxlength="<?php echo (int) $m[1]; ?>" autocomplete="off"
                       aria-label="<?php echo esc_attr((string) $m[0]); ?>" placeholder="<?php echo esc_attr((string) $m[2]); ?>"
                       value="<?php echo esc_attr((string) ($sor->{$nev} ?? '')); ?>">
            <?php endforeach; ?>
            <?php if (!empty($t['alap'])) : ?>
                <label class="sdh-torzs__jelolo" title="Az új árukiadás ezzel a helyszínnel indul">
                    <input type="radio" name="alap" value="<?php echo esc_attr($index); ?>" aria-label="Alapértelmezett"
                        <?php checked($sor !== null && (int) ($sor->alap ?? 0) === 1); ?>>
                </label>
            <?php endif; ?>
            <label class="sdh-torzs__jelolo">
                <input type="hidden" name="<?php echo esc_attr($elotag); ?>[aktiv]" value="0">
                <input type="checkbox" name="<?php echo esc_attr($elotag); ?>[aktiv]" value="1" aria-label="Aktív" <?php checked($aktiv); ?>>
            </label>
        </div>
        <?php
    }

    public static function ajax_torzs_ment(string $torzs): void
    {
        global $wpdb;

        check_ajax_referer('sdh_muhely_torzs_' . $torzs, 'sdh_nonce');

        $t = self::torzsek()[$torzs] ?? null;

        if ($t === null || !self::torzs_jog($t)) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $tabla  = SDH_Muhely_Schema::tabla((string) $t['tabla']);
        $be     = isset($_POST['sorok']) && is_array($_POST['sorok']) ? wp_unslash($_POST['sorok']) : [];
        $alap   = isset($_POST['alap']) ? (string) wp_unslash($_POST['alap']) : '';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $letezo = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$tabla}"));
        $n      = 0;

        foreach ($be as $index => $sor) {
            if (!is_array($sor)) {
                continue;
            }

            $id   = isset($sor['id']) ? (int) $sor['id'] : 0;
            $adat = [];

            foreach ((array) $t['mezok'] as $nev => $m) {
                $adat[$nev] = mb_substr(sanitize_text_field((string) ($sor[$nev] ?? '')), 0, (int) $m[1]);
            }

            // Új sor név nélkül: nem kell. Meglévő sor neve nem törölhető ki.
            if ($adat['nev'] === '') {
                if ($id <= 0 || !in_array($id, $letezo, true)) {
                    continue;
                }

                unset($adat['nev']);
            }

            if (isset($adat['email']) && $adat['email'] !== '' && !is_email($adat['email'])) {
                wp_send_json_error(['uzenet' => 'Érvénytelen e-mail cím: ' . $adat['email']]);
            }

            $n++;
            $adat['aktiv']   = !empty($sor['aktiv']) ? 1 : 0;
            $adat['sorrend'] = $n * 10;

            if (!empty($t['alap'])) {
                $adat['alap'] = ((string) $index === $alap && $adat['aktiv'] === 1) ? 1 : 0;
            }

            if ($id > 0 && in_array($id, $letezo, true)) {
                $wpdb->update($tabla, $adat, ['id' => $id]);
            } else {
                $adat['letrehozva'] = current_time('mysql');
                $wpdb->insert($tabla, $adat);
            }
        }

        $kontextus = isset($_POST['kontextus']) && sanitize_key(wp_unslash($_POST['kontextus'])) === 'frontend' ? 'frontend' : 'admin';

        wp_send_json_success([
            'torzs'  => $torzs,
            'lista'  => self::torzs_nevek($torzs, true),
            'uzenet' => $t['cim'] . ' elmentve.',
            // A hívó oldal újratöltése (ha nem a rendelés űrlapjáról nyitották).
            'vissza' => isset($_SERVER['HTTP_REFERER']) && wp_validate_redirect(wp_unslash($_SERVER['HTTP_REFERER']), '') !== ''
                ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']))
                : SDH_Muhely_Modulok::visszateres((string) $t['modul'], [], $kontextus),
        ]);
    }

    /* =================================================================
     * A rendelés útja
     * ============================================================== */

    /**
     * @return array<string, array{nev: string, szin: string}>
     */
    public static function rend_allapotok(): array
    {
        return [
            'felveve'     => ['nev' => 'Felvéve', 'szin' => 'sarga'],
            'megrendelve' => ['nev' => 'Megrendelve', 'szin' => 'kek'],
            'megerkezett' => ['nev' => 'Megérkezett', 'szin' => 'narancs'],
            'ertesitve'   => ['nev' => 'Ügyfél értesítve', 'szin' => 'lila'],
            'atadva'      => ['nev' => 'Átadva', 'szin' => 'zold'],
        ];
    }

    public static function rend_jelveny(string $kulcs): string
    {
        $a = self::rend_allapotok()[$kulcs] ?? ['nev' => $kulcs !== '' ? $kulcs : '—', 'szin' => 'szurke'];

        return '<span class="sdh-allapot sdh-allapot--' . esc_attr($a['szin']) . '">' . esc_html($a['nev']) . '</span>';
    }

    /** Hány rendelés érkezett meg úgy, hogy az ügyfél még nem tud róla (a menü jelvénye). */
    public static function erkezett_db(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('munkalap') . " WHERE fajta = 'rendeles' AND rend_allapot = 'megerkezett'"
        );
    }

    /** A még át nem adott (nyitott) rendelések száma – az Áttekintés csempéje. */
    public static function nyitott_rendeles_db(): int
    {
        global $wpdb;

        $zart = SDH_Muhely_Munkalap::zart_kulcsok(SDH_Muhely_Munkalap::allapotok());
        $sql  = 'SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('munkalap') . " WHERE fajta = 'rendeles' AND rend_allapot <> 'atadva'";

        if ($zart !== []) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- csak helyőrzők kerülnek bele.
            return (int) $wpdb->get_var($wpdb->prepare($sql . ' AND allapot NOT IN (' . implode(',', array_fill(0, count($zart), '%s')) . ')', $zart));
        }

        return (int) $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** A mai árukiadások száma – az Áttekintés csempéje. */
    public static function mai_arukiadas_db(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('munkalap') . " WHERE fajta = 'arukiadas' AND keszult = %s",
            current_time('Y-m-d')
        ));
    }

    /* =================================================================
     * Az űrlap saját mezői (a munkalap űrlapjába illesztve)
     * ============================================================== */

    /** Az eszközválasztó helyén: helyszín (árukiadás) vagy beszállító (rendelés). */
    public static function urlap_mezok(?object $munkalap, string $fajta): void
    {
        if ($fajta === 'arukiadas') {
            $helyszinek = self::torzs_nevek('helyszinek', true);
            $mentett    = (int) ($munkalap->helyszin_id ?? 0);

            // A régi lap helyszíne akkor is látszódjon, ha már nem aktív.
            if ($mentett > 0 && !isset($helyszinek[$mentett])) {
                $osszes = self::torzs_nevek('helyszinek');

                if (isset($osszes[$mentett])) {
                    $helyszinek[$mentett] = $osszes[$mentett];
                }
            }

            $valasztott = $munkalap !== null ? $mentett : self::alap_helyszin($helyszinek);

            // Egy üzletes cég: nincs mit választani – a mező rejtve megy.
            if (count($helyszinek) < 2) {
                echo '<div class="sdh-ig sdh-eladas-ures"><input type="hidden" name="helyszin_id" value="' . (int) ($valasztott ?: (int) (array_key_first($helyszinek) ?? 0)) . '"></div>';

                return;
            }

            ?>
            <div class="sdh-ig">
                <label for="helyszin_id">Helyszín</label>
                <select name="helyszin_id" id="helyszin_id">
                    <?php foreach ($helyszinek as $id => $nev) : ?>
                        <option value="<?php echo (int) $id; ?>" <?php selected($valasztott, $id); ?>><?php echo esc_html($nev); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php

            return;
        }

        $beszallitok = self::torzs_nevek('beszallitok', true);
        $mentett     = (int) ($munkalap->beszallito_id ?? 0);

        if ($mentett > 0 && !isset($beszallitok[$mentett])) {
            $osszes = self::torzs_nevek('beszallitok');

            if (isset($osszes[$mentett])) {
                $beszallitok[$mentett] = $osszes[$mentett];
            }
        }

        ?>
        <div class="sdh-ig">
            <label for="beszallito_id" title="Honnan van a rendelés">Honnan</label>
            <div class="sdh-mezo__sor">
                <select name="beszallito_id" id="beszallito_id" data-sdh-beszallito-valaszto>
                    <option value="0">— válassz beszállítót —</option>
                    <?php foreach ($beszallitok as $id => $nev) : ?>
                        <option value="<?php echo (int) $id; ?>" <?php selected($mentett, $id); ?>><?php echo esc_html($nev); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--plusz" data-sdh-beszallitok
                        title="Beszállítók szerkesztése, új felvétele" aria-label="Beszállítók szerkesztése, új felvétele"><span class="sdh-plusz" aria-hidden="true"></span></button>
            </div>
        </div>
        <?php
    }

    /** Az új árukiadás helyszíne: amit a kolléga legutóbb használt, különben az alapértelmezett. */
    private static function alap_helyszin(array $helyszinek): int
    {
        $utolso = (int) get_user_meta(get_current_user_id(), self::META_HELYSZIN, true);

        if ($utolso > 0 && isset($helyszinek[$utolso])) {
            return $utolso;
        }

        foreach (self::torzs_sorok('helyszinek', true) as $sor) {
            if ((int) $sor->alap === 1) {
                return (int) $sor->id;
            }
        }

        return (int) (array_key_first($helyszinek) ?? 0);
    }

    /** A rendelés saját sora az űrlapon: a rendelés útja, a beszállítói rendelésszám, az értesítés. */
    public static function urlap_rendeles_sor(?object $munkalap): void
    {
        $allapot = (string) ($munkalap->rend_allapot ?? '');
        $allapot = isset(self::rend_allapotok()[$allapot]) ? $allapot : 'felveve';
        $ertesitve = (string) ($munkalap->rend_ertesitve ?? '');

        ?>
        <div class="sdh-sor sdh-sor--ketto sdh-eladas-rendeles">
            <div class="sdh-ig">
                <label for="rend_allapot" title="A rendelés útja: felvéve → megrendelve → megérkezett → ügyfél értesítve → átadva">Hol tart</label>
                <div class="sdh-mezo__sor">
                    <select name="rend_allapot" id="rend_allapot">
                        <?php foreach (self::rend_allapotok() as $kulcs => $a) : ?>
                            <option value="<?php echo esc_attr($kulcs); ?>" <?php selected($allapot, $kulcs); ?>><?php echo esc_html($a['nev']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($munkalap !== null && (int) $munkalap->ugyfel_id > 0 && (int) $munkalap->munkalap_szam > 0) : ?>
                        <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-eladas-ertesit" data-sdh-rendeles-ertesit="<?php echo (int) $munkalap->id; ?>"
                                title="Üzenet az ügyfélnek: megérkezett a rendelt áru. Az ügyféloldalon látja, és (ha van e-mail címe) e-mailben is megkapja.">
                            <?php echo $ertesitve !== '' ? 'Újra értesít' : 'Megjött – értesítés'; ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sdh-ig">
                <label for="rend_szam" title="A beszállító rendelés- vagy visszaigazolás-száma">Rendelésszám</label>
                <input type="text" name="rend_szam" id="rend_szam" maxlength="60" autocomplete="off"
                       placeholder="a beszállító rendelésszáma (nem kötelező)"
                       value="<?php echo esc_attr((string) ($munkalap->rend_szam ?? '')); ?>">
            </div>
            <?php if ($ertesitve !== '') : ?>
                <p class="sdh-eladas-rendeles__info">Az ügyfél értesítve: <?php echo esc_html(SDH_Muhely_Munkalap::datumido_megjelenit($ertesitve)); ?></p>
            <?php elseif ($munkalap === null) : ?>
                <p class="sdh-eladas-rendeles__info">Rögzítés után innen egy gombbal értesíthető az ügyfél, ha megjött az áru.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Az eladás saját mezői a beküldésből (SDH_Muhely_Munkalap::adatok_osszeallit hívja).
     *
     * @return array<string, mixed>
     */
    public static function bekuldott_mezok(string $fajta, ?object $regi): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a munkalap mentése ellenőrizte.
        if ($fajta === 'arukiadas') {
            $id = isset($_POST['helyszin_id']) ? max(0, (int) $_POST['helyszin_id']) : (int) ($regi->helyszin_id ?? 0);

            if ($id > 0 && !isset(self::torzs_nevek('helyszinek')[$id])) {
                $id = 0;
            }

            if ($id > 0 && $regi === null) {
                update_user_meta(get_current_user_id(), self::META_HELYSZIN, $id);
            }

            return ['helyszin_id' => $id];
        }

        $besz = isset($_POST['beszallito_id']) ? max(0, (int) $_POST['beszallito_id']) : (int) ($regi->beszallito_id ?? 0);

        if ($besz > 0 && !isset(self::torzs_nevek('beszallitok')[$besz])) {
            $besz = 0;
        }

        $allapot = isset($_POST['rend_allapot']) ? sanitize_key(wp_unslash($_POST['rend_allapot'])) : (string) ($regi->rend_allapot ?? '');

        return [
            'beszallito_id' => $besz,
            'rend_allapot'  => isset(self::rend_allapotok()[$allapot]) ? $allapot : 'felveve',
            'rend_szam'     => isset($_POST['rend_szam'])
                ? mb_substr(sanitize_text_field(wp_unslash($_POST['rend_szam'])), 0, 60)
                : (string) ($regi->rend_szam ?? ''),
        ];
        // phpcs:enable
    }

    /* =================================================================
     * Lezárás
     * ============================================================== */

    /**
     * A kifizetett napi árukiadás, illetve az átadott ÉS kifizetett rendelés
     * lezárt állapotba kerül (ha a beállítás kéri). Fizetés és mentés után fut.
     */
    public static function lezar_ha_kesz(int $id): void
    {
        global $wpdb;

        if (!self::beallitas()['auto_lezar']) {
            return;
        }

        $m = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $id));

        if (!$m || !SDH_Muhely_Munkalap::eladas_e($m) || (int) $m->fizetve !== 1) {
            return;
        }

        if ((string) $m->fajta === 'rendeles' && (string) $m->rend_allapot !== 'atadva') {
            return;
        }

        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $lezart    = SDH_Muhely_Munkalap::lezart_kulcs();

        if ($lezart === '' || !empty($allapotok[(string) $m->allapot]['zart'])) {
            return;
        }

        SDH_Muhely_Munkalap::allapot_valt($id, $lezart);
    }

    /* =================================================================
     * Rendelés: értesítés, ha megjött az áru
     * ============================================================== */

    private static function rendeles(int $id): ?object
    {
        global $wpdb;

        $m = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('munkalap') . " WHERE id = %d AND fajta = 'rendeles'", $id));

        return $m ?: null;
    }

    /** A rendelés tételeinek rövid felsorolása („2 db USB-C kábel, tok"). */
    public static function tetel_szoveg(int $munkalap_id, int $max = 6): string
    {
        $reszek = [];

        foreach (SDH_Muhely_Tetel::lista($munkalap_id) as $t) {
            if ((string) $t->megnevezes === '' || (float) $t->brutto_ertek < 0) {
                continue;
            }

            $menny    = (float) $t->mennyiseg;
            $reszek[] = ($menny > 1.0001 ? rtrim(rtrim(number_format($menny, 2, ',', ''), '0'), ',') . ' ' . ((string) $t->me !== '' ? (string) $t->me : 'db') . ' ' : '') . (string) $t->megnevezes;
        }

        $tobb = count($reszek) - $max;

        return implode(', ', array_slice($reszek, 0, $max)) . ($tobb > 0 ? ' és még ' . $tobb . ' tétel' : '');
    }

    /** Az értesítő szövege a rendelés adataival kitöltve. */
    public static function ertesito_szoveg(object $m): string
    {
        $rma     = SDH_Muhely_Rma::beallitas();
        $tetelek = self::tetel_szoveg((int) $m->id);

        return strtr(self::beallitas()['ertesito'], [
            '{nev}'     => SDH_Muhely_Ugyfel::nev((int) $m->ugyfel_id),
            '{sorszam}' => SDH_Muhely_Munkalap::szam_formaz($m->munkalap_szam),
            '{tetelek}' => $tetelek !== '' ? $tetelek : 'a rendelés',
            '{szerviz}' => (string) $rma['szerviz_nev'],
        ]);
    }

    public static function ajax_ertesit_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        $m = current_user_can(SDH_Muhely_Admin_UI::jog()) ? self::rendeles(isset($_GET['id']) ? (int) $_GET['id'] : 0) : null;

        if ($m === null || (int) $m->ugyfel_id <= 0) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Ehhez a rendeléshez nem küldhető értesítés (nincs ügyfele, vagy nem létezik).</div>';
            wp_die();
        }

        $ugyfel    = SDH_Muhely_Rma::ugyfel((int) $m->ugyfel_id);
        $van_email = $ugyfel && is_email((string) $ugyfel->email);

        ?>
        <h2 class="sdh-modal__cim">Értesítés: megérkezett a rendelés</h2>

        <form class="sdh-urlap sdh-eladas-ertesito" method="post" data-sdh-ajax-action="sdh_muhely_rendelesertesit_ment">
            <?php wp_nonce_field('sdh_muhely_rendelesertesit', 'sdh_nonce'); ?>
            <input type="hidden" name="id" value="<?php echo (int) $m->id; ?>">

            <p class="sdh-sugo">
                <strong><?php echo esc_html($ugyfel ? (string) $ugyfel->nev : ''); ?></strong><?php echo $ugyfel && (string) $ugyfel->telefon !== '' ? ' · ' . esc_html((string) $ugyfel->telefon) : ''; ?>
                – az üzenet a rendelés üzenetei közé kerül: az ügyféloldalon (QR-kód / link) látja, és válaszolhat rá.
            </p>

            <div class="sdh-mezo sdh-mezo--szeles">
                <label for="ertesito_szoveg">Üzenet</label>
                <textarea name="szoveg" id="ertesito_szoveg" rows="7" required maxlength="<?php echo (int) SDH_Muhely_Rma::UZENET_HOSSZ * 3; ?>"><?php echo esc_textarea(self::ertesito_szoveg($m)); ?></textarea>
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="email" id="ertesito_email" value="1" <?php checked($van_email); ?> <?php disabled(!$van_email); ?>>
                <label for="ertesito_email"><?php echo esc_html($van_email ? 'E-mailben is elküldöm: ' . $ugyfel->email : 'E-mail nem megy: az ügyfélnek nincs e-mail címe – telefonon is szólj neki'); ?></label>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Értesítés küldése</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                <span class="sdh-mezo__sugo">A rendelés állapota „Ügyfél értesítve" lesz.</span>
            </div>
        </form>
        <?php

        wp_die();
    }

    public static function ajax_ertesit_ment(): void
    {
        global $wpdb;

        check_ajax_referer('sdh_muhely_rendelesertesit', 'sdh_nonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $m      = self::rendeles(isset($_POST['id']) ? (int) $_POST['id'] : 0);
        $szoveg = isset($_POST['szoveg']) ? trim(sanitize_textarea_field(wp_unslash($_POST['szoveg']))) : '';

        if ($m === null || (int) $m->ugyfel_id <= 0) {
            wp_send_json_error(['uzenet' => 'Ehhez a rendeléshez nem küldhető értesítés.']);
        }

        if ($szoveg === '') {
            wp_send_json_error(['uzenet' => 'Írd be az üzenetet.']);
        }

        $uzenet_id = SDH_Muhely_Rma::uzenet_ment(
            (int) $m->id,
            (int) $m->ugyfel_id,
            'ki',
            'crm',
            mb_substr($szoveg, 0, SDH_Muhely_Rma::UZENET_HOSSZ * 3),
            get_current_user_id(),
            !empty($_POST['email'])
        );

        if ($uzenet_id <= 0) {
            wp_send_json_error(['uzenet' => 'Az adatbázis visszautasította az üzenetet.']);
        }

        // Az átadott rendelés állapota marad; minden korábbi „Ügyfél értesítve" lesz.
        $wpdb->update(SDH_Muhely_Schema::tabla('munkalap'), [
            'rend_allapot'   => (string) $m->rend_allapot === 'atadva' ? 'atadva' : 'ertesitve',
            'rend_ertesitve' => current_time('mysql'),
            'modositva'      => current_time('mysql'),
        ], ['id' => (int) $m->id]);

        $emailben = (int) $wpdb->get_var($wpdb->prepare('SELECT email_kuldve FROM ' . SDH_Muhely_Schema::tabla('uzenet') . ' WHERE id = %d', $uzenet_id)) === 1;

        wp_send_json_success([
            'id'     => (int) $m->id,
            'email'  => $emailben,
            'uzenet' => $emailben
                ? 'Értesítés elküldve: az ügyféloldalon látszik, és e-mailben is elment.'
                : (!empty($_POST['email'])
                    ? 'Az értesítés az ügyféloldalra került, de az e-mail nem ment el (ellenőrizd a levelezés beállítását) – telefonon is szólj.'
                    : 'Az értesítés az ügyféloldalra került. E-mail nem ment – telefonon is szólj az ügyfélnek.'),
        ]);
    }

    /* =================================================================
     * Ügyféloldal (RMA): a rendelés fejléce a Termékek fül tetején
     * ============================================================== */

    public static function rma_fej(object $munkalap, ?object $ugyfel): void
    {
        $i = ['hu' => 0, 'en' => 1, 'de' => 2][SDH_Muhely_Rma_Oldal::nyelv()] ?? 0;
        $t = static fn (array $szovegek): string => (string) ($szovegek[$i] ?? $szovegek[0]);

        echo '<dl class="rma-adat">';

        if ($ugyfel) {
            printf('<div class="rma-adat__sor"><dt>%s</dt><dd>%s</dd></div>', esc_html($t(['Ügyfél', 'Customer', 'Kunde'])), esc_html((string) $ugyfel->nev));
        }

        if ((string) $munkalap->fajta === 'rendeles') {
            $nevek = [
                'felveve'     => ['Felvéve', 'Recorded', 'Erfasst'],
                'megrendelve' => ['Megrendelve', 'Ordered', 'Bestellt'],
                'megerkezett' => ['Megérkezett – átvehető', 'Arrived – ready for pickup', 'Eingetroffen – abholbereit'],
                'ertesitve'   => ['Megérkezett – átvehető', 'Arrived – ready for pickup', 'Eingetroffen – abholbereit'],
                'atadva'      => ['Átadva', 'Handed over', 'Übergeben'],
            ];
            $kulcs = (string) $munkalap->rend_allapot;
            $a     = self::rend_allapotok()[$kulcs] ?? ['szin' => 'szurke'];

            printf(
                '<div class="rma-adat__sor"><dt>%s</dt><dd><span class="sdh-allapot sdh-allapot--%s">%s</span></dd></div>',
                esc_html($t(['Rendelés állapota', 'Order status', 'Bestellstatus'])),
                esc_attr((string) $a['szin']),
                esc_html($t($nevek[$kulcs] ?? [$kulcs]))
            );

            $varhato = (string) ($munkalap->hatarido ?? '');

            if ($varhato !== '' && !str_starts_with($varhato, '0000') && in_array($kulcs, ['felveve', 'megrendelve'], true)) {
                printf(
                    '<div class="rma-adat__sor"><dt>%s</dt><dd>%s</dd></div>',
                    esc_html($t(['Várható érkezés', 'Expected arrival', 'Voraussichtliche Ankunft'])),
                    esc_html(SDH_Muhely_Rma_Oldal::ido($varhato, false))
                );
            }
        }

        echo '</dl>';
    }

    /* =================================================================
     * Listák
     * ============================================================== */

    public static function oldal_arukiadas(): void
    {
        self::lista('arukiadas');
    }

    public static function oldal_rendelesek(): void
    {
        self::lista('rendeles');
    }

    private static function penz(float $osszeg): string
    {
        return number_format($osszeg, 0, ',', ' ') . ' Ft';
    }

    /**
     * A tételek rövid felsorolása laponként, egy lekérdezéssel.
     *
     * @param array<int, int> $idk
     * @return array<int, string>
     */
    private static function tetel_osszefoglalok(array $idk): array
    {
        global $wpdb;

        if ($idk === []) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- csak egész számok.
        $sorok = $wpdb->get_results(
            'SELECT munkalap_id, megnevezes, mennyiseg FROM ' . SDH_Muhely_Schema::tabla('munkalap_tetel')
            . ' WHERE munkalap_id IN (' . implode(',', array_map('intval', $idk)) . ") AND megnevezes <> '' ORDER BY munkalap_id, sorrend, id"
        );

        $ki = [];
        $db = [];

        foreach ((array) $sorok as $s) {
            $id      = (int) $s->munkalap_id;
            $db[$id] = ($db[$id] ?? 0) + 1;

            if ($db[$id] <= 3) {
                $menny   = (float) $s->mennyiseg;
                $ki[$id] = (isset($ki[$id]) ? $ki[$id] . ', ' : '') . ($menny > 1.0001 ? rtrim(rtrim(number_format($menny, 2, ',', ''), '0'), ',') . '× ' : '') . (string) $s->megnevezes;
            }
        }

        foreach ($db as $id => $n) {
            if ($n > 3) {
                $ki[$id] .= ' +' . ($n - 3);
            }
        }

        return $ki;
    }

    private static function lista(string $fajta): void
    {
        global $wpdb;

        $aru    = $fajta === 'arukiadas';
        $modul  = $aru ? self::KULCS_ARU : self::KULCS_REND;
        $cim    = $aru ? 'Napi árukiadás' : 'Rendelések';

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- csak szűrés.
        $kereses = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
        $szuro   = isset($_GET['szuro']) ? sanitize_key(wp_unslash($_GET['szuro'])) : '';
        $torzs   = isset($_GET['torzs']) ? max(0, (int) $_GET['torzs']) : 0;
        $oldal   = isset($_GET['oldalszam']) ? max(1, (int) $_GET['oldalszam']) : 1;
        // phpcs:enable

        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $zart      = SDH_Muhely_Munkalap::zart_kulcsok($allapotok);
        $rend      = self::rend_allapotok();
        $nevek     = self::torzs_nevek($aru ? 'helyszinek' : 'beszallitok');
        $ma        = current_time('Y-m-d');

        $feltetelek = ['m.fajta = %s'];
        $ertekek    = [$fajta];

        if ($aru) {
            // Időszak: ma (alap), 7 nap, 30 nap, mind, vagy a fizetetlenek.
            $szurok = ['' => 'Ma', 'het' => 'Utolsó 7 nap', 'honap' => 'Utolsó 30 nap', 'mind' => 'Mind', 'tartozas' => 'Nincs kifizetve'];
            $szuro  = isset($szurok[$szuro]) ? $szuro : '';

            // Keresésnél az időszak nem szűkít: a régi eladás is előkerül.
            if ($kereses === '') {
                if ($szuro === '') {
                    $feltetelek[] = 'm.keszult = %s';
                    $ertekek[]    = $ma;
                } elseif ($szuro === 'het' || $szuro === 'honap') {
                    $feltetelek[] = 'm.keszult >= %s';
                    $ertekek[]    = gmdate('Y-m-d', strtotime($ma . ($szuro === 'het' ? ' -6 days' : ' -29 days')));
                }
            }

            if ($szuro === 'tartozas') {
                $feltetelek[] = 'm.fizetve = 0 AND m.brutto_ertek - m.fizetett > 0.5';

                foreach ($allapotok as $kulcs => $a) {
                    if (!empty($a['ervenytelen'])) {
                        $feltetelek[] = 'm.allapot <> %s';
                        $ertekek[]    = (string) $kulcs;
                    }
                }
            }

            if ($torzs > 0) {
                $feltetelek[] = 'm.helyszin_id = %d';
                $ertekek[]    = $torzs;
            }
        } else {
            // Alap: a folyamatban lévő (át nem adott, nem lezárt) rendelések.
            $szurok = ['' => 'Folyamatban', 'mind' => 'Mind'];

            foreach ($rend as $kulcs => $a) {
                $szurok[$kulcs] = $a['nev'];
            }

            $szuro = isset($szurok[$szuro]) ? $szuro : '';

            if ($szuro === '' && $kereses === '') {
                $feltetelek[] = "m.rend_allapot <> 'atadva'";

                foreach ($zart as $kulcs) {
                    $feltetelek[] = 'm.allapot <> %s';
                    $ertekek[]    = $kulcs;
                }
            } elseif (isset($rend[$szuro])) {
                $feltetelek[] = 'm.rend_allapot = %s';
                $ertekek[]    = $szuro;
            }

            if ($torzs > 0) {
                $feltetelek[] = 'm.beszallito_id = %d';
                $ertekek[]    = $torzs;
            }
        }

        $tabla        = SDH_Muhely_Schema::tabla('munkalap');
        $ugyfel_tabla = SDH_Muhely_Schema::tabla('ugyfel');
        $tetel_tabla  = SDH_Muhely_Schema::tabla('munkalap_tetel');

        if ($kereses !== '') {
            $minta  = '%' . $wpdb->esc_like($kereses) . '%';
            $reszek = [
                'm.nev LIKE %s',
                'u.nev LIKE %s',
                'u.telefon LIKE %s',
                'm.rend_szam LIKE %s',
                "EXISTS (SELECT 1 FROM {$tetel_tabla} tt WHERE tt.munkalap_id = m.id AND tt.megnevezes LIKE %s)",
            ];
            $par    = array_fill(0, count($reszek), $minta);

            if (ctype_digit($kereses)) {
                array_unshift($reszek, 'm.munkalap_szam = %d');
                array_unshift($par, (int) $kereses);
            }

            $feltetelek[] = '(' . implode(' OR ', $reszek) . ')';
            $ertekek      = array_merge($ertekek, $par);
        }

        $hol  = 'WHERE ' . implode(' AND ', $feltetelek);
        $tobb = "FROM {$tabla} m LEFT JOIN {$ugyfel_tabla} u ON u.id = m.ugyfel_id";

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a $hol csak helyőrzőket és rögzített kifejezéseket tartalmaz.
        $ossz = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS db, COALESCE(SUM(m.brutto_ertek), 0) AS brutto, COALESCE(SUM(m.fizetett), 0) AS fizetett,
                    COALESCE(SUM(CASE WHEN m.fizetve = 1 THEN 0 ELSE m.brutto_ertek - m.fizetett END), 0) AS fizetendo
             {$tobb} {$hol}",
            $ertekek
        ));

        $osszesen = $ossz ? (int) $ossz->db : 0;
        $oldalak  = max(1, (int) ceil($osszesen / self::OLDAL_MERET));
        $oldal    = min($oldal, $oldalak);

        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.nev AS ugyfel_nev, u.telefon AS ugyfel_telefon
             {$tobb} {$hol}
             ORDER BY m.keszult DESC, m.munkalap_szam DESC, m.id DESC
             LIMIT %d OFFSET %d",
            array_merge($ertekek, [self::OLDAL_MERET, ($oldal - 1) * self::OLDAL_MERET])
        ));
        // phpcs:enable

        $sorok   = is_array($sorok) ? $sorok : [];
        $idk     = array_map(static fn (object $s): int => (int) $s->id, $sorok);
        $tetelek = self::tetel_osszefoglalok($idk);
        $tobb_helyszin = $aru && count(self::torzs_nevek('helyszinek', true)) > 1;

        $gombok = [[
            'cimke'      => $aru ? '+ Új árukiadás' : '+ Új rendelés',
            'url'        => '#',
            'elsodleges' => true,
            'adatok'     => ['sdh-urlap' => $modul, 'sdh-id' => '0'],
        ]];

        if (!$aru) {
            $gombok[] = ['cimke' => 'Beszállítók', 'url' => '#', 'adatok' => ['sdh-urlap' => 'beszallitok', 'sdh-id' => '0']];
        } elseif (SDH_Muhely_Admin_UI::admin_e()) {
            $gombok[] = ['cimke' => 'Helyszínek', 'url' => '#', 'adatok' => ['sdh-urlap' => 'helyszinek', 'sdh-id' => '0']];
        }

        $szurt = $kereses !== '' || $szuro !== '' || $torzs > 0;
        $oszlop_db = $aru ? ($tobb_helyszin ? 9 : 8) : 9;

        ?>
        <div class="sdh-wrap sdh-wrap--teljes sdh-eladas" data-sdh-eladas-lista="<?php echo esc_attr($modul); ?>">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                $cim,
                $aru
                    ? 'Pultos eladás javítás nélkül: termék és szolgáltatás, fizetéssel. Ügyfél nem kötelező.'
                    : 'Az ügyfeleknek rendelt áru: honnan jön, hol tart, ki van-e fizetve – és értesítés, ha megjött.',
                $gombok
            );
            ?>

            <div class="sdh-eladas__osszegzo">
                <div><span><?php echo esc_html($aru ? 'Eladás' : 'Rendelés'); ?></span><b><?php echo esc_html(number_format_i18n($osszesen)); ?> db</b></div>
                <div><span>Bruttó összesen</span><b><?php echo esc_html(self::penz($ossz ? (float) $ossz->brutto : 0)); ?></b></div>
                <div><span>Befolyt</span><b><?php echo esc_html(self::penz($ossz ? (float) $ossz->fizetett : 0)); ?></b></div>
                <div class="<?php echo $ossz && (float) $ossz->fizetendo > 0.5 ? 'is-tartozas' : ''; ?>"><span>Még fizetendő</span><b><?php echo esc_html(self::penz($ossz ? (float) $ossz->fizetendo : 0)); ?></b></div>
            </div>

            <form method="get" class="sdh-kereso" action="<?php echo esc_url(SDH_Muhely_Modulok::urlap_cel($modul)); ?>">
                <?php SDH_Muhely_Modulok::urlap_rejtett($modul); ?>

                <input type="search" name="k" value="<?php echo esc_attr($kereses); ?>"
                       placeholder="<?php echo esc_attr($aru ? 'Sorszám, vevő, telefon, termék…' : 'Sorszám, ügyfél, telefon, termék, rendelésszám…'); ?>">

                <select name="szuro" data-sdh-szuro aria-label="<?php echo esc_attr($aru ? 'Időszak' : 'A rendelés állapota'); ?>">
                    <?php foreach ($szurok as $kulcs => $nev) : ?>
                        <option value="<?php echo esc_attr((string) $kulcs); ?>" <?php selected($szuro, (string) $kulcs); ?>><?php echo esc_html($nev); ?></option>
                    <?php endforeach; ?>
                </select>

                <?php if ($aru ? $tobb_helyszin : $nevek !== []) : ?>
                    <select name="torzs" data-sdh-szuro aria-label="<?php echo esc_attr($aru ? 'Helyszín' : 'Beszállító'); ?>">
                        <option value="0"><?php echo esc_html($aru ? 'Minden helyszín' : 'Minden beszállító'); ?></option>
                        <?php foreach ($nevek as $id => $nev) : ?>
                            <option value="<?php echo (int) $id; ?>" <?php selected($torzs, $id); ?>><?php echo esc_html($nev); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Keresés</button>

                <?php if ($szurt) : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(SDH_Muhely_Modulok::url($modul)); ?>">Szűrő törlése</a>
                <?php endif; ?>

                <span class="sdh-kereso__talalat"><?php echo esc_html(number_format_i18n($osszesen)); ?> találat</span>
            </form>

            <table class="sdh-tabla sdh-eladas__tabla">
                <thead>
                    <tr>
                        <th>Szám</th>
                        <th><?php echo $aru ? 'Dátum' : 'Felvéve'; ?></th>
                        <th><?php echo $aru ? 'Vevő' : 'Ügyfél'; ?></th>
                        <th>Tételek</th>
                        <?php if ($aru && $tobb_helyszin) : ?>
                            <th class="sdh-tabla__rejtheto">Helyszín</th>
                        <?php endif; ?>
                        <?php if (!$aru) : ?>
                            <th>Honnan</th>
                            <th>Rendelés</th>
                        <?php endif; ?>
                        <th class="sdh-eladas__szam">Bruttó</th>
                        <th>Fizetés</th>
                        <?php if ($aru) : ?>
                            <th class="sdh-tabla__rejtheto">Állapot</th>
                        <?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($sorok === []) : ?>
                    <tr>
                        <td colspan="<?php echo (int) $oszlop_db; ?>" class="sdh-tabla__ures">
                            <?php if ($szurt) : ?>
                                Erre a szűrésre nincs találat.
                            <?php else : ?>
                                <?php echo esc_html($aru ? 'Ma még nem volt árukiadás.' : 'Nincs folyamatban lévő rendelés.'); ?>
                                <a href="#" data-sdh-urlap="<?php echo esc_attr($modul); ?>" data-sdh-id="0"><?php echo esc_html($aru ? 'Rögzítsd az elsőt.' : 'Vedd fel az elsőt.'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($sorok as $sor) : ?>
                        <?php
                        $id        = (int) $sor->id;
                        $allapot   = $allapotok[(string) $sor->allapot] ?? null;
                        $fizetve   = (int) $sor->fizetve === 1;
                        $fizetendo = $fizetve ? 0.0 : (float) $sor->brutto_ertek - (float) $sor->fizetett;
                        $lejart    = !$aru && (string) $sor->hatarido !== '' && (string) $sor->hatarido < $ma && in_array((string) $sor->rend_allapot, ['felveve', 'megrendelve'], true);
                        ?>
                        <tr class="<?php echo $allapot !== null && !empty($allapot['zart']) ? 'sdh-sor--zart' : ''; ?>">
                            <td class="sdh-tabla__nev">
                                <?php // A meglévő lap a munkalap ablakában nyílik (az a sorából tudja, hogy eladás). ?>
                                <a href="#" data-sdh-urlap="<?php echo esc_attr(SDH_Muhely_Munkalap::KULCS); ?>" data-sdh-id="<?php echo $id; ?>"><?php echo esc_html(SDH_Muhely_Munkalap::szam_formaz($sor->munkalap_szam)); ?></a>
                            </td>
                            <td>
                                <?php echo esc_html(SDH_Muhely_Munkalap::datum_megjelenit((string) $sor->keszult)); ?>
                                <?php if ($aru && (string) $sor->letrehozva !== '') : ?>
                                    <div class="sdh-tabla__halvany"><?php echo esc_html(mysql2date('H:i', (string) $sor->letrehozva)); ?></div>
                                <?php elseif (!$aru && (string) $sor->hatarido !== '' && !str_starts_with((string) $sor->hatarido, '0000')) : ?>
                                    <div class="sdh-tabla__halvany<?php echo $lejart ? ' sdh-hatarido--lejart' : ''; ?>">várható: <?php echo esc_html(SDH_Muhely_Munkalap::datum_megjelenit((string) $sor->hatarido)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $sor->ugyfel_id > 0 && $sor->ugyfel_nev !== null) : ?>
                                    <?php echo esc_html((string) $sor->ugyfel_nev); ?>
                                    <?php if ((string) $sor->ugyfel_telefon !== '') : ?>
                                        <div class="sdh-tabla__halvany"><?php echo esc_html((string) $sor->ugyfel_telefon); ?></div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="sdh-tabla__halvany"><?php echo esc_html(self::NEVTELEN); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="sdh-eladas__tetelek">
                                <?php echo esc_html($tetelek[$id] ?? '—'); ?>
                                <?php if ((string) $sor->nev !== '') : ?>
                                    <div class="sdh-tabla__halvany"><?php echo esc_html((string) $sor->nev); ?></div>
                                <?php endif; ?>
                            </td>
                            <?php if ($aru && $tobb_helyszin) : ?>
                                <td class="sdh-tabla__rejtheto"><?php echo esc_html($nevek[(int) $sor->helyszin_id] ?? '—'); ?></td>
                            <?php endif; ?>
                            <?php if (!$aru) : ?>
                                <td>
                                    <?php echo esc_html($nevek[(int) $sor->beszallito_id] ?? '—'); ?>
                                    <?php if ((string) $sor->rend_szam !== '') : ?>
                                        <div class="sdh-tabla__halvany"><?php echo esc_html((string) $sor->rend_szam); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo self::rend_jelveny((string) $sor->rend_allapot); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                    <?php echo SDH_Muhely_Rma::ful_jelveny($id, 'sdh-fulek__db sdh-eladas__uz'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                    <?php if ($allapot !== null && !empty($allapot['zart'])) : ?>
                                        <div class="sdh-tabla__halvany"><?php echo esc_html((string) $allapot['nev']); ?></div>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td class="sdh-eladas__szam"><?php echo esc_html(self::penz((float) $sor->brutto_ertek)); ?></td>
                            <td>
                                <?php if ($fizetve) : ?>
                                    <span class="sdh-eladas__fizetve">Fizetve</span>
                                    <?php if ((string) $sor->fizetesi_mod !== '') : ?>
                                        <div class="sdh-tabla__halvany"><?php echo esc_html((string) $sor->fizetesi_mod); ?></div>
                                    <?php endif; ?>
                                <?php elseif ($fizetendo > 0.5) : ?>
                                    <span class="sdh-eladas__fizetendo"><?php echo esc_html(self::penz($fizetendo)); ?></span>
                                    <div class="sdh-tabla__halvany"><?php echo (float) $sor->fizetett > 0 ? esc_html('előleg: ' . self::penz((float) $sor->fizetett)) : 'nincs fizetve'; ?></div>
                                <?php else : ?>
                                    <span class="sdh-tabla__halvany">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($aru) : ?>
                                <td class="sdh-tabla__rejtheto"><?php echo SDH_Muhely_Munkalap::jelveny((string) $sor->allapot, $allapotok); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <?php endif; ?>
                            <td>
                                <a class="sdh-gomb sdh-gomb--vilagos" href="#" data-sdh-urlap="<?php echo esc_attr(SDH_Muhely_Munkalap::KULCS); ?>" data-sdh-id="<?php echo $id; ?>">Megnyit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($oldalak > 1) : ?>
                <div class="sdh-lapozo">
                    <?php for ($i = 1; $i <= $oldalak; $i++) : ?>
                        <?php if ($i === $oldal) : ?>
                            <span class="sdh-lapozo__aktiv"><?php echo (int) $i; ?></span>
                        <?php else : ?>
                            <a href="<?php echo esc_url(SDH_Muhely_Modulok::url($modul, array_filter([
                                'oldalszam' => $i,
                                'k'         => $kereses !== '' ? $kereses : null,
                                'szuro'     => $szuro !== '' ? $szuro : null,
                                'torzs'     => $torzs > 0 ? $torzs : null,
                            ], static fn ($e): bool => $e !== null))); ?>"><?php echo (int) $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
