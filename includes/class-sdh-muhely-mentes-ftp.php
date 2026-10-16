<?php
/**
 * FTP / FTPS mentési cél (0.73.0, H3).
 *
 * Passzív módban, explicit TLS-sel (FTPS) vagy sima FTP-vel. A feltöltés
 * ideiglenes néven történik, és csak a teljes méretű, ellenőrzött fájl kapja
 * meg a végleges nevét – a félbeszakadt feltöltés nem számít mentésnek.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Mentes_Ftp extends SDH_Muhely_Mentes_Cel_Alap
{
    /** @var resource|\FTP\Connection|null */
    private $kapcsolat = null;

    public function __destruct()
    {
        $this->bont();
    }

    private function bont(): void
    {
        if ($this->kapcsolat !== null) {
            @ftp_close($this->kapcsolat); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $this->kapcsolat = null;
        }
    }

    /** Kapcsolódás, belépés, a mappába lépés (szükség esetén létrehozva). */
    private function nyit(): ?string
    {
        if ($this->kapcsolat !== null) {
            return null;
        }

        if (!function_exists('ftp_connect')) {
            return 'A szerveren nincs FTP-bővítmény (php-ftp).';
        }

        $host = $this->s('host');
        $port = (int) $this->s('port', '21');

        if ($host === '') {
            return 'Nincs megadva az FTP-kiszolgáló.';
        }

        $ftps = $this->s('titkositas', 'ftps') === 'ftps';

        if ($ftps && !function_exists('ftp_ssl_connect')) {
            return 'A szerveren az FTPS nem érhető el (hiányzó OpenSSL az FTP-bővítményben).';
        }

        $k = $ftps ? @ftp_ssl_connect($host, $port, 20) : @ftp_connect($host, $port, 20); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if ($k === false) {
            return 'Az FTP-kiszolgáló nem érhető el: ' . $host . ':' . $port;
        }

        if (!@ftp_login($k, $this->s('felhasznalo'), $this->s('jelszo'))) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            @ftp_close($k); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return 'Az FTP-kiszolgáló elutasította a belépést – ellenőrizd a felhasználót és a jelszót.';
        }

        @ftp_pasv($k, true); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $this->kapcsolat = $k;

        $mappa = trim(str_replace('\\', '/', $this->s('mappa', 'sdh-mentes')), '/');

        if ($mappa !== '') {
            $abs = strncmp($this->s('mappa'), '/', 1) === 0;

            if ($abs) {
                @ftp_chdir($k, '/'); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }

            foreach (explode('/', $mappa) as $resz) {
                if ($resz === '' || $resz === '.' || $resz === '..') {
                    continue;
                }

                if (!@ftp_chdir($k, $resz)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                    if (@ftp_mkdir($k, $resz) === false || !@ftp_chdir($k, $resz)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                        $this->bont();

                        return 'A távoli mappa nem érhető el, és nem hozható létre: ' . $mappa;
                    }
                }
            }
        }

        return null;
    }

    public function tesztel(): ?string
    {
        if (($h = $this->nyit()) !== null) {
            return $h;
        }

        $proba = tempnam(get_temp_dir(), 'sdhf');

        if ($proba === false || file_put_contents($proba, 'ok') === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions
            return 'Az ideiglenes próbafájl nem írható.';
        }

        $nev = '.sdh-proba-' . wp_generate_password(6, false);
        $ok  = @ftp_put($this->kapcsolat, $nev, $proba, FTP_BINARY); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        @unlink($proba); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if (!$ok) {
            return 'A kiszolgálóra nem sikerült fájlt írni – ellenőrizd a jogosultságot és a szabad helyet.';
        }

        @ftp_delete($this->kapcsolat, $nev); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        return null;
    }

    public function feltolt(string $helyi, string $nev): ?string
    {
        if (($h = $this->nyit()) !== null) {
            return $h;
        }

        @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        $tmp = $nev . '.tmp';

        if (!@ftp_put($this->kapcsolat, $tmp, $helyi, FTP_BINARY)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            @ftp_delete($this->kapcsolat, $tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return 'Az FTP-feltöltés megszakadt (kapcsolat vagy szabad hely).';
        }

        $meret = @ftp_size($this->kapcsolat, $tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        // A -1: a kiszolgáló nem tudja megmondani a méretet – ilyenkor az átnevezés sikere dönt.
        if ($meret !== -1 && $meret !== (int) filesize($helyi)) {
            @ftp_delete($this->kapcsolat, $tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return 'A feltöltött másolat mérete eltér – a feltöltés megismétlődik.';
        }

        @ftp_delete($this->kapcsolat, $nev); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if (!@ftp_rename($this->kapcsolat, $tmp, $nev)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            @ftp_delete($this->kapcsolat, $tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return 'A feltöltött fájl nem nevezhető át a kiszolgálón.';
        }

        return null;
    }

    public function listaz()
    {
        if (($h = $this->nyit()) !== null) {
            return $h;
        }

        $nevek = @ftp_nlist($this->kapcsolat, '.'); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if ($nevek === false) {
            // Üres mappánál egyes kiszolgálók hibát adnak: ez nem hiba, ha a belépés megvolt.
            return [];
        }

        $ki = [];

        foreach ($nevek as $n) {
            $nev = basename(str_replace('\\', '/', (string) $n));

            if (!self::mentes_nev($nev)) {
                continue;
            }

            $meret = @ftp_size($this->kapcsolat, $nev); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $ido   = @ftp_mdtm($this->kapcsolat, $nev); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $ki[]  = ['fajl' => $nev, 'meret' => max(0, (int) $meret), 'ido' => max(0, (int) $ido)];
        }

        return $ki;
    }

    public function letolt(string $nev, string $cel): ?string
    {
        if (!self::mentes_nev($nev)) {
            return 'Érvénytelen fájlnév.';
        }

        if (($h = $this->nyit()) !== null) {
            return $h;
        }

        @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        return @ftp_get($this->kapcsolat, $cel, $nev, FTP_BINARY) ? null : 'Az FTP-letöltés nem sikerült.'; // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }

    public function torol(string $nev): ?string
    {
        if (!self::mentes_nev($nev)) {
            return 'Érvénytelen fájlnév.';
        }

        if (($h = $this->nyit()) !== null) {
            return $h;
        }

        return @ftp_delete($this->kapcsolat, $nev) ? null : 'A régi mentés nem törölhető az FTP-n: ' . $nev; // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
}
