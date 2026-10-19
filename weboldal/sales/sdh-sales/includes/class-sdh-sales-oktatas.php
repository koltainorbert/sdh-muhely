<?php
/**
 * SDH Értékesítés – oktató mód: leckék (tartalom `lecke`, forrás data/oktatas.json), lépésenkénti lejátszó, kvíz
 * szerveroldali értékeléssel, haladás (látogató: localStorage; bejelentkezett vevő: user meta), tanúsítvány ellenőrző kóddal.
 *
 * Lecke (data/oktatas.json, tartalom ügynök): {kulcs, modul, cim, cel, lepesek[], kviz[], ido_perc}
 *   lepesek[]: szöveg vagy {cim, szoveg|leiras, tipp?}
 *   kviz[]:    {kerdes, valaszok|opciok[], helyes (index | szöveg | index-tömb), magyarazat}
 * Kurzus = egy modul leckéi (a `modul` mező szerint).
 *
 * REST (SDH_Sales_Rest::NS):
 *   POST /oktatas/kviz         {lecke, valaszok:[index…]}         → {pont, ossz, sikeres, helyes[], magyarazat[], igazolas}
 *   GET  /oktatas/haladas      (fiók)                             → {haladas:{lecke:{lepes, kesz, kviz, ido}}}
 *   POST /oktatas/haladas      (fiók) {haladas:{…}}               → összefésülve (a nagyobb haladás marad)
 *   POST /oktatas/tanusitvany  {kurzus, nev, igazolasok:{lecke:igazolas}} → {kod, url}
 *   GET  /oktatas/tanusitvany  ?kod=…                             → {ervenyes, nev, kurzus, datum}
 * Nyomtatható tanúsítvány: bármely oldal ?tanusitvany=KOD
 * Fiók: apply_filters('sdh_sales_fiok_oktatas', [], $user_id) → {kurzusok:[…], tanusitvanyok:[…]}
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Oktatas
{
    public const META = 'sdh_sales_oktatas';
    public const OPT_TANUSITVANY = 'sdh_sales_tanusitvanyok';
    public const SIKER_SZAZALEK = 80;

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/oktatas/kviz', 'POST', [self::class, 'rest_kviz'], 'nyilvanos', ['oktatas_kviz', 60, 600]);
        SDH_Sales_Rest::ut('/oktatas/haladas', 'GET', [self::class, 'rest_haladas'], 'fiok');
        SDH_Sales_Rest::ut('/oktatas/haladas', 'POST', [self::class, 'rest_haladas_ment'], 'fiok', ['oktatas_haladas', 120, 600]);
        SDH_Sales_Rest::ut('/oktatas/tanusitvany', 'POST', [self::class, 'rest_tanusitvany'], 'nyilvanos', ['oktatas_tanusitvany', 10, DAY_IN_SECONDS]);
        SDH_Sales_Rest::ut('/oktatas/tanusitvany', 'GET', [self::class, 'rest_ellenoriz'], 'nyilvanos', ['oktatas_ellenoriz', 60, 600]);

        add_action('init', [self::class, 'front_regisztral'], 20);
        add_action('template_redirect', [self::class, 'tanusitvany_oldal'], 1);
        add_filter('sdh_sales_fiok_oktatas', [self::class, 'fiok'], 10, 2);
        add_shortcode('oktatas', [self::class, 'shortcode']);
        add_shortcode('sdh_oktatas', [self::class, 'shortcode']); // régi név: egy régi tartalomban se maradjon nyers szövegként
    }

    /* =================================================================
     * Leckék
     * ============================================================== */

    /** @return array<int, array<string, mixed>> normalizált leckék (aktívak, sorrendben) */
    public static function leckek(): array
    {
        $ki = [];

        foreach (SDH_Sales_Tartalom::lista('lecke') as $e) {
            $l = self::normal((string) $e['kulcs'], (array) $e['adat'], (string) $e['cim']);

            if ($l !== null) {
                $ki[] = $l;
            }
        }

        return $ki;
    }

    /** @return array<string, mixed>|null */
    public static function lecke(string $kulcs): ?array
    {
        foreach (self::leckek() as $l) {
            if ($l['kulcs'] === $kulcs) {
                return $l;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>|null
     */
    private static function normal(string $kulcs, array $a, string $cim): ?array
    {
        $lepesek = [];

        foreach ((array) ($a['lepesek'] ?? []) as $i => $l) {
            if (is_string($l) && trim($l) !== '') {
                $lepesek[] = ['cim' => '', 'szoveg' => SDH_Sales::t($l), 'tipp' => ''];
            } elseif (is_array($l)) {
                $sz = (string) ($l['szoveg'] ?? $l['leiras'] ?? $l['tartalom'] ?? '');

                if (trim($sz) === '' && empty($l['cim'])) {
                    continue;
                }

                $lepesek[] = ['cim' => SDH_Sales::t((string) ($l['cim'] ?? '')), 'szoveg' => SDH_Sales::t($sz), 'tipp' => SDH_Sales::t((string) ($l['tipp'] ?? ''))];
            }
        }

        $kviz = [];

        foreach ((array) ($a['kviz'] ?? []) as $q) {
            if (!is_array($q) || empty($q['kerdes'])) {
                continue;
            }

            $v = array_values(array_map('strval', (array) ($q['valaszok'] ?? $q['opciok'] ?? $q['lehetosegek'] ?? [])));

            if (count($v) < 2) {
                continue;
            }

            $h = $q['helyes'] ?? $q['jo'] ?? 0;
            $h = is_array($h) ? $h : [$h];
            $hi = [];

            foreach ($h as $x) {
                if (is_int($x) || ctype_digit((string) $x)) {
                    $hi[] = (int) $x;
                } else {
                    $p = array_search((string) $x, $v, true);

                    if ($p !== false) {
                        $hi[] = (int) $p;
                    }
                }
            }

            $hi = array_values(array_unique(array_filter($hi, static fn ($x) => $x >= 0 && $x < count($v))));

            if ($hi === []) {
                continue;
            }

            sort($hi);
            $kviz[] = ['kerdes' => SDH_Sales::t((string) $q['kerdes']), 'valaszok' => array_map([SDH_Sales::class, 't'], $v), 'helyes' => $hi, 'magyarazat' => SDH_Sales::t((string) ($q['magyarazat'] ?? ''))];
        }

        if ($lepesek === []) {
            return null;
        }

        return [
            'kulcs'    => $kulcs,
            'modul'    => sanitize_key((string) ($a['modul'] ?? 'altalanos')) ?: 'altalanos',
            'cim'      => SDH_Sales::t((string) ($a['cim'] ?? $cim)),
            'cel'      => SDH_Sales::t((string) ($a['cel'] ?? '')),
            'ido_perc' => max(0, (int) ($a['ido_perc'] ?? 0)),
            'lepesek'  => $lepesek,
            'kviz'     => $kviz,
        ];
    }

    /** @return array<string, array{modul: string, cim: string, leckek: array<int, string>}> kurzusok modulonként */
    public static function kurzusok(): array
    {
        $nevek = [];

        foreach (SDH_Sales_Tartalom::lista('modul') as $m) {
            $nevek[(string) $m['kulcs']] = SDH_Sales::t((string) ($m['adat']['nev'] ?? $m['cim']));
        }

        $ki = [];

        foreach (self::leckek() as $l) {
            $m = $l['modul'];
            $ki[$m] ??= ['modul' => $m, 'cim' => $nevek[$m] ?? ($m === 'altalanos' ? __('Alapok', 'sdh-sales') : ucfirst(str_replace(['-', '_'], ' ', $m))), 'leckek' => []];
            $ki[$m]['leckek'][] = $l['kulcs'];
        }

        return $ki;
    }

    /** A kliensnek: a kvíz helyes válaszai és magyarázatai nélkül (a szerver értékel). @return array<int, array<string, mixed>> */
    public static function nyilvanos(): array
    {
        return array_map(static function (array $l): array {
            $l['kviz'] = array_map(static fn ($q) => ['kerdes' => $q['kerdes'], 'valaszok' => $q['valaszok'], 'tobb' => count($q['helyes']) > 1], $l['kviz']);

            return $l;
        }, self::leckek());
    }

    /** Egy lépés szövege Szikrának (oktató mód). */
    public static function lepes_szoveg(string $lecke, int $lepes): string
    {
        $l = self::lecke($lecke);

        if ($l === null) {
            return '';
        }

        $lepes = max(0, min(count($l['lepesek']) - 1, $lepes));
        $s     = $l['lepesek'][$lepes];
        $ki    = 'Lecke: ' . $l['cim'] . ($l['cel'] !== '' ? "\nCél: " . $l['cel'] : '') . "\nLépés " . ($lepes + 1) . '/' . count($l['lepesek']);
        $ki   .= ($s['cim'] !== '' ? ': ' . $s['cim'] : '') . "\n" . wp_strip_all_tags($s['szoveg']) . ($s['tipp'] !== '' ? "\nTipp: " . wp_strip_all_tags($s['tipp']) : '');

        return mb_substr($ki, 0, 3000);
    }

    /* =================================================================
     * Kvíz + igazolás
     * ============================================================== */

    /** HMAC-igazolás egy sikeres kvízről (a kliens tárolja; tanúsítványkéréskor a szerver ellenőrzi). */
    public static function igazolas(string $lecke, int $pont, int $ossz): string
    {
        $adat = $lecke . '.' . $pont . '.' . $ossz;

        return $adat . '.' . substr(hash_hmac('sha256', 'kviz|' . $adat, wp_salt('auth') . 'sdh-oktatas'), 0, 20);
    }

    public static function igazolas_jo(string $lecke, string $ig): bool
    {
        if (!preg_match('/^([a-z0-9_-]+)\.(\d+)\.(\d+)\.([a-f0-9]{20})$/', $ig, $m) || $m[1] !== $lecke) {
            return false;
        }

        return hash_equals(self::igazolas($m[1], (int) $m[2], (int) $m[3]), $ig) && (int) $m[3] > 0 && (int) $m[2] * 100 >= self::SIKER_SZAZALEK * (int) $m[3];
    }

    /**
     * Egy kvíz értékelése.
     *
     * @param array<int, mixed> $valaszok kérdésenként egy index vagy index-tömb
     * @return array<string, mixed>|null
     */
    public static function ertekel(string $lecke, array $valaszok): ?array
    {
        $l = self::lecke($lecke);

        if ($l === null || $l['kviz'] === []) {
            return null;
        }

        $pont    = 0;
        $helyes  = [];
        $magyar  = [];
        $jok     = [];

        foreach ($l['kviz'] as $i => $q) {
            $v = $valaszok[$i] ?? null;
            $v = is_array($v) ? $v : ($v === null || $v === '' ? [] : [$v]);
            $v = array_values(array_unique(array_map('intval', $v)));
            sort($v);
            $jo = $v === $q['helyes'];
            $pont += $jo ? 1 : 0;
            $helyes[] = $jo;
            $jok[]    = $q['helyes'];
            $magyar[] = $q['magyarazat'];
        }

        $ossz    = count($l['kviz']);
        $sikeres = $pont * 100 >= self::SIKER_SZAZALEK * $ossz;

        return [
            'pont'       => $pont,
            'ossz'       => $ossz,
            'sikeres'    => $sikeres,
            'helyes'     => $helyes,
            'jo_valasz'  => $jok,
            'magyarazat' => $magyar,
            'igazolas'   => $sikeres ? self::igazolas($lecke, $pont, $ossz) : '',
        ];
    }

    /** @return WP_REST_Response|WP_Error */
    public static function rest_kviz(WP_REST_Request $r)
    {
        $e = self::ertekel(sanitize_key((string) $r->get_param('lecke')), (array) $r->get_param('valaszok'));

        if ($e === null) {
            return SDH_Sales_Rest::hiba(__('Ehhez a leckéhez nincs kvíz.', 'sdh-sales'), 404, 'oktatas_nincs');
        }

        if (is_user_logged_in()) {
            $kulcs = sanitize_key((string) $r->get_param('lecke'));
            self::haladas_osszefesul(get_current_user_id(), [$kulcs => ['kviz' => ['pont' => $e['pont'], 'ossz' => $e['ossz'], 'igazolas' => $e['igazolas']], 'kesz' => $e['sikeres']]]);
        }

        return new WP_REST_Response($e, 200);
    }

    /* =================================================================
     * Haladás
     * ============================================================== */

    /** @return array<string, array<string, mixed>> */
    public static function haladas(int $user_id): array
    {
        $h = get_user_meta($user_id, self::META, true);

        return is_array($h) ? $h : [];
    }

    /**
     * Haladás összefésülése: leckénként a nagyobb lépés, a „kész” megmarad, a jobb (érvényes igazolású) kvíz marad.
     *
     * @param mixed $uj
     * @return array<string, array<string, mixed>>
     */
    public static function haladas_osszefesul(int $user_id, $uj): array
    {
        $h      = self::haladas($user_id);
        $leckek = [];

        foreach (self::leckek() as $l) {
            $leckek[$l['kulcs']] = count($l['lepesek']);
        }

        foreach (is_array($uj) ? $uj : [] as $k => $v) {
            $k = sanitize_key((string) $k);

            if (!isset($leckek[$k]) || !is_array($v)) {
                continue;
            }

            $r    = $h[$k] ?? ['lepes' => 0, 'kesz' => false, 'kviz' => null, 'ido' => ''];
            $lep  = max(0, min($leckek[$k] - 1, (int) ($v['lepes'] ?? 0)));
            $r['lepes'] = max((int) ($r['lepes'] ?? 0), $lep);
            $r['kesz']  = !empty($r['kesz']) || !empty($v['kesz']);

            if (is_array($v['kviz'] ?? null)) {
                $kv = $v['kviz'];
                $ig = (string) ($kv['igazolas'] ?? '');
                $kv = ['pont' => max(0, (int) ($kv['pont'] ?? 0)), 'ossz' => max(0, (int) ($kv['ossz'] ?? 0)), 'igazolas' => self::igazolas_jo($k, $ig) ? $ig : ''];

                if (!is_array($r['kviz'] ?? null) || ($kv['igazolas'] !== '' && ($r['kviz']['igazolas'] ?? '') === '') || ($kv['ossz'] > 0 && $kv['pont'] > (int) ($r['kviz']['pont'] ?? 0) && ($kv['igazolas'] !== '' || ($r['kviz']['igazolas'] ?? '') === ''))) {
                    $r['kviz'] = $kv;
                }
            }

            $r['ido'] = current_time('mysql', true);
            $h[$k]    = $r;
        }

        update_user_meta($user_id, self::META, $h);

        return $h;
    }

    public static function rest_haladas(WP_REST_Request $r): WP_REST_Response
    {
        return new WP_REST_Response(['haladas' => self::haladas(get_current_user_id())], 200);
    }

    public static function rest_haladas_ment(WP_REST_Request $r): WP_REST_Response
    {
        $uj = $r->get_param('haladas');

        if (is_array($uj) && count($uj) > 500) {
            $uj = array_slice($uj, 0, 500, true);
        }

        return new WP_REST_Response(['haladas' => self::haladas_osszefesul(get_current_user_id(), $uj)], 200);
    }

    /* =================================================================
     * Tanúsítvány
     * ============================================================== */

    /** @return array<string, array<string, mixed>> */
    private static function tanusitvanyok(): array
    {
        $t = get_option(self::OPT_TANUSITVANY, []);

        return is_array($t) ? $t : [];
    }

    /** Olvasható, ellenőrizhető kód: XXXX-XXXX-XXXX (a tévesztős betűk nélkül). */
    private static function uj_kod(): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $k   = '';

        for ($i = 0; $i < 12; $i++) {
            $k .= $abc[random_int(0, strlen($abc) - 1)];
        }

        return substr($k, 0, 4) . '-' . substr($k, 4, 4) . '-' . substr($k, 8, 4);
    }

    /**
     * Tanúsítvány kiállítása, ha a kurzus minden kvízes leckéjére érvényes igazolás van.
     *
     * @param array<string, string> $igazolasok lecke → igazolás
     * @return array{kod: string, url: string}|WP_Error
     */
    public static function kiallit(string $kurzus, string $nev, array $igazolasok, int $user_id = 0)
    {
        $nev = trim(preg_replace('/\s+/u', ' ', sanitize_text_field($nev)) ?? '');
        $k   = self::kurzusok()[$kurzus] ?? null;

        if ($k === null) {
            return SDH_Sales_Rest::hiba(__('Nincs ilyen kurzus.', 'sdh-sales'), 404, 'oktatas_kurzus');
        }

        if (mb_strlen($nev) < 2 || mb_strlen($nev) > 80) {
            return SDH_Sales_Rest::hiba(__('Add meg a neved a tanúsítványhoz (2–80 karakter).', 'sdh-sales'), 400, 'oktatas_nev');
        }

        if ($user_id > 0) {
            foreach (self::haladas($user_id) as $lk => $h) {
                if (!isset($igazolasok[$lk]) && !empty($h['kviz']['igazolas'])) {
                    $igazolasok[$lk] = (string) $h['kviz']['igazolas'];
                }
            }
        }

        $hiany = [];

        foreach ($k['leckek'] as $lk) {
            $l = self::lecke($lk);

            if ($l === null || $l['kviz'] === []) {
                continue;
            }

            if (!self::igazolas_jo($lk, (string) ($igazolasok[$lk] ?? ''))) {
                $hiany[] = $l['cim'];
            }
        }

        if ($hiany !== []) {
            /* translators: %s: leckék címe */
            return SDH_Sales_Rest::hiba(sprintf(__('A tanúsítványhoz ezeknek a leckéknek a kvízét kell sikeresen kitöltened: %s', 'sdh-sales'), implode(', ', $hiany)), 400, 'oktatas_hianyos');
        }

        $t = self::tanusitvanyok();

        do {
            $kod = self::uj_kod();
        } while (isset($t[$kod]));

        $t[$kod] = ['nev' => $nev, 'kurzus' => $kurzus, 'cim' => $k['cim'], 'datum' => current_time('Y-m-d'), 'user_id' => $user_id];

        // Felső korlát: a legrégebbiek esnek ki (a kiállított PDF/nyomat ettől érvényes marad, csak online nem ellenőrizhető).
        if (count($t) > 20000) {
            $t = array_slice($t, -20000, null, true);
        }

        update_option(self::OPT_TANUSITVANY, $t, false);
        SDH_Sales_Naplo::ir('oktatas', 'Tanúsítvány kiállítva: ' . $k['cim'] . ' (' . $kod . ')', 'siker', 'tanusitvany', $user_id);

        if ($user_id > 0) {
            $sajat   = (array) get_user_meta($user_id, self::META . '_tanusitvany', true);
            $sajat[] = $kod;
            update_user_meta($user_id, self::META . '_tanusitvany', array_values(array_unique(array_filter($sajat))));
        }

        return ['kod' => $kod, 'url' => self::tanusitvany_url($kod)];
    }

    public static function tanusitvany_url(string $kod): string
    {
        return add_query_arg('tanusitvany', rawurlencode($kod), home_url('/'));
    }

    /** @return array<string, mixed>|null */
    public static function ellenoriz(string $kod): ?array
    {
        $kod = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $kod) ?? '');

        if (strlen($kod) !== 12) {
            return null;
        }

        $kod = substr($kod, 0, 4) . '-' . substr($kod, 4, 4) . '-' . substr($kod, 8, 4);
        $t   = self::tanusitvanyok()[$kod] ?? null;

        return is_array($t) ? ['kod' => $kod] + $t : null;
    }

    /** @return WP_REST_Response|WP_Error */
    public static function rest_tanusitvany(WP_REST_Request $r)
    {
        $ig = [];

        foreach ((array) $r->get_param('igazolasok') as $k => $v) {
            $ig[sanitize_key((string) $k)] = (string) $v;
        }

        $e = self::kiallit(sanitize_key((string) $r->get_param('kurzus')), (string) $r->get_param('nev'), $ig, get_current_user_id());

        return is_wp_error($e) ? $e : new WP_REST_Response($e, 200);
    }

    public static function rest_ellenoriz(WP_REST_Request $r): WP_REST_Response
    {
        $t = self::ellenoriz((string) $r->get_param('kod'));

        return new WP_REST_Response($t === null ? ['ervenyes' => false] : ['ervenyes' => true, 'kod' => $t['kod'], 'nev' => $t['nev'], 'kurzus' => $t['cim'], 'datum' => $t['datum']], 200);
    }

    /** Nyomtatható tanúsítvány (?tanusitvany=KOD). */
    public static function tanusitvany_oldal(): void
    {
        if (!isset($_GET['tanusitvany'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        if (!SDH_Sales_Rest::korlat('oktatas_nyomat', 60, 600)) {
            status_header(429);
            exit;
        }

        $t = self::ellenoriz(sanitize_text_field(wp_unslash((string) $_GET['tanusitvany']))); // phpcs:ignore WordPress.Security.NonceVerification

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        status_header($t === null ? 404 : 200);
        echo self::tanusitvany_html($t); // phpcs:ignore WordPress.Security.EscapeOutput -- a függvény escapel
        exit;
    }

    /** @param array<string, mixed>|null $t */
    public static function tanusitvany_html(?array $t): string
    {
        $termek = SDH_Sales::termeknev();
        $ceg    = (string) SDH_Sales::b('ceg.nev', '');
        $cim    = $t === null ? __('Érvénytelen tanúsítványkód', 'sdh-sales') : __('Tanúsítvány', 'sdh-sales');
        $css    = ':root{--s-bg:#fff;--s-szoveg:#111;--s-halvany:#555;--s-kiemelo:#0b6bcb;--s-vonal:#d0d4da;--s-r-kartya:16px;--s-r-gomb:999px}'
            . 'body{margin:0;background:var(--s-bg);color:var(--s-szoveg);font-family:var(--s-betu-szoveg,system-ui,sans-serif)}'
            . '.t{max-width:820px;margin:32px auto;padding:48px 40px;border:3px double var(--s-kiemelo);border-radius:var(--s-r-kartya);text-align:center}'
            . '.t h1{font-family:var(--s-betu-cim,inherit);font-size:2.4rem;margin:0 0 8px;color:var(--s-kiemelo)}.t .nev{font-size:2rem;font-weight:700;margin:24px 0 8px}'
            . '.t .kurzus{font-size:1.3rem;margin:8px 0 24px}.t .kis{color:var(--s-halvany);font-size:.95rem}.t code{font-family:var(--s-betu-kod,monospace);font-size:1.05rem}'
            . '.gomb{display:inline-block;margin:16px auto;padding:10px 20px;border-radius:var(--s-r-gomb);border:1px solid var(--s-kiemelo);background:var(--s-kiemelo);color:var(--s-bg);cursor:pointer;font:inherit}'
            . '@media print{.gomb{display:none}.t{margin:0;border-width:4px}}';

        $h  = '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">';
        $h .= '<title>' . esc_html($cim . ' – ' . $termek) . '</title>';

        if (class_exists('SDH_Sales_Arculat') && method_exists('SDH_Sales_Arculat', 'css')) {
            $css = $css . (string) SDH_Sales_Arculat::css();
        }

        $h .= '<style>' . wp_strip_all_tags($css) . '</style></head><body><main class="t">';

        if ($t === null) {
            $h .= '<h1>' . esc_html($cim) . '</h1><p>' . esc_html__('Ilyen kódú tanúsítványt nem állítottunk ki. Ellenőrizd a kódot.', 'sdh-sales') . '</p>';
        } else {
            $h .= '<p class="kis">' . esc_html($termek) . '</p><h1>' . esc_html($cim) . '</h1>';
            $h .= '<p>' . esc_html__('Igazoljuk, hogy', 'sdh-sales') . '</p><p class="nev">' . esc_html((string) $t['nev']) . '</p>';
            $h .= '<p>' . esc_html__('sikeresen elvégezte a következő kurzust:', 'sdh-sales') . '</p><p class="kurzus"><strong>' . esc_html((string) $t['cim']) . '</strong></p>';
            $h .= '<p class="kis">' . esc_html__('Dátum:', 'sdh-sales') . ' ' . esc_html(wp_date('Y. F j.', (int) strtotime((string) $t['datum'] . ' 12:00:00'))) . ($ceg !== '' ? ' · ' . esc_html($ceg) : '') . '</p>';
            $h .= '<p class="kis">' . esc_html__('Ellenőrző kód:', 'sdh-sales') . ' <code>' . esc_html((string) $t['kod']) . '</code><br>' . esc_html__('Ellenőrzés:', 'sdh-sales') . ' ' . esc_html(self::tanusitvany_url((string) $t['kod'])) . '</p>';
            $h .= '<button type="button" class="gomb" onclick="window.print()">' . esc_html__('Nyomtatás / mentés PDF-be', 'sdh-sales') . '</button>';
        }

        return $h . '</main></body></html>';
    }

    /* =================================================================
     * Fiók
     * ============================================================== */

    /**
     * @param mixed $adat
     * @return array<string, mixed>
     */
    public static function fiok($adat, $user_id = 0): array
    {
        $uid = (int) ($user_id ?: get_current_user_id());
        $h   = $uid > 0 ? self::haladas($uid) : [];
        $ki  = [];

        foreach (self::kurzusok() as $k) {
            $kesz = 0;

            foreach ($k['leckek'] as $lk) {
                $kesz += !empty($h[$lk]['kesz']) ? 1 : 0;
            }

            $ki[] = ['modul' => $k['modul'], 'cim' => $k['cim'], 'kesz' => $kesz, 'ossz' => count($k['leckek']), 'szazalek' => count($k['leckek']) > 0 ? (int) round($kesz * 100 / count($k['leckek'])) : 0];
        }

        $tan = [];

        foreach ((array) get_user_meta($uid, self::META . '_tanusitvany', true) as $kod) {
            $t = is_string($kod) ? self::ellenoriz($kod) : null;

            if ($t !== null) {
                $tan[] = ['kod' => $t['kod'], 'kurzus' => $t['cim'], 'datum' => $t['datum'], 'url' => self::tanusitvany_url($t['kod'])];
            }
        }

        return (is_array($adat) ? $adat : []) + ['kurzusok' => $ki, 'tanusitvanyok' => $tan];
    }

    /* =================================================================
     * Front
     * ============================================================== */

    public static function front_regisztral(): void
    {
        // A stílus közös a csevegővel: ugyanazzal a kezelővel töltjük, így egy oldalon csak egyszer jön le.
        SDH_Sales_Front::modul('oktatas', 'assets/front/oktatas.js', [], [self::class, 'js_adat']);
        add_action('wp', static function (): void {
            if (is_singular() && ($tart = (string) get_post_field('post_content', (int) get_queried_object_id())) !== '' && has_shortcode($tart, 'oktatas')) {
                self::stilus();
            }
        });
    }

    public static function stilus(): void
    {
        wp_enqueue_style('p-szikra', SDH_SALES_URL . 'assets/front/szikra.css', [], SDH_SALES_VERSION);
    }

    /** @return array<string, mixed> */
    public static function js_adat(): array
    {
        self::stilus();
        $szikra = class_exists('SDH_Sales_Szikra') && SDH_Sales_Szikra::aktiv();

        if ($szikra) {
            SDH_Sales_Front::igeny('szikra');
        }

        return [
            'rest'      => esc_url_raw(rest_url(SDH_Sales_Rest::NS . '/oktatas')),
            'nonce'     => is_user_logged_in() ? wp_create_nonce('wp_rest') : '', // a front X-App-Nonce fejlécben küldi
            'belepett'  => is_user_logged_in(),
            'leckek'    => self::nyilvanos(),
            'kurzusok'  => array_values(self::kurzusok()),
            'szikra'    => $szikra,
            'siker'     => self::SIKER_SZAZALEK,
            'szoveg'    => [
                'kurzusok'      => __('Kurzusok', 'sdh-sales'),
                'lecke'         => __('Lecke', 'sdh-sales'),
                'perc'          => __('perc', 'sdh-sales'),
                'cel'           => __('Cél', 'sdh-sales'),
                'lepes'         => __('Lépés', 'sdh-sales'),
                'elozo'         => __('Előző', 'sdh-sales'),
                'kovetkezo'     => __('Következő', 'sdh-sales'),
                'kviz'          => __('Kvíz', 'sdh-sales'),
                'kviz_inditas'  => __('Jöhet a kvíz', 'sdh-sales'),
                'ellenoriz'     => __('Ellenőrzés', 'sdh-sales'),
                'ujra'          => __('Újra', 'sdh-sales'),
                'helyes'        => __('Helyes', 'sdh-sales'),
                'hibas'         => __('Nem jó', 'sdh-sales'),
                'sikeres'       => __('Sikeres kvíz!', 'sdh-sales'),
                'sikertelen'    => __('Még nem sikerült – nézd át a magyarázatokat, és próbáld újra.', 'sdh-sales'),
                'kesz'          => __('Kész', 'sdh-sales'),
                'lecke_kesz'    => __('Lecke kész', 'sdh-sales'),
                'vissza'        => __('Vissza a leckékhez', 'sdh-sales'),
                'magyarazd'     => __('Magyarázd el, Szikra', 'sdh-sales'),
                'magyarazd_kerdes' => __('Magyarázd el ezt a lépést egyszerűen, példával.', 'sdh-sales'),
                'tanusitvany'   => __('Tanúsítvány', 'sdh-sales'),
                'tanusitvany_nev' => __('Név a tanúsítványon', 'sdh-sales'),
                'tanusitvany_ker' => __('Tanúsítvány kérése', 'sdh-sales'),
                'tanusitvany_kesz' => __('A tanúsítványod elkészült. Ellenőrző kód:', 'sdh-sales'),
                'tanusitvany_nyit' => __('Megnyitás és nyomtatás', 'sdh-sales'),
                'tanusitvany_feltetel' => __('A tanúsítványhoz a kurzus minden kvízét sikeresen ki kell töltened.', 'sdh-sales'),
                'haladas'       => __('Haladás', 'sdh-sales'),
                'nincs'         => __('Még nincs elérhető lecke.', 'sdh-sales'),
                'tobb'          => __('Több helyes válasz is lehet.', 'sdh-sales'),
                'hiba'          => __('Valami nem sikerült. Próbáld újra.', 'sdh-sales'),
                'helyi'         => __('A haladásodat ez a böngésző jegyzi meg. Bejelentkezve a fiókodba mentjük.', 'sdh-sales'),
            ],
        ];
    }

    /** [oktatas modul="…" lecke="…"] */
    public static function shortcode($atts): string
    {
        $a = shortcode_atts(['modul' => '', 'lecke' => ''], (array) $atts);
        SDH_Sales_Front::igeny('oktatas');

        return '<div data-modul="oktatas" data-opciok="' . esc_attr((string) wp_json_encode(['modul' => sanitize_key($a['modul']), 'lecke' => sanitize_key($a['lecke'])])) . '"></div>';
    }
}
