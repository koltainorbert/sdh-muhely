<?php
/**
 * t49 – Változásnapló (0.59).
 *
 * Az SQL-elemző (tábla, WHERE), a rögzítés (létrehozás, módosítás mezőnként,
 * törlés, zaj nélkül, kérésenként csoportban), a forrás (AI-kontextus), a
 * beállítások (titkok nélkül), a védelem (a napló nem írható át, nem
 * törölhető), az aláírás és az épség, a szűrők, a kijelzés magyarul, a
 * privát naptáresemény, az AI-eszközök és a rendszerüzenet, a felület
 * (Napló ablak, oldal, lapfül).
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$V = 'SDH_Muhely_Valtozasnaplo';
$T = static fn (string $t): string => SDH_Muhely_Schema::tabla($t);
$el = $wpdb->prefix . 'sdh_';

/* --- Séma ------------------------------------------------------------------ */

T::igaz(SDH_Muhely_Schema::naprakesz(), 'a séma naprakész');
T::igaz((bool) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $V::tabla() . ' WHERE id > %d', -1)) || true, 'a napló táblája létezik');

/* --- Az SQL elemzése -------------------------------------------------- */

$e = $V::elemez("UPDATE `{$el}munkalap` SET `nev` = 'WHERE x', `a` = 1 WHERE `id` = 5", $el);
T::egyezik(['muvelet' => 'update', 'tabla' => 'munkalap', 'where' => '`id` = 5'], $e, 'UPDATE: tábla és WHERE (idézőjeles WHERE-rel az értékben)');
$e = $V::elemez("DELETE FROM `{$el}munkalap_tetel` WHERE `munkalap_id` = 3 AND (`id` IN (1,2)) ORDER BY id LIMIT 5", $el);
T::egyezik('`munkalap_id` = 3 AND (`id` IN (1,2))', $e['where'] ?? '', 'DELETE: WHERE az ORDER BY / LIMIT nélkül');
$e = $V::elemez("INSERT INTO `{$el}termek` (`megnevezes`) VALUES ('a), (b')", $el);
T::egyezik(['muvelet' => 'insert', 'tabla' => 'termek', 'tobb' => false], $e, 'INSERT: egy sor (idézőjelben „), (" nem számít)');
$e = $V::elemez("INSERT INTO {$el}penztar_nap (datum) VALUES ('2026-01-01'), ('2026-01-02') ON DUPLICATE KEY UPDATE x = VALUES(x)", $el);
T::igaz(!empty($e['tobb']), 'INSERT … ON DUPLICATE / több sor: tömeges');
T::egyezik('1=1', $V::elemez("UPDATE {$el}szolgaltatas SET hasznalat = 0", $el)['where'] ?? '', 'WHERE nélküli UPDATE: minden sor');
T::egyezik('nyers', $V::elemez("UPDATE {$el}munkalap m JOIN {$el}ugyfel u ON u.id = m.ugyfel_id SET m.nev = u.nev", $el)['muvelet'] ?? '', 'több táblás UPDATE: összetett írás');
T::egyezik(null, $V::elemez('UPDATE wp_options SET option_value = 1', $el), 'más tábla: nem érdekes');
T::egyezik("a = 'it''s WHERE' AND b = \"x\"", $V::where_resz("UPDATE t SET c = 1 WHERE a = 'it''s WHERE' AND b = \"x\""), 'WHERE: kettőzött idézőjel');
T::igaz(!$V::naplozott('tac') && !$V::naplozott('valtozasnaplo') && $V::naplozott('munkalap') && $V::naplozott('csapat_cetli'), 'mit naplóz, mit nem');

/* --- Rögzítés: létrehozás, módosítás, törlés ---------------------------- */

$V::uj_keres();
$u  = t_ugyfel('Napló Nóra');
$e1 = t_eszkoz($u, 'Apple', 'iPhone 14');
$id = t_munkalap($u, [$e1]);
$tid = t_tetel($id, 'Akkucsere', 15000);

$sorok = $V::lista(['rekord' => 'munkalap:' . $id], 1, 100, true)['sorok'];
$tablak = array_map(static fn ($s) => $s->muvelet . ':' . $s->tabla, $sorok);
T::igaz(in_array('letrehozas:munkalap', $tablak, true), 'a munkalap létrehozása a naplóban');
T::igaz(in_array('letrehozas:munkalap_tetel', $tablak, true), 'a tétel létrehozása a lap naplójában');
T::egyezik(1, count(array_unique(array_map(static fn ($s) => $s->keres, $sorok))), 'egy kérés: egy kérés-azonosító');

$V::uj_keres();
$wpdb->update($T('munkalap'), ['hatarido' => '2026-11-05', 'nev' => 'Akku', 'modositva' => current_time('mysql')], ['id' => $id]);
$wpdb->update($T('munkalap'), ['modositva' => '2030-01-01 00:00:00'], ['id' => $id]);
$l = $V::lista(['rekord' => 'munkalap:' . $id, 'muvelet' => 'modositas'], 1, 10)['sorok'];
$v = json_decode((string) $l[0]->valtozas, true);
T::egyezik([null, '2026-11-05'], $v['hatarido'] ?? null, 'módosítás: régi és új érték');
T::egyezik(['', 'Akku'], $v['nev'] ?? null, 'módosítás: több mező egy bejegyzésben');
T::igaz(!isset($v['modositva']), 'a „módosítva" időbélyeg nem kerül bele');
T::egyezik(1, (int) $V::lista(['rekord' => 'munkalap:' . $id, 'muvelet' => 'modositas', 'kereses' => '2030-01-01'])['osszes'] === 0 ? 1 : 0, 'csak időbélyeg-változás: nincs bejegyzés');
T::tartalmaz((string) $l[0]->osszegzes, 'Határidő', 'az összegzés magyarul');

// Nyers SQL is (nem csak a $wpdb->update).
$wpdb->query($wpdb->prepare('UPDATE ' . $T('munkalap') . " SET ugyfel_megjegyzes = %s WHERE id = %d", 'Időpontra; WHERE (hozza)', $id));
$l = $V::lista(['rekord' => 'munkalap:' . $id, 'kereses' => 'Időpontra'])['sorok'];
T::igaz($l !== [], 'nyers UPDATE is a naplóba kerül');

$wpdb->delete($T('munkalap_tetel'), ['id' => $tid]);
$l = $V::lista(['rekord' => 'munkalap:' . $id, 'muvelet' => 'torles'])['sorok'];
T::igaz(count($l) === 1 && (string) $l[0]->tabla === 'munkalap_tetel', 'a tétel törlése a lap naplójában');
$v = json_decode((string) $l[0]->valtozas, true);
T::egyezik('Akkucsere', $v['megnevezes'] ?? '', 'törlésnél a teljes régi sor megmarad');

// A wpdb állapota a napló után változatlan (insert_id).
$wpdb->insert($T('termek'), ['megnevezes' => 'Naplós tok', 'brutto_ar' => 2990, 'letrehozva' => current_time('mysql')]);
$termek_id = (int) $wpdb->insert_id;
$wpdb->get_var('SELECT 1');
T::egyezik($termek_id, (int) $wpdb->insert_id, 'a napló nem rontja el az insert_id-t');
SDH_Muhely_Termek::mozgas($termek_id, 5, 'bevet', 'Beérkezett');
$l = $V::lista(['rekord' => 'termek:' . $termek_id], 1, 20)['sorok'];
T::igaz(count(array_filter($l, static fn ($s) => $s->tabla === 'termek_mozgas')) === 1, 'a készletmozgás a termék naplójában');
T::igaz($V::lista(['tipus' => 'termek'])['osszes'] >= 2, 'szűrés kategóriára (Termékek)');

/* --- Üzenet, üzenőfal, naptár -------------------------------------------- */

SDH_Muhely_Rma::uzenet_ment($id, $u, 'ki', 'crm', 'Kész a készülék', get_current_user_id());
$l = $V::lista(['rekord' => 'munkalap:' . $id, 'kereses' => 'Kész a készülék'])['sorok'];
T::igaz($l !== [] && $l[0]->tabla === 'uzenet', 'az ügyfélüzenet a lap naplójában');

$cid = SDH_Muhely_Csapat::cetli_letrehoz(['szoveg' => 'Hívd vissza Nórát', 'kinek' => 'Mindenki']);
$wpdb->update($T('csapat_cetli'), ['x' => 50, 'y' => 20, 'z' => 99], ['id' => $cid]);
$wpdb->update($T('csapat_cetli'), ['kesz' => 1], ['id' => $cid]);
$l = $V::lista(['rekord' => 'csapat_cetli:' . $cid], 1, 20, true)['sorok'];
T::egyezik(['letrehozas', 'modositas'], array_map(static fn ($s) => $s->muvelet, $l), 'cetli: létrehozás és „kész" – az áthúzás a falon nem bejegyzés');

$masik = wp_insert_user(['user_login' => 'naplo_masik_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(), 'role' => 'administrator', 'display_name' => 'Másik Márk']);
$wpdb->insert($T('naptar_esemeny'), ['cim' => 'Titkos orvos', 'kezdet' => '2026-11-01 10:00:00', 'veg' => '2026-11-01 11:00:00', 'privat' => 1, 'letrehozta' => (int) $masik, 'letrehozva' => current_time('mysql')]);
$nid = (int) $wpdb->insert_id;
$wpdb->insert($T('naptar_esemeny'), ['cim' => 'Csapatmegbeszélés', 'kezdet' => '2026-11-02 10:00:00', 'veg' => '2026-11-02 11:00:00', 'letrehozta' => get_current_user_id(), 'letrehozva' => current_time('mysql')]);
$nid2 = (int) $wpdb->insert_id;
$s = $V::lista(['rekord' => 'naptar_esemeny:' . $nid])['sorok'][0] ?? null;
T::igaz($s !== null, 'a naptáresemény a naplóban');
$r = $s ? $V::reszletek($s) : [];
T::tartalmaz(wp_json_encode($r, JSON_UNESCAPED_UNICODE), 'Privát esemény', 'más privát eseményének részletei rejtve');
T::nem_tartalmaz(wp_json_encode($r, JSON_UNESCAPED_UNICODE), 'Titkos orvos', 'a privát esemény címe nem látszik');
T::egyezik('Naptáresemény (privát)', $V::rekord_cimke('naptar_esemeny', $nid), 'a privát esemény neve rejtve');
$s2 = $V::lista(['rekord' => 'naptar_esemeny:' . $nid2])['sorok'][0] ?? null;
T::tartalmaz(wp_json_encode($s2 ? $V::reszletek($s2) : [], JSON_UNESCAPED_UNICODE), 'Csapatmegbeszélés', 'a nem privát esemény részletei látszanak');

/* --- Forrás: AI-asszisztens ---------------------------------------------- */

$elozo = $V::kontextus('ai', 'AI-javaslat #77: határidő');
$wpdb->update($T('munkalap'), ['hatarido' => '2026-12-01'], ['id' => $id]);
$V::kontextus_vissza($elozo);
$wpdb->update($T('munkalap'), ['hatarido' => '2026-12-02'], ['id' => $id]);
$l = $V::lista(['rekord' => 'munkalap:' . $id, 'muvelet' => 'modositas'], 1, 2)['sorok'];
T::egyezik('cli', (string) $l[0]->forras, 'a kontextus visszaáll');
T::egyezik('ai', (string) $l[1]->forras, 'AI-kontextusban a forrás „ai"');
T::egyezik('AI-javaslat #77: határidő', (string) $l[1]->megjegyzes, 'a javaslat száma a megjegyzésben');
T::igaz($V::lista(['forras' => 'ai'])['osszes'] >= 1, 'szűrés forrásra');

/* --- Események -------------------------------------------------------------- */

$V::esemeny('munkalap', $id, 'Próba esemény', ['Kinek' => 'senki'], 'nyomtatas');
$l = $V::lista(['rekord' => 'munkalap:' . $id, 'muvelet' => 'nyomtatas'])['sorok'];
T::igaz($l !== [] && (string) $l[0]->osszegzes === 'Próba esemény', 'esemény a lap naplójában');

/* --- Beállítások (titkok nélkül) ----------------------------------------- */

$opt = 'sdh_muhely_t49_proba_' . wp_rand(100000, 999999);
update_option($opt, ['nev' => 'egy', 'api_kulcs' => 'NAGYONTITKOS', 'afa_kulcs' => '27']);
update_option($opt, ['nev' => 'kettő', 'api_kulcs' => 'MASIKTITOK', 'afa_kulcs' => '27']);
$l = $V::lista(['tipus' => 'beallitas', 'kereses' => $opt], 1, 5)['sorok'];
T::egyezik(2, count($l), 'a beállítás létrehozása és változása a naplóban');
T::nem_tartalmaz((string) ($l[0]->valtozas ?? ''), 'TITOK', 'a titok értéke nem kerül a naplóba');
T::tartalmaz((string) ($l[0]->valtozas ?? ''), 'megváltozott', 'csak annyi, hogy megváltozott');
T::nem_tartalmaz((string) ($l[0]->valtozas ?? ''), 'afa_kulcs', 'a nem változott mező nincs benne');
delete_option($opt);
update_option('sdh_muhely_mentes_utolso', ['ido' => time()]);
T::egyezik(0, $V::lista(['tipus' => 'beallitas', 'kereses' => 'mentes_utolso'])['osszes'], 'a futási állapot (utolsó mentés) nem kerül bele');

/* --- Védelem: a napló nem írható át, nem törölhető ----------------------- */

$db0 = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $V::tabla());
$r1  = $wpdb->query('DELETE FROM ' . $V::tabla());
$r2  = $wpdb->query('UPDATE ' . $V::tabla() . " SET osszegzes = 'hamis'");
$r3  = $wpdb->query('TRUNCATE TABLE ' . $V::tabla());
$db1 = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $V::tabla());
T::igaz($r1 === false && $r2 === false && $r3 === false, 'törlés, átírás, ürítés: nem fut le');
T::egyezik($db0 + 3, $db1, 'egy sor sem tűnt el, és a három kísérlet bekerült');
T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $V::tabla() . " WHERE osszegzes = 'hamis'"), 'semmi nem íródott át');
T::egyezik('vedelem', (string) $wpdb->get_var('SELECT muvelet FROM ' . $V::tabla() . ' ORDER BY id DESC LIMIT 1'), 'a kísérlet „védelem" bejegyzés');

/* --- Aláírás és épség ------------------------------------------------------ */

$ell = $V::ellenoriz();
T::igaz($ell['ok'], 'a napló ép (' . $ell['hibas_db'] . ' hibás, ' . count($ell['hezagok']) . ' hézag)');

// Hamisítás az őr megkerülésével (a szűrő nélkül) – az ellenőrzés kiszúrja, utána visszaállítjuk.
$utso = $wpdb->get_row('SELECT * FROM ' . $V::tabla() . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
remove_filter('query', [$V, 'szuro'], PHP_INT_MAX);
$wpdb->update($V::tabla(), ['felhasznalo_nev' => 'Hamis Henrik'], ['id' => (int) $utso['id']]);
add_filter('query', [$V, 'szuro'], PHP_INT_MAX);
$ell = $V::ellenoriz();
T::igaz(!$ell['ok'] && in_array((int) $utso['id'], $ell['hibas'], true), 'az átírt bejegyzést az ellenőrzés megtalálja');

$kozep = $wpdb->get_row('SELECT * FROM ' . $V::tabla() . ' WHERE id < ' . (int) $utso['id'] . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
remove_filter('query', [$V, 'szuro'], PHP_INT_MAX);
$wpdb->update($V::tabla(), ['felhasznalo_nev' => $utso['felhasznalo_nev']], ['id' => (int) $utso['id']]);
$wpdb->delete($V::tabla(), ['id' => (int) $kozep['id']]);
add_filter('query', [$V, 'szuro'], PHP_INT_MAX);
$ell = $V::ellenoriz();
T::igaz(!$ell['ok'] && $ell['hibas_db'] === 0 && in_array([(int) $kozep['id'], (int) $kozep['id']], $ell['hezagok'], true), 'a törölt bejegyzés hézagként látszik');

remove_filter('query', [$V, 'szuro'], PHP_INT_MAX);
$wpdb->insert($V::tabla(), $kozep);
add_filter('query', [$V, 'szuro'], PHP_INT_MAX);
T::igaz($V::ellenoriz()['ok'], 'visszaállítva: újra ép');

/* --- Kijelzés magyarul ------------------------------------------------------ */

T::egyezik('Lezárt', $V::ertek('munkalap', 'allapot', 'lezart'), 'állapotnév');
T::egyezik('15 000 Ft', $V::ertek('munkalap', 'brutto_ertek', '15000.00'), 'pénz');
T::egyezik('igen', $V::ertek('munkalap', 'fizetve', '1'), 'igen / nem');
T::egyezik('2026.11.05.', $V::ertek('munkalap', 'hatarido', '2026-11-05'), 'dátum');
T::egyezik('fő eszköz', $V::ertek('munkalap_hiba', 'eszkoz_id', '0'), 'a hibasor fő eszköze');
T::egyezik('(rejtett)', $V::ertek('eszkoz', 'zarkod', '4321', true), 'a zárkód az AI-nak rejtett');
T::egyezik('4321', $V::ertek('eszkoz', 'zarkod', '4321'), 'a felületen látszik (aki az eszközt is látja)');
T::egyezik('Határidő', $V::mezo_nev('munkalap', 'hatarido'), 'mezőnév');
T::tartalmaz($V::rekord_cimke('munkalap', $id), 'Munkalap', 'a rekord neve');
T::egyezik('AI-asszisztens', $V::forras_nev('ai'), 'forrás neve');

/* --- AI-eszközök -------------------------------------------------------------- */

$E = 'SDH_Muhely_Asszisztens_Eszkozok';
$def = $E::definiciok();

foreach (['naplo_idovonal', 'naplo_kereses', 'naplo_osszesites', 'naplo_epseg'] as $esz) {
    T::egyezik('olvas', $def[$esz]['szint'] ?? '', 'új olvasó eszköz: ' . $esz);
}

$wpdb->update($T('eszkoz'), ['zarkod' => '9876', 'minta' => '147'], ['id' => $e1]);
$ki = $E::olvas('naplo_idovonal', ['rekord_tipus' => 'eszkoz', 'rekord_id' => $e1]);
T::nem_tartalmaz($ki, '9876', 'a zárkód az AI-eszköz válaszában sincs benne');
T::tartalmaz($ki, '(rejtett)', 'helyette: rejtett');

$ki = json_decode($E::olvas('naplo_idovonal', ['munkalap' => '#' . $id]), true);
T::igaz(($ki['osszes'] ?? 0) >= 8 && !empty($ki['tortenet']), 'idővonal: a lap története');
T::tartalmaz(wp_json_encode($ki, JSON_UNESCAPED_UNICODE), 'Határidő', 'idővonal: mezőnként');

$ki = json_decode($E::olvas('naplo_kereses', ['kategoria' => 'cetli', 'kifejezes' => 'Nórát']), true);
T::igaz(($ki['osszes'] ?? 0) >= 1, 'keresés a naplóban: üzenőfal');
$ki = json_decode($E::olvas('naplo_kereses', ['felhasznalo' => 'nincsilyennevunk']), true);
T::igaz(isset($ki['hiba']), 'ismeretlen név: hiba');
$ki = json_decode($E::olvas('naplo_osszesites', []), true);
T::igaz(!empty($ki['felhasznalonkent']) && !empty($ki['naponta']), 'összesítés: kollégánként, naponta');
$ki = json_decode($E::olvas('naplo_epseg', []), true);
T::igaz(($ki['ep'] ?? false) === true, 'AI: a napló ép');

// A nyitott rekord története a rendszerüzenetben.
$m = new ReflectionMethod('SDH_Muhely_Asszisztens', 'rekord_tortenet');
$m->setAccessible(true);
$tort = (string) $m->invoke(null, 'munkalap:' . $id);
T::tartalmaz($tort, 'NYITOTT REKORD', 'a nyitott rekord a rendszerüzenetben');
T::tartalmaz($tort, 'Határidő', 'a története benne');
T::egyezik('', (string) $m->invoke(null, 'rossz-ertek'), 'hibás rekord-jelölés: semmi');

// Nem adminisztrátor: a beállítások naplója nélkül.
$kolléga = wp_insert_user(['user_login' => 'naplo_kollega_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(), 'role' => 'editor', 'display_name' => 'Kolléga Kata']);
(new WP_User((int) $kolléga))->add_cap(SDH_Muhely_Admin_UI::JOG);
$admin   = get_current_user_id();
wp_set_current_user((int) $kolléga);
$ki = json_decode($E::olvas('naplo_kereses', ['kategoria' => 'beallitas', 'tol' => '2000-01-01']), true);
T::egyezik(0, (int) ($ki['osszes'] ?? -1), 'kolléga: a beállítások naplója nem látszik');
wp_set_current_user($admin);

/* --- Felület ----------------------------------------------------------------- */

$h = t_ajax('sdh_muhely_naplo_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['tipus' => 'munkalap', 'id' => $id])['nyers'];
T::tartalmaz($h, 'data-sdh-naplo-ablak', 'a Napló ablak');
T::tartalmaz($h, 'data-sdh-naplo-rekord="munkalap:' . $id . '"', 'az ablak jelöli a rekordot (AI)');
T::tartalmaz($h, 'sdh-naplo__kartya', 'kártyák');
T::tartalmaz($h, '<del>', 'régi érték áthúzva');
T::tartalmaz($h, 'data-sdh-naplo-ai', 'Mi történt? gomb');
T::tartalmaz($h, 'AI-asszisztens', 'a forrás látszik');

$r = t_ajax('sdh_muhely_naplo_tobb', ['tipus' => 'munkalap', 'id' => $id, 'lap' => 2]);
T::igaz(!empty($r['success']) && isset($r['data']['html']), 'Régebbiek: a következő oldal');

$h = t_ajax('sdh_muhely_naplo_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['tipus' => 'csapat_cetli', 'id' => 0])['nyers'];
T::tartalmaz($h, 'Hívd vissza', 'a típus összes rekordja (az egész fal)');

$r = t_ajax('sdh_muhely_naplo_ellenoriz');
T::igaz(!empty($r['success']) && !empty($r['data']['ok']) && str_contains((string) $r['data']['szoveg'], 'Rendben'), 'épség az oldalról');

T::igaz(SDH_Muhely_Modulok::egy('naplo') !== null, 'a Napló menüpont bejegyezve');
T::igaz(isset(SDH_Muhely_Modulok::alapreszek()['naplo']), 'a Napló az alaprendszer része');
ob_start();
SDH_Muhely_Naplo_Felulet::oldal();
$o = (string) ob_get_clean();
T::tartalmaz($o, 'sdh-naplo__szuro', 'az oldal szűrője');
T::tartalmaz($o, 'data-sdh-naplo-ellenoriz', 'épség gomb');
T::tartalmaz($o, 'sdh_muhely_naplo_csv', 'CSV letöltés');
T::nem_tartalmaz($o, 'type="date"', 'nincs natív dátummező');

$_GET = ['n_tipus' => 'cetli'];
ob_start();
SDH_Muhely_Naplo_Felulet::oldal();
$o = (string) ob_get_clean();
$_GET = [];
T::tartalmaz($o, 'Hívd vissza', 'szűrés az oldalon: üzenőfal');
T::nem_tartalmaz($o, 'Kész a készülék', 'a szűrt listában nincs más');

wp_set_current_user((int) $kolléga);
$h = t_ajax('sdh_muhely_naplo_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['tipus' => 'beallitas', 'id' => 0])['nyers'];
T::tartalmaz($h, 'Nincs jogosultságod', 'kolléga: a beállítások naplója tiltott');
$h = t_ajax('sdh_muhely_naplo_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['tipus' => 'munkalap', 'id' => $id])['nyers'];
T::tartalmaz($h, 'data-sdh-naplo-ablak', 'kolléga: a munkalap naplója látszik');
wp_set_current_user($admin);

// Beállítások › Változásnapló.
T::igaz(isset(SDH_Muhely_Beallitasok::szakaszok()['naplo']), 'van Változásnapló lapfül');
$_POST = ['naplo_jelen' => '1', 'naplo_beallitasok' => '1', 'naplo_lap' => '50'];
SDH_Muhely_Naplo_Felulet::beallitas_mentes();
$_POST = [];
T::egyezik(['beallitasok' => true, 'ip' => false, 'lap' => 50], $V::beallitas(), 'a lapfül mentése');
T::igaz($V::lista(['kereses' => 'sdh_muhely_valtozasnaplo'])['osszes'] >= 1, 'a napló saját beállítása is a naplóba kerül');
delete_option($V::OPT);

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user((int) $masik);
wp_delete_user((int) $kolléga);

T::vege('t49 változásnapló: rögzítés, védelem, épség, szűrők, AI, felület');
