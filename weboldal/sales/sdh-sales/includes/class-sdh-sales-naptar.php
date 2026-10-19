<?php
/**
 * SDH Értékesítés – időpontfoglalás (demó, telepítés, oktatás, support): szabad sávok munkaidőből, pufferrel,
 * zárolt napokkal és ünnepnapokkal (Europe/Budapest, nyári időszámítás-helyesen), foglalás dupla foglalás nélkül
 * (adatbázis-zár), visszaigazolás ICS-sel, lemondás / átfoglalás tokennel, emlékeztető 24 és 1 órával előtte,
 * titkos iCal-feed (Google Naptár feliratkozás), opcionális Google Calendar API-küldés.
 *
 * API:
 *   tipusok(bool $csak_nyilvanos = false): array            – a foglalástípusok beállítása
 *   savok(string $tipus, string $tol, string $ig, ?int $most = null, int $kiveve_id = 0): array  – Y-m-d => [sáv]
 *   foglal(array $be, ?int $most = null): array|WP_Error     – {id, token, kezdet, veg}
 *   lemond(string $token, ?int $most = null, string $ki = 'ugyfel'): bool|WP_Error
 *   ajanlat_link(int $rendeles_id, string $tipus = 'telepites'): string – előtöltött foglaló link a rendeléshez
 *   ajanlat_kuld(int $rendeles_id, string $tipus = 'telepites'): bool   – ugyanez levélben (idopont_ajanlat)
 *   ics(array $foglalas, string $metodus = 'PUBLISH'): string / ical_feed(): string / ical_url(): string
 *   emlekezteto(?int $most = null): int – a cron hívja (SDH_Sales::CRON)
 * Front: `<div data-sdh="foglalas" data-tipus="demo"></div>` + SDH_Sales_Front::igeny('foglalas'), vagy [sdh_foglalas tipus="demo"].
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Naptar
{
    public const TZ = 'Europe/Budapest';

    /** emlekezteto_kuldve: 0 = semmi, 1 = a 24 órás ment ki, 2 = az 1 órás is. */
    public const EML_24 = 1;
    public const EML_1  = 2;

    private const NAPOK   = [1 => 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap'];
    private const HONAPOK = [1 => 'január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];

    public static function init(): void
    {
        add_action(SDH_Sales::CRON, [self::class, 'cron']);
        add_filter('rest_pre_serve_request', [self::class, 'nyers_kiszolgal'], 10, 4);
        add_shortcode('sdh_foglalas', [self::class, 'shortcode']);
        add_action('init', [self::class, 'front_regisztral'], 6);

        SDH_Sales_Rest::ut('/foglalas/savok', 'GET', [self::class, 'rest_savok'], 'nyilvanos', ['fogl_savok', 120, 60]);
        SDH_Sales_Rest::ut('/foglalas', 'POST', [self::class, 'rest_foglal'], 'nyilvanos', ['foglalas', 10, 3600]);
        SDH_Sales_Rest::ut('/foglalas/lemond', 'POST', [self::class, 'rest_lemond'], 'nyilvanos', ['fogl_lemond', 10, 3600]);
        SDH_Sales_Rest::ut('/foglalas/adat', 'GET', [self::class, 'rest_adat'], 'nyilvanos', ['fogl_adat', 60, 3600]);
        SDH_Sales_Rest::ut('/foglalas/ics', 'GET', [self::class, 'rest_ics'], 'nyilvanos', ['fogl_ics', 30, 3600]);
        SDH_Sales_Rest::ut('/foglalas/elotolt', 'GET', [self::class, 'rest_elotolt'], 'nyilvanos', ['fogl_elotolt', 30, 3600]);
        SDH_Sales_Rest::ut('/naptar/ical', 'GET', [self::class, 'rest_ical'], 'nyilvanos', ['ical', 60, 3600]);
        SDH_Sales_Rest::ut('/admin/foglalasok', 'GET', [self::class, 'rest_admin_lista'], 'admin');
        SDH_Sales_Rest::ut('/admin/foglalasok/(?P<id>\d+)/lemond', 'POST', [self::class, 'rest_admin_lemond'], 'admin');
        SDH_Sales_Rest::ut('/admin/naptar/ical', 'GET', [self::class, 'rest_admin_ical'], 'admin');
        SDH_Sales_Rest::ut('/admin/naptar/ical', 'POST', [self::class, 'rest_admin_ical_uj'], 'admin');
        SDH_Sales_Rest::ut('/admin/naptar/google', 'POST', [self::class, 'rest_admin_google'], 'admin', ['google_proba', 10, 60]);
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    private static function tz(): DateTimeZone
    {
        return new DateTimeZone((string) apply_filters('sdh_sales_naptar_idozona', self::TZ));
    }

    /** @return array<string, array<string, mixed>> */
    public static function tipus_alap(): array
    {
        return [
            'demo'      => ['nev' => __('Online bemutató', 'sdh-sales'), 'leiras' => __('Megmutatjuk a {termeknev} működését a te szervizedre szabva.', 'sdh-sales'), 'hossz' => 30, 'puffer' => 15, 'aktiv' => true, 'nyilvanos' => true],
            'telepites' => ['nev' => __('Telepítés', 'sdh-sales'), 'leiras' => __('Közösen telepítjük és beállítjuk a {termeknev} rendszert.', 'sdh-sales'), 'hossz' => 90, 'puffer' => 30, 'aktiv' => true, 'nyilvanos' => false],
            'oktatas'   => ['nev' => __('Betanítás', 'sdh-sales'), 'leiras' => __('Végigvesszük a napi munkát a csapatoddal.', 'sdh-sales'), 'hossz' => 60, 'puffer' => 15, 'aktiv' => true, 'nyilvanos' => false],
            'support'   => ['nev' => __('Support-hívás', 'sdh-sales'), 'leiras' => __('Kérdésed van a {termeknev} használatáról? Beszéljük meg.', 'sdh-sales'), 'hossz' => 30, 'puffer' => 10, 'aktiv' => true, 'nyilvanos' => true],
        ];
    }

    /** @return array<string, array{nev: string, leiras: string, hossz: int, puffer: int, aktiv: bool, nyilvanos: bool}> */
    public static function tipusok(bool $csak_nyilvanos = false): array
    {
        $ki     = [];
        $tomben = SDH_Sales::b('naptar.tipusok', []);

        foreach (self::tipus_alap() as $k => $a) {
            $f = SDH_Sales::b('naptar.tipus.' . $k, []);
            $t = (is_array($tomben[$k] ?? null) ? $tomben[$k] : []) + (is_array($f) ? $f : []) + $a;

            $t = [
                'nev'       => SDH_Sales::t((string) $t['nev']),
                'leiras'    => SDH_Sales::t((string) $t['leiras']),
                'hossz'     => max(10, min(480, (int) $t['hossz'])),
                'puffer'    => max(0, min(240, (int) $t['puffer'])),
                'aktiv'     => (bool) $t['aktiv'],
                'nyilvanos' => (bool) $t['nyilvanos'],
            ];

            if (!$t['aktiv'] || ($csak_nyilvanos && !$t['nyilvanos'])) {
                continue;
            }

            $ki[$k] = $t;
        }

        return $ki;
    }

    /** @return array{napok: array<int, int>, nyit: string, zar: string, lepes: int, min_ora: int, max_nap: int, kapacitas: int, lemondas_ora: int, unnep: bool, zarolt: array<int, string>} */
    public static function beallitas(): array
    {
        $ora = static function ($v, string $alap): string {
            return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1 ? $v : $alap;
        };

        $napok = SDH_Sales::b('naptar.napok', [1, 2, 3, 4, 5]);
        $napok = array_values(array_unique(array_filter(array_map('intval', is_array($napok) ? $napok : []), static fn (int $n): bool => $n >= 1 && $n <= 7)));
        $z     = SDH_Sales::b('naptar.zarolt', []);
        $z     = is_array($z) ? $z : preg_split('/[\s,;]+/', (string) $z);

        return [
            'napok'        => $napok,
            'nyit'         => $ora(SDH_Sales::b('naptar.nyit', '09:00'), '09:00'),
            'zar'          => $ora(SDH_Sales::b('naptar.zar', '17:00'), '17:00'),
            'lepes'        => max(5, min(120, (int) SDH_Sales::b('naptar.lepes', 30))),
            'min_ora'      => max(0, (int) SDH_Sales::b('naptar.min_ora', 12)),
            'max_nap'      => max(1, min(365, (int) SDH_Sales::b('naptar.max_nap', 30))),
            'kapacitas'    => max(1, (int) SDH_Sales::b('naptar.kapacitas', 1)),
            'lemondas_ora' => max(0, (int) SDH_Sales::b('naptar.lemondas_ora', 2)),
            'unnep'        => (bool) SDH_Sales::b('naptar.unnepnapok', true),
            'zarolt'       => array_values(array_filter(array_map('trim', (array) $z))),
        ];
    }

    /* =================================================================
     * Ünnepnapok, zárolt napok
     * ============================================================== */

    /** Húsvétvasárnap (Gauss / Meeus–Jones–Butcher, a calendar kiterjesztés nélkül). */
    private static function husvet(int $ev): DateTimeImmutable
    {
        $a = $ev % 19;
        $b = intdiv($ev, 100);
        $c = $ev % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $ho = intdiv($h + $l - 7 * $m + 114, 31);
        $nap = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $ev, $ho, $nap), self::tz());
    }

    /** Magyar munkaszüneti napok egy évben (Y-m-d => true). @return array<string, bool> */
    public static function unnepnapok(int $ev): array
    {
        $h  = self::husvet($ev);
        $ki = [];

        foreach (['01-01', '03-15', '05-01', '08-20', '10-23', '11-01', '12-24', '12-25', '12-26'] as $md) {
            $ki[$ev . '-' . $md] = true;
        }

        foreach (['-2 days', '+1 day', '+50 days'] as $elt) {
            $ki[$h->modify($elt)->format('Y-m-d')] = true;
        }

        return (array) apply_filters('sdh_sales_unnepnapok', $ki, $ev);
    }

    public static function zarolt_nap(string $nap, ?array $b = null): bool
    {
        $b = $b ?? self::beallitas();

        if ($b['unnep'] && isset(self::unnepnapok((int) substr($nap, 0, 4))[$nap])) {
            return true;
        }

        foreach ($b['zarolt'] as $z) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s*(?:\.\.|–|-{2}|\/)\s*(\d{4}-\d{2}-\d{2}))?$/u', (string) $z, $m) !== 1) {
                continue;
            }

            if ($nap === $m[1] || (!empty($m[2]) && $nap >= $m[1] && $nap <= $m[2])) {
                return true;
            }
        }

        return false;
    }

    /* =================================================================
     * Szabad sávok
     * ============================================================== */

    private static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function ts(string $utc): int
    {
        return (int) strtotime($utc . ' UTC');
    }

    /**
     * A [tol, ig) UTC-időszakba belelógó élő foglalások (puffer nélkül).
     *
     * @return array<int, array{id: int, tipus: string, k: int, v: int}>
     */
    private static function foglaltak(int $tol, int $ig, int $kiveve_id = 0): array
    {
        global $wpdb;

        $sorok = $wpdb->get_results($wpdb->prepare(
            'SELECT id, tipus, kezdet, veg FROM ' . SDH_Sales_Schema::t('foglalas') . " WHERE allapot = 'foglalt' AND kezdet < %s AND veg > %s AND id <> %d",
            self::utc($ig),
            self::utc($tol),
            $kiveve_id
        ), ARRAY_A);

        return array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'tipus' => (string) $s['tipus'], 'k' => self::ts((string) $s['kezdet']), 'v' => self::ts((string) $s['veg'])], (array) $sorok);
    }

    /**
     * Szabad sávok helyi naponként a [tol, ig] napokra (helyi dátum, mindkettő benne), a foglalható ablakra vágva.
     * Egy sáv akkor szabad, ha a [kezdet − puffer, vég + puffer) intervallum kevesebb élő foglalással fedi át egymást
     * (a meglévők pufferét is beleszámolva), mint a kapacitás.
     *
     * @return array<string, array<int, array{kezdet: string, utc: string, ido: string, veg: string}>>
     */
    public static function savok(string $tipus, string $tol, string $ig, ?int $most = null, int $kiveve_id = 0): array
    {
        $tipusok = self::tipusok();

        if (!isset($tipusok[$tipus])) {
            return [];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tol) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $ig) !== 1) {
            return [];
        }

        $b    = self::beallitas();
        $t    = $tipusok[$tipus];
        $tz   = self::tz();
        $most = $most ?? time();
        $elso = $most + $b['min_ora'] * HOUR_IN_SECONDS;
        $ma   = (new DateTimeImmutable('@' . $most))->setTimezone($tz)->setTime(0, 0);
        $vege = $ma->modify('+' . $b['max_nap'] . ' days');

        $kezd = new DateTimeImmutable($tol, $tz);
        $zaro = new DateTimeImmutable($ig, $tz);
        $kezd = $kezd < $ma ? $ma : $kezd;
        $zaro = $zaro > $vege ? $vege : $zaro;

        if ($zaro < $kezd || (int) $kezd->diff($zaro)->days > 62) {
            $zaro = $kezd > $zaro ? $kezd : $kezd->modify('+62 days');
        }

        if ($zaro < $kezd) {
            return [];
        }

        $max_puffer = 0;

        foreach ($tipusok as $x) {
            $max_puffer = max($max_puffer, $x['puffer']);
        }

        $ablak_tol = $kezd->getTimestamp() - DAY_IN_SECONDS;
        $ablak_ig  = $zaro->modify('+2 days')->getTimestamp();
        $foglalt   = self::foglaltak($ablak_tol - $max_puffer * 60, $ablak_ig + $max_puffer * 60, $kiveve_id);

        [$no, $np] = array_map('intval', explode(':', $b['nyit']));
        [$zo, $zp] = array_map('intval', explode(':', $b['zar']));

        $hossz = $t['hossz'] * 60;
        $ki    = [];

        for ($nap = $kezd; $nap <= $zaro; $nap = $nap->modify('+1 day')) {
            $napstr = $nap->format('Y-m-d');

            if (!in_array((int) $nap->format('N'), $b['napok'], true) || self::zarolt_nap($napstr, $b)) {
                continue;
            }

            // A helyi falióra-idő minden nap külön számolva: a DST-váltás hetében is 9:00 a 9:00.
            $nyit = $nap->setTime($no, $np)->getTimestamp();
            $zar  = $nap->setTime($zo, $zp)->getTimestamp();
            $sav  = [];

            for ($k = $nyit; $k + $hossz <= $zar; $k += $b['lepes'] * 60) {
                if ($k < $elso) {
                    continue;
                }

                $v  = $k + $hossz;
                $db = 0;

                foreach ($foglalt as $f) {
                    $p = max($t['puffer'], $tipusok[$f['tipus']]['puffer'] ?? $t['puffer']) * 60;

                    if ($k - $p < $f['v'] && $v + $p > $f['k']) {
                        $db++;
                    }
                }

                if ($db >= $b['kapacitas']) {
                    continue;
                }

                $helyi = (new DateTimeImmutable('@' . $k))->setTimezone($tz);
                $sav[] = [
                    'kezdet' => $helyi->format('c'),
                    'utc'    => gmdate('Y-m-d\TH:i:s\Z', $k),
                    'ido'    => $helyi->format('H:i'),
                    'veg'    => (new DateTimeImmutable('@' . $v))->setTimezone($tz)->format('H:i'),
                ];
            }

            if ($sav !== []) {
                $ki[$napstr] = $sav;
            }
        }

        return $ki;
    }

    /* =================================================================
     * Foglalás
     * ============================================================== */

    private static function zar(string $nev, int $mp = 30): bool
    {
        global $wpdb;

        $t     = SDH_Sales_Schema::t('korlat');
        $k     = 'zar:' . $nev;
        $proba = max(1, (int) apply_filters('sdh_sales_zar_proba', 40, $nev));

        for ($i = 0; $i < $proba; $i++) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE kulcs = %s AND lejar < %s", $k, gmdate('Y-m-d H:i:s')));
            $elnyom = $wpdb->suppress_errors(true);
            $ok     = $wpdb->insert($t, ['kulcs' => $k, 'db' => 1, 'lejar' => gmdate('Y-m-d H:i:s', time() + $mp)]);
            $wpdb->suppress_errors($elnyom);

            if ($ok) {
                return true;
            }

            usleep(100000);
        }

        return false;
    }

    private static function elenged(string $nev): void
    {
        global $wpdb;

        $wpdb->delete(SDH_Sales_Schema::t('korlat'), ['kulcs' => 'zar:' . $nev]);
    }

    /**
     * A kezelő (lemondó / átfoglaló) link tokenje: „<id>-<HMAC>”. Az adatbázisban csak a véletlen nonce áll (token mező),
     * a HMAC kulcsa a wp-config sója – adatbázis-szivárgásból nem állítható elő, a szerver bármikor újra kiszámolja.
     */
    public static function token_kesz(array $f): string
    {
        return (int) $f['id'] . '-' . substr(hash_hmac('sha256', 'fogl|' . (int) $f['id'] . '|' . (string) $f['token'], wp_salt('auth') . 'sdh-fogl-t'), 0, 32);
    }

    /** @return array<string, mixed>|null */
    public static function egy(int $id): ?array
    {
        global $wpdb;

        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('foglalas') . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($r) ? $r : null;
    }

    /** @return array<string, mixed>|null */
    public static function tokennel(string $token): ?array
    {
        global $wpdb;

        if (preg_match('/^(\d{1,18})-([a-f0-9]{32})$/', trim($token), $m) !== 1) {
            return null;
        }

        $r = self::egy((int) $m[1]);

        return $r !== null && (string) $r['token'] !== '' && hash_equals(self::token_kesz($r), trim($token)) ? $r : null;
    }

    private static function rendeles_token(int $rendeles_id, string $tipus): string
    {
        return substr(hash_hmac('sha256', 'fogl-ajanlat|' . $rendeles_id . '|' . $tipus, wp_salt('auth') . 'sdh-fogl'), 0, 24);
    }

    /** @return array<string, mixed>|null a rendelés, ha a link tokenje stimmel */
    private static function link_rendeles(string $azon, string $tipus, string $rt): ?array
    {
        global $wpdb;

        if ($azon === '' || strlen($rt) !== 24) {
            return null;
        }

        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE azon = %s', $azon), ARRAY_A);

        if (!is_array($r) || !hash_equals(self::rendeles_token((int) $r['id'], $tipus), $rt)) {
            return null;
        }

        return $r;
    }

    private static function hiba(string $uzenet, int $status = 422, string $kod = 'sdh_foglalas'): WP_Error
    {
        return new WP_Error($kod, $uzenet, ['status' => $status]);
    }

    /**
     * Új foglalás (vagy átfoglalás: `atfoglal` = a régi foglalás tokenje).
     * Bemenet: tipus, kezdet (UTC ISO a sávból), nev, email, telefon, ceg, megjegyzes, rendeles + rt (ajánlat-link).
     *
     * @param array<string, mixed> $be
     * @return array{id: int, token: string, kezdet: string, veg: string, tipus: string}|WP_Error
     */
    public static function foglal(array $be, ?int $most = null)
    {
        global $wpdb;

        $s     = static fn (string $k, int $max): string => isset($be[$k]) && is_scalar($be[$k]) ? mb_substr(trim(sanitize_text_field((string) $be[$k])), 0, $max) : '';
        $most  = $most ?? time();
        $regi  = null;
        $tipus = $s('tipus', 20);

        if ($s('atfoglal', 80) !== '') {
            $regi = self::tokennel($s('atfoglal', 80));

            if ($regi === null || !self::lemondhato($regi, $most)) {
                return self::hiba(__('Ez a foglalás már nem módosítható. Foglalj új időpontot.', 'sdh-sales'));
            }

            $tipus = (string) $regi['tipus'];
        }

        $tipusok = self::tipusok();

        if (!isset($tipusok[$tipus])) {
            return self::hiba(__('Válassz időponttípust.', 'sdh-sales'));
        }

        $rendeles = null;

        if ($regi !== null) {
            $rendeles_id = (int) $regi['rendeles_id'];
        } else {
            $rendeles    = self::link_rendeles($s('rendeles', 40), $tipus, $s('rt', 40));
            $rendeles_id = $rendeles !== null ? (int) $rendeles['id'] : 0;

            if (!$tipusok[$tipus]['nyilvanos'] && $rendeles === null) {
                return self::hiba(__('Ezt az időpontot a rendelésed után kapott levélben lévő linkkel tudod lefoglalni.', 'sdh-sales'), 403);
            }
        }

        $nev     = $regi !== null ? (string) $regi['nev'] : $s('nev', 190);
        $email   = $regi !== null ? (string) $regi['email'] : sanitize_email($s('email', 190));
        $telefon = $regi !== null ? (string) $regi['telefon'] : $s('telefon', 60);
        $ceg     = $regi !== null ? (string) $regi['ceg'] : $s('ceg', 190);
        $megj    = $regi !== null ? (string) $regi['megjegyzes'] : (isset($be['megjegyzes']) && is_scalar($be['megjegyzes']) ? mb_substr(trim(sanitize_textarea_field((string) $be['megjegyzes'])), 0, 1500) : '');

        if ($s('weboldal', 200) !== '') {
            return self::hiba(__('A foglalás nem sikerült. Próbáld újra.', 'sdh-sales'), 400);
        }

        if (mb_strlen($nev) < 2) {
            return self::hiba(__('Add meg a neved.', 'sdh-sales'));
        }

        if (!is_email($email)) {
            return self::hiba(__('Adj meg egy működő e-mail-címet – ide küldjük a visszaigazolást.', 'sdh-sales'));
        }

        if ($telefon !== '' && strlen((string) preg_replace('/\D/', '', $telefon)) < 7) {
            return self::hiba(__('A telefonszám hiányos.', 'sdh-sales'));
        }

        $k_ts = strtotime($s('kezdet', 40));

        if ($k_ts === false || $k_ts <= 0) {
            return self::hiba(__('Válassz időpontot.', 'sdh-sales'));
        }

        $tz     = self::tz();
        $napstr = (new DateTimeImmutable('@' . $k_ts))->setTimezone($tz)->format('Y-m-d');
        $v_ts   = $k_ts + $tipusok[$tipus]['hossz'] * 60;

        if ($regi === null && !SDH_Sales_Rest::korlat('foglalas_email', 5, DAY_IN_SECONDS, strtolower($email))) {
            return self::hiba(__('Erről a címről ma már sok foglalás érkezett. Írj nekünk, és egyeztetünk.', 'sdh-sales'), 429);
        }

        // Egyszerre csak egy folyamat dönthet az időpontokról (adatbázis-egyediség a korlat tábla kulcsán).
        if (!self::zar('foglalas')) {
            return self::hiba(__('Most sokan foglalnak. Próbáld újra pár másodperc múlva.', 'sdh-sales'), 503);
        }

        try {
            $szabad = false;

            foreach (self::savok($tipus, $napstr, $napstr, $most, $regi !== null ? (int) $regi['id'] : 0)[$napstr] ?? [] as $sav) {
                if (strtotime($sav['utc']) === $k_ts) {
                    $szabad = true;
                    break;
                }
            }

            if (!$szabad) {
                return self::hiba(__('Ezt az időpontot közben lefoglalták, vagy már nem foglalható. Válassz másikat.', 'sdh-sales'), 409);
            }

            $eml   = $k_ts - $most <= HOUR_IN_SECONDS ? self::EML_1 : ($k_ts - $most <= DAY_IN_SECONDS ? self::EML_24 : 0);

            $ok = $wpdb->insert(SDH_Sales_Schema::t('foglalas'), [
                'tipus'              => $tipus,
                'kezdet'             => self::utc($k_ts),
                'veg'                => self::utc($v_ts),
                'nev'                => $nev,
                'email'              => $email,
                'telefon'            => $telefon,
                'ceg'                => $ceg,
                'megjegyzes'         => $megj,
                'allapot'            => 'foglalt',
                'token'              => SDH_Sales::token(24),
                'rendeles_id'        => $rendeles_id,
                'google_id'          => '',
                'emlekezteto_kuldve' => $eml,
                'ido'                => self::utc($most),
            ]);

            if (!$ok) {
                return self::hiba(__('A foglalás nem sikerült. Próbáld újra.', 'sdh-sales'), 500);
            }

            $id = (int) $wpdb->insert_id;

            if ($regi !== null) {
                $wpdb->update(SDH_Sales_Schema::t('foglalas'), ['allapot' => 'atfoglalva'], ['id' => (int) $regi['id']]);
            }
        } finally {
            self::elenged('foglalas');
        }

        $f     = self::egy($id);
        $token = self::token_kesz($f);

        SDH_Sales_Naplo::ir('foglalas', ($regi !== null ? 'Átfoglalás' : 'Új foglalás') . ': ' . $tipusok[$tipus]['nev'] . ', ' . self::mikor((string) $f['kezdet']) . ' – ' . $nev, 'info', 'foglalas', $id);

        self::level_foglalas($f, $token, $regi);
        self::ertesit(($regi !== null ? 'Átfoglalt időpont' : 'Új időpontfoglalás') . ': ' . $tipusok[$tipus]['nev'], $f);
        do_action('sdh_sales_foglalas_letrehozva', $id, $regi !== null ? (int) $regi['id'] : 0);

        return ['id' => $id, 'token' => $token, 'kezdet' => gmdate('Y-m-d\TH:i:s\Z', $k_ts), 'veg' => gmdate('Y-m-d\TH:i:s\Z', $v_ts), 'tipus' => $tipus];
    }

    public static function lemondhato(array $f, ?int $most = null): bool
    {
        $b = self::beallitas();

        return (string) $f['allapot'] === 'foglalt' && self::ts((string) $f['kezdet']) - $b['lemondas_ora'] * HOUR_IN_SECONDS > ($most ?? time());
    }

    /** Lemondás a token alapján (ügyfél) – vagy adminként (`$ki` = 'admin', ilyenkor a határidő nem számít). @return true|WP_Error */
    public static function lemond(string $token, ?int $most = null, string $ki = 'ugyfel')
    {
        $f = self::tokennel($token);

        return $f === null ? self::hiba(__('Nincs ilyen foglalás.', 'sdh-sales'), 404) : self::lemond_sor($f, $most, $ki);
    }

    /** @return true|WP_Error */
    private static function lemond_sor(array $f, ?int $most, string $ki)
    {
        global $wpdb;

        if ((string) $f['allapot'] !== 'foglalt') {
            return self::hiba(__('Ezt a foglalást már lemondták vagy módosították.', 'sdh-sales'), 409);
        }

        if ($ki !== 'admin' && !self::lemondhato($f, $most)) {
            return self::hiba(sprintf(__('Lemondani legkésőbb %d órával az időpont előtt lehet. Írj nekünk, és megoldjuk.', 'sdh-sales'), self::beallitas()['lemondas_ora']), 409);
        }

        $n = $wpdb->query($wpdb->prepare('UPDATE ' . SDH_Sales_Schema::t('foglalas') . " SET allapot = 'lemondva' WHERE id = %d AND allapot = 'foglalt'", (int) $f['id']));

        if ((int) $n !== 1) {
            return self::hiba(__('Ezt a foglalást már lemondták.', 'sdh-sales'), 409);
        }

        $f['allapot'] = 'lemondva';

        SDH_Sales_Naplo::ir('foglalas', 'Lemondva (' . ($ki === 'admin' ? 'admin' : 'ügyfél') . '): ' . self::mikor((string) $f['kezdet']) . ' – ' . (string) $f['nev'], 'figyel', 'foglalas', (int) $f['id']);

        self::level('foglalas_lemondas', (string) $f['email'], self::tokenek($f, ''), [['nev' => 'lemondas.ics', 'tartalom' => self::ics($f, 'CANCEL')]]);
        self::ertesit('Lemondott időpont', $f);
        do_action('sdh_sales_foglalas_lemondva', (int) $f['id'], $ki);

        return true;
    }

    /* =================================================================
     * Ajánlat-link a rendeléshez (a kiszolgálási lánc `idopont` lépése)
     * ============================================================== */

    public static function oldal_url(): string
    {
        $u = trim((string) SDH_Sales::b('naptar.oldal', ''));

        if ($u === '') {
            $p = get_page_by_path('idopontfoglalas');
            $u = $p instanceof WP_Post ? (string) get_permalink($p) : home_url('/idopontfoglalas/');
        }

        return (string) apply_filters('sdh_sales_foglalas_oldal', $u);
    }

    public static function ajanlat_link(int $rendeles_id, string $tipus = 'telepites'): string
    {
        global $wpdb;

        $tipus = array_key_exists($tipus, self::tipus_alap()) ? $tipus : 'telepites';
        $azon  = (string) $wpdb->get_var($wpdb->prepare('SELECT azon FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE id = %d', $rendeles_id));

        if ($azon === '') {
            return '';
        }

        return add_query_arg(['tipus' => $tipus, 'rendeles' => rawurlencode($azon), 'rt' => self::rendeles_token($rendeles_id, $tipus)], self::oldal_url()) . '#foglalas';
    }

    public static function ajanlat_kuld(int $rendeles_id, string $tipus = 'telepites'): bool
    {
        global $wpdb;

        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE id = %d', $rendeles_id), ARRAY_A);

        if (!is_array($r)) {
            return false;
        }

        $vevo  = json_decode((string) $r['vevo'], true);
        $vevo  = is_array($vevo) ? $vevo : [];
        $email = (string) ($r['email'] ?: ($vevo['email'] ?? ''));

        if (!is_email($email)) {
            return false;
        }

        $t = self::tipusok()[$tipus] ?? self::tipusok()['telepites'] ?? ['nev' => $tipus];

        return self::level('idopont_ajanlat', $email, [
            '{nev}'           => (string) ($vevo['kapcsolattarto'] ?? $vevo['nev'] ?? ''),
            '{rendeles}'      => (string) $r['azon'],
            '{tipus}'         => (string) $t['nev'],
            '{foglalas_url}'  => self::ajanlat_link($rendeles_id, $tipus),
        ]);
    }

    /* =================================================================
     * Formázás, levelek
     * ============================================================== */

    /** „2026. október 26. (hétfő) 9:00” – UTC adatbázis-időből, helyi időben. */
    public static function mikor(string $utc): string
    {
        $d = (new DateTimeImmutable('@' . self::ts($utc)))->setTimezone(self::tz());

        return $d->format('Y. ') . self::HONAPOK[(int) $d->format('n')] . ' ' . $d->format('j.') . ' (' . self::NAPOK[(int) $d->format('N')] . ') ' . $d->format('G:i');
    }

    public static function kezelo_url(string $token): string
    {
        return add_query_arg(['foglalas' => $token], self::oldal_url()) . '#foglalas';
    }

    public static function ics_url(string $token): string
    {
        return add_query_arg(['t' => $token], rest_url(SDH_Sales_Rest::NS . '/foglalas/ics'));
    }

    /** @return array<string, string> */
    private static function tokenek(array $f, string $token): array
    {
        $t = self::tipusok()[(string) $f['tipus']] ?? ['nev' => (string) $f['tipus'], 'hossz' => 0];

        return [
            '{nev}'            => (string) $f['nev'],
            '{tipus}'          => (string) $t['nev'],
            '{idopont}'        => self::mikor((string) $f['kezdet']),
            '{idotartam}'      => (string) (int) round((self::ts((string) $f['veg']) - self::ts((string) $f['kezdet'])) / 60) . ' perc',
            '{helyszin}'       => SDH_Sales::t((string) SDH_Sales::b('naptar.helyszin', __('Online – a részleteket e-mailben küldjük', 'sdh-sales'))),
            '{lemondas_url}'   => $token !== '' ? self::kezelo_url($token) : '',
            '{atfoglalas_url}' => $token !== '' ? self::kezelo_url($token) : '',
            '{ics_url}'        => $token !== '' ? self::ics_url($token) : '',
            '{foglalas_url}'   => self::oldal_url(),
            '{megjegyzes}'     => (string) $f['megjegyzes'],
        ];
    }

    /** Beépített levélszövegek (ha az e-mail modul / sablon nincs meg). @return array{targy: string, html: string} */
    private static function alap_level(string $sablon): array
    {
        $l = [
            'foglalas_visszaigazolas' => [
                'targy' => __('Időpontod rögzítve: {tipus}, {idopont}', 'sdh-sales'),
                'html'  => __('<p>Kedves {nev}!</p><p>Rögzítettük az időpontodat.</p><p><strong>{tipus}</strong><br>{idopont} ({idotartam})<br>{helyszin}</p><p>A csatolt naptárfájllal (.ics) felveheted a saját naptáradba.</p><p>Ha mégsem jó, itt lemondhatod vagy áthelyezheted: <a href="{lemondas_url}">{lemondas_url}</a></p><p>Üdv,<br>{ceg}</p>', 'sdh-sales'),
            ],
            'foglalas_emlekezteto' => [
                'targy' => __('Emlékeztető: {tipus}, {idopont}', 'sdh-sales'),
                'html'  => __('<p>Kedves {nev}!</p><p>Emlékeztetünk a közös időpontra: <strong>{tipus}</strong>, {idopont} ({idotartam}).</p><p>{helyszin}</p><p>Ha közbejött valami: <a href="{lemondas_url}">lemondás vagy áthelyezés</a>.</p><p>Üdv,<br>{ceg}</p>', 'sdh-sales'),
            ],
            'foglalas_lemondas' => [
                'targy' => __('Lemondva: {tipus}, {idopont}', 'sdh-sales'),
                'html'  => __('<p>Kedves {nev}!</p><p>A(z) {idopont} időpontot ({tipus}) lemondtuk. Új időpontot itt foglalhatsz: <a href="{foglalas_url}">{foglalas_url}</a></p><p>Üdv,<br>{ceg}</p>', 'sdh-sales'),
            ],
            'idopont_ajanlat' => [
                'targy' => __('Válassz időpontot: {tipus} ({rendeles})', 'sdh-sales'),
                'html'  => __('<p>Kedves {nev}!</p><p>Köszönjük a rendelésed ({rendeles}). Válaszd ki, mikor legyen a(z) {tipus} – a link a rendelésedhez kötött:</p><p><a href="{foglalas_url}">{foglalas_url}</a></p><p>Üdv,<br>{ceg}</p>', 'sdh-sales'),
            ],
        ];

        return $l[$sablon] ?? ['targy' => '{termeknev}', 'html' => ''];
    }

    /**
     * Levél: az e-mail modul sablonjával (SDH_Sales_Email::kuld), ha van; különben a beépített szöveggel, wp_mail-lel.
     *
     * @param array<string, string>                              $tokenek
     * @param array<int, array{nev: string, tartalom: string}>   $csatolmany
     */
    public static function level(string $sablon, string $cimzett, array $tokenek, array $csatolmany = []): bool
    {
        if (!is_email($cimzett)) {
            return false;
        }

        $fajlok = [];
        $mappa  = trailingslashit(get_temp_dir()) . 'sdh-sales-' . wp_generate_password(12, false);

        foreach ($csatolmany as $c) {
            if (!is_dir($mappa)) {
                wp_mkdir_p($mappa);
            }

            $ut = $mappa . '/' . sanitize_file_name($c['nev']);
            file_put_contents($ut, $c['tartalom']);
            $fajlok[] = $ut;
        }

        try {
            if (class_exists('SDH_Sales_Email') && method_exists('SDH_Sales_Email', 'kuld') && (bool) apply_filters('sdh_sales_naptar_email_modul', true)) {
                $ok = SDH_Sales_Email::kuld($sablon, $cimzett, $tokenek, $fajlok);

                if (is_bool($ok)) {
                    return $ok;
                }
            }

            $l       = self::alap_level($sablon);
            $esc     = array_map(static fn ($v): string => esc_html((string) $v), $tokenek);
            $targy   = wp_specialchars_decode(SDH_Sales::t($l['targy'], $tokenek), ENT_QUOTES);
            $html    = SDH_Sales::t($l['html'], $esc + ['{ceg}' => esc_html((string) SDH_Sales::b('ceg.nev', ''))]);
            $felado  = (string) SDH_Sales::b('email.felado', '');
            $fejlec  = ['Content-Type: text/html; charset=UTF-8'];

            if (is_email($felado)) {
                $fejlec[] = 'From: ' . wp_specialchars_decode(str_replace(['"', "\r", "\n"], '', (string) SDH_Sales::b('ceg.nev', SDH_Sales::termeknev())), ENT_QUOTES) . ' <' . $felado . '>';
            }

            return (bool) wp_mail($cimzett, $targy, $html, $fejlec, $fajlok);
        } finally {
            foreach ($fajlok as $f) {
                @unlink($f); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }

            if (is_dir($mappa)) {
                @rmdir($mappa); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }
    }

    private static function level_foglalas(array $f, string $token, ?array $regi): void
    {
        $tok = self::tokenek($f, $token) + ['{atfoglalas}' => $regi !== null ? '1' : '', '{regi_idopont}' => $regi !== null ? self::mikor((string) $regi['kezdet']) : ''];
        $cs  = [['nev' => 'idopont.ics', 'tartalom' => self::ics($f, 'PUBLISH')]];

        if ($regi !== null) {
            // Az áthelyezett régi időpont törlése a vevő naptárából (ugyanaz az UID, STATUS:CANCELLED).
            $regi['allapot'] = 'atfoglalva';
            $cs[]            = ['nev' => 'regi-idopont-torlese.ics', 'tartalom' => self::ics($regi, 'CANCEL')];
        }

        self::level('foglalas_visszaigazolas', (string) $f['email'], $tok, $cs);
    }

    /** Rövid értesítés Norbinak (email.ertesites). */
    private static function ertesit(string $mi, array $f): void
    {
        $cim = (string) SDH_Sales::b('email.ertesites', '');

        if (!is_email($cim)) {
            return;
        }

        $sor = [
            $mi,
            'Időpont: ' . self::mikor((string) $f['kezdet']),
            'Név: ' . (string) $f['nev'] . ((string) $f['ceg'] !== '' ? ' (' . (string) $f['ceg'] . ')' : ''),
            'E-mail: ' . (string) $f['email'],
            (string) $f['telefon'] !== '' ? 'Telefon: ' . (string) $f['telefon'] : '',
            (string) $f['megjegyzes'] !== '' ? "Megjegyzés:\n" . (string) $f['megjegyzes'] : '',
        ];

        wp_mail($cim, '[' . SDH_Sales::termeknev() . '] ' . $mi . ' – ' . self::mikor((string) $f['kezdet']), implode("\n", array_filter($sor)));
    }

    /* =================================================================
     * ICS (RFC 5545: CRLF, 75 oktettes hajtogatás, UID, DTSTAMP, UTC idők)
     * ============================================================== */

    public static function ics_szoveg(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', ';' => '\\;', ',' => '\\,', "\r\n" => '\\n', "\n" => '\\n', "\r" => '\\n']);
    }

    /** Egy tartalomsor hajtogatása 75 oktetenként (UTF-8 karaktert nem vág ketté). */
    public static function ics_sor(string $sor): string
    {
        $ki    = '';
        $hossz = 0;
        $max   = 75;

        foreach (preg_split('//u', $sor, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $b = strlen($ch);

            if ($hossz + $b > $max) {
                $ki   .= "\r\n ";
                $hossz = 1;
            }

            $ki    .= $ch;
            $hossz += $b;
        }

        return $ki . "\r\n";
    }

    private static function ics_ido(string $utc): string
    {
        return gmdate('Ymd\THis\Z', self::ts($utc));
    }

    private static function ics_esemeny(array $f, string $metodus, bool $feed = false): string
    {
        $t      = self::tipusok()[(string) $f['tipus']] ?? ['nev' => (string) $f['tipus']];
        $host   = (string) (wp_parse_url(home_url(), PHP_URL_HOST) ?: 'localhost');
        $torolt = $metodus === 'CANCEL' || in_array((string) $f['allapot'], ['lemondva', 'atfoglalva'], true);
        $leiras = $feed
            ? implode("\n", array_filter([(string) $f['nev'] . ((string) $f['ceg'] !== '' ? ' – ' . (string) $f['ceg'] : ''), (string) $f['email'], (string) $f['telefon'], (string) $f['megjegyzes']]))
            : SDH_Sales::t((string) SDH_Sales::b('naptar.ics_leiras', '{termeknev} – {ceg}'));
        $osszeg = $feed ? (string) $t['nev'] . ': ' . (string) $f['nev'] : (string) $t['nev'] . ' – ' . SDH_Sales::termeknev();
        $sorok  = [
            'BEGIN:VEVENT',
            'UID:sdh-foglalas-' . (int) $f['id'] . '-' . substr(hash('sha256', (string) $f['id'] . '|' . wp_salt('nonce')), 0, 12) . '@' . $host,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . self::ics_ido((string) $f['kezdet']),
            'DTEND:' . self::ics_ido((string) $f['veg']),
            'SUMMARY:' . self::ics_szoveg($osszeg),
            'DESCRIPTION:' . self::ics_szoveg($leiras),
            'LOCATION:' . self::ics_szoveg(SDH_Sales::t((string) SDH_Sales::b('naptar.helyszin', __('Online – a részleteket e-mailben küldjük', 'sdh-sales')))),
            'STATUS:' . ($torolt ? 'CANCELLED' : 'CONFIRMED'),
            'SEQUENCE:' . ($torolt ? 1 : 0),
            'TRANSP:OPAQUE',
        ];

        $szervezo = (string) SDH_Sales::b('ceg.email', '');

        if (is_email($szervezo)) {
            $sorok[] = 'ORGANIZER;CN="' . str_replace(['"', ';', ':', ','], '', (string) SDH_Sales::b('ceg.nev', SDH_Sales::termeknev())) . '":mailto:' . $szervezo;
        }

        if (!$torolt && !$feed) {
            array_push($sorok, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::ics_szoveg($osszeg), 'TRIGGER:-PT30M', 'END:VALARM');
        }

        $sorok[] = 'END:VEVENT';

        return implode('', array_map([self::class, 'ics_sor'], $sorok));
    }

    private static function ics_keret(string $esemenyek, string $metodus, string $nev = ''): string
    {
        $fej = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//' . self::ics_szoveg(SDH_Sales::termeknev()) . '//Idopontfoglalas//HU',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $metodus,
        ];

        if ($nev !== '') {
            $fej[] = 'X-WR-CALNAME:' . self::ics_szoveg($nev);
            $fej[] = 'X-WR-TIMEZONE:' . self::TZ;
            $fej[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
            $fej[] = 'X-PUBLISHED-TTL:PT1H';
        }

        return implode('', array_map([self::class, 'ics_sor'], $fej)) . $esemenyek . self::ics_sor('END:VCALENDAR');
    }

    public static function ics(array $f, string $metodus = 'PUBLISH'): string
    {
        $metodus = $metodus === 'CANCEL' ? 'CANCEL' : 'PUBLISH';

        return self::ics_keret(self::ics_esemeny($f, $metodus), $metodus);
    }

    /** Titkos iCal-feed: az elmúlt 30 nap és a jövő foglalásai (a lemondottak CANCELLED-ként). */
    public static function ical_feed(): string
    {
        global $wpdb;

        $sorok = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SDH_Sales_Schema::t('foglalas') . " WHERE kezdet >= %s AND allapot IN ('foglalt','lemondva') ORDER BY kezdet LIMIT 2000",
            self::utc(time() - 30 * DAY_IN_SECONDS)
        ), ARRAY_A);

        $e = '';

        foreach ((array) $sorok as $f) {
            $e .= self::ics_esemeny($f, 'PUBLISH', true);
        }

        return self::ics_keret($e, 'PUBLISH', SDH_Sales::termeknev() . ' – foglalások');
    }

    private static function ical_token(bool $uj = false): string
    {
        $t = SDH_Sales::titkos('naptar_ical_token');

        if ($t === '' || $uj) {
            $t = SDH_Sales::token(24);
            SDH_Sales::titkos_ment('naptar_ical_token', $t);
        }

        return $t;
    }

    public static function ical_url(): string
    {
        return add_query_arg(['t' => self::ical_token()], rest_url(SDH_Sales_Rest::NS . '/naptar/ical'));
    }

    /* =================================================================
     * Emlékeztetők, cron
     * ============================================================== */

    public static function cron(): void
    {
        self::emlekezteto();
        self::google_szinkron();
    }

    /** 24 és 1 órás emlékeztető; mindegyik pontosan egyszer (feltételes UPDATE dönt). Visszaadja a kiküldöttek számát. */
    public static function emlekezteto(?int $most = null): int
    {
        global $wpdb;

        $most = $most ?? time();
        $t    = SDH_Sales_Schema::t('foglalas');
        $db   = 0;

        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE allapot = 'foglalt' AND emlekezteto_kuldve < %d AND kezdet > %s AND kezdet <= %s ORDER BY kezdet LIMIT 100",
            self::EML_1,
            self::utc($most),
            self::utc($most + DAY_IN_SECONDS)
        ), ARRAY_A);

        foreach ((array) $sorok as $f) {
            $hatra = self::ts((string) $f['kezdet']) - $most;
            $cel   = $hatra <= HOUR_IN_SECONDS ? self::EML_1 : self::EML_24;

            if ((int) $f['emlekezteto_kuldve'] >= $cel) {
                continue;
            }

            $n = $wpdb->query($wpdb->prepare("UPDATE {$t} SET emlekezteto_kuldve = %d WHERE id = %d AND emlekezteto_kuldve < %d AND allapot = 'foglalt'", $cel, (int) $f['id'], $cel));

            if ((int) $n !== 1) {
                continue;
            }

            self::level('foglalas_emlekezteto', (string) $f['email'], self::tokenek($f, self::token_kesz($f)) + ['{mikor}' => $cel === self::EML_1 ? __('egy órán belül', 'sdh-sales') : __('holnap', 'sdh-sales')]);
            $db++;
        }

        return $db;
    }

    /* =================================================================
     * Google Calendar API (opcionális, alapból ki)
     * ============================================================== */

    public static function google_be(): bool
    {
        return (bool) SDH_Sales::b('naptar.google.be', false)
            && (string) SDH_Sales::b('naptar.google.client_id', '') !== ''
            && SDH_Sales::titkos('google_client_secret') !== ''
            && SDH_Sales::titkos('google_refresh_token') !== '';
    }

    private static function google_token(): string
    {
        $t = get_transient('sdh_sales_google_at');

        if (is_string($t) && $t !== '') {
            return $t;
        }

        $v = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'client_id'     => (string) SDH_Sales::b('naptar.google.client_id', ''),
                'client_secret' => SDH_Sales::titkos('google_client_secret'),
                'refresh_token' => SDH_Sales::titkos('google_refresh_token'),
                'grant_type'    => 'refresh_token',
            ],
        ]);

        $d = is_wp_error($v) ? null : json_decode((string) wp_remote_retrieve_body($v), true);

        if (!is_array($d) || empty($d['access_token'])) {
            SDH_Sales_Naplo::ir('foglalas', 'Google Naptár: a hozzáférési token nem kérhető le (' . (is_wp_error($v) ? $v->get_error_message() : (string) ($d['error'] ?? wp_remote_retrieve_response_code($v))) . ').', 'hiba');

            return '';
        }

        set_transient('sdh_sales_google_at', (string) $d['access_token'], max(60, (int) ($d['expires_in'] ?? 3600) - 120));

        return (string) $d['access_token'];
    }

    /** @return array|WP_Error */
    private static function google_keres(string $metodus, string $ut, ?array $test = null)
    {
        $at = self::google_token();

        if ($at === '') {
            return new WP_Error('sdh_google', 'Nincs Google-token.');
        }

        $naptar = rawurlencode((string) SDH_Sales::b('naptar.google.naptar_id', 'primary') ?: 'primary');
        $v      = wp_remote_request('https://www.googleapis.com/calendar/v3/calendars/' . $naptar . '/events' . $ut, [
            'method'  => $metodus,
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $at, 'Content-Type' => 'application/json'],
            'body'    => $test !== null ? (string) wp_json_encode($test) : null,
        ]);

        if (is_wp_error($v)) {
            return $v;
        }

        $kod = (int) wp_remote_retrieve_response_code($v);
        $d   = json_decode((string) wp_remote_retrieve_body($v), true);

        if ($kod === 401) {
            delete_transient('sdh_sales_google_at');
        }

        return $kod >= 200 && $kod < 300 ? (is_array($d) ? $d : []) : new WP_Error('sdh_google', 'Google Naptár: HTTP ' . $kod, ['kod' => $kod]);
    }

    /** Új foglalások felküldése, lemondottak / átfoglaltak törlése a Google Naptárból (cronból, kötegben). */
    public static function google_szinkron(): int
    {
        global $wpdb;

        if (!self::google_be()) {
            return 0;
        }

        $t  = SDH_Sales_Schema::t('foglalas');
        $db = 0;

        $uj = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE allapot = 'foglalt' AND google_id = '' AND veg > %s ORDER BY kezdet LIMIT 20", self::utc(time())), ARRAY_A);

        foreach ((array) $uj as $f) {
            $tip = self::tipusok()[(string) $f['tipus']] ?? ['nev' => (string) $f['tipus']];
            $v   = self::google_keres('POST', '', [
                'summary'     => (string) $tip['nev'] . ': ' . (string) $f['nev'],
                'description' => implode("\n", array_filter([(string) $f['ceg'], (string) $f['email'], (string) $f['telefon'], (string) $f['megjegyzes']])),
                'start'       => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', self::ts((string) $f['kezdet'])), 'timeZone' => self::TZ],
                'end'         => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', self::ts((string) $f['veg'])), 'timeZone' => self::TZ],
            ]);

            if (is_wp_error($v) || empty($v['id'])) {
                SDH_Sales_Naplo::ir('foglalas', 'Google Naptár: a foglalás nem került fel (#' . (int) $f['id'] . ').', 'figyel', 'foglalas', (int) $f['id']);
                break;
            }

            $wpdb->update($t, ['google_id' => mb_substr((string) $v['id'], 0, 190)], ['id' => (int) $f['id']]);
            $db++;
        }

        $tor = $wpdb->get_results("SELECT id, google_id FROM {$t} WHERE allapot IN ('lemondva','atfoglalva') AND google_id <> '' LIMIT 20", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        foreach ((array) $tor as $f) {
            $v = self::google_keres('DELETE', '/' . rawurlencode((string) $f['google_id']));

            if (is_wp_error($v) && !in_array((int) ($v->get_error_data()['kod'] ?? 0), [404, 410], true)) {
                break;
            }

            $wpdb->update($t, ['google_id' => ''], ['id' => (int) $f['id']]);
            $db++;
        }

        return $db;
    }

    /* =================================================================
     * Front
     * ============================================================== */

    public static function front_regisztral(): void
    {
        if (!class_exists('SDH_Sales_Front')) {
            return;
        }

        SDH_Sales_Front::modul('foglalas', 'assets/front/foglalas.js', ['kozos'], static function (): array {
            $tip = [];

            foreach (self::tipusok() as $k => $t) {
                $tip[] = ['kulcs' => $k, 'nev' => $t['nev'], 'leiras' => $t['leiras'], 'hossz' => $t['hossz'], 'nyilvanos' => $t['nyilvanos']];
            }

            $b = self::beallitas();

            return [
                'rest'     => esc_url_raw(rest_url(SDH_Sales_Rest::NS)),
                'tipusok'  => $tip,
                'max_nap'  => $b['max_nap'],
                'lemondas_ora' => $b['lemondas_ora'],
                'adatvedelem' => (string) SDH_Sales::b('naptar.adatvedelem_url', get_privacy_policy_url()),
            ];
        });
    }

    /** [sdh_foglalas tipus="demo"] */
    public static function shortcode($atts): string
    {
        $a = shortcode_atts(['tipus' => 'demo'], (array) $atts, 'sdh_foglalas');

        if (class_exists('SDH_Sales_Front')) {
            SDH_Sales_Front::igeny('foglalas');
        }

        return '<div id="foglalas" data-sdh="foglalas" data-tipus="' . esc_attr(sanitize_key((string) $a['tipus'])) . '"></div>';
    }

    /* =================================================================
     * REST
     * ============================================================== */

    public static function nyers_kiszolgal($kiszolgalva, $valasz, $keres, $szerver)
    {
        if ($kiszolgalva || !($valasz instanceof WP_HTTP_Response)) {
            return $kiszolgalva;
        }

        $h = $valasz->get_headers();

        if (empty($h['X-SDH-Nyers']) || !is_string($valasz->get_data())) {
            return $kiszolgalva;
        }

        echo $valasz->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput -- ICS.

        return true;
    }

    private static function ics_valasz(string $ics, string $fajlnev, bool $letolt): WP_REST_Response
    {
        $v = new WP_REST_Response($ics, 200);
        $v->header('X-SDH-Nyers', '1');
        $v->header('Content-Type', 'text/calendar; charset=utf-8');
        $v->header('Content-Disposition', ($letolt ? 'attachment' : 'inline') . '; filename="' . $fajlnev . '"');

        return $v;
    }

    private static function biztonsag(WP_REST_Request $r)
    {
        if (class_exists('SDH_Sales_Biztonsag') && method_exists('SDH_Sales_Biztonsag', 'ellenoriz')) {
            $e = SDH_Sales_Biztonsag::ellenoriz($r);

            if (is_wp_error($e)) {
                return $e;
            }

            if ($e === false) {
                return self::hiba(__('A biztonsági ellenőrzés nem sikerült. Frissítsd az oldalt, és próbáld újra.', 'sdh-sales'), 403);
            }
        }

        return true;
    }

    public static function rest_savok(WP_REST_Request $r)
    {
        $tipus = sanitize_key((string) $r->get_param('tipus'));
        $tz    = self::tz();
        $ma    = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $tol   = (string) $r->get_param('tol');
        $ig    = (string) $r->get_param('ig');
        $tol   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tol) === 1 ? $tol : $ma;
        $ig    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ig) === 1 ? $ig : (new DateTimeImmutable($tol, $tz))->modify('+31 days')->format('Y-m-d');

        if (!isset(self::tipusok()[$tipus])) {
            return self::hiba(__('Ismeretlen időponttípus.', 'sdh-sales'), 400);
        }

        $b = self::beallitas();

        return new WP_REST_Response([
            'tipus'   => $tipus,
            'idozona' => self::TZ,
            'elso'    => $ma,
            'utolso'  => (new DateTimeImmutable($ma, $tz))->modify('+' . $b['max_nap'] . ' days')->format('Y-m-d'),
            'napok'   => (object) self::savok($tipus, $tol, $ig),
        ]);
    }

    public static function rest_foglal(WP_REST_Request $r)
    {
        $b = self::biztonsag($r);

        if ($b !== true) {
            return $b;
        }

        $e = self::foglal((array) $r->get_params());

        if (is_wp_error($e)) {
            return $e;
        }

        $f = self::egy($e['id']);

        return new WP_REST_Response([
            'ok'         => true,
            'tipus'      => $e['tipus'],
            'kezdet'     => $e['kezdet'],
            'veg'        => $e['veg'],
            'mikor'      => self::mikor((string) $f['kezdet']),
            'kezelo_url' => self::kezelo_url($e['token']),
            'ics_url'    => self::ics_url($e['token']),
            'uzenet'     => sprintf(__('Rögzítettük: %s. A visszaigazolást elküldtük e-mailben.', 'sdh-sales'), self::mikor((string) $f['kezdet'])),
        ], 201);
    }

    public static function rest_lemond(WP_REST_Request $r)
    {
        $e = self::lemond((string) $r->get_param('token'));

        return is_wp_error($e) ? $e : new WP_REST_Response(['ok' => true, 'uzenet' => __('Lemondtuk az időpontot. Elküldtük a visszaigazolást.', 'sdh-sales')]);
    }

    public static function rest_adat(WP_REST_Request $r)
    {
        $f = self::tokennel((string) $r->get_param('t'));

        if ($f === null) {
            return self::hiba(__('A link érvénytelen vagy lejárt. Nézd meg a legutóbbi levelünket.', 'sdh-sales'), 404);
        }

        $t = self::tipusok()[(string) $f['tipus']] ?? ['nev' => (string) $f['tipus']];

        return new WP_REST_Response([
            'tipus'      => (string) $f['tipus'],
            'tipus_nev'  => (string) $t['nev'],
            'kezdet'     => gmdate('Y-m-d\TH:i:s\Z', self::ts((string) $f['kezdet'])),
            'mikor'      => self::mikor((string) $f['kezdet']),
            'allapot'    => (string) $f['allapot'],
            'nev'        => (string) $f['nev'],
            'lemondhato' => self::lemondhato($f),
        ]);
    }

    public static function rest_ics(WP_REST_Request $r)
    {
        $f = self::tokennel((string) $r->get_param('t'));

        return $f === null ? self::hiba(__('A link érvénytelen.', 'sdh-sales'), 404) : self::ics_valasz(self::ics($f, (string) $f['allapot'] === 'foglalt' ? 'PUBLISH' : 'CANCEL'), 'idopont.ics', true);
    }

    public static function rest_elotolt(WP_REST_Request $r)
    {
        $tipus = sanitize_key((string) $r->get_param('tipus'));
        $ren   = self::link_rendeles(sanitize_text_field((string) $r->get_param('rendeles')), $tipus, sanitize_text_field((string) $r->get_param('rt')));

        if ($ren === null) {
            return self::hiba(__('A link érvénytelen.', 'sdh-sales'), 404);
        }

        $v = json_decode((string) $ren['vevo'], true);
        $v = is_array($v) ? $v : [];

        return new WP_REST_Response([
            'rendeles' => (string) $ren['azon'],
            'nev'      => (string) ($v['kapcsolattarto'] ?? $v['nev'] ?? ''),
            'email'    => (string) ($ren['email'] ?: ($v['email'] ?? '')),
            'telefon'  => (string) ($v['telefon'] ?? ''),
            'ceg'      => (string) ($v['ceg'] ?? $v['cegnev'] ?? ''),
        ]);
    }

    public static function rest_ical(WP_REST_Request $r)
    {
        $t = (string) $r->get_param('t');
        $j = SDH_Sales::titkos('naptar_ical_token');

        if ($j === '' || !hash_equals($j, $t)) {
            return self::hiba('Tiltott.', 403, 'sdh_ical');
        }

        return self::ics_valasz(self::ical_feed(), 'foglalasok.ics', false);
    }

    public static function rest_admin_lista(WP_REST_Request $r)
    {
        global $wpdb;

        $w = ['1=1'];
        $a = [];

        foreach (['tol' => 'kezdet >= %s', 'ig' => 'kezdet < %s'] as $k => $sql) {
            $v = (string) $r->get_param($k);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
                $w[] = $sql;
                $a[] = self::utc((new DateTimeImmutable($v, self::tz()))->modify($k === 'ig' ? '+1 day' : '+0 day')->getTimestamp());
            }
        }

        $all = sanitize_key((string) $r->get_param('allapot'));

        if (in_array($all, ['foglalt', 'lemondva', 'atfoglalva'], true)) {
            $w[] = 'allapot = %s';
            $a[] = $all;
        }

        $db    = max(1, min(200, (int) ($r->get_param('db') ?: 50)));
        $oldal = max(1, (int) $r->get_param('oldal'));
        $t     = SDH_Sales_Schema::t('foglalas');
        $wh    = implode(' AND ', $w);
        $ossz  = (int) ($a ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$wh}", ...$a)) : $wpdb->get_var("SELECT COUNT(*) FROM {$t}")); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sorok = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE {$wh} ORDER BY kezdet DESC LIMIT %d OFFSET %d", ...array_merge($a, [$db, ($oldal - 1) * $db])), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $tip = self::tipusok();

        return new WP_REST_Response([
            'ossz'  => $ossz,
            'sorok' => array_map(static function (array $f) use ($tip): array {
                unset($f['token']);
                $f['tipus_nev'] = (string) ($tip[$f['tipus']]['nev'] ?? $f['tipus']);
                $f['mikor']     = self::mikor((string) $f['kezdet']);

                return $f;
            }, $sorok),
        ]);
    }

    public static function rest_admin_lemond(WP_REST_Request $r)
    {
        $f = self::egy((int) $r['id']);

        if ($f === null) {
            return self::hiba('Nincs ilyen foglalás.', 404);
        }

        $e = self::lemond_sor($f, null, 'admin');

        return is_wp_error($e) ? $e : new WP_REST_Response(['ok' => true]);
    }

    public static function rest_admin_ical(WP_REST_Request $r)
    {
        return new WP_REST_Response(['url' => self::ical_url()]);
    }

    public static function rest_admin_ical_uj(WP_REST_Request $r)
    {
        self::ical_token(true);

        return new WP_REST_Response(['url' => self::ical_url(), 'uzenet' => 'Új feed-cím készült; a régi már nem működik.']);
    }

    public static function rest_admin_google(WP_REST_Request $r)
    {
        if (!self::google_be()) {
            return self::hiba('A Google Naptár-küldés ki van kapcsolva, vagy hiányzik a kliens-azonosító / titok / refresh token.', 422);
        }

        delete_transient('sdh_sales_google_at');
        $v = self::google_keres('GET', '?maxResults=1');

        return is_wp_error($v) ? self::hiba($v->get_error_message(), 422) : new WP_REST_Response(['ok' => true, 'uzenet' => 'A Google Naptár elérhető.']);
    }
}
