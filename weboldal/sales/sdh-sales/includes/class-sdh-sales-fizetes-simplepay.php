<?php
/**
 * SDH Értékesítés – SimplePay (OTP Mobil) API v2 (alapból KIKAPCSOLVA, alapból sandbox).
 *
 * Forrás: SimplePay fejlesztői oldal https://simplepay.hu/fejlesztoknek/ (2022-11-01 óta új regisztrációnál csak v2),
 * „SimplePay Online Payment System – v2.1” dokumentáció és PHP SDK (simplepartner.hu, target=v21dochu / v21sdk).
 * Lényeg: minden kérés JSON; `Signature` fejléc = base64(HMAC-SHA384(törzs, SECRET_KEY)); a válasz `Signature` fejléce
 * ugyanígy ellenőrzendő. Indítás: POST /payment/v2/start → paymentUrl, transactionId. IPN: POST JSON (orderRef, transactionId,
 * status = FINISHED…) aláírva; válasz: ugyanaz a JSON + `receiveDate` (ISO 8601), a válasz törzsének aláírásával.
 * Visszairányítás: ?r=base64(JSON {r, t, e: SUCCESS|FAIL|CANCEL|TIMEOUT, m, o})&s=aláírás (a dekódolt JSON-ra).
 * Lekérdezés: POST /payment/v2/query {merchant, transactionIds[], salt} → transactions[] {status, total, orderRef}.
 * Az IPN-ben nincs összeg → a fizetést a query API-val igazoljuk (összeg + orderRef), csak utána rögzítünk.
 *
 * Beállítások: fizetes.simplepay.aktiv (bool, alap: ki), fizetes.simplepay.eles (bool, alap: sandbox),
 * fizetes.simplepay.merchant (kereskedői azonosító); titok: `simplepay_secret`.
 *
 *   aktiv() · alap() · alairas(string $test): string · alairas_jo(string $test, string $alairas): bool
 *   indit(array $rendeles): array{ok, url?, azon?, uzenet?} · allapot(string $transaction_id): array{allapot, tranzakcio, osszeg, rendeles, nyers}
 *   vissza(string $r, string $s): ?array{esemeny, rendeles, tranzakcio}
 * REST: POST /fizetes/simplepay/ipn · GET /fizetes/simplepay/vissza?r=&s= (a pénztár-oldal hívja a visszatéréskor).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Fizetes_Simplepay
{
    public const SANDBOX = 'https://sandbox.simplepay.hu/payment/v2';
    public const ELES    = 'https://secure.simplepay.hu/payment/v2';

    private const ALLAPOT = [
        'FINISHED'      => 'fizetve',
        'INIT'          => 'fuggo',
        'INPAYMENT'     => 'fuggo',
        'INFRAUD'       => 'fuggo',
        'AUTHORIZED'    => 'fuggo',
        'TIMEOUT'       => 'sikertelen',
        'CANCELLED'     => 'sikertelen',
        'NOTAUTHORIZED' => 'sikertelen',
        'FRAUD'         => 'sikertelen',
        'REVERSED'      => 'sikertelen',
        'REFUND'        => 'ismeretlen',
    ];

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/fizetes/simplepay/ipn', 'POST', [self::class, 'rest_ipn'], 'nyilvanos', ['simplepay_ipn', 120, 60]);
        SDH_Sales_Rest::ut('/fizetes/simplepay/vissza', 'GET', [self::class, 'rest_vissza'], 'nyilvanos', ['simplepay_vissza', 30, 60]);
    }

    public static function aktiv(): bool
    {
        return (bool) SDH_Sales::b('fizetes.simplepay.aktiv', false) && SDH_Sales::titkos('simplepay_secret') !== '' && trim((string) SDH_Sales::b('fizetes.simplepay.merchant', '')) !== '';
    }

    public static function alap(): string
    {
        return (bool) SDH_Sales::b('fizetes.simplepay.eles', false) ? self::ELES : self::SANDBOX;
    }

    public static function alairas(string $test): string
    {
        return base64_encode(hash_hmac('sha384', $test, SDH_Sales::titkos('simplepay_secret'), true));
    }

    public static function alairas_jo(string $test, string $alairas): bool
    {
        return $alairas !== '' && SDH_Sales::titkos('simplepay_secret') !== '' && hash_equals(self::alairas($test), trim($alairas));
    }

    private static function salt(): string
    {
        return substr(bin2hex(random_bytes(16)), 0, 32);
    }

    /**
     * Aláírt kérés; a válasz aláírását is ellenőrzi.
     *
     * @param array<string, mixed> $adat
     * @return array<string, mixed>|null
     */
    private static function keres(string $ut, array $adat, string &$hiba = ''): ?array
    {
        $test = (string) wp_json_encode($adat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $v    = SDH_Sales_Fizetes::json_keres(self::alap() . $ut, null, ['Signature' => self::alairas($test)], 'POST', $test);

        if ($v['kod'] !== 200 || !is_array($v['json'])) {
            $hiba = $v['hiba'] !== '' ? $v['hiba'] : 'HTTP ' . $v['kod'];

            return null;
        }

        if (!self::alairas_jo($v['test'], (string) ($v['fejlec']['signature'] ?? ''))) {
            $hiba = 'a válasz aláírása hibás';

            return null;
        }

        if (!empty($v['json']['errorCodes'])) {
            $hiba = 'hibakód ' . implode(', ', array_map('strval', (array) $v['json']['errorCodes']));

            return null;
        }

        return $v['json'];
    }

    /** @param array<string, mixed> $r @return array{ok: bool, url?: string, azon?: string, uzenet?: string} */
    public static function indit(array $r): array
    {
        $v    = (array) $r['vevo'];
        $adat = [
            'salt'          => self::salt(),
            'merchant'      => trim((string) SDH_Sales::b('fizetes.simplepay.merchant', '')),
            'orderRef'      => (string) $r['azon'],
            'currency'      => 'HUF',
            'customerEmail' => (string) $r['email'],
            'language'      => 'HU',
            'sdkVersion'    => 'Portal_PHP:1.0',
            'methods'       => ['CARD'],
            'total'         => max(0, (int) $r['brutto'] - (int) $r['fizetett']),
            'timeout'       => gmdate('Y-m-d\TH:i:sP', time() + 30 * MINUTE_IN_SECONDS),
            'url'           => SDH_Sales_Rendeles::statusz_url($r),
            'invoice'       => [
                'name'    => mb_substr((string) ($v['ceg'] ?? ''), 0, 100),
                'company' => mb_substr((string) ($v['ceg'] ?? ''), 0, 100),
                'country' => strtolower((string) ($v['orszag'] ?? 'HU')),
                'city'    => mb_substr((string) ($v['telepules'] ?? ''), 0, 60),
                'zip'     => mb_substr((string) ($v['irsz'] ?? ''), 0, 12),
                'address' => mb_substr((string) ($v['utca'] ?? ''), 0, 100),
                'phone'   => mb_substr((string) ($v['telefon'] ?? ''), 0, 30),
            ],
        ];

        $hiba = '';
        $j    = self::keres('/start', $adat, $hiba);

        if ($j === null || empty($j['paymentUrl']) || empty($j['transactionId'])) {
            return ['ok' => false, 'uzenet' => 'SimplePay: ' . ($hiba !== '' ? $hiba : 'hiányos válasz')];
        }

        return ['ok' => true, 'url' => esc_url_raw((string) $j['paymentUrl']), 'azon' => (string) (int) $j['transactionId']];
    }

    /** @return array{allapot: string, tranzakcio: string, osszeg: int, rendeles: string, nyers: array<string, mixed>} */
    public static function allapot(string $tid): array
    {
        $ures = ['allapot' => 'ismeretlen', 'tranzakcio' => '', 'osszeg' => 0, 'rendeles' => '', 'nyers' => []];

        if (preg_match('/^\d{1,20}$/', $tid) !== 1) {
            return $ures;
        }

        $hiba = '';
        $j    = self::keres('/query', ['salt' => self::salt(), 'merchant' => trim((string) SDH_Sales::b('fizetes.simplepay.merchant', '')), 'transactionIds' => [$tid]], $hiba);
        $t    = null;

        foreach ((array) ($j['transactions'] ?? []) as $x) {
            if (is_array($x) && (string) ($x['transactionId'] ?? '') === $tid) {
                $t = $x;
            }
        }

        if ($t === null) {
            return $ures;
        }

        $st = strtoupper((string) ($t['status'] ?? ''));

        return [
            'allapot'    => self::ALLAPOT[$st] ?? 'ismeretlen',
            'tranzakcio' => 'simplepay:' . $tid,
            'osszeg'     => (int) round((float) ($t['total'] ?? 0)),
            'rendeles'   => (string) ($t['orderRef'] ?? ''),
            'ok'         => $st,
            'nyers'      => ['status' => $st, 'total' => $t['total'] ?? null, 'orderRef' => $t['orderRef'] ?? '', 'transactionId' => $tid],
        ];
    }

    /** A visszairányítás r/s paraméterei. @return array{esemeny: string, rendeles: string, tranzakcio: string}|null */
    public static function vissza(string $r, string $s): ?array
    {
        $json = base64_decode(strtr($r, '-_', '+/'), true);

        if ($json === false || !self::alairas_jo($json, $s)) {
            return null;
        }

        $d = json_decode($json, true);

        if (!is_array($d) || (string) ($d['m'] ?? '') !== trim((string) SDH_Sales::b('fizetes.simplepay.merchant', ''))) {
            return null;
        }

        return ['esemeny' => strtoupper((string) ($d['e'] ?? '')), 'rendeles' => (string) ($d['o'] ?? ''), 'tranzakcio' => (string) ($d['t'] ?? '')];
    }

    /** IPN: aláírás → orderRef → a mi tranzakciónk → query API (összeg) → fizetve(); válasz receiveDate-tel, aláírva. */
    public static function rest_ipn(WP_REST_Request $q)
    {
        $test = (string) $q->get_body();

        if (!self::aktiv() || !self::alairas_jo($test, (string) $q->get_header('signature'))) {
            SDH_Sales_Naplo::ir('fizetes', 'SimplePay IPN elutasítva: hibás aláírás.', 'figyel');

            return SDH_Sales_Rest::hiba('Tiltott.', 403, 'alairas');
        }

        $d = json_decode($test, true);

        if (!is_array($d) || empty($d['orderRef']) || empty($d['transactionId'])) {
            return SDH_Sales_Rest::hiba('Hibás kérés.', 400, 'keres');
        }

        $tid = (string) $d['transactionId'];
        $r   = SDH_Sales_Rendeles::azon_szerint((string) $d['orderRef']);

        if ($r !== null && in_array($tid, SDH_Sales_Fizetes::kartya_azonok($r), true)) {
            $a = self::allapot($tid);

            if ($a['allapot'] === 'ismeretlen' && strtoupper((string) ($d['status'] ?? '')) === 'FINISHED') {
                SDH_Sales_Naplo::ir('fizetes', 'SimplePay IPN: a lekérdezés nem igazolta (' . (string) $r['azon'] . ') – a cron újra megnézi.', 'figyel', 'rendeles', (int) $r['id']);
            } else {
                SDH_Sales_Fizetes::kapu_eredmeny($r, 'simplepay', $a);
            }
        } else {
            SDH_Sales_Naplo::ir('fizetes', 'SimplePay IPN: ismeretlen tranzakció (' . mb_substr((string) $d['orderRef'], 0, 40) . ').', 'figyel');
        }

        $d['receiveDate'] = wp_date('Y-m-d\TH:i:sP');
        $ki               = (string) wp_json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $v                = SDH_Sales_Rendeles::nyers($ki, 'application/json; charset=utf-8');
        $v->header('Signature', self::alairas($ki));

        return $v;
    }

    public static function rest_vissza(WP_REST_Request $q)
    {
        $v = self::vissza((string) $q->get_param('r'), (string) $q->get_param('s'));

        if ($v === null) {
            return SDH_Sales_Rest::hiba(__('A visszatérési adatok nem ellenőrizhetők.', 'sdh-sales'), 400, 'vissza');
        }

        $r = SDH_Sales_Rendeles::azon_szerint($v['rendeles']);

        // A sikertelen-visszatérés csak a rendelés AKTUÁLIS tranzakciójára hat (egy korábbi, aláírt FAIL-link újrajátszása
        // nem buktatja el a folyamatban lévő új fizetést).
        $aktualis = $r !== null ? (string) ($r['vevo']['_kartya']['azon'] ?? '') : '';

        if ($r !== null && in_array($v['esemeny'], ['FAIL', 'CANCEL', 'TIMEOUT'], true) && $aktualis !== '' && hash_equals($aktualis, (string) $v['tranzakcio'])) {
            $a = self::allapot($v['tranzakcio']);

            if ($a['allapot'] !== 'fizetve') {
                SDH_Sales_Fizetes::kartya_sikertelen((int) $r['id'], ['FAIL' => __('A bank elutasította a fizetést.', 'sdh-sales'), 'CANCEL' => __('A fizetést megszakítottad.', 'sdh-sales'), 'TIMEOUT' => __('Lejárt a fizetésre szánt idő.', 'sdh-sales')][$v['esemeny']]);
            }
        } elseif ($r !== null && $v['esemeny'] === 'SUCCESS') {
            SDH_Sales_Fizetes::kartya_ellenoriz((int) $r['id']);
        }

        $uz = [
            'SUCCESS' => __('Sikeres fizetés. A visszaigazolást e-mailben is megkapod.', 'sdh-sales'),
            'FAIL'    => __('Sikertelen fizetés. Ellenőrizd a kártya adatait, vagy próbáld másik kártyával.', 'sdh-sales'),
            'CANCEL'  => __('A fizetést megszakítottad.', 'sdh-sales'),
            'TIMEOUT' => __('Lejárt a fizetésre szánt idő.', 'sdh-sales'),
        ];

        return new WP_REST_Response(['esemeny' => $v['esemeny'], 'uzenet' => $uz[$v['esemeny']] ?? '', 'tranzakcio' => $v['tranzakcio']], 200);
    }
}
