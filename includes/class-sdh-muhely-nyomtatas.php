<?php
/**
 * Nyomtatás (0.52): a nyomtatványok beállításai, a Nyomtatás ablak
 * (előnézet, nyomtatás, mentés PDF-be, küldés e-mailben, példányszám) és a
 * felajánlás új munkalapnál / lezáráskor.
 *
 * A PDF-et az SDH_Muhely_Nyomtatvany rajzolja; ez az osztály a körítés:
 *
 *  - tipusok()      a nyomtatványok (átvételi, munkalap, belső munkalap,
 *                   árajánlat, szállítólevél, átadási bizonylat, megrendelés,
 *                   bianko munkalap, pénztárzárás, pénztárjelentés,
 *                   pénztártétel, listák);
 *  - altalanos()    a közös megjelenés (logó, betű, méret, szín, margó,
 *                   fejléc, lábléc) – Beállítások › Nyomtatványok;
 *  - sablon()       egy nyomtatvány saját beállításai (cím, példányszám,
 *                   mi látszik rajta, szövegek, aláírás, e-mail) – popupban
 *                   szerkeszthető, próbanyomattal;
 *  - a Nyomtatás ablak: modul `nyomtatas` (ajax_urlap), a PDF: ajax_pdf,
 *    e-mail: ajax_email.
 *
 * A kódban nincs cégadat: a fejléc és a lábléc a Beállításokból jön, a
 * hosszú (jogi) szövegek alapból üresek.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Nyomtatas
{
    public const KULCS   = 'nyomtatas';
    private const OPTION = 'sdh_muhely_nyomtatas';
    private const AJANLAT = 'sdh_muhely_ny_ajanlat';

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_nyomtatas_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_nyomtatas_pdf', [self::class, 'ajax_pdf']);
        add_action('wp_ajax_sdh_muhely_nyomtatas_email', [self::class, 'ajax_email']);
        add_action('wp_ajax_sdh_muhely_nyomtatas_ajanlat', [self::class, 'ajax_ajanlat']);
        add_action('wp_ajax_sdh_muhely_nyomtsablon_urlap', [self::class, 'ajax_sablon_urlap']);
        add_action('wp_ajax_sdh_muhely_nyomtsablon_ment', [self::class, 'ajax_sablon_ment']);
        add_action('wp_ajax_sdh_muhely_nyomtsablon_alap', [self::class, 'ajax_sablon_alap']);

        add_action('admin_enqueue_scripts', [self::class, 'admin_eszkozok'], 30);

        // Felajánlás: új lapnál és lezáráskor (bárhonnan történik: űrlap, lista, fizetés, asszisztens).
        add_action('sdh_muhely_munkalap_mentve', [self::class, 'mentve'], 20, 2);
        add_action('sdh_muhely_munkalap_allapot', [self::class, 'allapot_valtott'], 20, 3);
    }

    public static function admin_eszkozok(): void
    {
        $oldal = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!str_starts_with($oldal, SDH_Muhely_Admin_UI::FOMENU)) {
            return;
        }

        wp_enqueue_style('sdh-muhely-nyomtatas', SDH_MUHELY_URL . 'assets/nyomtatas.css', ['sdh-muhely-admin'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/nyomtatas.css'));
        wp_enqueue_script('sdh-muhely-nyomtatas', SDH_MUHELY_URL . 'assets/nyomtatas.js', ['sdh-muhely-app'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/nyomtatas.js'), true);
    }

    private static function jog(bool $json = true): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            if ($json) {
                wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
            }

            wp_die('Nincs jogosultságod ehhez.', '', ['response' => 403]);
        }
    }

    /** Kérésparaméter szövegként (GET vagy POST). */
    private static function p(string $nev, int $max = 200): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- a hívó ellenőrizte.
        $ertek = $_REQUEST[$nev] ?? '';

        return is_scalar($ertek) ? mb_substr(sanitize_text_field(wp_unslash((string) $ertek)), 0, $max) : '';
    }

    /* =================================================================
     * Nyomtatványok
     * ============================================================== */

    /**
     * A nyomtatványok. `adat`: milyen adatból készül (a rajzoló elosztója);
     * `fajtak`: mely lapfajtánál választható; `fekvo`: fekvő lapra kerül.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tipusok(): array
    {
        $mind = ['javitas', 'arukiadas', 'rendeles'];

        return [
            'atveteli'  => ['nev' => 'Átvételi elismervény', 'adat' => 'lap', 'csoport' => 'lap', 'fajtak' => ['javitas'],
                'leiras' => 'Felvételkor jár az ügyfélnek: mit hozott be, milyen hibával, milyen állapotban. Ár nincs rajta.'],
            'munkalap'  => ['nev' => 'Munkalap', 'adat' => 'lap', 'csoport' => 'lap', 'fajtak' => ['javitas'],
                'leiras' => 'A kész javítás papírja: hibák, elvégzett munka, tételek, végösszeg, aláírás. Átadáskor megy az ügyfélnek.'],
            'belso'     => ['nev' => 'Belső munkalap', 'adat' => 'lap', 'csoport' => 'lap', 'fajtak' => ['javitas', 'rendeles'],
                'leiras' => 'A műhelynek: ár nélkül, a belső megjegyzéssel és a zárkóddal. Az ügyfélnek nem adható ki.'],
            'arajanlat' => ['nev' => 'Árajánlat', 'adat' => 'lap', 'csoport' => 'lap', 'fajtak' => $mind,
                'leiras' => 'A lap tételeiből készülő ajánlat, érvényességi idővel.'],
            'szallito'  => ['nev' => 'Szállítólevél', 'adat' => 'lap', 'csoport' => 'lap', 'fajtak' => $mind,
                'leiras' => 'Futárhoz, kiszállításhoz: címzett, eszköz, tételek – ár nélkül, átadó–átvevő aláírással.'],
            'eladas'    => ['nev' => 'Átadási bizonylat', 'adat' => 'lap', 'csoport' => 'lap', 'fajtak' => ['arukiadas', 'rendeles'],
                'leiras' => 'A napi árukiadás és az átadott rendelés papírja a tételekkel és a végösszeggel. Nem számla.'],
            'rendeles'  => ['nev' => 'Megrendelés', 'adat' => 'lap', 'csoport' => 'lap', 'fajtak' => ['rendeles'],
                'leiras' => 'A felvett rendelés visszaigazolása az ügyfélnek: mit rendelt, mennyi előleget fizetett.'],
            'bianko'    => ['nev' => 'Bianko munkalap', 'adat' => 'bianko', 'csoport' => 'ures',
                'leiras' => 'Üres, kézzel kitölthető munkalap – áramszünetre, kiszállásra, vagy ha épp nincs gép a közelben.'],
            'penztar_zaras'  => ['nev' => 'Pénztárzárás', 'adat' => 'zaras', 'csoport' => 'penz',
                'leiras' => 'Egy nap zárása: összesítő, megszámolt készpénz, eltérés, címletek, a nap tételei.'],
            'penztar_riport' => ['nev' => 'Pénztárjelentés', 'adat' => 'riport', 'csoport' => 'penz',
                'leiras' => 'Időszaki összesítő: bevételek fizetési mód szerint, kiadások, napok, kivét és kifizetések.'],
            'penztar_tetel'  => ['nev' => 'Pénztártétel-igazolás', 'adat' => 'ptetel', 'csoport' => 'penz',
                'leiras' => 'Egyetlen pénztártétel (bevétel, kivét, kifizetés) aláírható papírja. Belső nyilvántartás, nem szigorú számadású bizonylat.'],
            'lista_munkalap' => ['nev' => 'Lapok listája', 'adat' => 'lista_munkalap', 'csoport' => 'lista', 'fekvo' => true,
                'leiras' => 'Munkalapok, árukiadások vagy rendelések listája: nyitott, lezárt, fizetetlen, vagy egy időszak.'],
            'lista_keszlet'  => ['nev' => 'Készletlista', 'adat' => 'lista_keszlet', 'csoport' => 'lista', 'fekvo' => true,
                'leiras' => 'A készletkezelt termékek darabszáma és készletértéke – leltárhoz is.'],
            'lista_szamla'   => ['nev' => 'Számlák listája', 'adat' => 'lista_szamla', 'csoport' => 'lista', 'fekvo' => true,
                'leiras' => 'Az időszakban kiállított számlák és helyi nyomtatványok.'],
        ];
    }

    /** @return array<string, string> */
    public static function csoportok(): array
    {
        return ['lap' => 'Munkalap, árukiadás, rendelés', 'ures' => 'Üres nyomtatvány', 'penz' => 'Házipénztár', 'lista' => 'Listák és kimutatások'];
    }

    /**
     * A sablon mezői: kulcs => [fajta, címke, súgó]. fajta: pipa, szoveg, hosszu, szam, papir.
     *
     * @return array<string, array{0: string, 1: string, 2?: string}>
     */
    public static function mezok(): array
    {
        return [
            'cim'          => ['szoveg', 'A nyomtatvány címe', 'Ez áll nagy betűvel a lap tetején.'],
            'peldany'      => ['szam', 'Alap példányszám', 'Ennyi példány az alap; nyomtatáskor átírható (1–9).'],
            'papir'        => ['papir', 'Papírméret', 'Üresen az általános beállítás érvényes.'],
            'fekvo'        => ['pipa', 'Fekvő lap', 'A széles táblázat fekvő lapon jobban elfér.'],
            // Mi látszik
            'ugyfel'       => ['pipa', 'Ügyfél adatai'],
            'eszkoz'       => ['pipa', 'Eszköz adatai'],
            'tartozek'     => ['pipa', 'Tartozékok és az átvételi állapot'],
            'hibak'        => ['pipa', 'Hibák'],
            'hiba_allapot' => ['pipa', 'A hibasor állapota'],
            'javitas'      => ['pipa', 'Az elvégzett javítás szövege a hiba alatt'],
            'tetelek'      => ['pipa', 'Szolgáltatások és termékek'],
            'tervezett'    => ['pipa', 'A tervezett (még nem teljesített) tételek is'],
            'arak'         => ['pipa', 'Árak'],
            'osszesito'    => ['pipa', 'Összesítő (nettó, áfa, bruttó, fizetendő)'],
            'eloleg'       => ['pipa', 'Befizetett előleg (ár nélküli nyomtatványon is)'],
            'megjegyzes'   => ['pipa', 'Megjegyzés (az ügyfélnek szóló)'],
            'belso'        => ['pipa', 'Belső megjegyzés', 'Csak belső papíron kapcsold be – az ügyfél nem láthatja.'],
            'zarkod'       => ['pipa', 'Zárkód és feloldóminta', 'Csak belső papíron kapcsold be.'],
            'hatarido'     => ['pipa', 'Határidő a fejben'],
            'allapot'      => ['pipa', 'A lap állapota a fejben'],
            'felelos'      => ['pipa', 'Felelős a fejben'],
            'qr'           => ['pipa', 'QR-kód az ügyféloldalhoz', 'Az ügyfél a telefonjával beolvassa, és követi a javítás állapotát.'],
            'cimletek'     => ['pipa', 'Címletek táblázata'],
            'napok'        => ['pipa', 'Napok táblázata'],
            'kiadasok'     => ['pipa', 'Kivét és kifizetések bontása'],
            'hiba_sor'     => ['szam', 'Üres hibasorok száma'],
            'tetel_sor'    => ['szam', 'Üres tételsorok száma'],
            'megj_sor'     => ['szam', 'Üres megjegyzéssorok száma'],
            'ervenyes_nap' => ['szam', 'Az ajánlat érvényessége (nap)', '0 = nem írunk érvényességet.'],
            // Szövegek
            'bevezeto'     => ['hosszu', 'Bevezető szöveg (a fej alatt)'],
            'qr_szoveg'    => ['szoveg', 'A QR-kód melletti szöveg'],
            'jogi_cim'     => ['szoveg', 'Az apró betűs rész címe'],
            'jogi'         => ['hosszu', 'Apró betűs szöveg (tudnivalók, feltételek)', 'Ide másold a saját átvételi / garanciális feltételeidet. Hosszú is lehet: új oldalra törik.'],
            'zaro'         => ['hosszu', 'Záró szöveg (az aláírás fölött)'],
            // Aláírás
            'alairas'      => ['pipa', 'Kelt és aláírás'],
            'alairas_bal'  => ['szoveg', 'Bal oldali aláírás felirata'],
            'alairas_jobb' => ['szoveg', 'Jobb oldali aláírás felirata'],
            'igazolvany'   => ['pipa', '„Átvevő neve” és „Igazolványszám” sor'],
            'ph'           => ['pipa', 'P. H. (bélyegző helye)'],
            // E-mail
            'email_targy'  => ['szoveg', 'Az e-mail tárgya'],
            'email_szoveg' => ['hosszu', 'Az e-mail szövege'],
        ];
    }

    /** Melyik mező melyik adatfajtánál értelmes (a szerkesztő csak ezeket mutatja). */
    private static function mezo_csoportok(string $adat, string $tipus = ''): array
    {
        $fej    = ['cim', 'peldany', 'papir'];
        $alair  = ['alairas', 'alairas_bal', 'alairas_jobb'];
        $email  = ['email_targy', 'email_szoveg'];

        switch ($adat) {
            case 'lap':
                return [
                    'Alapok'       => array_merge($fej, $tipus === 'arajanlat' ? ['ervenyes_nap'] : []),
                    'Mi látszik rajta' => ['ugyfel', 'eszkoz', 'tartozek', 'hibak', 'hiba_allapot', 'javitas', 'tetelek', 'tervezett', 'arak', 'osszesito', 'eloleg',
                        'megjegyzes', 'belso', 'zarkod', 'hatarido', 'allapot', 'felelos', 'qr'],
                    'Szövegek'     => ['bevezeto', 'qr_szoveg', 'jogi_cim', 'jogi', 'zaro'],
                    'Aláírás'      => array_merge($alair, ['igazolvany', 'ph']),
                    'E-mail'       => $email,
                ];
            case 'bianko':
                return [
                    'Alapok'   => array_merge($fej, ['hiba_sor', 'tetel_sor', 'megj_sor']),
                    'Szövegek' => ['bevezeto', 'jogi_cim', 'jogi', 'zaro'],
                    'Aláírás'  => array_merge($alair, ['igazolvany', 'ph']),
                ];
            case 'zaras':
                return ['Alapok' => $fej, 'Mi látszik rajta' => ['cimletek', 'tetelek'], 'Szövegek' => ['bevezeto', 'zaro'], 'Aláírás' => $alair, 'E-mail' => $email];
            case 'riport':
                return ['Alapok' => $fej, 'Mi látszik rajta' => ['napok', 'kiadasok'], 'Szövegek' => ['bevezeto', 'zaro'], 'Aláírás' => $alair, 'E-mail' => $email];
            case 'ptetel':
                return ['Alapok' => $fej, 'Szövegek' => ['bevezeto', 'zaro'], 'Aláírás' => $alair, 'E-mail' => $email];
        }

        return ['Alapok' => array_merge($fej, ['fekvo']), 'Szövegek' => ['bevezeto', 'zaro'], 'E-mail' => $email];
    }

    /** A nyomtatvány gyári beállításai. @return array<string, mixed> */
    public static function alap_sablon(string $tipus): array
    {
        $t = self::tipusok()[$tipus] ?? null;

        $kozos = [
            'cim' => $t ? (string) $t['nev'] : 'Nyomtatvány', 'peldany' => 1, 'papir' => '', 'fekvo' => 1,
            'ugyfel' => 1, 'eszkoz' => 1, 'tartozek' => 1, 'hibak' => 1, 'hiba_allapot' => 0, 'javitas' => 0, 'tetelek' => 1, 'tervezett' => 1,
            'arak' => 1, 'osszesito' => 1, 'eloleg' => 1, 'megjegyzes' => 1, 'belso' => 0, 'zarkod' => 0, 'hatarido' => 0, 'allapot' => 0, 'felelos' => 0, 'qr' => 0,
            'cimletek' => 1, 'napok' => 1, 'kiadasok' => 1, 'hiba_sor' => 4, 'tetel_sor' => 5, 'megj_sor' => 3, 'ervenyes_nap' => 0,
            'bevezeto' => '', 'qr_szoveg' => 'A javítás állapota itt követhető:', 'jogi_cim' => 'Tudnivalók', 'jogi' => '', 'zaro' => '',
            'alairas' => 1, 'alairas_bal' => 'Kiállító', 'alairas_jobb' => 'Ügyfél', 'igazolvany' => 0, 'ph' => 0,
            'email_targy' => '{cim} – {szam}',
            'email_szoveg' => "Tisztelt {ugyfel}!\n\nCsatolva küldjük: {cim} ({szam}).\n\nÜdvözlettel:\n{ceg}",
        ];

        $sajat = [
            'atveteli'  => ['peldany' => 2, 'tetelek' => 0, 'arak' => 0, 'osszesito' => 0, 'hatarido' => 1, 'qr' => 1,
                'zaro' => 'A fenti eszközt javításra / bevizsgálásra átvettük.', 'alairas_bal' => 'Átvevő (szerviz)', 'alairas_jobb' => 'Átadó (ügyfél)'],
            'munkalap'  => ['javitas' => 1, 'hiba_allapot' => 1,
                'zaro' => 'A javított / bevizsgált eszközt kipróbáltam, és hiánytalanul átvettem.', 'alairas_jobb' => 'Átvevő (ügyfél)'],
            'belso'     => ['arak' => 0, 'osszesito' => 0, 'eloleg' => 0, 'belso' => 1, 'zarkod' => 1, 'javitas' => 1, 'hiba_allapot' => 1, 'hatarido' => 1, 'allapot' => 1,
                'felelos' => 1, 'alairas' => 0],
            'arajanlat' => ['tartozek' => 0, 'ervenyes_nap' => 15, 'eloleg' => 0,
                'zaro' => 'Az ajánlat a feltüntetett tételekre vonatkozik; a javítás közben feltárt további hibáról külön tájékoztatjuk.',
                'alairas_bal' => 'Az ajánlatot adta', 'alairas_jobb' => 'Elfogadom (ügyfél)',
                'email_szoveg' => "Tisztelt {ugyfel}!\n\nCsatolva küldjük árajánlatunkat ({szam}), végösszege: {vegosszeg}.\nKérjük, jelezze vissza, hogy elfogadja-e.\n\nÜdvözlettel:\n{ceg}"],
            'szallito'  => ['peldany' => 2, 'hibak' => 0, 'arak' => 0, 'osszesito' => 0, 'eloleg' => 0, 'megjegyzes' => 1, 'igazolvany' => 1,
                'alairas_bal' => 'Átadó', 'alairas_jobb' => 'Átvevő'],
            'eladas'    => ['eszkoz' => 0, 'hibak' => 0, 'tartozek' => 0,
                'zaro' => 'A fenti termékeket / szolgáltatásokat hiánytalanul átvettem.', 'alairas_bal' => 'Átadó', 'alairas_jobb' => 'Átvevő'],
            'rendeles'  => ['eszkoz' => 0, 'hibak' => 0, 'tartozek' => 0, 'alairas_bal' => 'Felvette', 'alairas_jobb' => 'Megrendelő'],
            'bianko'    => ['cim' => 'Munkalap', 'alairas_bal' => 'Átvevő (szerviz)', 'alairas_jobb' => 'Ügyfél'],
            'penztar_zaras'  => ['alairas_bal' => 'Zárta', 'alairas_jobb' => 'Ellenőrizte',
                'email_szoveg' => "Csatolva: {cim} ({szam}).\n\n{ceg}"],
            'penztar_riport' => ['alairas' => 0, 'alairas_bal' => 'Készítette', 'alairas_jobb' => 'Ellenőrizte',
                'email_szoveg' => "Csatolva: {cim} ({szam}).\n\n{ceg}"],
            'penztar_tetel'  => ['peldany' => 2, 'alairas_bal' => 'Pénztáros', 'alairas_jobb' => 'Átvevő / befizető',
                'email_szoveg' => "Csatolva: {cim} ({szam}).\n\n{ceg}"],
            'lista_munkalap' => ['email_szoveg' => "Csatolva: {cim}.\n\n{ceg}", 'email_targy' => '{cim} – {datum}'],
            'lista_keszlet'  => ['email_szoveg' => "Csatolva: {cim}.\n\n{ceg}", 'email_targy' => '{cim} – {datum}'],
            'lista_szamla'   => ['email_szoveg' => "Csatolva: {cim}.\n\n{ceg}", 'email_targy' => '{cim} – {datum}'],
        ];

        return array_merge($kozos, $sajat[$tipus] ?? []);
    }

    /** @return array<string, mixed> A teljes mentett beállítás. */
    private static function nyers(): array
    {
        $b = get_option(self::OPTION, []);

        return is_array($b) ? $b : [];
    }

    /** Egy nyomtatvány érvényes beállításai (gyári + mentett). @return array<string, mixed> */
    public static function sablon(string $tipus): array
    {
        $alap    = self::alap_sablon($tipus);
        $mentett = self::nyers()['sablonok'][$tipus] ?? [];

        return array_merge($alap, is_array($mentett) ? array_intersect_key($mentett, $alap) : []);
    }

    /** Egy beküldött / mentett érték megtisztítása a mező fajtája szerint. */
    private static function tisztit(string $kulcs, $ertek)
    {
        $fajta = self::mezok()[$kulcs][0] ?? 'szoveg';

        switch ($fajta) {
            case 'pipa':
                return !empty($ertek) ? 1 : 0;
            case 'szam':
                $max = ['peldany' => 9, 'hiba_sor' => 12, 'tetel_sor' => 15, 'megj_sor' => 10, 'ervenyes_nap' => 365][$kulcs] ?? 99;

                return min($max, max($kulcs === 'peldany' ? 1 : 0, (int) $ertek));
            case 'papir':
                return in_array($ertek, ['A4', 'A5'], true) ? $ertek : '';
            case 'hosszu':
                return mb_substr(sanitize_textarea_field((string) $ertek), 0, 20000);
        }

        return mb_substr(sanitize_text_field((string) $ertek), 0, 200);
    }

    /* =================================================================
     * Általános megjelenés
     * ============================================================== */

    /** A CRM kiemelő színe – ez az alap, amíg a nyomtatványokhoz nincs saját megadva. */
    private static function alap_szin(): string
    {
        if (class_exists('SDH_Muhely_Arculat')) {
            $b      = SDH_Muhely_Arculat::beallitas();
            $szinek = SDH_Muhely_Arculat::szinek();
            $hex    = (string) ($szinek[(string) ($b['szin'] ?? '')]['hex'] ?? '');

            if (preg_match('/^#[0-9a-f]{6}$/i', $hex) === 1) {
                return strtolower($hex);
            }
        }

        return '#33475b';
    }

    /** @return array<string, mixed> */
    public static function altalanos(): array
    {
        $alap = [
            'betu' => 'dejavu', 'meret' => 9.5, 'papir' => 'A4', 'margo' => 12, 'szin' => '',
            'logo' => '', 'fej_logo' => 1, 'logo_szel' => 34, 'logo_hely' => 'bal', 'fej_ceg' => 1,
            'ceg' => '', 'lab' => '', 'kelt_hely' => '',
            'ajanl_uj' => 1, 'ajanl_zar' => 1, 'ajanl_eladas' => 1,
            'email_fiok' => '', 'email_masolat' => '',
        ];

        $m = self::nyers()['altalanos'] ?? [];
        $b = array_merge($alap, is_array($m) ? array_intersect_key($m, $alap) : []);

        require_once SDH_MUHELY_DIR . 'includes/lib/sdh-nyomtatvany-pdf.php';

        $b['betu']      = isset(SDH_Muhely_Nyomtatvany_Pdf::BETUK[(string) $b['betu']]) ? (string) $b['betu'] : 'dejavu';
        $b['meret']     = min(13.0, max(7.0, (float) $b['meret']));
        $b['papir']     = $b['papir'] === 'A5' ? 'A5' : 'A4';
        $b['margo']     = min(25.0, max(6.0, (float) $b['margo']));
        $b['szin']      = preg_match('/^#[0-9a-f]{6}$/i', (string) $b['szin']) === 1 ? strtolower((string) $b['szin']) : self::alap_szin();
        $b['logo_szel'] = min(80.0, max(10.0, (float) $b['logo_szel']));
        $b['logo_hely'] = in_array($b['logo_hely'], ['bal', 'jobb', 'kozep'], true) ? (string) $b['logo_hely'] : 'bal';

        return $b;
    }

    /** @return array<int, int> */
    public static function szin_rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
            return [51, 71, 91];
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** A logó fájlja a szerveren (saját → a Számlázás logója → a csomagolt). */
    public static function logo_fajl(?array $a = null): string
    {
        $a = $a ?? self::altalanos();

        return class_exists('SDH_Muhely_Szamla') ? SDH_Muhely_Szamla::logo_fajl((string) $a['logo']) : '';
    }

    /** Több soros szöveg sorai (üresek nélkül). @return array<int, string> */
    private static function sorok(string $szoveg, int $max): array
    {
        $ki = [];

        foreach (preg_split('/\R/u', $szoveg) ?: [] as $sor) {
            $sor = trim($sor);

            if ($sor !== '') {
                $ki[] = $sor;
            }
        }

        return array_slice($ki, 0, $max);
    }

    /**
     * A fejléc cégadatai. Ha a Nyomtatványok lapfülön nincs megadva, a
     * Számlázás lapfül kiállítói adataiból áll össze (egy helyen kell beírni).
     *
     * @return array<int, string> az első sor a cégnév
     */
    public static function ceg_sorok(?array $a = null): array
    {
        $a     = $a ?? self::altalanos();
        $sajat = self::sorok((string) $a['ceg'], 7);

        if ($sajat !== []) {
            return $sajat;
        }

        $sz = class_exists('SDH_Muhely_Szamla') ? SDH_Muhely_Szamla::beallitas() : [];
        $r  = class_exists('SDH_Muhely_Rma') ? SDH_Muhely_Rma::beallitas() : [];

        $nev = trim((string) ($sz['kiallito_nev'] ?? '')) !== '' ? (string) $sz['kiallito_nev'] : (string) ($r['szerviz_nev'] ?? get_bloginfo('name'));
        $cim = trim(implode(' ', array_filter([(string) ($sz['kiallito_cim1'] ?? ''), (string) ($sz['kiallito_cim2'] ?? '')])));
        $cim = $cim !== '' ? $cim : (string) ($r['cim'] ?? '');
        $tel = trim((string) ($sz['telefon'] ?? '')) !== '' ? (string) $sz['telefon'] : (string) ($r['telefon'] ?? '');

        return array_values(array_filter([
            $nev,
            $cim,
            trim((string) ($sz['kiallito_adoszam'] ?? '')) !== '' ? 'Adószám: ' . $sz['kiallito_adoszam'] : '',
            implode('  ·  ', array_filter([$tel !== '' ? 'Tel.: ' . $tel : '', (string) ($r['email'] ?? '')])),
        ], static fn (string $s): bool => trim($s) !== ''));
    }

    /** A lábléc sorai. @return array<int, string> */
    public static function lab_sorok(?array $a = null): array
    {
        $a     = $a ?? self::altalanos();
        $sajat = self::sorok((string) $a['lab'], 4);

        if ($sajat !== []) {
            return $sajat;
        }

        $r = class_exists('SDH_Muhely_Rma') ? SDH_Muhely_Rma::beallitas() : [];

        return array_values(array_filter([
            implode('  ·  ', array_filter([(string) ($r['weboldal'] ?? ''), (string) ($r['email'] ?? ''), (string) ($r['telefon'] ?? '')])),
            trim((string) preg_replace('/\s*\R\s*/u', '  ·  ', (string) ($r['nyitvatartas'] ?? ''))),
        ], static fn (string $s): bool => trim($s) !== ''));
    }

    /* =================================================================
     * Felajánlás: új lapnál és lezáráskor
     * ============================================================== */

    /** Melyik nyomtatványt ajánljuk az adott eseménynél (üres = semmit). */
    private static function ajanlott(string $fajta, string $esemeny): string
    {
        $a = self::altalanos();

        if ($esemeny === 'uj') {
            if ($fajta === 'javitas') {
                return !empty($a['ajanl_uj']) ? 'atveteli' : '';
            }

            return $fajta === 'rendeles' && !empty($a['ajanl_eladas']) ? 'rendeles' : '';
        }

        if ($fajta === 'javitas') {
            return !empty($a['ajanl_zar']) ? 'munkalap' : '';
        }

        return !empty($a['ajanl_eladas']) ? 'eladas' : '';
    }

    private static function ajanlat_jegyez(int $id, string $esemeny): void
    {
        $user = get_current_user_id();

        if ($user <= 0 || $id <= 0 || wp_doing_cron()) {
            return;
        }

        $m = class_exists('SDH_Muhely_Rma') ? SDH_Muhely_Rma::munkalap($id) : null;

        if ($m === null) {
            return;
        }

        $fajta = SDH_Muhely_Munkalap::fajta_ervenyes((string) ($m->fajta ?? ''));
        $tipus = self::ajanlott($fajta, $esemeny);

        if ($tipus === '' || empty(self::sablon($tipus)['peldany'])) {
            return;
        }

        $szam = SDH_Muhely_Munkalap::szam_formaz($m->munkalap_szam);

        update_user_meta($user, self::AJANLAT, [
            'id'      => $id,
            'tipus'   => $tipus,
            'esemeny' => $esemeny,
            'szam'    => $szam === '—' ? '' : $szam,
            'ido'     => time(),
        ]);
    }

    /** Új lap mentése után (a régi állapot null). */
    public static function mentve($id, $regi): void
    {
        // Kikapcsolt Nyomtatványok modul (Modulok): nincs nyomtatási felajánlás.
        if (SDH_Muhely_Modulok::ki('nyomtatas')) {
            return;
        }

        if ($regi === null) {
            self::ajanlat_jegyez((int) $id, 'uj');
        }
    }

    /** Állapotváltás: ha a lap most lett lezárva (és nem érvénytelen / törölt állapotba került). */
    public static function allapot_valtott($id, $regi, $uj): void
    {
        if (SDH_Muhely_Modulok::ki('nyomtatas')) {
            return;
        }

        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $uj        = (string) $uj;
        $regi      = (string) $regi;

        if (empty($allapotok[$uj]['zart']) || !empty($allapotok[$uj]['ervenytelen']) || !empty($allapotok[$regi]['zart'])) {
            return;
        }

        self::ajanlat_jegyez((int) $id, 'zaras');
    }

    /** Van-e a belépett felhasználónak friss, még meg nem mutatott ajánlata. */
    public static function van_ajanlat(): bool
    {
        $a = get_user_meta(get_current_user_id(), self::AJANLAT, true);

        return is_array($a) && (int) ($a['ido'] ?? 0) > time() - 180;
    }

    public static function ajax_ajanlat(): void
    {
        self::jog();

        $user = get_current_user_id();
        $a    = get_user_meta($user, self::AJANLAT, true);

        // Nyugta: a böngésző megmutatta (vagy a felhasználó döntött róla) – csak ekkor törlődik. Így nem vész el,
        // ha a mentés utáni oldalváltás megszakítja a lekérést.
        if (self::p('nyugta', 2) === '1') {
            delete_user_meta($user, self::AJANLAT);
            wp_send_json_success(['ajanlat' => null]);
        }

        if (!is_array($a) || (int) ($a['ido'] ?? 0) <= time() - 180) {
            if (is_array($a)) {
                delete_user_meta($user, self::AJANLAT);
            }

            wp_send_json_success(['ajanlat' => null]);
        }

        $tipus = (string) $a['tipus'];
        $s     = self::sablon($tipus);

        // Lezáráskor a számla is innen indítható (0.59.6): Számlázz.hu (ha a Számlázás modul be van
        // kapcsolva) és a helyi Számla-előkészítő – csak sorszámot kapott lapra.
        $szamla = ['fo' => false, 'helyi' => false];

        if ((string) $a['esemeny'] === 'zaras' && class_exists('SDH_Muhely_Szamla') && (string) $a['szam'] !== '') {
            $szamla = ['fo' => SDH_Muhely_Modulok::be('szamlazas'), 'helyi' => true];
        }

        wp_send_json_success(['ajanlat' => [
            'id'      => (int) $a['id'],
            'tipus'   => $tipus,
            'cim'     => (string) $s['cim'],
            'szam'    => (string) $a['szam'],
            'esemeny' => (string) $a['esemeny'],
            'peldany' => (int) $s['peldany'],
            'szamla'  => $szamla,
            'szoveg'  => (string) $a['esemeny'] === 'uj'
                ? 'A lap elkészült' . ((string) $a['szam'] !== '' ? ' (' . $a['szam'] . ')' : '') . '. Kinyomtatod: ' . $s['cim'] . '?'
                : 'A lap lezárva' . ((string) $a['szam'] !== '' ? ' (' . $a['szam'] . ')' : '') . '. Kinyomtatod: ' . $s['cim'] . '?',
        ]]);
    }

    /** A böngészőnek (SDH_MUHELY.nyomtat). */
    public static function js_adat(): array
    {
        return ['ajanlat' => is_user_logged_in() && SDH_Muhely_Modulok::be('nyomtatas') && self::van_ajanlat()];
    }

    /* =================================================================
     * Gombok más modulok felületén
     * ============================================================== */

    public static function ikon(): string
    {
        return '<svg viewBox="0 0 20 20" aria-hidden="true" class="sdh-ny-ikon"><path d="M5.5 7V3h9v4"/><rect x="3" y="7" width="14" height="7" rx="1.6"/><path d="M5.5 12h9v5h-9z"/><path d="M14 9.6h.1"/></svg>';
    }

    /**
     * Nyomtatás gomb. A nyomtatas.js a saját szintje FÖLÉ nyitja az ablakot,
     * így a munkalap-ablak nyitva marad alatta.
     *
     * @param array<string, string|int> $param  tipus, datum, tol, ig, szuro…
     */
    public static function gomb(int $id, array $param = [], string $felirat = 'Nyomtatás', string $osztaly = 'sdh-gomb sdh-gomb--vilagos', string $cimke = ''): string
    {
        // Kikapcsolt Nyomtatványok modul (Modulok): sehol nincs nyomtatógomb.
        if (SDH_Muhely_Modulok::ki('nyomtatas')) {
            return '';
        }

        $attr = '';

        foreach ($param as $k => $v) {
            $attr .= ' data-ny-' . sanitize_key((string) $k) . '="' . esc_attr((string) $v) . '"';
        }

        return '<button type="button" class="' . esc_attr($osztaly) . ' sdh-ny-gomb" data-sdh-nyomtat="' . (int) $id . '"' . $attr
            . ' title="' . esc_attr($cimke !== '' ? $cimke : 'Nyomtatás, mentés PDF-be, küldés e-mailben') . '">'
            . self::ikon() . ($felirat !== '' ? '<span>' . esc_html($felirat) . '</span>' : '') . '</button>';
    }

    /* =================================================================
     * A Nyomtatás ablak
     * ============================================================== */

    /** A kérés nyomtatvány-paraméterei (a PDF-hez és az ablakhoz). @return array<string, mixed> */
    private static function parameterek(): array
    {
        $datum = static fn (string $d): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : '';

        // phpcs:ignore WordPress.Security.NonceVerification -- a hívó ellenőrizte.
        $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

        return [
            'id'     => max(0, $id),
            'eszkoz' => self::p('eszkoz', 12) === '' ? '' : (string) max(0, (int) self::p('eszkoz', 12)),
            'datum'  => $datum(self::p('datum', 10)),
            'tol'    => $datum(self::p('tol', 10)),
            'ig'     => $datum(self::p('ig', 10)),
            'szuro'  => sanitize_key(self::p('szuro', 20)),
            'fajta'  => sanitize_key(self::p('fajta', 20)),
            'minta'  => self::p('minta', 2) === '1' ? 1 : 0,
        ];
    }

    /**
     * Mely nyomtatványok választhatók az ablakban ebben a helyzetben.
     *
     * @return array<int, string>
     */
    private static function valaszthato(array $p, string $kert): array
    {
        $tipusok = self::tipusok();

        // Munkalaphoz (árukiadáshoz, rendeléshez) nyitva: a lapfajta nyomtatványai.
        if ((int) $p['id'] > 0 && (!isset($tipusok[$kert]) || (string) $tipusok[$kert]['adat'] === 'lap')) {
            $m     = class_exists('SDH_Muhely_Rma') ? SDH_Muhely_Rma::munkalap((int) $p['id']) : null;
            $fajta = $m ? SDH_Muhely_Munkalap::fajta_ervenyes((string) ($m->fajta ?? '')) : 'javitas';

            $lista = array_keys(array_filter($tipusok, static fn (array $t): bool => (string) $t['adat'] === 'lap' && in_array($fajta, (array) $t['fajtak'], true)));

            // Eladásnál a saját papírja álljon elöl (megrendelés, átadási bizonylat), utána a többi.
            $elol = $fajta === 'rendeles' ? ['rendeles', 'eladas'] : ($fajta === 'arukiadas' ? ['eladas'] : []);

            return array_values(array_unique(array_merge(array_intersect($elol, $lista), $lista)));
        }

        // Próbanyomat, pénztártétel: csak a kért nyomtatvány.
        if (!empty($p['minta']) || $kert === 'penztar_tetel') {
            return isset($tipusok[$kert]) ? [$kert] : [];
        }

        // Pénztárból nyitva: a pénztár nyomtatványai; egyébként a gyűjtő (üres + pénz + listák).
        if (isset($tipusok[$kert]) && (string) $tipusok[$kert]['csoport'] === 'penz') {
            return ['penztar_zaras', 'penztar_riport'];
        }

        return array_values(array_filter(
            array_keys($tipusok),
            static fn (string $k): bool => (string) $tipusok[$k]['adat'] !== 'lap' && $k !== 'penztar_tetel'
        ));
    }

    /**
     * Melyik nyomtatvány legyen kiválasztva, ha a hívó nem kérte: nyitott javításnál az
     * átvételi elismervény, lezártnál a munkalap; átadott rendelésnél az átadási bizonylat.
     *
     * @param array<int, string> $lista
     */
    private static function alap_tipus(array $p, array $lista): string
    {
        $m = (int) $p['id'] > 0 && class_exists('SDH_Muhely_Rma') ? SDH_Muhely_Rma::munkalap((int) $p['id']) : null;

        if ($m !== null) {
            $allapotok = SDH_Muhely_Munkalap::allapotok();
            $a         = $allapotok[(string) $m->allapot] ?? [];
            $kesz      = !empty($a['zart']) || !empty($a['kesz']);
            $fajta     = SDH_Muhely_Munkalap::fajta_ervenyes((string) ($m->fajta ?? ''));
            $jelolt    = $fajta === 'javitas' ? ($kesz ? 'munkalap' : 'atveteli') : ($fajta === 'rendeles' && !$kesz ? 'rendeles' : 'eladas');

            if (in_array($jelolt, $lista, true)) {
                return $jelolt;
            }
        }

        return $lista[0];
    }

    public static function ajax_urlap(): void
    {
        self::jog(false);

        $p       = self::parameterek();
        $kert    = sanitize_key(self::p('tipus', 30));
        $tipusok = self::tipusok();
        $lista   = self::valaszthato($p, $kert);

        if ($lista === []) {
            echo '<h2 class="sdh-modal__cim">Nyomtatás</h2><div class="sdh-uzenet sdh-uzenet--hiba">Ismeretlen nyomtatvány.</div>';
            wp_die();
        }

        $aktiv = in_array($kert, $lista, true) ? $kert : self::alap_tipus($p, $lista);
        $a     = self::altalanos();
        $lap   = null;
        $cim   = 'Nyomtatás';
        $email = '';
        $eszkozok = [];

        if ((int) $p['id'] > 0 && (string) $tipusok[$aktiv]['adat'] === 'lap') {
            $lap = SDH_Muhely_Nyomtatvany::lap_adat((int) $p['id']);

            if (is_string($lap)) {
                echo '<h2 class="sdh-modal__cim">Nyomtatás</h2><div class="sdh-uzenet sdh-uzenet--hiba">' . esc_html($lap) . '</div>';
                wp_die();
            }

            $cim   = 'Nyomtatás – ' . $lap['szam'] . ((string) $lap['ugyfel_nev'] !== '' ? ' · ' . $lap['ugyfel_nev'] : '');
            $email = (string) $lap['ugyfel_email'];

            if ($lap['tobb']) {
                foreach ($lap['bontas'] as $b) {
                    $eszkozok[(int) $b['eszkoz_id']] = (string) $b['felirat'];
                }
            }
        } elseif (!empty($p['minta'])) {
            $cim = 'Próbanyomat – ' . self::sablon($aktiv)['cim'];
        }

        // A nyomtatványok adatai a böngészőnek: cím, alap példányszám, e-mail-sablon, milyen szűrő kell.
        $adat = [];

        foreach ($lista as $k) {
            $s = self::sablon($k);
            $d = is_array($lap) ? $lap : ['szam' => ''];

            $adat[$k] = [
                'cim'     => (string) $s['cim'],
                'peldany' => (int) $s['peldany'],
                'adat'    => (string) $tipusok[$k]['adat'],
                // Belső papírnál nincs előre kitöltött címzett: az nem az ügyfélnek megy.
                'cimzett' => self::belso_e($k) ? '' : $email,
                'targy'   => SDH_Muhely_Nyomtatvany::helyettesit((string) $s['email_targy'], $d, (string) $s['cim']),
                'szoveg'  => SDH_Muhely_Nyomtatvany::helyettesit((string) $s['email_szoveg'], $d, (string) $s['cim']),
            ];
        }

        $fiokok = class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Modulok::be('levelezes') ? SDH_Muhely_Levelezes::kuldo_fiokok() : [];
        $ma     = current_time('Y-m-d');

        ?>
        <h2 class="sdh-modal__cim"><?php echo esc_html($cim); ?></h2>

        <div class="sdh-ny" data-sdh-ny
             data-id="<?php echo (int) $p['id']; ?>"
             data-minta="<?php echo (int) $p['minta']; ?>"
             data-tipus="<?php echo esc_attr($aktiv); ?>"
             data-nonce="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>"
             data-adat="<?php echo esc_attr((string) wp_json_encode($adat)); ?>">

            <div class="sdh-ny__bal">
                <?php if (count($lista) > 1) : ?>
                    <div class="sdh-ny__tipusok" role="tablist" aria-label="Nyomtatvány">
                        <?php
                        $elozo = '';

                        foreach ($lista as $k) :
                            $csoport = (string) $tipusok[$k]['csoport'];

                            if ($lap === null && $csoport !== $elozo) {
                                echo '<span class="sdh-ny__csoport">' . esc_html(self::csoportok()[$csoport] ?? '') . '</span>';
                                $elozo = $csoport;
                            }
                            ?>
                            <button type="button" role="tab" class="sdh-ny__tipus<?php echo $k === $aktiv ? ' is-aktiv' : ''; ?>"
                                    data-ny-tipus="<?php echo esc_attr($k); ?>" aria-selected="<?php echo $k === $aktiv ? 'true' : 'false'; ?>"
                                    title="<?php echo esc_attr((string) $tipusok[$k]['leiras']); ?>">
                                <?php // A bianko lap címe is „Munkalap” lehet: a gombon a nyomtatvány neve áll, hogy megkülönböztethető legyen. ?>
                                <?php echo esc_html((string) $tipusok[$k]['adat'] === 'bianko' ? (string) $tipusok[$k]['nev'] : (string) self::sablon($k)['cim']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="sdh-ny__opciok">
                    <?php if ($eszkozok !== []) : ?>
                        <div class="sdh-mezo" data-ny-csak="lap">
                            <label for="ny_eszkoz">Melyik eszköz</label>
                            <select id="ny_eszkoz" data-ny-param="eszkoz">
                                <option value="">Mind a(z) <?php echo count($eszkozok); ?> eszköz egy lapon</option>
                                <?php foreach ($eszkozok as $k => $felirat) : ?>
                                    <option value="<?php echo (int) $k; ?>"<?php selected((string) $p['eszkoz'], (string) $k); ?>>Csak: <?php echo esc_html($felirat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="sdh-mezo" data-ny-csak="zaras" hidden>
                        <label for="ny_datum">Melyik nap</label>
                        <input type="text" id="ny_datum" class="sdh-pop sdh-pop--datum" readonly autocomplete="off" data-sdh-pop="datum" data-sdh-pop-adat="[]"
                               placeholder="éééé-hh-nn" data-ny-param="datum" value="<?php echo esc_attr($p['datum'] !== '' ? $p['datum'] : $ma); ?>">
                    </div>

                    <div class="sdh-ny__ketto" data-ny-csak="riport lista_munkalap lista_szamla" hidden>
                        <div class="sdh-mezo">
                            <label for="ny_tol">Ettől</label>
                            <input type="text" id="ny_tol" class="sdh-pop sdh-pop--datum" readonly autocomplete="off" data-sdh-pop="datum" data-sdh-pop-adat="[]"
                                   placeholder="éééé-hh-nn" data-ny-param="tol" value="<?php echo esc_attr($p['tol'] !== '' ? $p['tol'] : substr($ma, 0, 8) . '01'); ?>">
                        </div>
                        <div class="sdh-mezo">
                            <label for="ny_ig">Eddig</label>
                            <input type="text" id="ny_ig" class="sdh-pop sdh-pop--datum" readonly autocomplete="off" data-sdh-pop="datum" data-sdh-pop-adat="[]"
                                   placeholder="éééé-hh-nn" data-ny-param="ig" value="<?php echo esc_attr($p['ig'] !== '' ? $p['ig'] : $ma); ?>">
                        </div>
                    </div>

                    <div class="sdh-ny__ketto" data-ny-csak="lista_munkalap" hidden>
                        <div class="sdh-mezo">
                            <label for="ny_fajta">Mit listázzak</label>
                            <select id="ny_fajta" data-ny-param="fajta">
                                <?php foreach (SDH_Muhely_Munkalap::fajtak() as $k => $f) : ?>
                                    <option value="<?php echo esc_attr((string) $k); ?>"<?php selected($p['fajta'], (string) $k); ?>><?php echo esc_html(SDH_Muhely_Munkalap::fajta_nev((string) $k)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sdh-mezo">
                            <label for="ny_szuro_ml">Melyeket</label>
                            <select id="ny_szuro_ml" data-ny-param="szuro" data-ny-param-csak="lista_munkalap">
                                <option value="nyitott">Nyitott lapok (mind)</option>
                                <option value="fizetetlen">Fizetetlen lapok (mind)</option>
                                <option value="lezart">Lezártak az időszakban</option>
                                <option value="mind">Minden lap az időszakban</option>
                            </select>
                        </div>
                    </div>

                    <div class="sdh-mezo" data-ny-csak="lista_keszlet" hidden>
                        <label for="ny_szuro_k">Mely termékek</label>
                        <select id="ny_szuro_k" data-ny-param="szuro" data-ny-param-csak="lista_keszlet">
                            <option value="keszleten">Ami készleten van</option>
                            <option value="fogyo">Ami fogyóban van (minimum alatt)</option>
                            <option value="mind">Minden készletkezelt termék</option>
                        </select>
                    </div>

                    <div class="sdh-mezo sdh-ny__peldany">
                        <label for="ny_peldany">Példányszám</label>
                        <span class="sdh-ny__leptet">
                            <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-ny-lep="-1" aria-label="Kevesebb példány">−</button>
                            <input type="text" inputmode="numeric" id="ny_peldany" data-ny-peldany value="<?php echo (int) $adat[$aktiv]['peldany']; ?>" maxlength="1" aria-label="Példányszám">
                            <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-ny-lep="1" aria-label="Több példány">+</button>
                        </span>
                    </div>
                </div>

                <div class="sdh-ny__gombok">
                    <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-ny-nyomtat><?php echo self::ikon(); // phpcs:ignore WordPress.Security.EscapeOutput -- saját SVG. ?><span>Nyomtatás</span></button>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-ny-ment>Mentés PDF-be</button>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-ny-email-nyit aria-expanded="false">Küldés e-mailben</button>
                </div>

                <?php // Nem <form>: a popup az első űrlapot a saját mentéséhez kötné (app.js bekotUrlap). ?>
                <div class="sdh-ny__email" data-ny-email hidden>
                    <?php if (count($fiokok) > 0) : ?>
                        <div class="sdh-mezo">
                            <label for="ny_fiok">Feladó</label>
                            <select id="ny_fiok" name="fiok">
                                <?php foreach ($fiokok as $k => $nev) : ?>
                                    <option value="<?php echo esc_attr((string) $k); ?>"<?php selected((string) $a['email_fiok'], (string) $k); ?>><?php echo esc_html($nev); ?></option>
                                <?php endforeach; ?>
                                <option value=""<?php selected((string) $a['email_fiok'], ''); ?>>A rendszer levélküldője (WordPress)</option>
                            </select>
                        </div>
                    <?php else : ?>
                        <p class="sdh-sugo">A levél a WordPress levélküldőjével megy. Saját fiókból (pl. Gmail) akkor küldhető, ha a Beállítások › Levelezés alatt be van kötve.</p>
                    <?php endif; ?>
                    <div class="sdh-mezo">
                        <label for="ny_cimzett">Címzett <span class="sdh-kotelezo">*</span></label>
                        <input type="text" id="ny_cimzett" name="cimzett" inputmode="email" autocomplete="off" maxlength="300"
                               value="<?php echo esc_attr((string) $adat[$aktiv]['cimzett']); ?>" placeholder="nev@pelda.hu (több cím vesszővel)">
                    </div>
                    <div class="sdh-mezo">
                        <label for="ny_targy">Tárgy</label>
                        <input type="text" id="ny_targy" name="targy" maxlength="200" value="<?php echo esc_attr($adat[$aktiv]['targy']); ?>">
                    </div>
                    <div class="sdh-mezo">
                        <label for="ny_szoveg">Szöveg</label>
                        <textarea id="ny_szoveg" name="szoveg" rows="6" maxlength="5000"><?php echo esc_textarea($adat[$aktiv]['szoveg']); ?></textarea>
                        <span class="sdh-mezo__sugo">A nyomtatvány PDF-ben, csatolmányként megy (a beállított példányszámtól függetlenül egy példányban).</span>
                    </div>
                    <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-ny-kuld>Küldés</button>
                </div>

                <p class="sdh-ny__uzenet" data-ny-uzenet aria-live="polite"></p>

                <?php if ($lap !== null) : ?>
                    <p class="sdh-sugo sdh-ny__megj">A nyomtatvány a lap <strong>elmentett</strong> adataiból készül.</p>
                <?php endif; ?>
            </div>

            <div class="sdh-ny__elonezet" data-ny-elonezet>
                <div class="sdh-ny__toltes" data-ny-toltes>Az előnézet készül…</div>
                <iframe data-ny-keret title="A nyomtatvány előnézete" hidden></iframe>
                <?php // Telefonon és néhány böngészőben a PDF nem jelenik meg keretben: ott új lapon nyitható meg. ?>
                <a class="sdh-ny__ujlap" href="#" target="_blank" rel="noopener" data-ny-ujlap>Megnyitás új lapon</a>
            </div>
        </div>

        <div class="sdh-urlap__lablec">
            <?php if (SDH_Muhely_Admin_UI::admin_e() && empty($p['minta'])) : ?>
                <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(SDH_Muhely_Beallitasok::url('nyomtatvanyok')); ?>">A nyomtatványok beállítása</a>
            <?php endif; ?>
            <?php if ($lap === null && empty($p['minta'])) : ?>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-ny-kepernyo
                        title="A mögötte lévő oldalt nyomtatja úgy, ahogy most látszik (menü és fejléc nélkül) – ha valamiről nincs külön nyomtatvány.">Az oldal nyomtatása, ahogy látszik</button>
            <?php endif; ?>
            <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Bezárás</button>
        </div>
        <?php
        wp_die();
    }

    /** A PDF a böngészőnek: megnézésre (előnézet, nyomtatás) vagy letöltésre. */
    public static function ajax_pdf(): void
    {
        self::jog(false);

        $tipus   = sanitize_key(self::p('tipus', 30));
        $p       = self::parameterek();
        $peldany = min(9, max(1, (int) self::p('peldany', 2)));

        // A sablonszerkesztő élő előnézete: a még el nem mentett űrlapértékekkel (csak adminnak).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a jog() ellenőrizte.
        if (!empty($_POST['sablon_proba']) && SDH_Muhely_Admin_UI::admin_e()) {
            $p['minta']   = 1;
            $p['_sablon'] = self::bekuldott_sablon($tipus);
        }

        // A lap Naplójába (0.59): nyomtatásra küldve vagy letöltve – az előnézet nem számít.
        $naplo_mod = self::p('mod', 10);
        $naplo_kell = empty($p['minta']) && (int) ($p['id'] ?? 0) > 0 && (string) (self::tipusok()[$tipus]['adat'] ?? '') === 'lap'
            && (self::p('nyomtat', 2) === '1' || $naplo_mod === 'letolt') && class_exists('SDH_Muhely_Valtozasnaplo');

        if ($naplo_kell) {
            SDH_Muhely_Valtozasnaplo::esemeny('munkalap', (int) $p['id'], sprintf(
                '%s: %s',
                (string) (self::tipusok()[$tipus]['nev'] ?? $tipus),
                $naplo_mod === 'letolt' ? 'PDF letöltve' : 'nyomtatásra küldve (' . $peldany . ' példány)'
            ), [], 'nyomtatas');
        }

        // Csak a Napló-jelzés kellett (az előnézet már a böngészőben van).
        if ($naplo_mod === 'naplo') {
            nocache_headers();
            status_header(204);
            exit;
        }

        $r = SDH_Muhely_Nyomtatvany::keszit($tipus, $p, $peldany);

        if (is_string($r)) {
            status_header(400);
            nocache_headers();
            header('Content-Type: text/plain; charset=utf-8');
            echo $r; // phpcs:ignore WordPress.Security.EscapeOutput -- sima szöveg.
            exit;
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (self::p('mod', 10) === 'letolt' ? 'attachment' : 'inline') . '; filename="' . $r['nev'] . '"');
        header('Content-Length: ' . strlen($r['pdf']));
        header('X-SDH-Oldalak: ' . (int) $r['oldal']);
        echo $r['pdf']; // phpcs:ignore WordPress.Security.EscapeOutput -- PDF.
        exit;
    }

    /** Belső papír-e: a sablonja szerint rajta van a belső megjegyzés vagy a zárkód. */
    public static function belso_e(string $tipus): bool
    {
        if ((string) (self::tipusok()[$tipus]['adat'] ?? '') !== 'lap') {
            return false;
        }

        $s = self::sablon($tipus);

        return !empty($s['belso']) || !empty($s['zarkod']);
    }

    /** E-mail-címek listája: vesszővel / pontosvesszővel elválasztva. @return array<int, string>|null */
    private static function cimek(string $szoveg): ?array
    {
        $ki = [];

        foreach (preg_split('/[,;\s]+/', $szoveg) ?: [] as $c) {
            $c = trim($c);

            if ($c === '') {
                continue;
            }

            if (!is_email($c)) {
                return null;
            }

            $ki[] = sanitize_email($c);
        }

        return array_values(array_unique($ki));
    }

    public static function ajax_email(): void
    {
        self::jog();

        $tipus  = sanitize_key(self::p('tipus', 30));
        $p      = self::parameterek();
        $cimek  = self::cimek(self::p('cimzett', 300));
        $targy  = self::p('targy', 200);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a jog() ellenőrizte.
        $szoveg = isset($_POST['szoveg']) && is_scalar($_POST['szoveg']) ? mb_substr(sanitize_textarea_field(wp_unslash((string) $_POST['szoveg'])), 0, 5000) : '';
        $fiok   = sanitize_key(self::p('fiok', 40));

        if ($cimek === null) {
            wp_send_json_error(['uzenet' => 'Valamelyik e-mail-cím hibás. Több címet vesszővel válassz el.']);
        }

        if ($cimek === []) {
            wp_send_json_error(['uzenet' => 'Add meg a címzett e-mail-címét.']);
        }

        if (!empty($p['minta'])) {
            wp_send_json_error(['uzenet' => 'A próbanyomat nem küldhető el – ez kitalált adatokkal készül.']);
        }

        // Belső papír (belső megjegyzés, zárkód van rajta) nem mehet az ügyfél saját címére.
        if (self::belso_e($tipus) && (int) $p['id'] > 0 && class_exists('SDH_Muhely_Rma')) {
            $lap    = SDH_Muhely_Rma::munkalap((int) $p['id']);
            $ugyfel = $lap !== null ? SDH_Muhely_Rma::ugyfel((int) $lap->ugyfel_id) : null;
            $cime   = $ugyfel !== null ? strtolower(trim((string) $ugyfel->email)) : '';

            if ($cime !== '' && in_array($cime, array_map('strtolower', $cimek), true)) {
                wp_send_json_error(['uzenet' => 'Ezen a nyomtatványon belső megjegyzés vagy zárkód van, ezért az ügyfél címére nem küldhető. Az ügyfélnek a Munkalapot vagy az Átvételi elismervényt küldd.']);
            }
        }

        $r = SDH_Muhely_Nyomtatvany::keszit($tipus, $p, 1);

        if (is_string($r)) {
            wp_send_json_error(['uzenet' => $r]);
        }

        $targy  = $targy !== '' ? $targy : trim($r['cim'] . ' ' . $r['szam']);
        $szoveg = trim($szoveg) !== '' ? $szoveg : $r['cim'];
        $a      = self::altalanos();
        $masolat = is_email((string) $a['email_masolat']) ? (string) $a['email_masolat'] : '';
        $eredmeny = self::level_kuld($fiok, $cimek, $targy, $szoveg, $r['nev'], $r['pdf'], $masolat);

        if ($eredmeny !== true) {
            wp_send_json_error(['uzenet' => 'A levél nem ment el: ' . $eredmeny]);
        }

        if ((int) ($p['id'] ?? 0) > 0 && (string) (self::tipusok()[$tipus]['adat'] ?? '') === 'lap' && class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('munkalap', (int) $p['id'], (string) (self::tipusok()[$tipus]['nev'] ?? $tipus) . ': e-mailben elküldve', ['Címzett' => implode(', ', $cimek), 'Tárgy' => $targy], 'nyomtatas');
        }

        // Ha az ügyfél kapta, a munkalap üzenetei közé is bekerül, hogy később látszódjon, mi ment ki neki.
        // (Másnak – pl. kollégának – küldött papír nem kerül oda: azt az ügyfél is látná az ügyféloldalon.)
        if ((int) $p['id'] > 0 && (string) (self::tipusok()[$tipus]['adat'] ?? '') === 'lap' && !self::belso_e($tipus) && class_exists('SDH_Muhely_Rma')) {
            $m = SDH_Muhely_Rma::munkalap((int) $p['id']);
            $u = $m !== null ? SDH_Muhely_Rma::ugyfel((int) $m->ugyfel_id) : null;

            if ($m !== null && $u !== null && in_array(strtolower(trim((string) $u->email)), array_map('strtolower', $cimek), true)) {
                SDH_Muhely_Rma::uzenet_ment((int) $m->id, (int) $m->ugyfel_id, 'ki', 'email', 'E-mailben elküldtük: ' . $r['cim'] . ' (' . $r['szam'] . '), PDF csatolmányként.', get_current_user_id());
            }
        }

        wp_send_json_success(['uzenet' => 'Elküldve: ' . implode(', ', $cimek)]);
    }

    /**
     * Levél PDF-csatolmánnyal: a kiválasztott levelezőfiókból (SMTP), vagy a WordPress levélküldőjével.
     *
     * @param array<int, string> $cimek
     * @return true|string
     */
    public static function level_kuld(string $fiok, array $cimek, string $targy, string $szoveg, string $fajlnev, string $pdf, string $masolat = '')
    {
        if ($fiok !== '' && class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Modulok::be('levelezes')) {
            return SDH_Muhely_Levelezes::egyszeru_kuldes($fiok, $cimek, $targy, $szoveg, [['nev' => $fajlnev, 'tartalom' => $pdf, 'tipus' => 'application/pdf']], $masolat);
        }

        // A WordPress levélküldőjével: a PDF a memóriából megy csatolmányként (nem kerül ideiglenes fájlba,
        // így nem marad a szerveren, ha a küldés megszakad).
        $fejlec = [];
        $r      = class_exists('SDH_Muhely_Rma') ? SDH_Muhely_Rma::beallitas() : [];
        $valasz = sanitize_email((string) ($r['ertesites_email'] ?? ''));

        if ($valasz !== '' && is_email($valasz)) {
            $fejlec[] = 'Reply-To: ' . $valasz;
        }

        if ($masolat !== '' && is_email($masolat)) {
            $fejlec[] = 'Bcc: ' . sanitize_email($masolat);
        }

        $hiba    = '';
        $figyelo = static function ($h) use (&$hiba): void {
            $hiba = is_wp_error($h) ? $h->get_error_message() : '';
        };
        $csatol  = static function ($levelkuldo) use ($pdf, $fajlnev): void {
            $levelkuldo->addStringAttachment($pdf, $fajlnev, 'base64', 'application/pdf');
        };

        add_action('wp_mail_failed', $figyelo);
        add_action('phpmailer_init', $csatol);

        try {
            $ok = wp_mail($cimek, $targy, $szoveg, $fejlec);
        } catch (Throwable $h) {
            $ok   = false;
            $hiba = $h->getMessage();
        }

        remove_action('phpmailer_init', $csatol);
        remove_action('wp_mail_failed', $figyelo);

        return $ok ? true : ($hiba !== '' ? $hiba : 'a szerver levélküldője nem fogadta el (nincs beállítva a levélküldés?).');
    }

    /* =================================================================
     * Beállítások › Nyomtatványok
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $a       = self::altalanos();
        $nyers   = self::nyers()['altalanos'] ?? [];
        $tipusok = self::tipusok();
        $fiokok  = class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Modulok::be('levelezes') ? SDH_Muhely_Levelezes::kuldo_fiokok() : [];
        $sajat_szin = is_array($nyers) && preg_match('/^#[0-9a-f]{6}$/i', (string) ($nyers['szin'] ?? '')) === 1;

        require_once SDH_MUHELY_DIR . 'includes/lib/sdh-nyomtatvany-pdf.php';

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Nyomtatványok</h2>
            <p class="sdh-sugo">
                Minden nyomtatvány külön szerkeszthető: a címe, a példányszáma, hogy mi látszik rajta, a szövegei, az aláírása és a kísérő e-mail.
                A szerkesztő ablakban élő előnézet van; a <em>Próbanyomat</em> kitalált adatokkal mutatja a lapot.
            </p>

            <?php foreach (self::csoportok() as $ck => $cnev) : ?>
                <h3 class="sdh-ny-beall__csoport"><?php echo esc_html($cnev); ?></h3>
                <div class="sdh-ny-beall__lista">
                    <?php foreach ($tipusok as $k => $t) : ?>
                        <?php
                        if ((string) $t['csoport'] !== $ck) {
                            continue;
                        }

                        $s = self::sablon($k);
                        ?>
                        <article class="sdh-ny-beall__elem">
                            <div>
                                <strong><?php echo esc_html((string) $s['cim']); ?></strong>
                                <?php if ((string) $s['cim'] !== (string) $t['nev']) : ?>
                                    <small>(<?php echo esc_html((string) $t['nev']); ?>)</small>
                                <?php endif; ?>
                                <span><?php echo esc_html((string) $t['leiras']); ?></span>
                                <em><?php echo (int) $s['peldany']; ?> példány · <?php echo esc_html((string) $s['papir'] !== '' ? (string) $s['papir'] : $a['papir']); ?><?php echo !empty($t['fekvo']) && !empty($s['fekvo']) ? ' fekvő' : ''; ?></em>
                            </div>
                            <span class="sdh-ny-beall__gombok">
                                <a class="sdh-gomb sdh-gomb--vilagos" href="#" data-sdh-urlap="nyomtsablon" data-sdh-id="0" data-sdh-param-tipus="<?php echo esc_attr($k); ?>">Szerkesztés</a>
                                <?php
                                // A listáknak és a bianko lapnak nincs kitalált adata: azok az ablakban, valódi adatból nézhetők meg.
                                echo self::gomb(0, ['tipus' => $k] + (in_array((string) $t['adat'], ['lap', 'ptetel'], true) ? ['minta' => 1] : []), 'Próbanyomat'); // phpcs:ignore WordPress.Security.EscapeOutput -- a gomb() escapel.
                                ?>
                            </span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Közös megjelenés</h2>
            <p class="sdh-sugo">Ezek minden nyomtatványra érvényesek. A papírméret nyomtatványonként felülírható.</p>

            <input type="hidden" name="ny_jelen" value="1">

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="ny_betu">Betűtípus</label>
                    <select name="ny_betu" id="ny_betu">
                        <?php foreach (SDH_Muhely_Nyomtatvany_Pdf::BETUK as $k => $f) : ?>
                            <option value="<?php echo esc_attr($k); ?>"<?php selected($a['betu'], $k); ?>><?php echo esc_html($f[0]); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="ny_meret">Betűméret (pont)</label>
                    <input type="number" name="ny_meret" id="ny_meret" min="7" max="13" step="0.5" value="<?php echo esc_attr((string) $a['meret']); ?>">
                    <span class="sdh-mezo__sugo">Az alapszöveg mérete; a cím, a címkék és az apró betű ehhez igazodik.</span>
                </div>
                <div class="sdh-mezo">
                    <label for="ny_papir">Papírméret</label>
                    <select name="ny_papir" id="ny_papir">
                        <option value="A4"<?php selected($a['papir'], 'A4'); ?>>A4</option>
                        <option value="A5"<?php selected($a['papir'], 'A5'); ?>>A5 (fél A4)</option>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="ny_margo">Margó (mm)</label>
                    <input type="number" name="ny_margo" id="ny_margo" min="6" max="25" step="1" value="<?php echo esc_attr((string) $a['margo']); ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="ny_szin">Kiemelő szín</label>
                    <input type="text" name="ny_szin" id="ny_szin" maxlength="7" value="<?php echo esc_attr($sajat_szin ? (string) $a['szin'] : ''); ?>" placeholder="<?php echo esc_attr((string) $a['szin']); ?> (a CRM színe)">
                    <span class="sdh-mezo__sugo">Színkód (#rrggbb): a fejléc vonala, a szakaszcímek aláhúzása, a „Fizetendő” sáv. Üresen a CRM kiemelő színe.</span>
                </div>
                <div class="sdh-mezo">
                    <label for="ny_kelt_hely">Kelt (település)</label>
                    <input type="text" name="ny_kelt_hely" id="ny_kelt_hely" maxlength="60" value="<?php echo esc_attr((string) $a['kelt_hely']); ?>" placeholder="pl. a telephely települése">
                    <span class="sdh-mezo__sugo">Az aláírás fölötti „Kelt: …, dátum” sorba.</span>
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Fejléc és lábléc</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="ny_logo">Logó</label>
                    <div class="sdh-rma-media" data-sdh-media="image">
                        <input type="text" name="ny_logo" id="ny_logo" value="<?php echo esc_attr((string) $a['logo']); ?>" placeholder="https://…">
                        <button type="button" class="sdh-gomb" data-sdh-media-valaszt>Feltöltés…</button>
                        <button type="button" class="sdh-gomb" data-sdh-media-torol<?php echo (string) $a['logo'] === '' ? ' hidden' : ''; ?>>Törlés</button>
                        <span class="sdh-rma-media__elo" data-sdh-media-elo>
                            <?php if ((string) $a['logo'] !== '') : ?>
                                <img src="<?php echo esc_url((string) $a['logo']); ?>" alt="">
                            <?php endif; ?>
                        </span>
                    </div>
                    <span class="sdh-mezo__sugo">PNG vagy JPG. Üresen a Számlázás lapfülön megadott logó kerül a nyomtatványokra.</span>
                </div>
                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" name="ny_fej_logo" id="ny_fej_logo" value="1" <?php checked(!empty($a['fej_logo'])); ?>>
                    <label for="ny_fej_logo">Logó a fejlécben</label>
                </div>
                <div class="sdh-mezo">
                    <label for="ny_logo_szel">A logó szélessége (mm)</label>
                    <input type="number" name="ny_logo_szel" id="ny_logo_szel" min="10" max="80" step="1" value="<?php echo esc_attr((string) $a['logo_szel']); ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="ny_logo_hely">A logó helye</label>
                    <select name="ny_logo_hely" id="ny_logo_hely">
                        <option value="bal"<?php selected($a['logo_hely'], 'bal'); ?>>Balra (a cím jobbra)</option>
                        <option value="jobb"<?php selected($a['logo_hely'], 'jobb'); ?>>Jobbra (a cím alul, balra)</option>
                        <option value="kozep"<?php selected($a['logo_hely'], 'kozep'); ?>>Középre, legfelül</option>
                    </select>
                </div>
                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" name="ny_fej_ceg" id="ny_fej_ceg" value="1" <?php checked(!empty($a['fej_ceg'])); ?>>
                    <label for="ny_fej_ceg">Cégadatok a fejlécben</label>
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="ny_ceg">A fejléc szövege (cégadatok)</label>
                    <textarea name="ny_ceg" id="ny_ceg" rows="5" placeholder="<?php echo esc_attr(implode("\n", self::ceg_sorok(['ceg' => ''] + $a))); ?>"><?php echo esc_textarea((string) $a['ceg']); ?></textarea>
                    <span class="sdh-mezo__sugo">Soronként egy adat, az első sor (a cégnév) félkövér. Üresen a Számlázás lapfül kiállítói adataiból áll össze – a mezőben halványan az látszik.</span>
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="ny_lab">A lábléc szövege</label>
                    <textarea name="ny_lab" id="ny_lab" rows="3" placeholder="<?php echo esc_attr(implode("\n", self::lab_sorok(['lab' => ''] + $a))); ?>"><?php echo esc_textarea((string) $a['lab']); ?></textarea>
                    <span class="sdh-mezo__sugo">Legfeljebb 4 sor minden oldal alján (elérhetőség, nyitvatartás). Üresen az Ügyféloldal lapfül elérhetőségei.</span>
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Felajánlás és e-mail</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="checkbox" name="ny_ajanl_uj" id="ny_ajanl_uj" value="1" <?php checked(!empty($a['ajanl_uj'])); ?>>
                    <label for="ny_ajanl_uj">Új munkalap mentése után ajánlja fel az átvételi elismervény nyomtatását</label>
                </div>
                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="checkbox" name="ny_ajanl_zar" id="ny_ajanl_zar" value="1" <?php checked(!empty($a['ajanl_zar'])); ?>>
                    <label for="ny_ajanl_zar">Munkalap lezárásakor ajánlja fel a munkalap nyomtatását</label>
                </div>
                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="checkbox" name="ny_ajanl_eladas" id="ny_ajanl_eladas" value="1" <?php checked(!empty($a['ajanl_eladas'])); ?>>
                    <label for="ny_ajanl_eladas">Árukiadásnál és rendelésnél is ajánljon (új rendelés: megrendelés; lezárás: átadási bizonylat)</label>
                </div>
                <div class="sdh-mezo">
                    <label for="ny_email_fiok">Alap feladó az e-mailhez</label>
                    <select name="ny_email_fiok" id="ny_email_fiok">
                        <option value=""<?php selected((string) $a['email_fiok'], ''); ?>>A rendszer levélküldője (WordPress)</option>
                        <?php foreach ($fiokok as $k => $nev) : ?>
                            <option value="<?php echo esc_attr((string) $k); ?>"<?php selected((string) $a['email_fiok'], (string) $k); ?>><?php echo esc_html($nev); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sdh-mezo__sugo">A Levelezés lapfülön bekötött fiókok választhatók; küldéskor átállítható.</span>
                </div>
                <div class="sdh-mezo">
                    <label for="ny_email_masolat">Rejtett másolat minden kiküldött nyomtatványról</label>
                    <input type="text" name="ny_email_masolat" id="ny_email_masolat" maxlength="190" value="<?php echo esc_attr((string) $a['email_masolat']); ?>" placeholder="pl. a cég saját címe">
                </div>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['ny_jelen'])) {
            return;
        }

        $sz = static fn (string $k, int $max): string => isset($_POST[$k]) && is_scalar($_POST[$k]) ? mb_substr(sanitize_text_field(wp_unslash((string) $_POST[$k])), 0, $max) : '';
        $h  = static fn (string $k, int $max): string => isset($_POST[$k]) && is_scalar($_POST[$k]) ? mb_substr(sanitize_textarea_field(wp_unslash((string) $_POST[$k])), 0, $max) : '';

        $szin = strtolower($sz('ny_szin', 7));
        $b    = self::nyers();

        $b['altalanos'] = [
            'betu'      => sanitize_key($sz('ny_betu', 30)),
            'meret'     => (float) str_replace(',', '.', $sz('ny_meret', 5)),
            'papir'     => $sz('ny_papir', 2) === 'A5' ? 'A5' : 'A4',
            'margo'     => (float) $sz('ny_margo', 4),
            'szin'      => preg_match('/^#[0-9a-f]{6}$/', $szin) === 1 ? $szin : '',
            'logo'      => isset($_POST['ny_logo']) ? esc_url_raw(trim((string) wp_unslash($_POST['ny_logo']))) : '',
            'fej_logo'  => !empty($_POST['ny_fej_logo']) ? 1 : 0,
            'logo_szel' => (float) $sz('ny_logo_szel', 4),
            'logo_hely' => sanitize_key($sz('ny_logo_hely', 10)),
            'fej_ceg'   => !empty($_POST['ny_fej_ceg']) ? 1 : 0,
            'ceg'       => $h('ny_ceg', 1200),
            'lab'       => $h('ny_lab', 800),
            'kelt_hely' => $sz('ny_kelt_hely', 60),
            'ajanl_uj'     => !empty($_POST['ny_ajanl_uj']) ? 1 : 0,
            'ajanl_zar'    => !empty($_POST['ny_ajanl_zar']) ? 1 : 0,
            'ajanl_eladas' => !empty($_POST['ny_ajanl_eladas']) ? 1 : 0,
            'email_fiok'    => sanitize_key($sz('ny_email_fiok', 40)),
            'email_masolat' => is_email($sz('ny_email_masolat', 190)) ? sanitize_email($sz('ny_email_masolat', 190)) : '',
        ];
        // phpcs:enable

        update_option(self::OPTION, $b, false);
    }

    /* =================================================================
     * Egy nyomtatvány szerkesztése (popup, élő előnézettel)
     * ============================================================== */

    /** A szerkesztő űrlapjából beküldött sablonértékek, megtisztítva. @return array<string, mixed> */
    private static function bekuldott_sablon(string $tipus): array
    {
        $t = self::tipusok()[$tipus] ?? null;

        if ($t === null) {
            return [];
        }

        $ki = [];

        foreach (self::mezo_csoportok((string) $t['adat'], $tipus) as $mezok) {
            foreach ($mezok as $k) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a hívó ellenőrizte.
                $nyers = $_POST['s_' . $k] ?? '';
                $ki[$k] = self::tisztit($k, is_scalar($nyers) ? wp_unslash((string) $nyers) : '');
            }
        }

        if (trim((string) ($ki['cim'] ?? '')) === '') {
            $ki['cim'] = (string) $t['nev'];
        }

        return $ki;
    }

    public static function ajax_sablon_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        $tipus = sanitize_key(self::p('tipus', 30));
        $t     = self::tipusok()[$tipus] ?? null;

        if (!SDH_Muhely_Admin_UI::admin_e() || $t === null) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">' . ($t === null ? 'Ismeretlen nyomtatvány.' : 'A nyomtatványokat adminisztrátor szerkesztheti.') . '</div>';
            wp_die();
        }

        $s      = self::sablon($tipus);
        $mezok  = self::mezok();
        $minta  = in_array((string) $t['adat'], ['lap', 'ptetel', 'bianko'], true);

        ?>
        <form class="sdh-urlap sdh-ny-szerk" data-sdh-ajax-action="sdh_muhely_nyomtsablon_ment" data-sdh-ny-szerk
              data-tipus="<?php echo esc_attr($tipus); ?>" data-elonezet="<?php echo $minta ? '1' : '0'; ?>" novalidate>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <?php SDH_Muhely_Csapat::kontextus_mezo(); ?>
            <input type="hidden" name="tipus" value="<?php echo esc_attr($tipus); ?>">
            <h2 class="sdh-modal__cim">Nyomtatvány szerkesztése – <?php echo esc_html((string) $t['nev']); ?></h2>

            <div class="sdh-ny-szerk__torzs">
                <div class="sdh-ny-szerk__mezok">
                    <p class="sdh-sugo"><?php echo esc_html((string) $t['leiras']); ?>
                        A szövegekben használható jelölők: <code>{cim}</code> <code>{szam}</code> <code>{ugyfel}</code> <code>{eszkoz}</code> <code>{datum}</code>
                        <code>{hatarido}</code> <code>{vegosszeg}</code> <code>{fizetendo}</code> <code>{eloleg}</code> <code>{ervenyes}</code> <code>{ceg}</code>.</p>

                    <?php
                    $csoportok = self::mezo_csoportok((string) $t['adat'], $tipus);
                    $n         = 0;
                    ?>
                    <div class="sdh-fulek sdh-ny-szerk__fulek">
                        <?php foreach (array_keys($csoportok) as $csoport) : ?>
                            <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--<?php echo (int) ++$n; ?>" name="_ful_nysz"
                                   id="nysz_ful_<?php echo (int) $n; ?>"<?php checked($n, 1); ?>>
                        <?php endforeach; ?>

                        <div class="sdh-fulek__sav">
                            <?php $n = 0; ?>
                            <?php foreach (array_keys($csoportok) as $csoport) : ?>
                                <label for="nysz_ful_<?php echo (int) ++$n; ?>"><?php echo esc_html($csoport); ?></label>
                            <?php endforeach; ?>
                        </div>

                        <?php $n = 0; ?>
                        <?php foreach ($csoportok as $csoport => $kulcsok) : ?>
                            <div class="sdh-fulek__panel sdh-fulek__panel--<?php echo (int) ++$n; ?> sdh-ny-szerk__csoport">
                            <?php foreach ($kulcsok as $k) : ?>
                                <?php
                                $m     = $mezok[$k];
                                $nev   = 's_' . $k;
                                $sugo  = (string) ($m[2] ?? '');
                                ?>
                                <?php if ($m[0] === 'pipa') : ?>
                                    <label class="sdh-cs-kapcs" title="<?php echo esc_attr($sugo); ?>">
                                        <input type="checkbox" name="<?php echo esc_attr($nev); ?>" value="1"<?php checked(!empty($s[$k])); ?>>
                                        <span class="sdh-cs-kapcs__sin"></span>
                                        <span><?php echo esc_html($m[1]); ?><?php echo $sugo !== '' ? ' <small>– ' . esc_html($sugo) . '</small>' : ''; ?></span>
                                    </label>
                                <?php else : ?>
                                    <div class="sdh-mezo<?php echo $m[0] === 'hosszu' ? ' sdh-mezo--szeles' : ''; ?>">
                                        <label for="<?php echo esc_attr($nev); ?>"><?php echo esc_html($m[1]); ?></label>
                                        <?php if ($m[0] === 'hosszu') : ?>
                                            <textarea name="<?php echo esc_attr($nev); ?>" id="<?php echo esc_attr($nev); ?>" rows="<?php echo $k === 'jogi' ? 9 : 3; ?>" maxlength="20000"><?php echo esc_textarea((string) $s[$k]); ?></textarea>
                                        <?php elseif ($m[0] === 'papir') : ?>
                                            <select name="<?php echo esc_attr($nev); ?>" id="<?php echo esc_attr($nev); ?>">
                                                <option value=""<?php selected((string) $s[$k], ''); ?>>Az általános beállítás szerint</option>
                                                <option value="A4"<?php selected((string) $s[$k], 'A4'); ?>>A4</option>
                                                <option value="A5"<?php selected((string) $s[$k], 'A5'); ?>>A5 (fél A4)</option>
                                            </select>
                                        <?php elseif ($m[0] === 'szam') : ?>
                                            <input type="number" name="<?php echo esc_attr($nev); ?>" id="<?php echo esc_attr($nev); ?>" min="<?php echo $k === 'peldany' ? 1 : 0; ?>" max="365" step="1" value="<?php echo (int) $s[$k]; ?>">
                                        <?php else : ?>
                                            <input type="text" name="<?php echo esc_attr($nev); ?>" id="<?php echo esc_attr($nev); ?>" maxlength="200" value="<?php echo esc_attr((string) $s[$k]); ?>">
                                        <?php endif; ?>
                                        <?php if ($sugo !== '') : ?>
                                            <span class="sdh-mezo__sugo"><?php echo esc_html($sugo); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($minta) : ?>
                    <div class="sdh-ny__elonezet sdh-ny-szerk__elonezet">
                        <div class="sdh-ny__toltes" data-ny-toltes>Az előnézet készül…</div>
                        <iframe data-ny-keret title="A nyomtatvány előnézete" hidden></iframe>
                    </div>
                <?php endif; ?>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Mentés</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-ny-szerk__alap" data-ny-alap data-lepes="0">Gyári beállítás visszaállítása</button>
            </div>
        </form>
        <?php
        wp_die();
    }

    public static function ajax_sablon_ment(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'A nyomtatványokat adminisztrátor szerkesztheti.'], 403);
        }

        SDH_Muhely_Csapat::kontextus_be();

        $tipus = sanitize_key(self::p('tipus', 30));

        if (!isset(self::tipusok()[$tipus])) {
            wp_send_json_error(['uzenet' => 'Ismeretlen nyomtatvány.']);
        }

        $b = self::nyers();
        $b['sablonok']         = is_array($b['sablonok'] ?? null) ? $b['sablonok'] : [];
        // Ami az űrlapon nem szerepelt (más adatfajta mezője), az megmarad a korábbi értékén.
        $b['sablonok'][$tipus] = array_merge(is_array($b['sablonok'][$tipus] ?? null) ? $b['sablonok'][$tipus] : [], self::bekuldott_sablon($tipus));

        update_option(self::OPTION, $b, false);

        wp_send_json_success(['vissza' => SDH_Muhely_Beallitasok::url('nyomtatvanyok', ['uzenet' => 'mentve'])]);
    }

    /** Egy nyomtatvány visszaállítása a gyári beállításra. */
    public static function ajax_sablon_alap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'A nyomtatványokat adminisztrátor szerkesztheti.'], 403);
        }

        SDH_Muhely_Csapat::kontextus_be();

        $tipus = sanitize_key(self::p('tipus', 30));
        $b     = self::nyers();

        if (isset($b['sablonok'][$tipus])) {
            unset($b['sablonok'][$tipus]);
            update_option(self::OPTION, $b, false);
        }

        wp_send_json_success(['vissza' => SDH_Muhely_Beallitasok::url('nyomtatvanyok', ['uzenet' => 'mentve'])]);
    }
}
