<?php
/**
 * Munkalap-tételek: szolgáltatások és termékek.
 *
 * A MunkaLap 3 „Szolgáltatások" és „Termékek" lapfülének megfelelője. Egy
 * tétel egy munkalaphoz tartozik; a nettó és a bruttó értékét ez az osztály
 * számolja, és a munkalap összesített értékét is ez tartja karban
 * (munkalap.netto_ertek / brutto_ertek) – a kezdőképernyő rácsa onnan
 * rendez és szűr, nem a tételeket összegzi soronként.
 *
 * Ez a réteg egyelőre csak tárol és olvas: a tételek szerkesztése a
 * munkalap űrlapján külön lépésben készül el.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Tetel
{
    public static function init(): void
    {
        // Nincs saját végpontja: a munkalap és a rács használja.
    }

    public static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('munkalap_tetel');
    }

    /* =================================================================
     * Szótárak
     * ============================================================== */

    /**
     * @return array<string, string> kulcs => felirat
     */
    public static function tipusok(): array
    {
        return [
            'szolgaltatas' => 'Szolgáltatás',
            'termek'       => 'Termék',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mozgasok(): array
    {
        return [
            'kimeno' => 'Kimenő',
            'bejovo' => 'Bejövő',
        ];
    }

    /**
     * A tétel állapotai. A szín a munkalap-állapotok színnevei közül való
     * (`--sdh-allapot-<név>` az assets/admin.css tetején).
     *
     * @return array<string, array{nev: string, szin: string}>
     */
    public static function allapotok(): array
    {
        return [
            'tervezett'   => ['nev' => 'Tervezett', 'szin' => 'sarga'],
            'teljesitett' => ['nev' => 'Teljesített', 'szin' => 'lila'],
        ];
    }

    /* =================================================================
     * Olvasás
     * ============================================================== */

    /**
     * Egy munkalap tételei, sorrendben. Üres típusnál mind.
     *
     * @return array<int, object>
     */
    public static function lista(int $munkalap_id, string $tipus = ''): array
    {
        global $wpdb;

        if ($munkalap_id <= 0) {
            return [];
        }

        $tabla = self::tabla();

        if ($tipus !== '' && isset(self::tipusok()[$tipus])) {
            $sorok = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$tabla} WHERE munkalap_id = %d AND tipus = %s ORDER BY sorrend ASC, id ASC",
                    $munkalap_id,
                    $tipus
                )
            );
        } else {
            $sorok = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$tabla} WHERE munkalap_id = %d ORDER BY sorrend ASC, id ASC",
                    $munkalap_id
                )
            );
        }

        return is_array($sorok) ? $sorok : [];
    }

    /* =================================================================
     * Számolás
     * ============================================================== */

    /**
     * Nettó egységárból bruttó az áfakulccsal – és fordítva.
     */
    public static function nettobol_brutto(float $netto, float $afa): float
    {
        return round($netto * (1 + $afa / 100), 2);
    }

    public static function bruttobol_netto(float $brutto, float $afa): float
    {
        return round($brutto / (1 + $afa / 100), 2);
    }

    /**
     * Egy tétel értéke: egységár × mennyiség, a kedvezmény százalékával csökkentve.
     */
    public static function ertek(float $egysegar, float $mennyiseg, float $kedvezmeny): float
    {
        $kedvezmeny = min(100.0, max(0.0, $kedvezmeny));

        return round($egysegar * $mennyiseg * (1 - $kedvezmeny / 100), 2);
    }

    /* =================================================================
     * Írás
     * ============================================================== */

    /**
     * Új tétel egy munkalaphoz. Az árat elég egyféleképp megadni: ha csak
     * a bruttó egységár jön, a nettót az áfakulcsból számoljuk, és fordítva.
     *
     * @param array<string, mixed> $adat
     * @return int Az új tétel azonosítója, vagy 0, ha nem jött létre.
     */
    public static function hozzaad(int $munkalap_id, array $adat): int
    {
        global $wpdb;

        if ($munkalap_id <= 0) {
            return 0;
        }

        $tipus  = isset(self::tipusok()[$adat['tipus'] ?? '']) ? (string) $adat['tipus'] : 'szolgaltatas';
        $mozgas = isset(self::mozgasok()[$adat['mozgas'] ?? '']) ? (string) $adat['mozgas'] : 'kimeno';
        $allapot = isset(self::allapotok()[$adat['allapot'] ?? '']) ? (string) $adat['allapot'] : 'teljesitett';

        $afa        = isset($adat['afa']) ? max(0.0, (float) $adat['afa']) : 27.0;
        $mennyiseg  = isset($adat['mennyiseg']) ? (float) $adat['mennyiseg'] : 1.0;
        $kedvezmeny = isset($adat['kedvezmeny']) ? (float) $adat['kedvezmeny'] : 0.0;

        if (isset($adat['brutto_ar'])) {
            $brutto_ar = round((float) $adat['brutto_ar'], 2);
            $netto_ar  = isset($adat['netto_ar'])
                ? round((float) $adat['netto_ar'], 2)
                : self::bruttobol_netto($brutto_ar, $afa);
        } else {
            $netto_ar  = round((float) ($adat['netto_ar'] ?? 0), 2);
            $brutto_ar = self::nettobol_brutto($netto_ar, $afa);
        }

        $most = current_time('mysql');

        $sor = [
            'munkalap_id'     => $munkalap_id,
            'sorrend'         => (int) ($adat['sorrend'] ?? 0),
            'tipus'           => $tipus,
            'mozgas'          => $mozgas,
            'allapot'         => $allapot,
            'idopont'         => isset($adat['idopont']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $adat['idopont']) === 1
                ? (string) $adat['idopont']
                : null,
            'szamla'          => mb_substr((string) ($adat['szamla'] ?? ''), 0, 40),
            'megnevezes'      => mb_substr((string) ($adat['megnevezes'] ?? ''), 0, 255),
            'termekkod'       => mb_substr((string) ($adat['termekkod'] ?? ''), 0, 60),
            'cikkszam'        => mb_substr((string) ($adat['cikkszam'] ?? ''), 0, 60),
            'gyari_szam'      => mb_substr((string) ($adat['gyari_szam'] ?? ''), 0, 60),
            'mennyiseg'       => $mennyiseg,
            'me'              => mb_substr((string) ($adat['me'] ?? 'db'), 0, 20),
            'munkavegzo'      => max(0, (int) ($adat['munkavegzo'] ?? 0)),
            'kedvezmeny'      => min(100.0, max(0.0, $kedvezmeny)),
            'afa'             => $afa,
            'netto_ar'        => $netto_ar,
            'brutto_ar'       => $brutto_ar,
            'netto_ertek'     => self::ertek($netto_ar, $mennyiseg, $kedvezmeny),
            'brutto_ertek'    => self::ertek($brutto_ar, $mennyiseg, $kedvezmeny),
            'elado'           => mb_substr((string) ($adat['elado'] ?? ''), 0, 190),
            'forras'          => mb_substr((string) ($adat['forras'] ?? 'kezi'), 0, 30),
            'kulso_azonosito' => mb_substr((string) ($adat['kulso_azonosito'] ?? ''), 0, 40),
            'letrehozva'      => $most,
            'modositva'       => $most,
        ];

        if ($wpdb->insert(self::tabla(), $sor) === false) {
            return 0;
        }

        $id = (int) $wpdb->insert_id;

        self::ujraszamol($munkalap_id);

        return $id;
    }

    /**
     * A munkalap összesített nettó és bruttó értéke a kimenő tételekből.
     *
     * Minden tételváltozás után ezt kell hívni, hogy a munkalapon tárolt
     * összeg és a tételek soha ne térjenek el egymástól.
     */
    public static function ujraszamol(int $munkalap_id): void
    {
        global $wpdb;

        if ($munkalap_id <= 0) {
            return;
        }

        $osszeg = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COALESCE(SUM(netto_ertek), 0) AS netto, COALESCE(SUM(brutto_ertek), 0) AS brutto
                 FROM ' . self::tabla() . " WHERE munkalap_id = %d AND mozgas = 'kimeno'",
                $munkalap_id
            )
        );

        $wpdb->update(
            SDH_Muhely_Schema::tabla('munkalap'),
            [
                'netto_ertek'  => $osszeg ? round((float) $osszeg->netto, 2) : 0,
                'brutto_ertek' => $osszeg ? round((float) $osszeg->brutto, 2) : 0,
            ],
            ['id' => $munkalap_id]
        );
    }
}
