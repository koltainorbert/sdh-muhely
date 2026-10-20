<?php
/**
 * SDH Értékesítés – fizetés: kártyás kapuk elosztója (Barion, SimplePay), újrapróba, ütemezett feladatok
 * (díjbekérő-emlékeztető, lejárt díjbekérő törlése, elakadt kártyás fizetés, Számlázz.hu fizetettség-lekérdezés).
 * A fizetés rögzítése MINDIG: SDH_Sales_Rendeles::fizetve() (egyetlen belépési pont).
 *
 * API:
 *   kartya_indit(int $id, string $kapu): array{ok, url?, uzenet?}  – fizetési oldal indítása ('barion'|'simplepay')
 *   kartya_ellenoriz(int $id): string                              – a kapu szerinti állapot lekérdezése és rögzítése
 *                                                                    ('fizetve'|'fuggo'|'sikertelen'|'ismeretlen')
 *   kartya_sikertelen(int $id, string $ok): void                    – 'sikertelen' + levél újrapróba-linkkel (egyszer)
 *   ujraproba_url(array $r): string
 *   json_keres(string $url, array $test, array $fejlec, string $metodus = 'POST'): array{kod, test, fejlec, json}
 *   cron(): void                                                    – SDH_Sales::CRON
 * REST: POST /fizetes/ujra {azon, t} – kártyás fizetés újraindítása (vagy átváltás átutalásra: {fizmod:'atutalas'}).
 * Beállítások: fizetes.emlekezteto_nap (5), fizetes.torles_nap (30), fizetes.kartya_elakadt_perc (60),
 *   fizetes.automatizalas ('import'|'szamlazz'|'kartya'|'kezi'), fizetes.szamlazz_lekerdezes_perc (30).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Fizetes
{
    public static function init(): void
    {
        add_action(SDH_Sales::CRON, [self::class, 'cron'], 15, 0);
        SDH_Sales_Rest::ut('/fizetes/ujra', 'POST', [self::class, 'rest_ujra'], 'nyilvanos', ['fizetes_ujra', 10, 600]);
    }

    /** @return class-string|null */
    private static function kapu_osztaly(string $kapu): ?string
    {
        $o = ['barion' => 'SDH_Sales_Fizetes_Barion', 'simplepay' => 'SDH_Sales_Fizetes_Simplepay'][$kapu] ?? null;

        return $o !== null && class_exists($o) ? $o : null;
    }

    /** @return array{ok: bool, url?: string, uzenet?: string} */
    public static function kartya_indit(int $id, string $kapu): array
    {
        $o = self::kapu_osztaly($kapu);
        $r = SDH_Sales_Rendeles::rendeles($id);

        if ($o === null || $r === null || !$o::aktiv()) {
            return ['ok' => false, 'uzenet' => __('Ez a fizetési mód most nem érhető el.', 'sdh-sales')];
        }

        if (!in_array((string) $r['allapot'], ['uj', 'fizetesre_var', 'sikertelen', 'dijbekero'], true)) {
            return ['ok' => false, 'uzenet' => __('Ez a rendelés már nem fizethető.', 'sdh-sales')];
        }

        $e = $o::indit($r);

        if (!empty($e['ok'])) {
            self::kartya_jelol($id, $kapu, (string) $e['azon']);
            SDH_Sales_Rendeles::allapot_valt($id, 'fizetesre_var', ['uj', 'sikertelen', 'dijbekero'], 'kártyás fizetés indult');
        } else {
            SDH_Sales_Naplo::ir('fizetes', 'Kártyás fizetés nem indult (' . $kapu . ', ' . (string) $r['azon'] . '): ' . (string) ($e['uzenet'] ?? ''), 'hiba', 'rendeles', $id);
        }

        return ['ok' => !empty($e['ok']), 'url' => (string) ($e['url'] ?? ''), 'uzenet' => !empty($e['ok']) ? '' : __('A kártyás fizetés most nem indítható. Próbáld újra pár perc múlva, vagy válassz átutalást.', 'sdh-sales')];
    }

    /** A kapu fizetés-azonosítója a rendelés vevő-adatában (`_kartya`): a callback ehhez párosít. */
    private static function kartya_jelol(int $id, string $kapu, string $azon): void
    {
        global $wpdb;

        $r = SDH_Sales_Rendeles::rendeles($id);

        if ($r === null) {
            return;
        }

        $v                      = (array) $r['vevo'];
        $k                      = (array) ($v['_kartya'] ?? []);
        $k['kapu']              = $kapu;
        $k['azon']              = $azon;
        $k['ido']               = time();
        $k['korabbiak']         = array_slice(array_merge((array) ($k['korabbiak'] ?? []), $azon !== '' ? [$azon] : []), -10);
        unset($k['sikertelen_level']);
        $v['_kartya']           = $k;

        $wpdb->update(SDH_Sales_Schema::t('rendeles'), ['vevo' => (string) wp_json_encode($v, JSON_UNESCAPED_UNICODE), 'fizmod' => $kapu, 'modositva' => current_time('mysql', true)], ['id' => $id]);
    }

    /** A rendeléshez tartozó, a kapunál indított fizetés-azonosítók (a callback hitelességéhez). @return array<int, string> */
    public static function kartya_azonok(array $r): array
    {
        $k = (array) ($r['vevo']['_kartya'] ?? []);

        return array_values(array_unique(array_filter(array_map('strval', (array) ($k['korabbiak'] ?? [])))));
    }

    public static function kartya_ellenoriz(int $id): string
    {
        $r = SDH_Sales_Rendeles::rendeles($id);

        if ($r === null) {
            return 'ismeretlen';
        }

        $k = (array) ($r['vevo']['_kartya'] ?? []);
        $o = self::kapu_osztaly((string) ($k['kapu'] ?? ''));

        if ($o === null || empty($k['azon'])) {
            return 'ismeretlen';
        }

        $a = $o::allapot((string) $k['azon']);

        return self::kapu_eredmeny($r, (string) $k['kapu'], $a);
    }

    /**
     * Egy kapu által igazolt állapot rögzítése.
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed> $a {allapot: fizetve|fuggo|sikertelen|ismeretlen, tranzakcio, osszeg, rendeles (azon), nyers}
     */
    public static function kapu_eredmeny(array $r, string $kapu, array $a): string
    {
        $all = (string) ($a['allapot'] ?? 'ismeretlen');

        if (isset($a['rendeles']) && (string) $a['rendeles'] !== '' && (string) $a['rendeles'] !== (string) $r['azon']) {
            SDH_Sales_Naplo::ir('fizetes', 'Kártyás visszajelzés más rendelésre mutat (' . $kapu . ').', 'hiba', 'rendeles', (int) $r['id']);

            return 'ismeretlen';
        }

        // Csak forintos fizetés számít (a kapu más pénznemű összegét nem vesszük forintnak).
        $penznem = strtoupper((string) ($a['nyers']['Currency'] ?? $a['nyers']['currency'] ?? 'HUF'));

        if ($all === 'fizetve' && $penznem !== 'HUF' && $penznem !== '') {
            SDH_Sales_Naplo::ir('fizetes', 'Kártyás fizetés nem forintban (' . $kapu . ', ' . mb_substr($penznem, 0, 3) . ') – nem rögzítve, ellenőrizendő.', 'hiba', 'rendeles', (int) $r['id']);

            return 'ismeretlen';
        }

        if ($all === 'fizetve' && (int) ($a['osszeg'] ?? 0) > 0 && (string) ($a['tranzakcio'] ?? '') !== '') {
            SDH_Sales_Rendeles::fizetve((int) $r['id'], $kapu, (string) $a['tranzakcio'], (int) $a['osszeg'], (array) ($a['nyers'] ?? []));

            return 'fizetve';
        }

        if ($all === 'sikertelen') {
            self::kartya_sikertelen((int) $r['id'], (string) ($a['ok'] ?? ''));
        }

        return $all;
    }

    public static function ujraproba_url(array $r): string
    {
        return SDH_Sales_Kosar::penztar_url(['rendeles' => (string) $r['azon'], 't' => SDH_Sales_Rendeles::allapot_token($r), 'ujra' => '1']);
    }

    public static function kartya_sikertelen(int $id, string $ok = ''): void
    {
        global $wpdb;

        $r = SDH_Sales_Rendeles::rendeles($id);

        if ($r === null) {
            return;
        }

        SDH_Sales_Rendeles::allapot_valt($id, 'sikertelen', ['uj', 'fizetesre_var'], $ok !== '' ? $ok : 'kártyás fizetés sikertelen');
        $r = (array) SDH_Sales_Rendeles::rendeles($id);
        $v = (array) $r['vevo'];

        if ((string) $r['allapot'] !== 'sikertelen' || !empty($v['_kartya']['sikertelen_level'])) {
            return;
        }

        $v['_kartya']['sikertelen_level'] = time();
        $wpdb->update(SDH_Sales_Schema::t('rendeles'), ['vevo' => (string) wp_json_encode($v, JSON_UNESCAPED_UNICODE)], ['id' => $id]);

        SDH_Sales_Fizetes_Atutalas::level('fizetes_sikertelen', (string) $r['email'], [
            '_rendeles_id'  => $id,
            '_targy'        => sprintf(__('Nem sikerült a fizetés – %s', 'sdh-sales'), (string) $r['azon']),
            'nev'           => (string) ($v['kapcsolattarto'] ?? ''),
            'rendeles'      => (string) $r['azon'],
            'osszeg'        => SDH_Sales::ft((int) $r['brutto']),
            'ok'            => $ok !== '' ? $ok : __('A fizetés megszakadt vagy a bank elutasította.', 'sdh-sales'),
            'ujraproba_url' => self::ujraproba_url($r),
        ]);
    }

    /**
     * Közös HTTP-segéd a kapukhoz (JSON be/ki).
     *
     * @param array<string, mixed>|null $test
     * @param array<string, string>     $fejlec
     * @return array{kod: int, test: string, fejlec: array<string, string>, json: array<string, mixed>|null, hiba: string}
     */
    public static function json_keres(string $url, ?array $test, array $fejlec = [], string $metodus = 'POST', ?string $nyers_test = null): array
    {
        $args = [
            'method'  => $metodus,
            'timeout' => 20,
            'headers' => $fejlec + ['Accept' => 'application/json'],
        ];

        if ($metodus !== 'GET') {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = $nyers_test ?? (string) wp_json_encode($test);
        }

        $v = wp_remote_request($url, $args);

        if (is_wp_error($v)) {
            return ['kod' => 0, 'test' => '', 'fejlec' => [], 'json' => null, 'hiba' => $v->get_error_message()];
        }

        $t = (string) wp_remote_retrieve_body($v);
        $j = json_decode($t, true);
        $h = [];

        foreach ((array) wp_remote_retrieve_headers($v) as $k => $x) {
            $h[strtolower((string) $k)] = is_array($x) ? (string) end($x) : (string) $x;
        }

        return ['kod' => (int) wp_remote_retrieve_response_code($v), 'test' => $t, 'fejlec' => $h, 'json' => is_array($j) ? $j : null, 'hiba' => ''];
    }

    /* =================================================================
     * REST
     * ============================================================== */

    public static function rest_ujra(WP_REST_Request $q)
    {
        $r = SDH_Sales_Rendeles::azon_szerint((string) $q->get_param('azon'));
        $t = (string) $q->get_param('t');

        if ($r === null || $t === '' || !hash_equals(SDH_Sales_Rendeles::allapot_token($r), $t)) {
            return SDH_Sales_Rest::hiba(__('Nincs ilyen rendelés, vagy lejárt a link.', 'sdh-sales'), 404, 'nincs');
        }

        if (!in_array((string) $r['allapot'], ['fizetesre_var', 'sikertelen', 'dijbekero'], true)) {
            return SDH_Sales_Rest::hiba(__('Ez a rendelés már nem fizethető.', 'sdh-sales'), 409, 'nem_fizetheto');
        }

        $mod = sanitize_key((string) ($q->get_param('fizmod') ?: $r['fizmod']));

        if ($mod === 'atutalas') {
            global $wpdb;

            // Már átutalásos díjbekérő: nincs újabb levél / díjbekérő (a tokenes link nem lehet levélbomba).
            if ((string) $r['allapot'] === 'dijbekero' && (string) $r['fizmod'] === 'atutalas') {
                return new WP_REST_Response(['ok' => true, 'rendeles' => SDH_Sales_Rendeles::nyilvanos($r)], 200);
            }

            $wpdb->update(SDH_Sales_Schema::t('rendeles'), ['fizmod' => 'atutalas'], ['id' => (int) $r['id']]);
            SDH_Sales_Rendeles::allapot_valt((int) $r['id'], 'dijbekero', ['fizetesre_var', 'sikertelen'], 'átváltás átutalásra');
            do_action('sdh_sales_rendeles_letrehozva', (int) $r['id']);
            SDH_Sales_Fizetes_Atutalas::dijbekero_level((int) $r['id']);

            return new WP_REST_Response(['ok' => true, 'rendeles' => SDH_Sales_Rendeles::nyilvanos((array) SDH_Sales_Rendeles::rendeles((int) $r['id']))], 200);
        }

        $e = self::kartya_indit((int) $r['id'], $mod);

        return new WP_REST_Response(['ok' => $e['ok'], 'atiranyit' => $e['url'] ?? '', 'uzenet' => $e['uzenet'] ?? ''], $e['ok'] ? 200 : 502);
    }

    /* =================================================================
     * Cron
     * ============================================================== */

    public static function cron(?int $most = null): void
    {
        $most = $most ?? time();

        self::emlekeztetok($most);
        self::lejart_torles($most);
        self::kartya_elakadt($most);
        self::szamlazz_lekerdez($most);
    }

    /** @return array<int, array<string, mixed>> */
    private static function sorok(string $where, array $args, int $db = 50): array
    {
        global $wpdb;

        $sql = 'SELECT id FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE ' . $where . ' ORDER BY id ASC LIMIT ' . max(1, $db);
        $ids = $args !== [] ? $wpdb->get_col($wpdb->prepare($sql, ...$args)) : $wpdb->get_col($sql);

        return array_values(array_filter(array_map(static fn ($i) => SDH_Sales_Rendeles::rendeles((int) $i), (array) $ids)));
    }

    /** Fizetetlen díjbekérő: N nap után egy emlékeztető. Visszaad: hány ment ki. */
    public static function emlekeztetok(int $most): int
    {
        global $wpdb;

        $n   = max(1, (int) SDH_Sales::b('fizetes.emlekezteto_nap', 5));
        $hat = gmdate('Y-m-d H:i:s', $most - $n * DAY_IN_SECONDS);
        $db  = 0;

        // A már emlékeztetett rendelések az SQL-ben kimaradnak, így a LIMIT nem „éhezteti” az újabbakat.
        foreach (self::sorok("allapot = 'dijbekero' AND letrehozva <= %s AND vevo NOT LIKE %s", [$hat, '%' . $wpdb->esc_like('"_emlekeztetve"') . '%']) as $r) {
            $v = (array) $r['vevo'];

            if (!empty($v['_emlekeztetve'])) {
                continue;
            }

            $v['_emlekeztetve'] = gmdate('c', $most);
            $wpdb->update(SDH_Sales_Schema::t('rendeles'), ['vevo' => (string) wp_json_encode($v, JSON_UNESCAPED_UNICODE)], ['id' => (int) $r['id']]);

            if (SDH_Sales_Fizetes_Atutalas::emlekezteto_level((int) $r['id'])) {
                $db++;
                SDH_Sales_Naplo::ir('fizetes', 'Fizetési emlékeztető: ' . (string) $r['azon'] . '.', 'info', 'rendeles', (int) $r['id']);
            }
        }

        return $db;
    }

    /** M nap után a fizetetlen díjbekérő törlése (késve érkező utalás még visszaélesztheti). */
    public static function lejart_torles(int $most): int
    {
        $m   = max(1, (int) SDH_Sales::b('fizetes.torles_nap', 30));
        $hat = gmdate('Y-m-d H:i:s', $most - $m * DAY_IN_SECONDS);
        $db  = 0;

        foreach (self::sorok("allapot IN ('dijbekero','sikertelen') AND fizetett = 0 AND letrehozva <= %s", [$hat]) as $r) {
            if (SDH_Sales_Rendeles::allapot_valt((int) $r['id'], 'torolve', ['dijbekero', 'sikertelen'], $m . ' napja fizetetlen')) {
                $db++;
            }
        }

        return $db;
    }

    /** Kártyás fizetés, amiről a kapu nem szólt: lekérdezés; ha még mindig nincs kész, sikertelen + levél. */
    public static function kartya_elakadt(int $most): int
    {
        $perc = max(15, (int) SDH_Sales::b('fizetes.kartya_elakadt_perc', 60));
        $hat  = gmdate('Y-m-d H:i:s', $most - $perc * MINUTE_IN_SECONDS);
        $db   = 0;

        foreach (self::sorok("allapot = 'fizetesre_var' AND modositva <= %s", [$hat], 20) as $r) {
            $a = self::kartya_ellenoriz((int) $r['id']);

            if ($a === 'fuggo' || $a === 'ismeretlen') {
                self::kartya_sikertelen((int) $r['id'], __('A fizetés nem fejeződött be.', 'sdh-sales'));
            }

            $db++;
        }

        return $db;
    }

    /**
     * Számlázz.hu fizetettség-lekérdezés (csak `fizetes.automatizalas` = 'szamlazz'): a díjbekérőn rögzített kifizetések.
     * A tranzakció-kulcs ugyanaz, mint a Számlázz.hu IPN-é ('ipn:<díjbekérő>:<eddigi összes>'), így a kettő együtt is
     * csak egyszer rögzít.
     */
    public static function szamlazz_lekerdez(int $most): int
    {
        if ((string) SDH_Sales::b('fizetes.automatizalas', 'import') !== 'szamlazz' || !class_exists('SDH_Sales_Szamlazz') || !method_exists('SDH_Sales_Szamlazz', 'fizetettseg')) {
            return 0;
        }

        global $wpdb;

        $perc = max(5, (int) SDH_Sales::b('fizetes.szamlazz_lekerdezes_perc', 30));
        $db   = 0;
        $kert = 0;

        // A kihagyás (nemrég lekérdezve) a LIMIT ELŐTT: minden futás a következő 20 esedékest kérdezi (a legrégebbitől),
        // így 20-nál több nyitott díjbekérőnél sem marad ki egy sem.
        $ids = (array) $wpdb->get_col("SELECT id FROM " . SDH_Sales_Schema::t('rendeles') . " WHERE allapot = 'dijbekero' AND szamla_dijbekero <> '' ORDER BY id ASC LIMIT 5000");

        foreach ($ids as $rid) {
            $kulcs = 'szlpoll_' . (int) $rid;

            if (get_transient($kulcs)) {
                continue;
            }

            if ($kert >= 20) {
                break;
            }

            $r = SDH_Sales_Rendeles::rendeles((int) $rid);

            if ($r === null) {
                continue;
            }

            $kert++;

            set_transient($kulcs, 1, $perc * MINUTE_IN_SECONDS);
            $f = SDH_Sales_Szamlazz::fizetettseg((string) $r['szamla_dijbekero']);

            if (!is_array($f) || ($f['forras'] ?? '') !== 'szamlazz') {
                continue;
            }

            $ossz = (int) round((float) $f['fizetve']);
            $uj   = $ossz - (int) $r['fizetett'];

            if ($uj > 0) {
                SDH_Sales_Rendeles::fizetve((int) $r['id'], 'szamlazz', 'ipn:' . (string) $r['szamla_dijbekero'] . ':' . $ossz, $uj, ['lekerdezes' => $f]);
                $db++;
            }
        }

        return $db;
    }
}
