<?php
/**
 * Közös segéd a t260–t269 (számlázás + naptár) tesztekhez: rendelés beszúrása, hamis Számla Agent, levélcsatolmányok
 * elkapása. A futtató (`t[0-9]*.php`) nem futtatja külön.
 */
require_once __DIR__ . '/_keret.php';

/** @var array<int, array{mezo: string, xml: string}> az Agentnek küldött kérések */
$GLOBALS['t26_agent']     = [];
/** @var array<int, array{to: string, subject: string, message: string, csatolmany: array<string, string>}> */
$GLOBALS['t26_levelek']   = [];
/** @var callable|null fn(string $mezo, string $xml, int $n): array – az Agent válasza (t_valasz-formában) */
$GLOBALS['t26_agent_fv']  = null;

add_filter('pre_wp_mail', static function ($null, $atts) {
    $cs = [];

    foreach ((array) ($atts['attachments'] ?? []) as $f) {
        $cs[basename((string) $f)] = is_readable((string) $f) ? (string) file_get_contents((string) $f) : '';
    }

    $GLOBALS['t26_levelek'][] = ['to' => (string) (is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to']), 'subject' => (string) $atts['subject'], 'message' => (string) $atts['message'], 'csatolmany' => $cs];

    return $null;
}, 0, 2);

add_filter('sdh_sales_korlat_ki', '__return_true');

t_http(static function (string $url, array $args): ?array {
    if (strpos($url, 'szamlazz.hu/szamla') === false) {
        return null;
    }

    $test = (string) ($args['body'] ?? '');
    $mezo = preg_match('/name="([^"]+)"/', $test, $m) === 1 ? $m[1] : '';
    $xml  = preg_match("/Content-Type: text\\/xml\r\n\r\n(.*)\r\n--/s", $test, $m) === 1 ? $m[1] : '';

    $GLOBALS['t26_agent'][] = ['mezo' => $mezo, 'xml' => $xml];
    $fv = $GLOBALS['t26_agent_fv'];

    return is_callable($fv) ? $fv($mezo, $xml, count($GLOBALS['t26_agent'])) : t26_agent_ok('E-TESZT-2026-' . count($GLOBALS['t26_agent']));
});

/** Sikeres v2-es Agent-válasz (xmlszamlavalasz, base64 PDF-fel). */
function t26_agent_ok(string $szamlaszam, float $brutto = 190500): array
{
    $pdf = SDH_Sales_Szamlazz::pdf_ir([['Szamla ' . $szamlaszam, 12]]);
    $x   = '<?xml version="1.0" encoding="UTF-8"?><xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz"><sikeres>true</sikeres>'
        . '<szamlaszam>' . $szamlaszam . '</szamlaszam><szamlanetto>150000</szamlanetto><szamlabrutto>' . $brutto . '</szamlabrutto>'
        . '<kintlevoseg>0</kintlevoseg><pdf>' . base64_encode($pdf) . '</pdf></xmlszamlavalasz>';

    return t_valasz(200, $x, ['szlahu_szamlaszam' => rawurlencode($szamlaszam), 'szlahu_bruttovegosszeg' => (string) $brutto]);
}

function t26_agent_hiba(string $kod, string $uzenet, int $verzio = 2): array
{
    if ($verzio === 2) {
        return t_valasz(200, '<?xml version="1.0" encoding="UTF-8"?><xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz"><sikeres>false</sikeres><hibakod>' . $kod . '</hibakod><hibauzenet>' . htmlspecialchars($uzenet, ENT_XML1) . '</hibauzenet></xmlszamlavalasz>');
    }

    return t_valasz(200, "[ERR] {$uzenet}\n-----------------------\nrészletek", ['szlahu_error_code' => $kod, 'szlahu_error' => rawurlencode($uzenet)]);
}

/** Egy rendelés beszúrása (a sales-backend `letrehoz()` helyett, a séma mezőivel). */
function t26_rendeles(array $m = []): int
{
    global $wpdb;

    static $n = 0;
    $n++;

    $alap = [
        'azon'       => 'R-2026-' . str_pad((string) (900 + $n + wp_rand(0, 90000)), 6, '0', STR_PAD_LEFT),
        'allapot'    => 'dijbekero',
        'tipus'      => 'uj',
        'vevo'       => wp_json_encode(['ceg' => 'Próba Szerviz Kft.', 'adoszam' => '12345678-2-19', 'cim' => '8200 Veszprém, Fő utca 1.', 'kapcsolattarto' => 'Kis Anna', 'email' => 'anna@pelda.hu', 'telefon' => '+36 30 123 4567']),
        'tetelek'    => wp_json_encode([['nev' => 'Pro csomag – éves', 'mennyiseg' => 1, 'netto' => 120000], ['nev' => 'Telepítés', 'mennyiseg' => 1, 'netto' => 30000]]),
        'netto'      => 150000,
        'afa'        => 40500,
        'brutto'     => 190500,
        'fizetett'   => 0,
        'penznem'    => 'HUF',
        'ciklus'     => 'eves',
        'fizmod'     => 'atutalas',
        'kozlemeny'  => 'SDH' . wp_rand(100000, 999999),
        'email'      => 'anna@pelda.hu',
        'letrehozva' => current_time('mysql', true),
        'modositva'  => current_time('mysql', true),
        'hatarido'   => gmdate('Y-m-d', time() + 8 * DAY_IN_SECONDS),
    ];

    $wpdb->insert(SDH_Sales_Schema::t('rendeles'), $m + $alap);

    return (int) $wpdb->insert_id;
}

function t26_fizet(int $id, string $ido = '2026-10-19 10:00:00'): void
{
    global $wpdb;

    $wpdb->query($wpdb->prepare('UPDATE ' . SDH_Sales_Schema::t('rendeles') . " SET allapot = 'fizetve', fizetett = brutto, fizetve_ido = %s WHERE id = %d", $ido, $id));
}

/** Éles-szerű tesztüzem: tesztkulccsal (a hamis Agent felel). */
function t26_teszt_kulcs(): void
{
    SDH_Sales::b_ment(['szamlazz.teszt' => true]);
    SDH_Sales::titkos_ment('szamlazz_teszt_kulcs', 'tesztkulcs123abc');
}

function t26_dom(string $xml): ?DOMDocument
{
    $d = new DOMDocument();

    return @$d->loadXML($xml) ? $d : null;
}

/** Egy elem gyerekeinek nevei sorban. @return array<int, string> */
function t26_gyerekek(DOMDocument $d, string $szulo): array
{
    $l = $d->getElementsByTagName($szulo);
    $k = [];

    if ($l->length === 0) {
        return $k;
    }

    foreach ($l->item(0)->childNodes as $c) {
        if ($c instanceof DOMElement) {
            $k[] = $c->localName;
        }
    }

    return $k;
}

/** Igaz, ha $resz elemei $teljes-ben ugyanebben a sorrendben fordulnak elő (az XSD sequence). */
function t26_sorrendben(array $resz, array $teljes): bool
{
    $i = 0;

    foreach ($resz as $r) {
        $p = array_search($r, array_slice($teljes, $i), true);

        if ($p === false) {
            return false;
        }

        $i += (int) $p + 1;
    }

    return true;
}

/** UTC időbélyeg budapesti falióra-időből. */
function t26_bp(string $helyi): int
{
    return (new DateTimeImmutable($helyi, new DateTimeZone('Europe/Budapest')))->getTimestamp();
}

/** Naptár-alapbeállítás a tesztekhez: H–P 9–17, 30 perces lépés, 12 óra előzetes, 60 nap, kapacitás 1, ünnep be. */
function t26_naptar(array $extra = []): void
{
    SDH_Sales::b_ment($extra + [
        'naptar.napok'     => [1, 2, 3, 4, 5],
        'naptar.nyit'      => '09:00',
        'naptar.zar'       => '17:00',
        'naptar.lepes'     => 30,
        'naptar.min_ora'   => 12,
        'naptar.max_nap'   => 60,
        'naptar.kapacitas' => 1,
        'naptar.zarolt'    => [],
        'naptar.unnepnapok' => true,
    ]);
}
