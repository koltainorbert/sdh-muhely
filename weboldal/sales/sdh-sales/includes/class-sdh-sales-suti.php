<?php
/**
 * SDH Értékesítés – süti-hozzájárulás (banner + beállító ablak), Google Consent Mode v2, WP Consent API, naplózás,
 * a jogi oldalak tokenjei.
 *
 * Működés (gyorsítótár-biztos: minden kimenet minden látogatónak ugyanaz, a döntést a böngésző olvassa a sütiből):
 * - `wp_head` legelején (PHP_INT_MIN) inline szkript: `dataLayer`, `gtag()`, Consent Mode v2 alapállapot (minden `denied`,
 *   csak `security_storage` `granted`, `wait_for_update` 500), `ads_data_redaction`; ha már van érvényes döntés a sütiben
 *   (verzió + élettartam rendben), azonnal `consent update`. `window.wp_consent_type = 'optin'` (a kimenetben összerakva).
 * - Rejtés (REJTES.md): a kimenetben nincs `sdh` / platformnév – süti `suti`/`suti_id`, attribútum `data-suti`, JS `window.APP_SUTI`.
 * - Nem szükséges szkript csak `SDH_Sales_Suti::szkript($kategoria, $src_vagy_kod)`-dal kerülhet az oldalra:
 *   `<script type="text/plain" data-suti="<kat>" [data-src="…"]>` – az `assets/front/suti.js` hozzájárulás után élesíti
 *   (dokumentum-sorrendben). A GA4 / GTM betöltő is így megy ki (csak hozzájárulás után tölt be). A Site Kit gtag-je
 *   (`google_gtagjs` handle) ugyanígy blokkolva (szűrő: `sdh_sales_suti_blokk` → [handle => kategória]).
 * - Kategóriák: `szukseges` (mindig), `statisztika`, `marketing`, `preferencia`. Global Privacy Control: a marketing alapból ki.
 * - Süti: `suti` = „1|<verzió>|<s><m><p>|<unix idő>” (URL-kódolva), `suti_id` = véletlen azonosító; élettartam
 *   `suti.honap` (6). Ha az admin a `suti.verzio`-t átírja, mindenkitől újra kérdez.
 * - Napló: `POST /suti` (nyilvános, korlátos) → `suti` tábla; admin CSV: `GET /admin/suti/export?tol=ÉÉÉÉ-HH-NN&ig=…`.
 * - WP Consent API: regisztrálva (`wp_consent_api_registered_<bővítmény>`), a JS `wp_set_consent()`-tel jelez, így a Site Kit
 *   és más, a Consent API-t figyelő bővítmény követi.
 * - CSP: ha valaki alkalmazza az `sdh_sales_csp` szűrőt (formátum: [irányelv => [forrás, …]]), a Google-tartományokat
 *   hozzáadjuk; a szkriptek ettől még a hozzájárulásig blokkolva maradnak (text/plain).
 * - Beállítások: az admin `sdh_sales_admin_beallitas_csoportok` szűrőjén át (`suti` csoport); ha nincs admin, az itteni
 *   alapértékek érvényesek (`alapok()`).
 * - Jogi oldalak: `{tarhely_nev}`, `{tarhely_cim}`, `{tarhely_elerhetoseg}`, `{levelezo_nev}` token (`jogi.*` beállítások),
 *   és a `jogi` szekciós oldalak tartalmában a tokenek cseréje (`the_content`).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Suti
{
    public const SUTI     = 'suti';
    public const SUTI_ID  = 'suti_id';
    public const KATEGORIAK = ['szukseges', 'statisztika', 'marketing', 'preferencia'];

    private static bool $kiirva = false;

    public static function init(): void
    {
        add_action('wp_head', [self::class, 'alap_szkript'], PHP_INT_MIN);
        add_action('wp_head', [self::class, 'google_betolto'], PHP_INT_MIN + 1);
        add_action('wp', [self::class, 'igeny']);
        add_action('init', [self::class, 'modul_regisztral'], 6);
        add_action('wp_body_open', [self::class, 'panel'], 5);
        add_action('wp_footer', [self::class, 'panel'], 1);

        add_filter('script_loader_tag', [self::class, 'blokk_tag'], 99, 3);
        add_filter('wp_inline_script_attributes', [self::class, 'blokk_inline'], 99, 2);

        add_filter('wp_consent_api_registered_' . plugin_basename(SDH_SALES_FILE), '__return_true');
        add_filter('wp_get_consent_type', [self::class, 'consent_tipus']);

        add_filter('sdh_sales_admin_beallitas_csoportok', [self::class, 'admin_csoport']);
        add_filter('sdh_sales_csp', [self::class, 'csp']);
        add_filter('sdh_sales_tokenek', [self::class, 'tokenek']);
        add_filter('the_content', [self::class, 'jogi_tokenek'], 9);
        add_filter('rest_pre_serve_request', [self::class, 'csv_kiszolgal'], 10, 4);
        add_action(SDH_Sales::CRON, [self::class, 'takarit']);

        SDH_Sales_Rest::ut('/suti', 'POST', [self::class, 'rest_naplo'], 'nyilvanos', ['suti', 20, 600]);
        SDH_Sales_Rest::ut('/admin/suti/export', 'GET', [self::class, 'rest_export'], 'admin');
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    /** @return array<string, mixed> az alapértékek (`suti.` / `jogi.` előtag nélkül a kulcs után) */
    public static function alapok(): array
    {
        return [
            'suti.be'                    => true,
            'suti.verzio'                => '1.0',
            'suti.honap'                 => 6,
            'suti.ga4'                   => '',
            'suti.gtm'                   => '',
            'suti.gtm_kategoria'         => 'statisztika',
            'suti.sitekit_blokk'         => true,
            'suti.naplo_ev'              => 5,
            'suti.szoveg.cim'            => __('Sütik ezen az oldalon', 'sdh-sales'),
            'suti.szoveg.leiras'         => __('A webhely működéséhez szükséges sütiket mindig használjuk. Statisztikai, marketing- és kényelmi sütit csak akkor, ha hozzájárulsz. A döntésedet bármikor megváltoztathatod a lábléc „Süti-beállítások” linkjével.', 'sdh-sales'),
            'suti.szoveg.elfogad'        => __('Elfogadom', 'sdh-sales'),
            'suti.szoveg.elutasit'       => __('Elutasítom', 'sdh-sales'),
            'suti.szoveg.beallit'        => __('Beállítások', 'sdh-sales'),
            'suti.szoveg.ment'           => __('Kiválasztottak mentése', 'sdh-sales'),
            'suti.szoveg.modal_cim'      => __('Süti-beállítások', 'sdh-sales'),
            'suti.szoveg.modal_leiras'   => __('Kategóriánként döntheted el, mit engedsz. A szükséges sütik nélkül az oldal nem működne, ezért azok mindig be vannak kapcsolva.', 'sdh-sales'),
            'suti.szoveg.tajekoztato'    => __('Süti-tájékoztató', 'sdh-sales'),
            'suti.szoveg.gpc'            => __('A böngésződ „Global Privacy Control” jelzést küld, ezért a marketing-sütik alapból ki vannak kapcsolva.', 'sdh-sales'),
            'suti.szoveg.szukseges'      => __('Az oldal alapműködéséhez kell: biztonság, a kosár, a belépés és ennek a döntésnek a megjegyzése.', 'sdh-sales'),
            'suti.szoveg.statisztika'    => __('Névtelen látogatottsági mérés, hogy lássuk, mely oldalak hasznosak és hol akad el a látogató.', 'sdh-sales'),
            'suti.szoveg.marketing'      => __('Hirdetések mérése és személyre szabása (csak ha a webhely ilyet használ).', 'sdh-sales'),
            'suti.szoveg.preferencia'    => __('Kényelmi beállítások megjegyzése, például a megjelenés és a nyelv.', 'sdh-sales'),
            'jogi.tarhely_nev'           => '[tárhelyszolgáltató neve]',
            'jogi.tarhely_cim'           => '[tárhelyszolgáltató székhelye]',
            'jogi.tarhely_elerhetoseg'   => '[tárhelyszolgáltató e-mail-címe, telefonszáma]',
            'jogi.levelezo_nev'          => '[levelezési (SMTP) szolgáltató neve, székhelye]',
        ];
    }

    /** @return mixed */
    public static function b(string $kulcs)
    {
        $a = self::alapok();
        $v = SDH_Sales::b($kulcs, $a[$kulcs] ?? null);

        // Üresre törölt szövegnél az alapszöveg áll (a gomb soha nem maradhat felirat nélkül).
        if (($v === null || $v === '' || $v === []) && strncmp($kulcs, 'suti.szoveg.', 12) === 0) {
            return $a[$kulcs] ?? '';
        }

        return $v;
    }

    public static function be(): bool
    {
        return (bool) self::b('suti.be');
    }

    public static function verzio(): string
    {
        $v = trim((string) self::b('suti.verzio'));

        return preg_match('/^[0-9A-Za-z._-]{1,20}$/', $v) === 1 ? $v : '1.0';
    }

    public static function honap(): int
    {
        return max(1, min(13, (int) self::b('suti.honap')));
    }

    public static function ga4(): string
    {
        $v = strtoupper(trim((string) self::b('suti.ga4')));

        return preg_match('/^G-[A-Z0-9]{4,20}$/', $v) === 1 ? $v : '';
    }

    public static function gtm(): string
    {
        $v = strtoupper(trim((string) self::b('suti.gtm')));

        return preg_match('/^GTM-[A-Z0-9]{4,12}$/', $v) === 1 ? $v : '';
    }

    public static function kategoria(string $k): string
    {
        return in_array($k, self::KATEGORIAK, true) ? $k : 'marketing';
    }

    /* =================================================================
     * <head>: Consent Mode v2 alapállapot (mindennél előbb)
     * ============================================================== */

    /** Az inline alapszkript szövege (a teszt Node-ban is lefuttatja). */
    public static function alap_js(): string
    {
        $v = (string) wp_json_encode(self::verzio());
        $h = self::honap();

        return 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
            . "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'denied',personalization_storage:'denied',security_storage:'granted',wait_for_update:500});"
            . "gtag('set','ads_data_redaction',true);"
            . "window['wp'+'_consent_type']='optin';"
            . '(function(){try{var m=document.cookie.match(/(?:^|;\s*)' . self::SUTI . '=([^;]*)/);if(!m)return;'
            . "var p=decodeURIComponent(m[1]).split('|');if(p.length<4||p[0]!=='1'||p[1]!==" . $v . ')return;'
            . 'var d=new Date(parseInt(p[3],10)*1000);d.setMonth(d.getMonth()+' . $h . ');if(!(d>new Date()))return;'
            . "var s=p[2]||'',g=function(i){return s.charAt(i)==='1'?'granted':'denied';};"
            . "gtag('consent','update',{analytics_storage:g(0),ad_storage:g(1),ad_user_data:g(1),ad_personalization:g(1),functionality_storage:g(2),personalization_storage:g(2)});"
            . '}catch(e){}})();';
    }

    public static function alap_szkript(): void
    {
        if (!self::be() || is_admin()) {
            return;
        }

        wp_print_inline_script_tag(self::alap_js(), ['id' => 's-suti-alap']);
    }

    /** GA4 / GTM betöltő – blokkolva, csak hozzájárulás után fut. */
    public static function google_betolto(): void
    {
        if (!self::be() || is_admin()) {
            return;
        }

        $ga4 = self::ga4();
        $gtm = self::gtm();

        if ($ga4 !== '') {
            echo self::szkript('statisztika', 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($ga4)) . "\n";
            echo self::szkript('statisztika', "gtag('js',new Date());gtag('config'," . wp_json_encode($ga4) . ');') . "\n";
        }

        if ($gtm !== '') {
            $kat = self::kategoria((string) self::b('suti.gtm_kategoria'));
            echo self::szkript($kat === 'szukseges' ? 'statisztika' : $kat, "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer'," . wp_json_encode($gtm) . ');') . "\n";
        }
    }

    /**
     * Hozzájárulástól függő szkript. URL esetén külső (`data-src`), különben inline kód. A `szukseges` kategória rendes
     * szkriptként megy ki. Ismeretlen kategória: a legszigorúbb (marketing).
     */
    public static function szkript(string $kategoria, string $src_vagy_kod): string
    {
        $kat = self::kategoria($kategoria);
        $s   = trim($src_vagy_kod);
        $url = preg_match('#^(https?:)?//#i', $s) === 1 || (strncmp($s, '/', 1) === 0 && strpos($s, "\n") === false && strpos($s, ' ') === false);

        if ($kat === 'szukseges') {
            return $url
                ? '<script src="' . esc_url($s) . '"></script>'
                : '<script>' . str_ireplace('</script', '<\/script', $s) . '</script>';
        }

        if ($url) {
            return '<script type="text/plain" data-suti="' . esc_attr($kat) . '" data-src="' . esc_url($s) . '"></script>';
        }

        return '<script type="text/plain" data-suti="' . esc_attr($kat) . '">' . str_ireplace('</script', '<\/script', $s) . '</script>';
    }

    /* =================================================================
     * Más bővítmények (pl. Site Kit) szkriptjeinek blokkolása
     * ============================================================== */

    /** @return array<string, string> handle => kategória */
    public static function blokkolt(): array
    {
        $b = (bool) self::b('suti.sitekit_blokk') ? ['google_gtagjs' => 'statisztika'] : [];

        return (array) apply_filters('sdh_sales_suti_blokk', $b);
    }

    public static function blokk_tag(string $tag, string $handle, string $src = ''): string
    {
        if (!self::be() || is_admin()) {
            return $tag;
        }

        $b = self::blokkolt();

        if (!isset($b[$handle])) {
            return $tag;
        }

        $kat = self::kategoria((string) $b[$handle]);

        return (string) preg_replace_callback('/<script\b([^>]*)>/i', static function (array $m) use ($kat): string {
            $attr = (string) preg_replace('/\s(type|data-suti)=("[^"]*"|\'[^\']*\')/i', '', $m[1]);
            $attr = (string) preg_replace('/\ssrc=/i', ' data-src=', $attr);

            return '<script type="text/plain" data-suti="' . esc_attr($kat) . '"' . $attr . '>';
        }, $tag);
    }

    /**
     * @param array<string, mixed> $attr
     * @return array<string, mixed>
     */
    public static function blokk_inline(array $attr, string $kod = ''): array
    {
        if (!self::be() || is_admin() || empty($attr['id'])) {
            return $attr;
        }

        foreach (self::blokkolt() as $handle => $kat) {
            if (preg_match('/^' . preg_quote((string) $handle, '/') . '-js-(before|after|extra)$/', (string) $attr['id']) === 1) {
                $attr['type']          = 'text/plain';
                $attr['data-suti'] = self::kategoria((string) $kat);
            }
        }

        return $attr;
    }

    /* =================================================================
     * Front: modul, panel
     * ============================================================== */

    public static function modul_regisztral(): void
    {
        // Csak létező fájlt sorolunk be (hiányzó CSS ne adjon 404-et).
        $css = is_file(SDH_SALES_DIR . 'assets/front/suti.css') ? 'assets/front/suti.css' : '';

        SDH_Sales_Front::modul('suti', 'assets/front/suti.js', [], [self::class, 'js_adat'], $css);
    }

    /** @return array<string, mixed> */
    public static function js_adat(): array
    {
        return [
            'verzio' => self::verzio(),
            'honap'  => self::honap(),
            'rest'   => esc_url_raw(rest_url(SDH_Sales_Rest::NS)),
            'naplo'  => true,
        ];
    }

    public static function igeny(): void
    {
        if (self::be() && !is_admin() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)) {
            SDH_Sales_Front::igeny('suti');
        }
    }

    public static function tajekoztato_url(): string
    {
        $p = get_page_by_path('jogi/suti-tajekoztato') ?: get_page_by_path('suti-tajekoztato');

        return $p ? (string) get_permalink($p) : home_url('/jogi/suti-tajekoztato/');
    }

    /** A banner és a beállító ablak (statikus HTML, rejtve; a JS dönt a megjelenítésről). */
    public static function panel_html(): string
    {
        $sz = static fn (string $k): string => esc_html(SDH_Sales::t((string) self::b('suti.szoveg.' . $k)));

        $kat = [
            'szukseges'   => __('Szükséges', 'sdh-sales'),
            'statisztika' => __('Statisztika', 'sdh-sales'),
            'marketing'   => __('Marketing', 'sdh-sales'),
            'preferencia' => __('Kényelmi (preferencia)', 'sdh-sales'),
        ];

        $gombok = static fn (bool $modal): string => '<div class="s-suti-gombok">'
            . ($modal ? '<button type="button" class="s-suti-gomb" data-suti-muvelet="ment">' . $sz('ment') . '</button>' : '')
            . '<button type="button" class="s-suti-gomb" data-suti-muvelet="elfogad">' . $sz('elfogad') . '</button>'
            . '<button type="button" class="s-suti-gomb" data-suti-muvelet="elutasit">' . $sz('elutasit') . '</button>'
            . (!$modal ? '<button type="button" class="s-suti-gomb" data-suti-muvelet="beallit" aria-haspopup="dialog">' . $sz('beallit') . '</button>' : '')
            . '</div>';

        $h  = '<div class="s-suti" id="s-suti" hidden>';
        $h .= '<section class="s-suti-sav" role="region" aria-labelledby="s-suti-cim" aria-describedby="s-suti-leiras">';
        $h .= '<h2 class="s-suti-cim" id="s-suti-cim">' . $sz('cim') . '</h2>';
        $h .= '<p class="s-suti-leiras" id="s-suti-leiras">' . $sz('leiras') . ' <a class="s-suti-link" href="' . esc_url(self::tajekoztato_url()) . '">' . $sz('tajekoztato') . '</a></p>';
        $h .= $gombok(false);
        $h .= '</section></div>';

        $h .= '<div class="s-suti-modal" id="s-suti-modal" hidden>';
        $h .= '<div class="s-suti-hatter" data-suti-muvelet="bezar"></div>';
        $h .= '<div class="s-suti-ablak" role="dialog" aria-modal="true" aria-labelledby="s-suti-modal-cim" aria-describedby="s-suti-modal-leiras" tabindex="-1">';
        $h .= '<button type="button" class="s-suti-bezar" data-suti-muvelet="bezar" aria-label="' . esc_attr__('Bezárás', 'sdh-sales') . '"><span aria-hidden="true">×</span></button>';
        $h .= '<h2 class="s-suti-cim" id="s-suti-modal-cim">' . $sz('modal_cim') . '</h2>';
        $h .= '<p class="s-suti-leiras" id="s-suti-modal-leiras">' . $sz('modal_leiras') . ' <a class="s-suti-link" href="' . esc_url(self::tajekoztato_url()) . '">' . $sz('tajekoztato') . '</a></p>';
        $h .= '<p class="s-suti-gpc" hidden>' . $sz('gpc') . '</p>';
        $h .= '<ul class="s-suti-kategoriak">';

        foreach ($kat as $k => $c) {
            $fix = $k === 'szukseges';
            $h  .= '<li class="s-suti-kat">';
            $h  .= '<div class="s-suti-kat-fej"><span class="s-suti-kat-nev" id="s-suti-k-' . $k . '">' . esc_html($c) . '</span>';
            $h  .= '<label class="s-suti-kapcsolo"><input type="checkbox" role="switch" class="s-suti-valaszto" data-suti-kat="' . $k . '"'
                . ($fix ? ' checked disabled' : '') . ' aria-labelledby="s-suti-k-' . $k . '" aria-describedby="s-suti-l-' . $k . '">'
                . '<span class="s-suti-csuszka" aria-hidden="true"></span></label></div>';
            $h  .= '<p class="s-suti-kat-leiras" id="s-suti-l-' . $k . '">' . $sz($k) . '</p>';
            $h  .= '</li>';
        }

        $h .= '</ul>';
        $h .= $gombok(true);
        $h .= '</div></div>';

        return $h;
    }

    public static function panel(): void
    {
        if (self::$kiirva || !self::be() || is_admin()) {
            return;
        }

        self::$kiirva = true;
        echo self::panel_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- a panel_html() minden darabot escapel.
    }

    /* =================================================================
     * WP Consent API, CSP, tokenek
     * ============================================================== */

    public static function consent_tipus($tipus): string
    {
        return 'optin';
    }

    /**
     * @param mixed $csp [irányelv => [forrás, …]]
     * @return mixed
     */
    public static function csp($csp)
    {
        if (!is_array($csp) || (self::ga4() === '' && self::gtm() === '' && !defined('GOOGLESITEKIT_VERSION'))) {
            return $csp;
        }

        $add = [
            'script-src'  => ['https://www.googletagmanager.com', 'https://*.googletagmanager.com'],
            'img-src'     => ['https://*.google-analytics.com', 'https://*.googletagmanager.com'],
            'connect-src' => ['https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com'],
        ];

        if (self::gtm() !== '') {
            $add['frame-src'] = ['https://www.googletagmanager.com'];
        }

        foreach ($add as $ir => $forrasok) {
            $regi      = isset($csp[$ir]) ? (array) $csp[$ir] : [];
            $csp[$ir]  = array_values(array_unique(array_merge($regi, $forrasok)));
        }

        return $csp;
    }

    /**
     * @param array<string, string> $t
     * @return array<string, string>
     */
    public static function tokenek(array $t): array
    {
        foreach (['tarhely_nev', 'tarhely_cim', 'tarhely_elerhetoseg', 'levelezo_nev'] as $k) {
            $v = trim((string) self::b('jogi.' . $k));
            $t['{' . $k . '}'] = $v !== '' ? $v : (string) self::alapok()['jogi.' . $k];
        }

        return $t;
    }

    /** A jogi oldalak tartalmában a tokenek ({ceg}, {termeknev}, …) cseréje megjelenítéskor. */
    public static function jogi_tokenek(string $html): string
    {
        $id = (int) get_the_ID();

        if ($id <= 0 || strpos($html, '{') === false || get_post_type($id) !== 'page') {
            return $html;
        }

        $jogi = false;

        foreach (SDH_Sales_Tartalom::szekciok($id) as $sz) {
            if (is_array($sz) && ($sz['tipus'] ?? '') === 'jogi') {
                $jogi = true;
                break;
            }
        }

        if (!$jogi) {
            return $html;
        }

        $tok = array_map('esc_html', SDH_Sales::tokenek());

        return strtr($html, $tok);
    }

    /* =================================================================
     * Admin-beállítások csoportja
     * ============================================================== */

    /**
     * @param array<string, mixed> $cs
     * @return array<string, mixed>
     */
    public static function admin_csoport(array $cs): array
    {
        $szov = [];

        foreach (['cim', 'leiras', 'elfogad', 'elutasit', 'beallit', 'ment', 'modal_cim', 'modal_leiras', 'tajekoztato', 'gpc', 'szukseges', 'statisztika', 'marketing', 'preferencia'] as $k) {
            $szov[] = ['k' => 'suti.szoveg.' . $k, 't' => in_array($k, ['leiras', 'modal_leiras', 'gpc', 'szukseges', 'statisztika', 'marketing', 'preferencia'], true) ? 'hosszu' : 'szoveg', 'c' => 'Szöveg: ' . $k, 'ful' => 'Szövegek', 'alap' => self::alapok()['suti.szoveg.' . $k]];
        }

        $cs['suti'] = [
            'cim'     => 'Sütik és hozzájárulás',
            'ikon'    => 'pajzs',
            'leiras'  => 'Süti-sáv, Google-mérés (csak hozzájárulás után), a jogi oldalak tárhely- és levelezési adatai.',
            'sorrend' => 70,
            'mezok'   => array_merge([
                ['k' => 'suti.be', 't' => 'kapcsolo', 'c' => 'Süti-sáv és beállító ablak', 'ful' => 'Alap', 'alap' => true],
                ['k' => 'suti.verzio', 't' => 'szoveg', 'c' => 'Szabályzat verziója', 's' => 'Ha átírod (pl. 1.0 → 1.1), minden látogatótól újra megkérdezi.', 'ful' => 'Alap', 'alap' => '1.0'],
                ['k' => 'suti.honap', 't' => 'szam', 'c' => 'A döntés érvényessége (hónap)', 'min' => 1, 'max' => 13, 'ful' => 'Alap', 'alap' => 6],
                ['k' => 'suti.naplo_ev', 't' => 'szam', 'c' => 'Hozzájárulás-napló megőrzése (év)', 'min' => 1, 'max' => 10, 'ful' => 'Alap', 'alap' => 5],
                ['k' => 'suti.ga4', 't' => 'szoveg', 'c' => 'Google Analytics 4 mérőazonosító (G-…)', 's' => 'Csak statisztikai hozzájárulás után töltődik be. Ha a mérést már más betölti, hagyd üresen.', 'ful' => 'Google', 'alap' => ''],
                ['k' => 'suti.gtm', 't' => 'szoveg', 'c' => 'Google Tag Manager azonosító (GTM-…)', 'ful' => 'Google', 'alap' => ''],
                ['k' => 'suti.gtm_kategoria', 't' => 'valaszto', 'c' => 'A Tag Manager ennél a hozzájárulásnál indul', 'o' => ['statisztika' => 'Statisztika', 'marketing' => 'Marketing'], 'ful' => 'Google', 'alap' => 'statisztika'],
                ['k' => 'suti.sitekit_blokk', 't' => 'kapcsolo', 'c' => 'Más forrásból betöltött Google-mérőkód (gtag) is csak hozzájárulás után', 'ful' => 'Google', 'alap' => true],
                ['k' => 'jogi.tarhely_nev', 't' => 'szoveg', 'c' => 'Tárhelyszolgáltató neve', 'ful' => 'Jogi adatok', 'alap' => ''],
                ['k' => 'jogi.tarhely_cim', 't' => 'szoveg', 'c' => 'Tárhelyszolgáltató székhelye', 'ful' => 'Jogi adatok', 'alap' => ''],
                ['k' => 'jogi.tarhely_elerhetoseg', 't' => 'szoveg', 'c' => 'Tárhelyszolgáltató elérhetősége', 'ful' => 'Jogi adatok', 'alap' => ''],
                ['k' => 'jogi.levelezo_nev', 't' => 'szoveg', 'c' => 'Levelezési (SMTP) szolgáltató', 'ful' => 'Jogi adatok', 'alap' => ''],
                ['k' => '_suti_export', 't' => 'link', 'c' => 'Hozzájárulás-napló letöltése (CSV)', 'url' => esc_url_raw(add_query_arg('_wpnonce', wp_create_nonce('wp_rest'), rest_url(SDH_Sales_Rest::NS . '/admin/suti/export'))), 'ful' => 'Alap'],
            ], $szov),
        ];

        return $cs;
    }

    /* =================================================================
     * REST: napló, export
     * ============================================================== */

    /** @return WP_REST_Response|WP_Error */
    public static function rest_naplo(WP_REST_Request $r)
    {
        $p    = (array) $r->get_json_params();
        $azon = (string) ($p['azon'] ?? '');

        if (preg_match('/^[A-Za-z0-9_-]{16,40}$/', $azon) !== 1) {
            return SDH_Sales_Rest::hiba(__('Érvénytelen azonosító.', 'sdh-sales'), 400, 'sdh_suti_azon');
        }

        $verzio = (string) ($p['verzio'] ?? '');

        if (preg_match('/^[0-9A-Za-z._-]{1,20}$/', $verzio) !== 1) {
            return SDH_Sales_Rest::hiba(__('Érvénytelen verzió.', 'sdh-sales'), 400, 'sdh_suti_verzio');
        }

        $be  = (array) ($p['kategoriak'] ?? []);
        $kat = ['szukseges' => 1];

        foreach (['statisztika', 'marketing', 'preferencia'] as $k) {
            $kat[$k] = !empty($be[$k]) ? 1 : 0;
        }

        $kat['gpc'] = !empty($p['gpc']) ? 1 : 0;

        global $wpdb;

        $ok = $wpdb->insert(SDH_Sales_Schema::t('suti'), [
            'ido'        => current_time('mysql', true),
            'azon'       => $azon,
            'kategoriak' => (string) wp_json_encode($kat),
            'verzio'     => $verzio,
            'ip_hash'    => SDH_Sales::ip_hash(),
        ]);

        if ($ok === false) {
            return SDH_Sales_Rest::hiba(__('Valami nem sikerült. Próbáld újra.', 'sdh-sales'), 500);
        }

        return new WP_REST_Response(['ok' => true], 201);
    }

    /** @return array<int, array<int, string>> a CSV sorai */
    public static function export_sorok(string $tol = '', string $ig = ''): array
    {
        global $wpdb;

        $hol = [];
        $par = [];

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tol) === 1) {
            $hol[] = 'ido >= %s';
            $par[] = $tol . ' 00:00:00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ig) === 1) {
            $hol[] = 'ido <= %s';
            $par[] = $ig . ' 23:59:59';
        }

        $sql = 'SELECT id, ido, azon, kategoriak, verzio, ip_hash FROM ' . SDH_Sales_Schema::t('suti') . ($hol ? ' WHERE ' . implode(' AND ', $hol) : '') . ' ORDER BY id ASC LIMIT 200000';
        $s   = (array) $wpdb->get_results($par ? $wpdb->prepare($sql, ...$par) : $sql, ARRAY_A);

        $ki = [['id', 'ido_utc', 'azonosito', 'szukseges', 'statisztika', 'marketing', 'preferencia', 'gpc', 'verzio', 'ip_lenyomat']];

        foreach ($s as $sor) {
            $k    = json_decode((string) $sor['kategoriak'], true);
            $k    = is_array($k) ? $k : [];
            $ki[] = [
                (string) $sor['id'], (string) $sor['ido'], (string) $sor['azon'],
                (string) (int) ($k['szukseges'] ?? 1), (string) (int) ($k['statisztika'] ?? 0), (string) (int) ($k['marketing'] ?? 0),
                (string) (int) ($k['preferencia'] ?? 0), (string) (int) ($k['gpc'] ?? 0), (string) $sor['verzio'], (string) $sor['ip_hash'],
            ];
        }

        return $ki;
    }

    /** CSV-szöveg (UTF-8 BOM, pontosvessző – a magyar Excelnek), képlet-befecskendezés ellen védve. @param array<int, array<int, string>> $sorok */
    public static function csv(array $sorok): string
    {
        $ki = "\xEF\xBB\xBF";

        foreach ($sorok as $sor) {
            $ki .= implode(';', array_map(static function (string $c): string {
                if ($c !== '' && strpbrk($c[0], "=+-@\t\r") !== false) {
                    $c = "'" . $c;
                }

                return '"' . str_replace('"', '""', $c) . '"';
            }, $sor)) . "\r\n";
        }

        return $ki;
    }

    public static function rest_export(WP_REST_Request $r): WP_REST_Response
    {
        $csv = self::csv(self::export_sorok((string) $r->get_param('tol'), (string) $r->get_param('ig')));
        $v   = new WP_REST_Response($csv, 200);
        $v->header('Content-Type', 'text/csv; charset=utf-8');
        $v->header('Content-Disposition', 'attachment; filename="suti-hozzajarulas-' . gmdate('Y-m-d') . '.csv"');

        if (class_exists('SDH_Sales_Naplo')) {
            SDH_Sales_Naplo::ir('suti', 'Süti-hozzájárulás napló exportálva (' . (substr_count($csv, "\r\n") - 1) . ' sor)', 'info');
        }

        return $v;
    }

    /** A CSV-t nyersen szolgálja ki (nem JSON-ként). */
    public static function csv_kiszolgal($kiszolgalva, $valasz, $keres, $szerver)
    {
        if ($kiszolgalva || !($keres instanceof WP_REST_Request) || $keres->get_route() !== '/' . SDH_Sales_Rest::NS . '/admin/suti/export') {
            return $kiszolgalva;
        }

        if ($valasz instanceof WP_REST_Response && is_string($valasz->get_data()) && $valasz->get_status() === 200) {
            echo $valasz->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV-letöltés, nem HTML.

            return true;
        }

        return $kiszolgalva;
    }

    /** Naponta egyszer: a megőrzési időn túli napló törlése. */
    public static function takarit(): void
    {
        if (get_transient('sdh_sales_suti_takaritas')) {
            return;
        }

        set_transient('sdh_sales_suti_takaritas', 1, DAY_IN_SECONDS);

        global $wpdb;

        $ev = max(1, min(10, (int) self::b('suti.naplo_ev')));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Sales_Schema::t('suti') . ' WHERE ido < %s', gmdate('Y-m-d H:i:s', time() - $ev * 365 * DAY_IN_SECONDS)));
    }
}
