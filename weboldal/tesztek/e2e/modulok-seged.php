<?php
/**
 * Segéd a modulok vizuális bejárásához (modulok-vizualis.py hívja, parancssorból, a SAJÁT tesztoldalon):
 *   php weboldal/tesztek/e2e/modulok-seged.php <wp-load.php> <host:port> <parancs>
 * Parancsok (mind JSON-t ír a kimenetre):
 *   elokeszit  – hamis Szikra-kulcs (a csevegő panel megjelenik; a válasz hálózat nélkül hibaüzenet), kártyás fizetési
 *                mód nélkül (átutalás), teszt-rendelések: egy díjbekérős (állapotoldal), két kifizetett licenccel
 *                (egy hamarosan lejáró, egy lejárt) + letöltés; a vevő fiók e-mailes belépő linkje.
 *   belepo     – csak új belépő link a tesztvevőnek.
 *   takarit    – a hamis Szikra-kulcs ki (a többi tesztadat marad, a saját tesztoldalon nem zavar).
 * Hálózat nincs: a díjbekérő-levél wp_mail-je hibára fut (nincs levelező), ezt elnyeljük.
 *
 * @package SDH_Sales
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

[$wpload, $host, $parancs] = [(string) ($argv[1] ?? ''), (string) ($argv[2] ?? '127.0.0.1:8873'), (string) ($argv[3] ?? 'elokeszit')];

if (!is_file($wpload)) {
    fwrite(STDERR, "wp-load.php kell\n");
    exit(1);
}

$_SERVER['HTTP_HOST']   = $host;
$_SERVER['REQUEST_URI'] = '/wp/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require $wpload;

// Levelezés nincs a tesztoldalon: a wp_mail ne dobjon.
add_filter('pre_wp_mail', static fn () => true);
// Hálózat sincs: minden külső kérés azonnal "nincs kapcsolat".
add_filter('pre_http_request', static fn () => new WP_Error('nincs_halozat', 'teszt: nincs hálózat'));

$EMAIL = 'vevo.teszt@example.com';

function seg_vevo_id(string $email): int
{
    $u = get_user_by('email', $email);

    return $u instanceof WP_User ? (int) $u->ID : 0;
}

function seg_rendeles(string $email, array $tetelek, array $opciok = []): array
{
    $r = SDH_Sales_Rendeles::letrehoz([
        'tipus'   => 'uj',
        'fizmod'  => 'atutalas',
        'tetelek' => $tetelek,
        'opciok'  => $opciok + ['ciklus' => 'eves'],
        'vevo'    => [
            'ceg' => 'Teszt Szerviz Kft.', 'orszag' => 'HU', 'adoszam' => '12345676-2-41', 'irsz' => '1111', 'telepules' => 'Budapest',
            'utca' => 'Teszt utca 1.', 'kapcsolattarto' => 'Teszt Elek', 'email' => $email, 'telefon' => '+36301234567',
        ],
        'elfogad' => ['vallalkozas' => true, 'aszf' => true, 'eula' => true],
    ]);

    return is_array($r) ? $r : ['ok' => false];
}

/** Kifizetett rendelés licenckulccsal (a Központ-hívás nélkül, közvetlenül az adatbázisban). */
function seg_licenc(int $id, string $kulcs, string $lejar, bool $letoltes): void
{
    global $wpdb;

    $t = SDH_Sales_Schema::t('rendeles');
    $r = SDH_Sales_Rendeles::rendeles($id);

    if ($r === null) {
        return;
    }

    $lanc = ['lepesek' => ['kozpont' => ['allapot' => 'ok', 'eredmeny' => ['licenc_kulcs' => $kulcs, 'lejar' => $lejar] + ($letoltes ? ['letoltes_url' => home_url('/media/portal/teszt-csomag.zip')] : [])]]];
    $wpdb->update($t, [
        'allapot'      => 'kiszolgalva',
        'licenc_kulcs' => $kulcs,
        'fizetett'     => (int) $r['brutto'],
        'fizetve_ido'  => current_time('mysql', true),
        'lanc'         => (string) wp_json_encode($lanc, JSON_UNESCAPED_UNICODE),
    ], ['id' => $id]);

    $opt = 'sdh_sales_licencek';

    if (class_exists('SDH_Sales_Kiszolgalas')) {
        $ref = new ReflectionClass('SDH_Sales_Kiszolgalas');

        if ($ref->hasConstant('OPT_LICENC')) {
            $opt = (string) $ref->getConstant('OPT_LICENC');
        }
    }

    $m         = (array) get_option($opt, []);
    $m[$kulcs] = [
        'lejar' => $lejar, 'ciklus' => 'eves', 'csomag' => (string) ($r['vevo']['_opciok']['csomag'] ?? 'pro'),
        'email' => (string) $r['email'], 'nev' => 'Teszt Elek', 'rendeles_id' => $id, 'user_id' => (int) $r['user_id'], 'kuldve' => [],
    ];
    update_option($opt, $m, false);
}

$ki = ['ok' => true];

switch ($parancs) {
    case 'elokeszit':
        // Szikra: hamis kulcs → a lebegő csevegő megjelenik; a válasz hálózat nélkül hibaüzenet (ezt nézzük).
        SDH_Sales::titkos_ment(SDH_Sales_Szikra::TITOK, 'sk-ant-teszt-nem-valodi');
        SDH_Sales::b_ment(['szikra.aktiv' => true, 'fizetes.atutalas' => true]);
        $ki['szikra_aktiv'] = SDH_Sales_Szikra::aktiv();

        // Teszt-rendelések.
        $csomagok = SDH_Sales_Katalogus::csomagok();
        $kulcsok  = array_keys($csomagok);
        $cs       = in_array('pro', $kulcsok, true) ? 'pro' : (string) ($kulcsok[0] ?? '');
        $modulok  = array_keys(SDH_Sales_Katalogus::modulok());
        $tetelek  = [['tipus' => 'csomag', 'kulcs' => $cs, 'db' => 1]];

        $a = seg_rendeles($EMAIL, $tetelek, ['felhasznalo' => 5, 'telephely' => 2]);
        $b = seg_rendeles($EMAIL, $tetelek);
        $c = seg_rendeles($EMAIL, [['tipus' => 'csomag', 'kulcs' => (string) ($kulcsok[0] ?? $cs), 'db' => 1]]);
        $ki['rendelesek'] = [$a['ok'] ?? false, $b['ok'] ?? false, $c['ok'] ?? false];
        $ki['hibak']      = [$a['hibak'] ?? null, $b['hibak'] ?? null, $c['hibak'] ?? null];

        if (!empty($a['ok'])) {
            $ki['statusz_url'] = (string) $a['statusz_url'];
            $ki['azon']        = (string) $a['azon'];
        }

        $elotag = 'MUNKORA';

        if (!empty($b['ok'])) {
            seg_licenc((int) $b['id'], $elotag . '-TESZT-HAMAR-0001', wp_date('Y-m-d', time() + 12 * DAY_IN_SECONDS), true);
        }

        if (!empty($c['ok'])) {
            seg_licenc((int) $c['id'], $elotag . '-TESZT-LEJAR-0002', wp_date('Y-m-d', time() - 40 * DAY_IN_SECONDS), false);
        }

        // Egy foglalás a fiókhoz.
        global $wpdb;
        $ft = SDH_Sales_Schema::t('foglalas');
        $wpdb->insert($ft, [
            'tipus' => 'demo', 'kezdet' => gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS), 'veg' => gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS + 1800),
            'nev' => 'Teszt Elek', 'email' => $EMAIL, 'telefon' => '', 'ceg' => 'Teszt Szerviz Kft.', 'megjegyzes' => '', 'allapot' => 'foglalt',
            'token' => SDH_Sales::token(16), 'ido' => current_time('mysql', true),
        ]);

        $uid = seg_vevo_id($EMAIL);
        $ki['user_id'] = $uid;

        if ($uid > 0) {
            $tok            = SDH_Sales_Fiok::link_letrehoz($uid, $EMAIL);
            $ki['belepo_url'] = SDH_Sales_Fiok::fiok_url(['belepes' => $tok]);
        }

        $ki['fiok_url']    = SDH_Sales_Fiok::fiok_url();
        $ki['penztar_url'] = SDH_Sales_Kosar::penztar_url();
        break;

    case 'belepo':
        $uid = seg_vevo_id($EMAIL);
        $ki['belepo_url'] = $uid > 0 ? SDH_Sales_Fiok::fiok_url(['belepes' => SDH_Sales_Fiok::link_letrehoz($uid, $EMAIL)]) : '';
        break;

    case 'takarit':
        SDH_Sales::b_ment(['szikra.aktiv' => false]);
        break;

    default:
        $ki = ['ok' => false, 'hiba' => 'ismeretlen parancs'];
}

echo wp_json_encode($ki, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
