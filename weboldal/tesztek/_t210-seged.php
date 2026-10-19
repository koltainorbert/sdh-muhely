<?php
/**
 * Közös segéd a t210–t229 (sales-backend) tesztekhez: tesztkatalógus, rendelés-gyártó, érvényes adószám, robotszűrő-mezők.
 * A kiszolgálási lánc és a díjbekérő-hook itt le van választva, hogy a tesztek csak a saját modulunkat mérjék.
 */
require_once __DIR__ . '/_keret.php';

remove_all_actions('sdh_sales_rendeles_letrehozva');
if (class_exists('SDH_Sales_Kiszolgalas')) {
    remove_action('sdh_sales_fizetve', ['SDH_Sales_Kiszolgalas', 'fizetve_hook'], 20);
}

/** A számláló: hányszor futott le a sdh_sales_fizetve hook rendelésenként. */
$GLOBALS['t_fizetve'] = [];
add_action('sdh_sales_fizetve', static function ($id): void {
    $GLOBALS['t_fizetve'][(int) $id] = ($GLOBALS['t_fizetve'][(int) $id] ?? 0) + 1;
}, 1);

/** A tiszta árszámítás tesztkatalógusa (ugyanez megy a node-os tükörnek is). */
function t_kat(): array
{
    return [
        'csomagok' => [
            'start' => ['kulcs' => 'start', 'nev' => 'Start', 'havi' => 9900, 'felhasznalo_alap' => 2, 'felhasznalo_extra_havi' => 1900, 'telephely_alap' => 1, 'telephely_extra_havi' => 4900, 'modulok' => ['uzenetek', 'nyomtatas'], 'max_peldany' => 1],
            'pro' => ['kulcs' => 'pro', 'nev' => 'Pro', 'havi' => 19900, 'eves' => 202980, 'felhasznalo_alap' => 5, 'felhasznalo_extra_havi' => 1900, 'felhasznalo_extra_eves' => 19000, 'telephely_alap' => 1, 'telephely_extra_havi' => 4900, 'telephely_max' => 3, 'modulok' => ['termekek', 'penztar'], 'max_peldany' => 1],
            'business' => ['kulcs' => 'business', 'nev' => 'Business', 'havi' => 34900, 'felhasznalo_alap' => 10, 'felhasznalo_extra_havi' => 1500, 'telephely_alap' => 2, 'telephely_extra_havi' => 3900, 'modulok' => ['*'], 'max_peldany' => 2],
            'mini' => ['kulcs' => 'mini', 'nev' => 'Mini', 'havi' => 4990, 'felhasznalo_alap' => 1, 'felhasznalo_extra_havi' => null, 'telephely_alap' => 1, 'modulok' => []],
            'ingyen' => ['kulcs' => 'ingyen', 'nev' => 'Ingyen', 'havi' => 0, 'felhasznalo_alap' => 1, 'telephely_alap' => 1, 'modulok' => []],
        ],
        'modulok' => [
            'sms' => ['kulcs' => 'sms', 'nev' => 'SMS', 'havi' => 2490, 'csomagban' => ['business']],
            'asszisztens' => ['kulcs' => 'asszisztens', 'nev' => 'AI-asszisztens', 'havi' => 3990, 'eves' => 40000, 'csomagban' => []],
            'imei' => ['kulcs' => 'imei', 'nev' => 'IMEI', 'havi' => 990, 'csomagban' => ['pro', 'business']],
            'penztar' => ['kulcs' => 'penztar', 'nev' => 'Házipénztár', 'havi' => 1990, 'csomagban' => []],
            'matrica' => ['kulcs' => 'matrica', 'nev' => 'Címke', 'havi' => 1333, 'csomagban' => []],
        ],
        'szolgaltatasok' => [
            'telepites' => ['kulcs' => 'telepites', 'nev' => 'Telepítés', 'tipus' => 'egyszeri', 'ar' => 39900],
            'oktatas' => ['kulcs' => 'oktatas', 'nev' => 'Oktatás (1 óra)', 'tipus' => 'egyszeri', 'ar' => 12900],
            'support' => ['kulcs' => 'support', 'nev' => 'Support Pro', 'tipus' => 'havi', 'ar' => 9900],
            'karbantartas' => ['kulcs' => 'karbantartas', 'nev' => 'Éves karbantartás', 'tipus' => 'eves', 'ar' => 50000],
            'ingyen_s' => ['kulcs' => 'ingyen_s', 'nev' => 'Alap support', 'tipus' => 'havi', 'ar' => 0],
        ],
        'kuponok' => [
            'SZ10' => ['kod' => 'SZ10', 'tipus' => 'szazalek', 'ertek' => 10, 'lejar' => null, 'max' => null, 'hasznalva' => 0],
            'SZ33' => ['kod' => 'SZ33', 'tipus' => 'szazalek', 'ertek' => 33.33, 'lejar' => null, 'max' => 5, 'hasznalva' => 4],
            'FIX5000' => ['kod' => 'FIX5000', 'tipus' => 'fix', 'ertek' => 5000, 'lejar' => '2026-12-31', 'max' => 10, 'hasznalva' => 0],
            'NAGYFIX' => ['kod' => 'NAGYFIX', 'tipus' => 'fix', 'ertek' => 99999999, 'lejar' => null, 'max' => null, 'hasznalva' => 0],
            'LEJART' => ['kod' => 'LEJART', 'tipus' => 'szazalek', 'ertek' => 20, 'lejar' => '2026-01-31', 'max' => null, 'hasznalva' => 0],
            'ELFOGYOTT' => ['kod' => 'ELFOGYOTT', 'tipus' => 'szazalek', 'ertek' => 20, 'lejar' => null, 'max' => 3, 'hasznalva' => 3],
            'MIN50' => ['kod' => 'MIN50', 'tipus' => 'fix', 'ertek' => 3000, 'min' => 50000, 'lejar' => null, 'max' => null, 'hasznalva' => 0],
            'MODUL15' => ['kod' => 'MODUL15', 'tipus' => 'szazalek', 'ertek' => 15, 'hatokor' => 'modul', 'lejar' => null, 'max' => null, 'hasznalva' => 0],
            'PROCSAK' => ['kod' => 'PROCSAK', 'tipus' => 'szazalek', 'ertek' => 50, 'hatokor' => ['pro'], 'lejar' => null, 'max' => null, 'hasznalva' => 0],
            'SZOLG20' => ['kod' => 'SZOLG20', 'tipus' => 'szazalek', 'ertek' => 20, 'hatokor' => 'szolgaltatas', 'lejar' => null, 'max' => null, 'hasznalva' => 0],
            'EVESKUP' => ['kod' => 'EVESKUP', 'tipus' => 'szazalek', 'ertek' => 5, 'ciklus' => 'eves', 'lejar' => null, 'max' => null, 'hasznalva' => 0],
            'JOVO' => ['kod' => 'JOVO', 'tipus' => 'szazalek', 'ertek' => 5, 'kezd' => '2027-01-01', 'lejar' => null, 'max' => null, 'hasznalva' => 0],
            'INAKTIV' => ['kod' => 'INAKTIV', 'tipus' => 'szazalek', 'ertek' => 5, 'aktiv' => false, 'lejar' => null, 'max' => null, 'hasznalva' => 0],
        ],
        'eves_kedvezmeny' => 15,
        'afa' => 27,
        'ma' => '2026-10-19',
    ];
}

/** A tesztkatalógus betöltése a tartalom-tárba (a valódi szamol() ezt olvassa). A meglévő csomagokat kikapcsolja. */
function t_katalogus_betolt(): void
{
    foreach (['csomag', 'modul', 'szolgaltatas', 'kupon'] as $t) {
        foreach (SDH_Sales_Tartalom::lista($t, false) as $e) {
            SDH_Sales_Tartalom::torol($t, (string) $e['kulcs']);
        }
    }

    $k = t_kat();
    $i = 0;
    foreach ($k['csomagok'] as $c) { SDH_Sales_Tartalom::ment('csomag', $c['kulcs'], $c, null, $i++, true, 'hu', false); }
    foreach ($k['modulok'] as $c) { SDH_Sales_Tartalom::ment('modul', $c['kulcs'], $c, null, $i++, true, 'hu', false); }
    foreach ($k['szolgaltatasok'] as $c) { SDH_Sales_Tartalom::ment('szolgaltatas', $c['kulcs'], $c, null, $i++, true, 'hu', false); }
    foreach ($k['kuponok'] as $c) {
        unset($c['hasznalva']);
        SDH_Sales_Tartalom::ment('kupon', $c['kod'], $c, null, $i++, $c['aktiv'] ?? true, 'hu', false);
    }

    SDH_Sales::b_ment(['eves_kedvezmeny' => 15, 'afa' => 27, 'fizetes.atutalas' => true, 'ceg.nev' => 'Teszt Kft.', 'ceg.iban' => 'HU42117730161111101800000000', 'ceg.bic' => 'OTPVHUHB', 'ceg.bankszamla' => '11773016-11111111-00000000', 'email.ertesites' => 'norbi@example.com']);
    SDH_Sales::urit_cache();
    SDH_Sales_Tartalom::urit();
}

/** Érvényes magyar adószám a törzsszám első 7 jegyéből. */
function t_adoszam(string $hét = '1234567', string $afa = '2', string $megye = '42'): string
{
    $s = 0;
    foreach ([9, 7, 3, 1, 9, 7, 3] as $i => $w) { $s += (int) $hét[$i] * $w; }
    return $hét . ((10 - $s % 10) % 10) . '-' . $afa . '-' . $megye;
}

/** @return array<string, string> */
function t_vevo(array $felul = []): array
{
    return $felul + [
        'ceg' => 'Próba Szerviz Kft.', 'adoszam' => t_adoszam(), 'kapcsolattarto' => 'Kiss Péter', 'email' => 'vevo' . wp_rand(1000, 99999) . '@example.com',
        'telefon' => '+36 30 123 4567', 'orszag' => 'HU', 'irsz' => '8200', 'telepules' => 'Veszprém', 'utca' => 'Fő utca 1.',
    ];
}

/** Rendelés létrehozása; $brutto megadva: a sor összegét közvetlenül átírja (párosítási tesztekhez). */
function t_rendeles(array $felul = [], ?int $brutto = null): array
{
    $be = $felul + [
        'tipus' => 'uj',
        'tetelek' => [['tipus' => 'csomag', 'kulcs' => 'start']],
        'opciok' => ['ciklus' => 'havi'],
        'vevo' => t_vevo(),
        'fizmod' => 'atutalas',
        'elfogad' => ['vallalkozas' => true, 'aszf' => true, 'eula' => true],
    ];
    $e = SDH_Sales_Rendeles::letrehoz($be);
    if (empty($e['ok'])) {
        fwrite(STDERR, 'Rendelés nem jött létre: ' . wp_json_encode($e, JSON_UNESCAPED_UNICODE) . "\n");
        return $e;
    }
    if ($brutto !== null) {
        global $wpdb;
        $wpdb->update(SDH_Sales_Schema::t('rendeles'), ['brutto' => $brutto, 'netto' => (int) round($brutto / 1.27), 'afa' => $brutto - (int) round($brutto / 1.27)], ['id' => (int) $e['id']]);
    }
    return $e + ['r' => SDH_Sales_Rendeles::rendeles((int) $e['id'])];
}

/** A robotszűrő mezői egy nyilvános űrlaphoz (10 mp-es kitöltés). */
function t_vedelem(): array
{
    return ['_t' => SDH_Sales_Biztonsag::urlap_token(time() - 10), 'weboldal_cim' => ''];
}

/** A korlátok kikapcsolása (a korlát-tesztek kivételével). */
function t_korlat_ki(bool $ki = true): void
{
    remove_all_filters('sdh_sales_korlat_ki');
    if ($ki) {
        add_filter('sdh_sales_korlat_ki', '__return_true');
    }
}

/** Levelek, amelyek egy címre mentek. @return array<int, array> */
function t_levelek(string $cim): array
{
    return array_values(array_filter($GLOBALS['sdh_t_levelek'], static fn ($l) => in_array($cim, (array) $l['to'], true) || (string) $l['to'] === $cim));
}

t_korlat_ki();
