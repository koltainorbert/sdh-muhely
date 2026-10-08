<?php
/**
 * Beállítások.
 *
 * Két dolgot kezel: a munkalap-modul beállításait (számozás, állapotok)
 * és a külső IMEI-szolgáltatót. Azért van saját képernyője, mert a rendszert később más szervizeknek is el
 * akarjátok adni – ott más szolgáltató és más kulcs lesz, és semmi sem
 * lehet beégetve a kódba.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Beallitasok
{
    public const KULCS = 'beallitasok';

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'      => self::KULCS,
            'cim'        => 'Beállítások',
            'render'     => [self::class, 'oldal'],
            'sorrend'    => 95,
            'csak_admin' => true,
        ]);

        add_action('admin_post_sdh_muhely_beallitasok', [self::class, 'mentes']);
    }

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        $b = SDH_Muhely_Imei_Lekerdezes::beallitas();

        $ellenorzok = get_option('sdh_muhely_imei_ellenorzok', '');

        if (!is_string($ellenorzok) || trim($ellenorzok) === '') {
            $ellenorzok = SDH_Muhely_Imei_Lekerdezes::alap_ellenorzok_szovegkent();
        }

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Beállítások',
                'Szerviz-specifikus adatok. Semmi nincs beégetve a kódba.'
            );
            ?>

            <form class="sdh-urlap" method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_beallitasok">
                <?php wp_nonce_field('sdh_muhely_beallitasok', 'sdh_nonce'); ?>

                <?php SDH_Muhely_Munkalap::beallitas_dobozok(); ?>

                <div class="sdh-doboz">
                    <h2 class="sdh-doboz__cim">IMEI-szolgáltató</h2>

                    <p class="sdh-sugo">
                        A gyári szám, a sorozatszám, a garancia és a gyártási dátum a gyártó
                        szerveréről jön – ezt fizetős szolgáltatón keresztül lehet lekérdezni
                        (imeicheck.com, imeicheck.net, sickw és társaik). Írd be a végpont
                        címét: az <code>{imei}</code> és a <code>{kulcs}</code> helyére a
                        rendszer behelyettesít. Ha a szolgáltató fejlécben várja a kulcsot,
                        azt is elküldjük <code>Authorization: Bearer</code> formában.
                    </p>

                    <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--also-ter">
                        <input type="checkbox" name="aktiv" id="aktiv" value="1"
                            <?php checked($b['aktiv']); ?>>
                        <label for="aktiv">Lekérdezés bekapcsolva</label>
                    </div>

                    <div class="sdh-mezok">
                        <div class="sdh-mezo sdh-mezo--szeles">
                            <label for="url">Végpont címe</label>
                            <input type="text" name="url" id="url"
                                   value="<?php echo esc_attr($b['url']); ?>"
                                   placeholder="https://pelda.hu/api/check?key={kulcs}&imei={imei}">
                            <span class="sdh-mezo__sugo">
                                A szolgáltatód dokumentációjából másold ide, a kulcsot és az
                                IMEI-t helyettesítve a kapcsos zárójeles jelölőkkel.
                            </span>
                        </div>

                        <div class="sdh-mezo sdh-mezo--szeles">
                            <label for="kulcs">API-kulcs</label>
                            <input type="text" name="kulcs" id="kulcs"
                                   value="<?php echo esc_attr($b['kulcs']); ?>"
                                   autocomplete="off">
                        </div>
                    </div>
                </div>

                <div class="sdh-doboz">
                    <h2 class="sdh-doboz__cim">Ingyenes ellenőrző oldalak</h2>

                    <p class="sdh-sugo">
                        Ezek a gombok jelennek meg az eszköz-űrlap tetején. Soronként egy:
                        <code>Név | URL</code>. A gomb megnyitja az oldalt, és az IMEI-t a
                        vágólapra teszi, hogy ott csak be kelljen illeszteni. Ha egy oldal
                        az URL-ben is fogadja az IMEI-t, írd a címbe az <code>{imei}</code>
                        jelölőt – akkor már kitöltve nyílik meg.
                    </p>

                    <div class="sdh-mezo sdh-mezo--szeles">
                        <label for="ellenorzok">Oldalak</label>
                        <textarea name="ellenorzok" id="ellenorzok" rows="4"
                                  class="sdh-kod"
                        ><?php echo esc_textarea($ellenorzok); ?></textarea>
                        <span class="sdh-mezo__sugo">
                            Üresen hagyva az alapértelmezett lista jön vissza.
                        </span>
                    </div>
                </div>

                <div class="sdh-urlap__lablec">
                    <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Mentés</button>
                </div>
            </form>

            <div class="sdh-doboz">
                <h2 class="sdh-doboz__cim">Ami enélkül is megy</h2>
                <p class="sdh-sugo sdh-sugo--utolso">
                    Szolgáltató nélkül a rendszer a saját nyilvántartásából és a helyi
                    TAC-adatbázisból tölti ki a gyártót, a gyári számot és a kereskedelmi
                    nevet. A sorozatszámot, a garanciát és a gyártási dátumot kézzel kell
                    beírni. Ez az egyetlen pont, ami internetet igényel – ha nincs net, a
                    felvitel attól még megy.
                </p>
            </div>
        </div>
        <?php
    }

    public static function mentes(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_beallitasok', 'sdh_nonce');

        update_option(
            'sdh_muhely_imei_szolgaltato',
            [
                'url'   => isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '',
                'kulcs' => isset($_POST['kulcs']) ? sanitize_text_field(wp_unslash($_POST['kulcs'])) : '',
                'aktiv' => !empty($_POST['aktiv']),
            ]
        );

        update_option(
            'sdh_muhely_imei_ellenorzok',
            isset($_POST['ellenorzok'])
                ? sanitize_textarea_field(wp_unslash($_POST['ellenorzok']))
                : ''
        );

        SDH_Muhely_Munkalap::beallitas_mentes();

        wp_safe_redirect(SDH_Muhely_Modulok::admin_url(self::KULCS, ['uzenet' => 'mentve']));
        exit;
    }
}
