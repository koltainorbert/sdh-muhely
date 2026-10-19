<?php
/**
 * SDH Értékesítés – Barion Smart Gateway (alapból KIKAPCSOLVA, alapból teszt-környezet).
 *
 * Források (2026-10-19):
 *   Payment/Start v2:          https://docs.barion.com/Payment-Start-v2   (POST /v2/Payment/Start, POSKey a törzsben)
 *   Fizetés állapota v4:       https://docs.barion.com/Payment-Details-v4 (GET /v4/payment/{PaymentId}/paymentstate,
 *                              `x-pos-key` fejléc) – „the preferred API endpoint for querying the state of a payment”
 *   Callback:                  https://docs.barion.com/Callback_mechanism (POST a CallbackUrl-re a PaymentId-vel;
 *                              15 mp-en belül 200 OK; utána a PaymentState-tel kell ellenőrizni)
 * A callback nincs aláírva → mindig a Barion API-tól kérdezzük le az állapotot, és csak a saját indításunk
 * PaymentId-jére, a saját PaymentRequestId-nkkel (= rendelésazonosító) és a teljes összeggel rögzítünk fizetést.
 *
 * Beállítások: fizetes.barion.aktiv (bool, alap: ki), fizetes.barion.eles (bool, alap: teszt), fizetes.barion.payee
 * (a Barion-tárca e-mail-címe); titok: `barion_poskey`.
 *
 *   aktiv(): bool · api_alap(): string · indit(array $rendeles): array{ok, url?, azon?, uzenet?}
 *   allapot(string $payment_id): array{allapot, tranzakcio, osszeg, rendeles, nyers}
 * REST: POST /fizetes/barion/ipn (nyilvános, korlátozott).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Fizetes_Barion
{
    public const TESZT = 'https://api.test.barion.com';
    public const ELES  = 'https://api.barion.com';

    /** Barion PaymentStatus → saját állapot. */
    private const ALLAPOT = [
        'Succeeded'          => 'fizetve',
        'Prepared'           => 'fuggo',
        'Started'            => 'fuggo',
        'InProgress'         => 'fuggo',
        'Waiting'            => 'fuggo',
        'Reserved'           => 'fuggo',
        'Authorized'         => 'fuggo',
        'PartiallySucceeded' => 'fuggo',
        'Canceled'           => 'sikertelen',
        'Failed'             => 'sikertelen',
        'Expired'            => 'sikertelen',
    ];

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/fizetes/barion/ipn', 'POST', [self::class, 'rest_ipn'], 'nyilvanos', ['barion_ipn', 120, 60]);
    }

    public static function aktiv(): bool
    {
        return (bool) SDH_Sales::b('fizetes.barion.aktiv', false) && SDH_Sales::titkos('barion_poskey') !== '' && is_email((string) SDH_Sales::b('fizetes.barion.payee', ''));
    }

    public static function api_alap(): string
    {
        return (bool) SDH_Sales::b('fizetes.barion.eles', false) ? self::ELES : self::TESZT;
    }

    public static function ipn_url(): string
    {
        return rest_url(SDH_Sales_Rest::NS . '/fizetes/barion/ipn');
    }

    /**
     * @param array<string, mixed> $r a rendelés (SDH_Sales_Rendeles::rendeles)
     * @return array{ok: bool, url?: string, azon?: string, uzenet?: string}
     */
    public static function indit(array $r): array
    {
        $osszeg = max(0, (int) $r['brutto'] - (int) $r['fizetett']);
        $tetel  = [];

        foreach ((array) $r['tetelek'] as $t) {
            if (is_array($t)) {
                $b       = SDH_Sales::brutto((int) ($t['netto'] ?? 0));
                $tetel[] = [
                    'Name'        => mb_substr((string) ($t['megnevezes'] ?? $t['nev'] ?? SDH_Sales::termeknev()), 0, 250),
                    'Description' => mb_substr((string) ($t['nev'] ?? ''), 0, 500),
                    'Quantity'    => 1,
                    'Unit'        => 'db',
                    'UnitPrice'   => $b,
                    'ItemTotal'   => $b,
                    'SKU'         => mb_substr((string) ($t['kulcs'] ?? ''), 0, 100),
                ];
            }
        }

        // A tételek összege legyen pontosan a fizetendő (kerekítés, részfizetés után): egy tételként küldjük, ha eltér.
        if (array_sum(array_column($tetel, 'ItemTotal')) !== $osszeg || $tetel === []) {
            $tetel = [['Name' => SDH_Sales::termeknev() . ' – ' . (string) $r['azon'], 'Description' => (string) $r['azon'], 'Quantity' => 1, 'Unit' => 'db', 'UnitPrice' => $osszeg, 'ItemTotal' => $osszeg, 'SKU' => (string) $r['azon']]];
        }

        $kerelem = [
            'POSKey'           => SDH_Sales::titkos('barion_poskey'),
            'PaymentType'      => 'Immediate',
            'GuestCheckOut'    => true,
            'FundingSources'   => ['All'],
            'PaymentRequestId' => (string) $r['azon'],
            'PaymentWindow'    => '00:30:00',
            'Locale'           => 'hu-HU',
            'Currency'         => 'HUF',
            'OrderNumber'      => (string) $r['azon'],
            'PayerHint'        => (string) $r['email'],
            'RedirectUrl'      => SDH_Sales_Rendeles::statusz_url($r),
            'CallbackUrl'      => self::ipn_url(),
            'Transactions'     => [[
                'POSTransactionId' => (string) $r['azon'] . '-' . time(),
                'Payee'            => (string) SDH_Sales::b('fizetes.barion.payee', ''),
                'Total'            => $osszeg,
                'Items'            => $tetel,
            ]],
        ];

        $v = SDH_Sales_Fizetes::json_keres(self::api_alap() . '/v2/Payment/Start', $kerelem);
        $j = (array) $v['json'];

        if ($v['kod'] !== 200 || empty($j['PaymentId']) || empty($j['GatewayUrl'])) {
            $hiba = $v['hiba'] !== '' ? $v['hiba'] : implode('; ', array_map(static fn ($e): string => (string) ($e['Title'] ?? $e['ErrorCode'] ?? ''), (array) ($j['Errors'] ?? [])));

            return ['ok' => false, 'uzenet' => 'Barion: ' . ($hiba !== '' ? $hiba : 'HTTP ' . $v['kod'])];
        }

        return ['ok' => true, 'url' => esc_url_raw((string) $j['GatewayUrl']), 'azon' => sanitize_text_field((string) $j['PaymentId'])];
    }

    /** @return array{allapot: string, tranzakcio: string, osszeg: int, rendeles: string, nyers: array<string, mixed>} */
    public static function allapot(string $payment_id): array
    {
        $ures = ['allapot' => 'ismeretlen', 'tranzakcio' => '', 'osszeg' => 0, 'rendeles' => '', 'nyers' => []];

        if (preg_match('/^[0-9a-fA-F-]{32,36}$/', $payment_id) !== 1) {
            return $ures;
        }

        $v = SDH_Sales_Fizetes::json_keres(self::api_alap() . '/v4/payment/' . rawurlencode($payment_id) . '/paymentstate', null, ['x-pos-key' => SDH_Sales::titkos('barion_poskey')], 'GET');
        $j = $v['json'];

        if ($v['kod'] !== 200 || !is_array($j) || strcasecmp((string) ($j['PaymentId'] ?? ''), $payment_id) !== 0) {
            return $ures;
        }

        $st = (string) ($j['Status'] ?? '');

        return [
            'allapot'    => self::ALLAPOT[$st] ?? 'ismeretlen',
            'tranzakcio' => 'barion:' . strtolower($payment_id),
            'osszeg'     => (int) round((float) ($j['Total'] ?? 0)),
            'rendeles'   => (string) ($j['PaymentRequestId'] ?? ''),
            'ok'         => $st,
            'nyers'      => ['Status' => $st, 'Total' => $j['Total'] ?? null, 'Currency' => $j['Currency'] ?? '', 'PaymentRequestId' => $j['PaymentRequestId'] ?? '', 'CompletedAt' => $j['CompletedAt'] ?? ''],
        ];
    }

    /** Barion callback: a PaymentId-t az API-tól ellenőrizzük, csak utána rögzítünk. Mindig 200 (különben a Barion ismétel). */
    public static function rest_ipn(WP_REST_Request $q): WP_REST_Response
    {
        $pid = sanitize_text_field((string) ($q->get_param('paymentId') ?: $q->get_param('PaymentId')));

        if (!self::aktiv() || $pid === '') {
            return new WP_REST_Response(['ok' => false], 200);
        }

        $a = self::allapot($pid);
        $r = $a['rendeles'] !== '' ? SDH_Sales_Rendeles::azon_szerint($a['rendeles']) : null;

        if ($r === null || !in_array($pid, SDH_Sales_Fizetes::kartya_azonok($r), true)) {
            SDH_Sales_Naplo::ir('fizetes', 'Barion értesítés: ismeretlen fizetés (' . mb_substr($pid, 0, 40) . ').', 'figyel');

            if ($a['allapot'] === 'fizetve') {
                SDH_Sales_Rendeles::ismeretlen_fizetes('barion', $a['tranzakcio'], $a['osszeg'], $a['nyers']);
            }

            return new WP_REST_Response(['ok' => false], 200);
        }

        SDH_Sales_Fizetes::kapu_eredmeny($r, 'barion', $a);

        return new WP_REST_Response(['ok' => true], 200);
    }
}
