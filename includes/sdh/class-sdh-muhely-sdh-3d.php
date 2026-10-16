<?php
/**
 * SDH – belső modul: 3D figura (0.66.0; 0.66.1: Szikra helyett is; 0.68.0: több modell).
 *
 * CSAK AZ SDH SZERVIZÉ (lásd includes/sdh/class-sdh-muhely-sdh.php). Eladáskor az
 * `includes/sdh/` mappával együtt nyomtalanul eltűnik.
 *
 * Saját feltöltésű 3D modellek (.glb) valódi 3D-ben, a videokártyán (three.js / WebGL)
 * rajzolva. Minden modellnek szerepe van:
 *   - „helyette": MAGA az asszisztens, Szikra helyén – mindent ugyanúgy csinál;
 *   - „mellette": kísérő Szikra (vagy a helyette álló modell) mellett;
 *   - „pihen": megvan, de nem látszik.
 * Egyszerre egy „helyette" és egy „mellette" futhat. A modellfájlok NEM a pluginban és NEM
 * a git-repóban vannak, hanem a WordPress feltöltési mappájában (uploads/sdh-muhely-3d/) –
 * a Beállítások › SDH – belső › 3D figura lapon töltöd fel őket. Így a nyilvános repóba és
 * az eladási csomagba soha nem kerülhet harmadik fél modellje.
 *
 * A three.js (MIT) a `3d/vendor/` mappában van; csak akkor töltődik be, ha van látható modell.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Sdh_3d
{
    public const OPT    = 'sdh_muhely_sdh_3d';
    public const MAPPA  = 'sdh-muhely-3d';
    public const MAX_MB = 40;
    public const MAX_DB = 8;

    /** A szerepek, a választó sorrendjében. */
    public const SZEREPEK = [
        'helyette' => 'Szikra helyett – ő az asszisztens',
        'mellette' => 'Kísérő – mellette lebeg',
        'pihen'    => 'Pihen – nem látszik',
    ];

    public static function init(): void
    {
        if (!class_exists('SDH_Muhely_Sdh')) {
            return;
        }

        SDH_Muhely_Sdh::regisztral('3d', [
            'cim'    => '3D figura',
            'leiras' => 'Saját feltöltésű 3D modellek (.glb): egy Szikra helyett (ő az asszisztens, mindent ugyanúgy csinál), egy mellette kísérőként – valódi 3D, lebeg, követi az egeret. A modellek csak ezen a gépen vannak, a repóba és az eladási csomagba nem kerülnek.',
            'rajz'   => [self::class, 'doboz'],
            'ment'   => [self::class, 'mentes'],
        ]);

        // A műhely-felület (frontend.php) és a wp-admin műhely-oldalai végén.
        add_action('sdh_muhely_felulet_vege', [self::class, 'kiir']);
        add_action('admin_footer', [self::class, 'kiir_admin']);
    }

    /**
     * Egy modell adatai, rendbe téve.
     *
     * @param array<string, mixed> $m
     * @return array{fajl: string, nev: string, kredit: string, szerep: string, forgas: int, vegtag: bool}
     */
    private static function modell_rend(array $m): array
    {
        return [
            'fajl'   => (string) ($m['fajl'] ?? ''),
            'nev'    => mb_substr((string) ($m['nev'] ?? ''), 0, 120),
            'kredit' => mb_substr((string) ($m['kredit'] ?? ''), 0, 300),
            'szerep' => isset(self::SZEREPEK[(string) ($m['szerep'] ?? '')]) ? (string) $m['szerep'] : 'pihen',
            'forgas' => max(-180, min(180, (int) ($m['forgas'] ?? 0))),
            'vegtag' => !empty($m['vegtag']),
        ];
    }

    /**
     * A beállítás. A 0.66-os egymodelles formát (fajl, mod, nev, kredit, forgas) magától
     * átveszi az új listába.
     *
     * @return array{aktiv: bool, meret: int, hely: string, eger: bool, modellek: list<array{fajl: string, nev: string, kredit: string, szerep: string, forgas: int, vegtag: bool}>}
     */
    public static function beallitas(): array
    {
        $b = get_option(self::OPT, []);
        $b = is_array($b) ? $b : [];

        $lista = is_array($b['modellek'] ?? null) ? $b['modellek'] : [];

        if ($lista === [] && !empty($b['fajl'])) {
            $lista = [[
                'fajl'   => $b['fajl'],
                'nev'    => $b['nev'] ?? '',
                'kredit' => $b['kredit'] ?? '',
                'szerep' => ($b['mod'] ?? 'helyette') === 'mellette' ? 'mellette' : 'helyette',
                'forgas' => $b['forgas'] ?? 0,
                'vegtag' => false,
            ]];
        }

        $modellek = [];

        foreach ($lista as $m) {
            if (is_array($m) && preg_match('/^modell-[a-f0-9]{8,16}\.glb$/', (string) ($m['fajl'] ?? '')) === 1) {
                $modellek[] = self::modell_rend($m);
            }
        }

        return [
            'aktiv'    => !empty($b['aktiv']),
            'meret'    => max(60, min(320, (int) ($b['meret'] ?? 130))),
            'hely'     => in_array(($b['hely'] ?? 'bal'), ['bal', 'felette'], true) ? (string) ($b['hely'] ?? 'bal') : 'bal',
            'eger'     => !array_key_exists('eger', $b) || !empty($b['eger']),
            'modellek' => self::szerepek_rendbe(array_slice($modellek, 0, self::MAX_DB)),
        ];
    }

    /**
     * Egy szerepből csak egy lehet (helyette, mellette): a később következő azonos szerepű pihenni megy.
     *
     * @param list<array<string, mixed>> $modellek
     * @return list<array<string, mixed>>
     */
    private static function szerepek_rendbe(array $modellek): array
    {
        $foglalt = [];

        foreach ($modellek as $i => $m) {
            if ($m['szerep'] === 'pihen') {
                continue;
            }

            if (isset($foglalt[$m['szerep']])) {
                $modellek[$i]['szerep'] = 'pihen';
            } else {
                $foglalt[$m['szerep']] = true;
            }
        }

        return $modellek;
    }

    /** A feltöltési mappa útja és címe (a végén perjellel). */
    private static function mappa(): array
    {
        $f = wp_get_upload_dir();

        if (!empty($f['error'])) {
            return ['', ''];
        }

        return [trailingslashit($f['basedir']) . self::MAPPA . '/', trailingslashit($f['baseurl']) . self::MAPPA . '/'];
    }

    /** Egy feltöltött modell útja, ha megvan (üres név: az első modellé). */
    public static function modell_ut(string $fajl = ''): string
    {
        [$ut] = self::mappa();

        if ($fajl === '') {
            $elso = self::beallitas()['modellek'][0] ?? null;
            $fajl = $elso ? (string) $elso['fajl'] : '';
        }

        if ($fajl === '' || $ut === '' || preg_match('/^modell-[a-f0-9]{8,16}\.glb$/', $fajl) !== 1) {
            return '';
        }

        return is_file($ut . $fajl) ? $ut . $fajl : '';
    }

    /**
     * GLB ellenőrzése: „glTF" fej, 2-es verzió, és az első JSON-darab. A Sketchfabról
     * letöltött modellek az asset.extras-ban hozzák a szerzőt és a licencet – ebből lesz
     * a kötelező hivatkozás (CC-BY).
     *
     * @return array{jo: bool, hiba: string, cim: string, kredit: string}
     */
    public static function glb_vizsgal(string $ut): array
    {
        $ki = ['jo' => false, 'hiba' => '', 'cim' => '', 'kredit' => ''];
        $fp = @fopen($ut, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        if (!$fp) {
            $ki['hiba'] = 'A fájl nem olvasható.';

            return $ki;
        }

        $fej = (string) fread($fp, 20); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread

        if (strlen($fej) < 20 || substr($fej, 0, 4) !== 'glTF') {
            fclose($fp); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            $ki['hiba'] = 'Ez nem .glb (bináris glTF) fájl.';

            return $ki;
        }

        $f = unpack('Vver/Vhossz/Vjhossz/a4jtipus', substr($fej, 4));

        if ((int) $f['ver'] !== 2 || $f['jtipus'] !== 'JSON' || (int) $f['jhossz'] <= 0 || (int) $f['jhossz'] > 8 * 1048576) {
            fclose($fp); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            $ki['hiba'] = 'Csak glTF 2.0 .glb fájl tölthető fel.';

            return $ki;
        }

        $json = json_decode((string) fread($fp, (int) $f['jhossz']), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        fclose($fp); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        if (!is_array($json) || empty($json['asset']['version'])) {
            $ki['hiba'] = 'A .glb fájl leírása sérült.';

            return $ki;
        }

        $x = is_array($json['asset']['extras'] ?? null) ? $json['asset']['extras'] : [];
        $ki['jo']     = true;
        $ki['cim']    = mb_substr(sanitize_text_field((string) ($x['title'] ?? '')), 0, 120);
        $ki['kredit'] = mb_substr(sanitize_text_field(trim(implode(' · ', array_filter([
            (string) ($x['title'] ?? ''),
            (string) ($x['author'] ?? ''),
            (string) ($x['license'] ?? ''),
        ])))), 0, 300);

        return $ki;
    }

    /* =================================================================
     * Beállítások › SDH – belső › 3D figura
     * ============================================================== */

    public static function doboz(): void
    {
        $b = self::beallitas();
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">3D figurák – Szikra helyett és mellette</h2>

            <p class="sdh-sugo">
                Saját 3D modellek (.glb) valódi 3D-ben, a videokártyán rajzolva. A <strong>Szikra helyett</strong>
                szerepű maga az asszisztens: kattintásra nyílik a csevegő, buborékban beszél, sétál, végigvezet,
                jeleneteket játszik, alszik, tiszteleg – Szikra hangulatait és mozdulatait 3D-ben játssza el.
                A <strong>Kísérő</strong> mellette lebeg. Egyszerre egy-egy futhat; a többi pihen. A modellek
                <strong>csak ezen a gépen</strong> vannak (a feltöltési mappában) – a git-repóba és az eladási
                csomagba nem kerülnek. Hardveres gyorsítás nélkül mozdulatlan képként jelennek meg.
            </p>

            <input type="hidden" name="sdh_3d_jelen" value="1">

            <?php $hiba = (string) get_transient('sdh_muhely_sdh_3d_hiba_' . get_current_user_id()); ?>
            <?php if ($hiba !== '') : ?>
                <?php delete_transient('sdh_muhely_sdh_3d_hiba_' . get_current_user_id()); ?>
                <div class="sdh-uzenet sdh-uzenet--hiba" data-sdh-3d-hiba><?php echo esc_html($hiba); ?></div>
            <?php endif; ?>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="sdh_3d_aktiv" id="sdh_3d_aktiv" value="1" <?php checked($b['aktiv']); ?>>
                <label for="sdh_3d_aktiv">A 3D figurák látszódjanak</label>
            </div>

            <?php if ($b['modellek'] === []) : ?>
                <p class="sdh-sugo">Még nincs feltöltött modell.</p>
            <?php else : ?>
                <table class="sdh-tabla" data-sdh-3d-modellek>
                    <thead>
                        <tr>
                            <th>Modell</th>
                            <th>Szerepe</th>
                            <th>Alapirány (fok)</th>
                            <th>Saját keze-lába</th>
                            <th>Hivatkozás (szerző, licenc)</th>
                            <th>Törlés</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($b['modellek'] as $m) : ?>
                            <?php
                            $k  = 'sdh_3d_m[' . $m['fajl'] . ']';
                            $ut = self::modell_ut($m['fajl']);
                            ?>
                            <tr data-sdh-3d-modell="<?php echo esc_attr($m['fajl']); ?>">
                                <td class="sdh-tabla__nev">
                                    <?php echo esc_html($m['nev'] !== '' ? $m['nev'] : $m['fajl']); ?>
                                    <?php if ($ut !== '') : ?>
                                        <br><span class="sdh-sugo">(<?php echo esc_html(size_format((int) filesize($ut), 1)); ?>)</span>
                                    <?php else : ?>
                                        <br><span class="sdh-sugo">(a fájl hiányzik)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <select name="<?php echo esc_attr($k . '[szerep]'); ?>" aria-label="Szerepe">
                                        <?php foreach (self::SZEREPEK as $sk => $sn) : ?>
                                            <option value="<?php echo esc_attr($sk); ?>" <?php selected($m['szerep'], $sk); ?>><?php echo esc_html($sn); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="<?php echo esc_attr($k . '[forgas]'); ?>" min="-180" max="180" step="15" value="<?php echo (int) $m['forgas']; ?>" aria-label="Alapirány">
                                </td>
                                <td>
                                    <input type="checkbox" name="<?php echo esc_attr($k . '[vegtag]'); ?>" value="1" <?php checked($m['vegtag']); ?> aria-label="Saját keze-lába van (egész alakos)">
                                </td>
                                <td>
                                    <input type="text" name="<?php echo esc_attr($k . '[kredit]'); ?>" maxlength="300" value="<?php echo esc_attr($m['kredit']); ?>" aria-label="Hivatkozás">
                                </td>
                                <td>
                                    <input type="checkbox" name="<?php echo esc_attr($k . '[torol]'); ?>" value="1" aria-label="Törlés">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="sdh-sugo">
                    <strong>Saját keze-lába</strong>: pipáld be az egész alakos figuráknál (fej, test, kéz, láb) – így a
                    rendszer nem rajzol rá külön kesztyűt és csizmát. Csak fejnél, sisaknál hagyd üresen.
                </p>
            <?php endif; ?>

            <div class="sdh-mezo">
                <label for="sdh_3d_fajl">Új modell feltöltése (.glb, legfeljebb <?php echo (int) self::MAX_MB; ?> MB, összesen <?php echo (int) self::MAX_DB; ?> modell)</label>
                <input type="file" name="sdh_3d_fajl" id="sdh_3d_fajl" accept=".glb,model/gltf-binary">
                <span class="sdh-sugo">Ha még nincs asszisztens-modell, az új az lesz; ha már van, kísérő lesz; különben pihen.</span>
            </div>

            <div class="sdh-mezo">
                <label for="sdh_3d_meret">A kísérő mérete (képpont)</label>
                <input type="number" name="sdh_3d_meret" id="sdh_3d_meret" min="60" max="320" step="10" value="<?php echo (int) $b['meret']; ?>">
            </div>

            <div class="sdh-mezo">
                <label for="sdh_3d_hely">Hol legyen a kísérő</label>
                <select name="sdh_3d_hely" id="sdh_3d_hely">
                    <option value="bal" <?php selected($b['hely'], 'bal'); ?>>Az asszisztens bal oldalán</option>
                    <option value="felette" <?php selected($b['hely'], 'felette'); ?>>Az asszisztens fölött</option>
                </select>
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--also-ter">
                <input type="checkbox" name="sdh_3d_eger" id="sdh_3d_eger" value="1" <?php checked($b['eger']); ?>>
                <label for="sdh_3d_eger">Kövessék a tekintetükkel az egeret</label>
            </div>
        </div>
        <?php
    }

    public static function mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['sdh_3d_jelen'])) {
            return;
        }

        $regi = self::beallitas();
        $be   = isset($_POST['sdh_3d_m']) && is_array($_POST['sdh_3d_m']) ? wp_unslash($_POST['sdh_3d_m']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $mod  = [];

        foreach ($regi['modellek'] as $m) {
            $u = is_array($be[$m['fajl']] ?? null) ? $be[$m['fajl']] : null;

            if ($u !== null && !empty($u['torol'])) {
                $ut = self::modell_ut($m['fajl']);

                if ($ut !== '') {
                    wp_delete_file($ut);
                }

                continue;
            }

            if ($u !== null) {
                $m['szerep'] = isset(self::SZEREPEK[(string) ($u['szerep'] ?? '')]) ? (string) $u['szerep'] : $m['szerep'];
                $m['forgas'] = max(-180, min(180, (int) ($u['forgas'] ?? $m['forgas'])));
                $m['vegtag'] = !empty($u['vegtag']);
                $m['kredit'] = isset($u['kredit']) ? mb_substr(sanitize_text_field((string) $u['kredit']), 0, 300) : $m['kredit'];
            }

            $mod[] = $m;
        }

        $uj = [
            'aktiv'    => !empty($_POST['sdh_3d_aktiv']),
            'meret'    => max(60, min(320, (int) ($_POST['sdh_3d_meret'] ?? $regi['meret']))),
            'hely'     => in_array(($_POST['sdh_3d_hely'] ?? ''), ['bal', 'felette'], true) ? (string) $_POST['sdh_3d_hely'] : $regi['hely'],
            'eger'     => !empty($_POST['sdh_3d_eger']),
            'modellek' => $mod,
        ];

        [$mappa] = self::mappa();
        $f = $_FILES['sdh_3d_fajl'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (is_array($f) && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $hiba = self::feltolt($f, $mappa, $uj);

            if ($hiba !== '') {
                set_transient('sdh_muhely_sdh_3d_hiba_' . get_current_user_id(), $hiba, 120);
            }
        }

        $uj['modellek'] = self::szerepek_rendbe($uj['modellek']);
        update_option(self::OPT, $uj, false);
        // phpcs:enable
    }

    /**
     * A feltöltött .glb átvétele: a lista végére kerül. A szerepe: ha még nincs asszisztens-
     * modell, „helyette"; ha van, de nincs kísérő, „mellette"; különben „pihen".
     * Hibánál üzenettel tér vissza, és a lista változatlan.
     *
     * @param array<string, mixed> $f
     * @param array<string, mixed> $b  a beállítás (a „modellek" listát bővíti)
     */
    public static function feltolt(array $f, string $mappa, array &$b): string
    {
        if ((int) $f['error'] !== UPLOAD_ERR_OK) {
            return 'A feltöltés nem sikerült (hibakód: ' . (int) $f['error'] . ').';
        }

        if ((int) $f['size'] <= 0 || (int) $f['size'] > self::MAX_MB * 1048576) {
            return 'A modell legfeljebb ' . self::MAX_MB . ' MB lehet.';
        }

        if (strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'glb') {
            return 'Csak .glb fájl tölthető fel.';
        }

        $b['modellek'] = is_array($b['modellek'] ?? null) ? $b['modellek'] : [];

        if (count($b['modellek']) >= self::MAX_DB) {
            return 'Legfeljebb ' . self::MAX_DB . ' modell lehet – előbb törölj egyet.';
        }

        $v = self::glb_vizsgal((string) $f['tmp_name']);

        if (!$v['jo']) {
            return $v['hiba'];
        }

        if ($mappa === '' || (!is_dir($mappa) && !wp_mkdir_p($mappa))) {
            return 'A feltöltési mappa nem írható.';
        }

        $nev = 'modell-' . substr(md5_file((string) $f['tmp_name']) ?: wp_generate_password(12, false), 0, 12) . '.glb';

        foreach ($b['modellek'] as $m) {
            if ($m['fajl'] === $nev) {
                return 'Ez a modell már fel van töltve.';
            }
        }

        $cel = $mappa . $nev;
        $ok  = is_uploaded_file((string) $f['tmp_name'])
            ? move_uploaded_file((string) $f['tmp_name'], $cel)
            : copy((string) $f['tmp_name'], $cel); // a tesztek nem HTTP-feltöltésből jönnek

        if (!$ok) {
            return 'A modell mentése nem sikerült.';
        }

        $szerepek = array_column($b['modellek'], 'szerep');
        $szerep   = !in_array('helyette', $szerepek, true) ? 'helyette' : (!in_array('mellette', $szerepek, true) ? 'mellette' : 'pihen');

        $b['modellek'][] = self::modell_rend([
            'fajl'   => $nev,
            'nev'    => $v['cim'] !== '' ? $v['cim'] : sanitize_file_name((string) $f['name']),
            'kredit' => $v['kredit'],
            'szerep' => $szerep,
            'forgas' => 0,
            'vegtag' => false,
        ]);

        return '';
    }

    /* =================================================================
     * Betöltés a felületen
     * ============================================================== */

    /**
     * A betöltő adatai (a látható modellek: legfeljebb egy „helyette" és egy „mellette"),
     * vagy null, ha nincs mit mutatni.
     *
     * @return array{figurak: list<array<string, mixed>>}|null
     */
    public static function adat(): ?array
    {
        if (!SDH_Muhely_Sdh::be() || !is_user_logged_in()) {
            return null;
        }

        $b = self::beallitas();

        if (!$b['aktiv']) {
            return null;
        }

        [, $url] = self::mappa();
        $figurak = [];

        foreach ($b['modellek'] as $m) {
            $ut = self::modell_ut($m['fajl']);

            if ($m['szerep'] === 'pihen' || $ut === '') {
                continue;
            }

            $figurak[] = [
                'url'    => $url . $m['fajl'] . '?v=' . (int) filemtime($ut),
                'mod'    => $m['szerep'],
                'nev'    => $m['nev'],
                'kredit' => $m['kredit'],
                'meret'  => $b['meret'],
                'hely'   => $b['hely'],
                'eger'   => $b['eger'],
                'forgas' => $m['forgas'],
                'vegtag' => $m['vegtag'],
            ];
        }

        return $figurak === [] ? null : ['figurak' => $figurak];
    }

    public static function kiir(): void
    {
        $a = self::adat();

        if ($a === null) {
            return;
        }

        $alap = plugins_url('3d/', __FILE__);
        $ver  = defined('SDH_MUHELY_VERSION') ? SDH_MUHELY_VERSION : '1';

        printf(
            '<link rel="stylesheet" href="%1$s"><script type="module" id="sdh-3d-kiseret" src="%2$s" data-beall="%3$s"></script>' . "\n",
            esc_url($alap . 'kiseret.css?ver=' . $ver),
            esc_url($alap . 'kiseret.js?ver=' . $ver),
            esc_attr((string) wp_json_encode($a))
        );
    }

    /** wp-admin: csak a műhely saját oldalain. */
    public static function kiir_admin(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- csak megjelenítés.
        $oldal = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';

        if (strpos($oldal, 'sdh-muhely') !== 0) {
            return;
        }

        self::kiir();
    }
}
