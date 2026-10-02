<?php
/**
 * TAC – készüléktípus-adatbázis.
 *
 * Az IMEI első nyolc számjegye a TAC (Type Allocation Code): ez
 * azonosítja a készüléktípust. Ha ismerjük, akkor egy soha nem látott
 * telefonnál is ki tudjuk tölteni a gyártót és a típust pusztán az
 * IMEI-ből.
 *
 * Az adat helyben van, nem online szolgáltatásból jön. Ennek oka nem a
 * költség: a rendszernek internet nélkül, az irodai szerveren is mennie
 * kell, és egy külső API az első hálózatkimaradáskor megállítaná a
 * pultot. A plugin mellé csomagolt data/tac.csv.gz a nyilvános,
 * MIT-licencű TAC-adatbázisból készült (248 ezer készüléktípus).
 *
 * Az importálás darabokban fut, mert negyedmillió sort egyetlen
 * kérésben beírni időtúllépéshez vezetne.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Tac
{
    public const KULCS = 'tac';

    /** Hány sort dolgozunk fel egy kérésben. */
    private const ADAG = 20000;

    /** Hány soros darabokban megy az INSERT. */
    private const INSERT_DARAB = 500;

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'      => self::KULCS,
            'cim'        => 'TAC adatbázis',
            'render'     => [self::class, 'oldal'],
            'sorrend'    => 90,
            'csak_admin' => true,
        ]);

        add_action('wp_ajax_sdh_muhely_tac_import', [self::class, 'ajax_import']);
    }

    private static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('tac');
    }

    private static function fajl(): string
    {
        return SDH_MUHELY_DIR . 'data/tac.csv.gz';
    }

    /* =================================================================
     * Kikeresés
     * ============================================================== */

    /**
     * Egy IMEI (vagy TAC) alapján a készüléktípus.
     *
     * @return array{gyarto: string, modell: string, megnevezes: string}|null
     */
    public static function keres(string $imei_vagy_tac): ?array
    {
        global $wpdb;

        $tac = substr(preg_replace('/\D/', '', $imei_vagy_tac) ?? '', 0, 8);

        if (strlen($tac) !== 8) {
            return null;
        }

        $sor = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT gyarto, modell, megnevezes FROM ' . self::tabla() . ' WHERE tac = %s',
                $tac
            )
        );

        if ($sor === null) {
            return null;
        }

        return [
            'gyarto'     => (string) $sor->gyarto,
            'modell'     => (string) $sor->modell,
            'megnevezes' => (string) $sor->megnevezes,
        ];
    }

    public static function darabszam(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::tabla());
    }

    public static function sajat_darabszam(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::tabla() . " WHERE forras = 'sajat'"
        );
    }

    /* =================================================================
     * Tanulás
     * ============================================================== */

    /**
     * Megjegyzi egy új készüléktípus TAC-ját a saját felvitelből.
     *
     * A csomagolt lista 2025 végéig tart, tehát a 2026-os és későbbi
     * készülékek nincsenek benne. Ezt nem úgy oldjuk meg, hogy
     * negyedévente fájlt cserélünk: amikor egy ismeretlen TAC-ú
     * készüléket kézzel felvisznek, a gyártó és a típus bekerül ide.
     *
     * Apple IRP és Samsung hivatalos szervizként az új típusok amúgy is
     * elsőként nálatok fordulnak meg – így az adatbázis magától nő, és
     * internet nélkül is naprakész marad.
     *
     * A saját bejegyzést a csomagolt lista újratöltése nem írja felül.
     */
    public static function tanul(string $imei, string $gyarto, string $modell, string $megnevezes = ''): void
    {
        global $wpdb;

        $tac = substr(preg_replace('/\\D/', '', $imei) ?? '', 0, 8);

        // Gyári szám nélkül nem érdemes tanulni: abból nem lesz kitöltés.
        if (strlen($tac) !== 8 || trim($modell) === '' || trim($gyarto) === '') {
            return;
        }

        $meglevo = $wpdb->get_row(
            $wpdb->prepare('SELECT modell, forras FROM ' . self::tabla() . ' WHERE tac = %s', $tac)
        );

        // Ha már ismerjük a gyári számát, nem bántjuk.
        if ($meglevo !== null && trim((string) $meglevo->modell) !== '') {
            return;
        }

        $adatok = [
            'tac'        => $tac,
            'gyarto'     => mb_substr(trim($gyarto), 0, 80),
            'modell'     => mb_substr(trim($modell), 0, 60),
            'megnevezes' => mb_substr(trim($megnevezes), 0, 120),
            'forras'     => 'sajat',
            'frissitve'  => current_time('mysql'),
        ];

        if ($meglevo === null) {
            $wpdb->insert(self::tabla(), $adatok);

            return;
        }

        $wpdb->update(self::tabla(), $adatok, ['tac' => $tac]);
    }

    /* =================================================================
     * Admin képernyő
     * ============================================================== */

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        $darab    = self::darabszam();
        $sajat    = self::sajat_darabszam();
        $van_fajl = is_readable(self::fajl());

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::fejlec(
                'TAC adatbázis',
                'Az IMEI első nyolc számjegyéből ez mondja meg a gyártót és a típust.'
            );
            ?>

            <div class="sdh-doboz">
                <h2 class="sdh-doboz__cim">Állapot</h2>

                <table class="sdh-tabla sdh-tabla--keskeny" style="margin-bottom:1.2rem">
                    <tbody>
                        <tr>
                            <th>Betöltött készüléktípus</th>
                            <td><code id="sdh-tac-darab"><?php
                                echo esc_html(number_format_i18n($darab));
                            ?></code></td>
                        </tr>
                        <tr>
                            <th>Saját felvitelből tanult</th>
                            <td><code><?php echo esc_html(number_format_i18n($sajat)); ?></code></td>
                        </tr>
                        <tr>
                            <th>Forrásfájl</th>
                            <td><code><?php echo esc_html($van_fajl ? 'data/tac.csv.gz' : 'hiányzik'); ?></code></td>
                        </tr>
                    </tbody>
                </table>

                <?php if (!$van_fajl) : ?>
                    <div class="sdh-uzenet sdh-uzenet--hiba">
                        A <code>data/tac.csv.gz</code> fájl nincs a plugin mappájában, így nincs mit betölteni.
                    </div>
                <?php else : ?>
                    <p style="margin:0 0 1rem;font-size:13px;color:var(--sdh-halvany);line-height:1.6;max-width:62em">
                        A betöltés darabokban fut, és eltart egy percig. Nyugodtan megszakíthatod:
                        a már beírt sorok megmaradnak, és újraindításkor onnan folytatja.
                        Ismételt futtatás nem csinál duplikátumot – a meglévő sorokat frissíti.
                    </p>

                    <div class="sdh-urlap__lablec">
                        <button type="button" class="sdh-gomb sdh-gomb--elsodleges" id="sdh-tac-indit">
                            <?php echo $darab > 0 ? 'Újratöltés' : 'Betöltés indítása'; ?>
                        </button>
                        <span id="sdh-tac-allapot" style="font-size:13px;color:var(--sdh-halvany)"></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="sdh-doboz">
                <h2 class="sdh-doboz__cim">Honnan jön az adat</h2>
                <p style="margin:0 0 .9rem;font-size:13px;line-height:1.7;color:#3c434a;max-width:62em">
                    A csomagolt fájl a nyilvános, MIT-licencű TAC-adatbázisból készült, és
                    <strong>2025 végéig tart</strong> – a 2026-os és későbbi típusok nincsenek benne.
                    A TAC-ok nagyjából felénél van meg a gyári szám (modellkód); a többinél
                    csak a gyártó és a kereskedelmi név.
                </p>
                <p style="margin:0;font-size:13px;line-height:1.7;color:#3c434a;max-width:62em">
                    Ezt nem fájlcserével oldjuk meg: amikor egy ismeretlen TAC-ú készüléket
                    kézzel felvisztek, a gyártó és a típus bekerül ide, és onnantól a következő
                    ugyanolyan telefonnál magától kitöltődik. Apple IRP és Samsung hivatalos
                    szervizként az új típusok elsőként nálatok fordulnak meg, úgyhogy az
                    adatbázis magától nő – internet nélkül is. A saját bejegyzéseket a
                    csomagolt lista újratöltése nem írja felül.
                </p>
            </div>
        </div>

        <script>
        (function () {
            var gomb = document.getElementById('sdh-tac-indit');

            if (!gomb) {
                return;
            }

            var allapot = document.getElementById('sdh-tac-allapot');
            var darab = document.getElementById('sdh-tac-darab');

            gomb.addEventListener('click', function () {
                gomb.disabled = true;
                adag(0);
            });

            function adag(eltolas) {
                var cim = new URL(window.SDH_MUHELY.ajax, window.location.origin);
                cim.searchParams.set('action', 'sdh_muhely_tac_import');
                cim.searchParams.set('eltolas', eltolas);
                cim.searchParams.set('_wpnonce', window.SDH_MUHELY.nonce);

                allapot.textContent = 'Betöltés… ' + eltolas.toLocaleString('hu-HU') + ' sor kész';

                fetch(cim.toString(), { credentials: 'same-origin' })
                    .then(function (v) { return v.json(); })
                    .then(function (valasz) {
                        if (!valasz || !valasz.success) {
                            allapot.textContent = 'Hiba: ' +
                                ((valasz && valasz.data && valasz.data.uzenet) || 'ismeretlen');
                            gomb.disabled = false;

                            return;
                        }

                        darab.textContent = valasz.data.darab.toLocaleString('hu-HU');

                        if (valasz.data.kesz) {
                            allapot.textContent = 'Kész: ' +
                                valasz.data.darab.toLocaleString('hu-HU') + ' készüléktípus.';
                            gomb.disabled = false;

                            return;
                        }

                        adag(valasz.data.kovetkezo);
                    })
                    .catch(function (ok) {
                        allapot.textContent = 'Hiba: ' + ok.message;
                        gomb.disabled = false;
                    });
            }
        }());
        </script>
        <?php
    }

    /* =================================================================
     * Importálás
     * ============================================================== */

    public static function ajax_import(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $fajl = self::fajl();

        if (!is_readable($fajl)) {
            wp_send_json_error(['uzenet' => 'A data/tac.csv.gz nem olvasható.']);
        }

        $eltolas = isset($_GET['eltolas']) ? max(0, (int) $_GET['eltolas']) : 0;

        $kezelo = gzopen($fajl, 'rb');

        if ($kezelo === false) {
            wp_send_json_error(['uzenet' => 'A tömörített fájl nem nyitható meg.']);
        }

        // A gzip nem tud a sor közepére ugrani, ezért átlapozzuk az
        // eddig feldolgozott sorokat. Olcsóbb, mint kicsomagolni a
        // lemezre egy 12 MB-os fájlt.
        for ($i = 0; $i < $eltolas; $i++) {
            if (gzgets($kezelo) === false) {
                break;
            }
        }

        $sorok    = [];
        $olvasott = 0;
        $vege     = true;

        while ($olvasott < self::ADAG) {
            $sor = gzgets($kezelo);

            if ($sor === false) {
                break;
            }

            $olvasott++;

            $mezok = str_getcsv(rtrim($sor, "\r\n"));

            if (count($mezok) < 4 || !preg_match('/^\d{8}$/', (string) $mezok[0])) {
                continue;
            }

            $sorok[] = [
                (string) $mezok[0],
                mb_substr((string) $mezok[1], 0, 80),
                mb_substr((string) $mezok[2], 0, 60),
                mb_substr((string) $mezok[3], 0, 120),
            ];
        }

        // Ha pont annyit olvastunk, amennyi az adag, lehet még hátra.
        if ($olvasott === self::ADAG && gzgets($kezelo) !== false) {
            $vege = false;
        }

        gzclose($kezelo);

        self::beir($sorok);

        wp_send_json_success([
            'kesz'       => $vege,
            'kovetkezo'  => $eltolas + $olvasott,
            'darab'      => self::darabszam(),
        ]);
    }

    /**
     * Beírja a sorokat. Meglévő TAC-ot frissít, nem duplikál.
     *
     * @param array<int, array{0: string, 1: string, 2: string, 3: string}> $sorok
     */
    private static function beir(array $sorok): void
    {
        global $wpdb;

        if ($sorok === []) {
            return;
        }

        $tabla = self::tabla();

        foreach (array_chunk($sorok, self::INSERT_DARAB) as $darab) {
            $helyek  = [];
            $ertekek = [];

            foreach ($darab as $sor) {
                $helyek[] = "(%s, %s, %s, %s, 'csomag')";
                array_push($ertekek, $sor[0], $sor[1], $sor[2], $sor[3]);
            }

            $sql = "INSERT INTO {$tabla} (tac, gyarto, modell, megnevezes, forras) VALUES "
                . implode(', ', $helyek)
                . ' ON DUPLICATE KEY UPDATE'
                // A saját felvitelből tanult sorokat a csomagolt lista
                // nem írja felül – azok frissebbek nálunk.
                . " gyarto = IF(forras = 'sajat', gyarto, VALUES(gyarto)),"
                . " modell = IF(forras = 'sajat', modell, VALUES(modell)),"
                . " megnevezes = IF(forras = 'sajat', megnevezes, VALUES(megnevezes))";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($wpdb->prepare($sql, $ertekek));
        }
    }
}
