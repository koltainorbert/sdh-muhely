<?php
/**
 * t72 – 0.79.0: H6 – a feloldó kód (zárkód) és a feloldó minta titkosítva.
 *
 * - Tárolás `sdh1:` alakban; a régi nyílt értékek átállása csendben (napló nélkül).
 * - Mentés: új érték titkosítva; érintetlen (üres + marad) mező nem változik; ugyanaz az érték
 *   nem ad új titkos alakot; jog nélkül is felülírható; törlés.
 * - Űrlap: a kód nem kerül az oldalba; Megmutat csak jogosultnak; felfedés naplózva, jog nélkül elutasítva.
 * - Jog: `zarkod` (Admin, Kolléga, Szerelő alapból; régi mentett szerepek átállítása).
 * - Napló, nyomtatvány, matrica, IMEI-találat, hordozható mentés.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/user.php';

$Z     = 'SDH_Muhely_Zarkod';
$Sz    = 'SDH_Muhely_Szerepkor';
$tabla = SDH_Muhely_Schema::tabla('eszkoz');
$naplo = SDH_Muhely_Valtozasnaplo::tabla();
$admin = get_current_user_id();

$felh = static function (string $nev, string $szerep): int {
    $id = wp_insert_user(['user_login' => $nev . '_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => $szerep, 'display_name' => $nev]);

    return is_int($id) ? $id : 0;
};
$sor   = static fn (int $id): object => $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tabla} WHERE id = %d", $id)); // phpcs:ignore
$napl  = static function (int $id) use ($wpdb, $naplo): array {
    $wpdb->query('SELECT 1');

    return (array) $wpdb->get_col($wpdb->prepare("SELECT osszegzes FROM {$naplo} WHERE fo_tipus = 'eszkoz' AND fo_id = %d ORDER BY id", $id)); // phpcs:ignore
};

/* --- 1. Séma, titkosítás ------------------------------------------------------------ */

T::egyezik('0.44.0', SDH_Muhely_Schema::DB_VERSION, 'sémaverzió 0.44.0');
T::igaz(SDH_Muhely_Schema::naprakesz(), 'a séma naprakész');
$t = $Z::tarol('1234');
T::igaz(SDH_Muhely_Titok::titkos_e($t), 'a zárkód titkos alakban (sdh1:)');
T::egyezik('1234', $Z::olvas($t), 'és visszafejthető');
T::egyezik('', $Z::tarol(''), 'üres marad üres');
T::igaz(strlen($Z::tarol(str_repeat('x', 60))) <= 255, 'a leghosszabb kód titkos alakja is elfér (255)');

/* --- 2. Átállás: a régi nyílt értékek csendben titkosítva ---------------------------- */

$u  = t_ugyfel('Zárkód Teszt T72');
$e1 = t_eszkoz($u, 'Samsung', 'SM-T72A');
SDH_Muhely_Valtozasnaplo::csendben(static fn () => $wpdb->update($tabla, ['zarkod' => '4321', 'minta' => '14789'], ['id' => $e1]));
$elotte = count($napl($e1));
$Z::atallas_ujra();
T::igaz($Z::nyilt_sorok() >= 1, 'van átállásra váró sor');
T::igaz($Z::atallas_most() >= 1, 'az átállás titkosít');
T::egyezik(0, $Z::nyilt_sorok(), 'nem maradt nyílt zárkód / minta');
$s1 = $sor($e1);
T::igaz(SDH_Muhely_Titok::titkos_e((string) $s1->zarkod) && SDH_Muhely_Titok::titkos_e((string) $s1->minta), 'a kód és a minta is titkos alakban');
T::egyezik(['zarkod' => '4321', 'minta' => '14789'], $Z::nyilt($s1), 'az adminnak olvasható');
T::egyezik($elotte, count($napl($e1)), 'az átállás nem ír a naplóba');

/* --- 3. Mentés -------------------------------------------------------------------------- */

$m = $Z::mentendo($s1, ['zarkod' => '', 'minta' => ''], ['zarkod' => true, 'minta' => true]);
T::egyezik([], $m, 'érintetlen űrlap (üres + marad): semmi nem változik');
$m = $Z::mentendo($s1, ['zarkod' => '4321', 'minta' => '14789'], ['zarkod' => false, 'minta' => false]);
T::egyezik([], $m, 'ugyanaz az érték (felfedés után): a titkos alak marad');
$m = $Z::mentendo($s1, ['zarkod' => '9999', 'minta' => ''], ['zarkod' => true, 'minta' => true]);
T::egyezik(['zarkod'], array_keys($m), 'új kód: csak a kód íródik');
T::egyezik('9999', $Z::olvas($m['zarkod']), 'titkosítva');
$m = $Z::mentendo($s1, ['zarkod' => '', 'minta' => ''], ['zarkod' => false, 'minta' => false]);
T::egyezik(['zarkod' => '', 'minta' => ''], $m, 'felfedés után kiürítve: törlés');
T::igaz(SDH_Muhely_Titok::titkos_e($Z::mentendo(null, ['zarkod' => '55', 'minta' => '123'], [])['minta']), 'új eszköz: titkosítva');

$ment = static function (int $id, array $tovabbi) use ($u): array {
    return t_ajax('sdh_muhely_eszkozok_ment', $tovabbi + ['id' => $id, 'ugyfel_id' => $u, 'kategoria' => 'telefon', 'gyarto' => 'Samsung', 'tipus' => 'SM-T72A'], 'sdh_nonce', 'sdh_muhely_eszkoz_mentes');
};
$r = $ment(0, ['zarkod' => '2468', 'minta' => '1478']);
T::igaz(!empty($r['success']), 'új eszköz mentése (AJAX)');
$e2 = (int) ($r['data']['id'] ?? 0);
$s2 = $sor($e2);
T::igaz(SDH_Muhely_Titok::titkos_e((string) $s2->zarkod) && SDH_Muhely_Titok::titkos_e((string) $s2->minta), 'az új eszköz kódja és mintája titkosítva kerül az adatbázisba');
T::egyezik(['zarkod' => '2468', 'minta' => '1478'], $Z::nyilt($s2), 'és helyesen olvasható');
$ment($e2, ['zarkod' => '', 'zarkod_marad' => '1', 'minta' => '', 'minta_marad' => '1', 'szin' => 'kék']);
$s2b = $sor($e2);
T::egyezik([(string) $s2->zarkod, (string) $s2->minta], [(string) $s2b->zarkod, (string) $s2b->minta], 'más mező mentése a kódot és a mintát nem változtatja (a titkos alak sem)');
T::egyezik('kék', (string) $s2b->szin, 'a többi mező mentődött');
$nap = implode("\n", $napl($e2));
T::nem_tartalmaz($nap, '2468', 'a napló összegzésében nincs nyílt kód');
T::nem_tartalmaz($nap, 'sdh1:', 'és titkos alak sem');

/* --- 4. Jog: alapok és régi mentett szerepek ----------------------------------------- */

T::igaz(isset($Sz::jogok()[$Z::JOG]), 'új jog: Feloldó kód és minta megnézése');
$alap = $Sz::alapok();
T::igaz(in_array('zarkod', $alap['kollega']['jogok'], true) && in_array('zarkod', $alap['szerelo']['jogok'], true), 'alapból a Kolléga és a Szerelő láthatja');
T::igaz(!in_array('zarkod', $alap['pultos']['jogok'], true) && !in_array('zarkod', $alap['penztaros']['jogok'], true), 'a Pultos és a Pénztáros nem');

$regi_opt = get_option($Sz::OPT, null);
update_option($Sz::OPT, [
    'kollega' => ['nev' => 'Kolléga', 'tiltott' => [], 'jogok' => ['penztar_riport', 'szamla']],
    'pultos'  => ['nev' => 'Pultos', 'tiltott' => [], 'jogok' => []],
    'sajat'   => ['nev' => 'Saját', 'tiltott' => [], 'jogok' => []],
]);
$Sz::felejt();
$sz = $Sz::szerepek();
T::igaz(in_array('zarkod', $sz['kollega']['jogok'], true), 'frissítés után a régi mentett Kolléga megkapja az új jogot');
T::igaz(!in_array('zarkod', $sz['pultos']['jogok'], true), 'a régi Pultos nem');
T::igaz(in_array('zarkod', $sz['sajat']['jogok'], true), 'a saját szerep a Kolléga alapját követi');
update_option($Sz::OPT, ['kollega' => ['nev' => 'Kolléga', 'tiltott' => [], 'jogok' => [], 'ismert' => array_keys($Sz::jogok())]]);
$Sz::felejt();
T::igaz(!in_array('zarkod', $Sz::szerepek()['kollega']['jogok'], true), 'az új formátumban levett jogot nem adja vissza');
$regi_opt === null ? delete_option($Sz::OPT) : update_option($Sz::OPT, $regi_opt);
$Sz::felejt();

/* --- 5. Űrlap, Megmutat, napló --------------------------------------------------------- */

$urlap = t_ajax('sdh_muhely_eszkozok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $e2])['nyers'];
T::nem_tartalmaz($urlap, '2468', 'az űrlap nem tartalmazza a mentett kódot');
T::nem_tartalmaz($urlap, 'sdh1:', 'és a titkos alakot sem');
T::tartalmaz($urlap, 'data-sdh-zarkod-megmutat="' . $e2 . '"', 'az adminnak van Megmutat gombja');
T::tartalmaz($urlap, 'name="zarkod_marad" value="1"', 'a kód „érintetlen” jelzője');
T::tartalmaz($urlap, 'data-sdh-minta-rejtett', 'a mintarács jelzi a rejtett, mentett mintát');

$r = t_ajax('sdh_muhely_zarkod_megmutat', ['id' => $e2]);
T::egyezik(['2468', '1478', '1-4-7-8'], [$r['data']['zarkod'] ?? '', $r['data']['minta'] ?? '', $r['data']['minta_kiiras'] ?? ''], 'Megmutat: a kód és a minta');
T::tartalmaz(implode("\n", $napl($e2)), 'Feloldó kód / minta megnézve', 'a megnézés a naplóba kerül');

$pultos = $felh('Pultos T72', SDH_Muhely_Csapat::SZEREP);
$Sz::szerep_beallit($pultos, 'pultos');
wp_set_current_user($pultos);
SDH_Muhely_Szemely::felejt();
$Sz::felejt();
T::egyezik(false, $Z::lathat(), 'a pultos nem láthatja');
$r = t_ajax('sdh_muhely_zarkod_megmutat', ['id' => $e2]);
T::igaz(empty($r['success']) && strpos((string) ($r['data']['uzenet'] ?? ''), 'Jogosultságok') !== false, 'a pultos Megmutat-kérését a szerver elutasítja');
T::tartalmaz(implode("\n", $napl($e2)), 'elutasítva', 'az elutasítás is naplózva');
$urlap = t_ajax('sdh_muhely_eszkozok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $e2])['nyers'];
T::nem_tartalmaz($urlap, 'data-sdh-zarkod-megmutat', 'a pultos űrlapján nincs Megmutat gomb');
T::tartalmaz($urlap, 'a szereped nem láthatja', 'helyette megmondja, miért');
T::egyezik(['zarkod' => '', 'minta' => ''], $Z::nyilt($sor($e2)), 'a pultosnak a nyílt érték üres');
T::egyezik('(rejtett)', SDH_Muhely_Valtozasnaplo::ertek('eszkoz', 'zarkod', (string) $sor($e2)->zarkod), 'a naplóban rejtett (titkos alak)');
T::egyezik('(rejtett)', SDH_Muhely_Valtozasnaplo::ertek('eszkoz', 'zarkod', '1111'), 'és a régi, nyílt bejegyzés is');
$mat = array_values(SDH_Muhely_Matrica::adatok(t_munkalap($u, [$e2])))[0] ?? [];
T::egyezik(['', ''], [(string) ($mat['kod'] ?? 'x'), (string) ($mat['minta'] ?? 'x')], 'a pultos matricájára nem kerül kód és minta');
$ment($e2, ['zarkod' => '7777', 'zarkod_marad' => '1', 'minta' => '', 'minta_marad' => '1']);
wp_set_current_user($admin);
SDH_Muhely_Szemely::felejt();
$Sz::felejt();
T::egyezik('7777', $Z::nyilt($sor($e2))['zarkod'], 'a pultos felülírhatja a kódot (beírni jog nélkül is lehet)');
T::egyezik('1478', $Z::nyilt($sor($e2))['minta'], 'a minta közben megmaradt');
T::egyezik('7777', SDH_Muhely_Valtozasnaplo::ertek('eszkoz', 'zarkod', (string) $sor($e2)->zarkod), 'az adminnak a naplóban visszafejtve látszik');
$mat = array_values(SDH_Muhely_Matrica::adatok(t_munkalap($u, [$e2])))[0] ?? [];
T::egyezik(['7777', '1478'], [(string) ($mat['kod'] ?? ''), (string) ($mat['minta'] ?? '')], 'az admin matricáján ott a kód és a minta');

/* --- 6. IMEI-találat, hordozható mentés ------------------------------------------------- */

$wpdb->update($tabla, ['imei' => '356789012345672'], ['id' => $e2]);
$imei = t_ajax('sdh_muhely_eszkozok_imei', [], '_wpnonce', 'sdh_muhely_modal', ['imei' => '356789012345672', 'kizar' => '0']);
$mz   = (array) ($imei['data']['mezok'] ?? []);
T::igaz(!empty($imei['data']['talalat']) && !isset($mz['zarkod']) && ($mz['zarkod_marad'] ?? '') === '1', 'IMEI-találat: a kód nem megy ki, a mentéskor marad');

$dir = SDH_Muhely_Asszisztens_Tudas::mentes_mappa();
$be  = $dir . 'sdh-mentes-t72-be.jsonl.gz';
$ki  = $dir . 'sdh-mentes-t72-ki.jsonl.gz';
$gz  = gzopen($be, 'wb');
gzwrite($gz, wp_json_encode(['t' => 'eszkoz', 's' => ['id' => 1, 'zarkod' => $Z::tarol('3141'), 'minta' => '']]) . "\n");
gzclose($gz);
T::egyezik(null, SDH_Muhely_Mentes_Vissza::hordozhato($be, $ki), 'hordozható csomag készül');
$tart = implode('', gzfile($ki));
T::tartalmaz($tart, 'sdh-nyilt:3141', 'a hordozható csomag a zárkódot is áthozható alakban viszi');
@unlink($be); // phpcs:ignore
@unlink($ki); // phpcs:ignore

// Rendrakás.
wp_delete_user($pultos);

T::vege('t72 zárkód / minta titkosítva (H6)');
