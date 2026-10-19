<?php
/**
 * SEO-tesztek (t300–t309) közös mintaadata és segédjei. A _keret.php után töltendő be.
 * A mintaadat a szerződés formátumait követi (data/arak.json, funkciok.json, iparagak.json, gyik.json, blog frontmatter).
 */

if (!function_exists('t_rejtett')) {
    /**
     * Rejtés (weboldal/REJTES.md): a modul kimenetében (HTML, JS, JSON, e-mail, ICS, PDF, fejléc) egyik
     * SDH_Sales_Rejtes::TILTOTT minta sem fordulhat elő.
     *
     * @param mixed                $kimenet szöveg, tömb vagy WP_REST_Response (JSON-ként vizsgálva)
     * @param array<string, mixed> $fejlecek
     */
    function t_rejtett($kimenet, string $nev, array $fejlecek = []): void
    {
        if ($kimenet instanceof WP_REST_Response) {
            $fejlecek += $kimenet->get_headers();
            $kimenet   = $kimenet->get_data();
        }

        $s = is_string($kimenet) ? $kimenet : (string) wp_json_encode($kimenet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $t = SDH_Sales_Rejtes::vizsgal($s, $fejlecek);
        T::igaz($t === [] && $s !== '', 'rejtés: ' . $nev . ($t !== [] ? ' – ' . implode(' | ', array_slice($t, 0, 3)) : ($s === '' ? ' – üres kimenet' : '')));
    }
}

/** Mintatartalom betöltése (tartalom-tár + oldalak + blog). @return array<string, int> oldal-ID-k slug-útvonal szerint */
function t_seo_minta(): array
{
    $m = static fn (string $t, string $k, array $a, int $s = 0) => SDH_Sales_Tartalom::ment($t, $k, $a, null, $s, true, 'hu', false);

    $m('csomag', 'start', ['kulcs' => 'start', 'nev' => 'Start', 'leiras' => 'Kis szervizeknek, egy pulttal.', 'havi' => 9900, 'eves' => 99000, 'felhasznalo_alap' => 2, 'telephely_alap' => 1, 'modulok' => ['szamlazas']], 1);
    $m('csomag', 'pro', ['kulcs' => 'pro', 'nev' => 'Pro', 'leiras' => 'Növekvő szervizeknek raktárral.', 'havi' => 19900, 'eves' => 199000, 'felhasznalo_alap' => 5, 'telephely_alap' => 2, 'modulok' => ['szamlazas', 'penztar']], 2);
    $m('modul', 'szamlazas', ['kulcs' => 'szamlazas', 'nev' => 'Számlázás', 'csoport' => 'Pénzügy', 'havi' => 2900, 'eves' => 29000, 'leiras' => 'Számlázz.hu-számla a munkalapból.', 'csomagban' => ['start', 'pro']], 1);
    $m('modul', 'penztar', ['kulcs' => 'penztar', 'nev' => 'Házipénztár', 'csoport' => 'Pénzügy', 'havi' => 1900, 'eves' => 19000, 'leiras' => 'Készpénzes bevételek és kiadások.', 'csomagban' => ['pro']], 2);
    $m('szolgaltatas', 'telepites', ['kulcs' => 'telepites', 'nev' => 'Telepítés', 'tipus' => 'egyszeri', 'ar' => 30000, 'leiras' => 'Távoli telepítés és beállítás.'], 1);

    $m('funkcio', 'munkalapok', ['kulcs' => 'munkalapok', 'cim' => 'Munkalapok', 'rovid' => 'Digitális munkalap fotóval és aláírással a {termeknev} rendszerben.', 'kulcsszavak' => ['munkalap program', 'digitális munkalap'], 'kapcsolodo' => ['szamlazas', 'imei'], 'modulok' => ['szamlazas']], 1);
    $m('funkcio', 'szamlazas', ['kulcs' => 'szamlazas', 'cim' => 'Számlázás', 'rovid' => 'Munkalapból Számlázz.hu-számla egy kattintással.', 'kulcsszavak' => ['számlázz.hu munkalap'], 'kapcsolodo' => ['munkalapok'], 'modulok' => ['szamlazas', 'penztar']], 2);
    $m('funkcio', 'imei', ['kulcs' => 'imei', 'cim' => 'IMEI- és TAC-keresés', 'rovid' => 'Készülék azonosítása IMEI alapján.', 'kulcsszavak' => ['imei', 'tac'], 'kapcsolodo' => ['munkalapok']], 3);
    $m('iparag', 'telefonszerviz', ['kulcs' => 'telefonszerviz', 'cim' => 'Telefonszerviz', 'rovid' => 'Telefonszerviz program IMEI-kereséssel.', 'funkciok' => ['munkalapok', 'imei'], 'kulcsszavak' => ['mobiltelefon szerviz']], 1);

    $gy = [
        ['ar-1', 'Árak', 'Mennyibe kerül a {termeknev}?', '<p>A Start csomag havi <strong>9 900 Ft + ÁFA</strong>.</p>'],
        ['ar-2', 'Árak', 'Van éves kedvezmény?', '<p>Igen, éves fizetésnél kedvezmény jár.</p>'],
        ['tel-1', 'Telepítés', 'Mi kell a telepítéshez?', '<p>Egy tárhely PHP 8.1-gyel.</p><ul><li>PHP 8.1+</li><li>MySQL</li></ul>'],
        ['adat-1', 'Adatok', 'Átvehetők a MunkaLap 3 adatai?', '<p>Igen, CSV/XLSX importtal. <a href="https://example.com/x" target="_blank">Leírás</a></p>'],
        ['xss-1', 'Adatok', 'Biztonságos a válasz?', '<p>Igen<script>alert(1)</script><img src=x onerror=alert(1)></p>'],
    ];

    foreach ($gy as $i => [$k, $kat, $q, $v]) {
        $m('gyik', $k, ['kulcs' => $k, 'kategoria' => $kat, 'kerdes' => $q, 'valasz' => $v, 'kapcsolodo' => $k === 'ar-1' ? ['munkalapok'] : []], $i);
    }

    $m('lecke', 'munkalap-felvetel', ['kulcs' => 'munkalap-felvetel', 'cim' => 'Új munkalap felvétele', 'leiras' => 'Az első munkalap rögzítése lépésenként.', 'modul' => 'munkalapok'], 1);
    $m('telepites', 'kov', ['kulcs' => 'kov', 'tipus' => 'kovetelmeny', 'cim' => 'PHP 8.1 vagy újabb', 'leiras' => 'MySQL 5.7+ vagy MariaDB'], 0);
    $m('telepites', 'l1', ['kulcs' => 'l1', 'cim' => 'Töltsd le a csomagot', 'leiras' => 'A fiókodból töltsd le a telepítő zipet.'], 1);
    $m('telepites', 'l2', ['kulcs' => 'l2', 'cim' => 'Add meg a licenckulcsot', 'leiras' => 'Az első indításkor írd be a licenckulcsot.'], 2);
    $m('esemeny', 'webinar-1', ['kulcs' => 'webinar-1', 'cim' => 'Bemutató webinár', 'kezdet' => '2026-11-20 10:00', 'veg' => '2026-11-20 11:00', 'leiras' => 'Élő bemutató kérdésekkel.', 'online' => true], 1);

    SDH_Sales::b_ment(['ceg.nev' => 'Teszt Kft.', 'ceg.email' => 'info@example.com', 'ceg.telefon' => '+36 1 234 5678', 'ceg.cim' => '8200 Veszprém, Fő utca 1.', 'seo.sameas' => ['https://www.facebook.com/teszt', 'nem-url'], 'seo.og_kep' => 'https://example.com/og.png']);

    $o = [
        ['kezdolap', 'Kezdőlap', '', ['title' => '{termeknev} – szerviz program', 'description' => 'Szerviz program és munkalapkezelő.'], [['tipus' => 'hero', 'cim' => 'Szia']]],
        ['arak', 'Árak', '', ['title' => 'Árak – {termeknev}', 'description' => 'Csomagok és árak.'], [['tipus' => 'arak']]],
        ['gyik', 'GYIK', '', ['title' => 'Gyakori kérdések', 'description' => 'Kérdések és válaszok.'], [['tipus' => 'gyik']]],
        ['funkciok', 'Funkciók', '', [], [['tipus' => 'funkcio_racs']]],
        ['munkalapok', 'Munkalapok', 'funkciok', ['title' => 'Munkalap program', 'description' => 'Digitális munkalap.'], [['tipus' => 'gyik', 'kulcsok' => ['ar-1', 'tel-1']]]],
        ['szamlazas', 'Számlázás', 'funkciok', [], []],
        ['iparagak', 'Iparágak', '', [], []],
        ['telefonszerviz', 'Telefonszerviz', 'iparagak', [], []],
        ['csomagok', 'Csomagok', '', [], []],
        ['pro', 'Pro csomag', 'csomagok', [], []],
        ['telepites', 'Telepítés', '', [], [['tipus' => 'telepito']]],
        ['esemenyek', 'Események', '', [], []],
        ['kosar', 'Kosár', '', [], [['tipus' => 'kosar']]],
        ['penztar', 'Pénztár', '', [], [['tipus' => 'penztar']]],
        ['fiok', 'Fiók', '', [], [['tipus' => 'fiok']]],
        ['kereses', 'Keresés', '', [], [['tipus' => 'kereso']]],
        ['jogi', 'Jogi', '', [], []],
        ['aszf', 'ÁSZF', 'jogi', [], [['tipus' => 'jogi']]],
        ['blog', 'Blog', '', [], []],
    ];
    $ids = [];

    foreach ($o as [$slug, $cim, $szulo, $seo, $sz]) {
        SDH_Sales_Tartalom::oldal_betolt(['slug' => $slug, 'cim' => $cim, 'szulo' => $szulo, 'seo' => $seo, 'szekciok' => $sz]);
        $p = get_page_by_path(($szulo !== '' ? $szulo . '/' : '') . $slug);
        $ids[($szulo !== '' ? $szulo . '/' : '') . $slug] = (int) $p->ID;
    }

    update_post_meta($ids['jogi/aszf'], SDH_Sales_Tartalom::META_FORRAS, 'data/jogi/aszf.md');
    update_option('show_on_front', 'page');
    update_option('page_on_front', $ids['kezdolap']);
    update_option('page_for_posts', $ids['blog']);

    $kat = wp_insert_term('Munkalap és nyilvántartás', 'category');
    $b1  = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'papir-vagy-digitalis-munkalap', 'post_title' => 'Papír munkalap vagy digitális munkalap?', 'post_excerpt' => 'Költség, idő és hibalehetőség összevetve.', 'post_content' => '<p>A <a href="/funkciok/munkalapok/">munkalap program</a> sokat segít.</p>', 'post_category' => [is_array($kat) ? (int) $kat['term_id'] : 1], 'tags_input' => ['munkalapok', 'digitalis-munkalap']]);
    $b2  = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'imei-kereses', 'post_title' => 'IMEI, sorozatszám, telefonszám', 'post_excerpt' => 'Így találd meg bármelyik korábbi javítást.', 'post_content' => '<p>IMEI alapján.</p>', 'tags_input' => ['imei', 'munkalapok']]);
    SDH_Sales_Tartalom::seo_meta((int) $b1, ['title' => 'Papír vagy digitális munkalap', 'description' => 'Összevetés.', 'kulcsszo' => 'digitális munkalap']);
    $ids['blog:1'] = (int) $b1;
    $ids['blog:2'] = (int) $b2;

    SDH_Sales_Seo::urit();
    SDH_Sales_Gyik::urit();
    flush_rewrite_rules(false);

    return $ids;
}

/** Egy front-oldali kérés szimulálása (mint a WP-tesztek go_to-ja): a fő lekérdezés + a globálisok. */
function t_lap(string $url): void
{
    $ut = (string) wp_parse_url($url, PHP_URL_PATH);
    $q  = (string) wp_parse_url($url, PHP_URL_QUERY);
    $_SERVER['REQUEST_URI'] = $ut . ($q !== '' ? '?' . $q : '');
    $_GET = [];
    parse_str($q, $_GET);

    foreach (['query_string', 'id', 'postdata', 'authordata', 'day', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages', 'pagenow', 'post'] as $v) {
        unset($GLOBALS[$v]);
    }

    $GLOBALS['wp_the_query'] = new WP_Query();
    $GLOBALS['wp_query']     = $GLOBALS['wp_the_query'];
    $GLOBALS['wp']           = new WP();
    _cleanup_query_vars_t();
    $GLOBALS['wp']->main($q !== '' ? $q : '');

    if (is_singular() && have_posts()) {
        the_post();
    }

    SDH_Sales_Gyik::megjelent_urit();
}

function _cleanup_query_vars_t(): void
{
    foreach (get_taxonomies([], 'objects') as $t) {
        if ($t->public && !empty($t->query_var)) {
            $GLOBALS['wp']->add_query_var($t->query_var);
        }
    }

    foreach (get_post_types([], 'objects') as $t) {
        if (is_post_type_viewable($t) && $t->query_var) {
            $GLOBALS['wp']->add_query_var($t->query_var);
        }
    }
}

/** wp_head kimenete. */
function t_fej(): string
{
    ob_start();
    do_action('wp_head');

    return (string) ob_get_clean();
}

/** wp_footer kimenete. */
function t_lab(): string
{
    ob_start();
    do_action('wp_footer');

    return (string) ob_get_clean();
}

/** A HTML-ben lévő JSON-LD blokkok (dekódolva), és a @type-ok listája. @return array{0: array<int, array>, 1: array<int, string>, 2: bool} */
function t_ld(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $blokk = [];
    $tip   = [];
    $jo    = true;

    foreach ($m[1] as $j) {
        $d = json_decode($j, true);

        if (!is_array($d)) {
            $jo = false;
            continue;
        }

        $blokk[] = $d;

        foreach (isset($d['@graph']) ? $d['@graph'] : [$d] as $n) {
            $tip[] = (string) ($n['@type'] ?? '?');
        }
    }

    return [$blokk, $tip, $jo];
}

/** Egy @type első csomópontja. @param array<int, array> $blokk */
function t_ld_csomopont(array $blokk, string $tipus): ?array
{
    foreach ($blokk as $d) {
        foreach (isset($d['@graph']) ? $d['@graph'] : [$d] as $n) {
            if (($n['@type'] ?? '') === $tipus) {
                return $n;
            }
        }
    }

    return null;
}
