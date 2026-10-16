<?php
/**
 * Modul-nyilvántartás és útvonalkezelés.
 *
 * A rendszer két helyen jelenik meg: a saját frontend-felületen
 * (sdh-muhely.local/muhely/…) és a wp-adminban. A modulok egyszer írják
 * meg magukat, és itt jelentkeznek be – a két felület innen tudja meg,
 * mi létezik egyáltalán.
 *
 * Az URL-építés is itt lakik, mert ugyanaz a modulkód fut mindkét
 * helyen: a lista nem tudhatja, melyik felületen van, csak annyit
 * mond, hogy "az ügyfelek oldalára, szerkesztés nézetben, 12-es azonosítóval".
 * A kontextus eldönti, hogy ebből admin.php?page=… vagy /muhely/… lesz.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Modulok
{
    /**
     * A bejegyzett modulok.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $modulok = [];

    /** 'frontend' vagy 'admin' – melyik felület fut éppen. */
    private static string $kontextus = 'admin';

    /**
     * Modul bejegyzése.
     *
     * @param array{
     *     kulcs: string,
     *     cim: string,
     *     render: callable,
     *     sorrend?: int,
     *     keszul?: bool
     * } $modul
     */
    public static function regisztral(array $modul): void
    {
        if (empty($modul['kulcs']) || empty($modul['cim'])) {
            return;
        }

        $modul['sorrend'] = $modul['sorrend'] ?? 50;
        $modul['keszul']  = $modul['keszul'] ?? false;

        self::$modulok[$modul['kulcs']] = $modul;
    }

    /**
     * A bejegyzett modul úgy, ahogy most látszik: a kikapcsolt fő modul
     * (Modulok oldal) nincs – nincs menüpontja, csempéje, oldala. A `ki_rejtve`
     * modul kikapcsolva is megvan, csak menüpont nélkül (pl. a Csapat oldalán a
     * kollégák kezelése a csevegés nélkül is kell).
     *
     * @param array<string, mixed> $modul
     * @return array<string, mixed>|null
     */
    private static function lathato(array $modul): ?array
    {
        // A csak fejlesztőnek szóló modul (Modulok: a telepítői zár, a kapcsolók) az adminisztrátornak sem látszik.
        if (!empty($modul['csak_fejleszto']) && class_exists('SDH_Muhely_Admin_UI') && !SDH_Muhely_Admin_UI::fejleszto_e()) {
            return null;
        }

        // A szerepkör (H5): amit a tényleges személy szerepe nem enged, annak nincs menüpontja, csempéje, oldala.
        if (class_exists('SDH_Muhely_Szerepkor') && !SDH_Muhely_Szerepkor::modul_engedett((string) $modul['kulcs'])) {
            return null;
        }

        if (self::be((string) $modul['kulcs'])) {
            return $modul;
        }

        if (empty($modul['ki_rejtve'])) {
            return null;
        }

        $modul['rejtett'] = true;

        return $modul;
    }

    /**
     * Minden bejegyzett modul, sorrendben.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function osszes(): array
    {
        $modulok = array_filter(array_map([self::class, 'lathato'], self::$modulok));

        uasort(
            $modulok,
            static fn (array $a, array $b): int => $a['sorrend'] <=> $b['sorrend']
        );

        return $modulok;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function egy(string $kulcs): ?array
    {
        return isset(self::$modulok[$kulcs]) ? self::lathato(self::$modulok[$kulcs]) : null;
    }

    /* =================================================================
     * Ki- és bekapcsolható fő modulok (Modulok oldal, 0.58)
     *
     * Az alaprendszer (Áttekintés, Munkalapok, Ügyfelek, Eszközök,
     * Szolgáltatások, Beállítások, Súgó, Mentés) mindig fut. A többi fő
     * funkció modulonként kikapcsolható – így külön is értékesíthető.
     *
     * Kikapcsolt modulnál: nincs menüpont, csempe, oldal és beállítás-lapfül;
     * az AJAX-végpontjai elutasítanak (SDH_Muhely_Modul_Kapcsolo::ajax_or);
     * az időzített munkái és a más modulokban lévő gombjai nem futnak / nem
     * látszanak. Az adatai megmaradnak: visszakapcsolva minden ott folytatódik,
     * ahol abbamaradt.
     * ============================================================== */

    /** A kikapcsolt modulok kulcsai (option). Ami nincs benne, az be van kapcsolva. */
    public const OPT_KI = 'sdh_muhely_modulok_ki';

    /** @var array<int, string>|null */
    private static ?array $ki_gyorsitotar = null;

    /**
     * Az alaprendszer részei – ezek nem kapcsolhatók ki (a Modulok oldalon
     * tájékoztatásul szerepelnek).
     *
     * @return array<string, array{cim: string, leiras: string}>
     */
    public static function alapreszek(): array
    {
        return [
            'attekintes'     => ['cim' => 'Áttekintés', 'leiras' => 'A kezdőképernyő: munkalapok rácsa, nézetek, szűrők, csempék.'],
            'munkalapok'     => ['cim' => 'Munkalapok', 'leiras' => 'Felvétel, állapotok, több eszköz, hibák, tételek, fizetés.'],
            'ugyfelek'       => ['cim' => 'Ügyfelek', 'leiras' => 'Ügyféltörzs, adatlap, előzmények.'],
            'eszkozok'       => ['cim' => 'Eszközök', 'leiras' => 'Készüléktörzs: IMEI, sorozatszám, zárkód, minta.'],
            'szolgaltatasok' => ['cim' => 'Szolgáltatások', 'leiras' => 'Szolgáltatás-törzs és árak, bevizsgálási díj.'],
            'beallitasok'    => ['cim' => 'Beállítások', 'leiras' => 'Minden paraméter, lapfülenként.'],
            'sugo'           => ['cim' => 'Súgó', 'leiras' => 'Bemutató, dokumentáció, kérdés–válasz.'],
            'mentes'         => ['cim' => 'Mentés', 'leiras' => 'Független napi mentés és visszaállítás.'],
            'naplo'          => ['cim' => 'Napló', 'leiras' => 'Minden módosítás mezőszinten, visszakereshetően; lezárt lap zárolása.'],
            'riportok'       => ['cim' => 'Riportok', 'leiras' => 'Átfutás, technikusok, javítási arány, bevétel kategóriák szerint (csak adminnak).'],
        ];
    }

    /**
     * A ki- és bekapcsolható fő modulok, csoportonként sorrendben.
     *
     * - `ajax`: az AJAX-műveletek (sdh_muhely_ után) elejei, amelyek a modulé –
     *   kikapcsolva ezek elutasítanak; `kivetel`: ami mégis mehet (pl. a munkalap
     *   Fizetés ablaka akkor is kell, ha nincs Házipénztár modul).
     * - `post`: admin-post.php műveletek elejei.
     * - `fugg`: a modulok, amelyek nélkül ez nem működik.
     * - `szakaszok`: a Beállítások lapfülei, amelyek kikapcsolva eltűnnek.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function katalogus(): array
    {
        static $katalogus = null;

        if ($katalogus !== null) {
            return $katalogus;
        }

        return $katalogus = [
            // --- Értékesítés -------------------------------------------------
            'termekek' => [
                'cim' => 'Termékek és készlet', 'csoport' => 'Értékesítés', 'ikon' => 'termekek',
                'leiras' => 'Termékkatalógus, készlet, vonalkód; termék-tételek a munkalapon, az árukiadáson és a rendelésen.',
                'ajax' => ['termekek', 'termek_', 'raktar_'], 'post' => ['termek_'],
                'szakaszok' => [],
            ],
            'arukiadas' => [
                'cim' => 'Napi árukiadás', 'csoport' => 'Értékesítés', 'ikon' => 'arukiadas',
                'leiras' => 'Eladás javítás nélkül (termék vagy szolgáltatás), fizetéssel, előleggel, több helyszínnel.',
                'ajax' => ['arukiadas_'],
                'szakaszok' => [],
            ],
            'rendelesek' => [
                'cim' => 'Rendelések', 'csoport' => 'Értékesítés', 'ikon' => 'rendelesek',
                'leiras' => 'Beszállítói rendelések ügyfélhez kötve, állapotokkal, fizetéssel és értesítéssel az áru megérkezéséről.',
                'ajax' => ['rendelesek_', 'rendelesertesit_'], 'fugg' => ['uzenetek'],
                'szakaszok' => [],
            ],

            // --- Pénzügy -----------------------------------------------------
            'penztar' => [
                'cim' => 'Házipénztár', 'csoport' => 'Pénzügy', 'ikon' => 'penztar',
                'leiras' => 'Napi kassza, címletes zárás, riport, statisztika, Excel-import és egyeztető ügynök. Kikapcsolva a munkalap Fizetés ablaka megmarad, csak nem ír a pénztárba.',
                'ajax' => ['penztar_', 'penztarfizetes_'], 'kivetel' => ['penztarfizetes_urlap', 'penztar_fizet'],
                'szakaszok' => ['penztar'],
            ],
            'szamlazas' => [
                'cim' => 'Számlázz.hu számlázás', 'csoport' => 'Pénzügy', 'ikon' => 'szamlazas',
                'leiras' => 'Valódi számla a munkalapról gombnyomásra, tételesen, adattörlő kóddal, eszközönként is. A helyi számla-előkészítő (PDF) kikapcsolva is megmarad.',
                'ajax' => ['szamla_ment', 'szamla_elonezet', 'szamla_kapcsolat'],
                'szakaszok' => [],
            ],

            // --- Ügyfélkapcsolat ---------------------------------------------
            'uzenetek' => [
                'cim' => 'Ügyfélüzenetek és RMA-oldal', 'csoport' => 'Ügyfélkapcsolat', 'ikon' => 'uzenetek',
                'leiras' => 'Az ügyfél QR-kóddal követi a javítást; csevegő a munkalapon, üzenetek menü, gyors szövegek.',
                'ajax' => ['uzenet_', 'rma_'],
                'szakaszok' => ['ugyfeloldal'],
            ],
            'sms' => [
                'cim' => 'SMS-értesítések', 'csoport' => 'Ügyfélkapcsolat', 'ikon' => 'sms',
                'leiras' => 'SMS a bolti androidos telefonról: sablonok, szabályok, döntési popup, bejövő SMS, napló.',
                'ajax' => ['sms'], 'fugg' => ['uzenetek'],
                'szakaszok' => ['sms'],
            ],
            'levelezes' => [
                'cim' => 'Levelezés', 'csoport' => 'Ügyfélkapcsolat', 'ikon' => 'levelezes',
                'leiras' => 'A cég e-mail-fiókjai a rendszerben (IMAP / SMTP), fontos levelek szűrése, rendező ügynök.',
                'ajax' => ['level_', 'levelrendezo_'],
                'szakaszok' => ['levelezes'],
            ],

            // --- Csapat és idő -----------------------------------------------
            'csapat' => [
                'cim' => 'Csapat-üzenetek', 'csoport' => 'Csapat és idő', 'ikon' => 'csapat',
                'leiras' => 'Belső csevegés vagy üzenőfal (cetlik). A kollégák felvétele kikapcsolva is elérhető.',
                'ajax' => ['csapat_', 'csapatuj_', 'csapatcetli_', 'cetli_'],
                'szakaszok' => [],
            ],
            'naptar' => [
                'cim' => 'Naptár', 'csoport' => 'Csapat és idő', 'ikon' => 'naptar',
                'leiras' => 'Naptár névenként, minden nézettel, emlékeztetőkkel, e-mail értesítéssel és napi összefoglalóval.',
                'ajax' => ['naptar'],
                'szakaszok' => ['naptar'],
            ],

            // --- Mesterséges intelligencia -------------------------------------
            'asszisztens' => [
                'cim' => 'AI-asszisztens', 'csoport' => 'Mesterséges intelligencia', 'ikon' => 'asszisztens',
                'leiras' => 'A figura és a chat: mindenbe belelát, végigvezet, és csak jóváhagyással módosít.',
                'ajax' => ['ai_', 'aitudas_'],
                'szakaszok' => ['asszisztens'],
            ],

            // --- Dokumentumok és készülékadatok -------------------------------
            'nyomtatas' => [
                'cim' => 'Nyomtatványok', 'csoport' => 'Dokumentumok és készülékadatok', 'ikon' => 'nyomtatas',
                'leiras' => 'Átvételi elismervény, munkalap, árajánlat, szállítólevél, bianko, riportok – PDF, nyomtatás, e-mail.',
                'ajax' => ['nyomtatas_', 'nyomtsablon_'],
                'szakaszok' => ['nyomtatvanyok'],
            ],
            'imei' => [
                'cim' => 'IMEI-lekérdezés', 'csoport' => 'Dokumentumok és készülékadatok', 'ikon' => 'imei',
                'leiras' => 'Fizetős IMEI-szolgáltató, ingyenes ellenőrző oldalak, a gyári adatok beillesztése.',
                'ajax' => ['imei_'],
                'szakaszok' => ['imei'],
            ],
            'tac' => [
                'cim' => 'TAC-adatbázis', 'csoport' => 'Dokumentumok és készülékadatok', 'ikon' => 'tac',
                'leiras' => 'Az IMEI elejéből felismeri a gyártót és a típust, és tanul a felvitt készülékekből.',
                'ajax' => ['tac_'],
                'szakaszok' => ['tac'],
            ],
            'matrica' => [
                'cim' => 'Címkenyomtatás (Brother)', 'csoport' => 'Dokumentumok és készülékadatok', 'ikon' => 'matrica',
                'leiras' => 'Brother címkenyomtató: RMA-matrica a készülékre, termék- és szolgáltatás-címke (ár, cikkszám, vonalkód) – böngészőből vagy közvetlenül hálózaton, állítható tartalommal.',
                'ajax' => ['matrica_'],
                'szakaszok' => ['matrica'],
            ],
            'mockup' => [
                'cim' => 'Eszköz-mockupok', 'csoport' => 'Dokumentumok és készülékadatok', 'ikon' => 'mockup',
                'leiras' => 'Termékkép minden eszköz mellett (kategória, gyártó, típus szerint), saját képekkel.',
                'szakaszok' => ['mockupok'],
            ],
        ];
    }

    /** @return array<int, string> A kikapcsolt modulok kulcsai. */
    public static function kikapcsoltak(): array
    {
        if (self::$ki_gyorsitotar === null) {
            $ki = function_exists('get_option') ? get_option(self::OPT_KI, []) : [];
            $ki = is_array($ki) ? array_values(array_intersect(array_map('strval', $ki), array_keys(self::katalogus()))) : [];

            self::$ki_gyorsitotar = $ki;
        }

        return self::$ki_gyorsitotar;
    }

    /**
     * Be van-e kapcsolva a modul. Az alaprendszer és az ismeretlen kulcs mindig „be".
     * Függő modul csak akkor fut, ha amire épül, az is be van kapcsolva.
     */
    public static function be(string $kulcs): bool
    {
        $katalogus = self::katalogus();

        if (!isset($katalogus[$kulcs])) {
            return true;
        }

        if (in_array($kulcs, self::kikapcsoltak(), true)) {
            return false;
        }

        foreach ((array) ($katalogus[$kulcs]['fugg'] ?? []) as $alap) {
            if (in_array((string) $alap, self::kikapcsoltak(), true)) {
                return false;
            }
        }

        return true;
    }

    /** Ki van-e kapcsolva (a be() tagadása, az olvashatóság kedvéért). */
    public static function ki(string $kulcs): bool
    {
        return !self::be($kulcs);
    }

    /**
     * A kikapcsolt modulok mentése. A függőségeket rendbe teszi: ha egy modul
     * ki van kapcsolva, az is kikapcsol, ami rá épül.
     *
     * @param array<int, string> $ki
     * @return array<int, string> Ami a függőség miatt kapcsolt még ki (a kérésen felül).
     */
    public static function kikapcsoltak_ment(array $ki): array
    {
        $katalogus = self::katalogus();
        $ki        = array_values(array_unique(array_intersect(array_map('strval', $ki), array_keys($katalogus))));
        $plusz     = [];

        do {
            $valtozott = false;

            foreach ($katalogus as $kulcs => $m) {
                if (in_array($kulcs, $ki, true)) {
                    continue;
                }

                foreach ((array) ($m['fugg'] ?? []) as $alap) {
                    if (in_array((string) $alap, $ki, true)) {
                        $ki[]      = $kulcs;
                        $plusz[]   = $kulcs;
                        $valtozott = true;
                        break;
                    }
                }
            }
        } while ($valtozott);

        sort($ki);
        update_option(self::OPT_KI, $ki, true);
        self::$ki_gyorsitotar = null;

        return $plusz;
    }

    /** Teszthez és mentés után: a gyorsítótár ürítése. */
    public static function kapcsolok_urit(): void
    {
        self::$ki_gyorsitotar = null;
    }

    /**
     * Melyik kikapcsolható modulhoz tartozik egy AJAX-művelet (az `sdh_muhely_`
     * előtag nélkül). Üres = egyikhez sem (vagy kivétel).
     */
    public static function ajax_modulja(string $muvelet): string
    {
        foreach (self::katalogus() as $kulcs => $m) {
            if (in_array($muvelet, (array) ($m['kivetel'] ?? []), true)) {
                return '';
            }

            foreach ((array) ($m['ajax'] ?? []) as $eleje) {
                if ($eleje !== '' && strpos($muvelet, (string) $eleje) === 0) {
                    return $kulcs;
                }
            }
        }

        return '';
    }

    /** Ugyanez admin-post.php műveletekre. */
    public static function post_modulja(string $muvelet): string
    {
        foreach (self::katalogus() as $kulcs => $m) {
            foreach ((array) ($m['post'] ?? []) as $eleje) {
                if ($eleje !== '' && strpos($muvelet, (string) $eleje) === 0) {
                    return $kulcs;
                }
            }
        }

        return '';
    }

    /** Melyik modul írja le a Beállítások egy lapfülét (üres = az alaprendszer). */
    public static function szakasz_modulja(string $ful): string
    {
        foreach (self::katalogus() as $kulcs => $m) {
            if (in_array($ful, (array) ($m['szakaszok'] ?? []), true)) {
                return $kulcs;
            }
        }

        return '';
    }

    /* =================================================================
     * Kontextus
     * ============================================================== */

    public static function kontextus_beallit(string $kontextus): void
    {
        self::$kontextus = $kontextus === 'frontend' ? 'frontend' : 'admin';
    }

    public static function kontextus(): string
    {
        return self::$kontextus;
    }

    public static function frontenden_vagyunk(): bool
    {
        return self::$kontextus === 'frontend';
    }

    /* =================================================================
     * URL-ek
     * ============================================================== */

    /**
     * Egy modul URL-je az éppen futó felületen.
     *
     * @param string               $kulcs       Modulkulcs, pl. 'ugyfelek'. Üres = áttekintés.
     * @param array<string, mixed> $parameterek Lekérdezési paraméterek (nezet, id, k…).
     */
    public static function url(string $kulcs = '', array $parameterek = []): string
    {
        return self::frontenden_vagyunk()
            ? self::frontend_url($kulcs, $parameterek)
            : self::admin_url($kulcs, $parameterek);
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    public static function admin_url(string $kulcs = '', array $parameterek = []): string
    {
        $slug = $kulcs === ''
            ? SDH_Muhely_Admin_UI::FOMENU
            : SDH_Muhely_Admin_UI::FOMENU . '-' . $kulcs;

        return add_query_arg(
            array_merge(['page' => $slug], $parameterek),
            admin_url('admin.php')
        );
    }

    /**
     * A frontend URL-je.
     *
     * Szép URL-eknél /muhely/ugyfelek/, egyébként ?muhely=ugyfelek.
     * A Local alapértelmezésben nem feltétlenül kapcsol szép URL-t, és
     * a rendszernek akkor is működnie kell.
     *
     * @param array<string, mixed> $parameterek
     */
    public static function frontend_url(string $kulcs = '', array $parameterek = []): string
    {
        if (get_option('permalink_structure')) {
            $ut = SDH_Muhely_Frontend::ALAP . '/' . ($kulcs !== '' ? $kulcs . '/' : '');

            return add_query_arg($parameterek, home_url('/' . ltrim($ut, '/')));
        }

        return add_query_arg(
            array_merge([SDH_Muhely_Frontend::QUERY_VAR => $kulcs !== '' ? $kulcs : 'attekintes'], $parameterek),
            home_url('/')
        );
    }

    /**
     * Az űrlapok mindkét felületről az admin-post.php-ra küldenek be.
     * Mentés után ez mondja meg, hová térjünk vissza.
     */
    public static function visszateres(string $kulcs, array $parameterek, string $kontextus): string
    {
        return $kontextus === 'frontend'
            ? self::frontend_url($kulcs, $parameterek)
            : self::admin_url($kulcs, $parameterek);
    }

    /* =================================================================
     * GET-űrlapok (kereső, szűrő)
     * ============================================================== */

    /**
     * Egy GET-es űrlap `action` címe: az URL lekérdezés nélküli része.
     *
     * A böngésző a GET-űrlap beküldésekor eldobja az action URL-jében
     * lévő lekérdezést, ezért azt rejtett mezőkben kell visszaadni –
     * lásd urlap_rejtett().
     */
    public static function urlap_cel(string $kulcs = ''): string
    {
        $url   = self::url($kulcs);
        $reszek = wp_parse_url($url);

        // A port is kell: a Local „localhost" módban (pl. localhost:10005)
        // nélküle a kereső egy nem létező címre küldene.
        return sprintf(
            '%s://%s%s%s',
            $reszek['scheme'] ?? 'http',
            $reszek['host'] ?? '',
            isset($reszek['port']) ? ':' . (int) $reszek['port'] : '',
            $reszek['path'] ?? '/'
        );
    }

    /**
     * Azok a rejtett mezők, amik nélkül a GET-űrlap nem találna vissza
     * erre az oldalra (adminban a `page`, szép URL nélkül a `muhely`).
     */
    public static function urlap_rejtett(string $kulcs = ''): void
    {
        $url   = self::url($kulcs);
        $query = wp_parse_url($url, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return;
        }

        $parok = [];
        wp_parse_str($query, $parok);

        foreach ($parok as $nev => $ertek) {
            printf(
                '<input type="hidden" name="%s" value="%s">',
                esc_attr((string) $nev),
                esc_attr((string) $ertek)
            );
        }
    }
}
