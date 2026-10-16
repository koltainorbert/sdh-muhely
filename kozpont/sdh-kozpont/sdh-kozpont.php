<?php
/**
 * Plugin Name:       SDH Központ
 * Description:       Az SDH Műhely forgalmazói központja: licencek, telepített példányok, kiadások (frissítés), támogatási linkek.
 * Version:           1.0.0
 * Requires PHP:      8.0
 * Text Domain:       sdh-kozpont
 *
 * N4 (SDH Műhely 0.93.0). A FORGALMAZÓ webhelyére kerül, nem a vevőkhöz.
 *
 * Adatvédelem: a példányoktól csak a licenckulcs, a példány véletlen azonosítója, a webhely sózott ujjlenyomata,
 * a verzió és a dátum érkezik. A vevő ügyféladata soha. Támogatási link csak akkor jön, ha a vevő adminisztrátora
 * engedélyezte (időkorláttal); a link lejártával a vevőnél a fiók törlődik.
 *
 * Végpontok (REST, `sdh-kozpont/v1`):
 * - POST `jelentes`  {kulcs, peldany, ujjlenyomat, verzio} → Ed25519-aláírt válasz {adat, alairas, kozpont_kulcs}
 * - GET  `csomag`    ?kulcs&peldany → az aktuális kiadás zipje, ha a licenc aktív és a példány ismert
 * - POST `tamogatas` {kulcs, peldany, link, lejar, ki} → a támogatási link rögzítése
 *
 * @package SDH_Kozpont
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SDH_KOZPONT_VERSION', '1.0.0');

final class SDH_Kozpont
{
    public const DB_VERSION = '1.0.0';
    public const NS         = 'sdh-kozpont/v1';
    private const OPT_KULCS = 'sdh_kozpont_kulcspar';
    private const OPT_KIADAS = 'sdh_kozpont_kiadas';

    /** Ennyi napig számít élőnek egy példány (a példányszám-korláthoz). */
    public const ELO_NAP = 60;

    public static function init(): void
    {
        add_action('plugins_loaded', [self::class, 'sema']);
        add_action('rest_api_init', [self::class, 'utvonalak']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_sdh_kozpont_licenc', [self::class, 'post_licenc']);
        add_action('admin_post_sdh_kozpont_kiadas', [self::class, 'post_kiadas']);
    }

    public static function t(string $nev): string
    {
        global $wpdb;

        return $wpdb->prefix . 'sdh_kozpont_' . $nev;
    }

    public static function sema(): void
    {
        if (get_option('sdh_kozpont_db') === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();

        dbDelta('CREATE TABLE ' . self::t('licenc') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            kulcs varchar(40) NOT NULL default '',
            vevo varchar(190) NOT NULL default '',
            vevo_azonosito varchar(60) NOT NULL default '',
            lejar date NULL,
            max_peldany int(11) NOT NULL default 1,
            allapot varchar(12) NOT NULL default 'aktiv',
            uzenet varchar(255) NOT NULL default '',
            megjegyzes text NULL,
            letrehozva datetime NULL,
            PRIMARY KEY  (id),
            unique key kulcs (kulcs)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('peldany') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            licenc_id bigint(20) unsigned NOT NULL default 0,
            peldany varchar(40) NOT NULL default '',
            ujjlenyomat varchar(40) NOT NULL default '',
            verzio varchar(20) NOT NULL default '',
            elso datetime NULL,
            utolso datetime NULL,
            jelentes int(11) NOT NULL default 0,
            PRIMARY KEY  (id),
            unique key peldany (peldany),
            key licenc_id (licenc_id)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('tamogatas') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            licenc_id bigint(20) unsigned NOT NULL default 0,
            peldany varchar(40) NOT NULL default '',
            link varchar(500) NOT NULL default '',
            lejar datetime NULL,
            ki varchar(120) NOT NULL default '',
            erkezett datetime NULL,
            PRIMARY KEY  (id),
            key peldany (peldany)
        ) {$c};");

        update_option('sdh_kozpont_db', self::DB_VERSION);
    }

    /* =================================================================
     * Kulcspár, aláírás
     * ============================================================== */

    /** @return array{titkos: string, nyilvanos: string} nyers bájtok */
    public static function kulcspar(): array
    {
        $m = get_option(self::OPT_KULCS);

        if (!is_array($m) || empty($m['titkos']) || empty($m['nyilvanos'])) {
            $kp = sodium_crypto_sign_keypair();
            $m  = ['titkos' => base64_encode(sodium_crypto_sign_secretkey($kp)), 'nyilvanos' => base64_encode(sodium_crypto_sign_publickey($kp))];
            update_option(self::OPT_KULCS, $m, false);
        }

        return ['titkos' => (string) base64_decode((string) $m['titkos'], true), 'nyilvanos' => (string) base64_decode((string) $m['nyilvanos'], true)];
    }

    /**
     * @param array<string, mixed> $adat
     * @return array{adat: string, alairas: string, kozpont_kulcs: string}
     */
    public static function alair(array $adat): array
    {
        $kp  = self::kulcspar();
        $raw = (string) wp_json_encode($adat);

        return [
            'adat'          => base64_encode($raw),
            'alairas'       => base64_encode(sodium_crypto_sign_detached($raw, $kp['titkos'])),
            'kozpont_kulcs' => base64_encode($kp['nyilvanos']),
        ];
    }

    /* =================================================================
     * Licenc, példány
     * ============================================================== */

    public static function uj_kulcs(): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $r   = [];

        for ($i = 0; $i < 4; $i++) {
            $d = '';

            for ($j = 0; $j < 4; $j++) {
                $d .= $abc[random_int(0, strlen($abc) - 1)];
            }

            $r[] = $d;
        }

        return 'SDH-' . implode('-', $r);
    }

    public static function licenc(string $kulcs): ?object
    {
        global $wpdb;

        $l = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('licenc') . ' WHERE kulcs = %s', strtoupper($kulcs)));

        return $l ?: null;
    }

    /** A licenc tényleges állapota ma. */
    public static function licenc_allapot(object $l, string $ma): string
    {
        if ((string) $l->allapot === 'tiltva') {
            return 'tiltva';
        }

        if (!empty($l->lejar) && (string) $l->lejar < $ma) {
            return 'lejart';
        }

        return 'aktiv';
    }

    /** Az aktuális kiadás: verzió és fájl. */
    public static function kiadas(): array
    {
        $k = get_option(self::OPT_KIADAS, []);

        return is_array($k) ? $k + ['verzio' => '', 'fajl' => '', 'ido' => ''] : ['verzio' => '', 'fajl' => '', 'ido' => ''];
    }

    /**
     * A jelentés feldolgozása (a REST-végpont és a teszt is ezt hívja).
     *
     * @param array<string, mixed> $be
     * @return array<string, mixed> a (még aláíratlan) válaszadat
     */
    public static function jelentes_feldolgoz(array $be, ?int $most = null): array
    {
        global $wpdb;

        $most    = $most ?? time();
        $peldany = preg_match('/^[a-f0-9\-]{36}$/', (string) ($be['peldany'] ?? '')) === 1 ? (string) $be['peldany'] : '';
        $ki      = ['peldany' => $peldany, 'ido' => $most, 'allapot' => 'ismeretlen', 'lejar' => '', 'vevo' => '', 'uzenet' => '', 'verzio' => ''];
        $l       = $peldany !== '' ? self::licenc((string) ($be['kulcs'] ?? '')) : null;

        if ($l === null) {
            return $ki;
        }

        $ma     = wp_date('Y-m-d', $most);
        $mysql  = wp_date('Y-m-d H:i:s', $most);
        $sor    = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('peldany') . ' WHERE peldany = %s', $peldany));
        $allapot = self::licenc_allapot($l, $ma);

        if ($sor === null || (int) $sor->licenc_id !== (int) $l->id) {
            $hatar = wp_date('Y-m-d H:i:s', $most - self::ELO_NAP * DAY_IN_SECONDS);
            $elo   = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t('peldany') . ' WHERE licenc_id = %d AND utolso >= %s AND peldany <> %s', (int) $l->id, $hatar, $peldany));

            if ($elo >= max(1, (int) $l->max_peldany)) {
                $ki['allapot'] = 'tul_sok';
                $ki['vevo']    = (string) $l->vevo;

                return $ki;
            }
        }

        $adat = [
            'licenc_id'   => (int) $l->id,
            'ujjlenyomat' => substr(preg_replace('/[^a-f0-9]/', '', (string) ($be['ujjlenyomat'] ?? '')) ?? '', 0, 40),
            'verzio'      => substr(preg_replace('/[^0-9.]/', '', (string) ($be['verzio'] ?? '')) ?? '', 0, 20),
            'utolso'      => $mysql,
        ];

        if ($sor === null) {
            $wpdb->insert(self::t('peldany'), $adat + ['peldany' => $peldany, 'elso' => $mysql, 'jelentes' => 1]);
        } else {
            $wpdb->update(self::t('peldany'), $adat + ['jelentes' => (int) $sor->jelentes + 1], ['id' => (int) $sor->id]);
        }

        $kiadas = self::kiadas();

        return array_merge($ki, [
            'allapot' => $allapot,
            'lejar'   => (string) ($l->lejar ?? ''),
            'vevo'    => (string) $l->vevo,
            'uzenet'  => (string) $l->uzenet,
            'verzio'  => $allapot === 'aktiv' ? (string) $kiadas['verzio'] : '',
        ]);
    }

    /** Letölthet-e csomagot. */
    public static function csomag_szabad(string $kulcs, string $peldany, ?int $most = null): bool
    {
        global $wpdb;

        $l = self::licenc($kulcs);

        if ($l === null || self::licenc_allapot($l, wp_date('Y-m-d', $most ?? time())) !== 'aktiv') {
            return false;
        }

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t('peldany') . ' WHERE peldany = %s AND licenc_id = %d', $peldany, (int) $l->id)) > 0;
    }

    /* =================================================================
     * REST
     * ============================================================== */

    public static function utvonalak(): void
    {
        register_rest_route(self::NS, '/jelentes', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => static function (WP_REST_Request $r) {
                return rest_ensure_response(self::alair(self::jelentes_feldolgoz((array) $r->get_json_params())));
            },
        ]);

        register_rest_route(self::NS, '/csomag', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => static function (WP_REST_Request $r) {
                $k = self::kiadas();
                $f = (string) $k['fajl'];

                if (!self::csomag_szabad((string) $r->get_param('kulcs'), (string) $r->get_param('peldany')) || $f === '' || !is_file($f)) {
                    return new WP_Error('sdh_kozpont_tiltva', 'Nincs érvényes licenc ehhez a letöltéshez.', ['status' => 403]);
                }

                nocache_headers();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="sdh-muhely-' . preg_replace('/[^0-9.]/', '', (string) $k['verzio']) . '.zip"');
                header('Content-Length: ' . (string) filesize($f));
                readfile($f); // phpcs:ignore WordPress.WP.AlternativeFunctions
                exit;
            },
        ]);

        register_rest_route(self::NS, '/tamogatas', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => static function (WP_REST_Request $r) {
                $hiba = self::tamogatas_rogzit((array) $r->get_json_params());

                return $hiba === '' ? rest_ensure_response(['ok' => true]) : new WP_Error('sdh_kozpont_tamogatas', $hiba, ['status' => 403]);
            },
        ]);
    }

    /** @param array<string, mixed> $be */
    public static function tamogatas_rogzit(array $be): string
    {
        global $wpdb;

        $l       = self::licenc((string) ($be['kulcs'] ?? ''));
        $peldany = (string) ($be['peldany'] ?? '');
        $link    = esc_url_raw((string) ($be['link'] ?? ''));

        if ($l === null || $link === '' || (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t('peldany') . ' WHERE peldany = %s AND licenc_id = %d', $peldany, (int) $l->id)) === 0) {
            return 'Ismeretlen licenc vagy példány.';
        }

        $wpdb->insert(self::t('tamogatas'), [
            'licenc_id' => (int) $l->id,
            'peldany'   => $peldany,
            'link'      => mb_substr($link, 0, 500),
            'lejar'     => wp_date('Y-m-d H:i:s', max(time(), (int) ($be['lejar'] ?? 0))),
            'ki'        => mb_substr(sanitize_text_field((string) ($be['ki'] ?? '')), 0, 120),
            'erkezett'  => current_time('mysql'),
        ]);

        return '';
    }

    /* =================================================================
     * Admin
     * ============================================================== */

    public static function menu(): void
    {
        add_menu_page('SDH Központ', 'SDH Központ', 'manage_options', 'sdh-kozpont', [self::class, 'oldal'], 'dashicons-admin-network', 58);
    }

    public static function post_licenc(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Nincs jogosultság.');
        }

        check_admin_referer('sdh_kozpont_licenc');
        global $wpdb;

        $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $lej  = isset($_POST['lejar']) ? sanitize_text_field(wp_unslash($_POST['lejar'])) : '';
        $adat = [
            'vevo'           => isset($_POST['vevo']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['vevo'])), 0, 190) : '',
            'vevo_azonosito' => isset($_POST['vevo_azonosito']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['vevo_azonosito'])), 0, 60) : '',
            'lejar'          => preg_match('/^\d{4}-\d{2}-\d{2}$/', $lej) === 1 ? $lej : null,
            'max_peldany'    => isset($_POST['max_peldany']) ? max(1, min(99, (int) $_POST['max_peldany'])) : 1,
            'allapot'        => isset($_POST['allapot']) && $_POST['allapot'] === 'tiltva' ? 'tiltva' : 'aktiv',
            'uzenet'         => isset($_POST['uzenet']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['uzenet'])), 0, 255) : '',
            'megjegyzes'     => isset($_POST['megjegyzes']) ? sanitize_textarea_field(wp_unslash($_POST['megjegyzes'])) : '',
        ];

        if ($id > 0) {
            $wpdb->update(self::t('licenc'), $adat, ['id' => $id]);
        } else {
            $wpdb->insert(self::t('licenc'), $adat + ['kulcs' => self::uj_kulcs(), 'letrehozva' => current_time('mysql')]);
        }

        wp_safe_redirect(admin_url('admin.php?page=sdh-kozpont&ful=licencek&ok=1'));
        exit;
    }

    /** Új kiadás feltöltése: a zipből kiolvassa a verziót (sdh-muhely/sdh-muhely.php fejléce). */
    public static function post_kiadas(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Nincs jogosultság.');
        }

        check_admin_referer('sdh_kozpont_kiadas');

        $hiba = isset($_FILES['zip']['tmp_name']) ? self::kiadas_be((string) $_FILES['zip']['tmp_name']) : 'Nincs fájl.';

        wp_safe_redirect(admin_url('admin.php?page=sdh-kozpont&ful=kiadas&' . ($hiba === '' ? 'ok=1' : 'hiba=' . rawurlencode($hiba))));
        exit;
    }

    /** A kiadás eltárolása. Hibánál üzenet. */
    public static function kiadas_be(string $tmp): string
    {
        if (!is_file($tmp) || !class_exists('ZipArchive')) {
            return 'A fájl nem olvasható.';
        }

        $z = new ZipArchive();

        if ($z->open($tmp) !== true) {
            return 'Ez nem zip-fájl.';
        }

        $fej = (string) $z->getFromName('sdh-muhely/sdh-muhely.php');
        $z->close();

        if (preg_match('/^[ \t\/*#@]*Version:\s*([0-9]+(?:\.[0-9]+){1,3})\s*$/mi', substr($fej, 0, 4096), $m) !== 1) {
            return 'A zipben nincs sdh-muhely/sdh-muhely.php verziófejléccel.';
        }

        $up    = wp_upload_dir();
        $mappa = trailingslashit($up['basedir']) . 'sdh-kozpont';
        wp_mkdir_p($mappa);

        if (!is_file($mappa . '/.htaccess')) {
            file_put_contents($mappa . '/.htaccess', "Require all denied\nDeny from all\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
            file_put_contents($mappa . '/index.php', "<?php\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        $cel = $mappa . '/sdh-muhely-' . $m[1] . '-' . wp_generate_password(12, false, false) . '.zip';

        if (!copy($tmp, $cel)) {
            return 'A fájl nem menthető.';
        }

        update_option(self::OPT_KIADAS, ['verzio' => $m[1], 'fajl' => $cel, 'ido' => current_time('mysql')], false);

        return '';
    }

    public static function oldal(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $ful    = isset($_GET['ful']) ? sanitize_key(wp_unslash($_GET['ful'])) : 'licencek';
        $fulek  = ['licencek' => 'Licencek', 'peldanyok' => 'Példányok', 'kiadas' => 'Kiadás', 'tamogatas' => 'Támogatás', 'kulcs' => 'Kulcs'];
        $ful    = isset($fulek[$ful]) ? $ful : 'licencek';
        $szerk  = isset($_GET['szerk']) ? (int) $_GET['szerk'] : 0;
        // phpcs:enable
        $ma = wp_date('Y-m-d');
        ?>
        <div class="wrap">
            <h1>SDH Központ</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ($fulek as $k => $nev) : ?>
                    <a class="nav-tab<?php echo $k === $ful ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=sdh-kozpont&ful=' . $k)); ?>"><?php echo esc_html($nev); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($ful === 'licencek') :
                $lista = $wpdb->get_results('SELECT l.*, (SELECT COUNT(*) FROM ' . self::t('peldany') . ' p WHERE p.licenc_id = l.id) AS db FROM ' . self::t('licenc') . ' l ORDER BY l.id DESC');
                $e     = $szerk > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('licenc') . ' WHERE id = %d', $szerk)) : null;
                ?>
                <table class="widefat striped" style="margin-top:1em">
                    <thead><tr><th>Kulcs</th><th>Vevő</th><th>Azonosító</th><th>Lejár</th><th>Példány</th><th>Állapot</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ((array) $lista as $l) : ?>
                        <tr>
                            <td><code><?php echo esc_html((string) $l->kulcs); ?></code></td>
                            <td><?php echo esc_html((string) $l->vevo); ?></td>
                            <td><?php echo esc_html((string) $l->vevo_azonosito); ?></td>
                            <td><?php echo esc_html((string) ($l->lejar ?: '–')); ?></td>
                            <td><?php echo (int) $l->db; ?> / <?php echo (int) $l->max_peldany; ?></td>
                            <td><?php echo esc_html(['aktiv' => 'Aktív', 'lejart' => 'Lejárt', 'tiltva' => 'Letiltva'][self::licenc_allapot($l, $ma)]); ?></td>
                            <td><a href="<?php echo esc_url(admin_url('admin.php?page=sdh-kozpont&ful=licencek&szerk=' . (int) $l->id)); ?>">Szerkesztés</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <h2><?php echo $e ? 'Licenc szerkesztése' : 'Új licenc'; ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sdh_kozpont_licenc'); ?>
                    <input type="hidden" name="action" value="sdh_kozpont_licenc">
                    <input type="hidden" name="id" value="<?php echo (int) ($e->id ?? 0); ?>">
                    <table class="form-table">
                        <tr><th><label for="k_vevo">Vevő neve</label></th><td><input class="regular-text" name="vevo" id="k_vevo" value="<?php echo esc_attr((string) ($e->vevo ?? '')); ?>" required></td></tr>
                        <tr><th><label for="k_azon">Vevőazonosító</label></th><td><input class="regular-text" name="vevo_azonosito" id="k_azon" value="<?php echo esc_attr((string) ($e->vevo_azonosito ?? '')); ?>"></td></tr>
                        <tr><th><label for="k_lejar">Lejár (üresen soha)</label></th><td><input type="text" name="lejar" id="k_lejar" placeholder="éééé-hh-nn" value="<?php echo esc_attr((string) ($e->lejar ?? '')); ?>"></td></tr>
                        <tr><th><label for="k_max">Példányok száma</label></th><td><input type="number" min="1" max="99" name="max_peldany" id="k_max" value="<?php echo (int) ($e->max_peldany ?? 1); ?>"></td></tr>
                        <tr><th><label for="k_all">Állapot</label></th><td><select name="allapot" id="k_all"><option value="aktiv">Aktív</option><option value="tiltva"<?php selected((string) ($e->allapot ?? ''), 'tiltva'); ?>>Letiltva</option></select></td></tr>
                        <tr><th><label for="k_uz">Üzenet a vevőnek</label></th><td><input class="large-text" name="uzenet" id="k_uz" value="<?php echo esc_attr((string) ($e->uzenet ?? '')); ?>"></td></tr>
                        <tr><th><label for="k_megj">Belső megjegyzés</label></th><td><textarea class="large-text" rows="3" name="megjegyzes" id="k_megj"><?php echo esc_textarea((string) ($e->megjegyzes ?? '')); ?></textarea></td></tr>
                    </table>
                    <?php submit_button($e ? 'Mentés' : 'Licenc létrehozása (a kulcs magától készül)'); ?>
                </form>

            <?php elseif ($ful === 'peldanyok') :
                $lista = $wpdb->get_results('SELECT p.*, l.vevo, l.kulcs FROM ' . self::t('peldany') . ' p LEFT JOIN ' . self::t('licenc') . ' l ON l.id = p.licenc_id ORDER BY p.utolso DESC LIMIT 500');
                $hatar = wp_date('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
                ?>
                <p>Csak a licenckulcs, a példány azonosítója, az ujjlenyomat és a verzió érkezik. Ügyféladat nem.</p>
                <table class="widefat striped">
                    <thead><tr><th>Vevő</th><th>Kulcs</th><th>Példány</th><th>Ujjlenyomat</th><th>Verzió</th><th>Első</th><th>Utolsó jelentés</th><th>Jelentés</th></tr></thead>
                    <tbody>
                    <?php foreach ((array) $lista as $p) : ?>
                        <tr<?php echo (string) $p->utolso < $hatar ? ' style="opacity:.6"' : ''; ?>>
                            <td><?php echo esc_html((string) $p->vevo); ?></td>
                            <td><code><?php echo esc_html((string) $p->kulcs); ?></code></td>
                            <td><code><?php echo esc_html(substr((string) $p->peldany, 0, 8)); ?>…</code></td>
                            <td><code><?php echo esc_html(substr((string) $p->ujjlenyomat, 0, 10)); ?></code></td>
                            <td><?php echo esc_html((string) $p->verzio); ?></td>
                            <td><?php echo esc_html((string) $p->elso); ?></td>
                            <td><?php echo esc_html((string) $p->utolso); ?></td>
                            <td><?php echo (int) $p->jelentes; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ($ful === 'kiadas') : $k = self::kiadas(); ?>
                <p>Aktuális kiadás: <strong><?php echo esc_html($k['verzio'] !== '' ? $k['verzio'] : 'nincs'); ?></strong><?php echo $k['ido'] !== '' ? ' (' . esc_html((string) $k['ido']) . ')' : ''; ?>.
                    Az aktív licencű példányok a következő napi jelentéskor látják, és a saját Frissítés gombjukkal telepítik.</p>
                <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                if (isset($_GET['hiba'])) : ?><div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['hiba']))); ?></p></div><?php endif; ?>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sdh_kozpont_kiadas'); ?>
                    <input type="hidden" name="action" value="sdh_kozpont_kiadas">
                    <p><input type="file" name="zip" accept=".zip" required> <?php submit_button('Kiadás feltöltése', 'primary', 'submit', false); ?></p>
                    <p class="description">Az SDH Műhely telepítő zipje (benne: sdh-muhely/sdh-muhely.php). A verziót a fájl fejlécéből olvassa ki.</p>
                </form>

            <?php elseif ($ful === 'tamogatas') :
                $lista = $wpdb->get_results('SELECT t.*, l.vevo FROM ' . self::t('tamogatas') . ' t LEFT JOIN ' . self::t('licenc') . ' l ON l.id = t.licenc_id ORDER BY t.id DESC LIMIT 100');
                $most  = current_time('mysql');
                ?>
                <p>A vevő adminisztrátora által engedélyezett, időkorlátos belépő linkek. Lejárat után a vevőnél a fiók törlődik.</p>
                <table class="widefat striped">
                    <thead><tr><th>Vevő</th><th>Engedélyezte</th><th>Érkezett</th><th>Lejár</th><th>Link</th></tr></thead>
                    <tbody>
                    <?php foreach ((array) $lista as $t) : $el = (string) $t->lejar > $most; ?>
                        <tr<?php echo $el ? '' : ' style="opacity:.5"'; ?>>
                            <td><?php echo esc_html((string) $t->vevo); ?></td>
                            <td><?php echo esc_html((string) $t->ki); ?></td>
                            <td><?php echo esc_html((string) $t->erkezett); ?></td>
                            <td><?php echo esc_html((string) $t->lejar); ?></td>
                            <td><?php echo $el ? '<a href="' . esc_url((string) $t->link) . '" target="_blank" rel="noopener noreferrer">Belépés</a>' : 'lejárt'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

            <?php else : ?>
                <p>A központ nyilvános aláíró kulcsa (a példányok az első kapcsolatkor megjegyzik):</p>
                <p><code><?php echo esc_html(base64_encode(self::kulcspar()['nyilvanos'])); ?></code></p>
                <p>A vevőnek ezt a címet add meg „A központ címe” mezőbe: <code><?php echo esc_html(home_url('/')); ?></code></p>
            <?php endif; ?>
        </div>
        <?php
    }
}

SDH_Kozpont::init();
