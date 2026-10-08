<?php
/**
 * Eszköz-modul.
 *
 * Egy eszköz egy konkrét készülék, ami egy ügyfélhez tartozik – a
 * MunkaLap „Tárgy" fogalmának megfelelője. A munkalap később erre fog
 * hivatkozni, így a készülék előélete (hányszor járt nálunk, mivel)
 * egyben látszik.
 *
 * Ugyanazt a mintát követi, mint az ügyfél-modul: lista kereséssel,
 * popupos felvitel és szerkesztés, teljes oldalas tartalék JS nélkülre.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Eszkoz
{
    public const KULCS = 'eszkozok';

    private const OLDAL_MERET = 25;

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Eszközök',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 20,
        ]);

        add_action('admin_post_sdh_muhely_eszkoz_mentes', [self::class, 'mentes']);
        add_action('wp_ajax_sdh_muhely_eszkozok_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_eszkozok_ment', [self::class, 'ajax_mentes']);
        add_action('wp_ajax_sdh_muhely_eszkozok_imei', [self::class, 'ajax_imei']);
    }

    /* =================================================================
     * IMEI-kikeresés
     * ============================================================== */

    /**
     * Megkeresi a készüléket IMEI alapján, és visszaadja az adatait.
     *
     * A saját adatbázisunkban keres: ha a telefon már járt nálunk,
     * minden kitöltődik, és a mentés a meglévő rekordot frissíti
     * ahelyett, hogy másodszor is felvinné ugyanazt a készüléket.
     *
     * Gyártót és típust az IMEI-ből kiolvasni nem tudunk: ahhoz a
     * GSMA TAC-adatbázisa kellene, ami fizetős külső szolgáltatás.
     * Ha van ilyen előfizetésed, be lehet kötni ide.
     */
    public static function ajax_imei(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        global $wpdb;

        $imei = isset($_GET['imei'])
            ? preg_replace('/\D/', '', (string) wp_unslash($_GET['imei']))
            : '';

        if (strlen((string) $imei) !== 15) {
            wp_send_json_success(['talalat' => false]);
        }

        // Kizárjuk azt a rekordot, amit épp szerkesztünk – különben a
        // saját IMEI-je „találat" lenne a szerkesztés közben.
        $kizar = isset($_GET['kizar']) ? (int) $_GET['kizar'] : 0;

        $eszkoz_tabla = self::tabla();
        $ugyfel_tabla = SDH_Muhely_Schema::tabla('ugyfel');

        $sor = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT e.*, u.nev AS ugyfel_nev
                 FROM {$eszkoz_tabla} e
                 LEFT JOIN {$ugyfel_tabla} u ON u.id = e.ugyfel_id
                 WHERE (e.imei = %s OR e.imei2 = %s) AND e.id <> %d
                 ORDER BY e.modositva DESC
                 LIMIT 1",
                $imei,
                $imei,
                $kizar
            )
        );

        if ($sor === null) {
            // Nem járt még nálunk: a TAC-adatbázisból próbáljuk
            // megmondani, milyen készülék ez.
            $tac = class_exists('SDH_Muhely_Tac') ? SDH_Muhely_Tac::keres($imei) : null;

            wp_send_json_success([
                'talalat'  => false,
                'ervenyes' => self::imei_ervenyes($imei),
                'tac'      => $tac,
            ]);
        }

        wp_send_json_success([
            'talalat'  => true,
            'ervenyes' => self::imei_ervenyes($imei),
            'id'       => (int) $sor->id,
            'megnevez' => self::megnevezes($sor),
            'utoljara' => $sor->modositva ? mysql2date('Y. m. d.', $sor->modositva) : '',
            'ugyfel'   => [
                'id'  => (int) $sor->ugyfel_id,
                'nev' => (string) ($sor->ugyfel_nev ?? ''),
            ],
            'mezok'    => [
                'kategoria'        => self::kategoria_cimke((string) $sor->kategoria),
                'gyarto'           => (string) $sor->gyarto,
                'tipus'            => (string) $sor->tipus,
                'megnevezes'       => (string) $sor->megnevezes,
                'szin'             => (string) $sor->szin,
                'imei'             => (string) $sor->imei,
                'imei2'            => (string) $sor->imei2,
                'sorozatszam'      => (string) $sor->sorozatszam,
                'modell_szam'      => (string) $sor->modell_szam,
                'garancia_allapot' => (string) $sor->garancia_allapot,
                'gyartas_datuma'   => self::datum((string) ($sor->gyartas_datuma ?? '')),
                'orszag'           => (string) $sor->orszag,
                'szolgaltato'      => (string) $sor->szolgaltato,
                'kep_url'          => (string) $sor->kep_url,
                'zarkod'           => (string) $sor->zarkod,
                'tartozekok'       => (string) ($sor->tartozekok ?? ''),
                'atveteli_allapot' => (string) ($sor->atveteli_allapot ?? ''),
                'megjegyzes'       => (string) ($sor->megjegyzes ?? ''),
                'belso_megjegyzes' => (string) ($sor->belso_megjegyzes ?? ''),
                'garancias'        => (int) $sor->garancias === 1,
                'vasarlas_datuma'  => self::datum((string) ($sor->vasarlas_datuma ?? '')),
                'garancia_lejar'   => self::datum((string) ($sor->garancia_lejar ?? '')),
            ],
        ]);
    }

    /**
     * IMEI ellenőrzőszám (Luhn).
     *
     * Nem tiltunk vele semmit – elgépelést jelez, és a pultnál ez
     * többet ér, mint egy elutasított mentés.
     */
    public static function imei_ervenyes(string $imei): bool
    {
        if (!preg_match('/^\d{15}$/', $imei)) {
            return false;
        }

        $osszeg = 0;

        for ($i = 0; $i < 15; $i++) {
            $szamjegy = (int) $imei[$i];

            // Hátulról a második számjegytől minden másodikat duplázunk.
            if ((14 - $i) % 2 === 1) {
                $szamjegy *= 2;

                if ($szamjegy > 9) {
                    $szamjegy -= 9;
                }
            }

            $osszeg += $szamjegy;
        }

        return $osszeg % 10 === 0;
    }

    private static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('eszkoz');
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
        $ugyfel_id = isset($_GET['ugyfel_id']) ? (int) $_GET['ugyfel_id'] : 0;
        $oldal     = isset($_GET['oldalszam']) ? max(1, (int) $_GET['oldalszam']) : 1;
        $eltolas   = ($oldal - 1) * self::OLDAL_MERET;

        $eszkoz_tabla = self::tabla();
        $ugyfel_tabla = SDH_Muhely_Schema::tabla('ugyfel');

        $feltetelek = ['e.aktiv = %d'];
        $ertekek    = [1];

        if ($ugyfel_id > 0) {
            $feltetelek[] = 'e.ugyfel_id = %d';
            $ertekek[]    = $ugyfel_id;
        }

        if ($kereses !== '') {
            $minta = '%' . $wpdb->esc_like($kereses) . '%';

            $feltetelek[] = '(e.gyarto LIKE %s OR e.tipus LIKE %s OR e.megnevezes LIKE %s '
                . 'OR e.imei LIKE %s OR e.imei2 LIKE %s OR e.sorozatszam LIKE %s OR u.nev LIKE %s)';

            array_push($ertekek, $minta, $minta, $minta, $minta, $minta, $minta, $minta);
        }

        $hol = 'WHERE ' . implode(' AND ', $feltetelek);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- a $hol csak helyőrzőket tartalmaz.
        $osszesen = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$eszkoz_tabla} e
                 LEFT JOIN {$ugyfel_tabla} u ON u.id = e.ugyfel_id {$hol}",
                $ertekek
            )
        );

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.*, u.nev AS ugyfel_nev
                 FROM {$eszkoz_tabla} e
                 LEFT JOIN {$ugyfel_tabla} u ON u.id = e.ugyfel_id
                 {$hol}
                 ORDER BY e.modositva DESC, e.id DESC
                 LIMIT %d OFFSET %d",
                array_merge($ertekek, [self::OLDAL_MERET, $eltolas])
            )
        );
        // phpcs:enable

        $oldalak = max(1, (int) ceil($osszesen / self::OLDAL_MERET));
        $szurt   = $ugyfel_id > 0 ? SDH_Muhely_Ugyfel::nev($ugyfel_id) : '';

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Eszközök',
                $szurt !== ''
                    ? $szurt . ' készülékei.'
                    : 'Ügyfélhez kötött készülékek. A munkalap mindig egy eszközre hivatkozik.',
                [
                    [
                        'cimke'      => '+ Új eszköz',
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

                <?php if ($ugyfel_id > 0) : ?>
                    <input type="hidden" name="ugyfel_id" value="<?php echo (int) $ugyfel_id; ?>">
                <?php endif; ?>

                <input type="search" name="k" value="<?php echo esc_attr($kereses); ?>"
                       placeholder="Gyári szám, kereskedelmi név, IMEI, sorozatszám, ügyfél…">

                <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Keresés</button>

                <?php if ($kereses !== '' || $ugyfel_id > 0) : ?>
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
                        <th class="sdh-oszlop--kep"></th>
                        <th>Készülék (gyári szám)</th>
                        <th>Ügyfél</th>
                        <th>IMEI / sorozatszám</th>
                        <th class="sdh-tabla__rejtheto">Kategória</th>
                        <th class="sdh-tabla__rejtheto">Garancia</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($sorok === []) : ?>
                    <tr>
                        <td colspan="7" class="sdh-tabla__ures">
                            <?php if ($kereses !== '' || $ugyfel_id > 0) : ?>
                                Erre a szűrésre nincs találat.
                            <?php else : ?>
                                Még nincs eszköz.
                                <a href="<?php echo esc_url(self::url(['nezet' => 'uj'])); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="0">Vidd fel az elsőt.</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($sorok as $sor) : ?>
                        <?php $szerkeszt_url = self::url(['nezet' => 'szerkeszt', 'id' => (int) $sor->id]); ?>
                        <tr>
                            <td>
                                <?php if ($sor->kep_url !== '') : ?>
                                    <img class="sdh-tabla__kep"
                                         src="<?php echo esc_url($sor->kep_url); ?>" alt="" loading="lazy">
                                <?php endif; ?>
                            </td>
                            <td class="sdh-tabla__nev">
                                <a href="<?php echo esc_url($szerkeszt_url); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="<?php echo (int) $sor->id; ?>">
                                    <?php echo esc_html(self::megnevezes($sor)); ?>
                                </a>
                                <?php
                                $alatta = array_filter([$sor->megnevezes, $sor->szin]);
                                ?>
                                <?php if ($alatta !== []) : ?>
                                    <div class="sdh-tabla__halvany">
                                        <?php echo esc_html(implode(' · ', $alatta)); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sor->ugyfel_nev) : ?>
                                    <a href="<?php echo esc_url(
                                        SDH_Muhely_Modulok::url(
                                            SDH_Muhely_Ugyfel::KULCS,
                                            ['nezet' => 'szerkeszt', 'id' => (int) $sor->ugyfel_id]
                                        )
                                    ); ?>"
                                       data-sdh-urlap="<?php echo esc_attr(SDH_Muhely_Ugyfel::KULCS); ?>"
                                       data-sdh-id="<?php echo (int) $sor->ugyfel_id; ?>">
                                        <?php echo esc_html($sor->ugyfel_nev); ?>
                                    </a>
                                <?php else : ?>
                                    <span class="sdh-tabla__halvany">nincs ügyfél</span>
                                <?php endif; ?>
                            </td>
                            <td class="sdh-tabla__halvany">
                                <?php echo esc_html($sor->imei !== '' ? $sor->imei : $sor->sorozatszam); ?>
                            </td>
                            <td class="sdh-tabla__rejtheto">
                                <span class="sdh-cimke"><?php
                                    echo esc_html(self::kategoria_cimke($sor->kategoria));
                                ?></span>
                            </td>
                            <td class="sdh-tabla__rejtheto">
                                <?php if ((int) $sor->garancias === 1) : ?>
                                    <span class="sdh-cimke">garanciális</span>
                                <?php else : ?>
                                    <span class="sdh-tabla__halvany">—</span>
                                <?php endif; ?>
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
                                        'ugyfel_id' => $ugyfel_id > 0 ? $ugyfel_id : null,
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

    /* =================================================================
     * Űrlap – teljes oldalas változat (tartalék)
     * ============================================================== */

    private static function urlap_oldal(string $nezet): void
    {
        $eszkoz = null;

        if ($nezet === 'szerkeszt') {
            $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            $eszkoz = self::egy($id);

            if ($eszkoz === null) {
                wp_safe_redirect(self::url(['uzenet' => 'nincs_ilyen']));
                exit;
            }
        }

        $uj = $eszkoz === null;

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                $uj ? 'Új eszköz' : self::megnevezes($eszkoz),
                $uj ? 'Előbb válaszd ki az ügyfelet (vagy vidd fel a + gombbal), akihez a készülék tartozik.' : '',
                [
                    [
                        'cimke' => '← Vissza a listához',
                        'url'   => self::url(),
                    ],
                ]
            );
            ?>

            <form class="sdh-urlap" method="post" enctype="multipart/form-data"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_eszkoz_mentes">
                <?php self::urlap_belso($eszkoz, false); ?>
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

        $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $eszkoz = $id > 0 ? self::egy($id) : null;

        if ($id > 0 && $eszkoz === null) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs ilyen eszköz.</div>';
            wp_die();
        }

        $uj = $eszkoz === null;

        ?>
        <h2 class="sdh-modal__cim"><?php echo esc_html($uj ? 'Új eszköz' : self::megnevezes($eszkoz)); ?></h2>
        <p class="sdh-modal__alcim">
            <?php echo esc_html(
                $uj
                    ? 'Előbb válaszd ki az ügyfelet, akihez a készülék tartozik.'
                    : 'A készülék adatai. A munkalapok ehhez az eszközhöz fognak kapcsolódni.'
            ); ?>
        </p>

        <form class="sdh-urlap" method="post" enctype="multipart/form-data"
              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-sdh-ajax-action="sdh_muhely_eszkozok_ment">
            <input type="hidden" name="action" value="sdh_muhely_eszkoz_mentes">
            <?php self::urlap_belso($eszkoz, true); ?>
        </form>
        <?php

        wp_die();
    }

    /**
     * Az űrlap belseje – a teljes oldalas és a popupos változat is ezt
     * használja, hogy a kettő ne csússzon szét.
     */
    private static function urlap_belso(?object $eszkoz, bool $modal): void
    {
        $ert = static fn (string $mezo, string $alap = ''): string => $eszkoz !== null
            ? (string) ($eszkoz->{$mezo} ?? $alap)
            : $alap;

        $uj = $eszkoz === null;

        // Ha az eszközlistát ügyfélre szűrve nyitottad meg, az új eszköz
        // rögtön ahhoz az ügyfélhez kötődik – nem kell újra kikeresni.
        $ugyfel_id = $eszkoz !== null
            ? (int) $eszkoz->ugyfel_id
            : (isset($_GET['ugyfel_id']) ? (int) $_GET['ugyfel_id'] : 0);

        // A kategória a popupban és a mezőben is a nevén jelenik meg; a
        // mentés alakítja vissza kulccsá.
        $kategoria_cimke = self::kategoria_cimke(self::kategoria_ervenyes($ert('kategoria', 'telefon')));
        $csat_db         = $uj ? 0 : SDH_Muhely_Csatolmany::darab('eszkoz', (int) $eszkoz->id);

        ?>
        <input type="hidden" name="id" value="<?php echo (int) ($eszkoz->id ?? 0); ?>">
        <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
        <input type="hidden" name="kep_url" data-sdh-kep-mezo value="<?php echo esc_attr($ert('kep_url')); ?>">
        <?php wp_nonce_field('sdh_muhely_eszkoz_mentes', 'sdh_nonce'); ?>

        <div class="sdh-ugyfelurlap sdh-eszkozurlap">
            <?php
            // Ugyanaz a kompakt, lapfüles szerkezet, mint az ügyfélűrlapon.
            // A lapfülek tisztán CSS-ből működnek (rádiógombok).
            ?>
            <div class="sdh-fulek">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--1" name="_ful_eszkoz"
                       id="ful_e_eszkoz" checked>
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--2" name="_ful_eszkoz"
                       id="ful_e_azonositok">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--3" name="_ful_eszkoz"
                       id="ful_e_garancia">

                <div class="sdh-fulek__sav">
                    <label for="ful_e_eszkoz">Eszköz</label>
                    <label for="ful_e_azonositok">Azonosítók</label>
                    <label for="ful_e_garancia">Garancia és átvétel</label>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--1">
                    <div class="sdh-sor">
                        <div class="sdh-ig">
                            <span class="sdh-ig__cimke">Ügyfél <span class="sdh-kotelezo">*</span></span>
                            <div class="sdh-mezo__sor">
                                <?php SDH_Muhely_Ugyfel::valaszto_mezo($ugyfel_id); ?>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--plusz"
                                        data-sdh-uj-ugyfel
                                        title="Új ügyfél felvétele" aria-label="Új ügyfél felvétele">+</button>
                            </div>
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="kategoria">Kategória</label>
                            <?php self::pop_mezo('kategoria', 'kategoria', $kategoria_cimke, 'Válassz kategóriát…', self::kategoria_csoportok_pop()); ?>
                        </div>

                        <div class="sdh-ig">
                            <label for="gyarto">Gyártó</label>
                            <?php self::pop_mezo('gyarto', 'gyarto', $ert('gyarto'), 'Válassz gyártót…', self::gyarto_csoportok()); ?>
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="tipus">Gyári szám <span class="sdh-kotelezo">*</span></label>
                            <input type="text" name="tipus" id="tipus"
                                   placeholder="Pl. SM-A505F/DS vagy A1660"
                                   title="A gyártó modellkódja – ez az azonosító, nem a kereskedelmi név."
                                   value="<?php echo esc_attr($ert('tipus')); ?>">
                        </div>

                        <div class="sdh-ig">
                            <label for="megnevezes">Keresk. név</label>
                            <input type="text" name="megnevezes" id="megnevezes"
                                   placeholder="Pl. Galaxy A50, iPhone 7"
                                   title="Amin az ügyfél keresi. Kereséshez jó, azonosításra nem."
                                   value="<?php echo esc_attr($ert('megnevezes')); ?>">
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="szin">Szín</label>
                            <?php self::pop_mezo('szin', 'szin', $ert('szin'), 'Válassz színt…', self::szinek()); ?>
                        </div>

                        <div class="sdh-ig">
                            <label for="tartozekok">Tartozékok</label>
                            <?php self::pop_mezo('tartozekok', 'tartozek', $ert('tartozekok'), 'Mit hozott magával…', self::tartozek_lista()); ?>
                        </div>
                    </div>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--2">
                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="imei">IMEI</label>
                            <input type="text" name="imei" id="imei" inputmode="numeric" maxlength="15"
                                   data-sdh-imei autocomplete="off"
                                   title="15 számjegy után megnézem, járt-e már nálunk ez a készülék."
                                   value="<?php echo esc_attr($ert('imei')); ?>">
                        </div>

                        <div class="sdh-ig">
                            <label for="imei2">IMEI 2.</label>
                            <input type="text" name="imei2" id="imei2" inputmode="numeric" autocomplete="off"
                                   value="<?php echo esc_attr($ert('imei2')); ?>">
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="sorozatszam">Sorozatszám</label>
                            <input type="text" name="sorozatszam" id="sorozatszam"
                                   value="<?php echo esc_attr($ert('sorozatszam')); ?>">
                        </div>

                        <div class="sdh-ig">
                            <label for="modell_szam">Modellszám</label>
                            <input type="text" name="modell_szam" id="modell_szam"
                                   title="A teljes gyári kód, pl. SM-A505FZBCAFG."
                                   value="<?php echo esc_attr($ert('modell_szam')); ?>">
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="zarkod">Zárkód / PIN</label>
                            <input type="text" name="zarkod" id="zarkod" autocomplete="off"
                                   title="E nélkül a javítás nagy része nem tesztelhető."
                                   value="<?php echo esc_attr($ert('zarkod')); ?>">
                        </div>
                    </div>

                    <div class="sdh-sor">
                        <div class="sdh-ig sdh-ig--felso">
                            <span class="sdh-ig__cimke">Feloldó minta</span>
                            <?php self::minta_mezo($ert('minta')); ?>
                        </div>
                    </div>

                    <div class="sdh-sor">
                        <div class="sdh-ig">
                            <span class="sdh-ig__cimke">Ellenőrzés</span>
                            <div class="sdh-ig__gombok">
                                <?php foreach (SDH_Muhely_Imei_Lekerdezes::ellenorzok() as $ellenorzo) : ?>
                                    <?php
                                    // Az {imei} helyőrzőt a böngésző nem szeretné a linkben:
                                    // a href tiszta cím, a helyőrzős változatot a JS használja.
                                    $tiszta_url = str_replace('{imei}', '', (string) $ellenorzo['url']);
                                    ?>
                                    <a class="sdh-gomb sdh-gomb--vilagos"
                                       href="<?php echo esc_url($tiszta_url); ?>"
                                       target="_blank" rel="noopener noreferrer"
                                       data-sdh-ellenorzo="<?php echo esc_attr((string) $ellenorzo['url']); ?>">
                                        <?php echo esc_html((string) $ellenorzo['nev']); ?> ↗
                                    </a>
                                <?php endforeach; ?>

                                <?php if (SDH_Muhely_Imei_Lekerdezes::beallitva()) : ?>
                                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-imei-lekerdez>
                                        Lekérdezés a szolgáltatótól
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="sdh-sor">
                        <div class="sdh-ig sdh-ig--felso">
                            <label for="beillesztes">Gyári adatok</label>
                            <div class="sdh-ig__beillesztes">
                                <textarea id="beillesztes" data-sdh-beillesztes rows="2"
                                          placeholder="Az ellenőrző oldal eredményét másold ide – a mezőket kitöltöm (gyári szám, sorozatszám, garancia, kép…)"></textarea>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-beillesztes-feldolgoz>
                                    Feldolgozás
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--3">
                    <label class="sdh-jelolo" for="garancias">
                        <input type="checkbox" name="garancias" id="garancias" value="1"
                            <?php checked($ert('garancias'), '1'); ?>>
                        Garanciális készülék
                    </label>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="vasarlas_datuma">Vásárlás</label>
                            <input type="date" name="vasarlas_datuma" id="vasarlas_datuma"
                                   value="<?php echo esc_attr(self::datum($ert('vasarlas_datuma'))); ?>">
                        </div>

                        <div class="sdh-ig">
                            <label for="garancia_lejar">Garancia lejár</label>
                            <input type="date" name="garancia_lejar" id="garancia_lejar"
                                   value="<?php echo esc_attr(self::datum($ert('garancia_lejar'))); ?>">
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="garancia_allapot">Garancia áll.</label>
                            <input type="text" name="garancia_allapot" id="garancia_allapot"
                                   value="<?php echo esc_attr($ert('garancia_allapot')); ?>">
                        </div>

                        <div class="sdh-ig">
                            <label for="gyartas_datuma">Gyártás</label>
                            <input type="date" name="gyartas_datuma" id="gyartas_datuma"
                                   value="<?php echo esc_attr(self::datum($ert('gyartas_datuma'))); ?>">
                        </div>
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="orszag">Ország</label>
                            <input type="text" name="orszag" id="orszag"
                                   value="<?php echo esc_attr($ert('orszag')); ?>">
                        </div>

                        <div class="sdh-ig">
                            <label for="szolgaltato">SIM-zár</label>
                            <input type="text" name="szolgaltato" id="szolgaltato"
                                   placeholder="Szolgáltató / SIM-zár"
                                   value="<?php echo esc_attr($ert('szolgaltato')); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="sdh-fulek sdh-fulek--also">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--1" name="_ful_also_e"
                       id="ful_e_megjegyzes" checked>
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--2" name="_ful_also_e"
                       id="ful_e_belso">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--3" name="_ful_also_e"
                       id="ful_e_allapot">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--4" name="_ful_also_e"
                       id="ful_e_kep">

                <div class="sdh-fulek__sav">
                    <label for="ful_e_megjegyzes">Megjegyzés</label>
                    <label for="ful_e_belso">Belső megjegyzés</label>
                    <label for="ful_e_allapot">Átvételkori állapot</label>
                    <label for="ful_e_kep">Készülékkép / fájlok<?php
                        echo $csat_db > 0 ? ' <span class="sdh-fulek__db">' . (int) $csat_db . '</span>' : '';
                    ?></label>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--1">
                    <textarea name="megjegyzes" id="megjegyzes" aria-label="Megjegyzés"
                              placeholder="Az ügyfél felé is megjelenhet (pl. nyomtatványon)."><?php
                        echo esc_textarea($ert('megjegyzes'));
                    ?></textarea>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--2">
                    <textarea name="belso_megjegyzes" id="belso_megjegyzes" aria-label="Belső megjegyzés"
                              placeholder="Csak a CRM-ben látszik."><?php
                        echo esc_textarea($ert('belso_megjegyzes'));
                    ?></textarea>
                    <span class="sdh-zar">
                        Belső: az ügyfél nem látja, és sem a munkalapra, sem nyomtatványra nem kerül ki.
                    </span>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--3">
                    <textarea name="atveteli_allapot" id="atveteli_allapot" aria-label="Átvételkori állapot"
                              placeholder="Karcok, törött üveg, hiányzó alkatrész – ez véd a későbbi vitáktól."><?php
                        echo esc_textarea($ert('atveteli_allapot'));
                    ?></textarea>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--4">
                    <div class="sdh-kep" data-sdh-kep>
                        <?php if ($ert('kep_url') !== '') : ?>
                            <img src="<?php echo esc_url($ert('kep_url')); ?>" alt="">
                        <?php endif; ?>
                    </div>
                    <?php SDH_Muhely_Csatolmany::panel('eszkoz', $uj ? 0 : (int) $eszkoz->id); ?>
                </div>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">
                    <?php echo $uj ? 'Eszköz létrehozása' : 'Mentés'; ?>
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
     * Popupból választható mező.
     *
     * Csak olvasható szövegmező: a kattintás popupot nyit (app.js, data-sdh-pop),
     * a választás visszaíródik a mezőbe. A mező maga adja az értéket a mentésnek,
     * ezért az IMEI-kitöltő és a beillesztés is simán beleírhat.
     *
     * @param array<int|string, mixed> $adat A popup tartalma (JSON-ként utazik).
     */
    private static function pop_mezo(string $nev, string $tipus, string $ertek, string $helyorzo, array $adat): void
    {
        ?>
        <input type="text" name="<?php echo esc_attr($nev); ?>" id="<?php echo esc_attr($nev); ?>"
               class="sdh-pop" readonly autocomplete="off"
               data-sdh-pop="<?php echo esc_attr($tipus); ?>"
               data-sdh-pop-adat="<?php echo esc_attr((string) wp_json_encode($adat)); ?>"
               placeholder="<?php echo esc_attr($helyorzo); ?>"
               value="<?php echo esc_attr($ertek); ?>">
        <?php
    }

    /**
     * A feloldó minta rajzolható mezője.
     *
     * Szövegként leírva a minta félreérthető („balról jobbra az alsó
     * sor" – melyik irányból?), és a pultnál ez naponta okoz gondot.
     * Itt egérrel behúzható, és a nyilak mutatják az irányt. A tárolt
     * érték a pöttyök sorrendje: a bal felső az 1, a jobb alsó a 9.
     */
    private static function minta_mezo(string $ertek): void
    {
        ?>
        <div class="sdh-minta" data-sdh-minta>
            <input type="hidden" name="minta" value="<?php echo esc_attr($ertek); ?>">

            <svg class="sdh-minta__rajz" viewBox="0 0 240 240"
                 role="img" aria-label="Feloldó minta rajzolása"></svg>

            <div class="sdh-minta__lab">
                <span class="sdh-minta__sor"></span>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-minta-torol>
                    Törlés
                </button>
            </div>

            <span class="sdh-mezo__sugo">
                Húzd be egérrel, ahogy az ügyfél mutatta. A nyilak az irányt jelölik.
                A sorszámozás balról jobbra, fentről lefelé: 1–9.
            </span>
        </div>
        <?php
    }

    /**
     * A feloldó minta megtisztítása.
     *
     * Csak 1–9 közötti számjegy, mindegyik legfeljebb egyszer – a
     * telefon sem enged ugyanarra a pöttyre kétszer lépni. Ami ennek
     * nem felel meg, azt eldobjuk, nem próbáljuk megjavítani: egy
     * félig értelmezett minta rosszabb, mint a semmi.
     */
    private static function minta_tisztit(string $ertek): string
    {
        $szamjegyek = preg_replace('/[^1-9]/', '', $ertek) ?? '';

        $latott = [];

        foreach (str_split($szamjegyek) as $szamjegy) {
            if (in_array($szamjegy, $latott, true)) {
                return '';
            }

            $latott[] = $szamjegy;
        }

        return count($latott) >= 2 ? implode('', $latott) : '';
    }

    private static function kontextus_ertek(): string
    {
        if (wp_doing_ajax() && isset($_REQUEST['kontextus'])) {
            return sanitize_key(wp_unslash($_REQUEST['kontextus'])) === 'frontend' ? 'frontend' : 'admin';
        }

        return SDH_Muhely_Modulok::kontextus();
    }

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

    /* =================================================================
     * Mentés
     * ============================================================== */

    /**
     * @return array<string, mixed>
     */
    private static function adatok_osszeallit(): array
    {
        $szoveg = static fn (string $mezo): string => isset($_POST[$mezo])
            ? sanitize_text_field(wp_unslash($_POST[$mezo]))
            : '';

        return [
            'ugyfel_id'        => isset($_POST['ugyfel_id']) ? (int) $_POST['ugyfel_id'] : 0,
            'kategoria'        => self::kategoria_ervenyes($szoveg('kategoria')),
            'gyarto'           => $szoveg('gyarto'),
            'tipus'            => $szoveg('tipus'),
            'megnevezes'       => $szoveg('megnevezes'),
            'imei'             => $szoveg('imei'),
            'imei2'            => $szoveg('imei2'),
            'sorozatszam'      => $szoveg('sorozatszam'),
            'modell_szam'      => $szoveg('modell_szam'),
            'garancia_allapot' => $szoveg('garancia_allapot'),
            'gyartas_datuma'   => self::datum_vagy_null($szoveg('gyartas_datuma')),
            'orszag'           => $szoveg('orszag'),
            'szolgaltato'      => $szoveg('szolgaltato'),
            'kep_url'          => isset($_POST['kep_url'])
                ? esc_url_raw(wp_unslash($_POST['kep_url']))
                : '',
            'szin'             => $szoveg('szin'),
            'zarkod'           => $szoveg('zarkod'),
            'minta'            => self::minta_tisztit($szoveg('minta')),
            'tartozekok'       => self::tartozekok_tisztit($szoveg('tartozekok')),
            'atveteli_allapot' => isset($_POST['atveteli_allapot'])
                ? sanitize_textarea_field(wp_unslash($_POST['atveteli_allapot']))
                : '',
            'garancias'        => !empty($_POST['garancias']) ? 1 : 0,
            'vasarlas_datuma'  => self::datum_vagy_null($szoveg('vasarlas_datuma')),
            'garancia_lejar'   => self::datum_vagy_null($szoveg('garancia_lejar')),
            'megjegyzes'       => isset($_POST['megjegyzes'])
                ? sanitize_textarea_field(wp_unslash($_POST['megjegyzes']))
                : '',
            // Belső: csak a CRM-ben él, lásd kulso_adatok().
            'belso_megjegyzes' => isset($_POST['belso_megjegyzes'])
                ? sanitize_textarea_field(wp_unslash($_POST['belso_megjegyzes']))
                : '',
            'modositva'        => current_time('mysql'),
            'lekerdezve'       => isset($_POST['lekerdezve']) && $_POST['lekerdezve'] === '1'
                ? current_time('mysql')
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $adatok
     * @return array{0: int, 1: string}|null
     */
    private static function adatbazisba(int $id, array $adatok): ?array
    {
        global $wpdb;

        if ($id > 0) {
            $eredmeny = $wpdb->update(self::tabla(), $adatok, ['id' => $id]);
            $uzenet   = 'mentve';
        } else {
            $adatok['letrehozva'] = current_time('mysql');
            $adatok['letrehozo']  = get_current_user_id();
            $adatok['aktiv']      = 1;
            $adatok['forras']     = 'kezi';

            $eredmeny = $wpdb->insert(self::tabla(), $adatok);
            $id       = (int) $wpdb->insert_id;
            $uzenet   = 'letrehozva_eszkoz';
        }

        if ($eredmeny === false) {
            return null;
        }

        // Minden mentés taníthatja a TAC-adatbázist: ha ez a típus még
        // ismeretlen volt, a következő ugyanolyan készüléknél már
        // magától kitöltődik.
        if (class_exists('SDH_Muhely_Tac')) {
            SDH_Muhely_Tac::tanul(
                (string) ($adatok['imei'] ?? ''),
                (string) ($adatok['gyarto'] ?? ''),
                (string) ($adatok['tipus'] ?? ''),
                (string) ($adatok['megnevezes'] ?? ''),
                (string) ($adatok['kep_url'] ?? '')
            );
        }

        return [$id, $uzenet];
    }

    /**
     * Az ügyfél megléte nem formaság: eszköz ügyfél nélkül nem
     * azonosítható, és a munkalap sem tudná, kinek adja vissza.
     */
    private static function ugyfel_hibas(int $ugyfel_id): bool
    {
        return $ugyfel_id <= 0 || SDH_Muhely_Ugyfel::nev($ugyfel_id) === '';
    }

    public static function mentes(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_eszkoz_mentes', 'sdh_nonce');

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adatok = self::adatok_osszeallit();

        if (self::ugyfel_hibas((int) $adatok['ugyfel_id'])) {
            wp_safe_redirect(
                self::vissza(
                    array_filter([
                        'nezet'  => $id > 0 ? 'szerkeszt' : 'uj',
                        'id'     => $id > 0 ? $id : null,
                        'uzenet' => 'hianyzo_ugyfel',
                    ])
                )
            );
            exit;
        }

        // Hibás fájl esetén semmi sem mentődik félig.
        if (SDH_Muhely_Csatolmany::ellenoriz() !== null) {
            wp_safe_redirect(
                self::vissza(
                    array_filter([
                        'nezet'  => $id > 0 ? 'szerkeszt' : 'uj',
                        'id'     => $id > 0 ? $id : null,
                        'uzenet' => 'csatolmany_hiba',
                    ])
                )
            );
            exit;
        }

        $eredmeny = self::adatbazisba($id, $adatok);

        if ($eredmeny === null) {
            wp_safe_redirect(self::vissza(['uzenet' => 'mentes_hiba']));
            exit;
        }

        [$uj_id, $uzenet] = $eredmeny;

        SDH_Muhely_Csatolmany::feldolgoz('eszkoz', $uj_id);

        wp_safe_redirect(self::vissza(['nezet' => 'szerkeszt', 'id' => $uj_id, 'uzenet' => $uzenet]));
        exit;
    }

    public static function ajax_mentes(): void
    {
        check_ajax_referer('sdh_muhely_eszkoz_mentes', 'sdh_nonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adatok = self::adatok_osszeallit();

        if (self::ugyfel_hibas((int) $adatok['ugyfel_id'])) {
            wp_send_json_error([
                'uzenet' => 'Válassz ügyfelet a listából – gépelés önmagában nem elég.',
            ]);
        }

        $csat_hiba = SDH_Muhely_Csatolmany::ellenoriz();

        if ($csat_hiba !== null) {
            wp_send_json_error(['uzenet' => $csat_hiba]);
        }

        $eredmeny = self::adatbazisba($id, $adatok);

        if ($eredmeny === null) {
            wp_send_json_error(['uzenet' => 'Az adatbázis visszautasította a mentést.']);
        }

        [$uj_id, $uzenet] = $eredmeny;

        $csat = SDH_Muhely_Csatolmany::feldolgoz('eszkoz', $uj_id);

        if ($csat['hibak'] !== []) {
            // Az eszköz már mentve van, ezért ezt külön, érthetően jelezzük.
            wp_send_json_error([
                'uzenet' => 'Az eszköz mentve, de néhány fájl nem: ' . implode(' ', $csat['hibak']),
            ]);
        }

        // Az ügyfél adatai a munkalap-űrlap „+” gombjához kellenek: az új
        // eszköz oldalfrissítés nélkül kiválasztódik, és az ügyfélmező is
        // követi, ha a popupban másik ügyfelet választottak.
        $ugyfel_id = (int) $adatok['ugyfel_id'];

        wp_send_json_success([
            'id'         => $uj_id,
            'ugyfel_id'  => $ugyfel_id,
            'ugyfel_nev' => SDH_Muhely_Ugyfel::nev($ugyfel_id),
            'vissza'     => self::vissza(['uzenet' => $uzenet]),
        ]);
    }

    /* =================================================================
     * Segédek
     * ============================================================== */

    /**
     * A készülék megnevezése a listákban és a fejlécben.
     *
     * A gyári szám az elsődleges – az azonosít egyértelműen. A
     * kereskedelmi név csak akkor jelenik meg helyette, ha gyári szám
     * nincs felvéve.
     */
    public static function megnevezes(object $eszkoz): string
    {
        $nev = trim(($eszkoz->gyarto ?? '') . ' ' . ($eszkoz->tipus ?? ''));

        if (trim((string) ($eszkoz->tipus ?? '')) === '') {
            $nev = trim(($eszkoz->gyarto ?? '') . ' ' . ($eszkoz->megnevezes ?? ''));
        }

        return $nev !== '' ? $nev : 'Névtelen eszköz';
    }

    /** Az adatbázisban NULL lehet; az űrlap üres mezőt vár. */
    private static function datum(string $ertek): string
    {
        return ($ertek === '' || str_starts_with($ertek, '0000')) ? '' : $ertek;
    }

    private static function datum_vagy_null(string $ertek): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $ertek) === 1 ? $ertek : null;
    }

    /* =================================================================
     * Kategóriák, gyártók, színek, tartozékok
     *
     * A popupok tartalma. Gépelési segítség, nem korlátozás: a gyártó,
     * a szín és a tartozékok mellé mindig beírható saját is.
     * ============================================================== */

    /**
     * Csoportosított kategóriák: csoport => [kulcs => felirat].
     * A kulcsok tárolódnak; a régiek (telefon, tablet, laptop, ora,
     * asztali, egyeb) változatlanok, hogy a meglévő eszközök megmaradjanak.
     *
     * @return array<string, array<string, string>>
     */
    public static function kategoria_csoportok(): array
    {
        return [
            'Mobil és hordható' => [
                'telefon'    => 'Telefon',
                'tablet'     => 'Tablet',
                'ora'        => 'Okosóra / sportóra',
                'fejhallgato' => 'Fül- és fejhallgató',
                'ebook'      => 'E-book olvasó',
                'vr'         => 'VR / AR eszköz',
                'powerbank'  => 'Powerbank / töltő',
            ],
            'Számítástechnika' => [
                'laptop'     => 'Laptop',
                'asztali'    => 'Asztali gép',
                'aio'        => 'All-in-one gép',
                'monitor'    => 'Monitor',
                'nyomtato'   => 'Nyomtató / szkenner',
                'halozat'    => 'Router / hálózati eszköz',
                'tarolo'     => 'Külső tároló (SSD, HDD)',
                'periferia'  => 'Billentyűzet / egér / webkamera',
                'alkatresz'  => 'PC-alkatrész (VGA, alaplap, RAM)',
                'ups'        => 'Szünetmentes tápegység (UPS)',
                'pos'        => 'Pénztárgép / vonalkódolvasó',
            ],
            'Szórakoztató elektronika' => [
                'tv'         => 'Televízió',
                'tvbox'      => 'TV-box / médialejátszó',
                'konzol'     => 'Játékkonzol',
                'kezi_konzol' => 'Kézi konzol',
                'kontroller' => 'Kontroller / játékeszköz',
                'projektor'  => 'Projektor',
                'hifi'       => 'Hifi / hangfal / erősítő',
                'soundbar'   => 'Soundbar / házimozi',
                'hangszoro'  => 'Bluetooth hangszóró',
                'radio'      => 'Rádió / lemezjátszó',
            ],
            'Fotó és videó' => [
                'fenykepezo' => 'Fényképezőgép',
                'kamera'     => 'Videó- / akciókamera',
                'drone'      => 'Drón',
            ],
            'Háztartás' => [
                'nagygep'    => 'Nagygép (mosógép, hűtő, mosogatógép)',
                'kisgep'     => 'Kisgép (kávéfőző, turmixgép, sütő)',
                'porszivo'   => 'Porszívó / robotporszívó',
                'klima'      => 'Klíma / fűtés / ventilátor',
                'vasalo'     => 'Vasaló / gőzállomás',
            ],
            'Szépségápolás' => [
                'hajszarito' => 'Hajszárító',
                'hajformazo' => 'Hajvasaló / hajformázó',
                'borotva'    => 'Borotva / szakállvágó',
                'fogkefe'    => 'Elektromos fogkefe / szájzuhany',
            ],
            'Okosotthon és biztonság' => [
                'okosotthon' => 'Okosotthon-eszköz',
                'biz_kamera' => 'Biztonsági kamera',
                'riaszto'    => 'Riasztó / kaputelefon',
            ],
            'Közlekedés' => [
                'roller'     => 'Elektromos roller / kerékpár',
                'auto_elektronika' => 'Autós elektronika (fejegység, GPS)',
            ],
            'Egyéb' => [
                'szerszam'   => 'Akkus szerszám',
                'orvosi'     => 'Orvosi / egészségügyi eszköz',
                'jatek'      => 'RC / elektronikus játék',
                'vilagitas'  => 'Lámpa / világítás',
                'hangszer'   => 'Elektronikus hangszer',
                'meromuszer' => 'Mérőműszer',
                'egyeb'      => 'Egyéb eszköz',
            ],
        ];
    }

    /**
     * @return array<string, string> kulcs => felirat
     */
    public static function kategoriak(): array
    {
        $lapos = [];

        foreach (self::kategoria_csoportok() as $csoport) {
            $lapos += $csoport;
        }

        return $lapos;
    }

    /**
     * A kategória-popup adata: a felirat az érték, mert a mező is a feliratot mutatja.
     *
     * @return array<int, array{cim: string, elemek: array<int, string>}>
     */
    private static function kategoria_csoportok_pop(): array
    {
        $ki = [];

        foreach (self::kategoria_csoportok() as $cim => $elemek) {
            $ki[] = ['cim' => $cim, 'elemek' => array_values($elemek)];
        }

        return $ki;
    }

    /**
     * A beküldött érték (felirat vagy kulcs) vissza kulccsá.
     * Üres érték: telefon (a megszokott alap); ismeretlen: egyéb.
     */
    private static function kategoria_ervenyes(string $kategoria): string
    {
        $kategoria = trim($kategoria);

        if ($kategoria === '') {
            return 'telefon';
        }

        $minden = self::kategoriak();

        if (isset($minden[$kategoria])) {
            return $kategoria;
        }

        $keresett = mb_strtolower($kategoria);

        foreach ($minden as $kulcs => $felirat) {
            if (mb_strtolower($felirat) === $keresett) {
                return $kulcs;
            }
        }

        return 'egyeb';
    }

    public static function kategoria_cimke(string $kategoria): string
    {
        return self::kategoriak()[$kategoria] ?? $kategoria;
    }

    /**
     * A népszerű gyártók, csoportosítva. Ami több csoportba is illene,
     * csak az elsőben szerepel.
     *
     * @return array<int, array{cim: string, elemek: array<int, string>}>
     */
    private static function gyarto_csoportok(): array
    {
        $csoportok = [
            'Telefon, tablet' => [
                'Apple', 'Samsung', 'Xiaomi', 'Huawei', 'Honor', 'Motorola', 'OnePlus', 'Oppo', 'Realme',
                'Vivo', 'Google', 'Nokia', 'Sony', 'LG', 'ZTE', 'Nothing', 'Poco', 'Tecno', 'Infinix',
                'Alcatel', 'TCL', 'HTC', 'Fairphone', 'Meizu', 'Wiko', 'Blackview', 'Doogee', 'Ulefone', 'Cat',
            ],
            'Számítástechnika' => [
                'Asus', 'Lenovo', 'Acer', 'Dell', 'HP', 'MSI', 'Microsoft', 'Toshiba', 'Gigabyte', 'Razer',
                'Logitech', 'Canon', 'Epson', 'Brother', 'Western Digital', 'Seagate', 'SanDisk', 'Kingston',
                'TP-Link', 'Netgear', 'D-Link',
            ],
            'TV, hang, játék' => [
                'Philips', 'Panasonic', 'Sharp', 'Hisense', 'Grundig', 'JBL', 'Bose', 'Marshall',
                'Harman Kardon', 'Yamaha', 'Pioneer', 'Denon', 'Sennheiser', 'Beats', 'Anker', 'Nintendo', 'Valve',
            ],
            'Fotó, hordható' => [
                'Nikon', 'Fujifilm', 'GoPro', 'DJI', 'Garmin', 'Fitbit', 'Amazfit', 'Polar', 'Suunto', 'Leica',
            ],
            'Háztartás, szépségápolás' => [
                'Bosch', 'Siemens', 'Whirlpool', 'Electrolux', 'Gorenje', 'Miele', 'Beko', 'Zanussi', 'Candy',
                'Indesit', 'Dyson', 'Rowenta', 'Tefal', 'Braun', 'Remington', 'BaByliss', 'Roborock', 'iRobot',
                'Kärcher', 'Severin', 'Sencor', 'Hoover', 'Oral-B', 'De\'Longhi', 'Krups',
            ],
        ];

        $latott = [];
        $ki     = [];

        foreach ($csoportok as $cim => $nevek) {
            $elemek = [];

            foreach ($nevek as $nev) {
                if (isset($latott[$nev])) {
                    continue;
                }

                $latott[$nev] = true;
                $elemek[]     = $nev;
            }

            $ki[] = ['cim' => $cim, 'elemek' => $elemek];
        }

        return $ki;
    }

    /**
     * Színek: név + a mintakör háttere (CSS). A név kerül a mezőbe.
     *
     * @return array<int, array{nev: string, h: string}>
     */
    private static function szinek(): array
    {
        $lista = [
            'Fekete' => '#1b1b1d', 'Fehér' => '#f5f5f2', 'Ezüst' => '#c9ccd1', 'Szürke' => '#8e9096',
            'Asztroszürke' => '#4a4b50', 'Grafit' => '#3b3d42', 'Titán' => '#a9a49b', 'Arany' => '#d9b86c',
            'Rózsaarany' => '#e6b8a8', 'Piros' => '#d4231d', 'Bordó' => '#7a1e2b', 'Narancs' => '#f08a14',
            'Sárga' => '#f2c200', 'Zöld' => '#2e9b57', 'Sötétzöld' => '#2f4f3e', 'Türkiz' => '#1ab5b0',
            'Égszínkék' => '#8fc4ec', 'Kék' => '#3a78d8', 'Sötétkék' => '#1f3563', 'Lila' => '#7a55c9',
            'Rózsaszín' => '#f0a0c0', 'Barna' => '#7b5a3c', 'Bézs / krém' => '#e6dcc6',
            'Éjfekete' => '#101114', 'Csillagfény' => '#efe9dc',
            'Átlátszó' => 'repeating-conic-gradient(#d7dbe0 0% 25%, #ffffff 0% 50%) 50% / 10px 10px',
            'Többszínű' => 'linear-gradient(135deg, #d4231d, #f2c200, #2e9b57, #3a78d8)',
        ];

        $ki = [];

        foreach ($lista as $nev => $hatter) {
            $ki[] = ['nev' => $nev, 'h' => $hatter];
        }

        return $ki;
    }

    /**
     * A tartozéklista. Az első nyolc a MunkaLap 3 átvételi lapjáról való.
     *
     * @return array<int, string>
     */
    private static function tartozek_lista(): array
    {
        return [
            'Csereeszköz', 'Hálózati kábel', 'Akku', 'Akkufedél / hátlap', 'Adatkábel', 'Töltő', 'Doboz', 'Headset',
            'SIM-kártya', 'SIM-tálca', 'Memóriakártya', 'Tok', 'Védőfólia / üveg', 'Fülhallgató',
            'Toll (S Pen / Pencil)', 'Távirányító', 'Állvány / tartó', 'Adapter', 'Kézikönyv',
            'Számla / garancialevél',
        ];
    }

    /**
     * A tartozékok tárolt alakja: vesszővel elválasztott lista. A saját
     * beírt tételekből a vesszőt kivesszük, hogy visszaolvasáskor ne essen szét.
     */
    private static function tartozekok_tisztit(string $ertek): string
    {
        $tetelek = [];

        foreach (explode(',', $ertek) as $tetel) {
            $tetel = trim($tetel);

            if ($tetel !== '' && !in_array($tetel, $tetelek, true)) {
                $tetelek[] = $tetel;
            }
        }

        return mb_substr(implode(', ', $tetelek), 0, 1000);
    }

    /* =================================================================
     * Külső felület / nyomtatás
     * ============================================================== */

    /**
     * Az eszköz azon adatai, amelyek az ügyfél elé kerülhetnek:
     * nyomtatványra, átvételi elismervényre, ügyfélnek küldött üzenetbe.
     *
     * A belső megjegyzés SZÁNDÉKOSAN hiányzik: az csak a CRM-ben él.
     * Minden ügyfél felé menő kimenet ezt a függvényt használja,
     * soha ne az eszköz sorát közvetlenül – így egy új nyomtatvány
     * sem szivárogtathatja ki véletlenül.
     *
     * @return array<string, string>
     */
    public static function kulso_adatok(object $eszkoz): array
    {
        return [
            'megnevezes'       => self::megnevezes($eszkoz),
            'kategoria'        => self::kategoria_cimke((string) ($eszkoz->kategoria ?? '')),
            'gyarto'           => (string) ($eszkoz->gyarto ?? ''),
            'tipus'            => (string) ($eszkoz->tipus ?? ''),
            'imei'             => (string) ($eszkoz->imei ?? ''),
            'imei2'            => (string) ($eszkoz->imei2 ?? ''),
            'sorozatszam'      => (string) ($eszkoz->sorozatszam ?? ''),
            'szin'             => (string) ($eszkoz->szin ?? ''),
            'tartozekok'       => (string) ($eszkoz->tartozekok ?? ''),
            'atveteli_allapot' => (string) ($eszkoz->atveteli_allapot ?? ''),
            'megjegyzes'       => (string) ($eszkoz->megjegyzes ?? ''),
        ];
    }
}
