<?php
/**
 * SDH Értékesítés – kupon ellenőrzése egy kosárra (POST /kupon). A kuponok a tartalom-tárban (`kupon` típus, az admin
 * szerkeszti); mezők: kod, tipus ('szazalek'|'fix'), ertek, lejar ('Y-m-d'|null), kezd?, max (felhasználások)|null,
 * min (nettó Ft)?, hatokor ('mind'|'csomag'|'modul'|'szolgaltatas'| [kulcsok/típusok]), ciklus? ('havi'|'eves'), aktiv?.
 * Új mező: `vevonkent` (egy vevő – e-mail / fiók – legfeljebb ennyiszer használhatja).
 * A kuponlista soha nem kerül a frontra; a kód kitalálását a szigorú korlát fékezi: a sikertelen kuponpróbák IP-nként
 * közös keretből fogynak (`PROBA`) – a /kupon, az /ar és a /kosar/ellenoriz egyaránt ebből dolgozik, így az ár-végponton
 * át sem lehet kódokat próbálgatni.
 * Felhasználás: csak a kifizetett rendelés számít, a fizetetlen rendelés `kupon.foglalas_ora` óráig foglal; a keret-
 * ellenőrzés a rendelés beszúrása után, az azonosító sorrendjében fut (atomi, nem léphető túl párhuzamosan).
 *
 *   ellenoriz(string $kod, array $tetelek, array $opciok): array{ok, kod, kedvezmeny, uzenet, ar}
 *   foglal(string $kod, int $rendeles_id): bool       – a beszúrt rendelés belefér-e a keretbe
 *   vevo_korlat(string $kod, string $email, int $uid): string – hibaüzenet, ha a vevő elérte a `vevonkent` korlátot
 *   opciok_szur(array $opciok, ?string &$hiba): array – /ar, /kosar: kupon kivétele, ha az IP kimerítette a próbákat
 *   eredmeny_jelez(array $opciok, array $ar): void     – sikertelen kuponpróba könyvelése
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Kupon
{
    /** Sikertelen kuponpróbák IP-nként: [név, darab, másodperc]. */
    public const PROBA = ['kupon_hiba', 10, 900];

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/kupon', 'POST', [self::class, 'rest'], 'nyilvanos', ['kupon', 20, 600]);
    }

    public static function tul_sok_szoveg(): string
    {
        return __('Túl sok sikertelen kuponpróba. Próbáld újra 15 perc múlva.', 'sdh-sales');
    }

    /** Kimerítette-e az IP a sikertelen kuponpróbák keretét (nem számol). */
    public static function proba_tiltva(): bool
    {
        return !SDH_Sales_Rest::korlat_belefer(self::PROBA[0], self::PROBA[1], self::PROBA[2]);
    }

    /** Egy sikertelen kuponpróba könyvelése. */
    public static function proba_hiba(): void
    {
        SDH_Sales_Rest::korlat_novel(self::PROBA[0], self::PROBA[2]);
    }

    /**
     * Az /ar és a /kosar/ellenoriz opciói: ha az IP kimerítette a kuponpróbákat, a kupon kimarad (és hibaüzenet jön).
     *
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    public static function opciok_szur(array $o, ?string &$hiba = null): array
    {
        $hiba = null;

        if (!empty($o['kupon']) && self::proba_tiltva()) {
            unset($o['kupon']);
            $hiba = self::tul_sok_szoveg();
        }

        return $o;
    }

    /** @param array<string, mixed> $o @param array<string, mixed> $ar */
    public static function eredmeny_jelez(array $o, array $ar): void
    {
        if (!empty($o['kupon']) && empty($ar['kupon'])) {
            self::proba_hiba();
        }
    }

    /** A rendelés (már beszúrva) belefér-e a kupon keretébe: az előtte beszúrt élő felhasználások < max. */
    public static function foglal(string $kod, int $rendeles_id): bool
    {
        $k = SDH_Sales_Katalogus::kupon($kod);

        if ($k === null || !isset($k['max']) || $k['max'] === null || (int) $k['max'] <= 0) {
            return true;
        }

        return SDH_Sales_Ar::kupon_hasznalat($kod, $rendeles_id, $rendeles_id) < (int) $k['max'];
    }

    /** Vevőnkénti korlát (`vevonkent`): az e-mail-cím vagy a fiók élő felhasználásai. Üres = rendben. */
    public static function vevo_korlat(string $kod, string $email, int $uid): string
    {
        $k = SDH_Sales_Katalogus::kupon($kod);
        $n = (int) ($k['vevonkent'] ?? 0);

        if ($k === null || $n <= 0) {
            return '';
        }

        global $wpdb;

        $hatar = gmdate('Y-m-d H:i:s', time() - SDH_Sales_Ar::kupon_foglalas_ora() * HOUR_IN_SECONDS);
        $db    = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . SDH_Sales_Schema::t('rendeles') . " WHERE kupon = %s AND (email = %s OR (%d > 0 AND user_id = %d))
             AND (allapot IN ('fizetve','kiszolgalva') OR (allapot IN ('uj','dijbekero','fizetesre_var') AND letrehozva >= %s))",
            strtoupper($kod),
            strtolower($email),
            $uid,
            $uid,
            $hatar
        ));

        return $db >= $n ? sprintf(__('A(z) %s kupont már felhasználtad.', 'sdh-sales'), strtoupper($kod)) : '';
    }

    /**
     * @param array<int, mixed>    $tetelek
     * @param array<string, mixed> $opciok
     * @return array{ok: bool, kod: string, kedvezmeny: int, uzenet: string, ar: array<string, mixed>}
     */
    public static function ellenoriz(string $kod, array $tetelek, array $opciok = []): array
    {
        $kod = strtoupper(trim($kod));

        if ($kod === '' || preg_match('/^[A-Z0-9_-]{2,40}$/', $kod) !== 1) {
            self::proba_hiba();
            $ar = SDH_Sales_Ar::szamol($tetelek, array_diff_key($opciok, ['kupon' => 1]));

            return ['ok' => false, 'kod' => $kod, 'kedvezmeny' => 0, 'uzenet' => 'Érvénytelen kuponkód.', 'ar' => $ar];
        }

        $alap = SDH_Sales_Ar::szamol($tetelek, array_diff_key($opciok, ['kupon' => 1]));
        $ar   = SDH_Sales_Ar::szamol($tetelek, ['kupon' => $kod] + $opciok);
        $sajat = array_values(array_diff($ar['hibak'], $alap['hibak']));

        if ($ar['kupon'] === null) {
            self::proba_hiba();
            SDH_Sales_Naplo::ir('kupon', 'Sikertelen kuponpróba: ' . $kod, 'info');

            return ['ok' => false, 'kod' => $kod, 'kedvezmeny' => 0, 'uzenet' => $sajat[0] ?? 'A kupon erre a kosárra nem érvényes.', 'ar' => $alap];
        }

        return ['ok' => true, 'kod' => $kod, 'kedvezmeny' => (int) $ar['kedvezmeny'], 'uzenet' => 'A kupon érvényes: ' . SDH_Sales::ft((int) $ar['kedvezmeny']) . ' kedvezmény.', 'ar' => $ar];
    }

    public static function rest(WP_REST_Request $r): WP_REST_Response
    {
        $o = SDH_Sales_Ar::opciok_tisztit($r->get_param('opciok'));
        unset($o['kupon']);

        if (self::proba_tiltva()) {
            return new WP_REST_Response(['ok' => false, 'kod' => '', 'kedvezmeny' => 0, 'uzenet' => self::tul_sok_szoveg(), 'ar' => SDH_Sales_Ar::szamol(SDH_Sales_Ar::tetelek_tisztit($r->get_param('tetelek')), $o)], 200);
        }

        return new WP_REST_Response(self::ellenoriz(
            sanitize_text_field((string) $r->get_param('kod')),
            SDH_Sales_Ar::tetelek_tisztit($r->get_param('tetelek')),
            $o
        ), 200);
    }
}
