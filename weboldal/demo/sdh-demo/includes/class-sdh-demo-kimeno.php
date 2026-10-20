<?php
/**
 * Kimenő üzenetek: a bemutatóban semmi nem megy ki, de látszik, mi menne.
 *
 * - E-mail: a `pre_wp_mail` szűrő (a műhely tesztüzeme előtt) elfogja – címzett, tárgy, szöveg – és „elküldöttnek”
 *   jelenti (a műhely felülete így a megszokott sikert mutatja).
 * - SMS: a műhely SMS-sora (tesztüzemben szimuláció) – az `sdh_muhely_sms_azonnal` szűrő jelzi, hogy SMS került a
 *   sorba; a kérés végén (és óránként, valamint a nézet megnyitásakor) az új SMS-sorok átkerülnek ide.
 * - Nézet: „Kimenő üzenetek” menüpont a műhely felületén (saját modul). A látogató a saját fiókja üzeneteit látja,
 *   az adminisztrátor mindet.
 *
 * @package SDH_Demo
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Demo_Kimeno
{
    public const KULCS = 'kimeno';

    private static bool $sms_jott = false;

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Kimenő üzenetek',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 92,
        ]);

        add_filter('sdh_muhely_sms_azonnal', [self::class, 'sms_jelzes'], 999);
        add_action('shutdown', [self::class, 'sms_shutdown'], 50);
    }

    /* =================================================================
     * Elfogás
     * ============================================================== */

    /**
     * A wp_mail elfogása. Visszatérés: true – a levél „elküldöttnek” számít, de nem megy ki.
     *
     * @param mixed                $elozo
     * @param array<string, mixed> $atts
     */
    public static function level($elozo, $atts): bool
    {
        global $wpdb;

        $atts    = is_array($atts) ? $atts : [];
        $cimzett = $atts['to'] ?? '';
        $cimzett = is_array($cimzett) ? implode(', ', array_map('strval', $cimzett)) : (string) $cimzett;
        $fejlec  = $atts['headers'] ?? '';
        $fejlec  = is_array($fejlec) ? implode("\n", array_map('strval', $fejlec)) : (string) $fejlec;
        $szoveg  = (string) ($atts['message'] ?? '');
        $html    = stripos($fejlec, 'text/html') !== false || preg_match('/<(html|body|p|div|br|table)\b/i', $szoveg) === 1;
        $mell    = $atts['attachments'] ?? [];
        $mell    = is_array($mell) ? count($mell) : ((string) $mell !== '' ? 1 : 0);

        if (!self::tabla_van()) {
            return true;
        }

        SDH_Demo_Keret::csendben(static function () use ($wpdb, $cimzett, $atts, $szoveg, $html, $mell): void {
            $wpdb->insert(SDH_Demo::tabla('kimeno'), [
                'csatorna'    => 'email',
                'cimzett'     => mb_substr($cimzett, 0, 255),
                'targy'       => mb_substr((string) ($atts['subject'] ?? ''), 0, 255),
                'szoveg'      => mb_substr($szoveg, 0, 200000) . ($mell > 0 ? "\n\n[" . $mell . ' melléklet]' : ''),
                'html'        => $html ? 1 : 0,
                'felhasznalo' => get_current_user_id(),
                'ido'         => time(),
            ]);
        });

        return true;
    }

    /** @param mixed $v @return mixed */
    public static function sms_jelzes($v)
    {
        self::$sms_jott = true;

        return $v;
    }

    public static function sms_shutdown(): void
    {
        if (self::$sms_jott) {
            self::sms_szinkron();
        }
    }

    /** Az SMS-sor új sorai a kimenő üzenetek közé. @return int átvett SMS-ek */
    public static function sms_szinkron(): int
    {
        global $wpdb;

        if (!self::tabla_van() || !class_exists('SDH_Muhely_Schema')) {
            return 0;
        }

        $k    = SDH_Demo::tabla('kimeno');
        $sms  = SDH_Muhely_Schema::tabla('sms_uzenet');
        $utso = (int) $wpdb->get_var("SELECT COALESCE(MAX(forras_id), 0) FROM {$k} WHERE csatorna = 'sms'");
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, szam, szam_eredeti, szoveg, felhasznalo, munkalap_id, allapot FROM {$sms} WHERE irany = 'ki' AND id > %d ORDER BY id LIMIT 500",
            $utso
        ));
        $db   = 0;

        SDH_Demo_Keret::csendben(static function () use ($wpdb, $k, $rows, &$db): void {
            foreach ((array) $rows as $r) {
                $cimzett = (string) ($r->szam !== '' ? $r->szam : $r->szam_eredeti);

                $wpdb->insert($k, [
                    'csatorna'    => 'sms',
                    'cimzett'     => $cimzett !== '' ? $cimzett : '(nincs szám)',
                    'targy'       => (int) $r->munkalap_id > 0 ? 'Munkalaphoz' : '',
                    'szoveg'      => (string) $r->szoveg,
                    'felhasznalo' => (int) $r->felhasznalo > 0 ? (int) $r->felhasznalo : get_current_user_id(),
                    'forras_id'   => (int) $r->id,
                    'ido'         => time(),
                ]);
                $db++;
            }
        });

        return $db;
    }

    private static function tabla_van(): bool
    {
        return get_option(SDH_Demo::OPT_DB) === SDH_Demo::DB_VERZIO;
    }

    /**
     * @return array<int, object>
     */
    public static function lista(int $felhasznalo = 0, string $csatorna = '', int $db = 100): array
    {
        global $wpdb;

        $k     = SDH_Demo::tabla('kimeno');
        $felt  = ['1=1'];
        $param = [];

        if ($felhasznalo > 0) {
            $felt[]  = 'felhasznalo = %d';
            $param[] = $felhasznalo;
        }

        if (in_array($csatorna, ['email', 'sms'], true)) {
            $felt[]  = 'csatorna = %s';
            $param[] = $csatorna;
        }

        $param[] = max(1, min(500, $db));
        $sql     = "SELECT * FROM {$k} WHERE " . implode(' AND ', $felt) . ' ORDER BY id DESC LIMIT %d';

        return (array) $wpdb->get_results($wpdb->prepare($sql, $param)); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /* =================================================================
     * Nézet (a műhely felületén belül)
     * ============================================================== */

    public static function oldal(): void
    {
        echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput -- a html() escapel.
    }

    public static function html(?int $felhasznalo = null, string $csatorna = ''): string
    {
        self::sms_szinkron();

        $felhasznalo = $felhasznalo ?? (SDH_Demo::demo_felhasznalo() ? get_current_user_id() : 0);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $csatorna = $csatorna !== '' ? $csatorna : (isset($_GET['csatorna']) ? sanitize_key(wp_unslash((string) $_GET['csatorna'])) : '');
        $lista    = self::lista($felhasznalo, $csatorna);
        $alap     = SDH_Muhely_Modulok::frontend_url(self::KULCS);
        $fulek    = ['' => 'Mind', 'email' => 'E-mail', 'sms' => 'SMS'];

        $h  = '<div class="bm-kimeno">';
        $h .= '<style>'
            . '.bm-kimeno{max-width:1100px;margin:0 auto;padding:clamp(12px,2.5vw,28px)}'
            . '.bm-kimeno h1{font-size:1.5rem;margin:0 0 .3rem}.bm-kimeno__lead{opacity:.8;margin:0 0 1rem;max-width:70ch}'
            . '.bm-kimeno__fulek{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 1rem}'
            . '.bm-kimeno__ful{display:inline-block;padding:.4rem .95rem;border-radius:999px;border:1px solid rgba(127,127,127,.45);color:inherit;text-decoration:none}'
            . '.bm-kimeno__ful[aria-current="page"]{background:#f5b301;border-color:#f5b301;color:#1d1a10;font-weight:600}'
            . '.bm-kimeno__lista{display:grid;gap:10px;list-style:none;margin:0;padding:0}'
            . '.bm-kimeno__elem{border:1px solid rgba(127,127,127,.35);border-radius:14px;padding:.8rem 1rem;background:rgba(127,127,127,.06)}'
            . '.bm-kimeno__fej{display:flex;flex-wrap:wrap;gap:.4rem .8rem;align-items:center}'
            . '.bm-kimeno__pill{display:inline-block;font-size:.78rem;font-weight:700;letter-spacing:.04em;padding:.15rem .6rem;border-radius:999px;background:rgba(127,127,127,.18)}'
            . '.bm-kimeno__pill--sms{background:#d7f0e3;color:#0d4a2c}.bm-kimeno__pill--email{background:#dfe8ff;color:#15306b}'
            . '.bm-kimeno__ido{opacity:.75;font-size:.88rem;margin-left:auto}'
            . '.bm-kimeno__targy{font-weight:600;margin:.35rem 0 0}'
            . '.bm-kimeno details{margin-top:.4rem}.bm-kimeno summary{cursor:pointer}'
            . '.bm-kimeno__szoveg{white-space:pre-wrap;word-break:break-word;margin:.5rem 0 0;font:inherit;max-height:420px;overflow:auto}'
            . '.bm-kimeno__ures{padding:1.2rem;border:1px dashed rgba(127,127,127,.5);border-radius:14px}'
            . '</style>';
        $h .= '<h1>Kimenő üzenetek</h1>';
        $h .= '<p class="bm-kimeno__lead">A bemutatóban semmi nem megy ki valódi címre vagy telefonra. Itt látod, milyen e-mailt és SMS-t '
            . 'kapna az ügyfél (vagy a kolléga) – ' . ($felhasznalo > 0 ? 'a te bemutatódból.' : 'minden látogatótól.') . '</p>';
        $h .= '<nav class="bm-kimeno__fulek" aria-label="Szűrés csatornára">';

        foreach ($fulek as $k => $c) {
            $h .= '<a class="bm-kimeno__ful" href="' . esc_url($k === '' ? $alap : add_query_arg('csatorna', $k, $alap)) . '"'
                . ($k === $csatorna ? ' aria-current="page"' : '') . '>' . esc_html($c) . '</a>';
        }

        $h .= '</nav>';

        if ($lista === []) {
            $h .= '<p class="bm-kimeno__ures">Még nincs kimenő üzenet. Próbáld ki: nyiss meg egy munkalapot, és küldj az ügyfélnek SMS-t vagy e-mailt '
                . '(pl. „kész, átvehető” értesítés), vagy állítsd a lapot olyan állapotba, amelyik értesítést küld.</p>';
        } else {
            $h .= '<ul class="bm-kimeno__lista">';

            foreach ($lista as $u) {
                $sms    = (string) $u->csatorna === 'sms';
                $szoveg = (int) $u->html === 1 ? trim((string) preg_replace("/\n{3,}/", "\n\n", wp_strip_all_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</tr>'], "\n", (string) $u->szoveg)))) : (string) $u->szoveg;
                $h     .= '<li class="bm-kimeno__elem"><div class="bm-kimeno__fej">'
                    . '<span class="bm-kimeno__pill bm-kimeno__pill--' . ($sms ? 'sms' : 'email') . '">' . ($sms ? 'SMS' : 'E-mail') . '</span>'
                    . '<span><span class="bm-kimeno__cimke">Címzett:</span> <strong>' . esc_html((string) $u->cimzett) . '</strong></span>'
                    . '<time class="bm-kimeno__ido" datetime="' . esc_attr(gmdate('c', (int) $u->ido)) . '">' . esc_html(wp_date('Y. m. d. H:i', (int) $u->ido)) . '</time>'
                    . '</div>'
                    . ((string) $u->targy !== '' && !$sms ? '<p class="bm-kimeno__targy">' . esc_html((string) $u->targy) . '</p>' : '')
                    . '<details' . ($sms ? ' open' : '') . '><summary>' . ($sms ? 'Az SMS szövege' : 'A levél szövege') . '</summary>'
                    . '<div class="bm-kimeno__szoveg">' . esc_html($szoveg) . '</div></details></li>';
            }

            $h .= '</ul>';
        }

        return $h . '</div>';
    }
}
