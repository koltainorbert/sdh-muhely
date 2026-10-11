<?php
/**
 * t36 – 0.51: Súgó. A dokumentáció és a rendszer szinkronja (minden modulnak,
 * Beállítások-lapfülnek és asszisztens-eszköznek van leírása; a súgó verziója
 * egyezik a bővítményével), a fejezetek formátuma, a jelölésnyelv, a keresés,
 * a kérdés–válasz (értékelés, saját kérdés), az oldaltúrák, az asszisztens
 * tudása és új eszközei, a Papír megjelenés, a kékfény-szűrő, a személyiség.
 *
 * HA EZ A TESZT BUKIK, a súgó lemaradt a rendszertől: a sugo/ mappa megfelelő
 * fejezetét kell frissíteni (a szabályok: sugo/OLVASSEL.md).
 */

require __DIR__ . '/_keret.php';

$S = 'SDH_Muhely_Sugo';
$A = 'SDH_Muhely_Asszisztens';
$E = 'SDH_Muhely_Asszisztens_Eszkozok';

global $wpdb;

$figy = [];
set_error_handler(static function (int $szint, string $szoveg, string $fajl, int $sor) use (&$figy): bool {
    if (strpos($fajl, 'sdh-muhely') !== false) {
        $figy[] = basename($fajl) . ':' . $sor . ' ' . $szoveg;
    }

    return true;
});

SDH_Muhely_Modulok::kontextus_beallit('frontend');
$S::urit();

$regi_ai      = get_option('sdh_muhely_ai');
$regi_arculat = get_option('sdh_muhely_arculat');

/* ======================================================================== */
/* 1. A fejezetek megvannak, és jól formáltak                                */
/* ======================================================================== */

$fajlok    = $S::fajlok();
$fejezetek = $S::fejezetek();
$csoportok = ['Kezdés', 'Napi munka', 'Pénz', 'Kapcsolat', 'Csapat és idő', 'Rendszer'];

T::igaz(count($fajlok) >= 20, 'legalább 20 fejezetfájl van a sugo mappában (' . count($fajlok) . ')');
T::egyezik(count($fajlok), count($fejezetek), 'minden fejezetfájl feldolgozható, és a kulcsok egyediek');
T::igaz(SDH_Muhely_Modulok::egy('sugo') !== null, 'a Súgó menüpont be van jegyezve');
T::igaz(is_readable($S::mappa() . 'OLVASSEL.md'), 'az írási szabályok (sugo/OLVASSEL.md) megvannak');

$gyik_ossz = 0;

foreach ($fajlok as $ut) {
    $nyers = (string) file_get_contents($ut);
    $nev   = basename($ut, '.md');
    $f     = $S::fajl_olvas($ut);

    if ($f === null) {
        T::igaz(false, $nev . ': a fejléc hibás');
        continue;
    }

    $kulcs = (string) $f['kulcs'];

    T::egyezik((string) preg_replace('/^\d+-/', '', $nev), $kulcs, $nev . ': a kulcs a fájlnév a sorszám nélkül');
    T::igaz(preg_match('/^csoport:\s*(.+)$/mu', $nyers, $m) === 1 && in_array(trim($m[1]), $csoportok, true), $nev . ': érvényes csoport');
    T::igaz(trim((string) $f['cim']) !== '' && mb_strlen((string) $f['osszefoglalo']) >= 30, $nev . ': van címe és összefoglalója');
    T::igaz(mb_strlen((string) $f['kulcsszavak']) >= 20, $nev . ': vannak kulcsszavai');
    T::igaz(count((array) $f['szakaszok']) >= 5, $nev . ': legalább 5 szakasz (' . count((array) $f['szakaszok']) . ')');
    T::igaz(count((array) $f['gyik']) >= 10, $nev . ': legalább 10 kérdés–válasz (' . count((array) $f['gyik']) . ')');

    $gyik_ossz += count((array) $f['gyik']);

    $rossz_kerdes = array_filter((array) $f['gyik'], static fn (array $g): bool => mb_substr(rtrim((string) $g['kerdes']), -1) !== '?' || trim((string) $g['valasz']) === '');
    T::egyezik([], array_values(array_map(static fn (array $g): string => (string) $g['kerdes'], $rossz_kerdes)), $nev . ': minden kérdés kérdőjelre végződik, és van válasza');

    $horgonyok = array_map(static fn (array $s): string => (string) $s['horgony'], (array) $f['szakaszok']);
    T::egyezik(count($horgonyok), count(array_unique($horgonyok)), $nev . ': a szakaszok horgonya egyedi');

    $ures = array_filter((array) $f['szakaszok'], static fn (array $s): bool => mb_strlen(trim((string) $s['torzs'])) < 40);
    T::egyezik([], array_values(array_map(static fn (array $s): string => (string) $s['cim'], $ures)), $nev . ': nincs üres szakasz');

    // Nyers HTML nem lehet a fejezetben (a jelölésnyelv amúgy is kiszűrné).
    T::igaz(preg_match('/<(script|style|iframe|img|a|div|span|table|br)\b/i', (string) preg_replace('/`[^`]*`|```.*?```/s', '', $nyers)) !== 1, $nev . ': nincs benne nyers HTML');

    // A hivatkozások létező fejezetre és menüpontra mutatnak.
    preg_match_all('/\]\(sugo:([a-z0-9\-]*)/', $nyers, $hs);
    $nincs = array_values(array_unique(array_filter($hs[1], static fn (string $k): bool => $k !== '' && !isset($fejezetek[$k]))));
    T::egyezik([], $nincs, $nev . ': minden sugo: hivatkozás létező fejezetre mutat');

    preg_match_all('/\]\(modul:([a-z0-9_\-]*)/', $nyers, $hm);
    $nincs = array_values(array_unique(array_filter($hm[1], static fn (string $k): bool => $k !== 'attekintes' && SDH_Muhely_Modulok::egy($k) === null)));
    T::egyezik([], $nincs, $nev . ': minden modul: hivatkozás létező menüpontra mutat');

    foreach ((array) $f['modulok'] as $modul) {
        T::igaz($modul === 'attekintes' || SDH_Muhely_Modulok::egy((string) $modul) !== null, $nev . ': a fejlécben megadott menüpont létezik (' . $modul . ')');
    }

    foreach ((array) $f['turak'] as $modul => $lepesek) {
        T::igaz($modul === 'attekintes' || SDH_Muhely_Modulok::egy((string) $modul) !== null, $nev . ': a túra létező oldalé (' . $modul . ')');
        T::igaz(count($lepesek) >= 3, $nev . ': a túrának legalább 3 lépése van (' . count($lepesek) . ')');

        $hibas = array_filter($lepesek, static fn (array $l): bool => trim($l['szel']) === '' || strpbrk($l['szel'], '<>{}') !== false || mb_strlen($l['cim']) < 3 || mb_strlen($l['szoveg']) < 15);
        T::egyezik([], array_values(array_map(static fn (array $l): string => $l['szel'], $hibas)), $nev . ': a túra lépései jól formáltak');
    }
}

T::igaz($gyik_ossz >= 350, 'összesen legalább 350 kérdés–válasz van (' . $gyik_ossz . ')');

/* ======================================================================== */
/* 2. SZINKRON: a súgó követi a rendszert                                     */
/* ======================================================================== */

T::egyezik(SDH_MUHELY_VERSION, $S::verzio(), 'a súgó verziója (sugo/00-sugo.md, verzio:) egyezik a bővítményével – frissítsd a fejezeteket, és írd át');

// Minden menüpontnak van fejezete.
foreach (array_merge(['attekintes'], array_keys(SDH_Muhely_Modulok::osszes())) as $modul) {
    T::igaz($S::modul_fejezet((string) $modul) !== '', 'a(z) „' . $modul . '” menüpontnak van súgófejezete (fejléc: modul:)');
}

// Minden oldalmenüben lévő menüpontnak van túrája is.
foreach (SDH_Muhely_Modulok::osszes() as $modul => $adat) {
    if (empty($adat['keszul'])) {
        T::igaz($S::tura((string) $modul) !== [], 'a(z) „' . $modul . '” oldalnak van túrája (## Túra: ' . $modul . ')');
    }
}

T::igaz($S::tura('attekintes') !== [], 'az Áttekintésnek van túrája');

// Minden Beállítások-lapfület leír egy fejezet, a lapfül pontos nevével.
$leirt = [];

foreach ($fejezetek as $kulcs => $f) {
    foreach ((array) $f['lapfulek'] as $lapful) {
        $leirt[(string) $lapful] = $kulcs;
    }
}

foreach (SDH_Muhely_Beallitasok::szakaszok() as $lapful => $adat) {
    $cim = (string) (is_array($adat) ? ($adat['cim'] ?? '') : $adat);

    T::igaz(isset($leirt[$lapful]), 'a Beállítások „' . $cim . '” lapfülét (' . $lapful . ') leírja egy fejezet (fejléc: szakaszok:)');

    if (isset($leirt[$lapful])) {
        $szoveg = (string) file_get_contents($S::mappa() . $fejezetek[$leirt[$lapful]]['fajl']);
        T::tartalmaz($szoveg, $cim, 'a(z) ' . $leirt[$lapful] . ' fejezet a nevén nevezi a „' . $cim . '” lapfület');
    }
}

// Az asszisztens minden eszközét megnevezi az Asszisztens fejezet.
$asz = (string) file_get_contents($S::mappa() . $fejezetek['asszisztens']['fajl']);

foreach ($E::definiciok() as $nev => $d) {
    T::tartalmaz($asz, '| ' . $d['cim'] . ' |', 'az Asszisztens fejezet táblázata megnevezi a(z) „' . $d['cim'] . '” eszközt (' . $nev . ')');
}

// A súgó saját útvonala létező fejezetekre és menüpontokra mutat.
$utvonal = (array) $fejezetek['sugo']['utvonal'];
T::igaz(count($utvonal) >= 5, 'a Bemutató útvonalának legalább 5 lépése van');

foreach ($utvonal as $l) {
    T::igaz(isset($fejezetek[$l['fejezet']]) && ($l['modul'] === 'attekintes' || SDH_Muhely_Modulok::egy($l['modul']) !== null), 'az útvonal lépése létező fejezetre és menüpontra mutat: ' . $l['cim']);
}

/* ======================================================================== */
/* 3. A jelölésnyelv                                                         */
/* ======================================================================== */

$html = $S::md("Sima **félkövér** és *dőlt*, `kód <b>`, [[Enter]].\n\n- egy\n- kettő\n  - belső\n\n1. első\n2. második\n\n> **Figyelem:** vigyázz!\n\n| A | B |\n|---|---|\n| `a|b` | 2 |\n\n### Alcím\n\n[Pénztár](sugo:penztar#zaras) és [megnyit](modul:penztar).\n\n<script>alert(1)</script>");

T::tartalmaz($html, '<strong>félkövér</strong>', 'félkövér');
T::tartalmaz($html, '<em>dőlt</em>', 'dőlt');
T::tartalmaz($html, '<code>kód &lt;b&gt;</code>', 'a kód tartalma HTML-biztos');
T::tartalmaz($html, '<kbd>Enter</kbd>', 'billentyű');
T::tartalmaz($html, '<ul><li>egy</li><li>kettő<ul><li>belső</li></ul></li></ul>', 'felsorolás beágyazott szinttel');
T::tartalmaz($html, '<ol><li>első</li><li>második</li></ol>', 'számozott lista');
T::tartalmaz($html, 'sdh-sugo-doboz--figyelem', 'kiemelt doboz a kezdőszava szerint');
T::tartalmaz($html, '<th>A</th><th>B</th>', 'táblázat fejléce');
T::tartalmaz($html, '<td><code>a|b</code></td><td>2</td>', 'a kódban álló függőleges vonal nem cellahatár');
T::tartalmaz($html, '<h3 class="sdh-sugo-alcim">Alcím</h3>', 'alcím');
T::igaz(preg_match('~<a class="sdh-sugo-link" href="[^"]*fejezet=penztar[^"]*#zaras">Pénztár</a>~', $html) === 1, 'hivatkozás fejezetre, horgonnyal');
T::igaz(preg_match('~<a class="sdh-sugo-modul" href="[^"]*penztar[^"]*">megnyit</a>~', $html) === 1, 'hivatkozás menüpontra');
T::nem_tartalmaz($html, '<script>', 'nyers HTML nem megy át');
T::tartalmaz($html, '&lt;script&gt;', 'a nyers HTML szövegként jelenik meg');
T::egyezik('Sima félkövér és dőlt, kód <b>, Enter.', $S::sima('Sima **félkövér** és *dőlt*, `kód <b>`, [[Enter]].'), 'a jelölések nélküli szöveg');
T::egyezik('lepesrol-lepesre-a-nap-zarasa', $S::horgony('Lépésről lépésre: a nap zárása'), 'horgony a címből');

// Minden fejezet minden szakasza és válasza figyelmeztetés nélkül kirajzolható.
$hossz = 0;

foreach ($fejezetek as $f) {
    foreach ((array) $f['szakaszok'] as $s) {
        $hossz += strlen($S::md((string) $s['torzs']));
    }

    foreach ((array) $f['gyik'] as $g) {
        $hossz += strlen($S::md((string) $g['valasz']));
    }
}

T::igaz($hossz > 300000, 'a teljes dokumentáció kirajzolható (' . (int) round($hossz / 1024) . ' KB HTML)');

/* ======================================================================== */
/* 4. Keresés                                                                */
/* ======================================================================== */

$elso = static fn (string $q, string $mit = 'mind'): array => $S::keres($q, 5, $mit)[0] ?? ['fejezet' => '', 'tipus' => '', 'cim' => ''];

T::egyezik('penztar', $elso('hogyan zárom le a kasszát')['fejezet'], 'a „kassza” a pénztár fejezetét hozza (rokon szó)');
T::egyezik('naptar', $elso('ismétlődő esemény beállítása')['fejezet'], 'naptár: ismétlődő esemény');
T::egyezik('megjelenes', $elso('kékfény szűrő')['fejezet'], 'megjelenés: kékfény-szűrő');
T::egyezik('asszisztens', $elso('vezess végig az oldalon túra')['fejezet'], 'asszisztens: túra');
T::igaz(in_array($elso('bevizsgálási díj levonódik')['fejezet'], ['munkalapok', 'szolgaltatasok', 'szamlazas'], true), 'bevizsgálási díj: a munkalap / szolgáltatás / számlázás fejezete');
T::igaz(in_array($elso('ügyfél QR kód belépés')['fejezet'], ['uzenetek-rma', 'munkalapok'], true), 'QR-kód: az ügyféloldal fejezete');
T::egyezik('gyik', $elso('hogyan váltok sötét módra', 'gyik')['tipus'], 'csak kérdés–válaszra szűkíthető');
T::egyezik('szakasz', $elso('házipénztár', 'szakasz')['tipus'], 'csak dokumentációra szűkíthető');
T::egyezik([], $S::keres('xqzvw kjhgf', 5), 'értelmetlen keresésre nincs találat');
T::egyezik([], $S::keres('az', 5), 'töltelékszóra nincs találat');

$t = $S::keres('hogyan zárom a kasszát', 3)[0];
T::igaz(mb_strlen($S::reszlet((string) $t['torzs'], 'hogyan zárom a kasszát', 200)) <= 215, 'a találat részlete rövid');
T::tartalmaz($S::talalat_url($t), 'muhely=sugo', 'a találat a Súgó oldalára visz');

/* ======================================================================== */
/* 5. AJAX: keresés, értékelés, saját kérdés–válasz                           */
/* ======================================================================== */

$r = t_ajax('sdh_muhely_sugo_keres', [], '_wpnonce', 'sdh_muhely_modal', ['q' => 'kassza zárás']);
T::igaz(!empty($r['success']) && count($r['data']['talalatok']) >= 3, 'élő keresés: találatok jönnek');
T::tartalmaz((string) $r['data']['mind'], 'nezet=kereses', 'élő keresés: megvan a teljes lista címe');

delete_option('sdh_muhely_sugo_ertekeles');
delete_user_meta(get_current_user_id(), 'sdh_muhely_sugo_szavazat');

$r = t_ajax('sdh_muhely_sugo_ertekel', ['id' => 'penztar-1', 'ertek' => '1']);
T::igaz(!empty($r['success']) && $r['data']['jo'] === 1 && $r['data']['nem'] === 0, 'értékelés: segített');
$r = t_ajax('sdh_muhely_sugo_ertekel', ['id' => 'penztar-1', 'ertek' => '1']);
T::egyezik(1, $r['data']['jo'], 'ugyanaz a szavazat nem számít kétszer');
$r = t_ajax('sdh_muhely_sugo_ertekel', ['id' => 'penztar-1', 'ertek' => '-1']);
T::igaz($r['data']['jo'] === 0 && $r['data']['nem'] === 1, 'a szavazat átírható');

$wpdb->query('DELETE FROM ' . SDH_Muhely_Asszisztens_Tudas::tabla() . " WHERE forras = 'gyik'");
delete_option('sdh_muhely_sugo_keresesek');

$S::kereses_naploz('mennyi a garancia tokra');
T::igaz(isset($S::megvalaszolatlan()['mennyi a garancia tokra']), 'a találat nélküli keresés feljegyezve');

$urlap = t_ajax('sdh_muhely_sugogyik_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0, 'kerdes' => 'mennyi a garancia tokra'])['nyers'];
T::tartalmaz($urlap, 'data-sdh-ajax-action="sdh_muhely_sugogyik_ment"', 'a saját kérdés űrlapja popupban nyílik');
T::tartalmaz($urlap, 'value="mennyi a garancia tokra"', 'a megválaszolatlan keresésből a kérdés előre kitöltve');

$r = t_ajax('sdh_muhely_sugogyik_ment', ['id' => 0, 'kerdes' => '', 'valasz' => 'x']);
T::igaz(empty($r['success']), 'kérdés nélkül nem menthető');

$r = t_ajax('sdh_muhely_sugogyik_ment', ['id' => 0, 'kerdes' => 'Mennyi garanciát adunk tokra?', 'valasz' => "Tokra **tizennégy nap** cseregaranciát adunk.\n\n- blokkal\n- sértetlenül", 'kulcsszavak' => 'garancia, tok', 'aktiv' => '1', 'eredeti' => 'mennyi a garancia tokra']);
T::igaz(!empty($r['success']), 'a saját kérdés–válasz menthető');
$sajat_id = (int) ($r['data']['id'] ?? 0);

$sajat = $S::sajat_gyik();
T::igaz(count($sajat) === 1 && $sajat[0]['kerdes'] === 'Mennyi garanciát adunk tokra?', 'a saját kérdés megjelenik');
T::egyezik('s' . $sajat_id, $S::gyik()[0]['id'], 'a saját kérdések a lista elején állnak');
T::egyezik('s' . $sajat_id, $elso('garancia tok')['id'] ?? ($S::keres('garancia tok', 1)[0]['id'] ?? ''), 'a saját kérdés a keresésben az első');
T::igaz(!isset($S::megvalaszolatlan()['mennyi a garancia tokra']), 'megválaszolva lekerül a hiánylistáról');
T::tartalmaz($S::ai_reszek('mennyi garancia jár a tokra'), 'tizennégy nap', 'a saját kérdés–választ az asszisztens is megkapja');

t_ajax('sdh_muhely_sugogyik_ment', ['id' => $sajat_id, 'kerdes' => 'Mennyi garanciát adunk tokra?', 'valasz' => 'Rejtett.', 'aktiv' => '']);
T::egyezik([], $S::sajat_gyik(), 'kikapcsolva nem látszik');
T::egyezik(1, count($S::sajat_gyik(false)), 'de megmarad');

$kollega = wp_insert_user(['user_login' => 'sugo' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(), 'role' => 'editor']);
$admin   = get_current_user_id();
get_userdata($kollega)->add_cap(SDH_Muhely_Admin_UI::jog());
wp_set_current_user($kollega);
$r = t_ajax('sdh_muhely_sugogyik_ment', ['id' => 0, 'kerdes' => 'Tiltott?', 'valasz' => 'Nem.', 'aktiv' => '1']);
T::igaz(empty($r['success']), 'kolléga nem vehet fel saját kérdés–választ');
$r = t_ajax('sdh_muhely_sugo_keres', [], '_wpnonce', 'sdh_muhely_modal', ['q' => 'naptár']);
T::igaz(!empty($r['success']), 'kolléga is kereshet a súgóban');
wp_set_current_user($admin);

/* ======================================================================== */
/* 6. Oldaltúra                                                              */
/* ======================================================================== */

$r = t_ajax('sdh_muhely_ai_tura', ['modul' => 'penztar']);
T::igaz(!empty($r['success']) && count($r['data']['lepesek']) >= 3, 'az oldaltúra lépései API nélkül jönnek');
T::igaz($r['data']['cim'] !== '' && strpos((string) $r['data']['leiras'], 'fejezet=penztar') !== false, 'a túra megkapja a fejezet címét és a leírás címét');
T::igaz(isset($r['data']['lepesek'][0]['szel'], $r['data']['lepesek'][0]['cim'], $r['data']['lepesek'][0]['szoveg']), 'egy lépés: szelektor, cím, szöveg');

$r = t_ajax('sdh_muhely_ai_tura', ['modul' => '']);
T::egyezik('attekintes', $r['data']['modul'] ?? '', 'üres modulkulcs = Áttekintés');
$r = t_ajax('sdh_muhely_ai_tura', ['modul' => 'nincsilyen']);
T::igaz(!empty($r['success']) && $r['data']['lepesek'] === [], 'ismeretlen oldalra üres túra (a felület általános túrát ad)');

/* ======================================================================== */
/* 7. Az asszisztens tudása és új eszközei                                    */
/* ======================================================================== */

delete_transient('sdh_muhely_ai_statikus');
update_option('sdh_muhely_ai', ['humor' => 90] + (is_array($regi_ai) ? $regi_ai : []), false);
$st = SDH_Muhely_Asszisztens_Tudas::statikus();

T::tartalmaz($st, 'SÚGÓ – TARTALOMJEGYZÉK', 'a fix rendszerüzenetben ott a súgó tartalomjegyzéke');

foreach (array_keys($fejezetek) as $kulcs) {
    T::tartalmaz($st, '- ' . $kulcs . ' – ', 'a tartalomjegyzékben ott a(z) ' . $kulcs . ' fejezet');
}

T::tartalmaz($st, 'Humor (90%)', 'a humor beállítása a rendszerüzenetben');
T::tartalmaz($st, 'sugo_kereses', 'a rendszerüzenet megmondja, hogy a súgóban keressen');
T::igaz(strlen($st) < 90000, 'a fix rendszerüzenet kezelhető méretű (' . (int) round(strlen($st) / 1024) . ' KB)');

update_option('sdh_muhely_ai', ['humor' => 0] + (is_array($regi_ai) ? $regi_ai : []), false);
$st0 = SDH_Muhely_Asszisztens_Tudas::statikus();
T::igaz($st0 !== $st && strpos($st0, 'viccelődés nélkül') !== false, 'a humor átállítása azonnal érvényes (0% = tárgyszerű)');
T::egyezik(90, (function () { delete_option('sdh_muhely_ai'); return SDH_Muhely_Asszisztens::beallitas()['humor']; })(), 'a humor alapértéke 90%');
T::egyezik(80, $A::beallitas()['elenkseg'], 'az élénkség alapértéke 80%');

$r = t_ajax('sdh_muhely_ai_beallit', ['be' => '1', 'nev' => 'Szikra', 'proaktiv' => '1', 'napi_max' => '300', 'humor' => '35', 'elenkseg' => '10']);
T::igaz(!empty($r['success']) && $A::beallitas()['humor'] === 35 && $A::beallitas()['elenkseg'] === 10, 'a személyiség elmenthető');
$js = $A::js_adat();
T::igaz($js['humor'] === 35 && $js['elenkseg'] === 10 && $js['sugo'] !== '', 'a felület megkapja a személyiséget és a Súgó címét');

$reszek = $S::ai_reszek('hogyan zárom le a napot a pénztárban', 'penztar');
T::tartalmaz($reszek, 'EZ AZ OLDAL A SÚGÓBAN: ', 'a kérdéshez az oldal fejezete is megy');
T::tartalmaz($reszek, '[penztar#', 'a kérdéshez illő szakasz megy az asszisztensnek');
T::tartalmaz($reszek, 'K: ', 'és a hozzá illő kérdés–válasz');
T::igaz(strlen($reszek) < 22000, 'a kérdésenkénti súgórész kezelhető méretű');

$def = $E::definiciok();

foreach (['sugo_kereses', 'sugo_olvas', 'naptar_esemenyek', 'eladasok_listaja', 'keszlet', 'szolgaltatasok_listaja', 'eszkoz_adatok', 'nyitvatartas', 'beallitasok_allapota'] as $eszkoz) {
    T::egyezik('olvas', $def[$eszkoz]['szint'] ?? '', 'új olvasó eszköz: ' . $eszkoz);
}

T::egyezik('felulet', $def['vezet']['szint'] ?? '', 'új felületi eszköz: vezet');
T::egyezik('felulet', $def['tura']['szint'] ?? '', 'új felületi eszköz: tura');

$o = static fn (string $nev, array $p = []): array => (array) json_decode($E::olvas($nev, $p), true);

$x = $o('sugo_kereses', ['kifejezes' => 'kassza zárás']);
T::igaz(count($x['talalatok'] ?? []) >= 3 && isset($x['talalatok'][0]['szoveg']), 'sugo_kereses: találatok szöveggel');
$x = $o('sugo_kereses', ['kifejezes' => 'xqzvw kjhgf']);
T::igaz(($x['talalatok'] ?? null) === [] && isset($x['megjegyzes']), 'sugo_kereses: találat nélkül megmondja, hogy ne találgasson');
$x = $o('sugo_olvas', ['fejezet' => 'megjelenes']);
T::igaz(count($x['szakaszok'] ?? []) >= 5 && isset($x['kerdesek']), 'sugo_olvas: a fejezet szakaszai és kérdései');
$x = $o('sugo_olvas', ['fejezet' => 'megjelenes#kekfeny-szuro']);
T::igaz(count($x['szakaszok'] ?? []) === 1 && strpos($x['szakaszok'][0]['szoveg'], 'kék fényt') !== false, 'sugo_olvas: egy szakasz a találat azonosítójával');
T::igaz(isset($o('sugo_olvas', ['fejezet' => 'nincs'])['hiba']), 'sugo_olvas: ismeretlen fejezetre hiba a fejezetek listájával');

// Adatok az új eszközökhöz.
$u  = t_ugyfel('Súgó Sára');
$e  = t_eszkoz($u, 'Minta', 'Telefon X');
$wpdb->update(SDH_Muhely_Schema::tabla('eszkoz'), ['zarkod' => '4321', 'minta' => '1-2-3'], ['id' => $e]);
$ml = t_munkalap($u, [$e]);

$x = $o('eszkoz_adatok', ['eszkoz_id' => $e]);
T::igaz(strpos((string) ($x['gazdaja']['nev'] ?? ''), 'Súgó Sára') === 0 && count($x['munkalapok'] ?? []) === 1, 'eszkoz_adatok: gazda és előélet');
T::igaz(!isset($x['eszkoz']['zarkod']) && !isset($x['eszkoz']['minta']), 'eszkoz_adatok: a zárkód és a feloldó minta nem megy az asszisztenshez');
T::nem_tartalmaz($E::olvas('eszkoz_adatok', ['eszkoz_id' => $e]), '4321', 'a zárkód értéke sehol nem szerepel a válaszban');

$ma = current_time('Y-m-d');
SDH_Muhely_Naptar::szemelyek_frissit();
$szemelyek = SDH_Muhely_Naptar::szemelyek();
$elso_sz   = reset($szemelyek);
$sz_id     = (int) (is_array($elso_sz) ? ($elso_sz['id'] ?? 0) : ($elso_sz->id ?? 0));
t_ajax('sdh_muhely_naptar_ment', ['id' => 0, 'cim' => 'Súgóteszt esemény', 'szemely_id' => $sz_id, 'prioritas' => 'fontos', 'kontextus' => 'frontend',
    'kezdet_datum' => $ma, 'veg_datum' => $ma, 'kezdet_ora' => '10', 'kezdet_perc' => '00', 'veg_ora' => '11', 'veg_perc' => '00']);

$x = $o('naptar_esemenyek', ['tol' => $ma, 'ig' => $ma]);
$cimek = array_map(static fn (array $ev): string => (string) $ev['cim'], (array) ($x['esemenyek'] ?? []));
T::igaz(in_array('Súgóteszt esemény', $cimek, true), 'naptar_esemenyek: a mai esemény megvan (a zárónap is benne van)');
T::igaz(isset($o('naptar_esemenyek', ['szemely' => 'ilyennevunincs'])['hiba']), 'naptar_esemenyek: ismeretlen névre hiba a nevekkel');

$r = t_ajax('sdh_muhely_munkalapok_ment', ['id' => 0, 'fajta' => 'arukiadas', 'tetelek_jelen' => 1, 'allapot' => 'nyitott', 'keszult' => $ma, 'lap_afakulcs' => '27', 'lap_kedvezmeny' => '0', 'fizetett' => '0',
    'tetelek' => ['termek' => [['megnevezes' => 'Súgóteszt kábel', 'brutto_ar' => '3000', 'mennyiseg' => '2', 'me' => 'db', 'afa_kulcs' => '27']]]], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
$x = $o('eladasok_listaja', ['fajta' => 'arukiadas']);
T::igaz(($x['osszesen'] ?? 0) >= 1 && strpos((string) wp_json_encode($x, JSON_UNESCAPED_UNICODE), '2× Súgóteszt kábel') !== false, 'eladasok_listaja: a mai árukiadás a tételeivel');
T::igaz(isset($o('eladasok_listaja', ['fajta' => 'rendeles'])['lista']), 'eladasok_listaja: rendelések');

$wpdb->query('DELETE FROM ' . SDH_Muhely_Schema::tabla('termek') . " WHERE megnevezes = 'Súgóteszt fólia'"); // ismételt futtatásnál se duplázzon
$wpdb->insert(SDH_Muhely_Schema::tabla('termek'), ['megnevezes' => 'Súgóteszt fólia', 'keszletkezeles' => 1, 'keszlet' => 1, 'min_keszlet' => 5, 'me' => 'db', 'brutto_ar' => 2000, 'aktiv' => 1, 'letrehozva' => current_time('mysql'), 'modositva' => current_time('mysql')]);
$x = $o('keszlet', ['fogyoban' => true, 'kifejezes' => 'Súgóteszt']);
T::igaz(($x['osszesen'] ?? 0) === 1 && $x['lista'][0]['keszlet'] === '1 db', 'keszlet: ami a minimum alá fogyott');
T::igaz(isset($o('szolgaltatasok_listaja')['lista']), 'szolgaltatasok_listaja');
$x = $o('nyitvatartas');
T::igaz(isset($x['het'], $x['allapot']) && count($x['het']) === 7, 'nyitvatartas: a hét napjai és a mostani állapot');
$x = $o('beallitasok_allapota');
T::igaz(($x['verzio'] ?? '') === SDH_MUHELY_VERSION && isset($x['asszisztens']['van_api_kulcs'], $x['csapat_mod'], $x['munkalap_allapotok']), 'beallitasok_allapota: mi van beállítva');
T::nem_tartalmaz((string) wp_json_encode($x), 'sk-ant', 'beallitasok_allapota: kulcs nem megy ki');

/* ======================================================================== */
/* 8. Megjelenés: Papír, kékfény-szűrő, óra a fejlécben                       */
/* ======================================================================== */

$AR = 'SDH_Muhely_Arculat';

T::igaz(isset($AR::temak()['papir']), 'a Papír megjelenés választható');
T::egyezik(0, (function () use ($AR) { delete_option('sdh_muhely_arculat'); return $AR::beallitas()['kekfeny']; })(), 'a kékfény-szűrő alapból ki van kapcsolva');
T::egyezik(45, $AR::kekfeny_szukit(43), 'a szűrő ötösével lép');
T::egyezik(100, $AR::kekfeny_szukit(250), 'legfeljebb 100%');

$_POST = wp_slash(['tema' => 'papir', 'szin' => '', 'arculat_kekfeny' => '40']);
$AR::beallitas_mentes();
$_POST = [];
T::egyezik('papir', $AR::beallitas()['tema'], 'a Papír a cég alapértelmezése lehet');
T::egyezik(40, $AR::beallitas()['kekfeny'], 'a kékfény-szűrő alap erőssége elmenthető');
T::egyezik(40, SDH_Muhely_Admin_UI::js_beallitas('frontend')['alapKekfeny'], 'a felület megkapja a szűrő alap erősségét');

$vp = $AR::megjelenes_valaszto('profil');
T::egyezik(4, substr_count($vp, '<button type="button" data-ertek='), 'a profilmenüben négy megjelenés választható');
T::tartalmaz($vp, 'data-ertek="papir"', 'köztük a Papír');
T::tartalmaz($vp, 'data-sdh-kekfeny-csuszka', 'és ott a kékfény-szűrő csúszkája');

$admin_css = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/admin.css');
T::tartalmaz($admin_css, ':root[data-papir] {', 'admin.css: a Papír megjelenés színei');
T::tartalmaz($admin_css, ':root[data-kekfeny] {', 'admin.css: a kékfény-szűrő a gyökérelemen');
T::tartalmaz($admin_css, '.sdh-ora--fej', 'admin.css: az óra tömör változata a fejlécben');

$app_js = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/app.js');
T::tartalmaz($app_js, 'function nagyitasAnimal', 'app.js: a betűméret lágyan vált');
T::igaz(preg_match("/addEventListener\\('change', function \\(esemeny\\) \\{\\s*var csuszka = esemeny\\.target\\.closest \\? esemeny\\.target\\.closest\\('\\[data-sdh-nagyito-csuszka\\]'\\)/", $app_js) === 1, 'app.js: a felület a csúszka elengedésekor vált méretet');
T::tartalmaz($app_js, "nagyitoValt(csuszka.closest('[data-sdh-nagyito]'), csuszka.value, false);", 'app.js: húzás közben csak előnézet');
T::tartalmaz($app_js, 'function kekfenyAlkalmaz', 'app.js: kékfény-szűrő');

$ai_js = (string) file_get_contents(SDH_MUHELY_DIR . 'assets/asszisztens.js');
T::tartalmaz($ai_js, 'function turaIndit', 'asszisztens.js: többlépcsős túra');
T::tartalmaz($ai_js, 'function turaOldal', 'asszisztens.js: az oldal kész túrája');
T::nem_tartalmaz($ai_js, "el.addEventListener('click', kiemeltKatt", 'asszisztens.js: a kiemelés nem tűnik el kattintásra');
T::tartalmaz($ai_js, 'window.SDH_AI_API = {', 'asszisztens.js: nyilvános felület (túra, vezetés)');

/* ======================================================================== */
/* 9. A Súgó oldalai figyelmeztetés nélkül kirajzolódnak                      */
/* ======================================================================== */

$oldal = static function (array $get) use ($S): string {
    $regi = $_GET;
    $_GET = $get;
    ob_start();
    $S::oldal();
    $_GET = $regi;

    return (string) ob_get_clean();
};

$html = $oldal([]);
T::tartalmaz($html, 'data-sdh-sugo-kereso', 'Bemutató: kereső');
T::tartalmaz($html, 'Az első napod', 'Bemutató: útvonal');
T::tartalmaz($html, 'A rendszer térképe', 'Bemutató: térkép');
T::igaz(substr_count($html, 'class="sdh-sugo-kartya"') === count($fejezetek) - 1, 'Bemutató: minden fejezetnek van kártyája');
T::tartalmaz($html, 'tura=1', 'Bemutató: a „Vezess végig” gomb túrával nyitja az oldalt');

foreach ($fejezetek as $kulcs => $f) {
    $html = $oldal(['nezet' => 'dokumentacio', 'fejezet' => $kulcs]);
    T::igaz(strpos($html, '<h2>' . esc_html((string) $f['cim']) . '</h2>') !== false && substr_count($html, 'class="sdh-sugo-szakasz"') >= count((array) $f['szakaszok']), 'Dokumentáció: ' . $kulcs);
}

$html = $oldal(['nezet' => 'gyik']);
T::egyezik(12, substr_count($html, '<details class="sdh-sugo-kv'), 'Kérdés–válasz: 12 kérdés egy lapon (lapozó, nem görgetősáv)');
T::tartalmaz($html, 'sdh-cs-lapozo', 'Kérdés–válasz: lapozó');

$S::kereses_naploz('hol van a raktárkulcs');
$html = $oldal(['nezet' => 'gyik']);
T::tartalmaz($html, 'class="sdh-doboz sdh-sugo-hiany"', 'Kérdés–válasz: az adminisztrátor látja a megválaszolatlan kereséseket');
T::tartalmaz($html, 'data-sdh-param-kerdes="hol van a raktárkulcs"', 'Kérdés–válasz: a „Megválaszolom” gomb előre kitölti a kérdést');

$html = $oldal(['nezet' => 'gyik', 'kerdes' => 'penztar-3']);
T::igaz(preg_match('~<details class="sdh-sugo-kv[^"]*" id="gyik-penztar-3" open>~', $html) === 1, 'Kérdés–válasz: a hivatkozott kérdés a saját lapján, lenyitva jelenik meg');

$html = $oldal(['nezet' => 'kereses', 'q' => 'kassza zárás']);
T::tartalmaz($html, '<mark>', 'Keresés: a keresett szavak kiemelve');
T::tartalmaz($html, 'találat erre', 'Keresés: találatszám');

$html = $oldal(['nezet' => 'kereses', 'q' => 'xqzvw kjhgf']);
T::tartalmaz($html, 'Erre nincs kész válasz', 'Keresés: találat nélkül az asszisztenst ajánlja');
T::igaz(isset($S::megvalaszolatlan()['xqzvw kjhgf']), 'Keresés: a találat nélküli keresés feljegyezve');

$html = $oldal(['nezet' => 'ujdonsagok']);
T::tartalmaz($html, SDH_MUHELY_VERSION, 'Újdonságok: a mostani verzió');
T::tartalmaz($html, 'ez fut most', 'Újdonságok: jelöli, melyik fut');

// A verziónaplóban ott a mostani verzió (az Újdonságok ebből épül).
T::igaz(isset(array_flip(array_map(static fn (string $v): string => (string) strtok($v, ' '), array_keys(SDH_Muhely_Asszisztens_Tudas::readme()['verziok'])))[SDH_MUHELY_VERSION]), 'a README verziónaplójában ott a mostani verzió');

/* ======================================================================== */
/* 10. Kísérő javítás: árlista-beillesztés ezer forint alatti árral            */
/* ======================================================================== */

$al = SDH_Muhely_Szolgaltatas::arlista_ertelmez("Kiegészítők\nFóliázás 500 Ft\nTok felrakása 800,-\nKijelzőcsere 25.000 Ft\nGyorsszerviz 1500");
$arak = [];

foreach ($al['tetelek'] as $t) {
    $arak[(string) $t['rovid']] = (float) $t['ar'];
}

T::egyezik(500.0, $arak['Fóliázás'] ?? 0.0, 'árlista: az ezer forint alatti ár is ár, ha Ft áll utána');
T::egyezik(800.0, $arak['Tok felrakása'] ?? 0.0, 'árlista: 800,- alak');
T::egyezik(25000.0, $arak['Kijelzőcsere'] ?? 0.0, 'árlista: a tagolt ár változatlanul jó');
T::egyezik(1500.0, $arak['Gyorsszerviz'] ?? 0.0, 'árlista: pénznem nélkül ezertől ár');
T::egyezik('Kiegészítők', (string) ($al['tetelek'][0]['kategoria'] ?? ''), 'árlista: a kategóriasor kategória marad');
T::egyezik([], SDH_Muhely_Szolgaltatas::arlista_ertelmez("Mobiltelefon 100")['tetelek'], 'árlista: a puszta kis szám nem ár');

/* ---- Takarítás ---------------------------------------------------------- */

$wpdb->query('DELETE FROM ' . SDH_Muhely_Asszisztens_Tudas::tabla() . " WHERE forras = 'gyik'");
delete_option('sdh_muhely_sugo_keresesek');
delete_option('sdh_muhely_sugo_ertekeles');
$regi_ai === false ? delete_option('sdh_muhely_ai') : update_option('sdh_muhely_ai', $regi_ai, false);
$regi_arculat === false ? delete_option('sdh_muhely_arculat') : update_option('sdh_muhely_arculat', $regi_arculat);
delete_transient('sdh_muhely_ai_statikus');

restore_error_handler();
T::egyezik([], array_values(array_unique($figy)), 'PHP-figyelmeztetés nélkül fut');

T::vege('t36 súgó: fejezetek, szinkron, keresés, kérdés–válasz, túra, asszisztens-tudás, megjelenés');
