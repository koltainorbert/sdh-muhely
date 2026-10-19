<?php
/**
 * Online időpontfoglalás (F6, 0.88.0).
 *
 * Nyilvános oldal (`/?sdh_foglalas=1`), amely a cég weboldalára linkként vagy beágyazva tehető ki.
 * Az ügyfél a szabad időpontok közül választ, megadja a nevét, telefonszámát, a készüléket és a hibát;
 * a foglalás eseményként a beállított naptárba kerül (`forras = online`). Az ügyfél e-mailben
 * visszaigazolást kap egy lemondó linkkel, a szerviz e-mailt kap az új foglalásról.
 *
 * Szabad időpont: a nyitvatartási napokon a nyitástól a zárásig, a foglalási hossz lépéseiben; egy
 * időpont addig foglalható, amíg a naptárban az átfedő (időponthoz kötött, nem elmaradt) események száma
 * kisebb, mint az egyszerre fogadható ügyfelek száma. Az egész napos események nem foglalnak időt.
 *
 * Védelem: rejtett csapdamező, legalább 3 másodperc kitöltési idő (aláírt időbélyeg), óránként legfeljebb
 * 5 foglalás egy címről, és a mentés előtt az időpont újraellenőrzése.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Foglalas
{
    public const OPT   = 'sdh_muhely_foglalas';
    public const QV    = 'sdh_foglalas';
    public const HOSSZAK = [15, 20, 30, 45, 60, 90, 120];

    private const NAPOK = [1 => 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap'];
    private const NAPOK_ROVID = [1 => 'H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];
    private const HONAPOK = [1 => 'jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];

    /** Egy címről óránként ennyi foglalás mehet. */
    private const KORLAT = 5;

    public static function init(): void
    {
        add_filter('query_vars', [self::class, 'query_vars']);
        add_action('template_redirect', [self::class, 'fogadas'], 0);
    }

    private static function t(): string
    {
        return SDH_Muhely_Schema::tabla('naptar_esemeny');
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /** @return array<string, mixed> */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        $ido = static function ($e, string $alap): string {
            return is_string($e) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $e) === 1 ? $e : $alap;
        };

        $napok = [];

        foreach ((array) ($m['napok'] ?? [1, 2, 3, 4, 5]) as $n) {
            if ((int) $n >= 1 && (int) $n <= 7) {
                $napok[] = (int) $n;
            }
        }

        sort($napok);

        $nyit = $ido($m['nyit'] ?? null, '09:00');
        $zar  = $ido($m['zar'] ?? null, '17:00');

        if ($zar <= $nyit) {
            $zar = '17:00' > $nyit ? '17:00' : '23:59';
        }

        $hossz = (int) ($m['hossz'] ?? 30);

        $szolg = [];

        foreach (preg_split('/\R/', (string) ($m['szolgaltatasok'] ?? '')) ?: [] as $sor) {
            $sor = trim(sanitize_text_field($sor));

            if ($sor !== '') {
                $szolg[] = mb_substr($sor, 0, 120);
            }
        }

        return [
            'be'             => !empty($m['be']),
            'szemely_id'     => (int) ($m['szemely_id'] ?? 0),
            'kategoria'      => sanitize_key((string) ($m['kategoria'] ?? '')),
            'napok'          => array_values(array_unique($napok)),
            'nyit'           => $nyit,
            'zar'            => $zar,
            'hossz'          => in_array($hossz, self::HOSSZAK, true) ? $hossz : 30,
            'parhuzam'       => max(1, min(10, (int) ($m['parhuzam'] ?? 1))),
            'elore_ora'      => max(0, min(168, (int) ($m['elore_ora'] ?? 2))),
            'max_nap'        => max(1, min(90, (int) ($m['max_nap'] ?? 21))),
            'szolgaltatasok' => array_values(array_unique($szolg)),
            'bevezeto'       => (string) ($m['bevezeto'] ?? ''),
            'email_kotelezo' => !empty($m['email_kotelezo']),
            'adatvedelem'    => esc_url_raw((string) ($m['adatvedelem'] ?? '')),
            'kuldo'          => sanitize_key((string) ($m['kuldo'] ?? '')),
        ];
    }

    /** A cég levelezőfiókjai, amelyekből a visszaigazolás mehet (Levelezés modul). @return array<string, string> */
    public static function kuldo_fiokok(): array
    {
        return class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Modulok::be('levelezes') ? SDH_Muhely_Levelezes::kuldo_fiokok() : [];
    }

    /** A használt küldőfiók: a beállított, különben az első bekötött fiók; '' = a szerver levélküldője. */
    public static function kuldo(): string
    {
        $f = self::kuldo_fiokok();
        $k = self::beallitas()['kuldo'];

        if ($k === 'wp') {
            return '';
        }

        return isset($f[$k]) ? $k : (string) (array_key_first($f) ?? '');
    }

    /**
     * Levél a foglalás ügyében: a cég levelezőfiókjából (SMTP), ha van, különben a szerver levélküldőjével.
     * Az eredmény a Beállításokban látszik. Siker: true, hiba: az oka.
     *
     * @return true|string
     */
    public static function level(string $cim, string $targy, string $torzs, string $valasz = '')
    {
        $fiok = self::kuldo();

        if ($fiok !== '') {
            $r = SDH_Muhely_Levelezes::egyszeru_kuldes($fiok, [$cim], $targy, $torzs);
        } else {
            $hiba    = '';
            $figyelo = static function ($h) use (&$hiba): void {
                $hiba = is_wp_error($h) ? $h->get_error_message() : '';
            };
            add_action('wp_mail_failed', $figyelo);
            $ok = wp_mail($cim, $targy, $torzs, $valasz !== '' && is_email($valasz) ? ['Reply-To: ' . $valasz] : []);
            remove_action('wp_mail_failed', $figyelo);
            $r = $ok ? true : ($hiba !== '' ? $hiba : 'a szerver levélküldője nem fogadta el (nincs beállítva a levélküldés?).');
        }

        update_option(self::OPT . '_level', ['ido' => time(), 'cim' => $cim, 'ok' => $r === true, 'hiba' => $r === true ? '' : (string) $r, 'fiok' => $fiok], false);

        return $r;
    }

    /** A naptár, ahova a foglalás kerül: a beállított, különben az első aktív. */
    public static function szemely(): ?array
    {
        $sz = SDH_Muhely_Naptar::szemelyek();
        $id = self::beallitas()['szemely_id'];

        if (isset($sz[$id])) {
            return $sz[$id];
        }

        return $sz === [] ? null : reset($sz);
    }

    /** A nyilvános foglalóoldal címe. */
    public static function url(array $parameterek = []): string
    {
        return add_query_arg([self::QV => '1'] + $parameterek, home_url('/'));
    }

    /** A weboldalba illeszthető kód. */
    public static function beagyazo(): string
    {
        return '<iframe src="' . esc_url(self::url()) . '" title="Időpontfoglalás" style="width:100%;min-height:760px;border:0" loading="lazy"></iframe>';
    }

    /* =================================================================
     * Szabad időpontok
     * ============================================================== */

    private static function most(?string $most): DateTimeImmutable
    {
        return new DateTimeImmutable($most ?? current_time('mysql'), wp_timezone());
    }

    /**
     * A naptár időponthoz kötött eseményeinek alkalmai a [$tol, $ig) időszakban.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function foglalt_savok(int $szemely_id, string $tol, string $ig): array
    {
        global $wpdb;

        $sorok = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::t() . " WHERE torolve = 0 AND allapot <> 'elmaradt' AND egesz_nap = 0
               AND (szemely_id = %d OR resztvevok <> '')
               AND ((ismetles = '' AND kezdet < %s AND veg > %s) OR (ismetles <> '' AND kezdet < %s))",
            $szemely_id,
            $ig,
            $tol,
            $ig
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $savok = [];

        foreach (is_array($sorok) ? $sorok : [] as $e) {
            $resztvevo = in_array((string) $szemely_id, array_map('trim', explode(',', (string) $e->resztvevok)), true);

            if ((int) $e->szemely_id !== $szemely_id && !$resztvevo) {
                continue;
            }

            foreach (SDH_Muhely_Naptar::alkalmak($e, $tol, $ig) as $a) {
                $savok[] = $a;
            }
        }

        return $savok;
    }

    /**
     * A szabad időpontok napokra bontva (csak a nem üres napok).
     *
     * @return array<string, array<int, string>> Y-m-d => [H:i, …]
     */
    public static function szabad(?string $most = null): array
    {
        $b  = self::beallitas();
        $sz = self::szemely();

        if ($sz === null) {
            return [];
        }

        $most_dt = self::most($most);
        $elso    = $most_dt->modify('+' . $b['elore_ora'] . ' hours');
        $ma      = $most_dt->setTime(0, 0);
        $utolso  = $ma->modify('+' . $b['max_nap'] . ' days');
        $savok   = self::foglalt_savok((int) $sz['id'], $ma->format('Y-m-d H:i:s'), $utolso->modify('+1 day')->format('Y-m-d H:i:s'));

        [$no, $np] = array_map('intval', explode(':', $b['nyit']));
        [$zo, $zp] = array_map('intval', explode(':', $b['zar']));

        $ki = [];

        for ($nap = $ma; $nap <= $utolso; $nap = $nap->modify('+1 day')) {
            if (!in_array((int) $nap->format('N'), $b['napok'], true)) {
                continue;
            }

            $zaras = $nap->setTime($zo, $zp);
            $idok  = [];

            for ($k = $nap->setTime($no, $np); $k->modify('+' . $b['hossz'] . ' minutes') <= $zaras; $k = $k->modify('+' . $b['hossz'] . ' minutes')) {
                if ($k < $elso) {
                    continue;
                }

                $kezd = $k->format('Y-m-d H:i:s');
                $veg  = $k->modify('+' . $b['hossz'] . ' minutes')->format('Y-m-d H:i:s');
                $db   = 0;

                foreach ($savok as [$sk, $sv]) {
                    if ($sk < $veg && $sv > $kezd) {
                        $db++;
                    }
                }

                if ($db < $b['parhuzam']) {
                    $idok[] = $k->format('H:i');
                }
            }

            if ($idok !== []) {
                $ki[$nap->format('Y-m-d')] = $idok;
            }
        }

        return $ki;
    }

    /** Szabad-e még az időpont. */
    public static function szabad_e(string $nap, string $ido, ?string $most = null): bool
    {
        return in_array($ido, self::szabad($most)[$nap] ?? [], true);
    }

    /** „2026. okt. 20. (kedd)" */
    public static function nap_nev(string $nap): string
    {
        $d = new DateTimeImmutable(substr($nap, 0, 10), wp_timezone());

        return $d->format('Y. ') . self::HONAPOK[(int) $d->format('n')] . ' ' . $d->format('j.') . ' (' . self::NAPOK[(int) $d->format('N')] . ')';
    }

    /** „2026. okt. 20. (kedd) 09:30" */
    public static function mikor(string $kezdet): string
    {
        return self::nap_nev($kezdet) . ' ' . substr($kezdet, 11, 5);
    }

    /**
     * A választott időpont: az űrlap `idopont` mezőjéből („Y-m-d H:i") vagy a `nap` + `ido` mezőkből.
     *
     * @param array<string, mixed> $be
     * @return array{0: string, 1: string}
     */
    public static function valasztott(array $be): array
    {
        $i = isset($be['idopont']) && is_scalar($be['idopont']) ? trim((string) $be['idopont']) : '';

        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $i, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [
            isset($be['nap']) && is_scalar($be['nap']) ? substr(trim((string) $be['nap']), 0, 10) : '',
            isset($be['ido']) && is_scalar($be['ido']) ? substr(trim((string) $be['ido']), 0, 5) : '',
        ];
    }

    /* =================================================================
     * Foglalás és lemondás
     * ============================================================== */

    /** Aláírt időbélyeg az űrlapba (a túl gyors, gépi kitöltés ellen). */
    public static function ido_jel(?int $ido = null): string
    {
        $ido = $ido ?? time();

        return $ido . '.' . substr(wp_hash('sdh_foglalas|' . $ido), 0, 16);
    }

    private static function ido_jel_jo(string $jel): bool
    {
        $r = explode('.', $jel);

        if (count($r) !== 2 || !ctype_digit($r[0]) || !hash_equals(substr(wp_hash('sdh_foglalas|' . $r[0]), 0, 16), $r[1])) {
            return false;
        }

        $kor = time() - (int) $r[0];

        return $kor >= 3 && $kor <= 2 * HOUR_IN_SECONDS;
    }

    private static function cim_kulcs(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';

        return 'sdh_fogl_' . substr(wp_hash('ip|' . $ip), 0, 20);
    }

    /**
     * Új foglalás. Az esemény azonosítóját adja, hibánál a hibaüzenetet.
     *
     * @param array<string, mixed> $be
     * @param bool $vedelem A csapdamező, az időbélyeg és a címenkénti korlát ellenőrzése (a nyilvános oldalról).
     * @return int|string
     */
    public static function foglal(array $be, ?string $most = null, bool $vedelem = true)
    {
        global $wpdb;

        $b = self::beallitas();

        if (!$b['be'] || SDH_Muhely_Modulok::ki(SDH_Muhely_Naptar::KULCS)) {
            return 'Az online időpontfoglalás jelenleg nem elérhető.';
        }

        $sz = self::szemely();

        if ($sz === null) {
            return 'Az online időpontfoglalás jelenleg nem elérhető.';
        }

        $s = static fn (string $k, int $max): string => isset($be[$k]) && is_scalar($be[$k]) ? mb_substr(trim(sanitize_text_field((string) $be[$k])), 0, $max) : '';

        if ($vedelem) {
            if ($s('weboldal_cim', 200) !== '') {
                return 'A foglalás nem sikerült. Kérjük, próbálja újra.';
            }

            if (!self::ido_jel_jo($s('t', 40))) {
                return 'Az űrlap lejárt vagy túl gyorsan küldték el. Kérjük, töltse ki újra.';
            }

            if ((int) get_transient(self::cim_kulcs()) >= self::KORLAT) {
                return 'Túl sok foglalás érkezett erről a címről. Kérjük, hívjon minket telefonon.';
            }
        }

        $nev   = $s('nev', 120);
        $tel   = $s('telefon', 40);
        $email = sanitize_email($s('email', 190));
        [$nap, $ido] = self::valasztott($be);

        if ($nev === '') {
            return 'Adja meg a nevét.';
        }

        if (strlen((string) preg_replace('/\D/', '', $tel)) < 7) {
            return 'Adja meg a telefonszámát.';
        }

        if ($email !== '' && !is_email($email)) {
            return 'Az e-mail-cím nem jó.';
        }

        if ($email === '' && $b['email_kotelezo']) {
            return 'Adja meg az e-mail-címét (ide küldjük a visszaigazolást).';
        }

        if (empty($be['hozzajarul'])) {
            return 'A foglaláshoz fogadja el az adatkezelést.';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $nap) !== 1 || preg_match('/^\d{2}:\d{2}$/', $ido) !== 1) {
            return 'Válasszon időpontot.';
        }

        if (!self::szabad_e($nap, $ido, $most)) {
            return 'Ezt az időpontot közben lefoglalták, vagy már nem foglalható. Kérjük, válasszon másikat.';
        }

        $szolg  = $s('szolgaltatas', 120);
        $szolg  = in_array($szolg, $b['szolgaltatasok'], true) ? $szolg : '';
        $eszkoz = $s('eszkoz', 120);
        $hiba   = isset($be['hiba']) && is_scalar($be['hiba']) ? mb_substr(trim(sanitize_textarea_field((string) $be['hiba'])), 0, 1500) : '';

        $kezd = new DateTimeImmutable($nap . ' ' . $ido . ':00', wp_timezone());
        $veg  = $kezd->modify('+' . $b['hossz'] . ' minutes');

        // Meglévő ügyfél a telefonszám alapján (új ügyfelet nem hozunk létre: azt a felvételkor rögzítik).
        $ugyfel_id = 0;
        $norm      = SDH_Muhely_Rma::telefon_norm($tel);

        if ($norm !== '') {
            // A szóközt, kötőjelet, perjelet a keresés előtt kivesszük („+36 30 555 8080" is egyezzen).
            $tiszta   = static fn (string $o): string => "REPLACE(REPLACE(REPLACE({$o}, ' ', ''), '-', ''), '/', '')";
            $minta    = '%' . $wpdb->esc_like(substr($norm, -7));
            $jeloltek = $wpdb->get_results($wpdb->prepare(
                'SELECT id, telefon, telefon2 FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE ' . $tiszta('telefon') . ' LIKE %s OR ' . $tiszta('telefon2') . ' LIKE %s LIMIT 20',
                $minta,
                $minta
            )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            foreach (is_array($jeloltek) ? $jeloltek : [] as $j) {
                if (SDH_Muhely_Rma::telefon_norm((string) $j->telefon) === $norm || SDH_Muhely_Rma::telefon_norm((string) $j->telefon2) === $norm) {
                    $ugyfel_id = (int) $j->id;
                    break;
                }
            }
        }

        $leiras = array_filter([
            'Online foglalás',
            'Név: ' . $nev,
            'Telefon: ' . $tel,
            $email !== '' ? 'E-mail: ' . $email : '',
            $szolg !== '' ? 'Szolgáltatás: ' . $szolg : '',
            $eszkoz !== '' ? 'Készülék: ' . $eszkoz : '',
            $hiba !== '' ? "Hiba:\n" . $hiba : '',
        ]);

        $nb = SDH_Muhely_Naptar::beallitas();

        $id = SDH_Muhely_Naptar::ment(0, [
            'cim'          => mb_substr($nev . ($eszkoz !== '' ? ' – ' . $eszkoz : '') . ' (online foglalás)', 0, 190),
            'szemely_id'   => (int) $sz['id'],
            'kezdet_datum' => $nap,
            'veg_datum'    => $veg->format('Y-m-d'),
            'kezdet_ora'   => $kezd->format('H'),
            'kezdet_perc'  => $kezd->format('i'),
            'veg_ora'      => $veg->format('H'),
            'veg_perc'     => $veg->format('i'),
            'kategoria'    => $b['kategoria'],
            'leiras'       => implode("\n", $leiras),
            'emlekeztetok' => $nb['alap_emlekezteto'],
            'ertesites'    => 1,
            'email'        => 1,
            'ugyfel_id'    => $ugyfel_id,
        ]);

        if (!is_int($id)) {
            return 'A foglalás nem sikerült. Kérjük, hívjon minket telefonon.';
        }

        $kulcs = strtolower(wp_generate_password(24, false, false));

        $wpdb->update(self::t(), [
            'forras'          => 'online',
            'foglalas_kulcs'  => $kulcs,
            'foglalo_email'   => $email,
            'foglalo_telefon' => $tel,
        ], ['id' => $id]);

        if ($vedelem) {
            set_transient(self::cim_kulcs(), (int) get_transient(self::cim_kulcs()) + 1, HOUR_IN_SECONDS);
        }

        $e = SDH_Muhely_Naptar::esemeny($id);

        if ($e !== null) {
            self::level_ugyfelnek($e, $nev);
            self::level_szerviznek($e, $sz, 'Új online foglalás');
        }

        return $id;
    }

    /** A foglalás a lemondó kulcs alapján. */
    public static function kulccsal(string $kulcs): ?object
    {
        global $wpdb;

        $kulcs = preg_replace('/[^a-z0-9]/', '', strtolower($kulcs)) ?? '';

        if (strlen($kulcs) < 16) {
            return null;
        }

        $e = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t() . " WHERE foglalas_kulcs = %s AND forras = 'online' AND torolve = 0", $kulcs));

        return $e ?: null;
    }

    /** Lemondható-e még (nem múlt el, nem mondták le). */
    public static function lemondhato(object $e, ?string $most = null): bool
    {
        return (string) $e->allapot !== 'elmaradt' && (string) $e->kezdet > self::most($most)->format('Y-m-d H:i:s');
    }

    /** Lemondás az ügyfél linkjéről: az esemény „Elmaradt" lesz, a szerviz e-mailt kap. */
    public static function lemond(string $kulcs, ?string $most = null): bool
    {
        global $wpdb;

        $e = self::kulccsal($kulcs);

        if ($e === null || !self::lemondhato($e, $most)) {
            return false;
        }

        $wpdb->update(self::t(), [
            'allapot'   => 'elmaradt',
            'leiras'    => trim((string) $e->leiras) . "\n\nAz ügyfél online lemondta: " . current_time('Y-m-d H:i'),
            'modositva' => current_time('mysql'),
        ], ['id' => (int) $e->id]);

        $sz = SDH_Muhely_Naptar::szemelyek(true)[(int) $e->szemely_id] ?? null;

        if ($sz !== null) {
            self::level_szerviznek($e, $sz, 'Lemondott online foglalás');
        }

        return true;
    }

    /* =================================================================
     * Levelek
     * ============================================================== */

    private static function elerhetoseg(): array
    {
        $b = SDH_Muhely_Rma::beallitas();

        return [
            'nev'  => (string) $b['szerviz_nev'],
            'sor'  => array_values(array_filter([(string) $b['cim'], (string) $b['telefon'], (string) $b['email'], (string) $b['weboldal']])),
            'valasz' => sanitize_email((string) $b['ertesites_email']),
        ];
    }

    private static function level_ugyfelnek(object $e, string $nev): bool
    {
        if (!is_email((string) $e->foglalo_email)) {
            return false;
        }

        $el    = self::elerhetoseg();
        $torzs = 'Kedves ' . $nev . "!\n\n"
            . "Köszönjük a foglalását. Az időpont:\n\n"
            . self::mikor((string) $e->kezdet) . "\n\n"
            . "Ha mégsem tud jönni, itt lemondhatja:\n"
            . self::url(['k' => (string) $e->foglalas_kulcs]) . "\n\n"
            . $el['nev']
            . ($el['sor'] !== [] ? "\n" . implode("\n", $el['sor']) : '');

        $ok = self::level((string) $e->foglalo_email, $el['nev'] . ' – időpontfoglalás visszaigazolása', $torzs, $el['valasz']) === true;
        set_transient('sdh_fogl_level_' . (string) $e->foglalas_kulcs, $ok ? 1 : 0, DAY_IN_SECONDS);

        return $ok;
    }

    private static function level_szerviznek(object $e, array $sz, string $mi): bool
    {
        $cim = SDH_Muhely_Naptar::szemely_email($sz);

        if ($cim === '') {
            $cim = self::elerhetoseg()['valasz'];
        }

        if (!is_email($cim)) {
            return false;
        }

        $rendszer = class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::rendszer_nev() : 'Műhely';
        $torzs    = $mi . "\n\n" . 'Időpont: ' . self::mikor((string) $e->kezdet) . "\n" . 'Naptár: ' . $sz['nev'] . "\n\n"
            . (string) $e->leiras . "\n\n" . 'A naptárban: ' . SDH_Muhely_Modulok::frontend_url(SDH_Muhely_Naptar::KULCS) . "\n";
        return self::level($cim, '[' . $rendszer . '] ' . $mi . ' – ' . self::mikor((string) $e->kezdet), $torzs, is_email((string) $e->foglalo_email) ? (string) $e->foglalo_email : '') === true;
    }

    /* =================================================================
     * Nyilvános oldal
     * ============================================================== */

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function query_vars(array $vars): array
    {
        $vars[] = self::QV;

        return $vars;
    }

    public static function fogadas(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $qv = get_query_var(self::QV);

        if (($qv === '' || $qv === null) && isset($_GET[self::QV])) {
            $qv = sanitize_key(wp_unslash($_GET[self::QV]));
        }

        if ($qv === '' || $qv === null) {
            return;
        }

        SDH_Muhely_Schema::frissites_ha_kell();

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);

        SDH_Muhely_Rma_Oldal::nyelv_kenyszerit('hu');

        $post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
        $mit  = $post && isset($_POST['sdh_fogl_muvelet']) ? sanitize_key(wp_unslash($_POST['sdh_fogl_muvelet'])) : '';
        $k    = isset($_GET['k']) ? sanitize_key(wp_unslash($_GET['k'])) : '';

        if ($mit === 'foglal') {
            $r = self::foglal(wp_unslash($_POST));

            if (is_int($r)) {
                $e = SDH_Muhely_Naptar::esemeny($r);
                wp_safe_redirect(self::url(['kesz' => $e ? (string) $e->foglalas_kulcs : '']));
                exit;
            }

            status_header(200);
            self::oldal($r, wp_unslash($_POST));
            exit;
        }

        if ($mit === 'lemond' && $k !== '') {
            $ok = self::lemond($k);
            wp_safe_redirect(self::url(['k' => $k, 'lemondva' => $ok ? '1' : '0']));
            exit;
        }

        status_header(200);

        if ($k !== '') {
            self::lemondo_oldal($k, isset($_GET['lemondva']) ? (string) sanitize_key(wp_unslash($_GET['lemondva'])) : '');
            exit;
        }

        if (isset($_GET['kesz'])) {
            self::kesz_oldal(sanitize_key(wp_unslash($_GET['kesz'])));
            exit;
        }

        self::oldal();
        exit;
        // phpcs:enable
    }

    /** Egy kis ablak: ikon, cím, szöveg, alatta tetszőleges tartalom. */
    private static function kis_ablak(string $cim, string $ikon, string $szoveg, ?callable $tovabb = null): void
    {
        SDH_Muhely_Rma_Oldal::keret($cim, 'rma--zar rma--foglalas', static function () use ($cim, $ikon, $szoveg, $tovabb): void {
            ?>
            <div class="rma-ablak rma-ablak--kicsi">
                <?php SDH_Muhely_Rma_Oldal::fejlec(null, false, false, 'Időpontfoglalás'); ?>
                <div class="rma-zar">
                    <div class="rma-zar__ikon"><?php echo SDH_Muhely_Rma_Oldal::ikon($ikon); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                    <h1><?php echo esc_html($cim); ?></h1>
                    <?php if ($szoveg !== '') : ?><p><?php echo esc_html($szoveg); ?></p><?php endif; ?>
                    <?php if ($tovabb) { $tovabb(); } ?>
                    <?php echo SDH_Muhely_Rma_Oldal::gyorsgombok(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
            </div>
            <?php
        });
    }

    private static function kesz_oldal(string $kulcs): void
    {
        $e = self::kulccsal($kulcs);

        if ($e === null) {
            self::kis_ablak('Időpontfoglalás', 'info', 'Ez a foglalás nem található.');

            return;
        }

        self::kis_ablak('Köszönjük, várjuk!', 'ora', 'Az időpontját rögzítettük:', static function () use ($e): void {
            ?>
            <p class="rma-fogl-ido"><b><?php echo esc_html(self::mikor((string) $e->kezdet)); ?></b></p>
            <?php $sdh_elkuldve = get_transient('sdh_fogl_level_' . (string) $e->foglalas_kulcs); ?>
            <p><?php echo esc_html(!is_email((string) $e->foglalo_email) ? 'Ha mégsem tud jönni, kérjük, jelezze telefonon.' : ((string) $sdh_elkuldve === '0' ? 'A visszaigazoló e-mailt most nem sikerült elküldeni – az időpontja ettől rögzítve van. Lemondáshoz hívjon minket.' : 'A visszaigazolást e-mailben is elküldtük (' . (string) $e->foglalo_email . '), benne a lemondás linkjével.')); ?></p>
            <?php
        });
    }

    private static function lemondo_oldal(string $kulcs, string $lemondva): void
    {
        $e = self::kulccsal($kulcs);

        if ($e === null) {
            self::kis_ablak('Időpontfoglalás', 'info', 'Ez a foglalás nem található.');

            return;
        }

        if ((string) $e->allapot === 'elmaradt') {
            self::kis_ablak('Foglalás lemondva', 'info', 'A(z) ' . self::mikor((string) $e->kezdet) . ' időpontot lemondta. Új időpontot itt foglalhat.', static function (): void {
                ?>
                <a class="sdh-gomb sdh-gomb--elsodleges rma-gomb-nagy" href="<?php echo esc_url(self::url()); ?>">Új időpont foglalása</a>
                <?php
            });

            return;
        }

        if (!self::lemondhato($e)) {
            self::kis_ablak('Időpontfoglalás', 'info', 'Ez az időpont már elmúlt, nem mondható le.');

            return;
        }

        self::kis_ablak('Foglalás lemondása', 'ora', 'Az Ön időpontja:', static function () use ($e, $lemondva): void {
            ?>
            <p class="rma-fogl-ido"><b><?php echo esc_html(self::mikor((string) $e->kezdet)); ?></b></p>
            <?php if ($lemondva === '0') : ?><div class="rma-jelzes rma-jelzes--hiba">A lemondás nem sikerült.</div><?php endif; ?>
            <form method="post" class="rma-urlap">
                <input type="hidden" name="sdh_fogl_muvelet" value="lemond">
                <button type="submit" class="sdh-gomb rma-gomb-nagy">Lemondom az időpontot</button>
            </form>
            <?php
        });
    }

    /**
     * A foglalóűrlap.
     *
     * @param array<string, mixed> $be Hibánál a beküldött adatok.
     */
    public static function oldal(string $hiba = '', array $be = []): void
    {
        $b = self::beallitas();

        if (!$b['be'] || SDH_Muhely_Modulok::ki(SDH_Muhely_Naptar::KULCS) || self::szemely() === null) {
            self::kis_ablak('Időpontfoglalás', 'info', 'Az online időpontfoglalás jelenleg nem elérhető. Kérjük, hívjon minket telefonon.');

            return;
        }

        $szabad = self::szabad();
        $v      = static fn (string $k): string => isset($be[$k]) && is_scalar($be[$k]) ? (string) $be[$k] : '';
        [$valasz_nap, $valasz_ido] = self::valasztott($be);
        $valasztott = $valasz_nap . ' ' . $valasz_ido;
        $valasz_nap = isset($szabad[$valasz_nap]) ? $valasz_nap : (string) array_key_first($szabad);

        SDH_Muhely_Rma_Oldal::keret('Időpontfoglalás', 'rma--zar rma--foglalas', static function () use ($b, $szabad, $hiba, $v, $valasz_nap, $valasztott): void {
            ?>
            <div class="rma-ablak rma-ablak--foglalas">
                <?php SDH_Muhely_Rma_Oldal::fejlec(null, false, false, 'Időpontfoglalás'); ?>
                <div class="rma-zar rma-fogl-zar">
                    <div class="rma-fogl-fej">
                        <h1>Időpontfoglalás</h1>
                        <?php if ($b['bevezeto'] !== '') : ?><p><?php echo esc_html($b['bevezeto']); ?></p><?php endif; ?>
                    </div>
                    <?php if ($hiba !== '') : ?><div class="rma-jelzes rma-jelzes--hiba" role="alert"><?php echo esc_html($hiba); ?></div><?php endif; ?>

                    <?php if ($szabad === []) : ?>
                        <p>Jelenleg nincs szabad időpont. Kérjük, hívjon minket telefonon.</p>
                    <?php else : ?>
                        <form method="post" class="rma-urlap rma-fogl" data-rma-foglalas autocomplete="on">
                            <input type="hidden" name="sdh_fogl_muvelet" value="foglal">
                            <input type="hidden" name="t" value="<?php echo esc_attr(self::ido_jel()); ?>">
                            <div class="rma-fogl__csapda" aria-hidden="true">
                                <label>Weboldal <input type="text" name="weboldal_cim" tabindex="-1" autocomplete="off" value=""></label>
                            </div>

                            <section class="rma-fogl__panel rma-fogl__panel--ido" aria-label="Időpont">
                                <div class="rma-fogl__lepes"><b>1</b>Válasszon napot</div>
                                <div class="rma-fogl__napok" role="tablist">
                                    <?php foreach (array_keys($szabad) as $nap) :
                                        $d = new DateTimeImmutable($nap, wp_timezone());
                                        ?>
                                        <button type="button" class="rma-fogl__nap<?php echo $nap === $valasz_nap ? ' is-aktiv' : ''; ?>" data-rma-fogl-nap="<?php echo esc_attr($nap); ?>" aria-pressed="<?php echo $nap === $valasz_nap ? 'true' : 'false'; ?>">
                                            <small><?php echo esc_html(self::NAPOK_ROVID[(int) $d->format('N')]); ?></small>
                                            <b><?php echo esc_html($d->format('j')); ?></b>
                                            <small><?php echo esc_html(self::HONAPOK[(int) $d->format('n')]); ?></small>
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <div class="rma-fogl__lepes"><b>2</b>Válasszon időpontot</div>
                                <?php foreach ($szabad as $nap => $idok) : ?>
                                    <fieldset class="rma-fogl__idok" data-rma-fogl-idok="<?php echo esc_attr($nap); ?>"<?php echo $nap !== $valasz_nap ? ' data-rejtve' : ''; ?>>
                                        <legend><?php echo esc_html(self::nap_nev($nap)); ?></legend>
                                        <?php foreach ($idok as $ido) : ?>
                                            <label class="rma-fogl__ido">
                                                <input type="radio" name="idopont" value="<?php echo esc_attr($nap . ' ' . $ido); ?>"<?php checked($valasztott, $nap . ' ' . $ido); ?>>
                                                <span><?php echo esc_html($ido); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </fieldset>
                                <?php endforeach; ?>
                            </section>

                            <section class="rma-fogl__panel rma-fogl__panel--adat" aria-label="Adatai">
                                <div class="rma-fogl__lepes"><b>3</b>Adatai</div>
                                <div class="rma-fogl__mezok">
                                    <label class="rma-mezo"><span>Név *</span><input type="text" name="nev" autocomplete="name" required maxlength="120" value="<?php echo esc_attr($v('nev')); ?>"></label>
                                    <label class="rma-mezo"><span>Telefonszám *</span><input type="tel" name="telefon" inputmode="tel" autocomplete="tel" required maxlength="40" value="<?php echo esc_attr($v('telefon')); ?>"></label>
                                    <label class="rma-mezo"><span>E-mail<?php echo $b['email_kotelezo'] ? ' *' : ' (ide jön a visszaigazolás)'; ?></span><input type="email" name="email" autocomplete="email" maxlength="190"<?php echo $b['email_kotelezo'] ? ' required' : ''; ?> value="<?php echo esc_attr($v('email')); ?>"></label>
                                    <?php if ($b['szolgaltatasok'] !== []) : ?>
                                        <label class="rma-mezo"><span>Mivel kapcsolatban?</span>
                                            <select name="szolgaltatas">
                                                <option value="">– válasszon –</option>
                                                <?php foreach ($b['szolgaltatasok'] as $sz) : ?>
                                                    <option value="<?php echo esc_attr($sz); ?>"<?php selected($v('szolgaltatas'), $sz); ?>><?php echo esc_html($sz); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    <?php endif; ?>
                                    <label class="rma-mezo<?php echo $b['szolgaltatasok'] === [] ? '' : ' rma-mezo--szeles'; ?>"><span>Készülék (márka, típus)</span><input type="text" name="eszkoz" maxlength="120" value="<?php echo esc_attr($v('eszkoz')); ?>"></label>
                                    <label class="rma-mezo rma-mezo--szeles"><span>A hiba röviden</span><textarea name="hiba" rows="2" maxlength="1500"><?php echo esc_textarea($v('hiba')); ?></textarea></label>
                                </div>
                                <label class="rma-fogl__jelolo">
                                    <input type="checkbox" name="hozzajarul" value="1" required<?php checked($v('hozzajarul'), '1'); ?>>
                                    <span>Hozzájárulok, hogy az adataimat az időpont egyeztetéséhez kezeljék.<?php if ($b['adatvedelem'] !== '') : ?> <a href="<?php echo esc_url($b['adatvedelem']); ?>" target="_blank" rel="noopener">Adatkezelési tájékoztató</a><?php endif; ?></span>
                                </label>
                                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges rma-gomb-nagy">Időpont foglalása</button>
                            </section>
                        </form>
                    <?php endif; ?>

                    <?php echo SDH_Muhely_Rma_Oldal::gyorsgombok(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
            </div>
            <?php
        });
    }

    /* =================================================================
     * Beállítások lapfül
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $b   = self::beallitas();
        $szk = SDH_Muhely_Naptar::szemelyek();
        $kat = SDH_Muhely_Naptar::beallitas()['kategoriak'];
        $sz  = self::szemely();

        $ido = static function (string $nev, string $ertek, string $cimke): void {
            [$o, $p] = array_map('intval', explode(':', $ertek));
            echo '<span class="sdh-np-ido">';
            printf('<select name="foglalas[%1$s_ora]" aria-label="%2$s – óra">', esc_attr($nev), esc_attr($cimke));

            for ($i = 0; $i < 24; $i++) {
                printf('<option value="%1$02d"%2$s>%1$02d</option>', $i, selected($o, $i, false));
            }

            printf('</select><b aria-hidden="true">:</b><select name="foglalas[%1$s_perc]" aria-label="%2$s – perc">', esc_attr($nev), esc_attr($cimke));

            for ($i = 0; $i < 60; $i += 5) {
                printf('<option value="%1$02d"%2$s>%1$02d</option>', $i, selected($p - $p % 5, $i, false));
            }

            echo '</select></span>';
        };

        ?>
        <div class="sdh-doboz" id="foglalas">
            <h2 class="sdh-doboz__cim">Online időpontfoglalás</h2>
            <p class="sdh-sugo">
                Az ügyfelek a weboldalatokról foglalhatnak időpontot a szabad helyekre. A foglalás a kiválasztott naptárba
                kerül, az ügyfél e-mailben visszaigazolást kap (lemondó linkkel), a naptár gazdája e-mailt kap az új foglalásról.
                Foglaltnak számít minden időponthoz kötött esemény az adott naptárban; az egész napos esemény nem foglal időt.
            </p>
            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="checkbox" name="foglalas[be]" id="foglalas_be" value="1" <?php checked($b['be']); ?>>
                    <label for="foglalas_be">Online időpontfoglalás bekapcsolva</label>
                </div>
                <div class="sdh-mezo">
                    <label for="foglalas_szemely">Melyik naptárba kerüljön</label>
                    <select name="foglalas[szemely_id]" id="foglalas_szemely">
                        <?php foreach ($szk as $s) : ?>
                            <option value="<?php echo (int) $s['id']; ?>"<?php selected($sz !== null && (int) $sz['id'] === (int) $s['id']); ?>><?php echo esc_html($s['nev']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="foglalas_kategoria">Kategória</label>
                    <select name="foglalas[kategoria]" id="foglalas_kategoria">
                        <option value="">– nincs –</option>
                        <?php foreach ($kat as $kulcs => $k) : ?>
                            <option value="<?php echo esc_attr($kulcs); ?>"<?php selected($b['kategoria'], $kulcs); ?>><?php echo esc_html($k['nev']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <span class="sdh-mezo__cimke">Foglalható napok</span>
                    <div class="sdh-fogl-napok">
                        <?php foreach (self::NAPOK as $n => $nev) : ?>
                            <label><input type="checkbox" name="foglalas[napok][]" value="<?php echo (int) $n; ?>" <?php checked(in_array($n, $b['napok'], true)); ?>> <?php echo esc_html($nev); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="sdh-mezo">
                    <span class="sdh-mezo__cimke">Első időpont</span>
                    <?php $ido('nyit', $b['nyit'], 'Első időpont'); ?>
                </div>
                <div class="sdh-mezo">
                    <span class="sdh-mezo__cimke">Zárás (eddig tarthat az utolsó)</span>
                    <?php $ido('zar', $b['zar'], 'Zárás'); ?>
                </div>
                <div class="sdh-mezo">
                    <label for="foglalas_hossz">Egy foglalás hossza</label>
                    <select name="foglalas[hossz]" id="foglalas_hossz">
                        <?php foreach (self::HOSSZAK as $h) : ?>
                            <option value="<?php echo (int) $h; ?>"<?php selected($b['hossz'], $h); ?>><?php echo (int) $h; ?> perc</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="foglalas_parhuzam">Egyszerre fogadható ügyfél</label>
                    <input type="number" name="foglalas[parhuzam]" id="foglalas_parhuzam" min="1" max="10" step="1" value="<?php echo (int) $b['parhuzam']; ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="foglalas_elore">Legalább ennyi órával előre</label>
                    <input type="number" name="foglalas[elore_ora]" id="foglalas_elore" min="0" max="168" step="1" value="<?php echo (int) $b['elore_ora']; ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="foglalas_max">Legfeljebb ennyi napra előre</label>
                    <input type="number" name="foglalas[max_nap]" id="foglalas_max" min="1" max="90" step="1" value="<?php echo (int) $b['max_nap']; ?>">
                </div>
                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="checkbox" name="foglalas[email_kotelezo]" id="foglalas_email" value="1" <?php checked($b['email_kotelezo']); ?>>
                    <label for="foglalas_email">Az e-mail-cím kötelező (különben a telefonszám elég)</label>
                </div>
                <?php $sdh_fiokok = self::kuldo_fiokok(); $sdh_lev = get_option(self::OPT . '_level'); ?>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="foglalas_kuldo">A visszaigazoló e-mail innen megy</label>
                    <select name="foglalas[kuldo]" id="foglalas_kuldo">
                        <?php foreach ($sdh_fiokok as $k => $nev) : ?>
                            <option value="<?php echo esc_attr($k); ?>"<?php selected(self::kuldo(), $k); ?>><?php echo esc_html($nev); ?></option>
                        <?php endforeach; ?>
                        <option value="wp"<?php selected(self::kuldo(), ''); ?>>A szerver levélküldője<?php echo $sdh_fiokok === [] ? '' : ' (nem ajánlott)'; ?></option>
                    </select>
                    <span class="sdh-mezo__sugo">
                        <?php if ($sdh_fiokok === []) : ?>Nincs bekötött levelezőfiók (Beállítások › Levelezés): a szerver levélküldője csak akkor kézbesít, ha a szerveren be van állítva a levélküldés.<?php else : ?>A cég saját postafiókjából megy, így biztosan megérkezik, és a válasz is oda jön.<?php endif; ?>
                        <?php if (is_array($sdh_lev) && !empty($sdh_lev['ido'])) : ?><br>Utolsó levél: <?php echo esc_html(wp_date('Y.m.d. H:i', (int) $sdh_lev['ido']) . ' → ' . (string) $sdh_lev['cim'] . ': ' . (!empty($sdh_lev['ok']) ? 'elküldve' : 'NEM ment ki – ' . (string) $sdh_lev['hiba'])); ?><?php endif; ?>
                    </span>
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="foglalas_szolg">Választható témák (soronként egy, üresen nincs választó)</label>
                    <textarea name="foglalas[szolgaltatasok]" id="foglalas_szolg" rows="4"><?php echo esc_textarea(implode("\n", $b['szolgaltatasok'])); ?></textarea>
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="foglalas_bevezeto">Bevezető szöveg az oldal tetején</label>
                    <textarea name="foglalas[bevezeto]" id="foglalas_bevezeto" rows="2"><?php echo esc_textarea($b['bevezeto']); ?></textarea>
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="foglalas_adatv">Adatkezelési tájékoztató címe (nem kötelező)</label>
                    <input type="text" inputmode="url" spellcheck="false" name="foglalas[adatvedelem]" id="foglalas_adatv" value="<?php echo esc_attr($b['adatvedelem']); ?>" placeholder="https://">
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="foglalas_link">A foglalóoldal címe (ezt linkeld a weboldalon)</label>
                    <input type="text" id="foglalas_link" readonly value="<?php echo esc_attr(self::url()); ?>">
                    <span class="sdh-mezo__sugo"><a href="<?php echo esc_url(self::url()); ?>" target="_blank" rel="noopener">Megnyitás új lapon</a></span>
                </div>
                <div class="sdh-mezo sdh-mezo--szeles">
                    <label for="foglalas_kod">Beágyazó kód (a weboldal egy oldalába illesztve ott jelenik meg az űrlap)</label>
                    <input type="text" id="foglalas_kod" readonly value="<?php echo esc_attr(self::beagyazo()); ?>">
                </div>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        $be = isset($_POST['foglalas']) && is_array($_POST['foglalas']) ? wp_unslash($_POST['foglalas']) : [];

        $ido = static fn (string $k): string => sprintf('%02d:%02d', max(0, min(23, (int) ($be[$k . '_ora'] ?? 0))), max(0, min(59, (int) ($be[$k . '_perc'] ?? 0))));

        update_option(self::OPT, [
            'be'             => !empty($be['be']),
            'szemely_id'     => (int) ($be['szemely_id'] ?? 0),
            'kategoria'      => sanitize_key((string) ($be['kategoria'] ?? '')),
            'napok'          => array_map('intval', (array) ($be['napok'] ?? [])),
            'nyit'           => $ido('nyit'),
            'zar'            => $ido('zar'),
            'hossz'          => (int) ($be['hossz'] ?? 30),
            'parhuzam'       => (int) ($be['parhuzam'] ?? 1),
            'elore_ora'      => (int) ($be['elore_ora'] ?? 2),
            'max_nap'        => (int) ($be['max_nap'] ?? 21),
            'szolgaltatasok' => sanitize_textarea_field((string) ($be['szolgaltatasok'] ?? '')),
            'bevezeto'       => mb_substr(sanitize_textarea_field((string) ($be['bevezeto'] ?? '')), 0, 600),
            'email_kotelezo' => !empty($be['email_kotelezo']),
            'adatvedelem'    => esc_url_raw((string) ($be['adatvedelem'] ?? '')),
            'kuldo'          => sanitize_key((string) ($be['kuldo'] ?? '')),
        ], false);
    }
}
