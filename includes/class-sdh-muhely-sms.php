<?php
/**
 * SMS-értesítések (0.57): átjáró-kliens, számkezelés, napló, beállítások.
 *
 * Az SMS-t egy Android telefon küldi az „SMS Gateway for Android” (sms-gate.app)
 * appon át. Három üzemmód, UGYANAZZAL a klienssel – csak a cím más:
 *   helyi  – a CRM közvetlenül a telefonra hív a helyi hálón (http://IP:port),
 *   felho  – a nyilvános felhőszerver (https://api.sms-gate.app/3rdparty/v1),
 *   privat – a saját szerverre telepített átjáró (egyéni cím).
 *
 * Szabályok:
 *   - SEMMI nem kerül a kódba, ami egy üzlethez köt: cím, szám, név, szöveg –
 *     minden beállítás (Beállítások › SMS), a jelszó titkosítva (SDH_Muhely_Titok).
 *   - A napló csak hozzáfűzős: sms_uzenet sor nem törlődik, a küldött szöveg nem
 *     íródik felül; minden állapotátmenet külön sor az sms_esemeny-ben.
 *   - SMS-hiba sosem akadályozza meg a státuszváltás vagy rendelés mentését.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Sms
{
    public const OPT         = 'sdh_muhely_sms';
    public const OPT_ALLAPOT = 'sdh_muhely_sms_allapot';
    public const NONCE       = 'sdh_muhely_sms';

    /** A nyilvános felhőszerver gyökere (az API-dokumentáció szerint). */
    private const FELHO_ALAP = 'https://api.sms-gate.app/3rdparty/v1';

    /**
     * Az átjáró elfogadja-e a kliens által adott üzenet-azonosítót (`id`). Ha igen,
     * időtúllépés után is lekérdezhető, hogy az üzenet kiment-e (nincs dupla küldés).
     * A swagger.json alapján igazolandó, addig KI.
     */
    public const SAJAT_ID = false;

    /* =================================================================
     * Indítás
     * ============================================================== */

    public static function init(): void
    {
        add_filter('cron_schedules', [self::class, 'cron_utem']);

        add_action(self::HOOK, [self::class, 'feldolgoz']);
        add_action('init', [self::class, 'idozit']);

        add_action('wp_ajax_sdh_muhely_sms_kapcsolat', [self::class, 'ajax_kapcsolat']);
        add_action('wp_ajax_sdh_muhely_sms_teszt', [self::class, 'ajax_teszt']);
    }

    /** @param array<string, array<string, mixed>> $utemek */
    public static function cron_utem(array $utemek): array
    {
        $utemek['sdh_perc'] = ['interval' => 60, 'display' => 'Percenként'];

        return $utemek;
    }

    /* =================================================================
     * Beállítások és állapot
     * ============================================================== */

    /** @return array<string, mixed> */
    public static function alapok(): array
    {
        return [
            // Főkapcsoló: új telepítés magától SOHA ne küldjön.
            'be'              => false,
            // Szimuláció: nem küld, csak naplóz.
            'szimulacio'      => false,
            'mod'             => 'helyi',   // helyi | felho | privat
            'cim'             => '',
            'port'            => 8080,
            'felhasznalo'     => '',
            'jelszo'          => '',        // titkosítva tárolva (sdh1:)
            'idotullepes'     => 10,        // másodperc
            'ujra_db'         => 3,
            'ujra_perc'       => 5,         // az első újrapróbálás ennyi perc múlva, utána duplázódik
            'csendes_be'      => true,
            'csendes_tol'     => '21:00',
            'csendes_ig'      => '08:00',
            'alairas'         => '',
            'orszagkod'       => '+36',
            'health_perc'     => 1,
            'onteszt_be'      => true,
            'onteszt_ido'     => '09:00',
            'onteszt_szam'    => '',
            'riasztas_email'  => '',        // üres = az ügyféloldal értesítési e-mailje
            'kihagyott_perc'  => 30,        // „elküldve, de nincs kézbesítési jelzés” ennyi perc után
        ];
    }

    /** @return array<string, mixed> */
    public static function beallitas(): array
    {
        $mentett = get_option(self::OPT, []);
        $b       = array_merge(self::alapok(), is_array($mentett) ? $mentett : []);

        $b['be']         = !empty($b['be']);
        $b['szimulacio'] = !empty($b['szimulacio']);
        $b['mod']        = in_array($b['mod'], ['helyi', 'felho', 'privat'], true) ? (string) $b['mod'] : 'helyi';
        $b['port']       = max(0, min(65535, (int) $b['port']));
        $b['idotullepes'] = max(2, min(60, (int) $b['idotullepes']));
        $b['ujra_db']    = max(0, min(10, (int) $b['ujra_db']));
        $b['ujra_perc']  = max(1, min(240, (int) $b['ujra_perc']));
        $b['health_perc'] = max(1, min(60, (int) $b['health_perc']));
        $b['kihagyott_perc'] = max(5, min(1440, (int) $b['kihagyott_perc']));

        return $b;
    }

    public static function bekapcsolva(): bool
    {
        // A Modulok menüpontban kikapcsolt SMS modul felülír mindent: semmi nem megy ki, a sor sem fut.
        return SDH_Muhely_Modulok::be('sms') && (bool) self::beallitas()['be'];
    }

    /** A kapcsolat jelszava nyíltan – csak a kérés idejére, kliensnek, naplónak soha. */
    private static function jelszo(): string
    {
        $b = get_option(self::OPT, []);

        return is_array($b) && is_string($b['jelszo'] ?? null) ? SDH_Muhely_Titok::visszafejt((string) $b['jelszo']) : '';
    }

    /** @return array<string, mixed> */
    public static function allapot(): array
    {
        $a = get_option(self::OPT_ALLAPOT, []);

        return is_array($a) ? $a : [];
    }

    /** @param array<string, mixed> $mezok */
    public static function allapot_ment(array $mezok): void
    {
        update_option(self::OPT_ALLAPOT, array_merge(self::allapot(), $mezok), false);
    }

    public static function tabla(string $nev): string
    {
        return SDH_Muhely_Schema::tabla('sms_' . $nev);
    }

    /* =================================================================
     * Átjáró-kliens
     * ============================================================== */

    /** Az átjáró gyökércíme a beállításokból; üres, ha nincs (vagy hibás) cím. */
    private static function alap_url(array $b): string
    {
        if ($b['mod'] === 'felho') {
            return self::FELHO_ALAP;
        }

        $cim = trim((string) $b['cim']);

        if ($cim === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $cim)) {
            $cim = ($b['mod'] === 'privat' ? 'https://' : 'http://') . $cim;
        }

        $r = wp_parse_url($cim);

        if (!is_array($r) || empty($r['host'])) {
            return '';
        }

        if ($b['mod'] === 'helyi' && (int) $b['port'] > 0 && empty($r['port'])) {
            $cim = rtrim($cim, '/') . ':' . (int) $b['port'];
        }

        return rtrim($cim, '/');
    }

    /** Érthető magyar hiba az átjáró elérhetetlenségére, az üzemmód szerint. */
    public static function nem_elerheto_uzenet(): string
    {
        return self::beallitas()['mod'] === 'felho'
            ? 'Az SMS-átjáró (felhő) nem elérhető. Ellenőrizd az internetkapcsolatot, és hogy a telefonon az app Cloud Server kapcsolója Online-e.'
            : 'Az SMS-átjáró nem elérhető. Ellenőrizd, hogy a telefon be van-e kapcsolva, Wi-Fi-n van-e, és az app Local Server kapcsolója Online-e.';
    }

    /**
     * Egy hívás az átjárónak. A jelszó SOHA nem kerül a válaszba, hibába vagy naplóba.
     *
     * @param array<string, mixed>|null $torzs
     * @return array{ok: bool, kod: int, tipus: string, hiba: string, adat: mixed}
     *   tipus: ok | nincs_cim | elerhetetlen | idotullepes | hitelesites | atjaro_hiba | kerelem | ismeretlen
     */
    public static function hivas(string $metodus, string $ut, ?array $torzs = null, ?int $idotullepes = null): array
    {
        $b    = self::beallitas();
        $alap = self::alap_url($b);

        if ($alap === '') {
            return ['ok' => false, 'kod' => 0, 'tipus' => 'nincs_cim', 'hiba' => 'Nincs megadva az SMS-átjáró címe (Beállítások › SMS).', 'adat' => null];
        }

        $fejlec = ['Accept' => 'application/json'];
        $fel    = (string) $b['felhasznalo'];
        $jelszo = self::jelszo();

        if ($fel !== '' || $jelszo !== '') {
            $fejlec['Authorization'] = 'Basic ' . base64_encode($fel . ':' . $jelszo);
        }

        $args = [
            'method'      => $metodus,
            'timeout'     => $idotullepes ?? (int) $b['idotullepes'],
            'redirection' => 0,
            'headers'     => $fejlec,
        ];

        if ($torzs !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = wp_json_encode($torzs);
        }

        $v = wp_remote_request($alap . $ut, $args);

        if (is_wp_error($v)) {
            // Időtúllépés: a kérés elindulhatott, a válasz nem jött meg – ez más, mint a „nem elérhető”.
            if (stripos($v->get_error_message(), 'timed out') !== false || stripos($v->get_error_message(), 'error 28') !== false) {
                return ['ok' => false, 'kod' => 0, 'tipus' => 'idotullepes', 'hiba' => 'Az átjáró nem válaszolt időben.', 'adat' => null];
            }

            return ['ok' => false, 'kod' => 0, 'tipus' => 'elerhetetlen', 'hiba' => self::nem_elerheto_uzenet(), 'adat' => null];
        }

        $kod  = (int) wp_remote_retrieve_response_code($v);
        $adat = json_decode((string) wp_remote_retrieve_body($v), true);

        if ($kod >= 200 && $kod < 300) {
            return ['ok' => true, 'kod' => $kod, 'tipus' => 'ok', 'hiba' => '', 'adat' => $adat];
        }

        if ($kod === 401 || $kod === 403) {
            return ['ok' => false, 'kod' => $kod, 'tipus' => 'hitelesites', 'hiba' => 'Az átjáró elutasította a felhasználónevet vagy a jelszót. Ellenőrizd a Beállítások › SMS oldalon.', 'adat' => $adat];
        }

        $uzenet = is_array($adat) && isset($adat['message']) && is_string($adat['message']) ? mb_substr($adat['message'], 0, 160) : '';

        if ($kod >= 500) {
            return ['ok' => false, 'kod' => $kod, 'tipus' => 'atjaro_hiba', 'hiba' => 'Az SMS-átjáró hibát jelzett (' . $kod . ').' . ($uzenet !== '' ? ' ' . $uzenet : ''), 'adat' => $adat];
        }

        return ['ok' => false, 'kod' => $kod, 'tipus' => $kod >= 400 ? 'kerelem' : 'ismeretlen', 'hiba' => 'Az átjáró nem fogadta el a kérést (' . $kod . ').' . ($uzenet !== '' ? ' ' . $uzenet : ''), 'adat' => $adat];
    }

    /**
     * Egy SMS átadása az átjárónak. A válasz: az átjáró üzenetazonosítója és kezdő állapota.
     *
     * @return array{ok: bool, id: string, allapot: string, tipus: string, hiba: string}
     */
    public static function atjaro_kuld(string $szam, string $szoveg, string $sajat_id = ''): array
    {
        $torzs = ['textMessage' => ['text' => $szoveg], 'phoneNumbers' => [$szam]];

        if (self::SAJAT_ID && $sajat_id !== '') {
            $torzs['id'] = $sajat_id;
        }

        $v = self::hivas('POST', '/messages', $torzs);

        if (!$v['ok']) {
            // Időtúllépésnél nem tudjuk, kiment-e: bizonytalan (nem küldjük újra automatikusan).
            if ($v['tipus'] === 'idotullepes') {
                return ['ok' => false, 'id' => '', 'allapot' => '', 'tipus' => 'bizonytalan', 'hiba' => 'Az átjáró nem válaszolt időben – lehet, hogy az SMS kiment. Ellenőrizd a telefonon.'];
            }

            return ['ok' => false, 'id' => '', 'allapot' => '', 'tipus' => $v['tipus'], 'hiba' => $v['hiba']];
        }

        $adat = is_array($v['adat']) ? $v['adat'] : [];
        $id   = isset($adat['id']) && is_scalar($adat['id']) ? (string) $adat['id'] : '';

        if ($id === '') {
            // Az átjáró 2xx-et mondott, de azonosító nélkül: nem tudjuk lekérdezni.
            return ['ok' => false, 'id' => '', 'allapot' => '', 'tipus' => 'bizonytalan', 'hiba' => 'Az átjáró válasza nem tartalmaz üzenetazonosítót – nem biztos, hogy az SMS elindult.'];
        }

        return ['ok' => true, 'id' => $id, 'allapot' => self::allapot_nev((string) ($adat['state'] ?? 'Pending')), 'tipus' => 'ok', 'hiba' => ''];
    }

    /**
     * Egy üzenet állapota az átjárón (GET /messages/{id}).
     *
     * @return array{ok: bool, allapot: string, hiba: string, tipus: string}
     */
    public static function atjaro_allapot(string $id): array
    {
        $v = self::hivas('GET', '/messages/' . rawurlencode($id), null, 8);

        if (!$v['ok']) {
            return ['ok' => false, 'allapot' => '', 'hiba' => $v['hiba'], 'tipus' => $v['tipus']];
        }

        $adat  = is_array($v['adat']) ? $v['adat'] : [];
        $hiba  = '';
        $allap = self::allapot_nev((string) ($adat['state'] ?? ''));

        // A címzettenkénti állapot pontosabb lehet, mint az üzenet egésze.
        if (isset($adat['recipients'][0]) && is_array($adat['recipients'][0])) {
            $c = $adat['recipients'][0];

            if (!empty($c['state']) && is_string($c['state'])) {
                $allap = self::allapot_nev($c['state']);
            }

            if (!empty($c['error']) && is_string($c['error'])) {
                $hiba = mb_substr($c['error'], 0, 200);
            }
        }

        return ['ok' => true, 'allapot' => $allap, 'hiba' => $hiba, 'tipus' => 'ok'];
    }

    /** Az átjáró állapotnevei (Pending, Processed, Sent, Delivered, Failed) a naplóban kisbetűvel. */
    public static function allapot_nev(string $nyers): string
    {
        $k = strtolower(trim($nyers));

        return in_array($k, ['pending', 'processed', 'sent', 'delivered', 'failed'], true) ? $k : ($k === '' ? 'pending' : 'ismeretlen');
    }

    /**
     * Az átjáró elérhetősége. A végpont a swagger.json alapján véglegesítendő:
     * először /health, ha az nincs (404), az üzenetlista lekérdezése jelzi az életet.
     *
     * @return array{ok: bool, hiba: string, tipus: string, adat: mixed}
     */
    public static function health(): array
    {
        $v = self::hivas('GET', '/health', null, 6);

        if (!$v['ok'] && $v['kod'] === 404) {
            $v = self::hivas('GET', '/messages?limit=1', null, 6);
        }

        return ['ok' => $v['ok'], 'hiba' => $v['hiba'], 'tipus' => $v['tipus'], 'adat' => $v['adat']];
    }

    /* =================================================================
     * Telefonszám
     * ============================================================== */

    /**
     * Egységes (E.164) alak és érvényesség. A „0”, az üres, a nem szám, a túl rövid /
     * hosszú és a nem mobil magyar szám érvénytelen. Külföldi szám akkor jó, ha érvényes E.164.
     * Az eredeti szám mindig megmarad.
     *
     * @return array{ok: bool, szam: string, eredeti: string, kod: string, uzenet: string}
     */
    public static function szam_normalizal(string $nyers, ?string $orszag = null): array
    {
        $eredeti = trim($nyers);
        $orszag  = $orszag ?? (string) self::beallitas()['orszagkod'];
        $orszag  = preg_match('/^\+[1-9]\d{0,3}$/', $orszag) === 1 ? $orszag : '+36';
        $ki      = ['ok' => false, 'szam' => '', 'eredeti' => $eredeti, 'kod' => '', 'uzenet' => ''];

        $t = preg_replace('/[\s\-\.\(\)\/]/u', '', $eredeti) ?? '';

        if ($t === '' || preg_match('/^[+0]*$/', $t) === 1) {
            $ki['kod']    = 'ures';
            $ki['uzenet'] = 'Nincs érvényes telefonszám – SMS nem küldhető.';

            return $ki;
        }

        if (preg_match('/^\+?\d+$/', $t) !== 1) {
            $ki['kod']    = 'nem_szam';
            $ki['uzenet'] = 'A telefonszám nem szám (csak számjegy, szóköz, kötőjel és a vezető + engedett) – SMS nem küldhető.';

            return $ki;
        }

        $d = ltrim($t, '+');

        if ($t[0] === '+') {
            $e = '+' . $d;
        } elseif (str_starts_with($d, '00')) {
            $e = '+' . substr($d, 2);
        } elseif (str_starts_with($d, '06')) {
            $e = '+36' . substr($d, 2);
        } elseif (str_starts_with($d, '0')) {
            $e = $orszag . substr($d, 1);
        } elseif (preg_match('/^36\d{9}$/', $d) === 1) {
            $e = '+' . $d;
        } else {
            $e = $orszag . $d;
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/', $e) !== 1) {
            $ki['kod']    = strlen($e) < 9 ? 'rovid' : 'hosszu';
            $ki['uzenet'] = strlen($e) < 9
                ? 'A telefonszám túl rövid – SMS nem küldhető.'
                : 'A telefonszám túl hosszú vagy hibás – SMS nem küldhető.';

            return $ki;
        }

        if (str_starts_with($e, '+36') && preg_match('/^\+36(20|30|31|50|70)\d{7}$/', $e) !== 1) {
            $ki['kod']    = 'nem_mobil';
            $ki['uzenet'] = 'Ez nem magyar mobilszám (06 20/30/31/50/70…) – SMS nem küldhető.';

            return $ki;
        }

        return ['ok' => true, 'szam' => $e, 'eredeti' => $eredeti, 'kod' => '', 'uzenet' => ''];
    }

    /**
     * Az ügyfél SMS-re használható számai a küldés sorrendjében: az 1. telefonmező, majd
     * a 2. (ha van és más). Az érvénytelen szám is benne marad, jelzéssel – a felület
     * kiírja, miért nem küldhető.
     *
     * @return array{ugyfel: object|null, tilt: bool, szamok: array<int, array<string, mixed>>}
     */
    public static function ugyfel_szamai(int $ugyfel_id): array
    {
        global $wpdb;

        $u = $ugyfel_id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT id, nev, telefon, telefon2, sms_tilt FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', $ugyfel_id))
            : null;

        if (!is_object($u)) {
            return ['ugyfel' => null, 'tilt' => false, 'szamok' => []];
        }

        $lista  = [];
        $lattuk = [];

        foreach ([['telefon', 'ugyfel1'], ['telefon2', 'ugyfel2']] as [$mezo, $forras]) {
            $nyers = trim((string) $u->$mezo);

            if ($nyers === '' && $forras === 'ugyfel2') {
                continue;
            }

            $n           = self::szam_normalizal($nyers);
            $n['forras'] = $forras;

            if ($n['ok']) {
                if (isset($lattuk[$n['szam']])) {
                    continue;
                }

                $lattuk[$n['szam']] = true;
            }

            $lista[] = $n;
        }

        return ['ugyfel' => $u, 'tilt' => (int) $u->sms_tilt === 1, 'szamok' => $lista];
    }

    /* =================================================================
     * SMS-hossz: kódolás és darabszám
     * ============================================================== */

    /** @var array<string, int>|null */
    private static ?array $gsm_alap = null;

    /** @var array<string, int>|null */
    private static ?array $gsm_bovit = null;

    /**
     * Kódolás és szegmensszám. Ékezetes (á, í, ó, ú, ő, ű) szöveg UCS-2: 70 karakter
     * egy SMS-ben, hosszabbnál 67 / szegmens. A tiszta GSM-7: 160, illetve 153.
     *
     * @return array{kodolas: string, hossz: int, szegmens: int, egy: int, tobb: int}
     */
    public static function szegmensek(string $szoveg): array
    {
        if (self::$gsm_alap === null) {
            $alap  = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
            $bovit = "^{}\\[~]|€\f";

            self::$gsm_alap  = array_flip(mb_str_split($alap));
            self::$gsm_bovit = array_flip(mb_str_split($bovit));
        }

        $szoveg = str_replace(["\r\n", "\r"], "\n", $szoveg);
        $gsm    = true;
        $hossz  = 0;

        foreach (mb_str_split($szoveg) as $k) {
            if (isset(self::$gsm_alap[$k])) {
                $hossz += 1;
            } elseif (isset(self::$gsm_bovit[$k])) {
                $hossz += 2;
            } else {
                $gsm = false;
                break;
            }
        }

        if ($gsm) {
            $egy  = 160;
            $tobb = 153;
        } else {
            $hossz = intdiv(strlen((string) mb_convert_encoding($szoveg, 'UTF-16LE', 'UTF-8')), 2);
            $egy   = 70;
            $tobb  = 67;
        }

        $db = $hossz === 0 ? 0 : ($hossz <= $egy ? 1 : (int) ceil($hossz / $tobb));

        return ['kodolas' => $gsm ? 'GSM-7' : 'UCS-2', 'hossz' => $hossz, 'szegmens' => $db, 'egy' => $egy, 'tobb' => $tobb];
    }

    /* =================================================================
     * Napló (csak hozzáfűzés)
     * ============================================================== */

    /**
     * Új sor az sms_uzenet-ben (kimenő vagy bejövő) + az első esemény. A visszatérés az
     * új sor azonosítója, 0 hiba esetén. A szám és a szöveg itt kerül végleges alakba.
     *
     * @param array<string, mixed> $adat
     */
    public static function naplo_uj(array $adat): int
    {
        global $wpdb;

        $felh = isset($adat['felhasznalo']) ? (int) $adat['felhasznalo'] : get_current_user_id();
        $user = $felh > 0 ? get_userdata($felh) : false;
        $szoveg = (string) ($adat['szoveg'] ?? '');

        $sor = array_merge([
            'irany'           => 'ki',
            'tipus'           => 'ugyfel',
            'kivalto'         => 'kezi',
            'munkalap_id'     => 0,
            'eszkoz_id'       => 0,
            'ugyfel_id'       => 0,
            'felhasznalo'     => $felh,
            'felhasznalo_nev' => $user ? mb_substr((string) $user->display_name, 0, 120) : '',
            'szam'            => '',
            'szam_eredeti'    => '',
            'szam_forras'     => '',
            'sablon_id'       => 0,
            'sablon_verzio'   => 0,
            'szegmens'        => self::szegmensek($szoveg)['szegmens'],
            'allapot'         => 'varakozik',
            'hiba'            => '',
            'olvasva'         => 1,
            'parositva'       => 1,
            'letrehozva'      => current_time('mysql'),
        ], $adat);

        $sor['szoveg'] = $szoveg;
        $sor['hiba']   = mb_substr((string) $sor['hiba'], 0, 255);

        if (isset($sor['kulcs']) && $sor['kulcs'] === '') {
            unset($sor['kulcs']);
        }

        $suppress = $wpdb->suppress_errors(true);
        $ok       = $wpdb->insert(self::tabla('uzenet'), $sor);
        $wpdb->suppress_errors($suppress);

        if ($ok === false) {
            return 0;
        }

        $id = (int) $wpdb->insert_id;

        self::esemeny($id, 'allapot', (string) $sor['allapot'], 'Létrehozva', null, (int) $sor['felhasznalo']);

        return $id;
    }

    /** Egy esemény az sms_esemeny-ben. A `kulcs` egyedi: ugyanaz az esemény kétszer nem rögzül. */
    public static function esemeny(int $sms_id, string $tipus, string $allapot, string $reszlet = '', ?string $kulcs = null, ?int $felhasznalo = null): bool
    {
        global $wpdb;

        $sor = [
            'sms_id'      => $sms_id,
            'tipus'       => $tipus,
            'allapot'     => $allapot,
            'reszlet'     => mb_substr($reszlet, 0, 255),
            'felhasznalo' => $felhasznalo ?? get_current_user_id(),
            'letrehozva'  => current_time('mysql'),
        ];

        if ($kulcs !== null && $kulcs !== '') {
            $sor['kulcs'] = $kulcs;
        }

        $suppress = $wpdb->suppress_errors(true);
        $ok       = $wpdb->insert(self::tabla('esemeny'), $sor);
        $wpdb->suppress_errors($suppress);

        return $ok !== false;
    }

    /**
     * Állapotváltás: a sor jelenlegi állapota frissül, az átmenet új eseménysorként
     * kerül a naplóba (a korábbi sorok érintetlenek).
     *
     * @param array<string, mixed> $mezok A sor további mezői (pl. atjaro_id, elkuldve, proba).
     */
    public static function allapot_ir(int $sms_id, string $uj, string $reszlet = '', array $mezok = [], ?string $kulcs = null): bool
    {
        global $wpdb;

        $mezok['allapot'] = $uj;

        if ($reszlet !== '' && in_array($uj, ['failed', 'manualis', 'nincs_szam', 'kihagyva', 'letiltva', 'bizonytalan'], true)) {
            $mezok['hiba'] = mb_substr($reszlet, 0, 255);
        }

        if ($wpdb->update(self::tabla('uzenet'), $mezok, ['id' => $sms_id]) === false) {
            return false;
        }

        self::esemeny($sms_id, 'allapot', $uj, $reszlet, $kulcs);

        return true;
    }

    /**
     * Az átjáró állapotai csak előre lépnek (egy elkésett „sent” nem írja felül a „delivered”-et);
     * a „failed” a kézbesítés előtt bármikor érvényes.
     */
    public static function allapot_javul(string $regi, string $uj): bool
    {
        $rang = ['varakozik' => 0, 'kuldve_atjarora' => 1, 'pending' => 2, 'processed' => 3, 'sent' => 4, 'delivered' => 6];

        if ($uj === 'failed') {
            return $regi !== 'delivered' && $regi !== 'failed';
        }

        if (!isset($rang[$uj])) {
            return false;
        }

        return ($rang[$regi] ?? -1) < $rang[$uj];
    }

    /* =================================================================
     * Sor: küldés, újrapróbálás, állapotkövetés
     * ============================================================== */

    public const HOOK = 'sdh_muhely_sms_feldolgoz';
    private const ZAR = 'sdh_muhely_sms_zar';

    /** Az ütemező bekapcsolása vagy leállítása a főkapcsoló szerint. */
    public static function idozit(): void
    {
        $van = wp_next_scheduled(self::HOOK);

        if (self::bekapcsolva() && !$van) {
            wp_schedule_event(time() + 30, 'sdh_perc', self::HOOK);
        } elseif (!self::bekapcsolva() && $van) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /** „HH:MM” → percek éjféltől. */
    private static function percek(string $ido): int
    {
        return preg_match('/^(\d{1,2}):(\d{2})$/', $ido, $m) ? ((int) $m[1] * 60 + (int) $m[2]) : 0;
    }

    private static function ts(?string $mysql): int
    {
        if ($mysql === null || $mysql === '' || $mysql === '0000-00-00 00:00:00') {
            return 0;
        }

        try {
            return (new DateTimeImmutable($mysql, wp_timezone()))->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    private static function mysql(int $ts): string
    {
        return wp_date('Y-m-d H:i:s', $ts);
    }

    /** Ha a megadott időpont csendes órába esik: a csendes órák vége (időbélyeg), különben 0. */
    public static function csendes_vege(int $ts): int
    {
        $b = self::beallitas();

        if (!$b['csendes_be']) {
            return 0;
        }

        $tol = self::percek((string) $b['csendes_tol']);
        $ig  = self::percek((string) $b['csendes_ig']);

        if ($tol === $ig) {
            return 0;
        }

        $d    = (new DateTimeImmutable('@' . $ts))->setTimezone(wp_timezone());
        $most = (int) $d->format('G') * 60 + (int) $d->format('i');
        $bent = $tol < $ig ? ($most >= $tol && $most < $ig) : ($most >= $tol || $most < $ig);

        if (!$bent) {
            return 0;
        }

        $veg = $d->setTime(intdiv($ig, 60), $ig % 60, 0);

        if ($veg->getTimestamp() <= $ts) {
            $veg = $veg->modify('+1 day');
        }

        return $veg->getTimestamp();
    }

    /**
     * Új kimenő SMS a sorba. Soha nem küld azonnal a hívó szálán: a sor sorát rögzíti, a küldés
     * a kérés végén (shutdown) vagy a percenkénti ütemezőből történik – így az SMS-hiba sosem
     * akadályozza a státuszváltást.
     *
     * @param array<string, mixed> $a ugyfel_id, szoveg, kivalto (kezi|auto), munkalap_id, eszkoz_id,
     *                                sablon_id, sablon_verzio, kesleltetes (perc), kulcs, szam (kézi szám)
     * @return array{ok: bool, id: int, allapot: string, uzenet: string}
     */
    public static function sor_be(array $a): array
    {
        global $wpdb;

        $hiba = static fn (string $uzenet, int $id = 0, string $allapot = ''): array => ['ok' => false, 'id' => $id, 'allapot' => $allapot, 'uzenet' => $uzenet];

        if (!self::bekapcsolva()) {
            return $hiba('Az SMS-küldés ki van kapcsolva (Beállítások › SMS).');
        }

        $szoveg = trim((string) ($a['szoveg'] ?? ''));

        if ($szoveg === '') {
            return $hiba('Az SMS szövege üres.');
        }

        if (mb_strlen($szoveg) > SDH_Muhely_Sms_Szabaly::SZOVEG_MAX) {
            return $hiba('Az SMS túl hosszú (legfeljebb ' . SDH_Muhely_Sms_Szabaly::SZOVEG_MAX . ' karakter lehet, ennyi ' . mb_strlen($szoveg) . ').');
        }

        $kulcs = (string) ($a['kulcs'] ?? '');

        if ($kulcs !== '') {
            $van = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::tabla('uzenet') . ' WHERE kulcs = %s', $kulcs));

            if ($van > 0) {
                return ['ok' => true, 'id' => $van, 'allapot' => 'duplikalt', 'uzenet' => 'Ez az SMS már a sorban van.'];
            }
        }

        $kivalto   = (string) ($a['kivalto'] ?? 'kezi');
        $ugyfel_id = (int) ($a['ugyfel_id'] ?? 0);
        $alap      = [
            'kivalto'       => $kivalto,
            'munkalap_id'   => (int) ($a['munkalap_id'] ?? 0),
            'eszkoz_id'     => (int) ($a['eszkoz_id'] ?? 0),
            'ugyfel_id'     => $ugyfel_id,
            'sablon_id'     => (int) ($a['sablon_id'] ?? 0),
            'sablon_verzio' => (int) ($a['sablon_verzio'] ?? 0),
            'szoveg'        => $szoveg,
            'kulcs'         => $kulcs,
        ];

        if (isset($a['felhasznalo'])) {
            $alap['felhasznalo'] = (int) $a['felhasznalo'];
        }

        // Kézzel megadott szám (pl. önálló „Új SMS” ablak) vagy az ügyfél számai.
        $kezi_szam = trim((string) ($a['szam'] ?? ''));

        if ($kezi_szam !== '') {
            $n = self::szam_normalizal($kezi_szam);
            $n['forras'] = 'kezi';
            $szamok = [$n];
            $tilt   = false;
        } else {
            $sz     = self::ugyfel_szamai($ugyfel_id);
            $szamok = $sz['szamok'];
            $tilt   = $sz['tilt'];
        }

        if ($tilt && $kivalto === 'auto') {
            $id = self::naplo_uj($alap + ['szam' => '', 'allapot' => 'letiltva', 'hiba' => 'Az ügyfél nem kér SMS-t.']);

            return $hiba('Az ügyfél nem kér SMS-t, ezért nem küldtem.', $id, 'letiltva');
        }

        $jok = array_values(array_filter($szamok, static fn ($n) => !empty($n['ok'])));

        if ($jok === []) {
            $elso = $szamok[0] ?? ['eredeti' => '', 'uzenet' => 'Az ügyfélnek nincs telefonszáma.', 'forras' => ''];
            $id   = self::naplo_uj($alap + [
                'szam'         => '',
                'szam_eredeti' => (string) ($elso['eredeti'] ?? ''),
                'szam_forras'  => (string) ($elso['forras'] ?? ''),
                'allapot'      => 'nincs_szam',
                'hiba'         => (string) ($elso['uzenet'] ?? 'Nincs használható telefonszám.'),
            ]);

            return $hiba('Nincs használható telefonszám, az SMS nem ment ki: ' . (string) ($elso['uzenet'] ?? ''), $id, 'nincs_szam');
        }

        $most = time();
        $mikor = $most + max(0, (int) ($a['kesleltetes'] ?? 0)) * 60;

        if ($kivalto === 'auto') {
            $csend = self::csendes_vege($mikor);
            $mikor = $csend > 0 ? $csend : $mikor;
        }

        $id = self::naplo_uj($alap + [
            'szam'         => (string) $jok[0]['szam'],
            'szam_eredeti' => (string) $jok[0]['eredeti'],
            'szam_forras'  => (string) $jok[0]['forras'],
            'allapot'      => 'varakozik',
            'kovetkezo'    => self::mysql($mikor),
        ]);

        if ($id === 0) {
            // Egyedi kulcs ütközése (párhuzamos kérés) vagy adatbázishiba.
            $van = $kulcs !== '' ? (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::tabla('uzenet') . ' WHERE kulcs = %s', $kulcs)) : 0;

            return $van > 0
                ? ['ok' => true, 'id' => $van, 'allapot' => 'duplikalt', 'uzenet' => 'Ez az SMS már a sorban van.']
                : $hiba('Az SMS-t nem sikerült a sorba tenni.');
        }

        // A lap üzenetszálában is látszik (az RMA csevegőben „SMS” jelzéssel).
        if ((int) $alap['munkalap_id'] > 0 && $ugyfel_id > 0 && class_exists('SDH_Muhely_Rma')) {
            self::szalba($id, $alap, $szoveg, (int) ($a['uzenet_id'] ?? 0));
        }

        if ($mikor <= $most + 1) {
            self::kuld_hamarosan($id);
        }

        return ['ok' => true, 'id' => $id, 'allapot' => 'varakozik', 'uzenet' => $mikor > $most + 60 ? 'Az SMS be van ütemezve.' : 'Az SMS a sorban van, hamarosan kimegy.'];
    }

    /**
     * Az SMS bekerül a munkalap üzenetszálába (uzenet.sms_id köti a naplósorhoz). Ha a szálban már
     * van sor (a csevegőből „SMS-ben is” küldték), azt kötjük, nem készül második.
     *
     * @param array<string, mixed> $alap
     */
    private static function szalba(int $sms_id, array $alap, string $szoveg, int $uzenet_id): void
    {
        global $wpdb;

        if ($uzenet_id <= 0) {
            $felh = $alap['kivalto'] === 'auto' ? 0 : (int) ($alap['felhasznalo'] ?? get_current_user_id());

            $uzenet_id = SDH_Muhely_Rma::uzenet_ment((int) $alap['munkalap_id'], (int) $alap['ugyfel_id'], 'ki', 'sms', $szoveg, $felh, false, (int) $alap['eszkoz_id']);
        }

        if ($uzenet_id > 0) {
            $wpdb->update(SDH_Muhely_Schema::tabla('uzenet'), ['sms_id' => $sms_id], ['id' => $uzenet_id]);
        }
    }

    /**
     * Az SMS állapotának magyar neve és színe (monitor, szál, riasztások).
     *
     * @return array{0: string, 1: string} [felirat, szín]
     */
    public static function allapot_cimke(string $allapot): array
    {
        $t = [
            'varakozik'   => ['Várakozik', 'szurke'],
            'kuldes'      => ['Küldés alatt', 'kek'],
            'pending'     => ['Átadva a telefonnak', 'kek'],
            'processed'   => ['Feldolgozva', 'kek'],
            'sent'        => ['Elküldve', 'sarga'],
            'delivered'   => ['Kézbesítve', 'zold'],
            'failed'      => ['Nem ment ki', 'piros'],
            'manualis'    => ['Manuális teendő', 'piros'],
            'bizonytalan' => ['Bizonytalan', 'narancs'],
            'nincs_szam'  => ['Nincs használható szám', 'piros'],
            'letiltva'    => ['Leiratkozott', 'szurke'],
            'szimulalt'   => ['Szimulált', 'lila'],
            'kihagyva'    => ['Kihagyva', 'szurke'],
            'bejott'      => ['Beérkezett', 'kek'],
        ];

        return $t[$allapot] ?? [$allapot, 'szurke'];
    }

    /** Az SMS rövid állapotjelzése az üzenetszálhoz: [felirat, hiba-e]. */
    public static function szal_jelzo(int $sms_id): array
    {
        global $wpdb;

        $a = (string) $wpdb->get_var($wpdb->prepare('SELECT allapot FROM ' . self::tabla('uzenet') . ' WHERE id = %d', $sms_id));

        if ($a === '') {
            return ['SMS', false];
        }

        if (in_array($a, ['failed', 'manualis', 'bizonytalan', 'nincs_szam'], true)) {
            return ['SMS ✗ ' . mb_strtolower(self::allapot_cimke($a)[0]), true];
        }

        if ($a === 'delivered') {
            return ['SMS ✓ kézbesítve', false];
        }

        if (in_array($a, ['sent', 'pending', 'processed'], true)) {
            return ['SMS ✓ elküldve', false];
        }

        return ['SMS · ' . mb_strtolower(self::allapot_cimke($a)[0]), false];
    }

    /** A küldés a kérés végén, a válasz után: az SMS-hiba így sem lassítja, sem megakasztja a mentést. */
    private static function kuld_hamarosan(int $id): void
    {
        if (!apply_filters('sdh_muhely_sms_azonnal', true)) {
            return;
        }

        add_action('shutdown', static function () use ($id): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            try {
                self::kuld_sor($id);
            } catch (Throwable $e) {
                // Az ütemező a következő percben újra megnézi.
            }
        }, 20);
    }

    /**
     * A sor feldolgozása (percenként): állapotjelentés, esedékes küldések, állapotkövetés.
     *
     * @return array{kuldve: int, frissitve: int, zarva: bool}
     */
    public static function feldolgoz(): array
    {
        $ki = ['kuldve' => 0, 'frissitve' => 0, 'zarva' => false];

        if (!self::bekapcsolva()) {
            return $ki;
        }

        $zar = (int) get_option(self::ZAR, 0);

        if ($zar > 0 && time() - $zar < 120) {
            $ki['zarva'] = true;

            return $ki;
        }

        update_option(self::ZAR, time(), false);

        try {
            self::health_ha_kell();
            self::megszakadtak();
            $ki['kuldve']    = self::esedekesek_kuldese();
            $ki['frissitve'] = self::allapotok_kovetese();
        } catch (Throwable $e) {
            self::allapot_ment(['feldolgozo_hiba' => mb_substr($e->getMessage(), 0, 200), 'feldolgozo_hiba_ido' => current_time('mysql')]);
        } finally {
            delete_option(self::ZAR);
        }

        return $ki;
    }

    /** Az átjáró állapotának rögzítése (kapcsolat-teszt és időzített ellenőrzés közös). */
    public static function kapcsolat_jegyez(array $h): void
    {
        $a = self::allapot();

        if ($h['ok']) {
            self::allapot_ment(['atjaro_ok' => current_time('mysql'), 'atjaro_hiba' => '', 'hiba_db' => 0, 'hiba_kezdet' => '', 'health_ido' => time()]);

            return;
        }

        self::allapot_ment([
            'atjaro_hiba'     => (string) $h['hiba'],
            'atjaro_hiba_ido' => current_time('mysql'),
            'hiba_db'         => (int) ($a['hiba_db'] ?? 0) + 1,
            'hiba_kezdet'     => (string) ($a['hiba_kezdet'] ?? '') !== '' ? (string) $a['hiba_kezdet'] : current_time('mysql'),
            'health_ido'      => time(),
        ]);
    }

    private static function health_ha_kell(): void
    {
        $b = self::beallitas();
        $a = self::allapot();

        if (time() - (int) ($a['health_ido'] ?? 0) < (int) $b['health_perc'] * 60 - 5) {
            return;
        }

        self::kapcsolat_jegyez(self::health());
    }

    /** A „küldés” állapotban elakadt sorok: nem tudjuk, kiment-e – nem küldjük újra, jelezzük. */
    private static function megszakadtak(): void
    {
        global $wpdb;

        $hatar = self::mysql(time() - 180);
        $ids   = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::tabla('uzenet') . " WHERE allapot = 'kuldes' AND utolso_proba < %s LIMIT 20", $hatar));

        foreach ($ids as $id) {
            self::bizonytalan((int) $id, 'A küldés közben megszakadt a folyamat – lehet, hogy az SMS kiment. Ellenőrizd a telefonon.');
        }
    }

    private static function esedekesek_kuldese(): int
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . self::tabla('uzenet') . " WHERE irany = 'ki' AND allapot = 'varakozik' AND (kovetkezo IS NULL OR kovetkezo <= %s) ORDER BY id LIMIT 25",
            current_time('mysql')
        ));
        $db = 0;

        foreach ($ids as $id) {
            if (self::kuld_sor((int) $id) === 'atadva') {
                $db++;
            }
        }

        return $db;
    }

    private static function sor_adat(int $id): ?object
    {
        global $wpdb;

        $s = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla('uzenet') . ' WHERE id = %d', $id));

        return is_object($s) ? $s : null;
    }

    /**
     * Egy esedékes SMS elküldése. A sor először „küldés” állapotba kerül egyetlen feltételes
     * UPDATE-tel – párhuzamos futások közül csak az egyik viheti tovább, így nincs dupla SMS.
     *
     * @return string atadva | foglalt | elhalasztva | szimulalt | ujra | atiranyitva | hiba | bizonytalan | kihagyva
     */
    public static function kuld_sor(int $id): string
    {
        global $wpdb;

        $t    = self::tabla('uzenet');
        $most = current_time('mysql');
        $n    = $wpdb->query($wpdb->prepare(
            "UPDATE {$t} SET allapot = 'kuldes', proba = proba + 1, utolso_proba = %s WHERE id = %d AND allapot = 'varakozik' AND irany = 'ki'",
            $most,
            $id
        ));

        if ((int) $n !== 1) {
            return 'foglalt';
        }

        $sor = self::sor_adat($id);

        if (!$sor) {
            return 'foglalt';
        }

        $visszatesz = static function (string $mikor, string $reszlet, bool $proba_vissza = true) use ($id, $wpdb, $t): void {
            if ($proba_vissza) {
                $wpdb->query($wpdb->prepare("UPDATE {$t} SET proba = GREATEST(proba - 1, 0) WHERE id = %d", $id));
            }

            self::allapot_ir($id, 'varakozik', $reszlet, ['kovetkezo' => $mikor]);
        };

        $b = self::beallitas();

        if (!$b['be']) {
            $visszatesz(self::mysql(time() + 300), 'Az SMS-küldés ki van kapcsolva – várakozik.');

            return 'elhalasztva';
        }

        if ($sor->kivalto === 'auto') {
            $csend = self::csendes_vege(time());

            if ($csend > 0) {
                $visszatesz(self::mysql($csend), 'Csendes órák: a küldés reggelre halasztva.');

                return 'elhalasztva';
            }

            if ((int) $sor->ugyfel_id > 0 && self::ugyfel_szamai((int) $sor->ugyfel_id)['tilt']) {
                self::allapot_ir($id, 'letiltva', 'Az ügyfél közben leiratkozott.');

                return 'kihagyva';
            }
        }

        if ($b['szimulacio']) {
            self::allapot_ir($id, 'szimulalt', 'Szimulációs mód: nem ment ki.', ['kovetkezo' => null]);

            return 'szimulalt';
        }

        $v = self::atjaro_kuld((string) $sor->szam, (string) $sor->szoveg, 'sdh-' . $id . '-' . ((int) $sor->proba));

        if ($v['ok']) {
            self::allapot_ir($id, $v['allapot'], 'Átadva az átjárónak (' . (int) $sor->proba . '. próba).', [
                'atjaro_id' => $v['id'],
                'elkuldve'  => current_time('mysql'),
                'kovetkezo' => null,
                'hiba'      => '',
            ]);

            return 'atadva';
        }

        if ($v['tipus'] === 'bizonytalan') {
            self::bizonytalan($id, $v['hiba']);

            return 'bizonytalan';
        }

        if ($v['tipus'] === 'kerelem') {
            // Az átjáró ezt a számot / kérést véglegesen elutasította: nincs értelme újrapróbálni.
            return self::szam_sikertelen($sor, $v['hiba']);
        }

        return self::ujra_vagy_vege($sor, $v['hiba']);
    }

    /** Átmeneti hiba: újrapróbálás növekvő várakozással, a próbák elfogyása után manuális teendő. */
    private static function ujra_vagy_vege(object $sor, string $hiba, bool $csatorna = true): string
    {
        $b       = self::beallitas();
        $proba   = (int) $sor->proba;
        $osszes  = 1 + (int) $b['ujra_db'];

        if ($proba < $osszes) {
            $perc = (int) $b['ujra_perc'] * (2 ** max(0, $proba - 1));

            self::allapot_ir((int) $sor->id, 'varakozik', $proba . '. próba sikertelen, újra ' . $perc . ' perc múlva: ' . $hiba, [
                'kovetkezo' => self::mysql(time() + $perc * 60),
                'hiba'      => mb_substr($hiba, 0, 255),
            ]);

            return 'ujra';
        }

        return self::szam_sikertelen($sor, $hiba, $csatorna);
    }

    /**
     * Ez a szám nem működött: ha az 1. számról van szó és van 2. szám, oda megy tovább egy új
     * sorral; különben manuális teendő. A „csatorna” hibája (nem elérhető átjáró) nem számhiba,
     * ezért arra nincs 2. számos kitérő.
     *
     * @param bool $csatorna igaz, ha az átjáró nem volt elérhető (és nem a szám a hibás)
     */
    private static function szam_sikertelen(object $sor, string $hiba, bool $csatorna = false): string
    {
        if (!$csatorna && $sor->szam_forras === 'ugyfel1' && (int) $sor->ugyfel_id > 0) {
            foreach (self::ugyfel_szamai((int) $sor->ugyfel_id)['szamok'] as $n) {
                if (!empty($n['ok']) && $n['forras'] === 'ugyfel2' && $n['szam'] !== $sor->szam) {
                    $uj = self::naplo_uj([
                        'tipus'         => $sor->tipus,
                        'kivalto'       => $sor->kivalto,
                        'munkalap_id'   => (int) $sor->munkalap_id,
                        'eszkoz_id'     => (int) $sor->eszkoz_id,
                        'ugyfel_id'     => (int) $sor->ugyfel_id,
                        'felhasznalo'   => (int) $sor->felhasznalo,
                        'szam'          => $n['szam'],
                        'szam_eredeti'  => $n['eredeti'],
                        'szam_forras'   => 'ugyfel2',
                        'sablon_id'     => (int) $sor->sablon_id,
                        'sablon_verzio' => (int) $sor->sablon_verzio,
                        'szoveg'        => (string) $sor->szoveg,
                        'allapot'       => 'varakozik',
                        'kovetkezo'     => current_time('mysql'),
                        'kulcs'         => 'tovabb:' . (int) $sor->id,
                    ]);

                    $reszlet = 'Az 1. szám nem fogadta el (' . $hiba . ') – tovább a 2. számra' . ($uj > 0 ? ' (#' . $uj . ').' : '.');

                    if ($sor->allapot === 'failed') {
                        self::esemeny((int) $sor->id, 'allapot', 'failed', $reszlet);
                    } else {
                        self::allapot_ir((int) $sor->id, 'failed', $reszlet, ['kovetkezo' => null]);
                    }

                    if ($uj > 0) {
                        self::kuld_hamarosan($uj);
                    }

                    return 'atiranyitva';
                }
            }
        }

        self::allapot_ir((int) $sor->id, 'manualis', $hiba, ['kovetkezo' => null]);
        self::ertesit_hiba($sor, $hiba);

        return 'hiba';
    }

    private static function bizonytalan(int $id, string $hiba): void
    {
        self::allapot_ir($id, 'bizonytalan', $hiba, ['kovetkezo' => null]);

        $sor = self::sor_adat($id);

        if ($sor) {
            self::ertesit_hiba($sor, $hiba);
        }
    }

    /** Végleges hiba esetén egyszeri értesítő e-mail a szervizbe (soronként legfeljebb egy). */
    private static function ertesit_hiba(object $sor, string $hiba): void
    {
        if (!self::esemeny((int) $sor->id, 'ertesites', (string) $sor->allapot, 'Értesítő e-mail', 'ertesites:' . (int) $sor->id)) {
            return;
        }

        $b   = self::beallitas();
        $cel = sanitize_email((string) $b['riasztas_email']);

        if ($cel === '' && class_exists('SDH_Muhely_Rma')) {
            $cel = sanitize_email((string) SDH_Muhely_Rma::beallitas()['ertesites_email']);
        }

        if (!is_email($cel)) {
            $cel = (string) get_option('admin_email');
        }

        self::allapot_ment(['utolso_hiba_sms' => (int) $sor->id, 'utolso_hiba_ido' => current_time('mysql')]);

        if (!is_email($cel)) {
            return;
        }

        $ugyfel = '';

        if ((int) $sor->ugyfel_id > 0) {
            global $wpdb;
            $ugyfel = (string) $wpdb->get_var($wpdb->prepare('SELECT nev FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', (int) $sor->ugyfel_id));
        }

        $torzs = "Egy SMS nem ment ki, kézi teendő van vele.\n\n"
            . 'Ügyfél: ' . ($ugyfel !== '' ? $ugyfel : '–') . "\n"
            . 'Szám: ' . ($sor->szam !== '' ? $sor->szam : (string) $sor->szam_eredeti) . "\n"
            . 'Ok: ' . $hiba . "\n\n"
            . "Szöveg:\n" . (string) $sor->szoveg . "\n\n"
            . 'Hívd fel az ügyfelet, vagy küldd újra az SMS-t a CRM-ben.' . "\n";

        wp_mail($cel, 'SMS nem ment ki – kézi teendő', $torzs);
    }

    /**
     * Az átjárónak átadott SMS-ek állapotának követése (Pending → Sent → Delivered / Failed).
     * A webhook (bejövő jelzés) ezt csak gyorsítja, az alap a lekérdezés.
     */
    private static function allapotok_kovetese(): int
    {
        global $wpdb;

        $b     = self::beallitas();
        $hatar = self::mysql(time() - 3 * DAY_IN_SECONDS);
        $sorok = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::tabla('uzenet') . " WHERE irany = 'ki' AND atjaro_id <> '' AND allapot IN ('pending','processed','sent') AND letrehozva > %s ORDER BY id LIMIT 30",
            $hatar
        ));
        $db = 0;

        foreach ($sorok as $sor) {
            $v = self::atjaro_allapot((string) $sor->atjaro_id);

            if (!$v['ok']) {
                if (in_array($v['tipus'], ['elerhetetlen', 'idotullepes'], true)) {
                    break;
                }

                continue;
            }

            $uj = $v['allapot'];

            if ($uj === 'failed' && self::allapot_javul((string) $sor->allapot, 'failed')) {
                self::allapot_ir((int) $sor->id, 'failed', 'Az átjáró szerint nem ment ki' . ($v['hiba'] !== '' ? ': ' . $v['hiba'] : '.'), [], 'atjaro:' . (int) $sor->id . ':' . (string) $sor->atjaro_id . ':failed');
                $sor->allapot = 'failed';
                self::ujra_vagy_vege($sor, 'Az átjáró szerint nem ment ki' . ($v['hiba'] !== '' ? ': ' . $v['hiba'] : '.'), false);
                $db++;

                continue;
            }

            if ($uj !== 'ismeretlen' && self::allapot_javul((string) $sor->allapot, $uj)) {
                $mezok = $uj === 'delivered' ? ['kezbesitve' => current_time('mysql')] : [];

                self::allapot_ir((int) $sor->id, $uj, 'Az átjáró jelzése.', $mezok);
                $db++;

                continue;
            }

            // „Elküldve”, de a kézbesítési jelzés sokáig nem jön: egyszeri figyelmeztetés a naplóban.
            if ($sor->allapot === 'sent' && self::ts((string) $sor->elkuldve) > 0 && time() - self::ts((string) $sor->elkuldve) > (int) $b['kihagyott_perc'] * 60) {
                self::esemeny((int) $sor->id, 'jelzes', 'sent', 'Elküldve, de ' . (int) $b['kihagyott_perc'] . ' perce nincs kézbesítési jelzés.', 'nincs_jelzes:' . (int) $sor->id);
            }
        }

        return $db;
    }

    /* =================================================================
     * AJAX – kapcsolat-teszt, teszt SMS (csak adminisztrátor)
     * ============================================================== */

    private static function ajax_admin(): void
    {
        check_ajax_referer(self::NONCE, '_wpnonce');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Az SMS-beállításokhoz adminisztrátori jog kell.'], 403);
        }
    }

    public static function ajax_kapcsolat(): void
    {
        self::ajax_admin();

        $h = self::health();

        self::kapcsolat_jegyez($h);

        if ($h['ok']) {
            wp_send_json_success(['uzenet' => 'Az átjáró elérhető, a bejelentkezés rendben.']);
        }

        wp_send_json_error(['uzenet' => $h['hiba']]);
    }

    public static function ajax_teszt(): void
    {
        self::ajax_admin();

        $nyers = isset($_POST['szam']) ? sanitize_text_field(wp_unslash((string) $_POST['szam'])) : '';
        $v     = self::teszt_kuldes($nyers);

        if ($v['ok']) {
            wp_send_json_success(['uzenet' => $v['uzenet'], 'id' => $v['id']]);
        }

        wp_send_json_error(['uzenet' => $v['uzenet'], 'id' => $v['id']]);
    }

    /**
     * Teszt SMS a megadott (vagy a beállított teszt-) számra. A főkapcsolótól független, a
     * szimulációs módot tiszteli, és „onteszt” típusként naplózódik – a vevő-SMS-ektől külön.
     *
     * @return array{ok: bool, uzenet: string, id: int}
     */
    public static function teszt_kuldes(string $nyers, bool $zar_kell = true): array
    {
        $b     = self::beallitas();
        $nyers = $nyers !== '' ? $nyers : (string) $b['onteszt_szam'];
        $n     = self::szam_normalizal($nyers);

        if (!$n['ok']) {
            return ['ok' => false, 'uzenet' => $n['uzenet'], 'id' => 0];
        }

        $zar = 'sdh_sms_teszt_' . get_current_user_id();

        if ($zar_kell) {
            if (get_transient($zar)) {
                return ['ok' => false, 'uzenet' => 'Várj pár másodpercet két teszt SMS között.', 'id' => 0];
            }

            set_transient($zar, 1, 15);
        }

        $nev    = class_exists('SDH_Muhely_Rma') ? trim((string) SDH_Muhely_Rma::beallitas()['szerviz_nev']) : '';
        $szoveg = ($nev !== '' ? $nev . ': ' : '') . 'teszt SMS, ' . wp_date('Y.m.d. H:i') . '.';

        $id = self::naplo_uj([
            'tipus'        => 'onteszt',
            'kivalto'      => 'onteszt',
            'szam'         => $n['szam'],
            'szam_eredeti' => $n['eredeti'],
            'szam_forras'  => 'kezi',
            'szoveg'       => $szoveg,
            'allapot'      => 'varakozik',
        ]);

        if ($id === 0) {
            return ['ok' => false, 'uzenet' => 'A teszt SMS-t nem sikerült naplózni, ezért nem küldtem el.', 'id' => 0];
        }

        if ($b['szimulacio']) {
            self::allapot_ir($id, 'szimulalt', 'Szimulációs mód: nem ment ki.');

            return ['ok' => true, 'uzenet' => 'Szimulációs mód: a teszt SMS-t nem küldtem el, csak naplóztam.', 'id' => $id];
        }

        $v = self::atjaro_kuld($n['szam'], $szoveg);

        if (!$v['ok']) {
            self::allapot_ir($id, 'failed', $v['hiba'], ['proba' => 1, 'utolso_proba' => current_time('mysql')]);

            return ['ok' => false, 'uzenet' => $v['hiba'], 'id' => $id];
        }

        self::allapot_ir($id, $v['allapot'], 'Átadva az átjárónak.', [
            'atjaro_id'    => $v['id'],
            'proba'        => 1,
            'utolso_proba' => current_time('mysql'),
            'elkuldve'     => current_time('mysql'),
        ]);

        return ['ok' => true, 'uzenet' => 'A teszt SMS átadva az átjárónak (' . $n['szam'] . '). Néhány másodperc múlva meg kell érkeznie.', 'id' => $id];
    }

    /* =================================================================
     * Beállítások › SMS
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $b     = self::beallitas();
        $a     = self::allapot();
        $nonce = wp_create_nonce(self::NONCE);
        $van   = is_array(get_option(self::OPT, null)) && (string) (get_option(self::OPT)['jelszo'] ?? '') !== '';

        ?>
        <div class="sdh-doboz" id="sms-fo">
            <h2 class="sdh-doboz__cim">SMS-értesítések</h2>
            <p class="sdh-sugo">
                Az SMS-t egy Android telefon küldi az „SMS Gateway for Android” (sms-gate.app) appon át. A telefon
                üzemeltetési feltételei: <strong>fix IP-cím</strong> (DHCP-foglalás a routeren), az appnál az
                <strong>akkumulátor-optimalizálás kikapcsolva</strong>, a telefon <strong>töltőn van</strong>, és az app
                <strong>Local Server</strong> (vagy Cloud Server) kapcsolója <strong>Online</strong>.
            </p>

            <?php if (!SDH_Muhely_Titok::elerheto()) : ?>
                <div class="sdh-uzenet sdh-uzenet--hiba">A szerveren nincs titkosító (sodium) – az átjáró jelszava ezért nem titkosítva tárolódik.</div>
            <?php endif; ?>

            <input type="hidden" name="sms_jelen" value="1">

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="sms_be" id="sms_be" value="1" <?php checked($b['be']); ?>>
                <label for="sms_be">Az SMS-küldés be van kapcsolva (főkapcsoló)</label>
            </div>
            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="sms_szimulacio" id="sms_szimulacio" value="1" <?php checked($b['szimulacio']); ?>>
                <label for="sms_szimulacio">Szimulációs mód: nem küld, csak naplóz (kipróbáláshoz)</label>
            </div>
        </div>

        <div class="sdh-doboz" id="sms-atjaro">
            <h2 class="sdh-doboz__cim">Átjáró</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="sms_mod">Üzemmód</label>
                    <select name="sms_mod" id="sms_mod">
                        <option value="helyi" <?php selected($b['mod'], 'helyi'); ?>>Helyi szerver (a CRM a helyi hálón éri el a telefont)</option>
                        <option value="felho" <?php selected($b['mod'], 'felho'); ?>>Nyilvános felhő (api.sms-gate.app – bárhonnan működik)</option>
                        <option value="privat" <?php selected($b['mod'], 'privat'); ?>>Privát szerver (saját átjáró-szerver, egyéni cím)</option>
                    </select>
                    <span class="sdh-mezo__sugo">Szerveren futó CRM-nél a telefon helyi címe nem érhető el: ott felhő vagy privát szerver kell.</span>
                </div>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="sms_cim">Átjáró címe</label>
                    <input type="text" name="sms_cim" id="sms_cim" autocomplete="off" placeholder="pl. a telefon IP-címe, vagy a privát szerver címe"
                           value="<?php echo esc_attr((string) $b['cim']); ?>">
                    <span class="sdh-mezo__sugo">Felhő módban nem kell kitölteni.</span>
                </div>
                <div class="sdh-mezo">
                    <label for="sms_port">Port</label>
                    <input type="number" name="sms_port" id="sms_port" min="0" max="65535" value="<?php echo (int) $b['port']; ?>">
                    <span class="sdh-mezo__sugo">Csak helyi módban (az app alapértelmezése: 8080).</span>
                </div>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="sms_felhasznalo">Felhasználónév</label>
                    <input type="text" name="sms_felhasznalo" id="sms_felhasznalo" autocomplete="off" value="<?php echo esc_attr((string) $b['felhasznalo']); ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="sms_jelszo">Jelszó</label>
                    <input type="password" name="sms_jelszo" id="sms_jelszo" autocomplete="new-password"
                           placeholder="<?php echo esc_attr($van ? '•••••••• (mentve – csak akkor írd be, ha cserélni akarod)' : ''); ?>" value="">
                    <?php if ($van) : ?>
                        <label class="sdh-mezo__sugo"><input type="checkbox" name="sms_jelszo_torol" value="1"> A mentett jelszó törlése</label>
                    <?php endif; ?>
                    <span class="sdh-mezo__sugo">Titkosítva tárolódik, nem látszik a naplóban és a böngészőben.</span>
                </div>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="sms_idotullepes">Időtúllépés (másodperc)</label>
                    <input type="number" name="sms_idotullepes" id="sms_idotullepes" min="2" max="60" value="<?php echo (int) $b['idotullepes']; ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="sms_orszagkod">Alapértelmezett országkód</label>
                    <input type="text" name="sms_orszagkod" id="sms_orszagkod" maxlength="5" value="<?php echo esc_attr((string) $b['orszagkod']); ?>">
                    <span class="sdh-mezo__sugo">A 06-tal vagy országkód nélkül megadott számokhoz (alap: +36).</span>
                </div>
            </div>

            <p>
                <button type="button" class="sdh-gomb" data-sdh-sms-akcio="sdh_muhely_sms_kapcsolat" data-nonce="<?php echo esc_attr($nonce); ?>">Kapcsolat-teszt</button>
                <span class="sdh-sugo" data-sdh-sms-uzenet="kapcsolat" aria-live="polite">
                    <?php
                    if (!empty($a['atjaro_ok'])) {
                        echo esc_html('Utolsó sikeres kapcsolat: ' . $a['atjaro_ok']);
                    }
                    ?>
                </span>
            </p>
            <p class="sdh-sugo">A kapcsolat-tesztet és a teszt SMS-t a MENTETT beállításokkal végzi – előbb mentsd el a lapot.</p>
        </div>

        <div class="sdh-doboz" id="sms-teszt">
            <h2 class="sdh-doboz__cim">Teszt SMS</h2>
            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="sms_onteszt_szam">Telefonszám</label>
                    <input type="text" name="sms_onteszt_szam" id="sms_onteszt_szam" inputmode="tel" autocomplete="off"
                           placeholder="pl. a bolti telefon saját száma" value="<?php echo esc_attr((string) $b['onteszt_szam']); ?>">
                    <span class="sdh-mezo__sugo">Ide megy a teszt SMS és a napi önteszt-SMS is (a telefon saját száma jó).</span>
                </div>
            </div>
            <p>
                <button type="button" class="sdh-gomb" data-sdh-sms-akcio="sdh_muhely_sms_teszt" data-sdh-sms-szam="sms_onteszt_szam"
                        data-nonce="<?php echo esc_attr($nonce); ?>">Teszt SMS küldése</button>
                <span class="sdh-sugo" data-sdh-sms-uzenet="teszt" aria-live="polite"></span>
            </p>
        </div>

        <div class="sdh-doboz" id="sms-ujra">
            <h2 class="sdh-doboz__cim">Újrapróbálás és csendes órák</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="sms_ujra_db">Újrapróbálkozások száma</label>
                    <input type="number" name="sms_ujra_db" id="sms_ujra_db" min="0" max="10" value="<?php echo (int) $b['ujra_db']; ?>">
                    <span class="sdh-mezo__sugo">Ha minden próba elbukik, az SMS „Manuális teendő” lesz.</span>
                </div>
                <div class="sdh-mezo">
                    <label for="sms_ujra_perc">Első újrapróbálás (perc múlva)</label>
                    <input type="number" name="sms_ujra_perc" id="sms_ujra_perc" min="1" max="240" value="<?php echo (int) $b['ujra_perc']; ?>">
                    <span class="sdh-mezo__sugo">Minden további próba előtt a várakozás megduplázódik.</span>
                </div>
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="sms_csendes_be" id="sms_csendes_be" value="1" <?php checked($b['csendes_be']); ?>>
                <label for="sms_csendes_be">Csendes órák: ilyenkor az automatikus SMS nem megy ki, hanem sorban vár, és reggel indul</label>
            </div>
            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="sms_csendes_tol">Csendes órák kezdete</label>
                    <input type="text" name="sms_csendes_tol" id="sms_csendes_tol" maxlength="5" placeholder="óó:pp" value="<?php echo esc_attr((string) $b['csendes_tol']); ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="sms_csendes_ig">Csendes órák vége</label>
                    <input type="text" name="sms_csendes_ig" id="sms_csendes_ig" maxlength="5" placeholder="óó:pp" value="<?php echo esc_attr((string) $b['csendes_ig']); ?>">
                </div>
            </div>
            <p class="sdh-sugo">A kézzel (popupból vagy az Új SMS ablakból) küldött SMS-re a csendes óra nem vonatkozik – azt te döntöd el.</p>
        </div>

        <div class="sdh-doboz" id="sms-szoveg">
            <h2 class="sdh-doboz__cim">Szöveg és figyelés</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="sms_alairas">Aláírás / lábléc</label>
                    <input type="text" name="sms_alairas" id="sms_alairas" maxlength="160" autocomplete="off" value="<?php echo esc_attr((string) $b['alairas']); ?>">
                    <span class="sdh-mezo__sugo">Minden automatikus SMS végére kerül. Ékezetes szöveg UCS-2: 70 karakter / SMS (hosszabbnál 67). Rövid aláírás kevesebb szegmenst jelent.</span>
                </div>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="sms_health_perc">Átjáró-ellenőrzés gyakorisága (perc)</label>
                    <input type="number" name="sms_health_perc" id="sms_health_perc" min="1" max="60" value="<?php echo (int) $b['health_perc']; ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="sms_kihagyott_perc">„Nincs kézbesítési jelzés” ennyi perc után</label>
                    <input type="number" name="sms_kihagyott_perc" id="sms_kihagyott_perc" min="5" max="1440" value="<?php echo (int) $b['kihagyott_perc']; ?>">
                </div>
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="sms_onteszt_be" id="sms_onteszt_be" value="1" <?php checked($b['onteszt_be']); ?>>
                <label for="sms_onteszt_be">Napi önteszt: naponta egy SMS a fenti teszt-számra (küldést, kézbesítést, bejövő webhookot ellenőriz)</label>
            </div>
            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="sms_onteszt_ido">Az önteszt időpontja</label>
                    <input type="text" name="sms_onteszt_ido" id="sms_onteszt_ido" maxlength="5" placeholder="óó:pp" value="<?php echo esc_attr((string) $b['onteszt_ido']); ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="sms_riasztas_email">Riasztás e-mail-címe</label>
                    <input type="email" name="sms_riasztas_email" id="sms_riasztas_email" autocomplete="off" value="<?php echo esc_attr((string) $b['riasztas_email']); ?>">
                    <span class="sdh-mezo__sugo">Ide megy az e-mail, ha az SMS-csatorna kiesik. Üresen az ügyféloldal értesítési e-mailje.</span>
                </div>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['sms_jelen'])) {
            return;
        }

        $r  = static fn (string $k): string => isset($_POST[$k]) && is_scalar($_POST[$k]) ? trim(sanitize_text_field(wp_unslash((string) $_POST[$k]))) : '';
        $ido = static function (string $ertek, string $alap): string {
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ertek) === 1 ? $ertek : $alap;
        };
        $szam = static fn (string $k, int $min, int $max, int $alap): int => isset($_POST[$k]) ? max($min, min($max, (int) $_POST[$k])) : $alap;

        $regi = self::beallitas();
        $mod  = $r('sms_mod');
        $cim  = $r('sms_cim');

        // Csak http(s) cím vagy cím séma nélkül; semmi más protokoll.
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $cim) === 1 && preg_match('#^https?://#i', $cim) !== 1) {
            $cim = '';
        }

        $jelszo_uj = isset($_POST['sms_jelszo']) && is_scalar($_POST['sms_jelszo']) ? trim((string) wp_unslash($_POST['sms_jelszo'])) : '';
        $mentett   = get_option(self::OPT, []);
        $tarolt    = is_array($mentett) && is_string($mentett['jelszo'] ?? null) ? (string) $mentett['jelszo'] : '';

        if (!empty($_POST['sms_jelszo_torol'])) {
            $jelszo = '';
        } elseif ($jelszo_uj !== '') {
            $jelszo = SDH_Muhely_Titok::titkosit($jelszo_uj);
        } else {
            $jelszo = $tarolt; // marad, ami volt (akkor is, ha ezen a telepítésen épp nem olvasható)
        }

        $orszag = $r('sms_orszagkod');
        $orszag = preg_match('/^\+[1-9]\d{0,3}$/', $orszag) === 1 ? $orszag : '+36';

        $email = sanitize_email($r('sms_riasztas_email'));

        update_option(self::OPT, [
            'be'             => !empty($_POST['sms_be']),
            'szimulacio'     => !empty($_POST['sms_szimulacio']),
            'mod'            => in_array($mod, ['helyi', 'felho', 'privat'], true) ? $mod : 'helyi',
            'cim'            => $cim,
            'port'           => $szam('sms_port', 0, 65535, (int) $regi['port']),
            'felhasznalo'    => mb_substr($r('sms_felhasznalo'), 0, 120),
            'jelszo'         => $jelszo,
            'idotullepes'    => $szam('sms_idotullepes', 2, 60, (int) $regi['idotullepes']),
            'ujra_db'        => $szam('sms_ujra_db', 0, 10, (int) $regi['ujra_db']),
            'ujra_perc'      => $szam('sms_ujra_perc', 1, 240, (int) $regi['ujra_perc']),
            'csendes_be'     => !empty($_POST['sms_csendes_be']),
            'csendes_tol'    => $ido($r('sms_csendes_tol'), (string) $regi['csendes_tol']),
            'csendes_ig'     => $ido($r('sms_csendes_ig'), (string) $regi['csendes_ig']),
            'alairas'        => mb_substr($r('sms_alairas'), 0, 160),
            'orszagkod'      => $orszag,
            'health_perc'    => $szam('sms_health_perc', 1, 60, (int) $regi['health_perc']),
            'onteszt_be'     => !empty($_POST['sms_onteszt_be']),
            'onteszt_ido'    => $ido($r('sms_onteszt_ido'), (string) $regi['onteszt_ido']),
            'onteszt_szam'   => mb_substr($r('sms_onteszt_szam'), 0, 40),
            'riasztas_email' => is_email($email) ? $email : '',
            'kihagyott_perc' => $szam('sms_kihagyott_perc', 5, 1440, (int) $regi['kihagyott_perc']),
        ], false);
        // phpcs:enable
    }
}
