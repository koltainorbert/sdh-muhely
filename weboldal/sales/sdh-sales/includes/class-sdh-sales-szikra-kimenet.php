<?php
/**
 * SDH Értékesítés – Szikra kimenet és bemenet védelme:
 *   - html(md): a modell Markdown-válasza → biztonságos HTML-részhalmaz (p, ul, ol, li, strong, em, code, a, br);
 *     csak saját domainre mutató link marad link, az idegen URL semlegesítve;
 *   - bemenet(s): a felhasználói szövegben a saját határoló címkéink semlegesítése (nem tud „kitörni” a blokkjából);
 *   - szivarog(valasz, system): a rendszerprompt vagy kulcs kiszivárgásának felismerése (kanári + szó szerinti mondatok).
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Szikra_Kimenet
{
    /** A határoló címkék, amelyeket a promptban használunk. */
    public const CIMKEK = ['tudas', 'forras', 'felhasznalo_uzenete', 'lecke', 'eszkoz_eredmeny', 'rendszer', 'system', 'utasitas'];

    /** @return array<string, array<string, bool>> */
    public static function engedett(): array
    {
        return [
            'p'      => [],
            'br'     => [],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
            'strong' => [],
            'em'     => [],
            'code'   => [],
            'a'      => ['href' => true, 'rel' => true, 'target' => true],
        ];
    }

    /** A saját webhely gazdaneve(i). @return array<int, string> */
    public static function sajat_hostok(): array
    {
        $h = array_filter([
            (string) wp_parse_url(home_url(), PHP_URL_HOST),
            (string) wp_parse_url(site_url(), PHP_URL_HOST),
        ]);

        return array_values(array_unique(array_map('strtolower', (array) apply_filters('sdh_sales_szikra_hostok', $h))));
    }

    /** Saját domainre mutat-e (relatív út is az). */
    public static function sajat_url(string $u): bool
    {
        $u = trim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($u === '' || preg_match('/[\s"\'<>`]/', $u)) {
            return false;
        }

        if ($u[0] === '#' || ($u[0] === '/' && (strlen($u) === 1 || $u[1] !== '/'))) {
            return true;
        }

        if (!preg_match('#^https?://#i', $u)) {
            return false;
        }

        $host = strtolower((string) wp_parse_url($u, PHP_URL_HOST));

        return $host !== '' && in_array($host, self::sajat_hostok(), true);
    }

    /** Teljes URL egy saját linkből. */
    private static function teljes(string $u): string
    {
        $u = html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $u[0] === '/' ? home_url($u) : $u;
    }

    /** A nem saját URL helyére kerülő szöveg. */
    public static function semleges(): string
    {
        return '[' . __('külső link eltávolítva', 'sdh-sales') . ']';
    }

    /** Egy sor inline-jelölései (a bemenet már escapelt). */
    private static function inline(string $t): string
    {
        $tarolt = [];
        $tarol  = static function (string $html) use (&$tarolt): string {
            $tarolt[] = $html;

            return "\x01" . (count($tarolt) - 1) . "\x02";
        };

        // Kód: a belseje érintetlen.
        $t = preg_replace_callback('/`([^`]+)`/', static fn ($m) => $tarol('<code>' . $m[1] . '</code>'), $t) ?? $t;

        // Kép: soha (csak a felirata marad).
        $t = preg_replace('/!\[([^\]]*)\]\(([^)\s]*)\)/', '$1', $t) ?? $t;

        // [felirat](url)
        $t = preg_replace_callback('/\[([^\]]{1,200})\]\(([^)\s]{1,600})\)/', static function ($m) use ($tarol) {
            if (!self::sajat_url($m[2])) {
                return $m[1] . ' ' . $tarol(esc_html(self::semleges()));
            }

            return $tarol('<a href="' . esc_url(self::teljes($m[2])) . '">' . $m[1] . '</a>');
        }, $t) ?? $t;

        // Csupasz URL-ek (és www.-kezdetűek).
        $t = preg_replace_callback('#\b((?:https?://|www\.)[^\s<>()]+[^\s<>().,;:!?\'"])#i', static function ($m) use ($tarol) {
            $u = stripos($m[1], 'www.') === 0 ? 'https://' . $m[1] : $m[1];

            if (!self::sajat_url($u)) {
                return $tarol(esc_html(self::semleges()));
            }

            return $tarol('<a href="' . esc_url(self::teljes($u)) . '">' . $m[1] . '</a>');
        }, $t) ?? $t;

        // Csupasz domainnevek (pl. „gonosz.example/x”), ha nem a sajátunk.
        $t = preg_replace_callback('#\b([a-z0-9][a-z0-9-]{0,62}(?:\.[a-z0-9-]{1,63})*\.(?:com|net|org|io|ru|cn|xyz|info|biz|top|link|click|example|hu|eu|de|app|dev|ly|co|me|tk|ml)(?:/[^\s<>]*)?)#i', static function ($m) use ($tarol) {
            $host = strtolower((string) preg_replace('#/.*$#', '', $m[1]));

            return in_array($host, self::sajat_hostok(), true) ? $m[1] : $tarol(esc_html(self::semleges()));
        }, $t) ?? $t;

        $t = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t) ?? $t;
        $t = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $t) ?? $t;
        $t = preg_replace('/(?<![*\w])\*([^*\n]+)\*(?![*\w])/', '<em>$1</em>', $t) ?? $t;

        return preg_replace_callback("/\x01(\\d+)\x02/", static fn ($m) => $tarolt[(int) $m[1]] ?? '', $t) ?? $t;
    }

    /** A modell válasza (Markdown) → biztonságos HTML. */
    public static function html(string $md): string
    {
        $md    = str_replace(["\r\n", "\r", "\x01", "\x02"], ["\n", "\n", '', ''], $md);
        $sorok = explode("\n", esc_html($md));
        $ki    = [];
        $i     = 0;
        $n     = count($sorok);

        while ($i < $n) {
            $s = $sorok[$i];

            if (trim($s) === '') {
                $i++;
                continue;
            }

            if (preg_match('/^```/', $s)) {
                $kod = [];
                $i++;

                while ($i < $n && !preg_match('/^```/', $sorok[$i])) {
                    $kod[] = $sorok[$i];
                    $i++;
                }

                $i++;
                $ki[] = '<p><code>' . implode('<br>', $kod) . '</code></p>';
                continue;
            }

            if (preg_match('/^#{1,6}\s+(.+?)\s*#*$/', $s, $m)) {
                $ki[] = '<p><strong>' . self::inline($m[1]) . '</strong></p>';
                $i++;
                continue;
            }

            if (preg_match('/^\s*([-*+•]|\d+[.)])\s+/', $s, $m)) {
                $rendezett = ctype_digit(substr(trim($m[1]), 0, 1));
                $elemek    = [];

                while ($i < $n && preg_match('/^\s*([-*+•]|\d+[.)])\s+(.*)$/', $sorok[$i], $mm)) {
                    $elemek[] = '<li>' . self::inline($mm[2]) . '</li>';
                    $i++;
                }

                $ki[] = ($rendezett ? '<ol>' : '<ul>') . implode('', $elemek) . ($rendezett ? '</ol>' : '</ul>');
                continue;
            }

            if (preg_match('/^(-{3,}|\*{3,}|_{3,})\s*$/', $s)) {
                $i++;
                continue;
            }

            $p = [];

            while ($i < $n && trim($sorok[$i]) !== '' && !preg_match('/^(#{1,6}\s|```|\s*([-*+•]|\d+[.)])\s+)/', $sorok[$i])) {
                $p[] = self::inline(trim(preg_replace('/^&gt;\s?/', '', $sorok[$i]) ?? $sorok[$i]));
                $i++;
            }

            $ki[] = '<p>' . implode('<br>', $p) . '</p>';
        }

        $html = wp_kses(implode("\n", $ki), self::engedett(), ['http', 'https']);

        // Minden link: noopener, és ugyanabban a lapban marad (saját domain).
        return preg_replace('/<a href="/', '<a rel="noopener" href="', $html) ?? $html;
    }

    /** Felhasználói (vagy más, nem megbízható) szöveg a promptba: a határoló címkéink nem zárhatók le / nyithatók meg benne. */
    public static function bemenet(string $s): string
    {
        $s = str_replace("\0", '', $s);
        $c = implode('|', array_map('preg_quote', self::CIMKEK));

        return preg_replace_callback('#<\s*(/?)\s*(' . $c . ')\b([^>]*)>#iu', static fn ($m) => '‹' . $m[1] . $m[2] . '›', $s) ?? $s;
    }

    /** A telepítéshez kötött kanári: ha a válaszban megjelenik, a rendszerprompt szivárgott. */
    public static function kanari(): string
    {
        return 'SZK-' . substr(hash_hmac('sha256', 'szikra-kanari', wp_salt('auth')), 0, 12);
    }

    /**
     * Kiszivárogna-e a válaszban a rendszerprompt vagy egy titok?
     * Jel: a kanári, egy API-kulcs alak, a valódi kulcs, vagy a rendszerprompt bármelyik ≥ 50 karakteres mondata szó szerint.
     */
    public static function szivarog(string $valasz, string $system): bool
    {
        if (strpos($valasz, self::kanari()) !== false || preg_match('/sk-ant-[A-Za-z0-9_-]{6,}/', $valasz)) {
            return true;
        }

        $kulcs = SDH_Sales::titkos(SDH_Sales_Szikra::TITOK);

        if ($kulcs !== '' && strpos($valasz, substr($kulcs, 0, 16)) !== false) {
            return true;
        }

        $v = self::tomorit($valasz);

        foreach (preg_split('/(?<=[.!?:])\s+|\n+/u', $system) ?: [] as $mondat) {
            $m = self::tomorit($mondat);

            if (mb_strlen($m) >= 50 && strpos($v, $m) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function tomorit(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(wp_strip_all_tags($s))));
    }
}
