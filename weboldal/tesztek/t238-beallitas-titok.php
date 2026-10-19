<?php
/** t238 – Beállítások: fehérlista, termeknev, titok csak „beállítva”, Rejtés-csoport ellenőrzése, idegen csoport (szűrő). */
require __DIR__ . '/_keret.php';

$v = t_rest('POST', '/admin/beallitasok', ['ertekek' => ['termeknev' => 'Munkora <b>X</b>', 'afa' => '150', 'nem_letezo' => 'x', 'ceg.email' => 'rossz', 'fizetes.atutalas' => '0']]);
T::egyezik(422, $v->get_status(), 'érvénytelen e-mail → 422');
$v = t_rest('POST', '/admin/beallitasok', ['ertekek' => ['termeknev' => 'Munkora <b>X</b>', 'afa' => '150', 'nem_letezo' => 'x', 'fizetes.atutalas' => '0', 'nyelvek' => ['hu', 'xx']]]);
T::egyezik(200, $v->get_status(), 'mentés');
T::egyezik('Munkora X', SDH_Sales::termeknev(), 'termeknev tisztítva');
T::egyezik(100, SDH_Sales::afa(), 'áfa korlát');
T::egyezik(null, SDH_Sales::b('nem_letezo'), 'csak fehérlista');
T::egyezik(false, SDH_Sales::b('fizetes.atutalas'), 'kapcsoló');
T::egyezik(['hu'], SDH_Sales::b('nyelvek'), 'nyelvek');
T::egyezik(422, t_rest('POST', '/admin/beallitasok', ['ertekek' => ['termeknev' => '']])->get_status(), 'kötelező termeknev');

$v = t_rest('POST', '/admin/titok', ['kulcs' => 'szamlazz_agent_kulcs', 'ertek' => 'titkos-agent-123']);
T::egyezik(true, $v->get_data()['beallitva'], 'titok beállítva');
$j = (string) wp_json_encode(t_rest('GET', '/admin/beallitasok')->get_data());
T::nem_tartalmaz($j, 'titkos-agent-123', 'titok nincs a válaszban');
T::egyezik(true, t_rest('GET', '/admin/beallitasok')->get_data()['titkok']['szamlazz_agent_kulcs'], '„beállítva” jelzés');
T::egyezik('titkos-agent-123', SDH_Sales::titkos('szamlazz_agent_kulcs'), 'titkosítva tárolva');
T::egyezik(400, t_rest('POST', '/admin/titok', ['kulcs' => 'termeknev', 'ertek' => 'x'])->get_status(), 'nem titok kulcs');
t_rest('POST', '/admin/titok', ['kulcs' => 'szamlazz_agent_kulcs', 'ertek' => '']);
T::egyezik('', SDH_Sales::titkos('szamlazz_agent_kulcs'), 'titok törölve');

// Rejtés-csoport
$cs = SDH_Sales_Admin_Beallitas::csoportok();
T::igaz(isset($cs['rejtes']), 'Rejtés csoport');
$k = array_column($cs['rejtes']['mezok'], 'k');
foreach (['rejtes.aktiv', 'rejtes.api', 'rejtes.belepo', 'rejtes.alias', 'rejtes.kimenet'] as $x) {
    T::igaz(in_array($x, $k, true), 'kulcs: ' . $x);
}
T::egyezik(422, t_rest('POST', '/admin/beallitasok', ['ertekek' => ['rejtes.belepo' => 'wp-login']])->get_status(), 'foglalt belépő');
T::egyezik(422, t_rest('POST', '/admin/beallitasok', ['ertekek' => ['rejtes.api' => 'wp-json']])->get_status(), 'wp API-előtag tiltva');
T::egyezik(200, t_rest('POST', '/admin/beallitasok', ['ertekek' => ['rejtes.belepo' => 'iroda-titok-77', 'rejtes.api' => 'api']])->get_status(), 'jó belépő');
T::egyezik('iroda-titok-77', SDH_Sales::b('rejtes.belepo'), 'belépő mentve');
T::nem_tartalmaz((string) wp_json_encode(SDH_Sales_Exportimport::export()), 'iroda-titok-77', 'a belépő nincs az exportban');

// Idegen csoport hosszú mezőalakkal + megjelenítő link
add_filter('sdh_sales_admin_beallitas_csoportok', static function (array $c): array {
    $c['t238'] = ['cim' => 'T238', 'mezok' => [['kulcs' => 't238.szoveg', 'tipus' => 'szoveg_tobbsoros', 'cim' => 'Szöveg'], ['kulcs' => 't238.ar', 'tipus' => 'tizedes', 'cim' => 'Ár', 'min' => 0, 'max' => 10], ['k' => '_t238_link', 't' => 'link', 'c' => 'Letöltés', 'url' => 'https://example.com/a.csv']]];

    return $c;
});
$m = SDH_Sales_Admin_Beallitas::mezok();
T::egyezik('hosszu', $m['t238.szoveg']['t'], 'normalizált fajta');
T::egyezik('hivatkozas', $m['_t238_link']['t'], 'megjelenítő link');
t_rest('POST', '/admin/beallitasok', ['ertekek' => ['t238.szoveg' => "a\nb<script>", 't238.ar' => '12.55', '_t238_link' => 'x']]);
T::egyezik("a\nb", SDH_Sales::b('t238.szoveg'), 'többsoros tisztítva');
T::egyezik(10.0, SDH_Sales::b('t238.ar'), 'tizedes korlát');
T::egyezik(null, SDH_Sales::b('_t238_link'), 'link nem mentődik');
T::vege('t238 beállítás + titok + rejtés');
