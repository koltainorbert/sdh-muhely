<?php
/**
 * Licenc, központi kapcsolat és támogatási hozzáférés (N4, 0.93.0) – a VEVŐ oldala.
 *
 * - Központ nélkül (alap, a saját példány) semmi nem változik: nincs licencfeltétel, a frissítés a régi úton jön.
 * - Központtal (Beállítások › Rendszer › Licenc: a központ címe + a licenckulcs) a rendszer 5 percenként szívverést
 *   küld (0.99.0): licenckulcs, a példány véletlen azonosítója, ujjlenyomat (a cím sózott lenyomata), verzió, és a
 *   műszaki állapotkép (SDH_Muhely_Telemetria: szerver, sebesség, mentés, hibák, darabszámok). Az ügyfelek személyes
 *   adata, a munkalapok tartalma és a forgalom összege soha. A válaszban jönnek a licenc moduljai és jogai, és a
 *   központ távoli műveletei (SDH_Muhely_Tavoli). Régi (1.0-s) központnál a napi jelentésre áll vissza.
 * - A központ válasza Ed25519-aláírt (libsodium). A központ nyilvános kulcsát az első sikeres kapcsolatkor jegyzi
 *   meg a rendszer (utána csak azzal aláírt választ fogad el; a fejlesztő törölheti, ha a központ kulcsot cserél).
 * - A licenc állapota: aktív / lejárt / tiltva / túl sok példány / ismeretlen kulcs. Lejárt vagy tiltott licencnél
 *   a rendszer TOVÁBB MŰKÖDIK (az ügyfél adata az övé), csak frissítést nem kap, és az adminisztrátor figyelmeztetést lát.
 *   Ha 30 napig nincs kapcsolat a központtal, ugyanez a figyelmeztetés látszik (de frissítés sem jön).
 * - Frissítés központtal: az újabb verziót és a csomagot a központ adja (licencellenőrzéssel), nem a GitHub.
 * - Támogatási hozzáférés: a vevő adminisztrátora engedélyezi (2 / 24 / 72 órára). Ideiglenes fejlesztői fiók jön létre
 *   egy belépő linkkel; a link a központba megy (ott látja a támogató). Lejáratkor vagy visszavonáskor a fiók törlődik.
 *   Minden lépés a Naplóba kerül.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Licenc
{
    public const OPT  = 'sdh_muhely_licenc';
    public const CRON = 'sdh_muhely_licenc_cron';
    public const SZIV = 'sdh_muhely_szivveres';
    public const QV   = 'sdh_tamogatas';
    public const NS   = 'sdh-kozpont/v1';

    /** Ennyi nap kapcsolat nélkül után figyelmeztet. */
    public const KAPCSOLAT_NAP = 30;

    public const TAMOGATAS_ORAK = [2 => '2 óra', 24 => '1 nap', 72 => '3 nap'];

    private const TAM_META = 'sdh_muhely_tamogatas';

    /** Szívverés közben ne induljon még egy (pl. a „Frissítés keresése” művelet ne jelentsen újra). */
    private static bool $fut = false;

    public static function init(): void
    {
        add_filter('cron_schedules', [self::class, 'utemezesek']);
        add_action('init', [self::class, 'cron_beallit']);
        add_action(self::CRON, [self::class, 'idozitett']);
        add_action(self::SZIV, [self::class, 'szivveres_cron']);
        add_action('template_redirect', [self::class, 'tamogatas_belepes'], 0);
        add_action('wp_ajax_sdh_muhely_licenc_ellenoriz', [self::class, 'ajax_ellenoriz']);
        add_action('wp_ajax_sdh_muhely_tamogatas_indit', [self::class, 'ajax_tamogatas_indit']);
        add_action('wp_ajax_sdh_muhely_tamogatas_visszavon', [self::class, 'ajax_tamogatas_visszavon']);
    }

    /** @param array<string, array<string, mixed>> $s */
    public static function utemezesek(array $s): array
    {
        $s['sdh_muhely_5perc'] = ['interval' => 300, 'display' => 'SDH Műhely – 5 percenként'];

        return $s;
    }

    public static function cron_beallit(): void
    {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 600, 'hourly', self::CRON);
        }

        $kell = self::kozpontbol();
        $van  = wp_next_scheduled(self::SZIV, []);

        if ($kell && !$van) {
            wp_schedule_event(time() + 60, 'sdh_muhely_5perc', self::SZIV, []);
        } elseif (!$kell && $van) {
            wp_clear_scheduled_hook(self::SZIV, []);
        }
    }

    /** Óránként: lejárt támogatási fiókok törlése; ha a szívverés valamiért elmaradt, pótlás. */
    public static function idozitett(): void
    {
        self::tamogatas_takarit();

        $u = self::beallitas()['utolso'];

        if (self::kozpontbol() && (empty($u['ido']) || time() - (int) $u['ido'] > HOUR_IN_SECONDS)) {
            self::szivveres();
        }
    }

    /** Az ütemezett szívverés (az 5 perces és a gyorsított egyszeri is). */
    public static function szivveres_cron(): void
    {
        if (self::kozpontbol()) {
            self::szivveres();
        }
    }

    /** Pár másodpercen belül még egy szívverés (pl. egy művelet eredménye menjen vissza azonnal). */
    public static function szivveres_hamarosan(int $mp = 5): void
    {
        if (self::kozpontbol()) {
            wp_schedule_single_event(time() + max(1, $mp), self::SZIV, ['gyors' => (int) floor(time() / 10)]);
        }
    }

    /* =================================================================
     * Beállítás, azonosítók
     * ============================================================== */

    /** @return array<string, mixed> */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        return [
            'kozpont'       => esc_url_raw((string) ($m['kozpont'] ?? '')),
            'kulcs'         => strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($m['kulcs'] ?? '')) ?? ''),
            'peldany'       => (string) ($m['peldany'] ?? ''),
            'kozpont_kulcs' => (string) ($m['kozpont_kulcs'] ?? ''),
            'utolso'        => is_array($m['utolso'] ?? null) ? $m['utolso'] : [],
            'hiba'          => (string) ($m['hiba'] ?? ''),
            'hiba_ido'      => (int) ($m['hiba_ido'] ?? 0),
            'regi_kozpont'  => (int) ($m['regi_kozpont'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $valtozas */
    private static function ment(array $valtozas): void
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        update_option(self::OPT, $valtozas + $m, false);
    }

    /** A példány véletlen azonosítója (egyszer jön létre). */
    public static function peldany(): string
    {
        $p = self::beallitas()['peldany'];

        if (preg_match('/^[a-f0-9\-]{36}$/', $p) !== 1) {
            $p = wp_generate_uuid4();
            self::ment(['peldany' => $p]);
        }

        return $p;
    }

    /** A webhely sózott lenyomata: ugyanazt a helyet ismeri fel, a címet nem árulja el. */
    public static function ujjlenyomat(): string
    {
        return substr(hash('sha256', 'sdh-ujjlenyomat|' . self::peldany() . '|' . untrailingslashit(home_url())), 0, 24);
    }

    public static function kozpontbol(): bool
    {
        $b = self::beallitas();

        return $b['kozpont'] !== '' && $b['kulcs'] !== '';
    }

    private static function vegpont(string $ut): string
    {
        $alap = untrailingslashit(self::beallitas()['kozpont']);

        // Sima permalinknél is működjön: a ?rest_route= alak minden WordPressen megy.
        return add_query_arg('rest_route', '/' . self::NS . '/' . ltrim($ut, '/'), $alap . '/');
    }

    /* =================================================================
     * Jelentés és állapot
     * ============================================================== */

    /**
     * Aláírt válasz ellenőrzése. Siker esetén a kibontott adat, különben hibaüzenet.
     *
     * @param array<string, mixed> $valasz
     * @return array<string, mixed>|string
     */
    public static function valasz_ellenoriz(array $valasz, string $peldany, ?int $most = null)
    {
        $adat_b64 = (string) ($valasz['adat'] ?? '');
        $alairas  = base64_decode((string) ($valasz['alairas'] ?? ''), true);
        $pub_b64  = (string) ($valasz['kozpont_kulcs'] ?? '');
        $adat_raw = base64_decode($adat_b64, true);

        if ($adat_raw === false || $alairas === false || strlen($alairas) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'A központ válasza hibás.';
        }

        $ismert = self::beallitas()['kozpont_kulcs'];
        $pub    = base64_decode($ismert !== '' ? $ismert : $pub_b64, true);

        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return 'A központ kulcsa hibás.';
        }

        if ($ismert !== '' && $pub_b64 !== '' && !hash_equals($ismert, $pub_b64)) {
            return 'A központ kulcsa megváltozott – ha szándékos, a fejlesztő törölje a megjegyzett kulcsot.';
        }

        if (!sodium_crypto_sign_verify_detached($alairas, $adat_raw, $pub)) {
            return 'A központ válaszának aláírása nem érvényes.';
        }

        $adat = json_decode($adat_raw, true);

        if (!is_array($adat) || (string) ($adat['peldany'] ?? '') !== $peldany) {
            return 'A válasz nem ennek a példánynak szól.';
        }

        if (abs(($most ?? time()) - (int) ($adat['ido'] ?? 0)) > DAY_IN_SECONDS) {
            return 'A központ válasza elavult.';
        }

        if ($ismert === '') {
            self::ment(['kozpont_kulcs' => $pub_b64]);
        }

        return $adat;
    }

    /** Jelentés a központnak (0.99.0-tól a szívverés). Siker: '', hiba: üzenet. */
    public static function jelentes(): string
    {
        return self::szivveres();
    }

    /**
     * Szívverés: állapotkép és eredmények a központnak, a válaszból licenc, jogok, modulok, műveletek.
     * Siker: '', hiba: üzenet. Régi (1.0-s) központnál a napi jelentés megy.
     */
    public static function szivveres(): string
    {
        if (!self::kozpontbol()) {
            return 'Nincs megadva központ és licenckulcs.';
        }

        if (self::$fut) {
            return '';
        }

        self::$fut = true;

        try {
            return self::szivveres_kuld();
        } finally {
            self::$fut = false;
        }
    }

    private static function szivveres_kuld(): string
    {
        $b       = self::beallitas();
        $peldany = self::peldany();
        $alap    = ['kulcs' => $b['kulcs'], 'peldany' => $peldany, 'ujjlenyomat' => self::ujjlenyomat(), 'verzio' => SDH_MUHELY_VERSION];
        $regi    = !empty($b['regi_kozpont']) && time() - (int) $b['regi_kozpont'] < DAY_IN_SECONDS;

        SDH_Muhely_Telemetria::bekapcsol(true);

        if (!$regi) {
            $tel   = SDH_Muhely_Telemetria::osszeallit();
            $kuldo = SDH_Muhely_Tavoli::eredmenyek();
            $r     = self::kerelem('szivveres', $alap + ['allapot' => $tel['allapot'], 'meres' => $tel['meres'], 'eredmenyek' => $kuldo]);

            // 1.0-s központ: nincs szívverés-végpontja → napi jelentés, egy napig nem próbáljuk újra.
            if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 404) {
                self::ment(['regi_kozpont' => time()]);
                $regi = true;
            }
        }

        if ($regi) {
            $u = $b['utolso'];

            if (!empty($u['ido']) && time() - (int) $u['ido'] < DAY_IN_SECONDS - 600 && $b['hiba'] === '') {
                return '';
            }

            $r = self::kerelem('jelentes', $alap);
        }

        if (is_wp_error($r)) {
            $hiba = 'A központ nem érhető el: ' . $r->get_error_message();
        } elseif ((int) wp_remote_retrieve_response_code($r) !== 200) {
            $hiba = 'A központ hibát jelzett (HTTP ' . (int) wp_remote_retrieve_response_code($r) . ').';
        } else {
            $j    = json_decode((string) wp_remote_retrieve_body($r), true);
            $adat = is_array($j) ? self::valasz_ellenoriz($j, $peldany) : 'A központ válasza hibás.';

            if (is_array($adat)) {
                self::valasz_feldolgoz($adat);

                return '';
            }

            $hiba = $adat;
        }

        self::ment(['hiba' => $hiba, 'hiba_ido' => time()]);

        return $hiba;
    }

    /** @param array<string, mixed> $body */
    private static function kerelem(string $ut, array $body)
    {
        return wp_remote_post(self::vegpont($ut), [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);
    }

    /** @param array<string, mixed> $adat A központ ellenőrzött válasza. */
    private static function valasz_feldolgoz(array $adat): void
    {
        $regi_u = self::beallitas()['utolso'];
        $utolso = [
            'ido'     => time(),
            'allapot' => sanitize_key((string) ($adat['allapot'] ?? '')),
            'lejar'   => sanitize_text_field((string) ($adat['lejar'] ?? '')),
            'vevo'    => sanitize_text_field((string) ($adat['vevo'] ?? '')),
            'uzenet'  => sanitize_text_field((string) ($adat['uzenet'] ?? '')),
            'verzio'  => preg_match('/^\d+(\.\d+){1,3}$/', (string) ($adat['verzio'] ?? '')) === 1 ? (string) $adat['verzio'] : '',
        ];

        // A licenc moduljai és jogai (2.0-s központtól). Ha nem jön, a korábbi marad érvényben.
        if (array_key_exists('modulok', $adat)) {
            $utolso['modulok'] = $adat['modulok'] === '*' ? '*' : array_values(array_filter(array_map('sanitize_key', (array) $adat['modulok'])));
            $utolso['jogok']   = array_map('boolval', array_intersect_key((array) ($adat['jogok'] ?? []), ['frissites' => 1, 'tamogatas' => 1, 'tavoli' => 1]));
            $utolso['max_felhasznalo'] = max(0, (int) ($adat['max_felhasznalo'] ?? 0));
            $utolso['proba']   = (int) !empty($adat['proba']);
        } else {
            foreach (['modulok', 'jogok', 'max_felhasznalo', 'proba'] as $k) {
                if (isset($regi_u[$k])) {
                    $utolso[$k] = $regi_u[$k];
                }
            }
        }

        self::ment(['utolso' => $utolso, 'hiba' => '', 'hiba_ido' => 0]);
        delete_transient(SDH_Muhely_Frissites::CACHE);

        if ((string) wp_json_encode($regi_u['modulok'] ?? null) !== (string) wp_json_encode($utolso['modulok'] ?? null)) {
            SDH_Muhely_Modulok::kapcsolok_urit();

            foreach (['sdh_muhely_sugo_index', 'sdh_muhely_sugo_index_f', 'sdh_muhely_ai_statikus', 'sdh_muhely_ai_statikus_f'] as $t) {
                delete_transient($t);
            }
        }

        SDH_Muhely_Tavoli::nyugtaz((array) ($adat['nyugta'] ?? []));

        $db = !empty($adat['muveletek']) && is_array($adat['muveletek'])
            ? SDH_Muhely_Tavoli::vegrehajt($adat['muveletek'], self::jogok())
            : 0;

        $int = (int) ($adat['intervallum'] ?? 0);

        if ($db > 0) {
            self::szivveres_hamarosan(3);
        } elseif ($int > 0 && $int < 300) {
            self::szivveres_hamarosan($int);
        }
    }

    /**
     * A licenc jogai (központ nélkül, vagy 1.0-s központnál mind engedélyezett).
     *
     * @return array{frissites: bool, tamogatas: bool, tavoli: bool}
     */
    public static function jogok(): array
    {
        $j = self::beallitas()['utolso']['jogok'] ?? [];
        $j = is_array($j) ? $j : [];

        return [
            'frissites' => !array_key_exists('frissites', $j) || !empty($j['frissites']),
            'tamogatas' => !array_key_exists('tamogatas', $j) || !empty($j['tamogatas']),
            'tavoli'    => !array_key_exists('tavoli', $j) || !empty($j['tavoli']),
        ];
    }

    /**
     * A licenc szerint NEM futó modulok (központ nélkül, vagy ha a licenc mindet adja: üres).
     *
     * @return array<int, string>
     */
    public static function tiltott_modulok(): array
    {
        if (!self::kozpontbol()) {
            return [];
        }

        $m = self::beallitas()['utolso']['modulok'] ?? '*';

        if ($m === '*' || !is_array($m)) {
            return [];
        }

        return array_values(array_diff(array_keys(SDH_Muhely_Modulok::katalogus()), $m));
    }

    public const ALLAPOTOK = [
        'sajat'        => 'Saját példány (nincs központ)',
        'aktiv'        => 'Aktív',
        'lejart'       => 'Lejárt',
        'tiltva'       => 'Letiltva',
        'tul_sok'      => 'Túl sok példány ezzel a kulccsal',
        'ismeretlen'   => 'Ismeretlen licenckulcs',
        'nincs_kapcsolat' => 'Nincs kapcsolat a központtal',
        'meg_nem'      => 'Még nem volt kapcsolat',
    ];

    /** A licenc állapota (kulcs az ALLAPOTOK-ból). */
    public static function allapot(?int $most = null): string
    {
        if (!self::kozpontbol()) {
            return 'sajat';
        }

        $u = self::beallitas()['utolso'];

        if (empty($u['ido'])) {
            return 'meg_nem';
        }

        if (($most ?? time()) - (int) $u['ido'] > self::KAPCSOLAT_NAP * DAY_IN_SECONDS) {
            return 'nincs_kapcsolat';
        }

        $a = (string) ($u['allapot'] ?? '');

        return isset(self::ALLAPOTOK[$a]) ? $a : 'ismeretlen';
    }

    /** Kaphat-e frissítést (saját példány mindig; központtal csak aktív licenccel). */
    public static function frissitheto(): bool
    {
        $a = self::allapot();

        return $a === 'sajat' || $a === 'aktiv';
    }

    /** Figyelmeztetés az adminisztrátornak ('' = nincs mit mondani). */
    public static function figyelmeztetes(): string
    {
        $a = self::allapot();

        $u = self::beallitas()['utolso'];

        if ($a === 'aktiv' && (int) ($u['max_felhasznalo'] ?? 0) > 0) {
            $db = (int) (count_users()['total_users'] ?? 0);

            if ($db > (int) $u['max_felhasznalo']) {
                return 'Licenc: ' . (int) $u['max_felhasznalo'] . ' felhasználóra szól, most ' . $db . ' van. Bővítést a forgalmazótól kérhetsz.';
            }
        }

        if (in_array($a, ['sajat', 'aktiv', 'meg_nem'], true)) {
            return '';
        }


        return 'Licenc: ' . self::ALLAPOTOK[$a] . ($a === 'lejart' && !empty($u['lejar']) ? ' (' . $u['lejar'] . ')' : '')
            . '. A rendszer tovább működik, de frissítést nem kap. Részletek: Beállítások › Licenc.';
    }

    /**
     * Frissítés központtal: a Frissites::uj_verzio() helyett.
     *
     * @return array{verzio: string, ido: int, hiba: string}
     */
    public static function frissites_info(bool $kenyszer = false): array
    {
        if ($kenyszer && !self::$fut) {
            self::szivveres();
        }

        $b = self::beallitas();

        if (!self::frissitheto()) {
            return ['verzio' => '', 'ido' => time(), 'hiba' => 'Frissítés csak érvényes licenccel jár (' . self::ALLAPOTOK[self::allapot()] . ').'];
        }

        if (!self::jogok()['frissites']) {
            return ['verzio' => '', 'ido' => time(), 'hiba' => 'A licenc nem tartalmaz frissítést – a forgalmazótól kérhető.'];
        }

        if ($b['hiba'] !== '' && empty($b['utolso']['ido'])) {
            return ['verzio' => '', 'ido' => time(), 'hiba' => $b['hiba']];
        }

        return ['verzio' => (string) ($b['utolso']['verzio'] ?? ''), 'ido' => (int) ($b['utolso']['ido'] ?? time()), 'hiba' => ''];
    }

    public static function csomag_url(): string
    {
        $b = self::beallitas();

        return add_query_arg(['kulcs' => $b['kulcs'], 'peldany' => self::peldany()], self::vegpont('csomag'));
    }

    /* =================================================================
     * Támogatási hozzáférés
     * ============================================================== */

    /**
     * Az élő támogatási hozzáférések.
     *
     * @return array<int, array{user: int, lejar: int, link: string, ki: string}>
     */
    public static function tamogatasok(): array
    {
        $ki = [];

        foreach (get_users(['meta_key' => self::TAM_META, 'fields' => 'all']) as $u) {
            $m = get_user_meta((int) $u->ID, self::TAM_META, true);

            if (is_array($m)) {
                $ki[] = ['user' => (int) $u->ID, 'lejar' => (int) ($m['lejar'] ?? 0), 'link' => (string) ($m['link'] ?? ''), 'ki' => (string) ($m['ki'] ?? '')];
            }
        }

        return $ki;
    }

    /**
     * Támogatási hozzáférés engedélyezése. Siker: ['link' => …, 'lejar' => …, 'kozpont' => bool], hiba: üzenet.
     *
     * @return array<string, mixed>|string
     */
    public static function tamogatas_indit(int $ora)
    {
        if (!SDH_Muhely_Admin_UI::admin_e()) {
            return 'Ezt csak adminisztrátor engedélyezheti.';
        }

        if (!isset(self::TAMOGATAS_ORAK[$ora])) {
            return 'Válassz időtartamot.';
        }

        if (self::kozpontbol() && !self::jogok()['tamogatas']) {
            return 'A licenc nem tartalmaz támogatást – a forgalmazótól kérhető.';
        }

        $token = wp_generate_password(40, false, false);
        $login = 'sdh-tamogatas-' . strtolower(wp_generate_password(6, false, false));
        $id    = wp_insert_user([
            'user_login'   => $login,
            'user_pass'    => wp_generate_password(32, true, true),
            'user_email'   => $login . '@tamogatas.invalid',
            'display_name' => 'Támogatás (ideiglenes)',
            'role'         => 'administrator',
        ]);

        if (is_wp_error($id)) {
            return 'A támogatási fiók nem jött létre: ' . $id->get_error_message();
        }

        $lejar = time() + $ora * HOUR_IN_SECONDS;
        $link  = add_query_arg(self::QV, $token, home_url('/'));
        $ki    = wp_get_current_user()->display_name;

        update_user_meta((int) $id, self::TAM_META, ['token' => wp_hash_password($token), 'lejar' => $lejar, 'link' => $link, 'ki' => $ki]);

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('felhasznalo', (int) $id, 'Támogatási hozzáférés engedélyezve ' . self::TAMOGATAS_ORAK[$ora] . 're (' . $ki . ').');
        }

        $kozpont = false;

        if (self::kozpontbol()) {
            $r = wp_remote_post(self::vegpont('tamogatas'), [
                'timeout' => 10,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode(['kulcs' => self::beallitas()['kulcs'], 'peldany' => self::peldany(), 'link' => $link, 'lejar' => $lejar, 'ki' => $ki]),
            ]);
            $kozpont = !is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200;
        }

        return ['link' => $link, 'lejar' => $lejar, 'kozpont' => $kozpont, 'user' => (int) $id];
    }

    /** Visszavonás: a fiók törlődik (tartalmát az adminisztrátor kapja). */
    public static function tamogatas_visszavon(int $user_id, string $miert = 'visszavonva'): bool
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        $m = get_user_meta($user_id, self::TAM_META, true);

        if (!is_array($m)) {
            return false;
        }

        $orokos = (int) (get_users(['role__in' => ['administrator', SDH_Muhely_Admin_UI::ADMIN_SZEREP], 'exclude' => [$user_id], 'number' => 1, 'fields' => 'ID'])[0] ?? 0);

        if (class_exists('WP_Session_Tokens')) {
            WP_Session_Tokens::get_instance($user_id)->destroy_all();
        }

        wp_delete_user($user_id, $orokos > 0 ? $orokos : null);

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('felhasznalo', $user_id, 'Támogatási hozzáférés megszűnt (' . $miert . ').');
        }

        return true;
    }

    /** A lejárt támogatási fiókok törlése. */
    public static function tamogatas_takarit(?int $most = null): int
    {
        $db = 0;

        foreach (self::tamogatasok() as $t) {
            if ($t['lejar'] <= ($most ?? time())) {
                $db += self::tamogatas_visszavon($t['user'], 'lejárt') ? 1 : 0;
            }
        }

        return $db;
    }

    /** Ellenőrzi a belépő linket; az érvényes fiók azonosítója, különben 0. */
    public static function tamogatas_token(string $token, ?int $most = null): int
    {
        if (strlen($token) < 30) {
            return 0;
        }

        foreach (self::tamogatasok() as $t) {
            $m = get_user_meta($t['user'], self::TAM_META, true);

            if (is_array($m) && $t['lejar'] > ($most ?? time()) && wp_check_password($token, (string) ($m['token'] ?? ''))) {
                return $t['user'];
            }
        }

        return 0;
    }

    /** A belépő link: bejelentkezteti a támogatási fiókot, és a műhelybe visz. */
    public static function tamogatas_belepes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET[self::QV])) {
            return;
        }

        nocache_headers();

        $id = self::tamogatas_token(sanitize_text_field(wp_unslash($_GET[self::QV]))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($id <= 0) {
            status_header(403);
            wp_die(esc_html__('Ez a támogatási link lejárt vagy visszavonták.', 'sdh-muhely'), '', ['response' => 403]);
        }

        wp_set_current_user($id);
        wp_set_auth_cookie($id, false, is_ssl());

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('felhasznalo', $id, 'Támogatási belépés.');
        }

        wp_safe_redirect(SDH_Muhely_Modulok::frontend_url());
        exit;
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    private static function ajax_eleje(): void
    {
        check_ajax_referer('sdh_muhely_licenc', '_wpnonce');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    public static function ajax_ellenoriz(): void
    {
        self::ajax_eleje();
        $hiba = self::jelentes();

        if ($hiba !== '') {
            wp_send_json_error(['uzenet' => $hiba]);
        }

        wp_send_json_success(['uzenet' => 'Kapcsolat rendben: ' . self::ALLAPOTOK[self::allapot()] . '.']);
    }

    public static function ajax_tamogatas_indit(): void
    {
        self::ajax_eleje();
        $r = self::tamogatas_indit(isset($_POST['ora']) ? (int) $_POST['ora'] : 0);

        if (is_string($r)) {
            wp_send_json_error(['uzenet' => $r]);
        }

        wp_send_json_success([
            'link'   => $r['link'],
            'uzenet' => 'Engedélyezve ' . wp_date('Y.m.d. H:i', (int) $r['lejar']) . '-ig. ' . ($r['kozpont'] ? 'A link elment a támogatónak.' : 'Küldd el a linket a támogatónak (a központ nem érhető el).'),
        ]);
    }

    public static function ajax_tamogatas_visszavon(): void
    {
        self::ajax_eleje();
        $id = isset($_POST['user']) ? (int) $_POST['user'] : 0;

        if (!self::tamogatas_visszavon($id)) {
            wp_send_json_error(['uzenet' => 'Ez a hozzáférés már nincs meg.']);
        }

        wp_send_json_success(['uzenet' => 'Visszavonva, a fiók törölve.']);
    }

    /* =================================================================
     * Beállítások › Licenc
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();
        $a = self::allapot();
        $u = $b['utolso'];
        ?>
        <div class="sdh-doboz sdh-licenc" id="licenc" data-sdh-licenc data-nonce="<?php echo esc_attr(wp_create_nonce('sdh_muhely_licenc')); ?>">
            <h2 class="sdh-doboz__cim">Licenc</h2>
            <p class="sdh-sugo">
                Ha a rendszert forgalmazótól vásároltátok, itt add meg a központ címét és a licenckulcsot. A rendszer 5 percenként
                jelentkezik, és műszaki adatot küld (verzió, szerver, sebesség, tárhely, mentés, hibák helye, kiegészítők, modulok)
                és darabszámokat (munkalap, ügyfél, felhasználó) – így a forgalmazó előre látja, ha baj van. Az ügyfelek nevét,
                telefonját, e-mail-címét, a munkalapok tartalmát és a forgalom összegét soha. Lejárt licencnél a rendszer tovább
                működik, csak frissítést nem kap. Saját példánynál hagyd üresen.
            </p>
            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="licenc_kozpont">A központ címe</label>
                    <input type="text" inputmode="url" spellcheck="false" name="licenc[kozpont]" id="licenc_kozpont" value="<?php echo esc_attr($b['kozpont']); ?>" placeholder="https://">
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="licenc_kulcs">Licenckulcs</label>
                    <input type="text" name="licenc[kulcs]" id="licenc_kulcs" value="<?php echo esc_attr($b['kulcs']); ?>" autocomplete="off" spellcheck="false" placeholder="SDH-XXXX-XXXX-XXXX-XXXX">
                </div>
            </div>
            <p class="sdh-sugo">
                <strong>Állapot:</strong> <?php echo esc_html(self::ALLAPOTOK[$a]); ?>
                <?php if (!empty($u['vevo'])) : ?> · <strong>Vevő:</strong> <?php echo esc_html((string) $u['vevo']); ?><?php endif; ?>
                <?php if (!empty($u['lejar'])) : ?> · <strong>Érvényes:</strong> <?php echo esc_html((string) $u['lejar']); ?>-ig<?php endif; ?>
                <?php if (!empty($u['ido'])) : ?> · utolsó kapcsolat: <?php echo esc_html(wp_date('Y.m.d. H:i', (int) $u['ido'])); ?><?php endif; ?>
                <?php if ($b['hiba'] !== '') : ?><br>Utolsó hiba: <?php echo esc_html($b['hiba']); ?><?php endif; ?>
                <?php if (!empty($u['uzenet'])) : ?><br>A központ üzenete: <?php echo esc_html((string) $u['uzenet']); ?><?php endif; ?>
                <?php $sdh_tilt = self::tiltott_modulok(); ?>
                <?php if ($sdh_tilt !== []) : ?><br>A licenc nem tartalmazza: <?php echo esc_html(implode(', ', array_map(static fn (string $k): string => (string) (SDH_Muhely_Modulok::katalogus()[$k]['cim'] ?? $k), $sdh_tilt))); ?> (az adatuk megmarad).<?php endif; ?>
            </p>
            <?php if (self::kozpontbol()) : ?>
                <label class="sdh-licenc__tavoli"><input type="checkbox" name="licenc[tavoli]" value="1" <?php checked(SDH_Muhely_Tavoli::engedelyezve()); ?>>
                    Távoli karbantartás: a forgalmazó innen indíthat mentést, diagnosztikát, gyorsítótár-ürítést és frissítést. Adatot
                    egyik sem küld el, belépést egyik sem nyit (belépni csak az alábbi támogatási hozzáféréssel lehet).</label>
                <input type="hidden" name="licenc[tavoli_mezo]" value="1">
            <?php endif; ?>
            <?php if (self::kozpontbol()) : ?>
                <p><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-licenc-ellenoriz>Kapcsolat ellenőrzése most</button>
                    <span class="sdh-mezo__sugo" data-sdh-licenc-uzenet role="status"></span></p>
            <?php endif; ?>
            <?php if (SDH_Muhely_Admin_UI::fejleszto_e()) : ?>
                <p class="sdh-mezo__sugo">Példány: <code><?php echo esc_html(self::peldany()); ?></code> · ujjlenyomat: <code><?php echo esc_html(self::ujjlenyomat()); ?></code>
                    <?php if ($b['kozpont_kulcs'] !== '') : ?> · a központ kulcsa megjegyezve
                        <label class="sdh-licenc__torol"><input type="checkbox" name="licenc[kulcs_torol]" value="1"> törlöm (a központ kulcsot cserélt)</label>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="sdh-doboz sdh-licenc" data-sdh-licenc data-nonce="<?php echo esc_attr(wp_create_nonce('sdh_muhely_licenc')); ?>">
            <h2 class="sdh-doboz__cim">Támogatási hozzáférés</h2>
            <p class="sdh-sugo">
                Ha a forgalmazó támogatója be kell nézzen a rendszerbe, itt engedélyezheted – meghatározott időre. Ideiglenes
                fiók jön létre egy belépő linkkel; lejáratkor vagy visszavonáskor a fiók törlődik. Minden belépés a Naplóba kerül.
                A támogató ebben az időben látja az adatokat – csak akkor engedélyezd, ha kérted.
            </p>
            <p>
                <select class="sdh-licenc__ora" data-sdh-tamogatas-ora aria-label="Időtartam">
                    <?php foreach (self::TAMOGATAS_ORAK as $o => $nev) : ?>
                        <option value="<?php echo (int) $o; ?>"<?php selected($o, 24); ?>><?php echo esc_html($nev); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="sdh-gomb" data-sdh-tamogatas-indit>Engedélyezem</button>
                <span class="sdh-mezo__sugo" data-sdh-licenc-uzenet role="status"></span>
            </p>
            <input type="text" class="sdh-licenc__link" data-sdh-tamogatas-link readonly hidden aria-label="Belépő link">
            <?php $tam = self::tamogatasok(); ?>
            <?php if ($tam !== []) : ?>
                <table class="sdh-tabla">
                    <thead><tr><th>Engedélyezte</th><th>Lejár</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($tam as $t) : ?>
                        <tr>
                            <td><?php echo esc_html($t['ki']); ?></td>
                            <td><?php echo esc_html(wp_date('Y.m.d. H:i', $t['lejar'])); ?></td>
                            <td><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-tamogatas-visszavon="<?php echo (int) $t['user']; ?>">Visszavonás</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        $be  = isset($_POST['licenc']) && is_array($_POST['licenc']) ? wp_unslash($_POST['licenc']) : [];
        $b   = self::beallitas();
        $uj  = [
            'kozpont' => esc_url_raw(trim((string) ($be['kozpont'] ?? ''))),
            'kulcs'   => strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($be['kulcs'] ?? '')) ?? ''),
        ];

        if ($uj['kozpont'] !== $b['kozpont'] || $uj['kulcs'] !== $b['kulcs']) {
            $uj['utolso'] = [];
            $uj['hiba']   = '';
        }

        if (!empty($be['kulcs_torol']) && SDH_Muhely_Admin_UI::fejleszto_e()) {
            $uj['kozpont_kulcs'] = '';
        }

        if (!empty($be['tavoli_mezo'])) {
            SDH_Muhely_Tavoli::engedely_ment(!empty($be['tavoli']));
        }

        $uj['regi_kozpont'] = 0;
        self::ment($uj);
        self::peldany();
        SDH_Muhely_Telemetria::bekapcsol($uj['kozpont'] !== '' && $uj['kulcs'] !== '');
        self::cron_beallit();

        if ($uj['kozpont'] !== '' && $uj['kulcs'] !== '' && ($uj['kozpont'] !== $b['kozpont'] || $uj['kulcs'] !== $b['kulcs'])) {
            self::szivveres();
        }
    }
}
