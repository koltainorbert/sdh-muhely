<?php
/** t280 – jogi oldalak (frontmatter, tervezet-sor, tokenek) + süti-hozzájárulás (Consent Mode, blokkolás, napló, korlát, verzió). */
require __DIR__ . '/_keret.php';

global $wpdb;

/* ---------------- 1. Jogi dokumentumok ---------------- */
$jogi_mappa = SDH_SALES_DIR . 'data/jogi/';
$vart = ['aszf', 'adatkezeles', 'suti-tajekoztato', 'impresszum', 'licencszerzodes', 'sla', 'adatfeldolgozasi-szerzodes', 'elallas-visszaterites', 'ai-chat-tajekoztato', 'demo-feltetelek'];

foreach ($vart as $slug) {
    $f = $jogi_mappa . $slug . '.md';
    T::igaz(is_file($f), "jogi fájl: {$slug}");

    if (!is_file($f)) {
        continue;
    }

    $s = (string) file_get_contents($f);
    [$fej, $md] = SDH_Sales_Tartalom::frontmatter($s);

    foreach (['cim', 'leiras', 'verzio', 'hatalyos', 'szulo'] as $k) {
        T::igaz(!empty($fej[$k]), "{$slug}: frontmatter {$k}");
    }

    T::egyezik('1.0', (string) ($fej['verzio'] ?? ''), "{$slug}: verzió");
    T::egyezik('2026-11-01', (string) ($fej['hatalyos'] ?? ''), "{$slug}: hatályos");
    T::egyezik('jogi', (string) ($fej['szulo'] ?? ''), "{$slug}: szülő");
    $elso = strtok(ltrim($md), "\n");
    T::egyezik('**TERVEZET – jogásszal ellenőriztetni.**', trim((string) $elso), "{$slug}: első sor");
    T::nem_tartalmaz($s, 'SDH CRM', "{$slug}: nincs beégetett terméknév");
    T::igaz(preg_match('/wordpress|sdh|plugin|bővítmény|site kit|rank math|wp rocket/iu', $s) === 0, "{$slug}: rejtés (nincs platform-/SDH-név)");
    T::nem_tartalmaz($s, 'Cégnév Kft', "{$slug}: nincs beégetett cégnév");
    T::igaz(preg_match('/\{(ceg|termeknev)[a-z_]*\}/', $s) === 1, "{$slug}: tokenes cég/termék");
    T::igaz(strlen(SDH_Sales_Tartalom::md_html($md)) > 200, "{$slug}: HTML-re alakítható");
}

$imp = (string) file_get_contents($jogi_mappa . 'impresszum.md');
foreach (['{ceg}', '{ceg_cim}', '{ceg_adoszam}', '{ceg_cegjegyzek}', '{ceg_email}', '{ceg_telefon}', '{ceg_kepviselo}', '{tarhely_nev}', '{tarhely_cim}'] as $tok) {
    T::tartalmaz($imp, $tok, 'impresszum token');
}

// Token-csere: a tárhely-tokenek a magba be vannak kötve, a cégnév a beállításból jön.
SDH_Sales::b_ment(['ceg.nev' => 'Próba Szerviz Bt.', 'jogi.tarhely_nev' => 'Próba Tárhely Zrt.']);
$cs = SDH_Sales::t('{ceg} – {tarhely_nev} – {levelezo_nev}');
T::tartalmaz($cs, 'Próba Szerviz Bt.', 'token: cégnév beállításból');
T::tartalmaz($cs, 'Próba Tárhely Zrt.', 'token: tárhely beállításból');
T::tartalmaz($cs, '[levelezési', 'token: üres → helyőrző');

/* ---------------- 2. <head>: Consent Mode az első szkript ---------------- */
wp_enqueue_script('t280-fej', 'https://pelda.invalid/fej.js', [], '1', false);
add_action('wp_head', static function (): void { echo "<script>window.t280korai=1;</script>\n"; }, -1000);
ob_start();
do_action('wp_head');
$fej = (string) ob_get_clean();
$elso_script = strpos($fej, '<script');
T::igaz($elso_script !== false, 'head: van szkript');
T::igaz(strpos($fej, 'id="s-suti-alap"') !== false && strpos($fej, '<script id="s-suti-alap"') === $elso_script || strpos(substr($fej, $elso_script, 120), 's-suti-alap') !== false, 'head: az első szkript a hozzájárulási alap');
T::igaz(strpos($fej, "gtag('consent','default'") < strpos($fej, 'fej.js'), 'head: default a sorban lévő szkript előtt');
T::igaz(strpos($fej, "gtag('consent','default'") < strpos($fej, 't280korai'), 'head: default a korai (−1000) szkript előtt');
foreach (["ad_storage:'denied'", "ad_user_data:'denied'", "ad_personalization:'denied'", "analytics_storage:'denied'", "functionality_storage:'denied'", "personalization_storage:'denied'", "security_storage:'granted'", 'wait_for_update:500', "'ads_data_redaction',true"] as $r) {
    T::tartalmaz($fej, $r, 'consent default');
}

// GA4 / GTM: csak blokkolva megy ki.
SDH_Sales::b_ment(['suti.ga4' => 'G-ABC1234567', 'suti.gtm' => 'GTM-ABCD123']);
ob_start();
SDH_Sales_Suti::google_betolto();
$g = (string) ob_get_clean();
T::tartalmaz($g, 'data-src="https://www.googletagmanager.com/gtag/js?id=G-ABC1234567"', 'GA4 betöltő data-src');
T::igaz(preg_match_all('/<script\b(?![^>]*type="text\/plain")/', $g) === 0, 'GA4/GTM: nincs futó szkript hozzájárulás előtt');
T::igaz(substr_count($g, 'type="text/plain"') === 3, 'GA4 + config + GTM blokkolva');

/* ---------------- 3. szkript() ---------------- */
$k = SDH_Sales_Suti::szkript('statisztika', 'https://pelda.invalid/a.js');
T::egyezik('<script type="text/plain" data-suti="statisztika" data-src="https://pelda.invalid/a.js"></script>', $k, 'szkript(): külső');
$k = SDH_Sales_Suti::szkript('marketing', 'fbq("init","1");</script><script>alert(1)');
T::tartalmaz($k, '<script type="text/plain" data-suti="marketing">', 'szkript(): inline');
T::nem_tartalmaz($k, '</script><script>', 'szkript(): nem lehet kitörni');
T::tartalmaz(SDH_Sales_Suti::szkript('ismeretlen', 'x()'), 'data-suti="marketing"', 'szkript(): ismeretlen → marketing');
T::egyezik('<script>y()</script>', SDH_Sales_Suti::szkript('szukseges', 'y()'), 'szkript(): szükséges fut');

// Site Kit gtag (google_gtagjs) blokkolása.
$tag = SDH_Sales_Suti::blokk_tag("<script type=\"text/javascript\" src=\"https://www.googletagmanager.com/gtag/js?id=G-X\" id=\"google_gtagjs-js\" async></script>\n", 'google_gtagjs');
T::tartalmaz($tag, 'type="text/plain" data-suti="statisztika"', 'Site Kit: blokkolva');
T::tartalmaz($tag, 'data-src="https://www.googletagmanager.com/gtag/js?id=G-X"', 'Site Kit: src → data-src');
T::egyezik(1, substr_count($tag, 'type='), 'Site Kit: egy type');
$ia = SDH_Sales_Suti::blokk_inline(['id' => 'google_gtagjs-js-after'], 'gtag()');
T::egyezik('text/plain', $ia['type'] ?? '', 'Site Kit inline: blokkolva');
T::igaz(!isset(SDH_Sales_Suti::blokk_inline(['id' => 'mas-js-after'])['type']), 'más inline: érintetlen');

// CSP: Google-tartományok hozzáadva.
$csp = SDH_Sales_Suti::csp(['script-src' => ["'self'"]]);
T::igaz(in_array('https://www.googletagmanager.com', $csp['script-src'], true) && in_array("'self'", $csp['script-src'], true), 'CSP: script-src bővítve');
T::igaz(isset($csp['connect-src']) && in_array('https://*.google-analytics.com', $csp['connect-src'], true), 'CSP: connect-src');

// Panel: egyforma gombok, kapcsolók, ESC-hez dialog.
$p = SDH_Sales_Suti::panel_html();
T::igaz(substr_count($p, 'class="s-suti-gomb"') === 6, 'panel: 3 + 3 egyforma gomb');
T::tartalmaz($p, 'role="dialog" aria-modal="true"', 'panel: modális dialógus');
T::tartalmaz($p, 'data-suti-kat="szukseges" checked disabled', 'panel: szükséges fix');
T::nem_tartalmaz($p, 'Aktív', 'panel: nincs Aktív felirat');

/* ---------------- 4. POST /suti napló + korlát ---------------- */
$t = SDH_Sales_Schema::t('suti');
$elotte = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");
$azon = 'TesztAzonosito_1234567';
$r = t_latogato(static fn () => t_rest('POST', '/suti', ['azon' => $azon, 'verzio' => '1.0', 'kategoriak' => ['statisztika' => true, 'marketing' => false, 'preferencia' => true], 'gpc' => true]));
T::egyezik(201, $r->get_status(), 'napló: 201');
$sor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE azon = %s ORDER BY id DESC LIMIT 1", $azon), ARRAY_A);
T::igaz(is_array($sor), 'napló: sor');
$kat = json_decode((string) ($sor['kategoriak'] ?? ''), true);
T::egyezik(['szukseges' => 1, 'statisztika' => 1, 'marketing' => 0, 'preferencia' => 1, 'gpc' => 1], $kat, 'napló: kategóriák');
T::egyezik(32, strlen((string) ($sor['ip_hash'] ?? '')), 'napló: IP-lenyomat');
T::igaz(strpos((string) ($sor['ip_hash'] ?? ''), '203.0.113') === false, 'napló: nincs nyers IP');
$r = t_latogato(static fn () => t_rest('POST', '/suti', ['azon' => 'rossz!', 'verzio' => '1.0']));
T::egyezik(400, $r->get_status(), 'napló: rossz azonosító 400');

$kodok = [];
for ($i = 0; $i < 25; $i++) {
    $kodok[] = t_latogato(static fn () => t_rest('POST', '/suti', ['azon' => 'KorlatTeszt_' . str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'verzio' => '1.0', 'kategoriak' => []]))->get_status();
}
T::igaz(in_array(429, $kodok, true), 'korlát: 429 jön');
// 20 kérés fér bele 10 percben: 1 érvényes + 1 hibás (az is számít) + 18 a ciklusból.
T::egyezik(19, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}") - $elotte, 'korlát: 20 kérés fér bele 10 percben');
T::egyezik(18, count(array_filter($kodok, static fn ($k) => $k === 201)), 'korlát: ciklusban 18 siker');

// Admin CSV-export.
$r = t_rest('GET', '/admin/suti/export');
T::egyezik(200, $r->get_status(), 'export: 200');
$csv = (string) $r->get_data();
T::tartalmaz($csv, '"id";"ido_utc";"azonosito"', 'export: fejléc');
T::tartalmaz($csv, $azon, 'export: sor');
T::egyezik(0, strpos(SDH_Sales_Suti::csv([['=1+1']]), "\xEF\xBB\xBF"), 'csv: BOM');
T::tartalmaz(SDH_Sales_Suti::csv([['=1+1']]), "\"'=1+1\"", 'csv: képlet semlegesítve');
$r = t_latogato(static fn () => t_rest('GET', '/admin/suti/export'));
T::igaz(in_array($r->get_status(), [401, 403], true), 'export: látogatónak tiltott');

/* ---------------- 5. Node: verzió-újrakérdezés, Consent Mode alapszkript ---------------- */
$node = trim((string) shell_exec('command -v node'));
T::igaz($node !== '', 'node elérhető');

if ($node !== '') {
    $js  = SDH_SALES_DIR . 'assets/front/suti.js';
    $alap_js = SDH_Sales_Suti::alap_js();
    $nodekod = <<<'JS'
const S = require(process.argv[2]);
const vm = require('vm');
const ki = [];
const most = Date.UTC(2026, 9, 19) ;
const t = Math.floor(most / 1000) - 86400;
const c = (v) => 'x=1; suti=' + encodeURIComponent(v) + '; y=2';
ki.push(S.kerdezni('', '1.0', 6, most) === true);                               // nincs döntés → kérdez
ki.push(S.kerdezni(c(S.kodol({statisztika:true,marketing:false,preferencia:true}, '1.0', t)), '1.0', 6, most) === false); // érvényes
ki.push(S.kerdezni(c(S.kodol({statisztika:true,marketing:false,preferencia:true}, '1.0', t)), '1.1', 6, most) === true);  // új verzió → újra
ki.push(S.kerdezni(c(S.kodol({statisztika:true,marketing:true,preferencia:true}, '1.0', t - 200 * 86400)), '1.0', 6, most) === true); // lejárt
ki.push(S.kerdezni(c('1|1.0|1x1|123'), '1.0', 6, most) === true);                // sérült
const e = S.elemez(S.kodol({statisztika:false,marketing:true,preferencia:false}, '2.0', t));
ki.push(e.verzio === '2.0' && e.kat.marketing === true && e.kat.statisztika === false);
ki.push(S.mindet(true, true).marketing === false && S.mindet(true, true).statisztika === true); // GPC
ki.push(S.mindet(true, false).marketing === true && S.mindet(false, false).statisztika === false);
const ca = S.consentAllapot({statisztika:true,marketing:false,preferencia:false});
ki.push(ca.analytics_storage === 'granted' && ca.ad_storage === 'denied' && ca.ad_user_data === 'denied');
ki.push(S.wpConsent({statisztika:false,marketing:true,preferencia:true}).marketing === 'allow' && S.wpConsent({}).functional === 'allow');
ki.push(S.visszavont({statisztika:true}, {statisztika:false}) === true && S.visszavont(null, {}) === false);
ki.push(S.engedett(null, 'szukseges') === true && S.engedett(null, 'statisztika') === false);
const az = S.ujAzon((n) => Uint8Array.from({length:n}, (_, i) => i * 37 % 256));
ki.push(/^[A-Za-z0-9_-]{22}$/.test(az));
// Az inline <head> szkript: alapból denied, érvényes sütinél azonnal update; más verziónál nincs update.
const futtat = (cookie) => { const w = { document: { cookie } }; w.window = w; vm.runInNewContext(process.argv[3], w); return w.dataLayer.map(a => Array.from(a)); };
let dl = futtat('');
ki.push(dl[0][0] === 'consent' && dl[0][1] === 'default' && dl[0][2].analytics_storage === 'denied' && dl[0][2].security_storage === 'granted' && dl.length === 2);
dl = futtat(c(S.kodol({statisztika:true,marketing:false,preferencia:false}, process.argv[4], Math.floor(Date.now()/1000))));
ki.push(dl.length === 3 && dl[2][1] === 'update' && dl[2][2].analytics_storage === 'granted' && dl[2][2].ad_storage === 'denied');
dl = futtat(c(S.kodol({statisztika:true,marketing:true,preferencia:true}, 'regi', Math.floor(Date.now()/1000))));
ki.push(dl.length === 2);
console.log(JSON.stringify(ki));
JS;
    $f = tempnam(sys_get_temp_dir(), 't280') . '.js';
    file_put_contents($f, $nodekod);
    $kimenet = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($f) . ' ' . escapeshellarg($js) . ' ' . escapeshellarg($alap_js) . ' ' . escapeshellarg(SDH_Sales_Suti::verzio()) . ' 2>&1');
    @unlink($f);
    $eredm = json_decode(trim($kimenet), true);
    T::igaz(is_array($eredm), 'node: lefutott (' . substr($kimenet, 0, 200) . ')');

    foreach ((array) $eredm as $i => $ok) {
        T::igaz($ok === true, 'node-ellenőrzés #' . $i);
    }
}

// Verzióváltás: az alapszkript az új verziót figyeli.
SDH_Sales::b_ment(['suti.verzio' => '1.1']);
T::tartalmaz(SDH_Sales_Suti::alap_js(), "p[1]!==\"1.1\"", 'verzióváltás: alapszkript új verziót vár');
T::egyezik('1.1', SDH_Sales_Suti::js_adat()['verzio'], 'verzióváltás: JS-adat');

/* ---------------- 6. Fájlok, beállítás-csoport ---------------- */
T::igaz(is_file(SDH_SALES_DIR . 'assets/front/suti.js') && is_file(SDH_SALES_DIR . 'assets/front/suti.css'), 'assets: suti.js + suti.css megvan');
foreach (['assets/front/suti.js', 'assets/front/suti.css'] as $af) {
    T::igaz(preg_match('/wordpress|sdh|plugin|bővítmény|site kit/iu', (string) file_get_contents(SDH_SALES_DIR . $af)) === 0, "rejtés: {$af}");
}
$kimenet = SDH_Sales_Suti::panel_html() . SDH_Sales_Suti::alap_js() . SDH_Sales_Suti::szkript('statisztika', 'x()');
T::igaz(preg_match('/wordpress|sdh|wp_consent/iu', $kimenet) === 0, 'rejtés: panel + alapszkript + szkript() kimenete');
$css = (string) file_get_contents(SDH_SALES_DIR . 'assets/front/suti.css');
T::igaz(preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(/', $css) === 0, 'suti.css: csak változó-színek');
$csop = SDH_Sales_Suti::admin_csoport([]);
T::igaz(isset($csop['suti']['mezok']) && count($csop['suti']['mezok']) >= 10, 'admin: süti-csoport');
T::igaz(has_filter('wp_consent_api_registered_' . plugin_basename(SDH_SALES_FILE)) !== false, 'WP Consent API: regisztrálva');

T::vege('t280 süti + jogi');
