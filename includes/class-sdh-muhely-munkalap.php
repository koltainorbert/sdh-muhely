<?php
/**
 * Munkalap-modul.
 *
 * A munkalap egy ügyfél egy eszközéhez tartozó javítási ügy: állapota,
 * felelőse, határideje van, alatta pedig hibasorok, mindegyik saját
 * állapottal. Ugyanúgy működik, mint az ügyfél- és az eszközmodul:
 * lista kereséssel és lapozással, felvitel és szerkesztés popupban,
 * JavaScript nélkül teljes oldalas tartalékkal.
 *
 * Három dolog itt szerviz-specifikus, ezért mind beállítás, semmi sem
 * ég a kódban (a Beállítások képernyőn szerkeszthető):
 *   - a munkalap állapotai (név, szín, számozott-e, lezárt-e),
 *   - a hibasorok állapotai,
 *   - a számozás (következő szám, előtag, kitöltés).
 *
 * Miért nincs törlés: a munkalapszám bizonylat-azonosító. Ha egy lap
 * eltűnne, lyuk maradna a számozásban. Az érvénytelen lap az
 * "Érvénytelen" állapotba kerül, de megmarad.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Munkalap
{
    /** A modul kulcsa az URL-ekben, a menüben és az AJAX-műveletekben. */
    public const KULCS = 'munkalapok';

    /** Hány sor egy oldalon. */
    private const OLDAL_MERET = 25;

    /** A beállítások option-kulcsai. */
    private const OPT_ALLAPOTOK      = 'sdh_muhely_munkalap_allapotok';
    private const OPT_HIBA_ALLAPOTOK = 'sdh_muhely_munkalap_hiba_allapotok';
    private const OPT_SZAMOZAS       = 'sdh_muhely_munkalap_szamozas';
    private const OPT_FIZMODOK       = 'sdh_muhely_fizetesi_modok';
    private const OPT_GYAKORI_HIBAK  = 'sdh_muhely_gyakori_hibak';

    /** Legfeljebb ennyi gyakori hiba tárolódik (a választó popup lapozás nélkül mutatja). */
    private const GYAKORI_HIBA_MAX = 60;

    /** A számkiosztás zárjának neve (MySQL GET_LOCK). */
    private const ZAR_NEV = 'sdh_muhely_munkalap_szam';

    /** Jelzi, hogy az új alapszínekre (Lezárt = zöld) az átállás megtörtént. */
    private const OPT_SZIN_ATALLAS = 'sdh_muhely_allapot_szin_v2';

    /**
     * A feldolgozott állapotlisták gyorsítótára egy kérésen belül.
     *
     * @var array<string, array<string, array{nev: string, szin: string, szamozott: bool, zart: bool}>>
     */
    private static array $gyorsitotar = [];

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Munkalapok',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 30,
        ]);

        // Teljes oldalas űrlap beküldése (tartalék, JS nélkül is működik).
        add_action('admin_post_sdh_muhely_munkalap_mentes', [self::class, 'mentes']);

        // Popup: az űrlap lekérése és beküldése.
        add_action('wp_ajax_sdh_muhely_munkalapok_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_munkalapok_ment', [self::class, 'ajax_mentes']);

        // Állapotváltás közvetlenül a listából.
        add_action('wp_ajax_sdh_muhely_munkalapok_allapot', [self::class, 'ajax_allapot']);

        // Több eszközös lap: egy eszköz saját állapota, és a „fizetve" jelzőjének visszavonása.
        add_action('wp_ajax_sdh_muhely_munkalapok_eszkoz_allapot', [self::class, 'ajax_eszkoz_allapot']);
        add_action('wp_ajax_sdh_muhely_munkalapok_eszkoz_fizetve', [self::class, 'ajax_eszkoz_fizetve']);

        // Az ügyfél kiválasztása után az eszközválasztó ebből töltődik.
        add_action('wp_ajax_sdh_muhely_munkalapok_eszkozok', [self::class, 'ajax_eszkozok']);

        // Új gyakori hiba felvétele a munkalap Hibák lapfüléről.
        add_action('wp_ajax_sdh_muhely_munkalapok_gyakori_hiba', [self::class, 'ajax_gyakori_hiba']);

        // Az űrlap Eszköz lapfüle (az eszköz és az ügyfél összefoglalója).
        add_action('wp_ajax_sdh_muhely_munkalapok_osszefoglalo', [self::class, 'ajax_osszefoglalo']);

        // Sémafrissítés után: a régi lezárt lapok lezárási dátumának pótlása.
        add_action('sdh_muhely_sema_frissult', [self::class, 'lezarva_potlas']);

        // …és a „Részben elkészült" közös állapot a mentett állapotlistába.
        add_action('sdh_muhely_sema_frissult', [self::class, 'reszben_potlas'], 5);

        // …és a régi kifizetett lapokon a „Fizetett" a teljes összeg legyen (egyszer fut).
        add_action('sdh_muhely_sema_frissult', [self::class, 'fizetett_potlas'], 40);
    }

    /* =================================================================
     * Lezárás dátuma
     * ============================================================== */

    /**
     * A „Lezárva" oszlop értéke állapotváltáskor.
     *
     * Lezárt állapotba lépéskor a mai nap; ha a lap már lezárt volt (pl.
     * Lezártból Érvénytelenbe kerül), az eredeti nap marad. Nem lezárt
     * állapotban a mező üres – az újranyitott lap nem viseli a régi dátumot.
     */
    private static function lezarva_ertek(string $uj_allapot, ?object $regi): ?string
    {
        $allapotok = self::allapotok();

        if (empty($allapotok[$uj_allapot]['zart'])) {
            return null;
        }

        $regi_datum = $regi !== null ? self::datum_ertek((string) ($regi->lezarva ?? '')) : '';
        $regi_zart  = $regi !== null && !empty($allapotok[(string) $regi->allapot]['zart']);

        return $regi_zart && $regi_datum !== '' ? $regi_datum : current_time('Y-m-d');
    }

    /**
     * A 0.15.0 előtti lezárt lapokon nincs lezárási dátum: az utolsó
     * módosítás napját kapják. Újrafuttatható, kitöltött mezőhöz nem nyúl.
     */
    public static function lezarva_potlas(): void
    {
        global $wpdb;

        $zart = self::zart_kulcsok(self::allapotok());

        if ($zart === []) {
            return;
        }

        $tabla = self::tabla();

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- csak helyőrzők kerülnek bele.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$tabla}
                 SET lezarva = SUBSTRING(COALESCE(modositva, letrehozva), 1, 10)
                 WHERE lezarva IS NULL
                   AND COALESCE(modositva, letrehozva) IS NOT NULL
                   AND allapot IN (" . self::in_helyorzo($zart) . ')',
                $zart
            )
        );
        // phpcs:enable
    }

    /* =================================================================
     * Táblák, lekérdezések
     * ============================================================== */

    private static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('munkalap');
    }

    private static function hiba_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('munkalap_hiba');
    }

    private static function tobbi_eszkoz_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('munkalap_eszkoz');
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    private static function url(array $parameterek = []): string
    {
        return SDH_Muhely_Modulok::url(self::KULCS, $parameterek);
    }

    /* =================================================================
     * Több eszköz egy munkalapon
     *
     * A fő eszköz a munkalap `eszkoz_id` mezője; a többi a munkalap_eszkoz
     * táblában áll, sorrendben. Darabszám-korlát nincs.
     * ============================================================== */

    /**
     * A munkalap TOVÁBBI eszközeinek azonosítói (a fő eszköz nélkül), sorrendben.
     *
     * @return array<int, int>
     */
    public static function tovabbi_eszkoz_idk(int $munkalap_id): array
    {
        global $wpdb;

        if ($munkalap_id <= 0) {
            return [];
        }

        return array_map(
            'intval',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT eszkoz_id FROM ' . self::tobbi_eszkoz_tabla() . ' WHERE munkalap_id = %d ORDER BY sorrend ASC, id ASC',
                    $munkalap_id
                )
            )
        );
    }

    /**
     * A munkalap további eszközeinek kapcsolósorai (eszkoz_id, bev_dij,
     * bev_nev, bev_szolg, fizetett, allapot, fizetve, fizetes_ideje),
     * sorrendben – az eszközönkénti bevizsgálási díjhoz, a külön
     * befizetéshez, a saját állapothoz és a saját „fizetve" jelzőhöz.
     *
     * @return array<int, object>
     */
    public static function tovabbi_reszletek(int $munkalap_id): array
    {
        global $wpdb;

        if ($munkalap_id <= 0) {
            return [];
        }

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                // Minden oszlop: így frissítés után, a sémafrissítés előtti első kérésnél sem hibázik
                // (az új oszlopokat a hívók `?? ''` / `?? 0` alapértékkel olvassák).
                'SELECT * FROM ' . self::tobbi_eszkoz_tabla()
                . ' WHERE munkalap_id = %d ORDER BY sorrend ASC, id ASC',
                $munkalap_id
            )
        );

        return is_array($sorok) ? $sorok : [];
    }

    /**
     * A beküldött eszközönkénti bevizsgálási díjak a megadott (már
     * ellenőrzött) csatolt eszközökhöz.
     *
     * @param array<int, int> $idk
     * @return array<int, array{dij: float, nev: string, szolg: int}> eszköz-azonosító => díj
     */
    private static function tovabbi_dijak_osszeallit(array $idk): array
    {
        $tomb = static fn (string $mezo): array => isset($_POST[$mezo]) && is_array($_POST[$mezo])
            ? wp_unslash($_POST[$mezo])
            : [];

        $dij   = $tomb('tovabbi_dij');
        $nev   = $tomb('tovabbi_dij_nev');
        $szolg = $tomb('tovabbi_dij_szolg');
        $ki    = [];

        foreach ($idk as $id) {
            $osszeg = max(0.0, round(SDH_Muhely_Tetel::szam((string) ($dij[$id] ?? '0')), 2));

            $ki[$id] = [
                'dij'   => $osszeg,
                // Díj nélkül a díjlistából választott név sem marad meg.
                'nev'   => $osszeg > 0 ? mb_substr(sanitize_text_field((string) ($nev[$id] ?? '')), 0, 255) : '',
                'szolg' => $osszeg > 0 ? max(0, (int) ($szolg[$id] ?? 0)) : 0,
            ];
        }

        return $ki;
    }

    /**
     * Eszközsorok a megadott azonosítókhoz, a megadott sorrendben.
     *
     * @param array<int, int> $idk
     * @return array<int, object>
     */
    public static function eszkoz_sorok(array $idk): array
    {
        global $wpdb;

        $idk = array_values(array_unique(array_filter(array_map('intval', $idk), static fn (int $id): bool => $id > 0)));

        if ($idk === []) {
            return [];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- csak helyőrzők kerülnek bele.
        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' WHERE id IN (' . self::in_helyorzo($idk, '%d') . ')',
                $idk
            )
        );
        // phpcs:enable

        $szerint = [];

        foreach ((array) $sorok as $sor) {
            $szerint[(int) $sor->id] = $sor;
        }

        $ki = [];

        foreach ($idk as $id) {
            if (isset($szerint[$id])) {
                $ki[] = $szerint[$id];
            }
        }

        return $ki;
    }

    /**
     * A munkalap MINDEN eszköze: elöl a fő eszköz, utána a továbbiak.
     *
     * @return array<int, object>
     */
    public static function eszkozok(object $munkalap): array
    {
        return self::eszkoz_sorok(
            array_merge([(int) $munkalap->eszkoz_id], self::tovabbi_eszkoz_idk((int) $munkalap->id))
        );
    }

    /**
     * A munkalap eszközönkénti bontása: minden eszköz önálló ügy a lapon
     * belül – saját hibák, saját tételek (szolgáltatás, termék, díj), saját
     * pénzügy. Ebből él a részletpanel, az ügyféloldal (RMA), a számla és a
     * pénztár.
     *
     * Elemenként: `eszkoz_id` (0 = a fő eszköz; a hibák és tételek ezzel
     * hivatkoznak rá), `valodi_id`, `eszkoz` (sor vagy null), `sorszam`,
     * `nev` (megnevezés), `felirat` („2. Apple A1660"), `hibak`, `tetelek`,
     * `netto`, `brutto` (a kimenő tételek összege), `dij` (bevizsgálási díj),
     * `eloleg` (az eszközre már befizetett rész), `fizetendo`, `allapot`
     * (az eszköz saját állapotának kulcsa), `kesz` (elkészült vagy lezárt),
     * `fizetve`, `fizetes_ideje`.
     *
     * Az előleg felosztása: a csatolt eszközé a díja + amit külön befizettek
     * rá; a fő eszközé a lap előlegének maradéka. Kifizetett lapon – és a
     * külön kifizetett eszköznél – a fizetendő nulla.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function eszkoz_bontas(object $munkalap): array
    {
        $id        = (int) $munkalap->id;
        $jelolo    = (int) ($munkalap->bevizsgalasi_dij ?? 0) === 1;
        $fizetve   = (int) ($munkalap->fizetve ?? 0) === 1;
        $reszletek = [];

        foreach (self::tovabbi_reszletek($id) as $r) {
            $reszletek[(int) $r->eszkoz_id] = $r;
        }

        $eszkozok = self::eszkoz_sorok(array_merge([(int) $munkalap->eszkoz_id], array_keys($reszletek)));
        $bontas   = [];
        $n        = 0;

        // A fő eszköz mindig az első elem – akkor is, ha a lapon még nincs eszköz.
        $fo = null;

        foreach ($eszkozok as $e) {
            if ((int) $e->id === (int) $munkalap->eszkoz_id) {
                $fo = $e;
            }
        }

        $sorrend = array_merge([[0, $fo]], array_map(
            static fn (object $e): array => [(int) $e->id, $e],
            array_values(array_filter($eszkozok, static fn (object $e): bool => isset($reszletek[(int) $e->id])))
        ));

        foreach ($sorrend as [$kulcs, $e]) {
            $n++;
            $nev = $e ? SDH_Muhely_Eszkoz::megnevezes($e) : 'Eszköz nélkül';

            $bontas[$kulcs] = [
                'eszkoz_id' => $kulcs,
                'valodi_id' => $e ? (int) $e->id : 0,
                'eszkoz'    => $e,
                'sorszam'   => $n,
                'nev'       => $nev,
                'felirat'   => $n . '. ' . $nev,
                'hibak'     => [],
                'tetelek'   => [],
                'netto'     => 0.0,
                'brutto'    => 0.0,
                'dij'       => 0.0,
                'eloleg'    => 0.0,
                'fizetendo' => 0.0,
                'allapot'   => (string) $munkalap->allapot,
                'kesz'      => false,
                'fizetve'   => $fizetve,
                'fizetes_ideje' => $fizetve ? self::datum_ertek((string) ($munkalap->fizetes_ideje ?? '')) : '',
            ];
        }

        // Saját állapot és saját „fizetve" eszközönként (egy eszköznél a lapé).
        $allapotok   = self::allapotok();
        $sajat       = self::eszkoz_allapotok($munkalap, array_values($reszletek));
        $tobb_eszkoz = $reszletek !== [];

        foreach ($bontas as $kulcs => $b) {
            $a = (string) ($sajat[$kulcs] ?? $munkalap->allapot);

            $bontas[$kulcs]['allapot'] = $a;
            $bontas[$kulcs]['kesz']    = !empty($allapotok[$a]['kesz']) || (!empty($allapotok[$a]['zart']) && empty($allapotok[$a]['ervenytelen']));

            if ($fizetve || !$tobb_eszkoz) {
                continue;
            }

            $jel = $kulcs === 0 ? (int) ($munkalap->fo_fizetve ?? 0) === 1 : (int) ($reszletek[$kulcs]->fizetve ?? 0) === 1;
            $nap = $kulcs === 0 ? (string) ($munkalap->fo_fizetes_ideje ?? '') : (string) ($reszletek[$kulcs]->fizetes_ideje ?? '');

            $bontas[$kulcs]['fizetve']       = $jel;
            $bontas[$kulcs]['fizetes_ideje'] = $jel ? self::datum_ertek($nap) : '';
        }

        // Ami olyan eszközre mutat, amely már nincs a lapon, az a fő eszközé.
        foreach (self::hibasorok($id) as $hiba) {
            $kulcs = isset($bontas[(int) ($hiba->eszkoz_id ?? 0)]) ? (int) $hiba->eszkoz_id : 0;

            $bontas[$kulcs]['hibak'][] = $hiba;
        }

        foreach (SDH_Muhely_Tetel::lista($id) as $tetel) {
            $kulcs = isset($bontas[(int) ($tetel->eszkoz_id ?? 0)]) ? (int) $tetel->eszkoz_id : 0;

            $bontas[$kulcs]['tetelek'][] = $tetel;

            if ((string) $tetel->mozgas === 'kimeno') {
                $bontas[$kulcs]['netto']  += (float) $tetel->netto_ertek;
                $bontas[$kulcs]['brutto'] += (float) $tetel->brutto_ertek;
            }

            if ((string) $tetel->forras === 'bevizsgalas') {
                $bontas[$kulcs]['dij'] += (float) $tetel->brutto_ertek;
            }
        }

        $csatolt_eloleg = 0.0;

        foreach ($reszletek as $kulcs => $r) {
            if (!isset($bontas[$kulcs])) {
                continue;
            }

            $bontas[$kulcs]['eloleg'] = round(($jelolo ? (float) $r->bev_dij : 0.0) + (float) ($r->fizetett ?? 0), 2);
            $csatolt_eloleg          += $bontas[$kulcs]['eloleg'];
        }

        $bontas[0]['eloleg'] = round(max(0.0, (float) $munkalap->fizetett - $csatolt_eloleg), 2);

        foreach ($bontas as $kulcs => $b) {
            $bontas[$kulcs]['netto']     = round($b['netto'], 2);
            $bontas[$kulcs]['brutto']    = round($b['brutto'], 2);
            $bontas[$kulcs]['fizetendo'] = $b['fizetve'] ? 0.0 : round($b['brutto'] - $bontas[$kulcs]['eloleg'], 2);
        }

        return array_values($bontas);
    }

    /**
     * Hány TOVÁBBI eszköz van a megadott munkalapokon (a rács „+2" jelzéséhez),
     * egy lekérdezéssel.
     *
     * @param array<int, int> $munkalap_idk
     * @return array<int, int> munkalap-azonosító => darab
     */
    public static function tovabbi_eszkoz_db(array $munkalap_idk): array
    {
        global $wpdb;

        $munkalap_idk = array_values(array_filter(array_map('intval', $munkalap_idk)));

        if ($munkalap_idk === []) {
            return [];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- csak helyőrzők kerülnek bele.
        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT munkalap_id, COUNT(*) AS db FROM ' . self::tobbi_eszkoz_tabla()
                . ' WHERE munkalap_id IN (' . self::in_helyorzo($munkalap_idk, '%d') . ') GROUP BY munkalap_id',
                $munkalap_idk
            )
        );
        // phpcs:enable

        $ki = [];

        foreach ((array) $sorok as $sor) {
            $ki[(int) $sor->munkalap_id] = (int) $sor->db;
        }

        return $ki;
    }

    /** Az eszköz felirata a választókban: megnevezés · IMEI (vagy sorozatszám). */
    public static function eszkoz_felirat(object $eszkoz): string
    {
        $felirat = SDH_Muhely_Eszkoz::megnevezes($eszkoz);

        if ((string) $eszkoz->imei !== '') {
            $felirat .= ' · ' . $eszkoz->imei;
        } elseif ((string) $eszkoz->sorozatszam !== '') {
            $felirat .= ' · ' . $eszkoz->sorozatszam;
        }

        return $felirat;
    }

    /**
     * A beküldött további eszközök: csak létező, ugyanahhoz az ügyfélhez
     * tartozó készülék maradhat, a fő eszköz és az ismétlődés kimarad.
     *
     * @return array<int, int>
     */
    private static function tovabbi_osszeallit(int $ugyfel_id, int $fo_eszkoz): array
    {
        global $wpdb;

        $nyers = isset($_POST['tovabbi_eszkoz']) && is_array($_POST['tovabbi_eszkoz'])
            ? array_map('intval', wp_unslash($_POST['tovabbi_eszkoz']))
            : [];

        $idk = [];

        foreach ($nyers as $id) {
            if ($id > 0 && $id !== $fo_eszkoz && !in_array($id, $idk, true)) {
                $idk[] = $id;
            }
        }

        if ($idk === [] || $ugyfel_id <= 0) {
            return [];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- csak helyőrzők kerülnek bele.
        $sajat = array_map(
            'intval',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT id FROM ' . SDH_Muhely_Schema::tabla('eszkoz')
                    . ' WHERE ugyfel_id = %d AND id IN (' . self::in_helyorzo($idk, '%d') . ')',
                    array_merge([$ugyfel_id], $idk)
                )
            )
        );
        // phpcs:enable

        return array_values(array_filter($idk, static fn (int $id): bool => in_array($id, $sajat, true)));
    }

    /**
     * A munkalap további eszközeinek szinkronizálása a megadott listával:
     * ami új, bekerül; ami hiányzik, lekerül; a sorrend a listáé.
     *
     * @param array<int, int> $idk
     * @param array<int, array{dij: float, nev: string, szolg: int}>|null $dijak
     *        Eszközönkénti bevizsgálási díj; null = a díjakhoz nem nyúlunk.
     */
    public static function tovabbi_ment(int $munkalap_id, array $idk, ?array $dijak = null): void
    {
        global $wpdb;

        if ($munkalap_id <= 0) {
            return;
        }

        $tabla = self::tobbi_eszkoz_tabla();
        $regi  = self::tovabbi_eszkoz_idk($munkalap_id);

        foreach (array_diff($regi, $idk) as $torlendo) {
            $wpdb->delete($tabla, ['munkalap_id' => $munkalap_id, 'eszkoz_id' => (int) $torlendo]);
        }

        foreach (array_values($idk) as $sorrend => $eszkoz_id) {
            $sor = ['sorrend' => $sorrend];

            if ($dijak !== null) {
                $sor['bev_dij']   = (float) ($dijak[$eszkoz_id]['dij'] ?? 0);
                $sor['bev_nev']   = (string) ($dijak[$eszkoz_id]['nev'] ?? '');
                $sor['bev_szolg'] = (int) ($dijak[$eszkoz_id]['szolg'] ?? 0);
            }

            if (in_array($eszkoz_id, $regi, true)) {
                $wpdb->update($tabla, $sor, ['munkalap_id' => $munkalap_id, 'eszkoz_id' => $eszkoz_id]);

                continue;
            }

            $wpdb->insert($tabla, $sor + [
                'munkalap_id' => $munkalap_id,
                'eszkoz_id'   => $eszkoz_id,
                'letrehozva'  => current_time('mysql'),
            ]);
        }
    }

    private static function egy(int $id): ?object
    {
        global $wpdb;

        $sor = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id)
        );

        return $sor ?: null;
    }

    /**
     * Egy munkalap hibasorai, sorrendben.
     *
     * @return array<int, object>
     */
    public static function hibasorok(int $munkalap_id): array
    {
        global $wpdb;

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::hiba_tabla() . ' WHERE munkalap_id = %d ORDER BY sorrend ASC, id ASC',
                $munkalap_id
            )
        );

        return is_array($sorok) ? $sorok : [];
    }

    /**
     * `IN (%s, %s…)` helyőrzők egy értéklistához.
     *
     * @param array<int, mixed> $ertekek
     */
    private static function in_helyorzo(array $ertekek, string $tipus = '%s'): string
    {
        return implode(', ', array_fill(0, count($ertekek), $tipus));
    }

    /**
     * Hány munkalap nyitott (számozott, de még nem lezárt állapotú).
     *
     * Az áttekintő kártya használja.
     */
    public static function nyitott_db(): int
    {
        global $wpdb;

        $nyitott = self::nyitott_kulcsok(self::allapotok());

        if ($nyitott === []) {
            return 0;
        }

        $tabla = self::tabla();
        $sql   = "SELECT COUNT(*) FROM {$tabla} WHERE munkalap_szam IS NOT NULL AND allapot IN ("
            . self::in_helyorzo($nyitott) . ')';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- csak helyőrzők kerülnek bele.
        return (int) $wpdb->get_var($wpdb->prepare($sql, $nyitott));
        // phpcs:enable
    }

    /* =================================================================
     * Állapotok (beállításból)
     * ============================================================== */

    /**
     * A választható színek nevei. Maguk a színek a CSS-ben vannak
     * (`--sdh-allapot-<név>` az assets/admin.css tetején) – a PHP csak
     * a nevüket ismeri, így az átszínezés egy fájlból megy.
     *
     * @return array<string, string>
     */
    public static function szinek(): array
    {
        return [
            'sarga'    => 'Sárga',
            'zold'     => 'Zöld',
            'szurke'   => 'Szürke',
            'kek'      => 'Kék',
            'narancs'  => 'Narancs',
            'olajzold' => 'Olajzöld',
            'lila'     => 'Lila',
            'piros'    => 'Piros',
        ];
    }

    /**
     * A MunkaLap 3 állapotai, ahogy a régi programban vannak.
     */
    public static function alap_allapotok_szovegkent(): string
    {
        return implode("\n", [
            'bejelentett | Bejelentett | sarga | szamozott',
            'arajanlat | Árajánlat | lila |',
            'sablon | Sablon | szurke |',
            'fuggo | Függő | kek | szamozott',
            'nyitott | Nyitott | narancs | szamozott, alap',
            'elkeszult | Elkészült | olajzold | szamozott, kesz',
            'reszben | Részben elkészült | olajzold | szamozott, reszben',
            'lezart | Lezárt | zold | szamozott, zart',
            'ervenytelen | Érvénytelen | piros | szamozott, zart, ervenytelen',
        ]);
    }

    public static function alap_hiba_allapotok_szovegkent(): string
    {
        return implode("\n", [
            'uj | Új | sarga |',
            'folyamatban | Folyamatban | kek |',
            'kesz | Kész | olajzold | zart',
            'nem_javithato | Nem javítható | piros | zart',
        ]);
    }

    /**
     * Állapotlista szövegből tömb.
     *
     * Soronként egy állapot: `kulcs | Név | szín | jelzők`. A jelzők
     * (vesszővel elválasztva): `szamozott` – a lap munkalapszámot kap;
     * `zart` – a lap lezárt, nem számít nyitottnak; `alap` – az új lap
     * ezzel indul; `kesz` – az eszköz elkészült (átvehető); `reszben` – a
     * több eszközös lap közös állapota, amikor az egyik eszköz kész, a
     * másik még nem; `ervenytelen` – az ilyen eszköz nem számít bele a
     * közös állapotba.
     *
     * Hibás vagy üres bemenetnél a megadott alapértelmezés jön vissza,
     * hogy elgépelt beállítás ne tegye használhatatlanná a modult.
     *
     * @return array<string, array{nev: string, szin: string, szamozott: bool, zart: bool}>
     */
    public static function allapotok_feldolgoz(string $szoveg, string $alap): array
    {
        $lista = [];

        foreach (preg_split('/\R/u', $szoveg) ?: [] as $sor) {
            $reszek = array_map('trim', explode('|', $sor));

            if (count($reszek) < 2) {
                continue;
            }

            $kulcs = sanitize_key($reszek[0]);
            $nev   = sanitize_text_field($reszek[1]);

            if ($kulcs === '' || $nev === '' || isset($lista[$kulcs])) {
                continue;
            }

            $szin    = sanitize_key($reszek[2] ?? '');
            $jelzok  = array_map('trim', explode(',', strtolower($reszek[3] ?? '')));

            $lista[$kulcs] = [
                'nev'       => $nev,
                'szin'      => isset(self::szinek()[$szin]) ? $szin : 'szurke',
                'szamozott' => in_array('szamozott', $jelzok, true),
                'zart'      => in_array('zart', $jelzok, true),
                'alap'      => in_array('alap', $jelzok, true),
                'kesz'      => in_array('kesz', $jelzok, true),
                'reszben'   => in_array('reszben', $jelzok, true),
                'ervenytelen' => in_array('ervenytelen', $jelzok, true),
            ];
        }

        if ($lista === [] && $szoveg !== $alap) {
            return self::allapotok_feldolgoz($alap, $alap);
        }

        return $lista;
    }

    /**
     * A munkalap állapotai.
     *
     * @return array<string, array{nev: string, szin: string, szamozott: bool, zart: bool}>
     */
    public static function allapotok(): array
    {
        if (!isset(self::$gyorsitotar['munkalap'])) {
            $mentett = self::szin_atallas(get_option(self::OPT_ALLAPOTOK, ''));
            $alap    = self::alap_allapotok_szovegkent();

            self::$gyorsitotar['munkalap'] = self::allapotok_kiegeszit(self::allapotok_feldolgoz(
                is_string($mentett) && trim($mentett) !== '' ? $mentett : $alap,
                $alap
            ));
        }

        return self::$gyorsitotar['munkalap'];
    }

    /**
     * A több eszközös laphoz kellő jelzők pótlása a régebben mentett
     * állapotlistában: ha nincs `kesz` jelzős állapot, az `elkeszult` kulcsú
     * az; ha nincs `ervenytelen` jelzős, az `ervenytelen` kulcsú; ha nincs
     * `reszben` jelzős, a `reszben` kulcsú – és ha az sincs, bekerül a
     * „Részben elkészült" (a kész állapot után), hogy a közös állapotnak
     * mindig legyen neve.
     *
     * @param array<string, array<string, mixed>> $lista
     * @return array<string, array<string, mixed>>
     */
    private static function allapotok_kiegeszit(array $lista): array
    {
        $van = static function (string $jelzo) use (&$lista): bool {
            foreach ($lista as $a) {
                if (!empty($a[$jelzo])) {
                    return true;
                }
            }

            return false;
        };

        if (!$van('kesz') && isset($lista['elkeszult'])) {
            $lista['elkeszult']['kesz'] = true;
        }

        if (!$van('ervenytelen') && isset($lista['ervenytelen'])) {
            $lista['ervenytelen']['ervenytelen'] = true;
        }

        if ($van('reszben')) {
            return $lista;
        }

        if (isset($lista['reszben'])) {
            $lista['reszben']['reszben'] = true;

            return $lista;
        }

        $uj = [
            'nev' => 'Részben elkészült', 'szin' => 'olajzold', 'szamozott' => true, 'zart' => false,
            'alap' => false, 'kesz' => false, 'reszben' => true, 'ervenytelen' => false,
        ];
        $ki      = [];
        $betette = false;

        foreach ($lista as $kulcs => $a) {
            $ki[$kulcs] = $a;

            if (!$betette && !empty($a['kesz'])) {
                $ki['reszben'] = $uj;
                $betette       = true;
            }
        }

        if (!$betette) {
            $ki['reszben'] = $uj;
        }

        return $ki;
    }

    /** A „Részben elkészült" (a több eszközös lap közös állapota) kulcsa. */
    public static function reszben_kulcs(): string
    {
        foreach (self::allapotok() as $kulcs => $a) {
            if (!empty($a['reszben'])) {
                return (string) $kulcs;
            }
        }

        return 'reszben';
    }

    /**
     * Sémafrissítéskor: a mentett (szerkesztett) állapotlistába bekerül a
     * „Részben elkészült" sora, hogy a Beállításokban átnevezhető és
     * átszínezhető legyen. Újrafuttatható; az alaplistához nem nyúl.
     */
    public static function reszben_potlas(): void
    {
        $mentett = get_option(self::OPT_ALLAPOTOK, '');

        if (!is_string($mentett) || trim($mentett) === '') {
            return;
        }

        $lista = self::allapotok_feldolgoz($mentett, self::alap_allapotok_szovegkent());

        foreach ($lista as $kulcs => $a) {
            if (!empty($a['reszben']) || (string) $kulcs === 'reszben') {
                return;
            }
        }

        $sor   = 'reszben | Részben elkészült | olajzold | szamozott, reszben';
        $sorok = preg_split('/\R/u', trim($mentett)) ?: [];
        $ki    = [];
        $kesz  = false;

        foreach ($sorok as $egy) {
            $ki[] = $egy;

            $reszek = array_map('trim', explode('|', $egy));
            $jelzok = array_map('trim', explode(',', strtolower($reszek[3] ?? '')));

            if (!$kesz && (sanitize_key($reszek[0] ?? '') === 'elkeszult' || in_array('kesz', $jelzok, true))) {
                $ki[] = $sor;
                $kesz = true;
            }
        }

        if (!$kesz) {
            $ki[] = $sor;
        }

        update_option(self::OPT_ALLAPOTOK, implode("\n", $ki));
        unset(self::$gyorsitotar['munkalap']);
    }

    /**
     * Egyszeri átállás az új alapszínekre: a Lezárt zöld, az Árajánlat lila.
     *
     * A korábbi alap a Lezárt = lila, Árajánlat = zöld volt. Ha a mentett
     * beállítás még ezt tartalmazza (azaz senki nem szerkesztette a színeket
     * ezekben a sorokban), átírjuk; más szín nem változik. Egyszer fut.
     *
     * @param mixed $mentett A mentett állapotlista szövege.
     * @return mixed
     */
    private static function szin_atallas($mentett)
    {
        if (get_option(self::OPT_SZIN_ATALLAS) === '1') {
            return $mentett;
        }

        if (is_string($mentett) && trim($mentett) !== '') {
            $uj = preg_replace_callback(
                '/^(\s*)(lezart|arajanlat)(\s*\|[^|\r\n]*\|\s*)(lila|zold)(\s*(?:\||$))/mu',
                static function (array $m): string {
                    $szin = $m[4];

                    if ($m[2] === 'lezart' && $szin === 'lila') {
                        $szin = 'zold';
                    } elseif ($m[2] === 'arajanlat' && $szin === 'zold') {
                        $szin = 'lila';
                    }

                    return $m[1] . $m[2] . $m[3] . $szin . $m[5];
                },
                $mentett
            );

            if (is_string($uj) && $uj !== $mentett) {
                update_option(self::OPT_ALLAPOTOK, $uj);
                $mentett = $uj;
            }
        }

        update_option(self::OPT_SZIN_ATALLAS, '1');

        return $mentett;
    }

    /**
     * A hibasorok állapotai.
     *
     * @return array<string, array{nev: string, szin: string, szamozott: bool, zart: bool}>
     */
    public static function hiba_allapotok(): array
    {
        if (!isset(self::$gyorsitotar['hiba'])) {
            $mentett = get_option(self::OPT_HIBA_ALLAPOTOK, '');
            $alap    = self::alap_hiba_allapotok_szovegkent();

            self::$gyorsitotar['hiba'] = self::allapotok_feldolgoz(
                is_string($mentett) && trim($mentett) !== '' ? $mentett : $alap,
                $alap
            );
        }

        return self::$gyorsitotar['hiba'];
    }

    /**
     * @param array<string, array{nev: string, szin: string, szamozott: bool, zart: bool}> $lista
     * @return array<int, string>
     */
    public static function zart_kulcsok(array $lista): array
    {
        $kulcsok = [];

        foreach ($lista as $kulcs => $allapot) {
            if ($allapot['zart']) {
                $kulcsok[] = (string) $kulcs;
            }
        }

        return $kulcsok;
    }

    /**
     * A nyitott állapotok: számozottak (valódi munkalapok), de nem lezártak.
     *
     * @param array<string, array{nev: string, szin: string, szamozott: bool, zart: bool}> $lista
     * @return array<int, string>
     */
    public static function nyitott_kulcsok(array $lista): array
    {
        $kulcsok = [];

        foreach ($lista as $kulcs => $allapot) {
            if ($allapot['szamozott'] && !$allapot['zart']) {
                $kulcsok[] = (string) $kulcs;
            }
        }

        return $kulcsok;
    }

    /**
     * Az új munkalap alapértelmezett állapota.
     *
     * Sorrend: az `alap` jelzővel ellátott állapot; ha a mentett
     * beállításban még nincs ilyen (a jelző későbbi), a `nyitott` kulcsú;
     * végül az első számozott.
     *
     * @param array<string, array{nev: string, szin: string, szamozott: bool, zart: bool}> $lista
     */
    public static function alap_kulcs(array $lista): string
    {
        foreach ($lista as $kulcs => $allapot) {
            if (!empty($allapot['alap'])) {
                return (string) $kulcs;
            }
        }

        if (isset($lista['nyitott'])) {
            return 'nyitott';
        }

        foreach ($lista as $kulcs => $allapot) {
            if ($allapot['szamozott']) {
                return (string) $kulcs;
            }
        }

        return (string) (array_key_first($lista) ?? 'bejelentett');
    }

    /**
     * Állapotjelvény (színes pötty + név).
     *
     * Ismeretlen kulcsnál – például törölt állapotnál – szürke jelvény
     * jelenik meg a nyers kulccsal, hogy a régi lap ne tűnjön el.
     *
     * @param array<string, array{nev: string, szin: string, szamozott: bool, zart: bool}> $lista
     */
    public static function jelveny(string $kulcs, array $lista): string
    {
        $szin = $lista[$kulcs]['szin'] ?? 'szurke';
        $nev  = $lista[$kulcs]['nev'] ?? $kulcs;

        return sprintf(
            '<span class="sdh-allapot sdh-allapot--%s">%s</span>',
            esc_attr($szin),
            esc_html($nev)
        );
    }

    /* =================================================================
     * Fizetési módok (beállításból)
     * ============================================================== */

    /** A MunkaLap 3 fizetési módjai – ez az alap, a Beállításokban átírható. */
    public static function alap_fizetesi_modok_szovegkent(): string
    {
        return implode("\n", [
            'Átutalás',
            'Bankkártya',
            'Barion',
            'Előre utalás',
            'Halasztott KP',
            'Készpénz',
            'Kombinált',
            'Kompenzáció szerint',
            'PayPal',
            'SZÉP Kártya',
            'Utalvány',
            'Utánvét',
        ]);
    }

    /**
     * A választható fizetési módok, a beállítás sorrendjében. A munkalapon a
     * mód neve tárolódik, ezért egy átnevezés a régi lapokat nem írja át.
     *
     * @return array<int, string>
     */
    public static function fizetesi_modok(): array
    {
        $mentett = get_option(self::OPT_FIZMODOK, '');
        $szoveg  = is_string($mentett) && trim($mentett) !== ''
            ? $mentett
            : self::alap_fizetesi_modok_szovegkent();

        $lista = [];

        foreach (preg_split('/\R/u', $szoveg) ?: [] as $sor) {
            $nev = mb_substr(sanitize_text_field($sor), 0, 40);

            if ($nev !== '' && !in_array($nev, $lista, true)) {
                $lista[] = $nev;
            }
        }

        return $lista;
    }

    /* =================================================================
     * Gyakori hibák (beállításból)
     * ============================================================== */

    /**
     * A húsz leggyakoribb készülékhiba – ez az alap, a Beállításokban
     * átírható, és a munkalap Hibák lapfüléről is bővíthető. Általános
     * szervizes lista, cégfüggő adat nincs benne.
     */
    public static function alap_gyakori_hibak_szovegkent(): string
    {
        return implode("\n", [
            'Törött kijelző',
            'Érintés nem működik',
            'Nem kapcsol be',
            'Nem tölt',
            'Gyorsan merül az akkumulátor',
            'Töltőcsatlakozó hibás',
            'Beázott',
            'Törött hátlap',
            'Kamera nem működik',
            'Törött kameraüveg',
            'Hangszóró nem szól',
            'Mikrofon nem működik',
            'Nincs térerő',
            'Wi-Fi / Bluetooth nem működik',
            'Bekapcsológomb hibás',
            'Hangerőgomb hibás',
            'Újraindul / lefagy',
            'Szoftverhiba, frissítés',
            'Melegszik',
            'Adatmentés / adatátvitel',
        ]);
    }

    /**
     * Hibalista szövegből tömb: soronként egy hiba, üres és ismétlődő sor nélkül.
     *
     * @return array<int, string>
     */
    private static function gyakori_hibak_feldolgoz(string $szoveg): array
    {
        $lista = [];
        $volt  = [];

        foreach (preg_split('/\R/u', $szoveg) ?: [] as $sor) {
            $nev   = mb_substr(sanitize_text_field($sor), 0, 255);
            $kulcs = mb_strtolower($nev);

            if ($nev !== '' && !isset($volt[$kulcs])) {
                $volt[$kulcs] = true;
                $lista[]      = $nev;
            }
        }

        return array_slice($lista, 0, self::GYAKORI_HIBA_MAX);
    }

    /**
     * A munkalap Hibák lapfülén egy kattintással felvehető hibák, a
     * beállítás sorrendjében.
     *
     * @return array<int, string>
     */
    public static function gyakori_hibak(): array
    {
        $mentett = get_option(self::OPT_GYAKORI_HIBAK, '');

        return self::gyakori_hibak_feldolgoz(
            is_string($mentett) && trim($mentett) !== '' ? $mentett : self::alap_gyakori_hibak_szovegkent()
        );
    }

    /**
     * Új gyakori hiba a lista végére. Visszaadja a teljes listát, vagy
     * hibaüzenetet (üres név, betelt lista).
     *
     * @return array<int, string>|string
     */
    public static function gyakori_hiba_felvesz(string $nev)
    {
        $nev = mb_substr(sanitize_text_field($nev), 0, 255);

        if ($nev === '') {
            return 'Írd be a hiba nevét.';
        }

        $lista = self::gyakori_hibak();

        foreach ($lista as $van) {
            if (mb_strtolower($van) === mb_strtolower($nev)) {
                return $lista; // Már szerepel: nincs teendő.
            }
        }

        if (count($lista) >= self::GYAKORI_HIBA_MAX) {
            return 'A gyakori hibák listája betelt (' . self::GYAKORI_HIBA_MAX . ' db). A Beállításokban törölhetsz belőle.';
        }

        $lista[] = $nev;
        update_option(self::OPT_GYAKORI_HIBAK, implode("\n", $lista));

        return $lista;
    }

    /** Új gyakori hiba felvétele a munkalap Hibák lapfüléről (AJAX). */
    public static function ajax_gyakori_hiba(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $eredmeny = self::gyakori_hiba_felvesz(
            isset($_POST['nev']) ? (string) wp_unslash($_POST['nev']) : ''
        );

        if (is_string($eredmeny)) {
            wp_send_json_error(['uzenet' => $eredmeny]);
        }

        wp_send_json_success(['lista' => $eredmeny]);
    }

    /* =================================================================
     * Számozás
     * ============================================================== */

    /**
     * @return array{kovetkezo: int, elotag: string, szamjegy: int}
     */
    public static function szamozas(): array
    {
        $mentett = get_option(self::OPT_SZAMOZAS, []);
        $mentett = is_array($mentett) ? $mentett : [];

        return [
            'kovetkezo' => max(1, (int) ($mentett['kovetkezo'] ?? 1)),
            'elotag'    => isset($mentett['elotag']) ? (string) $mentett['elotag'] : '',
            'szamjegy'  => min(10, max(0, (int) ($mentett['szamjegy'] ?? 0))),
        ];
    }

    /**
     * A következő kiadandó munkalapszám.
     *
     * A beállított kezdőérték és a ténylegesen kiadott legnagyobb szám
     * +1-ének nagyobbika: a kezdőérték csak előre ugrathatja a
     * számozást, vissza nem – így nem keletkezhet ütközés.
     */
    public static function kovetkezo_szam(): int
    {
        global $wpdb;

        $legnagyobb = (int) $wpdb->get_var('SELECT MAX(munkalap_szam) FROM ' . self::tabla());

        return max(self::szamozas()['kovetkezo'], $legnagyobb + 1);
    }

    /**
     * A megjelenített munkalapszám: előtag + kitöltött szám.
     *
     * @param int|string|null $szam
     */
    public static function szam_formaz($szam): string
    {
        $szam = (int) $szam;

        if ($szam <= 0) {
            return '—';
        }

        $b      = self::szamozas();
        $szoveg = (string) $szam;

        if ($b['szamjegy'] > 0) {
            $szoveg = str_pad($szoveg, $b['szamjegy'], '0', STR_PAD_LEFT);
        }

        return $b['elotag'] . $szoveg;
    }

    /* =================================================================
     * Beállítások (a Beállítások képernyő hívja)
     * ============================================================== */

    /**
     * A munkalap-beállítások dobozai a Beállítások űrlapjában.
     */
    public static function beallitas_dobozok(): void
    {
        $b = self::szamozas();

        $allapotok = get_option(self::OPT_ALLAPOTOK, '');
        $allapotok = is_string($allapotok) && trim($allapotok) !== ''
            ? $allapotok
            : self::alap_allapotok_szovegkent();

        $hiba_allapotok = get_option(self::OPT_HIBA_ALLAPOTOK, '');
        $hiba_allapotok = is_string($hiba_allapotok) && trim($hiba_allapotok) !== ''
            ? $hiba_allapotok
            : self::alap_hiba_allapotok_szovegkent();

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Munkalap-számozás</h2>

            <p class="sdh-sugo">
                A munkalapszám az első számozott állapotba lépéskor generálódik. A következő
                kiadandó szám most: <strong><?php
                    echo esc_html(self::szam_formaz(self::kovetkezo_szam()));
                ?></strong>. A kezdőérték csak előre ugrathatja a számozást: ha a táblában már
                nagyobb szám van, az számít.
            </p>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="mu_kovetkezo">Következő szám (legalább)</label>
                    <input type="number" name="mu_kovetkezo" id="mu_kovetkezo" min="1" step="1"
                           value="<?php echo esc_attr((string) $b['kovetkezo']); ?>">
                    <span class="sdh-mezo__sugo">
                        A MunkaLap 3-ból való átvétel után ezt állítsd az utolsó átvett szám +1-re.
                    </span>
                </div>

                <div class="sdh-mezo">
                    <label for="mu_elotag">Előtag</label>
                    <input type="text" name="mu_elotag" id="mu_elotag" maxlength="10"
                           value="<?php echo esc_attr($b['elotag']); ?>">
                    <span class="sdh-mezo__sugo">Pl. <code>M-</code>. Üresen hagyva csak a szám látszik.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="mu_szamjegy">Kitöltés (számjegyek)</label>
                    <input type="number" name="mu_szamjegy" id="mu_szamjegy" min="0" max="10" step="1"
                           value="<?php echo esc_attr((string) $b['szamjegy']); ?>">
                    <span class="sdh-mezo__sugo">0 = nincs kitöltés. 6-nál a 34983 így lesz: 034983.</span>
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Munkalap-állapotok</h2>

            <p class="sdh-sugo">
                Soronként egy állapot: <code>kulcs | Név | szín | jelzők</code>. A kulcs az
                adatbázisban tárolt azonosító – amint használatban van, ne írd át, csak a nevét.
                Színek: <?php echo esc_html(implode(', ', array_keys(self::szinek()))); ?>.
                Jelzők (vesszővel): <code>szamozott</code> – a lap munkalapszámot kap;
                <code>zart</code> – a lap lezárt, nem számít nyitottnak;
                <code>alap</code> – új munkalapnál ez az állapot van előre kiválasztva
                (több <code>alap</code> esetén az első). A sorrend a lista sorrendje.
            </p>

            <p class="sdh-sugo">
                Több eszközös munkalaphoz: <code>kesz</code> – az eszköz elkészült, átvehető;
                <code>reszben</code> – a lap közös állapota, amikor az egyik eszköz már kész, a másik
                még nem (magától áll be, kézzel nem választható); <code>ervenytelen</code> – az ilyen
                állapotú eszköz nem számít bele a közös állapotba. Ha a közös állapot nem
                egyértelmű, a listában előrébb álló állapot érvényesül.
            </p>

            <div class="sdh-mezo sdh-mezo--szeles">
                <label for="mu_allapotok">Állapotok</label>
                <textarea name="mu_allapotok" id="mu_allapotok" rows="9"
                          class="sdh-kod"><?php echo esc_textarea($allapotok); ?></textarea>
                <span class="sdh-mezo__sugo">
                    Üresen hagyva a MunkaLap 3 nyolc állapota jön vissza.
                </span>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Fizetési módok</h2>

            <p class="sdh-sugo">
                Soronként egy fizetési mód – ezek közül lehet választani a munkalapon
                (a „Nincs” mindig választható). A munkalapon a mód neve tárolódik, ezért
                egy átnevezés a régi lapokat nem írja át.
            </p>

            <div class="sdh-mezo sdh-mezo--szeles">
                <label for="mu_fizetesi_modok">Fizetési módok</label>
                <textarea name="mu_fizetesi_modok" id="mu_fizetesi_modok" rows="8"
                          class="sdh-kod"><?php
                    $fizmodok = get_option(self::OPT_FIZMODOK, '');
                    echo esc_textarea(
                        is_string($fizmodok) && trim($fizmodok) !== ''
                            ? $fizmodok
                            : self::alap_fizetesi_modok_szovegkent()
                    );
                ?></textarea>
                <span class="sdh-mezo__sugo">Üresen hagyva a MunkaLap 3 listája jön vissza.</span>
            </div>
        </div>

        <div class="sdh-doboz" id="gyakori-hibak">
            <h2 class="sdh-doboz__cim">Gyakori hibák</h2>

            <p class="sdh-sugo">
                Soronként egy hiba – a munkalap Hibák lapfülén a „Gyakori hibák” gombbal egy
                kattintással felvehetők. A sorrend a lista sorrendje; a kollégák a munkalapról
                is bővíthetik (a lista végére kerül). Legfeljebb
                <?php echo (int) self::GYAKORI_HIBA_MAX; ?> hiba fér bele.
            </p>

            <div class="sdh-mezo sdh-mezo--szeles">
                <label for="mu_gyakori_hibak">Gyakori hibák</label>
                <textarea name="mu_gyakori_hibak" id="mu_gyakori_hibak" rows="12"
                          class="sdh-kod"><?php echo esc_textarea(implode("\n", self::gyakori_hibak())); ?></textarea>
                <span class="sdh-mezo__sugo">Üresen hagyva a húsz leggyakoribb készülékhiba jön vissza.</span>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Hibasor-állapotok</h2>

            <p class="sdh-sugo">
                Ugyanez a formátum, a hibasorok saját állapotlistájához. A <code>szamozott</code>
                jelzőnek itt nincs jelentése.
            </p>

            <div class="sdh-mezo sdh-mezo--szeles">
                <label for="mu_hiba_allapotok">Hibasor-állapotok</label>
                <textarea name="mu_hiba_allapotok" id="mu_hiba_allapotok" rows="5"
                          class="sdh-kod"><?php echo esc_textarea($hiba_allapotok); ?></textarea>
            </div>
        </div>
        <?php
    }

    /**
     * A Beállítások űrlapjának mentése (a nonce-t és a jogot a hívó
     * már ellenőrizte).
     */
    public static function beallitas_mentes(): void
    {
        $szam = static fn (string $mezo, int $alap): int => isset($_POST[$mezo])
            ? (int) wp_unslash($_POST[$mezo])
            : $alap;

        update_option(
            self::OPT_SZAMOZAS,
            [
                'kovetkezo' => max(1, $szam('mu_kovetkezo', 1)),
                'elotag'    => isset($_POST['mu_elotag'])
                    ? mb_substr(sanitize_text_field(wp_unslash($_POST['mu_elotag'])), 0, 10)
                    : '',
                'szamjegy'  => min(10, max(0, $szam('mu_szamjegy', 0))),
            ]
        );

        update_option(
            self::OPT_ALLAPOTOK,
            isset($_POST['mu_allapotok'])
                ? sanitize_textarea_field(wp_unslash($_POST['mu_allapotok']))
                : ''
        );

        update_option(
            self::OPT_HIBA_ALLAPOTOK,
            isset($_POST['mu_hiba_allapotok'])
                ? sanitize_textarea_field(wp_unslash($_POST['mu_hiba_allapotok']))
                : ''
        );

        update_option(
            self::OPT_FIZMODOK,
            isset($_POST['mu_fizetesi_modok'])
                ? sanitize_textarea_field(wp_unslash($_POST['mu_fizetesi_modok']))
                : ''
        );

        if (isset($_POST['mu_gyakori_hibak'])) {
            update_option(
                self::OPT_GYAKORI_HIBAK,
                implode("\n", self::gyakori_hibak_feldolgoz(sanitize_textarea_field(wp_unslash($_POST['mu_gyakori_hibak']))))
            );
        }

        self::$gyorsitotar = [];
    }

    /* =================================================================
     * Felelősök, eszközök
     * ============================================================== */

    /**
     * Akik felelősek lehetnek: a rendszert használni jogosult felhasználók.
     *
     * @return array<int, string> azonosító => név
     */
    public static function felelosok(): array
    {
        $felhasznalok = get_users([
            'capability' => SDH_Muhely_Admin_UI::jog(),
            'orderby'    => 'display_name',
            'order'      => 'ASC',
            'fields'     => ['ID', 'display_name'],
        ]);

        $lista = [];

        foreach ($felhasznalok as $felhasznalo) {
            $lista[(int) $felhasznalo->ID] = (string) $felhasznalo->display_name;
        }

        return $lista;
    }

    public static function felelos_nev(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $felhasznalo = get_userdata($id);

        return $felhasznalo ? (string) $felhasznalo->display_name : '';
    }

    /**
     * Egy ügyfél eszközei az eszközválasztóhoz.
     *
     * A már kiválasztott eszköz akkor is szerepel, ha közben inaktív lett,
     * hogy egy régi lap szerkesztése ne tegye üressé a mezőt.
     *
     * @return array<int, string> azonosító => felirat
     */
    private static function ugyfel_eszkozei(int $ugyfel_id, int $kivalasztott = 0): array
    {
        global $wpdb;

        if ($ugyfel_id <= 0) {
            return [];
        }

        $tabla = SDH_Muhely_Schema::tabla('eszkoz');

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tabla}
                 WHERE ugyfel_id = %d AND (aktiv = 1 OR id = %d)
                 ORDER BY modositva DESC, id DESC",
                $ugyfel_id,
                $kivalasztott
            )
        );

        $lista = [];

        foreach ((array) $sorok as $sor) {
            $lista[(int) $sor->id] = self::eszkoz_felirat($sor);
        }

        return $lista;
    }

    /** Az eszközválasztó tartalma az ügyfél kiválasztása után. */
    public static function ajax_eszkozok(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $ugyfel_id = isset($_GET['ugyfel_id']) ? (int) $_GET['ugyfel_id'] : 0;
        $talalatok = [];

        foreach (self::ugyfel_eszkozei($ugyfel_id) as $id => $felirat) {
            $talalatok[] = ['id' => $id, 'nev' => $felirat];
        }

        wp_send_json_success($talalatok);
    }

    /* =================================================================
     * Útválasztás
     * ============================================================== */

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        $nezet = isset($_GET['nezet']) ? sanitize_key(wp_unslash($_GET['nezet'])) : 'lista';

        if ($nezet === 'uj' || $nezet === 'szerkeszt') {
            self::urlap_oldal($nezet);

            return;
        }

        self::lista_oldal();
    }

    /* =================================================================
     * Lista
     * ============================================================== */

    private static function lista_oldal(): void
    {
        global $wpdb;

        $kereses   = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
        $szuro     = isset($_GET['allapot']) ? sanitize_key(wp_unslash($_GET['allapot'])) : '';
        $ugyfel_id = isset($_GET['ugyfel_id']) ? (int) $_GET['ugyfel_id'] : 0;
        $eszkoz_id = isset($_GET['eszkoz_id']) ? (int) $_GET['eszkoz_id'] : 0;
        $oldal     = isset($_GET['oldalszam']) ? max(1, (int) $_GET['oldalszam']) : 1;
        $eltolas   = ($oldal - 1) * self::OLDAL_MERET;

        $allapotok = self::allapotok();
        $nyitott   = self::nyitott_kulcsok($allapotok);

        $tabla        = self::tabla();
        $ugyfel_tabla = SDH_Muhely_Schema::tabla('ugyfel');
        $eszkoz_tabla = SDH_Muhely_Schema::tabla('eszkoz');

        // A feltételek és az értékek párhuzamosan épülnek, hogy a
        // helyőrzők sorrendje biztosan egyezzen.
        $feltetelek = ['1 = 1'];
        $ertekek    = [];

        if ($szuro === 'mind') {
            // Nincs állapotszűrés.
        } elseif ($szuro !== '' && isset($allapotok[$szuro])) {
            $feltetelek[] = 'm.allapot = %s';
            $ertekek[]    = $szuro;
        } elseif ($nyitott !== []) {
            // Alapnézet: a nyitott munkalapok. A Sablon és az Árajánlat
            // nem munkalap, azokat az állapotszűrő külön mutatja.
            $feltetelek[] = 'm.allapot IN (' . self::in_helyorzo($nyitott) . ')';
            $ertekek      = array_merge($ertekek, $nyitott);
        }

        if ($ugyfel_id > 0) {
            $feltetelek[] = 'm.ugyfel_id = %d';
            $ertekek[]    = $ugyfel_id;
        }

        $tobbi_tabla = self::tobbi_eszkoz_tabla();

        if ($eszkoz_id > 0) {
            // Az eszköz akkor is ehhez a laphoz tartozik, ha nem a fő, hanem csatolt eszköze.
            $feltetelek[] = "(m.eszkoz_id = %d OR EXISTS (SELECT 1 FROM {$tobbi_tabla} tk WHERE tk.munkalap_id = m.id AND tk.eszkoz_id = %d))";
            $ertekek[]    = $eszkoz_id;
            $ertekek[]    = $eszkoz_id;
        }

        if ($kereses !== '') {
            $minta  = '%' . $wpdb->esc_like($kereses) . '%';
            $reszek = [
                'm.nev LIKE %s',
                'u.nev LIKE %s',
                'u.telefon LIKE %s',
                'e.imei LIKE %s',
                'e.sorozatszam LIKE %s',
                'e.gyarto LIKE %s',
                'e.tipus LIKE %s',
                'e.megnevezes LIKE %s',
                // A csatolt (további) eszközök IMEI-je, sorozatszáma és típusa is találat.
                "EXISTS (SELECT 1 FROM {$tobbi_tabla} tk INNER JOIN {$eszkoz_tabla} te2 ON te2.id = tk.eszkoz_id
                         WHERE tk.munkalap_id = m.id
                           AND (te2.imei LIKE %s OR te2.imei2 LIKE %s OR te2.sorozatszam LIKE %s OR te2.tipus LIKE %s OR te2.megnevezes LIKE %s))",
            ];
            // Az utolsó (EXISTS) rész öt helyőrzőt tartalmaz.
            $par    = array_fill(0, count($reszek) + 4, $minta);

            if (ctype_digit($kereses)) {
                array_unshift($reszek, 'm.munkalap_szam = %d');
                array_unshift($par, (int) $kereses);
            }

            $feltetelek[] = '(' . implode(' OR ', $reszek) . ')';
            $ertekek      = array_merge($ertekek, $par);
        }

        $hol   = 'WHERE ' . implode(' AND ', $feltetelek);
        $tobb  = "FROM {$tabla} m
                  LEFT JOIN {$ugyfel_tabla} u ON u.id = m.ugyfel_id
                  LEFT JOIN {$eszkoz_tabla} e ON e.id = m.eszkoz_id";

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- a $hol csak helyőrzőket tartalmaz.
        $szam_sql = "SELECT COUNT(*) {$tobb} {$hol}";
        $osszesen = (int) $wpdb->get_var(
            $ertekek === [] ? $szam_sql : $wpdb->prepare($szam_sql, $ertekek)
        );

        $sor_sql = "SELECT m.*, u.nev AS ugyfel_nev, u.telefon AS ugyfel_telefon,
                           u.szamlazasi_iranyitoszam, u.szamlazasi_telepules, u.szamlazasi_cim,
                           e.gyarto AS e_gyarto, e.tipus AS e_tipus,
                           e.megnevezes AS e_megnevezes, e.imei AS e_imei
                    {$tobb} {$hol}
                    ORDER BY (m.munkalap_szam IS NULL) ASC, m.munkalap_szam DESC, m.id DESC
                    LIMIT %d OFFSET %d";

        $sorok = $wpdb->get_results(
            $wpdb->prepare($sor_sql, array_merge($ertekek, [self::OLDAL_MERET, $eltolas]))
        );
        // phpcs:enable

        $sorok = is_array($sorok) ? $sorok : [];

        // A további (csatolt) eszközök száma laponként, egy lekérdezéssel.
        $tobbi_db = self::tovabbi_eszkoz_db(array_map(static fn (object $s): int => (int) $s->id, $sorok));

        // Hibasor-számlálók az oldalon látszó lapokhoz, egy lekérdezéssel.
        $hiba_szamlalo = self::hiba_szamlalok(
            array_map(static fn (object $s): int => (int) $s->id, $sorok),
            self::zart_kulcsok(self::hiba_allapotok())
        );

        $oldalak = max(1, (int) ceil($osszesen / self::OLDAL_MERET));
        $ma      = current_time('Y-m-d');

        $uj_adatok = ['sdh-urlap' => self::KULCS, 'sdh-id' => '0'];

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Munkalapok',
                'Javítási ügyek: ügyfél, eszköz, állapot, felelős, határidő – alattuk a hibasorok.',
                [
                    [
                        'cimke'      => '+ Új munkalap',
                        'url'        => self::url(['nezet' => 'uj']),
                        'elsodleges' => true,
                        'adatok'     => $uj_adatok,
                    ],
                ]
            );
            ?>

            <form method="get" class="sdh-kereso"
                  action="<?php echo esc_url(SDH_Muhely_Modulok::urlap_cel(self::KULCS)); ?>">
                <?php SDH_Muhely_Modulok::urlap_rejtett(self::KULCS); ?>

                <?php if ($ugyfel_id > 0) : ?>
                    <input type="hidden" name="ugyfel_id" value="<?php echo (int) $ugyfel_id; ?>">
                <?php endif; ?>

                <?php if ($eszkoz_id > 0) : ?>
                    <input type="hidden" name="eszkoz_id" value="<?php echo (int) $eszkoz_id; ?>">
                <?php endif; ?>

                <input type="search"
                       name="k"
                       value="<?php echo esc_attr($kereses); ?>"
                       placeholder="Munkalapszám, név, ügyfél, telefon, IMEI, készülék…">

                <select name="allapot" data-sdh-szuro aria-label="Állapot szerinti szűrés">
                    <option value="" <?php selected($szuro, ''); ?>>Nyitott munkalapok</option>
                    <option value="mind" <?php selected($szuro, 'mind'); ?>>Mind</option>
                    <?php foreach ($allapotok as $kulcs => $allapot) : ?>
                        <option value="<?php echo esc_attr((string) $kulcs); ?>"
                            data-szin="<?php echo esc_attr($allapot['szin']); ?>"
                            <?php selected($szuro, (string) $kulcs); ?>>
                            <?php echo esc_html($allapot['nev']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Keresés</button>

                <?php if ($kereses !== '' || $szuro !== '' || $ugyfel_id > 0 || $eszkoz_id > 0) : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(self::url()); ?>">
                        Szűrő törlése
                    </a>
                <?php endif; ?>

                <span class="sdh-kereso__talalat">
                    <?php echo esc_html(number_format_i18n($osszesen)); ?> találat
                </span>
            </form>

            <table class="sdh-tabla">
                <thead>
                    <tr>
                        <th>Szám</th>
                        <th>Állapot</th>
                        <th>Ügyfél</th>
                        <th>Eszköz</th>
                        <th class="sdh-tabla__rejtheto">Név</th>
                        <th class="sdh-tabla__rejtheto">Felelős</th>
                        <th>Határidő</th>
                        <th class="sdh-tabla__rejtheto">Hibák</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($sorok === []) : ?>
                    <tr>
                        <td colspan="9" class="sdh-tabla__ures">
                            <?php if ($kereses !== '' || $szuro !== '' || $ugyfel_id > 0 || $eszkoz_id > 0) : ?>
                                Erre a szűrésre nincs találat.
                            <?php else : ?>
                                Még nincs nyitott munkalap.
                                <a href="<?php echo esc_url(self::url(['nezet' => 'uj'])); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="0">Vidd fel az elsőt.</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($sorok as $sor) : ?>
                        <?php
                        $szerkeszt_url = self::url(['nezet' => 'szerkeszt', 'id' => (int) $sor->id]);
                        $allapot       = $allapotok[$sor->allapot] ?? null;
                        $lejart        = $sor->hatarido !== null
                            && $sor->hatarido !== ''
                            && $sor->hatarido < $ma
                            && $allapot !== null
                            && $allapot['szamozott']
                            && !$allapot['zart'];
                        $eszkoz_nev    = trim($sor->e_gyarto . ' ' . ($sor->e_tipus !== '' ? $sor->e_tipus : $sor->e_megnevezes));
                        $hibak         = $hiba_szamlalo[(int) $sor->id] ?? ['osszes' => 0, 'kesz' => 0];
                        ?>
                        <tr<?php echo $allapot !== null && $allapot['zart'] ? ' class="sdh-sor--zart"' : ''; ?>>
                            <td class="sdh-tabla__nev">
                                <a href="<?php echo esc_url($szerkeszt_url); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="<?php echo (int) $sor->id; ?>"
                                   data-sdh-munkalap-szam>
                                    <?php echo esc_html(self::szam_formaz($sor->munkalap_szam)); ?>
                                </a>
                            </td>
                            <td><?php self::allapot_valaszto((int) $sor->id, (string) $sor->allapot, $allapotok); ?></td>
                            <td>
                                <?php if ((int) $sor->ugyfel_id > 0 && $sor->ugyfel_nev !== null) : ?>
                                    <?php echo esc_html($sor->ugyfel_nev); ?>
                                    <?php if ($sor->ugyfel_telefon !== '') : ?>
                                        <div class="sdh-tabla__halvany">
                                            <?php echo esc_html($sor->ugyfel_telefon); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php $ugyfel_cim = SDH_Muhely_Ugyfel::cim_szoveg($sor); ?>
                                    <?php if ($ugyfel_cim !== '') : ?>
                                        <div class="sdh-tabla__halvany sdh-tabla__cim">
                                            <?php echo esc_html($ugyfel_cim); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="sdh-tabla__halvany">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $sor->eszkoz_id > 0 && $eszkoz_nev !== '') : ?>
                                    <?php echo esc_html($eszkoz_nev); ?>
                                    <?php if (!empty($tobbi_db[(int) $sor->id])) : ?>
                                        <span class="sdh-tobb-eszkoz"
                                              title="<?php echo esc_attr('További ' . (int) $tobbi_db[(int) $sor->id] . ' eszköz ezen a munkalapon'); ?>">+<?php echo (int) $tobbi_db[(int) $sor->id]; ?></span>
                                    <?php endif; ?>
                                    <?php if ($sor->e_imei !== '') : ?>
                                        <div class="sdh-tabla__halvany"><?php echo esc_html($sor->e_imei); ?></div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="sdh-tabla__halvany">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="sdh-tabla__rejtheto"><?php echo esc_html($sor->nev); ?></td>
                            <td class="sdh-tabla__rejtheto">
                                <?php echo esc_html(self::felelos_nev((int) $sor->felelos)); ?>
                            </td>
                            <td class="<?php echo $lejart ? 'sdh-hatarido--lejart' : ''; ?>">
                                <?php echo esc_html(self::datum_megjelenit((string) $sor->hatarido)); ?>
                            </td>
                            <td class="sdh-tabla__rejtheto sdh-tabla__halvany">
                                <?php
                                echo $hibak['osszes'] > 0
                                    ? esc_html($hibak['kesz'] . ' / ' . $hibak['osszes'] . ' kész')
                                    : '—';
                                ?>
                            </td>
                            <td>
                                <a class="sdh-gomb sdh-gomb--vilagos"
                                   href="<?php echo esc_url($szerkeszt_url); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="<?php echo (int) $sor->id; ?>">
                                    Megnyit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($oldalak > 1) : ?>
                <div class="sdh-lapozo">
                    <?php for ($i = 1; $i <= $oldalak; $i++) : ?>
                        <?php if ($i === $oldal) : ?>
                            <span class="sdh-lapozo__aktiv"><?php echo (int) $i; ?></span>
                        <?php else : ?>
                            <?php
                            $lap_url = self::url(
                                array_filter(
                                    [
                                        'oldalszam' => $i,
                                        'k'         => $kereses !== '' ? $kereses : null,
                                        'allapot'   => $szuro !== '' ? $szuro : null,
                                        'ugyfel_id' => $ugyfel_id > 0 ? $ugyfel_id : null,
                                        'eszkoz_id' => $eszkoz_id > 0 ? $eszkoz_id : null,
                                    ],
                                    static fn ($ertek): bool => $ertek !== null
                                )
                            );
                            ?>
                            <a href="<?php echo esc_url($lap_url); ?>"><?php echo (int) $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * A lista állapot-oszlopa: a jelvény maga a választó, így a lista
     * sorából azonnal állítható az állapot (data-sdh-lista-allapot, app.js).
     *
     * @param array<string, array{nev: string, szin: string, szamozott: bool, zart: bool}> $allapotok
     */
    private static function allapot_valaszto(int $id, string $kulcs, array $allapotok): void
    {
        $szin = $allapotok[$kulcs]['szin'] ?? 'szurke';

        ?>
        <span class="sdh-allapot sdh-allapot--valaszthato sdh-allapot--<?php echo esc_attr($szin); ?>"
              data-sdh-allapot-hely>
            <select data-sdh-lista-allapot
                    data-id="<?php echo (int) $id; ?>"
                    data-elozo="<?php echo esc_attr($kulcs); ?>"
                    aria-label="Állapot módosítása">
                <?php if (!isset($allapotok[$kulcs])) : ?>
                    <option value="<?php echo esc_attr($kulcs); ?>" selected><?php echo esc_html($kulcs); ?></option>
                <?php endif; ?>
                <?php foreach ($allapotok as $k => $allapot) : ?>
                    <option value="<?php echo esc_attr((string) $k); ?>"
                            data-szin="<?php echo esc_attr($allapot['szin']); ?>"
                            <?php selected($kulcs, (string) $k); ?>>
                        <?php echo esc_html($allapot['nev']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </span>
        <?php
    }

    /**
     * A lap közös állapotának írása. Ugyanazok a szabályok, mint az űrlapon:
     * számozott állapothoz ügyfél és eszköz kell, és az első számozott
     * állapotba lépéskor a lap munkalapszámot kap (zár alatt). Beáll a
     * lezárás napja, és megy a napló-esemény.
     *
     * @param array<string, mixed> $tovabbi_mezok A munkalap-sor további mezői ugyanabban az írásban.
     * @return array{id: int, szam: string, kulcs: string, nev: string, szin: string, zart: bool, lezarva: string}|string
     */
    private static function lap_allapot_ir(object $regi, string $kulcs, array $tovabbi_mezok = [])
    {
        global $wpdb;

        $id        = (int) $regi->id;
        $allapotok = self::allapotok();

        if (!isset($allapotok[$kulcs])) {
            return 'Ismeretlen állapot.';
        }

        $szamozott = (bool) $allapotok[$kulcs]['szamozott'];

        if ($szamozott && (int) $regi->ugyfel_id <= 0) {
            return 'Válassz ügyfelet a munkalaphoz – ügyfél nélkül nem adható munkalapszám.';
        }

        if ($szamozott && (int) $regi->eszkoz_id <= 0) {
            return 'Válassz eszközt a munkalaphoz – a munkalap mindig egy eszközre vonatkozik.';
        }

        $szamot_kap = $szamozott && (int) $regi->munkalap_szam <= 0;
        $zar        = false;

        if ($szamot_kap) {
            $zar = (int) $wpdb->get_var(
                $wpdb->prepare('SELECT GET_LOCK(%s, 5)', self::ZAR_NEV)
            ) === 1;

            if (!$zar) {
                return 'A számozás épp foglalt – próbáld újra egy pillanat múlva.';
            }
        }

        $adatok = $tovabbi_mezok + [
            'allapot'   => $kulcs,
            'lezarva'   => self::lezarva_ertek($kulcs, $regi),
            'modositva' => current_time('mysql'),
        ];

        try {
            if ($szamot_kap) {
                $adatok['munkalap_szam'] = self::kovetkezo_szam();
            }

            if ($wpdb->update(self::tabla(), $adatok, ['id' => $id]) === false) {
                return 'Az adatbázis visszautasította a módosítást.';
            }
        } finally {
            if ($zar) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::ZAR_NEV));
            }
        }

        if ((string) $regi->allapot !== $kulcs) {
            /** Állapotváltás: az RMA-modul naplózza (állapottörténet). */
            do_action('sdh_muhely_munkalap_allapot', $id, (string) $regi->allapot, $kulcs);
        }

        return [
            'id'    => $id,
            'szam'  => self::szam_formaz($adatok['munkalap_szam'] ?? $regi->munkalap_szam),
            'kulcs' => $kulcs,
            'nev'   => $allapotok[$kulcs]['nev'],
            'szin'  => $allapotok[$kulcs]['szin'],
            'zart'  => (bool) $allapotok[$kulcs]['zart'],
            // Az űrlap „Lezárva" sora ebből frissül (üres, ha a lap nem lezárt).
            'lezarva' => $adatok['lezarva'] !== null ? str_replace('-', '.', (string) $adatok['lezarva']) : '',
        ];
    }

    /**
     * Állapotváltás a listából vagy az űrlapról: a LAP állapota. Több
     * eszközös lapon ez minden eszközre érvényes (mind ezt az állapotot
     * kapja) – egy eszközt külön az eszkoz_allapot_valt() állít.
     *
     * @return array<string, mixed>|string
     */
    public static function allapot_valt(int $id, string $kulcs)
    {
        global $wpdb;

        $regi = $id > 0 ? self::egy($id) : null;

        if ($regi === null) {
            return 'Nincs ilyen munkalap. Lehet, hogy időközben törölték.';
        }

        $allapotok = self::allapotok();

        if (!isset($allapotok[$kulcs])) {
            return 'Ismeretlen állapot.';
        }

        $tobb = self::tovabbi_eszkoz_idk($id) !== [];

        if ($tobb && !empty($allapotok[$kulcs]['reszben']) && (string) $regi->allapot !== $kulcs) {
            return 'A „' . $allapotok[$kulcs]['nev'] . '” a több eszközös lap közös állapota: magától áll be, amikor az egyik eszköz kész, a másik még nem. Az eszközök állapotát külön állítsd.';
        }

        // Ugyanaz a (közös) állapot újra: nincs mit szétosztani az eszközökre.
        $szetoszt = $tobb && empty($allapotok[$kulcs]['reszben']);

        $eredmeny = self::lap_allapot_ir($regi, $kulcs, $szetoszt ? ['fo_allapot' => $kulcs] : ($tobb ? [] : ['fo_allapot' => '']));

        if (is_string($eredmeny)) {
            return $eredmeny;
        }

        if ($szetoszt) {
            $wpdb->update(self::tobbi_eszkoz_tabla(), ['allapot' => $kulcs], ['munkalap_id' => $id]);
        }

        return $eredmeny + self::eszkoz_valasz($id);
    }

    /* =================================================================
     * Több eszköz: eszközönként saját állapot és saját „fizetve"
     *
     * Egy eszközös lapon minden a régi: a lap állapota az eszközé. Több
     * eszköznél minden eszköznek saját állapota van (az egyik kész és
     * elviszik, a másik alkatrészre vár), a lap állapota pedig a KÖZÖS
     * állapot, amely ezekből adódik – lásd kozos_allapot().
     * ============================================================== */

    /**
     * A lap eszközeinek állapota: kulcs (0 = a fő eszköz, különben a csatolt
     * eszköz azonosítója) => állapotkulcs. Amelyik eszköznek még nincs saját
     * állapota, az a lapét követi.
     *
     * @param array<int, object>|null $reszletek A kapcsolósorok, ha a hívónál már megvannak.
     * @return array<int, string>
     */
    public static function eszkoz_allapotok(object $munkalap, ?array $reszletek = null): array
    {
        $allapotok = self::allapotok();
        $lap       = (string) $munkalap->allapot;
        $reszletek = $reszletek ?? self::tovabbi_reszletek((int) $munkalap->id);

        if ($reszletek === []) {
            return [0 => $lap];
        }

        // A közös „részben" állapot nem lehet egy eszközé: helyette az alapállapot.
        $alap     = !empty($allapotok[$lap]['reszben']) ? self::alap_kulcs($allapotok) : $lap;
        $ervenyes = static fn (string $k): bool => $k !== '' && isset($allapotok[$k]) && empty($allapotok[$k]['reszben']);
        $fo       = (string) ($munkalap->fo_allapot ?? '');
        $ki       = [0 => $ervenyes($fo) ? $fo : $alap];

        foreach ($reszletek as $r) {
            $sajat = (string) ($r->allapot ?? '');

            $ki[(int) $r->eszkoz_id] = $ervenyes($sajat) ? $sajat : $alap;
        }

        return $ki;
    }

    /**
     * A több eszközös lap közös állapota az eszközök állapotából:
     *   - az érvénytelen eszköz nem számít, amíg van másik;
     *   - ha mind ugyanaz, az;
     *   - ha van kész (elkészült vagy lezárt) és van, ami még nem az:
     *     „Részben elkészült";
     *   - különben az állapotlista sorrendjében a legkorábbi (pl. Elkészült +
     *     Lezárt = Elkészült: a lap nem zárul le, amíg van át nem adott eszköz).
     *
     * @param array<int, string> $eszkoz_allapotok kulcs => állapotkulcs
     */
    public static function kozos_allapot(array $eszkoz_allapotok): string
    {
        $allapotok = self::allapotok();
        $ertekek   = array_values(array_map('strval', $eszkoz_allapotok));

        if ($ertekek === []) {
            return self::alap_kulcs($allapotok);
        }

        if (count($ertekek) === 1) {
            return $ertekek[0];
        }

        $elo = array_values(array_filter($ertekek, static fn (string $k): bool => empty($allapotok[$k]['ervenytelen'])));

        if ($elo === []) {
            return $ertekek[0];
        }

        if (count(array_unique($elo)) === 1) {
            return $elo[0];
        }

        $kesz = array_filter($elo, static fn (string $k): bool => !empty($allapotok[$k]['kesz']) || !empty($allapotok[$k]['zart']));

        if ($kesz !== [] && count($kesz) < count($elo)) {
            return self::reszben_kulcs();
        }

        $sorrend = array_flip(array_keys($allapotok));

        usort($elo, static fn (string $a, string $b): int => ($sorrend[$a] ?? PHP_INT_MAX) <=> ($sorrend[$b] ?? PHP_INT_MAX));

        return $elo[0];
    }

    /**
     * Egy eszköz saját állapotának váltása. A lap közös állapota ebből
     * újraszámolódik (és ha kell, a lap számot kap, lezárul vagy újranyílik).
     * Egy eszközös lapon ez a lap állapotváltása.
     *
     * @param int $kulcs 0 = a fő eszköz, különben a csatolt eszköz azonosítója.
     * @return array<string, mixed>|string
     */
    public static function eszkoz_allapot_valt(int $id, int $kulcs, string $allapot)
    {
        global $wpdb;

        $regi = $id > 0 ? self::egy($id) : null;

        if ($regi === null) {
            return 'Nincs ilyen munkalap. Lehet, hogy időközben törölték.';
        }

        $allapotok = self::allapotok();

        if (!isset($allapotok[$allapot]) || !empty($allapotok[$allapot]['reszben'])) {
            return 'Ismeretlen állapot.';
        }

        $mostani = self::eszkoz_allapotok($regi);

        if (count($mostani) < 2) {
            return self::allapot_valt($id, $allapot);
        }

        if (!isset($mostani[$kulcs])) {
            return 'Ez az eszköz már nincs a munkalapon. Nyisd meg újra a lapot.';
        }

        $elozo           = $mostani[$kulcs];
        $mostani[$kulcs] = $allapot;

        // A fő eszköz állapota a munkalap-sorban áll: egy írásban megy a közös állapottal.
        $eredmeny = self::lap_allapot_ir($regi, self::kozos_allapot($mostani), ['fo_allapot' => $mostani[0]]);

        if (is_string($eredmeny)) {
            return $eredmeny;
        }

        // Innentől minden eszköz állapota ki van írva (eddig a lapét követhették).
        foreach ($mostani as $k => $a) {
            if ($k > 0) {
                $wpdb->update(self::tobbi_eszkoz_tabla(), ['allapot' => $a], ['munkalap_id' => $id, 'eszkoz_id' => $k]);
            }
        }

        if ($elozo !== $allapot) {
            /** Egy eszköz állapotváltása: az RMA-modul naplózza az eszköz nevével. */
            do_action('sdh_muhely_munkalap_eszkoz_allapot', $id, $kulcs > 0 ? $kulcs : (int) $regi->eszkoz_id, $elozo, $allapot);
        }

        return $eredmeny + self::eszkoz_valasz($id);
    }

    /**
     * A lap közös állapotának újraszámolása az eszközökéből – mentés után,
     * amikor eszköz került a lapra vagy le róla.
     */
    public static function allapot_szinkron(int $id): void
    {
        global $wpdb;

        $lap = $id > 0 ? self::egy($id) : null;

        if ($lap === null) {
            return;
        }

        $allapotok = self::allapotok();
        $reszletek = self::tovabbi_reszletek($id);

        if ($reszletek !== []) {
            $kozos = self::kozos_allapot(self::eszkoz_allapotok($lap, $reszletek));

            if ($kozos !== (string) $lap->allapot) {
                self::lap_allapot_ir($lap, $kozos);
            }

            return;
        }

        // Egy eszköz maradt: az ő állapota a lapé, a külön tárolt érték kiürül.
        $fo  = (string) ($lap->fo_allapot ?? '');
        $cel = (string) $lap->allapot;

        if ($fo !== '' && isset($allapotok[$fo]) && empty($allapotok[$fo]['reszben'])) {
            $cel = $fo;
        } elseif (!empty($allapotok[$cel]['reszben']) && $fo !== '') {
            $cel = self::alap_kulcs($allapotok);
        }

        if ($cel !== (string) $lap->allapot) {
            self::lap_allapot_ir($lap, $cel, ['fo_allapot' => '']);
        } elseif ($fo !== '') {
            $wpdb->update(self::tabla(), ['fo_allapot' => ''], ['id' => $id]);
        }
    }

    /**
     * A lap állapotának szétosztása minden eszközre (az űrlapon a lap
     * állapotát írták át): több eszköznél mind ezt kapja.
     */
    private static function allapot_szetoszt(int $id, string $kulcs): void
    {
        global $wpdb;

        $tobb = self::tovabbi_eszkoz_idk($id) !== [];

        $wpdb->update(self::tabla(), ['fo_allapot' => $tobb ? $kulcs : ''], ['id' => $id]);

        if ($tobb) {
            $wpdb->update(self::tobbi_eszkoz_tabla(), ['allapot' => $kulcs], ['munkalap_id' => $id]);
        }
    }

    /**
     * A lapot kézzel „Fizetve"-re állították (nem a Fizetés ablakból): ami még
     * hátravolt, az most befizetettnek számít. A „Fizetett" így a teljes
     * kifizetett összeg lesz – nem marad ott a puszta előleg –, eszközönként
     * a hátralék az eszköz külön befizetéséhez adódik (a díjak nem változnak).
     *
     * @return float Ennyivel nőtt a „Fizetett".
     */
    public static function teljes_kifizetes_konyvel(int $id): float
    {
        global $wpdb;

        $lap = $id > 0 ? self::egy($id) : null;

        if ($lap === null) {
            return 0.0;
        }

        $ossz   = 0.0;
        $reszek = [];

        foreach (self::eszkoz_bontas($lap) as $b) {
            $hatra = round((float) $b['brutto'] - (float) $b['eloleg'], 2);

            if ($hatra <= 0) {
                continue;
            }

            $ossz                           += $hatra;
            $reszek[(int) $b['eszkoz_id']]   = $hatra;

            if ((int) $b['eszkoz_id'] > 0) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query($wpdb->prepare(
                    'UPDATE ' . self::tobbi_eszkoz_tabla() . ' SET fizetett = fizetett + %f WHERE munkalap_id = %d AND eszkoz_id = %d',
                    $hatra,
                    $id,
                    (int) $b['eszkoz_id']
                ));
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query($wpdb->prepare('UPDATE ' . self::tabla() . ' SET fo_kulon = fo_kulon + %f WHERE id = %d', $hatra, $id));
            }
        }

        if ($ossz > 0) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($wpdb->prepare('UPDATE ' . self::tabla() . ' SET fizetett = fizetett + %f WHERE id = %d', $ossz, $id));
        }

        self::vegfizetes_jegyez($id, $reszek);

        return round($ossz, 2);
    }

    /**
     * A lapot lezáró fizetés eszközönkénti részének feljegyzése (kulcs => összeg;
     * 0 = a fő eszköz). Amelyik eszköz nincs benne, annál nulla lesz.
     *
     * @param array<int, float> $reszek
     */
    public static function vegfizetes_jegyez(int $id, array $reszek): void
    {
        global $wpdb;

        $wpdb->update(self::tabla(), ['fo_veg' => round(max(0.0, (float) ($reszek[0] ?? 0)), 2)], ['id' => $id]);

        foreach (self::tovabbi_eszkoz_idk($id) as $kulcs) {
            $wpdb->update(self::tobbi_eszkoz_tabla(), ['veg' => round(max(0.0, (float) ($reszek[$kulcs] ?? 0)), 2)], ['munkalap_id' => $id, 'eszkoz_id' => $kulcs]);
        }
    }

    /**
     * A lapot lezáró fizetés visszavonása (a „Fizetve" pipa levételekor, ha a
     * fizetés nem történt meg): eszközönként pontosan az megy vissza, amit a
     * lezáró fizetés könyvelt. A díjak és a korábbi előlegek megmaradnak.
     *
     * @return float Ennyivel csökkent a „Fizetett".
     */
    public static function vegfizetes_visszavon(int $id): float
    {
        global $wpdb;

        $lap = $id > 0 ? self::egy($id) : null;

        if ($lap === null) {
            return 0.0;
        }

        $fo   = min(max(0.0, (float) ($lap->fo_veg ?? 0)), max(0.0, (float) ($lap->fo_kulon ?? 0)));
        $ossz = $fo;

        foreach (self::tovabbi_reszletek($id) as $r) {
            $le = min(max(0.0, (float) ($r->veg ?? 0)), max(0.0, (float) ($r->fizetett ?? 0)));

            $wpdb->update(self::tobbi_eszkoz_tabla(), ['fizetett' => round((float) $r->fizetett - $le, 2), 'veg' => 0], ['munkalap_id' => $id, 'eszkoz_id' => (int) $r->eszkoz_id]);
            $ossz += $le;
        }

        $wpdb->update(self::tabla(), [
            'fo_kulon' => round((float) ($lap->fo_kulon ?? 0) - $fo, 2),
            'fo_veg'   => 0,
            'fizetett' => round(max(0.0, (float) $lap->fizetett - $ossz), 2),
        ], ['id' => $id]);

        return round($ossz, 2);
    }

    /**
     * Az űrlapon lejjebb írták a „Fizetett (előleg)" mezőt: a csökkenés előbb a
     * díjakon FELÜLI befizetéseket viszi el (a fő eszközét, aztán a csatoltakét
     * sorban) – így egy visszavont fizetés után a bevizsgálási díjak épek
     * maradnak. Csak ami ezen túl is hiányzik, az csökkenti a fő eszköz díját.
     */
    public static function kulon_csokkent(int $id, float $mennyivel): void
    {
        global $wpdb;

        $lap = $id > 0 ? self::egy($id) : null;

        if ($lap === null || $mennyivel <= 0.005) {
            return;
        }

        $fo  = max(0.0, (float) ($lap->fo_kulon ?? 0));
        $le  = min($fo, $mennyivel);

        if ($le > 0) {
            $wpdb->update(self::tabla(), ['fo_kulon' => round($fo - $le, 2)], ['id' => $id]);
            $mennyivel -= $le;
        }

        foreach (self::tovabbi_reszletek($id) as $r) {
            if ($mennyivel <= 0.005) {
                break;
            }

            $kulon = max(0.0, (float) ($r->fizetett ?? 0));
            $le    = min($kulon, $mennyivel);

            if ($le > 0) {
                $wpdb->update(self::tobbi_eszkoz_tabla(), ['fizetett' => round($kulon - $le, 2)], ['munkalap_id' => $id, 'eszkoz_id' => (int) $r->eszkoz_id]);
                $mennyivel -= $le;
            }
        }
    }

    /**
     * Egyszeri pótlás: a korábban kifizetett lapokon a „Fizetett" csak az
     * előleget mutatta. Ezeken a hátralék most befizetettnek könyvelődik,
     * hogy a „Fizetett" a kifizetett végösszeg legyen. Egyszer fut.
     */
    public static function fizetett_potlas(): void
    {
        global $wpdb;

        if (get_option('sdh_muhely_fizetett_potlas') === '1') {
            return;
        }

        update_option('sdh_muhely_fizetett_potlas', '1');

        $idk = (array) $wpdb->get_col('SELECT id FROM ' . self::tabla() . ' WHERE fizetve = 1 AND brutto_ertek - fizetett > 0.5');

        foreach ($idk as $id) {
            self::teljes_kifizetes_konyvel((int) $id);
        }
    }

    /**
     * A lap és az eszközök „fizetve" jelzőinek összehangolása mentés és
     * fizetés után:
     *   - kifizetett lapon minden eszköz fizetve (a lap fizetési napjával);
     *   - ha a lapról most került le a „Fizetve" ($regi még fizetve volt),
     *     az eszközökről is lekerül;
     *   - ha minden eszköz külön ki lett fizetve, a lap is fizetve lesz.
     */
    public static function fizetve_szinkron(int $id, ?object $regi = null): void
    {
        global $wpdb;

        $lap = $id > 0 ? self::egy($id) : null;

        if ($lap === null) {
            return;
        }

        $kapcsolo = self::tobbi_eszkoz_tabla();
        $ma       = current_time('Y-m-d');

        if ((int) $lap->fizetve === 1) {
            $nap = self::datum_ertek((string) ($lap->fizetes_ideje ?? '')) ?: $ma;

            if ((int) ($lap->fo_fizetve ?? 0) !== 1) {
                $wpdb->update(self::tabla(), ['fo_fizetve' => 1, 'fo_fizetes_ideje' => $nap], ['id' => $id]);
            }

            $wpdb->update($kapcsolo, ['fizetve' => 1, 'fizetes_ideje' => $nap], ['munkalap_id' => $id, 'fizetve' => 0]);

            return;
        }

        if ($regi !== null && (int) $regi->fizetve === 1) {
            $wpdb->update(self::tabla(), ['fo_fizetve' => 0, 'fo_fizetes_ideje' => null], ['id' => $id]);
            $wpdb->update($kapcsolo, ['fizetve' => 0, 'fizetes_ideje' => null], ['munkalap_id' => $id]);

            // Amelyik eszköz a befizetések alapján továbbra is ki van fizetve, az fizetve
            // marad – de csak ha van, amelyik nincs (különben a lap rögtön újra fizetve lenne).
            $bontas = self::eszkoz_bontas(self::egy($id) ?? $lap);
            $kesz   = array_filter($bontas, static fn (array $b): bool => (float) $b['brutto'] > 0.5 && (float) $b['brutto'] - (float) $b['eloleg'] <= 0.5);

            if (count($bontas) > 1 && $kesz !== [] && count($kesz) < count($bontas)) {
                foreach ($kesz as $b) {
                    if ((int) $b['eszkoz_id'] === 0) {
                        $wpdb->update(self::tabla(), ['fo_fizetve' => 1, 'fo_fizetes_ideje' => $ma], ['id' => $id]);
                    } else {
                        $wpdb->update($kapcsolo, ['fizetve' => 1, 'fizetes_ideje' => $ma], ['munkalap_id' => $id, 'eszkoz_id' => (int) $b['eszkoz_id']]);
                    }
                }
            }

            return;
        }

        $reszletek = self::tovabbi_reszletek($id);

        // Egy eszközös lapon csak a lap „Fizetve" jelzője él: a korábbi (több eszközös
        // időből maradt) eszköz-jelző kiürül – a levett eszköz tételei a megmaradtra
        // szálltak, azokat a régi jelző nem fedezi.
        if ($reszletek === []) {
            if ((int) ($lap->fo_fizetve ?? 0) === 1) {
                $wpdb->update(self::tabla(), ['fo_fizetve' => 0, 'fo_fizetes_ideje' => null], ['id' => $id]);
            }

            return;
        }

        if ((int) ($lap->fo_fizetve ?? 0) !== 1) {
            return;
        }

        $nap = self::datum_ertek((string) ($lap->fo_fizetes_ideje ?? '')) ?: $ma;

        foreach ($reszletek as $r) {
            if ((int) ($r->fizetve ?? 0) !== 1) {
                return;
            }

            $nap = max($nap, self::datum_ertek((string) ($r->fizetes_ideje ?? '')) ?: $nap);
        }

        $wpdb->update(self::tabla(), ['fizetve' => 1, 'fizetes_ideje' => $nap, 'modositva' => current_time('mysql')], ['id' => $id]);
    }

    /**
     * Egy eszköz „fizetve" jelzője. Bejelölni a Fizetés ablak szokta
     * (részfizetés, amely az eszköz teljes fizetendőjét fedezi); kézzel
     * levenni akkor lehet, ha a lap egésze nincs fizetve.
     *
     * @param int $kulcs 0 = a fő eszköz, különben a csatolt eszköz azonosítója.
     * @return true|string Igaz, vagy hibaüzenet.
     */
    public static function eszkoz_fizetve_ir(int $id, int $kulcs, bool $fizetve)
    {
        global $wpdb;

        $lap = $id > 0 ? self::egy($id) : null;

        if ($lap === null) {
            return 'Nincs ilyen munkalap.';
        }

        $csatolt = self::tovabbi_eszkoz_idk($id);

        if ($csatolt === []) {
            return 'Egy eszközös lapon a „Fizetve” a munkalapé – ott állítsd.';
        }

        if ($kulcs !== 0 && !in_array($kulcs, $csatolt, true)) {
            return 'Ez az eszköz már nincs a munkalapon. Nyisd meg újra a lapot.';
        }

        if (!$fizetve && (int) $lap->fizetve === 1) {
            return 'A munkalap egészben ki van fizetve. Előbb a lapon vedd le a „Fizetve” pipát.';
        }

        $nap = $fizetve ? current_time('Y-m-d') : null;

        if ($kulcs === 0) {
            $wpdb->update(self::tabla(), ['fo_fizetve' => $fizetve ? 1 : 0, 'fo_fizetes_ideje' => $nap, 'modositva' => current_time('mysql')], ['id' => $id]);
        } else {
            $wpdb->update(self::tobbi_eszkoz_tabla(), ['fizetve' => $fizetve ? 1 : 0, 'fizetes_ideje' => $nap], ['munkalap_id' => $id, 'eszkoz_id' => $kulcs]);
        }

        if ($fizetve) {
            self::fizetve_szinkron($id);
        }

        return true;
    }

    /**
     * Az eszközönkénti állapot blokkja (űrlap bal oldala, Áttekintés
     * részletpanel): eszközönként név, állapotválasztó és a fizetés állása.
     * Egy eszközös lapnál üres – ott a lap állapota az eszközé.
     */
    public static function eszkoz_allapot_blokk(object $munkalap): string
    {
        $bontas = self::eszkoz_bontas($munkalap);

        if (count($bontas) < 2) {
            return '';
        }

        $allapotok = self::allapotok();
        $id        = (int) $munkalap->id;
        $kesz_db   = count(array_filter($bontas, static fn (array $b): bool => (bool) $b['kesz']));
        $html      = '<div class="sdh-eszall" data-sdh-eszkoz-allapotok="' . $id . '">';

        foreach ($bontas as $b) {
            $kulcs = (string) $b['allapot'];
            $szin  = $allapotok[$kulcs]['szin'] ?? 'szurke';
            $opciok = '';

            if (!isset($allapotok[$kulcs])) {
                $opciok .= '<option value="' . esc_attr($kulcs) . '" selected>' . esc_html($kulcs) . '</option>';
            }

            foreach ($allapotok as $k => $a) {
                // A „Részben elkészült" a lap közös állapota, eszköznek nem adható.
                if (!empty($a['reszben'])) {
                    continue;
                }

                $opciok .= sprintf(
                    '<option value="%s" data-szin="%s"%s>%s</option>',
                    esc_attr((string) $k),
                    esc_attr((string) $a['szin']),
                    selected($kulcs, (string) $k, false),
                    esc_html((string) $a['nev'])
                );
            }

            $ft       = static fn (float $o): string => esc_html(number_format($o, 0, ',', "\u{00a0}")) . "\u{00a0}Ft";
            $befolyt = (float) $b['eloleg'] > 0.5 ? 'befizetve ' . $ft((float) $b['eloleg']) . ' · ' : '';

            if ($b['fizetve']) {
                $penz = '<span class="sdh-eszall__penz is-fizetve">Fizetve'
                    . ((float) $b['eloleg'] > 0.5 ? ' ' . $ft((float) $b['eloleg']) : '')
                    . ($b['fizetes_ideje'] !== '' ? ' · ' . esc_html(str_replace('-', '.', (string) $b['fizetes_ideje'])) : '')
                    . ((int) $munkalap->fizetve === 1 ? '' : sprintf(
                        ' <button type="button" class="sdh-eszall__vissza" data-sdh-eszkoz-fizetve-vissza data-id="%d" data-kulcs="%d" data-nev="' . str_replace('%', '%%', esc_attr((string) $b['felirat'])) . '" data-befizetve="' . esc_attr((string) (float) $b['eloleg']) . '"'
                        . ' title="A „Fizetve” jelző levétele erről az eszközről. A befizetett összeghez és a pénztárhoz nem nyúl." aria-label="A fizetve jelző levétele: %s">&times;</button>',
                        $id,
                        (int) $b['eszkoz_id'],
                        esc_attr((string) $b['felirat'])
                    ))
                    . '</span>';
            } elseif ((float) $b['fizetendo'] > 0.5) {
                $penz = '<span class="sdh-eszall__penz">' . $befolyt . 'fizetendő ' . $ft((float) $b['fizetendo']) . '</span>';
            } else {
                $penz = '<span class="sdh-eszall__penz">' . $befolyt . 'nincs fizetendő</span>';
            }

            $html .= sprintf(
                '<div class="sdh-eszall__sor%s" data-kulcs="%d"><span class="sdh-eszall__nev" title="%s">%s</span>'
                . '<span class="sdh-allapot sdh-allapot--valaszthato sdh-allapot--%s" data-sdh-allapot-hely>'
                . '<select data-sdh-eszkoz-allapot data-id="%d" data-kulcs="%d" data-elozo="%s" aria-label="%s">%s</select></span>%s</div>',
                $b['kesz'] ? ' is-kesz' : '',
                (int) $b['eszkoz_id'],
                esc_attr((string) $b['felirat']),
                esc_html((string) $b['felirat']),
                esc_attr((string) $szin),
                $id,
                (int) $b['eszkoz_id'],
                esc_attr($kulcs),
                esc_attr($b['felirat'] . ' állapota'),
                $opciok,
                $penz
            );
        }

        return $html . '<p class="sdh-eszall__ossz">' . esc_html(self::kesz_szoveg($bontas, $kesz_db)) . '</p></div>';
    }

    /**
     * Rövid összegzés a több eszközös lapról: melyik eszköz kész.
     * Pl. „1 / 2 kész: 2. Samsung Galaxy A54".
     *
     * @param array<int, array<string, mixed>> $bontas
     */
    public static function kesz_szoveg(array $bontas, ?int $kesz_db = null): string
    {
        $keszek = array_values(array_filter($bontas, static fn (array $b): bool => (bool) $b['kesz']));
        $db     = $kesz_db ?? count($keszek);

        if (count($bontas) < 2) {
            return '';
        }

        if ($db === 0) {
            return 'Még egyik eszköz sincs kész.';
        }

        if ($db === count($bontas)) {
            return 'Minden eszköz kész (' . $db . ' / ' . count($bontas) . ').';
        }

        return $db . ' / ' . count($bontas) . ' kész: ' . implode(', ', array_map(static fn (array $b): string => (string) $b['felirat'], $keszek));
    }

    /**
     * Az állapotváltás válaszának eszközönkénti része: a frissített blokk
     * és a „melyik kész" szöveg (egy eszközös lapnál üresek).
     *
     * @return array{blokk: string, kesz_szoveg: string}
     */
    private static function eszkoz_valasz(int $id): array
    {
        $lap = self::egy($id);

        if ($lap === null) {
            return ['blokk' => '', 'kesz_szoveg' => ''];
        }

        return [
            'blokk'       => self::eszkoz_allapot_blokk($lap),
            'kesz_szoveg' => self::kesz_szoveg(self::eszkoz_bontas($lap)),
        ];
    }

    /** Egy eszköz állapotváltása (AJAX). */
    public static function ajax_eszkoz_allapot(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $eredmeny = self::eszkoz_allapot_valt(
            isset($_POST['id']) ? (int) $_POST['id'] : 0,
            isset($_POST['kulcs']) ? max(0, (int) $_POST['kulcs']) : 0,
            isset($_POST['allapot']) ? sanitize_key(wp_unslash($_POST['allapot'])) : ''
        );

        if (is_string($eredmeny)) {
            wp_send_json_error(['uzenet' => $eredmeny]);
        }

        wp_send_json_success($eredmeny);
    }

    /** Egy eszköz „fizetve" jelzőjének levétele (AJAX). */
    public static function ajax_eszkoz_fizetve(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $eredmeny = self::eszkoz_fizetve_ir($id, isset($_POST['kulcs']) ? max(0, (int) $_POST['kulcs']) : 0, false);

        if (is_string($eredmeny)) {
            wp_send_json_error(['uzenet' => $eredmeny]);
        }

        wp_send_json_success(['id' => $id] + self::eszkoz_valasz($id));
    }

    /** Állapotváltás a listából (AJAX). */
    public static function ajax_allapot(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id    = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $kulcs = isset($_POST['allapot']) ? sanitize_key(wp_unslash($_POST['allapot'])) : '';

        $eredmeny = self::allapot_valt($id, $kulcs);

        if (is_string($eredmeny)) {
            wp_send_json_error(['uzenet' => $eredmeny]);
        }

        wp_send_json_success($eredmeny);
    }

    /**
     * Hibasor-számlálók munkalaponként: hány hibasor van, és hány kész.
     *
     * @param array<int, int>    $munkalap_idk
     * @param array<int, string> $zart_kulcsok A "kész" hibasor-állapotok.
     * @return array<int, array{osszes: int, kesz: int}>
     */
    private static function hiba_szamlalok(array $munkalap_idk, array $zart_kulcsok): array
    {
        global $wpdb;

        if ($munkalap_idk === []) {
            return [];
        }

        $tabla = self::hiba_tabla();
        $kesz  = $zart_kulcsok === []
            ? '0'
            : 'SUM(allapot IN (' . self::in_helyorzo($zart_kulcsok) . '))';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- csak helyőrzők kerülnek bele.
        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT munkalap_id, COUNT(*) AS osszes, {$kesz} AS kesz
                 FROM {$tabla}
                 WHERE munkalap_id IN (" . self::in_helyorzo($munkalap_idk, '%d') . ')
                 GROUP BY munkalap_id',
                array_merge($zart_kulcsok, $munkalap_idk)
            )
        );
        // phpcs:enable

        $eredmeny = [];

        foreach ((array) $sorok as $sor) {
            $eredmeny[(int) $sor->munkalap_id] = [
                'osszes' => (int) $sor->osszes,
                'kesz'   => (int) $sor->kesz,
            ];
        }

        return $eredmeny;
    }

    /* =================================================================
     * Űrlap – teljes oldalas változat (tartalék)
     * ============================================================== */

    private static function urlap_oldal(string $nezet): void
    {
        $munkalap = null;

        if ($nezet === 'szerkeszt') {
            $id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            $munkalap = self::egy($id);

            if ($munkalap === null) {
                wp_safe_redirect(self::url(['uzenet' => 'nincs_ilyen']));
                exit;
            }
        }

        $uj = $munkalap === null;

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                $uj ? 'Új munkalap' : 'Munkalap ' . self::szam_formaz($munkalap->munkalap_szam),
                $uj
                    ? 'A munkalapszám az első számozott állapotba lépéskor generálódik.'
                    : 'Utoljára módosítva: ' . self::datumido_megjelenit((string) $munkalap->modositva),
                [
                    [
                        'cimke' => '← Vissza a listához',
                        'url'   => self::url(),
                    ],
                ]
            );
            ?>

            <form class="sdh-urlap sdh-urlap--szeles" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_munkalap_mentes">
                <?php self::urlap_belso($munkalap, false); ?>
            </form>
        </div>
        <?php
    }

    /* =================================================================
     * Űrlap – popup változat
     * ============================================================== */

    public static function ajax_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }

        $id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $munkalap = $id > 0 ? self::egy($id) : null;

        if ($id > 0 && $munkalap === null) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs ilyen munkalap.</div>';
            wp_die();
        }

        $uj = $munkalap === null;

        ?>
        <h2 class="sdh-modal__cim">
            <?php echo esc_html($uj ? 'Új munkalap' : 'Munkalap ' . self::szam_formaz($munkalap->munkalap_szam)); ?>
            <?php if (!$uj) : ?>
                <span class="sdh-modal__cim-megj">
                    módosítva: <?php echo esc_html(self::datumido_megjelenit((string) $munkalap->modositva)); ?>
                </span>
            <?php endif; ?>
        </h2>

        <form class="sdh-urlap" method="post"
              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-sdh-ajax-action="sdh_muhely_munkalapok_ment">
            <input type="hidden" name="action" value="sdh_muhely_munkalap_mentes">
            <?php self::urlap_belso($munkalap, true); ?>
        </form>
        <?php

        wp_die();
    }

    /**
     * Az űrlap belseje: rejtett mezők, dobozok, lábléc.
     *
     * Ugyanez megy a teljes oldalas és a popupos változatba.
     */
    private static function urlap_belso(?object $munkalap, bool $modal): void
    {
        $uj = $munkalap === null;

        $allapotok      = self::allapotok();
        $hiba_allapotok = self::hiba_allapotok();

        // Új lapnál az ügyfél és az eszköz előre kitölthető a címből.
        $ugyfel_id = $uj
            ? (isset($_REQUEST['ugyfel_id']) ? (int) $_REQUEST['ugyfel_id'] : 0)
            : (int) $munkalap->ugyfel_id;
        $eszkoz_id = $uj
            ? (isset($_REQUEST['eszkoz_id']) ? (int) $_REQUEST['eszkoz_id'] : 0)
            : (int) $munkalap->eszkoz_id;

        $allapot  = $uj ? self::alap_kulcs($allapotok) : (string) $munkalap->allapot;
        $felelos  = $uj ? get_current_user_id() : (int) $munkalap->felelos;
        $keszult  = $uj ? current_time('Y-m-d') : self::datum_ertek((string) $munkalap->keszult);
        $hatarido = $uj ? '' : self::datum_ertek((string) $munkalap->hatarido);
        $lezarva  = $uj ? '' : self::datum_ertek((string) ($munkalap->lezarva ?? ''));

        $hibak   = $uj ? [] : self::hibasorok((int) $munkalap->id);
        $hibak   = $hibak === [] ? [null] : $hibak;

        $tetelek  = $uj ? [] : SDH_Muhely_Tetel::lista((int) $munkalap->id);
        $szolg    = array_values(array_filter($tetelek, static fn (object $t): bool => $t->tipus === 'szolgaltatas'));
        $termekek = array_values(array_filter($tetelek, static fn (object $t): bool => $t->tipus === 'termek'));

        $megjegyzes        = $uj ? '' : (string) $munkalap->megjegyzes;
        $ugyfel_megjegyzes = $uj ? '' : (string) ($munkalap->ugyfel_megjegyzes ?? '');

        // Fizetés és a tételek alapértékei.
        $afakulcsok  = SDH_Muhely_Tetel::afakulcsok();
        $lap_afa     = $uj
            ? SDH_Muhely_Tetel::alap_afakulcs()
            : SDH_Muhely_Tetel::afakulcs_ervenyes((string) ($munkalap->afakulcs ?? ''));
        $lap_kedv    = $uj ? 0.0 : (float) ($munkalap->kedvezmeny ?? 0);
        $fizetve     = !$uj && (int) ($munkalap->fizetve ?? 0) === 1;
        $fiz_ideje   = $uj ? '' : self::datum_ertek((string) ($munkalap->fizetes_ideje ?? ''));
        $fizetett    = $uj ? 0.0 : (float) ($munkalap->fizetett ?? 0);
        $fiz_mod     = $uj ? '' : (string) ($munkalap->fizetesi_mod ?? '');
        $fiz_modok   = self::fizetesi_modok();

        // Egy régi lap fizetési módja akkor is látszódjon, ha a listából már kikerült.
        if ($fiz_mod !== '' && !in_array($fiz_mod, $fiz_modok, true)) {
            $fiz_modok[] = $fiz_mod;
        }

        $felelosok = self::felelosok();

        // Egy régi lap felelőse akkor is látszódjon, ha már nem jogosult.
        if ($felelos > 0 && !isset($felelosok[$felelos])) {
            $nev = self::felelos_nev($felelos);

            if ($nev !== '') {
                $felelosok[$felelos] = $nev;
            }
        }

        $eszkozok = self::ugyfel_eszkozei($ugyfel_id, $eszkoz_id);

        // A lap további (csatolt) eszközei – a fő eszköz a fenti választóban áll.
        $tovabbi = $uj ? [] : self::eszkoz_sorok(self::tovabbi_eszkoz_idk((int) $munkalap->id));

        // Az eszközönkénti bevizsgálási díjak (eszköz-azonosító => kapcsolósor).
        $tovabbi_dij = [];

        foreach ($uj ? [] : self::tovabbi_reszletek((int) $munkalap->id) as $reszlet) {
            $tovabbi_dij[(int) $reszlet->eszkoz_id] = $reszlet;
        }

        // A hibasorok eszközválasztója: 0 = a fő eszköz, utána a csatoltak.
        $hiba_eszkozok = [0 => '1. ' . ($eszkozok[$eszkoz_id] ?? 'fő eszköz')];

        foreach ($tovabbi as $i => $t_eszkoz) {
            $hiba_eszkozok[(int) $t_eszkoz->id] = ($i + 2) . '. ' . self::eszkoz_felirat($t_eszkoz);
        }

        ?>
        <input type="hidden" name="id" value="<?php echo (int) ($munkalap->id ?? 0); ?>">
        <input type="hidden" name="kontextus"
               value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
        <?php // Jelzi a mentésnek, hogy az űrlap a tételeket is hozza (lásd feldolgoz()). ?>
        <input type="hidden" name="tetelek_jelen" value="1">
        <?php // 1, ha a „Fizetve" pipát a fizetés visszavonásával vették le (app.js: fizetveLevetel). ?>
        <input type="hidden" name="fizetes_vissza" value="" data-sdh-fizetes-vissza>
        <?php wp_nonce_field('sdh_muhely_munkalap_mentes', 'sdh_nonce'); ?>

        <?php
        // A MunkaLap 3 „Munkalap szerkesztése" ablakának elrendezése: balra az
        // állapot, a dátumok, az összeg és a fizetés; jobbra az ügyfél és az
        // eszköz, alattuk lapfüleken az Eszköz, a Hibák, a Szolgáltatások és a
        // Termékek, legalul a megjegyzések. Az összegeket az app.js számolja
        // gépelés közben (munkalapSzamol); a mérvadó érték mentéskor a szerveré.
        ?>
        <?php // data-fo-kulon: a fő eszközre a díján felül befizetett rész – a díj számításából kimarad (app.js: munkalapSzamol). ?>
        <div class="sdh-ugyfelurlap sdh-munkalapurlap" data-sdh-munkalap
             data-fo-kulon="<?php echo esc_attr((string) ($uj ? 0 : (float) ($munkalap->fo_kulon ?? 0))); ?>"
             data-eloleg-mentett="<?php echo esc_attr((string) $fizetett); ?>"
             data-veg-ossz="<?php
                $veg_ossz = $uj ? 0.0 : (float) ($munkalap->fo_veg ?? 0);

                foreach ($tovabbi_dij as $veg_sor) {
                    $veg_ossz += (float) ($veg_sor->veg ?? 0);
                }

                echo esc_attr((string) $veg_ossz);
             ?>"
             data-fo-veg="<?php echo esc_attr((string) ($uj ? 0 : (float) ($munkalap->fo_veg ?? 0))); ?>"
             data-fizetve-mentett="<?php echo $fizetve ? '1' : '0'; ?>"
             data-bev-mentett="<?php echo !$uj && (int) ($munkalap->bevizsgalasi_dij ?? 0) === 1 ? '1' : '0'; ?>"
             data-kasszaban="<?php echo esc_attr((string) (!$uj && class_exists('SDH_Muhely_Penztar') ? SDH_Muhely_Penztar::munkalap_kasszaban((int) $munkalap->id, (int) $munkalap->munkalap_szam > 0 ? (string) (int) $munkalap->munkalap_szam : '', (string) ($munkalap->letrehozva ?? '')) : 0)); ?>"
             data-ma="<?php echo esc_attr(current_time('Y-m-d')); ?>">
            <div class="sdh-ml">
                <div class="sdh-ml__oldal">
                    <div class="sdh-ml__csoport">
                        <h3 class="sdh-ml__cim">Állapot</h3>

                        <?php
                        // Több eszköznél a lap állapota a KÖZÖS állapot: az eszközökéből adódik,
                        // itt átírva pedig minden eszköz megkapja.
                        $eszall = $uj ? '' : self::eszkoz_allapot_blokk($munkalap);
                        ?>
                        <div class="sdh-ig">
                            <label for="allapot"<?php echo $eszall !== '' ? ' title="A lap közös állapota: az eszközök állapotából adódik. Ha itt írod át, minden eszköz ezt kapja."' : ''; ?>><?php echo $eszall !== '' ? 'Közös állapot' : 'Állapot'; ?></label>
                            <div class="sdh-allapot-valaszto">
                                <span class="sdh-allapot-pont sdh-allapot--<?php
                                    echo esc_attr($allapotok[$allapot]['szin'] ?? 'szurke');
                                ?>" data-sdh-allapot-pont></span>
                                <select name="allapot" id="allapot" data-sdh-allapot-valaszto>
                                    <?php foreach ($allapotok as $kulcs => $a) : ?>
                                        <?php
                                        // A „Részben elkészült" több eszköznél magától áll be – kézzel nem választható.
                                        if ($eszall !== '' && !empty($a['reszben']) && $allapot !== (string) $kulcs) {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr((string) $kulcs); ?>"
                                                data-szin="<?php echo esc_attr($a['szin']); ?>"
                                            <?php selected($allapot, (string) $kulcs); ?>>
                                            <?php echo esc_html($a['nev']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (!isset($allapotok[$allapot])) : ?>
                                        <option value="<?php echo esc_attr($allapot); ?>"
                                                data-szin="szurke" selected>
                                            <?php echo esc_html($allapot); ?> (törölt állapot)
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <?php if ($eszall !== '') : ?>
                            <?php // Eszközönként saját állapot és a fizetés állása; a váltás azonnal mentődik (app.js: eszkozAllapotValt). ?>
                            <div class="sdh-ml__eszall">
                                <span class="sdh-ml__eszall-cim">Eszközönként</span>
                                <?php echo $eszall; // phpcs:ignore WordPress.Security.EscapeOutput -- a blokk már szűrt HTML. ?>
                            </div>
                        <?php endif; ?>

                        <div class="sdh-ig">
                            <label for="nev">Név</label>
                            <input type="text" name="nev" id="nev" maxlength="190"
                                   title="Rövid tárgy, pl. „kijelzőcsere” – nem kötelező"
                                   value="<?php echo esc_attr($uj ? '' : (string) $munkalap->nev); ?>">
                        </div>

                        <div class="sdh-ig">
                            <label for="felelos">Felelős</label>
                            <select name="felelos" id="felelos">
                                <option value="0">Nincs</option>
                                <?php foreach ($felelosok as $id => $nev) : ?>
                                    <option value="<?php echo (int) $id; ?>" <?php selected($felelos, $id); ?>>
                                        <?php echo esc_html($nev); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="sdh-ml__csoport">
                        <h3 class="sdh-ml__cim">Dátumok</h3>

                        <div class="sdh-ig">
                            <label for="keszult">Készült</label>
                            <?php self::datum_mezo('keszult', $keszult); ?>
                        </div>

                        <div class="sdh-ig">
                            <label for="hatarido">Határidő</label>
                            <?php self::datum_mezo('hatarido', $hatarido); ?>
                        </div>

                        <?php // Mindig jelen van: az űrlapon váltott állapot (app.js: urlapAllapotMent) helyben frissíti. ?>
                        <div class="sdh-ig sdh-ml__olvas" data-sdh-lezarva<?php echo $lezarva === '' ? ' hidden' : ''; ?>>
                            <span class="sdh-ig__cimke">Lezárva</span>
                            <span data-sdh-lezarva-ertek><?php echo esc_html(str_replace('-', '.', $lezarva)); ?></span>
                        </div>
                    </div>

                    <div class="sdh-ml__csoport">
                        <h3 class="sdh-ml__cim">Összeg</h3>

                        <div class="sdh-ml__osszeg">
                            <span>Nettó</span><output data-sdh-ossz="netto">0</output><span>Ft</span>
                        </div>
                        <div class="sdh-ml__osszeg">
                            <span>Áfa</span><output data-sdh-ossz="afa">0</output><span>Ft</span>
                        </div>
                        <div class="sdh-ml__osszeg sdh-ml__osszeg--fo">
                            <span>Bruttó</span><output data-sdh-ossz="brutto">0</output><span>Ft</span>
                        </div>
                    </div>

                    <div class="sdh-ml__csoport">
                        <h3 class="sdh-ml__cim">Tételek</h3>

                        <div class="sdh-ig">
                            <label for="lap_kedvezmeny">Kedvezmény</label>
                            <span class="sdh-szam sdh-szam--utotag">
                                <input type="number" name="lap_kedvezmeny" id="lap_kedvezmeny"
                                       step="1" min="0" max="100" data-sdh-lap-kedv
                                       title="Minden tételsorra érvényes; az új sorok is ezzel indulnak."
                                       value="<?php echo esc_attr(self::szam_mezobe($lap_kedv)); ?>">
                                <span class="sdh-szam__utotag" aria-hidden="true">%</span>
                            </span>
                        </div>

                        <div class="sdh-ig">
                            <label for="lap_afakulcs">Áfakulcs</label>
                            <select name="lap_afakulcs" id="lap_afakulcs" data-sdh-lap-afa
                                    title="Minden tételsorra érvényes; az új sorok is ezzel indulnak.">
                                <?php self::afa_opciok($afakulcsok, $lap_afa); ?>
                            </select>
                        </div>
                    </div>

                    <div class="sdh-ml__csoport">
                        <h3 class="sdh-ml__cim">Fizetés</h3>

                        <div class="sdh-ig">
                            <label for="fizetesi_mod">Fizetési mód</label>
                            <select name="fizetesi_mod" id="fizetesi_mod">
                                <option value="">Nincs</option>
                                <?php foreach ($fiz_modok as $mod) : ?>
                                    <option value="<?php echo esc_attr($mod); ?>" <?php selected($fiz_mod, $mod); ?>>
                                        <?php echo esc_html($mod); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sdh-ig">
                            <span class="sdh-ig__cimke"></span>
                            <label class="sdh-jelolo" for="fizetve">
                                <input type="checkbox" name="fizetve" id="fizetve" value="1" data-sdh-fizetve
                                    <?php checked($fizetve); ?>>
                                Fizetve
                            </label>
                        </div>

                        <div class="sdh-ig">
                            <label for="fizetes_ideje">Fizetés ideje</label>
                            <?php self::datum_mezo('fizetes_ideje', $fiz_ideje); ?>
                        </div>

                        <div class="sdh-ig">
                            <label for="fizetett" title="Előleg: a már befizetett összeg. Mindig levonódik a teljes összegből.">Fizetett (előleg)</label>
                            <span class="sdh-szam sdh-szam--utotag">
                                <input type="number" name="fizetett" id="fizetett"
                                       step="any" data-sdh-lepes="1000" min="0" data-sdh-fizetett
                                       title="Előleg: a már befizetett összeg (pl. bevizsgálási díj). Mindig levonódik a teljes összegből."
                                       value="<?php echo esc_attr(self::szam_mezobe($fizetett)); ?>">
                                <span class="sdh-szam__utotag" aria-hidden="true">Ft</span>
                            </span>
                        </div>

                        <?php
                        $bev_be   = !$uj && (int) ($munkalap->bevizsgalasi_dij ?? 0) === 1;
                        $bev_mod  = (string) ($munkalap->bevizsgalas_mod ?? 'auto') === 'marad' ? 'marad' : 'levon';
                        $bev_nev  = $uj ? '' : (string) ($munkalap->bevizsgalas_nev ?? '');
                        $bev_szlg = $uj ? 0 : (int) ($munkalap->bevizsgalas_szolg ?? 0);
                        ?>
                        <div class="sdh-ig sdh-bev" data-sdh-bev-doboz>
                            <span class="sdh-ig__cimke"></span>
                            <div class="sdh-bev__sor">
                                <label class="sdh-jelolo" for="bevizsgalasi_dij"
                                       title="Bepipálva az előleg bevizsgálási díj: külön tételként kerül a munkalapra és a számlára (pl. „Samsung SM-A175B mobiltelefon bevizsgálási díj”). Ha az ügyfél kéri a javítást, mínusz tételként levonódik a végösszegből; ha nem, munkadíjként marad.">
                                    <input type="checkbox" name="bevizsgalasi_dij" id="bevizsgalasi_dij" value="1" data-sdh-bevizsgalas
                                        <?php checked($bev_be); ?>>
                                    Bevizsgálási díj
                                </label>
                                <button type="button" class="sdh-bev__lista" data-sdh-bev-lista
                                        title="Díj választása a díjlistából" aria-label="Díj választása a díjlistából">
                                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2.5 4h11M2.5 8h11M2.5 12h7"/></svg>
                                    <span>Díjlista</span>
                                </button>
                            </div>
                            <input type="hidden" name="bevizsgalas_nev" data-sdh-bev-nev value="<?php echo esc_attr($bev_nev); ?>">
                            <input type="hidden" name="bevizsgalas_szolg" data-sdh-bev-szolg value="<?php echo (int) $bev_szlg; ?>">
                        </div>

                        <div class="sdh-ig sdh-bev__mod" data-sdh-bev-mod-doboz>
                            <span class="sdh-ig__cimke" title="Mi legyen a díjjal, ha az ügyfél kéri a javítást?">Ha javítás</span>
                            <div class="sdh-szegmens sdh-szegmens--kicsi" role="radiogroup" aria-label="A bevizsgálási díj sorsa">
                                <label class="sdh-szegmens__elem" title="Az ügyfél kéri a javítást: a díj mínusz tételként levonódik a végösszegből.">
                                    <input type="radio" name="bevizsgalas_mod" value="levon" data-sdh-bev-mod <?php checked($bev_mod, 'levon'); ?>>
                                    <span>Javítás: levonódik</span>
                                </label>
                                <label class="sdh-szegmens__elem" title="Az ügyfél nem kéri a javítást (vagy a díj nem számít be): a díj munkadíjként nálunk marad.">
                                    <input type="radio" name="bevizsgalas_mod" value="marad" data-sdh-bev-mod <?php checked($bev_mod, 'marad'); ?>>
                                    <span>Munkadíj marad</span>
                                </label>
                            </div>
                        </div>

                        <p class="sdh-bev__info" data-sdh-bev-info aria-live="polite"></p>

                        <div class="sdh-ml__osszeg sdh-ml__osszeg--fo">
                            <span>Fizetendő</span><output data-sdh-ossz="fizetendo">0</output><span>Ft</span>
                        </div>

                        <?php // Több eszköznél: eszközönként a végösszeg és a fizetendő (app.js: munkalapSzamol). ?>
                        <div class="sdh-ml__bontas" data-sdh-eszkoz-bontas aria-live="polite" hidden></div>
                    </div>
                </div>

                <div class="sdh-ml__fo">
                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <span class="sdh-ig__cimke">Ügyfél <span class="sdh-kotelezo">*</span></span>
                            <div class="sdh-mezo__sor">
                                <?php SDH_Muhely_Ugyfel::valaszto_mezo($ugyfel_id); ?>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--plusz"
                                        data-sdh-uj-ugyfel
                                        title="Új ügyfél felvétele" aria-label="Új ügyfél felvétele"><span class="sdh-plusz" aria-hidden="true"></span></button>
                            </div>
                        </div>

                        <div class="sdh-ig">
                            <label for="eszkoz_id">Eszköz <span class="sdh-kotelezo">*</span></label>
                            <div class="sdh-mezo__sor">
                                <select name="eszkoz_id" id="eszkoz_id" data-sdh-eszkoz-valaszto>
                                    <option value="0">
                                        <?php echo esc_html($ugyfel_id > 0 ? '— válassz eszközt —' : '— előbb válassz ügyfelet —'); ?>
                                    </option>
                                    <?php foreach ($eszkozok as $id => $felirat) : ?>
                                        <option value="<?php echo (int) $id; ?>" <?php selected($eszkoz_id, $id); ?>>
                                            <?php echo esc_html($felirat); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--plusz"
                                        data-sdh-uj-eszkoz
                                        title="Új eszköz felvétele" aria-label="Új eszköz felvétele"><span class="sdh-plusz" aria-hidden="true"></span></button>
                            </div>
                        </div>
                    </div>

                    <?php
                    // Egy munkalaphoz akárhány eszköz csatolható. A fő eszköz a fenti
                    // választó; a többi itt áll címkeként, sorrendben (app.js: tovabbi*).
                    ?>
                    <div class="sdh-ml-eszkozok" data-sdh-tovabbi-eszkozok>
                        <input type="hidden" name="eszkozok_jelen" value="1">
                        <span class="sdh-ml-eszkozok__cimke">További eszközök</span>
                        <div class="sdh-ml-eszkozok__lista" data-sdh-tovabbi-lista>
                            <?php foreach ($tovabbi as $t_eszkoz) : ?>
                                <?php self::tovabbi_cimke((string) (int) $t_eszkoz->id, self::eszkoz_felirat($t_eszkoz), $tovabbi_dij[(int) $t_eszkoz->id] ?? null); ?>
                            <?php endforeach; ?>
                            <span class="sdh-ml-eszkozok__ures" data-sdh-tovabbi-ures<?php echo $tovabbi !== [] ? ' hidden' : ''; ?>>
                                nincs – egy munkalapra akárhány eszköz felvehető
                            </span>
                        </div>
                        <template data-sdh-tovabbi-sablon><?php self::tovabbi_cimke('__ID__', '', null); ?></template>
                        <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-ml-eszkozok__gomb" data-sdh-tovabbi-uj
                                title="Még egy eszköz erre a munkalapra: az ügyfél meglévő készülékei közül, vagy új felvitelével">
                            <span class="sdh-plusz" aria-hidden="true"></span>Eszköz csatolása
                        </button>
                    </div>

                    <div class="sdh-fulek sdh-ml__fulek">
                        <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--1" name="_ful_munkalap"
                               id="ful_m_eszkoz" checked>
                        <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--2" name="_ful_munkalap"
                               id="ful_m_hibak">
                        <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--3" name="_ful_munkalap"
                               id="ful_m_szolg">
                        <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--4" name="_ful_munkalap"
                               id="ful_m_termek">
                        <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--5" name="_ful_munkalap"
                               id="ful_m_rma" data-sdh-ful-kulcs="rma">

                        <div class="sdh-fulek__sav">
                            <label for="ful_m_eszkoz">Eszköz <span class="sdh-fulek__db" data-sdh-db="eszkoz" hidden></span></label>
                            <label for="ful_m_hibak">Hibák <span class="sdh-fulek__db" data-sdh-db="hibak" hidden></span></label>
                            <label for="ful_m_szolg">Szolgáltatások <span class="sdh-fulek__db" data-sdh-db="szolgaltatas" hidden></span></label>
                            <label for="ful_m_termek">Termékek <span class="sdh-fulek__db" data-sdh-db="termek" hidden></span></label>
                            <label for="ful_m_rma" data-sdh-rma-ful>RMA <?php echo $munkalap ? SDH_Muhely_Rma::ful_jelveny((int) $munkalap->id) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
                        </div>

                        <div class="sdh-fulek__panel sdh-fulek__panel--1" data-sdh-osszefoglalo>
                            <?php self::osszefoglalo($ugyfel_id, $eszkoz_id, array_map(static fn (object $e): int => (int) $e->id, $tovabbi)); ?>
                        </div>

                        <div class="sdh-fulek__panel sdh-fulek__panel--2" data-sdh-hibak
                             data-sdh-gyakori="<?php echo esc_attr((string) wp_json_encode(self::gyakori_hibak(), JSON_UNESCAPED_UNICODE)); ?>">
                            <div class="sdh-hibafej" aria-hidden="true">
                                <span class="sdh-hibafej__eszkoz">Eszköz</span>
                                <span>Hiba</span>
                                <span>Állapot</span>
                                <span>Javítás / megjegyzés</span>
                                <span></span>
                            </div>

                            <div class="sdh-hibasorok" data-sdh-hibasorok
                                 data-kovetkezo="<?php echo (int) count($hibak); ?>">
                                <?php foreach ($hibak as $i => $hiba) : ?>
                                    <?php self::hibasor_sor((string) $i, $hiba, $hiba_allapotok, $hiba_eszkozok); ?>
                                <?php endforeach; ?>
                            </div>

                            <template data-sdh-hibasor-sablon>
                                <?php self::hibasor_sor('__I__', null, $hiba_allapotok, $hiba_eszkozok); ?>
                            </template>

                            <div class="sdh-hibalab">
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-hibasor-uj>
                                    <span class="sdh-plusz" aria-hidden="true"></span>Hiba
                                </button>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-gyakori-hibak
                                        title="A leggyakoribb hibák listája: egy kattintással felveszi a munkalapra">
                                    Gyakori hibák
                                </button>
                                <span class="sdh-mezo__sugo">
                                    Minden hibának saját állapota van. Az üresen hagyott sor mentéskor eldobódik.
                                </span>
                            </div>
                        </div>

                        <div class="sdh-fulek__panel sdh-fulek__panel--3">
                            <?php self::tetel_szerkeszto('szolgaltatas', $szolg, $afakulcsok, $lap_afa, $hiba_eszkozok); ?>
                        </div>

                        <div class="sdh-fulek__panel sdh-fulek__panel--4">
                            <?php self::tetel_szerkeszto('termek', $termekek, $afakulcsok, $lap_afa, $hiba_eszkozok); ?>
                        </div>

                        <div class="sdh-fulek__panel sdh-fulek__panel--5 sdh-fulek__panel--rma">
                            <?php SDH_Muhely_Rma::munkalap_ful($munkalap); ?>
                        </div>
                    </div>

                    <div class="sdh-fulek sdh-fulek--also">
                        <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--1" name="_ful_also_m"
                               id="ful_m_megjegyzes" checked>
                        <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--2" name="_ful_also_m"
                               id="ful_m_belso">

                        <div class="sdh-fulek__sav">
                            <label for="ful_m_megjegyzes">Megjegyzés<?php
                                echo trim($ugyfel_megjegyzes) !== '' ? ' <span class="sdh-fulek__db">!</span>' : '';
                            ?></label>
                            <label for="ful_m_belso">Belső megjegyzés<?php
                                echo trim($megjegyzes) !== '' ? ' <span class="sdh-fulek__db">!</span>' : '';
                            ?></label>
                        </div>

                        <div class="sdh-fulek__panel sdh-fulek__panel--1">
                            <textarea name="ugyfel_megjegyzes" id="ugyfel_megjegyzes" aria-label="Megjegyzés"
                                      placeholder="Az ügyfél felé is megjelenhet (pl. nyomtatványon)."><?php
                                echo esc_textarea($ugyfel_megjegyzes);
                            ?></textarea>
                        </div>

                        <div class="sdh-fulek__panel sdh-fulek__panel--2">
                            <textarea name="megjegyzes" id="megjegyzes" aria-label="Belső megjegyzés"
                                      placeholder="Csak a CRM-ben látszik."><?php
                                echo esc_textarea($megjegyzes);
                            ?></textarea>
                            <span class="sdh-zar">
                                Belső: az ügyfél nem látja, nyomtatványra nem kerül ki.
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">
                    <?php echo $uj ? 'Munkalap létrehozása' : 'Mentés'; ?>
                </button>

                <?php if ($modal) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                <?php else : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(self::url()); ?>">Mégsem</a>
                <?php endif; ?>

                <?php if ($uj) : ?>
                    <span class="sdh-mezo__sugo">
                        A munkalapszám az első számozott állapotba lépéskor generálódik; ahhoz ügyfél és eszköz kell.
                    </span>
                <?php endif; ?>

                <?php // Fizetés (és pénztárba írás): mentett lapnál (SDH_Muhely_Penztar, penztar.js). ?>
                <?php if (!$uj && $modal && class_exists('SDH_Muhely_Penztar')) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-pt-fizetes-gomb<?php echo (int) ($munkalap->fizetve ?? 0) === 1 ? ' is-fizetve' : ''; ?>"
                            data-sdh-penztar-fizetes title="Fizetés rögzítése, és (ha kéred) beírás a házipénztárba">
                        <?php echo (int) ($munkalap->fizetve ?? 0) === 1 ? 'Fizetve ✓' : 'Fizetés'; ?>
                    </button>
                <?php endif; ?>

                <?php // Számlázás: csak mentett, sorszámot kapott lapnál (SDH_Muhely_Szamla). ?>
                <?php if (!$uj && $modal && (int) $munkalap->munkalap_szam > 0 && class_exists('SDH_Muhely_Szamla')) : ?>
                    <?php SDH_Muhely_Szamla::munkalap_gombok($munkalap); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Dátummező saját naptárral (app.js: data-sdh-pop="datum") – ugyanaz,
     * mint az eszközűrlapon; a böngésző natív dátumválasztója helyett.
     * Az érték ISO alakú (éééé-hh-nn) vagy üres.
     */
    private static function datum_mezo(string $nev, string $ertek): void
    {
        ?>
        <input type="text" name="<?php echo esc_attr($nev); ?>" id="<?php echo esc_attr($nev); ?>"
               class="sdh-pop sdh-pop--datum" readonly autocomplete="off"
               data-sdh-pop="datum" data-sdh-pop-adat="[]"
               placeholder="éééé-hh-nn"
               value="<?php echo esc_attr($ertek); ?>">
        <?php
    }

    /** Szám az űrlapmezőbe: fölösleges tizedesek nélkül, ponttal (13000, 2.5). */
    private static function szam_mezobe(float $ertek): string
    {
        $szoveg = rtrim(rtrim(number_format($ertek, 3, '.', ''), '0'), '.');

        return $szoveg === '' ? '0' : $szoveg;
    }

    /**
     * Az áfakulcs-választó elemei. A százalék data-attribútumban utazik,
     * mert a böngészőoldali számolás (app.js) abból dolgozik.
     *
     * @param array<string, array{nev: string, szazalek: float}> $afakulcsok
     */
    private static function afa_opciok(array $afakulcsok, string $kivalasztott): void
    {
        foreach ($afakulcsok as $kulcs => $adat) {
            printf(
                '<option value="%s" data-szazalek="%s"%s>%s</option>',
                esc_attr((string) $kulcs),
                esc_attr((string) $adat['szazalek']),
                selected($kivalasztott, (string) $kulcs, false),
                esc_html($adat['nev'])
            );
        }
    }

    /* =================================================================
     * Űrlap – Eszköz lapfül (az eszköz és az ügyfél összefoglalója)
     * ============================================================== */

    /**
     * A kiválasztott eszköz és ügyfél adatai, ahogy a MunkaLap 3 „Eszköz"
     * lapfüle mutatja. Csak olvasható: szerkeszteni a saját űrlapjukon lehet.
     */
    private static function osszefoglalo(int $ugyfel_id, int $eszkoz_id, array $tovabbi_idk = []): void
    {
        global $wpdb;

        $ugyfel = $ugyfel_id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE id = %d', $ugyfel_id))
            : null;

        // Elöl a fő eszköz, utána a csatoltak – mindegyik saját kártyát kap.
        $mind    = self::eszkoz_sorok(array_merge([$eszkoz_id], $tovabbi_idk));
        $eszkoz  = null;
        $tovabbi = [];

        foreach ($mind as $egy) {
            if ((int) $egy->id === $eszkoz_id && $eszkoz === null) {
                $eszkoz = $egy;
            } else {
                $tovabbi[] = $egy;
            }
        }

        $sor = static function (string $cimke, string $ertek): void {
            printf(
                '<div class="sdh-ml-adat__sor"><dt>%s:</dt><dd>%s%s</dd></div>',
                esc_html($cimke),
                $ertek !== '' ? esc_html($ertek) : '<span class="sdh-ml-adat__ures">—</span>',
                SDH_Muhely_Admin_UI::masolo_gomb($ertek, $cimke) // phpcs:ignore WordPress.Security.EscapeOutput -- a gomb szűrt HTML.
            );
        };

        $eszkoz_adatok = static function (object $e) use ($sor): void {
            echo '<dl class="sdh-ml-adat">';
            $sor('Azonosító', SDH_Muhely_Eszkoz::kategoria_cimke((string) $e->kategoria));
            $sor('Gyártó', (string) $e->gyarto);
            $sor('Típus', (string) $e->tipus);
            $sor('Megnevezés', (string) $e->megnevezes);
            $sor('Készülék szín', (string) $e->szin);
            $sor('Garancia', (int) $e->garancias === 1 ? 'Igen' : 'Nem');
            $sor('IMEI szám', (string) $e->imei);
            $sor('Sorozatszám', (string) $e->sorozatszam);
            $sor('Tartozékok', (string) $e->tartozekok);
            echo '</dl>';
        };

        $tobb = $tovabbi !== [];

        echo '<div class="sdh-ml-kartya"><div class="sdh-ml-kartya__fej"><strong>' . ($tobb ? '1. eszköz' : 'Eszköz') . '</strong>';

        if ($eszkoz) {
            echo '<span>Sorszám: ' . (int) $eszkoz->id . '</span>';
        }

        echo '</div>';

        if ($eszkoz) {
            $eszkoz_adatok($eszkoz);
        } else {
            echo '<p class="sdh-ml-kartya__ures">'
                . esc_html($ugyfel ? 'Válassz eszközt a fenti mezőben – az adatai itt jelennek meg.' : 'Előbb válassz ügyfelet, utána eszközt.')
                . '</p>';
        }

        echo '</div>';

        // A csatolt eszközök tömören, soronként egy: akárhány lehet, a popup
        // ettől ne nőjön a képernyő fölé. A részletes adatlap a kezdőképernyő
        // Eszközök lapfülén és az eszköz saját űrlapján van.
        if ($tobb) {
            echo '<div class="sdh-ml-kartya"><div class="sdh-ml-kartya__fej"><strong>További eszközök</strong>'
                . '<span>' . count($tovabbi) . ' db</span></div><ol class="sdh-ml-tobbi" start="2">';

            foreach ($tovabbi as $egy) {
                $reszek = [];

                foreach ([
                    'IMEI'        => (string) $egy->imei,
                    'Sorozatszám' => (string) $egy->sorozatszam,
                    'Szín'        => (string) $egy->szin,
                    'Tartozékok'  => (string) $egy->tartozekok,
                ] as $cimke => $ertek) {
                    if (trim($ertek) !== '') {
                        $reszek[] = '<span class="sdh-ml-tobbi__adat"><em>' . esc_html($cimke) . ':</em> ' . esc_html($ertek)
                            . SDH_Muhely_Admin_UI::masolo_gomb($ertek, $cimke) . '</span>';
                    }
                }

                $nev = SDH_Muhely_Eszkoz::megnevezes($egy);

                echo '<li><strong>' . esc_html($nev) . '</strong>' . SDH_Muhely_Admin_UI::masolo_gomb($nev, 'Típus') // phpcs:ignore WordPress.Security.EscapeOutput
                    . ' <span class="sdh-ml-tobbi__fajta">' . esc_html(SDH_Muhely_Eszkoz::kategoria_cimke((string) $egy->kategoria)) . '</span>'
                    . implode('', $reszek) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- a részek szűrt HTML.
            }

            echo '</ol></div>';
        }

        echo '<div class="sdh-ml-kartya"><div class="sdh-ml-kartya__fej"><strong>Ügyfél</strong>';

        if ($ugyfel) {
            echo '<span>Sorszám: ' . esc_html((string) $ugyfel->ugyfel_szam !== '' ? (string) $ugyfel->ugyfel_szam : (string) (int) $ugyfel->id) . '</span>';
        }

        echo '</div>';

        if ($ugyfel) {
            $cim = SDH_Muhely_Ugyfel::cim_szoveg($ugyfel);

            echo '<dl class="sdh-ml-adat">';
            $sor('Név', (string) $ugyfel->nev);
            $sor('Kategória', (string) $ugyfel->kategoria);
            $sor('Cím', $cim);
            $sor('Telefonszám', implode(', ', array_filter([(string) $ugyfel->telefon, (string) $ugyfel->telefon2])));
            $sor('E-mail', (string) $ugyfel->email);

            if ((float) $ugyfel->kedvezmeny > 0) {
                $sor('Kedvezmény', rtrim(rtrim(number_format((float) $ugyfel->kedvezmeny, 2, ',', ''), '0'), ',') . '%');
            }

            echo '</dl>';
        } else {
            echo '<p class="sdh-ml-kartya__ures">Válassz ügyfelet a fenti mezőben – az adatai itt jelennek meg.</p>';
        }

        echo '</div>';
    }

    /** Az Eszköz lapfül tartalma az ügyfél vagy az eszköz megváltozása után (AJAX). */
    public static function ajax_osszefoglalo(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            status_header(403);
            wp_die();
        }

        self::osszefoglalo(
            isset($_GET['ugyfel_id']) ? (int) $_GET['ugyfel_id'] : 0,
            isset($_GET['eszkoz_id']) ? (int) $_GET['eszkoz_id'] : 0,
            // A csatolt eszközök azonosítói vesszővel (app.js: munkalapOsszefoglalo).
            isset($_GET['tovabbi'])
                ? array_filter(array_map('intval', explode(',', sanitize_text_field(wp_unslash($_GET['tovabbi'])))))
                : []
        );

        wp_die();
    }

    /* =================================================================
     * Űrlap – tételek (Szolgáltatások és Termékek lapfül)
     * ============================================================== */

    /**
     * Egy tételtípus szerkesztője: oszlopfej, sorok, összesítő, új sor gomb.
     * A termékeknél három azonosító oszlop is van (termékkód, cikkszám,
     * gyári szám); a szolgáltatásoknál ezek hiányoznak.
     *
     * @param array<int, object>                                 $tetelek
     * @param array<string, array{nev: string, szazalek: float}> $afakulcsok
     */
    private static function tetel_szerkeszto(string $tipus, array $tetelek, array $afakulcsok, string $lap_afa, array $eszkozok = [0 => '1. fő eszköz']): void
    {
        $termek = $tipus === 'termek';
        $sorok  = $tetelek === [] ? [null] : $tetelek;

        ?>
        <div class="sdh-tetelek sdh-tetelek--<?php echo esc_attr($tipus); ?>"
             data-sdh-tetelek="<?php echo esc_attr($tipus); ?>"
             data-kovetkezo="<?php echo (int) count($sorok); ?>">
            <div class="sdh-tetelfej" aria-hidden="true">
                <span>Sorszám</span>
                <span class="sdh-tetelfej__eszkoz">Eszköz</span>
                <span>Megnevezés</span>
                <?php if ($termek) : ?>
                    <span>Termékkód</span>
                    <span>Cikkszám</span>
                    <span>Gyári szám</span>
                <?php endif; ?>
                <span class="is-jobb">Menny.</span>
                <span>M.e.</span>
                <span class="is-jobb">Bruttó ár</span>
                <span class="is-jobb">Kedv. %</span>
                <span>Áfak.</span>
                <span class="is-jobb">Nettó é.</span>
                <span class="is-jobb">Bruttó é.</span>
                <span></span>
            </div>

            <div class="sdh-tetelsorok" data-sdh-tetelsorok>
                <?php foreach ($sorok as $i => $tetel) : ?>
                    <?php self::tetel_sor($tipus, (string) $i, $tetel, $afakulcsok, $lap_afa, $eszkozok); ?>
                <?php endforeach; ?>
            </div>

            <template data-sdh-tetel-sablon>
                <?php self::tetel_sor($tipus, '__I__', null, $afakulcsok, $lap_afa, $eszkozok); ?>
            </template>

            <div class="sdh-tetelsor sdh-tetelsor--ossz">
                <span></span>
                <span class="sdh-tetelfej__eszkoz"></span>
                <span>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-tetel-uj
                            title="<?php echo $termek ? 'Termék választása a készletből' : 'Szolgáltatás választása a listából'; ?>">
                        <span class="sdh-plusz" aria-hidden="true"></span><?php echo $termek ? 'Termék' : 'Szolgáltatás'; ?>
                    </button>
                </span>
                <?php if ($termek) : ?>
                    <span></span>
                    <span></span>
                    <span></span>
                <?php endif; ?>
                <output class="is-jobb" data-sdh-tetel-ossz="menny" title="Mennyiség összesen">0</output>
                <span></span>
                <span></span>
                <span></span>
                <span class="is-jobb sdh-tetelsor__szumma">Σ</span>
                <output class="is-jobb" data-sdh-tetel-ossz="netto" title="Nettó érték összesen">0</output>
                <output class="is-jobb" data-sdh-tetel-ossz="brutto" title="Bruttó érték összesen">0</output>
                <span></span>
            </div>
        </div>
        <?php
    }

    /**
     * Egy tételsor. Az ár bruttó egységár; a nettó és a bruttó értéket az
     * app.js írja ki gépelés közben, mentéskor a szerver számolja újra.
     *
     * @param array<string, array{nev: string, szazalek: float}> $afakulcsok
     */
    private static function tetel_sor(string $tipus, string $index, ?object $tetel, array $afakulcsok, string $lap_afa, array $eszkozok = [0 => '1. fő eszköz']): void
    {
        $elotag = 'tetelek[' . $tipus . '][' . $index . ']';
        $termek = $tipus === 'termek';
        $ert    = static fn (string $mezo): string => $tetel !== null ? (string) ($tetel->{$mezo} ?? '') : '';
        $afa    = $tetel !== null
            ? SDH_Muhely_Tetel::afakulcs_ervenyes((string) ($tetel->afa_kulcs ?? ''))
            : $lap_afa;
        $kedv   = $tetel !== null ? (float) $tetel->kedvezmeny : 0.0;

        ?>
        <?php
        $bevizsgalas = $tetel !== null && (string) ($tetel->forras ?? '') === 'bevizsgalas';
        $szamlazva   = $tetel !== null ? (string) ($tetel->szamla ?? '') : '';
        // A díj levonása (mínusz sor): csak olvasható, nem küldődik be – a
        // bevizsgálási díj szinkronja tartja karban (SDH_Muhely_Szamla).
        $levonas     = $tetel !== null && (string) ($tetel->forras ?? '') === 'bevizsgalas_le';
        $csak_olvas  = $levonas ? ' disabled' : '';
        // Melyik eszköz tétele (0 = a fő eszközé); ami már nincs a lapon, az a fő eszközé.
        $tetel_eszkoz = $tetel !== null && isset($eszkozok[(int) ($tetel->eszkoz_id ?? 0)]) ? (int) $tetel->eszkoz_id : 0;
        ?>
        <div class="sdh-tetelsor<?php echo $szamlazva !== '' ? ' is-szamlazva' : ''; ?><?php echo $levonas ? ' sdh-tetelsor--levonas' : ''; ?>" data-sdh-tetelsor
            <?php echo $bevizsgalas ? 'data-sdh-bev' . ($szamlazva !== '' ? '="szamlazva"' : '') . ' data-sdh-bev-eszkoz="' . (int) ($tetel->eszkoz_id ?? 0) . '"' : ''; ?>
            <?php echo $levonas ? 'data-sdh-bevle' . ($szamlazva !== '' ? '="szamlazva"' : '') . ' data-sdh-bev-eszkoz="' . (int) ($tetel->eszkoz_id ?? 0) . '"' : ''; ?>>
            <input type="hidden" name="<?php echo esc_attr($elotag); ?>[id]"<?php echo $csak_olvas; ?>
                   value="<?php echo (int) ($tetel->id ?? 0); ?>">

            <span class="sdh-tetelsor__sorszam"
                <?php echo $szamlazva !== '' ? 'title="Számlázva: ' . esc_attr($szamlazva) . '"' : ($bevizsgalas ? 'title="Bevizsgálási díj – az előlegből (a „Bevizsgálási díj” jelölő tartja karban)"' : ($levonas ? 'title="A bevizsgálási díj levonása a végösszegből"' : '')); ?>><?php
                echo $tetel !== null ? (int) $tetel->id : 'új';
                echo $szamlazva !== '' ? ' ✓' : '';
            ?></span>

            <?php
            // Csak több eszköznél látszik; a listáját az app.js tartja frissen. A díj és a
            // levonás sora az eszközéhez kötött: ott a választó csak mutatja, nem küldődik be.
            ?>
            <select name="<?php echo esc_attr($elotag); ?>[eszkoz_id]" class="sdh-tetelsor__eszkoz"
                    data-sdh-tetel-eszkoz aria-label="Melyik eszköz tétele"<?php echo ($bevizsgalas || $levonas) ? ' disabled' : ''; ?>>
                <?php foreach ($eszkozok as $e_id => $e_nev) : ?>
                    <option value="<?php echo (int) $e_id; ?>" <?php selected($tetel_eszkoz, (int) $e_id); ?>>
                        <?php echo esc_html($e_nev); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($termek) : ?>
                <?php // A sor végi gomb a termékválasztó popupot nyitja (app.js termValasztoNyit). ?>
                <span class="sdh-szolgmezo">
                    <input type="hidden" name="<?php echo esc_attr($elotag); ?>[termek_id]" data-sdh-termek-id
                           value="<?php echo (int) ($tetel->termek_id ?? 0); ?>">
                    <input type="text" name="<?php echo esc_attr($elotag); ?>[megnevezes]" maxlength="255"
                           aria-label="Megnevezés" autocomplete="off" data-sdh-termek
                           placeholder="Válassz a készletből (jobbra), vagy írd be"
                           value="<?php echo esc_attr($ert('megnevezes')); ?>">
                    <button type="button" class="sdh-szolgmezo__gomb" data-sdh-termek-valaszt
                            title="Termék választása a készletből" aria-label="Termék választása a készletből">
                        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2.5 4h11M2.5 8h11M2.5 12h7"/></svg>
                    </button>
                </span>
            <?php else : ?>
                <?php // A sor végi gomb a szolgáltatás-választó popupot nyitja (app.js szolgValasztoNyit). ?>
                <span class="sdh-szolgmezo">
                    <input type="text" name="<?php echo esc_attr($elotag); ?>[megnevezes]" maxlength="255"
                           aria-label="Megnevezés" autocomplete="off" data-sdh-szolg<?php echo $csak_olvas; ?>
                           placeholder="Válassz a listából (jobbra), vagy írd be"
                           value="<?php echo esc_attr($ert('megnevezes')); ?>">
                    <button type="button" class="sdh-szolgmezo__gomb" data-sdh-szolg-valaszt<?php echo $csak_olvas; ?>
                            title="Szolgáltatás választása a listából" aria-label="Szolgáltatás választása a listából">
                        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2.5 4h11M2.5 8h11M2.5 12h7"/></svg>
                    </button>
                </span>
            <?php endif; ?>

            <?php if ($termek) : ?>
                <input type="text" name="<?php echo esc_attr($elotag); ?>[termekkod]" maxlength="60"
                       aria-label="Termékkód" value="<?php echo esc_attr($ert('termekkod')); ?>">
                <input type="text" name="<?php echo esc_attr($elotag); ?>[cikkszam]" maxlength="60"
                       aria-label="Cikkszám" value="<?php echo esc_attr($ert('cikkszam')); ?>">
                <input type="text" name="<?php echo esc_attr($elotag); ?>[gyari_szam]" maxlength="60"
                       aria-label="Gyári szám" value="<?php echo esc_attr($ert('gyari_szam')); ?>">
            <?php endif; ?>

            <input type="text" inputmode="decimal" class="is-jobb" data-sdh-tetel="menny"<?php echo $csak_olvas; ?>
                   name="<?php echo esc_attr($elotag); ?>[mennyiseg]" aria-label="Mennyiség"
                   value="<?php echo esc_attr($tetel !== null ? self::szam_mezobe((float) $tetel->mennyiseg) : '1'); ?>">

            <input type="text" name="<?php echo esc_attr($elotag); ?>[me]" maxlength="20"
                   aria-label="Mennyiségi egység" data-sdh-tetel="me"<?php echo $csak_olvas; ?>
                   value="<?php echo esc_attr($tetel !== null ? (string) $tetel->me : 'db'); ?>">

            <input type="text" inputmode="decimal" class="is-jobb" data-sdh-tetel="ar"<?php echo $csak_olvas; ?>
                   name="<?php echo esc_attr($elotag); ?>[brutto_ar]" aria-label="Bruttó egységár"
                   placeholder="0"
                   value="<?php echo esc_attr($tetel !== null ? self::szam_mezobe((float) $tetel->brutto_ar) : ''); ?>">

            <input type="text" inputmode="decimal" class="is-jobb" data-sdh-tetel="kedv"<?php echo $csak_olvas; ?>
                   name="<?php echo esc_attr($elotag); ?>[kedvezmeny]" aria-label="Kedvezmény százalékban"
                   placeholder="0"
                   value="<?php echo esc_attr($kedv > 0 ? self::szam_mezobe($kedv) : ''); ?>">

            <select name="<?php echo esc_attr($elotag); ?>[afa_kulcs]" data-sdh-tetel="afa" aria-label="Áfakulcs"<?php echo $csak_olvas; ?>>
                <?php self::afa_opciok($afakulcsok, $afa); ?>
            </select>

            <output class="is-jobb" data-sdh-tetel="netto">0</output>
            <output class="is-jobb" data-sdh-tetel="brutto">0</output>

            <?php if ($levonas) : ?>
                <span class="sdh-tetelsor__zar" title="A bevizsgálási díj levonása – a „Bevizsgálási díj” jelölő és a „Ha javítás” választó tartja karban">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><rect x="3.5" y="7" width="9" height="6.5" rx="1.2"/><path d="M5.5 7V5.2a2.5 2.5 0 0 1 5 0V7"/></svg>
                </span>
            <?php else : ?>
            <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-hibasor__torol"
                    data-sdh-tetel-torol title="Tétel eltávolítása"
                    aria-label="Tétel eltávolítása">&times;</button>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Egy hibasor az űrlapban: hiba, állapot, javítás egy sorban. A mezők
     * neve az oszlopfejben áll (.sdh-hibafej), a sorokban csak a mezők.
     *
     * Ugyanez a függvény rajzolja a meglévő sorokat és a JavaScript által
     * klónozott sablont (az indexe ott `__I__`), hogy a kettő ne csússzon szét.
     *
     * @param array<string, array{nev: string, szin: string, szamozott: bool, zart: bool}> $allapotok
     * @param array<int, string> $eszkozok A lap eszközei a választóhoz (0 = a fő eszköz).
     */
    private static function hibasor_sor(string $index, ?object $hiba, array $allapotok, array $eszkozok = [0 => '1. fő eszköz']): void
    {
        $nev_elotag = 'hibak[' . $index . ']';
        $allapot    = $hiba !== null ? (string) $hiba->allapot : (string) (array_key_first($allapotok) ?? 'uj');
        // Melyik eszköz hibája: 0 = a fő eszközé; ami már nincs a lapon, az is a fő eszközhöz kerül.
        $hiba_eszkoz = $hiba !== null && isset($eszkozok[(int) ($hiba->eszkoz_id ?? 0)]) ? (int) $hiba->eszkoz_id : 0;

        ?>
        <div class="sdh-hibasor" data-sdh-hibasor>
            <input type="hidden" name="<?php echo esc_attr($nev_elotag); ?>[id]"
                   value="<?php echo (int) ($hiba->id ?? 0); ?>">

            <?php // Csak több eszköznél látszik (CSS: .is-tobb-eszkoz); a listáját az app.js tartja frissen. ?>
            <select name="<?php echo esc_attr($nev_elotag); ?>[eszkoz_id]" class="sdh-hibasor__eszkoz"
                    data-sdh-hiba-eszkoz aria-label="Melyik eszköz hibája">
                <?php foreach ($eszkozok as $e_id => $e_nev) : ?>
                    <option value="<?php echo (int) $e_id; ?>" <?php selected($hiba_eszkoz, (int) $e_id); ?>>
                        <?php echo esc_html($e_nev); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="<?php echo esc_attr($nev_elotag); ?>[leiras]" maxlength="255"
                   class="sdh-hibasor__leiras" aria-label="Hiba" placeholder="Pl. törött kijelző"
                   value="<?php echo esc_attr($hiba !== null ? (string) $hiba->leiras : ''); ?>">

            <select name="<?php echo esc_attr($nev_elotag); ?>[allapot]" class="sdh-hibasor__allapot"
                    aria-label="A hiba állapota">
                <?php foreach ($allapotok as $kulcs => $a) : ?>
                    <option value="<?php echo esc_attr((string) $kulcs); ?>"
                            data-szin="<?php echo esc_attr($a['szin']); ?>"
                        <?php selected($allapot, (string) $kulcs); ?>>
                        <?php echo esc_html($a['nev']); ?>
                    </option>
                <?php endforeach; ?>
                <?php if (!isset($allapotok[$allapot])) : ?>
                    <option value="<?php echo esc_attr($allapot); ?>" selected>
                        <?php echo esc_html($allapot); ?> (törölt állapot)
                    </option>
                <?php endif; ?>
            </select>

            <input type="text" name="<?php echo esc_attr($nev_elotag); ?>[javitas]"
                   class="sdh-hibasor__javitas" aria-label="Javítás / megjegyzés"
                   placeholder="Mit csináltunk vele"
                   value="<?php echo esc_attr($hiba !== null ? (string) $hiba->javitas : ''); ?>">

            <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-hibasor__torol"
                    data-sdh-hibasor-torol title="Hibasor eltávolítása"
                    aria-label="Hibasor eltávolítása">&times;</button>
        </div>
        <?php
    }

    /**
     * Egy csatolt (további) eszköz címkéje az űrlapon: rejtett azonosító,
     * felirat, levétel gomb. Ugyanez rajzolja a meglévőket és a JavaScript
     * által klónozott sablont (azonosító 0, üres felirat).
     */
    private static function tovabbi_cimke(string $eszkoz_id, string $felirat, ?object $reszlet): void
    {
        // A sablonban az azonosító helyén `__ID__` áll: a JavaScript írja be klónozáskor.
        $dij = $reszlet !== null ? (float) $reszlet->bev_dij : 0.0;

        ?>
        <span class="sdh-ml-eszkoz" data-sdh-tovabbi="<?php echo esc_attr($eszkoz_id); ?>"
              data-veg="<?php echo esc_attr((string) ($reszlet !== null ? (float) ($reszlet->veg ?? 0) : 0)); ?>"
              data-kulon="<?php echo esc_attr((string) ($reszlet !== null ? (float) ($reszlet->fizetett ?? 0) : 0)); ?>">
            <input type="hidden" name="tovabbi_eszkoz[]" value="<?php echo esc_attr($eszkoz_id); ?>">
            <span class="sdh-ml-eszkoz__nev" data-sdh-tovabbi-nev><?php echo esc_html($felirat); ?></span>
            <span class="sdh-ml-eszkoz__dij"
                  title="Ennek az eszköznek a bevizsgálási díja. Külön tételként kerül a munkalapra és a számlára, és beleszámít az előlegbe.">
                <label>díj
                    <input type="text" inputmode="decimal" autocomplete="off" data-sdh-tovabbi-dij
                           name="tovabbi_dij[<?php echo esc_attr($eszkoz_id); ?>]" placeholder="0"
                           aria-label="Az eszköz bevizsgálási díja (Ft)"
                           value="<?php echo $dij > 0 ? esc_attr(self::szam_mezobe($dij)) : ''; ?>"></label>
                <span aria-hidden="true">Ft</span>
                <button type="button" class="sdh-ml-eszkoz__lista" data-sdh-tovabbi-dijlista
                        title="Díj választása a díjlistából" aria-label="Díj választása a díjlistából">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2.5 4h11M2.5 8h11M2.5 12h7"/></svg>
                </button>
            </span>
            <input type="hidden" name="tovabbi_dij_nev[<?php echo esc_attr($eszkoz_id); ?>]" data-sdh-tovabbi-dijnev
                   value="<?php echo esc_attr($reszlet !== null ? (string) $reszlet->bev_nev : ''); ?>">
            <input type="hidden" name="tovabbi_dij_szolg[<?php echo esc_attr($eszkoz_id); ?>]" data-sdh-tovabbi-dijszolg
                   value="<?php echo (int) ($reszlet !== null ? $reszlet->bev_szolg : 0); ?>">
            <button type="button" class="sdh-ml-eszkoz__torol" data-sdh-tovabbi-torol
                    title="Levétel a munkalapról (az eszköz megmarad az ügyfélnél)"
                    aria-label="Eszköz levétele a munkalapról">&times;</button>
        </span>
        <?php
    }

    /**
     * Melyik felületen készül az űrlap.
     *
     * AJAX-hívásnál a kontextus nem állapítható meg magától – a
     * JavaScript küldi el, mert ő tudja, honnan nyitották a popupot.
     */
    private static function kontextus_ertek(): string
    {
        if (wp_doing_ajax() && isset($_REQUEST['kontextus'])) {
            return sanitize_key(wp_unslash($_REQUEST['kontextus'])) === 'frontend' ? 'frontend' : 'admin';
        }

        return SDH_Muhely_Modulok::kontextus();
    }

    /* =================================================================
     * Mentés
     * ============================================================== */

    /** Honnan jött a beküldés – oda is megy vissza. */
    private static function bekuldes_kontextusa(): string
    {
        $kontextus = isset($_REQUEST['kontextus'])
            ? sanitize_key(wp_unslash($_REQUEST['kontextus']))
            : 'admin';

        return $kontextus === 'frontend' ? 'frontend' : 'admin';
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    private static function vissza(array $parameterek): string
    {
        return SDH_Muhely_Modulok::visszateres(self::KULCS, $parameterek, self::bekuldes_kontextusa());
    }

    /**
     * A beküldött mezőkből adatbázisra kész tömb.
     *
     * @return array<string, mixed>
     */
    private static function adatok_osszeallit(?object $regi): array
    {
        $szoveg = static fn (string $mezo): string => isset($_POST[$mezo])
            ? sanitize_text_field(wp_unslash($_POST[$mezo]))
            : '';

        $allapotok = self::allapotok();
        $allapot   = sanitize_key($szoveg('allapot'));

        // Ismeretlen kulcsot csak akkor fogadunk el, ha a lap már eleve
        // azt viseli (törölt állapot) – új, kitalált kulcsot nem.
        if (!isset($allapotok[$allapot]) && !($regi !== null && $allapot === (string) $regi->allapot)) {
            $allapot = self::alap_kulcs($allapotok);
        }

        $felelos = isset($_POST['felelos']) ? (int) $_POST['felelos'] : 0;

        if ($felelos > 0 && !get_userdata($felelos)) {
            $felelos = 0;
        }

        $keszult = self::datum_vagy_null($szoveg('keszult')) ?? current_time('Y-m-d');

        // Fizetés. A „fizetett" az előleg: ennyivel kevesebb a fizetendő.
        // A fizetési mód csak a lista eleme lehet – vagy az, ami a lapon már áll.
        $fizetesi_mod = mb_substr($szoveg('fizetesi_mod'), 0, 40);

        if (
            $fizetesi_mod !== ''
            && !in_array($fizetesi_mod, self::fizetesi_modok(), true)
            && !($regi !== null && $fizetesi_mod === (string) ($regi->fizetesi_mod ?? ''))
        ) {
            $fizetesi_mod = '';
        }

        $fizetve       = !empty($_POST['fizetve']);
        $fizetes_ideje = self::datum_vagy_null($szoveg('fizetes_ideje'));

        // Kifizetett lapnál a fizetés napja nem maradhat üresen.
        if ($fizetve && $fizetes_ideje === null) {
            $fizetes_ideje = current_time('Y-m-d');
        }

        return [
            'allapot'           => $allapot,
            'nev'               => mb_substr($szoveg('nev'), 0, 190),
            'ugyfel_id'         => isset($_POST['ugyfel_id']) ? max(0, (int) $_POST['ugyfel_id']) : 0,
            'eszkoz_id'         => isset($_POST['eszkoz_id']) ? max(0, (int) $_POST['eszkoz_id']) : 0,
            'felelos'           => $felelos,
            'keszult'           => $keszult,
            'hatarido'          => self::datum_vagy_null($szoveg('hatarido')),
            'fizetesi_mod'      => $fizetesi_mod,
            'fizetve'           => $fizetve ? 1 : 0,
            'fizetes_ideje'     => $fizetes_ideje,
            'fizetett'          => max(0.0, round(SDH_Muhely_Tetel::szam($szoveg('fizetett')), 2)),
            'bevizsgalasi_dij'  => !empty($_POST['bevizsgalasi_dij']) ? 1 : 0,
            // A díj módja és a díjlistából választott díj (ha az űrlap küldte).
            'bevizsgalas_mod'   => isset($_POST['bevizsgalas_mod'])
                ? (in_array($szoveg('bevizsgalas_mod'), ['levon', 'marad'], true) ? $szoveg('bevizsgalas_mod') : 'auto')
                : (string) ($regi->bevizsgalas_mod ?? 'auto'),
            'bevizsgalas_nev'   => isset($_POST['bevizsgalas_nev'])
                ? mb_substr($szoveg('bevizsgalas_nev'), 0, 255)
                : (string) ($regi->bevizsgalas_nev ?? ''),
            'bevizsgalas_szolg' => isset($_POST['bevizsgalas_szolg'])
                ? max(0, (int) $_POST['bevizsgalas_szolg'])
                : (int) ($regi->bevizsgalas_szolg ?? 0),
            'kedvezmeny'        => min(100.0, max(0.0, round(SDH_Muhely_Tetel::szam($szoveg('lap_kedvezmeny')), 2))),
            'afakulcs'          => SDH_Muhely_Tetel::afakulcs_ervenyes($szoveg('lap_afakulcs')),
            'megjegyzes'        => isset($_POST['megjegyzes'])
                ? sanitize_textarea_field(wp_unslash($_POST['megjegyzes']))
                : '',
            'ugyfel_megjegyzes' => isset($_POST['ugyfel_megjegyzes'])
                ? sanitize_textarea_field(wp_unslash($_POST['ugyfel_megjegyzes']))
                : '',
            'modositva'         => current_time('mysql'),
        ];
    }

    /**
     * A beküldött hibasorok.
     *
     * Az üres sorokat (se hiba, se javítás) eldobja. Az állapotot a
     * hibasor-lista kulcsaihoz köti.
     *
     * @return array<int, array{id: int, leiras: string, javitas: string, allapot: string}>
     */
    private static function hibak_osszeallit(): array
    {
        if (!isset($_POST['hibak']) || !is_array($_POST['hibak'])) {
            return [];
        }

        $allapotok = self::hiba_allapotok();
        $alap      = (string) (array_key_first($allapotok) ?? 'uj');
        $nyers     = wp_unslash($_POST['hibak']);
        $hibak     = [];

        foreach ($nyers as $sor) {
            if (!is_array($sor)) {
                continue;
            }

            $leiras  = isset($sor['leiras']) ? mb_substr(sanitize_text_field((string) $sor['leiras']), 0, 255) : '';
            $javitas = isset($sor['javitas']) ? sanitize_textarea_field((string) $sor['javitas']) : '';

            if ($leiras === '' && $javitas === '') {
                continue;
            }

            $allapot = isset($sor['allapot']) ? sanitize_key((string) $sor['allapot']) : '';

            $hibak[] = [
                'id'      => isset($sor['id']) ? (int) $sor['id'] : 0,
                // Melyik eszköz hibája (0 = a fő eszközé); a feldolgoz() a lap eszközeihez köti.
                'eszkoz_id' => isset($sor['eszkoz_id']) ? max(0, (int) $sor['eszkoz_id']) : 0,
                'leiras'  => $leiras,
                'javitas' => $javitas,
                // A törölt állapotú régi sor megtartja a kulcsát; újat csak a lista adhat.
                'allapot' => $allapot !== '' && (isset($allapotok[$allapot]) || self::hiba_allapot_letezo($allapot))
                    ? $allapot
                    : $alap,
            ];
        }

        return $hibak;
    }

    /** Van-e ilyen állapotkulcs valamelyik hibasoron (törölt állapot esete). */
    private static function hiba_allapot_letezo(string $kulcs): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::hiba_tabla() . ' WHERE allapot = %s',
                $kulcs
            )
        ) > 0;
    }

    /**
     * Ellenőrzi az adatokat. Visszatérés: hibaüzenet, vagy null, ha rendben.
     *
     * Számozott állapotnál kötelező az ügyfél és az eszköz; a Sablon és az
     * Árajánlat még lehet hiányos.
     *
     * @param array<string, mixed> $adatok
     */
    private static function ervenyesit(array $adatok, bool $szamozott): ?string
    {
        global $wpdb;

        $ugyfel_id = (int) $adatok['ugyfel_id'];
        $eszkoz_id = (int) $adatok['eszkoz_id'];

        if ($ugyfel_id > 0 && SDH_Muhely_Ugyfel::nev($ugyfel_id) === '') {
            return 'Válassz ügyfelet a listából – gépelés önmagában nem elég.';
        }

        if ($szamozott && $ugyfel_id <= 0) {
            return 'Válassz ügyfelet a listából – ügyfél nélkül nem adható munkalapszám.';
        }

        if ($szamozott && $eszkoz_id <= 0) {
            return 'Válassz eszközt – a munkalap mindig egy eszközre vonatkozik.';
        }

        if ($eszkoz_id > 0) {
            $eszkoz_gazdaja = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT ugyfel_id FROM ' . SDH_Muhely_Schema::tabla('eszkoz') . ' WHERE id = %d',
                    $eszkoz_id
                )
            );

            if ($eszkoz_gazdaja === null) {
                return 'A kiválasztott eszköz nem létezik.';
            }

            if ($ugyfel_id > 0 && (int) $eszkoz_gazdaja !== $ugyfel_id) {
                return 'A kiválasztott eszköz nem ennek az ügyfélnek a készüléke.';
            }
        }

        return null;
    }

    /**
     * Beírja az adatbázisba a munkalapot és a hibasorait.
     *
     * A munkalapszámot zár alatt osztja ki: két egyszerre mentő gép nem
     * kaphat ugyanazt. Visszatérés: [azonosító, üzenetkulcs, szám] vagy
     * hibaüzenet szövegként.
     *
     * @param array<string, mixed>                                                          $adatok
     * @param array<int, array{id: int, leiras: string, javitas: string, allapot: string}> $hibak
     * @return array{0: int, 1: string, 2: string}|string
     */
    private static function adatbazisba(int $id, array $adatok, array $hibak, ?object $regi)
    {
        global $wpdb;

        $allapotok = self::allapotok();
        $szamozott = $allapotok[$adatok['allapot']]['szamozott'] ?? false;

        // Számot akkor kap a lap, ha számozott állapotban van, és még nincs száma.
        $szamot_kap = $szamozott && ($regi === null || (int) $regi->munkalap_szam <= 0);
        $zar        = false;

        if ($szamot_kap) {
            $zar = (int) $wpdb->get_var(
                $wpdb->prepare('SELECT GET_LOCK(%s, 5)', self::ZAR_NEV)
            ) === 1;

            if (!$zar) {
                return 'A számozás épp foglalt – próbáld újra egy pillanat múlva.';
            }
        }

        $adatok['lezarva'] = self::lezarva_ertek((string) $adatok['allapot'], $regi);

        try {
            if ($szamot_kap) {
                $adatok['munkalap_szam'] = self::kovetkezo_szam();
            }

            if ($id > 0) {
                $eredmeny = $wpdb->update(self::tabla(), $adatok, ['id' => $id]);
                $uzenet   = 'munkalap_mentve';
            } else {
                $adatok['letrehozva'] = current_time('mysql');
                $adatok['letrehozo']  = get_current_user_id();
                $adatok['forras']     = $adatok['forras'] ?? 'kezi';

                $eredmeny = $wpdb->insert(self::tabla(), $adatok);
                $id       = (int) $wpdb->insert_id;
                $uzenet   = 'munkalap_letrehozva';
            }

            if ($eredmeny === false || $id <= 0) {
                return 'Az adatbázis visszautasította a mentést.';
            }

            self::hibak_ment($id, $hibak);
        } finally {
            if ($zar) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::ZAR_NEV));
            }
        }

        $regi_allapot = $regi !== null ? (string) $regi->allapot : '';

        if (isset($adatok['allapot']) && $regi_allapot !== (string) $adatok['allapot']) {
            do_action('sdh_muhely_munkalap_allapot', $id, $regi_allapot, (string) $adatok['allapot']);
        }

        $szam = $szamot_kap
            ? self::szam_formaz($adatok['munkalap_szam'])
            : ($regi !== null ? self::szam_formaz($regi->munkalap_szam) : '');

        return [$id, $uzenet, $szam];
    }

    /**
     * Munkalap létrehozása kódból (demó adatok, később az átvétel).
     *
     * Ugyanazon az úton megy, mint az űrlap mentése: a számot zár alatt
     * kapja, a lezárás dátuma is beáll. Az $adatok kulcsai a tábla oszlopai
     * (allapot, nev, ugyfel_id, eszkoz_id, felelos, keszult, hatarido,
     * megjegyzes…), a hibasoroké: id (0), leiras, javitas, allapot.
     *
     * @param array<string, mixed>                                                         $adatok
     * @param array<int, array{id: int, leiras: string, javitas: string, allapot: string}> $hibak
     * @return int|string Az új munkalap azonosítója, vagy hibaüzenet.
     */
    public static function letrehoz(array $adatok, array $hibak = [])
    {
        $adatok['modositva'] = $adatok['modositva'] ?? current_time('mysql');

        $eredmeny = self::adatbazisba(0, $adatok, $hibak, null);

        return is_string($eredmeny) ? $eredmeny : (int) $eredmeny[0];
    }

    /**
     * A munkalap hibasorainak szinkronizálása a beküldöttel: ami új, az
     * létrejön, ami megvan, az frissül, ami hiányzik, az törlődik.
     *
     * @param array<int, array{id: int, leiras: string, javitas: string, allapot: string}> $hibak
     */
    private static function hibak_ment(int $munkalap_id, array $hibak): void
    {
        global $wpdb;

        $tabla  = self::hiba_tabla();
        $most   = current_time('mysql');
        $leteze = array_map(
            'intval',
            (array) $wpdb->get_col(
                $wpdb->prepare("SELECT id FROM {$tabla} WHERE munkalap_id = %d", $munkalap_id)
            )
        );

        $megmarad = [];

        foreach ($hibak as $sorrend => $hiba) {
            $sor = [
                'munkalap_id' => $munkalap_id,
                'sorrend'     => $sorrend,
                'leiras'      => $hiba['leiras'],
                'javitas'     => $hiba['javitas'],
                'allapot'     => $hiba['allapot'],
                'eszkoz_id'   => (int) ($hiba['eszkoz_id'] ?? 0),
                'modositva'   => $most,
            ];

            // Csak ehhez a munkalaphoz tartozó sort írhatunk felül – egy
            // meghamisított azonosító nem nyúlhat más lap hibasorához.
            if ($hiba['id'] > 0 && in_array($hiba['id'], $leteze, true)) {
                $wpdb->update($tabla, $sor, ['id' => $hiba['id']]);
                $megmarad[] = $hiba['id'];

                continue;
            }

            $sor['letrehozva'] = $most;
            $wpdb->insert($tabla, $sor);
            $megmarad[] = (int) $wpdb->insert_id;
        }

        foreach (array_diff($leteze, $megmarad) as $torlendo) {
            $wpdb->delete($tabla, ['id' => $torlendo, 'munkalap_id' => $munkalap_id]);
        }
    }

    /**
     * A teljes mentési folyamat: összeállítás, ellenőrzés, írás.
     *
     * @return array{0: int, 1: string, 2: string}|string
     */
    private static function feldolgoz(int $id)
    {
        $regi = $id > 0 ? self::egy($id) : null;

        if ($id > 0 && $regi === null) {
            return 'Nincs ilyen munkalap. Lehet, hogy időközben törölték.';
        }

        $adatok    = self::adatok_osszeallit($regi);
        $hibak     = self::hibak_osszeallit();

        // További eszközök: csak ha az űrlap hozta őket (más hívó nem törölhet).
        $eszkozok_jelen = !empty($_POST['eszkozok_jelen']);

        if ($eszkozok_jelen && (int) $adatok['eszkoz_id'] <= 0
            && isset($_POST['tovabbi_eszkoz']) && is_array($_POST['tovabbi_eszkoz'])) {
            // Ha a fő választó üres, de van csatolt eszköz, az első lép a helyére.
            foreach (array_map('intval', wp_unslash($_POST['tovabbi_eszkoz'])) as $jelolt) {
                if ($jelolt > 0) {
                    $adatok['eszkoz_id'] = $jelolt;
                    break;
                }
            }
        }

        $tovabbi   = $eszkozok_jelen
            ? self::tovabbi_osszeallit((int) $adatok['ugyfel_id'], (int) $adatok['eszkoz_id'])
            : null;

        // Hiba csak a lap eszközéhez köthető: ami nem (vagy már nem) a lap csatolt
        // eszköze, az a fő eszközhöz (0) kerül.
        $lap_eszkozei = $tovabbi ?? self::tovabbi_eszkoz_idk($id);

        foreach ($hibak as $i => $egy_hiba) {
            if (!in_array((int) $egy_hiba['eszkoz_id'], $lap_eszkozei, true)) {
                $hibak[$i]['eszkoz_id'] = 0;
            }
        }

        // A „Részben elkészült" a több eszközös lap közös állapota: az eszközökéből
        // adódik, kézzel nem választható – ilyenkor az eddigi állapot marad.
        $allapot_lista = self::allapotok();
        $tobb_lesz     = $tovabbi !== null ? $tovabbi !== [] : $lap_eszkozei !== [];

        if ($tobb_lesz && !empty($allapot_lista[$adatok['allapot']]['reszben'])
            && ($regi === null || (string) $regi->allapot !== (string) $adatok['allapot'])) {
            $adatok['allapot'] = $regi !== null ? (string) $regi->allapot : self::alap_kulcs($allapot_lista);
        }

        // Az űrlapon a LAP állapotát írták-e át (akkor minden eszköz ezt kapja).
        $allapot_valtott = $regi === null || (string) $regi->allapot !== (string) $adatok['allapot'];

        $szamozott = $allapot_lista[$adatok['allapot']]['szamozott'] ?? false;
        $hiba      = self::ervenyesit($adatok, $szamozott);

        if ($hiba !== null) {
            return $hiba;
        }

        $eredmeny = self::adatbazisba($id, $adatok, $hibak, $regi);

        if (is_array($eredmeny) && $tovabbi !== null) {
            self::tovabbi_ment((int) $eredmeny[0], $tovabbi, self::tovabbi_dijak_osszeallit($tovabbi));
        }

        // Eszközönkénti állapot és „fizetve": a lapon átírt állapot minden eszközé
        // lesz; különben a közös állapot az eszközökéből számolódik újra (eszköz
        // került a lapra vagy le róla). A „fizetve" jelzők a lapéhoz igazodnak.
        if (is_array($eredmeny)) {
            if ($allapot_valtott) {
                self::allapot_szetoszt((int) $eredmeny[0], (string) $adatok['allapot']);
            } else {
                self::allapot_szinkron((int) $eredmeny[0]);
            }
        }

        // A „Fizetve" pipa levétele a fizetés visszavonásával (az űrlap kérte): a lapot
        // lezáró fizetés eszközönként pontosan visszamegy; a „Fizetett" ebből adódik,
        // nem az űrlap mezőjéből.
        $visszavonas = is_array($eredmeny) && $regi !== null && (int) $regi->fizetve === 1
            && (int) $adatok['fizetve'] !== 1 && !empty($_POST['fizetes_vissza']);

        if ($visszavonas) {
            global $wpdb;

            $wpdb->update(self::tabla(), ['fizetett' => (float) $regi->fizetett], ['id' => (int) $eredmeny[0]]);
            self::vegfizetes_visszavon((int) $eredmeny[0]);
        } elseif (is_array($eredmeny) && $regi !== null && (float) $adatok['fizetett'] < (float) $regi->fizetett - 0.005) {
            // Lejjebb írt előleg: előbb a díjakon felüli befizetéseket viszi el (a díj marad).
            self::kulon_csokkent((int) $eredmeny[0], (float) $regi->fizetett - (float) $adatok['fizetett']);
        }

        // A tételek csak akkor szinkronizálódnak, ha az űrlap hozta őket –
        // egy tételek nélküli beküldés (régi oldal, más hívó) nem törölhet tételt.
        if (is_array($eredmeny) && !empty($_POST['tetelek_jelen'])) {
            $bekuldott_tetelek = SDH_Muhely_Tetel::bekuldott(isset($_POST['tetelek']) ? wp_unslash($_POST['tetelek']) : []);

            // Tétel csak a lap eszközéhez köthető: ami nem (vagy már nem) a lap
            // csatolt eszköze, az a fő eszközhöz (0) kerül.
            foreach ($bekuldott_tetelek as $i => $egy_tetel) {
                if (($egy_tetel['eszkoz_id'] ?? null) !== null && !in_array((int) $egy_tetel['eszkoz_id'], $lap_eszkozei, true)) {
                    $bekuldott_tetelek[$i]['eszkoz_id'] = 0;
                }
            }

            SDH_Muhely_Tetel::mentes((int) $eredmeny[0], $bekuldott_tetelek);
        }

        // A bevizsgálási díj tételsora a jelölőhöz és az előleghez igazodik.
        if (is_array($eredmeny) && class_exists('SDH_Muhely_Szamla')) {
            SDH_Muhely_Szamla::bevizsgalas_szinkron((int) $eredmeny[0]);
        }

        // Most pipálták be a „Fizetve"-t: a hátralék befizetettnek számít, a „Fizetett"
        // a teljes kifizetett összeg lesz (a végleges tételek és díjsorok után számolva).
        if (is_array($eredmeny) && (int) $adatok['fizetve'] === 1 && ($regi === null || (int) $regi->fizetve !== 1)) {
            self::teljes_kifizetes_konyvel((int) $eredmeny[0]);
        }

        // Az eszközönkénti „fizetve" jelzők a lapéhoz igazodnak – a végleges összegek után.
        if (is_array($eredmeny)) {
            self::fizetve_szinkron((int) $eredmeny[0], $regi);
        }

        return $eredmeny;
    }

    /** Teljes oldalas beküldés (JS nélküli tartalék). */
    public static function mentes(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_munkalap_mentes', 'sdh_nonce');

        $id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $eredmeny = self::feldolgoz($id);

        if (is_string($eredmeny)) {
            wp_safe_redirect(
                self::vissza(
                    array_filter([
                        'nezet'  => $id > 0 ? 'szerkeszt' : 'uj',
                        'id'     => $id > 0 ? $id : null,
                        'uzenet' => 'munkalap_hiba',
                        'hiba'   => $eredmeny,
                    ])
                )
            );
            exit;
        }

        [$uj_id, $uzenet, $szam] = $eredmeny;

        wp_safe_redirect(
            self::vissza(
                array_filter([
                    'nezet'  => 'szerkeszt',
                    'id'     => $uj_id,
                    'uzenet' => $uzenet,
                    'szam'   => $szam !== '' && $szam !== '—' ? $szam : null,
                ])
            )
        );
        exit;
    }

    /** Popupos beküldés. */
    public static function ajax_mentes(): void
    {
        check_ajax_referer('sdh_muhely_munkalap_mentes', 'sdh_nonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        // A mentés előtti állapot: a pénztár ebből látja, most lett-e fizetve / nőtt-e az előleg.
        $elotte   = $id > 0 ? self::egy($id) : null;
        $eredmeny = self::feldolgoz($id);

        if (is_string($eredmeny)) {
            wp_send_json_error(['uzenet' => $eredmeny]);
        }

        [$uj_id, $uzenet, $szam] = $eredmeny;

        $penztar = class_exists('SDH_Muhely_Penztar') ? SDH_Muhely_Penztar::munkalap_ajanlat((int) $uj_id, $elotte) : null;

        // A lista oldalára térünk vissza, hogy a változás rögtön látszódjon.
        wp_send_json_success([
            'id'     => $uj_id,
            'penztar' => $penztar,
            'vissza' => self::vissza(
                array_filter([
                    'uzenet' => $uzenet,
                    'szam'   => $szam !== '' && $szam !== '—' ? $szam : null,
                ])
            ),
        ]);
    }

    /* =================================================================
     * Dátumsegédek
     * ============================================================== */

    /** Az adatbázisban NULL vagy 0000-00-00 lehet; az űrlap üres mezőt vár. */
    private static function datum_ertek(string $ertek): string
    {
        return ($ertek === '' || str_starts_with($ertek, '0000')) ? '' : substr($ertek, 0, 10);
    }

    private static function datum_vagy_null(string $ertek): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $ertek) === 1 ? $ertek : null;
    }

    public static function datum_megjelenit(string $ertek): string
    {
        $ertek = self::datum_ertek($ertek);

        return $ertek === '' ? '—' : mysql2date('Y. m. d.', $ertek);
    }

    public static function datumido_megjelenit(string $ertek): string
    {
        return ($ertek === '' || str_starts_with($ertek, '0000'))
            ? '—'
            : mysql2date('Y. m. d. H:i', $ertek);
    }
}
