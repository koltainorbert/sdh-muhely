<?php
/**
 * Kétlépcsős belépés (H7, 0.81.0): jelszó + a telefon hitelesítő alkalmazásának kódja (TOTP).
 *
 * - Szabvány TOTP (RFC 6238: SHA-1, 6 jegy, 30 mp), bármelyik hitelesítő alkalmazással megy
 *   (Google / Microsoft Authenticator, Aegis, 2FAS…). A titok fiókonként, titkosítva
 *   (SDH_Muhely_Titok) a felhasználó adatai között; egy kód csak egyszer fogadható el.
 * - 10 tartalék kód (egyszer használható, csak a lenyomatuk tárolva) a telefon elvesztésére.
 * - Szabály (Beállítások › Jogosultságok): kikapcsolva / választható / adminisztrátoroknak
 *   kötelező / mindenkinek kötelező. Kötelező esetben aki még nem állította be, a belépéskor
 *   állítja be (a bejelentkező oldalon, QR-kóddal).
 * - „Megbízható gép”: a kód N napig nem kell ezen a böngészőn (aláírt süti; a titok cseréje,
 *   a visszaállítás és a „gépek visszavonása” érvényteleníti).
 * - Minden más belépési út (a wp-login.php beküldése, bármely wp_signon) elutasítva annál,
 *   akinek a kód kell: a kód csak a saját bejelentkezőn adható meg.
 * - Vészkapcsoló a fejlesztőnek: define('SDH_MUHELY_KETLEPCSOS_KI', true); a wp-config.php-ban.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Ketlepcsos
{
    public const OPT        = 'sdh_muhely_ketlepcsos';
    public const KIKAPCSOLO = 'SDH_MUHELY_KETLEPCSOS_KI';

    public const META_TITOK    = 'sdh_muhely_2fa_titok';
    public const META_TARTALEK = 'sdh_muhely_2fa_tartalek';
    public const META_LEPES    = 'sdh_muhely_2fa_lepes';
    public const META_IDO      = 'sdh_muhely_2fa_ido';
    public const META_GEN      = 'sdh_muhely_2fa_gen';

    public const SUTI = 'sdh_muhely_megbizhato';

    public const LEPES_MP      = 30;
    public const JEGY          = 6;
    public const ABLAK         = 1;   // ± ennyi 30 mp-es lépést fogad el (óraeltérés)
    public const TARTALEK_DB   = 10;
    public const FUGGO_MP      = 600; // a jelszó után ennyi ideje van a kódra
    public const PROBA_MAX     = 5;   // egy belépési kísérletben ennyi rossz kód
    public const ZAR_MAX       = 10;  // fiókonként ennyi rossz kód után…
    public const ZAR_MP        = 900; // …ennyi ideig nem fogad kódot
    public const MEGBIZHATO_MAX = 90;

    private const NONCE = 'sdh_muhely_2fa';

    /** A saját bejelentkező első lépése alatt igaz: ilyenkor a jelszó-ellenőrzés még nem utasít el. */
    private static bool $sajat = false;

    public static function init(): void
    {
        add_filter('authenticate', [self::class, 'kapu'], 99, 1);

        add_action('wp_ajax_sdh_muhely_ketlepcsos_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_ketlepcsos_bekapcsol', [self::class, 'ajax_bekapcsol']);
        add_action('wp_ajax_sdh_muhely_ketlepcsos_kikapcsol', [self::class, 'ajax_kikapcsol']);
        add_action('wp_ajax_sdh_muhely_ketlepcsos_tartalek', [self::class, 'ajax_tartalek']);
        add_action('wp_ajax_sdh_muhely_ketlepcsos_gepek', [self::class, 'ajax_gepek']);
        add_action('wp_ajax_sdh_muhely_ketlepcsos_visszaallit', [self::class, 'ajax_visszaallit']);
    }

    /* =================================================================
     * Szabály
     * ============================================================== */

    /** @return array<string, string> */
    public static function szabalyok(): array
    {
        return [
            'ki'         => 'Kikapcsolva',
            'valaszthato' => 'Választható – aki akarja, bekapcsolja magának',
            'admin'      => 'Adminisztrátoroknak kötelező, a többieknek választható',
            'mindenki'   => 'Mindenkinek kötelező',
        ];
    }

    /** @return array{szabaly: string, megbizhato_nap: int} */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];
        $s = (string) ($m['szabaly'] ?? 'valaszthato');

        return [
            'szabaly'        => isset(self::szabalyok()[$s]) ? $s : 'valaszthato',
            'megbizhato_nap' => max(0, min(self::MEGBIZHATO_MAX, (int) ($m['megbizhato_nap'] ?? 30))),
        ];
    }

    /** Ki van kapcsolva az egész (szabály vagy a fejlesztő vészkapcsolója). */
    public static function ki(): bool
    {
        return (defined(self::KIKAPCSOLO) && constant(self::KIKAPCSOLO)) || self::beallitas()['szabaly'] === 'ki';
    }

    public static function admin_felhasznalo(WP_User $u): bool
    {
        return user_can($u, SDH_Muhely_Admin_UI::ADMIN_JOG) || user_can($u, SDH_Muhely_Admin_UI::FEJLESZTO_JOG);
    }

    /** Kötelező-e neki (akkor is, ha még nem állította be). */
    public static function kotelezo(WP_User $u): bool
    {
        if (self::ki()) {
            return false;
        }

        $s = self::beallitas()['szabaly'];

        return $s === 'mindenki' || ($s === 'admin' && self::admin_felhasznalo($u));
    }

    /** Be van-e állítva neki (van titka). */
    public static function aktiv(int $uid): bool
    {
        return self::titok($uid) !== '';
    }

    /** Kell-e neki kód a belépéshez (vagy a beállítás). */
    public static function kell(WP_User $u): bool
    {
        return !self::ki() && (self::aktiv((int) $u->ID) || self::kotelezo($u));
    }

    /* =================================================================
     * TOTP
     * ============================================================== */

    public static function base32_kodol(string $bin): string
    {
        $abc  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bitek = '';

        foreach (str_split($bin) as $b) {
            $bitek .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }

        $ki = '';

        foreach (str_split($bitek, 5) as $darab) {
            $ki .= $abc[bindec(str_pad($darab, 5, '0'))];
        }

        return $ki;
    }

    public static function base32_dekodol(string $szoveg): string
    {
        $abc    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $szoveg = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $szoveg));
        $bitek  = '';

        foreach (str_split($szoveg) as $c) {
            if ($c === '') {
                continue;
            }

            $bitek .= str_pad(decbin((int) strpos($abc, $c)), 5, '0', STR_PAD_LEFT);
        }

        $ki = '';

        foreach (str_split($bitek, 8) as $darab) {
            if (strlen($darab) === 8) {
                $ki .= chr((int) bindec($darab));
            }
        }

        return $ki;
    }

    /** Új titok (base32, 160 bit). */
    public static function uj_titok(): string
    {
        return self::base32_kodol(random_bytes(20));
    }

    /** A kód egy adott 30 mp-es lépésre. */
    public static function kod(string $titok_b32, int $lepes): string
    {
        $kulcs = self::base32_dekodol($titok_b32);
        $h     = hash_hmac('sha1', pack('N2', ($lepes >> 32) & 0xffffffff, $lepes & 0xffffffff), $kulcs, true);
        $o     = ord($h[19]) & 0x0f;
        $szam  = (((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3])) % (10 ** self::JEGY);

        return str_pad((string) $szam, self::JEGY, '0', STR_PAD_LEFT);
    }

    public static function most_lepes(?int $ido = null): int
    {
        return intdiv($ido ?? time(), self::LEPES_MP);
    }

    /**
     * Melyik lépésre illik a kód (± ablak), vagy null. Az $utolso lépésig (bezárólag) nem fogad el:
     * egy kód kétszer nem használható.
     */
    public static function illik(string $titok_b32, string $kod, int $utolso = 0, ?int $ido = null): ?int
    {
        $kod = (string) preg_replace('/\D/', '', $kod);

        if (strlen($kod) !== self::JEGY || $titok_b32 === '') {
            return null;
        }

        $most = self::most_lepes($ido);

        for ($d = -self::ABLAK; $d <= self::ABLAK; $d++) {
            $l = $most + $d;

            if ($l > $utolso && hash_equals(self::kod($titok_b32, $l), $kod)) {
                return $l;
            }
        }

        return null;
    }

    /** otpauth:// cím a QR-kódhoz. */
    public static function otpauth(string $titok_b32, WP_User $u): string
    {
        $kiado = class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::rendszer_nev() : 'Műhely';
        $cimke = rawurlencode($kiado) . ':' . rawurlencode((string) $u->user_login);

        return 'otpauth://totp/' . $cimke . '?secret=' . $titok_b32 . '&issuer=' . rawurlencode($kiado)
            . '&algorithm=SHA1&digits=' . self::JEGY . '&period=' . self::LEPES_MP;
    }

    /** A titok négyes csoportokban (kézi beíráshoz). */
    public static function csoportos(string $titok_b32): string
    {
        return trim(implode(' ', str_split($titok_b32, 4)));
    }

    /* =================================================================
     * Tárolás
     * ============================================================== */

    public static function titok(int $uid): string
    {
        $t = (string) get_user_meta($uid, self::META_TITOK, true);

        return $t === '' ? '' : SDH_Muhely_Titok::visszafejt($t);
    }

    /** A kód bekapcsolása: titok + új tartalék kódok (a nyílt kódokat adja vissza – egyszer látszanak). */
    public static function bekapcsol(int $uid, string $titok_b32, int $lepes = 0): array
    {
        update_user_meta($uid, self::META_TITOK, SDH_Muhely_Titok::titkosit($titok_b32));
        update_user_meta($uid, self::META_LEPES, $lepes);
        update_user_meta($uid, self::META_IDO, time());
        self::gepek_visszavon($uid);
        self::naplo($uid, 'Kétlépcsős belépés bekapcsolva');

        return self::tartalek_uj($uid);
    }

    public static function kikapcsol(int $uid, string $miert = 'Kétlépcsős belépés kikapcsolva'): void
    {
        foreach ([self::META_TITOK, self::META_TARTALEK, self::META_LEPES, self::META_IDO] as $k) {
            delete_user_meta($uid, $k);
        }

        self::gepek_visszavon($uid);
        self::naplo($uid, $miert);
    }

    /** @return array<int, string> az új (nyílt) tartalék kódok */
    public static function tartalek_uj(int $uid): array
    {
        $kodok    = [];
        $lenyomat = [];
        $abc      = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // összetéveszthető jelek (0/O, 1/I) nélkül

        for ($i = 0; $i < self::TARTALEK_DB; $i++) {
            $k = '';

            for ($j = 0; $j < 8; $j++) {
                $k .= $abc[random_int(0, strlen($abc) - 1)];
            }

            $kodok[]    = substr($k, 0, 4) . '-' . substr($k, 4);
            $lenyomat[] = self::tartalek_lenyomat($k);
        }

        update_user_meta($uid, self::META_TARTALEK, $lenyomat);

        return $kodok;
    }

    private static function tartalek_lenyomat(string $kod): string
    {
        $kod = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $kod));

        return hash_hmac('sha256', $kod, wp_salt('auth'));
    }

    public static function tartalek_db(int $uid): int
    {
        $l = get_user_meta($uid, self::META_TARTALEK, true);

        return is_array($l) ? count($l) : 0;
    }

    /* =================================================================
     * Kód ellenőrzése (TOTP vagy tartalék kód), zárolás
     * ============================================================== */

    public static function zarolva(int $uid): bool
    {
        return (int) get_transient('sdh_2fa_hiba_' . $uid) >= self::ZAR_MAX;
    }

    /**
     * @return string '' = rendben (totp / tartalek a módja), különben a hibaüzenet
     */
    public static function ellenoriz(int $uid, string $kod, string &$mod = ''): string
    {
        if (self::zarolva($uid)) {
            return 'Túl sok hibás kód. Várj negyedórát, vagy kérd az adminisztrátort.';
        }

        $titok = self::titok($uid);
        $lepes = self::illik($titok, $kod, (int) get_user_meta($uid, self::META_LEPES, true));

        if ($lepes !== null) {
            update_user_meta($uid, self::META_LEPES, $lepes);
            delete_transient('sdh_2fa_hiba_' . $uid);
            $mod = 'totp';

            return '';
        }

        // Tartalék kód (8 jel, kötőjellel vagy anélkül).
        $tiszta = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $kod));
        $lista  = get_user_meta($uid, self::META_TARTALEK, true);
        $lista  = is_array($lista) ? array_values($lista) : [];

        if (strlen($tiszta) === 8) {
            $lny = self::tartalek_lenyomat($tiszta);

            foreach ($lista as $i => $l) {
                if (hash_equals((string) $l, $lny)) {
                    unset($lista[$i]);
                    update_user_meta($uid, self::META_TARTALEK, array_values($lista));
                    delete_transient('sdh_2fa_hiba_' . $uid);
                    self::naplo($uid, 'Belépés tartalék kóddal (maradt: ' . count($lista) . ')');
                    $mod = 'tartalek';

                    return '';
                }
            }
        }

        $db = (int) get_transient('sdh_2fa_hiba_' . $uid) + 1;
        set_transient('sdh_2fa_hiba_' . $uid, $db, self::ZAR_MP);

        if ($db >= self::ZAR_MAX) {
            self::naplo($uid, 'Kétlépcsős belépés zárolva túl sok hibás kód miatt');
        }

        return 'Hibás vagy lejárt kód. Nézd meg a telefonon az aktuálisat.';
    }

    /* =================================================================
     * Megbízható gép (süti)
     * ============================================================== */

    private static function gen(int $uid): int
    {
        return (int) get_user_meta($uid, self::META_GEN, true);
    }

    public static function gepek_visszavon(int $uid): void
    {
        update_user_meta($uid, self::META_GEN, self::gen($uid) + 1);
    }

    private static function alairas(int $uid, int $lejar): string
    {
        return hash_hmac('sha256', $uid . '|' . $lejar . '|' . self::gen($uid) . '|' . substr(hash('sha256', self::titok($uid)), 0, 16), wp_salt('auth'));
    }

    public static function suti_ertek(int $uid, int $lejar): string
    {
        return $uid . '|' . $lejar . '|' . self::alairas($uid, $lejar);
    }

    public static function megbizhato(int $uid, ?string $ertek = null): bool
    {
        if (self::beallitas()['megbizhato_nap'] < 1) {
            return false;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $ertek = $ertek ?? (isset($_COOKIE[self::SUTI . '_' . $uid]) ? (string) wp_unslash($_COOKIE[self::SUTI . '_' . $uid]) : '');
        $d     = explode('|', $ertek);

        return count($d) === 3 && (int) $d[0] === $uid && (int) $d[1] > time() && hash_equals(self::alairas($uid, (int) $d[1]), $d[2]);
    }

    private static function megbizhato_beallit(int $uid): void
    {
        $nap = self::beallitas()['megbizhato_nap'];

        if ($nap < 1 || headers_sent()) {
            return;
        }

        $lejar = time() + $nap * DAY_IN_SECONDS;
        setcookie(self::SUTI . '_' . $uid, self::suti_ertek($uid, $lejar), [
            'expires' => $lejar, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    /* =================================================================
     * Belépés
     * ============================================================== */

    /** Minden más belépési út elutasítva annál, akinek kód kell (a saját bejelentkező első lépése kivétel). */
    public static function kapu($felhasznalo)
    {
        if (self::$sajat || !$felhasznalo instanceof WP_User || !self::kell($felhasznalo)) {
            return $felhasznalo;
        }

        return new WP_Error('sdh_ketlepcsos', 'Ehhez a fiókhoz a kétlépcsős belépés kell: lépj be a rendszer bejelentkező oldalán.');
    }

    /** A jelszó ellenőrzése a kód-kapu nélkül (a saját bejelentkező első lépése). */
    public static function jelszo(string $nev, string $jelszo)
    {
        self::$sajat = true;

        try {
            return wp_authenticate($nev, $jelszo);
        } finally {
            self::$sajat = false;
        }
    }

    /** A belépés befejezése (süti, „wp_login” esemény) – mint a wp_signon. */
    public static function belep(WP_User $u, bool $marad): void
    {
        wp_set_auth_cookie((int) $u->ID, $marad, is_ssl());
        wp_set_current_user((int) $u->ID);
        do_action('wp_login', $u->user_login, $u);
    }

    /**
     * A jelszó után: kell-e kód, és ha igen, a függő belépés jegye.
     *
     * @return array{lepes: string, jegy: string}|null null = nem kell (beléphet)
     */
    public static function fuggo_indit(WP_User $u, bool $marad, string $vissza): ?array
    {
        if (!self::kell($u) || (self::aktiv((int) $u->ID) && self::megbizhato((int) $u->ID))) {
            return null;
        }

        $jegy  = bin2hex(random_bytes(24));
        $lepes = self::aktiv((int) $u->ID) ? 'kod' : 'beallitas';

        set_transient('sdh_2fa_' . hash('sha256', $jegy), [
            'u' => (int) $u->ID, 'm' => $marad, 'v' => $vissza, 'p' => 0,
            't' => $lepes === 'beallitas' ? self::uj_titok() : '',
        ], self::FUGGO_MP);

        return ['lepes' => $lepes, 'jegy' => $jegy];
    }

    /** @return array<string, mixed>|null */
    public static function fuggo(string $jegy): ?array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $jegy)) {
            return null;
        }

        $f = get_transient('sdh_2fa_' . hash('sha256', $jegy));

        return is_array($f) ? $f : null;
    }

    private static function fuggo_ir(string $jegy, ?array $f): void
    {
        $kulcs = 'sdh_2fa_' . hash('sha256', $jegy);

        if ($f === null) {
            delete_transient($kulcs);
        } else {
            set_transient($kulcs, $f, self::FUGGO_MP);
        }
    }

    /**
     * A második lépés beküldése a bejelentkezőn.
     *
     * @param array<string, mixed> $be
     * @return array<string, mixed> cel (belépett) | lepes+jegy+hiba (újra) | tartalek+cel (új kódok) | hiba+vissza (elölről)
     */
    public static function feldolgoz(array $be): array
    {
        $jegy = (string) ($be['sdh_2fa_jegy'] ?? '');
        $f    = self::fuggo($jegy);

        if ($f === null || !wp_verify_nonce((string) ($be['_sdh_2fa'] ?? ''), self::NONCE)) {
            return ['hiba' => 'Lejárt a belépés. Add meg újra a neved és a jelszavad.', 'elolrol' => true];
        }

        $u = get_userdata((int) $f['u']);

        if (!$u instanceof WP_User) {
            self::fuggo_ir($jegy, null);

            return ['hiba' => 'Lejárt a belépés. Add meg újra a neved és a jelszavad.', 'elolrol' => true];
        }

        $kod = (string) ($be['kod'] ?? '');

        // Beállítás a belépéskor (kötelező, még nincs kódja).
        if ((string) $f['t'] !== '') {
            $lepes = self::illik((string) $f['t'], $kod);

            if ($lepes === null) {
                return self::rossz($jegy, $f, 'beallitas', 'A kód nem egyezik. Nézd meg, jól olvastad-e be, és írd be az aktuális kódot.');
            }

            self::fuggo_ir($jegy, null);
            $kodok = self::bekapcsol((int) $u->ID, (string) $f['t'], $lepes);
            self::belep($u, !empty($f['m']));

            if (!empty($be['megbizhato'])) {
                self::megbizhato_beallit((int) $u->ID);
            }

            return ['tartalek' => $kodok, 'cel' => (string) $f['v']];
        }

        $mod  = '';
        $hiba = self::ellenoriz((int) $u->ID, $kod, $mod);

        if ($hiba !== '') {
            return self::rossz($jegy, $f, 'kod', $hiba);
        }

        self::fuggo_ir($jegy, null);
        self::belep($u, !empty($f['m']));

        if (!empty($be['megbizhato'])) {
            self::megbizhato_beallit((int) $u->ID);
        }

        return ['cel' => (string) $f['v'], 'mod' => $mod];
    }

    /** @param array<string, mixed> $f */
    private static function rossz(string $jegy, array $f, string $lepes, string $hiba): array
    {
        $f['p'] = (int) $f['p'] + 1;

        if ($f['p'] >= self::PROBA_MAX) {
            self::fuggo_ir($jegy, null);

            return ['hiba' => 'Túl sok hibás kód. Add meg újra a neved és a jelszavad.', 'elolrol' => true];
        }

        self::fuggo_ir($jegy, $f);

        return ['lepes' => $lepes, 'jegy' => $jegy, 'hiba' => $hiba];
    }

    /* =================================================================
     * A bejelentkező második lépése (a Rejtes rajzolja köré a dobozt)
     * ============================================================== */

    /** @param array<string, mixed> $l lepes, jegy, (tartalek, cel) */
    public static function lepes_html(array $l, string $cim_url): string
    {
        $lepes = (string) ($l['lepes'] ?? '');
        $nap   = self::beallitas()['megbizhato_nap'];

        ob_start();

        if ($lepes === 'tartalek') {
            ?>
            <h2 class="sdh-belepes__lepes">Tartalék kódok</h2>
            <p class="sdh-belepes__szoveg">A kétlépcsős belépés bekapcsolva. Ha a telefonod elveszik, ezekkel a kódokkal léphetsz be – mindegyik <strong>egyszer</strong> használható. <strong>Írd le vagy nyomtasd ki most</strong>: többet nem látod őket.</p>
            <ol class="sdh-belepes__tartalek">
                <?php foreach ((array) ($l['tartalek'] ?? []) as $k) : ?>
                    <li><code><?php echo esc_html((string) $k); ?></code></li>
                <?php endforeach; ?>
            </ol>
            <a class="sdh-gomb sdh-gomb--elsodleges sdh-belepes__gomb" href="<?php echo esc_url((string) ($l['cel'] ?? '')); ?>">Leírtam, tovább</a>
            <?php
            return (string) ob_get_clean();
        }

        $f = self::fuggo((string) ($l['jegy'] ?? ''));
        $u = $f ? get_userdata((int) $f['u']) : null;
        ?>
        <input type="hidden" name="sdh_2fa_jegy" value="<?php echo esc_attr((string) ($l['jegy'] ?? '')); ?>">
        <?php wp_nonce_field(self::NONCE, '_sdh_2fa', false); ?>

        <?php if ($lepes === 'beallitas' && $f && $u instanceof WP_User) : ?>
            <h2 class="sdh-belepes__lepes">Kétlépcsős belépés beállítása</h2>
            <p class="sdh-belepes__szoveg">Ennél a fióknál a belépéshez a jelszó mellé a telefon kódja is kell. Egyszer kell beállítani:</p>
            <ol class="sdh-belepes__lepesek">
                <li>Telepíts a telefonra egy hitelesítő alkalmazást (pl. Google Authenticator, Microsoft Authenticator, Aegis).</li>
                <li>Az alkalmazásban olvasd be ezt a QR-kódot:</li>
            </ol>
            <div class="sdh-belepes__qr"><?php echo SDH_Muhely_Kodok::qr_svg(self::otpauth((string) $f['t'], $u), 'sdh-qr sdh-belepes__qrkep'); // phpcs:ignore WordPress.Security.EscapeOutput -- saját SVG. ?></div>
            <p class="sdh-belepes__szoveg sdh-belepes__kulcs">Vagy írd be kézzel a kulcsot: <code><?php echo esc_html(self::csoportos((string) $f['t'])); ?></code></p>
            <p class="sdh-belepes__szoveg">3. Írd be az alkalmazás által mutatott 6 jegyű kódot:</p>
        <?php else : ?>
            <h2 class="sdh-belepes__lepes">Kód a telefonról</h2>
            <p class="sdh-belepes__szoveg">Nyisd meg a hitelesítő alkalmazást, és írd be a 6 jegyű kódot. Telefon nélkül egy tartalék kódot is beírhatsz.</p>
        <?php endif; ?>

        <?php if (!empty($l['hiba'])) : ?>
            <div class="sdh-uzenet sdh-uzenet--hiba" role="alert"><?php echo esc_html((string) $l['hiba']); ?></div>
        <?php endif; ?>

        <div class="sdh-mezo">
            <label for="sdh-belepes-kod">Kód</label>
            <input type="text" id="sdh-belepes-kod" name="kod" value="" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus>
        </div>
        <?php if ($nap > 0) : ?>
            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" id="sdh-belepes-megbizhato" name="megbizhato" value="1">
                <label for="sdh-belepes-megbizhato">Ezen a gépen <?php echo (int) $nap; ?> napig ne kérje</label>
            </div>
        <?php endif; ?>
        <button type="submit" class="sdh-gomb sdh-gomb--elsodleges sdh-belepes__gomb"><?php echo $lepes === 'beallitas' ? 'Bekapcsolás és belépés' : 'Belépés'; ?></button>
        <p class="sdh-sugo sdh-belepes__lab"><a href="<?php echo esc_url($cim_url); ?>">Vissza: másik fiókkal lépek be</a></p>
        <?php
        return (string) ob_get_clean();
    }

    /* =================================================================
     * A saját fiók: profilmenü → popup
     * ============================================================== */

    /** A profilmenü sora (üres, ha ki van kapcsolva). */
    public static function profil_sor(): string
    {
        if (self::ki() || !is_user_logged_in()) {
            return '';
        }

        $be = self::aktiv(get_current_user_id());

        return '<button type="button" class="sdh-gomb sdh-ketlepcsos__nyit" data-sdh-ketlepcsos-nyit>'
            . 'Kétlépcsős belépés: ' . ($be ? '<strong>bekapcsolva</strong>' : 'nincs bekapcsolva') . '</button>';
    }

    private static function sajat_jog(): void
    {
        check_ajax_referer('sdh_muhely_modal', '_wpnonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['uzenet' => 'Lépj be újra.'], 403);
        }

        // Közös fiókon a fiók (nem a kiválasztott dolgozó) kódja: csak adminisztrátor kezelheti.
        if (class_exists('SDH_Muhely_Szemely') && SDH_Muhely_Szemely::aktiv_id() !== 0 && !SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'A közös fiók kétlépcsős belépését az adminisztrátor kezeli.'], 403);
        }
    }

    /** A popup (SDH_MUHELY_APP.nyit('ketlepcsos')) – HTML. */
    public static function ajax_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal', '_wpnonce');
        $u = wp_get_current_user();

        echo self::urlap_html($u); // phpcs:ignore WordPress.Security.EscapeOutput -- szűrt HTML.
        wp_die();
    }

    public static function urlap_html(WP_User $u): string
    {
        $uid  = (int) $u->ID;
        $kotelezo = self::kotelezo($u);

        ob_start();
        ?>
        <h2 class="sdh-modal__cim">Kétlépcsős belépés</h2>
        <div class="sdh-ketlepcsos" data-sdh-ketlepcsos>
            <?php if (self::ki()) : ?>
                <p class="sdh-sugo">A kétlépcsős belépés ki van kapcsolva (Beállítások › Jogosultságok).</p>
            <?php elseif (class_exists('SDH_Muhely_Szemely') && SDH_Muhely_Szemely::aktiv_id() !== 0 && !SDH_Muhely_Admin_UI::admin_e()) : ?>
                <p class="sdh-sugo">Ez közös fiók: a kétlépcsős belépését az adminisztrátor kezeli.</p>
            <?php elseif (!self::aktiv($uid)) : ?>
                <?php
                $titok = self::uj_titok();
                set_transient('sdh_2fa_uj_' . $uid, $titok, self::FUGGO_MP);
                ?>
                <p class="sdh-sugo">
                    Belépéskor a jelszó mellé a telefonod kódja is kell – így a jelszó egymagában nem elég senkinek.
                    <?php echo $kotelezo ? '<strong>A fiókodnál ez kötelező.</strong>' : ''; ?>
                </p>
                <div class="sdh-ketlepcsos__beallit">
                    <div class="sdh-ketlepcsos__qr"><?php echo SDH_Muhely_Kodok::qr_svg(self::otpauth($titok, $u), 'sdh-qr sdh-ketlepcsos__qrkep'); // phpcs:ignore WordPress.Security.EscapeOutput -- saját SVG. ?></div>
                    <ol class="sdh-ketlepcsos__lepesek">
                        <li>Telepíts a telefonra hitelesítő alkalmazást (pl. Google Authenticator, Microsoft Authenticator, Aegis).</li>
                        <li>Olvasd be a QR-kódot – vagy írd be a kulcsot: <code><?php echo esc_html(self::csoportos($titok)); ?></code></li>
                        <li>Írd be az alkalmazás 6 jegyű kódját, és kapcsold be.</li>
                    </ol>
                </div>
                <div class="sdh-mezok">
                    <div class="sdh-mezo">
                        <label for="sdh-ketlepcsos-kod">Kód a telefonról</label>
                        <input type="text" id="sdh-ketlepcsos-kod" inputmode="numeric" autocomplete="one-time-code" maxlength="12" data-sdh-ketlepcsos-kod>
                    </div>
                </div>
                <p class="sdh-ketlepcsos__gombok">
                    <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-ketlepcsos-akcio="bekapcsol">Bekapcsolás</button>
                </p>
            <?php else : ?>
                <p class="sdh-sugo">
                    <strong>Bekapcsolva</strong><?php echo get_user_meta($uid, self::META_IDO, true) ? ' ' . esc_html(wp_date('Y. m. d.', (int) get_user_meta($uid, self::META_IDO, true))) . ' óta' : ''; ?>.
                    Tartalék kód: <strong><?php echo (int) self::tartalek_db($uid); ?></strong> db maradt.
                </p>
                <p class="sdh-sugo">Az alábbi műveletekhez írd be a telefon aktuális kódját (vagy egy tartalék kódot).</p>
                <div class="sdh-mezok">
                    <div class="sdh-mezo">
                        <label for="sdh-ketlepcsos-kod">Kód a telefonról</label>
                        <input type="text" id="sdh-ketlepcsos-kod" inputmode="numeric" autocomplete="one-time-code" maxlength="12" data-sdh-ketlepcsos-kod>
                    </div>
                </div>
                <p class="sdh-ketlepcsos__gombok">
                    <button type="button" class="sdh-gomb" data-sdh-ketlepcsos-akcio="tartalek">Új tartalék kódok</button>
                    <button type="button" class="sdh-gomb" data-sdh-ketlepcsos-akcio="gepek">Megbízható gépek visszavonása</button>
                    <?php if (!$kotelezo) : ?>
                        <button type="button" class="sdh-gomb sdh-ketlepcsos__ki" data-sdh-ketlepcsos-akcio="kikapcsol">Kikapcsolás</button>
                    <?php endif; ?>
                </p>
                <?php if ($kotelezo) : ?>
                    <p class="sdh-mezo__sugo">A fiókodnál kötelező, ezért nem kapcsolható ki. Új telefonnál az adminisztrátor visszaállítja, és a következő belépéskor újra beállítod.</p>
                <?php endif; ?>
            <?php endif; ?>
            <div class="sdh-uzenet" data-sdh-ketlepcsos-uzenet role="status" hidden></div>
            <ol class="sdh-ketlepcsos__tartalek" data-sdh-ketlepcsos-tartalek hidden></ol>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function ajax_bekapcsol(): void
    {
        self::sajat_jog();
        $uid   = get_current_user_id();
        $titok = (string) get_transient('sdh_2fa_uj_' . $uid);
        $kod   = isset($_POST['kod']) ? sanitize_text_field(wp_unslash((string) $_POST['kod'])) : '';

        if (self::aktiv($uid)) {
            wp_send_json_error(['uzenet' => 'Már be van kapcsolva.']);
        }

        if ($titok === '') {
            wp_send_json_error(['uzenet' => 'Lejárt a beállítás. Zárd be az ablakot, és nyisd meg újra.']);
        }

        $lepes = self::illik($titok, $kod);

        if ($lepes === null) {
            wp_send_json_error(['uzenet' => 'A kód nem egyezik. Írd be az alkalmazás aktuális kódját.']);
        }

        delete_transient('sdh_2fa_uj_' . $uid);

        wp_send_json_success(['tartalek' => self::bekapcsol($uid, $titok, $lepes), 'uzenet' => 'Bekapcsolva. Írd le a tartalék kódokat – többet nem látod őket.']);
    }

    /** A kódot kérő műveletek közös eleje. */
    private static function kodos(): int
    {
        self::sajat_jog();
        $uid = get_current_user_id();
        $kod = isset($_POST['kod']) ? sanitize_text_field(wp_unslash((string) $_POST['kod'])) : '';

        if (!self::aktiv($uid)) {
            wp_send_json_error(['uzenet' => 'Nincs bekapcsolva.']);
        }

        $hiba = self::ellenoriz($uid, $kod);

        if ($hiba !== '') {
            wp_send_json_error(['uzenet' => $hiba]);
        }

        return $uid;
    }

    public static function ajax_kikapcsol(): void
    {
        $uid = self::kodos();

        if (self::kotelezo(wp_get_current_user())) {
            wp_send_json_error(['uzenet' => 'A fiókodnál kötelező, nem kapcsolható ki.']);
        }

        self::kikapcsol($uid);
        wp_send_json_success(['uzenet' => 'Kikapcsolva. Mostantól elég a jelszó.', 'ujratolt' => true]);
    }

    public static function ajax_tartalek(): void
    {
        $uid = self::kodos();
        self::naplo($uid, 'Új tartalék kódok');

        wp_send_json_success(['tartalek' => self::tartalek_uj($uid), 'uzenet' => 'Új tartalék kódok – a régiek már nem érvényesek. Írd le őket.']);
    }

    public static function ajax_gepek(): void
    {
        $uid = self::kodos();
        self::gepek_visszavon($uid);
        self::naplo($uid, 'Megbízható gépek visszavonva');

        wp_send_json_success(['uzenet' => 'Kész: minden gépen újra kérni fogja a kódot.']);
    }

    /* =================================================================
     * Beállítások › Jogosultságok
     * ============================================================== */

    /** @return array<int, WP_User> akik beléphetnek a rendszerbe */
    public static function felhasznalok(): array
    {
        return array_values(array_filter(
            get_users(['orderby' => 'display_name']),
            static fn (WP_User $u): bool => user_can($u, SDH_Muhely_Admin_UI::JOG) || user_can($u, SDH_Muhely_Admin_UI::FEJLESZTO_JOG)
        ));
    }

    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();
        ?>
        <div class="sdh-doboz" id="ketlepcsos">
            <h2 class="sdh-doboz__cim">Kétlépcsős belépés</h2>

            <p class="sdh-sugo">
                Belépéskor a jelszó mellé a telefon hitelesítő alkalmazásának 6 jegyű kódja is kell. Mielőtt a rendszer
                kívülről (az interneten át) is elérhető lesz, kapcsold kötelezőre. Aki még nem állította be, a következő
                belépéskor a bejelentkező oldalon állítja be (QR-kóddal); a saját fiókjánál a profilmenüben is.
            </p>

            <?php if (defined(self::KIKAPCSOLO) && constant(self::KIKAPCSOLO)) : ?>
                <p class="sdh-uzenet sdh-uzenet--figyelem">Most a rendszer konfigurációja kikapcsolja (vészkapcsoló) – amíg ott áll, senkitől nem kér kódot.</p>
            <?php endif; ?>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="ketlepcsos_szabaly">Kinek kell</label>
                    <select name="ketlepcsos[szabaly]" id="ketlepcsos_szabaly">
                        <?php foreach (self::szabalyok() as $k => $nev) : ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($b['szabaly'], $k); ?>><?php echo esc_html($nev); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="ketlepcsos_nap">Megbízható gép (nap)</label>
                    <input type="number" name="ketlepcsos[megbizhato_nap]" id="ketlepcsos_nap" min="0" max="<?php echo (int) self::MEGBIZHATO_MAX; ?>" step="1" value="<?php echo (int) $b['megbizhato_nap']; ?>">
                    <span class="sdh-mezo__sugo">Ennyi napig nem kér kódot azon a gépen, ahol bepipálták. 0 = mindig kér.</span>
                </div>
            </div>

            <table class="sdh-tabla sdh-ketlepcsos__lista">
                <thead><tr><th>Fiók</th><th>Állapot</th><th>Tartalék kód</th><th></th></tr></thead>
                <tbody>
                    <?php foreach (self::felhasznalok() as $u) : ?>
                        <?php $be = self::aktiv((int) $u->ID); ?>
                        <tr>
                            <td><?php echo esc_html($u->display_name); ?></td>
                            <td><?php echo $be ? 'Bekapcsolva' : (self::kotelezo($u) ? 'Kötelező – a következő belépéskor állítja be' : 'Nincs'); ?></td>
                            <td><?php echo $be ? (int) self::tartalek_db((int) $u->ID) . ' db' : '–'; ?></td>
                            <td>
                                <?php if ($be) : ?>
                                    <button type="button" class="sdh-gomb" data-sdh-ketlepcsos-visszaallit="<?php echo (int) $u->ID; ?>"
                                            data-nonce="<?php echo esc_attr(wp_create_nonce('sdh_muhely_ketlepcsos_visszaallit')); ?>">Visszaállítás</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="sdh-mezo__sugo">Visszaállítás: elveszett vagy új telefonnál. Törli a fiók kódját és tartalék kódjait; a következő belépéskor (ha kötelező) újra beállítja, különben jelszóval lép be.</p>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (!isset($_POST['ketlepcsos']) || !is_array($_POST['ketlepcsos'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $be     = wp_unslash($_POST['ketlepcsos']);
        $regi   = self::beallitas();
        $s      = sanitize_key((string) ($be['szabaly'] ?? 'valaszthato'));
        $uj     = [
            'szabaly'        => isset(self::szabalyok()[$s]) ? $s : 'valaszthato',
            'megbizhato_nap' => max(0, min(self::MEGBIZHATO_MAX, (int) ($be['megbizhato_nap'] ?? 30))),
        ];

        update_option(self::OPT, $uj, false);

        if ($uj['szabaly'] !== $regi['szabaly']) {
            self::naplo(0, 'Kétlépcsős belépés szabálya: ' . self::szabalyok()[$uj['szabaly']]);
        }
    }

    public static function ajax_visszaallit(): void
    {
        check_ajax_referer('sdh_muhely_ketlepcsos_visszaallit', '_wpnonce');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ehhez adminisztrátori jog kell.'], 403);
        }

        $uid = isset($_POST['felhasznalo']) ? (int) $_POST['felhasznalo'] : 0;
        $u   = get_userdata($uid);

        if (!$u instanceof WP_User) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen fiók.']);
        }

        // A fejlesztő fiókját csak fejlesztő állíthatja vissza.
        if (user_can($u, SDH_Muhely_Admin_UI::FEJLESZTO_JOG) && !SDH_Muhely_Admin_UI::fejleszto_e()) {
            wp_send_json_error(['uzenet' => 'Ezt a fiókot csak a fejlesztő állíthatja vissza.'], 403);
        }

        self::kikapcsol($uid, 'Kétlépcsős belépés visszaállítva (adminisztrátor): ' . $u->display_name);
        wp_send_json_success(['uzenet' => 'Visszaállítva.']);
    }

    /* =================================================================
     * Napló
     * ============================================================== */

    private static function naplo(int $uid, string $szoveg): void
    {
        if (!class_exists('SDH_Muhely_Valtozasnaplo')) {
            return;
        }

        $u = $uid > 0 ? get_userdata($uid) : null;
        SDH_Muhely_Valtozasnaplo::esemeny('rendszer', 0, $szoveg . ($u instanceof WP_User && strpos($szoveg, $u->display_name) === false ? ' – ' . $u->display_name : ''));
    }
}
