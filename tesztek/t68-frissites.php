<?php
/**
 * t68 – 0.74.0 (H4): frissítés a GitHubról.
 *
 * Hamis GitHub-válaszokkal (pre_http_request): verzió kiolvasása és gyorsítótára, hibák (nincs net, 404, korlát),
 * a WordPress frissítési rendszerébe kötés, a csomag mappájának átnevezése, privát tároló kulcsa, védelmek
 * (nem admin, git-példány), a Névjegy lapfül doboza. A tényleges csere nem fut (a tesztoldal pluginját nem írjuk felül).
 */

require __DIR__ . '/_keret.php';

$M = 'SDH_Muhely_Frissites';

foreach ([$M::OPT, $M::CACHE] as $o) {
    delete_option($o);
    delete_transient($o);
}

/* ---- 1. Fejléc-olvasás -------------------------------------------------- */
$fejlec = "<?php\n/**\n * Plugin Name:       SDH Műhely\n * Version:           1.2.3\n * Requires PHP:      8.1\n */\n";
T::egyezik('1.2.3', $M::verzio_fejlecbol($fejlec), 'a Version: fejléc kiolvasható');
T::egyezik('', $M::verzio_fejlecbol("<?php\n// semmi\n"), 'fejléc nélkül üres');
T::egyezik('', $M::verzio_fejlecbol("<?php\n * Version: <script>\n"), 'érvénytelen verziószöveg nem megy át');
T::egyezik('0.74.0', $M::verzio_fejlecbol(" * Version:           0.74.0\r\n"), 'Windows sorvégnél is jó');
T::egyezik('2.0.0-beta1', $M::verzio_fejlecbol(" * Version: 2.0.0-beta1\n"), 'előverzió-jelölés megmarad');

/* ---- 2. Hamis GitHub ---------------------------------------------------- */
$g = (object) ['kod' => 200, 'verzio' => '99.0.0', 'db' => 0, 'url' => '', 'fej' => [], 'hiba' => false];

add_filter('pre_http_request', static function ($elo, array $args, string $url) use ($g) {
    if (strpos($url, 'api.github.com') === false) {
        return $elo;
    }

    $g->db++;
    $g->url = $url;
    $g->fej = (array) ($args['headers'] ?? []);

    if ($g->hiba) {
        return new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host');
    }

    return ['headers' => [], 'body' => "<?php\n/**\n * Version:           {$g->verzio}\n */\n", 'response' => ['code' => $g->kod, 'message' => ''], 'cookies' => [], 'filename' => null];
}, 10, 3);

$uj = $M::uj_verzio(true);
T::egyezik('99.0.0', $uj['verzio'], 'az újabb verzió kiolvasva');
T::egyezik('', $uj['hiba'], 'siker: nincs hiba');
T::igaz(strpos($g->url, '/repos/' . $M::REPO . '/contents/sdh-muhely.php?ref=main') !== false, 'a kérés a tároló fő fájljára megy');
T::igaz(($g->fej['Accept'] ?? '') === 'application/vnd.github.raw+json' && strpos((string) ($g->fej['User-Agent'] ?? ''), 'sdh-muhely/') === 0, 'kérés-fejlécek: nyers tartalom + User-Agent');
T::igaz(!isset($g->fej['Authorization']), 'kulcs nélkül nincs Authorization fejléc');

$db = $g->db;
$M::uj_verzio();
$M::uj_verzio();
T::egyezik($db, $g->db, 'a gyorsítótár miatt nem kérdez újra');
$M::uj_verzio(true);
T::egyezik($db + 1, $g->db, 'kényszerített ellenőrzés újra kérdez');

T::igaz($M::van_ujabb(), '99.0.0 > telepített: van újabb');
$g->verzio = SDH_MUHELY_VERSION;
T::igaz(!$M::van_ujabb($M::uj_verzio(true)), 'azonos verzió: nincs újabb');
$g->verzio = '0.0.1';
T::igaz(!$M::van_ujabb($M::uj_verzio(true)), 'régebbi verzió: nincs újabb (nincs visszalépés)');

// Hibák
foreach ([[404, 'nem található'], [403, 'korlátoz'], [500, 'HTTP 500']] as [$kod, $resz]) {
    $g->kod = $kod;
    $r = $M::uj_verzio(true);
    T::igaz($r['verzio'] === '' && strpos($r['hiba'], $resz) !== false, "HTTP $kod: érthető hiba");
}

$g->kod = 200;
$g->hiba = true;
$r = $M::uj_verzio(true);
T::igaz($r['verzio'] === '' && strpos($r['hiba'], 'nem érhető el') !== false, 'nincs internet: érthető hiba, nem dob kivételt');
T::igaz(!$M::van_ujabb($r), 'hibás ellenőrzésnél nincs frissítési ajánlat');
$db = $g->db;
$M::uj_verzio();
T::egyezik($db, $g->db, 'a hiba is gyorsítótárba kerül (nem ostromolja a netet)');
$g->hiba = false;

/* ---- 3. Forrás és privát tároló ------------------------------------------ */
$g->verzio = '99.0.0';
delete_transient($M::CACHE);
T::egyezik(['repo' => $M::REPO, 'ag' => 'main', 'token' => ''], $M::forras(), 'alapértelmezett forrás');
T::egyezik('https://api.github.com/repos/' . $M::REPO . '/zipball/main', $M::csomag_url(), 'a csomag a GitHub zipball-ja');

update_option($M::OPT, ['repo' => 'valaki/masik-repo', 'ag' => 'stabil', 'token' => SDH_Muhely_Titok::titkosit('ghp_TESZTKULCS')], false);
T::egyezik('valaki/masik-repo', $M::forras()['repo'], 'a tároló beállítható');
T::egyezik('ghp_TESZTKULCS', $M::forras()['token'], 'a kulcs titkosítva tárolt, visszafejthető');
$M::uj_verzio(true);
T::egyezik('Bearer ghp_TESZTKULCS', $g->fej['Authorization'] ?? '', 'privát tárolónál a kulcs megy az ellenőrzéssel');
T::igaz(strpos($g->url, '/repos/valaki/masik-repo/contents/sdh-muhely.php?ref=stabil') !== false, 'a beállított tároló és ág szerepel az URL-ben');

$h = $M::kerelem_fejlec(['headers' => []], 'https://api.github.com/repos/valaki/masik-repo/zipball/stabil');
T::egyezik('Bearer ghp_TESZTKULCS', $h['headers']['Authorization'] ?? '', 'a csomag letöltése is hitelesített');
$h = $M::kerelem_fejlec(['headers' => []], 'https://masik.example.com/repos/valaki/masik-repo/zipball/x');
T::igaz(!isset($h['headers']['Authorization']), 'idegen címre a kulcs nem megy ki');
$h = $M::kerelem_fejlec(['headers' => []], 'https://api.github.com/repos/mas/tarolo/zipball/x');
T::igaz(!isset($h['headers']['Authorization']), 'másik tárolóra a kulcs nem megy ki');

update_option($M::OPT, ['repo' => '../etc/passwd; x', 'ag' => 'a b;c'], false);
T::egyezik(['repo' => $M::REPO, 'ag' => 'main'], array_intersect_key($M::forras(), ['repo' => 1, 'ag' => 1]), 'szabálytalan tároló / ág érték nem kerül az URL-be');
delete_option($M::OPT);

add_filter('sdh_muhely_frissites_forras', static fn (array $f): array => ['repo' => 'szuro/proba'] + $f);
T::egyezik('szuro/proba', $M::forras()['repo'], 'a szűrő felülírhatja a forrást');
remove_all_filters('sdh_muhely_frissites_forras');

/* ---- 4. WordPress frissítési rendszer -------------------------------------- */
delete_transient($M::CACHE);
$g->verzio = '99.0.0';
$t = (object) ['response' => [], 'no_update' => [], 'checked' => []];
$t = $M::szuro($t);
$e = $t->response[SDH_MUHELY_BASENAME] ?? null;
T::igaz(is_object($e) && $e->new_version === '99.0.0' && $e->slug === 'sdh-muhely' && $e->package === $M::csomag_url(), 'újabb verziónál a „Frissítés elérhető” bejegyzés megvan');
T::igaz(!isset($t->no_update[SDH_MUHELY_BASENAME]), 'ilyenkor nincs no_update bejegyzés');

delete_transient($M::CACHE);
$g->verzio = SDH_MUHELY_VERSION;
$t = $M::szuro((object) ['response' => [SDH_MUHELY_BASENAME => (object) ['x' => 1]], 'no_update' => []]);
T::igaz(!isset($t->response[SDH_MUHELY_BASENAME]) && isset($t->no_update[SDH_MUHELY_BASENAME]), 'naprakész rendszernél a bejegyzés no_update-be kerül');
T::egyezik('x', $M::szuro('x'), 'nem objektumot érintetlenül hagy');

$p = $M::plugin_adatok(false, 'plugin_information', (object) ['slug' => 'sdh-muhely']);
T::igaz(is_object($p) && $p->slug === 'sdh-muhely' && $p->download_link === $M::csomag_url(), 'a bővítmény-információs ablak adatot kap');
T::egyezik(false, $M::plugin_adatok(false, 'plugin_information', (object) ['slug' => 'masik']), 'más bővítményhez nem nyúl');

delete_transient($M::CACHE);
T::egyezik('', $M::ismert_ujabb(), 'üres gyorsítótárnál az oldalbetöltés nem kérdez a netről');
$db = $g->db;
$M::ismert_ujabb();
T::egyezik($db, $g->db, 'az ismert_ujabb soha nem hív a netre');
$g->verzio = '99.0.0';
$M::uj_verzio(true);
T::egyezik('99.0.0', $M::ismert_ujabb(), 'gyorsítótárból megmondja az újabb verziót');

/* ---- 5. A csomag mappájának átnevezése ------------------------------------ */
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
global $wp_filesystem;
$wp_filesystem = new WP_Filesystem_Direct(null);

$tmp = sys_get_temp_dir() . '/sdh-t68-' . wp_generate_password(8, false);
mkdir($tmp . '/koltainorbert-sdh-muhely-abc1234', 0777, true);
file_put_contents($tmp . '/koltainorbert-sdh-muhely-abc1234/sdh-muhely.php', "<?php\n/** Version: 99.0.0 */\n");
$extra = ['plugin' => SDH_MUHELY_BASENAME, 'type' => 'plugin', 'action' => 'update'];

$r = $M::forrasmappa($tmp . '/koltainorbert-sdh-muhely-abc1234/', $tmp, null, $extra);
T::igaz(is_string($r) && substr($r, -12) === '/sdh-muhely/' && is_file($r . 'sdh-muhely.php'), 'a GitHub-os mappanév sdh-muhely-re cserélve');
T::igaz(!is_dir($tmp . '/koltainorbert-sdh-muhely-abc1234'), 'a régi mappanév megszűnt');

mkdir($tmp . '/valami-mas-123', 0777, true);
file_put_contents($tmp . '/valami-mas-123/masik.php', '<?php');
$r = $M::forrasmappa($tmp . '/valami-mas-123/', $tmp, null, $extra);
T::igaz($r instanceof WP_Error, 'fő fájl nélküli csomagot visszautasít (nem írja felül vele a bővítményt)');
T::egyezik($tmp . '/valami-mas-123/', $M::forrasmappa($tmp . '/valami-mas-123/', $tmp, null, ['plugin' => 'masik/masik.php']), 'más bővítmény frissítéséhez nem nyúl');

foreach (glob($tmp . '/*/*') ?: [] as $f) {
    @unlink($f);
}

foreach (glob($tmp . '/*') ?: [] as $d) {
    @rmdir($d);
}

@rmdir($tmp);

/* ---- 6. Védelmek ---------------------------------------------------------- */
T::igaz($M::git_peldany(), 'a tesztgépen a bővítmény git-tároló: itt a gomb nem frissít');

$g->verzio = '99.0.0';
$v = t_ajax('sdh_muhely_frissites_ellenorzes');
T::igaz(!empty($v['success']) && $v['data']['verzio'] === '99.0.0', 'AJAX ellenőrzés: kiírja az újabb verziót');
T::igaz($v['data']['ujabb'] === false, 'git-példányon az ellenőrzés nem ajánl gombos frissítést');

$v = t_ajax('sdh_muhely_frissites_indit');
T::igaz(empty($v['success']) && strpos((string) ($v['data']['uzenet'] ?? ''), 'git') !== false, 'git-példányon a frissítés elutasítva');

add_filter('sdh_muhely_frissites_git', '__return_false');
$g->verzio = SDH_MUHELY_VERSION;
$v = t_ajax('sdh_muhely_frissites_ellenorzes');
T::igaz(!empty($v['success']) && $v['data']['ujabb'] === false && strpos($v['data']['uzenet'], 'naprakész') !== false, 'naprakész rendszer: „naprakész” üzenet');
$v = t_ajax('sdh_muhely_frissites_indit');
T::igaz(empty($v['success']) && strpos((string) ($v['data']['uzenet'] ?? ''), 'Nincs újabb') !== false, 'ha nincs újabb verzió, nem indul frissítés');
$g->hiba = true;
$v = t_ajax('sdh_muhely_frissites_indit');
T::igaz(empty($v['success']) && strpos((string) ($v['data']['uzenet'] ?? ''), 'nem érhető el') !== false, 'net nélkül a frissítés érthető hibával megáll');
$g->hiba = false;

$regi = get_current_user_id();
wp_set_current_user(0);
$v = t_ajax('sdh_muhely_frissites_indit');
T::igaz(empty($v['success']), 'nem adminisztrátor nem indíthat frissítést');
$v = t_ajax('sdh_muhely_frissites_ellenorzes');
T::igaz(empty($v['success']), 'nem adminisztrátor nem ellenőrizhet');
wp_set_current_user($regi);

$v = t_ajax('sdh_muhely_frissites_ellenorzes', [], '_wpnonce', 'rossz_akcio');
T::igaz(empty($v['success']), 'rossz nonce-szal nincs ellenőrzés');
remove_all_filters('sdh_muhely_frissites_git');

/* ---- 7. Felület ----------------------------------------------------------- */
$g->verzio = '99.0.0';
delete_transient($M::CACHE);
$M::uj_verzio(true);
add_filter('sdh_muhely_frissites_git', '__return_false');
ob_start();
SDH_Muhely_Beallitasok::rendszer_doboz();
$html = (string) ob_get_clean();
T::tartalmaz($html, 'data-sdh-frissites', 'a Névjegy lapfül tartalmazza a Frissítés dobozt');
T::tartalmaz($html, SDH_MUHELY_VERSION, 'kiírja a telepített verziót');
T::tartalmaz($html, 'Frissítés elérhető: 99.0.0.', 'kiírja az elérhető verziót');
T::igaz(preg_match('/data-sdh-frissites-indit(?! hidden)/', $html) === 1, 'a „Frissítés most” gomb látszik, ha van újabb');
T::tartalmaz($html, 'github.com/' . $M::REPO, 'kiírja a forrást');

remove_all_filters('sdh_muhely_frissites_git');
add_filter('sdh_muhely_frissites_git', '__return_true');
ob_start();
SDH_Muhely_Beallitasok::rendszer_doboz();
$html = (string) ob_get_clean();
T::tartalmaz($html, 'git pull', 'git-példányon a doboz a git pull-ra utal');
T::igaz(strpos($html, 'data-sdh-frissites-indit hidden') !== false, 'git-példányon a „Frissítés most” gomb rejtett');
remove_all_filters('sdh_muhely_frissites_git');

foreach ([$M::OPT, $M::CACHE] as $o) {
    delete_option($o);
    delete_transient($o);
}

T::vege('t68 frissítés a GitHubról (H4): verzió, hibák, WordPress-bekötés, csomagmappa, védelmek, felület');
