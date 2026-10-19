<?php
/**
 * SDH Értékesítés – /llms.txt és /llms-full.txt (llmstxt.org javaslat: H1 név, összefoglaló idézetblokk, részletező
 * bekezdés, H2 linklisták `- [név](url): megjegyzés` alakban, „Optional” szakasz a másodlagos linkekkel).
 * A llms-full.txt a tényszerű tartalom tiszta Markdownban: funkciók leírással, csomagok és modulok ára (nettó + bruttó,
 * a tartalom-tárból), GYIK kérdés–válasz, telepítési követelmények és lépések, cégadatok.
 *
 * Kiszolgálás: `parse_request` a pontos útvonalra (a WP lekérdezés előtt, olcsón), `text/plain; charset=utf-8`,
 * `Cache-Control: public, max-age=3600`, ETag + 304, `X-Robots-Tag: noindex` (ne legyen a Google-találatok között).
 * Gyorsítótár: option, a tartalom ujjlenyomatára (SDH_Sales_Kereso::allapot()) kötve → tartalomváltozáskor újragenerálódik.
 * Méretkorlát: llms.txt ≤ `seo.llms_max` (50 KB), llms-full.txt ≤ `seo.llms_full_max` (400 KB) – a lista vége levágva, jelölve.
 * Kikapcsolás: `seo.llms` = false (404).
 *
 * API: SDH_Sales_Llms::szoveg(bool $teljes) → a Markdown (gyorsítótárból), SDH_Sales_Llms::general(bool $teljes) → frissen.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Llms
{
    public const OPT = 'sdh_sales_llms';

    public static function init(): void
    {
        add_action('parse_request', [self::class, 'kiszolgal'], 0);
        add_filter('redirect_canonical', [self::class, 'nincs_atiranyitas'], 10, 2);
        add_action('sdh_sales_kereso_epult', [self::class, 'urit']);
    }

    /** Melyik fájlt kérték: '' | 'llms' | 'llms-full'. */
    public static function keres_ut(?string $uri = null): string
    {
        $uri  = (string) ($uri ?? ($_SERVER['REQUEST_URI'] ?? ''));
        $ut   = (string) wp_parse_url($uri, PHP_URL_PATH);
        $alap = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');

        foreach (['llms', 'llms-full'] as $n) {
            if ($ut === $alap . '/' . $n . '.txt') {
                return $n;
            }
        }

        return '';
    }

    /** @param mixed $wp */
    public static function kiszolgal($wp = null): void
    {
        $n = self::keres_ut();

        if ($n === '') {
            return;
        }

        if (!(bool) SDH_Sales::b('seo.llms', true)) {
            return;
        }

        $v = self::valasz($n === 'llms-full');

        status_header($v['status']);

        foreach ($v['fejlec'] as $k => $e) {
            header($k . ': ' . $e);
        }

        if ($v['status'] === 200 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo $v['test']; // phpcs:ignore WordPress.Security.EscapeOutput -- text/plain
        }

        exit;
    }

    /**
     * A HTTP-válasz összerakása (tesztelhető, kiírás nélkül).
     *
     * @return array{status: int, fejlec: array<string, string>, test: string}
     */
    public static function valasz(bool $teljes, ?string $if_none_match = null): array
    {
        $s    = self::szoveg($teljes);
        $etag = '"' . substr(md5($s), 0, 16) . '"';
        $c    = self::cache();
        $ido  = (int) ($c['ido'] ?? time());
        $inm  = $if_none_match ?? (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        $f    = [
            'Content-Type'  => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600, stale-while-revalidate=86400',
            'ETag'          => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $ido) . ' GMT',
            'X-Robots-Tag'  => 'noindex, follow',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($inm !== '' && trim($inm) === $etag) {
            return ['status' => 304, 'fejlec' => $f, 'test' => ''];
        }

        return ['status' => 200, 'fejlec' => $f + ['Content-Length' => (string) strlen($s)], 'test' => $s];
    }

    /** @param mixed $uj @param mixed $kert */
    public static function nincs_atiranyitas($uj, $kert)
    {
        return self::keres_ut((string) $kert) !== '' ? false : $uj;
    }

    /* =================================================================
     * Gyorsítótár
     * ============================================================== */

    /** @return array<string, mixed> */
    private static function cache(): array
    {
        $c = get_option(self::OPT, []);

        return is_array($c) ? $c : [];
    }

    public static function urit(): void
    {
        delete_option(self::OPT);
    }

    public static function szoveg(bool $teljes): string
    {
        $all = SDH_Sales_Kereso::allapot();
        $c   = self::cache();
        $k   = $teljes ? 'full' : 'rovid';

        if (($c['allapot'] ?? '') === $all && isset($c[$k]) && is_string($c[$k])) {
            return $c[$k];
        }

        if (($c['allapot'] ?? '') !== $all) {
            $c = ['allapot' => $all, 'ido' => time()];
        }

        $c[$k] = self::general($teljes);
        update_option(self::OPT, $c, false);

        return $c[$k];
    }

    /* =================================================================
     * Generálás
     * ============================================================== */

    /** Markdown-link szövegének / megjegyzésnek tisztítása (sortörés, szögletes zárójel nélkül). */
    private static function md(string $s): string
    {
        $s = SDH_Sales_Seo::sima($s);

        return trim(str_replace(['[', ']', "\n"], ['(', ')', ' '], $s));
    }

    private static function link(string $nev, string $url, string $megj = ''): string
    {
        $megj = self::md($megj);

        return '- [' . self::md($nev) . '](' . esc_url_raw($url) . ')' . ($megj !== '' ? ': ' . $megj : '');
    }

    /** Biztonságos HTML (p/ul/ol/li/strong/em/a/br/code) → Markdown. */
    public static function html_md(string $h): string
    {
        $h = SDH_Sales::t($h);
        $h = (string) preg_replace_callback('#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', static fn ($m) => '[' . self::md(wp_strip_all_tags($m[2])) . '](' . esc_url_raw(html_entity_decode($m[1])) . ')', $h);
        $h = (string) preg_replace('#<(strong|b)>(.*?)</\1>#is', '**$2**', $h);
        $h = (string) preg_replace('#<(em|i)>(.*?)</\1>#is', '*$2*', $h);
        $h = (string) preg_replace('#<code>(.*?)</code>#is', '`$1`', $h);
        $h = (string) preg_replace('#<li[^>]*>\s*#i', "\n- ", $h);
        $h = (string) preg_replace('#<br\s*/?>#i', "\n", $h);
        $h = (string) preg_replace('#</(p|ul|ol)>#i', "\n\n", $h);
        $h = wp_strip_all_tags($h);
        $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $h = (string) preg_replace("/[ \t]+/", ' ', $h);
        $h = (string) preg_replace("/ *\n */", "\n", $h);
        $h = (string) preg_replace("/\n{3,}/", "\n\n", $h);

        return trim($h);
    }

    private static function ar(int $netto, string $ciklus): string
    {
        return SDH_Sales::ft($netto) . ' + ' . SDH_Sales::afa() . '% ÁFA (bruttó ' . SDH_Sales::ft(SDH_Sales::brutto($netto)) . ') / ' . $ciklus;
    }

    /** @return array<int, WP_Post> */
    private static function jogi_oldalak(): array
    {
        $ki   = [];
        $volt = [];
        $jogi = SDH_Sales_Seo::oldal(SDH_Sales_Seo::utvonalak()['jogi']);

        $posts = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 50, 'meta_key' => SDH_Sales_Tartalom::META_FORRAS, 'meta_value' => 'data/jogi/', 'meta_compare' => 'LIKE', 'orderby' => 'menu_order title', 'order' => 'ASC']);

        foreach ($posts as $p) {
            if (strpos((string) get_post_meta($p->ID, SDH_Sales_Tartalom::META_FORRAS, true), 'data/jogi/') === 0) {
                $ki[] = $p;
                $volt[$p->ID] = true;
            }
        }

        if ($jogi !== null) {
            foreach (get_pages(['child_of' => $jogi->ID, 'post_status' => 'publish', 'sort_column' => 'menu_order']) as $p) {
                if (!isset($volt[$p->ID])) {
                    $ki[] = $p;
                }
            }
        }

        return $ki;
    }

    /** @return array<int, array{nev: string, szoveg: string}> telepítési lépések és @param array<int, string> $kov követelmények */
    private static function telepites(array &$kov): array
    {
        $lep = [];

        foreach (SDH_Sales_Seo::elemek('telepites') as $e) {
            $a = (array) $e['adat'];

            foreach (SDH_Sales_Seo::lista($a['kovetelmenyek'] ?? []) as $k) {
                $kov[] = SDH_Sales::t($k);
            }

            $t = (string) ($a['tipus'] ?? '');

            if ($t === 'kovetelmeny') {
                $kov[] = SDH_Sales_Seo::nev($e) . (($l = SDH_Sales_Seo::leiras($e)) !== '' ? ' – ' . $l : '');
                continue;
            }

            if ($t === 'ellenorzolista') {
                foreach (SDH_Sales_Seo::lista($a['pontok'] ?? []) as $p) {
                    $kov[] = SDH_Sales::t($p);
                }
                continue;
            }

            if (is_array($a['lepesek'] ?? null)) {
                foreach ($a['lepesek'] as $l) {
                    if (is_array($l) && !empty($l['cim'])) {
                        $lep[] = ['nev' => SDH_Sales::t((string) $l['cim']), 'szoveg' => SDH_Sales_Seo::sima(SDH_Sales::t((string) ($l['leiras'] ?? $l['szoveg'] ?? '')))];
                    } elseif (is_string($l) && trim($l) !== '') {
                        $lep[] = ['nev' => SDH_Sales::t($l), 'szoveg' => ''];
                    }
                }
                continue;
            }

            $lep[] = ['nev' => SDH_Sales_Seo::nev($e), 'szoveg' => SDH_Sales_Seo::leiras($e)];
        }

        $kov = array_values(array_unique(array_filter($kov)));

        return $lep;
    }

    private static function korlatoz(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        $s = substr($s, 0, $max);
        $p = strrpos($s, "\n");
        $s = $p !== false ? substr($s, 0, $p) : $s;

        return rtrim(mb_convert_encoding($s, 'UTF-8', 'UTF-8')) . "\n\n" . __('(A fájl mérete korlátozott; a teljes tartalom a webhelyen érhető el.)', 'sdh-sales') . "\n";
    }

    public static function general(bool $teljes): string
    {
        SDH_Sales_Seo::urit();
        SDH_Sales_Gyik::urit();

        $u      = SDH_Sales_Seo::utvonalak();
        $nev    = SDH_Sales::termeknev();
        $szlog  = trim(SDH_Sales::t((string) SDH_Sales::b('szlogen', '')));
        $ceg    = trim((string) SDH_Sales::b('ceg.nev', ''));
        $fo     = home_url('/');
        $leiras = trim((string) get_post_meta((int) get_option('page_on_front'), '_sdh_seo_description', true));
        $leiras = SDH_Sales_Seo::sima(SDH_Sales::t($leiras));
        $o      = [];

        $o[] = '# ' . $nev;
        $o[] = '';
        $o[] = '> ' . self::md(implode(' ', array_filter([
            $szlog !== '' ? rtrim($szlog, '.') . '.' : '',
            $leiras,
            sprintf(/* translators: 1: termék neve */ __('A(z) %1$s szervizkezelő, munkalap- és ügyfélkezelő (CRM) szoftver elektronikai és telefonszervizeknek, magyar nyelven.', 'sdh-sales'), $nev),
        ])));
        $o[] = '';
        $o[] = self::md(sprintf(
            /* translators: 1: cég, 2: webhely, 3: áfa */
            __('Fejlesztő és forgalmazó: %1$s. Webhely: %2$s. Minden ár nettó, egész forintban; az ÁFA %3$d%%. A nyelv: magyar.', 'sdh-sales'),
            rtrim($ceg !== '' ? $ceg : get_bloginfo('name'), '.'),
            $fo,
            SDH_Sales::afa()
        ));

        if (!$teljes) {
            $o[] = self::md(__('A tényszerű, teljes tartalom (funkciók leírással, árak, GYIK, telepítés) egy fájlban:', 'sdh-sales')) . ' ' . home_url('/llms-full.txt');
        }

        $o[] = '';

        // Funkciók
        $f = SDH_Sales_Seo::elemek('funkcio');

        if ($f !== []) {
            $o[] = '## ' . __('Funkciók', 'sdh-sales');
            $o[] = '';

            foreach ($f as $k => $e) {
                if ($teljes) {
                    $o[] = '### ' . self::md(SDH_Sales_Seo::nev($e));
                    $o[] = '';
                    $o[] = SDH_Sales_Seo::leiras($e);
                    $a = (array) $e['adat'];

                    foreach (['elonyok', 'pontok', 'jellemzok'] as $m) {
                        foreach (SDH_Sales_Seo::lista(is_array($a[$m] ?? null) ? array_map(static fn ($x) => is_array($x) ? (string) ($x['cim'] ?? $x['szoveg'] ?? '') : $x, $a[$m]) : []) as $p) {
                            $o[] = '- ' . self::md(SDH_Sales::t($p));
                        }
                    }

                    $o[] = '';
                    $o[] = __('Részletek:', 'sdh-sales') . ' ' . SDH_Sales_Seo::url('funkcio', (string) $k, $a);
                    $o[] = '';
                } else {
                    $o[] = self::link(SDH_Sales_Seo::nev($e), SDH_Sales_Seo::url('funkcio', (string) $k, (array) $e['adat']), SDH_Sales_Seo::leiras($e, 150));
                }
            }

            $o[] = '';
        }

        // Modulok
        $m = SDH_Sales_Seo::modulok();

        if ($m !== []) {
            $o[] = '## ' . __('Modulok', 'sdh-sales');
            $o[] = '';

            foreach ($m as $x) {
                if ($teljes) {
                    $ar = [];

                    if ($x['havi'] > 0) {
                        $ar[] = self::ar($x['havi'], __('hó', 'sdh-sales'));
                    }

                    if ($x['eves'] > 0) {
                        $ar[] = self::ar($x['eves'], __('év', 'sdh-sales'));
                    }

                    $o[] = '- **' . self::md($x['nev']) . '**' . ($x['csoport'] !== '' ? ' (' . self::md($x['csoport']) . ')' : '') . ': ' . self::md($x['leiras'])
                        . ($ar !== [] ? ' ' . __('Ár:', 'sdh-sales') . ' ' . implode('; ', $ar) . '.' : '')
                        . ($x['csomagban'] !== [] ? ' ' . __('Benne van:', 'sdh-sales') . ' ' . implode(', ', array_map(static fn ($c) => (string) (SDH_Sales_Seo::elem('csomag', $c) ? SDH_Sales_Seo::nev(SDH_Sales_Seo::elem('csomag', $c)) : $c), $x['csomagban'])) . '.' : '');
                } else {
                    $o[] = self::link($x['nev'], $x['url'], SDH_Sales_Seo::rovidit($x['leiras'], 150));
                }
            }

            $o[] = '';
        }

        // Árak
        $cs = SDH_Sales_Seo::csomagok();
        $o[] = '## ' . __('Árak', 'sdh-sales');
        $o[] = '';

        if ($teljes) {
            $o[] = sprintf(/* translators: %d: áfa */ __('Minden ár nettó, egész forint; a bruttó ár %d%% ÁFÁ-val értendő. Forrás: a webhely árlistája (a rendelésnél mindig a szerver által számolt ár érvényes).', 'sdh-sales'), SDH_Sales::afa());
            $o[] = '';

            if ($cs !== []) {
                $o[] = '| ' . __('Csomag', 'sdh-sales') . ' | ' . __('Havi (nettó)', 'sdh-sales') . ' | ' . __('Havi (bruttó)', 'sdh-sales') . ' | ' . __('Éves (nettó)', 'sdh-sales') . ' | ' . __('Éves (bruttó)', 'sdh-sales') . ' | ' . __('Felhasználó', 'sdh-sales') . ' | ' . __('Telephely', 'sdh-sales') . ' |';
                $o[] = '|---|---:|---:|---:|---:|---:|---:|';

                foreach ($cs as $c) {
                    $o[] = '| ' . self::md($c['nev']) . ' | ' . SDH_Sales::ft($c['havi']) . ' | ' . SDH_Sales::ft($c['havi_brutto']) . ' | ' . SDH_Sales::ft($c['eves']) . ' | ' . SDH_Sales::ft($c['eves_brutto']) . ' | ' . $c['felhasznalo'] . ' | ' . $c['telephely'] . ' |';
                }

                $o[] = '';

                foreach ($cs as $c) {
                    if ($c['leiras'] !== '' || $c['modulok'] !== []) {
                        $o[] = '- **' . self::md($c['nev']) . '**: ' . self::md($c['leiras']) . ($c['modulok'] !== [] ? ' ' . __('Modulok:', 'sdh-sales') . ' ' . implode(', ', array_map(static fn ($k) => SDH_Sales_Seo::elem('modul', $k) ? SDH_Sales_Seo::nev(SDH_Sales_Seo::elem('modul', $k)) : $k, $c['modulok'])) . '.' : '');
                    }
                }

                $o[] = '';
            }

            $ev = (int) SDH_Sales::b('eves_kedvezmeny', 0);

            if ($ev > 0) {
                $o[] = sprintf(/* translators: %d: kedvezmény */ __('Éves fizetésnél %d%% kedvezmény.', 'sdh-sales'), $ev);
                $o[] = '';
            }

            foreach (SDH_Sales_Seo::elemek('szolgaltatas') as $e) {
                $a  = (array) $e['adat'];
                $ar = (int) ($a['ar'] ?? 0);
                $o[] = '- **' . self::md(SDH_Sales_Seo::nev($e)) . '**' . ($ar > 0 ? ': ' . self::ar($ar, (string) ($a['tipus'] ?? '') === 'havi' ? __('hó', 'sdh-sales') : __('alkalom', 'sdh-sales')) : '') . (($l = SDH_Sales_Seo::leiras($e)) !== '' ? ' – ' . self::md($l) : '');
            }

            $o[] = '';
            $o[] = __('Árak oldal:', 'sdh-sales') . ' ' . SDH_Sales_Seo::oldal_url($u['arak']);
        } else {
            $o[] = self::link(__('Árak és csomagok', 'sdh-sales'), SDH_Sales_Seo::oldal_url($u['arak']), $cs !== [] ? implode(', ', array_map(static fn ($c) => $c['nev'] . ': ' . ($c['havi'] > 0 ? SDH_Sales::ft($c['havi']) . ' + ÁFA/hó' : SDH_Sales::ft($c['eves']) . ' + ÁFA/év'), $cs)) : '');
        }

        $o[] = '';

        // Iparágak
        $ip = SDH_Sales_Seo::elemek('iparag');

        if ($ip !== []) {
            $o[] = '## ' . __('Iparágak', 'sdh-sales');
            $o[] = '';

            foreach ($ip as $k => $e) {
                $a = (array) $e['adat'];

                if ($teljes) {
                    $fk = array_map(static fn ($x) => SDH_Sales_Seo::elem('funkcio', $x) ? SDH_Sales_Seo::nev(SDH_Sales_Seo::elem('funkcio', $x)) : '', SDH_Sales_Seo::lista($a['funkciok'] ?? []));
                    $o[] = '- **' . self::md(SDH_Sales_Seo::nev($e)) . '**: ' . self::md(SDH_Sales_Seo::leiras($e)) . (array_filter($fk) !== [] ? ' ' . __('Fő funkciók:', 'sdh-sales') . ' ' . implode(', ', array_filter($fk)) . '.' : '') . ' ' . SDH_Sales_Seo::url('iparag', (string) $k, $a);
                } else {
                    $o[] = self::link(SDH_Sales_Seo::nev($e), SDH_Sales_Seo::url('iparag', (string) $k, $a), SDH_Sales_Seo::leiras($e, 150));
                }
            }

            $o[] = '';
        }

        // GYIK
        $gy = SDH_Sales_Gyik::lista_kategoriaval();

        if ($gy !== []) {
            $o[] = '## ' . __('GYIK', 'sdh-sales');
            $o[] = '';

            if ($teljes) {
                foreach ($gy as $kat) {
                    $o[] = '### ' . self::md($kat['kategoria']);
                    $o[] = '';

                    foreach ($kat['elemek'] as $g) {
                        $o[] = '**' . self::md($g['kerdes']) . '**';
                        $o[] = '';
                        $o[] = self::html_md((string) $g['valasz']);
                        $o[] = '';
                    }
                }
            } else {
                $o[] = self::link(__('Gyakori kérdések', 'sdh-sales'), SDH_Sales_Seo::oldal_url($u['gyik']), sprintf(/* translators: %d: darab */ __('%d kérdés és válasz', 'sdh-sales'), count(SDH_Sales_Gyik::lista())));

                foreach ($gy as $kat) {
                    foreach (array_slice($kat['elemek'], 0, 3) as $g) {
                        $o[] = self::link($g['kerdes'], $g['url']);
                    }
                }
            }

            $o[] = '';
        }

        // Telepítés
        $kov = [];
        $lep = self::telepites($kov);
        $o[] = '## ' . __('Telepítés', 'sdh-sales');
        $o[] = '';

        if ($teljes) {
            if ($kov !== []) {
                $o[] = '### ' . __('Követelmények', 'sdh-sales');
                $o[] = '';

                foreach ($kov as $k) {
                    $o[] = '- ' . self::md($k);
                }

                $o[] = '';
            }

            if ($lep !== []) {
                $o[] = '### ' . __('Lépések', 'sdh-sales');
                $o[] = '';

                foreach ($lep as $i => $l) {
                    $o[] = ($i + 1) . '. **' . self::md($l['nev']) . '**' . ($l['szoveg'] !== '' && $l['szoveg'] !== $l['nev'] ? ' – ' . self::md($l['szoveg']) : '');
                }

                $o[] = '';
            }
        }

        $o[] = self::link(__('Telepítési útmutató', 'sdh-sales'), SDH_Sales_Seo::oldal_url($u['telepites']), $teljes ? '' : SDH_Sales_Seo::rovidit(implode('; ', array_slice($kov, 0, 4)), 150));
        $o[] = '';

        // Cégadatok (csak a teljesben)
        if ($teljes) {
            $o[] = '## ' . __('Cégadatok', 'sdh-sales');
            $o[] = '';

            foreach ([
                __('Cégnév', 'sdh-sales')         => 'ceg.nev',
                __('Székhely', 'sdh-sales')       => 'ceg.cim',
                __('Adószám', 'sdh-sales')        => 'ceg.adoszam',
                __('Cégjegyzékszám', 'sdh-sales') => 'ceg.cegjegyzek',
                __('E-mail', 'sdh-sales')         => 'ceg.email',
                __('Telefon', 'sdh-sales')        => 'ceg.telefon',
            ] as $cim => $kulcs) {
                $v = trim((string) SDH_Sales::b($kulcs, ''));

                if ($v !== '') {
                    $o[] = '- ' . $cim . ': ' . self::md($v);
                }
            }

            $o[] = '- ' . __('Webhely', 'sdh-sales') . ': ' . home_url('/');

            foreach (SDH_Sales_Seo::lista(SDH_Sales::b('seo.sameas', [])) as $s) {
                $o[] = '- ' . __('Profil', 'sdh-sales') . ': ' . esc_url_raw($s);
            }

            $o[] = '';
        }

        // Jogi
        $jogi = self::jogi_oldalak();

        if ($jogi !== []) {
            $o[] = '## ' . __('Jogi', 'sdh-sales');
            $o[] = '';

            foreach ($jogi as $p) {
                $o[] = self::link(SDH_Sales::t($p->post_title), (string) get_permalink($p));
            }

            $o[] = '';
        }

        // Optional: blog, további oldalak
        $blog = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => $teljes ? 100 : 30, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => true]);
        $o[] = '## Optional';
        $o[] = '';

        if (!$teljes) {
            $o[] = self::link('llms-full.txt', home_url('/llms-full.txt'), __('a teljes tényszerű tartalom egy fájlban', 'sdh-sales'));
        }

        foreach ($blog as $p) {
            $l = (string) get_post_meta($p->ID, '_sdh_seo_description', true) ?: $p->post_excerpt;
            $o[] = self::link(SDH_Sales::t($p->post_title), (string) get_permalink($p), SDH_Sales_Seo::rovidit(SDH_Sales_Seo::sima(SDH_Sales::t($l)), 150));
        }

        $s = implode("\n", (array) apply_filters('sdh_sales_llms_sorok', $o, $teljes));
        $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);
        $s = trim($s) . "\n";

        $max = $teljes ? (int) SDH_Sales::b('seo.llms_full_max', 400000) : (int) SDH_Sales::b('seo.llms_max', 50000);

        return self::korlatoz($s, max(2000, $max));
    }
}
