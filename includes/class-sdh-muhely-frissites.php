<?php
/**
 * Frissítés a GitHubról (HIBALISTA H4, 0.74.0).
 *
 * - A GitHub fő ágán (alapból `main`) álló sdh-muhely.php `Version:` fejléce adja az újabb verziót; nincs szükség
 *   külön „release”-re. A tároló és az ág felülírható (`sdh_muhely_frissites_forras` szűrő, vagy az opció).
 * - A WordPress saját frissítési rendszerébe is bekötjük (Bővítmények › „Frissítés elérhető”), a csomag a GitHub
 *   zipball-ja; az `upgrader_source_selection` a GitHub-os mappanevet `sdh-muhely`-re cseréli.
 * - A Beállítások › Névjegy lapon az „Ellenőrzés most” és a kétlépcsős „Frissítés most” gomb: előbb teljes mentés
 *   (`frissites-elott`), utána a WordPress Plugin_Upgrader-e. Ha a mentés nem sikerül, nem frissít.
 * - Net nélkül csendben kihagyja az ellenőrzést (rövid időkorlát, hibás eredmény 15 percig gyorsítótárban).
 * - Git-példányon (a bővítmény mappájában .git van) nem frissít: ott `git pull` a rend, a mappát nem szabad felülírni.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Frissites
{
    public const OPT   = 'sdh_muhely_frissites';
    public const CACHE = 'sdh_muhely_frissites_uj';
    public const REPO  = 'koltainorbert/sdh-muhely';
    public const AG    = 'main';
    private const FAJL = 'sdh-muhely.php';
    private const MAPPA = 'sdh-muhely';

    public static function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'szuro']);
        add_filter('plugins_api', [self::class, 'plugin_adatok'], 20, 3);
        add_filter('upgrader_source_selection', [self::class, 'forrasmappa'], 10, 4);
        add_filter('http_request_args', [self::class, 'kerelem_fejlec'], 10, 2);
        add_action('wp_ajax_sdh_muhely_frissites_ellenorzes', [self::class, 'ajax_ellenorzes']);
        add_action('wp_ajax_sdh_muhely_frissites_indit', [self::class, 'ajax_indit']);
    }

    /* =================================================================
     * Forrás
     * ============================================================== */

    /** @return array{repo: string, ag: string, token: string} */
    public static function forras(): array
    {
        $o = get_option(self::OPT, []);
        $o = is_array($o) ? $o : [];

        $f = [
            'repo'  => isset($o['repo']) && is_string($o['repo']) && $o['repo'] !== '' ? $o['repo'] : self::REPO,
            'ag'    => isset($o['ag']) && is_string($o['ag']) && $o['ag'] !== '' ? $o['ag'] : self::AG,
            'token' => isset($o['token']) && is_string($o['token']) ? SDH_Muhely_Titok::visszafejt($o['token']) : '',
        ];

        $f = apply_filters('sdh_muhely_frissites_forras', $f);

        // Csak „tulajdonos/tároló” és ágnév-alakú érték mehet az URL-be.
        if (!preg_match('#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$#', (string) $f['repo'])) {
            $f['repo'] = self::REPO;
        }

        if (!preg_match('#^[A-Za-z0-9_.\-/]+$#', (string) $f['ag'])) {
            $f['ag'] = self::AG;
        }

        return ['repo' => (string) $f['repo'], 'ag' => (string) $f['ag'], 'token' => (string) ($f['token'] ?? '')];
    }

    public static function csomag_url(): string
    {
        $f = self::forras();

        return 'https://api.github.com/repos/' . $f['repo'] . '/zipball/' . $f['ag'];
    }

    /** A hitelesítő fejléc a GitHub-csomag letöltéséhez (privát tárolóhoz). */
    public static function kerelem_fejlec(array $args, string $url): array
    {
        $f = self::forras();

        if ($f['token'] !== '' && strpos($url, 'https://api.github.com/repos/' . $f['repo'] . '/') === 0) {
            $args['headers'] = (array) ($args['headers'] ?? []);
            $args['headers']['Authorization'] = 'Bearer ' . $f['token'];
        }

        return $args;
    }

    /** Fejlesztői példány: a mappában git-tároló van, ott nem szabad felülírni. */
    public static function git_peldany(): bool
    {
        return (bool) apply_filters('sdh_muhely_frissites_git', file_exists(SDH_MUHELY_DIR . '.git'));
    }

    /* =================================================================
     * Az újabb verzió megkérdezése
     * ============================================================== */

    /**
     * @return array{verzio: string, ido: int, hiba: string}
     */
    public static function uj_verzio(bool $kenyszer = false): array
    {
        if (!$kenyszer) {
            $c = get_transient(self::CACHE);

            if (is_array($c) && isset($c['verzio'], $c['ido'], $c['hiba'])) {
                return $c;
            }
        }

        $f = self::forras();
        $h = ['Accept' => 'application/vnd.github.raw+json', 'User-Agent' => 'sdh-muhely/' . SDH_MUHELY_VERSION];

        if ($f['token'] !== '') {
            $h['Authorization'] = 'Bearer ' . $f['token'];
        }

        $r = wp_remote_get(
            'https://api.github.com/repos/' . $f['repo'] . '/contents/' . self::FAJL . '?ref=' . rawurlencode($f['ag']),
            ['timeout' => 8, 'headers' => $h]
        );

        $kod = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        $ki  = ['verzio' => '', 'ido' => time(), 'hiba' => ''];

        if (is_wp_error($r)) {
            $ki['hiba'] = 'A GitHub nem érhető el (nincs internet?).';
        } elseif ($kod === 404) {
            $ki['hiba'] = 'A tároló vagy a fájl nem található (privát tárolóhoz hozzáférési kulcs kell).';
        } elseif ($kod === 403 || $kod === 429) {
            $ki['hiba'] = 'A GitHub átmenetileg korlátozza a lekérdezést – próbáld később.';
        } elseif ($kod !== 200) {
            $ki['hiba'] = 'A GitHub hibát jelzett (HTTP ' . $kod . ').';
        } else {
            $v = self::verzio_fejlecbol((string) wp_remote_retrieve_body($r));

            if ($v === '') {
                $ki['hiba'] = 'A GitHubon álló fájlból nem olvasható ki a verzió.';
            } else {
                $ki['verzio'] = $v;
            }
        }

        set_transient(self::CACHE, $ki, $ki['hiba'] === '' ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS);

        return $ki;
    }

    /** A plugin-fejléc „Version:” sora. */
    public static function verzio_fejlecbol(string $forras): string
    {
        $fej = substr($forras, 0, 4096);

        return preg_match('/^[ \t\/*#@]*Version:\s*([0-9]+(?:\.[0-9]+){1,3}(?:-[0-9A-Za-z.]+)?)\s*$/mi', $fej, $m) === 1 ? $m[1] : '';
    }

    public static function van_ujabb(?array $uj = null): bool
    {
        $uj = $uj ?? self::uj_verzio();

        return $uj['hiba'] === '' && $uj['verzio'] !== '' && version_compare($uj['verzio'], SDH_MUHELY_VERSION, '>');
    }

    /** Csak a gyorsítótárból (oldalbetöltés közben nem hív ki a netre). */
    public static function ismert_ujabb(): string
    {
        $c = get_transient(self::CACHE);

        return is_array($c) && self::van_ujabb($c) ? (string) $c['verzio'] : '';
    }

    /* =================================================================
     * WordPress frissítési rendszer
     * ============================================================== */

    /**
     * @param mixed $t
     * @return mixed
     */
    public static function szuro($t)
    {
        if (!is_object($t)) {
            return $t;
        }

        $uj = self::uj_verzio();
        $f  = self::forras();
        $e  = (object) [
            'id'          => 'github.com/' . $f['repo'],
            'slug'        => self::MAPPA,
            'plugin'      => SDH_MUHELY_BASENAME,
            'new_version' => $uj['verzio'] !== '' ? $uj['verzio'] : SDH_MUHELY_VERSION,
            'url'         => 'https://github.com/' . $f['repo'],
            'package'     => self::csomag_url(),
        ];

        if (self::van_ujabb($uj)) {
            $t->response = (array) ($t->response ?? []);
            $t->response[SDH_MUHELY_BASENAME] = $e;
            unset($t->no_update[SDH_MUHELY_BASENAME]);
        } else {
            $t->no_update = (array) ($t->no_update ?? []);
            $t->no_update[SDH_MUHELY_BASENAME] = $e;
            unset($t->response[SDH_MUHELY_BASENAME]);
        }

        return $t;
    }

    /**
     * @param mixed $eredmeny
     * @param mixed $args
     * @return mixed
     */
    public static function plugin_adatok($eredmeny, string $muvelet, $args)
    {
        if ($muvelet !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::MAPPA) {
            return $eredmeny;
        }

        $uj = self::uj_verzio();
        $f  = self::forras();

        return (object) [
            'name'          => 'SDH Műhely',
            'slug'          => self::MAPPA,
            'version'       => $uj['verzio'] !== '' ? $uj['verzio'] : SDH_MUHELY_VERSION,
            'homepage'      => 'https://github.com/' . $f['repo'],
            'download_link' => self::csomag_url(),
            'sections'      => ['description' => 'Műhely- és ügyfélkezelő rendszer. A frissítés a GitHub ' . $f['ag'] . ' ágáról érkezik.'],
        ];
    }

    /**
     * A GitHub-csomag mappája `tulajdonos-tároló-azonosító`; a bővítmény mappája `sdh-muhely` kell legyen.
     *
     * @param mixed $forras
     * @param mixed $tavoli
     * @param mixed $upgrader
     * @param mixed $extra
     * @return mixed
     */
    public static function forrasmappa($forras, $tavoli, $upgrader, $extra)
    {
        if (!is_string($forras) || !is_array($extra) || ($extra['plugin'] ?? '') !== SDH_MUHELY_BASENAME) {
            return $forras;
        }

        global $wp_filesystem;

        if (!is_object($wp_filesystem) || !is_string($tavoli)) {
            return $forras;
        }

        if (!$wp_filesystem->exists(trailingslashit($forras) . self::FAJL)) {
            return new WP_Error('sdh_frissites_csomag', 'A letöltött csomag nem SDH Műhely csomag (nincs benne a fő fájl).');
        }

        $uj = trailingslashit($tavoli) . self::MAPPA . '/';

        if (untrailingslashit($forras) === untrailingslashit($uj)) {
            return $forras;
        }

        if ($wp_filesystem->exists($uj)) {
            $wp_filesystem->delete($uj, true);
        }

        if (!$wp_filesystem->move(untrailingslashit($forras), untrailingslashit($uj))) {
            return new WP_Error('sdh_frissites_atnevezes', 'A letöltött csomag mappája nem nevezhető át.');
        }

        return $uj;
    }

    /* =================================================================
     * Felület: Beállítások › Névjegy
     * ============================================================== */

    public static function doboz(): void
    {
        $uj     = self::uj_verzio();
        $ujabb  = self::van_ujabb($uj);
        $nonce  = wp_create_nonce('sdh_muhely_modal');
        $f      = self::forras();
        $git    = self::git_peldany();
        ?>
        <div class="sdh-doboz" data-sdh-frissites data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <h2 class="sdh-doboz__cim">Frissítés</h2>
            <table class="sdh-tabla sdh-tabla--keskeny">
                <tbody>
                    <tr><th>Telepített verzió</th><td><code><?php echo esc_html(SDH_MUHELY_VERSION); ?></code></td></tr>
                    <tr><th>Legújabb verzió</th><td><code data-sdh-frissites-uj><?php echo esc_html($uj['verzio'] !== '' ? $uj['verzio'] : '–'); ?></code></td></tr>
                    <tr><th>Forrás</th><td><code><?php echo esc_html('github.com/' . $f['repo'] . ' (' . $f['ag'] . ')'); ?></code></td></tr>
                </tbody>
            </table>

            <p class="sdh-sugo" data-sdh-frissites-uzenet role="status" aria-live="polite">
                <?php
                if ($uj['hiba'] !== '') {
                    echo esc_html($uj['hiba']);
                } elseif ($ujabb) {
                    echo esc_html('Frissítés elérhető: ' . $uj['verzio'] . '.');
                } else {
                    echo esc_html('A rendszer naprakész.');
                }
                ?>
            </p>

            <?php if ($git) : ?>
                <p class="sdh-uzenet sdh-uzenet--figyelem">Ez fejlesztői példány (a mappában git-tároló van): itt nem a gomb, hanem a <code>git pull</code> frissít.</p>
            <?php endif; ?>

            <p>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-frissites-ellenorzes>Ellenőrzés most</button>
                <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-frissites-indit<?php echo $ujabb && !$git ? '' : ' hidden'; ?>>Frissítés most</button>
            </p>
            <p class="sdh-sugo sdh-sugo--utolso">
                A frissítés előtt a rendszer teljes mentést készít, és csak utána cserél. Az adatok és a beállítások megmaradnak.
                A csere néhány másodpercig tart; közben a felület rövid időre „karbantartás alatt” állapotot mutathat.
            </p>
        </div>
        <?php
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    private static function admin_vagy_leall(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!SDH_Muhely_Admin_UI::fejleszto_e()) {
            wp_send_json_error(['uzenet' => 'Ehhez fejlesztői jog kell.'], 403);
        }
    }

    public static function ajax_ellenorzes(): void
    {
        self::admin_vagy_leall();

        $uj = self::uj_verzio(true);
        delete_site_transient('update_plugins');

        if ($uj['hiba'] !== '') {
            wp_send_json_error(['uzenet' => $uj['hiba']]);
        }

        $ujabb = self::van_ujabb($uj);

        wp_send_json_success([
            'verzio'  => $uj['verzio'],
            'ujabb'   => $ujabb && !self::git_peldany(),
            'uzenet'  => $ujabb ? 'Frissítés elérhető: ' . $uj['verzio'] . '.' : 'A rendszer naprakész (' . SDH_MUHELY_VERSION . ').',
        ]);
    }

    public static function ajax_indit(): void
    {
        self::admin_vagy_leall();

        if (self::git_peldany()) {
            wp_send_json_error(['uzenet' => 'Fejlesztői példány (git): itt git pull-lal frissíts.']);
        }

        $uj = self::uj_verzio(true);

        if ($uj['hiba'] !== '') {
            wp_send_json_error(['uzenet' => $uj['hiba']]);
        }

        if (!self::van_ujabb($uj)) {
            wp_send_json_error(['uzenet' => 'Nincs újabb verzió.']);
        }

        @set_time_limit(600); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        ignore_user_abort(true);

        // 1) Biztonsági mentés: nélküle nem cserélünk.
        $b = class_exists('SDH_Muhely_Asszisztens_Tudas') ? SDH_Muhely_Asszisztens_Tudas::teljes_mentes('frissites-elott') : 'A mentés nem érhető el.';

        if (!is_array($b)) {
            wp_send_json_error(['uzenet' => 'A frissítés előtti mentés nem sikerült, ezért nem frissítek: ' . (string) $b]);
        }

        // 2) A WordPress frissítője.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        delete_site_transient('update_plugins');
        wp_update_plugins();

        $skin = new Automatic_Upgrader_Skin();
        $up   = new Plugin_Upgrader($skin);
        $r    = $up->upgrade(SDH_MUHELY_BASENAME, ['clear_update_cache' => true]);

        if (is_wp_error($r)) {
            wp_send_json_error(['uzenet' => 'A frissítés nem sikerült: ' . $r->get_error_message() . ' A régi verzió változatlan; a mentés: ' . $b['fajl'] . '.']);
        }

        if ($r !== true) {
            $uz = $skin->get_upgrade_messages();
            $v  = $up->skin->result;
            $ok = is_wp_error($v) ? $v->get_error_message() : (is_array($uz) && $uz !== [] ? wp_strip_all_tags((string) end($uz)) : 'a WordPress nem tudta felülírni a fájlokat (írási jog?).');

            wp_send_json_error(['uzenet' => 'A frissítés nem sikerült: ' . $ok . ' A régi verzió változatlan; a mentés: ' . $b['fajl'] . '.']);
        }

        if (!is_plugin_active(SDH_MUHELY_BASENAME)) {
            activate_plugin(SDH_MUHELY_BASENAME, '', false, true);
        }

        delete_transient(self::CACHE);
        wp_clean_plugins_cache(true);

        $adat = get_plugin_data(SDH_MUHELY_FILE, false, false);

        wp_send_json_success([
            'uzenet' => 'Frissítve: ' . (string) ($adat['Version'] ?? $uj['verzio']) . '. Az oldal újratöltődik. A frissítés előtti mentés: ' . $b['fajl'] . '.',
        ]);
    }
}
