<?php
/** t213 – fizetve(): idempotencia, részfizetés, túlfizetés, ismeretlen, versenyhelyzet (párhuzamos folyamatok), admin „Fizetve”. */
require __DIR__ . '/_t210-seged.php';
t_katalogus_betolt();
global $wpdb;

$r  = t_rendeles();
$id = (int) $r['id'];
$b  = (int) $r['r']['brutto'];

// Részfizetés
T::igaz(SDH_Sales_Rendeles::fizetve($id, 'bank_import', 'ref:A1', 5000), 'részfizetés rögzítve');
$x = SDH_Sales_Rendeles::rendeles($id);
T::egyezik('dijbekero', $x['allapot'], 'részfizetés után még nyitott');
T::egyezik(5000, $x['fizetett'], 'fizetett = 5000');
T::egyezik(0, $GLOBALS['t_fizetve'][$id] ?? 0, 'hook még nem futott');

// Ugyanaz a tranzakció újra: nem számít
T::igaz(!SDH_Sales_Rendeles::fizetve($id, 'bank_import', 'ref:A1', 5000), 'ismételt tranzakció: hamis');
T::egyezik(5000, SDH_Sales_Rendeles::rendeles($id)['fizetett'], 'ismétlés nem növel');
// Más forrás, ugyanaz az azonosító: külön tranzakció
T::igaz(SDH_Sales_Rendeles::fizetve($id, 'kezi', 'ref:A1', 1000), 'más forrás azonos azonosítóval külön');

// Kiegészítés → fizetve
T::igaz(SDH_Sales_Rendeles::fizetve($id, 'bank_import', 'ref:A2', $b - 6000), 'maradék');
$x = SDH_Sales_Rendeles::rendeles($id);
T::egyezik('fizetve', $x['allapot'], 'teljes összeg után fizetve');
T::igaz(!empty($x['fizetve_ido']), 'fizetve_ido kitöltve');
T::egyezik(1, $GLOBALS['t_fizetve'][$id] ?? 0, 'hook pontosan egyszer');

// Túlfizetés
T::igaz(SDH_Sales_Rendeles::fizetve($id, 'bank_import', 'ref:A3', 700), 'túlfizetés rögzítve');
T::egyezik('tulfizetes', $wpdb->get_var($wpdb->prepare('SELECT allapot FROM ' . SDH_Sales_Schema::t('fizetes') . ' WHERE tranzakcio = %s', 'ref:A3')), 'túlfizetés jelölve');
T::egyezik(1, $GLOBALS['t_fizetve'][$id] ?? 0, 'túlfizetés nem indít újra');
T::igaz(SDH_Sales_Naplo::lista(['kereses' => 'Túlfizetés', 'ref_id' => $id])['ossz'] >= 1, 'túlfizetés naplózva');

// Egy lépésben túlfizetett rendelés
$r2 = t_rendeles();
SDH_Sales_Rendeles::fizetve((int) $r2['id'], 'kezi', 'k-1', (int) $r2['r']['brutto'] + 100);
T::egyezik('fizetve', SDH_Sales_Rendeles::rendeles((int) $r2['id'])['allapot'], 'egyben túlfizetve: fizetve');
T::igaz(SDH_Sales_Naplo::lista(['kereses' => 'többlet', 'ref_id' => (int) $r2['id']])['ossz'] === 1, 'többlet naplózva');

// Ismeretlen rendelés, rossz forrás
T::igaz(!SDH_Sales_Rendeles::fizetve(99999999, 'bank_import', 'ref:X', 100), 'ismeretlen rendelés: hamis');
T::egyezik('ismeretlen', $wpdb->get_var($wpdb->prepare('SELECT allapot FROM ' . SDH_Sales_Schema::t('fizetes') . ' WHERE tranzakcio = %s', 'ref:X')), 'ismeretlen befizetés rögzítve');
T::igaz(!SDH_Sales_Rendeles::fizetve($id, 'bitcoin', 'x', 1), 'ismeretlen forrás elutasítva');

// Törölt rendelésre késve érkezett pénz
$r3 = t_rendeles();
SDH_Sales_Rendeles::allapot_valt((int) $r3['id'], 'torolve');
SDH_Sales_Rendeles::fizetve((int) $r3['id'], 'bank_import', 'ref:T1', (int) $r3['r']['brutto']);
T::egyezik('fizetve', SDH_Sales_Rendeles::rendeles((int) $r3['id'])['allapot'], 'törölt rendelés feléled a fizetéssel');

// Nulla összegű rendelés (100%-os kupon) azonnal fizetve
$r4 = t_rendeles(['opciok' => ['ciklus' => 'havi', 'kupon' => 'NAGYFIX']]);
T::egyezik('fizetve', $r4['allapot'], 'nulla összegű rendelés: fizetve');
T::egyezik(1, $GLOBALS['t_fizetve'][(int) $r4['id']] ?? 0, 'nulla összegnél is egyszer');

// Admin kétlépcsős „Fizetve” REST
$r5 = t_rendeles();
$v  = t_rest('POST', '/admin/rendeles/' . (int) $r5['id'] . '/fizetve', []);
T::egyezik(200, $v->get_status(), 'admin fizetve 200');
T::egyezik('fizetve', SDH_Sales_Rendeles::rendeles((int) $r5['id'])['allapot'], 'admin fizetve → fizetve');
T::igaz((bool) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('fizetes') . " WHERE rendeles_id = %d AND forras = 'kezi' AND tranzakcio LIKE %s", (int) $r5['id'], 'kezi-%')), 'kezi-<user>-<idő> tranzakció');
T::egyezik(409, t_rest('POST', '/admin/rendeles/' . (int) $r5['id'] . '/fizetve', [])->get_status(), 'másodszor 409');
T::egyezik(401, t_latogato(static fn () => t_rest('POST', '/admin/rendeles/' . (int) $r5['id'] . '/fizetve', []))->get_status(), 'látogató nem hívhatja');

// Versenyhelyzet: 6 párhuzamos folyamat ugyanarra a rendelésre (3 különböző tranzakció, mindegyik kétszer),
// mind a teljes összeggel. Elvárás: pontosan 1 állapotváltás / hook, 3 fizetés-sor.
$r6  = t_rendeles();
$id6 = (int) $r6['id'];
$b6  = (int) $r6['r']['brutto'];
$dir = sys_get_temp_dir() . '/t213-' . getmypid();
wp_mkdir_p($dir);
$szkript = $dir . '/verseny.php';
file_put_contents($szkript, '<?php
$_SERVER["HTTP_HOST"] = "127.0.0.1:8765"; $_SERVER["REQUEST_URI"] = "/wp/"; $_SERVER["REMOTE_ADDR"] = "203.0.113.9";
require getenv("SDH_WP_LOAD");
remove_all_actions("sdh_sales_fizetve");
add_filter("pre_wp_mail", "__return_true");
add_action("sdh_sales_fizetve", function ($id) { file_put_contents(' . var_export($dir, true) . ' . "/hook", "x", FILE_APPEND | LOCK_EX); });
$cel = (float) $argv[3];
while (microtime(true) < $cel) { usleep(500); }
$ok = SDH_Sales_Rendeles::fizetve((int) $argv[1], "bank_import", $argv[2], (int) $argv[4]);
echo $ok ? "[UJ]" : "[NEM]";
');
$cel = microtime(true) + 2.5;
$proc = [];
foreach (['V1', 'V2', 'V3', 'V1', 'V2', 'V3'] as $tr) {
    $proc[] = popen('SDH_WP_LOAD=' . escapeshellarg((string) getenv('SDH_WP_LOAD')) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($szkript) . ' ' . $id6 . ' ref:' . $tr . ' ' . $cel . ' ' . $b6 . ' 2>&1', 'r');
}
$ki = '';
foreach ($proc as $p) { $ki .= trim((string) stream_get_contents($p)); pclose($p); }
$hook = is_file($dir . '/hook') ? strlen((string) file_get_contents($dir . '/hook')) : 0;
T::egyezik(3, substr_count($ki, '[UJ]'), 'verseny: 3 új befizetés rögzítve, 3 ismétlés elutasítva (' . mb_substr($ki, 0, 200) . ')');
T::egyezik(1, $hook, 'verseny: a hook pontosan egyszer futott');
T::egyezik(3, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('fizetes') . ' WHERE rendeles_id = %d', $id6)), 'verseny: 3 fizetés-sor');
$x = SDH_Sales_Rendeles::rendeles($id6);
T::egyezik('fizetve', $x['allapot'], 'verseny után fizetve');
T::egyezik(3 * $b6, $x['fizetett'], 'verseny: minden befizetés összeadva (2 túlfizetés)');
array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);

T::vege('t213 fizetve');
