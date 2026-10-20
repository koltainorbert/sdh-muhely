<?php
/**
 * SDH Értékesítés – rendelés (ARCHITEKTURA.md 5.): létrehozás, számlázási adatok ellenőrzése, közlemény, állapotoldal,
 * és a fizetés EGYETLEN belépési pontja.
 *
 * API (más modulok ezt hívják):
 *   letrehoz(array $be): array{ok, hibak?, id?, azon?, token?, allapot?, brutto?, kozlemeny?, hatarido?, statusz_url?, atiranyit?}
 *     be: tetelek[], opciok{ciklus, felhasznalo, telephely, kupon}, vevo{ceg, adoszam, eu_adoszam?, kapcsolattarto, email,
 *         telefon, orszag?, irsz, telepules, utca}, fizmod, tipus ('uj'|'megujitas'|'bovites'|'szolgaltatas'),
 *         licenc_kulcs? (megújítás / bővítés – a bejelentkezett vevőé kell legyen), elfogad{vallalkozas, aszf, eula} (mind
 *         kötelező; B2B-only – a nyilatkozat a jogi verziókkal együtt naplózva), megjegyzes?, vart_brutto?
 *   fizetve(int $id, string $forras, string $tranzakcio, int $osszeg, array $nyers = []): bool
 *     – idempotens (UNIQUE forras+tranzakcio); a részfizetések a `fizetett` mezőben gyűlnek; amikor fizetett ≥ bruttó:
 *       allapot 'fizetve', fizetve_ido, napló, do_action('sdh_sales_fizetve', $id) PONTOSAN EGYSZER (feltételes UPDATE).
 *       Igaz, ha ez a hívás új befizetést rögzített. Túlfizetés / törölt rendelésre érkező pénz: rögzítve + napló.
 *   ismeretlen_fizetes(string $forras, string $tranzakcio, int $osszeg, array $nyers = []): bool – nem párosítható befizetés
 *   rendeles(int $id): ?array (vevo, tetelek, lanc tömbként) · azon_szerint(string $azon): ?array · kozlemeny_szerint(string): ?array
 *   allapot_token(array $r): string · statusz_url(array $r): string · qr_url(array $r): string
 *   adoszam_jo(string): bool (magyar, 8-1-2 + ellenőrző jegy) · eu_adoszam_jo(string): bool · kozlemeny_jo(string): bool
 *   vevo_ellenoriz(array $v): array{vevo, hibak} · jogi_verzio('aszf'|'eula'|'adatvedelem'): string
 *   licenc_sajat(string $kulcs, int $user_id): ?array – a licenc eredeti rendelése, ha a vevőé
 *   allapot_szoveg(string): string · utolso_vevo(int $uid, string $email): array
 * Hookok: do_action('sdh_sales_rendeles_letrehozva', $id) (a számlázás itt állít díjbekérőt), do_action('sdh_sales_fizetve', $id),
 *   do_action('sdh_sales_reszfizetes', $id, $osszeg), do_action('sdh_sales_rendeles_allapot', $id, $regi, $uj).
 * REST: POST /rendeles · GET /rendeles/{azon}/allapot?t= · GET /rendeles/{azon}/qr?t= (PNG) · POST /admin/rendeles/{id}/fizetve
 *   · POST /admin/rendeles/{id}/torol.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Rendeles
{
    public const ALLAPOTOK = ['uj', 'dijbekero', 'fizetesre_var', 'fizetve', 'kiszolgalva', 'sikertelen', 'torolve', 'visszaterites', 'ajanlat'];
    public const TIPUSOK   = ['uj', 'megujitas', 'bovites', 'szolgaltatas'];

    /** 0 Ft-os kosár, amely nem kifejezetten ingyen kiszolgálható tételekből áll: ajánlatkérés (lead), nem fizetett rendelés. */
    public const TIPUS_AJANLAT = 'ajanlat';
    public const FORRASOK  = ['bank_import', 'szamlazz', 'barion', 'simplepay', 'kezi'];

    /** Ezekből lesz „fizetve”, ha a pénz megjött (a törölt is: késve érkező utalás). */
    private const NYITOTT = ['uj', 'dijbekero', 'fizetesre_var', 'sikertelen', 'torolve'];

    /** A közlemény betűkészlete: nincs 0/O, 1/I/L, 5/S, 8/B, Z/2 – kézzel is félreolvashatatlan. */
    public const KOZL_ABC = '34679ACDEFGHJKMNPQRTUVWXY';

    /** B2B: a webshop csak vállalkozásoknak / szervezeteknek értékesít (jogi döntés, 45/2014. Korm. r. miatt). */
    public const B2B_SZOVEG  = 'Vállalkozásként / szervezetként vásárolok (nem fogyasztóként).';
    public const B2B_FELIRAT = 'Rendelést csak vállalkozások és szervezetek adhatnak le.';

    /** @var array<int, true> nyersen kiszolgálandó válaszok (spl_object_id) */
    private static array $nyers = [];

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/rendeles', 'POST', [self::class, 'rest_letrehoz'], 'nyilvanos', ['rendeles', 8, 600]);
        SDH_Sales_Rest::ut('/rendeles/(?P<azon>R-\d{4}-\d{6})/allapot', 'GET', [self::class, 'rest_allapot'], 'nyilvanos', ['rendeles_allapot', 120, 600]);
        SDH_Sales_Rest::ut('/rendeles/(?P<azon>R-\d{4}-\d{6})/qr', 'GET', [self::class, 'rest_qr'], 'nyilvanos', ['rendeles_qr', 60, 600]);
        SDH_Sales_Rest::ut('/admin/rendeles/(?P<id>\d+)/fizetve', 'POST', [self::class, 'rest_admin_fizetve'], 'admin', ['admin_fizetve', 30, 60]);
        SDH_Sales_Rest::ut('/admin/rendeles/(?P<id>\d+)/torol', 'POST', [self::class, 'rest_admin_torol'], 'admin', ['admin_torol', 30, 60]);
        add_filter('rest_pre_serve_request', [self::class, 'nyers_kiszolgal'], 9, 4);
    }

    /* =================================================================
     * Olvasás
     * ============================================================== */

    /** @param array<string, mixed> $r */
    private static function bont(array $r): array
    {
        foreach (['vevo', 'tetelek', 'lanc'] as $k) {
            $d     = json_decode((string) ($r[$k] ?? ''), true);
            $r[$k] = is_array($d) ? $d : [];
        }

        foreach (['id', 'netto', 'afa', 'brutto', 'fizetett', 'user_id', 'kozpont_licenc_id'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0);
        }

        return $r;
    }

    /** @return array<string, mixed>|null */
    public static function rendeles(int $id): ?array
    {
        global $wpdb;

        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($r) ? self::bont($r) : null;
    }

    /** @return array<string, mixed>|null */
    public static function azon_szerint(string $azon): ?array
    {
        global $wpdb;

        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE azon = %s', strtoupper(trim($azon))), ARRAY_A);

        return is_array($r) ? self::bont($r) : null;
    }

    /** @return array<string, mixed>|null */
    public static function kozlemeny_szerint(string $k): ?array
    {
        global $wpdb;

        $k = strtoupper(trim($k));

        if ($k === '') {
            return null;
        }

        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE kozlemeny = %s', $k), ARRAY_A);

        return is_array($r) ? self::bont($r) : null;
    }

    public static function allapot_szoveg(string $a): string
    {
        $m = [
            'uj'            => __('Új', 'sdh-sales'),
            'dijbekero'     => __('Fizetésre vár', 'sdh-sales'),
            'fizetesre_var' => __('Fizetés folyamatban', 'sdh-sales'),
            'fizetve'       => __('Fizetve', 'sdh-sales'),
            'kiszolgalva'   => __('Teljesítve', 'sdh-sales'),
            'sikertelen'    => __('Sikertelen fizetés', 'sdh-sales'),
            'torolve'       => __('Törölve', 'sdh-sales'),
            'visszaterites' => __('Visszatérítve', 'sdh-sales'),
            'ajanlat'       => __('Ajánlatkérés', 'sdh-sales'),
        ];

        return $m[$a] ?? $a;
    }

    public static function tipus_szoveg(string $t): string
    {
        return ['uj' => __('Új licenc', 'sdh-sales'), 'megujitas' => __('Megújítás', 'sdh-sales'), 'bovites' => __('Bővítés', 'sdh-sales'), 'szolgaltatas' => __('Szolgáltatás', 'sdh-sales'), 'ajanlat' => __('Ajánlatkérés', 'sdh-sales')][$t] ?? $t;
    }

    /* =================================================================
     * Token, URL-ek
     * ============================================================== */

    /** @param array<string, mixed> $r */
    public static function allapot_token(array $r): string
    {
        return substr(hash_hmac('sha256', 'allapot|' . (int) $r['id'] . '|' . (string) $r['azon'], wp_salt('auth') . 'rendeles'), 0, 32);
    }

    /** @param array<string, mixed> $r */
    public static function statusz_url(array $r): string
    {
        return SDH_Sales_Kosar::penztar_url(['rendeles' => (string) $r['azon'], 't' => self::allapot_token($r)]);
    }

    /** @param array<string, mixed> $r */
    public static function qr_url(array $r): string
    {
        return add_query_arg(['t' => self::allapot_token($r)], rest_url(SDH_Sales_Rest::NS . '/rendeles/' . rawurlencode((string) $r['azon']) . '/qr'));
    }

    /* =================================================================
     * Ellenőrzők
     * ============================================================== */

    /** Magyar adószám: 8 jegyű törzsszám (ellenőrző jeggyel, súlyok 9-7-3-1-9-7-3) – áfakód (1–5) – megyekód. */
    public static function adoszam_jo(string $a): bool
    {
        $a = (string) preg_replace('/[\s.]/', '', $a);

        if (preg_match('/^(\d{8})-?([1-5])-?(\d{2})$/', $a, $m) !== 1) {
            return false;
        }

        $s = 0;

        foreach ([9, 7, 3, 1, 9, 7, 3] as $i => $w) {
            $s += (int) $m[1][$i] * $w;
        }

        if ((10 - $s % 10) % 10 !== (int) $m[1][7]) {
            return false;
        }

        $megye = (int) $m[3];

        return ($megye >= 2 && $megye <= 20) || ($megye >= 22 && $megye <= 44) || $megye === 51;
    }

    public static function adoszam_formaz(string $a): string
    {
        $d = (string) preg_replace('/\D/', '', $a);

        return strlen($d) === 11 ? substr($d, 0, 8) . '-' . $d[8] . '-' . substr($d, 9, 2) : trim($a);
    }

    /** Közösségi (EU) adószám formátuma országonként (a VIES-lekérdezés nem része – azt a számlázó ellenőrzi). */
    public static function eu_adoszam_jo(string $a): bool
    {
        $a = strtoupper((string) preg_replace('/[\s.\-]/', '', $a));
        $m = [
            'AT' => 'U\d{8}', 'BE' => '[01]\d{9}', 'BG' => '\d{9,10}', 'CY' => '\d{8}[A-Z]', 'CZ' => '\d{8,10}', 'DE' => '\d{9}',
            'DK' => '\d{8}', 'EE' => '\d{9}', 'EL' => '\d{9}', 'ES' => '[A-Z0-9]\d{7}[A-Z0-9]', 'FI' => '\d{8}', 'FR' => '[A-Z0-9]{2}\d{9}',
            'HR' => '\d{11}', 'HU' => '\d{8}', 'IE' => '\d[A-Z0-9+*]\d{5}[A-Z]{1,2}', 'IT' => '\d{11}', 'LT' => '(\d{9}|\d{12})',
            'LU' => '\d{8}', 'LV' => '\d{11}', 'MT' => '\d{8}', 'NL' => '\d{9}B\d{2}', 'PL' => '\d{10}', 'PT' => '\d{9}', 'RO' => '\d{2,10}',
            'SE' => '\d{12}', 'SI' => '\d{8}', 'SK' => '\d{10}', 'XI' => '(\d{9}|\d{12}|GD\d{3}|HA\d{3})',
        ];
        $o = substr($a, 0, 2);

        return isset($m[$o]) && preg_match('/^' . $o . $m[$o] . '$/', $a) === 1;
    }

    public static function telefon_jo(string $t): bool
    {
        $d = (string) preg_replace('/[\s\-\/().]/', '', $t);

        return preg_match('/^(\+|00)?\d{8,15}$/', $d) === 1;
    }

    /** A közlemény ellenőrző karaktere (súlyozott összeg mod 25 a betűkészletben). */
    public static function kozlemeny_ellenorzo(string $test): string
    {
        $abc = self::KOZL_ABC;
        $n   = strlen($abc);
        $s   = 0;

        foreach (str_split($test) as $i => $c) {
            $p  = strpos($abc, $c);
            $s += ((int) $p + 1) * ($i * 2 + 3);
        }

        return $abc[$s % $n];
    }

    public static function kozlemeny_elotag(): string
    {
        $e = strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) SDH_Sales::b('fizetes.kozlemeny_elotag', 'RF')));

        return substr($e !== '' ? $e : 'RF', 0, 4);
    }

    /** Érvényes közlemény-e (előtag + 6 jel + ellenőrző). Szóköz / kötőjel nélkül, nagybetűvel kell. */
    public static function kozlemeny_jo(string $k): bool
    {
        $e = self::kozlemeny_elotag();

        if (strpos($k, $e) !== 0 || strlen($k) !== strlen($e) + 7) {
            return false;
        }

        $t = substr($k, strlen($e), 6);

        return strspn($t, self::KOZL_ABC) === 6 && self::kozlemeny_ellenorzo($t) === substr($k, -1);
    }

    private static function kozlemeny_uj(): string
    {
        global $wpdb;

        $t = SDH_Sales_Schema::t('rendeles');

        for ($i = 0; $i < 50; $i++) {
            $s = '';

            for ($j = 0; $j < 6; $j++) {
                $s .= self::KOZL_ABC[random_int(0, strlen(self::KOZL_ABC) - 1)];
            }

            $k = self::kozlemeny_elotag() . $s . self::kozlemeny_ellenorzo($s);

            if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE kozlemeny = %s", $k)) === 0) {
                return $k;
            }
        }

        throw new RuntimeException('Nem sikerült egyedi közleményt képezni.');
    }

    /**
     * Számlázási adatok tisztítása + ellenőrzése (magyar hibaüzenetek, mezőnként).
     *
     * @param array<string, mixed> $v
     * @return array{vevo: array<string, string>, hibak: array<string, string>}
     */
    public static function vevo_ellenoriz(array $v): array
    {
        $s = static fn (string $k, int $max = 190): string => mb_substr(trim(sanitize_text_field((string) ($v[$k] ?? ''))), 0, $max);
        $o = strtoupper($s('orszag', 2)) ?: 'HU';
        $o = preg_match('/^[A-Z]{2}$/', $o) === 1 ? $o : 'HU';
        $x = [
            'ceg'            => $s('ceg'),
            'adoszam'        => $s('adoszam', 20),
            'eu_adoszam'     => strtoupper((string) preg_replace('/\s+/', '', $s('eu_adoszam', 20))),
            'kapcsolattarto' => $s('kapcsolattarto', 120),
            'email'          => strtolower(sanitize_email((string) ($v['email'] ?? ''))),
            'telefon'        => $s('telefon', 40),
            'orszag'         => $o,
            'irsz'           => $s('irsz', 12),
            'telepules'      => $s('telepules', 120),
            'utca'           => $s('utca', 190),
        ];
        $h = [];

        if (mb_strlen($x['ceg']) < 2) {
            $h['ceg'] = __('Add meg a cég nevét.', 'sdh-sales');
        }

        if ($o === 'HU') {
            if ($x['adoszam'] === '') {
                $h['adoszam'] = __('Add meg az adószámot (11 számjegy, pl. 12345678-1-42).', 'sdh-sales');
            } elseif (!self::adoszam_jo($x['adoszam'])) {
                $h['adoszam'] = __('Az adószám hibás: ellenőrizd a számjegyeket (formátum: 12345678-1-42).', 'sdh-sales');
            } else {
                $x['adoszam'] = self::adoszam_formaz($x['adoszam']);
            }

            if (preg_match('/^\d{4}$/', $x['irsz']) !== 1) {
                $h['irsz'] = __('Az irányítószám 4 számjegy.', 'sdh-sales');
            }

            if ($x['eu_adoszam'] !== '' && (!self::eu_adoszam_jo($x['eu_adoszam']) || strpos($x['eu_adoszam'], 'HU') !== 0 || substr($x['eu_adoszam'], 2) !== substr((string) preg_replace('/\D/', '', $x['adoszam']), 0, 8))) {
                $h['eu_adoszam'] = __('A közösségi adószám HU + az adószám első 8 jegye (pl. HU12345678).', 'sdh-sales');
            }
        } else {
            if ($x['eu_adoszam'] === '' || !self::eu_adoszam_jo($x['eu_adoszam'])) {
                $h['eu_adoszam'] = __('Külföldi cégnél add meg az érvényes közösségi adószámot (pl. ATU12345678).', 'sdh-sales');
            }

            if ($x['irsz'] === '') {
                $h['irsz'] = __('Add meg az irányítószámot.', 'sdh-sales');
            }
        }

        if (mb_strlen($x['telepules']) < 2) {
            $h['telepules'] = __('Add meg a települést.', 'sdh-sales');
        }

        if (mb_strlen($x['utca']) < 3) {
            $h['utca'] = __('Add meg az utcát és a házszámot.', 'sdh-sales');
        }

        if (mb_strlen($x['kapcsolattarto']) < 3) {
            $h['kapcsolattarto'] = __('Add meg a kapcsolattartó nevét.', 'sdh-sales');
        }

        if (!is_email($x['email'])) {
            $h['email'] = __('Az e-mail-cím hibás.', 'sdh-sales');
        }

        if (!self::telefon_jo($x['telefon'])) {
            $h['telefon'] = __('A telefonszám hibás (pl. +36 30 123 4567).', 'sdh-sales');
        }

        $x['nev'] = $x['kapcsolattarto'];
        $x['cim'] = trim($x['irsz'] . ' ' . $x['telepules'] . ', ' . $x['utca'], ' ,');

        return ['vevo' => $x, 'hibak' => $h];
    }

    /** A jogi dokumentum aktuális verziója (beállítás `jogi.<k>_verzio`, különben a data/jogi/*.md frontmatterje). */
    public static function jogi_verzio(string $k): string
    {
        $b = trim((string) SDH_Sales::b('jogi.' . $k . '_verzio', ''));

        if ($b !== '') {
            return $b;
        }

        $f = ['aszf' => 'aszf', 'eula' => 'licencszerzodes', 'adatvedelem' => 'adatkezeles'][$k] ?? '';
        $u = $f !== '' ? SDH_SALES_DIR . 'data/jogi/' . $f . '.md' : '';

        if ($u === '' || !is_file($u)) {
            return '1.0';
        }

        $fej = (string) file_get_contents($u, false, null, 0, 2000);

        return preg_match('/^verzio:\s*"?([^"\n]+)"?\s*$/m', $fej, $m) === 1 ? trim($m[1]) : '1.0';
    }

    /* =================================================================
     * Licenc-tulajdon (megújítás, bővítés)
     * ============================================================== */

    /**
     * A licenc legutóbbi teljesített rendelése, ha a vevőé. A tulajdon a fiókhoz (user_id) kötött; e-mail-egyezés csak
     * fiók nélküli (user_id = 0) régi rendelésnél számít – így egy átírt fiók-e-mail nem ad hozzáférést idegen licenchez.
     *
     * @return array<string, mixed>|null
     */
    public static function licenc_sajat(string $kulcs, int $user_id): ?array
    {
        global $wpdb;

        $kulcs = strtoupper(trim($kulcs));

        if ($kulcs === '' || $user_id <= 0 || preg_match('/^[A-Z0-9-]{6,40}$/', $kulcs) !== 1) {
            return null;
        }

        $u     = get_userdata($user_id);
        $email = $u instanceof WP_User ? strtolower((string) $u->user_email) : '';
        $t     = SDH_Sales_Schema::t('rendeles');
        $sorok = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE licenc_kulcs = %s AND allapot IN ('fizetve','kiszolgalva') ORDER BY id DESC LIMIT 20",
            $kulcs
        ), ARRAY_A);

        foreach ($sorok as $s) {
            if ((int) $s['user_id'] === $user_id || ((int) $s['user_id'] === 0 && $email !== '' && strtolower((string) $s['email']) === $email)) {
                return self::bont($s);
            }
        }

        if (class_exists('SDH_Sales_Kiszolgalas') && method_exists('SDH_Sales_Kiszolgalas', 'licencek')) {
            $l = SDH_Sales_Kiszolgalas::licencek()[$kulcs] ?? null;

            if (is_array($l) && ((int) ($l['user_id'] ?? 0) === $user_id || ((int) ($l['user_id'] ?? 0) === 0 && $email !== '' && strtolower((string) ($l['email'] ?? '')) === $email)) && !empty($l['rendeles_id'])) {
                return self::rendeles((int) $l['rendeles_id']);
            }
        }

        return null;
    }

    /** A licenc első (új) rendeléséből a csomag és a keret. @return array{csomag: string, modulok: array<int, string>, felhasznalo: int, telephely: int, ciklus: string, lejar: string} */
    public static function licenc_keret(string $kulcs, array $r): array
    {
        $o   = (array) ($r['vevo']['_opciok'] ?? []);
        $cs  = (string) ($o['csomag'] ?? '');
        $mod = [];

        foreach ((array) $r['tetelek'] as $t) {
            if (!is_array($t)) {
                continue;
            }

            if (($t['tipus'] ?? '') === 'csomag' && $cs === '') {
                $cs = (string) $t['kulcs'];
            }

            if (($t['tipus'] ?? '') === 'modul') {
                $mod[] = (string) $t['kulcs'];
            }
        }

        $lejar = '';

        if (class_exists('SDH_Sales_Kiszolgalas') && method_exists('SDH_Sales_Kiszolgalas', 'licencek')) {
            $lejar = (string) (SDH_Sales_Kiszolgalas::licencek()[$kulcs]['lejar'] ?? '');
        }

        return [
            'csomag'      => $cs,
            'modulok'     => array_values(array_unique(array_merge($mod, array_map('strval', (array) ($o['extra_modulok'] ?? []))))),
            'felhasznalo' => (int) ($o['felhasznalo'] ?? 0),
            'telephely'   => (int) ($o['telephely'] ?? 0),
            'ciklus'      => (string) ($o['ciklus'] ?? $r['ciklus']),
            'lejar'       => $lejar,
        ];
    }

    /**
     * Bővítés: a hozzáadott ismétlődő tételek ára a hátralévő időre arányosan (egész Ft, félre felfelé), az áfa újraszámolva.
     *
     * @param array<string, mixed> $ar
     * @return array<string, mixed>
     */
    public static function idoaranyos(array $ar, string $lejar, ?int $most = null): array
    {
        $most = $most ?? time();
        $vege = strtotime($lejar . ' 23:59:59');

        if ($vege === false || $vege <= $most) {
            return $ar;
        }

        $nap   = (int) ceil(($vege - $most) / DAY_IN_SECONDS);
        $netto = 0;

        foreach ($ar['sorok'] as $i => $s) {
            if ($s['ciklus'] !== 'egyszeri') {
                $teljes = $s['ciklus'] === 'havi' ? 30 : 365;
                $n      = min($nap, $teljes);
                $uj     = SDH_Sales_Ar::kerek((int) $s['netto'] * $n, $teljes);
                $ar['sorok'][$i]['netto']      = $uj;
                $ar['sorok'][$i]['idoaranyos'] = $n . '/' . $teljes;
            }

            $netto += (int) $ar['sorok'][$i]['netto'];
        }

        $ar['netto'] = $netto;
        $ar['afa']   = SDH_Sales_Ar::kerek($netto * (int) $ar['afa_kulcs'], 100);
        $ar['brutto'] = $netto + $ar['afa'];

        return $ar;
    }

    /* =================================================================
     * Létrehozás
     * ============================================================== */

    /**
     * 0 Ft-os rendelés kiszolgálható-e fizetés nélkül: igen, ha a nulla egy kuponból jön (az adminisztrátor döntése), vagy
     * ha minden tétel a katalógusban kifejezetten ingyenesnek van jelölve (`ingyenes: true`). Minden más (pl. 0 Ft-os
     * alap-support, „ajánlatkérés” szolgáltatás) ajánlatkérés lesz – nem fizetett, nincs licenc, nincs kiszolgálás.
     *
     * @param array<string, mixed> $ar
     */
    public static function ingyen_kiszolgalhato(array $ar): bool
    {
        if ((int) $ar['brutto'] !== 0) {
            return false;
        }

        if ((int) ($ar['netto_lista'] ?? 0) > 0) {
            return !empty($ar['kupon']);
        }

        if ((array) ($ar['sorok'] ?? []) === []) {
            return false;
        }

        foreach ((array) $ar['sorok'] as $s) {
            $k = (string) ($s['kulcs'] ?? '');
            $e = match ((string) ($s['tipus'] ?? '')) {
                'csomag', 'felhasznalo', 'telephely' => SDH_Sales_Katalogus::csomag($k),
                'modul'        => SDH_Sales_Katalogus::modul($k),
                'szolgaltatas' => SDH_Sales_Katalogus::szolgaltatas($k),
                default        => null,
            };

            if (!is_array($e) || empty($e['ingyenes']) || !empty($e['ajanlatkeres'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $be
     * @return array<string, mixed>
     */
    public static function letrehoz(array $be): array
    {
        global $wpdb;

        $tipus  = sanitize_key((string) ($be['tipus'] ?? 'uj'));
        $fizmod = sanitize_key((string) ($be['fizmod'] ?? 'atutalas'));
        $hibak  = [];
        $uid    = get_current_user_id();

        if (!in_array($tipus, self::TIPUSOK, true)) {
            return ['ok' => false, 'hibak' => ['tipus' => __('Ismeretlen rendeléstípus.', 'sdh-sales')]];
        }

        if (!in_array($fizmod, array_column(SDH_Sales_Kosar::fizmodok(), 'kulcs'), true)) {
            $hibak['fizmod'] = __('Válassz fizetési módot.', 'sdh-sales');
        }

        $tetelek = SDH_Sales_Ar::tetelek_tisztit($be['tetelek'] ?? []);
        $opciok  = SDH_Sales_Ar::opciok_tisztit($be['opciok'] ?? []);
        $licenc  = strtoupper(sanitize_text_field((string) ($be['licenc_kulcs'] ?? '')));
        $eredeti = null;
        $keret   = null;

        // --- Megújítás / bővítés: a licenc a bejelentkezett vevőé legyen ---------------------
        if ($tipus === 'megujitas' || $tipus === 'bovites') {
            $eredeti = $uid > 0 ? self::licenc_sajat($licenc, $uid) : null;

            if ($eredeti === null) {
                return ['ok' => false, 'hibak' => ['licenc_kulcs' => __('Ez a licenc nem a fiókodhoz tartozik, vagy nem vagy belépve.', 'sdh-sales')]];
            }

            $keret = self::licenc_keret($licenc, $eredeti);

            if ($keret['csomag'] === '') {
                return ['ok' => false, 'hibak' => ['licenc_kulcs' => __('A licenc csomagja nem állapítható meg – írj nekünk.', 'sdh-sales')]];
            }

            if ($tipus === 'megujitas') {
                $tetelek = [['tipus' => 'csomag', 'kulcs' => $keret['csomag'], 'db' => 1]];

                foreach ($keret['modulok'] as $m) {
                    $tetelek[] = ['tipus' => 'modul', 'kulcs' => $m, 'db' => 1];
                }

                $opciok = ['ciklus' => in_array($opciok['ciklus'], ['havi', 'eves'], true) ? $opciok['ciklus'] : $keret['ciklus']]
                    + array_filter(['felhasznalo' => $keret['felhasznalo'] ?: null, 'telephely' => $keret['telephely'] ?: null])
                    + array_intersect_key($opciok, ['kupon' => 1]);
            } else {
                // A licencben már meglévő (külön vett) modult a bővítés nem számolja fel újra.
                $tetelek = array_values(array_filter($tetelek, static fn (array $t): bool => $t['tipus'] !== 'csomag' && !($t['tipus'] === 'modul' && in_array((string) $t['kulcs'], $keret['modulok'], true))));
                $opciok['ciklus']           = $keret['ciklus'] === 'havi' ? 'havi' : 'eves';
                $opciok['alap_csomag']      = $keret['csomag'];
                $opciok['alap_felhasznalo'] = $keret['felhasznalo'];
                $opciok['alap_telephely']   = $keret['telephely'];
                $opciok['felhasznalo']      = max($keret['felhasznalo'], (int) ($opciok['felhasznalo'] ?? 0)) ?: null;
                $opciok['telephely']        = max($keret['telephely'], (int) ($opciok['telephely'] ?? 0)) ?: null;
                $opciok                     = array_filter($opciok, static fn ($x): bool => $x !== null);
            }
        } elseif ($tipus === 'szolgaltatas') {
            $tetelek = array_values(array_filter($tetelek, static fn (array $t): bool => $t['tipus'] === 'szolgaltatas'));
        } elseif (!in_array('csomag', array_column($tetelek, 'tipus'), true)) {
            $tipus   = 'szolgaltatas';
        }

        $ar = SDH_Sales_Ar::szamol($tetelek, $opciok);

        if ($tipus === 'bovites' && $keret !== null && $keret['lejar'] !== '') {
            $ar = self::idoaranyos($ar, $keret['lejar']);
        }

        if ($ar['hibak'] !== []) {
            $hibak['kosar'] = implode(' ', $ar['hibak']);
        }

        if ($tipus === 'bovites' && ($ar['sorok'] === [] || (int) $ar['netto_lista'] === 0) && $ar['hibak'] === []) {
            $hibak['kosar'] = __('Válassz legalább egy új modult vagy nagyobb keretet a bővítéshez.', 'sdh-sales');
        }

        // --- Vevő -------------------------------------------------------------------------------
        $vbe = is_array($be['vevo'] ?? null) ? $be['vevo'] : [];

        if ($eredeti !== null && empty($vbe['ceg'])) {
            $vbe = array_merge(array_intersect_key($eredeti['vevo'], array_flip(['ceg', 'adoszam', 'eu_adoszam', 'kapcsolattarto', 'email', 'telefon', 'orszag', 'irsz', 'telepules', 'utca'])), $vbe);
        }

        $ve = self::vevo_ellenoriz($vbe);
        $hibak += $ve['hibak'];
        $vevo  = $ve['vevo'];

        $elf = is_array($be['elfogad'] ?? null) ? $be['elfogad'] : [];

        if (empty($elf['vallalkozas'])) {
            $hibak['elfogad_vallalkozas'] = __('Rendelést csak vállalkozások és szervezetek adhatnak le: jelöld be, hogy vállalkozásként / szervezetként vásárolsz (nem fogyasztóként).', 'sdh-sales');
        }

        if (empty($elf['aszf'])) {
            $hibak['elfogad_aszf'] = __('A rendeléshez el kell fogadnod az Általános Szerződési Feltételeket.', 'sdh-sales');
        }

        if ($tipus !== 'szolgaltatas' && empty($elf['eula'])) {
            $hibak['elfogad_eula'] = __('A rendeléshez el kell fogadnod a licencszerződést.', 'sdh-sales');
        }

        if ($hibak !== []) {
            return ['ok' => false, 'hibak' => $hibak, 'ar' => $ar];
        }

        if (isset($be['vart_brutto']) && is_numeric($be['vart_brutto']) && (int) $be['vart_brutto'] !== (int) $ar['brutto']) {
            return ['ok' => false, 'hibak' => ['kosar' => sprintf(__('Az ár időközben változott: %s. Nézd át, és küldd el újra.', 'sdh-sales'), SDH_Sales::ft((int) $ar['brutto']))], 'ar' => $ar, 'arvaltozas' => true];
        }

        // 0 Ft, nem kifejezetten ingyenes tételek: ajánlatkérés (nincs fizetés, licenc, kiszolgálás, vevői fiók).
        $ajanlat = (int) $ar['brutto'] === 0 && !self::ingyen_kiszolgalhato($ar);

        if ($ajanlat) {
            $tipus = self::TIPUS_AJANLAT;
        }

        // Kupon: vevőnkénti korlát (a kupon `vevonkent` mezője).
        $kod = (string) ($ar['kupon']['kod'] ?? '');

        if ($kod !== '') {
            $kh = SDH_Sales_Kupon::vevo_korlat($kod, (string) $vevo['email'], $uid);

            if ($kh !== '') {
                return ['ok' => false, 'hibak' => ['kosar' => $kh], 'ar' => $ar];
            }
        }

        // --- Vevő-fiók ---------------------------------------------------------------------------
        $user_id = $uid > 0 ? $uid : ($ajanlat ? 0 : self::vevo_fiok($vevo));

        $vevo['_jogi'] = [
            'aszf'   => self::jogi_verzio('aszf'),
            'eula'   => $tipus !== 'szolgaltatas' ? self::jogi_verzio('eula') : '',
            'vallalkozas' => true,
            'vallalkozas_szoveg' => self::B2B_SZOVEG,
            'ido'    => gmdate('c'),
            'ip_hash' => SDH_Sales::ip_hash(),
        ];
        $vevo['_opciok'] = [
            'csomag'        => (string) ($ar['csomag'] ?? ($keret['csomag'] ?? '')),
            'extra_modulok' => array_values(array_column(array_filter($ar['sorok'], static fn ($s): bool => $s['tipus'] === 'modul'), 'kulcs')),
            'felhasznalo'   => (int) ($ar['felhasznalo'] ?? 0),
            'telephely'     => (int) ($ar['telephely'] ?? 0),
            'ciklus'        => (string) $ar['ciklus'],
            'max_peldany'   => (int) ($ar['max_peldany'] ?? 0),
        ];

        if ($tipus === 'bovites' && $keret !== null) {
            $vevo['_opciok']['extra_modulok'] = array_values(array_unique(array_merge($keret['modulok'], $vevo['_opciok']['extra_modulok'])));
        }

        $megj = mb_substr(sanitize_textarea_field((string) ($be['megjegyzes'] ?? '')), 0, 1000);

        if ($megj !== '') {
            $vevo['_megjegyzes'] = $megj;
        }

        // A tételsorok a számlához és a kiszolgáláshoz (nettó a kupon után).
        $ciklus_szo = ['havi' => __('havi díj', 'sdh-sales'), 'eves' => __('éves díj', 'sdh-sales'), 'egyszeri' => ''];
        $sorok      = [];

        foreach ($ar['sorok'] as $s) {
            $sorok[] = [
                'tipus'      => $s['tipus'],
                'kulcs'      => $s['kulcs'],
                'nev'        => $s['nev'],
                'megnevezes' => SDH_Sales::termeknev() . ' – ' . $s['nev'] . ($ciklus_szo[$s['ciklus']] !== '' ? ' (' . $ciklus_szo[$s['ciklus']] . ')' : ''),
                'mennyiseg'  => (int) $s['mennyiseg'],
                'egysegar'   => (int) $s['egysegar'],
                'netto'      => (int) $s['netto'],
                'kedvezmeny' => (int) $s['kedvezmeny'],
                'ciklus'     => $s['ciklus'],
                'idoaranyos' => (string) ($s['idoaranyos'] ?? ''),
            ];
        }

        $most     = current_time('mysql', true);
        $hatarido = wp_date('Y-m-d', time() + max(1, (int) SDH_Sales::b('fizetes.dijbekero_nap', 8)) * DAY_IN_SECONDS);
        $t        = SDH_Sales_Schema::t('rendeles');

        try {
            $kozl = self::kozlemeny_uj();
        } catch (RuntimeException $e) {
            return ['ok' => false, 'hibak' => ['rendszer' => __('Átmeneti hiba. Próbáld újra egy perc múlva.', 'sdh-sales')]];
        }

        $ok = $wpdb->insert($t, [
            'azon'         => 'TMP-' . SDH_Sales::token(9),
            'allapot'      => 'uj',
            'tipus'        => $tipus,
            'vevo'         => (string) wp_json_encode($vevo, JSON_UNESCAPED_UNICODE),
            'tetelek'      => (string) wp_json_encode($sorok, JSON_UNESCAPED_UNICODE),
            'netto'        => (int) $ar['netto'],
            'afa'          => (int) $ar['afa'],
            'brutto'       => (int) $ar['brutto'],
            'fizetett'     => 0,
            'penznem'      => 'HUF',
            'ciklus'       => (string) $ar['ciklus'],
            'kupon'        => (string) ($ar['kupon']['kod'] ?? ''),
            'fizmod'       => $fizmod,
            'kozlemeny'    => $kozl,
            'email'        => $vevo['email'],
            'licenc_kulcs' => $tipus === 'megujitas' || $tipus === 'bovites' ? $licenc : '',
            'user_id'      => $user_id,
            'ip_hash'      => SDH_Sales::ip_hash(),
            'letrehozva'   => $most,
            'modositva'    => $most,
            'hatarido'     => $hatarido,
        ]);

        if (!$ok) {
            SDH_Sales_Naplo::ir('rendeles', 'Rendelés mentése nem sikerült: ' . $wpdb->last_error, 'hiba');

            return ['ok' => false, 'hibak' => ['rendszer' => __('Átmeneti hiba. Próbáld újra egy perc múlva.', 'sdh-sales')]];
        }

        $id = (int) $wpdb->insert_id;

        // Kupon-foglalás: a beszúrás után, az azonosító sorrendjében dönt (atomi – két párhuzamos rendelésből a később
        // beszúrt esik ki, ha a keret elfogyott). A foglalás `kupon.foglalas_ora` óráig él, ha a rendelés fizetetlen.
        if ($kod !== '' && !SDH_Sales_Kupon::foglal($kod, $id)) {
            $wpdb->delete($t, ['id' => $id]);

            return ['ok' => false, 'hibak' => ['kosar' => sprintf(__('A(z) %s kupon elfogyott.', 'sdh-sales'), $kod)], 'ar' => $ar];
        }

        $azon = SDH_Sales::azon('R', $id);
        $uj   = $ajanlat ? 'ajanlat' : ($fizmod === 'atutalas' ? 'dijbekero' : 'fizetesre_var');
        $wpdb->update($t, ['azon' => $azon, 'allapot' => $uj], ['id' => $id]);

        SDH_Sales_Naplo::ir('rendeles', 'Új rendelés: ' . $azon . ' (' . self::tipus_szoveg($tipus) . ', ' . SDH_Sales::ft((int) $ar['brutto']) . ', ' . $fizmod . ').', 'siker', 'rendeles', $id, null, [
            'tipus' => $tipus, 'brutto' => (int) $ar['brutto'], 'kupon' => (string) ($ar['kupon']['kod'] ?? ''), 'jogi' => $vevo['_jogi'],
        ]);

        if ($ajanlat) {
            return self::ajanlat_rogzit($id);
        }

        do_action('sdh_sales_rendeles_letrehozva', $id);

        $r   = (array) self::rendeles($id);
        $ki  = [
            'ok'          => true,
            'id'          => $id,
            'azon'        => $azon,
            'token'       => self::allapot_token($r),
            'allapot'     => (string) $r['allapot'],
            'brutto'      => (int) $r['brutto'],
            'kozlemeny'   => $kozl,
            'hatarido'    => $hatarido,
            'fizmod'      => $fizmod,
            'statusz_url' => self::statusz_url($r),
        ];

        if ((int) $r['brutto'] === 0) {
            self::fizetve($id, 'kezi', 'ingyenes-' . $azon, 0, ['ok' => 'nulla összegű rendelés']);
            $ki['allapot'] = 'fizetve';

            return $ki;
        }

        if ($fizmod === 'atutalas') {
            SDH_Sales_Fizetes_Atutalas::dijbekero_level($id);
        } else {
            $k = SDH_Sales_Fizetes::kartya_indit($id, $fizmod);

            if (!empty($k['ok'])) {
                $ki['atiranyit'] = (string) $k['url'];
            } else {
                $ki['uzenet'] = (string) ($k['uzenet'] ?? __('A kártyás fizetés most nem indítható. Próbáld újra, vagy válassz átutalást.', 'sdh-sales'));
            }
        }

        return $ki;
    }

    /**
     * Ajánlatkérés rögzítése: érdeklődés (lead) + levél a forgalmazónak. Nincs díjbekérő, fizetés, licenc, vevői fiók.
     *
     * @return array<string, mixed>
     */
    private static function ajanlat_rogzit(int $id): array
    {
        $r = (array) self::rendeles($id);
        $v = (array) $r['vevo'];
        $t = array_map(static fn ($x): string => is_array($x) ? (string) ($x['nev'] ?? $x['kulcs'] ?? '') : '', (array) $r['tetelek']);

        SDH_Sales_Urlap::lead_ment('ajanlat', [
            'rendeles'       => (string) $r['azon'],
            'ceg'            => (string) ($v['ceg'] ?? ''),
            'nev'            => (string) ($v['kapcsolattarto'] ?? ''),
            'telefon'        => (string) ($v['telefon'] ?? ''),
            'tetelek'        => $t,
            'megjegyzes'     => (string) ($v['_megjegyzes'] ?? ''),
        ], (string) $r['email']);
        SDH_Sales_Urlap::ertesit(sprintf(__('Ajánlatkérés %s – %s', 'sdh-sales'), (string) $r['azon'], (string) ($v['ceg'] ?? '')), [
            __('Cég', 'sdh-sales')       => (string) ($v['ceg'] ?? ''),
            __('Név', 'sdh-sales')       => (string) ($v['kapcsolattarto'] ?? ''),
            __('E-mail', 'sdh-sales')    => (string) $r['email'],
            __('Telefon', 'sdh-sales')   => (string) ($v['telefon'] ?? ''),
            __('Tételek', 'sdh-sales')   => implode(', ', array_filter($t)),
            __('Megjegyzés', 'sdh-sales') => (string) ($v['_megjegyzes'] ?? ''),
        ], (string) $r['email']);
        SDH_Sales_Naplo::ir('rendeles', 'Ajánlatkérés (0 Ft-os kosár): ' . (string) $r['azon'] . ' – nem fizetett rendelés, nincs kiszolgálás.', 'info', 'rendeles', $id);
        do_action('sdh_sales_ajanlatkeres', $id);

        return [
            'ok'          => true,
            'id'          => $id,
            'azon'        => (string) $r['azon'],
            'token'       => self::allapot_token($r),
            'allapot'     => 'ajanlat',
            'brutto'      => 0,
            'kozlemeny'   => '',
            'hatarido'    => '',
            'fizmod'      => (string) $r['fizmod'],
            'statusz_url' => self::statusz_url($r),
            'uzenet'      => __('Köszönjük! Az ajánlatkérésedet rögzítettük, hamarosan jelentkezünk a részletekkel.', 'sdh-sales'),
        ];
    }

    /** A vevő WordPress-fiókja e-mail alapján (új: sdh_vevo szerep, használhatatlan véletlen jelszó – belépés e-mailes linkkel). */
    public static function vevo_fiok(array $vevo): int
    {
        $email = (string) ($vevo['email'] ?? '');

        if (!is_email($email)) {
            return 0;
        }

        $u = get_user_by('email', $email);

        if ($u instanceof WP_User) {
            return (int) $u->ID;
        }

        $alap = sanitize_user((string) strtok($email, '@'), true) ?: 'vevo';
        $nev  = substr($alap, 0, 40);

        for ($i = 0; username_exists($nev) && $i < 20; $i++) {
            $nev = substr($alap, 0, 32) . '-' . strtolower(SDH_Sales::token(3));
        }

        if (get_role('sdh_vevo') === null) {
            add_role('sdh_vevo', 'Vevő', ['read' => true]);
        }

        $id = wp_insert_user([
            'user_login'   => $nev,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password(48, true, true),
            'display_name' => (string) ($vevo['kapcsolattarto'] ?? $nev),
            'first_name'   => (string) ($vevo['kapcsolattarto'] ?? ''),
            'role'         => 'sdh_vevo',
        ]);

        if (is_wp_error($id)) {
            SDH_Sales_Naplo::ir('rendeles', 'Vevői fiók nem jött létre: ' . $id->get_error_message(), 'figyel');

            return 0;
        }

        update_user_meta((int) $id, 'show_admin_bar_front', 'false');

        return (int) $id;
    }

    /** Az utolsó rendelés számlázási adatai (a pénztár előtöltéséhez). @return array<string, string> */
    public static function utolso_vevo(int $uid, string $email): array
    {
        global $wpdb;

        $r = $wpdb->get_var($wpdb->prepare('SELECT vevo FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE user_id = %d OR (user_id = 0 AND email = %s) ORDER BY id DESC LIMIT 1', $uid, strtolower($email)));
        $v = json_decode((string) $r, true);

        if (!is_array($v)) {
            return ['email' => $email];
        }

        return array_map('strval', array_intersect_key($v, array_flip(['ceg', 'adoszam', 'eu_adoszam', 'kapcsolattarto', 'email', 'telefon', 'orszag', 'irsz', 'telepules', 'utca'])));
    }

    /* =================================================================
     * Fizetés – EGYETLEN belépési pont
     * ============================================================== */

    public static function fizetve(int $id, string $forras, string $tranzakcio, int $osszeg, array $nyers = []): bool
    {
        global $wpdb;

        $tranzakcio = mb_substr(trim($tranzakcio), 0, 120);
        $meta       = self::fizetes_meta($forras, $nyers);

        if (!in_array($forras, self::FORRASOK, true) || $tranzakcio === '' || $osszeg < 0) {
            SDH_Sales_Naplo::ir('fizetes', 'Érvénytelen fizetési adat (' . $forras . ').', 'hiba', 'rendeles', $id);

            return false;
        }

        $r = self::rendeles($id);

        if ($r === null) {
            self::ismeretlen_fizetes($forras, $tranzakcio, $osszeg, $nyers + ['rendeles_id' => $id]);

            return false;
        }

        $tf = SDH_Sales_Schema::t('fizetes');
        $tr = SDH_Sales_Schema::t('rendeles');

        // Idempotencia: a (forras, tranzakcio) egyedi kulcs dönt – a második beszúrás elbukik.
        $elnyom = $wpdb->suppress_errors(true);
        $be     = $wpdb->insert($tf, [
            'rendeles_id' => $id,
            'forras'      => $forras,
            'tranzakcio'  => $tranzakcio,
            'osszeg'      => $osszeg,
            'ido'         => current_time('mysql', true),
            'nyers'       => (string) wp_json_encode($nyers, JSON_UNESCAPED_UNICODE),
            'allapot'     => 'ok',
        ] + $meta);
        $wpdb->suppress_errors($elnyom);

        if (!$be) {
            SDH_Sales_Naplo::ir('fizetes', 'Már feldolgozott befizetés: ' . $forras . ' / ' . $tranzakcio . ' (' . (string) $r['azon'] . ').', 'info', 'rendeles', $id);

            return false;
        }

        $fid = (int) $wpdb->insert_id;

        // Kifizetett (vagy nem fizethető: ajánlatkérés) rendelésre érkező pénz: rögzítve, de nem indít semmit. A levél
        // ellenőrzést kér, nem visszautalást – lehet, hogy ugyanaz a jóváírás jött meg egy másik csatornán is.
        if (in_array((string) $r['allapot'], ['fizetve', 'kiszolgalva', 'visszaterites', 'ajanlat'], true) || ((int) $r['brutto'] > 0 && (int) $r['fizetett'] >= (int) $r['brutto'])) {
            $wpdb->update($tf, ['allapot' => 'tulfizetes'], ['id' => $fid]);
            $wpdb->query($wpdb->prepare("UPDATE {$tr} SET fizetett = fizetett + %d, modositva = %s WHERE id = %d", $osszeg, current_time('mysql', true), $id));
            SDH_Sales_Naplo::ir('fizetes', 'Túlfizetés (ellenőrizendő): ' . SDH_Sales::ft($osszeg) . ' érkezett a(z) ' . (string) $r['azon'] . ' rendelésre (' . $forras . ', ' . self::allapot_szoveg((string) $r['allapot']) . '). Ellenőrizendő.', 'figyel', 'rendeles', $id);
            self::admin_level('Ellenőrizendő befizetés: ' . (string) $r['azon'], 'A(z) ' . (string) $r['azon'] . ' rendelésre (' . self::allapot_szoveg((string) $r['allapot']) . ') újabb ' . SDH_Sales::ft($osszeg) . ' érkezett (' . $forras . ', ' . $tranzakcio . ").\nEllenőrizd a bankszámlán, hogy valóban két külön jóváírás történt-e. Ha igen, a többletet utald vissza vagy írd jóvá; ha ugyanaz a tétel két forrásból jött, nincs teendő.");

            return true;
        }

        $most = current_time('mysql', true);
        $wpdb->query($wpdb->prepare("UPDATE {$tr} SET fizetett = fizetett + %d, modositva = %s WHERE id = %d", $osszeg, $most, $id));

        // Csak az a hívás vált állapotot, amelyiknél a feltétel teljesül ÉS az állapot még nyitott (versenyhelyzet ellen).
        $nyitott = "'" . implode("','", self::NYITOTT) . "'";
        $valt    = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$tr} SET allapot = 'fizetve', fizetve_ido = %s, modositva = %s WHERE id = %d AND allapot IN ({$nyitott}) AND fizetett >= brutto",
            $most,
            $most,
            $id
        ));

        $friss = (array) self::rendeles($id);

        if ($valt === 1) {
            if ((string) $r['allapot'] === 'torolve') {
                SDH_Sales_Naplo::ir('fizetes', 'Törölt rendelésre érkezett a fizetés – a rendelés újra él: ' . (string) $r['azon'] . '.', 'figyel', 'rendeles', $id);
            }

            if ((int) $friss['fizetett'] > (int) $friss['brutto']) {
                SDH_Sales_Naplo::ir('fizetes', 'Túlfizetés: ' . (string) $r['azon'] . ' – ' . SDH_Sales::ft((int) $friss['fizetett'] - (int) $friss['brutto']) . ' többlet.', 'figyel', 'rendeles', $id);
            }

            SDH_Sales_Naplo::ir('fizetes', 'Fizetve: ' . (string) $r['azon'] . ' – ' . SDH_Sales::ft((int) $friss['fizetett']) . ' (' . $forras . ').', 'siker', 'rendeles', $id, ['allapot' => $r['allapot'], 'fizetett' => (int) $r['fizetett']], ['allapot' => 'fizetve', 'fizetett' => (int) $friss['fizetett']]);
            do_action('sdh_sales_rendeles_allapot', $id, (string) $r['allapot'], 'fizetve');
            do_action('sdh_sales_fizetve', $id);

            return true;
        }

        if ((int) $friss['fizetett'] < (int) $friss['brutto']) {
            SDH_Sales_Naplo::ir('fizetes', 'Részfizetés: ' . (string) $r['azon'] . ' – ' . SDH_Sales::ft($osszeg) . ' érkezett, hátralék ' . SDH_Sales::ft((int) $friss['brutto'] - (int) $friss['fizetett']) . ' (' . $forras . ').', 'figyel', 'rendeles', $id);
            do_action('sdh_sales_reszfizetes', $id, $osszeg);
        }

        return true;
    }

    /**
     * A befizetés csatornája, könyvelési napja és csatornafüggetlen lenyomata (a banki import adja `_csatorna`, `_datum`,
     * `_lenyomat` kulcsokkal a nyers adatban – ezek oszlopba kerülnek, a nyers adatból kimaradnak).
     *
     * @param array<string, mixed> $nyers
     * @return array{csatorna: string, datum: string, lenyomat: string}
     */
    private static function fizetes_meta(string $forras, array &$nyers): array
    {
        $cs = sanitize_key((string) ($nyers['_csatorna'] ?? $forras));
        $d  = (string) ($nyers['_datum'] ?? '');
        $l  = (string) ($nyers['_lenyomat'] ?? '');
        unset($nyers['_csatorna'], $nyers['_datum'], $nyers['_lenyomat']);

        return [
            'csatorna' => substr($cs !== '' ? $cs : $forras, 0, 20),
            'datum'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : wp_date('Y-m-d'),
            'lenyomat' => preg_match('/^[a-f0-9]{16,64}$/', $l) === 1 ? $l : '',
        ];
    }

    /** Nem párosítható befizetés rögzítése (rendeles_id = 0), idempotensen. */
    public static function ismeretlen_fizetes(string $forras, string $tranzakcio, int $osszeg, array $nyers = [], int $rendeles_id = 0, string $allapot = 'ismeretlen'): bool
    {
        global $wpdb;

        $meta   = self::fizetes_meta($forras, $nyers);
        $allapot = in_array($allapot, ['ismeretlen', 'jovahagyasra_var', 'ellenorizendo', 'duplikat'], true) ? $allapot : 'ismeretlen';
        $elnyom = $wpdb->suppress_errors(true);
        $ok     = $wpdb->insert(SDH_Sales_Schema::t('fizetes'), [
            'rendeles_id' => max(0, $rendeles_id),
            'forras'      => in_array($forras, self::FORRASOK, true) ? $forras : 'kezi',
            'tranzakcio'  => mb_substr($tranzakcio, 0, 120),
            'osszeg'      => $osszeg,
            'ido'         => current_time('mysql', true),
            'nyers'       => (string) wp_json_encode($nyers, JSON_UNESCAPED_UNICODE),
            'allapot'     => $allapot,
        ] + $meta);
        $wpdb->suppress_errors($elnyom);

        if ($ok) {
            $mit = ['ismeretlen' => 'Nem párosítható befizetés', 'jovahagyasra_var' => 'Kézi jóváhagyásra váró befizetés', 'ellenorizendo' => 'Ellenőrizendő befizetés', 'duplikat' => 'Már rögzített jóváírás másik forrásból'][$allapot];
            SDH_Sales_Naplo::ir('fizetes', $mit . ': ' . SDH_Sales::ft($osszeg) . ' (' . $forras . ', ' . mb_substr($tranzakcio, 0, 60) . ').', $allapot === 'duplikat' ? 'info' : 'figyel', 'fizetes', (int) $wpdb->insert_id);
        }

        return (bool) $ok;
    }

    /** Állapotváltás (nem fizetési): sikertelen, torolve, visszaterites, fizetesre_var. Feltételesen, naplózva. */
    public static function allapot_valt(int $id, string $uj, array $csak_ha = [], string $ok = ''): bool
    {
        global $wpdb;

        if (!in_array($uj, self::ALLAPOTOK, true)) {
            return false;
        }

        $r = self::rendeles($id);

        if ($r === null || ($csak_ha !== [] && !in_array((string) $r['allapot'], $csak_ha, true)) || (string) $r['allapot'] === $uj) {
            return false;
        }

        $n = (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . SDH_Sales_Schema::t('rendeles') . ' SET allapot = %s, modositva = %s WHERE id = %d AND allapot = %s',
            $uj,
            current_time('mysql', true),
            $id,
            (string) $r['allapot']
        ));

        if ($n !== 1) {
            return false;
        }

        SDH_Sales_Naplo::ir('rendeles', (string) $r['azon'] . ': ' . self::allapot_szoveg((string) $r['allapot']) . ' → ' . self::allapot_szoveg($uj) . ($ok !== '' ? ' (' . $ok . ')' : '') . '.', 'info', 'rendeles', $id, ['allapot' => $r['allapot']], ['allapot' => $uj]);
        do_action('sdh_sales_rendeles_allapot', $id, (string) $r['allapot'], $uj);

        return true;
    }

    public static function admin_level(string $targy, string $szoveg): void
    {
        $c = sanitize_email((string) SDH_Sales::b('email.ertesites', ''));

        if (!is_email($c)) {
            $c = (string) get_option('admin_email');
        }

        if (is_email($c)) {
            wp_mail($c, '[' . SDH_Sales::termeknev() . '] ' . $targy, $szoveg);
        }
    }

    /* =================================================================
     * Nyilvános állapot
     * ============================================================== */

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function nyilvanos(array $r): array
    {
        $nyitott = in_array((string) $r['allapot'], ['dijbekero', 'fizetesre_var', 'sikertelen', 'uj'], true);
        $hatra   = max(0, (int) $r['brutto'] - (int) $r['fizetett']);
        $ki      = [
            'azon'           => (string) $r['azon'],
            'allapot'        => (string) $r['allapot'],
            'allapot_szoveg' => self::allapot_szoveg((string) $r['allapot']),
            'tipus'          => (string) $r['tipus'],
            'datum'          => (string) $r['letrehozva'],
            'netto'          => (int) $r['netto'],
            'afa'            => (int) $r['afa'],
            'brutto'         => (int) $r['brutto'],
            'fizetett'       => (int) $r['fizetett'],
            'hatralek'       => $hatra,
            'fizmod'         => (string) $r['fizmod'],
            'kozlemeny'      => (string) $r['kozlemeny'],
            'hatarido'       => (string) $r['hatarido'],
            'tetelek'        => array_map(static fn ($t): array => is_array($t) ? array_intersect_key($t, array_flip(['nev', 'mennyiseg', 'netto', 'ciklus'])) : [], (array) $r['tetelek']),
            'kesz'           => in_array((string) $r['allapot'], ['fizetve', 'kiszolgalva'], true),
        ];

        if ($nyitott && $hatra > 0 && (string) $r['fizmod'] === 'atutalas') {
            $ki['atutalas'] = SDH_Sales_Fizetes_Atutalas::adatok($r);
        }

        if (class_exists('SDH_Sales_Szamlazz') && method_exists('SDH_Sales_Szamlazz', 'pdf_link')) {
            $ki['dijbekero_url'] = (string) $r['szamla_dijbekero'] !== '' ? SDH_Sales_Szamlazz::pdf_link((int) $r['id'], 'dijbekero') : '';
            $ki['szamla_url']    = (string) $r['szamla_vegszamla'] !== '' ? SDH_Sales_Szamlazz::pdf_link((int) $r['id'], 'vegszamla') : '';
        }

        if (class_exists('SDH_Sales_Kiszolgalas') && method_exists('SDH_Sales_Kiszolgalas', 'allapot') && $ki['kesz']) {
            $k = SDH_Sales_Kiszolgalas::allapot((int) $r['id']);

            $ki['kiszolgalas'] = is_array($k) ? ['kesz' => !empty($k['kesz']), 'lepesek' => array_map(static fn ($l): array => ['nev' => (string) ($l['nev'] ?? ''), 'allapot' => (string) ($l['allapot'] ?? '')], (array) ($k['lepesek'] ?? []))] : null;
        }

        if (in_array((string) $r['fizmod'], ['barion', 'simplepay'], true) && in_array((string) $r['allapot'], ['fizetesre_var', 'sikertelen'], true)) {
            $ki['ujraproba'] = true;
        }

        return $ki;
    }

    /* =================================================================
     * REST
     * ============================================================== */

    public static function rest_letrehoz(WP_REST_Request $q)
    {
        if (!is_user_logged_in()) {
            $v = SDH_Sales_Biztonsag::ellenoriz($q);

            if (is_wp_error($v)) {
                return $v;
            }
        }

        $be = [
            'tetelek'      => $q->get_param('tetelek'),
            'opciok'       => $q->get_param('opciok'),
            'vevo'         => $q->get_param('vevo'),
            'fizmod'       => $q->get_param('fizmod'),
            'tipus'        => $q->get_param('tipus') ?: 'uj',
            'licenc_kulcs' => $q->get_param('licenc_kulcs'),
            'elfogad'      => $q->get_param('elfogad'),
            'megjegyzes'   => $q->get_param('megjegyzes'),
        ];

        if ($q->get_param('vart_brutto') !== null) {
            $be['vart_brutto'] = $q->get_param('vart_brutto');
        }

        $e = self::letrehoz($be);

        if (empty($e['ok'])) {
            return new WP_REST_Response(['ok' => false, 'code' => 'rendeles_hiba', 'message' => __('Ellenőrizd a megjelölt mezőket.', 'sdh-sales'), 'hibak' => $e['hibak'] ?? [], 'ar' => $e['ar'] ?? null, 'arvaltozas' => !empty($e['arvaltozas'])], 400);
        }

        unset($e['id']);

        return new WP_REST_Response($e, 201);
    }

    /** @return array<string, mixed>|WP_Error */
    private static function tokenes(WP_REST_Request $q)
    {
        $r = self::azon_szerint((string) $q['azon']);
        $t = (string) $q->get_param('t');

        if ($r === null || $t === '' || !hash_equals(self::allapot_token($r), $t)) {
            return SDH_Sales_Rest::hiba(__('Nincs ilyen rendelés, vagy lejárt a link.', 'sdh-sales'), 404, 'nincs');
        }

        return $r;
    }

    public static function rest_allapot(WP_REST_Request $q)
    {
        $r = self::tokenes($q);

        if (is_wp_error($r)) {
            return $r;
        }

        // Kártyás fizetésnél a visszatérő vevő oldala kérdezi le a kaput is (ha az értesítés még nem jött meg).
        if ((string) $r['allapot'] === 'fizetesre_var' && in_array((string) $r['fizmod'], ['barion', 'simplepay'], true)) {
            SDH_Sales_Fizetes::kartya_ellenoriz((int) $r['id']);
            $r = (array) self::rendeles((int) $r['id']);
        }

        return new WP_REST_Response(self::nyilvanos($r), 200);
    }

    public static function rest_qr(WP_REST_Request $q)
    {
        $r = self::tokenes($q);

        if (is_wp_error($r)) {
            return $r;
        }

        $adat = SDH_Sales_Qr::mnb((int) $r['id']);

        if ($adat === '' || !function_exists('imagecreate')) {
            return SDH_Sales_Rest::hiba(__('A QR-kód most nem érhető el.', 'sdh-sales'), 404, 'nincs');
        }

        $v = self::nyers(SDH_Sales_Qr::png($adat, 6, 4), 'image/png');
        $v->header('Cache-Control', 'private, max-age=600');

        return $v;
    }

    /** Nyers (nem JSON-kódolt) REST-válasz: PNG, vagy bájtra pontos JSON (aláírt IPN-válasz). */
    public static function nyers(string $test, string $tipus, int $kod = 200): WP_REST_Response
    {
        $v = new WP_REST_Response($test, $kod);
        $v->header('Content-Type', $tipus);
        $v->header('Content-Length', (string) strlen($test));
        self::$nyers[spl_object_id($v)] = true;

        return $v;
    }

    /** Teszthez: nyersen kiszolgálandó-e a válasz. */
    public static function nyers_e(WP_REST_Response $v): bool
    {
        return isset(self::$nyers[spl_object_id($v)]);
    }

    /** Nyers (PNG) válasz kiszolgálása – jelölő fejléc nélkül (a jelölés a válaszobjektumon). */
    public static function nyers_kiszolgal($kiszolgalva, $valasz, $keres, $szerver)
    {
        if ($kiszolgalva || !is_object($valasz) || !isset(self::$nyers[spl_object_id($valasz)]) || !is_string($valasz->get_data())) {
            return $kiszolgalva;
        }

        echo $valasz->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput -- PNG.

        return true;
    }

    public static function rest_admin_fizetve(WP_REST_Request $q)
    {
        $id = (int) $q['id'];
        $r  = self::rendeles($id);

        if ($r === null) {
            return SDH_Sales_Rest::hiba(__('Nincs ilyen rendelés.', 'sdh-sales'), 404, 'nincs');
        }

        $hatra = (int) $r['brutto'] - (int) $r['fizetett'];

        if ($hatra <= 0 && (int) $r['brutto'] > 0) {
            return SDH_Sales_Rest::hiba(__('Ez a rendelés már ki van fizetve.', 'sdh-sales'), 409, 'mar_fizetve');
        }

        $u     = wp_get_current_user();
        $osszeg = $q->get_param('osszeg') !== null ? max(0, (int) $q->get_param('osszeg')) : $hatra;
        $ok    = self::fizetve($id, 'kezi', 'kezi-' . sanitize_user((string) $u->user_login, true) . '-' . time(), $osszeg, ['admin' => (int) $u->ID, 'megjegyzes' => sanitize_text_field((string) $q->get_param('megjegyzes'))]);

        return new WP_REST_Response(['ok' => $ok, 'rendeles' => self::nyilvanos((array) self::rendeles($id))], $ok ? 200 : 409);
    }

    public static function rest_admin_torol(WP_REST_Request $q)
    {
        $id = (int) $q['id'];
        $ok = self::allapot_valt($id, 'torolve', ['uj', 'dijbekero', 'fizetesre_var', 'sikertelen'], 'adminisztrátor');

        return new WP_REST_Response(['ok' => $ok], $ok ? 200 : 409);
    }
}
