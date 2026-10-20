<?php
/**
 * Jelszavas titkosítás a mentésfájlokhoz (0.73.0, H3).
 *
 * MIÉRT: a külső helyekre (Google Drive, FTP, NAS, másik gép) kerülő mentés
 * ügyféladatot és beállítást tartalmaz. Ezek a helyek nem a mi szerverünk, ezért
 * a fájl titkosítva hagyja el a gépet. A kulcs egy JELSZÓBÓL készül (Argon2id),
 * nem a WordPress titkos sóiból – így a mentés egy másik gépen, tiszta
 * telepítésen is megnyitható, ha megvan a jelszó. Jelszó nélkül nem nyitható.
 *
 * Formátum (.sdhmentes), a tartalom nagyságától függetlenül darabonként, tehát
 * a memóriát nem terheli:
 *   "SDHMENT1" · ops (u32) · mem (u32) · só (16 bájt) · folyam-fejléc (24 bájt)
 *   majd darabok: hossz (u32) · titkosított darab (1 MiB nyílt szövegig).
 * Az utolsó darab FINAL jelzést kap: a megcsonkított fájl hibaként derül ki.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Mentes_Titkos
{
    public const KITERJESZTES = '.sdhmentes';
    public const MIN_JELSZO   = 8;

    private const MAGIC = 'SDHMENT1';
    private const DARAB = 1048576;

    public static function elerheto(): bool
    {
        return function_exists('sodium_crypto_pwhash')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')
            && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13');
    }

    /** Titkosított mentésfájl-e (a fejléc alapján, nem a kiterjesztés szerint). */
    public static function titkos_fajl_e(string $ut): bool
    {
        $f = @fopen($ut, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

        if ($f === false) {
            return false;
        }

        $fej = (string) fread($f, strlen(self::MAGIC)); // phpcs:ignore WordPress.WP.AlternativeFunctions
        fclose($f); // phpcs:ignore WordPress.WP.AlternativeFunctions

        return $fej === self::MAGIC;
    }

    /** Hibaüzenet a jelszóra, vagy null, ha használható. */
    public static function jelszo_hiba(string $jelszo): ?string
    {
        if (strlen($jelszo) < self::MIN_JELSZO) {
            return 'A titkosító jelszó legalább ' . self::MIN_JELSZO . ' karakter legyen.';
        }

        return null;
    }

    /**
     * @return string|null null = kész; különben a hiba szövege
     */
    public static function titkosit_fajl(string $forras, string $cel, string $jelszo): ?string
    {
        if (!self::elerheto()) {
            return 'A szerveren nincs libsodium – a titkosítás nem lehetséges.';
        }

        if (($h = self::jelszo_hiba($jelszo)) !== null) {
            return $h;
        }

        $be = @fopen($forras, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

        if ($be === false) {
            return 'A mentésfájl nem olvasható: ' . basename($forras);
        }

        $ideiglenes = $cel . '.tmp';
        $ki         = @fopen($ideiglenes, 'wb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

        if ($ki === false) {
            fclose($be); // phpcs:ignore WordPress.WP.AlternativeFunctions

            return 'A titkosított fájl nem írható: ' . dirname($cel);
        }

        $hiba = null;

        try {
            $so  = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
            $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
            $kulcs = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $jelszo, $so, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);

            [$allapot, $folyam] = sodium_crypto_secretstream_xchacha20poly1305_init_push($kulcs);
            sodium_memzero($kulcs);

            $fejlec = self::MAGIC . pack('NN', $ops, $mem) . $so . $folyam;

            if (fwrite($ki, $fejlec) !== strlen($fejlec)) { // phpcs:ignore WordPress.WP.AlternativeFunctions
                throw new RuntimeException('írási hiba');
            }

            $adat = (string) fread($be, self::DARAB); // phpcs:ignore WordPress.WP.AlternativeFunctions

            do {
                $kovetkezo = feof($be) ? '' : (string) fread($be, self::DARAB); // phpcs:ignore WordPress.WP.AlternativeFunctions
                $utolso    = $kovetkezo === '' && feof($be);
                $titkos    = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $allapot,
                    $adat,
                    '',
                    $utolso ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                );
                $sor = pack('N', strlen($titkos)) . $titkos;

                if (fwrite($ki, $sor) !== strlen($sor)) { // phpcs:ignore WordPress.WP.AlternativeFunctions
                    throw new RuntimeException('írási hiba (megtelt a lemez?)');
                }

                $adat = $kovetkezo;
            } while (!$utolso);
        } catch (Throwable $e) {
            $hiba = 'A titkosítás megszakadt: ' . $e->getMessage();
        }

        fclose($be); // phpcs:ignore WordPress.WP.AlternativeFunctions

        if (!fclose($ki) && $hiba === null) { // phpcs:ignore WordPress.WP.AlternativeFunctions
            $hiba = 'A titkosított fájl nem zárható le.';
        }

        if ($hiba === null && !@rename($ideiglenes, $cel)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $hiba = 'A titkosított fájl nem nevezhető át: ' . basename($cel);
        }

        if ($hiba !== null) {
            @unlink($ideiglenes); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return $hiba;
        }

        return null;
    }

    /**
     * @return string|null null = kész; különben a hiba szövege (rossz jelszó, sérült fájl)
     */
    public static function visszafejt_fajl(string $forras, string $cel, string $jelszo)
    {
        if (!self::elerheto()) {
            return 'A szerveren nincs libsodium – a titkosított mentés nem nyitható meg.';
        }

        $be = @fopen($forras, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

        if ($be === false) {
            return 'A mentésfájl nem olvasható.';
        }

        $hossz = strlen(self::MAGIC) + 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $fejlec = (string) fread($be, $hossz); // phpcs:ignore WordPress.WP.AlternativeFunctions

        if (strlen($fejlec) !== $hossz || strncmp($fejlec, self::MAGIC, strlen(self::MAGIC)) !== 0) {
            fclose($be); // phpcs:ignore WordPress.WP.AlternativeFunctions

            return 'Ez nem titkosított ' . (class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::termeknev() : 'Műhely') . '-mentés.';
        }

        $p    = unpack('Nops/Nmem', substr($fejlec, strlen(self::MAGIC), 8));
        $so   = substr($fejlec, strlen(self::MAGIC) + 8, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $fol  = substr($fejlec, strlen(self::MAGIC) + 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ideiglenes = $cel . '.tmp';
        $ki   = @fopen($ideiglenes, 'wb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

        if ($ki === false) {
            fclose($be); // phpcs:ignore WordPress.WP.AlternativeFunctions

            return 'A kibontott fájl nem írható: ' . dirname($cel);
        }

        $hiba  = null;
        $vege  = false;

        try {
            // Védelem: a fejlécben szereplő költség nem lehet észszerűtlen (sérült vagy támadó fájl).
            if ($p['mem'] > 1024 * 1024 * 1024 || $p['ops'] > 20) {
                throw new RuntimeException('a fájl fejléce hibás');
            }

            $kulcs = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $jelszo, $so, (int) $p['ops'], (int) $p['mem'], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
            $allapot = sodium_crypto_secretstream_xchacha20poly1305_init_pull($fol, $kulcs);
            sodium_memzero($kulcs);
            $elso = true;

            while (!$vege) {
                $h = (string) fread($be, 4); // phpcs:ignore WordPress.WP.AlternativeFunctions

                if (strlen($h) !== 4) {
                    throw new RuntimeException('a fájl csonka');
                }

                $n = unpack('N', $h)[1];

                if ($n < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $n > self::DARAB + 64) {
                    throw new RuntimeException('a fájl sérült');
                }

                $titkos = (string) fread($be, $n); // phpcs:ignore WordPress.WP.AlternativeFunctions

                if (strlen($titkos) !== $n) {
                    throw new RuntimeException('a fájl csonka');
                }

                $r = sodium_crypto_secretstream_xchacha20poly1305_pull($allapot, $titkos);

                if ($r === false) {
                    throw new RuntimeException($elso ? 'rossz jelszó, vagy sérült fájl' : 'a fájl sérült');
                }

                $elso = false;

                if (fwrite($ki, $r[0]) !== strlen($r[0])) { // phpcs:ignore WordPress.WP.AlternativeFunctions
                    throw new RuntimeException('írási hiba (megtelt a lemez?)');
                }

                $vege = $r[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
        } catch (Throwable $e) {
            $hiba = 'A mentés nem nyitható meg: ' . $e->getMessage() . '.';
        }

        fclose($be); // phpcs:ignore WordPress.WP.AlternativeFunctions
        fclose($ki); // phpcs:ignore WordPress.WP.AlternativeFunctions

        if ($hiba === null && !@rename($ideiglenes, $cel)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $hiba = 'A kibontott fájl nem nevezhető át.';
        }

        if ($hiba !== null) {
            @unlink($ideiglenes); // phpcs:ignore WordPress.PHP.NoSilencedErrors

            return $hiba;
        }

        return null;
    }
}
