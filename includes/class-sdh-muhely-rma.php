<?php
/**
 * RMA – az ügyfél saját munkalap-oldala, QR-kód, vonalkód és levelezés.
 *
 * Minden munkalap kap egy titkos, véletlen azonosítót (rma_token). A
 * QR-kód erre a címre mutat: /rma/<token>. Az oldal zárva nyílik meg;
 * az ügyfél a telefonszámával (06301234567) és a munkalap sorszámával
 * oldja fel. Utána látja:
 *  - a munkalap aktuális állapotát és az állapotváltások idejét,
 *  - az eszközt, a hibákat, a tételeket és a fizetendő összeget,
 *  - a szerviz üzeneteit, és válaszolni is tud.
 *
 * Az ügyfél válasza bekerül a CRM-be (munkalap részletei → RMA /
 * Üzenetek lapfül), és e-mailben is megérkezik a beállított címre.
 * A CRM-ből írt üzenet kérésre e-mailben is elmegy az ügyfélnek.
 *
 * A vonalkód a munkalapszámot hordozza (Code 128), a nyomtatható
 * címkén a QR-kód mellett áll.
 *
 * Biztonság: a token 96 bites véletlen, kitalálni nem lehet; a feloldás
 * mellé a telefonszám és a sorszám is kell. A próbálkozás IP-nként és
 * munkalaponként korlátozott. A feloldott állapotot aláírt süti tartja,
 * ami a telefonszám megváltozásakor magától érvénytelenné válik.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Rma
{
    /** Az útvonal: /rma/<token>. */
    public const ALAP = 'rma';

    /** Query var szép URL nélkül: /?sdh_rma=<token>. */
    public const QUERY_VAR = 'sdh_rma';

    /** A nyomtatható címke: /?sdh_muhely_cimke=<munkalap id>. */
    public const CIMKE_VAR = 'sdh_muhely_cimke';

    /** Beállítások optionje. */
    private const OPT = 'sdh_muhely_rma';

    /** Feloldási próbák: ennyi / ablak / IP + munkalap. */
    private const PROBA_MAX    = 8;
    private const PROBA_ABLAK  = 15 * MINUTE_IN_SECONDS;

    /** Ügyfélüzenetek: ennyi / óra / munkalap. */
    private const UZENET_MAX = 10;

    /** Üzenet leghosszabb hossza (karakter). */
    private const UZENET_HOSSZ = 3000;

    /** A süti élettartama. */
    private const SUTI_ELET = 90 * DAY_IN_SECONDS;

    /** Az állapotszínek az ügyféloldalon (a CRM CSS-változói ott nincsenek). */
    private const SZINEK = [
        'sarga'    => '#c99400',
        'zold'     => '#2e9d4f',
        'szurke'   => '#6f757d',
        'kek'      => '#2f6fd6',
        'narancs'  => '#e2711d',
        'olajzold' => '#6f7f22',
        'lila'     => '#8a4fc7',
        'piros'    => '#d33a3a',
    ];

    public static function init(): void
    {
        add_action('init', [self::class, 'szabalyok']);
        add_filter('query_vars', [self::class, 'query_vars']);
        add_filter('request', [self::class, 'utvonal_tartalek'], 1);
        add_action('template_redirect', [self::class, 'fogadas'], 0);

        add_action('sdh_muhely_munkalap_allapot', [self::class, 'naplo_ir'], 10, 3);
        add_action('sdh_muhely_sema_frissult', [self::class, 'naplo_potlas']);

        add_action('wp_ajax_sdh_muhely_uzenet_kuld', [self::class, 'ajax_uzenet_kuld']);
        add_action('wp_ajax_sdh_muhely_uzenet_olvasva', [self::class, 'ajax_olvasva']);
    }

    /* =================================================================
     * Táblák, beállítások
     * ============================================================== */

    private static function munkalap_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('munkalap');
    }

    private static function naplo_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('munkalap_naplo');
    }

    private static function uzenet_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('uzenet');
    }

    /**
     * @return array{nyilvanos_cim: string, szerviz_nev: string, elerhetoseg: string, ertesites_email: string, ugyfel_email: bool}
     */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        return [
            'nyilvanos_cim'   => isset($m['nyilvanos_cim']) ? (string) $m['nyilvanos_cim'] : '',
            'szerviz_nev'     => isset($m['szerviz_nev']) && $m['szerviz_nev'] !== '' ? (string) $m['szerviz_nev'] : (string) get_bloginfo('name'),
            'elerhetoseg'     => isset($m['elerhetoseg']) ? (string) $m['elerhetoseg'] : '',
            'ertesites_email' => isset($m['ertesites_email']) && $m['ertesites_email'] !== '' ? (string) $m['ertesites_email'] : (string) get_option('admin_email'),
            'ugyfel_email'    => !isset($m['ugyfel_email']) || !empty($m['ugyfel_email']),
        ];
    }

    /** A beállítások doboza (a Beállítások képernyő hívja). */
    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Ügyféloldal (RMA, QR-kód)</h2>

            <p class="sdh-sugo">
                Minden munkalap kap egy QR-kódot. Az ügyfél a telefonjával beolvassa, a
                telefonszámával és a munkalap sorszámával belép, és látja a javítás
                állapotát, a fizetendő összeget és az üzeneteket – válaszolni is tud.
                A QR-kód csak akkor működik az ügyfél telefonján, ha ez a rendszer
                <strong>az internetről elérhető</strong>; ha nem, add meg alább azt a
                nyilvános címet, ahol elérhető.
            </p>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="rma_nyilvanos_cim">Nyilvános cím</label>
                    <input type="text" name="rma_nyilvanos_cim" id="rma_nyilvanos_cim"
                           value="<?php echo esc_attr($b['nyilvanos_cim']); ?>"
                           placeholder="<?php echo esc_attr(self::alap_cim()); ?>">
                    <span class="sdh-mezo__sugo">
                        Üresen hagyva: <code><?php echo esc_html(self::alap_cim()); ?></code>.
                        A cím végére kerül az azonosító; ha máshová kell, írd a címbe a
                        <code>{token}</code> jelölőt.
                    </span>
                </div>

                <div class="sdh-mezo">
                    <label for="rma_szerviz_nev">Szerviz neve az ügyféloldalon</label>
                    <input type="text" name="rma_szerviz_nev" id="rma_szerviz_nev"
                           value="<?php echo esc_attr($b['szerviz_nev']); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="rma_ertesites_email">Ügyfélüzenetek ide érkeznek (e-mail)</label>
                    <input type="email" name="rma_ertesites_email" id="rma_ertesites_email"
                           value="<?php echo esc_attr($b['ertesites_email']); ?>">
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="rma_elerhetoseg">Elérhetőség az ügyféloldal alján</label>
                    <textarea name="rma_elerhetoseg" id="rma_elerhetoseg" rows="3"
                    ><?php echo esc_textarea($b['elerhetoseg']); ?></textarea>
                    <span class="sdh-mezo__sugo">Cím, telefon, nyitvatartás – ahogy az ügyfélnek mutatni akarod.</span>
                </div>
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="rma_ugyfel_email" id="rma_ugyfel_email" value="1"
                    <?php checked($b['ugyfel_email']); ?>>
                <label for="rma_ugyfel_email">Új üzenetnél alapból e-mail értesítés az ügyfélnek (ha van e-mail címe)</label>
            </div>
        </div>
        <?php
    }

    /** A beállítások mentése (a Beállítások mentése hívja, a jog és a nonce már ellenőrzött). */
    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $cim = isset($_POST['rma_nyilvanos_cim']) ? trim(sanitize_text_field(wp_unslash($_POST['rma_nyilvanos_cim']))) : '';

        if ($cim !== '' && !preg_match('#^https?://#i', $cim)) {
            $cim = 'https://' . $cim;
        }

        update_option(self::OPT, [
            'nyilvanos_cim'   => $cim,
            'szerviz_nev'     => isset($_POST['rma_szerviz_nev']) ? sanitize_text_field(wp_unslash($_POST['rma_szerviz_nev'])) : '',
            'elerhetoseg'     => isset($_POST['rma_elerhetoseg']) ? sanitize_textarea_field(wp_unslash($_POST['rma_elerhetoseg'])) : '',
            'ertesites_email' => isset($_POST['rma_ertesites_email']) ? sanitize_email(wp_unslash($_POST['rma_ertesites_email'])) : '',
            'ugyfel_email'    => !empty($_POST['rma_ugyfel_email']),
        ]);
        // phpcs:enable
    }

    /* =================================================================
     * Token és címek
     * ============================================================== */

    /** Új, véletlen azonosító (24 hexa karakter = 96 bit). */
    public static function uj_token(): string
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * A munkalap tokenje; ha még nincs, most kap (és el is mentjük).
     */
    public static function token(object $munkalap): string
    {
        global $wpdb;

        $token = (string) ($munkalap->rma_token ?? '');

        if ($token !== '') {
            return $token;
        }

        $token = self::uj_token();

        $wpdb->update(self::munkalap_tabla(), ['rma_token' => $token], ['id' => (int) $munkalap->id]);
        $munkalap->rma_token = $token;

        return $token;
    }

    /** A gyári cím (a saját WordPress alatt), tokenhely nélkül. */
    private static function alap_cim(): string
    {
        return get_option('permalink_structure')
            ? home_url('/' . self::ALAP . '/')
            : home_url('/?' . self::QUERY_VAR . '=');
    }

    /** Az ügyféloldal címe – ez kerül a QR-kódba. */
    public static function url(string $token): string
    {
        $alap = self::beallitas()['nyilvanos_cim'];

        if ($alap === '') {
            return self::alap_cim() . $token . (get_option('permalink_structure') ? '/' : '');
        }

        if (str_contains($alap, '{token}')) {
            return str_replace('{token}', $token, $alap);
        }

        if (str_ends_with($alap, '=')) {
            return $alap . $token;
        }

        return rtrim($alap, '/') . '/' . $token . '/';
    }

    /** A nyomtatható címke címe (belépéshez kötött). */
    public static function cimke_url(int $munkalap_id): string
    {
        return add_query_arg(self::CIMKE_VAR, $munkalap_id, home_url('/'));
    }

    /* =================================================================
     * Útvonal
     * ============================================================== */

    public static function szabalyok(): void
    {
        add_rewrite_rule(
            '^' . self::ALAP . '/([A-Za-z0-9]{8,40})/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function query_vars(array $vars): array
    {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    /**
     * A /rma/<token> cím akkor is működjön, ha a rewrite szabály kiesett.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public static function utvonal_tartalek(array $vars): array
    {
        if (!empty($vars[self::QUERY_VAR])) {
            return $vars;
        }

        $token = self::token_a_cimbol(
            isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '',
            (string) wp_parse_url(home_url('/'), PHP_URL_PATH)
        );

        return $token === null ? $vars : [self::QUERY_VAR => $token];
    }

    /** A kért címből a token, vagy null, ha a cím nem az RMA-oldalé. */
    public static function token_a_cimbol(string $keres, string $alap_ut): ?string
    {
        $ut      = (string) parse_url($keres, PHP_URL_PATH);
        $alap_ut = '/' . trim($alap_ut, '/');
        $alap_ut = $alap_ut === '/' ? '' : $alap_ut;

        if ($alap_ut !== '' && !str_starts_with($ut, $alap_ut . '/')) {
            return null;
        }

        $ut = substr($ut, strlen($alap_ut));

        if (preg_match('#^/' . preg_quote(self::ALAP, '#') . '/([A-Za-z0-9]{8,40})/?$#', $ut, $t) !== 1) {
            return null;
        }

        return $t[1];
    }

    /** A kérés kezelése: ügyféloldal vagy nyomtatható címke. */
    public static function fogadas(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET[self::CIMKE_VAR])) {
            self::cimke_oldal((int) $_GET[self::CIMKE_VAR]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            exit;
        }

        $token = get_query_var(self::QUERY_VAR);

        if ($token === '' || $token === null) {
            return;
        }

        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $token) ?? '';

        // A frontend nem jár az admin_init-en: a sémafrissítés itt is fusson.
        SDH_Muhely_Schema::frissites_ha_kell();

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        header('X-Frame-Options: DENY', true);

        $munkalap = $token !== '' ? self::munkalap_tokennel($token) : null;

        if ($munkalap === null || (int) $munkalap->munkalap_szam <= 0) {
            status_header(404);
            self::ugyfel_keret('Ismeretlen munkalap', static function (): void {
                echo '<section class="k"><h2>Ismeretlen munkalap</h2><p>Ez a hivatkozás nem érvényes, vagy a munkalap még nincs rögzítve. '
                    . 'Kérjük, olvassa be újra a munkalapon lévő QR-kódot, vagy keresse a szervizt.</p></section>';
            });
            exit;
        }

        $ugyfel = self::ugyfel((int) $munkalap->ugyfel_id);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $muvelet = isset($_POST['sdh_rma_muvelet']) ? sanitize_key(wp_unslash($_POST['sdh_rma_muvelet'])) : '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $muvelet !== '') {
            self::post_kezeles($muvelet, $munkalap, $ugyfel);
            exit;
        }

        status_header(200);

        if (!self::feloldva($munkalap, $ugyfel)) {
            self::zar_oldal($munkalap);
            exit;
        }

        self::rma_oldal($munkalap, $ugyfel);
        exit;
    }

    private static function munkalap_tokennel(string $token): ?object
    {
        global $wpdb;

        $sor = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::munkalap_tabla() . ' WHERE rma_token = %s AND rma_token <> %s LIMIT 1', $token, '')
        );

        return $sor ?: null;
    }

    private static function munkalap(int $id): ?object
    {
        global $wpdb;

        $sor = $id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::munkalap_tabla() . ' WHERE id = %d', $id))
            : null;

        return $sor ?: null;
    }

    private static function ugyfel(int $id): ?object
    {
        global $wpdb;

        $sor = $id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', $id))
            : null;

        return $sor ?: null;
    }

    private static function eszkoz(int $id): ?object
    {
        global $wpdb;

        $sor = $id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' WHERE id = %d', $id))
            : null;

        return $sor ?: null;
    }

    /* =================================================================
     * Feloldás (telefonszám + sorszám)
     * ============================================================== */

    /**
     * Telefonszám egységes alakja: csak számjegy, belföldi 06-os formában.
     * +36 30 400 4636 → 06304004636; 0036… → 06…
     */
    public static function telefon_norm(string $telefon): string
    {
        $d = preg_replace('/\D/', '', $telefon) ?? '';

        if (str_starts_with($d, '0036')) {
            $d = '06' . substr($d, 4);
        } elseif (str_starts_with($d, '36') && strlen($d) >= 10) {
            $d = '06' . substr($d, 2);
        }

        return $d;
    }

    /**
     * Egyezik-e a beírt telefonszám és sorszám a munkalappal.
     */
    public static function egyezik(object $munkalap, ?object $ugyfel, string $telefon, string $sorszam): bool
    {
        if ($ugyfel === null) {
            return false;
        }

        $szam = preg_replace('/\D/', '', $sorszam) ?? '';

        if ($szam === '' || (int) $szam !== (int) $munkalap->munkalap_szam) {
            return false;
        }

        $be = self::telefon_norm($telefon);

        if (strlen($be) < 8) {
            return false;
        }

        foreach ([(string) $ugyfel->telefon, (string) ($ugyfel->telefon2 ?? '')] as $sajat) {
            $sajat = self::telefon_norm($sajat);

            if ($sajat !== '' && hash_equals($sajat, $be)) {
                return true;
            }
        }

        return false;
    }

    private static function suti_nev(object $munkalap): string
    {
        return 'sdh_rma_' . substr((string) $munkalap->rma_token, 0, 12);
    }

    /** A feloldott állapot aláírása: a telefonszám változásakor érvénytelen. */
    private static function alairas(object $munkalap, ?object $ugyfel): string
    {
        $adat = implode('|', [
            'rma',
            (string) $munkalap->rma_token,
            (int) $munkalap->munkalap_szam,
            (int) $munkalap->ugyfel_id,
            $ugyfel ? self::telefon_norm((string) $ugyfel->telefon) : '',
            $ugyfel ? self::telefon_norm((string) ($ugyfel->telefon2 ?? '')) : '',
        ]);

        return hash_hmac('sha256', $adat, wp_salt('auth'));
    }

    private static function feloldva(object $munkalap, ?object $ugyfel): bool
    {
        if ($ugyfel === null) {
            return false;
        }

        $nev = self::suti_nev($munkalap);

        return isset($_COOKIE[$nev])
            && hash_equals(self::alairas($munkalap, $ugyfel), (string) wp_unslash($_COOKIE[$nev]));
    }

    private static function suti_beallit(string $nev, string $ertek, int $lejar): void
    {
        setcookie($nev, $ertek, [
            'expires'  => $lejar,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function proba_kulcs(object $munkalap): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return 'sdh_rma_p_' . md5($ip . '|' . (int) $munkalap->id);
    }

    /** Az ügyfél űrlapjának aláírt jele (CSRF ellen). */
    private static function urlap_jel(object $munkalap): string
    {
        return hash_hmac('sha256', 'valasz|' . (string) $munkalap->rma_token, wp_salt('nonce'));
    }

    /** Az ügyfél POST-jai: belépés, válasz, kilépés. Mindig átirányít (PRG). */
    private static function post_kezeles(string $muvelet, object $munkalap, ?object $ugyfel): void
    {
        // Ugyanarra a címre irányítunk vissza, ahonnan az ügyfél jött – relatív
        // útvonallal, hogy nyilvános (proxyzott) címen is ott maradjon.
        $ut     = (string) strtok(isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/', '?');
        $vissza = $ut;

        if (self::token_a_cimbol($ut, (string) wp_parse_url(home_url('/'), PHP_URL_PATH)) === null) {
            $vissza = add_query_arg(self::QUERY_VAR, (string) $munkalap->rma_token, $ut);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ($muvelet === 'belep') {
            $kulcs = self::proba_kulcs($munkalap);
            $db    = (int) get_transient($kulcs);

            if ($db >= self::PROBA_MAX) {
                wp_safe_redirect(add_query_arg('h', 'sok', $vissza));
                return;
            }

            $telefon = isset($_POST['telefon']) ? sanitize_text_field(wp_unslash($_POST['telefon'])) : '';
            $sorszam = isset($_POST['sorszam']) ? sanitize_text_field(wp_unslash($_POST['sorszam'])) : '';

            if (!self::egyezik($munkalap, $ugyfel, $telefon, $sorszam)) {
                set_transient($kulcs, $db + 1, self::PROBA_ABLAK);
                wp_safe_redirect(add_query_arg('h', 'rossz', $vissza));
                return;
            }

            delete_transient($kulcs);
            self::suti_beallit(self::suti_nev($munkalap), self::alairas($munkalap, $ugyfel), time() + self::SUTI_ELET);
            wp_safe_redirect($vissza);
            return;
        }

        if ($muvelet === 'kilep') {
            self::suti_beallit(self::suti_nev($munkalap), '', time() - DAY_IN_SECONDS);
            wp_safe_redirect($vissza);
            return;
        }

        if ($muvelet === 'valasz') {
            if (!self::feloldva($munkalap, $ugyfel)) {
                wp_safe_redirect($vissza);
                return;
            }

            $jel = isset($_POST['jel']) ? (string) wp_unslash($_POST['jel']) : '';

            if (!hash_equals(self::urlap_jel($munkalap), $jel)) {
                wp_safe_redirect(add_query_arg('h', 'lejart', $vissza));
                return;
            }

            $szoveg = isset($_POST['szoveg']) ? trim(sanitize_textarea_field(wp_unslash($_POST['szoveg']))) : '';
            $szoveg = mb_substr($szoveg, 0, self::UZENET_HOSSZ);

            if ($szoveg === '') {
                wp_safe_redirect(add_query_arg('h', 'ures', $vissza) . '#uzenetek');
                return;
            }

            if (self::friss_ugyfeluzenetek((int) $munkalap->id) >= self::UZENET_MAX) {
                wp_safe_redirect(add_query_arg('h', 'sokuzenet', $vissza) . '#uzenetek');
                return;
            }

            self::uzenet_ment((int) $munkalap->id, (int) $munkalap->ugyfel_id, 'be', 'rma', $szoveg, 0);
            wp_safe_redirect(add_query_arg('ok', 'kuldve', $vissza) . '#uzenetek');
            return;
        }
        // phpcs:enable

        wp_safe_redirect($vissza);
    }

    /* =================================================================
     * Állapotnapló
     * ============================================================== */

    /** Állapotváltás naplózása (a Munkalap modul eseménye hívja). */
    public static function naplo_ir(int $munkalap_id, string $elozo, string $uj): void
    {
        global $wpdb;

        if ($munkalap_id <= 0 || $uj === '') {
            return;
        }

        $wpdb->insert(self::naplo_tabla(), [
            'munkalap_id' => $munkalap_id,
            'allapot'     => $uj,
            'elozo'       => $elozo,
            'felhasznalo' => get_current_user_id(),
            'letrehozva'  => current_time('mysql'),
        ]);
    }

    /**
     * Sémafrissítéskor: a napló nélküli munkalapok kapnak egy kezdősort
     * (a mostani állapot, a létrehozás idejével), hogy az ügyféloldalon
     * a régebbi lapoknál se legyen üres a történet.
     */
    public static function naplo_potlas(): void
    {
        global $wpdb;

        $m = self::munkalap_tabla();
        $n = self::naplo_tabla();

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$n} (munkalap_id, allapot, elozo, felhasznalo, letrehozva)
                 SELECT m.id, m.allapot, '', m.letrehozo, COALESCE(m.letrehozva, m.modositva, %s)
                 FROM {$m} m
                 LEFT JOIN {$n} n ON n.munkalap_id = m.id
                 WHERE n.id IS NULL",
                current_time('mysql')
            )
        );
    }

    /**
     * @return array<int, object>
     */
    public static function naplo(int $munkalap_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::naplo_tabla() . ' WHERE munkalap_id = %d ORDER BY letrehozva DESC, id DESC', $munkalap_id)
        ) ?: [];
    }

    /* =================================================================
     * Üzenetek
     * ============================================================== */

    /**
     * @return array<int, object>
     */
    public static function uzenetek(int $munkalap_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::uzenet_tabla() . ' WHERE munkalap_id = %d ORDER BY letrehozva ASC, id ASC', $munkalap_id)
        ) ?: [];
    }

    /**
     * Üzenetszámlálók a lapfül címéhez.
     *
     * @return array{osszes: int, uj: int}
     */
    public static function szamlalo(int $munkalap_id): array
    {
        global $wpdb;

        $sor = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS osszes, SUM(CASE WHEN irany = 'be' AND olvasva = 0 THEN 1 ELSE 0 END) AS uj
                 FROM " . self::uzenet_tabla() . ' WHERE munkalap_id = %d',
                $munkalap_id
            )
        );

        return [
            'osszes' => $sor ? (int) $sor->osszes : 0,
            'uj'     => $sor ? (int) $sor->uj : 0,
        ];
    }

    private static function friss_ugyfeluzenetek(int $munkalap_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::uzenet_tabla() . " WHERE munkalap_id = %d AND irany = 'be' AND letrehozva >= %s",
                $munkalap_id,
                gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - HOUR_IN_SECONDS)
            )
        );
    }

    /**
     * Üzenet mentése és e-mail értesítés.
     *
     * Bejövő (ügyfél) üzenetnél a szerviz kap e-mailt; kimenőnél az
     * ügyfél, ha $email_ugyfelnek igaz és van érvényes e-mail címe.
     *
     * @return int Az új üzenet azonosítója (0 = hiba).
     */
    public static function uzenet_ment(int $munkalap_id, int $ugyfel_id, string $irany, string $csatorna, string $szoveg, int $felhasznalo, bool $email_ugyfelnek = false): int
    {
        global $wpdb;

        $irany = $irany === 'be' ? 'be' : 'ki';

        $ok = $wpdb->insert(self::uzenet_tabla(), [
            'munkalap_id' => $munkalap_id,
            'ugyfel_id'   => $ugyfel_id,
            'irany'       => $irany,
            'csatorna'    => $csatorna,
            'szoveg'      => $szoveg,
            'felhasznalo' => $felhasznalo,
            'olvasva'     => $irany === 'ki' ? 1 : 0,
            'email_kuldve' => 0,
            'letrehozva'  => current_time('mysql'),
        ]);

        if ($ok === false) {
            return 0;
        }

        $id       = (int) $wpdb->insert_id;
        $munkalap = self::munkalap($munkalap_id);
        $ugyfel   = self::ugyfel($ugyfel_id);
        $kuldve   = false;

        if ($munkalap !== null) {
            $kuldve = $irany === 'be'
                ? self::email_szerviznek($munkalap, $ugyfel, $szoveg)
                : ($email_ugyfelnek && self::email_ugyfelnek($munkalap, $ugyfel, $szoveg));
        }

        if ($kuldve) {
            $wpdb->update(self::uzenet_tabla(), ['email_kuldve' => 1], ['id' => $id]);
        }

        return $id;
    }

    private static function email_szerviznek(object $munkalap, ?object $ugyfel, string $szoveg): bool
    {
        $b  = self::beallitas();
        $cel = sanitize_email($b['ertesites_email']);

        if ($cel === '' || !is_email($cel)) {
            return false;
        }

        $szam = SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam);
        $nev  = $ugyfel ? (string) $ugyfel->nev : '';

        $torzs = "Új üzenet érkezett az ügyféloldalról.\n\n"
            . 'Munkalap: ' . $szam . "\n"
            . 'Ügyfél: ' . $nev . ($ugyfel && $ugyfel->telefon !== '' ? ' (' . $ugyfel->telefon . ')' : '') . "\n\n"
            . "Üzenet:\n" . $szoveg . "\n\n"
            . 'A CRM-ben: ' . SDH_Muhely_Modulok::frontend_url() . "\n"
            . '(Munkalap részletei → RMA / Üzenetek lapfül)';

        $fejlec = [];

        if ($ugyfel && is_email((string) $ugyfel->email)) {
            $fejlec[] = 'Reply-To: ' . self::fejlec_nev($nev) . ' <' . $ugyfel->email . '>';
        }

        return (bool) wp_mail($cel, '[SDH Műhely] Ügyfélüzenet – munkalap ' . $szam . ($nev !== '' ? ' – ' . $nev : ''), $torzs, $fejlec);
    }

    private static function email_ugyfelnek(object $munkalap, ?object $ugyfel, string $szoveg): bool
    {
        if ($ugyfel === null || !is_email((string) $ugyfel->email)) {
            return false;
        }

        $b    = self::beallitas();
        $szam = SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam);

        $torzs = 'Kedves ' . (string) $ugyfel->nev . "!\n\n"
            . 'Üzenetet küldtünk a(z) ' . $szam . " számú munkalapjával kapcsolatban:\n\n"
            . $szoveg . "\n\n"
            . "A javítás állapotát megnézheti és válaszolhat itt:\n"
            . self::url(self::token($munkalap)) . "\n"
            . "(Belépés: telefonszám és munkalapszám.)\n\n"
            . $b['szerviz_nev']
            . ($b['elerhetoseg'] !== '' ? "\n" . $b['elerhetoseg'] : '');

        $fejlec = [];
        $valasz = sanitize_email($b['ertesites_email']);

        if ($valasz !== '' && is_email($valasz)) {
            $fejlec[] = 'Reply-To: ' . self::fejlec_nev($b['szerviz_nev']) . ' <' . $valasz . '>';
        }

        return (bool) wp_mail((string) $ugyfel->email, $b['szerviz_nev'] . ' – üzenet a(z) ' . $szam . ' munkalaphoz', $torzs, $fejlec);
    }

    /** Név e-mail fejlécbe: sortörés és idézőjel nélkül. */
    private static function fejlec_nev(string $nev): string
    {
        $nev = trim(str_replace(["\r", "\n", '"', '<', '>'], ' ', $nev));

        return $nev === '' ? '' : '"' . $nev . '"';
    }

    /** Üzenet küldése a CRM-ből (AJAX). */
    public static function ajax_uzenet_kuld(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $szoveg   = isset($_POST['szoveg']) ? trim(sanitize_textarea_field(wp_unslash($_POST['szoveg']))) : '';
        $email    = !empty($_POST['email']);
        $munkalap = self::munkalap($id);

        if ($munkalap === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen munkalap.']);
        }

        if ($szoveg === '') {
            wp_send_json_error(['uzenet' => 'Írd be az üzenetet.']);
        }

        $uj = self::uzenet_ment($id, (int) $munkalap->ugyfel_id, 'ki', 'crm', mb_substr($szoveg, 0, self::UZENET_HOSSZ * 3), get_current_user_id(), $email);

        if ($uj <= 0) {
            wp_send_json_error(['uzenet' => 'Az adatbázis visszautasította az üzenetet.']);
        }

        self::olvasottra((int) $munkalap->id);

        global $wpdb;
        $kuldve = (int) $wpdb->get_var($wpdb->prepare('SELECT email_kuldve FROM ' . self::uzenet_tabla() . ' WHERE id = %d', $uj)) === 1;

        wp_send_json_success([
            'html'   => self::uzenetek_html((int) $munkalap->id, 'crm'),
            'email'  => $kuldve,
            'szamlalo' => self::szamlalo((int) $munkalap->id),
        ]);
    }

    /** A bejövő üzenetek olvasottra állítása (AJAX, a lapfül megnyitásakor). */
    public static function ajax_olvasva(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        self::olvasottra($id);

        wp_send_json_success(['szamlalo' => self::szamlalo($id)]);
    }

    private static function olvasottra(int $munkalap_id): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::uzenet_tabla() . " SET olvasva = 1 WHERE munkalap_id = %d AND irany = 'be' AND olvasva = 0",
                $munkalap_id
            )
        );
    }

    /**
     * Az üzenetszál HTML-je. $nezet: crm (a dolgozónak) vagy ugyfel.
     */
    public static function uzenetek_html(int $munkalap_id, string $nezet): string
    {
        $uzenetek = self::uzenetek($munkalap_id);
        $b        = self::beallitas();

        if ($uzenetek === []) {
            return '<p class="sdh-uz-ures">' . esc_html(
                $nezet === 'crm' ? 'Még nincs üzenet ehhez a munkalaphoz.' : 'Még nincs üzenet. Kérdését alább írhatja meg.'
            ) . '</p>';
        }

        // A CRM-ben a legfrissebb van felül (az alsó panel alacsony), az
        // ügyféloldalon időrendben, mint egy csevegés.
        if ($nezet === 'crm') {
            $uzenetek = array_reverse($uzenetek);
        }

        $html = '';

        foreach ($uzenetek as $u) {
            $sajat = $nezet === 'crm' ? $u->irany === 'ki' : $u->irany === 'be';

            if ($u->irany === 'ki') {
                $ki = $nezet === 'crm'
                    ? ((int) $u->felhasznalo > 0 && ($f = get_userdata((int) $u->felhasznalo)) ? (string) $f->display_name : 'Szerviz')
                    : $b['szerviz_nev'];
            } else {
                $ki = $nezet === 'crm' ? 'Ügyfél' : 'Ön';
            }

            $jelek = [];

            if ($nezet === 'crm') {
                if ($u->irany === 'be' && (int) $u->olvasva === 0) {
                    $jelek[] = 'új';
                }

                if ((int) $u->email_kuldve === 1) {
                    $jelek[] = 'e-mail elküldve';
                }
            }

            $html .= sprintf(
                '<div class="sdh-uz%s"><div class="sdh-uz__fej"><b>%s</b> <span>%s%s</span></div><div class="sdh-uz__szoveg">%s</div></div>',
                $sajat ? ' sdh-uz--sajat' : '',
                esc_html($ki),
                esc_html(SDH_Muhely_Munkalap::datumido_megjelenit((string) $u->letrehozva)),
                $jelek !== [] ? esc_html(' · ' . implode(' · ', $jelek)) : '',
                nl2br(esc_html((string) $u->szoveg))
            );
        }

        return $html;
    }

    /* =================================================================
     * CRM: a munkalap RMA / Üzenetek lapfüle
     * ============================================================== */

    /** A lapfül címe: „RMA / Üzenetek (3, 1 új)". */
    public static function ful_cim(int $munkalap_id): string
    {
        $sz = self::szamlalo($munkalap_id);

        if ($sz['osszes'] === 0) {
            return 'RMA / Üzenetek';
        }

        return 'RMA / Üzenetek (' . $sz['osszes'] . ($sz['uj'] > 0 ? ', ' . $sz['uj'] . ' új' : '') . ')';
    }

    /** A lapfül tartalma a részletpanelen. */
    public static function crm_panel(object $munkalap, ?object $ugyfel): void
    {
        $id        = (int) $munkalap->id;
        $szamozott = (int) $munkalap->munkalap_szam > 0;
        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $b         = self::beallitas();
        $van_email = $ugyfel && is_email((string) $ugyfel->email);

        echo '<div class="sdh-rma-panel" data-sdh-rma="' . $id . '">';

        // --- Kódok --------------------------------------------------------
        echo '<div class="sdh-rma-panel__kodok">';

        if ($szamozott) {
            $url  = self::url(self::token($munkalap));
            $szam = SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam);

            echo '<div class="sdh-rma-panel__qr">' . SDH_Muhely_Kodok::qr_svg($url) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
            echo '<div class="sdh-rma-panel__vonalkod">' . SDH_Muhely_Kodok::vonalkod_svg($szam) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
            printf(
                '<div class="sdh-rma-panel__link"><input type="text" readonly value="%s" aria-label="Az ügyféloldal címe">'
                . '<button type="button" class="sdh-gomb" data-sdh-rma-masol>Másolás</button></div>',
                esc_attr($url)
            );
            printf(
                '<div class="sdh-rma-panel__gombok"><a class="sdh-gomb" href="%s" target="_blank" rel="noopener">Címke nyomtatása</a>'
                . '<a class="sdh-gomb" href="%s" target="_blank" rel="noopener">Ügyféloldal megnyitása</a></div>',
                esc_url(self::cimke_url($id)),
                esc_url($url)
            );
            echo '<p class="sdh-rma-panel__sugo">Az ügyfél a <b>telefonszámával</b> (pl. 06304004636) és a <b>munkalap sorszámával</b> ('
                . esc_html($szam) . ') lép be.</p>';
        } else {
            echo '<p class="sdh-reszlet__ures">A QR-kód és a vonalkód akkor készül el, amikor a munkalap sorszámot kap (számozott állapotba kerül).</p>';
        }

        echo '</div>';

        // --- Üzenetek -----------------------------------------------------
        echo '<div class="sdh-rma-panel__uzenetek">';
        echo '<h4>Üzenetek az ügyféllel</h4>';

        if ($ugyfel !== null) {
            printf(
                '<form class="sdh-uz-urlap" data-sdh-uzenet-urlap data-id="%d">'
                . '<textarea name="szoveg" rows="3" placeholder="Üzenet az ügyfélnek (az ügyféloldalon látja, és válaszolhat rá)"></textarea>'
                . '<div class="sdh-uz-urlap__lab">'
                . '<label class="sdh-uz-urlap__jelolo"><input type="checkbox" name="email" value="1"%s%s> E-mail értesítés is%s</label>'
                . '<button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Küldés</button></div>'
                . '<p class="sdh-uz-urlap__hiba" data-sdh-uzenet-hiba hidden></p></form>',
                $id,
                $van_email && $b['ugyfel_email'] ? ' checked' : '',
                $van_email ? '' : ' disabled',
                esc_html($van_email ? ' (' . $ugyfel->email . ')' : ' – az ügyfélnek nincs e-mail címe')
            );
        } else {
            echo '<p class="sdh-reszlet__ures">Üzenetet ügyfélhez rendelt munkalapon lehet küldeni.</p>';
        }

        echo '<div class="sdh-uz-szal" data-sdh-uzenetek>' . self::uzenetek_html($id, 'crm') . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput

        echo '</div>';

        // --- Állapottörténet ----------------------------------------------
        echo '<div class="sdh-rma-panel__naplo"><h4>Állapottörténet</h4>';
        $naplo = self::naplo($id);

        if ($naplo === []) {
            echo '<p class="sdh-reszlet__ures">Még nincs bejegyzés.</p>';
        } else {
            echo '<ol class="sdh-rma-naplo">';

            foreach ($naplo as $sor) {
                $ki = (int) $sor->felhasznalo > 0 && ($f = get_userdata((int) $sor->felhasznalo)) ? (string) $f->display_name : '';

                printf(
                    '<li>%s <span>%s%s</span></li>',
                    SDH_Muhely_Munkalap::jelveny((string) $sor->allapot, $allapotok), // phpcs:ignore WordPress.Security.EscapeOutput
                    esc_html(SDH_Muhely_Munkalap::datumido_megjelenit((string) $sor->letrehozva)),
                    $ki !== '' ? esc_html(' · ' . $ki) : ''
                );
            }

            echo '</ol>';
        }

        echo '</div></div>';
    }

    /* =================================================================
     * Nyomtatható címke (belépéshez kötött)
     * ============================================================== */

    private static function cimke_oldal(int $id): void
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(add_query_arg(self::CIMKE_VAR, $id, home_url('/'))));
            exit;
        }

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_die('Nincs jogosultságod ehhez.', '', ['response' => 403]);
        }

        SDH_Muhely_Schema::frissites_ha_kell();

        $munkalap = self::munkalap($id);

        if ($munkalap === null || (int) $munkalap->munkalap_szam <= 0) {
            wp_die('Ennek a munkalapnak még nincs sorszáma, ezért címke sem nyomtatható.', '', ['response' => 404]);
        }

        $ugyfel = self::ugyfel((int) $munkalap->ugyfel_id);
        $eszkoz = self::eszkoz((int) $munkalap->eszkoz_id);
        $szam   = SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam);
        $url    = self::url(self::token($munkalap));
        $b      = self::beallitas();

        nocache_headers();
        status_header(200);

        ?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>Címke – <?php echo esc_html($szam); ?></title>
<style>
    @page { size: auto; margin: 8mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font: 13px/1.35 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #000; background: #fff; }
    .eszkoztar { padding: 12px 16px; background: #f3f3f3; border-bottom: 1px solid #ddd; display: flex; gap: 8px; align-items: center; }
    .eszkoztar button { font: inherit; padding: 6px 14px; border: 1px solid #999; border-radius: 6px; background: #fff; cursor: pointer; }
    .cimke { width: 92mm; margin: 12px; border: 1px dashed #999; padding: 4mm; display: grid; grid-template-columns: 30mm 1fr; gap: 3mm; }
    .cimke .qr svg { width: 30mm; height: 30mm; display: block; }
    .cimke h1 { font-size: 11px; margin: 0 0 1mm; font-weight: 600; }
    .cimke .szam { font-size: 22px; font-weight: 700; letter-spacing: .02em; line-height: 1.1; }
    .cimke .sor { font-size: 11px; }
    .cimke .vk { grid-column: 1 / -1; }
    .cimke .vk svg { width: 100%; height: 14mm; display: block; }
    .cimke .sugo { grid-column: 1 / -1; font-size: 9.5px; color: #333; }
    @media print { .eszkoztar { display: none; } .cimke { margin: 0; border: 0; } }
</style>
</head>
<body>
<div class="eszkoztar">
    <button type="button" onclick="window.print()">Nyomtatás</button>
    <span>Munkalap <?php echo esc_html($szam); ?> – címke (QR + vonalkód)</span>
</div>
<div class="cimke">
    <div class="qr"><?php echo SDH_Muhely_Kodok::qr_svg($url); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
    <div>
        <h1><?php echo esc_html($b['szerviz_nev']); ?></h1>
        <div class="szam"><?php echo esc_html($szam); ?></div>
        <?php if ($ugyfel) : ?><div class="sor"><?php echo esc_html((string) $ugyfel->nev); ?></div><?php endif; ?>
        <?php if ($eszkoz) : ?><div class="sor"><?php echo esc_html(SDH_Muhely_Eszkoz::megnevezes($eszkoz)); ?></div><?php endif; ?>
        <div class="sor">Átvéve: <?php echo esc_html(SDH_Muhely_Munkalap::datum_megjelenit((string) $munkalap->keszult)); ?></div>
    </div>
    <div class="vk"><?php echo SDH_Muhely_Kodok::vonalkod_svg($szam); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
    <div class="sugo">Javítás állapota: olvassa be a QR-kódot, és lépjen be a telefonszámával és a munkalap sorszámával.</div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
</body>
</html>
        <?php
    }

    /* =================================================================
     * Ügyféloldal
     * ============================================================== */

    private static function penz(float $osszeg): string
    {
        return number_format($osszeg, 0, ',', "\u{00A0}") . "\u{00A0}Ft";
    }

    /** Az ügyféloldal kerete: önálló, mobilra tervezett lap. */
    private static function ugyfel_keret(string $cim, callable $tartalom): void
    {
        $b = self::beallitas();

        ?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html($cim . ' – ' . $b['szerviz_nev']); ?></title>
<style>
    :root {
        --hatter: #f4f5f7; --lap: #fff; --szoveg: #1d2127; --halvany: #6a7079; --vonal: #e3e5e9;
        --kiemel: #c8102e; --kiemel-hatter: #fdecef; --fo: #1f5fbf; --fo-szoveg: #fff; --ok: #2e9d4f;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --hatter: #15171b; --lap: #1e2126; --szoveg: #e8eaed; --halvany: #9aa1ab; --vonal: #30343b;
            --kiemel: #ff5c70; --kiemel-hatter: #3a1a20; --fo: #4d8be8; --fo-szoveg: #fff; --ok: #4fc274;
        }
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--hatter); color: var(--szoveg); font: 16px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    header.fej { background: var(--lap); border-bottom: 1px solid var(--vonal); padding: 14px 16px; }
    header.fej .nev { font-weight: 700; font-size: 17px; }
    header.fej .al { color: var(--halvany); font-size: 13px; }
    main { max-width: 680px; margin: 0 auto; padding: 16px; display: grid; gap: 12px; }
    .k { background: var(--lap); border: 1px solid var(--vonal); border-radius: 12px; padding: 16px; }
    .k h2 { margin: 0 0 10px; font-size: 15px; text-transform: uppercase; letter-spacing: .04em; color: var(--halvany); font-weight: 600; }
    .k p { margin: 0 0 8px; }
    .szam { font-size: 26px; font-weight: 700; }
    .allapot { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 999px; font-weight: 600; font-size: 17px; color: #fff; margin: 8px 0 4px; }
    .halvany { color: var(--halvany); font-size: 14px; }
    dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; }
    dt { color: var(--halvany); }
    dd { margin: 0; font-weight: 500; overflow-wrap: anywhere; }
    .fizet { border: 2px solid var(--kiemel); background: var(--kiemel-hatter); }
    .fizet .osszeg { font-size: 30px; font-weight: 800; color: var(--kiemel); }
    .fizetve { border-left: 4px solid var(--ok); }
    table { width: 100%; border-collapse: collapse; font-size: 15px; }
    td, th { padding: 7px 0; border-bottom: 1px solid var(--vonal); text-align: left; vertical-align: top; }
    td.j, th.j { text-align: right; white-space: nowrap; padding-left: 10px; }
    tfoot td { font-weight: 700; border-bottom: 0; }
    .brutto { color: var(--kiemel); font-weight: 700; }
    ol.ido { list-style: none; margin: 0; padding: 0; }
    ol.ido li { position: relative; padding: 0 0 12px 22px; }
    ol.ido li::before { content: ""; position: absolute; left: 4px; top: 7px; width: 10px; height: 10px; border-radius: 50%; background: var(--pont, #999); }
    ol.ido li::after { content: ""; position: absolute; left: 8px; top: 20px; bottom: 0; width: 2px; background: var(--vonal); }
    ol.ido li:last-child::after { display: none; }
    ol.ido b { display: block; }
    .megj { border-left: 4px solid var(--fo); }
    label { display: block; font-weight: 600; margin: 10px 0 4px; }
    input, textarea { width: 100%; font: inherit; padding: 12px; border: 1px solid var(--vonal); border-radius: 10px; background: var(--lap); color: var(--szoveg); }
    textarea { min-height: 100px; resize: vertical; }
    button { font: inherit; font-weight: 600; padding: 12px 18px; border: 0; border-radius: 10px; background: var(--fo); color: var(--fo-szoveg); cursor: pointer; margin-top: 12px; width: 100%; }
    button.masodlagos { background: transparent; color: var(--halvany); border: 1px solid var(--vonal); width: auto; padding: 8px 14px; font-weight: 500; }
    .hiba { background: var(--kiemel-hatter); color: var(--kiemel); padding: 10px 12px; border-radius: 10px; margin: 0 0 10px; }
    .siker { background: color-mix(in srgb, var(--ok) 15%, var(--lap)); color: var(--ok); padding: 10px 12px; border-radius: 10px; margin: 0 0 10px; }
    footer { max-width: 680px; margin: 0 auto; padding: 8px 16px 32px; color: var(--halvany); font-size: 14px; }
    footer form { margin-top: 10px; }
    .sdh-uz-ures { color: var(--halvany); margin: 0 0 8px; }
    .sdh-uz { padding: 10px 12px; border-radius: 10px; background: var(--hatter); margin: 0 0 8px; max-width: 90%; }
    .sdh-uz--sajat { margin-left: auto; background: color-mix(in srgb, var(--fo) 14%, var(--lap)); }
    .sdh-uz__fej { font-size: 13px; color: var(--halvany); margin-bottom: 2px; }
    .sdh-uz__fej b { color: var(--szoveg); }
</style>
</head>
<body>
<header class="fej">
    <div class="nev"><?php echo esc_html($b['szerviz_nev']); ?></div>
    <div class="al">Javítás állapota</div>
</header>
<main>
<?php call_user_func($tartalom); ?>
</main>
<footer>
    <?php if ($b['elerhetoseg'] !== '') : ?>
        <div><?php echo nl2br(esc_html($b['elerhetoseg'])); ?></div>
    <?php endif; ?>
</footer>
</body>
</html>
        <?php
    }

    /** Hibaüzenet a ?h= paraméterből. */
    private static function visszajelzes(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $h  = isset($_GET['h']) ? sanitize_key(wp_unslash($_GET['h'])) : '';
        $ok = isset($_GET['ok']) ? sanitize_key(wp_unslash($_GET['ok'])) : '';
        // phpcs:enable

        $hibak = [
            'rossz'     => 'A telefonszám vagy a munkalap sorszáma nem egyezik. Ellenőrizze, és próbálja újra.',
            'sok'       => 'Túl sok sikertelen próbálkozás. Kérjük, próbálja újra 15 perc múlva.',
            'lejart'    => 'Az űrlap lejárt. Kérjük, írja meg újra az üzenetet.',
            'ures'      => 'Az üzenet üres volt.',
            'sokuzenet' => 'Rövid időn belül sok üzenet érkezett. Kérjük, próbálja később, vagy hívjon minket.',
        ];

        if (isset($hibak[$h])) {
            return '<p class="hiba" role="alert">' . esc_html($hibak[$h]) . '</p>';
        }

        if ($ok === 'kuldve') {
            return '<p class="siker" role="status">Üzenetét megkaptuk, hamarosan válaszolunk.</p>';
        }

        return '';
    }

    /** A zárt oldal: telefonszám + sorszám. */
    private static function zar_oldal(object $munkalap): void
    {
        self::ugyfel_keret('Belépés', static function () use ($munkalap): void {
            ?>
            <section class="k">
                <h2>Munkalap megtekintése</h2>
                <p>A javítás állapotának megtekintéséhez adja meg a telefonszámát és a munkalap sorszámát (a munkalapon / címkén találja).</p>
                <?php echo self::visszajelzes(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <form method="post" autocomplete="on">
                    <input type="hidden" name="sdh_rma_muvelet" value="belep">
                    <label for="telefon">Telefonszám</label>
                    <input type="tel" id="telefon" name="telefon" inputmode="tel" autocomplete="tel" placeholder="06301234567" required>
                    <label for="sorszam">Munkalap sorszáma</label>
                    <input type="text" id="sorszam" name="sorszam" inputmode="numeric" autocomplete="off" placeholder="pl. <?php echo esc_attr(preg_replace('/\d/', '0', SDH_Muhely_Munkalap::szam_formaz(1)) ?: '1'); ?>" required>
                    <button type="submit">Megnyitás</button>
                </form>
            </section>
            <?php
        });
    }

    /** A feloldott RMA-oldal. */
    private static function rma_oldal(object $munkalap, ?object $ugyfel): void
    {
        self::ugyfel_keret('Munkalap ' . SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam), static function () use ($munkalap, $ugyfel): void {
            $allapotok = SDH_Muhely_Munkalap::allapotok();
            $kulcs     = (string) $munkalap->allapot;
            $allapot   = $allapotok[$kulcs] ?? ['nev' => $kulcs, 'szin' => 'szurke'];
            $szin      = self::SZINEK[$allapot['szin']] ?? self::SZINEK['szurke'];
            $naplo     = self::naplo((int) $munkalap->id);
            $utolso    = $naplo !== [] ? (string) $naplo[0]->letrehozva : (string) $munkalap->modositva;
            $eszkoz    = self::eszkoz((int) $munkalap->eszkoz_id);
            $hibak     = SDH_Muhely_Munkalap::hibasorok((int) $munkalap->id);
            $hiba_all  = SDH_Muhely_Munkalap::hiba_allapotok();
            $tetelek   = SDH_Muhely_Tetel::lista((int) $munkalap->id);

            $brutto    = (float) $munkalap->brutto_ertek;
            $fizetett  = (float) $munkalap->fizetett;
            $fizetve   = (int) $munkalap->fizetve === 1;
            $fizetendo = $fizetve ? 0.0 : $brutto - $fizetett;

            echo self::visszajelzes(); // phpcs:ignore WordPress.Security.EscapeOutput

            // --- Állapot ---------------------------------------------------
            ?>
            <section class="k">
                <div class="halvany">Munkalap sorszáma</div>
                <div class="szam"><?php echo esc_html(SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam)); ?></div>
                <div class="allapot" style="background: <?php echo esc_attr($szin); ?>"><?php echo esc_html((string) $allapot['nev']); ?></div>
                <div class="halvany">Utoljára módosult: <?php echo esc_html(SDH_Muhely_Munkalap::datumido_megjelenit($utolso)); ?></div>
            </section>
            <?php

            // --- Fizetés ---------------------------------------------------
            if ($brutto > 0 || $fizetett > 0) {
                if ($fizetendo > 0) {
                    ?>
                    <section class="k fizet">
                        <h2>Fizetendő</h2>
                        <div class="osszeg"><?php echo esc_html(self::penz($fizetendo)); ?></div>
                        <dl style="margin-top:10px">
                            <dt>Bruttó végösszeg</dt><dd class="brutto"><?php echo esc_html(self::penz($brutto)); ?></dd>
                            <?php if ($fizetett > 0) : ?>
                                <dt>Befizetett előleg</dt><dd><?php echo esc_html(self::penz($fizetett)); ?></dd>
                            <?php endif; ?>
                        </dl>
                    </section>
                    <?php
                } else {
                    ?>
                    <section class="k fizetve">
                        <h2>Fizetés</h2>
                        <dl>
                            <dt>Bruttó végösszeg</dt><dd class="brutto"><?php echo esc_html(self::penz($brutto)); ?></dd>
                            <dt>Állapot</dt><dd><?php echo esc_html($fizetve ? 'Kifizetve' . ($munkalap->fizetes_ideje ? ' (' . SDH_Muhely_Munkalap::datum_megjelenit((string) $munkalap->fizetes_ideje) . ')' : '') : 'Nincs fizetendő'); ?></dd>
                        </dl>
                    </section>
                    <?php
                }
            }

            // --- Adatok ----------------------------------------------------
            ?>
            <section class="k">
                <h2>Adatok</h2>
                <dl>
                    <?php if ($ugyfel) : ?><dt>Ügyfél</dt><dd><?php echo esc_html((string) $ugyfel->nev); ?></dd><?php endif; ?>
                    <?php if ($eszkoz) : ?>
                        <dt>Eszköz</dt><dd><?php echo esc_html(SDH_Muhely_Eszkoz::kategoria_cimke((string) $eszkoz->kategoria) . ' – ' . SDH_Muhely_Eszkoz::megnevezes($eszkoz)); ?></dd>
                        <?php
                        $azon = (string) ($eszkoz->imei !== '' ? $eszkoz->imei : $eszkoz->sorozatszam);

                        if ($azon !== '') :
                            ?>
                            <dt><?php echo esc_html($eszkoz->imei !== '' ? 'IMEI' : 'Sorozatszám'); ?></dt>
                            <dd><?php echo esc_html(strlen($azon) > 5 ? str_repeat('•', strlen($azon) - 5) . substr($azon, -5) : $azon); ?></dd>
                        <?php endif; ?>
                    <?php endif; ?>
                    <dt>Átvétel</dt><dd><?php echo esc_html(SDH_Muhely_Munkalap::datum_megjelenit((string) $munkalap->keszult) ?: '—'); ?></dd>
                    <?php if (!empty($munkalap->hatarido)) : ?>
                        <dt>Várható elkészülés</dt><dd><?php echo esc_html(SDH_Muhely_Munkalap::datum_megjelenit((string) $munkalap->hatarido)); ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($munkalap->lezarva)) : ?>
                        <dt>Lezárva</dt><dd><?php echo esc_html(SDH_Muhely_Munkalap::datum_megjelenit((string) $munkalap->lezarva)); ?></dd>
                    <?php endif; ?>
                </dl>
            </section>
            <?php

            // --- Hibák -----------------------------------------------------
            if ($hibak !== []) {
                echo '<section class="k"><h2>Bejelentett hibák</h2><table><tbody>';

                foreach ($hibak as $hiba) {
                    $ha = $hiba_all[(string) $hiba->allapot] ?? null;

                    printf(
                        '<tr><td>%s</td><td class="j">%s</td></tr>',
                        esc_html((string) $hiba->leiras),
                        esc_html($ha ? (string) $ha['nev'] : '')
                    );
                }

                echo '</tbody></table></section>';
            }

            // --- Tételek ---------------------------------------------------
            if ($tetelek !== []) {
                echo '<section class="k"><h2>Elvégzett munka és alkatrészek</h2><table><thead><tr><th>Megnevezés</th><th class="j">Menny.</th><th class="j">Bruttó</th></tr></thead><tbody>';
                $ossz = 0.0;

                foreach ($tetelek as $t) {
                    $ossz += (float) $t->brutto_ertek;
                    $menny = rtrim(rtrim(number_format((float) $t->mennyiseg, 3, ',', ''), '0'), ',');

                    printf(
                        '<tr><td>%s</td><td class="j">%s %s</td><td class="j">%s</td></tr>',
                        esc_html((string) $t->megnevezes),
                        esc_html($menny),
                        esc_html((string) $t->me),
                        esc_html(self::penz((float) $t->brutto_ertek))
                    );
                }

                printf(
                    '</tbody><tfoot><tr><td>Összesen</td><td></td><td class="j brutto">%s</td></tr></tfoot></table></section>',
                    esc_html(self::penz($ossz))
                );
            }

            // --- Állapottörténet -------------------------------------------
            if ($naplo !== []) {
                echo '<section class="k"><h2>Állapottörténet</h2><ol class="ido">';

                foreach ($naplo as $sor) {
                    $a  = $allapotok[(string) $sor->allapot] ?? ['nev' => (string) $sor->allapot, 'szin' => 'szurke'];
                    $sz = self::SZINEK[$a['szin']] ?? self::SZINEK['szurke'];

                    printf(
                        '<li style="--pont: %s"><b>%s</b><span class="halvany">%s</span></li>',
                        esc_attr($sz),
                        esc_html((string) $a['nev']),
                        esc_html(SDH_Muhely_Munkalap::datumido_megjelenit((string) $sor->letrehozva))
                    );
                }

                echo '</ol></section>';
            }

            // --- Megjegyzés és üzenetek -----------------------------------
            $megj = trim((string) ($munkalap->ugyfel_megjegyzes ?? ''));

            if ($megj !== '') {
                echo '<section class="k megj"><h2>Megjegyzés a szerviztől</h2><p>' . nl2br(esc_html($megj)) . '</p></section>';
            }

            ?>
            <section class="k" id="uzenetek">
                <h2>Üzenetek</h2>
                <?php echo self::uzenetek_html((int) $munkalap->id, 'ugyfel'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <form method="post">
                    <input type="hidden" name="sdh_rma_muvelet" value="valasz">
                    <input type="hidden" name="jel" value="<?php echo esc_attr(self::urlap_jel($munkalap)); ?>">
                    <label for="szoveg">Üzenet a szerviznek</label>
                    <textarea id="szoveg" name="szoveg" maxlength="<?php echo (int) self::UZENET_HOSSZ; ?>" required></textarea>
                    <button type="submit">Küldés</button>
                </form>
            </section>

            <section class="k">
                <form method="post">
                    <input type="hidden" name="sdh_rma_muvelet" value="kilep">
                    <button type="submit" class="masodlagos">Kilépés ezen az eszközön</button>
                </form>
            </section>
            <?php
        });
    }
}
