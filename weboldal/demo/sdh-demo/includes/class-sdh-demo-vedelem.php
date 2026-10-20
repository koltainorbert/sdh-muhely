<?php
/**
 * Tiltott műveletek a látogatónak (szerepe: bemutato).
 *
 * Három szinten:
 * 1. Engedélylista: a látogató AJAX-ban csak a műhely saját műveleteit hívhatja (sdh_muhely_*), admin-post-ban csak a
 *    rekord-mentéseket; a WordPress-admin oldalai helyett a műhely felületére kerül; a REST-felhasználóvégpontok tiltva.
 * 2. Tiltólista a műhely műveletei között: frissítés, licenc, távoli karbantartás / támogatás, mentés (külső célok,
 *    visszaállítás, export / import), csapattagok kezelése, kétlépcsős belépés, adatátvétel, anonimizálás, pénztár-import,
 *    exportok, fejlesztői részek (mérés, admin-átállás, modulok, demó-betöltő), feltöltés, AI-kulcs, SMS-párosítás.
 *    A Beállítások közül csak a nem érzékeny lapfülek nyílnak meg; mentés csak, ha az admin megengedi (alap: csak nézni).
 * 3. Felület: a tiltott lapfülek és a „Kollégák” nézet linkje kimarad (a menüben csak az áll, ami meg is nyitható),
 *    a közvetlen címük visszairányít.
 *
 * @package SDH_Demo
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Demo_Vedelem
{
    /** A Beállítások látogatónak megnyitható lapfülei (minden más – a később jövők is – tiltott). */
    public const ENGEDETT_FULEK = [
        'arculat', 'belepes', 'csempek', 'nyitvatartas', 'ugyfeloldal', 'kereses',
        'szamozas', 'allapotok', 'hiba-allapotok', 'gyakori-hibak', 'gyakori-megjegyzesek', 'garancia', 'ajanlat', 'zarolas', 'urlapok', 'urlap_email',
        'fizmodok', 'szamlazas', 'matrica', 'nyomtatvanyok', 'penztar', 'eladas',
        'sms', 'csapat', 'naptar', 'foglalas', 'elegedettseg',
        'imei', 'mockupok',
    ];

    /** Mindig tiltott műveletek (az `sdh_muhely_` előtag után, pontosan). */
    public const TILTOTT = [
        'frissites_ellenorzes', 'frissites_indit', 'frissites_forras',
        'licenc_ellenoriz', 'tamogatas_indit', 'tamogatas_visszavon', 'tavoli_valasz', 'tavoli_uzenet_le',
        'csapatkollega_urlap', 'csapatkollega_ment',
        'anonim_urlap', 'anonim_vegrehajt',
        'penztar_export', 'raktar_csv', 'naplo_csv', 'tac_import',
        'admin_atallit', 'meres', 'beallitasok_feltolt',
        'ai_beallit', 'ai_teszt', 'ai_mentes', 'ai_mentes_le', 'ai_visszaallit',
        'sms_parosit', 'sms_webhook_titok', 'level_teszt',
        'demo_betoltes', 'termek_demo', 'modulok', 'modulok_zar',
    ];

    /** Mindig tiltott művelet-családok (előtag). */
    public const TILTOTT_ELEJE = ['frissites_', 'mentes_', 'ketlepcsos_', 'atvetel_', 'penztar_import_', 'licenc_', 'tavoli_', 'tamogatas_'];

    /** Közös (mindenkire ható) beállítást író műveletek: csak, ha az admin megengedi. */
    public const BEALLITAS_IRAS = [
        'urlap_beall_ment', 'nyomtsablon_ment', 'nyomtsablon_alap', 'sms_sablon_ment', 'sms_szabaly_ment', 'sugogyik_ment', 'aitudas_ment',
    ];

    /** admin-post.php: a látogató ezeket küldheti be (rekordok mentése). */
    public const ENGEDETT_POST = [
        'ugyfel_mentes', 'eszkoz_mentes', 'munkalap_mentes', 'termek_mentes', 'szolgaltatas_mentes', 'szolgaltatas_arak', 'ugyfel_allapot',
    ];

    public const TILTOTT_UZENET = 'Ez a funkció a bemutatóban nem érhető el (frissítés, licenc, mentés, jogosultságok, adatátvétel és a rendszerszintű beállítások). A saját rendszeredben természetesen működik.';
    public const NEZET_UZENET   = 'A bemutatóban a beállítások csak megtekinthetők – a mentés a saját rendszeredben működik.';

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'or'], -100);
        add_action('template_redirect', [self::class, 'felulet'], 0);
        add_filter('rest_pre_dispatch', [self::class, 'rest'], 0, 3);
        add_filter('show_admin_bar', [self::class, 'admin_sav'], 99);
    }

    /* =================================================================
     * Döntés (tesztelhető mag)
     * ============================================================== */

    /**
     * Mehet-e a művelet a látogatónak. null = mehet; különben [HTTP-kód, üzenet].
     *
     * @param array<string, mixed> $post
     * @return array{0: int, 1: string}|null
     */
    public static function dontes(string $akcio, bool $ajax, array $post = []): ?array
    {
        $akcio = sanitize_key($akcio);
        $tilt  = [403, self::TILTOTT_UZENET];

        if ($ajax && $akcio === 'heartbeat') {
            return null;
        }

        if (strpos($akcio, 'sdh_muhely_') !== 0) {
            return $tilt;
        }

        $m = substr($akcio, strlen('sdh_muhely_'));

        if (!$ajax) {
            if ($m === 'beallitasok') {
                return self::beallitas_dontes((string) ($post['ful'] ?? ''));
            }

            return in_array($m, self::ENGEDETT_POST, true) ? null : $tilt;
        }

        if (in_array($m, self::TILTOTT, true)) {
            return $tilt;
        }

        foreach (self::TILTOTT_ELEJE as $eleje) {
            if (strpos($m, $eleje) === 0) {
                return $tilt;
            }
        }

        if ($m === 'beallitasok_ment') {
            return self::beallitas_dontes((string) ($post['ful'] ?? ''));
        }

        if (in_array($m, self::BEALLITAS_IRAS, true) && empty(SDH_Demo::b('beallitas_mentes'))) {
            return [403, self::NEZET_UZENET];
        }

        return null;
    }

    /** @return array{0: int, 1: string}|null */
    public static function beallitas_dontes(string $ful): ?array
    {
        if (!self::ful_engedett($ful)) {
            return [403, self::TILTOTT_UZENET];
        }

        return empty(SDH_Demo::b('beallitas_mentes')) ? [403, self::NEZET_UZENET] : null;
    }

    public static function ful_engedett(string $ful): bool
    {
        return in_array(sanitize_title($ful), self::ENGEDETT_FULEK, true);
    }

    /* =================================================================
     * Őr: admin-ajax, admin-post, wp-admin
     * ============================================================== */

    public static function or(): void
    {
        if (!SDH_Demo::demo_felhasznalo()) {
            return;
        }

        $ajax = wp_doing_ajax();
        $post = ($GLOBALS['pagenow'] ?? '') === 'admin-post.php';

        if (!$ajax && !$post) {
            // A WordPress-admin oldalai (profil, vezérlőpult…) helyett a műhely.
            if (defined('BEMUTATO_TESZT') && BEMUTATO_TESZT) {
                throw new SDH_Demo_Megallitva('atiranyitas', 302);
            }

            wp_safe_redirect(SDH_Muhely_Modulok::frontend_url(), 302);
            exit;
        }

        // phpcs:disable WordPress.Security.NonceVerification -- csak döntés; a műhely végpontja ellenőrzi a nonce-ot.
        $akcio = isset($_REQUEST['action']) ? (string) wp_unslash($_REQUEST['action']) : '';
        $be    = wp_unslash($_POST);
        // phpcs:enable

        $d = self::dontes($akcio, $ajax, is_array($be) ? $be : []);

        if ($d !== null) {
            SDH_Demo::megallit($d[0], $d[1]);
        }
    }

    /* =================================================================
     * Felület: tiltott lapfülek, Kollégák nézet
     * ============================================================== */

    public static function felulet(): void
    {
        if (!SDH_Demo::demo_felhasznalo() || !class_exists('SDH_Muhely_Frontend')) {
            return;
        }

        $kulcs = sanitize_key((string) get_query_var(SDH_Muhely_Frontend::QUERY_VAR));

        if ($kulcs === '') {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $ful   = isset($_GET['ful']) ? sanitize_title(wp_unslash((string) $_GET['ful'])) : '';
        $nezet = isset($_GET['nezet']) ? sanitize_key(wp_unslash((string) $_GET['nezet'])) : '';
        // phpcs:enable

        $cel = self::visszairanyitas($kulcs, $ful, $nezet);

        if ($cel !== '') {
            wp_safe_redirect($cel, 302);
            exit;
        }

        ob_start([self::class, 'html_szuro']);
    }

    /** Ha a kért műhely-oldal a látogatónak tiltott: hová menjen ('' = maradhat). */
    public static function visszairanyitas(string $kulcs, string $ful, string $nezet): string
    {
        if ($kulcs === 'beallitasok' && $ful !== '' && !self::ful_engedett($ful)) {
            return SDH_Muhely_Modulok::frontend_url('beallitasok');
        }

        if ($kulcs === 'csapat' && $nezet === 'kollegak') {
            return SDH_Muhely_Modulok::frontend_url('csapat');
        }

        if ($kulcs === 'modulok') {
            return SDH_Muhely_Modulok::frontend_url();
        }

        return '';
    }

    /** A kimenetből kimarad, amit a látogató úgysem nyithat meg: tiltott lapfülek, üres csoportjuk, a Kollégák fül. */
    public static function html_szuro(string $html): string
    {
        if ($html === '' || strpos($html, 'ful=') === false && strpos($html, 'nezet=kollegak') === false) {
            return $html;
        }

        // A Beállítások lapfülei: <a class="… pill …" href="…ful=X…">…</a>
        $html = (string) preg_replace_callback(
            '#<a\b[^>]*\bhref="([^"]*[?&](?:amp;|\#038;)?ful=([a-z0-9_\-]+)[^"]*)"[^>]*>.*?</a>#s',
            static fn (array $t): string => self::ful_engedett($t[2]) ? $t[0] : '',
            $html
        );

        // Kiürült csoportsor (felirat + üres pill-sor).
        $html = (string) preg_replace('#<div class="[a-z_\-]*beall__sor">\s*<span[^>]*>[^<]*</span>\s*<div class="[a-z_\-]*beall__pillek">\s*</div>\s*</div>#', '', $html);

        // A Csapat oldal „Kollégák” füle.
        return (string) preg_replace('#<a\b[^>]*\bhref="[^"]*[?&](?:amp;|\#038;)?nezet=kollegak[^"]*"[^>]*>.*?</a>#s', '', $html);
    }

    /* =================================================================
     * REST, admin-sáv
     * ============================================================== */

    /**
     * @param mixed $eredmeny
     * @return mixed
     */
    public static function rest($eredmeny, $szerver, $keres)
    {
        if ($eredmeny !== null || !SDH_Demo::demo_felhasznalo() || !$keres instanceof WP_REST_Request) {
            return $eredmeny;
        }

        if (strpos((string) $keres->get_route(), '/wp/v2/') === 0) {
            return new WP_Error('bemutato_tiltva', 'A bemutatóban nem elérhető.', ['status' => 403]);
        }

        return $eredmeny;
    }

    /** @param bool $mutat */
    public static function admin_sav($mutat): bool
    {
        return SDH_Demo::demo_felhasznalo() ? false : (bool) $mutat;
    }
}
