<?php
/**
 * SMS – az SMS menüpont és a popupok (0.57).
 *
 * Nézetek: Monitor (utolsó SMS-ek), Bejövő SMS, Sablonok (+ szabályok), Hívandók (amit emberi
 * hívással kell elintézni), Függő döntések (a „Később döntök” válaszok). Popupok: a státuszváltás
 * utáni „Küldjek SMS-t?”, az „Új SMS” (kézi küldés) és a sablonszerkesztő.
 *
 * Jogosultság: nézni és küldeni a normál CRM-joggal lehet; a sablonokat és szabályokat (és a
 * Beállítások › SMS oldalt) csak adminisztrátor szerkesztheti.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Sms_Felulet
{
    public const KULCS = 'sms';

    private const OLDAL_MERET = 50;

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'SMS',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 34,
            // Teendő: függő döntés + kézzel hívandó ügyfél.
            'jelveny' => [self::class, 'jelveny'],
        ]);

        $muveletek = [
            'smsdontes_urlap' => 'ajax_dontes_urlap',
            'smsuj_urlap'     => 'ajax_uj_urlap',
            'smssablon_urlap' => 'ajax_sablon_urlap',
            'sms_dontesek'    => 'ajax_dontesek',
            'sms_dontes_ment' => 'ajax_dontes_ment',
            'sms_elonezet'    => 'ajax_elonezet',
            'sms_kuld'        => 'ajax_kuld',
            'sms_sablon_ment' => 'ajax_sablon_ment',
            'sms_szabaly_ment' => 'ajax_szabaly_ment',
            'sms_hivott'      => 'ajax_hivott',
        ];

        foreach ($muveletek as $nev => $fuggveny) {
            add_action('wp_ajax_sdh_muhely_' . $nev, [self::class, $fuggveny]);
        }
    }

    /* =================================================================
     * Közös segédek
     * ============================================================== */

    /** @param array<string, mixed> $p */
    private static function url(array $p = []): string
    {
        return SDH_Muhely_Modulok::url(self::KULCS, $p);
    }

    private static function kontextus(): string
    {
        $k = isset($_REQUEST['kontextus']) ? sanitize_key(wp_unslash($_REQUEST['kontextus'])) : (wp_doing_ajax() ? 'admin' : SDH_Muhely_Modulok::kontextus());

        return $k === 'frontend' ? 'frontend' : 'admin';
    }

    /** @param array<string, mixed> $p */
    private static function vissza(array $p): string
    {
        return SDH_Muhely_Modulok::visszateres(self::KULCS, $p, self::kontextus());
    }

    private static function tabla(string $nev): string
    {
        return SDH_Muhely_Sms::tabla($nev);
    }

    private static function p(string $nev, string $alap = ''): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- a hívó ellenőrizte.
        return isset($_POST[$nev]) ? sanitize_text_field(wp_unslash((string) $_POST[$nev])) : $alap;
    }

    private static function jog(bool $admin = false): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        if ($admin && !SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ezt csak adminisztrátor módosíthatja.'], 403);
        }
    }

    private static function urlap_jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }
    }

    /** +36301112222 → +36 30 111 2222 (magyar számnál; másnál változatlan). */
    public static function szam_szep(string $szam): string
    {
        if (preg_match('/^\+36(\d{2})(\d{3})(\d{4})$/', $szam, $m)) {
            return '+36 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
        }

        return $szam;
    }

    private static function ido(?string $mysql): string
    {
        return $mysql ? mysql2date('Y.m.d. H:i', $mysql) : '';
    }

    /** Állapotjelző kapszula (a munkalap-állapotokkal azonos stílus). */
    public static function kapszula(string $allapot): string
    {
        [$felirat, $szin] = SDH_Muhely_Sms::allapot_cimke($allapot);

        return '<span class="sdh-allapot sdh-allapot--' . esc_attr($szin) . '">' . esc_html($felirat) . '</span>';
    }

    private static function kivalto_nev(string $k): string
    {
        return ['kezi' => 'Kézi', 'auto' => 'Automata', 'allapot' => 'Státuszváltás', 'onteszt' => 'Önteszt'][$k] ?? $k;
    }

    /* =================================================================
     * Menü-jelvény és számlálók
     * ============================================================== */

    /** Kézzel hívandó (nem ment ki) és még nem „Felhívtam”-mal elintézett SMS-ek. */
    public static function hivando_db(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::tabla('uzenet') . " WHERE irany = 'ki' AND tipus = 'ugyfel' AND hivott IS NULL AND allapot IN ('manualis','bizonytalan','nincs_szam')"
        );
    }

    public static function jelveny(): int
    {
        return SDH_Muhely_Sms_Szabaly::fuggo_db() + self::hivando_db();
    }

    /* =================================================================
     * A modul oldala
     * ============================================================== */

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        $nezet = isset($_GET['nezet']) ? sanitize_key(wp_unslash($_GET['nezet'])) : 'monitor';
        $nezet = in_array($nezet, ['monitor', 'bejovo', 'sablonok', 'hivando', 'dontesek'], true) ? $nezet : 'monitor';

        $gombok = [[
            'cimke'      => '+ Új SMS',
            'url'        => self::url(),
            'elsodleges' => true,
            'adatok'     => ['sdh-urlap' => 'smsuj', 'sdh-id' => '0'],
        ]];

        if ($nezet === 'sablonok' && SDH_Muhely_Admin_UI::admin_e()) {
            array_unshift($gombok, [
                'cimke'  => '+ Új sablon',
                'url'    => self::url(['nezet' => 'sablonok']),
                'adatok' => ['sdh-urlap' => 'smssablon', 'sdh-id' => '0'],
            ]);
        }

        echo '<div class="sdh-wrap sdh-sms">';
        SDH_Muhely_Admin_UI::uzenet();
        SDH_Muhely_Admin_UI::fejlec(
            'SMS',
            'A vevőknek küldött SMS-ek, a bejövő válaszok, a sablonok és ami emberi figyelmet kér.',
            $gombok
        );

        self::allapotsor();
        self::pillek($nezet);

        switch ($nezet) {
            case 'bejovo':
                self::bejovo();
                break;
            case 'sablonok':
                self::sablonok();
                break;
            case 'hivando':
                self::hivando();
                break;
            case 'dontesek':
                self::dontesek();
                break;
            default:
                self::monitor();
        }

        echo '</div>';
    }

    /** A rendszer állapota egy sorban: kikapcsolva / szimuláció / átjáró hibája. */
    private static function allapotsor(): void
    {
        $b = SDH_Muhely_Sms::beallitas();
        $a = SDH_Muhely_Sms::allapot();

        if (!$b['be']) {
            echo '<div class="sdh-uzenet sdh-uzenet--figyelem">Az SMS-küldés <strong>ki van kapcsolva</strong>, ezért semmi nem megy ki. '
                . (SDH_Muhely_Admin_UI::admin_e() ? 'Bekapcsolás: Beállítások › SMS-értesítések.' : 'A bekapcsolást adminisztrátor végezheti.') . '</div>';

            return;
        }

        if ($b['szimulacio']) {
            echo '<div class="sdh-uzenet sdh-uzenet--figyelem"><strong>Szimulációs mód:</strong> az SMS-ek csak a naplóba kerülnek, nem mennek ki.</div>';
        }

        if ((int) ($a['hiba_db'] ?? 0) > 0) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba"><strong>Az SMS-átjáró nem elérhető.</strong> ' . esc_html((string) ($a['atjaro_hiba'] ?? ''))
                . ' A sorban lévő SMS-ek az átjáró helyreállása után mennek ki.</div>';
        }
    }

    private static function pillek(string $aktiv): void
    {
        $p = [
            'monitor'  => ['Monitor', 0],
            'bejovo'   => ['Bejövő SMS', self::bejovo_olvasatlan()],
            'sablonok' => ['Sablonok', 0],
            'hivando'  => ['Hívandók', self::hivando_db()],
            'dontesek' => ['Függő döntések', SDH_Muhely_Sms_Szabaly::fuggo_db()],
        ];

        echo '<nav class="sdh-szolg-pillek" aria-label="Nézet">';

        foreach ($p as $k => [$cimke, $db]) {
            printf(
                '<a class="sdh-szolg-pill%s" href="%s"%s>%s%s</a>',
                $k === $aktiv ? ' is-aktiv' : '',
                esc_url(self::url(['nezet' => $k])),
                $k === $aktiv ? ' aria-current="page"' : '',
                esc_html($cimke),
                $db > 0 ? ' <span class="sdh-sms-db">' . (int) $db . '</span>' : ''
            );
        }

        echo '</nav>';
    }

    private static function bejovo_olvasatlan(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::tabla('uzenet') . " WHERE irany = 'be' AND olvasva = 0");
    }

    private static function lapozo(int $osszes, int $oldal, array $alap): void
    {
        $oldalak = max(1, (int) ceil($osszes / self::OLDAL_MERET));

        if ($oldalak < 2) {
            return;
        }

        echo '<div class="sdh-lapozo">';

        foreach (SDH_Muhely_Admin_UI::lapozo_oldalak($oldal, $oldalak) as $i) {
            if ($i === 0) {
                echo '<span class="sdh-lapozo__kihagy" aria-hidden="true">…</span>';
                continue;
            }

            echo $i === $oldal
                ? '<span class="sdh-lapozo__aktiv">' . (int) $i . '</span>'
                : '<a href="' . esc_url(self::url(array_merge($alap, ['oldalszam' => $i]))) . '">' . (int) $i . '</a>';
        }

        echo '</div>';
    }

    /* ---------------------------------------------------------------
     * Monitor
     * ------------------------------------------------------------ */

    private static function monitor(): void
    {
        global $wpdb;

        $allapot = isset($_GET['allapot']) ? sanitize_key(wp_unslash($_GET['allapot'])) : '';
        $kereses = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
        $oldal   = isset($_GET['oldalszam']) ? max(1, (int) $_GET['oldalszam']) : 1;
        $felt    = [];
        $ertek   = [];

        if ($allapot !== '') {
            $felt[]  = 'u.allapot = %s';
            $ertek[] = $allapot;
        }

        if ($kereses !== '') {
            $m       = '%' . $wpdb->esc_like($kereses) . '%';
            $felt[]  = '(u.szam LIKE %s OR u.szam_eredeti LIKE %s OR u.szoveg LIKE %s OR g.nev LIKE %s)';
            $ertek   = array_merge($ertek, [$m, $m, $m, $m]);
        }

        $hol = $felt === [] ? '' : 'WHERE ' . implode(' AND ', $felt);
        $ut  = self::tabla('uzenet');
        $gt  = SDH_Muhely_Schema::tabla('ugyfel');
        $mt  = SDH_Muhely_Schema::tabla('munkalap');

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- csak helyőrzők kerülnek bele.
        $osszes = (int) ($ertek === []
            ? $wpdb->get_var("SELECT COUNT(*) FROM {$ut} u LEFT JOIN {$gt} g ON g.id = u.ugyfel_id {$hol}")
            : $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ut} u LEFT JOIN {$gt} g ON g.id = u.ugyfel_id {$hol}", $ertek)));
        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT u.*, g.nev AS ugyfel_nev, m.munkalap_szam AS lap_szam FROM {$ut} u LEFT JOIN {$gt} g ON g.id = u.ugyfel_id LEFT JOIN {$mt} m ON m.id = u.munkalap_id {$hol} ORDER BY u.id DESC LIMIT %d OFFSET %d",
            array_merge($ertek, [self::OLDAL_MERET, ($oldal - 1) * self::OLDAL_MERET])
        ));
        // phpcs:enable

        ?>
        <form method="get" class="sdh-kereso" action="<?php echo esc_url(SDH_Muhely_Modulok::urlap_cel(self::KULCS)); ?>">
            <?php SDH_Muhely_Modulok::urlap_rejtett(self::KULCS); ?>
            <input type="hidden" name="nezet" value="monitor">
            <select name="allapot" aria-label="Állapot szerint">
                <option value="">Minden állapot</option>
                <?php foreach (['varakozik', 'pending', 'sent', 'delivered', 'failed', 'manualis', 'bizonytalan', 'nincs_szam', 'letiltva', 'szimulalt', 'bejott'] as $a) : ?>
                    <option value="<?php echo esc_attr($a); ?>" <?php selected($allapot, $a); ?>><?php echo esc_html(SDH_Muhely_Sms::allapot_cimke($a)[0]); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="k" value="<?php echo esc_attr($kereses); ?>" placeholder="Szám, név vagy szöveg…">
            <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Szűrés</button>
            <?php if ($allapot !== '' || $kereses !== '') : ?>
                <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(self::url(['nezet' => 'monitor'])); ?>">Szűrő törlése</a>
            <?php endif; ?>
            <span class="sdh-kereso__talalat"><?php echo esc_html(number_format_i18n($osszes)); ?> SMS</span>
        </form>

        <table class="sdh-tabla sdh-tabla--sms">
            <thead>
                <tr>
                    <th>Mikor</th>
                    <th>Ügyfél</th>
                    <th>Szám</th>
                    <th>Szöveg</th>
                    <th>Állapot</th>
                    <th class="sdh-tabla__rejtheto">Kiváltó</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$sorok) : ?>
                <tr><td colspan="6" class="sdh-tabla__ures">Még nincs SMS a naplóban.</td></tr>
            <?php else : ?>
                <?php foreach ($sorok as $s) : ?>
                    <tr>
                        <td class="sdh-tabla__halvany"><?php echo esc_html(self::ido((string) $s->letrehozva)); ?></td>
                        <td>
                            <?php echo esc_html((string) ($s->ugyfel_nev ?? '') !== '' ? (string) $s->ugyfel_nev : '—'); ?>
                            <?php if ((int) $s->munkalap_id > 0 && (int) ($s->lap_szam ?? 0) > 0) : ?>
                                <div class="sdh-tabla__halvany"><?php echo esc_html(SDH_Muhely_Munkalap::szam_formaz($s->lap_szam)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo (string) $s->irany === 'be' ? '↙ ' : '↗ '; ?>
                            <?php echo esc_html((string) $s->szam !== '' ? self::szam_szep((string) $s->szam) : (string) $s->szam_eredeti); ?>
                        </td>
                        <td class="sdh-sms-szoveg" title="<?php echo esc_attr((string) $s->szoveg); ?>"><?php echo esc_html(wp_trim_words((string) $s->szoveg, 18, '…')); ?></td>
                        <td>
                            <?php echo self::kapszula((string) $s->allapot); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <?php if ((string) $s->hiba !== '') : ?>
                                <div class="sdh-tabla__halvany"><?php echo esc_html((string) $s->hiba); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="sdh-tabla__rejtheto sdh-tabla__halvany">
                            <?php echo esc_html(self::kivalto_nev((string) $s->kivalto)); ?>
                            <?php if ((int) $s->proba > 1) : ?>
                                <div><?php echo (int) $s->proba; ?>. próba</div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php

        self::lapozo($osszes, $oldal, array_filter(['nezet' => 'monitor', 'allapot' => $allapot ?: null, 'k' => $kereses ?: null]));
    }

    /* ---------------------------------------------------------------
     * Bejövő SMS
     * ------------------------------------------------------------ */

    private static function bejovo(): void
    {
        global $wpdb;

        $ut    = self::tabla('uzenet');
        $sorok = $wpdb->get_results(
            "SELECT u.*, g.nev AS ugyfel_nev, m.munkalap_szam AS lap_szam FROM {$ut} u LEFT JOIN " . SDH_Muhely_Schema::tabla('ugyfel') . ' g ON g.id = u.ugyfel_id LEFT JOIN '
            . SDH_Muhely_Schema::tabla('munkalap') . " m ON m.id = u.munkalap_id WHERE u.irany = 'be' ORDER BY u.id DESC LIMIT 100"
        );
        $utolso = SDH_Muhely_Sms_Bejovo::utolso();

        if (SDH_Muhely_Admin_UI::admin_e()) :
            ?>
            <div class="sdh-sms-webhook">
                <label for="sdh-sms-webhook-url"><strong>Webhook-cím</strong> (ezt kell az SMS-átjáró app „Webhooks” részébe beírni, esemény: <code>sms:received</code>)</label>
                <input type="text" id="sdh-sms-webhook-url" readonly value="<?php echo esc_attr(SDH_Muhely_Sms_Bejovo::url()); ?>" onclick="this.select()">
                <p class="sdh-sms-sugo">
                    <?php echo $utolso > 0 ? 'Utolsó jelzés a telefontól: ' . esc_html(self::ido(wp_date('Y-m-d H:i:s', $utolso))) . '.' : 'A telefon még nem jelzett vissza.'; ?>
                    A címben titkos kulcs van – ne oszd meg. Helyi telepítésnél a telefonnak elérnie kell ezt a gépet (a webhook-cím a gép hálózati címére mutasson, ne a „localhost”-ra).
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--kicsi" data-sdh-sms-titok>Új kulcs kérése</button>
                </p>
            </div>
            <?php
        endif;

        ?>
        <table class="sdh-tabla sdh-tabla--sms">
            <thead><tr><th>Mikor</th><th>Feladó</th><th>Üzenet</th><th>Hová tartozik</th></tr></thead>
            <tbody>
            <?php if (!$sorok) : ?>
                <tr><td colspan="4" class="sdh-tabla__ures">Még nem érkezett SMS. A bejövő SMS-ek a telefon appjától jönnek webhookon át.</td></tr>
            <?php else : ?>
                <?php foreach ($sorok as $s) : ?>
                    <tr<?php echo (int) $s->olvasva === 0 ? ' class="sdh-sms-uj"' : ''; ?>>
                        <td class="sdh-tabla__halvany"><?php echo esc_html(self::ido((string) ($s->elkuldve ?: $s->letrehozva))); ?></td>
                        <td>
                            <?php echo esc_html((string) ($s->ugyfel_nev ?? '') !== '' ? (string) $s->ugyfel_nev : self::szam_szep((string) $s->szam)); ?>
                            <?php if ((string) ($s->ugyfel_nev ?? '') !== '') : ?>
                                <div class="sdh-tabla__halvany"><?php echo esc_html(self::szam_szep((string) $s->szam)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html((string) $s->szoveg); ?></td>
                        <td>
                            <?php if ((int) $s->parositva === 1 && (int) $s->munkalap_id > 0) : ?>
                                <?php echo esc_html(SDH_Muhely_Munkalap::szam_formaz($s->lap_szam)); ?>
                            <?php else : ?>
                                <span class="sdh-allapot sdh-allapot--narancs">Párosítatlan</span>
                                <form class="sdh-sms-parosit" data-sdh-sms-parosit="<?php echo (int) $s->id; ?>">
                                    <input type="text" name="munkalap_szam" inputmode="numeric" placeholder="Munkalap száma" aria-label="Munkalap száma" size="8">
                                    <button type="submit" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--kicsi">Hozzárendel</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php

        // A megnyitott lista után az SMS-ek olvasottak (a jelvény ettől csökken).
        $wpdb->query("UPDATE {$ut} SET olvasva = 1 WHERE irany = 'be' AND olvasva = 0 AND parositva = 1");
    }

    /* ---------------------------------------------------------------
     * Hívandók
     * ------------------------------------------------------------ */

    private static function hivando(): void
    {
        global $wpdb;

        $mind  = !empty($_GET['mind']);
        $ut    = self::tabla('uzenet');
        $hol   = $mind ? '' : 'AND u.hivott IS NULL';
        $sorok = $wpdb->get_results(
            "SELECT u.*, g.nev AS ugyfel_nev, g.telefon AS ugyfel_tel, g.telefon2 AS ugyfel_tel2 FROM {$ut} u LEFT JOIN " . SDH_Muhely_Schema::tabla('ugyfel')
            . " g ON g.id = u.ugyfel_id WHERE u.irany = 'ki' AND u.tipus = 'ugyfel' AND u.allapot IN ('manualis','bizonytalan','nincs_szam') {$hol} ORDER BY u.id DESC LIMIT 200"
        );

        ?>
        <p class="sdh-sms-sugo">Ide kerül minden SMS, amelyről nem biztos, hogy megérkezett az ügyfélhez. Hívd fel, majd jelöld: <strong>Felhívtam</strong>.
            <a href="<?php echo esc_url(self::url(['nezet' => 'hivando', 'mind' => $mind ? null : 1])); ?>"><?php echo $mind ? 'Csak a hívandók' : 'Az elintézettek is'; ?></a></p>
        <table class="sdh-tabla sdh-tabla--sms">
            <thead><tr><th>Mikor</th><th>Ügyfél</th><th>Mi történt</th><th>Az SMS szövege</th><th></th></tr></thead>
            <tbody>
            <?php if (!$sorok) : ?>
                <tr><td colspan="5" class="sdh-tabla__ures">Nincs hívandó ügyfél. Minden SMS rendben elment.</td></tr>
            <?php else : ?>
                <?php foreach ($sorok as $s) : ?>
                    <tr data-sdh-sms-sor="<?php echo (int) $s->id; ?>">
                        <td class="sdh-tabla__halvany"><?php echo esc_html(self::ido((string) $s->letrehozva)); ?></td>
                        <td>
                            <?php echo esc_html((string) ($s->ugyfel_nev ?? '') !== '' ? (string) $s->ugyfel_nev : '—'); ?>
                            <div class="sdh-tabla__halvany">
                                <?php echo esc_html(implode(' · ', array_filter([(string) ($s->ugyfel_tel ?? ''), (string) ($s->ugyfel_tel2 ?? '')]))); ?>
                            </div>
                        </td>
                        <td><?php echo self::kapszula((string) $s->allapot); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <div class="sdh-tabla__halvany"><?php echo esc_html((string) $s->hiba); ?></div></td>
                        <td><?php echo esc_html((string) $s->szoveg); ?></td>
                        <td>
                            <?php if ($s->hivott === null) : ?>
                                <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-sms-hivott="<?php echo (int) $s->id; ?>">Felhívtam</button>
                            <?php else : ?>
                                <span class="sdh-tabla__halvany">Felhívva: <?php echo esc_html(self::ido((string) $s->hivott)); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /* ---------------------------------------------------------------
     * Függő döntések
     * ------------------------------------------------------------ */

    private static function dontesek(): void
    {
        $lista = SDH_Muhely_Sms_Szabaly::fuggo_lista();

        ?>
        <p class="sdh-sms-sugo">Ezeknél a státuszváltásoknál még nem dőlt el, hogy menjen-e SMS az ügyfélnek.</p>
        <table class="sdh-tabla sdh-tabla--sms">
            <thead><tr><th>Mikor</th><th>Munkalap</th><th>Új állapot</th><th>Megjegyzés</th><th></th></tr></thead>
            <tbody>
            <?php if (!$lista) : ?>
                <tr><td colspan="5" class="sdh-tabla__ures">Nincs függő döntés.</td></tr>
            <?php else : ?>
                <?php foreach ($lista as $d) : ?>
                    <?php $lap = SDH_Muhely_Rma::munkalap((int) $d->munkalap_id); ?>
                    <tr>
                        <td class="sdh-tabla__halvany"><?php echo esc_html(self::ido((string) $d->letrehozva)); ?></td>
                        <td><?php echo esc_html($lap ? SDH_Muhely_Munkalap::szam_formaz($lap->munkalap_szam) : '—'); ?></td>
                        <td><?php echo esc_html(SDH_Muhely_Sms_Szabaly::allapot_nev((string) $d->terulet, (string) $d->allapot)); ?></td>
                        <td class="sdh-tabla__halvany"><?php echo esc_html((string) $d->ok); ?></td>
                        <td><a class="sdh-gomb sdh-gomb--elsodleges" href="<?php echo esc_url(self::url(['nezet' => 'dontesek'])); ?>"
                               data-sdh-urlap="smsdontes" data-sdh-id="<?php echo (int) $d->id; ?>">Döntés</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /* ---------------------------------------------------------------
     * Sablonok és szabályok
     * ------------------------------------------------------------ */

    private static function sablonok(): void
    {
        $admin   = SDH_Muhely_Admin_UI::admin_e();
        $sablonok = SDH_Muhely_Sms_Szabaly::sablonok();

        ?>
        <h2 class="sdh-sms-cim">Sablonok</h2>
        <table class="sdh-tabla sdh-tabla--sms">
            <thead><tr><th>Név</th><th>Szöveg</th><th class="sdh-tabla__szam">SMS</th><th>Állapot</th><th></th></tr></thead>
            <tbody>
            <?php if (!$sablonok) : ?>
                <tr><td colspan="5" class="sdh-tabla__ures">Még nincs sablon.</td></tr>
            <?php else : ?>
                <?php foreach ($sablonok as $s) : ?>
                    <?php $sz = SDH_Muhely_Sms::szegmensek((string) $s->szoveg); ?>
                    <tr>
                        <td class="sdh-tabla__nev">
                            <?php echo esc_html((string) $s->nev); ?>
                            <?php if ((int) $s->pelda === 1) : ?>
                                <div><span class="sdh-allapot sdh-allapot--narancs">PÉLDA – jóváhagyásra vár</span></div>
                            <?php endif; ?>
                            <div class="sdh-tabla__halvany">v<?php echo (int) $s->verzio; ?></div>
                        </td>
                        <td class="sdh-sms-szoveg"><?php echo esc_html((string) $s->szoveg); ?></td>
                        <td class="sdh-tabla__szam" title="<?php echo esc_attr($sz['kodolas'] . ', ' . $sz['hossz'] . ' karakter'); ?>"><?php echo (int) $sz['szegmens']; ?></td>
                        <td><?php echo (int) $s->aktiv === 1
                            ? '<span class="sdh-allapot sdh-allapot--zold">Aktív</span>'
                            : '<span class="sdh-allapot sdh-allapot--szurke">Kikapcsolva</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                        <td><a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(self::url(['nezet' => 'sablonok'])); ?>"
                               data-sdh-urlap="smssablon" data-sdh-id="<?php echo (int) $s->id; ?>"><?php echo $admin ? 'Megnyit' : 'Megnéz'; ?></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <h2 class="sdh-sms-cim">Mikor ajánljon SMS-t?</h2>
        <p class="sdh-sms-sugo">Állapotonként beállíthatod, melyik sablon tartozik hozzá, és mi történjen: <strong>rákérdez</strong> (popup, ez a javasolt), <strong>automatikusan küld</strong>
            (csak ha a szöveg hiánytalanul kitölthető és van használható szám), vagy <strong>nem küld</strong>. Csak aktív sablon választható.</p>
        <?php

        self::szabaly_urlap($admin);
    }

    private static function szabaly_urlap(bool $admin): void
    {
        $aktiv   = SDH_Muhely_Sms_Szabaly::sablonok(true);
        $szabaly = SDH_Muhely_Sms_Szabaly::szabalyok();
        $csoportok = [
            'lap'      => ['Javítási munkalap állapotai', SDH_Muhely_Munkalap::allapotok()],
            'rendeles' => ['Rendelések útja', class_exists('SDH_Muhely_Eladas') ? SDH_Muhely_Eladas::rend_allapotok() : []],
        ];

        ?>
        <form class="sdh-urlap sdh-sms-szabalyok" data-sdh-sms-szabalyok>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <?php foreach ($csoportok as $terulet => [$cim, $allapotok]) : ?>
                <?php if (!$allapotok) { continue; } ?>
                <h3 class="sdh-sms-alcim"><?php echo esc_html($cim); ?></h3>
                <table class="sdh-tabla sdh-tabla--sms">
                    <thead><tr><th>Állapot</th><th>Sablon</th><th>Mit tegyen?</th><th>Késleltetés (perc)</th></tr></thead>
                    <tbody>
                    <?php foreach ($allapotok as $kulcs => $a) : ?>
                        <?php
                        $sz  = $szabaly[$terulet . '|' . $kulcs] ?? null;
                        $nev = $terulet . '[' . $kulcs . ']';
                        ?>
                        <tr>
                            <td><span class="sdh-allapot sdh-allapot--<?php echo esc_attr((string) $a['szin']); ?>"><?php echo esc_html((string) $a['nev']); ?></span></td>
                            <td>
                                <select name="<?php echo esc_attr($nev); ?>[sablon_id]" <?php disabled(!$admin); ?> aria-label="Sablon">
                                    <option value="0">— nincs —</option>
                                    <?php foreach ($aktiv as $s) : ?>
                                        <option value="<?php echo (int) $s->id; ?>" <?php selected($sz ? (int) $sz->sablon_id : 0, (int) $s->id); ?>><?php echo esc_html((string) $s->nev); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="<?php echo esc_attr($nev); ?>[mukodes]" <?php disabled(!$admin); ?> aria-label="Működés">
                                    <?php foreach (SDH_Muhely_Sms_Szabaly::mukodesek() as $k => $felirat) : ?>
                                        <option value="<?php echo esc_attr($k); ?>" <?php selected($sz ? (string) $sz->mukodes : 'kerdez', $k); ?>><?php echo esc_html($felirat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" min="0" max="10080" name="<?php echo esc_attr($nev); ?>[kesleltetes]" value="<?php echo (int) ($sz->kesleltetes ?? 0); ?>" <?php disabled(!$admin); ?> aria-label="Késleltetés percben"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>

            <?php if ($admin) : ?>
                <div class="sdh-urlap__lablec">
                    <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Szabályok mentése</button>
                    <span class="sdh-sms-uzenet" data-sdh-sms-uzenet aria-live="polite"></span>
                </div>
            <?php else : ?>
                <p class="sdh-sms-sugo">A szabályokat adminisztrátor módosíthatja.</p>
            <?php endif; ?>
        </form>
        <?php
    }

    /* =================================================================
     * Popupok
     * ============================================================== */

    /** A számok blokkja: kihez megy az SMS, és ha nem lehet, miért. @return bool van használható szám */
    private static function szamok_blokk(int $ugyfel_id): bool
    {
        $sz = SDH_Muhely_Sms::ugyfel_szamai($ugyfel_id);
        $ok = false;

        echo '<div class="sdh-sms-szamok">';

        if ($sz['ugyfel'] === null) {
            echo '<p class="sdh-sms-figy">Nincs ügyfél a laphoz – add meg a telefonszámot.</p></div>';

            return false;
        }

        if ($sz['tilt']) {
            echo '<p class="sdh-sms-figy">Az ügyfél jelezte, hogy <strong>nem kér SMS-t</strong>. Az automatikus SMS-ek nem mennek neki; kézzel küldhetsz, ha indokolt.</p>';
        }

        if (!$sz['szamok']) {
            echo '<p class="sdh-sms-figy">Az ügyfélnek nincs telefonszáma, az SMS nem küldhető.</p></div>';

            return false;
        }

        echo '<ul class="sdh-sms-szamlista">';

        foreach ($sz['szamok'] as $n) {
            if (!empty($n['ok'])) {
                $ok = true;
                echo '<li>' . esc_html(self::szam_szep((string) $n['szam'])) . ' <small>' . ($n['forras'] === 'ugyfel1' ? '1. telefonmező' : '2. telefonmező (tartalék)') . '</small></li>';
            } else {
                echo '<li class="is-hiba">' . esc_html((string) $n['eredeti'] !== '' ? (string) $n['eredeti'] : '(üres)') . ' – ' . esc_html((string) $n['uzenet']) . '</li>';
            }
        }

        echo '</ul>';

        if (!$ok) {
            echo '<p class="sdh-sms-figy">Egyik szám sem használható – az SMS nem küldhető. Javítsd a számot az ügyfél adatlapján.</p>';
        }

        echo '</div>';

        return $ok;
    }

    /** A sablonválasztó, a szövegmező és a számláló. */
    private static function szovegmezok(array $a): void
    {
        $sablonok = SDH_Muhely_Sms_Szabaly::sablonok(true);
        $kijelolt = (int) ($a['sablon_id'] ?? 0);

        if ($kijelolt > 0 && !array_filter($sablonok, static fn ($s) => (int) $s->id === $kijelolt)) {
            $e = SDH_Muhely_Sms_Szabaly::sablon($kijelolt);

            if ($e) {
                $sablonok[] = $e;
            }
        }

        ?>
        <div class="sdh-sor">
            <label for="sms_sablon">Sablon</label>
            <select name="sablon_id" id="sms_sablon" data-sdh-sms-sablon
                    data-munkalap="<?php echo (int) ($a['munkalap_id'] ?? 0); ?>" data-eszkoz="<?php echo (int) ($a['eszkoz_id'] ?? 0); ?>"
                    data-ugyfel="<?php echo (int) ($a['ugyfel_id'] ?? 0); ?>" data-terulet="<?php echo esc_attr((string) ($a['terulet'] ?? 'lap')); ?>"
                    data-allapot="<?php echo esc_attr((string) ($a['allapot'] ?? '')); ?>">
                <option value="0">— szabad szöveg —</option>
                <?php foreach ($sablonok as $s) : ?>
                    <option value="<?php echo (int) $s->id; ?>" <?php selected($kijelolt, (int) $s->id); ?>><?php echo esc_html((string) $s->nev); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sdh-sor">
            <label for="sms_szoveg">Az SMS szövege</label>
            <textarea name="szoveg" id="sms_szoveg" rows="5" maxlength="<?php echo (int) SDH_Muhely_Sms_Szabaly::SZOVEG_MAX; ?>" data-sdh-sms-szoveg><?php echo esc_textarea((string) ($a['szoveg'] ?? '')); ?></textarea>
            <div class="sdh-sms-szaml" data-sdh-sms-szaml aria-live="polite"></div>
            <p class="sdh-sms-figy" data-sdh-sms-hianyzik <?php echo empty($a['hianyzik']) ? 'hidden' : ''; ?>><?php
                if (!empty($a['hianyzik'])) {
                    echo esc_html('Ehhez a laphoz nem tölthető ki: ' . implode(', ', array_map(static fn ($n) => SDH_Muhely_Sms_Szabaly::valtozok()[$n] ?? $n, $a['hianyzik'])) . '. Egészítsd ki kézzel.');
                }
            ?></p>
        </div>
        <?php
    }

    public static function ajax_dontes_urlap(): void
    {
        self::urlap_jog();

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $d  = SDH_Muhely_Sms_Szabaly::dontes($id);

        if (!$d) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs ilyen kérdés.</div>';
            wp_die();
        }

        $lap    = SDH_Muhely_Rma::munkalap((int) $d->munkalap_id);
        $ugyfel = $lap ? SDH_Muhely_Rma::ugyfel((int) $lap->ugyfel_id) : null;
        $szam   = $lap && (int) $lap->munkalap_szam > 0 ? SDH_Muhely_Munkalap::szam_formaz($lap->munkalap_szam) : '';
        $allapot = SDH_Muhely_Sms_Szabaly::allapot_nev((string) $d->terulet, (string) $d->allapot);

        ?>
        <h2 class="sdh-modal__cim">Küldjek SMS-t az ügyfélnek?</h2>
        <p class="sdh-modal__alcim"><?php echo esc_html(implode(' · ', array_filter([$ugyfel ? (string) $ugyfel->nev : '', $szam, 'új állapot: ' . $allapot]))); ?></p>
        <?php

        if ($d->lezarva !== null) {
            echo '<div class="sdh-uzenet">Erről már döntöttek.</div>';
            wp_die();
        }

        $sablon = SDH_Muhely_Sms_Szabaly::sablon((int) $d->sablon_id);
        $k      = SDH_Muhely_Sms_Szabaly::kitolt($sablon ? (string) $sablon->szoveg : '', SDH_Muhely_Sms_Szabaly::ertekek((int) $d->munkalap_id, (int) $d->eszkoz_id, (string) $d->terulet, (string) $d->allapot));

        ?>
        <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-sdh-ajax-action="sdh_muhely_sms_dontes_ment" data-sdh-sms-urlap>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::kontextus()); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $d->id; ?>">
            <input type="hidden" name="valasz" value="kuld" data-sdh-sms-valasz>
            <div class="sdh-ugyfelurlap">
                <?php
                $van_szam = self::szamok_blokk($lap ? (int) $lap->ugyfel_id : 0);

                self::szovegmezok([
                    'sablon_id' => (int) $d->sablon_id, 'munkalap_id' => (int) $d->munkalap_id, 'eszkoz_id' => (int) $d->eszkoz_id,
                    'ugyfel_id' => $lap ? (int) $lap->ugyfel_id : 0, 'terulet' => (string) $d->terulet, 'allapot' => (string) $d->allapot,
                    'szoveg' => $k['szoveg'], 'hianyzik' => $k['hianyzik'],
                ]);

                if ((string) $d->ok !== '') {
                    echo '<p class="sdh-sms-figy">' . esc_html((string) $d->ok) . '</p>';
                }
                ?>
                <div class="sdh-urlap__lablec">
                    <button type="submit" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-sms-gomb="kuld" <?php disabled(!$van_szam); ?>>SMS küldése</button>
                    <button type="submit" class="sdh-gomb sdh-gomb--vilagos" data-sdh-sms-gomb="nem">Nem küldök</button>
                    <button type="submit" class="sdh-gomb sdh-gomb--vilagos" data-sdh-sms-gomb="kesobb">Később döntök</button>
                </div>
            </div>
        </form>
        <?php

        wp_die();
    }

    public static function ajax_uj_urlap(): void
    {
        self::urlap_jog();

        $lap_id    = isset($_GET['munkalap_id']) ? (int) $_GET['munkalap_id'] : 0;
        $ugyfel_id = isset($_GET['ugyfel_id']) ? (int) $_GET['ugyfel_id'] : 0;
        $eszkoz_id = isset($_GET['eszkoz_id']) ? (int) $_GET['eszkoz_id'] : 0;
        $lap       = $lap_id > 0 ? SDH_Muhely_Rma::munkalap($lap_id) : null;

        if ($lap) {
            $ugyfel_id = (int) $lap->ugyfel_id;
        }

        $ugyfel = $ugyfel_id > 0 ? SDH_Muhely_Rma::ugyfel($ugyfel_id) : null;
        $szam   = $lap && (int) $lap->munkalap_szam > 0 ? SDH_Muhely_Munkalap::szam_formaz($lap->munkalap_szam) : '';

        ?>
        <h2 class="sdh-modal__cim">Új SMS</h2>
        <p class="sdh-modal__alcim"><?php echo esc_html(implode(' · ', array_filter([$ugyfel ? (string) $ugyfel->nev : 'Önálló SMS', $szam]))); ?></p>
        <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-sdh-ajax-action="sdh_muhely_sms_kuld" data-sdh-sms-urlap>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::kontextus()); ?>">
            <input type="hidden" name="ugyfel_id" value="<?php echo (int) $ugyfel_id; ?>">
            <input type="hidden" name="munkalap_id" value="<?php echo (int) $lap_id; ?>">
            <input type="hidden" name="eszkoz_id" value="<?php echo (int) $eszkoz_id; ?>">
            <div class="sdh-ugyfelurlap">
                <?php
                self::szamok_blokk($ugyfel_id);

                ?>
                <div class="sdh-sor">
                    <label for="sms_szam"><?php echo $ugyfel ? 'Másik szám (elhagyható)' : 'Telefonszám'; ?></label>
                    <input type="tel" name="szam" id="sms_szam" inputmode="tel" autocomplete="off" placeholder="pl. 06 30 123 4567" <?php echo $ugyfel ? '' : 'required'; ?>>
                </div>
                <?php

                self::szovegmezok([
                    'sablon_id' => 0, 'munkalap_id' => $lap_id, 'eszkoz_id' => $eszkoz_id, 'ugyfel_id' => $ugyfel_id,
                    'terulet' => 'lap', 'allapot' => '', 'szoveg' => '', 'hianyzik' => [],
                ]);

                ?>
                <div class="sdh-urlap__lablec">
                    <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">SMS küldése</button>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                </div>
            </div>
        </form>
        <?php

        wp_die();
    }

    public static function ajax_sablon_urlap(): void
    {
        self::urlap_jog();

        $id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $s     = $id > 0 ? SDH_Muhely_Sms_Szabaly::sablon($id) : null;
        $admin = SDH_Muhely_Admin_UI::admin_e();

        if ($id > 0 && !$s) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs ilyen sablon.</div>';
            wp_die();
        }

        ?>
        <h2 class="sdh-modal__cim"><?php echo esc_html($s ? (string) $s->nev : 'Új sablon'); ?></h2>
        <?php if ($s && (int) $s->pelda === 1) : ?>
            <p class="sdh-modal__alcim"><span class="sdh-allapot sdh-allapot--narancs">PÉLDA – jóváhagyásra vár</span> Olvasd át, írd át, ha kell, majd mentsd: a mentés a jóváhagyásod.</p>
        <?php endif; ?>
        <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-sdh-ajax-action="sdh_muhely_sms_sablon_ment" data-sdh-sms-urlap>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::kontextus()); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <div class="sdh-ugyfelurlap">
                <div class="sdh-sor">
                    <label for="sms_sablon_nev">Név</label>
                    <input type="text" name="nev" id="sms_sablon_nev" maxlength="120" required value="<?php echo esc_attr($s ? (string) $s->nev : ''); ?>" <?php disabled(!$admin); ?>>
                </div>
                <div class="sdh-sor">
                    <label for="sms_szoveg">Szöveg</label>
                    <textarea name="szoveg" id="sms_szoveg" rows="5" required maxlength="<?php echo (int) SDH_Muhely_Sms_Szabaly::SZOVEG_MAX; ?>" data-sdh-sms-szoveg <?php disabled(!$admin); ?>><?php echo esc_textarea($s ? (string) $s->szoveg : ''); ?></textarea>
                    <div class="sdh-sms-szaml" data-sdh-sms-szaml aria-live="polite"></div>
                </div>
                <div class="sdh-sor sdh-sms-valtozok">
                    <span class="sdh-ig__cimke">Változók (kattintásra beszúródnak)</span>
                    <?php foreach (SDH_Muhely_Sms_Szabaly::valtozok() as $k => $mit) : ?>
                        <button type="button" class="sdh-gomb sdh-gomb--pill" data-sdh-sms-valtozo="{<?php echo esc_attr($k); ?>}" title="<?php echo esc_attr($mit); ?>" <?php disabled(!$admin); ?>>{<?php echo esc_html($k); ?>}</button>
                    <?php endforeach; ?>
                </div>
                <div class="sdh-sor">
                    <label class="sdh-jelolo" for="sms_sablon_aktiv">
                        <input type="checkbox" name="aktiv" id="sms_sablon_aktiv" value="1" <?php checked($s && (int) $s->aktiv === 1); ?> <?php disabled(!$admin); ?>>
                        Aktív (csak aktív sablon használható küldéshez és szabályhoz)
                    </label>
                </div>
                <?php if ($s) : ?>
                    <details class="sdh-sms-verziok">
                        <summary>Korábbi változatok (<?php echo (int) $s->verzio; ?> verzió)</summary>
                        <?php foreach (SDH_Muhely_Sms_Szabaly::verziok((int) $s->id) as $v) : ?>
                            <?php $f = (int) $v->felhasznalo > 0 ? get_userdata((int) $v->felhasznalo) : false; ?>
                            <div class="sdh-sms-verzio">
                                <strong>v<?php echo (int) $v->verzio; ?></strong>
                                <span class="sdh-tabla__halvany"><?php echo esc_html(self::ido((string) $v->letrehozva) . ($f ? ' · ' . $f->display_name : '')); ?></span>
                                <div><?php echo esc_html((string) $v->szoveg); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </details>
                <?php endif; ?>
                <div class="sdh-urlap__lablec">
                    <?php if ($admin) : ?>
                        <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Mentés</button>
                    <?php endif; ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem><?php echo $admin ? 'Mégsem' : 'Bezár'; ?></button>
                </div>
            </div>
        </form>
        <?php

        wp_die();
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    /** A böngésző kérdezi: van-e friss nyitott döntésem a popuphoz? */
    public static function ajax_dontesek(): void
    {
        self::jog();

        wp_send_json_success([
            'fuggo' => SDH_Muhely_Sms_Szabaly::fuggo_db(),
            'friss' => SDH_Muhely_Sms::bekapcsolva() ? SDH_Muhely_Sms_Szabaly::friss_sajat() : 0,
        ]);
    }

    public static function ajax_dontes_ment(): void
    {
        self::jog();

        $id     = (int) self::p('id', '0');
        $valasz = self::p('valasz', 'kuld');
        // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- fent ellenőrizve.
        $szoveg = isset($_POST['szoveg']) ? sanitize_textarea_field(wp_unslash((string) $_POST['szoveg'])) : '';
        $v      = SDH_Muhely_Sms_Szabaly::dontes_lezar($id, $valasz, $szoveg);

        if (!$v['ok']) {
            wp_send_json_error(['uzenet' => $v['uzenet']]);
        }

        wp_send_json_success(['uzenet' => $v['uzenet'], 'sms_id' => $v['sms_id'], 'vissza' => self::vissza(['nezet' => 'dontesek'])]);
    }

    /** Sablon kitöltése egy laphoz (a popup sablonváltásához). */
    public static function ajax_elonezet(): void
    {
        self::jog();

        $sablon = SDH_Muhely_Sms_Szabaly::sablon((int) self::p('sablon_id', '0'));

        if (!$sablon) {
            wp_send_json_success(['szoveg' => '', 'hianyzik' => [], 'uzenet' => '']);
        }

        $k = SDH_Muhely_Sms_Szabaly::kitolt((string) $sablon->szoveg, SDH_Muhely_Sms_Szabaly::ertekek(
            (int) self::p('munkalap_id', '0'),
            (int) self::p('eszkoz_id', '0'),
            self::p('terulet', 'lap') === 'rendeles' ? 'rendeles' : 'lap',
            sanitize_key(self::p('allapot', '')),
            (int) self::p('ugyfel_id', '0')
        ));
        $nevek = SDH_Muhely_Sms_Szabaly::valtozok();

        wp_send_json_success([
            'szoveg'   => $k['szoveg'],
            'hianyzik' => array_values(array_map(static fn ($n) => $nevek[$n] ?? $n, $k['hianyzik'])),
            'uzenet'   => $k['hianyzik'] === [] ? '' : 'Ehhez a laphoz nem tölthető ki: ' . implode(', ', array_map(static fn ($n) => $nevek[$n] ?? $n, $k['hianyzik'])) . '. Egészítsd ki kézzel.',
        ]);
    }

    /** Kézi SMS (Új SMS popup). */
    public static function ajax_kuld(): void
    {
        self::jog();

        $lap_id    = (int) self::p('munkalap_id', '0');
        $ugyfel_id = (int) self::p('ugyfel_id', '0');
        $lap       = $lap_id > 0 ? SDH_Muhely_Rma::munkalap($lap_id) : null;
        // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- fent ellenőrizve.
        $szoveg = isset($_POST['szoveg']) ? trim(sanitize_textarea_field(wp_unslash((string) $_POST['szoveg']))) : '';
        $szam   = self::p('szam');

        if ($lap) {
            $ugyfel_id = (int) $lap->ugyfel_id;
        }

        if ($szoveg === '') {
            wp_send_json_error(['uzenet' => 'Írd be az SMS szövegét.']);
        }

        if (preg_match('/\{[a-z_]+\}/', $szoveg)) {
            wp_send_json_error(['uzenet' => 'A szövegben kitöltetlen {változó} maradt – javítsd, mielőtt elküldöd.']);
        }

        if ($ugyfel_id <= 0 && $szam === '') {
            wp_send_json_error(['uzenet' => 'Add meg a telefonszámot.']);
        }

        $sablon = SDH_Muhely_Sms_Szabaly::sablon((int) self::p('sablon_id', '0'));
        $v      = SDH_Muhely_Sms::sor_be([
            'ugyfel_id' => $ugyfel_id, 'munkalap_id' => $lap_id, 'eszkoz_id' => (int) self::p('eszkoz_id', '0'),
            'szoveg' => $szoveg, 'kivalto' => 'kezi', 'szam' => $szam,
            'sablon_id' => $sablon ? (int) $sablon->id : 0, 'sablon_verzio' => $sablon ? (int) $sablon->verzio : 0,
        ]);

        if (!$v['ok']) {
            wp_send_json_error(['uzenet' => $v['uzenet']]);
        }

        wp_send_json_success(['uzenet' => $v['uzenet'], 'sms_id' => $v['id'], 'vissza' => self::vissza(['nezet' => 'monitor'])]);
    }

    public static function ajax_sablon_ment(): void
    {
        self::jog(true);

        // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- fent ellenőrizve.
        $szoveg = isset($_POST['szoveg']) ? sanitize_textarea_field(wp_unslash((string) $_POST['szoveg'])) : '';
        $v      = SDH_Muhely_Sms_Szabaly::sablon_ment(['nev' => self::p('nev'), 'szoveg' => $szoveg, 'aktiv' => !empty($_POST['aktiv'])], (int) self::p('id', '0'));

        if (!$v['ok']) {
            wp_send_json_error(['uzenet' => $v['uzenet']]);
        }

        wp_send_json_success(['uzenet' => $v['uzenet'], 'id' => $v['id'], 'vissza' => self::vissza(['nezet' => 'sablonok', 'uzenet' => 'sms_sablon_mentve'])]);
    }

    public static function ajax_szabaly_ment(): void
    {
        self::jog(true);

        $db = 0;

        foreach (['lap', 'rendeles'] as $terulet) {
            // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- fent ellenőrizve; a mentő érvényesít.
            $sorok = isset($_POST[$terulet]) && is_array($_POST[$terulet]) ? wp_unslash($_POST[$terulet]) : [];
            $db   += SDH_Muhely_Sms_Szabaly::szabalyok_ment($terulet, $sorok);
        }

        wp_send_json_success(['uzenet' => $db > 0 ? 'Elmentve: ' . $db . ' szabály.' : 'Elmentve – egyik állapothoz sincs szabály (nem megy automatikus kérdés).', 'db' => $db]);
    }

    public static function ajax_hivott(): void
    {
        self::jog();

        global $wpdb;

        $id = (int) self::p('id', '0');
        $n  = $wpdb->update(self::tabla('uzenet'), ['hivott' => current_time('mysql'), 'hivo' => get_current_user_id()], ['id' => $id, 'irany' => 'ki']);

        if ($n === false || $id <= 0) {
            wp_send_json_error(['uzenet' => 'Nem sikerült rögzíteni.']);
        }

        SDH_Muhely_Sms::esemeny($id, 'hivas', 'felhivva', 'Az ügyfelet felhívták.');
        wp_send_json_success(['uzenet' => 'Rögzítve.', 'hivando' => self::hivando_db()]);
    }
}
