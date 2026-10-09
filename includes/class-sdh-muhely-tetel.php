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
 * A tételeket a munkalap űrlapja szerkeszti (Szolgáltatások és Termékek
 * lapfül); a beküldött sorokat a mentes() igazítja az adatbázishoz. Az ár
 * bruttóban érkezik – a pultnál bruttóval dolgoznak –, a nettót az
 * áfakulcs adja.
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

    /**
     * A választható áfakulcsok, a MunkaLap 3 listája szerint. A számos
     * kulcsok tényleges százalékok; a betűkódok (AAM, TAM, FOA…) a NAV
     * adómentességi és különleges esetei – ezeknél az áfa nulla.
     *
     * Egy helyen van, és szűrővel bővíthető (más ország, új kulcs), mert
     * a rendszer később más szerviznek is eladható.
     *
     * @return array<string, array{nev: string, szazalek: float}>
     */
    public static function afakulcsok(): array
    {
        $kulcsok = [
            '27'      => ['nev' => '27%', 'szazalek' => 27.0],
            '18'      => ['nev' => '18%', 'szazalek' => 18.0],
            '5'       => ['nev' => '5%', 'szazalek' => 5.0],
            '0'       => ['nev' => 'Nincs (0%)', 'szazalek' => 0.0],
            'EAM'     => ['nev' => 'EAM – Adómentes termékértékesítés a Közösség területén kívülre', 'szazalek' => 0.0],
            'ATK'     => ['nev' => 'ATK – Áfa tárgyi hatályán kívül', 'szazalek' => 0.0],
            'AAM'     => ['nev' => 'AAM – Alanyi adómentes', 'szazalek' => 0.0],
            'NAM'     => ['nev' => 'NAM – Egyéb nemzetközi ügyletekhez kapcsolódó adómentesség', 'szazalek' => 0.0],
            'FOA'     => ['nev' => 'FOA – Fordított adózás – az adót a vevő fizeti', 'szazalek' => 0.0],
            'HO'      => ['nev' => 'HO – Harmadik országban teljesített ügylet', 'szazalek' => 0.0],
            'KBAET'   => ['nev' => 'KBAET – Közösségen belül adómentes', 'szazalek' => 0.0],
            'KBAUK'   => ['nev' => 'KBAUK – Közösségen belül adómentes új közlekedési eszköz értékesítése', 'szazalek' => 0.0],
            'KSZH'    => ['nev' => 'KSZH – Különbözet szerinti adózás (használtcikk)', 'szazalek' => 0.0],
            'KSZM'    => ['nev' => 'KSZM – Különbözet szerinti adózás (műalkotás)', 'szazalek' => 0.0],
            'KSZR'    => ['nev' => 'KSZR – Különbözet szerinti adózás (régiség és gyűjtemény)', 'szazalek' => 0.0],
            'KSZU'    => ['nev' => 'KSZU – Különbözet szerinti adózás (utazási iroda)', 'szazalek' => 0.0],
            'EUFAD37' => ['nev' => 'EUFAD37 – Másik tagállamban teljesített, fordítottan adózó ügylet', 'szazalek' => 0.0],
            'EUE'     => ['nev' => 'EUE – Másik tagállamban teljesített, nem fordítottan adózó ügylet', 'szazalek' => 0.0],
            'APP'     => ['nev' => 'APP – Nincs felszámított áfa a 17. § alapján', 'szazalek' => 0.0],
            'TAM'     => ['nev' => 'TAM – Tárgyi adómentes', 'szazalek' => 0.0],
        ];

        $szurt = apply_filters('sdh_muhely_afakulcsok', $kulcsok);

        return is_array($szurt) && $szurt !== [] ? $szurt : $kulcsok;
    }

    /** Az alapértelmezett áfakulcs: 27%. */
    public static function alap_afakulcs(): string
    {
        $kulcs = (string) apply_filters('sdh_muhely_alap_afakulcs', '27');

        return isset(self::afakulcsok()[$kulcs]) ? $kulcs : (string) array_key_first(self::afakulcsok());
    }

    /** Ismeretlen kulcs helyett az alapértelmezett. */
    public static function afakulcs_ervenyes(string $kulcs): string
    {
        return isset(self::afakulcsok()[$kulcs]) ? $kulcs : self::alap_afakulcs();
    }

    public static function afa_szazalek(string $kulcs): float
    {
        return (float) (self::afakulcsok()[$kulcs]['szazalek'] ?? 27.0);
    }

    /**
     * A beírt szám értelmezése: „13 000", „13000,5" és „13.000,50" is jó
     * (szóköz és ezres tagolás nélkül, a vessző tizedesjel). Hibás érték: 0.
     */
    public static function szam(string $szoveg): float
    {
        $tiszta = str_replace(["\u{00a0}", "\u{202f}", ' ', 'Ft', 'ft', '%'], '', $szoveg);

        // Ha vessző is és pont is van, a pont ezres tagolás.
        if (strpos($tiszta, ',') !== false) {
            $tiszta = str_replace('.', '', $tiszta);
        }

        $tiszta = str_replace(',', '.', $tiszta);

        return is_numeric($tiszta) ? (float) $tiszta : 0.0;
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

        // Az áfakulcs a mérvadó; a régi hívások (csak százalék) is működnek.
        if (isset($adat['afa_kulcs'])) {
            $afa_kulcs = self::afakulcs_ervenyes((string) $adat['afa_kulcs']);
            $afa       = self::afa_szazalek($afa_kulcs);
        } else {
            $afa       = isset($adat['afa']) ? max(0.0, (float) $adat['afa']) : self::afa_szazalek(self::alap_afakulcs());
            $afa_kulcs = self::kulcs_szazalekbol($afa);
        }

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
            'afa_kulcs'       => $afa_kulcs,
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

    /** Százalékból áfakulcs: a számos kulcsok közül az egyező, különben az alap. */
    private static function kulcs_szazalekbol(float $szazalek): string
    {
        foreach (self::afakulcsok() as $kulcs => $adat) {
            if (is_numeric((string) $kulcs) && abs((float) $adat['szazalek'] - $szazalek) < 0.005) {
                return (string) $kulcs;
            }
        }

        return self::alap_afakulcs();
    }

    /**
     * A munkalap űrlapjáról beküldött tételsorok megtisztítva.
     *
     * A bemenet: `tetelek[szolgaltatas|termek][i][mező]`. Az üres sorokat
     * (se megnevezés, se ár) eldobja. Az ár bruttó egységár.
     *
     * @param mixed $nyers A beküldött tömb (már wp_unslash után).
     * @return array<int, array<string, mixed>>
     */
    public static function bekuldott($nyers): array
    {
        $sorok = [];

        if (!is_array($nyers)) {
            return $sorok;
        }

        foreach (array_keys(self::tipusok()) as $tipus) {
            if (!isset($nyers[$tipus]) || !is_array($nyers[$tipus])) {
                continue;
            }

            foreach ($nyers[$tipus] as $sor) {
                if (!is_array($sor)) {
                    continue;
                }

                $szoveg = static fn (string $mezo, int $hossz): string => isset($sor[$mezo]) && is_scalar($sor[$mezo])
                    ? mb_substr(sanitize_text_field((string) $sor[$mezo]), 0, $hossz)
                    : '';
                $szam   = static fn (string $mezo, float $alap): float => isset($sor[$mezo]) && is_scalar($sor[$mezo]) && trim((string) $sor[$mezo]) !== ''
                    ? self::szam((string) $sor[$mezo])
                    : $alap;

                $megnevezes = $szoveg('megnevezes', 255);
                $brutto_ar  = max(0.0, round($szam('brutto_ar', 0.0), 2));

                if ($megnevezes === '' && $brutto_ar <= 0) {
                    continue;
                }

                $mennyiseg = $szam('mennyiseg', 1.0);

                $sorok[] = [
                    'id'         => isset($sor['id']) ? max(0, (int) $sor['id']) : 0,
                    'tipus'      => $tipus,
                    'megnevezes' => $megnevezes,
                    // A szolgáltatások sorában ez a három mező nincs az űrlapon:
                    // null = „nem jött", a meglévő érték érintetlen marad.
                    'termekkod'  => array_key_exists('termekkod', $sor) ? $szoveg('termekkod', 60) : null,
                    'cikkszam'   => array_key_exists('cikkszam', $sor) ? $szoveg('cikkszam', 60) : null,
                    'gyari_szam' => array_key_exists('gyari_szam', $sor) ? $szoveg('gyari_szam', 60) : null,
                    'mennyiseg'  => $mennyiseg > 0 ? round($mennyiseg, 3) : 1.0,
                    'me'         => $szoveg('me', 20) !== '' ? $szoveg('me', 20) : 'db',
                    'kedvezmeny' => min(100.0, max(0.0, round($szam('kedvezmeny', 0.0), 2))),
                    'afa_kulcs'  => self::afakulcs_ervenyes($szoveg('afa_kulcs', 12)),
                    'brutto_ar'  => $brutto_ar,
                ];
            }
        }

        return $sorok;
    }

    /**
     * A munkalap tételeinek szinkronizálása a beküldött sorokkal: ami új,
     * létrejön; ami megvan, frissül; ami hiányzik, törlődik. Végül a
     * munkalap összesített értéke is frissül.
     *
     * Azok a mezők, amelyeket az űrlap nem szerkeszt (mozgás, állapot,
     * időpont, számla, eladó, munkavégző), a meglévő soron érintetlenek.
     *
     * @param array<int, array<string, mixed>> $sorok A bekuldott() eredménye.
     */
    public static function mentes(int $munkalap_id, array $sorok): void
    {
        global $wpdb;

        if ($munkalap_id <= 0) {
            return;
        }

        $tabla   = self::tabla();
        $most    = current_time('mysql');
        $letezok = array_map(
            'intval',
            (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tabla} WHERE munkalap_id = %d", $munkalap_id))
        );

        $megmarad = [];
        $sorrend  = ['szolgaltatas' => 0, 'termek' => 0];

        foreach ($sorok as $sor) {
            $afa       = self::afa_szazalek((string) $sor['afa_kulcs']);
            $brutto_ar = (float) $sor['brutto_ar'];
            $netto_ar  = self::bruttobol_netto($brutto_ar, $afa);
            $menny     = (float) $sor['mennyiseg'];
            $kedv      = (float) $sor['kedvezmeny'];

            $adat = [
                'munkalap_id'  => $munkalap_id,
                'sorrend'      => $sorrend[$sor['tipus']]++,
                'tipus'        => $sor['tipus'],
                'megnevezes'   => $sor['megnevezes'],
                'mennyiseg'    => $menny,
                'me'           => $sor['me'],
                'kedvezmeny'   => $kedv,
                'afa'          => $afa,
                'afa_kulcs'    => $sor['afa_kulcs'],
                'netto_ar'     => $netto_ar,
                'brutto_ar'    => $brutto_ar,
                'netto_ertek'  => self::ertek($netto_ar, $menny, $kedv),
                'brutto_ertek' => self::ertek($brutto_ar, $menny, $kedv),
                'modositva'    => $most,
            ];

            foreach (['termekkod', 'cikkszam', 'gyari_szam'] as $mezo) {
                if ($sor[$mezo] !== null) {
                    $adat[$mezo] = $sor[$mezo];
                }
            }

            // Csak ehhez a munkalaphoz tartozó sort írhatunk felül – egy
            // meghamisított azonosító nem nyúlhat más lap tételéhez.
            if ($sor['id'] > 0 && in_array($sor['id'], $letezok, true)) {
                $wpdb->update($tabla, $adat, ['id' => $sor['id']]);
                $megmarad[] = $sor['id'];

                continue;
            }

            $adat['mozgas']     = 'kimeno';
            $adat['allapot']    = 'teljesitett';
            $adat['idopont']    = current_time('Y-m-d');
            $adat['forras']     = 'kezi';
            $adat['letrehozva'] = $most;

            if ($wpdb->insert($tabla, $adat) !== false) {
                $megmarad[] = (int) $wpdb->insert_id;
            }
        }

        foreach (array_diff($letezok, $megmarad) as $torlendo) {
            $wpdb->delete($tabla, ['id' => $torlendo, 'munkalap_id' => $munkalap_id]);
        }

        self::ujraszamol($munkalap_id);
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
