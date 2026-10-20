<?php
/**
 * t296 – Számlázz.hu és a hálózati kapu: tesztkulcs nélkül semmi nem megy a Számlázz.hu-ra (érthető üzenet),
 * a valódi kulcs helyett a bemutató tesztkulcsa, minden előtag „MINTA”, a vevő nem kap levelet; tesztkulccsal csak az
 * a kérés megy ki, amelyikben a tesztkulcs áll; minden más külső cím tiltva (a Turnstile és a saját webhely kivétel).
 */
require __DIR__ . '/_t29-seged.php';

// A valódi kulcs a nyers opcióban – a bemutató szűrője elrejti.
remove_filter('option_sdh_muhely_szamla', [SDH_Demo::class, 'szamla_opcio']);
update_option('sdh_muhely_szamla', ['kulcs' => SDH_Muhely_Titok::titkosit('VALODI-AGENT-KULCS'), 'elotag_fo' => 'SZV', 'nyomt_elotag' => 'SDE', 'email_kuldes' => true]);
add_filter('option_sdh_muhely_szamla', [SDH_Demo::class, 'szamla_opcio']);
SDH_Demo::felejt();

$b = SDH_Muhely_Szamla::beallitas();
T::egyezik('', $b['kulcs'], 'a valódi Agent-kulcs nem használható');
T::igaz(!SDH_Muhely_Szamla::beallitva(), 'a számlázás „nincs beállítva” (valódi számla nem készülhet)');
T::egyezik('MINTA', $b['elotag_fo'], 'számla előtag: MINTA');
T::egyezik('MINTA', $b['nyomt_elotag'], 'helyi nyomtatvány előtag: MINTA');
T::egyezik(false, (bool) $b['email_kuldes'], 'a vevő nem kap levelet');
T::igaz(strpos(SDH_Muhely_Szamla::nyomtatvany_szam((object) ['munkalap_szam' => 12]), 'MINTA-') === 0, 'a nyomtatvány száma MINTA-…');

// Kulcs nélkül: a Számlázz.hu felé semmi.
$r = wp_remote_post('https://www.szamlazz.hu/szamla/', ['body' => '<xmlszamla><szamlaagentkulcs>VALODI-AGENT-KULCS</szamlaagentkulcs></xmlszamla>']);
T::igaz(is_wp_error($r) && $r->get_error_code() === 'bemutato_szamla', 'Számlázz.hu tiltva (tesztkulcs nélkül)');
T::tartalmaz(is_wp_error($r) ? $r->get_error_message() : '', 'valódi számla nem készül', 'érthető magyar üzenet');
T::egyezik([], array_values(array_filter($GLOBALS['bm_t_keresek'], static fn ($k): bool => strpos($k['url'], 'szamlazz') !== false)), 'a kérés el sem indult');

// Tesztkulccsal: csak a tesztkulcsos kérés megy (a bemutató küldője – itt hamis).
SDH_Demo::titok_ment('szamlazz_teszt_kulcs', 'TESZTFIOK-KULCS-123');
SDH_Demo::felejt();
T::egyezik('TESZTFIOK-KULCS-123', SDH_Muhely_Szamla::beallitas()['kulcs'], 'a számlázás a tesztkulcsot kapja');
T::egyezik('MINTA', SDH_Muhely_Szamla::beallitas()['elotag_fo'], 'tesztkulccsal is MINTA előtag');
$kuldve = [];
$kuldo = static function (string $url, array $args) use (&$kuldve): array {
    $kuldve[] = $url;

    return t_valasz(200, '<ok/>') + ['headers' => [], 'cookies' => [], 'filename' => null];
};
add_filter('sdh_demo_szamlazz_kuldo', static fn () => $kuldo);
$r = wp_remote_post('https://www.szamlazz.hu/szamla/', ['body' => '<xmlszamla><szamlaagentkulcs>TESZTFIOK-KULCS-123</szamlaagentkulcs></xmlszamla>']);
T::igaz(!is_wp_error($r) && count($kuldve) === 1, 'tesztkulcsos kérés kimegy (a tesztüzem ellenére)');
$r = wp_remote_post('https://www.szamlazz.hu/szamla/', ['body' => '<xmlszamla><szamlaagentkulcs>MAS-KULCS</szamlaagentkulcs></xmlszamla>']);
T::igaz(is_wp_error($r) && count($kuldve) === 1, 'más kulccsal tiltva');

// Minden más külső cím tiltva.
foreach (['https://api.anthropic.com/v1/messages', 'https://www.googleapis.com/upload/drive/v3/files', 'http://192.168.1.50:8080/messages', 'https://kozpont.pelda.hu/licenc/v1/jelentes', 'https://downloads.wordpress.org/x.zip'] as $url) {
    $r = wp_remote_get($url);
    T::igaz(is_wp_error($r) && $r->get_error_code() === 'bemutato_halozat', 'tiltva: ' . wp_parse_url($url, PHP_URL_HOST));
}

T::tartalmaz(SDH_Demo::HALOZAT_UZENET, 'A saját rendszeredben ez működik', 'a hálózati üzenet érthető');

// A Turnstile ellenőrzése és a saját webhely mehet (a teszt hamis hálózatához jut el).
t_http(static fn (string $url): ?array => strpos($url, 'challenges.cloudflare.com') !== false || strpos($url, home_url()) === 0 ? t_valasz(200, '{}') : null);
T::igaz(!is_wp_error(wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['body' => []])), 'Turnstile mehet');
T::igaz(!is_wp_error(wp_remote_get(home_url('/?x=1'))), 'a saját webhely mehet');

T::vege('t296 bemutató-számlázás és hálózat');
