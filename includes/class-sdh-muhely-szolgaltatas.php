<?php
/**
 * Szolgáltatás-törzs: a rendszer által megjegyzett szolgáltatások.
 *
 * Amit a munkalap „Szolgáltatások" lapfülén egyszer beírtak, azt a rendszer
 * megjegyzi (név, mennyiségi egység, bruttó ár, áfakulcs). A törzsnek saját
 * modulja van az oldalsávban („Szolgáltatások"): lista, új felvitel és
 * szerkesztés popupban – ugyanúgy, mint az ügyfeleknél. A munkalapon a
 * szolgáltatást választó popupból lehet kiválasztani (app.js `szolgValaszto*`).
 *
 * EGY NÉV = EGY SOR. Az azonosságot a `kulcs` adja: a név kisbetűsítve, a
 * szóközök egységesítve („Kijelző  csere" = „kijelző csere"), md5-ben. A
 * `kulcs` egyedi index, ezért két egyforma nevű szolgáltatás soha nem jöhet
 * létre – a második használat a meglévő sort frissíti (utolsó ár, számláló).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Szolgaltatas
{
    /** A modul kulcsa az URL-ekben, a menüben és az AJAX-műveletekben. */
    public const KULCS = 'szolgaltatasok';

    /** Ennyi sort kap legfeljebb a böngésző (a leggyakrabban használtakat). */
    private const LISTA_HATAR = 3000;

    /** Hány sor egy oldalon a modul listájában. */
    private const OLDAL_MERET = 50;

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Szolgáltatások',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 35,
        ]);

        // Teljes oldalas űrlap beküldése (tartalék, JS nélkül is működik).
        add_action('admin_post_sdh_muhely_szolgaltatas_mentes', [self::class, 'mentes']);

        // Popup: az űrlap lekérése és beküldése.
        add_action('wp_ajax_sdh_muhely_szolgaltatasok_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_szolgaltatasok_ment', [self::class, 'ajax_mentes']);

        // A munkalap választó popupja ebből a listából dolgozik.
        add_action('wp_ajax_sdh_muhely_szolgaltatasok', [self::class, 'ajax_lista']);
        add_action('wp_ajax_sdh_muhely_szolgaltatas_felejt', [self::class, 'ajax_felejt']);

        // Sémafrissítés után: az egyforma nevűek összevonása, és a meglévő
        // munkalap-tételekből a törzs feltöltése (csak ha még üres).
        add_action('sdh_muhely_sema_frissult', [self::class, 'rendbetetel']);
    }

    public static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('szolgaltatas');
    }

    /* =================================================================
     * Név és kulcs
     * ============================================================== */

    /** A név tárolt alakja: szélek levágva, a belső szóközök egy szóközre. */
    public static function nev_tisztit(string $nev): string
    {
        $nev = preg_replace('/[\s\x{00a0}\x{202f}]+/u', ' ', $nev);

        return mb_substr(trim((string) $nev), 0, 255);
    }

    /**
     * Az azonosság kulcsa: két név akkor „egyforma", ha ez megegyezik.
     * Kis- és nagybetű, valamint a szóközök száma nem számít.
     */
    public static function kulcs(string $nev): string
    {
        $tiszta = self::nev_tisztit($nev);

        return $tiszta === '' ? '' : md5(mb_strtolower($tiszta, 'UTF-8'));
    }

    /* =================================================================
     * Olvasás
     * ============================================================== */

    /**
     * A megjegyzett szolgáltatások, a leggyakrabban használt elöl.
     *
     * @return array<int, object>
     */
    public static function lista(): array
    {
        global $wpdb;

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, nev, me, brutto_ar, afa_kulcs, hasznalat FROM ' . self::tabla() .
                ' ORDER BY hasznalat DESC, nev ASC LIMIT %d',
                self::LISTA_HATAR
            )
        );

        return is_array($sorok) ? $sorok : [];
    }

    public static function darab(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::tabla());
    }

    public static function nev_szerint(string $nev): ?object
    {
        global $wpdb;

        $kulcs = self::kulcs($nev);

        if ($kulcs === '') {
            return null;
        }

        $sor = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE kulcs = %s ORDER BY id ASC LIMIT 1', $kulcs)
        );

        return is_object($sor) ? $sor : null;
    }

    /* =================================================================
     * Írás
     * ============================================================== */

    /**
     * Megjegyez egy szolgáltatást. Ha ilyen nevű már van, NEM jön létre új
     * sor: a meglévő kapja meg az új árat, egységet, áfakulcsot, és nő a
     * használat-számlálója. A név írásmódja az elsőként rögzített marad.
     *
     * Nulla ár nem írja felül a már megjegyzett árat.
     *
     * @param array<string, mixed> $adat    nev, me, brutto_ar, afa_kulcs
     * @param int                  $hasznalat Ennyivel nő a számláló (0 = csak frissít).
     * @return int A törzssor azonosítója, vagy 0, ha a név üres.
     */
    public static function megjegyez(array $adat, int $hasznalat = 1): int
    {
        global $wpdb;

        $nev   = self::nev_tisztit((string) ($adat['nev'] ?? ''));
        $kulcs = self::kulcs($nev);

        if ($kulcs === '') {
            return 0;
        }

        $tabla = self::tabla();
        $most  = current_time('mysql');
        $ar    = max(0.0, round((float) ($adat['brutto_ar'] ?? 0), 2));
        $me    = mb_substr(trim((string) ($adat['me'] ?? '')), 0, 20);
        $afa   = SDH_Muhely_Tetel::afakulcs_ervenyes((string) ($adat['afa_kulcs'] ?? ''));
        $novel = max(0, $hasznalat);

        $meglevo = self::nev_szerint($nev);

        if ($meglevo === null) {
            $siker = $wpdb->insert($tabla, [
                'nev'        => $nev,
                'kulcs'      => $kulcs,
                'me'         => $me !== '' ? $me : 'db',
                'brutto_ar'  => $ar,
                'afa_kulcs'  => $afa,
                'hasznalat'  => $novel,
                'letrehozva' => $most,
                'modositva'  => $most,
            ]);

            if ($siker !== false) {
                return (int) $wpdb->insert_id;
            }

            // Közben más már felvette (egyedi kulcs): azt frissítjük.
            $meglevo = self::nev_szerint($nev);

            if ($meglevo === null) {
                return 0;
            }
        }

        $valtozas = [
            'afa_kulcs' => $afa,
            'hasznalat' => (int) $meglevo->hasznalat + $novel,
            'modositva' => $most,
        ];

        if ($ar > 0) {
            $valtozas['brutto_ar'] = $ar;
        }

        if ($me !== '') {
            $valtozas['me'] = $me;
        }

        $wpdb->update($tabla, $valtozas, ['id' => (int) $meglevo->id]);

        return (int) $meglevo->id;
    }

    /** Egy megjegyzett szolgáltatás elfelejtése. A munkalapok tételeihez nem nyúl. */
    public static function felejt(int $id): bool
    {
        global $wpdb;

        return $id > 0 && (bool) $wpdb->delete(self::tabla(), ['id' => $id]);
    }

    /* =================================================================
     * Rendbetétel: összevonás és feltöltés
     * ============================================================== */

    /**
     * Sémafrissítés után fut. Idempotens.
     */
    public static function rendbetetel(): void
    {
        self::osszevon();

        if (self::darab() === 0) {
            self::tetelekbol();
        }
    }

    /**
     * Az egyforma nevű sorok összevonása: a legrégebbi marad, a többiek
     * használata hozzáadódik, az ár a legutóbb módosítotté lesz. Az egyedi
     * kulcs miatt rendes működésben nincs mit összevonni – ez a biztonsági
     * háló (import, kézi adatbázis-módosítás, hiányzó kulcs).
     *
     * @return int Ennyi fölösleges sor törlődött.
     */
    public static function osszevon(): int
    {
        global $wpdb;

        $tabla = self::tabla();
        $sorok = $wpdb->get_results("SELECT * FROM {$tabla} ORDER BY id ASC");

        if (!is_array($sorok) || $sorok === []) {
            return 0;
        }

        $csoportok = [];

        foreach ($sorok as $sor) {
            $kulcs = self::kulcs((string) $sor->nev);

            if ($kulcs === '') {
                $wpdb->delete($tabla, ['id' => (int) $sor->id]);
                continue;
            }

            $csoportok[$kulcs][] = $sor;
        }

        $torolt = 0;

        foreach ($csoportok as $kulcs => $csoport) {
            $megmarad = $csoport[0];

            if (count($csoport) === 1) {
                if ((string) $megmarad->kulcs !== $kulcs) {
                    $wpdb->update($tabla, ['kulcs' => $kulcs], ['id' => (int) $megmarad->id]);
                }

                continue;
            }

            $hasznalat = 0;
            $utolso    = $megmarad;

            foreach ($csoport as $sor) {
                $hasznalat += (int) $sor->hasznalat;

                if ((float) $sor->brutto_ar > 0
                    && ((float) $utolso->brutto_ar <= 0 || (string) $sor->modositva >= (string) $utolso->modositva)) {
                    $utolso = $sor;
                }
            }

            // Előbb a fölöslegesek törlődnek, hogy az egyedi kulcs szabad legyen.
            foreach (array_slice($csoport, 1) as $sor) {
                if ($wpdb->delete($tabla, ['id' => (int) $sor->id])) {
                    $torolt++;
                }
            }

            $wpdb->update($tabla, [
                'kulcs'     => $kulcs,
                'hasznalat' => $hasznalat,
                'brutto_ar' => (float) $utolso->brutto_ar,
                'me'        => (string) $utolso->me !== '' ? (string) $utolso->me : 'db',
                'afa_kulcs' => (string) $utolso->afa_kulcs,
                'modositva' => current_time('mysql'),
            ], ['id' => (int) $megmarad->id]);
        }

        return $torolt;
    }

    /**
     * A törzs feltöltése a munkalapokon már szereplő szolgáltatásokból:
     * nevenként egy sor, a használat a tételek száma, az ár a legutóbbié.
     *
     * @return int Ennyi szolgáltatás került a törzsbe.
     */
    public static function tetelekbol(): int
    {
        global $wpdb;

        $tetelek = $wpdb->get_results(
            'SELECT megnevezes, me, brutto_ar, afa_kulcs FROM ' . SDH_Muhely_Tetel::tabla() .
            " WHERE tipus = 'szolgaltatas' AND megnevezes <> '' ORDER BY id ASC"
        );

        if (!is_array($tetelek)) {
            return 0;
        }

        $osszes = [];

        foreach ($tetelek as $t) {
            $kulcs = self::kulcs((string) $t->megnevezes);

            if ($kulcs === '') {
                continue;
            }

            if (!isset($osszes[$kulcs])) {
                $osszes[$kulcs] = [
                    'nev'       => (string) $t->megnevezes,
                    'me'        => (string) $t->me,
                    'brutto_ar' => 0.0,
                    'afa_kulcs' => (string) $t->afa_kulcs,
                    'db'        => 0,
                ];
            }

            $osszes[$kulcs]['db']++;
            $osszes[$kulcs]['afa_kulcs'] = (string) $t->afa_kulcs;

            if ((string) $t->me !== '') {
                $osszes[$kulcs]['me'] = (string) $t->me;
            }

            if ((float) $t->brutto_ar > 0) {
                $osszes[$kulcs]['brutto_ar'] = (float) $t->brutto_ar;
            }
        }

        $db = 0;

        foreach ($osszes as $sz) {
            if (self::megjegyez($sz, $sz['db']) > 0) {
                $db++;
            }
        }

        return $db;
    }

    /* =================================================================
     * Kézi felvitel és szerkesztés
     * ============================================================== */

    public static function egy(int $id): ?object
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id));

        return is_object($sor) ? $sor : null;
    }

    /**
     * Egy szolgáltatás mentése a törzsbe az űrlapról. Az „egy név = egy sor"
     * szabály itt is áll: ha a név egy MÁSIK meglévő sor neve, a kettő
     * összeolvad – egy marad, a használat összeadódik.
     *
     * @param array{nev: string, me: string, brutto_ar: float, afa_kulcs: string, megjegyzes: string} $adat
     * @return array{0: int, 1: string}|null [azonosító, üzenetkulcs], vagy null adatbázishibánál.
     */
    public static function torzsbe(int $id, array $adat): ?array
    {
        global $wpdb;

        $tabla = self::tabla();
        $most  = current_time('mysql');
        $nev   = self::nev_tisztit($adat['nev']);
        $kulcs = self::kulcs($nev);

        if ($kulcs === '') {
            return null;
        }

        $sor = [
            'nev'        => $nev,
            'kulcs'      => $kulcs,
            'me'         => $adat['me'] !== '' ? mb_substr($adat['me'], 0, 20) : 'db',
            'brutto_ar'  => max(0.0, round($adat['brutto_ar'], 2)),
            'afa_kulcs'  => SDH_Muhely_Tetel::afakulcs_ervenyes($adat['afa_kulcs']),
            'megjegyzes' => $adat['megjegyzes'],
            'modositva'  => $most,
        ];

        $masik  = self::nev_szerint($nev);
        $uzenet = $id > 0 ? 'szolg_mentve' : 'szolg_letrehozva';

        if ($masik !== null && (int) $masik->id !== $id) {
            $uzenet = 'szolg_osszevonva';

            if ($id > 0) {
                // Átnevezés egy már létező névre: ez a sor marad, a másik beleolvad.
                $ez = self::egy($id);

                $sor['hasznalat'] = (int) ($ez->hasznalat ?? 0) + (int) $masik->hasznalat;
                $wpdb->delete($tabla, ['id' => (int) $masik->id]);
            } else {
                // Új felvitel létező névvel: nem jön létre új sor, a meglévő frissül.
                $id = (int) $masik->id;
                // A meglévő írásmódja marad – az a név már munkalapokon is szerepelhet.
                unset($sor['nev']);
            }
        }

        if ($id > 0) {
            if ($wpdb->update($tabla, $sor, ['id' => $id]) === false) {
                return null;
            }

            return [$id, $uzenet];
        }

        $sor['hasznalat']  = 0;
        $sor['letrehozva'] = $most;

        if ($wpdb->insert($tabla, $sor) === false) {
            return null;
        }

        return [(int) $wpdb->insert_id, $uzenet];
    }

    /**
     * A beküldött űrlapmezők megtisztítva.
     *
     * @return array{nev: string, me: string, brutto_ar: float, afa_kulcs: string, megjegyzes: string}
     */
    private static function adatok_osszeallit(): array
    {
        $szoveg = static fn (string $mezo): string => isset($_POST[$mezo]) && is_scalar($_POST[$mezo])
            ? sanitize_text_field(wp_unslash((string) $_POST[$mezo]))
            : '';

        return [
            'nev'        => self::nev_tisztit($szoveg('nev')),
            'me'         => $szoveg('me'),
            'brutto_ar'  => SDH_Muhely_Tetel::szam($szoveg('brutto_ar')),
            'afa_kulcs'  => $szoveg('afa_kulcs'),
            'megjegyzes' => isset($_POST['megjegyzes']) && is_scalar($_POST['megjegyzes'])
                ? sanitize_textarea_field(wp_unslash((string) $_POST['megjegyzes']))
                : '',
        ];
    }

    private static function bekuldes_kontextusa(): string
    {
        $kontextus = isset($_REQUEST['kontextus']) ? sanitize_key(wp_unslash($_REQUEST['kontextus'])) : 'admin';

        return $kontextus === 'frontend' ? 'frontend' : 'admin';
    }

    private static function kontextus_ertek(): string
    {
        return wp_doing_ajax() ? self::bekuldes_kontextusa() : SDH_Muhely_Modulok::kontextus();
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    private static function vissza(array $parameterek): string
    {
        return SDH_Muhely_Modulok::visszateres(self::KULCS, $parameterek, self::bekuldes_kontextusa());
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    private static function url(array $parameterek = []): string
    {
        return SDH_Muhely_Modulok::url(self::KULCS, $parameterek);
    }

    /** Teljes oldalas beküldés (JS nélküli tartalék). */
    public static function mentes(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_szolgaltatas_mentes', 'sdh_nonce');

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adatok = self::adatok_osszeallit();

        if ($adatok['nev'] === '') {
            wp_safe_redirect(self::vissza(array_filter([
                'nezet'  => $id > 0 ? 'szerkeszt' : 'uj',
                'id'     => $id > 0 ? $id : null,
                'uzenet' => 'szolg_hianyzo_nev',
            ])));
            exit;
        }

        $eredmeny = $id > 0 && self::egy($id) === null ? null : self::torzsbe($id, $adatok);

        wp_safe_redirect(self::vissza(['uzenet' => $eredmeny === null ? 'mentes_hiba' : $eredmeny[1]]));
        exit;
    }

    /** Popupos beküldés. */
    public static function ajax_mentes(): void
    {
        check_ajax_referer('sdh_muhely_szolgaltatas_mentes', 'sdh_nonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adatok = self::adatok_osszeallit();

        if ($adatok['nev'] === '') {
            wp_send_json_error(['uzenet' => 'A megnevezés kitöltése kötelező.']);
        }

        if ($id > 0 && self::egy($id) === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen szolgáltatás. Lehet, hogy időközben törölték.']);
        }

        $eredmeny = self::torzsbe($id, $adatok);

        if ($eredmeny === null) {
            wp_send_json_error(['uzenet' => 'Az adatbázis visszautasította a mentést.']);
        }

        $mentett = self::egy($eredmeny[0]);

        // A mezőket a munkalap választó popupja használja: a frissen felvitt
        // szolgáltatást oldalfrissítés nélkül teszi a tételsorba.
        wp_send_json_success([
            'id'         => (int) $mentett->id,
            'nev'        => (string) $mentett->nev,
            'me'         => (string) $mentett->me,
            'brutto_ar'  => (float) $mentett->brutto_ar,
            'afa_kulcs'  => (string) $mentett->afa_kulcs,
            'osszevonva' => $eredmeny[1] === 'szolg_osszevonva',
            'vissza'     => self::vissza(['uzenet' => $eredmeny[1]]),
        ]);
    }

    /* =================================================================
     * A modul oldala: lista
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

    private static function penz(float $osszeg): string
    {
        return number_format($osszeg, 0, ',', "\u{00a0}") . "\u{00a0}Ft";
    }

    private static function lista_oldal(): void
    {
        global $wpdb;

        $kereses = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
        $gyakori = !empty($_GET['gyakori']);
        $oldal   = isset($_GET['oldalszam']) ? max(1, (int) $_GET['oldalszam']) : 1;
        $eltolas = ($oldal - 1) * self::OLDAL_MERET;
        $tabla   = self::tabla();

        $hol     = '';
        $ertekek = [];

        if ($kereses !== '') {
            $hol     = 'WHERE (nev LIKE %s OR megjegyzes LIKE %s)';
            $minta   = '%' . $wpdb->esc_like($kereses) . '%';
            $ertekek = [$minta, $minta];
        }

        $rendez = $gyakori ? 'hasznalat DESC, nev ASC' : 'nev ASC';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- a $hol csak helyőrzőket tartalmaz.
        $osszesen = (int) ($ertekek === []
            ? $wpdb->get_var("SELECT COUNT(*) FROM {$tabla}")
            : $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tabla} {$hol}", $ertekek)));

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tabla} {$hol} ORDER BY {$rendez} LIMIT %d OFFSET %d",
                array_merge($ertekek, [self::OLDAL_MERET, $eltolas])
            )
        );
        // phpcs:enable

        $sorok   = is_array($sorok) ? $sorok : [];
        $oldalak = max(1, (int) ceil($osszesen / self::OLDAL_MERET));
        $alap    = array_filter(['k' => $kereses !== '' ? $kereses : null, 'gyakori' => $gyakori ? 1 : null]);

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Szolgáltatások',
                'A munkalapokon használt szolgáltatások törzse. Egy névből mindig egy van; a munkalap mentése is ide jegyzi az újakat.',
                [
                    [
                        'cimke'      => '+ Új szolgáltatás',
                        'url'        => self::url(['nezet' => 'uj']),
                        'elsodleges' => true,
                        'adatok'     => ['sdh-urlap' => self::KULCS, 'sdh-id' => '0'],
                    ],
                ]
            );
            ?>

            <form method="get" class="sdh-kereso"
                  action="<?php echo esc_url(SDH_Muhely_Modulok::urlap_cel(self::KULCS)); ?>">
                <?php SDH_Muhely_Modulok::urlap_rejtett(self::KULCS); ?>

                <?php if ($gyakori) : ?>
                    <input type="hidden" name="gyakori" value="1">
                <?php endif; ?>

                <input type="search" name="k" value="<?php echo esc_attr($kereses); ?>"
                       placeholder="Megnevezés vagy megjegyzés…">

                <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Keresés</button>

                <?php if ($kereses !== '') : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos"
                       href="<?php echo esc_url(self::url($gyakori ? ['gyakori' => 1] : [])); ?>">Szűrő törlése</a>
                <?php endif; ?>

                <a class="sdh-gomb sdh-gomb--vilagos"
                   href="<?php echo esc_url(self::url(array_filter([
                       'k'       => $kereses !== '' ? $kereses : null,
                       'gyakori' => $gyakori ? null : 1,
                   ]))); ?>">
                    <?php echo $gyakori ? 'Névsorban' : 'Leggyakoribbak elöl'; ?>
                </a>

                <span class="sdh-kereso__talalat">
                    <?php echo esc_html(number_format_i18n($osszesen)); ?> szolgáltatás
                </span>
            </form>

            <table class="sdh-tabla sdh-tabla--szolg">
                <thead>
                    <tr>
                        <th>Megnevezés</th>
                        <th>M.e.</th>
                        <th class="sdh-tabla__szam">Bruttó ár</th>
                        <th class="sdh-tabla__szam sdh-tabla__rejtheto">Nettó ár</th>
                        <th>Áfa</th>
                        <th class="sdh-tabla__szam" title="Hány munkalap-tételben szerepelt">Használat</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($sorok === []) : ?>
                    <tr>
                        <td colspan="7" class="sdh-tabla__ures">
                            <?php if ($kereses !== '') : ?>
                                Erre a keresésre nincs találat.
                            <?php else : ?>
                                Még nincs szolgáltatás.
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
                        $afa_kulcs     = SDH_Muhely_Tetel::afakulcs_ervenyes((string) $sor->afa_kulcs);
                        $brutto        = (float) $sor->brutto_ar;
                        $netto         = SDH_Muhely_Tetel::bruttobol_netto($brutto, SDH_Muhely_Tetel::afa_szazalek($afa_kulcs));
                        ?>
                        <tr>
                            <td class="sdh-tabla__nev">
                                <a href="<?php echo esc_url($szerkeszt_url); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="<?php echo (int) $sor->id; ?>">
                                    <?php echo esc_html($sor->nev); ?>
                                </a>
                                <?php if ((string) ($sor->megjegyzes ?? '') !== '') : ?>
                                    <div class="sdh-tabla__halvany">
                                        <?php echo esc_html(wp_trim_words((string) $sor->megjegyzes, 14, '…')); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($sor->me); ?></td>
                            <td class="sdh-tabla__szam"><?php echo $brutto > 0 ? esc_html(self::penz($brutto)) : '—'; ?></td>
                            <td class="sdh-tabla__szam sdh-tabla__rejtheto sdh-tabla__halvany">
                                <?php echo $brutto > 0 ? esc_html(self::penz($netto)) : '—'; ?>
                            </td>
                            <td><?php echo esc_html(is_numeric($afa_kulcs) ? $afa_kulcs . '%' : $afa_kulcs); ?></td>
                            <td class="sdh-tabla__szam sdh-tabla__halvany"><?php echo (int) $sor->hasznalat; ?></td>
                            <td>
                                <a class="sdh-gomb sdh-gomb--vilagos"
                                   href="<?php echo esc_url($szerkeszt_url); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="<?php echo (int) $sor->id; ?>">Megnyit</a>
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
                            <a href="<?php echo esc_url(self::url(array_merge($alap, ['oldalszam' => $i]))); ?>"><?php echo (int) $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =================================================================
     * Űrlap
     * ============================================================== */

    private static function alcim(?object $sz): string
    {
        return $sz === null
            ? 'Csak a megnevezés kötelező. Az ár bruttó egységár.'
            : 'Eddig ' . (int) $sz->hasznalat . ' munkalap-tételben szerepelt.';
    }

    /** Teljes oldalas változat (tartalék). */
    private static function urlap_oldal(string $nezet): void
    {
        $sz = null;

        if ($nezet === 'szerkeszt') {
            $sz = self::egy(isset($_GET['id']) ? (int) $_GET['id'] : 0);

            if ($sz === null) {
                wp_safe_redirect(self::url(['uzenet' => 'nincs_ilyen']));
                exit;
            }
        }

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                $sz === null ? 'Új szolgáltatás' : (string) $sz->nev,
                self::alcim($sz),
                [['cimke' => '← Vissza a listához', 'url' => self::url()]]
            );
            ?>

            <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_szolgaltatas_mentes">
                <?php self::urlap_belso($sz, false); ?>
            </form>
        </div>
        <?php
    }

    /** Popup változat. */
    public static function ajax_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $sz = $id > 0 ? self::egy($id) : null;

        if ($id > 0 && $sz === null) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs ilyen szolgáltatás.</div>';
            wp_die();
        }

        // Új felvitelnél a hívó előre megadhatja a nevet (a választó popup keresője).
        $nev = $sz === null && isset($_GET['nev']) ? self::nev_tisztit(sanitize_text_field(wp_unslash($_GET['nev']))) : '';

        ?>
        <h2 class="sdh-modal__cim"><?php echo esc_html($sz === null ? 'Új szolgáltatás' : (string) $sz->nev); ?></h2>
        <p class="sdh-modal__alcim"><?php echo esc_html(self::alcim($sz)); ?></p>

        <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-sdh-ajax-action="sdh_muhely_szolgaltatasok_ment">
            <input type="hidden" name="action" value="sdh_muhely_szolgaltatas_mentes">
            <?php self::urlap_belso($sz, true, $nev); ?>
        </form>
        <?php

        wp_die();
    }

    /**
     * Az űrlap belseje – a teljes oldalas és a popupos változat közös része.
     */
    private static function urlap_belso(?object $sz, bool $modal, string $nev = ''): void
    {
        $uj        = $sz === null;
        $afakulcs  = $uj ? SDH_Muhely_Tetel::alap_afakulcs() : SDH_Muhely_Tetel::afakulcs_ervenyes((string) $sz->afa_kulcs);
        $brutto    = $uj ? 0.0 : (float) $sz->brutto_ar;
        $ar_mezobe = $brutto > 0 ? rtrim(rtrim(number_format($brutto, 2, '.', ''), '0'), '.') : '';

        ?>
        <input type="hidden" name="id" value="<?php echo (int) ($sz->id ?? 0); ?>">
        <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
        <?php wp_nonce_field('sdh_muhely_szolgaltatas_mentes', 'sdh_nonce'); ?>

        <div class="sdh-ugyfelurlap sdh-szolgurlap" data-sdh-szolgurlap>
            <div class="sdh-sor">
                <input type="text" name="nev" id="szolg_nev" required maxlength="255" autocomplete="off"
                       aria-label="Megnevezés" placeholder="Megnevezés * (pl. Kijelzőcsere munkadíj)"
                       value="<?php echo esc_attr($uj ? $nev : (string) $sz->nev); ?>">
            </div>

            <div class="sdh-sor sdh-sor--ketto">
                <div class="sdh-ig">
                    <label for="szolg_brutto_ar">Bruttó ár</label>
                    <span class="sdh-szam sdh-szam--utotag">
                        <input type="number" name="brutto_ar" id="szolg_brutto_ar" min="0" step="any"
                               data-sdh-lepes="500" data-sdh-szolg-brutto placeholder="0"
                               value="<?php echo esc_attr($ar_mezobe); ?>">
                        <span class="sdh-szam__utotag" aria-hidden="true">Ft</span>
                    </span>
                </div>

                <div class="sdh-ig">
                    <label for="szolg_afa_kulcs">Áfakulcs</label>
                    <select name="afa_kulcs" id="szolg_afa_kulcs" data-sdh-szolg-afa>
                        <?php foreach (SDH_Muhely_Tetel::afakulcsok() as $kulcs => $adat) : ?>
                            <option value="<?php echo esc_attr((string) $kulcs); ?>"
                                    data-szazalek="<?php echo esc_attr((string) $adat['szazalek']); ?>"
                                    <?php selected($afakulcs, (string) $kulcs); ?>>
                                <?php echo esc_html($adat['nev']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sdh-ig">
                    <label for="szolg_me">Menny. egység</label>
                    <input type="text" name="me" id="szolg_me" maxlength="20" placeholder="db"
                           value="<?php echo esc_attr($uj ? 'db' : (string) $sz->me); ?>">
                </div>

                <div class="sdh-ig">
                    <span class="sdh-ig__cimke">Nettó ár</span>
                    <output class="sdh-szolgurlap__netto" data-sdh-szolg-netto>—</output>
                </div>
            </div>

            <div class="sdh-sor">
                <textarea name="megjegyzes" id="szolg_megjegyzes" rows="3" aria-label="Megjegyzés"
                          placeholder="Belső megjegyzés (mit tartalmaz, mikor adható…)"><?php
                    echo esc_textarea($uj ? '' : (string) ($sz->megjegyzes ?? ''));
                ?></textarea>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">
                    <?php echo $uj ? 'Szolgáltatás létrehozása' : 'Mentés'; ?>
                </button>

                <?php if ($modal) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                <?php else : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(self::url()); ?>">Mégsem</a>
                <?php endif; ?>

                <?php if (!$uj && $modal) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--jobbra"
                            data-sdh-szolg-torol="<?php echo (int) $sz->id; ?>"
                            data-vissza="<?php echo esc_url(self::vissza(['uzenet' => 'szolg_torolve'])); ?>"
                            title="A törzsből törli. A munkalapokon már szereplő tételek megmaradnak.">
                        Törlés
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    private static function jog_ellenorzes(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    /**
     * A megjegyzett szolgáltatások a böngészőnek. Egy sor:
     * [id, név, m.e., bruttó ár, áfakulcs, használat].
     */
    public static function ajax_lista(): void
    {
        self::jog_ellenorzes();

        $sorok = [];

        foreach (self::lista() as $sz) {
            $sorok[] = [
                (int) $sz->id,
                (string) $sz->nev,
                (string) $sz->me,
                (float) $sz->brutto_ar,
                (string) $sz->afa_kulcs,
                (int) $sz->hasznalat,
            ];
        }

        nocache_headers();
        wp_send_json_success(['sorok' => $sorok]);
    }

    public static function ajax_felejt(): void
    {
        self::jog_ellenorzes();

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        if (!self::felejt($id)) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen megjegyzett szolgáltatás.'], 404);
        }

        wp_send_json_success(['id' => $id]);
    }
}
