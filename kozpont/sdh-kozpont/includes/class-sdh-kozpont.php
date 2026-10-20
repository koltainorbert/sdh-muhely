<?php
/**
 * SDH Központ – mag: adatbázis, kulcspár, licencek és jogok, szívverés-feldolgozás, egészség, riasztás, műveletek, napló.
 *
 * @package SDH_Kozpont
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Kozpont
{
    public const DB_VERSION = '2.1.0';
    public const NS         = 'sdh-kozpont/v1';
    public const CRON       = 'sdh_kozpont_cron';
    private const OPT_KULCS    = 'sdh_kozpont_kulcspar';
    private const OPT_KIADAS   = 'sdh_kozpont_kiadas';
    public const OPT_BEALL     = 'sdh_kozpont_beallitas';
    public const OPT_KATALOGUS = 'sdh_kozpont_katalogus';

    /** Ennyi napig számít élőnek egy példány (a példányszám-korláthoz). */
    public const ELO_NAP = 60;

    /** Szívverés alapból ennyi másodpercenként; élő követésnél / váró műveletnél gyakrabban. */
    public const INTERVALLUM      = 300;
    public const INTERVALLUM_ELO  = 30;

    /** A 0.93–0.98-as példányok naponta jelentenek: ennyi óra után számítanak állónak. */
    public const NAPI_ORA = 26;

    /** A központból kérhető távoli műveletek (a vevő oldalán is ugyanez a lista fut). */
    public const MUVELETEK = [
        'diagnosztika'      => 'Diagnosztika most',
        'mentes'            => 'Mentés most',
        'gyorsitotar'       => 'Gyorsítótár ürítése',
        'halozat'           => 'Internetkapcsolat ellenőrzése',
        'frissites_keres'   => 'Frissítés keresése',
        'frissites_telepit' => 'Frissítés telepítése',
        'uzenet'            => 'Üzenet a vevőnek',
        'uzenet_torol'      => 'Üzenet levétele',
        'tamogatas_keres'   => 'Támogatási hozzáférés kérése',
    ];

    /** A jogok, amelyeket a licenc ad (alapból mind). */
    public const JOGOK = [
        'frissites' => 'Frissítéseket kap',
        'tamogatas' => 'Támogatás jár (belépő link)',
        'tavoli'    => 'Távoli karbantartás (mentés, diagnosztika, frissítés)',
    ];

    /** A Műhely fő moduljai – ha még egyetlen példány sem jelentette a sajátját. */
    private const KATALOGUS_ALAP = [
        'termekek' => ['Termékek és készlet', 'Értékesítés'], 'arukiadas' => ['Napi árukiadás', 'Értékesítés'], 'rendelesek' => ['Rendelések', 'Értékesítés'],
        'penztar' => ['Házipénztár', 'Pénzügy'], 'szamlazas' => ['Számlázás', 'Pénzügy'],
        'uzenetek' => ['Ügyfélüzenetek és RMA-oldal', 'Ügyfélkapcsolat'], 'sms' => ['SMS-értesítések', 'Ügyfélkapcsolat'], 'levelezes' => ['Levelezés', 'Ügyfélkapcsolat'],
        'csapat' => ['Csapat-üzenetek', 'Csapat és idő'], 'naptar' => ['Naptár', 'Csapat és idő'], 'foglalas' => ['Online időpontfoglalás', 'Csapat és idő'],
        'asszisztens' => ['AI-asszisztens', 'Mesterséges intelligencia'],
        'nyomtatas' => ['Nyomtatványok', 'Dokumentumok'], 'imei' => ['IMEI-lekérdezés', 'Dokumentumok'], 'tac' => ['TAC-adatbázis', 'Dokumentumok'],
        'matrica' => ['Címkenyomtatás', 'Dokumentumok'], 'mockup' => ['Eszköz-mockupok', 'Dokumentumok'],
        'munkalapclone' => ['Munkalapszoftver Clone (MunkaLap 3 átvétel)', 'Átállás'],
    ];

    public static function init(): void
    {
        add_action('plugins_loaded', [self::class, 'sema']);
        add_filter('cron_schedules', [self::class, 'utemezesek']);
        add_action('init', [self::class, 'cron_beallit']);
        add_action(self::CRON, [self::class, 'idozitett']);
    }

    public static function t(string $nev): string
    {
        global $wpdb;

        return $wpdb->prefix . 'sdh_kozpont_' . $nev;
    }

    public static function most(): string
    {
        return current_time('mysql');
    }

    /* =================================================================
     * Adatbázis
     * ============================================================== */

    public static function sema(): void
    {
        $regi = (string) get_option('sdh_kozpont_db', '');

        if ($regi === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();

        dbDelta('CREATE TABLE ' . self::t('vevo') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            nev varchar(190) NOT NULL default '',
            kapcsolattarto varchar(190) NOT NULL default '',
            email varchar(190) NOT NULL default '',
            telefon varchar(60) NOT NULL default '',
            cim varchar(255) NOT NULL default '',
            adoszam varchar(40) NOT NULL default '',
            weboldal varchar(255) NOT NULL default '',
            cimkek varchar(255) NOT NULL default '',
            allapot varchar(20) NOT NULL default 'aktiv',
            megjegyzes text NULL,
            letrehozva datetime NULL,
            modositva datetime NULL,
            PRIMARY KEY  (id),
            key nev (nev)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('licenc') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            kulcs varchar(40) NOT NULL default '',
            vevo_id bigint(20) unsigned NOT NULL default 0,
            vevo varchar(190) NOT NULL default '',
            vevo_azonosito varchar(60) NOT NULL default '',
            csomag varchar(60) NOT NULL default '',
            modulok text NULL,
            jogok text NULL,
            lejar date NULL,
            max_peldany int(11) NOT NULL default 1,
            max_felhasznalo int(11) NOT NULL default 0,
            proba tinyint(1) NOT NULL default 0,
            dij int(11) NOT NULL default 0,
            ciklus varchar(12) NOT NULL default 'eves',
            esedekes date NULL,
            csatorna varchar(12) NOT NULL default 'stabil',
            allapot varchar(12) NOT NULL default 'aktiv',
            uzenet varchar(255) NOT NULL default '',
            megjegyzes text NULL,
            letrehozva datetime NULL,
            modositva datetime NULL,
            PRIMARY KEY  (id),
            unique key kulcs (kulcs),
            key vevo_id (vevo_id)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('peldany') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            licenc_id bigint(20) unsigned NOT NULL default 0,
            peldany varchar(40) NOT NULL default '',
            ujjlenyomat varchar(40) NOT NULL default '',
            verzio varchar(20) NOT NULL default '',
            elso datetime NULL,
            utolso datetime NULL,
            jelentes int(11) NOT NULL default 0,
            cimke varchar(120) NOT NULL default '',
            jegyzet text NULL,
            utolso_jel datetime NULL,
            jel_db int(11) NOT NULL default 0,
            allapot longtext NULL,
            egeszseg varchar(10) NOT NULL default '',
            problema text NULL,
            valasz_ms int(11) NOT NULL default 0,
            tavoli tinyint(1) NOT NULL default 0,
            elo_ig datetime NULL,
            offline_jelezve tinyint(1) NOT NULL default 0,
            PRIMARY KEY  (id),
            unique key peldany (peldany),
            key licenc_id (licenc_id)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('meres') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            peldany_id bigint(20) unsigned NOT NULL default 0,
            ido datetime NULL,
            ora tinyint(1) NOT NULL default 0,
            valasz_ms int(11) NOT NULL default 0,
            p95_ms int(11) NOT NULL default 0,
            db_ms int(11) NOT NULL default 0,
            mem_mb int(11) NOT NULL default 0,
            db_mb int(11) NOT NULL default 0,
            lemez_mb int(11) NOT NULL default 0,
            hiba int(11) NOT NULL default 0,
            aktiv int(11) NOT NULL default 0,
            keres int(11) NOT NULL default 0,
            munkalap int(11) NOT NULL default 0,
            PRIMARY KEY  (id),
            key peldany_ido (peldany_id, ido)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('esemeny') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            ido datetime NULL,
            tipus varchar(20) NOT NULL default '',
            szint varchar(8) NOT NULL default 'info',
            vevo_id bigint(20) unsigned NOT NULL default 0,
            licenc_id bigint(20) unsigned NOT NULL default 0,
            peldany_id bigint(20) unsigned NOT NULL default 0,
            szoveg varchar(500) NOT NULL default '',
            ki varchar(120) NOT NULL default '',
            PRIMARY KEY  (id),
            key ido (ido),
            key vevo_id (vevo_id),
            key peldany_id (peldany_id)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('muvelet') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            peldany_id bigint(20) unsigned NOT NULL default 0,
            tipus varchar(30) NOT NULL default '',
            parameter text NULL,
            allapot varchar(10) NOT NULL default 'var',
            letrehozva datetime NULL,
            kuldve datetime NULL,
            kesz datetime NULL,
            lejar datetime NULL,
            eredmeny mediumtext NULL,
            ki varchar(120) NOT NULL default '',
            PRIMARY KEY  (id),
            key peldany_id (peldany_id)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('tamogatas') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            licenc_id bigint(20) unsigned NOT NULL default 0,
            peldany varchar(40) NOT NULL default '',
            link varchar(500) NOT NULL default '',
            lejar datetime NULL,
            ki varchar(120) NOT NULL default '',
            erkezett datetime NULL,
            PRIMARY KEY  (id),
            key peldany (peldany)
        ) {$c};");

        dbDelta('CREATE TABLE ' . self::t('kiadas') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            verzio varchar(20) NOT NULL default '',
            fajl varchar(500) NOT NULL default '',
            meret bigint(20) NOT NULL default 0,
            sha256 varchar(64) NOT NULL default '',
            csatorna varchar(12) NOT NULL default 'stabil',
            megjegyzes text NULL,
            ido datetime NULL,
            ki varchar(120) NOT NULL default '',
            PRIMARY KEY  (id)
        ) {$c};");

        // 2.1: kiszolgálás (rendelések, egyszer használható letöltés, nonce).
        if (class_exists('SDH_Kozpont_Kiszolgalas')) {
            SDH_Kozpont_Kiszolgalas::sema($c);
        }

        if ($regi !== '' && version_compare($regi, '2.0.0', '<')) {
            self::atallas_v2();
        }

        update_option('sdh_kozpont_db', self::DB_VERSION);
    }

    /** 1.0 → 2.0: a licencek vevőnevéből vevő lesz, a régi kiadás a kiadások közé kerül. */
    private static function atallas_v2(): void
    {
        global $wpdb;

        $most = self::most();

        foreach ((array) $wpdb->get_results('SELECT id, vevo FROM ' . self::t('licenc') . ' WHERE vevo_id = 0') as $l) {
            $nev = trim((string) $l->vevo) !== '' ? trim((string) $l->vevo) : 'Névtelen vevő';
            $vid = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t('vevo') . ' WHERE nev = %s', $nev));

            if ($vid === 0) {
                $wpdb->insert(self::t('vevo'), ['nev' => $nev, 'allapot' => 'aktiv', 'letrehozva' => $most, 'modositva' => $most]);
                $vid = (int) $wpdb->insert_id;
            }

            $wpdb->update(self::t('licenc'), ['vevo_id' => $vid], ['id' => (int) $l->id]);
        }

        $k = get_option(self::OPT_KIADAS, []);

        if (is_array($k) && !empty($k['verzio']) && !empty($k['fajl']) && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::t('kiadas')) === 0) {
            $f = (string) $k['fajl'];
            $wpdb->insert(self::t('kiadas'), [
                'verzio' => (string) $k['verzio'], 'fajl' => $f, 'meret' => is_file($f) ? (int) filesize($f) : 0,
                'sha256' => is_file($f) ? (string) hash_file('sha256', $f) : '', 'csatorna' => 'stabil',
                'ido' => (string) ($k['ido'] ?: $most), 'megjegyzes' => '',
            ]);
        }

        self::naplo('rendszer', 'A Központ 2.0-ra frissült: vevők és kiadások átvéve.');
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    /** @return array<string, mixed> */
    public static function beallitas(): array
    {
        $m = get_option(self::OPT_BEALL, []);
        $m = is_array($m) ? $m : [];

        $alap = [
            'offline_perc'   => 15,
            'valasz_figyel'  => 1200,
            'valasz_hiba'    => 3000,
            'mentes_ora'     => 26,
            'lemez_gb'       => 2,
            'meres_nap'      => 90,
            'belepeskor'     => 1,
            'email_riasztas' => 1,
            'email_cim'      => '',
            'ertekesito_kulcs' => '',
            'ertekesito_ip'    => '',
            'termek_nev'       => '',
            'kulcs_elotag'     => '',
            'csomagok'       => [
                ['kulcs' => 'teljes', 'nev' => 'Teljes', 'modulok' => '*', 'max_peldany' => 1, 'max_felhasznalo' => 0],
                ['kulcs' => 'alap', 'nev' => 'Alap', 'modulok' => ['uzenetek', 'nyomtatas', 'matrica', 'tac'], 'max_peldany' => 1, 'max_felhasznalo' => 3],
            ],
        ];

        $b = $m + $alap;

        foreach (['offline_perc' => [5, 1440], 'valasz_figyel' => [100, 60000], 'valasz_hiba' => [200, 120000], 'mentes_ora' => [1, 720], 'lemez_gb' => [0, 1000], 'meres_nap' => [7, 730]] as $k => [$min, $max]) {
            $b[$k] = max($min, min($max, (int) $b[$k]));
        }

        $b['belepeskor']     = (int) !empty($b['belepeskor']);
        $b['email_riasztas'] = (int) !empty($b['email_riasztas']);
        $b['email_cim']      = sanitize_email((string) $b['email_cim']);
        $b['ertekesito_kulcs'] = (string) $b['ertekesito_kulcs'];
        $b['ertekesito_ip']    = (string) $b['ertekesito_ip'];
        $b['termek_nev']       = mb_substr(sanitize_text_field((string) $b['termek_nev']), 0, 60);
        $b['kulcs_elotag']     = self::elotag_tisztit((string) $b['kulcs_elotag']);
        $b['csomagok']       = is_array($b['csomagok']) ? array_values($b['csomagok']) : $alap['csomagok'];

        return $b;
    }

    /**
     * @param array<string, mixed> $be
     * @return array<string, mixed>
     */
    public static function beallitas_ment(array $be): array
    {
        $b = self::beallitas();

        foreach (['offline_perc', 'valasz_figyel', 'valasz_hiba', 'mentes_ora', 'lemez_gb', 'meres_nap'] as $k) {
            if (isset($be[$k])) {
                $b[$k] = (int) $be[$k];
            }
        }

        foreach (['belepeskor', 'email_riasztas'] as $k) {
            if (array_key_exists($k, $be)) {
                $b[$k] = (int) !empty($be[$k]);
            }
        }

        if (isset($be['email_cim'])) {
            $b['email_cim'] = sanitize_email((string) $be['email_cim']);
        }

        // 2.1: az értékesítő webhely nyilvános kulcsa (érvénytelen kulcs nem kerül be) és az engedélyezett IP-címek.
        if (isset($be['ertekesito_kulcs']) && class_exists('SDH_Kozpont_Kiszolgalas')) {
            $k = SDH_Kozpont_Kiszolgalas::kulcs_tisztit((string) $be['ertekesito_kulcs']);

            if ($k !== null && $k !== $b['ertekesito_kulcs']) {
                $b['ertekesito_kulcs'] = $k;
                self::naplo('beallitas', $k === '' ? 'Az értékesítő webhely kulcsa törölve.' : 'Az értékesítő webhely kulcsa beállítva.', 'figyel', [], (string) wp_get_current_user()->display_name);
            }
        }

        // 2.1: a vevőknek látszó terméknév és a licenckulcs előtagja (üresen a terméknévből, végső esetben „LIC”).
        if (isset($be['termek_nev'])) {
            $b['termek_nev'] = mb_substr(sanitize_text_field((string) $be['termek_nev']), 0, 60);
        }

        if (isset($be['kulcs_elotag'])) {
            $b['kulcs_elotag'] = self::elotag_tisztit((string) $be['kulcs_elotag']);
        }

        if (isset($be['ertekesito_ip']) && class_exists('SDH_Kozpont_Kiszolgalas')) {
            $b['ertekesito_ip'] = SDH_Kozpont_Kiszolgalas::ip_tisztit((string) $be['ertekesito_ip']);
        }

        if (isset($be['csomagok']) && is_array($be['csomagok'])) {
            $cs = [];

            foreach ($be['csomagok'] as $c) {
                if (!is_array($c) || trim((string) ($c['nev'] ?? '')) === '') {
                    continue;
                }

                $nev  = mb_substr(sanitize_text_field((string) $c['nev']), 0, 60);
                $cs[] = [
                    'kulcs'           => sanitize_key((string) ($c['kulcs'] ?? '')) ?: sanitize_title($nev),
                    'nev'             => $nev,
                    'modulok'         => self::modulok_tisztit($c['modulok'] ?? '*'),
                    'max_peldany'     => max(1, min(99, (int) ($c['max_peldany'] ?? 1))),
                    'max_felhasznalo' => max(0, min(999, (int) ($c['max_felhasznalo'] ?? 0))),
                ];
            }

            $b['csomagok'] = $cs;
        }

        update_option(self::OPT_BEALL, $b, false);

        return self::beallitas();
    }

    /** @return array<string, array{cim: string, csoport: string}> */
    public static function katalogus(): array
    {
        $ki = [];

        foreach (self::KATALOGUS_ALAP as $k => [$cim, $cs]) {
            $ki[$k] = ['cim' => $cim, 'csoport' => $cs];
        }

        $jelentett = get_option(self::OPT_KATALOGUS, []);

        foreach (is_array($jelentett) ? $jelentett : [] as $k => $m) {
            if (is_array($m) && preg_match('/^[a-z0-9_]{2,40}$/', (string) $k) === 1) {
                $ki[(string) $k] = ['cim' => mb_substr((string) ($m['cim'] ?? $k), 0, 80), 'csoport' => mb_substr((string) ($m['csoport'] ?? ''), 0, 60)];
            }
        }

        return $ki;
    }

    /**
     * '*' (minden modul) vagy a modulkulcsok listája.
     *
     * @param mixed $m
     * @return string|array<int, string>
     */
    public static function modulok_tisztit($m)
    {
        if ($m === '*' || $m === null || $m === '') {
            return '*';
        }

        if (is_string($m)) {
            $d = json_decode($m, true);
            $m = is_array($d) ? $d : explode(',', $m);
        }

        $ki = [];

        foreach ((array) $m as $k) {
            $k = sanitize_key((string) $k);

            if ($k !== '' && strlen($k) <= 40) {
                $ki[] = $k;
            }
        }

        return array_values(array_unique($ki));
    }

    /* =================================================================
     * Kulcspár, aláírás
     * ============================================================== */

    /** @return array{titkos: string, nyilvanos: string} nyers bájtok */
    public static function kulcspar(): array
    {
        $m = get_option(self::OPT_KULCS);

        if (!is_array($m) || empty($m['titkos']) || empty($m['nyilvanos'])) {
            $kp = sodium_crypto_sign_keypair();
            $m  = ['titkos' => base64_encode(sodium_crypto_sign_secretkey($kp)), 'nyilvanos' => base64_encode(sodium_crypto_sign_publickey($kp))];
            update_option(self::OPT_KULCS, $m, false);
        }

        return ['titkos' => (string) base64_decode((string) $m['titkos'], true), 'nyilvanos' => (string) base64_decode((string) $m['nyilvanos'], true)];
    }

    /**
     * @param array<string, mixed> $adat
     * @return array{adat: string, alairas: string, kozpont_kulcs: string}
     */
    public static function alair(array $adat): array
    {
        $kp  = self::kulcspar();
        $raw = (string) wp_json_encode($adat);

        return [
            'adat'          => base64_encode($raw),
            'alairas'       => base64_encode(sodium_crypto_sign_detached($raw, $kp['titkos'])),
            'kozpont_kulcs' => base64_encode($kp['nyilvanos']),
        ];
    }

    /* =================================================================
     * Licenc
     * ============================================================== */

    /** Licenckulcs-előtag: nagybetű, ékezet nélkül, 2–10 karakter (A–Z, 0–9); érvénytelennél üres. */
    public static function elotag_tisztit(string $e): string
    {
        $e = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', remove_accents($e)));

        return strlen($e) >= 2 ? substr($e, 0, 10) : '';
    }

    /** Az új licenckulcsok előtagja: a beállítás, különben a terméknévből, különben „LIC”. (A régi kulcsok érvényesek maradnak.) */
    public static function kulcs_elotag(): string
    {
        $b = self::beallitas();

        if ($b['kulcs_elotag'] !== '') {
            return $b['kulcs_elotag'];
        }

        $e = self::elotag_tisztit(self::termek_nev());

        return $e !== '' && stripos($e, 'SDH') === false ? $e : 'LIC';
    }

    /** A vevőknek látszó terméknév (letöltött fájl neve); üresen semleges. */
    public static function termek_nev(): string
    {
        $n = self::beallitas()['termek_nev'];

        // Ugyanazon a webhelyen futó értékesítő oldal terméknevét veszi át, ha itt nincs megadva.
        if ($n === '' && class_exists('SDH_Sales') && method_exists('SDH_Sales', 'termeknev')) {
            $n = (string) SDH_Sales::termeknev();
        }

        return $n;
    }

    public static function uj_kulcs(): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $r   = [];

        for ($i = 0; $i < 4; $i++) {
            $d = '';

            for ($j = 0; $j < 4; $j++) {
                $d .= $abc[random_int(0, strlen($abc) - 1)];
            }

            $r[] = $d;
        }

        return self::kulcs_elotag() . '-' . implode('-', $r);
    }

    public static function licenc(string $kulcs): ?object
    {
        global $wpdb;

        $l = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('licenc') . ' WHERE kulcs = %s', strtoupper(trim($kulcs))));

        return $l ?: null;
    }

    /** A licenc tényleges állapota ma: aktiv / lejart / tiltva. */
    public static function licenc_allapot(object $l, string $ma): string
    {
        if ((string) $l->allapot === 'tiltva') {
            return 'tiltva';
        }

        if (!empty($l->lejar) && (string) $l->lejar < $ma) {
            return 'lejart';
        }

        return 'aktiv';
    }

    /**
     * A licenc jogai (hiányzó kulcs = engedélyezve).
     *
     * @return array<string, bool>
     */
    public static function jogok(object $l): array
    {
        $j = json_decode((string) ($l->jogok ?? ''), true);
        $j = is_array($j) ? $j : [];
        $ki = [];

        foreach (array_keys(self::JOGOK) as $k) {
            $ki[$k] = !array_key_exists($k, $j) || !empty($j[$k]);
        }

        return $ki;
    }

    /** @return string|array<int, string> */
    public static function licenc_modulok(object $l)
    {
        return self::modulok_tisztit((string) ($l->modulok ?? ''));
    }

    /**
     * Licenc létrehozása / módosítása. Siker: a licenc azonosítója, hiba: üzenet.
     *
     * @param array<string, mixed> $be
     * @return int|string
     */
    public static function licenc_ment(array $be, string $ki = '')
    {
        global $wpdb;

        $id  = (int) ($be['id'] ?? 0);
        $vid = (int) ($be['vevo_id'] ?? 0);

        if ($vid <= 0 && trim((string) ($be['vevo_nev'] ?? '')) !== '') {
            $v = self::vevo_ment(['nev' => (string) $be['vevo_nev']], $ki);
            $vid = is_int($v) ? $v : 0;
        }

        $vevo = $vid > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('vevo') . ' WHERE id = %d', $vid)) : null;

        if ($vevo === null) {
            return 'Válassz vevőt (vagy adj meg újat).';
        }

        $datum = static function ($d): ?string {
            $d = trim((string) $d);

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) ? $d : null;
        };

        $jogok = [];

        foreach (array_keys(self::JOGOK) as $k) {
            $jogok[$k] = isset($be['jogok']) && is_array($be['jogok']) ? (int) !empty($be['jogok'][$k]) : 1;
        }

        $mod  = self::modulok_tisztit($be['modulok'] ?? '*');
        $adat = [
            'vevo_id'         => $vid,
            'vevo'            => (string) $vevo->nev,
            'vevo_azonosito'  => mb_substr(sanitize_text_field((string) ($be['vevo_azonosito'] ?? '')), 0, 60),
            'csomag'          => mb_substr(sanitize_text_field((string) ($be['csomag'] ?? '')), 0, 60),
            'modulok'         => $mod === '*' ? null : (string) wp_json_encode($mod),
            'jogok'           => (string) wp_json_encode($jogok),
            'lejar'           => $datum($be['lejar'] ?? ''),
            'max_peldany'     => max(1, min(99, (int) ($be['max_peldany'] ?? 1))),
            'max_felhasznalo' => max(0, min(999, (int) ($be['max_felhasznalo'] ?? 0))),
            'proba'           => (int) !empty($be['proba']),
            'dij'             => max(0, min(100000000, (int) ($be['dij'] ?? 0))),
            'ciklus'          => in_array((string) ($be['ciklus'] ?? ''), ['havi', 'eves', 'egyszeri'], true) ? (string) $be['ciklus'] : 'eves',
            'esedekes'        => $datum($be['esedekes'] ?? ''),
            'csatorna'        => (string) ($be['csatorna'] ?? '') === 'teszt' ? 'teszt' : 'stabil',
            'allapot'         => (string) ($be['allapot'] ?? '') === 'tiltva' ? 'tiltva' : 'aktiv',
            'uzenet'          => mb_substr(sanitize_text_field((string) ($be['uzenet'] ?? '')), 0, 255),
            'megjegyzes'      => sanitize_textarea_field((string) ($be['megjegyzes'] ?? '')),
            'modositva'       => self::most(),
        ];

        if ($id > 0) {
            $regi = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('licenc') . ' WHERE id = %d', $id));

            if ($regi === null) {
                return 'Ez a licenc nincs meg.';
            }

            $wpdb->update(self::t('licenc'), $adat, ['id' => $id]);
            $valt = [];

            foreach (['allapot' => 'állapot', 'lejar' => 'lejárat', 'max_peldany' => 'példányszám', 'max_felhasznalo' => 'felhasználószám', 'modulok' => 'modulok', 'jogok' => 'jogok', 'csatorna' => 'csatorna', 'dij' => 'díj', 'csomag' => 'csomag'] as $k => $nev) {
                if ((string) ($regi->$k ?? '') !== (string) ($adat[$k] ?? '')) {
                    $valt[] = $nev;
                }
            }

            if ($valt !== []) {
                self::naplo('licenc', 'Licenc módosítva (' . (string) $regi->kulcs . '): ' . implode(', ', $valt) . '.', 'info', ['vevo_id' => $vid, 'licenc_id' => $id], $ki);
            }

            return $id;
        }

        $kulcs = self::uj_kulcs();
        $wpdb->insert(self::t('licenc'), $adat + ['kulcs' => $kulcs, 'letrehozva' => self::most()]);
        $id = (int) $wpdb->insert_id;
        self::naplo('licenc', 'Új licenc: ' . $kulcs . ($adat['csomag'] !== '' ? ' (' . $adat['csomag'] . ')' : '') . '.', 'info', ['vevo_id' => $vid, 'licenc_id' => $id], $ki);

        return $id;
    }

    /** Gyors licencműveletek: megujit (+1 ciklus) / tilt / enged / ujkulcs. Hiba: üzenet, siker: ''. */
    public static function licenc_muvelet(int $id, string $m, string $ki = ''): string
    {
        global $wpdb;

        $l = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('licenc') . ' WHERE id = %d', $id));

        if ($l === null) {
            return 'Ez a licenc nincs meg.';
        }

        $ids = ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => $id];

        switch ($m) {
            case 'megujit':
                $ho   = (string) $l->ciklus === 'havi' ? 1 : 12;
                $alap = !empty($l->lejar) && (string) $l->lejar > wp_date('Y-m-d') ? (string) $l->lejar : wp_date('Y-m-d');
                $uj   = wp_date('Y-m-d', (int) strtotime($alap . ' +' . $ho . ' month'));
                $wpdb->update(self::t('licenc'), ['lejar' => $uj, 'esedekes' => $uj, 'allapot' => 'aktiv', 'proba' => 0, 'modositva' => self::most()], ['id' => $id]);
                self::naplo('licenc', 'Licenc megújítva ' . $uj . '-ig (' . (string) $l->kulcs . ').', 'info', $ids, $ki);

                return '';
            case 'tilt':
            case 'enged':
                $wpdb->update(self::t('licenc'), ['allapot' => $m === 'tilt' ? 'tiltva' : 'aktiv', 'modositva' => self::most()], ['id' => $id]);
                self::naplo('licenc', 'Licenc ' . ($m === 'tilt' ? 'letiltva' : 'újra engedélyezve') . ' (' . (string) $l->kulcs . ').', $m === 'tilt' ? 'figyel' : 'info', $ids, $ki);

                return '';
            case 'ujkulcs':
                $uj = self::uj_kulcs();
                $wpdb->update(self::t('licenc'), ['kulcs' => $uj, 'modositva' => self::most()], ['id' => $id]);
                self::naplo('licenc', 'Új kulcs: ' . (string) $l->kulcs . ' → ' . $uj . '. A vevőnél a kulcsot cserélni kell.', 'figyel', $ids, $ki);

                return '';
        }

        return 'Ismeretlen művelet.';
    }

    /* =================================================================
     * Vevő
     * ============================================================== */

    /**
     * @param array<string, mixed> $be
     * @return int|string
     */
    public static function vevo_ment(array $be, string $ki = '')
    {
        global $wpdb;

        $nev = mb_substr(sanitize_text_field((string) ($be['nev'] ?? '')), 0, 190);

        if ($nev === '') {
            return 'A vevő neve kötelező.';
        }

        $adat = [
            'nev'            => $nev,
            'kapcsolattarto' => mb_substr(sanitize_text_field((string) ($be['kapcsolattarto'] ?? '')), 0, 190),
            'email'          => mb_substr(sanitize_email((string) ($be['email'] ?? '')), 0, 190),
            'telefon'        => mb_substr(sanitize_text_field((string) ($be['telefon'] ?? '')), 0, 60),
            'cim'            => mb_substr(sanitize_text_field((string) ($be['cim'] ?? '')), 0, 255),
            'adoszam'        => mb_substr(sanitize_text_field((string) ($be['adoszam'] ?? '')), 0, 40),
            'weboldal'       => mb_substr(esc_url_raw((string) ($be['weboldal'] ?? '')), 0, 255),
            'cimkek'         => mb_substr(implode(', ', array_filter(array_map('trim', explode(',', sanitize_text_field((string) ($be['cimkek'] ?? '')))))), 0, 255),
            'allapot'        => in_array((string) ($be['allapot'] ?? ''), ['erdeklodo', 'aktiv', 'szunetel', 'volt'], true) ? (string) $be['allapot'] : 'aktiv',
            'megjegyzes'     => sanitize_textarea_field((string) ($be['megjegyzes'] ?? '')),
            'modositva'      => self::most(),
        ];

        $id = (int) ($be['id'] ?? 0);

        if ($id > 0) {
            if ($wpdb->update(self::t('vevo'), $adat, ['id' => $id]) === false) {
                return 'A vevő nem menthető.';
            }

            $wpdb->update(self::t('licenc'), ['vevo' => $nev], ['vevo_id' => $id]);

            return $id;
        }

        $wpdb->insert(self::t('vevo'), $adat + ['letrehozva' => self::most()]);
        $id = (int) $wpdb->insert_id;
        self::naplo('vevo', 'Új vevő: ' . $nev . '.', 'info', ['vevo_id' => $id], $ki);

        return $id;
    }

    /* =================================================================
     * Kiadás
     * ============================================================== */

    /** Az aktuális (stabil) kiadás – a régi felület és a tesztek kedvéért. */
    public static function kiadas(string $csatorna = 'stabil'): array
    {
        global $wpdb;

        $sorok = (array) $wpdb->get_results('SELECT * FROM ' . self::t('kiadas') . ($csatorna === 'stabil' ? " WHERE csatorna = 'stabil'" : ''));
        $leg   = null;

        foreach ($sorok as $s) {
            if ($leg === null || version_compare((string) $s->verzio, (string) $leg->verzio, '>') || ((string) $s->verzio === (string) $leg->verzio && (int) $s->id > (int) $leg->id)) {
                $leg = $s;
            }
        }

        return $leg === null
            ? ['verzio' => '', 'fajl' => '', 'ido' => '', 'id' => 0]
            : ['verzio' => (string) $leg->verzio, 'fajl' => (string) $leg->fajl, 'ido' => (string) $leg->ido, 'id' => (int) $leg->id];
    }

    /** A licenc csatornájának kiadása (teszt csatornán a legújabb bármelyik). */
    public static function kiadas_licenchez(object $l): array
    {
        return self::kiadas((string) ($l->csatorna ?? '') === 'teszt' ? 'teszt' : 'stabil');
    }

    /** A kiadás eltárolása. Hibánál üzenet. */
    public static function kiadas_be(string $tmp, string $csatorna = 'stabil', string $megjegyzes = '', string $ki = ''): string
    {
        global $wpdb;

        if (!is_file($tmp) || !class_exists('ZipArchive')) {
            return 'A fájl nem olvasható.';
        }

        $z = new ZipArchive();

        if ($z->open($tmp) !== true) {
            return 'Ez nem zip-fájl.';
        }

        $fej = (string) $z->getFromName('sdh-muhely/sdh-muhely.php');
        $z->close();

        if (preg_match('/^[ \t\/*#@]*Version:\s*([0-9]+(?:\.[0-9]+){1,3})\s*$/mi', substr($fej, 0, 4096), $m) !== 1) {
            return 'A zipben nincs sdh-muhely/sdh-muhely.php verziófejléccel.';
        }

        $up    = wp_upload_dir();
        $mappa = trailingslashit($up['basedir']) . 'sdh-kozpont';
        wp_mkdir_p($mappa);

        if (!is_file($mappa . '/.htaccess')) {
            file_put_contents($mappa . '/.htaccess', "Require all denied\nDeny from all\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
            file_put_contents($mappa . '/index.php', "<?php\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        $cel = $mappa . '/sdh-muhely-' . $m[1] . '-' . wp_generate_password(12, false, false) . '.zip';

        if (!copy($tmp, $cel)) {
            return 'A fájl nem menthető.';
        }

        $csatorna = $csatorna === 'teszt' ? 'teszt' : 'stabil';

        $wpdb->insert(self::t('kiadas'), [
            'verzio' => $m[1], 'fajl' => $cel, 'meret' => (int) filesize($cel), 'sha256' => (string) hash_file('sha256', $cel),
            'csatorna' => $csatorna, 'megjegyzes' => sanitize_textarea_field($megjegyzes), 'ido' => self::most(), 'ki' => $ki,
        ]);

        update_option(self::OPT_KIADAS, ['verzio' => $m[1], 'fajl' => $cel, 'ido' => self::most()], false);
        self::naplo('kiadas', 'Új kiadás: ' . $m[1] . ' (' . ($csatorna === 'teszt' ? 'teszt' : 'stabil') . ' csatorna).', 'info', [], $ki);

        return '';
    }

    /* =================================================================
     * Szívverés és jelentés
     * ============================================================== */

    /**
     * A régi (napi) jelentés feldolgozása – a 0.93–0.98-as példányok ezt hívják.
     *
     * @param array<string, mixed> $be
     * @return array<string, mixed>
     */
    public static function jelentes_feldolgoz(array $be, ?int $most = null): array
    {
        unset($be['allapot'], $be['meres'], $be['eredmenyek']);

        return self::szivveres_feldolgoz($be, $most, false);
    }

    /**
     * A szívverés feldolgozása (a REST-végpont és a teszt is ezt hívja).
     *
     * @param array<string, mixed> $be
     * @return array<string, mixed> a (még aláíratlan) válaszadat
     */
    public static function szivveres_feldolgoz(array $be, ?int $most = null, bool $szivveres = true): array
    {
        global $wpdb;

        $most    = $most ?? time();
        $peldany = preg_match('/^[a-f0-9\-]{36}$/', (string) ($be['peldany'] ?? '')) === 1 ? (string) $be['peldany'] : '';
        $ki      = ['peldany' => $peldany, 'ido' => $most, 'allapot' => 'ismeretlen', 'lejar' => '', 'vevo' => '', 'uzenet' => '', 'verzio' => ''];
        $l       = $peldany !== '' ? self::licenc((string) ($be['kulcs'] ?? '')) : null;

        if ($l === null) {
            return $ki;
        }

        $ma      = wp_date('Y-m-d', $most);
        $mysql   = wp_date('Y-m-d H:i:s', $most);
        $sor     = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('peldany') . ' WHERE peldany = %s', $peldany));
        $allapot = self::licenc_allapot($l, $ma);

        if ($sor === null || (int) $sor->licenc_id !== (int) $l->id) {
            $hatar = wp_date('Y-m-d H:i:s', $most - self::ELO_NAP * DAY_IN_SECONDS);
            $elo   = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t('peldany') . ' WHERE licenc_id = %d AND utolso >= %s AND peldany <> %s', (int) $l->id, $hatar, $peldany));

            if ($elo >= max(1, (int) $l->max_peldany)) {
                $ki['allapot'] = 'tul_sok';
                $ki['vevo']    = (string) $l->vevo;

                if ($szivveres && ($sor === null || (int) $sor->licenc_id !== (int) $l->id)) {
                    self::naplo_ritkan('tul_sok_' . $peldany, 'licenc', 'Túl sok példány: egy újabb gép próbált csatlakozni a(z) ' . (string) $l->kulcs . ' kulccsal.', 'figyel', ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => (int) $l->id]);
                }

                return $ki;
            }
        }

        $adat = [
            'licenc_id'   => (int) $l->id,
            'ujjlenyomat' => substr(preg_replace('/[^a-f0-9]/', '', (string) ($be['ujjlenyomat'] ?? '')) ?? '', 0, 40),
            'verzio'      => substr(preg_replace('/[^0-9.]/', '', (string) ($be['verzio'] ?? '')) ?? '', 0, 20),
            'utolso'      => $mysql,
        ];

        $snap = $szivveres && isset($be['allapot']) && is_array($be['allapot']) ? self::tisztit($be['allapot'], 0) : null;
        $mer  = $szivveres && isset($be['meres']) && is_array($be['meres']) ? $be['meres'] : [];

        if ($snap !== null) {
            $json = (string) wp_json_encode($snap);

            if (strlen($json) > 200000) {
                unset($snap['hibak'], $snap['bovitmenyek']);
                $json = (string) wp_json_encode($snap);
            }

            $adat['allapot']    = $json;
            $adat['utolso_jel'] = $mysql;
            $adat['valasz_ms']  = max(0, (int) ($mer['valasz_ms'] ?? 0));
            $adat['tavoli']     = (int) !empty($snap['muhely']['tavoli']);
            $adat['offline_jelezve'] = 0;
        }

        $uj = $sor === null;

        if ($uj) {
            $wpdb->insert(self::t('peldany'), $adat + ['peldany' => $peldany, 'elso' => $mysql, 'jelentes' => 1, 'jel_db' => $snap !== null ? 1 : 0]);
            $pid = (int) $wpdb->insert_id;
            self::naplo('peldany', 'Új rendszer csatlakozott (' . (string) $l->vevo . ', ' . $adat['verzio'] . ').', 'info', ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => (int) $l->id, 'peldany_id' => $pid]);
        } else {
            $pid = (int) $sor->id;
            $wpdb->update(self::t('peldany'), $adat + ['jelentes' => (int) $sor->jelentes + 1, 'jel_db' => (int) $sor->jel_db + ($snap !== null ? 1 : 0)], ['id' => $pid]);

            if ((string) $sor->verzio !== '' && $adat['verzio'] !== '' && (string) $sor->verzio !== $adat['verzio']) {
                self::naplo('peldany', 'Verzióváltás: ' . (string) $sor->verzio . ' → ' . $adat['verzio'] . '.', 'info', ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => (int) $l->id, 'peldany_id' => $pid]);
            }

            if ((int) $sor->offline_jelezve === 1 && $snap !== null) {
                self::naplo('peldany', 'A rendszer újra jelentkezik.', 'info', ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => (int) $l->id, 'peldany_id' => $pid]);
            }
        }

        $nyugta = [];

        if ($snap !== null) {
            self::meres_rogzit($pid, $mer, $most);
            self::katalogus_tanul($snap);
            $nyugta = self::eredmenyek_be($pid, (array) ($be['eredmenyek'] ?? []), $l);

            $sor2 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('peldany') . ' WHERE id = %d', $pid));
            $e    = self::egeszseg($snap, $l, $most);
            self::problemak_valtozasa($sor2, $l, $e['problemak']);
            $wpdb->update(self::t('peldany'), ['egeszseg' => $e['szint'], 'problema' => (string) wp_json_encode($e['problemak'])], ['id' => $pid]);
        }

        $kiadas = self::kiadas_licenchez($l);
        $jogok  = self::jogok($l);
        $valasz = array_merge($ki, [
            'allapot' => $allapot,
            'lejar'   => (string) ($l->lejar ?? ''),
            'vevo'    => (string) $l->vevo,
            'uzenet'  => (string) $l->uzenet,
            'verzio'  => $allapot === 'aktiv' && $jogok['frissites'] ? (string) $kiadas['verzio'] : '',
        ]);

        if ($szivveres) {
            $mv = $allapot !== 'tiltva' ? self::muveletek_ki($pid, $most) : [];
            $sor3 = $wpdb->get_row($wpdb->prepare('SELECT elo_ig FROM ' . self::t('peldany') . ' WHERE id = %d', $pid));
            $elo  = $sor3 !== null && !empty($sor3->elo_ig) && (string) $sor3->elo_ig > $mysql;

            $valasz += [
                'modulok'         => self::licenc_modulok($l),
                'jogok'           => $jogok,
                'max_felhasznalo' => (int) $l->max_felhasznalo,
                'proba'           => (int) $l->proba,
                'muveletek'       => $mv,
                'nyugta'          => $nyugta,
                'intervallum'     => ($mv !== [] || $elo) ? self::INTERVALLUM_ELO : self::INTERVALLUM,
            ];
        }

        return $valasz;
    }

    /**
     * A beérkezett állapot tisztítása: szöveg, szám, logikai, legfeljebb 5 szint mély, szintenként 300 elem.
     *
     * @param array<mixed> $a
     * @return array<mixed>
     */
    public static function tisztit(array $a, int $szint): array
    {
        $ki = [];
        $db = 0;

        foreach ($a as $k => $v) {
            if (++$db > 300) {
                break;
            }

            $k = is_int($k) ? $k : mb_substr(preg_replace('/[^A-Za-z0-9_\-.]/', '', (string) $k) ?? '', 0, 60);

            if ($k === '') {
                continue;
            }

            if (is_array($v)) {
                if ($szint < 4) {
                    $ki[$k] = self::tisztit($v, $szint + 1);
                }
            } elseif (is_bool($v) || is_int($v) || is_float($v)) {
                $ki[$k] = $v;
            } elseif ($v === null) {
                $ki[$k] = null;
            } else {
                $ki[$k] = mb_substr(wp_strip_all_tags((string) $v), 0, 400);
            }
        }

        return $ki;
    }

    /** @param array<string, mixed> $m */
    private static function meres_rogzit(int $pid, array $m, int $most): void
    {
        global $wpdb;

        $ido   = wp_date('Y-m-d H:i:s', $most);
        $elozo = (string) $wpdb->get_var($wpdb->prepare('SELECT ido FROM ' . self::t('meres') . ' WHERE peldany_id = %d ORDER BY ido DESC LIMIT 1', $pid));

        $szam = static fn (string $k): int => max(0, min(2000000000, (int) round((float) ($m[$k] ?? 0))));

        $wpdb->insert(self::t('meres'), [
            'peldany_id' => $pid,
            'ido'        => $ido,
            'ora'        => (int) ($elozo === '' || substr($elozo, 0, 13) !== substr($ido, 0, 13)),
            'valasz_ms'  => $szam('valasz_ms'),
            'p95_ms'     => $szam('p95_ms'),
            'db_ms'      => $szam('db_ms'),
            'mem_mb'     => $szam('mem_mb'),
            'db_mb'      => $szam('db_mb'),
            'lemez_mb'   => $szam('lemez_mb'),
            'hiba'       => $szam('hiba'),
            'aktiv'      => $szam('aktiv'),
            'keres'      => $szam('keres'),
            'munkalap'   => $szam('munkalap_ma'),
        ]);
    }

    /** @param array<string, mixed> $snap */
    private static function katalogus_tanul(array $snap): void
    {
        $k = $snap['muhely']['katalogus'] ?? null;

        if (!is_array($k) || $k === []) {
            return;
        }

        $uj = [];

        foreach ($k as $m) {
            if (is_array($m) && preg_match('/^[a-z0-9_]{2,40}$/', (string) ($m['k'] ?? '')) === 1) {
                $uj[(string) $m['k']] = ['cim' => (string) ($m['c'] ?? $m['k']), 'csoport' => (string) ($m['cs'] ?? '')];
            }
        }

        $regi = get_option(self::OPT_KATALOGUS, []);

        if ($uj !== [] && $uj != $regi) { // phpcs:ignore Universal.Operators.StrictComparisons
            update_option(self::OPT_KATALOGUS, $uj, false);
        }
    }

    /* =================================================================
     * Egészség, riasztás
     * ============================================================== */

    /**
     * A példány problémái az állapotból.
     *
     * @param array<string, mixed> $s
     * @return array{szint: string, problemak: array<int, array{kod: string, szint: string, szoveg: string}>}
     */
    public static function egeszseg(array $s, ?object $l, ?int $most = null): array
    {
        $most = $most ?? time();
        $b    = self::beallitas();
        $p    = [];
        $add  = static function (string $kod, string $szint, string $szoveg) use (&$p): void {
            $p[] = ['kod' => $kod, 'szint' => $szint, 'szoveg' => $szoveg];
        };

        $seb = (array) ($s['sebesseg'] ?? []);
        $atl = (int) ($seb['atlag_ms'] ?? 0);

        if ($atl >= (int) $b['valasz_hiba']) {
            $add('lassu', 'hiba', 'Nagyon lassú: átlag ' . $atl . ' ms válaszidő.');
        } elseif ($atl >= (int) $b['valasz_figyel']) {
            $add('lassu', 'figyel', 'Lassú: átlag ' . $atl . ' ms válaszidő.');
        }

        $m = (array) ($s['mentes'] ?? []);

        if (array_key_exists('utolso', $m)) {
            $u = (int) $m['utolso'];

            if ($u <= 0) {
                $add('mentes', 'hiba', 'Még nem készült mentés.');
            } elseif ($most - $u > 3 * (int) $b['mentes_ora'] * HOUR_IN_SECONDS) {
                $add('mentes', 'hiba', 'Az utolsó mentés ' . (int) floor(($most - $u) / DAY_IN_SECONDS) . ' napos.');
            } elseif ($most - $u > (int) $b['mentes_ora'] * HOUR_IN_SECONDS) {
                $add('mentes', 'figyel', 'Az utolsó mentés ' . (int) floor(($most - $u) / HOUR_IN_SECONDS) . ' órás.');
            }

            if (isset($m['kulso_hiba']) && (string) $m['kulso_hiba'] !== '') {
                $add('kulso_mentes', 'figyel', 'Külső mentés: ' . (string) $m['kulso_hiba']);
            }
        }

        $lem = (array) ($s['lemez'] ?? []);

        if (isset($lem['szabad_mb']) && (int) $lem['szabad_mb'] >= 0) {
            $gb = (int) $lem['szabad_mb'] / 1024;

            if ($gb < max(0.3, (float) $b['lemez_gb'] / 4)) {
                $add('lemez', 'hiba', 'Elfogy a tárhely: ' . number_format($gb, 1, ',', ' ') . ' GB szabad.');
            } elseif ($gb < (float) $b['lemez_gb']) {
                $add('lemez', 'figyel', 'Kevés a tárhely: ' . number_format($gb, 1, ',', ' ') . ' GB szabad.');
            }
        }

        $hibak = 0;

        foreach ((array) ($s['hibak'] ?? []) as $h) {
            if (is_array($h) && $most - (int) ($h['utolso'] ?? 0) < DAY_IN_SECONDS) {
                $hibak += (int) ($h['db'] ?? 1);
            }
        }

        if ($hibak > 0) {
            $add('hiba', 'hiba', $hibak . ' végzetes hiba az elmúlt 24 órában.');
        }

        $cron = (array) ($s['cron'] ?? []);

        if ((int) ($cron['kesik_perc'] ?? 0) > 60 && empty($s['rendszer']['cron_tiltva'])) {
            $add('cron', 'figyel', 'Az ütemezett feladatok ' . (int) $cron['kesik_perc'] . ' perce késnek.');
        }

        if (isset($s['halozat']['online']) && $s['halozat']['online'] === false) {
            $add('internet', 'figyel', 'A rendszer szerint nincs internet (SMS, számla, e-mail vár).');
        }

        if (!empty($s['muhely']['tesztuzem'])) {
            $add('tesztuzem', 'figyel', 'Tesztüzemben fut: SMS, e-mail, számla nem megy ki.');
        }

        $r = (array) ($s['rendszer'] ?? []);

        if (!empty($r['php']) && version_compare((string) $r['php'], '8.1', '<')) {
            $add('php', 'figyel', 'Régi PHP: ' . (string) $r['php'] . '.');
        }

        if (isset($r['opcache']) && $r['opcache'] === false) {
            $add('opcache', 'figyel', 'Az OPcache ki van kapcsolva (lassabb).');
        }

        if (isset($r['memoria_mb']) && (int) $r['memoria_mb'] > 0 && (int) $r['memoria_mb'] < 256) {
            $add('memoria', 'figyel', 'Kevés PHP-memória: ' . (int) $r['memoria_mb'] . ' MB.');
        }

        if (!empty($r['debug_kijelzes'])) {
            $add('debug', 'figyel', 'A hibák kiírása be van kapcsolva (éles rendszeren ne legyen).');
        }

        if ($l !== null) {
            $felh = (int) ($s['adat']['felhasznalo'] ?? 0);

            if ((int) $l->max_felhasznalo > 0 && $felh > (int) $l->max_felhasznalo) {
                $add('felhasznalo', 'figyel', 'Több felhasználó (' . $felh . '), mint amennyit a licenc enged (' . (int) $l->max_felhasznalo . ').');
            }

            if (!empty($l->lejar)) {
                $nap = (int) floor((strtotime((string) $l->lejar . ' 23:59:59') - $most) / DAY_IN_SECONDS);

                if ($nap < 0) {
                    $add('licenc', 'hiba', 'A licenc lejárt (' . (string) $l->lejar . ').');
                } elseif ($nap <= 14) {
                    $add('licenc', 'figyel', 'A licenc ' . $nap . ' nap múlva lejár.');
                }
            }

            if ((string) $l->allapot === 'tiltva') {
                $add('licenc', 'hiba', 'A licenc le van tiltva.');
            }
        }

        $szint = 'ok';

        foreach ($p as $x) {
            if ($x['szint'] === 'hiba') {
                $szint = 'hiba';
                break;
            }

            $szint = 'figyel';
        }

        return ['szint' => $szint, 'problemak' => $p];
    }

    /**
     * Új és megszűnt problémák naplózása; új hibánál e-mail.
     *
     * @param array<int, array<string, string>> $uj
     */
    private static function problemak_valtozasa(?object $p, object $l, array $uj): void
    {
        if ($p === null) {
            return;
        }

        $regi = json_decode((string) ($p->problema ?? ''), true);
        $regi = is_array($regi) ? $regi : [];
        $rk   = array_column($regi, 'szint', 'kod');
        $ids  = ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => (int) $l->id, 'peldany_id' => (int) $p->id];
        $uj_hibak = [];

        foreach ($uj as $x) {
            if (!isset($rk[$x['kod']]) || ($rk[$x['kod']] !== $x['szint'] && $x['szint'] === 'hiba')) {
                self::naplo('riasztas', $x['szoveg'], $x['szint'], $ids);

                if ($x['szint'] === 'hiba') {
                    $uj_hibak[] = $x['szoveg'];
                }
            }
        }

        $uk = array_column($uj, 'kod');

        foreach ($regi as $x) {
            if (is_array($x) && !in_array((string) ($x['kod'] ?? ''), $uk, true)) {
                self::naplo('riasztas', 'Megoldódott: ' . (string) ($x['szoveg'] ?? ''), 'ok', $ids);
            }
        }

        if ($uj_hibak !== []) {
            self::email(self::peldany_nev($p, $l) . ': ' . count($uj_hibak) . ' új hiba', implode("\n", $uj_hibak));
        }
    }

    public static function peldany_nev(object $p, ?object $l = null): string
    {
        if (trim((string) ($p->cimke ?? '')) !== '') {
            return (string) $p->cimke;
        }

        return ($l !== null ? (string) $l->vevo : 'Rendszer') . ' · ' . substr((string) $p->peldany, 0, 6);
    }

    /** Riasztó e-mail (ha be van kapcsolva). */
    public static function email(string $targy, string $szoveg): void
    {
        $b = self::beallitas();

        if (empty($b['email_riasztas'])) {
            return;
        }

        $cim = $b['email_cim'] !== '' ? $b['email_cim'] : (string) get_option('admin_email');

        if ($cim === '') {
            return;
        }

        wp_mail($cim, '[SDH Központ] ' . $targy, $szoveg . "\n\n" . admin_url('admin.php?page=sdh-kozpont'));
    }

    /**
     * A példány állapota most: online / figyel / hiba / offline / napi / uj.
     */
    public static function statusz(object $p, ?int $most = null): string
    {
        $most = $most ?? time();
        $b    = self::beallitas();

        if (empty($p->utolso_jel)) {
            if (empty($p->utolso)) {
                return 'uj';
            }

            return $most - self::ido_ts((string) $p->utolso) > self::NAPI_ORA * HOUR_IN_SECONDS ? 'offline' : 'napi';
        }

        $kor = $most - self::ido_ts((string) $p->utolso_jel);

        if ($kor > (int) $b['offline_perc'] * MINUTE_IN_SECONDS) {
            return 'offline';
        }

        $e = (string) $p->egeszseg;

        return in_array($e, ['figyel', 'hiba'], true) ? $e : 'online';
    }

    /** A helyi idő (current_time mysql) → időbélyeg. */
    public static function ido_ts(string $mysql): int
    {
        if ($mysql === '') {
            return 0;
        }

        $d = date_create_immutable($mysql, wp_timezone());

        return $d ? $d->getTimestamp() : 0;
    }

    /* =================================================================
     * Távoli műveletek
     * ============================================================== */

    /**
     * Művelet sorba állítása. Siker: azonosító, hiba: üzenet.
     *
     * @param array<string, mixed> $param
     * @return int|string
     */
    public static function muvelet_kerj(int $pid, string $tipus, array $param = [], string $ki = '')
    {
        global $wpdb;

        if (!isset(self::MUVELETEK[$tipus])) {
            return 'Ismeretlen művelet.';
        }

        $p = $wpdb->get_row($wpdb->prepare('SELECT p.*, l.jogok, l.vevo_id, l.vevo, l.allapot AS l_allapot FROM ' . self::t('peldany') . ' p LEFT JOIN ' . self::t('licenc') . ' l ON l.id = p.licenc_id WHERE p.id = %d', $pid));

        if ($p === null) {
            return 'Ez a rendszer nincs meg.';
        }

        $j = self::jogok((object) ['jogok' => $p->jogok]);

        if (!in_array($tipus, ['uzenet', 'uzenet_torol', 'tamogatas_keres'], true) && !$j['tavoli']) {
            return 'A licenc nem tartalmaz távoli karbantartást (Licencek › Jogok).';
        }

        if ($tipus === 'tamogatas_keres' && !$j['tamogatas']) {
            return 'A licenc nem tartalmaz támogatást (Licencek › Jogok).';
        }

        if (empty($p->utolso_jel)) {
            return 'Ez a rendszer még régi verzió (napi jelentés): távoli művelet a 0.99.0-tól megy.';
        }

        $tiszta = [];

        if ($tipus === 'uzenet') {
            $tiszta['szoveg'] = mb_substr(sanitize_textarea_field((string) ($param['szoveg'] ?? '')), 0, 500);
            $tiszta['szint']  = in_array((string) ($param['szint'] ?? ''), ['info', 'figyel', 'hiba'], true) ? (string) $param['szint'] : 'info';

            if ($tiszta['szoveg'] === '') {
                return 'Írd be az üzenetet.';
            }
        }

        if ($tipus === 'tamogatas_keres') {
            $tiszta['ora']   = in_array((int) ($param['ora'] ?? 0), [2, 24, 72], true) ? (int) $param['ora'] : 2;
            $tiszta['indok'] = mb_substr(sanitize_text_field((string) ($param['indok'] ?? '')), 0, 200);
        }

        $ora = $tipus === 'frissites_telepit' ? 2 : 24;

        $wpdb->insert(self::t('muvelet'), [
            'peldany_id' => $pid, 'tipus' => $tipus, 'parameter' => (string) wp_json_encode($tiszta), 'allapot' => 'var',
            'letrehozva' => self::most(), 'lejar' => wp_date('Y-m-d H:i:s', time() + $ora * HOUR_IN_SECONDS), 'ki' => $ki,
        ]);
        $id = (int) $wpdb->insert_id;

        // A következő szívverések gyakoribbak, hogy a művelet hamar lefusson.
        $wpdb->update(self::t('peldany'), ['elo_ig' => wp_date('Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS)], ['id' => $pid]);
        self::naplo('muvelet', 'Távoli művelet kérve: ' . self::MUVELETEK[$tipus] . '.', 'info', ['vevo_id' => (int) $p->vevo_id, 'licenc_id' => (int) $p->licenc_id, 'peldany_id' => $pid], $ki);

        return $id;
    }

    /**
     * A váró műveletek a szívverés válaszába (és „kiküldve” jelölés).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function muveletek_ki(int $pid, int $most): array
    {
        global $wpdb;

        $mysql = wp_date('Y-m-d H:i:s', $most);
        $ujra  = wp_date('Y-m-d H:i:s', $most - 15 * MINUTE_IN_SECONDS);
        $sorok = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::t('muvelet') . " WHERE peldany_id = %d AND lejar > %s AND (allapot = 'var' OR (allapot = 'kuldve' AND kuldve < %s)) ORDER BY id LIMIT 10",
            $pid,
            $mysql,
            $ujra
        ));
        $ki = [];

        foreach ($sorok as $s) {
            $ki[] = ['id' => (int) $s->id, 'tipus' => (string) $s->tipus, 'parameter' => json_decode((string) $s->parameter, true) ?: [], 'lejar' => self::ido_ts((string) $s->lejar)];
            $wpdb->update(self::t('muvelet'), ['allapot' => 'kuldve', 'kuldve' => $mysql], ['id' => (int) $s->id]);
        }

        return $ki;
    }

    /**
     * A példány által visszaküldött eredmények. A nyugtázott azonosítók listája.
     *
     * @param array<int, mixed> $eredmenyek
     * @return array<int, int>
     */
    private static function eredmenyek_be(int $pid, array $eredmenyek, object $l): array
    {
        global $wpdb;

        $nyugta = [];

        foreach (array_slice($eredmenyek, 0, 20) as $e) {
            if (!is_array($e) || (int) ($e['id'] ?? 0) <= 0) {
                continue;
            }

            $id = (int) $e['id'];
            $m  = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('muvelet') . ' WHERE id = %d AND peldany_id = %d', $id, $pid));
            $nyugta[] = $id;

            if ($m === null || in_array((string) $m->allapot, ['kesz', 'hiba'], true)) {
                continue;
            }

            $ok  = !empty($e['ok']);
            $eri = self::tisztit(['uzenet' => (string) ($e['uzenet'] ?? ''), 'adat' => is_array($e['adat'] ?? null) ? $e['adat'] : []], 1);
            $wpdb->update(self::t('muvelet'), ['allapot' => $ok ? 'kesz' : 'hiba', 'kesz' => self::most(), 'eredmeny' => (string) wp_json_encode($eri)], ['id' => $id]);

            self::naplo(
                'muvelet',
                (self::MUVELETEK[(string) $m->tipus] ?? (string) $m->tipus) . ': ' . ($ok ? 'kész' : 'nem sikerült') . ((string) $eri['uzenet'] !== '' ? ' – ' . mb_substr((string) $eri['uzenet'], 0, 300) : '') . '.',
                $ok ? 'ok' : 'hiba',
                ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => (int) $l->id, 'peldany_id' => $pid]
            );
        }

        return $nyugta;
    }

    /* =================================================================
     * Csomag és támogatás (vevő felől)
     * ============================================================== */

    /** Letölthet-e csomagot. */
    public static function csomag_szabad(string $kulcs, string $peldany, ?int $most = null): bool
    {
        global $wpdb;

        $l = self::licenc($kulcs);

        if ($l === null || self::licenc_allapot($l, wp_date('Y-m-d', $most ?? time())) !== 'aktiv' || !self::jogok($l)['frissites']) {
            return false;
        }

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t('peldany') . ' WHERE peldany = %s AND licenc_id = %d', $peldany, (int) $l->id)) > 0;
    }

    /** @param array<string, mixed> $be */
    public static function tamogatas_rogzit(array $be): string
    {
        global $wpdb;

        $l       = self::licenc((string) ($be['kulcs'] ?? ''));
        $peldany = (string) ($be['peldany'] ?? '');
        $link    = esc_url_raw((string) ($be['link'] ?? ''));
        $pid     = $l !== null ? (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t('peldany') . ' WHERE peldany = %s AND licenc_id = %d', $peldany, (int) $l->id)) : 0;

        if ($l === null || $link === '' || $pid === 0) {
            return 'Ismeretlen licenc vagy példány.';
        }

        $lejar = max(time(), (int) ($be['lejar'] ?? 0));

        $wpdb->insert(self::t('tamogatas'), [
            'licenc_id' => (int) $l->id,
            'peldany'   => $peldany,
            'link'      => mb_substr($link, 0, 500),
            'lejar'     => wp_date('Y-m-d H:i:s', $lejar),
            'ki'        => mb_substr(sanitize_text_field((string) ($be['ki'] ?? '')), 0, 120),
            'erkezett'  => self::most(),
        ]);

        self::naplo('tamogatas', 'Támogatási hozzáférés érkezett ' . wp_date('Y.m.d. H:i', $lejar) . '-ig (engedélyezte: ' . sanitize_text_field((string) ($be['ki'] ?? '')) . ').', 'ok', ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => (int) $l->id, 'peldany_id' => $pid]);
        self::email('Támogatási hozzáférés: ' . (string) $l->vevo, 'A vevő engedélyezte a belépést ' . wp_date('Y.m.d. H:i', $lejar) . '-ig.');

        return '';
    }

    /* =================================================================
     * Napló
     * ============================================================== */

    /** @param array<string, int> $ids */
    public static function naplo(string $tipus, string $szoveg, string $szint = 'info', array $ids = [], string $ki = ''): void
    {
        global $wpdb;

        $wpdb->insert(self::t('esemeny'), [
            'ido'        => self::most(),
            'tipus'      => mb_substr($tipus, 0, 20),
            'szint'      => in_array($szint, ['info', 'ok', 'figyel', 'hiba'], true) ? $szint : 'info',
            'vevo_id'    => (int) ($ids['vevo_id'] ?? 0),
            'licenc_id'  => (int) ($ids['licenc_id'] ?? 0),
            'peldany_id' => (int) ($ids['peldany_id'] ?? 0),
            'szoveg'     => mb_substr($szoveg, 0, 500),
            'ki'         => mb_substr($ki, 0, 120),
        ]);
    }

    /** Ugyanazt naponta legfeljebb egyszer. */
    private static function naplo_ritkan(string $kulcs, string $tipus, string $szoveg, string $szint, array $ids): void
    {
        $t = 'sdh_kp_rit_' . md5($kulcs);

        if (get_transient($t)) {
            return;
        }

        set_transient($t, 1, DAY_IN_SECONDS);
        self::naplo($tipus, $szoveg, $szint, $ids);
    }

    /* =================================================================
     * Időzített: álló rendszerek, takarítás
     * ============================================================== */

    /** @param array<string, array<string, mixed>> $s */
    public static function utemezesek(array $s): array
    {
        $s['sdh_kozpont_5perc'] = ['interval' => 300, 'display' => 'SDH Központ – 5 percenként'];

        return $s;
    }

    public static function cron_beallit(): void
    {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 120, 'sdh_kozpont_5perc', self::CRON);
        }
    }

    public static function idozitett(?int $most = null): void
    {
        global $wpdb;

        $most  = $most ?? time();
        $b     = self::beallitas();
        $hatar = wp_date('Y-m-d H:i:s', $most - (int) $b['offline_perc'] * MINUTE_IN_SECONDS);
        $mysql = wp_date('Y-m-d H:i:s', $most);

        // Álló rendszerek (egyszer jelezzük, amíg újra nem jelentkezik).
        $allok = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT p.*, l.vevo, l.vevo_id FROM ' . self::t('peldany') . ' p LEFT JOIN ' . self::t('licenc') . ' l ON l.id = p.licenc_id WHERE p.utolso_jel IS NOT NULL AND p.utolso_jel < %s AND p.offline_jelezve = 0',
            $hatar
        ));

        foreach ($allok as $p) {
            $wpdb->update(self::t('peldany'), ['offline_jelezve' => 1], ['id' => (int) $p->id]);
            $nev = self::peldany_nev($p, (object) ['vevo' => (string) $p->vevo]);
            self::naplo('riasztas', 'Nem jelentkezik ' . (int) $b['offline_perc'] . ' perce.', 'hiba', ['vevo_id' => (int) $p->vevo_id, 'licenc_id' => (int) $p->licenc_id, 'peldany_id' => (int) $p->id]);
            self::email($nev . ': nem jelentkezik', 'Az utolsó jel: ' . (string) $p->utolso_jel . '. Lehet, hogy a gép ki van kapcsolva, nincs internet, vagy a rendszer leállt.');
        }

        // Lejárt műveletek.
        $wpdb->query($wpdb->prepare('UPDATE ' . self::t('muvelet') . " SET allapot = 'lejart' WHERE allapot IN ('var', 'kuldve') AND lejar < %s", $mysql));

        // Mérések: 48 óra után óránként egy marad, a megőrzési idő után semmi.
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('meres') . ' WHERE ora = 0 AND ido < %s', wp_date('Y-m-d H:i:s', $most - 2 * DAY_IN_SECONDS)));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('meres') . ' WHERE ido < %s', wp_date('Y-m-d H:i:s', $most - (int) $b['meres_nap'] * DAY_IN_SECONDS)));

        // Napi egyszer: lejáró licencek.
        if (get_transient('sdh_kp_napi') === false) {
            set_transient('sdh_kp_napi', 1, DAY_IN_SECONDS - 300);
            $ma  = wp_date('Y-m-d', $most);
            $het = wp_date('Y-m-d', $most + 14 * DAY_IN_SECONDS);

            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::t('licenc') . " WHERE allapot = 'aktiv' AND lejar IS NOT NULL AND lejar >= %s AND lejar <= %s", $ma, $het)) as $l) {
                self::naplo_ritkan('lejar_' . (int) $l->id . '_' . (string) $l->lejar, 'licenc', 'Lejár ' . (string) $l->lejar . '-n: ' . (string) $l->kulcs . '.', 'figyel', ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => (int) $l->id]);
            }
        }
    }
}
