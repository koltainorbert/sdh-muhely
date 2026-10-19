<?php
/**
 * SDH Értékesítés – napló és változásnapló (régi → új érték, visszaállítható).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Naplo
{
    /**
     * @param mixed $elotte
     * @param mixed $utana
     */
    public static function ir(string $tipus, string $szoveg, string $szint = 'info', string $ref_tipus = '', int $ref_id = 0, $elotte = null, $utana = null): int
    {
        global $wpdb;

        $u  = wp_get_current_user();
        $ki = $u instanceof WP_User && $u->exists() ? (string) $u->user_login : (defined('DOING_CRON') && DOING_CRON ? 'rendszer' : 'látogató');

        $wpdb->insert(SDH_Sales_Schema::t('naplo'), [
            'ido'       => current_time('mysql', true),
            'tipus'     => mb_substr($tipus, 0, 30),
            'szint'     => in_array($szint, ['info', 'figyel', 'hiba', 'siker'], true) ? $szint : 'info',
            'szoveg'    => mb_substr($szoveg, 0, 500),
            'ref_tipus' => mb_substr($ref_tipus, 0, 30),
            'ref_id'    => $ref_id,
            'elotte'    => $elotte === null ? null : (string) wp_json_encode($elotte),
            'utana'     => $utana === null ? null : (string) wp_json_encode($utana),
            'ki'        => mb_substr($ki, 0, 120),
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $szuro tipus, ref_tipus, ref_id, szint, kereses, oldal, db
     * @return array{sorok: array<int, array<string, mixed>>, ossz: int}
     */
    public static function lista(array $szuro = []): array
    {
        global $wpdb;

        $w = ['1=1'];
        $a = [];

        foreach (['tipus', 'ref_tipus', 'szint'] as $k) {
            if (!empty($szuro[$k])) {
                $w[] = "{$k} = %s";
                $a[] = (string) $szuro[$k];
            }
        }

        if (!empty($szuro['ref_id'])) {
            $w[] = 'ref_id = %d';
            $a[] = (int) $szuro['ref_id'];
        }

        if (!empty($szuro['kereses'])) {
            $w[] = 'szoveg LIKE %s';
            $a[] = '%' . $wpdb->esc_like((string) $szuro['kereses']) . '%';
        }

        $db    = max(1, min(200, (int) ($szuro['db'] ?? 50)));
        $oldal = max(1, (int) ($szuro['oldal'] ?? 1));
        $t     = SDH_Sales_Schema::t('naplo');
        $wh    = implode(' AND ', $w);

        $ossz = (int) $wpdb->get_var($a ? $wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$wh}", ...$a) : "SELECT COUNT(*) FROM {$t} WHERE {$wh}");
        $sql  = "SELECT * FROM {$t} WHERE {$wh} ORDER BY id DESC LIMIT %d OFFSET %d";
        $sor  = (array) $wpdb->get_results($wpdb->prepare($sql, ...array_merge($a, [$db, ($oldal - 1) * $db])), ARRAY_A);

        return ['sorok' => $sor, 'ossz' => $ossz];
    }

    /** @return array<string, mixed>|null */
    public static function egy(int $id): ?array
    {
        global $wpdb;

        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('naplo') . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($r) ? $r : null;
    }

    /**
     * Visszaállítás egy naplóbejegyzés „előtte” állapotára. A tényleges visszaírást a ref_tipus gazdája végzi
     * (`sdh_sales_visszaallit_<ref_tipus>` szűrő → true, ha sikerült). A beállításokat ez az osztály maga állítja vissza.
     */
    public static function visszaallit(int $id): string
    {
        $n = self::egy($id);

        if ($n === null || $n['elotte'] === null) {
            return 'Ehhez a bejegyzéshez nincs visszaállítható állapot.';
        }

        $elotte = json_decode((string) $n['elotte'], true);

        if ((string) $n['ref_tipus'] === 'beallitas' && is_array($elotte)) {
            $m = get_option(SDH_Sales::OPT, []);
            $m = is_array($m) ? $m : [];

            foreach ($elotte as $k => $v) {
                if ($v === null) {
                    unset($m[$k]);
                } else {
                    $m[$k] = $v;
                }
            }

            SDH_Sales::b_felulir($m);
            self::ir('visszaallitas', 'Beállítás visszaállítva a #' . $id . ' bejegyzés előtti állapotra.', 'figyel', 'naplo', $id);

            return '';
        }

        $ok = (bool) apply_filters('sdh_sales_visszaallit_' . (string) $n['ref_tipus'], false, (int) $n['ref_id'], $elotte, $n);

        if (!$ok) {
            return 'Ez a változás nem állítható vissza.';
        }

        self::ir('visszaallitas', 'Visszaállítva a #' . $id . ' bejegyzés előtti állapotra.', 'figyel', 'naplo', $id);

        return '';
    }
}
