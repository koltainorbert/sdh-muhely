<?php
/** t200 – mag: beállítások, tokenek, titkok, korlát, napló, séma. */
require __DIR__ . '/_keret.php';

global $wpdb;
foreach (SDH_Sales_Schema::tablak() as $t) {
    T::igaz($wpdb->get_var("SELECT COUNT(*) FROM " . SDH_Sales_Schema::t($t)) !== null, "tábla: {$t}");
}
T::egyezik('Munkora', SDH_Sales::termeknev(), 'helyőrző termeknev');
SDH_Sales::b_ment(['termeknev' => 'Próbanév']);
T::egyezik('Próbanév', SDH_Sales::termeknev(), 'termeknev csere egy mezővel');
T::egyezik('A Próbanév jó.', SDH_Sales::t('A {termeknev} jó.'), 'token');
SDH_Sales::b_ment(['termeknev' => 'Munkora']);
T::egyezik(27, SDH_Sales::afa(), 'áfa 27');
T::egyezik(12700, SDH_Sales::brutto(10000), 'bruttó');
SDH_Sales::titkos_ment('teszt', 'titok-123');
T::egyezik('titok-123', SDH_Sales::titkos('teszt'), 'titok körút');
$nyers = (array) get_option(SDH_Sales::OPT_TITOK);
T::nem_tartalmaz((string) wp_json_encode($nyers), 'titok-123', 'titok nem nyílt');
SDH_Sales::titkos_ment('teszt', '');
T::egyezik('', SDH_Sales::titkos('teszt'), 'titok törlés');
$n = 0; $kn = 't200' . wp_rand();
for ($i = 0; $i < 5; $i++) { $n += SDH_Sales_Rest::korlat($kn, 3, 600) ? 1 : 0; }
T::egyezik(3, $n, 'korlát: 3 fér bele');
$l = SDH_Sales_Naplo::lista(['ref_tipus' => 'beallitas']);
T::igaz($l['ossz'] >= 2, 'napló: beállítás-változás');
$id = (int) $l['sorok'][0]['id'];
T::egyezik('', SDH_Sales_Naplo::visszaallit($id), 'visszaállítás');
T::igaz(strlen(SDH_Sales::ip_hash()) === 32 && SDH_Sales::ip_hash() !== '203.0.113.7', 'IP-lenyomat');
T::vege('t200 mag');
