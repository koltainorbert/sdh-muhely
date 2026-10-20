<?php
/**
 * Mentési célok (0.73.0, H3): hová másolódik a titkosított mentés a szerveren kívül.
 *
 * Négy hely, mind kapcsolható:
 *   gep    – saját gép: egy mappa azon a gépen, ahol a rendszer fut (külső lemez, másik partíció);
 *   nas    – hálózati tároló: csatolt / hálózati mappa (UNC is), vagy WebDAV (Synology, QNAP);
 *   ftp    – FTP vagy FTPS (explicit TLS);
 *   gdrive – Google Drive (a saját Google-fiókba, külön mappába; lásd class-sdh-muhely-mentes-gdrive.php).
 *
 * Ez a fájl az alaposztályt, a mappás és a WebDAV-meghajtót, és a célok
 * nyilvántartását tartalmazza (beállítások, titkos mezők, jelszó). A mentés
 * fájlneve minden helyen azonos, a tartalom mindenhol jelszóval titkosított.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ===================================================================== */

abstract class SDH_Muhely_Mentes_Cel_Alap
{
    /** @var array<string, mixed> */
    protected array $be;

    /** @param array<string, mixed> $be a cél beállításai (a titkos mezők már visszafejtve) */
    public function __construct(array $be)
    {
        $this->be = $be;
    }

    /** Kapcsolat- és íráspróba. @return string|null null = rendben, különben a hiba */
    abstract public function tesztel(): ?string;

    /** Egy helyi fájl feltöltése. A fájl csak hibátlan, teljes méretű másolat után kerül a végleges nevére. */
    abstract public function feltolt(string $helyi, string $nev): ?string;

    /** @return array<int, array{fajl: string, meret: int, ido: int}>|string a mentések listája, vagy a hiba */
    abstract public function listaz();

    abstract public function letolt(string $nev, string $cel): ?string;

    abstract public function torol(string $nev): ?string;

    /** Mentésfájl-e a név (a mi nevezéktanunk; idegen fájlhoz soha nem nyúlunk). */
    public static function mentes_nev(string $nev): bool
    {
        return preg_match('/^sdh-mentes-[\w\-]+\.sdhmentes$/', $nev) === 1;
    }

    protected function s(string $kulcs, string $alap = ''): string
    {
        $v = $this->be[$kulcs] ?? $alap;

        return is_scalar($v) ? trim((string) $v) : $alap;
    }
}

/* ===================================================================== */

/** Mappa: saját gép, csatolt vagy hálózati meghajtó (UNC is). */
final class SDH_Muhely_Mentes_Cel_Mappa extends SDH_Muhely_Mentes_Cel_Alap
{
    private function mappa(): string
    {
        $m = $this->s('mappa');

        return $m === '' ? '' : rtrim($m, "/\\") . DIRECTORY_SEPARATOR;
    }

    private function kesz(): ?string
    {
        $m = $this->mappa();

        if ($m === '') {
            return 'Nincs megadva mappa.';
        }

        if (class_exists('SDH_Muhely_Asszisztens_Tudas') && realpath($m) !== false
            && realpath($m) === realpath(SDH_Muhely_Asszisztens_Tudas::mentes_mappa())) {
            return 'Ez a rendszer saját mentési mappája – válassz másik helyet, másik lemezen.';
        }

        if (!is_dir($m) && !@mkdir($m, 0775, true) && !is_dir($m)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
            return 'A mappa nem érhető el, és nem hozható létre: ' . $m;
        }

        if (!is_writable($m)) {
            return 'A mappába nem lehet írni: ' . $m;
        }

        return null;
    }

    public function tesztel(): ?string
    {
        if (($h = $this->kesz()) !== null) {
            return $h;
        }

        $proba = $this->mappa() . '.sdh-proba-' . wp_generate_password(6, false);

        if (@file_put_contents($proba, 'ok') === false) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
            return 'A mappába nem sikerült írni: ' . $this->mappa();
        }

        @unlink($proba); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        return null;
    }

    public function feltolt(string $helyi, string $nev): ?string
    {
        if (($h = $this->kesz()) !== null) {
            return $h;
        }

        $cel = $this->mappa() . $nev;
        $tmp = $cel . '.tmp';

        if (!@copy($helyi, $tmp)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return 'A másolás nem sikerült (megtelt a lemez, vagy megszakadt a kapcsolat): ' . $this->mappa();
        }

        clearstatcache(true, $tmp);

        if (filesize($tmp) !== filesize($helyi) || !@rename($tmp, $cel)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return 'A másolat hiányos – a feltöltés megismétlődik.';
        }

        return null;
    }

    public function listaz()
    {
        $m = $this->mappa();

        if ($m === '' || !is_dir($m)) {
            return 'A mappa nem érhető el: ' . $m;
        }

        $ki = [];

        foreach (new DirectoryIterator($m) as $f) {
            if ($f->isFile() && self::mentes_nev($f->getFilename())) {
                $ki[] = ['fajl' => $f->getFilename(), 'meret' => (int) $f->getSize(), 'ido' => (int) $f->getMTime()];
            }
        }

        return $ki;
    }

    public function letolt(string $nev, string $cel): ?string
    {
        if (!self::mentes_nev($nev) || !is_file($this->mappa() . $nev)) {
            return 'Nincs ilyen mentés a mappában.';
        }

        return @copy($this->mappa() . $nev, $cel) ? null : 'A mentés nem másolható át.'; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
    }

    public function torol(string $nev): ?string
    {
        if (!self::mentes_nev($nev)) {
            return 'Érvénytelen fájlnév.';
        }

        $ut = $this->mappa() . $nev;

        return !is_file($ut) || @unlink($ut) ? null : 'A régi mentés nem törölhető: ' . $nev; // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
}

/* ===================================================================== */

/** WebDAV (NAS-ok beépített szolgáltatása). A fájlokat folyamként tölti, a memóriát nem terheli. */
final class SDH_Muhely_Mentes_Cel_Webdav extends SDH_Muhely_Mentes_Cel_Alap
{
    private function alap(): string
    {
        $u = $this->s('webdav_url');

        return $u === '' ? '' : rtrim($u, '/') . '/';
    }

    /**
     * @param array<string, mixed> $opt
     * @return array{kod: int, torzs: string}|string
     */
    private function kerek(string $metodus, string $url, array $opt = [])
    {
        if (!function_exists('curl_init')) {
            return 'A szerveren nincs cURL – a WebDAV nem használható.';
        }

        $ch = curl_init($url);
        $fej = $opt['fejlec'] ?? [];
        $fej[] = 'Expect:';

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodus,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => (int) ($opt['idokorlat'] ?? 60),
            CURLOPT_HTTPHEADER     => $fej,
            CURLOPT_USERPWD        => $this->s('felhasznalo') . ':' . $this->s('jelszo'),
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => $this->s('ssl_ell', '1') !== '0',
            CURLOPT_SSL_VERIFYHOST => $this->s('ssl_ell', '1') !== '0' ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $nyitott = [];

        if (isset($opt['feltolt'])) {
            $fh = fopen($opt['feltolt'], 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions

            if ($fh === false) {
                return 'A helyi fájl nem olvasható.';
            }

            $nyitott[] = $fh;
            curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => (int) filesize($opt['feltolt'])]);
        } elseif (isset($opt['torzs'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['torzs']);
        }

        if (isset($opt['ide'])) {
            $fh = fopen($opt['ide'], 'wb'); // phpcs:ignore WordPress.WP.AlternativeFunctions

            if ($fh === false) {
                return 'A letöltés célja nem írható.';
            }

            $nyitott[] = $fh;
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FILE => $fh]);
        }

        $torzs = curl_exec($ch);
        $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hiba  = curl_error($ch);
        curl_close($ch);

        foreach ($nyitott as $fh) {
            fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        if ($torzs === false && $hiba !== '') {
            return 'A WebDAV-kiszolgáló nem érhető el: ' . $hiba;
        }

        if ($kod === 401 || $kod === 403) {
            return 'A WebDAV elutasította a belépést (' . $kod . ') – ellenőrizd a felhasználót, a jelszót és a jogosultságot.';
        }

        return ['kod' => $kod, 'torzs' => is_string($torzs) ? $torzs : ''];
    }

    private function hiba_kod(int $kod, string $mit): string
    {
        return 'A WebDAV ' . $mit . ' nem sikerült (HTTP ' . $kod . ').';
    }

    public function tesztel(): ?string
    {
        if ($this->alap() === '') {
            return 'Nincs megadva a WebDAV-cím.';
        }

        // A mappa létrehozása (ha már megvan, a kiszolgáló 405-öt ad – az rendben van).
        $r = $this->kerek('MKCOL', $this->alap());

        if (is_string($r)) {
            return $r;
        }

        if (!in_array($r['kod'], [200, 201, 204, 301, 405], true)) {
            return $this->hiba_kod($r['kod'], 'mappa létrehozása');
        }

        $proba = $this->alap() . '.sdh-proba-' . wp_generate_password(6, false);
        $r     = $this->kerek('PUT', $proba, ['torzs' => 'ok']);

        if (is_string($r)) {
            return $r;
        }

        if ($r['kod'] < 200 || $r['kod'] > 299) {
            return $this->hiba_kod($r['kod'], 'próbafájl írása');
        }

        $this->kerek('DELETE', $proba);

        return null;
    }

    public function feltolt(string $helyi, string $nev): ?string
    {
        if ($this->alap() === '') {
            return 'Nincs megadva a WebDAV-cím.';
        }

        $mk = $this->kerek('MKCOL', $this->alap());

        if (is_string($mk)) {
            return $mk;
        }

        $tmp = $this->alap() . rawurlencode($nev) . '.tmp';
        $r   = $this->kerek('PUT', $tmp, ['feltolt' => $helyi, 'idokorlat' => 3600]);

        if (is_string($r)) {
            return $r;
        }

        if ($r['kod'] < 200 || $r['kod'] > 299) {
            return $this->hiba_kod($r['kod'], 'feltöltése');
        }

        $mv = $this->kerek('MOVE', $tmp, ['fejlec' => ['Destination: ' . $this->alap() . rawurlencode($nev), 'Overwrite: T']]);

        if (is_string($mv) || $mv['kod'] < 200 || $mv['kod'] > 299) {
            $this->kerek('DELETE', $tmp);

            return is_string($mv) ? $mv : $this->hiba_kod($mv['kod'], 'átnevezése (MOVE)');
        }

        $lista = $this->listaz();

        if (is_array($lista)) {
            foreach ($lista as $m) {
                if ($m['fajl'] === $nev) {
                    return $m['meret'] === (int) filesize($helyi) ? null : 'A feltöltött másolat mérete eltér – a feltöltés megismétlődik.';
                }
            }

            return 'A feltöltött fájl nem jelent meg a mappában.';
        }

        return null;
    }

    public function listaz()
    {
        $r = $this->kerek('PROPFIND', $this->alap(), ['fejlec' => ['Depth: 1', 'Content-Type: application/xml'], 'torzs' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getlastmodified/><d:resourcetype/></d:prop></d:propfind>']);

        if (is_string($r)) {
            return $r;
        }

        if ($r['kod'] !== 207 && $r['kod'] !== 200) {
            return $this->hiba_kod($r['kod'], 'listázása');
        }

        $dom = new DOMDocument();

        if (!@$dom->loadXML($r['torzs'], LIBXML_NONET)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return 'A WebDAV válasza nem értelmezhető.';
        }

        $ki = [];

        foreach ($dom->getElementsByTagNameNS('*', 'response') as $v) {
            $href = $v->getElementsByTagNameNS('*', 'href')->item(0);
            $nev  = $href ? rawurldecode(basename(rtrim((string) $href->textContent, '/'))) : '';

            if (!self::mentes_nev($nev) || $v->getElementsByTagNameNS('*', 'collection')->length > 0) {
                continue;
            }

            $m = $v->getElementsByTagNameNS('*', 'getcontentlength')->item(0);
            $t = $v->getElementsByTagNameNS('*', 'getlastmodified')->item(0);
            $ki[] = ['fajl' => $nev, 'meret' => $m ? (int) $m->textContent : 0, 'ido' => $t ? (int) strtotime((string) $t->textContent) : 0];
        }

        return $ki;
    }

    public function letolt(string $nev, string $cel): ?string
    {
        if (!self::mentes_nev($nev)) {
            return 'Érvénytelen fájlnév.';
        }

        $r = $this->kerek('GET', $this->alap() . rawurlencode($nev), ['ide' => $cel, 'idokorlat' => 3600]);

        if (is_string($r)) {
            return $r;
        }

        return $r['kod'] === 200 ? null : $this->hiba_kod($r['kod'], 'letöltése');
    }

    public function torol(string $nev): ?string
    {
        if (!self::mentes_nev($nev)) {
            return 'Érvénytelen fájlnév.';
        }

        $r = $this->kerek('DELETE', $this->alap() . rawurlencode($nev));

        if (is_string($r)) {
            return $r;
        }

        return ($r['kod'] >= 200 && $r['kod'] < 300) || $r['kod'] === 404 ? null : $this->hiba_kod($r['kod'], 'törlése');
    }
}

/* ===================================================================== */

/** A célok nyilvántartása: beállítások, titkos mezők, a titkosító jelszó. */
final class SDH_Muhely_Mentes_Cel
{
    public const OPT        = 'sdh_muhely_mentes_cel';
    public const OPT_JELSZO = 'sdh_muhely_mentes_titkositas';
    /** Futási állapot (utolsó feltöltés, hiba, várakozó fájlok) – helyreállításkor nem töltődik vissza. */
    public const OPT_ALLAPOT = 'sdh_muhely_mentes_cel_allapot';

    /** @return array<string, array{nev: string, leiras: string}> */
    public static function tipusok(): array
    {
        return [
            'gep'    => ['nev' => 'Saját gép', 'leiras' => 'Egy mappa azon a gépen, ahol a rendszer fut – például külső lemez vagy másik partíció.'],
            'nas'    => ['nev' => 'NAS (hálózati tároló)', 'leiras' => 'Csatolt vagy hálózati mappa (pl. \\\\NAS\\mentes), vagy a NAS WebDAV-szolgáltatása.'],
            'ftp'    => ['nev' => 'FTP / FTPS', 'leiras' => 'Másik szerver FTP-n; a titkosított FTPS (explicit TLS) javasolt.'],
            'gdrive' => ['nev' => 'Google Drive', 'leiras' => 'A saját Google-fiókod egy külön mappájába. Egyszeri összekapcsolás kell hozzá.'],
        ];
    }

    /**
     * A cél mezői. tipus: szoveg | jelszo | szam | valaszto | jelolo. A „titkos” mezők az
     * adatbázisban titkosítva állnak, és a felületen sosem jelennek meg.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function mezok(string $id): array
    {
        switch ($id) {
            case 'gep':
                return [
                    'mappa' => ['cim' => 'Mappa', 'tipus' => 'szoveg', 'alap' => '', 'sugo' => 'Teljes út, pl. D:\\Mentes (Windows) vagy /mnt/mentes. Ne a rendszer saját mentési mappája legyen.'],
                ];

            case 'nas':
                return [
                    'mod'        => ['cim' => 'Elérés módja', 'tipus' => 'valaszto', 'alap' => 'mappa', 'opciok' => ['mappa' => 'Hálózati / csatolt mappa', 'webdav' => 'WebDAV']],
                    'mappa'      => ['cim' => 'Mappa', 'tipus' => 'szoveg', 'alap' => '', 'sugo' => 'Pl. \\\\NAS\\mentes\\sdh vagy Z:\\sdh. A szerver felhasználójának írnia kell tudni.', 'ha' => ['mod' => 'mappa']],
                    'webdav_url' => ['cim' => 'WebDAV-cím', 'tipus' => 'szoveg', 'alap' => '', 'sugo' => 'Pl. https://nas.local:5006/mentes/sdh/', 'ha' => ['mod' => 'webdav']],
                    'felhasznalo' => ['cim' => 'Felhasználó', 'tipus' => 'szoveg', 'alap' => '', 'ha' => ['mod' => 'webdav']],
                    'jelszo'     => ['cim' => 'Jelszó', 'tipus' => 'jelszo', 'titkos' => true, 'ha' => ['mod' => 'webdav']],
                    'ssl_ell'    => ['cim' => 'Tanúsítvány ellenőrzése', 'tipus' => 'jelolo', 'alap' => '1', 'sugo' => 'Önaláírt tanúsítványú NAS-nál kikapcsolható – ilyenkor csak a saját hálózatodon használd.', 'ha' => ['mod' => 'webdav']],
                ];

            case 'ftp':
                return [
                    'host'        => ['cim' => 'Kiszolgáló', 'tipus' => 'szoveg', 'alap' => ''],
                    'port'        => ['cim' => 'Port', 'tipus' => 'szam', 'alap' => '21'],
                    'titkositas'  => ['cim' => 'Kapcsolat', 'tipus' => 'valaszto', 'alap' => 'ftps', 'opciok' => ['ftps' => 'FTPS (explicit TLS)', 'ftp' => 'Sima FTP (titkosítatlan kapcsolat)']],
                    'felhasznalo' => ['cim' => 'Felhasználó', 'tipus' => 'szoveg', 'alap' => ''],
                    'jelszo'      => ['cim' => 'Jelszó', 'tipus' => 'jelszo', 'titkos' => true],
                    'mappa'       => ['cim' => 'Távoli mappa', 'tipus' => 'szoveg', 'alap' => 'sdh-mentes', 'sugo' => 'Ha nincs meg, a rendszer létrehozza. A mentésfájl maga mindenképp titkosított.'],
                ];

            case 'gdrive':
                return [
                    'kliens_id'    => ['cim' => 'Kliensazonosító', 'tipus' => 'szoveg', 'alap' => '', 'sugo' => 'Google Cloud › Hitelesítő adatok › OAuth-kliens, típusa: „TV és korlátozott bemenetű eszközök”.'],
                    'kliens_titok' => ['cim' => 'Kliens titka', 'tipus' => 'jelszo', 'titkos' => true],
                ];
        }

        return [];
    }

    /* ----- Beállítások ------------------------------------------------ */

    /** @return array<string, mixed> a nyers (tárolt) beállítás */
    private static function nyers(): array
    {
        $b = get_option(self::OPT, []);

        return is_array($b) ? $b : [];
    }

    /**
     * A cél beállításai alapértékekkel; a titkos mezők visszafejtve.
     *
     * @return array<string, mixed>
     */
    public static function adatok(string $id): array
    {
        $tarolt = self::nyers()[$id] ?? [];

        return self::dekodol($id, is_array($tarolt) ? $tarolt : []);
    }

    /**
     * A (nem mentett) űrlapadatokból – a titkos mezők üresen a most tároltat adják.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    public static function adatok_postbol(string $id, array $p): array
    {
        return self::dekodol($id, self::mentendo($id, $p));
    }

    /**
     * @param array<string, mixed> $tarolt
     * @return array<string, mixed>
     */
    private static function dekodol(string $id, array $tarolt): array
    {
        $ki = ['aktiv' => !empty($tarolt['aktiv'])];

        foreach (self::mezok($id) as $kulcs => $m) {
            $v = isset($tarolt[$kulcs]) && is_scalar($tarolt[$kulcs]) ? (string) $tarolt[$kulcs] : (string) ($m['alap'] ?? '');
            $ki[$kulcs] = !empty($m['titkos']) ? SDH_Muhely_Titok::visszafejt($v) : $v;
        }

        // Nem űrlapmezők (a Google-kapcsolat állapota).
        foreach (['refresh_token', 'mappa_id', 'fiok'] as $extra) {
            if (isset($tarolt[$extra]) && is_string($tarolt[$extra])) {
                $ki[$extra] = $extra === 'refresh_token' ? SDH_Muhely_Titok::visszafejt($tarolt[$extra]) : $tarolt[$extra];
            }
        }

        return $ki;
    }

    public static function aktiv(string $id): bool
    {
        return !empty(self::nyers()[$id]['aktiv']);
    }

    /** Egy nem űrlapmező (pl. a Google-kapcsolat) tárolása. A titkosat titkosítva írja. */
    public static function extra_ment(string $id, string $kulcs, string $ertek): void
    {
        $b = self::nyers();
        $b[$id] = is_array($b[$id] ?? null) ? $b[$id] : [];
        $b[$id][$kulcs] = $kulcs === 'refresh_token' ? SDH_Muhely_Titok::titkosit($ertek) : $ertek;
        update_option(self::OPT, $b, false);
    }

    public static function extra_torol(string $id, string $kulcs): void
    {
        $b = self::nyers();

        if (isset($b[$id][$kulcs])) {
            unset($b[$id][$kulcs]);
            update_option(self::OPT, $b, false);
        }
    }

    /**
     * Az űrlapból (vagy a próba-kérésből) érkező adatok megtisztítva. A titkos mezők üresen
     * hagyva a MOST TÁROLT értéket tartják meg; a nem űrlapmezők (Google-kapcsolat) megmaradnak.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed> tárolható alak (titkosítva)
     */
    public static function mentendo(string $id, array $p): array
    {
        $tarolt = self::nyers()[$id] ?? [];
        $tarolt = is_array($tarolt) ? $tarolt : [];
        $ki     = [];

        foreach (['refresh_token', 'mappa_id', 'fiok'] as $extra) {
            if (isset($tarolt[$extra])) {
                $ki[$extra] = $tarolt[$extra];
            }
        }

        $ki['aktiv'] = !empty($p['aktiv']);

        foreach (self::mezok($id) as $kulcs => $m) {
            $nyers = isset($p[$kulcs]) && is_scalar($p[$kulcs]) ? (string) wp_unslash($p[$kulcs]) : '';

            switch ($m['tipus']) {
                case 'jelolo':
                    $ertek = !empty($p[$kulcs]) ? '1' : '0';
                    break;
                case 'szam':
                    $ertek = (string) max(1, min(65535, (int) $nyers ?: (int) ($m['alap'] ?? 0)));
                    break;
                case 'valaszto':
                    $ertek = isset($m['opciok'][$nyers]) ? $nyers : (string) $m['alap'];
                    break;
                case 'jelszo':
                    $ertek = $nyers !== '' ? SDH_Muhely_Titok::titkosit($nyers) : (string) ($tarolt[$kulcs] ?? '');
                    break;
                default:
                    $ertek = trim(wp_strip_all_tags($nyers));
            }

            $ki[$kulcs] = $ertek;
        }

        return $ki;
    }

    /**
     * Egy cél mezőinek mentése a többi érintése nélkül (az „aktív” jelzés megmarad).
     *
     * @param array<string, mixed> $p
     */
    public static function ment_egy(string $id, array $p): void
    {
        $uj = self::nyers();
        $uj[$id] = self::mentendo($id, $p + ['aktiv' => self::aktiv($id)]);
        update_option(self::OPT, $uj, false);
    }

    /** @param array<string, mixed> $post a Mentés-lap űrlapja (mentes_cel[id][mező]) */
    public static function ment_postbol(array $post): void
    {
        $be  = isset($post['mentes_cel']) && is_array($post['mentes_cel']) ? $post['mentes_cel'] : [];
        $uj  = self::nyers();

        foreach (array_keys(self::tipusok()) as $id) {
            $uj[$id] = self::mentendo($id, isset($be[$id]) && is_array($be[$id]) ? $be[$id] : []);
        }

        update_option(self::OPT, $uj, false);
    }

    /* ----- Titkosító jelszó ------------------------------------------- */

    public static function jelszo(): string
    {
        $b = get_option(self::OPT_JELSZO, []);

        return is_array($b) && is_string($b['jelszo'] ?? null) ? SDH_Muhely_Titok::visszafejt((string) $b['jelszo']) : '';
    }

    public static function jelszo_ment(string $jelszo): void
    {
        update_option(self::OPT_JELSZO, ['jelszo' => SDH_Muhely_Titok::titkosit($jelszo)], false);
    }

    public static function jelszo_van(): bool
    {
        return self::jelszo() !== '';
    }

    /* ----- Példányok -------------------------------------------------- */

    /**
     * @param array<string, mixed>|null $adatok nem mentett (próba) adatok; null = a tárolt beállítás
     */
    public static function peldany(string $id, ?array $adatok = null): ?SDH_Muhely_Mentes_Cel_Alap
    {
        $adatok = $adatok ?? self::adatok($id);

        switch ($id) {
            case 'gep':
                return new SDH_Muhely_Mentes_Cel_Mappa($adatok);
            case 'nas':
                return ($adatok['mod'] ?? 'mappa') === 'webdav' ? new SDH_Muhely_Mentes_Cel_Webdav($adatok) : new SDH_Muhely_Mentes_Cel_Mappa($adatok);
            case 'ftp':
                return class_exists('SDH_Muhely_Mentes_Ftp') ? new SDH_Muhely_Mentes_Ftp($adatok) : null;
            case 'gdrive':
                return class_exists('SDH_Muhely_Mentes_Gdrive') ? new SDH_Muhely_Mentes_Gdrive($adatok) : null;
        }

        return null;
    }

    /** A bekapcsolt célok azonosítói. @return string[] */
    public static function aktiv_idk(): array
    {
        return array_values(array_filter(array_keys(self::tipusok()), static fn (string $id): bool => self::aktiv($id)));
    }

    /** @return array<string, mixed> */
    public static function allapot(): array
    {
        $a = get_option(self::OPT_ALLAPOT, []);

        return is_array($a) ? $a : [];
    }

    /** @param array<string, mixed> $valtozas */
    public static function allapot_ment(string $id, array $valtozas): void
    {
        $a = self::allapot();
        $a[$id] = array_merge(is_array($a[$id] ?? null) ? $a[$id] : [], $valtozas);
        update_option(self::OPT_ALLAPOT, $a, false);
    }
}
