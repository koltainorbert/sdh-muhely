<?php
/**
 * AI-asszisztens – eszközök (0.38).
 *
 * Az asszisztens ezeken keresztül lát bele a rendszerbe és tesz javaslatot.
 * Minden eszköznek van szintje:
 *  - olvas:   csak olvas (kereső, munkalap, ügyfél, pénztár, áttekintés…).
 *             A felhasználó kérdésére fut; a Beállításokban kérhető, hogy
 *             ehhez is engedély kelljen.
 *  - felulet: a felületen mutat valamit (elem kiemelése, oldal megnyitása).
 *             Adatot nem érint.
 *  - hozzaad: új adat (megjegyzés, csapatüzenet, tudástár-sor). Javaslat →
 *             emberi jóváhagyás → végrehajtás.
 *  - atir:    meglévő adat felülírása (állapot, határidő, ügyfél adatai, ár).
 *             Csak a felhasználó kifejezett kérésére, jóváhagyáskor megerősítő
 *             kód beírásával.
 *  - torol:   törlés – ugyanígy, és csak ahol a rendszer egyáltalán enged
 *             törlést (tudástár). Ügyfél és munkalap nem törölhető.
 *
 * Minden végrehajtás előtt a rekord teljes másolata mentődik (elotte),
 * utána is (utana) – ebből a művelet visszaállítható.
 *
 * Új eszköz = új elem a definiciok() tömbjében. Az asszisztens a következő
 * kérdéstől már használja.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Asszisztens_Eszkozok
{
    /** Az olvasó eszközök legfeljebb ennyi karaktert adnak vissza. */
    private const MAX_VALASZ = 14000;

    /** Az ügyfél adataiból ezek írhatók át. */
    private const UGYFEL_MEZOK = [
        'nev', 'kapcsolattarto', 'adoszam', 'telefon', 'telefon2', 'email',
        'szamlazasi_iranyitoszam', 'szamlazasi_telepules', 'szamlazasi_cim',
        'kategoria', 'megjegyzes', 'belso_megjegyzes',
    ];

    /* =================================================================
     * Definíciók
     * ============================================================== */

    /**
     * @return array<string, array{szint: string, cim: string, leiras: string, schema: array<string, mixed>}>
     */
    public static function definiciok(): array
    {
        $obj = static fn (array $props, array $kotelezo = []): array => ['type' => 'object', 'properties' => (object) $props, 'required' => $kotelezo];
        $sz  = static fn (string $leiras): array => ['type' => 'string', 'description' => $leiras];
        $eg  = static fn (string $leiras): array => ['type' => 'integer', 'description' => $leiras];
        $lo  = static fn (string $leiras): array => ['type' => 'boolean', 'description' => $leiras];

        $allapotok = class_exists('SDH_Muhely_Munkalap') ? array_keys(SDH_Muhely_Munkalap::allapotok()) : [];

        return [
            /* ---------- olvasás ---------- */
            'kereses' => [
                'szint' => 'olvas', 'cim' => 'Keresés',
                'leiras' => 'Keresés az egész rendszerben: ügyfél (név, telefon, e-mail), eszköz (IMEI, sorozatszám, típus), munkalap (szám, név), termék, szolgáltatás. Mindig ezzel kezdj, ha egy konkrét rekordról kérdeznek.',
                'schema' => $obj([
                    'kifejezes' => $sz('A keresett szöveg vagy szám.'),
                    'tipus'     => ['type' => 'string', 'enum' => ['mind', 'ugyfel', 'eszkoz', 'munkalap', 'termek', 'szolgaltatas'], 'description' => 'Mire keressen (alap: mind).'],
                ], ['kifejezes']),
            ],
            'munkalap_adatok' => [
                'szint' => 'olvas', 'cim' => 'Munkalap adatai',
                'leiras' => 'Egy munkalap minden adata: állapot, ügyfél, eszköz, hibák, tételek, összegek, fizetés, határidő, megjegyzés, állapottörténet, számlák.',
                'schema' => $obj(['munkalap' => $sz('A munkalapszám, ahogy a lapon látszik (pl. 1234), vagy #azonosító (pl. #57).')], ['munkalap']),
            ],
            'munkalapok_listaja' => [
                'szint' => 'olvas', 'cim' => 'Munkalapok listája',
                'leiras' => 'Munkalapok szűrve: állapot, csak nyitottak, lejárt határidő, fizetetlen, felelős, az utolsó N nap.',
                'schema' => $obj([
                    'allapot'        => ['type' => 'string', 'enum' => $allapotok !== [] ? $allapotok : ['nyitott'], 'description' => 'Állapot kulcsa.'],
                    'csak_nyitott'   => $lo('Csak a nyitott (számozott, nem lezárt) lapok.'),
                    'lejart'         => $lo('Csak a lejárt határidejű nyitott lapok.'),
                    'fizetetlen'     => $lo('Csak a ki nem fizetett, értékkel bíró lapok.'),
                    'felelos'        => $sz('A felelős kolléga neve (vagy része).'),
                    'napok'          => $eg('Az utolsó ennyi napban létrehozottak.'),
                    'limit'          => $eg('Legfeljebb ennyi sor (alap 25, max 100).'),
                ]),
            ],
            'ugyfel_adatok' => [
                'szint' => 'olvas', 'cim' => 'Ügyfél adatai',
                'leiras' => 'Egy ügyfél adatai, eszközei és legutóbbi munkalapjai.',
                'schema' => $obj(['ugyfel_id' => $eg('Az ügyfél azonosítója (a keresésből).')], ['ugyfel_id']),
            ],
            'penztar_nap' => [
                'szint' => 'olvas', 'cim' => 'Pénztár egy napja',
                'leiras' => 'A házipénztár egy napjának összesítője és tételei (nyitó, KP, kártya, utalás, kivét, kifizetés, várható, számolt, eltérés, zárás).',
                'schema' => $obj(['datum' => $sz('ÉÉÉÉ-HH-NN; üresen: ma.')]),
            ],
            'attekintes' => [
                'szint' => 'olvas', 'cim' => 'Áttekintés',
                'leiras' => 'A rendszer állapota számokban: nyitott, lejárt, ma lejáró, ma felvett, fizetetlen munkalapok; olvasatlan ügyfélüzenetek és csapatüzenetek; mai pénztár.',
                'schema' => $obj([]),
            ],
            'csapat_olvasatlan' => [
                'szint' => 'olvas', 'cim' => 'Olvasatlan csapatüzenetek',
                'leiras' => 'A felhasználó olvasatlan belső (csapat-) üzenetei.',
                'schema' => $obj([]),
            ],
            'tudastar_kereses' => [
                'szint' => 'olvas', 'cim' => 'Tudástár',
                'leiras' => 'Keresés a tudástárban (amit tanítottak neked, és amit a verziókból tanultál).',
                'schema' => $obj(['kifejezes' => $sz('Mit keresel.')], ['kifejezes']),
            ],
            'muveletnaplo' => [
                'szint' => 'olvas', 'cim' => 'Műveletnapló',
                'leiras' => 'A te (az asszisztens) javaslataid és műveleteid naplója: mit javasoltál, mit hagytak jóvá, mi futott le, mi lett visszavonva.',
                'schema' => $obj(['limit' => $eg('Legfeljebb ennyi sor (alap 15).')]),
            ],

            /* ---------- felület ---------- */
            'mutat' => [
                'szint' => 'felulet', 'cim' => 'Megmutatom',
                'leiras' => 'Kiemel egy elemet a felhasználó képernyőjén (neon keret + buborék a magyarázattal). Az elem azonosítóját a „LÁTHATÓ ELEMEK" listából vedd (pl. e12). Lépésenkénti vezetésnél egyszerre csak a következő lépés elemét mutasd.',
                'schema' => $obj([
                    'elem'   => $sz('Az elem azonosítója a látható elemek listájából (pl. e12).'),
                    'szoveg' => $sz('Rövid magyarázat a buborékba (mit csináljon vele).'),
                ], ['elem', 'szoveg']),
            ],
            'megnyit' => [
                'szint' => 'felulet', 'cim' => 'Megnyitás',
                'leiras' => 'Gombot ad a felhasználónak, amivel egy oldalra ugrik vagy egy rekordot megnyit (munkalap, ügyfél, eszköz popupja).',
                'schema' => $obj([
                    'cel'     => $sz('Modulkulcs (pl. penztar, csapat, szolgaltatasok, attekintes) vagy rekordtípus: munkalap, ugyfel, eszkoz.'),
                    'id'      => $eg('Rekord azonosítója (munkalap/ugyfel/eszkoz esetén).'),
                    'felirat' => $sz('A gomb felirata.'),
                ], ['cel']),
            ],

            /* ---------- módosítás (jóváhagyással) ---------- */
            'munkalap_megjegyzes' => [
                'szint' => 'hozzaad', 'cim' => 'Megjegyzés a munkalapra',
                'leiras' => 'Belső megjegyzést fűz egy munkalap megjegyzéséhez (a régi szöveg megmarad, az új a végére kerül dátummal és névvel). Jóváhagyás kell.',
                'schema' => $obj(['munkalap' => $sz('Munkalapszám vagy #azonosító.'), 'szoveg' => $sz('A hozzáfűzendő megjegyzés.')], ['munkalap', 'szoveg']),
            ],
            'munkalap_allapot' => [
                'szint' => 'atir', 'cim' => 'Munkalap állapotának átírása',
                'leiras' => 'Átírja egy munkalap állapotát. Csak a felhasználó kifejezett kérésére; jóváhagyás + megerősítő kód kell.',
                'schema' => $obj([
                    'munkalap' => $sz('Munkalapszám vagy #azonosító.'),
                    'allapot'  => ['type' => 'string', 'enum' => $allapotok !== [] ? $allapotok : ['nyitott'], 'description' => 'Az új állapot kulcsa.'],
                ], ['munkalap', 'allapot']),
            ],
            'munkalap_hatarido' => [
                'szint' => 'atir', 'cim' => 'Munkalap határidejének átírása',
                'leiras' => 'Átírja egy munkalap határidejét. Csak kifejezett kérésre; jóváhagyás + megerősítő kód kell.',
                'schema' => $obj(['munkalap' => $sz('Munkalapszám vagy #azonosító.'), 'datum' => $sz('Új határidő ÉÉÉÉ-HH-NN; üres szöveg = határidő törlése.')], ['munkalap', 'datum']),
            ],
            'ugyfel_modositas' => [
                'szint' => 'atir', 'cim' => 'Ügyfél adatainak átírása',
                'leiras' => 'Átírja egy ügyfél megadott mezőit (' . implode(', ', self::UGYFEL_MEZOK) . '). Csak kifejezett kérésre; jóváhagyás + megerősítő kód kell.',
                'schema' => $obj([
                    'ugyfel_id' => $eg('Az ügyfél azonosítója.'),
                    'mezok'     => ['type' => 'object', 'description' => 'Mező => új érték, pl. {"telefon": "+36 30 123 4567"}.', 'additionalProperties' => ['type' => 'string']],
                ], ['ugyfel_id', 'mezok']),
            ],
            'szolgaltatas_ar' => [
                'szint' => 'atir', 'cim' => 'Szolgáltatás árának átírása',
                'leiras' => 'Átírja egy szolgáltatás bruttó árát a Szolgáltatások között. Csak kifejezett kérésre; jóváhagyás + megerősítő kód kell.',
                'schema' => $obj(['szolgaltatas_id' => $eg('A szolgáltatás azonosítója (a keresésből).'), 'brutto_ar' => ['type' => 'number', 'description' => 'Új bruttó ár forintban.']], ['szolgaltatas_id', 'brutto_ar']),
            ],
            'csapat_uzenet' => [
                'szint' => 'hozzaad', 'cim' => 'Csapatüzenet küldése',
                'leiras' => 'Belső üzenetet küld a felhasználó nevében (jelölve, hogy AI-val írták) kollégának, csoportnak vagy mindenkinek. Jóváhagyás kell.',
                'schema' => $obj([
                    'cimzettek' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Kollégák neve, „csoport:Név", vagy „mindenki".'],
                    'szoveg'    => $sz('Az üzenet.'),
                    'fontos'    => $lo('Fontos üzenet (felugró ablakban jelenik meg).'),
                    'munkalap'  => $sz('Ha egy munkalaphoz kapcsolódik: munkalapszám vagy #azonosító (nem kötelező).'),
                ], ['cimzettek', 'szoveg']),
            ],
            'tudas_mentes' => [
                'szint' => 'hozzaad', 'cim' => 'Megtanulom',
                'leiras' => 'Új tudást ment a tudástáradba (pl. egy cégen belüli szabályt, bevált megoldást, amit a felhasználó elmondott). Jóváhagyás kell.',
                'schema' => $obj(['cim' => $sz('Rövid cím.'), 'szoveg' => $sz('A megjegyzendő tudás.'), 'kulcsszavak' => $sz('Vesszővel elválasztott kulcsszavak.')], ['cim', 'szoveg']),
            ],
            'tudas_torles' => [
                'szint' => 'torol', 'cim' => 'Tudás törlése',
                'leiras' => 'Kivesz egy sort a tudástárból (kikapcsolja; visszaállítható). Csak kifejezett kérésre; jóváhagyás + megerősítő kód kell.',
                'schema' => $obj(['tudas_id' => $eg('A tudástár-sor azonosítója.')], ['tudas_id']),
            ],
        ];
    }

    /**
     * Az API-nak küldött eszközlista (a Beállításokban kikapcsoltak nélkül).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function api_lista(): array
    {
        $engedett = SDH_Muhely_Asszisztens::beallitas()['eszkozok'];
        $ki = [];

        foreach (self::definiciok() as $nev => $d) {
            if (isset($engedett[$nev]) && !$engedett[$nev]) {
                continue;
            }

            $szint = ['olvas' => '', 'felulet' => '', 'hozzaad' => ' [JÓVÁHAGYÁS KELL]', 'atir' => ' [ÁTÍRÁS – csak kifejezett kérésre, jóváhagyás + kód]', 'torol' => ' [TÖRLÉS – csak kifejezett kérésre, jóváhagyás + kód]'][$d['szint']];

            $ki[] = ['name' => $nev, 'description' => $d['leiras'] . $szint, 'input_schema' => $d['schema']];
        }

        return $ki;
    }

    public static function szint(string $nev): string
    {
        return self::definiciok()[$nev]['szint'] ?? '';
    }

    /* =================================================================
     * Segédek
     * ============================================================== */

    private static function tabla(string $nev): string
    {
        return SDH_Muhely_Schema::tabla($nev);
    }

    /** @param mixed $adat */
    private static function json($adat): string
    {
        $s = (string) wp_json_encode($adat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return strlen($s) > self::MAX_VALASZ ? mb_strcut($s, 0, self::MAX_VALASZ) . '…[levágva]' : $s;
    }

    private static function penz($n): string
    {
        return number_format((float) $n, 0, ',', ' ') . ' Ft';
    }

    /** Munkalap a felhasználó hivatkozásából (szám vagy #azonosító). */
    public static function munkalap_keres(string $hiv): ?object
    {
        global $wpdb;

        $t   = self::tabla('munkalap');
        $hiv = trim($hiv);

        if ($hiv === '') {
            return null;
        }

        if ($hiv[0] === '#') {
            $id = (int) substr($hiv, 1);

            return $id > 0 ? ($wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id)) ?: null) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        $szam = (int) preg_replace('/\D+/', '', $hiv);

        if ($szam <= 0) {
            return null;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE munkalap_szam = %d", $szam));

        return $sor ?: ($wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d AND munkalap_szam IS NULL", $szam)) ?: null);
        // phpcs:enable
    }

    private static function sor(string $tabla, int $id): ?array
    {
        global $wpdb;

        $t = self::tabla($tabla);
        $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($s) ? $s : null;
    }

    private static function allapot_nev(string $k): string
    {
        $l = SDH_Muhely_Munkalap::allapotok();

        return $l[$k]['nev'] ?? $k;
    }

    private static function datum_ok(string $d): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /* =================================================================
     * Olvasás
     * ============================================================== */

    /**
     * Olvasó vagy felület-eszköz futtatása.
     *
     * @param array<string, mixed> $p
     */
    public static function olvas(string $nev, array $p): string
    {
        try {
            switch ($nev) {
                case 'kereses':
                    return self::json(self::kereses((string) ($p['kifejezes'] ?? ''), (string) ($p['tipus'] ?? 'mind')));
                case 'munkalap_adatok':
                    return self::json(self::munkalap_adatok((string) ($p['munkalap'] ?? '')));
                case 'munkalapok_listaja':
                    return self::json(self::munkalapok($p));
                case 'ugyfel_adatok':
                    return self::json(self::ugyfel_adatok((int) ($p['ugyfel_id'] ?? 0)));
                case 'penztar_nap':
                    return self::json(self::penztar((string) ($p['datum'] ?? '')));
                case 'attekintes':
                    return self::json(self::attekintes());
                case 'csapat_olvasatlan':
                    return self::json(self::csapat_olvasatlan());
                case 'tudastar_kereses':
                    return self::json(array_map(static fn ($r) => ['id' => (int) $r->id, 'cim' => $r->cim, 'szoveg' => $r->szoveg, 'forras' => $r->forras], SDH_Muhely_Asszisztens_Tudas::keres((string) ($p['kifejezes'] ?? ''), 10)));
                case 'muveletnaplo':
                    return self::json(self::naplo(min(50, max(1, (int) ($p['limit'] ?? 15)))));
            }
        } catch (Throwable $h) {
            return self::json(['hiba' => 'Az adat nem olvasható: ' . $h->getMessage()]);
        }

        return self::json(['hiba' => 'Ismeretlen eszköz.']);
    }

    /** @return array<string, mixed> */
    private static function kereses(string $q, string $tipus): array
    {
        global $wpdb;

        $q = trim($q);

        if (mb_strlen($q) < 2) {
            return ['hiba' => 'Legalább 2 karakter kell.'];
        }

        $m  = '%' . $wpdb->esc_like($q) . '%';
        $ki = [];
        $mind = $tipus === '' || $tipus === 'mind';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($mind || $tipus === 'ugyfel') {
            $t = self::tabla('ugyfel');
            $ki['ugyfelek'] = $wpdb->get_results($wpdb->prepare(
                "SELECT id, ugyfel_szam, nev, telefon, email, szamlazasi_telepules AS telepules, tipus FROM {$t}
                 WHERE aktiv = 1 AND (nev LIKE %s OR telefon LIKE %s OR telefon2 LIKE %s OR email LIKE %s OR ugyfel_szam LIKE %s OR adoszam LIKE %s)
                 ORDER BY nev LIMIT 10",
                $m, $m, $m, $m, $m, $m
            ), ARRAY_A);
        }

        if ($mind || $tipus === 'eszkoz') {
            $t = self::tabla('eszkoz');
            $u = self::tabla('ugyfel');
            $ki['eszkozok'] = $wpdb->get_results($wpdb->prepare(
                "SELECT e.id, e.gyarto, e.tipus, e.megnevezes, e.imei, e.sorozatszam, e.ugyfel_id, u.nev AS ugyfel FROM {$t} e LEFT JOIN {$u} u ON u.id = e.ugyfel_id
                 WHERE e.aktiv = 1 AND (e.imei LIKE %s OR e.imei2 LIKE %s OR e.sorozatszam LIKE %s OR e.tipus LIKE %s OR e.megnevezes LIKE %s OR e.modell_szam LIKE %s)
                 ORDER BY e.modositva DESC LIMIT 10",
                $m, $m, $m, $m, $m, $m
            ), ARRAY_A);
        }

        if ($mind || $tipus === 'munkalap') {
            $t   = self::tabla('munkalap');
            $u   = self::tabla('ugyfel');
            $szam = (int) preg_replace('/\D+/', '', $q);
            $sorok = $wpdb->get_results($wpdb->prepare(
                "SELECT m.id, m.munkalap_szam, m.allapot, m.nev, m.hatarido, m.brutto_ertek, m.fizetve, u.nev AS ugyfel FROM {$t} m LEFT JOIN {$u} u ON u.id = m.ugyfel_id
                 WHERE m.munkalap_szam = %d OR m.nev LIKE %s OR u.nev LIKE %s OR u.telefon LIKE %s
                 ORDER BY m.id DESC LIMIT 10",
                $szam, $m, $m, $m
            ));
            $ki['munkalapok'] = array_map(static fn ($s) => [
                'id' => (int) $s->id, 'szam' => SDH_Muhely_Munkalap::szam_formaz($s->munkalap_szam), 'allapot' => self::allapot_nev((string) $s->allapot),
                'nev' => $s->nev, 'ugyfel' => $s->ugyfel, 'hatarido' => $s->hatarido, 'brutto' => self::penz($s->brutto_ertek), 'fizetve' => (bool) $s->fizetve,
            ], (array) $sorok);
        }

        if ($mind || $tipus === 'termek') {
            $t = self::tabla('termek');
            $ki['termekek'] = $wpdb->get_results($wpdb->prepare(
                "SELECT id, megnevezes, cikkszam, termekkod, vonalkod, keszlet, me, brutto_ar FROM {$t}
                 WHERE aktiv = 1 AND (megnevezes LIKE %s OR cikkszam LIKE %s OR termekkod LIKE %s OR vonalkod LIKE %s) ORDER BY megnevezes LIMIT 10",
                $m, $m, $m, $m
            ), ARRAY_A);
        }

        if ($mind || $tipus === 'szolgaltatas') {
            $t = self::tabla('szolgaltatas');
            $ki['szolgaltatasok'] = $wpdb->get_results($wpdb->prepare(
                "SELECT id, nev, kategoria, kod, brutto_ar, ar_max, bevizsgalas FROM {$t} WHERE nev LIKE %s OR kod LIKE %s OR kategoria LIKE %s ORDER BY hasznalat DESC LIMIT 10",
                $m, $m, $m
            ), ARRAY_A);
        }
        // phpcs:enable

        return array_filter($ki);
    }

    /** @return array<string, mixed> */
    private static function munkalap_adatok(string $hiv): array
    {
        global $wpdb;

        $ml = self::munkalap_keres($hiv);

        if ($ml === null) {
            return ['hiba' => 'Nincs ilyen munkalap: ' . $hiv];
        }

        $id = (int) $ml->id;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ugyfel = $wpdb->get_row($wpdb->prepare('SELECT id, nev, telefon, email, szamlazasi_telepules FROM ' . self::tabla('ugyfel') . ' WHERE id = %d', (int) $ml->ugyfel_id), ARRAY_A);
        $eszkoz = $wpdb->get_row($wpdb->prepare('SELECT id, gyarto, tipus, megnevezes, imei, sorozatszam, szin, garancias, tartozekok, atveteli_allapot FROM ' . self::tabla('eszkoz') . ' WHERE id = %d', (int) $ml->eszkoz_id), ARRAY_A);
        $hibak  = $wpdb->get_results($wpdb->prepare('SELECT leiras, javitas, allapot FROM ' . self::tabla('munkalap_hiba') . ' WHERE munkalap_id = %d ORDER BY sorrend', $id), ARRAY_A);
        $tetelek = $wpdb->get_results($wpdb->prepare('SELECT tipus, megnevezes, mennyiseg, me, brutto_ar, brutto_ertek, kedvezmeny FROM ' . self::tabla('munkalap_tetel') . ' WHERE munkalap_id = %d ORDER BY sorrend', $id), ARRAY_A);
        $naplo  = $wpdb->get_results($wpdb->prepare('SELECT allapot, elozo, felhasznalo, letrehozva FROM ' . self::tabla('munkalap_naplo') . ' WHERE munkalap_id = %d ORDER BY id DESC LIMIT 15', $id));
        $szamlak = $wpdb->get_results($wpdb->prepare('SELECT szamlaszam, sorozat, brutto, kelt, fizetve FROM ' . self::tabla('szamla') . ' WHERE munkalap_id = %d ORDER BY id', $id), ARRAY_A);
        // phpcs:enable

        return [
            'id'          => $id,
            'szam'        => SDH_Muhely_Munkalap::szam_formaz($ml->munkalap_szam),
            'allapot'     => self::allapot_nev((string) $ml->allapot) . ' (' . $ml->allapot . ')',
            'nev'         => $ml->nev,
            'felelos'     => SDH_Muhely_Munkalap::felelos_nev((int) $ml->felelos),
            'letrehozva'  => $ml->letrehozva,
            'hatarido'    => $ml->hatarido,
            'lezarva'     => $ml->lezarva,
            'brutto_ertek' => self::penz($ml->brutto_ertek),
            'eloleg'      => self::penz($ml->fizetett),
            'fizetve'     => (bool) $ml->fizetve,
            'fizetesi_mod' => $ml->fizetesi_mod,
            'bevizsgalasi_dij' => (bool) $ml->bevizsgalasi_dij,
            'megjegyzes_belso' => $ml->megjegyzes,
            'ugyfel_megjegyzes' => $ml->ugyfel_megjegyzes,
            'ugyfel'      => $ugyfel,
            'eszkoz'      => $eszkoz,
            'hibak'       => $hibak,
            'tetelek'     => $tetelek,
            'szamlak'     => $szamlak,
            'allapottortenet' => array_map(static fn ($n) => [
                'mikor' => $n->letrehozva, 'mire' => self::allapot_nev((string) $n->allapot), 'honnan' => self::allapot_nev((string) $n->elozo),
                'ki' => SDH_Muhely_Munkalap::felelos_nev((int) $n->felhasznalo),
            ], (array) $naplo),
        ];
    }

    /** @param array<string, mixed> $p */
    private static function munkalapok(array $p): array
    {
        global $wpdb;

        $t      = self::tabla('munkalap');
        $u      = self::tabla('ugyfel');
        $hol    = ['1=1'];
        $ertek  = [];
        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $nyitott = SDH_Muhely_Munkalap::nyitott_kulcsok($allapotok);

        if (!empty($p['allapot']) && isset($allapotok[(string) $p['allapot']])) {
            $hol[] = 'm.allapot = %s';
            $ertek[] = (string) $p['allapot'];
        }

        if ((!empty($p['csak_nyitott']) || !empty($p['lejart'])) && $nyitott !== []) {
            $hol[] = 'm.allapot IN (' . implode(',', array_fill(0, count($nyitott), '%s')) . ')';
            $ertek = array_merge($ertek, $nyitott);
        }

        if (!empty($p['lejart'])) {
            $hol[] = 'm.hatarido IS NOT NULL AND m.hatarido < %s';
            $ertek[] = current_time('Y-m-d');
        }

        if (!empty($p['fizetetlen'])) {
            $hol[] = 'm.fizetve = 0 AND m.brutto_ertek > 0';
        }

        if (!empty($p['napok'])) {
            $hol[] = 'm.letrehozva >= %s';
            $ertek[] = wp_date('Y-m-d 00:00:00', time() - max(1, (int) $p['napok']) * DAY_IN_SECONDS);
        }

        if (!empty($p['felelos'])) {
            $idk = [];

            foreach (SDH_Muhely_Munkalap::felelosok() as $fid => $fnev) {
                if (mb_stripos(remove_accents($fnev), remove_accents((string) $p['felelos'])) !== false) {
                    $idk[] = (int) $fid;
                }
            }

            $hol[] = $idk !== [] ? 'm.felelos IN (' . implode(',', array_map('intval', $idk)) . ')' : '1=0';
        }

        $limit = min(100, max(1, (int) ($p['limit'] ?? 25)));
        $sql   = "SELECT m.id, m.munkalap_szam, m.allapot, m.nev, m.hatarido, m.brutto_ertek, m.fizetve, m.felelos, m.letrehozva, u.nev AS ugyfel
                  FROM {$t} m LEFT JOIN {$u} u ON u.id = m.ugyfel_id WHERE " . implode(' AND ', $hol) . " ORDER BY m.id DESC LIMIT {$limit}";
        $osszes = "SELECT COUNT(*) FROM {$t} m WHERE " . implode(' AND ', $hol);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $sorok = $ertek !== [] ? $wpdb->get_results($wpdb->prepare($sql, $ertek)) : $wpdb->get_results($sql);
        $db    = $ertek !== [] ? (int) $wpdb->get_var($wpdb->prepare($osszes, $ertek)) : (int) $wpdb->get_var($osszes);
        // phpcs:enable

        return [
            'osszesen' => $db,
            'lista'    => array_map(static fn ($s) => [
                'id' => (int) $s->id, 'szam' => SDH_Muhely_Munkalap::szam_formaz($s->munkalap_szam), 'allapot' => self::allapot_nev((string) $s->allapot),
                'nev' => $s->nev, 'ugyfel' => $s->ugyfel, 'hatarido' => $s->hatarido, 'brutto' => self::penz($s->brutto_ertek), 'fizetve' => (bool) $s->fizetve,
                'felelos' => SDH_Muhely_Munkalap::felelos_nev((int) $s->felelos), 'letrehozva' => $s->letrehozva,
            ], (array) $sorok),
        ];
    }

    /** @return array<string, mixed> */
    private static function ugyfel_adatok(int $id): array
    {
        global $wpdb;

        $u = self::sor('ugyfel', $id);

        if ($u === null) {
            return ['hiba' => 'Nincs ilyen ügyfél.'];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $eszkozok = $wpdb->get_results($wpdb->prepare('SELECT id, gyarto, tipus, megnevezes, imei, sorozatszam FROM ' . self::tabla('eszkoz') . ' WHERE ugyfel_id = %d AND aktiv = 1 ORDER BY id DESC LIMIT 20', $id), ARRAY_A);
        $lapok    = $wpdb->get_results($wpdb->prepare('SELECT id, munkalap_szam, allapot, nev, brutto_ertek, fizetve, letrehozva FROM ' . self::tabla('munkalap') . ' WHERE ugyfel_id = %d ORDER BY id DESC LIMIT 15', $id));
        // phpcs:enable

        $adat = array_intersect_key($u, array_flip(array_merge(['id', 'ugyfel_szam', 'tipus', 'kedvezmeny', 'letrehozva'], self::UGYFEL_MEZOK)));

        return [
            'ugyfel'     => $adat,
            'eszkozok'   => $eszkozok,
            'munkalapok' => array_map(static fn ($s) => [
                'id' => (int) $s->id, 'szam' => SDH_Muhely_Munkalap::szam_formaz($s->munkalap_szam), 'allapot' => self::allapot_nev((string) $s->allapot),
                'nev' => $s->nev, 'brutto' => self::penz($s->brutto_ertek), 'fizetve' => (bool) $s->fizetve, 'letrehozva' => $s->letrehozva,
            ], (array) $lapok),
        ];
    }

    /** @return array<string, mixed> */
    private static function penztar(string $datum): array
    {
        if (!class_exists('SDH_Muhely_Penztar')) {
            return ['hiba' => 'A pénztár modul nincs bekapcsolva.'];
        }

        $datum = self::datum_ok($datum) ? $datum : SDH_Muhely_Penztar::ma();
        $nap   = SDH_Muhely_Penztar::nap($datum);

        $tetelek = array_map(static function ($t): array {
            $t = (array) $t;

            return array_intersect_key($t, array_flip(['id', 'tipus', 'leiras', 'nev', 'szemely', 'kp', 'kartya', 'utalas', 'munkalap_szam', 'szamlaszam', 'megjegyzes', 'ido']));
        }, array_slice(SDH_Muhely_Penztar::nap_tetelei($datum), 0, 120));

        return [
            'datum'   => $datum,
            'nap'     => $nap ? array_intersect_key((array) $nap, array_flip(['allapot', 'nyito', 'kp_be', 'kartya', 'utalas', 'kifizetes', 'kivet', 'befizetes', 'zaro', 'szamolt', 'elteres', 'forgalom', 'tetel_db', 'lezarva'])) : null,
            'tetelek' => $tetelek,
        ];
    }

    /** @return array<string, mixed> */
    public static function attekintes(): array
    {
        global $wpdb;

        $t       = self::tabla('munkalap');
        $nyitott = SDH_Muhely_Munkalap::nyitott_kulcsok(SDH_Muhely_Munkalap::allapotok());
        $ma      = current_time('Y-m-d');
        $ki      = ['ma' => $ma, 'nyitott_munkalap' => SDH_Muhely_Munkalap::nyitott_db()];

        if ($nyitott !== []) {
            $in = implode(',', array_fill(0, count($nyitott), '%s'));

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $ki['lejart_hataridos']  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE allapot IN ({$in}) AND hatarido IS NOT NULL AND hatarido < %s", array_merge($nyitott, [$ma])));
            $ki['ma_lejaro']         = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE allapot IN ({$in}) AND hatarido = %s", array_merge($nyitott, [$ma])));
            // phpcs:enable
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ki['ma_felvett']   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE letrehozva >= %s", $ma . ' 00:00:00'));
        $ki['fizetetlen']   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE fizetve = 0 AND brutto_ertek > 0 AND munkalap_szam IS NOT NULL");
        // phpcs:enable

        if (class_exists('SDH_Muhely_Rma') && method_exists('SDH_Muhely_Rma', 'olvasatlan_db')) {
            $ki['olvasatlan_ugyfeluzenet'] = SDH_Muhely_Rma::olvasatlan_db();
        }

        $ki['olvasatlan_csapatuzenet'] = SDH_Muhely_Csapat::olvasatlan_db();

        if (class_exists('SDH_Muhely_Penztar')) {
            $nap = SDH_Muhely_Penztar::nap($ma);
            $ki['penztar_ma'] = $nap ? array_intersect_key((array) $nap, array_flip(['allapot', 'nyito', 'kp_be', 'kartya', 'utalas', 'zaro', 'forgalom', 'tetel_db'])) : 'Ma még nincs tétel.';
        }

        return $ki;
    }

    /** @return array<int, array<string, mixed>> */
    private static function csapat_olvasatlan(): array
    {
        global $wpdb;

        $en  = get_current_user_id();
        $idk = SDH_Muhely_Csapat::elerheto($en);

        if ($idk === []) {
            return [];
        }

        $u   = self::tabla('csapat_uzenet');
        $tag = self::tabla('csapat_tag');
        $in  = implode(',', array_map('intval', $idk));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT u.id, u.felado_id, u.szoveg, u.fontos, u.letrehozva FROM {$u} u
             LEFT JOIN {$tag} t ON t.beszelgetes_id = u.beszelgetes_id AND t.user_id = %d
             WHERE u.beszelgetes_id IN ({$in}) AND u.torolve = 0 AND u.felado_id <> %d AND u.id > COALESCE(t.olvasott_id, 0)
             ORDER BY u.id DESC LIMIT 15",
            $en,
            $en
        ));
        // phpcs:enable

        return array_map(static fn ($s) => [
            'kitol' => SDH_Muhely_Munkalap::felelos_nev((int) $s->felado_id), 'szoveg' => $s->szoveg, 'fontos' => (bool) $s->fontos, 'mikor' => $s->letrehozva,
        ], (array) $sorok);
    }

    /** @return array<int, array<string, mixed>> */
    private static function naplo(int $limit): array
    {
        global $wpdb;

        $t = self::tabla('ai_muvelet');
        $sorok = $wpdb->get_results($wpdb->prepare("SELECT id, eszkoz, leiras, allapot, letrehozva, dontes_ido, eredmeny FROM {$t} ORDER BY id DESC LIMIT %d", $limit)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map(static fn ($s) => (array) $s, (array) $sorok);
    }

    /* =================================================================
     * Módosítás: javaslat (előkészítés) és végrehajtás
     * ============================================================== */

    /**
     * Előkészítés: ellenőrzi a paramétereket, és leírja, mi fog változni.
     *
     * @param array<string, mixed> $p
     * @return array{leiras: string, valtozasok: array<int, array{mezo: string, regi: string, uj: string}>, tabla: string, rekord_id: int, parameterek: array<string, mixed>}|string
     */
    public static function elokeszit(string $nev, array $p)
    {
        global $wpdb;

        switch ($nev) {
            case 'munkalap_megjegyzes':
                $ml = self::munkalap_keres((string) ($p['munkalap'] ?? ''));
                $szoveg = trim(sanitize_textarea_field((string) ($p['szoveg'] ?? '')));

                if ($ml === null) {
                    return 'Nincs ilyen munkalap.';
                }

                if ($szoveg === '') {
                    return 'Üres a megjegyzés.';
                }

                return [
                    'leiras'     => 'Megjegyzés hozzáfűzése a(z) ' . SDH_Muhely_Munkalap::szam_formaz($ml->munkalap_szam) . ' munkalaphoz',
                    'valtozasok' => [['mezo' => 'Belső megjegyzés (hozzáfűzés)', 'regi' => '', 'uj' => $szoveg]],
                    'tabla'      => 'munkalap', 'rekord_id' => (int) $ml->id,
                    'parameterek' => ['id' => (int) $ml->id, 'szoveg' => mb_substr($szoveg, 0, 2000)],
                ];

            case 'munkalap_allapot':
                $ml = self::munkalap_keres((string) ($p['munkalap'] ?? ''));
                $k  = sanitize_key((string) ($p['allapot'] ?? ''));

                if ($ml === null) {
                    return 'Nincs ilyen munkalap.';
                }

                if (!isset(SDH_Muhely_Munkalap::allapotok()[$k])) {
                    return 'Ismeretlen állapot: ' . $k;
                }

                if ((string) $ml->allapot === $k) {
                    return 'A munkalap már ebben az állapotban van.';
                }

                return [
                    'leiras'     => 'A(z) ' . SDH_Muhely_Munkalap::szam_formaz($ml->munkalap_szam) . ' munkalap állapotának átírása',
                    'valtozasok' => [['mezo' => 'Állapot', 'regi' => self::allapot_nev((string) $ml->allapot), 'uj' => self::allapot_nev($k)]],
                    'tabla'      => 'munkalap', 'rekord_id' => (int) $ml->id,
                    'parameterek' => ['id' => (int) $ml->id, 'allapot' => $k],
                ];

            case 'munkalap_hatarido':
                $ml = self::munkalap_keres((string) ($p['munkalap'] ?? ''));
                $d  = trim((string) ($p['datum'] ?? ''));

                if ($ml === null) {
                    return 'Nincs ilyen munkalap.';
                }

                if ($d !== '' && !self::datum_ok($d)) {
                    return 'A dátum ÉÉÉÉ-HH-NN formában kell.';
                }

                return [
                    'leiras'     => 'A(z) ' . SDH_Muhely_Munkalap::szam_formaz($ml->munkalap_szam) . ' munkalap határidejének átírása',
                    'valtozasok' => [['mezo' => 'Határidő', 'regi' => (string) ($ml->hatarido ?? '—'), 'uj' => $d !== '' ? $d : '— (nincs)']],
                    'tabla'      => 'munkalap', 'rekord_id' => (int) $ml->id,
                    'parameterek' => ['id' => (int) $ml->id, 'datum' => $d],
                ];

            case 'ugyfel_modositas':
                $id = (int) ($p['ugyfel_id'] ?? 0);
                $u  = self::sor('ugyfel', $id);
                $mezok = is_array($p['mezok'] ?? null) ? $p['mezok'] : [];

                if ($u === null) {
                    return 'Nincs ilyen ügyfél.';
                }

                $valt = [];
                $uj   = [];

                foreach ($mezok as $m => $ertek) {
                    $m = (string) $m;

                    if (!in_array($m, self::UGYFEL_MEZOK, true)) {
                        return 'Ez a mező nem írható át: ' . $m;
                    }

                    $ertek = in_array($m, ['megjegyzes', 'belso_megjegyzes'], true)
                        ? sanitize_textarea_field((string) $ertek)
                        : sanitize_text_field((string) $ertek);

                    if ($m === 'email' && $ertek !== '' && !is_email($ertek)) {
                        return 'Érvénytelen e-mail-cím: ' . $ertek;
                    }

                    if ((string) $u[$m] === $ertek) {
                        continue;
                    }

                    $uj[$m] = $ertek;
                    $valt[] = ['mezo' => $m, 'regi' => (string) $u[$m], 'uj' => $ertek];
                }

                if ($uj === []) {
                    return 'Nincs változás – az ügyfél adatai már ezek.';
                }

                return [
                    'leiras'     => 'Ügyfél adatainak átírása: ' . (string) $u['nev'],
                    'valtozasok' => $valt,
                    'tabla'      => 'ugyfel', 'rekord_id' => $id,
                    'parameterek' => ['id' => $id, 'mezok' => $uj],
                ];

            case 'szolgaltatas_ar':
                $id = (int) ($p['szolgaltatas_id'] ?? 0);
                $s  = self::sor('szolgaltatas', $id);
                $ar = round((float) ($p['brutto_ar'] ?? -1), 2);

                if ($s === null) {
                    return 'Nincs ilyen szolgáltatás.';
                }

                if ($ar < 0 || $ar > 100000000) {
                    return 'Érvénytelen ár.';
                }

                return [
                    'leiras'     => 'Szolgáltatás árának átírása: ' . (string) $s['nev'],
                    'valtozasok' => [['mezo' => 'Bruttó ár', 'regi' => self::penz($s['brutto_ar']), 'uj' => self::penz($ar)]],
                    'tabla'      => 'szolgaltatas', 'rekord_id' => $id,
                    'parameterek' => ['id' => $id, 'ar' => $ar],
                ];

            case 'csapat_uzenet':
                $cimzettek = self::cimzettek((array) ($p['cimzettek'] ?? []));
                $szoveg = trim(sanitize_textarea_field((string) ($p['szoveg'] ?? '')));

                if (is_string($cimzettek)) {
                    return $cimzettek;
                }

                if ($szoveg === '') {
                    return 'Üres az üzenet.';
                }

                $hiv = '';

                if (!empty($p['munkalap'])) {
                    $ml = self::munkalap_keres((string) $p['munkalap']);
                    $hiv = $ml ? 'munkalap:' . (int) $ml->id : '';
                }

                return [
                    'leiras'     => 'Csapatüzenet küldése a nevedben' . (!empty($p['fontos']) ? ' (FONTOS – felugró ablakban)' : ''),
                    'valtozasok' => [['mezo' => 'Címzett', 'regi' => '', 'uj' => implode(', ', $cimzettek['nevek'])], ['mezo' => 'Üzenet', 'regi' => '', 'uj' => $szoveg]],
                    'tabla'      => 'csapat_uzenet', 'rekord_id' => 0,
                    'parameterek' => ['cimzett' => $cimzettek['kodok'], 'szoveg' => mb_substr($szoveg, 0, 4000), 'fontos' => !empty($p['fontos']), 'hivatkozas' => $hiv],
                ];

            case 'tudas_mentes':
                $cim = trim(sanitize_text_field((string) ($p['cim'] ?? '')));
                $szoveg = trim(sanitize_textarea_field((string) ($p['szoveg'] ?? '')));

                if ($cim === '' || $szoveg === '') {
                    return 'A tudáshoz cím és szöveg kell.';
                }

                return [
                    'leiras'     => 'Új tudás a tudástárba',
                    'valtozasok' => [['mezo' => 'Cím', 'regi' => '', 'uj' => $cim], ['mezo' => 'Tartalom', 'regi' => '', 'uj' => $szoveg]],
                    'tabla'      => 'ai_tudas', 'rekord_id' => 0,
                    'parameterek' => ['cim' => $cim, 'szoveg' => $szoveg, 'kulcsszavak' => sanitize_text_field((string) ($p['kulcsszavak'] ?? ''))],
                ];

            case 'tudas_torles':
                $id = (int) ($p['tudas_id'] ?? 0);
                $s  = self::sor('ai_tudas', $id);

                if ($s === null || !(int) $s['aktiv']) {
                    return 'Nincs ilyen (aktív) tudástár-sor.';
                }

                return [
                    'leiras'     => 'Tudás kivétele a tudástárból: ' . (string) $s['cim'],
                    'valtozasok' => [['mezo' => 'Állapot', 'regi' => 'aktív', 'uj' => 'kikapcsolva (visszaállítható)']],
                    'tabla'      => 'ai_tudas', 'rekord_id' => $id,
                    'parameterek' => ['id' => $id],
                ];
        }

        return 'Ismeretlen művelet.';
    }

    /**
     * Címzettek nevekből: kolléga neve (részlet is), „csoport:Név", „mindenki".
     *
     * @param array<int, mixed> $nevek
     * @return array{kodok: array<int, string>, nevek: array<int, string>}|string
     */
    private static function cimzettek(array $nevek)
    {
        $norm = static fn (string $s): string => strtolower(remove_accents(trim($s)));
        $kollegak = SDH_Muhely_Csapat::kollegak();
        $csoportok = SDH_Muhely_Csapat::csoportok();
        $kodok = [];
        $ki = [];

        foreach ($nevek as $n) {
            $n = (string) $n;
            $nn = $norm($n);

            if ($nn === '') {
                continue;
            }

            if (in_array($nn, ['mindenki', 'mindenkinek', 'all'], true)) {
                return ['kodok' => ['mindenki'], 'nevek' => ['Mindenki']];
            }

            $csop = preg_replace('/^(csoport|group)\s*:\s*/', '', $nn);
            $talalt = false;

            foreach ($csoportok as $cs) {
                if ($norm((string) $cs->nev) === $csop) {
                    $kodok[] = 'cs:' . (int) $cs->id;
                    $ki[] = (string) $cs->nev . ' (csoport)';
                    $talalt = true;
                    break;
                }
            }

            if ($talalt) {
                continue;
            }

            $jelolt = [];

            foreach ($kollegak as $k) {
                $kn = $norm($k['nev']);

                if ($kn === $nn) {
                    $jelolt = [$k];
                    break;
                }

                if (strpos($kn, $nn) !== false || strpos($norm($k['login']), $nn) !== false) {
                    $jelolt[] = $k;
                }
            }

            if (count($jelolt) !== 1) {
                return count($jelolt) === 0
                    ? 'Nem találok ilyen kollégát vagy csoportot: ' . $n
                    : 'Több kolléga is illik erre: ' . $n . ' (' . implode(', ', array_column($jelolt, 'nev')) . ') – pontosítsd.';
            }

            $kodok[] = 'u:' . $jelolt[0]['id'];
            $ki[] = $jelolt[0]['nev'];
        }

        if ($kodok === []) {
            return 'Nincs címzett.';
        }

        return ['kodok' => array_values(array_unique($kodok)), 'nevek' => array_values(array_unique($ki))];
    }

    /**
     * Végrehajtás (a jóváhagyás után). Az előtte-mentést a hívó készíti.
     *
     * @param array<string, mixed> $p A javaslat parameterek mezője.
     * @return array{eredmeny: string, rekord_id: int, tabla: string}|string
     */
    public static function vegrehajt(string $nev, array $p, int $felhasznalo)
    {
        global $wpdb;

        $most = current_time('mysql');

        switch ($nev) {
            case 'munkalap_megjegyzes':
                $s = self::sor('munkalap', (int) $p['id']);

                if ($s === null) {
                    return 'A munkalap már nem létezik.';
                }

                $nev_ki = SDH_Muhely_Munkalap::felelos_nev($felhasznalo);
                $uj = trim((string) $s['megjegyzes'] . "\n[" . wp_date('Y.m.d H:i') . ' – ' . $nev_ki . ', AI-asszisztenssel] ' . (string) $p['szoveg']);

                $wpdb->update(self::tabla('munkalap'), ['megjegyzes' => $uj, 'modositva' => $most], ['id' => (int) $p['id']]);

                return ['eredmeny' => 'A megjegyzés a munkalapra került.', 'rekord_id' => (int) $p['id'], 'tabla' => 'munkalap'];

            case 'munkalap_allapot':
                $r = SDH_Muhely_Munkalap::allapot_valt((int) $p['id'], (string) $p['allapot']);

                if (is_string($r)) {
                    return $r;
                }

                return ['eredmeny' => 'A(z) ' . $r['szam'] . ' munkalap új állapota: ' . $r['nev'] . '.', 'rekord_id' => (int) $p['id'], 'tabla' => 'munkalap'];

            case 'munkalap_hatarido':
                $wpdb->update(self::tabla('munkalap'), ['hatarido' => $p['datum'] !== '' ? (string) $p['datum'] : null, 'modositva' => $most], ['id' => (int) $p['id']]);

                return ['eredmeny' => 'A határidő átírva: ' . ($p['datum'] !== '' ? $p['datum'] : 'nincs határidő') . '.', 'rekord_id' => (int) $p['id'], 'tabla' => 'munkalap'];

            case 'ugyfel_modositas':
                $adat = [];

                foreach ((array) $p['mezok'] as $m => $v) {
                    if (in_array((string) $m, self::UGYFEL_MEZOK, true)) {
                        $adat[(string) $m] = (string) $v;
                    }
                }

                $adat['modositva'] = $most;
                $wpdb->update(self::tabla('ugyfel'), $adat, ['id' => (int) $p['id']]);

                return ['eredmeny' => 'Az ügyfél adatai átírva.', 'rekord_id' => (int) $p['id'], 'tabla' => 'ugyfel'];

            case 'szolgaltatas_ar':
                $wpdb->update(self::tabla('szolgaltatas'), ['brutto_ar' => (float) $p['ar'], 'modositva' => $most], ['id' => (int) $p['id']]);

                return ['eredmeny' => 'Az ár átírva: ' . self::penz($p['ar']) . '.', 'rekord_id' => (int) $p['id'], 'tabla' => 'szolgaltatas'];

            case 'csapat_uzenet':
                $besz = SDH_Muhely_Csapat::besz_cimzettekbol((array) $p['cimzett'], $felhasznalo);

                if (is_string($besz)) {
                    return $besz;
                }

                $uz = SDH_Muhely_Csapat::kuld($besz, $felhasznalo, (string) $p['szoveg'], [
                    'fontos' => !empty($p['fontos']), 'hivatkozas' => (string) ($p['hivatkozas'] ?? ''), 'forras' => 'ai',
                ]);

                if (is_string($uz)) {
                    return $uz;
                }

                return ['eredmeny' => 'Az üzenet elment.', 'rekord_id' => (int) $uz['id'], 'tabla' => 'csapat_uzenet'];

            case 'tudas_mentes':
                $id = SDH_Muhely_Asszisztens_Tudas::ment((string) $p['cim'], (string) $p['szoveg'], (string) $p['kulcsszavak'], 'ai');

                return $id > 0 ? ['eredmeny' => 'Megtanultam: ' . $p['cim'], 'rekord_id' => $id, 'tabla' => 'ai_tudas'] : 'A mentés nem sikerült.';

            case 'tudas_torles':
                $wpdb->update(SDH_Muhely_Asszisztens_Tudas::tabla(), ['aktiv' => 0, 'modositva' => $most], ['id' => (int) $p['id']]);

                return ['eredmeny' => 'A tudás kikapcsolva (visszaállítható).', 'rekord_id' => (int) $p['id'], 'tabla' => 'ai_tudas'];
        }

        return 'Ismeretlen művelet.';
    }

    /** A rekord jelenlegi állapota (mentéshez / összevetéshez). */
    public static function pillanatkep(string $tabla, int $id): ?array
    {
        if ($tabla === '' || $id <= 0) {
            return null;
        }

        return self::sor($tabla, $id);
    }

    /**
     * Visszaállítás: a módosított sor visszakapja az előtte-állapotot; az új
     * sor (üzenet, tudás) visszavonódik / kikapcsolódik.
     *
     * @param array<string, mixed>|null $elotte
     */
    public static function visszaallit(string $eszkoz, string $tabla, int $id, ?array $elotte): string
    {
        global $wpdb;

        if ($elotte === null) {
            // Új rekord volt: jelöléssel vonjuk vissza.
            if ($tabla === 'csapat_uzenet') {
                $wpdb->update(self::tabla('csapat_uzenet'), ['torolve' => 1, 'kituzve' => 0, 'modositva' => current_time('mysql')], ['id' => $id]);

                return 'Az üzenet visszavonva.';
            }

            if ($tabla === 'ai_tudas') {
                $wpdb->update(SDH_Muhely_Asszisztens_Tudas::tabla(), ['aktiv' => 0], ['id' => $id]);

                return 'A tudás kikapcsolva.';
            }

            return 'Ehhez a művelethez nincs visszaállítás.';
        }

        $adat = $elotte;
        unset($adat['id']);

        $r = $wpdb->update(self::tabla($tabla), $adat, ['id' => $id]);

        if ($r === false) {
            return 'Az adatbázis nem fogadta el a visszaállítást.';
        }

        if ($tabla === 'munkalap' && $eszkoz === 'munkalap_allapot' && isset($elotte['allapot'])) {
            do_action('sdh_muhely_munkalap_allapot', $id, '', (string) $elotte['allapot']);
        }

        return 'Visszaállítva a művelet előtti állapotra.';
    }
}
