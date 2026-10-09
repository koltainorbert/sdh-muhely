<?php
/**
 * Demó adatok.
 *
 * 10 fiktív magyar ügyfél és hozzájuk rendelt 10 fiktív eszköz, minden mező
 * kitöltve – a felület kipróbálásához, bemutatóhoz. A Beállítások oldalon egy
 * gombbal tölthető be.
 *
 * Biztonságos újrafuttatni: minden demó rekord `kulso_azonosito`-ja `demo-…`
 * kezdetű, és ami már megvan, azt nem hozza létre újra. Valódi adathoz nem nyúl.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Demo
{
    public static function init(): void
    {
        add_action('admin_post_sdh_muhely_demo_betoltes', [self::class, 'betoltes']);
    }

    /* =================================================================
     * Felület (Beállítások)
     * ============================================================== */

    public static function doboz(): void
    {
        global $wpdb;

        $db = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . " WHERE kulso_azonosito LIKE 'demo-%'"
        );

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Demó adatok</h2>

            <p class="sdh-sugo">
                10 fiktív magyar ügyfél, mindegyikhez egy készülékkel – minden mező kitöltve
                (címek, telefonszámok, IMEI, zárkód, minta, garancia, tartozékok, megjegyzések).
                A rekordok „demo-” azonosítót kapnak, a gomb nem hoz létre kétszer ugyanazt.
                <?php if ($db > 0) : ?>
                    Most <strong><?php echo (int) $db; ?></strong> demó ügyfél van a rendszerben.
                <?php endif; ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_demo_betoltes">
                <?php wp_nonce_field('sdh_muhely_demo_betoltes', 'sdh_nonce'); ?>
                <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Demó adatok betöltése</button>
            </form>
        </div>
        <?php
    }

    public static function betoltes(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_demo_betoltes', 'sdh_nonce');

        $eredmeny = self::betolt();

        wp_safe_redirect(
            SDH_Muhely_Modulok::admin_url(
                SDH_Muhely_Beallitasok::KULCS,
                [
                    'uzenet' => 'demo_betoltve',
                    'ugyfel' => $eredmeny['ugyfel'],
                    'eszkoz' => $eredmeny['eszkoz'],
                ]
            )
        );
        exit;
    }

    /* =================================================================
     * Betöltés
     * ============================================================== */

    /**
     * @return array{ugyfel: int, eszkoz: int} Hány új rekord jött létre.
     */
    public static function betolt(): array
    {
        global $wpdb;

        $ugyfel_tabla = SDH_Muhely_Schema::tabla('ugyfel');
        $eszkoz_tabla = SDH_Muhely_Schema::tabla('eszkoz');
        $most         = current_time('mysql');
        $felhasznalo  = get_current_user_id();
        $uj_ugyfel    = 0;
        $uj_eszkoz    = 0;

        $ugyfelek = self::ugyfelek();
        $eszkozok = self::eszkozok();

        foreach ($ugyfelek as $i => $u) {
            $kulcs = sprintf('demo-u-%02d', $i + 1);
            $id    = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$ugyfel_tabla} WHERE kulso_azonosito = %s", $kulcs)
            );

            if ($id <= 0) {
                $sor = array_merge($u, [
                    'aktiv'           => 1,
                    'forras'          => 'demo',
                    'kulso_azonosito' => $kulcs,
                    'letrehozva'      => $most,
                    'modositva'       => $most,
                    'letrehozo'       => $felhasznalo,
                ]);

                if ($wpdb->insert($ugyfel_tabla, $sor) === false) {
                    continue;
                }

                $id = (int) $wpdb->insert_id;
                $wpdb->update($ugyfel_tabla, ['ugyfel_szam' => sprintf('U-%06d', $id)], ['id' => $id]);
                $uj_ugyfel++;
            }

            $eszkoz_kulcs = sprintf('demo-e-%02d', $i + 1);
            $van          = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$eszkoz_tabla} WHERE kulso_azonosito = %s", $eszkoz_kulcs)
            );

            if ($van > 0 || !isset($eszkozok[$i])) {
                continue;
            }

            $e = $eszkozok[$i];

            $sor = array_merge($e, [
                'ugyfel_id'       => $id,
                'imei'            => self::imei($e['imei_alap']),
                'imei2'           => $e['imei2_alap'] !== '' ? self::imei($e['imei2_alap']) : '',
                'kep_url'         => SDH_MUHELY_URL . 'assets/demo/' . $e['kep'],
                'lekerdezve'      => $most,
                'aktiv'           => 1,
                'forras'          => 'demo',
                'kulso_azonosito' => $eszkoz_kulcs,
                'letrehozva'      => $most,
                'modositva'       => $most,
                'letrehozo'       => $felhasznalo,
            ]);

            unset($sor['imei_alap'], $sor['imei2_alap'], $sor['kep']);

            if ($wpdb->insert($eszkoz_tabla, $sor) !== false) {
                $uj_eszkoz++;
            }
        }

        return ['ugyfel' => $uj_ugyfel, 'eszkoz' => $uj_eszkoz];
    }

    /**
     * 14 jegyű törzsből érvényes (Luhn-ellenőrző számjegyes) 15 jegyű IMEI.
     */
    private static function imei(string $torzs): string
    {
        $osszeg = 0;

        for ($i = 0; $i < 14; $i++) {
            $szam = (int) $torzs[13 - $i];

            if ($i % 2 === 0) {
                $szam *= 2;

                if ($szam > 9) {
                    $szam -= 9;
                }
            }

            $osszeg += $szam;
        }

        return $torzs . (string) ((10 - ($osszeg % 10)) % 10);
    }

    /* =================================================================
     * Az adatok
     * ============================================================== */

    /**
     * Egy ügyfél minden mezője. A másodlagos címek mind külön vannak
     * kitöltve (az „azonos” jelző nulla).
     *
     * @return array<string, mixed>
     */
    private static function ugyfel(
        string $tipus,
        string $nev,
        string $kapcsolattarto,
        string $adoszam,
        string $telefon,
        string $telefon2,
        string $email,
        array $szamlazasi,
        array $levelezesi,
        array $szallitasi,
        array $telephely,
        string $kategoria,
        float $kedvezmeny,
        string $megjegyzes,
        string $belso
    ): array {
        return [
            'tipus'                   => $tipus,
            'nev'                     => $nev,
            'kapcsolattarto'          => $kapcsolattarto,
            'adoszam'                 => $adoszam,
            'telefon'                 => $telefon,
            'telefon2'                => $telefon2,
            'email'                   => $email,
            'szamlazasi_iranyitoszam' => $szamlazasi[0],
            'szamlazasi_telepules'    => $szamlazasi[1],
            'szamlazasi_cim'          => $szamlazasi[2],
            'szamlazasi_orszag'       => 'Magyarország',
            'levelezesi_azonos'       => 0,
            'levelezesi_iranyitoszam' => $levelezesi[0],
            'levelezesi_telepules'    => $levelezesi[1],
            'levelezesi_cim'          => $levelezesi[2],
            'szallitasi_azonos'       => 0,
            'szallitasi_iranyitoszam' => $szallitasi[0],
            'szallitasi_telepules'    => $szallitasi[1],
            'szallitasi_cim'          => $szallitasi[2],
            'telephely_azonos'        => 0,
            'telephely_nev'           => $telephely[0],
            'telephely_iranyitoszam'  => $telephely[1],
            'telephely_telepules'     => $telephely[2],
            'telephely_cim'           => $telephely[3],
            'kategoria'               => $kategoria,
            'kedvezmeny'              => $kedvezmeny,
            'megjegyzes'              => $megjegyzes,
            'belso_megjegyzes'        => $belso,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function ugyfelek(): array
    {
        return [
            self::ugyfel(
                'maganszemely', 'Kovács Péter', 'Kovács Péter', '8412345678', '+36 30 412 3801', '+36 88 412 380',
                'kovacs.peter.demo@example.com',
                ['8200', 'Veszprém', 'Kossuth Lajos utca 14. 2/3.'],
                ['8200', 'Veszprém', 'Pf. 118'],
                ['8200', 'Veszprém', 'Kossuth Lajos utca 14. 2/3.'],
                ['Munkahely', '8230', 'Balatonfüred', 'Jókai Mór utca 7.'],
                'Törzsvásárló', 5.00,
                'Délután 16 óra után érhető el telefonon.',
                'Korábban kétszer is hozott javításra telefont; mindig fizetett átvételkor.'
            ),
            self::ugyfel(
                'maganszemely', 'Nagy Eszter', 'Nagy Eszter', '8398765432', '+36 20 398 7654', '+36 1 398 7654',
                'nagy.eszter.demo@example.com',
                ['1111', 'Budapest', 'Budafoki út 33. fszt. 1.'],
                ['1111', 'Budapest', 'Budafoki út 33. fszt. 1.'],
                ['1117', 'Budapest', 'Október huszonharmadika utca 8.'],
                ['Szülői ház', '2030', 'Érd', 'Tárnoki út 21.'],
                'VIP', 10.00,
                'E-mailben kéri az értesítést, telefonhívást nem szeret.',
                'Az Apple-javításokat mindig garanciális ügyként kéri; számlát kér a cégére is.'
            ),
            self::ugyfel(
                'maganszemely', 'Szabó Gergely', 'Szabó Gergely', '8456781234', '+36 70 456 7812', '+36 96 456 781',
                'szabo.gergely.demo@example.com',
                ['9021', 'Győr', 'Baross Gábor út 52. 4/12.'],
                ['9021', 'Győr', 'Baross Gábor út 52. 4/12.'],
                ['9024', 'Győr', 'Ménfőcsanaki út 9.'],
                ['Albérlet', '9026', 'Győr', 'Fehérvári út 3.'],
                'Új ügyfél', 0.00,
                'Fiatal, gyors döntéseket hoz, az árajánlatot SMS-ben kéri.',
                'Az első javítása a kijelzőcsere volt, elégedett volt.'
            ),
            self::ugyfel(
                'maganszemely', 'Tóth Katalin', 'Tóth Katalin', '8321456789', '+36 30 321 4567', '+36 72 321 456',
                'toth.katalin.demo@example.com',
                ['7621', 'Pécs', 'Király utca 28. 1/4.'],
                ['7621', 'Pécs', 'Király utca 28. 1/4.'],
                ['7625', 'Pécs', 'Szabadság út 11.'],
                ['Boltja', '7630', 'Pécs', 'Siklósi út 2.'],
                'Törzsvásárló', 3.00,
                'Nyugdíjas, a telefonját mindig személyesen hozza és viszi.',
                'A zárkódot papíron adta át; a minta a lap hátoldalán van.'
            ),
            self::ugyfel(
                'maganszemely', 'Horváth Dávid', 'Horváth Dávid', '8467123450', '+36 20 467 1234', '+36 62 467 123',
                'horvath.david.demo@example.com',
                ['6720', 'Szeged', 'Tisza Lajos körút 41. 3/2.'],
                ['6720', 'Szeged', 'Tisza Lajos körút 41. 3/2.'],
                ['6722', 'Szeged', 'Kálvária sugárút 17.'],
                ['Egyetemi kollégium', '6726', 'Szeged', 'Dugonics tér 13.'],
                'Diák', 7.50,
                'Szemeszter alatt csak hétvégén van Szegeden.',
                'Diákigazolvány-kedvezmény 7,5%; számlát a szülő nevére kér.'
            ),
            self::ugyfel(
                'maganszemely', 'Varga Zsuzsanna', 'Varga Zsuzsanna', '8445678123', '+36 70 445 6781', '+36 52 445 678',
                'varga.zsuzsanna.demo@example.com',
                ['4024', 'Debrecen', 'Piac utca 20. 5/21.'],
                ['4024', 'Debrecen', 'Piac utca 20. 5/21.'],
                ['4026', 'Debrecen', 'Bethlen utca 36.'],
                ['Rendelő', '4032', 'Debrecen', 'Egyetem sugárút 6.'],
                'Partner', 4.00,
                'Orvosként ügyel, a készüléket gyakran a recepción hagyják.',
                'Mindig kéri a készülék adatainak törlését a javítás előtt.'
            ),
            self::ugyfel(
                'ceg', 'Balaton Digital Kft.', 'Molnár Gábor', '24681357-2-19', '+36 87 540 1200', '+36 30 540 1201',
                'iroda.demo@example.com',
                ['8230', 'Balatonfüred', 'Ady Endre utca 5.'],
                ['8230', 'Balatonfüred', 'Pf. 42'],
                ['8230', 'Balatonfüred', 'Széchenyi István utca 12. (raktár)'],
                ['Központi iroda', '8230', 'Balatonfüred', 'Ady Endre utca 5.'],
                'Cég', 12.00,
                'Havi összesítő számlát kérnek, fizetési határidő 15 nap.',
                'Szerződéses partner; a javításokat a kapcsolattartó hagyja jóvá.'
            ),
            self::ugyfel(
                'ceg', 'Miskolci Építő Zrt.', 'Fekete Anikó', '13572468-2-05', '+36 46 510 8800', '+36 20 510 8801',
                'beszerzes.demo@example.com',
                ['3525', 'Miskolc', 'Városház tér 9. 2. em.'],
                ['3525', 'Miskolc', 'Pf. 301'],
                ['3527', 'Miskolc', 'Kassai út 25.'],
                ['Telephely', '3534', 'Miskolc', 'Vörösmarty utca 40.'],
                'Cég', 8.00,
                'Beszerzési osztály intézi, minden javításhoz megrendelőszám kell.',
                'Keretszerződés alapján számláz; készpénzfizetés nem lehetséges.'
            ),
            self::ugyfel(
                'ceg', 'Tapolcai Fogászat Bt.', 'Dr. Simon Lilla', '35791357-1-19', '+36 87 413 5600', '+36 70 413 5601',
                'rendelo.demo@example.com',
                ['8300', 'Tapolca', 'Deák Ferenc utca 3.'],
                ['8300', 'Tapolca', 'Deák Ferenc utca 3.'],
                ['8300', 'Tapolca', 'Batsányi tér 1.'],
                ['Fogászati rendelő', '8300', 'Tapolca', 'Deák Ferenc utca 3. fszt.'],
                'Partner', 6.00,
                'A rendelés ideje alatt (8–16 óra) nem veszik fel a telefont.',
                'Tableteket és okosórákat is hoz a recepciótól; mindig sürgős.'
            ),
            self::ugyfel(
                'kozulet', 'Székesfehérvári Városi Könyvtár', 'Papp Réka', '15246801-2-07', '+36 22 510 7700', '+36 30 510 7701',
                'konyvtar.demo@example.com',
                ['8000', 'Székesfehérvár', 'Hosszú sor 2.'],
                ['8000', 'Székesfehérvár', 'Pf. 73'],
                ['8000', 'Székesfehérvár', 'Kossuth Lajos utca 17.'],
                ['Főkönyvtár', '8000', 'Székesfehérvár', 'Hosszú sor 2. fszt.'],
                'Közület', 15.00,
                'Utalással fizetnek, a teljesítésigazolást a gazdasági iroda kéri.',
                'Közbeszerzési keret alatt; árajánlat nélkül nem indítható javítás.'
            ),
        ];
    }

    /**
     * Egy-egy eszköz az ügyfelekhez, ugyanabban a sorrendben. Az `imei_alap`
     * 14 jegyű törzs: a végére a Luhn-számjegy kerül.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function eszkozok(): array
    {
        return [
            [
                'kategoria' => 'telefon', 'gyarto' => 'Samsung', 'tipus' => 'SM-S911B/DS', 'megnevezes' => 'Galaxy S23',
                'imei_alap' => '35120411300001', 'imei2_alap' => '35120411300002', 'sorozatszam' => 'R5CW31ABCDE',
                'modell_szam' => 'SM-S911BZKDEUE', 'garancia_allapot' => 'Garanciális', 'gyartas_datuma' => '2023-03-14',
                'orszag' => 'Vietnám', 'szolgaltato' => 'Feloldva', 'kep' => 'telefon.svg', 'szin' => 'Fekete',
                'zarkod' => '4821', 'minta' => '14789', 'tartozekok' => 'Töltő, Adatkábel, Tok, Védőfólia / üveg',
                'atveteli_allapot' => 'Hátlap bal alsó sarkán apró karc, a kijelzőn nincs sérülés.',
                'garancias' => 1, 'vasarlas_datuma' => '2024-11-22', 'garancia_lejar' => '2026-11-22',
                'megjegyzes' => 'Az akkumulátor gyorsan merül, töltés közben melegszik.',
                'belso_megjegyzes' => 'Ügyfél sürgeti, hétvégén utazik; a garanciális ügyintézést jelezni.',
            ],
            [
                'kategoria' => 'telefon', 'gyarto' => 'Apple', 'tipus' => 'A2882', 'megnevezes' => 'iPhone 14 Pro',
                'imei_alap' => '35390811300011', 'imei2_alap' => '35390811300012', 'sorozatszam' => 'F2LXK0ABC1D2',
                'modell_szam' => 'MQ0E3ZD/A', 'garancia_allapot' => 'Lejárt', 'gyartas_datuma' => '2022-10-05',
                'orszag' => 'Kína', 'szolgaltato' => 'Telekom', 'kep' => 'telefon.svg', 'szin' => 'Lila',
                'zarkod' => '705913', 'minta' => '', 'tartozekok' => 'Töltő, Tok, SIM-tálca, Doboz',
                'atveteli_allapot' => 'Keret kopott, hátlap üvege hibátlan, a kijelzőn hajszálvékony karcok.',
                'garancias' => 0, 'vasarlas_datuma' => '2022-12-02', 'garancia_lejar' => '2024-12-02',
                'megjegyzes' => 'Eltört a hátlap kamera üvege, a kamera életlen képet ad.',
                'belso_megjegyzes' => 'Kamera-üveg eredeti alkatrésszel; ügyfél VIP, elsőbbséget kap.',
            ],
            [
                'kategoria' => 'telefon', 'gyarto' => 'Xiaomi', 'tipus' => '2312DRA50G', 'megnevezes' => 'Redmi Note 13 Pro',
                'imei_alap' => '86481207300021', 'imei2_alap' => '86481207300022', 'sorozatszam' => '56081/F2M2TQ5H',
                'modell_szam' => '2312DRA50G', 'garancia_allapot' => 'Garanciális', 'gyartas_datuma' => '2024-01-18',
                'orszag' => 'Kína', 'szolgaltato' => 'Yettel', 'kep' => 'telefon.svg', 'szin' => 'Éjfekete',
                'zarkod' => '1357', 'minta' => '2584369', 'tartozekok' => 'Töltő, Adatkábel, Tok, SIM-kártya',
                'atveteli_allapot' => 'Kijelző bal felső sarka repedt, a hátlap épp.',
                'garancias' => 1, 'vasarlas_datuma' => '2025-02-14', 'garancia_lejar' => '2027-02-14',
                'megjegyzes' => 'Leejtés után a kijelző repedt, az érintés a repedés mentén nem működik.',
                'belso_megjegyzes' => 'Fizikai sérülés – garanciába nem vehető fel, árajánlat kell.',
            ],
            [
                'kategoria' => 'telefon', 'gyarto' => 'Huawei', 'tipus' => 'ANA-NX9', 'megnevezes' => 'P40',
                'imei_alap' => '86695205300031', 'imei2_alap' => '86695205300032', 'sorozatszam' => 'TEW7N20A12345678',
                'modell_szam' => 'ANA-NX9', 'garancia_allapot' => 'Lejárt', 'gyartas_datuma' => '2020-05-11',
                'orszag' => 'Kína', 'szolgaltato' => 'Feloldva', 'kep' => 'telefon.svg', 'szin' => 'Ezüst',
                'zarkod' => '2468', 'minta' => '36987', 'tartozekok' => 'Töltő, Adatkábel, Doboz, Kézikönyv',
                'atveteli_allapot' => 'Használat nyomai a kereten, a kijelző ép.',
                'garancias' => 0, 'vasarlas_datuma' => '2020-09-03', 'garancia_lejar' => '2022-09-03',
                'megjegyzes' => 'Nem tölt, a töltőport laza.',
                'belso_megjegyzes' => 'Töltőport-csere; az ügyfél nem szeret telefonálni, SMS-ben értesítsük.',
            ],
            [
                'kategoria' => 'telefon', 'gyarto' => 'Motorola', 'tipus' => 'XT2321-3', 'megnevezes' => 'Moto G73',
                'imei_alap' => '35947822300041', 'imei2_alap' => '35947822300042', 'sorozatszam' => 'ZY22FKC7LH',
                'modell_szam' => 'PAUX0004PL', 'garancia_allapot' => 'Garanciális', 'gyartas_datuma' => '2023-06-02',
                'orszag' => 'Kína', 'szolgaltato' => 'One', 'kep' => 'telefon.svg', 'szin' => 'Kék',
                'zarkod' => '9090', 'minta' => '147852369', 'tartozekok' => 'Töltő, Adatkábel, Tok',
                'atveteli_allapot' => 'Hátlap enyhén karcos, a kijelző tiszta.',
                'garancias' => 1, 'vasarlas_datuma' => '2025-06-19', 'garancia_lejar' => '2027-06-19',
                'megjegyzes' => 'A hangszóró recseg, a hívásnál a másik fél alig hallható.',
                'belso_megjegyzes' => 'Garanciális hangszóró-csere; szerviznapló a lapon.',
            ],
            [
                'kategoria' => 'tablet', 'gyarto' => 'Apple', 'tipus' => 'A2757', 'megnevezes' => 'iPad Air 5. gen. (Cellular)',
                'imei_alap' => '35219306300051', 'imei2_alap' => '', 'sorozatszam' => 'DMPH4QABC123',
                'modell_szam' => 'MM6V3HC/A', 'garancia_allapot' => 'Garanciális', 'gyartas_datuma' => '2023-09-21',
                'orszag' => 'Kína', 'szolgaltato' => 'Feloldva', 'kep' => 'tablet.svg', 'szin' => 'Ezüst',
                'zarkod' => '314159', 'minta' => '', 'tartozekok' => 'Töltő, Adatkábel, Tok, Toll (S Pen / Pencil)',
                'atveteli_allapot' => 'Sarkán apró horpadás, a kijelző hibátlan.',
                'garancias' => 1, 'vasarlas_datuma' => '2025-03-08', 'garancia_lejar' => '2027-03-08',
                'megjegyzes' => 'Az érintőképernyő időnként magától görget.',
                'belso_megjegyzes' => 'Betegadatok vannak rajta – kizárólag a tulajdonos jelenlétében tesztelhető.',
            ],
            [
                'kategoria' => 'tablet', 'gyarto' => 'Samsung', 'tipus' => 'SM-X716B', 'megnevezes' => 'Galaxy Tab S9 (5G)',
                'imei_alap' => '35712408300061', 'imei2_alap' => '35712408300062', 'sorozatszam' => 'R52W90ABCDE',
                'modell_szam' => 'SM-X716BZAAEUE', 'garancia_allapot' => 'Garanciális', 'gyartas_datuma' => '2023-08-09',
                'orszag' => 'Vietnám', 'szolgaltato' => 'Telekom', 'kep' => 'tablet.svg', 'szin' => 'Grafit',
                'zarkod' => '1122', 'minta' => '12369', 'tartozekok' => 'Töltő, Adatkábel, Tok, Toll (S Pen / Pencil), Doboz',
                'atveteli_allapot' => 'Hátlap tiszta, a keret apró kopással.',
                'garancias' => 1, 'vasarlas_datuma' => '2025-09-30', 'garancia_lejar' => '2027-09-30',
                'megjegyzes' => 'A töltés lassú, 30% fölött már nem tölt.',
                'belso_megjegyzes' => 'Céges eszköz, a javítás költségét a kft. állja; számla a cégre.',
            ],
            [
                'kategoria' => 'ora', 'gyarto' => 'Apple', 'tipus' => 'A2773', 'megnevezes' => 'Apple Watch Series 8 (Cellular)',
                'imei_alap' => '35428709300071', 'imei2_alap' => '', 'sorozatszam' => 'KQ5X8VABC9',
                'modell_szam' => 'MNUR3FD/A', 'garancia_allapot' => 'Lejárt', 'gyartas_datuma' => '2022-09-12',
                'orszag' => 'Kína', 'szolgaltato' => 'Feloldva', 'kep' => 'ora.svg', 'szin' => 'Éjfekete',
                'zarkod' => '8642', 'minta' => '', 'tartozekok' => 'Töltő, Adatkábel',
                'atveteli_allapot' => 'Üveg közepén két hajszálkarc, a pánt kopott.',
                'garancias' => 0, 'vasarlas_datuma' => '2022-11-18', 'garancia_lejar' => '2024-11-18',
                'megjegyzes' => 'Nem kapcsol be, töltőre sem reagál.',
                'belso_megjegyzes' => 'Akkumulátor-csere vagy alaplap; a tulajdonos az árajánlatot telefonon kéri.',
            ],
            [
                'kategoria' => 'ora', 'gyarto' => 'Samsung', 'tipus' => 'SM-R960', 'megnevezes' => 'Galaxy Watch6 Classic (LTE)',
                'imei_alap' => '35891602300081', 'imei2_alap' => '', 'sorozatszam' => 'RF9X30ABCDE',
                'modell_szam' => 'SM-R960NZKAEUE', 'garancia_allapot' => 'Garanciális', 'gyartas_datuma' => '2023-07-24',
                'orszag' => 'Vietnám', 'szolgaltato' => 'Yettel', 'kep' => 'ora.svg', 'szin' => 'Fekete',
                'zarkod' => '3690', 'minta' => '', 'tartozekok' => 'Töltő, Doboz',
                'atveteli_allapot' => 'Forgatható gyűrű kopott, üveg ép.',
                'garancias' => 1, 'vasarlas_datuma' => '2025-04-11', 'garancia_lejar' => '2027-04-11',
                'megjegyzes' => 'A pulzusmérő szenzor nem mér, a vízállóság bizonytalan.',
                'belso_megjegyzes' => 'Szenzor garanciális; a rendelő recepcióján szokták átvenni.',
            ],
            [
                'kategoria' => 'halozat', 'gyarto' => 'Huawei', 'tipus' => 'B535-232', 'megnevezes' => '4G+ mobil router',
                'imei_alap' => '86374005300091', 'imei2_alap' => '', 'sorozatszam' => 'RKH7S21A12345678',
                'modell_szam' => 'B535-232a', 'garancia_allapot' => 'Garanciális', 'gyartas_datuma' => '2024-02-06',
                'orszag' => 'Kína', 'szolgaltato' => 'Feloldva', 'kep' => 'router.svg', 'szin' => 'Fehér',
                'zarkod' => 'admin-2468', 'minta' => '', 'tartozekok' => 'Hálózati kábel, Adapter, Kézikönyv, Doboz',
                'atveteli_allapot' => 'A ház tiszta, a SIM-fedél kicsit lazán záródik.',
                'garancias' => 1, 'vasarlas_datuma' => '2025-01-20', 'garancia_lejar' => '2027-01-20',
                'megjegyzes' => 'Időnként elveszíti a mobil hálózatot, újraindítás kell.',
                'belso_megjegyzes' => 'Intézményi eszköz; firmware-frissítés és antennateszt, megrendelőszámmal.',
            ],
        ];
    }
}
