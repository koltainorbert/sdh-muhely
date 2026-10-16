<?php
/**
 * Szerepkörök és jogok (H5).
 *
 * A WordPress-fiók (admin / kolléga / közös fiók) továbbra is eldönti, hogy valaki
 * egyáltalán bejön-e. Ezen belül a SZEREP dönti el, mit csinálhat: melyik modult
 * látja, és négy érzékeny műveletet (pénztárriport, törzsárak átírása, számla
 * kiállítása, törlés-szerű műveletek) külön jogként.
 *
 * - A szerep a „tényleges személyé”: közös fióknál a kiválasztott dolgozóé
 *   (SDH_Muhely_Szemely::ki()), egyébként a belépett felhasználóé.
 * - A WordPress-adminisztrátor mindig „admin” szerepű (nem zárhatja ki magát).
 * - A Beállítások / Mentés / Modulok / Napló jog („beallitasok”) csak azt tudja
 *   elvenni, amit a WordPress-fiók amúgy is adna: közös adminisztrátori fiókon a
 *   kiválasztott dolgozó így nem kap beállítás-hozzáférést.
 * - A modulok tiltólistásak (új modul mindenkinél megjelenik), a finom jogok
 *   engedélylistásak (új érzékeny jog alapból senkinél nincs).
 * - A szerep nélküli, nem adminisztrátor személy „kolléga”: a 0.74-es állapot
 *   szerinti teljes (nem admin) hozzáférés – a frissítés semmit nem vesz el.
 *
 * Az érvényesítés a szerveren van: az AJAX- és admin-post végpontoknál az őr
 * (admin_init, 0), a menüknél / csempéknél a Modulok::lathato(); a böngészőben a
 * body-osztályok (sdh-ki-<modul>, sdh-nem-<jog>) csak a gombokat rejtik el.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Szerepkor
{
    public const OPT   = 'sdh_muhely_szerepek';
    public const META  = 'sdh_muhely_szerep';
    public const ADMIN = 'admin';
    public const ALAP  = 'kollega';
    public const MAX   = 12;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $gyors = null;

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'orszem'], 0);
    }

    /* ------------------------------------------------------------------
     * Katalógus
     * ------------------------------------------------------------------ */

    /**
     * A finom jogok.
     *
     * @return array<string, array{cim: string, leiras: string}>
     */
    public static function jogok(): array
    {
        return [
            'penztar_riport' => [
                'cim'    => 'Pénztárriport',
                'leiras' => 'Riport, statisztika, Excel-export és -import, egyeztető ügynök.',
            ],
            'ar_atiras' => [
                'cim'    => 'Árak és törzsek átírása',
                'leiras' => 'Termék- és szolgáltatás-törzs, árlista szerkesztése (az árak a munkalapon ettől függetlenül használhatók).',
            ],
            'szamla' => [
                'cim'    => 'Számla kiállítása',
                'leiras' => 'Valódi (Számlázz.hu) számla kiállítása; az előnézet nem kell hozzá.',
            ],
            'torles' => [
                'cim'    => 'Törlés-szerű műveletek',
                'leiras' => 'Termék törlése, pénztártétel törlése, pénztárnap és lezárt munkalap újranyitása, importált napló visszavonása, naptári bejegyzés és emlék törlése.',
            ],
            'beallitasok' => [
                'cim'    => 'Beállítások, Mentés, Modulok, Napló',
                'leiras' => 'Az adminisztrátor kapja; közös adminisztrátori fiókon ez választja el az Admin szerepű dolgozót a többitől. A Modulok oldalt és a frissítést csak a fejlesztő éri el.',
            ],
        ];
    }

    /**
     * AJAX-műveletek (sdh_muhely_ után, pontos név) → szükséges jog.
     *
     * @return array<string, string>
     */
    public static function ajax_jogok(): array
    {
        $t = [];

        foreach (['penztar_riport', 'penztar_statisztika', 'penztar_export', 'penztar_import_feltolt', 'penztar_import_lepes', 'penztar_egyeztet', 'penztar_ugyek', 'penztar_kerdes'] as $m) {
            $t[$m] = 'penztar_riport';
        }

        foreach (['termekek_ment', 'szolgaltatasok_ment', 'szolgaltatas_arak', 'szolgaltatas_arlista_ment'] as $m) {
            $t[$m] = 'ar_atiras';
        }

        $t['szamla_ment'] = 'szamla';

        foreach (['termek_torol', 'penztar_torol', 'penztar_ujranyit', 'penztar_import_visszavon', 'munkalapok_ujranyit', 'naptar_torol', 'emlek_torol'] as $m) {
            $t[$m] = 'torles';
        }

        return $t;
    }

    /**
     * admin-post.php műveletek → szükséges jog.
     *
     * @return array<string, string>
     */
    public static function post_jogok(): array
    {
        return [
            'termek_mentes'        => 'ar_atiras',
            'szolgaltatas_mentes'  => 'ar_atiras',
            'szolgaltatas_arak'    => 'ar_atiras',
        ];
    }

    /** A szükséges jog egy AJAX- vagy post-műveletre (üres = nem jogköteles). */
    public static function muvelet_jog(string $muvelet, bool $ajax = true): string
    {
        $t = $ajax ? self::ajax_jogok() : self::post_jogok();

        return $t[$muvelet] ?? '';
    }

    /**
     * A szerepek alapértelmezése (amíg az admin nem mentett).
     *
     * @return array<string, array{nev: string, zarolt: bool, tiltott: array<int, string>, jogok: array<int, string>}>
     */
    public static function alapok(): array
    {
        $mind = array_keys(self::jogok());

        return [
            self::ADMIN => [
                'nev' => 'Admin', 'zarolt' => true, 'tiltott' => [], 'jogok' => $mind,
            ],
            'kollega' => [
                'nev' => 'Kolléga', 'zarolt' => false, 'tiltott' => [],
                'jogok' => array_values(array_diff($mind, ['beallitasok'])),
            ],
            'pultos' => [
                'nev' => 'Pultos', 'zarolt' => false,
                'tiltott' => ['penztar', 'szamlazas', 'asszisztens'],
                'jogok' => [],
            ],
            'szerelo' => [
                'nev' => 'Szerelő', 'zarolt' => false,
                'tiltott' => ['penztar', 'szamlazas', 'arukiadas', 'rendelesek', 'levelezes', 'sms', 'asszisztens'],
                'jogok' => [],
            ],
            'penztaros' => [
                'nev' => 'Pénztáros', 'zarolt' => false,
                'tiltott' => ['asszisztens', 'levelezes'],
                'jogok' => ['penztar_riport', 'szamla'],
            ],
        ];
    }

    /**
     * A szerepek: a mentett készlet (ha van), különben az alapok. Az admin mindig teljes.
     *
     * @return array<string, array{nev: string, zarolt: bool, tiltott: array<int, string>, jogok: array<int, string>}>
     */
    public static function szerepek(): array
    {
        if (self::$gyors !== null) {
            return self::$gyors;
        }

        $alap    = self::alapok();
        $mentett = get_option(self::OPT, null);
        $ismert  = array_keys(self::jogok());
        $modulok = array_keys(SDH_Muhely_Modulok::katalogus());

        if (!is_array($mentett) || $mentett === []) {
            return self::$gyors = $alap;
        }

        $ki = [];

        foreach ($mentett as $slug => $sz) {
            $slug = sanitize_key((string) $slug);

            if ($slug === '' || !is_array($sz) || $slug === self::ADMIN) {
                continue;
            }

            $ki[$slug] = [
                'nev'     => mb_substr(trim((string) ($sz['nev'] ?? $slug)), 0, 40) ?: $slug,
                'zarolt'  => false,
                'tiltott' => array_values(array_intersect($modulok, array_map('strval', (array) ($sz['tiltott'] ?? [])))),
                'jogok'   => array_values(array_intersect($ismert, array_map('strval', (array) ($sz['jogok'] ?? [])))),
            ];
        }

        // Az admin mindig az első, zárolt, teljes jogú.
        $ki = [self::ADMIN => $alap[self::ADMIN]] + $ki;

        // A „kolléga” (a régi állapot) nem tűnhet el: a szerep nélküli személyek erre esnek vissza.
        if (!isset($ki[self::ALAP])) {
            $ki[self::ALAP] = $alap[self::ALAP];
        }

        return self::$gyors = $ki;
    }

    /** Gyorsítótár ürítése (mentés után, tesztekben). */
    public static function felejt(): void
    {
        self::$gyors = null;
    }

    /* ------------------------------------------------------------------
     * Személy → szerep
     * ------------------------------------------------------------------ */

    /** Melyik szerepe van egy személynek (felhasználó vagy dolgozó). */
    public static function szerep_slug(int $szemely): string
    {
        if ($szemely <= 0) {
            return self::ADMIN;
        }

        // A WordPress-adminisztrátor mindig admin (közös fiókon kiválasztva is): nem zárhatja ki magát.
        if (user_can($szemely, SDH_Muhely_Admin_UI::ADMIN_JOG)) {
            return self::ADMIN;
        }

        $slug = sanitize_key((string) get_user_meta($szemely, self::META, true));

        // Admin szerepet csak dolgozó kaphat (a közös adminisztrátori fiókon ő az „admin személy").
        if ($slug === self::ADMIN) {
            return SDH_Muhely_Szemely::dolgozo_e($szemely) ? self::ADMIN : self::ALAP;
        }

        return $slug !== '' && isset(self::szerepek()[$slug]) ? $slug : self::ALAP;
    }

    /**
     * A személy szerepe.
     *
     * @return array{nev: string, zarolt: bool, tiltott: array<int, string>, jogok: array<int, string>}
     */
    public static function szerep(?int $szemely = null): array
    {
        $szemely = $szemely ?? SDH_Muhely_Szemely::ki();

        return self::szerepek()[self::szerep_slug($szemely)];
    }

    public static function szerep_beallit(int $szemely, string $slug): void
    {
        $slug = sanitize_key($slug);

        if ($slug === self::ADMIN && SDH_Muhely_Szemely::dolgozo_e($szemely)) {
            update_user_meta($szemely, self::META, self::ADMIN);

            return;
        }

        if ($slug === '' || $slug === self::ALAP || !isset(self::szerepek()[$slug]) || $slug === self::ADMIN) {
            delete_user_meta($szemely, self::META);

            return;
        }

        update_user_meta($szemely, self::META, $slug);
    }

    /** Nincs-e akadály: kérésen kívüli (cron, CLI) környezetben nincs mit korlátozni. */
    private static function korlatozott(): bool
    {
        return get_current_user_id() > 0;
    }

    /** Megvan-e a finom jog (a tényleges személynek, vagy a megadottnak). */
    public static function megvan(string $jog, ?int $szemely = null): bool
    {
        if ($szemely === null && !self::korlatozott()) {
            return true;
        }

        if (!isset(self::jogok()[$jog])) {
            return true;
        }

        return in_array($jog, self::szerep($szemely)['jogok'], true);
    }

    /** Használhatja-e a modult (a tiltólistán nincs). */
    public static function modul_engedett(string $kulcs, ?int $szemely = null): bool
    {
        if ($szemely === null && !self::korlatozott()) {
            return true;
        }

        return !in_array($kulcs, self::szerep($szemely)['tiltott'], true);
    }

    /* ------------------------------------------------------------------
     * Őr
     * ------------------------------------------------------------------ */

    public static function orszem(): void
    {
        $akcio = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (strpos($akcio, 'sdh_muhely_') !== 0 || !is_user_logged_in()) {
            return;
        }

        $muvelet = substr($akcio, strlen('sdh_muhely_'));
        $ajax    = wp_doing_ajax();
        $modul   = $ajax ? SDH_Muhely_Modulok::ajax_modulja($muvelet) : SDH_Muhely_Modulok::post_modulja($muvelet);

        if ($modul !== '' && !self::modul_engedett($modul)) {
            $katalogus = SDH_Muhely_Modulok::katalogus();
            self::elutasit($muvelet, $ajax, 'A(z) „' . ($katalogus[$modul]['cim'] ?? $modul) . '” a te szerepkörödben nem elérhető.');
        }

        $jog = self::muvelet_jog($muvelet, $ajax);

        if ($jog !== '' && !self::megvan($jog)) {
            self::elutasit($muvelet, $ajax, 'Ehhez nincs jogod: ' . self::jogok()[$jog]['cim'] . '. Kérd a vezetőt, hogy adja meg a szerepkörödnek.', $jog);
        }
    }

    private static function elutasit(string $muvelet, bool $ajax, string $uzenet, string $jog = ''): void
    {
        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('rendszer', 0, 'Jogosultság hiány: ' . SDH_Muhely_Szemely::nev() . ' – ' . $muvelet);
        }

        if (!$ajax) {
            wp_die(esc_html($uzenet), 'Nincs jogosultság', ['response' => 403, 'back_link' => true]);
        }

        // Az űrlap-végpontok HTML-t adnak a popupba: ott a popup mondja el, mi a helyzet.
        if (substr($muvelet, -6) === '_urlap') {
            echo '<h2 class="sdh-modal__cim">Nincs jogosultság</h2><div class="sdh-uzenet sdh-uzenet--hiba">' . esc_html($uzenet) . '</div>';
            wp_die();
        }

        wp_send_json_error(['uzenet' => $uzenet, 'jog_hiany' => $jog], 403);
    }

    /** „ sdh-nem-penztar_riport” – a böngésző ebből rejti el az érintett gombokat (a szerver amúgy is elutasít). */
    public static function body_osztalyok(): string
    {
        if (!self::korlatozott()) {
            return '';
        }

        $szerep = self::szerep();
        $ki     = '';

        foreach (array_keys(self::jogok()) as $jog) {
            if (!in_array($jog, $szerep['jogok'], true)) {
                $ki .= ' sdh-nem-' . $jog;
            }
        }

        foreach ($szerep['tiltott'] as $modul) {
            $ki .= ' sdh-ki-' . $modul;
        }

        return $ki;
    }

    /* ------------------------------------------------------------------
     * Beállítások › Jogosultságok
     * ------------------------------------------------------------------ */

    /** Akiknek szerepet lehet adni: nem adminisztrátor kollégák és a dolgozók. */
    private static function szemelyek(): array
    {
        $lista = [];

        foreach (get_users(['capability' => SDH_Muhely_Admin_UI::jog(), 'orderby' => 'display_name', 'order' => 'ASC']) as $u) {
            if (user_can($u, SDH_Muhely_Admin_UI::ADMIN_JOG) || SDH_Muhely_Szemely::kozos((int) $u->ID)) {
                continue;
            }

            $lista[(int) $u->ID] = ['nev' => (string) $u->display_name, 'fajta' => 'kolléga'];
        }

        foreach (SDH_Muhely_Szemely::dolgozok() as $id => $d) {
            $lista[(int) $id] = ['nev' => $d['nev'], 'fajta' => 'dolgozó'];
        }

        return $lista;
    }

    public static function doboz(): void
    {
        $szerepek = self::szerepek();
        $modulok  = SDH_Muhely_Modulok::katalogus();
        $jogok    = self::jogok();
        $szemelyek = self::szemelyek();

        ?>
        <div class="sdh-doboz" id="szerepkorok">
            <h2 class="sdh-doboz__cim">Szerepkörök és jogok</h2>
            <p class="sdh-sugo">
                Minden szerepnél jelöld, mit csinálhat. A szerep a ténylegesen dolgozó személyé: közös fiókon a kiválasztott dolgozóé.
                Az adminisztrátor mindig teljes jogú. A „Kolléga” a régi állapot (mindent lát, amit eddig), a nem adminisztrátor
                kolléga és dolgozó alapból ez.
            </p>

            <input type="hidden" name="szerep_jelen" value="1">

            <div class="sdh-szerep-racs" style="--sdh-szerep-oszlop: <?php echo (int) count($szerepek); ?>">
                <div class="sdh-szerep-racs__sor sdh-szerep-racs__sor--fej">
                    <span></span>
                    <?php foreach ($szerepek as $slug => $sz) : ?>
                        <span class="sdh-szerep-racs__fej">
                            <?php if ($sz['zarolt']) : ?>
                                <strong><?php echo esc_html($sz['nev']); ?></strong>
                            <?php else : ?>
                                <input type="text" name="szerep[<?php echo esc_attr($slug); ?>][nev]" value="<?php echo esc_attr($sz['nev']); ?>" maxlength="40" aria-label="Szerep neve" autocomplete="off">
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <div class="sdh-szerep-racs__csoport">Jogok</div>
                <?php foreach ($jogok as $jog => $j) : ?>
                    <div class="sdh-szerep-racs__sor">
                        <span class="sdh-szerep-racs__cimke" title="<?php echo esc_attr($j['leiras']); ?>"><?php echo esc_html($j['cim']); ?></span>
                        <?php foreach ($szerepek as $slug => $sz) : ?>
                            <span class="sdh-szerep-racs__cella">
                                <input type="checkbox" <?php echo $sz['zarolt'] ? 'disabled' : 'name="szerep[' . esc_attr($slug) . '][jogok][]"'; ?>
                                       value="<?php echo esc_attr($jog); ?>" aria-label="<?php echo esc_attr($j['cim'] . ' – ' . $sz['nev']); ?>"
                                       <?php checked(in_array($jog, $sz['jogok'], true)); ?>>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <p class="sdh-szerep-racs__leiras"><?php echo esc_html($j['leiras']); ?></p>
                <?php endforeach; ?>

                <div class="sdh-szerep-racs__csoport">Modulok (ami nincs bejelölve, azt a szerep nem látja)</div>
                <?php foreach ($modulok as $kulcs => $m) : ?>
                    <div class="sdh-szerep-racs__sor">
                        <span class="sdh-szerep-racs__cimke"><?php echo esc_html($m['cim']); ?></span>
                        <?php foreach ($szerepek as $slug => $sz) : ?>
                            <span class="sdh-szerep-racs__cella">
                                <input type="checkbox" <?php echo $sz['zarolt'] ? 'disabled' : 'name="szerep[' . esc_attr($slug) . '][modulok][]"'; ?>
                                       value="<?php echo esc_attr($kulcs); ?>" aria-label="<?php echo esc_attr($m['cim'] . ' – ' . $sz['nev']); ?>"
                                       <?php checked(!in_array($kulcs, $sz['tiltott'], true)); ?>>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <h3 class="sdh-doboz__alcim">Új szerep</h3>
            <div class="sdh-mezo">
                <input type="text" name="szerep_uj" value="" maxlength="40" placeholder="pl. Ügyvezető" aria-label="Új szerep neve" autocomplete="off">
                <p class="sdh-sugo">Az új szerep a Kolléga jogaival indul; mentés után állítható. Törléséhez ürítsd ki a nevét – akié volt, kolléga lesz.</p>
            </div>

            <h3 class="sdh-doboz__alcim">Ki melyik szerepben?</h3>
            <?php if ($szemelyek === []) : ?>
                <p class="sdh-sugo">Még nincs nem adminisztrátor kolléga vagy dolgozó.</p>
            <?php endif; ?>
            <div class="sdh-kivagy-lista">
                <?php foreach ($szemelyek as $id => $p) : ?>
                    <div class="sdh-kivagy-lista__sor">
                        <span><?php echo esc_html($p['nev']); ?> <small>(<?php echo esc_html($p['fajta']); ?>)</small></span>
                        <?php if (user_can($id, SDH_Muhely_Admin_UI::ADMIN_JOG)) : ?>
                            <span>Admin (fiókjából adódóan)</span>
                        <?php else : ?>
                            <select name="szemely_szerep[<?php echo (int) $id; ?>]" aria-label="Szerep – <?php echo esc_attr($p['nev']); ?>">
                                <?php foreach ($szerepek as $slug => $sz) : ?>
                                    <?php if ($sz['zarolt'] && $p['fajta'] !== 'dolgozó') { continue; } ?>
                                    <option value="<?php echo esc_attr($slug); ?>" <?php selected(self::szerep_slug($id), $slug); ?>><?php echo esc_html($sz['nev']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <span></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="sdh-sugo">
                Az adminisztrátor fiókok mindig Adminok; az ő szerepük itt nem állítható. Közös adminisztrátori fiókon az <strong>Admin</strong>
                szerepű dolgozó éri el a Beállításokat – ha nincs ilyen, a választó azon a fiókon nem jelenik meg (hogy senki ne zárhassa ki magát).
            </p>
        </div>
        <?php
    }

    public static function mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['szerep_jelen'])) {
            return;
        }

        $regi     = self::szerepek();
        $modulok  = array_keys(SDH_Muhely_Modulok::katalogus());
        $ismert   = array_keys(self::jogok());
        $bejovo   = (array) ($_POST['szerep'] ?? []);
        $uj       = [];
        $torolt   = [];

        foreach ($regi as $slug => $sz) {
            if ($sz['zarolt']) {
                continue;
            }

            $sor = isset($bejovo[$slug]) && is_array($bejovo[$slug]) ? $bejovo[$slug] : null;

            if ($sor === null) {
                $uj[$slug] = $sz;

                continue;
            }

            $nev = mb_substr(trim(sanitize_text_field(wp_unslash((string) ($sor['nev'] ?? '')))), 0, 40);

            // Üres név = törlés (a kolléga szerep nem törölhető).
            if ($nev === '' && $slug !== self::ALAP) {
                $torolt[] = $slug;

                continue;
            }

            $engedett = array_map('sanitize_key', array_map('strval', (array) wp_unslash($sor['modulok'] ?? [])));
            $jogok    = array_map('sanitize_key', array_map('strval', (array) wp_unslash($sor['jogok'] ?? [])));

            $uj[$slug] = [
                'nev'     => $nev !== '' ? $nev : $sz['nev'],
                'zarolt'  => false,
                'tiltott' => array_values(array_diff($modulok, $engedett)),
                'jogok'   => array_values(array_intersect($ismert, $jogok)),
            ];
        }

        $nev_uj = mb_substr(trim(sanitize_text_field(wp_unslash((string) ($_POST['szerep_uj'] ?? '')))), 0, 40);

        if ($nev_uj !== '' && count($uj) + 1 < self::MAX) {
            $slug = sanitize_key(sanitize_title($nev_uj));
            $slug = $slug !== '' ? $slug : 'szerep';
            $alap = $slug;

            for ($i = 2; isset($uj[$slug]) || $slug === self::ADMIN; $i++) {
                $slug = $alap . '-' . $i;
            }

            $uj[$slug] = [
                'nev'     => $nev_uj,
                'zarolt'  => false,
                'tiltott' => $regi[self::ALAP]['tiltott'],
                'jogok'   => $regi[self::ALAP]['jogok'],
            ];
        }

        $mentendo = [];

        foreach ($uj as $slug => $sz) {
            $mentendo[$slug] = ['nev' => $sz['nev'], 'tiltott' => $sz['tiltott'], 'jogok' => $sz['jogok']];
        }

        update_option(self::OPT, $mentendo);
        self::felejt();

        // Akinek a szerepe megszűnt, kolléga lesz.
        foreach ($torolt as $slug) {
            $ids = get_users(['meta_key' => self::META, 'meta_value' => $slug, 'fields' => 'ID']); // phpcs:ignore WordPress.DB.SlowDBQuery

            foreach ($ids as $id) {
                delete_user_meta((int) $id, self::META);
            }
        }

        foreach ((array) ($_POST['szemely_szerep'] ?? []) as $id => $slug) {
            $id = (int) $id;

            if ($id > 0 && !user_can($id, SDH_Muhely_Admin_UI::ADMIN_JOG) && (SDH_Muhely_Szemely::dolgozo_e($id) || user_can($id, SDH_Muhely_Admin_UI::jog()))) {
                self::szerep_beallit($id, sanitize_key(wp_unslash((string) $slug)));
            }
        }
        // phpcs:enable

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('rendszer', 0, 'Szerepkörök és jogok módosítva');
        }

        self::felejt();
    }
}
