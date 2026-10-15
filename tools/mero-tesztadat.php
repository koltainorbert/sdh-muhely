<?php
/**
 * SDH Műhely – mérőpad: nagy mennyiségű TESZTADAT generálása (és törlése).
 *
 *   SDH_MERO_BIZTOS=igen SDH_WP_LOAD=/út/wp-load.php php tools/mero-tesztadat.php            # generál
 *   SDH_MERO_BIZTOS=igen SDH_WP_LOAD=/út/wp-load.php php tools/mero-tesztadat.php torol      # töröl
 *   … php tools/mero-tesztadat.php allapot                                                    # számlálók
 *   … php tools/mero-tesztadat.php general 0.1                                                # a méret 10%-a
 *   … php tools/mero-tesztadat.php kereso                                                     # a mérő-ügyfelek kereső-adatai újra
 *   … php tools/mero-tesztadat.php hozzaad 1.0                                                # a meglévő mérő-adat mellé még egyszer ennyi
 *
 * Alapméret (1.0): 500 000 ügyfél, 600 000 eszköz, 500 000 munkalap (kb. 1,25 millió tétel),
 * ~150 000 pénztártétel, ~200 000 RMA-üzenet.
 *
 * BIZTONSÁG – ez a szkript soha nem éles adatbázisra való:
 *  - csak az SDH_MERO_BIZTOS=igen környezeti változóval indul;
 *  - megáll, ha a táblákban a saját (nem mérő-) sorok száma éles jellegű
 *    (több mint 2000 ügyfél vagy 5000 munkalap), hacsak SDH_MERO_ERŐSZAK=igen nincs megadva;
 *  - minden általa írt sor jelölt (kulso_azonosito / kulso = „mero-…"), a törlés csak ezeket
 *    viszi el (az üzeneteket a mérő-munkalapokon át);
 *  - a változásnaplóba nem ír (csendben), a hálózatra nem megy ki.
 *
 * A tools/ mappa nem kerül a telepítő zipbe (.gitattributes), és a munkagépre sem szinkronizáljuk.
 */

if (PHP_SAPI !== 'cli') {
    exit("Csak parancssorból futtatható.\n");
}

if (getenv('SDH_MERO_BIZTOS') !== 'igen') {
    fwrite(STDERR, "Ez a szkript TESZT-adatbázisra való, és tömegesen ír. Indítás: SDH_MERO_BIZTOS=igen php tools/mero-tesztadat.php\n");
    exit(2);
}

$wp_load = getenv('SDH_WP_LOAD') ?: dirname(__DIR__, 4) . '/wp-load.php';

if (!is_file($wp_load)) {
    fwrite(STDERR, "Nem találom a wp-load.php fájlt. Add meg: SDH_WP_LOAD=/út/wp-load.php\n");
    exit(2);
}

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require $wp_load;

if (!class_exists('SDH_Muhely_Schema')) {
    fwrite(STDERR, "Az SDH Műhely plugin nincs bekapcsolva.\n");
    exit(2);
}

global $wpdb;

$parancs = $argv[1] ?? 'general';
$meret   = isset($argv[2]) ? max(0.001, (float) $argv[2]) : 1.0;

wp_set_current_user(1);
SDH_Muhely_Schema::frissites_ha_kell();

$T = static fn (string $n): string => SDH_Muhely_Schema::tabla($n);
$ugyfel_t  = $T('ugyfel');
$eszkoz_t  = $T('eszkoz');
$munkalap_t = $T('munkalap');
$tetel_t   = $T('munkalap_tetel');
$penztar_t = $T('penztar');
$uzenet_t  = $T('uzenet');

$szam = static fn (string $t, string $hol = '1=1'): int => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$hol}");

/* -------------------------------------------------------------------- */
/* Állapot                                                               */
/* -------------------------------------------------------------------- */

if ($parancs === 'allapot') {
    foreach (['ugyfel' => $ugyfel_t, 'eszkoz' => $eszkoz_t, 'munkalap' => $munkalap_t, 'tétel' => $tetel_t, 'pénztár' => $penztar_t, 'üzenet' => $uzenet_t] as $n => $t) {
        $mero = $t === $uzenet_t
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE munkalap_id IN (SELECT id FROM {$munkalap_t} WHERE kulso_azonosito LIKE 'mero-%')")
            : (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $t . ' WHERE ' . ($t === $penztar_t ? 'kulso' : 'kulso_azonosito') . " LIKE 'mero-%'");
        echo sprintf("%-9s összes: %9d   ebből mérő: %9d\n", $n, $szam($t), $mero);
    }

    exit(0);
}

/* -------------------------------------------------------------------- */
/* Törlés                                                                */
/* -------------------------------------------------------------------- */

$csendben = static fn (callable $f) => SDH_Muhely_Valtozasnaplo::csendben($f);

if ($parancs === 'torol') {
    $csendben(static function () use ($wpdb, $ugyfel_t, $eszkoz_t, $munkalap_t, $tetel_t, $penztar_t, $uzenet_t): void {
        $mero_lapok = "SELECT id FROM {$munkalap_t} WHERE kulso_azonosito LIKE 'mero-%'";

        foreach ([
            "DELETE FROM {$uzenet_t} WHERE munkalap_id IN ({$mero_lapok})",
            "DELETE FROM {$tetel_t} WHERE munkalap_id IN ({$mero_lapok})",
            "DELETE FROM {$penztar_t} WHERE kulso LIKE 'mero-%'",
            "DELETE FROM {$munkalap_t} WHERE kulso_azonosito LIKE 'mero-%'",
            "DELETE FROM {$eszkoz_t} WHERE kulso_azonosito LIKE 'mero-%'",
            'DELETE FROM ' . SDH_Muhely_Schema::tabla('ugyfel_szo') . " WHERE ugyfel_id IN (SELECT id FROM {$ugyfel_t} WHERE kulso_azonosito LIKE 'mero-%')",
            "DELETE FROM {$ugyfel_t} WHERE kulso_azonosito LIKE 'mero-%'",
        ] as $sql) {
            $wpdb->query($sql);
        }
    });

    SDH_Muhely_Gyorsitotar::ervenytelenit();
    echo "Törölve: minden „mero-” jelölésű sor (ügyfél és szó-indexe, eszköz, munkalap, tétel, pénztár, üzenet).\n";
    exit(0);
}

/* -------------------------------------------------------------------- */
/* Biztonsági őr                                                          */
/* -------------------------------------------------------------------- */

$sajat_ugyfel = $szam($ugyfel_t, "kulso_azonosito NOT LIKE 'mero-%'");
$sajat_lap    = $szam($munkalap_t, "kulso_azonosito NOT LIKE 'mero-%'");

if (($sajat_ugyfel > 2000 || $sajat_lap > 5000) && getenv('SDH_MERO_ERŐSZAK') !== 'igen') {
    fwrite(STDERR, "MEGÁLLTAM: az adatbázisban {$sajat_ugyfel} ügyfél és {$sajat_lap} munkalap van – ez éles adatbázisnak látszik. Itt nem generálok.\n");
    exit(3);
}


/* -------------------------------------------------------------------- */
/* Adatforrások                                                           */
/* -------------------------------------------------------------------- */

mt_srand(20261014);

$vezeteknevek = ['Kovács', 'Nagy', 'Szabó', 'Tóth', 'Horváth', 'Varga', 'Kiss', 'Molnár', 'Németh', 'Farkas', 'Balogh', 'Papp', 'Takács', 'Juhász', 'Lakatos', 'Mészáros', 'Oláh', 'Simon', 'Rácz', 'Fekete', 'Szilágyi', 'Török', 'Fehér', 'Balázs', 'Gál', 'Kis', 'Szűcs', 'Kocsis', 'Pintér', 'Fodor', 'Orsós', 'Szalai', 'Sipos', 'Magyar', 'Lukács', 'Gulyás', 'Biró', 'Király', 'Katona', 'László', 'Jakab', 'Bogdán', 'Hegedűs', 'Illés', 'Vincze', 'Bodnár', 'Sándor', 'Antal', 'Pál', 'Somogyi'];
$keresztnevek = ['János', 'István', 'József', 'László', 'Zoltán', 'Gábor', 'Attila', 'Tamás', 'Péter', 'András', 'Ferenc', 'Sándor', 'Balázs', 'Norbert', 'Csaba', 'Tibor', 'Miklós', 'Ádám', 'Dániel', 'Gergely', 'Mária', 'Erzsébet', 'Katalin', 'Ilona', 'Éva', 'Anna', 'Judit', 'Ágnes', 'Zsuzsanna', 'Andrea', 'Eszter', 'Réka', 'Nóra', 'Orsolya', 'Edina', 'Szilvia', 'Krisztina', 'Gabriella', 'Hajnalka', 'Lilla'];
$varosok = [['8200', 'Veszprém'], ['8230', 'Balatonfüred'], ['8300', 'Tapolca'], ['8400', 'Ajka'], ['8500', 'Pápa'], ['8000', 'Székesfehérvár'], ['9700', 'Szombathely'], ['9400', 'Sopron'], ['9021', 'Győr'], ['7621', 'Pécs'], ['6720', 'Szeged'], ['4024', 'Debrecen'], ['3525', 'Miskolc'], ['1011', 'Budapest'], ['1051', 'Budapest'], ['2800', 'Tatabánya'], ['2400', 'Dunaújváros'], ['8800', 'Nagykanizsa'], ['8360', 'Keszthely'], ['8220', 'Balatonalmádi']];
$utcak = ['Kossuth Lajos', 'Petőfi Sándor', 'Széchenyi', 'Ady Endre', 'Rákóczi', 'Arany János', 'Dózsa György', 'Jókai Mór', 'Szent István', 'Kodály Zoltán', 'Bartók Béla', 'Fő', 'Templom', 'Iskola', 'Hunyadi', 'Béke', 'Tó', 'Hegy', 'Vár', 'Piac'];
$gyartok = [['Apple', ['iPhone 11', 'iPhone 12', 'iPhone 13', 'iPhone 14 Pro', 'iPhone SE', 'iPad Air', 'MacBook Air']], ['Samsung', ['Galaxy S21', 'Galaxy S22', 'Galaxy A52', 'Galaxy A54', 'Galaxy S23 Ultra', 'Galaxy Tab S7']], ['Xiaomi', ['Redmi Note 11', 'Redmi Note 12', 'Poco X5', 'Mi 11']], ['Huawei', ['P30 Lite', 'P40', 'Mate 20']], ['Lenovo', ['ThinkPad E14', 'IdeaPad 3']], ['HP', ['Pavilion 15', 'ProBook 450']], ['Asus', ['VivoBook 15', 'ROG Strix']], ['Sony', ['PlayStation 5', 'Xperia 5']]];
$tetelnevek = [['Kijelzőcsere', 38000], ['Akkumulátorcsere', 16500], ['Töltőcsatlakozó csere', 14500], ['Bevizsgálási díj', 4500], ['Szoftveres javítás', 8900], ['Vízkár tisztítás', 12000], ['Számítógép szerviz szolgáltatás', 9500], ['Alaplapi javítás', 42000], ['Hátlap csere', 11000], ['Kamera csere', 21000]];
$allapotok = array_keys(SDH_Muhely_Munkalap::allapotok());
$zart_allapotok = array_keys(array_filter(SDH_Muhely_Munkalap::allapotok(), static fn ($a) => !empty($a['zart'])));

$v = static fn (array $l) => $l[mt_rand(0, count($l) - 1)];
$q = static fn (string $s): string => "'" . esc_sql($s) . "'";

/**
 * SQLite (a konténeres tesztkörnyezet): közvetlenül PDO-n írunk – a WordPress SQLite-plugin SQL-fordítója
 * ezer sornál is másodperceket vesz el. MySQL / MariaDB: a megszokott $wpdb.
 */
$pdo = (is_object($wpdb->dbh ?? null) && method_exists($wpdb->dbh, 'get_pdo')) ? $wpdb->dbh->get_pdo() : null;

$sql_fut = static function (string $sql) use ($wpdb, $pdo): void {
    if ($pdo !== null) {
        $pdo->exec($sql);

        return;
    }

    $wpdb->query($sql);
};

/** Sorok beszúrása kötegekben, egy tranzakcióban; a változásnaplót nem terheli. */
$beszur = static function (string $tabla, array $oszlopok, callable $sorGenerator, int $db, int $koteg = 1000) use ($csendben, $sql_fut, $pdo): void {
    $oszlop_sql = '(' . implode(',', $oszlopok) . ')';
    $kezdet = microtime(true);

    $csendben(static function () use ($tabla, $oszlop_sql, $sorGenerator, $db, $koteg, $kezdet, $sql_fut, $pdo): void {
        $kezd = static function () use ($sql_fut, $pdo): void {
            $pdo !== null ? $pdo->beginTransaction() : $sql_fut('START TRANSACTION');
        };
        $zar = static function () use ($sql_fut, $pdo): void {
            $pdo !== null ? $pdo->commit() : $sql_fut('COMMIT');
        };

        $kezd();

        for ($i = 0; $i < $db; $i += $koteg) {
            $sorok = [];
            $veg   = min($db, $i + $koteg);

            for ($k = $i; $k < $veg; $k++) {
                $sorok[] = '(' . $sorGenerator($k) . ')';
            }

            $sql_fut("INSERT INTO {$tabla} {$oszlop_sql} VALUES " . implode(',', $sorok));

            if (($i / $koteg) % 50 === 49) {
                $zar();
                $kezd();
                fwrite(STDERR, sprintf("  %s: %d / %d (%.0f s)\r", $tabla, $veg, $db, microtime(true) - $kezdet));
            }
        }

        $zar();
    });

    fwrite(STDERR, sprintf("  %s: %d sor (%.0f s)            \n", $tabla, $db, microtime(true) - $kezdet));
};

/** A mérő-ügyfelek szó-indexe (és kereső-oszlopai, ha $oszlopok) – közvetlen, kötegelt írással. */
$kereso_szavak = static function (int $tol, int $db, bool $oszlopok = false) use ($wpdb, $ugyfel_t, $sql_fut, $csendben, $pdo): void {
    $szo_t  = SDH_Muhely_Schema::tabla('ugyfel_szo');
    $kezdet = microtime(true);

    $csendben(static function () use ($wpdb, $ugyfel_t, $szo_t, $sql_fut, $pdo, $tol, $db, $oszlopok, $kezdet): void {
        for ($i = $tol; $i < $tol + $db; $i += 2000) {
            $sorok = $wpdb->get_results($wpdb->prepare(
                "SELECT id, nev, szamlazasi_telepules, szamlazasi_cim, szamlazasi_iranyitoszam, email, ugyfel_szam, telefon, telefon2
                 FROM {$ugyfel_t} WHERE id >= %d AND id < %d AND kulso_azonosito LIKE 'mero-%%'",
                $i,
                min($tol + $db, $i + 2000)
            ));

            if (!$sorok) {
                continue;
            }

            $pdo !== null ? $pdo->beginTransaction() : $sql_fut('START TRANSACTION');
            $ertek = [];
            $idk   = [];

            foreach ($sorok as $sor) {
                $idk[] = (int) $sor->id;

                foreach (SDH_Muhely_Ugyfel::kereso_szavak($sor) as $szo) {
                    $ertek[] = "('" . esc_sql($szo) . "'," . (int) $sor->id . ')';
                }

                if ($oszlopok) {
                    $a = SDH_Muhely_Ugyfel::kereso_adat($sor);
                    $sql_fut("UPDATE {$ugyfel_t} SET kereso = '" . esc_sql($a['kereso']) . "', tel_ford = '" . esc_sql($a['tel_ford'])
                        . "', cim_rendez = '" . esc_sql($a['cim_rendez']) . "' WHERE id = " . (int) $sor->id);
                }
            }

            $sql_fut("DELETE FROM {$szo_t} WHERE ugyfel_id IN (" . implode(',', $idk) . ')');

            foreach (array_chunk($ertek, 2000) as $darab) {
                $sql_fut("INSERT INTO {$szo_t} (szo, ugyfel_id) VALUES " . implode(',', $darab));
            }

            $pdo !== null ? $pdo->commit() : $sql_fut('COMMIT');
            fwrite(STDERR, sprintf("  szó-index: %d / %d (%.0f s)\r", $i - $tol + 2000, $db, microtime(true) - $kezdet));
        }
    });

    fwrite(STDERR, sprintf("  szó-index kész (%.0f s)            \n", microtime(true) - $kezdet));
};

if ($parancs === 'kereso') {
    $tol = (int) $wpdb->get_var("SELECT MIN(id) FROM {$ugyfel_t} WHERE kulso_azonosito LIKE 'mero-%'");
    $ig  = (int) $wpdb->get_var("SELECT MAX(id) FROM {$ugyfel_t} WHERE kulso_azonosito LIKE 'mero-%'");
    $kereso_szavak($tol, $ig - $tol + 1, true);

    if ($szam($ugyfel_t, "kulso_azonosito NOT LIKE 'mero-%'") === 0) {
        update_option(SDH_Muhely_Ugyfel::KERESO_ALLAPOT, SDH_Muhely_Ugyfel::KERESO_KESZ);
        delete_option('sdh_muhely_kereso_utolso');
    }

    echo "Kész.\n";
    exit(0);
}

$N_U = (int) round(500000 * $meret);
$N_E = (int) round(600000 * $meret);
$N_L = (int) round(500000 * $meret);

$id_u = 1 + (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$ugyfel_t}");
$id_e = 1 + (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$eszkoz_t}");
$id_l = 1 + (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$munkalap_t}");
$id_t = 1 + (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$tetel_t}");
$szam_l = 1 + (int) $wpdb->get_var("SELECT COALESCE(MAX(munkalap_szam), 0) FROM {$munkalap_t}");

$most_ts = time();
$datum = static fn (int $napja): string => gmdate('Y-m-d', $most_ts - $napja * 86400);

if ($parancs !== 'hozzaad' && $szam($ugyfel_t, "kulso_azonosito LIKE 'mero-%'") > 0) {
    fwrite(STDERR, "Már van mérő-adat. Előbb: php tools/mero-tesztadat.php torol (vagy: hozzaad)\n");
    exit(3);
}

/* -------------------------------------------------------------------- */
/* Ügyfelek                                                               */
/* -------------------------------------------------------------------- */

echo "Ügyfelek: {$N_U}\n";

$ugyfel_nev = [];

$beszur($ugyfel_t, [
    'id', 'ugyfel_szam', 'tipus', 'nev', 'telefon', 'email', 'szamlazasi_iranyitoszam', 'szamlazasi_telepules',
    'szamlazasi_cim', 'aktiv', 'forras', 'kulso_azonosito', 'letrehozva', 'kereso', 'tel_ford', 'cim_rendez',
], static function (int $i) use ($id_u, $vezeteknevek, $keresztnevek, $varosok, $utcak, $v, $q, $datum): string {
    $nev   = $v($vezeteknevek) . ' ' . $v($keresztnevek);
    $varos = $v($varosok);
    $tel   = '+36' . $v(['20', '30', '70', '30', '20']) . mt_rand(1000000, 9999999);
    $cim   = $v($utcak) . ' ' . $v(['utca', 'út', 'tér']) . ' ' . mt_rand(1, 120) . '.';
    $mail  = strtolower(remove_accents(str_replace(' ', '.', $nev))) . mt_rand(1, 9999) . '@example.hu';
    $id    = $id_u + $i;
    $adat  = SDH_Muhely_Ugyfel::kereso_adat([
        'nev' => $nev, 'telefon' => $tel, 'email' => $mail, 'szamlazasi_telepules' => $varos[1],
        'szamlazasi_cim' => $cim, 'szamlazasi_iranyitoszam' => $varos[0], 'ugyfel_szam' => sprintf('U-%06d', $id),
    ]);

    return implode(',', [
        $id, $q(sprintf('U-%06d', $id)), $q(mt_rand(0, 9) === 0 ? 'ceg' : 'maganszemely'), $q($nev), $q($tel), $q($mail),
        $q($varos[0]), $q($varos[1]), $q($cim), mt_rand(0, 40) === 0 ? 0 : 1, $q('mero'), $q('mero-u-' . $id),
        $q($datum(mt_rand(1, 1500)) . ' 10:00:00'), $q($adat['kereso']), $q($adat['tel_ford']), $q($adat['cim_rendez']),
    ]);
}, $N_U);

$kereso_szavak($id_u, $N_U);

// A kereső-oszlopok kész: a háttérfeltöltést nem kell megvárni.
$utolso_nem_mero = $szam($ugyfel_t, "kulso_azonosito NOT LIKE 'mero-%'");

if ($utolso_nem_mero === 0) {
    update_option(SDH_Muhely_Ugyfel::KERESO_ALLAPOT, SDH_Muhely_Ugyfel::KERESO_KESZ);
    delete_option('sdh_muhely_kereso_utolso');
}

/* -------------------------------------------------------------------- */
/* Eszközök                                                               */
/* -------------------------------------------------------------------- */

echo "Eszközök: {$N_E}\n";

$beszur($eszkoz_t, [
    'id', 'ugyfel_id', 'kategoria', 'gyarto', 'tipus', 'megnevezes', 'imei', 'sorozatszam', 'aktiv', 'forras', 'kulso_azonosito', 'letrehozva',
], static function (int $i) use ($id_e, $id_u, $N_U, $gyartok, $v, $q, $datum): string {
    $gy  = $v($gyartok);
    $tip = $v($gy[1]);

    return implode(',', [
        $id_e + $i, $id_u + (($i * 7919) % $N_U), $q($gy[0] === 'Apple' || $gy[0] === 'Samsung' ? 'telefon' : 'szamitogep'),
        $q($gy[0]), $q($tip), $q($gy[0] . ' ' . $tip), $q((string) mt_rand(350000000000000, 359999999999999)),
        $q(strtoupper(wp_generate_password(10, false))), 1, $q('mero'), $q('mero-e-' . ($id_e + $i)), $q($datum(mt_rand(1, 1500)) . ' 10:00:00'),
    ]);
}, $N_E);

/* -------------------------------------------------------------------- */
/* Munkalapok + tételek                                                    */
/* -------------------------------------------------------------------- */

echo "Munkalapok: {$N_L}\n";

$beszur($munkalap_t, [
    'id', 'munkalap_szam', 'allapot', 'nev', 'ugyfel_id', 'eszkoz_id', 'keszult', 'hatarido', 'lezarva', 'fizetve', 'fizetett',
    'netto_ertek', 'brutto_ertek', 'forras', 'kulso_azonosito', 'letrehozva', 'fajta', 'felelos',
], static function (int $i) use ($id_l, $szam_l, $id_e, $id_u, $N_U, $N_E, $allapotok, $zart_allapotok, $v, $q, $datum, $tetelnevek): string {
    mt_srand(77000 + $i);

    $r     = mt_rand(1, 100);
    $fajta = $r <= 70 ? 'javitas' : ($r <= 90 ? 'arukiadas' : 'rendeles');
    $napja = mt_rand(0, 1400);
    $zart  = $napja > 20 && mt_rand(1, 100) <= 80;
    $allap = $zart ? $v($zart_allapotok ?: $allapotok) : $v($allapotok);
    $esz   = $fajta === 'arukiadas' && mt_rand(0, 1) === 0 ? 0 : $id_e + (($i * 104729) % $N_E);
    $ug    = $esz === 0 ? 0 : $id_u + ((($esz - $id_e) * 7919) % $N_U);
    $netto = 0.0;
    $db    = mt_rand(1, 3);

    for ($k = 0; $k < $db; $k++) {
        $t      = $v($tetelnevek);
        $netto += round($t[1] / 1.27, 2);
    }

    $brutto = round($netto * 1.27, 2);
    $fizetve = $zart && mt_rand(1, 100) <= 90 ? 1 : 0;

    return implode(',', [
        $id_l + $i, $szam_l + $i, $q($allap), $q($v(['Kovács János', 'Nagy Éva', 'Szabó Péter', 'Tóth Anna', 'Varga Zoltán'])),
        $ug, $esz, $q($datum($napja)), $q($datum(max(0, $napja - mt_rand(1, 14)))), $zart ? $q($datum(max(0, $napja - mt_rand(0, 10)))) : 'NULL',
        $fizetve, $fizetve ? $brutto : 0, $netto, $brutto, $q('mero'), $q('mero-l-' . ($id_l + $i)), $q($datum($napja) . ' 09:00:00'), $q($fajta), mt_rand(1, 3),
    ]);
}, $N_L);

echo "Tételek (munkalaponként 1–3)\n";

$tetel_sorok = [];

for ($i = 0; $i < $N_L; $i++) {
    // A mérőpadnak a sorok száma és eloszlása kell; a tételek összege a munkalap értékének közelítése.
    mt_srand(88000 + $i);
    $db = mt_rand(1, 3);

    for ($k = 0; $k < $db; $k++) {
        $t = $tetelnevek[($i + $k) % count($tetelnevek)];
        $tetel_sorok[] = [$id_l + $i, $k, $t[0], round($t[1] / 1.27, 2), $t[1]];
    }
}

$tetel_db = count($tetel_sorok);

$beszur($tetel_t, [
    'id', 'munkalap_id', 'sorrend', 'tipus', 'allapot', 'megnevezes', 'mennyiseg', 'netto_ar', 'brutto_ar', 'netto_ertek', 'brutto_ertek', 'forras', 'kulso_azonosito',
], static function (int $i) use ($tetel_sorok, $id_t, $q): string {
    $s = $tetel_sorok[$i];

    return implode(',', [$id_t + $i, $s[0], $s[1], $q('szolgaltatas'), $q('teljesitett'), $q($s[2]), 1, $s[3], $s[4], $s[3], $s[4], $q('mero'), $q('mero-t-' . ($id_t + $i))]);
}, $tetel_db);

unset($tetel_sorok);

/* -------------------------------------------------------------------- */
/* Pénztár és RMA-üzenetek                                                  */
/* -------------------------------------------------------------------- */

$N_P = (int) round(150000 * $meret);
$N_M = (int) round(200000 * $meret);
$id_p = 1 + (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$penztar_t}");
$id_m = 1 + (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$uzenet_t}");

echo "Pénztártételek: {$N_P}\n";

$beszur($penztar_t, [
    'id', 'datum', 'tipus', 'leiras', 'nev', 'kp', 'kartya', 'munkalap_id', 'munkalap_szam', 'forras', 'kulso', 'letrehozva',
], static function (int $i) use ($id_p, $id_l, $szam_l, $N_L, $q, $datum): string {
    mt_srand(99000 + $i);

    $lap   = ($i * 31) % $N_L;
    $napja = mt_rand(0, 1400);
    $osszeg = mt_rand(30, 900) * 100;
    $kp    = mt_rand(0, 1) === 0;

    return implode(',', [
        $id_p + $i, $q($datum($napja)), $q('bevetel'), $q('Munkalap fizetés'), $q('Mérő ügyfél'), $kp ? $osszeg : 0, $kp ? 0 : $osszeg,
        $id_l + $lap, $q((string) ($szam_l + $lap)), $q('mero'), $q('mero-p-' . ($id_p + $i)), $q($datum($napja) . ' 12:00:00'),
    ]);
}, $N_P);

echo "RMA-üzenetek: {$N_M}\n";

$beszur($uzenet_t, ['id', 'munkalap_id', 'ugyfel_id', 'irany', 'csatorna', 'szoveg', 'olvasva', 'letrehozva'], static function (int $i) use ($id_m, $id_l, $id_u, $N_L, $N_U, $q, $datum): string {
    mt_srand(55000 + $i);

    $lap = ($i * 17) % $N_L;

    return implode(',', [
        $id_m + $i, $id_l + $lap, $id_u + (($i * 7919) % $N_U), $q(mt_rand(0, 1) ? 'be' : 'ki'), $q('rma'),
        $q('Mérő üzenet: a készülék állapotáról érdeklődés, ' . ($i % 97)), mt_rand(0, 3) === 0 ? 0 : 1, $q($datum(mt_rand(0, 1400)) . ' 14:00:00'),
    ]);
}, $N_M);

foreach ([$ugyfel_t, $eszkoz_t, $munkalap_t, $tetel_t] as $tabla) {
    $sql_fut($pdo !== null ? "ANALYZE {$tabla}" : "ANALYZE TABLE {$tabla}");
}

echo "Kész.\n";
