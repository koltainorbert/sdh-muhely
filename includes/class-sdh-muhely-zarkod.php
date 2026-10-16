<?php
/**
 * H6 (0.79.0): a készülék feloldó kódja (zárkód / PIN) és feloldó mintája titkosítva.
 *
 * - Tárolás: az `eszkoz.zarkod` és `eszkoz.minta` oszlopban `sdh1:` titkos alak
 *   (SDH_Muhely_Titok – a szerver sóihoz kötött libsodium). A régi, nyílt értékeket
 *   az atallas() csendben (napló nélkül), kötegenként titkosítja.
 * - Megnézés: csak a `zarkod` jogú szerep (Beállítások › Jogosultságok) – az űrlapon és a
 *   részletpanelen a **Megmutat** gomb kéri le, és minden felfedés a Változásnaplóba kerül.
 *   Beírni / felülírni jog nélkül is lehet (a pultos átvételkor beírja, de utána nem látja).
 * - Nyomtatványra és matricára is csak jogosultnál kerül (aki a nyomtatást indítja).
 * - Az asszisztens soha nem kapja meg (SDH_Muhely_Asszisztens_Eszkozok, Valtozasnaplo::AI_REJTETT).
 *
 * Ami NEM védett: a 0.79.0 előtti változásnapló-bejegyzésekben a régi kódok nyíltan
 * állnak (a napló nem írható át) – megjelenítéskor ezek is csak jogosultnak látszanak.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Zarkod
{
    /** A jog kulcsa (SDH_Muhely_Szerepkor::jogok()). */
    public const JOG = 'zarkod';

    /** A titkosított mezők. */
    public const MEZOK = ['zarkod', 'minta'];

    private const KESZ = 'sdh_muhely_zarkod_atallas';

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_zarkod_megmutat', [self::class, 'ajax_megmutat']);
        add_action('sdh_muhely_sema_frissult', [self::class, 'atallas_most']);
        add_action('init', [self::class, 'atallas_ha_kell'], 25);
    }

    /** Láthatja-e a jelenlegi személy (közös fiókon a kiválasztott dolgozó szerepe számít). */
    public static function lathat(): bool
    {
        return class_exists('SDH_Muhely_Szerepkor') ? SDH_Muhely_Szerepkor::megvan(self::JOG) : true;
    }

    /** Nyílt értékből tárolható alak. */
    public static function tarol(string $nyilt): string
    {
        return class_exists('SDH_Muhely_Titok') ? SDH_Muhely_Titok::titkosit($nyilt) : $nyilt;
    }

    /** Tárolt alakból nyílt érték (a régi nyílt érték változatlanul jön). */
    public static function olvas(string $tarolt): string
    {
        return class_exists('SDH_Muhely_Titok') ? SDH_Muhely_Titok::visszafejt($tarolt) : $tarolt;
    }

    /** Van-e mentett érték (a tartalom felfedése nélkül). */
    public static function van(string $tarolt): bool
    {
        return trim($tarolt) !== '';
    }

    /** Az eszközsor két nyílt értéke – csak jogosultnak (különben üres). */
    public static function nyilt(object $eszkoz, bool $jogtol_fuggetlen = false): array
    {
        if (!$jogtol_fuggetlen && !self::lathat()) {
            return ['zarkod' => '', 'minta' => ''];
        }

        return [
            'zarkod' => self::olvas((string) ($eszkoz->zarkod ?? '')),
            'minta'  => preg_replace('/[^1-9]/', '', self::olvas((string) ($eszkoz->minta ?? ''))) ?? '',
        ];
    }

    /**
     * Mentéskor: mi kerüljön az adatbázisba a két mezőbe.
     *
     * - üres érték + `marad` = az űrlap nem nyúlt a mezőhöz (nem fedte fel, nem törölte): a tárolt marad;
     * - ha az új nyílt érték ugyanaz, mint a tárolt, a tárolt titkos alak marad (különben minden
     *   mentés új titkos alakot – és felesleges naplósort – adna);
     * - különben az új érték titkosítva (üres = törlés).
     *
     * @param array<string, string> $uj    mező => új nyílt érték
     * @param array<string, bool>   $marad mező => változatlanul hagyandó
     * @return array<string, string> a ténylegesen írandó mezők (ami kimarad, az nem változik)
     */
    public static function mentendo(?object $regi, array $uj, array $marad): array
    {
        $ki = [];

        foreach (self::MEZOK as $mezo) {
            if (!array_key_exists($mezo, $uj)) {
                continue;
            }

            $tarolt = $regi !== null ? (string) ($regi->{$mezo} ?? '') : '';

            // Üresen jött, és az űrlap nem nyúlt hozzá (nem fedte fel, nem törölte): a tárolt marad.
            // (Akinek nincs joga megnézni, az is beírhat újat – az felülírja.)
            if ($uj[$mezo] === '' && !empty($marad[$mezo]) && $regi !== null) {
                continue;
            }

            if ($regi !== null && self::olvas($tarolt) === $uj[$mezo] && SDH_Muhely_Titok::titkos_e($tarolt)) {
                continue;
            }

            $ki[$mezo] = self::tarol($uj[$mezo]);
        }

        return $ki;
    }

    /* =================================================================
     * Felfedés (Megmutat gomb) – naplózva
     * ============================================================== */

    public static function ajax_megmutat(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if (!self::lathat()) {
            if (class_exists('SDH_Muhely_Valtozasnaplo') && $id > 0) {
                SDH_Muhely_Valtozasnaplo::esemeny('eszkoz', $id, 'Feloldó kód megnézése elutasítva: nincs hozzá jog', [], 'elutasitva');
            }

            wp_send_json_error(['uzenet' => 'A feloldó kódot és mintát a szereped nem láthatja. Szólj az adminisztrátornak (Beállítások › Jogosultságok).'], 403);
        }

        global $wpdb;

        $t   = SDH_Muhely_Schema::tabla('eszkoz');
        $sor = $id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT id, zarkod, minta FROM {$t} WHERE id = %d", $id)) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (!$sor) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen eszköz.'], 404);
        }

        $n = self::nyilt($sor, true);

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('eszkoz', $id, 'Feloldó kód / minta megnézve', ['kod' => $n['zarkod'] !== '', 'minta' => $n['minta'] !== '']);
        }

        wp_send_json_success([
            'zarkod'      => $n['zarkod'],
            'minta'       => $n['minta'],
            'minta_kiiras' => $n['minta'] !== '' ? implode('-', str_split($n['minta'])) : '',
            'olvashatatlan' => ($n['zarkod'] === '' && self::van((string) $sor->zarkod)) || ($n['minta'] === '' && self::van((string) $sor->minta)),
        ]);
    }

    /* =================================================================
     * Átállás: a régi nyílt értékek titkosítása (csendben, kötegenként)
     * ============================================================== */

    public static function atallas_ha_kell(): void
    {
        if (get_option(self::KESZ) === '1' || !SDH_Muhely_Schema::naprakesz()) {
            return;
        }

        self::atallas_most();
    }

    /** @return int ennyi sort titkosított */
    public static function atallas_most(): int
    {
        global $wpdb;

        if (!class_exists('SDH_Muhely_Titok') || !SDH_Muhely_Titok::elerheto()) {
            return 0;
        }

        $t     = SDH_Muhely_Schema::tabla('eszkoz');
        $db    = 0;
        $vege  = microtime(true) + 3.0;

        do {
            $sorok = $wpdb->get_results(
                "SELECT id, zarkod, minta FROM {$t} WHERE (zarkod <> '' AND zarkod NOT LIKE 'sdh1:%') OR (minta <> '' AND minta NOT LIKE 'sdh1:%') ORDER BY id LIMIT 300" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            );

            foreach ((array) $sorok as $s) {
                $adat = [];

                foreach (self::MEZOK as $mezo) {
                    $ertek = (string) $s->{$mezo};

                    if ($ertek !== '' && !SDH_Muhely_Titok::titkos_e($ertek)) {
                        $adat[$mezo] = self::tarol($ertek);
                    }
                }

                if ($adat !== []) {
                    // A napló nélkül: ez nem módosítás, csak a tárolás módja változik.
                    $irt = class_exists('SDH_Muhely_Valtozasnaplo')
                        ? SDH_Muhely_Valtozasnaplo::csendben(static fn () => $wpdb->update($t, $adat, ['id' => (int) $s->id]))
                        : $wpdb->update($t, $adat, ['id' => (int) $s->id]);
                    $db += $irt ? 1 : 0;
                }
            }
        } while ($sorok && microtime(true) < $vege);

        if (!$sorok) {
            update_option(self::KESZ, '1', true);
        }

        return $db;
    }

    /** Hány sor vár még átállásra (teszthez, állapothoz). */
    public static function nyilt_sorok(): int
    {
        global $wpdb;

        $t = SDH_Muhely_Schema::tabla('eszkoz');

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE (zarkod <> '' AND zarkod NOT LIKE 'sdh1:%') OR (minta <> '' AND minta NOT LIKE 'sdh1:%')"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** Újra kell futnia (pl. import után): a jelző törlése. */
    public static function atallas_ujra(): void
    {
        delete_option(self::KESZ);
    }
}
