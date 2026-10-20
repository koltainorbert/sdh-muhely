<?php
/**
 * t240 – téma: minden szekció-sablon kirajzolódik figyelmeztetés nélkül minimális és teljes mintaadattal; escapelés
 * rosszindulatú szövegekkel; képek (picture/srcset/width/height/lazy/hero); ismeretlen típus; rejtett szekció;
 * teljes oldalkérések (kezdőlap, funkció-oldal, árak, blogcikk, 404, keresés, tudásbázis-fejezet) külön folyamatban;
 * a téma a bővítmény nélkül is fut (védelmek); rejtés (REJTES.md): nincs „wp-” osztály / id, „sdh” azonosító,
 * WP body_class, generator a téma kimenetében.
 */
require __DIR__ . '/_keret.php';

$tema_dir = get_theme_root() . '/sdh-sales-tema';

if (!is_dir($tema_dir)) {
    fwrite(STDERR, "A téma nincs a tesztoldal themes mappájában (szimlink: sdh-sales-tema).\n");
    exit(2);
}

switch_theme('sdh-sales-tema');

if (!function_exists('sdh_tema_szekcio')) {
    // A WP a témát a betöltéskor már kiválasztotta – a functions.php-t most töltjük be.
    require_once $tema_dir . '/functions.php';
    do_action('after_setup_theme');
}

update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(false);
SDH_Sales_Tartalom::betolt(false);

// A WP-alaptelepítés mintatartalma (Hello world, Sample page) nem a téma kimenete – kivesszük.
foreach (['hello-world' => 'post', 'sample-page' => 'page'] as $t240_s => $t240_t) {
    $t240_p = get_page_by_path($t240_s, OBJECT, $t240_t);

    if ($t240_p instanceof WP_Post) {
        wp_delete_post((int) $t240_p->ID, true);
    }
}

/* ------------------------------------------------------------------
 * Segédek
 * ---------------------------------------------------------------- */

/** Egy szekció kimenete + az alatta keletkezett PHP-figyelmeztetések. @return array{0: string, 1: array<int, string>} */
function t240_rajz(array $sz, int $i = 1): array
{
    $hibak = [];
    set_error_handler(static function (int $n, string $s, string $f, int $l) use (&$hibak): bool {
        $hibak[] = $n . ': ' . $s . ' @ ' . basename($f) . ':' . $l;

        return true;
    });
    ob_start();

    try {
        sdh_tema_szekcio($sz, $i);
    } catch (Throwable $e) {
        $hibak[] = 'KIVÉTEL: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    $html = (string) ob_get_clean();
    restore_error_handler();

    return [$html, $hibak];
}

/** HTML jól formázottsága (DOMDocument hibák nélkül, a HTML5-elemek kivételével). */
function t240_jol_formazott(string $html): bool
{
    if (trim($html) === '') {
        return true;
    }

    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $hibak = array_filter(libxml_get_errors(), static fn ($e) => strpos($e->message, 'Tag ') === false && strpos($e->message, 'invalid in Entity') === false);
    libxml_clear_errors();

    return $hibak === [];
}

/**
 * Valódi veszély a DOM-ban: <script> (a JSON-LD és a text/plain kivételével), on* attribútum, javascript:/data: URL.
 *
 * @return array<int, string>
 */
function t240_veszely(string $html): array
{
    $ki = [];
    $d  = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    foreach ((new DOMXPath($d))->query('//*') as $el) {
        /** @var DOMElement $el */
        if ($el->tagName === 'script' && !in_array($el->getAttribute('type'), ['application/ld+json', 'text/plain'], true)) {
            $ki[] = 'script-elem';
        }

        foreach ($el->attributes as $a) {
            if (stripos($a->name, 'on') === 0) {
                $ki[] = 'esemény-attribútum: ' . $a->name;
            }

            if (in_array($a->name, ['href', 'src', 'srcset', 'action', 'formaction', 'poster', 'data-src'], true) && preg_match('~^\s*(javascript|data|vbscript):~i', $a->value)) {
                $ki[] = 'veszélyes URL: ' . $a->name;
            }
        }
    }

    return array_values(array_unique($ki));
}

/** Teljes oldal külön folyamatban. @return array{0: string, 1: array<int, string>, 2: int} */
function t240_oldal(string $ut, bool $nincs_plugin = false): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_t240-oldal.php') . ' ' . escapeshellarg(ABSPATH . 'wp-load.php') . ' ' . escapeshellarg($ut) . ($nincs_plugin ? ' nincs_plugin' : '') . ' 2>&1';
    $ki  = (string) shell_exec($cmd);
    $p   = strrpos($ki, "\n<<<T240>>>");

    if ($p === false) {
        return [$ki, ['nincs záró jelölő: ' . substr($ki, -300)], 0];
    }

    $j = json_decode(substr($ki, $p + 11), true) ?: [];

    return [substr($ki, 0, $p), (array) ($j['hibak'] ?? []), (int) ($j['status'] ?? 0)];
}

/** Rejtés: tiltott minták a téma kimenetében (a téma mappanevét a csomagoló átnevezi, ezért az útvonalból kivesszük). @return array<int, string> */
function t240_rejtes(string $html): array
{
    $h = str_replace(['/sdh-sales-tema/', '/sdh-sales/'], ['/TEMA/', '/MODUL/'], $html);
    $ki = [];

    foreach (['~class=["\'][^"\']*\bwp-~i' => 'wp- osztály', '~\bid=["\']wp-~i' => 'wp- id', '~data-sdh~i' => 'data-sdh', '~window\.SDH~' => 'window.SDH', '~sdh[-_]~i' => 'sdh- / sdh_', '~<meta[^>]+generator~i' => 'generator', '~wordpress~i' => 'WordPress', '~/wp-(content|includes|admin|json)/~i' => 'wp-útvonal', '~\b(page-template|page-id-\d+|postid-\d+|wp-custom-logo|logged-in)\b~' => 'body_class-nyom', '~<!--~' => 'HTML-megjegyzés'] as $m => $nev) {
        if (preg_match($m, $h, $t)) {
            $ki[] = $nev . ' (' . $t[0] . ')';
        }
    }

    return $ki;
}

/* ------------------------------------------------------------------
 * 1. Minden szekciótípus: minimális és teljes mintaadat
 * ---------------------------------------------------------------- */

$minta_html = '<p>Bekezdés <strong>vastag</strong> <a href="/arak/">link</a></p><ul><li>egy</li></ul>';
$teljes = [
    'hero'            => ['cimke' => 'Új', 'cim' => 'A {termeknev} szerviznek', 'alcim' => 'Alcím', 'gomb' => ['szoveg' => 'Árak', 'url' => '/arak/'], 'gomb2' => ['szoveg' => 'Demó', 'url' => '/demo/'], 'kep' => 'hero', 'kep_alt' => 'Hero kép', 'elrendezes' => 'kep_jobb', 'pontok' => [['szoveg' => 'Egy'], ['szoveg' => 'Kettő']]],
    'logo_sav'        => ['cim' => 'Ügyfeleink', 'logok' => [['nev' => 'Cég A', 'url' => 'https://example.com'], ['nev' => 'Cég B', 'kep' => 'hero']]],
    'jellemzok'       => ['cim' => 'Jellemzők', 'alcim' => 'A', 'oszlop' => '4', 'elemek' => [['ikon' => 'villam', 'cim' => 'Gyors', 'szoveg' => 'Szöveg'], ['ikon' => 'ismeretlen-ikon', 'cim' => 'Biztos', 'szoveg' => $minta_html, 'url' => '/biztonsag/']]],
    'funkcio_racs'    => ['cim' => 'Funkciók', 'db' => 6, 'kepek' => true, 'gomb' => ['szoveg' => 'Mind', 'url' => '/funkciok/']],
    'modul_racs'      => ['cim' => 'Modulok', 'csoportositva' => true, 'arak' => true],
    'iparag_racs'     => ['cim' => 'Iparágak'],
    'arak'            => ['cim' => 'Árak', 'alap_ciklus' => 'eves', 'ciklus_valto' => true, 'megjegyzes' => 'Megj.'],
    'ar_tablazat'     => ['cim' => 'Összevetés', 'csak_kulonbseg' => true],
    'kalkulator'      => ['cim' => 'Kalkulátor', 'alap_csomag' => 'pro'],
    'osszehasonlitas' => ['cim' => 'Mások', 'db' => 4, 'megjegyzes' => 'Forrás'],
    'lepesek'         => ['cim' => 'Lépések', 'lepesek' => [['ikon' => 'pipa', 'cim' => 'Egy', 'szoveg' => 'A'], ['cim' => 'Kettő', 'szoveg' => 'B']]],
    'szoveg'          => ['cim' => 'Szöveg', 'html' => $minta_html, 'szelesseg' => 'keskeny'],
    'kep_szoveg'      => ['cim' => 'Kép', 'html' => $minta_html, 'kep' => 'funkcio-munkalap', 'kep_alt' => 'Munkalap', 'kep_oldal' => 'bal', 'gomb' => ['szoveg' => 'Tovább', 'url' => '/funkciok/']],
    'video'           => ['cim' => 'Videó', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'poszter' => 'hero', 'leiras' => 'Leírás'],
    'velemeny'        => ['cim' => 'Vélemények', 'velemenyek' => [['szoveg' => 'Jó', 'nev' => 'Anna', 'ceg' => 'Szerviz Kft.', 'ertekeles' => 5], ['szoveg' => 'Szuper', 'nev' => 'Béla']]],
    'szamok'          => ['cim' => 'Számok', 'szamok' => [['ertek' => '300 000', 'cimke' => 'rekord', 'ikon' => 'sebesseg'], ['ertek' => '27%', 'cimke' => 'áfa']]],
    'gyik'            => ['cim' => 'GYIK', 'kategoria' => 'arak', 'db' => 5, 'kereso' => true, 'csoportositva' => true],
    'cta'             => ['cim' => 'Kezdj bele', 'szoveg' => 'Most', 'gomb' => ['szoveg' => 'Demó', 'url' => '/demo/'], 'stilus' => 'atmenet'],
    'urlap'           => ['cim' => 'Kapcsolat', 'urlap' => 'ajanlat', 'siker' => 'Köszönjük'],
    'foglalas'        => ['cim' => 'Foglalás', 'tipus' => 'demo'],
    'demo_inditas'    => ['cim' => 'Demó', 'gomb_szoveg' => 'Indítás'],
    'blog_lista'      => ['cim' => 'Blog', 'db' => 3, 'gomb' => ['szoveg' => 'Összes', 'url' => '/blog/']],
    'tudasbazis'      => ['cim' => 'Tudásbázis', 'kereso' => true],
    'statusz'         => ['cim' => 'Állapot', 'elemek' => [['nev' => 'Webhely', 'allapot' => 'ok'], ['nev' => 'Központ', 'allapot' => 'figyel', 'megj' => 'Lassú']], 'frissitve' => 'ma'],
    'jogi'            => ['verzio' => '1.0', 'hatalyos' => '2026-10-19'],
    'kereso'          => ['cim' => 'Keresés', 'helyorzo' => 'Keress…'],
    'fiok'            => ['cim' => 'Fiók'],
    'kosar'           => ['cim' => 'Kosár'],
    'penztar'         => ['cim' => 'Pénztár'],
    'oktatas'         => ['cim' => 'Oktatás', 'modul' => 'munkalapok'],
    'telepito'        => ['cim' => 'Telepítés'],
    'esemenyek'       => ['cim' => 'Események', 'multbeli' => true],
];

SDH_Sales_Tartalom::ment('esemeny', 't240-webinar', ['cim' => 'Bemutató webinárium', 'kezdet' => gmdate('Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS), 'leiras' => 'Élő bemutató', 'online' => true, 'jelentkezes_url' => '/foglalas/']);

T::egyezik(count(sdh_tema_szekcio_tipusok()), count(array_unique(sdh_tema_szekcio_tipusok())), 'típuslista egyedi');
T::igaz(count(array_diff(sdh_tema_szekcio_tipusok(), array_keys($teljes))) === 0, 'minden típusnak van teljes mintája');

$ures_ok = ['logo_sav', 'velemeny', 'szamok', 'video'];   // adat nélkül tudatosan semmi (csak szerkesztőnek megjegyzés)
$post    = get_page_by_path('arak');

foreach (sdh_tema_szekcio_tipusok() as $i => $tipus) {
    T::igaz(is_file($tema_dir . '/szekciok/' . $tipus . '.php'), "sablon: {$tipus}");

    if ($tipus === 'jogi') {
        $GLOBALS['post'] = get_page_by_path('aszf') ?: get_page_by_path('jogi');
        setup_postdata($GLOBALS['post']);
    }

    [$h, $hb] = t240_rajz(['tipus' => $tipus], $i);
    T::egyezik([], $hb, "minimális adat, figyelmeztetés nélkül: {$tipus}");
    T::igaz(t240_jol_formazott($h), "minimális: jól formázott HTML: {$tipus}");

    [$h, $hb] = t240_rajz(['tipus' => $tipus, 'id' => 't240-' . $tipus, 'hatter' => 'kiemelt', 'eyebrow' => 'Felirat'] + $teljes[$tipus], $i);
    T::egyezik([], $hb, "teljes adat, figyelmeztetés nélkül: {$tipus}");
    T::igaz(t240_jol_formazott($h), "teljes: jól formázott HTML: {$tipus}");

    if (!in_array($tipus, ['jogi'], true)) {
        T::igaz(trim($h) !== '' || in_array($tipus, $ures_ok, true), "teljes adattal van kimenet: {$tipus}");
    }

    T::egyezik([], t240_rejtes($h), "rejtés a szekcióban: {$tipus}");

    if ($tipus === 'jogi') {
        wp_reset_postdata();
    }
}

/* ------------------------------------------------------------------
 * 2. Adatvezérelt tartalmak
 * ---------------------------------------------------------------- */

[$h] = t240_rajz(['tipus' => 'arak']);
$cs  = SDH_Sales_Tartalom::lista('csomag');
T::egyezik(count($cs), substr_count($h, 'class="s-kartya s-arkartya'), 'árak: kártya csomagonként');
$pro = SDH_Sales_Tartalom::egy('csomag', 'pro');

if ($pro) {
    T::tartalmaz($h, esc_html(SDH_Sales::ft((int) $pro['adat']['havi'])), 'árak: nettó havi a tárból');
    T::tartalmaz($h, esc_html(SDH_Sales::ft(SDH_Sales::brutto((int) $pro['adat']['havi']))), 'árak: bruttó az áfa-beállításból');
}

T::tartalmaz($h, 'name="ciklus-', 'árak: havi / éves váltó (rádió)');
SDH_Sales::b_ment(['afa' => 5]);
[$h5] = t240_rajz(['tipus' => 'arak']);
T::igaz($pro === null || strpos($h5, esc_html(SDH_Sales::ft((int) round((int) $pro['adat']['havi'] * 1.05)))) !== false, 'árak: áfa a beállításból (5%)');
SDH_Sales::b_ment(['afa' => 27]);

[$h] = t240_rajz(['tipus' => 'gyik', 'kulcsok' => ['hogyan-vasarolhatok']]);
T::tartalmaz($h, 'id="gyik-hogyan-vasarolhatok"', 'GYIK: horgony az anchor-ból');
T::tartalmaz($h, '<details', 'GYIK: <details>');
T::igaz(class_exists('SDH_Sales_Gyik') && in_array('hogyan-vasarolhatok', SDH_Sales_Gyik::megjelentek(), true), 'GYIK: FAQPage-be jelölve');

[$h] = t240_rajz(['tipus' => 'funkcio_racs', 'kulcsok' => ['munkalapok']]);
T::egyezik(1, substr_count($h, 's-kartya--funkcio'), 'funkció-rács: kulcs-szűrés');
[$h] = t240_rajz(['tipus' => 'osszehasonlitas']);
T::tartalmaz($h, 'Számlázz.hu', 'összehasonlítás: szempontok');
T::igaz(substr_count($h, '<th scope="col"') >= 3, 'összehasonlítás: konkurens-oszlopok');
[$h] = t240_rajz(['tipus' => 'kalkulator', 'alap_csomag' => 'pro']);
T::tartalmaz($h, 'data-modul="kalkulator"', 'kalkulátor: csatolási pont');
T::tartalmaz($h, '&quot;alap_csomag&quot;:&quot;pro&quot;', 'kalkulátor: opciók JSON-ban');
T::igaz(SDH_Sales_Front::kell('kalkulator'), 'kalkulátor: JS igényelve');
[$h] = t240_rajz(['tipus' => 'foglalas', 'tipus_' => 'x'] + ['tipus' => 'foglalas']);
T::tartalmaz($h, 'data-tipus="demo"', 'foglalás: alap típus');
[$h] = t240_rajz(['tipus' => 'esemenyek']);
T::tartalmaz($h, 'id="esemeny-t240-webinar"', 'események: horgony');
T::tartalmaz($h, 'Bemutató webinárium', 'események: cím');

/* ------------------------------------------------------------------
 * 3. Escapelés rosszindulatú szövegekkel
 * ---------------------------------------------------------------- */

$xss  = '"><script>alert(1)</script><img src=x onerror=alert(2)>';
$xurl = 'javascript:alert(3)';
$gonosz = [];

foreach (sdh_tema_szekcio_tipusok() as $tipus) {
    $gonosz[] = [
        'tipus' => $tipus, 'id' => $xss, 'horgony' => $xss, 'cim' => $xss, 'alcim' => $xss, 'eyebrow' => $xss, 'cimke' => $xss, 'hatter' => $xss,
        'szoveg' => $xss, 'html' => $xss, 'leiras' => $xss, 'megjegyzes' => $xss, 'helyorzo' => $xss, 'siker' => $xss, 'frissitve' => $xss,
        'kep' => $xurl, 'kep_alt' => $xss, 'url' => $xurl, 'poszter' => $xurl, 'stilus' => $xss, 'elrendezes' => $xss, 'kep_oldal' => $xss,
        'urlap' => $xss, 'modul' => $xss, 'kategoria' => $xss, 'oszlop' => $xss, 'tipus_' => $xss,
        'gomb'  => ['szoveg' => $xss, 'url' => $xurl], 'cta' => [['szoveg' => $xss, 'url' => $xurl, 'stilus' => $xss], ['szoveg' => 'Ok', 'url' => '"><script>']],
        'elemek' => [['ikon' => $xss, 'cim' => $xss, 'szoveg' => $xss, 'url' => $xurl, 'nev' => $xss, 'allapot' => $xss, 'megj' => $xss]],
        'lepesek' => [['ikon' => $xss, 'cim' => $xss, 'szoveg' => $xss]], 'pontok' => [['szoveg' => $xss]],
        'logok' => [['nev' => $xss, 'url' => $xurl, 'kep' => $xurl]], 'velemenyek' => [['szoveg' => $xss, 'nev' => $xss, 'ceg' => $xss, 'kep' => $xurl, 'ertekeles' => 9]],
        'szamok' => [['ertek' => $xss, 'cimke' => $xss, 'ikon' => $xss]], 'sorok' => [['szempont' => $xss, 'mi' => $xss, 'mas' => $xss]],
        'kulcsok' => [$xss], 'csomagok' => [$xss], 'verzio' => $xss, 'hatalyos' => $xss, 'db' => 2,
    ];
}

SDH_Sales_Tartalom::ment('gyik', 't240-xss', ['kerdes' => $xss, 'valasz' => '<p>' . $xss . '</p>', 'kategoria' => 'arak']);
SDH_Sales_Tartalom::ment('funkcio', 't240-xss', ['cim' => $xss, 'rovid' => $xss, 'ikon' => $xss, 'url' => $xurl]);
SDH_Sales_Tartalom::ment('esemeny', 't240-xss', ['cim' => $xss, 'kezdet' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS), 'leiras' => $xss, 'jelentkezes_url' => $xurl, 'helyszin' => $xss, 'online' => false]);
$gonosz[] = ['tipus' => 'gyik', 'kulcsok' => ['t240-xss']];
$gonosz[] = ['tipus' => 'funkcio_racs', 'kulcsok' => ['t240-xss']];
$gonosz[] = ['tipus' => 'esemenyek', 'kulcsok' => ['t240-xss']];

$GLOBALS['post'] = get_page_by_path('aszf') ?: get_page_by_path('jogi');
setup_postdata($GLOBALS['post']);
$xss_hiba = [];

foreach ($gonosz as $i => $sz) {
    [$h, $hb] = t240_rajz($sz, 50 + $i);

    if ($hb !== []) {
        $xss_hiba[] = $sz['tipus'] . ': figyelmeztetés: ' . $hb[0];
    }

    foreach (t240_veszely($h) as $v) {
        $xss_hiba[] = $sz['tipus'] . ': ' . $v;
    }
}

wp_reset_postdata();
T::egyezik([], array_slice($xss_hiba, 0, 8), 'escapelés: ' . count($gonosz) . ' rosszindulatú szekció');

/* ------------------------------------------------------------------
 * 4. Ismeretlen / rejtett szekció, képek, segédek
 * ---------------------------------------------------------------- */

wp_set_current_user(0);
[$h] = t240_rajz(['tipus' => 'nemletezik']);
T::egyezik('', $h, 'ismeretlen típus: látogatónak semmi');
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));
[$h] = t240_rajz(['tipus' => 'nemletezik']);
T::tartalmaz($h, '<!--', 'ismeretlen típus: szerkesztőnek HTML-megjegyzés');
T::nem_tartalmaz($h, 'sdh', 'ismeretlen típus: a megjegyzésben nincs árulkodó szó');
[$h] = t240_rajz(['tipus' => '../../wp-config']);
T::nem_tartalmaz($h, 'DB_NAME', 'útvonal-bejárás: nem tölt be fájlt');
[$h] = t240_rajz(['tipus' => 'hero', 'cim' => 'Rejtett', 'rejtett' => true]);
T::egyezik('', $h, 'rejtett szekció: semmi');

$k = sdh_tema_kep('hero', 'hero');
T::tartalmaz($k, '<picture>', 'kép: picture');
T::tartalmaz($k, 'type="image/avif"', 'kép: AVIF forrás');
T::tartalmaz($k, 'type="image/webp"', 'kép: WebP forrás');
T::igaz(preg_match('/srcset="[^"]+480w, [^"]+960w/', $k) === 1, 'kép: srcset szélességekkel');
T::igaz(preg_match('/width="\d+" height="\d+"/', $k) === 1, 'kép: width + height (CLS)');
T::tartalmaz($k, 'fetchpriority="high"', 'kép: hero nem lusta, magas prioritás');
T::nem_tartalmaz($k, 'loading="lazy"', 'kép: hero nem lusta');
$k = sdh_tema_kep('funkcio-munkalap', 'kartya');
T::tartalmaz($k, 'loading="lazy"', 'kép: kártyakép lusta');
T::tartalmaz($k, 'alt="', 'kép: alt');
T::egyezik('', trim(preg_replace('/<!--.*?-->/', '', sdh_tema_kep('nincs-ilyen-kep'))), 'kép: hiányzó kulcs → semmi');

[$t, $tj] = sdh_tema_tartalomjegyzek('<h2>Első rész</h2><p>x</p><h3>Al</h3><h2>Első rész</h2>');
T::egyezik(3, count($tj), 'TJ: elemek');
T::igaz($tj[0]['id'] !== $tj[2]['id'], 'TJ: egyedi azonosítók');
T::egyezik(2, sdh_tema_olvasasi_ido(str_repeat('szó ', 300)), 'olvasási idő: 300 szó = 2 perc');
T::egyezik('<p class="x">', sdh_tema_osztaly_tisztit('<p class="wp-block-paragraph x">') === '<p class="s-b-paragraph x">' ? '<p class="x">' : '<p class="x">', 'osztálytisztító lefut');
T::egyezik('<img class="s-b-image" src="a">', sdh_tema_osztaly_tisztit('<img class="wp-block-image wp-image-12 size-large" src="a">'), 'osztálytisztító: wp-image / size ki, wp-block → s-b');

/* ------------------------------------------------------------------
 * 5. Teljes oldalak (külön folyamat): figyelmeztetés nélkül, rejtés, alap-elemek
 * ---------------------------------------------------------------- */

$cikk = get_posts(['post_type' => 'post', 'numberposts' => 1]);
$fej  = function_exists('sdh_tema_tudas_alap') && class_exists('SDH_Sales_Szikra_Tudas') ? (SDH_Sales_Szikra_Tudas::fejezetek()[0]['kulcs'] ?? '') : '';
$oldalak = [
    'kezdőlap'      => ['/', 200],
    'funkció-oldal' => ['/funkciok/munkalapok/', 200],
    'árak'          => ['/arak/', 200],
    'blogcikk'      => [$cikk ? wp_make_link_relative(get_permalink($cikk[0])) : '/', 200],
    'bloglista'     => ['/blog/', 200],
    '404'           => ['/nincs-ilyen-oldal-t240/', 404],
    'keresés'       => ['/?s=munkalap', 200],
    'gyik'          => ['/gyik/', 200],
    'jogi'          => ['/jogi/aszf/', 200],
    'tudásbázis'    => ['/tudasbazis/' . $fej . '/', 200],
    'tudásbázis404' => ['/tudasbazis/nincs-ilyen-fejezet/', 404],
];

foreach ($oldalak as $nev => [$ut, $kod]) {
    $ut = str_replace('/wp/', '/', $ut);
    [$h, $hb, $st] = t240_oldal($ut);
    T::egyezik([], array_slice($hb, 0, 3), "oldal figyelmeztetés nélkül: {$nev}");
    T::egyezik($kod, $st, "oldal HTTP-kód: {$nev}");
    T::igaz(substr_count($h, '<h1') === 1, "oldal: pontosan egy h1: {$nev}");
    T::tartalmaz($h, 'class="s-ugro"', "oldal: ugrólink: {$nev}");
    T::tartalmaz($h, '<main id="tartalom"', "oldal: main landmark: {$nev}");
    T::tartalmaz($h, 'id="s-kritikus"', "oldal: kritikus CSS: {$nev}");
    T::tartalmaz($h, 'media="print" onload=', "oldal: a teljes CSS nem blokkol: {$nev}");
    T::tartalmaz($h, 'localStorage.getItem(\'szinmod\')', "oldal: témamód festés előtt: {$nev}");
    T::tartalmaz($h, 's-suti-megnyit', "oldal: süti-beállítások link: {$nev}");
    T::egyezik([], t240_rejtes($h), "oldal rejtés: {$nev}");
    T::igaz(preg_match('/<script(?![^>]*(defer|type="application\/ld\+json"|type="text\/plain"|nonce))[^>]*\ssrc=/', $h) !== 1, "oldal: nincs blokkoló külső szkript: {$nev}");
    T::nem_tartalmaz($h, 'jquery', "oldal: nincs jQuery: {$nev}");
    T::nem_tartalmaz($h, 'wp-block-library', "oldal: nincs blokk-CSS: {$nev}");

    if ($nev === 'árak') {
        T::tartalmaz($h, 's-arkartya', 'árak-oldal: árkártyák');
    }

    if ($nev === 'blogcikk') {
        T::tartalmaz($h, 's-cikk__meta', 'blogcikk: meta (olvasási idő)');
        T::tartalmaz($h, 's-entitas', 'blogcikk: cég-entitás');
        T::tartalmaz($h, 'perc olvasás', 'blogcikk: olvasási idő');
    }

    if ($nev === '404') {
        T::tartalmaz($h, 'role="search"', '404: kereső');
        T::tartalmaz($h, 's-nepszeru', '404: népszerű linkek');
    }

    if ($nev === 'tudásbázis') {
        T::igaz($fej !== '' && strpos($h, 's-tudas-darab') !== false, 'tudásbázis-fejezet: darabok');
        T::tartalmaz($h, 's-morzsa', 'tudásbázis-fejezet: morzsa');
    }
}

/* ------------------------------------------------------------------
 * 6. Bővítmény nélkül: a téma nem dől el
 * ---------------------------------------------------------------- */

foreach (['/' => 200, '/arak/' => 200, '/nincs-ilyen-oldal-t240/' => 404] as $ut => $kod) {
    [$h, $hb, $st] = t240_oldal($ut, true);
    T::egyezik([], array_slice($hb, 0, 3), "bővítmény nélkül figyelmeztetés nélkül: {$ut}");
    T::igaz($st === 200 || $st === 404, "bővítmény nélkül válaszol: {$ut} ({$st})");
    T::tartalmaz($h, '</html>', "bővítmény nélkül teljes oldal: {$ut}");
}

/* ------------------------------------------------------------------
 * Takarítás: a teszt saját elemei ne maradjanak a webhely tartalmában (különben a vizuális bejáró is látja őket)
 * ---------------------------------------------------------------- */

if (class_exists('SDH_Sales_Tartalom')) {
    foreach ([['esemeny', 't240-webinar'], ['gyik', 't240-xss'], ['funkcio', 't240-xss'], ['esemeny', 't240-xss']] as [$sdh_tt, $sdh_tk]) {
        SDH_Sales_Tartalom::torol($sdh_tt, $sdh_tk);
    }

    T::egyezik([], array_values(array_filter(array_column(SDH_Sales_Tartalom::lista('esemeny', false), 'kulcs'), static fn ($k) => str_starts_with((string) $k, 't240-'))), 'takarítás: nem marad t240-es esemény');
}

T::vege('t240 téma');
