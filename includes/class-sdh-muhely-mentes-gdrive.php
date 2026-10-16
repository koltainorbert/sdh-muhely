<?php
/**
 * Google Drive mentési cél (0.73.0, H3).
 *
 * Hogyan kapcsolódik: az „eszközkódos” (device) OAuth-folyamattal – a felületen
 * megjelenik egy rövid kód, azt a google.com/device oldalon kell megadni. Nem
 * kell átirányítási cím, ezért ugyanúgy működik a helyi gépen (LocalWP), a
 * műhely szerverén és internet felőli eléréssel is.
 *
 * Jogosultság: csak a „drive.file” hatókör – a rendszer kizárólag a SAJÁT maga
 * által létrehozott mappát és fájlokat látja, a Drive többi tartalmát nem.
 *
 * Amit a gazdának egyszer el kell végeznie (a súgó lépésenként leírja):
 * a Google Cloud-ban egy „TV és korlátozott bemenetű eszközök” típusú OAuth-kliens,
 * és az OAuth-hozzájárulási képernyő állapota legyen „Éles” (Production) – a
 * „Tesztelés” állapotban a Google 7 naponta érvényteleníti a kapcsolatot.
 *
 * A feltöltés folytatható (resumable), 8 MiB-os darabokban: nagy mentésnél sem
 * kell a fájlt a memóriába olvasni.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Mentes_Gdrive extends SDH_Muhely_Mentes_Cel_Alap
{
    public const HATOKOR = 'https://www.googleapis.com/auth/drive.file';
    public const MAPPA   = 'SDH Műhely mentés';

    private const TOKEN   = 'https://oauth2.googleapis.com/token';
    private const ESZKOZ  = 'https://oauth2.googleapis.com/device/code';
    private const VISSZAV = 'https://oauth2.googleapis.com/revoke';
    private const API     = 'https://www.googleapis.com/drive/v3/';
    private const FELTOLT = 'https://www.googleapis.com/upload/drive/v3/files';
    private const DARAB   = 8388608; // 8 MiB (a 256 KiB többszöröse – a Google ezt kéri)

    private const AT_TRANSIENT = 'sdh_muhely_gdrive_at';

    /* ----- Összekapcsolás (eszközkódos folyamat) ----------------------- */

    /**
     * @return array{eszkoz_kod: string, felhasznalo_kod: string, cim: string, lejar: int, varakozas: int}|string
     */
    public static function kod_kerese(string $kliens_id)
    {
        if ($kliens_id === '') {
            return 'Add meg a kliensazonosítót, és mentsd a beállításokat.';
        }

        $r = self::kerek('POST', self::ESZKOZ, ['body' => ['client_id' => $kliens_id, 'scope' => self::HATOKOR]]);

        if (is_string($r)) {
            return $r;
        }

        if (empty($r['device_code']) || empty($r['user_code'])) {
            return 'A Google nem adott eszközkódot' . (isset($r['error_description']) ? ': ' . $r['error_description'] : '.');
        }

        return [
            'eszkoz_kod'      => (string) $r['device_code'],
            'felhasznalo_kod' => (string) $r['user_code'],
            'cim'             => (string) ($r['verification_url'] ?? 'https://www.google.com/device'),
            'lejar'           => (int) ($r['expires_in'] ?? 1800),
            'varakozas'       => max(3, (int) ($r['interval'] ?? 5)),
        ];
    }

    /**
     * Egyetlen lekérdezés: megadta-e már a felhasználó a kódot. Sikernél eltárolja a kapcsolatot.
     *
     * @return array{allapot: string, uzenet: string} allapot: var | lassabban | kesz | hiba
     */
    public static function kod_ellenorzese(string $kliens_id, string $kliens_titok, string $eszkoz_kod): array
    {
        $r = self::kerek('POST', self::TOKEN, ['body' => [
            'client_id'     => $kliens_id,
            'client_secret' => $kliens_titok,
            'device_code'   => $eszkoz_kod,
            'grant_type'    => 'urn:ietf:params:oauth:grant-type:device_code',
        ]], true);

        if (is_string($r)) {
            return ['allapot' => 'hiba', 'uzenet' => $r];
        }

        $hiba = (string) ($r['error'] ?? '');

        if ($hiba === 'authorization_pending') {
            return ['allapot' => 'var', 'uzenet' => 'Várakozás a Google-jóváhagyásra…'];
        }

        if ($hiba === 'slow_down') {
            return ['allapot' => 'lassabban', 'uzenet' => 'Várakozás a Google-jóváhagyásra…'];
        }

        if ($hiba === 'access_denied') {
            return ['allapot' => 'hiba', 'uzenet' => 'A hozzáférést megtagadtad a Google-oldalon.'];
        }

        if ($hiba === 'expired_token') {
            return ['allapot' => 'hiba', 'uzenet' => 'A kód lejárt – kérj újat.'];
        }

        if ($hiba !== '' || empty($r['refresh_token'])) {
            return ['allapot' => 'hiba', 'uzenet' => 'A Google hibát jelzett: ' . ($r['error_description'] ?? ($hiba !== '' ? $hiba : 'nem érkezett tartós kulcs')) . '.'];
        }

        SDH_Muhely_Mentes_Cel::extra_ment('gdrive', 'refresh_token', (string) $r['refresh_token']);
        delete_transient(self::AT_TRANSIENT);

        if (!empty($r['access_token'])) {
            set_transient(self::AT_TRANSIENT, (string) $r['access_token'], max(60, (int) ($r['expires_in'] ?? 3600) - 120));
        }

        $d = new self(SDH_Muhely_Mentes_Cel::adatok('gdrive'));
        $f = $d->fiok_neve();

        if ($f !== '') {
            SDH_Muhely_Mentes_Cel::extra_ment('gdrive', 'fiok', $f);
        }

        return ['allapot' => 'kesz', 'uzenet' => 'Összekapcsolva' . ($f !== '' ? ': ' . $f : '') . '.'];
    }

    /** A kapcsolat bontása: a Google-oldali hozzáférés visszavonása és a tárolt kulcs törlése. */
    public static function lekapcsol(): void
    {
        $be = SDH_Muhely_Mentes_Cel::adatok('gdrive');

        if (!empty($be['refresh_token'])) {
            self::kerek('POST', self::VISSZAV, ['body' => ['token' => $be['refresh_token']]], true);
        }

        SDH_Muhely_Mentes_Cel::extra_torol('gdrive', 'refresh_token');
        SDH_Muhely_Mentes_Cel::extra_torol('gdrive', 'fiok');
        delete_transient(self::AT_TRANSIENT);
    }

    public function osszekapcsolva(): bool
    {
        return $this->s('refresh_token') !== '';
    }

    /* ----- HTTP ---------------------------------------------------------- */

    /**
     * Egy kérés; a JSON-választ tömbként adja vissza, a hibát szövegként.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|string
     */
    private static function kerek(string $metodus, string $url, array $args = [], bool $hibatest_is = false)
    {
        $r = wp_remote_request($url, array_merge(['method' => $metodus, 'timeout' => 60], $args));

        if (is_wp_error($r)) {
            return 'A Google nem érhető el: ' . $r->get_error_message();
        }

        $kod   = (int) wp_remote_retrieve_response_code($r);
        $torzs = (string) wp_remote_retrieve_body($r);
        $json  = json_decode($torzs, true);
        $json  = is_array($json) ? $json : [];

        if ($kod >= 400 && !$hibatest_is) {
            $uzenet = is_array($json['error'] ?? null) ? (string) ($json['error']['message'] ?? '') : (string) ($json['error_description'] ?? $json['error'] ?? '');

            return 'Google-hiba (' . $kod . ')' . ($uzenet !== '' ? ': ' . $uzenet : '.');
        }

        $json['_kod'] = $kod;

        return $json;
    }

    /** Az utolsó token- vagy mappahiba szövege. */
    private ?string $utolso_hiba = null;

    /** @return string|null a hozzáférési kulcs; hiba esetén null (a szöveg az $utolso_hiba-ban) */
    private function token(): ?string
    {
        $t = get_transient(self::AT_TRANSIENT);

        if (is_string($t) && $t !== '') {
            return $t;
        }

        if (!$this->osszekapcsolva()) {
            $this->utolso_hiba = 'A Google Drive nincs összekapcsolva.';

            return null;
        }

        $r = self::kerek('POST', self::TOKEN, ['body' => [
            'client_id'     => $this->s('kliens_id'),
            'client_secret' => $this->s('kliens_titok'),
            'refresh_token' => $this->s('refresh_token'),
            'grant_type'    => 'refresh_token',
        ]], true);

        if (is_string($r)) {
            $this->utolso_hiba = $r;

            return null;
        }

        if (empty($r['access_token'])) {
            $this->utolso_hiba = ($r['error'] ?? '') === 'invalid_grant'
                ? 'A Google-kapcsolat lejárt vagy visszavonták – kapcsold össze újra. (Ha az OAuth-képernyő „Tesztelés” állapotú, a Google 7 naponta érvényteleníti: állítsd „Éles” állapotra.)'
                : 'A Google nem adott hozzáférési kulcsot: ' . ($r['error_description'] ?? $r['error'] ?? 'ismeretlen hiba') . '.';

            return null;
        }

        set_transient(self::AT_TRANSIENT, (string) $r['access_token'], max(60, (int) ($r['expires_in'] ?? 3600) - 120));

        return (string) $r['access_token'];
    }

    /**
     * Hitelesített Drive-kérés.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|string
     */
    private function api(string $metodus, string $url, array $args = [])
    {
        $t = $this->token();

        if ($t === null) {
            return $this->utolso_hiba ?? 'A Google Drive nem érhető el.';
        }

        $args['headers'] = array_merge(['Authorization' => 'Bearer ' . $t], $args['headers'] ?? []);

        return self::kerek($metodus, $url, $args);
    }

    /** A Google-fiók neve (a kapcsolat sikerének megjelenítéséhez). */
    public function fiok_neve(): string
    {
        $r = $this->api('GET', self::API . 'about?fields=user(displayName,emailAddress)');

        if (is_string($r) || !is_array($r['user'] ?? null)) {
            return '';
        }

        return trim((string) ($r['user']['emailAddress'] ?? $r['user']['displayName'] ?? ''));
    }

    /* ----- Mappa ---------------------------------------------------------- */

    /** @return string|null a mappa azonosítója; hiba esetén null (szöveg: $utolso_hiba) */
    private function mappa_id(): ?string
    {
        $id = $this->s('mappa_id');

        if ($id !== '') {
            $r = $this->api('GET', self::API . 'files/' . rawurlencode($id) . '?fields=id,trashed');

            if (is_array($r) && empty($r['trashed']) && !empty($r['id'])) {
                return $id;
            }
        }

        $q = "name='" . self::MAPPA . "' and mimeType='application/vnd.google-apps.folder' and trashed=false";
        $r = $this->api('GET', self::API . 'files?fields=files(id)&pageSize=1&q=' . rawurlencode($q));

        if (is_string($r)) {
            $this->utolso_hiba = $r;

            return null;
        }

        $uj = '';

        if (!empty($r['files'][0]['id'])) {
            $uj = (string) $r['files'][0]['id'];
        } else {
            $r = $this->api('POST', self::API . 'files?fields=id', [
                'headers' => ['Content-Type' => 'application/json; charset=UTF-8'],
                'body'    => wp_json_encode(['name' => self::MAPPA, 'mimeType' => 'application/vnd.google-apps.folder']),
            ]);

            if (is_string($r)) {
                $this->utolso_hiba = $r;

                return null;
            }

            $uj = (string) ($r['id'] ?? '');
        }

        if ($uj === '') {
            $this->utolso_hiba = 'A Drive-mappa nem hozható létre.';

            return null;
        }

        SDH_Muhely_Mentes_Cel::extra_ment('gdrive', 'mappa_id', $uj);
        $this->be['mappa_id'] = $uj;

        return $uj;
    }

    /* ----- A célfelület ---------------------------------------------------- */

    public function tesztel(): ?string
    {
        if ($this->s('kliens_id') === '' || $this->s('kliens_titok') === '') {
            return 'Add meg a kliensazonosítót és a kliens titkát, majd kapcsold össze a Drive-ot.';
        }

        if (!$this->osszekapcsolva()) {
            return 'A Google Drive még nincs összekapcsolva.';
        }

        if ($this->mappa_id() === null) {
            return $this->utolso_hiba;
        }

        // Teljes út próbája: egy pár bájtos fájl feltöltése és törlése.
        $tmp = tempnam(get_temp_dir(), 'sdhg');

        if ($tmp === false || file_put_contents($tmp, 'ok') === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions
            return 'Az ideiglenes próbafájl nem írható.';
        }

        $nev = 'sdh-mentes-proba-' . wp_generate_password(6, false) . SDH_Muhely_Mentes_Titkos::KITERJESZTES;
        $h   = $this->feltolt($tmp, $nev);
        @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if ($h !== null) {
            return $h;
        }

        return $this->torol($nev);
    }

    public function feltolt(string $helyi, string $nev): ?string
    {
        $mappa = $this->mappa_id();

        if ($mappa === null) {
            return $this->utolso_hiba;
        }

        $meret = (int) filesize($helyi);
        $t     = $this->token();

        if ($t === null) {
            return $this->utolso_hiba;
        }

        // 1) Feltöltési munkamenet nyitása.
        $r = wp_remote_post(self::FELTOLT . '?uploadType=resumable&fields=id,size', [
            'timeout' => 60,
            'headers' => [
                'Authorization'           => 'Bearer ' . $t,
                'Content-Type'            => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type'   => 'application/octet-stream',
                'X-Upload-Content-Length' => (string) $meret,
            ],
            'body'    => wp_json_encode(['name' => $nev, 'parents' => [$mappa]]),
        ]);

        if (is_wp_error($r)) {
            return 'A Google nem érhető el: ' . $r->get_error_message();
        }

        if ((int) wp_remote_retrieve_response_code($r) >= 400) {
            $j = json_decode((string) wp_remote_retrieve_body($r), true);

            return 'A Drive nem fogadta a feltöltést (' . wp_remote_retrieve_response_code($r) . ')'
                . (is_array($j) && !empty($j['error']['message']) ? ': ' . $j['error']['message'] : '.');
        }

        $hely = (string) wp_remote_retrieve_header($r, 'location');

        if ($hely === '') {
            return 'A Drive nem adott feltöltési címet.';
        }

        // 2) Darabonként.
        $fh = fopen($helyi, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions

        if ($fh === false) {
            return 'A helyi fájl nem olvasható.';
        }

        $offset = 0;
        $kesz   = null;

        try {
            while ($offset < $meret || $meret === 0) {
                fseek($fh, $offset); // phpcs:ignore WordPress.WP.AlternativeFunctions
                $darab = (string) fread($fh, self::DARAB); // phpcs:ignore WordPress.WP.AlternativeFunctions
                $hossz = strlen($darab);
                $veg   = $offset + $hossz;
                $fej   = ['Content-Length' => (string) $hossz, 'Content-Range' => $hossz === 0 ? 'bytes */' . $meret : 'bytes ' . $offset . '-' . ($veg - 1) . '/' . $meret];
                $v     = null;

                for ($proba = 0; $proba < 3; $proba++) {
                    $v = wp_remote_request($hely, ['method' => 'PUT', 'timeout' => 180, 'headers' => $fej, 'body' => $darab]);

                    if (!is_wp_error($v) && (int) wp_remote_retrieve_response_code($v) < 500) {
                        break;
                    }

                    sleep(2 * ($proba + 1));
                }

                if (is_wp_error($v)) {
                    return 'A Drive-feltöltés megszakadt: ' . $v->get_error_message();
                }

                $kod = (int) wp_remote_retrieve_response_code($v);

                if ($kod === 200 || $kod === 201) {
                    $j    = json_decode((string) wp_remote_retrieve_body($v), true);
                    $kesz = is_array($j) ? $j : [];
                    break;
                }

                if ($kod !== 308) {
                    return 'A Drive-feltöltés hibával állt le (HTTP ' . $kod . ').';
                }

                $tartomany = (string) wp_remote_retrieve_header($v, 'range');
                $offset    = preg_match('/bytes=0-(\d+)/', $tartomany, $m) === 1 ? (int) $m[1] + 1 : $veg;
            }
        } finally {
            fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        if ($kesz === null) {
            return 'A Drive-feltöltés nem fejeződött be.';
        }

        if (isset($kesz['size']) && (int) $kesz['size'] !== $meret) {
            if (!empty($kesz['id'])) {
                $this->api('DELETE', self::API . 'files/' . rawurlencode((string) $kesz['id']));
            }

            return 'A Drive-ra került másolat mérete eltér – a feltöltés megismétlődik.';
        }

        return null;
    }

    /** @return array<int, array{id: string, fajl: string, meret: int, ido: int}>|string */
    private function fajlok()
    {
        $mappa = $this->mappa_id();

        if ($mappa === null) {
            return $this->utolso_hiba ?? 'A Drive-mappa nem érhető el.';
        }

        $ki    = [];
        $lap   = '';
        $korok = 0;

        do {
            $q = "'" . $mappa . "' in parents and trashed=false";
            $r = $this->api('GET', self::API . 'files?pageSize=1000&fields=nextPageToken,files(id,name,size,createdTime)&q=' . rawurlencode($q) . ($lap !== '' ? '&pageToken=' . rawurlencode($lap) : ''));

            if (is_string($r)) {
                return $r;
            }

            foreach ((array) ($r['files'] ?? []) as $f) {
                $nev = (string) ($f['name'] ?? '');

                if (self::mentes_nev($nev)) {
                    $ki[] = ['id' => (string) $f['id'], 'fajl' => $nev, 'meret' => (int) ($f['size'] ?? 0), 'ido' => (int) strtotime((string) ($f['createdTime'] ?? ''))];
                }
            }

            $lap = (string) ($r['nextPageToken'] ?? '');
        } while ($lap !== '' && ++$korok < 50);

        return $ki;
    }

    public function listaz()
    {
        $l = $this->fajlok();

        if (is_string($l)) {
            return $l;
        }

        return array_map(static fn (array $f): array => ['fajl' => $f['fajl'], 'meret' => $f['meret'], 'ido' => $f['ido']], $l);
    }

    public function letolt(string $nev, string $cel): ?string
    {
        $l = $this->fajlok();

        if (is_string($l)) {
            return $l;
        }

        foreach ($l as $f) {
            if ($f['fajl'] !== $nev) {
                continue;
            }

            $t = $this->token();

            if ($t === null) {
                return $this->utolso_hiba;
            }

            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            $r = wp_remote_get(self::API . 'files/' . rawurlencode($f['id']) . '?alt=media', [
                'timeout'  => 3600,
                'stream'   => true,
                'filename' => $cel,
                'headers'  => ['Authorization' => 'Bearer ' . $t],
            ]);

            if (is_wp_error($r)) {
                return 'A Drive-letöltés nem sikerült: ' . $r->get_error_message();
            }

            if ((int) wp_remote_retrieve_response_code($r) !== 200) {
                @unlink($cel); // phpcs:ignore WordPress.PHP.NoSilencedErrors

                return 'A Drive-letöltés hibával állt le (HTTP ' . wp_remote_retrieve_response_code($r) . ').';
            }

            return null;
        }

        return 'Nincs ilyen mentés a Drive-mappában.';
    }

    public function torol(string $nev): ?string
    {
        if (!self::mentes_nev($nev)) {
            return 'Érvénytelen fájlnév.';
        }

        $l = $this->fajlok();

        if (is_string($l)) {
            return $l;
        }

        foreach ($l as $f) {
            if ($f['fajl'] === $nev) {
                $r = $this->api('DELETE', self::API . 'files/' . rawurlencode($f['id']));

                if (is_string($r)) {
                    return $r;
                }
            }
        }

        return null;
    }
}
