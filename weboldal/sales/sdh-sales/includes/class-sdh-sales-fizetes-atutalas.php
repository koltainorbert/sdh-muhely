<?php
/**
 * SDH Értékesítés – átutalás: a vevőnek szóló átutalási adatok (kedvezményezett, számlaszám, IBAN, közlemény, határidő,
 * MNB QR), a díjbekérő levél és a fizetési emlékeztető.
 *
 *   adatok(array $rendeles): array{kedvezmenyezett, bankszamla, iban, bic, osszeg, kozlemeny, hatarido, qr_url, qr_svg}
 *   dijbekero_level(int $id): bool   – a rendelés létrehozásakor (SDH_Sales_Rendeles::letrehoz) fut
 *   emlekezteto_level(int $id): bool – a cron hívja (SDH_Sales_Fizetes::cron)
 *   level(string $sablon, string $cimzett, array $adat, array $csatolmany = []): bool – SDH_Sales_Email::kuld vagy wp_mail
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Fizetes_Atutalas
{
    public static function init(): void
    {
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function adatok(array $r): array
    {
        $qr  = SDH_Sales_Qr::mnb((int) $r['id']);
        $svg = '';

        if ($qr !== '') {
            try {
                $svg = SDH_Sales_Qr::svg($qr, 4, 4, __('Átutalási QR-kód', 'sdh-sales'));
            } catch (Throwable $e) {
                $svg = '';
            }
        }

        return [
            'kedvezmenyezett' => (string) SDH_Sales::b('ceg.nev', ''),
            'bankszamla'      => (string) SDH_Sales::b('ceg.bankszamla', ''),
            'iban'            => (string) SDH_Sales::b('ceg.iban', ''),
            'bic'             => (string) SDH_Sales::b('ceg.bic', ''),
            'osszeg'          => max(0, (int) $r['brutto'] - (int) $r['fizetett']),
            'kozlemeny'       => (string) $r['kozlemeny'],
            'hatarido'        => (string) $r['hatarido'],
            'qr_url'          => $qr !== '' ? SDH_Sales_Rendeles::qr_url($r) : '',
            'qr_svg'          => $svg,
        ];
    }

    /**
     * Levél küldése: a sablonos levélküldő, ha megvan; különben egyszerű szöveges levél.
     *
     * @param array<string, mixed> $adat
     * @param array<int, string>   $csatolmany
     */
    public static function level(string $sablon, string $cimzett, array $adat, array $csatolmany = []): bool
    {
        if (class_exists('SDH_Sales_Email') && method_exists('SDH_Sales_Email', 'kuld')) {
            return SDH_Sales_Email::kuld($sablon, $cimzett, $adat, $csatolmany);
        }

        $targy = (string) ($adat['_targy'] ?? SDH_Sales::termeknev());
        $sorok = [];

        foreach ($adat as $k => $v) {
            if (is_scalar($v) && $k[0] !== '_' && substr((string) $k, -5) !== '_html' && (string) $v !== '') {
                $sorok[] = ucfirst(str_replace('_', ' ', (string) $k)) . ': ' . (string) $v;
            }
        }

        return (bool) wp_mail($cimzett, $targy, implode("\n", $sorok), [], $csatolmany);
    }

    /** @param array<string, mixed> $r */
    private static function tetelek_html(array $r): string
    {
        $h = '<table class="tetelek"><tr><th>' . esc_html__('Tétel', 'sdh-sales') . '</th><th style="text-align:right">' . esc_html__('Nettó', 'sdh-sales') . '</th></tr>';

        foreach ((array) $r['tetelek'] as $t) {
            if (is_array($t)) {
                $h .= '<tr><td>' . esc_html((string) ($t['nev'] ?? '')) . ((int) ($t['mennyiseg'] ?? 1) > 1 ? ' × ' . (int) $t['mennyiseg'] : '') . '</td><td style="text-align:right">' . esc_html(SDH_Sales::ft((int) ($t['netto'] ?? 0))) . '</td></tr>';
            }
        }

        $h .= '<tr><td>' . esc_html(sprintf(__('Áfa (%d%%)', 'sdh-sales'), SDH_Sales::afa())) . '</td><td style="text-align:right">' . esc_html(SDH_Sales::ft((int) $r['afa'])) . '</td></tr>';
        $h .= '<tr><th>' . esc_html__('Bruttó', 'sdh-sales') . '</th><th style="text-align:right">' . esc_html(SDH_Sales::ft((int) $r['brutto'])) . '</th></tr></table>';

        return $h;
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function level_adat(array $r): array
    {
        $a   = self::adatok($r);
        $dbk = '';

        if ((string) $r['szamla_dijbekero'] !== '' && class_exists('SDH_Sales_Szamlazz') && method_exists('SDH_Sales_Szamlazz', 'pdf_link')) {
            $dbk = SDH_Sales_Szamlazz::pdf_link((int) $r['id'], 'dijbekero');
        }

        return [
            '_rendeles_id'   => (int) $r['id'],
            '_targy'         => sprintf(__('Díjbekérő – %s', 'sdh-sales'), (string) $r['azon']),
            'nev'            => (string) ($r['vevo']['kapcsolattarto'] ?? $r['vevo']['nev'] ?? ''),
            'rendeles'       => (string) $r['azon'],
            'osszeg'         => SDH_Sales::ft((int) $a['osszeg']),
            'kozlemeny'      => (string) $a['kozlemeny'],
            'hatarido'       => (string) $a['hatarido'],
            'tetelek_html'   => self::tetelek_html($r),
            'qr_url'         => (string) $a['qr_url'],
            'szamla_url'     => $dbk,
            'fiok_url'       => SDH_Sales_Rendeles::statusz_url($r),
            'ceg_bankszamla' => (string) $a['bankszamla'],
            'ceg_iban'       => (string) $a['iban'],
        ];
    }

    /** A QR PNG-je ideiglenes fájlba (levélcsatolmány); a hívó törli. */
    private static function qr_fajl(array $r): string
    {
        $adat = SDH_Sales_Qr::mnb((int) $r['id']);

        if ($adat === '' || !function_exists('imagecreate')) {
            return '';
        }

        $f = trailingslashit(get_temp_dir()) . 'atutalas-qr-' . sanitize_file_name((string) $r['azon']) . '.png';

        return file_put_contents($f, SDH_Sales_Qr::png($adat, 6, 4)) ? $f : '';
    }

    /** @param array<int, string> $cs */
    private static function kuld(string $sablon, array $r, array $adat): bool
    {
        $cs = [];
        $qr = self::qr_fajl($r);

        if ($qr !== '') {
            $cs[] = $qr;
        }

        if (class_exists('SDH_Sales_Szamlazz') && method_exists('SDH_Sales_Szamlazz', 'dijbekero_pdf')) {
            $pdf = SDH_Sales_Szamlazz::dijbekero_pdf((int) $r['id']);

            if (is_string($pdf) && $pdf !== '' && is_readable($pdf)) {
                $cs[] = $pdf;
            }
        }

        try {
            return self::level($sablon, (string) $r['email'], $adat, $cs);
        } finally {
            if ($qr !== '' && is_file($qr)) {
                wp_delete_file($qr);
            }
        }
    }

    public static function dijbekero_level(int $id): bool
    {
        $r = SDH_Sales_Rendeles::rendeles($id);

        if ($r === null || (string) $r['fizmod'] !== 'atutalas' || !is_email((string) $r['email'])) {
            return false;
        }

        return self::kuld('dijbekero', $r, self::level_adat($r));
    }

    public static function emlekezteto_level(int $id): bool
    {
        $r = SDH_Sales_Rendeles::rendeles($id);

        if ($r === null || !is_email((string) $r['email'])) {
            return false;
        }

        $a           = self::level_adat($r);
        $a['_targy'] = sprintf(__('Emlékeztető: %s', 'sdh-sales'), (string) $r['azon']);

        return self::kuld('dijbekero_emlekezteto', $r, $a);
    }
}
