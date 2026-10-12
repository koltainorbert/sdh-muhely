<?php
/**
 * SDH Műhely – bejövő SMS (webhook).
 *
 * A telefon SMS-átjáró appja ide jelzi: megérkezett egy SMS (sms:received), illetve egy kimenő
 * SMS állapota változott (sms:sent / sms:delivered). A cím titkos kulcsot tartalmaz; kulcs nélkül
 * vagy rossz kulccsal a kérés 401-et kap. Minden esemény legfeljebb egyszer rögzül.
 *
 * FIGYELEM: a hasznos adat mezőnevei az átjáró dokumentációja (swagger.json) alapján
 * véglegesítendők; az olvasás tűrő (több lehetséges mezőnevet is elfogad).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Sms_Bejovo
{
    public const OPT_TITOK = 'sdh_muhely_sms_webhook_titok';

    /** Ezekre a szövegekre a feladó leiratkozik az automatikus SMS-ekről. */
    private const LEIRATKOZAS = ['stop', 'leiratkozas', 'leiratkozom', 'unsubscribe'];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'utvonal']);
        add_action('wp_ajax_sdh_muhely_sms_parosit', [self::class, 'ajax_parosit']);
        add_action('wp_ajax_sdh_muhely_sms_webhook_titok', [self::class, 'ajax_titok']);
    }

    public static function utvonal(): void
    {
        register_rest_route('sdh-muhely/v1', '/sms/bejovo', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'kezel'],
            'permission_callback' => [self::class, 'jogosult'],
        ]);
    }

    /* =================================================================
     * Titkos kulcs
     * ============================================================== */

    public static function titok(): string
    {
        $t = (string) get_option(self::OPT_TITOK, '');

        if (strlen($t) < 24) {
            $t = wp_generate_password(40, false, false);
            update_option(self::OPT_TITOK, $t, false);
        }

        return $t;
    }

    /** Új kulcs: a régi cím azonnal érvénytelen. */
    public static function titok_ujra(): string
    {
        delete_option(self::OPT_TITOK);

        return self::titok();
    }

    /** A telefonba beírandó teljes cím. */
    public static function url(): string
    {
        return add_query_arg('k', self::titok(), rest_url('sdh-muhely/v1/sms/bejovo'));
    }

    /** @return true|WP_Error */
    public static function jogosult(WP_REST_Request $keres)
    {
        $kapott = (string) $keres->get_param('k');

        if ($kapott === '') {
            $kapott = (string) $keres->get_header('x_sdh_titok');
        }

        if ($kapott === '' && preg_match('/^Bearer\s+(.+)$/i', (string) $keres->get_header('authorization'), $m)) {
            $kapott = trim($m[1]);
        }

        if ($kapott !== '' && hash_equals(self::titok(), $kapott)) {
            return true;
        }

        return new WP_Error('sdh_sms_tiltott', 'Érvénytelen kulcs.', ['status' => 401]);
    }

    /* =================================================================
     * Fogadás
     * ============================================================== */

    public static function kezel(WP_REST_Request $keres): WP_REST_Response
    {
        $adat = $keres->get_json_params();

        if (!is_array($adat)) {
            return new WP_REST_Response(['ok' => false, 'hiba' => 'Nem JSON.'], 400);
        }

        // Minden hiteles jelzés életjel: az önteszt és a monitor ebből látja, hogy a webhook működik.
        SDH_Muhely_Sms::allapot_ment(['webhook_utolso' => time()]);

        $v = self::fogad($adat);

        return new WP_REST_Response($v, $v['ok'] ? 200 : 400);
    }

    /**
     * @param array<string, mixed> $adat A webhook törzse.
     *
     * @return array<string, mixed>
     */
    public static function fogad(array $adat): array
    {
        $esemeny = strtolower(trim((string) ($adat['event'] ?? $adat['type'] ?? '')));
        $hasznos = isset($adat['payload']) && is_array($adat['payload']) ? $adat['payload'] : $adat;

        if (!SDH_Muhely_Sms::bekapcsolva()) {
            // Kikapcsolt modulnál nem rögzítünk, de 200-zal nyugtázunk (az app ne próbálkozzon újra).
            return ['ok' => true, 'kihagyva' => 'Az SMS-modul ki van kapcsolva.'];
        }

        if ($esemeny === 'sms:received' || ($esemeny === '' && isset($hasznos['message']))) {
            return self::bejovo_sms($hasznos);
        }

        if (in_array($esemeny, ['sms:sent', 'sms:delivered'], true)) {
            return self::allapot_jelzes($esemeny === 'sms:sent' ? 'sent' : 'delivered', $hasznos);
        }

        // Más esemény (pl. sms:failed): az állapotkövetés lekérdezéssel úgyis pótolja.
        return ['ok' => true, 'kihagyva' => 'Ismeretlen vagy nem használt esemény.'];
    }

    /**
     * @param array<string, mixed> $h
     *
     * @return array<string, mixed>
     */
    private static function allapot_jelzes(string $uj, array $h): array
    {
        global $wpdb;

        $azon = trim((string) ($h['messageId'] ?? $h['id'] ?? ''));

        if ($azon === '') {
            return ['ok' => false, 'hiba' => 'Nincs messageId.'];
        }

        $sor = $wpdb->get_row($wpdb->prepare(
            'SELECT id, allapot FROM ' . SDH_Muhely_Sms::tabla('uzenet') . " WHERE irany = 'ki' AND atjaro_id = %s",
            $azon
        ));

        if (!is_object($sor)) {
            return ['ok' => true, 'kihagyva' => 'Ismeretlen üzenet.'];
        }

        if (!SDH_Muhely_Sms::allapot_javul((string) $sor->allapot, $uj)) {
            return ['ok' => true, 'kihagyva' => 'Nem új állapot.'];
        }

        $mezok = $uj === 'delivered' ? ['kezbesitve' => current_time('mysql')] : ['elkuldve' => current_time('mysql')];

        SDH_Muhely_Sms::allapot_ir((int) $sor->id, $uj, 'Webhook', $mezok, 'wh:' . $azon . ':' . $uj);

        return ['ok' => true, 'sms_id' => (int) $sor->id, 'allapot' => $uj];
    }

    /**
     * @param array<string, mixed> $h
     *
     * @return array<string, mixed>
     */
    private static function bejovo_sms(array $h): array
    {
        global $wpdb;

        $nyers_szam = trim((string) ($h['phoneNumber'] ?? $h['sender'] ?? $h['from'] ?? $h['number'] ?? ''));
        $szoveg     = trim((string) ($h['message'] ?? $h['text'] ?? $h['body'] ?? ''));
        $azon       = trim((string) ($h['messageId'] ?? $h['id'] ?? ''));
        $mikor      = trim((string) ($h['receivedAt'] ?? ''));

        if ($nyers_szam === '' || $szoveg === '') {
            return ['ok' => false, 'hiba' => 'Hiányzó feladó vagy szöveg.'];
        }

        $szam  = SDH_Muhely_Sms::szam_normalizal($nyers_szam);
        $kulcs = 'be:' . ($azon !== '' ? $azon : sha1($szam['szam'] . '|' . $szoveg . '|' . $mikor));
        $kulcs = mb_substr($kulcs, 0, 100);

        $ugyfel_id = self::ugyfel_keres($szam['ok'] ? $szam['szam'] : '');
        $lap_id    = $ugyfel_id > 0 ? self::nyitott_lap($ugyfel_id) : 0;

        $id = SDH_Muhely_Sms::naplo_uj([
            'irany'        => 'be',
            'tipus'        => 'ugyfel',
            'kivalto'      => 'bejovo',
            'felhasznalo'  => 0,
            'ugyfel_id'    => $ugyfel_id,
            'munkalap_id'  => $lap_id,
            'szam'         => $szam['ok'] ? $szam['szam'] : $nyers_szam,
            'szam_eredeti' => $nyers_szam,
            'szoveg'       => $szoveg,
            'allapot'      => 'fogadva',
            'olvasva'      => 0,
            'parositva'    => $lap_id > 0 ? 1 : 0,
            'kulcs'        => $kulcs,
            'atjaro_id'    => mb_substr($azon, 0, 80),
            'elkuldve'     => self::ido_mysql($mikor),
        ]);

        if ($id === 0) {
            return ['ok' => true, 'kihagyva' => 'Már rögzítve.'];
        }

        $kulcs_sz = self::tisztit($szoveg);

        if ($ugyfel_id > 0 && in_array($kulcs_sz, self::LEIRATKOZAS, true)) {
            $wpdb->update(SDH_Muhely_Schema::tabla('ugyfel'), ['sms_tilt' => 1], ['id' => $ugyfel_id]);
            SDH_Muhely_Sms::esemeny($id, 'leiratkozas', 'fogadva', 'Az ügyfél leiratkozott az automatikus SMS-ekről.', null, 0);
        }

        if ($lap_id > 0) {
            // A munkalap üzenetszálába kerül; a Rma::uzenet_ment e-mailben is jelzi a szervizesnek.
            $uzenet = SDH_Muhely_Rma::uzenet_ment($lap_id, $ugyfel_id, 'be', 'sms', $szoveg, 0);

            if ($uzenet > 0) {
                $wpdb->update(SDH_Muhely_Schema::tabla('uzenet'), ['sms_id' => $id], ['id' => $uzenet]);
            }
        } else {
            self::ertesit_parositatlan($nyers_szam, $szoveg, $ugyfel_id);
        }

        return ['ok' => true, 'sms_id' => $id, 'parositva' => $lap_id > 0, 'munkalap_id' => $lap_id];
    }

    private static function tisztit(string $szoveg): string
    {
        $s = remove_accents(mb_strtolower(trim($szoveg)));

        return trim(preg_replace('/[^a-z]+/', '', $s) ?? '');
    }

    private static function ido_mysql(string $nyers): ?string
    {
        $ts = $nyers !== '' ? strtotime($nyers) : false;

        return $ts ? wp_date('Y-m-d H:i:s', $ts) : null;
    }

    /* =================================================================
     * Párosítás
     * ============================================================== */

    /**
     * A feladó ügyfele az 1. vagy 2. telefonmező alapján. Több találatnál az, akinek van nyitott
     * munkalapja, azon belül a legutóbbi.
     */
    public static function ugyfel_keres(string $e164): int
    {
        global $wpdb;

        $szamjegy = preg_replace('/\D+/', '', $e164) ?? '';

        if (strlen($szamjegy) < 7) {
            return 0;
        }

        $vege = substr($szamjegy, -7);
        $tab  = SDH_Muhely_Schema::tabla('ugyfel');
        $tiszta = static fn (string $o): string => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$o},' ',''),'-',''),'/',''),'(',''),')','')";
        $sorok  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, telefon, telefon2 FROM {$tab} WHERE " . $tiszta('telefon') . ' LIKE %s OR ' . $tiszta('telefon2') . ' LIKE %s ORDER BY id DESC LIMIT 20',
            '%' . $wpdb->esc_like($vege),
            '%' . $wpdb->esc_like($vege)
        ));

        $jok = [];

        foreach ((array) $sorok as $s) {
            foreach ([(string) $s->telefon, (string) $s->telefon2] as $nyers) {
                $n = SDH_Muhely_Sms::szam_normalizal($nyers);

                if ($n['ok'] && $n['szam'] === $e164) {
                    $jok[] = (int) $s->id;
                    break;
                }
            }
        }

        foreach ($jok as $id) {
            if (self::nyitott_lap($id) > 0) {
                return $id;
            }
        }

        return $jok[0] ?? 0;
    }

    /** Az ügyfél legutóbbi nem lezárt munkalapja (a javítás előnyben a rendeléssel szemben). */
    public static function nyitott_lap(int $ugyfel_id): int
    {
        global $wpdb;

        $zartak = [];

        foreach (SDH_Muhely_Munkalap::allapotok() as $kulcs => $a) {
            if (!empty($a['zart'])) {
                $zartak[] = (string) $kulcs;
            }
        }

        $hol = '';

        if ($zartak) {
            $hol = ' AND allapot NOT IN (' . implode(',', array_fill(0, count($zartak), '%s')) . ')';
        }

        $sql = 'SELECT id FROM ' . SDH_Muhely_Schema::tabla('munkalap') . " WHERE ugyfel_id = %d AND fajta IN ('javitas','rendeles'){$hol} ORDER BY (fajta = 'javitas') DESC, id DESC LIMIT 1";

        return (int) $wpdb->get_var($wpdb->prepare($sql, array_merge([$ugyfel_id], $zartak)));
    }

    private static function ertesit_parositatlan(string $szam, string $szoveg, int $ugyfel_id): void
    {
        $cel = sanitize_email((string) SDH_Muhely_Rma::beallitas()['ertesites_email']);

        if ($cel === '') {
            return;
        }

        $kinek = $ugyfel_id > 0 ? 'ügyfél, nyitott munkalap nélkül' : 'ismeretlen szám';

        wp_mail(
            $cel,
            'Bejövő SMS (' . $kinek . ')',
            "Feladó: {$szam}\n\n{$szoveg}\n\nA CRM SMS › Bejövő SMS oldalán hozzárendelheted egy munkalaphoz."
        );
    }

    /**
     * Kézi hozzárendelés egy munkalaphoz (a párosítatlan listáról).
     *
     * @return array{ok: bool, uzenet: string}
     */
    public static function parosit(int $sms_id, int $munkalap_id): array
    {
        global $wpdb;

        $sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Sms::tabla('uzenet') . " WHERE id = %d AND irany = 'be'", $sms_id));
        $lap = SDH_Muhely_Rma::munkalap($munkalap_id);

        if (!is_object($sor) || !$lap) {
            return ['ok' => false, 'uzenet' => 'Nincs ilyen SMS vagy munkalap.'];
        }

        if ((int) $sor->parositva === 1 && (int) $sor->munkalap_id > 0) {
            return ['ok' => false, 'uzenet' => 'Ez az SMS már hozzá van rendelve egy munkalaphoz.'];
        }

        $ugyfel_id = (int) $lap->ugyfel_id;
        $uzenet    = SDH_Muhely_Rma::uzenet_ment($munkalap_id, $ugyfel_id, 'be', 'sms', (string) $sor->szoveg, 0);

        if ($uzenet > 0) {
            $wpdb->update(SDH_Muhely_Schema::tabla('uzenet'), ['sms_id' => $sms_id], ['id' => $uzenet]);
        }

        $wpdb->update(SDH_Muhely_Sms::tabla('uzenet'), ['munkalap_id' => $munkalap_id, 'ugyfel_id' => $ugyfel_id, 'parositva' => 1, 'olvasva' => 1], ['id' => $sms_id]);
        SDH_Muhely_Sms::esemeny($sms_id, 'parositas', (string) $sor->allapot, 'Kézzel hozzárendelve a(z) ' . $munkalap_id . '. azonosítójú munkalaphoz.');

        return ['ok' => true, 'uzenet' => 'Hozzárendelve.'];
    }

    public static function ajax_parosit(): void
    {
        check_ajax_referer('sdh_muhely_modal', '_wpnonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod.'], 403);
        }

        global $wpdb;

        $sms_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $szam   = isset($_POST['munkalap_szam']) ? (int) preg_replace('/\D+/', '', (string) wp_unslash($_POST['munkalap_szam'])) : 0;
        $lap_id = $szam > 0 ? (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE munkalap_szam = %d', $szam)) : 0;

        if ($lap_id <= 0) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen munkalapszám.']);
        }

        $v = self::parosit($sms_id, $lap_id);

        $v['ok'] ? wp_send_json_success(['uzenet' => $v['uzenet']]) : wp_send_json_error(['uzenet' => $v['uzenet']]);
    }

    public static function ajax_titok(): void
    {
        check_ajax_referer('sdh_muhely_modal', '_wpnonce');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Csak adminisztrátor kérheti új kulcsot.'], 403);
        }

        self::titok_ujra();
        wp_send_json_success(['url' => self::url()]);
    }

    /** Az utolsó fogadott webhook (életjel) időbélyege, 0 = még nem jött. */
    public static function utolso(): int
    {
        return (int) (SDH_Muhely_Sms::allapot()['webhook_utolso'] ?? 0);
    }
}
