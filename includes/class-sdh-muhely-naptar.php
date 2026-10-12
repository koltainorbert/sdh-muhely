<?php
/**
 * Naptár (0.50) – személyenkénti naptárak, események, értesítések.
 *
 * MINDEN NÉVHEZ SAJÁT NAPTÁR tartozik (naptar_szemely):
 *   - többfelhasználós módban (Csapat: „Többfelhasználós csevegés") minden
 *     fiók (profil) magától kap egy naptárat – profilonként pontosan egyet;
 *   - egy közös fióknál (Csapat: „Üzenőfal") a nevek kézzel vehetők fel,
 *     fiók nélkül: a naptár így is névre szól.
 * Mindkét módban felvehető további név (pl. „Műhely", „Kiszállás").
 *
 * AZ ESEMÉNY (naptar_esemeny) mindent tud, amit egy naptártól várni lehet:
 * időpont vagy egész nap, hely, leírás, kategória és szín, fontosság,
 * állapot, résztvevők, ismétlődés (napi / heti a hét napjaival / havi / éves,
 * végdátummal vagy alkalomszámmal, kihagyott alkalmakkal), több emlékeztető,
 * ügyfél és munkalap, hivatkozás, privát jelző.
 *
 * ÉRTESÍTÉS (naptar_jelzes):
 *   - a rendszerben: amikor az esemény (vagy az emlékeztetője) esedékes,
 *     kártya jelenik meg minden oldalon, és CSAK AKKOR tűnik el, ha kézzel
 *     kinyomják (a pulzusból jön: sdh_muhely_pulzus → naptar.js);
 *   - e-mailben: a megjelölt („E-mail értesítés") és a „Nagyon fontos"
 *     események az első emlékeztető idején (emlékeztető nélkül a kezdéskor)
 *     levélben is elmennek a naptár gazdájának és a résztvevőknek;
 *   - napi összefoglaló levél (kapcsolható): a nap eseményei és a levelező
 *     ügynöke szerint nagyon fontos levelek.
 * A küldést a WordPress időzítője (5 percenként) és a nyitott felület pulzusa
 * is elvégzi: ha a szerveren nincs valódi cron, akkor is megy, amíg valaki
 * használja a rendszert.
 *
 * A NÉZETEKET (ma, nap, 3 nap, munkahét, hét, hónap, negyedév, év, napirend,
 * idővonal, személyek, tábla) az assets/naptar.js rajzolja a
 * sdh_muhely_naptar_adat végpont adataiból; az ismétlődő eseményeket a
 * szerver bontja alkalmakra (alkalmak()).
 *
 * Cégadat nincs a kódban: a kategóriák, a munkaidő, a címek mind beállítások.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Naptar
{
    public const KULCS = 'naptar';

    private const OPTION = 'sdh_muhely_naptar';

    /** Az időzített feladat neve (WordPress cron). */
    private const CRON = 'sdh_muhely_naptar_cron';

    /** Ennyi napra előre / vissza keresünk esedékes alkalmat a jelzésekhez. */
    private const JELZES_VISSZA_NAP = 2;
    private const JELZES_ELORE_NAP  = 8;

    /** A személyek és a kategóriák színei (a CSS-ben: --np-<név>, assets/naptar.css). */
    public const SZINEK = [
        'kek'     => 'Kék',
        'zold'    => 'Zöld',
        'lila'    => 'Lila',
        'narancs' => 'Narancs',
        'rozsa'   => 'Rózsa',
        'turkiz'  => 'Türkiz',
        'sarga'   => 'Sárga',
        'piros'   => 'Piros',
        'szurke'  => 'Szürke',
    ];

    public const PRIORITASOK = [
        'normal'   => 'Normál',
        'fontos'   => 'Fontos',
        'kritikus' => 'Nagyon fontos',
    ];

    public const ALLAPOTOK = [
        'tervezett'   => 'Tervezett',
        'folyamatban' => 'Folyamatban',
        'kesz'        => 'Kész',
        'elmaradt'    => 'Elmaradt',
    ];

    public const ISMETLESEK = [
        ''     => 'Nem ismétlődik',
        'napi' => 'Naponta',
        'heti' => 'Hetente',
        'havi' => 'Havonta',
        'eves' => 'Évente',
    ];

    /** Emlékeztetők: perc a kezdés előtt => felirat. */
    public const EMLEKEZTETOK = [
        0     => 'Kezdéskor',
        5     => '5 perccel előtte',
        15    => '15 perccel előtte',
        30    => '30 perccel előtte',
        60    => '1 órával előtte',
        120   => '2 órával előtte',
        1440  => '1 nappal előtte',
        2880  => '2 nappal előtte',
        10080 => '1 héttel előtte',
    ];

    public const NEZETEK = [
        'ma'        => 'Ma',
        'nap'       => 'Nap',
        'haromnap'  => '3 nap',
        'munkahet'  => 'Munkahét',
        'het'       => 'Hét',
        'honap'     => 'Hónap',
        'negyedev'  => 'Negyedév',
        'ev'        => 'Év',
        'napirend'  => 'Napirend',
        'idovonal'  => 'Idővonal',
        'szemelyek' => 'Személyek',
        'tabla'     => 'Tábla',
    ];

    private const NAPOK = [1 => 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap'];
    private const NAPOK_ROVID = [1 => 'H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];
    private const HONAPOK = [1 => 'január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];

    /** @var array<string, array<int, array<string, mixed>>>|null */
    private static ?array $szemely_tar = null;

    public static function init(): void
    {
        $muveletek = [
            'naptar_adat'          => 'ajax_adat',
            'naptar_urlap'         => 'ajax_urlap',
            'naptar_ment'          => 'ajax_ment',
            'naptar_torol'         => 'ajax_torol',
            'naptar_athelyez'      => 'ajax_athelyez',
            'naptar_allapot'       => 'ajax_allapot',
            'naptar_kinyom'        => 'ajax_kinyom',
            'naptarszemely_urlap'  => 'ajax_szemely_urlap',
            'naptarszemely_ment'   => 'ajax_szemely_ment',
        ];

        foreach ($muveletek as $nev => $fuggveny) {
            add_action('wp_ajax_sdh_muhely_' . $nev, [self::class, $fuggveny]);
        }

        // A pulzus (minden oldalon, 15 másodpercenként) viszi a jelzéseket és küldi a leveleket.
        add_filter('sdh_muhely_pulzus', [self::class, 'pulzus'], 10, 2);

        // Időzítő: akkor is megy az e-mail, ha épp senki nincs a felületen (valódi cron mellett).
        add_filter('cron_schedules', [self::class, 'cron_utem']);
        add_action('init', [self::class, 'cron_beallit'], 20);
        add_action(self::CRON, [self::class, 'idozitett']);

        add_action('admin_enqueue_scripts', [self::class, 'admin_eszkozok'], 22);

        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Naptár',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 33,
            'jelveny' => [self::class, 'nyitott_jelzes_db'],
        ]);
    }

    /* =================================================================
     * Segédek
     * ============================================================== */

    private static function t(string $nev): string
    {
        return SDH_Muhely_Schema::tabla('naptar_' . $nev);
    }

    private static function most(): string
    {
        return current_time('mysql');
    }

    private static function tz(): DateTimeZone
    {
        return wp_timezone();
    }

    private static function dt(string $mysql): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($mysql !== '' ? $mysql : 'now', self::tz());
        } catch (Exception $e) {
            return new DateTimeImmutable('now', self::tz());
        }
    }

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    /** @param mixed $alap */
    private static function g(string $kulcs, int $max = 255, $alap = ''): string
    {
        // phpcs:disable WordPress.Security.NonceVerification -- a hívó ellenőrzi.
        $forras = $_POST[$kulcs] ?? ($_GET[$kulcs] ?? $alap);
        // phpcs:enable

        return is_scalar($forras) ? mb_substr(sanitize_text_field(wp_unslash((string) $forras)), 0, $max) : (string) $alap;
    }

    /** Számlista a kérésből: tömbként vagy vesszővel elválasztva is jöhet. */
    private static function g_szamok(string $kulcs): array
    {
        // phpcs:disable WordPress.Security.NonceVerification -- a hívó ellenőrzi.
        $forras = $_POST[$kulcs] ?? ($_GET[$kulcs] ?? []);
        // phpcs:enable

        if (is_scalar($forras)) {
            $forras = explode(',', (string) $forras);
        }

        $ki = [];

        foreach ((array) $forras as $egy) {
            if (is_scalar($egy) && is_numeric(trim((string) $egy))) {
                $ki[] = (int) $egy;
            }
        }

        return array_values(array_unique($ki));
    }

    private static function datum_e(string $d): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Egyfelhasználós (közös fiókos) mód-e – a Csapat beállítása dönti el. */
    public static function egyszeru(): bool
    {
        return !class_exists('SDH_Muhely_Csapat') || SDH_Muhely_Csapat::egyszeru();
    }

    private static function monogram(string $nev): string
    {
        $reszek = preg_split('/\s+/u', trim($nev)) ?: [];
        $betuk  = '';

        foreach (array_slice($reszek, 0, 2) as $r) {
            $betuk .= mb_strtoupper(mb_substr($r, 0, 1));
        }

        return $betuk !== '' ? $betuk : '?';
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /**
     * @return array{ora_tol: int, ora_ig: int, alap_nezet: string, alap_emlekezteto: array<int, int>, email_be: bool, kozos_email: string, napi_be: bool, napi_ora: string, napi_cimzettek: array<int, string>, kategoriak: array<string, array{nev: string, szin: string}>}
     */
    public static function beallitas(): array
    {
        $b = get_option(self::OPTION, []);
        $b = is_array($b) ? $b : [];

        $tol = isset($b['ora_tol']) ? max(0, min(22, (int) $b['ora_tol'])) : 7;
        $ig  = isset($b['ora_ig']) ? max($tol + 2, min(24, (int) $b['ora_ig'])) : 19;

        $nezet = (string) ($b['alap_nezet'] ?? 'het');
        $eml   = [];

        foreach ((array) ($b['alap_emlekezteto'] ?? [15]) as $p) {
            if (isset(self::EMLEKEZTETOK[(int) $p])) {
                $eml[] = (int) $p;
            }
        }

        $cimzettek = [];

        foreach (preg_split('/[\s,;]+/', (string) ($b['napi_cimzettek'] ?? '')) ?: [] as $c) {
            if (is_email($c)) {
                $cimzettek[] = strtolower($c);
            }
        }

        $napi_ora = (string) ($b['napi_ora'] ?? '07:30');

        return [
            // A napi / heti nézet alapból ezt az órasávot mutatja (a „0–24" gomb az egész napot).
            'ora_tol'          => $tol,
            'ora_ig'           => $ig,
            'alap_nezet'       => isset(self::NEZETEK[$nezet]) ? $nezet : 'het',
            'alap_emlekezteto' => array_values(array_unique($eml)),
            // E-mail: ki-be kapcsolható; a közös cím azoknak a neveknek szól, akiknek nincs saját címük.
            'email_be'         => !isset($b['email_be']) || !empty($b['email_be']),
            'kozos_email'      => is_email((string) ($b['kozos_email'] ?? '')) ? (string) $b['kozos_email'] : '',
            'napi_be'          => !empty($b['napi_be']),
            'napi_ora'         => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $napi_ora) === 1 ? $napi_ora : '07:30',
            'napi_cimzettek'   => array_values(array_unique($cimzettek)),
            'kategoriak'       => self::kategoriak_ertelmez((string) ($b['kategoriak'] ?? '')),
        ];
    }

    /**
     * A kategóriák soronként: „Név | szín". Üresen az alaplista.
     *
     * @return array<string, array{nev: string, szin: string}>
     */
    private static function kategoriak_ertelmez(string $szoveg): array
    {
        $ki = [];

        foreach (preg_split('/\R/u', $szoveg) ?: [] as $sor) {
            $reszek = array_map('trim', explode('|', $sor));
            $nev    = mb_substr(sanitize_text_field($reszek[0] ?? ''), 0, 40);
            $szin   = sanitize_key($reszek[1] ?? '');

            if ($nev === '') {
                continue;
            }

            $kulcs = sanitize_title($nev);
            $kulcs = $kulcs !== '' ? mb_substr($kulcs, 0, 40) : 'k' . count($ki);

            $ki[$kulcs] = ['nev' => $nev, 'szin' => isset(self::SZINEK[$szin]) ? $szin : 'kek'];
        }

        if ($ki !== []) {
            return $ki;
        }

        return [
            'megbeszeles' => ['nev' => 'Megbeszélés', 'szin' => 'kek'],
            'feladat'     => ['nev' => 'Feladat', 'szin' => 'zold'],
            'hatarido'    => ['nev' => 'Határidő', 'szin' => 'piros'],
            'ugyfel'      => ['nev' => 'Ügyfél', 'szin' => 'narancs'],
            'kiszallas'   => ['nev' => 'Kiszállás', 'szin' => 'turkiz'],
            'szabadsag'   => ['nev' => 'Szabadság', 'szin' => 'lila'],
            'magan'       => ['nev' => 'Magán', 'szin' => 'rozsa'],
            'egyeb'       => ['nev' => 'Egyéb', 'szin' => 'szurke'],
        ];
    }

    /** Doboz a Beállítások › Naptár lapfülre. */
    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();

        $kat_sorok = [];

        foreach ($b['kategoriak'] as $k) {
            $kat_sorok[] = $k['nev'] . ' | ' . $k['szin'];
        }

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Naptár – nézet</h2>
            <input type="hidden" name="naptar_jelen" value="1">

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="naptar_alap_nezet">Ezzel a nézettel nyílik</label>
                    <select name="naptar_alap_nezet" id="naptar_alap_nezet">
                        <?php foreach (self::NEZETEK as $k => $n) : ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($b['alap_nezet'], $k); ?>><?php echo esc_html($n); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sdh-mezo__sugo">A dolgozó utoljára használt nézetét a böngésző megjegyzi; ez az első megnyitásra szól.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="naptar_ora_tol">A nap látható kezdete (óra)</label>
                    <select name="naptar_ora_tol" id="naptar_ora_tol">
                        <?php for ($o = 0; $o <= 22; $o++) : ?>
                            <option value="<?php echo (int) $o; ?>" <?php selected($b['ora_tol'], $o); ?>><?php echo esc_html(sprintf('%02d:00', $o)); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="sdh-mezo">
                    <label for="naptar_ora_ig">A nap látható vége (óra)</label>
                    <select name="naptar_ora_ig" id="naptar_ora_ig">
                        <?php for ($o = 2; $o <= 24; $o++) : ?>
                            <option value="<?php echo (int) $o; ?>" <?php selected($b['ora_ig'], $o); ?>><?php echo esc_html(sprintf('%02d:00', $o)); ?></option>
                        <?php endfor; ?>
                    </select>
                    <span class="sdh-mezo__sugo">A napi és heti nézet ezt az órasávot tölti ki görgetés nélkül; a „0–24" gomb az egész napot mutatja.</span>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <span class="sdh-ig__cimke">Új esemény alap emlékeztetői</span>
                    <div class="sdh-cs-pillek">
                        <?php foreach (self::EMLEKEZTETOK as $perc => $felirat) : ?>
                            <?php $be = in_array($perc, $b['alap_emlekezteto'], true); ?>
                            <label class="sdh-cs-pill<?php echo $be ? ' is-aktiv' : ''; ?>">
                                <input type="checkbox" name="naptar_alap_emlekezteto[]" value="<?php echo (int) $perc; ?>"<?php checked($be); ?>><span><?php echo esc_html($felirat); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="naptar_kategoriak">Kategóriák (soronként: Név | szín)</label>
                    <textarea name="naptar_kategoriak" id="naptar_kategoriak" rows="8" class="sdh-kod"><?php echo esc_textarea(implode("\n", $kat_sorok)); ?></textarea>
                    <span class="sdh-mezo__sugo">Választható színek: <?php echo esc_html(implode(', ', array_keys(self::SZINEK))); ?>. Üresen hagyva az alaplista áll vissza.</span>
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Naptár – e-mail értesítések</h2>

            <p class="sdh-sugo">
                A megjelölt („E-mail értesítés") és a „Nagyon fontos" eseményekről levél megy a naptár gazdájának és a
                résztvevőknek: az első emlékeztető idején, emlékeztető nélkül a kezdéskor. A cím a személy adatlapján
                áll (többfelhasználós módban a fiók e-mail-címe). A levelet a WordPress küldi – ugyanazon az úton, mint a
                rendszer többi értesítőjét.
            </p>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" name="naptar_email_be" id="naptar_email_be" value="1" <?php checked($b['email_be']); ?>>
                    <label for="naptar_email_be">E-mail értesítések küldése</label>
                </div>

                <div class="sdh-mezo">
                    <label for="naptar_kozos_email">Közös értesítő cím</label>
                    <input type="text" name="naptar_kozos_email" id="naptar_kozos_email" maxlength="190" autocomplete="off"
                           placeholder="pl. iroda@pelda.hu" value="<?php echo esc_attr($b['kozos_email']); ?>">
                    <span class="sdh-mezo__sugo">Ide megy annak a névnek az értesítése, akinek nincs saját e-mail-címe. Üresen az ilyen név nem kap levelet.</span>
                </div>

                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" name="naptar_napi_be" id="naptar_napi_be" value="1" <?php checked($b['napi_be']); ?>>
                    <label for="naptar_napi_be">Napi összefoglaló levél (a nap eseményei és a nagyon fontos levelek)</label>
                </div>

                <div class="sdh-mezo">
                    <label for="naptar_napi_ora">Az összefoglaló ideje</label>
                    <select name="naptar_napi_ora" id="naptar_napi_ora">
                        <?php for ($o = 4; $o <= 12; $o++) : ?>
                            <?php foreach (['00', '30'] as $p) : ?>
                                <?php $ido = sprintf('%02d:%s', $o, $p); ?>
                                <option value="<?php echo esc_attr($ido); ?>" <?php selected($b['napi_ora'], $ido); ?>><?php echo esc_html($ido); ?></option>
                            <?php endforeach; ?>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="naptar_napi_cimzettek">Az összefoglaló címzettjei (vesszővel vagy soronként)</label>
                    <textarea name="naptar_napi_cimzettek" id="naptar_napi_cimzettek" rows="2" class="sdh-kod"><?php echo esc_textarea(implode("\n", $b['napi_cimzettek'])); ?></textarea>
                    <span class="sdh-mezo__sugo">Az itt megadott címek mindenki naptárát megkapják. Üresen mindenki a sajátját kapja a saját címére.</span>
                </div>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrzi.
        if (!isset($_POST['naptar_jelen'])) {
            return;
        }

        $szoveg = static fn (string $k, int $max): string => isset($_POST[$k]) && is_scalar($_POST[$k])
            ? mb_substr(sanitize_text_field(wp_unslash((string) $_POST[$k])), 0, $max)
            : '';
        $sorok = static fn (string $k): string => isset($_POST[$k]) && is_scalar($_POST[$k])
            ? sanitize_textarea_field(wp_unslash((string) $_POST[$k]))
            : '';

        update_option(self::OPTION, [
            'alap_nezet'       => sanitize_key($szoveg('naptar_alap_nezet', 20)),
            'ora_tol'          => (int) $szoveg('naptar_ora_tol', 2),
            'ora_ig'           => (int) $szoveg('naptar_ora_ig', 2),
            'alap_emlekezteto' => isset($_POST['naptar_alap_emlekezteto']) ? array_map('intval', (array) wp_unslash($_POST['naptar_alap_emlekezteto'])) : [],
            'kategoriak'       => $sorok('naptar_kategoriak'),
            'email_be'         => !empty($_POST['naptar_email_be']),
            'kozos_email'      => sanitize_email($szoveg('naptar_kozos_email', 190)),
            'napi_be'          => !empty($_POST['naptar_napi_be']),
            'napi_ora'         => $szoveg('naptar_napi_ora', 5),
            'napi_cimzettek'   => $sorok('naptar_napi_cimzettek'),
        ]);
        // phpcs:enable
    }

    /* =================================================================
     * Személyek – minden névhez saját naptár
     * ============================================================== */

    /** A gyorsítótár ürítése (mentés után; a tesztek is használják). */
    public static function szemelyek_frissit(): void
    {
        self::$szemely_tar = null;
    }

    /**
     * A naptárak gazdái. Többfelhasználós módban a fiókok maguktól kapnak
     * naptárat (profilonként egyet); egyfelhasználós módban az első
     * megnyitáskor a Csapat névlistájából töltődik fel a lista.
     *
     * @return array<int, array{id: int, nev: string, szin: string, email: string, user_id: int, aktiv: bool, betu: string, sorrend: int}>
     */
    public static function szemelyek(bool $inaktivval = false): array
    {
        global $wpdb;

        $kulcs = $inaktivval ? 'mind' : 'aktiv';

        if (isset(self::$szemely_tar[$kulcs])) {
            return self::$szemely_tar[$kulcs];
        }

        $tabla = self::t('szemely');
        $sorok = $wpdb->get_results("SELECT * FROM {$tabla} ORDER BY sorrend ASC, nev ASC, id ASC"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sorok = is_array($sorok) ? $sorok : [];

        $valtozott = false;
        $szinek    = array_keys(self::SZINEK);

        if (!self::egyszeru()) {
            // Profilonként egy naptár: akinek még nincs, most kap.
            $kollegak = SDH_Muhely_Csapat::kollegak(true);
            $van      = [];

            foreach ($sorok as $s) {
                if ((int) $s->user_id > 0) {
                    $van[(int) $s->user_id] = $s;
                }
            }

            foreach ($kollegak as $k) {
                // Frissen olvasva: a kollégalista kérésenként gyorsítótárazott.
                $k['letiltva'] = get_user_meta($k['id'], 'sdh_letiltva', true) === '1';

                if (isset($van[$k['id']])) {
                    $s = $van[$k['id']];

                    // A naptár neve követi a fiók nevét; letiltott fiók naptára nem látszik.
                    $aktiv = $k['letiltva'] ? 0 : 1;

                    if ((string) $s->nev !== $k['nev'] || (int) $s->aktiv !== $aktiv) {
                        $wpdb->update($tabla, ['nev' => mb_substr($k['nev'], 0, 80), 'aktiv' => $aktiv], ['id' => (int) $s->id]);
                        $valtozott = true;
                    }

                    continue;
                }

                if ($k['letiltva']) {
                    continue;
                }

                $wpdb->insert($tabla, [
                    'nev'        => mb_substr($k['nev'], 0, 80),
                    'szin'       => isset(self::SZINEK[$k['szin']]) ? $k['szin'] : $szinek[$k['id'] % count($szinek)],
                    'email'      => '',
                    'user_id'    => $k['id'],
                    'aktiv'      => 1,
                    'sorrend'    => 0,
                    'letrehozva' => self::most(),
                ]);
                $valtozott = true;
            }
        } elseif ($sorok === []) {
            // Közös fiók: az első megnyitáskor a Csapat névlistája (ennek híján a fiók neve) a kiindulás.
            $nevek = class_exists('SDH_Muhely_Csapat') ? SDH_Muhely_Csapat::beallitas()['nevek'] : [];

            if ($nevek === []) {
                $f     = wp_get_current_user();
                $nevek = [$f && $f->exists() && trim((string) $f->display_name) !== '' ? (string) $f->display_name : 'Közös naptár'];
            }

            foreach (array_values($nevek) as $i => $nev) {
                $wpdb->insert($tabla, [
                    'nev'        => mb_substr((string) $nev, 0, 80),
                    'szin'       => $szinek[$i % count($szinek)],
                    'email'      => '',
                    'user_id'    => 0,
                    'aktiv'      => 1,
                    'sorrend'    => $i,
                    'letrehozva' => self::most(),
                ]);
            }

            $valtozott = true;
        }

        if ($valtozott) {
            $sorok = $wpdb->get_results("SELECT * FROM {$tabla} ORDER BY sorrend ASC, nev ASC, id ASC"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sorok = is_array($sorok) ? $sorok : [];
        }

        $mind  = [];
        $aktiv = [];

        foreach ($sorok as $s) {
            $adat = self::szemely_adat($s);

            // Egyfelhasználós módban a fiókhoz kötött (korábbi többfelhasználós) naptárak is nevek maradnak.
            $mind[$adat['id']] = $adat;

            if ($adat['aktiv']) {
                $aktiv[$adat['id']] = $adat;
            }
        }

        self::$szemely_tar = ['mind' => $mind, 'aktiv' => $aktiv];

        return self::$szemely_tar[$kulcs];
    }

    /**
     * @return array{id: int, nev: string, szin: string, email: string, user_id: int, aktiv: bool, betu: string, sorrend: int}
     */
    private static function szemely_adat(object $s): array
    {
        $szin = (string) $s->szin;

        return [
            'id'      => (int) $s->id,
            'nev'     => (string) $s->nev,
            'szin'    => isset(self::SZINEK[$szin]) ? $szin : 'kek',
            'email'   => (string) $s->email,
            'user_id' => (int) $s->user_id,
            'aktiv'   => (int) $s->aktiv === 1,
            'betu'    => self::monogram((string) $s->nev),
            'sorrend' => (int) $s->sorrend,
        ];
    }

    /** A bejelentkezett fiók saját naptára (többfelhasználós módban), különben 0. */
    public static function sajat_szemely_id(?int $user_id = null): int
    {
        if (self::egyszeru()) {
            return 0;
        }

        $user_id = $user_id ?? get_current_user_id();

        foreach (self::szemelyek() as $sz) {
            if ($sz['user_id'] === $user_id && $user_id > 0) {
                return $sz['id'];
            }
        }

        return 0;
    }

    /** A személy értesítési címe: a sajátja, fióknál a fiók címe, végül a közös cím. */
    public static function szemely_email(array $sz): string
    {
        if (is_email($sz['email'])) {
            return $sz['email'];
        }

        if ($sz['user_id'] > 0) {
            $f = get_userdata($sz['user_id']);

            if ($f && is_email((string) $f->user_email)) {
                return (string) $f->user_email;
            }
        }

        return self::beallitas()['kozos_email'];
    }

    /** Ki szerkesztheti a neveket: közös fióknál bárki, többfelhasználós módban az adminisztrátor. */
    private static function szemely_szerkesztheto(): bool
    {
        return self::egyszeru() || SDH_Muhely_Admin_UI::admin_e();
    }

    /* =================================================================
     * Események – olvasás, jogosultság
     * ============================================================== */

    public static function esemeny(int $id): ?object
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('esemeny') . ' WHERE id = %d AND torolve = 0', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return $sor ?: null;
    }

    /** @return array<int, int> */
    private static function resztvevo_idk(object $e): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $e->resztvevok))));
    }

    /** Az esemény minden érintettje: a naptár gazdája és a résztvevők. */
    private static function erintettek(object $e): array
    {
        return array_values(array_unique(array_merge([(int) $e->szemely_id], self::resztvevo_idk($e))));
    }

    /**
     * Szerkesztheti-e a bejelentkezett fiók. Közös fióknál mindenki mindent;
     * többfelhasználós módban az adminisztrátor mindent, a kolléga a saját
     * naptárát, amit ő vett fel, és a fiók nélküli nevek naptárát.
     */
    public static function szerkesztheti(object $e): bool
    {
        if (self::egyszeru() || SDH_Muhely_Admin_UI::admin_e()) {
            return true;
        }

        $en = get_current_user_id();

        if ((int) $e->letrehozta === $en) {
            return true;
        }

        $gazda = self::szemelyek(true)[(int) $e->szemely_id] ?? null;

        return $gazda === null || $gazda['user_id'] === 0 || $gazda['user_id'] === $en;
    }

    /** Más privát eseményéből a kolléga csak annyit lát, hogy foglalt az idő. */
    private static function rejtett(object $e): bool
    {
        if ((int) $e->privat !== 1 || self::egyszeru()) {
            return false;
        }

        $sajat = self::sajat_szemely_id();

        return !in_array($sajat, self::erintettek($e), true) && (int) $e->letrehozta !== get_current_user_id();
    }

    /* =================================================================
     * Ismétlődés: az esemény alkalmai egy időszakban
     * ============================================================== */

    /**
     * Az esemény alkalmai a [$tol, $ig) időszakban, időrendben.
     *
     * @return array<int, array{0: string, 1: string}> kezdet és vég (Y-m-d H:i:s)
     */
    public static function alkalmak(object $e, string $tol, string $ig, int $max = 600): array
    {
        $tz = self::tz();
        $k0 = self::dt((string) $e->kezdet);
        $v0 = (string) $e->veg !== '' ? self::dt((string) $e->veg) : $k0;

        if ($v0 < $k0) {
            $v0 = $k0;
        }

        $hossz = $k0->diff($v0);
        $T     = self::dt($tol);
        $I     = self::dt($ig);
        $ki    = [];

        $kivetel = array_flip(array_filter(array_map('trim', explode(',', (string) ($e->kivetelek ?? '')))));

        $felvesz = static function (DateTimeImmutable $k) use (&$ki, $hossz, $T, $I, $kivetel): void {
            $v = $k->add($hossz);

            if ($k < $I && ($v > $T || $k >= $T) && !isset($kivetel[$k->format('Y-m-d')])) {
                $ki[] = [$k->format('Y-m-d H:i:s'), $v->format('Y-m-d H:i:s')];
            }
        };

        $ism = (string) $e->ismetles;

        if (!isset(self::ISMETLESEK[$ism]) || $ism === '') {
            $felvesz($k0);

            return $ki;
        }

        $koz    = max(1, (int) $e->ism_koz);
        $db_max = max(0, (int) $e->ism_db);
        $veg    = (string) ($e->ism_veg ?? '') !== '' && (string) $e->ism_veg !== '0000-00-00'
            ? self::dt(substr((string) $e->ism_veg, 0, 10) . ' 23:59:59')
            : null;

        // Az esemény hossza napokban: ennyivel korábban kezdődő alkalom is belelóghat az időszakba.
        $hossz_nap = (int) $hossz->days + 1;
        $db        = 0;
        $kor       = 0;

        $mehet = static function (DateTimeImmutable $k) use (&$db, $db_max, $veg, $I): bool {
            return $k < $I && ($veg === null || $k <= $veg) && ($db_max === 0 || $db < $db_max);
        };

        // Alkalomszám nélkül nem kell az elejétől számolni: az időszak elé ugrunk.
        $elore_nap = $db_max === 0 && $T > $k0 ? max(0, (int) $k0->diff($T)->days - $hossz_nap - 1) : 0;

        if ($ism === 'napi') {
            $i = intdiv($elore_nap, $koz);

            while ($kor++ < 6000 && count($ki) < $max) {
                $k = $k0->modify('+' . ($i * $koz) . ' days');

                if (!$mehet($k)) {
                    break;
                }

                $db++;
                $felvesz($k);
                $i++;
            }

            return $ki;
        }

        if ($ism === 'heti') {
            $napok = array_values(array_unique(array_filter(
                array_map('intval', explode(',', (string) $e->ism_napok)),
                static fn (int $n): bool => $n >= 1 && $n <= 7
            )));

            if ($napok === []) {
                $napok = [(int) $k0->format('N')];
            }

            sort($napok);

            // Az első hét hétfője, az esemény kezdési idejével.
            $hetfo = $k0->modify('-' . ((int) $k0->format('N') - 1) . ' days');
            $w     = intdiv(intdiv($elore_nap, 7), $koz);
            $vege  = false;

            while (!$vege && $kor++ < 6000 && count($ki) < $max) {
                $het = $hetfo->modify('+' . ($w * $koz * 7) . ' days');

                foreach ($napok as $nap) {
                    $k = $het->modify('+' . ($nap - 1) . ' days');

                    if ($k < $k0) {
                        continue;
                    }

                    if (!$mehet($k)) {
                        $vege = true;

                        break;
                    }

                    $db++;
                    $felvesz($k);
                }

                $w++;
            }

            return $ki;
        }

        $ev   = (int) $k0->format('Y');
        $ho   = (int) $k0->format('n');
        $nap  = (int) $k0->format('j');
        $ido  = $k0->format('H:i:s');
        $lepes = $ism === 'eves' ? 12 * $koz : $koz;
        $m     = $db_max === 0 ? intdiv(max(0, intdiv($elore_nap, 31) - 1), $lepes) : 0;

        while ($kor++ < 6000 && count($ki) < $max) {
            $honap_index = ($ev * 12 + ($ho - 1)) + $m * $lepes;
            $e_ev        = intdiv($honap_index, 12);
            $e_ho        = $honap_index % 12 + 1;
            $m++;

            // A hónap utolsó napján túli (31-i, február 29-i) alkalom abban a hónapban / évben kimarad.
            if (!checkdate($e_ho, $nap, $e_ev)) {
                // A végfeltételt így is figyelni kell, különben a kihagyott hónapok végtelenítenének.
                $proba = new DateTimeImmutable(sprintf('%04d-%02d-01 %s', $e_ev, $e_ho, $ido), $tz);

                if (!$mehet($proba)) {
                    break;
                }

                continue;
            }

            $k = new DateTimeImmutable(sprintf('%04d-%02d-%02d %s', $e_ev, $e_ho, $nap, $ido), $tz);

            if (!$mehet($k)) {
                break;
            }

            $db++;
            $felvesz($k);
        }

        return $ki;
    }

    /**
     * Az időszak eseményei (ismétlődők is), személyre szűrve.
     *
     * @param array<int, int> $szemelyek üres = mindenki
     * @return array<int, object>
     */
    private static function esemenyek(string $tol, string $ig, array $szemelyek = []): array
    {
        global $wpdb;

        $t = self::t('esemeny');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE torolve = 0 AND (
                (ismetles = '' AND kezdet < %s AND veg >= %s)
                OR (ismetles <> '' AND kezdet < %s AND (ism_veg IS NULL OR ism_veg >= %s))
             ) ORDER BY kezdet ASC, id ASC",
            $ig,
            $tol,
            $ig,
            substr($tol, 0, 10)
        ));
        // phpcs:enable

        $sorok = is_array($sorok) ? $sorok : [];

        if ($szemelyek === []) {
            return $sorok;
        }

        return array_values(array_filter($sorok, static fn (object $e): bool => array_intersect(self::erintettek($e), $szemelyek) !== []));
    }

    /** Az esemény színe: a sajátja, különben a kategóriájáé, végül a naptár gazdájáé. */
    private static function esemeny_szin(object $e, array $kategoriak, array $szemelyek): string
    {
        $szin = (string) $e->szin;

        if (isset(self::SZINEK[$szin])) {
            return $szin;
        }

        if (isset($kategoriak[(string) $e->kategoria])) {
            return $kategoriak[(string) $e->kategoria]['szin'];
        }

        return $szemelyek[(int) $e->szemely_id]['szin'] ?? 'kek';
    }

    /**
     * Egy alkalom a böngészőnek.
     *
     * @return array<string, mixed>
     */
    private static function alkalom_kifele(object $e, string $kezdet, string $veg, array $kategoriak, array $szemelyek): array
    {
        $rejtett = self::rejtett($e);
        $ugyfel  = '';

        if (!$rejtett && (int) $e->ugyfel_id > 0 && class_exists('SDH_Muhely_Ugyfel')) {
            $u      = SDH_Muhely_Ugyfel::egy((int) $e->ugyfel_id);
            $ugyfel = $u ? (string) $u->nev : '';
        }

        return [
            'id'     => (int) $e->id,
            'k'      => str_replace(' ', 'T', substr($kezdet, 0, 16)),
            'v'      => str_replace(' ', 'T', substr($veg, 0, 16)),
            'en'     => (int) $e->egesz_nap === 1,
            'cim'    => $rejtett ? 'Foglalt' : (string) $e->cim,
            'hely'   => $rejtett ? '' : (string) $e->hely,
            'leiras' => $rejtett ? '' : mb_substr(trim((string) $e->leiras), 0, 300),
            'sz'     => (int) $e->szemely_id,
            'resz'   => self::resztvevo_idk($e),
            'kat'    => $rejtett ? '' : (string) $e->kategoria,
            'szin'   => $rejtett ? 'szurke' : self::esemeny_szin($e, $kategoriak, $szemelyek),
            'pri'    => $rejtett ? 'normal' : (string) $e->prioritas,
            'all'    => (string) $e->allapot,
            'ism'    => (string) $e->ismetles !== '',
            'email'  => !$rejtett && self::emailes($e),
            'ert'    => (int) $e->ertesites === 1,
            'privat' => (int) $e->privat === 1,
            'rejt'   => $rejtett,
            'szerk'  => !$rejtett && self::szerkesztheti($e),
            'ugyfel' => $ugyfel,
            'ml'     => $rejtett ? '' : (string) $e->munkalap_szam,
            'link'   => $rejtett ? '' : (string) $e->link,
        ];
    }

    /** Megy-e róla e-mail: megjelölve, vagy „Nagyon fontos". */
    private static function emailes(object $e): bool
    {
        return (int) $e->email === 1 || (string) $e->prioritas === 'kritikus';
    }

    /* =================================================================
     * AJAX: adatok a nézetekhez
     * ============================================================== */

    /**
     * Az időszak alkalmai + a személyek, a kategóriák, a következő események
     * (visszaszámláláshoz); a „Ma" nézetnek a lemaradt események és a fontos levelek is.
     *
     * @param array<int, int> $szemelyek
     * @return array<string, mixed>
     */
    public static function adat(string $tol, string $ig, array $szemelyek = [], bool $ma = false): array
    {
        $b        = self::beallitas();
        $szem     = self::szemelyek();
        $szem_mind = self::szemelyek(true);
        $most     = self::most();
        $alkalmak = [];

        foreach (self::esemenyek($tol . ' 00:00:00', $ig . ' 00:00:00', $szemelyek) as $e) {
            foreach (self::alkalmak($e, $tol . ' 00:00:00', $ig . ' 00:00:00') as [$k, $v]) {
                $alkalmak[] = self::alkalom_kifele($e, $k, $v, $b['kategoriak'], $szem_mind);
            }
        }

        usort($alkalmak, static fn (array $a, array $c): int => [$a['k'], $a['id']] <=> [$c['k'], $c['id']]);

        // A következő események (a most zajlók is): ezekhez megy a visszaszámlálás.
        $kov     = [];
        $kov_ig  = self::dt($most)->modify('+60 days')->format('Y-m-d H:i:s');

        foreach (self::esemenyek($most, $kov_ig, $szemelyek) as $e) {
            if (in_array((string) $e->allapot, ['kesz', 'elmaradt'], true)) {
                continue;
            }

            foreach (self::alkalmak($e, $most, $kov_ig, 3) as [$k, $v]) {
                $kov[] = self::alkalom_kifele($e, $k, $v, $b['kategoriak'], $szem_mind);
            }
        }

        usort($kov, static fn (array $a, array $c): int => [$a['k'], $a['id']] <=> [$c['k'], $c['id']]);

        $valasz = [
            'most'       => str_replace(' ', 'T', $most),
            'tol'        => $tol,
            'ig'         => $ig,
            'szemelyek'  => array_values($szem),
            'sajat'      => self::sajat_szemely_id(),
            'kategoriak' => $b['kategoriak'],
            'esemenyek'  => $alkalmak,
            'kovetkezo'  => array_slice($kov, 0, 6),
            'jelzes_db'  => self::nyitott_jelzes_db(),
        ];

        if ($ma) {
            // Lemaradt: az elmúlt két hét lejárt, de le nem zárt eseményei.
            $lem    = [];
            $lem_tol = self::dt($most)->modify('-14 days')->format('Y-m-d H:i:s');

            foreach (self::esemenyek($lem_tol, $most, $szemelyek) as $e) {
                // Az ismétlődő sorozat állapota az egész sorozaté: a régi alkalmai nem „lemaradt" teendők.
                if (in_array((string) $e->allapot, ['kesz', 'elmaradt'], true) || (string) $e->ismetles !== '') {
                    continue;
                }

                foreach (self::alkalmak($e, $lem_tol, $most) as [$k, $v]) {
                    if ($v < $most && substr($v, 0, 10) < substr($most, 0, 10)) {
                        $lem[] = self::alkalom_kifele($e, $k, $v, $b['kategoriak'], $szem_mind);
                    }
                }
            }

            usort($lem, static fn (array $a, array $c): int => $c['k'] <=> $a['k']);

            $valasz['lemaradt'] = array_slice($lem, 0, 8);
            $valasz['levelek']  = self::fontos_levelek(5);
        }

        return $valasz;
    }

    /**
     * A levelező ügynöke szerint fontos, még el nem intézett levelek (a „Ma" nézethez).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fontos_levelek(int $limit, bool $csak_azonnal = false): array
    {
        if (!class_exists('SDH_Muhely_Levelezes') || SDH_Muhely_Modulok::ki('levelezes') || !SDH_Muhely_Levelezes::van_fiok()) {
            return [];
        }

        $url = SDH_Muhely_Modulok::url('levelezes');
        $ki  = [];

        foreach (SDH_Muhely_Levelezes::fontos_lista($limit, $csak_azonnal) as $l) {
            $ki[] = [
                'id'      => (int) $l['id'],
                'targy'   => (string) $l['targy'],
                'felado'  => (string) $l['felado'],
                'kivonat' => (string) $l['kivonat'],
                'szint'   => (string) $l['fontossag'],
                'ok'      => (string) $l['ok'],
                'datum'   => (string) $l['datum'],
                'url'     => $url,
            ];
        }

        return $ki;
    }

    public static function ajax_adat(): void
    {
        self::jog();

        $tol = self::g('tol', 10);
        $ig  = self::g('ig', 10);

        if (!self::datum_e($tol) || !self::datum_e($ig) || $ig <= $tol) {
            wp_send_json_error(['uzenet' => 'Hibás időszak.']);
        }

        // Egy kérés legfeljebb bő egy évet ad (az éves nézet): ennél hosszabb időszakot rövidítünk.
        $max_ig = self::dt($tol)->modify('+400 days')->format('Y-m-d');

        if ($ig > $max_ig) {
            $ig = $max_ig;
        }

        $aktiv     = self::szemelyek();
        $szemelyek = array_values(array_intersect(self::g_szamok('szemelyek'), array_keys($aktiv)));

        // Időnként itt is megnézzük, mi lett esedékes (a pulzus mellett), hogy a szám friss legyen.
        self::esedekes_feldolgoz();

        wp_send_json_success(self::adat($tol, $ig, $szemelyek, self::g('ma', 1) === '1'));
    }

    /* =================================================================
     * Oldal
     * ============================================================== */

    public static function admin_eszkozok(): void
    {
        $oldal = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!str_starts_with($oldal, SDH_Muhely_Admin_UI::FOMENU)) {
            return;
        }

        wp_enqueue_style('sdh-muhely-naptar', SDH_MUHELY_URL . 'assets/naptar.css', ['sdh-muhely-admin'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/naptar.css'));
        wp_enqueue_script('sdh-muhely-naptar', SDH_MUHELY_URL . 'assets/naptar.js', ['sdh-muhely-app'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/naptar.js'), true);
    }

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        $b = self::beallitas();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $datum   = isset($_GET['datum']) ? sanitize_text_field(wp_unslash((string) $_GET['datum'])) : '';
        $esemeny = isset($_GET['esemeny']) ? (int) $_GET['esemeny'] : 0;
        // phpcs:enable

        $config = [
            'alapNezet'   => $b['alap_nezet'],
            'oraTol'      => $b['ora_tol'],
            'oraIg'       => $b['ora_ig'],
            'nezetek'     => self::NEZETEK,
            'allapotok'   => self::ALLAPOTOK,
            'prioritasok' => self::PRIORITASOK,
            'egyszeru'    => self::egyszeru(),
            'szemelySzerk' => self::szemely_szerkesztheto(),
            'datum'       => self::datum_e($datum) ? $datum : '',
            'esemeny'     => $esemeny,
            'levelUrl'    => class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Modulok::be('levelezes') ? SDH_Muhely_Modulok::url('levelezes') : '',
            'beallitasUrl' => SDH_Muhely_Admin_UI::admin_e() && class_exists('SDH_Muhely_Beallitasok') ? SDH_Muhely_Beallitasok::url('naptar') : '',
        ];

        ?>
        <div class="sdh-wrap sdh-wrap--teljes sdh-np" data-sdh-naptar="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Naptár',
                self::egyszeru()
                    ? 'Minden névhez saját naptár. Az esedékes eseményről kártya szól, és addig marad, amíg ki nem nyomod.'
                    : 'Mindenkinek saját naptára van, a többieké mellé tehető. Az esedékes eseményről kártya szól, és addig marad, amíg ki nem nyomod.',
                [[
                    'cimke'      => '+ Új esemény',
                    'ikon'       => 'plusz',
                    'url'        => '#',
                    'elsodleges' => true,
                    'adatok'     => ['sdh-np-uj' => ''],
                ]]
            );
            ?>

            <div class="sdh-np__keret" data-sdh-np-keret>
                <aside class="sdh-np__oldal" data-sdh-np-oldal aria-label="Naptárak és következő események"></aside>
                <section class="sdh-np__fo">
                    <div class="sdh-np__sav" data-sdh-np-sav></div>
                    <div class="sdh-np__nezet" data-sdh-np-nezet aria-live="polite">
                        <div class="sdh-np__toltes">Betöltés…</div>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }

    /* =================================================================
     * Esemény: űrlap
     * ============================================================== */

    /** Pill-választó (rádió vagy jelölő) – a Csapat pilljeinek stílusával. */
    private static function pillek(string $nev, array $elemek, array $kijelolt, bool $tobb = false, string $extra = ''): void
    {
        echo '<div class="sdh-cs-pillek sdh-np-pillek"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rögzített attribútum.

        $kijelolt = array_map('strval', $kijelolt);

        foreach ($elemek as $ertek => $elem) {
            $cimke = is_array($elem) ? (string) $elem['cimke'] : (string) $elem;
            $szin  = is_array($elem) ? (string) ($elem['szin'] ?? '') : '';
            $be    = in_array((string) $ertek, $kijelolt, true);

            printf(
                '<label class="sdh-cs-pill%5$s"><input type="%1$s" name="%2$s" value="%3$s"%4$s>%6$s<span>%7$s</span></label>',
                $tobb ? 'checkbox' : 'radio',
                esc_attr($nev . ($tobb ? '[]' : '')),
                esc_attr((string) $ertek),
                $be ? ' checked' : '',
                $be ? ' is-aktiv' : '',
                $szin !== '' ? '<i class="sdh-np-potty sdh-np-szin--' . esc_attr($szin) . '" aria-hidden="true"></i>' : '',
                esc_html($cimke)
            );
        }

        echo '</div>';
    }

    /** Dátummező a rendszer saját naptárával (app.js: data-sdh-pop="datum"). */
    private static function datum_mezo(string $nev, string $ertek, string $extra = ''): void
    {
        printf(
            '<input type="text" name="%1$s" id="np_%1$s" class="sdh-pop sdh-pop--datum" readonly autocomplete="off" data-sdh-pop="datum" data-sdh-pop-adat="[]" placeholder="éééé-hh-nn" value="%2$s"%3$s>',
            esc_attr($nev),
            esc_attr($ertek),
            $extra // phpcs:ignore WordPress.Security.EscapeOutput -- rögzített attribútum.
        );
    }

    /** Időpont két listából: óra és perc (5 perces lépés). */
    private static function ido_mezo(string $nev, string $ido): void
    {
        [$o, $p] = array_map('intval', explode(':', $ido . ':0'));
        $p       = (int) (round($p / 5) * 5) % 60;

        echo '<span class="sdh-np-ido" data-sdh-np-ido>';
        printf('<select name="%1$s_ora" aria-label="Óra">', esc_attr($nev));

        for ($i = 0; $i < 24; $i++) {
            printf('<option value="%1$02d"%2$s>%1$02d</option>', $i, selected($o, $i, false));
        }

        printf('</select><b aria-hidden="true">:</b><select name="%1$s_perc" aria-label="Perc">', esc_attr($nev));

        for ($i = 0; $i < 60; $i += 5) {
            printf('<option value="%1$02d"%2$s>%1$02d</option>', $i, selected($p, $i, false));
        }

        echo '</select></span>';
    }

    public static function ajax_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }

        $id = (int) self::g('id', 20);
        $e  = $id > 0 ? self::esemeny($id) : null;

        if ($id > 0 && $e === null) {
            echo '<h2 class="sdh-modal__cim">Esemény</h2><div class="sdh-uzenet sdh-uzenet--hiba">Ez az esemény már nincs meg.</div>';
            wp_die();
        }

        if ($e !== null && self::rejtett($e)) {
            echo '<h2 class="sdh-modal__cim">Foglalt</h2><div class="sdh-uzenet sdh-uzenet--figyelem">Ez egy kolléga privát eseménye: csak azt látod, hogy az idő foglalt.</div>';
            wp_die();
        }

        $b         = self::beallitas();
        $szemelyek = self::szemelyek();
        $szerk     = $e === null || self::szerkesztheti($e);
        $most      = self::dt(self::most());

        // Új eseménynél a hívó (a naptár egy cellája) hozza az időpontot és a nevet.
        $kert_datum  = self::g('datum', 10);
        $kert_ido    = self::g('ido', 5);
        $kert_szemely = (int) self::g('szemely', 20);
        $kert_egesz  = self::g('egesz', 1) === '1';
        // Egy alkalomra kattintva nyílt (ismétlődő eseménynél a törléshez kell).
        $alkalom     = self::g('alkalom', 10);

        if ($e !== null) {
            $k        = self::dt((string) $e->kezdet);
            $v        = self::dt((string) $e->veg);
            $egesz    = (int) $e->egesz_nap === 1;
            $szemely  = (int) $e->szemely_id;
            $resztvevok = self::resztvevo_idk($e);
            $eml      = array_map('intval', array_filter(explode(',', (string) $e->emlekeztetok), 'strlen'));
        } else {
            $datum = self::datum_e($kert_datum) ? $kert_datum : $most->format('Y-m-d');

            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $kert_ido) === 1) {
                $k = self::dt($datum . ' ' . $kert_ido . ':00');
            } else {
                // A következő kerek óra (a mai napon), más napon a munkaidő eleje.
                $ora = $datum === $most->format('Y-m-d') ? min(23, (int) $most->format('G') + 1) : max($b['ora_tol'], 9);
                $k   = self::dt($datum . ' ' . sprintf('%02d:00:00', $ora));
            }

            $v          = $k->modify('+1 hour');
            $egesz      = $kert_egesz;
            $szemely    = isset($szemelyek[$kert_szemely]) ? $kert_szemely : (self::sajat_szemely_id() ?: (int) (array_key_first($szemelyek) ?? 0));
            $resztvevok = [];
            $eml        = $b['alap_emlekezteto'];
        }

        $szemely_pillek = [];

        foreach ($szemelyek as $sz) {
            $szemely_pillek[$sz['id']] = ['cimke' => $sz['nev'], 'szin' => $sz['szin']];
        }

        // A már nem aktív gazda / résztvevő neve szerkesztéskor megmarad a listában.
        foreach (array_merge([$szemely], $resztvevok) as $regi_id) {
            if ($regi_id > 0 && !isset($szemely_pillek[$regi_id])) {
                $regi = self::szemelyek(true)[$regi_id] ?? null;

                if ($regi !== null) {
                    $szemely_pillek[$regi_id] = ['cimke' => $regi['nev'] . ' (inaktív)', 'szin' => $regi['szin']];
                }
            }
        }

        $kat_pillek = ['' => ['cimke' => 'Nincs', 'szin' => '']];

        foreach ($b['kategoriak'] as $kk => $kat) {
            $kat_pillek[$kk] = ['cimke' => $kat['nev'], 'szin' => $kat['szin']];
        }

        $szin_pillek = ['' => ['cimke' => 'A kategória színe', 'szin' => '']];

        foreach (self::SZINEK as $sk => $sn) {
            $szin_pillek[$sk] = ['cimke' => $sn, 'szin' => $sk];
        }

        $nap_pillek = [];

        foreach (self::NAPOK_ROVID as $n => $rovid) {
            $nap_pillek[$n] = $rovid;
        }

        $ism_napok = $e !== null ? array_filter(explode(',', (string) $e->ism_napok), 'strlen') : [];
        $ismetles  = $e !== null ? (string) $e->ismetles : '';
        $ism_mod   = $e !== null && (int) $e->ism_db > 0 ? 'db' : ($e !== null && (string) ($e->ism_veg ?? '') !== '' ? 'datum' : 'soha');

        ?>
        <form class="sdh-urlap sdh-np-urlap" data-sdh-ajax-action="sdh_muhely_naptar_ment" data-sdh-np-urlap novalidate>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::g('kontextus', 10) === 'admin' ? 'admin' : 'frontend'); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <input type="hidden" name="alkalom" value="<?php echo esc_attr(self::datum_e($alkalom) ? $alkalom : ''); ?>">

            <h2 class="sdh-modal__cim"><?php echo $e ? 'Esemény' : 'Új esemény'; ?>
                <?php if ($e) : ?>
                    <small class="sdh-np-urlap__vissza" data-sdh-np-vissza data-kezdet="<?php echo esc_attr($k->format('Y-m-d\TH:i')); ?>" data-veg="<?php echo esc_attr($v->format('Y-m-d\TH:i')); ?>"></small>
                <?php endif; ?>
            </h2>

            <?php if (!$szerk) : ?>
                <div class="sdh-uzenet sdh-uzenet--figyelem">Ez egy kolléga naptára: megnézheted, de csak ő (vagy az adminisztrátor) módosíthatja.</div>
            <?php endif; ?>

            <div class="sdh-np-urlap__racs">
                <div class="sdh-np-urlap__oszlop">
                    <div class="sdh-ig">
                        <label for="np_cim">Megnevezés <span class="sdh-kotelezo">*</span></label>
                        <input type="text" name="cim" id="np_cim" maxlength="190" autocomplete="off" required
                               placeholder="pl. Megbeszélés, visszahívás, határidő…" value="<?php echo esc_attr($e ? (string) $e->cim : ''); ?>">
                    </div>

                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Naptár <span class="sdh-kotelezo">*</span></span>
                        <?php self::pillek('szemely_id', $szemely_pillek, [$szemely]); ?>
                    </div>

                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Mikor</span>
                        <div class="sdh-np-mikor">
                            <label class="sdh-np-jelolo">
                                <input type="checkbox" name="egesz_nap" value="1" data-sdh-np-egesz<?php checked($egesz); ?>> Egész nap
                            </label>
                            <div class="sdh-np-mikor__sor">
                                <span>Kezdet</span>
                                <?php self::datum_mezo('kezdet_datum', $k->format('Y-m-d')); ?>
                                <?php self::ido_mezo('kezdet', $k->format('H:i')); ?>
                            </div>
                            <div class="sdh-np-mikor__sor">
                                <span>Vég</span>
                                <?php self::datum_mezo('veg_datum', $v->format('Y-m-d')); ?>
                                <?php self::ido_mezo('veg', $v->format('H:i')); ?>
                            </div>
                        </div>
                    </div>

                    <div class="sdh-ig">
                        <label for="np_hely">Helyszín</label>
                        <input type="text" name="hely" id="np_hely" maxlength="190" autocomplete="off" value="<?php echo esc_attr($e ? (string) $e->hely : ''); ?>">
                    </div>

                    <div class="sdh-ig">
                        <label for="np_leiras">Leírás, jegyzet</label>
                        <textarea name="leiras" id="np_leiras" rows="5" maxlength="5000"><?php echo esc_textarea($e ? (string) $e->leiras : ''); ?></textarea>
                    </div>

                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Résztvevők</span>
                        <?php self::pillek('resztvevok', $szemely_pillek, $resztvevok, true); ?>
                        <span class="sdh-mezo__sugo">Az ő naptárukban is megjelenik, és ők is megkapják az értesítést.</span>
                    </div>
                </div>

                <div class="sdh-np-urlap__oszlop">
                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Kategória</span>
                        <?php self::pillek('kategoria', $kat_pillek, [$e ? (string) $e->kategoria : '']); ?>
                    </div>

                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Fontosság</span>
                        <?php self::pillek('prioritas', self::PRIORITASOK, [$e ? (string) $e->prioritas : 'normal']); ?>
                    </div>

                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Állapot</span>
                        <?php self::pillek('allapot', self::ALLAPOTOK, [$e ? (string) $e->allapot : 'tervezett']); ?>
                    </div>

                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Emlékeztető</span>
                        <?php self::pillek('emlekeztetok', self::EMLEKEZTETOK, $eml, true); ?>
                    </div>

                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Értesítés</span>
                        <div class="sdh-np-jelolok">
                            <label class="sdh-np-jelolo">
                                <input type="checkbox" name="ertesites" value="1"<?php checked($e === null || (int) $e->ertesites === 1); ?>>
                                Kártya a rendszerben, amikor esedékes (addig marad, amíg ki nem nyomják)
                            </label>
                            <label class="sdh-np-jelolo">
                                <input type="checkbox" name="email" value="1"<?php checked($e !== null && (int) $e->email === 1); ?>>
                                E-mail értesítés (a „Nagyon fontos" eseményről e nélkül is megy)
                            </label>
                            <?php if (!self::egyszeru()) : ?>
                                <label class="sdh-np-jelolo">
                                    <input type="checkbox" name="privat" value="1"<?php checked($e !== null && (int) $e->privat === 1); ?>>
                                    Privát – a többiek csak annyit látnak, hogy foglalt
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="sdh-ig">
                        <label for="np_ismetles">Ismétlődés</label>
                        <div class="sdh-np-ism" data-sdh-np-ism>
                            <div class="sdh-np-ism__sor">
                                <select name="ismetles" id="np_ismetles" data-sdh-np-ismetles>
                                    <?php foreach (self::ISMETLESEK as $ik => $in) : ?>
                                        <option value="<?php echo esc_attr($ik); ?>" <?php selected($ismetles, $ik); ?>><?php echo esc_html($in); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="sdh-np-ism__koz" data-sdh-np-ism-resz>minden
                                    <input type="number" name="ism_koz" min="1" max="99" step="1" value="<?php echo (int) ($e ? max(1, (int) $e->ism_koz) : 1); ?>">.
                                    <span data-sdh-np-ism-egyseg>alkalommal</span>
                                </label>
                            </div>
                            <div class="sdh-np-ism__sor" data-sdh-np-ism-napok>
                                <?php self::pillek('ism_napok', $nap_pillek, $ism_napok, true); ?>
                            </div>
                            <div class="sdh-np-ism__sor" data-sdh-np-ism-resz>
                                <span>Vége:</span>
                                <?php
                                self::pillek('ism_mod', ['soha' => 'Soha', 'datum' => 'Eddig a napig', 'db' => 'Ennyi alkalom'], [$ism_mod], false, ' data-sdh-np-ism-mod');
                                self::datum_mezo('ism_veg', $e && (string) ($e->ism_veg ?? '') !== '' ? substr((string) $e->ism_veg, 0, 10) : '', ' data-sdh-np-ism-veg');
                                ?>
                                <input type="number" name="ism_db" min="1" max="999" step="1" class="sdh-np-ism__db" data-sdh-np-ism-db
                                       value="<?php echo (int) ($e && (int) $e->ism_db > 0 ? $e->ism_db : 10); ?>" aria-label="Alkalmak száma">
                            </div>
                        </div>
                    </div>

                    <div class="sdh-ig">
                        <span class="sdh-ig__cimke">Szín</span>
                        <?php self::pillek('szin', $szin_pillek, [$e ? (string) $e->szin : '']); ?>
                    </div>

                    <div class="sdh-np-urlap__ketto">
                        <?php if (class_exists('SDH_Muhely_Ugyfel')) : ?>
                            <div class="sdh-ig">
                                <span class="sdh-ig__cimke">Ügyfél</span>
                                <?php SDH_Muhely_Ugyfel::valaszto_mezo($e ? (int) $e->ugyfel_id : 0, 'ugyfel_id', 'Nem kötelező – név, telefon…'); ?>
                            </div>
                        <?php endif; ?>
                        <div class="sdh-ig">
                            <label for="np_munkalap">Munkalap száma</label>
                            <input type="text" name="munkalap_szam" id="np_munkalap" maxlength="20" autocomplete="off" inputmode="numeric"
                                   value="<?php echo esc_attr($e ? (string) $e->munkalap_szam : ''); ?>">
                        </div>
                    </div>

                    <div class="sdh-ig">
                        <label for="np_link">Hivatkozás</label>
                        <input type="text" name="link" id="np_link" maxlength="255" autocomplete="off" placeholder="https://…"
                               value="<?php echo esc_attr($e ? (string) $e->link : ''); ?>">
                    </div>
                </div>
            </div>

            <div class="sdh-urlap__lablec">
                <?php if ($szerk) : ?>
                    <button type="submit" class="sdh-gomb sdh-gomb--elsodleges"><?php echo $e ? 'Mentés' : 'Felveszem a naptárba'; ?></button>
                <?php endif; ?>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem><?php echo $szerk ? 'Mégsem' : 'Bezárás'; ?></button>

                <?php if ($e && $szerk) : ?>
                    <span class="sdh-np-urlap__torles">
                        <?php if ((string) $e->ismetles !== '' && self::datum_e($alkalom)) : ?>
                            <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-np-torol="alkalom">Csak ez az alkalom törlése</button>
                        <?php endif; ?>
                        <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-np-urlap__torol" data-sdh-np-torol="mind"><?php echo (string) $e->ismetles !== '' ? 'Az egész sorozat törlése' : 'Törlés'; ?></button>
                    </span>
                <?php endif; ?>
            </div>
        </form>
        <?php
        wp_die();
    }

    /* =================================================================
     * Esemény: mentés, áthelyezés, állapot, törlés
     * ============================================================== */

    /**
     * Az űrlap adatai ellenőrizve. Hibánál a hibaüzenet (szöveg).
     *
     * @param array<string, mixed> $be
     * @return array<string, mixed>|string
     */
    public static function esemeny_adat(array $be)
    {
        $szoveg = static fn (string $k, int $max): string => isset($be[$k]) && is_scalar($be[$k]) ? mb_substr(sanitize_text_field((string) $be[$k]), 0, $max) : '';
        $lista  = static function (string $k) use ($be): array {
            $f = $be[$k] ?? [];
            $f = is_scalar($f) ? explode(',', (string) $f) : (array) $f;

            return array_values(array_filter(array_map(static fn ($x): string => is_scalar($x) ? trim((string) $x) : '', $f), 'strlen'));
        };

        $cim = $szoveg('cim', 190);

        if ($cim === '') {
            return 'Add meg az esemény megnevezését.';
        }

        $szemelyek = self::szemelyek(true);
        $szemely   = (int) ($be['szemely_id'] ?? 0);

        if (!isset($szemelyek[$szemely])) {
            return 'Válaszd ki, kinek a naptárába kerüljön.';
        }

        $kd = $szoveg('kezdet_datum', 10);
        $vd = $szoveg('veg_datum', 10);

        if (!self::datum_e($kd)) {
            return 'Add meg a kezdés napját.';
        }

        if (!self::datum_e($vd) || $vd < $kd) {
            $vd = $kd;
        }

        $egesz = !empty($be['egesz_nap']);
        $ido   = static function (string $k) use ($be): string {
            $o = isset($be[$k . '_ora']) ? max(0, min(23, (int) $be[$k . '_ora'])) : 0;
            $p = isset($be[$k . '_perc']) ? max(0, min(59, (int) $be[$k . '_perc'])) : 0;

            return sprintf('%02d:%02d:00', $o, $p);
        };

        if ($egesz) {
            $kezdet = $kd . ' 00:00:00';
            $veg    = $vd . ' 23:59:59';
        } else {
            $kezdet = $kd . ' ' . $ido('kezdet');
            $veg    = $vd . ' ' . $ido('veg');

            if ($veg <= $kezdet) {
                // Hibás vagy hiányzó vég: egy órás esemény.
                $veg = self::dt($kezdet)->modify('+1 hour')->format('Y-m-d H:i:s');
            }
        }

        $b        = self::beallitas();
        $kat      = sanitize_key($szoveg('kategoria', 40));
        $szin     = sanitize_key($szoveg('szin', 20));
        $pri      = sanitize_key($szoveg('prioritas', 10));
        $allapot  = sanitize_key($szoveg('allapot', 12));
        $ismetles = sanitize_key($szoveg('ismetles', 10));
        $ismetles = isset(self::ISMETLESEK[$ismetles]) ? $ismetles : '';

        $resztvevok = [];

        foreach ($lista('resztvevok') as $r) {
            if (isset($szemelyek[(int) $r]) && (int) $r !== $szemely) {
                $resztvevok[] = (int) $r;
            }
        }

        $eml = [];

        foreach ($lista('emlekeztetok') as $p) {
            if (is_numeric($p) && isset(self::EMLEKEZTETOK[(int) $p])) {
                $eml[] = (int) $p;
            }
        }

        sort($eml);

        $ism_napok = [];

        foreach ($lista('ism_napok') as $n) {
            if ((int) $n >= 1 && (int) $n <= 7) {
                $ism_napok[] = (int) $n;
            }
        }

        sort($ism_napok);

        $ism_mod = sanitize_key($szoveg('ism_mod', 10));
        $ism_veg = $szoveg('ism_veg', 10);
        $link    = isset($be['link']) && is_scalar($be['link']) ? esc_url_raw(trim((string) $be['link'])) : '';

        return [
            'szemely_id'    => $szemely,
            'cim'           => $cim,
            'leiras'        => isset($be['leiras']) && is_scalar($be['leiras']) ? mb_substr(sanitize_textarea_field((string) $be['leiras']), 0, 5000) : '',
            'hely'          => $szoveg('hely', 190),
            'kezdet'        => $kezdet,
            'veg'           => $veg,
            'egesz_nap'     => $egesz ? 1 : 0,
            'kategoria'     => isset($b['kategoriak'][$kat]) ? $kat : '',
            'szin'          => isset(self::SZINEK[$szin]) ? $szin : '',
            'prioritas'     => isset(self::PRIORITASOK[$pri]) ? $pri : 'normal',
            'allapot'       => isset(self::ALLAPOTOK[$allapot]) ? $allapot : 'tervezett',
            'ismetles'      => $ismetles,
            'ism_koz'       => max(1, min(99, (int) ($be['ism_koz'] ?? 1))),
            'ism_napok'     => $ismetles === 'heti' ? implode(',', array_unique($ism_napok)) : '',
            'ism_veg'       => $ismetles !== '' && $ism_mod === 'datum' && self::datum_e($ism_veg) && $ism_veg >= $kd ? $ism_veg : null,
            'ism_db'        => $ismetles !== '' && $ism_mod === 'db' ? max(1, min(999, (int) ($be['ism_db'] ?? 1))) : 0,
            'emlekeztetok'  => implode(',', array_unique($eml)),
            'ertesites'     => !empty($be['ertesites']) ? 1 : 0,
            'email'         => !empty($be['email']) ? 1 : 0,
            'resztvevok'    => implode(',', array_unique($resztvevok)),
            'ugyfel_id'     => max(0, (int) ($be['ugyfel_id'] ?? 0)),
            'munkalap_szam' => mb_substr((string) preg_replace('/[^0-9A-Za-z\-\/]/', '', $szoveg('munkalap_szam', 20)), 0, 20),
            'link'          => mb_substr($link, 0, 255),
            'privat'        => !empty($be['privat']) && !self::egyszeru() ? 1 : 0,
        ];
    }

    /**
     * Esemény létrehozása vagy módosítása. Visszaadja az azonosítót, hibánál a hibaüzenetet.
     *
     * @param array<string, mixed> $be
     * @return int|string
     */
    public static function ment(int $id, array $be)
    {
        global $wpdb;

        $adat = self::esemeny_adat($be);

        if (is_string($adat)) {
            return $adat;
        }

        if ($id > 0) {
            $regi = self::esemeny($id);

            if ($regi === null) {
                return 'Ez az esemény már nincs meg.';
            }

            if (!self::szerkesztheti($regi)) {
                return 'Ezt az eseményt csak a naptár gazdája vagy az adminisztrátor módosíthatja.';
            }

            // Új időpont vagy új ismétlődés: a régi alkalmak kihagyásai és jelzései már nem érvényesek.
            $ido_valtozott = (string) $regi->kezdet !== $adat['kezdet'] || (string) $regi->veg !== $adat['veg']
                || (string) $regi->ismetles !== $adat['ismetles'] || (string) $regi->emlekeztetok !== $adat['emlekeztetok'];

            if ((string) $regi->kezdet !== $adat['kezdet'] || (string) $regi->ismetles !== $adat['ismetles']) {
                $adat['kivetelek'] = '';
            }

            $wpdb->update(self::t('esemeny'), $adat + ['modositva' => self::most()], ['id' => $id]);

            if ($ido_valtozott) {
                // A még ki nem nyomott kártyák és a ki nem ment levelek az új időponthoz igazodnak.
                $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('jelzes') . ' WHERE esemeny_id = %d AND kinyomva IS NULL AND kuldve IS NULL', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }

            return $id;
        }

        $wpdb->insert(self::t('esemeny'), $adat + [
            'kivetelek'  => '',
            'letrehozta' => get_current_user_id(),
            'letrehozva' => self::most(),
            'modositva'  => self::most(),
            'torolve'    => 0,
        ]);

        return (int) $wpdb->insert_id > 0 ? (int) $wpdb->insert_id : 'A mentés nem sikerült.';
    }

    public static function ajax_ment(): void
    {
        self::jog();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a jog() ellenőrizte.
        $eredmeny = self::ment((int) self::g('id', 20), wp_unslash($_POST));

        if (is_string($eredmeny)) {
            wp_send_json_error(['uzenet' => $eredmeny]);
        }

        $kontextus = self::g('kontextus', 10) === 'admin' ? 'admin' : 'frontend';
        $e         = self::esemeny($eredmeny);

        wp_send_json_success([
            'id'     => $eredmeny,
            'datum'  => $e ? substr((string) $e->kezdet, 0, 10) : '',
            'vissza' => SDH_Muhely_Modulok::visszateres(self::KULCS, $e ? ['datum' => substr((string) $e->kezdet, 0, 10)] : [], $kontextus),
        ]);
    }

    /** Húzással áthelyezett / átméretezett esemény (naptar.js). Ismétlődő sorozatot nem mozgat. */
    public static function ajax_athelyez(): void
    {
        self::jog();

        global $wpdb;

        $e = self::esemeny((int) self::g('id', 20));

        if ($e === null) {
            wp_send_json_error(['uzenet' => 'Ez az esemény már nincs meg.']);
        }

        if (!self::szerkesztheti($e) || self::rejtett($e)) {
            wp_send_json_error(['uzenet' => 'Ezt az eseményt csak a naptár gazdája módosíthatja.']);
        }

        if ((string) $e->ismetles !== '') {
            wp_send_json_error(['uzenet' => 'Ismétlődő esemény időpontját az űrlapján lehet módosítani.']);
        }

        $kezdet = str_replace('T', ' ', self::g('kezdet', 16));
        $veg    = str_replace('T', ' ', self::g('veg', 16));
        $minta  = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/';

        if (preg_match($minta, $kezdet) !== 1 || preg_match($minta, $veg) !== 1 || !self::datum_e(substr($kezdet, 0, 10)) || !self::datum_e(substr($veg, 0, 10)) || $veg < $kezdet) {
            wp_send_json_error(['uzenet' => 'Hibás időpont.']);
        }

        $adat = ['modositva' => self::most()];

        if ((int) $e->egesz_nap === 1) {
            $adat['kezdet'] = substr($kezdet, 0, 10) . ' 00:00:00';
            $adat['veg']    = substr($veg, 0, 10) . ' 23:59:59';
        } else {
            $adat['kezdet'] = $kezdet . ':00';
            $adat['veg']    = $veg . ':00';
        }

        $szemely = (int) self::g('szemely', 20);

        // A „Személyek" és az „Idővonal" nézetben másik név naptárába is áthúzható.
        if ($szemely > 0 && isset(self::szemelyek()[$szemely])) {
            $adat['szemely_id'] = $szemely;
            $adat['resztvevok'] = implode(',', array_diff(self::resztvevo_idk($e), [$szemely]));
        }

        $wpdb->update(self::t('esemeny'), $adat, ['id' => (int) $e->id]);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('jelzes') . ' WHERE esemeny_id = %d AND kinyomva IS NULL AND kuldve IS NULL', (int) $e->id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        wp_send_json_success(['id' => (int) $e->id]);
    }

    /** Gyors állapotváltás (a „Tábla" nézet oszlopai, a „Kész" pipa). */
    public static function ajax_allapot(): void
    {
        self::jog();

        global $wpdb;

        $e       = self::esemeny((int) self::g('id', 20));
        $allapot = sanitize_key(self::g('allapot', 12));

        if ($e === null || !isset(self::ALLAPOTOK[$allapot])) {
            wp_send_json_error(['uzenet' => 'Ez az esemény már nincs meg.']);
        }

        if (!self::szerkesztheti($e) || self::rejtett($e)) {
            wp_send_json_error(['uzenet' => 'Ezt az eseményt csak a naptár gazdája módosíthatja.']);
        }

        $wpdb->update(self::t('esemeny'), ['allapot' => $allapot, 'modositva' => self::most()], ['id' => (int) $e->id]);

        // A lezárt eseményről nem kell tovább szólni.
        if (in_array($allapot, ['kesz', 'elmaradt'], true)) {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::t('jelzes') . " SET kinyomva = %s WHERE esemeny_id = %d AND tipus = 'app' AND kinyomva IS NULL", self::most(), (int) $e->id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        wp_send_json_success(['id' => (int) $e->id, 'allapot' => $allapot]);
    }

    /** Törlés: az egész esemény / sorozat (jelölés), vagy ismétlődőnél egyetlen alkalom (kivétel). */
    public static function ajax_torol(): void
    {
        self::jog();

        global $wpdb;

        $e = self::esemeny((int) self::g('id', 20));

        if ($e === null) {
            wp_send_json_error(['uzenet' => 'Ez az esemény már nincs meg.']);
        }

        if (!self::szerkesztheti($e) || self::rejtett($e)) {
            wp_send_json_error(['uzenet' => 'Ezt az eseményt csak a naptár gazdája törölheti.']);
        }

        $alkalom = self::g('alkalom', 10);

        if (self::g('mod', 10) === 'alkalom' && (string) $e->ismetles !== '' && self::datum_e($alkalom)) {
            $kivetelek   = array_filter(array_map('trim', explode(',', (string) $e->kivetelek)));
            $kivetelek[] = $alkalom;

            $wpdb->update(self::t('esemeny'), ['kivetelek' => implode(',', array_unique($kivetelek)), 'modositva' => self::most()], ['id' => (int) $e->id]);
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('jelzes') . ' WHERE esemeny_id = %d AND alkalom LIKE %s', (int) $e->id, $alkalom . '%')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } else {
            $wpdb->update(self::t('esemeny'), ['torolve' => 1, 'modositva' => self::most()], ['id' => (int) $e->id]);
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('jelzes') . ' WHERE esemeny_id = %d', (int) $e->id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        wp_send_json_success(['id' => (int) $e->id]);
    }

    /* =================================================================
     * Személy: űrlap és mentés
     * ============================================================== */

    public static function ajax_szemely_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog()) || !self::szemely_szerkesztheto()) {
            echo '<h2 class="sdh-modal__cim">Naptár</h2><div class="sdh-uzenet sdh-uzenet--hiba">A neveket az adminisztrátor szerkesztheti.</div>';
            wp_die();
        }

        $id = (int) self::g('id', 20);
        $sz = $id > 0 ? (self::szemelyek(true)[$id] ?? null) : null;

        if ($id > 0 && $sz === null) {
            echo '<h2 class="sdh-modal__cim">Naptár</h2><div class="sdh-uzenet sdh-uzenet--hiba">Ez a név már nincs meg.</div>';
            wp_die();
        }

        $fiokos = $sz !== null && $sz['user_id'] > 0 && !self::egyszeru();
        $szinek = [];

        foreach (self::SZINEK as $k => $n) {
            $szinek[$k] = ['cimke' => $n, 'szin' => $k];
        }

        ?>
        <form class="sdh-urlap sdh-np-urlap sdh-np-urlap--szemely" data-sdh-ajax-action="sdh_muhely_naptarszemely_ment" novalidate>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::g('kontextus', 10) === 'admin' ? 'admin' : 'frontend'); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <h2 class="sdh-modal__cim"><?php echo $sz ? 'Naptár: ' . esc_html($sz['nev']) : 'Új név – új naptár'; ?></h2>

            <div class="sdh-ig">
                <label for="npsz_nev">Név <span class="sdh-kotelezo">*</span></label>
                <input type="text" name="nev" id="npsz_nev" maxlength="80" autocomplete="off" required
                       value="<?php echo esc_attr($sz ? $sz['nev'] : ''); ?>"<?php echo $fiokos ? ' readonly' : ''; ?>
                       placeholder="pl. Péter, Műhely, Kiszállás">
                <?php if ($fiokos) : ?>
                    <span class="sdh-mezo__sugo">Ez a naptár egy felhasználói fiókhoz tartozik: a neve a fiók nevét követi (Csapat › Kollégák).</span>
                <?php else : ?>
                    <span class="sdh-mezo__sugo">Minden névhez saját naptár tartozik – belépés (fiók) nem kell hozzá.</span>
                <?php endif; ?>
            </div>

            <div class="sdh-ig">
                <label for="npsz_email">E-mail az értesítésekhez</label>
                <input type="text" name="email" id="npsz_email" maxlength="190" autocomplete="off" inputmode="email"
                       value="<?php echo esc_attr($sz ? $sz['email'] : ''); ?>"
                       placeholder="<?php echo esc_attr($fiokos ? 'üresen a fiók e-mail-címe' : 'üresen a közös értesítő cím'); ?>">
            </div>

            <div class="sdh-ig">
                <span class="sdh-ig__cimke">Szín</span>
                <?php self::pillek('szin', $szinek, [$sz ? $sz['szin'] : 'kek']); ?>
            </div>

            <div class="sdh-ig">
                <label for="npsz_sorrend">Sorrend</label>
                <input type="number" name="sorrend" id="npsz_sorrend" min="0" max="999" step="1" value="<?php echo (int) ($sz ? $sz['sorrend'] : 0); ?>">
            </div>

            <?php if ($sz !== null && !$fiokos) : ?>
                <div class="sdh-ig">
                    <span class="sdh-ig__cimke">Állapot</span>
                    <label class="sdh-np-jelolo">
                        <input type="checkbox" name="aktiv" value="1"<?php checked($sz['aktiv']); ?>>
                        Aktív – kivéve a naptár eltűnik a listából, az eseményei megmaradnak
                    </label>
                </div>
            <?php else : ?>
                <input type="hidden" name="aktiv" value="1">
            <?php endif; ?>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges"><?php echo $sz ? 'Mentés' : 'Felveszem'; ?></button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            </div>
        </form>
        <?php
        wp_die();
    }

    /**
     * Név felvétele / módosítása. Visszaadja az azonosítót, hibánál a hibaüzenetet.
     *
     * @param array<string, mixed> $be
     * @return int|string
     */
    public static function szemely_ment(int $id, array $be)
    {
        global $wpdb;

        $regi   = $id > 0 ? (self::szemelyek(true)[$id] ?? null) : null;
        $fiokos = $regi !== null && $regi['user_id'] > 0 && !self::egyszeru();

        if ($id > 0 && $regi === null) {
            return 'Ez a név már nincs meg.';
        }

        $nev   = isset($be['nev']) && is_scalar($be['nev']) ? mb_substr(trim(sanitize_text_field((string) $be['nev'])), 0, 80) : '';
        $email = isset($be['email']) && is_scalar($be['email']) ? trim((string) $be['email']) : '';
        $szin  = isset($be['szin']) && is_scalar($be['szin']) ? sanitize_key((string) $be['szin']) : 'kek';

        if ($fiokos) {
            $nev = $regi['nev'];
        }

        if ($nev === '') {
            return 'Add meg a nevet.';
        }

        if ($email !== '' && !is_email($email)) {
            return 'Ez nem érvényes e-mail-cím.';
        }

        foreach (self::szemelyek(true) as $masik) {
            if ($masik['id'] !== $id && mb_strtolower($masik['nev']) === mb_strtolower($nev)) {
                return 'Ilyen nevű naptár már van.';
            }
        }

        $adat = [
            'nev'     => $nev,
            'email'   => $email,
            'szin'    => isset(self::SZINEK[$szin]) ? $szin : 'kek',
            'sorrend' => max(0, min(999, (int) ($be['sorrend'] ?? 0))),
            'aktiv'   => $fiokos ? ($regi['aktiv'] ? 1 : 0) : (!empty($be['aktiv']) || $id === 0 ? 1 : 0),
        ];

        if ($id > 0) {
            $wpdb->update(self::t('szemely'), $adat, ['id' => $id]);
        } else {
            // Az új név a lista végére kerül, ha nem kapott külön sorrendet.
            if ($adat['sorrend'] === 0) {
                $adat['sorrend'] = array_reduce(self::szemelyek(true), static fn (int $max, array $sz): int => max($max, $sz['sorrend']), 0) + 1;
            }

            $wpdb->insert(self::t('szemely'), $adat + ['user_id' => 0, 'letrehozva' => self::most()]);
            $id = (int) $wpdb->insert_id;
        }

        self::szemelyek_frissit();

        return $id > 0 ? $id : 'A mentés nem sikerült.';
    }

    public static function ajax_szemely_ment(): void
    {
        self::jog();

        if (!self::szemely_szerkesztheto()) {
            wp_send_json_error(['uzenet' => 'A neveket az adminisztrátor szerkesztheti.'], 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a jog() ellenőrizte.
        $eredmeny = self::szemely_ment((int) self::g('id', 20), wp_unslash($_POST));

        if (is_string($eredmeny)) {
            wp_send_json_error(['uzenet' => $eredmeny]);
        }

        $kontextus = self::g('kontextus', 10) === 'admin' ? 'admin' : 'frontend';

        wp_send_json_success(['id' => $eredmeny, 'vissza' => SDH_Muhely_Modulok::visszateres(self::KULCS, [], $kontextus)]);
    }

    /* =================================================================
     * Jelzések: ami esedékes lett
     * ============================================================== */

    /**
     * Létrehozza az esedékessé vált jelzéseket (kártya és e-mail). A $most
     * helyi idő; a tesztek tetszőleges időpontot adhatnak.
     *
     * Kártya: minden emlékeztetőnél ÉS a kezdéskor esedékes; egy alkalomról
     * mindig csak a legutóbbi kártya él (a korábbi magától kinyomódik).
     * E-mail: alkalmanként egy, az első emlékeztető idején (ennek híján a kezdéskor).
     */
    public static function jelzesek_general(?string $most = null): int
    {
        global $wpdb;

        $most = $most ?? self::most();
        $t    = self::t('esemeny');
        $j    = self::t('jelzes');
        $dt   = self::dt($most);
        $tol  = $dt->modify('-' . self::JELZES_VISSZA_NAP . ' days')->format('Y-m-d H:i:s');
        $ig   = $dt->modify('+' . self::JELZES_ELORE_NAP . ' days')->format('Y-m-d H:i:s');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $esemenyek = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE torolve = 0 AND allapot NOT IN ('kesz', 'elmaradt')
               AND (ertesites = 1 OR email = 1 OR prioritas = 'kritikus')
               AND ((ismetles = '' AND kezdet < %s AND veg >= %s) OR (ismetles <> '' AND kezdet < %s AND (ism_veg IS NULL OR ism_veg >= %s)))",
            $ig,
            $tol,
            $ig,
            substr($tol, 0, 10)
        ));
        // phpcs:enable

        if (!is_array($esemenyek) || $esemenyek === []) {
            return 0;
        }

        $idk  = array_map(static fn (object $e): int => (int) $e->id, $esemenyek);
        $van  = [];
        $hely = implode(',', array_fill(0, count($idk), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT esemeny_id, szemely_id, alkalom, tipus, perc FROM {$j} WHERE esemeny_id IN ({$hely})", $idk)) as $sor) {
            $van[(int) $sor->esemeny_id . '|' . (int) $sor->szemely_id . '|' . (string) $sor->alkalom . '|' . (string) $sor->tipus . '|' . (int) $sor->perc] = true;
        }

        $szemelyek = self::szemelyek(true);
        $email_be  = self::beallitas()['email_be'];
        $uj        = 0;

        foreach ($esemenyek as $e) {
            $percek = array_map('intval', array_filter(explode(',', (string) $e->emlekeztetok), 'strlen'));
            // „Ha aktuális": a kezdéskor mindig szól.
            $app_percek = array_values(array_unique(array_merge($percek, [0])));
            rsort($app_percek);
            $email_perc = $percek !== [] ? max($percek) : 0;

            foreach (self::alkalmak($e, $tol, $ig, 40) as [$k, $v]) {
                // Utólag (a vége után) felvett vagy átírt eseményről már nem szólunk.
                if ($v < (string) $e->modositva) {
                    continue;
                }

                $kezdet = self::dt($k);

                foreach (self::erintettek($e) as $szemely) {
                    if (!isset($szemelyek[$szemely])) {
                        continue;
                    }

                    if ((int) $e->ertesites === 1) {
                        // A legutóbb esedékessé vált emlékeztető (a legkisebb, már elmúlt előidő).
                        $esedekes_perc = null;

                        foreach ($app_percek as $perc) {
                            if ($kezdet->modify('-' . $perc . ' minutes')->format('Y-m-d H:i:s') <= $most) {
                                $esedekes_perc = $perc;
                            }
                        }

                        if ($esedekes_perc !== null) {
                            $kulcs = (int) $e->id . '|' . $szemely . '|' . $k . '|app|' . $esedekes_perc;

                            if (!isset($van[$kulcs])) {
                                // Erről az alkalomról a korábbi (még nyitott) kártya helyére lép.
                                $wpdb->query($wpdb->prepare(
                                    "UPDATE {$j} SET kinyomva = %s WHERE esemeny_id = %d AND szemely_id = %d AND alkalom = %s AND tipus = 'app' AND kinyomva IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                                    $most,
                                    (int) $e->id,
                                    $szemely,
                                    $k
                                ));
                                $wpdb->insert($j, [
                                    'esemeny_id' => (int) $e->id,
                                    'szemely_id' => $szemely,
                                    'alkalom'    => $k,
                                    'tipus'      => 'app',
                                    'perc'       => $esedekes_perc,
                                    'esedekes'   => $kezdet->modify('-' . $esedekes_perc . ' minutes')->format('Y-m-d H:i:s'),
                                ]);
                                $van[$kulcs] = true;
                                $uj++;
                            }
                        }
                    }

                    if ($email_be && self::emailes($e)) {
                        $esedekes = $kezdet->modify('-' . $email_perc . ' minutes')->format('Y-m-d H:i:s');
                        $kulcs    = (int) $e->id . '|' . $szemely . '|' . $k . '|email|' . $email_perc;

                        // Csak a friss esedékesség megy ki: régi (egy napnál korábbi) eseményről nem küldünk utólag.
                        if (!isset($van[$kulcs]) && $esedekes <= $most && $esedekes > $dt->modify('-1 day')->format('Y-m-d H:i:s')) {
                            $wpdb->insert($j, [
                                'esemeny_id' => (int) $e->id,
                                'szemely_id' => $szemely,
                                'alkalom'    => $k,
                                'tipus'      => 'email',
                                'perc'       => $email_perc,
                                'esedekes'   => $esedekes,
                            ]);
                            $van[$kulcs] = true;
                            $uj++;
                        }
                    }
                }
            }
        }

        return $uj;
    }

    /**
     * A ki nem nyomott kártyák a megadott fióknak (közös fióknál mindenkié).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function nyitott_jelzesek(?int $user_id = null, int $limit = 30): array
    {
        global $wpdb;

        $j = self::t('jelzes');
        $t = self::t('esemeny');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sorok = $wpdb->get_results(
            "SELECT j.id AS jelzes_id, j.szemely_id AS j_szemely, j.alkalom, j.perc, j.esedekes, e.*
             FROM {$j} j INNER JOIN {$t} e ON e.id = j.esemeny_id
             WHERE j.tipus = 'app' AND j.kinyomva IS NULL AND e.torolve = 0
             ORDER BY j.alkalom ASC, j.id ASC"
        );
        // phpcs:enable

        $szemelyek = self::szemelyek(true);
        $sajat     = self::sajat_szemely_id($user_id);
        $egyszeru  = self::egyszeru();
        $ki        = [];

        foreach ((array) $sorok as $s) {
            $szemely = (int) $s->j_szemely;

            // Többfelhasználós módban mindenki a saját naptárának kártyáit látja.
            if (!$egyszeru && $szemely !== $sajat) {
                continue;
            }

            if (count($ki) >= $limit) {
                break;
            }

            $kezdet = self::dt((string) $s->alkalom);
            $hossz  = self::dt((string) $s->kezdet)->diff(self::dt((string) $s->veg));

            $ki[] = [
                'id'      => (int) $s->jelzes_id,
                'esemeny' => (int) $s->id,
                'cim'     => (string) $s->cim,
                'hely'    => (string) $s->hely,
                'k'       => $kezdet->format('Y-m-d\TH:i'),
                'v'       => $kezdet->add($hossz)->format('Y-m-d\TH:i'),
                'en'      => (int) $s->egesz_nap === 1,
                'mikor'   => self::mikor_szoveg($kezdet, $kezdet->add($hossz), (int) $s->egesz_nap === 1),
                'pri'     => (string) $s->prioritas,
                'szemely' => $szemelyek[$szemely]['nev'] ?? '',
                'szin'    => $szemelyek[$szemely]['szin'] ?? 'kek',
                'perc'    => (int) $s->perc,
            ];
        }

        return $ki;
    }

    /** A menü jelvénye és a csempe: hány kártya vár kinyomásra. */
    public static function nyitott_jelzes_db(): int
    {
        global $wpdb;

        static $van_tabla = null;

        // Frissítés után, amíg a séma nem futott le (első admin-betöltés), a menü ne dobjon hibát.
        if ($van_tabla === null) {
            $van_tabla = get_option('sdh_muhely_db_version') !== false
                && version_compare((string) get_option('sdh_muhely_db_version'), '0.35.0', '>=');
        }

        return $van_tabla ? count(self::nyitott_jelzesek(null, 999)) : 0;
    }

    /** A mai (még le nem zárt) események száma – az Áttekintés csempéje. */
    public static function mai_db(): int
    {
        if (get_option('sdh_muhely_db_version') === false || version_compare((string) get_option('sdh_muhely_db_version'), '0.35.0', '<')) {
            return 0;
        }

        $ma     = substr(self::most(), 0, 10);
        $holnap = self::dt($ma)->modify('+1 day')->format('Y-m-d');
        $sajat  = self::sajat_szemely_id();
        $db     = 0;

        foreach (self::esemenyek($ma . ' 00:00:00', $holnap . ' 00:00:00', $sajat > 0 ? [$sajat] : []) as $e) {
            $db += count(self::alkalmak($e, $ma . ' 00:00:00', $holnap . ' 00:00:00'));
        }

        return $db;
    }

    /** Kártya kinyomása: egy (id) vagy az összes (mind = 1), csak a sajátjai közül. */
    public static function ajax_kinyom(): void
    {
        self::jog();

        global $wpdb;

        $sajat = array_map(static fn (array $j): int => (int) $j['id'], self::nyitott_jelzesek(null, 999));
        $id    = (int) self::g('id', 20);
        $celok = self::g('mind', 1) === '1' ? $sajat : array_values(array_intersect($sajat, [$id]));

        foreach ($celok as $cel) {
            $wpdb->update(self::t('jelzes'), ['kinyomva' => self::most()], ['id' => $cel]);
        }

        wp_send_json_success(['jelzesek' => self::nyitott_jelzesek(), 'db' => self::nyitott_jelzes_db()]);
    }

    /** A pulzus válaszába: a nyitott kártyák (naptar.js rajzolja minden oldalon). */
    public static function pulzus(array $valasz, int $user_id = 0): array
    {
        if (SDH_Muhely_Modulok::ki(self::KULCS)) {
            return $valasz;
        }

        if (get_option('sdh_muhely_db_version') === false || version_compare((string) get_option('sdh_muhely_db_version'), '0.35.0', '<')) {
            return $valasz;
        }

        self::esedekes_feldolgoz();

        $jelzesek = self::nyitott_jelzesek($user_id > 0 ? $user_id : null);

        $valasz['naptar'] = [
            'jelzesek' => $jelzesek,
            'db'       => count($jelzesek),
            'most'     => str_replace(' ', 'T', self::most()),
            'url'      => SDH_Muhely_Modulok::url(self::KULCS),
        ];

        return $valasz;
    }

    /* =================================================================
     * Időzítés és e-mail
     * ============================================================== */

    /** @param array<string, array<string, mixed>> $utemek */
    public static function cron_utem(array $utemek): array
    {
        $utemek['sdh_ot_perc'] = ['interval' => 300, 'display' => 'Öt percenként'];

        return $utemek;
    }

    public static function cron_beallit(): void
    {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 60, 'sdh_ot_perc', self::CRON);
        }
    }

    public static function idozitett(): void
    {
        // Kikapcsolt Naptár modul (Modulok): nincs emlékeztető, e-mail, napi összefoglaló.
        if (SDH_Muhely_Modulok::ki(self::KULCS)) {
            return;
        }

        if (get_option('sdh_muhely_db_version') !== false && version_compare((string) get_option('sdh_muhely_db_version'), '0.35.0', '>=')) {
            self::esedekes_feldolgoz(true);
        }
    }

    /**
     * Jelzések létrehozása + a levelek kiküldése. A nyitott felületek 15
     * másodpercenként kérdeznek: húsz másodpercen belül nem futunk újra.
     */
    public static function esedekes_feldolgoz(bool $mindenkepp = false): void
    {
        $utoljara = (int) get_option('sdh_muhely_naptar_futott', 0);

        if (!$mindenkepp && time() - $utoljara < 20) {
            return;
        }

        update_option('sdh_muhely_naptar_futott', time(), false);

        self::jelzesek_general();
        self::levelek_kuld();
        self::napi_osszefoglalo();
    }

    /** „2026. október 12., hétfő 14:00–15:00" / „… egész nap". */
    private static function mikor_szoveg(DateTimeImmutable $k, DateTimeImmutable $v, bool $egesz): string
    {
        $nap = static fn (DateTimeImmutable $d): string => $d->format('Y') . '. ' . self::HONAPOK[(int) $d->format('n')] . ' ' . (int) $d->format('j') . '., ' . self::NAPOK[(int) $d->format('N')];

        $egy_nap = $k->format('Y-m-d') === $v->format('Y-m-d');

        if ($egesz) {
            return $egy_nap ? $nap($k) . ', egész nap' : $nap($k) . ' – ' . $nap($v);
        }

        return $egy_nap
            ? $nap($k) . ' ' . $k->format('H:i') . '–' . $v->format('H:i')
            : $nap($k) . ' ' . $k->format('H:i') . ' – ' . $nap($v) . ' ' . $v->format('H:i');
    }

    /** A levél tárgyának előtagja: a rendszer neve (Beállítások › Logó és megjelenés). */
    private static function targy_elotag(): string
    {
        return '[' . (class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::rendszer_nev() : 'Naptár') . '] ';
    }

    /** Az esedékes, még ki nem ment e-mail értesítők elküldése. Visszaadja, hány levél ment ki. */
    public static function levelek_kuld(?string $most = null): int
    {
        global $wpdb;

        $most = $most ?? self::most();
        $j    = self::t('jelzes');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sorok = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$j} WHERE tipus = 'email' AND kuldve IS NULL AND esedekes <= %s ORDER BY id ASC LIMIT 40", $most));

        if (!is_array($sorok) || $sorok === []) {
            return 0;
        }

        $szemelyek = self::szemelyek(true);
        $be        = self::beallitas()['email_be'];
        $kuldve    = 0;

        foreach ($sorok as $s) {
            // Előbb jelöljük, aztán küldünk: két egyszerre futó kérés ne küldje el kétszer.
            $jelolve = $wpdb->query($wpdb->prepare("UPDATE {$j} SET kuldve = %s WHERE id = %d AND kuldve IS NULL", $most, (int) $s->id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            if (!$jelolve || !$be) {
                continue;
            }

            $e  = self::esemeny((int) $s->esemeny_id);
            $sz = $szemelyek[(int) $s->szemely_id] ?? null;

            if ($e === null || $sz === null || in_array((string) $e->allapot, ['kesz', 'elmaradt'], true)) {
                continue;
            }

            $cim = self::szemely_email($sz);

            if (!is_email($cim)) {
                continue;
            }

            $kezdet = self::dt((string) $s->alkalom);
            $veg    = $kezdet->add(self::dt((string) $e->kezdet)->diff(self::dt((string) $e->veg)));

            if (self::level_esemenyrol($cim, $sz, $e, $kezdet, $veg, $szemelyek)) {
                $kuldve++;
            }
        }

        return $kuldve;
    }

    private static function level_esemenyrol(string $cim, array $sz, object $e, DateTimeImmutable $kezdet, DateTimeImmutable $veg, array $szemelyek): bool
    {
        $egesz = (int) $e->egesz_nap === 1;
        $b     = self::beallitas();
        $sorok = [
            'Szia ' . $sz['nev'] . '!',
            '',
            (string) $e->cim,
            'Mikor: ' . self::mikor_szoveg($kezdet, $veg, $egesz),
        ];

        if ((string) $e->hely !== '') {
            $sorok[] = 'Hol: ' . $e->hely;
        }

        $sorok[] = 'Naptár: ' . ($szemelyek[(int) $e->szemely_id]['nev'] ?? '');

        $resztvevok = [];

        foreach (self::resztvevo_idk($e) as $r) {
            if (isset($szemelyek[$r])) {
                $resztvevok[] = $szemelyek[$r]['nev'];
            }
        }

        if ($resztvevok !== []) {
            $sorok[] = 'Résztvevők: ' . implode(', ', $resztvevok);
        }

        if (isset($b['kategoriak'][(string) $e->kategoria])) {
            $sorok[] = 'Kategória: ' . $b['kategoriak'][(string) $e->kategoria]['nev'];
        }

        if ((string) $e->prioritas !== 'normal') {
            $sorok[] = 'Fontosság: ' . (self::PRIORITASOK[(string) $e->prioritas] ?? '');
        }

        if ((string) $e->munkalap_szam !== '') {
            $sorok[] = 'Munkalap: ' . $e->munkalap_szam;
        }

        if (trim((string) $e->leiras) !== '') {
            $sorok[] = '';
            $sorok[] = trim((string) $e->leiras);
        }

        if ((string) $e->link !== '') {
            $sorok[] = '';
            $sorok[] = 'Hivatkozás: ' . $e->link;
        }

        $sorok[] = '';
        $sorok[] = 'Megnyitás a naptárban: ' . SDH_Muhely_Modulok::frontend_url(self::KULCS, ['datum' => $kezdet->format('Y-m-d'), 'esemeny' => (int) $e->id]);

        $mikor_rovid = self::HONAPOK[(int) $kezdet->format('n')] . ' ' . (int) $kezdet->format('j') . '.' . ($egesz ? '' : ' ' . $kezdet->format('H:i'));
        $jel         = (string) $e->prioritas === 'kritikus' ? 'NAGYON FONTOS: ' : ((string) $e->prioritas === 'fontos' ? 'Fontos: ' : 'Emlékeztető: ');

        return (bool) wp_mail($cim, self::targy_elotag() . $jel . $e->cim . ' – ' . $mikor_rovid, implode("\n", $sorok));
    }

    /**
     * Napi összefoglaló levél: a nap eseményei és a nagyon fontos levelek.
     * Naponta egyszer megy, a beállított idő után. Visszaadja, hány levél ment ki.
     */
    public static function napi_osszefoglalo(?string $most = null): int
    {
        $b = self::beallitas();

        if (!$b['napi_be'] || !$b['email_be']) {
            return 0;
        }

        $most = $most ?? self::most();
        $ma   = substr($most, 0, 10);

        if (substr($most, 11, 5) < $b['napi_ora'] || get_option('sdh_muhely_naptar_napi') === $ma) {
            return 0;
        }

        update_option('sdh_muhely_naptar_napi', $ma, false);

        $holnap    = self::dt($ma)->modify('+1 day')->format('Y-m-d');
        $szemelyek = self::szemelyek();
        $napi      = [];   // személy => sorok

        foreach (self::esemenyek($ma . ' 00:00:00', $holnap . ' 00:00:00') as $e) {
            if ((string) $e->allapot === 'elmaradt') {
                continue;
            }

            foreach (self::alkalmak($e, $ma . ' 00:00:00', $holnap . ' 00:00:00') as [$k, $v]) {
                $sor = ((int) $e->egesz_nap === 1 ? 'egész nap' : substr($k, 11, 5) . '–' . substr($v, 11, 5)) . '  ' . $e->cim
                    . ((string) $e->hely !== '' ? ' (' . $e->hely . ')' : '')
                    . ((string) $e->prioritas === 'kritikus' ? '  [NAGYON FONTOS]' : ((string) $e->prioritas === 'fontos' ? '  [fontos]' : ''));

                foreach (self::erintettek($e) as $szemely) {
                    // Más privát eseménye a közös összefoglalóba nem kerül bele részletesen.
                    $napi[$szemely][] = [$k, (int) $e->privat === 1 ? ((int) $e->egesz_nap === 1 ? 'egész nap' : substr($k, 11, 5) . '–' . substr($v, 11, 5)) . '  (privát esemény)' : $sor, $sor];
                }
            }
        }

        $levelek   = self::fontos_levelek(10, true);
        $level_blokk = [];

        if ($levelek !== []) {
            $level_blokk[] = '';
            $level_blokk[] = 'NAGYON FONTOS LEVELEK (' . count($levelek) . ')';

            foreach ($levelek as $l) {
                $level_blokk[] = '- ' . $l['targy'] . ' – ' . $l['felado'] . ($l['ok'] !== '' ? ' (' . $l['ok'] . ')' : '');
            }

            $level_blokk[] = 'Levelezés: ' . SDH_Muhely_Modulok::frontend_url('levelezes');
        }

        $dt    = self::dt($ma);
        $datum = $dt->format('Y') . '. ' . self::HONAPOK[(int) $dt->format('n')] . ' ' . (int) $dt->format('j') . '., ' . self::NAPOK[(int) $dt->format('N')];
        $targy = self::targy_elotag() . 'Napi összefoglaló – ' . $datum;
        $lab   = ['', 'Naptár: ' . SDH_Muhely_Modulok::frontend_url(self::KULCS, ['datum' => $ma])];
        $blokk = static function (array $kinek, bool $sajat) use ($napi, $szemelyek): array {
            $ki = [];

            foreach ($kinek as $szemely) {
                if (!isset($szemelyek[$szemely])) {
                    continue;
                }

                $sorok = $napi[$szemely] ?? [];
                usort($sorok, static fn (array $a, array $c): int => $a[0] <=> $c[0]);

                $ki[] = '';
                $ki[] = mb_strtoupper($szemelyek[$szemely]['nev']) . ' (' . count($sorok) . ')';

                foreach ($sorok as $s) {
                    $ki[] = '- ' . ($sajat ? $s[2] : $s[1]);
                }

                if ($sorok === []) {
                    $ki[] = '- ma nincs esemény';
                }
            }

            return $ki;
        };

        $kuldve = 0;

        if ($b['napi_cimzettek'] !== []) {
            // A megadott címek mindenki naptárát megkapják.
            $torzs = array_merge([$datum], $blokk(array_keys($szemelyek), false), $level_blokk, $lab);

            foreach ($b['napi_cimzettek'] as $cim) {
                $kuldve += wp_mail($cim, $targy, implode("\n", $torzs)) ? 1 : 0;
            }

            return $kuldve;
        }

        // Mindenki a sajátját kapja; az azonos címre menő naptárak (közös cím) egy levélbe kerülnek.
        $cimenkent = [];

        foreach ($szemelyek as $sz) {
            $cim = self::szemely_email($sz);

            if (is_email($cim)) {
                $cimenkent[strtolower($cim)][] = $sz['id'];
            }
        }

        foreach ($cimenkent as $cim => $kinek) {
            $torzs   = array_merge([$datum], $blokk($kinek, true), $level_blokk, $lab);
            $kuldve += wp_mail($cim, $targy, implode("\n", $torzs)) ? 1 : 0;
        }

        return $kuldve;
    }
}
