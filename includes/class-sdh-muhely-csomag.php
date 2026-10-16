<?php
/**
 * SDH Műhely – eszközcsomag (0.60.0).
 *
 * MIÉRT: a műhely-felület minden oldala a plugin húsz-egynéhány stíluslapját és
 * szkriptjét töltötte be külön kérésben (a popupok bárhol megnyílhatnak, ezért
 * mindegyik kell). Helyi szerveren (LocalWP) egy-egy kérés önmagában is sokat
 * tart, így a betöltés ideje nem az adatmennyiségtől, hanem a KÉRÉSEK SZÁMÁTÓL
 * lett lassú. Ez az osztály a plugin saját fájljait – pontosan abban a
 * sorrendben, ahogy a WordPress betöltené őket – EGY .js és EGY .css fájlba
 * fűzi össze, és azt az egyet tölti be.
 *
 * Mit NEM csinál:
 *  - nem tömörít és nem ír át kódot (a fájlok tartalma karakterre ugyanaz),
 *  - nem nyúl idegen (WordPress, más plugin) eszközhöz,
 *  - nem teszi a csomagot a plugin mappájába (a feltöltési mappába írja),
 *  - ha bármi nem sikerül (nem írható mappa, hiányzó fájl), csendben marad
 *    minden a régiben: a WordPress a külön fájlokat tölti be.
 *
 * A csomag neve a benne lévő fájlok nevéből és verziójából képzett lenyomat,
 * így új verziónál magától új fájl keletkezik – nincs mit „üríteni".
 */

if (!defined('ABSPATH')) {
    exit;
}

class SDH_Muhely_Csomag
{
    /** A beállítás kulcsa (Beállítások › Megjelenés). */
    public const OPT = 'sdh_muhely_csomag';

    /** A feltöltési mappán belüli alkönyvtár. */
    private const MAPPA = 'sdh-muhely-csomag';

    /** Legfeljebb ennyi csomagfájl maradhat a mappában (a régieket takarítjuk). */
    private const MAX_FAJL = 12;

    public static function init(): void
    {
        // A többi modul eszköze a 10–20 közötti prioritáson kerül sorba; mi a végén nézünk rá.
        add_action('admin_enqueue_scripts', [self::class, 'osszevon'], 9999);
    }

    /** Be van-e kapcsolva (alapból igen). */
    public static function be(): bool
    {
        return get_option(self::OPT, '1') !== '0';
    }

    /** A plugin saját eszköze-e (csak ezekhez nyúlunk). */
    private static function sajat(string $src): bool
    {
        return $src !== '' && str_starts_with($src, SDH_MUHELY_URL . 'assets/');
    }

    /** A webcímből a fájl útja a lemezen (a ?ver= rész nélkül). */
    private static function fajl(string $src): string
    {
        $relativ = substr($src, strlen(SDH_MUHELY_URL));
        $relativ = (string) strtok($relativ, '?');

        // Biztonsági háló: csak az assets mappából, könyvtárlépés nélkül.
        if ($relativ === '' || str_contains($relativ, '..') || !str_starts_with($relativ, 'assets/')) {
            return '';
        }

        $ut = SDH_MUHELY_DIR . $relativ;

        return is_readable($ut) ? $ut : '';
    }

    /**
     * A sorba állított eszközök közül a plugin sajátjai, a WordPress függőségi
     * sorrendjében (ezt a WP_Dependencies::all_deps adja meg).
     *
     * @return array<int, string> handle-ök sorrendben
     */
    private static function handle_sorrend(WP_Dependencies $sor): array
    {
        $sor->all_deps($sor->queue);
        $ki = [];

        foreach ($sor->to_do as $handle) {
            $elem = $sor->registered[$handle] ?? null;

            if ($elem !== null && self::sajat((string) $elem->src) && self::fajl((string) $elem->src) !== '') {
                $ki[] = (string) $handle;
            }
        }

        return $ki;
    }

    /**
     * A csomagfájl útja és webcíme; ha még nincs meg, megírja.
     *
     * @param array<int, string> $fajlok teljes útvonalak
     * @return string a webcím, vagy '' ha nem sikerült
     */
    private static function csomagol(array $fajlok, string $kiterjesztes, string $kulcs): string
    {
        $feltoltes = wp_get_upload_dir();

        if (!empty($feltoltes['error'])) {
            return '';
        }

        $mappa = trailingslashit($feltoltes['basedir']) . self::MAPPA;
        $nev   = 'sdh-' . $kulcs . '.' . $kiterjesztes;
        $ut    = $mappa . '/' . $nev;
        $cim   = trailingslashit($feltoltes['baseurl']) . self::MAPPA . '/' . $nev;

        if (is_readable($ut) && filesize($ut) > 0) {
            return $cim;
        }

        if (!wp_mkdir_p($mappa)) {
            return '';
        }

        self::fejlec_szabaly($mappa);

        $szoveg = '';

        foreach ($fajlok as $fajl) {
            $tartalom = file_get_contents($fajl); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

            if ($tartalom === false) {
                return '';
            }

            // A fájl neve a csomagban is látszik: így a böngésző hibaüzenete is értelmezhető marad.
            // Szkriptnél (0.64) minden fájl saját try-blokkban fut: ha az egyik indulás közben hibát dob,
            // a többi ugyanúgy elindul, mint külön fájlként (a fájlok önálló függvényblokkok).
            $szoveg .= $kiterjesztes === 'js'
                ? '/* ' . basename($fajl) . " */\ntry {\n" . $tartalom . "\n} catch (sdhHiba) { if (window.console) { console.error('SDH Műhely – " . basename($fajl) . "', sdhHiba); } }\n"
                : '/* ' . basename($fajl) . " */\n" . $tartalom . "\n";
        }

        // Ideiglenes néven írunk, majd átnevezünk: fél fájlt soha nem szolgálunk ki.
        $ideiglenes = $ut . '.' . wp_generate_password(6, false) . '.tmp';

        if (file_put_contents($ideiglenes, $szoveg) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
            return '';
        }

        if (!@rename($ideiglenes, $ut)) {
            @unlink($ideiglenes);

            return '';
        }

        self::takarit($mappa);

        return $cim;
    }

    /**
     * Csomag a műhely-felület (Frontend::keret) fájljaiból (0.64).
     *
     * A wp-admin a WordPress sorából csomagol (osszevon); a műhely-felület saját
     * kezűleg sorolja fel az eszközeit – ez a függvény azokat fűzi össze, ugyanúgy.
     * Üres sztringet ad, ha ki van kapcsolva, vagy ha nem sikerül (ilyenkor a
     * felület a fájlokat egyenként tölti be, ahogy eddig).
     *
     * @param array<int, string> $relativ A plugin mappájához viszonyított utak (assets/…).
     */
    public static function fajlokbol(array $relativ, string $fajta): string
    {
        if (!self::be() || count($relativ) < 2 || !in_array($fajta, ['js', 'css'], true)) {
            return '';
        }

        $fajlok = [];
        $reszek = [SDH_MUHELY_VERSION, $fajta, 'felulet'];

        foreach ($relativ as $ut) {
            $fajl = self::fajl(SDH_MUHELY_URL . $ut);

            if ($fajl === '') {
                return '';
            }

            $fajlok[] = $fajl;
            $reszek[] = $ut . '|' . (string) filemtime($fajl);
        }

        return self::csomagol($fajlok, $fajta, substr(md5(implode("\n", $reszek)), 0, 16));
    }

    /**
     * Apache alatt (.htaccess) a csomagok egy évig gyorsítótárazhatók: a nevükben a tartalom
     * lenyomata van, új tartalom új nevet kap. (Nginx – LocalWP alapból – ezt a fájlt nem olvassa;
     * ott a súgó Sebesség fejezete mondja meg, mit kell beállítani.)
     */
    private static function fejlec_szabaly(string $mappa): void
    {
        $ut = $mappa . '/.htaccess';

        if (is_file($ut)) {
            return;
        }

        @file_put_contents($ut, "<IfModule mod_headers.c>\n    <FilesMatch \"^sdh-[0-9a-f]+\\.(js|css)$\">\n        Header set Cache-Control \"public, max-age=31536000, immutable\"\n    </FilesMatch>\n</IfModule>\n"); // phpcs:ignore
    }

    /** A régi csomagok törlése (verziónként keletkezik egy-egy új). */
    private static function takarit(string $mappa): void
    {
        $fajlok = glob($mappa . '/sdh-*.{js,css}', GLOB_BRACE) ?: [];

        if (count($fajlok) <= self::MAX_FAJL) {
            return;
        }

        usort($fajlok, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));

        foreach (array_slice($fajlok, self::MAX_FAJL) as $regi) {
            @unlink($regi);
        }
    }

    /**
     * A csomag kulcsa: a fájlok nevéből, verziójából és módosítási idejéből.
     *
     * A módosítási idő azért kell, mert frissítéskor nem mindig emelkedik a
     * verzió (javítás, kézi másolás): e nélkül a böngésző a régi csomagot kapná.
     */
    private static function kulcs(WP_Dependencies $sor, array $handlek, string $fajta): string
    {
        $reszek = [SDH_MUHELY_VERSION, $fajta];

        foreach ($handlek as $handle) {
            $elem     = $sor->registered[$handle];
            $fajl     = self::fajl((string) $elem->src);
            $reszek[] = $handle . '|' . (string) $elem->ver . '|' . ($fajl !== '' ? (string) filemtime($fajl) : '');
        }

        return substr(md5(implode("\n", $reszek)), 0, 16);
    }

    /**
     * A plugin szkriptjeinek és stíluslapjainak összevonása.
     *
     * A beszúrt (inline) kódok megmaradnak: a „before" darabok a csomag elé, az
     * „after" darabok a csomag mögé kerülnek – ugyanabban a sorrendben.
     */
    public static function osszevon(): void
    {
        if (!self::be()) {
            return;
        }

        self::egy_sor(wp_scripts(), 'js');
        self::egy_sor(wp_styles(), 'css');
    }

    private static function egy_sor(WP_Dependencies $sor, string $fajta): void
    {
        $handlek = self::handle_sorrend($sor);

        // Egy fájlért nem éri meg csomagolni.
        if (count($handlek) < 2) {
            return;
        }

        $fajlok = [];
        $elott  = [];
        $utan   = [];
        $fugges = [];

        foreach ($handlek as $handle) {
            $elem     = $sor->registered[$handle];
            $fajlok[] = self::fajl((string) $elem->src);

            // A csomagon kívüli függőségek (pl. jquery) a csomagra öröklődnek.
            foreach ((array) $elem->deps as $dep) {
                if (!in_array($dep, $handlek, true)) {
                    $fugges[] = (string) $dep;
                }
            }

            foreach (['before' => &$elott, 'after' => &$utan] as $hol => &$gyujto) {
                $adat = $sor->get_data($handle, $hol);

                foreach ((array) $adat as $darab) {
                    if (is_string($darab) && trim($darab) !== '') {
                        $gyujto[] = $darab;
                    }
                }
            }

            unset($gyujto);
        }

        $kulcs = self::kulcs($sor, $handlek, $fajta);
        $cim   = self::csomagol($fajlok, $fajta, $kulcs);

        if ($cim === '') {
            return; // Nem sikerült: marad a régi, külön fájlos betöltés.
        }

        foreach ($handlek as $handle) {
            $sor->dequeue($handle);
            $sor->remove($handle);
        }

        $uj = 'sdh-muhely-csomag-' . $fajta;

        if ($fajta === 'js') {
            wp_enqueue_script($uj, $cim, array_values(array_unique($fugges)), SDH_MUHELY_VERSION, true);

            foreach ($elott as $darab) {
                wp_add_inline_script($uj, $darab, 'before');
            }

            foreach ($utan as $darab) {
                wp_add_inline_script($uj, $darab, 'after');
            }

            return;
        }

        wp_enqueue_style($uj, $cim, array_values(array_unique($fugges)), SDH_MUHELY_VERSION);

        foreach (array_merge($elott, $utan) as $darab) {
            wp_add_inline_style($uj, $darab);
        }
    }

    /* =================================================================
     * Beállítások › Rendszer › Sebesség
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $feltoltes = wp_get_upload_dir();
        $mappa     = empty($feltoltes['error']) ? trailingslashit($feltoltes['basedir']) . self::MAPPA : '';
        $irhato    = $mappa !== '' && (is_writable($mappa) || is_writable(dirname($mappa)));
        $db        = $mappa !== '' ? count(glob($mappa . '/sdh-*.{js,css}', GLOB_BRACE) ?: []) : 0;

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Gyors betöltés</h2>

            <p class="sdh-sugo">
                A műhely-felület húsznál is több stíluslapot és szkriptet használ, mert a popupok
                (munkalap, pénztár, nyomtatás, napló…) bármelyik oldalon megnyílhatnak. Bekapcsolva
                ezek egyetlen stíluslapba és egyetlen szkriptbe fűzve töltődnek be: ugyanaz a kód,
                de sok kérés helyett kettő. Helyi szerveren (LocalWP) ez a legnagyobb különbség,
                mert ott minden egyes kérés sokat tart.
            </p>

            <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--also-ter">
                <input type="hidden" name="csomag_jelen" value="1">
                <input type="checkbox" name="csomag_aktiv" id="csomag_aktiv" value="1" <?php checked(self::be()); ?>>
                <label for="csomag_aktiv">A felület CSS- és JS-fájljai egy kérésben töltődjenek be</label>
            </div>

            <?php if (!$irhato) : ?>
                <div class="sdh-uzenet sdh-uzenet--hiba">
                    A feltöltési mappa nem írható, ezért a csomag nem készülhet el – a felület a külön
                    fájlokat tölti be. (A beállítás bekapcsolva is maradhat: amint a mappa írható lesz,
                    magától működni kezd.)
                </div>
            <?php else : ?>
                <p class="sdh-sugo">
                    Jelenleg <?php echo (int) $db; ?> csomagfájl áll a feltöltési mappában. Új verziónál
                    magától új csomag készül, a régieket a rendszer takarítja – nincs mit üríteni.
                </p>
            <?php endif; ?>

            <?php $szerver = self::szerver_allapot(); ?>
            <p class="sdh-sugo" data-sdh-szerver-allapot>
                <strong>Szerver:</strong>
                PHP OPcache: <strong><?php echo esc_html($szerver['opcache']); ?></strong> ·
                MySQL puffer: <strong><?php echo esc_html($szerver['puffer']); ?></strong>
                <?php if ($szerver['tanacs'] !== '') : ?>
                    <br><?php echo esc_html($szerver['tanacs']); ?>
                <?php endif; ?>
            </p>

            <p class="sdh-sugo" data-sdh-gpu-allapot>Hardveres gyorsítás: ellenőrzés…</p>
            <p class="sdh-sugo">
                A hardveres gyorsítást weboldal nem kapcsolhatja be a böngészőben – ezt a böngésző
                beállítása dönti el. Ha KI van, a felület figyelmeztet, és könnyített módba kapcsol.
                Kikényszerítés a cég gépein: <code>tools/hardveres-gyorsitas/</code> (Súgó › Hibaelhárítás › Sebesség).
            </p>
        </div>
        <?php
        // H10 (0.90.0): adatbázis-mérés a böngészőből (csak fejlesztőnek).
        if (class_exists('SDH_Muhely_Meres')) {
            SDH_Muhely_Meres::doboz();
        }
    }

    /**
     * A sebességhez fontos két szerverbeállítás (0.64): a webszerver PHP-jának OPcache-e és
     * a MySQL / MariaDB InnoDB-puffere. Csak olvas.
     *
     * @return array{opcache: string, puffer: string, tanacs: string}
     */
    public static function szerver_allapot(): array
    {
        global $wpdb;

        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $be      = is_array($opcache) && !empty($opcache['opcache_enabled']);
        $mb      = 0;

        if (!(class_exists('WP_SQLite_DB', false) && $wpdb instanceof WP_SQLite_DB)) {
            $mb = (int) round((int) $wpdb->get_var('SELECT @@innodb_buffer_pool_size') / 1048576);
        }

        $tanacs = [];

        if (!$be) {
            $tanacs[] = 'Kapcsold be az OPcache-t (Súgó › Hibaelhárítás › Sebesség).';
        }

        if ($mb > 0 && $mb < 512) {
            $tanacs[] = 'Növeld a MySQL pufferét legalább 512 MB-ra (Súgó › Hibaelhárítás › Sebesség).';
        }

        return [
            'opcache' => $be ? 'be' : 'ki',
            'puffer'  => $mb > 0 ? $mb . ' MB' : 'nem MySQL (SQLite)',
            'tanacs'  => implode(' ', $tanacs),
        ];
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['csomag_jelen'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_option(self::OPT, empty($_POST['csomag_aktiv']) ? '0' : '1', false);
    }
}
