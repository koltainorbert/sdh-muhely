<?php
/**
 * t331 – Szerkesztő mód: SDH_Sales_Studio::attr() látogatónak és az előnézeten kívül üres; csak érvényes,
 * saját előnézeti tokennel él; az út-formátum ellenőrzött, escapelt; a szekció-jelölő csak módban;
 * a lebegő „Szerkesztés” gomb csak a bejelentkezett szerkesztőnek.
 */
require __DIR__ . '/_keret.php';

$S = 'SDH_Sales_Studio';

$S::mod_ki();
T::egyezik('', $S::attr('o:1/0/cim'), 'mód nélkül üres (admin)');
T::egyezik('', t_latogato(fn () => $S::attr('o:1/0/cim')), 'látogatónak üres');

// Látogató nem kapcsolhatja be (mod_be jogosultságot ellenőriz).
t_latogato(function () use ($S) {
    $S::mod_be();
    T::igaz(!$S::mod(), 'látogató nem kapcsolhat szerkesztő módot');
    T::egyezik('', $S::attr('o:1/0/cim'), 'látogató: attr üres');
});
$S::mod_ki();

$S::mod_be();
T::igaz($S::mod(), 'admin: mód be');
T::egyezik(' data-s-szerk="o:12/3/cim"', $S::attr('o:12/3/cim'), 'szekciómező');
T::egyezik(' data-s-szerk="o:12/3/elemek/2/cim"', $S::attr('o:12/3/elemek/2/cim'), 'listaelem');
T::egyezik(' data-s-szerk="f:fejlec.demo"', $S::attr('f:fejlec.demo'), 'felirat');
T::egyezik(' data-s-szerk="b:ceg.nev"', $S::attr('b:ceg.nev'), 'beállítás');
T::egyezik(' data-s-szerk="m:fo/2"', $S::attr('m:fo/2'), 'menüpont');
T::egyezik(' data-s-szerk="t:csomag/pro/nev"', $S::attr('t:csomag/pro/nev'), 'tartalomtár');
foreach (['', 'x:1/2', 'o:"><script>', 'o:1/2/cim onmouseover=alert(1)', 'f:../../etc', 'o:' . str_repeat('a', 300), "o:1/0/c\nim"] as $rossz) {
    T::egyezik('', $S::attr($rossz), 'érvénytelen út üres: ' . substr(json_encode($rossz), 0, 40));
}

// Jelölő a szekció-sablon előtt (csak módban).
ob_start();
$S::jelolo(['tipus' => 'hero', '_i' => 4], 'hero');
T::egyezik('<template data-s-szakasz="4"></template>', ob_get_clean(), 'jelölő módban');
$S::mod_ki();
ob_start();
$S::jelolo(['tipus' => 'hero', '_i' => 4], 'hero');
T::egyezik('', ob_get_clean(), 'jelölő mód nélkül: semmi');

// Előnézeti token: csak a létrehozó adminnak kapcsolja be a módot.
$pid = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'T331', 'post_name' => 't331']);
$r   = t_rest('POST', '/admin/elonezet', ['post_id' => $pid, 'szekciok' => [['tipus' => 'hero', 'cim' => 'Előnézet']], 'feliratok' => ['t331.proba' => 'Előnézeti felirat']]);
T::egyezik(200, $r->get_status(), 'előnézet létrejön');
$tok = $r->get_data()['token'];
$_GET[SDH_Sales_Admin_Tartalom::ELONEZET] = $tok;
SDH_Sales_Admin_Tartalom::elonezet_indit();
T::igaz($S::mod(), 'érvényes tokennel: szerkesztő mód');
T::egyezik('Előnézeti felirat', $S::felirat('t331.proba', 'alap'), 'az előnézet még nem mentett felirata él');
T::egyezik([], array_diff_key((array) get_option('sdh_sales_feliratok_nincs', []), []), '(más opció érintetlen)');
$S::mod_ki();
T::egyezik('alap', $S::felirat('t331.proba', 'alap'), 'mód után a felirat a mentett (alap)');

$masik = wp_insert_user(['user_login' => 't331-admin2', 'user_pass' => wp_generate_password(), 'user_email' => 't331b@example.com', 'role' => 'administrator']);
$elozo = get_current_user_id();
wp_set_current_user((int) $masik);
SDH_Sales_Admin_Tartalom::elonezet_indit();
T::igaz(!$S::mod(), 'más admin a tokennel nem kap szerkesztő módot');
wp_set_current_user($elozo);
unset($_GET[SDH_Sales_Admin_Tartalom::ELONEZET]);

// Lebegő „Szerkesztés” gomb: látogatónak semmi; adminnak egy semleges link a Stúdióba.
$GLOBALS['wp_query'] = new WP_Query(['page_id' => $pid]);
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
ob_start();
t_latogato(fn () => $S::szerk_gomb());
T::egyezik('', ob_get_clean(), 'látogató: nincs szerkesztés gomb');
ob_start();
$S::szerk_gomb();
$g = ob_get_clean();
T::tartalmaz($g, 's-stud-gomb', 'admin: van szerkesztés gomb');
T::tartalmaz($g, 'oldalak/' . $pid, 'a gomb az oldal építőjét nyitja');
T::igaz(preg_match('/sdh|wordpress/i', preg_replace('/href="[^"]*"/', '', $g)) !== 1, 'a gomb (a cím nélkül) semleges');
$S::mod_be();
ob_start();
$S::szerk_gomb();
T::egyezik('', ob_get_clean(), 'előnézetben nincs gomb');
$S::mod_ki();

T::vege('t331 szerkesztő mód és jelölés');
