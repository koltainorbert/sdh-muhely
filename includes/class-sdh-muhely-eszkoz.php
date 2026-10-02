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
                'kategoria'        => (string) $sor->kategoria,
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
                'tartozekok'       => (string) $sor->tartozekok,
                'atveteli_allapot' => (string) ($sor->atveteli_allapot ?? ''),
                'megjegyzes'       => (string) ($sor->megjegyzes ?? ''),
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
                        <th style="width:60px"></th>
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
                $uj ? 'Előbb válaszd ki az ügyfelet, akihez a készülék tartozik.' : '',
                [
                    [
                        'cimke' => '← Vissza a listához',
                        'url'   => self::url(),
                    ],
                ]
            );
            ?>

            <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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

        <form class="sdh-urlap" method="post"
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
            ? (string) ($eszkoz->{$mezo} ?? '')
            : $alap;

        $uj = $eszkoz === null;

        // Ha az eszközlistát ügyfélre szűrve nyitottad meg, az új eszköz
        // rögtön ahhoz az ügyfélhez kötődik – nem kell újra kikeresni.
        $ugyfel_id = $eszkoz !== null
            ? (int) $eszkoz->ugyfel_id
            : (isset($_GET['ugyfel_id']) ? (int) $_GET['ugyfel_id'] : 0);

        ?>
        <input type="hidden" name="id" value="<?php echo (int) ($eszkoz->id ?? 0); ?>">
        <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
        <?php wp_nonce_field('sdh_muhely_eszkoz_mentes', 'sdh_nonce'); ?>

        <div class="sdh-doboz sdh-doboz--beilleszt">
            <h2 class="sdh-doboz__cim">Gyári adatok beillesztése</h2>

            <p style="margin:0 0 .8rem;font-size:12px;line-height:1.6;color:var(--sdh-halvany);max-width:62em">
                Kérdezd le az IMEI-t a megszokott ingyenes oldalon, jelöld ki az eredményt,
                és illeszd ide be. A mezőket magamtól kitöltöm – gyári szám, sorozatszám,
                garancia, gyártási dátum, kép.
            </p>

            <div class="sdh-mezo sdh-mezo--szeles">
                <textarea data-sdh-beillesztes rows="3"
                          placeholder="Model Name: SM-A505F/DS&#10;Serial Number: R58M51QT5RE&#10;…"></textarea>
            </div>

            <div class="sdh-urlap__lablec" style="padding-top:.6rem">
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-beillesztes-feldolgoz>
                    Feldolgozás
                </button>
                <?php if (SDH_Muhely_Imei_Lekerdezes::beallitva()) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-imei-lekerdez>
                        Lekérdezés a szolgáltatótól
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Ügyfél és készülék</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label>Ügyfél <span class="sdh-kotelezo">*</span></label>
                    <?php SDH_Muhely_Ugyfel::valaszto_mezo($ugyfel_id); ?>
                    <span class="sdh-mezo__sugo">
                        Gépelj legalább két betűt, és válassz a listából. Enter az első találatot veszi.
                    </span>
                </div>

                <div class="sdh-mezo">
                    <label for="kategoria">Kategória</label>
                    <select name="kategoria" id="kategoria">
                        <?php foreach (self::kategoriak() as $kulcs => $cimke) : ?>
                            <option value="<?php echo esc_attr($kulcs); ?>"
                                <?php selected($ert('kategoria', 'telefon'), $kulcs); ?>>
                                <?php echo esc_html($cimke); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sdh-mezo">
                    <label for="gyarto">Gyártó</label>
                    <input type="text" name="gyarto" id="gyarto" list="sdh-gyartok"
                           value="<?php echo esc_attr($ert('gyarto')); ?>">
                    <datalist id="sdh-gyartok">
                        <?php foreach (self::gyakori_gyartok() as $gy) : ?>
                            <option value="<?php echo esc_attr($gy); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="sdh-mezo">
                    <label for="tipus">Gyári szám <span class="sdh-kotelezo">*</span></label>
                    <input type="text" name="tipus" id="tipus"
                           value="<?php echo esc_attr($ert('tipus')); ?>">
                    <span class="sdh-mezo__sugo">
                        A gyártó modellkódja, pl. SM-A505F/DS vagy A1660 – ez az azonosító,
                        nem a kereskedelmi név.
                    </span>
                </div>

                <div class="sdh-mezo">
                    <label for="megnevezes">Kereskedelmi név</label>
                    <input type="text" name="megnevezes" id="megnevezes"
                           value="<?php echo esc_attr($ert('megnevezes')); ?>">
                    <span class="sdh-mezo__sugo">
                        Amin az ügyfél keresi: Galaxy A50, iPhone 7. Kereséshez jó, azonosításra nem.
                    </span>
                </div>

                <div class="sdh-mezo">
                    <label for="szin">Szín</label>
                    <input type="text" name="szin" id="szin"
                           value="<?php echo esc_attr($ert('szin')); ?>">
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Azonosítók</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="imei">IMEI</label>
                    <input type="text" name="imei" id="imei" inputmode="numeric" maxlength="15"
                           data-sdh-imei
                           value="<?php echo esc_attr($ert('imei')); ?>">
                    <span class="sdh-mezo__sugo">
                        15 számjegy után megnézem, járt-e már nálunk ez a készülék.
                    </span>
                </div>

                <div class="sdh-mezo">
                    <label for="imei2">IMEI 2.</label>
                    <input type="text" name="imei2" id="imei2" inputmode="numeric"
                           value="<?php echo esc_attr($ert('imei2')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="sorozatszam">Sorozatszám</label>
                    <input type="text" name="sorozatszam" id="sorozatszam"
                           value="<?php echo esc_attr($ert('sorozatszam')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="zarkod">Zárkód / PIN</label>
                    <input type="text" name="zarkod" id="zarkod"
                           value="<?php echo esc_attr($ert('zarkod')); ?>">
                    <span class="sdh-mezo__sugo">E nélkül a javítás nagy része nem tesztelhető.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="modell_szam">Modellszám</label>
                    <input type="text" name="modell_szam" id="modell_szam"
                           value="<?php echo esc_attr($ert('modell_szam')); ?>">
                    <span class="sdh-mezo__sugo">A teljes gyári kód, pl. SM-A505FZBCAFG.</span>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label>Feloldó minta</label>
                    <?php self::minta_mezo($ert('minta')); ?>
                </div>
            </div>
        </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Átvétel és garancia</h2>

            <div class="sdh-mezo sdh-mezo--jelolo" style="margin-bottom:1rem">
                <input type="checkbox" name="garancias" id="garancias" value="1"
                    <?php checked($ert('garancias'), '1'); ?>>
                <label for="garancias">Garanciális készülék</label>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="vasarlas_datuma">Vásárlás dátuma</label>
                    <input type="date" name="vasarlas_datuma" id="vasarlas_datuma"
                           value="<?php echo esc_attr(self::datum($ert('vasarlas_datuma'))); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="garancia_lejar">Garancia lejár</label>
                    <input type="date" name="garancia_lejar" id="garancia_lejar"
                           value="<?php echo esc_attr(self::datum($ert('garancia_lejar'))); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="garancia_allapot">Garancia állapota</label>
                    <input type="text" name="garancia_allapot" id="garancia_allapot"
                           value="<?php echo esc_attr($ert('garancia_allapot')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="gyartas_datuma">Gyártás dátuma</label>
                    <input type="date" name="gyartas_datuma" id="gyartas_datuma"
                           value="<?php echo esc_attr(self::datum($ert('gyartas_datuma'))); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="orszag">Ország</label>
                    <input type="text" name="orszag" id="orszag"
                           value="<?php echo esc_attr($ert('orszag')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="szolgaltato">Szolgáltató / SIM-zár</label>
                    <input type="text" name="szolgaltato" id="szolgaltato"
                           value="<?php echo esc_attr($ert('szolgaltato')); ?>">
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="kep_url">Készülékkép (URL)</label>
                    <input type="text" name="kep_url" id="kep_url" data-sdh-kep-mezo
                           value="<?php echo esc_attr($ert('kep_url')); ?>">
                    <div class="sdh-kep" data-sdh-kep>
                        <?php if ($ert('kep_url') !== '') : ?>
                            <img src="<?php echo esc_url($ert('kep_url')); ?>" alt="">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="tartozekok">Tartozékok</label>
                    <input type="text" name="tartozekok" id="tartozekok"
                           value="<?php echo esc_attr($ert('tartozekok')); ?>">
                    <span class="sdh-mezo__sugo">Amit a készülékkel együtt átvettünk – töltő, tok, SIM, memóriakártya.</span>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="atveteli_allapot">Átvételkori állapot</label>
                    <textarea name="atveteli_allapot" id="atveteli_allapot"><?php
                        echo esc_textarea($ert('atveteli_allapot'));
                    ?></textarea>
                    <span class="sdh-mezo__sugo">Karcok, törött üveg, hiányzó alkatrész – ez véd a későbbi vitáktól.</span>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="megjegyzes">Megjegyzés</label>
                    <textarea name="megjegyzes" id="megjegyzes"><?php
                        echo esc_textarea($ert('megjegyzes'));
                    ?></textarea>
                </div>
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
            'tartozekok'       => $szoveg('tartozekok'),
            'atveteli_allapot' => isset($_POST['atveteli_allapot'])
                ? sanitize_textarea_field(wp_unslash($_POST['atveteli_allapot']))
                : '',
            'garancias'        => !empty($_POST['garancias']) ? 1 : 0,
            'vasarlas_datuma'  => self::datum_vagy_null($szoveg('vasarlas_datuma')),
            'garancia_lejar'   => self::datum_vagy_null($szoveg('garancia_lejar')),
            'megjegyzes'       => isset($_POST['megjegyzes'])
                ? sanitize_textarea_field(wp_unslash($_POST['megjegyzes']))
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

        $eredmeny = self::adatbazisba($id, $adatok);

        if ($eredmeny === null) {
            wp_safe_redirect(self::vissza(['uzenet' => 'mentes_hiba']));
            exit;
        }

        [$uj_id, $uzenet] = $eredmeny;

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

        $eredmeny = self::adatbazisba($id, $adatok);

        if ($eredmeny === null) {
            wp_send_json_error(['uzenet' => 'Az adatbázis visszautasította a mentést.']);
        }

        [$uj_id, $uzenet] = $eredmeny;

        wp_send_json_success([
            'id'     => $uj_id,
            'vissza' => self::vissza(['uzenet' => $uzenet]),
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

    /**
     * @return array<string, string>
     */
    public static function kategoriak(): array
    {
        return [
            'telefon'  => 'Telefon',
            'tablet'   => 'Tablet',
            'laptop'   => 'Laptop',
            'ora'      => 'Okosóra',
            'asztali'  => 'Asztali gép',
            'egyeb'    => 'Egyéb',
        ];
    }

    private static function kategoria_ervenyes(string $kategoria): string
    {
        return isset(self::kategoriak()[$kategoria]) ? $kategoria : 'telefon';
    }

    public static function kategoria_cimke(string $kategoria): string
    {
        return self::kategoriak()[$kategoria] ?? $kategoria;
    }

    /**
     * Gépelési segítség, nem korlátozás – bármi beírható.
     *
     * @return array<int, string>
     */
    private static function gyakori_gyartok(): array
    {
        return ['Apple', 'Samsung', 'Xiaomi', 'Huawei', 'Motorola', 'OnePlus', 'Nokia', 'Honor', 'Lenovo', 'Asus'];
    }
}
