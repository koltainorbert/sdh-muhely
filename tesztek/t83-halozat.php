<?php
/**
 * t83 – H12 (0.91.0): netkimaradás – helyi címek, gyors hiba külső kérésre, pulzus-jelzés, időzített kör kihagyása.
 */

require __DIR__ . '/_keret.php';

$H = 'SDH_Muhely_Halozat';

foreach (['http://localhost/x', 'http://127.0.0.1:8080/', 'http://192.168.1.50:8082/send', 'http://10.0.0.5/', 'http://telefon.local/api', 'http://sms-telefon/', home_url('/wp-cron.php')] as $c) {
    T::igaz($H::helyi($c), 'helyi: ' . $c);
}
foreach (['https://www.szamlazz.hu/szamla/', 'https://api.anthropic.com/v1/messages', 'https://8.8.8.8/', 'https://api.github.com/repos/x'] as $c) {
    T::igaz(!$H::helyi($c), 'külső: ' . $c);
}

// Nincs net (kényszerítve).
$offline = static fn (): string => 'offline';
add_filter('sdh_muhely_halozat_kenyszer', $offline, 20);
$H::urit();
T::egyezik(false, $H::online(), 'nincs net');
$r = wp_remote_get('https://www.szamlazz.hu/szamla/', ['timeout' => 30]);
T::igaz(is_wp_error($r) && $r->get_error_code() === 'sdh_nincs_internet', 'külső kérés: azonnali hiba');
T::tartalmaz(is_wp_error($r) ? $r->get_error_message() : '', 'Nincs internetkapcsolat', 'érthető üzenet');
$atment = false;
$fogo = static function ($e, $a, $u) use (&$atment) {
    $atment = true;

    return ['headers' => [], 'body' => 'ok', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
};
add_filter('pre_http_request', $fogo, 10, 3);
$r2 = wp_remote_get('http://192.168.1.50:8082/send');
T::igaz($atment && !is_wp_error($r2), 'helyi kérés (bolti SMS-telefon) net nélkül is megy');
$atment = false;
T::egyezik(0, SDH_Muhely_Elegedettseg::esedekes_kuld(), 'elégedettségi kör kimarad');
$p = $H::pulzus([]);
T::egyezik(false, $p['net']['online'] ?? null, 'pulzus: nincs net');
T::igaz(count($H::lista()['megy']) >= 5 && count($H::lista()['var']) >= 5, 'mi megy / mi vár lista');

// Van net.
remove_filter('sdh_muhely_halozat_kenyszer', $offline, 20);
$H::urit();
T::egyezik(true, $H::online(), 'van net');
wp_remote_get('https://www.szamlazz.hu/szamla/');
T::igaz($atment, 'külső kérés: átmegy');
T::egyezik(true, $H::pulzus([])['net']['online'] ?? null, 'pulzus: van net');
remove_filter('pre_http_request', $fogo, 10);

ob_start();
SDH_Muhely_Csomag::beallitas_doboz();
T::tartalmaz((string) ob_get_clean(), 'data-sdh-net-allapot', 'a Sebesség lapon az állapot');

T::vege('t83 netkimaradás (H12)');
