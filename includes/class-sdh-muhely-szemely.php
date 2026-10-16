<?php
/**
 * Közös fiók: „Ki vagy?" választó (0.72.0, HIBALISTA H2).
 *
 * Ha több dolgozó egyetlen WordPress-fiókkal dolgozik, a naplóban, a pénztárban és a
 * felelős mezőben mindenki ugyanaz lenne. Ezért a közösnek jelölt fiókon a felület
 * indulásakor ki kell választani, ki ül a gépnél (név + rövid PIN – a PIN kikapcsolható).
 *
 * - A dolgozók belépni nem tudó WordPress-felhasználók (szerep: sdh_dolgozo, jog nélkül).
 *   Így az azonosítójuk minden meglévő mezőbe (felelős, pénztár, napló) gond nélkül beírható.
 * - A választás böngészőnként él (HttpOnly süti → szerveroldali, rövid életű tároló),
 *   kijelentkezéskor és a „Személy váltása" gombra törlődik.
 * - Amíg nincs kiválasztva senki, a rendszer AJAX-hívásai nem futnak (a választó takarja a felületet).
 * - A beállítás a Beállítások › Jogosultságok fülön van: ki- és bekapcsolható, a PIN külön is.
 *
 * @package SDH_Muhely
 */

defined('ABSPATH') || exit;

final class SDH_Muhely_Szemely
{
    public const OPT       = 'sdh_muhely_ki_vagy';
    public const SZEREP    = 'sdh_dolgozo';
    public const SUTI      = 'sdh_muhely_szemely';
    /** A dolgozók bejelentkezési neve ezzel kezdődik – így a WP-ben átállított szerepű dolgozó sem tűnik el. */
    public const LOGIN_ELOTAG = 'sdh-dolgozo-';

    /** wp-config.php: define('SDH_MUHELY_KI_VAGY_KI', true); – kikapcsolja a választót (mentőöv). */
    public const KI_ALLANDO = 'SDH_MUHELY_KI_VAGY_KI';

    public const META_PIN  = 'sdh_muhely_pin';
    public const META_AKTIV = 'sdh_muhely_dolgozo_aktiv';
    public const TTL       = 12 * HOUR_IN_SECONDS;
    public const MAX_PROBA = 5;
    public const UJ_SOROK  = 3;

    /** Ezek az AJAX-hívások választás nélkül is futnak. */
    private const SZABAD = ['sdh_muhely_ki_vagy', 'sdh_muhely_ki_vagy_ki', 'sdh_muhely_pulzus'];

    /** @var array<int, int> fiók => kiválasztott dolgozó (kérésen belüli gyorstár) */
    private static array $gyors = [];

    public static function init(): void
    {
        add_action('init', [self::class, 'szerep'], 5);
        add_filter('wp_authenticate_user', [self::class, 'belepes_tiltas'], 20);
        add_action('admin_init', [self::class, 'ajax_kapu'], 0);
        add_action('wp_ajax_sdh_muhely_ki_vagy', [self::class, 'ajax_valaszt']);
        add_action('wp_ajax_sdh_muhely_ki_vagy_ki', [self::class, 'ajax_kilep']);
        add_action('wp_logout', [self::class, 'torol']);
        add_action('sdh_muhely_felulet_vege', [self::class, 'valaszto']);
        add_action('admin_footer', [self::class, 'valaszto_admin']);
    }

    /* ------------------------------------------------------------------
     * Beállítás
     * ------------------------------------------------------------------ */

    /**
     * @return array{be: bool, pin: bool, fiokok: array<int, int>}
     */
    public static function beallitas(): array
    {
        $b = get_option(self::OPT, []);
        $b = is_array($b) ? $b : [];

        return [
            'be'     => !empty($b['be']),
            'pin'    => !array_key_exists('pin', $b) || !empty($b['pin']),
            'fiokok' => array_values(array_filter(array_map('intval', (array) ($b['fiokok'] ?? [])), static fn (int $i): bool => $i > 0)),
        ];
    }

    /** Közös (választót kérő) fiók-e. */
    public static function kozos(?int $uid = null): bool
    {
        if (defined(self::KI_ALLANDO) && constant(self::KI_ALLANDO)) {
            return false;
        }

        $uid = $uid ?? get_current_user_id();
        $b   = self::beallitas();

        return $uid > 0 && $b['be'] && in_array($uid, $b['fiokok'], true);
    }

    /** Ennél a kérésnél kell-e (még) választani. */
    public static function kell_valasztani(): bool
    {
        return self::kozos() && self::aktiv_id() === 0 && !self::admin_kizarva();
    }

    /**
     * Mentőöv: az adminisztrátor közös fiókján, ha nincs egyetlen választható adminisztrátor-személy sem,
     * a választó nem jelenik meg – különben senki nem érhetné el a Beállításokat.
     */
    public static function admin_kizarva(): bool
    {
        if (!current_user_can(SDH_Muhely_Admin_UI::ADMIN_JOG)) {
            return false;
        }

        return self::admin_szemelyek() === [];
    }

    /**
     * A közös fiókon választható adminisztrátor-személyek: aktív dolgozók, akik WP-adminisztrátorok
     * vagy Admin szerepet kaptak.
     *
     * @return array<int, string>
     */
    public static function admin_szemelyek(): array
    {
        $lista = [];

        foreach (self::dolgozok(true) as $id => $d) {
            if (SDH_Muhely_Szerepkor::szerep_slug((int) $id) === SDH_Muhely_Szerepkor::ADMIN) {
                $lista[(int) $id] = $d['nev'];
            }
        }

        return $lista;
    }

    /* ------------------------------------------------------------------
     * Dolgozók
     * ------------------------------------------------------------------ */

    public static function szerep(): void
    {
        if (function_exists('get_role') && get_role(self::SZEREP) === null) {
            add_role(self::SZEREP, 'SDH dolgozó (közös fiók)', []);
        }
    }

    /** A dolgozó-felhasználóval nem lehet belépni (csak a közös fiókon választható). */
    public static function belepes_tiltas($user)
    {
        if ($user instanceof WP_User && in_array(self::SZEREP, (array) $user->roles, true)) {
            return new WP_Error('sdh_dolgozo', 'Ezzel a névvel nem lehet belépni: a közös fiókon kell kiválasztani.');
        }

        return $user;
    }

    /**
     * @return array<int, array{nev: string, pin: bool, aktiv: bool}>
     */
    public static function dolgozok(bool $csak_aktiv = false): array
    {
        $lista = [];
        $userek = [];

        // Szerep szerint és bejelentkezési név szerint is: ha a WP-ben a dolgozó szerepét átállítják
        // (pl. adminisztrátorrá teszik), akkor is dolgozó marad.
        foreach (get_users(['role' => self::SZEREP]) as $u) {
            $userek[(int) $u->ID] = $u;
        }

        foreach (get_users(['search' => self::LOGIN_ELOTAG . '*', 'search_columns' => ['user_login']]) as $u) {
            $userek[(int) $u->ID] = $u;
        }

        uasort($userek, static fn ($a, $b): int => strcasecmp((string) $a->display_name, (string) $b->display_name));

        foreach ($userek as $u) {
            $aktiv = get_user_meta($u->ID, self::META_AKTIV, true) !== '0';

            if ($csak_aktiv && !$aktiv) {
                continue;
            }

            $lista[(int) $u->ID] = [
                'nev'   => (string) $u->display_name,
                'pin'   => (string) get_user_meta($u->ID, self::META_PIN, true) !== '',
                'aktiv' => $aktiv,
            ];
        }

        return $lista;
    }

    public static function dolgozo_e(int $id): bool
    {
        $u = $id > 0 ? get_userdata($id) : false;

        return $u instanceof WP_User
            && (in_array(self::SZEREP, (array) $u->roles, true) || str_starts_with((string) $u->user_login, self::LOGIN_ELOTAG));
    }

    /** Új dolgozó (belépni nem tudó felhasználó). Visszaad: azonosító vagy 0. */
    public static function uj_dolgozo(string $nev, string $pin = ''): int
    {
        $nev = mb_substr(trim(sanitize_text_field($nev)), 0, 60);

        if ($nev === '') {
            return 0;
        }

        self::szerep();

        $id = wp_insert_user([
            'user_login'   => 'sdh-dolgozo-' . strtolower(wp_generate_password(10, false, false)),
            'user_pass'    => wp_generate_password(32, true, true),
            'display_name' => $nev,
            'nickname'     => $nev,
            'first_name'   => $nev,
            'role'         => self::SZEREP,
        ]);

        if (is_wp_error($id)) {
            return 0;
        }

        update_user_meta((int) $id, self::META_AKTIV, '1');

        if ($pin !== '') {
            self::pin_beallit((int) $id, $pin);
        }

        return (int) $id;
    }

    public static function pin_jo(string $pin): bool
    {
        return preg_match('/^\d{4,8}$/', $pin) === 1;
    }

    public static function pin_beallit(int $id, string $pin): bool
    {
        if (!self::dolgozo_e($id) || !self::pin_jo($pin)) {
            return false;
        }

        update_user_meta($id, self::META_PIN, wp_hash_password($pin));

        return true;
    }

    /* ------------------------------------------------------------------
     * Az aktuális dolgozó
     * ------------------------------------------------------------------ */

    private static function tar_kulcs(string $token): string
    {
        return 'sdh_muhely_kivagy_' . sha1($token);
    }

    private static function token(): string
    {
        $t = isset($_COOKIE[self::SUTI]) ? (string) $_COOKIE[self::SUTI] : '';

        return preg_match('/^[A-Za-z0-9]{32,64}$/', $t) === 1 ? $t : '';
    }

    /** A közös fióknál kiválasztott dolgozó (0, ha nem közös fiók vagy még nincs választás). */
    public static function aktiv_id(): int
    {
        $uid = get_current_user_id();

        if ($uid <= 0) {
            return 0;
        }

        if (array_key_exists($uid, self::$gyors)) {
            return self::$gyors[$uid];
        }

        $id = 0;

        if (self::kozos($uid)) {
            $token = self::token();
            $adat  = $token !== '' ? get_transient(self::tar_kulcs($token)) : false;

            if (is_array($adat) && (int) ($adat['fiok'] ?? 0) === $uid) {
                $sz = (int) ($adat['szemely'] ?? 0);

                if (self::dolgozo_e($sz) && get_user_meta($sz, self::META_AKTIV, true) !== '0') {
                    $id = $sz;
                }
            }
        }

        self::$gyors[$uid] = $id;

        return $id;
    }

    /** Akinek a nevére a munka kerül: a kiválasztott dolgozó, egyébként a belépett felhasználó. */
    public static function ki(): int
    {
        return self::aktiv_id() ?: get_current_user_id();
    }

    public static function nev(int $id = 0): string
    {
        $u = get_userdata($id > 0 ? $id : self::ki());

        return $u ? (string) ($u->display_name ?: $u->user_login) : '';
    }

    /** Tesztekhez és a kérésen belüli váltáshoz: a gyorstár ürítése. */
    public static function felejt(): void
    {
        self::$gyors = [];
    }

    /** A választás rögzítése ebben a böngészőben. Visszaad: a token. */
    public static function rogzit(int $szemely): string
    {
        $uid   = get_current_user_id();
        $regi  = self::token();

        if ($regi !== '') {
            delete_transient(self::tar_kulcs($regi));
        }

        $token = wp_generate_password(40, false, false);

        set_transient(self::tar_kulcs($token), ['fiok' => $uid, 'szemely' => $szemely, 'ido' => time()], self::TTL);
        $_COOKIE[self::SUTI] = $token;
        self::suti($token, time() + self::TTL);
        self::felejt();

        return $token;
    }

    /** A választás törlése (Személy váltása, kijelentkezés). */
    public static function torol(): void
    {
        $token = self::token();

        if ($token !== '') {
            delete_transient(self::tar_kulcs($token));
        }

        unset($_COOKIE[self::SUTI]);
        self::suti('', time() - YEAR_IN_SECONDS);
        self::felejt();
    }

    private static function suti(string $ertek, int $lejar): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::SUTI, $ertek, [
            'expires'  => $lejar,
            'path'     => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    /** Választás nélkül a közös fiókon a rendszer hívásai nem futnak. */
    public static function ajax_kapu(): void
    {
        if (!wp_doing_ajax()) {
            return;
        }

        $akcio = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if (!str_starts_with($akcio, 'sdh_muhely_') || in_array($akcio, self::SZABAD, true)) {
            return;
        }

        if (self::kell_valasztani()) {
            wp_send_json_error(['ki_vagy' => true, 'uzenet' => 'Előbb válaszd ki, ki vagy (közös fiók).'], 403);
        }
    }

    public static function ajax_valaszt(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog()) || !self::kozos()) {
            wp_send_json_error(['uzenet' => 'Ez a fiók nem közös fiók.'], 403);
        }

        $uid = get_current_user_id();
        $id  = isset($_POST['szemely']) ? (int) $_POST['szemely'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $pin = isset($_POST['pin']) ? trim((string) wp_unslash($_POST['pin'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $dolgozok = self::dolgozok(true);

        if (!isset($dolgozok[$id])) {
            wp_send_json_error(['uzenet' => 'Válassz a listából.']);
        }

        if (self::beallitas()['pin']) {
            $szamlalo = 'sdh_muhely_kivagy_proba_' . $uid . '_' . $id;
            $proba    = (int) get_transient($szamlalo);
            $hash     = (string) get_user_meta($id, self::META_PIN, true);
            $u        = get_userdata($id);

            // A WP-adminisztrátor személy a saját WordPress-jelszavával is igazolhat (a dolgozóé véletlen jelszó).
            $admin_szemely = $u instanceof WP_User && user_can($u, SDH_Muhely_Admin_UI::ADMIN_JOG);

            if ($proba >= self::MAX_PROBA) {
                wp_send_json_error(['uzenet' => 'Túl sok hibás PIN. Próbáld újra 5 perc múlva.']);
            }

            if ($hash === '' && !$admin_szemely) {
                wp_send_json_error(['uzenet' => 'Ennek a névnek még nincs PIN-je – az adminisztrátor a Beállítások › Jogosultságok fülön adhatja meg.']);
            }

            $jo = $pin !== '' && (
                ($hash !== '' && wp_check_password($pin, $hash))
                || ($admin_szemely && wp_check_password($pin, (string) $u->user_pass, $id))
            );

            if (!$jo) {
                set_transient($szamlalo, $proba + 1, 5 * MINUTE_IN_SECONDS);
                wp_send_json_error(['uzenet' => $pin === '' ? 'Add meg a PIN-ed.' : 'Hibás PIN.']);
            }

            delete_transient($szamlalo);
        }

        self::rogzit($id);

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('rendszer', 0, 'Közös fiók: ' . $dolgozok[$id]['nev'] . ' kezdett dolgozni');
        }

        wp_send_json_success(['nev' => $dolgozok[$id]['nev']]);
    }

    public static function ajax_kilep(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (self::aktiv_id() > 0 && class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('rendszer', 0, 'Közös fiók: ' . self::nev() . ' befejezte');
        }

        self::torol();

        wp_send_json_success(['ok' => true]);
    }

    /* ------------------------------------------------------------------
     * Felület
     * ------------------------------------------------------------------ */

    /** A „Ki vagy?" választó – a felület végén, ha a közös fiókon még nincs választás. */
    public static function valaszto(): void
    {
        if (!self::kell_valasztani()) {
            return;
        }

        $dolgozok = self::dolgozok(true);
        $pin      = self::beallitas()['pin'];

        ?>
        <div class="sdh-kivagy" data-sdh-kivagy data-pin="<?php echo $pin ? '1' : '0'; ?>" role="dialog" aria-modal="true" aria-labelledby="sdh-kivagy-cim">
            <div class="sdh-kivagy__doboz">
                <h2 class="sdh-kivagy__cim" id="sdh-kivagy-cim">Ki vagy?</h2>
                <?php if ($dolgozok === []) : ?>
                    <p>Ez közös fiók, de még nincs felvéve dolgozó. Az adminisztrátor a <strong>Beállítások › Jogosultságok</strong> fülön veheti fel a neveket.</p>
                <?php else : ?>
                    <p class="sdh-kivagy__sugo">Közös fiók: válaszd ki a neved<?php echo $pin ? ', és add meg a PIN-ed' : ''; ?>. Ami ezután történik, a te nevedre kerül.</p>
                    <div class="sdh-kivagy__nevek">
                        <?php foreach ($dolgozok as $id => $d) : ?>
                            <button type="button" class="sdh-gomb sdh-kivagy__nev" data-sdh-kivagy-nev="<?php echo (int) $id; ?>"><?php echo esc_html($d['nev']); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($pin) : ?>
                        <div class="sdh-kivagy__pin" data-sdh-kivagy-pinsor hidden>
                            <label for="sdh-kivagy-pin">PIN</label>
                            <input type="password" id="sdh-kivagy-pin" maxlength="72" autocomplete="off" data-sdh-kivagy-pin>
                            <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-kivagy-ok>Belépés</button>
                        </div>
                    <?php endif; ?>
                    <p class="sdh-kivagy__hiba" data-sdh-kivagy-hiba hidden></p>
                <?php endif; ?>
                <p class="sdh-kivagy__lab"><a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Kijelentkezés</a></p>
            </div>
        </div>
        <?php
    }

    /** A régi (wp-admin) oldalakon is: csak a rendszer saját oldalain. */
    public static function valaszto_admin(): void
    {
        $kepernyo = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($kepernyo && str_contains((string) $kepernyo->id, 'sdh-muhely')) {
            self::valaszto();
        }
    }

    /** A profilpanel sora: ki dolgozik most, és a váltás gombja (csak közös fióknál). */
    public static function profil_sor(): string
    {
        if (self::aktiv_id() === 0) {
            return '';
        }

        return '<p class="sdh-profil__cim">Közös fiók – most: <strong>' . esc_html(self::nev()) . '</strong></p>'
            . '<button type="button" class="sdh-gomb sdh-kivagy__valt" data-sdh-kivagy-valt>Személy váltása</button>';
    }

    /* ------------------------------------------------------------------
     * Beállítások › Jogosultságok
     * ------------------------------------------------------------------ */

    /** A Jogosultságok fül: a zárolás (Utkozes) és a közös fiók egy helyen. */
    public static function jogosultsagok_rajz(): void
    {
        SDH_Muhely_Szerepkor::doboz();
        SDH_Muhely_Utkozes::beallitas_doboz();
        self::beallitas_doboz();
    }

    public static function jogosultsagok_mentes(): void
    {
        SDH_Muhely_Szerepkor::mentes();
        SDH_Muhely_Utkozes::beallitas_mentes();
        self::beallitas_mentes();
    }

    public static function beallitas_doboz(): void
    {
        $b        = self::beallitas();
        $dolgozok = self::dolgozok();
        $fiokok   = get_users(['capability' => SDH_Muhely_Admin_UI::jog(), 'orderby' => 'display_name', 'order' => 'ASC']);

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Közös fiók: „Ki vagy?”</h2>
            <p class="sdh-sugo">
                Ha többen dolgoztok egy fiókkal, a közösnek jelölt fiókon a felület indulásakor ki kell választani, ki ül a gépnél.
                A Naplóba, a pénztárba és az új munkalap felelős mezőjébe ez a név kerül. A választás az adott böngészőben
                legfeljebb <?php echo (int) (self::TTL / HOUR_IN_SECONDS); ?> óráig él, a profilmenüben bármikor váltható.
            </p>

            <input type="hidden" name="kivagy_jelen" value="1">

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="kivagy_be" id="kivagy_be" value="1" <?php checked($b['be']); ?>>
                <label for="kivagy_be">„Ki vagy?” választó a közös fiókokon</label>
            </div>
            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="kivagy_pin" id="kivagy_pin" value="1" <?php checked($b['pin']); ?>>
                <label for="kivagy_pin">PIN kód kell a választáshoz (kikapcsolva elég a név)</label>
            </div>

            <h3 class="sdh-doboz__alcim">Melyik fiók közös?</h3>
            <?php foreach ($fiokok as $f) : ?>
                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" name="kivagy_fiok[]" id="kivagy_fiok_<?php echo (int) $f->ID; ?>" value="<?php echo (int) $f->ID; ?>" <?php checked(in_array((int) $f->ID, $b['fiokok'], true)); ?>>
                    <label for="kivagy_fiok_<?php echo (int) $f->ID; ?>"><?php echo esc_html($f->display_name . ' (' . $f->user_login . ')'); ?></label>
                </div>
            <?php endforeach; ?>

            <?php
            $kizart = [];

            foreach ($fiokok as $f) {
                if (in_array((int) $f->ID, $b['fiokok'], true) && user_can($f, SDH_Muhely_Admin_UI::ADMIN_JOG)) {
                    $kizart[] = $f->display_name;
                }
            }

            if ($b['be'] && $kizart !== [] && self::admin_szemelyek() === []) :
                ?>
                <div class="sdh-uzenet sdh-uzenet--hiba">
                    A(z) <?php echo esc_html(implode(', ', $kizart)); ?> adminisztrátori közös fiók, de nincs <strong>Admin</strong> szerepű dolgozó,
                    ezért a választó azon a fiókon <strong>nem jelenik meg</strong> – így nem zárhatod ki magad. Adj egy dolgozónak Admin szerepet (fent, „Ki melyik szerepben?”).
                </div>
            <?php endif; ?>

            <h3 class="sdh-doboz__alcim">Dolgozók</h3>
            <p class="sdh-sugo">
                Név és 4–8 számjegyű PIN. A PIN csak lenyomatként tárolódik; üresen hagyva a régi marad. Dolgozót nem törlünk
                (a régi munkái az ő nevén maradnak) – inaktívra állítva nem választható.
            </p>
            <div class="sdh-kivagy-lista">
                <?php foreach ($dolgozok as $id => $d) : ?>
                    <div class="sdh-kivagy-lista__sor">
                        <input type="text" name="dolgozo[<?php echo (int) $id; ?>][nev]" value="<?php echo esc_attr($d['nev']); ?>" maxlength="60" aria-label="Név" autocomplete="off">
                        <input type="password" name="dolgozo[<?php echo (int) $id; ?>][pin]" value="" inputmode="numeric" maxlength="8" autocomplete="new-password"
                               placeholder="<?php echo $d['pin'] ? 'PIN beállítva' : 'Nincs PIN'; ?>" aria-label="Új PIN">
                        <label class="sdh-jelolo">
                            <input type="hidden" name="dolgozo[<?php echo (int) $id; ?>][jelen]" value="1">
                            <input type="checkbox" name="dolgozo[<?php echo (int) $id; ?>][aktiv]" value="1" aria-label="Választható" <?php checked($d['aktiv']); ?>>
                        </label>
                    </div>
                <?php endforeach; ?>
                <?php for ($n = 0; $n < self::UJ_SOROK; $n++) : ?>
                    <div class="sdh-kivagy-lista__sor">
                        <input type="text" name="dolgozo_uj[<?php echo (int) $n; ?>][nev]" value="" maxlength="60" placeholder="Új dolgozó neve" aria-label="Új dolgozó neve" autocomplete="off">
                        <input type="password" name="dolgozo_uj[<?php echo (int) $n; ?>][pin]" value="" inputmode="numeric" maxlength="8" autocomplete="new-password" placeholder="PIN" aria-label="PIN">
                        <span></span>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['kivagy_jelen'])) {
            return;
        }

        update_option(self::OPT, [
            'be'     => !empty($_POST['kivagy_be']),
            'pin'    => !empty($_POST['kivagy_pin']),
            'fiokok' => array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['kivagy_fiok'] ?? []))))),
        ]);

        foreach ((array) ($_POST['dolgozo'] ?? []) as $id => $sor) {
            $id = (int) $id;

            if (!is_array($sor) || !self::dolgozo_e($id)) {
                continue;
            }

            $nev = mb_substr(trim(sanitize_text_field(wp_unslash((string) ($sor['nev'] ?? '')))), 0, 60);

            if ($nev !== '' && $nev !== self::nev($id)) {
                wp_update_user(['ID' => $id, 'display_name' => $nev, 'nickname' => $nev, 'first_name' => $nev]);
            }

            $pin = trim((string) wp_unslash($sor['pin'] ?? ''));

            if ($pin !== '') {
                self::pin_beallit($id, $pin);
            }

            if (!empty($sor['jelen'])) {
                update_user_meta($id, self::META_AKTIV, !empty($sor['aktiv']) ? '1' : '0');
            }
        }

        foreach ((array) ($_POST['dolgozo_uj'] ?? []) as $sor) {
            if (!is_array($sor)) {
                continue;
            }

            $nev = (string) wp_unslash($sor['nev'] ?? '');
            $pin = trim((string) wp_unslash($sor['pin'] ?? ''));

            if (trim($nev) !== '') {
                self::uj_dolgozo($nev, self::pin_jo($pin) ? $pin : '');
            }
        }
        // phpcs:enable

        self::felejt();
    }
}
