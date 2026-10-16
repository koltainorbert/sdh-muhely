<?php
/**
 * Mentés kibontása, visszaállítás, export / import (0.73.0, H3).
 *
 * A mentésfájl formátuma (SDH_Muhely_Asszisztens_Tudas::teljes_mentes): gzip-pelt JSON-sorok,
 * az első sor a fejléc, majd {"t":tábla,"s":sor} és {"o":opció,"v":érték} sorok.
 *
 * Három fajta fájl nyitható meg:
 *   – a rendszer saját nyers mentése (.jsonl.gz): a titkos kulcsok a WordPress-sókhoz kötve állnak,
 *     ezért ugyanezen a telepítésen állítható vissza hibátlanul;
 *   – hordozható, jelszavas csomag (.sdhmentes): a kulcsok a titkosított burkon BELÜL nyíltan állnak,
 *     és visszaállításkor az új telepítés sóival titkosítódnak újra – így a mentés másik gépre
 *     költözéshez is jó, a beállításokat nem kell újra megadni;
 *   – beállítás-csomag (.sdhmentes, "tipus":"beallitasok"): csak a beállítások, adatok nélkül.
 *
 * Visszaállítás előtt MINDIG készül egy biztonsági mentés az aktuális állapotról, és az adatbázis-
 * művelet egy tranzakcióban fut: hiba esetén minden a régi marad.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Mentes_Vissza
{
    /** A hordozható csomagban a titkos érték nyílt szövege ezzel az előtaggal áll. */
    public const NYILT = 'sdh-nyilt:';

    private const ZAR   = 'sdh_muhely_vissza_fut';
    private const UTOLSO = 'sdh_muhely_mentes_vissza_utolso';

    /** Futási állapot, amely nem „beállítás” – visszaállításkor sosem íródik felül. */
    private const ALLAPOT_OPCIO = '/(utolso|futott|_naplo$|_napi$|_nap$|verzio|version|potlas|telepitve|_zar$|_fut$|lock|gyorsitotar|cache|szamlalo|ujdonsag|tudott|health|_jelzes|rewrite|onteszt|_pulzus|statusz|atallas|_allapot$|kereso_)/i';

    /** @return list<string> a Beállítás-csomagból és a „csak beállítások” visszaállításból kimaradó extra opciók */
    private static function csak_adathoz(): array
    {
        return ['sdh_muhely_valtozasnaplo_ellenorzes'];
    }

    /** A beállításnak számító opció-e (nem futási állapot). */
    public static function beallitas_opcio(string $nev): bool
    {
        return strncmp($nev, 'sdh_muhely_', 11) === 0 && !self::futasi_allapot($nev);
    }

    /* =================================================================
     * Titkos értékek: sdh1:… (sóhoz kötött)  ⇄  sdh-nyilt:… (hordozható)
     * ============================================================== */

    /**
     * @param mixed $ertek
     * @return mixed
     */
    private static function atalakit($ertek, bool $nyiltra)
    {
        if (is_string($ertek)) {
            if ($nyiltra && SDH_Muhely_Titok::titkos_e($ertek)) {
                return self::NYILT . SDH_Muhely_Titok::visszafejt($ertek);
            }

            if (!$nyiltra && strncmp($ertek, self::NYILT, strlen(self::NYILT)) === 0) {
                return SDH_Muhely_Titok::titkosit(substr($ertek, strlen(self::NYILT)));
            }

            return $ertek;
        }

        if (is_array($ertek)) {
            foreach ($ertek as $k => $v) {
                $ertek[$k] = self::atalakit($v, $nyiltra);
            }
        }

        return $ertek;
    }

    /** Egy opció tárolt (szerializált) értékének átalakítása. */
    private static function opcio_ertek(string $nyers, bool $nyiltra): string
    {
        if (!$nyiltra && strncmp($nyers, self::NYILT, strlen(self::NYILT)) !== 0 && strpos($nyers, self::NYILT) === false) {
            return $nyers;
        }

        if ($nyiltra && strpos($nyers, 'sdh1:') === false) {
            return $nyers;
        }

        $ertek = is_serialized($nyers) ? @unserialize($nyers, ['allowed_classes' => false]) : $nyers; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions

        if ($ertek === false && $nyers !== 'b:0;') {
            return $nyers;
        }

        $uj = self::atalakit($ertek, $nyiltra);

        return is_string($uj) ? $uj : serialize($uj); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
    }

    /* =================================================================
     * Hordozható csomag készítése
     * ============================================================== */

    /** Ideiglenes fájl a mentési mappában (a mappa kívülről nem olvasható). */
    private static function ideiglenes(string $vegzodes): string
    {
        return SDH_Muhely_Asszisztens_Tudas::mentes_mappa() . 'sdh-mentes-tmp-' . wp_generate_password(10, false) . $vegzodes . '.tmp';
    }

    /**
     * A nyers mentésből hordozható (nyílt kulcsos) gzip készül, amit a hívó azonnal titkosít.
     *
     * @return string|null null = kész; különben a hiba
     */
    public static function hordozhato(string $forras_gz, string $cel_gz): ?string
    {
        $be = @gzopen($forras_gz, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if ($be === false) {
            return 'A mentésfájl nem olvasható.';
        }

        $ki = @gzopen($cel_gz, 'wb6'); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if ($ki === false) {
            gzclose($be);

            return 'A csomag ideiglenes fájlja nem írható.';
        }

        $hiba = null;

        while (($sor = gzgets($be)) !== false) {
            // Táblasor sóhoz kötött titkos mezővel (0.79: feloldó kód / minta): hordozható alakra.
            if (strncmp($sor, '{"t":', 5) === 0 && strpos($sor, '"sdh1:') !== false) {
                $t = json_decode($sor, true);

                if (is_array($t) && isset($t['s']) && is_array($t['s'])) {
                    $t['s'] = self::atalakit($t['s'], true);
                    $sor    = (string) wp_json_encode($t, JSON_UNESCAPED_UNICODE) . "\n";
                }
            }

            if (strncmp($sor, '{"o":', 5) === 0) {
                $o = json_decode($sor, true);

                if (is_array($o) && isset($o['o'], $o['v']) && is_string($o['v'])) {
                    $o['v'] = self::opcio_ertek($o['v'], true);
                    $sor    = (string) wp_json_encode($o, JSON_UNESCAPED_UNICODE) . "\n";
                }
            }

            if (gzwrite($ki, $sor) !== strlen($sor)) {
                $hiba = 'A csomag írása megszakadt (megtelt a lemez?).';
                break;
            }
        }

        gzclose($be);

        if (!gzclose($ki) && $hiba === null) {
            $hiba = 'A csomag nem zárható le.';
        }

        if ($hiba !== null) {
            @unlink($cel_gz); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        return $hiba;
    }

    /**
     * Beállítás-csomag az élő adatbázisból: csak a beállítások, nyílt kulcsokkal (titkosítás a hívónál).
     *
     * @return string|null
     */
    public static function beallitas_csomag(string $cel_gz): ?string
    {
        global $wpdb;

        $ki = @gzopen($cel_gz, 'wb6'); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if ($ki === false) {
            return 'A csomag ideiglenes fájlja nem írható.';
        }

        $ir = static function (array $adat) use ($ki): bool {
            $sor = (string) wp_json_encode($adat, JSON_UNESCAPED_UNICODE) . "\n";

            return gzwrite($ki, $sor) === strlen($sor);
        };

        $ok = $ir(['mentes' => SDH_MUHELY_VERSION, 'db' => SDH_Muhely_Schema::DB_VERSION, 'ido' => current_time('mysql'), 'ok' => 'beallitasok', 'tipus' => 'beallitasok']);

        $opciok = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'sdh\\_muhely\\_%' ORDER BY option_name", ARRAY_A); // phpcs:ignore

        foreach ((array) $opciok as $o) {
            $nev = (string) $o['option_name'];

            if (!self::beallitas_opcio($nev) || in_array($nev, self::csak_adathoz(), true)) {
                continue;
            }

            $ok = $ok && $ir(['o' => $nev, 'v' => self::opcio_ertek((string) $o['option_value'], true)]);
        }

        if (!gzclose($ki) || !$ok) {
            @unlink($cel_gz); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return 'A beállítás-csomag írása megszakadt.';
        }

        return null;
    }

    /**
     * Kész, jelszóval titkosított fájl: a nyers mentésből (hordozható) vagy az élő beállításokból.
     *
     * @param string $forras a nyers mentés teljes útja; üres = beállítás-csomag az élő adatbázisból
     * @return string|null
     */
    public static function csomag_keszit(string $forras, string $cel_enc, string $jelszo): ?string
    {
        if (($h = SDH_Muhely_Mentes_Titkos::jelszo_hiba($jelszo)) !== null) {
            return $h;
        }

        $tmp = self::ideiglenes('.jsonl.gz');
        $h   = $forras === '' ? self::beallitas_csomag($tmp) : self::hordozhato($forras, $tmp);

        if ($h === null) {
            $h = SDH_Muhely_Mentes_Titkos::titkosit_fajl($tmp, $cel_enc, $jelszo);
        }

        @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        return $h;
    }

    /* =================================================================
     * Megnyitás és áttekintés
     * ============================================================== */

    /**
     * A fájl kibontása és áttekintése. A titkosított fájl a mentési mappába kerül kibontva (ideiglenes néven);
     * a hívó a visszaállítás után a 'gz' fájlt törli.
     *
     * @return array{gz: string, kibontott: bool, fej: array<string, mixed>, tipus: string, tablak: array<string, int>, opciok: int, jelszavas: bool}|string
     */
    public static function megnyit(string $fajl, string $jelszo)
    {
        if (!is_file($fajl)) {
            return 'A mentésfájl nem található.';
        }

        $gz        = $fajl;
        $kibontott = false;
        $jelszavas = false;

        if (SDH_Muhely_Mentes_Titkos::titkos_fajl_e($fajl)) {
            $jelszavas = true;
            $gz        = self::ideiglenes('.jsonl.gz');
            $h         = SDH_Muhely_Mentes_Titkos::visszafejt_fajl($fajl, $gz, $jelszo);

            if ($h !== null) {
                return $h;
            }

            $kibontott = true;
        }

        $f = @gzopen($gz, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if ($f === false) {
            self::eldob($gz, $kibontott);

            return 'A fájl nem mentés (nem gzip).';
        }

        $elso = gzgets($f);
        $fej  = is_string($elso) ? json_decode($elso, true) : null;

        if (!is_array($fej) || !isset($fej['mentes'])) {
            gzclose($f);
            self::eldob($gz, $kibontott);

            return 'Ez a fájl nem SDH Műhely mentés.';
        }

        $tablak = [];
        $opciok = 0;

        while (($sor = gzgets($f)) !== false) {
            if (strncmp($sor, '{"t":"', 6) === 0) {
                $veg = strpos($sor, '"', 6);
                $t   = $veg === false ? '' : substr($sor, 6, $veg - 6);
                $tablak[$t] = ($tablak[$t] ?? 0) + 1;
            } elseif (strncmp($sor, '{"o":', 5) === 0) {
                $opciok++;
            }
        }

        gzclose($f);

        return [
            'gz'        => $gz,
            'kibontott' => $kibontott,
            'fej'       => $fej,
            'tipus'     => (string) ($fej['tipus'] ?? 'teljes'),
            'tablak'    => $tablak,
            'opciok'    => $opciok,
            'jelszavas' => $jelszavas,
        ];
    }

    /** A megnyitás ideiglenes fájljának eldobása. */
    public static function eldob(string $gz, bool $kibontott = true): void
    {
        if ($kibontott && strpos(basename($gz), 'sdh-mentes-tmp-') === 0) {
            @unlink($gz); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
    }

    /* =================================================================
     * Visszaállítás
     * ============================================================== */

    /**
     * @param string $mod teljes | beallitasok
     * @return array{tablak: int, sorok: int, opciok: int, biztonsagi: string}|string az összegzés, vagy a hiba
     */
    public static function visszaallit(string $gz, string $mod)
    {
        global $wpdb;

        if (!in_array($mod, ['teljes', 'beallitasok'], true)) {
            return 'Ismeretlen visszaállítási mód.';
        }

        if (!function_exists('gzopen') || !class_exists('SDH_Muhely_Asszisztens_Tudas')) {
            return 'A visszaállítás ezen a szerveren nem érhető el.';
        }

        $f = @gzopen($gz, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if ($f === false) {
            return 'A mentésfájl nem olvasható.';
        }

        $elso = gzgets($f);
        $fej  = is_string($elso) ? json_decode($elso, true) : null;

        if (!is_array($fej) || !isset($fej['mentes'])) {
            gzclose($f);

            return 'Ez a fájl nem SDH Műhely mentés.';
        }

        $tipus = (string) ($fej['tipus'] ?? 'teljes');

        if ($mod === 'teljes' && $tipus !== 'teljes') {
            gzclose($f);

            return 'Ez beállítás-csomag: csak a beállítások állíthatók vissza belőle.';
        }

        if (isset($fej['db']) && version_compare((string) $fej['db'], SDH_Muhely_Schema::DB_VERSION, '>')) {
            gzclose($f);

            return 'Ez a mentés újabb verzióból készült (' . $fej['mentes'] . ') – előbb frissítsd a plugint.';
        }

        if (!self::zar_fog()) {
            gzclose($f);

            return 'Már fut egy visszaállítás.';
        }

        @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        ignore_user_abort(true);

        $b = null;

        try {
            // 1) Biztonsági mentés az ÁLLAPOTRÓL, mielőtt bármihez nyúlnánk.
            SDH_Muhely_Schema::frissites_ha_kell();
            $b = SDH_Muhely_Asszisztens_Tudas::teljes_mentes('visszaallitas-elott');

            if (is_string($b)) {
                return 'A biztonsági mentés nem készült el, ezért nem állítom vissza: ' . $b;
            }

            $r = SDH_Muhely_Valtozasnaplo::csendben(static fn () => self::alkalmaz($f, $mod));
        } finally {
            gzclose($f);
            delete_option(self::ZAR);
        }

        if (!is_array($b)) {
            return (string) $b;
        }

        if (is_string($r)) {
            return $r . ' Az adatok nem változtak; a biztonsági mentés: ' . $b['fajl'] . '.';
        }

        // Újraépülő származtatott adatok és gyorsítótárak (csak az adat visszaállítása érinti őket).
        if ($mod === 'teljes') {
            delete_option(SDH_Muhely_Ugyfel::KERESO_ALLAPOT);
            delete_option('sdh_muhely_kereso_utolso');
            delete_option('sdh_muhely_mentes_nap');
        }

        wp_cache_flush();

        $r['biztonsagi'] = $b['fajl'];

        update_option(self::UTOLSO, [
            'ido'        => current_time('mysql'),
            'mod'        => $mod,
            'biztonsagi' => $b['fajl'],
            'felhasznalo' => (int) get_current_user_id(),
        ], false);

        return $r;
    }

    private static function zar_fog(): bool
    {
        if (add_option(self::ZAR, (string) time(), '', false)) {
            return true;
        }

        if ((int) get_option(self::ZAR, 0) < time() - 30 * MINUTE_IN_SECONDS) {
            delete_option(self::ZAR);

            return add_option(self::ZAR, (string) time(), '', false);
        }

        return false;
    }

    /**
     * A tranzakció: törlés + beszúrás + beállítások. Hiba esetén ROLLBACK, és a hiba szövege.
     *
     * @param resource $f a fejléc után álló gzip-folyam
     * @return array{tablak: int, sorok: int, opciok: int}|string
     */
    private static function alkalmaz($f, string $mod)
    {
        global $wpdb;

        $mentendo = SDH_Muhely_Asszisztens_Tudas::mentendo_tablak();
        $rovid    = [];

        foreach ($mentendo as $t) {
            $rovid[str_replace($wpdb->prefix, '', $t)] = $t;
        }

        $oszlopok = [];   // tábla (rövid név) => ma létező oszlopok
        $puffer   = [];   // tábla => ['oszl' => [...], 'sorok' => [[...]]]
        $tablak   = [];
        $hiba     = null;
        $sorok    = 0;
        $opciok   = 0;

        $wpdb->suppress_errors(true);
        $wpdb->query('START TRANSACTION');

        $vegrehajt = static function (string $sql) use ($wpdb, &$hiba): bool {
            if ($wpdb->query($sql) === false) {
                $hiba = 'Adatbázis-hiba: ' . $wpdb->last_error;

                return false;
            }

            return true;
        };

        // Egy tábla gyűjtött soraiból egy többsoros INSERT.
        $kiir = static function (string $nev) use (&$puffer, $rovid, $vegrehajt, &$sorok): bool {
            if (empty($puffer[$nev]['sorok'])) {
                return true;
            }

            $ossz  = $puffer[$nev]['oszl'];
            $ertek = [];

            foreach ($puffer[$nev]['sorok'] as $s) {
                $dara = [];

                foreach ($ossz as $o) {
                    $v      = $s[$o] ?? null;
                    $dara[] = $v === null ? 'NULL' : "'" . esc_sql((string) $v) . "'";
                }

                $ertek[] = '(' . implode(',', $dara) . ')';
            }

            $sorok         += count($ertek);
            $puffer[$nev]   = ['oszl' => $ossz, 'sorok' => []];

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- esc_sql-lel készült értékek, ellenőrzött táblanév és oszlopnevek.
            return $vegrehajt('INSERT INTO ' . $rovid[$nev] . ' (`' . implode('`,`', $ossz) . '`) VALUES ' . implode(',', $ertek));
        };

        if ($mod === 'teljes') {
            // A szóindex nem része a mentésnek (újraépül) – a régi szavai nem maradhatnak vissza.
            foreach (array_merge($mentendo, [SDH_Muhely_Schema::tabla('ugyfel_szo')]) as $t) {
                if (!$vegrehajt('DELETE FROM ' . $t)) {
                    break;
                }
            }
        }

        while ($hiba === null && ($sor = gzgets($f)) !== false) {
            $sor = trim($sor);

            if ($sor === '') {
                continue;
            }

            $a = json_decode($sor, true);

            if (!is_array($a)) {
                $hiba = 'A mentésfájl sérült (értelmezhetetlen sor).';
                break;
            }

            if (isset($a['t'])) {
                $nev = (string) $a['t'];

                if ($mod !== 'teljes' || !isset($rovid[$nev]) || !is_array($a['s'] ?? null)) {
                    continue; // beállítás-visszaállításnál nincs adat; ismeretlen (megszűnt) tábla kimarad
                }

                if (!isset($oszlopok[$nev])) {
                    $mezok = (array) $wpdb->get_col('SHOW COLUMNS FROM ' . $rovid[$nev], 0);

                    if ($mezok === []) {
                        $hiba = 'A(z) ' . $nev . ' tábla oszlopai nem olvashatók.';
                        break;
                    }

                    $oszlopok[$nev] = array_values(array_map('strval', $mezok));
                }

                // Csak a ma is létező oszlopok kerülnek vissza; a régebbi mentésből hiányzó oszlop a tábla alapértékét kapja.
                $sorf = [];

                foreach ($oszlopok[$nev] as $o) {
                    if (array_key_exists($o, $a['s'])) {
                        // A hordozható csomag nyílt titkai (sdh-nyilt:…) az új gép sóival titkosítva kerülnek vissza.
                        $sorf[$o] = is_string($a['s'][$o]) ? self::atalakit($a['s'][$o], false) : $a['s'][$o];
                    }
                }

                if ($sorf === []) {
                    continue;
                }

                $oszl = array_keys($sorf);

                // Egy INSERT-ben csak azonos oszlopkészletű sorok lehetnek.
                if (isset($puffer[$nev]) && $puffer[$nev]['oszl'] !== $oszl && !$kiir($nev)) {
                    break;
                }

                $puffer[$nev]['oszl']     = $oszl;
                $puffer[$nev]['sorok'][]  = $sorf;
                $tablak[$nev]             = true;

                if (count($puffer[$nev]['sorok']) >= 200 && !$kiir($nev)) {
                    break;
                }

                continue;
            }

            if (isset($a['o'])) {
                $nev = (string) $a['o'];

                if (!self::beallitas_opcio($nev) || ($mod === 'beallitasok' && in_array($nev, self::csak_adathoz(), true))) {
                    continue;
                }

                // Közvetlenül az options-táblába: a WordPress-horgok (és a változásnapló) kihagyásával, a
                // tárolt (szerializált) alakban. A meglévő opció autoload-jelölése megmarad.
                $ertek = self::opcio_ertek((string) ($a['v'] ?? ''), false);
                $van   = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $nev)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

                $ok = $van !== null
                    ? $wpdb->update($wpdb->options, ['option_value' => $ertek], ['option_name' => $nev])
                    : $wpdb->insert($wpdb->options, ['option_name' => $nev, 'option_value' => $ertek, 'autoload' => 'no']);

                if ($ok === false) {
                    $hiba = 'Adatbázis-hiba a(z) ' . $nev . ' beállításnál: ' . $wpdb->last_error;
                    break;
                }

                $opciok++;
            }
        }

        if ($hiba === null) {
            foreach (array_keys($puffer) as $nev) {
                if (!$kiir($nev)) {
                    break;
                }
            }
        }

        if ($hiba !== null) {
            $wpdb->query('ROLLBACK');
            $wpdb->suppress_errors(false);
            wp_cache_flush();

            return $hiba;
        }

        $wpdb->query('COMMIT');
        $wpdb->suppress_errors(false);

        return ['tablak' => count($tablak), 'sorok' => $sorok, 'opciok' => $opciok];
    }

    /** Futási állapot-e (visszaállításkor sosem írjuk). */
    private static function futasi_allapot(string $nev): bool
    {
        return preg_match(self::ALLAPOT_OPCIO, $nev) === 1
            || strpos($nev, 'transient') !== false
            || in_array($nev, ['sdh_muhely_db_version', 'sdh_muhely_version', 'sdh_muhely_telepitve', self::ZAR], true);
    }

    /** A legutóbbi visszaállítás adatai (a Mentés lapon látszik). @return array<string, mixed> */
    public static function utolso(): array
    {
        $u = get_option(self::UTOLSO, []);

        return is_array($u) ? $u : [];
    }
}
