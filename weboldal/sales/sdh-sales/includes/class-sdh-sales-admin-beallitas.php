<?php
/**
 * SDH Értékesítés – admin: Beállítások (csoportos űrlapok, titkok), Arculat, Export / Import.
 *
 * Beállítás-csoportok: `csoportok()` → [kulcs => {cim, ikon, leiras, mezok: [{k, t, c, s?, o?, min?, max?, fel?}]}].
 * Csak a csoportokban felsorolt kulcsok menthetők (fehérlista). Mezőfajták: a SDH_Sales_Szekcio fajtái + `email`,
 * `nyelvek` (a támogatott nyelvek közül), `titok` (SDH_Sales::titkos_ment – a válaszban csak „beállítva” igen/nem).
 * Bővítés: `sdh_sales_admin_beallitas_csoportok` szűrő (új csoport, vagy egy meglévő kulcsú csoport cseréje / bővítése).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Admin_Beallitas
{
    /** Az adminban választható nyelvek (a `nyelvek` beállítás ezekből; most csak a magyar él). */
    public const NYELVEK = ['hu' => 'Magyar', 'en' => 'English', 'de' => 'Deutsch'];

    public static function init(): void
    {
        $ir  = ['admin_ir', 240, 60];
        $olv = ['admin_olv', 900, 60];

        SDH_Sales_Rest::ut('/admin/beallitasok', 'GET', [self::class, 'r_beallitasok'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/beallitasok', 'POST', [self::class, 'r_ment'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/titok', 'POST', [self::class, 'r_titok'], 'admin', ['admin_titok', 30, 60]);
        SDH_Sales_Rest::ut('/admin/arculat', 'GET', [self::class, 'r_arculat'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/arculat', 'POST', [self::class, 'r_arculat_ment'], 'admin', $ir);
        SDH_Sales_Rest::ut('/admin/arculat/elonezet', 'POST', [self::class, 'r_arculat_elonezet'], 'admin', ['admin_elonezet', 120, 60]);
        SDH_Sales_Rest::ut('/admin/export', 'GET', [self::class, 'r_export'], 'admin', ['admin_export', 30, 60]);
        SDH_Sales_Rest::ut('/admin/import/elonezet', 'POST', [self::class, 'r_import_elonezet'], 'admin', ['admin_import', 30, 60]);
        SDH_Sales_Rest::ut('/admin/import', 'POST', [self::class, 'r_import'], 'admin', ['admin_import', 30, 60]);
        SDH_Sales_Rest::ut('/admin/mentesek', 'GET', [self::class, 'r_mentesek'], 'admin', $olv);
        SDH_Sales_Rest::ut('/admin/mentes', 'GET', [self::class, 'r_mentes'], 'admin', ['admin_export', 30, 60]);

        add_action('template_redirect', [self::class, 'arculat_elonezet_indit'], 0);
    }

    /* =================================================================
     * Csoportok
     * ============================================================== */

    /** @return array<string, array<string, mixed>> */
    public static function csoportok(): array
    {
        $f = static fn (string $k, string $t, string $c, array $x = []): array => ['k' => $k, 't' => $t, 'c' => $c] + $x;

        $cs = [
            'altalanos' => ['Általános', 'beallitas', 'A termék neve mindenhol ebből jön ({termeknev}).', [
                $f('termeknev', 'szoveg', 'Terméknév', ['s' => 'Egy mező – az egész webhelyen átnevezi a terméket.', 'max' => 60, 'fel' => true, 'kotelezo' => true]),
                $f('termeknev_rovid', 'szoveg', 'Rövid név', ['max' => 30, 'fel' => true]),
                $f('szlogen', 'szoveg', 'Szlogen', ['max' => 160]),
                $f('nyelvek', 'nyelvek', 'Nyelvek', ['s' => 'Most csak a magyar tartalom készült el.']),
                $f('demo.url', 'url', 'Demó webhely címe', ['fel' => true]),
            ]],
            'halozat' => ['Hálózat és proxy', 'pajzs', 'Honnan jön a látogató valódi IP-címe (korlátok, naplók, fizetési jelzések szűrése). A továbbított fejlécet csak megbízható küldőtől fogadjuk el.', [
                $f('cloudflare', 'kapcsolo', 'Cloudflare mögött fut (valódi IP a CF-fejlécből, csak Cloudflare-címről)', ['fel' => true, 'alap' => true]),
                $f('proxy.cloudflare_ip', 'hosszu', 'Cloudflare címtartományai (felülírás)', ['max' => 4000, 's' => 'CIDR-lista, soronként vagy vesszővel (pl. 173.245.48.0/20). Üresen a beépített, közzétett lista él.']),
                $f('proxy.megbizhato', 'hosszu', 'Saját megbízható proxyk', ['max' => 4000, 's' => 'CIDR-lista (pl. 10.0.0.0/8). Csak ezektől fogadjuk el az X-Forwarded-For fejlécet; üresen senkitől.']),
            ]],
            'ceg' => ['Cégadatok', 'ceg', 'Számlán, leveleken, jogi oldalakon és a láblécben jelenik meg.', [
                $f('ceg.nev', 'szoveg', 'Cégnév', ['max' => 160, 'fel' => true, 'kotelezo' => true]),
                $f('ceg.adoszam', 'szoveg', 'Adószám', ['max' => 20, 'fel' => true]),
                $f('ceg.cim', 'szoveg', 'Székhely', ['max' => 200]),
                $f('ceg.email', 'email', 'E-mail', ['fel' => true]),
                $f('ceg.telefon', 'szoveg', 'Telefon', ['max' => 40, 'fel' => true]),
                $f('ceg.cegjegyzek', 'szoveg', 'Cégjegyzékszám', ['max' => 40, 'fel' => true]),
                $f('ceg.kepviselo', 'szoveg', 'Képviselő', ['max' => 120, 'fel' => true]),
                $f('ceg.bankszamla', 'szoveg', 'Bankszámlaszám', ['max' => 40, 'fel' => true]),
                $f('ceg.iban', 'szoveg', 'IBAN', ['max' => 40, 'fel' => true]),
                $f('ceg.bic', 'szoveg', 'BIC / SWIFT', ['max' => 20, 'fel' => true]),
            ]],
            'penzugy' => ['Pénzügy és fizetés', 'penz', 'Áfa, pénznem, kedvezmény, fizetési módok.', [
                $f('afa', 'szam', 'Áfa (%)', ['min' => 0, 'max' => 100, 'fel' => true]),
                $f('penznem', 'valaszto', 'Pénznem', ['o' => ['HUF' => 'Forint (HUF)', 'EUR' => 'Euró (EUR)'], 'fel' => true]),
                $f('eves_kedvezmeny', 'szam', 'Éves fizetés kedvezménye (%)', ['min' => 0, 'max' => 90, 'fel' => true]),
                $f('fizetes.dijbekero_nap', 'szam', 'Díjbekérő fizetési határideje (nap)', ['min' => 1, 'max' => 60, 'fel' => true]),
                $f('fizetes.atutalas', 'kapcsolo', 'Banki átutalás (díjbekérő + QR)', ['fel' => true]),
                $f('fizetes.automatizalas', 'valaszto', 'Fizetés párosítása', ['o' => ['import' => 'Bankkivonat-import', 'szamlazz' => 'Számlázz.hu jelzés', 'kartya' => 'Csak kártyás kapu', 'kezi' => 'Kézi jelölés'], 'fel' => true]),
                $f('kupon.foglalas_ora', 'szam', 'Kupon foglalása fizetetlen rendelésnél (óra)', ['min' => 1, 'max' => 720, 'alap' => 24, 'fel' => true, 's' => 'Ennyi ideig számít bele a kupon keretébe egy még ki nem fizetett rendelés.']),
            ]],
            'barion' => ['Barion', 'kartya', 'Kártyás fizetés Barionnal. Éles kulcsot csak a tulajdonos ad meg.', [
                $f('fizetes.barion.aktiv', 'kapcsolo', 'Barion bekapcsolva', ['fel' => true]),
                $f('fizetes.barion.teszt', 'kapcsolo', 'Tesztkörnyezet (sandbox)', ['fel' => true]),
                $f('fizetes.barion.payee', 'email', 'Barion-fiók e-mail címe (Payee)', ['fel' => true]),
                $f('barion_poskey', 'titok', 'POSKey', ['fel' => true]),
            ]],
            'simplepay' => ['SimplePay', 'kartya', 'Kártyás fizetés SimplePay-jel.', [
                $f('fizetes.simplepay.aktiv', 'kapcsolo', 'SimplePay bekapcsolva', ['fel' => true]),
                $f('fizetes.simplepay.teszt', 'kapcsolo', 'Tesztkörnyezet (sandbox)', ['fel' => true]),
                $f('fizetes.simplepay.merchant', 'szoveg', 'Kereskedői azonosító (MERCHANT)', ['max' => 40, 'fel' => true]),
                $f('simplepay_secret', 'titok', 'Titkos kulcs (SECRET KEY)', ['fel' => true]),
            ]],
            'szamlazz' => ['Számlázz.hu', 'szamla', 'Díjbekérő és végszámla. Tesztüzemben nem keletkezik valódi számla.', [
                $f('szamlazz_agent_kulcs', 'titok', 'Számla Agent kulcs (éles)', ['fel' => true]),
                $f('szamlazz_teszt_kulcs', 'titok', 'Számla Agent kulcs (teszt)', ['fel' => true]),
                $f('szamlazz.teszt', 'kapcsolo', 'Tesztüzem', ['fel' => true]),
                $f('szamlazz.automatikus', 'kapcsolo', 'Sikeres fizetés után a végszámla magától megy', ['fel' => true]),
                $f('szamlazz.elotag', 'szoveg', 'Számlaszám-előtag', ['max' => 20, 'fel' => true]),
            ]],
            'kozpont' => ['Licencszerver (Központ)', 'kozpont', 'A licencek kiszolgálása. „Helyi”: a Központ ezen a webhelyen fut.', [
                $f('kozpont.mod', 'valaszto', 'Kapcsolat módja', ['o' => ['helyi' => 'Helyi (ugyanez a webhely)', 'tavoli' => 'Távoli (aláírt kérés)'], 'fel' => true]),
                $f('kozpont.url', 'url', 'A Központ címe (távoli módban)', ['fel' => true]),
                $f('kozpont.nyilvanos', 'hosszu', 'A Központ nyilvános kulcsa (első kapcsolódáskor rögzül)', ['max' => 200]),
            ]],
            'email' => ['E-mail', 'email', 'A kimenő levelek feladója és az értesítések címzettje.', [
                $f('email.felado', 'email', 'Feladó címe', ['fel' => true]),
                $f('email.ertesites', 'email', 'Értesítések címzettje', ['fel' => true]),
            ]],
            'szikra' => ['AI-asszisztens (Anthropic)', 'szikra', 'A webhely asszisztense. A kulcs titkosítva tárolódik.', [
                $f('szikra.aktiv', 'kapcsolo', 'Asszisztens bekapcsolva', ['fel' => true]),
                $f('szikra.modell', 'szoveg', 'Modell', ['max' => 80, 'fel' => true]),
                $f('szikra.napi_keret', 'szam', 'Napi keret (kérdés / látogató)', ['min' => 0, 'max' => 1000, 'fel' => true]),
                $f('szikra.havi_keret_usd', 'szam', 'Havi költségkeret (USD)', ['min' => 0, 'max' => 100000, 'fel' => true]),
                $f('szikra_kulcs', 'titok', 'Anthropic API-kulcs'),
            ]],
            'rejtes' => ['Rejtés', 'pajzs', 'A látogató semmiből nem tudhatja meg, mire épül a webhely. A belépési cím vagy az API-előtag módosítása után az új címet használd (a régi 404-et ad).', [
                $f('rejtes.aktiv', 'kapcsolo', 'Rejtés bekapcsolva (főkapcsoló)', ['fel' => true, 'alap' => true]),
                $f('rejtes.kimenet', 'kapcsolo', 'Kimeneti szűrő: a megmaradt nyomok cseréje (utolsó védvonal)', ['fel' => true, 'alap' => true]),
                $f('rejtes.alias', 'kapcsolo', 'Semleges fájlútvonalak (/static/, /media/, /lib/)', ['fel' => true, 'alap' => true, 's' => 'A webszerveren is kell szabály (üzemeltetési leírás).']),
                $f('rejtes.belepo', 'szoveg', 'Titkos belépési cím', ['max' => 60, 'fel' => true, 's' => 'Kisbetű, szám, kötőjel, legalább 8 karakter; üresen állandó, véletlen cím. Most: ' . (class_exists('SDH_Sales_Rejtes') ? home_url('/' . SDH_Sales_Rejtes::belepo_szelet() . '/') : '') . ' – jegyezd fel!']),
                $f('rejtes.api', 'szoveg', 'API-előtag', ['max' => 30, 'fel' => true, 'alap' => 'api', 's' => 'Az API címe: ' . home_url('/') . '<előtag>/…']),
            ]],
            'turnstile' => ['Turnstile (robotszűrő)', 'pajzs', 'A Cloudflare Turnstile védi az űrlapokat.', [
                $f('turnstile.aktiv', 'kapcsolo', 'Turnstile bekapcsolva', ['fel' => true]),
                $f('turnstile.oldalkulcs', 'szoveg', 'Oldalkulcs (site key)', ['max' => 80, 'fel' => true]),
                $f('turnstile_titok', 'titok', 'Titkos kulcs (secret key)'),
            ]],
        ];

        $ki = [];
        $n  = 0;

        foreach ($cs as $k => [$c, $ikon, $l, $mezok]) {
            $ki[$k] = ['cim' => $c, 'ikon' => $ikon, 'leiras' => $l, 'mezok' => $mezok, 'sorrend' => ($n += 10)];
        }

        // Kapcsolatpróbák és egyéb műveletek – csak ha a gazdamodul végpontja regisztrálva van.
        $ki['kozpont']['muveletek'] = [
            ['cim' => 'Kapcsolat próbája', 'ut' => '/admin/kozpont/proba'],
            ['cim' => 'A Központ kulcsának elfelejtése', 'ut' => '/admin/kozpont/kulcs_felejt', 'ketlepcsos' => 'Biztos? Újra rögzül a következő kapcsolatkor'],
            ['cim' => 'Új saját kulcspár', 'ut' => '/admin/kozpont/kulcspar_uj', 'ketlepcsos' => 'Biztos? A Központban is cserélni kell'],
        ];
        $ki['szamlazz']['muveletek'] = [['cim' => 'Kapcsolat próbája', 'ut' => '/admin/szamlazz/proba']];
        $ki['rejtes']['muveletek']   = [['cim' => 'Ellenőrzés most (kívülről nézve)', 'ut' => '/admin/rejtes/ellenoriz', 'lista' => true]];

        $ki  = (array) apply_filters('sdh_sales_admin_beallitas_csoportok', $ki);
        $out = [];

        foreach ($ki as $k => $c) {
            if (!is_array($c) || empty($c['mezok']) || !is_array($c['mezok']) || preg_match('/^[a-z0-9_-]{1,40}$/', (string) $k) !== 1) {
                continue;
            }

            $mezok = array_values(array_filter(array_map([self::class, 'mezo_norm'], $c['mezok'])));

            if ($mezok === []) {
                continue;
            }

            $out[(string) $k] = [
                'cim'     => (string) ($c['cim'] ?? $k),
                'ikon'    => sanitize_key((string) ($c['ikon'] ?? 'beallitas')),
                'leiras'  => (string) ($c['leiras'] ?? ''),
                'sorrend' => (int) ($c['sorrend'] ?? 500),
                'mezok'   => $mezok,
                'allapot' => is_array($c['allapot'] ?? null) ? SDH_Sales_Szekcio::altalanos($c['allapot']) : null,
                'muveletek' => self::muveletek((array) ($c['muveletek'] ?? [])),
            ];
        }

        uasort($out, static fn ($a, $b) => $a['sorrend'] <=> $b['sorrend']);

        return $out;
    }

    /**
     * Csoport-műveletek (gombok): [{cim, ut (/admin/…), ketlepcsos?}] – csak a regisztrált admin-végpontok maradnak.
     *
     * @param array<int, mixed> $m
     * @return array<int, array<string, mixed>>
     */
    private static function muveletek(array $m): array
    {
        $utak = SDH_Sales_Admin::utak();
        $ki   = [];

        foreach ($m as $x) {
            if (is_array($x) && !empty($x['cim']) && in_array((string) ($x['ut'] ?? ''), $utak, true)) {
                $ki[] = ['cim' => (string) $x['cim'], 'ut' => (string) $x['ut'], 'ketlepcsos' => (string) ($x['ketlepcsos'] ?? ''), 'lista' => !empty($x['lista'])];
            }
        }

        return $ki;
    }

    /**
     * Egy mezőleírás egységesítése: a rövid (`k, t, c, s`) és a hosszú (`kulcs, tipus, cim, sugo`) alak is elfogadott;
     * `szoveg_tobbsoros` → hosszu, `tizedes` → tizedes (lebegőpontos szám). A `link` + `url` csak megjelenítendő hivatkozás.
     *
     * @param mixed $m
     * @return array<string, mixed>|null
     */
    public static function mezo_norm($m): ?array
    {
        if (!is_array($m)) {
            return null;
        }

        $k = (string) ($m['k'] ?? $m['kulcs'] ?? '');
        $t = (string) ($m['t'] ?? $m['tipus'] ?? 'szoveg');

        if (preg_match('/^[a-z0-9_.]{1,80}$/', $k) !== 1) {
            return null;
        }

        $t = ['szoveg_tobbsoros' => 'hosszu', 'tobbsoros' => 'hosszu', 'textarea' => 'hosszu', 'bool' => 'kapcsolo', 'select' => 'valaszto', 'egesz' => 'szam'][$t] ?? $t;

        if ($t === 'link' && !empty($m['url'])) {
            $t = 'hivatkozas';
        }

        $ki = ['k' => $k, 't' => $t, 'c' => (string) ($m['c'] ?? $m['cim'] ?? $k)];

        foreach (['s' => ['s', 'sugo'], 'o' => ['o', 'opciok'], 'min' => ['min'], 'max' => ['max'], 'fel' => ['fel'], 'ful' => ['ful'], 'alap' => ['alap'], 'kotelezo' => ['kotelezo'], 'url' => ['url']] as $cel => $forras) {
            foreach ($forras as $f) {
                if (array_key_exists($f, $m)) {
                    $ki[$cel] = $m[$f];
                    break;
                }
            }
        }

        if ($t === 'hivatkozas') {
            $ki['url'] = esc_url_raw((string) $ki['url']);
        }

        return $ki;
    }

    /** @return array<string, array<string, mixed>> kulcs → mező (minden csoportból) */
    public static function mezok(): array
    {
        $ki = [];

        foreach (self::csoportok() as $cs) {
            foreach ($cs['mezok'] as $m) {
                if (is_array($m) && isset($m['k'], $m['t']) && preg_match('/^[a-z0-9_.]{1,80}$/', (string) $m['k']) === 1) {
                    $ki[(string) $m['k']] = $m;
                }
            }
        }

        return $ki;
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    public static function r_beallitasok(): WP_REST_Response
    {
        $e = [];
        $t = [];

        foreach (self::mezok() as $k => $m) {
            if ($m['t'] === 'titok') {
                $t[$k] = SDH_Sales::van_titok($k);
                continue;
            }

            if ($m['t'] === 'hivatkozas') {
                continue;
            }

            $e[$k] = SDH_Sales::b($k, $m['alap'] ?? ($m['t'] === 'kapcsolo' ? false : ''));
        }

        return new WP_REST_Response(['csoportok' => self::csoportok(), 'ertekek' => $e, 'titkok' => $t, 'nyelvek' => self::NYELVEK], 200);
    }

    /**
     * @param array<string, mixed> $m
     * @param mixed                $v
     * @return mixed
     */
    public static function tisztit(array $m, $v)
    {
        switch ((string) $m['t']) {
            case 'email':
                return sanitize_email(is_scalar($v) ? (string) $v : '');

            case 'nyelvek':
                $l = array_values(array_intersect(array_map('strval', is_array($v) ? $v : [$v]), array_keys(self::NYELVEK)));

                return $l === [] ? ['hu'] : $l;

            case 'tizedes':
                $n = is_numeric($v) ? round((float) $v, 4) : (float) ($m['alap'] ?? 0);

                if (isset($m['min'])) {
                    $n = max((float) $m['min'], $n);
                }

                if (isset($m['max'])) {
                    $n = min((float) $m['max'], $n);
                }

                return $n;

            case 'titok':
            case 'hivatkozas':
                return null;
        }

        return SDH_Sales_Szekcio::mezo_tisztit($m, $v);
    }

    public static function r_ment(WP_REST_Request $r)
    {
        $be    = (array) $r->get_param('ertekek');
        $mezok = self::mezok();
        $uj    = [];
        $hibak = [];

        foreach ($be as $k => $v) {
            $k = (string) $k;
            $m = $mezok[$k] ?? null;

            if ($m === null || $m['t'] === 'titok' || $m['t'] === 'hivatkozas') {
                continue;
            }

            $t = self::tisztit($m, $v);

            if (!empty($m['kotelezo']) && (is_string($t) && trim($t) === '')) {
                $hibak[] = '„' . $m['c'] . '” nem lehet üres.';
                continue;
            }

            if ($m['t'] === 'email' && is_scalar($v) && trim((string) $v) !== '' && $t === '') {
                $hibak[] = '„' . $m['c'] . '”: érvénytelen e-mail-cím.';
                continue;
            }

            $uj[$k] = $t;
        }

        $hibak = array_merge($hibak, self::rejtes_ellenoriz($uj));

        if ($hibak !== []) {
            return new WP_Error('ervenytelen', implode(' ', $hibak), ['status' => 422]);
        }

        $cimvaltas = false;

        foreach (['rejtes.belepo', 'rejtes.api'] as $k) {
            if (array_key_exists($k, $uj) && (string) $uj[$k] !== (string) SDH_Sales::b($k, $k === 'rejtes.api' ? 'api' : '')) {
                $cimvaltas = true;
            }
        }

        SDH_Sales::b_ment($uj);

        if ($cimvaltas) {
            // Az átírási szabályokat a Rejtés modul építi újra (sdh_sales_beallitas_mentve); itt csak jelezzük.
            do_action('sdh_sales_rejtes_valtozott');
        }

        return self::r_beallitasok();
    }

    /**
     * A Rejtés-címek ellenőrzése (a már tisztított értékeken).
     *
     * @param array<string, mixed> $uj
     * @return array<int, string>
     */
    public static function rejtes_ellenoriz(array $uj): array
    {
        $h       = [];
        $foglalt = ['wp-admin', 'wp-login', 'wp-json', 'wp-content', 'wp-includes', 'admin', 'login', 'belepes', 'api', 'feed', 'xmlrpc', 'kosar', 'penztar', 'fiok', 'blog'];

        if (array_key_exists('rejtes.belepo', $uj)) {
            $b = (string) $uj['rejtes.belepo'];

            if ($b !== '' && (preg_match('/^[a-z0-9][a-z0-9-]{7,59}$/', $b) !== 1 || in_array($b, $foglalt, true) || strpos($b, 'wp') === 0)) {
                $h[] = 'A titkos belépési cím legalább 8 karakter (kisbetű, szám, kötőjel), és nem lehet foglalt vagy „wp” kezdetű.';
            } elseif ($b !== '' && get_page_by_path($b)) {
                $h[] = 'Ilyen című oldal már van – válassz másik belépési címet.';
            }
        }

        if (array_key_exists('rejtes.api', $uj)) {
            $a = (string) $uj['rejtes.api'];

            if (preg_match('/^[a-z][a-z0-9-]{1,29}$/', $a) !== 1 || strpos($a, 'wp') === 0) {
                $h[] = 'Az API-előtag 2–30 karakter (kisbetűvel kezdődik; kisbetű, szám, kötőjel), és nem kezdődhet „wp”-vel.';
            } elseif (isset($uj['rejtes.belepo']) && $uj['rejtes.belepo'] === $a) {
                $h[] = 'Az API-előtag és a belépési cím nem lehet ugyanaz.';
            }
        }

        return $h;
    }

    /** Titok beállítása / törlése (`ertek` üres = törlés). A válasz soha nem tartalmazza az értéket. */
    public static function r_titok(WP_REST_Request $r)
    {
        $k = (string) $r->get_param('kulcs');
        $m = self::mezok()[$k] ?? null;

        if ($m === null || $m['t'] !== 'titok') {
            return SDH_Sales_Rest::hiba('Ismeretlen titok.', 400);
        }

        $v = trim((string) $r->get_param('ertek'));

        if (strlen($v) > 4000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v) === 1) {
            return SDH_Sales_Rest::hiba('Érvénytelen érték.', 422, 'ervenytelen');
        }

        SDH_Sales::titkos_ment($k, $v);

        return new WP_REST_Response(['ok' => true, 'kulcs' => $k, 'beallitva' => SDH_Sales::van_titok($k)], 200);
    }

    /* =================================================================
     * Arculat
     * ============================================================== */

    public static function r_arculat(): WP_REST_Response
    {
        return new WP_REST_Response(SDH_Sales_Arculat::allapot() + ['css' => SDH_Sales_Arculat::css()], 200);
    }

    public static function r_arculat_ment(WP_REST_Request $r)
    {
        $be = (array) $r->get_json_params();
        $e = SDH_Sales_Arculat::ment($be);

        if ($e['hibas'] !== []) {
            return new WP_Error('ervenytelen', 'Érvénytelen érték: ' . implode(', ', $e['hibas']) . '. A többi mentve.', ['status' => 422, 'hibas' => $e['hibas']]);
        }

        return self::r_arculat();
    }

    public static function r_arculat_elonezet(WP_REST_Request $r): WP_REST_Response
    {
        $terkep = SDH_Sales_Arculat::terkep((array) $r->get_json_params());
        $token  = strtolower(wp_generate_password(32, false, false));
        $mod    = (string) $r->get_param('mod');

        set_transient('sdh_sales_arculat_elo_' . $token, ['user' => get_current_user_id(), 'terkep' => $terkep, 'mod' => in_array($mod, ['sotet', 'vilagos'], true) ? $mod : ''], 15 * MINUTE_IN_SECONDS);

        return new WP_REST_Response(['url' => add_query_arg('arculat_elonezet', $token, home_url('/')), 'css' => SDH_Sales_Arculat::css($terkep)], 200);
    }

    public static function arculat_elonezet_indit(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a token a jogosítvány (+ bejelentkezett admin).
        $t = isset($_GET['arculat_elonezet']) ? preg_replace('/[^a-z0-9]/', '', strtolower((string) wp_unslash($_GET['arculat_elonezet']))) : '';

        if ($t === '' || strlen((string) $t) !== 32) {
            return;
        }

        $a = get_transient('sdh_sales_arculat_elo_' . $t);

        if (!is_array($a) || !SDH_Sales::admin() || (int) $a['user'] !== get_current_user_id()) {
            return;
        }

        SDH_Sales_Arculat::elonezet((array) $a['terkep']);

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();
        add_filter('show_admin_bar', '__return_false');

        if ($a['mod'] !== '') {
            $mod = (string) $a['mod'];
            add_filter('language_attributes', static fn (string $x): string => (string) preg_replace('/\sdata-tema="[^"]*"/', '', $x) . ' data-tema="' . esc_attr($mod) . '"', 30);
        }
    }

    /* =================================================================
     * Export / Import
     * ============================================================== */

    public static function r_export(): WP_REST_Response
    {
        $e = SDH_Sales_Exportimport::export();
        SDH_Sales_Naplo::ir('export', 'Export letöltve (' . count($e['tartalom']) . ' tartalomelem, ' . count($e['oldalak']) . ' oldal).', 'info');

        $v = new WP_REST_Response($e, 200);
        $v->header('Content-Disposition', 'attachment; filename="portal-export-' . gmdate('Ymd-His') . '.json"');

        return $v;
    }

    /** @return mixed a feltöltött csomag (JSON-törzs `csomag` mezője) */
    private static function csomag(WP_REST_Request $r)
    {
        $c = $r->get_param('csomag');

        if (is_string($c)) {
            if (strlen($c) > 20 * MB_IN_BYTES) {
                return null;
            }

            $c = json_decode($c, true);
        }

        return $c;
    }

    public static function r_import_elonezet(WP_REST_Request $r): WP_REST_Response
    {
        return new WP_REST_Response(SDH_Sales_Exportimport::elonezet(self::csomag($r)), 200);
    }

    public static function r_import(WP_REST_Request $r)
    {
        $e = SDH_Sales_Exportimport::import(self::csomag($r));

        if (empty($e['ok'])) {
            return new WP_Error('import_hiba', implode(' ', (array) $e['hibak']), ['status' => 422]);
        }

        return new WP_REST_Response($e, 200);
    }

    public static function r_mentesek(): WP_REST_Response
    {
        return new WP_REST_Response(['lista' => SDH_Sales_Exportimport::mentesek()], 200);
    }

    public static function r_mentes(WP_REST_Request $r)
    {
        $m = SDH_Sales_Exportimport::mentes((string) $r->get_param('nev'));

        return $m === null ? SDH_Sales_Rest::hiba('Nincs ilyen mentés.', 404, 'nincs') : new WP_REST_Response($m, 200);
    }
}
