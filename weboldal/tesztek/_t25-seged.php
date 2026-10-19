<?php
/**
 * Közös segéd a t250–t259 (licenc-integráció) tesztekhez: rendelés beszúrása és „kifizetése”, kiadás a Központban,
 * levelek elkapása (a `sdh_sales_email_kuldes` szűrővel – a teljes levél, csatolmánnyal), hamis Számlázz.hu.
 * A futtató (`t[0-9]*.php`) nem futtatja külön.
 */
require_once __DIR__ . '/_keret.php';

if (!class_exists('SDH_Kozpont_Kiszolgalas')) {
    fwrite(STDERR, "Az SDH Központ (2.1) nincs bekapcsolva a tesztoldalon.\n");
    exit(2);
}

SDH_Kozpont::sema();
do_action('rest_api_init', rest_get_server());
add_filter('sdh_sales_korlat_ki', '__return_true');

/** @var array<int, array<string, mixed>> a kiküldött levelek (sablon, cimzett, targy, html, szoveg, fejlec, csatolmany) */
$GLOBALS['t25_levelek'] = [];
/** @var bool hamis: a levélküldés „nem sikerül” */
$GLOBALS['t25_level_hiba'] = false;

add_filter('sdh_sales_email_kuldes', static function ($null, array $l) {
    if ($GLOBALS['t25_level_hiba']) {
        return false;
    }

    $GLOBALS['t25_levelek'][] = $l;

    return true;
}, 10, 2);

SDH_Sales::b_ment([
    'kozpont.mod'          => 'helyi',
    'ceg.nev'              => 'Forgalmazó Teszt Kft.',
    'ceg.email'            => 'info@forgalmazo.example',
    'email.felado'         => 'rendeles@forgalmazo.example',
    'email.ertesites'      => 'norbi@forgalmazo.example',
    'szamlazz.teszt'       => true,
    'szamlazz.automatikus' => true,
]);
SDH_Sales::titkos_ment('szamlazz_teszt_kulcs', '');
SDH_Sales::titkos_ment('szamlazz_agent_kulcs', '');

if (class_exists('SDH_Sales_Tartalom')) {
    SDH_Sales_Tartalom::betolt(false);
}

/** Kiadás a Központban (a letöltéshez). */
function t25_kiadas(string $verzio = '9.9.5'): void
{
    $zip = wp_tempnam('t25.zip');
    $z   = new ZipArchive();
    $z->open($zip, ZipArchive::OVERWRITE);
    $z->addFromString('sdh-muhely/sdh-muhely.php', "<?php\n/**\n * Plugin Name: SDH Műhely\n * Version: {$verzio}\n */\n");
    $z->close();
    SDH_Kozpont::kiadas_be($zip, 'stabil', 't25', 'teszt');
    @unlink($zip);
}

/**
 * Rendelés beszúrása (a sales-backend `letrehoz()`-a helyett, a szerződés mezőivel).
 *
 * @param array<string, mixed> $m felülírások: tipus, tetelek (Ar::szamol bemenet), opciok, vevo, email, licenc_kulcs, ciklus
 */
function t25_rendeles(array $m = []): int
{
    global $wpdb;

    static $n = 0;
    $n++;
    $sfx  = (string) wp_rand(10000, 99999) . $n;
    $email = (string) ($m['email'] ?? 'vevo' . $sfx . '@pelda.hu');
    $vevo = ($m['vevo'] ?? []) + [
        'ceg'            => 'Próba Szerviz Kft. ' . $sfx,
        'adoszam'        => '2' . str_pad($sfx, 7, '0', STR_PAD_LEFT) . '-2-19',
        'cim'            => '8200 Veszprém, Fő utca 1.',
        'kapcsolattarto' => 'Kovács Béla',
        'email'          => $email,
        'telefon'        => '+36 30 123 4567',
    ];
    $ciklus  = (string) ($m['ciklus'] ?? 'eves');
    $tetelek = $m['tetelek'] ?? [['tipus' => 'csomag', 'kulcs' => 'pro'], ['tipus' => 'modul', 'kulcs' => 'asszisztens'], ['tipus' => 'szolgaltatas', 'kulcs' => 'telepites']];
    $ar      = SDH_Sales_Ar::szamol($tetelek, ($m['opciok'] ?? []) + ['ciklus' => $ciklus]);
    $netto   = (int) ($m['netto'] ?? $ar['netto']);
    $brutto  = (int) ($m['brutto'] ?? SDH_Sales::brutto($netto));

    $wpdb->insert(SDH_Sales_Schema::t('rendeles'), [
        'azon'         => 'R-T25-' . $sfx,
        'allapot'      => 'dijbekero',
        'tipus'        => (string) ($m['tipus'] ?? 'uj'),
        'vevo'         => (string) wp_json_encode($vevo, JSON_UNESCAPED_UNICODE),
        'tetelek'      => (string) wp_json_encode(!empty($m['teljes']) ? $ar : $ar['sorok'], JSON_UNESCAPED_UNICODE),
        'netto'        => $netto,
        'afa'          => $brutto - $netto,
        'brutto'       => $brutto,
        'ciklus'       => $ciklus,
        'fizmod'       => 'atutalas',
        'kozlemeny'    => 'SDH' . $sfx,
        'email'        => $email,
        'licenc_kulcs' => (string) ($m['licenc_kulcs'] ?? ''),
        'letrehozva'   => current_time('mysql', true),
        'modositva'    => current_time('mysql', true),
    ]);

    return (int) $wpdb->insert_id;
}

/** A fizetés beérkezése (a sales-backend `fizetve()`-je helyett): állapot + a szerződés hookja. */
function t25_fizet(int $id, bool $hook = true): void
{
    global $wpdb;

    $b = (int) $wpdb->get_var($wpdb->prepare('SELECT brutto FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE id = %d', $id));
    $wpdb->update(SDH_Sales_Schema::t('rendeles'), ['allapot' => 'fizetve', 'fizetett' => $b, 'fizetve_ido' => current_time('mysql', true)], ['id' => $id]);

    if ($hook) {
        do_action('sdh_sales_fizetve', $id);
    }
}

/** @return array<string, mixed> */
function t25_r(int $id): array
{
    return (array) SDH_Sales_Kiszolgalas::rendeles($id);
}

/** A lánc egy lépése. @return array<string, mixed> */
function t25_lepes(int $id, string $lepes): array
{
    return (array) (t25_r($id)['lanc']['lepesek'][$lepes] ?? []);
}

/** @return array<int, array<string, mixed>> a megadott sablonú levelek */
function t25_levelek(string $sablon, string $cimzett = ''): array
{
    return array_values(array_filter($GLOBALS['t25_levelek'], static fn (array $l): bool => $l['sablon'] === $sablon && ($cimzett === '' || $l['cimzett'] === $cimzett)));
}

function t25_token(string $url): string
{
    parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);

    return (string) ($q['t'] ?? '');
}
