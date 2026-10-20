<?php
/**
 * Értékesítés – Stúdió (a teljes szerkesztő magja): szerkesztő mód az élő előnézetben, a téma elemeinek jelölése,
 * feliratok (a téma kódba írt szövegeinek felülírása), oldal-piszkozat, revíziók, oldalműveletek (másolás, kuka,
 * kezdőlap), kanonikus cím, és a webhelyen a lebegő „Szerkesztés” gomb a bejelentkezett szerkesztőnek.
 *
 * Szerződés a témával:
 *   SDH_Sales_Studio::attr(string $ut): string – szerkesztő módban ` data-s-szerk="<ut>"`, egyébként ''. Látogatónak soha.
 *     Út: `o:{post_id}/{szekcio_index}/{mezo}`, listaelem: `o:{post_id}/{i}/{mezo}/{elem_index}/{almezo}`,
 *     felirat: `f:{kulcs}`, beállítás: `b:{kulcs.pontozva}`, menüpont: `m:{hely}/{index}`, tartalomtár: `t:{tipus}/{kulcs}/{mezo}`.
 *   sdh_tema_feliratok(): array – a téma felirat-jegyzéke (kulcs => alap, csoport, leiras); a felülírások a
 *     `sdh_sales_feliratok` opcióban (ezt az osztály kezeli: mentés, napló, visszaállítás, export / import).
 *
 * Szerkesztő mód: csak az admin-előnézetben (érvényes, 15 perces token + ugyanaz a bejelentkezett felhasználó –
 * SDH_Sales_Admin_Tartalom::elonezet_indit kapcsolja be). Ilyenkor minden szekció előtt egy láthatatlan
 * `<template data-s-szakasz="i">` jelölő áll (a Stúdió ebből tudja, melyik szekcióra kattintottak).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Studio
{
    public const OPT_FELIRAT = 'sdh_sales_feliratok';
    public const META_VAZLAT = '_sdh_szekciok_vazlat';
    public const META_KANON  = '_sdh_kanonikus';
    public const UT_MINTA    = '~^[ofbmt]:[A-Za-z0-9_.\-/]{1,200}$~';

    private static bool $mod = false;

    /** @var array<string, string>|null az előnézet még nem mentett feliratai */
    private static ?array $felirat_elo = null;

    public static function init(): void
    {
        $ir  = ['admin_ir', 240, 60];
        $olv = ['admin_olv', 900, 60];

        SDH_Sales_Rest::ut('/admin/feliratok', 'GET', [self::class, 'r_feliratok'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/feliratok', 'POST', [self::class, 'r_feliratok_ment'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/oldal/(?P<id>\d+)/vazlat', 'POST', [self::class, 'r_vazlat'], 'admin', ['admin_vazlat', 600, 60]);
        SDH_Sales_Rest::ut('/admin/oldal/(?P<id>\d+)/vazlat/elvet', 'POST', [self::class, 'r_vazlat_elvet'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/oldal/(?P<id>\d+)/reviziok', 'GET', [self::class, 'r_reviziok'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/oldal/(?P<id>\d+)/masol', 'POST', [self::class, 'r_masol'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/oldal/(?P<id>\d+)/kuka', 'POST', [self::class, 'r_kuka'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/oldal/(?P<id>\d+)/vissza', 'POST', [self::class, 'r_vissza'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/oldal/(?P<id>\d+)/kezdolap', 'POST', [self::class, 'r_kezdolap'], 'admin', $ir);

        add_filter('sdh_tema_szekcio', [self::class, 'jelolo'], 1, 2);
        add_filter('get_canonical_url', [self::class, 'kanonikus'], 20, 2);
        add_filter('sdh_sales_visszaallit_feliratok', [self::class, 'feliratok_visszaallit'], 10, 3);
        add_action('wp_footer', [self::class, 'szerk_gomb'], 50);
    }

    /* =================================================================
     * Szerkesztő mód, jelölés
     * ============================================================== */

    /** Az előnézet bekapcsolja (csak érvényes admin-tokennel). @param array<string, string>|null $feliratok */
    public static function mod_be(?array $feliratok = null): void
    {
        if (!SDH_Sales::admin()) {
            return;
        }

        self::$mod = true;

        if (is_array($feliratok)) {
            $alap = get_option(self::OPT_FELIRAT, []);
            self::$felirat_elo = array_merge(is_array($alap) ? $alap : [], self::feliratok_tisztit($feliratok, true));
            add_filter('pre_option_' . self::OPT_FELIRAT, static fn () => self::$felirat_elo);
        }
    }

    /** Teszthez: a mód kikapcsolása. */
    public static function mod_ki(): void
    {
        self::$mod = false;
        self::$felirat_elo = null;
        remove_all_filters('pre_option_' . self::OPT_FELIRAT);
    }

    public static function mod(): bool
    {
        return self::$mod && SDH_Sales::admin();
    }

    /** A téma ezzel jelöli a szerkeszthető elemeket. */
    public static function attr(string $ut): string
    {
        if (!self::mod() || preg_match(self::UT_MINTA, $ut) !== 1 || strpos($ut, '..') !== false) {
            return '';
        }

        return ' data-s-szerk="' . esc_attr($ut) . '"';
    }

    /**
     * Szekció-jelölő az előnézetben (a sablon előtt, a `sdh_tema_szekcio` szűrőből).
     *
     * @param array<string, mixed> $sz
     * @return array<string, mixed>
     */
    public static function jelolo($sz, $tipus = '')
    {
        if (self::mod() && is_array($sz) && isset($sz['_i'])) {
            echo '<template data-s-szakasz="' . (int) $sz['_i'] . '"></template>'; // phpcs:ignore WordPress.Security.EscapeOutput -- egész szám
        }

        return $sz;
    }

    /* =================================================================
     * Feliratok
     * ============================================================== */

    /** @return array<string, array{alap: string, csoport: string, leiras: string}> */
    public static function jegyzek(): array
    {
        if (!function_exists('sdh_tema_feliratok')) {
            return [];
        }

        try {
            $j = sdh_tema_feliratok();
        } catch (Throwable $x) {
            return [];
        }

        $ki = [];

        foreach ((array) $j as $k => $v) {
            if (!is_string($k) || preg_match('/^[a-z0-9_.\-]{1,80}$/', $k) !== 1) {
                continue;
            }

            $v      = is_array($v) ? $v : ['alap' => (string) $v];
            $ki[$k] = [
                'alap'    => (string) ($v['alap'] ?? ''),
                'csoport' => (string) ($v['csoport'] ?? 'Egyéb'),
                'leiras'  => (string) ($v['leiras'] ?? ''),
            ];
        }

        return $ki;
    }

    /**
     * @param array<mixed> $be
     * @return array<string, string>
     */
    public static function feliratok_tisztit(array $be, bool $uresis = false): array
    {
        $ki = [];

        foreach ($be as $k => $v) {
            if (!is_string($k) || preg_match('/^[a-z0-9_.\-]{1,80}$/', $k) !== 1 || !is_scalar($v)) {
                continue;
            }

            $s = mb_substr(trim(sanitize_text_field((string) $v)), 0, 500);

            if ($s !== '' || $uresis) {
                $ki[$k] = $s;
            }
        }

        return $ki;
    }

    /** A felirat értéke (a téma `sdh_tema_f` is ugyanezt adja). */
    public static function felirat(string $kulcs, string $alap): string
    {
        $m = get_option(self::OPT_FELIRAT, []);

        return is_array($m) && isset($m[$kulcs]) && is_string($m[$kulcs]) && $m[$kulcs] !== '' ? $m[$kulcs] : $alap;
    }

    public static function r_feliratok(): WP_REST_Response
    {
        $j  = self::jegyzek();
        $m  = get_option(self::OPT_FELIRAT, []);
        $m  = is_array($m) ? $m : [];
        $cs = [];

        foreach ($j as $k => $x) {
            $cs[$x['csoport']][] = ['kulcs' => $k, 'alap' => $x['alap'], 'leiras' => $x['leiras'], 'ertek' => isset($m[$k]) ? (string) $m[$k] : ''];
        }

        // A jegyzékben (már) nem szereplő felülírások is látszanak, hogy törölhetők legyenek.
        foreach ($m as $k => $v) {
            if (!isset($j[$k]) && is_string($k)) {
                $cs['Jegyzéken kívül'][] = ['kulcs' => $k, 'alap' => '', 'leiras' => 'A téma már nem használja.', 'ertek' => (string) $v];
            }
        }

        $ki = [];

        foreach ($cs as $c => $l) {
            $ki[] = ['cim' => (string) $c, 'elemek' => $l];
        }

        return new WP_REST_Response(['csoportok' => $ki, 'van_jegyzek' => $j !== [], 'db' => count($j), 'felulirva' => count($m)], 200);
    }

    /** {ertekek: {kulcs: szöveg}} – üres vagy az alappal egyező érték: a felülírás törlődik. */
    public static function r_feliratok_ment(WP_REST_Request $r)
    {
        $be = $r->get_param('ertekek');

        if (!is_array($be)) {
            return SDH_Sales_Rest::hiba('Hibás feliratlista.', 400);
        }

        $j    = self::jegyzek();
        $regi = get_option(self::OPT_FELIRAT, []);
        $regi = is_array($regi) ? $regi : [];
        $uj   = $regi;

        foreach (self::feliratok_tisztit($be, true) as $k => $v) {
            if ($v === '' || (isset($j[$k]) && $v === $j[$k]['alap'])) {
                unset($uj[$k]);
            } else {
                $uj[$k] = $v;
            }
        }

        if (count($uj) > 2000) {
            return SDH_Sales_Rest::hiba('Túl sok felirat.', 400);
        }

        ksort($uj);

        if ($uj !== $regi) {
            update_option(self::OPT_FELIRAT, $uj, true);
            SDH_Sales_Naplo::ir('felirat', 'Feliratok módosítva (' . count(array_diff_assoc($uj, $regi)) + count(array_diff_key($regi, $uj)) . ')', 'info', 'feliratok', 0, $regi, $uj);
            do_action('sdh_sales_tartalom_mentve', 'felirat', '', 'hu');
        }

        return new WP_REST_Response(['ok' => true, 'felulirva' => count($uj)], 200);
    }

    /** @param mixed $elotte */
    public static function feliratok_visszaallit(bool $ok, int $id, $elotte): bool
    {
        if (!is_array($elotte)) {
            return false;
        }

        update_option(self::OPT_FELIRAT, self::feliratok_tisztit($elotte), true);

        return true;
    }

    /* =================================================================
     * Oldal: piszkozat, revíziók, műveletek
     * ============================================================== */

    private static function oldal(int $id): ?WP_Post
    {
        $p = get_post($id);

        return $p instanceof WP_Post && $p->post_type === 'page' ? $p : null;
    }

    /** @return array{szekciok: array, ido: int, ki: string}|null */
    public static function vazlat(int $id): ?array
    {
        $v = json_decode((string) get_post_meta($id, self::META_VAZLAT, true), true);

        if (!is_array($v) || !is_array($v['szekciok'] ?? null)) {
            return null;
        }

        $u = get_userdata((int) ($v['user'] ?? 0));

        return ['szekciok' => array_values($v['szekciok']), 'ido' => (int) ($v['ido'] ?? 0), 'ki' => $u ? (string) $u->display_name : ''];
    }

    public static function vazlat_torol(int $id): void
    {
        delete_post_meta($id, self::META_VAZLAT);
    }

    public static function r_vazlat(WP_REST_Request $r)
    {
        $id = (int) $r['id'];

        if (self::oldal($id) === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen oldal.', 404, 'nincs');
        }

        $sz = $r->get_param('szekciok');

        if (!is_array($sz)) {
            return SDH_Sales_Rest::hiba('Hibás szekciólista.', 400);
        }

        $tiszta = SDH_Sales_Szekcio::ervenyesit_lista($sz);
        $ido    = time();

        update_post_meta($id, self::META_VAZLAT, wp_slash((string) wp_json_encode(['szekciok' => $tiszta, 'ido' => $ido, 'user' => get_current_user_id()], JSON_UNESCAPED_UNICODE)));

        return new WP_REST_Response(['ok' => true, 'ido' => $ido], 200);
    }

    public static function r_vazlat_elvet(WP_REST_Request $r)
    {
        $id = (int) $r['id'];

        if (self::oldal($id) === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen oldal.', 404, 'nincs');
        }

        self::vazlat_torol($id);

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function r_reviziok(WP_REST_Request $r)
    {
        $id = (int) $r['id'];

        if (self::oldal($id) === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen oldal.', 404, 'nincs');
        }

        $l = SDH_Sales_Naplo::lista(['ref_tipus' => 'szekciok', 'ref_id' => $id, 'db' => 60, 'oldal' => max(1, (int) $r->get_param('oldal'))]);

        return new WP_REST_Response(['lista' => array_map([SDH_Sales_Admin::class, 'naplo_sor'], $l['sorok']), 'ossz' => (int) $l['ossz']], 200);
    }

    /** Másolat vázlatként: cím, szekciók, tartalom, sablon, SEO. */
    public static function r_masol(WP_REST_Request $r)
    {
        $p = self::oldal((int) $r['id']);

        if ($p === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen oldal.', 404, 'nincs');
        }

        $cim = mb_substr(sanitize_text_field((string) ($r->get_param('cim') ?: $p->post_title . ' (másolat)')), 0, 200);
        $id  = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => $cim,
            'post_name'    => sanitize_title($cim),
            'post_parent'  => (int) $p->post_parent,
            'post_content' => (string) $p->post_content,
            'menu_order'   => (int) $p->menu_order,
        ], true);

        if (is_wp_error($id)) {
            return SDH_Sales_Rest::hiba($id->get_error_message(), 400);
        }

        SDH_Sales_Tartalom::szekciok_ment((int) $id, SDH_Sales_Tartalom::szekciok((int) $p->ID), false);
        SDH_Sales_Exportimport::seo_ir((int) $id, SDH_Sales_Exportimport::seo((int) $p->ID));

        $sablon = (string) get_post_meta($p->ID, '_wp_page_template', true);

        if ($sablon !== '') {
            update_post_meta((int) $id, '_wp_page_template', $sablon);
        }

        update_post_meta((int) $id, '_sdh_szerkesztve', 1);
        SDH_Sales_Naplo::ir('oldal', 'Oldal másolva: ' . $p->post_title . ' → ' . $cim, 'info', 'oldal', (int) $id);

        return new WP_REST_Response(['ok' => true, 'id' => (int) $id], 201);
    }

    public static function r_kuka(WP_REST_Request $r)
    {
        $p = self::oldal((int) $r['id']);

        if ($p === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen oldal.', 404, 'nincs');
        }

        if ((int) get_option('page_on_front') === (int) $p->ID) {
            return SDH_Sales_Rest::hiba('A kezdőlap nem tehető a kukába. Előbb jelölj ki másik kezdőlapot.', 409, 'kezdolap');
        }

        wp_trash_post((int) $p->ID);
        SDH_Sales_Naplo::ir('oldal', 'Oldal a kukába: ' . $p->post_title, 'figyel', 'oldal', (int) $p->ID);

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function r_vissza(WP_REST_Request $r)
    {
        $p = get_post((int) $r['id']);

        if (!$p instanceof WP_Post || !in_array($p->post_type, ['page', 'post'], true) || $p->post_status !== 'trash') {
            return SDH_Sales_Rest::hiba('Nincs ilyen elem a kukában.', 404, 'nincs');
        }

        wp_untrash_post((int) $p->ID);
        SDH_Sales_Naplo::ir($p->post_type === 'page' ? 'oldal' : 'blog', 'Visszaállítva a kukából: ' . $p->post_title, 'info', $p->post_type === 'page' ? 'oldal' : 'cikk', (int) $p->ID);

        return new WP_REST_Response(['ok' => true, 'statusz' => (string) get_post_status((int) $p->ID)], 200);
    }

    public static function r_kezdolap(WP_REST_Request $r)
    {
        $p = self::oldal((int) $r['id']);

        if ($p === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen oldal.', 404, 'nincs');
        }

        if ($p->post_status !== 'publish') {
            return SDH_Sales_Rest::hiba('Csak közzétett oldal lehet kezdőlap.', 409, 'nem_publikus');
        }

        $regi = (int) get_option('page_on_front');
        update_option('show_on_front', 'page');
        update_option('page_on_front', (int) $p->ID);
        SDH_Sales_Naplo::ir('oldal', 'Új kezdőlap: ' . $p->post_title, 'figyel', 'oldal', (int) $p->ID, ['page_on_front' => $regi], ['page_on_front' => (int) $p->ID]);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /** Médiatár-elem azonosítója a címéből (a rejtés álnév-címét is visszafejti). */
    public static function kep_id(string $url): int
    {
        $url = trim($url);

        if ($url === '') {
            return 0;
        }

        $id = (int) attachment_url_to_postid($url);

        if ($id > 0 || !class_exists('SDH_Sales_Rejtes')) {
            return $id;
        }

        $home = rtrim((string) get_option('home'), '/');

        foreach (SDH_Sales_Rejtes::aliasok() as $alias => $valodi) {
            $a = $home . '/' . $alias . '/';

            if (strpos($url, $a) === 0) {
                $id = (int) attachment_url_to_postid($home . '/' . $valodi . '/' . substr($url, strlen($a)));

                if ($id > 0) {
                    return $id;
                }
            }
        }

        return 0;
    }

    /* =================================================================
     * Kanonikus cím (oldalanként felülírható)
     * ============================================================== */

    /** @param mixed $url @param mixed $post */
    public static function kanonikus($url, $post)
    {
        $id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;
        $k  = $id > 0 ? trim((string) get_post_meta($id, self::META_KANON, true)) : '';

        return $k !== '' ? $k : $url;
    }

    /* =================================================================
     * „Szerkesztés” gomb a webhelyen (csak bejelentkezett szerkesztőnek)
     * ============================================================== */

    public static function szerk_gomb(): void
    {
        if (is_admin() || self::$mod || !is_user_logged_in() || !SDH_Sales::admin() || is_customize_preview() || wp_doing_ajax()) {
            return;
        }

        $id   = is_page() ? (int) get_queried_object_id() : 0;
        $cel  = $id > 0 ? SDH_Sales_Admin::url('oldalak/' . $id) : SDH_Sales_Admin::url(is_singular('post') ? 'blog/' . (int) get_queried_object_id() : 'oldalak');
        $szov = $id > 0 ? 'Oldal szerkesztése' : (is_singular('post') ? 'Cikk szerkesztése' : 'Stúdió megnyitása');

        // Semleges osztálynevek, saját (a téma CSS-étől független) stílus; a kimeneti szűrő nem írja át.
        echo '<a class="s-stud-gomb" href="' . esc_url($cel) . '" title="' . esc_attr($szov) . '"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg><span>' . esc_html($szov) . '</span></a>'
            . '<style>.s-stud-gomb{position:fixed;left:16px;bottom:16px;z-index:99990;display:inline-flex;align-items:center;gap:8px;min-height:40px;padding:0 16px;border-radius:999px;'
            . 'background:linear-gradient(135deg,#6d5efc,#22d3ee);color:#fff;font:600 14px/1 system-ui,sans-serif;text-decoration:none;box-shadow:0 8px 24px rgba(20,16,60,.35)}'
            . '.s-stud-gomb:hover{filter:brightness(1.08)}.s-stud-gomb:focus-visible{outline:3px solid #fff;outline-offset:2px;box-shadow:0 0 0 6px #6d5efc}'
            . '@media print{.s-stud-gomb{display:none}}</style>';
    }
}
