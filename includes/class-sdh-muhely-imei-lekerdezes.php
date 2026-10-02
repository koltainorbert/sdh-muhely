<?php
/**
 * Külső IMEI-lekérdezés.
 *
 * A gyári szám, a sorozatszám, a garancia állapota és a gyártási dátum
 * nem olvasható ki az IMEI-ből: ezeket a gyártó szervere tudja, és
 * fizetős szolgáltatók adják tovább (imeicheck.com, imeicheck.net,
 * sickw és társaik). Ezért ez a modul nem köt egyetlen szolgáltatóhoz
 * sem: te adod meg a végpont címét és a kulcsot, a válasz feldolgozása
 * pedig mindkét elterjedt formátumot ismeri.
 *
 * Ez az egyetlen pont a rendszerben, ami internetet igényel. Ha nincs
 * net, vagy nincs beállítva szolgáltató, a felvitel ugyanúgy megy
 * kézzel – semmi nem áll meg tőle.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Imei_Lekerdezes
{
    /**
     * A válasz címkéinek megfeleltetése a saját mezőinkhez.
     *
     * Kisbetűsítve, írásjelek nélkül hasonlítunk, mert a szolgáltatók
     * ugyanazt az adatot hol „Model Name", hol „model_name", hol
     * „modelName" néven adják vissza.
     *
     * @var array<string, string>
     */
    private const MEZOK = [
        'modelname'                => 'tipus',
        'model'                    => 'tipus',
        'modeldesc'                => 'megnevezes',
        'modeldescription'         => 'megnevezes',
        'modelnumber'              => 'modell_szam',
        'modelcode'                => 'modell_szam',
        'serialnumber'             => 'sorozatszam',
        'serial'                   => 'sorozatszam',
        'imei'                     => 'imei',
        'imei1'                    => 'imei',
        'imei2'                    => 'imei2',
        'brand'                    => 'gyarto',
        'manufacturer'             => 'gyarto',
        'warrantystatus'           => 'garancia_allapot',
        'warranty'                 => 'garancia_allapot',
        'estimatedwarrantyenddate' => 'garancia_lejar',
        'warrantyenddate'          => 'garancia_lejar',
        'productiondate'           => 'gyartas_datuma',
        'manufacturedate'          => 'gyartas_datuma',
        'purchasedate'             => 'vasarlas_datuma',
        'country'                  => 'orszag',
        'carrier'                  => 'szolgaltato',
        'network'                  => 'szolgaltato',
        'simlock'                  => 'szolgaltato',
        'image'                    => 'kep_url',
        'imageurl'                 => 'kep_url',
        'photo'                    => 'kep_url',
    ];

    /** Ezeket dátummá alakítjuk. */
    private const DATUM_MEZOK = ['garancia_lejar', 'gyartas_datuma', 'vasarlas_datuma'];

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_imei_lekerdez', [self::class, 'ajax_lekerdez']);
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    /**
     * @return array{url: string, kulcs: string, aktiv: bool}
     */
    public static function beallitas(): array
    {
        $mentett = get_option('sdh_muhely_imei_szolgaltato', []);

        return [
            'url'   => (string) ($mentett['url'] ?? ''),
            'kulcs' => (string) ($mentett['kulcs'] ?? ''),
            'aktiv' => !empty($mentett['aktiv']),
        ];
    }

    public static function beallitva(): bool
    {
        $b = self::beallitas();

        return $b['aktiv'] && $b['url'] !== '';
    }

    /* =================================================================
     * Lekérdezés
     * ============================================================== */

    public static function ajax_lekerdez(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        if (!self::beallitva()) {
            wp_send_json_error([
                'uzenet' => 'Nincs beállítva IMEI-szolgáltató. '
                    . 'SDH Műhely → Beállítások, ott add meg a végpontot és a kulcsot.',
            ]);
        }

        $imei = isset($_GET['imei'])
            ? preg_replace('/\D/', '', (string) wp_unslash($_GET['imei']))
            : '';

        if (strlen((string) $imei) !== 15) {
            wp_send_json_error(['uzenet' => 'Előbb írd be a teljes, 15 számjegyű IMEI-t.']);
        }

        $eredmeny = self::lekerdez((string) $imei);

        if ($eredmeny === null) {
            wp_send_json_error([
                'uzenet' => 'A szolgáltató nem válaszolt, vagy nem ismerte fel az IMEI-t. '
                    . 'Töltsd ki kézzel.',
            ]);
        }

        wp_send_json_success(['mezok' => $eredmeny]);
    }

    /**
     * Meghívja a beállított szolgáltatót, és a válaszból a saját
     * mezőinket állítja elő.
     *
     * @return array<string, string>|null
     */
    public static function lekerdez(string $imei): ?array
    {
        $beallitas = self::beallitas();

        $cim = str_replace(
            ['{imei}', '{kulcs}', '{key}'],
            [rawurlencode($imei), rawurlencode($beallitas['kulcs']), rawurlencode($beallitas['kulcs'])],
            $beallitas['url']
        );

        $valasz = wp_remote_get(
            $cim,
            [
                'timeout' => 20,
                'headers' => $beallitas['kulcs'] !== ''
                    ? ['Authorization' => 'Bearer ' . $beallitas['kulcs']]
                    : [],
            ]
        );

        if (is_wp_error($valasz) || (int) wp_remote_retrieve_response_code($valasz) >= 400) {
            return null;
        }

        $torzs = wp_remote_retrieve_body($valasz);

        if (trim($torzs) === '') {
            return null;
        }

        $mezok = self::feldolgoz($torzs);

        return $mezok === [] ? null : $mezok;
    }

    /* =================================================================
     * Feldolgozás
     * ============================================================== */

    /**
     * A szolgáltató válaszából a saját mezőink.
     *
     * Kétféle választ ismerünk: JSON objektumot, és a sok szolgáltatónál
     * szokásos „Címke: érték" sorokat (ilyet ad vissza a legtöbb
     * webes IMEI-ellenőrző is).
     *
     * @return array<string, string>
     */
    public static function feldolgoz(string $torzs): array
    {
        $parok = [];

        $json = json_decode($torzs, true);

        if (is_array($json)) {
            $parok = self::lapit($json);
        }

        // JSON-ban is előfordul, hogy a tényleges adat egy HTML- vagy
        // szövegblokkban ül – azt is átnézzük.
        $parok = array_merge($parok, self::sorokbol(wp_strip_all_tags($torzs)));

        $mezok = [];

        foreach ($parok as $cimke => $ertek) {
            $kulcs = self::MEZOK[self::normalizal($cimke)] ?? '';

            if ($kulcs === '' || trim((string) $ertek) === '') {
                continue;
            }

            // Az elsőként talált érték nyer: a JSON megbízhatóbb, mint a
            // szövegblokk, és azt dolgozzuk fel előbb.
            if (isset($mezok[$kulcs])) {
                continue;
            }

            $ertek = trim((string) $ertek);

            if (in_array($kulcs, self::DATUM_MEZOK, true)) {
                $ertek = self::datumma($ertek);

                if ($ertek === '') {
                    continue;
                }
            }

            $mezok[$kulcs] = $ertek;
        }

        return $mezok;
    }

    /**
     * Többszintű JSON kilapítása „kulcs => érték" párokká.
     *
     * @param array<mixed> $tomb
     * @return array<string, string>
     */
    private static function lapit(array $tomb): array
    {
        $parok = [];

        foreach ($tomb as $kulcs => $ertek) {
            if (is_array($ertek)) {
                $parok = array_merge($parok, self::lapit($ertek));

                continue;
            }

            if (is_scalar($ertek) && is_string($kulcs)) {
                $parok[$kulcs] = (string) $ertek;
            }
        }

        return $parok;
    }

    /**
     * „Címke: érték" sorok kiszedése szövegből.
     *
     * @return array<string, string>
     */
    private static function sorokbol(string $szoveg): array
    {
        $parok = [];

        foreach (preg_split('/\r\n|\r|\n|<br\s*\/?>/i', $szoveg) ?: [] as $sor) {
            if (!str_contains($sor, ':')) {
                continue;
            }

            [$cimke, $ertek] = explode(':', $sor, 2);

            $cimke = trim($cimke);
            $ertek = trim($ertek);

            if ($cimke === '' || $ertek === '' || mb_strlen($cimke) > 40) {
                continue;
            }

            if (!isset($parok[$cimke])) {
                $parok[$cimke] = $ertek;
            }
        }

        return $parok;
    }

    private static function normalizal(string $cimke): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $cimke) ?? '');
    }

    /**
     * Dátumszöveg egységes Y-m-d alakra.
     *
     * A szolgáltatók hol 07-05-2019, hol 2019-05-07 formában adják.
     * A kétjegyűvel kezdődő alakot nap-hónap-évnek vesszük, mert az
     * európai szolgáltatók így küldik.
     */
    private static function datumma(string $ertek): string
    {
        $ertek = trim($ertek);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ertek, $r) === 1) {
            return $r[0];
        }

        if (preg_match('/^(\d{2})[-.\/](\d{2})[-.\/](\d{4})$/', $ertek, $r) === 1) {
            return sprintf('%s-%s-%s', $r[3], $r[2], $r[1]);
        }

        $ido = strtotime($ertek);

        return $ido === false ? '' : gmdate('Y-m-d', $ido);
    }
}
