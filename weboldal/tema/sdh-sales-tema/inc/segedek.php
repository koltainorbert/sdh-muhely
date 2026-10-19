<?php
/**
 * SDH Értékesítés téma – segédfüggvények: bővítmény-hívások védve (a téma a bővítmény nélkül is fut), tokenek,
 * URL-ek, gombok, szekció-keret, képek (assets/kepek/manifest.json → <picture>), tartalomjegyzék, olvasási idő.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

/* =====================================================================
 * Bővítmény-hidak (class_exists-szel védve)
 * ================================================================== */

/** Be van-e kapcsolva az SDH Értékesítés bővítmény. */
function sdh_tema_plugin(): bool
{
    return class_exists('SDH_Sales');
}

/** Látogatói szöveg tokencserével ({termeknev}, {ceg}…). A bővítmény nélkül a webhely neve áll a helyén. */
function sdh_tema_t(string $s): string
{
    if (class_exists('SDH_Sales')) {
        return SDH_Sales::t($s);
    }

    if (strpos($s, '{') === false) {
        return $s;
    }

    $s = strtr($s, [
        '{termeknev}'       => get_bloginfo('name'),
        '{termeknev_rovid}' => get_bloginfo('name'),
        '{ceg}'             => get_bloginfo('name'),
        '{webhely}'         => home_url('/'),
        '{ev}'              => wp_date('Y'),
        '{afa}'             => '27',
    ]);

    return (string) preg_replace('/\{[a-z_]+\}/', '', $s);
}

/** Fordítható + tokenezett szöveg a témából. */
function sdh_tema__(string $s): string
{
    // A szöveg fordítás után megy a tokencserén át (a fordítás is tartalmazhat tokent).
    return sdh_tema_t(translate($s, 'sdh-sales-tema')); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
}

function sdh_tema_termeknev(): string
{
    return class_exists('SDH_Sales') ? SDH_Sales::termeknev() : get_bloginfo('name');
}

/**
 * @param mixed $alap
 * @return mixed
 */
function sdh_tema_b(string $kulcs, $alap = null)
{
    return class_exists('SDH_Sales') ? SDH_Sales::b($kulcs, $alap) : $alap;
}

function sdh_tema_afa(): int
{
    return class_exists('SDH_Sales') ? SDH_Sales::afa() : 27;
}

function sdh_tema_brutto(int $netto): int
{
    return class_exists('SDH_Sales') ? SDH_Sales::brutto($netto) : (int) round($netto * (100 + sdh_tema_afa()) / 100);
}

function sdh_tema_ft(int $osszeg): string
{
    return class_exists('SDH_Sales') ? SDH_Sales::ft($osszeg) : number_format($osszeg, 0, ',', "\u{00A0}") . "\u{00A0}Ft";
}

/**
 * A tartalom-tár egy típusa (csomag, modul, gyik, funkcio, iparag, konkurens…). Bővítmény nélkül üres.
 *
 * @return array<int, array<string, mixed>>
 */
function sdh_tema_lista(string $tipus): array
{
    if (!class_exists('SDH_Sales_Tartalom') || !class_exists('SDH_Sales_Schema')) {
        return [];
    }

    try {
        return SDH_Sales_Tartalom::lista($tipus);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Menü: a bővítmény tartalom-tárából; bővítmény nélkül a WP menühely (fo, lablec, jogi), ha van.
 *
 * @return array<int, array{cim: string, url: string, gyerekek: array}>
 */
function sdh_tema_menu(string $hely): array
{
    static $cache = [];

    if (isset($cache[$hely])) {
        return $cache[$hely];
    }

    $ki = [];

    if (class_exists('SDH_Sales_Tartalom') && class_exists('SDH_Sales_Schema')) {
        try {
            $ki = SDH_Sales_Tartalom::menu($hely);
        } catch (Throwable $e) {
            $ki = [];
        }
    }

    if ($ki === []) {
        $helyek = get_nav_menu_locations();

        if (!empty($helyek[$hely])) {
            $elemek = (array) wp_get_nav_menu_items((int) $helyek[$hely]);
            $fa     = [];
            $ref    = [];

            foreach ($elemek as $e) {
                $ref[(int) $e->ID] = ['cim' => (string) $e->title, 'url' => (string) $e->url, 'gyerekek' => []];
            }

            foreach ($elemek as $e) {
                $sz = (int) $e->menu_item_parent;

                if ($sz > 0 && isset($ref[$sz])) {
                    $ref[$sz]['gyerekek'][] = &$ref[(int) $e->ID];
                } else {
                    $fa[] = &$ref[(int) $e->ID];
                }
            }

            $ki = json_decode((string) wp_json_encode($fa), true) ?: [];
        }
    }

    // Csak az áll a menüben, amit meg is lehet nyitni: üres URL-ű levélelem kimarad.
    $szur = static function (array $elemek) use (&$szur): array {
        $o = [];

        foreach ($elemek as $e) {
            if (!is_array($e) || trim((string) ($e['cim'] ?? '')) === '') {
                continue;
            }

            $e['gyerekek'] = $szur((array) ($e['gyerekek'] ?? []));

            if (trim((string) ($e['url'] ?? '')) === '' && $e['gyerekek'] === []) {
                continue;
            }

            $o[] = $e;
        }

        return $o;
    };

    return $cache[$hely] = $szur($ki);
}

/* =====================================================================
 * URL-ek
 * ================================================================== */

/** Szekcióadatból jövő URL: „/út” → home_url, „#horgony”, http(s), mailto:, tel:; különben oldal-útvonal. */
function sdh_tema_url(string $u): string
{
    $u = trim(sdh_tema_t($u));

    if ($u === '') {
        return '';
    }

    if ($u[0] === '#' || preg_match('#^(https?:|mailto:|tel:)#i', $u) === 1) {
        return $u;
    }

    // Más séma (javascript:, data:, vbscript: …) soha nem lesz link.
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $u) === 1 || strpos($u, '//') === 0) {
        return '';
    }

    if ($u[0] === '/') {
        return home_url($u);
    }

    return home_url('/' . trim($u, '/') . '/');
}

/** Egy létező oldal URL-je útvonal alapján („arak”, „funkciok/munkalap”), vagy '' ha nincs ilyen oldal. */
function sdh_tema_oldal_url(string $ut): string
{
    static $cache = [];

    if (!array_key_exists($ut, $cache)) {
        $p           = get_page_by_path(trim($ut, '/'));
        $cache[$ut] = ($p instanceof WP_Post && $p->post_status === 'publish') ? (string) get_permalink($p) : '';
    }

    return $cache[$ut];
}

/**
 * Egy szülőoldal közzétett gyermekei: [slug => url] (egy lekérdezés a rácsokhoz).
 *
 * @return array<string, string>
 */
function sdh_tema_aloldalak(string $szulo_ut): array
{
    static $cache = [];

    if (isset($cache[$szulo_ut])) {
        return $cache[$szulo_ut];
    }

    $ki    = [];
    $szulo = get_page_by_path(trim($szulo_ut, '/'));

    if ($szulo instanceof WP_Post) {
        $gy = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'post_parent' => (int) $szulo->ID, 'numberposts' => 300, 'orderby' => 'menu_order title', 'order' => 'ASC']);

        foreach ($gy as $p) {
            $ki[(string) $p->post_name] = (string) get_permalink($p);
        }
    }

    return $cache[$szulo_ut] = $ki;
}

/** Egy tartalom-elem (funkció, iparág) aloldalának URL-je: adat.url → aloldal → ''. @param array<string, mixed> $elem */
function sdh_tema_elem_url(array $elem, string $szulo_ut): string
{
    $a = (array) ($elem['adat'] ?? []);

    if (!empty($a['url'])) {
        return sdh_tema_url((string) $a['url']);
    }

    $k  = (string) ($elem['kulcs'] ?? '');
    $al = sdh_tema_aloldalak($szulo_ut);

    return $al[$k] ?? ($al[sanitize_title($k)] ?? '');
}

/* =====================================================================
 * Kimeneti segédek
 * ================================================================== */

/** Biztonságos HTML-részlet (tokenekkel). */
function sdh_tema_html(string $html): string
{
    return wp_kses_post(sdh_tema_t($html));
}

/** Szöveg, ami lehet HTML (p/strong/a…) vagy sima szöveg – mindkét esetben biztonságos. */
function sdh_tema_szoveg(string $s): string
{
    $s = trim($s);

    if ($s === '') {
        return '';
    }

    if ($s !== wp_strip_all_tags($s)) {
        return sdh_tema_html($s);
    }

    return '<p>' . nl2br(esc_html(sdh_tema_t($s))) . '</p>';
}

/** JSON adat-attribútumba (escapelve). @param mixed $adat */
function sdh_tema_json_attr($adat): string
{
    return esc_attr((string) wp_json_encode($adat, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
}

/** Egy szekció-mező szövegként (tömb / szám esetén is biztonságos). @param array<string, mixed> $sz */
function sdh_tema_mezo(array $sz, string $kulcs, string $alap = ''): string
{
    $v = $sz[$kulcs] ?? $alap;

    return is_scalar($v) ? trim((string) $v) : $alap;
}

/** Egy szekció-mező listaként (csak a tömb-elemek). @param array<string, mixed> $sz @return array<int, array<string, mixed>> */
function sdh_tema_elemek(array $sz, string $kulcs = 'elemek'): array
{
    $v = $sz[$kulcs] ?? [];

    return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
}

/** Szöveges lista (kulcsok, pillek). @param array<string, mixed> $sz @return array<int, string> */
function sdh_tema_szovegek(array $sz, string $kulcs): array
{
    $v = $sz[$kulcs] ?? [];

    if (is_string($v)) {
        $v = array_map('trim', explode(',', $v));
    }

    return is_array($v) ? array_values(array_filter(array_map(static fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v), 'strlen')) : [];
}

/**
 * CTA-gombok: [{szoveg, url, stilus: elsodleges|masodlagos|szellem|link}]. Üres URL vagy szöveg kimarad.
 *
 * @param mixed $cta
 */
function sdh_tema_gombok($cta, string $osztaly = ''): string
{
    if (!is_array($cta)) {
        return '';
    }

    if (isset($cta['szoveg'])) {
        $cta = [$cta];
    }

    $ki = '';
    $i  = 0;

    foreach ($cta as $g) {
        if (!is_array($g)) {
            continue;
        }

        $sz  = trim(sdh_tema_t((string) ($g['szoveg'] ?? '')));
        $url = sdh_tema_url((string) ($g['url'] ?? ''));

        if ($sz === '' || $url === '') {
            continue;
        }

        $st = (string) ($g['stilus'] ?? ($i === 0 ? 'elsodleges' : 'masodlagos'));
        $st = in_array($st, ['elsodleges', 'masodlagos', 'szellem', 'link', 'neon'], true) ? $st : 'masodlagos';
        $uj  = !empty($g['uj']);
        $ki .= '<a class="s-gomb s-gomb--' . esc_attr($st) . '" href="' . esc_url($url) . '"' . ($uj ? ' target="_blank" rel="noopener"' : '') . '>' . esc_html($sz) . ($st === 'elsodleges' || $st === 'link' ? sdh_tema_ikon('nyil-jobb', 's-gomb__ikon') : '') . '</a>';
        $i++;
    }

    return $ki === '' ? '' : '<div class="s-gombsor ' . esc_attr($osztaly) . '">' . $ki . '</div>';
}

/** Egyedi azonosító egy oldalon belül. */
function sdh_tema_azon(string $elotag): string
{
    static $n = 0;
    $n++;

    return sanitize_html_class($elotag) . '-' . $n;
}

/* =====================================================================
 * Szekció-keret
 * ================================================================== */

/** @var array<int, string> a nyitott szekciók stackje (címazonosító) */
$GLOBALS['sdh_tema_szekcio_verem'] = [];

/** Van-e már H1 az oldalon (az első hero viszi el). */
function sdh_tema_h1(?bool $beallit = null): bool
{
    static $van = false;

    if ($beallit !== null) {
        $van = $beallit;
    }

    return $van;
}

/**
 * Szekció nyitása: <section id aria-labelledby class> + belső konténer + fejléc (eyebrow, cím, alcím).
 *
 * @param array<string, mixed> $sz
 * @param array{fej?: bool, szint?: string, szelesseg?: string, osztaly?: string, igazitas?: string} $o
 */
function sdh_tema_szekcio_nyit(array $sz, array $o = []): string
{
    $tipus = sanitize_html_class((string) ($sz['tipus'] ?? 'szekcio'));
    $id    = sdh_tema_szekcio_id($sz);
    $hatt  = sdh_tema_hatter($sz);
    $cim   = sdh_tema_mezo($sz, 'cim');
    $cimid = $id . '-cim';
    $szint = $o['szint'] ?? 'h2';
    $szel  = $o['szelesseg'] ?? (string) ($sz['szelesseg'] ?? '');
    $igaz  = $o['igazitas'] ?? (string) ($sz['igazitas'] ?? '');
    $terk  = (string) ($sz['terkoz'] ?? '');

    $GLOBALS['sdh_tema_szekcio_verem'][] = $cimid;

    $osztaly = 's-szekcio s-szekcio--' . $tipus . ' s-hatter--' . $hatt
        . (in_array($terk, ['kicsi', 'nagy'], true) ? ' s-terkoz--' . $terk : '')
        . (!empty($o['osztaly']) ? ' ' . $o['osztaly'] : '');
    $ki = '<section id="' . esc_attr($id) . '" class="' . esc_attr($osztaly) . '"' . ($cim !== '' ? ' aria-labelledby="' . esc_attr($cimid) . '"' : '') . '>';
    $ki .= '<div class="s-kont' . (in_array($szel, ['szuk', 'keskeny'], true) ? ' s-kont--szuk' : ($szel === 'szeles' ? ' s-kont--szeles' : '')) . '">';

    if (($o['fej'] ?? true) && ($cim !== '' || sdh_tema_eyebrow($sz) !== '' || sdh_tema_mezo($sz, 'alcim') !== '')) {
        $ki .= sdh_tema_szekcio_fej($sz, $cimid, $szint, $igaz);
    }

    return $ki;
}

/** A szekció horgonya: `horgony` (admin) vagy `id`; különben sorszám + típus. @param array<string, mixed> $sz */
function sdh_tema_szekcio_id(array $sz): string
{
    $id = sanitize_title(ltrim(sdh_tema_mezo($sz, 'horgony', sdh_tema_mezo($sz, 'id')), '#'));

    return $id !== '' ? $id : 'sz-' . (int) ($sz['_i'] ?? 0) . '-' . sanitize_html_class((string) ($sz['tipus'] ?? 'szekcio'));
}

/** Háttér-változat: alap | felulet | uveg | kiemelt | sotet | atmenet. @param array<string, mixed> $sz */
function sdh_tema_hatter(array $sz): string
{
    $h = sdh_tema_mezo($sz, 'hatter', 'alap');

    return in_array($h, ['alap', 'felulet', 'uveg', 'kiemelt', 'sotet', 'atmenet'], true) ? $h : 'alap';
}

/** Kis felirat a cím fölött: `eyebrow` vagy `cimke` (admin). @param array<string, mixed> $sz */
function sdh_tema_eyebrow(array $sz): string
{
    return sdh_tema_mezo($sz, 'eyebrow', sdh_tema_mezo($sz, 'cimke'));
}

/**
 * A szekció gombjai egy listában: `cta` [{szoveg, url, stilus}] és/vagy az admin `gomb` / `gomb2` link-mezői.
 *
 * @param array<string, mixed> $sz
 * @return array<int, array<string, string>>
 */
function sdh_tema_cta(array $sz): array
{
    $ki  = [];
    $cta = $sz['cta'] ?? [];

    if (is_array($cta) && isset($cta['szoveg'])) {
        $cta = [$cta];
    }

    foreach (is_array($cta) ? $cta : [] as $g) {
        if (is_array($g)) {
            $ki[] = $g;
        }
    }

    foreach (['gomb' => 'elsodleges', 'gomb2' => 'masodlagos'] as $k => $st) {
        $g = $sz[$k] ?? null;

        if (is_array($g) && trim((string) ($g['szoveg'] ?? '')) !== '' && trim((string) ($g['url'] ?? '')) !== '') {
            $ki[] = ['szoveg' => (string) $g['szoveg'], 'url' => (string) $g['url'], 'stilus' => (string) ($g['stilus'] ?? ($ki === [] ? 'elsodleges' : $st)), 'uj' => !empty($g['uj'])];
        }
    }

    return $ki;
}

/** @param array<string, mixed> $sz */
function sdh_tema_szekcio_fej(array $sz, string $cimid, string $szint = 'h2', string $igazitas = ''): string
{
    $szint = in_array($szint, ['h1', 'h2', 'h3'], true) ? $szint : 'h2';
    $ki    = '<header class="s-szekcio__fej' . ($igazitas === 'bal' ? ' s-szekcio__fej--bal' : '') . '">';
    $eb    = sdh_tema_eyebrow($sz);
    $cim   = sdh_tema_mezo($sz, 'cim');
    $alcim = sdh_tema_mezo($sz, 'alcim');

    if ($eb !== '') {
        $ki .= '<p class="s-eyebrow">' . esc_html(sdh_tema_t($eb)) . '</p>';
    }

    if ($cim !== '') {
        $ki .= '<' . $szint . ' id="' . esc_attr($cimid) . '" class="s-szekcio__cim">' . esc_html(sdh_tema_t($cim)) . '</' . $szint . '>';
    }

    if ($alcim !== '') {
        $ki .= '<p class="s-szekcio__alcim">' . esc_html(sdh_tema_t($alcim)) . '</p>';
    }

    return $ki . '</header>';
}

/** Szekció zárása (+ a szekció CTA-gombjai, ha a sablon nem rajzolta ki maga). @param array<string, mixed> $sz */
function sdh_tema_szekcio_zar(array $sz = [], bool $cta = true): string
{
    array_pop($GLOBALS['sdh_tema_szekcio_verem']);
    $g = $cta ? sdh_tema_gombok(sdh_tema_cta($sz), 's-gombsor--kozep') : '';

    return $g . '</div></section>';
}

/**
 * Interaktív rész csatolási pontja: <div data-modul="nev" data-opciok='{…}'> + a bővítmény JS-ének igénylése.
 * A szekció közös mezői nem kerülnek az opciókba.
 *
 * @param array<string, mixed> $sz
 * @param array<string, string> $attr további data-* attribútumok (kulcs: a „data-” utáni rész)
 */
function sdh_tema_mount(string $nev, array $sz = [], array $attr = [], string $tartalek = ''): string
{
    if (class_exists('SDH_Sales_Front')) {
        SDH_Sales_Front::igeny($nev);
    }

    $opc = array_diff_key($sz, array_flip(['tipus', 'id', 'horgony', 'cim', 'alcim', 'eyebrow', 'cimke', 'hatter', 'terkoz', 'cta', 'rejtett', '_i', 'szelesseg', 'igazitas']));
    $ki  = '<div class="s-mount s-mount--' . esc_attr($nev) . '" data-modul="' . esc_attr($nev) . '"';

    if ($opc !== []) {
        $ki .= ' data-opciok="' . sdh_tema_json_attr(class_exists('SDH_Sales') ? SDH_Sales::t_tomb($opc) : $opc) . '"';
    }

    foreach ($attr as $k => $v) {
        $k = sanitize_key((string) $k);

        if ($k === '' || $k === 'modul' || $k === 'opciok') {
            continue;
        }

        $ki .= ' data-' . esc_attr($k) . '="' . esc_attr((string) $v) . '"';
    }

    $ki .= '>';
    $ki .= '<div class="s-mount__tolt" aria-hidden="true"><span class="s-csontvaz"></span><span class="s-csontvaz"></span><span class="s-csontvaz s-csontvaz--rovid"></span></div>';

    if ($tartalek !== '') {
        $ki .= '<noscript><div class="s-doboz s-doboz--figyel">' . $tartalek . '</div></noscript>';
    }

    return $ki . '</div>';
}

/** Alap noscript-szöveg az interaktív részekhez (céges e-mail, ha van). */
function sdh_tema_noscript(): string
{
    $email = (string) sdh_tema_b('ceg.email', '');
    $sz    = esc_html(sdh_tema__('Ehhez a részhez JavaScript szükséges.'));

    if ($email !== '' && is_email($email)) {
        $sz .= ' ' . esc_html(sdh_tema__('Írj nekünk:')) . ' <a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
    }

    return '<p>' . $sz . '</p>';
}

/** Csak adminnak / szerkesztőnek látható HTML-megjegyzés (fejlesztői jelzés). */
function sdh_tema_admin_megjegyzes(string $s): string
{
    if (!is_user_logged_in() || !current_user_can('edit_pages')) {
        return '';
    }

    return '<!-- szekcio: ' . esc_html(str_replace('--', '–', $s)) . ' -->';
}

/* =====================================================================
 * Képek: assets/kepek/manifest.json → <picture> (AVIF + WebP srcset, width/height, lazy)
 * ================================================================== */

/**
 * A manifest (tűrő olvasás). Elfogadott alakok egy kulcsra:
 *   {"alt":"…","w":1600,"h":900,"avif":[{"w":480,"src":"hero-480.avif"},…],"webp":[…],"tartalek":"hero.jpg"}
 *   {"alt":"…","szelesseg":…,"magassag":…,"valtozatok":{"avif":{"480":"hero-480.avif"},"webp":{…}},"src":"…"}
 *   {"alt":"…","width":…,"height":…,"srcset":{"avif":"a-480.avif 480w, …","webp":"…"},"src":"…"}
 *   {"alt":"…","fajlok":[{"fajl":"hero-480.avif","w":480,"tipus":"avif"},…]}
 * A fájlnevek az assets/kepek/-hez képest relatívak (vagy abszolút URL-ek).
 *
 * @return array<string, array<string, mixed>>
 */
function sdh_tema_manifest(): array
{
    static $m = null;

    if ($m !== null) {
        return $m;
    }

    $f = SDH_TEMA_DIR . '/assets/kepek/manifest.json';
    $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;

    if (is_array($j) && isset($j['kepek']) && is_array($j['kepek'])) {
        $j = $j['kepek'];
    }

    $m = [];

    if (is_array($j)) {
        foreach ($j as $k => $v) {
            if (is_array($v)) {
                $kulcs     = is_string($k) ? $k : (string) ($v['kulcs'] ?? '');
                $m[$kulcs] = $v;
            }
        }
    }

    return $m;
}

/** @return array{0: array<string, array<int, array{0: int, 1: string}>>, 1: string, 2: int, 3: int, 4: string} formátum → [[w, url]], tartalék, w, h, alt */
function sdh_tema_kep_adat(string $kulcs): array
{
    $e    = sdh_tema_manifest()[$kulcs] ?? null;
    $alap = trailingslashit(SDH_TEMA_URL . '/assets/kepek');

    if (!is_array($e)) {
        return [[], '', 0, 0, ''];
    }

    $url = static function (string $f) use ($alap): string {
        return preg_match('#^(https?:)?//#', $f) ? $f : $alap . ltrim($f, '/');
    };

    $fmt = [];
    $add = static function (string $tipus, int $w, string $f) use (&$fmt, $url): void {
        $tipus = strtolower($tipus === 'jpeg' ? 'jpg' : $tipus);

        if ($f !== '') {
            $fmt[$tipus][] = [$w, $url($f)];
        }
    };

    foreach (['avif', 'webp', 'jpg', 'jpeg', 'png'] as $t) {
        $lista = $e[$t] ?? ($e['valtozatok'][$t] ?? ($e['formatumok'][$t] ?? null));

        if (is_array($lista)) {
            foreach ($lista as $kk => $vv) {
                if (is_array($vv)) {
                    $add($t, (int) ($vv['w'] ?? $vv['szelesseg'] ?? $vv['width'] ?? 0), (string) ($vv['src'] ?? $vv['fajl'] ?? $vv['url'] ?? ''));
                } elseif (is_string($vv)) {
                    // Lista ([fájl, …]) a `szelessegek` tömbbel párban, vagy {szélesség: fájl}; végső esetben a fájlnévből (-480.avif).
                    $sz = is_int($kk) && isset($e['szelessegek'][$kk]) ? (int) $e['szelessegek'][$kk] : (is_string($kk) ? (int) $kk : 0);

                    if ($sz === 0 && preg_match('/-(\d{2,5})\.[a-z0-9]+$/i', $vv, $mm)) {
                        $sz = (int) $mm[1];
                    }

                    $add($t, $sz, $vv);
                }
            }
        }

        $ss = $e['srcset'][$t] ?? null;

        if (is_string($ss)) {
            foreach (array_filter(array_map('trim', explode(',', $ss))) as $d) {
                $p = preg_split('/\s+/', $d);
                $add($t, (int) rtrim((string) ($p[1] ?? '0'), 'w'), (string) $p[0]);
            }
        }
    }

    foreach ((array) ($e['fajlok'] ?? []) as $vv) {
        if (is_array($vv)) {
            $f = (string) ($vv['fajl'] ?? $vv['src'] ?? '');
            $add((string) ($vv['tipus'] ?? pathinfo($f, PATHINFO_EXTENSION)), (int) ($vv['w'] ?? 0), $f);
        }
    }

    foreach ($fmt as $t => $l) {
        usort($l, static fn ($a, $b) => $a[0] <=> $b[0]);
        $fmt[$t] = $l;
    }

    $w   = (int) ($e['w'] ?? $e['szelesseg'] ?? $e['width'] ?? 0);
    $h   = (int) ($e['h'] ?? $e['magassag'] ?? $e['height'] ?? 0);
    $src = (string) ($e['tartalek'] ?? $e['src'] ?? $e['fallback'] ?? '');
    $src = $src !== '' ? $url($src) : '';

    if ($src === '') {
        foreach (['jpg', 'png', 'webp', 'avif'] as $t) {
            if (!empty($fmt[$t])) {
                $src = (string) end($fmt[$t])[1];
                break;
            }
        }
    }

    if ($w === 0) {
        foreach ($fmt as $l) {
            $w = max($w, (int) end($l)[0]);
        }
    }

    return [$fmt, $src, $w, $h, sdh_tema_t((string) ($e['alt'] ?? ''))];
}

/** Van-e ilyen kép a manifestben. */
function sdh_tema_van_kep(string $kulcs): bool
{
    return $kulcs !== '' && isset(sdh_tema_manifest()[$kulcs]);
}

/**
 * <picture> a manifestből. $meret: 'hero' | 'teljes' | 'fel' | 'kartya' | 'ikon' vagy egy CSS `sizes` érték.
 * $attrs: alt, class, loading, fetchpriority, decoding, id… A hero (vagy fetchpriority=high) nem lusta.
 *
 * @param array<string, string|int|bool> $attrs
 */
function sdh_tema_kep(string $kulcs, string $meret = 'kartya', array $attrs = []): string
{
    [$fmt, $src, $w, $h, $alt] = sdh_tema_kep_adat($kulcs);

    if ($src === '' && $fmt === []) {
        return sdh_tema_admin_megjegyzes('hiányzó kép: ' . $kulcs);
    }

    $sizes = [
        'hero'   => '(min-width: 1100px) 640px, (min-width: 768px) 50vw, 100vw',
        'teljes' => '100vw',
        'fel'    => '(min-width: 900px) 50vw, 100vw',
        'kartya' => '(min-width: 1100px) 380px, (min-width: 640px) 50vw, 100vw',
        'ikon'   => '96px',
    ][$meret] ?? $meret;

    $hero = $meret === 'hero' || ($attrs['fetchpriority'] ?? '') === 'high';
    $a    = $attrs + [
        'alt'      => $alt,
        'loading'  => $hero ? 'eager' : 'lazy',
        'decoding' => $hero ? 'sync' : 'async',
    ];

    if ($hero) {
        $a['fetchpriority'] = 'high';
        unset($a['loading']);
    }

    $srcset = static fn (array $l): string => implode(', ', array_map(static fn ($x) => esc_url($x[1]) . ($x[0] > 0 ? ' ' . $x[0] . 'w' : ''), $l));

    $ki = '<picture>';

    foreach (['avif' => 'image/avif', 'webp' => 'image/webp'] as $t => $mime) {
        if (!empty($fmt[$t])) {
            $ki .= '<source type="' . $mime . '" srcset="' . $srcset($fmt[$t]) . '" sizes="' . esc_attr($sizes) . '">';
        }
    }

    $img = '<img src="' . esc_url($src) . '"';

    foreach (['jpg', 'png'] as $t) {
        if (!empty($fmt[$t]) && count($fmt[$t]) > 1) {
            $img .= ' srcset="' . $srcset($fmt[$t]) . '" sizes="' . esc_attr($sizes) . '"';
            break;
        }
    }

    if ($w > 0 && $h > 0) {
        $img .= ' width="' . $w . '" height="' . $h . '"';
    }

    foreach ($a as $k => $v) {
        if ($v === false || $v === null) {
            continue;
        }

        $img .= ' ' . esc_attr((string) $k) . '="' . esc_attr((string) $v) . '"';
    }

    return $ki . $img . '></picture>';
}

/* =====================================================================
 * Blog: olvasási idő, tartalomjegyzék
 * ================================================================== */

/** Becsült olvasási idő percben (200 szó / perc). */
function sdh_tema_olvasasi_ido(string $html): int
{
    $szavak = preg_split('/\s+/u', trim(wp_strip_all_tags($html)), -1, PREG_SPLIT_NO_EMPTY);

    return max(1, (int) ceil(count($szavak ?: []) / 200));
}

/**
 * h2/h3 címsorok azonosítóval + tartalomjegyzék.
 *
 * @return array{0: string, 1: array<int, array{id: string, cim: string, szint: int}>}
 */
function sdh_tema_tartalomjegyzek(string $html): array
{
    $tj   = [];
    $lato = [];

    $html = (string) preg_replace_callback('#<h([23])([^>]*)>(.*?)</h\1>#is', static function (array $m) use (&$tj, &$lato): string {
        $attr = $m[2];
        $cim  = trim(html_entity_decode(wp_strip_all_tags($m[3]), ENT_QUOTES, 'UTF-8'));

        if (preg_match('/\sid=("|\')([^"\']+)\1/', $attr, $im)) {
            $id = $im[2];
        } else {
            $id   = sanitize_title($cim) ?: 'resz';
            $attr .= ' id="' . esc_attr($id) . '"';
        }

        $alap = $id;
        $n    = 2;

        while (isset($lato[$id])) {
            $id = $alap . '-' . $n++;
            $attr = (string) preg_replace('/\sid=("|\')[^"\']+\1/', ' id="' . esc_attr($id) . '"', $attr);
        }

        $lato[$id] = true;

        if ($cim !== '') {
            $tj[] = ['id' => $id, 'cim' => $cim, 'szint' => (int) $m[1]];
        }

        return '<h' . $m[1] . $attr . '>' . $m[3] . '</h' . $m[1] . '>';
    }, $html);

    return [$html, $tj];
}

/** @param array<int, array{id: string, cim: string, szint: int}> $tj */
function sdh_tema_tj_html(array $tj, string $cim = ''): string
{
    if (count($tj) < 2) {
        return '';
    }

    $ki = '<nav class="s-tj" aria-label="' . esc_attr(sdh_tema__('Tartalomjegyzék')) . '"><details class="s-tj__doboz" open><summary class="s-tj__cim">' . esc_html($cim !== '' ? $cim : sdh_tema__('Tartalomjegyzék')) . '</summary><ol class="s-tj__lista">';

    foreach ($tj as $e) {
        $ki .= '<li class="s-tj__elem s-tj__elem--' . (int) $e['szint'] . '"><a href="#' . esc_attr($e['id']) . '">' . esc_html($e['cim']) . '</a></li>';
    }

    return $ki . '</ol></details></nav>';
}

/** Morzsamenü (vizuális; a sémát a Rank Math / a bővítmény adja). @param array<int, array{0: string, 1: string}> $ut */
function sdh_tema_morzsa(array $ut): string
{
    $ki = '<nav class="s-morzsa" aria-label="' . esc_attr(sdh_tema__('Morzsamenü')) . '"><ol>';
    $ki .= '<li><a href="' . esc_url(home_url('/')) . '">' . esc_html(sdh_tema__('Kezdőlap')) . '</a></li>';
    $n  = count($ut);

    foreach ($ut as $i => [$cim, $url]) {
        $ki .= $i === $n - 1 || $url === ''
            ? '<li><span aria-current="page">' . esc_html($cim) . '</span></li>'
            : '<li><a href="' . esc_url($url) . '">' . esc_html($cim) . '</a></li>';
    }

    return $ki . '</ol></nav>';
}

/** Cégadat a láblécben / entitás-dobozban. @return array<string, string> */
function sdh_tema_ceg(): array
{
    $c = [];

    foreach (['nev', 'cim', 'email', 'telefon', 'adoszam', 'cegjegyzek'] as $k) {
        $c[$k] = trim((string) sdh_tema_b('ceg.' . $k, ''));
    }

    if ($c['nev'] === '') {
        $c['nev'] = get_bloginfo('name');
    }

    return $c;
}

/**
 * Képmező kirajzolása: manifest-kulcs („hero”), médiatár-azonosító (szám) vagy URL. Üres → ''.
 *
 * @param mixed                          $ertek
 * @param array<string, string|int|bool> $attrs
 */
function sdh_tema_kep_mezo($ertek, string $meret = 'kartya', array $attrs = [], string $alt = ''): string
{
    if (is_array($ertek)) {
        $ertek = $ertek['url'] ?? ($ertek['id'] ?? '');
    }

    $ertek = is_scalar($ertek) ? trim((string) $ertek) : '';

    if ($ertek === '') {
        return '';
    }

    if ($alt !== '') {
        $attrs['alt'] = sdh_tema_t($alt);
    }

    if (sdh_tema_van_kep($ertek)) {
        return sdh_tema_kep($ertek, $meret, $attrs);
    }

    $hero = $meret === 'hero' || ($attrs['fetchpriority'] ?? '') === 'high';

    if (ctype_digit($ertek)) {
        $a = ['alt' => (string) ($attrs['alt'] ?? ''), 'class' => (string) ($attrs['class'] ?? ''), 'decoding' => $hero ? 'sync' : 'async'];
        $a += $hero ? ['fetchpriority' => 'high', 'loading' => 'eager'] : ['loading' => 'lazy'];

        return (string) wp_get_attachment_image((int) $ertek, $hero ? 'full' : 'large', false, $a);
    }

    $url = sdh_tema_url($ertek);

    if ($url === '' || preg_match('#^(https?:)?//#i', $url) !== 1) {
        return '';
    }

    $img = '<img src="' . esc_url($url) . '" alt="' . esc_attr((string) ($attrs['alt'] ?? '')) . '"';

    // Saját médiatárbeli kép: méret a WP-ből (CLS ellen).
    $id = attachment_url_to_postid($url);

    if ($id > 0) {
        $m = wp_get_attachment_metadata($id);

        if (is_array($m) && !empty($m['width']) && !empty($m['height'])) {
            $img .= ' width="' . (int) $m['width'] . '" height="' . (int) $m['height'] . '"';
        }
    }

    foreach (['class', 'width', 'height'] as $k) {
        if (isset($attrs[$k]) && strpos($img, ' ' . $k . '=') === false) {
            $img .= ' ' . $k . '="' . esc_attr((string) $attrs[$k]) . '"';
        }
    }

    $img .= $hero ? ' fetchpriority="high" decoding="sync"' : ' loading="lazy" decoding="async"';

    return $img . '>';
}

/** Ár-/dátum-független rövid dátum: „2026. október 19.” */
function sdh_tema_datum(int $ts, string $forma = 'Y. F j.'): string
{
    return (string) wp_date($forma, $ts);
}

/**
 * Morzsamenü az aktuális oldalhoz: a bővítmény SEO-moduljából (SDH_Sales_Seo::morzsa() – ugyanebből lesz a
 * BreadcrumbList), különben a WP-szülőkből. Kezdőlapon üres.
 */
function sdh_tema_morzsa_auto(): string
{
    if (is_front_page()) {
        return '';
    }

    $m = [];

    if (class_exists('SDH_Sales_Seo')) {
        try {
            $m = SDH_Sales_Seo::morzsa();
        } catch (Throwable $e) {
            $m = [];
        }
    } elseif (function_exists('sdh_tema_tudas_e') && sdh_tema_tudas_e() && sdh_tema_tudas_fejezet() !== null) {
        $m = sdh_tema_tudas_morzsa((array) sdh_tema_tudas_fejezet());
    } else {
        $m[] = ['cim' => '', 'url' => home_url('/')];

        if (is_singular()) {
            $id = (int) get_queried_object_id();

            if (is_singular('post') && (int) get_option('page_for_posts') > 0) {
                $b   = (int) get_option('page_for_posts');
                $m[] = ['cim' => get_the_title($b), 'url' => (string) get_permalink($b)];
            }

            foreach (array_reverse(get_post_ancestors($id)) as $a) {
                $m[] = ['cim' => get_the_title($a), 'url' => (string) get_permalink($a)];
            }

            $m[] = ['cim' => get_the_title($id), 'url' => (string) get_permalink($id)];
        } elseif (is_search()) {
            $m[] = ['cim' => sprintf(/* translators: %s: keresett szöveg */ sdh_tema__('Keresés: „%s”'), get_search_query(false)), 'url' => ''];
        } elseif (is_archive()) {
            $m[] = ['cim' => wp_strip_all_tags(get_the_archive_title()), 'url' => ''];
        }
    }

    if (count($m) < 2) {
        return '';
    }

    $ut = [];

    foreach (array_slice($m, 1) as $e) {
        $ut[] = [sdh_tema_t(wp_strip_all_tags((string) ($e['cim'] ?? ''))), (string) ($e['url'] ?? '')];
    }

    return sdh_tema_morzsa($ut);
}

/** Oldalfej (cím + morzsa) a szekció nélküli oldalakhoz. */
function sdh_tema_oldalfej(string $cim, string $alcim = '', string $extra = ''): string
{
    sdh_tema_h1(true);

    return '<header class="s-oldalfej"><div class="s-kont">' . sdh_tema_morzsa_auto()
        . '<h1 class="s-oldalfej__cim">' . esc_html($cim) . '</h1>'
        . ($alcim !== '' ? '<p class="s-oldalfej__alcim">' . esc_html($alcim) . '</p>' : '')
        . $extra . '</div></header>';
}
