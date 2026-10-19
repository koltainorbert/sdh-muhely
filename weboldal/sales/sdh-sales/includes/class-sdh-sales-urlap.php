<?php
/**
 * SDH Értékesítés – egyszerű űrlapok: kapcsolat, Enterprise-ajánlatkérés, partnerprogram → `lead` + értesítő levél.
 *
 *   lead_ment(string $forras, array $adat, string $email = ''): int  – közös lead-mentés (az igényfelmérő is ezt hívja)
 *   ertesit(string $targy, array $sorok): void                      – levél a `email.ertesites` címre
 *   mezok(string $tipus): array                                     – az űrlap mezői (a front ebből rajzol)
 * REST: POST /lead {tipus: kapcsolat|ajanlat|partner, …mezők, _t, weboldal_cim, turnstile?}
 * Front: `[data-modul="urlap"][data-opciok='{"tipus":"kapcsolat"}']` → assets/front/urlap.js
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Urlap
{
    public const TIPUSOK = ['kapcsolat', 'ajanlat', 'partner'];

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/lead', 'POST', [self::class, 'rest_lead'], 'nyilvanos', ['lead', 6, 600]);
        add_action('init', [self::class, 'front'], 6);
    }

    public static function front(): void
    {
        SDH_Sales_Front::modul('urlap', 'assets/front/urlap.js', ['kozos'], static fn (): array => [
            'mezok'       => array_combine(self::TIPUSOK, array_map([self::class, 'mezok'], self::TIPUSOK)),
            'vedelem'     => SDH_Sales_Biztonsag::front_adat(),
            'adatvedelem' => home_url(user_trailingslashit((string) SDH_Sales::b('oldal.adatvedelem', 'adatkezeles'))),
        ]);
    }

    /**
     * @return array<int, array{nev: string, cimke: string, tipus: string, kotelezo: bool, opciok?: array<int, string>, max?: int}>
     */
    public static function mezok(string $tipus): array
    {
        $kozos = [
            ['nev' => 'nev', 'cimke' => __('Neved', 'sdh-sales'), 'tipus' => 'text', 'kotelezo' => true, 'max' => 120],
            ['nev' => 'email', 'cimke' => __('E-mail-cím', 'sdh-sales'), 'tipus' => 'email', 'kotelezo' => true, 'max' => 190],
            ['nev' => 'telefon', 'cimke' => __('Telefonszám', 'sdh-sales'), 'tipus' => 'tel', 'kotelezo' => false, 'max' => 40],
            ['nev' => 'ceg', 'cimke' => __('Cég / szerviz neve', 'sdh-sales'), 'tipus' => 'text', 'kotelezo' => $tipus !== 'kapcsolat', 'max' => 190],
        ];

        if ($tipus === 'ajanlat') {
            return array_merge($kozos, [
                ['nev' => 'telephely', 'cimke' => __('Telephelyek száma', 'sdh-sales'), 'tipus' => 'number', 'kotelezo' => true, 'max' => 4],
                ['nev' => 'felhasznalo', 'cimke' => __('Felhasználók száma', 'sdh-sales'), 'tipus' => 'number', 'kotelezo' => true, 'max' => 5],
                ['nev' => 'uzemeltetes', 'cimke' => __('Üzemeltetés', 'sdh-sales'), 'tipus' => 'select', 'kotelezo' => true, 'opciok' => [__('Saját szerveren', 'sdh-sales'), __('Felhőben, ti üzemeltetitek', 'sdh-sales'), __('Még nem tudom', 'sdh-sales')]],
                ['nev' => 'uzenet', 'cimke' => __('Mire van szükségetek?', 'sdh-sales'), 'tipus' => 'textarea', 'kotelezo' => true, 'max' => 4000],
            ]);
        }

        if ($tipus === 'partner') {
            return array_merge($kozos, [
                ['nev' => 'weboldal', 'cimke' => __('Weboldal', 'sdh-sales'), 'tipus' => 'url', 'kotelezo' => false, 'max' => 190],
                ['nev' => 'partner_tipus', 'cimke' => __('Milyen partnerség érdekel?', 'sdh-sales'), 'tipus' => 'select', 'kotelezo' => true, 'opciok' => [__('Ajánló partner', 'sdh-sales'), __('Viszonteladó', 'sdh-sales'), __('Telepítő / rendszergazda', 'sdh-sales'), __('Egyéb', 'sdh-sales')]],
                ['nev' => 'uzenet', 'cimke' => __('Mutatkozz be röviden', 'sdh-sales'), 'tipus' => 'textarea', 'kotelezo' => true, 'max' => 4000],
            ]);
        }

        return array_merge($kozos, [
            ['nev' => 'tema', 'cimke' => __('Téma', 'sdh-sales'), 'tipus' => 'select', 'kotelezo' => true, 'opciok' => [__('Kérdésem van', 'sdh-sales'), __('Bemutatót kérek', 'sdh-sales'), __('Számlázás, fizetés', 'sdh-sales'), __('Technikai segítség', 'sdh-sales'), __('Egyéb', 'sdh-sales')]],
            ['nev' => 'uzenet', 'cimke' => __('Üzenet', 'sdh-sales'), 'tipus' => 'textarea', 'kotelezo' => true, 'max' => 4000],
        ]);
    }

    /**
     * Mezők ellenőrzése a leírás szerint.
     *
     * @param array<int, array<string, mixed>> $mezok
     * @param array<string, mixed>             $be
     * @return array{adat: array<string, string>, hibak: array<string, string>}
     */
    public static function ellenoriz(array $mezok, array $be): array
    {
        $adat  = [];
        $hibak = [];

        foreach ($mezok as $m) {
            $n = (string) $m['nev'];
            $v = $be[$n] ?? '';
            $v = is_scalar($v) ? (string) $v : '';
            $v = $m['tipus'] === 'textarea' ? sanitize_textarea_field($v) : sanitize_text_field($v);
            $v = mb_substr(trim($v), 0, (int) ($m['max'] ?? 190));

            if ($v === '') {
                if (!empty($m['kotelezo'])) {
                    $hibak[$n] = sprintf(__('Töltsd ki: %s.', 'sdh-sales'), (string) $m['cimke']);
                }

                $adat[$n] = '';
                continue;
            }

            if ($m['tipus'] === 'email' && !is_email($v)) {
                $hibak[$n] = __('Az e-mail-cím hibás.', 'sdh-sales');
            } elseif ($m['tipus'] === 'tel' && !SDH_Sales_Rendeles::telefon_jo($v)) {
                $hibak[$n] = __('A telefonszám hibás.', 'sdh-sales');
            } elseif ($m['tipus'] === 'number' && (!ctype_digit($v) || (int) $v < 1)) {
                $hibak[$n] = sprintf(__('%s: pozitív egész számot adj meg.', 'sdh-sales'), (string) $m['cimke']);
            } elseif ($m['tipus'] === 'url' && !wp_http_validate_url(preg_match('~^https?://~i', $v) ? $v : 'https://' . $v)) {
                $hibak[$n] = __('A webcím hibás.', 'sdh-sales');
            } elseif ($m['tipus'] === 'select' && !in_array($v, (array) ($m['opciok'] ?? []), true)) {
                $hibak[$n] = sprintf(__('Válassz a listából: %s.', 'sdh-sales'), (string) $m['cimke']);
            }

            $adat[$n] = $m['tipus'] === 'email' ? strtolower(sanitize_email($v)) : $v;
        }

        return ['adat' => $adat, 'hibak' => $hibak];
    }

    /** @param array<string, mixed> $adat */
    public static function lead_ment(string $forras, array $adat, string $email = '', string $allapot = 'uj'): int
    {
        global $wpdb;

        $wpdb->insert(SDH_Sales_Schema::t('lead'), [
            'forras'  => substr(sanitize_key($forras), 0, 20),
            'email'   => strtolower(mb_substr($email, 0, 190)),
            'adat'    => (string) wp_json_encode($adat, JSON_UNESCAPED_UNICODE),
            'allapot' => $allapot,
            'ido'     => current_time('mysql', true),
            'ip_hash' => SDH_Sales::ip_hash(),
        ]);

        $id = (int) $wpdb->insert_id;
        SDH_Sales_Naplo::ir('lead', 'Új érdeklődés (' . $forras . ') #' . $id . '.', 'info', 'lead', $id);
        do_action('sdh_sales_lead', $id, $forras, $adat);

        return $id;
    }

    /** @param array<string, string> $sorok */
    public static function ertesit(string $targy, array $sorok, string $valasz = ''): void
    {
        $c = sanitize_email((string) SDH_Sales::b('email.ertesites', ''));
        $c = is_email($c) ? $c : (string) get_option('admin_email');

        if (!is_email($c)) {
            return;
        }

        $t = '';

        foreach ($sorok as $k => $v) {
            $t .= $k . ': ' . $v . "\n";
        }

        $fej = is_email($valasz) ? ['Reply-To: ' . $valasz] : [];
        wp_mail($c, '[' . SDH_Sales::termeknev() . '] ' . $targy, $t, $fej);
    }

    public static function rest_lead(WP_REST_Request $q)
    {
        $tipus = sanitize_key((string) $q->get_param('tipus'));

        if (!in_array($tipus, self::TIPUSOK, true)) {
            return SDH_Sales_Rest::hiba(__('Ismeretlen űrlap.', 'sdh-sales'), 400, 'urlap');
        }

        $v = SDH_Sales_Biztonsag::ellenoriz($q);

        if (is_wp_error($v)) {
            return $v;
        }

        if (empty($q->get_param('adatkezeles'))) {
            return new WP_REST_Response(['ok' => false, 'message' => __('Ellenőrizd a megjelölt mezőket.', 'sdh-sales'), 'hibak' => ['adatkezeles' => __('Az elküldéshez fogadd el az adatkezelési tájékoztatót.', 'sdh-sales')]], 400);
        }

        $e = self::ellenoriz(self::mezok($tipus), (array) $q->get_params());

        if ($e['hibak'] !== []) {
            return new WP_REST_Response(['ok' => false, 'message' => __('Ellenőrizd a megjelölt mezőket.', 'sdh-sales'), 'hibak' => $e['hibak']], 400);
        }

        $forras = ['kapcsolat' => 'kapcsolat', 'ajanlat' => 'ajanlat', 'partner' => 'partner'][$tipus];
        $id     = self::lead_ment($forras, $e['adat'] + ['_oldal' => esc_url_raw((string) $q->get_param('oldal'))], $e['adat']['email']);
        $cim    = ['kapcsolat' => __('Új üzenet', 'sdh-sales'), 'ajanlat' => __('Enterprise ajánlatkérés', 'sdh-sales'), 'partner' => __('Partnerjelentkezés', 'sdh-sales')][$tipus];

        $sorok = [];

        foreach (self::mezok($tipus) as $m) {
            if ($e['adat'][$m['nev']] !== '') {
                $sorok[(string) $m['cimke']] = $e['adat'][$m['nev']];
            }
        }

        self::ertesit($cim . ' #' . $id, $sorok, $e['adat']['email']);

        SDH_Sales_Fizetes_Atutalas::level('lead_visszaigazolas', $e['adat']['email'], [
            '_targy'    => __('Megkaptuk az üzenetedet', 'sdh-sales'),
            'nev'       => $e['adat']['nev'],
            'tema'      => $e['adat']['tema'] ?? $cim,
            'uzenet'    => $e['adat']['uzenet'] ?? '',
            'valaszido' => (string) SDH_Sales::b('lead.valaszido', __('egy munkanapon belül', 'sdh-sales')),
            'demo_url'  => (string) SDH_Sales::b('demo.url', ''),
        ]);

        return new WP_REST_Response(['ok' => true, 'uzenet' => __('Köszönjük! Megkaptuk az üzenetedet, hamarosan válaszolunk.', 'sdh-sales')], 201);
    }
}
