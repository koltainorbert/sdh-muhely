<?php
/**
 * t294 – pillanatkép és visszaállítás: tömörített fájl védett mappában, a műhely minden táblája + beállításai + a
 * munkatársak; visszaállítás után ugyanaz az adat (ellenőrzőösszeg), a látogatói fiókok, rekordok, üzenetek, keret
 * és fájlok eltűnnek; éjjeli ütemezés 03:00 Europe/Budapest (nyári és téli időben is).
 */
require __DIR__ . '/_t29-seged.php';

global $wpdb;

$t = static fn (string $n): string => SDH_Muhely_Schema::tabla($n);

SDH_Demo_Generator::general(1000);

$osszeg = static function () use ($wpdb, $t): string {
    $h = '';

    foreach (['ugyfel', 'eszkoz', 'munkalap', 'munkalap_tetel', 'termek', 'penztar', 'naptar_esemeny', 'csatolmany'] as $tabla) {
        $h .= md5((string) wp_json_encode($wpdb->get_results('SELECT * FROM ' . $t($tabla) . ' ORDER BY id', ARRAY_N)));
    }

    return md5($h);
};

$elotte = $osszeg();
$opcio  = get_option('sdh_muhely_arculat');
$p      = SDH_Demo_Pillanatkep::keszit();

T::igaz(is_file($p['fajl']) && $p['tablak'] >= 40 && $p['sorok'] > 1500, 'pillanatkép: ' . $p['tablak'] . ' tábla, ' . $p['sorok'] . ' sor');
T::egyezik("\x1f\x8b", (string) file_get_contents($p['fajl'], false, null, 0, 2), 'gzip-tömörítve');
T::igaz($p['meret'] < 2 * 1024 * 1024, 'méret ésszerű (' . round($p['meret'] / 1024) . ' KB)');
$mappa = dirname($p['fajl']);
T::igaz(is_file($mappa . '/.htaccess') && strpos((string) file_get_contents($mappa . '/.htaccess'), 'Require all denied') !== false, 'védett mappa (.htaccess)');
T::igaz(is_file($mappa . '/index.php') && is_file($mappa . '/web.config'), 'index.php + web.config');
T::egyezik('0600', substr(sprintf('%o', fileperms($p['fajl'])), -4), 'a fájl csak a szervernek olvasható');
T::igaz(strpos($mappa, (string) wp_upload_dir()['basedir']) === false, 'nem a feltöltési mappában');
T::igaz(SDH_Demo_Pillanatkep::van(), 'van pillanatkép');
T::igaz((int) (SDH_Demo::allapot()['maxok']['ugyfel'] ?? 0) > 0, 'a legnagyobb azonosítók feljegyezve');

// Látogatói változások.
$latogato = t_latogato('198.51.100.40');
t_ugyfel('Látogató ügyfele');
$wpdb->update($t('ugyfel'), ['nev' => 'Átírt Név'], ['id' => (int) $wpdb->get_var("SELECT MIN(id) FROM {$t('ugyfel')}")]);
$wpdb->query("DELETE FROM {$t('eszkoz')} WHERE id IN (SELECT id FROM {$t('eszkoz')} ORDER BY id LIMIT 5)");
wp_mail('valaki@pelda.hu', 'Teszt', 'Szöveg');
t_admin();
update_option('sdh_muhely_arculat', ['termeknev' => 'Átnevezett']);
$fajl_ut = trailingslashit(wp_upload_dir()['basedir']) . 'sdh-muhely/csatolmany/' . str_repeat('ab', 16);
wp_mkdir_p(dirname($fajl_ut));
file_put_contents($fajl_ut, 'latogatoi-feltoltes');
$wpdb->query('CREATE TABLE IF NOT EXISTS ' . $t('uj_tabla_teszt') . ' (id INTEGER PRIMARY KEY, x TEXT)');
$wpdb->insert($t('uj_tabla_teszt'), ['x' => 'maradna']);
T::igaz($osszeg() !== $elotte, 'az adat megváltozott');

// Visszaállítás.
$v = SDH_Demo_Pillanatkep::visszaallit();
T::egyezik($elotte, $osszeg(), 'visszaállítás után ugyanaz az adat (ellenőrzőösszeg)');
T::egyezik($opcio, get_option('sdh_muhely_arculat'), 'a műhely beállításai visszaálltak');
T::igaz(get_userdata($latogato) === false, 'a látogatói fiók törölve');
T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Demo::tabla('kimeno')), 'a kimenő üzenetek ürítve');
T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Demo::tabla('naplo') . " WHERE tipus IN ('rekord','iras')"), 'a keret-napló ürítve');
T::igaz(!is_file($fajl_ut), 'a látogatói fájl törölve');
T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $t('uj_tabla_teszt')), 'a pillanatkép óta jött tábla üres');
$wpdb->query('DROP TABLE ' . $t('uj_tabla_teszt'));
T::egyezik(4, count(get_users(['login__in' => ['munkatars-bence', 'munkatars-reka', 'munkatars-adam', 'munkatars-eszter']])), 'a munkatársak megvannak');
T::igaz($v['sorok'] === $p['sorok'] && $v['tablak'] === $p['tablak'], 'minden sor visszakerült');
T::igaz(isset(SDH_Demo::allapot()['visszaallitas']['mp']), 'a visszaállítás ideje feljegyezve');

// Kétszeri visszaállítás is ugyanaz (idempotens).
SDH_Demo_Pillanatkep::visszaallit();
T::egyezik($elotte, $osszeg(), 'ismételt visszaállítás: ugyanaz');

// Éjjel 03:00 (Europe/Budapest), nyári és téli időben.
$tz = new DateTimeZone('Europe/Budapest');
$nyar = SDH_Demo::kovetkezo_hajnal((new DateTimeImmutable('2026-07-10 14:00:00', $tz))->getTimestamp());
$tel  = SDH_Demo::kovetkezo_hajnal((new DateTimeImmutable('2026-12-10 02:30:00', $tz))->getTimestamp());
T::egyezik('2026-07-11 03:00', (new DateTimeImmutable('@' . $nyar))->setTimezone($tz)->format('Y-m-d H:i'), 'nyáron: másnap 03:00');
T::egyezik('2026-12-10 03:00', (new DateTimeImmutable('@' . $tel))->setTimezone($tz)->format('Y-m-d H:i'), 'télen 02:30-kor: aznap 03:00');
T::egyezik('2026-10-25 03:00', (new DateTimeImmutable('@' . SDH_Demo::kovetkezo_hajnal((new DateTimeImmutable('2026-10-24 23:00:00', $tz))->getTimestamp())))->setTimezone($tz)->format('Y-m-d H:i'), 'óraátállítás éjszakáján is 03:00');

// Az éjjeli feladat: visszaállít és újraütemez.
$latogato2 = t_latogato('198.51.100.41');
t_admin();
wp_clear_scheduled_hook(SDH_Demo::CRON_EJJEL);
do_action(SDH_Demo::CRON_EJJEL);
T::igaz(get_userdata($latogato2) === false, 'éjjeli feladat: visszaállított');
$kov = (int) wp_next_scheduled(SDH_Demo::CRON_EJJEL);
T::egyezik('03:00', (new DateTimeImmutable('@' . $kov))->setTimezone($tz)->format('H:i'), 'a következő éjszaka ütemezve 03:00-ra');

// A tesztkörnyezetben a pillanatkép-fájl ne maradjon meg (az adatbázis visszaáll).
@unlink((string) (SDH_Demo::allapot()['pillanatkep']['fajl'] ?? ''));

T::vege(sprintf('t294 bemutató-pillanatkép (1000: készít %.2f mp, visszaállít %.2f mp)', $p['mp'], $v['mp']));
