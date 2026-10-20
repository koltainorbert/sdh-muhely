<?php
/** t217 – Barion és SimplePay hamis hálózattal: indítás, aláírás (HMAC-SHA384), IPN/callback ellenőrzés API-lekérdezéssel, sikertelen fizetés + újrapróba. */
require __DIR__ . '/_t210-seged.php';
t_katalogus_betolt();

// Alapból kikapcsolva
T::igaz(!SDH_Sales_Fizetes_Barion::aktiv() && !SDH_Sales_Fizetes_Simplepay::aktiv(), 'kártyás kapuk alapból kikapcsolva');
T::egyezik(SDH_Sales_Fizetes_Barion::TESZT, SDH_Sales_Fizetes_Barion::api_alap(), 'Barion: alapból teszt-környezet');
T::egyezik(SDH_Sales_Fizetes_Simplepay::SANDBOX, SDH_Sales_Fizetes_Simplepay::alap(), 'SimplePay: alapból sandbox');

SDH_Sales::titkos_ment('barion_poskey', 'pos-titok-1234567890');
SDH_Sales::titkos_ment('simplepay_secret', 'FxDa5w314kLlNseq2sKuVwaqZshZT5d6');
SDH_Sales::b_ment(['fizetes.barion.aktiv' => true, 'fizetes.barion.payee' => 'bolt@example.com', 'fizetes.simplepay.aktiv' => true, 'fizetes.simplepay.merchant' => 'PUBLICTESTHUF']);
T::igaz(SDH_Sales_Fizetes_Barion::aktiv() && SDH_Sales_Fizetes_Simplepay::aktiv(), 'bekapcsolva kulccsal');
T::egyezik(['atutalas', 'barion', 'simplepay'], array_column(SDH_Sales_Kosar::fizmodok(), 'kulcs'), 'fizetési módok');

/* ---------------- Barion ---------------- */
$pid = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
$allapot = 'Succeeded';
$osszeg = 0;
$rend = '';
t_http(static function (string $url, array $a) use (&$pid, &$allapot, &$osszeg, &$rend): ?array {
    if (strpos($url, 'api.test.barion.com/v2/Payment/Start') !== false) {
        $j = json_decode((string) $a['body'], true);
        $GLOBALS['t_barion_start'] = $j;
        return t_valasz(200, (string) wp_json_encode(['PaymentId' => $pid, 'PaymentRequestId' => $j['PaymentRequestId'], 'Status' => 'Prepared', 'GatewayUrl' => 'https://secure.test.barion.com/Pay?Id=' . $pid]));
    }
    if (strpos($url, 'api.test.barion.com/v4/payment/') !== false) {
        $GLOBALS['t_barion_fej'] = $a['headers'] ?? [];
        return t_valasz(200, (string) wp_json_encode(['PaymentId' => $pid, 'PaymentRequestId' => $rend, 'Status' => $allapot, 'Total' => $osszeg, 'Currency' => 'HUF']));
    }
    return null;
});

$r = t_latogato(static fn () => t_rendeles(['fizmod' => 'barion']));
T::igaz(!empty($r['ok']) && strpos((string) ($r['atiranyit'] ?? ''), 'secure.test.barion.com') !== false, 'Barion: átirányítás a fizetőoldalra');
$x = SDH_Sales_Rendeles::rendeles((int) $r['id']);
T::egyezik('fizetesre_var', $x['allapot'], 'Barion: fizetésre vár');
T::egyezik($pid, $x['vevo']['_kartya']['azon'], 'Barion: PaymentId eltárolva');
$st = $GLOBALS['t_barion_start'];
T::egyezik('pos-titok-1234567890', $st['POSKey'], 'Barion: POSKey a kérésben');
T::egyezik($r['azon'], $st['PaymentRequestId'], 'Barion: PaymentRequestId = rendelés');
T::egyezik((int) $x['brutto'], (int) array_sum(array_column($st['Transactions'][0]['Items'], 'ItemTotal')), 'Barion: tételek összege = bruttó');
T::egyezik((int) $x['brutto'], (int) $st['Transactions'][0]['Total'], 'Barion: Total = bruttó');
T::tartalmaz($st['CallbackUrl'], '/fizetes/barion/ipn', 'Barion: callback URL');

// Hamis callback (ismeretlen PaymentId) – nem rögzít
$rend = $r['azon']; $osszeg = (int) $x['brutto'];
$pidMent = $pid; $pid = 'ffffffffffffffffffffffffffffffff';
$v = t_latogato(static fn () => t_rest('POST', '/fizetes/barion/ipn', ['paymentId' => 'ffffffffffffffffffffffffffffffff']));
T::egyezik(200, $v->get_status(), 'Barion: ismeretlen callback is 200');
T::egyezik('fizetesre_var', SDH_Sales_Rendeles::rendeles((int) $r['id'])['allapot'], 'Barion: nem a mi fizetésünk → nem fizetve');
$pid = $pidMent;

// Kisebb összeg a lekérdezésben → részfizetés, nem teljes
$allapot = 'Succeeded'; $osszeg = 100;
$GLOBALS['t_barion_fej'] = [];
t_latogato(static fn () => t_rest('POST', '/fizetes/barion/ipn', ['paymentId' => $pid]));
T::egyezik('pos-titok-1234567890', $GLOBALS['t_barion_fej']['x-pos-key'] ?? '', 'Barion: állapot-lekérdezés x-pos-key fejléccel');
T::egyezik('fizetesre_var', SDH_Sales_Rendeles::rendeles((int) $r['id'])['allapot'], 'Barion: az API összege számít (100 Ft nem elég)');

// Teljes összeg ugyanazzal a PaymentId-vel: a tranzakció-kulcs ugyanaz → idempotens. Új próbálkozás új PaymentId-vel kell.
$pid = 'b1b2c3d4e5f60718293a4b5c6d7e8f91';
$k = SDH_Sales_Fizetes::kartya_indit((int) $r['id'], 'barion');
$osszeg = (int) $x['brutto'] - 100;
$v = t_latogato(static fn () => t_rest('POST', '/fizetes/barion/ipn', ['paymentId' => $pid]));
T::egyezik('fizetve', SDH_Sales_Rendeles::rendeles((int) $r['id'])['allapot'], 'Barion: callback + API-ellenőrzés → fizetve');
t_latogato(static fn () => t_rest('POST', '/fizetes/barion/ipn', ['paymentId' => $pid]));
T::egyezik(1, $GLOBALS['t_fizetve'][(int) $r['id']] ?? 0, 'Barion: ismételt callback nem indít újra');

// Sikertelen: Canceled → sikertelen + levél újrapróba-linkkel (egyszer)
$pid = 'c1b2c3d4e5f60718293a4b5c6d7e8f92';
$GLOBALS['sdh_t_levelek'] = [];
$r2 = t_latogato(static fn () => t_rendeles(['fizmod' => 'barion']));
$rend = $r2['azon']; $allapot = 'Canceled'; $osszeg = 0;
t_latogato(static fn () => t_rest('POST', '/fizetes/barion/ipn', ['paymentId' => $pid]));
t_latogato(static fn () => t_rest('POST', '/fizetes/barion/ipn', ['paymentId' => $pid]));
T::egyezik('sikertelen', SDH_Sales_Rendeles::rendeles((int) $r2['id'])['allapot'], 'Barion: megszakítva → sikertelen');
$l = array_values(array_filter($GLOBALS['sdh_t_levelek'], static fn ($m) => strpos((string) $m['message'], 'ujra=1') !== false || strpos((string) $m['message'], 'ujra=') !== false));
T::egyezik(1, count($l), 'sikertelen fizetés: egy levél újrapróba-linkkel');

// Újrapróba REST → új fizetés, majd átváltás átutalásra
$pid = 'd1b2c3d4e5f60718293a4b5c6d7e8f93';
$v = t_latogato(static fn () => t_rest('POST', '/fizetes/ujra', ['azon' => $r2['azon'], 't' => $r2['token']]));
T::igaz($v->get_status() === 200 && strpos((string) $v->get_data()['atiranyit'], $pid) !== false, 'újrapróba: új Barion-fizetés');
$v = t_latogato(static fn () => t_rest('POST', '/fizetes/ujra', ['azon' => $r2['azon'], 't' => $r2['token'], 'fizmod' => 'atutalas']));
T::egyezik('dijbekero', SDH_Sales_Rendeles::rendeles((int) $r2['id'])['allapot'], 'átváltás átutalásra → díjbekérő');
T::egyezik(404, t_latogato(static fn () => t_rest('POST', '/fizetes/ujra', ['azon' => $r2['azon'], 't' => 'rossz']))->get_status(), 'újrapróba rossz tokennel 404');

/* ---------------- SimplePay ---------------- */
$titok = 'FxDa5w314kLlNseq2sKuVwaqZshZT5d6';
$alair = static fn (string $s): string => base64_encode(hash_hmac('sha384', $s, $titok, true));
T::egyezik($alair('{"a":1}'), SDH_Sales_Fizetes_Simplepay::alairas('{"a":1}'), 'SimplePay: HMAC-SHA384 base64');
T::igaz(SDH_Sales_Fizetes_Simplepay::alairas_jo('{"a":1}', $alair('{"a":1}')) && !SDH_Sales_Fizetes_Simplepay::alairas_jo('{"a":2}', $alair('{"a":1}')), 'SimplePay: aláírás-ellenőrzés');

$tid = '501234567';
$spAllapot = 'FINISHED';
$spOsszeg = 0;
$spRend = '';
$spRosszAlairas = false;
t_http(static function (string $url, array $a) use (&$tid, &$spAllapot, &$spOsszeg, &$spRend, &$spRosszAlairas, $alair): ?array {
    if (strpos($url, 'sandbox.simplepay.hu/payment/v2/start') !== false) {
        $j = json_decode((string) $a['body'], true);
        $GLOBALS['t_sp_start'] = ['j' => $j, 'sig' => $a['headers']['Signature'] ?? '', 'body' => (string) $a['body']];
        $t = (string) wp_json_encode(['salt' => 'x', 'merchant' => $j['merchant'], 'orderRef' => $j['orderRef'], 'currency' => 'HUF', 'transactionId' => (int) $tid, 'timeout' => $j['timeout'], 'total' => $j['total'], 'paymentUrl' => 'https://sandbox.simplepay.hu/pay/pay/pspHU/abc']);
        return t_valasz(200, $t, ['signature' => $spRosszAlairas ? 'rossz' : $alair($t)]);
    }
    if (strpos($url, 'sandbox.simplepay.hu/payment/v2/query') !== false) {
        $t = (string) wp_json_encode(['salt' => 'y', 'merchant' => 'PUBLICTESTHUF', 'transactions' => [['salt' => 'z', 'merchant' => 'PUBLICTESTHUF', 'orderRef' => $spRend, 'transactionId' => (int) $tid, 'status' => $spAllapot, 'total' => $spOsszeg]]]);
        return t_valasz(200, $t, ['signature' => $alair($t)]);
    }
    return null;
});

$spRosszAlairas = true;
$r3 = t_latogato(static fn () => t_rendeles(['fizmod' => 'simplepay']));
T::igaz(empty($r3['atiranyit']) && !empty($r3['uzenet']), 'SimplePay: hibás válasz-aláírás → nincs átirányítás');
$spRosszAlairas = false;
$r3 = t_latogato(static fn () => t_rendeles(['fizmod' => 'simplepay']));
T::tartalmaz((string) ($r3['atiranyit'] ?? ''), 'sandbox.simplepay.hu/pay', 'SimplePay: átirányítás');
$s = $GLOBALS['t_sp_start'];
T::egyezik($alair($s['body']), $s['sig'], 'SimplePay: a kérés Signature fejléce helyes');
T::egyezik($r3['azon'], $s['j']['orderRef'], 'SimplePay: orderRef');
T::egyezik((int) $r3['r']['brutto'], $s['j']['total'], 'SimplePay: total');
T::egyezik('Próba Szerviz Kft.', $s['j']['invoice']['company'], 'SimplePay: számlázási adatok');

$ipn = (string) wp_json_encode(['salt' => 'q', 'orderRef' => $r3['azon'], 'method' => 'CARD', 'merchant' => 'PUBLICTESTHUF', 'finishDate' => '2026-10-19T10:00:00+02:00', 'paymentDate' => '2026-10-19T09:59:00+02:00', 'transactionId' => (int) $tid, 'status' => 'FINISHED']);
$keres = static function (string $test, string $sig): WP_REST_Response {
    $q = new WP_REST_Request('POST', '/' . SDH_Sales_Rest::NS . '/fizetes/simplepay/ipn');
    $q->set_header('content-type', 'application/json');
    $q->set_header('signature', $sig);
    $q->set_body($test);
    return rest_do_request($q);
};
$v = t_latogato(static fn () => $keres($ipn, 'hamis'));
T::egyezik(403, $v->get_status(), 'SimplePay IPN: hamis aláírás 403');

$spRend = $r3['azon']; $spOsszeg = (int) $r3['r']['brutto']; $spAllapot = 'FINISHED';
$v = t_latogato(static fn () => $keres($ipn, $alair($ipn)));
T::egyezik(200, $v->get_status(), 'SimplePay IPN: 200');
$valasz = (string) $v->get_data();
$vj = json_decode($valasz, true);
T::igaz(!empty($vj['receiveDate']) && $vj['orderRef'] === $r3['azon'], 'SimplePay IPN válasz: receiveDate');
T::egyezik($alair($valasz), $v->get_headers()['Signature'] ?? '', 'SimplePay IPN válasz aláírva');
T::igaz(SDH_Sales_Rendeles::nyers_e($v), 'SimplePay IPN válasz bájtra pontos (nyers)');
T::egyezik('fizetve', SDH_Sales_Rendeles::rendeles((int) $r3['id'])['allapot'], 'SimplePay: IPN + lekérdezés → fizetve');
t_latogato(static fn () => $keres($ipn, $alair($ipn)));
T::egyezik(1, $GLOBALS['t_fizetve'][(int) $r3['id']] ?? 0, 'SimplePay: ismételt IPN nem indít újra');

// FINISHED IPN, de a lekérdezés mást mond → nem fizetve
$tid = '501234568';
$r4 = t_latogato(static fn () => t_rendeles(['fizmod' => 'simplepay']));
$spRend = $r4['azon']; $spAllapot = 'INPAYMENT';
$ipn4 = (string) wp_json_encode(['salt' => 'q', 'orderRef' => $r4['azon'], 'merchant' => 'PUBLICTESTHUF', 'transactionId' => (int) $tid, 'status' => 'FINISHED']);
t_latogato(static fn () => $keres($ipn4, $alair($ipn4)));
T::egyezik('fizetesre_var', SDH_Sales_Rendeles::rendeles((int) $r4['id'])['allapot'], 'SimplePay: az IPN magában nem elég, a lekérdezés dönt');

// Visszairányítás r/s
$rj = (string) wp_json_encode(['r' => 0, 't' => (int) $tid, 'e' => 'CANCEL', 'm' => 'PUBLICTESTHUF', 'o' => $r4['azon']]);
$v = t_latogato(static fn () => t_rest('GET', '/fizetes/simplepay/vissza', ['r' => base64_encode($rj), 's' => $alair($rj)]));
T::egyezik('CANCEL', $v->get_data()['esemeny'] ?? '', 'SimplePay vissza: esemény');
T::egyezik('sikertelen', SDH_Sales_Rendeles::rendeles((int) $r4['id'])['allapot'], 'SimplePay vissza CANCEL → sikertelen');
T::egyezik(400, t_latogato(static fn () => t_rest('GET', '/fizetes/simplepay/vissza', ['r' => base64_encode($rj), 's' => 'rossz']))->get_status(), 'SimplePay vissza: rossz aláírás 400');

// Független ellenőrzés (2026-10-20), alacsony 7: egy korábbi tranzakció aláírt FAIL-linkje nem buktatja el az új fizetést.
$tid = '501234569';
$r5  = t_latogato(static fn () => t_rendeles(['fizmod' => 'simplepay']));
$spRend = $r5['azon']; $spAllapot = 'FAIL';
$tid = '501234570';
SDH_Sales_Fizetes::kartya_indit((int) $r5['id'], 'simplepay');
$spAllapot = 'INPAYMENT';
$regi = (string) wp_json_encode(['r' => 0, 't' => 501234569, 'e' => 'FAIL', 'm' => 'PUBLICTESTHUF', 'o' => $r5['azon']]);
t_latogato(static fn () => t_rest('GET', '/fizetes/simplepay/vissza', ['r' => base64_encode($regi), 's' => $alair($regi)]));
T::egyezik('fizetesre_var', SDH_Sales_Rendeles::rendeles((int) $r5['id'])['allapot'], 'alacsony-7: régi FAIL-link újrajátszása nem állítja „sikertelen”-re a folyamatban lévő új fizetést');
// alacsony 6: nem forintos kapu-összeg nem rögzül fizetésként
$r6 = t_rendeles();
T::egyezik('ismeretlen', SDH_Sales_Fizetes::kapu_eredmeny(SDH_Sales_Rendeles::rendeles((int) $r6['id']), 'barion', ['allapot' => 'fizetve', 'tranzakcio' => 'barion:eur-1', 'osszeg' => (int) $r6['r']['brutto'], 'rendeles' => $r6['azon'], 'nyers' => ['Currency' => 'EUR']]), 'alacsony-6: EUR-ban igazolt fizetés nem rögzül');
T::egyezik('dijbekero', SDH_Sales_Rendeles::rendeles((int) $r6['id'])['allapot'], 'alacsony-6: a rendelés nyitott marad');
// alacsony 3: a díjbekérő újraküldése tokenes linkkel nem ismételhető (levélbomba ellen)
$r7 = t_rendeles();
$GLOBALS['sdh_t_levelek'] = [];
for ($i = 0; $i < 3; $i++) {
    t_latogato(static fn () => t_rest('POST', '/fizetes/ujra', ['azon' => $r7['azon'], 't' => $r7['token'], 'fizmod' => 'atutalas']));
}
T::egyezik(0, count(t_levelek((string) $r7['r']['email'])), 'alacsony-3: díjbekérős rendelésen a „fizetés újra (átutalás)” nem küld újabb levelet');

// A titok nem jelenhet meg REST-válaszban
$v = t_rest('POST', '/kosar/ellenoriz', ['tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start']]]);
T::nem_tartalmaz(wp_json_encode($v->get_data()), 'FxDa5w314k', 'titok nincs a REST-válaszban');
T::igaz(SDH_Sales_Biztonsag::szur(['a' => 'x FxDa5w314kLlNseq2sKuVwaqZshZT5d6 y', 'poskey' => 'k'], ['FxDa5w314kLlNseq2sKuVwaqZshZT5d6']) === ['a' => 'x *** y'], 'titokszűrő: érték kicsillagozva, kulcs eldobva');

T::vege('t217 kártya');
