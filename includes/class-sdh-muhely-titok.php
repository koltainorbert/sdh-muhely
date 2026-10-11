<?php
/**
 * Titkok tárolása (0.52): a külső szolgáltatások kulcsai az adatbázisban
 * titkosítva állnak.
 *
 * Mit véd: aki az adatbázist (vagy egy mentésfájlt) megszerzi, a kulcsot
 * nem tudja belőle kiolvasni – ahhoz a WordPress wp-config.php-jában lévő
 * titkos sók is kellenek. Mit NEM véd: aki a teljes szerverhez hozzáfér
 * (fájlok + adatbázis), az vissza tudja fejteni.
 *
 * Következmény: ha a rendszer másik gépre költözik, és ott a wp-config.php
 * sói mások, a titkosított kulcs olvashatatlan – újra be kell írni a
 * Beállításokban. (A mentésből visszatöltött kulcsra ugyanez igaz.)
 *
 * A régi, titkosítatlan érték olvasható marad, és az első betöltéskor
 * magától átáll (atallas()).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Titok
{
    private const ELOTAG  = 'sdh1:';
    private const ATALLAS = 'sdh_muhely_titok_atallas';

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'atallas']);
        add_action('init', [self::class, 'atallas'], 20);
    }

    public static function elerheto(): bool
    {
        return function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_generichash');
    }

    private static function kulcs(): string
    {
        return sodium_crypto_generichash('sdh-muhely-titok|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function titkos_e(string $ertek): bool
    {
        return strncmp($ertek, self::ELOTAG, strlen(self::ELOTAG)) === 0;
    }

    /** Nyílt szövegből tárolható alak. Üres marad az üres; ami már titkos, azt nem titkosítja újra. */
    public static function titkosit(string $nyilt): string
    {
        if ($nyilt === '' || self::titkos_e($nyilt) || !self::elerheto()) {
            return $nyilt;
        }

        try {
            $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return self::ELOTAG . base64_encode($n . sodium_crypto_secretbox($nyilt, $n, self::kulcs()));
        } catch (Throwable $hiba) {
            return $nyilt;
        }
    }

    /**
     * Tárolt alakból nyílt szöveg. A régi (titkosítatlan) értéket változatlanul
     * visszaadja; ha a titkos érték ezzel a telepítéssel nem fejthető vissza
     * (más sók), üres szöveg jön.
     */
    public static function visszafejt(string $tarolt): string
    {
        if (!self::titkos_e($tarolt)) {
            return $tarolt;
        }

        if (!self::elerheto()) {
            return '';
        }

        $nyers = base64_decode(substr($tarolt, strlen(self::ELOTAG)), true);

        if ($nyers === false || strlen($nyers) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        try {
            $ny = sodium_crypto_secretbox_open(
                substr($nyers, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($nyers, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                self::kulcs()
            );
        } catch (Throwable $hiba) {
            return '';
        }

        return $ny === false ? '' : (string) $ny;
    }

    /**
     * Mit kell eltárolni mentéskor: új kulcs → titkosítva; törlés → üres; különben a
     * MOST TÁROLT érték marad – akkor is, ha ezen a telepítésen épp nem olvasható (más sók).
     * Így egy másik mező mentése nem írja felül a kulcsot üresre, és a sók visszaállítása
     * után a régi kulcs újra működik.
     */
    public static function mentendo(string $opcio, string $uj, bool $torol): string
    {
        if ($torol) {
            return '';
        }

        if ($uj !== '') {
            return self::titkosit($uj);
        }

        $b      = get_option($opcio, null);
        $tarolt = is_array($b) && is_string($b['kulcs'] ?? null) ? (string) $b['kulcs'] : '';

        return self::titkos_e($tarolt) ? $tarolt : self::titkosit($tarolt);
    }

    /** Van eltárolt érték, de ezen a telepítésen nem olvasható (más sók)? */
    public static function olvashatatlan(string $tarolt): bool
    {
        return self::titkos_e($tarolt) && self::visszafejt($tarolt) === '';
    }

    /** Figyelmeztetés a Beállítások dobozába, ha a mentett kulcs ezen a telepítésen nem olvasható. */
    public static function figyelmeztetes(string $opcio): void
    {
        $b = get_option($opcio, null);

        if (is_array($b) && isset($b['kulcs']) && is_string($b['kulcs']) && self::olvashatatlan($b['kulcs'])) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">A mentett kulcs ezen a telepítésen nem olvasható '
                . '(a rendszer másik gépre költözött, vagy megváltoztak a WordPress titkos sói). Írd be újra a kulcsot.</div>';
        }
    }

    /**
     * A korábban titkosítatlanul eltárolt kulcsok átállítása – egyszer fut,
     * és nem változtat azon, ami már titkos.
     */
    public static function atallas(): void
    {
        if (!self::elerheto() || get_option(self::ATALLAS) === '1') {
            return;
        }

        $kesz = true;

        foreach (['sdh_muhely_szamla', 'sdh_muhely_imei_szolgaltato'] as $opcio) {
            $b = get_option($opcio, null);

            if (is_array($b) && isset($b['kulcs']) && is_string($b['kulcs']) && $b['kulcs'] !== '' && !self::titkos_e($b['kulcs'])) {
                $b['kulcs'] = self::titkosit($b['kulcs']);

                // Ha a titkosítás most nem sikerült, legközelebb újra megpróbáljuk.
                if (!self::titkos_e($b['kulcs'])) {
                    $kesz = false;

                    continue;
                }

                update_option($opcio, $b, false);
            }
        }

        if ($kesz) {
            update_option(self::ATALLAS, '1', false);
        }
    }
}
