<?php
/**
 * SDH Értékesítés – e-mail: sablonos, reszponzív HTML-levél (inline stílus, az Arculat világos színeivel) + szöveges rész.
 *
 * API:
 *   kuld(string $sablon, string $cimzett, array $adat, array $csatolmany = []): bool
 *     – $sablon: a `tartalom` tár `email` típusú eleme (az adminban szerkeszthető), tartalék: `data/email/<sablon>.json`
 *       ({cim, leiras, targy, html, szoveg?, tokenek[]}); kikapcsolt sablon nem megy ki.
 *     – $adat: tokenek, kapcsos zárójellel vagy anélkül (`{nev}` / `nev`). A közös tokenek (`{termeknev}`, `{ceg}`, …)
 *       maguktól jönnek. Érték a HTML-be escapelve kerül; `*_url` → esc_url; `*_html` → wp_kses_post (a hívó felel érte).
 *       Feltételes rész: `{#kulcs}…{/kulcs}` csak akkor marad, ha a token nem üres. `_rendeles_id`: a napló hivatkozása.
 *     – $csatolmany: helyi fájlutak (wp_mail).
 *   osszeallit(string $sablon, array $adat): ?array{targy, html, szoveg} – küldés nélkül (előnézet, teszt)
 *   sablonok(): array – a ismert sablonkulcsok (data/email) címmel és tokenlistával
 * Szűrő: `sdh_sales_email_kuldes` (null, $level) → bool: a küldés elfogása (pl. teszt, külső levélküldő).
 * Feladó: `email.felado` + `ceg.nev`; válaszcím: `ceg.email`. Minden küldés naplózva (a cím maszkolva).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Email
{
    /** Tartalék színek, ha az Arculat modul nincs meg (világos mód). */
    private const SZIN_ALAP = [
        'bg'      => '#f4f5f9',
        'felulet' => '#ffffff',
        'szoveg'  => '#0f1222',
        'halvany' => '#4b5168',
        'vonal'   => '#e3e5ec',
        'kiemelo' => '#c41f1a',
        'ok'      => '#047857',
    ];

    /** @var string a phpmailer_init-nek átadott szöveges rész */
    private static string $alt = '';

    public static function init(): void
    {
        add_action('phpmailer_init', [self::class, 'phpmailer']);
    }

    /* =================================================================
     * Küldés
     * ============================================================== */

    /**
     * @param array<string, mixed>   $adat
     * @param array<int, string>     $csatolmany
     */
    public static function kuld(string $sablon, string $cimzett, array $adat, array $csatolmany = []): bool
    {
        $sablon  = sanitize_key($sablon);
        $cimzett = trim($cimzett);
        $ref     = (int) ($adat['_rendeles_id'] ?? 0);

        if (!is_email($cimzett)) {
            self::naplo('Levél nem ment ki (' . $sablon . '): érvénytelen címzett.', 'figyel', $ref);

            return false;
        }

        $l = self::osszeallit($sablon, $adat);

        if ($l === null) {
            self::naplo('Levél nem ment ki (' . $sablon . ' → ' . self::maszk($cimzett) . '): a sablon nincs meg vagy ki van kapcsolva.', 'figyel', $ref);

            return false;
        }

        $fejlec = ['Content-Type: text/html; charset=UTF-8'];
        $felado = sanitize_email((string) SDH_Sales::b('email.felado', ''));
        $nev    = self::fejlec_nev((string) SDH_Sales::b('ceg.nev', '') ?: SDH_Sales::termeknev());

        if (is_email($felado)) {
            $fejlec[] = 'From: ' . ($nev !== '' ? '"' . $nev . '" ' : '') . '<' . $felado . '>';
        }

        $valasz = sanitize_email((string) SDH_Sales::b('ceg.email', ''));

        if (is_email($valasz) && $valasz !== $felado) {
            $fejlec[] = 'Reply-To: ' . $valasz;
        }

        $fajlok = array_values(array_filter(array_map('strval', $csatolmany), static fn (string $f): bool => $f !== '' && is_readable($f)));
        $level  = [
            'sablon'     => $sablon,
            'cimzett'    => $cimzett,
            'targy'      => $l['targy'],
            'html'       => $l['html'],
            'szoveg'     => $l['szoveg'],
            'fejlec'     => $fejlec,
            'csatolmany' => $fajlok,
        ];

        $elfog = apply_filters('sdh_sales_email_kuldes', null, $level);

        if (is_bool($elfog)) {
            self::naplo('Levél (' . $sablon . ' → ' . self::maszk($cimzett) . ') átadva a szűrőnek: ' . ($elfog ? 'elküldve' : 'nem ment ki') . '.', $elfog ? 'info' : 'figyel', $ref);

            return $elfog;
        }

        self::$alt = $l['szoveg'];

        try {
            $ok = (bool) wp_mail($cimzett, $l['targy'], $l['html'], $fejlec, $fajlok);
        } finally {
            self::$alt = '';
        }

        self::naplo('Levél ' . ($ok ? 'elküldve' : 'NEM ment ki') . ': ' . $sablon . ' → ' . self::maszk($cimzett) . ($fajlok !== [] ? ' (' . count($fajlok) . ' csatolmány)' : '') . '.', $ok ? 'info' : 'hiba', $ref);

        return $ok;
    }

    /** A szöveges rész (multipart/alternative) – csak a saját levelünkhöz. @param mixed $m */
    public static function phpmailer($m): void
    {
        if (self::$alt !== '' && is_object($m) && property_exists($m, 'AltBody')) {
            $m->AltBody = self::$alt;
        }
    }

    /* =================================================================
     * Összeállítás
     * ============================================================== */

    /**
     * A sablon nyers adata: a tartalom-tárból (ha ott van), különben a data/ fájlból. Kikapcsolt sablon: null.
     *
     * @return array<string, mixed>|null
     */
    /** @var array<string, mixed>|null a Stúdió előnézete: még nem mentett sablon (csak az előnézet kérésének idejére) */
    public static ?array $elonezet = null;

    public static function sablon(string $sablon): ?array
    {
        $sablon = sanitize_key($sablon);

        if ($sablon === '') {
            return null;
        }

        if (self::$elonezet !== null) {
            return self::$elonezet;
        }

        if (class_exists('SDH_Sales_Tartalom')) {
            $t = SDH_Sales_Tartalom::egy('email', $sablon);

            if ($t !== null) {
                return $t['aktiv'] && !empty($t['adat']['html']) ? $t['adat'] : null;
            }
        }

        $a = SDH_Sales::adat('email/' . $sablon . '.json');

        return is_array($a) && !empty($a['html']) ? $a : null;
    }

    /** @return array<string, array{cim: string, leiras: string, tokenek: array<int, string>}> */
    public static function sablonok(): array
    {
        $ki = [];

        foreach (glob(SDH_SALES_DIR . 'data/email/*.json') ?: [] as $f) {
            $a = json_decode((string) file_get_contents($f), true);

            if (is_array($a)) {
                $ki[basename($f, '.json')] = [
                    'cim'     => (string) ($a['cim'] ?? basename($f, '.json')),
                    'leiras'  => (string) ($a['leiras'] ?? ''),
                    'tokenek' => array_values(array_map('strval', (array) ($a['tokenek'] ?? []))),
                ];
            }
        }

        ksort($ki);

        return $ki;
    }

    /**
     * @param array<string, mixed> $adat
     * @return array{targy: string, html: string, szoveg: string}|null
     */
    public static function osszeallit(string $sablon, array $adat): ?array
    {
        $s = self::sablon($sablon);

        if ($s === null) {
            return null;
        }

        $nyers = self::tokenek($adat);

        // A sablonban maradt, ismeretlen tokenek üresek lesznek (a látogató ne lásson „{valami}”-t).
        if (preg_match_all('/\{([a-z0-9_]{1,40})\}/', (string) $s['html'] . (string) ($s['targy'] ?? '') . (string) ($s['szoveg'] ?? ''), $m) > 0) {
            foreach ($m[0] as $tok) {
                $nyers += [$tok => ''];
            }
        }

        $html  = self::feltetel((string) $s['html'], $nyers);
        $targy = self::feltetel((string) ($s['targy'] ?? ''), $nyers);

        // Tárgy: nyers érték, sortörés nélkül (fejléc-befecskendezés ellen).
        $targy = trim(preg_replace('/[\r\n\t]+/', ' ', wp_specialchars_decode(strtr($targy, $nyers), ENT_QUOTES)) ?? '');
        $targy = $targy !== '' ? mb_substr($targy, 0, 200) : SDH_Sales::termeknev();

        $torzs  = strtr($html, self::escapel($nyers));
        $torzs  = self::stilus($torzs);
        $szoveg = !empty($s['szoveg']) ? strtr(self::feltetel((string) $s['szoveg'], $nyers), $nyers) : self::szovegge($torzs);
        $elo    = mb_substr(trim(preg_replace('/\s+/', ' ', self::szovegge($torzs)) ?? ''), 0, 140);

        return ['targy' => $targy, 'html' => self::keret($targy, $torzs, $elo), 'szoveg' => self::szoveg_keret($szoveg)];
    }

    /**
     * A levél tokenjei (nyers értékek): a közösek + a cég bankadatai + a hívóéi.
     *
     * @param array<string, mixed> $adat
     * @return array<string, string>
     */
    public static function tokenek(array $adat): array
    {
        $t = [];

        foreach ($adat as $k => $v) {
            $k = trim((string) $k, '{}');

            if ($k === '' || $k[0] === '_' || preg_match('/^[a-z0-9_]{1,40}$/', $k) !== 1) {
                continue;
            }

            if (is_array($v)) {
                $v = implode(', ', array_map('strval', array_filter($v, 'is_scalar')));
            }

            $t['{' . $k . '}'] = is_scalar($v) || $v === null ? (string) $v : '';
        }

        $kozos = SDH_Sales::tokenek() + [
            '{ceg_bankszamla}' => (string) SDH_Sales::b('ceg.bankszamla', ''),
            '{ceg_iban}'       => (string) SDH_Sales::b('ceg.iban', ''),
            '{ceg_bic}'        => (string) SDH_Sales::b('ceg.bic', ''),
            '{fiok_url}'       => home_url('/fiok/'),
        ];

        return $t + $kozos;
    }

    /**
     * Escapelt tokenértékek a HTML-hez.
     *
     * @param array<string, string> $t
     * @return array<string, string>
     */
    private static function escapel(array $t): array
    {
        $ki = [];

        foreach ($t as $k => $v) {
            $n = trim($k, '{}');

            if (substr($n, -5) === '_html') {
                $ki[$k] = wp_kses_post($v);
            } elseif (substr($n, -4) === '_url' || $n === 'webhely') {
                $ki[$k] = esc_url($v);
            } else {
                $ki[$k] = nl2br(esc_html($v), false);
            }
        }

        return $ki;
    }

    /**
     * `{#kulcs}…{/kulcs}`: a rész csak nem üres tokennél marad (egymásba ágyazva is).
     *
     * @param array<string, string> $t
     */
    private static function feltetel(string $s, array $t): string
    {
        for ($i = 0; $i < 5 && strpos($s, '{#') !== false; $i++) {
            $s = (string) preg_replace_callback('/\{#([a-z0-9_]+)\}(.*?)\{\/\1\}/s', static function (array $m) use ($t): string {
                return trim((string) ($t['{' . $m[1] . '}'] ?? '')) !== '' ? $m[2] : '';
            }, $s);
        }

        return $s;
    }

    /* =================================================================
     * Megjelenés
     * ============================================================== */

    /** @return array<string, string> a levél színei (az Arculat világos módja, érvényesítve) */
    public static function szinek(): array
    {
        $ki = self::SZIN_ALAP;

        if (!class_exists('SDH_Sales_Arculat')) {
            return $ki;
        }

        $alap = SDH_Sales_Arculat::alapok();

        foreach (array_keys($ki) as $tok) {
            $v = (string) SDH_Sales::b(SDH_Sales_Arculat::kulcs($tok, 'vilagos'), '');
            $v = $v !== '' ? (string) (SDH_Sales_Arculat::szin($v) ?? '') : '';

            if ($v === '' && isset($alap[$tok][1])) {
                $v = (string) (SDH_Sales_Arculat::szin((string) $alap[$tok][1]) ?? '');
            }

            if ($v !== '' && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,\s%]+\))$/', $v) === 1) {
                $ki[$tok] = $v;
            }
        }

        return $ki;
    }

    /** Inline stílus a sablon gyakori elemeire (a levelezők a <style>-t sokszor eldobják). */
    private static function stilus(string $h): string
    {
        $sz = self::szinek();
        $st = [
            'p'          => 'margin:0 0 14px;',
            'h1'         => 'margin:0 0 16px;font-size:24px;line-height:1.25;color:' . $sz['szoveg'] . ';',
            'h2'         => 'margin:22px 0 10px;font-size:18px;line-height:1.3;color:' . $sz['szoveg'] . ';',
            'ul'         => 'margin:0 0 14px;padding-left:20px;',
            'ol'         => 'margin:0 0 14px;padding-left:20px;',
            'li'         => 'margin:0 0 6px;',
            'a'          => 'color:' . $sz['kiemelo'] . ';text-decoration:underline;',
            'code'       => 'font-family:ui-monospace,Menlo,Consolas,monospace;font-size:15px;background:' . $sz['bg'] . ';border:1px solid ' . $sz['vonal'] . ';border-radius:6px;padding:2px 6px;',
            'table'      => 'border-collapse:collapse;width:100%;margin:0 0 14px;',
            'td'         => 'padding:8px 10px;border-bottom:1px solid ' . $sz['vonal'] . ';vertical-align:top;',
            'th'         => 'padding:8px 10px;border-bottom:1px solid ' . $sz['vonal'] . ';text-align:left;color:' . $sz['halvany'] . ';font-weight:600;',
            'blockquote' => 'margin:0 0 14px;padding:12px 16px;background:' . $sz['bg'] . ';border-left:3px solid ' . $sz['kiemelo'] . ';border-radius:8px;',
        ];

        // Gomb: <a class="gomb" href="…">
        $h = (string) preg_replace(
            '/<a\s+class="gomb"\s+/i',
            '<a style="display:inline-block;background:' . $sz['kiemelo'] . ';color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:12px;margin:4px 0 8px;" ',
            $h
        );

        // Kiemelt doboz: <div class="doboz">
        $h = (string) preg_replace(
            '/<div\s+class="doboz">/i',
            '<div style="margin:0 0 16px;padding:14px 16px;background:' . $sz['bg'] . ';border:1px solid ' . $sz['vonal'] . ';border-radius:12px;">',
            $h
        );

        foreach ($st as $tag => $css) {
            $h = (string) preg_replace('/<' . $tag . '(?=[\s>])(?![^>]*\bstyle=)/i', '<' . $tag . ' style="' . $css . '"', $h);
        }

        return $h;
    }

    private static function keret(string $targy, string $torzs, string $elo): string
    {
        $sz   = self::szinek();
        $logo = class_exists('SDH_Sales_Arculat') ? SDH_Sales_Arculat::logo('vilagos') : '';
        $fej  = $logo !== ''
            ? '<img src="' . esc_url($logo) . '" alt="' . esc_attr(SDH_Sales::termeknev()) . '" height="36" style="display:block;height:36px;width:auto;border:0;">'
            : '<span style="font-size:20px;font-weight:700;color:' . $sz['szoveg'] . ';">' . esc_html(SDH_Sales::termeknev()) . '</span>';

        $lab = array_filter([
            esc_html((string) SDH_Sales::b('ceg.nev', '')),
            esc_html((string) SDH_Sales::b('ceg.cim', '')),
            esc_html((string) SDH_Sales::b('ceg.adoszam', '')) !== '' ? 'Adószám: ' . esc_html((string) SDH_Sales::b('ceg.adoszam', '')) : '',
        ]);
        $kapcs = array_filter([
            is_email((string) SDH_Sales::b('ceg.email', '')) ? '<a href="mailto:' . esc_attr((string) SDH_Sales::b('ceg.email', '')) . '" style="color:' . $sz['halvany'] . ';">' . esc_html((string) SDH_Sales::b('ceg.email', '')) . '</a>' : '',
            esc_html((string) SDH_Sales::b('ceg.telefon', '')),
            '<a href="' . esc_url(home_url('/')) . '" style="color:' . $sz['halvany'] . ';">' . esc_html((string) wp_parse_url(home_url('/'), PHP_URL_HOST)) . '</a>',
        ]);
        $betu = '-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif';

        return '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>' . esc_html($targy) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . $sz['bg'] . ';-webkit-text-size-adjust:100%;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . esc_html($elo) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $sz['bg'] . ';"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:' . $sz['felulet'] . ';border:1px solid ' . $sz['vonal'] . ';border-radius:16px;">'
            . '<tr><td style="padding:22px 28px;border-bottom:3px solid ' . $sz['kiemelo'] . ';font-family:' . $betu . ';">' . $fej . '</td></tr>'
            . '<tr><td style="padding:28px;font-family:' . $betu . ';font-size:16px;line-height:1.6;color:' . $sz['szoveg'] . ';">' . $torzs . '</td></tr>'
            . '<tr><td style="padding:18px 28px;border-top:1px solid ' . $sz['vonal'] . ';font-family:' . $betu . ';font-size:13px;line-height:1.5;color:' . $sz['halvany'] . ';">'
            . ($lab !== [] ? implode(' · ', $lab) . '<br>' : '') . implode(' · ', $kapcs)
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** HTML → olvasható szöveg (a linkek címe zárójelben). */
    public static function szovegge(string $h): string
    {
        $h = (string) preg_replace_callback('/<a\s[^>]*href=("|\')(.*?)\1[^>]*>(.*?)<\/a>/is', static function (array $m): string {
            $u = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            $t = trim(wp_strip_all_tags($m[3]));

            return $t === '' || $t === $u ? $u : $t . ' (' . $u . ')';
        }, $h);
        $h = (string) preg_replace('/<br\s*\/?>/i', "\n", $h);
        $h = (string) preg_replace('/<li[^>]*>/i', "\n- ", $h);
        $h = (string) preg_replace('/<\/(p|h[1-6]|div|ul|ol|table|tr|blockquote)>/i', "\n\n", $h);
        $h = (string) preg_replace('/<\/t[dh]>/i', "\t", $h);
        $h = html_entity_decode(wp_strip_all_tags($h), ENT_QUOTES, 'UTF-8');
        $h = (string) preg_replace("/[ \t]+\n/", "\n", $h);
        $h = (string) preg_replace("/\n{3,}/", "\n\n", $h);

        return trim($h);
    }

    private static function szoveg_keret(string $s): string
    {
        $lab = array_filter([(string) SDH_Sales::b('ceg.nev', ''), (string) SDH_Sales::b('ceg.email', ''), (string) SDH_Sales::b('ceg.telefon', ''), home_url('/')]);

        return trim($s) . "\n\n--\n" . implode("\n", $lab) . "\n";
    }

    /* =================================================================
     * Segédek
     * ============================================================== */

    private static function fejlec_nev(string $s): string
    {
        return trim(str_replace(['"', "\r", "\n", '<', '>'], '', wp_specialchars_decode($s, ENT_QUOTES)));
    }

    /** „ko***@pelda.hu” – a naplóba. */
    public static function maszk(string $email): string
    {
        $r = explode('@', $email, 2);

        if (count($r) !== 2) {
            return '***';
        }

        return mb_substr($r[0], 0, 2) . '***@' . $r[1];
    }

    private static function naplo(string $szoveg, string $szint, int $rendeles_id): void
    {
        if (class_exists('SDH_Sales_Naplo')) {
            SDH_Sales_Naplo::ir('email', $szoveg, $szint, $rendeles_id > 0 ? 'rendeles' : '', $rendeles_id);
        }
    }
}
