<?php
/**
 * SDH Központ 2.1 – kiszolgálás: az értékesítő webhely rendelései alapján vevő + licenc (új / megújítás / bővítés),
 * idempotensen (rendelés-azonosítónként egyszer), aláírt REST-végponttal és egyszer használható letöltő linkkel.
 *
 * - `feldolgoz(array $keres): array` – közvetlen hívás (az értékesítő ugyanazon a webhelyen: `helyi` mód) vagy a
 *   REST-végpont mögött. Kérés: {muvelet: uj|megujit|bovit|proba, rendeles, vevo:{nev, adoszam, cim, kapcsolattarto,
 *   email, telefon}, licenc:{csomag, modulok:[…]|"*", max_felhasznalo, max_peldany, ciklus, lejar?, dij, esedekes?},
 *   licenc_kulcs?} → {ok, licenc_kulcs, licenc_id, vevo_id, lejar, letoltes_url, …} vagy {ok:false, uzenet}.
 *   Ugyanazzal a `rendeles` azonosítóval ismételt kérés a tárolt választ adja (a letöltő link minden alkalommal friss,
 *   mert a tokent csak lenyomatként tároljuk).
 * - POST `kiszolgalas` {adat: base64(json), alairas: base64(Ed25519)} – az értékesítő webhely kulcsával aláírva
 *   (Beállítások › Értékesítő webhely), időbélyeg ±300 mp, egyszer használható `nonce` (10 perc), opcionális IP-lista.
 *   Hibás kérésre 401, részletek nélkül. A válasz a Központ kulcsával aláírva (`SDH_Kozpont::alair()`).
 * - GET `letoltes?t=<token>` – a licenc csatornájának aktuális kiadása (mint a `csomag`), 14 napig, legfeljebb 3-szor.
 *
 * @package SDH_Kozpont
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Kozpont_Kiszolgalas
{
    /** Az aláírt kérés időbélyegének tűrése (másodperc). */
    public const IDO_TURES = 300;

    /** Egy nonce ennyi ideig foglalt (másodperc). */
    public const NONCE_IDO = 600;

    /** Letöltő link: érvényesség (nap) és letöltésszám. */
    public const LETOLTES_NAP = 14;
    public const LETOLTES_MAX = 3;

    public const MUVELETEK = ['uj', 'megujit', 'bovit', 'proba'];

    private const KI = 'Értékesítés';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'utvonalak']);
        add_action(SDH_Kozpont::CRON, [self::class, 'takarit']);
    }

    /* =================================================================
     * Séma (a SDH_Kozpont::sema() hívja)
     * ============================================================== */

    public static function sema(string $c): void
    {
        dbDelta('CREATE TABLE ' . SDH_Kozpont::t('rendeles') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            rendeles varchar(40) NOT NULL default '',
            muvelet varchar(12) NOT NULL default '',
            licenc_id bigint(20) unsigned NOT NULL default 0,
            vevo_id bigint(20) unsigned NOT NULL default 0,
            valasz longtext NULL,
            ido datetime NULL,
            PRIMARY KEY  (id),
            unique key rendeles (rendeles),
            key licenc_id (licenc_id)
        ) {$c};");

        dbDelta('CREATE TABLE ' . SDH_Kozpont::t('letoltes') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            token_hash varchar(64) NOT NULL default '',
            licenc_id bigint(20) unsigned NOT NULL default 0,
            rendeles varchar(40) NOT NULL default '',
            db int(11) NOT NULL default 0,
            max_db int(11) NOT NULL default 3,
            lejar datetime NULL,
            letrehozva datetime NULL,
            utolso datetime NULL,
            PRIMARY KEY  (id),
            unique key token_hash (token_hash),
            key licenc_id (licenc_id)
        ) {$c};");

        dbDelta('CREATE TABLE ' . SDH_Kozpont::t('nonce') . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            nonce varchar(64) NOT NULL default '',
            lejar bigint(20) NOT NULL default 0,
            PRIMARY KEY  (id),
            unique key nonce (nonce),
            key lejar (lejar)
        ) {$c};");
    }

    /* =================================================================
     * Beállítás-segédek (a SDH_Kozpont::beallitas*() hívja)
     * ============================================================== */

    /** Az értékesítő webhely nyilvános kulcsa (base64, 32 bájt) – érvénytelennél null. */
    public static function kulcs_tisztit(string $k): ?string
    {
        $k = preg_replace('/\s+/', '', $k) ?? '';

        if ($k === '') {
            return '';
        }

        $b = base64_decode($k, true);

        return $b !== false && strlen($b) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? base64_encode($b) : null;
    }

    /** IP-cím / CIDR lista (vessző, szóköz vagy sortörés választ el); a hibás elemek kimaradnak. */
    public static function ip_tisztit(string $s): string
    {
        $ki = [];

        foreach (preg_split('/[\s,;]+/', $s) ?: [] as $t) {
            $t = trim($t);

            if ($t === '') {
                continue;
            }

            [$ip, $maszk] = array_pad(explode('/', $t, 2), 2, null);

            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $hossz = strpos((string) $ip, ':') !== false ? 128 : 32;

            if ($maszk !== null && (!ctype_digit((string) $maszk) || (int) $maszk > $hossz)) {
                continue;
            }

            $ki[] = $maszk === null ? (string) $ip : $ip . '/' . (int) $maszk;
        }

        return implode(', ', array_values(array_unique($ki)));
    }

    public static function ip_engedett(string $ip, string $lista): bool
    {
        $lista = trim($lista);

        if ($lista === '') {
            return true;
        }

        $cel = @inet_pton($ip); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if ($cel === false) {
            return false;
        }

        foreach (explode(',', $lista) as $t) {
            [$halo, $maszk] = array_pad(explode('/', trim($t), 2), 2, null);
            $h = @inet_pton((string) $halo); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            if ($h === false || strlen($h) !== strlen($cel)) {
                continue;
            }

            $bit = $maszk === null ? strlen($h) * 8 : (int) $maszk;
            $teljes = intdiv($bit, 8);
            $marad  = $bit % 8;

            if (substr($h, 0, $teljes) !== substr($cel, 0, $teljes)) {
                continue;
            }

            if ($marad === 0 || ((ord($h[$teljes]) ^ ord($cel[$teljes])) & (0xFF << (8 - $marad)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    /* =================================================================
     * REST
     * ============================================================== */

    public static function utvonalak(): void
    {
        register_rest_route(SDH_Kozpont::NS, '/kiszolgalas', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'rest_kiszolgalas'],
        ]);

        register_rest_route(SDH_Kozpont::NS, '/letoltes', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'rest_letoltes'],
        ]);
    }

    /** @return WP_REST_Response|WP_Error */
    public static function rest_kiszolgalas(WP_REST_Request $r)
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $be = $r->get_json_params();
        $ok = self::keres_ellenoriz(is_array($be) ? $be : [], $ip);

        if (is_string($ok)) {
            self::elutasit_naplo($ok);

            return new WP_Error('sdh_kozpont_tiltva', 'Nem engedélyezett kérés.', ['status' => 401]);
        }

        $valasz = self::feldolgoz($ok);
        $valasz['nonce'] = (string) $ok['nonce'];
        $valasz['ido']   = time();

        $v = rest_ensure_response(SDH_Kozpont::alair($valasz));
        $v->header('Cache-Control', 'private, no-store, max-age=0');

        return $v;
    }

    /**
     * Az aláírt kérés ellenőrzése: IP-lista, kulcs, aláírás, időbélyeg, nonce (egyszer).
     * Siker: a kibontott kérés; hiba: belső ok (csak a naplóba).
     *
     * @param array<string, mixed> $be
     * @return array<string, mixed>|string
     */
    public static function keres_ellenoriz(array $be, string $ip, ?int $most = null)
    {
        $b    = SDH_Kozpont::beallitas();
        $most = $most ?? time();

        if (!self::ip_engedett($ip, (string) $b['ertekesito_ip'])) {
            return 'nem engedélyezett IP-cím';
        }

        $pub = base64_decode((string) $b['ertekesito_kulcs'], true);

        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return 'nincs beállítva az értékesítő webhely kulcsa';
        }

        $raw = base64_decode((string) ($be['adat'] ?? ''), true);
        $sig = base64_decode((string) ($be['alairas'] ?? ''), true);

        if ($raw === false || $raw === '' || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'hibás formátum';
        }

        if (!sodium_crypto_sign_verify_detached($sig, $raw, $pub)) {
            return 'érvénytelen aláírás';
        }

        $adat = json_decode($raw, true);

        if (!is_array($adat)) {
            return 'hibás adat';
        }

        if (abs($most - (int) ($adat['ido'] ?? 0)) > self::IDO_TURES) {
            return 'elavult időbélyeg';
        }

        $nonce = (string) ($adat['nonce'] ?? '');

        if (preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $nonce) !== 1) {
            return 'hiányzó nonce';
        }

        global $wpdb;

        $wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Kozpont::t('nonce') . ' WHERE lejar < %d', $most));
        $elnyom = $wpdb->suppress_errors(true);
        $uj     = $wpdb->insert(SDH_Kozpont::t('nonce'), ['nonce' => $nonce, 'lejar' => $most + self::NONCE_IDO]);
        $wpdb->suppress_errors($elnyom);

        if ($uj === false) {
            return 'ismételt kérés (nonce)';
        }

        return $adat;
    }

    /** Elutasított kérés a naplóba – okonként percenként legfeljebb egyszer (ne lehessen elárasztani). */
    private static function elutasit_naplo(string $ok): void
    {
        $t = 'sdh_kp_kisz_' . md5($ok);

        if (get_transient($t)) {
            return;
        }

        set_transient($t, 1, MINUTE_IN_SECONDS);
        SDH_Kozpont::naplo('kiszolgalas', 'Elutasított kiszolgálási kérés: ' . $ok . '.', 'figyel', [], self::KI);
    }

    /* =================================================================
     * Feldolgozás
     * ============================================================== */

    /**
     * @param array<string, mixed> $keres
     * @return array<string, mixed>
     */
    public static function feldolgoz(array $keres): array
    {
        global $wpdb;

        $muvelet  = (string) ($keres['muvelet'] ?? '');
        $rendeles = (string) ($keres['rendeles'] ?? '');

        if (!in_array($muvelet, self::MUVELETEK, true)) {
            return self::hiba('Ismeretlen művelet.');
        }

        if ($muvelet === 'proba') {
            return ['ok' => true, 'muvelet' => 'proba', 'verzio' => SDH_KOZPONT_VERSION, 'uzenet' => 'A Központ elérhető.'];
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{0,39}$/', $rendeles) !== 1) {
            return self::hiba('Hiányzó vagy hibás rendelésazonosító.');
        }

        $t = SDH_Kozpont::t('rendeles');
        $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE rendeles = %s", $rendeles));

        if ($m !== null) {
            return self::ismetles($m, $muvelet);
        }

        // Foglalás: párhuzamos ugyanazzal az azonosítóval nem fut kétszer.
        $elnyom = $wpdb->suppress_errors(true);
        $fog    = $wpdb->insert($t, ['rendeles' => $rendeles, 'muvelet' => $muvelet, 'ido' => SDH_Kozpont::most()]);
        $wpdb->suppress_errors($elnyom);

        if ($fog === false) {
            $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE rendeles = %s", $rendeles));

            return $m !== null ? self::ismetles($m, $muvelet) : self::hiba('A rendelés most nem dolgozható fel, próbáld újra.');
        }

        $fid = (int) $wpdb->insert_id;

        try {
            $v = self::vegrehajt($muvelet, $rendeles, $keres);
        } catch (Throwable $e) {
            $v = self::hiba('Belső hiba a feldolgozásban.');
            SDH_Kozpont::naplo('kiszolgalas', 'Kiszolgálási hiba (' . $rendeles . '): ' . mb_substr($e->getMessage(), 0, 300), 'hiba', [], self::KI);
        }

        if (empty($v['ok'])) {
            $wpdb->delete($t, ['id' => $fid]);
            SDH_Kozpont::naplo('kiszolgalas', 'Rendelés ' . $rendeles . ' (' . $muvelet . ') nem teljesíthető: ' . (string) ($v['uzenet'] ?? ''), 'figyel', [], self::KI);

            return $v;
        }

        $tarolt = $v;
        unset($tarolt['letoltes_url']);

        $wpdb->update($t, [
            'licenc_id' => (int) $v['licenc_id'],
            'vevo_id'   => (int) $v['vevo_id'],
            'valasz'    => (string) wp_json_encode($tarolt),
        ], ['id' => $fid]);

        $v['letoltes_url'] = $muvelet === 'uj' ? self::letoltes_link((int) $v['licenc_id'], $rendeles) : '';

        return $v;
    }

    /**
     * @param object $m a tárolt rendeléssor
     * @return array<string, mixed>
     */
    private static function ismetles(object $m, string $muvelet): array
    {
        $v = json_decode((string) ($m->valasz ?? ''), true);

        if (!is_array($v)) {
            return self::hiba('A rendelés feldolgozása folyamatban van, próbáld újra később.');
        }

        if ((string) $m->muvelet !== $muvelet) {
            return self::hiba('Ezzel a rendelésazonosítóval már más művelet futott.');
        }

        $v['ismetles']     = true;
        $v['letoltes_url'] = $muvelet === 'uj' ? self::letoltes_link((int) $m->licenc_id, (string) $m->rendeles) : '';

        return $v;
    }

    /** @return array{ok: false, uzenet: string} */
    private static function hiba(string $uzenet): array
    {
        return ['ok' => false, 'uzenet' => $uzenet];
    }

    /**
     * @param array<string, mixed> $keres
     * @return array<string, mixed>
     */
    private static function vegrehajt(string $muvelet, string $rendeles, array $keres): array
    {
        global $wpdb;

        $vevo = is_array($keres['vevo'] ?? null) ? $keres['vevo'] : [];
        $lic  = is_array($keres['licenc'] ?? null) ? $keres['licenc'] : [];
        $ki   = self::KI . ' · ' . $rendeles;

        if ($muvelet === 'uj') {
            $vid = self::vevo_keres_vagy_letrehoz($vevo, $ki);

            if (is_string($vid)) {
                return self::hiba($vid);
            }

            $ciklus = self::ciklus((string) ($lic['ciklus'] ?? 'eves'));
            $lejar  = self::datum((string) ($lic['lejar'] ?? '')) ?? self::lejar_szamol($ciklus, null);
            $lid    = SDH_Kozpont::licenc_ment([
                'vevo_id'         => $vid,
                'csomag'          => (string) ($lic['csomag'] ?? ''),
                'modulok'         => $lic['modulok'] ?? '*',
                'max_peldany'     => (int) ($lic['max_peldany'] ?? 1),
                'max_felhasznalo' => (int) ($lic['max_felhasznalo'] ?? 0),
                'dij'             => (int) ($lic['dij'] ?? 0),
                'ciklus'          => $ciklus,
                'lejar'           => $lejar,
                'esedekes'        => self::datum((string) ($lic['esedekes'] ?? '')) ?? $lejar,
                'megjegyzes'      => 'Rendelés: ' . $rendeles . ' (értékesítő webhely).',
            ], $ki);

            if (!is_int($lid)) {
                return self::hiba((string) $lid);
            }

            return self::eredmeny('uj', $rendeles, $lid);
        }

        $l = SDH_Kozpont::licenc((string) ($keres['licenc_kulcs'] ?? ''));

        if ($l === null) {
            return self::hiba('Ismeretlen licenckulcs.');
        }

        if (!self::sajat($l, $vevo)) {
            return self::hiba('A licenc nem ehhez a vevőhöz tartozik.');
        }

        if ((string) $l->allapot === 'tiltva') {
            return self::hiba('A licenc le van tiltva – ezt a forgalmazó kézzel rendezi.');
        }

        $lid = (int) $l->id;
        $ids = ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => $lid];

        if ($muvelet === 'megujit') {
            $ciklus = self::ciklus((string) ($lic['ciklus'] ?? (string) $l->ciklus));

            if ($ciklus === 'egyszeri') {
                return self::hiba('Egyszeri licenc nem újítható meg.');
            }

            $frissit = ['ciklus' => $ciklus];

            if ((int) ($lic['dij'] ?? 0) > 0) {
                $frissit['dij'] = min(100000000, (int) $lic['dij']);
            }

            $wpdb->update(SDH_Kozpont::t('licenc'), $frissit, ['id' => $lid]);
            $h = SDH_Kozpont::licenc_muvelet($lid, 'megujit', $ki);

            if ($h !== '') {
                return self::hiba($h);
            }

            return self::eredmeny('megujit', $rendeles, $lid);
        }

        // bovit: modulok hozzáadása, korlátok emelése (csökkenteni nem lehet). Hiányzó mező = nincs változás;
        // `extra_felhasznalo` / `extra_peldany` hozzáadódik; `max_felhasznalo: 0` = korlátlanra emelés.
        $regi = SDH_Kozpont::licenc_modulok($l);
        $uj   = SDH_Kozpont::modulok_tisztit($lic['modulok'] ?? []);

        if ($regi === '*' || $uj === '*') {
            $mod = '*';
        } else {
            $mod = array_values(array_unique(array_merge($regi, $uj)));
        }

        $peld = max((int) $l->max_peldany, isset($lic['max_peldany']) ? (int) $lic['max_peldany'] : 0) + max(0, (int) ($lic['extra_peldany'] ?? 0));
        $felh = (int) $l->max_felhasznalo;

        if ($felh > 0 && array_key_exists('max_felhasznalo', $lic)) {
            $felh = (int) $lic['max_felhasznalo'] === 0 ? 0 : max($felh, (int) $lic['max_felhasznalo']);
        }

        if ($felh > 0) {
            $felh += max(0, (int) ($lic['extra_felhasznalo'] ?? 0));
        }

        $frissit = [
            'modulok'         => $mod === '*' ? null : (string) wp_json_encode($mod),
            'max_peldany'     => max(1, min(99, $peld)),
            'max_felhasznalo' => max(0, min(999, $felh)),
            'modositva'       => SDH_Kozpont::most(),
        ];

        if (trim((string) ($lic['csomag'] ?? '')) !== '') {
            $frissit['csomag'] = mb_substr(sanitize_text_field((string) $lic['csomag']), 0, 60);
        }

        $wpdb->update(SDH_Kozpont::t('licenc'), $frissit, ['id' => $lid]);
        $uj_lista = $mod === '*' ? 'minden modul' : implode(', ', $regi === '*' ? [] : array_diff($mod, $regi));
        SDH_Kozpont::naplo('licenc', 'Licenc bővítve (' . (string) $l->kulcs . '): ' . ($uj_lista !== '' ? $uj_lista : 'korlátok') . '.', 'info', $ids, $ki);

        return self::eredmeny('bovit', $rendeles, $lid);
    }

    /** @return array<string, mixed> */
    private static function eredmeny(string $muvelet, string $rendeles, int $lid): array
    {
        global $wpdb;

        $l = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('licenc') . ' WHERE id = %d', $lid));

        if ($l === null) {
            return self::hiba('A licenc nem jött létre.');
        }

        $ids = ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => $lid];
        SDH_Kozpont::naplo('kiszolgalas', 'Rendelés kiszolgálva: ' . $rendeles . ' (' . ['uj' => 'új licenc', 'megujit' => 'megújítás', 'bovit' => 'bővítés'][$muvelet] . ', ' . (string) $l->kulcs . ').', 'ok', $ids, self::KI);

        return [
            'ok'              => true,
            'muvelet'         => $muvelet,
            'rendeles'        => $rendeles,
            'licenc_kulcs'    => (string) $l->kulcs,
            'licenc_id'       => $lid,
            'vevo_id'         => (int) $l->vevo_id,
            'lejar'           => (string) ($l->lejar ?? ''),
            'csomag'          => (string) $l->csomag,
            'modulok'         => SDH_Kozpont::licenc_modulok($l),
            'max_peldany'     => (int) $l->max_peldany,
            'max_felhasznalo' => (int) $l->max_felhasznalo,
            'ciklus'          => (string) $l->ciklus,
            'letoltes_url'    => '',
        ];
    }

    private static function ciklus(string $c): string
    {
        return in_array($c, ['havi', 'eves', 'egyszeri'], true) ? $c : 'eves';
    }

    private static function datum(string $d): ?string
    {
        $d = trim($d);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) ? $d : null;
    }

    /** Új licenc lejárata: ma + 1 / 12 hónap; egyszeri licencnek nincs lejárata. */
    public static function lejar_szamol(string $ciklus, ?string $alap): string
    {
        if ($ciklus === 'egyszeri') {
            return '';
        }

        $alap = $alap !== null && $alap > wp_date('Y-m-d') ? $alap : wp_date('Y-m-d');

        return wp_date('Y-m-d', (int) strtotime($alap . ' +' . ($ciklus === 'havi' ? 1 : 12) . ' month'));
    }

    /* =================================================================
     * Vevő
     * ============================================================== */

    public static function adoszam_norm(string $a): string
    {
        return preg_replace('/[^0-9A-Za-z]/', '', strtoupper($a)) ?? '';
    }

    /**
     * Meglévő vevő keresése: adószám → e-mail → név. Nincs találat: 0.
     *
     * @param array<string, mixed> $vevo
     */
    public static function vevo_keres(array $vevo): int
    {
        global $wpdb;

        $t   = SDH_Kozpont::t('vevo');
        $ado = self::adoszam_norm((string) ($vevo['adoszam'] ?? ''));

        if (strlen($ado) >= 8) {
            foreach ((array) $wpdb->get_results("SELECT id, adoszam FROM {$t} WHERE adoszam <> '' ORDER BY id ASC") as $s) {
                if (self::adoszam_norm((string) $s->adoszam) === $ado) {
                    return (int) $s->id;
                }
            }
        }

        $email = strtolower(sanitize_email((string) ($vevo['email'] ?? '')));

        if ($email !== '') {
            $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE LOWER(email) = %s ORDER BY id ASC LIMIT 1", $email));

            if ($id > 0) {
                return $id;
            }
        }

        $nev = trim(sanitize_text_field((string) ($vevo['nev'] ?? '')));

        if ($nev !== '') {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE nev = %s ORDER BY id ASC LIMIT 1", $nev));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $vevo
     * @return int|string
     */
    private static function vevo_keres_vagy_letrehoz(array $vevo, string $ki)
    {
        global $wpdb;

        $nev = trim(sanitize_text_field((string) ($vevo['nev'] ?? '')));

        if ($nev === '') {
            $nev = trim(sanitize_text_field((string) ($vevo['kapcsolattarto'] ?? '')));
        }

        if ($nev === '' || sanitize_email((string) ($vevo['email'] ?? '')) === '') {
            return 'A vevő neve és e-mail-címe kötelező.';
        }

        $vid = self::vevo_keres($vevo + ['nev' => $nev]);

        if ($vid > 0) {
            // Csak az üres mezőket töltjük ki – a kézzel gondozott adatot nem írjuk felül.
            $s = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('vevo') . ' WHERE id = %d', $vid));
            $f = [];

            foreach (['kapcsolattarto' => 190, 'email' => 190, 'telefon' => 60, 'cim' => 255, 'adoszam' => 40] as $k => $max) {
                $uj = $k === 'email' ? sanitize_email((string) ($vevo[$k] ?? '')) : sanitize_text_field((string) ($vevo[$k] ?? ''));

                if ($s !== null && trim((string) $s->$k) === '' && $uj !== '') {
                    $f[$k] = mb_substr($uj, 0, $max);
                }
            }

            if ($f !== []) {
                $f['modositva'] = SDH_Kozpont::most();
                $wpdb->update(SDH_Kozpont::t('vevo'), $f, ['id' => $vid]);
            }

            if ($s !== null && (string) $s->allapot === 'erdeklodo') {
                $wpdb->update(SDH_Kozpont::t('vevo'), ['allapot' => 'aktiv'], ['id' => $vid]);
            }

            SDH_Kozpont::naplo('vevo', 'Meglévő vevő az értékesítő webhelyről: ' . (string) ($s->nev ?? $nev) . '.', 'info', ['vevo_id' => $vid], $ki);

            return $vid;
        }

        return SDH_Kozpont::vevo_ment([
            'nev'            => $nev,
            'kapcsolattarto' => (string) ($vevo['kapcsolattarto'] ?? ''),
            'email'          => (string) ($vevo['email'] ?? ''),
            'telefon'        => (string) ($vevo['telefon'] ?? ''),
            'cim'            => (string) ($vevo['cim'] ?? ''),
            'adoszam'        => (string) ($vevo['adoszam'] ?? ''),
            'cimkek'         => 'webshop',
            'allapot'        => 'aktiv',
        ], $ki);
    }

    /**
     * A licenc a kérés vevőjéé-e: egyező adószám vagy e-mail-cím (a licenc vevőjénél).
     *
     * @param array<string, mixed> $vevo
     */
    public static function sajat(object $l, array $vevo): bool
    {
        global $wpdb;

        $v = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('vevo') . ' WHERE id = %d', (int) $l->vevo_id));

        if ($v === null) {
            return false;
        }

        $ado = self::adoszam_norm((string) ($vevo['adoszam'] ?? ''));

        if (strlen($ado) >= 8 && $ado === self::adoszam_norm((string) $v->adoszam)) {
            return true;
        }

        $email = strtolower(sanitize_email((string) ($vevo['email'] ?? '')));

        return $email !== '' && $email === strtolower((string) $v->email);
    }

    /* =================================================================
     * Egyszer használható letöltés
     * ============================================================== */

    /** Új letöltő link a licenchez (a token csak lenyomatként tárolódik). */
    public static function letoltes_link(int $licenc_id, string $rendeles = '', ?int $most = null): string
    {
        global $wpdb;

        $most  = $most ?? time();
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        $wpdb->insert(SDH_Kozpont::t('letoltes'), [
            'token_hash' => hash('sha256', $token),
            'licenc_id'  => $licenc_id,
            'rendeles'   => mb_substr($rendeles, 0, 40),
            'db'         => 0,
            'max_db'     => self::LETOLTES_MAX,
            'lejar'      => gmdate('Y-m-d H:i:s', $most + self::LETOLTES_NAP * DAY_IN_SECONDS),
            'letrehozva' => gmdate('Y-m-d H:i:s', $most),
        ]);

        return add_query_arg('t', $token, rest_url(SDH_Kozpont::NS . '/letoltes'));
    }

    /**
     * A token ellenőrzése és (ha $szamol) egy letöltés elhasználása. Siker: {fajl, verzio, licenc_id, maradt};
     * hiba: üzenet.
     *
     * @return array{fajl: string, verzio: string, licenc_id: int, maradt: int}|string
     */
    public static function letoltes_ellenoriz(string $token, bool $szamol = true, ?int $most = null)
    {
        global $wpdb;

        $most = $most ?? time();
        $rossz = 'A letöltő link lejárt vagy már nem érvényes. Új linket a fiókodból vagy a forgalmazótól kérhetsz.';

        if (preg_match('/^[A-Za-z0-9_\-]{20,64}$/', $token) !== 1) {
            return $rossz;
        }

        $t = SDH_Kozpont::t('letoltes');
        $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE token_hash = %s", hash('sha256', $token)));

        if ($s === null || (string) $s->lejar < gmdate('Y-m-d H:i:s', $most) || (int) $s->db >= (int) $s->max_db) {
            return $rossz;
        }

        $l = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Kozpont::t('licenc') . ' WHERE id = %d', (int) $s->licenc_id));

        if ($l === null || SDH_Kozpont::licenc_allapot($l, wp_date('Y-m-d', $most)) !== 'aktiv') {
            return 'Ehhez a licenchez most nem tölthető le a rendszer.';
        }

        $k = SDH_Kozpont::kiadas_licenchez($l);
        $f = (string) $k['fajl'];

        if ($f === '' || !is_file($f)) {
            return 'Még nincs letölthető kiadás. Kérlek, jelezd a forgalmazónak.';
        }

        if ($szamol) {
            $n = $wpdb->query($wpdb->prepare("UPDATE {$t} SET db = db + 1, utolso = %s WHERE id = %d AND db < max_db", gmdate('Y-m-d H:i:s', $most), (int) $s->id));

            if ((int) $n !== 1) {
                return $rossz;
            }

            SDH_Kozpont::naplo('letoltes', 'Letöltés a rendelés linkjével (' . (string) $s->rendeles . ', ' . (string) $k['verzio'] . ', ' . ((int) $s->db + 1) . '/' . (int) $s->max_db . ').', 'info', ['vevo_id' => (int) $l->vevo_id, 'licenc_id' => (int) $l->id], self::KI);
        }

        return ['fajl' => $f, 'verzio' => (string) $k['verzio'], 'licenc_id' => (int) $l->id, 'maradt' => max(0, (int) $s->max_db - (int) $s->db - ($szamol ? 1 : 0))];
    }

    /** @return WP_Error|never */
    public static function rest_letoltes(WP_REST_Request $r)
    {
        $e = self::letoltes_ellenoriz((string) $r->get_param('t'));

        if (is_string($e)) {
            return new WP_Error('sdh_kozpont_letoltes', $e, ['status' => 410]);
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="sdh-muhely-' . preg_replace('/[^0-9.]/', '', $e['verzio']) . '.zip"');
        header('Content-Length: ' . (string) filesize($e['fajl']));
        header('X-Robots-Tag: noindex');
        readfile($e['fajl']); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    /* =================================================================
     * Takarítás (a Központ 5 perces ütemezőjéből)
     * ============================================================== */

    public static function takarit(?int $most = null): void
    {
        global $wpdb;

        $most = $most ?? time();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Kozpont::t('nonce') . ' WHERE lejar < %d', $most));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Kozpont::t('letoltes') . ' WHERE lejar < %s', gmdate('Y-m-d H:i:s', $most - 30 * DAY_IN_SECONDS)));
        // Félbemaradt foglalás (válasz nélkül, 1 óránál régebbi): újra próbálható legyen.
        $wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Kozpont::t('rendeles') . ' WHERE valasz IS NULL AND ido < %s', wp_date('Y-m-d H:i:s', $most - HOUR_IN_SECONDS)));
    }
}
