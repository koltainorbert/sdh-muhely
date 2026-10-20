<?php
/**
 * SDH Értékesítés – kapcsolat az SDH Központtal (licenc-backend, Központ 2.1+).
 *
 * Két mód (`kozpont.mod`):
 *   - `helyi`  – a Központ ugyanezen a WordPressen fut: közvetlen PHP-hívás `SDH_Kozpont_Kiszolgalas::feldolgoz()`.
 *   - `tavoli` – aláírt POST a `{kozpont.url}` Központnak (`licenc/v1/kiszolgalas` – semleges névtér; ha a Központ még
 *     nem ismeri (404), a régi `sdh-kozpont/v1/kiszolgalas`; `?rest_route=` alakban, hogy sima permalinknél és a
 *     Központ saját REST-előtagjától függetlenül is menjen – ez szerver–szerver kérés, a vevő nem látja): törzs `{adat: base64(json), alairas: base64(Ed25519)}`, a kérésben `ido` és egyszer használható
 *     `nonce`. Az értékesítő webhely saját kulcspárja: titkos `SDH_Sales::titkos('kulcspar_titkos')`, nyilvános a
 *     `kozpont.sajat_nyilvanos` beállításban (ezt kell a Központ Beállítások › Értékesítő webhely mezőjébe másolni).
 *     A választ a Központ kulcsával ellenőrizzük: `kozpont.nyilvanos` (első kapcsolatkor megjegyezve – TOFU; utána csak az
 *     egyező kulcs fogadható el), a válaszban vissza kell jönnie a mi `nonce`-unknak, és az idő ±300 mp.
 *
 * API:
 *   kuld(array $keres): array  – a Központ ellenőrzött válasza, vagy {ok:false, uzenet (magyar), atmeneti (újrapróbálható)}
 *   mod(): 'helyi' | 'tavoli'
 *   nyilvanos(): string        – a saját nyilvános kulcs (base64), szükség esetén elkészíti a kulcspárt
 *   kozpont_cim(): string      – a Központ címe, amit a vevő a Műhelyben megad (helyi módban ez a webhely)
 * Admin REST: GET /admin/kozpont (állapot), POST /admin/kozpont/proba, POST /admin/kozpont/kulcs_felejt (TOFU törlése),
 *   POST /admin/kozpont/kulcspar_uj (új saját kulcspár – utána a Központban is cserélni kell).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Kozpont_Kapcsolat
{
    public const IDOKORLAT = 20;
    public const IDO_TURES = 300;
    public const TITOK     = 'kulcspar_titkos';
    public const UT        = '/licenc/v1/kiszolgalas';
    public const UT_REGI   = '/sdh-kozpont/v1/kiszolgalas';

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/admin/kozpont', 'GET', [self::class, 'rest_allapot'], 'admin');
        SDH_Sales_Rest::ut('/admin/kozpont/proba', 'POST', [self::class, 'rest_proba'], 'admin', ['kozpont_proba', 10, 60]);
        SDH_Sales_Rest::ut('/admin/kozpont/kulcs_felejt', 'POST', [self::class, 'rest_kulcs_felejt'], 'admin', ['kozpont_kulcs', 10, 60]);
        SDH_Sales_Rest::ut('/admin/kozpont/kulcspar_uj', 'POST', [self::class, 'rest_kulcspar_uj'], 'admin', ['kozpont_kulcs', 10, 60]);
    }

    /* =================================================================
     * Mód, kulcsok
     * ============================================================== */

    public static function mod(): string
    {
        return (string) SDH_Sales::b('kozpont.mod', 'helyi') === 'tavoli' ? 'tavoli' : 'helyi';
    }

    public static function helyi_elerheto(): bool
    {
        return class_exists('SDH_Kozpont_Kiszolgalas') && method_exists('SDH_Kozpont_Kiszolgalas', 'feldolgoz');
    }

    /** A Központ címe (a vevő ezt adja meg a Műhely Licenc lapján). */
    public static function kozpont_cim(): string
    {
        if (self::mod() === 'helyi') {
            return home_url('/');
        }

        $u = trim((string) SDH_Sales::b('kozpont.url', ''));

        return $u !== '' ? trailingslashit(esc_url_raw($u)) : '';
    }

    /**
     * A saját Ed25519 kulcspár (nyers bájtok). Ha nincs (vagy a titok nem bontható ki), újat készít.
     *
     * @return array{titkos: string, nyilvanos: string}
     */
    public static function kulcspar(bool $uj = false): array
    {
        $t = $uj ? '' : base64_decode(SDH_Sales::titkos(self::TITOK), true);

        if (is_string($t) && strlen($t) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $p = sodium_crypto_sign_publickey_from_secretkey($t);

            if ((string) SDH_Sales::b('kozpont.sajat_nyilvanos', '') !== base64_encode($p)) {
                SDH_Sales::b_ment(['kozpont.sajat_nyilvanos' => base64_encode($p)], 'kozpont');
            }

            return ['titkos' => $t, 'nyilvanos' => $p];
        }

        $kp = sodium_crypto_sign_keypair();
        $t  = sodium_crypto_sign_secretkey($kp);
        $p  = sodium_crypto_sign_publickey($kp);

        SDH_Sales::titkos_ment(self::TITOK, base64_encode($t));
        SDH_Sales::b_ment(['kozpont.sajat_nyilvanos' => base64_encode($p)], 'kozpont');
        SDH_Sales_Naplo::ir('kozpont', 'Új aláíró kulcspár készült az értékesítő webhelynek. A nyilvános kulcsot a Központ beállításaiba kell másolni.', 'figyel');

        return ['titkos' => $t, 'nyilvanos' => $p];
    }

    public static function nyilvanos(): string
    {
        return base64_encode(self::kulcspar()['nyilvanos']);
    }

    /* =================================================================
     * Küldés
     * ============================================================== */

    /**
     * @param array<string, mixed> $keres a szerződés szerinti kérés (muvelet, rendeles, vevo, licenc, licenc_kulcs?)
     * @return array<string, mixed>
     */
    public static function kuld(array $keres): array
    {
        $keres = (array) apply_filters('sdh_sales_kozpont_keres', $keres);
        $v     = self::mod() === 'helyi' ? self::helyi($keres) : self::tavoli($keres);

        if (empty($v['ok'])) {
            $v += ['uzenet' => 'A Központ hibát jelzett.', 'atmeneti' => false];
        }

        return $v;
    }

    /**
     * @param array<string, mixed> $keres
     * @return array<string, mixed>
     */
    private static function helyi(array $keres): array
    {
        if (!self::helyi_elerheto()) {
            return self::hiba('A Központ (2.1 vagy újabb) nincs bekapcsolva ezen a webhelyen. Kapcsold be, vagy állítsd a kapcsolatot „távoli” módra a Központ címével.', true);
        }

        try {
            $v = SDH_Kozpont_Kiszolgalas::feldolgoz($keres);
        } catch (Throwable $e) {
            SDH_Sales_Naplo::ir('kozpont', 'Központ (helyi) kivétel: ' . mb_substr($e->getMessage(), 0, 300), 'hiba');

            return self::hiba('A Központ feldolgozás közben hibát jelzett. Részletek a naplóban.', true);
        }

        if (!is_array($v)) {
            return self::hiba('A Központ válasza hibás.', true);
        }

        return empty($v['ok']) ? ['ok' => false, 'uzenet' => (string) ($v['uzenet'] ?? 'A Központ elutasította a kérést.'), 'atmeneti' => false] : $v;
    }

    /**
     * @param array<string, mixed> $keres
     * @return array<string, mixed>
     */
    private static function tavoli(array $keres): array
    {
        $cim = self::kozpont_cim();

        if ($cim === '') {
            return self::hiba('Nincs megadva a Központ címe (Beállítások › Központ).', true);
        }

        // Titkosítatlan kapcsolaton az első válasz kulcsát (TOFU) bárki az útvonalon meghamisíthatná: csak https,
        // kivéve a gépen belüli (fejlesztői) címet.
        $host = strtolower((string) wp_parse_url($cim, PHP_URL_HOST));

        if (strtolower((string) wp_parse_url($cim, PHP_URL_SCHEME)) !== 'https' && !in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return self::hiba('A Központ címe csak https:// kezdetű lehet (Beállítások › Központ).', false);
        }

        $kp    = self::kulcspar();
        $nonce = SDH_Sales::token(24);
        $adat  = $keres + ['ido' => time(), 'nonce' => $nonce, 'kuldo' => home_url('/')];
        $raw   = (string) wp_json_encode($adat);
        $torzs = (string) wp_json_encode(['adat' => base64_encode($raw), 'alairas' => base64_encode(sodium_crypto_sign_detached($raw, $kp['titkos']))]);
        $kuld  = static fn (string $ut) => wp_remote_post(add_query_arg('rest_route', $ut, $cim), [
            'timeout'     => self::IDOKORLAT,
            'redirection' => 0,
            'headers'     => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body'        => $torzs,
        ]);

        $r = $kuld(self::UT);

        // 2.1 előtti / semleges névtér nélküli Központ: a régi útvonal (ugyanaz a nonce – az első kérés el sem jutott hozzá).
        if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 404) {
            $r = $kuld(self::UT_REGI);
        }

        if (is_wp_error($r)) {
            return self::hiba('A Központ nem érhető el (' . $r->get_error_message() . '). A rendszer később újrapróbálja.', true);
        }

        $kod = (int) wp_remote_retrieve_response_code($r);

        if ($kod === 401 || $kod === 403) {
            return self::hiba('A Központ elutasította a kérést. Ellenőrizd, hogy a Központ beállításaiban (Értékesítő webhely) ennek a webhelynek a nyilvános kulcsa szerepel-e, és az IP-lista engedi-e ezt a szervert.', true);
        }

        if ($kod === 404) {
            return self::hiba('A Központon nincs kiszolgálási végpont – frissítsd a Központot 2.1-re, és ellenőrizd a címét.', true);
        }

        if ($kod !== 200) {
            return self::hiba('A Központ hibát jelzett (HTTP ' . $kod . '). A rendszer később újrapróbálja.', true);
        }

        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        $v = is_array($j) ? self::valasz_ellenoriz($j, $nonce) : 'A Központ válasza nem értelmezhető.';

        if (is_string($v)) {
            SDH_Sales_Naplo::ir('kozpont', 'A Központ válasza elutasítva: ' . $v, 'hiba');

            return self::hiba($v, true);
        }

        return empty($v['ok']) ? ['ok' => false, 'uzenet' => (string) ($v['uzenet'] ?? 'A Központ elutasította a kérést.'), 'atmeneti' => false] : $v;
    }

    /**
     * A Központ aláírt válaszának ellenőrzése (TOFU, aláírás, nonce, idő). Siker: az adat; hiba: magyar üzenet.
     *
     * @param array<string, mixed> $valasz
     * @return array<string, mixed>|string
     */
    public static function valasz_ellenoriz(array $valasz, string $nonce, ?int $most = null)
    {
        $raw = base64_decode((string) ($valasz['adat'] ?? ''), true);
        $sig = base64_decode((string) ($valasz['alairas'] ?? ''), true);
        $kk  = (string) ($valasz['kozpont_kulcs'] ?? '');

        if ($raw === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'A Központ válasza hibás (nincs érvényes aláírás).';
        }

        $ismert = (string) SDH_Sales::b('kozpont.nyilvanos', '');
        $pub    = base64_decode($ismert !== '' ? $ismert : $kk, true);

        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return 'A Központ kulcsa hibás.';
        }

        if ($ismert !== '' && $kk !== '' && !hash_equals($ismert, $kk)) {
            return 'A Központ kulcsa megváltozott. Ha szándékos (új Központ), az adminban töröld a megjegyzett kulcsot.';
        }

        if (!sodium_crypto_sign_verify_detached($sig, $raw, $pub)) {
            return 'A Központ válaszának aláírása nem érvényes.';
        }

        $adat = json_decode($raw, true);

        if (!is_array($adat)) {
            return 'A Központ válasza nem értelmezhető.';
        }

        if (!hash_equals($nonce, (string) ($adat['nonce'] ?? ''))) {
            return 'A Központ válasza nem erre a kérésre szól (visszajátszás?).';
        }

        if (abs(($most ?? time()) - (int) ($adat['ido'] ?? 0)) > self::IDO_TURES) {
            return 'A Központ válasza elavult – ellenőrizd a két szerver óráját.';
        }

        if ($ismert === '') {
            SDH_Sales::b_ment(['kozpont.nyilvanos' => base64_encode($pub)], 'kozpont');
            SDH_Sales_Naplo::ir('kozpont', 'A Központ kulcsa megjegyezve (első kapcsolat).', 'info');
        }

        return $adat;
    }

    /** @return array{ok: false, uzenet: string, atmeneti: bool} */
    private static function hiba(string $uzenet, bool $atmeneti): array
    {
        return ['ok' => false, 'uzenet' => $uzenet, 'atmeneti' => $atmeneti];
    }

    /* =================================================================
     * Admin REST
     * ============================================================== */

    /** @return array<string, mixed> */
    public static function allapot(): array
    {
        return [
            'mod'               => self::mod(),
            'kozpont_cim'       => self::kozpont_cim(),
            'helyi_elerheto'    => self::helyi_elerheto(),
            'sajat_nyilvanos'   => self::nyilvanos(),
            'kozpont_nyilvanos' => self::mod() === 'helyi' && self::helyi_elerheto() && method_exists('SDH_Kozpont', 'kulcspar')
                ? base64_encode(SDH_Kozpont::kulcspar()['nyilvanos'])
                : (string) SDH_Sales::b('kozpont.nyilvanos', ''),
        ];
    }

    public static function rest_allapot(): WP_REST_Response
    {
        return rest_ensure_response(self::allapot());
    }

    /** @return WP_REST_Response|WP_Error */
    public static function rest_proba()
    {
        $t = microtime(true);
        $v = self::kuld(['muvelet' => 'proba']);
        $ms = (int) round((microtime(true) - $t) * 1000);

        SDH_Sales_Naplo::ir('kozpont', 'Központ-próba (' . self::mod() . '): ' . (!empty($v['ok']) ? 'rendben, ' . $ms . ' ms' : (string) $v['uzenet']), !empty($v['ok']) ? 'siker' : 'figyel');

        if (empty($v['ok'])) {
            return SDH_Sales_Rest::hiba((string) $v['uzenet'], 502, 'kozpont');
        }

        return rest_ensure_response(['ok' => true, 'uzenet' => 'A Központ elérhető (' . self::mod() . ' mód, ' . $ms . ' ms, Központ ' . sanitize_text_field((string) ($v['verzio'] ?? '')) . ').', 'ms' => $ms] + self::allapot());
    }

    public static function rest_kulcs_felejt(): WP_REST_Response
    {
        SDH_Sales::b_ment(['kozpont.nyilvanos' => ''], 'kozpont');

        return rest_ensure_response(['ok' => true, 'uzenet' => 'A Központ kulcsa törölve – a következő kapcsolatkor újra megjegyezzük.'] + self::allapot());
    }

    public static function rest_kulcspar_uj(): WP_REST_Response
    {
        self::kulcspar(true);

        return rest_ensure_response(['ok' => true, 'uzenet' => 'Új kulcspár készült. Másold az új nyilvános kulcsot a Központ beállításaiba, különben a kiszolgálás leáll.'] + self::allapot());
    }
}
