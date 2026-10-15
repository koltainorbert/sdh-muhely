<?php
/**
 * SDH – belső modul: 3D figura (0.66.0; 0.66.1: Szikra helyett is).
 *
 * CSAK AZ SDH SZERVIZÉ (lásd includes/sdh/class-sdh-muhely-sdh.php). Eladáskor az
 * `includes/sdh/` mappával együtt nyomtalanul eltűnik.
 *
 * Egy saját feltöltésű 3D modell (.glb) él Szikra mellett: valódi 3D-ben, a
 * videokártyán (three.js / WebGL) rajzolva, lebeg, követi az egeret, kattintásra
 * megpördül, és Szikra reagál rá. A modellfájl NEM a pluginban és NEM a git-repóban
 * van, hanem a WordPress feltöltési mappájában (uploads/sdh-muhely-3d/) – a
 * Beállítások › SDH – belső › 3D kísérő lapon töltöd fel. Így a nyilvános repóba és az
 * eladási csomagba soha nem kerülhet harmadik fél modellje.
 *
 * A three.js (MIT) a `3d/vendor/` mappában van; csak akkor töltődik be, ha a kísérő
 * be van kapcsolva és van feltöltött modell.
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

    public static function init(): void
    {
        if (!class_exists('SDH_Muhely_Sdh')) {
            return;
        }

        SDH_Muhely_Sdh::regisztral('3d', [
            'cim'    => '3D figura',
            'leiras' => 'Saját feltöltésű 3D modell (.glb) Szikra helyett (ő az asszisztens, mindent ugyanúgy csinál) vagy mellette: valódi 3D, lebeg, követi az egeret. A modell csak ezen a gépen van, a repóba és az eladási csomagba nem kerül.',
            'rajz'   => [self::class, 'doboz'],
            'ment'   => [self::class, 'mentes'],
        ]);

        // A műhely-felület (frontend.php) és a wp-admin műhely-oldalai végén.
        add_action('sdh_muhely_felulet_vege', [self::class, 'kiir']);
        add_action('admin_footer', [self::class, 'kiir_admin']);
    }

    /** @return array{aktiv: bool, mod: string, fajl: string, nev: string, kredit: string, meret: int, hely: string, eger: bool, forgas: int} */
    public static function beallitas(): array
    {
        $b = get_option(self::OPT, []);
        $b = is_array($b) ? $b : [];

        return [
            'aktiv'  => !empty($b['aktiv']),
            // 0.66.1: „helyette" – a modell MAGA az asszisztens (Szikra helyén, mindent ugyanúgy csinál);
            // „mellette" – kísérő Szikra mellett.
            'mod'    => ($b['mod'] ?? 'helyette') === 'mellette' ? 'mellette' : 'helyette',
            'fajl'   => (string) ($b['fajl'] ?? ''),
            'nev'    => (string) ($b['nev'] ?? ''),
            'kredit' => (string) ($b['kredit'] ?? ''),
            'meret'  => max(60, min(320, (int) ($b['meret'] ?? 130))),
            'hely'   => in_array(($b['hely'] ?? 'bal'), ['bal', 'felette'], true) ? (string) ($b['hely'] ?? 'bal') : 'bal',
            'eger'   => !array_key_exists('eger', $b) || !empty($b['eger']),
            'forgas' => max(-180, min(180, (int) ($b['forgas'] ?? 0))),
        ];
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

    /** A feltöltött modell útja, ha megvan. */
    public static function modell_ut(): string
    {
        $b = self::beallitas();
        [$ut] = self::mappa();

        if ($b['fajl'] === '' || $ut === '' || preg_match('/^modell-[a-f0-9]{8,16}\.glb$/', $b['fajl']) !== 1) {
            return '';
        }

        return is_file($ut . $b['fajl']) ? $ut . $b['fajl'] : '';
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
     * Beállítások › SDH – belső › 3D kísérő
     * ============================================================== */

    public static function doboz(): void
    {
        $b  = self::beallitas();
        $ut = self::modell_ut();
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">3D figura – Szikra helyett vagy mellett</h2>

            <p class="sdh-sugo">
                Egy saját 3D modell (.glb) valódi 3D-ben, a videokártyán rajzolva. <strong>Szikra helyett</strong>
                ő maga az asszisztens: kattintásra nyílik a csevegő, buborékban beszél, sétál, végigvezet,
                jeleneteket játszik, alszik – Szikra hangulatait és mozdulatait 3D-ben játssza el (gondolkodik,
                bólogat, örül, szomorú, pörög, táncol…). <strong>Szikra mellett</strong> kísérőként lebeg mellette. A modell <strong>csak ezen a
                gépen</strong> van (a feltöltési mappában) – a git-repóba és az eladási csomagba nem kerül.
                Ha a böngészőben nincs hardveres gyorsítás, mozdulatlan képként jelenik meg.
            </p>

            <input type="hidden" name="sdh_3d_jelen" value="1">

            <?php $hiba = (string) get_transient('sdh_muhely_sdh_3d_hiba_' . get_current_user_id()); ?>
            <?php if ($hiba !== '') : ?>
                <?php delete_transient('sdh_muhely_sdh_3d_hiba_' . get_current_user_id()); ?>
                <div class="sdh-uzenet sdh-uzenet--hiba" data-sdh-3d-hiba><?php echo esc_html($hiba); ?></div>
            <?php endif; ?>

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="sdh_3d_aktiv" id="sdh_3d_aktiv" value="1" <?php checked($b['aktiv']); ?>>
                <label for="sdh_3d_aktiv">A 3D figura látszódjon</label>
            </div>

            <div class="sdh-mezo">
                <label for="sdh_3d_mod">Szerepe</label>
                <select name="sdh_3d_mod" id="sdh_3d_mod">
                    <option value="helyette" <?php selected($b['mod'], 'helyette'); ?>>Szikra helyett – ő az asszisztens, mindent ugyanúgy csinál</option>
                    <option value="mellette" <?php selected($b['mod'], 'mellette'); ?>>Szikra mellett – kísérő</option>
                </select>
            </div>

            <div class="sdh-mezo">
                <label for="sdh_3d_fajl">3D modell (.glb, legfeljebb <?php echo (int) self::MAX_MB; ?> MB)</label>
                <input type="file" name="sdh_3d_fajl" id="sdh_3d_fajl" accept=".glb,model/gltf-binary">
                <?php if ($ut !== '') : ?>
                    <span class="sdh-sugo" data-sdh-3d-modell>
                        Jelenleg: <strong><?php echo esc_html($b['nev'] !== '' ? $b['nev'] : $b['fajl']); ?></strong>
                        (<?php echo esc_html(size_format((int) filesize($ut), 1)); ?>)
                    </span>
                <?php else : ?>
                    <span class="sdh-sugo">Még nincs feltöltött modell.</span>
                <?php endif; ?>
            </div>

            <?php if ($ut !== '') : ?>
                <div class="sdh-mezo sdh-mezo--jelolo">
                    <input type="checkbox" name="sdh_3d_torol" id="sdh_3d_torol" value="1">
                    <label for="sdh_3d_torol">A modell törlése</label>
                </div>
            <?php endif; ?>

            <div class="sdh-mezo">
                <label for="sdh_3d_kredit">Hivatkozás (szerző, licenc – a CC-BY modelleknél kötelező)</label>
                <input type="text" name="sdh_3d_kredit" id="sdh_3d_kredit" maxlength="300" value="<?php echo esc_attr($b['kredit']); ?>">
            </div>

            <div class="sdh-mezo">
                <label for="sdh_3d_meret">Méret (képpont – csak kísérőként; Szikra helyett a figura méretét veszi át)</label>
                <input type="number" name="sdh_3d_meret" id="sdh_3d_meret" min="60" max="320" step="10" value="<?php echo (int) $b['meret']; ?>">
            </div>

            <div class="sdh-mezo">
                <label for="sdh_3d_hely">Hol legyen (csak kísérőként)</label>
                <select name="sdh_3d_hely" id="sdh_3d_hely">
                    <option value="bal" <?php selected($b['hely'], 'bal'); ?>>Szikra bal oldalán</option>
                    <option value="felette" <?php selected($b['hely'], 'felette'); ?>>Szikra fölött</option>
                </select>
            </div>

            <div class="sdh-mezo">
                <label for="sdh_3d_forgas">Alapirány (fok – ha a modell nem szembe néz)</label>
                <input type="number" name="sdh_3d_forgas" id="sdh_3d_forgas" min="-180" max="180" step="15" value="<?php echo (int) $b['forgas']; ?>">
            </div>

            <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--also-ter">
                <input type="checkbox" name="sdh_3d_eger" id="sdh_3d_eger" value="1" <?php checked($b['eger']); ?>>
                <label for="sdh_3d_eger">Kövesse a tekintetével az egeret</label>
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
        $uj   = [
            'aktiv'  => !empty($_POST['sdh_3d_aktiv']),
            'mod'    => ($_POST['sdh_3d_mod'] ?? $regi['mod']) === 'mellette' ? 'mellette' : 'helyette',
            'fajl'   => $regi['fajl'],
            'nev'    => $regi['nev'],
            'kredit' => isset($_POST['sdh_3d_kredit']) ? mb_substr(sanitize_text_field(wp_unslash((string) $_POST['sdh_3d_kredit'])), 0, 300) : $regi['kredit'],
            'meret'  => max(60, min(320, (int) ($_POST['sdh_3d_meret'] ?? $regi['meret']))),
            'hely'   => in_array(($_POST['sdh_3d_hely'] ?? ''), ['bal', 'felette'], true) ? (string) $_POST['sdh_3d_hely'] : $regi['hely'],
            'eger'   => !empty($_POST['sdh_3d_eger']),
            'forgas' => max(-180, min(180, (int) ($_POST['sdh_3d_forgas'] ?? $regi['forgas']))),
        ];

        [$mappa] = self::mappa();

        if (!empty($_POST['sdh_3d_torol']) && self::modell_ut() !== '') {
            wp_delete_file(self::modell_ut());
            $uj['fajl'] = '';
            $uj['nev']  = '';
        }

        $f = $_FILES['sdh_3d_fajl'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (is_array($f) && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $hiba = self::feltolt($f, $mappa, $uj);

            if ($hiba !== '') {
                set_transient('sdh_muhely_sdh_3d_hiba_' . get_current_user_id(), $hiba, 120);
            }
        }

        update_option(self::OPT, $uj, false);
        // phpcs:enable
    }

    /**
     * A feltöltött .glb átvétele. Hibánál üzenettel tér vissza, és a régi modell marad.
     *
     * @param array<string, mixed> $f
     * @param array<string, mixed> $uj
     */
    public static function feltolt(array $f, string $mappa, array &$uj): string
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

        $v = self::glb_vizsgal((string) $f['tmp_name']);

        if (!$v['jo']) {
            return $v['hiba'];
        }

        if ($mappa === '' || (!is_dir($mappa) && !wp_mkdir_p($mappa))) {
            return 'A feltöltési mappa nem írható.';
        }

        $nev = 'modell-' . substr(md5_file((string) $f['tmp_name']) ?: wp_generate_password(12, false), 0, 12) . '.glb';
        $cel = $mappa . $nev;
        $ok  = is_uploaded_file((string) $f['tmp_name'])
            ? move_uploaded_file((string) $f['tmp_name'], $cel)
            : copy((string) $f['tmp_name'], $cel); // a tesztek nem HTTP-feltöltésből jönnek

        if (!$ok) {
            return 'A modell mentése nem sikerült.';
        }

        $regi = self::modell_ut();

        if ($regi !== '' && $regi !== $cel) {
            wp_delete_file($regi);
        }

        $uj['fajl'] = $nev;
        $uj['nev']  = $v['cim'] !== '' ? $v['cim'] : sanitize_file_name((string) $f['name']);

        if ((string) $uj['kredit'] === '' && $v['kredit'] !== '') {
            $uj['kredit'] = $v['kredit'];
        }

        return '';
    }

    /* =================================================================
     * Betöltés a felületen
     * ============================================================== */

    /** A betöltő adatai, vagy null, ha nincs mit mutatni. */
    public static function adat(): ?array
    {
        if (!SDH_Muhely_Sdh::be() || !is_user_logged_in()) {
            return null;
        }

        $b = self::beallitas();

        if (!$b['aktiv'] || self::modell_ut() === '') {
            return null;
        }

        [, $url] = self::mappa();

        return [
            'url'    => $url . $b['fajl'] . '?v=' . (int) filemtime(self::modell_ut()),
            'mod'    => $b['mod'],
            'nev'    => $b['nev'],
            'kredit' => $b['kredit'],
            'meret'  => $b['meret'],
            'hely'   => $b['hely'],
            'eger'   => $b['eger'],
            'forgas' => $b['forgas'],
        ];
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
