<?php
/**
 * Licenckezelés a Portál adminjában – a licencközpont beágyazása és a Licencek / Telepítések / Kiadások panelek.
 *
 * Beágyazás (egy telepítés):
 * - A Portál csomagja hozza a licencközpontot (`licenc/kozpont.php`, semleges fájlnevekkel – eszkozok/csomagol.sh).
 *   A fejlesztői tárolóban a `kozpont/sdh-kozpont` mappát tölti be.
 * - Ha a különálló Központ-bővítmény aktív, az fut (a betöltés `plugins_loaded` -100-on dől el, amikor már minden
 *   bővítmény fájlja betöltődött), különben a beágyazott – azonos táblák és beállítások, így a kettő között
 *   adatvesztés nélkül lehet váltani.
 * - Beágyazva nincs külön felső menü: a licencek itt kezelhetők, a teljes központ-felület rejtett haladó oldal.
 *
 * REST (`portal/v1`, csak admin): `admin/licenc/allapot`, `admin/licencek`, `admin/licenc/{id}` (GET részletek,
 * POST módosítás), `admin/licenc` (POST új), `admin/licenc/{id}/muvelet`, `admin/licenc/vevok`, `admin/licenc/peldanyok`,
 * `admin/licenc/peldany/{id}` (+ `/muvelet`, `/levalaszt`), `admin/licenc/kiadasok`, `admin/licenc/kiadas` (+ `/{id}`),
 * `admin/licenc/naplo`. Minden módosítás a licencközpont naplójába kerül (és a Portál naplójába is).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Licenc
{
    /** A listák alap lapmérete. */
    public const LAP = 25;

    /** Ennyi napon belül lejáró licenc számít „hamarosan lejár”-nak. */
    public const LEJAR_NAP = 30;

    public static function init(): void
    {
        add_action('plugins_loaded', [self::class, 'kozpont_betolt'], -100);
        add_filter('sdh_sales_admin_panelek', [self::class, 'panelek'], 10, 2);
        add_filter('sdh_kozpont_felulet_url', [self::class, 'felulet_url']);
        add_filter('sdh_kozpont_eszkoz_url', [self::class, 'eszkoz_url']);

        $olv = ['licenc_olv', 240, 60];
        $ir  = ['licenc_ir', 120, 60];

        SDH_Sales_Rest::ut('/admin/licenc/allapot', 'GET', [self::class, 'r_allapot'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/licencek', 'GET', [self::class, 'r_lista'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/licenc/(?P<id>\d+)', 'GET', [self::class, 'r_egy'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/licenc', 'POST', [self::class, 'r_uj'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/licenc/(?P<id>\d+)', 'POST', [self::class, 'r_ment'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/licenc/(?P<id>\d+)/muvelet', 'POST', [self::class, 'r_muvelet'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/licenc/vevok', 'GET', [self::class, 'r_vevok'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/licenc/peldanyok', 'GET', [self::class, 'r_peldanyok'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/licenc/peldany/(?P<id>\d+)', 'GET', [self::class, 'r_peldany'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/licenc/peldany/(?P<id>\d+)', 'POST', [self::class, 'r_peldany_ment'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/licenc/peldany/(?P<id>\d+)/muvelet', 'POST', [self::class, 'r_peldany_muvelet'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/licenc/peldany/(?P<id>\d+)/levalaszt', 'POST', [self::class, 'r_levalaszt'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/licenc/kiadasok', 'GET', [self::class, 'r_kiadasok'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/licenc/kiadas', 'POST', [self::class, 'r_kiadas_feltolt'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/licenc/kiadas/(?P<id>\d+)', 'POST', [self::class, 'r_kiadas_ment'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/licenc/naplo', 'GET', [self::class, 'r_naplo'], 'admin', $olv);
    }

    /* =================================================================
     * Beágyazás
     * ============================================================== */

    /** A beágyazott licencközpont fő fájlja ('' = nincs). */
    public static function beagyazott_fajl(): string
    {
        $csomag = SDH_SALES_DIR . 'licenc/kozpont.php';

        if (is_file($csomag)) {
            return $csomag;
        }

        // Fejlesztői tároló: weboldal/sales/sdh-sales → kozpont/sdh-kozpont.
        $tarolo = dirname(SDH_SALES_DIR, 3) . '/kozpont/sdh-kozpont/sdh-kozpont.php';

        return is_file($tarolo) ? $tarolo : '';
    }

    /** A beágyazott licencközpont fájljainak webcíme (a haladó felület stílusához / szkriptjéhez). */
    public static function beagyazott_url(): string
    {
        if (is_file(SDH_SALES_DIR . 'licenc/kozpont.php')) {
            return SDH_SALES_URL . 'licenc/';
        }

        // Fejlesztői tesztoldal: a tároló a bővítmények közé szimlinkelve.
        return is_dir(WP_PLUGIN_DIR . '/sdh-kozpont') ? trailingslashit(WP_PLUGIN_URL) . 'sdh-kozpont/' : SDH_SALES_URL;
    }

    /** `plugins_loaded` -100: ha a különálló Központ nem fut, a beágyazottat tölti be. */
    public static function kozpont_betolt(): void
    {
        if (defined('SDH_KOZPONT_VERSION') || class_exists('SDH_Kozpont', false)) {
            return;
        }

        if (!apply_filters('sdh_sales_licenc_beagyaz', true)) {
            return;
        }

        $f = self::beagyazott_fajl();

        if ($f === '') {
            return;
        }

        if (!defined('SDH_KOZPONT_BEAGYAZVA')) {
            define('SDH_KOZPONT_BEAGYAZVA', true);
        }

        if (!defined('SDH_KOZPONT_URL')) {
            define('SDH_KOZPONT_URL', self::beagyazott_url());
        }

        require_once $f;
    }

    /** Fut-e licencközpont ezen a webhelyen (különálló vagy beágyazott). */
    public static function kozpont_van(): bool
    {
        return class_exists('SDH_Kozpont') && class_exists('SDH_Kozpont_Kiszolgalas') && class_exists('SDH_Kozpont_Api');
    }

    public static function beagyazott(): bool
    {
        return self::kozpont_van() && method_exists('SDH_Kozpont', 'beagyazott') && SDH_Kozpont::beagyazott();
    }

    /** Itt kezelhetők-e a licencek: helyi mód + futó licencközpont. */
    public static function kezelheto(): bool
    {
        return self::kozpont_van() && (!class_exists('SDH_Sales_Kozpont_Kapcsolat') || SDH_Sales_Kozpont_Kapcsolat::mod() === 'helyi');
    }

    /** A licencközpont riasztó leveleiben is a Portál Licencek panelje a link. */
    public static function felulet_url($url)
    {
        return self::beagyazott() ? admin_url('admin.php?page=' . SDH_Sales_Admin::OLDAL . '#/licencek') : $url;
    }

    /** A haladó felület fájljai az útvonal-álnéven (ha a rejtés álnevei élnek). */
    public static function eszkoz_url($url)
    {
        return self::alias((string) $url);
    }

    private static function alias(string $url): string
    {
        if (class_exists('SDH_Sales_Rejtes') && method_exists('SDH_Sales_Rejtes', 'alias_aktiv') && SDH_Sales_Rejtes::be('aktiv') && SDH_Sales_Rejtes::alias_aktiv()) {
            return SDH_Sales_Rejtes::alias_csere($url);
        }

        return $url;
    }

    /* =================================================================
     * Panelek
     * ============================================================== */

    /**
     * @param array<string, array<string, mixed>> $p
     * @param array<int, string>                   $utak
     * @return array<string, array<string, mixed>>
     */
    public static function panelek($p, $utak = []): array
    {
        $p = is_array($p) ? $p : [];

        if (!self::kozpont_van()) {
            return $p;
        }

        $fajl = SDH_SALES_DIR . 'assets/admin-licenc.js';
        $js   = self::alias(SDH_SALES_URL . 'assets/admin-licenc.js') . '?ver=' . rawurlencode(SDH_SALES_VERSION . '.' . (string) @filemtime($fajl)); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        // A szkript egyszer töltődik (a többi panel ugyanebből rajzol).
        $p['licencek']    = ['cim' => 'Licencek', 'ikon' => 'kulcs', 'csoport' => 'Licencközpont', 'sorrend' => 52, 'js' => $js];
        $p['telepitesek'] = ['cim' => 'Telepítések', 'ikon' => 'kozpont', 'csoport' => 'Licencközpont', 'sorrend' => 54];
        $p['kiadasok']    = ['cim' => 'Kiadások', 'ikon' => 'feltolt', 'csoport' => 'Licencközpont', 'sorrend' => 56];

        return $p;
    }

    /* =================================================================
     * Segédek
     * ============================================================== */

    private static function hiba(string $uzenet, int $status = 400): WP_Error
    {
        return SDH_Sales_Rest::hiba($uzenet, $status);
    }

    /** null = rendben, különben a hiba (távoli mód / nincs licencközpont). */
    private static function ellenoriz(): ?WP_Error
    {
        if (!self::kozpont_van()) {
            return self::hiba('Ezen a webhelyen nem fut licencközpont.', 409);
        }

        if (!self::kezelheto()) {
            return self::hiba('A licenceket a távoli licencközpont kezeli (Beállítások › Licencközpont).', 409);
        }

        return null;
    }

    private static function ki(): string
    {
        $u = wp_get_current_user();

        return (string) ($u->display_name !== '' ? $u->display_name : 'admin');
    }

    private static function ma(): string
    {
        return wp_date('Y-m-d');
    }

    /** Helyi idő (a licencközpont `current_time('mysql')`-t ír) → Unix-idő. */
    private static function ts(?string $helyi): int
    {
        return $helyi === null || $helyi === '' ? 0 : SDH_Kozpont::ido_ts($helyi);
    }

    /** UTC MySQL-idő (a letöltő linkek így tárolnak) → Unix-idő. */
    private static function ts_utc(?string $utc): int
    {
        if ($utc === null || $utc === '' || strpos($utc, '0000') === 0) {
            return 0;
        }

        $t = strtotime($utc . ' UTC');

        return $t === false ? 0 : $t;
    }

    private static function datum_ok(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    /** @return object|null */
    private static function licenc_sor(int $id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('licenc') . ' WHERE id = %d', $id)) ?: null;
    }

    /** @return object|null */
    private static function vevo_sor(int $id)
    {
        global $wpdb;

        return $id > 0 ? ($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('vevo') . ' WHERE id = %d', $id)) ?: null) : null;
    }

    /** A licenc állapotkódja a szűrőkhöz: tiltva / lejart / lejar (30 napon belül) / aktiv. */
    public static function allapot_kod(object $l, ?string $ma = null): string
    {
        $ma = $ma ?? self::ma();

        if ((string) $l->allapot === 'tiltva') {
            return 'tiltva';
        }

        if (!empty($l->lejar) && (string) $l->lejar < $ma) {
            return 'lejart';
        }

        if (!empty($l->lejar) && (string) $l->lejar <= wp_date('Y-m-d', (int) strtotime($ma . ' +' . self::LEJAR_NAP . ' days'))) {
            return 'lejar';
        }

        return 'aktiv';
    }

    private static function nap_hatra(object $l, string $ma): ?int
    {
        return !empty($l->lejar) ? (int) floor((strtotime((string) $l->lejar) - strtotime($ma)) / DAY_IN_SECONDS) : null;
    }

    /** @return array<string, array{cim: string, csoport: string}> a modulok (a Műhely katalógusa + a webhely modulnevei) */
    public static function katalogus(): array
    {
        $ki = SDH_Kozpont::katalogus();

        if (class_exists('SDH_Sales_Katalogus')) {
            foreach (SDH_Sales_Katalogus::modulok() as $k => $m) {
                $k = sanitize_key((string) $k);

                if ($k === '') {
                    continue;
                }

                $ki[$k] = ['cim' => (string) ($m['nev'] ?? ($ki[$k]['cim'] ?? $k)), 'csoport' => (string) ($ki[$k]['csoport'] ?? ($m['csoport'] ?? 'Egyéb'))];
            }
        }

        return $ki;
    }

    /** @return array<int, array<string, mixed>> a webhely csomagjai (csomagváltáshoz) */
    public static function csomagok(): array
    {
        $ki = [];

        if (class_exists('SDH_Sales_Katalogus')) {
            foreach (SDH_Sales_Katalogus::csomagok() as $k => $c) {
                $mod = SDH_Sales_Katalogus::csomag_modulok((string) $k);
                $ki[] = [
                    'kulcs'           => (string) $k,
                    'nev'             => (string) ($c['nev'] ?? $k),
                    'modulok'         => in_array('*', $mod, true) ? '*' : array_values($mod),
                    'max_peldany'     => max(1, (int) ($c['max_peldany'] ?? $c['telephely_alap'] ?? 1)),
                    'max_felhasznalo' => max(0, (int) ($c['felhasznalo_alap'] ?? 0)),
                ];
            }
        }

        if ($ki === []) {
            foreach ((array) SDH_Kozpont::beallitas()['csomagok'] as $c) {
                $ki[] = [
                    'kulcs'           => (string) ($c['kulcs'] ?? ''),
                    'nev'             => (string) ($c['nev'] ?? ''),
                    'modulok'         => $c['modulok'] ?? '*',
                    'max_peldany'     => (int) ($c['max_peldany'] ?? 1),
                    'max_felhasznalo' => (int) ($c['max_felhasznalo'] ?? 0),
                ];
            }
        }

        return $ki;
    }

    /**
     * Egy licenc a listához / részletekhez.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function licenc_ki(object $l, ?object $v = null, array $extra = []): array
    {
        $ma = self::ma();

        return [
            'id'              => (int) $l->id,
            'kulcs'           => (string) $l->kulcs,
            'vevo_id'         => (int) $l->vevo_id,
            'vevo'            => (string) ($v->nev ?? $l->vevo),
            'vevo_email'      => (string) ($v->email ?? ''),
            'vevo_adoszam'    => (string) ($v->adoszam ?? ''),
            'kapcsolattarto'  => (string) ($v->kapcsolattarto ?? ''),
            'vevo_azonosito'  => (string) $l->vevo_azonosito,
            'csomag'          => (string) $l->csomag,
            'modulok'         => SDH_Kozpont::licenc_modulok($l),
            'jogok'           => SDH_Kozpont::jogok($l),
            'lejar'           => (string) ($l->lejar ?? ''),
            'lejar_nap'       => self::nap_hatra($l, $ma),
            'max_peldany'     => (int) $l->max_peldany,
            'max_felhasznalo' => (int) $l->max_felhasznalo,
            'proba'           => (int) $l->proba,
            'dij'             => (int) $l->dij,
            'ciklus'          => (string) $l->ciklus,
            'esedekes'        => (string) ($l->esedekes ?? ''),
            'csatorna'        => (string) $l->csatorna,
            'allapot'         => (string) $l->allapot,
            'kod'             => self::allapot_kod($l, $ma),
            'uzenet'          => (string) $l->uzenet,
            'megjegyzes'      => (string) ($l->megjegyzes ?? ''),
            'letrehozva'      => self::ts((string) $l->letrehozva),
            'modositva'       => self::ts((string) ($l->modositva ?? '')),
        ] + $extra;
    }

    /** Teljes adatkészlet a meglévő licencből (a licencközpont `licenc_ment` mindent felülír). @return array<string, mixed> */
    private static function licenc_be(object $l): array
    {
        $mod = SDH_Kozpont::licenc_modulok($l);

        return [
            'id'              => (int) $l->id,
            'vevo_id'         => (int) $l->vevo_id,
            'vevo_azonosito'  => (string) $l->vevo_azonosito,
            'csomag'          => (string) $l->csomag,
            'modulok'         => $mod,
            'jogok'           => SDH_Kozpont::jogok($l),
            'lejar'           => (string) ($l->lejar ?? ''),
            'max_peldany'     => (int) $l->max_peldany,
            'max_felhasznalo' => (int) $l->max_felhasznalo,
            'proba'           => (int) $l->proba,
            'dij'             => (int) $l->dij,
            'ciklus'          => (string) $l->ciklus,
            'esedekes'        => (string) ($l->esedekes ?? ''),
            'csatorna'        => (string) $l->csatorna,
            'allapot'         => (string) $l->allapot,
            'uzenet'          => (string) $l->uzenet,
            'megjegyzes'      => (string) ($l->megjegyzes ?? ''),
        ];
    }

    private static function naplo(string $szoveg, string $szint, int $licenc_id): void
    {
        if (class_exists('SDH_Sales_Naplo')) {
            SDH_Sales_Naplo::ir('licenc', $szoveg, $szint, 'licenc', $licenc_id);
        }
    }

    /** @return array<string, int> */
    private static function szamok(array $ls, string $ma): array
    {
        $sz = ['mind' => 0, 'aktiv' => 0, 'lejar' => 0, 'lejart' => 0, 'tiltva' => 0, 'proba' => 0];

        foreach ($ls as $l) {
            $sz['mind']++;
            $k = self::allapot_kod($l, $ma);
            $sz[$k]++;

            if ($k === 'lejar') {
                $sz['aktiv']++; // a hamarosan lejáró is aktív
            }

            if ((int) $l->proba === 1) {
                $sz['proba']++;
            }
        }

        return $sz;
    }

    /* =================================================================
     * REST – állapot, lista, részletek
     * ============================================================== */

    public static function r_allapot(): WP_REST_Response
    {
        $van = self::kozpont_van();
        $mod = class_exists('SDH_Sales_Kozpont_Kapcsolat') ? SDH_Sales_Kozpont_Kapcsolat::mod() : 'helyi';
        $ki  = [
            'van'         => $van,
            'mod'         => $mod,
            'kezelheto'   => self::kezelheto(),
            'beagyazott'  => self::beagyazott(),
            'verzio'      => defined('SDH_KOZPONT_VERSION') ? (string) SDH_KOZPONT_VERSION : '',
            'kozpont_cim' => class_exists('SDH_Sales_Kozpont_Kapcsolat') ? SDH_Sales_Kozpont_Kapcsolat::kozpont_cim() : home_url('/'),
            'halado_url'  => $van && current_user_can('manage_options') && class_exists('SDH_Kozpont_App') ? SDH_Kozpont_App::url() : '',
        ];

        if (self::kezelheto()) {
            global $wpdb;

            $ls = (array) $wpdb->get_results('SELECT * FROM ' . SDH_Kozpont::t('licenc'));
            $ki['szamok']    = self::szamok($ls, self::ma());
            $ki['katalogus'] = self::katalogus();
            $ki['csomagok']  = self::csomagok();
            $ki['jogok']     = SDH_Kozpont::JOGOK;
            $ki['muveletek'] = SDH_Kozpont::MUVELETEK;
            $ki['kiadas']    = SDH_Kozpont::kiadas('stabil')['verzio'];
            $ki['lejar_nap'] = self::LEJAR_NAP;
        }

        return rest_ensure_response($ki);
    }

    /** @return WP_REST_Response|WP_Error */
    public static function r_lista(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        global $wpdb;

        $ma     = self::ma();
        $q      = trim((string) $r->get_param('q'));
        $szuro  = sanitize_key((string) $r->get_param('szuro'));
        $rend   = sanitize_key((string) $r->get_param('rend'));
        $irany  = (string) $r->get_param('irany') === 'csokken' ? 'csokken' : 'novekvo';
        $db     = max(5, min(100, (int) ($r->get_param('db') ?: self::LAP)));
        $oldal  = max(1, (int) $r->get_param('oldal'));
        $vevo   = (int) $r->get_param('vevo_id');

        $ls = (array) $wpdb->get_results('SELECT * FROM ' . SDH_Kozpont::t('licenc') . ' ORDER BY id DESC');
        $vk = [];

        foreach ((array) $wpdb->get_results('SELECT * FROM ' . SDH_Kozpont::t('vevo')) as $v) {
            $vk[(int) $v->id] = $v;
        }

        $pd = [];
        $pk = [];

        foreach ((array) $wpdb->get_results('SELECT licenc_id, cimke, peldany, allapot, utolso, utolso_jel, verzio FROM ' . SDH_Kozpont::t('peldany')) as $p) {
            $lid = (int) $p->licenc_id;
            $pd[$lid] = ($pd[$lid] ?? 0) + 1;
            $pk[$lid] = ($pk[$lid] ?? '') . ' ' . (string) $p->cimke . ' ' . (string) $p->verzio . ' ' . mb_substr((string) $p->allapot, 0, 4000);
            $u = self::ts((string) ($p->utolso_jel ?: $p->utolso));
            $pk['u' . $lid] = max((int) ($pk['u' . $lid] ?? 0), $u);
        }

        $szamok = self::szamok($ls, $ma);
        $tu     = mb_strtolower($q);
        $tszam  = preg_replace('/\D+/', '', $q);
        $ki     = [];

        foreach ($ls as $l) {
            $v = $vk[(int) $l->vevo_id] ?? null;

            if ($vevo > 0 && (int) $l->vevo_id !== $vevo) {
                continue;
            }

            $kod = self::allapot_kod($l, $ma);

            if ($szuro !== '' && $szuro !== 'mind') {
                $jo = match ($szuro) {
                    'aktiv'  => in_array($kod, ['aktiv', 'lejar'], true),
                    'lejar'  => $kod === 'lejar',
                    'lejart' => $kod === 'lejart',
                    'tiltva' => $kod === 'tiltva',
                    'proba'  => (int) $l->proba === 1,
                    default  => true,
                };

                if (!$jo) {
                    continue;
                }
            }

            if ($q !== '') {
                $szoveg = mb_strtolower(implode(' ', [
                    (string) $l->kulcs, (string) $l->vevo, (string) $l->vevo_azonosito, (string) $l->csomag, (string) ($l->megjegyzes ?? ''),
                    (string) ($v->nev ?? ''), (string) ($v->email ?? ''), (string) ($v->kapcsolattarto ?? ''), (string) ($v->adoszam ?? ''),
                    (string) ($v->weboldal ?? ''), (string) ($v->telefon ?? ''), (string) ($pk[(int) $l->id] ?? ''),
                ]));
                $talal = mb_strpos($szoveg, $tu) !== false;

                // Adószám kötőjelek nélkül is (12345678-2-42 = 12345678242).
                if (!$talal && strlen((string) $tszam) >= 6) {
                    $talal = strpos((string) preg_replace('/\D+/', '', (string) ($v->adoszam ?? '')), (string) $tszam) !== false;
                }

                if (!$talal) {
                    continue;
                }
            }

            $ki[] = ['l' => $l, 'v' => $v, 'kod' => $kod];
        }

        $kulcs = static function (array $x) use ($rend, $pk) {
            $l = $x['l'];

            return match ($rend) {
                'vevo'       => mb_strtolower((string) ($x['v']->nev ?? $l->vevo)),
                'kulcs'      => (string) $l->kulcs,
                'letrehozva' => (string) $l->letrehozva,
                'peldany'    => (int) ($pk['u' . (int) $l->id] ?? 0),
                default      => empty($l->lejar) ? '9999-12-31' : (string) $l->lejar,
            };
        };

        if ($rend !== '' && $rend !== 'alap') {
            usort($ki, static function ($a, $b) use ($kulcs, $irany): int {
                $c = $kulcs($a) <=> $kulcs($b);

                return $irany === 'csokken' ? -$c : $c;
            });
        }

        $ossz  = count($ki);
        $oldal = min($oldal, max(1, (int) ceil($ossz / $db)));
        $lap   = array_slice($ki, ($oldal - 1) * $db, $db);

        return rest_ensure_response([
            'lista'  => array_map(static fn (array $x): array => self::licenc_ki($x['l'], $x['v'], [
                'peldany_db' => (int) ($pd[(int) $x['l']->id] ?? 0),
                'utolso_jel' => (int) ($pk['u' . (int) $x['l']->id] ?? 0),
            ]), $lap),
            'ossz'   => $ossz,
            'oldal'  => $oldal,
            'db'     => $db,
            'szamok' => $szamok,
        ]);
    }

    /** @return WP_REST_Response|WP_Error */
    public static function r_egy(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        $l = self::licenc_sor((int) $r['id']);

        if ($l === null) {
            return self::hiba('Ez a licenc nincs meg.', 404);
        }

        return rest_ensure_response(self::reszletek($l));
    }

    /** @return array<string, mixed> */
    private static function reszletek(object $l): array
    {
        global $wpdb;

        $id = (int) $l->id;
        $v  = self::vevo_sor((int) $l->vevo_id);

        // Telepítések
        $ps = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('peldany') . ' WHERE licenc_id = %d ORDER BY utolso_jel DESC, utolso DESC', $id));
        $peldanyok = array_map(static fn ($p): array => self::peldany_ki($p, $l), $ps);

        // Letöltő linkek (UTC-ben tárolva)
        $most = gmdate('Y-m-d H:i:s');
        $lt   = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('letoltes') . ' WHERE licenc_id = %d ORDER BY id DESC LIMIT 30', $id));
        $letoltesek = array_map(static fn ($t): array => [
            'id'         => (int) $t->id,
            'rendeles'   => (string) $t->rendeles,
            'db'         => (int) $t->db,
            'max_db'     => (int) $t->max_db,
            'lejar'      => self::ts_utc((string) $t->lejar),
            'letrehozva' => self::ts_utc((string) $t->letrehozva),
            'utolso'     => self::ts_utc((string) ($t->utolso ?? '')),
            'el'         => (string) $t->lejar > $most && (int) $t->db < (int) $t->max_db,
        ], $lt);

        // A webhely rendelései (licenckulcs vagy licencközpont-azonosító szerint) + a licencközpont rendelésnaplója
        $rendelesek = [];

        if (class_exists('SDH_Sales_Schema')) {
            $rs = (array) $wpdb->get_results($wpdb->prepare(
                'SELECT id, azon, tipus, allapot, brutto, netto, letrehozva, fizetve_ido, szamla_dijbekero, szamla_vegszamla FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE licenc_kulcs = %s OR kozpont_licenc_id = %d ORDER BY id DESC LIMIT 50',
                (string) $l->kulcs,
                $id
            ));

            foreach ($rs as $x) {
                $rendelesek[] = [
                    'id' => (int) $x->id, 'azon' => (string) $x->azon, 'tipus' => (string) $x->tipus, 'allapot' => (string) $x->allapot,
                    'brutto' => (int) $x->brutto, 'netto' => (int) $x->netto, 'letrehozva' => self::ts_utc((string) $x->letrehozva),
                    'fizetve' => self::ts_utc((string) ($x->fizetve_ido ?? '')), 'dijbekero' => (string) $x->szamla_dijbekero, 'vegszamla' => (string) $x->szamla_vegszamla,
                ];
            }
        }

        $kr = (array) $wpdb->get_results($wpdb->prepare('SELECT rendeles, muvelet, ido FROM ' . SDH_Kozpont::t('rendeles') . ' WHERE licenc_id = %d ORDER BY id DESC LIMIT 50', $id));
        $kozpont_rendelesek = array_map(static fn ($x): array => ['rendeles' => (string) $x->rendeles, 'muvelet' => (string) $x->muvelet, 'ido' => self::ts((string) $x->ido)], $kr);

        // Napló (a licencközpont eseményei ehhez a licenchez)
        $es = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('esemeny') . ' WHERE licenc_id = %d ORDER BY id DESC LIMIT 60', $id));
        $esemenyek = array_map(static fn ($e): array => ['id' => (int) $e->id, 'ido' => self::ts((string) $e->ido), 'tipus' => (string) $e->tipus, 'szint' => (string) $e->szint, 'szoveg' => (string) $e->szoveg, 'ki' => (string) $e->ki], $es);

        $pd = count($ps);
        $uj = 0;

        foreach ($peldanyok as $p) {
            $uj = max($uj, (int) $p['utolso']);
        }

        return [
            'licenc'             => self::licenc_ki($l, $v, ['peldany_db' => $pd, 'utolso_jel' => $uj]),
            'vevo'               => $v !== null ? [
                'id' => (int) $v->id, 'nev' => (string) $v->nev, 'kapcsolattarto' => (string) $v->kapcsolattarto, 'email' => (string) $v->email,
                'telefon' => (string) $v->telefon, 'cim' => (string) $v->cim, 'adoszam' => (string) $v->adoszam, 'weboldal' => (string) $v->weboldal,
                'allapot' => (string) $v->allapot,
            ] : null,
            'peldanyok'          => $peldanyok,
            'letoltesek'         => $letoltesek,
            'rendelesek'         => $rendelesek,
            'kozpont_rendelesek' => $kozpont_rendelesek,
            'esemenyek'          => $esemenyek,
            'kiadas'             => SDH_Kozpont::kiadas_licenchez($l)['verzio'],
        ];
    }

    /** @return array<string, mixed> */
    private static function peldany_ki(object $p, ?object $l = null): array
    {
        $pr = json_decode((string) ($p->problema ?? ''), true);
        $pr = is_array($pr) ? $pr : [];
        $st = SDH_Kozpont::statusz($p);

        if ($st === 'offline') {
            array_unshift($pr, ['kod' => 'offline', 'szint' => 'hiba', 'szoveg' => 'Nem jelentkezik.']);
        }

        $s = json_decode((string) ($p->allapot ?? ''), true);
        $s = is_array($s) ? $s : [];
        $webhely = '';

        foreach ([['rendszer', 'url'], ['rendszer', 'webhely'], ['webhely'], ['url'], ['rendszer', 'home']] as $ut) {
            $x = $s;

            foreach ($ut as $k) {
                $x = is_array($x) && isset($x[$k]) ? $x[$k] : null;
            }

            if (is_string($x) && $x !== '') {
                $webhely = $x;
                break;
            }
        }

        return [
            'id'         => (int) $p->id,
            'nev'        => SDH_Kozpont::peldany_nev($p, $l),
            'cimke'      => (string) $p->cimke,
            'peldany'    => (string) $p->peldany,
            'licenc_id'  => (int) $p->licenc_id,
            'kulcs'      => (string) ($p->kulcs ?? ($l->kulcs ?? '')),
            'vevo'       => (string) ($p->vevo ?? ($l->vevo ?? '')),
            'verzio'     => (string) $p->verzio,
            'statusz'    => $st,
            'egeszseg'   => (string) $p->egeszseg,
            'problemak'  => $pr,
            'utolso'     => self::ts((string) ($p->utolso_jel ?: $p->utolso)),
            'elso'       => self::ts((string) $p->elso),
            'valasz_ms'  => (int) $p->valasz_ms,
            'php'        => (string) ($s['rendszer']['php'] ?? ''),
            'webhely'    => esc_url_raw($webhely),
            'tavoli'     => (int) $p->tavoli,
            'mod'        => empty($p->utolso_jel) ? 'napi' : 'elo',
        ];
    }

    /* =================================================================
     * REST – létrehozás, módosítás
     * ============================================================== */

    /**
     * Új licenc. Vevő: `vevo_id`, vagy új vevő (`vevo` objektum: nev, email, kapcsolattarto, telefon, adoszam, cim).
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function r_uj(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        $be   = (array) $r->get_json_params();
        $vid  = (int) ($be['vevo_id'] ?? 0);
        $adat = self::adat_tisztit($be); // előbb a licenc adatai: hibánál új vevő sem jön létre

        if (is_string($adat)) {
            return self::hiba($adat);
        }

        if ($vid > 0 && self::vevo_sor($vid) === null) {
            return self::hiba('Ez a vevő nincs meg.', 404);
        }

        if ($vid <= 0) {
            $uj = is_array($be['vevo'] ?? null) ? (array) $be['vevo'] : [];

            if (trim((string) ($uj['nev'] ?? '')) === '') {
                return self::hiba('Válassz vevőt, vagy add meg az új vevő nevét.');
            }

            if (($uj['email'] ?? '') !== '' && !is_email((string) $uj['email'])) {
                return self::hiba('Az e-mail-cím nem érvényes.');
            }

            $v = SDH_Kozpont::vevo_ment($uj, self::ki());

            if (!is_int($v)) {
                return self::hiba((string) $v);
            }

            $vid = $v;
        }

        $e = SDH_Kozpont::licenc_ment(['vevo_id' => $vid] + $adat, self::ki());

        if (!is_int($e)) {
            return self::hiba((string) $e);
        }

        $l = self::licenc_sor($e);
        self::naplo('Új licenc kézzel: ' . (string) $l->kulcs . ' (' . (string) $l->vevo . ').', 'info', $e);
        $uzenet = 'A licenc elkészült: ' . (string) $l->kulcs . '.';

        if (!empty($be['kuld'])) {
            $k = self::kulcs_kuld($l, '', !empty($be['link']));
            $uzenet .= ' ' . $k['uzenet'];
        }

        return rest_ensure_response(['ok' => true, 'uzenet' => $uzenet] + self::reszletek($l));
    }

    /**
     * Licenc módosítása – csak a megadott mezők változnak.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function r_ment(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        $l = self::licenc_sor((int) $r['id']);

        if ($l === null) {
            return self::hiba('Ez a licenc nincs meg.', 404);
        }

        $be   = (array) $r->get_json_params();
        $adat = self::adat_tisztit($be, true);

        if (is_string($adat)) {
            return self::hiba($adat);
        }

        $e = SDH_Kozpont::licenc_ment($adat + self::licenc_be($l), self::ki());

        if (!is_int($e)) {
            return self::hiba((string) $e);
        }

        $uj = self::licenc_sor($e);
        self::naplo('Licenc módosítva: ' . (string) $uj->kulcs . '.', 'info', $e);

        return rest_ensure_response(['ok' => true, 'uzenet' => 'Mentve.'] + self::reszletek($uj));
    }

    /**
     * A beérkező licencmezők ellenőrzése. $reszleges: csak a megadott kulcsok (módosítás).
     *
     * @param array<string, mixed> $be
     * @return array<string, mixed>|string
     */
    private static function adat_tisztit(array $be, bool $reszleges = false)
    {
        $ki = [];
        $kat = self::katalogus();

        if (!$reszleges || array_key_exists('csomag', $be)) {
            $ki['csomag'] = mb_substr(sanitize_text_field((string) ($be['csomag'] ?? '')), 0, 60);
        }

        if (!$reszleges || array_key_exists('modulok', $be)) {
            $m = $be['modulok'] ?? '*';

            if ($m !== '*' && !(is_array($m) && in_array('*', $m, true))) {
                $m = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $m))));
                $ismeretlen = array_diff($m, array_keys($kat));

                if ($ismeretlen !== []) {
                    return 'Ismeretlen modul: ' . implode(', ', $ismeretlen) . '.';
                }
            } else {
                $m = '*';
            }

            $ki['modulok'] = $m;
        }

        foreach (['lejar', 'esedekes'] as $k) {
            if (!$reszleges || array_key_exists($k, $be)) {
                $d = trim((string) ($be[$k] ?? ''));

                if ($d !== '' && !self::datum_ok($d)) {
                    return 'Érvénytelen dátum (' . ($k === 'lejar' ? 'lejárat' : 'esedékesség') . ').';
                }

                $ki[$k] = $d;
            }
        }

        if (!$reszleges || array_key_exists('max_peldany', $be)) {
            $n = (int) ($be['max_peldany'] ?? 1);

            if ($n < 1 || $n > 99) {
                return 'A telepítések száma 1 és 99 között lehet.';
            }

            $ki['max_peldany'] = $n;
        }

        if (!$reszleges || array_key_exists('max_felhasznalo', $be)) {
            $n = (int) ($be['max_felhasznalo'] ?? 0);

            if ($n < 0 || $n > 999) {
                return 'A felhasználószám 0 (korlátlan) és 999 között lehet.';
            }

            $ki['max_felhasznalo'] = $n;
        }

        if (!$reszleges || array_key_exists('dij', $be)) {
            $ki['dij'] = max(0, min(100000000, (int) ($be['dij'] ?? 0)));
        }

        if (!$reszleges || array_key_exists('ciklus', $be)) {
            $c = (string) ($be['ciklus'] ?? 'eves');

            if (!in_array($c, ['havi', 'eves', 'egyszeri'], true)) {
                return 'Ismeretlen számlázási ciklus.';
            }

            $ki['ciklus'] = $c;
        }

        if (!$reszleges || array_key_exists('csatorna', $be)) {
            $ki['csatorna'] = (string) ($be['csatorna'] ?? '') === 'teszt' ? 'teszt' : 'stabil';
        }

        if (!$reszleges || array_key_exists('proba', $be)) {
            $ki['proba'] = (int) !empty($be['proba']);
        }

        if (!$reszleges || array_key_exists('jogok', $be)) {
            $j = is_array($be['jogok'] ?? null) ? (array) $be['jogok'] : [];
            $ki['jogok'] = [];

            foreach (array_keys(SDH_Kozpont::JOGOK) as $k) {
                $ki['jogok'][$k] = array_key_exists($k, $j) ? (int) !empty($j[$k]) : 1;
            }
        }

        foreach (['uzenet' => 255, 'vevo_azonosito' => 60] as $k => $max) {
            if (!$reszleges || array_key_exists($k, $be)) {
                $ki[$k] = mb_substr(sanitize_text_field((string) ($be[$k] ?? '')), 0, $max);
            }
        }

        if (!$reszleges || array_key_exists('megjegyzes', $be)) {
            $ki['megjegyzes'] = sanitize_textarea_field((string) ($be['megjegyzes'] ?? ''));
        }

        // Az állapot a módosításban nem állítható (felfüggesztés / visszaállítás: külön művelet, naplózva).
        return $ki;
    }

    /* =================================================================
     * REST – műveletek
     * ============================================================== */

    /** @return WP_REST_Response|WP_Error */
    public static function r_muvelet(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        $id = (int) $r['id'];
        $l  = self::licenc_sor($id);

        if ($l === null) {
            return self::hiba('Ez a licenc nincs meg.', 404);
        }

        $be = (array) $r->get_json_params();
        $m  = sanitize_key((string) ($be['muvelet'] ?? ''));
        $e  = self::muvelet($l, $m, $be);

        if (is_string($e)) {
            return self::hiba($e);
        }

        $friss = self::licenc_sor($id);

        return rest_ensure_response(['ok' => true] + $e + self::reszletek($friss));
    }

    /**
     * Egy licencművelet. Siker: ['uzenet' => …, (link)], hiba: üzenet.
     *
     * @param array<string, mixed> $be
     * @return array<string, mixed>|string
     */
    public static function muvelet(object $l, string $m, array $be = [])
    {
        global $wpdb;

        $id  = (int) $l->id;
        $ki  = self::ki();
        $ids = ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => $id];

        switch ($m) {
            case 'hosszabbit':
                $ho  = (int) ($be['ho'] ?? 0);
                $dat = trim((string) ($be['lejar'] ?? ''));

                if ($dat === '' && $ho <= 0) {
                    // Egy számlázási ciklussal (a licencközpont szabálya: havi +1 hó, különben +12 hó).
                    $e = SDH_Kozpont::licenc_muvelet($id, 'megujit', $ki);

                    if ($e !== '') {
                        return $e;
                    }

                    $uj = self::licenc_sor($id);
                    self::naplo('Licenc meghosszabbítva ' . (string) $uj->lejar . '-ig (' . (string) $l->kulcs . ').', 'info', $id);

                    return ['uzenet' => 'Meghosszabbítva: ' . (string) $uj->lejar . '-ig.'];
                }

                if ($dat !== '') {
                    if (!self::datum_ok($dat)) {
                        return 'Érvénytelen dátum.';
                    }

                    $uj = $dat;
                } else {
                    if ($ho > 120) {
                        return 'Legfeljebb 120 hónappal hosszabbítható egyszerre.';
                    }

                    $alap = !empty($l->lejar) && (string) $l->lejar > self::ma() ? (string) $l->lejar : self::ma();
                    $uj   = wp_date('Y-m-d', (int) strtotime($alap . ' +' . $ho . ' month'));
                }

                $wpdb->update(SDH_Kozpont::t('licenc'), ['lejar' => $uj, 'esedekes' => $uj, 'modositva' => SDH_Kozpont::most()], ['id' => $id]);
                SDH_Kozpont::naplo('licenc', 'Licenc lejárata: ' . ((string) ($l->lejar ?? '') ?: 'nincs') . ' → ' . $uj . ' (' . (string) $l->kulcs . ').', 'info', $ids, $ki);
                self::naplo('Licenc lejárata ' . $uj . ' (' . (string) $l->kulcs . ').', 'info', $id);

                return ['uzenet' => 'Az új lejárat: ' . $uj . '.'];

            case 'lejarat_nelkul':
                $wpdb->update(SDH_Kozpont::t('licenc'), ['lejar' => null, 'esedekes' => null, 'modositva' => SDH_Kozpont::most()], ['id' => $id]);
                SDH_Kozpont::naplo('licenc', 'Licenc lejárat nélkül (' . (string) $l->kulcs . ').', 'info', $ids, $ki);
                self::naplo('Licenc lejárat nélkül (' . (string) $l->kulcs . ').', 'info', $id);

                return ['uzenet' => 'A licencnek nincs lejárata.'];

            case 'tilt':
            case 'enged':
                $e = SDH_Kozpont::licenc_muvelet($id, $m, $ki);

                if ($e !== '') {
                    return $e;
                }

                self::naplo('Licenc ' . ($m === 'tilt' ? 'felfüggesztve' : 'visszaállítva') . ' (' . (string) $l->kulcs . ').', $m === 'tilt' ? 'figyel' : 'info', $id);

                return ['uzenet' => $m === 'tilt' ? 'Felfüggesztve: a rendszer a következő jelentkezéskor megkapja.' : 'Visszaállítva.'];

            case 'ujkulcs':
                $e = SDH_Kozpont::licenc_muvelet($id, 'ujkulcs', $ki);

                if ($e !== '') {
                    return $e;
                }

                $uj = self::licenc_sor($id);

                // A webhely rendelésein is az új kulcs áll (a vevő fiókja ezt mutatja).
                if (class_exists('SDH_Sales_Schema')) {
                    $wpdb->query($wpdb->prepare('UPDATE ' . SDH_Sales_Schema::t('rendeles') . ' SET licenc_kulcs = %s WHERE licenc_kulcs = %s OR kozpont_licenc_id = %d', (string) $uj->kulcs, (string) $l->kulcs, $id));
                }

                self::naplo('Új licenckulcs: ' . (string) $l->kulcs . ' → ' . (string) $uj->kulcs . '.', 'figyel', $id);

                return ['uzenet' => 'Új kulcs: ' . (string) $uj->kulcs . '. A vevőnél is cserélni kell (küldd el neki).'];

            case 'modul_be':
            case 'modul_ki':
                $mod = sanitize_key((string) ($be['modul'] ?? ''));
                $kat = self::katalogus();

                if ($mod === '' || !isset($kat[$mod])) {
                    return 'Ismeretlen modul.';
                }

                $most = SDH_Kozpont::licenc_modulok($l);

                if ($m === 'modul_be') {
                    if ($most === '*' || in_array($mod, (array) $most, true)) {
                        return ['uzenet' => 'Ez a modul már benne van.'];
                    }

                    $uj = array_values(array_merge((array) $most, [$mod]));
                } else {
                    $lista = $most === '*' ? array_keys($kat) : (array) $most;

                    if (!in_array($mod, $lista, true)) {
                        return ['uzenet' => 'Ez a modul nincs benne.'];
                    }

                    $uj = array_values(array_diff($lista, [$mod]));
                }

                $e = SDH_Kozpont::licenc_ment(['modulok' => $uj] + self::licenc_be($l), $ki);

                if (!is_int($e)) {
                    return (string) $e;
                }

                $nev = (string) $kat[$mod]['cim'];
                self::naplo('Modul ' . ($m === 'modul_be' ? 'hozzáadva' : 'elvéve') . ': ' . $nev . ' (' . (string) $l->kulcs . ').', 'info', $id);

                return ['uzenet' => ($m === 'modul_be' ? 'Hozzáadva: ' : 'Elvéve: ') . $nev . '.'];

            case 'csomag':
                $kulcs = sanitize_key((string) ($be['csomag'] ?? ''));
                $cs    = null;

                foreach (self::csomagok() as $c) {
                    if ($c['kulcs'] === $kulcs) {
                        $cs = $c;
                    }
                }

                if ($cs === null) {
                    return 'Ismeretlen csomag.';
                }

                $adat = ['csomag' => (string) $cs['nev']];

                if (!array_key_exists('modulok_is', $be) || !empty($be['modulok_is'])) {
                    $adat['modulok']         = $cs['modulok'];
                    $adat['max_peldany']     = (int) $cs['max_peldany'];
                    $adat['max_felhasznalo'] = (int) $cs['max_felhasznalo'];
                }

                $e = SDH_Kozpont::licenc_ment($adat + self::licenc_be($l), $ki);

                if (!is_int($e)) {
                    return (string) $e;
                }

                self::naplo('Csomagváltás: ' . ((string) $l->csomag ?: '–') . ' → ' . (string) $cs['nev'] . ' (' . (string) $l->kulcs . ').', 'info', $id);

                return ['uzenet' => 'A csomag: ' . (string) $cs['nev'] . (isset($adat['modulok']) ? ' (a modulok és a keretek is).' : '.')];

            case 'kuld':
                $cim = trim((string) ($be['email'] ?? ''));

                if ($cim !== '' && !is_email($cim)) {
                    return 'Az e-mail-cím nem érvényes.';
                }

                $k = self::kulcs_kuld($l, $cim, !empty($be['link']));

                return $k['ok'] ? ['uzenet' => $k['uzenet']] : $k['uzenet'];

            case 'letoltes_link':
                if (!empty($be['regiek_le']) || !array_key_exists('regiek_le', $be)) {
                    $wpdb->query($wpdb->prepare('UPDATE ' . SDH_Kozpont::t('letoltes') . ' SET lejar = %s WHERE licenc_id = %d AND lejar > %s', gmdate('Y-m-d H:i:s', time() - 1), $id, gmdate('Y-m-d H:i:s')));
                }

                $url = SDH_Kozpont_Kiszolgalas::letoltes_link($id, 'kezi');
                SDH_Kozpont::naplo('letoltes', 'Új letöltő link kézzel (' . (string) $l->kulcs . ').', 'info', $ids, $ki);
                self::naplo('Új letöltő link (' . (string) $l->kulcs . ').', 'info', $id);
                $van = SDH_Kozpont::kiadas_licenchez($l)['verzio'] !== '';

                return ['uzenet' => 'Új letöltő link készült (14 napig, 3 letöltésre).' . ($van ? '' : ' Figyelem: még nincs feltöltött kiadás.'), 'link' => $url];
        }

        return 'Ismeretlen művelet.';
    }

    /**
     * A licenckulcs (és opcionálisan egy új letöltő link) elküldése a vevőnek.
     *
     * @return array{ok: bool, uzenet: string}
     */
    public static function kulcs_kuld(object $l, string $cim = '', bool $link = false): array
    {
        $v   = self::vevo_sor((int) $l->vevo_id);
        $cim = $cim !== '' ? $cim : (string) ($v->email ?? '');

        if (!is_email($cim)) {
            return ['ok' => false, 'uzenet' => 'A vevőnek nincs érvényes e-mail-címe – add meg a címzettet.'];
        }

        if (!class_exists('SDH_Sales_Email')) {
            return ['ok' => false, 'uzenet' => 'A levélküldés nem érhető el.'];
        }

        $url = $link ? SDH_Kozpont_Kiszolgalas::letoltes_link((int) $l->id, 'kezi') : '';
        $kat = self::katalogus();
        $mod = SDH_Kozpont::licenc_modulok($l);

        $adat = [
            'nev'           => (string) (($v->kapcsolattarto ?? '') !== '' ? $v->kapcsolattarto : ($v->nev ?? $l->vevo)),
            'vevo_ceg'      => (string) ($v->nev ?? $l->vevo),
            'licenc_kulcs'  => (string) $l->kulcs,
            'kozpont_url'   => class_exists('SDH_Sales_Kozpont_Kapcsolat') ? SDH_Sales_Kozpont_Kapcsolat::kozpont_cim() : home_url('/'),
            'csomag'        => (string) $l->csomag,
            'modulok'       => $mod === '*' ? 'minden modul' : implode(', ', array_map(static fn ($k) => (string) ($kat[$k]['cim'] ?? $k), (array) $mod)),
            'lejar'         => !empty($l->lejar) ? str_replace('-', '.', (string) $l->lejar) . '.' : '',
            'letoltes_url'  => $url,
            'fiok_url'      => home_url('/fiok/'),
        ];

        $ok = SDH_Sales_Email::kuld('licenc_kulcs', $cim, $adat);

        SDH_Kozpont::naplo('licenc', 'Licenckulcs elküldve' . ($url !== '' ? ' letöltő linkkel' : '') . ': ' . SDH_Sales_Email::maszk($cim) . ($ok ? '' : ' – SIKERTELEN') . ' (' . (string) $l->kulcs . ').', $ok ? 'info' : 'figyel', ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => (int) $l->id], self::ki());
        self::naplo('Licenckulcs ' . ($ok ? 'elküldve' : 'NEM ment ki') . ': ' . SDH_Sales_Email::maszk($cim) . '.', $ok ? 'info' : 'figyel', (int) $l->id);

        return $ok
            ? ['ok' => true, 'uzenet' => 'Elküldve: ' . SDH_Sales_Email::maszk($cim) . ($url !== '' ? ' (új letöltő linkkel).' : '.')]
            : ['ok' => false, 'uzenet' => 'A levél nem ment ki – nézd meg az e-mail-beállításokat és a naplót.'];
    }

    /* =================================================================
     * REST – vevők (választóhoz)
     * ============================================================== */

    /** @return WP_REST_Response|WP_Error */
    public static function r_vevok(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        global $wpdb;

        $q  = trim((string) $r->get_param('q'));
        $vk = (array) $wpdb->get_results('SELECT * FROM ' . SDH_Kozpont::t('vevo') . ' ORDER BY nev');
        $tu = mb_strtolower($q);
        $ki = [];

        foreach ($vk as $v) {
            if ($q !== '' && mb_strpos(mb_strtolower((string) $v->nev . ' ' . (string) $v->email . ' ' . (string) $v->adoszam . ' ' . (string) $v->kapcsolattarto), $tu) === false) {
                continue;
            }

            $ki[] = ['id' => (int) $v->id, 'nev' => (string) $v->nev, 'email' => (string) $v->email, 'adoszam' => (string) $v->adoszam, 'kapcsolattarto' => (string) $v->kapcsolattarto];

            if (count($ki) >= 30) {
                break;
            }
        }

        return rest_ensure_response(['lista' => $ki]);
    }

    /* =================================================================
     * REST – telepítések
     * ============================================================== */

    /** @return WP_REST_Response|WP_Error */
    public static function r_peldanyok(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        global $wpdb;

        $q     = mb_strtolower(trim((string) $r->get_param('q')));
        $szuro = sanitize_key((string) $r->get_param('szuro'));
        $db    = max(5, min(100, (int) ($r->get_param('db') ?: self::LAP)));
        $oldal = max(1, (int) $r->get_param('oldal'));
        $lid   = (int) $r->get_param('licenc_id');

        $ps = (array) $wpdb->get_results('SELECT p.*, l.vevo, l.vevo_id, l.kulcs FROM ' . SDH_Kozpont::t('peldany') . ' p LEFT JOIN ' . SDH_Kozpont::t('licenc') . ' l ON l.id = p.licenc_id ORDER BY p.utolso_jel DESC, p.utolso DESC');
        $legujabb = SDH_Kozpont::kiadas('stabil')['verzio'];
        $sz = ['mind' => 0, 'online' => 0, 'offline' => 0, 'hiba' => 0, 'regi' => 0];
        $ki = [];

        foreach ($ps as $p) {
            $x = self::peldany_ki($p);
            $sz['mind']++;
            $on   = !in_array($x['statusz'], ['offline', 'uj'], true); // a még soha nem jelentkezett egyik sem
            $hib  = $x['egeszseg'] === 'hiba' || $x['statusz'] === 'offline';
            $regi = $legujabb !== '' && $x['verzio'] !== '' && version_compare($x['verzio'], $legujabb, '<');
            $sz['online']  += $on ? 1 : 0;
            $sz['offline'] += $x['statusz'] === 'offline' ? 1 : 0;
            $sz['hiba'] += $hib ? 1 : 0;
            $sz['regi'] += $regi ? 1 : 0;

            if ($lid > 0 && $x['licenc_id'] !== $lid) {
                continue;
            }

            if ($szuro === 'online' && !$on || $szuro === 'offline' && $x['statusz'] !== 'offline' || $szuro === 'hiba' && !$hib || $szuro === 'regi' && !$regi) {
                continue;
            }

            if ($q !== '' && mb_strpos(mb_strtolower($x['nev'] . ' ' . $x['vevo'] . ' ' . $x['kulcs'] . ' ' . $x['verzio'] . ' ' . $x['webhely'] . ' ' . $x['peldany'] . ' ' . mb_substr((string) $p->allapot, 0, 4000)), $q) === false) {
                continue;
            }

            $x['regi'] = $regi;
            $ki[] = $x;
        }

        $ossz  = count($ki);
        $oldal = min($oldal, max(1, (int) ceil($ossz / $db)));

        return rest_ensure_response(['lista' => array_slice($ki, ($oldal - 1) * $db, $db), 'ossz' => $ossz, 'oldal' => $oldal, 'db' => $db, 'szamok' => $sz, 'legujabb' => $legujabb]);
    }

    /** A licencközpont saját végpontjának hívása (azonos logika, egy helyen). @return WP_REST_Response|WP_Error */
    private static function kozpont_hiv(string $fv, array $url = [], array $json = [], array $fajlok = [], array $param = [])
    {
        $k = new WP_REST_Request('POST', '/');
        $k->set_url_params($url);

        if ($json !== []) {
            $k->set_header('Content-Type', 'application/json');
            $k->set_body((string) wp_json_encode($json));
        }

        if ($fajlok !== []) {
            $k->set_file_params($fajlok);
        }

        foreach ($param as $a => $b) {
            $k->set_param($a, $b);
        }

        return call_user_func(['SDH_Kozpont_Api', $fv], $k);
    }

    /** @return WP_REST_Response|WP_Error */
    public static function r_peldany(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        return $h ?? self::kozpont_hiv('peldany', ['id' => (int) $r['id']]);
    }

    /** Címke / jegyzet. @return WP_REST_Response|WP_Error */
    public static function r_peldany_ment(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        $be = array_intersect_key((array) $r->get_json_params(), ['cimke' => 1, 'jegyzet' => 1, 'elo' => 1]);

        return self::kozpont_hiv('peldany_ment', ['id' => (int) $r['id']], $be);
    }

    /** Távoli művelet kérése (diagnosztika, mentés, frissítés…). @return WP_REST_Response|WP_Error */
    public static function r_peldany_muvelet(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        $be = (array) $r->get_json_params();

        if (!isset(SDH_Kozpont::MUVELETEK[sanitize_key((string) ($be['tipus'] ?? ''))])) {
            return self::hiba('Ismeretlen művelet.');
        }

        $v = self::kozpont_hiv('peldany_muvelet', ['id' => (int) $r['id']], ['tipus' => (string) $be['tipus'], 'parameter' => is_array($be['parameter'] ?? null) ? $be['parameter'] : []]);

        if (!is_wp_error($v)) {
            self::naplo('Távoli művelet kérve: ' . SDH_Kozpont::MUVELETEK[sanitize_key((string) $be['tipus'])] . ' (telepítés #' . (int) $r['id'] . ').', 'info', 0);
        }

        return $v;
    }

    /** Telepítés leválasztása (helyet szabadít fel a licencen). @return WP_REST_Response|WP_Error */
    public static function r_levalaszt(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        $v = self::kozpont_hiv('peldany_elfelejt', ['id' => (int) $r['id']]);

        if (!is_wp_error($v)) {
            self::naplo('Telepítés leválasztva (#' . (int) $r['id'] . ').', 'figyel', 0);
        }

        return $v;
    }

    /* =================================================================
     * REST – kiadások, napló
     * ============================================================== */

    /** @return WP_REST_Response|WP_Error */
    public static function r_kiadasok(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        return $h ?? self::kozpont_hiv('kiadasok');
    }

    /** Vevői zip feltöltése (multipart: zip, csatorna, megjegyzes). @return WP_REST_Response|WP_Error */
    public static function r_kiadas_feltolt(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        $v = self::kozpont_hiv('kiadas_feltolt', [], [], (array) $r->get_file_params(), [
            'csatorna'   => (string) $r->get_param('csatorna') === 'teszt' ? 'teszt' : 'stabil',
            'megjegyzes' => (string) $r->get_param('megjegyzes'),
        ]);

        if (!is_wp_error($v)) {
            self::naplo('Új kiadás feltöltve (' . ((string) $r->get_param('csatorna') === 'teszt' ? 'teszt' : 'stabil') . ' csatorna).', 'info', 0);
        }

        return $v;
    }

    /** Csatorna / megjegyzés / törlés. @return WP_REST_Response|WP_Error */
    public static function r_kiadas_ment(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        $be = array_intersect_key((array) $r->get_json_params(), ['csatorna' => 1, 'megjegyzes' => 1, 'torol' => 1]);
        $v  = self::kozpont_hiv('kiadas_ment', ['id' => (int) $r['id']], $be);

        if (!is_wp_error($v)) {
            self::naplo('Kiadás #' . (int) $r['id'] . (!empty($be['torol']) ? ' törölve.' : ' módosítva.'), !empty($be['torol']) ? 'figyel' : 'info', 0);
        }

        return $v;
    }

    /** A licencközpont naplója (szűrhető: licenc_id, vevo_id, peldany_id, tipus, szint, q, elotte). @return WP_REST_Response|WP_Error */
    public static function r_naplo(WP_REST_Request $r)
    {
        $h = self::ellenoriz();

        if ($h !== null) {
            return $h;
        }

        global $wpdb;

        $w    = ['1=1'];
        $args = [];

        foreach (['licenc_id', 'vevo_id', 'peldany_id'] as $k) {
            if ((int) $r->get_param($k) > 0) {
                $w[]    = $k . ' = %d';
                $args[] = (int) $r->get_param($k);
            }
        }

        foreach (['tipus', 'szint'] as $k) {
            $v = sanitize_key((string) $r->get_param($k));

            if ($v !== '') {
                $w[]    = $k . ' = %s';
                $args[] = $v;
            }
        }

        $q = trim((string) $r->get_param('q'));

        if ($q !== '') {
            $w[]    = 'szoveg LIKE %s';
            $args[] = '%' . $wpdb->esc_like($q) . '%';
        }

        if ((int) $r->get_param('elotte') > 0) {
            $w[]    = 'id < %d';
            $args[] = (int) $r->get_param('elotte');
        }

        $sql = 'SELECT * FROM ' . SDH_Kozpont::t('esemeny') . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 60';
        $es  = (array) $wpdb->get_results($args !== [] ? $wpdb->prepare($sql, $args) : $sql);

        return rest_ensure_response(['lista' => array_map(static fn ($e): array => [
            'id' => (int) $e->id, 'ido' => self::ts((string) $e->ido), 'tipus' => (string) $e->tipus, 'szint' => (string) $e->szint,
            'szoveg' => (string) $e->szoveg, 'ki' => (string) $e->ki, 'licenc_id' => (int) $e->licenc_id, 'peldany_id' => (int) $e->peldany_id,
        ], $es)]);
    }
}
