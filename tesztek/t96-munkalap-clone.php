<?php
/**
 * t96 – Munkalapszoftver Clone (0.99.6): a MunkaLap 3 csomag átvétele – felismerés, kibontás, ellenőrzés, javaslat,
 * leképezés (ügyfél, eszköz, munkalap + napló, hiba, tétel, termék, szolgáltatás, készlet, csatolmány, e-mail), nyers tár,
 * riport, újraátvétel (változatlan / frissített / új állapot), foglalt munkalapszám, meglévő rekord összekötése,
 * visszavonás, a modul ki-be kapcsolása, a beállítás-lapfül.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$M = 'SDH_Muhely_Ml3';
$L = 'SDH_Muhely_Ml3_Lekepzes';
$T = static fn (string $t): string => SDH_Muhely_Schema::tabla($t);
$r = (string) wp_rand(100000, 999999);

/* --- Csomag építése (a Java-átalakító kimenetének formájában) ------------------------------------------------- */

$tablak = [
    'USERS.USER_DATA' => ['USER_ID', [
        ['USER_ID' => 1, 'NAME' => 'APP'],
        ['USER_ID' => 6, 'NAME' => 'Teszt Technikus ' . $r],
    ]],
    'APP.CITY' => ['CITY_ID', [
        ['CITY_ID' => 10, 'NAME' => 'Veszprém', 'POSTCODE' => '8200'],
        ['CITY_ID' => 11, 'NAME' => 'Alsóörs', 'POSTCODE' => '8226'],
    ]],
    'APP.CUSTOMER_CATEGORY' => ['CUSTOMER_CATEGORY_ID', [['CUSTOMER_CATEGORY_ID' => 1, 'NAME' => 'Ügyfél']]],
    'APP.ITEM_CATEGORY' => ['ITEM_CATEGORY_ID', [['ITEM_CATEGORY_ID' => 5, 'NAME' => 'SM-A326B', 'PATH' => '/Alkatrész/Samsung telefon']]],
    'APP.DATA_COLUMN' => ['DATA_COLUMN_ID', [
        ['DATA_COLUMN_ID' => 1, 'TABLE_NAME' => 'CUSTOMER', 'NAME' => 'Telefonszám', 'TYPE' => 4, 'ACTIVE' => 1],
        ['DATA_COLUMN_ID' => 2, 'TABLE_NAME' => 'CUSTOMER', 'NAME' => 'E-mail', 'TYPE' => 8, 'ACTIVE' => 1],
        ['DATA_COLUMN_ID' => 25, 'TABLE_NAME' => 'CUSTOMER', 'NAME' => 'Partner kód', 'TYPE' => 4, 'ACTIVE' => 1],
        ['DATA_COLUMN_ID' => 7, 'TABLE_NAME' => 'THING', 'NAME' => 'Gyártó', 'TYPE' => 4, 'ACTIVE' => 1],
        ['DATA_COLUMN_ID' => 8, 'TABLE_NAME' => 'THING', 'NAME' => 'Típus', 'TYPE' => 4, 'ACTIVE' => 1],
        ['DATA_COLUMN_ID' => 16, 'TABLE_NAME' => 'THING', 'NAME' => 'IMEI szám (telefonok esetében)', 'TYPE' => 4, 'ACTIVE' => 1],
        ['DATA_COLUMN_ID' => 10, 'TABLE_NAME' => 'THING', 'NAME' => 'Garancia lejárta', 'TYPE' => 5, 'ACTIVE' => 1],
        ['DATA_COLUMN_ID' => 24, 'TABLE_NAME' => 'THING', 'NAME' => 'Garancia', 'TYPE' => 4, 'ACTIVE' => 1],
        ['DATA_COLUMN_ID' => 28, 'TABLE_NAME' => 'THING', 'NAME' => 'Navon - Csomagszám', 'TYPE' => 4, 'ACTIVE' => 1],
        ['DATA_COLUMN_ID' => 19, 'TABLE_NAME' => 'WORKSHEET', 'NAME' => 'Töltő', 'TYPE' => 4, 'ACTIVE' => 1],
        ['DATA_COLUMN_ID' => 13, 'TABLE_NAME' => 'WORKSHEET', 'NAME' => 'Hálózati kábel', 'TYPE' => 0, 'ACTIVE' => 1],
    ]],
    'APP.ADDRESS' => ['ADDRESS_ID', [
        ['ADDRESS_ID' => 1, 'TYPE' => 0, 'CUSTOMER_ID' => 501, 'NAME' => 'Árvíztűrő Tünde ' . $r, 'CITY_ID' => 10, 'STREET_ADDRESS' => 'Kossuth u. 65/3'],
        ['ADDRESS_ID' => 2, 'TYPE' => 3, 'CUSTOMER_ID' => 501, 'NAME' => 'Árvíztűrő Tünde ' . $r, 'CITY_ID' => 11, 'STREET_ADDRESS' => 'Gagarin u. 28', 'COUNTRY' => 'HU'],
        ['ADDRESS_ID' => 3, 'TYPE' => 0, 'CUSTOMER_ID' => 502, 'NAME' => 'Teszt Kft ' . $r, 'CITY_ID' => 10, 'STREET_ADDRESS' => 'Fő tér 1.'],
        ['ADDRESS_ID' => 4, 'TYPE' => 0, 'CUSTOMER_ID' => 503, 'NAME' => 'Meglévő Mária ' . $r, 'CITY_ID' => 10, 'STREET_ADDRESS' => 'Új u. 2.'],
    ]],
    'APP.CUSTOMER' => ['CUSTOMER_ID', [
        ['CUSTOMER_ID' => 501, 'CUSTOMER_CATEGORY_ID' => 1, 'DATA_1' => '06-30-111-' . $r . ', 06 20 222 3333; 06 1 444 5555', 'DATA_2' => 'tunde' . $r . '@example.com', 'DATA_25' => 'P-77', 'VAT_STATE' => 2, 'NOTES' => 'Régi megjegyzés', 'SYSTEM_CREATOR' => 1, 'SYSTEM_CREATION_TIME' => '2019-05-02 10:11:12.5'],
        ['CUSTOMER_ID' => 502, 'CUSTOMER_CATEGORY_ID' => 1, 'VAT_NUMBER' => '12345678-2-19', 'VAT_STATE' => 0, 'DISCOUNT_RATE' => '0.1000', 'SYSTEM_CREATION_TIME' => '2020-01-01 08:00:00.0'],
        ['CUSTOMER_ID' => 503, 'VAT_STATE' => 2, 'SYSTEM_CREATION_TIME' => '2020-01-01 08:00:00.0'],
    ]],
    'APP.CUSTOMER_ATTACHEMENT' => ['CUSTOMER_ATTACHEMENT_ID', [
        ['CUSTOMER_ATTACHEMENT_ID' => 1, 'CONNECTION_ID' => 501, 'NAME' => 'szemelyi', 'EXTENSION' => 'jpg', 'CONTENT' => ['$fajl' => 'files/CUSTOMER_ATTACHEMENT/1.CONTENT.jpg']],
    ]],
    'APP.THING' => ['THING_ID', [
        ['THING_ID' => 701, 'CUSTOMER_ID' => 501, 'NAME' => 'Mobiltelefon', 'DATA_7' => 'Samsung', 'DATA_8' => 'SM-F711B', 'DATA_16' => '354949610378226 / 354949610378234 / 111', 'DATA_10' => '2027-03-01 00:00:00.0', 'DATA_24' => 'Igen', 'DATA_28' => 'NV-' . $r, 'NOTES' => 'karcos', 'SYSTEM_CREATOR' => 6, 'SYSTEM_CREATION_TIME' => '2021-03-22 13:27:24.016'],
        ['THING_ID' => 702, 'CUSTOMER_ID' => 502, 'NAME' => 'Laptop', 'DATA_7' => 'Lenovo', 'SYSTEM_CREATION_TIME' => '2021-03-22 14:00:00.0'],
        ['THING_ID' => 703, 'CUSTOMER_ID' => 502, 'NAME' => 'Vásárlás', 'SYSTEM_CREATION_TIME' => '2021-03-22 15:00:00.0'],
    ]],
    'APP.MASTER_ITEM' => ['MASTER_ITEM_ID', [
        ['MASTER_ITEM_ID' => 901, 'TYPE' => 0, 'STOCK_CONTROL' => 1, 'SELLER_ID' => 502, 'CATEGORY_ID' => 5, 'NAME' => 'Kijelző ' . $r, 'QUANTITY' => '3.0000', 'UNIT' => 'db', 'CODE' => 'K-1', 'ITEM_NUMBER' => 'GH97', 'BARCODE' => '5900000000001', 'COST_PRICE' => '10000.0000', 'COST_TAX_RATE' => '0.2700', 'SELLING_PRICE' => '15748.0315', 'SELLING_TAX_RATE' => '0.2700', 'SYSTEM_CREATION_TIME' => '2021-01-01 00:00:00.0'],
        ['MASTER_ITEM_ID' => 902, 'TYPE' => 1, 'STOCK_CONTROL' => 0, 'NAME' => 'Szerviz díj ' . $r, 'UNIT' => 'db', 'SELLING_PRICE' => '11811.0236', 'SELLING_TAX_RATE' => '0.2700', 'SYSTEM_CREATION_TIME' => '2020-01-01 00:00:00.0'],
        ['MASTER_ITEM_ID' => 903, 'TYPE' => 1, 'STOCK_CONTROL' => 0, 'NAME' => 'Szerviz díj ' . $r, 'UNIT' => 'db', 'SELLING_PRICE' => '7874.0157', 'SELLING_TAX_RATE' => '0.2700', 'SYSTEM_CREATION_TIME' => '2023-01-01 00:00:00.0'],
    ]],
    'APP.WORKSHEET' => ['WORKSHEET_ID', [
        ['WORKSHEET_ID' => 880001, 'THING_ID' => 701, 'OWNER_ID' => 6, 'CUSTOMER_ID' => 501, 'STATE' => 3, 'CREATION_TIME' => '2025-05-14 10:41:00.0', 'BID_TIME' => '2025-05-14 11:20:00.0', 'BIDDER' => 6, 'COMPLETION_TIME' => '2025-05-14 11:39:00.0', 'COMPLETER' => 6, 'CLOSING_TIME' => '2025-05-14 14:10:00.0', 'CLOSER' => 1, 'PAYED' => 1, 'PAYMENT_TIME' => '2025-05-14 14:11:00.0', 'PAYMENT_TYPE' => 3, 'NET_SUM' => '24015.7480', 'TAX_SUM' => '6484.2520', 'DEPOSIT' => '0.0000', 'NOTES' => 'A bekapcsoló gomb hibás volt.', 'INTERNAL_NOTES' => 'IMEI ellenőrizve', 'DATA_19' => 'van', 'DATA_13' => 1, 'SYSTEM_CREATOR' => 6, 'SYSTEM_CREATION_TIME' => '2025-05-14 10:44:28.058'],
        ['WORKSHEET_ID' => 880002, 'THING_ID' => 702, 'CUSTOMER_ID' => 502, 'STATE' => 0, 'CREATION_TIME' => '2025-06-01 09:00:00.0', 'PENDING_TIME' => '2025-06-01 09:05:00.0', 'PENDER' => 1, 'DEADLINE' => '2025-06-10 00:00:00.0', 'PAYED' => 0, 'NET_SUM' => '0', 'TAX_SUM' => '0', 'DEPOSIT' => '5000.0000', 'SYSTEM_CREATION_TIME' => '2025-06-01 09:01:00.0'],
        ['WORKSHEET_ID' => 880003, 'THING_ID' => 703, 'CUSTOMER_ID' => 502, 'STATE' => 3, 'CREATION_TIME' => '2025-06-02 09:00:00.0', 'CLOSING_TIME' => '2025-06-02 09:10:00.0', 'PAYED' => 1, 'PAYMENT_TYPE' => 0, 'NET_SUM' => '15748.0315', 'TAX_SUM' => '4251.9685', 'SYSTEM_CREATION_TIME' => '2025-06-02 09:01:00.0'],
        ['WORKSHEET_ID' => 1, 'THING_ID' => 702, 'CUSTOMER_ID' => 503, 'STATE' => 4, 'CREATION_TIME' => '2019-01-01 09:00:00.0', 'CANCELLATION_TIME' => '2019-01-02 09:00:00.0', 'PAYED' => 0, 'NET_SUM' => '0', 'TAX_SUM' => '0', 'SYSTEM_CREATION_TIME' => '2019-01-01 09:00:00.0'],
    ]],
    'APP.WORKSHEET_ATTACHEMENT' => ['WORKSHEET_ATTACHEMENT_ID', [
        ['WORKSHEET_ATTACHEMENT_ID' => 1, 'CONNECTION_ID' => 880001, 'NAME' => '20250514_104153', 'EXTENSION' => 'jpg', 'CONTENT' => ['$fajl' => 'files/WORKSHEET_ATTACHEMENT/1.CONTENT.jpg'], 'SYSTEM_CREATION_TIME' => '2025-05-14 10:50:00.0'],
    ]],
    'APP.PROBLEM' => ['PROBLEM_ID', [
        ['PROBLEM_ID' => 1, 'TYPE' => 0, 'NAME' => 'Nem kapcsol be'],
        ['PROBLEM_ID' => 2, 'LIST_PROBLEM_ID' => 1, 'TYPE' => 2, 'CONNECTION_ID' => 880001, 'CONNECTION_KEY' => 'WORKSHEET', 'NAME' => 'Nem kapcsol be', 'DESCRIPTION' => 'Gomb cserélve', 'SOLUTION_TIME' => '2025-05-14 11:30:00.0', 'SYSTEM_CREATION_TIME' => '2025-05-14 10:45:00.0'],
        ['PROBLEM_ID' => 3, 'TYPE' => 4, 'CONNECTION_ID' => 880001, 'CONNECTION_KEY' => 'WORKSHEET', 'NAME' => 'Karcos hátlap', 'SYSTEM_CREATION_TIME' => '2025-05-14 10:45:00.0'],
    ]],
    'APP.ITEM' => ['ITEM_ID', [
        ['ITEM_ID' => 1, 'MASTER_ITEM_ID' => 901, 'DIRECTION' => 0, 'STATE' => 1, 'QUANTITY' => '5.0000', 'PRICE' => '10000', 'STOCK_CONTROL' => 1, 'TYPE' => 0, 'NAME' => 'Kijelző ' . $r, 'TRANSACTION_TIME' => '2021-01-02 00:00:00.0'],
        ['ITEM_ID' => 2, 'MASTER_ITEM_ID' => 901, 'DIRECTION' => 2, 'STATE' => 1, 'CONNECTION_ID' => 880001, 'CONNECTION_KEY' => 'WORKSHEET', 'QUANTITY' => '-1.0000', 'PRICE' => '15748.0315', 'TAX_RATE' => '0.2700', 'STOCK_CONTROL' => 1, 'TYPE' => 0, 'SELLER_ID' => 502, 'NAME' => 'Kijelző ' . $r, 'CODE' => 'K-1', 'ITEM_NUMBER' => 'GH97', 'ORDERING' => 1, 'TRANSACTION_TIME' => '2025-05-14 11:00:00.0', 'DISCOUNT_RATE' => '0.00000000'],
        ['ITEM_ID' => 3, 'MASTER_ITEM_ID' => 902, 'DIRECTION' => 2, 'STATE' => 1, 'CONNECTION_ID' => 880001, 'CONNECTION_KEY' => 'WORKSHEET', 'QUANTITY' => '-1.0000', 'PRICE' => '9186.3517', 'TAX_RATE' => '0.2700', 'TYPE' => 1, 'NAME' => 'Szerviz díj ' . $r, 'ORDERING' => 2, 'TRANSACTION_TIME' => '2025-05-14 11:00:00.0', 'DISCOUNT_RATE' => '0.10000000'],
        ['ITEM_ID' => 4, 'MASTER_ITEM_ID' => 901, 'DIRECTION' => 2, 'STATE' => 1, 'CANCELLED' => 1, 'CONNECTION_ID' => 880001, 'CONNECTION_KEY' => 'WORKSHEET', 'QUANTITY' => '-1.0000', 'PRICE' => '15748.0315', 'TAX_RATE' => '0.2700', 'STOCK_CONTROL' => 1, 'TYPE' => 0, 'NAME' => 'Kijelző ' . $r, 'TRANSACTION_TIME' => '2025-05-14 11:00:00.0'],
        ['ITEM_ID' => 5, 'MASTER_ITEM_ID' => 901, 'DIRECTION' => 2, 'STATE' => 1, 'CONNECTION_ID' => 880003, 'CONNECTION_KEY' => 'WORKSHEET', 'QUANTITY' => '-1.0000', 'PRICE' => '15748.0315', 'TAX_RATE' => '0.2700', 'STOCK_CONTROL' => 1, 'TYPE' => 0, 'NAME' => 'Kijelző ' . $r, 'TRANSACTION_TIME' => '2025-06-02 09:05:00.0'],
    ]],
    'APP.EMAIL' => ['EMAIL_ID', [
        ['EMAIL_ID' => 1, 'TYPE' => 2, 'DATABASE_TABLE' => 'WORKSHEET', 'TRANSFER_DATE' => '2025-05-14 12:00:00.0', 'SENDER' => 'szerviz@example.com', 'SUBJECT' => 'Munkalap (880001)', 'CONTENT' => '<div>Csatolt rekord: Munkalap (880001).html</div><div>Kész a javítás &#337;</div>', 'RECIPIENTS' => 'tunde' . $r . '@example.com'],
        ['EMAIL_ID' => 2, 'TYPE' => 1, 'SUBJECT' => 'Hírlevél'],
    ]],
    'APP.EMAIL_ATTACHEMENT' => ['EMAIL_ATTACHEMENT_ID', [
        ['EMAIL_ATTACHEMENT_ID' => 1, 'CONNECTION_ID' => 1, 'NAME' => 'Munkalap (880001)', 'EXTENSION' => 'pdf', 'CONTENT' => ['$fajl' => 'files/EMAIL_ATTACHEMENT/1.CONTENT.pdf']],
    ]],
    'APP.SYSTEM_PROPERTY' => ['PROPERTY_KEY', [
        ['PROPERTY_KEY' => 'worksheet.editor.defaultState', 'PROPERTY' => '1'],
        // Csak kis- és nagybetűben különböző, és 64 karakternél hosszabb, csak a végén eltérő kulcsok (a valódi mentésben is vannak).
        ['PROPERTY_KEY' => 'ACCESS.version', 'PROPERTY' => '1.11'],
        ['PROPERTY_KEY' => 'access.version', 'PROPERTY' => '1.12'],
        ['PROPERTY_KEY' => str_repeat('worksheet.printout.internal.', 3) . 'xerox workcentre 3025', 'PROPERTY' => 'a'],
        ['PROPERTY_KEY' => str_repeat('worksheet.printout.internal.', 3) . 'xerox workcentre 3215', 'PROPERTY' => 'b'],
    ]],
    'APP.FILE' => ['FILE_ID', [['FILE_ID' => 1, 'FILE_KEY' => 'printout.header.logo', 'BYTES' => ['$fajl' => 'files/FILE/1.BYTES.bin']]]],
];

$fajl_tartalom = [
    'files/CUSTOMER_ATTACHEMENT/1.CONTENT.jpg' => "\xFF\xD8\xFF ugyfel kep {$r}",
    'files/WORKSHEET_ATTACHEMENT/1.CONTENT.jpg' => "\xFF\xD8\xFF munkalap kep {$r}",
    'files/EMAIL_ATTACHEMENT/1.CONTENT.pdf' => "%PDF-1.4 {$r}",
    'files/FILE/1.BYTES.bin' => "LOGO {$r}",
];

$csomag = static function (array $tablak, array $fajlok, string $ut): void {
    $z = new ZipArchive();
    $z->open($ut, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $man = ['formatum' => 'munkalap3-csomag', 'atalakito' => 'teszt', 'keszult' => '2026-10-20 10:00:00', 'forras' => ['applicationVersion' => '3.857'], 'tablak' => [], 'osszes_sor' => 0, 'fajlok' => 0, 'fajl_bajt' => 0];

    foreach ($tablak as $nev => [$kulcs, $sorok]) {
        $jsonl = '';
        $fdb   = 0;

        foreach ($sorok as $s) {
            foreach ($s as $k => $v) {
                if (is_array($v) && isset($v['$fajl'])) {
                    $s[$k]['meret'] = strlen($fajlok[$v['$fajl']]);
                    $s[$k]['sha1']  = sha1($fajlok[$v['$fajl']]);
                    $z->addFromString($v['$fajl'], $fajlok[$v['$fajl']]);
                    $fdb++;
                    $man['fajl_bajt'] += strlen($fajlok[$v['$fajl']]);
                }
            }

            $jsonl .= json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }

        $z->addFromString('tables/' . $nev . '.jsonl', $jsonl);
        $osz = [];

        foreach ($sorok as $s) {
            foreach (array_keys($s) as $k) {
                $osz[$k] = [$k, 'VARCHAR', 64];
            }
        }

        $man['tablak'][] = ['nev' => $nev, 'sorok' => count($sorok), 'fajlok' => $fdb, 'sha1' => sha1($jsonl), 'kulcs' => $kulcs, 'oszlopok' => array_values($osz)];
        $man['osszes_sor'] += count($sorok);
        $man['fajlok']     += $fdb;
    }

    $z->addFromString('manifest.json', (string) json_encode($man, JSON_UNESCAPED_UNICODE));
    $z->close();
};

$futtat = static function () use ($M): array {
    $o = [];

    for ($i = 0; $i < 400; $i++) {
        $M::urit();
        $o = $M::lepes();

        if (!$o['tovabb']) {
            break;
        }
    }

    return $o;
};

$dir = sys_get_temp_dir() . '/t96-' . $r;
wp_mkdir_p($dir);
$zip = $dir . '/csomag.zip';
$csomag($tablak, $fajl_tartalom, $zip);

// Tiszta lap: a modul állapota, a nyers tár.
delete_option($M::OPT);
$M::urit();
$M::tabla_telepit();
$wpdb->query('DELETE FROM ' . $M::nyers_tabla());
$wpdb->query($wpdb->prepare('DELETE FROM ' . $T('atvetel_sor') . ' WHERE import_id = %s', $M::IMPORT_ID));

try {
    /* --- Modul, lapfül, felismerés ------------------------------------------------------------------------- */

    T::igaz(isset(SDH_Muhely_Modulok::katalogus()['munkalapclone']), 'a modul a katalógusban (Átállás)');
    T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['munkalapclone']), 'Beállítások › Munkalapszoftver Clone lapfül');
    T::igaz(is_file($M::jar_ut()) && filesize($M::jar_ut()) > 5000, 'az átalakító (jar) a csomagban');
    T::igaz(is_file(SDH_MUHELY_DIR . 'assets/ml3/Atalakitas.bat'), 'a kézi átalakító parancsfájlja');
    T::egyezik('csomag', $M::forras_tipus($zip), 'felismerés: átalakított csomag');

    $wdg = $dir . '/mentes.wdg';
    $z   = new ZipArchive();
    $z->open($wdg, ZipArchive::CREATE);
    $z->addFromString('Meta.properties', "applicationName=MunkaLap\n");
    $z->addFromString('database/service.properties', "derby.serviceProtocol=org.apache.derby.database.Database\n");
    $z->close();
    T::egyezik('wdg', $M::forras_tipus($wdg), 'felismerés: MunkaLap 3 mentés (.wdg)');
    file_put_contents($dir . '/rossz.zip', 'nem zip');
    T::tartalmaz($M::forras_tipus($dir . '/rossz.zip'), 'nem MunkaLap 3 mentés', 'felismerés: hibás fájl');

    // Átalakító nélküli szerver: a .wdg után „átalakítóra vár” (a kézi átalakító letölthető).
    update_option($M::OPT_BEALL, ['mappa' => $dir . '/nincs-ilyen', 'java' => '']);
    T::egyezik('', $M::forras_be($wdg, 'mentes.wdg', false), 'a .wdg forrásként rögzítve');
    $M::urit();
    $o = $M::lepes();
    T::egyezik('atalakitora_var', $o['fazis'], 'nincs MunkaLap-mappa: átalakítóra vár');
    T::tartalmaz((string) $o['uzenet'], 'MunkaLap 3 programmappáját', 'a felület megmondja, mi hiányzik');

    /* --- Kibontás, ellenőrzés, javaslat ------------------------------------------------------------------- */

    T::egyezik('', $M::forras_be($zip, 'csomag.zip', false), 'a csomag forrásként rögzítve');
    $o = $futtat();
    T::egyezik('kesz', $o['fazis'], 'kibontva és ellenőrizve: kész a párosításra');
    $a = $M::allapot();
    T::egyezik('telefon', $a['javaslat']['mezok']['CUSTOMER']['1'] ?? '', 'javaslat: Telefonszám → telefon');
    T::egyezik('email', $a['javaslat']['mezok']['CUSTOMER']['2'] ?? '', 'javaslat: E-mail → e-mail');
    T::egyezik('belso', $a['javaslat']['mezok']['CUSTOMER']['25'] ?? '', 'javaslat: Partner kód → belső megjegyzés');
    T::egyezik('imei', $a['javaslat']['mezok']['THING']['16'] ?? '', 'javaslat: IMEI szám → IMEI');
    T::egyezik('garancia_lejar', $a['javaslat']['mezok']['THING']['10'] ?? '', 'javaslat: Garancia lejárta');
    T::egyezik('garancias', $a['javaslat']['mezok']['THING']['24'] ?? '', 'javaslat: Garancia → garanciás');
    T::egyezik('tartozek', $a['javaslat']['mezok']['WORKSHEET']['19'] ?? '', 'javaslat: Töltő → tartozék');
    T::egyezik('Teszt Technikus ' . $r, $a['felhasznalok']['6'] ?? '', 'a régi felhasználók listája');

    // Sérült csomag: az ellenőrzés megfogja.
    $rossz = $tablak;
    $rossz_zip = $dir . '/rossz-csomag.zip';
    $csomag($rossz, $fajl_tartalom, $rossz_zip);
    $zz = new ZipArchive();
    $zz->open($rossz_zip);
    $zz->addFromString('tables/APP.CUSTOMER.jsonl', "{\"CUSTOMER_ID\":1}\n");
    $zz->close();
    $M::forras_be($rossz_zip, 'rossz.zip', false);
    $o = $futtat();
    T::egyezik('hiba', $o['fazis'], 'sérült tábla: hiba');
    T::tartalmaz((string) $o['uzenet'], 'APP.CUSTOMER (sérült)', 'a hibás tábla neve');

    /* --- Előre bent lévő adatok -------------------------------------------------------------------------- */

    // Táblázatos átvételből már bent lévő ügyfél (összekötjük, nem duplázzuk, a visszavonás nem törli).
    $meglevo = t_ugyfel('Meglévő Mária', ['forras' => 'munkalap3', 'kulso_azonosito' => '503']);
    // Foglalt munkalapszám (az 1-es).
    $szam1_volt = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $T('munkalap') . ' WHERE munkalap_szam = 1');

    if ($szam1_volt === 0) {
        $wpdb->insert($T('munkalap'), ['munkalap_szam' => 1, 'allapot' => 'nyitott', 'nev' => 't96 foglaló ' . $r, 'letrehozva' => current_time('mysql')]);
    }

    $fiz_elotte  = get_option('sdh_muhely_fizetesi_modok', null);
    $hiba_elotte = get_option('sdh_muhely_munkalap_hiba_allapotok', null);
    $technikus   = wp_insert_user(['user_login' => 't96tech' . $r, 'user_pass' => wp_generate_password(), 'display_name' => 'Teszt Technikus ' . $r, 'role' => 'administrator']);

    /* --- Átvétel ----------------------------------------------------------------------------------------- */

    $M::forras_be($zip, 'csomag.zip', false);
    $o = $futtat();
    T::egyezik('kesz', $o['fazis'], 'újra kész a párosításra');
    $a = $M::allapot();
    T::egyezik((int) $technikus, (int) ($a['javaslat']['felhasznalok']['6'] ?? 0), 'a felhasználó névegyezéssel párosítva');
    $jav = $a['javaslat'];
    $jav['mezok']['THING']['28'] = 'kihagy';
    T::egyezik('', $M::indit($jav), 'átvétel indítva');
    // Apró időkeret: sok kérés, a táblahatárok kérésen belül is átlépődnek (a hivatkozás-térkép ne legyen részleges).
    $kicsi = static fn (): float => 0.003;
    add_filter('sdh_muhely_ml3_idokeret', $kicsi);
    $lepes_db = 0;

    for ($i = 0; $i < 3000; $i++) {
        $M::urit();          // minden lépés új kérés (üres gyorsítótárral), kevés sorral
        $o = $M::lepes();
        $lepes_db++;

        if (!$o['tovabb']) {
            break;
        }
    }

    remove_filter('sdh_muhely_ml3_idokeret', $kicsi);
    T::igaz($lepes_db > 5, 'sok kis lépésben (' . $lepes_db . ')');
    $o = $futtat();
    T::egyezik('befejezve', $o['fazis'], 'az átvétel befejeződött');
    $a = $M::allapot();
    T::egyezik(0, (int) ($a['hibak_db'] ?? 0), 'hiba nélkül (' . implode(' | ', (array) ($a['hibak'] ?? [])) . ')');

    T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $M::nyers_tabla() . " WHERE megj LIKE '%nincs meg%'"), 'minden hivatkozás feloldva (nincs „gazdarekord nincs meg”)');
    $nyers_db = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $M::nyers_tabla());
    $regi_db  = array_sum(array_map(static fn (array $t): int => count($t[1]), $tablak));
    T::egyezik($regi_db, $nyers_db, 'minden régi sor a nyers tárban (' . $regi_db . ')');
    T::egyezik($regi_db, (int) $a['eredmeny']['nyers'], 'a riport is ennyit mond');

    $uid = static fn (string $t, $k): int => $M::uj_id($t, (string) $k);
    $M::urit();

    // Ügyfél
    $u = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('ugyfel') . ' WHERE id = %d', $uid('APP.CUSTOMER', 501)));
    T::egyezik('Árvíztűrő Tünde ' . $r, (string) $u->nev, 'ügyfél: név a központi címből');
    T::egyezik(['8200', 'Veszprém', 'Kossuth u. 65/3'], [(string) $u->szamlazasi_iranyitoszam, (string) $u->szamlazasi_telepules, (string) $u->szamlazasi_cim], 'ügyfél: cím a településtáblával');
    T::egyezik([0, '8226', 'Alsóörs', 'Gagarin u. 28'], [(int) $u->szallitasi_azonos, (string) $u->szallitasi_iranyitoszam, (string) $u->szallitasi_telepules, (string) $u->szallitasi_cim], 'ügyfél: szállítási cím');
    T::egyezik(['06-30-111-' . $r, '06 20 222 3333'], [(string) $u->telefon, (string) $u->telefon2], 'ügyfél: két telefon szétválasztva');
    T::tartalmaz((string) $u->belso_megjegyzes, 'további telefon: 06 1 444 5555', 'ügyfél: a harmadik telefon a belső megjegyzésben');
    T::tartalmaz((string) $u->belso_megjegyzes, 'MunkaLap 3 – Partner kód: P-77', 'ügyfél: a nem párosított egyedi mező névvel');
    T::egyezik(['tunde' . $r . '@example.com', 'Ügyfél', 'maganszemely', 'Régi megjegyzés', '2019-05-02 10:11:12'], [(string) $u->email, (string) $u->kategoria, (string) $u->tipus, (string) $u->megjegyzes, (string) $u->letrehozva], 'ügyfél: e-mail, kategória, típus, megjegyzés, felvétel ideje');
    T::egyezik([sprintf('U-%06d', (int) $u->id), 'munkalap3', '501'], [(string) $u->ugyfel_szam, (string) $u->forras, (string) $u->kulso_azonosito], 'ügyfél: ügyfélszám, forrás, régi azonosító');
    $u2 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('ugyfel') . ' WHERE id = %d', $uid('APP.CUSTOMER', 502)));
    T::egyezik(['ceg', '12345678-2-19', 10.0], [(string) $u2->tipus, (string) $u2->adoszam, (float) $u2->kedvezmeny], 'cég: típus, adószám, kedvezmény %');
    T::egyezik($meglevo, $uid('APP.CUSTOMER', 503), 'a már bent lévő ügyfél összekötve (nem új)');
    T::igaz((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('ugyfel_szo') . ' WHERE ugyfel_id = %d', (int) $u->id)) > 0, 'ügyfél: a gyorskereső feltöltve');

    // Eszköz
    $e = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('eszkoz') . ' WHERE id = %d', $uid('APP.THING', 701)));
    T::egyezik(['telefon', 'Mobiltelefon', 'Samsung', 'SM-F711B', '354949610378226', '354949610378234'], [(string) $e->kategoria, (string) $e->megnevezes, (string) $e->gyarto, (string) $e->tipus, (string) $e->imei, (string) $e->imei2], 'eszköz: kategória, gyártó, típus, két IMEI');
    T::egyezik([1, '2027-03-01', 'karcos', (int) $u->id], [(int) $e->garancias, (string) $e->garancia_lejar, (string) $e->megjegyzes, (int) $e->ugyfel_id], 'eszköz: garancia, lejárat, megjegyzés, ügyfél');
    T::nem_tartalmaz((string) $e->belso_megjegyzes, 'NV-' . $r, 'eszköz: a „kihagy” mező nem kerül a megjegyzésbe');
    T::egyezik('Töltő, Hálózati kábel', (string) $e->tartozekok, 'eszköz: a munkalap tartozékai az üres Tartozékok mezőbe');
    T::egyezik(0, $uid('APP.THING', 703), 'a „Vásárlás” tárgyból nem lesz eszköz');
    T::egyezik('laptop', (string) $wpdb->get_var($wpdb->prepare('SELECT kategoria FROM ' . $T('eszkoz') . ' WHERE id = %d', $uid('APP.THING', 702))), 'eszköz: Laptop → laptop kategória');

    // Munkalap
    $m = t_lap($uid('APP.WORKSHEET', 880001));
    T::egyezik([880001, 'lezart', 'javitas', (int) $u->id, (int) $e->id, (int) $technikus], [(int) $m->munkalap_szam, (string) $m->allapot, (string) $m->fajta, (int) $m->ugyfel_id, (int) $m->eszkoz_id, (int) $m->felelos], 'munkalap: régi szám, lezárt, ügyfél, eszköz, felelős');
    T::egyezik(['2025-05-14', '2025-05-14', 1, '2025-05-14', 'Bankkártya'], [(string) $m->keszult, (string) $m->lezarva, (int) $m->fizetve, (string) $m->fizetes_ideje, (string) $m->fizetesi_mod], 'munkalap: dátumok, fizetve, fizetési mód');
    T::egyezik([24015.75, 30500.0, 30500.0], [(float) $m->netto_ertek, (float) $m->brutto_ertek, (float) $m->fizetett], 'munkalap: nettó, bruttó (kerekítve), fizetett = bruttó');
    T::egyezik('A bekapcsoló gomb hibás volt.', (string) $m->ugyfel_megjegyzes, 'munkalap: a régi megjegyzés az ügyfélnek szóló mezőben');
    T::tartalmaz((string) $m->megjegyzes, 'IMEI ellenőrizve', 'munkalap: a belső megjegyzés');
    T::tartalmaz((string) $m->megjegyzes, 'Átvett tartozékok: Töltő, Hálózati kábel', 'munkalap: az átvett tartozékok (a logikai mező is)');
    T::tartalmaz((string) $m->megjegyzes, 'sztornózott tétel: Kijelző ' . $r, 'munkalap: a sztornózott tétel a belső megjegyzésben');
    $naplo = $wpdb->get_results($wpdb->prepare('SELECT allapot, elozo, felhasznalo, letrehozva FROM ' . $T('munkalap_naplo') . ' WHERE munkalap_id = %d ORDER BY letrehozva, id', (int) $m->id));
    T::egyezik(['arajanlat', 'elkeszult', 'lezart'], array_map(static fn ($x): string => (string) $x->allapot, $naplo), 'napló: az állapotváltások időrendben');
    T::egyezik(['', 'arajanlat', 'elkeszult'], array_map(static fn ($x): string => (string) $x->elozo, $naplo), 'napló: az előző állapot');
    T::egyezik([(int) $technikus, '2025-05-14 11:39:00'], [(int) $naplo[1]->felhasznalo, (string) $naplo[1]->letrehozva], 'napló: ki és mikor (párosított felhasználó)');
    $m2 = t_lap($uid('APP.WORKSHEET', 880002));
    T::egyezik(['fuggo', '2025-06-10', 0, 5000.0], [(string) $m2->allapot, (string) $m2->hatarido, (int) $m2->fizetve, (float) $m2->fizetett], 'függő lap: határidő, előleg = fizetett');
    $m3 = t_lap($uid('APP.WORKSHEET', 880003));
    T::egyezik(['arukiadas', 0, 'Készpénz'], [(string) $m3->fajta, (int) $m3->eszkoz_id, (string) $m3->fizetesi_mod], '„Vásárlás” lap: napi árukiadás, eszköz nélkül');
    $m4 = t_lap($uid('APP.WORKSHEET', 1));
    T::egyezik('ervenytelen', (string) $m4->allapot, 'érvénytelen lap');
    T::igaz((int) $m4->munkalap_szam > 880003, 'foglalt munkalapszám: új szám a régiek után (' . (int) $m4->munkalap_szam . ')');
    T::tartalmaz((string) $m4->megjegyzes, 'MunkaLap 3 munkalapszám: 1', 'foglalt szám: a régi szám a belső megjegyzésben');
    T::egyezik($meglevo, (int) $m4->ugyfel_id, 'a lap a meglévő ügyfélhez kötve');

    // Hibák
    $h = $wpdb->get_results($wpdb->prepare('SELECT leiras, javitas, allapot FROM ' . $T('munkalap_hiba') . ' WHERE munkalap_id = %d ORDER BY sorrend', (int) $m->id));
    T::egyezik([['Nem kapcsol be', 'Gomb cserélve', 'kesz'], ['Karcos hátlap', '', 'nem_javitando']], array_map(static fn ($x): array => [(string) $x->leiras, (string) $x->javitas, (string) $x->allapot], $h), 'hibák: leírás, javítás, állapot (Kijavított → Kész, Nem javítandó)');
    T::igaz(isset(SDH_Muhely_Munkalap::hiba_allapotok()['nem_javitando']), 'a „Nem javítandó” hibaállapot felvéve');
    T::tartalmaz((string) get_option('sdh_muhely_munkalap_hiba_allapotok', ''), 'nem_javitando | Nem javítandó', 'a hibaállapot a beállításokban');

    // Tételek
    $tt = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $T('munkalap_tetel') . ' WHERE munkalap_id = %d ORDER BY sorrend', (int) $m->id));
    T::egyezik(2, count($tt), 'tételek: kettő (a sztornózott nem)');
    T::egyezik(['termek', 'Kijelző ' . $r, 1.0, 20000.0, 15748.03, 'K-1', 'GH97', 'Teszt Kft ' . $r, 'teljesitett'], [(string) $tt[0]->tipus, (string) $tt[0]->megnevezes, (float) $tt[0]->mennyiseg, (float) $tt[0]->brutto_ar, (float) $tt[0]->netto_ar, (string) $tt[0]->termekkod, (string) $tt[0]->cikkszam, (string) $tt[0]->elado, (string) $tt[0]->allapot], 'termék-tétel: ár, kód, eladó');
    T::egyezik(['szolgaltatas', 10.0, 10500.0, 27.0], [(string) $tt[1]->tipus, (float) $tt[1]->kedvezmeny, (float) $tt[1]->brutto_ertek, (float) $tt[1]->afa], 'szolgáltatás-tétel: kedvezmény, érték, áfa');
    T::egyezik(round((float) $m->brutto_ertek, 0), round((float) $tt[0]->brutto_ertek + (float) $tt[1]->brutto_ertek, 0), 'a tételek összege a lap végösszege');

    // Termék, szolgáltatás, készlet
    $tk = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('termek') . ' WHERE id = %d', $uid('APP.MASTER_ITEM', 901)));
    T::egyezik(['Kijelző ' . $r, 'Alkatrész / Samsung telefon / SM-A326B', 'Teszt Kft ' . $r, 20000.0, 10000.0, '5900000000001', 3.0], [(string) $tk->megnevezes, (string) $tk->kategoria, (string) $tk->beszallito, (float) $tk->brutto_ar, (float) $tk->besz_netto, (string) $tk->vonalkod, (float) $tk->keszlet], 'termék: kategória úttal, beszállító, árak, készlet = a régi (5 − 1 − 1)');
    T::egyezik(3, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('termek_mozgas') . ' WHERE termek_id = %d', (int) $tk->id)), 'készlet: nyitó + két eladás (a sztornózott nem)');
    T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('termek_mozgas') . " WHERE termek_id = %d AND tipus = 'munkalap' AND munkalap_id = %d AND tetel_id = %d", (int) $tk->id, (int) $m->id, (int) $tt[0]->id)), 'készletmozgás a munkalaphoz és a tételhez kötve');
    T::egyezik($uid('APP.MASTER_ITEM', 902), $uid('APP.MASTER_ITEM', 903), 'egyforma nevű szolgáltatás: egy rekord');
    T::egyezik(10000.0, (float) $wpdb->get_var($wpdb->prepare('SELECT brutto_ar FROM ' . $T('szolgaltatas') . ' WHERE id = %d', $uid('APP.MASTER_ITEM', 902))), 'összevont szolgáltatás: a legújabb ár');

    // Csatolmányok, e-mail
    $cs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $T('csatolmany') . ' WHERE tipus = %s AND ref_id = %d', 'munkalap', (int) $m->id));
    T::egyezik(['20250514_104153.jpg', 'image/jpeg', '2025-05-14 10:50:00'], [(string) $cs[0]->eredeti_nev, (string) $cs[0]->mime, (string) $cs[0]->feltoltve], 'munkalap képe: név, típus, idő');
    T::egyezik("\xFF\xD8\xFF munkalap kep {$r}", (string) file_get_contents(SDH_Muhely_Csatolmany::fajl_ut($cs[0])), 'a kép tartalma bájtra egyezik');
    T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('csatolmany') . " WHERE tipus = 'ugyfel' AND ref_id = %d", (int) $u->id)), 'ügyfél fájlja');
    $mf = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $T('csatolmany') . " WHERE tipus = 'munkalap_fajl' AND ref_id = %d ORDER BY id", (int) $m->id));
    T::egyezik(2, count($mf), 'a munkalap Fájlok füle: az e-mail és a melléklete');
    $html = (string) file_get_contents(SDH_Muhely_Csatolmany::fajl_ut($mf[0]));
    T::tartalmaz($html, 'Kész a javítás &#337;', 'az e-mail tartalma HTML-ben, ahogy elküldték');
    T::tartalmaz((string) $mf[0]->eredeti_nev, 'E-mail-2025-05-14', 'az e-mail fájlneve a dátummal');
    T::tartalmaz($html, 'tunde' . $r . '@example.com', 'az e-mail címzettje');
    T::tartalmaz((string) $mf[1]->eredeti_nev, 'E-mail-melleklet', 'a melléklet neve jelöli, honnan jön');
    $logo = json_decode((string) $wpdb->get_var("SELECT adat FROM " . $M::nyers_tabla() . " WHERE tabla = 'APP.FILE'"), true);
    T::igaz(strpos((string) ($logo['BYTES']['tar'] ?? ''), 'fajlok/') === 0 && is_file($M::mappa() . '/' . $logo['BYTES']['tar']), 'a le nem képzett fájl a modul tárában megőrizve');
    $hirlevel = $wpdb->get_row("SELECT uj_id, megj FROM " . $M::nyers_tabla() . " WHERE tabla = 'APP.EMAIL' AND kulcs = '2'");
    T::egyezik([0, 'Nem munkalaphoz tartozó e-mail.'], [(int) $hirlevel->uj_id, (string) $hirlevel->megj], 'a nem munkalaphoz tartozó e-mail csak a régi adatok közt, okkal');
    T::egyezik('Hibakatalógus (lista) eleme.', (string) $wpdb->get_var("SELECT megj FROM " . $M::nyers_tabla() . " WHERE tabla = 'APP.PROBLEM' AND kulcs = '1'"), 'a hibakatalógus eleme a régi adatok közt');

    // Riport, beállítások
    $e = $a['eredmeny'];
    T::egyezik((float) $e['regi_brutto'], (float) $e['uj_brutto'], 'riport: a régi és az átvett végösszeg egyezik');
    T::egyezik(4, (int) $e['uj']['munkalap'], 'riport: 4 munkalap');
    T::egyezik(2, (int) $e['uj']['ugyfel'], 'riport: 2 új ügyfél (a harmadik meglévő)');
    T::egyezik(4, (int) $e['fajlok_bent'], 'riport: minden fájl megvan');
    T::igaz(in_array('Bankkártya', SDH_Muhely_Munkalap::fizetesi_modok(), true), 'fizetési mód a listában');

    /* --- Régi adatok böngészése ------------------------------------------------------------------------- */

    $v = t_ajax('sdh_muhely_ml3_regi', ['tabla' => 'APP.WORKSHEET', 'keres' => '880001'], '_wpnonce', 'sdh_muhely_ml3');
    T::igaz(!empty($v['success']) && (int) $v['data']['db'] === 1, 'böngészés: keresés azonosítóra');
    T::tartalmaz((string) ($v['data']['sorok'][0]['uj'] ?? ''), 'Munkalap', 'böngészés: mi lett belőle');
    $v = t_ajax('sdh_muhely_ml3_regi', ['tabla' => 'APP.CUSTOMER', 'keres' => 'P-77'], '_wpnonce', 'sdh_muhely_ml3');
    T::egyezik(1, (int) ($v['data']['db'] ?? 0), 'böngészés: szabad szöveg');

    /* --- Újraátvétel: változatlan, frissített, új állapot ----------------------------------------------- */

    $tablak2 = $tablak;
    $tablak2['APP.WORKSHEET'][1][1]['STATE'] = 2;
    $tablak2['APP.WORKSHEET'][1][1]['COMPLETION_TIME'] = '2025-06-05 10:00:00.0';
    $tablak2['APP.WORKSHEET'][1][1]['INTERNAL_NOTES'] = 'Alkatrész megjött';
    $tablak2['APP.WORKSHEET'][1][] = ['WORKSHEET_ID' => 880004, 'THING_ID' => 701, 'CUSTOMER_ID' => 501, 'STATE' => 1, 'CREATION_TIME' => '2025-07-01 09:00:00.0', 'OPENING_TIME' => '2025-07-01 09:00:00.0', 'NET_SUM' => '0', 'TAX_SUM' => '0', 'SYSTEM_CREATION_TIME' => '2025-07-01 09:00:00.0'];
    $zip2 = $dir . '/csomag2.zip';
    $csomag($tablak2, $fajl_tartalom, $zip2);
    T::egyezik('', $M::forras_be($zip2, 'csomag2.zip', false), 'az újabb mentés rögzítve');
    $o = $futtat();
    T::egyezik('kesz', $o['fazis'], 'újabb mentés: kész a párosításra');
    $M::indit($M::allapot()['javaslat']);
    $o = $futtat();
    T::egyezik('befejezve', $o['fazis'], 'újabb mentés: kész');
    $a  = $M::allapot();
    $st = (array) $a['atvetel']['stat']['APP.WORKSHEET'];
    T::egyezik([1, 1, 3], [(int) ($st['uj'] ?? 0), (int) ($st['frissitett'] ?? 0), (int) ($st['valtozatlan'] ?? 0)], 'újraátvétel: 1 új, 1 frissített, 3 változatlan lap');
    T::egyezik(3, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $T('ugyfel') . " WHERE forras = 'munkalap3' AND kulso_azonosito IN ('501','502','503')"), 'ügyfelek: nincs duplázás');
    $m2 = t_lap($uid('APP.WORKSHEET', 880002));
    T::egyezik('elkeszult', (string) $m2->allapot, 'frissített lap: új állapot');
    T::tartalmaz((string) $m2->megjegyzes, 'Alkatrész megjött', 'frissített lap: új belső megjegyzés');
    T::egyezik(['fuggo', 'elkeszult'], array_map('strval', (array) $wpdb->get_col($wpdb->prepare('SELECT allapot FROM ' . $T('munkalap_naplo') . ' WHERE munkalap_id = %d ORDER BY letrehozva', (int) $m2->id))), 'frissített lap: csak az új állapotváltás került a naplóba');
    T::egyezik(1, count((array) $wpdb->get_results($wpdb->prepare('SELECT id FROM ' . $T('csatolmany') . " WHERE tipus = 'munkalap' AND ref_id = %d", (int) $m->id))), 'újraátvétel: a kép nem duplázódik');
    T::egyezik(3.0, (float) $wpdb->get_var($wpdb->prepare('SELECT keszlet FROM ' . $T('termek') . ' WHERE id = %d', (int) $tk->id)), 'újraátvétel: a készlet változatlan');

    /* --- Modul ki: az AJAX nem fut ---------------------------------------------------------------------- */

    $ki_elotte = SDH_Muhely_Modulok::kikapcsoltak();
    SDH_Muhely_Modulok::kikapcsoltak_ment(array_merge($ki_elotte, ['munkalapclone']));
    T::igaz(!isset(SDH_Muhely_Beallitasok::szakaszok()['munkalapclone']), 'kikapcsolva: nincs lapfül');
    T::egyezik('munkalapclone', SDH_Muhely_Modulok::ajax_modulja('ml3_lepes'), 'az AJAX a modulhoz tartozik (kikapcsolva elutasítja az őr)');
    SDH_Muhely_Modulok::kikapcsoltak_ment($ki_elotte);
    T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['munkalapclone']), 'visszakapcsolva: újra van lapfül');

    // A lapfül kirajzolása figyelmeztetés nélkül.
    ob_start();
    $M::beallitas_doboz();
    $lap = (string) ob_get_clean();
    T::tartalmaz($lap, 'data-sdh-ml3', 'a lapfül kirajzolva');
    T::tartalmaz($lap, 'Ellenőrző riport', 'az eredmény a lapfülön');
    T::tartalmaz($lap, 'Régi adatok', 'a régi adatok böngészője a lapfülön');
    T::nem_tartalmaz($lap, 'Warning', 'nincs PHP-figyelmeztetés');

    /* --- Visszavonás -------------------------------------------------------------------------------------- */

    $kep_ut = SDH_Muhely_Csatolmany::fajl_ut($cs[0]);
    T::egyezik('', $M::visszavon_indit(), 'visszavonás indítva');
    $o = $futtat();
    T::egyezik('ures', $o['fazis'], 'visszavonás kész');
    T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $M::nyers_tabla()), 'a nyers tár üres');
    T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('atvetel_sor') . ' WHERE import_id = %s', $M::IMPORT_ID)), 'a követés üres');
    T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $T('munkalap') . " WHERE forras = 'munkalap3' AND kulso_azonosito IN ('880001','880002','880003','880004','1')"), 'a munkalapok törölve');
    T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $T('ugyfel') . " WHERE forras = 'munkalap3' AND kulso_azonosito IN ('501','502')"), 'az új ügyfelek törölve');
    T::egyezik(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('ugyfel') . ' WHERE id = %d', $meglevo)), 'a már bent volt ügyfél megmaradt');
    T::igaz(!is_file($kep_ut), 'a kép fájlja törölve');
    T::egyezik(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('termek') . ' WHERE id = %d', (int) $tk->id)), 'a termék törölve');
    T::egyezik($hiba_elotte, get_option('sdh_muhely_munkalap_hiba_allapotok', null), 'a hibaállapotok beállítása visszaállítva');
    T::egyezik($fiz_elotte, get_option('sdh_muhely_fizetesi_modok', null), 'a fizetési módok beállítása visszaállítva');
    T::tartalmaz((string) $o['uzenet'], 'Visszavonva', 'a felület jelzi');
} finally {
    if (isset($technikus) && is_int($technikus)) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($technikus);
    }

    $wpdb->query($wpdb->prepare('DELETE FROM ' . $T('munkalap') . ' WHERE nev = %s', 't96 foglaló ' . $r));

    if (isset($meglevo)) {
        $wpdb->delete($T('ugyfel'), ['id' => $meglevo]);
    }

    delete_option($M::OPT);
    delete_option($M::OPT_BEALL);
    $M::urit();

    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }

    @rmdir($dir);
}

T::vege('t96 Munkalapszoftver Clone');
