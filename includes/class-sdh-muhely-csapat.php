<?php
/**
 * Csapat – belső üzenetek és kollégák (0.38).
 *
 * Három dolgot fog össze:
 *  1. Kollégák: a rendszer felhasználói. Az adminisztrátor a Csapat oldal
 *     „Kollégák" lapján vesz fel új kollégát (WordPress-fiók a „Kolléga"
 *     szerepkörrel), szerkeszti, letiltja – törlés nincs, a letiltott fiók
 *     nem lép be, de a régi üzenetei és munkalapjai megmaradnak.
 *  2. Csoportok: pl. „Szerelők", „Pult". Minden csoportnak saját beszélgetése
 *     van, a tagság a csoport űrlapjáról állítható.
 *  3. Üzenetek: személyesen, több kiválasztott kollégának, csoportnak vagy
 *     mindenkinek. Válasz idézettel, „fontos" üzenet (felugró ablak a
 *     címzetteknek, amíg nyugtázzák), kitűzés (az üzenet a beszélgetés
 *     tetején marad – „üzenet hagyása a rendszerben"), olvasottság, gépelés-jelzés.
 *
 * Minden CRM-oldalon fut egy „pulzus" (assets/csapat.js): ez hozza az
 * olvasatlanok számát, az új üzenetek felugró kártyáit és a fontos
 * üzeneteket. Más modul (az AI-asszisztens) a `sdh_muhely_pulzus` szűrőn
 * teheti hozzá a sajátját – így egy kérés szolgál ki mindent.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Csapat
{
    public const KULCS = 'csapat';

    /** A kolléga szerepköre. */
    public const SZEREP = 'sdh_kollega';

    /** Egy beszélgetés egy betöltésére ennyi üzenet jön (a többi: „Korábbiak"). */
    private const OLDAL = 40;

    /** A kollégák és csoportok listájának egy oldala. */
    private const LISTA_OLDAL = 12;

    /** A kolléga színei (a CSS-ben: --sdh-cs-av-<név>). */
    public const SZINEK = [
        'kek'     => 'Kék',
        'zold'    => 'Zöld',
        'lila'    => 'Lila',
        'narancs' => 'Narancs',
        'rozsa'   => 'Rózsa',
        'turkiz'  => 'Türkiz',
        'sarga'   => 'Sárga',
        'piros'   => 'Piros',
    ];

    /** @var array<int, array<int, int>> */
    private static array $elerheto = [];

    public static function init(): void
    {
        $muveletek = [
            'csapat_lista'      => 'ajax_lista',
            'csapat_szal'       => 'ajax_szal',
            'csapat_frissit'    => 'ajax_frissit',
            'csapat_kuld'       => 'ajax_kuld',
            'csapat_gepel'      => 'ajax_gepel',
            'csapat_nyugta'     => 'ajax_nyugta',
            'csapat_kituz'      => 'ajax_kituz',
            'csapat_visszavon'  => 'ajax_visszavon',
            'csapat_olvasott'   => 'ajax_olvasott',
            'pulzus'            => 'ajax_pulzus',
            // Popupok (app.js: sdh_muhely_<modul>_urlap).
            'csapatuj_urlap'      => 'ajax_uj_urlap',
            'csapatkollega_urlap' => 'ajax_kollega_urlap',
            'csapatkollega_ment'  => 'ajax_kollega_ment',
            'csapatcsoport_urlap' => 'ajax_csoport_urlap',
            'csapatcsoport_ment'  => 'ajax_csoport_ment',
        ];

        foreach ($muveletek as $nev => $fuggveny) {
            add_action('wp_ajax_sdh_muhely_' . $nev, [self::class, $fuggveny]);
        }

        add_action('init', [self::class, 'szerep_telepit'], 6);
        add_filter('login_redirect', [self::class, 'belepes_utan'], 20, 3);
        add_action('admin_init', [self::class, 'admin_kizar'], 2);
        // A menü jogosultság-ellenőrzése (wp_die) az admin_init előtt fut: már itt átirányítunk.
        add_action('admin_menu', [self::class, 'admin_kizar'], 1);
        add_filter('show_admin_bar', [self::class, 'admin_sav']);
        add_filter('authenticate', [self::class, 'letiltott_belepes'], 40);

        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Csapat',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 34,
            'jelveny' => [self::class, 'olvasatlan_db'],
        ]);
    }

    /* =================================================================
     * Szerepkör, belépés
     * ============================================================== */

    /** A „Kolléga" szerepkör: belép, és használja a CRM-et – a WordPress-admint nem. */
    public static function szerep_telepit(): void
    {
        if (get_option('sdh_muhely_kollega_szerep') === '1' && get_role(self::SZEREP) !== null) {
            return;
        }

        if (get_role(self::SZEREP) === null) {
            add_role(self::SZEREP, 'SDH kolléga', ['read' => true, SDH_Muhely_Admin_UI::JOG => true]);
        }

        update_option('sdh_muhely_kollega_szerep', '1');
    }

    /** Belépés után a kolléga egyenesen a műhelybe kerül. */
    public static function belepes_utan(string $cel, string $kert, $felhasznalo): string
    {
        if ($felhasznalo instanceof WP_User
            && user_can($felhasznalo, SDH_Muhely_Admin_UI::JOG)
            && !user_can($felhasznalo, SDH_Muhely_Admin_UI::ADMIN_JOG)) {
            return SDH_Muhely_Modulok::frontend_url();
        }

        return $cel;
    }

    /** A kolléga nem a WordPress-adminban dolgozik: onnan a műhelybe kerül. */
    public static function admin_kizar(): void
    {
        if (wp_doing_ajax() || !is_user_logged_in() || SDH_Muhely_Admin_UI::admin_e()) {
            return;
        }

        $fajl = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';

        if (in_array($fajl, ['admin-post.php', 'admin-ajax.php', 'async-upload.php'], true)) {
            return;
        }

        if (current_user_can(SDH_Muhely_Admin_UI::JOG)) {
            wp_safe_redirect(SDH_Muhely_Modulok::frontend_url());
            exit;
        }
    }

    /** A letiltott kolléga nem léphet be. @param mixed $felhasznalo */
    public static function letiltott_belepes($felhasznalo)
    {
        if ($felhasznalo instanceof WP_User && get_user_meta($felhasznalo->ID, 'sdh_letiltva', true) === '1') {
            return new WP_Error('sdh_letiltva', 'Ez a fiók le van tiltva. Szólj az adminisztrátornak.');
        }

        return $felhasznalo;
    }

    public static function admin_sav(bool $mutat): bool
    {
        return current_user_can(SDH_Muhely_Admin_UI::ADMIN_JOG) ? $mutat : false;
    }

    /* =================================================================
     * Táblák, segédek
     * ============================================================== */

    private static function t(string $nev): string
    {
        return SDH_Muhely_Schema::tabla('csapat_' . $nev);
    }

    private static function most(): string
    {
        return current_time('mysql');
    }

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    private static function admin_jog(): void
    {
        self::jog();

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ehhez adminisztrátori jog kell.'], 403);
        }
    }

    /** @param mixed $ertek */
    private static function g(string $kulcs, int $max = 255, $alap = ''): string
    {
        // phpcs:disable WordPress.Security.NonceVerification -- a hívó ellenőrzi.
        $forras = $_POST[$kulcs] ?? ($_GET[$kulcs] ?? $alap);
        // phpcs:enable

        return is_scalar($forras) ? mb_substr(sanitize_text_field(wp_unslash((string) $forras)), 0, $max) : (string) $alap;
    }

    private static function szoveg_be(string $kulcs, int $max = 4000): string
    {
        // phpcs:disable WordPress.Security.NonceVerification -- a hívó ellenőrzi.
        $forras = $_POST[$kulcs] ?? '';
        // phpcs:enable

        return is_scalar($forras) ? mb_substr(trim(sanitize_textarea_field(wp_unslash((string) $forras))), 0, $max) : '';
    }

    private static function in(array $ertekek, string $tipus = '%d'): string
    {
        return implode(',', array_fill(0, max(1, count($ertekek)), $tipus));
    }

    /* =================================================================
     * Kollégák
     * ============================================================== */

    /**
     * A rendszer felhasználói (letiltottakkal együtt, ha kérik).
     *
     * @return array<int, array{id: int, nev: string, betu: string, szin: string, titulus: string, email: string, login: string, admin: bool, letiltva: bool, online: bool, regisztralt: string}>
     */
    public static function kollegak(bool $letiltottal = false): array
    {
        static $gyorsitotar = [];

        $kulcs = $letiltottal ? 'mind' : 'aktiv';

        if (isset($gyorsitotar[$kulcs])) {
            return $gyorsitotar[$kulcs];
        }

        $felhasznalok = get_users([
            'role__in' => ['administrator', self::SZEREP],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ]);

        $lista = [];

        foreach ($felhasznalok as $f) {
            $k = self::kollega_adat($f);

            if (!$letiltottal && $k['letiltva']) {
                continue;
            }

            $lista[$k['id']] = $k;
        }

        $gyorsitotar[$kulcs] = $lista;

        return $lista;
    }

    /**
     * @return array{id: int, nev: string, betu: string, szin: string, titulus: string, email: string, login: string, admin: bool, letiltva: bool, online: bool, regisztralt: string}
     */
    public static function kollega_adat(WP_User $f): array
    {
        $id   = (int) $f->ID;
        $nev  = trim((string) $f->display_name) !== '' ? (string) $f->display_name : (string) $f->user_login;
        $szin = (string) get_user_meta($id, 'sdh_szin', true);

        if (!isset(self::SZINEK[$szin])) {
            $kulcsok = array_keys(self::SZINEK);
            $szin    = $kulcsok[$id % count($kulcsok)];
        }

        $utoljara = (int) get_user_meta($id, 'sdh_utoljara', true);

        return [
            'id'          => $id,
            'nev'         => $nev,
            'betu'        => self::monogram($nev),
            'szin'        => $szin,
            'titulus'     => (string) get_user_meta($id, 'sdh_titulus', true),
            'email'       => (string) $f->user_email,
            'login'       => (string) $f->user_login,
            'admin'       => user_can($f, SDH_Muhely_Admin_UI::ADMIN_JOG),
            'letiltva'    => get_user_meta($id, 'sdh_letiltva', true) === '1',
            'online'      => $utoljara > 0 && (time() - $utoljara) < 150,
            'regisztralt' => (string) $f->user_registered,
        ];
    }

    private static function monogram(string $nev): string
    {
        $szavak = preg_split('/\s+/u', trim($nev)) ?: [];
        $betuk  = '';

        foreach (array_slice($szavak, 0, 2) as $sz) {
            $betuk .= mb_strtoupper(mb_substr($sz, 0, 1));
        }

        return $betuk !== '' ? $betuk : '?';
    }

    /** @return array{id: int, nev: string, betu: string, szin: string}|null */
    private static function kollega(int $id): ?array
    {
        $mind = self::kollegak(true);

        if (isset($mind[$id])) {
            return $mind[$id];
        }

        $f = get_userdata($id);

        return $f instanceof WP_User ? self::kollega_adat($f) : null;
    }

    private static function nev(int $id): string
    {
        if ($id === 0) {
            return 'Rendszer';
        }

        $k = self::kollega($id);

        return $k['nev'] ?? 'Ismeretlen';
    }

    /** @return array{id: int, nev: string, betu: string, szin: string} */
    private static function felado_adat(int $id, string $forras = 'kezi'): array
    {
        $k = $id > 0 ? self::kollega($id) : null;

        if ($k === null) {
            return ['id' => $id, 'nev' => $forras === 'ai' ? 'Asszisztens' : 'Rendszer', 'betu' => $forras === 'ai' ? 'AI' : '•', 'szin' => 'lila'];
        }

        return ['id' => $k['id'], 'nev' => $k['nev'], 'betu' => $k['betu'], 'szin' => $k['szin']];
    }

    /* =================================================================
     * Csoportok
     * ============================================================== */

    /** @return array<int, object> */
    public static function csoportok(bool $archivval = false): array
    {
        global $wpdb;

        $t = self::t('csoport');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sorok = $wpdb->get_results("SELECT * FROM {$t}" . ($archivval ? '' : ' WHERE aktiv = 1') . ' ORDER BY nev ASC');
        // phpcs:enable

        $lista = [];

        foreach ((array) $sorok as $s) {
            $lista[(int) $s->id] = $s;
        }

        return $lista;
    }

    /** @return array<int, int> */
    public static function csoport_tagjai(int $csoport_id): array
    {
        global $wpdb;

        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT user_id FROM ' . self::t('csoport_tag') . ' WHERE csoport_id = %d',
            $csoport_id
        )));
    }

    /** @return array<int, int> */
    private static function sajat_csoportok(int $user): array
    {
        global $wpdb;

        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT csoport_id FROM ' . self::t('csoport_tag') . ' WHERE user_id = %d',
            $user
        )));
    }

    /* =================================================================
     * Beszélgetések
     * ============================================================== */

    /** A „Mindenki" beszélgetés (egy van belőle; ha még nincs, létrejön). */
    public static function mindenki_id(): int
    {
        global $wpdb;

        $t  = self::t('beszelgetes');
        $id = (int) $wpdb->get_var("SELECT id FROM {$t} WHERE tipus = 'mindenki' ORDER BY id ASC LIMIT 1"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($id > 0) {
            return $id;
        }

        $wpdb->insert($t, ['tipus' => 'mindenki', 'nev' => 'Mindenki', 'letrehozva' => self::most(), 'utolso_ido' => self::most()]);

        return (int) $wpdb->insert_id;
    }

    /** Egy csoport beszélgetése (ha még nincs, létrejön). */
    public static function csoport_besz_id(int $csoport_id): int
    {
        global $wpdb;

        $t  = self::t('beszelgetes');
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE tipus = 'csoport' AND csoport_id = %d ORDER BY id ASC LIMIT 1", $csoport_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($id > 0) {
            return $id;
        }

        $cs = self::csoportok(true)[$csoport_id] ?? null;

        if ($cs === null) {
            return 0;
        }

        $wpdb->insert($t, [
            'tipus' => 'csoport', 'nev' => (string) $cs->nev, 'csoport_id' => $csoport_id,
            'letrehozo' => get_current_user_id(), 'letrehozva' => self::most(), 'utolso_ido' => self::most(),
        ]);

        return (int) $wpdb->insert_id;
    }

    /** Két kolléga közös beszélgetése (ha még nincs, létrejön). */
    public static function ketto_id(int $a, int $b): int
    {
        global $wpdb;

        $t   = self::t('beszelgetes');
        $tag = self::t('tag');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT b.id FROM {$t} b
             JOIN {$tag} t1 ON t1.beszelgetes_id = b.id AND t1.user_id = %d AND t1.csatlakozott IS NOT NULL
             JOIN {$tag} t2 ON t2.beszelgetes_id = b.id AND t2.user_id = %d AND t2.csatlakozott IS NOT NULL
             WHERE b.tipus = 'ketto' ORDER BY b.id ASC LIMIT 1",
            $a,
            $b
        ));
        // phpcs:enable

        if ($id > 0) {
            return $id;
        }

        return self::uj_beszelgetes('ketto', '', [$a, $b]);
    }

    /** @param array<int, int> $tagok */
    private static function uj_beszelgetes(string $tipus, string $nev, array $tagok): int
    {
        global $wpdb;

        $wpdb->insert(self::t('beszelgetes'), [
            'tipus' => $tipus, 'nev' => mb_substr($nev, 0, 190), 'letrehozo' => get_current_user_id(),
            'letrehozva' => self::most(), 'utolso_ido' => self::most(),
        ]);

        $id = (int) $wpdb->insert_id;

        foreach (array_unique(array_filter(array_map('intval', $tagok))) as $u) {
            self::tag_biztosit($id, $u, true);
        }

        return $id;
    }

    /** A tag-sor (olvasottság); $csatlakozik = valódi tagság (hozzáférés is). */
    private static function tag_biztosit(int $besz, int $user, bool $csatlakozik = false): void
    {
        global $wpdb;

        $t   = self::t('tag');
        $sor = $wpdb->get_row($wpdb->prepare("SELECT id, csatlakozott FROM {$t} WHERE beszelgetes_id = %d AND user_id = %d", $besz, $user)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($sor === null) {
            $wpdb->insert($t, [
                'beszelgetes_id' => $besz, 'user_id' => $user, 'olvasott_id' => 0,
                'csatlakozott'   => $csatlakozik ? self::most() : null,
            ]);

            return;
        }

        if ($csatlakozik && $sor->csatlakozott === null) {
            $wpdb->update($t, ['csatlakozott' => self::most()], ['id' => (int) $sor->id]);
        }
    }

    /**
     * Mely beszélgetésekhez fér hozzá a felhasználó.
     *
     * @return array<int, int>
     */
    public static function elerheto(int $user): array
    {
        if (isset(self::$elerheto[$user])) {
            return self::$elerheto[$user];
        }

        global $wpdb;

        $t    = self::t('beszelgetes');
        $tag  = self::t('tag');
        $cst  = self::t('csoport_tag');
        $csop = self::t('csoport');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sajat = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT beszelgetes_id FROM {$tag} WHERE user_id = %d AND csatlakozott IS NOT NULL",
            $user
        ));
        $csoportos = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT b.id FROM {$t} b
             JOIN {$cst} c ON c.csoport_id = b.csoport_id
             JOIN {$csop} g ON g.id = b.csoport_id AND g.aktiv = 1
             WHERE b.tipus = 'csoport' AND c.user_id = %d",
            $user
        ));
        // phpcs:enable

        $idk = array_map('intval', array_merge($sajat, $csoportos));

        if (user_can($user, SDH_Muhely_Admin_UI::JOG)) {
            $idk[] = self::mindenki_id();
        }

        self::$elerheto[$user] = array_values(array_unique(array_filter($idk)));

        return self::$elerheto[$user];
    }

    private static function hozzafer(int $besz, int $user): bool
    {
        return in_array($besz, self::elerheto($user), true);
    }

    private static function besz(int $id): ?object
    {
        global $wpdb;

        $t = self::t('beszelgetes');

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id)) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * A beszélgetés tagjai (akiknek szól).
     *
     * @return array<int, int>
     */
    private static function besz_tagok(object $b): array
    {
        global $wpdb;

        if ($b->tipus === 'mindenki') {
            return array_keys(self::kollegak());
        }

        $tagok = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT user_id FROM ' . self::t('tag') . ' WHERE beszelgetes_id = %d AND csatlakozott IS NOT NULL',
            (int) $b->id
        )));

        if ($b->tipus === 'csoport') {
            $tagok = array_merge($tagok, self::csoport_tagjai((int) $b->csoport_id));
        }

        $aktivak = self::kollegak();

        return array_values(array_filter(array_unique($tagok), static fn (int $u): bool => isset($aktivak[$u])));
    }

    /**
     * A beszélgetés megjelenítendő neve és képe a felhasználó szemszögéből.
     *
     * @return array{nev: string, alcim: string, kep: array<int, array{betu: string, szin: string}>, online: bool, csoport_szin: string}
     */
    private static function besz_megjelenes(object $b, int $en): array
    {
        if ($b->tipus === 'mindenki') {
            return ['nev' => 'Mindenki', 'alcim' => count(self::kollegak()) . ' kolléga', 'kep' => [['betu' => '∞', 'szin' => 'kek']], 'online' => false, 'csoport_szin' => 'kek'];
        }

        if ($b->tipus === 'csoport') {
            $cs   = self::csoportok(true)[(int) $b->csoport_id] ?? null;
            $szin = $cs && isset(self::SZINEK[(string) $cs->szin]) ? (string) $cs->szin : 'turkiz';

            return [
                'nev'   => $cs ? (string) $cs->nev : ((string) $b->nev !== '' ? (string) $b->nev : 'Csoport'),
                'alcim' => 'Csoport · ' . count(self::besz_tagok($b)) . ' tag',
                'kep'   => [['betu' => self::monogram($cs ? (string) $cs->nev : 'Cs'), 'szin' => $szin]],
                'online' => false,
                'csoport_szin' => $szin,
            ];
        }

        $masok = array_values(array_filter(self::besz_tagok($b), static fn (int $u): bool => $u !== $en));
        $kep   = [];
        $nevek = [];
        $online = false;

        foreach ($masok as $u) {
            $k = self::kollega($u);

            if ($k === null) {
                continue;
            }

            $kep[]   = ['betu' => $k['betu'], 'szin' => $k['szin']];
            $nevek[] = $k['nev'];
            $online  = $online || $k['online'];
        }

        if ($b->tipus === 'ketto') {
            $k = isset($masok[0]) ? self::kollega($masok[0]) : null;

            return [
                'nev'   => $k['nev'] ?? 'Kolléga',
                'alcim' => $k ? ($k['online'] ? 'Elérhető' : ($k['titulus'] !== '' ? $k['titulus'] : 'Kolléga')) : '',
                'kep'   => array_slice($kep, 0, 1),
                'online' => $online,
                'csoport_szin' => '',
            ];
        }

        return [
            'nev'   => (string) $b->nev !== '' ? (string) $b->nev : implode(', ', array_slice($nevek, 0, 3)) . (count($nevek) > 3 ? ' +' . (count($nevek) - 3) : ''),
            'alcim' => (count($masok) + 1) . ' résztvevő',
            'kep'   => array_slice($kep, 0, 3),
            'online' => $online,
            'csoport_szin' => '',
        ];
    }

    /* =================================================================
     * Üzenetek
     * ============================================================== */

    private static function ido_szoveg(string $mysql): string
    {
        if ($mysql === '') {
            return '';
        }

        $d = date_create_immutable($mysql, wp_timezone());

        if (!$d) {
            return '';
        }

        $ma     = current_time('Y-m-d');
        $tegnap = wp_date('Y-m-d', strtotime('-1 day', current_time('timestamp', true)));
        $nap    = $d->format('Y-m-d');

        if ($nap === $ma) {
            return $d->format('H:i');
        }

        if ($nap === $tegnap) {
            return 'tegnap ' . $d->format('H:i');
        }

        $honapok = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];

        return ($d->format('Y') !== current_time('Y') ? $d->format('Y.') . ' ' : '')
            . $honapok[(int) $d->format('n') - 1] . ' ' . (int) $d->format('j') . '. ' . $d->format('H:i');
    }

    /** @return array{tipus: string, id: int, cim: string, url: string}|null */
    private static function hivatkozas(string $h): ?array
    {
        if (preg_match('/^(munkalap|ugyfel|eszkoz):(\d+)$/', $h, $m) !== 1) {
            return null;
        }

        $id = (int) $m[2];
        $cim = ['munkalap' => 'Munkalap', 'ugyfel' => 'Ügyfél', 'eszkoz' => 'Eszköz'][$m[1]];

        if ($m[1] === 'munkalap') {
            global $wpdb;

            $szam = $wpdb->get_var($wpdb->prepare('SELECT munkalap_szam FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $id));
            $cim .= $szam ? ' ' . SDH_Muhely_Munkalap::szam_formaz($szam) : ' (#' . $id . ')';
        }

        // A popup modulneve (app.js: sdh_muhely_<modul>_urlap) többes számú.
        $modul = ['munkalap' => 'munkalapok', 'ugyfel' => 'ugyfelek', 'eszkoz' => 'eszkozok'][$m[1]];

        return ['tipus' => $modul, 'id' => $id, 'cim' => $cim, 'url' => ''];
    }

    /**
     * Egy üzenet a felületnek.
     *
     * @param array<int, int> $olvasottsag user_id => olvasott_id (a „látta" jelzéshez)
     * @return array<string, mixed>
     */
    private static function uzenet_adat(object $u, int $en, array $olvasottsag = [], ?array $tagok = null): array
    {
        global $wpdb;

        $valasz = null;

        if ((int) $u->valasz_id > 0) {
            $v = $wpdb->get_row($wpdb->prepare('SELECT id, felado_id, szoveg, torolve, forras FROM ' . self::t('uzenet') . ' WHERE id = %d', (int) $u->valasz_id));

            if ($v) {
                $valasz = [
                    'id'     => (int) $v->id,
                    'nev'    => self::felado_adat((int) $v->felado_id, (string) $v->forras)['nev'],
                    'szoveg' => (int) $v->torolve ? 'Visszavont üzenet' : mb_substr((string) $v->szoveg, 0, 140),
                ];
            }
        }

        $sajat = (int) $u->felado_id === $en;
        $latta = [];
        $nyugtazta = [];

        if ($sajat && $tagok !== null) {
            foreach ($tagok as $t) {
                if ($t !== $en && ($olvasottsag[$t] ?? 0) >= (int) $u->id) {
                    $latta[] = self::nev($t);
                }
            }
        }

        if ((int) $u->fontos === 1 && ($sajat || SDH_Muhely_Admin_UI::admin_e())) {
            $nyugtak = $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . self::t('nyugta') . ' WHERE uzenet_id = %d', (int) $u->id));

            foreach ((array) $nyugtak as $n) {
                $nyugtazta[] = self::nev((int) $n);
            }
        }

        $torolve = (int) $u->torolve === 1;

        return [
            'id'         => (int) $u->id,
            'besz'       => (int) $u->beszelgetes_id,
            'felado'     => self::felado_adat((int) $u->felado_id, (string) $u->forras),
            'sajat'      => $sajat,
            'szoveg'     => $torolve ? '' : (string) $u->szoveg,
            'torolve'    => $torolve,
            'ido'        => (string) $u->letrehozva,
            'ido_szoveg' => self::ido_szoveg((string) $u->letrehozva),
            'valasz'     => $valasz,
            'fontos'     => (int) $u->fontos === 1,
            'kituzve'    => (int) $u->kituzve === 1 && !$torolve,
            'hivatkozas' => self::hivatkozas((string) $u->hivatkozas),
            'forras'     => (string) $u->forras,
            'latta'      => $latta,
            'nyugtazta'  => $nyugtazta,
        ];
    }

    /** @return array<int, int> user_id => olvasott_id */
    private static function olvasottsag(int $besz): array
    {
        global $wpdb;

        $sorok = $wpdb->get_results($wpdb->prepare('SELECT user_id, olvasott_id FROM ' . self::t('tag') . ' WHERE beszelgetes_id = %d', $besz));
        $ki    = [];

        foreach ((array) $sorok as $s) {
            $ki[(int) $s->user_id] = (int) $s->olvasott_id;
        }

        return $ki;
    }

    private static function olvasottnak_jelol(int $besz, int $user, int $uzenet_id): void
    {
        global $wpdb;

        self::tag_biztosit($besz, $user);

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::t('tag') . ' SET olvasott_id = %d WHERE beszelgetes_id = %d AND user_id = %d AND olvasott_id < %d',
            $uzenet_id,
            $besz,
            $user,
            $uzenet_id
        ));
    }

    /**
     * Olvasatlan üzenetek száma beszélgetésenként.
     *
     * @param array<int, int> $idk
     * @return array<int, int>
     */
    private static function olvasatlanok(int $user, array $idk): array
    {
        global $wpdb;

        if ($idk === []) {
            return [];
        }

        $u   = self::t('uzenet');
        $tag = self::t('tag');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT u.beszelgetes_id AS b, COUNT(*) AS db
             FROM {$u} u
             LEFT JOIN {$tag} t ON t.beszelgetes_id = u.beszelgetes_id AND t.user_id = %d
             WHERE u.beszelgetes_id IN (" . self::in($idk) . ")
               AND u.torolve = 0 AND u.felado_id <> %d
               AND u.id > COALESCE(t.olvasott_id, 0)
             GROUP BY u.beszelgetes_id",
            array_merge([$user], $idk, [$user])
        ));
        // phpcs:enable

        $ki = [];

        foreach ((array) $sorok as $s) {
            $ki[(int) $s->b] = (int) $s->db;
        }

        return $ki;
    }

    /** Az oldalmenü jelvénye: a felhasználó összes olvasatlan üzenete. */
    public static function olvasatlan_db(): int
    {
        $en = get_current_user_id();

        return $en > 0 ? array_sum(self::olvasatlanok($en, self::elerheto($en))) : 0;
    }

    /**
     * Üzenet küldése – a felületről és más modulból (pl. az AI-asszisztens) is.
     *
     * @param array{valasz_id?: int, fontos?: bool, kituz?: bool, hivatkozas?: string, forras?: string} $opciok
     * @return array<string, mixed>|string Az üzenet adatai, vagy hibaszöveg.
     */
    public static function kuld(int $besz, int $felado, string $szoveg, array $opciok = [])
    {
        global $wpdb;

        $szoveg = trim($szoveg);

        if ($szoveg === '') {
            return 'Üres üzenetet nem lehet küldeni.';
        }

        $b = self::besz($besz);

        if ($b === null || !self::hozzafer($besz, $felado)) {
            return 'Ehhez a beszélgetéshez nincs hozzáférésed.';
        }

        $hiv = (string) ($opciok['hivatkozas'] ?? '');

        $wpdb->insert(self::t('uzenet'), [
            'beszelgetes_id' => $besz,
            'felado_id'      => $felado,
            'szoveg'         => mb_substr($szoveg, 0, 4000),
            'valasz_id'      => max(0, (int) ($opciok['valasz_id'] ?? 0)),
            'fontos'         => !empty($opciok['fontos']) ? 1 : 0,
            'kituzve'        => !empty($opciok['kituz']) ? 1 : 0,
            'hivatkozas'     => preg_match('/^(munkalap|ugyfel|eszkoz):\d+$/', $hiv) === 1 ? $hiv : '',
            'forras'         => sanitize_key((string) ($opciok['forras'] ?? 'kezi')),
            'letrehozva'     => self::most(),
        ]);

        $id = (int) $wpdb->insert_id;

        if ($id <= 0) {
            return 'Az üzenet mentése nem sikerült.';
        }

        $wpdb->update(self::t('beszelgetes'), ['utolso_ido' => self::most(), 'utolso_uzenet_id' => $id], ['id' => $besz]);
        self::olvasottnak_jelol($besz, $felado, $id);
        self::gepel_torol($besz, $felado);

        $sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('uzenet') . ' WHERE id = %d', $id));

        do_action('sdh_muhely_csapat_uzenet', $id, $besz, $felado);

        return self::uzenet_adat($sor, $felado);
    }

    /**
     * Címzettlistából beszélgetés: u:ID (kolléga), cs:ID (csoport), mindenki.
     *
     * @param array<int, string> $cimzettek
     * @return int|string A beszélgetés azonosítója, vagy hibaszöveg.
     */
    public static function besz_cimzettekbol(array $cimzettek, int $en, string $nev = '')
    {
        $szemelyek = [];
        $csoportok = [];
        $mindenki  = false;
        $kollegak  = self::kollegak();

        foreach ($cimzettek as $c) {
            $c = (string) $c;

            if ($c === 'mindenki') {
                $mindenki = true;
            } elseif (preg_match('/^u:(\d+)$/', $c, $m) === 1 && isset($kollegak[(int) $m[1]])) {
                $szemelyek[] = (int) $m[1];
            } elseif (preg_match('/^cs:(\d+)$/', $c, $m) === 1 && isset(self::csoportok()[(int) $m[1]])) {
                $csoportok[] = (int) $m[1];
            }
        }

        $szemelyek = array_values(array_unique(array_filter($szemelyek, static fn (int $u): bool => $u !== $en)));
        $csoportok = array_values(array_unique($csoportok));

        if ($mindenki) {
            return self::mindenki_id();
        }

        if ($szemelyek === [] && $csoportok === []) {
            return 'Válassz legalább egy címzettet.';
        }

        if ($szemelyek === [] && count($csoportok) === 1) {
            $id = self::csoport_besz_id($csoportok[0]);

            if (!in_array($en, self::csoport_tagjai($csoportok[0]), true)) {
                self::tag_biztosit($id, $en, true); // a nem tag küldő is látja a választ
                unset(self::$elerheto[$en]);
            }

            return $id;
        }

        if ($csoportok === [] && count($szemelyek) === 1) {
            $id = self::ketto_id($en, $szemelyek[0]);
            unset(self::$elerheto[$en]);

            return $id;
        }

        foreach ($csoportok as $cs) {
            $szemelyek = array_merge($szemelyek, self::csoport_tagjai($cs));
        }

        $szemelyek[] = $en;
        $id = self::uj_beszelgetes('egyedi', $nev, array_values(array_unique($szemelyek)));
        unset(self::$elerheto[$en]);

        return $id;
    }

    /* =================================================================
     * Gépelés-jelzés
     * ============================================================== */

    private static function gepel_kulcs(int $besz): string
    {
        return 'sdh_cs_gepel_' . $besz;
    }

    private static function gepel_torol(int $besz, int $user): void
    {
        $g = get_transient(self::gepel_kulcs($besz));

        if (is_array($g) && isset($g[$user])) {
            unset($g[$user]);
            set_transient(self::gepel_kulcs($besz), $g, 60);
        }
    }

    /** @return array<int, string> */
    private static function gepelok(int $besz, int $en): array
    {
        $g  = get_transient(self::gepel_kulcs($besz));
        $ki = [];

        foreach (is_array($g) ? $g : [] as $u => $ido) {
            if ((int) $u !== $en && time() - (int) $ido < 7) {
                $ki[] = self::nev((int) $u);
            }
        }

        return $ki;
    }

    /* =================================================================
     * AJAX – beszélgetések
     * ============================================================== */

    /** A beszélgetéslista, a kollégák és a csoportok. */
    public static function ajax_lista(): void
    {
        self::jog();

        global $wpdb;

        $en  = get_current_user_id();
        $idk = self::elerheto($en);
        $olv = self::olvasatlanok($en, $idk);
        $ki  = [];

        if ($idk !== []) {
            $t = self::t('beszelgetes');
            $u = self::t('uzenet');

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $sorok = $wpdb->get_results($wpdb->prepare(
                "SELECT b.*, u.szoveg AS u_szoveg, u.felado_id AS u_felado, u.torolve AS u_torolve, u.letrehozva AS u_ido, u.forras AS u_forras
                 FROM {$t} b LEFT JOIN {$u} u ON u.id = b.utolso_uzenet_id
                 WHERE b.id IN (" . self::in($idk) . ')
                 ORDER BY b.utolso_ido DESC, b.id DESC',
                $idk
            ));
            // phpcs:enable

            foreach ((array) $sorok as $b) {
                // Üres kettes beszélgetés (még senki nem írt) nem kell a listába.
                if ((int) $b->utolso_uzenet_id === 0 && in_array($b->tipus, ['ketto', 'egyedi'], true) && (int) $b->letrehozo !== $en) {
                    continue;
                }

                $m = self::besz_megjelenes($b, $en);

                $ki[] = [
                    'id'      => (int) $b->id,
                    'tipus'   => (string) $b->tipus,
                    'nev'     => $m['nev'],
                    'alcim'   => $m['alcim'],
                    'kep'     => $m['kep'],
                    'online'  => $m['online'],
                    'olvasatlan' => (int) ($olv[(int) $b->id] ?? 0),
                    'utolso'  => (int) $b->utolso_uzenet_id > 0 ? [
                        'nev'    => (int) $b->u_felado === $en ? 'Te' : self::felado_adat((int) $b->u_felado, (string) $b->u_forras)['nev'],
                        'szoveg' => (int) $b->u_torolve ? 'Visszavont üzenet' : mb_substr(preg_replace('/\s+/u', ' ', (string) $b->u_szoveg) ?? '', 0, 90),
                        'ido'    => self::ido_szoveg((string) $b->u_ido),
                    ] : null,
                ];
            }
        }

        $kollegak = [];

        foreach (self::kollegak() as $k) {
            if ($k['id'] === $en) {
                continue;
            }

            $kollegak[] = array_intersect_key($k, array_flip(['id', 'nev', 'betu', 'szin', 'titulus', 'online']));
        }

        $csoportok = [];

        foreach (self::csoportok() as $cs) {
            $csoportok[] = ['id' => (int) $cs->id, 'nev' => (string) $cs->nev, 'szin' => (string) $cs->szin, 'db' => count(self::csoport_tagjai((int) $cs->id))];
        }

        $en_adat = self::kollega($en);

        wp_send_json_success([
            'en'           => $en_adat ? array_intersect_key($en_adat, array_flip(['id', 'nev', 'betu', 'szin'])) : ['id' => $en, 'nev' => '', 'betu' => '?', 'szin' => 'kek'],
            'beszelgetesek' => $ki,
            'kollegak'     => $kollegak,
            'csoportok'    => $csoportok,
        ]);
    }

    /** Egy beszélgetés üzenetei (a legújabbak, vagy egy üzenet előttiek). */
    public static function ajax_szal(): void
    {
        self::jog();

        global $wpdb;

        $en    = get_current_user_id();
        $besz  = (int) self::g('besz', 20);
        $elott = (int) self::g('elotte', 20);

        // Kollégára kattintva: a kettes beszélgetés (ha még nincs, létrejön).
        $kivel = (int) self::g('kivel', 20);

        if ($besz <= 0 && $kivel > 0) {
            if (!isset(self::kollegak()[$kivel]) || $kivel === $en) {
                wp_send_json_error(['uzenet' => 'Ismeretlen kolléga.']);
            }

            $besz = self::ketto_id($en, $kivel);
            unset(self::$elerheto[$en]);
        }

        $csop = (int) self::g('csoport', 20);

        if ($besz <= 0 && $csop > 0) {
            $r = self::besz_cimzettekbol(['cs:' . $csop], $en);
            $besz = is_int($r) ? $r : 0;
        }

        if ($besz <= 0 && self::g('mindenki', 2) === '1') {
            $besz = self::mindenki_id();
        }

        $b = self::besz($besz);

        if ($b === null || !self::hozzafer($besz, $en)) {
            wp_send_json_error(['uzenet' => 'Ez a beszélgetés nem érhető el.']);
        }

        $u = self::t('uzenet');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sorok = $elott > 0
            ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$u} WHERE beszelgetes_id = %d AND id < %d ORDER BY id DESC LIMIT %d", $besz, $elott, self::OLDAL + 1))
            : $wpdb->get_results($wpdb->prepare("SELECT * FROM {$u} WHERE beszelgetes_id = %d ORDER BY id DESC LIMIT %d", $besz, self::OLDAL + 1));
        $kituzott = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$u} WHERE beszelgetes_id = %d AND kituzve = 1 AND torolve = 0 ORDER BY id DESC LIMIT 10", $besz));
        // phpcs:enable

        $sorok = (array) $sorok;
        $tobb  = count($sorok) > self::OLDAL;
        $sorok = array_reverse(array_slice($sorok, 0, self::OLDAL));
        $olv   = self::olvasottsag($besz);
        $tagok = self::besz_tagok($b);

        $uzenetek = array_map(static fn ($s) => self::uzenet_adat($s, $en, $olv, $tagok), $sorok);

        if ($elott <= 0 && $sorok !== []) {
            self::olvasottnak_jelol($besz, $en, (int) end($sorok)->id);
        }

        $m = self::besz_megjelenes($b, $en);
        $tag_adat = [];

        foreach ($tagok as $t) {
            $k = self::kollega($t);

            if ($k) {
                $tag_adat[] = array_intersect_key($k, array_flip(['id', 'nev', 'betu', 'szin', 'online']));
            }
        }

        wp_send_json_success([
            'besz'     => ['id' => $besz, 'tipus' => (string) $b->tipus, 'nev' => $m['nev'], 'alcim' => $m['alcim'], 'kep' => $m['kep'], 'online' => $m['online'], 'tagok' => $tag_adat],
            'uzenetek' => $uzenetek,
            'tobb'     => $tobb,
            'kituzott' => array_map(static fn ($s) => self::uzenet_adat($s, $en), (array) $kituzott),
        ]);
    }

    /** Élő frissítés egy nyitott beszélgetésben: új üzenetek, gépelés, olvasottság. */
    public static function ajax_frissit(): void
    {
        self::jog();

        global $wpdb;

        $en    = get_current_user_id();
        $besz  = (int) self::g('besz', 20);
        $utana = (int) self::g('utana', 20);
        $b     = self::besz($besz);

        if ($b === null || !self::hozzafer($besz, $en)) {
            wp_send_json_error(['uzenet' => 'Ez a beszélgetés nem érhető el.']);
        }

        $u = self::t('uzenet');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sorok = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$u} WHERE beszelgetes_id = %d AND id > %d ORDER BY id ASC LIMIT 100", $besz, $utana));
        // A már látott üzenetek változása (visszavonás, kitűzés) – az utolsó 40-ből.
        $valtozott = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$u} WHERE beszelgetes_id = %d AND id <= %d AND modositva IS NOT NULL AND modositva >= %s ORDER BY id DESC LIMIT 40",
            $besz,
            $utana,
            wp_date('Y-m-d H:i:s', current_time('timestamp', true) - 60)
        ));
        // phpcs:enable

        $olv   = self::olvasottsag($besz);
        $tagok = self::besz_tagok($b);

        if ($sorok !== []) {
            self::olvasottnak_jelol($besz, $en, (int) end($sorok)->id);
        }

        // A saját üzenetek „látta" jelzése a teljes látható szakaszra.
        $latta = [];

        foreach ($tagok as $t) {
            if ($t !== $en && isset($olv[$t])) {
                $latta[] = ['nev' => self::nev($t), 'olvasott' => $olv[$t]];
            }
        }

        wp_send_json_success([
            'uzenetek'  => array_map(static fn ($s) => self::uzenet_adat($s, $en, $olv, $tagok), $sorok),
            'valtozott' => array_map(static fn ($s) => self::uzenet_adat($s, $en, $olv, $tagok), $valtozott),
            'gepel'     => self::gepelok($besz, $en),
            'latta'     => $latta,
        ]);
    }

    public static function ajax_kuld(): void
    {
        self::jog();

        $en     = get_current_user_id();
        $szoveg = self::szoveg_be('szoveg');
        $besz   = (int) self::g('besz', 20);

        if ($besz <= 0) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a jog() ellenőrzi.
            $nyers = isset($_POST['cimzett']) ? (array) wp_unslash($_POST['cimzett']) : [];
            $cimzettek = array_map(static fn ($c): string => sanitize_text_field((string) $c), $nyers);
            $r = self::besz_cimzettekbol($cimzettek, $en, self::g('nev', 190));

            if (is_string($r)) {
                wp_send_json_error(['uzenet' => $r]);
            }

            $besz = $r;
        }

        $uz = self::kuld($besz, $en, $szoveg, [
            'valasz_id'  => (int) self::g('valasz_id', 20),
            'fontos'     => self::g('fontos', 2) === '1',
            'kituz'      => self::g('kituz', 2) === '1',
            'hivatkozas' => self::g('hivatkozas', 60),
        ]);

        if (is_string($uz)) {
            wp_send_json_error(['uzenet' => $uz]);
        }

        // A popupból (Új üzenet) küldve az app.js a „vissza" címre lép: a beszélgetés nyílik meg.
        wp_send_json_success([
            'besz'    => $besz,
            'uzenet'  => $uz,
            'vissza'  => SDH_Muhely_Modulok::url(self::KULCS, ['besz' => $besz]),
        ]);
    }

    public static function ajax_gepel(): void
    {
        self::jog();

        $en   = get_current_user_id();
        $besz = (int) self::g('besz', 20);

        if (!self::hozzafer($besz, $en)) {
            wp_send_json_error([]);
        }

        $g = get_transient(self::gepel_kulcs($besz));
        $g = is_array($g) ? $g : [];
        $g[$en] = time();
        set_transient(self::gepel_kulcs($besz), $g, 60);

        wp_send_json_success([]);
    }

    public static function ajax_olvasott(): void
    {
        self::jog();

        $en   = get_current_user_id();
        $besz = (int) self::g('besz', 20);
        $id   = (int) self::g('id', 20);

        if (self::hozzafer($besz, $en) && $id > 0) {
            self::olvasottnak_jelol($besz, $en, $id);
        }

        wp_send_json_success(['olvasatlan' => self::olvasatlan_db()]);
    }

    private static function uzenet_sor(int $id): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('uzenet') . ' WHERE id = %d', $id)) ?: null;
    }

    /** A fontos üzenet nyugtázása („Elolvastam"). */
    public static function ajax_nyugta(): void
    {
        self::jog();

        global $wpdb;

        $en = get_current_user_id();
        $u  = self::uzenet_sor((int) self::g('id', 20));

        if ($u === null || !self::hozzafer((int) $u->beszelgetes_id, $en)) {
            wp_send_json_error(['uzenet' => 'Ez az üzenet nem érhető el.']);
        }

        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::t('nyugta') . ' (uzenet_id, user_id, ido) VALUES (%d, %d, %s)',
            (int) $u->id,
            $en,
            self::most()
        ));
        self::olvasottnak_jelol((int) $u->beszelgetes_id, $en, (int) $u->id);

        wp_send_json_success(['olvasatlan' => self::olvasatlan_db()]);
    }

    /** Kitűzés / levétel: a küldő és az adminisztrátor teheti. */
    public static function ajax_kituz(): void
    {
        self::jog();

        global $wpdb;

        $en = get_current_user_id();
        $u  = self::uzenet_sor((int) self::g('id', 20));

        if ($u === null || !self::hozzafer((int) $u->beszelgetes_id, $en)) {
            wp_send_json_error(['uzenet' => 'Ez az üzenet nem érhető el.']);
        }

        if ((int) $u->felado_id !== $en && !SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Csak a saját üzenetedet tűzheted ki (vagy adminisztrátor).']);
        }

        $wpdb->update(self::t('uzenet'), ['kituzve' => self::g('be', 2) === '1' ? 1 : 0, 'modositva' => self::most()], ['id' => (int) $u->id]);

        wp_send_json_success([]);
    }

    /** Saját üzenet visszavonása (jelölés – a szöveg nem látszik, a nyoma megmarad). */
    public static function ajax_visszavon(): void
    {
        self::jog();

        global $wpdb;

        $en = get_current_user_id();
        $u  = self::uzenet_sor((int) self::g('id', 20));

        if ($u === null || (int) $u->felado_id !== $en) {
            wp_send_json_error(['uzenet' => 'Csak a saját üzenetedet vonhatod vissza.']);
        }

        $wpdb->update(self::t('uzenet'), ['torolve' => 1, 'kituzve' => 0, 'modositva' => self::most()], ['id' => (int) $u->id]);

        wp_send_json_success([]);
    }

    /* =================================================================
     * Pulzus – minden oldalon
     * ============================================================== */

    /**
     * Olvasatlanok, új üzenetek (felugró kártyához), nyugtázatlan fontos
     * üzenetek. Az „utana" az utoljára látott üzenet azonosítója; 0-nál
     * csak a kiindulópontot adjuk vissza (régi üzenet nem ugrik fel).
     */
    public static function ajax_pulzus(): void
    {
        self::jog();

        global $wpdb;

        $en    = get_current_user_id();
        $utana = (int) self::g('utana', 20);
        $idk   = self::elerheto($en);

        // Jelenlét: legfeljebb percenként írunk.
        $utoljara = (int) get_user_meta($en, 'sdh_utoljara', true);

        if (time() - $utoljara > 60) {
            update_user_meta($en, 'sdh_utoljara', time());
        }

        $u   = self::t('uzenet');
        $tag = self::t('tag');
        $ny  = self::t('nyugta');
        $uj  = [];
        $max = 0;
        $fontos = [];

        if ($idk !== []) {
            $in = self::in($idk);

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $max = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM {$u} WHERE beszelgetes_id IN ({$in})", $idk));

            if ($utana > 0 && $max > $utana) {
                $sorok = $wpdb->get_results($wpdb->prepare(
                    "SELECT u.* FROM {$u} u
                     LEFT JOIN {$tag} t ON t.beszelgetes_id = u.beszelgetes_id AND t.user_id = %d
                     WHERE u.beszelgetes_id IN ({$in}) AND u.id > %d AND u.felado_id <> %d AND u.torolve = 0
                       AND u.fontos = 0 AND u.id > COALESCE(t.olvasott_id, 0)
                     ORDER BY u.id DESC LIMIT 5",
                    array_merge([$en], $idk, [$utana, $en])
                ));

                foreach (array_reverse((array) $sorok) as $s) {
                    $adat = self::uzenet_adat($s, $en);
                    $b    = self::besz((int) $s->beszelgetes_id);
                    $adat['besz_nev'] = $b ? self::besz_megjelenes($b, $en)['nev'] : '';
                    $adat['besz_tipus'] = $b ? (string) $b->tipus : '';
                    $uj[] = $adat;
                }
            }

            $f = get_userdata($en);
            $regisztralt = $f ? (string) $f->user_registered : '1970-01-01 00:00:00';

            $fontos_sorok = $wpdb->get_results($wpdb->prepare(
                "SELECT u.* FROM {$u} u
                 LEFT JOIN {$ny} n ON n.uzenet_id = u.id AND n.user_id = %d
                 WHERE u.beszelgetes_id IN ({$in}) AND u.fontos = 1 AND u.torolve = 0 AND u.felado_id <> %d
                   AND n.id IS NULL AND u.letrehozva >= %s
                 ORDER BY u.id ASC LIMIT 5",
                array_merge([$en], $idk, [$en, get_date_from_gmt($regisztralt)])
            ));
            // phpcs:enable

            foreach ((array) $fontos_sorok as $s) {
                $adat = self::uzenet_adat($s, $en);
                $b    = self::besz((int) $s->beszelgetes_id);
                $adat['besz_nev'] = $b ? self::besz_megjelenes($b, $en)['nev'] : '';
                $adat['besz_tipus'] = $b ? (string) $b->tipus : '';
                $fontos[] = $adat;
            }
        }

        $valasz = [
            'olvasatlan' => array_sum(self::olvasatlanok($en, $idk)),
            'max'        => $max,
            'uj'         => $uj,
            'fontos'     => $fontos,
            'csapatUrl'  => SDH_Muhely_Modulok::url(self::KULCS),
        ];

        /** Más modul (AI-asszisztens) itt teheti hozzá a sajátját. */
        $valasz = apply_filters('sdh_muhely_pulzus', $valasz, $en);

        wp_send_json_success($valasz);
    }

    /* =================================================================
     * Oldal
     * ============================================================== */

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        $admin = SDH_Muhely_Admin_UI::admin_e();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nezet = isset($_GET['nezet']) ? sanitize_key(wp_unslash($_GET['nezet'])) : '';
        $nezet = $admin && in_array($nezet, ['kollegak', 'csoportok'], true) ? $nezet : 'uzenetek';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $besz  = isset($_GET['besz']) ? (int) $_GET['besz'] : 0;

        ?>
        <div class="sdh-wrap sdh-cs" data-sdh-csapat-oldal data-nezet="<?php echo esc_attr($nezet); ?>">
            <?php
            SDH_Muhely_Admin_UI::uzenet();

            $gombok = [[
                'cimke'      => '+ Új üzenet',
                'url'        => SDH_Muhely_Modulok::url(self::KULCS),
                'elsodleges' => true,
                'adatok'     => ['sdh-urlap' => 'csapatuj', 'sdh-id' => '0'],
            ]];

            if ($admin && $nezet === 'kollegak') {
                $gombok = [['cimke' => '+ Új kolléga', 'url' => '#', 'elsodleges' => true, 'adatok' => ['sdh-urlap' => 'csapatkollega', 'sdh-id' => '0']]];
            } elseif ($admin && $nezet === 'csoportok') {
                $gombok = [['cimke' => '+ Új csoport', 'url' => '#', 'elsodleges' => true, 'adatok' => ['sdh-urlap' => 'csapatcsoport', 'sdh-id' => '0']]];
            }

            SDH_Muhely_Admin_UI::fejlec(
                'Csapat',
                'Belső üzenetek a kollégáknak – személyesen, csoportnak vagy mindenkinek. A fontos üzenet felugró ablakban jelenik meg, amíg el nem olvassák.',
                $gombok
            );
            ?>

            <?php if ($admin) : ?>
                <nav class="sdh-cs-fulek" aria-label="Csapat nézetek">
                    <?php
                    foreach (['uzenetek' => 'Beszélgetések', 'kollegak' => 'Kollégák', 'csoportok' => 'Csoportok'] as $k => $c) {
                        printf(
                            '<a class="sdh-cs-ful%s" href="%s">%s</a>',
                            $k === $nezet ? ' is-aktiv' : '',
                            esc_url(SDH_Muhely_Modulok::url(self::KULCS, $k === 'uzenetek' ? [] : ['nezet' => $k])),
                            esc_html($c)
                        );
                    }
                    ?>
                </nav>
            <?php endif; ?>

            <?php
            if ($nezet === 'kollegak') {
                self::kollegak_nezet();
            } elseif ($nezet === 'csoportok') {
                self::csoportok_nezet();
            } else {
                ?>
                <div class="sdh-cs-app" data-sdh-cs-app data-besz="<?php echo (int) $besz; ?>">
                    <section class="sdh-cs-oldalsav" aria-label="Beszélgetések">
                        <div class="sdh-cs-kereso">
                            <input type="search" placeholder="Keresés: kolléga, csoport…" autocomplete="off" data-sdh-cs-kereso aria-label="Beszélgetések szűrése">
                        </div>
                        <div class="sdh-cs-lista" data-sdh-cs-lista><div class="sdh-cs-toltes">Betöltés…</div></div>
                        <div class="sdh-cs-lapozo" data-sdh-cs-lapozo hidden></div>
                    </section>
                    <section class="sdh-cs-szal" data-sdh-cs-szal aria-live="polite">
                        <div class="sdh-cs-ures">
                            <div class="sdh-cs-ures__jel" aria-hidden="true"></div>
                            <p><strong>Válassz beszélgetést</strong> a bal oldalon, vagy írj újat a „+ Új üzenet" gombbal.</p>
                        </div>
                    </section>
                </div>
                <?php
            }
            ?>
        </div>
        <?php
    }

    private static function lapozo(int $oldal, int $osszes, string $nezet): void
    {
        $oldalak = (int) ceil($osszes / self::LISTA_OLDAL);

        if ($oldalak <= 1) {
            return;
        }

        $nyil = static fn (string $d): string => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="' . $d . '"/></svg>';

        echo '<nav class="sdh-cs-lapozo sdh-cs-lapozo--lap" aria-label="Lapozás">';

        if ($oldal > 1) {
            printf('<a class="sdh-gomb sdh-lapnyil" href="%s" aria-label="Előző oldal">%s</a>', esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => $nezet, 'lap' => $oldal - 1])), $nyil('M10 3 5 8l5 5')); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        printf('<span>%d / %d</span>', (int) $oldal, (int) $oldalak);

        if ($oldal < $oldalak) {
            printf('<a class="sdh-gomb sdh-lapnyil" href="%s" aria-label="Következő oldal">%s</a>', esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => $nezet, 'lap' => $oldal + 1])), $nyil('M6 3l5 5-5 5')); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '</nav>';
    }

    private static function avatar(string $betu, string $szin, bool $online = false, string $extra = ''): string
    {
        return sprintf(
            '<span class="sdh-cs-av sdh-cs-av--%s%s"%s><span>%s</span></span>',
            esc_attr(isset(self::SZINEK[$szin]) ? $szin : 'kek'),
            $online ? ' is-online' : '',
            $extra,
            esc_html($betu)
        );
    }

    private static function kollegak_nezet(): void
    {
        $en   = get_current_user_id();
        // Az imént felvett kolléga jelszava egyszer látszik.
        $jelszo = get_transient('sdh_cs_jelszo_' . $en);

        if (is_array($jelszo)) {
            delete_transient('sdh_cs_jelszo_' . $en);
            ?>
            <div class="sdh-cs-jelszo" role="status">
                <strong>Belépési adatok – most látszik utoljára, add át a kollégának:</strong>
                <span><?php echo esc_html((string) $jelszo['nev']); ?></span>
                <span>Felhasználónév: <code><?php echo esc_html((string) $jelszo['login']); ?></code></span>
                <span>Jelszó: <code><?php echo esc_html((string) $jelszo['jelszo']); ?></code></span>
                <span>Belépés: <code><?php echo esc_html(wp_login_url()); ?></code></span>
            </div>
            <?php
        }

        $mind  = array_values(self::kollegak(true));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $oldal = max(1, isset($_GET['lap']) ? (int) $_GET['lap'] : 1);
        $resz  = array_slice($mind, ($oldal - 1) * self::LISTA_OLDAL, self::LISTA_OLDAL);
        $csop  = self::csoportok(true);

        ?>
        <div class="sdh-cs-kartyak">
            <?php foreach ($resz as $k) : ?>
                <?php
                $tagsag = [];

                foreach (self::sajat_csoportok($k['id']) as $c) {
                    if (isset($csop[$c])) {
                        $tagsag[] = (string) $csop[$c]->nev;
                    }
                }
                ?>
                <article class="sdh-cs-kartya<?php echo $k['letiltva'] ? ' is-letiltva' : ''; ?>">
                    <?php echo self::avatar($k['betu'], $k['szin'], $k['online'] && !$k['letiltva']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <div class="sdh-cs-kartya__test">
                        <h3><?php echo esc_html($k['nev']); ?><?php echo $k['id'] === $en ? ' <small>(te)</small>' : ''; ?></h3>
                        <p><?php echo esc_html($k['titulus'] !== '' ? $k['titulus'] : ($k['admin'] ? 'Adminisztrátor' : 'Kolléga')); ?></p>
                        <p class="sdh-cs-kartya__halvany"><?php echo esc_html($k['email']); ?> · <?php echo esc_html($k['login']); ?></p>
                        <p class="sdh-cs-kartya__cimkek">
                            <span class="sdh-cs-cimke<?php echo $k['admin'] ? ' sdh-cs-cimke--admin' : ''; ?>"><?php echo $k['admin'] ? 'Admin' : 'Kolléga'; ?></span>
                            <?php if ($k['letiltva']) : ?><span class="sdh-cs-cimke sdh-cs-cimke--tilt">Letiltva</span><?php endif; ?>
                            <?php foreach ($tagsag as $t) : ?><span class="sdh-cs-cimke"><?php echo esc_html($t); ?></span><?php endforeach; ?>
                        </p>
                    </div>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="#" data-sdh-urlap="csapatkollega" data-sdh-id="<?php echo (int) $k['id']; ?>">Szerkesztés</a>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        self::lapozo($oldal, count($mind), 'kollegak');
    }

    private static function csoportok_nezet(): void
    {
        $mind  = array_values(self::csoportok(true));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $oldal = max(1, isset($_GET['lap']) ? (int) $_GET['lap'] : 1);
        $resz  = array_slice($mind, ($oldal - 1) * self::LISTA_OLDAL, self::LISTA_OLDAL);
        $kollegak = self::kollegak(true);

        if ($mind === []) {
            echo '<div class="sdh-doboz"><p class="sdh-sugo sdh-sugo--utolso">Még nincs csoport. Hozz létre egyet (pl. „Szerelők", „Pult"), és válaszd ki a tagjait – a csoportnak saját beszélgetése lesz.</p></div>';

            return;
        }

        ?>
        <div class="sdh-cs-kartyak">
            <?php foreach ($resz as $cs) : ?>
                <?php $tagok = self::csoport_tagjai((int) $cs->id); ?>
                <article class="sdh-cs-kartya<?php echo (int) $cs->aktiv ? '' : ' is-letiltva'; ?>">
                    <?php echo self::avatar(self::monogram((string) $cs->nev), (string) $cs->szin); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <div class="sdh-cs-kartya__test">
                        <h3><?php echo esc_html((string) $cs->nev); ?></h3>
                        <p><?php echo esc_html((string) $cs->leiras !== '' ? (string) $cs->leiras : count($tagok) . ' tag'); ?></p>
                        <p class="sdh-cs-avsor">
                            <?php
                            foreach (array_slice($tagok, 0, 8) as $t) {
                                if (isset($kollegak[$t])) {
                                    echo self::avatar($kollegak[$t]['betu'], $kollegak[$t]['szin'], false, ' title="' . esc_attr($kollegak[$t]['nev']) . '"'); // phpcs:ignore WordPress.Security.EscapeOutput
                                }
                            }
                            ?>
                        </p>
                        <?php if (!(int) $cs->aktiv) : ?><p class="sdh-cs-kartya__cimkek"><span class="sdh-cs-cimke sdh-cs-cimke--tilt">Archivált</span></p><?php endif; ?>
                    </div>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="#" data-sdh-urlap="csapatcsoport" data-sdh-id="<?php echo (int) $cs->id; ?>">Szerkesztés</a>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        self::lapozo($oldal, count($mind), 'csoportok');
    }

    /* =================================================================
     * Popupok
     * ============================================================== */

    /** Pillek (rádió vagy jelölő) – natív lenyíló helyett. */
    private static function pillek(string $nev, array $elemek, array $kijelolt, bool $tobb = false, string $extra = ''): void
    {
        echo '<div class="sdh-cs-pillek"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput

        foreach ($elemek as $ertek => $elem) {
            $cimke = is_array($elem) ? (string) $elem['cimke'] : (string) $elem;
            $elo   = is_array($elem) ? (string) ($elem['elo'] ?? '') : '';
            $be    = in_array((string) $ertek, array_map('strval', $kijelolt), true);

            printf(
                '<label class="sdh-cs-pill%5$s"><input type="%1$s" name="%2$s" value="%3$s"%4$s>%6$s<span>%7$s</span></label>',
                $tobb ? 'checkbox' : 'radio',
                esc_attr($nev . ($tobb ? '[]' : '')),
                esc_attr((string) $ertek),
                $be ? ' checked' : '',
                $be ? ' is-aktiv' : '',
                $elo, // phpcs:ignore WordPress.Security.EscapeOutput -- saját, már escape-elt avatar.
                esc_html($cimke)
            );
        }

        echo '</div>';
    }

    /** Új üzenet: címzettek pillként, szöveg, fontos / kitűzés. */
    public static function ajax_uj_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }

        $en    = get_current_user_id();
        $elemek = ['mindenki' => ['cimke' => 'Mindenki', 'elo' => self::avatar('∞', 'kek')]];

        foreach (self::csoportok() as $cs) {
            $elemek['cs:' . (int) $cs->id] = ['cimke' => (string) $cs->nev . ' (csoport)', 'elo' => self::avatar(self::monogram((string) $cs->nev), (string) $cs->szin)];
        }

        foreach (self::kollegak() as $k) {
            if ($k['id'] !== $en) {
                $elemek['u:' . $k['id']] = ['cimke' => $k['nev'], 'elo' => self::avatar($k['betu'], $k['szin'], $k['online'])];
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $elore = isset($_GET['cimzett']) ? [sanitize_text_field(wp_unslash((string) $_GET['cimzett']))] : [];

        ?>
        <form class="sdh-urlap sdh-cs-urlap" data-sdh-ajax-action="sdh_muhely_csapat_kuld" novalidate>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <h2 class="sdh-modal__cim">Új üzenet</h2>

            <div class="sdh-ig">
                <span class="sdh-ig__cimke">Kinek <span class="sdh-kotelezo">*</span></span>
                <?php self::pillek('cimzett', $elemek, $elore, true, ' data-sdh-cs-cimzettek'); ?>
                <span class="sdh-mezo__sugo">Több kollégát is választhatsz – közös beszélgetés lesz belőle. Csoport: a csoport beszélgetésébe megy.</span>
            </div>

            <div class="sdh-ig" data-sdh-cs-nevmezo hidden>
                <label for="cs_nev">A közös beszélgetés neve (nem kötelező)</label>
                <input type="text" name="nev" id="cs_nev" maxlength="190" autocomplete="off" placeholder="pl. Hétvégi ügyelet">
            </div>

            <div class="sdh-ig">
                <label for="cs_szoveg">Üzenet <span class="sdh-kotelezo">*</span></label>
                <textarea name="szoveg" id="cs_szoveg" rows="6" maxlength="4000" placeholder="Írd ide az üzenetet…"></textarea>
            </div>

            <div class="sdh-cs-kapcsolok">
                <label class="sdh-cs-kapcs"><input type="checkbox" name="fontos" value="1"><span class="sdh-cs-kapcs__sin"></span><span><strong>Fontos</strong> – felugró ablakban jelenik meg, amíg el nem olvassák</span></label>
                <label class="sdh-cs-kapcs"><input type="checkbox" name="kituz" value="1"><span class="sdh-cs-kapcs__sin"></span><span><strong>Kitűzés</strong> – a beszélgetés tetején marad (üzenet hagyása)</span></label>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Küldés</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            </div>
        </form>
        <?php
        wp_die();
    }

    public static function ajax_kollega_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Kollégát csak adminisztrátor vehet fel.</div>';
            wp_die();
        }

        $id = (int) self::g('id', 20);
        $f  = $id > 0 ? get_userdata($id) : null;
        $k  = $f instanceof WP_User ? self::kollega_adat($f) : null;
        $en = get_current_user_id() === $id;

        $szinek = [];

        foreach (self::SZINEK as $sz => $nev) {
            $szinek[$sz] = ['cimke' => $nev, 'elo' => self::avatar('', $sz)];
        }

        $csop = [];

        foreach (self::csoportok() as $cs) {
            $csop[(int) $cs->id] = (string) $cs->nev;
        }

        ?>
        <form class="sdh-urlap sdh-cs-urlap" data-sdh-ajax-action="sdh_muhely_csapatkollega_ment" novalidate>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <h2 class="sdh-modal__cim"><?php echo $k ? esc_html($k['nev']) : 'Új kolléga'; ?></h2>

            <div class="sdh-cs-racs">
                <div>
                    <div class="sdh-ig">
                        <label for="ck_nev">Név <span class="sdh-kotelezo">*</span></label>
                        <input type="text" name="nev" id="ck_nev" maxlength="120" value="<?php echo esc_attr($k['nev'] ?? ''); ?>" autocomplete="off">
                    </div>
                    <div class="sdh-ig">
                        <label for="ck_email">E-mail <span class="sdh-kotelezo">*</span></label>
                        <input type="text" name="email" id="ck_email" maxlength="190" inputmode="email" value="<?php echo esc_attr($k['email'] ?? ''); ?>" autocomplete="off">
                    </div>
                    <div class="sdh-ig">
                        <label for="ck_login">Felhasználónév <?php echo $k ? '' : '<span class="sdh-kotelezo">*</span>'; ?></label>
                        <input type="text" name="login" id="ck_login" maxlength="60" value="<?php echo esc_attr($k['login'] ?? ''); ?>" autocomplete="off"<?php echo $k ? ' readonly' : ''; ?>>
                        <?php if ($k) : ?><span class="sdh-mezo__sugo">A felhasználónév nem változtatható.</span><?php endif; ?>
                    </div>
                    <div class="sdh-ig">
                        <label for="ck_jelszo"><?php echo $k ? 'Új jelszó (csak ha cserélni akarod)' : 'Jelszó'; ?></label>
                        <input type="text" name="jelszo" id="ck_jelszo" maxlength="100" autocomplete="new-password" placeholder="<?php echo $k ? 'üresen: marad a régi' : 'üresen: a rendszer generál erőset'; ?>">
                    </div>
                    <div class="sdh-ig">
                        <label for="ck_titulus">Beosztás</label>
                        <input type="text" name="titulus" id="ck_titulus" maxlength="80" value="<?php echo esc_attr($k['titulus'] ?? ''); ?>" placeholder="pl. szerelő, pultos, ügyvezető" autocomplete="off">
                    </div>
                </div>
                <div>
                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Szerep</span>
                        <?php if ($en) : ?>
                            <p class="sdh-mezo__sugo">A saját szerepedet és állapotodat nem módosíthatod (nehogy kizárd magad).</p>
                        <?php else : ?>
                            <?php self::pillek('szerep', ['kollega' => 'Kolléga – napi munka a CRM-ben', 'admin' => 'Adminisztrátor – beállítások, kollégák, WordPress'], [$k && $k['admin'] ? 'admin' : 'kollega']); ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!$en) : ?>
                        <div class="sdh-ig">
                            <span class="sdh-ig__cimke">Állapot</span>
                            <?php self::pillek('allapot', ['aktiv' => 'Aktív', 'letiltva' => 'Letiltva (nem léphet be)'], [$k && $k['letiltva'] ? 'letiltva' : 'aktiv']); ?>
                        </div>
                    <?php endif; ?>
                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Szín</span>
                        <?php self::pillek('szin', $szinek, [$k['szin'] ?? 'kek']); ?>
                    </div>
                    <?php if ($csop !== []) : ?>
                        <div class="sdh-ig">
                            <span class="sdh-ig__cimke">Csoportok</span>
                            <?php self::pillek('csoport', $csop, $k ? self::sajat_csoportok($k['id']) : [], true); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!$k) : ?>
                        <label class="sdh-cs-kapcs"><input type="checkbox" name="ertesit" value="1"><span class="sdh-cs-kapcs__sin"></span><span>Belépési link e-mailben a kollégának</span></label>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges"><?php echo $k ? 'Mentés' : 'Kolléga felvétele'; ?></button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            </div>
        </form>
        <?php
        wp_die();
    }

    public static function ajax_kollega_ment(): void
    {
        self::admin_jog();

        global $wpdb;

        $id      = (int) self::g('id', 20);
        $nev     = self::g('nev', 120);
        $email   = sanitize_email(self::g('email', 190));
        $login   = sanitize_user(self::g('login', 60), true);
        // A jelszó nyersen kell (szóköz, írásjel); csak a hosszát vágjuk.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
        $jelszo  = isset($_POST['jelszo']) ? mb_substr(trim((string) wp_unslash($_POST['jelszo'])), 0, 100) : '';
        $titulus = self::g('titulus', 80);
        $szin    = self::g('szin', 20);
        $en      = get_current_user_id();

        if ($nev === '') {
            wp_send_json_error(['uzenet' => 'Add meg a kolléga nevét.']);
        }

        if (!is_email($email)) {
            wp_send_json_error(['uzenet' => 'Érvényes e-mail-címet adj meg.']);
        }

        $foglalt = email_exists($email);

        if ($foglalt && (int) $foglalt !== $id) {
            wp_send_json_error(['uzenet' => 'Ez az e-mail-cím már egy másik fiókhoz tartozik.']);
        }

        $uj = $id <= 0;

        if ($uj) {
            if ($login === '' || mb_strlen($login) < 3) {
                wp_send_json_error(['uzenet' => 'A felhasználónév legalább 3 karakter legyen (ékezet és szóköz nélkül).']);
            }

            if (username_exists($login)) {
                wp_send_json_error(['uzenet' => 'Ez a felhasználónév már foglalt.']);
            }

            if ($jelszo !== '' && mb_strlen($jelszo) < 8) {
                wp_send_json_error(['uzenet' => 'A jelszó legalább 8 karakter legyen – vagy hagyd üresen, és a rendszer generál egyet.']);
            }

            $generalt = $jelszo === '';
            $jelszo   = $generalt ? wp_generate_password(14, true, false) : $jelszo;

            $id = wp_insert_user([
                'user_login'   => $login,
                'user_email'   => $email,
                'user_pass'    => $jelszo,
                'display_name' => $nev,
                'first_name'   => $nev,
                'role'         => self::SZEREP,
            ]);

            if (is_wp_error($id)) {
                wp_send_json_error(['uzenet' => 'A fiók nem jött létre: ' . $id->get_error_message()]);
            }

            set_transient('sdh_cs_jelszo_' . $en, ['nev' => $nev, 'login' => $login, 'jelszo' => $jelszo], 10 * MINUTE_IN_SECONDS);

            if (self::g('ertesit', 2) === '1') {
                wp_new_user_notification((int) $id, null, 'user');
            }
        } else {
            $f = get_userdata($id);

            if (!$f instanceof WP_User) {
                wp_send_json_error(['uzenet' => 'Ez a fiók már nem létezik.']);
            }

            $adat = ['ID' => $id, 'user_email' => $email, 'display_name' => $nev];

            if ($jelszo !== '') {
                if (mb_strlen($jelszo) < 8) {
                    wp_send_json_error(['uzenet' => 'Az új jelszó legalább 8 karakter legyen.']);
                }

                $adat['user_pass'] = $jelszo;
                set_transient('sdh_cs_jelszo_' . $en, ['nev' => $nev, 'login' => $f->user_login, 'jelszo' => $jelszo], 10 * MINUTE_IN_SECONDS);
            }

            $r = wp_update_user($adat);

            if (is_wp_error($r)) {
                wp_send_json_error(['uzenet' => 'A mentés nem sikerült: ' . $r->get_error_message()]);
            }
        }

        $id = (int) $id;

        // Szerep és állapot – a saját fiókon nem (kizárás ellen).
        if ($id !== $en) {
            $f = new WP_User($id);
            $szerep = self::g('szerep', 10);

            if ($szerep === 'admin') {
                $f->set_role('administrator');
            } elseif ($szerep === 'kollega') {
                $f->set_role(self::SZEREP);
            }

            if (self::g('allapot', 10) === 'letiltva') {
                update_user_meta($id, 'sdh_letiltva', '1');
                // A letiltott kolléga azonnal kilép mindenhonnan.
                WP_Session_Tokens::get_instance($id)->destroy_all();
            } elseif (self::g('allapot', 10) === 'aktiv') {
                delete_user_meta($id, 'sdh_letiltva');
            }
        }

        update_user_meta($id, 'sdh_titulus', $titulus);

        if (isset(self::SZINEK[$szin])) {
            update_user_meta($id, 'sdh_szin', $szin);
        }

        // Csoporttagság.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $kert = isset($_POST['csoport']) ? array_map('intval', (array) wp_unslash($_POST['csoport'])) : [];
        $letezo = self::csoportok();
        $kert = array_values(array_filter($kert, static fn (int $c): bool => isset($letezo[$c])));
        $t = self::t('csoport_tag');

        foreach (array_keys($letezo) as $c) {
            if (in_array($c, $kert, true)) {
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$t} (csoport_id, user_id) VALUES (%d, %d)", $c, $id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            } else {
                $wpdb->delete($t, ['csoport_id' => $c, 'user_id' => $id]);
            }
        }

        wp_send_json_success([
            'id'     => $id,
            'vissza' => SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'kollegak', 'uzenet' => 'mentve']),
        ]);
    }

    public static function ajax_csoport_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Csoportot csak adminisztrátor kezel.</div>';
            wp_die();
        }

        $id = (int) self::g('id', 20);
        $cs = $id > 0 ? (self::csoportok(true)[$id] ?? null) : null;

        $szinek = [];

        foreach (self::SZINEK as $sz => $nev) {
            $szinek[$sz] = ['cimke' => $nev, 'elo' => self::avatar('', $sz)];
        }

        $tagok = [];

        foreach (self::kollegak() as $k) {
            $tagok[$k['id']] = ['cimke' => $k['nev'], 'elo' => self::avatar($k['betu'], $k['szin'])];
        }

        ?>
        <form class="sdh-urlap sdh-cs-urlap" data-sdh-ajax-action="sdh_muhely_csapatcsoport_ment" novalidate>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <h2 class="sdh-modal__cim"><?php echo $cs ? esc_html((string) $cs->nev) : 'Új csoport'; ?></h2>

            <div class="sdh-cs-racs">
                <div>
                    <div class="sdh-ig">
                        <label for="cg_nev">Név <span class="sdh-kotelezo">*</span></label>
                        <input type="text" name="nev" id="cg_nev" maxlength="120" value="<?php echo esc_attr($cs ? (string) $cs->nev : ''); ?>" placeholder="pl. Szerelők" autocomplete="off">
                    </div>
                    <div class="sdh-ig">
                        <label for="cg_leiras">Leírás</label>
                        <input type="text" name="leiras" id="cg_leiras" maxlength="255" value="<?php echo esc_attr($cs ? (string) $cs->leiras : ''); ?>" autocomplete="off">
                    </div>
                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Szín</span>
                        <?php self::pillek('szin', $szinek, [$cs ? (string) $cs->szin : 'turkiz']); ?>
                    </div>
                    <?php if ($cs) : ?>
                        <div class="sdh-ig">
                            <span class="sdh-ig__cimke">Állapot</span>
                            <?php self::pillek('aktiv', ['1' => 'Aktív', '0' => 'Archivált (a beszélgetés megmarad, de nem jelenik meg)'], [(string) (int) $cs->aktiv]); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Tagok</span>
                        <?php self::pillek('tag', $tagok, $cs ? self::csoport_tagjai((int) $cs->id) : [], true, ' data-sdh-cs-oszlop'); ?>
                    </div>
                </div>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges"><?php echo $cs ? 'Mentés' : 'Csoport létrehozása'; ?></button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            </div>
        </form>
        <?php
        wp_die();
    }

    public static function ajax_csoport_ment(): void
    {
        self::admin_jog();

        global $wpdb;

        $id   = (int) self::g('id', 20);
        $nev  = self::g('nev', 120);
        $szin = self::g('szin', 20);

        if ($nev === '') {
            wp_send_json_error(['uzenet' => 'Adj nevet a csoportnak.']);
        }

        $adat = [
            'nev'    => $nev,
            'leiras' => self::g('leiras', 255),
            'szin'   => isset(self::SZINEK[$szin]) ? $szin : 'turkiz',
        ];

        if ($id > 0) {
            $adat['aktiv'] = self::g('aktiv', 2) === '0' ? 0 : 1;
            $wpdb->update(self::t('csoport'), $adat, ['id' => $id]);
        } else {
            $adat['aktiv'] = 1;
            $adat['letrehozva'] = self::most();
            $wpdb->insert(self::t('csoport'), $adat);
            $id = (int) $wpdb->insert_id;
        }

        if ($id <= 0) {
            wp_send_json_error(['uzenet' => 'A csoport mentése nem sikerült.']);
        }

        $wpdb->update(self::t('beszelgetes'), ['nev' => $nev], ['tipus' => 'csoport', 'csoport_id' => $id]);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $kert = isset($_POST['tag']) ? array_map('intval', (array) wp_unslash($_POST['tag'])) : [];
        $kollegak = self::kollegak(true);
        $kert = array_values(array_filter($kert, static fn (int $u): bool => isset($kollegak[$u])));
        $t = self::t('csoport_tag');

        $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE csoport_id = %d", $id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        foreach ($kert as $u) {
            $wpdb->insert($t, ['csoport_id' => $id, 'user_id' => $u]);
        }

        self::csoport_besz_id($id);

        wp_send_json_success([
            'id'     => $id,
            'vissza' => SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'csoportok', 'uzenet' => 'mentve']),
        ]);
    }
}
