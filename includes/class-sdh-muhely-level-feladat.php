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
        foreach (['lista', 'elintez', 'ujranyit', 'cimke'] as $m) {
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

        return [
            'nev'   => $szo($sz['nev'] ?? '', 60),
            'aktiv' => !empty($sz['aktiv']),
            'fiok'  => $szo($sz['fiok'] ?? '', 10),
            'ha'    => [
                'felado'     => mb_strtolower($szo($ha['felado'] ?? '')),
                'targy'      => mb_strtolower($szo($ha['targy'] ?? '')),
                'csatolmany' => ($ha['csatolmany'] ?? '') === 'pdf' ? 'pdf' : '',
            ],
            'tesz'  => [
                'mappa'   => $szo($t['mappa'] ?? '', 120),
                'ertesit' => !empty($t['ertesit']),
                'nyomtat' => ($t['nyomtat'] ?? '') === 'csatolmany' ? 'csatolmany' : '',
                'olvaso'  => $olv !== '' && isset(self::$olvasok[$olv]) ? $olv : '',
            ],
        ];
    }

    /** @param array<string, array<string, mixed>> $szabalyok */
    public static function ment(array $szabalyok): void
    {
        $ki = [];

        foreach ($szabalyok as $kulcs => $sz) {
            $kulcs = sanitize_key((string) $kulcs);

            if ($kulcs === '' || !is_array($sz) || count($ki) >= self::MAX_SZABALY) {
                continue;
            }

            $ki[$kulcs] = self::szabaly_szukit($sz);
        }

        update_option(self::OPT, $ki, false);
    }

    /** Egy szabály beírása / felülírása (az SDH belső modulja ezzel teszi be a sajátját). */
    public static function szabaly_ir(string $kulcs, array $szabaly): void
    {
        $mind = self::szabalyok();
        $mind[sanitize_key($kulcs)] = self::szabaly_szukit($szabaly);

        update_option(self::OPT, $mind, false);
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

        foreach (self::szabalyok() as $kulcs => $sz) {
            if (!self::illik($sz, $sor)) {
                continue;
            }

            $adat = [
                'nyomtat'  => (string) $sz['tesz']['nyomtat'],
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

        wp_send_json_success(['tetelek' => self::tetelek($f)]);
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

                                <button type="button" class="sdh-gomb <?php echo $kesz ? 'sdh-gomb--vilagos' : 'sdh-gomb--elsodleges'; ?>"
                                        data-sdh-lf-<?php echo $kesz ? 'ujranyit' : 'elintez'; ?>="<?php echo (int) $f->id; ?>">
                                    <?php echo $kesz ? 'Újranyitás' : 'Elintézve'; ?>
                                </button>
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
