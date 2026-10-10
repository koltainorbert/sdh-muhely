<?php
/**
 * AI-asszisztens (0.38) – a CRM „élő" segítője, Claude (Anthropic) alapon.
 *
 * Mit tud:
 *  - Kérdezni lehet tőle bármit a rendszerről: a modulokat, a funkciókat, a
 *    munkalapokat, ügyfeleket, a pénztárat – az eszközeivel belelát (olvas).
 *  - Lépésenként vezet: a képernyőn megmutatja (neon kerettel), hova kell
 *    kattintani, és gombot ad a megfelelő oldalra.
 *  - Mindig kéznél van: minden CRM-oldal jobb alsó sarkában él (asszisztens.js),
 *    pislog, figyel, szól, ha valami sürgős (lejárt határidő, lezáratlan
 *    kassza, jóváhagyásra váró javaslat, új verzió), és ha egy űrlap hibát
 *    dob, felajánlja a segítséget.
 *  - Tanul: a rendszerből építi a tudását (modulok, verziónapló, séma,
 *    beállítások), verzióváltáskor megtanulja az újdonságokat, és a
 *    tudástárába jóváhagyással új tudást ment.
 *
 * Amit SOHA nem tesz magától: adatot módosítani. Minden módosítás javaslat
 * (sdh_ai_muvelet), amit ember hagy jóvá; átíráshoz és törléshez a
 * felhasználó kifejezett kérése ÉS megerősítő kód kell. Végrehajtás előtt
 * a rekord másolata (és naponta teljes mentés) készül, a művelet
 * visszaállítható. Lásd: SDH_Muhely_Asszisztens_Eszkozok, _Tudas.
 *
 * API-kulcs: wp-config.php `SDH_MUHELY_CLAUDE_KEY` (vagy `SDH_CLAUDE_API_KEY`),
 * különben az Asszisztens beállításai, végül a Levelezésnél megadott kulcs.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Asszisztens
{
    public const KULCS = 'asszisztens';

    private const OPTION = 'sdh_muhely_ai';

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Az alapmodell; ha az API nem ismeri, a tartalék fut. */
    private const ALAP_MODELL = 'claude-sonnet-5-5';

    private const TARTALEK_MODELL = 'claude-haiku-4-5-20251001';

    /** Egy kérdésre legfeljebb ennyi eszköz-kör. */
    private const MAX_KOR = 6;

    /** A javaslat ennyi idő után lejár (nem lehet már jóváhagyni). */
    private const JAVASLAT_ELETTARTAM = 6 * HOUR_IN_SECONDS;

    /** Kifejezett módosítási / törlési szándék a felhasználó szövegében (ékezet nélkül). */
    private const SZANDEK = '/(torol|torl|toroljed|atir|irdat|ird at|irjad at|modosit|valtoztat|allitsd|allitsa|allits|cserel|javitsd|javitsa|frissitsd|legyen|tedd|ird be|zard|lezar|emeld|csokkentsd|nullazd|attesz|atteni|rakd|vedd ki|kapcsold ki|felejtsd|ird felul|irja felul)/';

    public static function init(): void
    {
        $muveletek = [
            'ai_kerdez'      => 'ajax_kerdez',
            'ai_elozmeny'    => 'ajax_elozmeny',
            'ai_uj'          => 'ajax_uj',
            'ai_dont'        => 'ajax_dont',
            'ai_visszaallit' => 'ajax_visszaallit',
            'ai_ertekel'     => 'ajax_ertekel',
            'ai_tanit'       => 'ajax_tanit',
            'ai_beallit'     => 'ajax_beallit',
            'ai_teszt'       => 'ajax_teszt',
            'ai_mentes'      => 'ajax_mentes',
            'ai_mentes_le'   => 'ajax_mentes_le',
            'aitudas_urlap'  => 'ajax_tudas_urlap',
            'aitudas_ment'   => 'ajax_tudas_ment',
        ];

        foreach ($muveletek as $nev => $fuggveny) {
            add_action('wp_ajax_sdh_muhely_' . $nev, [self::class, $fuggveny]);
        }

        add_filter('sdh_muhely_pulzus', [self::class, 'pulzus'], 10, 2);

        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Asszisztens',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 90,
            'jelveny' => [self::class, 'fuggo_db'],
        ]);
    }

    /* =================================================================
     * Beállítás, kulcs
     * ============================================================== */

    /**
     * @return array{be: bool, nev: string, modell: string, kulcs: string, olvasas_engedely: bool, proaktiv: bool, napi_max: int, eszkozok: array<string, bool>}
     */
    public static function beallitas(): array
    {
        $b = get_option(self::OPTION, []);
        $b = is_array($b) ? $b : [];

        return [
            'be'               => !isset($b['be']) || (bool) $b['be'],
            'nev'              => isset($b['nev']) && trim((string) $b['nev']) !== '' ? mb_substr((string) $b['nev'], 0, 40) : 'Szikra',
            'modell'           => isset($b['modell']) && trim((string) $b['modell']) !== '' ? (string) $b['modell'] : self::ALAP_MODELL,
            'kulcs'            => (string) ($b['kulcs'] ?? ''),
            'olvasas_engedely' => !empty($b['olvasas_engedely']),
            'proaktiv'         => !isset($b['proaktiv']) || (bool) $b['proaktiv'],
            'napi_max'         => max(10, (int) ($b['napi_max'] ?? 300)),
            'eszkozok'         => is_array($b['eszkozok'] ?? null) ? array_map('boolval', $b['eszkozok']) : [],
        ];
    }

    private static function titok(): string
    {
        return sodium_crypto_generichash('sdh-muhely-ai|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private static function titkosit(string $nyilt): string
    {
        if ($nyilt === '') {
            return '';
        }

        $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:' . base64_encode($n . sodium_crypto_secretbox($nyilt, $n, self::titok()));
    }

    private static function visszafejt(string $t): string
    {
        if (strncmp($t, 'v1:', 3) !== 0) {
            return '';
        }

        $nyers = base64_decode(substr($t, 3), true);

        if ($nyers === false || strlen($nyers) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $ny = sodium_crypto_secretbox_open(substr($nyers, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($nyers, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::titok());

        return $ny === false ? '' : $ny;
    }

    /** @return array{kulcs: string, forras: string} */
    public static function kulcs(): array
    {
        if (defined('SDH_MUHELY_CLAUDE_KEY') && SDH_MUHELY_CLAUDE_KEY) {
            return ['kulcs' => (string) SDH_MUHELY_CLAUDE_KEY, 'forras' => 'wp-config.php (SDH_MUHELY_CLAUDE_KEY)'];
        }

        if (defined('SDH_CLAUDE_API_KEY') && SDH_CLAUDE_API_KEY) {
            return ['kulcs' => (string) SDH_CLAUDE_API_KEY, 'forras' => 'wp-config.php (SDH_CLAUDE_API_KEY)'];
        }

        $sajat = self::visszafejt(self::beallitas()['kulcs']);

        if ($sajat !== '') {
            return ['kulcs' => $sajat, 'forras' => 'Asszisztens beállításai'];
        }

        if (class_exists('SDH_Muhely_Levelezes') && method_exists('SDH_Muhely_Levelezes', 'ai_kulcs')) {
            $l = SDH_Muhely_Levelezes::ai_kulcs();

            if (is_array($l) && (string) ($l['kulcs'] ?? '') !== '') {
                return ['kulcs' => (string) $l['kulcs'], 'forras' => 'Levelezés beállításai'];
            }
        }

        return ['kulcs' => '', 'forras' => ''];
    }

    public static function mukodik(): bool
    {
        return self::beallitas()['be'] && self::kulcs()['kulcs'] !== '';
    }

    /** A JavaScriptnek (a window.SDH_MUHELY.ai). */
    public static function js_adat(): array
    {
        $b = self::beallitas();

        $f = wp_get_current_user();

        return [
            'be'      => $b['be'],
            'nev'     => $b['nev'],
            'en'      => (string) ($f->display_name ?: $f->user_login),
            'kulcs'   => self::kulcs()['kulcs'] !== '',
            'proaktiv' => $b['proaktiv'],
            'oldal'   => SDH_Muhely_Modulok::url(self::KULCS),
        ];
    }

    /* =================================================================
     * Táblák, segédek
     * ============================================================== */

    private static function t(string $nev): string
    {
        return SDH_Muhely_Schema::tabla('ai_' . $nev);
    }

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    private static function admin_jog(): void
    {
        self::jog();

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ehhez adminisztrátori jog kell.'], 403);
        }
    }

    private static function p(string $k, int $max = 255): string
    {
        // phpcs:disable WordPress.Security.NonceVerification -- a hívó ellenőrzi.
        $v = $_POST[$k] ?? ($_GET[$k] ?? '');
        // phpcs:enable

        return is_scalar($v) ? mb_substr(sanitize_text_field(wp_unslash((string) $v)), 0, $max) : '';
    }

    private static function uzenet_ment(int $user, string $szerep, string $szoveg, string $oldal = '', int $be = 0, int $ki = 0): int
    {
        global $wpdb;

        $wpdb->insert(self::t('uzenet'), [
            'user_id' => $user, 'szerep' => $szerep, 'szoveg' => mb_substr($szoveg, 0, 30000),
            'oldal' => mb_substr($oldal, 0, 80), 'be_token' => $be, 'ki_token' => $ki, 'ido' => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }

    /** Az utolsó „új beszélgetés" jel óta a felhasználó üzenetei. */
    private static function elozmeny(int $user, int $max = 40): array
    {
        global $wpdb;

        $t = self::t('uzenet');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hatar = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM {$t} WHERE user_id = %d AND szerep = 'uj'", $user));
        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE user_id = %d AND id > %d AND szerep IN ('user','assistant','esemeny') AND ido >= %s ORDER BY id DESC LIMIT %d",
            $user,
            $hatar,
            wp_date('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS),
            $max
        ));
        // phpcs:enable

        return array_reverse((array) $sorok);
    }

    public static function fuggo_db(): int
    {
        global $wpdb;

        $t = self::t('muvelet');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND allapot = 'javaslat' AND letrehozva >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            get_current_user_id(),
            wp_date('Y-m-d H:i:s', time() - self::JAVASLAT_ELETTARTAM)
        ));
    }

    /* =================================================================
     * Claude API
     * ============================================================== */

    /**
     * @param array<int, array<string, mixed>> $system
     * @param array<int, array<string, mixed>> $uzenetek
     * @param array<int, array<string, mixed>> $eszkozok
     * @return array<string, mixed>|string A válasz, vagy hibaszöveg.
     */
    private static function api(array $system, array $uzenetek, array $eszkozok, ?string $modell = null)
    {
        $k = self::kulcs()['kulcs'];

        if ($k === '') {
            return 'Nincs megadva Claude API-kulcs (Asszisztens › Beállítások, vagy a wp-config.php-ban).';
        }

        $modell = $modell ?? self::beallitas()['modell'];
        $test = [
            'model'       => $modell,
            'max_tokens'  => 1600,
            'temperature' => 0.4,
            'system'      => $system,
            'messages'    => $uzenetek,
        ];

        if ($eszkozok !== []) {
            $test['tools'] = $eszkozok;
        }

        $v = wp_remote_post(self::API_URL, [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => $k,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => (string) wp_json_encode($test),
        ]);

        if (is_wp_error($v)) {
            return 'Nem érem el az AI-t: ' . $v->get_error_message();
        }

        $kod  = (int) wp_remote_retrieve_response_code($v);
        $adat = json_decode((string) wp_remote_retrieve_body($v), true);
        $adat = is_array($adat) ? $adat : [];

        if ($kod === 404 && $modell !== self::TARTALEK_MODELL) {
            // Ismeretlen modellnév: a tartalék modellel újra.
            return self::api($system, $uzenetek, $eszkozok, self::TARTALEK_MODELL);
        }

        if ($kod !== 200) {
            $uz = (string) ($adat['error']['message'] ?? ('HTTP ' . $kod));

            if ($kod === 401) {
                return 'Az API-kulcs érvénytelen (401). Ellenőrizd az Asszisztens beállításaiban.';
            }

            if ($kod === 429 || $kod === 529) {
                return 'Az AI most túlterhelt – próbáld újra fél perc múlva.';
            }

            return 'Az AI hibát adott: ' . mb_substr($uz, 0, 200);
        }

        return $adat;
    }

    /* =================================================================
     * Kérdés
     * ============================================================== */

    /** @return array<int, array<string, mixed>> */
    private static function api_uzenetek(array $sorok): array
    {
        $ki = [];

        foreach ($sorok as $s) {
            $szerep = $s->szerep === 'assistant' ? 'assistant' : 'user';
            $szoveg = $s->szerep === 'esemeny' ? '[Rendszer-esemény – nem a felhasználó írta] ' . (string) $s->szoveg : (string) $s->szoveg;

            if (trim($szoveg) === '') {
                continue;
            }

            if ($ki === [] && $szerep === 'assistant') {
                continue;
            }

            $n = count($ki);

            if ($n && $ki[$n - 1]['role'] === $szerep) {
                $ki[$n - 1]['content'] .= "\n\n" . $szoveg;
                continue;
            }

            $ki[] = ['role' => $szerep, 'content' => $szoveg];
        }

        if ($ki !== [] && end($ki)['role'] !== 'user') {
            $ki[] = ['role' => 'user', 'content' => '[Rendszer-esemény] Folytasd: a fentiek alapján válaszolj a felhasználónak.'];
        }

        return $ki;
    }

    /**
     * @param array<string, mixed>              $oldal  kulcs, cim, ablak
     * @param array<int, array<string, string>> $elemek látható elemek
     */
    private static function dinamikus(string $kerdes, array $oldal, array $elemek, int $user): string
    {
        global $wpdb;

        $f = wp_get_current_user();
        $b = self::beallitas();
        $t = 'MOST: ' . wp_date('Y. F j., l H:i') . "\n"
            . 'FELHASZNÁLÓ: ' . ($f->display_name ?: $f->user_login) . (SDH_Muhely_Admin_UI::admin_e() ? ' (adminisztrátor)' : ' (kolléga)') . "\n"
            . 'OLVASÁSHOZ IS ENGEDÉLY KELL: ' . ($b['olvasas_engedely'] ? 'igen (az olvasó eszköz is javaslatot hoz létre)' : 'nem') . "\n";

        $mk = (string) ($oldal['kulcs'] ?? '');
        $t .= "\nJELENLEGI OLDAL: " . ($oldal['cim'] ?? '') . ($mk !== '' ? ' (' . $mk . ')' : '');

        if (!empty($oldal['ablak'])) {
            $t .= "\nNYITOTT ABLAK (popup): " . mb_substr((string) $oldal['ablak'], 0, 120);
        }

        if (!empty($oldal['hiba'])) {
            $t .= "\nLÁTHATÓ HIBAÜZENET: " . mb_substr((string) $oldal['hiba'], 0, 300);
        }

        if ($elemek !== []) {
            $t .= "\nLÁTHATÓ ELEMEK (azonosító – felirat [fajta]; a `mutat` eszközzel ezekre mutathatsz):\n";

            foreach (array_slice($elemek, 0, 90) as $e) {
                $t .= '- ' . $e['id'] . ' – ' . $e['cimke'] . ' [' . $e['tipus'] . ($e['hely'] !== '' ? ', ' . $e['hely'] : '') . "]\n";
            }
        }

        $tudas = SDH_Muhely_Asszisztens_Tudas::keres($kerdes, 8);

        if ($tudas !== []) {
            $t .= "\nTUDÁSTÁR (a kérdéshez illő; ez a legfrissebb, cégen belüli tudás – ha ide tartozik a kérdés, ezt használd)\n";

            foreach ($tudas as $r) {
                $t .= '[#' . (int) $r->id . '] ' . $r->cim . ': ' . mb_substr((string) $r->szoveg, 0, 1500) . "\n";
            }
        }

        $m = self::t('muvelet');
        $fuggok = $wpdb->get_results($wpdb->prepare(
            "SELECT id, leiras FROM {$m} WHERE user_id = %d AND allapot = 'javaslat' AND letrehozva >= %s ORDER BY id DESC LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $user,
            wp_date('Y-m-d H:i:s', time() - self::JAVASLAT_ELETTARTAM)
        ));

        if ($fuggok) {
            $t .= "\nJÓVÁHAGYÁSRA VÁRÓ JAVASLATAID: ";

            foreach ($fuggok as $j) {
                $t .= '#' . (int) $j->id . ' ' . $j->leiras . '; ';
            }

            $t .= "\n";
        }

        return $t;
    }

    /** Kifejezetten módosítást / törlést kért-e a felhasználó. */
    private static function szandek(string $szoveg): bool
    {
        return preg_match(self::SZANDEK, strtolower(remove_accents($szoveg))) === 1;
    }

    /**
     * Javaslat létrehozása (sdh_ai_muvelet) – a kártya adataival tér vissza.
     *
     * @param array<string, mixed> $elo Az elokeszit() eredménye.
     * @return array<string, mixed>
     */
    private static function javaslat(int $user, string $eszkoz, string $szint, array $elo): array
    {
        global $wpdb;

        $kod = in_array($szint, ['atir', 'torol'], true) ? (string) random_int(1000, 9999) : '';

        $wpdb->insert(self::t('muvelet'), [
            'user_id'     => $user,
            'eszkoz'      => $eszkoz,
            'szint'       => $szint,
            'leiras'      => mb_substr((string) $elo['leiras'], 0, 255),
            'parameterek' => (string) wp_json_encode(['p' => $elo['parameterek'], 'v' => $elo['valtozasok']], JSON_UNESCAPED_UNICODE),
            'allapot'     => 'javaslat',
            'kod'         => $kod,
            'tabla'       => (string) $elo['tabla'],
            'rekord_id'   => (int) $elo['rekord_id'],
            'letrehozva'  => current_time('mysql'),
        ]);

        return self::kartya((int) $wpdb->insert_id);
    }

    /** Egy művelet kártyája a felületnek. */
    public static function kartya(int $id): array
    {
        global $wpdb;

        $m = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('muvelet') . ' WHERE id = %d', $id));

        if (!$m) {
            return [];
        }

        $par  = json_decode((string) $m->parameterek, true);
        $def  = SDH_Muhely_Asszisztens_Eszkozok::definiciok()[(string) $m->eszkoz] ?? ['cim' => (string) $m->eszkoz];
        $allapot = (string) $m->allapot;

        if ($allapot === 'javaslat' && strtotime((string) $m->letrehozva) < current_time('timestamp') - self::JAVASLAT_ELETTARTAM) {
            $allapot = 'lejart';
        }

        return [
            'id'          => (int) $m->id,
            'eszkoz'      => (string) $m->eszkoz,
            'cim'         => (string) $def['cim'],
            'szint'       => (string) $m->szint,
            'leiras'      => (string) $m->leiras,
            'valtozasok'  => is_array($par['v'] ?? null) ? $par['v'] : [],
            'allapot'     => $allapot,
            'kod'         => (string) $m->kod,
            'eredmeny'    => (string) $m->eredmeny,
            'visszaallithato' => $allapot === 'vegrehajtva' && (string) $m->tabla !== '' && (int) $m->rekord_id > 0,
            'ido'         => (string) $m->letrehozva,
            'sajat'       => (int) $m->user_id === get_current_user_id(),
            'ki'          => SDH_Muhely_Munkalap::felelos_nev((int) $m->user_id),
        ];
    }

    public static function ajax_kerdez(): void
    {
        self::jog();

        $b    = self::beallitas();
        $user = get_current_user_id();

        if (!$b['be']) {
            wp_send_json_error(['uzenet' => 'Az asszisztens ki van kapcsolva (Asszisztens › Beállítások).']);
        }

        // Napi keret felhasználónként – az API-költség védelmére.
        $szamlalo = 'sdh_ai_db_' . $user . '_' . current_time('Ymd');
        $db = (int) get_transient($szamlalo);

        if ($db >= $b['napi_max']) {
            wp_send_json_error(['uzenet' => 'Mára elérted a napi keretet (' . $b['napi_max'] . ' kérdés). Holnap újra itt vagyok!']);
        }

        set_transient($szamlalo, $db + 1, DAY_IN_SECONDS);

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a jog() ellenőrzi.
        $szoveg = isset($_POST['szoveg']) ? mb_substr(trim(sanitize_textarea_field(wp_unslash((string) $_POST['szoveg']))), 0, 3000) : '';
        $oldal  = isset($_POST['oldal']) ? json_decode(wp_unslash((string) $_POST['oldal']), true) : [];
        $elemek = isset($_POST['elemek']) ? json_decode(wp_unslash((string) $_POST['elemek']), true) : [];
        // phpcs:enable
        $folytat = self::p('folytat', 2) === '1';

        $oldal = is_array($oldal) ? array_map(static fn ($v) => is_scalar($v) ? sanitize_text_field((string) $v) : '', $oldal) : [];
        $tiszta_elemek = [];

        foreach (is_array($elemek) ? array_slice($elemek, 0, 100) : [] as $e) {
            if (is_array($e) && preg_match('/^e\d{1,4}$/', (string) ($e['id'] ?? '')) === 1) {
                $tiszta_elemek[] = [
                    'id'    => (string) $e['id'],
                    'cimke' => mb_substr(sanitize_text_field((string) ($e['cimke'] ?? '')), 0, 70),
                    'tipus' => mb_substr(sanitize_key((string) ($e['tipus'] ?? '')), 0, 20),
                    'hely'  => mb_substr(sanitize_text_field((string) ($e['hely'] ?? '')), 0, 40),
                ];
            }
        }

        if ($szoveg === '' && !$folytat) {
            wp_send_json_error(['uzenet' => 'Üres kérdés.']);
        }

        if ($szoveg !== '') {
            self::uzenet_ment($user, 'user', $szoveg, (string) ($oldal['kulcs'] ?? ''));
        }

        $sorok = self::elozmeny($user, 24);
        $uzenetek = self::api_uzenetek($sorok);

        if ($uzenetek === []) {
            wp_send_json_error(['uzenet' => 'Üres kérdés.']);
        }

        // Az utolsó valódi felhasználói kérdés (a szándék-ellenőrzéshez és a tudástár-kereséshez).
        $utolso_kerdes = $szoveg;

        if ($utolso_kerdes === '') {
            foreach (array_reverse($sorok) as $s) {
                if ($s->szerep === 'user') {
                    $utolso_kerdes = (string) $s->szoveg;
                    break;
                }
            }
        }

        $system = [
            ['type' => 'text', 'text' => SDH_Muhely_Asszisztens_Tudas::statikus(), 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => self::dinamikus($utolso_kerdes, $oldal, $tiszta_elemek, $user)],
        ];

        $eszkozok = SDH_Muhely_Asszisztens_Eszkozok::api_lista();
        $ui       = [];
        $kartyak  = [];
        $szoveg_ki = '';
        $be_t = 0;
        $ki_t = 0;

        for ($kor = 0; $kor < self::MAX_KOR; $kor++) {
            $v = self::api($system, $uzenetek, $eszkozok);

            if (is_string($v)) {
                if ($szoveg_ki === '' && $kartyak === [] && $ui === []) {
                    wp_send_json_error(['uzenet' => $v]);
                }

                $szoveg_ki .= "\n\n_(" . $v . ')_';
                break;
            }

            $be_t += (int) ($v['usage']['input_tokens'] ?? 0) + (int) ($v['usage']['cache_read_input_tokens'] ?? 0);
            $ki_t += (int) ($v['usage']['output_tokens'] ?? 0);

            $tartalom = is_array($v['content'] ?? null) ? $v['content'] : [];
            $eredmenyek = [];

            foreach ($tartalom as $blokk) {
                if (($blokk['type'] ?? '') === 'text') {
                    $szoveg_ki .= ($szoveg_ki !== '' ? "\n\n" : '') . (string) $blokk['text'];
                    continue;
                }

                if (($blokk['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $nev   = (string) ($blokk['name'] ?? '');
                $param = is_array($blokk['input'] ?? null) ? $blokk['input'] : [];
                $szint = SDH_Muhely_Asszisztens_Eszkozok::szint($nev);
                $engedett = $b['eszkozok'][$nev] ?? true;
                $res = '';
                $hiba = false;

                if ($szint === '' || !$engedett) {
                    $res = 'Ez az eszköz nem elérhető (a Beállításokban kikapcsolták).';
                    $hiba = true;
                } elseif ($szint === 'felulet') {
                    $ui[] = ['tipus' => $nev, 'adat' => self::ui_adat($nev, $param)];
                    $res = 'Megjelenítve a felhasználónak.';
                } elseif ($szint === 'olvas' && !$b['olvasas_engedely']) {
                    $res = SDH_Muhely_Asszisztens_Eszkozok::olvas($nev, $param);
                } elseif ($szint === 'olvas') {
                    $kartyak[] = self::javaslat($user, $nev, 'olvas', [
                        'leiras' => 'Adat lekérése: ' . (SDH_Muhely_Asszisztens_Eszkozok::definiciok()[$nev]['cim'] ?? $nev),
                        'valtozasok' => [['mezo' => 'Lekérdezés', 'regi' => '', 'uj' => mb_substr((string) wp_json_encode($param, JSON_UNESCAPED_UNICODE), 0, 300)]],
                        'tabla' => '', 'rekord_id' => 0, 'parameterek' => $param,
                    ]);
                    $res = 'Jóváhagyásra vár (#' . end($kartyak)['id'] . '): a felhasználó engedélyezi az adat lekérését; ha igen, az eredményt rendszer-eseményként kapod meg. Mondd el röviden, mit szeretnél megnézni és miért.';
                } else {
                    if (in_array($szint, ['atir', 'torol'], true) && !self::szandek($utolso_kerdes)) {
                        $res = 'ELUTASÍTVA: átírást / törlést csak a felhasználó kifejezett kérésére javasolhatsz. Kérdezd meg, szeretné-e (pontosan mit), és csak az igenlő válasz után javasold.';
                        $hiba = true;
                    } else {
                        $elo = SDH_Muhely_Asszisztens_Eszkozok::elokeszit($nev, $param);

                        if (is_string($elo)) {
                            $res = 'Nem javasolható: ' . $elo;
                            $hiba = true;
                        } else {
                            $kartyak[] = self::javaslat($user, $nev, $szint, $elo);
                            $res = 'Javaslat rögzítve (#' . end($kartyak)['id'] . '), JÓVÁHAGYÁSRA VÁR: a felhasználó a kártyán engedélyezi'
                                . (in_array($szint, ['atir', 'torol'], true) ? ' a megerősítő kód beírásával' : '')
                                . ' vagy elutasítja. Még NEM történt meg – ne állítsd, hogy kész.';
                        }
                    }
                }

                $eredmenyek[] = ['type' => 'tool_result', 'tool_use_id' => (string) ($blokk['id'] ?? ''), 'content' => $res] + ($hiba ? ['is_error' => true] : []);
            }

            if (($v['stop_reason'] ?? '') !== 'tool_use' || $eredmenyek === []) {
                break;
            }

            $uzenetek[] = ['role' => 'assistant', 'content' => $tartalom];
            $uzenetek[] = ['role' => 'user', 'content' => $eredmenyek];
        }

        $szoveg_ki = trim($szoveg_ki);

        if ($szoveg_ki === '' && $kartyak !== []) {
            $szoveg_ki = 'Előkészítettem – nézd meg a kártyát, és döntsd el, engedélyezed-e.';
        }

        if ($szoveg_ki === '' && $ui !== []) {
            $szoveg_ki = 'Megmutattam!';
        }

        $menteni = $szoveg_ki;

        foreach ($kartyak as $k) {
            $menteni .= "\n[Javaslat #" . $k['id'] . ': ' . $k['leiras'] . ' – jóváhagyásra vár]';
        }

        $uid = self::uzenet_ment($user, 'assistant', $menteni, (string) ($oldal['kulcs'] ?? ''), $be_t, $ki_t);

        wp_send_json_success([
            'id'      => $uid,
            'valasz'  => $szoveg_ki,
            'ui'      => $ui,
            'kartyak' => $kartyak,
        ]);
    }

    /** @param array<string, mixed> $p */
    private static function ui_adat(string $nev, array $p): array
    {
        if ($nev === 'mutat') {
            return ['elem' => sanitize_key((string) ($p['elem'] ?? '')), 'szoveg' => mb_substr(sanitize_text_field((string) ($p['szoveg'] ?? '')), 0, 240)];
        }

        $cel = sanitize_key((string) ($p['cel'] ?? ''));
        $id  = (int) ($p['id'] ?? 0);
        $felirat = mb_substr(sanitize_text_field((string) ($p['felirat'] ?? '')), 0, 60);

        $popupok = ['munkalap' => 'munkalapok', 'ugyfel' => 'ugyfelek', 'eszkoz' => 'eszkozok'];

        if (isset($popupok[$cel]) && $id > 0) {
            return ['popup' => $popupok[$cel], 'id' => $id, 'felirat' => $felirat !== '' ? $felirat : 'Megnyitás'];
        }

        $modul = $cel === 'attekintes' || $cel === '' ? null : SDH_Muhely_Modulok::egy($cel);

        if ($cel !== 'attekintes' && $cel !== '' && $modul === null) {
            return ['url' => '', 'felirat' => 'Ismeretlen oldal: ' . $cel];
        }

        return [
            'url'     => SDH_Muhely_Modulok::frontend_url($cel === 'attekintes' ? '' : $cel),
            'felirat' => $felirat !== '' ? $felirat : ($modul ? (string) $modul['cim'] : 'Áttekintés') . ' megnyitása',
        ];
    }

    /** A beszélgetés (az utolsó „új" óta) és a függő kártyák. */
    public static function ajax_elozmeny(): void
    {
        self::jog();

        global $wpdb;

        $user = get_current_user_id();
        $ki   = [];

        foreach (self::elozmeny($user, 40) as $s) {
            $ki[] = [
                'id'        => (int) $s->id,
                'szerep'    => (string) $s->szerep,
                'szoveg'    => preg_replace('/\n\[Javaslat #\d+: .+? – jóváhagyásra vár\]/u', '', (string) $s->szoveg),
                'ertekeles' => (int) $s->ertekeles,
            ];
        }

        $m = self::t('muvelet');
        $idk = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$m} WHERE user_id = %d AND (allapot = 'javaslat' OR dontes_ido >= %s) ORDER BY id DESC LIMIT 8", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $user,
            wp_date('Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS)
        ));

        $kartyak = [];

        foreach (array_reverse((array) $idk) as $id) {
            $k = self::kartya((int) $id);

            if ($k !== [] && $k['allapot'] !== 'lejart') {
                $kartyak[] = $k;
            }
        }

        wp_send_json_success(['uzenetek' => $ki, 'kartyak' => $kartyak, 'mukodik' => self::mukodik()]);
    }

    public static function ajax_uj(): void
    {
        self::jog();
        self::uzenet_ment(get_current_user_id(), 'uj', '');
        wp_send_json_success([]);
    }

    /* =================================================================
     * Döntés: jóváhagyás / elutasítás / visszaállítás
     * ============================================================== */

    public static function ajax_dont(): void
    {
        self::jog();

        global $wpdb;

        $user = get_current_user_id();
        $id   = (int) self::p('id', 20);
        $igen = self::p('dontes', 5) === 'igen';
        $kod  = self::p('kod', 12);
        $k    = self::kartya($id);

        if ($k === []) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen javaslat.']);
        }

        $m = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('muvelet') . ' WHERE id = %d', $id));

        if ((int) $m->user_id !== $user && !SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ezt a javaslatot annak kell eldöntenie, akinek készült (vagy adminisztrátornak).']);
        }

        if ($k['allapot'] !== 'javaslat') {
            wp_send_json_error(['uzenet' => $k['allapot'] === 'lejart' ? 'Ez a javaslat lejárt – kérd újra az asszisztenstől.' : 'Erről már döntöttek.', 'kartya' => $k]);
        }

        $most = current_time('mysql');

        if (!$igen) {
            $wpdb->update(self::t('muvelet'), ['allapot' => 'elutasitva', 'dontes_ido' => $most, 'dontes_user' => $user], ['id' => $id]);
            self::uzenet_ment((int) $m->user_id, 'esemeny', '#' . $id . ' javaslatot (' . $m->leiras . ') a felhasználó ELUTASÍTOTTA. Nem történt semmi.');

            wp_send_json_success(['kartya' => self::kartya($id)]);
        }

        if ((string) $m->kod !== '' && trim($kod) !== (string) $m->kod) {
            wp_send_json_error(['uzenet' => 'A megerősítő kód nem egyezik. Írd be pontosan: ' . $m->kod]);
        }

        $par = json_decode((string) $m->parameterek, true);
        $p   = is_array($par['p'] ?? null) ? $par['p'] : [];

        // Olvasás engedéllyel: lefut, az eredmény eseményként megy az asszisztensnek.
        if ((string) $m->szint === 'olvas') {
            $adat = SDH_Muhely_Asszisztens_Eszkozok::olvas((string) $m->eszkoz, $p);
            $wpdb->update(self::t('muvelet'), ['allapot' => 'vegrehajtva', 'dontes_ido' => $most, 'dontes_user' => $user, 'eredmeny' => 'Lekérve.'], ['id' => $id]);
            self::uzenet_ment((int) $m->user_id, 'esemeny', '#' . $id . ' adatlekérés ENGEDÉLYEZVE. Az eredmény (' . $m->eszkoz . '): ' . mb_substr($adat, 0, 8000));

            wp_send_json_success(['kartya' => self::kartya($id), 'folytat' => true]);
        }

        // Mentés: napi teljes mentés (ha ma még nem volt) + a rekord másolata.
        SDH_Muhely_Asszisztens_Tudas::napi_mentes_ha_kell();
        $elotte = SDH_Muhely_Asszisztens_Eszkozok::pillanatkep((string) $m->tabla, (int) $m->rekord_id);

        $wpdb->update(self::t('muvelet'), [
            'allapot' => 'fut', 'dontes_ido' => $most, 'dontes_user' => $user,
            'elotte'  => $elotte !== null ? (string) wp_json_encode($elotte, JSON_UNESCAPED_UNICODE) : null,
        ], ['id' => $id]);

        $r = SDH_Muhely_Asszisztens_Eszkozok::vegrehajt((string) $m->eszkoz, $p, $user);

        if (is_string($r)) {
            $wpdb->update(self::t('muvelet'), ['allapot' => 'hiba', 'eredmeny' => mb_substr($r, 0, 500)], ['id' => $id]);
            self::uzenet_ment((int) $m->user_id, 'esemeny', '#' . $id . ' végrehajtása NEM sikerült: ' . $r);

            wp_send_json_error(['uzenet' => $r, 'kartya' => self::kartya($id)]);
        }

        $utana = SDH_Muhely_Asszisztens_Eszkozok::pillanatkep($r['tabla'], $r['rekord_id']);

        $wpdb->update(self::t('muvelet'), [
            'allapot'   => 'vegrehajtva',
            'tabla'     => $r['tabla'],
            'rekord_id' => $r['rekord_id'],
            'utana'     => $utana !== null ? (string) wp_json_encode($utana, JSON_UNESCAPED_UNICODE) : null,
            'eredmeny'  => mb_substr($r['eredmeny'], 0, 500),
        ], ['id' => $id]);

        self::uzenet_ment((int) $m->user_id, 'esemeny', '#' . $id . ' (' . $m->leiras . ') JÓVÁHAGYVA és VÉGREHAJTVA: ' . $r['eredmeny'] . ' (mentés készült, visszaállítható)');

        wp_send_json_success(['kartya' => self::kartya($id)]);
    }

    /** Végrehajtott művelet visszaállítása (megerősítő kóddal: a művelet azonosítója). */
    public static function ajax_visszaallit(): void
    {
        self::jog();

        global $wpdb;

        $user = get_current_user_id();
        $id   = (int) self::p('id', 20);
        $m    = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('muvelet') . ' WHERE id = %d', $id));

        if (!$m || (string) $m->allapot !== 'vegrehajtva') {
            wp_send_json_error(['uzenet' => 'Ez a művelet nem állítható vissza.']);
        }

        if ((int) $m->user_id !== $user && !SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Csak a saját műveletedet állíthatod vissza (vagy adminisztrátor).']);
        }

        if (self::p('kod', 12) !== (string) $id) {
            wp_send_json_error(['uzenet' => 'A megerősítéshez írd be a művelet számát: ' . $id]);
        }

        $elotte = $m->elotte !== null ? json_decode((string) $m->elotte, true) : null;
        $utana  = $m->utana !== null ? json_decode((string) $m->utana, true) : null;
        $most   = SDH_Muhely_Asszisztens_Eszkozok::pillanatkep((string) $m->tabla, (int) $m->rekord_id);

        // Ha azóta valaki más is módosította a rekordot, nem írjuk felül vakon.
        if (is_array($elotte) && is_array($utana) && is_array($most)) {
            $hasonlit = static function (array $a): array {
                unset($a['modositva']);
                ksort($a);

                return $a;
            };

            if ($hasonlit($most) != $hasonlit($utana) && self::p('eroltet', 2) !== '1') { // phpcs:ignore Universal.Operators.StrictComparisons
                wp_send_json_error(['uzenet' => 'A rekord a művelet óta megváltozott – a visszaállítás azt is felülírná. Nézd meg kézzel, vagy erősítsd meg még egyszer.', 'eroltetheto' => true]);
            }
        }

        SDH_Muhely_Asszisztens_Tudas::napi_mentes_ha_kell();

        $uz = SDH_Muhely_Asszisztens_Eszkozok::visszaallit((string) $m->eszkoz, (string) $m->tabla, (int) $m->rekord_id, is_array($elotte) ? $elotte : null);

        $wpdb->update(self::t('muvelet'), ['allapot' => 'visszavonva', 'eredmeny' => mb_substr((string) $m->eredmeny . ' → ' . $uz, 0, 500)], ['id' => $id]);
        self::uzenet_ment((int) $m->user_id, 'esemeny', '#' . $id . ' műveletet a felhasználó VISSZAÁLLÍTOTTA: ' . $uz);

        wp_send_json_success(['kartya' => self::kartya($id), 'uzenet' => $uz]);
    }

    public static function ajax_ertekel(): void
    {
        self::jog();

        global $wpdb;

        $ertek = max(-1, min(1, (int) self::p('ertek', 3)));

        $wpdb->update(self::t('uzenet'), ['ertekeles' => $ertek], ['id' => (int) self::p('id', 20), 'user_id' => get_current_user_id(), 'szerep' => 'assistant']);

        wp_send_json_success([]);
    }

    /** „Jegyezd meg": a kérdés és a válasz tudástár-javaslat lesz (jóváhagyással). */
    public static function ajax_tanit(): void
    {
        self::jog();

        global $wpdb;

        $user = get_current_user_id();
        $t    = self::t('uzenet');
        $id   = (int) self::p('id', 20);
        $v    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d AND user_id = %d AND szerep = 'assistant'", $id, $user)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $k    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id < %d AND user_id = %d AND szerep = 'user' ORDER BY id DESC LIMIT 1", $id, $user)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (!$v || !$k) {
            wp_send_json_error(['uzenet' => 'Ezt a választ nem találom.']);
        }

        $valasz = trim((string) preg_replace('/\n\[Javaslat #\d+: .+? – jóváhagyásra vár\]/u', '', (string) $v->szoveg));
        $elo = SDH_Muhely_Asszisztens_Eszkozok::elokeszit('tudas_mentes', [
            'cim'    => mb_substr((string) $k->szoveg, 0, 180),
            'szoveg' => $valasz,
            'kulcsszavak' => implode(', ', array_slice(SDH_Muhely_Asszisztens_Tudas::szavak((string) $k->szoveg), 0, 8)),
        ]);

        if (is_string($elo)) {
            wp_send_json_error(['uzenet' => $elo]);
        }

        wp_send_json_success(['kartya' => self::javaslat($user, 'tudas_mentes', 'hozzaad', $elo)]);
    }

    /* =================================================================
     * Pulzus: az élő figura „érzékei"
     * ============================================================== */

    /**
     * @param array<string, mixed> $valasz
     * @return array<string, mixed>
     */
    public static function pulzus(array $valasz, int $user): array
    {
        $b = self::beallitas();

        if (!$b['be']) {
            return $valasz;
        }

        $uj = SDH_Muhely_Asszisztens_Tudas::verzio_tanulas();
        unset($uj);

        $ai = ['fuggo' => self::fuggo_db(), 'tippek' => [], 'ujdonsag' => null, 'mukodik' => self::mukodik()];

        // Újdonság: felhasználónként egyszer jelenik meg.
        $ujd = get_option('sdh_muhely_ai_ujdonsag');

        if (is_array($ujd) && ($ujd['verzio'] ?? '') !== '' && get_user_meta($user, 'sdh_ai_latta_verzio', true) !== $ujd['verzio']) {
            $pontok = [];

            foreach ((array) ($ujd['reszek'] ?? []) as $szoveg) {
                $pontok = array_merge($pontok, SDH_Muhely_Asszisztens_Tudas::ujdonsag_pontok((string) $szoveg, 5));
            }

            if ($pontok !== []) {
                $ai['ujdonsag'] = ['verzio' => (string) $ujd['verzio'], 'pontok' => array_slice($pontok, 0, 6)];
            }

            update_user_meta($user, 'sdh_ai_latta_verzio', (string) $ujd['verzio']);
        }

        if ($b['proaktiv']) {
            $tippek = get_transient('sdh_ai_tipp_' . $user);

            if (!is_array($tippek)) {
                $tippek = self::tippek();
                set_transient('sdh_ai_tipp_' . $user, $tippek, 2 * MINUTE_IN_SECONDS);
            }

            if ($ai['fuggo'] > 0) {
                array_unshift($tippek, [
                    'kulcs'  => 'fuggo-' . $ai['fuggo'],
                    'szoveg' => $ai['fuggo'] . ' javaslatom jóváhagyásra vár. Megnézed?',
                    'gomb'   => ['felirat' => 'Mutasd', 'nyit' => true],
                ]);
            }

            $ai['tippek'] = $tippek;
        }

        $valasz['ai'] = $ai;

        return $valasz;
    }

    /** Helyi (API nélküli) megfigyelések – ezekre szól magától. */
    private static function tippek(): array
    {
        $ki = [];

        try {
            $a = SDH_Muhely_Asszisztens_Eszkozok::attekintes();
        } catch (Throwable $h) {
            return [];
        }

        if (($a['lejart_hataridos'] ?? 0) > 0) {
            $ki[] = [
                'kulcs'  => 'lejart-' . $a['ma'] . '-' . $a['lejart_hataridos'],
                'szoveg' => $a['lejart_hataridos'] . ' nyitott munkalap határideje lejárt. Megmutassam, melyek?',
                'gomb'   => ['felirat' => 'Mutasd', 'kerdes' => 'Melyik nyitott munkalapok határideje járt le? Sorold fel őket.'],
            ];
        }

        if (($a['ma_lejaro'] ?? 0) > 0) {
            $ki[] = [
                'kulcs'  => 'malejar-' . $a['ma'] . '-' . $a['ma_lejaro'],
                'szoveg' => 'Ma ' . $a['ma_lejaro'] . ' munkalap határideje jár le.',
                'gomb'   => ['felirat' => 'Melyek?', 'kerdes' => 'Melyik munkalapok határideje jár le ma?'],
            ];
        }

        if (class_exists('SDH_Muhely_Penztar')) {
            $tegnap = wp_date('Y-m-d', time() - DAY_IN_SECONDS);
            $nap    = SDH_Muhely_Penztar::nap($tegnap);

            if ($nap && (string) $nap->allapot !== 'lezart' && (int) $nap->tetel_db > 0) {
                $ki[] = [
                    'kulcs'  => 'kassza-' . $tegnap,
                    'szoveg' => 'A tegnapi kassza (' . $tegnap . ') még nincs lezárva.',
                    'gomb'   => ['felirat' => 'Pénztár', 'url' => SDH_Muhely_Modulok::frontend_url('penztar')],
                ];
            }
        }

        return $ki;
    }

    /* =================================================================
     * Beállítás, teszt, mentés (admin)
     * ============================================================== */

    public static function ajax_beallit(): void
    {
        self::admin_jog();

        $b = self::beallitas();
        $regi = get_option(self::OPTION, []);
        $regi = is_array($regi) ? $regi : [];

        $uj = [
            'be'               => self::p('be', 2) === '1',
            'nev'              => self::p('nev', 40) !== '' ? self::p('nev', 40) : 'Szikra',
            'modell'           => preg_replace('/[^a-z0-9.\-]/', '', strtolower(self::p('modell', 80))) ?: self::ALAP_MODELL,
            'kulcs'            => (string) ($regi['kulcs'] ?? ''),
            'olvasas_engedely' => self::p('olvasas_engedely', 2) === '1',
            'proaktiv'         => self::p('proaktiv', 2) === '1',
            'napi_max'         => max(10, min(5000, (int) self::p('napi_max', 6))),
            'eszkozok'         => [],
        ];

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $be = isset($_POST['eszkoz']) ? array_map('sanitize_key', (array) wp_unslash($_POST['eszkoz'])) : [];

        foreach (array_keys(SDH_Muhely_Asszisztens_Eszkozok::definiciok()) as $nev) {
            $uj['eszkozok'][$nev] = in_array($nev, $be, true);
        }

        $kulcs = trim(self::p('kulcs', 200));

        if ($kulcs !== '') {
            $uj['kulcs'] = self::titkosit($kulcs);
        }

        if (self::p('kulcs_torol', 2) === '1') {
            $uj['kulcs'] = '';
        }

        update_option(self::OPTION, $uj, false);
        delete_transient('sdh_muhely_ai_statikus');
        unset($b);

        wp_send_json_success(['vissza' => SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'beallitasok', 'uzenet' => 'mentve'])]);
    }

    public static function ajax_teszt(): void
    {
        self::admin_jog();

        $v = self::api([['type' => 'text', 'text' => 'Válaszolj egy szóval.']], [['role' => 'user', 'content' => 'Mondd: rendben.']], []);

        if (is_string($v)) {
            wp_send_json_error(['uzenet' => $v]);
        }

        wp_send_json_success(['uzenet' => 'Működik – modell: ' . (string) ($v['model'] ?? '?') . ', válasz: ' . trim((string) ($v['content'][0]['text'] ?? ''))]);
    }

    public static function ajax_mentes(): void
    {
        self::admin_jog();

        $r = SDH_Muhely_Asszisztens_Tudas::teljes_mentes('kezi');

        if (is_string($r)) {
            wp_send_json_error(['uzenet' => $r]);
        }

        wp_send_json_success(['uzenet' => 'Mentés kész: ' . $r['fajl'] . ' (' . size_format($r['meret']) . ', ' . $r['sorok'] . ' sor).']);
    }

    public static function ajax_mentes_le(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_die('Ehhez adminisztrátori jog kell.', '', ['response' => 403]);
        }

        $fajl = basename(self::p('fajl', 120));
        $ut   = SDH_Muhely_Asszisztens_Tudas::mentes_mappa() . $fajl;

        if (preg_match('/^sdh-mentes-[\w\-]+\.jsonl\.gz$/', $fajl) !== 1 || !is_file($ut)) {
            wp_die('Nincs ilyen mentés.', '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $fajl . '"');
        header('Content-Length: ' . filesize($ut));
        readfile($ut); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    /* =================================================================
     * Tudástár szerkesztése (popup)
     * ============================================================== */

    public static function ajax_tudas_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">A tudástárat adminisztrátor szerkeszti.</div>';
            wp_die();
        }

        global $wpdb;

        $id = (int) self::p('id', 20);
        $r  = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Asszisztens_Tudas::tabla() . ' WHERE id = %d', $id)) : null;

        ?>
        <form class="sdh-urlap sdh-cs-urlap" data-sdh-ajax-action="sdh_muhely_aitudas_ment" novalidate>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <h2 class="sdh-modal__cim"><?php echo $r ? 'Tudás szerkesztése' : 'Új tudás az asszisztensnek'; ?></h2>

            <div class="sdh-ig">
                <label for="at_cim">Cím / kérdés <span class="sdh-kotelezo">*</span></label>
                <input type="text" name="cim" id="at_cim" maxlength="190" value="<?php echo esc_attr($r ? (string) $r->cim : ''); ?>" placeholder="pl. Mennyi a garancia kijelzőcserére?" autocomplete="off">
            </div>
            <div class="sdh-ig">
                <label for="at_szoveg">Amit tudnia kell <span class="sdh-kotelezo">*</span></label>
                <textarea name="szoveg" id="at_szoveg" rows="8" maxlength="8000"><?php echo esc_textarea($r ? (string) $r->szoveg : ''); ?></textarea>
            </div>
            <div class="sdh-ig">
                <label for="at_kulcs">Kulcsszavak</label>
                <input type="text" name="kulcsszavak" id="at_kulcs" maxlength="255" value="<?php echo esc_attr($r ? (string) $r->kulcsszavak : ''); ?>" placeholder="vesszővel: garancia, kijelző, csere" autocomplete="off">
            </div>
            <div class="sdh-cs-kapcsolok">
                <label class="sdh-cs-kapcs"><input type="checkbox" name="mindig" value="1"<?php checked($r && (int) $r->mindig); ?>><span class="sdh-cs-kapcs__sin"></span><span><strong>Mindig tudja</strong> – minden kérdésnél megkapja (pl. aktuális akció, fontos szabály)</span></label>
                <label class="sdh-cs-kapcs"><input type="checkbox" name="aktiv" value="1"<?php checked(!$r || (int) $r->aktiv); ?>><span class="sdh-cs-kapcs__sin"></span><span><strong>Aktív</strong> – kikapcsolva megmarad, de nem használja</span></label>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Mentés</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            </div>
        </form>
        <?php
        wp_die();
    }

    public static function ajax_tudas_ment(): void
    {
        self::admin_jog();

        global $wpdb;

        $id  = (int) self::p('id', 20);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $szoveg = isset($_POST['szoveg']) ? mb_substr(trim(sanitize_textarea_field(wp_unslash((string) $_POST['szoveg']))), 0, 8000) : '';
        $cim = self::p('cim', 190);

        if ($cim === '' || $szoveg === '') {
            wp_send_json_error(['uzenet' => 'A cím és a tartalom is kell.']);
        }

        $adat = [
            'cim' => $cim, 'szoveg' => $szoveg, 'kulcsszavak' => self::p('kulcsszavak', 255),
            'mindig' => self::p('mindig', 2) === '1' ? 1 : 0, 'aktiv' => self::p('aktiv', 2) === '1' ? 1 : 0, 'modositva' => current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update(SDH_Muhely_Asszisztens_Tudas::tabla(), $adat, ['id' => $id]);
        } else {
            $adat += ['forras' => 'kezi', 'letrehozo' => get_current_user_id(), 'letrehozva' => current_time('mysql')];
            $wpdb->insert(SDH_Muhely_Asszisztens_Tudas::tabla(), $adat);
        }

        wp_send_json_success(['vissza' => SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => 'tudastar', 'uzenet' => 'mentve'])]);
    }

    /* =================================================================
     * Oldal
     * ============================================================== */

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        $admin = SDH_Muhely_Admin_UI::admin_e();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nezet = isset($_GET['nezet']) ? sanitize_key(wp_unslash($_GET['nezet'])) : 'muveletek';
        $fulek = ['muveletek' => 'Javaslatok és napló', 'tudastar' => 'Tudástár'];

        if ($admin) {
            $fulek += ['mentesek' => 'Mentések', 'beallitasok' => 'Beállítások'];
        }

        $nezet = isset($fulek[$nezet]) ? $nezet : 'muveletek';
        $b = self::beallitas();

        ?>
        <div class="sdh-wrap sdh-cs sdh-ai-oldal" data-sdh-ai-oldal>
            <?php
            SDH_Muhely_Admin_UI::uzenet();

            $gombok = [['cimke' => 'Beszélgetés ' . $b['nev'] . '-val', 'url' => '#', 'elsodleges' => true, 'adatok' => ['sdh-ai-nyit' => '1']]];

            if ($admin && $nezet === 'tudastar') {
                $gombok[] = ['cimke' => '+ Új tudás', 'url' => '#', 'adatok' => ['sdh-urlap' => 'aitudas', 'sdh-id' => '0']];
            }

            SDH_Muhely_Admin_UI::fejlec(
                'Asszisztens',
                $b['nev'] . ' – a CRM AI-segítője (Claude). Belelát mindenbe, vezet, tanul; adatot csak a te jóváhagyásoddal módosít, előtte mindig mentést készít.',
                $gombok
            );
            ?>

            <?php if (!self::mukodik()) : ?>
                <div class="sdh-uzenet sdh-uzenet--figyelem">
                    <?php echo !$b['be'] ? 'Az asszisztens ki van kapcsolva.' : 'Az asszisztenshez Claude API-kulcs kell.'; ?>
                    <?php echo $admin ? 'Állítsd be a Beállítások lapon.' : 'Szólj az adminisztrátornak.'; ?>
                </div>
            <?php endif; ?>

            <nav class="sdh-cs-fulek" aria-label="Asszisztens nézetek">
                <?php
                foreach ($fulek as $k => $c) {
                    printf(
                        '<a class="sdh-cs-ful%s" href="%s">%s</a>',
                        $k === $nezet ? ' is-aktiv' : '',
                        esc_url(SDH_Muhely_Modulok::url(self::KULCS, $k === 'muveletek' ? [] : ['nezet' => $k])),
                        esc_html($c)
                    );
                }
                ?>
            </nav>

            <?php
            if ($nezet === 'tudastar') {
                self::tudastar_nezet($admin);
            } elseif ($nezet === 'mentesek') {
                self::mentesek_nezet();
            } elseif ($nezet === 'beallitasok') {
                self::beallitas_nezet();
            } else {
                self::muveletek_nezet($admin);
            }
            ?>
        </div>
        <?php
    }

    private static function lap(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return max(1, isset($_GET['lap']) ? (int) $_GET['lap'] : 1);
    }

    private static function lapozo(int $lap, int $osszes, int $egy, string $nezet): void
    {
        $oldalak = (int) ceil($osszes / $egy);

        if ($oldalak <= 1) {
            return;
        }

        $nyil = static fn (string $d): string => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="' . $d . '"/></svg>';

        echo '<nav class="sdh-cs-lapozo sdh-cs-lapozo--lap" aria-label="Lapozás">';

        if ($lap > 1) {
            printf('<a class="sdh-gomb sdh-lapnyil" href="%s" aria-label="Előző oldal">%s</a>', esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => $nezet, 'lap' => $lap - 1])), $nyil('M10 3 5 8l5 5')); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        printf('<span>%d / %d</span>', (int) $lap, (int) $oldalak);

        if ($lap < $oldalak) {
            printf('<a class="sdh-gomb sdh-lapnyil" href="%s" aria-label="Következő oldal">%s</a>', esc_url(SDH_Muhely_Modulok::url(self::KULCS, ['nezet' => $nezet, 'lap' => $lap + 1])), $nyil('M6 3l5 5-5 5')); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '</nav>';
    }

    private static function muveletek_nezet(bool $admin): void
    {
        global $wpdb;

        $t    = self::t('muvelet');
        $user = get_current_user_id();
        $hol  = $admin ? '1=1' : $wpdb->prepare('user_id = %d', $user);
        $egy  = 12;
        $lap  = self::lap();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $osszes = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$hol}");
        $idk    = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$t} WHERE {$hol} ORDER BY (allapot = 'javaslat') DESC, id DESC LIMIT %d OFFSET %d", $egy, ($lap - 1) * $egy));
        // phpcs:enable

        if (!$idk) {
            echo '<div class="sdh-doboz sdh-ai-ures"><p class="sdh-sugo sdh-sugo--utolso">Még nincs javaslat. Kérdezz az asszisztenstől, vagy kérd meg, hogy csináljon meg valamit – a módosítást itt (és a beszélgetésben) hagyod jóvá, és innen vissza is állíthatod.</p></div>';

            return;
        }

        echo '<div class="sdh-ai-kartyak" data-sdh-ai-kartyak>';

        foreach ($idk as $id) {
            $k = self::kartya((int) $id);
            printf('<div class="sdh-ai-kartyahely" data-kartya="%s"></div>', esc_attr((string) wp_json_encode($k, JSON_UNESCAPED_UNICODE)));
        }

        echo '</div>';

        self::lapozo($lap, $osszes, $egy, 'muveletek');
    }

    private static function tudastar_nezet(bool $admin): void
    {
        global $wpdb;

        $t   = SDH_Muhely_Asszisztens_Tudas::tabla();
        $egy = 12;
        $lap = self::lap();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $osszes = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");
        $sorok  = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} ORDER BY aktiv DESC, id DESC LIMIT %d OFFSET %d", $egy, ($lap - 1) * $egy));
        // phpcs:enable

        echo '<p class="sdh-sugo">Amit az asszisztens a rendszeren kívül tud: kézzel tanított szabályok, jóváhagyott „Jegyezd meg" válaszok, és a verzióváltáskor megtanult újdonságok. A rendszer funkcióit (modulok, verziónapló, beállítások) magától is ismeri.</p>';

        if (!$sorok) {
            echo '<div class="sdh-doboz"><p class="sdh-sugo sdh-sugo--utolso">Még üres. Taníts neki valamit („+ Új tudás"), vagy egy jó válasznál nyomd meg a „Jegyezd meg" gombot.</p></div>';

            return;
        }

        $forras = ['kezi' => 'Kézzel', 'ai' => 'Jóváhagyott javaslat', 'verzio' => 'Verzióból tanulta'];

        echo '<div class="sdh-cs-kartyak">';

        foreach ($sorok as $r) {
            ?>
            <article class="sdh-cs-kartya sdh-ai-tudas<?php echo (int) $r->aktiv ? '' : ' is-letiltva'; ?>">
                <div class="sdh-cs-kartya__test">
                    <h3><?php echo esc_html((string) $r->cim); ?></h3>
                    <p class="sdh-ai-tudas__szoveg"><?php echo esc_html(wp_trim_words((string) $r->szoveg, 40)); ?></p>
                    <p class="sdh-cs-kartya__cimkek">
                        <span class="sdh-cs-cimke"><?php echo esc_html($forras[(string) $r->forras] ?? (string) $r->forras); ?></span>
                        <?php if ((int) $r->mindig) : ?><span class="sdh-cs-cimke sdh-cs-cimke--admin">Mindig</span><?php endif; ?>
                        <?php if (!(int) $r->aktiv) : ?><span class="sdh-cs-cimke sdh-cs-cimke--tilt">Kikapcsolva</span><?php endif; ?>
                        <span class="sdh-cs-cimke">#<?php echo (int) $r->id; ?></span>
                    </p>
                </div>
                <?php if ($admin) : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="#" data-sdh-urlap="aitudas" data-sdh-id="<?php echo (int) $r->id; ?>">Szerkesztés</a>
                <?php endif; ?>
            </article>
            <?php
        }

        echo '</div>';

        self::lapozo($lap, $osszes, $egy, 'tudastar');
    }

    private static function mentesek_nezet(): void
    {
        $lista = SDH_Muhely_Asszisztens_Tudas::mentesek();
        $nonce = wp_create_nonce('sdh_muhely_modal');

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Teljes mentések</h2>
            <p class="sdh-sugo">Minden nap az asszisztens első módosítása előtt automatikusan teljes mentés készül a CRM összes saját táblájáról és beállításáról (a TAC-adatbázis és a levél-gyorsítótár kivételével – azok újra előállíthatók). Ezen felül minden egyes módosított rekord előtte-állapota a műveletnél tárolódik, onnan egy gombbal visszaállítható. Az utolsó 20 teljes mentés marad meg. A mappa kívülről nem olvasható.</p>
            <p><button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-ai-mentes>Mentés most</button> <span class="sdh-sugo" data-sdh-ai-mentes-uzenet></span></p>

            <?php if ($lista === []) : ?>
                <p class="sdh-sugo sdh-sugo--utolso">Még nincs mentés.</p>
            <?php else : ?>
                <ul class="sdh-ai-mentesek">
                    <?php foreach (array_slice($lista, 0, 20) as $m) : ?>
                        <li>
                            <span><?php echo esc_html(wp_date('Y. m. d. H:i', $m['ido'])); ?></span>
                            <code><?php echo esc_html($m['fajl']); ?></code>
                            <span><?php echo esc_html(size_format($m['meret'])); ?></span>
                            <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(add_query_arg(['action' => 'sdh_muhely_ai_mentes_le', 'fajl' => $m['fajl'], '_wpnonce' => $nonce], admin_url('admin-ajax.php'))); ?>">Letöltés</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function beallitas_nezet(): void
    {
        $b = self::beallitas();
        $k = self::kulcs();

        ?>
        <form class="sdh-urlap sdh-cs-urlap" data-sdh-ai-beallitas>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">

            <div class="sdh-doboz">
                <h2 class="sdh-doboz__cim">Alapok</h2>
                <div class="sdh-cs-kapcsolok">
                    <label class="sdh-cs-kapcs"><input type="checkbox" name="be" value="1"<?php checked($b['be']); ?>><span class="sdh-cs-kapcs__sin"></span><span><strong>Bekapcsolva</strong> – minden CRM-oldalon ott van a jobb alsó sarokban</span></label>
                    <label class="sdh-cs-kapcs"><input type="checkbox" name="proaktiv" value="1"<?php checked($b['proaktiv']); ?>><span class="sdh-cs-kapcs__sin"></span><span><strong>Magától szól</strong> – lejárt határidő, lezáratlan kassza, újdonság, űrlaphiba esetén buborékban jelez (API nélkül, helyben számolva)</span></label>
                    <label class="sdh-cs-kapcs"><input type="checkbox" name="olvasas_engedely" value="1"<?php checked($b['olvasas_engedely']); ?>><span class="sdh-cs-kapcs__sin"></span><span><strong>Olvasáshoz is engedélyt kér</strong> – bekapcsolva az adatok lekéréséhez (munkalap, ügyfél, pénztár) is jóváhagyás kell. Kikapcsolva csak a te kérdésedre olvas; módosításhoz mindig engedély kell.</span></label>
                </div>
                <div class="sdh-cs-racs">
                    <div class="sdh-ig">
                        <label for="ai_nev">A neve</label>
                        <input type="text" name="nev" id="ai_nev" maxlength="40" value="<?php echo esc_attr($b['nev']); ?>" autocomplete="off">
                    </div>
                    <div class="sdh-ig">
                        <label for="ai_napi">Napi kérdéskeret / kolléga</label>
                        <input type="text" inputmode="numeric" name="napi_max" id="ai_napi" value="<?php echo (int) $b['napi_max']; ?>" autocomplete="off">
                    </div>
                    <div class="sdh-ig">
                        <label for="ai_modell">Claude modell</label>
                        <input type="text" name="modell" id="ai_modell" maxlength="80" value="<?php echo esc_attr($b['modell']); ?>" autocomplete="off">
                        <span class="sdh-mezo__sugo">Alap: <code><?php echo esc_html(self::ALAP_MODELL); ?></code>. Ha az API nem ismeri, magától a <code><?php echo esc_html(self::TARTALEK_MODELL); ?></code> fut.</span>
                    </div>
                    <div class="sdh-ig">
                        <label for="ai_kulcs">Claude API-kulcs</label>
                        <?php if (defined('SDH_MUHELY_CLAUDE_KEY') || defined('SDH_CLAUDE_API_KEY')) : ?>
                            <p class="sdh-mezo__sugo">A kulcs a wp-config.php-ban van – ez a legbiztonságosabb.</p>
                        <?php else : ?>
                            <input type="password" name="kulcs" id="ai_kulcs" maxlength="200" autocomplete="off" placeholder="<?php echo $b['kulcs'] !== '' ? 'mentve (titkosítva) – újat csak cseréhez írj' : 'sk-ant-…'; ?>">
                            <label class="sdh-cs-kapcs"><input type="checkbox" name="kulcs_torol" value="1"><span class="sdh-cs-kapcs__sin"></span><span>Mentett kulcs törlése</span></label>
                        <?php endif; ?>
                        <span class="sdh-mezo__sugo">Most használt: <?php echo $k['kulcs'] !== '' ? esc_html($k['forras']) : '<strong>nincs kulcs</strong>'; ?>. A wp-config.php-ban: <code>define( 'SDH_MUHELY_CLAUDE_KEY', 'sk-ant-…' );</code></span>
                    </div>
                </div>
            </div>

            <div class="sdh-doboz">
                <h2 class="sdh-doboz__cim">Mit tehet az asszisztens</h2>
                <p class="sdh-sugo">A kikapcsolt eszközt nem kapja meg. A módosító eszközök mindig csak javaslatot tesznek – a végrehajtáshoz emberi jóváhagyás kell, az átíráshoz és a törléshez kifejezett kérés és megerősítő kód is.</p>
                <?php
                $szintek = ['olvas' => 'olvas', 'felulet' => 'megmutat', 'hozzaad' => 'hozzáad – jóváhagyással', 'atir' => 'átír – kérésre, kóddal', 'torol' => 'töröl – kérésre, kóddal'];
                echo '<div class="sdh-cs-pillek" data-sdh-cs-oszlop>';

                foreach (SDH_Muhely_Asszisztens_Eszkozok::definiciok() as $nev => $d) {
                    $be = $b['eszkozok'][$nev] ?? true;
                    printf(
                        '<label class="sdh-cs-pill%s"><input type="checkbox" name="eszkoz[]" value="%s"%s><span>%s <small>(%s)</small></span></label>',
                        $be ? ' is-aktiv' : '',
                        esc_attr($nev),
                        $be ? ' checked' : '',
                        esc_html($d['cim']),
                        esc_html($szintek[$d['szint']] ?? $d['szint'])
                    );
                }

                echo '</div>';
                ?>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Mentés</button>
                <button type="button" class="sdh-gomb" data-sdh-ai-teszt>API teszt</button>
                <span class="sdh-sugo" data-sdh-ai-teszt-uzenet></span>
            </div>
        </form>
        <?php
    }
}
