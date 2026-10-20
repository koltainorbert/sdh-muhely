<?php
/**
 * t291 – egykattintásos indítás: korlátozott ideiglenes fiók (szerep, jogok, 24 óra, titkos jelszó), jegy + rejtett
 * mező, Turnstile a szerveren, címenkénti indításkorlát, jelszó / profil zárolva, lejárat, demo / demo alapból ki.
 */
require __DIR__ . '/_t29-seged.php';

$ip = '192.0.2.44';
$jo = static fn (): array => ['bm_jegy' => SDH_Demo_Inditas::jegy(time() - 5), 'weboldal' => ''];

// Az indító oldal.
wp_set_current_user(0);
$html = SDH_Demo_Inditas::oldal_html();
T::tartalmaz($html, '<form method="post"', 'POST-űrlap');
T::tartalmaz($html, 'name="bm_jegy"', 'időbélyeges jegy');
T::tartalmaz($html, 'name="weboldal"', 'rejtett csapda-mező (Turnstile nélkül)');
T::nem_tartalmaz($html, 'challenges.cloudflare.com', 'kulcs nélkül nincs Turnstile-szkript');
T::nem_tartalmaz($html, 'demo</strong>', 'demo / demo alapból nem látszik');

// Gépi beküldés: túl gyors, csapda kitöltve, hamis jegy.
T::igaz(empty(SDH_Demo_Inditas::inditas(['bm_jegy' => SDH_Demo_Inditas::jegy(time())], $ip)['ok']), 'túl gyors beküldés elutasítva');
T::igaz(empty(SDH_Demo_Inditas::inditas(['bm_jegy' => SDH_Demo_Inditas::jegy(time() - 5), 'weboldal' => 'x'], $ip)['ok']), 'kitöltött csapda-mező elutasítva');
T::igaz(empty(SDH_Demo_Inditas::inditas(['bm_jegy' => (time() - 5) . '.hamis'], $ip)['ok']), 'hamis jegy elutasítva');

// Sikeres indítás: korlátozott fiók.
$r = SDH_Demo_Inditas::inditas($jo(), $ip);
T::igaz(!empty($r['ok']) && (int) $r['felhasznalo'] > 0, 'indítás sikeres');
$id = (int) ($r['felhasznalo'] ?? 0);
$u  = get_userdata($id);
T::egyezik([SDH_Demo::SZEREP], array_values((array) $u->roles), 'szerep: csak bemutato');
T::egyezik(['read', 'sdh_muhely_hasznal', 'sdh_muhely_admin'], SDH_Demo::szerep_jogai(), 'a szerep jogai (engedélylista)');

foreach (['manage_options', 'upload_files', 'edit_users', 'list_users', 'promote_users', 'create_users', 'activate_plugins', 'edit_posts'] as $jog) {
    T::igaz(!user_can($id, $jog), 'nincs joga: ' . $jog);
}

T::igaz(strpos($u->user_login, 'bemutato-') === 0 && str_ends_with($u->user_email, '@pelda.hu'), 'véletlen név, @pelda.hu');
$lejar = (int) get_user_meta($id, SDH_Demo::META_LEJAR, true);
T::igaz(abs($lejar - (time() + DAY_IN_SECONDS)) < 60, '24 óra élettartam');
T::igaz(!isset($r['jelszo']) && !isset($r['user_pass']), 'a jelszó nem kerül ki');
T::egyezik(1, (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM ' . SDH_Demo::tabla('naplo') . " WHERE tipus = 'inditas' AND felhasznalo = %d", $id)), 'indítás naplózva (IP-kivonattal)');
T::egyezik(32, strlen((string) get_user_meta($id, SDH_Demo::META_IP, true)), 'IP csak kivonatként');

// Jelszó, profil, e-mail zárolva.
T::egyezik(false, apply_filters('allow_password_reset', true, $id), 'jelszó-visszaállítás tiltva');
wp_set_current_user($id);
T::egyezik(false, (bool) apply_filters('show_password_fields', true, $u), 'jelszómező rejtve');
$hibak = new WP_Error();
do_action_ref_array('user_profile_update_errors', [&$hibak, true, $u]);
T::igaz($hibak->has_errors(), 'profilmentés elutasítva');
$regi = $u->user_pass;
wp_update_user(['ID' => $id, 'user_pass' => 'ujjelszo123', 'user_email' => 'mas@pelda.hu']);
clean_user_cache($id);
$most = get_userdata($id);
T::igaz($most->user_pass === $regi && $most->user_email === $u->user_email, 'jelszó és e-mail nem módosítható (saját kérésre)');
t_admin();

// Címenkénti indításkorlát.
SDH_Demo::ment(['max_inditas_ip' => 2]);
T::igaz(!empty(SDH_Demo_Inditas::inditas($jo(), $ip)['ok']), '2. indítás még mehet');
$harmadik = SDH_Demo_Inditas::inditas($jo(), $ip);
T::igaz(empty($harmadik['ok']) && (int) $harmadik['kod'] === 429, '3. indítás ugyanarról a címről: 429');
T::tartalmaz((string) ($harmadik['hiba'] ?? ''), 'Erről a címről ma már 2 bemutatót', 'érthető magyar üzenet');
T::igaz(!empty(SDH_Demo_Inditas::inditas($jo(), '192.0.2.45')['ok']), 'másik címről mehet');

// Turnstile: a szerveren ellenőrizve.
SDH_Demo::ment(['turnstile_kulcs' => '0x4AAAAAAA-teszt', 'max_inditas_ip' => 50]);
SDH_Demo::titok_ment('turnstile_titok', 'titkos-turnstile');
T::igaz(SDH_Demo::b('turnstile_titok') !== 'titkos-turnstile', 'a titkos kulcs titkosítva tárolva');
$html = SDH_Demo_Inditas::oldal_html();
T::tartalmaz($html, 'challenges.cloudflare.com/turnstile/v0/api.js', 'Turnstile-szkript');
T::tartalmaz($html, 'data-sitekey="0x4AAAAAAA-teszt"', 'webhelykulcs az űrlapon');
$kuldott = [];
t_http(static function (string $url, array $args) use (&$kuldott): ?array {
    if (strpos($url, 'challenges.cloudflare.com/turnstile/v0/siteverify') === false) {
        return null;
    }

    $kuldott = (array) $args['body'];

    return t_valasz(200, wp_json_encode(['success' => ($args['body']['response'] ?? '') === 'jo-token']));
});
T::igaz(empty(SDH_Demo_Inditas::inditas($jo(), '192.0.2.50')['ok']), 'Turnstile-token nélkül elutasítva');
T::igaz(empty(SDH_Demo_Inditas::inditas($jo() + ['cf-turnstile-response' => 'rossz'], '192.0.2.50')['ok']), 'rossz token elutasítva');
T::igaz(!empty(SDH_Demo_Inditas::inditas($jo() + ['cf-turnstile-response' => 'jo-token'], '192.0.2.50')['ok']), 'jó token: indítás');
T::egyezik(['secret' => 'titkos-turnstile', 'response' => 'jo-token', 'remoteip' => '192.0.2.50'], $kuldott, 'siteverify: titok + token + IP');

// Lejárat: kiléptetés.
$lejart = t_latogato('192.0.2.60');
update_user_meta($lejart, SDH_Demo::META_LEJAR, time() - 10);
T::igaz(SDH_Demo_Inditas::lejart($lejart), 'lejárt fiók felismerve');
SDH_Demo_Inditas::lejarat();
T::egyezik(0, get_current_user_id(), 'lejárt fiók kiléptetve');
t_admin();

// demo / demo: alapból ki; bekapcsolva létezik (jelszó: demo), kikapcsolva törlődik.
SDH_Demo::ment(['demo_demo' => false]);
SDH_Demo_Inditas::demo_demo_szinkron();
T::egyezik(false, get_user_by('login', 'demo'), 'demo fiók alapból nincs');
SDH_Demo::ment(['demo_demo' => true]);
SDH_Demo_Inditas::demo_demo_szinkron();
$demo = get_user_by('login', 'demo');
T::igaz($demo instanceof WP_User && wp_check_password('demo', $demo->user_pass, $demo->ID) && SDH_Demo::demo_felhasznalo((int) $demo->ID), 'bekapcsolva: demo / demo, bemutató szereppel');
T::tartalmaz(SDH_Demo_Inditas::oldal_html(), '<strong>demo</strong>', 'az indító oldal megemlíti');
SDH_Demo::ment(['demo_demo' => false]);
SDH_Demo_Inditas::demo_demo_szinkron();
T::egyezik(false, get_user_by('login', 'demo'), 'kikapcsolva törlődik');

T::vege('t291 bemutató-indítás');
