<?php
/**
 * SDH Értékesítés – Szikra, az értékesítő webhely AI-asszisztense: beállítások, REST (`POST /szikra`), korlátok, havi keret,
 * beszélgetésnapló (30 nap), lebegő csevegő a láblécben.
 *
 * Részek:
 *   -szikra.php          ez: belépési pont, beállítások, korlátok, napló, front
 *   -szikra-api.php      Anthropic Messages API, rendszerprompt, eszközhasználati kör
 *   -szikra-eszkozok.php a modell eszközei (csomag_ajanlo, lead_mentes, idopont_link, atadas_emberre)
 *   -szikra-kimenet.php  a válasz tisztítása (Markdown → biztonságos HTML, idegen linkek semlegesítése, prompt-szivárgás)
 *   -szikra-tudas.php    tudásbázis + BM25
 *
 * REST: POST /szikra {munkamenet, uzenet, mod: "ertekesites"|"oktato", hozzajarulas?: bool, lecke?: string, lepes?: int}
 *   → {ok, html, forrasok[{cim,url}], hozzajarulas_kell?, atadva?, lead?, maradek}
 *
 * Beállítások (`szikra.*`, kezdőérték: self::ALAP; titok: SDH_Sales::titkos('szikra_kulcs')).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Szikra
{
    public const TITOK = 'szikra_kulcs';
    public const OPT_HONAP = 'sdh_sales_szikra_honap';

    /** Kezdőértékek (az admin beállítás-csoportja ezeket mutatja, amíg nincs mentett érték). */
    public const ALAP = [
        'szikra.aktiv'            => true,
        'szikra.modell'           => 'claude-sonnet-5-5',
        'szikra.modell_tartalek'  => 'claude-haiku-5-5',
        'szikra.max_tokens'       => 1024,
        'szikra.max_bemenet'      => 1500,
        'szikra.napi_ip'          => 40,
        'szikra.munkamenet_max'   => 30,
        'szikra.percenkent'       => 8,
        'szikra.havi_keret'       => 30.0,
        'szikra.ar_be_mtok'       => 2.0,
        'szikra.ar_ki_mtok'       => 10.0,
        'szikra.megorzes_nap'     => 30,
        'szikra.top_k'            => 6,
        'szikra.elozmeny'         => 12,
        'szikra.eszkoz_kor'       => 4,
        'szikra.udvozlo'          => 'Szia! Szikra vagyok, a(z) {termeknev} asszisztense. Segítek kiválasztani a megfelelő csomagot, és elmagyarázom, mit tud a rendszer. Miben segíthetek?',
        'szikra.tajekoztato_url'  => '/jogi/ai-chat-tajekoztato/',
        'szikra.idopont_url'      => '/idopontfoglalas/',
        'szikra.tudas_url'        => '/tudasbazis/{fejezet}/',
        'szikra.gyik_url'         => '/gyik/',
        'szikra.arak_url'         => '/arak/',
        'szikra.kapcsolat_url'    => '/kapcsolat/',
    ];

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/szikra', 'POST', [self::class, 'rest_kerdez'], 'nyilvanos');
        add_action('init', [self::class, 'front_regisztral'], 20);
        add_action('wp', [self::class, 'igeny_ha_aktiv']);
        add_action('wp_footer', [self::class, 'lab'], 1);
        add_action(SDH_Sales::CRON, [self::class, 'takarit_ha_kell']);
        add_filter('sdh_sales_admin_beallitas_csoportok', [self::class, 'beallitas_csoport']);
        add_action('sdh_sales_tartalom_betoltve', [SDH_Sales_Szikra_Tudas::class, 'urit']);
    }

    /** Egy szikra-beállítás (mentett érték, különben az alapérték). @return mixed */
    public static function b(string $kulcs)
    {
        return SDH_Sales::b($kulcs, self::ALAP[$kulcs] ?? null);
    }

    public static function i(string $kulcs): int
    {
        return (int) self::b($kulcs);
    }

    public static function aktiv(): bool
    {
        return (bool) self::b('szikra.aktiv') && SDH_Sales::van_titok(self::TITOK);
    }

    /** Relatív út vagy teljes URL → teljes URL a saját webhelyen. */
    public static function url(string $kulcs): string
    {
        $u = (string) self::b($kulcs);

        return preg_match('#^https?://#', $u) ? $u : home_url('/' . ltrim($u, '/'));
    }

    /* =================================================================
     * Admin: beállítás-csoport (az admin ügynök szűrője rajzolja)
     * ============================================================== */

    /**
     * @param array<string, mixed> $cs
     * @return array<string, mixed>
     */
    public static function beallitas_csoport($cs): array
    {
        $cs = is_array($cs) ? $cs : [];
        $m  = static fn (string $k, string $tipus, string $cim, string $sugo = '', array $extra = []): array => ['kulcs' => $k, 'tipus' => $tipus, 'cim' => $cim, 'sugo' => $sugo, 'alap' => self::ALAP[$k] ?? null] + $extra;

        $cs['szikra'] = [
            'cim'   => __('Szikra (AI-asszisztens)', 'sdh-sales'),
            'leiras' => __('Az értékesítő webhely csevegő asszisztense és az oktató mód.', 'sdh-sales'),
            'mezok' => [
                $m('szikra.aktiv', 'kapcsolo', __('Szikra bekapcsolva', 'sdh-sales'), __('A csevegő csak akkor jelenik meg, ha az API-kulcs is meg van adva.', 'sdh-sales')),
                ['kulcs' => self::TITOK, 'tipus' => 'titok', 'cim' => __('Claude API-kulcs', 'sdh-sales'), 'sugo' => __('Titkosítva tároljuk; soha nem jelenik meg újra.', 'sdh-sales')],
                $m('szikra.modell', 'szoveg', __('Modell', 'sdh-sales'), __('Ha az API nem ismeri, a tartalék modell fut.', 'sdh-sales')),
                $m('szikra.modell_tartalek', 'szoveg', __('Tartalék modell', 'sdh-sales')),
                $m('szikra.max_tokens', 'szam', __('Válasz max. hossza (token)', 'sdh-sales'), '', ['min' => 128, 'max' => 4096]),
                $m('szikra.max_bemenet', 'szam', __('Üzenet max. hossza (karakter)', 'sdh-sales'), '', ['min' => 100, 'max' => 8000]),
                $m('szikra.napi_ip', 'szam', __('Napi üzenetkorlát látogatónként', 'sdh-sales'), '', ['min' => 1, 'max' => 1000]),
                $m('szikra.munkamenet_max', 'szam', __('Üzenetkorlát beszélgetésenként', 'sdh-sales'), '', ['min' => 1, 'max' => 500]),
                $m('szikra.percenkent', 'szam', __('Üzenet / perc látogatónként', 'sdh-sales'), '', ['min' => 1, 'max' => 120]),
                $m('szikra.havi_keret', 'tizedes', __('Havi keret (USD)', 'sdh-sales'), __('Ha elfogy, Szikra udvariasan jelzi, és a hónap végéig nem válaszol.', 'sdh-sales')),
                $m('szikra.ar_be_mtok', 'tizedes', __('Bemeneti ár / millió token (USD)', 'sdh-sales')),
                $m('szikra.ar_ki_mtok', 'tizedes', __('Kimeneti ár / millió token (USD)', 'sdh-sales')),
                $m('szikra.megorzes_nap', 'szam', __('Beszélgetésnapló megőrzése (nap)', 'sdh-sales'), '', ['min' => 1, 'max' => 365]),
                $m('szikra.udvozlo', 'szoveg_tobbsoros', __('Üdvözlő szöveg', 'sdh-sales'), __('Tokenek: {termeknev}, {ceg}.', 'sdh-sales')),
                $m('szikra.tajekoztato_url', 'szoveg', __('AI-csevegés tájékoztató címe', 'sdh-sales')),
                $m('szikra.idopont_url', 'szoveg', __('Időpontfoglalás címe', 'sdh-sales')),
                $m('szikra.tudas_url', 'szoveg', __('Tudásbázis-fejezet címe ({fejezet})', 'sdh-sales')),
            ],
            'allapot' => self::allapot(),
        ];

        return $cs;
    }

    /** @return array<string, mixed> az admin számára: e havi felhasználás */
    public static function allapot(): array
    {
        $h = self::honap();

        return [
            'kulcs_megadva' => SDH_Sales::van_titok(self::TITOK),
            'honap'         => $h['honap'],
            'token_be'      => (int) $h['be'],
            'token_ki'      => (int) $h['ki'],
            'koltseg'       => round((float) $h['koltseg'], 4),
            'keret'         => (float) self::b('szikra.havi_keret'),
            'tudas_darab'   => (int) (SDH_Sales_Szikra_Tudas::index()['darab_ossz'] ?? 0),
        ];
    }

    /* =================================================================
     * Havi keret
     * ============================================================== */

    /** @return array{honap: string, be: int, ki: int, koltseg: float} */
    public static function honap(): array
    {
        $most = gmdate('Y-m');
        $h    = get_option(self::OPT_HONAP, []);

        if (!is_array($h) || ($h['honap'] ?? '') !== $most) {
            $h = ['honap' => $most, 'be' => 0, 'ki' => 0, 'koltseg' => 0.0];
        }

        return ['honap' => $most, 'be' => (int) $h['be'], 'ki' => (int) $h['ki'], 'koltseg' => (float) $h['koltseg']];
    }

    public static function keret_elfogyott(): bool
    {
        $keret = (float) self::b('szikra.havi_keret');

        return $keret <= 0 || self::honap()['koltseg'] >= $keret;
    }

    /**
     * A felhasználás könyvelése (Anthropic `usage`: input, output, cache írás ×1,25, cache olvasás ×0,1).
     *
     * @param array<string, int> $u
     */
    public static function konyvel(array $u): float
    {
        $be   = (int) ($u['input_tokens'] ?? 0);
        $ki   = (int) ($u['output_tokens'] ?? 0);
        $cw   = (int) ($u['cache_creation_input_tokens'] ?? 0);
        $cr   = (int) ($u['cache_read_input_tokens'] ?? 0);
        $pbe  = (float) self::b('szikra.ar_be_mtok');
        $pki  = (float) self::b('szikra.ar_ki_mtok');
        $kolt = ($be + $cw * 1.25 + $cr * 0.1) * $pbe / 1e6 + $ki * $pki / 1e6;
        $h    = self::honap();

        update_option(self::OPT_HONAP, ['honap' => $h['honap'], 'be' => $h['be'] + $be + $cw + $cr, 'ki' => $h['ki'] + $ki, 'koltseg' => $h['koltseg'] + $kolt], false);

        return $kolt;
    }

    /* =================================================================
     * Napló (szikra tábla) + megőrzés
     * ============================================================== */

    public static function naploz(string $munkamenet, string $szerep, string $szoveg, int $be = 0, int $ki = 0): void
    {
        global $wpdb;

        $wpdb->insert(SDH_Sales_Schema::t('szikra'), [
            'ido'        => current_time('mysql', true),
            'munkamenet' => $munkamenet,
            'szerep'     => substr($szerep, 0, 12),
            'szoveg'     => mb_substr($szoveg, 0, 20000),
            'token_be'   => $be,
            'token_ki'   => $ki,
            'ip_hash'    => SDH_Sales::ip_hash(),
        ]);
    }

    /**
     * A munkamenet előzménye (csak ugyanattól a látogatótól – más IP-ről a munkamenet-azonosító nem ér semmit).
     *
     * @return array<int, array{szerep: string, szoveg: string}>
     */
    public static function elozmeny(string $munkamenet, int $max): array
    {
        global $wpdb;

        $t     = SDH_Sales_Schema::t('szikra');
        $sorok = (array) $wpdb->get_results($wpdb->prepare("SELECT szerep, szoveg FROM {$t} WHERE munkamenet = %s AND ip_hash = %s AND szerep IN ('user','assistant') ORDER BY id DESC LIMIT %d", $munkamenet, SDH_Sales::ip_hash(), max(0, $max)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_reverse(array_map(static fn ($s) => ['szerep' => (string) $s['szerep'], 'szoveg' => (string) $s['szoveg']], $sorok));
    }

    /** A megőrzési időnél régebbi beszélgetések törlése. Visszaadja a törölt sorok számát. */
    public static function takarit(): int
    {
        global $wpdb;

        $nap = max(1, self::i('szikra.megorzes_nap'));

        return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Sales_Schema::t('szikra') . ' WHERE ido < %s', gmdate('Y-m-d H:i:s', time() - $nap * DAY_IN_SECONDS)));
    }

    /** A cron 5 percenként fut – a takarítás óránként egyszer elég. */
    public static function takarit_ha_kell(): void
    {
        if (get_transient('sdh_sales_szikra_takaritva')) {
            return;
        }

        set_transient('sdh_sales_szikra_takaritva', 1, HOUR_IN_SECONDS);
        $n = self::takarit();

        if ($n > 0) {
            SDH_Sales_Naplo::ir('szikra', 'Régi Szikra-beszélgetések törölve: ' . $n . ' sor', 'info');
        }
    }

    /* =================================================================
     * REST
     * ============================================================== */

    /** @return WP_REST_Response|WP_Error */
    public static function rest_kerdez(WP_REST_Request $r)
    {
        $mm     = (string) $r->get_param('munkamenet');
        $uzenet = trim(str_replace("\0", '', (string) $r->get_param('uzenet')));
        $mod    = (string) $r->get_param('mod') === 'oktato' ? 'oktato' : 'ertekesites';

        if (preg_match('/^[A-Za-z0-9_-]{16,40}$/', $mm) !== 1) {
            return SDH_Sales_Rest::hiba(__('Érvénytelen munkamenet.', 'sdh-sales'), 400, 'sdh_szikra_munkamenet');
        }

        if ($uzenet === '') {
            return SDH_Sales_Rest::hiba(__('Írj egy kérdést.', 'sdh-sales'), 400, 'sdh_szikra_ures');
        }

        $max = max(100, self::i('szikra.max_bemenet'));

        if (mb_strlen($uzenet) > $max) {
            /* translators: %d: karakterek száma */
            return SDH_Sales_Rest::hiba(sprintf(__('Az üzenet túl hosszú (legfeljebb %d karakter).', 'sdh-sales'), $max), 400, 'sdh_szikra_hossz');
        }

        if (!(bool) self::b('szikra.aktiv') || !SDH_Sales::van_titok(self::TITOK)) {
            return SDH_Sales_Rest::hiba(__('Szikra most nem elérhető. Írj nekünk a kapcsolati űrlapon, és egy kolléga válaszol.', 'sdh-sales'), 503, 'sdh_szikra_ki');
        }

        // Korlátok: percenként, naponta (IP), munkamenetenként.
        if (!SDH_Sales_Rest::korlat('szikra_perc', max(1, self::i('szikra.percenkent')), 60)) {
            return SDH_Sales_Rest::hiba(__('Kicsit gyorsan jönnek a kérdések – várj egy percet, és folytassuk.', 'sdh-sales'), 429, 'sdh_korlat');
        }

        if (!SDH_Sales_Rest::korlat('szikra_nap', max(1, self::i('szikra.napi_ip')), DAY_IN_SECONDS)) {
            return SDH_Sales_Rest::hiba(__('Mára elérted a Szikrával váltható üzenetek számát. Holnap folytathatjuk – vagy írj nekünk, és egy kolléga válaszol.', 'sdh-sales'), 429, 'sdh_korlat');
        }

        if (!SDH_Sales_Rest::korlat('szikra_mm', max(1, self::i('szikra.munkamenet_max')), DAY_IN_SECONDS, $mm)) {
            return SDH_Sales_Rest::hiba(__('Ez a beszélgetés elérte a hosszkorlátot. Kezdj újat, vagy kérj visszahívást egy kollégától.', 'sdh-sales'), 429, 'sdh_korlat');
        }

        if (self::keret_elfogyott()) {
            SDH_Sales_Naplo::ir('szikra', 'A Szikra havi kerete elfogyott – a kérdés nem ment ki.', 'figyel');

            return SDH_Sales_Rest::hiba(__('Szikra erre a hónapra pihenőt tart. Írj nekünk a kapcsolati űrlapon vagy foglalj időpontot – egy kolléga szívesen segít.', 'sdh-sales'), 429, 'sdh_szikra_keret');
        }

        $v = SDH_Sales_Szikra_Api::valaszol($mm, $uzenet, $mod, [
            'hozzajarulas' => (bool) $r->get_param('hozzajarulas'),
            'lecke'        => sanitize_key((string) $r->get_param('lecke')),
            'lepes'        => (int) $r->get_param('lepes'),
        ]);

        if (is_wp_error($v)) {
            return $v;
        }

        return new WP_REST_Response($v, 200);
    }

    /* =================================================================
     * Front
     * ============================================================== */

    public static function front_regisztral(): void
    {
        SDH_Sales_Front::modul('szikra', 'assets/front/szikra.js', [], [self::class, 'js_adat'], 'assets/front/szikra.css');
    }

    /** @return array<string, mixed> */
    public static function js_adat(): array
    {
        return [
            'rest'        => esc_url_raw(rest_url(SDH_Sales_Rest::NS . '/szikra')),
            'udvozlo'     => SDH_Sales::t((string) self::b('szikra.udvozlo')),
            'tajekoztato' => esc_url_raw(self::url('szikra.tajekoztato_url')),
            'kapcsolat'   => esc_url_raw(self::url('szikra.kapcsolat_url')),
            'max'         => max(100, self::i('szikra.max_bemenet')),
            'nev'         => 'Szikra',
            'szoveg'      => [
                'nyit'          => __('Kérdezd Szikrát', 'sdh-sales'),
                'bezar'         => __('Csevegő bezárása', 'sdh-sales'),
                'cim'           => __('Szikra – AI-asszisztens', 'sdh-sales'),
                'alcim'         => __('Mesterséges intelligencia válaszol; tévedhet.', 'sdh-sales'),
                'adatvedelem'   => __('Ez egy mesterséges intelligenciával működő csevegő. A beszélgetést a válaszadáshoz feldolgozzuk és legfeljebb 30 napig őrizzük; személyes adatot csak a kifejezett hozzájárulásoddal mentünk. Ne írj be jelszót vagy bankkártyaadatot.', 'sdh-sales'),
                'tajekoztato'   => __('Tájékoztató az AI-csevegésről', 'sdh-sales'),
                'mezo'          => __('Írd ide a kérdésed…', 'sdh-sales'),
                'kuld'          => __('Küldés', 'sdh-sales'),
                'gondolkodik'   => __('Szikra gondolkodik…', 'sdh-sales'),
                'uj'            => __('Új beszélgetés', 'sdh-sales'),
                'forrasok'      => __('Források', 'sdh-sales'),
                'hiba'          => __('Nem sikerült választ kapni. Próbáld újra, vagy írj nekünk.', 'sdh-sales'),
                'hozzajarulas'  => __('Szikra el szeretné menteni a megadott elérhetőségedet, hogy egy kolléga felvegye veled a kapcsolatot. Hozzájárulsz?', 'sdh-sales'),
                'igen'          => __('Hozzájárulok, mentsétek el', 'sdh-sales'),
                'nem'           => __('Inkább ne', 'sdh-sales'),
                'igen_uzenet'   => __('Hozzájárulok, hogy elmentsétek az adataimat, és felvegyétek velem a kapcsolatot.', 'sdh-sales'),
                'nem_uzenet'    => __('Most nem szeretném, hogy elmentsétek az adataimat.', 'sdh-sales'),
                'te'            => __('Te', 'sdh-sales'),
                'karakter'      => __('karakter', 'sdh-sales'),
            ],
        ];
    }

    public static function igeny_ha_aktiv(): void
    {
        if (!is_admin() && self::aktiv()) {
            SDH_Sales_Front::igeny('szikra');
        }
    }

    /** A lebegő csevegő csatolási pontja (minden oldalon, ha Szikra be van kapcsolva). */
    public static function lab(): void
    {
        if (is_admin() || !self::aktiv()) {
            return;
        }

        SDH_Sales_Front::igeny('szikra');
        echo '<div data-sdh="szikra" data-opciok="' . esc_attr((string) wp_json_encode(['lebego' => true])) . '"></div>' . "\n";
    }
}
