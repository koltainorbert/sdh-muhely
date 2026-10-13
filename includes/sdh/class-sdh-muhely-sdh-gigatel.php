<?php
/**
 * SDH – belső: Gigatel (beszállítói levelek).
 *
 * CSAK AZ SDH SZERVIZÉ (lásd includes/sdh/class-sdh-muhely-sdh.php). Eladáskor
 * ez a fájl nem megy ki; az általános képesség – a Levél-feladat ügynök – a
 * rendes modulban van, itt csak a Gigatelre szabott BEÁLLÍTÁS áll.
 *
 * Mit tud:
 *   - felismeri a GIGATEL webáruház leveleit (feladó + tárgy),
 *   - a levelet a „Gigatel számla" mappába teszi (létrehozza, ha nincs),
 *   - feladatot nyit rá, és addig emlékeztet, amíg ki nem nyomtatjuk,
 *   - a mellékelt PDF számlát egy gombbal meg lehet nyitni nyomtatásra,
 *   - a levél tételeiből kiolvassa azokat, amelyekhez TELEFONSZÁM tartozik
 *     (a megjegyzésben: 0620, 0630, 0670, 0687, 0036… kezdetű szám) – ezekre
 *     kell címkét nyomtatni, hogy a pulton tudjuk, kié az áru.
 *
 * A tételolvasó a levél SZÖVEGÉT nézi (a webáruház levele tételenként ugyanazt
 * a néhány sort tartalmazza), és csak adatot gyűjt: a levél nem tud vele
 * műveletet végrehajtatni.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Sdh_Gigatel
{
    public const SZABALY = 'gigatel';
    public const OPT     = 'sdh_muhely_sdh_gigatel';

    /** Magyar telefonszám-előtagok a megjegyzésben (ezekre kell címke). */
    public const ELOTAGOK = ['0620', '0630', '0631', '0650', '0670', '0671', '0687', '0036', '+36'];

    public static function init(): void
    {
        if (!class_exists('SDH_Muhely_Sdh') || !class_exists('SDH_Muhely_Level_Feladat')) {
            return;
        }

        SDH_Muhely_Level_Feladat::olvaso_regisztral(
            'gigatel',
            'Gigatel webáruház – tételek telefonszámmal',
            [self::class, 'tetelek']
        );

        SDH_Muhely_Sdh::regisztral('gigatel', [
            'cim'    => 'Gigatel',
            'leiras' => 'A Gigatel webáruház napi levelei: mappába teszi, emlékeztet a számla kinyomtatására, és címkét kínál a telefonszámos tételekre.',
            'rajz'   => [self::class, 'doboz'],
            'ment'   => [self::class, 'mentes'],
        ]);
    }

    /** @return array<string, mixed> */
    public static function beallitas(): array
    {
        $b = get_option(self::OPT, []);
        $b = is_array($b) ? $b : [];

        return [
            'aktiv'  => !isset($b['aktiv']) || !empty($b['aktiv']),
            'felado' => isset($b['felado']) ? (string) $b['felado'] : 'gigatel',
            'targy'  => isset($b['targy']) ? (string) $b['targy'] : 'számlája elkészült',
            'mappa'  => isset($b['mappa']) ? (string) $b['mappa'] : 'Gigatel számla',
            // Innentől figyeli a leveleket: alapból a bekapcsolás előtti nap.
            // A régi postafiókot nem bányásszuk elő visszamenőleg (0.61.1).
            'tol'    => isset($b['tol']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $b['tol']) === 1
                ? (string) $b['tol']
                : SDH_Muhely_Level_Feladat::tegnap(),
        ];
    }

    /** A beállításból a Levél-feladat ügynök szabálya (egy helyen a kettő). */
    public static function szabaly_frissit(): void
    {
        $b = self::beallitas();

        SDH_Muhely_Level_Feladat::szabaly_ir(self::SZABALY, [
            'nev'   => 'Gigatel számla',
            'aktiv' => $b['aktiv'] && SDH_Muhely_Sdh::be(),
            'fiok'  => '',
            'tol'   => $b['tol'],
            'ha'    => ['felado' => $b['felado'], 'targy' => $b['targy'], 'csatolmany' => ''],
            // A Gigatel a számlát sokszor nem mellékeli, hanem LINKET küld rá:
            // a gigatel.hu-ra mutató linket kínáljuk fel megnyitásra (0.61.2).
            'tesz'  => ['mappa' => $b['mappa'], 'ertesit' => true, 'nyomtat' => 'csatolmany', 'link' => 'gigatel.hu', 'olvaso' => 'gigatel'],
        ]);
    }

    /* =================================================================
     * A tételolvasó
     * ============================================================== */

    /**
     * Van-e telefonszám a szövegben, és ha igen, mi az.
     * Csak a magyar előtagokkal kezdődő, 10–12 jegyű számokat fogadjuk el.
     */
    public static function telefon(string $szoveg): string
    {
        if (preg_match_all('/(?:\+?36|06)[\s\-\/]?(\d[\s\-\/]?){8,10}/u', $szoveg, $talalat) !== 1 && empty($talalat[0])) {
            return '';
        }

        foreach ((array) $talalat[0] as $nyers) {
            $szam = (string) preg_replace('/[^\d+]/', '', (string) $nyers);

            if (mb_strlen((string) preg_replace('/\D/', '', $szam)) >= 10) {
                return $szam;
            }
        }

        return '';
    }

    /**
     * A levél szövegéből a tételek. Egy tétel a webáruház levelében így néz ki:
     *
     *     Samsung Galaxy Tab A11 WIFI (SM-X130)     ← készülék (nem mindig van)
     *     Tok álló, bőr hatású (…) PIROS            ← megnevezés
     *     Azonosító: GP-177299
     *     Garancia: Szavatosság
     *     Cikkszám: 177299
     *     Megjegyzés: 8000Fizetve, 06302121235      ← ebben van a telefonszám
     *
     * A felismerés a CÍMKÉKRE épül („Azonosító:", „Cikkszám:"…): ami két
     * azonosító-sor közé esik, az egy tétel. Ár NEM kerül bele – az nem az
     * ügyfélre tartozik, a címkén sem szerepelhet.
     *
     * @return array<int, array<string, string>>
     */
    public static function tetelek(string $szoveg, array $level = []): array
    {
        // A levél HTML-ből is jöhet: ott a címkék egy sorba csúszhatnak. Minden
        // ismert címke és a tételt záró sorok elé sortörést teszünk, hogy a
        // feldolgozás ugyanúgy menjen, mint a szöveges változatnál (0.61.1).
        $nyers = (string) preg_replace(
            '/\s*(Azonosító\s*:|Garancia\s*:|Cikkszám\s*:|Megjegyzés\s*:|Nettó egységár\s*:|\d+\s*db\s+Készleten)/ui',
            "\n$1",
            (string) $szoveg
        );

        $sorok = preg_split('/\R/u', $nyers) ?: [];
        $sorok = array_map(static fn (string $s): string => trim(preg_replace('/\s+/u', ' ', $s) ?? ''), $sorok);

        $cimkek = [
            'azonosito' => 'Azonosító',
            'garancia'  => 'Garancia',
            'cikkszam'  => 'Cikkszám',
            'megjegyzes' => 'Megjegyzés',
        ];

        $tetelek = [];
        $most    = null;
        $elozo   = [];

        foreach ($sorok as $sor) {
            if ($sor === '') {
                continue;
            }

            $mezo = '';
            $ertek = '';

            foreach ($cimkek as $kulcs => $cimke) {
                if (preg_match('/^' . preg_quote($cimke, '/') . '\s*:\s*(.*)$/ui', $sor, $m) === 1) {
                    $mezo  = $kulcs;
                    $ertek = trim((string) $m[1]);
                    break;
                }
            }

            // Ár és készlet: ezek a tétel VÉGÉT jelzik, és soha nem kerülnek a címkére.
            if (preg_match('/^(Nettó egységár\s*:|Bruttó\s*:|\d+\s*db\s+Készleten|[\d\s]+Ft$)/ui', $sor) === 1) {
                if ($most !== null) {
                    $tetelek[] = $most;
                    $most = null;
                }

                $elozo = [];

                continue;
            }

            if ($mezo === '') {
                // Nem címkés sor: a tétel fejléce (készülék, megnevezés) – az utolsó kettőt tartjuk meg.
                if ($most !== null) {
                    $tetelek[] = $most;
                    $most = null;
                    $elozo = [];
                }

                $elozo[] = $sor;

                if (count($elozo) > 2) {
                    array_shift($elozo);
                }

                continue;
            }

            if ($mezo === 'azonosito' || $most === null) {
                if ($most !== null) {
                    $tetelek[] = $most;
                }

                $most = [
                    'keszulek'   => count($elozo) > 1 ? $elozo[0] : '',
                    'megnevezes' => $elozo !== [] ? (string) end($elozo) : '',
                    'azonosito'  => '',
                    'garancia'   => '',
                    'cikkszam'   => '',
                    'megjegyzes' => '',
                ];
                $elozo = [];
            }

            $most[$mezo] = $ertek;
        }

        if ($most !== null) {
            $tetelek[] = $most;
        }

        // Csak az, amihez telefonszám tartozik: azt kell címkézni.
        $ki = [];

        foreach ($tetelek as $t) {
            $telefon = self::telefon((string) $t['megjegyzes']);

            if ($telefon === '') {
                continue;
            }

            $t['telefon'] = $telefon;
            $t['cim']     = trim(((string) $t['megnevezes']) !== '' ? (string) $t['megnevezes'] : (string) $t['cikkszam']);
            $ki[]         = $t;
        }

        return $ki;
    }

    /* =================================================================
     * Beállítások › SDH – belső › Gigatel
     * ============================================================== */

    public static function doboz(): void
    {
        $b   = self::beallitas();
        $db  = 0;
        $nyit = 0;

        if (class_exists('SDH_Muhely_Level_Feladat') && SDH_Muhely_Schema::naprakesz()) {
            global $wpdb;

            $t    = SDH_Muhely_Level_Feladat::tabla();
            $db   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE szabaly = %s", self::SZABALY)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $nyit = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE szabaly = %s AND allapot = 'nyitott'", self::SZABALY)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Gigatel – napi számlalevél</h2>

            <p class="sdh-sugo">
                A GIGATEL webáruház naponta küld egy levelet a napi számláról, PDF melléklettel, és felsorolja
                a tételeket. Ez a modul felismeri ezeket a leveleket, a megadott mappába teszi őket, feladatot
                nyit rájuk (hogy a számla kinyomtatása ne maradjon el), és a telefonszámos tételekre címkét kínál.
            </p>

            <input type="hidden" name="sdh_gigatel_jelen" value="1">

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="sdh_gigatel_aktiv" id="sdh_gigatel_aktiv" value="1" <?php checked($b['aktiv']); ?>>
                <label for="sdh_gigatel_aktiv">A Gigatel-levelek feldolgozása fusson</label>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="sdh_gigatel_felado">A feladó tartalmazza</label>
                    <input type="text" name="sdh_gigatel_felado" id="sdh_gigatel_felado" maxlength="190" autocomplete="off" value="<?php echo esc_attr($b['felado']); ?>">
                    <span class="sdh-mezo__sugo">Például <code>gigatel</code>. A feladó nevére és címére is illeszkedik.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="sdh_gigatel_targy">A tárgy tartalmazza</label>
                    <input type="text" name="sdh_gigatel_targy" id="sdh_gigatel_targy" maxlength="190" autocomplete="off" value="<?php echo esc_attr($b['targy']); ?>">
                    <span class="sdh-mezo__sugo">Például <code>számlája elkészült</code> (a számlaszám így is mindegy lehet).</span>
                </div>

                <div class="sdh-mezo">
                    <label for="sdh_gigatel_tol">Ettől a naptól figyelje</label>
                    <input type="text" name="sdh_gigatel_tol" id="sdh_gigatel_tol" maxlength="10" autocomplete="off"
                           inputmode="numeric" pattern="\d{4}-\d{2}-\d{2}" placeholder="ÉÉÉÉ-HH-NN" value="<?php echo esc_attr($b['tol']); ?>">
                    <span class="sdh-mezo__sugo">A régebbi leveleket nem dolgozza fel – felesleges visszamenőleg feladatot nyitni rájuk. Alapból a bekapcsolás előtti nap.</span>
                </div>

                <div class="sdh-mezo">
                    <label for="sdh_gigatel_mappa">Melyik mappába kerüljön</label>
                    <input type="text" name="sdh_gigatel_mappa" id="sdh_gigatel_mappa" maxlength="120" autocomplete="off" value="<?php echo esc_attr($b['mappa']); ?>">
                    <span class="sdh-mezo__sugo">Ha nincs ilyen mappa a postafiókban, a rendszer létrehozza. Üresen hagyva a levél a helyén marad.</span>
                </div>
            </div>

            <p class="sdh-sugo">
                Eddig <strong><?php echo (int) $db; ?></strong> ilyen levél érkezett, ebből <strong><?php echo (int) $nyit; ?></strong> nincs még elintézve.
                A feladatok a Levelezés oldalon, a <strong>Feladatok</strong> gombbal nézhetők meg.
            </p>

            <p class="sdh-sugo sdh-sugo--utolso">
                Címke azokra a tételekre kerül, amelyek megjegyzésében telefonszám áll
                (<?php echo esc_html(implode(', ', array_slice(self::ELOTAGOK, 0, 6))); ?>…). A címkén a készülék, a megnevezés,
                az azonosító, a garancia, a cikkszám és a megjegyzés szerepel – <strong>ár nem</strong>, mert az nem az ügyfélre tartozik.
            </p>
        </div>
        <?php
    }

    public static function mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['sdh_gigatel_jelen'])) {
            return;
        }

        $szo = static fn (string $nev, string $alap): string => isset($_POST[$nev])
            ? mb_substr(sanitize_text_field(wp_unslash((string) $_POST[$nev])), 0, 190)
            : $alap;

        $regi = self::beallitas();
        $tol  = $szo('sdh_gigatel_tol', '');

        update_option(self::OPT, [
            'aktiv'  => !empty($_POST['sdh_gigatel_aktiv']),
            'felado' => $szo('sdh_gigatel_felado', 'gigatel'),
            'targy'  => $szo('sdh_gigatel_targy', 'számlája elkészült'),
            'mappa'  => $szo('sdh_gigatel_mappa', 'Gigatel számla'),
            'tol'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $tol) === 1 ? $tol : $regi['tol'],
        ], false);
        // phpcs:enable

        self::szabaly_frissit();
    }
}
