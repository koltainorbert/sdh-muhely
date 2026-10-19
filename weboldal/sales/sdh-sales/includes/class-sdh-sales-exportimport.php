<?php
/**
 * SDH Értékesítés – Export / Import: beállítások (titkok nélkül), tartalom-tár, oldalak szekciói + SEO, menük egy JSON-ban.
 *
 *   export(): array                    → a csomag (formatum, verzio, db, ido, beallitas, tartalom[], oldalak[])
 *   elonezet(array $csomag): array     → {ok, hibak[], figyel[], beallitas[], tartalom{uj,modosul,valtozatlan}, oldalak{…}}
 *   import(array $csomag): array       → {ok, hibak[], mentes, db{…}} – előtte teljes mentés (export) a védett mappába
 *   mentesek(): array / mentes(nev)    → a korábbi, import előtti mentések
 *
 * A menük a tartalom-tárban (`menu` típus) utaznak. Importnál minden szöveg újra tisztul (SDH_Sales_Szekcio), a változatlan
 * elemekhez nem nyúl (a napló nem telik), a csomagban nem szereplő elemeket nem törli (összefésülés).
 * Verzió: a csomag fő verziója egyezzen a bővítményével, és az adatbázis-sémája ne legyen újabb.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Exportimport
{
    public const FORMATUM = 'portal-export';
    /** A régi formátumnév (importnál még elfogadjuk). */
    private const FORMATUM_REGI = 'sdh-sales-export';
    private const MAPPA   = 'portal-mentes';
    private const MAX_MENTES = 10;

    public static function init(): void
    {
    }

    /* =================================================================
     * Export
     * ============================================================== */

    /** Titok-gyanús kulcs (ezek soha nem kerülnek exportba). */
    public static function titok_kulcs(string $k): bool
    {
        return preg_match('/(^|[._])(agent_kulcs|api_kulcs|kulcs_titkos|titok|titkos|secret|poskey|jelszo|password|token|privat)$/', $k) === 1
            || preg_match('/(^|\.)(szikra|anthropic)\.kulcs$/', $k) === 1
            || $k === 'rejtes.belepo';
    }

    /** @return array<string, mixed> */
    public static function export(): array
    {
        global $wpdb;

        $b = get_option(SDH_Sales::OPT, []);
        $b = is_array($b) ? $b : [];

        foreach (array_keys($b) as $k) {
            if (self::titok_kulcs((string) $k)) {
                unset($b[$k]);
            }
        }

        ksort($b);

        $sorok = (array) $wpdb->get_results('SELECT tipus, kulcs, nyelv, cim, adat, sorrend, aktiv, szerkesztve FROM ' . SDH_Sales_Schema::t('tartalom') . ' ORDER BY tipus ASC, nyelv ASC, sorrend ASC, kulcs ASC', ARRAY_A);
        $t     = [];

        foreach ($sorok as $s) {
            $a   = json_decode((string) $s['adat'], true);
            $t[] = [
                'tipus'       => (string) $s['tipus'],
                'kulcs'       => (string) $s['kulcs'],
                'nyelv'       => (string) $s['nyelv'],
                'cim'         => (string) $s['cim'],
                'adat'        => is_array($a) ? $a : [],
                'sorrend'     => (int) $s['sorrend'],
                'aktiv'       => (bool) (int) $s['aktiv'],
                'szerkesztve' => (bool) (int) $s['szerkesztve'],
            ];
        }

        return [
            'formatum'  => self::FORMATUM,
            'verzio'    => SDH_SALES_VERSION,
            'db'        => SDH_Sales_Schema::DB_VERSION,
            'ido'       => gmdate('c'),
            'webhely'   => home_url('/'),
            'beallitas' => $b,
            'tartalom'  => $t,
            'oldalak'   => self::oldalak(),
        ];
    }

    /** @return array<int, array<string, mixed>> a szekciós oldalak (út szerint rendezve) */
    private static function oldalak(): array
    {
        $q = get_posts([
            'post_type'      => 'page',
            'post_status'    => ['publish', 'draft', 'private', 'pending'],
            'posts_per_page' => -1,
            'meta_key'       => SDH_Sales_Tartalom::META, // phpcs:ignore WordPress.DB.SlowDBQuery
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $ki = [];

        foreach ($q as $p) {
            $ki[] = [
                'ut'       => get_page_uri($p),
                'cim'      => (string) $p->post_title,
                'statusz'  => (string) $p->post_status,
                'sablon'   => (string) get_post_meta($p->ID, '_wp_page_template', true),
                'szekciok' => SDH_Sales_Tartalom::szekciok((int) $p->ID),
                'seo'      => self::seo((int) $p->ID),
            ];
        }

        usort($ki, static fn ($a, $b) => substr_count($a['ut'], '/') <=> substr_count($b['ut'], '/') ?: strcmp($a['ut'], $b['ut']));

        return $ki;
    }

    /** @return array{title: string, description: string, kulcsszo: string} */
    public static function seo(int $id): array
    {
        return [
            'title'       => (string) get_post_meta($id, '_sdh_seo_title', true),
            'description' => (string) get_post_meta($id, '_sdh_seo_description', true),
            'kulcsszo'    => (string) get_post_meta($id, 'rank_math_focus_keyword', true),
        ];
    }

    /* =================================================================
     * Ellenőrzés, előnézet
     * ============================================================== */

    /** @param mixed $csomag @return array<int, string> hibák (üres = rendben) */
    public static function ellenoriz($csomag): array
    {
        if (!is_array($csomag) || !in_array($csomag['formatum'] ?? '', [self::FORMATUM, self::FORMATUM_REGI], true)) {
            return ['Ez nem az Értékesítés exportfájlja.'];
        }

        $h  = [];
        $v  = (string) ($csomag['verzio'] ?? '');
        $db = (string) ($csomag['db'] ?? '');

        if (preg_match('/^\d+\.\d+\.\d+/', $v) !== 1) {
            $h[] = 'A fájlban nincs érvényes verziószám.';
        } elseif (explode('.', $v)[0] !== explode('.', SDH_SALES_VERSION)[0]) {
            $h[] = 'A fájl más fő verzióból való (' . $v . ', itt ' . SDH_SALES_VERSION . ') – nem importálható.';
        }

        if ($db !== '' && version_compare($db, SDH_Sales_Schema::DB_VERSION, '>')) {
            $h[] = 'A fájl újabb adatbázis-sémából való (' . $db . ') – előbb frissítsd a bővítményt.';
        }

        foreach (['beallitas', 'tartalom', 'oldalak'] as $k) {
            if (isset($csomag[$k]) && !is_array($csomag[$k])) {
                $h[] = 'Sérült rész: ' . $k . '.';
            }
        }

        return $h;
    }

    /**
     * Mi változna az importtal.
     *
     * @param mixed $csomag
     * @return array<string, mixed>
     */
    public static function elonezet($csomag): array
    {
        $hibak = self::ellenoriz($csomag);

        if ($hibak !== []) {
            return ['ok' => false, 'hibak' => $hibak];
        }

        $figyel = [];

        if (version_compare((string) $csomag['verzio'], SDH_SALES_VERSION, '<')) {
            $figyel[] = 'A fájl régebbi verzióból való (' . $csomag['verzio'] . ') – a hiányzó mezők alapértéket kapnak.';
        }

        // Beállítások
        $most = get_option(SDH_Sales::OPT, []);
        $most = self::beallitas_tiszta(is_array($most) ? $most : []);
        $bk   = [];

        foreach (self::beallitas_tiszta((array) ($csomag['beallitas'] ?? [])) as $k => $v) {
            $regi = $most[$k] ?? null;

            if ($regi !== $v) {
                $bk[] = ['kulcs' => $k, 'regi' => $regi, 'uj' => $v];
            }
        }

        // Tartalom
        $t = ['uj' => [], 'modosul' => [], 'valtozatlan' => 0];

        foreach (self::tartalom_tiszta((array) ($csomag['tartalom'] ?? [])) as $e) {
            $regi = SDH_Sales_Tartalom::egy($e['tipus'], $e['kulcs'], $e['nyelv']);
            $nev  = $e['tipus'] . ' / ' . $e['kulcs'] . ($e['nyelv'] !== 'hu' ? ' (' . $e['nyelv'] . ')' : '');

            if ($regi === null) {
                $t['uj'][] = ['nev' => $nev, 'cim' => $e['cim']];
            } elseif (!self::elem_egyezik($regi, $e, $e['tipus'])) {
                $t['modosul'][] = ['nev' => $nev, 'cim' => $e['cim'], 'mezok' => self::elteres($regi, $e, $e['tipus'])];
            } else {
                $t['valtozatlan']++;
            }
        }

        // Oldalak
        $o = ['uj' => [], 'modosul' => [], 'valtozatlan' => 0];

        foreach (self::oldalak_tiszta((array) ($csomag['oldalak'] ?? [])) as $p) {
            $letezo = get_page_by_path($p['ut']);

            if (!$letezo) {
                $o['uj'][] = ['ut' => $p['ut'], 'cim' => $p['cim'], 'szekcio' => count($p['szekciok'])];
                continue;
            }

            $kul = [];

            if (SDH_Sales_Szekcio::ervenyesit_lista(SDH_Sales_Tartalom::szekciok((int) $letezo->ID)) !== $p['szekciok']) {
                $kul[] = 'szekciók';
            }

            if (self::seo((int) $letezo->ID) !== $p['seo']) {
                $kul[] = 'SEO';
            }

            if ((string) $letezo->post_title !== $p['cim']) {
                $kul[] = 'cím';
            }

            if ($kul !== []) {
                $o['modosul'][] = ['ut' => $p['ut'], 'cim' => $p['cim'], 'mezok' => $kul];
            } else {
                $o['valtozatlan']++;
            }
        }

        return [
            'ok'        => true,
            'hibak'     => [],
            'figyel'    => $figyel,
            'verzio'    => (string) $csomag['verzio'],
            'ido'       => (string) ($csomag['ido'] ?? ''),
            'webhely'   => (string) ($csomag['webhely'] ?? ''),
            'beallitas' => $bk,
            'tartalom'  => $t,
            'oldalak'   => $o,
        ];
    }

    /* =================================================================
     * Import
     * ============================================================== */

    /**
     * @param mixed $csomag
     * @return array<string, mixed>
     */
    public static function import($csomag): array
    {
        $hibak = self::ellenoriz($csomag);

        if ($hibak !== []) {
            return ['ok' => false, 'hibak' => $hibak];
        }

        $mentes = self::mentes_ir(self::export());

        if ($mentes === '') {
            return ['ok' => false, 'hibak' => ['Az import előtti mentést nem sikerült megírni – az import elmaradt.']];
        }

        $db = ['beallitas' => 0, 'tartalom' => 0, 'oldal' => 0];

        // Beállítások: csak a csomagban szereplő kulcsok (a titkok érintetlenek).
        $b    = self::beallitas_tiszta((array) ($csomag['beallitas'] ?? []));
        $most = get_option(SDH_Sales::OPT, []);
        $most = self::beallitas_tiszta(is_array($most) ? $most : []);
        $valt = array_filter($b, static fn ($v, $k) => ($most[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);

        if ($valt !== []) {
            SDH_Sales::b_ment($valt, 'import');
            $db['beallitas'] = count($valt);
        }

        foreach (self::tartalom_tiszta((array) ($csomag['tartalom'] ?? [])) as $e) {
            $regi = SDH_Sales_Tartalom::egy($e['tipus'], $e['kulcs'], $e['nyelv']);

            if ($regi !== null && self::elem_egyezik($regi, $e, $e['tipus'])) {
                continue;
            }

            SDH_Sales_Tartalom::ment($e['tipus'], $e['kulcs'], $e['adat'], $e['cim'], $e['sorrend'], $e['aktiv'], $e['nyelv'], true);
            $db['tartalom']++;
        }

        foreach (self::oldalak_tiszta((array) ($csomag['oldalak'] ?? [])) as $p) {
            $db['oldal'] += self::oldal_import($p) ? 1 : 0;
        }

        SDH_Sales_Naplo::ir('import', sprintf('Import: %d beállítás, %d tartalomelem, %d oldal (mentés: %s)', $db['beallitas'], $db['tartalom'], $db['oldal'], $mentes), 'figyel');

        return ['ok' => true, 'hibak' => [], 'mentes' => $mentes, 'db' => $db];
    }

    /** @param array<string, mixed> $p */
    private static function oldal_import(array $p): bool
    {
        $letezo = get_page_by_path($p['ut']);

        if ($letezo) {
            $id   = (int) $letezo->ID;
            $volt = false;

            if ((string) $letezo->post_title !== $p['cim'] && $p['cim'] !== '') {
                wp_update_post(['ID' => $id, 'post_title' => $p['cim']]);
                $volt = true;
            }
        } else {
            $reszek = explode('/', $p['ut']);
            $slug   = (string) array_pop($reszek);
            $szulo  = $reszek !== [] ? get_page_by_path(implode('/', $reszek)) : null;

            if ($reszek !== [] && !$szulo) {
                return false;
            }

            $id = wp_insert_post([
                'post_type'   => 'page',
                'post_status' => in_array($p['statusz'], ['publish', 'draft', 'private', 'pending'], true) ? $p['statusz'] : 'draft',
                'post_title'  => $p['cim'] !== '' ? $p['cim'] : $slug,
                'post_name'   => $slug,
                'post_parent' => $szulo ? (int) $szulo->ID : 0,
            ], true);

            if (is_wp_error($id) || (int) $id === 0) {
                return false;
            }

            $id   = (int) $id;
            $volt = true;
        }

        if ($p['sablon'] !== '') {
            update_post_meta($id, '_wp_page_template', $p['sablon']);
        }

        if (SDH_Sales_Szekcio::ervenyesit_lista(SDH_Sales_Tartalom::szekciok($id)) !== $p['szekciok']) {
            SDH_Sales_Tartalom::szekciok_ment($id, $p['szekciok']);
            $volt = true;
        }

        if (self::seo($id) !== $p['seo']) {
            self::seo_ir($id, $p['seo']);
            $volt = true;
        }

        if ($volt) {
            update_post_meta($id, '_sdh_szerkesztve', 1);
        }

        return $volt;
    }

    /** SEO-mezők írása (az üres érték törli – a SDH_Sales_Tartalom::seo_meta() csak a kitöltöttet írja). @param array<string, string> $seo */
    public static function seo_ir(int $id, array $seo): void
    {
        SDH_Sales_Tartalom::seo_meta($id, $seo);

        $parok = ['title' => ['_sdh_seo_title', 'rank_math_title'], 'description' => ['_sdh_seo_description', 'rank_math_description'], 'kulcsszo' => ['rank_math_focus_keyword']];

        foreach ($parok as $k => $metak) {
            if (($seo[$k] ?? '') === '') {
                foreach ($metak as $m) {
                    delete_post_meta($id, $m);
                }
            }
        }
    }

    /* =================================================================
     * Tisztítás (a csomag minden része újra tisztul)
     * ============================================================== */

    /** @param array<string, mixed> $b @return array<string, mixed> */
    private static function beallitas_tiszta(array $b): array
    {
        $ki = [];

        foreach ($b as $k => $v) {
            $k = (string) $k;

            if (preg_match('/^[a-z0-9_.]{1,80}$/', $k) !== 1 || self::titok_kulcs($k)) {
                continue;
            }

            if (is_string($v)) {
                $v = strpos($v, '<') !== false ? SDH_Sales_Szekcio::html($v, true) : sanitize_textarea_field($v);
            } elseif (is_array($v)) {
                $v = SDH_Sales_Szekcio::altalanos($v);
            } elseif (!is_bool($v) && !is_int($v) && !is_float($v) && $v !== null) {
                continue;
            }

            $ki[$k] = $v;
        }

        return $ki;
    }

    /** @param array<int, mixed> $t @return array<int, array<string, mixed>> */
    private static function tartalom_tiszta(array $t): array
    {
        $ki = [];

        foreach ($t as $e) {
            if (!is_array($e)) {
                continue;
            }

            $tipus = substr(sanitize_key((string) ($e['tipus'] ?? '')), 0, 30);
            $kulcs = mb_substr(trim(sanitize_text_field((string) ($e['kulcs'] ?? ''))), 0, 120);

            if ($tipus === '' || $kulcs === '') {
                continue;
            }

            $ki[] = [
                'tipus'   => $tipus,
                'kulcs'   => $kulcs,
                'nyelv'   => substr(sanitize_key((string) ($e['nyelv'] ?? 'hu')), 0, 8) ?: 'hu',
                'cim'     => mb_substr(sanitize_text_field((string) ($e['cim'] ?? '')), 0, 255),
                'adat'    => SDH_Sales_Szekcio::elem_tisztit($tipus, is_array($e['adat'] ?? null) ? $e['adat'] : []),
                'sorrend' => (int) ($e['sorrend'] ?? 0),
                'aktiv'   => (bool) ($e['aktiv'] ?? true),
            ];
        }

        return $ki;
    }

    /** @param array<int, mixed> $o @return array<int, array<string, mixed>> */
    private static function oldalak_tiszta(array $o): array
    {
        $ki = [];

        foreach ($o as $p) {
            if (!is_array($p) || empty($p['ut'])) {
                continue;
            }

            $ut = implode('/', array_filter(array_map('sanitize_title', explode('/', (string) $p['ut']))));

            if ($ut === '') {
                continue;
            }

            $seo = is_array($p['seo'] ?? null) ? $p['seo'] : [];

            $ki[] = [
                'ut'       => $ut,
                'cim'      => mb_substr(sanitize_text_field((string) ($p['cim'] ?? '')), 0, 200),
                'statusz'  => sanitize_key((string) ($p['statusz'] ?? 'publish')),
                'sablon'   => sanitize_file_name((string) ($p['sablon'] ?? '')),
                'szekciok' => SDH_Sales_Szekcio::ervenyesit_lista(is_array($p['szekciok'] ?? null) ? $p['szekciok'] : []),
                'seo'      => [
                    'title'       => mb_substr(sanitize_text_field((string) ($seo['title'] ?? '')), 0, 200),
                    'description' => mb_substr(sanitize_textarea_field((string) ($seo['description'] ?? '')), 0, 400),
                    'kulcsszo'    => mb_substr(sanitize_text_field((string) ($seo['kulcsszo'] ?? '')), 0, 120),
                ],
            ];
        }

        usort($ki, static fn ($a, $b) => substr_count($a['ut'], '/') <=> substr_count($b['ut'], '/'));

        return $ki;
    }

    /**
     * Egyezik-e a tárolt elem az importálttal – a tároltat is ugyanazon a tisztításon átvezetve (így a nem az adminból
     * írt, de tartalmilag azonos elemek nem számítanak változásnak).
     *
     * @param array<string, mixed> $regi
     * @param array<string, mixed> $uj
     */
    private static function elem_egyezik(array $regi, array $uj, string $tipus = ''): bool
    {
        return self::elteres($regi, $uj, $tipus) === [];
    }

    /**
     * @param array<string, mixed> $regi
     * @param array<string, mixed> $uj
     * @return array<int, string> az eltérő mezők neve
     */
    private static function elteres(array $regi, array $uj, string $tipus = ''): array
    {
        $ki = [];

        if ((string) $regi['cim'] !== (string) $uj['cim']) {
            $ki[] = 'cim';
        }

        if ((int) $regi['sorrend'] !== (int) $uj['sorrend']) {
            $ki[] = 'sorrend';
        }

        if ((bool) $regi['aktiv'] !== (bool) $uj['aktiv']) {
            $ki[] = 'aktiv';
        }

        $a = $tipus !== '' ? SDH_Sales_Szekcio::elem_tisztit($tipus, (array) $regi['adat']) : (array) $regi['adat'];
        $b = (array) $uj['adat'];

        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (wp_json_encode($a[$k] ?? null) !== wp_json_encode($b[$k] ?? null)) {
                $ki[] = (string) $k;
            }
        }

        return array_slice($ki, 0, 12);
    }

    /* =================================================================
     * Mentések (import előtt)
     * ============================================================== */

    private static function mappa(): string
    {
        $u = wp_upload_dir(null, false);
        $d = trailingslashit((string) $u['basedir']) . self::MAPPA;

        if (!is_dir($d)) {
            wp_mkdir_p($d);
        }

        if (!is_file($d . '/.htaccess')) {
            file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
        }

        if (!is_file($d . '/index.php')) {
            file_put_contents($d . '/index.php', "<?php\n// Csendes.\n");
        }

        return $d;
    }

    /** @param array<string, mixed> $adat @return string a mentés neve (üres: hiba) */
    private static function mentes_ir(array $adat): string
    {
        $d   = self::mappa();
        $nev = 'import-elott-' . gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(10, false, false)) . '.json';

        if (file_put_contents($d . '/' . $nev, (string) wp_json_encode($adat, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            return '';
        }

        $fajlok = glob($d . '/import-elott-*.json') ?: [];
        sort($fajlok);

        foreach (array_slice($fajlok, 0, max(0, count($fajlok) - self::MAX_MENTES)) as $f) {
            unlink($f);
        }

        return $nev;
    }

    /** @return array<int, array{nev: string, ido: string, meret: int}> */
    public static function mentesek(): array
    {
        $ki = [];

        foreach (glob(self::mappa() . '/import-elott-*.json') ?: [] as $f) {
            $ki[] = ['nev' => basename($f), 'ido' => gmdate('Y-m-d H:i:s', (int) filemtime($f)), 'meret' => (int) filesize($f)];
        }

        usort($ki, static fn ($a, $b) => strcmp($b['nev'], $a['nev']));

        return $ki;
    }

    /** @return array<string, mixed>|null */
    public static function mentes(string $nev): ?array
    {
        if (preg_match('/^import-elott-\d{8}-\d{6}-[a-z0-9]{10}\.json$/', $nev) !== 1) {
            return null;
        }

        $f = self::mappa() . '/' . $nev;

        if (!is_file($f)) {
            return null;
        }

        $a = json_decode((string) file_get_contents($f), true);

        return is_array($a) ? $a : null;
    }
}
