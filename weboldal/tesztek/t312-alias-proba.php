<?php
/**
 * t312 – Útvonal-álnevek csak bizonyítottan működő webszerver mellett (2026-10-20, Local/nginx: stílus nélküli, lassú oldal):
 * - a próba (alias_proba) 404 / idegen tartalom / hálózati hiba esetén „sikertelen”, a valódi fájl esetén „sikeres”,
 * - sikertelen próba mellett a kimenet a valódi címeket tartja meg, és az Apache-szabályokba nem kerül a valódi útvonalak tiltása,
 * - sikeres próba mellett a kimenet álneves, a tiltás bekerül.
 */
require __DIR__ . '/_keret.php';

$R = 'SDH_Sales_Rejtes';

// A _keret „sikeres”-re rögzíti a próbát – itt a valódi opcióval dolgozunk.
remove_all_filters('pre_option_' . $R::OPT_ALIAS);
delete_option($R::OPT_ALIAS);

// Nyers címek (a content_url/includes_url szűrői az init-kor, még „sikeres” próbával kerültek fel).
$minta = '<html><head><link rel="stylesheet" href="' . WP_CONTENT_URL . '/plugins/x/a.css"><script src="' . site_url('/wp-includes/js/b.js') . '"></script></head></html>';

T::igaz(!$R::alias_aktiv(), 'próba nélkül az álnevek nem élnek');
T::tartalmaz($R::kimenet($minta), '/wp-content/plugins/x/a.css', 'próba nélkül: a valódi cím marad');
T::nem_tartalmaz($R::htaccess(''), 'THE_REQUEST', 'próba nélkül: nincs valódi-útvonal-tiltás');

/* --- Próba hamis válaszokkal --------------------------------------------------------------------------------------- */

$fajl  = SDH_SALES_DIR . 'assets/front/kozos.js';
$valasz = null;
t_http(static function (string $url) use (&$valasz) {
    return strpos($url, 'kozos.js') !== false ? $valasz : null;
});

$valasz = t_valasz(404, 'nincs');
$p = $R::alias_proba();
T::igaz(!$p['ok'] && strpos($p['uzenet'], '404') !== false, 'próba: 404 → sikertelen, az üzenet a kódot mondja');
T::igaz(strpos($p['url'], '/wp-content/') === false, 'próba: az álnév-címet kérdezi');
T::igaz(!$R::alias_aktiv(), '404 után az álnevek nem élnek');

$valasz = t_valasz(200, '<!doctype html><title>Kezdőlap</title>');
T::igaz(!$R::alias_proba()['ok'], 'próba: 200, de idegen tartalom (a 404-oldal) → sikertelen');

$valasz = new WP_Error('http_request_failed', 'cURL error 7');
T::igaz(!$R::alias_proba()['ok'], 'próba: hálózati hiba → sikertelen');

$valasz = t_valasz(200, (string) file_get_contents($fajl));
T::igaz($R::alias_proba()['ok'], 'próba: 200 + a valódi fájl → sikeres');
T::igaz($R::alias_aktiv(), 'sikeres próba után az álnevek élnek');
T::nem_tartalmaz($R::kimenet($minta), '/wp-content/', 'sikeres próba után: a kimenet álneves');
T::nem_tartalmaz($R::kimenet($minta), '/wp-includes/', 'sikeres próba után: a lib-cím is álneves');
T::tartalmaz($R::htaccess(''), 'THE_REQUEST', 'sikeres próba után: a valódi útvonalak tiltása bekerül');

/* --- Újrapróba ütemezése ------------------------------------------------------------------------------------------- */

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));
$valasz = t_valasz(404, 'nincs');
$R::alias_proba_ha_kell();
T::igaz($R::alias_aktiv(), 'friss sikeres próba után egy órán belül nincs újrapróba');
update_option($R::OPT_ALIAS, ['ok' => true, 'ido' => time() - 2 * HOUR_IN_SECONDS, 'uzenet' => '', 'url' => '']);
$R::alias_proba_ha_kell();
T::igaz(!$R::alias_aktiv(), 'egy óra után újrapróba – a szabály eltűnt → az álnevek kikapcsolnak');

T::vege('t312 útvonal-álnév próba');
