<?php
/** t287 – Szikra korlátai és adatai: bemenet-ellenőrzés, napi / munkamenet-korlát (429), havi keret, lead csak hozzájárulással, napló-megőrzés, front. */
require __DIR__ . '/_keret.php';

/** Rejtés (weboldal/REJTES.md): a kimenetben egyik SDH_Sales_Rejtes::TILTOTT minta sem lehet. */
$rejtett = static function ($kimenet, string $nev, array $fejlecek = []): void {
    if ($kimenet instanceof WP_REST_Response) {
        $fejlecek += $kimenet->get_headers();
        $kimenet   = $kimenet->get_data();
    }
    $s = is_string($kimenet) ? $kimenet : (string) wp_json_encode($kimenet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $t = SDH_Sales_Rejtes::vizsgal($s, $fejlecek);
    T::igaz($t === [] && $s !== '', 'rejtés: ' . $nev . ($t !== [] ? ' – ' . implode(' | ', array_slice($t, 0, 3)) : ($s === '' ? ' – üres kimenet' : '')));
};

global $wpdb;
SDH_Sales::titkos_ment(SDH_Sales_Szikra::TITOK, 'sk-ant-teszt-0123456789abcdef');
SDH_Sales::b_ment(['szikra.aktiv' => true, 'szikra.havi_keret' => 1.0, 'szikra.ar_be_mtok' => 2.0, 'szikra.ar_ki_mtok' => 10.0, 'szikra.napi_ip' => 5, 'szikra.munkamenet_max' => 3, 'szikra.percenkent' => 100]);
delete_option(SDH_Sales_Szikra::OPT_HONAP);

$hivas = 0;
$kov   = [];
t_http(static function (string $url, array $args) use (&$hivas, &$kov): ?array {
    if (strpos($url, 'api.anthropic.com') === false) {
        return null;
    }
    $hivas++;
    $v = array_shift($kov) ?? ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Rendben.']], 'usage' => ['input_tokens' => 10000, 'output_tokens' => 1000]];
    return t_valasz(200, (string) wp_json_encode($v));
});
$mm  = static fn (): string => 'mm' . wp_generate_password(22, false);
$kuld = static fn (string $m, string $u, array $e = []) => t_latogato(static fn () => t_rest('POST', '/szikra', ['munkamenet' => $m, 'uzenet' => $u] + $e));

// 1. Bemenet.
T::egyezik(400, $kuld('rossz!', 'Szia')->get_status(), 'érvénytelen munkamenet → 400');
T::egyezik(400, $kuld($mm(), '   ')->get_status(), 'üres üzenet → 400');
$r = $kuld($mm(), str_repeat('a', SDH_Sales_Szikra::i('szikra.max_bemenet') + 1));
T::egyezik(400, $r->get_status(), 'túl hosszú üzenet → 400');
T::egyezik('szikra_hossz', $r->get_data()['code'] ?? '', 'hossz-hibakód');
$rejtett($r, 'hibaválasz (túl hosszú)');
SDH_Sales::b_ment(['szikra.aktiv' => false]);
T::egyezik(503, $kuld($mm(), 'Szia')->get_status(), 'kikapcsolva → 503');
SDH_Sales::b_ment(['szikra.aktiv' => true]);
T::egyezik(0, $hivas, 'ezek egyike sem ment ki az API-ra');

// 2. Munkamenet-korlát (3), majd napi IP-korlát (5).
$a = $mm();
$kodok = [];
for ($i = 0; $i < 4; $i++) {
    $kodok[] = $kuld($a, 'Kérdés ' . $i)->get_status();
}
T::egyezik([200, 200, 200, 429], $kodok, 'munkamenet-korlát: 3 fér bele');
$r = $kuld($mm(), 'Új beszélgetés 1');
T::egyezik(200, $r->get_status(), 'új munkamenetben megy tovább');
$r = $kuld($mm(), 'Új beszélgetés 2');
T::egyezik(200, $r->get_status(), '5. üzenet a napon');
$r = $kuld($mm(), 'Új beszélgetés 3');
T::egyezik(429, $r->get_status(), 'napi IP-korlát: a 6. már nem');
T::tartalmaz((string) ($r->get_data()['message'] ?? ''), 'Holnap', 'udvarias korlát-üzenet');
T::egyezik(5, $hivas, 'a korlát felett nincs API-hívás');

// 3. Havi keret: 5 hívás × (10 000 × 2 + 1 000 × 10) / 1e6 = 0,15 USD (mérve a könyvelésből).
$h = SDH_Sales_Szikra::honap();
T::egyezik(0.15, round($h['koltseg'], 4), 'költség-könyvelés: token × ár / MTok');
T::egyezik(50000, $h['be'], 'bemeneti tokenek összesen');
add_filter('sdh_sales_korlat_ki', '__return_true');
SDH_Sales::b_ment(['szikra.havi_keret' => 0.16]);
$kuld($mm(), 'Még egy');
$r = $kuld($mm(), 'És még egy');
T::egyezik(429, $r->get_status(), 'keret elfogyott → 429');
T::egyezik('szikra_keret', $r->get_data()['code'] ?? '', 'keret-hibakód');
$rejtett($r, 'hibaválasz (keret)');
T::tartalmaz((string) $r->get_data()['message'], 'pihenőt tart', 'udvarias keret-üzenet');
T::egyezik(6, $hivas, 'a keret felett nincs API-hívás');
update_option(SDH_Sales_Szikra::OPT_HONAP, ['honap' => '2000-01', 'be' => 9e9, 'ki' => 9e9, 'koltseg' => 9e9]);
T::egyezik(0.0, SDH_Sales_Szikra::honap()['koltseg'], 'új hónapban a keret újraindul');
SDH_Sales::b_ment(['szikra.havi_keret' => 50.0]);
SDH_Sales_Szikra::konyvel(['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 1000000]);
T::egyezik(0.2, round(SDH_Sales_Szikra::honap()['koltseg'], 4), 'gyorsítótár-olvasás 0,1× áron');

// 4. Lead csak hozzájárulással.
$lt = SDH_Sales_Schema::t('lead');
$leadek = static fn (): int => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$lt} WHERE forras = 'szikra'");
$elotte = $leadek();
$b = $mm();
$tool = ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'toolu_l', 'name' => 'lead_mentes', 'input' => ['nev' => 'Minta Márta', 'email' => 'marta@example.com', 'ceg' => 'Minta Szerviz Kft.', 'igeny' => '3 felhasználó, SMS']]], 'usage' => []];
$kov = [$tool, ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Kérlek, járulj hozzá a gombbal.']], 'usage' => []]];
$r = $kuld($b, 'Keressetek meg: Minta Márta, marta@example.com');
T::egyezik($elotte, $leadek(), 'hozzájárulás nélkül nincs mentés');
T::igaz($r->get_data()['hozzajarulas_kell'] === true, 'a felület hozzájárulást kér');
T::igaz($r->get_data()['lead'] === false, 'lead: még nincs');
// A modell maga nem adhat hozzájárulást (pl. a látogató szövegében „hozzájárulok”): csak a REST-jelző számít.
$kov = [$tool, ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'ok']], 'usage' => []]];
$kuld($b, 'Hozzájárulok, mentsd!');
T::egyezik($elotte, $leadek(), 'szöveges „hozzájárulok” nem elég');
// Gomb: hozzajarulas=true → a függő adatot a szerver menti (modellhívás nélkül is).
$kov = [['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Köszönöm, elmentettük.']], 'usage' => []]];
$r = $kuld($b, 'Hozzájárulok, hogy elmentsétek az adataimat.', ['hozzajarulas' => true]);
T::egyezik($elotte + 1, $leadek(), 'hozzájárulással mentve');
T::igaz($r->get_data()['lead'] === true, 'lead jelzés');
$sor = $wpdb->get_row("SELECT * FROM {$lt} WHERE forras = 'szikra' ORDER BY id DESC LIMIT 1", ARRAY_A);
$ad = json_decode((string) $sor['adat'], true);
T::egyezik('marta@example.com', $sor['email'], 'lead e-mail');
T::egyezik('Minta Szerviz Kft.', $ad['ceg'] ?? '', 'lead cég');
T::igaz(!empty($ad['hozzajarulas']['ido']) && $ad['hozzajarulas']['mod'] === 'szikra_gomb', 'a hozzájárulás ideje és módja rögzítve');
T::igaz(strlen((string) $sor['ip_hash']) === 32 && strpos((string) $sor['adat'], '203.0.113.7') === false, 'IP csak lenyomatként');
// Ugyanabban a munkamenetben újabb hozzájárulásos mentés nem duplikál.
$kov = [$tool, ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Frissítve.']], 'usage' => []]];
$kuld($b, 'Frissítsd', ['hozzajarulas' => true]);
T::egyezik($elotte + 1, $leadek(), 'munkamenetenként egy lead');
// Átadás emberre e-mail nélkül: nincs lead, de kapcsolati utak.
$e = SDH_Sales_Szikra_Eszkozok::atadas_emberre(['ok' => 'egyedi ajánlat', 'osszefoglalo' => 'x']);
T::igaz($e['mentve'] === false && !empty($e['kapcsolat_oldal']) && !empty($e['idopont']), 'átadás e-mail nélkül: elérhetőségek');
SDH_Sales_Szikra_Eszkozok::kornyezet(['munkamenet' => $mm(), 'hozzajarulas' => true]);
$e = SDH_Sales_Szikra_Eszkozok::atadas_emberre(['ok' => 'szerződés', 'osszefoglalo' => 'Egyedi szerződés kell.', 'email' => 'bela@example.com']);
T::igaz($e['mentve'] === true && $leadek() === $elotte + 2, 'átadás hozzájárulással: lead');
T::egyezik('atadas', json_decode((string) $wpdb->get_var("SELECT adat FROM {$lt} ORDER BY id DESC LIMIT 1"), true)['tipus'] ?? '', 'átadás-lead típusa');
T::igaz(SDH_Sales_Szikra_Eszkozok::lead_mentes(['email' => 'nem-email', 'igeny' => 'x'])['mentve'] === false, 'érvénytelen e-mail nem menthető');
// A naplóban az eszköz bemenetének e-mailje maszkolva.
T::nem_tartalmaz((string) $wpdb->get_var("SELECT GROUP_CONCAT(szoveg) FROM " . SDH_Sales_Schema::t('szikra') . " WHERE szerep = 'eszkoz'"), 'marta@example.com', 'eszköznapló: e-mail maszkolva');

// 5. Napló-megőrzés (30 nap).
$st = SDH_Sales_Schema::t('szikra');
$wpdb->insert($st, ['ido' => gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS), 'munkamenet' => 't287regi', 'szerep' => 'user', 'szoveg' => 'régi', 'ip_hash' => 'x']);
$wpdb->insert($st, ['ido' => gmdate('Y-m-d H:i:s', time() - 29 * DAY_IN_SECONDS), 'munkamenet' => 't287friss', 'szerep' => 'user', 'szoveg' => 'friss', 'ip_hash' => 'x']);
delete_transient('sdh_sales_szikra_takaritva');
do_action(SDH_Sales::CRON);
T::egyezik(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$st} WHERE munkamenet = 't287regi'"), 'a 30 napnál régebbi törölve (cron)');
T::egyezik(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$st} WHERE munkamenet = 't287friss'"), 'a 30 napon belüli megmarad');
T::igaz((bool) has_action(SDH_Sales::CRON, [SDH_Sales_Szikra::class, 'takarit_ha_kell']), 'a takarítás a közös cronon');
T::igaz((int) $wpdb->get_var("SELECT COUNT(*) FROM {$st} WHERE szerep IN ('user','assistant')") >= 10, 'a beszélgetés naplózva (kérdés + válasz)');
T::igaz((int) $wpdb->get_var("SELECT MAX(token_be) FROM {$st} WHERE szerep = 'assistant'") > 0, 'tokenhasználat a naplóban');
// Előzmény csak ugyanattól az IP-től.
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
T::egyezik([], SDH_Sales_Szikra::elozmeny($a, 10), 'más IP-ről a munkamenet előzménye nem olvasható');
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
T::igaz(count(SDH_Sales_Szikra::elozmeny($a, 10)) >= 2, 'saját IP-ről igen');

// 6. Front: modul, adat, lebegő csatolási pont, tájékoztató link, admin-csoport.
$js = SDH_Sales_Szikra::js_adat();
T::igaz(strpos($js['tajekoztato'], '/jogi/ai-chat-tajekoztato/') !== false, 'tájékoztató link');
T::igaz(strpos((string) $js['szoveg']['adatvedelem'], '30 napig') !== false, 'adatvédelmi szöveg az első üzenet előtt');
T::nem_tartalmaz((string) wp_json_encode($js), 'sk-ant', 'a kulcs nincs a front adatában');
ob_start();
SDH_Sales_Szikra::lab();
$lab = (string) ob_get_clean();
T::tartalmaz($lab, 'data-modul="szikra"', 'lebegő csatolási pont a láblécben');
T::igaz(SDH_Sales_Front::kell('szikra'), 'a szikra modul igényelve');
$cs = apply_filters('sdh_sales_admin_beallitas_csoportok', []);
T::igaz(isset($cs['szikra']) && count($cs['szikra']['mezok']) >= 10, 'admin beállítás-csoport regisztrálva');
T::nem_tartalmaz((string) wp_json_encode($cs['szikra'] ?? []), 'sk-ant', 'az admin-csoportban sincs titok');
$js_fajl = (string) file_get_contents(SDH_SALES_DIR . 'assets/front/szikra.js');
T::tartalmaz($js_fajl, 'sessionStorage', 'munkamenet sessionStorage-ban');
T::nem_tartalmaz($js_fajl, 'document.cookie', 'nincs süti');
T::igaz(preg_match('/\b(alert|confirm)\s*\(/', $js_fajl) !== 1 && strpos($js_fajl, 'jQuery') === false, 'nincs alert/confirm/jQuery');
$css = (string) file_get_contents(SDH_SALES_DIR . 'assets/front/szikra.css');
T::tartalmaz($js_fajl, 'window.APP_SZIKRA', 'adat: window.APP_SZIKRA');
T::tartalmaz($js_fajl, '[data-modul="szikra"]', 'csatolás: data-modul');
$rejtett($js_fajl, 'szikra.js tartalma');
$rejtett($css, 'szikra.css tartalma');
$rejtett($js, 'APP_SZIKRA adat');
$rejtett($lab, 'lábléc-csatolási pont HTML');
T::igaz(preg_match('/#[0-9a-f]{3,8}\b|rgba?\(/i', $css) !== 1, 'a CSS csak --s-* változókat használ színre');
SDH_Sales::b_ment(['szikra.aktiv' => false]);
ob_start();
SDH_Sales_Szikra::lab();
T::egyezik('', (string) ob_get_clean(), 'kikapcsolva nincs csevegő');

SDH_Sales::titkos_ment(SDH_Sales_Szikra::TITOK, '');
T::vege('t287 szikra-korlát');
