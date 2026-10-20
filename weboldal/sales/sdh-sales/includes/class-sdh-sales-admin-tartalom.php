<?php
/**
 * SDH Értékesítés – admin: Tartalom (oldalak + szekció-építő + élő előnézet, típusos elemek, menük, kezdőtartalom újratöltése).
 *
 * Előnézet: POST /admin/elonezet {post_id, szekciok} → rövid életű (15 perc) tranziens-token; a lap a saját URL-jén
 * `?elonezet=<token>` paraméterrel a téma sablonjaival rajzolódik, de a `_sdh_szekciok` meta helyett a még nem mentett
 * szekciókkal (get_post_metadata szűrő). Csak bejelentkezett adminnak, nem gyorsítótárazható, noindex.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Admin_Tartalom
{
    public const ELONEZET = 'elonezet';
    private const MENU_HELYEK = ['fo' => 'Főmenü', 'lablec' => 'Lábléc', 'jogi' => 'Jogi sáv'];

    /** @var array<int, array<int, array<string, mixed>>> post_id → előnézeti szekciók (a kérés idejére) */
    private static array $elonezet = [];

    public static function init(): void
    {
        $ir  = ['admin_ir', 240, 60];
        $olv = ['admin_olv', 900, 60];

        SDH_Sales_Rest::ut('/admin/oldalak', 'GET', [self::class, 'r_oldalak'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/oldal', 'POST', [self::class, 'r_oldal_uj'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/oldal/(?P<id>\d+)', 'GET', [self::class, 'r_oldal'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/oldal/(?P<id>\d+)', 'POST', [self::class, 'r_oldal_ment'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/elonezet', 'POST', [self::class, 'r_elonezet'], 'admin', ['admin_elonezet', 120, 60]);
        SDH_Sales_Rest::ut('/admin/elemek', 'GET', [self::class, 'r_elemek'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/elem', 'POST', [self::class, 'r_elem_ment'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/elem/torol', 'POST', [self::class, 'r_elem_torol'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/elemek/sorrend', 'POST', [self::class, 'r_sorrend'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/menuk', 'GET', [self::class, 'r_menuk'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/menu/(?P<hely>[a-z_]+)', 'POST', [self::class, 'r_menu_ment'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/tartalom/betolt', 'POST', [self::class, 'r_betolt'], 'admin', ['admin_betolt', 10, 60]);

        add_action('template_redirect', [self::class, 'elonezet_indit'], 0);
        add_filter('get_post_metadata', [self::class, 'elonezet_meta'], 10, 4);
    }

    /* =================================================================
     * Oldalak
     * ============================================================== */

    /** @return array<string, mixed> */
    private static function oldal_sor(WP_Post $p): array
    {
        $sz = SDH_Sales_Tartalom::szekciok((int) $p->ID);

        return [
            'id'        => (int) $p->ID,
            'cim'       => (string) $p->post_title,
            'ut'        => get_page_uri($p),
            'szulo'     => (int) $p->post_parent,
            'statusz'   => (string) $p->post_status,
            'szekcios'  => metadata_exists('post', (int) $p->ID, SDH_Sales_Tartalom::META),
            'szekcio_db' => count($sz),
            'tipusok'   => array_values(array_unique(array_map(static fn ($s) => (string) ($s['tipus'] ?? ''), $sz))),
            'url'       => (string) get_permalink($p),
            'modositva' => strtotime((string) $p->post_modified_gmt . ' UTC') ?: 0,
            'kezdolap'  => (int) get_option('page_on_front') === (int) $p->ID,
            'vazlat'    => metadata_exists('post', (int) $p->ID, SDH_Sales_Studio::META_VAZLAT),
            'jogi'      => in_array('jogi', array_column($sz, 'tipus'), true),
            'sorrend'   => (int) $p->menu_order,
        ];
    }

    public static function r_oldalak(WP_REST_Request $r): WP_REST_Response
    {
        $kuka = (bool) $r->get_param('kuka');
        $q    = get_posts([
            'post_type'      => 'page',
            'post_status'    => $kuka ? ['trash'] : ['publish', 'draft', 'private', 'pending'],
            'posts_per_page' => 1000,
            'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'no_found_rows'  => true,
        ]);

        update_meta_cache('post', wp_list_pluck($q, 'ID'));

        $lista = array_map([self::class, 'oldal_sor'], $q);
        usort($lista, static fn ($a, $b) => strcmp($a['ut'], $b['ut']));

        return new WP_REST_Response(['lista' => $lista], 200);
    }

    public static function r_oldal_uj(WP_REST_Request $r)
    {
        $cim   = mb_substr(sanitize_text_field((string) $r->get_param('cim')), 0, 200);
        $slug  = sanitize_title((string) ($r->get_param('slug') ?: $cim));
        $szulo = (int) $r->get_param('szulo');

        if ($cim === '' || $slug === '') {
            return SDH_Sales_Rest::hiba('Add meg az oldal címét.', 400);
        }

        if ($szulo > 0 && get_post_type($szulo) !== 'page') {
            return SDH_Sales_Rest::hiba('A szülő oldal nem létezik.', 400);
        }

        $id = wp_insert_post([
            'post_type'   => 'page',
            'post_status' => $r->get_param('statusz') === 'publish' ? 'publish' : 'draft',
            'post_title'  => $cim,
            'post_name'   => $slug,
            'post_parent' => max(0, $szulo),
        ], true);

        if (is_wp_error($id)) {
            return SDH_Sales_Rest::hiba($id->get_error_message(), 400);
        }

        $sablon = (array) $r->get_param('szekciok');
        SDH_Sales_Tartalom::szekciok_ment((int) $id, SDH_Sales_Szekcio::ervenyesit_lista($sablon));
        update_post_meta((int) $id, '_sdh_szerkesztve', 1);

        return new WP_REST_Response(self::oldal_sor(get_post((int) $id)), 201);
    }

    public static function r_oldal(WP_REST_Request $r)
    {
        $p = get_post((int) $r['id']);

        if (!$p || $p->post_type !== 'page') {
            return SDH_Sales_Rest::hiba('Nincs ilyen oldal.', 404, 'nincs');
        }

        $kep = (int) get_post_thumbnail_id($p);

        return new WP_REST_Response(self::oldal_sor($p) + [
            'szekciok' => SDH_Sales_Tartalom::szekciok((int) $p->ID),
            'seo'      => SDH_Sales_Exportimport::seo((int) $p->ID) + [
                'og_kep'    => $kep > 0 ? (string) wp_get_attachment_url($kep) : '',
                'noindex'   => (string) get_post_meta($p->ID, '_sdh_noindex', true) === '1',
                'kanonikus' => (string) get_post_meta($p->ID, SDH_Sales_Studio::META_KANON, true),
            ],
            'slug'     => (string) $p->post_name,
            'sablon'   => (string) get_post_meta($p->ID, '_wp_page_template', true),
            'sablonok' => ['' => 'Alap'] + array_flip((array) wp_get_theme()->get_page_templates(null, 'page')),
            'tartalom' => (string) $p->post_content,
            'vazlat'   => SDH_Sales_Studio::vazlat((int) $p->ID),
            'forras'   => (string) get_post_meta($p->ID, SDH_Sales_Tartalom::META_FORRAS, true),
        ], 200);
    }

    /**
     * Kötelező mezők ellenőrzése (a már tisztított szekciókon).
     *
     * @param array<int, array<string, mixed>> $szekciok
     * @return array<int, string>
     */
    public static function hianyok(array $szekciok): array
    {
        $t  = SDH_Sales_Szekcio::tipusok();
        $ki = [];

        foreach ($szekciok as $i => $sz) {
            foreach ($t[$sz['tipus']]['mezok'] ?? [] as $m) {
                if (!empty($m['kotelezo']) && trim((string) (is_scalar($sz[$m['k']] ?? null) ? $sz[$m['k']] : '')) === '') {
                    $ki[] = ($i + 1) . '. szekció (' . $t[$sz['tipus']]['cim'] . '): „' . $m['c'] . '” kötelező.';
                }
            }
        }

        return $ki;
    }

    public static function r_oldal_ment(WP_REST_Request $r)
    {
        $id = (int) $r['id'];
        $p  = get_post($id);

        if (!$p || $p->post_type !== 'page') {
            return SDH_Sales_Rest::hiba('Nincs ilyen oldal.', 404, 'nincs');
        }

        $nyers = $r->get_param('szekciok');

        if ($nyers !== null) {
            if (!is_array($nyers)) {
                return SDH_Sales_Rest::hiba('Hibás szekciólista.', 400);
            }

            $tiszta  = SDH_Sales_Szekcio::ervenyesit_lista($nyers);
            $hianyok = self::hianyok($tiszta);

            if ($hianyok !== []) {
                return new WP_Error('ervenytelen', implode(' ', array_slice($hianyok, 0, 5)), ['status' => 422, 'hianyok' => $hianyok]);
            }

            SDH_Sales_Tartalom::szekciok_ment($id, $tiszta);
            SDH_Sales_Studio::vazlat_torol($id);
        }

        $seo = $r->get_param('seo');

        if (is_array($seo)) {
            $s = [
                'title'       => mb_substr(sanitize_text_field((string) ($seo['title'] ?? '')), 0, 200),
                'description' => mb_substr(sanitize_textarea_field((string) ($seo['description'] ?? '')), 0, 400),
                'kulcsszo'    => mb_substr(sanitize_text_field((string) ($seo['kulcsszo'] ?? '')), 0, 120),
            ];
            $regi = SDH_Sales_Exportimport::seo($id);

            if ($regi !== $s) {
                SDH_Sales_Exportimport::seo_ir($id, $s);
                SDH_Sales_Naplo::ir('oldal', 'SEO módosítva: ' . get_the_title($id), 'info', 'oldal', $id, $regi, $s);
            }

            if (array_key_exists('noindex', $seo)) {
                if (!empty($seo['noindex'])) {
                    update_post_meta($id, '_sdh_noindex', '1');
                } else {
                    delete_post_meta($id, '_sdh_noindex');
                }
            }

            if (array_key_exists('kanonikus', $seo)) {
                $k = SDH_Sales_Szekcio::url((string) $seo['kanonikus']);
                $k = $k !== '' && $k[0] === '/' ? home_url($k) : $k;

                if ($k !== '' && preg_match('#^https?://#i', $k) === 1) {
                    update_post_meta($id, SDH_Sales_Studio::META_KANON, esc_url_raw($k));
                } else {
                    delete_post_meta($id, SDH_Sales_Studio::META_KANON);
                }
            }

            if (array_key_exists('og_kep', $seo)) {
                $u   = SDH_Sales_Szekcio::url((string) $seo['og_kep']);
                $att = $u !== '' ? SDH_Sales_Studio::kep_id($u) : 0;

                if ($att > 0) {
                    set_post_thumbnail($id, $att);
                } elseif ($u === '') {
                    delete_post_thumbnail($id);
                } else {
                    return SDH_Sales_Rest::hiba('A megosztási kép csak a médiatárból választható.', 422, 'ervenytelen');
                }
            }
        }

        $tart = $r->get_param('tartalom');

        if (is_string($tart) && $tart !== $p->post_content) {
            $postarr_t = SDH_Sales_Szekcio::html($tart);
        }

        $postarr = ['ID' => $id];
        $cim     = $r->get_param('cim');

        if (is_string($cim) && trim($cim) !== '' && $cim !== $p->post_title) {
            $postarr['post_title'] = mb_substr(sanitize_text_field($cim), 0, 200);
        }

        $st = $r->get_param('statusz');

        if (in_array($st, ['publish', 'draft', 'private'], true) && $st !== $p->post_status) {
            $postarr['post_status'] = $st;
        }

        if (isset($postarr_t)) {
            $postarr['post_content'] = $postarr_t;
        }

        $slug = $r->get_param('slug');

        if (is_string($slug) && trim($slug) !== '' && sanitize_title($slug) !== $p->post_name) {
            $postarr['post_name'] = sanitize_title($slug);
        }

        if ($r->has_param('szulo')) {
            $szulo = (int) $r->get_param('szulo');

            if ($szulo !== (int) $p->post_parent) {
                if ($szulo === $id || ($szulo > 0 && (get_post_type($szulo) !== 'page' || in_array($id, get_post_ancestors($szulo), true)))) {
                    return SDH_Sales_Rest::hiba('Ez nem lehet a szülő oldal.', 422, 'ervenytelen');
                }

                $postarr['post_parent'] = max(0, $szulo);
            }
        }

        if ($r->has_param('sorrend')) {
            $postarr['menu_order'] = max(-1000, min(1000, (int) $r->get_param('sorrend')));
        }

        if ($r->has_param('sablon')) {
            $sablon = (string) $r->get_param('sablon');
            $ok     = array_keys(['' => 1] + array_flip((array) wp_get_theme()->get_page_templates(null, 'page')));

            if (!in_array($sablon, $ok, true)) {
                return SDH_Sales_Rest::hiba('Ismeretlen sablon.', 422, 'ervenytelen');
            }

            if ($sablon === '') {
                delete_post_meta($id, '_wp_page_template');
            } else {
                update_post_meta($id, '_wp_page_template', $sablon);
            }
        }

        if (count($postarr) > 1) {
            wp_update_post($postarr);
        }

        update_post_meta($id, '_sdh_szerkesztve', 1);

        return self::r_oldal($r);
    }

    /* =================================================================
     * Előnézet
     * ============================================================== */

    public static function r_elonezet(WP_REST_Request $r)
    {
        $id = (int) $r->get_param('post_id');
        $p  = get_post($id);

        if (!$p || $p->post_type !== 'page') {
            return SDH_Sales_Rest::hiba('Nincs ilyen oldal.', 404, 'nincs');
        }

        $sz    = SDH_Sales_Szekcio::ervenyesit_lista((array) $r->get_param('szekciok'));
        $token = strtolower(wp_generate_password(32, false, false));

        $fel = $r->get_param('feliratok');

        set_transient('sdh_sales_elonezet_' . $token, ['post_id' => $id, 'user' => get_current_user_id(), 'szekciok' => $sz, 'feliratok' => is_array($fel) ? SDH_Sales_Studio::feliratok_tisztit($fel, true) : null], 15 * MINUTE_IN_SECONDS);

        $url = add_query_arg(self::ELONEZET, $token, $p->post_status === 'publish' ? (string) get_permalink($p) : (string) get_preview_post_link($p));

        return new WP_REST_Response(['url' => $url, 'token' => $token, 'szekciok' => $sz], 200);
    }

    /** Az előnézeti kérés előkészítése (a sablon előtt). */
    public static function elonezet_indit(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a token maga a jogosítvány (+ bejelentkezett admin).
        $t = isset($_GET[self::ELONEZET]) ? preg_replace('/[^a-z0-9]/', '', strtolower((string) wp_unslash($_GET[self::ELONEZET]))) : '';

        if ($t === '' || strlen((string) $t) !== 32) {
            return;
        }

        $a = get_transient('sdh_sales_elonezet_' . $t);

        if (!is_array($a) || !SDH_Sales::admin() || (int) $a['user'] !== get_current_user_id()) {
            return;
        }

        self::$elonezet[(int) $a['post_id']] = (array) $a['szekciok'];

        if (class_exists('SDH_Sales_Studio')) {
            SDH_Sales_Studio::mod_be(is_array($a['feliratok'] ?? null) ? $a['feliratok'] : null);
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        add_filter('show_admin_bar', '__return_false');

        // Az építő előnézetét ne takarja a süti-sáv (a szerkesztő a lap tartalmát nézi, nem a hozzájárulást).
        if (class_exists('SDH_Sales_Suti')) {
            remove_action('wp_body_open', ['SDH_Sales_Suti', 'panel'], 5);
            remove_action('wp_footer', ['SDH_Sales_Suti', 'panel'], 1);
        }
    }

    /**
     * @param mixed $ertek
     * @return mixed
     */
    public static function elonezet_meta($ertek, $post_id, $kulcs, $egy)
    {
        if ($kulcs !== SDH_Sales_Tartalom::META || !isset(self::$elonezet[(int) $post_id])) {
            return $ertek;
        }

        $j = (string) wp_json_encode(self::$elonezet[(int) $post_id], JSON_UNESCAPED_UNICODE);

        return $egy ? [$j] : [$j];
    }

    /** Teszthez / más modulhoz: az előnézeti szekciók bekapcsolása egy oldalra. @param array<int, array<string, mixed>> $sz */
    public static function elonezet_beallit(int $post_id, array $sz): void
    {
        self::$elonezet[$post_id] = $sz;
    }

    /* =================================================================
     * Típusos elemek
     * ============================================================== */

    private static function nyelv(WP_REST_Request $r): string
    {
        $n  = sanitize_key((string) ($r->get_param('nyelv') ?: 'hu'));
        $ok = array_map('strval', (array) SDH_Sales::b('nyelvek', ['hu']));

        return in_array($n, $ok, true) ? $n : 'hu';
    }

    public static function r_elemek(WP_REST_Request $r)
    {
        $tipus = sanitize_key((string) $r->get_param('tipus'));
        $s     = SDH_Sales_Szekcio::elem_semak();

        if ($tipus === '') {
            global $wpdb;

            $db = [];

            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT tipus, COUNT(*) AS db FROM ' . SDH_Sales_Schema::t('tartalom') . ' WHERE nyelv = %s GROUP BY tipus', self::nyelv($r)), ARRAY_A) as $x) {
                $db[(string) $x['tipus']] = (int) $x['db'];
            }

            return new WP_REST_Response(['szamok' => $db], 200);
        }

        if (!isset($s[$tipus]) && $tipus !== 'menu') {
            return SDH_Sales_Rest::hiba('Ismeretlen elemtípus.', 400);
        }

        $lista = array_map(static fn ($e) => [
            'kulcs'       => $e['kulcs'],
            'cim'         => $e['cim'],
            'adat'        => $e['adat'],
            'sorrend'     => $e['sorrend'],
            'aktiv'       => $e['aktiv'],
            'szerkesztve' => $e['szerkesztve'],
            'modositva'   => SDH_Sales_Admin::ts($e['modositva']),
        ], SDH_Sales_Tartalom::lista($tipus, false, self::nyelv($r)));

        return new WP_REST_Response(['lista' => $lista], 200);
    }

    public static function r_elem_ment(WP_REST_Request $r)
    {
        $tipus = sanitize_key((string) $r->get_param('tipus'));
        $s     = SDH_Sales_Szekcio::elem_semak()[$tipus] ?? null;

        if ($s === null) {
            return SDH_Sales_Rest::hiba('Ismeretlen elemtípus.', 400);
        }

        $nyelv   = self::nyelv($r);
        $adat    = SDH_Sales_Szekcio::elem_tisztit($tipus, (array) $r->get_param('adat'));
        $eredeti = mb_substr(trim(sanitize_text_field((string) $r->get_param('eredeti'))), 0, 120);

        // Meglévő elem kulcsa nem változik (a kezdőtartalom kulcsa más alakú is lehet, mint amit az új elem kap).
        if ($eredeti !== '') {
            if (SDH_Sales_Tartalom::egy($tipus, $eredeti, $nyelv) === null) {
                return SDH_Sales_Rest::hiba('Nincs ilyen elem.', 404, 'nincs');
            }

            $adat[$s['kulcsmezo']] = $eredeti;
        }

        $kulcs = (string) ($adat[$s['kulcsmezo']] ?? '');

        if ($kulcs === '') {
            return SDH_Sales_Rest::hiba('A kulcs kötelező (kisbetű, szám, kötőjel).', 422, 'ervenytelen');
        }

        foreach ($s['mezok'] as $m) {
            if (!empty($m['kotelezo']) && trim((string) (is_scalar($adat[$m['k']] ?? null) ? $adat[$m['k']] : '')) === '') {
                return SDH_Sales_Rest::hiba('„' . $m['c'] . '” kötelező.', 422, 'ervenytelen');
            }
        }

        $regi = SDH_Sales_Tartalom::egy($tipus, $kulcs, $nyelv);

        if ($eredeti === '' && $regi !== null) {
            return SDH_Sales_Rest::hiba('Ilyen kulcsú elem már van.', 409, 'utkozes');
        }

        // A sémán kívüli, korábban tárolt mezők (más modul adatai) megmaradnak.
        if ($regi !== null) {
            $adat += array_diff_key($regi['adat'], $adat);
        }

        $cim     = mb_substr((string) ($adat[$s['cimmezo']] ?? $kulcs), 0, 255);
        $sorrend = $r->has_param('sorrend') ? (int) $r->get_param('sorrend') : ($regi === null ? count(SDH_Sales_Tartalom::lista($tipus, false, $nyelv)) : null);
        $aktiv   = $r->has_param('aktiv') ? (bool) $r->get_param('aktiv') : null;

        SDH_Sales_Tartalom::ment($tipus, $kulcs, $adat, $cim, $sorrend, $aktiv, $nyelv, true);

        return new WP_REST_Response(['ok' => true, 'elem' => SDH_Sales_Tartalom::egy($tipus, $kulcs, $nyelv)], 200);
    }

    public static function r_elem_torol(WP_REST_Request $r)
    {
        $tipus = sanitize_key((string) $r->get_param('tipus'));

        if (!isset(SDH_Sales_Szekcio::elem_semak()[$tipus])) {
            return SDH_Sales_Rest::hiba('Ismeretlen elemtípus.', 400);
        }

        $ok = SDH_Sales_Tartalom::torol($tipus, (string) $r->get_param('kulcs'), self::nyelv($r));

        return $ok ? new WP_REST_Response(['ok' => true], 200) : SDH_Sales_Rest::hiba('Nincs ilyen elem.', 404, 'nincs');
    }

    public static function r_sorrend(WP_REST_Request $r)
    {
        $tipus = sanitize_key((string) $r->get_param('tipus'));

        if (!isset(SDH_Sales_Szekcio::elem_semak()[$tipus])) {
            return SDH_Sales_Rest::hiba('Ismeretlen elemtípus.', 400);
        }

        $nyelv = self::nyelv($r);
        $n     = 0;

        foreach (array_values((array) $r->get_param('kulcsok')) as $i => $k) {
            $e = SDH_Sales_Tartalom::egy($tipus, (string) $k, $nyelv);

            if ($e !== null && $e['sorrend'] !== $i) {
                SDH_Sales_Tartalom::ment($tipus, (string) $k, $e['adat'], $e['cim'], $i, $e['aktiv'], $nyelv, true);
                $n++;
            }
        }

        return new WP_REST_Response(['ok' => true, 'modositva' => $n], 200);
    }

    /* =================================================================
     * Menük
     * ============================================================== */

    /**
     * Menüfa tisztítása: {cim, oldal | url, uj?, gyerekek[]}, legfeljebb 3 szint, 80 elem.
     *
     * @param array<int, mixed> $elemek
     * @return array<int, array<string, mixed>>
     */
    public static function menu_tisztit(array $elemek, int $szint = 1, int &$db = 0): array
    {
        $ki = [];

        foreach (array_values($elemek) as $e) {
            if (!is_array($e) || $db >= 80) {
                continue;
            }

            $cim = mb_substr(sanitize_text_field((string) ($e['cim'] ?? '')), 0, 80);

            if ($cim === '') {
                continue;
            }

            $db++;
            $x     = ['cim' => $cim];
            $oldal = trim((string) ($e['oldal'] ?? ''), '/');

            if ($oldal !== '') {
                $x['oldal'] = implode('/', array_filter(array_map('sanitize_title', explode('/', $oldal))));
            } else {
                $x['url'] = SDH_Sales_Szekcio::url((string) ($e['url'] ?? ''));
            }

            if (!empty($e['uj'])) {
                $x['uj'] = true;
            }

            $gy = is_array($e['gyerekek'] ?? null) && $szint < 3 ? self::menu_tisztit($e['gyerekek'], $szint + 1, $db) : [];

            if ($gy !== []) {
                $x['gyerekek'] = $gy;
            }

            $ki[] = $x;
        }

        return $ki;
    }

    public static function r_menuk(WP_REST_Request $r): WP_REST_Response
    {
        $ki = [];

        foreach (self::MENU_HELYEK as $h => $cim) {
            $m      = SDH_Sales_Tartalom::egy('menu', $h, self::nyelv($r));
            $ki[$h] = ['cim' => $cim, 'elemek' => is_array($m['adat']['elemek'] ?? null) ? $m['adat']['elemek'] : []];
        }

        return new WP_REST_Response(['helyek' => $ki], 200);
    }

    public static function r_menu_ment(WP_REST_Request $r)
    {
        $hely = (string) $r['hely'];

        if (!isset(self::MENU_HELYEK[$hely])) {
            return SDH_Sales_Rest::hiba('Ismeretlen menühely.', 400);
        }

        $nyelv  = self::nyelv($r);
        $elemek = self::menu_tisztit((array) $r->get_param('elemek'));
        $regi   = SDH_Sales_Tartalom::egy('menu', $hely, $nyelv);
        $adat   = (is_array($regi['adat'] ?? null) ? $regi['adat'] : []);
        $adat['kulcs']  = $hely;
        $adat['elemek'] = $elemek;

        SDH_Sales_Tartalom::ment('menu', $hely, $adat, self::MENU_HELYEK[$hely], $regi['sorrend'] ?? 0, true, $nyelv, true);

        return new WP_REST_Response(['ok' => true, 'elemek' => $elemek], 200);
    }

    /* =================================================================
     * Kezdőtartalom újratöltése
     * ============================================================== */

    public static function r_betolt(WP_REST_Request $r): WP_REST_Response
    {
        $k  = (bool) $r->get_param('kenyszer');
        $db = SDH_Sales_Tartalom::betolt($k);

        SDH_Sales_Naplo::ir('tartalom', 'Kezdőtartalom újratöltve' . ($k ? ' (a nem szerkesztettek frissítve)' : ' (csak a hiányzók)') . ': ' . (string) wp_json_encode($db), 'figyel');

        return new WP_REST_Response(['ok' => true, 'db' => $db], 200);
    }
}
