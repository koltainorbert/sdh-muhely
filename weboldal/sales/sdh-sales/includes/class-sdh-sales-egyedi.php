<?php
/**
 * SDH Értékesítés – egyedi fejlesztés igényfelmérő: többlépéses űrlap (iparág, méret, folyamatok, integrációk, határidő,
 * keret, fájlok, NDA-kérés) → `lead` (forrás 'egyedi') + automatikus ajánlat-vázlat (strukturált összefoglaló és
 * munkaigény-sáv, BECSLÉSKÉNT jelölve), állapot a vevő fiókjában, értesítés az adminnak, visszaigazolás a látogatónak.
 *
 *   lepesek(): array                     – a front lépései és mezői (assets/front/egyedi.js ebből rajzol)
 *   vazlat(array $adat): array           – {osszefoglalo[], ora_min, ora_max, ar_min, ar_max, het_min, het_max, becsles: true,
 *                                          tetelek[{nev, ora_min, ora_max}], feltetelezesek[]}
 *   fiok_lista(string $email): array     – a vevő fiókjába (azon, dátum, állapot, összefoglaló, becslés)
 *   mappa(): string                      – védett feltöltési mappa (uploads/portal/egyedi, .htaccess + index.php)
 * REST: POST /egyedi (multipart: mezők + fajlok[]) · GET /admin/egyedi/{id}/fajl/{n} (letöltés adminnak)
 * Allapotok (lead.allapot): uj → felmeres → ajanlat → elfogadva | elutasitva | lezarva (az admin állítja).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Egyedi
{
    public const MAX_FAJL      = 5;
    public const MAX_FAJL_BAJT = 10 * 1024 * 1024;

    /** Engedett kiterjesztés → MIME (a tartalom alapján ellenőrizve). */
    public const TIPUSOK = [
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    public const ALLAPOT = [
        'uj'         => 'Beérkezett',
        'felmeres'   => 'Felmérés alatt',
        'ajanlat'    => 'Ajánlat elküldve',
        'elfogadva'  => 'Elfogadva – fejlesztés',
        'elutasitva' => 'Nem valósul meg',
        'lezarva'    => 'Lezárva',
    ];

    public static function init(): void
    {
        SDH_Sales_Rest::ut('/egyedi', 'POST', [self::class, 'rest_bekuld'], 'nyilvanos', ['egyedi', 4, 900]);
        SDH_Sales_Rest::ut('/admin/egyedi/(?P<id>\d+)/fajl/(?P<n>\d+)', 'GET', [self::class, 'rest_fajl'], 'admin');
        add_action('init', [self::class, 'front'], 6);
    }

    public static function front(): void
    {
        SDH_Sales_Front::modul('egyedi', 'assets/front/egyedi.js', ['kozos'], static fn (): array => [
            'lepesek'     => self::lepesek(),
            'vedelem'     => SDH_Sales_Biztonsag::front_adat(),
            'max_fajl'    => self::MAX_FAJL,
            'max_mb'      => (int) (self::MAX_FAJL_BAJT / 1048576),
            'tipusok'     => array_keys(self::TIPUSOK),
            'adatvedelem' => home_url(user_trailingslashit((string) SDH_Sales::b('oldal.adatvedelem', 'adatkezeles'))),
        ]);
    }

    /** @return array<string, array<int, string>> a választható értékek */
    private static function opciok(): array
    {
        return [
            'iparag'     => [__('Elektronikai / telefonszerviz', 'sdh-sales'), __('Számítástechnikai szerviz', 'sdh-sales'), __('Háztartásigép-szerviz', 'sdh-sales'), __('Autó- / motorszerviz', 'sdh-sales'), __('Kereskedelem', 'sdh-sales'), __('Egyéb szolgáltatás', 'sdh-sales')],
            'meret'      => [__('1–2 fő', 'sdh-sales'), __('3–10 fő', 'sdh-sales'), __('11–50 fő', 'sdh-sales'), __('50 fő felett', 'sdh-sales')],
            'folyamatok' => [__('Munkalap / javításkövetés', 'sdh-sales'), __('Készlet és beszerzés', 'sdh-sales'), __('Értékesítés / kassza', 'sdh-sales'), __('Számlázás', 'sdh-sales'), __('Ügyfélkommunikáció (SMS, e-mail)', 'sdh-sales'), __('Időpontfoglalás', 'sdh-sales'), __('Riportok, kimutatások', 'sdh-sales'), __('Garancia / RMA', 'sdh-sales'), __('Több telephely kezelése', 'sdh-sales'), __('Egyedi nyomtatványok', 'sdh-sales')],
            'integraciok' => [__('Számlázó program', 'sdh-sales'), __('Webshop', 'sdh-sales'), __('Könyvelő program', 'sdh-sales'), __('Beszállítói rendszer / API', 'sdh-sales'), __('Futárszolgálat', 'sdh-sales'), __('Bank / online fizetés', 'sdh-sales'), __('Meglévő adatbázis átvétele', 'sdh-sales'), __('Egyéb', 'sdh-sales')],
            'hatarido'   => [__('1 hónapon belül', 'sdh-sales'), __('1–3 hónap', 'sdh-sales'), __('3–6 hónap', 'sdh-sales'), __('Nem sürgős', 'sdh-sales')],
            'keret'      => [__('500 ezer Ft alatt', 'sdh-sales'), __('500 ezer – 1,5 millió Ft', 'sdh-sales'), __('1,5 – 5 millió Ft', 'sdh-sales'), __('5 millió Ft felett', 'sdh-sales'), __('Még nem tudom', 'sdh-sales')],
        ];
    }

    /** @return array<int, array{cim: string, mezok: array<int, array<string, mixed>>}> */
    public static function lepesek(): array
    {
        $o = self::opciok();

        return [
            ['cim' => __('A vállalkozás', 'sdh-sales'), 'mezok' => [
                ['nev' => 'iparag', 'cimke' => __('Iparág', 'sdh-sales'), 'tipus' => 'radio', 'kotelezo' => true, 'opciok' => $o['iparag']],
                ['nev' => 'meret', 'cimke' => __('Hányan használnák?', 'sdh-sales'), 'tipus' => 'radio', 'kotelezo' => true, 'opciok' => $o['meret']],
                ['nev' => 'telephely', 'cimke' => __('Telephelyek száma', 'sdh-sales'), 'tipus' => 'number', 'kotelezo' => true],
            ]],
            ['cim' => __('Mit kell tudnia?', 'sdh-sales'), 'mezok' => [
                ['nev' => 'folyamatok', 'cimke' => __('Folyamatok', 'sdh-sales'), 'tipus' => 'checkbox', 'kotelezo' => true, 'opciok' => $o['folyamatok']],
                ['nev' => 'integraciok', 'cimke' => __('Kapcsolódó rendszerek', 'sdh-sales'), 'tipus' => 'checkbox', 'kotelezo' => false, 'opciok' => $o['integraciok']],
                ['nev' => 'leiras', 'cimke' => __('Írd le a saját szavaiddal, mit szeretnél', 'sdh-sales'), 'tipus' => 'textarea', 'kotelezo' => true, 'max' => 6000],
            ]],
            ['cim' => __('Időzítés és keret', 'sdh-sales'), 'mezok' => [
                ['nev' => 'hatarido', 'cimke' => __('Mikorra kellene?', 'sdh-sales'), 'tipus' => 'radio', 'kotelezo' => true, 'opciok' => $o['hatarido']],
                ['nev' => 'keret', 'cimke' => __('Tervezett keret (nettó)', 'sdh-sales'), 'tipus' => 'radio', 'kotelezo' => true, 'opciok' => $o['keret']],
                ['nev' => 'fajlok', 'cimke' => __('Csatolmány (minta nyomtatvány, táblázat, képernyőkép)', 'sdh-sales'), 'tipus' => 'file', 'kotelezo' => false],
            ]],
            ['cim' => __('Elérhetőség', 'sdh-sales'), 'mezok' => [
                ['nev' => 'nev', 'cimke' => __('Neved', 'sdh-sales'), 'tipus' => 'text', 'kotelezo' => true, 'max' => 120],
                ['nev' => 'ceg', 'cimke' => __('Cég neve', 'sdh-sales'), 'tipus' => 'text', 'kotelezo' => true, 'max' => 190],
                ['nev' => 'email', 'cimke' => __('E-mail-cím', 'sdh-sales'), 'tipus' => 'email', 'kotelezo' => true, 'max' => 190],
                ['nev' => 'telefon', 'cimke' => __('Telefonszám', 'sdh-sales'), 'tipus' => 'tel', 'kotelezo' => false, 'max' => 40],
                ['nev' => 'nda', 'cimke' => __('Titoktartási megállapodást (NDA) kérek a részletek előtt', 'sdh-sales'), 'tipus' => 'kapcsolo', 'kotelezo' => false],
            ]],
        ];
    }

    /**
     * Ajánlat-vázlat: szabályalapú munkaigény-becslés (óra-sáv), az óradíj a `modulfejlesztes` szolgáltatás ára.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    public static function vazlat(array $a): array
    {
        $o       = self::opciok();
        $tetelek = [['nev' => __('Igényfelmérés, specifikáció', 'sdh-sales'), 'ora_min' => 4, 'ora_max' => 8]];
        $folyam  = array_values(array_intersect((array) ($a['folyamatok'] ?? []), $o['folyamatok']));
        $integr  = array_values(array_intersect((array) ($a['integraciok'] ?? []), $o['integraciok']));

        foreach ($folyam as $f) {
            $tetelek[] = ['nev' => $f, 'ora_min' => 6, 'ora_max' => 16];
        }

        foreach ($integr as $i) {
            $nagy      = in_array($i, [$o['integraciok'][3], $o['integraciok'][6]], true);
            $tetelek[] = ['nev' => sprintf(__('Integráció: %s', 'sdh-sales'), $i), 'ora_min' => $nagy ? 12 : 8, 'ora_max' => $nagy ? 32 : 20];
        }

        $hossz = mb_strlen((string) ($a['leiras'] ?? ''));

        if ($hossz > 1500) {
            $tetelek[] = ['nev' => __('Részletes egyedi igények feldolgozása', 'sdh-sales'), 'ora_min' => 6, 'ora_max' => 16];
        }

        $tetelek[] = ['nev' => __('Tesztelés, átadás, betanítás', 'sdh-sales'), 'ora_min' => 4, 'ora_max' => 10];

        $szorzo = [1.0, 1.0, 1.15, 1.3][array_search((string) ($a['meret'] ?? ''), $o['meret'], true) ?: 0];
        $tel    = max(1, (int) ($a['telephely'] ?? 1));
        $szorzo += $tel > 1 ? min(0.3, 0.05 * ($tel - 1)) : 0.0;

        $min = (int) ceil(array_sum(array_column($tetelek, 'ora_min')) * $szorzo);
        $max = (int) ceil(array_sum(array_column($tetelek, 'ora_max')) * $szorzo);
        $sz  = SDH_Sales_Katalogus::szolgaltatas('modulfejlesztes');
        $ora = (int) ($sz['ar'] ?? 0) > 0 ? (int) $sz['ar'] : (int) SDH_Sales::b('egyedi.oradij', 14900);

        $sor = [
            sprintf(__('Iparág: %s; méret: %s; telephely: %d.', 'sdh-sales'), (string) ($a['iparag'] ?? '–'), (string) ($a['meret'] ?? '–'), $tel),
            sprintf(__('Folyamatok (%d): %s.', 'sdh-sales'), count($folyam), $folyam !== [] ? implode(', ', $folyam) : '–'),
            sprintf(__('Kapcsolódó rendszerek (%d): %s.', 'sdh-sales'), count($integr), $integr !== [] ? implode(', ', $integr) : '–'),
            sprintf(__('Határidő: %s; keret: %s.', 'sdh-sales'), (string) ($a['hatarido'] ?? '–'), (string) ($a['keret'] ?? '–')),
        ];

        if (!empty($a['nda'])) {
            $sor[] = __('NDA-t kért a részletek előtt.', 'sdh-sales');
        }

        return [
            'becsles'        => true,
            'cimke'          => __('BECSLÉS – nem ajánlat; a pontos árat az igényfelmérés után adjuk meg.', 'sdh-sales'),
            'osszefoglalo'   => $sor,
            'tetelek'        => $tetelek,
            'szorzo'         => round($szorzo, 2),
            'ora_min'        => $min,
            'ora_max'        => $max,
            'oradij'         => $ora,
            'ar_min'         => $min * $ora,
            'ar_max'         => $max * $ora,
            'het_min'        => max(1, (int) ceil($min / 25)),
            'het_max'        => max(2, (int) ceil($max / 15)),
            'feltetelezesek' => [
                __('A meglévő rendszerre épül; az alapfunkciókat (ügyfél, munkalap, jogosultság) nem kell újraírni.', 'sdh-sales'),
                __('A külső rendszereknek van dokumentált hozzáférése (API vagy export).', 'sdh-sales'),
                __('Az árak nettók, az áfát külön számítjuk fel.', 'sdh-sales'),
            ],
        ];
    }

    /* =================================================================
     * Fájlok (védett mappa)
     * ============================================================== */

    public static function mappa(): string
    {
        $u = wp_upload_dir();
        $m = trailingslashit((string) $u['basedir']) . 'portal/egyedi';

        if (!is_dir($m)) {
            wp_mkdir_p($m);
        }

        $ht = $m . '/.htaccess';

        if (!is_file($ht)) {
            file_put_contents($ht, "Require all denied\nDeny from all\n");
        }

        if (!is_file($m . '/index.php')) {
            file_put_contents($m . '/index.php', "<?php\nhttp_response_code(404);\n");
        }

        return $m;
    }

    /**
     * Feltöltés ellenőrzése: méret, kiterjesztés, tartalom szerinti MIME, kettős kiterjesztés, futtatható tartalom.
     *
     * @param array<string, mixed> $f egy $_FILES-elem
     * @return array{ok: bool, hiba?: string, kit?: string}
     */
    public static function fajl_ellenoriz(array $f): array
    {
        $nev = (string) ($f['name'] ?? '');
        $tmp = (string) ($f['tmp_name'] ?? '');

        if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'hiba' => sprintf(__('A(z) „%s” fájl feltöltése nem sikerült.', 'sdh-sales'), $nev)];
        }

        if ((int) $f['size'] > self::MAX_FAJL_BAJT || filesize($tmp) > self::MAX_FAJL_BAJT) {
            return ['ok' => false, 'hiba' => sprintf(__('A(z) „%s” túl nagy (legfeljebb %d MB).', 'sdh-sales'), $nev, (int) (self::MAX_FAJL_BAJT / 1048576))];
        }

        $kit = strtolower(pathinfo($nev, PATHINFO_EXTENSION));

        if (!isset(self::TIPUSOK[$kit]) || preg_match('/\.(php\d?|phtml|phar|pl|py|cgi|sh|exe|js|html?|svg)\./i', $nev) === 1) {
            return ['ok' => false, 'hiba' => sprintf(__('A(z) „%s” típusa nem engedett (PDF, kép, Office, TXT, CSV, ZIP).', 'sdh-sales'), $nev)];
        }

        $mime = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp) : '';

        if ($mime !== '' && !in_array($mime, self::TIPUSOK[$kit], true)) {
            return ['ok' => false, 'hiba' => sprintf(__('A(z) „%s” tartalma nem egyezik a kiterjesztésével.', 'sdh-sales'), $nev)];
        }

        $eleje = (string) file_get_contents($tmp, false, null, 0, 4096);

        if (preg_match('/<\?php|<script|<\?=/i', $eleje) === 1) {
            return ['ok' => false, 'hiba' => sprintf(__('A(z) „%s” nem tölthető fel.', 'sdh-sales'), $nev)];
        }

        return ['ok' => true, 'kit' => $kit];
    }

    /**
     * @param array<int, array<string, mixed>> $fajlok normalizált $_FILES-lista
     * @return array{mentve: array<int, array{nev: string, fajl: string, meret: int}>, hibak: array<int, string>}
     */
    private static function fajlok_ment(array $fajlok, int $lead_id): array
    {
        $ki  = ['mentve' => [], 'hibak' => []];
        $dir = self::mappa();

        foreach (array_slice($fajlok, 0, self::MAX_FAJL) as $f) {
            $e = self::fajl_ellenoriz($f);

            if (!$e['ok']) {
                $ki['hibak'][] = (string) $e['hiba'];
                continue;
            }

            $cel = $lead_id . '-' . bin2hex(random_bytes(12)) . '.' . $e['kit'];
            $ok  = is_uploaded_file((string) $f['tmp_name']) ? move_uploaded_file((string) $f['tmp_name'], $dir . '/' . $cel) : rename((string) $f['tmp_name'], $dir . '/' . $cel);

            if ($ok) {
                @chmod($dir . '/' . $cel, 0640);
                $ki['mentve'][] = ['nev' => sanitize_file_name((string) $f['name']), 'fajl' => $cel, 'meret' => (int) filesize($dir . '/' . $cel)];
            }
        }

        return $ki;
    }

    /** @return array<int, array<string, mixed>> */
    private static function fajlok_normal(WP_REST_Request $q): array
    {
        $f = $q->get_file_params()['fajlok'] ?? null;

        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }

        if (!is_array($f['name'])) {
            return [$f];
        }

        $ki = [];

        foreach (array_keys($f['name']) as $i) {
            $ki[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
        }

        return $ki;
    }

    /* =================================================================
     * Beküldés
     * ============================================================== */

    /**
     * @param array<string, mixed> $be
     * @return array{adat: array<string, mixed>, hibak: array<string, string>}
     */
    public static function ellenoriz(array $be): array
    {
        $adat  = [];
        $hibak = [];

        foreach (self::lepesek() as $l) {
            foreach ($l['mezok'] as $m) {
                $n = (string) $m['nev'];

                if ($m['tipus'] === 'file') {
                    continue;
                }

                if ($m['tipus'] === 'checkbox') {
                    $v        = is_array($be[$n] ?? null) ? $be[$n] : (is_string($be[$n] ?? null) && $be[$n] !== '' ? explode('|', (string) $be[$n]) : []);
                    $v        = array_values(array_intersect(array_map('strval', $v), (array) $m['opciok']));
                    $adat[$n] = $v;

                    if (!empty($m['kotelezo']) && $v === []) {
                        $hibak[$n] = sprintf(__('Válassz legalább egyet: %s.', 'sdh-sales'), (string) $m['cimke']);
                    }

                    continue;
                }

                if ($m['tipus'] === 'kapcsolo') {
                    $adat[$n] = in_array($be[$n] ?? false, [true, 1, '1', 'true', 'on', 'igen'], true);
                    continue;
                }

                if ($m['tipus'] === 'radio') {
                    $v        = sanitize_text_field((string) ($be[$n] ?? ''));
                    $adat[$n] = in_array($v, (array) $m['opciok'], true) ? $v : '';

                    if ($adat[$n] === '' && !empty($m['kotelezo'])) {
                        $hibak[$n] = sprintf(__('Válassz: %s.', 'sdh-sales'), (string) $m['cimke']);
                    }

                    continue;
                }

                if ($m['tipus'] === 'number') {
                    $v        = (string) ($be[$n] ?? '');
                    $adat[$n] = ctype_digit($v) ? min(999, (int) $v) : 0;

                    if ($adat[$n] < 1 && !empty($m['kotelezo'])) {
                        $hibak[$n] = sprintf(__('%s: pozitív egész számot adj meg.', 'sdh-sales'), (string) $m['cimke']);
                    }

                    continue;
                }

                $e = SDH_Sales_Urlap::ellenoriz([$m], $be);
                $adat[$n] = $e['adat'][$n];
                $hibak   += $e['hibak'];
            }
        }

        return ['adat' => $adat, 'hibak' => $hibak];
    }

    public static function rest_bekuld(WP_REST_Request $q)
    {
        $v = SDH_Sales_Biztonsag::ellenoriz($q);

        if (is_wp_error($v)) {
            return $v;
        }

        $p = (array) $q->get_params();

        if (empty($p['adatkezeles'])) {
            return new WP_REST_Response(['ok' => false, 'message' => __('Ellenőrizd a megjelölt mezőket.', 'sdh-sales'), 'hibak' => ['adatkezeles' => __('Az elküldéshez fogadd el az adatkezelési tájékoztatót.', 'sdh-sales')]], 400);
        }

        $e = self::ellenoriz($p);

        if ($e['hibak'] !== []) {
            return new WP_REST_Response(['ok' => false, 'message' => __('Ellenőrizd a megjelölt mezőket.', 'sdh-sales'), 'hibak' => $e['hibak']], 400);
        }

        $fajlok = self::fajlok_normal($q);

        if (count($fajlok) > self::MAX_FAJL) {
            return new WP_REST_Response(['ok' => false, 'message' => sprintf(__('Legfeljebb %d fájlt csatolhatsz.', 'sdh-sales'), self::MAX_FAJL), 'hibak' => ['fajlok' => sprintf(__('Legfeljebb %d fájlt csatolhatsz.', 'sdh-sales'), self::MAX_FAJL)]], 400);
        }

        foreach ($fajlok as $f) {
            $fe = self::fajl_ellenoriz($f);

            if (!$fe['ok']) {
                return new WP_REST_Response(['ok' => false, 'message' => (string) $fe['hiba'], 'hibak' => ['fajlok' => (string) $fe['hiba']]], 400);
            }
        }

        $adat  = $e['adat'];
        $vazl  = self::vazlat($adat);
        $id    = SDH_Sales_Urlap::lead_ment('egyedi', $adat + ['vazlat' => $vazl, 'fajlok' => []], (string) $adat['email']);
        $f     = self::fajlok_ment($fajlok, $id);
        $azon  = 'E-' . wp_date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);

        global $wpdb;

        $wpdb->update(SDH_Sales_Schema::t('lead'), ['adat' => (string) wp_json_encode($adat + ['azon' => $azon, 'vazlat' => $vazl, 'fajlok' => $f['mentve']], JSON_UNESCAPED_UNICODE)], ['id' => $id]);

        SDH_Sales_Urlap::ertesit(sprintf(__('Egyedi fejlesztési igény %s – %s', 'sdh-sales'), $azon, (string) $adat['ceg']), [
            __('Név', 'sdh-sales')        => (string) $adat['nev'],
            __('Cég', 'sdh-sales')        => (string) $adat['ceg'],
            __('E-mail', 'sdh-sales')     => (string) $adat['email'],
            __('Telefon', 'sdh-sales')    => (string) $adat['telefon'],
            __('Összefoglaló', 'sdh-sales') => implode(' ', $vazl['osszefoglalo']),
            __('Leírás', 'sdh-sales')     => (string) $adat['leiras'],
            __('Becslés', 'sdh-sales')    => sprintf('%d–%d óra, %s – %s (becslés)', $vazl['ora_min'], $vazl['ora_max'], SDH_Sales::ft($vazl['ar_min']), SDH_Sales::ft($vazl['ar_max'])),
            __('Csatolmány', 'sdh-sales') => (string) count($f['mentve']),
            'NDA'                          => !empty($adat['nda']) ? __('kér', 'sdh-sales') : __('nem kér', 'sdh-sales'),
        ], (string) $adat['email']);

        SDH_Sales_Fizetes_Atutalas::level('egyedi_visszaigazolas', (string) $adat['email'], [
            '_targy'       => sprintf(__('Megkaptuk az igényedet – %s', 'sdh-sales'), $azon),
            'nev'          => (string) $adat['nev'],
            'azon'         => $azon,
            'osszefoglalo' => implode("\n", $vazl['osszefoglalo']),
            'valaszido'    => (string) SDH_Sales::b('lead.valaszido', __('két munkanapon belül', 'sdh-sales')),
        ]);

        return new WP_REST_Response([
            'ok'     => true,
            'azon'   => $azon,
            'vazlat' => $vazl,
            'fajlok' => count($f['mentve']),
            'hibak'  => $f['hibak'],
            'uzenet' => __('Köszönjük! Megkaptuk az igényedet; a becslés tájékoztató jellegű, a pontos ajánlatot a felmérés után küldjük.', 'sdh-sales'),
        ], 201);
    }

    /** @return array<int, array<string, mixed>> */
    public static function fiok_lista(string $email): array
    {
        global $wpdb;

        if (!is_email($email)) {
            return [];
        }

        $ki = [];

        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, adat, allapot, ido FROM ' . SDH_Sales_Schema::t('lead') . " WHERE forras = 'egyedi' AND email = %s ORDER BY id DESC LIMIT 50", strtolower($email)), ARRAY_A) as $s) {
            $a    = json_decode((string) $s['adat'], true);
            $a    = is_array($a) ? $a : [];
            $v    = (array) ($a['vazlat'] ?? []);
            $ki[] = [
                'azon'           => (string) ($a['azon'] ?? ('E-' . (int) $s['id'])),
                'datum'          => (string) $s['ido'],
                'allapot'        => (string) $s['allapot'],
                'allapot_szoveg' => __(self::ALLAPOT[(string) $s['allapot']] ?? (string) $s['allapot'], 'sdh-sales'),
                'osszefoglalo'   => (array) ($v['osszefoglalo'] ?? []),
                'becsles'        => isset($v['ora_min']) ? ['ora_min' => (int) $v['ora_min'], 'ora_max' => (int) $v['ora_max'], 'ar_min' => (int) $v['ar_min'], 'ar_max' => (int) $v['ar_max'], 'cimke' => (string) ($v['cimke'] ?? '')] : null,
            ];
        }

        return $ki;
    }

    public static function rest_fajl(WP_REST_Request $q)
    {
        global $wpdb;

        $a = json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT adat FROM ' . SDH_Sales_Schema::t('lead') . " WHERE id = %d AND forras = 'egyedi'", (int) $q['id'])), true);
        $f = is_array($a) ? ($a['fajlok'][(int) $q['n']] ?? null) : null;
        $u = is_array($f) ? self::mappa() . '/' . basename((string) $f['fajl']) : '';

        if ($u === '' || !is_file($u)) {
            return SDH_Sales_Rest::hiba(__('Nincs ilyen fájl.', 'sdh-sales'), 404, 'nincs');
        }

        $kit  = strtolower(pathinfo($u, PATHINFO_EXTENSION));
        $v    = SDH_Sales_Rendeles::nyers((string) file_get_contents($u), (string) (self::TIPUSOK[$kit][0] ?? 'application/octet-stream'));
        $v->header('Content-Disposition', 'attachment; filename="' . sanitize_file_name((string) $f['nev']) . '"');
        $v->header('X-Content-Type-Options', 'nosniff');

        return $v;
    }
}
