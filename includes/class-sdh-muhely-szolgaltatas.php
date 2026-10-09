<?php
/**
 * Szolgáltatás-törzs: a rendszer által megjegyzett szolgáltatások.
 *
 * Amit a munkalap „Szolgáltatások" lapfülén egyszer beírtak, azt a rendszer
 * megjegyzi (név, mennyiségi egység, bruttó ár, áfakulcs), és legközelebb a
 * Megnevezés mezőbe gépelve felkínálja. Külön felvinni nem kell: a munkalap
 * mentése veszi fel.
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
    /** Ennyi sort kap legfeljebb a böngésző (a leggyakrabban használtakat). */
    private const LISTA_HATAR = 3000;

    public static function init(): void
    {
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
