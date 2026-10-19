<?php
/**
 * SDH Értékesítés téma – alapok: theme supports, fej-takarítás, CSS (kritikus a fejben, a többi nem blokkol),
 * JS (defer), önkiszolgált betűk + előtöltés, sötét / világos mód festés előtt.
 *
 * CSS-építés: `php bin/epit.php` → assets/dist/kritikus.min.css (tokenek + kritikus; a fejbe ágyazva) és
 * assets/dist/tema.min.css (minden más komponens; nem blokkoló). Ha a dist hiányzik vagy régebbi a forrásnál,
 * a téma a forrásfájlokat használja (lassabb, de működik).
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

/** A nem kritikus CSS forrásai, betöltési sorrendben (bin/epit.php is ezt használja). @return array<int, string> */
function sdh_tema_css_forrasok(): array
{
    return ['uveg', 'neon', 'gombok', 'urlap', 'datum', 'kartyak', 'tablazat', 'harmonika', 'ertesites', 'szekciok', 'arak', 'blog', 'kereso', 'lablec'];
}

/* =====================================================================
 * Theme supports, menük
 * ================================================================== */

function sdh_tema_setup(): void
{
    load_theme_textdomain('sdh-sales-tema', SDH_TEMA_DIR . '/languages');

    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets']);
    add_theme_support('responsive-embeds');
    add_theme_support('custom-logo', ['height' => 40, 'width' => 160, 'flex-height' => true, 'flex-width' => true]);
    add_theme_support('automatic-feed-links');

    // Tartalék menühelyek (ha a bővítmény nincs bekapcsolva).
    register_nav_menus([
        'fo'     => __('Főmenü', 'sdh-sales-tema'),
        'lablec' => __('Lábléc', 'sdh-sales-tema'),
        'jogi'   => __('Jogi linkek', 'sdh-sales-tema'),
    ]);

    add_image_size('sdh-kartya', 760, 428, true);
}
add_action('after_setup_theme', 'sdh_tema_setup');

function sdh_tema_tartalomszelesseg(): void
{
    $GLOBALS['content_width'] = 760;
}
add_action('after_setup_theme', 'sdh_tema_tartalomszelesseg', 0);

/* =====================================================================
 * Takarítás: emoji, oEmbed-felfedezés, generátor, blokk-CSS a blogon kívül, jQuery migrate
 * ================================================================== */

function sdh_tema_takaritas(): void
{
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    add_filter('emoji_svg_url', '__return_false');

    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'wp_shortlink_wp_head');
    remove_action('wp_head', 'feed_links_extra', 3);
}
add_action('init', 'sdh_tema_takaritas');

/** A TinyMCE emoji-bővítmény se töltődjön. @param mixed $p @return array<int, string> */
function sdh_tema_tinymce_emoji($p): array
{
    return is_array($p) ? array_values(array_diff($p, ['wpemoji'])) : [];
}
add_filter('tiny_mce_plugins', 'sdh_tema_tinymce_emoji');

/** A blog-oldalak (cikk, lista, archívum, keresés) – csak itt kell a blokk-könyvtár CSS-e. */
function sdh_tema_blog_oldal(): bool
{
    return is_singular('post') || is_home() || is_archive() || is_search();
}

function sdh_tema_blokk_css(): void
{
    if (sdh_tema_blog_oldal()) {
        return;
    }

    foreach (['wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles', 'core-block-supports'] as $h) {
        wp_dequeue_style($h);
        wp_deregister_style($h);
    }
}
add_action('wp_enqueue_scripts', 'sdh_tema_blokk_css', 100);

// Klasszikus téma: a blokk-támogatás CSS-e ne kerüljön a lábba a nem-blog oldalakon.
add_filter('should_load_separate_core_block_assets', '__return_true');

/** jQuery migrate ki a fronton (a témának nincs jQuery-függése; ha egy bővítmény kéri a jQuery-t, az migrate nélkül jön). */
function sdh_tema_jquery_migrate(WP_Scripts $s): void
{
    if (is_admin() || !isset($s->registered['jquery'])) {
        return;
    }

    $s->registered['jquery']->deps = array_values(array_diff($s->registered['jquery']->deps, ['jquery-migrate']));
}
add_action('wp_default_scripts', 'sdh_tema_jquery_migrate');

/* =====================================================================
 * Betűk (önkiszolgált @fontsource-variable woff2; latin + latin-ext; változó súly)
 * ================================================================== */

/** @return array<int, array{csalad: string, fajl: string, tartomany: string, suly: string, elotolt: bool}> */
function sdh_tema_betuk(): array
{
    $latin = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
    $ext   = 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF';

    return [
        ['csalad' => 'Onest Variable', 'fajl' => 'onest-latin-wght-normal.woff2', 'tartomany' => $latin, 'suly' => '100 900', 'elotolt' => true],
        ['csalad' => 'Onest Variable', 'fajl' => 'onest-latin-ext-wght-normal.woff2', 'tartomany' => $ext, 'suly' => '100 900', 'elotolt' => false],
        ['csalad' => 'Bricolage Grotesque Variable', 'fajl' => 'bricolage-grotesque-latin-wght-normal.woff2', 'tartomany' => $latin, 'suly' => '200 800', 'elotolt' => true],
        ['csalad' => 'Bricolage Grotesque Variable', 'fajl' => 'bricolage-grotesque-latin-ext-wght-normal.woff2', 'tartomany' => $ext, 'suly' => '200 800', 'elotolt' => false],
        ['csalad' => 'JetBrains Mono Variable', 'fajl' => 'jetbrains-mono-latin-wght-normal.woff2', 'tartomany' => $latin, 'suly' => '100 800', 'elotolt' => false],
        ['csalad' => 'JetBrains Mono Variable', 'fajl' => 'jetbrains-mono-latin-ext-wght-normal.woff2', 'tartomany' => $ext, 'suly' => '100 800', 'elotolt' => false],
    ];
}

/**
 * @font-face szabályok + méretre igazított tartalékbetűk (kisebb CLS a csere pillanatában).
 * A size-adjust / ascent-override értékek Chromiumban mérve (tesztek/t240-tema.php melletti mérés, Arial-hoz).
 */
function sdh_tema_betu_css(): string
{
    $url = SDH_TEMA_URL . '/assets/fonts/';
    $ki  = '';

    foreach (sdh_tema_betuk() as $b) {
        $ki .= '@font-face{font-family:"' . $b['csalad'] . '";font-style:normal;font-display:swap;font-weight:' . $b['suly'] . ';src:url(' . esc_url($url . $b['fajl']) . ') format("woff2-variations"),url(' . esc_url($url . $b['fajl']) . ') format("woff2");unicode-range:' . $b['tartomany'] . '}';
    }

    $ki .= '@font-face{font-family:"Onest Tartalek";src:local("Arial"),local("Helvetica"),local("Liberation Sans");size-adjust:' . SDH_TEMA_BETU_ONEST['meret'] . '%;ascent-override:' . SDH_TEMA_BETU_ONEST['fel'] . '%;descent-override:' . SDH_TEMA_BETU_ONEST['le'] . '%;line-gap-override:0%}';
    $ki .= '@font-face{font-family:"Bricolage Tartalek";src:local("Arial"),local("Helvetica"),local("Liberation Sans");size-adjust:' . SDH_TEMA_BETU_BRICOLAGE['meret'] . '%;ascent-override:' . SDH_TEMA_BETU_BRICOLAGE['fel'] . '%;descent-override:' . SDH_TEMA_BETU_BRICOLAGE['le'] . '%;line-gap-override:0%}';

    return $ki;
}

/** Tartalékbetű-metrikák (Chromiumban mérve, lásd bin/betu-meres.py). */
const SDH_TEMA_BETU_ONEST     = ['meret' => '100', 'fel' => '100', 'le' => '25'];
const SDH_TEMA_BETU_BRICOLAGE = ['meret' => '100', 'fel' => '100', 'le' => '25'];

/* =====================================================================
 * Fej: sötét / világos mód festés előtt, kritikus CSS, előtöltés
 * ================================================================== */

/** Már a <head> elején: a tárolt téma a <html>-re (villanás nélkül) + „js” osztály. */
function sdh_tema_fej_elso(): void
{
    // A localStorage tiltott lehet (privát mód) – try/catch.
    echo "<script>(function(d){d.className+=' js';try{var t=localStorage.getItem('sdh-tema');if(t==='sotet'||t==='vilagos'){d.setAttribute('data-tema',t)}}catch(e){}})(document.documentElement)</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}
add_action('wp_head', 'sdh_tema_fej_elso', 0);

/** A dist frissebb-e a forrásoknál (ha nem, a forrásból dolgozunk). */
function sdh_tema_dist_jo(string $dist, array $forrasok): bool
{
    if (!is_file($dist)) {
        return false;
    }

    $t = (int) filemtime($dist);

    foreach ($forrasok as $f) {
        if (is_file($f) && (int) filemtime($f) > $t) {
            return false;
        }
    }

    return true;
}

/** A kritikus CSS szövege (tokenek + kritikus.css), a fejbe ágyazva. */
function sdh_tema_kritikus_css(): string
{
    $css  = SDH_TEMA_DIR . '/assets/css/';
    $src  = [$css . 'tokenek.css', $css . 'kritikus.css'];
    $dist = SDH_TEMA_DIR . '/assets/dist/kritikus.min.css';

    if (sdh_tema_dist_jo($dist, $src)) {
        return (string) file_get_contents($dist);
    }

    $ki = '';

    foreach ($src as $f) {
        if (is_file($f)) {
            $ki .= sdh_tema_css_tomorit((string) file_get_contents($f));
        }
    }

    return $ki;
}

/** Egyszerű, biztonságos CSS-tömörítés (megjegyzés, felesleges szóköz). */
function sdh_tema_css_tomorit(string $css): string
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $css = (string) preg_replace('/\s+/', ' ', $css);
    $css = (string) preg_replace('/\s*([{};,>])\s*/', '$1', $css);
    $css = (string) preg_replace('/:\s+/', ':', $css);
    $css = str_replace(';}', '}', $css);

    // A media query-k „and (” részét visszaállítjuk (a fenti szabály nem bántja, de a „) and (” biztonságos).
    return trim($css);
}

function sdh_tema_fej_css(): void
{
    $betu = SDH_TEMA_URL . '/assets/fonts/';

    foreach (sdh_tema_betuk() as $b) {
        if ($b['elotolt']) {
            echo '<link rel="preload" href="' . esc_url($betu . $b['fajl']) . '" as="font" type="font/woff2" crossorigin>' . "\n";
        }
    }

    // A kritikus CSS + a betűk: a CSS-ben nincs felhasználói adat (statikus fájl), a betű-URL-ek esc_url-lel mentek.
    echo '<style id="sdh-tema-kritikus">' . sdh_tema_betu_css() . sdh_tema_kritikus_css() . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput

    // A teljes CSS nem blokkol: print → all; JS nélkül <noscript>.
    foreach (sdh_tema_css_url() as $i => $u) {
        echo '<link rel="stylesheet" id="sdh-tema-css-' . (int) $i . '" href="' . esc_url($u) . '" media="print" onload="this.media=\'all\';this.onload=null">' . "\n";
    }

    echo '<noscript>';

    foreach (sdh_tema_css_url() as $u) {
        echo '<link rel="stylesheet" href="' . esc_url($u) . '">';
    }

    echo "</noscript>\n";
}
add_action('wp_head', 'sdh_tema_fej_css', 2);

/** A nem kritikus CSS URL-je(i): a dist, vagy (fejlesztéskor) a források egyenként. @return array<int, string> */
function sdh_tema_css_url(): array
{
    $css  = SDH_TEMA_DIR . '/assets/css/';
    $src  = array_map(static fn ($n) => $css . $n . '.css', sdh_tema_css_forrasok());
    $dist = SDH_TEMA_DIR . '/assets/dist/tema.min.css';

    if (sdh_tema_dist_jo($dist, $src)) {
        return [SDH_TEMA_URL . '/assets/dist/tema.min.css?ver=' . rawurlencode(SDH_TEMA_VERSION . '-' . (string) filemtime($dist))];
    }

    $ki = [];

    foreach (sdh_tema_css_forrasok() as $n) {
        if (is_file($css . $n . '.css')) {
            $ki[] = SDH_TEMA_URL . '/assets/css/' . $n . '.css?ver=' . rawurlencode(SDH_TEMA_VERSION . '-' . (string) filemtime($css . $n . '.css'));
        }
    }

    return $ki;
}

/* =====================================================================
 * JS (defer, a lábban; a kereső és a naptár csak igény szerint töltődik)
 * ================================================================== */

/** Egy téma-JS fájl URL-je: a dist (tömörített) ha frissebb, különben a forrás. */
function sdh_tema_js_url(string $nev): string
{
    $src  = SDH_TEMA_DIR . '/assets/js/' . $nev . '.js';
    $dist = SDH_TEMA_DIR . '/assets/dist/' . $nev . '.min.js';

    if (sdh_tema_dist_jo($dist, [$src])) {
        return SDH_TEMA_URL . '/assets/dist/' . $nev . '.min.js?ver=' . rawurlencode(SDH_TEMA_VERSION . '-' . (string) filemtime($dist));
    }

    return SDH_TEMA_URL . '/assets/js/' . $nev . '.js?ver=' . rawurlencode(SDH_TEMA_VERSION . '-' . (string) (is_file($src) ? filemtime($src) : 0));
}

function sdh_tema_szkriptek(): void
{
    wp_enqueue_script('sdh-tema', sdh_tema_js_url('tema'), [], null, ['in_footer' => true, 'strategy' => 'defer']); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- a verzió az URL-ben

    $index = '';

    if (class_exists('SDH_Sales_Kereso') && method_exists('SDH_Sales_Kereso', 'index_url')) {
        try {
            $index = (string) SDH_Sales_Kereso::index_url();
        } catch (Throwable $e) {
            $index = '';
        }
    }

    $nepszeru = [];

    foreach (array_slice(sdh_tema_menu('fo'), 0, 6) as $m) {
        if ($m['url'] !== '') {
            $nepszeru[] = ['t' => $m['cim'], 'u' => $m['url']];
        }
    }

    // Csak statikus, nem felhasználófüggő adat (gyorsítótárazható oldalak).
    $adat = [
        'kereso_js' => sdh_tema_js_url('kereso'),
        'datum_js'  => sdh_tema_js_url('datum'),
        'index'     => $index,
        'rest'      => class_exists('SDH_Sales_Rest') ? esc_url_raw(rest_url(SDH_Sales_Rest::NS)) : '',
        'kereses'   => home_url('/'),
        'nepszeru'  => $nepszeru,
        'szoveg'    => [
            'biztos'       => __('Biztos? Kattints újra.', 'sdh-sales-tema'),
            'sotet'        => __('Sötét mód bekapcsolása', 'sdh-sales-tema'),
            'vilagos'      => __('Világos mód bekapcsolása', 'sdh-sales-tema'),
            'menu_nyit'    => __('Menü megnyitása', 'sdh-sales-tema'),
            'menu_zar'     => __('Menü bezárása', 'sdh-sales-tema'),
            'masolva'      => __('Vágólapra másolva.', 'sdh-sales-tema'),
            'bezar'        => __('Bezárás', 'sdh-sales-tema'),
            'video_betolt' => __('Videó betöltése', 'sdh-sales-tema'),
        ],
        'kereso_szoveg' => [
            'cim'        => __('Keresés', 'sdh-sales-tema'),
            'hely'       => sdh_tema_t(__('Keress funkcióra, modulra, kérdésre…', 'sdh-sales-tema')),
            'nincs'      => __('Nincs találat. Próbáld más szóval, vagy nézd meg a népszerű oldalakat.', 'sdh-sales-tema'),
            'tolt'       => __('Keresés…', 'sdh-sales-tema'),
            'nepszeru'   => __('Népszerű', 'sdh-sales-tema'),
            'legutobbi'  => __('Legutóbbi keresések', 'sdh-sales-tema'),
            'osszes'     => __('Összes találat', 'sdh-sales-tema'),
            'sugo'       => __('↑↓ lépked · Enter megnyit · Esc bezár', 'sdh-sales-tema'),
            'talalat'    => __('%d találat', 'sdh-sales-tema'),
            'hiba'       => __('A keresés most nem érhető el. Próbáld újra.', 'sdh-sales-tema'),
            'csoport'    => [
                'oldal'   => __('Oldalak', 'sdh-sales-tema'),
                'funkcio' => __('Funkciók', 'sdh-sales-tema'),
                'modul'   => __('Modulok', 'sdh-sales-tema'),
                'iparag'  => __('Iparágak', 'sdh-sales-tema'),
                'gyik'    => __('Gyakori kérdések', 'sdh-sales-tema'),
                'tudas'   => __('Tudásbázis', 'sdh-sales-tema'),
                'blog'    => __('Blog', 'sdh-sales-tema'),
            ],
        ],
        'datum_szoveg' => [
            'honapok'   => [__('január', 'sdh-sales-tema'), __('február', 'sdh-sales-tema'), __('március', 'sdh-sales-tema'), __('április', 'sdh-sales-tema'), __('május', 'sdh-sales-tema'), __('június', 'sdh-sales-tema'), __('július', 'sdh-sales-tema'), __('augusztus', 'sdh-sales-tema'), __('szeptember', 'sdh-sales-tema'), __('október', 'sdh-sales-tema'), __('november', 'sdh-sales-tema'), __('december', 'sdh-sales-tema')],
            'napok'     => [__('hétfő', 'sdh-sales-tema'), __('kedd', 'sdh-sales-tema'), __('szerda', 'sdh-sales-tema'), __('csütörtök', 'sdh-sales-tema'), __('péntek', 'sdh-sales-tema'), __('szombat', 'sdh-sales-tema'), __('vasárnap', 'sdh-sales-tema')],
            'rovid'     => [__('H', 'sdh-sales-tema'), __('K', 'sdh-sales-tema'), __('Sze', 'sdh-sales-tema'), __('Cs', 'sdh-sales-tema'), __('P', 'sdh-sales-tema'), __('Szo', 'sdh-sales-tema'), __('V', 'sdh-sales-tema')],
            'nyit'      => __('Naptár megnyitása', 'sdh-sales-tema'),
            'elozo'     => __('Előző hónap', 'sdh-sales-tema'),
            'kovetkezo' => __('Következő hónap', 'sdh-sales-tema'),
            'ma'        => __('Ma', 'sdh-sales-tema'),
            'torol'     => __('Törlés', 'sdh-sales-tema'),
            'hibas'     => __('Érvénytelen dátum. Formátum: ÉÉÉÉ.HH.NN.', 'sdh-sales-tema'),
            'tartomany' => __('Ez a nap nem választható.', 'sdh-sales-tema'),
            'valasztott' => __('Kiválasztva:', 'sdh-sales-tema'),
        ],
    ];

    wp_add_inline_script('sdh-tema', 'window.SDH_TEMA=' . wp_json_encode($adat, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ';', 'before');

    if (is_singular() && comments_open() && get_option('thread_comments')) {
        wp_enqueue_script('comment-reply');
    }
}
add_action('wp_enqueue_scripts', 'sdh_tema_szkriptek');

/** A kereső- és naptár-JS előre jelzése alacsony prioritással nem kell (lusta) – csak a dns/előcsatlakozás nem kell (saját domain). */

/* =====================================================================
 * Egyéb front-finomhangolás
 * ================================================================== */

/** <body> osztályok: blog / szekciós oldal. @param array<int, string> $o @return array<int, string> */
function sdh_tema_body_class(array $o): array
{
    if (sdh_tema_blog_oldal()) {
        $o[] = 's-blog-oldal';
    }

    if (is_singular() && sdh_tema_van_szekcio((int) get_queried_object_id())) {
        $o[] = 's-szekcios';
    }

    return $o;
}
add_filter('body_class', 'sdh_tema_body_class');

/** A kivonat rövidebb, „…” végű. */
add_filter('excerpt_length', static fn (): int => 26);
add_filter('excerpt_more', static fn (): string => '…');

/** A kereső-űrlap: a téma saját markupja (lekerekített mező + gomb). */
function sdh_tema_kereso_urlap(string $form = ''): string
{
    $id = sdh_tema_azon('kereso-mezo');

    return '<form role="search" method="get" class="s-kereso-urlap" action="' . esc_url(home_url('/')) . '">'
        . '<label class="s-sr" for="' . esc_attr($id) . '">' . esc_html(sdh_tema__('Keresés')) . '</label>'
        . '<input class="s-mezo" type="search" id="' . esc_attr($id) . '" name="s" value="' . esc_attr(get_search_query()) . '" placeholder="' . esc_attr(sdh_tema__('Mit keresel?')) . '" autocomplete="off" enterkeyhint="search">'
        . '<button class="s-gomb s-gomb--elsodleges" type="submit">' . sdh_tema_ikon('kereso') . '<span>' . esc_html(sdh_tema__('Keresés')) . '</span></button>'
        . '</form>';
}
add_filter('get_search_form', 'sdh_tema_kereso_urlap');

/** Kommentek kikapcsolva az oldalakon (értékesítő webhely). */
add_filter('comments_open', static fn ($nyitva, $id) => get_post_type((int) $id) === 'post' ? $nyitva : false, 10, 2);
