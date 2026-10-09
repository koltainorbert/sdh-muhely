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

        // Az ügyfél kiválasztása után az eszközválasztó ebből töltődik.
        add_action('wp_ajax_sdh_muhely_munkalapok_eszkozok', [self::class, 'ajax_eszkozok']);

        // Sémafrissítés után: a régi lezárt lapok lezárási dátumának pótlása.
        add_action('sdh_muhely_sema_frissult', [self::class, 'lezarva_potlas']);
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

    /**
     * @param array<string, mixed> $parameterek
     */
    private static function url(array $parameterek = []): string
    {
        return SDH_Muhely_Modulok::url(self::KULCS, $parameterek);
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
            'elkeszult | Elkészült | olajzold | szamozott',
            'lezart | Lezárt | zold | szamozott, zart',
            'ervenytelen | Érvénytelen | piros | szamozott, zart',
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
     * `zart` – a lap lezárt, nem számít nyitottnak.
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

            self::$gyorsitotar['munkalap'] = self::allapotok_feldolgoz(
                is_string($mentett) && trim($mentett) !== '' ? $mentett : $alap,
                $alap
            );
        }

        return self::$gyorsitotar['munkalap'];
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
            $felirat = SDH_Muhely_Eszkoz::megnevezes($sor);

            if ($sor->imei !== '') {
                $felirat .= ' · ' . $sor->imei;
            } elseif ($sor->sorozatszam !== '') {
                $felirat .= ' · ' . $sor->sorozatszam;
            }

            $lista[(int) $sor->id] = $felirat;
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

        if ($eszkoz_id > 0) {
            $feltetelek[] = 'm.eszkoz_id = %d';
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
            ];
            $par    = array_fill(0, count($reszek), $minta);

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
                                <?php else : ?>
                                    <span class="sdh-tabla__halvany">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $sor->eszkoz_id > 0 && $eszkoz_nev !== '') : ?>
                                    <?php echo esc_html($eszkoz_nev); ?>
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
     * Állapotváltás a listából. Ugyanazok a szabályok, mint az űrlapon:
     * számozott állapothoz ügyfél és eszköz kell, és az első számozott
     * állapotba lépéskor a lap munkalapszámot kap (zár alatt).
     *
     * @return array{id: int, szam: string, nev: string, szin: string, zart: bool}|string
     */
    private static function allapot_valt(int $id, string $kulcs)
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

        $adatok = [
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

        return [
            'id'   => $id,
            'szam' => self::szam_formaz($adatok['munkalap_szam'] ?? $regi->munkalap_szam),
            'nev'  => $allapotok[$kulcs]['nev'],
            'szin' => $allapotok[$kulcs]['szin'],
            'zart' => (bool) $allapotok[$kulcs]['zart'],
        ];
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

            <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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

        $hibak    = $uj ? [] : self::hibasorok((int) $munkalap->id);
        $hiba_db  = count($hibak);
        $hibak    = $hibak === [] ? [null] : $hibak;

        $megjegyzes = $uj ? '' : (string) $munkalap->megjegyzes;

        $felelosok = self::felelosok();

        // Egy régi lap felelőse akkor is látszódjon, ha már nem jogosult.
        if ($felelos > 0 && !isset($felelosok[$felelos])) {
            $nev = self::felelos_nev($felelos);

            if ($nev !== '') {
                $felelosok[$felelos] = $nev;
            }
        }

        $eszkozok = self::ugyfel_eszkozei($ugyfel_id, $eszkoz_id);

        ?>
        <input type="hidden" name="id" value="<?php echo (int) ($munkalap->id ?? 0); ?>">
        <input type="hidden" name="kontextus"
               value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
        <?php wp_nonce_field('sdh_muhely_munkalap_mentes', 'sdh_nonce'); ?>

        <?php
        // Ugyanaz a kompakt, széles, lapfüles szerkezet, mint az ügyfél- és az
        // eszközűrlapon: címke + mező egy sorban, két szimmetrikus oszlopban.
        // Így a popup alacsony marad, és nem kell a képernyőhöz kicsinyíteni.
        ?>
        <div class="sdh-ugyfelurlap sdh-munkalapurlap">
            <div class="sdh-fulek">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--1" name="_ful_munkalap"
                       id="ful_m_alap" checked>

                <div class="sdh-fulek__sav">
                    <label for="ful_m_alap">Munkalap</label>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--1">
                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <span class="sdh-ig__cimke">Ügyfél <span class="sdh-kotelezo">*</span></span>
                            <div class="sdh-mezo__sor">
                                <?php SDH_Muhely_Ugyfel::valaszto_mezo($ugyfel_id); ?>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--plusz"
                                        data-sdh-uj-ugyfel
                                        title="Új ügyfél felvétele" aria-label="Új ügyfél felvétele">+</button>
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
                                        title="Új eszköz felvétele" aria-label="Új eszköz felvétele">+</button>
                            </div>
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="allapot">Állapot</label>
                            <div class="sdh-allapot-valaszto">
                                <span class="sdh-allapot-pont sdh-allapot--<?php
                                    echo esc_attr($allapotok[$allapot]['szin'] ?? 'szurke');
                                ?>" data-sdh-allapot-pont></span>
                                <select name="allapot" id="allapot" data-sdh-allapot-valaszto>
                                    <?php foreach ($allapotok as $kulcs => $a) : ?>
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

                        <div class="sdh-ig">
                            <label for="felelos">Felelős</label>
                            <select name="felelos" id="felelos">
                                <option value="0">— nincs —</option>
                                <?php foreach ($felelosok as $id => $nev) : ?>
                                    <option value="<?php echo (int) $id; ?>" <?php selected($felelos, $id); ?>>
                                        <?php echo esc_html($nev); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="keszult">Készült</label>
                            <?php self::datum_mezo('keszult', $keszult); ?>
                        </div>

                        <div class="sdh-ig">
                            <label for="hatarido">Határidő</label>
                            <?php self::datum_mezo('hatarido', $hatarido); ?>
                        </div>
                    </div>

                    <div class="sdh-sor">
                        <div class="sdh-ig">
                            <label for="nev">Név</label>
                            <input type="text" name="nev" id="nev" maxlength="190"
                                   placeholder="Rövid tárgy, pl. „kijelzőcsere” – nem kötelező"
                                   value="<?php echo esc_attr($uj ? '' : (string) $munkalap->nev); ?>">
                        </div>
                    </div>

                    <?php if ($uj) : ?>
                        <span class="sdh-mezo__sugo">
                            A munkalapszám az első számozott állapotba lépéskor generálódik; ahhoz ügyfél és eszköz kell.
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sdh-fulek sdh-fulek--also">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--1" name="_ful_also_m"
                       id="ful_m_hibak" checked>
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--2" name="_ful_also_m"
                       id="ful_m_megjegyzes">

                <div class="sdh-fulek__sav">
                    <label for="ful_m_hibak">Hibasorok<?php
                        echo $hiba_db > 0 ? ' <span class="sdh-fulek__db">' . (int) $hiba_db . '</span>' : '';
                    ?></label>
                    <label for="ful_m_megjegyzes">Belső megjegyzés<?php
                        echo trim($megjegyzes) !== '' ? ' <span class="sdh-fulek__db">!</span>' : '';
                    ?></label>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--1" data-sdh-hibak>
                    <div class="sdh-hibafej" aria-hidden="true">
                        <span>Hiba</span>
                        <span>Állapot</span>
                        <span>Javítás / megjegyzés</span>
                        <span></span>
                    </div>

                    <div class="sdh-hibasorok" data-sdh-hibasorok
                         data-kovetkezo="<?php echo (int) count($hibak); ?>">
                        <?php foreach ($hibak as $i => $hiba) : ?>
                            <?php self::hibasor_sor((string) $i, $hiba, $hiba_allapotok); ?>
                        <?php endforeach; ?>
                    </div>

                    <template data-sdh-hibasor-sablon>
                        <?php self::hibasor_sor('__I__', null, $hiba_allapotok); ?>
                    </template>

                    <div class="sdh-hibalab">
                        <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-hibasor-uj>
                            + Hibasor
                        </button>
                        <span class="sdh-mezo__sugo">
                            Minden hibának saját állapota van. Az üresen hagyott sor mentéskor eldobódik.
                        </span>
                    </div>
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

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">
                    <?php echo $uj ? 'Munkalap létrehozása' : 'Mentés'; ?>
                </button>

                <?php if ($modal) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                <?php else : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(self::url()); ?>">Mégsem</a>
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

    /**
     * Egy hibasor az űrlapban: hiba, állapot, javítás egy sorban. A mezők
     * neve az oszlopfejben áll (.sdh-hibafej), a sorokban csak a mezők.
     *
     * Ugyanez a függvény rajzolja a meglévő sorokat és a JavaScript által
     * klónozott sablont (az indexe ott `__I__`), hogy a kettő ne csússzon szét.
     *
     * @param array<string, array{nev: string, szin: string, szamozott: bool, zart: bool}> $allapotok
     */
    private static function hibasor_sor(string $index, ?object $hiba, array $allapotok): void
    {
        $nev_elotag = 'hibak[' . $index . ']';
        $allapot    = $hiba !== null ? (string) $hiba->allapot : (string) (array_key_first($allapotok) ?? 'uj');

        ?>
        <div class="sdh-hibasor" data-sdh-hibasor>
            <input type="hidden" name="<?php echo esc_attr($nev_elotag); ?>[id]"
                   value="<?php echo (int) ($hiba->id ?? 0); ?>">

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

        return [
            'allapot'    => $allapot,
            'nev'        => mb_substr($szoveg('nev'), 0, 190),
            'ugyfel_id'  => isset($_POST['ugyfel_id']) ? max(0, (int) $_POST['ugyfel_id']) : 0,
            'eszkoz_id'  => isset($_POST['eszkoz_id']) ? max(0, (int) $_POST['eszkoz_id']) : 0,
            'felelos'    => $felelos,
            'keszult'    => $keszult,
            'hatarido'   => self::datum_vagy_null($szoveg('hatarido')),
            'megjegyzes' => isset($_POST['megjegyzes'])
                ? sanitize_textarea_field(wp_unslash($_POST['megjegyzes']))
                : '',
            'modositva'  => current_time('mysql'),
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
        $szamozott = self::allapotok()[$adatok['allapot']]['szamozott'] ?? false;
        $hiba      = self::ervenyesit($adatok, $szamozott);

        if ($hiba !== null) {
            return $hiba;
        }

        return self::adatbazisba($id, $adatok, $hibak, $regi);
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
        $eredmeny = self::feldolgoz($id);

        if (is_string($eredmeny)) {
            wp_send_json_error(['uzenet' => $eredmeny]);
        }

        [$uj_id, $uzenet, $szam] = $eredmeny;

        // A lista oldalára térünk vissza, hogy a változás rögtön látszódjon.
        wp_send_json_success([
            'id'     => $uj_id,
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
