<?php
/**
 * SDH Értékesítés – admin: saját, teljes képernyős alkalmazás a wp-adminon belül (admin.php?page=sdh-sales, menü „Értékesítés”),
 * a Műhely / SDH Központ felületének mintájára. Vanilla JS (assets/admin.js + admin-*.js), REST: sdh-sales/v1/admin/*.
 *
 * Rejtés (weboldal/REJTES.md): a felület szövegeiben nincs „SDH” / „WordPress”; a JS-globálisok `PA_BOOT` / `PA`,
 * a REST-cím mindig rest_url(SDH_Sales_Rest::NS)-ből jön.
 *
 * Ez a fájl: az oldal váza, a panel-nyilvántartás, és az Áttekintés / Rendelések / Vevők / Leadek / Napló / keresés végpontjai.
 * Tartalom (oldalak, szekciók, elemek, menük, előnézet): class-sdh-sales-admin-tartalom.php.
 * Beállítások, Arculat, Export / Import: class-sdh-sales-admin-beallitas.php.
 *
 * Bővítés:
 *   - `sdh_sales_admin_panelek` szűrő: [kulcs => {cim, ikon, csoport, sorrend, elerheto: bool, js?: URL, paletta?: bool}].
 *     Egy külső panel a saját JS-ében: `window.PA.panel('kulcs', async (hova, param) => {…})` (a `js` URL a vázzal töltődik).
 *   - `sdh_sales_admin_rendeles_reszlet` szűrő: (array $blokkok, array $rendeles) → [{cim, sorok: [[címke, érték], …]}].
 *   - `sdh_sales_admin_beallitas_csoportok` szűrő (lásd -admin-beallitas.php).
 *   - `sdh_sales_admin_lead_allapotok` szűrő: a lead-állapotok listája.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Admin
{
    public const OLDAL = 'ertekesites';
    public const META_TEMA = 'sdh_sales_admin_tema';

    /** Kiszolgálási lánc lépései (ARCHITEKTURA.md §5). */
    public const LEPESEK = ['kozpont' => 'Központ (licenc)', 'szamla' => 'Végszámla', 'email' => 'Üdvözlő levél', 'idopont' => 'Időpont felajánlása', 'ertesites' => 'Értesítés'];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 20);
        add_filter('sdh_sales_visszaallit_lead', [self::class, 'lead_visszaallit'], 10, 3);

        $ir  = ['admin_ir', 240, 60];
        $olv = ['admin_olv', 900, 60];

        SDH_Sales_Rest::ut('/admin/attekintes', 'GET', [self::class, 'r_attekintes'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/rendelesek', 'GET', [self::class, 'r_rendelesek'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/rendeles/(?P<id>\d+)', 'GET', [self::class, 'r_rendeles'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/rendeles/(?P<id>\d+)/ujra', 'POST', [self::class, 'r_ujra'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/vevok', 'GET', [self::class, 'r_vevok'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/leadek', 'GET', [self::class, 'r_leadek'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/lead/(?P<id>\d+)', 'POST', [self::class, 'r_lead'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/naplo', 'GET', [self::class, 'r_naplo'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/naplo/(?P<id>\d+)', 'GET', [self::class, 'r_naplo_egy'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/naplo/(?P<id>\d+)/visszaallit', 'POST', [self::class, 'r_visszaallit'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/kereses', 'GET', [self::class, 'r_kereses'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/tema', 'POST', [self::class, 'r_tema'], 'admin', $ir);
    }

    public static function url(string $hash = ''): string
    {
        return admin_url('admin.php?page=' . self::OLDAL) . ($hash !== '' ? '#/' . ltrim($hash, '#/') : '');
    }

    public static function menu(): void
    {
        if (!SDH_Sales::admin()) {
            return;
        }

        $jog  = current_user_can('manage_options') ? 'manage_options' : SDH_Sales::JOG;
        $hook = add_menu_page('Értékesítés', 'Értékesítés', $jog, self::OLDAL, '__return_null', 'dashicons-cart', 3);

        add_action('load-' . $hook, [self::class, 'oldal']);
    }

    /* =================================================================
     * Panelek
     * ============================================================== */

    /** @return array<int, string> a regisztrált admin-útvonalak normalizálva (`/admin/rendeles/{}/fizetve`) */
    public static function utak(): array
    {
        $ki = [];

        foreach (array_keys(rest_get_server()->get_routes(SDH_Sales_Rest::NS)) as $u) {
            $u = (string) preg_replace('/\(\?P<[^>]+>[^)]*\)/', '{}', (string) $u);

            if (strpos($u, '/' . SDH_Sales_Rest::NS . '/admin/') === 0) {
                $ki[] = substr($u, strlen('/' . SDH_Sales_Rest::NS));
            }
        }

        sort($ki);

        return array_values(array_unique($ki));
    }

    /** @return array<string, array<string, mixed>> */
    public static function panelek(?array $utak = null): array
    {
        $utak = $utak ?? self::utak();
        $van  = static fn (string $u): bool => in_array($u, $utak, true);

        $p = [
            'attekintes' => ['cim' => 'Áttekintés', 'ikon' => 'attekintes', 'csoport' => '', 'sorrend' => 10],
            'rendelesek' => ['cim' => 'Rendelések', 'ikon' => 'rendeles', 'csoport' => 'Értékesítés', 'sorrend' => 20],
            'bank'       => ['cim' => 'Banki egyeztetés', 'ikon' => 'bank', 'csoport' => 'Értékesítés', 'sorrend' => 25, 'elerheto' => $van('/admin/bank/fuggo')],
            'vevok'      => ['cim' => 'Vevők', 'ikon' => 'vevo', 'csoport' => 'Értékesítés', 'sorrend' => 30],
            'leadek'     => ['cim' => 'Leadek', 'ikon' => 'lead', 'csoport' => 'Értékesítés', 'sorrend' => 40],
            'foglalasok' => ['cim' => 'Foglalások', 'ikon' => 'naptar', 'csoport' => 'Értékesítés', 'sorrend' => 50, 'elerheto' => $van('/admin/foglalasok')],
            'oldalak'    => ['cim' => 'Oldalak', 'ikon' => 'oldal', 'csoport' => 'Tartalom', 'sorrend' => 60],
            'elemek'     => ['cim' => 'Elemek', 'ikon' => 'elem', 'csoport' => 'Tartalom', 'sorrend' => 70],
            'menuk'      => ['cim' => 'Menü és lábléc', 'ikon' => 'menu', 'csoport' => 'Tartalom', 'sorrend' => 80],
            'arculat'    => ['cim' => 'Arculat', 'ikon' => 'arculat', 'csoport' => 'Tartalom', 'sorrend' => 90],
            'beallitasok' => ['cim' => 'Beállítások', 'ikon' => 'beallitas', 'csoport' => 'Rendszer', 'sorrend' => 100],
            'naplo'      => ['cim' => 'Napló', 'ikon' => 'naplo', 'csoport' => 'Rendszer', 'sorrend' => 110],
            'export'     => ['cim' => 'Export / Import', 'ikon' => 'export', 'csoport' => 'Rendszer', 'sorrend' => 120],
        ];

        $p = (array) apply_filters('sdh_sales_admin_panelek', $p, $utak);
        $ki = [];

        foreach ($p as $k => $v) {
            if (!is_array($v) || preg_match('/^[a-z0-9_-]{1,40}$/', (string) $k) !== 1) {
                continue;
            }

            $ki[(string) $k] = [
                'cim'      => (string) ($v['cim'] ?? $k),
                'ikon'     => sanitize_key((string) ($v['ikon'] ?? 'info')),
                'csoport'  => (string) ($v['csoport'] ?? 'Egyéb'),
                'sorrend'  => (int) ($v['sorrend'] ?? 500),
                'elerheto' => (bool) ($v['elerheto'] ?? true),
                'js'       => isset($v['js']) ? esc_url_raw((string) $v['js']) : '',
            ];
        }

        uasort($ki, static fn ($a, $b) => $a['sorrend'] <=> $b['sorrend']);

        return $ki;
    }

    /* =================================================================
     * Az oldal (saját HTML, a wp-admin keretei nélkül)
     * ============================================================== */

    public static function oldal(): void
    {
        if (!SDH_Sales::admin()) {
            wp_die('Nincs jogosultság.', 403);
        }

        // Rejtés: ezen az oldalon a fájlok is az álnév-útvonalakról töltődnek (a webszerver a valódiakat elzárhatja).
        $alias = class_exists('SDH_Sales_Rejtes') && method_exists('SDH_Sales_Rejtes', 'alias_csere') && SDH_Sales_Rejtes::be('aktiv') && SDH_Sales_Rejtes::be('alias');
        $al    = static fn (string $x): string => $alias ? SDH_Sales_Rejtes::alias_csere($x) : $x;

        if ($alias) {
            add_filter('script_loader_src', $al, 99);
            add_filter('style_loader_src', $al, 99);
            add_filter('includes_url', $al, 99);
        }

        $u     = wp_get_current_user();
        $tema  = (string) get_user_meta($u->ID, self::META_TEMA, true);
        $tema  = in_array($tema, ['sotet', 'vilagos'], true) ? $tema : '';
        $utak  = self::utak();
        $alap  = $al(SDH_SALES_URL . 'assets/');
        $ver   = static fn (string $f): string => SDH_SALES_VERSION . '.' . (string) @filemtime(SDH_SALES_DIR . 'assets/' . $f); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $nyelv = array_values(array_filter(array_map('strval', (array) SDH_Sales::b('nyelvek', ['hu'])), static fn ($x) => preg_match('/^[a-z]{2}$/', $x) === 1)) ?: ['hu'];

        $boot = [
            'rest'      => esc_url_raw(rest_url(SDH_Sales_Rest::NS . '/')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'nev'       => (string) ($u->first_name !== '' ? $u->first_name : $u->display_name),
            'teljes'    => (string) $u->display_name,
            'avatar'    => '', // nincs külső kérés (Gravatar) – monogram
            'wp'        => admin_url('index.php'),
            'kilep'     => wp_logout_url(wp_login_url()),
            'webhely'   => home_url('/'),
            'verzio'    => SDH_SALES_VERSION,
            'tema'      => $tema,
            'termeknev' => SDH_Sales::termeknev(),
            'afa'       => SDH_Sales::afa(),
            'penznem'   => (string) SDH_Sales::b('penznem', 'HUF'),
            'nyelvek'   => $nyelv,
            'panelek'   => self::panelek($utak),
            'utak'      => $utak,
            'lepesek'   => self::lepesek(),
            'lead_allapotok' => self::lead_allapotok(),
            'ujra'      => class_exists('SDH_Sales_Kiszolgalas') && method_exists('SDH_Sales_Kiszolgalas', 'lepes_ujra'),
            'ikonok'    => SDH_Sales_Szekcio::ikonok(),
            'szekciok'  => SDH_Sales_Szekcio::tipusok(),
            'elemek'    => SDH_Sales_Szekcio::elem_semak(),
            'media'     => current_user_can('upload_files'),
        ];

        if ($boot['media']) {
            wp_enqueue_media();
        }

        $extra = [];

        foreach ($boot['panelek'] as $p) {
            if ($p['js'] !== '' && $p['elerheto']) {
                $extra[] = $p['js'];
            }
        }

        $GLOBALS['concatenate_scripts'] = false; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- a saját oldalon külön töltünk.

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: SAMEORIGIN');
        ?>
<!doctype html>
<html lang="hu" data-tema="<?php echo esc_attr($tema); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <meta name="robots" content="noindex, nofollow">
    <title>Értékesítés · <?php echo esc_html(SDH_Sales::termeknev()); ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%236d5efc'/%3E%3Cstop offset='1' stop-color='%2322d3ee'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='32' height='32' rx='9' fill='url(%23g)'/%3E%3Cpath d='M9 11h14l-2 9H11z' stroke='white' stroke-width='2.4' fill='none' stroke-linejoin='round'/%3E%3C/svg%3E">
    <?php
    if ($boot['media']) {
        wp_print_styles();
        print_head_scripts();
    }
    ?>
    <link rel="stylesheet" href="<?php echo esc_url($alap . 'admin.css?ver=' . rawurlencode($ver('admin.css'))); ?>">
</head>
<body class="sa">
    <a class="sa-ugro" href="#sa-tartalom">Ugrás a tartalomra</a>
    <div id="sa-app" class="sa-app">
        <div class="sa-indul"><div class="sa-indul__jel"></div><span>Értékesítés</span></div>
    </div>
    <script>window.PA_BOOT = <?php echo wp_json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>
    <script src="<?php echo esc_url($alap . 'admin.js?ver=' . rawurlencode($ver('admin.js'))); ?>"></script>
    <script src="<?php echo esc_url($alap . 'admin-tartalom.js?ver=' . rawurlencode($ver('admin-tartalom.js'))); ?>"></script>
    <script src="<?php echo esc_url($alap . 'admin-beallitas.js?ver=' . rawurlencode($ver('admin-beallitas.js'))); ?>"></script>
    <?php foreach ($extra as $js) : ?>
    <script src="<?php echo esc_url($js); ?>"></script>
    <?php endforeach; ?>
    <script>window.PA && window.PA.indul();</script>
    <?php
    if ($boot['media']) {
        print_footer_scripts();
        wp_print_media_templates();
    }
    ?>
</body>
</html>
        <?php
        exit;
    }

    /* =================================================================
     * Segédek
     * ============================================================== */

    /** UTC időpont (MySQL) a helyi nap kezdetéből N nappal ezelőtt. */
    public static function nap_kezdet(int $nap_elotte = 0): string
    {
        $tz = wp_timezone();
        $d  = new DateTimeImmutable('today', $tz);

        if ($nap_elotte > 0) {
            $d = $d->modify('-' . $nap_elotte . ' days');
        }

        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** UTC MySQL időből Unix-idő. */
    public static function ts(?string $utc): int
    {
        if ($utc === null || $utc === '' || strpos($utc, '0000') === 0) {
            return 0;
        }

        $t = strtotime($utc . ' UTC');

        return $t === false ? 0 : $t;
    }

    /** @return array<string, mixed> */
    private static function json($s): array
    {
        $a = is_string($s) ? json_decode($s, true) : null;

        return is_array($a) ? $a : [];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> egy rendeléssor a listához */
    private static function rendeles_sor(array $r): array
    {
        $v = self::json($r['vevo'] ?? '');

        return [
            'id'        => (int) $r['id'],
            'azon'      => (string) $r['azon'],
            'allapot'   => (string) $r['allapot'],
            'tipus'     => (string) $r['tipus'],
            'vevo'      => (string) ($v['ceg'] ?? $v['nev'] ?? $v['kapcsolattarto'] ?? ''),
            'kapcsolattarto' => (string) ($v['kapcsolattarto'] ?? $v['nev'] ?? ''),
            'email'     => (string) ($r['email'] !== '' ? $r['email'] : ($v['email'] ?? '')),
            'netto'     => (int) $r['netto'],
            'brutto'    => (int) $r['brutto'],
            'fizetett'  => (int) $r['fizetett'],
            'fizmod'    => (string) $r['fizmod'],
            'ciklus'    => (string) $r['ciklus'],
            'letrehozva' => self::ts($r['letrehozva'] ?? null),
            'fizetve_ido' => self::ts($r['fizetve_ido'] ?? null),
            'lanc_hiba' => self::lanc_hibas((string) ($r['lanc'] ?? '')),
        ];
    }

    private static function lanc_hibas(string $lanc): bool
    {
        if ($lanc === '' || strpos($lanc, 'hiba') === false) {
            return false;
        }

        foreach (self::lanc_lepesek(self::json($lanc)) as $l) {
            if (is_array($l) && ($l['allapot'] ?? '') === 'hiba') {
                return true;
            }
        }

        return false;
    }

    /** A lánc lépései – a kiszolgálás `{lepesek: {…}}` alakját és a lapos alakot is érti. @param array<string, mixed> $lanc */
    private static function lanc_lepesek(array $lanc): array
    {
        return is_array($lanc['lepesek'] ?? null) ? $lanc['lepesek'] : array_filter($lanc, 'is_array');
    }

    /** @return array<string, string> lépéskulcs → név (a kiszolgálás saját listája, ha van) */
    public static function lepesek(): array
    {
        if (class_exists('SDH_Sales_Kiszolgalas') && defined('SDH_Sales_Kiszolgalas::LEPES_NEV') && is_array(SDH_Sales_Kiszolgalas::LEPES_NEV)) {
            return array_map('strval', SDH_Sales_Kiszolgalas::LEPES_NEV);
        }

        return self::LEPESEK;
    }

    /** @return array<string, string> */
    public static function lead_allapotok(): array
    {
        return (array) apply_filters('sdh_sales_admin_lead_allapotok', [
            'uj'          => 'Új',
            'folyamatban' => 'Folyamatban',
            'ajanlat'     => 'Ajánlat kiküldve',
            'nyert'       => 'Nyert',
            'elveszett'   => 'Elveszett',
            'spam'        => 'Kéretlen',
        ]);
    }

    /* =================================================================
     * Áttekintés
     * ============================================================== */

    public static function r_attekintes(): WP_REST_Response
    {
        global $wpdb;

        $tr = SDH_Sales_Schema::t('rendeles');
        $tl = SDH_Sales_Schema::t('lead');
        $tf = SDH_Sales_Schema::t('foglalas');

        $hatar = ['ma' => self::nap_kezdet(0), 'het' => self::nap_kezdet(6), 'ho' => self::nap_kezdet(29)];
        $kpi   = [];

        // Egy lekérdezés a 30 napra, a bontás PHP-ben (SQLite és MySQL alatt ugyanúgy fut).
        $rend = (array) $wpdb->get_results($wpdb->prepare("SELECT allapot, netto, brutto, letrehozva, fizetve_ido FROM {$tr} WHERE (letrehozva >= %s OR fizetve_ido >= %s) AND allapot <> 'torolve'", $hatar['ho'], $hatar['ho']), ARRAY_A);
        $lead = (array) $wpdb->get_col($wpdb->prepare("SELECT ido FROM {$tl} WHERE ido >= %s", $hatar['ho']));
        $fogl = (array) $wpdb->get_col($wpdb->prepare("SELECT ido FROM {$tf} WHERE ido >= %s AND allapot <> 'torolve'", $hatar['ho']));

        $fizetett = static fn (array $r): bool => in_array($r['allapot'], ['fizetve', 'kiszolgalva'], true) && !empty($r['fizetve_ido']);

        foreach ($hatar as $k => $h) {
            $kpi[$k] = [
                'rendeles'      => count(array_filter($rend, static fn ($r) => (string) $r['letrehozva'] >= $h)),
                'bevetel_netto' => array_sum(array_map(static fn ($r) => (int) $r['netto'], array_filter($rend, static fn ($r) => $fizetett($r) && (string) $r['fizetve_ido'] >= $h))),
                'bevetel_brutto' => array_sum(array_map(static fn ($r) => (int) $r['brutto'], array_filter($rend, static fn ($r) => $fizetett($r) && (string) $r['fizetve_ido'] >= $h))),
                'lead'          => count(array_filter($lead, static fn ($i) => (string) $i >= $h)),
                'foglalas'      => count(array_filter($fogl, static fn ($i) => (string) $i >= $h)),
            ];
        }

        // Napi bontás (30 nap, helyi nap szerint).
        $tz    = wp_timezone();
        $napok = [];

        for ($i = 29; $i >= 0; $i--) {
            $napok[(new DateTimeImmutable('today', $tz))->modify('-' . $i . ' days')->format('Y-m-d')] = ['bevetel' => 0, 'rendeles' => 0];
        }

        $helyi = static fn (string $utc): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');

        foreach ($rend as $r) {
            if (!empty($r['letrehozva'])) {
                $n = $helyi((string) $r['letrehozva']);

                if (isset($napok[$n])) {
                    $napok[$n]['rendeles']++;
                }
            }

            if ($fizetett($r)) {
                $n = $helyi((string) $r['fizetve_ido']);

                if (isset($napok[$n])) {
                    $napok[$n]['bevetel'] += (int) $r['brutto'];
                }
            }
        }

        $nyitott = $wpdb->get_row("SELECT COUNT(*) AS db, COALESCE(SUM(brutto - fizetett), 0) AS osszeg FROM {$tr} WHERE allapot IN ('dijbekero', 'fizetesre_var')", ARRAY_A);

        $hibas = 0;
        $hibas_lista = [];

        foreach ((array) $wpdb->get_results("SELECT * FROM {$tr} WHERE lanc LIKE '%hiba%' AND allapot NOT IN ('torolve', 'visszaterites') ORDER BY id DESC LIMIT 200", ARRAY_A) as $r) {
            if (self::lanc_hibas((string) $r['lanc'])) {
                $hibas++;

                if (count($hibas_lista) < 6) {
                    $hibas_lista[] = self::rendeles_sor($r);
                }
            }
        }

        $utolso = array_map([self::class, 'rendeles_sor'], (array) $wpdb->get_results("SELECT * FROM {$tr} ORDER BY id DESC LIMIT 8", ARRAY_A));
        $kozelgo = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tf} WHERE kezdet >= %s AND allapot NOT IN ('torolve', 'lemondva')", gmdate('Y-m-d H:i:s')));
        $uj_lead = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tl} WHERE allapot = 'uj'");
        $naplo   = SDH_Sales_Naplo::lista(['db' => 8]);

        return new WP_REST_Response([
            'kpi'      => $kpi,
            'napok'    => $napok,
            'nyitott'  => ['db' => (int) ($nyitott['db'] ?? 0), 'osszeg' => (int) ($nyitott['osszeg'] ?? 0)],
            'lanc_hiba' => $hibas,
            'hibas'    => $hibas_lista,
            'utolso'   => $utolso,
            'kozelgo_foglalas' => $kozelgo,
            'uj_lead'  => $uj_lead,
            'naplo'    => array_map([self::class, 'naplo_sor'], $naplo['sorok']),
        ], 200);
    }

    /* =================================================================
     * Rendelések
     * ============================================================== */

    public static function r_rendelesek(WP_REST_Request $r): WP_REST_Response
    {
        global $wpdb;

        $t = SDH_Sales_Schema::t('rendeles');
        $w = ['1=1'];
        $a = [];

        $allapot = sanitize_key((string) $r->get_param('allapot'));

        if ($allapot === 'nyitott') {
            $w[] = "allapot IN ('uj', 'dijbekero', 'fizetesre_var')";
        } elseif ($allapot === 'hiba') {
            $w[] = "lanc LIKE '%hiba%'";
        } elseif ($allapot !== '' && $allapot !== 'mind') {
            $w[] = 'allapot = %s';
            $a[] = $allapot;
        }

        foreach (['tipus', 'fizmod', 'ciklus'] as $k) {
            $v = sanitize_key((string) $r->get_param($k));

            if ($v !== '') {
                $w[] = "{$k} = %s";
                $a[] = $v;
            }
        }

        $q = trim(sanitize_text_field((string) $r->get_param('q')));

        if ($q !== '') {
            $l   = '%' . $wpdb->esc_like($q) . '%';
            $w[] = '(azon LIKE %s OR email LIKE %s OR vevo LIKE %s OR licenc_kulcs LIKE %s OR kozlemeny LIKE %s)';
            array_push($a, $l, $l, $l, $l, $l);
        }

        $email = sanitize_email((string) $r->get_param('email'));

        if ($email !== '') {
            $w[] = 'email = %s';
            $a[] = $email;
        }

        $db    = max(1, min(100, (int) ($r->get_param('db') ?: 25)));
        $oldal = max(1, (int) ($r->get_param('oldal') ?: 1));
        $wh    = implode(' AND ', $w);
        $ossz  = (int) ($a ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$wh}", ...$a)) : $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$wh}"));
        $sorok = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE {$wh} ORDER BY id DESC LIMIT %d OFFSET %d", ...array_merge($a, [$db, ($oldal - 1) * $db])), ARRAY_A);

        $lista = array_map([self::class, 'rendeles_sor'], $sorok);

        if ($allapot === 'hiba') {
            $lista = array_values(array_filter($lista, static fn ($x) => $x['lanc_hiba']));
        }

        return new WP_REST_Response(['lista' => $lista, 'ossz' => $ossz, 'oldal' => $oldal, 'db' => $db], 200);
    }

    /** @return array<string, mixed>|null */
    public static function rendeles(int $id): ?array
    {
        global $wpdb;

        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($r) ? $r : null;
    }

    public static function r_rendeles(WP_REST_Request $q)
    {
        global $wpdb;

        $id = (int) $q['id'];
        $r  = self::rendeles($id);

        if ($r === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen rendelés.', 404, 'nincs');
        }

        $lanc = self::lanc_lepesek(self::json($r['lanc']));
        $nev  = self::lepesek();
        $l    = [];

        foreach (array_unique(array_merge(array_keys($nev), array_keys($lanc))) as $k) {
            $s   = is_array($lanc[$k] ?? null) ? $lanc[$k] : [];
            $l[] = [
                'kulcs'       => (string) $k,
                'cim'         => $nev[$k] ?? (string) $k,
                'allapot'     => (string) ($s['allapot'] ?? (isset($lanc[$k]) ? 'var' : 'nincs')),
                'probalkozas' => (int) ($s['probalkozas'] ?? 0),
                'uzenet'      => (string) ($s['uzenet'] ?? ''),
                'ido'         => is_numeric($s['ido'] ?? null) ? (int) $s['ido'] : self::ts(is_string($s['ido'] ?? null) ? $s['ido'] : null),
            ];
        }

        $fiz = array_map(static fn ($f) => [
            'id'         => (int) $f['id'],
            'forras'     => (string) $f['forras'],
            'tranzakcio' => (string) $f['tranzakcio'],
            'osszeg'     => (int) $f['osszeg'],
            'ido'        => self::ts($f['ido']),
            'allapot'    => (string) $f['allapot'],
        ], (array) $wpdb->get_results($wpdb->prepare('SELECT id, forras, tranzakcio, osszeg, ido, allapot FROM ' . SDH_Sales_Schema::t('fizetes') . ' WHERE rendeles_id = %d ORDER BY id ASC', $id), ARRAY_A));

        $tetelek = array_map(static fn ($t) => is_array($t) ? [
            'nev'    => (string) ($t['nev'] ?? $t['cim'] ?? $t['kulcs'] ?? ''),
            'tipus'  => (string) ($t['tipus'] ?? ''),
            'db'     => (int) ($t['db'] ?? $t['mennyiseg'] ?? 1),
            'netto'  => (int) ($t['netto'] ?? $t['ar'] ?? 0),
        ] : null, self::json($r['tetelek']));

        $naplo = SDH_Sales_Naplo::lista(['ref_tipus' => 'rendeles', 'ref_id' => $id, 'db' => 30]);
        $blokk = (array) apply_filters('sdh_sales_admin_rendeles_reszlet', [], $r);
        $tiszta = [];

        foreach ($blokk as $b) {
            if (is_array($b) && !empty($b['cim'])) {
                $tiszta[] = ['cim' => (string) $b['cim'], 'sorok' => array_values(array_map(static fn ($s) => [(string) ($s[0] ?? ''), (string) ($s[1] ?? '')], array_filter((array) ($b['sorok'] ?? []), 'is_array')))];
            }
        }

        return new WP_REST_Response(self::rendeles_sor($r) + [
            'vevo_adat'   => self::json($r['vevo']),
            'tetelek'     => array_values(array_filter($tetelek)),
            'afa'         => (int) $r['afa'],
            'penznem'     => (string) $r['penznem'],
            'kupon'       => (string) $r['kupon'],
            'kozlemeny'   => (string) $r['kozlemeny'],
            'licenc_kulcs' => (string) $r['licenc_kulcs'],
            'kozpont_licenc_id' => (int) $r['kozpont_licenc_id'],
            'szamla_dijbekero' => (string) $r['szamla_dijbekero'],
            'szamla_vegszamla' => (string) $r['szamla_vegszamla'],
            'hatarido'    => (string) ($r['hatarido'] ?? ''),
            'modositva'   => self::ts($r['modositva'] ?? null),
            'lanc'        => $l,
            'fizetesek'   => $fiz,
            'naplo'       => array_map([self::class, 'naplo_sor'], $naplo['sorok']),
            'blokkok'     => $tiszta,
        ], 200);
    }

    /** Egy kiszolgálási lépés újrafuttatása (az üdvözlő levél újraküldése = az `email` lépés újra). */
    public static function r_ujra(WP_REST_Request $q)
    {
        $id    = (int) $q['id'];
        $lepes = sanitize_key((string) $q->get_param('lepes'));

        if (self::rendeles($id) === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen rendelés.', 404, 'nincs');
        }

        if (!isset(self::lepesek()[$lepes])) {
            return SDH_Sales_Rest::hiba('Ismeretlen lépés.', 400);
        }

        if (!class_exists('SDH_Sales_Kiszolgalas') || !method_exists('SDH_Sales_Kiszolgalas', 'lepes_ujra')) {
            return SDH_Sales_Rest::hiba('A kiszolgálás még nincs bekapcsolva.', 501, 'nincs_bekapcsolva');
        }

        try {
            $ki = SDH_Sales_Kiszolgalas::lepes_ujra($id, $lepes);
        } catch (Throwable $x) {
            SDH_Sales_Naplo::ir('admin', 'Lépés újra – hiba: ' . $lepes . ': ' . $x->getMessage(), 'hiba', 'rendeles', $id);

            return SDH_Sales_Rest::hiba('A lépés nem futott le: ' . $x->getMessage(), 500);
        }

        $ok = is_array($ki) ? !empty($ki['ok']) : $ki !== false;
        $uz = is_array($ki) ? (string) ($ki['uzenet'] ?? '') : '';

        return new WP_REST_Response(['ok' => $ok, 'uzenet' => $uz !== '' ? $uz : ($ok ? 'A lépés lefutott.' : 'A lépés nem sikerült.')], 200);
    }

    /* =================================================================
     * Vevők (a rendelésekből, e-mail szerint)
     * ============================================================== */

    public static function r_vevok(WP_REST_Request $r): WP_REST_Response
    {
        global $wpdb;

        $t = SDH_Sales_Schema::t('rendeles');
        $q = trim(sanitize_text_field((string) $r->get_param('q')));
        $w = "email <> '' AND allapot <> 'torolve'";
        $a = [];

        if ($q !== '') {
            $l  = '%' . $wpdb->esc_like($q) . '%';
            $w .= ' AND (email LIKE %s OR vevo LIKE %s OR licenc_kulcs LIKE %s)';
            $a  = [$l, $l, $l];
        }

        $db    = max(1, min(100, (int) ($r->get_param('db') ?: 25)));
        $oldal = max(1, (int) ($r->get_param('oldal') ?: 1));
        $ossz  = (int) ($a ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT email) FROM {$t} WHERE {$w}", ...$a)) : $wpdb->get_var("SELECT COUNT(DISTINCT email) FROM {$t} WHERE {$w}"));
        $sql   = "SELECT email, COUNT(*) AS db, SUM(CASE WHEN allapot IN ('fizetve', 'kiszolgalva') THEN brutto ELSE 0 END) AS koltes, MAX(id) AS utolso_id, MAX(letrehozva) AS utolso FROM {$t} WHERE {$w} GROUP BY email ORDER BY utolso DESC LIMIT %d OFFSET %d";
        $sorok = (array) $wpdb->get_results($wpdb->prepare($sql, ...array_merge($a, [$db, ($oldal - 1) * $db])), ARRAY_A);
        $lista = [];

        foreach ($sorok as $s) {
            $u = self::rendeles((int) $s['utolso_id']);
            $v = self::json($u['vevo'] ?? '');
            $k = (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT licenc_kulcs FROM {$t} WHERE email = %s AND licenc_kulcs <> ''", (string) $s['email']));

            $lista[] = [
                'email'    => (string) $s['email'],
                'ceg'      => (string) ($v['ceg'] ?? ''),
                'nev'      => (string) ($v['kapcsolattarto'] ?? $v['nev'] ?? ''),
                'adoszam'  => (string) ($v['adoszam'] ?? ''),
                'telefon'  => (string) ($v['telefon'] ?? ''),
                'cim'      => is_array($v['cim'] ?? null) ? implode(', ', array_map('strval', $v['cim'])) : (string) ($v['cim'] ?? ''),
                'rendeles' => (int) $s['db'],
                'koltes'   => (int) $s['koltes'],
                'utolso'   => self::ts($s['utolso']),
                'licencek' => array_values(array_map('strval', $k)),
            ];
        }

        return new WP_REST_Response(['lista' => $lista, 'ossz' => $ossz, 'oldal' => $oldal, 'db' => $db], 200);
    }

    /* =================================================================
     * Leadek
     * ============================================================== */

    public static function r_leadek(WP_REST_Request $r): WP_REST_Response
    {
        global $wpdb;

        $t = SDH_Sales_Schema::t('lead');
        $w = ['1=1'];
        $a = [];

        foreach (['forras', 'allapot'] as $k) {
            $v = sanitize_key((string) $r->get_param($k));

            if ($v !== '' && $v !== 'mind') {
                $w[] = "{$k} = %s";
                $a[] = $v;
            }
        }

        $q = trim(sanitize_text_field((string) $r->get_param('q')));

        if ($q !== '') {
            $l   = '%' . $wpdb->esc_like($q) . '%';
            $w[] = '(email LIKE %s OR adat LIKE %s)';
            array_push($a, $l, $l);
        }

        $db    = max(1, min(100, (int) ($r->get_param('db') ?: 25)));
        $oldal = max(1, (int) ($r->get_param('oldal') ?: 1));
        $wh    = implode(' AND ', $w);
        $ossz  = (int) ($a ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$wh}", ...$a)) : $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$wh}"));
        $sorok = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE {$wh} ORDER BY id DESC LIMIT %d OFFSET %d", ...array_merge($a, [$db, ($oldal - 1) * $db])), ARRAY_A);
        $szam  = [];

        foreach ((array) $wpdb->get_results("SELECT forras, allapot, COUNT(*) AS db FROM {$t} GROUP BY forras, allapot", ARRAY_A) as $s) {
            $szam['forras'][(string) $s['forras']] = ($szam['forras'][(string) $s['forras']] ?? 0) + (int) $s['db'];
            $szam['allapot'][(string) $s['allapot']] = ($szam['allapot'][(string) $s['allapot']] ?? 0) + (int) $s['db'];
        }

        $lista = array_map(static function ($s) {
            $a = self::json($s['adat']);

            return [
                'id'      => (int) $s['id'],
                'forras'  => (string) $s['forras'],
                'email'   => (string) $s['email'],
                'nev'     => (string) ($a['nev'] ?? $a['kapcsolattarto'] ?? ''),
                'ceg'     => (string) ($a['ceg'] ?? ''),
                'allapot' => (string) $s['allapot'],
                'ido'     => self::ts($s['ido']),
                'adat'    => SDH_Sales_Szekcio::altalanos($a),
            ];
        }, $sorok);

        return new WP_REST_Response(['lista' => $lista, 'ossz' => $ossz, 'oldal' => $oldal, 'db' => $db, 'szamok' => $szam], 200);
    }

    public static function r_lead(WP_REST_Request $r)
    {
        global $wpdb;

        $id = (int) $r['id'];
        $t  = SDH_Sales_Schema::t('lead');
        $s  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id), ARRAY_A);

        if (!is_array($s)) {
            return SDH_Sales_Rest::hiba('Nincs ilyen lead.', 404, 'nincs');
        }

        $uj   = [];
        $all  = sanitize_key((string) $r->get_param('allapot'));
        $adat = self::json($s['adat']);

        if ($all !== '') {
            if (!isset(self::lead_allapotok()[$all])) {
                return SDH_Sales_Rest::hiba('Ismeretlen állapot.', 400);
            }

            $uj['allapot'] = $all;
        }

        if ($r->has_param('megjegyzes')) {
            $adat['admin_megjegyzes'] = mb_substr(sanitize_textarea_field((string) $r->get_param('megjegyzes')), 0, 2000);
            $uj['adat'] = (string) wp_json_encode($adat, JSON_UNESCAPED_UNICODE);
        }

        if ($uj === []) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        $wpdb->update($t, $uj, ['id' => $id]);
        SDH_Sales_Naplo::ir('lead', 'Lead #' . $id . ' módosítva' . (isset($uj['allapot']) ? ': ' . self::lead_allapotok()[$uj['allapot']] : ''), 'info', 'lead', $id, ['allapot' => $s['allapot'], 'adat' => self::json($s['adat'])], ['allapot' => $uj['allapot'] ?? $s['allapot'], 'adat' => $adat]);

        return new WP_REST_Response(['ok' => true, 'allapot' => $uj['allapot'] ?? (string) $s['allapot']], 200);
    }

    /** @param mixed $elotte */
    public static function lead_visszaallit(bool $ok, int $id, $elotte): bool
    {
        global $wpdb;

        if (!is_array($elotte) || !isset($elotte['allapot'])) {
            return $ok;
        }

        $n = $wpdb->update(SDH_Sales_Schema::t('lead'), [
            'allapot' => sanitize_key((string) $elotte['allapot']),
            'adat'    => (string) wp_json_encode(is_array($elotte['adat'] ?? null) ? $elotte['adat'] : [], JSON_UNESCAPED_UNICODE),
        ], ['id' => $id]);

        return $n !== false;
    }

    /* =================================================================
     * Napló
     * ============================================================== */

    /** @param array<string, mixed> $n @return array<string, mixed> */
    public static function naplo_sor(array $n): array
    {
        return [
            'id'        => (int) $n['id'],
            'ido'       => self::ts($n['ido']),
            'tipus'     => (string) $n['tipus'],
            'szint'     => (string) $n['szint'],
            'szoveg'    => (string) $n['szoveg'],
            'ref_tipus' => (string) $n['ref_tipus'],
            'ref_id'    => (int) $n['ref_id'],
            'ki'        => (string) $n['ki'],
            'valtozas'  => $n['elotte'] !== null || $n['utana'] !== null,
            'visszaallithato' => $n['elotte'] !== null,
        ];
    }

    public static function r_naplo(WP_REST_Request $r): WP_REST_Response
    {
        global $wpdb;

        $sz = [
            'tipus'     => sanitize_key((string) $r->get_param('tipus')),
            'szint'     => sanitize_key((string) $r->get_param('szint')),
            'ref_tipus' => sanitize_key((string) $r->get_param('ref_tipus')),
            'ref_id'    => (int) $r->get_param('ref_id'),
            'kereses'   => sanitize_text_field((string) $r->get_param('q')),
            'oldal'     => max(1, (int) $r->get_param('oldal')),
            'db'        => max(1, min(100, (int) ($r->get_param('db') ?: 40))),
        ];

        if ($sz['szint'] === 'mind') {
            $sz['szint'] = '';
        }

        $l      = SDH_Sales_Naplo::lista($sz);
        $tipusok = (array) $wpdb->get_col('SELECT DISTINCT tipus FROM ' . SDH_Sales_Schema::t('naplo') . ' ORDER BY tipus ASC LIMIT 100');

        return new WP_REST_Response(['lista' => array_map([self::class, 'naplo_sor'], $l['sorok']), 'ossz' => $l['ossz'], 'oldal' => $sz['oldal'], 'db' => $sz['db'], 'tipusok' => array_map('strval', $tipusok)], 200);
    }

    public static function r_naplo_egy(WP_REST_Request $r)
    {
        $n = SDH_Sales_Naplo::egy((int) $r['id']);

        if ($n === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen bejegyzés.', 404, 'nincs');
        }

        $el = $n['elotte'] === null ? null : json_decode((string) $n['elotte'], true);
        $ut = $n['utana'] === null ? null : json_decode((string) $n['utana'], true);

        // Titok soha nem megy ki (a beállítás-napló titkot nem ír, de a biztonság kedvéért).
        $takar = static function ($a) use (&$takar) {
            if (!is_array($a)) {
                return $a;
            }

            foreach ($a as $k => $v) {
                $a[$k] = is_string($k) && SDH_Sales_Exportimport::titok_kulcs($k) ? '••••' : $takar($v);
            }

            return $a;
        };

        return new WP_REST_Response(self::naplo_sor($n) + ['elotte' => $takar($el), 'utana' => $takar($ut)], 200);
    }

    public static function r_visszaallit(WP_REST_Request $r)
    {
        $h = SDH_Sales_Naplo::visszaallit((int) $r['id']);

        if ($h !== '') {
            return SDH_Sales_Rest::hiba($h, 409, 'nem_allithato');
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    /* =================================================================
     * Keresés (Ctrl+K), téma
     * ============================================================== */

    public static function r_kereses(WP_REST_Request $r): WP_REST_Response
    {
        global $wpdb;

        $q  = trim(sanitize_text_field((string) $r->get_param('q')));
        $ki = [];

        if (mb_strlen($q) < 2) {
            return new WP_REST_Response(['lista' => []], 200);
        }

        $l = '%' . $wpdb->esc_like($q) . '%';

        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE azon LIKE %s OR email LIKE %s OR licenc_kulcs LIKE %s OR vevo LIKE %s ORDER BY id DESC LIMIT 8', $l, $l, $l, $l), ARRAY_A) as $s) {
            $x    = self::rendeles_sor($s);
            $ki[] = ['tipus' => 'rendeles', 'id' => $x['id'], 'cim' => $x['azon'] . ' · ' . ($x['vevo'] !== '' ? $x['vevo'] : $x['email']), 'al' => $x['email']];
        }

        foreach (get_posts(['post_type' => 'page', 'post_status' => ['publish', 'draft', 'private'], 's' => $q, 'posts_per_page' => 6]) as $p) {
            $ki[] = ['tipus' => 'oldal', 'id' => (int) $p->ID, 'cim' => (string) $p->post_title, 'al' => '/' . get_page_uri($p)];
        }

        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, email, forras FROM ' . SDH_Sales_Schema::t('lead') . ' WHERE email LIKE %s ORDER BY id DESC LIMIT 5', $l), ARRAY_A) as $s) {
            $ki[] = ['tipus' => 'lead', 'id' => (int) $s['id'], 'cim' => (string) $s['email'], 'al' => (string) $s['forras']];
        }

        return new WP_REST_Response(['lista' => $ki], 200);
    }

    public static function r_tema(WP_REST_Request $r): WP_REST_Response
    {
        $t = (string) $r->get_param('tema');

        update_user_meta(get_current_user_id(), self::META_TEMA, in_array($t, ['sotet', 'vilagos'], true) ? $t : '');

        return new WP_REST_Response(['ok' => true], 200);
    }
}
