<?php
/**
 * Ügyfél-modul.
 *
 * Ügyfelek listája kereséssel és lapozással, felvitel és szerkesztés
 * popupban, inaktiválás törlés helyett.
 *
 * Ugyanez a kód fut a saját műhely-felületen és a wp-adminban is. A modul
 * nem tudja, melyiken van – minden URL-t a SDH_Muhely_Modulok épít, az
 * pedig ismeri a kontextust.
 *
 * A felvitel popupban történik, hogy ne veszítsd el a listát és a
 * keresést. A teljes oldalas űrlap megmarad tartalékként: ha a
 * JavaScript nem fut, a linkek oda visznek, és a rendszer működik tovább.
 *
 * Miért nincs törlés: egy ügyfélre később munkalapok hivatkoznak. Ha az
 * ügyfél eltűnne, a munkalap története értelmezhetetlen lenne. Ezért az
 * ügyfél inaktívvá válik – kikerül a napi listából, de a régi munkalapok
 * mellett megmarad.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Ugyfel
{
    /** A modul kulcsa az URL-ekben, a menüben és az AJAX-műveletekben. */
    public const KULCS = 'ugyfelek';

    /** Hány sor egy oldalon. */
    private const OLDAL_MERET = 25;

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Ügyfelek',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 10,
        ]);

        // Teljes oldalas űrlap beküldése (tartalék, JS nélkül is működik).
        add_action('admin_post_sdh_muhely_ugyfel_mentes', [self::class, 'mentes']);
        add_action('admin_post_sdh_muhely_ugyfel_allapot', [self::class, 'allapot_valtas']);

        // Popup: az űrlap lekérése és beküldése.
        add_action('wp_ajax_sdh_muhely_ugyfelek_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_ugyfelek_ment', [self::class, 'ajax_mentes']);

        // Más modulok ügyfélválasztó mezője ebből él.
        add_action('wp_ajax_sdh_muhely_ugyfelek_kereso', [self::class, 'ajax_kereso']);
    }

    /* =================================================================
     * Ügyfélválasztó – más modulok használják
     * ============================================================== */

    /**
     * Gépelésre kereső ügyfélmező.
     *
     * Legördülő helyett azért, mert az átvett állományban közel 40 ezer
     * ügyfél lesz: egy <select> ennyi elemmel használhatatlan, és a
     * böngészőt is megfogná.
     */
    public static function valaszto_mezo(int $ugyfel_id = 0, string $mezo_nev = 'ugyfel_id'): void
    {
        $ugyfel = $ugyfel_id > 0 ? self::egy($ugyfel_id) : null;

        ?>
        <div class="sdh-valaszto" data-sdh-valaszto="ugyfelek">
            <input type="hidden" name="<?php echo esc_attr($mezo_nev); ?>"
                   value="<?php echo (int) $ugyfel_id; ?>">
            <input type="text" class="sdh-valaszto__mezo" autocomplete="off"
                   placeholder="Kezdd el írni a nevet, telefont, ügyfélszámot…"
                   value="<?php echo esc_attr($ugyfel !== null ? $ugyfel->nev : ''); ?>">
            <ul class="sdh-valaszto__lista" hidden></ul>
        </div>
        <?php
    }

    /**
     * Az ügyfélválasztó mögötti kereső.
     *
     * Csak aktív ügyfeleket ad vissza, és legfeljebb tízet: a lista
     * nem böngészésre való, hanem arra, hogy gépelés közben megtaláld
     * a megfelelőt.
     */
    public static function ajax_kereso(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        global $wpdb;

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

        if (mb_strlen($q) < 2) {
            wp_send_json_success([]);
        }

        $minta = '%' . $wpdb->esc_like($q) . '%';
        $tabla = self::tabla();

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, nev, telefon, szamlazasi_telepules, ugyfel_szam
                 FROM {$tabla}
                 WHERE aktiv = 1
                   AND (nev LIKE %s OR telefon LIKE %s OR telefon2 LIKE %s
                        OR email LIKE %s OR ugyfel_szam LIKE %s)
                 ORDER BY nev ASC
                 LIMIT 10",
                $minta,
                $minta,
                $minta,
                $minta,
                $minta
            )
        );

        $talalatok = [];

        foreach ($sorok as $sor) {
            $reszletek = array_filter([
                $sor->telefon,
                $sor->szamlazasi_telepules,
                $sor->ugyfel_szam,
            ]);

            $talalatok[] = [
                'id'       => (int) $sor->id,
                'nev'      => $sor->nev,
                'reszlet'  => implode(' · ', $reszletek),
            ];
        }

        wp_send_json_success($talalatok);
    }

    private static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('ugyfel');
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
     * Egy ügyfél neve az azonosítója alapján, vagy üres sztring, ha
     * nincs ilyen. Más modulok ezzel ellenőrzik, hogy a kapott
     * azonosító valódi ügyfélre mutat-e.
     */
    public static function nev(int $id): string
    {
        global $wpdb;

        if ($id <= 0) {
            return '';
        }

        $nev = $wpdb->get_var(
            $wpdb->prepare('SELECT nev FROM ' . self::tabla() . ' WHERE id = %d', $id)
        );

        return is_string($nev) ? $nev : '';
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

        $kereses = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
        $inaktiv = !empty($_GET['inaktiv']);
        $oldal   = isset($_GET['oldalszam']) ? max(1, (int) $_GET['oldalszam']) : 1;
        $eltolas = ($oldal - 1) * self::OLDAL_MERET;

        $tabla = self::tabla();

        // A feltételeket külön építjük, hogy a számláló és a lekérdezés
        // ugyanazt lássa.
        $feltetelek = ['aktiv = %d'];
        $ertekek    = [$inaktiv ? 0 : 1];

        if ($kereses !== '') {
            $minta = '%' . $wpdb->esc_like($kereses) . '%';

            $feltetelek[] = '(nev LIKE %s OR telefon LIKE %s OR telefon2 LIKE %s OR email LIKE %s '
                . 'OR ugyfel_szam LIKE %s OR szamlazasi_telepules LIKE %s)';

            array_push($ertekek, $minta, $minta, $minta, $minta, $minta, $minta);
        }

        $hol = 'WHERE ' . implode(' AND ', $feltetelek);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- a $hol csak helyőrzőket tartalmaz.
        $osszesen = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$tabla} {$hol}", $ertekek)
        );

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tabla} {$hol} ORDER BY nev ASC LIMIT %d OFFSET %d",
                array_merge($ertekek, [self::OLDAL_MERET, $eltolas])
            )
        );
        // phpcs:enable

        $oldalak = max(1, (int) ceil($osszesen / self::OLDAL_MERET));

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Ügyfelek',
                'Magánszemélyek és cégek egy helyen. A munkalap mindig egy ügyfélre hivatkozik.',
                [
                    [
                        'cimke'      => '+ Új ügyfél',
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

                <?php if ($inaktiv) : ?>
                    <input type="hidden" name="inaktiv" value="1">
                <?php endif; ?>

                <input type="search"
                       name="k"
                       value="<?php echo esc_attr($kereses); ?>"
                       placeholder="Név, telefon, e-mail, ügyfélszám, település…">

                <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Keresés</button>

                <?php if ($kereses !== '') : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos"
                       href="<?php echo esc_url(self::url($inaktiv ? ['inaktiv' => 1] : [])); ?>">
                        Szűrő törlése
                    </a>
                <?php endif; ?>

                <a class="sdh-gomb sdh-gomb--vilagos"
                   href="<?php echo esc_url(self::url($inaktiv ? [] : ['inaktiv' => 1])); ?>">
                    <?php echo $inaktiv ? 'Aktív ügyfelek' : 'Inaktívak'; ?>
                </a>

                <span class="sdh-kereso__talalat">
                    <?php echo esc_html(number_format_i18n($osszesen)); ?> találat
                </span>
            </form>

            <table class="sdh-tabla">
                <thead>
                    <tr>
                        <th>Név</th>
                        <th>Típus</th>
                        <th>Telefon</th>
                        <th class="sdh-tabla__rejtheto">E-mail</th>
                        <th class="sdh-tabla__rejtheto">Település</th>
                        <th>Ügyfélszám</th>
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
                                Még nincs <?php echo $inaktiv ? 'inaktív' : ''; ?> ügyfél.
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
                            <td class="sdh-tabla__nev">
                                <a href="<?php echo esc_url($szerkeszt_url); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="<?php echo (int) $sor->id; ?>">
                                    <?php echo esc_html($sor->nev); ?>
                                </a>
                                <?php if ((int) $sor->aktiv === 0) : ?>
                                    <span class="sdh-cimke sdh-cimke--inaktiv">inaktív</span>
                                <?php endif; ?>
                                <?php if ($sor->kapcsolattarto !== '') : ?>
                                    <div class="sdh-tabla__halvany">
                                        <?php echo esc_html($sor->kapcsolattarto); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="sdh-cimke">
                                    <?php echo esc_html(self::tipus_cimke($sor->tipus)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($sor->telefon); ?></td>
                            <td class="sdh-tabla__rejtheto"><?php echo esc_html($sor->email); ?></td>
                            <td class="sdh-tabla__rejtheto">
                                <?php echo esc_html($sor->szamlazasi_telepules); ?>
                            </td>
                            <td class="sdh-tabla__halvany"><?php echo esc_html($sor->ugyfel_szam); ?></td>
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
                                        'inaktiv'   => $inaktiv ? 1 : null,
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
        $ugyfel = null;

        if ($nezet === 'szerkeszt') {
            $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            $ugyfel = self::egy($id);

            if ($ugyfel === null) {
                wp_safe_redirect(self::url(['uzenet' => 'nincs_ilyen']));
                exit;
            }
        }

        $uj = $ugyfel === null;

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                $uj ? 'Új ügyfél' : $ugyfel->nev,
                $uj
                    ? 'Csak a név kötelező. A többi mezőt bármikor pótolhatod.'
                    : 'Ügyfélszám: ' . ($ugyfel->ugyfel_szam !== '' ? $ugyfel->ugyfel_szam : '—'),
                [
                    [
                        'cimke' => '← Vissza a listához',
                        'url'   => self::url(),
                    ],
                ]
            );
            ?>

            <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_ugyfel_mentes">
                <?php self::urlap_belso($ugyfel, false); ?>
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
        $ugyfel = $id > 0 ? self::egy($id) : null;

        if ($id > 0 && $ugyfel === null) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs ilyen ügyfél.</div>';
            wp_die();
        }

        $uj = $ugyfel === null;

        ?>
        <h2 class="sdh-modal__cim"><?php echo esc_html($uj ? 'Új ügyfél' : $ugyfel->nev); ?></h2>
        <p class="sdh-modal__alcim">
            <?php
            echo esc_html(
                $uj
                    ? 'Csak a név kötelező. A többi mezőt bármikor pótolhatod.'
                    : 'Ügyfélszám: ' . ($ugyfel->ugyfel_szam !== '' ? $ugyfel->ugyfel_szam : '—')
            );
            ?>
        </p>

        <form class="sdh-urlap" method="post"
              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-sdh-ajax-action="sdh_muhely_ugyfelek_ment">
            <input type="hidden" name="action" value="sdh_muhely_ugyfel_mentes">
            <?php self::urlap_belso($ugyfel, true); ?>
        </form>
        <?php

        wp_die();
    }

    /**
     * Az űrlap belseje: rejtett mezők, dobozok, lábléc.
     *
     * Ugyanez megy a teljes oldalas és a popupos változatba – egy helyen
     * írjuk meg, hogy ne csússzon szét a kettő.
     */
    private static function urlap_belso(?object $ugyfel, bool $modal): void
    {
        // Minden mezőnek van alapértéke, hogy az űrlap új és meglévő
        // ügyfélnél ugyanazt a kódot használhassa.
        $ert = static fn (string $mezo, string $alap = ''): string => $ugyfel !== null
            ? (string) $ugyfel->{$mezo}
            : $alap;

        $uj = $ugyfel === null;

        ?>
        <input type="hidden" name="id" value="<?php echo (int) ($ugyfel->id ?? 0); ?>">
        <input type="hidden" name="kontextus"
               value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
        <?php wp_nonce_field('sdh_muhely_ugyfel_mentes', 'sdh_nonce'); ?>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Alapadatok</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="tipus">Típus</label>
                    <select name="tipus" id="tipus">
                        <?php foreach (self::tipusok() as $kulcs => $cimke) : ?>
                            <option value="<?php echo esc_attr($kulcs); ?>"
                                <?php selected($ert('tipus', 'maganszemely'), $kulcs); ?>>
                                <?php echo esc_html($cimke); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sdh-mezo">
                    <label for="nev">Név <span class="sdh-kotelezo">*</span></label>
                    <input type="text" name="nev" id="nev" required
                           value="<?php echo esc_attr($ert('nev')); ?>">
                    <span class="sdh-mezo__sugo">Cégnél a cég neve, magánszemélynél a teljes név.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="kapcsolattarto">Kapcsolattartó</label>
                    <input type="text" name="kapcsolattarto" id="kapcsolattarto"
                           value="<?php echo esc_attr($ert('kapcsolattarto')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="adoszam">Adószám</label>
                    <input type="text" name="adoszam" id="adoszam"
                           value="<?php echo esc_attr($ert('adoszam')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="telefon">Telefon</label>
                    <input type="tel" name="telefon" id="telefon"
                           value="<?php echo esc_attr($ert('telefon')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="telefon2">Telefon 2.</label>
                    <input type="tel" name="telefon2" id="telefon2"
                           value="<?php echo esc_attr($ert('telefon2')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="email">E-mail</label>
                    <input type="email" name="email" id="email"
                           value="<?php echo esc_attr($ert('email')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="ugyfel_szam">Ügyfélszám</label>
                    <input type="text" name="ugyfel_szam" id="ugyfel_szam"
                           value="<?php echo esc_attr($ert('ugyfel_szam')); ?>">
                    <span class="sdh-mezo__sugo">Üresen hagyva a mentéskor generálódik.</span>
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Számlázási cím</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="szamlazasi_iranyitoszam">Irányítószám</label>
                    <input type="text" name="szamlazasi_iranyitoszam" id="szamlazasi_iranyitoszam"
                           value="<?php echo esc_attr($ert('szamlazasi_iranyitoszam')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="szamlazasi_telepules">Település</label>
                    <input type="text" name="szamlazasi_telepules" id="szamlazasi_telepules"
                           value="<?php echo esc_attr($ert('szamlazasi_telepules')); ?>">
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="szamlazasi_cim">Utca, házszám</label>
                    <input type="text" name="szamlazasi_cim" id="szamlazasi_cim"
                           value="<?php echo esc_attr($ert('szamlazasi_cim')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="szamlazasi_orszag">Ország</label>
                    <input type="text" name="szamlazasi_orszag" id="szamlazasi_orszag"
                           value="<?php echo esc_attr($ert('szamlazasi_orszag', 'Magyarország')); ?>">
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Levelezési cím</h2>

            <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--also-ter">
                <input type="checkbox" name="levelezesi_azonos" id="levelezesi_azonos" value="1"
                    <?php checked($uj ? '1' : $ert('levelezesi_azonos'), '1'); ?>>
                <label for="levelezesi_azonos">Megegyezik a számlázási címmel</label>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="levelezesi_iranyitoszam">Irányítószám</label>
                    <input type="text" name="levelezesi_iranyitoszam" id="levelezesi_iranyitoszam"
                           value="<?php echo esc_attr($ert('levelezesi_iranyitoszam')); ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="levelezesi_telepules">Település</label>
                    <input type="text" name="levelezesi_telepules" id="levelezesi_telepules"
                           value="<?php echo esc_attr($ert('levelezesi_telepules')); ?>">
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="levelezesi_cim">Utca, házszám</label>
                    <input type="text" name="levelezesi_cim" id="levelezesi_cim"
                           value="<?php echo esc_attr($ert('levelezesi_cim')); ?>">
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Besorolás és megjegyzés</h2>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="kategoria">Kategória</label>
                    <input type="text" name="kategoria" id="kategoria"
                           value="<?php echo esc_attr($ert('kategoria')); ?>">
                    <span class="sdh-mezo__sugo">Pl. viszonteladó, szerződéses partner.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="kedvezmeny">Kedvezmény (%)</label>
                    <input type="number" name="kedvezmeny" id="kedvezmeny"
                           step="0.01" min="0" max="100"
                           value="<?php echo esc_attr($ert('kedvezmeny', '0')); ?>">
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
                <?php echo $uj ? 'Ügyfél létrehozása' : 'Mentés'; ?>
            </button>

            <?php if ($modal) : ?>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            <?php else : ?>
                <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(self::url()); ?>">Mégsem</a>
            <?php endif; ?>

            <?php if (!$uj) : ?>
                <a class="sdh-gomb sdh-gomb--vilagos"
                   href="<?php echo esc_url(
                       SDH_Muhely_Modulok::url('eszkozok', ['ugyfel_id' => (int) $ugyfel->id])
                   ); ?>">
                    Készülékei
                </a>

                <a class="sdh-gomb sdh-gomb--vilagos"
                   href="<?php echo esc_url(
                       SDH_Muhely_Modulok::url('munkalapok', ['ugyfel_id' => (int) $ugyfel->id, 'allapot' => 'mind'])
                   ); ?>">
                    Munkalapjai
                </a>

                <?php
                $allapot_url = wp_nonce_url(
                    add_query_arg(
                        [
                            'action'    => 'sdh_muhely_ugyfel_allapot',
                            'id'        => (int) $ugyfel->id,
                            'ertek'     => (int) $ugyfel->aktiv === 1 ? 0 : 1,
                            'kontextus' => self::kontextus_ertek(),
                        ],
                        admin_url('admin-post.php')
                    ),
                    'sdh_muhely_ugyfel_allapot_' . (int) $ugyfel->id
                );
                ?>
                <a class="sdh-gomb sdh-gomb--vilagos sdh-gomb--jobbra"
                   href="<?php echo esc_url($allapot_url); ?>">
                    <?php echo (int) $ugyfel->aktiv === 1 ? 'Inaktívra állít' : 'Újra aktív'; ?>
                </a>
            <?php endif; ?>
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

    /**
     * Honnan jött a beküldés – oda is megy vissza.
     */
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
    private static function adatok_osszeallit(): array
    {
        $szoveg = static fn (string $mezo): string => isset($_POST[$mezo])
            ? sanitize_text_field(wp_unslash($_POST[$mezo]))
            : '';

        $levelezesi_azonos = !empty($_POST['levelezesi_azonos']) ? 1 : 0;

        $adatok = [
            'tipus'                   => self::tipus_ervenyes($szoveg('tipus')),
            'nev'                     => $szoveg('nev'),
            'kapcsolattarto'          => $szoveg('kapcsolattarto'),
            'adoszam'                 => $szoveg('adoszam'),
            'telefon'                 => $szoveg('telefon'),
            'telefon2'                => $szoveg('telefon2'),
            'email'                   => isset($_POST['email'])
                ? sanitize_email(wp_unslash($_POST['email']))
                : '',
            'szamlazasi_iranyitoszam' => $szoveg('szamlazasi_iranyitoszam'),
            'szamlazasi_telepules'    => $szoveg('szamlazasi_telepules'),
            'szamlazasi_cim'          => $szoveg('szamlazasi_cim'),
            'szamlazasi_orszag'       => $szoveg('szamlazasi_orszag'),
            'levelezesi_azonos'       => $levelezesi_azonos,
            'kategoria'               => $szoveg('kategoria'),
            'kedvezmeny'              => isset($_POST['kedvezmeny'])
                ? min(100, max(0, (float) wp_unslash($_POST['kedvezmeny'])))
                : 0,
            'megjegyzes'              => isset($_POST['megjegyzes'])
                ? sanitize_textarea_field(wp_unslash($_POST['megjegyzes']))
                : '',
            'ugyfel_szam'             => $szoveg('ugyfel_szam'),
            'modositva'               => current_time('mysql'),
        ];

        // Ha a levelezési cím megegyezik a számlázásival, nem tárolunk
        // két külön igazságot – a másolat idővel szétcsúszna.
        if ($levelezesi_azonos === 1) {
            $adatok['levelezesi_iranyitoszam'] = '';
            $adatok['levelezesi_telepules']    = '';
            $adatok['levelezesi_cim']          = '';
        } else {
            $adatok['levelezesi_iranyitoszam'] = $szoveg('levelezesi_iranyitoszam');
            $adatok['levelezesi_telepules']    = $szoveg('levelezesi_telepules');
            $adatok['levelezesi_cim']          = $szoveg('levelezesi_cim');
        }

        return $adatok;
    }

    /**
     * Beírja az adatbázisba. Visszatérés: [azonosító, üzenetkulcs] vagy null hibánál.
     *
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
            $uzenet   = 'letrehozva';
        }

        if ($eredmeny === false) {
            return null;
        }

        // Ügyfélszám pótlása, ha a felhasználó nem adott meg sajátot.
        if ($id > 0 && ($adatok['ugyfel_szam'] ?? '') === '') {
            $wpdb->update(
                self::tabla(),
                ['ugyfel_szam' => sprintf('U-%06d', $id)],
                ['id' => $id]
            );
        }

        return [$id, $uzenet];
    }

    /** Teljes oldalas beküldés (JS nélküli tartalék). */
    public static function mentes(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_ugyfel_mentes', 'sdh_nonce');

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adatok = self::adatok_osszeallit();

        if ($adatok['nev'] === '') {
            wp_safe_redirect(
                self::vissza(
                    array_filter([
                        'nezet'  => $id > 0 ? 'szerkeszt' : 'uj',
                        'id'     => $id > 0 ? $id : null,
                        'uzenet' => 'hianyzo_nev',
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

        wp_safe_redirect(
            self::vissza(['nezet' => 'szerkeszt', 'id' => $uj_id, 'uzenet' => $uzenet])
        );
        exit;
    }

    /** Popupos beküldés. */
    public static function ajax_mentes(): void
    {
        check_ajax_referer('sdh_muhely_ugyfel_mentes', 'sdh_nonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adatok = self::adatok_osszeallit();

        if ($adatok['nev'] === '') {
            wp_send_json_error(['uzenet' => 'A név kitöltése kötelező.']);
        }

        $eredmeny = self::adatbazisba($id, $adatok);

        if ($eredmeny === null) {
            wp_send_json_error(['uzenet' => 'Az adatbázis visszautasította a mentést.']);
        }

        [$uj_id, $uzenet] = $eredmeny;

        // A lista oldalára térünk vissza, hogy a változás rögtön látszódjon.
        wp_send_json_success([
            'id'     => $uj_id,
            'vissza' => self::vissza(['uzenet' => $uzenet]),
        ]);
    }

    /* =================================================================
     * Aktív / inaktív
     * ============================================================== */

    public static function allapot_valtas(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        global $wpdb;

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        check_admin_referer('sdh_muhely_ugyfel_allapot_' . $id);

        $ertek = isset($_GET['ertek']) && (int) $_GET['ertek'] === 1 ? 1 : 0;

        $wpdb->update(
            self::tabla(),
            ['aktiv' => $ertek, 'modositva' => current_time('mysql')],
            ['id' => $id]
        );

        wp_safe_redirect(
            self::vissza([
                'uzenet' => $ertek === 1 ? 'aktivalva' : 'inaktivalva',
            ])
        );
        exit;
    }

    /* =================================================================
     * Típusok
     * ============================================================== */

    /**
     * @return array<string, string>
     */
    public static function tipusok(): array
    {
        return [
            'maganszemely' => 'Magánszemély',
            'ceg'          => 'Cég',
            'kozulet'      => 'Közület',
        ];
    }

    private static function tipus_ervenyes(string $tipus): string
    {
        return isset(self::tipusok()[$tipus]) ? $tipus : 'maganszemely';
    }

    public static function tipus_cimke(string $tipus): string
    {
        return self::tipusok()[$tipus] ?? $tipus;
    }
}
