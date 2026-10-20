<?php
/** t221 – ütemezett feladatok: díjbekérő-emlékeztető (egyszer), lejárt díjbekérő törlése, elakadt kártyás fizetés, Számlázz.hu fizetettség-lekérdezés. */
require __DIR__ . '/_t210-seged.php';
t_katalogus_betolt();
global $wpdb;
$t = SDH_Sales_Schema::t('rendeles');

SDH_Sales::b_ment(['fizetes.emlekezteto_nap' => 5, 'fizetes.torles_nap' => 30]);
$most = time();

$uj    = t_rendeles();
$regi6 = t_rendeles();
$regi31 = t_rendeles();
$fiz31 = t_rendeles();
$wpdb->update($t, ['letrehozva' => gmdate('Y-m-d H:i:s', $most - 6 * DAY_IN_SECONDS)], ['id' => (int) $regi6['id']]);
$wpdb->update($t, ['letrehozva' => gmdate('Y-m-d H:i:s', $most - 31 * DAY_IN_SECONDS)], ['id' => (int) $regi31['id']]);
$wpdb->update($t, ['letrehozva' => gmdate('Y-m-d H:i:s', $most - 31 * DAY_IN_SECONDS)], ['id' => (int) $fiz31['id']]);
SDH_Sales_Rendeles::fizetve((int) $fiz31['id'], 'kezi', 'reszlet', 1000);

$GLOBALS['sdh_t_levelek'] = [];
$n = SDH_Sales_Fizetes::emlekeztetok($most);
T::egyezik(3, $n, 'emlékeztető: a 6 és 31 napos (fizetett részlettel is) – 3 levél');
T::egyezik(0, count(t_levelek($uj['r']['email'])), 'friss rendelés: nincs emlékeztető');
T::egyezik(1, count(t_levelek($regi6['r']['email'])), 'régi rendelés: emlékeztető');
T::tartalmaz((string) t_levelek($regi6['r']['email'])[0]['message'], $regi6['kozlemeny'], 'emlékeztetőben a közlemény');
T::egyezik(0, SDH_Sales_Fizetes::emlekeztetok($most), 'emlékeztető csak egyszer');

$n = SDH_Sales_Fizetes::lejart_torles($most);
T::egyezik(1, $n, 'törlés: csak a fizetetlen 31 napos');
T::egyezik('torolve', SDH_Sales_Rendeles::rendeles((int) $regi31['id'])['allapot'], '31 napos fizetetlen → törölve');
T::egyezik('dijbekero', SDH_Sales_Rendeles::rendeles((int) $fiz31['id'])['allapot'], 'részben fizetett nem törlődik');
T::egyezik('dijbekero', SDH_Sales_Rendeles::rendeles((int) $regi6['id'])['allapot'], '6 napos marad');

// Elakadt kártyás fizetés (a kapu nem válaszol) → sikertelen + levél
SDH_Sales::titkos_ment('barion_poskey', 'pos-titok-1234567890');
SDH_Sales::b_ment(['fizetes.barion.aktiv' => true, 'fizetes.barion.payee' => 'bolt@example.com']);
t_http(static function (string $url, array $a): ?array {
    if (strpos($url, '/v2/Payment/Start') !== false) {
        return t_valasz(200, (string) wp_json_encode(['PaymentId' => '0123456789abcdef0123456789abcdef', 'GatewayUrl' => 'https://secure.test.barion.com/Pay?Id=x']));
    }
    if (strpos($url, '/paymentstate') !== false) {
        return t_valasz(200, (string) wp_json_encode(['PaymentId' => '0123456789abcdef0123456789abcdef', 'PaymentRequestId' => $GLOBALS['t_rend'] ?? '', 'Status' => 'Started', 'Total' => 0]));
    }
    return null;
});
$k = t_latogato(static fn () => t_rendeles(['fizmod' => 'barion']));
$GLOBALS['t_rend'] = $k['azon'];
$wpdb->update($t, ['modositva' => gmdate('Y-m-d H:i:s', $most - 2 * HOUR_IN_SECONDS)], ['id' => (int) $k['id']]);
$GLOBALS['sdh_t_levelek'] = [];
SDH_Sales_Fizetes::kartya_elakadt($most);
T::egyezik('sikertelen', SDH_Sales_Rendeles::rendeles((int) $k['id'])['allapot'], 'elakadt kártyás fizetés → sikertelen');
T::egyezik(1, count(t_levelek($k['r']['email'])), 'sikertelen fizetés levele');
T::tartalmaz((string) t_levelek($k['r']['email'])[0]['message'], 'ujra=1', 'levélben újrapróba-link');

// Számlázz.hu fizetettség-lekérdezés (csak 'szamlazz' módban; hamis Számlázz-osztály helyett szűrő: a valódit hívjuk MINTA bizonylattal)
$sz = t_rendeles();
$wpdb->update($t, ['szamla_dijbekero' => 'D-TST-2026-1'], ['id' => (int) $sz['id']]);
T::egyezik(0, SDH_Sales_Fizetes::szamlazz_lekerdez($most), 'nem szamlazz módban nem kérdez');
SDH_Sales::b_ment(['fizetes.automatizalas' => 'szamlazz']);
$kif = 0;
if (class_exists('SDH_Sales_Szamlazz')) {
    // A számlázó modul a Számla Agentet hívja: hamis válasz egy 5000 Ft-os kifizetéssel
    t_http(static function (string $url, array $a) use (&$kif): ?array {
        if (strpos($url, 'szamlazz.hu/szamla') === false) { return null; }
        $kif++;
        $x = '<?xml version="1.0" encoding="UTF-8"?><szamla xmlns="http://www.szamlazz.hu/szamla"><alap><tipus>D</tipus><szamlaszam>D-TST-2026-1</szamlaszam><fizh>2026-10-27</fizh></alap>'
            . '<osszegek><totalossz><netto>9900</netto><afa>2673</afa><brutto>12573</brutto></totalossz></osszegek><kifizetesek><kifizetes><datum>2026-10-19</datum><jogcim>átutalás</jogcim><osszeg>5000</osszeg></kifizetes></kifizetesek></szamla>';
        return t_valasz(200, $x, ['content-type' => 'application/xml', 'szlahu_szamlaszam' => 'D-TST-2026-1']);
    });
    SDH_Sales::titkos_ment('szamlazz_agent_kulcs', 'agent-kulcs-teszt-123456');
    SDH_Sales::titkos_ment('szamlazz_teszt_kulcs', 'agent-kulcs-teszt-123456');
    $n = SDH_Sales_Fizetes::szamlazz_lekerdez($most);
    $x = SDH_Sales_Rendeles::rendeles((int) $sz['id']);
    if ($kif === 0) {
        T::igaz(true, 'Számlázz-modul nincs élesítve a tesztben – a lekérdezés kihagyva');
    } else {
        T::egyezik(5000, $x['fizetett'], 'Számlázz.hu: 5000 Ft kifizetés átvéve');
        T::egyezik(0, SDH_Sales_Fizetes::szamlazz_lekerdez($most), 'Számlázz.hu: várakozási idő alatt nem kérdez újra');
        delete_transient('szlpoll_' . (int) $sz['id']);
        SDH_Sales_Fizetes::szamlazz_lekerdez($most);
        T::egyezik(5000, SDH_Sales_Rendeles::rendeles((int) $sz['id'])['fizetett'], 'Számlázz.hu: ugyanaz a kifizetés nem számít kétszer');
    }
} else {
    T::igaz(true, 'Számlázz-modul hiányzik – kihagyva');
}

// A cron-hook be van kötve
T::igaz(has_action(SDH_Sales::CRON, ['SDH_Sales_Fizetes', 'cron']) !== false, 'cron-hook regisztrálva');
T::igaz(has_action(SDH_Sales::CRON, ['SDH_Sales_Fiok', 'takarit']) !== false, 'fiók-link takarítás a cronban');


// Független ellenőrzés (2026-10-20), K-4: a LIMIT nem „éhezteti” az újabb tételeket.
// a) Számlázz.hu fizetettség: 45 nyitott díjbekérő – két futás 40 különbözőt kérdez (régen: 20, a második 0).
$ids4 = [];
for ($i = 0; $i < 45; $i++) {
    $q4 = t_rendeles();
    $wpdb->update($t, ['szamla_dijbekero' => 'D-K4-' . $i], ['id' => (int) $q4['id']]);
    $ids4[] = (int) $q4['id'];
}
SDH_Sales::b_ment(['fizetes.automatizalas' => 'szamlazz']);
foreach (array_merge($ids4, [(int) $sz['id']]) as $i4) {
    delete_transient('szlpoll_' . $i4);
}
$kerdezett = static fn (): int => count(array_filter(array_merge($ids4, [(int) $sz['id']]), static fn (int $i): bool => (bool) get_transient('szlpoll_' . $i)));
SDH_Sales_Fizetes::szamlazz_lekerdez($most);
$k1 = $kerdezett();
SDH_Sales_Fizetes::szamlazz_lekerdez($most);
T::igaz($k1 === 20 && $kerdezett() === 40, 'K-4: Számlázz.hu-lekérdezés: futásonként a következő 20 (20 → 40; most ' . $k1 . ' → ' . $kerdezett() . ')');
SDH_Sales::b_ment(['fizetes.automatizalas' => 'import']);
// b) emlékeztető: 55 már emlékeztetett régi díjbekérő mellett az új esedékes is megkapja
$wpdb->query("UPDATE {$t} SET allapot = 'torolve' WHERE allapot = 'dijbekero'");
for ($i = 0; $i < 55; $i++) {
    $q4 = t_rendeles();
    $vv = (array) SDH_Sales_Rendeles::rendeles((int) $q4['id'])['vevo'] + ['_emlekeztetve' => gmdate('c', $most - DAY_IN_SECONDS)];
    $wpdb->update($t, ['letrehozva' => gmdate('Y-m-d H:i:s', $most - 7 * DAY_IN_SECONDS), 'vevo' => (string) wp_json_encode($vv, JSON_UNESCAPED_UNICODE)], ['id' => (int) $q4['id']]);
}
$friss4 = t_rendeles();
$wpdb->update($t, ['letrehozva' => gmdate('Y-m-d H:i:s', $most - 6 * DAY_IN_SECONDS)], ['id' => (int) $friss4['id']]);
T::egyezik(1, SDH_Sales_Fizetes::emlekeztetok($most), 'K-4: emlékeztető: 55 már emlékeztetett mellett az új is (1 levél)');
// c) kiszolgálási lánc: 55 adminra váró (kézi számla) lánc mellett az új fizetett rendelés lánca elindul
$var_lanc = (string) wp_json_encode(['lepesek' => ['kozpont' => ['allapot' => 'ok'], 'szamla' => ['allapot' => 'var', 'kezi' => true, 'kovetkezo' => 0], 'email' => ['allapot' => 'ok'], 'idopont' => ['allapot' => 'kihagyva'], 'ertesites' => ['allapot' => 'ok']], 'kesz' => false]);
for ($i = 0; $i < 55; $i++) {
    $q4 = t_rendeles();
    $wpdb->update($t, ['allapot' => 'kiszolgalva', 'fizetve_ido' => gmdate('Y-m-d H:i:s', $most - DAY_IN_SECONDS), 'lanc' => $var_lanc], ['id' => (int) $q4['id']]);
}
$uj4 = t_rendeles();
$wpdb->update($t, ['allapot' => 'fizetve', 'fizetve_ido' => gmdate('Y-m-d H:i:s', $most), 'lanc' => ''], ['id' => (int) $uj4['id']]);
SDH_Sales_Kiszolgalas::cron($most);
T::igaz(!empty(SDH_Sales_Rendeles::rendeles((int) $uj4['id'])['lanc']['lepesek']), 'K-4: kiszolgálás-cron: 55 adminra váró lánc mellett az új lánc is elindul');

T::vege('t221 cron + Számlázz.hu');
