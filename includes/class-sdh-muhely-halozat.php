<?php
/**
 * Netkimaradás: helyi működés (H12, 0.91.0).
 *
 * A belső munka (munkalap, ügyfél, pénztár, nyomtatás, helyi mentés) a helyi szerveren fut, net nélkül is.
 * Ami internetet kér (Számlázz.hu, e-mail, AI, IMEI-lekérdezés, Drive / FTP-másolat, frissítés), az kimaradáskor
 * eddig a saját időkorlátjáig (8–60 mp) várakoztatta a felhasználót. Mostantól:
 *
 * - `online()`: gyors ellenőrzés (TCP-kapcsolat két nyilvános címre, egyenként legfeljebb 1,5 mp), az eredmény
 *   60 másodpercig tárolva – így percenként legfeljebb egyszer fut.
 * - `pre_http_request`: ha tudjuk, hogy nincs internet, a KÜLSŐ kérés azonnal, érthető üzenettel hibát ad
 *   (nem vár). A helyi címek (localhost, 192.168.x.x, 10.x, *.local, saját webhely – pl. a bolti SMS-telefon)
 *   mindig mennek.
 * - A pulzus (30 mp) viszi az állapotot: a fejlécben „Nincs internet” jelzés áll, amíg vissza nem jön.
 * - Az időzített, internetet kérő feladatok (pl. elégedettségi kérdés) ilyenkor kihagyják a kört, és később pótolnak.
 *
 * Teszthez / próbához: az `sdh_muhely_halozat_kenyszer` szűrő ('online' | 'offline') felülírja az ellenőrzést.
 * Vészkapcsoló (ha a tűzfal a próbacímeket tiltja): `define('SDH_MUHELY_HALOZAT_KI', true);` a wp-config.php-ban.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Halozat
{
    private const TAR = 'sdh_muhely_halozat';

    /** Ennyi másodpercig érvényes egy ellenőrzés. */
    public const ERVENY = 60;

    /** @var array{online: bool, ido: int}|null */
    private static ?array $most = null;

    public static function init(): void
    {
        add_filter('pre_http_request', [self::class, 'kerelem_elott'], 5, 3);
        add_filter('sdh_muhely_pulzus', [self::class, 'pulzus'], 30, 1);
    }

    /** Ellenőrzés a hálózaton (TCP-kapcsolat), tárolás nélkül. */
    public static function ellenoriz(): bool
    {
        $k = apply_filters('sdh_muhely_halozat_kenyszer', '');

        if ($k === 'online' || $k === 'offline') {
            return $k === 'online';
        }

        // Ha a szerver csak proxyn át éri el a netet, a proxyt kérdezzük (különben tévesen „nincs net” lenne).
        $celok = defined('WP_PROXY_HOST') && WP_PROXY_HOST
            ? [[(string) WP_PROXY_HOST, defined('WP_PROXY_PORT') ? (int) WP_PROXY_PORT : 8080]]
            : [['1.1.1.1', 443], ['8.8.8.8', 53], ['9.9.9.9', 443]];

        foreach ($celok as [$cim, $port]) {
            $hiba = 0;
            $szoveg = '';
            $s = @fsockopen($cim, $port, $hiba, $szoveg, 1.5); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

            if ($s) {
                fclose($s); // phpcs:ignore WordPress.WP.AlternativeFunctions

                return true;
            }
        }

        return false;
    }

    /**
     * Van-e internet (60 mp-ig tárolt eredmény).
     *
     * @return array{online: bool, ido: int}
     */
    public static function allapot(bool $friss = false): array
    {
        $k = apply_filters('sdh_muhely_halozat_kenyszer', '');

        if ($k === 'online' || $k === 'offline') {
            return ['online' => $k === 'online', 'ido' => time()];
        }

        if (!$friss && self::$most !== null && time() - self::$most['ido'] < self::ERVENY) {
            return self::$most;
        }

        $t = $friss ? false : get_transient(self::TAR);

        if (is_array($t) && isset($t['online'], $t['ido'])) {
            return self::$most = ['online' => (bool) $t['online'], 'ido' => (int) $t['ido']];
        }

        $a = ['online' => self::ellenoriz(), 'ido' => time()];
        set_transient(self::TAR, $a, self::ERVENY);

        $elozo = get_option(self::TAR . '_utolso');

        if (!$a['online'] && (!is_array($elozo) || !empty($elozo['online']))) {
            update_option(self::TAR . '_utolso', ['online' => false, 'ota' => current_time('mysql')], false);
        } elseif ($a['online'] && is_array($elozo) && empty($elozo['online'])) {
            update_option(self::TAR . '_utolso', ['online' => true, 'ota' => current_time('mysql'), 'kiesett' => (string) ($elozo['ota'] ?? '')], false);
        }

        return self::$most = $a;
    }

    public static function online(): bool
    {
        return self::allapot()['online'];
    }

    /** A tárolt állapot törlése (pl. a fejléc „Újra” gombja). */
    public static function urit(): void
    {
        self::$most = null;
        delete_transient(self::TAR);
    }

    /** Helyi-e a cím: localhost, magánhálózat, .local / .lan, pont nélküli név, a saját webhely. */
    public static function helyi(string $url): bool
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return true;
        }

        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        if ($host === 'localhost' || !str_contains($host, '.') || preg_match('/\.(local|lan|home|internal|localhost)$/', $host) === 1) {
            return true;
        }

        return $host === strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)) || $host === strtolower((string) wp_parse_url(site_url(), PHP_URL_HOST));
    }

    /**
     * Külső kérés net nélkül: azonnali, érthető hiba a hosszú várakozás helyett.
     *
     * @param false|array|WP_Error $elozo
     * @param array<string, mixed> $args
     * @return false|array|WP_Error
     */
    public static function kerelem_elott($elozo, $args, $url)
    {
        // Vészkapcsoló: ha a tűzfal a próbacímeket tiltja, a védelem kikapcsolható a wp-config.php-ban.
        if (defined('SDH_MUHELY_HALOZAT_KI') && SDH_MUHELY_HALOZAT_KI) {
            return $elozo;
        }

        if ($elozo !== false || !is_string($url) || self::helyi($url)) {
            return $elozo;
        }

        if (self::online()) {
            return $elozo;
        }

        return new WP_Error('sdh_nincs_internet', 'Nincs internetkapcsolat, ezért ez most nem megy ki. A helyi munka (munkalap, pénztár, nyomtatás) közben is folytatható; próbáld újra, ha visszajött a net.');
    }

    /**
     * A pulzus válaszában az állapot: a felület a fejlécben jelzi, ha nincs internet.
     *
     * @param array<string, mixed> $valasz
     * @return array<string, mixed>
     */
    public static function pulzus(array $valasz): array
    {
        $a = self::allapot();
        $u = get_option(self::TAR . '_utolso');

        $valasz['net'] = [
            'online' => $a['online'],
            'ota'    => !$a['online'] && is_array($u) && !empty($u['ota']) ? mysql2date('H:i', (string) $u['ota']) : '',
        ];

        return $valasz;
    }

    /**
     * Mi megy és mi vár net nélkül – a Súgó és a Névjegy listája.
     *
     * @return array{megy: array<int, string>, var: array<int, string>}
     */
    public static function lista(): array
    {
        return [
            'megy' => [
                'Munkalapok, ügyfelek, eszközök felvétele és szerkesztése',
                'Házipénztár, napi árukiadás, rendelések rögzítése',
                'Nyomtatás (helyi nyomtatóra), matrica, PDF',
                'Naptár, csapatüzenetek, napló',
                'Helyi mentés (a szerver lemezére)',
                'SMS a bolti telefonon át – ha a telefon a helyi hálózaton van és van térereje',
            ],
            'var' => [
                'Számlázz.hu-számla (kézzel újrapróbálható, ha visszajött a net)',
                'E-mail (értesítések, árajánlat, visszaigazolás)',
                'Asszisztens (AI), IMEI-lekérdezés',
                'Külső mentés: Google Drive, FTP / NAS távoli címen',
                'Frissítés-ellenőrzés',
                'Távoli elérés (ügyféloldal, időpontfoglalás, elégedettségi oldal kívülről)',
            ],
        ];
    }
}
