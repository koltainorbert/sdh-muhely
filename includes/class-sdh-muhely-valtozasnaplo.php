<?php
/**
 * Változásnapló (0.59) – minden módosítás visszakereshetően, mezőszinten.
 *
 * Hogyan rögzít: a WordPress adatbázis-rétegének `query` szűrőjén minden
 * író lekérdezés átmegy, amely a rendszer saját tábláit érinti. Módosítás és
 * törlés ELŐTT a rendszer kiolvassa az érintett sorokat, a lekérdezés UTÁN
 * (a következő lekérdezés elején, illetve a kérés végén) az új állapotukat,
 * és a kettő különbségét írja a naplóba: melyik mező, mi volt, mi lett, ki,
 * mikor, honnan (felület, AI, időzített feladat, ügyféloldal). Így nincs
 * olyan kódút – űrlap, lista, AI-eszköz, import, időzítő –, amely kimaradna,
 * és egyetlen modulhoz sem kell hozzányúlni, ha új mezőt kap.
 *
 * A napló szigorú:
 *  - csak hozzáfűzni lehet: a napló táblájára irányuló UPDATE / DELETE /
 *    TRUNCATE / DROP lekérdezést a rendszer nem engedi lefutni (és a
 *    kísérletet is naplózza);
 *  - minden sor saját HMAC-ujjlenyomatot kap (titkos kulcs: a wp-config
 *    sója): ha valaki adatbázis-szinten átír egy sort, az ellenőrzés
 *    megmutatja; a kihagyott azonosítók (hézagok) a törölt sorokat jelzik;
 *  - a beállítások (wp_options, sdh_muhely_*) változása is bekerül, a titkos
 *    értékek (kulcs, jelszó, token) helyett csak annyi, hogy megváltoztak.
 *
 * A kijelzést (mezőnevek, értékek magyarul) és a lekérdezést is ez az
 * osztály adja: a Napló menüpont, a rekordok Napló ablaka és az
 * AI-asszisztens eszközei mind innen olvasnak.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Valtozasnaplo
{
    public const TABLA = 'valtozasnaplo';

    /** A Napló beállításai (Beállítások › Változásnapló). */
    public const OPT = 'sdh_muhely_valtozasnaplo';

    /** Ennél több érintett sornál egyetlen összesítő bejegyzés készül (pl. séma-pótlás). */
    private const TOMEGES = 500;

    /** Egy mező értéke legfeljebb ennyi karakter a naplóban. */
    private const MAX_ERTEK = 20000;

    /**
     * Ezek a táblák nem kerülnek a naplóba: gyorsítótár, újraépíthető
     * adatbázis, saját napló, vagy olvasási jelzés (ki mit látott már).
     */
    private const KIHAGYOTT = [
        'valtozasnaplo', 'tac', 'level', 'level_mappa', 'ai_uzenet', 'ai_muvelet', 'ai_tudas',
        'munkalap_naplo', 'penztar_naplo', 'csapat_tag', 'csapat_nyugta', 'naptar_jelzes',
        'sms_esemeny', 'sms_onteszt', 'sms_link_esemeny', 'sms_sablon_verzio', 'mezo_emlek', 'ugyfel_szo',
    ];

    /** Mezők, amelyek változása önmagában nem napló-esemény (minden mentéskor változnak). */
    private const ZAJ_MINDENHOL = ['modositva'];

    /** Táblánként a zajos mezők (pozíció a falon, olvasottság, újraszámolt összesítők). */
    private const ZAJ = [
        // A gyorskereső származtatott oszlopai (0.64): mentéskor magától készülnek.
        'ugyfel'             => ['kereso', 'tel_ford', 'cim_rendez'],
        // Az RMA-token a QR-kódhoz magától készül (első címke / matrica) – nem a lap módosítása.
        'munkalap'           => ['rma_token'],
        'csapat_cetli'       => ['x', 'y', 'z'],
        'csapat_beszelgetes' => ['utolso_ido', 'utolso_uzenet_id'],
        'szolgaltatas'       => ['hasznalat'],
        'uzenet'             => ['olvasva'],
        'sms_uzenet'         => ['proba', 'utolso_proba', 'kovetkezo', 'olvasva'],
        'penztar_nap'        => ['kp_be', 'kartya', 'utalas', 'kifizetes', 'kivet', 'befizetes', 'zaro', 'forgalom', 'halmozott', 'tetel_db', 'szamolva'],
    ];

    /** Titkos mezők / kulcsok: az értékük soha nem kerül a naplóba (csak hogy megváltozott). */
    private const TITKOS = '/(jelszo|jelszó|titok|token|secret|password|api_?kulcs|agent|zar_hash|privat_kulcs)/i';

    /** A beállításokban (option-tömbök kulcsai) a sima „kulcs" / „…_kulcs" is titok (API-kulcs). */
    private const OPT_TITKOS = '/(jelszo|jelszó|titok|token|secret|password|api_?kulcs|agent|zar_hash|privat_kulcs|(^|[_.])kulcs$)/i';

    /** Az AI-asszisztens ezeket a mezőket sem kapja meg (zárkód, feloldó minta). */
    private const AI_REJTETT = ['zarkod', 'minta'];

    /** Ezek az option-ök nem kerülnek a naplóba (futási állapot, gyorsítótár, verzió). */
    private const OPT_KIHAGY = '/(utolso|futott|_naplo$|_napi$|_nap$|verzio|version|potlas|telepitve|_zar$|_fut$|lock|gyorsitotar|cache|szamlalo|ujdonsag|ertekeles|_demo$|atallas|tudott|health|_db_version|_jelzes|_allapot_ido|rewrite|onteszt|_pulzus|_sor$|statusz|_allapot$|keresesek)/i';

    private static bool $bent = false;

    /**
     * Egy műveletet a napló nélkül futtat (származtatott oszlopok, tömeges
     * háttérfeltöltés) – a lekérdezés nem kerül a naplóba, és nem is olvassa
     * ki előtte az érintett sorokat.
     *
     * @return mixed
     */
    public static function csendben(callable $muvelet)
    {
        $elozo       = self::$bent;
        self::$bent = true;

        try {
            return $muvelet();
        } finally {
            self::$bent = $elozo;
        }
    }

    /** @var array<string, mixed>|null A még le nem zárt író lekérdezés (előtte-állapottal). */
    private static ?array $fuggo = null;

    private static string $keres = '';

    /** @var array{forras: string, megjegyzes: string} */
    private static array $kontextus = ['forras' => '', 'megjegyzes' => ''];

    private static ?bool $kesz = null;

    /** @var array<string, string> */
    private static array $cimke_gyorsitotar = [];

    public static function init(): void
    {
        // A legutolsó szűrő: amit más bővítmény átírt, azt naplózzuk.
        add_filter('query', [self::class, 'szuro'], PHP_INT_MAX);
        add_action('shutdown', [self::class, 'rogzit'], 1);
        add_action('sdh_muhely_sema_frissult', static function (): void {
            self::$kesz = null;
        });

        add_action('updated_option', [self::class, 'option_valtozott'], 10, 3);
        add_action('added_option', [self::class, 'option_letrejott'], 10, 2);
        add_action('deleted_option', [self::class, 'option_torolve'], 10, 1);
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    /** @return array{beallitasok: bool, ip: bool, lap: int} */
    public static function beallitas(): array
    {
        $b = get_option(self::OPT, []);
        $b = is_array($b) ? $b : [];

        return [
            'beallitasok' => !isset($b['beallitasok']) || !empty($b['beallitasok']),
            'ip'          => !isset($b['ip']) || !empty($b['ip']),
            'lap'         => min(500, max(20, (int) ($b['lap'] ?? 100))),
        ];
    }

    public static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla(self::TABLA);
    }

    /** Készen áll-e a napló (a tábla a sémafrissítés után létezik). */
    private static function kesz(): bool
    {
        if (self::$kesz === null) {
            self::$kesz = SDH_Muhely_Schema::naprakesz();
        }

        return self::$kesz;
    }

    /**
     * A következő bejegyzések forrása és megjegyzése (pl. „ai", „AI-javaslat #12").
     * Visszaadja az előzőt, hogy a hívó visszaállíthassa (kontextus_vissza).
     *
     * @return array{forras: string, megjegyzes: string}
     */
    public static function kontextus(string $forras, string $megjegyzes = ''): array
    {
        self::rogzit();

        $elozo = self::$kontextus;
        self::$kontextus = ['forras' => sanitize_key($forras), 'megjegyzes' => mb_substr($megjegyzes, 0, 255)];

        return $elozo;
    }

    /** @param array{forras: string, megjegyzes: string} $elozo */
    public static function kontextus_vissza(array $elozo): void
    {
        self::rogzit();
        self::$kontextus = $elozo;
    }

    /* =================================================================
     * Rögzítés: a query szűrő
     * ============================================================== */

    /**
     * Minden lekérdezés előtt fut: lezárja az előző írást (utána-állapot),
     * és ha ez írás a saját táblákra, kiolvassa az előtte-állapotot.
     *
     * @param mixed $query
     * @return mixed
     */
    public static function szuro($query)
    {
        if (self::$bent || !is_string($query) || $query === '') {
            return $query;
        }

        // Olcsó előszűrés: csak az író lekérdezések érdekesek.
        if (preg_match('/^\s*(?:\/\*.*?\*\/\s*)*(insert|update|delete|replace|truncate|drop)\b/is', $query, $elso) !== 1) {
            if (self::$fuggo !== null) {
                self::bent(static function (): void {
                    self::fuggo_lezar();
                });
            }

            return $query;
        }

        $vissza = $query;

        self::bent(static function () use ($query, &$vissza): void {
            self::fuggo_lezar();

            if (!self::kesz()) {
                return;
            }

            global $wpdb;

            $naplo = self::tabla();

            // A napló csak hozzáfűzhető: átírni, törölni, üríteni nem lehet.
            if (preg_match('/^\s*(update|delete|truncate|drop|replace)\b/i', $query) === 1
                && preg_match('/`?' . preg_quote($naplo, '/') . '`?(\s|$|;|,)/i', $query) === 1) {
                $vissza = '';
                self::ir([
                    'muvelet' => 'vedelem', 'tabla' => self::TABLA, 'sor_id' => 0,
                    'fo_tipus' => 'naplo', 'fo_id' => 0, 'ml_id' => 0,
                    'valtozas' => ['sql' => mb_substr($query, 0, 600)],
                    'osszegzes' => 'A napló módosítását a rendszer megakadályozta.',
                ]);

                return;
            }

            $e = self::elemez($query, $wpdb->prefix . 'sdh_');

            if ($e === null) {
                return;
            }

            if (!self::naplozott($e['tabla'])) {
                return;
            }

            if ($e['muvelet'] === 'nyers') {
                self::ir([
                    'muvelet' => 'nyers', 'tabla' => $e['tabla'], 'sor_id' => 0,
                    'fo_tipus' => $e['tabla'], 'fo_id' => 0, 'ml_id' => 0,
                    'valtozas' => ['sql' => mb_substr($query, 0, 1000)],
                    'osszegzes' => 'Összetett adatbázis-írás: ' . self::tabla_nev($e['tabla']),
                ]);

                return;
            }

            $f = ['muvelet' => $e['muvelet'], 'tabla' => $e['tabla'], 'sql' => $query, 'elotte' => [], 'tomeges' => 0, 'kontextus' => self::$kontextus];

            if ($e['muvelet'] === 'update' || $e['muvelet'] === 'delete') {
                $teljes = $wpdb->prefix . 'sdh_' . $e['tabla'];
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- a WHERE ugyanaz, ami a lekérdezésben lefut.
                $sorok = $wpdb->get_results("SELECT * FROM `{$teljes}` WHERE " . $e['where'] . ' LIMIT ' . (self::TOMEGES + 1), ARRAY_A);

                if (!is_array($sorok) || $sorok === []) {
                    return;
                }

                if (count($sorok) > self::TOMEGES) {
                    $f['tomeges'] = count($sorok);
                } else {
                    foreach ($sorok as $s) {
                        if (isset($s['id'])) {
                            $f['elotte'][(int) $s['id']] = $s;
                        }
                    }

                    if ($f['elotte'] === []) {
                        return;
                    }
                }
            } elseif (!empty($e['tobb'])) {
                $f['tomeges'] = -1;
            } else {
                // Az új sor(ok) azonosítója a legnagyobb meglévő fölött lesz. (Az insert_id-re nem
                // lehet építeni: a $wpdb->insert() a következő beszúrás előtt nullázza.)
                $teljes = $wpdb->prefix . 'sdh_' . $e['tabla'];
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $f['max'] = (int) $wpdb->get_var("SELECT MAX(id) FROM `{$teljes}`");
            }

            self::$fuggo = $f;
        });

        return $vissza;
    }

    /** A napló saját lekérdezései nem kerülnek a naplóba; a wpdb állapota visszaáll. */
    private static function bent(callable $fv): void
    {
        global $wpdb;

        // Újrabelépés (egymásba ágyazott hívás) esetén a külső állapot marad.
        $volt = self::$bent;
        self::$bent = true;

        $mentes = [];

        foreach (['insert_id', 'rows_affected', 'num_rows', 'last_result', 'last_query', 'last_error', 'func_call'] as $p) {
            if (property_exists($wpdb, $p)) {
                $mentes[$p] = $wpdb->$p;
            }
        }

        try {
            $fv();
        } catch (Throwable $h) {
            // A napló hibája soha nem akaszthatja meg a munkát.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('SDH Változásnapló: ' . $h->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        } finally {
            foreach ($mentes as $p => $v) {
                $wpdb->$p = $v;
            }

            self::$bent = $volt;
        }
    }

    /** A függő írás lezárása most (kérés vége, olvasás előtt, kontextusváltáskor). */
    public static function rogzit(): void
    {
        if (self::$fuggo === null || self::$bent) {
            return;
        }

        self::bent(static function (): void {
            self::fuggo_lezar();
        });
    }

    public static function naplozott(string $tabla): bool
    {
        return $tabla !== '' && !in_array($tabla, self::KIHAGYOTT, true);
    }

    /**
     * Az író lekérdezés elemzése: művelet, tábla (előtag nélkül), WHERE.
     *
     * @return array{muvelet: string, tabla: string, where?: string, tobb?: bool}|null
     */
    public static function elemez(string $sql, string $elotag): ?array
    {
        $ep = preg_quote($elotag, '/');

        if (preg_match('/^\s*(insert|replace)\s+(?:(?:low_priority|delayed|high_priority|ignore)\s+)*(?:into\s+)?`?' . $ep . '([a-z0-9_]+)`?/i', $sql, $m) === 1) {
            // Csak az „ON DUPLICATE KEY UPDATE" tömeges: abban a meglévő sorok is átíródhatnak.
            // A több soros VALUES és az INSERT … SELECT új sorai egyenként naplózódnak.
            $tobb = stripos(self::idezet_nelkul($sql), 'on duplicate key') !== false;

            return ['muvelet' => 'insert', 'tabla' => strtolower($m[2]), 'tobb' => $tobb];
        }

        if (preg_match('/^\s*update\s+(?:(?:low_priority|ignore)\s+)*`?' . $ep . '([a-z0-9_]+)`?\s+set\s/i', $sql, $m) === 1) {
            return ['muvelet' => 'update', 'tabla' => strtolower($m[1]), 'where' => self::where_resz($sql) ?? '1=1'];
        }

        if (preg_match('/^\s*delete\s+(?:(?:low_priority|quick|ignore)\s+)*from\s+`?' . $ep . '([a-z0-9_]+)`?(\s|$)/i', $sql, $m) === 1
            && preg_match('/\bjoin\b|\busing\b/i', self::idezet_nelkul($sql)) !== 1) {
            return ['muvelet' => 'delete', 'tabla' => strtolower($m[1]), 'where' => self::where_resz($sql) ?? '1=1'];
        }

        // Írás a saját táblára, amit nem tudunk soronként követni (pl. több táblás UPDATE).
        if (preg_match('/^\s*(update|delete|insert|replace)\b/i', $sql) === 1 && preg_match('/`?' . $ep . '([a-z0-9_]+)`?/i', $sql, $m) === 1) {
            return ['muvelet' => 'nyers', 'tabla' => strtolower($m[1])];
        }

        return null;
    }

    /** A szöveg az idézőjeles részek nélkül (a szerkezet vizsgálatához). */
    private static function idezet_nelkul(string $sql): string
    {
        return (string) preg_replace("/'(?:\\\\.|''|[^'\\\\])*'|\"(?:\\\\.|\"\"|[^\"\\\\])*\"/s", "''", $sql);
    }

    /** A legfelső szintű WHERE feltétel (idézőjelek és zárójelek figyelembevételével), ORDER BY / LIMIT nélkül. */
    public static function where_resz(string $sql): ?string
    {
        $hossz = strlen($sql);
        $mely  = 0;
        $idez  = null;
        $kezd  = -1;
        $veg   = $hossz;
        $szo   = static fn (string $c): bool => ctype_alnum($c) || $c === '_';

        for ($i = 0; $i < $hossz; $i++) {
            $c = $sql[$i];

            if ($idez !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $idez) {
                    if ($i + 1 < $hossz && $sql[$i + 1] === $idez) {
                        $i++;
                    } else {
                        $idez = null;
                    }
                }

                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $idez = $c;
                continue;
            }

            if ($c === '(') {
                $mely++;
                continue;
            }

            if ($c === ')') {
                $mely--;
                continue;
            }

            if ($mely !== 0 || ($i > 0 && $szo($sql[$i - 1]))) {
                continue;
            }

            if ($kezd < 0 && strncasecmp(substr($sql, $i, 5), 'where', 5) === 0 && !$szo($sql[$i + 5] ?? ' ')) {
                $kezd = $i + 5;
                $i += 4;
                continue;
            }

            if ($kezd >= 0 && (preg_match('/\Gorder\s+by\b/i', $sql, $m, 0, $i) === 1 || preg_match('/\Glimit\b/i', $sql, $m, 0, $i) === 1)) {
                $veg = $i;
                break;
            }
        }

        if ($kezd < 0) {
            return null;
        }

        $w = trim(substr($sql, $kezd, $veg - $kezd));

        return $w === '' ? null : rtrim($w, '; ');
    }

    /** Az előző írás lezárása: az utána-állapot kiolvasása és a különbség naplózása. */
    private static function fuggo_lezar(): void
    {
        global $wpdb;

        $f = self::$fuggo;
        self::$fuggo = null;

        if ($f === null) {
            return;
        }

        $hiba          = (string) $wpdb->last_error;
        $insert_id     = (int) $wpdb->insert_id;
        $erintett      = (int) $wpdb->rows_affected;
        $teljes        = $wpdb->prefix . 'sdh_' . $f['tabla'];
        $kontextus_most = self::$kontextus;
        self::$kontextus = $f['kontextus'];

        try {
            if ($hiba !== '') {
                return;
            }

            if ($f['tomeges'] !== 0) {
                $db = $f['tomeges'] > 0 ? (int) $f['tomeges'] : max($erintett, 1);

                self::ir([
                    'muvelet' => 'tomeges', 'tabla' => $f['tabla'], 'sor_id' => 0,
                    'fo_tipus' => $f['tabla'], 'fo_id' => 0, 'ml_id' => 0,
                    'valtozas' => ['db' => $db, 'tipus' => $f['muvelet'], 'sql' => mb_substr((string) $f['sql'], 0, 1000)],
                    'osszegzes' => sprintf('Tömeges %s: %s – %d sor', ['insert' => 'beírás', 'update' => 'módosítás', 'delete' => 'törlés'][$f['muvelet']] ?? 'írás', self::tabla_nev($f['tabla']), $db),
                ]);

                return;
            }

            if ($f['muvelet'] === 'insert') {
                // Ha az insert_id még megvan (a következő lekérdezés nem beszúrás), az a pontos;
                // különben a beszúrás előtti legnagyobb azonosító fölötti sorok az újak.
                // (Egy sornál az insert_id pontos – egyszerre mentő kollégák sora nem keveredik bele;
                // több sornál és nullázott insert_id-nél az érintett sorok száma szerint a legkisebbek.)
                if ($erintett <= 1 && $insert_id > (int) ($f['max'] ?? 0)) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sorok = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$teljes}` WHERE id = %d", $insert_id), ARRAY_A);
                } else {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sorok = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$teljes}` WHERE id > %d ORDER BY id ASC LIMIT %d", (int) ($f['max'] ?? 0), $erintett > 0 ? min($erintett, self::TOMEGES + 1) : self::TOMEGES + 1), ARRAY_A);
                }

                $sorok = is_array($sorok) ? $sorok : [];

                if (count($sorok) > self::TOMEGES) {
                    self::ir([
                        'muvelet' => 'tomeges', 'tabla' => $f['tabla'], 'sor_id' => 0,
                        'fo_tipus' => $f['tabla'], 'fo_id' => 0, 'ml_id' => 0,
                        'valtozas' => ['db' => count($sorok), 'tipus' => 'insert', 'sql' => mb_substr((string) $f['sql'], 0, 1000)],
                        'osszegzes' => sprintf('Tömeges beírás: %s – több mint %d sor', self::tabla_nev($f['tabla']), self::TOMEGES),
                    ]);

                    return;
                }

                foreach ($sorok as $sor) {
                    self::sor_naplo('letrehozas', $f['tabla'], null, $sor);
                }

                return;
            }

            $idk = array_keys($f['elotte']);

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $utana = $wpdb->get_results("SELECT * FROM `{$teljes}` WHERE id IN (" . implode(',', array_map('intval', $idk)) . ')', ARRAY_A);
            $most  = [];

            foreach ((array) $utana as $s) {
                $most[(int) $s['id']] = $s;
            }

            foreach ($f['elotte'] as $id => $elotte) {
                if ($f['muvelet'] === 'delete') {
                    if (!isset($most[$id])) {
                        self::sor_naplo('torles', $f['tabla'], $elotte, null);
                    }

                    continue;
                }

                if (isset($most[$id])) {
                    self::sor_naplo('modositas', $f['tabla'], $elotte, $most[$id]);
                }
            }
        } finally {
            self::$kontextus = $kontextus_most;
        }
    }

    /**
     * Egy sor változásának naplózása.
     *
     * @param array<string, mixed>|null $elotte
     * @param array<string, mixed>|null $utana
     */
    private static function sor_naplo(string $muvelet, string $tabla, ?array $elotte, ?array $utana): void
    {
        $sor  = $utana ?? $elotte ?? [];
        $zaj  = array_merge(self::ZAJ_MINDENHOL, self::ZAJ[$tabla] ?? []);
        $mezok = [];

        if ($muvelet === 'modositas') {
            foreach ((array) $utana as $k => $uj) {
                if (in_array($k, $zaj, true) || !array_key_exists($k, (array) $elotte)) {
                    continue;
                }

                $regi = $elotte[$k];

                if (self::norm($regi) === self::norm($uj)) {
                    continue;
                }

                $mezok[$k] = [self::vag($k, $regi), self::vag($k, $uj)];
            }

            if ($mezok === []) {
                return;
            }
        } else {
            foreach ($sor as $k => $v) {
                if ($k === 'id' || in_array($k, $zaj, true) || $v === null || $v === '' || (is_string($v) && preg_match('/^0+(\.0+)?$/', $v) === 1 && $k !== 'allapot')) {
                    continue;
                }

                $mezok[$k] = self::vag($k, $v);
            }
        }

        [$fo_tipus, $fo_id, $ml_id] = self::fo($tabla, $sor);

        self::ir([
            'muvelet'   => $muvelet,
            'tabla'     => $tabla,
            'sor_id'    => (int) ($sor['id'] ?? 0),
            'fo_tipus'  => $fo_tipus,
            'fo_id'     => $fo_id,
            'ml_id'     => $ml_id,
            'valtozas'  => $mezok,
            'osszegzes' => self::osszegzes($muvelet, $tabla, $mezok, $sor),
        ]);
    }

    /** @param mixed $v */
    private static function norm($v): string
    {
        return $v === null ? '' : (string) $v;
    }

    /**
     * Érték a naplóba: a titkos mező helyett jelzés, a hosszú szöveg levágva.
     *
     * @param mixed $v
     * @return mixed
     */
    private static function vag(string $mezo, $v)
    {
        if ($v === null) {
            return null;
        }

        if (preg_match(self::TITKOS, $mezo) === 1) {
            return $v === '' ? '' : '••• (titkos)';
        }

        $v = (string) $v;

        return mb_strlen($v) > self::MAX_ERTEK ? mb_substr($v, 0, self::MAX_ERTEK) . '…' : $v;
    }

    /**
     * Melyik „rekordhoz" tartozik a sor (a Napló ablak ez alapján gyűjt):
     * [fő típus, fő azonosító, kapcsolt munkalap].
     *
     * @param array<string, mixed> $s
     * @return array{0: string, 1: int, 2: int}
     */
    private static function fo(string $tabla, array $s): array
    {
        $id = (int) ($s['id'] ?? 0);
        $ml = (int) ($s['munkalap_id'] ?? 0);

        switch ($tabla) {
            case 'munkalap':
                return ['munkalap', $id, $id];
            case 'munkalap_hiba':
            case 'munkalap_eszkoz':
            case 'munkalap_tetel':
            case 'uzenet':
            case 'szamla':
            case 'sms_dontes':
                return $ml > 0 ? ['munkalap', $ml, $ml] : [$tabla, $id, 0];
            case 'sms_uzenet':
                if ($ml > 0) {
                    return ['munkalap', $ml, $ml];
                }

                return (int) ($s['ugyfel_id'] ?? 0) > 0 ? ['ugyfel', (int) $s['ugyfel_id'], 0] : [$tabla, $id, 0];
            case 'sms_leiratkozas':
                return ['ugyfel', (int) ($s['ugyfel_id'] ?? 0), 0];
            case 'termek_mozgas':
                return ['termek', (int) ($s['termek_id'] ?? 0), $ml];
            case 'csapat_uzenet':
                return ['csapat_beszelgetes', (int) ($s['beszelgetes_id'] ?? 0), 0];
            case 'csapat_csoport_tag':
                return ['csapat_csoport', (int) ($s['csoport_id'] ?? 0), 0];
            case 'csatolmany':
                return [sanitize_key((string) ($s['tipus'] ?? 'csatolmany')), (int) ($s['ref_id'] ?? 0), 0];
        }

        return [$tabla, $id, $ml];
    }

    /**
     * Egy bejegyzés kiírása (aláírással).
     *
     * @param array<string, mixed> $b
     */
    private static function ir(array $b): void
    {
        global $wpdb;

        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $uid  = $user instanceof WP_User ? (int) $user->ID : 0;

        // Közös fiók (H2): a kiválasztott dolgozó nevére kerül a bejegyzés.
        $szemely = $uid > 0 && class_exists('SDH_Muhely_Szemely') ? SDH_Muhely_Szemely::aktiv_id() : 0;
        $szemely_u = $szemely > 0 ? get_userdata($szemely) : false;

        if ($szemely_u instanceof WP_User) {
            $user = $szemely_u;
            $uid  = $szemely;
        }

        $sor = [
            'ido'             => current_time('mysql'),
            'keres'           => self::keres_azon(),
            'muvelet'         => (string) $b['muvelet'],
            'tabla'           => mb_substr((string) $b['tabla'], 0, 40),
            'sor_id'          => (int) $b['sor_id'],
            'fo_tipus'        => mb_substr((string) $b['fo_tipus'], 0, 30),
            'fo_id'           => (int) $b['fo_id'],
            'ml_id'           => (int) $b['ml_id'],
            'valtozas'        => (string) wp_json_encode($b['valtozas'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'osszegzes'       => mb_substr((string) ($b['osszegzes'] ?? ''), 0, 255),
            'felhasznalo'     => $uid,
            'felhasznalo_nev' => mb_substr($uid > 0 ? (string) ($user->display_name ?: $user->user_login) : self::nevtelen_nev(), 0, 190),
            'forras'          => self::forras(),
            'akcio'           => self::akcio(),
            'megjegyzes'      => mb_substr((string) ($b['megjegyzes'] ?? self::$kontextus['megjegyzes']), 0, 255),
            'ip'              => self::ip(),
        ];

        $sor['hash'] = self::alairas($sor);

        $wpdb->insert(self::tabla(), $sor);
    }

    /** Egy kérés egy azonosítót kap: ami egy mentéssel változott, együtt látszik. */
    private static function keres_azon(): string
    {
        if (self::$keres === '') {
            self::$keres = substr(md5(uniqid('', true) . wp_rand()), 0, 12);
        }

        return self::$keres;
    }

    /** Teszt / hosszú futás: új kérés-azonosító (a következő bejegyzések külön csoportba kerülnek). */
    public static function uj_keres(): void
    {
        self::rogzit();
        self::$keres = '';
    }

    private static function nevtelen_nev(): string
    {
        $f = self::forras();

        return ['ugyfeloldal' => 'Ügyfél (RMA-oldal)', 'idozitett' => 'Rendszer (időzített)', 'webhook' => 'SMS-átjáró', 'cli' => 'Parancssor'][$f] ?? 'Rendszer';
    }

    private static function forras(): string
    {
        if (self::$kontextus['forras'] !== '') {
            return self::$kontextus['forras'];
        }

        if (defined('WP_CLI') && WP_CLI) {
            return 'cli';
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return 'idozitett';
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'webhook';
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return is_user_logged_in() ? 'felulet' : 'ugyfeloldal';
        }

        if (PHP_SAPI === 'cli') {
            return 'cli';
        }

        if (is_admin()) {
            return 'felulet';
        }

        return is_user_logged_in() ? 'felulet' : 'ugyfeloldal';
    }

    private static function akcio(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- csak naplózzuk, melyik művelet futott.
        $a = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : '';

        return mb_substr(str_starts_with($a, 'sdh_muhely_') ? substr($a, 11) : $a, 0, 80);
    }

    private static function ip(): string
    {
        if (!self::beallitas()['ip']) {
            return '';
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * A sor aláírása: a tartalmából és a telepítés titkos sójából. Aki a kulcs
     * nélkül átír egy mezőt, annak a sora az ellenőrzésnél hibásnak látszik.
     *
     * @param array<string, mixed> $s
     */
    private static function alairas(array $s): string
    {
        $mezok = ['ido', 'keres', 'muvelet', 'tabla', 'sor_id', 'fo_tipus', 'fo_id', 'ml_id', 'valtozas', 'osszegzes', 'felhasznalo', 'felhasznalo_nev', 'forras', 'akcio', 'megjegyzes', 'ip'];
        $tartalom = '';

        foreach ($mezok as $m) {
            $tartalom .= $m . '=' . (string) ($s[$m] ?? '') . "\x1f";
        }

        return hash_hmac('sha256', $tartalom, wp_salt('auth') . '|sdh-valtozasnaplo');
    }

    /* =================================================================
     * Események (nem adatváltozás: nyomtatás, újranyitás indoka, elutasítás)
     * ============================================================== */

    /**
     * Esemény a naplóba egy rekordhoz (pl. „Matrica nyomtatva", „Szerkesztés
     * elutasítva: a lap lezárt").
     *
     * @param array<string, mixed> $adat
     */
    public static function esemeny(string $fo_tipus, int $fo_id, string $leiras, array $adat = [], string $muvelet = 'esemeny'): void
    {
        if (!self::kesz()) {
            return;
        }

        self::rogzit();

        $ml = $fo_tipus === 'munkalap' ? $fo_id : 0;

        self::bent(static function () use ($fo_tipus, $fo_id, $ml, $leiras, $adat, $muvelet): void {
            self::ir([
                'muvelet' => in_array($muvelet, ['esemeny', 'elutasitva', 'nyomtatas'], true) ? $muvelet : 'esemeny',
                'tabla' => $fo_tipus, 'sor_id' => $fo_id, 'fo_tipus' => $fo_tipus, 'fo_id' => $fo_id, 'ml_id' => $ml,
                'valtozas' => ['leiras' => $leiras] + ($adat !== [] ? ['adat' => $adat] : []),
                'osszegzes' => $leiras,
            ]);
        });
    }

    /* =================================================================
     * Beállítások változása (wp_options)
     * ============================================================== */

    private static function option_figyelt(string $nev): bool
    {
        // A napló és a zárolás saját beállítása mindig naplózódik (a kikapcsolás is).
        if ($nev === self::OPT || $nev === 'sdh_muhely_zarolas') {
            return true;
        }

        return str_starts_with($nev, 'sdh_muhely_')
            && !str_starts_with($nev, self::OPT . '_')
            && preg_match(self::OPT_KIHAGY, substr($nev, 11)) !== 1
            && self::beallitas()['beallitasok'];
    }

    /**
     * @param mixed $regi
     * @param mixed $uj
     */
    public static function option_valtozott(string $nev, $regi, $uj): void
    {
        if (self::$bent || !self::kesz() || !self::option_figyelt($nev)) {
            return;
        }

        $mezok = self::option_kulonbseg($nev, $regi, $uj);

        if ($mezok === []) {
            return;
        }

        self::option_ir($nev, 'beallitas', $mezok);
    }

    /** @param mixed $ertek */
    public static function option_letrejott(string $nev, $ertek): void
    {
        if (self::$bent || !self::kesz() || !self::option_figyelt($nev)) {
            return;
        }

        $mezok = self::option_kulonbseg($nev, null, $ertek);

        if ($mezok !== []) {
            self::option_ir($nev, 'beallitas', $mezok);
        }
    }

    public static function option_torolve(string $nev): void
    {
        if (self::$bent || !self::kesz() || !self::option_figyelt($nev)) {
            return;
        }

        self::option_ir($nev, 'beallitas', ['(törölve)' => ['…', '']]);
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $mezok */
    private static function option_ir(string $nev, string $muvelet, array $mezok): void
    {
        self::rogzit();

        self::bent(static function () use ($nev, $muvelet, $mezok): void {
            self::ir([
                'muvelet' => $muvelet, 'tabla' => 'beallitas', 'sor_id' => 0,
                'fo_tipus' => 'beallitas', 'fo_id' => 0, 'ml_id' => 0,
                'valtozas' => ['nev' => $nev, 'mezok' => $mezok],
                'osszegzes' => 'Beállítás: ' . self::option_cim($nev) . ' (' . count($mezok) . ' változás)',
            ]);
        });
    }

    /** Ember számára olvasható név az option-höz. */
    public static function option_cim(string $nev): string
    {
        $rov = str_replace('_', ' ', preg_replace('/^sdh_muhely_/', '', $nev) ?? $nev);

        return mb_strtoupper(mb_substr($rov, 0, 1)) . mb_substr($rov, 1);
    }

    /**
     * Két option-érték különbsége útvonalanként (tömbnél legfeljebb 3 szint mélyen).
     *
     * @param mixed $regi
     * @param mixed $uj
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function option_kulonbseg(string $nev, $regi, $uj): array
    {
        $lapit = static function ($v, string $elotag, int $mely, array &$ki) use (&$lapit): void {
            if (is_object($v)) {
                $v = (array) $v;
            }

            if (is_array($v) && $mely < 3 && $v !== []) {
                foreach ($v as $k => $al) {
                    $lapit($al, $elotag === '' ? (string) $k : $elotag . '.' . $k, $mely + 1, $ki);
                }

                return;
            }

            $ki[$elotag === '' ? '(érték)' : $elotag] = is_scalar($v) || $v === null ? $v : wp_json_encode($v, JSON_UNESCAPED_UNICODE);
        };

        $a = [];
        $b = [];
        $lapit($regi, '', 0, $a);
        $lapit($uj, '', 0, $b);

        $ki = [];

        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            $va = $a[$k] ?? null;
            $vb = $b[$k] ?? null;

            if (self::norm(is_bool($va) ? (int) $va : $va) === self::norm(is_bool($vb) ? (int) $vb : $vb)) {
                continue;
            }

            $titkos = preg_match(self::OPT_TITKOS, (string) $k) === 1 || preg_match(self::OPT_TITKOS, substr($nev, 11)) === 1;
            $ki[(string) $k] = $titkos
                ? [$va === null || $va === '' ? '' : '•••', $vb === null || $vb === '' ? '' : '••• (megváltozott)']
                : [is_string($va) ? mb_substr($va, 0, 2000) : $va, is_string($vb) ? mb_substr($vb, 0, 2000) : $vb];

            if (count($ki) >= 200) {
                break;
            }
        }

        return $ki;
    }

    /* =================================================================
     * Olvasás
     * ============================================================== */

    /**
     * A szűrő feltételei SQL-ben.
     *
     * Kulcsok: rekord (tipus:id), tipus (kategória), felhasznalo, forras,
     * muvelet, tol, ig (ÉÉÉÉ-HH-NN), kereses, munkalap (szám), keres (azonosító).
     *
     * @param array<string, mixed> $sz
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function feltetel(array $sz): array
    {
        global $wpdb;

        $w = ['1=1'];
        $p = [];

        if (!empty($sz['rekord']) && preg_match('/^([a-z_]+):(\d+)$/', (string) $sz['rekord'], $m) === 1) {
            $tipus = $m[1];
            $id    = (int) $m[2];

            if ($id > 0) {
                if ($tipus === 'munkalap') {
                    $w[] = '(ml_id = %d OR (fo_tipus = %s AND fo_id = %d))';
                    array_push($p, $id, 'munkalap', $id);
                } else {
                    $w[] = '((fo_tipus = %s AND fo_id = %d) OR (tabla = %s AND sor_id = %d))';
                    array_push($p, $tipus, $id, $tipus, $id);
                }
            } else {
                $w[] = '(fo_tipus = %s OR tabla = %s)';
                array_push($p, $tipus, $tipus);
            }
        }

        if (!empty($sz['tipus'])) {
            $k = self::kategoriak()[(string) $sz['tipus']] ?? null;

            if ($k !== null) {
                $resz = [];

                foreach ($k['tablak'] as $t) {
                    $resz[] = 'tabla = %s';
                    $p[] = $t;
                }

                if (!empty($k['fajta'])) {
                    $ml = SDH_Muhely_Schema::tabla('munkalap');
                    $w[] = "(fo_tipus = 'munkalap' AND fo_id IN (SELECT id FROM {$ml} WHERE fajta = %s))";
                    $p[] = $k['fajta'];
                } elseif ($resz !== []) {
                    $w[] = '(' . implode(' OR ', $resz) . ')';
                }
            }
        }

        // Nem adminisztrátor: a beállítások és a napló védelmi bejegyzései nélkül.
        if (!empty($sz['_korlat'])) {
            $w[] = "tabla NOT IN ('beallitas', 'valtozasnaplo')";
        }

        if (!empty($sz['felhasznalo'])) {
            $w[] = 'felhasznalo = %d';
            $p[] = (int) $sz['felhasznalo'];
        }

        if (!empty($sz['forras'])) {
            $w[] = 'forras = %s';
            $p[] = sanitize_key((string) $sz['forras']);
        }

        if (!empty($sz['muvelet'])) {
            $w[] = 'muvelet = %s';
            $p[] = sanitize_key((string) $sz['muvelet']);
        }

        if (!empty($sz['tol']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $sz['tol']) === 1) {
            $w[] = 'ido >= %s';
            $p[] = $sz['tol'] . ' 00:00:00';
        }

        if (!empty($sz['ig']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $sz['ig']) === 1) {
            $w[] = 'ido <= %s';
            $p[] = $sz['ig'] . ' 23:59:59';
        }

        if (!empty($sz['keres'])) {
            $w[] = 'keres = %s';
            $p[] = preg_replace('/[^a-f0-9]/', '', (string) $sz['keres']);
        }

        if (!empty($sz['munkalap'])) {
            $szam = (int) preg_replace('/\D+/', '', (string) $sz['munkalap']);
            $ml   = SDH_Muhely_Schema::tabla('munkalap');
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $id   = $szam > 0 ? (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$ml} WHERE munkalap_szam = %d", $szam)) : 0;
            $w[] = 'ml_id = %d';
            $p[] = $id > 0 ? $id : -1;
        }

        if (!empty($sz['kereses'])) {
            $q = '%' . $wpdb->esc_like(mb_substr((string) $sz['kereses'], 0, 100)) . '%';
            $w[] = '(osszegzes LIKE %s OR valtozas LIKE %s OR megjegyzes LIKE %s OR felhasznalo_nev LIKE %s)';
            array_push($p, $q, $q, $q, $q);
        }

        return [implode(' AND ', $w), $p];
    }

    /**
     * Bejegyzések a szűrő szerint, a legújabb elöl.
     *
     * @param array<string, mixed> $sz
     * @return array{sorok: array<int, object>, osszes: int}
     */
    public static function lista(array $sz = [], int $lap = 1, int $egy = 100, bool $regiek_elol = false): array
    {
        global $wpdb;

        self::rogzit();

        if (!self::kesz()) {
            return ['sorok' => [], 'osszes' => 0];
        }

        [$w, $p] = self::feltetel($sz);
        $t   = self::tabla();
        $egy = min(1000, max(1, $egy));
        $off = max(0, $lap - 1) * $egy;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $osszes = (int) $wpdb->get_var($p === [] ? "SELECT COUNT(*) FROM {$t} WHERE {$w}" : $wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$w}", $p));
        $sql    = "SELECT * FROM {$t} WHERE {$w} ORDER BY id " . ($regiek_elol ? 'ASC' : 'DESC') . ' LIMIT %d OFFSET %d';
        $sorok  = $wpdb->get_results($wpdb->prepare($sql, array_merge($p, [$egy, $off])));
        // phpcs:enable

        return ['sorok' => is_array($sorok) ? $sorok : [], 'osszes' => $osszes];
    }

    /**
     * Összesítés: hány bejegyzés felhasználónként, kategóriánként, naponként.
     *
     * @param array<string, mixed> $sz
     * @return array<string, mixed>
     */
    public static function osszesites(array $sz = []): array
    {
        global $wpdb;

        self::rogzit();

        if (!self::kesz()) {
            return [];
        }

        [$w, $p] = self::feltetel($sz);
        $t = self::tabla();

        $futtat = static function (string $sql) use ($p, $wpdb): array {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $r = $p === [] ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql, $p));

            return is_array($r) ? $r : [];
        };

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $kik    = $futtat("SELECT felhasznalo_nev AS nev, COUNT(*) AS db FROM {$t} WHERE {$w} GROUP BY felhasznalo_nev ORDER BY db DESC LIMIT 30");
        $tablak = $futtat("SELECT tabla, COUNT(*) AS db FROM {$t} WHERE {$w} GROUP BY tabla ORDER BY db DESC LIMIT 40");
        $napok  = $futtat("SELECT SUBSTRING(ido, 1, 10) AS nap, COUNT(*) AS db FROM {$t} WHERE {$w} GROUP BY SUBSTRING(ido, 1, 10) ORDER BY nap DESC LIMIT 62");
        $muv    = $futtat("SELECT muvelet, COUNT(*) AS db FROM {$t} WHERE {$w} GROUP BY muvelet ORDER BY db DESC");
        $forr   = $futtat("SELECT forras, COUNT(*) AS db FROM {$t} WHERE {$w} GROUP BY forras ORDER BY db DESC");
        // phpcs:enable

        return [
            'felhasznalonkent' => array_map(static fn ($r) => ['nev' => (string) $r->nev, 'db' => (int) $r->db], $kik),
            'tablankent'       => array_map(static fn ($r) => ['mi' => self::tabla_nev((string) $r->tabla), 'db' => (int) $r->db], $tablak),
            'naponta'          => array_map(static fn ($r) => ['nap' => (string) $r->nap, 'db' => (int) $r->db], $napok),
            'muveletenkent'    => array_map(static fn ($r) => ['muvelet' => self::muvelet_nev((string) $r->muvelet), 'db' => (int) $r->db], $muv),
            'forrasonkent'     => array_map(static fn ($r) => ['forras' => self::forras_nev((string) $r->forras), 'db' => (int) $r->db], $forr),
        ];
    }

    /**
     * Az egymás utáni, ugyanabból a kérésből jövő, ugyanarra a rekordra
     * vonatkozó sorok egy csoportba (egy kártya a felületen).
     *
     * @param array<int, object> $sorok
     * @return array<int, array{fej: object, sorok: array<int, object>}>
     */
    public static function csoportosit(array $sorok): array
    {
        $ki = [];

        foreach ($sorok as $s) {
            // Egy kártya: ugyanaz a kérés, rekord, felhasználó, forrás és megjegyzés (az AI-lépés külön kártya).
            $kulcs = $s->keres . '|' . $s->fo_tipus . '|' . $s->fo_id . '|' . $s->felhasznalo . '|' . $s->forras . '|' . $s->megjegyzes;
            $n     = count($ki);

            if ($n > 0 && $ki[$n - 1]['kulcs'] === $kulcs) {
                $ki[$n - 1]['sorok'][] = $s;
                continue;
            }

            $ki[] = ['kulcs' => $kulcs, 'fej' => $s, 'sorok' => [$s]];
        }

        return $ki;
    }

    /**
     * A napló épségének ellenőrzése: minden sor aláírása, és a hézagok.
     *
     * @return array{db: int, hibas: array<int, int>, hibas_db: int, hezagok: array<int, array{0: int, 1: int}>, ok: bool, ido: string}
     */
    public static function ellenoriz(): array
    {
        global $wpdb;

        self::rogzit();

        $ki = ['db' => 0, 'hibas' => [], 'hibas_db' => 0, 'hezagok' => [], 'ok' => true, 'ido' => current_time('mysql')];

        if (!self::kesz()) {
            return $ki;
        }

        $t    = self::tabla();
        $utso = 0;
        $elozo = 0;

        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sorok = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE id > %d ORDER BY id ASC LIMIT 2000", $utso), ARRAY_A);

            foreach ((array) $sorok as $s) {
                $id = (int) $s['id'];
                $ki['db']++;

                if ($elozo > 0 && $id !== $elozo + 1 && count($ki['hezagok']) < 50) {
                    $ki['hezagok'][] = [$elozo + 1, $id - 1];
                }

                if (!hash_equals(self::alairas($s), (string) $s['hash'])) {
                    $ki['hibas_db']++;

                    if (count($ki['hibas']) < 50) {
                        $ki['hibas'][] = $id;
                    }
                }

                $elozo = $id;
                $utso  = $id;
            }
        } while (is_array($sorok) && count($sorok) === 2000);

        $ki['ok'] = $ki['hibas_db'] === 0 && $ki['hezagok'] === [];

        update_option(self::OPT . '_ellenorzes', ['ido' => $ki['ido'], 'db' => $ki['db'], 'ok' => $ki['ok'], 'hibas_db' => $ki['hibas_db'], 'hezag' => count($ki['hezagok'])], false);

        return $ki;
    }

    /** Hány bejegyzés van összesen és ma. @return array{osszes: int, ma: int} */
    public static function szamok(): array
    {
        global $wpdb;

        self::rogzit();

        if (!self::kesz()) {
            return ['osszes' => 0, 'ma' => 0];
        }

        $t = self::tabla();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return [
            'osszes' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"),
            'ma'     => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE ido >= %s", current_time('Y-m-d') . ' 00:00:00')),
        ];
        // phpcs:enable
    }

    /* =================================================================
     * Kijelzés: nevek és értékek magyarul
     * ============================================================== */

    /**
     * A szűrhető kategóriák (a felület választója és az AI eszköze).
     *
     * @return array<string, array{cim: string, tablak: array<int, string>, fajta?: string}>
     */
    public static function kategoriak(): array
    {
        return [
            'munkalap'     => ['cim' => 'Munkalapok', 'tablak' => [], 'fajta' => 'javitas'],
            'arukiadas'    => ['cim' => 'Napi árukiadás', 'tablak' => [], 'fajta' => 'arukiadas'],
            'rendeles'     => ['cim' => 'Rendelések', 'tablak' => [], 'fajta' => 'rendeles'],
            'termek'       => ['cim' => 'Termékek és készlet', 'tablak' => ['termek', 'termek_mozgas']],
            'uzenet'       => ['cim' => 'Ügyfélüzenetek és SMS', 'tablak' => ['uzenet', 'sms_uzenet', 'sms_dontes', 'sms_leiratkozas']],
            'csapat'       => ['cim' => 'Csapatüzenetek', 'tablak' => ['csapat_uzenet', 'csapat_beszelgetes', 'csapat_csoport', 'csapat_csoport_tag']],
            'cetli'        => ['cim' => 'Üzenőfal (cetlik)', 'tablak' => ['csapat_cetli']],
            'naptar'       => ['cim' => 'Naptár', 'tablak' => ['naptar_esemeny', 'naptar_szemely']],
            'ugyfel'       => ['cim' => 'Ügyfelek', 'tablak' => ['ugyfel']],
            'eszkoz'       => ['cim' => 'Eszközök', 'tablak' => ['eszkoz']],
            'szolgaltatas' => ['cim' => 'Szolgáltatások', 'tablak' => ['szolgaltatas']],
            'penztar'      => ['cim' => 'Házipénztár', 'tablak' => ['penztar', 'penztar_nap']],
            'szamla'       => ['cim' => 'Számlák', 'tablak' => ['szamla']],
            'beallitas'    => ['cim' => 'Beállítások', 'tablak' => ['beallitas']],
            'naplo'        => ['cim' => 'A napló védelme', 'tablak' => ['valtozasnaplo']],
        ];
    }

    public static function tabla_nev(string $t): string
    {
        $n = [
            'munkalap' => 'Munkalap', 'munkalap_hiba' => 'Hiba', 'munkalap_eszkoz' => 'Csatolt eszköz', 'munkalap_tetel' => 'Tétel',
            'uzenet' => 'Ügyfélüzenet (RMA)', 'szamla' => 'Számla', 'termek' => 'Termék', 'termek_mozgas' => 'Készletmozgás',
            'ugyfel' => 'Ügyfél', 'eszkoz' => 'Eszköz', 'szolgaltatas' => 'Szolgáltatás', 'penztar' => 'Pénztártétel',
            'penztar_nap' => 'Pénztárnap', 'csapat_uzenet' => 'Csapatüzenet', 'csapat_beszelgetes' => 'Beszélgetés',
            'csapat_csoport' => 'Csapatcsoport', 'csapat_csoport_tag' => 'Csoporttagság', 'csapat_cetli' => 'Cetli (üzenőfal)',
            'naptar_esemeny' => 'Naptáresemény', 'naptar_szemely' => 'Naptár (személy)', 'helyszin' => 'Helyszín',
            'beszallito' => 'Beszállító', 'sms_sablon' => 'SMS-sablon', 'sms_szabaly' => 'SMS-szabály', 'sms_uzenet' => 'SMS',
            'sms_dontes' => 'SMS-döntés', 'sms_leiratkozas' => 'SMS-leiratkozás', 'csatolmany' => 'Csatolmány',
            'beallitas' => 'Beállítás', 'valtozasnaplo' => 'Napló', 'naplo' => 'Napló',
        ];

        return $n[$t] ?? self::emberi($t);
    }

    public static function muvelet_nev(string $m): string
    {
        return [
            'letrehozas' => 'Létrehozás', 'modositas' => 'Módosítás', 'torles' => 'Törlés', 'tomeges' => 'Tömeges írás',
            'nyers' => 'Összetett írás', 'beallitas' => 'Beállítás', 'esemeny' => 'Esemény', 'nyomtatas' => 'Nyomtatás',
            'elutasitva' => 'Elutasítva', 'vedelem' => 'Védelem',
        ][$m] ?? $m;
    }

    public static function forras_nev(string $f): string
    {
        return [
            'felulet' => 'Felület', 'ai' => 'AI-asszisztens', 'ai-visszaallitas' => 'AI-visszaállítás', 'idozitett' => 'Időzített feladat',
            'ugyfeloldal' => 'Ügyféloldal', 'webhook' => 'SMS-átjáró / webhook', 'cli' => 'Parancssor', 'rendszer' => 'Rendszer',
        ][$f] ?? ($f !== '' ? $f : '—');
    }

    /** Mezőnév magyarul. */
    public static function mezo_nev(string $tabla, string $mezo): string
    {
        $kulon = [
            'munkalap.nev' => 'Ügyfél neve a lapon', 'munkalap.megjegyzes' => 'Belső megjegyzés', 'munkalap.ugyfel_megjegyzes' => 'Megjegyzés',
            'munkalap.fo_kulon' => 'Fő eszköz külön befizetése', 'munkalap.fo_veg' => 'Fő eszköz végfizetése', 'munkalap.fo_allapot' => 'Fő eszköz állapota',
            'munkalap.fo_fizetve' => 'Fő eszköz fizetve', 'munkalap.fo_fizetes_ideje' => 'Fő eszköz fizetésének ideje',
            'munkalap.bevizsgalas_szolg' => 'Bevizsgálási díj (szolgáltatás)', 'munkalap_eszkoz.bev_szolg' => 'Bevizsgálási díj (szolgáltatás)', 'munkalap_eszkoz.veg' => 'Végfizetés', 'munkalap_hiba.leiras' => 'Hiba',
            'munkalap_hiba.javitas' => 'Javítás / megjegyzés', 'termek_mozgas.tipus' => 'Mozgás', 'csapat_cetli.kesz' => 'Kész',
            'naptar_esemeny.cim' => 'Esemény', 'uzenet.irany' => 'Irány', 'eszkoz.minta' => 'Feloldó minta', 'eszkoz.zarkod' => 'Feloldó kód',
        ];

        if (isset($kulon[$tabla . '.' . $mezo])) {
            return $kulon[$tabla . '.' . $mezo];
        }

        $alt = [
            'allapot' => 'Állapot', 'jelzes' => 'Jelzés', 'nev' => 'Név', 'ugyfel_id' => 'Ügyfél', 'eszkoz_id' => 'Eszköz', 'felelos' => 'Felelős',
            'keszult' => 'Felvéve', 'hatarido' => 'Határidő', 'lezarva' => 'Lezárva', 'fizetve' => 'Fizetve', 'fizetes_ideje' => 'Fizetés ideje',
            'fizetesi_mod' => 'Fizetési mód', 'fizetett' => 'Fizetett', 'bevizsgalasi_dij' => 'Bevizsgálási díj', 'bevizsgalas_mod' => 'Bevizsgálás módja',
            'bevizsgalas_nev' => 'Bevizsgálás szövege', 'kedvezmeny' => 'Kedvezmény (%)', 'afakulcs' => 'Áfakulcs', 'netto_ertek' => 'Nettó érték',
            'brutto_ertek' => 'Bruttó érték', 'megjegyzes' => 'Megjegyzés', 'munkalap_szam' => 'Munkalapszám', 'fajta' => 'Fajta',
            'helyszin_id' => 'Helyszín', 'beszallito_id' => 'Beszállító', 'rend_allapot' => 'Rendelés útja', 'rend_szam' => 'Rendelésszám',
            'rend_ertesitve' => 'Értesítve', 'tipus' => 'Típus', 'megnevezes' => 'Megnevezés', 'mennyiseg' => 'Mennyiség', 'me' => 'Mennyiségi egység',
            'brutto_ar' => 'Bruttó ár', 'netto_ar' => 'Nettó ár', 'afa_kulcs' => 'Áfakulcs', 'sorrend' => 'Sorrend', 'leiras' => 'Leírás',
            'javitas' => 'Javítás', 'szoveg' => 'Szöveg', 'kinek' => 'Kinek', 'kitol' => 'Kitől', 'szin' => 'Szín', 'torolve' => 'Törölve',
            'kesz' => 'Kész', 'kezdet' => 'Kezdet', 'veg' => 'Vég', 'egesz_nap' => 'Egész nap', 'kategoria' => 'Kategória', 'prioritas' => 'Fontosság',
            'hely' => 'Hely', 'ismetles' => 'Ismétlődés', 'privat' => 'Privát', 'szemely_id' => 'Kié', 'resztvevok' => 'Résztvevők',
            'emlekeztetok' => 'Emlékeztetők', 'keszlet' => 'Készlet', 'min_keszlet' => 'Minimum készlet', 'cikkszam' => 'Cikkszám',
            'vonalkod' => 'Vonalkód', 'termekkod' => 'Termékkód', 'besz_netto' => 'Beszerzési nettó ár', 'aktiv' => 'Aktív',
            'termek_id' => 'Termék', 'telefon' => 'Telefon', 'telefon2' => 'Telefon 2', 'email' => 'E-mail', 'adoszam' => 'Adószám',
            'gyarto' => 'Gyártó', 'imei' => 'IMEI', 'imei2' => 'IMEI 2', 'sorozatszam' => 'Sorozatszám', 'modell_szam' => 'Modellszám',
            'tartozekok' => 'Tartozékok', 'atveteli_allapot' => 'Átvételi állapot', 'belso_megjegyzes' => 'Belső megjegyzés',
            'kp' => 'Készpénz', 'kartya' => 'Bankkártya', 'utalas' => 'Átutalás', 'info_osszeg' => 'Összeg (nem kassza)',
            'szamlaszam' => 'Számlaszám', 'datum' => 'Dátum', 'szemely' => 'Személy', 'felhasznalo' => 'Felhasználó', 'felado_id' => 'Feladó',
            'fontos' => 'Fontos', 'kituzve' => 'Kitűzve', 'letrehozo' => 'Létrehozta', 'letrehozta' => 'Létrehozta', 'letrehozva' => 'Létrehozva',
            'bev_dij' => 'Bevizsgálási díj', 'bev_nev' => 'Bevizsgálás szövege', 'irany' => 'Irány', 'csatorna' => 'Csatorna',
            'email_kuldve' => 'E-mailben elküldve', 'szam' => 'Telefonszám', 'kivalto' => 'Kiváltó', 'hiba' => 'Hiba', 'elkuldve' => 'Elküldve',
            'kezbesitve' => 'Kézbesítve', 'dontes' => 'Döntés', 'ok' => 'Ok', 'tiltva' => 'Tiltva', 'nyito' => 'Nyitó', 'szamolt' => 'Számolt',
            'elteres' => 'Eltérés', 'cimletek' => 'Címletek', 'lezarta' => 'Lezárta', 'osszeg' => 'Összeg', 'kozvetlen' => 'Közvetlen',
            'szamlazasi_iranyitoszam' => 'Irányítószám', 'szamlazasi_telepules' => 'Település', 'szamlazasi_cim' => 'Cím',
            'kapcsolattarto' => 'Kapcsolattartó', 'garancias' => 'Garanciális', 'vasarlas_datuma' => 'Vásárlás dátuma',
            'garancia_lejar' => 'Garancia lejár', 'eredeti_nev' => 'Fájl', 'tarolt_nev' => 'Tárolt fájl', 'feltolto' => 'Feltöltötte',
            'kod' => 'Kód', 'ar_max' => 'Ár (felső határ)', 'bevizsgalas' => 'Bevizsgálási díj', 'link' => 'Link', 'jelolt' => 'Jelölt',
        ];

        return $alt[$mezo] ?? self::emberi($mezo);
    }

    private static function emberi(string $s): string
    {
        $s = str_replace('_', ' ', $s);

        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /**
     * Mezőérték magyarul (állapotnév, felhasználónév, pénz, dátum, igen/nem).
     *
     * @param mixed $v
     */
    public static function ertek(string $tabla, string $mezo, $v, bool $ai = false): string
    {
        if ($ai && in_array($mezo, self::AI_REJTETT, true)) {
            return $v === null || $v === '' ? '—' : '(rejtett)';
        }

        // H6 (0.79): a feloldó kód / minta titkosítva áll – csak a jogosult látja, visszafejtve.
        if ($tabla === 'eszkoz' && in_array($mezo, self::AI_REJTETT, true) && $v !== null && $v !== '' && class_exists('SDH_Muhely_Zarkod')) {
            if (!SDH_Muhely_Zarkod::lathat()) {
                return '(rejtett)';
            }

            $v = SDH_Muhely_Zarkod::olvas((string) $v);

            return $v !== '' ? $v : '(titkosítva – ezen a gépen nem olvasható)';
        }

        if ($v === null || $v === '') {
            return '—';
        }

        if (is_array($v) || is_object($v)) {
            return (string) wp_json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        $v = (string) $v;

        if (str_starts_with($v, '•••')) {
            return $v;
        }

        if ($mezo === 'allapot' && class_exists('SDH_Muhely_Munkalap')) {
            if (in_array($tabla, ['munkalap', 'munkalap_eszkoz'], true)) {
                return (string) (SDH_Muhely_Munkalap::allapotok()[$v]['nev'] ?? $v);
            }

            if ($tabla === 'munkalap_hiba') {
                return (string) (SDH_Muhely_Munkalap::hiba_allapotok()[$v]['nev'] ?? $v);
            }
        }

        if ($mezo === 'fajta' && class_exists('SDH_Muhely_Munkalap')) {
            return SDH_Muhely_Munkalap::fajta_nev($v);
        }

        if (in_array($mezo, ['felelos', 'letrehozo', 'letrehozta', 'felhasznalo', 'felado_id', 'feltolto', 'lezarta', 'user_id', 'dontes_user', 'hivo'], true)) {
            $id = (int) $v;

            if ($id <= 0) {
                return '—';
            }

            $u = get_userdata($id);

            return $u ? (string) ($u->display_name ?: $u->user_login) : '#' . $id;
        }

        if ($mezo === 'ugyfel_id') {
            return (int) $v > 0 ? self::rekord_cimke('ugyfel', (int) $v) : '—';
        }

        if ($mezo === 'eszkoz_id') {
            if ((int) $v <= 0) {
                return in_array($tabla, ['munkalap_hiba', 'munkalap_tetel', 'penztar'], true) ? 'fő eszköz' : '—';
            }

            return self::rekord_cimke('eszkoz', (int) $v);
        }

        if ($mezo === 'termek_id') {
            return self::rekord_cimke('termek', (int) $v);
        }

        if ($mezo === 'szemely_id' && $tabla === 'naptar_esemeny') {
            return self::rekord_cimke('naptar_szemely', (int) $v);
        }

        if (in_array($mezo, ['helyszin_id', 'beszallito_id'], true)) {
            return (int) $v > 0 ? self::rekord_cimke(substr($mezo, 0, -3), (int) $v) : '—';
        }

        if (preg_match('/^(fizetve|fo_fizetve|aktiv|torolve|kesz|fontos|kituzve|privat|egesz_nap|garancias|bevizsgalasi_dij|keszletkezeles|email_kuldve|tiltva|jelolt|ertesites|email|bevizsgalas|parositva)$/', $mezo) === 1 && preg_match('/^[01]$/', $v) === 1) {
            return $v === '1' ? 'igen' : 'nem';
        }

        if (preg_match('/(^|_)(ar|ertek|fizetett|kulon|veg|dij|osszeg|netto|brutto|nyito|szamolt|elteres|kp|kartya|utalas|kifizetes|kivet|befizetes|zaro|ar_max)$/', $mezo) === 1 && is_numeric($v)) {
            return number_format((float) $v, 0, ',', ' ') . ' Ft';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v) === 1) {
            if (str_starts_with($v, '0000')) {
                return '—';
            }

            return strlen($v) > 10 ? str_replace('-', '.', substr($v, 0, 16)) : str_replace('-', '.', $v) . '.';
        }

        if ($mezo === 'irany') {
            return $v === 'be' ? 'bejövő' : ($v === 'ki' ? 'kimenő' : $v);
        }

        return mb_strlen($v) > 600 ? mb_substr($v, 0, 600) . '…' : $v;
    }

    /** Egy rekord rövid neve (pl. „Munkalap 000123 – Minta Márta"). */
    public static function rekord_cimke(string $tipus, int $id): string
    {
        global $wpdb;

        if ($tipus === 'beallitas') {
            return 'Beállítások';
        }

        if ($id <= 0) {
            return self::tabla_nev($tipus);
        }

        $kulcs = $tipus . ':' . $id;

        if (isset(self::$cimke_gyorsitotar[$kulcs])) {
            return self::$cimke_gyorsitotar[$kulcs];
        }

        $cimke = self::tabla_nev($tipus) . ' #' . $id;

        try {
            if (in_array($tipus, self::KIHAGYOTT, true) || preg_match('/^[a-z_]+$/', $tipus) !== 1) {
                return self::$cimke_gyorsitotar[$kulcs] = $cimke;
            }

            $t = SDH_Muhely_Schema::tabla($tipus);

            self::bent(static function () use ($wpdb, $t, $id, $tipus, &$cimke): void {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id), ARRAY_A);

                if (!is_array($s)) {
                    $cimke .= ' (már nincs meg)';

                    return;
                }

                if ($tipus === 'munkalap' && class_exists('SDH_Muhely_Munkalap')) {
                    $szam  = (int) $s['munkalap_szam'] > 0 ? SDH_Muhely_Munkalap::szam_formaz($s['munkalap_szam']) : '#' . $id;
                    $cimke = SDH_Muhely_Munkalap::fajta_nev((string) ($s['fajta'] ?? 'javitas')) . ' ' . $szam . (trim((string) $s['nev']) !== '' ? ' – ' . $s['nev'] : '');

                    return;
                }

                if ($tipus === 'eszkoz' && class_exists('SDH_Muhely_Eszkoz')) {
                    $cimke = SDH_Muhely_Eszkoz::megnevezes((object) $s);

                    return;
                }

                if ($tipus === 'naptar_esemeny' && !empty($s['privat']) && (int) ($s['letrehozta'] ?? 0) !== get_current_user_id()) {
                    $cimke = 'Naptáresemény (privát)';

                    return;
                }

                foreach (['megnevezes', 'nev', 'cim', 'szamlaszam', 'leiras', 'szoveg', 'datum'] as $m) {
                    if (isset($s[$m]) && trim((string) $s[$m]) !== '') {
                        $cimke = self::tabla_nev($tipus) . ': ' . mb_substr(trim(wp_strip_all_tags((string) $s[$m])), 0, 60);

                        return;
                    }
                }
            });
        } catch (Throwable $h) {
            // marad az alap felirat
        }

        return self::$cimke_gyorsitotar[$kulcs] = $cimke;
    }

    /**
     * Rövid összefoglaló a bejegyzéshez (a naplóírás pillanatában készül,
     * így a később átnevezett állapot / törölt felhasználó is olvasható marad).
     *
     * @param array<string, mixed> $mezok
     * @param array<string, mixed> $sor
     */
    private static function osszegzes(string $muvelet, string $tabla, array $mezok, array $sor): string
    {
        $mi = self::tabla_nev($tabla);
        $nev = '';

        foreach (['megnevezes', 'leiras', 'nev', 'cim', 'szoveg', 'szamlaszam'] as $m) {
            if (isset($sor[$m]) && trim((string) $sor[$m]) !== '') {
                $nev = mb_substr(trim(wp_strip_all_tags((string) $sor[$m])), 0, 50);
                break;
            }
        }

        if ($muvelet === 'letrehozas') {
            return 'Új ' . mb_strtolower($mi) . ($nev !== '' ? ': ' . $nev : '');
        }

        if ($muvelet === 'torles') {
            return 'Törölve: ' . mb_strtolower($mi) . ($nev !== '' ? ' (' . $nev . ')' : '');
        }

        $reszek = [];

        foreach ($mezok as $m => $par) {
            if (count($reszek) >= 3) {
                $reszek[] = '…';
                break;
            }

            $reszek[] = self::mezo_nev($tabla, (string) $m) . ': ' . mb_substr(self::ertek($tabla, (string) $m, $par[0], true), 0, 40) . ' → ' . mb_substr(self::ertek($tabla, (string) $m, $par[1], true), 0, 40);
        }

        return ($tabla !== 'munkalap' ? $mi . ($nev !== '' ? ' (' . $nev . ')' : '') . ' – ' : '') . implode('; ', $reszek);
    }

    /**
     * Egy bejegyzés változásai sorokra bontva, magyarul.
     *
     * @return array<int, array{mezo: string, regi: string, uj: string}>
     */
    public static function reszletek(object $s, bool $ai = false): array
    {
        $v = json_decode((string) $s->valtozas, true);
        $v = is_array($v) ? $v : [];
        $t = (string) $s->tabla;
        $ki = [];

        // Privát naptáresemény: más nem látja a részleteit.
        if ($t === 'naptar_esemeny' && self::privat_mas($v)) {
            return [['mezo' => 'Esemény', 'regi' => '', 'uj' => 'Privát esemény – a részletek csak a tulajdonosának látszanak.']];
        }

        switch ((string) $s->muvelet) {
            case 'modositas':
                foreach ($v as $m => $par) {
                    $ki[] = ['mezo' => self::mezo_nev($t, (string) $m), 'regi' => self::ertek($t, (string) $m, $par[0] ?? null, $ai), 'uj' => self::ertek($t, (string) $m, $par[1] ?? null, $ai)];
                }
                break;
            case 'letrehozas':
            case 'torles':
                foreach ($v as $m => $ert) {
                    if (in_array($m, ['letrehozva', 'feltoltve', 'sorrend', 'munkalap_id', 'forras', 'kulso_azonosito'], true)) {
                        continue;
                    }

                    $ki[] = ['mezo' => self::mezo_nev($t, (string) $m), 'regi' => $s->muvelet === 'torles' ? self::ertek($t, (string) $m, $ert, $ai) : '', 'uj' => $s->muvelet === 'letrehozas' ? self::ertek($t, (string) $m, $ert, $ai) : ''];
                }
                break;
            case 'beallitas':
                foreach ((array) ($v['mezok'] ?? []) as $m => $par) {
                    $ki[] = ['mezo' => (string) $m, 'regi' => self::ertek('beallitas', (string) $m, $par[0] ?? null, $ai), 'uj' => self::ertek('beallitas', (string) $m, $par[1] ?? null, $ai)];
                }
                break;
            case 'tomeges':
                $ki[] = ['mezo' => 'Érintett sorok', 'regi' => '', 'uj' => (string) (int) ($v['db'] ?? 0)];
                break;
            case 'esemeny':
            case 'nyomtatas':
            case 'elutasitva':
                foreach ((array) ($v['adat'] ?? []) as $m => $ert) {
                    $ki[] = ['mezo' => self::emberi((string) $m), 'regi' => '', 'uj' => is_scalar($ert) ? (string) $ert : (string) wp_json_encode($ert, JSON_UNESCAPED_UNICODE)];
                }
                break;
            default:
                if (isset($v['sql'])) {
                    $ki[] = ['mezo' => 'Lekérdezés', 'regi' => '', 'uj' => mb_substr((string) $v['sql'], 0, 300)];
                }
        }

        return $ki;
    }

    /** @param array<string, mixed> $v */
    private static function privat_mas(array $v): bool
    {
        $privat = $v['privat'] ?? null;
        $tul    = $v['letrehozta'] ?? null;

        if (is_array($privat)) {
            $privat = ($privat[0] ?? '0') === '1' || ($privat[1] ?? '0') === '1' ? '1' : '0';
        }

        if ($privat !== '1') {
            return false;
        }

        $tul = is_array($tul) ? ($tul[1] ?? $tul[0] ?? 0) : $tul;

        return (int) $tul !== get_current_user_id();
    }

    /**
     * Idővonal szövegként az AI-asszisztensnek (régiek elöl, tömören).
     *
     * @param array<string, mixed> $sz
     */
    public static function idovonal_szoveg(array $sz, int $max = 40): string
    {
        $l = self::lista($sz, 1, $max);

        if ($l['sorok'] === []) {
            return '(nincs bejegyzés)';
        }

        $sorok = array_reverse($l['sorok']);
        $ki = '';

        foreach (self::csoportosit($sorok) as $cs) {
            $f = $cs['fej'];
            $ki .= '- ' . substr((string) $f->ido, 0, 16) . ' · ' . $f->felhasznalo_nev . ' (' . self::forras_nev((string) $f->forras) . ')'
                . ((string) $f->megjegyzes !== '' ? ' [' . $f->megjegyzes . ']' : '') . ":\n";

            foreach ($cs['sorok'] as $s) {
                $r = self::reszletek($s, true);
                $ki .= '    ' . self::muvelet_nev((string) $s->muvelet) . ' – ' . self::tabla_nev((string) $s->tabla);

                if (in_array($s->muvelet, ['modositas', 'beallitas'], true)) {
                    $ki .= ': ' . implode('; ', array_map(static fn ($x) => $x['mezo'] . ': ' . mb_substr($x['regi'], 0, 80) . ' → ' . mb_substr($x['uj'], 0, 80), array_slice($r, 0, 8)));
                } else {
                    $ki .= ': ' . mb_substr((string) $s->osszegzes, 0, 160);
                }

                $ki .= "\n";
            }
        }

        if ($l['osszes'] > $max) {
            $ki = '(a legutóbbi ' . $max . ' bejegyzés a ' . $l['osszes'] . '-ből)' . "\n" . $ki;
        }

        return $ki;
    }
}
