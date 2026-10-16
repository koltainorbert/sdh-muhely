<?php
/**
 * Elégedettségi kérdés (F7, 0.89.0).
 *
 * A lezárt javítás után (a beállított nap elteltével, a beállított órától) az ügyfél SMS-ben vagy
 * e-mailben egy linket kap: `/?sdh_ertekeles=<az ügyféloldal titkos azonosítója>`. Ott 1–5 csillaggal
 * értékel és megjegyzést írhat. Ha az értékelés eléri a küszöböt (alapból 4), az oldal a cég
 * Google-értékelő linkjét kínálja; ha alatta marad, a szerviz e-mailt kap a megjegyzéssel, hogy
 * személyesen rendezhesse.
 *
 * Óránként fut (WordPress időzítő); egy lap egyszer kap kérdést (`elegedettseg_kuldve`). Ha nincs
 * se használható szám, se e-mail-cím, a lap „nincs” csatornával megjelölve kimarad. A munkalapon az
 * „Elégedettség” csoport mutatja az állapotot, és kézzel is elküldhető. A riport (F5) átlagolja.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Elegedettseg
{
    public const OPT  = 'sdh_muhely_elegedettseg';
    public const QV   = 'sdh_ertekeles';
    public const CRON = 'sdh_muhely_elegedettseg_cron';

    public const CSATORNAK = [
        'sms_email' => 'SMS, ha nincs szám: e-mail',
        'email'     => 'Csak e-mail',
        'sms'       => 'Csak SMS',
    ];

    /** Egy futás legfeljebb ennyi lapnak küld. */
    private const ADAG = 30;

    /** A lezárás után legfeljebb ennyi nappal később még kimegy (bekapcsoláskor a régi lapok nem kapnak). */
    private const POTLAS_NAP = 3;

    public static function init(): void
    {
        add_action('init', [self::class, 'cron_beallit']);
        add_action(self::CRON, [self::class, 'esedekes_kuld']);
        add_action('template_redirect', [self::class, 'fogadas'], 0);
        add_action('wp_ajax_sdh_muhely_elegedettseg_kuld', [self::class, 'ajax_kuld']);
    }

    private static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('munkalap');
    }

    public static function cron_beallit(): void
    {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON);
        }
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /** @return array<string, string> */
    public static function alap_szovegek(): array
    {
        return [
            'sms'         => '{szerviz}: Köszönjük, hogy minket választott! Mennyire volt elégedett? Értékeljen itt: {link}',
            'email_targy' => '{szerviz} – mennyire volt elégedett?',
            'email'       => "Kedves {nev}!\n\nKöszönjük, hogy minket választott (munkalap: {munkalap}).\nMennyire volt elégedett a javítással? Egy kattintással értékelhet:\n\n{link}\n\nKöszönjük a visszajelzését!\n{szerviz}",
        ];
    }

    /** @return array<string, mixed> */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];
        $a = self::alap_szovegek();
        $c = (string) ($m['csatorna'] ?? 'sms_email');
        $s = static fn (string $k): string => isset($m[$k]) && is_string($m[$k]) && trim($m[$k]) !== '' ? (string) $m[$k] : $a[$k];

        return [
            'be'          => !empty($m['be']),
            'csatorna'    => isset(self::CSATORNAK[$c]) ? $c : 'sms_email',
            'nap'         => max(0, min(14, (int) ($m['nap'] ?? 1))),
            'ora'         => max(0, min(22, (int) ($m['ora'] ?? 10))),
            'kuszob'      => in_array((int) ($m['kuszob'] ?? 4), [3, 4, 5], true) ? (int) ($m['kuszob'] ?? 4) : 4,
            'google'      => esc_url_raw((string) ($m['google'] ?? '')),
            'sms'         => $s('sms'),
            'email_targy' => $s('email_targy'),
            'email'       => $s('email'),
        ];
    }

    /* =================================================================
     * Küldés
     * ============================================================== */

    public static function link(object $lap): string
    {
        return add_query_arg(self::QV, SDH_Muhely_Rma::token($lap), home_url('/'));
    }

    /** A helyőrzők kitöltése: {nev}, {szerviz}, {munkalap}, {link}. */
    public static function kitolt(string $sablon, object $lap, ?object $ugyfel): string
    {
        return strtr($sablon, [
            '{nev}'      => $ugyfel ? (string) $ugyfel->nev : '',
            '{szerviz}'  => (string) SDH_Muhely_Rma::beallitas()['szerviz_nev'],
            '{munkalap}' => SDH_Muhely_Munkalap::szam_formaz($lap->munkalap_szam),
            '{link}'     => self::link($lap),
        ]);
    }

    /**
     * Az esedékes lapok: lezárt javítás, a lezárás óta legalább `nap` nap (de legfeljebb + 3), még nem kérdeztük.
     *
     * @return array<int, object>
     */
    public static function esedekesek(?string $most = null): array
    {
        global $wpdb;

        $b = self::beallitas();

        if (!$b['be'] || SDH_Muhely_Modulok::ki(SDH_Muhely_Rma::KULCS)) {
            return [];
        }

        $m = new DateTimeImmutable($most ?? current_time('mysql'), wp_timezone());

        if ((int) $m->format('G') < $b['ora']) {
            return [];
        }

        $ig  = $m->modify('-' . $b['nap'] . ' days')->format('Y-m-d');
        $tol = $m->modify('-' . ($b['nap'] + self::POTLAS_NAP) . ' days')->format('Y-m-d');

        $sorok = $wpdb->get_results($wpdb->prepare(
            'SELECT m.* FROM ' . self::tabla() . ' m LEFT JOIN ' . SDH_Muhely_Schema::tabla('ugyfel') . " u ON u.id = m.ugyfel_id
             WHERE m.fajta = 'javitas' AND m.allapot = %s AND m.munkalap_szam > 0 AND m.ugyfel_id > 0
               AND m.lezarva >= %s AND m.lezarva <= %s AND m.elegedettseg_kuldve IS NULL
               AND (u.anonimizalva IS NULL)
             ORDER BY m.lezarva ASC, m.id ASC LIMIT %d",
            SDH_Muhely_Munkalap::lezart_kulcs(),
            $tol,
            $ig,
            self::ADAG
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_array($sorok) ? $sorok : [];
    }

    /** Az óránkénti futás: az esedékes lapoknak kiküldi a kérdést. Visszaadja, hánynak ment ki. */
    public static function esedekes_kuld(?string $most = null): int
    {
        // H12: net nélkül a kör kimarad (különben a lapok „nincs” csatornával kiesnének); a következő órában pótol.
        if (class_exists('SDH_Muhely_Halozat') && !SDH_Muhely_Halozat::online()) {
            return 0;
        }

        $db = 0;

        foreach (self::esedekesek($most) as $lap) {
            $db += in_array(self::kuld($lap), ['sms', 'email'], true) ? 1 : 0;
        }

        return $db;
    }

    /**
     * A kérdés kiküldése egy lapnak. A csatorna: 'sms', 'email' vagy '' (nem ment ki – ezt is megjegyezzük,
     * hogy a lap ne kerüljön újra sorra).
     */
    public static function kuld(object $lap): string
    {
        global $wpdb;

        $b      = self::beallitas();
        $ugyfel = SDH_Muhely_Rma::ugyfel((int) $lap->ugyfel_id);
        $mi     = '';

        if ($ugyfel !== null && empty($ugyfel->anonimizalva)) {
            if ($b['csatorna'] !== 'email' && class_exists('SDH_Muhely_Sms') && SDH_Muhely_Sms::bekapcsolva()) {
                $r = SDH_Muhely_Sms::sor_be([
                    'ugyfel_id'   => (int) $ugyfel->id,
                    'munkalap_id' => (int) $lap->id,
                    'szoveg'      => self::kitolt($b['sms'], $lap, $ugyfel),
                    'kivalto'     => 'auto',
                    'kulcs'       => 'elegedettseg-' . (int) $lap->id,
                ]);

                if (!empty($r['ok']) && !in_array((string) ($r['allapot'] ?? ''), ['letiltva', 'hibas_szam', 'nincs_szam'], true)) {
                    $mi = 'sms';
                }
            }

            if ($mi === '' && $b['csatorna'] !== 'sms' && is_email((string) $ugyfel->email)) {
                $rma    = SDH_Muhely_Rma::beallitas();
                $valasz = sanitize_email((string) $rma['ertesites_email']);
                $fejlec = $valasz !== '' && is_email($valasz) ? ['Reply-To: ' . str_replace(['"', "\r", "\n"], '', (string) $rma['szerviz_nev']) . ' <' . $valasz . '>'] : [];

                if (wp_mail((string) $ugyfel->email, self::kitolt($b['email_targy'], $lap, $ugyfel), self::kitolt($b['email'], $lap, $ugyfel), $fejlec)) {
                    $mi = 'email';
                }
            }
        }

        $wpdb->update(self::tabla(), [
            'elegedettseg_kuldve'   => current_time('mysql'),
            'elegedettseg_csatorna' => $mi !== '' ? $mi : 'nincs',
        ], ['id' => (int) $lap->id]);

        if ($mi !== '' && class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('munkalap', (int) $lap->id, 'Elégedettségi kérdés elküldve (' . ($mi === 'sms' ? 'SMS' : 'e-mail') . ').');
        }

        return $mi;
    }

    /* =================================================================
     * Értékelés
     * ============================================================== */

    public static function lap_tokennel(string $token): ?object
    {
        global $wpdb;

        $token = preg_replace('/[^A-Za-z0-9]/', '', $token) ?? '';

        if (strlen($token) < 8) {
            return null;
        }

        $l = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE rma_token = %s AND munkalap_szam > 0 LIMIT 1', $token));

        return $l ?: null;
    }

    /**
     * Az ügyfél értékelésének mentése. Hibánál a hibaüzenet, különben ''.
     */
    public static function ertekel(object $lap, int $csillag, string $szoveg): string
    {
        global $wpdb;

        if ($csillag < 1 || $csillag > 5) {
            return 'Válasszon 1 és 5 csillag között.';
        }

        $szoveg = mb_substr(trim(sanitize_textarea_field($szoveg)), 0, 1000);

        $wpdb->update(self::tabla(), [
            'elegedettseg'        => $csillag,
            'elegedettseg_szoveg' => $szoveg,
            'elegedettseg_ido'    => current_time('mysql'),
        ], ['id' => (int) $lap->id]);

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('munkalap', (int) $lap->id, 'Ügyfél-értékelés: ' . $csillag . '/5' . ($szoveg !== '' ? ' – „' . $szoveg . '”' : ''));
        }

        if ($csillag < self::beallitas()['kuszob']) {
            self::level_szerviznek($lap, $csillag, $szoveg);
        }

        return '';
    }

    private static function level_szerviznek(object $lap, int $csillag, string $szoveg): bool
    {
        $rma = SDH_Muhely_Rma::beallitas();
        $cel = sanitize_email((string) $rma['ertesites_email']);

        if ($cel === '' || !is_email($cel)) {
            $cel = (string) get_option('admin_email');
        }

        if (!is_email($cel)) {
            return false;
        }

        $ugyfel   = SDH_Muhely_Rma::ugyfel((int) $lap->ugyfel_id);
        $szam     = SDH_Muhely_Munkalap::szam_formaz($lap->munkalap_szam);
        $rendszer = class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::rendszer_nev() : 'Műhely';
        $torzs    = 'Alacsony értékelés érkezett: ' . $csillag . "/5\n\n"
            . 'Munkalap: ' . $szam . "\n"
            . 'Ügyfél: ' . ($ugyfel ? (string) $ugyfel->nev . ((string) $ugyfel->telefon !== '' ? ' (' . $ugyfel->telefon . ')' : '') : '') . "\n\n"
            . ($szoveg !== '' ? "Megjegyzés:\n" . $szoveg . "\n\n" : '')
            . 'Érdemes felhívni és rendezni.' . "\n";

        return (bool) wp_mail($cel, '[' . $rendszer . '] Alacsony értékelés (' . $csillag . '/5) – munkalap ' . $szam, $torzs);
    }

    /* =================================================================
     * Nyilvános oldal
     * ============================================================== */

    public static function fogadas(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification
        if (!isset($_GET[self::QV])) {
            return;
        }

        SDH_Muhely_Schema::frissites_ha_kell();
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        header('X-Frame-Options: SAMEORIGIN', true);
        SDH_Muhely_Rma_Oldal::nyelv_kenyszerit('hu');

        $token = sanitize_text_field(wp_unslash($_GET[self::QV]));
        $lap   = SDH_Muhely_Modulok::ki(SDH_Muhely_Rma::KULCS) ? null : self::lap_tokennel($token);

        if ($lap === null) {
            status_header(404);
            self::ablak('Értékelés', 'info', 'Ez a link nem érvényes.');
            exit;
        }

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $hiba = self::ertekel($lap, isset($_POST['csillag']) ? (int) $_POST['csillag'] : 0, isset($_POST['szoveg']) ? (string) wp_unslash($_POST['szoveg']) : '');

            if ($hiba === '') {
                wp_safe_redirect(add_query_arg([self::QV => $token, 'koszon' => '1'], home_url('/')));
                exit;
            }

            status_header(200);
            self::oldal($lap, $hiba);
            exit;
        }

        status_header(200);

        if (isset($_GET['koszon']) && (int) $lap->elegedettseg > 0) {
            self::koszono($lap);
            exit;
        }

        self::oldal($lap, '', isset($_GET['c']) ? (int) $_GET['c'] : (int) $lap->elegedettseg);
        exit;
        // phpcs:enable
    }

    private static function ablak(string $cim, string $ikon, string $szoveg, ?callable $tovabb = null): void
    {
        SDH_Muhely_Rma_Oldal::keret($cim, 'rma--zar rma--ertekeles', static function () use ($cim, $ikon, $szoveg, $tovabb): void {
            ?>
            <div class="rma-ablak rma-ablak--kicsi">
                <?php SDH_Muhely_Rma_Oldal::fejlec(null, false, false); ?>
                <div class="rma-zar">
                    <?php if ($ikon !== '') : ?><div class="rma-zar__ikon"><?php echo SDH_Muhely_Rma_Oldal::ikon($ikon); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
                    <h1><?php echo esc_html($cim); ?></h1>
                    <?php if ($szoveg !== '') : ?><p><?php echo esc_html($szoveg); ?></p><?php endif; ?>
                    <?php if ($tovabb) { $tovabb(); } ?>
                </div>
            </div>
            <?php
        });
    }

    private static function oldal(object $lap, string $hiba = '', int $elore = 0): void
    {
        $szam = SDH_Muhely_Munkalap::szam_formaz($lap->munkalap_szam);

        self::ablak('Mennyire volt elégedett?', '', 'Munkalap: ' . $szam, static function () use ($lap, $hiba, $elore): void {
            ?>
            <?php if ($hiba !== '') : ?><div class="rma-jelzes rma-jelzes--hiba" role="alert"><?php echo esc_html($hiba); ?></div><?php endif; ?>
            <form method="post" class="rma-urlap rma-ert" data-rma-ertekeles>
                <fieldset class="rma-ert__csillagok">
                    <legend>Értékelés</legend>
                    <?php for ($i = 5; $i >= 1; $i--) : ?>
                        <input type="radio" name="csillag" id="rma_csillag_<?php echo (int) $i; ?>" value="<?php echo (int) $i; ?>"<?php checked($elore, $i); ?>>
                        <label for="rma_csillag_<?php echo (int) $i; ?>" title="<?php echo (int) $i; ?> csillag"><span aria-hidden="true">★</span><span class="rma-ert__sr"><?php echo (int) $i; ?> csillag</span></label>
                    <?php endfor; ?>
                </fieldset>
                <label class="rma-mezo"><span>Megjegyzés (nem kötelező)</span>
                    <textarea name="szoveg" rows="3" maxlength="1000"><?php echo esc_textarea((string) $lap->elegedettseg_szoveg); ?></textarea>
                </label>
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges rma-gomb-nagy">Elküldöm</button>
            </form>
            <?php
        });
    }

    private static function koszono(object $lap): void
    {
        $b  = self::beallitas();
        $jo = (int) $lap->elegedettseg >= $b['kuszob'];

        self::ablak('Köszönjük a visszajelzését!', 'info', $jo
            ? 'Örülünk, hogy elégedett volt.' . ($b['google'] !== '' ? ' Sokat segítene, ha a Google-ön is megírná – egy perc az egész.' : '')
            : 'Sajnáljuk, hogy nem minden sikerült jól. Megkaptuk a megjegyzését, és hamarosan keressük.', static function () use ($b, $jo): void {
                if ($jo && $b['google'] !== '') {
                    ?>
                    <a class="sdh-gomb sdh-gomb--elsodleges rma-gomb-nagy" href="<?php echo esc_url($b['google']); ?>" target="_blank" rel="noopener">Értékelés a Google-ön</a>
                    <?php
                }
            });
    }

    /* =================================================================
     * Munkalap: Elégedettség csoport
     * ============================================================== */

    public static function urlap_blokk(?object $l): string
    {
        if ($l === null || empty($l->id) || (string) ($l->fajta ?? '') !== 'javitas' || (int) ($l->munkalap_szam ?? 0) <= 0) {
            return '';
        }

        $b       = self::beallitas();
        $csillag = (int) ($l->elegedettseg ?? 0);
        $kuldve  = (string) ($l->elegedettseg_kuldve ?? '');

        if (!$b['be'] && $csillag === 0 && $kuldve === '') {
            return '';
        }

        $ido = static fn ($v): string => $v ? mysql2date('Y.m.d. H:i', (string) $v) : '';

        ob_start();
        ?>
        <div class="sdh-ml__csoport sdh-ml-elegedettseg" data-sdh-elegedettseg="<?php echo (int) $l->id; ?>">
            <h3 class="sdh-ml__cim">Elégedettség</h3>
            <?php if ($csillag > 0) : ?>
                <p class="sdh-ml-elegedettseg__csillag is-<?php echo $csillag >= $b['kuszob'] ? 'jo' : 'rossz'; ?>">
                    <span aria-hidden="true"><?php echo esc_html(str_repeat('★', $csillag) . str_repeat('☆', 5 - $csillag)); ?></span>
                    <strong><?php echo (int) $csillag; ?>/5</strong> – <?php echo esc_html($ido($l->elegedettseg_ido)); ?>
                </p>
                <?php if ((string) $l->elegedettseg_szoveg !== '') : ?>
                    <p class="sdh-ml-ajanlat__megj">„<?php echo esc_html((string) $l->elegedettseg_szoveg); ?>”</p>
                <?php endif; ?>
            <?php elseif ($kuldve !== '') : ?>
                <p class="sdh-mezo__sugo">
                    <?php if ((string) $l->elegedettseg_csatorna === 'nincs') : ?>
                        Nem ment ki kérdés (nincs használható telefonszám vagy e-mail-cím).
                    <?php else : ?>
                        Kérdés elküldve <?php echo esc_html($ido($kuldve)); ?> (<?php echo (string) $l->elegedettseg_csatorna === 'sms' ? 'SMS' : 'e-mail'; ?>), még nem értékelt.
                    <?php endif; ?>
                </p>
            <?php else : ?>
                <p class="sdh-mezo__sugo">A lezárás után <?php echo (int) $b['nap']; ?> nappal magától kimegy.</p>
                <p class="sdh-ml-ajanlat__gombok">
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-elegedettseg-kuld>Kérdés küldése most</button>
                </p>
            <?php endif; ?>
            <span class="sdh-mezo__sugo" data-sdh-elegedettseg-uzenet role="status"></span>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function ajax_kuld(): void
    {
        check_ajax_referer('sdh_muhely_modal', '_wpnonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $lap = SDH_Muhely_Ajanlat::lap(isset($_POST['id']) ? (int) $_POST['id'] : 0);

        if ($lap === null || (int) $lap->ugyfel_id <= 0) {
            wp_send_json_error(['uzenet' => 'A lapon nincs ügyfél.']);
        }

        $mi = self::kuld($lap);

        if ($mi === '') {
            wp_send_json_error(['uzenet' => 'Nem ment ki: nincs használható telefonszám vagy e-mail-cím.', 'html' => self::urlap_blokk(SDH_Muhely_Ajanlat::lap((int) $lap->id))]);
        }

        wp_send_json_success(['html' => self::urlap_blokk(SDH_Muhely_Ajanlat::lap((int) $lap->id)), 'uzenet' => 'Elküldve.']);
    }

    /* =================================================================
     * Riport
     * ============================================================== */

    /** @return array{db: int, atlag: float, jo: int} */
    public static function osszesit(string $tol, string $ig): array
    {
        global $wpdb;

        $r = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS db, AVG(elegedettseg) AS atlag, SUM(CASE WHEN elegedettseg >= %d THEN 1 ELSE 0 END) AS jo FROM ' . self::tabla()
            . ' WHERE elegedettseg > 0 AND elegedettseg_ido >= %s AND elegedettseg_ido <= %s',
            self::beallitas()['kuszob'],
            $tol . ' 00:00:00',
            $ig . ' 23:59:59'
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return ['db' => (int) ($r->db ?? 0), 'atlag' => (float) ($r->atlag ?? 0), 'jo' => (int) ($r->jo ?? 0)];
    }

    /* =================================================================
     * Beállítások lapfül
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();
        ?>
        <div class="sdh-doboz" id="elegedettseg">
            <h2 class="sdh-doboz__cim">Elégedettségi kérdés</h2>
            <p class="sdh-sugo">
                A lezárt javítás után az ügyfél SMS-ben vagy e-mailben egy linket kap, ahol 1–5 csillaggal értékel. Ha elégedett,
                az oldal a Google-értékelés linkjét kínálja; ha nem, te kapsz e-mailt a megjegyzésével, hogy rendezhesd.
                Egy lap egyszer kap kérdést. Bekapcsoláskor csak a frissen lezárt lapok kapnak, a régiek nem.
            </p>
            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="checkbox" name="elegedettseg[be]" id="eleg_be" value="1" <?php checked($b['be']); ?>>
                    <label for="eleg_be">Elégedettségi kérdés küldése a lezárt javítások után</label>
                </div>
                <div class="sdh-mezo">
                    <label for="eleg_csatorna">Hogyan menjen</label>
                    <select name="elegedettseg[csatorna]" id="eleg_csatorna">
                        <?php foreach (self::CSATORNAK as $k => $nev) : ?>
                            <option value="<?php echo esc_attr($k); ?>"<?php selected($b['csatorna'], $k); ?>><?php echo esc_html($nev); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="eleg_nap">Hány nappal a lezárás után</label>
                    <input type="number" name="elegedettseg[nap]" id="eleg_nap" min="0" max="14" step="1" value="<?php echo (int) $b['nap']; ?>">
                    <span class="sdh-mezo__sugo">0 = még a lezárás napján.</span>
                </div>
                <div class="sdh-mezo">
                    <label for="eleg_ora">Legkorábban ennyi órakor</label>
                    <input type="number" name="elegedettseg[ora]" id="eleg_ora" min="0" max="22" step="1" value="<?php echo (int) $b['ora']; ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="eleg_kuszob">Elégedettnek számít</label>
                    <select name="elegedettseg[kuszob]" id="eleg_kuszob">
                        <?php foreach ([3, 4, 5] as $k) : ?>
                            <option value="<?php echo (int) $k; ?>"<?php selected($b['kuszob'], $k); ?>><?php echo (int) $k; ?> csillagtól</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="eleg_google">Google-értékelés linkje</label>
                    <input type="url" name="elegedettseg[google]" id="eleg_google" value="<?php echo esc_attr($b['google']); ?>" placeholder="https://g.page/r/…/review">
                    <span class="sdh-mezo__sugo">A cégprofilod „Értékelés kérése” linkje. Üresen az elégedett ügyfél csak köszönetet lát.</span>
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="eleg_sms">Az SMS szövege</label>
                    <textarea name="elegedettseg[sms]" id="eleg_sms" rows="2"><?php echo esc_textarea($b['sms']); ?></textarea>
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="eleg_targy">Az e-mail tárgya</label>
                    <input type="text" name="elegedettseg[email_targy]" id="eleg_targy" value="<?php echo esc_attr($b['email_targy']); ?>">
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="eleg_email">Az e-mail szövege</label>
                    <textarea name="elegedettseg[email]" id="eleg_email" rows="7"><?php echo esc_textarea($b['email']); ?></textarea>
                    <span class="sdh-mezo__sugo">Helyőrzők: {nev} az ügyfél neve, {szerviz} a szerviz neve, {munkalap} a munkalap száma, {link} az értékelő oldal. Üresen az alapszöveg megy.</span>
                </div>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        $be = isset($_POST['elegedettseg']) && is_array($_POST['elegedettseg']) ? wp_unslash($_POST['elegedettseg']) : [];
        $sz = static fn (string $k, int $max): string => mb_substr(sanitize_textarea_field((string) ($be[$k] ?? '')), 0, $max);

        update_option(self::OPT, [
            'be'          => !empty($be['be']),
            'csatorna'    => sanitize_key((string) ($be['csatorna'] ?? 'sms_email')),
            'nap'         => (int) ($be['nap'] ?? 1),
            'ora'         => (int) ($be['ora'] ?? 10),
            'kuszob'      => (int) ($be['kuszob'] ?? 4),
            'google'      => esc_url_raw((string) ($be['google'] ?? '')),
            'sms'         => $sz('sms', 300),
            'email_targy' => $sz('email_targy', 200),
            'email'       => $sz('email', 3000),
        ], false);
    }
}
