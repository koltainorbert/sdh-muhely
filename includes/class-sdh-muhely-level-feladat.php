<?php
/**
 * Levél-feladat ügynök: a visszatérő, egyforma leveleket teendővé alakítja.
 *
 * MIÉRT: a postafiókba naponta érkeznek olyan levelek, amelyekkel mindig
 * UGYANAZ a dolgunk (beszállítói számla: mappába, kinyomtatni; megrendelés-
 * visszaigazolás: címkét nyomtatni a tételekre). Ez eddig kézi munka volt, és
 * könnyen elúszott: a levél elkeveredett, a számla nem lett kinyomtatva.
 *
 * MIT CSINÁL. Szabályalapú, nincs benne mesterséges intelligencia:
 *   1. a beérkező levélre ráilleszti a szabályokat (feladó, tárgy, csatolmány),
 *   2. ha illik, FELADATOT nyit rá (a feladat a levélhez tartozik, és addig
 *      nyitott marad, amíg el nem intézed),
 *   3. a levelet a szabályban megadott mappába teszi (ha kell, létre is hozza),
 *   4. a tételolvasóval kinyeri a levélből, amire címke kell,
 *   5. a nyitott feladatokról jelez, amíg el nem intézed őket.
 *
 * MIT NEM CSINÁL: nem töröl levelet, nem válaszol, nem küld semmit, nem
 * módosít munkalapot, ügyfelet vagy pénztárt. Amit nyomtat, azt is csak
 * gombnyomásra. A levél szövege ADAT: a benne álló „utasításoknak" nem
 * engedelmeskedik – a szabályok a feladótól és a tárgytól függnek, nem attól,
 * amit a levél kér.
 *
 * ÁLTALÁNOS: a szabályokat a Beállítások › Levelezés › Levél-feladat ügynök
 * lapon bárki összerakhatja a saját beszállítójára. A tételolvasók
 * bejelenthetők (olvaso_regisztral) – az SDH saját olvasói az
 * includes/sdh/ mappában élnek, és eladáskor nem mennek ki.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Level_Feladat
{
    /** A szabályok tárolója. */
    public const OPT = 'sdh_muhely_level_feladat';

    /** Legfeljebb ennyi szabály (a szinkron ne lassuljon el). */
    public const MAX_SZABALY = 20;

    /** Egy feladat legfeljebb ennyi tételt tart meg. */
    public const MAX_TETEL = 60;

    /**
     * Bejelentett tételolvasók: kulcs => ['cim' => …, 'hivas' => callable].
     * A hívás a levél szövegét kapja, és tételek tömbjét adja vissza.
     *
     * @var array<string, array{cim: string, hivas: callable}>
     */
    private static array $olvasok = [];

    /** A szinkron alatt összegyűlt áthelyezések: fiók|mappa => [level_id => cél mappa neve]. */
    private static array $mozgatando = [];

    public static function init(): void
    {
        foreach (['lista', 'elintez', 'ujranyit', 'cimke', 'ujra', 'beilleszt'] as $m) {
            add_action('wp_ajax_sdh_muhely_levelfeladat_' . $m, [self::class, 'ajax_' . $m]);
        }

        add_action('wp_ajax_sdh_muhely_levelfeladat_urlap', [self::class, 'ajax_urlap']);
    }

    /* =================================================================
     * Tételolvasók
     * ============================================================== */

    /**
     * Tételolvasó bejelentése. A hívás így néz ki:
     *     function (string $szoveg, array $level): array
     * és tételek listáját adja vissza; egy tétel mezői szabadok, de a
     * címkenyomtatás ezeket ismeri: keszulek, megnevezes, azonosito,
     * garancia, cikkszam, megjegyzes, telefon.
     */
    public static function olvaso_regisztral(string $kulcs, string $cim, callable $hivas): void
    {
        self::$olvasok[sanitize_key($kulcs)] = ['cim' => $cim, 'hivas' => $hivas];
    }

    /** @return array<string, array{cim: string, hivas: callable}> */
    public static function olvasok(): array
    {
        return self::$olvasok;
    }

    /* =================================================================
     * Szabályok
     * ============================================================== */

    /**
     * A szabályok. Egy szabály:
     *   kulcs, nev, aktiv, fiok ('' = mind)
     *   ha:   felado (tartalmazza), targy (tartalmazza), csatolmany ('' | 'pdf')
     *   tesz: mappa (cél mappa neve; '' = marad), ertesit, nyomtat ('' | 'csatolmany'), olvaso ('' | kulcs)
     *
     * @return array<string, array<string, mixed>>
     */
    public static function szabalyok(): array
    {
        $mentett = get_option(self::OPT, []);
        $ki      = [];

        foreach (is_array($mentett) ? $mentett : [] as $kulcs => $sz) {
            if (!is_array($sz) || count($ki) >= self::MAX_SZABALY) {
                continue;
            }

            $ki[sanitize_key((string) $kulcs)] = self::szabaly_szukit($sz);
        }

        return $ki;
    }

    /**
     * @param array<string, mixed> $sz
     * @return array<string, mixed>
     */
    public static function szabaly_szukit(array $sz): array
    {
        $szo = static fn ($e, int $max = 190): string => mb_substr(sanitize_text_field((string) $e), 0, $max);
        $ha  = is_array($sz['ha'] ?? null) ? $sz['ha'] : [];
        $t   = is_array($sz['tesz'] ?? null) ? $sz['tesz'] : [];
        $olv = sanitize_key((string) ($t['olvaso'] ?? ''));

        $tol = (string) ($sz['tol'] ?? '');
        $tol = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tol) === 1 ? $tol : '';

        return [
            'nev'   => $szo($sz['nev'] ?? '', 60),
            'aktiv' => !empty($sz['aktiv']),
            'fiok'  => $szo($sz['fiok'] ?? '', 10),
            // Innentől figyeli a leveleket (alapból a bekapcsolás előtti nap):
            // a régi postafiókot nem dolgozzuk fel visszamenőleg.
            'tol'   => $tol,
            'ha'    => [
                'felado'     => mb_strtolower($szo($ha['felado'] ?? '')),
                'targy'      => mb_strtolower($szo($ha['targy'] ?? '')),
                'csatolmany' => ($ha['csatolmany'] ?? '') === 'pdf' ? 'pdf' : '',
            ],
            'tesz'  => [
                'mappa'   => $szo($t['mappa'] ?? '', 120),
                'ertesit' => !empty($t['ertesit']),
                'nyomtat' => ($t['nyomtat'] ?? '') === 'csatolmany' ? 'csatolmany' : '',
                // Ha a számla nem melléklet, hanem LINK a levélben: ez a minta keresi meg
                // (az URL-ben vagy a link szövegében). Pl. „szamla" vagy „gigatel.hu".
                'link'    => mb_strtolower($szo($t['link'] ?? '', 120)),
                'olvaso'  => $olv !== '' && isset(self::$olvasok[$olv]) ? $olv : '',
            ],
        ];
    }

    /** @param array<string, array<string, mixed>> $szabalyok */
    public static function ment(array $szabalyok): void
    {
        $ki   = [];
        $regi = self::szabalyok();

        foreach ($szabalyok as $kulcs => $sz) {
            $kulcs = sanitize_key((string) $kulcs);

            if ($kulcs === '' || !is_array($sz) || count($ki) >= self::MAX_SZABALY) {
                continue;
            }

            $sor = self::szabaly_szukit($sz);

            // Új szabály: a tegnapi naptól figyel (a régi leveleket nem bányássza elő).
            // A meglévőé megmarad, hogy a dátum ne csússzon minden mentésnél.
            $sor['tol'] = $sor['tol'] !== '' ? $sor['tol'] : (string) ($regi[$kulcs]['tol'] ?? self::tegnap());
            $ki[$kulcs] = $sor;

            // A kezdőnap előtti, még nyitott feladatok maguktól eltűnnek (0.61.2).
            self::takarit($kulcs, (string) $sor['tol']);
        }

        update_option(self::OPT, $ki, false);
    }

    /**
     * A kezdőnap előtti feladatok takarítása (0.61.2).
     *
     * Ha a kezdőnapot később állítod be (vagy feljebb viszed), az addig
     * összegyűlt régi feladatok maguktól eltűnnek – nem kell egyenként
     * elintézni őket. Csak a NYITOTT, saját levélhez tartozó feladatokat
     * törli; a levelekhez nem nyúl.
     */
    public static function takarit(string $szabaly, string $tol): int
    {
        global $wpdb;

        if ($tol === '' || !SDH_Muhely_Schema::naprakesz()) {
            return 0;
        }

        $t = self::tabla();
        $l = SDH_Muhely_Schema::tabla('level');

        // Soronként: a többtáblás DELETE nem minden adatbázison megy (SQLite-on
        // csendben nem csinál semmit), és a feladatokból úgysem lesz sok.
        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id, v.datum, f.letrehozva FROM {$t} f LEFT JOIN {$l} v ON v.id = f.level_id
             WHERE f.szabaly = %s AND f.allapot = 'nyitott'",
            $szabaly
        )); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $db = 0;

        foreach ((array) $sorok as $sor) {
            $datum = (string) ($sor->datum ?? '') !== '' ? (string) $sor->datum : (string) $sor->letrehozva;

            if (substr($datum, 0, 10) < $tol) {
                $db += (int) $wpdb->delete($t, ['id' => (int) $sor->id]);
            }
        }

        return $db;
    }

    /** A tegnapi nap (az új szabály innentől figyel). */
    public static function tegnap(): string
    {
        return gmdate('Y-m-d', (int) strtotime(current_time('Y-m-d') . ' -1 day'));
    }

    /** Egy szabály beírása / felülírása (az SDH belső modulja ezzel teszi be a sajátját). */
    public static function szabaly_ir(string $kulcs, array $szabaly): void
    {
        $kulcs = sanitize_key($kulcs);
        $mind  = self::szabalyok();
        $uj    = self::szabaly_szukit($szabaly);

        if ($uj['tol'] === '') {
            $uj['tol'] = (string) ($mind[$kulcs]['tol'] ?? self::tegnap());
        }

        $mind[$kulcs] = $uj;

        update_option(self::OPT, $mind, false);
        self::takarit($kulcs, (string) $uj['tol']);
    }

    /* =================================================================
     * A beérkező levél vizsgálata
     * ============================================================== */

    /**
     * Ráillik-e a szabály a levélre. Csak a feladót, a tárgyat és a csatolmány
     * meglétét nézi – a levél szövegét NEM (hogy a levél ne tudja magát
     * „beajánlani" egy szabályba).
     *
     * @param array<string, mixed> $sz  a szabály
     * @param array<string, mixed> $sor a levél sora (felado_email, targy, csatolmany, fiok)
     */
    public static function illik(array $sz, array $sor): bool
    {
        if (empty($sz['aktiv'])) {
            return false;
        }

        if ((string) $sz['fiok'] !== '' && (string) $sz['fiok'] !== (string) ($sor['fiok'] ?? '')) {
            return false;
        }

        $ha = (array) $sz['ha'];

        // Üres szabály (nincs egyetlen feltétel sem) soha nem illik: nehogy mindenre ráfusson.
        if ((string) $ha['felado'] === '' && (string) $ha['targy'] === '') {
            return false;
        }

        if ((string) $ha['felado'] !== '' && mb_strpos(mb_strtolower((string) ($sor['felado_email'] ?? '') . ' ' . (string) ($sor['felado_nev'] ?? '')), (string) $ha['felado']) === false) {
            return false;
        }

        if ((string) $ha['targy'] !== '' && mb_strpos(mb_strtolower((string) ($sor['targy'] ?? '')), (string) $ha['targy']) === false) {
            return false;
        }

        return (string) $ha['csatolmany'] !== 'pdf' || (int) ($sor['csatolmany'] ?? 0) === 1;
    }

    /**
     * Egy most beérkezett levél: szabályt keres rá, és ha talál, feladatot nyit.
     * A levelező szinkronja hívja, levelenként egyszer.
     *
     * @param array<string, mixed> $sor   a levél sora (a táblába írt mezők)
     * @param array<string, mixed> $level a feldolgozott levél (csatolmányok fejadatai)
     */
    public static function uj_level(int $level_id, int $mappa_id, array $sor, string $szoveg, array $level): void
    {
        if ($level_id <= 0 || !SDH_Muhely_Schema::naprakesz()) {
            return;
        }

        $datum = (string) ($sor['datum'] ?? '');

        foreach (self::szabalyok() as $kulcs => $sz) {
            if (!self::illik($sz, $sor)) {
                continue;
            }

            // A szabály bekapcsolása előtti levelekkel már nincs dolgunk: a régi
            // postafiókot nem dolgozzuk fel visszamenőleg (0.61.1).
            if ((string) $sz['tol'] !== '' && $datum !== '' && substr($datum, 0, 10) < (string) $sz['tol']) {
                continue;
            }

            $adat = [
                'nyomtat'  => (string) $sz['tesz']['nyomtat'],
                'olvaso'   => (string) $sz['tesz']['olvaso'],
                // A szinkron a levélnek csak az elejét tölti le (csatolmány nincs benne),
                // ezért a melléklet és a tételek a feladat ELSŐ MEGNYITÁSAKOR jönnek
                // (betolt()) – akkor már a teljes levél a kezünkben van.
                'betoltve' => false,
                'link'     => (string) $sz['tesz']['link'],
                'linkek'   => self::linkek($level, (string) $sz['tesz']['link']),
                'csatolmanyok' => self::pdf_csatolmanyok($level),
                'tetelek'  => [],
                'targy'    => (string) ($sor['targy'] ?? ''),
            ];

            if ((string) $sz['tesz']['olvaso'] !== '') {
                $adat['tetelek'] = self::tetelek_olvas((string) $sz['tesz']['olvaso'], $szoveg, $level);
            }

            self::feladat_ir($kulcs, $sz, $level_id, $sor, $adat);

            // Áthelyezés: a szinkron végén, egyben (akkor már nem zavarja a számlálókat).
            if ((string) $sz['tesz']['mappa'] !== '') {
                self::$mozgatando[(string) ($sor['fiok'] ?? '') . '|' . $mappa_id][$level_id] = (string) $sz['tesz']['mappa'];
            }
        }
    }

    /**
     * A levél PDF-csatolmányai: sorszám, név, méret. A tartalmat NEM tartjuk
     * meg – nyomtatáskor a levelezőszerverről jön, mint bármelyik csatolmány.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function pdf_csatolmanyok(array $level): array
    {
        $ki = [];

        foreach ((array) ($level['csatolmanyok'] ?? []) as $n => $c) {
            if (!empty($c['beagyazott'])) {
                continue;
            }

            $nev = (string) ($c['nev'] ?? '');

            if (stripos((string) ($c['tipus'] ?? ''), 'pdf') === false && !str_ends_with(mb_strtolower($nev), '.pdf')) {
                continue;
            }

            $ki[] = ['n' => (int) $n, 'nev' => mb_substr($nev, 0, 190), 'meret' => (int) ($c['meret'] ?? 0)];
        }

        return $ki;
    }

    /**
     * A levélben álló linkek (0.61.2). Sok beszállító nem mellékeli a számlát,
     * hanem LINKET küld rá – ilyenkor ezt kínáljuk fel megnyitásra. Csak http(s)
     * linkeket tartunk meg, és csak azokat, amelyekre a szabály mintája illik.
     *
     * @return array<int, array<string, string>>
     */
    public static function linkek(array $level, string $minta): array
    {
        $minta = trim(mb_strtolower($minta));

        if ($minta === '') {
            return [];
        }

        $html = (string) ($level['html'] ?? '');
        $ki   = [];
        $volt = [];

        if (preg_match_all('~<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $talalat, PREG_SET_ORDER) !== false) {
            foreach ($talalat as $t) {
                $url = html_entity_decode(trim((string) $t[1]), ENT_QUOTES, 'UTF-8');
                $cim = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $t[2])));

                if (!preg_match('~^https?://~i', $url) || isset($volt[$url]) || count($ki) >= 6) {
                    continue;
                }

                if (mb_strpos(mb_strtolower($url . ' ' . $cim), $minta) === false) {
                    continue;
                }

                $volt[$url] = true;
                $ki[] = ['url' => esc_url_raw($url), 'cim' => mb_substr($cim !== '' ? $cim : $url, 0, 120)];
            }
        }

        return $ki;
    }

    /** @return array<int, array<string, string>> */
    public static function tetelek_olvas(string $olvaso, string $szoveg, array $level): array
    {
        $o = self::$olvasok[$olvaso] ?? null;

        if ($o === null) {
            return [];
        }

        try {
            $tetelek = (array) call_user_func($o['hivas'], $szoveg, $level);
        } catch (\Throwable $hiba) {
            return [];
        }

        $ki = [];

        foreach ($tetelek as $t) {
            if (!is_array($t) || count($ki) >= self::MAX_TETEL) {
                continue;
            }

            $sor = [];

            foreach ($t as $k => $v) {
                $sor[sanitize_key((string) $k)] = mb_substr(sanitize_text_field((string) $v), 0, 190);
            }

            $ki[] = $sor;
        }

        return $ki;
    }

    /* =================================================================
     * Feladatok
     * ============================================================== */

    public static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('level_feladat');
    }

    /** @param array<string, mixed> $adat */
    private static function feladat_ir(string $kulcs, array $sz, int $level_id, array $sor, array $adat): void
    {
        global $wpdb;

        $mid = (string) ($sor['message_id'] ?? '');
        $mid = $mid !== '' ? $mid : 'uid:' . (string) ($sor['fiok'] ?? '') . ':' . (int) ($sor['uid'] ?? 0);
        $az  = md5($mid . '|' . $kulcs);

        $wpdb->suppress_errors(true);
        $wpdb->insert(self::tabla(), [
            'azonosito'  => $az,
            'level_id'   => $level_id,
            'message_id' => mb_substr($mid, 0, 255),
            'fiok'       => mb_substr((string) ($sor['fiok'] ?? ''), 0, 10),
            'szabaly'    => mb_substr($kulcs, 0, 40),
            'cim'        => mb_substr((string) ($sz['nev'] !== '' ? $sz['nev'] : ($sor['targy'] ?? '')), 0, 255),
            'felado'     => mb_substr((string) ($sor['felado_nev'] ?? '') !== '' ? (string) $sor['felado_nev'] : (string) ($sor['felado_email'] ?? ''), 0, 190),
            'allapot'    => 'nyitott',
            'adat'       => wp_json_encode($adat),
            'letrehozva' => current_time('mysql'),
        ]);
        $wpdb->suppress_errors(false);

        // Ugyanarra a levélre már volt feladat (a levél mappát váltott): a mutató frissül.
        if ((int) $wpdb->insert_id === 0) {
            $wpdb->update(self::tabla(), ['level_id' => $level_id], ['azonosito' => $az]);
        }
    }

    /**
     * A szinkron végén: az összegyűlt áthelyezések végrehajtása.
     * A levelező adja az IMAP-kapcsolatot – ez az osztály nem nyúl a postafiókhoz.
     *
     * @return array<string, array<int, int>> cél mappa neve => levél-azonosítók
     */
    public static function mozgatando(string $fiok, int $mappa_id): array
    {
        $kulcs = $fiok . '|' . $mappa_id;
        $sorok = self::$mozgatando[$kulcs] ?? [];

        unset(self::$mozgatando[$kulcs]);

        $ki = [];

        foreach ($sorok as $level_id => $cel) {
            $ki[$cel][] = (int) $level_id;
        }

        return $ki;
    }

    /**
     * Nyitott (vagy minden) feladat.
     *
     * @return array<int, object>
     */
    public static function feladatok(bool $csak_nyitott = true, int $limit = 50): array
    {
        global $wpdb;

        if (!SDH_Muhely_Schema::naprakesz()) {
            return [];
        }

        $sql = 'SELECT * FROM ' . self::tabla() . ($csak_nyitott ? " WHERE allapot = 'nyitott'" : '') . ' ORDER BY id DESC LIMIT %d';
        $sor = $wpdb->get_results($wpdb->prepare($sql, max(1, min(200, $limit)))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($sor) ? $sor : [];
    }

    public static function nyitott_db(): int
    {
        global $wpdb;

        if (!SDH_Muhely_Schema::naprakesz()) {
            return 0;
        }

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::tabla() . " WHERE allapot = 'nyitott'"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function feladat(int $id): ?object
    {
        global $wpdb;

        if ($id <= 0 || !SDH_Muhely_Schema::naprakesz()) {
            return null;
        }

        $sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_object($sor) ? $sor : null;
    }

    /** @return array<string, mixed> */
    public static function adat(object $feladat): array
    {
        $a = json_decode((string) ($feladat->adat ?? ''), true);

        return is_array($a) ? $a : [];
    }

    /** @return array<int, array<string, string>> */
    public static function tetelek(object $feladat): array
    {
        $t = self::adat($feladat)['tetelek'] ?? [];

        return is_array($t) ? array_values(array_filter($t, 'is_array')) : [];
    }

    /**
     * A feladat kiegészítése a TELJES levélből (0.61.1).
     *
     * A szinkron a levélnek csak az elejét tölti le, abban a csatolmány és a
     * tételek java még nincs benne. Az első megnyitáskor (vagy ha a levél
     * időközben mappát váltott) letöltjük az egészet, és eltesszük, amit
     * találtunk: a PDF mellékletek listáját és a tételeket. A levél tartalma
     * ADAT – a szabály ettől nem változik.
     */
    public static function betolt(object $f, bool $kenyszer = false): object
    {
        $a = self::adat($f);

        if (!$kenyszer && !empty($a['betoltve']) && (int) $f->level_id > 0) {
            return $f;
        }

        if (!class_exists('SDH_Muhely_Levelezes') || !method_exists('SDH_Muhely_Levelezes', 'feladat_level')) {
            return $f;
        }

        $teljes = SDH_Muhely_Levelezes::feladat_level((string) $f->message_id, (int) $f->level_id);

        if ($teljes === null) {
            return $f;
        }

        global $wpdb;

        $a['betoltve']     = true;
        $a['csatolmanyok'] = self::pdf_csatolmanyok($teljes['level']);
        $a['linkek']       = self::linkek($teljes['level'], (string) ($a['link'] ?? ''));

        if ((string) ($a['olvaso'] ?? '') !== '') {
            $a['tetelek'] = self::tetelek_olvas((string) $a['olvaso'], (string) $teljes['szoveg'], $teljes['level']);
        }

        $wpdb->update(self::tabla(), [
            'level_id' => (int) $teljes['level_id'],
            'adat'     => wp_json_encode($a),
        ], ['id' => (int) $f->id]);

        $f->level_id = (int) $teljes['level_id'];
        $f->adat     = (string) wp_json_encode($a);

        return $f;
    }

    public static function allapot_ir(int $id, string $allapot): bool
    {
        global $wpdb;

        $f = self::feladat($id);

        if ($f === null) {
            return false;
        }

        $kesz = $allapot === 'elintezve';

        $wpdb->update(self::tabla(), [
            'allapot'  => $kesz ? 'elintezve' : 'nyitott',
            'elintezve' => $kesz ? current_time('mysql') : null,
            'elintezte' => $kesz ? get_current_user_id() : 0,
        ], ['id' => $id]);

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('level', (int) $f->level_id, 'Levél-feladat ' . ($kesz ? 'elintézve' : 'újranyitva') . ': ' . (string) $f->cim, [], 'esemeny');
        }

        return true;
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    /** A nyitott feladatok (a levelező jelzéséhez). */
    public static function ajax_lista(): void
    {
        self::jog();

        $ki = [];

        foreach (self::feladatok(true, 20) as $f) {
            $a = self::adat($f);

            $ki[] = [
                'id'      => (int) $f->id,
                'cim'     => (string) $f->cim,
                'felado'  => (string) $f->felado,
                'targy'   => (string) ($a['targy'] ?? ''),
                'tetel'   => count((array) ($a['tetelek'] ?? [])),
                'nyomtat' => (string) ($a['nyomtat'] ?? '') !== '' && (array) ($a['csatolmanyok'] ?? []) !== [],
                'ido'     => (string) $f->letrehozva,
            ];
        }

        wp_send_json_success(['feladatok' => $ki, 'db' => self::nyitott_db()]);
    }

    public static function ajax_elintez(): void
    {
        self::jog();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        if (!self::allapot_ir($id, 'elintezve')) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen feladat.']);
        }

        wp_send_json_success(['db' => self::nyitott_db(), 'uzenet' => 'Elintézve.']);
    }

    public static function ajax_ujranyit(): void
    {
        self::jog();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        if (!self::allapot_ir($id, 'nyitott')) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen feladat.']);
        }

        wp_send_json_success(['db' => self::nyitott_db(), 'uzenet' => 'Újranyitva.']);
    }

    /** A feladat újraolvasása a levélből (ha a melléklet vagy a tétel hiányzik). */
    public static function ajax_ujra(): void
    {
        self::jog();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $f = self::feladat(isset($_POST['id']) ? (int) $_POST['id'] : 0);

        if ($f === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen feladat.']);
        }

        $f = self::betolt($f, true);
        $a = self::adat($f);

        $pdf  = count((array) ($a['csatolmanyok'] ?? []));
        $link = count((array) ($a['linkek'] ?? []));

        wp_send_json_success([
            'pdf'    => $pdf + $link,
            'tetel'  => count((array) ($a['tetelek'] ?? [])),
            'uzenet' => $pdf > 0 ? 'Megvan a melléklet.' : ($link > 0 ? 'A számla linkje megvan.' : 'Ebben a levélben nincs melléklet és számlalink sem.'),
        ]);
    }

    /**
     * Tételek kézzel: a beillesztett szövegből (0.61.4).
     *
     * Van beszállító, aki se nem csatolja, se nem írja ki a tételeket a levélbe
     * – csak egy linket küld a számlára. Ilyenkor a számlából (vagy a weboldalról)
     * kimásolt szöveget ide beillesztve ugyanaz a tételolvasó dolgozik, és
     * ugyanúgy nyomtathatók a címkék. A beillesztett szöveg ADAT: a szabály
     * ettől nem változik, és a rendszer semmi mást nem csinál vele.
     */
    public static function ajax_beilleszt(): void
    {
        self::jog();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $f = self::feladat(isset($_POST['id']) ? (int) $_POST['id'] : 0);

        if ($f === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen feladat.']);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $szoveg = isset($_POST['szoveg']) ? (string) wp_unslash($_POST['szoveg']) : '';
        $szoveg = mb_substr(trim(wp_strip_all_tags($szoveg)), 0, 200000);

        if ($szoveg === '') {
            wp_send_json_error(['uzenet' => 'Üres a beillesztett szöveg.']);
        }

        $a      = self::adat($f);
        $olvaso = (string) ($a['olvaso'] ?? '');

        if ($olvaso === '' || !isset(self::$olvasok[$olvaso])) {
            // Nincs a szabályhoz kötött olvasó: a sorokból általános tételeket szedünk.
            $tetelek = self::altalanos_tetelek($szoveg);
        } else {
            $tetelek = self::tetelek_olvas($olvaso, $szoveg, []);

            if ($tetelek === []) {
                // A beszállító formátuma más, mint a levélben: az általános olvasó próbálkozik.
                $tetelek = self::altalanos_tetelek($szoveg);
            }
        }

        if ($tetelek === []) {
            wp_send_json_error(['uzenet' => 'Ebben a szövegben nem találtam telefonszámos tételt.']);
        }

        global $wpdb;

        $a['tetelek'] = $tetelek;
        $a['kezi']    = true;

        $wpdb->update(self::tabla(), ['adat' => wp_json_encode($a)], ['id' => (int) $f->id]);

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('level', (int) $f->level_id, sprintf('Levél-feladat: %d tétel kézzel beillesztve', count($tetelek)), [], 'esemeny');
        }

        wp_send_json_success(['tetel' => count($tetelek), 'uzenet' => sprintf('%d tétel – mehet a címke.', count($tetelek))]);
    }

    /**
     * Általános tételolvasó a beillesztett szöveghez (0.61.4).
     *
     * Nem ismer egyetlen beszállítót sem: a telefonszámot keresi, és ami a
     * sorában (vagy az előtte álló sorokban) van, abból rakja össze a címkét.
     * Így akkor is van címke, ha a beszállító formátuma változik.
     *
     * @return array<int, array<string, string>>
     */
    public static function altalanos_tetelek(string $szoveg): array
    {
        $sorok = preg_split('/\R/u', (string) preg_replace('/[ \t]+/u', ' ', $szoveg)) ?: [];
        $sorok = array_values(array_filter(array_map('trim', $sorok), static fn (string $s): bool => $s !== ''));

        $ki   = [];
        $cimk = '~^(Azonosító|Garancia|Cikkszám|Megjegyzés|Nettó egységár|Bruttó|Mennyiség|Egységár)\s*:~ui';

        foreach ($sorok as $i => $sor) {
            $tel = self::telefonszam($sor);

            if ($tel === '' || count($ki) >= self::MAX_TETEL) {
                continue;
            }

            $t = ['telefon' => $tel, 'megjegyzes' => mb_substr($sor, 0, 190)];

            // Visszafelé olvasva: a címkés sorok a tétel adatai, az első címke
            // nélküli sor a megnevezés, az előtte álló a készülék.
            $nevek = [];

            for ($j = $i - 1; $j >= 0 && $i - $j <= 8; $j--) {
                $elozo = $sorok[$j];

                // Másik telefonszám: ott már az előző tétel áll, nem megyünk tovább.
                if (self::telefonszam($elozo) !== '') {
                    break;
                }

                if (preg_match($cimk, $elozo, $m) === 1) {
                    $kulcs = mb_strtolower((string) $m[1]);
                    $ertek = trim((string) preg_replace('~^[^:]*:~u', '', $elozo));

                    if (str_starts_with($kulcs, 'azonos') && !isset($t['azonosito'])) {
                        $t['azonosito'] = $ertek;
                    } elseif (str_starts_with($kulcs, 'garan') && !isset($t['garancia'])) {
                        $t['garancia'] = $ertek;
                    } elseif (str_starts_with($kulcs, 'cikk') && !isset($t['cikkszam'])) {
                        $t['cikkszam'] = $ertek;
                    }

                    continue;
                }

                // Árat és készletsort nem viszünk a címkére.
                if (preg_match('~(\d[\d\s]*(Ft|HUF)|db\s+Készleten)~ui', $elozo) === 1) {
                    continue;
                }

                $nevek[] = $elozo;

                if (count($nevek) >= 2) {
                    break;
                }
            }

            if (isset($nevek[0])) {
                $t['megnevezes'] = mb_substr($nevek[0], 0, 190);
            }

            if (isset($nevek[1])) {
                $t['keszulek'] = mb_substr($nevek[1], 0, 190);
            }

            $ki[] = array_map(
                static fn ($ertek): string => mb_substr(sanitize_text_field((string) $ertek), 0, 190),
                $t
            );
        }

        return $ki;
    }

    /** Magyar mobil- és vezetékes szám a szövegből (üres, ha nincs). */
    public static function telefonszam(string $szoveg): string
    {
        $jeloltek = [];

        if (preg_match_all('~(?:\+36|0036|06)[\s\-\/]?\d{1,2}[\s\-\/]?\d{3}[\s\-\/]?\d{3,4}~u', $szoveg, $t) !== false) {
            $jeloltek = (array) $t[0];
        }

        foreach ($jeloltek as $j) {
            $szam = (string) preg_replace('/\D+/', '', (string) $j);

            // 06 + körzet + 6-7 jegy, vagy +36/0036 ugyanaz.
            if (preg_match('~^(?:36|06|0036)?\d{8,9}$~', $szam) === 1 && mb_strlen($szam) >= 10) {
                return str_starts_with($szam, '36') ? '+' . $szam : $szam;
            }
        }

        return '';
    }

    /** Egy feladat tételei a címkenyomtatónak (a Matrica ablak ezt kéri). */
    public static function ajax_cimke(): void
    {
        self::jog();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
        $f  = self::feladat($id);

        if ($f === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen feladat.']);
        }

        wp_send_json_success(['tetelek' => self::tetelek(self::betolt($f))]);
    }

    /** A feladatok ablaka (popup; app.js nyitja „levelfeladat" modulként). */
    public static function ajax_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        self::ablak(isset($_GET['mind']) && $_GET['mind'] === '1');

        wp_die();
    }

    /** A feladatablak tartalma (az ajax_urlap és a tesztek is ezt hívják). */
    public static function ablak(bool $mind = false): void
    {
        $sorok = self::feladatok(!$mind, 50);

        ?>
        <h2 class="sdh-modal__cim">Levél-feladatok <span class="sdh-modal__cim-megj"><?php echo esc_html($mind ? 'minden feladat' : self::nyitott_db() . ' nyitott'); ?></span></h2>

        <div class="sdh-lf" data-sdh-lf-ablak>
            <p class="sdh-sugo">
                Ide a visszatérő levelek kerülnek, amikkel mindig ugyanaz a dolgunk. A feladat addig nyitott marad,
                amíg el nem intézed – a rendszer közben emlékeztet rá. Mit figyeljen: <strong>Beállítások › Levelezés › Levél-feladat ügynök</strong>.
            </p>

            <?php if ($sorok === []) : ?>
                <p class="sdh-lf__ures"><?php echo esc_html($mind ? 'Még nincs feladat.' : 'Nincs nyitott feladat – minden el van intézve.'); ?></p>
            <?php else : ?>
                <div class="sdh-lf__lista">
                    <?php foreach ($sorok as $f) : ?>
                        <?php
                        // Első megnyitáskor a teljes levélből egészül ki (melléklet, tételek).
                        $f       = self::betolt($f);
                        $a       = self::adat($f);
                        $tetelek = self::tetelek($f);
                        $pdf     = (array) ($a['csatolmanyok'] ?? []);
                        $kesz    = (string) $f->allapot === 'elintezve';
                        ?>
                        <article class="sdh-lf__kartya<?php echo $kesz ? ' is-kesz' : ''; ?>" data-sdh-lf="<?php echo (int) $f->id; ?>" data-level="<?php echo (int) $f->level_id; ?>">
                            <header class="sdh-lf__fej">
                                <strong><?php echo esc_html((string) $f->cim); ?></strong>
                                <span class="sdh-lf__felado"><?php echo esc_html((string) $f->felado); ?></span>
                                <span class="sdh-lf__ido"><?php echo esc_html(SDH_Muhely_Munkalap::datumido_megjelenit((string) $f->letrehozva)); ?></span>
                            </header>

                            <?php if ((string) ($a['targy'] ?? '') !== '') : ?>
                                <p class="sdh-lf__targy"><?php echo esc_html((string) $a['targy']); ?></p>
                            <?php endif; ?>

                            <div class="sdh-lf__gombok">
                                <?php if ((int) $f->level_id > 0) : ?>
                                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-lf-level="<?php echo (int) $f->level_id; ?>">Levél megnyitása</button>
                                <?php endif; ?>

                                <?php foreach ((array) ($a['linkek'] ?? []) as $l) : ?>
                                    <a class="sdh-gomb sdh-gomb--elsodleges" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url((string) $l['url']); ?>">
                                        Számla megnyitása<?php echo (string) $l['cim'] !== '' ? ' (' . esc_html((string) $l['cim']) . ')' : ''; ?>
                                    </a>
                                <?php endforeach; ?>

                                <?php if ($pdf === [] && (array) ($a['linkek'] ?? []) === [] && (string) ($a['nyomtat'] ?? '') !== '') : ?>
                                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-lf-ujra="<?php echo (int) $f->id; ?>"
                                            title="A levél melléklete vagy számlalinkje még nincs meg – a gomb újra megnézi a levelet.">Számla keresése</button>
                                <?php endif; ?>

                                <?php foreach ($pdf as $c) : ?>
                                    <a class="sdh-gomb sdh-gomb--elsodleges" target="_blank" rel="noopener"
                                       href="<?php echo esc_url(add_query_arg([
                                           'action'   => 'sdh_muhely_level_csatolmany',
                                           'id'       => (int) $f->level_id,
                                           'n'        => (int) $c['n'],
                                           '_wpnonce' => wp_create_nonce('sdh_muhely_modal'),
                                       ], admin_url('admin-ajax.php'))); ?>">
                                        Számla nyomtatása (<?php echo esc_html((string) $c['nev']); ?>)
                                    </a>
                                <?php endforeach; ?>

                                <?php if ($tetelek !== [] && class_exists('SDH_Muhely_Matrica') && SDH_Muhely_Modulok::be('matrica')) : ?>
                                    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-matrica-gomb" data-sdh-matrica="<?php echo (int) $f->id; ?>" data-sdh-matrica-tipus="tetel">
                                        Címkék (<?php echo (int) count($tetelek); ?> tétel)
                                    </button>
                                <?php endif; ?>

                                <?php // Ha a levélben nincs tétel (csak link a számlára): a számlából kimásolt szöveg is jó. ?>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-lf-beilleszt="<?php echo (int) $f->id; ?>"
                                        title="A számlából vagy a weboldalról kimásolt tételsorokból címke lesz.">
                                    <?php echo $tetelek === [] ? 'Tételek beillesztése' : 'Tételek cseréje'; ?>
                                </button>

                                <button type="button" class="sdh-gomb <?php echo $kesz ? 'sdh-gomb--vilagos' : 'sdh-gomb--elsodleges'; ?>"
                                        data-sdh-lf-<?php echo $kesz ? 'ujranyit' : 'elintez'; ?>="<?php echo (int) $f->id; ?>">
                                    <?php echo $kesz ? 'Újranyitás' : 'Elintézve'; ?>
                                </button>
                            </div>

                            <div class="sdh-lf__beilleszt" data-sdh-lf-mezo="<?php echo (int) $f->id; ?>" hidden>
                                <label for="sdh-lf-szoveg-<?php echo (int) $f->id; ?>">
                                    Másold ide a számla tételsorait (a PDF-ből vagy a webáruházból) – a telefonszámos tételekre címke készül.
                                </label>
                                <textarea id="sdh-lf-szoveg-<?php echo (int) $f->id; ?>" rows="6" spellcheck="false"
                                          placeholder="Készülék&#10;Megnevezés&#10;Azonosító: …&#10;Garancia: …&#10;Cikkszám: …&#10;Megjegyzés: … 06301234567"></textarea>
                                <div class="sdh-lf__beilleszt-gombok">
                                    <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-lf-feldolgoz="<?php echo (int) $f->id; ?>">Feldolgozás</button>
                                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-lf-megse="<?php echo (int) $f->id; ?>">Mégse</button>
                                    <span class="sdh-lf__allapot" role="status" aria-live="polite"></span>
                                </div>
                            </div>

                            <?php if ($tetelek !== []) : ?>
                                <ul class="sdh-lf__tetelek">
                                    <?php foreach (array_slice($tetelek, 0, 8) as $t) : ?>
                                        <li>
                                            <span><?php echo esc_html((string) ($t['megnevezes'] ?? '')); ?></span>
                                            <?php if ((string) ($t['telefon'] ?? '') !== '') : ?>
                                                <strong><?php echo esc_html((string) $t['telefon']); ?></strong>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (count($tetelek) > 8) : ?>
                                        <li class="sdh-lf__tobb">… és még <?php echo (int) (count($tetelek) - 8); ?> tétel</li>
                                    <?php endif; ?>
                                </ul>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p class="sdh-lf__lab">
                <a class="sdh-gomb sdh-gomb--vilagos" href="#" data-sdh-lf-mind="<?php echo $mind ? '0' : '1'; ?>"><?php echo $mind ? 'Csak a nyitottak' : 'Az elintézettek is'; ?></a>
            </p>
        </div>
        <?php
    }
}
