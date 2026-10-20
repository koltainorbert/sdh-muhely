<?php
/**
 * Értékesítés – Stúdió: a tartalomtárak oldalpanel nélküli részei – blog (bejegyzések), médiatár, kuka
 * (oldalak, cikkek, törölt tartalom-elemek), e-mail-sablon előnézet és próbalevél, űrlapmezők felülírása.
 *
 * REST (mind admin-jog + nonce):
 *   GET  /admin/blog, /admin/blog/kategoriak, /admin/blog/{id};  POST /admin/blog, /admin/blog/{id}, /admin/blog/{id}/kuka,
 *        /admin/blog/{id}/vissza, /admin/blog/kategoria
 *   GET  /admin/media;  POST /admin/media/{id}
 *   GET  /admin/kuka;   POST /admin/kuka/{id}/vegleges (oldal / cikk végleges törlése a kukából)
 *   POST /admin/email/elonezet, /admin/email/proba
 *   GET  /admin/urlapok; POST /admin/urlapok
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Studio_Tar
{
    public const OPT_URLAP = 'sdh_sales_urlap_felulir';

    /** Az űrlapok mezői közül ezek mindig kötelezők és láthatók (a leadhez kellenek). */
    private const URLAP_ZAROLT = ['nev', 'email'];

    public static function init(): void
    {
        $ir  = ['admin_ir', 240, 60];
        $olv = ['admin_olv', 900, 60];

        SDH_Sales_Rest::ut('/admin/blog', 'GET', [self::class, 'r_blog_lista'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/blog', 'POST', [self::class, 'r_blog_uj'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/blog/kategoriak', 'GET', [self::class, 'r_kategoriak'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/blog/kategoria', 'POST', [self::class, 'r_kategoria_uj'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/blog/(?P<id>\d+)', 'GET', [self::class, 'r_blog'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/blog/(?P<id>\d+)', 'POST', [self::class, 'r_blog_ment'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/blog/(?P<id>\d+)/kuka', 'POST', [self::class, 'r_blog_kuka'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/blog/(?P<id>\d+)/vissza', 'POST', [SDH_Sales_Studio::class, 'r_vissza'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/media', 'GET', [self::class, 'r_media'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/media/(?P<id>\d+)', 'POST', [self::class, 'r_media_ment'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/kuka', 'GET', [self::class, 'r_kuka'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/kuka/(?P<id>\d+)/vegleges', 'POST', [self::class, 'r_vegleges'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/email/elonezet', 'POST', [self::class, 'r_email_elonezet'], 'admin', ['admin_elonezet', 120, 60]);
        SDH_Sales_Rest::ut('/admin/email/proba', 'POST', [self::class, 'r_email_proba'], 'admin', ['admin_proba', 10, 600]);
        SDH_Sales_Rest::ut('/admin/urlapok', 'GET', [self::class, 'r_urlapok'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/urlapok', 'POST', [self::class, 'r_urlapok_ment'], 'admin', $ir);

        add_filter('sdh_sales_urlap_mezok', [self::class, 'urlap_felulir'], 10, 2);
        add_filter('sdh_sales_visszaallit_urlapok', [self::class, 'urlap_visszaallit'], 10, 3);
    }

    /* =================================================================
     * Blog
     * ============================================================== */

    private static function cikk(int $id): ?WP_Post
    {
        $p = get_post($id);

        return $p instanceof WP_Post && $p->post_type === 'post' ? $p : null;
    }

    /** @return array<string, mixed> */
    private static function cikk_sor(WP_Post $p): array
    {
        $kep = (int) get_post_thumbnail_id($p);

        return [
            'id'         => (int) $p->ID,
            'cim'        => (string) $p->post_title,
            'slug'       => (string) $p->post_name,
            'statusz'    => (string) $p->post_status,
            'datum'      => (int) (strtotime((string) $p->post_date_gmt . ' UTC') ?: 0),
            'modositva'  => (int) (strtotime((string) $p->post_modified_gmt . ' UTC') ?: 0),
            'kategoriak' => array_values(array_map(static fn ($t) => (string) $t->name, get_the_category((int) $p->ID))),
            'kep'        => $kep > 0 ? (string) wp_get_attachment_image_url($kep, 'thumbnail') : '',
            'url'        => (string) get_permalink($p),
            'szerkesztve' => (bool) get_post_meta($p->ID, '_sdh_szerkesztve', true),
        ];
    }

    public static function r_blog_lista(WP_REST_Request $r): WP_REST_Response
    {
        $st = sanitize_key((string) $r->get_param('statusz'));
        $q  = [
            'post_type'      => 'post',
            'post_status'    => in_array($st, ['publish', 'draft', 'future', 'private', 'trash'], true) ? $st : ['publish', 'draft', 'future', 'private', 'pending'],
            'posts_per_page' => max(1, min(100, (int) ($r->get_param('db') ?: 25))),
            'paged'          => max(1, (int) ($r->get_param('oldal') ?: 1)),
            'orderby'        => 'date',
            'order'          => 'DESC',
            's'              => mb_substr(sanitize_text_field((string) $r->get_param('q')), 0, 100),
        ];

        $kat = (int) $r->get_param('kategoria');

        if ($kat > 0) {
            $q['cat'] = $kat;
        }

        $w = new WP_Query($q);

        return new WP_REST_Response(['lista' => array_map([self::class, 'cikk_sor'], $w->posts), 'ossz' => (int) $w->found_posts, 'oldal' => (int) $q['paged'], 'db' => (int) $q['posts_per_page']], 200);
    }

    public static function r_kategoriak(): WP_REST_Response
    {
        $t = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);

        return new WP_REST_Response(['lista' => is_array($t) ? array_values(array_map(static fn ($x) => ['id' => (int) $x->term_id, 'nev' => (string) $x->name, 'db' => (int) $x->count], $t)) : []], 200);
    }

    public static function r_kategoria_uj(WP_REST_Request $r)
    {
        $nev = mb_substr(sanitize_text_field((string) $r->get_param('nev')), 0, 80);

        if ($nev === '') {
            return SDH_Sales_Rest::hiba('Add meg a kategória nevét.', 400);
        }

        $t = term_exists($nev, 'category') ?: wp_insert_term($nev, 'category');

        if (is_wp_error($t)) {
            return SDH_Sales_Rest::hiba($t->get_error_message(), 400);
        }

        return new WP_REST_Response(['ok' => true, 'id' => (int) (is_array($t) ? $t['term_id'] : $t), 'nev' => $nev], 201);
    }

    public static function r_blog(WP_REST_Request $r)
    {
        $p = self::cikk((int) $r['id']);

        if ($p === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen cikk.', 404, 'nincs');
        }

        $kep = (int) get_post_thumbnail_id($p);
        $tz  = wp_timezone();

        return new WP_REST_Response(self::cikk_sor($p) + [
            'kivonat'      => (string) $p->post_excerpt,
            'tartalom'     => (string) $p->post_content,
            'kategoria_id' => array_values(array_map('intval', wp_get_post_categories((int) $p->ID))),
            'cimkek'       => implode(', ', wp_get_post_tags((int) $p->ID, ['fields' => 'names'])),
            'kep_url'      => $kep > 0 ? (string) wp_get_attachment_url($kep) : '',
            'nap'          => (new DateTimeImmutable((string) $p->post_date_gmt, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d'),
            'ora'          => (new DateTimeImmutable((string) $p->post_date_gmt, new DateTimeZone('UTC')))->setTimezone($tz)->format('H:i'),
            'seo'          => SDH_Sales_Exportimport::seo((int) $p->ID) + ['noindex' => (string) get_post_meta($p->ID, '_sdh_noindex', true) === '1'],
        ], 200);
    }

    public static function r_blog_uj(WP_REST_Request $r)
    {
        $cim = mb_substr(sanitize_text_field((string) $r->get_param('cim')), 0, 200);

        if ($cim === '') {
            return SDH_Sales_Rest::hiba('Add meg a cikk címét.', 400);
        }

        $id = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => $cim, 'post_name' => sanitize_title($cim)], true);

        if (is_wp_error($id)) {
            return SDH_Sales_Rest::hiba($id->get_error_message(), 400);
        }

        update_post_meta((int) $id, '_sdh_szerkesztve', 1);
        SDH_Sales_Naplo::ir('blog', 'Új cikk: ' . $cim, 'info', 'cikk', (int) $id);

        return new WP_REST_Response(['ok' => true, 'id' => (int) $id], 201);
    }

    public static function r_blog_ment(WP_REST_Request $r)
    {
        $p = self::cikk((int) $r['id']);

        if ($p === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen cikk.', 404, 'nincs');
        }

        $id  = (int) $p->ID;
        $arr = ['ID' => $id];

        if ($r->has_param('cim')) {
            $c = mb_substr(sanitize_text_field((string) $r->get_param('cim')), 0, 200);

            if ($c === '') {
                return SDH_Sales_Rest::hiba('A cím kötelező.', 422, 'ervenytelen');
            }

            $arr['post_title'] = $c;
        }

        if ($r->has_param('slug') && trim((string) $r->get_param('slug')) !== '') {
            $arr['post_name'] = sanitize_title((string) $r->get_param('slug'));
        }

        if ($r->has_param('kivonat')) {
            $arr['post_excerpt'] = mb_substr(sanitize_textarea_field((string) $r->get_param('kivonat')), 0, 600);
        }

        if ($r->has_param('tartalom')) {
            $arr['post_content'] = SDH_Sales_Szekcio::html((string) $r->get_param('tartalom'));
        }

        $st = (string) $r->get_param('statusz');

        if (in_array($st, ['publish', 'draft', 'private'], true)) {
            $arr['post_status'] = $st;
        }

        $nap = (string) $r->get_param('nap');

        if ($nap !== '') {
            $ora = (string) ($r->get_param('ora') ?: '09:00');

            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $nap, $dn) !== 1 || !checkdate((int) $dn[2], (int) $dn[3], (int) $dn[1])
                || preg_match('/^(\d{1,2}):(\d{2})$/', $ora, $on) !== 1 || (int) $on[1] > 23 || (int) $on[2] > 59) {
                return SDH_Sales_Rest::hiba('Hibás dátum vagy időpont.', 422, 'ervenytelen');
            }

            $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', $nap . ' ' . $ora, wp_timezone());

            if ($d === false) {
                return SDH_Sales_Rest::hiba('Hibás dátum.', 422, 'ervenytelen');
            }

            $arr['post_date']     = $d->format('Y-m-d H:i:s');
            $arr['post_date_gmt'] = $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $arr['edit_date']     = true;

            // Jövőbeli dátum közzétéve: ütemezett.
            if (($arr['post_status'] ?? $p->post_status) === 'publish' && $d->getTimestamp() > time() + 60) {
                $arr['post_status'] = 'future';
            }
        }

        $elotte = ['cim' => $p->post_title, 'statusz' => $p->post_status, 'tartalom_hossz' => strlen((string) $p->post_content)];

        if (count($arr) > 1) {
            $u = wp_update_post(wp_slash($arr), true);

            if (is_wp_error($u)) {
                return SDH_Sales_Rest::hiba($u->get_error_message(), 400);
            }
        }

        if ($r->has_param('kategoriak')) {
            wp_set_post_categories($id, array_values(array_filter(array_map('intval', (array) $r->get_param('kategoriak')), static fn ($x) => $x > 0 && term_exists($x, 'category'))));
        }

        if ($r->has_param('cimkek')) {
            wp_set_post_tags($id, array_slice(array_filter(array_map(static fn ($x) => mb_substr(sanitize_text_field(trim((string) $x)), 0, 60), explode(',', (string) $r->get_param('cimkek')))), 0, 30), false);
        }

        if ($r->has_param('kep')) {
            $u = (string) $r->get_param('kep');

            if (trim($u) === '') {
                delete_post_thumbnail($id);
            } else {
                $att = SDH_Sales_Studio::kep_id(SDH_Sales_Szekcio::url($u));

                if ($att <= 0) {
                    return SDH_Sales_Rest::hiba('A kiemelt kép csak a médiatárból választható.', 422, 'ervenytelen');
                }

                set_post_thumbnail($id, $att);
            }
        }

        $seo = $r->get_param('seo');

        if (is_array($seo)) {
            SDH_Sales_Exportimport::seo_ir($id, [
                'title'       => mb_substr(sanitize_text_field((string) ($seo['title'] ?? '')), 0, 200),
                'description' => mb_substr(sanitize_textarea_field((string) ($seo['description'] ?? '')), 0, 400),
                'kulcsszo'    => mb_substr(sanitize_text_field((string) ($seo['kulcsszo'] ?? '')), 0, 120),
            ]);

            if (!empty($seo['noindex'])) {
                update_post_meta($id, '_sdh_noindex', '1');
            } else {
                delete_post_meta($id, '_sdh_noindex');
            }
        }

        update_post_meta($id, '_sdh_szerkesztve', 1);
        $p2 = get_post($id);
        SDH_Sales_Naplo::ir('blog', 'Cikk mentve: ' . $p2->post_title, 'info', 'cikk', $id, $elotte, ['cim' => $p2->post_title, 'statusz' => $p2->post_status, 'tartalom_hossz' => strlen((string) $p2->post_content)]);

        $q = new WP_REST_Request('GET', '/admin/blog/' . $id);
        $q->set_url_params(['id' => $id]);

        return self::r_blog($q);
    }

    public static function r_blog_kuka(WP_REST_Request $r)
    {
        $p = self::cikk((int) $r['id']);

        if ($p === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen cikk.', 404, 'nincs');
        }

        wp_trash_post((int) $p->ID);
        SDH_Sales_Naplo::ir('blog', 'Cikk a kukába: ' . $p->post_title, 'figyel', 'cikk', (int) $p->ID);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /* =================================================================
     * Médiatár
     * ============================================================== */

    /** @return array<string, mixed> */
    private static function media_sor(WP_Post $a): array
    {
        $meta = wp_get_attachment_metadata((int) $a->ID);
        $mime = (string) $a->post_mime_type;
        $fajl = get_attached_file((int) $a->ID);

        return [
            'id'      => (int) $a->ID,
            'url'     => (string) wp_get_attachment_url((int) $a->ID),
            'kiskep'  => strpos($mime, 'image/') === 0 ? (string) wp_get_attachment_image_url((int) $a->ID, 'medium') : '',
            'cim'     => (string) $a->post_title,
            'alt'     => (string) get_post_meta((int) $a->ID, '_wp_attachment_image_alt', true),
            'felirat' => (string) $a->post_excerpt,
            'mime'    => $mime,
            'meret'   => is_string($fajl) && is_file($fajl) ? (int) filesize($fajl) : 0,
            'w'       => (int) ($meta['width'] ?? 0),
            'h'       => (int) ($meta['height'] ?? 0),
            'datum'   => (int) (strtotime((string) $a->post_date_gmt . ' UTC') ?: 0),
        ];
    }

    public static function r_media(WP_REST_Request $r): WP_REST_Response
    {
        $t = sanitize_key((string) $r->get_param('tipus'));
        $q = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => max(1, min(120, (int) ($r->get_param('db') ?: 48))),
            'paged'          => max(1, (int) ($r->get_param('oldal') ?: 1)),
            'orderby'        => 'date',
            'order'          => 'DESC',
            's'              => mb_substr(sanitize_text_field((string) $r->get_param('q')), 0, 100),
        ];

        if ($t === 'kep') {
            $q['post_mime_type'] = 'image';
        } elseif ($t === 'video') {
            $q['post_mime_type'] = 'video';
        } elseif ($t === 'dokumentum') {
            $q['post_mime_type'] = ['application/pdf', 'text/plain', 'application/zip'];
        }

        $w = new WP_Query($q);

        return new WP_REST_Response(['lista' => array_map([self::class, 'media_sor'], $w->posts), 'ossz' => (int) $w->found_posts, 'oldal' => (int) $q['paged'], 'db' => (int) $q['posts_per_page']], 200);
    }

    public static function r_media_ment(WP_REST_Request $r)
    {
        $a = get_post((int) $r['id']);

        if (!$a instanceof WP_Post || $a->post_type !== 'attachment') {
            return SDH_Sales_Rest::hiba('Nincs ilyen médiaelem.', 404, 'nincs');
        }

        $arr = ['ID' => (int) $a->ID];

        if ($r->has_param('cim')) {
            $arr['post_title'] = mb_substr(sanitize_text_field((string) $r->get_param('cim')), 0, 200);
        }

        if ($r->has_param('felirat')) {
            $arr['post_excerpt'] = mb_substr(sanitize_textarea_field((string) $r->get_param('felirat')), 0, 600);
        }

        if (count($arr) > 1) {
            wp_update_post(wp_slash($arr));
        }

        if ($r->has_param('alt')) {
            update_post_meta((int) $a->ID, '_wp_attachment_image_alt', mb_substr(sanitize_text_field((string) $r->get_param('alt')), 0, 300));
        }

        SDH_Sales_Naplo::ir('media', 'Médiaelem adatai módosítva: ' . $a->post_title, 'info', 'media', (int) $a->ID);

        return new WP_REST_Response(self::media_sor(get_post((int) $a->ID)), 200);
    }

    /* =================================================================
     * Kuka
     * ============================================================== */

    public static function r_kuka(): WP_REST_Response
    {
        global $wpdb;

        $kukas = static fn (string $tipus): array => array_map(static fn (WP_Post $p) => [
            'id'    => (int) $p->ID,
            'cim'   => (string) $p->post_title,
            'ut'    => (string) $p->post_name,
            'ido'   => (int) get_post_meta($p->ID, '_wp_trash_meta_time', true),
        ], get_posts(['post_type' => $tipus, 'post_status' => 'trash', 'posts_per_page' => 200, 'orderby' => 'modified', 'order' => 'DESC']));

        $elemek = [];
        $sorok  = (array) $wpdb->get_results("SELECT id, ido, ki, elotte FROM " . SDH_Sales_Schema::t('naplo') . " WHERE tipus = 'tartalom' AND utana IS NULL AND elotte IS NOT NULL ORDER BY id DESC LIMIT 200", ARRAY_A);
        $latott = [];

        foreach ($sorok as $s) {
            $e = json_decode((string) $s['elotte'], true);

            if (!is_array($e) || empty($e['tipus']) || empty($e['kulcs'])) {
                continue;
            }

            $k = $e['tipus'] . '/' . $e['kulcs'] . '/' . ($e['nyelv'] ?? 'hu');

            // Csak a legutóbbi törlés, és csak ha az elem azóta sem jött vissza.
            if (isset($latott[$k]) || SDH_Sales_Tartalom::egy((string) $e['tipus'], (string) $e['kulcs'], (string) ($e['nyelv'] ?? 'hu')) !== null) {
                $latott[$k] = true;
                continue;
            }

            $latott[$k] = true;
            $elemek[]   = [
                'naplo' => (int) $s['id'],
                'tipus' => (string) $e['tipus'],
                'kulcs' => (string) $e['kulcs'],
                'cim'   => (string) ($e['cim'] ?? $e['kulcs']),
                'ido'   => SDH_Sales_Admin::ts((string) $s['ido']),
                'ki'    => (string) $s['ki'],
            ];
        }

        return new WP_REST_Response(['oldalak' => $kukas('page'), 'cikkek' => $kukas('post'), 'elemek' => $elemek], 200);
    }

    public static function r_vegleges(WP_REST_Request $r)
    {
        $p = get_post((int) $r['id']);

        if (!$p instanceof WP_Post || !in_array($p->post_type, ['page', 'post'], true) || $p->post_status !== 'trash') {
            return SDH_Sales_Rest::hiba('Csak a kukában lévő oldal vagy cikk törölhető véglegesen.', 404, 'nincs');
        }

        $cim = (string) $p->post_title;
        $sz  = $p->post_type === 'page' ? SDH_Sales_Tartalom::szekciok((int) $p->ID) : [];

        wp_delete_post((int) $p->ID, true);
        SDH_Sales_Naplo::ir($p->post_type === 'page' ? 'oldal' : 'blog', 'Véglegesen törölve: ' . $cim, 'figyel', '', 0, ['cim' => $cim, 'szekciok' => $sz], null);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /* =================================================================
     * E-mail sablonok: előnézet, próbalevél
     * ============================================================== */

    /** @return array<string, string> mintaértékek a tokenekhez */
    private static function minta(string $kulcs): array
    {
        $a = [
            'nev' => 'Minta Péter', 'kapcsolattarto' => 'Minta Péter', 'ceg_nev_vevo' => 'Minta Szerviz Kft.', 'vevo_ceg' => 'Minta Szerviz Kft.',
            'rendeles' => 'R-2026-000123', 'azon' => 'R-2026-000123', 'osszeg' => '119 380 Ft', 'brutto' => '119 380 Ft', 'netto' => '94 000 Ft',
            'licenc_kulcs' => 'MINTA-1234-ABCD-5678', 'letoltes_url' => home_url('/fiok/'), 'csomag' => 'Pro', 'lejarat' => wp_date('Y. m. d.', time() + YEAR_IN_SECONDS),
            'idopont' => wp_date('Y. m. d. H:i', time() + 3 * DAY_IN_SECONDS), 'hatarido' => wp_date('Y. m. d.', time() + 8 * DAY_IN_SECONDS),
            'kozlemeny' => 'R-2026-000123', 'link' => home_url('/'), 'url' => home_url('/'), 'kod' => '482913',
        ];

        foreach ((array) (SDH_Sales_Email::sablonok()[$kulcs]['tokenek'] ?? []) as $t) {
            $t = trim((string) $t, '{}');

            if ($t !== '' && !isset($a[$t]) && preg_match('/^[a-z0-9_]{1,40}$/', $t) === 1) {
                $a[$t] = 'Minta ' . str_replace('_', ' ', $t);
            }
        }

        return $a;
    }

    /** @return array{0: array<string, mixed>|null, 1: string} sablon (a még nem mentett adatokkal), kulcs */
    private static function email_sablon(WP_REST_Request $r): array
    {
        $kulcs = sanitize_key((string) $r->get_param('kulcs'));
        $adat  = $r->get_param('adat');

        if (is_array($adat)) {
            $adat = SDH_Sales_Szekcio::elem_tisztit('email', $adat);

            return [!empty($adat['html']) ? $adat : null, $kulcs];
        }

        return [null, $kulcs];
    }

    public static function r_email_elonezet(WP_REST_Request $r)
    {
        [$sablon, $kulcs] = self::email_sablon($r);

        if ($kulcs === '' && $sablon === null) {
            return SDH_Sales_Rest::hiba('Melyik sablon?', 400);
        }

        SDH_Sales_Email::$elonezet = $sablon;

        try {
            $l = SDH_Sales_Email::osszeallit($kulcs !== '' ? $kulcs : 'elonezet', self::minta($kulcs));
        } finally {
            SDH_Sales_Email::$elonezet = null;
        }

        if ($l === null) {
            return SDH_Sales_Rest::hiba('A sablon üres vagy ki van kapcsolva.', 404, 'nincs');
        }

        return new WP_REST_Response(['targy' => $l['targy'], 'html' => $l['html'], 'szoveg' => $l['szoveg']], 200);
    }

    public static function r_email_proba(WP_REST_Request $r)
    {
        [$sablon, $kulcs] = self::email_sablon($r);
        $cimzett = (string) wp_get_current_user()->user_email;

        if (!is_email($cimzett)) {
            return SDH_Sales_Rest::hiba('A fiókodhoz nincs érvényes e-mail-cím.', 409, 'nincs_cim');
        }

        SDH_Sales_Email::$elonezet = $sablon;

        try {
            $ok = SDH_Sales_Email::kuld($kulcs !== '' ? $kulcs : 'elonezet', $cimzett, self::minta($kulcs));
        } finally {
            SDH_Sales_Email::$elonezet = null;
        }

        return $ok ? new WP_REST_Response(['ok' => true, 'cimzett' => SDH_Sales_Email::maszk($cimzett)], 200) : SDH_Sales_Rest::hiba('A próbalevél nem ment ki (nézd meg a levelezés beállításait).', 502, 'kuldes');
    }

    /* =================================================================
     * Űrlapok: mezők feliratának, kötelezőségének és láthatóságának felülírása
     * ============================================================== */

    /** @return array<string, array<string, array{cimke?: string, kotelezo?: bool, latszik?: bool}>> */
    private static function urlap_beallitas(): array
    {
        $o = get_option(self::OPT_URLAP, []);

        return is_array($o) ? $o : [];
    }

    /**
     * @param array<int, array<string, mixed>> $mezok
     * @return array<int, array<string, mixed>>
     */
    public static function urlap_felulir($mezok, $tipus): array
    {
        $f  = self::urlap_beallitas()[(string) $tipus] ?? [];
        $ki = [];

        foreach ((array) $mezok as $m) {
            if (!is_array($m) || !isset($m['nev'])) {
                continue;
            }

            $x = $f[(string) $m['nev']] ?? null;

            if (is_array($x) && !in_array($m['nev'], self::URLAP_ZAROLT, true)) {
                if (isset($x['latszik']) && $x['latszik'] === false) {
                    continue;
                }

                if (isset($x['kotelezo'])) {
                    $m['kotelezo'] = (bool) $x['kotelezo'];
                }
            }

            if (is_array($x) && !empty($x['cimke'])) {
                $m['cimke'] = (string) $x['cimke'];
            }

            $ki[] = $m;
        }

        return $ki;
    }

    public static function r_urlapok(): WP_REST_Response
    {
        $f     = self::urlap_beallitas();
        $nevek = ['kapcsolat' => 'Kapcsolat', 'ajanlat' => 'Ajánlatkérés', 'partner' => 'Partnerprogram'];
        $ki    = [];

        foreach (SDH_Sales_Urlap::TIPUSOK as $t) {
            $ki[$t] = ['cim' => $nevek[$t] ?? $t, 'mezok' => array_map(static function ($m) use ($f, $t) {
                $x = (array) ($f[$t][(string) $m['nev']] ?? []);
                $z = in_array($m['nev'], self::URLAP_ZAROLT, true);

                return [
                    'nev'           => (string) $m['nev'],
                    'tipus'         => (string) $m['tipus'],
                    'cimke_alap'    => (string) $m['cimke'],
                    'cimke'         => (string) ($x['cimke'] ?? ''),
                    'kotelezo_alap' => (bool) $m['kotelezo'],
                    'kotelezo'      => $z ? true : (bool) ($x['kotelezo'] ?? $m['kotelezo']),
                    'latszik'       => $z ? true : (bool) ($x['latszik'] ?? true),
                    'zarolt'        => $z,
                ];
            }, SDH_Sales_Urlap::mezok_alap($t))];
        }

        return new WP_REST_Response(['tipusok' => $ki], 200);
    }

    public static function r_urlapok_ment(WP_REST_Request $r)
    {
        $be = $r->get_param('tipusok');

        if (!is_array($be)) {
            return SDH_Sales_Rest::hiba('Hibás adat.', 400);
        }

        $regi = self::urlap_beallitas();
        $uj   = [];

        foreach (SDH_Sales_Urlap::TIPUSOK as $t) {
            $alap = [];

            foreach (SDH_Sales_Urlap::mezok_alap($t) as $m) {
                $alap[(string) $m['nev']] = $m;
            }

            foreach ((array) ($be[$t] ?? []) as $nev => $x) {
                if (!isset($alap[$nev]) || !is_array($x)) {
                    continue;
                }

                $z = in_array($nev, self::URLAP_ZAROLT, true);
                $e = [];
                $c = mb_substr(sanitize_text_field((string) ($x['cimke'] ?? '')), 0, 120);

                if ($c !== '' && $c !== $alap[$nev]['cimke']) {
                    $e['cimke'] = $c;
                }

                if (!$z && array_key_exists('kotelezo', $x) && (bool) $x['kotelezo'] !== (bool) $alap[$nev]['kotelezo']) {
                    $e['kotelezo'] = (bool) $x['kotelezo'];
                }

                if (!$z && array_key_exists('latszik', $x) && !$x['latszik']) {
                    $e['latszik'] = false;
                }

                if ($e !== []) {
                    $uj[$t][(string) $nev] = $e;
                }
            }
        }

        if ($uj !== $regi) {
            update_option(self::OPT_URLAP, $uj, false);
            SDH_Sales_Naplo::ir('urlap', 'Űrlapmezők módosítva', 'info', 'urlapok', 0, $regi, $uj);
        }

        return self::r_urlapok();
    }

    /** @param mixed $elotte */
    public static function urlap_visszaallit(bool $ok, int $id, $elotte): bool
    {
        if (!is_array($elotte)) {
            return false;
        }

        update_option(self::OPT_URLAP, $elotte, false);

        return true;
    }
}
