<?php
/**
 * Csatolt fájlok.
 *
 * Bármelyik modul (ma az ügyfél, később az eszköz és a munkalap) ezen
 * keresztül kap „Csatolt fájlok” fület: fotó, PDF, számla, nyilatkozat.
 * Egy csatolmány egy (típus, azonosító) párhoz tartozik, pl. ('ugyfel', 12).
 *
 * Tárolás: a fájl a wp-content/uploads/sdh-muhely/csatolmany/ mappába kerül,
 * kitalálhatatlan véletlen néven és kiterjesztés nélkül; az eredeti név és
 * típus az adatbázisban van. A mappa nem böngészhető (index.php és
 * .htaccess), a fájlt pedig csak bejelentkezett, jogosult felhasználónak adja
 * át a letöltő végpont – közvetlen címen nem érhető el.
 *
 * Biztonság: engedélyezési lista a kiterjesztésekre, a képeket és a PDF-et a
 * tartalmuk alapján is ellenőrizzük, kiszolgáláskor a típust mi adjuk meg
 * (nem a fájlból találgatjuk), a nem képet és nem PDF-et mindig letöltésként
 * küldjük.
 *
 * A mentés az őt tartalmazó űrlap mentésével együtt történik: a feltöltendő
 * fájlok és a törlésre jelöltek az űrlap beküldésekor mennek át.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Csatolmany
{
    /** Az uploads alatti mappa. */
    private const MAPPA = 'sdh-muhely/csatolmany';

    /** Mely modulokhoz lehet csatolni (új modulnál ide kell felvenni). */
    private const TIPUSOK = ['ugyfel', 'eszkoz'];

    /** A mi felső korlátunk fájlonként; a szerver saját korlátja ennél kisebb is lehet. */
    private const MAX_FAJL_MB = 25;

    /** Ezek böngészőben megnyithatók (képek, PDF); a többi mindig letöltés. */
    private const MEGNYITHATO = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

    /** Ezekből készül bélyegkép. */
    private const KEPEK = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_csatolmany_letolt', [self::class, 'ajax_letolt']);
    }

    /* =================================================================
     * Szabályok
     * ============================================================== */

    /**
     * Engedélyezett kiterjesztések és a hozzájuk tartozó típus.
     *
     * @return array<string, string>
     */
    public static function engedelyezett(): array
    {
        return apply_filters('sdh_muhely_csatolmany_engedelyezett', [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odt'  => 'application/vnd.oasis.opendocument.text',
            'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
            'rtf'  => 'application/rtf',
            'txt'  => 'text/plain',
            'csv'  => 'text/csv',
            'zip'  => 'application/zip',
            'mp3'  => 'audio/mpeg',
            'mp4'  => 'video/mp4',
            'mov'  => 'video/quicktime',
        ]);
    }

    /** Egy fájl legnagyobb mérete bájtban: a szerver korlátja és a mi 25 MB-unk közül a kisebb. */
    public static function max_meret(): int
    {
        $szerver = (int) wp_max_upload_size();
        $sajat   = self::MAX_FAJL_MB * MB_IN_BYTES;

        return (int) apply_filters(
            'sdh_muhely_csatolmany_max_meret',
            $szerver > 0 ? min($szerver, $sajat) : $sajat
        );
    }

    /** Egy beküldés összes fájljának legnagyobb mérete (a szerver post_max_size korlátja). */
    public static function max_osszes(): int
    {
        $post = (int) wp_convert_hr_to_bytes((string) ini_get('post_max_size'));

        // A 0 itt „nincs korlát”; a többi mezőnek is kell egy kis hely.
        return $post > 0 ? max(0, $post - 256 * KB_IN_BYTES) : 0;
    }

    private static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('csatolmany');
    }

    private static function tipus_ervenyes(string $tipus): bool
    {
        return in_array($tipus, self::TIPUSOK, true);
    }

    /* =================================================================
     * Tárolás
     * ============================================================== */

    /** A tárolómappa abszolút útja; létrehozza és védi, ha még nincs. */
    private static function mappa(): string
    {
        $feltoltes = wp_upload_dir();
        $mappa     = trailingslashit($feltoltes['basedir']) . self::MAPPA;

        if (!is_dir($mappa)) {
            wp_mkdir_p($mappa);
        }

        $vedelem = [
            'index.php'  => "<?php\n// Csend az aranyat ér.\n",
            '.htaccess'  => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
        ];

        foreach ($vedelem as $nev => $tartalom) {
            $ut = $mappa . '/' . $nev;

            if (!file_exists($ut)) {
                file_put_contents($ut, $tartalom);
            }
        }

        return $mappa;
    }

    /** A tárolt név csak az általunk generált (32 hexa) lehet – útvonal-átírás ellen. */
    private static function tarolt_nev_ervenyes(string $nev): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $nev);
    }

    /**
     * @return array<int, object>
     */
    public static function lista(string $tipus, int $ref_id): array
    {
        global $wpdb;

        if ($ref_id <= 0 || !self::tipus_ervenyes($tipus)) {
            return [];
        }

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::tabla() . ' WHERE tipus = %s AND ref_id = %d ORDER BY id ASC',
                $tipus,
                $ref_id
            )
        );

        return is_array($sorok) ? $sorok : [];
    }

    private static function egy(int $id): ?object
    {
        global $wpdb;

        $sor = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id)
        );

        return $sor ?: null;
    }

    private static function kiterjesztes(string $nev): string
    {
        return strtolower((string) pathinfo($nev, PATHINFO_EXTENSION));
    }

    /* =================================================================
     * A beküldött fájlok ellenőrzése és mentése
     * ============================================================== */

    /**
     * A beküldött fájlok, egységes szerkezetben (az üres fájlmezőt kihagyja).
     *
     * @return array<int, array{nev: string, tmp: string, hiba: int, meret: int}>
     */
    private static function beerkezett(): array
    {
        if (empty($_FILES['csatolmany']) || !is_array($_FILES['csatolmany']['name'])) {
            return [];
        }

        $fajlok = $_FILES['csatolmany']; // phpcs:ignore WordPress.Security.NonceVerification
        $ki     = [];

        foreach ($fajlok['name'] as $i => $nev) {
            $hiba = (int) ($fajlok['error'][$i] ?? UPLOAD_ERR_NO_FILE);

            if ($hiba === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $tiszta = sanitize_text_field(wp_unslash(basename((string) $nev)));

            $ki[] = [
                'nev'   => function_exists('mb_substr') ? mb_substr($tiszta, 0, 190) : substr($tiszta, 0, 190),
                'tmp'   => (string) ($fajlok['tmp_name'][$i] ?? ''),
                'hiba'  => $hiba,
                'meret' => (int) ($fajlok['size'][$i] ?? 0),
            ];
        }

        return $ki;
    }

    /**
     * Mentés ELŐTT: van-e a beküldött fájlok között elfogadhatatlan.
     *
     * Visszatérés: a hibaüzenet, vagy null, ha minden rendben. Így hibás
     * fájl esetén az őt tartalmazó rekord (pl. az ügyfél) sem mentődik félig.
     */
    public static function ellenoriz(): ?string
    {
        $hibak = [];
        $max   = self::max_meret();

        foreach (self::beerkezett() as $fajl) {
            $nev = $fajl['nev'];
            $ext = self::kiterjesztes($nev);

            if ($fajl['hiba'] === UPLOAD_ERR_INI_SIZE || $fajl['hiba'] === UPLOAD_ERR_FORM_SIZE) {
                $hibak[] = sprintf('„%s” nagyobb, mint amit a szerver elfogad (legfeljebb %s).', $nev, size_format($max));
                continue;
            }

            if ($fajl['hiba'] !== UPLOAD_ERR_OK) {
                $hibak[] = sprintf('„%s” feltöltése nem sikerült (hibakód: %d).', $nev, $fajl['hiba']);
                continue;
            }

            if ($fajl['meret'] > $max) {
                $hibak[] = sprintf('„%s” túl nagy (legfeljebb %s lehet).', $nev, size_format($max));
                continue;
            }

            if (!isset(self::engedelyezett()[$ext])) {
                $hibak[] = sprintf('„%s” típusa nem engedélyezett.', $nev);
                continue;
            }

            if (!is_uploaded_file($fajl['tmp']) && !self::teszt_mod()) {
                $hibak[] = sprintf('„%s” nem érkezett meg rendben.', $nev);
                continue;
            }

            if (in_array($ext, self::KEPEK, true) && @getimagesize($fajl['tmp']) === false) {
                $hibak[] = sprintf('„%s” nem valódi kép.', $nev);
                continue;
            }

            if ($ext === 'pdf') {
                $fej = (string) @file_get_contents($fajl['tmp'], false, null, 0, 5);

                if ($fej !== '%PDF-') {
                    $hibak[] = sprintf('„%s” nem valódi PDF.', $nev);
                }
            }
        }

        return $hibak === [] ? null : implode(' ', $hibak);
    }

    /** Csak automata teszteknél igaz: ott nem HTTP-feltöltés a forrás. */
    private static function teszt_mod(): bool
    {
        return defined('SDH_MUHELY_TESZT') && SDH_MUHELY_TESZT;
    }

    /**
     * Mentés UTÁN: a törlésre jelöltek eltávolítása és az új fájlok eltárolása.
     *
     * @return array{hozzaadva: int, torolve: int, hibak: array<int, string>}
     */
    public static function feldolgoz(string $tipus, int $ref_id): array
    {
        global $wpdb;

        $eredmeny = ['hozzaadva' => 0, 'torolve' => 0, 'hibak' => []];

        if ($ref_id <= 0 || !self::tipus_ervenyes($tipus)) {
            return $eredmeny;
        }

        // Törlés: csak az ehhez a rekordhoz tartozó sorok mehetnek.
        $torlendo = isset($_POST['torol_csatolmany']) && is_array($_POST['torol_csatolmany']) // phpcs:ignore WordPress.Security.NonceVerification
            ? array_filter(array_map('intval', wp_unslash($_POST['torol_csatolmany'])))
            : [];

        foreach ($torlendo as $id) {
            $sor = self::egy((int) $id);

            if ($sor === null || $sor->tipus !== $tipus || (int) $sor->ref_id !== $ref_id) {
                continue;
            }

            self::fajl_torol((string) $sor->tarolt_nev);

            if ($wpdb->delete(self::tabla(), ['id' => (int) $sor->id]) !== false) {
                $eredmeny['torolve']++;
            }
        }

        // Feltöltés.
        $fajlok = self::beerkezett();

        if ($fajlok === []) {
            return $eredmeny;
        }

        $mappa = self::mappa();

        foreach ($fajlok as $fajl) {
            $ext = self::kiterjesztes($fajl['nev']);
            $mime = self::engedelyezett()[$ext] ?? 'application/octet-stream';

            try {
                $tarolt = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $tarolt = md5(uniqid((string) wp_rand(), true));
            }

            $cel = $mappa . '/' . $tarolt;

            $athelyezve = self::teszt_mod()
                ? @copy($fajl['tmp'], $cel)
                : @move_uploaded_file($fajl['tmp'], $cel);

            if (!$athelyezve) {
                $eredmeny['hibak'][] = sprintf('„%s” mentése nem sikerült.', $fajl['nev']);
                continue;
            }

            @chmod($cel, 0644);

            $beszurva = $wpdb->insert(self::tabla(), [
                'tipus'       => $tipus,
                'ref_id'      => $ref_id,
                'eredeti_nev' => $fajl['nev'],
                'tarolt_nev'  => $tarolt,
                'mime'        => $mime,
                'meret'       => $fajl['meret'],
                'feltoltve'   => current_time('mysql'),
                'feltolto'    => get_current_user_id(),
            ]);

            if ($beszurva === false) {
                @unlink($cel);
                $eredmeny['hibak'][] = sprintf('„%s” adatainak mentése nem sikerült.', $fajl['nev']);
                continue;
            }

            $eredmeny['hozzaadva']++;
        }

        return $eredmeny;
    }

    private static function fajl_torol(string $tarolt): void
    {
        if (!self::tarolt_nev_ervenyes($tarolt)) {
            return;
        }

        $mappa = self::mappa();

        @unlink($mappa . '/' . $tarolt);
        @unlink($mappa . '/' . $tarolt . '_k.jpg');
    }

    /* =================================================================
     * Kiszolgálás
     * ============================================================== */

    /** A letöltő végpont címe. `mod`: letolt | megnyit | kicsi. */
    public static function url(int $id, string $mod = 'letolt'): string
    {
        return add_query_arg(
            [
                'action'   => 'sdh_muhely_csatolmany_letolt',
                'id'       => $id,
                'mod'      => $mod,
                '_wpnonce' => wp_create_nonce('sdh_muhely_modal'),
            ],
            admin_url('admin-ajax.php')
        );
    }

    private static function nem_talalhato(): void
    {
        status_header(404);
        wp_die('A fájl nem található.', '', ['response' => 404]);
    }

    public static function ajax_letolt(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            status_header(403);
            wp_die('Nincs jogosultságod ehhez.', '', ['response' => 403]);
        }

        $id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $mod = isset($_GET['mod']) ? sanitize_key(wp_unslash($_GET['mod'])) : 'letolt';
        $sor = $id > 0 ? self::egy($id) : null;

        if ($sor === null || !self::tarolt_nev_ervenyes((string) $sor->tarolt_nev)) {
            self::nem_talalhato();
        }

        $mappa = self::mappa();
        $ut    = $mappa . '/' . $sor->tarolt_nev;
        $ext   = self::kiterjesztes((string) $sor->eredeti_nev);
        $mime  = self::engedelyezett()[$ext] ?? 'application/octet-stream';

        if (!is_readable($ut)) {
            self::nem_talalhato();
        }

        $kep         = in_array($ext, self::KEPEK, true);
        $megnyithato = in_array($ext, self::MEGNYITHATO, true);

        // Bélyegkép: egyszer legyártjuk, utána a gyorsítótárból megy.
        if ($mod === 'kicsi' && $kep) {
            $kicsi = $mappa . '/' . $sor->tarolt_nev . '_k.jpg';

            if (!is_readable($kicsi)) {
                $szerkeszto = wp_get_image_editor($ut, ['mime_type' => $mime]);

                if (!is_wp_error($szerkeszto)) {
                    $szerkeszto->resize(240, 240, false);
                    $szerkeszto->set_quality(80);
                    $mentve = $szerkeszto->save($kicsi, 'image/jpeg');

                    if (is_wp_error($mentve)) {
                        $kicsi = '';
                    }
                } else {
                    $kicsi = '';
                }
            }

            if ($kicsi !== '' && is_readable($kicsi)) {
                self::kuld($kicsi, 'image/jpeg', 'inline', 'bely.jpg', true);
            }

            // Nincs GD: az eredeti kép is megteszi bélyegnek.
            self::kuld($ut, $mime, 'inline', (string) $sor->eredeti_nev, true);
        }

        if ($mod === 'megnyit' && $megnyithato) {
            self::kuld($ut, $mime, 'inline', (string) $sor->eredeti_nev, false);
        }

        // Minden más: letöltés, általános típussal, hogy a böngésző ne futtasson semmit.
        self::kuld($ut, 'application/octet-stream', 'attachment', (string) $sor->eredeti_nev, false);
    }

    /**
     * Elküldi a fájlt, és kilép.
     */
    private static function kuld(string $ut, string $mime, string $kezeles, string $nev, bool $gyorsitotar): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $biztonsagos = sanitize_file_name($nev);

        nocache_headers();

        if ($gyorsitotar) {
            header('Cache-Control: private, max-age=86400');
            header_remove('Expires');
            header_remove('Pragma');
        }

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($ut));
        header(
            'Content-Disposition: ' . $kezeles . '; filename="' . $biztonsagos . '"; filename*=UTF-8\'\'' . rawurlencode($nev)
        );

        readfile($ut);
        exit;
    }

    /* =================================================================
     * Megjelenítés – az „Csatolt fájlok” fül tartalma
     * ============================================================== */

    /**
     * A lapfül tartalma: nézetváltó, húzd-ide felület, a meglévő és az új fájlok.
     *
     * A böngészőoldali működés (húzás, kiválasztás, eltávolítás) az app.js-ben van.
     */
    public static function panel(string $tipus, int $ref_id): void
    {
        $meglevok = self::lista($tipus, $ref_id);
        $max      = self::max_meret();
        $osszes   = self::max_osszes();

        ?>
        <div class="sdh-csat" data-sdh-csat data-nezet="ikon"
             data-max-fajl="<?php echo (int) $max; ?>"
             data-max-osszes="<?php echo (int) $osszes; ?>"
             data-engedett="<?php echo esc_attr(implode(',', array_keys(self::engedelyezett()))); ?>">

            <div class="sdh-csat__fej">
                <div class="sdh-csat__nezet" role="group" aria-label="Nézet">
                    <button type="button" class="sdh-csat__nezet-gomb" data-sdh-csat-nezet="ikon"
                            aria-pressed="true">Ikon</button>
                    <button type="button" class="sdh-csat__nezet-gomb" data-sdh-csat-nezet="lista"
                            aria-pressed="false">Lista</button>
                    <button type="button" class="sdh-csat__nezet-gomb" data-sdh-csat-nezet="tomor"
                            aria-pressed="false">Tömör</button>
                </div>

                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-csat-hozzaad>
                    + Fájl hozzáadása
                </button>
            </div>

            <div class="sdh-csat__zona" data-sdh-csat-zona>
                <input type="file" name="csatolmany[]" multiple class="sdh-csat__input"
                       data-sdh-csat-input tabindex="-1">

                <ul class="sdh-csat__lista" data-sdh-csat-lista>
                    <?php foreach ($meglevok as $sor) : ?>
                        <?php
                        $ext = self::kiterjesztes((string) $sor->eredeti_nev);
                        $kep = in_array($ext, self::KEPEK, true);
                        ?>
                        <li class="sdh-csat__elem" data-sdh-csat-elem>
                            <a class="sdh-csat__kep"
                               href="<?php echo esc_url(self::url((int) $sor->id, in_array($ext, self::MEGNYITHATO, true) ? 'megnyit' : 'letolt')); ?>"
                               target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
                                <?php if ($kep) : ?>
                                    <img src="<?php echo esc_url(self::url((int) $sor->id, 'kicsi')); ?>"
                                         alt="" loading="lazy">
                                <?php else : ?>
                                    <span class="sdh-csat__ikon"><?php echo esc_html(strtoupper($ext)); ?></span>
                                <?php endif; ?>
                            </a>

                            <a class="sdh-csat__nev"
                               href="<?php echo esc_url(self::url((int) $sor->id, in_array($ext, self::MEGNYITHATO, true) ? 'megnyit' : 'letolt')); ?>"
                               target="_blank" rel="noopener"
                               title="<?php echo esc_attr((string) $sor->eredeti_nev); ?>">
                                <?php echo esc_html((string) $sor->eredeti_nev); ?>
                            </a>

                            <span class="sdh-csat__meta">
                                <?php echo esc_html(size_format((int) $sor->meret)); ?>
                                <?php if (!empty($sor->feltoltve)) : ?>
                                    · <?php echo esc_html(mysql2date('Y-m-d H:i', (string) $sor->feltoltve)); ?>
                                <?php endif; ?>
                            </span>

                            <span class="sdh-csat__muveletek">
                                <a class="sdh-csat__gomb" title="Letöltés" aria-label="Letöltés"
                                   href="<?php echo esc_url(self::url((int) $sor->id, 'letolt')); ?>">↓</a>
                                <button type="button" class="sdh-csat__gomb sdh-csat__gomb--torol"
                                        data-sdh-csat-torol title="Eltávolítás mentéskor"
                                        aria-label="Eltávolítás">×</button>
                            </span>

                            <input type="hidden" name="torol_csatolmany[]"
                                   value="<?php echo (int) $sor->id; ?>" disabled>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="sdh-csat__ures" data-sdh-csat-ures>
                    <svg class="sdh-csat__gemkapocs" viewBox="0 0 24 24" width="34" height="34" aria-hidden="true">
                        <path d="M16.5 6.5v9a4.5 4.5 0 0 1-9 0V5.8a3 3 0 0 1 6 0v8.4a1.5 1.5 0 0 1-3 0V7"
                              fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                    </svg>
                    <span>Húzza ide a csatolandó fájlokat</span>
                </div>
            </div>

            <p class="sdh-csat__sugo">
                Fájlonként legfeljebb <?php echo esc_html(size_format($max)); ?>. A fájlok az űrlap mentésekor kerülnek fel
                (vagy törlődnek).
            </p>
            <p class="sdh-csat__figyelem" data-sdh-csat-figyelem hidden></p>
        </div>
        <?php
    }

    /** Hány csatolmánya van egy rekordnak (a lapfül feliratához). */
    public static function darab(string $tipus, int $ref_id): int
    {
        return count(self::lista($tipus, $ref_id));
    }
}
