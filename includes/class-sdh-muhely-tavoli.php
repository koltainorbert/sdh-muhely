<?php
/**
 * Távoli karbantartás (0.99.0) – a központból kért műveletek végrehajtása a VEVŐ gépén.
 *
 * - A műveletek a központ aláírt szívverés-válaszában jönnek (a megjegyzett kulccsal ellenőrizve), azonosítóval és
 *   lejárattal; egy művelet csak egyszer fut le. Az eredmény a következő szívveréssel megy vissza, nyugtáig megmarad.
 * - Karbantartó művelet (diagnosztika, mentés, gyorsítótár, hálózat, frissítés) csak akkor fut, ha a licenc adja
 *   (jog: tavoli) ÉS a vevő adminisztrátora nem kapcsolta ki (Beállítások › Licenc). Adatot egyik sem küld el,
 *   és egyik sem nyit belépést.
 * - Üzenet: sáv a vevő adminisztrátorainak. Támogatási kérés: sáv „Engedélyezem / Elutasítom” gombbal – belépés
 *   csak a vevő kattintására jön létre (a meglévő, időkorlátos támogatási hozzáférés).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Tavoli
{
    public const OPT = 'sdh_muhely_tavoli';

    public const TIPUSOK = ['diagnosztika', 'mentes', 'gyorsitotar', 'halozat', 'frissites_keres', 'frissites_telepit', 'uzenet', 'uzenet_torol', 'tamogatas_keres'];

    /** Amihez nem kell a „távoli karbantartás” jog és engedély. */
    private const MINDIG = ['uzenet', 'uzenet_torol', 'tamogatas_keres'];

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_tavoli_valasz', [self::class, 'ajax_valasz']);
        add_action('wp_ajax_sdh_muhely_tavoli_uzenet_le', [self::class, 'ajax_uzenet_le']);
    }

    /** @return array<string, mixed> */
    public static function adat(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        return [
            'engedely'    => !array_key_exists('engedely', $m) || !empty($m['engedely']),
            'vegrehajtva' => array_map('intval', (array) ($m['vegrehajtva'] ?? [])),
            'eredmenyek'  => array_values(array_filter((array) ($m['eredmenyek'] ?? []), 'is_array')),
            'uzenet'      => is_array($m['uzenet'] ?? null) ? $m['uzenet'] : null,
            'keres'       => is_array($m['keres'] ?? null) ? $m['keres'] : null,
        ];
    }

    /** @param array<string, mixed> $valtozas */
    private static function ment(array $valtozas): void
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        update_option(self::OPT, $valtozas + $m, false);
    }

    public static function engedelyezve(): bool
    {
        return self::adat()['engedely'];
    }

    public static function engedely_ment(bool $be): void
    {
        self::ment(['engedely' => $be ? 1 : 0]);
    }

    /* =================================================================
     * Végrehajtás
     * ============================================================== */

    /**
     * A központ műveletei. A végrehajtottak száma.
     *
     * @param array<int, mixed>   $muveletek
     * @param array<string, bool> $jogok
     */
    public static function vegrehajt(array $muveletek, array $jogok, ?int $most = null): int
    {
        $most = $most ?? time();
        $db   = 0;

        foreach (array_slice($muveletek, 0, 10) as $m) {
            if (!is_array($m)) {
                continue;
            }

            $id    = (int) ($m['id'] ?? 0);
            $tipus = (string) ($m['tipus'] ?? '');
            $a     = self::adat();

            if ($id <= 0 || in_array($id, $a['vegrehajtva'], true)) {
                continue;
            }

            // Előbb jelöljük: ha a művelet közben leállna a PHP, se fusson le kétszer.
            self::ment(['vegrehajtva' => array_slice(array_merge($a['vegrehajtva'], [$id]), -200)]);

            if ((int) ($m['lejar'] ?? 0) > 0 && (int) $m['lejar'] < $most) {
                self::eredmeny($id, false, 'Lejárt, mielőtt megérkezett.');
                continue;
            }

            if (!in_array($tipus, self::TIPUSOK, true)) {
                self::eredmeny($id, false, 'Ezt a műveletet ez a verzió nem ismeri (' . SDH_MUHELY_VERSION . ').');
                continue;
            }

            if (!in_array($tipus, self::MINDIG, true) && (empty($jogok['tavoli']) || !$a['engedely'])) {
                self::eredmeny($id, false, empty($jogok['tavoli']) ? 'A licenc nem tartalmaz távoli karbantartást.' : 'A vevő kikapcsolta a távoli karbantartást.');
                continue;
            }

            $param = is_array($m['parameter'] ?? null) ? $m['parameter'] : [];

            try {
                $r = self::futtat($id, $tipus, $param, $jogok);
            } catch (Throwable $x) {
                $r = [false, 'Megszakadt: ' . SDH_Muhely_Telemetria::maszkol($x->getMessage()), []];
            }

            if ($r !== null) {
                self::eredmeny($id, $r[0], $r[1], $r[2] ?? []);
            }

            if (class_exists('SDH_Muhely_Valtozasnaplo')) {
                SDH_Muhely_Valtozasnaplo::esemeny('rendszer', 0, 'Távoli művelet a központból: ' . $tipus . '.');
            }

            $db++;
        }

        return $db;
    }

    /**
     * Egy művelet. [siker, üzenet, adat] vagy null, ha az eredmény később jön (támogatási kérés).
     *
     * @param array<string, mixed> $p
     * @param array<string, bool>  $jogok
     * @return array{0: bool, 1: string, 2?: array<string, mixed>}|null
     */
    private static function futtat(int $id, string $tipus, array $p, array $jogok): ?array
    {
        switch ($tipus) {
            case 'diagnosztika':
                $lassu = [];
                $sorok = class_exists('SDH_Muhely_Meres') ? SDH_Muhely_Meres::futtat($lassu) : [];

                return [true, $lassu === [] ? 'Minden mérési lépés gyors.' : 'Lassú lépések: ' . implode(', ', array_slice($lassu, 0, 8)) . '.', ['sorok' => array_slice($sorok, 0, 120)]];

            case 'mentes':
                if (!class_exists('SDH_Muhely_Mentes')) {
                    return [false, 'A mentés nem érhető el.'];
                }

                $r = SDH_Muhely_Mentes::keszit(true);

                if (is_array($r)) {
                    return [true, 'Mentés kész: ' . size_format((int) ($r['meret'] ?? 0)) . ', ' . (int) ($r['sorok'] ?? 0) . ' sor.' . SDH_Muhely_Mentes::kulso_osszegzes((array) ($r['kulso'] ?? [])), ['fajl' => (string) ($r['fajl'] ?? '')]];
                }

                return [false, is_string($r) ? $r : 'Most nem indítható (épp fut egy másik mentés, vagy nincs mentőmotor).'];

            case 'gyorsitotar':
                if (class_exists('SDH_Muhely_Gyorsitotar')) {
                    SDH_Muhely_Gyorsitotar::ervenytelenit();
                }

                foreach (['sdh_muhely_sugo_index', 'sdh_muhely_sugo_index_f', 'sdh_muhely_ai_statikus', 'sdh_muhely_ai_statikus_f'] as $t) {
                    delete_transient($t);
                }

                wp_cache_flush();
                $op = function_exists('opcache_reset') && @opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors

                return [true, 'Gyorsítótárak ürítve' . ($op ? ' (az OPcache is)' : '') . '.'];

            case 'halozat':
                $a = class_exists('SDH_Muhely_Halozat') ? SDH_Muhely_Halozat::allapot(true) : ['online' => true];

                return [true, !empty($a['online']) ? 'Van internet.' : 'Nincs internet (a helyi munka megy).', ['online' => !empty($a['online'])]];

            case 'frissites_keres':
                $u = SDH_Muhely_Frissites::uj_verzio(true);

                if ($u['hiba'] !== '') {
                    return [false, $u['hiba']];
                }

                return [true, SDH_Muhely_Frissites::van_ujabb($u) ? 'Elérhető: ' . $u['verzio'] . ' (most: ' . SDH_MUHELY_VERSION . ').' : 'Naprakész (' . SDH_MUHELY_VERSION . ').'];

            case 'frissites_telepit':
                if (empty($jogok['frissites'])) {
                    return [false, 'A licenc nem tartalmaz frissítést.'];
                }

                $r = SDH_Muhely_Frissites::telepit();

                return is_string($r) ? [false, $r] : [true, $r['uzenet'], ['verzio' => $r['verzio']]];

            case 'uzenet':
                $sz = mb_substr(sanitize_textarea_field((string) ($p['szoveg'] ?? '')), 0, 500);

                if ($sz === '') {
                    return [false, 'Üres üzenet.'];
                }

                self::ment(['uzenet' => ['szoveg' => $sz, 'szint' => in_array((string) ($p['szint'] ?? ''), ['info', 'figyel', 'hiba'], true) ? (string) $p['szint'] : 'info', 'ido' => time(), 'id' => $id]]);

                return [true, 'Az üzenet kint van a vevő adminisztrátorainál.'];

            case 'uzenet_torol':
                self::ment(['uzenet' => null]);

                return [true, 'Az üzenet levéve.'];

            case 'tamogatas_keres':
                if (empty($jogok['tamogatas'])) {
                    return [false, 'A licenc nem tartalmaz támogatást.'];
                }

                $ora = in_array((int) ($p['ora'] ?? 0), [2, 24, 72], true) ? (int) $p['ora'] : 2;
                self::ment(['keres' => ['id' => $id, 'ora' => $ora, 'indok' => mb_substr(sanitize_text_field((string) ($p['indok'] ?? '')), 0, 200), 'ido' => time()]]);

                return null;
        }

        return [false, 'Ismeretlen művelet.'];
    }

    /** @param array<string, mixed> $adat */
    public static function eredmeny(int $id, bool $ok, string $uzenet, array $adat = []): void
    {
        $a = self::adat();
        $a['eredmenyek'][] = ['id' => $id, 'ok' => $ok, 'uzenet' => mb_substr($uzenet, 0, 600), 'adat' => $adat, 'ido' => time()];
        self::ment(['eredmenyek' => array_slice($a['eredmenyek'], -20)]);
    }

    /** @return array<int, array<string, mixed>> A még nem nyugtázott eredmények. */
    public static function eredmenyek(): array
    {
        return self::adat()['eredmenyek'];
    }

    /** @param array<int, mixed> $ids */
    public static function nyugtaz(array $ids): void
    {
        $ids = array_map('intval', $ids);

        if ($ids === []) {
            return;
        }

        self::ment(['eredmenyek' => array_values(array_filter(self::eredmenyek(), static fn (array $e): bool => !in_array((int) ($e['id'] ?? 0), $ids, true)))]);
    }

    /* =================================================================
     * A vevő adminisztrátorának sávjai
     * ============================================================== */

    /** A támogatási kérés (24 óráig él). */
    public static function keres(?int $most = null): ?array
    {
        $k = self::adat()['keres'];

        return $k !== null && (int) ($k['ido'] ?? 0) > ($most ?? time()) - DAY_IN_SECONDS ? $k : null;
    }

    /**
     * Döntés a támogatási kérésről (az AJAX és a teszt is ezt hívja). Siker: '' vagy a link, hiba: üzenet.
     *
     * @return array{ok: bool, uzenet: string, link?: string}
     */
    public static function valasz(bool $engedi): array
    {
        $k = self::keres();

        if ($k === null) {
            return ['ok' => false, 'uzenet' => 'Ez a kérés már nem érvényes.'];
        }

        self::ment(['keres' => null]);

        if (!$engedi) {
            self::eredmeny((int) $k['id'], false, 'A vevő elutasította (' . wp_get_current_user()->display_name . ').');
            SDH_Muhely_Licenc::szivveres_hamarosan();

            return ['ok' => true, 'uzenet' => 'Elutasítva. A forgalmazó értesül róla.'];
        }

        $r = SDH_Muhely_Licenc::tamogatas_indit((int) $k['ora']);

        if (is_string($r)) {
            self::ment(['keres' => $k]);

            return ['ok' => false, 'uzenet' => $r];
        }

        self::eredmeny((int) $k['id'], true, 'A vevő engedélyezte ' . wp_date('Y.m.d. H:i', (int) $r['lejar']) . '-ig (' . wp_get_current_user()->display_name . ').');
        SDH_Muhely_Licenc::szivveres_hamarosan();

        return ['ok' => true, 'uzenet' => 'Engedélyezve ' . wp_date('Y.m.d. H:i', (int) $r['lejar']) . '-ig. A belépés a Beállítások › Licenc lapon visszavonható.', 'link' => (string) $r['link']];
    }

    /** A sávok a műhely tetején (csak adminisztrátornak). */
    public static function savok(): void
    {
        if (!class_exists('SDH_Muhely_Admin_UI') || !SDH_Muhely_Admin_UI::admin_e()) {
            return;
        }

        $a     = self::adat();
        $nonce = wp_create_nonce('sdh_muhely_tavoli');
        $u     = $a['uzenet'];
        $k     = self::keres();

        if ($u !== null) {
            $osztaly = ['figyel' => ' sdh-uzenet--figyelem', 'hiba' => ' sdh-uzenet--hiba'][(string) ($u['szint'] ?? 'info')] ?? '';
            echo '<div class="sdh-uzenet' . esc_attr($osztaly) . ' sdh-tavoli-sav" role="status" data-sdh-tavoli data-nonce="' . esc_attr($nonce) . '"><strong>Üzenet a forgalmazótól:</strong> '
                . nl2br(esc_html((string) $u['szoveg'])) . ' <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-tavoli-sav__gomb" data-sdh-tavoli-uzenet-le>Rendben</button></div>';
        }

        if ($k !== null) {
            echo '<div class="sdh-uzenet sdh-uzenet--figyelem sdh-tavoli-sav" role="alert" data-sdh-tavoli data-nonce="' . esc_attr($nonce) . '"><strong>A forgalmazó támogatási hozzáférést kér</strong> '
                . esc_html(SDH_Muhely_Licenc::TAMOGATAS_ORAK[(int) $k['ora']] ?? ((int) $k['ora'] . ' óra')) . 're'
                . ((string) $k['indok'] !== '' ? ': „' . esc_html((string) $k['indok']) . '”' : '') . '. Engedélyezéskor ideiglenes fiók jön létre, lejáratkor törlődik; minden belépés a Naplóba kerül. '
                . '<button type="button" class="sdh-gomb sdh-tavoli-sav__gomb" data-sdh-tavoli-valasz="1">Engedélyezem</button> '
                . '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-tavoli-sav__gomb" data-sdh-tavoli-valasz="0">Elutasítom</button> '
                . '<span class="sdh-mezo__sugo" data-sdh-tavoli-allapot role="status"></span></div>';
        }
    }

    private static function ajax_eleje(): void
    {
        check_ajax_referer('sdh_muhely_tavoli', '_wpnonce');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ezt csak adminisztrátor döntheti el.'], 403);
        }
    }

    public static function ajax_valasz(): void
    {
        self::ajax_eleje();
        $r = self::valasz(!empty($_POST['engedi'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- fent ellenőrizve.

        $r['ok'] ? wp_send_json_success($r) : wp_send_json_error($r);
    }

    public static function ajax_uzenet_le(): void
    {
        self::ajax_eleje();
        self::ment(['uzenet' => null]);
        wp_send_json_success(['uzenet' => 'Levéve.']);
    }
}
