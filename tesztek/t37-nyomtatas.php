<?php
/**
 * t37 – 0.52: nyomtatványok (PDF), Nyomtatás ablak, sablonok, felajánlás,
 * e-mail; a kulcsok titkosított tárolása; független napi mentés; a zárás
 * utáni emlékeztető.
 *
 * A PDF szövegét a `pdftotext` olvassa ki; ahol nincs telepítve, a
 * szövegre vonatkozó állítások kimaradnak (a PDF elkészültét akkor is nézzük).
 */

require __DIR__ . '/_keret.php';

$N = 'SDH_Muhely_Nyomtatas';
$P = 'SDH_Muhely_Nyomtatvany';

global $wpdb;

$figy = [];
set_error_handler(static function (int $szint, string $szoveg, string $fajl, int $sor) use (&$figy): bool {
    if (!(error_reporting() & $szint)) {
        return true; // @-tal elnémított hiba (pl. gyorsítótár törlése): nem számít
    }
    if (strpos($fajl, 'sdh-muhely') !== false) {
        $figy[] = basename($fajl) . ':' . $sor . ' ' . $szoveg;
    }

    return true;
});

SDH_Muhely_Modulok::kontextus_beallit('frontend');

$regi = [];

foreach (['sdh_muhely_nyomtatas', 'sdh_muhely_szamla', 'sdh_muhely_imei_szolgaltato', 'sdh_muhely_mentes', 'sdh_muhely_penztar', 'sdh_muhely_titok_atallas'] as $o) {
    $regi[$o] = get_option($o, null);
}

// A teszt beállításokat ír át: ha bármi megszakítaná, a kilépéskor akkor is visszaállnak
// (különben a következő tesztek a félbehagyott beállításokkal futnának).
register_shutdown_function(static function () use (&$regi): void {
    foreach ($regi as $o => $ertek) {
        if ($ertek === null) {
            delete_option($o);
        } else {
            update_option($o, $ertek, false);
        }
    }

    delete_option('sdh_muhely_mentes_fut');
});

delete_option('sdh_muhely_nyomtatas');
delete_user_meta(get_current_user_id(), 'sdh_muhely_ny_ajanlat');

/** A PDF szövege (pdftotext), vagy null, ha az eszköz nincs meg. */
function t_pdf_szoveg(string $pdf): ?string
{
    static $van = null;

    if ($van === null) {
        $van = function_exists('shell_exec') && trim((string) @shell_exec('command -v pdftotext 2>/dev/null')) !== '';
    }

    if (!$van) {
        return null;
    }

    $f = tempnam(sys_get_temp_dir(), 'sdhpdf');
    file_put_contents($f, $pdf);
    $ki = (string) shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
    unlink($f);

    return $ki;
}

/** Hány oldalas a PDF. */
function t_pdf_oldal(string $pdf): int
{
    return (int) preg_match_all('~/Type /Page\b(?!s)~', $pdf);
}

/* ======================================================================== */
/* 1. Titkok: a kulcsok titkosítva állnak az adatbázisban                    */
/* ======================================================================== */

$T = 'SDH_Muhely_Titok';

T::igaz(class_exists($T) && $T::elerheto(), 'a titkosító elérhető');

$kod = $T::titkosit('titkos-kulcs-ÁÉŐ-123');
T::igaz($T::titkos_e($kod) && strpos($kod, 'titkos-kulcs') === false, 'a tárolt alak nem tartalmazza a nyílt szöveget');
T::egyezik('titkos-kulcs-ÁÉŐ-123', $T::visszafejt($kod), 'oda-vissza ugyanaz');
T::egyezik($kod, $T::titkosit($kod), 'ami már titkos, azt nem titkosítja újra');
T::egyezik('regi-nyilt', $T::visszafejt('regi-nyilt'), 'a régi, titkosítatlan érték olvasható marad');
T::egyezik('', $T::titkosit(''), 'az üres üres marad');
T::igaz($T::titkosit('a') !== $T::titkosit('a'), 'kétszer titkosítva más a tárolt alak (véletlen kezdőérték)');
T::igaz($T::olvashatatlan('sdh1:' . base64_encode(str_repeat('x', 60))), 'a más kulccsal készült érték olvashatatlan – nem ad vissza szemetet');
T::egyezik('', $T::visszafejt('sdh1:' . base64_encode(str_repeat('x', 60))), 'olvashatatlan érték → üres');

// Számla Agent kulcs: mentéskor titkosítva, olvasáskor nyíltan.
$_POST = ['szamla_alap_fizmod' => 'Készpénz', 'szamla_kulcs' => 'agent-KULCS-0042'];
SDH_Muhely_Szamla::beallitas_mentes();
$_POST = [];
$nyers = (array) get_option('sdh_muhely_szamla');

T::igaz($T::titkos_e((string) $nyers['kulcs']), 'a Számla Agent kulcs az adatbázisban titkosítva áll');
T::nem_tartalmaz((string) wp_json_encode($nyers), 'agent-KULCS-0042', 'a nyílt kulcs sehol nincs a tárolt beállításban');
T::egyezik('agent-KULCS-0042', SDH_Muhely_Szamla::beallitas()['kulcs'], 'a számlázás a nyílt kulcsot kapja');
T::igaz(SDH_Muhely_Szamla::beallitva(), 'a számlázás beállítottnak látja');

// Más beállítás mentése (üres kulcsmezővel) nem bántja a mentett kulcsot.
$_POST = ['szamla_alap_fizmod' => 'Bankkártya', 'szamla_kulcs' => ''];
SDH_Muhely_Szamla::beallitas_mentes();
$_POST = [];
T::egyezik('agent-KULCS-0042', SDH_Muhely_Szamla::beallitas()['kulcs'], 'üres kulcsmezővel mentve a Számla Agent kulcs megmarad');
T::igaz($T::titkos_e((string) get_option('sdh_muhely_szamla')['kulcs']), '…és továbbra is titkosítva áll');

// A nem olvasható (más sókkal titkosított) kulcsot egy másik mező mentése nem írja felül üresre.
$jo_tarolt  = (array) get_option('sdh_muhely_szamla');
$olvashatatlan = 'sdh1:' . base64_encode(str_repeat('y', 70));
update_option('sdh_muhely_szamla', ['kulcs' => $olvashatatlan] + $jo_tarolt, false);
T::egyezik('', SDH_Muhely_Szamla::beallitas()['kulcs'], 'más sókkal titkosított kulcs: a számlázás üresnek látja');
$_POST = ['szamla_alap_fizmod' => 'Készpénz', 'szamla_kulcs' => ''];
SDH_Muhely_Szamla::beallitas_mentes();
$_POST = [];
T::egyezik($olvashatatlan, (string) get_option('sdh_muhely_szamla')['kulcs'], 'más mező mentése a nem olvasható kulcsot érintetlenül hagyja (a sók visszaállítása után újra működne)');
ob_start();
$T::figyelmeztetes('sdh_muhely_szamla');
T::tartalmaz((string) ob_get_clean(), 'nem olvasható', 'a lapfül figyelmeztet a nem olvasható kulcsra');
update_option('sdh_muhely_szamla', $jo_tarolt, false);
T::egyezik('agent-KULCS-0042', SDH_Muhely_Szamla::beallitas()['kulcs'], 'a visszaállított kulcs újra olvasható');

// IMEI-kulcs.
$_POST = ['imei_jelen' => '1', 'url' => 'https://pelda.hu/api?key={kulcs}&imei={imei}', 'kulcs' => 'imei-KULCS-77', 'aktiv' => '1'];
SDH_Muhely_Beallitasok::imei_mentes();
$nyers_i = get_option('sdh_muhely_imei_szolgaltato');
T::igaz($T::titkos_e((string) $nyers_i['kulcs']), 'az IMEI-kulcs titkosítva áll');
T::egyezik('imei-KULCS-77', SDH_Muhely_Imei_Lekerdezes::beallitas()['kulcs'], 'az IMEI-lekérdezés a nyílt kulcsot kapja');

// Üres mező: a mentett kulcs marad; pipa: törlődik.
$_POST = ['imei_jelen' => '1', 'url' => 'https://pelda.hu/api?key={kulcs}&imei={imei}', 'kulcs' => '', 'aktiv' => '1'];
SDH_Muhely_Beallitasok::imei_mentes();
T::egyezik('imei-KULCS-77', SDH_Muhely_Imei_Lekerdezes::beallitas()['kulcs'], 'üres mezővel mentve a kulcs megmarad');
$_POST['kulcs_torol'] = '1';
SDH_Muhely_Beallitasok::imei_mentes();
T::egyezik('', SDH_Muhely_Imei_Lekerdezes::beallitas()['kulcs'], '„Kulcs törlése" → üres');
$_POST = [];

ob_start();
SDH_Muhely_Beallitasok::imei_doboz();
$doboz = (string) ob_get_clean();
T::nem_tartalmaz($doboz, 'imei-KULCS-77', 'az IMEI-kulcs nem kerül vissza a HTML-be');
T::tartalmaz($doboz, 'type="password" name="kulcs"', 'az IMEI-kulcs mezője jelszómező');

// Átállás: a régi, nyílt érték magától titkosítva lesz.
update_option('sdh_muhely_imei_szolgaltato', ['url' => 'https://pelda.hu', 'kulcs' => 'regi-nyilt-imei', 'aktiv' => true], false);
$sz_nyers          = get_option('sdh_muhely_szamla');
$sz_nyers['kulcs'] = 'regi-nyilt-agent';
update_option('sdh_muhely_szamla', $sz_nyers, false);
delete_option('sdh_muhely_titok_atallas');
$T::atallas();
T::igaz($T::titkos_e((string) get_option('sdh_muhely_imei_szolgaltato')['kulcs']), 'átállás: a régi IMEI-kulcs titkosítva');
T::igaz($T::titkos_e((string) get_option('sdh_muhely_szamla')['kulcs']), 'átállás: a régi Számla Agent kulcs titkosítva');
T::egyezik('regi-nyilt-agent', SDH_Muhely_Szamla::beallitas()['kulcs'], 'átállás után is ugyanaz a kulcs');
T::egyezik('1', get_option('sdh_muhely_titok_atallas'), 'az átállás egyszer fut');

/* ======================================================================== */
/* 2. Független napi mentés                                                  */
/* ======================================================================== */

$M = 'SDH_Muhely_Mentes';

delete_option('sdh_muhely_mentes');
T::igaz($M::beallitas()['aktiv'], 'a napi mentés alapból be van kapcsolva');
T::egyezik(20, $M::megtartas(), 'alapból 20 mentés marad meg');
T::igaz((bool) wp_next_scheduled('sdh_muhely_napi_mentes') || (function () use ($M) {
    $M::cron_beallit();

    return (bool) wp_next_scheduled('sdh_muhely_napi_mentes');
})(), 'a napi mentés időzítve van');

$mappa = SDH_Muhely_Asszisztens_Tudas::mentes_mappa();

foreach (glob($mappa . 'sdh-mentes-*.jsonl.gz') ?: [] as $f) {
    unlink($f);
}

T::igaz(!$M::ma_megvan(), 'mentés nélkül: ma még nincs');
$r = $M::keszit();
T::igaz(is_array($r) && $r['sorok'] > 0, 'a napi mentés elkészül az asszisztens nélkül is');
T::igaz($M::ma_megvan(), 'utána: a mai megvan');
T::igaz($M::keszit() === null, 'ugyanaznap nem készül még egy (nem kell)');
T::tartalmaz((string) $r['fajl'], '-napi-', 'a fájl neve jelzi, hogy napi mentés');

T::egyezik([], glob($mappa . '*.tmp') ?: [], 'nem marad félkész (ideiglenes) mentésfájl');
T::igaz(get_option('sdh_muhely_mentes_fut', null) === null, 'a mentés végén a zár felszabadul');

// Egyszerre csak egy mentés fut: friss zárral a második nem indul el, a beragadt (régi) zárat elengedi.
add_option('sdh_muhely_mentes_fut', (string) time(), '', false);
T::igaz($M::keszit(true) === null, 'futó mentés mellett nem indul második');
update_option('sdh_muhely_mentes_fut', (string) (time() - 3600), false);
T::igaz(is_array($M::keszit(true)), 'a beragadt (régi) zár nem akasztja meg a mentést');
T::igaz(get_option('sdh_muhely_mentes_fut', null) === null, 'utána a zár megint szabad');

$tartalom = (string) gzdecode((string) file_get_contents($mappa . $r['fajl']));
T::tartalmaz($tartalom, 'sdh_muhely_szamla', 'a mentésben benne vannak a beállítások');
T::nem_tartalmaz($tartalom, 'regi-nyilt-agent', 'a mentésfájlban NINCS benne nyíltan a Számla Agent kulcs');
T::nem_tartalmaz($tartalom, 'regi-nyilt-imei', 'a mentésfájlban NINCS benne nyíltan az IMEI-kulcs');

// Megőrzés: a beállított darabszám fölötti régiek törlődnek.
$_POST = ['mentes_jelen' => '1', 'mentes_aktiv' => '1', 'mentes_megtart' => '3'];
$M::beallitas_mentes();
$_POST = [];
T::egyezik(3, $M::megtartas(), 'a megőrzött mentések száma állítható');

// Tiszta lappal: a megőrzés az előző napi és a visszaállítás előtti mentést is védi (t66 / t67 hagyhat ilyet).
foreach (glob($mappa . 'sdh-mentes-*.jsonl.gz') ?: [] as $regi_mentes) {
    @unlink($regi_mentes);
}

for ($i = 0; $i < 4; $i++) {
    $M::keszit(true);
}

T::igaz(count(SDH_Muhely_Asszisztens_Tudas::mentesek()) <= 3, 'legfeljebb a beállított számú mentés marad meg');

$_POST = ['mentes_jelen' => '1', 'mentes_megtart' => '20'];
$M::beallitas_mentes();
$_POST = [];
T::igaz(!$M::beallitas()['aktiv'], 'a napi mentés kikapcsolható');

foreach (glob($mappa . 'sdh-mentes-*.jsonl.gz') ?: [] as $f) {
    unlink($f);
}

T::igaz($M::keszit() === null, 'kikapcsolva nem készül automatikus mentés');
T::igaz(is_array($M::keszit(true)), 'kézzel („Mentés most") kikapcsolva is készül');
delete_option('sdh_muhely_mentes');

/* ======================================================================== */
/* 3. Nyomtatványok: minden típus elkészül, a tartalom a sablon szerint      */
/* ======================================================================== */

$u  = t_ugyfel('Nyomtat Nóra');
$e1 = t_eszkoz($u, 'Mintagyár', 'Alfa 1');
$e2 = t_eszkoz($u, 'Mintagyár', 'Béta 2');

$wpdb->update(SDH_Muhely_Schema::tabla('eszkoz'), ['zarkod' => '4711', 'tartozekok' => 'tok és töltő', 'belso_megjegyzes' => 'ESZKOZBELSO', 'szin' => 'kék'], ['id' => $e1]);

$ml = t_munkalap($u, [$e1, $e2], 'nyitott', ['megjegyzes' => 'LAPBELSO megjegyzés', 'ugyfel_megjegyzes' => 'UGYFELNEK szóló megjegyzés']);
t_tetel($ml, 'Kijelzőcsere munkadíj', 12000, 0);
t_tetel($ml, 'Akkumulátor', 9000, $e2, 'termek');
SDH_Muhely_Tetel::ujraszamol($ml);
$wpdb->insert(SDH_Muhely_Schema::tabla('munkalap_hiba'), ['munkalap_id' => $ml, 'leiras' => 'Törött kijelző', 'javitas' => 'JAVITASSZOVEG', 'allapot' => 'uj', 'eszkoz_id' => 0, 'letrehozva' => current_time('mysql')]);

$lap    = t_lap($ml);
$ugyfel = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', $u));

T::egyezik(14, count($N::tipusok()), '14 nyomtatvány van');

foreach (array_keys($N::tipusok()) as $k) {
    $s = $N::sablon($k);
    T::igaz($s['cim'] !== '' && $s['peldany'] >= 1, 'sablon: ' . $k . ' – van címe és példányszáma');
}

T::egyezik(2, $N::sablon('atveteli')['peldany'], 'az átvételi elismervény alapból 2 példány');
T::egyezik('', $N::sablon('atveteli')['jogi'], 'a hosszú (jogi) szöveg alapból üres – nincs cégadat a kódban');

$keszit = static function (string $tipus, array $p = [], int $peldany = 1) use ($P) {
    $r = $P::keszit($tipus, $p, $peldany);

    return is_array($r) ? $r + ['szoveg' => t_pdf_szoveg($r['pdf'])] : $r;
};

$van_szoveg = t_pdf_szoveg('%PDF-1.3') !== null;

// --- Átvételi elismervény
$r = $keszit('atveteli', ['id' => $ml]);
T::igaz(is_array($r) && strncmp($r['pdf'], '%PDF-', 5) === 0, 'átvételi: PDF készül');
T::tartalmaz((string) $r['nev'], '.pdf', 'átvételi: van fájlneve');
T::egyezik($r['oldal'], t_pdf_oldal($r['pdf']), 'átvételi: az oldalszám egyezik');

if ($van_szoveg) {
    T::tartalmaz($r['szoveg'], 'ÁTVÉTELI ELISMERVÉNY', 'átvételi: a cím nagybetűvel a fejben');
    T::tartalmaz($r['szoveg'], (string) $ugyfel->nev, 'átvételi: az ügyfél neve');
    T::tartalmaz($r['szoveg'], 'Mintagyár Alfa 1', 'átvételi: az első eszköz');
    T::tartalmaz($r['szoveg'], 'Mintagyár Béta 2', 'átvételi: a második eszköz is (több eszközös lap)');
    T::tartalmaz($r['szoveg'], 'Törött kijelző', 'átvételi: a bejelentett hiba');
    T::tartalmaz($r['szoveg'], 'tok és töltő', 'átvételi: a tartozékok');
    T::tartalmaz($r['szoveg'], 'UGYFELNEK', 'átvételi: az ügyfélnek szóló megjegyzés');
    T::nem_tartalmaz($r['szoveg'], 'LAPBELSO', 'átvételi: a belső megjegyzés NEM látszik');
    T::nem_tartalmaz($r['szoveg'], 'ESZKOZBELSO', 'átvételi: az eszköz belső megjegyzése NEM látszik');
    T::nem_tartalmaz($r['szoveg'], '4711', 'átvételi: a zárkód NEM látszik');
    T::nem_tartalmaz($r['szoveg'], '12 000', 'átvételi: ár nincs rajta');
    T::tartalmaz($r['szoveg'], 'Oldal 1/' . $r['oldal'], 'átvételi: lábléc oldalszámmal');
}

// --- Példányszám
$r3 = $keszit('atveteli', ['id' => $ml], 3);
T::egyezik(3 * $r['oldal'], t_pdf_oldal($r3['pdf']), '3 példány = háromszor annyi oldal');

if ($van_szoveg) {
    T::tartalmaz($r3['szoveg'], '1. példány', 'a példány sorszáma a láblécben');
    T::tartalmaz($r3['szoveg'], '3. példány', 'a harmadik példány is megvan');
    T::egyezik(3, substr_count($r3['szoveg'], 'Oldal 1/' . $r['oldal']), 'az oldalszámozás példányonként újraindul');
}

T::egyezik(9 * $r['oldal'], t_pdf_oldal($keszit('atveteli', ['id' => $ml], 50)['pdf']), 'legfeljebb 9 példány');

// --- Munkalap
$r = $keszit('munkalap', ['id' => $ml]);

if ($van_szoveg) {
    T::tartalmaz($r['szoveg'], 'Kijelzőcsere munkadíj', 'munkalap: a szolgáltatás');
    T::tartalmaz($r['szoveg'], 'Akkumulátor', 'munkalap: a termék');
    T::tartalmaz($r['szoveg'], '12 000', 'munkalap: az ár');
    T::tartalmaz($r['szoveg'], 'JAVITASSZOVEG', 'munkalap: az elvégzett javítás szövege');
    T::tartalmaz($r['szoveg'], 'Fizetendő', 'munkalap: fizetendő');
    T::tartalmaz($r['szoveg'], SDH_Muhely_Nyomtatvany::ft((float) $lap->brutto_ertek), 'munkalap: a végösszeg a lapé');
    T::nem_tartalmaz($r['szoveg'], 'LAPBELSO', 'munkalap: a belső megjegyzés NEM látszik');
    T::nem_tartalmaz($r['szoveg'], '4711', 'munkalap: a zárkód NEM látszik');
}

// --- Csak az egyik eszköz
$r = $keszit('munkalap', ['id' => $ml, 'eszkoz' => (string) $e2]);

if ($van_szoveg) {
    T::tartalmaz($r['szoveg'], 'Béta 2', 'egy eszköz: a kért eszköz rajta van');
    T::nem_tartalmaz($r['szoveg'], 'Alfa 1', 'egy eszköz: a másik nincs rajta');
    T::nem_tartalmaz($r['szoveg'], 'Kijelzőcsere', 'egy eszköz: a másik eszköz tétele nincs rajta');
    T::tartalmaz($r['szoveg'], '9 000', 'egy eszköz: a saját tétele rajta van');
}

T::tartalmaz((string) $r['szam'], '/2', 'egy eszköz: a sorszám jelzi, melyik eszköz');

// --- Belső munkalap
$r = $keszit('belso', ['id' => $ml]);

if ($van_szoveg) {
    T::tartalmaz($r['szoveg'], 'LAPBELSO', 'belső: a belső megjegyzés látszik');
    T::tartalmaz($r['szoveg'], 'ESZKOZBELSO', 'belső: az eszköz belső megjegyzése látszik');
    T::tartalmaz($r['szoveg'], '4711', 'belső: a zárkód látszik');
    T::nem_tartalmaz($r['szoveg'], '12 000', 'belső: ár nincs rajta');
}

// --- Árajánlat, szállítólevél
$r = $keszit('arajanlat', ['id' => $ml]);

if ($van_szoveg) {
    T::tartalmaz($r['szoveg'], 'Érvényes', 'árajánlat: érvényességi idő');
    T::tartalmaz($r['szoveg'], 'végösszege', 'árajánlat: az ajánlat végösszege');
}

$r = $keszit('szallito', ['id' => $ml]);

if ($van_szoveg) {
    T::tartalmaz($r['szoveg'], 'Címzett', 'szállítólevél: címzett');
    T::tartalmaz($r['szoveg'], 'Igazolványszám', 'szállítólevél: átvevő neve és igazolványszáma');
    T::nem_tartalmaz($r['szoveg'], '12 000', 'szállítólevél: ár nincs rajta');
}

// --- A többi típus is elkészül
foreach ([
    'bianko' => [], 'penztar_zaras' => ['datum' => SDH_Muhely_Penztar::ma()], 'penztar_riport' => [], 'penztar_tetel' => ['minta' => 1],
    'lista_munkalap' => ['szuro' => 'nyitott'], 'lista_keszlet' => ['szuro' => 'mind'], 'lista_szamla' => [],
] as $k => $p) {
    $r = $keszit($k, $p);
    T::igaz(is_array($r) && strncmp($r['pdf'], '%PDF-', 5) === 0 && $r['oldal'] >= 1, $k . ': PDF készül');
}

if ($van_szoveg) {
    $r = $keszit('lista_munkalap', ['szuro' => 'nyitott']);
    T::tartalmaz($r['szoveg'], 'nyitott lapok', 'lapok listája: a szűrő kiírva');
    T::tartalmaz($r['szoveg'], 'Összesen', 'lapok listája: összesítő sor');
    T::tartalmaz($keszit('bianko')['szoveg'], 'Bejelentett hiba', 'bianko: kitölthető hibatábla');
}

// Fekvő lista: az oldal szélesebb, mint magas.
T::igaz(preg_match('~/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]~', $keszit('lista_keszlet')['pdf'], $mb) === 1 && (float) $mb[1] > (float) $mb[2], 'a lista fekvő lapra kerül');

// Próbanyomat: kitalált adatokkal, valódi lap nélkül.
foreach (['atveteli', 'munkalap', 'belso', 'arajanlat', 'szallito', 'eladas', 'rendeles'] as $k) {
    $r = $keszit($k, ['minta' => 1]);
    T::igaz(is_array($r) && strncmp($r['pdf'], '%PDF-', 5) === 0, 'próbanyomat: ' . $k);
}

if ($van_szoveg) {
    T::tartalmaz($keszit('munkalap', ['minta' => 1])['szoveg'], 'Minta Ügyfél', 'a próbanyomat kitalált ügyféllel készül');
}

// --- A rajzoló szélső esetei
require_once SDH_MUHELY_DIR . 'includes/lib/sdh-nyomtatvany-pdf.php';

$pdf = new SDH_Muhely_Nyomtatvany_Pdf('P', 'A5');
$pdf->AddPage();
$pdf->betu('', 1.0);

$kezd  = microtime(true);
$darab = $pdf->tordel(str_repeat('A1b2C3d4', 1000), 60.0);   // 8000 betű szóköz nélkül
T::igaz(microtime(true) - $kezd < 2.0, 'tördelés: a nagyon hosszú, szóköz nélküli szöveg sem lassú');
T::egyezik(8000, mb_strlen(implode('', $darab)), 'tördelés: egy betű sem vész el');
T::igaz(max(array_map([$pdf, 'GetStringWidth'], $darab)) <= 60.01, 'tördelés: minden sor belefér a szélességbe');
T::egyezik([''], $pdf->tordel('', 50.0), 'tördelés: üres szöveg');
T::egyezik(['árvíztűrő', 'tükörfúrógép'], $pdf->tordel('árvíztűrő tükörfúrógép', $pdf->GetStringWidth('tükörfúrógép') + 0.5), 'tördelés: ékezetes szavak szóhatáron törnek');

// Egy oldalnál magasabb cella nem robban szét oldalak tucatjaira.
$hosszu = implode(' ', array_fill(0, 1500, 'szöveg'));
$pdf->tabla([['c' => '#', 'sz' => 0.1], ['c' => 'Leírás', 'sz' => 0.9]], [['c' => ['1', $hosszu]], ['c' => ['2', 'rövid']]]);
T::igaz($pdf->PageNo() <= 6, 'táblázat: az oldalnál magasabb cella néhány oldalon folytatódik, nem több tucaton (' . $pdf->PageNo() . ' oldal)');
$elotte = $pdf->PageNo();
$pdf->parok([['c' => 'Megjegyzés', 'e' => $hosszu, 'sz' => 3], ['c' => 'Rövid', 'e' => 'x']], 2);
T::igaz($pdf->PageNo() - $elotte <= 6, 'címke–érték: a nagyon hosszú érték is néhány oldalon elfér (' . ($pdf->PageNo() - $elotte) . ' oldal)');
T::igaz(strncmp((string) $pdf->Output('S'), '%PDF-', 5) === 0, 'a szélső esetek után is érvényes PDF készül');

// Keskeny (A5) lapon a három oszloposra tervezett cella nem lóg ki: a szélessége a lapéhoz igazodik.
$wpdb->update(SDH_Muhely_Schema::tabla('eszkoz'), ['tartozekok' => implode(', ', array_fill(0, 40, 'tartozék'))], ['id' => $e1]);
$a5 = $P::keszit('belso', ['id' => $ml, '_sablon' => ['papir' => 'A5']], 1);
T::igaz(is_array($a5), 'A5-ös belső munkalap hosszú tartozéklistával is elkészül');

if ($van_szoveg && is_array($a5)) {
    $szo = t_pdf_szoveg($a5['pdf']);
    T::egyezik(40, substr_count((string) $szo, 'tartozék'), 'A5: a hosszú szöveg minden szava a lapon van (nem lóg ki)');
}

$wpdb->update(SDH_Muhely_Schema::tabla('eszkoz'), ['tartozekok' => 'tok és töltő'], ['id' => $e1]);

// Hibás logó miatt nem marad el a nyomtatvány.
$rossz = tempnam(sys_get_temp_dir(), 'sdhlogo') . '.png';
file_put_contents($rossz, 'ez nem kép');
$pdf2 = new SDH_Muhely_Nyomtatvany_Pdf('P', 'A4');
$pdf2->AddPage();
$pdf2->fej(['logo' => $rossz, 'ceg' => ['Minta Kft.'], 'cim' => 'Próba', 'adatok' => []]);
T::igaz(strncmp((string) $pdf2->Output('S'), '%PDF-', 5) === 0, 'olvashatatlan logóval is elkészül a lap (logó nélkül)');
unlink($rossz);

// Hibák.
T::igaz(is_string($P::keszit('nincs_ilyen')), 'ismeretlen nyomtatvány → hibaüzenet');
T::igaz(is_string($P::keszit('munkalap', ['id' => 99999999])), 'nem létező lap → hibaüzenet');
T::igaz(is_string($P::keszit('penztar_tetel', ['id' => 99999999])), 'nem létező pénztártétel → hibaüzenet');

/* ======================================================================== */
/* 4. Sablon és közös megjelenés: minden állítható                           */
/* ======================================================================== */

SDH_Muhely_Admin_UI::class; // betöltve

$valasz = t_ajax('sdh_muhely_nyomtsablon_ment', [
    'tipus' => 'atveteli', 's_cim' => 'Átvételi lap SAJÁT', 's_peldany' => '4', 's_papir' => 'A5',
    's_ugyfel' => '1', 's_eszkoz' => '1', 's_hibak' => '1', 's_zarkod' => '', 's_alairas' => '1',
    's_jogi_cim' => 'Feltételek', 's_jogi' => "ELSOFELTETEL sor.\nMásodik sor a {szam} laphoz.", 's_zaro' => 'ZAROSZOVEG {ugyfel}',
    's_alairas_bal' => 'Felvette', 's_alairas_jobb' => 'Hozta', 's_email_targy' => 'Tárgy {szam}', 's_email_szoveg' => 'Szöveg {ugyfel}',
]);
T::igaz(!empty($valasz['success']), 'a sablon elmenthető');

$s = $N::sablon('atveteli');
T::egyezik('Átvételi lap SAJÁT', $s['cim'], 'sablon: a cím átírható');
T::egyezik(4, $s['peldany'], 'sablon: az alap példányszám átírható');
T::egyezik('A5', $s['papir'], 'sablon: saját papírméret');
T::egyezik(0, $s['qr'], 'sablon: a kikapcsolt blokk kikapcsolva marad');
T::egyezik('Felvette', $s['alairas_bal'], 'sablon: az aláírás felirata átírható');

$r = $keszit('atveteli', ['id' => $ml]);
T::igaz(preg_match('~/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]~', $r['pdf'], $mb) === 1 && abs((float) $mb[1] - 420) < 2, 'A5: a lap valóban A5 méretű');

if ($van_szoveg) {
    T::tartalmaz($r['szoveg'], 'ÁTVÉTELI LAP SAJÁT', 'a saját cím a nyomtatványon');
    T::tartalmaz($r['szoveg'], 'ELSOFELTETEL', 'az apró betűs szöveg a nyomtatványon');
    T::tartalmaz($r['szoveg'], 'Feltételek', 'az apró betűs rész saját címe');
    T::tartalmaz($r['szoveg'], 'ZAROSZOVEG ' . $ugyfel->nev, 'a jelölők behelyettesítve ({ugyfel})');
    T::tartalmaz($r['szoveg'], 'Hozta', 'a saját aláírás-felirat');
}

T::egyezik('Munkalap', $N::sablon('munkalap')['cim'], 'a többi nyomtatvány érintetlen');

// A szerkesztő előnézete a NEM mentett értékekkel rajzol.
$r = $P::keszit('atveteli', ['minta' => 1, '_sablon' => ['cim' => 'ELONEZETCIM']], 1);

if ($van_szoveg) {
    T::tartalmaz((string) t_pdf_szoveg($r['pdf']), 'ELONEZETCIM', 'előnézet: a még nem mentett cím látszik');
}

T::egyezik('Átvételi lap SAJÁT', $N::sablon('atveteli')['cim'], 'az előnézet nem ment semmit');

// Tisztítás: értelmetlen értékek nem jutnak át.
t_ajax('sdh_muhely_nyomtsablon_ment', ['tipus' => 'munkalap', 's_cim' => '', 's_peldany' => '99', 's_papir' => 'B7']);
$s = $N::sablon('munkalap');
T::egyezik('Munkalap', $s['cim'], 'üres cím → a nyomtatvány neve');
T::egyezik(9, $s['peldany'], 'a példányszám legfeljebb 9');
T::egyezik('', $s['papir'], 'ismeretlen papírméret → az általános');

// Gyári visszaállítás.
$valasz = t_ajax('sdh_muhely_nyomtsablon_alap', ['tipus' => 'atveteli']);
T::igaz(!empty($valasz['success']), 'gyári visszaállítás');
T::egyezik('Átvételi elismervény', $N::sablon('atveteli')['cim'], 'visszaállítás után a gyári cím');
t_ajax('sdh_muhely_nyomtsablon_alap', ['tipus' => 'munkalap']);

T::igaz(empty(t_ajax('sdh_muhely_nyomtsablon_ment', ['tipus' => 'nincs'])['success']), 'ismeretlen nyomtatvány nem menthető');

// Közös megjelenés.
$_POST = [
    'ny_jelen' => '1', 'ny_betu' => 'liberation-serif', 'ny_meret' => '11', 'ny_papir' => 'A4', 'ny_margo' => '15', 'ny_szin' => '#123456',
    'ny_logo_szel' => '40', 'ny_logo_hely' => 'jobb', 'ny_fej_logo' => '1', 'ny_fej_ceg' => '1', 'ny_ceg' => "SAJATCEG Kft.\nMintaváros, Fő utca 1.",
    'ny_lab' => 'LABLECSOR · nyitva: H–P 9–17', 'ny_kelt_hely' => 'Mintaváros', 'ny_ajanl_uj' => '1', 'ny_ajanl_zar' => '1', 'ny_ajanl_eladas' => '1',
    'ny_email_masolat' => 'masolat@pelda.hu',
];
$N::beallitas_mentes();
$_POST = [];

$a = $N::altalanos();
T::egyezik('liberation-serif', $a['betu'], 'általános: betűtípus');
T::egyezik(11.0, $a['meret'], 'általános: betűméret');
T::egyezik('#123456', $a['szin'], 'általános: kiemelő szín');
T::egyezik([18, 52, 86], $N::szin_rgb($a['szin']), 'a színkód RGB-re fordul');
T::egyezik('SAJATCEG Kft.', $N::ceg_sorok()[0], 'a fejléc első sora a megadott cégnév');
T::egyezik(['LABLECSOR · nyitva: H–P 9–17'], $N::lab_sorok(), 'saját lábléc');
T::egyezik('Átvételi elismervény', $N::sablon('atveteli')['cim'], 'az általános mentés nem bántja a sablonokat');

$r = $keszit('munkalap', ['id' => $ml]);

if ($van_szoveg) {
    T::tartalmaz($r['szoveg'], 'SAJATCEG Kft.', 'a saját cégadat a fejlécben');
    T::tartalmaz($r['szoveg'], 'LABLECSOR', 'a saját lábléc minden oldalon');
    T::tartalmaz($r['szoveg'], 'Kelt: Mintaváros,', 'a „Kelt" sorban a megadott település');
}

T::tartalmaz($r['pdf'], 'LiberationSerif', 'a választott betűtípus van a PDF-ben');

foreach (array_keys(SDH_Muhely_Nyomtatvany_Pdf::BETUK) as $betu) {
    $rb = $P::keszit('munkalap', ['id' => $ml, '_altalanos' => ['betu' => $betu]], 1);
    T::igaz(is_array($rb), 'betűtípus: ' . $betu . ' – a nyomtatvány elkészül');
}

// Értelmetlen értékek: határok közé szorítva, rossz színkód → az alap.
$_POST = ['ny_jelen' => '1', 'ny_betu' => 'comic', 'ny_meret' => '99', 'ny_margo' => '0', 'ny_szin' => 'piros', 'ny_logo_szel' => '500', 'ny_logo_hely' => 'fent'];
$N::beallitas_mentes();
$_POST = [];
$a = $N::altalanos();
T::egyezik('dejavu', $a['betu'], 'ismeretlen betűtípus → az alap');
T::egyezik(13.0, $a['meret'], 'a betűméret legfeljebb 13 pont');
T::egyezik(6.0, $a['margo'], 'a margó legalább 6 mm');
T::igaz(preg_match('/^#[0-9a-f]{6}$/', $a['szin']) === 1, 'rossz színkód → a CRM kiemelő színe');
T::egyezik(80.0, $a['logo_szel'], 'a logó legfeljebb 80 mm');
T::egyezik('bal', $a['logo_hely'], 'ismeretlen logóhely → balra');
T::igaz(!$N::altalanos()['ajanl_uj'], 'a felajánlás kikapcsolható');

delete_option('sdh_muhely_nyomtatas');

// A Beállítások oldalon ott a lapfül, és kirajzolódik.
T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['nyomtatvanyok']), 'Beállítások: Nyomtatványok lapfül');
T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['mentes']), 'Beállítások: Mentés lapfül');

ob_start();
$N::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::egyezik(14, substr_count($doboz, 'data-sdh-urlap="nyomtsablon"'), 'mind a 14 nyomtatvány szerkeszthető');
T::tartalmaz($doboz, 'name="ny_betu"', 'betűtípus-választó');
T::tartalmaz($doboz, 'name="ny_logo"', 'logó-mező');
T::tartalmaz($doboz, 'data-ny-minta="1"', 'próbanyomat gomb');

$urlap = t_ajax('sdh_muhely_nyomtsablon_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['tipus' => 'atveteli'])['nyers'];
T::tartalmaz($urlap, 'name="s_jogi"', 'szerkesztő: apró betűs szöveg mező');
T::tartalmaz($urlap, 'name="s_peldany"', 'szerkesztő: példányszám');
T::tartalmaz($urlap, 'data-ny-keret', 'szerkesztő: élő előnézet');
T::tartalmaz($urlap, '{vegosszeg}', 'szerkesztő: a jelölők listája');
T::nem_tartalmaz($urlap, 'name="s_cimletek"', 'szerkesztő: csak az ide tartozó mezők');

/* ======================================================================== */
/* 5. A Nyomtatás ablak                                                      */
/* ======================================================================== */

$ablak = t_ajax('sdh_muhely_nyomtatas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ml])['nyers'];
T::tartalmaz($ablak, 'data-sdh-ny', 'ablak: megnyílik');

foreach (['atveteli', 'munkalap', 'belso', 'arajanlat', 'szallito'] as $k) {
    T::tartalmaz($ablak, 'data-ny-tipus="' . $k . '"', 'ablak (javítás): ' . $k . ' választható');
}

T::nem_tartalmaz($ablak, 'data-ny-tipus="eladas"', 'ablak (javítás): az átadási bizonylat nem választható');
T::tartalmaz($ablak, 'data-tipus="atveteli"', 'nyitott javításnál az átvételi az alap');
T::tartalmaz($ablak, esc_attr((string) $ugyfel->email), 'az e-mail címzettje az ügyfél címe');
T::tartalmaz($ablak, 'data-ny-param="eszkoz"', 'több eszközös lapnál választható, melyik eszköz');
T::tartalmaz($ablak, 'data-ny-peldany', 'példányszám');
T::tartalmaz($ablak, 'data-ny-ment', 'mentés PDF-be');
T::tartalmaz($ablak, 'data-ny-email', 'küldés e-mailben');
T::nem_tartalmaz($ablak, '<form', 'az ablakban nincs űrlap (a popup nem köti rá a saját mentését)');

$ablak = t_ajax('sdh_muhely_nyomtatas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ml, 'tipus' => 'arajanlat'])['nyers'];
T::tartalmaz($ablak, 'data-tipus="arajanlat"', 'a kért nyomtatvány az aktív');

// Árukiadás: saját papírok.
$ak = SDH_Muhely_Munkalap::letrehoz(['allapot' => 'nyitott', 'fajta' => 'arukiadas', 'ugyfel_id' => 0, 'eszkoz_id' => 0, 'keszult' => current_time('Y-m-d')]);

if (is_int($ak)) {
    $ablak = t_ajax('sdh_muhely_nyomtatas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ak])['nyers'];
    T::tartalmaz($ablak, 'data-tipus="eladas"', 'árukiadásnál az átadási bizonylat az alap');
    T::nem_tartalmaz($ablak, 'data-ny-tipus="atveteli"', 'árukiadásnál nincs átvételi elismervény');
    T::igaz(is_array($P::keszit('eladas', ['id' => $ak])), 'átadási bizonylat ügyfél és eszköz nélküli lapról is készül');
} else {
    T::igaz(false, 'az árukiadás tesztlapja létrejön: ' . $ak);
}

// Gyűjtő: üres nyomtatvány, pénztár, listák – lap nélkül.
$ablak = t_ajax('sdh_muhely_nyomtatas_urlap', [], '_wpnonce', 'sdh_muhely_modal', [])['nyers'];

foreach (['bianko', 'penztar_zaras', 'penztar_riport', 'lista_munkalap', 'lista_keszlet', 'lista_szamla'] as $k) {
    T::tartalmaz($ablak, 'data-ny-tipus="' . $k . '"', 'gyűjtő: ' . $k);
}

T::nem_tartalmaz($ablak, 'data-ny-tipus="munkalap"', 'gyűjtő: lapot igénylő nyomtatvány nincs benne');
T::nem_tartalmaz($ablak, 'type="date"', 'nincs natív dátumválasztó');
T::tartalmaz($ablak, 'data-ny-kepernyo', 'gyűjtő: az oldal nyomtatása, ahogy látszik');

$ablak = t_ajax('sdh_muhely_nyomtatas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['tipus' => 'munkalap', 'minta' => '1'])['nyers'];
T::tartalmaz($ablak, 'Próbanyomat', 'próbanyomat ablak');
T::tartalmaz($ablak, 'data-minta="1"', 'a próbanyomat jelölve');

T::tartalmaz(t_ajax('sdh_muhely_nyomtatas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 99999999])['nyers'], 'Nincs ilyen munkalap', 'nem létező lap: érthető hiba');

// Gombok a felületen.
T::tartalmaz($N::gomb(12, ['tipus' => 'atveteli']), 'data-sdh-nyomtat="12"', 'gomb: a lap azonosítója');
T::tartalmaz($N::gomb(12, ['tipus' => 'atveteli']), 'data-ny-tipus="atveteli"', 'gomb: a paraméter');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-munkalap.php'), 'SDH_Muhely_Nyomtatas::gomb', 'a munkalap űrlapján ott a Nyomtatás gomb');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-racs.php'), 'SDH_Muhely_Nyomtatas::gomb', 'a részletpanelen ott a Nyomtatás gomb');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-frontend.php'), 'sdh-fej__nyomtat', 'a fejlécben ott a Nyomtatás gomb');

$pjs = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/penztar.js');
T::tartalmaz($pjs, 'data-ny-tipus="penztar_zaras"', 'pénztár: zárás nyomtatása');
T::tartalmaz($pjs, 'data-ny-tipus="penztar_riport"', 'pénztár: riport nyomtatása');

/* ======================================================================== */
/* 6. Küldés e-mailben                                                       */
/* ======================================================================== */

require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

/** Levélküldő-utánzat: nem küld, csak feljegyzi, mi ment volna ki. */
final class T_Levelkuldo extends PHPMailer\PHPMailer\PHPMailer
{
    /** @var array<int, array<string, mixed>> */
    public static $levelek = [];

    /** @var bool */
    public static $bukjon = false;

    public function send()
    {
        if (self::$bukjon) {
            throw new PHPMailer\PHPMailer\Exception('A levélszerver nem érhető el (teszt).');
        }

        self::$levelek[] = [
            'to'      => array_column($this->getToAddresses(), 0),
            'bcc'     => array_column($this->getBccAddresses(), 0),
            'subject' => $this->Subject,
            'body'    => $this->Body,
            'csat'    => $this->getAttachments(),
        ];

        return true;
    }
}

$regi_kuldo            = $GLOBALS['phpmailer'] ?? null;
$GLOBALS['phpmailer']  = new T_Levelkuldo(true);
$felado                = static fn (): string => 'muhely@pelda.hu';
add_filter('wp_mail_from', $felado);
$levelek = &T_Levelkuldo::$levelek;

$alap_post = ['tipus' => 'arajanlat', 'id' => (string) $ml, 'targy' => 'Ajánlat', 'szoveg' => 'Tisztelt Ügyfél!', 'fiok' => ''];

$v = t_ajax('sdh_muhely_nyomtatas_email', ['cimzett' => 'nem-cim'] + $alap_post);
T::igaz(empty($v['success']), 'hibás cím: nem megy el');
T::tartalmaz((string) ($v['data']['uzenet'] ?? ''), 'hibás', 'hibás cím: érthető üzenet');
T::igaz(empty(t_ajax('sdh_muhely_nyomtatas_email', ['cimzett' => ''] + $alap_post)['success']), 'címzett nélkül nem megy el');
T::egyezik(0, count($levelek), 'eddig nem ment ki levél');

$uz_elott = count(SDH_Muhely_Rma::uzenetek($ml));
$v = t_ajax('sdh_muhely_nyomtatas_email', ['cimzett' => $ugyfel->email . ', masik@pelda.hu'] + $alap_post);
T::igaz(!empty($v['success']), 'a nyomtatvány elküldhető e-mailben' . (empty($v['success']) ? ' – ' . ($v['data']['uzenet'] ?? '') : ''));
T::egyezik(1, count($levelek), 'egy levél ment ki');

if (count($levelek) === 1) {
    T::igaz(in_array((string) $ugyfel->email, $levelek[0]['to'], true) && in_array('masik@pelda.hu', $levelek[0]['to'], true), 'több címzett');
    T::egyezik('Ajánlat', $levelek[0]['subject'], 'a megadott tárgy');
    T::egyezik(1, count($levelek[0]['csat']), 'egy csatolmány');
    T::tartalmaz((string) $levelek[0]['csat'][0][2], '.pdf', 'a csatolmány neve .pdf-re végződik');
    T::egyezik('application/pdf', (string) $levelek[0]['csat'][0][4], 'a csatolmány típusa PDF');
    T::igaz(!empty($levelek[0]['csat'][0][5]) && strncmp((string) $levelek[0]['csat'][0][0], '%PDF-', 5) === 0, 'a PDF a memóriából megy (nincs ideiglenes fájl a szerveren)');
}

T::egyezik($uz_elott + 1, count(SDH_Muhely_Rma::uzenetek($ml)), 'a lap üzenetei közé bekerül, hogy mi ment ki az ügyfélnek');
$uzik = SDH_Muhely_Rma::uzenetek($ml);
T::nem_tartalmaz((string) end($uzik)->szoveg, 'masik@pelda.hu', 'az ügyfél által is látható sorban nincs e-mail-cím');

// Belső munkalap: kollégának mehet, de nem kerül az ügyfél által is látható üzenetek közé…
$v = t_ajax('sdh_muhely_nyomtatas_email', ['tipus' => 'belso', 'cimzett' => 'kollega@pelda.hu'] + $alap_post);
T::igaz(!empty($v['success']), 'belső munkalap kollégának küldhető');
T::egyezik($uz_elott + 1, count(SDH_Muhely_Rma::uzenetek($ml)), 'a másnak küldött papír nem kerül az ügyfél üzenetei közé');

// …az ügyfél saját címére viszont nem megy ki (belső megjegyzés, zárkód van rajta).
$db = count($levelek);
$v  = t_ajax('sdh_muhely_nyomtatas_email', ['tipus' => 'belso', 'cimzett' => 'kollega@pelda.hu, ' . strtoupper((string) $ugyfel->email)] + $alap_post);
T::igaz(empty($v['success']), 'belső munkalap az ügyfél címére NEM küldhető');
T::tartalmaz((string) ($v['data']['uzenet'] ?? ''), 'belső', 'érthető üzenet arról, miért nem');
T::egyezik($db, count($levelek), 'ilyenkor levél sem megy ki');
T::igaz($N::belso_e('belso') && !$N::belso_e('munkalap') && !$N::belso_e('atveteli'), 'belső papír az, amelyiken belső megjegyzés vagy zárkód van');

$ablak_b = t_ajax('sdh_muhely_nyomtatas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ml])['nyers'];
T::igaz(preg_match('/data-adat="([^"]*)"/', $ablak_b, $mm) === 1, 'az ablak átadja a nyomtatványok adatait');
$ny_adat = json_decode(html_entity_decode((string) ($mm[1] ?? ''), ENT_QUOTES), true);
T::egyezik('', $ny_adat['belso']['cimzett'] ?? 'x', 'belső munkalapnál nincs előre kitöltött címzett');
T::egyezik((string) $ugyfel->email, $ny_adat['munkalap']['cimzett'] ?? '', 'a munkalapnál a címzett az ügyfél');

T::igaz(empty(t_ajax('sdh_muhely_nyomtatas_email', ['tipus' => 'munkalap', 'minta' => '1', 'cimzett' => 'a@pelda.hu', 'id' => '0'])['success']), 'a próbanyomat nem küldhető el');

// Pénztárzárás is küldhető (pl. a tulajdonosnak); üres tárgy → a nyomtatvány címe.
$v = t_ajax('sdh_muhely_nyomtatas_email', ['tipus' => 'penztar_zaras', 'datum' => SDH_Muhely_Penztar::ma(), 'cimzett' => 'fonok@pelda.hu', 'targy' => '', 'szoveg' => '']);
T::igaz(!empty($v['success']), 'a pénztárzárás is elküldhető');
T::tartalmaz((string) (end($levelek)['subject'] ?? ''), 'Pénztárzárás', 'üres tárgy → a nyomtatvány címe');

// Rejtett másolat a beállított címre.
$_POST = ['ny_jelen' => '1', 'ny_betu' => 'dejavu', 'ny_meret' => '9.5', 'ny_margo' => '12', 'ny_logo_szel' => '34', 'ny_ajanl_uj' => '1', 'ny_ajanl_zar' => '1', 'ny_ajanl_eladas' => '1', 'ny_email_masolat' => 'archiv@pelda.hu'];
$N::beallitas_mentes();
$_POST = [];
t_ajax('sdh_muhely_nyomtatas_email', ['cimzett' => 'valaki@pelda.hu'] + $alap_post);
T::igaz(in_array('archiv@pelda.hu', (array) (end($levelek)['bcc'] ?? []), true), 'rejtett másolat megy a beállított címre');
delete_option('sdh_muhely_nyomtatas');

// Ha a levélküldés nem megy: hibaüzenet az okával, nem „elküldve".
T_Levelkuldo::$bukjon = true;
$v = t_ajax('sdh_muhely_nyomtatas_email', ['cimzett' => 'a@pelda.hu'] + $alap_post);
T::igaz(empty($v['success']), 'sikertelen küldésnél hibát jelez');
T::tartalmaz((string) ($v['data']['uzenet'] ?? ''), 'nem érhető el', 'a hibaüzenet megírja az okát');
T_Levelkuldo::$bukjon = false;

remove_filter('wp_mail_from', $felado);
$GLOBALS['phpmailer'] = $regi_kuldo;
unset($levelek);

T::igaz(method_exists('SDH_Muhely_Levelezes', 'egyszeru_kuldes') && method_exists('SDH_Muhely_Levelezes', 'kuldo_fiokok'), 'a levelező fiókjából is küldhető (SMTP)');
T::igaz(is_string(SDH_Muhely_Levelezes::egyszeru_kuldes('nincs-ilyen-fiok', ['a@pelda.hu'], 't', 's')), 'nem létező fiók: hibaüzenet');

/* ======================================================================== */
/* 7. Felajánlás: új lapnál és lezáráskor                                    */
/* ======================================================================== */

$uid = get_current_user_id();
delete_user_meta($uid, 'sdh_muhely_ny_ajanlat');

T::igaz(!$N::van_ajanlat(), 'kezdetben nincs ajánlat');
T::igaz(($N::js_adat()['ajanlat'] ?? null) === false, 'a böngésző is úgy tudja: nincs');

$ml2 = t_munkalap($u, [$e1]);
T::igaz(!$N::van_ajanlat(), 'programból létrehozott lap (import, demó) nem ajánl');

do_action('sdh_muhely_munkalap_mentve', $ml2, null);
T::igaz($N::van_ajanlat(), 'új lap mentése után van ajánlat');
$nyugta = static fn (): array => t_ajax('sdh_muhely_nyomtatas_ajanlat', [], '_wpnonce', 'sdh_muhely_modal', ['nyugta' => '1']);

$a = t_ajax('sdh_muhely_nyomtatas_ajanlat')['data']['ajanlat'] ?? null;
T::egyezik('atveteli', $a['tipus'] ?? '', 'új javításnál az átvételi elismervényt ajánlja');
T::egyezik($ml2, $a['id'] ?? 0, 'az ajánlat az új lapra szól');
T::egyezik(2, $a['peldany'] ?? 0, 'az ajánlat a sablon példányszámával nyomtat');
T::igaz($N::van_ajanlat(), 'a lekérés nem törli: ha az oldalváltás megszakítja, újra lekérhető');
T::egyezik('atveteli', t_ajax('sdh_muhely_nyomtatas_ajanlat')['data']['ajanlat']['tipus'] ?? '', 'másodszor is megkapja, amíg a böngésző nem nyugtázta');
$nyugta();
T::igaz(!$N::van_ajanlat(), 'nyugtázás után nincs több ajánlat');
$masodik = t_ajax('sdh_muhely_nyomtatas_ajanlat');
T::igaz(!empty($masodik['success']) && array_key_exists('ajanlat', (array) $masodik['data']) && $masodik['data']['ajanlat'] === null, 'nyugtázás után már nincs mit mutatni');

do_action('sdh_muhely_munkalap_mentve', $ml2, t_lap($ml2));
T::igaz(!$N::van_ajanlat(), 'meglévő lap mentése nem ajánl átvételit');

$zart = SDH_Muhely_Munkalap::lezart_kulcs();
SDH_Muhely_Munkalap::allapot_valt($ml2, $zart);
$a = t_ajax('sdh_muhely_nyomtatas_ajanlat')['data']['ajanlat'] ?? null;
T::egyezik('munkalap', $a['tipus'] ?? '', 'lezáráskor a munkalapot ajánlja');
T::egyezik('zaras', $a['esemeny'] ?? '', 'az ajánlat tudja, hogy lezárás történt');
$nyugta();

SDH_Muhely_Munkalap::allapot_valt($ml2, 'nyitott');
T::igaz(!$N::van_ajanlat(), 'újranyitás nem ajánl');

// Érvénytelen (törölt) állapotba zárás nem ajánl.
$ervenytelen = '';

foreach (SDH_Muhely_Munkalap::allapotok() as $k => $al) {
    if (!empty($al['zart']) && !empty($al['ervenytelen'])) {
        $ervenytelen = (string) $k;
    }
}

if ($ervenytelen !== '') {
    SDH_Muhely_Munkalap::allapot_valt($ml2, $ervenytelen);
    T::igaz(!$N::van_ajanlat(), 'érvénytelen állapotba zárás nem ajánl nyomtatást');
    SDH_Muhely_Munkalap::allapot_valt($ml2, 'nyitott');
}

// Árukiadás lezárása: átadási bizonylat.
if (is_int($ak)) {
    SDH_Muhely_Munkalap::allapot_valt($ak, $zart);
    $a = t_ajax('sdh_muhely_nyomtatas_ajanlat')['data']['ajanlat'] ?? null;
    T::egyezik('eladas', $a['tipus'] ?? '', 'árukiadás lezárásakor az átadási bizonylatot ajánlja');
    $nyugta();
}

// Kikapcsolva nem ajánl.
$_POST = ['ny_jelen' => '1', 'ny_betu' => 'dejavu', 'ny_meret' => '9.5', 'ny_margo' => '12', 'ny_logo_szel' => '34'];
$N::beallitas_mentes();
$_POST = [];
do_action('sdh_muhely_munkalap_mentve', $ml2, null);
SDH_Muhely_Munkalap::allapot_valt($ml2, $zart);
T::igaz(!$N::van_ajanlat(), 'kikapcsolt felajánlásnál nincs ajánlat');
delete_option('sdh_muhely_nyomtatas');

// Régi (3 percnél öregebb) ajánlat már nem jelenik meg.
update_user_meta($uid, 'sdh_muhely_ny_ajanlat', ['id' => $ml2, 'tipus' => 'munkalap', 'esemeny' => 'zaras', 'szam' => '1', 'ido' => time() - 600]);
T::igaz(!$N::van_ajanlat(), 'a régi ajánlat lejár');
delete_user_meta($uid, 'sdh_muhely_ny_ajanlat');

$js = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/nyomtatas.js');
T::tartalmaz($js, "'sdh:mentve'", 'nyomtatas.js: mentés után rákérdez az ajánlatra');
T::tartalmaz($js, "'sdh:allapot'", 'nyomtatas.js: állapotváltás után is');
T::tartalmaz($js, 'sessionStorage', 'nyomtatas.js: az ajánlat túléli az oldalváltást');
T::tartalmaz($js, "'nyugta'", 'nyomtatas.js: a megmutatott ajánlatot nyugtázza');
T::nem_tartalmaz($js, 'confirm(', 'nyomtatas.js: nincs böngésző-megerősítés (kétlépcsős gomb van)');
T::tartalmaz((string) wp_json_encode(SDH_Muhely_Admin_UI::js_beallitas('frontend')), '"nyomtat"', 'a felület megkapja a nyomtatás adatait');

/* ======================================================================== */
/* 8. Pénztár: zárás utáni emlékeztető, közös riportadat                     */
/* ======================================================================== */

delete_option('sdh_muhely_penztar');
$pb = SDH_Muhely_Penztar::beallitas();
T::igaz($pb['terminal'], 'a zárás utáni emlékeztető alapból be van kapcsolva');
T::tartalmaz($pb['terminal_szoveg'], 'bankkártya-terminál', 'az alap szöveg a bankkártya-terminálra figyelmeztet');

$ind = SDH_Muhely_Penztar::indulo();
T::igaz(!empty($ind['terminal']['be']) && $ind['terminal']['szoveg'] !== '', 'a pénztár felülete megkapja az emlékeztetőt');

$_POST = ['penztar' => ['szemelyek' => '', 'cimletek' => '', 'kp_modok' => '', 'kartya_modok' => '', 'kategoriak' => '', 'ajanlat' => '1', 'terminal' => '1', 'terminal_szoveg' => 'SAJATEMLEKEZTETO: riasztó!']];
SDH_Muhely_Penztar::beallitas_mentes();
T::egyezik('SAJATEMLEKEZTETO: riasztó!', SDH_Muhely_Penztar::beallitas()['terminal_szoveg'], 'az emlékeztető szövege átírható');
$_POST['penztar']['terminal'] = '0';
SDH_Muhely_Penztar::beallitas_mentes();
T::igaz(!SDH_Muhely_Penztar::beallitas()['terminal'], 'az emlékeztető kikapcsolható');
$_POST = [];

T::tartalmaz($pjs, 'terminalSzol(', 'penztar.js: záráskor szól');
T::tartalmaz($pjs, 'mindig: true', 'penztar.js: az asszisztens akkor is szól, ha épp túra megy');
T::tartalmaz($pjs, 'ms: 0', 'penztar.js: az emlékeztető nem tűnik el magától');
T::tartalmaz((string) file_get_contents(SDH_MUHELY_DIR . 'assets/asszisztens.js'), 'opciok.mindig', 'asszisztens.js: a fontos buborék túra közben is megjelenik');

$riport = SDH_Muhely_Penztar_Elemzes::riport_adat(gmdate('Y-m-01'), gmdate('Y-m-d'));
T::igaz(isset($riport['osszesen'], $riport['napok'], $riport['kiadasok'], $riport['bizonylat']), 'a riport adatai a nyomtatványnak is elérhetők');

/* ======================================================================== */
/* 9. Asszisztens: a Nyomtatás ablakot meg tudja nyitni                      */
/* ======================================================================== */

$ui = new ReflectionMethod('SDH_Muhely_Asszisztens', 'ui_adat');
$ui->setAccessible(true);
$adat = $ui->invoke(null, 'megnyit', ['cel' => 'nyomtatas', 'id' => $ml, 'felirat' => 'Nyomtatás']);
T::egyezik('nyomtatas', $adat['popup'] ?? '', 'megnyit: a Nyomtatás ablakot nyitja');
T::egyezik($ml, $adat['id'] ?? 0, 'megnyit: a lap azonosítójával');

/* ======================================================================== */
/* 10. Fájlok, betöltés                                                      */
/* ======================================================================== */

foreach (['LiberationSans-Regular.ttf', 'LiberationSans-Bold.ttf', 'LiberationSerif-Regular.ttf', 'LiberationSerif-Bold.ttf', 'Carlito-Regular.ttf', 'Carlito-Bold.ttf', 'DejaVuSansCondensed.ttf'] as $f) {
    T::igaz(is_readable(SDH_MUHELY_DIR . 'includes/lib/tfpdf/font/unifont/' . $f), 'betűkészlet a csomagban: ' . $f);
}

$fe = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-frontend.php');
T::tartalmaz($fe, 'assets/nyomtatas.js', 'a műhely-felület betölti a nyomtatas.js-t');
T::tartalmaz($fe, 'assets/nyomtatas.css', 'a műhely-felület betölti a nyomtatas.css-t');

$kod = (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-nyomtatas.php') . (string) file_get_contents(SDH_MUHELY_DIR . 'includes/class-sdh-muhely-nyomtatvany.php');
T::nem_tartalmaz($kod, 'SMART DEVICE', 'a nyomtatás kódjában nincs cégnév');
T::nem_tartalmaz($kod, 'Veszprém', 'a nyomtatás kódjában nincs cím');

T::egyezik([], $figy, 'nincs PHP-figyelmeztetés');

/* ---- Takarítás --------------------------------------------------------- */

foreach ($regi as $o => $ertek) {
    if ($ertek === null) {
        delete_option($o);
    } else {
        update_option($o, $ertek, false);
    }
}

delete_user_meta($uid, 'sdh_muhely_ny_ajanlat');

foreach (glob($mappa . 'sdh-mentes-*.jsonl.gz') ?: [] as $f) {
    unlink($f);
}

delete_option('sdh_muhely_mentes_nap');
delete_option('sdh_muhely_mentes_fut');

T::vege('t37 nyomtatás: nyomtatványok, sablonok, ablak, e-mail, felajánlás; titkosított kulcsok; napi mentés; zárás utáni emlékeztető');
