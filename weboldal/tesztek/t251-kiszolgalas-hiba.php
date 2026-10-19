<?php
/**
 * t251 – kiszolgálási lánc hibái: hamis Számlázz.hu-hiba → újrapróba 1 / 5 / 15 / 60 / 240 perc várakozással, a levél
 * legfeljebb 3 számlapróbáig vár, 8 próba után `hiba` + levél a forgalmazónak, kézi újraindítás (REST); kikapcsolt
 * automatikus számlázás → adminra vár; nem bekötött Számlázz → kihagyva; elérhetetlen Központ → a lánc megáll, később
 * folytatódik; végleges Központ-hiba; levélküldési hiba → újrapróba; a sikeres lépés nem fut újra.
 */
require __DIR__ . '/_t25-seged.php';

global $wpdb;

/* --- Hamis Számlázz.hu: tesztkulcs, hibás válasz ------------------------------------------------------------------ */

SDH_Sales::titkos_ment('szamlazz_teszt_kulcs', 'tesztkulcs-t251');
$GLOBALS['t251_agent'] = 0;
$GLOBALS['t251_jo']    = false;
t_http(static function (string $url, array $args): ?array {
    if (strpos($url, 'szamlazz.hu/szamla') === false) {
        return null;
    }

    $GLOBALS['t251_agent']++;

    if (!$GLOBALS['t251_jo']) {
        return t_valasz(200, '<?xml version="1.0" encoding="UTF-8"?><xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz"><sikeres>false</sikeres><hibakod>1</hibakod><hibauzenet>Rendszerhiba (t251)</hibauzenet></xmlszamlavalasz>');
    }

    $pdf = SDH_Sales_Szamlazz::pdf_ir([['Szamla T251', 12]]);
    $x   = '<?xml version="1.0" encoding="UTF-8"?><xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz"><sikeres>true</sikeres><szamlaszam>E-T251-1</szamlaszam><szamlabrutto>1</szamlabrutto><pdf>' . base64_encode($pdf) . '</pdf></xmlszamlavalasz>';

    return t_valasz(200, $x, ['szlahu_szamlaszam' => 'E-T251-1']);
});
T::egyezik('teszt', SDH_Sales_Szamlazz::mod(), 'Számlázz: tesztüzem kulccsal');

$most = time();
$id   = t25_rendeles();
t25_fizet($id, false);
SDH_Sales_Kiszolgalas::indit($id, $most);

$sz = t25_lepes($id, 'szamla');
T::egyezik('ok', (string) t25_lepes($id, 'kozpont')['allapot'], 'a licenc elkészült');
T::igaz($sz['allapot'] === 'var' && (int) $sz['probalkozas'] === 1, 'számla: 1. próba sikertelen, vár');
T::egyezik($most + 60, (int) $sz['kovetkezo'], 'újra 1 perc múlva');
T::tartalmaz((string) $sz['uzenet'], 'Rendszerhiba', 'a Számlázz.hu üzenete a lépésen');
T::egyezik('var', (string) t25_lepes($id, 'email')['allapot'], 'az üdvözlő levél a számlára vár');
T::egyezik(0, count(t25_levelek('udvozlo')), 'még nincs üdvözlő levél');
T::egyezik('fizetve', (string) t25_r($id)['allapot'], 'állapot még: fizetve');

SDH_Sales_Kiszolgalas::cron($most + 30);
T::egyezik(1, (int) t25_lepes($id, 'szamla')['probalkozas'], 'cron 30 mp-nél: még nem esedékes');

SDH_Sales_Kiszolgalas::cron($most + 61);
$sz = t25_lepes($id, 'szamla');
T::egyezik(2, (int) $sz['probalkozas'], 'cron 61 mp-nél: 2. próba');
T::egyezik($most + 61 + 5 * 60, (int) $sz['kovetkezo'], 'újra 5 perc múlva');

$t = $most + 61 + 5 * 60 + 1;
SDH_Sales_Kiszolgalas::cron($t);
$sz = t25_lepes($id, 'szamla');
T::egyezik(3, (int) $sz['probalkozas'], '3. próba');
T::egyezik($t + 15 * 60, (int) $sz['kovetkezo'], 'újra 15 perc múlva');
T::egyezik('ok', (string) t25_lepes($id, 'email')['allapot'], '3 sikertelen számlapróba után a levél nélküle megy');
T::egyezik(1, count(t25_levelek('udvozlo')), 'üdvözlő levél egyszer');
T::egyezik([], (array) (t25_levelek('udvozlo')[0]['csatolmany'] ?? ['x']), 'számla nélkül, csatolmány nincs');
T::egyezik('kiszolgalva', (string) t25_r($id)['allapot'], 'licenc + levél: kiszolgálva');
T::egyezik('ok', (string) t25_lepes($id, 'ertesites')['allapot'], 'értesítés kiment');

$varak = [60, 240, 240, 240, 0];
foreach ($varak as $i => $perc) {
    $t = (int) t25_lepes($id, 'szamla')['kovetkezo'] + 1;
    SDH_Sales_Kiszolgalas::cron($t);
    $sz = t25_lepes($id, 'szamla');

    if ($i < 4) {
        T::egyezik($t + $perc * 60, (int) $sz['kovetkezo'], ($i + 4) . '. próba után ' . $perc . ' perc');
    }
}
$sz = t25_lepes($id, 'szamla');
T::igaz($sz['allapot'] === 'hiba' && (int) $sz['probalkozas'] === SDH_Sales_Kiszolgalas::MAX_PROBA, '8 próba után: hiba');
$h = t25_levelek('lanc_hiba_admin', 'norbi@forgalmazo.example');
T::egyezik(1, count($h), 'hibalevél a forgalmazónak (egyszer)');
T::tartalmaz((string) ($h[0]['html'] ?? ''), 'Végszámla', 'a hibalevélben a lépés');
T::tartalmaz((string) ($h[0]['targy'] ?? ''), (string) t25_r($id)['azon'], 'a tárgyban a rendelés');
T::igaz($GLOBALS['t251_agent'] <= SDH_Sales_Szamlazz::MAX_PROBA, 'a Számlázz.hu-t legfeljebb ' . SDH_Sales_Szamlazz::MAX_PROBA . '-ször hívtuk (' . $GLOBALS['t251_agent'] . ')');
SDH_Sales_Kiszolgalas::cron($t + DAY_IN_SECONDS);
T::egyezik(SDH_Sales_Kiszolgalas::MAX_PROBA, (int) t25_lepes($id, 'szamla')['probalkozas'], 'hibás lépést a cron nem próbálja tovább');

// Javítás után kézi újraindítás (REST).
$GLOBALS['t251_jo'] = true;
$v = t_rest('POST', '/admin/kiszolgalas/' . $id . '/ujra', ['lepes' => 'szamla']);
T::igaz($v->get_status() === 200 && $v->get_data()['ok'] === true, 'kézi újraindítás: sikerült');
T::egyezik('E-T251-1', (string) t25_r($id)['szamla_vegszamla'], 'a végszámla megvan');
T::egyezik('ok', (string) t25_lepes($id, 'szamla')['allapot'], 'számla-lépés: ok');
T::egyezik(1, count(t25_levelek('udvozlo')), 'az üdvözlő levél nem ment ki újra');
T::igaz(t_rest('POST', '/admin/kiszolgalas/' . $id . '/ujra', ['lepes' => 'nincs'])->get_data()['ok'] === false, 'ismeretlen lépés');
T::igaz(!empty(t25_r($id)['lanc']['kesz']), 'a lánc kész');

/* --- Kikapcsolt automatikus számlázás: az adminra vár ------------------------------------------------------------- */

SDH_Sales::b_ment(['szamlazz.automatikus' => false]);
$GLOBALS['t251_agent'] = 0;
$id2 = t25_rendeles();
t25_fizet($id2);
$sz = t25_lepes($id2, 'szamla');
T::igaz($sz['allapot'] === 'var' && !empty($sz['kezi']), 'automatikus ki: a számla az adminra vár');
T::egyezik(0, $GLOBALS['t251_agent'], 'a Számlázz.hu-t nem hívta');
T::egyezik('ok', (string) t25_lepes($id2, 'email')['allapot'], 'a levél nem vár a kézi számlára');
SDH_Sales_Kiszolgalas::cron(time() + DAY_IN_SECONDS);
T::egyezik(0, $GLOBALS['t251_agent'], 'a cron sem számláz magától');
$v = SDH_Sales_Kiszolgalas::lepes_ujra($id2, 'szamla');
T::igaz($v['ok'] && (string) t25_r($id2)['szamla_vegszamla'] !== '', 'admin indítás: végszámla kész');
SDH_Sales::b_ment(['szamlazz.automatikus' => true]);

/* --- Nincs bekötve a Számlázz.hu: kihagyva ----------------------------------------------------------------------- */

SDH_Sales::b_ment(['szamlazz.teszt' => false]);
SDH_Sales::titkos_ment('szamlazz_teszt_kulcs', '');
$id3 = t25_rendeles();
t25_fizet($id3);
$sz = t25_lepes($id3, 'szamla');
T::egyezik('kihagyva', (string) $sz['allapot'], 'nincs Számlázz: kihagyva');
T::tartalmaz((string) $sz['uzenet'], 'nincs bekötve', 'az ok a lépésen');
T::igaz(!empty(t25_r($id3)['lanc']['kesz']), 'a lánc így is kész');
SDH_Sales::b_ment(['szamlazz.teszt' => true]);

/* --- Elérhetetlen Központ (távoli mód, hálózati hiba): a lánc megáll, később folytatódik ------------------------------ */

SDH_Sales::b_ment(['kozpont.mod' => 'tavoli', 'kozpont.url' => 'https://kozpont.example.hu/']);
$GLOBALS['t251_net'] = false;
t_http(static function (string $url): ?array {
    return strpos($url, 'kozpont.example.hu') !== false && !$GLOBALS['t251_net'] ? t_valasz(503, 'Service Unavailable') : null;
});
$id4  = t25_rendeles();
$most = time();
t25_fizet($id4, false);
SDH_Sales_Kiszolgalas::indit($id4, $most);
$k = t25_lepes($id4, 'kozpont');
T::igaz($k['allapot'] === 'var' && (int) $k['probalkozas'] === 1, 'Központ 503: újrapróbára vár');
T::tartalmaz((string) $k['uzenet'], 'HTTP 503', 'magyar hibaüzenet a kóddal');
T::egyezik(0, (int) t25_lepes($id4, 'szamla')['probalkozas'], 'kritikus lépés: a lánc megállt (számla sem futott)');
T::egyezik(0, count(t25_levelek('udvozlo', (string) t25_r($id4)['email'])), 'nincs levél licenc nélkül');
SDH_Sales::b_ment(['kozpont.mod' => 'helyi']);
SDH_Sales_Kiszolgalas::cron($most + 61);
T::egyezik('ok', (string) t25_lepes($id4, 'kozpont')['allapot'], 'a Központ újra elérhető: a cron folytatja');
T::igaz(!empty(t25_r($id4)['lanc']['kesz']) && (string) t25_r($id4)['allapot'] === 'kiszolgalva', 'a lánc végigment');

/* --- Végleges Központ-hiba (ismeretlen licenc megújítása): azonnal hiba ---------------------------------------- */

$id5 = t25_rendeles(['tipus' => 'megujitas', 'licenc_kulcs' => 'SDH-ZZZZ-ZZZZ-ZZZZ-ZZZZ']);
t25_fizet($id5);
$k = t25_lepes($id5, 'kozpont');
T::igaz($k['allapot'] === 'hiba' && (int) $k['probalkozas'] === 1, 'végleges hiba: nem próbálja újra');
T::tartalmaz((string) $k['uzenet'], 'Ismeretlen licenckulcs', 'a Központ üzenete');
T::egyezik(1, count(t25_levelek('lanc_hiba_admin', 'norbi@forgalmazo.example')) - 1, 'hibalevél a forgalmazónak');
T::egyezik('var', (string) t25_lepes($id5, 'email')['allapot'], 'licenc nélkül nincs levél');

/* --- Levélküldési hiba: újrapróba, a sikeres lépés nem fut újra ---------------------------------------------------- */

$id6  = t25_rendeles();
$most = time();
$GLOBALS['t25_level_hiba'] = true;
t25_fizet($id6, false);
SDH_Sales_Kiszolgalas::indit($id6, $most);
$e = t25_lepes($id6, 'email');
T::igaz($e['allapot'] === 'var' && (int) $e['probalkozas'] === 1, 'levélhiba: újrapróbára vár');
T::egyezik('var', (string) t25_lepes($id6, 'ertesites')['allapot'], 'az értesítés vár (kritikus lépés)');
$kulcs6 = (string) t25_r($id6)['licenc_kulcs'];
$GLOBALS['t25_level_hiba'] = false;
SDH_Sales_Kiszolgalas::cron($most + 61);
T::egyezik('ok', (string) t25_lepes($id6, 'email')['allapot'], 'levél a 2. próbára');
T::egyezik(1, (int) t25_lepes($id6, 'kozpont')['probalkozas'], 'a Központ-lépés nem futott újra');
T::egyezik($kulcs6, (string) t25_r($id6)['licenc_kulcs'], 'ugyanaz a kulcs');

/* --- Párhuzamos futás: a zár --------------------------------------------------------------------------------------- */

$wpdb->insert(SDH_Sales_Schema::t('korlat'), ['kulcs' => 'zar:kiszolgalas:' . $id3, 'db' => 1, 'lejar' => gmdate('Y-m-d H:i:s', time() + 60)]);
T::tartalmaz(SDH_Sales_Kiszolgalas::indit($id3)['uzenet'], 'másik folyamat', 'párhuzamos futás: kizárva');
$wpdb->delete(SDH_Sales_Schema::t('korlat'), ['kulcs' => 'zar:kiszolgalas:' . $id3]);

T::vege('t251 kiszolgálás: hibák és újrapróba');
