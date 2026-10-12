<?php
/**
 * t42 – 0.56.0: eszköz-mockupok és az Eszközök panel.
 *
 * Gyári készlet (20 kép a csomagban), kiválasztás (szabott szabály > saját
 * fénykép > kategória > tartalék), Beállítások › Mockupok mentése, és hogy a
 * munkalapon minden eszköz kártyát kap képpel, a 2. eszköz teljes adatlapot.
 */

require __DIR__ . '/_keret.php';

$K = 'SDH_Muhely_Mockup';
delete_option($K::OPT);

/* --- Gyári készlet -------------------------------------------------------- */

$gyari = $K::gyari();
T::egyezik(20, count($gyari), 'a gyári készlet 20 mockup');
T::igaz(count(array_filter($gyari, static fn ($m) => $m['kep'] !== '')) === 20, 'mind a 20 gyári képfájl megvan (assets/mockup/*.webp)');
T::igaz(count(array_filter($gyari, static fn ($m) => $m['kategoriak'] === [] && $m['gyarto'] === '' && $m['modell'] === '')) === 1, 'egy tartalék (kategória és szabály nélküli) mockup van');

foreach (glob(SDH_MUHELY_DIR . 'assets/mockup/*.webp') as $f) {
    T::igaz(filesize($f) < 100 * 1024, 'a gyári kép 100 KB alatti: ' . basename($f));
}

/* --- Kiválasztás ---------------------------------------------------------- */

$e = static fn (array $m): object => (object) ($m + ['kategoria' => 'telefon', 'gyarto' => '', 'tipus' => '', 'megnevezes' => '', 'modell_szam' => '', 'kep_url' => '', 'imei' => '', 'sorozatszam' => '', 'szin' => '']);

T::tartalmaz($K::kep($e(['kategoria' => 'telefon'])), 'assets/mockup/telefon.webp', 'telefon → telefon-kép');
T::tartalmaz($K::kep($e(['kategoria' => 'ebook'])), 'assets/mockup/tablet.webp', 'e-book olvasó → tablet-kép (több kategória egy képhez)');
T::tartalmaz($K::kep($e(['kategoria' => 'meromuszer'])), 'assets/mockup/egyeb.webp', 'kép nélküli kategória → tartalék');
T::tartalmaz($K::kep($e(['kategoria' => 'telefon', 'kep_url' => 'https://pelda.hu/foto.jpg'])), 'pelda.hu/foto.jpg', 'saját fénykép a kategóriakép előtt');
T::tartalmaz($K::kep($e(['kategoria' => 'telefon', 'kep_url' => 'https://pelda.hu/rajz.svg'])), 'telefon.webp', 'SVG-rajz nem számít saját fényképnek');
T::egyezik('', $K::kep(null), 'eszköz nélkül nincs kép');

// Szabott szabály: gyártó + típusrészlet, kategóriához kötve.
$lista   = $gyari;
$lista[] = ['id' => '', 'nev' => 'iPhone', 'kategoriak' => ['telefon'], 'gyarto' => 'Apple', 'modell' => 'iPhone, iPad mini', 'leiras' => 'premium smartphone', 'kep' => 'https://pelda.hu/iphone.png'];
$lista[] = ['id' => '', 'nev' => 'Apple Watch', 'kategoriak' => ['ora'], 'gyarto' => 'Apple', 'modell' => '', 'leiras' => '', 'kep' => 'https://pelda.hu/watch.png'];
update_option($K::OPT, ['lista' => $K::tisztit($lista), 'sablon' => '']);

T::tartalmaz($K::kep($e(['gyarto' => 'Apple', 'tipus' => 'iPhone 15 Pro', 'kep_url' => 'https://pelda.hu/foto.jpg'])), 'iphone.png', 'gyártó + típus szabály mindent visz (a saját fényképet is)');
T::tartalmaz($K::kep($e(['gyarto' => 'apple', 'tipus' => 'IPHONE 12'])), 'iphone.png', 'a szabály kis- és nagybetűre érzéketlen');
T::tartalmaz($K::kep($e(['gyarto' => 'Samsung', 'tipus' => 'iPhone-tok'])), 'telefon.webp', 'más gyártónál a szabály nem él');
T::tartalmaz($K::kep($e(['gyarto' => 'Apple', 'tipus' => 'Galaxy'])), 'telefon.webp', 'típusrészlet nélkül a szabály nem él');
T::tartalmaz($K::kep($e(['kategoria' => 'ora', 'gyarto' => 'Apple'])), 'watch.png', 'gyártó-szabály a saját kategóriáján belül');
T::tartalmaz($K::kep($e(['kategoria' => 'telefon', 'gyarto' => 'Apple', 'tipus' => 'Valami'])), 'telefon.webp', 'az óra-szabály telefonra nem illik');

/* --- Tisztítás, mentés ---------------------------------------------------- */

$t = $K::tisztit([
    ['nev' => '', 'kep' => ''],
    ['nev' => 'Rossz kat', 'kategoriak' => ['nincs-ilyen', 'tablet'], 'kep' => 'javascript:alert(1)'],
    ['id' => 'azonos', 'nev' => 'A', 'kep' => 'https://a.hu/a.png'],
    ['id' => 'azonos', 'nev' => 'B', 'kep' => 'https://b.hu/b.png'],
]);
T::egyezik(3, count($t), 'üres sor kimarad');
T::egyezik(['tablet'], $t[0]['kategoriak'], 'csak létező kategória marad');
T::egyezik('', $t[0]['kep'], 'nem http(s) képcím kiesik');
T::igaz($t[1]['id'] !== $t[2]['id'], 'ismétlődő azonosító új azonosítót kap');

$_POST = ['mockup_jelen' => '1', 'mockup_sablon' => 'Photo of {eszkoz}', 'mockup' => [['nev' => 'Saját', 'kategoriak' => ['laptop'], 'kep' => home_url('/wp-content/x.png'), 'leiras' => 'red laptop']]];
$K::beallitas_mentes();
$b = $K::beallitas();
T::egyezik(1, count($b['lista']), 'a mentés a beküldött listát tárolja');
T::egyezik(home_url('/wp-content/x.png'), $b['lista'][0]['kep'], 'saját (helyi) képcím változatlan marad');
T::egyezik('Photo of red laptop', $K::prompt($b['lista'][0]), 'a generáló szöveg a sablonból és a leírásból áll');

$_POST = ['mockup_jelen' => '1', 'mockup_sablon' => '', 'mockup_gyari' => '1'];
$K::beallitas_mentes();
T::egyezik(20, count($K::beallitas()['lista']), 'gyári készlet visszaállítása');
T::tartalmaz($K::prompt($K::beallitas()['lista'][0]), 'Professional e-commerce product photo', 'üres sablon helyett a gyári áll');

$_POST = [];
$K::beallitas_mentes();
T::egyezik(20, count($K::beallitas()['lista']), 'mező nélküli mentés nem nyúl a listához');

T::igaz($K::helyi(home_url('/valami.png')) && !$K::helyi('https://pikaso.cdnpk.net/x.png'), 'helyi / külső képcím felismerése');

/* --- Beállítások lapfül ---------------------------------------------------- */

T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['mockupok']), 'a Beállításokban ott a Mockupok lapfül');
ob_start();
$K::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::egyezik(21, substr_count($doboz, 'data-sdh-mockup-sor'), '20 sor + az új sor sablonja');
T::tartalmaz($doboz, 'https://www.magnific.com/app', 'a Magnific megnyitása gomb ott van');
T::tartalmaz($doboz, 'data-sdh-mockup-masol', 'a generáló szöveg másolható');

/* --- Munkalap: Eszközök panel, kártyák képpel ------------------------------ */

delete_option($K::OPT);
$u  = t_ugyfel('Mockup Márta');
$e1 = t_eszkoz($u, 'Samsung', 'SM-A505FN');
$e2 = t_eszkoz($u, 'Motorola', 'Moto G Pad');
global $wpdb;
$wpdb->update(SDH_Muhely_Schema::tabla('eszkoz'), ['kategoria' => 'tablet', 'sorozatszam' => 'ZY22FKC7LH'], ['id' => $e2]);
$id = t_munkalap($u, [$e1, $e2]);

$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $id])['nyers'];
T::tartalmaz($urlap, 'Eszközök a lapon', 'az űrlapon ott az Eszközök panel');
T::tartalmaz($urlap, 'data-sdh-fo-kartya', 'a fő eszköz kártyát kap');
T::tartalmaz($urlap, 'assets/mockup/tablet.webp', 'a 2. eszköz kártyáján a tablet képe');
T::tartalmaz($urlap, '>Tablet · ', 'a 2. eszköz kártyáján az alcím (kategória, IMEI / sorozatszám)');
T::tartalmaz($urlap, 'data-sdh-eszk-penz="' . $e2 . '"', 'a 2. eszköz kártyáján a pénzsor helye');
T::nem_tartalmaz($urlap, 'data-sdh-eszkoz-bontas', 'a bal oldali külön bontás megszűnt');
T::nem_tartalmaz($urlap, 'sdh-ml__eszall', 'a bal oldali külön Eszközönként blokk megszűnt');

$ossz = t_ajax('sdh_muhely_munkalapok_osszefoglalo', [], '_wpnonce', 'sdh_muhely_modal', ['ugyfel_id' => $u, 'eszkoz_id' => $e1, 'tovabbi' => (string) $e2])['nyers'];
T::egyezik(2, substr_count($ossz, 'sdh-ml-kartya--eszkoz'), 'az Eszköz lapfülön mindkét eszköz teljes adatlapot kap');
T::tartalmaz($ossz, 'ZY22FKC7LH', 'a 2. eszköz sorozatszáma az adatlapon');
T::tartalmaz($ossz, 'data-sdh-eszk-info', 'az összefoglaló viszi a kártyák frissítéséhez az adatokat');

$blokk = SDH_Muhely_Munkalap::eszkoz_allapot_blokk(t_lap($id));
T::egyezik(2, substr_count($blokk, 'class="sdh-mockup'), 'a részletpanel eszközsoraiban is ott a kép');

T::vege('t42 eszköz-mockupok és az Eszközök panel');
