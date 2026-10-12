<?php
/**
 * Nyitvatartás és az Áttekintés órája.
 *
 * A műhely-felület felső fejlécében (minden oldalon) élő óra áll, mellette az, hogy mennyi idő van még
 * zárásig (nyitás előtt: nyitásig; zárás után: mikor nyit legközelebb). A
 * nyitvatartás naponként állítható a Beállításokban – a kódban semmilyen
 * cégadat nincs: amíg nincs megadva, csak az óra látszik.
 *
 * Az idő a WordPress időzónája szerinti (Beállítások › Általános), nem a
 * böngészőé: az oldal a szerver idejét kapja meg, a böngésző csak lépteti
 * (app.js: oraIndul), így a rosszul beállított gép órája sem téveszt meg.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Nyitvatartas
{
    private const OPT = 'sdh_muhely_nyitvatartas';

    /** A hét napjai (ISO: 1 = hétfő … 7 = vasárnap). */
    public static function napok(): array
    {
        return [1 => 'Hétfő', 2 => 'Kedd', 3 => 'Szerda', 4 => 'Csütörtök', 5 => 'Péntek', 6 => 'Szombat', 7 => 'Vasárnap'];
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /**
     * @return array{ora: bool, masodperc: bool, figyelmeztet: int, napok: array<int, array{0: string, 1: string}>}
     *         napok: csak a nyitvatartási napok, nap => [nyitás, zárás] „óó:pp" alakban.
     */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        $napok = [];

        foreach ((array) ($m['napok'] ?? []) as $nap => $ido) {
            $nap  = (int) $nap;
            $nyit = self::ido_ervenyes(is_array($ido) ? (string) ($ido[0] ?? '') : '');
            $zar  = self::ido_ervenyes(is_array($ido) ? (string) ($ido[1] ?? '') : '');

            if (isset(self::napok()[$nap]) && $nyit !== '' && $zar !== '' && $zar > $nyit) {
                $napok[$nap] = [$nyit, $zar];
            }
        }

        ksort($napok);

        return [
            'ora'          => !isset($m['ora']) || !empty($m['ora']),
            'masodperc'    => !isset($m['masodperc']) || !empty($m['masodperc']),
            // Ennyi perccel zárás előtt vált figyelmeztető színre a visszaszámláló.
            'figyelmeztet' => max(0, min(240, (int) ($m['figyelmeztet'] ?? 30))),
            'napok'        => $napok,
        ];
    }

    /**
     * Egy beírt időpont „óó:pp" alakra hozva; ami nem értelmezhető, abból üres szöveg lesz.
     * Elfogadja: 9, 9:00, 09.00, 0900, 17h30.
     */
    public static function ido_ervenyes(string $be): string
    {
        $be = trim($be);

        if ($be === '') {
            return '';
        }

        if (preg_match('/^(\d{1,2})\s*[:.h]\s*(\d{2})$/u', $be, $t) === 1) {
            [$ora, $perc] = [(int) $t[1], (int) $t[2]];
        } elseif (preg_match('/^(\d{2})(\d{2})$/', $be, $t) === 1) {
            [$ora, $perc] = [(int) $t[1], (int) $t[2]];
        } elseif (preg_match('/^\d{1,2}$/', $be) === 1) {
            [$ora, $perc] = [(int) $be, 0];
        } else {
            return '';
        }

        // A 24:00 a nap vége (éjfélig nyitva): 23:59-ként tároljuk.
        if ($ora === 24 && $perc === 0) {
            return '23:59';
        }

        return $ora > 23 || $perc > 59 ? '' : sprintf('%02d:%02d', $ora, $perc);
    }

    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();

        ?>
        <div class="sdh-doboz" id="nyitvatartas">
            <h2 class="sdh-doboz__cim">Nyitvatartás</h2>
            <p class="sdh-sugo">
                Ebből számol a fejléc órája: nyitva tartás alatt azt mutatja, mennyi idő van még zárásig,
                előtte azt, mennyi van nyitásig, utána azt, mikor nyit legközelebb az üzlet. A nap, amelyiknél
                nincs pipa (vagy hiányzik az időpont), zárva van. Az időpont <code>óó:pp</code> alakú, pl. <code>09:00</code>.
            </p>

            <input type="hidden" name="nyitva_jelen" value="1">

            <table class="sdh-tabla sdh-tabla--keskeny sdh-nyitva-tabla">
                <thead>
                    <tr><th>Nap</th><th>Nyitva</th><th>Nyitás</th><th>Zárás</th></tr>
                </thead>
                <tbody>
                    <?php foreach (self::napok() as $nap => $nev) : ?>
                        <?php $ido = $b['napok'][$nap] ?? null; ?>
                        <tr>
                            <td><strong><?php echo esc_html($nev); ?></strong></td>
                            <td>
                                <input type="checkbox" name="nyitva[<?php echo (int) $nap; ?>][be]" value="1" <?php checked($ido !== null); ?>
                                       aria-label="<?php echo esc_attr($nev . ': nyitva'); ?>">
                            </td>
                            <td>
                                <input type="text" class="sdh-nyitva-ido" name="nyitva[<?php echo (int) $nap; ?>][nyit]" inputmode="numeric" maxlength="5"
                                       autocomplete="off" placeholder="óó:pp" aria-label="<?php echo esc_attr($nev . ': nyitás'); ?>"
                                       value="<?php echo esc_attr($ido[0] ?? ''); ?>">
                            </td>
                            <td>
                                <input type="text" class="sdh-nyitva-ido" name="nyitva[<?php echo (int) $nap; ?>][zar]" inputmode="numeric" maxlength="5"
                                       autocomplete="off" placeholder="óó:pp" aria-label="<?php echo esc_attr($nev . ': zárás'); ?>"
                                       value="<?php echo esc_attr($ido[1] ?? ''); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Óra a fejlécben</h2>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="nyitva_ora" id="nyitva_ora" value="1" <?php checked($b['ora']); ?>>
                <label for="nyitva_ora">A felső fejlécben, minden oldalon látszik az óra és a zárásig hátralévő idő</label>
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="nyitva_masodperc" id="nyitva_masodperc" value="1" <?php checked($b['masodperc']); ?>>
                <label for="nyitva_masodperc">Az óra a másodperceket is mutatja</label>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="nyitva_figyelmeztet">Figyelmeztetés zárás előtt (perc)</label>
                    <input type="number" name="nyitva_figyelmeztet" id="nyitva_figyelmeztet" min="0" max="240" step="5"
                           value="<?php echo (int) $b['figyelmeztet']; ?>">
                    <span class="sdh-mezo__sugo">Ennyi perccel zárás előtt a visszaszámláló figyelmeztető színre vált. 0 = nincs figyelmeztetés.</span>
                </div>
            </div>

            <p class="sdh-sugo sdh-sugo--utolso">
                Az óra a rendszer időzónája szerint jár (most: <strong><?php echo esc_html(wp_date('Y. m. d. H:i')); ?></strong>,
                <?php echo esc_html(wp_timezone_string()); ?>).
            </p>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['nyitva_jelen'])) {
            return;
        }

        $be    = isset($_POST['nyitva']) && is_array($_POST['nyitva']) ? wp_unslash($_POST['nyitva']) : [];
        $napok = [];

        foreach (array_keys(self::napok()) as $nap) {
            $sor  = is_array($be[$nap] ?? null) ? $be[$nap] : [];
            $nyit = self::ido_ervenyes(sanitize_text_field((string) ($sor['nyit'] ?? '')));
            $zar  = self::ido_ervenyes(sanitize_text_field((string) ($sor['zar'] ?? '')));

            // Csak a bepipált, két érvényes időponttal megadott nap számít nyitvatartásnak.
            if (!empty($sor['be']) && $nyit !== '' && $zar !== '' && $zar > $nyit) {
                $napok[$nap] = [$nyit, $zar];
            }
        }

        update_option(self::OPT, [
            'ora'          => !empty($_POST['nyitva_ora']),
            'masodperc'    => !empty($_POST['nyitva_masodperc']),
            'figyelmeztet' => isset($_POST['nyitva_figyelmeztet']) ? max(0, min(240, (int) $_POST['nyitva_figyelmeztet'])) : 30,
            'napok'        => $napok,
        ], false);
        // phpcs:enable
    }

    /* =================================================================
     * Számítás
     * ============================================================== */

    private static function perc(string $ido): int
    {
        return (int) substr($ido, 0, 2) * 60 + (int) substr($ido, 3, 2);
    }

    /**
     * Hol tart a nap egy adott pillanatban.
     *
     * @param string|null $most „éééé-hh-nn óó:pp:mm" a rendszer időzónájában; null = most.
     * @return array{allapot: string, perc: int, szoveg: string, arany: float}
     *         allapot: nincs (nincs megadva nyitvatartás) | elott | nyitva | hamar (nyitva, de mindjárt zárás) | zarva
     *         perc: ennyi perc van zárásig (nyitva / hamar), nyitásig (elott), különben 0
     *         arany: a nyitvatartási nap eltelt része (0–1), nyitva tartás alatt
     */
    public static function allapot(?string $most = null): array
    {
        $b = self::beallitas();

        if ($b['napok'] === []) {
            return ['allapot' => 'nincs', 'perc' => 0, 'szoveg' => '', 'arany' => 0.0];
        }

        // A szöveges (helyi) időt UTC-ként értelmezve a gmdate() ugyanazt a napot és órát adja vissza.
        $t    = (int) strtotime(($most ?? current_time('mysql')) . ' UTC');
        $nap  = (int) gmdate('N', $t);
        $mp   = (int) gmdate('G', $t) * 3600 + (int) gmdate('i', $t) * 60 + (int) gmdate('s', $t);
        $ma   = $b['napok'][$nap] ?? null;

        if ($ma !== null) {
            $nyit = self::perc($ma[0]) * 60;
            $zar  = self::perc($ma[1]) * 60;

            if ($mp < $nyit) {
                $perc = (int) ceil(($nyit - $mp) / 60);

                return ['allapot' => 'elott', 'perc' => $perc, 'szoveg' => 'Nyitásig ' . self::idotartam($perc), 'arany' => 0.0];
            }

            if ($mp < $zar) {
                $perc = (int) ceil(($zar - $mp) / 60);

                return [
                    'allapot' => $b['figyelmeztet'] > 0 && $perc <= $b['figyelmeztet'] ? 'hamar' : 'nyitva',
                    'perc'    => $perc,
                    'szoveg'  => 'Zárásig ' . self::idotartam($perc),
                    'arany'   => round(($mp - $nyit) / max(1, $zar - $nyit), 4),
                ];
            }
        }

        // Zárva: a következő nyitás napja (legfeljebb egy hétre előre).
        for ($i = 1; $i <= 7; $i++) {
            $kov = (($nap - 1 + $i) % 7) + 1;

            if (isset($b['napok'][$kov])) {
                $mikor = $i === 1 ? 'holnap' : mb_strtolower(self::napok()[$kov]);

                return [
                    'allapot' => 'zarva',
                    'perc'    => 0,
                    'szoveg'  => ($ma === null ? 'Ma zárva' : 'Zárva') . ' · nyitás: ' . $mikor . ' ' . $b['napok'][$kov][0],
                    'arany'   => 0.0,
                ];
            }
        }

        return ['allapot' => 'zarva', 'perc' => 0, 'szoveg' => 'Zárva', 'arany' => 0.0];
    }

    /** „2 óra 14 perc", „14 perc", „kevesebb mint 1 perc". */
    public static function idotartam(int $perc): string
    {
        if ($perc < 1) {
            return 'kevesebb mint 1 perc';
        }

        $ora  = intdiv($perc, 60);
        $perc = $perc % 60;

        return trim(($ora > 0 ? $ora . ' óra ' : '') . ($perc > 0 || $ora === 0 ? $perc . ' perc' : ''));
    }

    /* =================================================================
     * Megjelenítés
     * ============================================================== */

    /**
     * Az óra (szűrt HTML), vagy üres szöveg, ha ki van kapcsolva. A böngésző az
     * itt kapott szerveridőt lépteti (app.js: oraIndul). $hely 'fej': a
     * műhely-felület felső fejlécének tömör változata (minden oldalon ott áll).
     */
    public static function ora_html(string $hely = ''): string
    {
        $b = self::beallitas();

        if (!$b['ora']) {
            return '';
        }

        $most    = current_time('mysql');
        $t       = (int) strtotime($most . ' UTC');
        $allapot = self::allapot($most);
        $honapok = ['', 'január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
        $het     = [];

        foreach ($b['napok'] as $nap => $ido) {
            $het[(string) $nap] = $ido;
        }

        $admin = class_exists('SDH_Muhely_Admin_UI') && SDH_Muhely_Admin_UI::admin_e() && class_exists('SDH_Muhely_Beallitasok');

        ob_start();

        ?>
        <div class="sdh-ora<?php echo $hely === 'fej' ? ' sdh-ora--fej' : ''; ?>" data-sdh-ora
             data-most="<?php echo esc_attr(str_replace(' ', 'T', $most)); ?>"
             data-het="<?php echo esc_attr((string) wp_json_encode((object) $het)); ?>"
             data-figy="<?php echo (int) $b['figyelmeztet']; ?>"
             data-allapot="<?php echo esc_attr($allapot['allapot']); ?>"
             style="--sdh-ora-arany: <?php echo esc_attr((string) round($allapot['arany'], 4)); ?>"
             role="timer" aria-label="Pontos idő és a zárásig hátralévő idő">
            <span class="sdh-ora__gyuru" aria-hidden="true"><?php echo SDH_Muhely_Admin_UI::gomb_ikon('ora'); // phpcs:ignore WordPress.Security.EscapeOutput -- saját SVG. ?></span>
            <div class="sdh-ora__ido">
                <b data-sdh-ora-ido><?php echo esc_html(gmdate('H:i', $t)); ?></b><?php if ($b['masodperc']) : ?><span data-sdh-ora-mp><?php echo esc_html(gmdate(':s', $t)); ?></span><?php endif; ?>
            </div>
            <div class="sdh-ora__resz">
                <span class="sdh-ora__datum" data-sdh-ora-datum><?php echo esc_html($honapok[(int) gmdate('n', $t)] . ' ' . (int) gmdate('j', $t) . '., ' . mb_strtolower(self::napok()[(int) gmdate('N', $t)])); ?></span>
                <?php if ($allapot['allapot'] !== 'nincs') : ?>
                    <span class="sdh-ora__zaras" data-sdh-ora-zaras aria-live="off"><?php echo esc_html($allapot['szoveg']); ?></span>
                <?php elseif ($admin) : ?>
                    <a class="sdh-ora__zaras sdh-ora__zaras--nincs" href="<?php echo esc_url(SDH_Muhely_Beallitasok::url('nyitvatartas')); ?>"
                       title="Add meg a nyitvatartást, és az óra mutatja, mennyi idő van még zárásig">Nyitvatartás megadása</a>
                <?php endif; ?>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}
