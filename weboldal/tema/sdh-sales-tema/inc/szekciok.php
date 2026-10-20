<?php
/**
 * SDH Értékesítés téma – szekció-diszpécser (ARCHITEKTURA.md §7, §11).
 *
 *   sdh_tema_szekciok($post_id)   – az oldal szekcióit a `szekciok/<tipus>.php` sablonokkal rajzolja ($sz = a szekció adata)
 *   sdh_tema_szekcio($sz, $i)     – egy szekció (pl. tesztből, vagy egy sablonból beágyazva)
 *   sdh_tema_szekcio_tipusok()    – a téma által ismert típusok
 *
 * A bővítmény nélkül nincs adat (a hívás védett), így üres a kimenet – nincs fatális hiba. Ismeretlen típus:
 * csak szerkesztőnek látható HTML-megjegyzés. A `rejtett` szekció nem rajzolódik.
 *
 * @package SDH_Sales_Tema
 */

if (!defined('ABSPATH')) {
    exit;
}

/** @return array<int, string> */
function sdh_tema_szekcio_tipusok(): array
{
    return [
        'hero', 'logo_sav', 'jellemzok', 'funkcio_racs', 'modul_racs', 'iparag_racs', 'arak', 'ar_tablazat', 'kalkulator',
        'osszehasonlitas', 'lepesek', 'szoveg', 'kep_szoveg', 'video', 'velemeny', 'szamok', 'gyik', 'cta', 'urlap',
        'foglalas', 'demo_inditas', 'blog_lista', 'tudasbazis', 'statusz', 'jogi', 'kereso', 'fiok', 'kosar', 'penztar',
        'oktatas', 'telepito', 'esemenyek',
    ];
}

/** @return array<int, array<string, mixed>> az oldal szekciói (bővítmény nélkül üres) */
function sdh_tema_szekcio_adat(int $post_id): array
{
    if ($post_id <= 0 || !class_exists('SDH_Sales_Tartalom')) {
        return [];
    }

    try {
        return SDH_Sales_Tartalom::szekciok($post_id);
    } catch (Throwable $e) {
        return [];
    }
}

/** Van-e az oldalnak (látható) szekciója. */
function sdh_tema_van_szekcio(int $post_id): bool
{
    foreach (sdh_tema_szekcio_adat($post_id) as $sz) {
        if (empty($sz['rejtett']) && !empty($sz['tipus'])) {
            return true;
        }
    }

    return false;
}

/** Van-e az oldalon adott típusú szekció. */
function sdh_tema_van_tipus(int $post_id, string $tipus): bool
{
    foreach (sdh_tema_szekcio_adat($post_id) as $sz) {
        if (($sz['tipus'] ?? '') === $tipus && empty($sz['rejtett'])) {
            return true;
        }
    }

    return false;
}

/** Az oldal összes szekciója. */
function sdh_tema_szekciok(int $post_id): void
{
    foreach (sdh_tema_szekcio_adat($post_id) as $i => $sz) {
        $sz['_post'] = $post_id; // a Stúdió szerkesztési útjához (o:{post}/{szekcio}/{mezo})
        sdh_tema_szekcio($sz, (int) $i);
    }
}

/**
 * Egy szekció kirajzolása. A sablon a `$sz` változót kapja (és a `$sz['_i']` sorszámot).
 *
 * @param array<string, mixed> $sz
 */
function sdh_tema_szekcio(array $sz, int $i = 0): void
{
    $tipus = is_string($sz['tipus'] ?? null) ? $sz['tipus'] : '';

    if ($tipus === '' || !empty($sz['rejtett'])) {
        return;
    }

    $fajl = '';

    if (preg_match('/^[a-z0-9_]{1,40}$/', $tipus) === 1 && in_array($tipus, sdh_tema_szekcio_tipusok(), true)) {
        $fajl = (string) locate_template('szekciok/' . $tipus . '.php');
    }

    if ($fajl === '') {
        echo sdh_tema_admin_megjegyzes('ismeretlen szekciótípus: ' . $tipus); // phpcs:ignore WordPress.Security.EscapeOutput -- escapelve
        return;
    }

    $sz['_i'] = $i;

    /**
     * Szűrő a sablon előtt (pl. egy bővítmény kiegészíti az adatot).
     *
     * @param array<string, mixed> $sz
     */
    $sz = (array) apply_filters('sdh_tema_szekcio', $sz, $tipus);

    sdh_tema_szekcio_futtat($fajl, $sz);
}

/**
 * A sablon elszigetelt hatókörben (csak $sz látszik).
 *
 * @param array<string, mixed> $sz
 */
function sdh_tema_szekcio_futtat(string $fajl, array $sz): void
{
    include $fajl;
}

/* =====================================================================
 * Adatvezérelt szekciók közös segédei
 * ================================================================== */

/**
 * Tartalom-elemek szűrése: `kulcsok` (ebben a sorrendben), `csoport` / `kategoria` (adatmező), `db` / `max` (darab).
 *
 * @param array<int, array<string, mixed>> $elemek
 * @param array<string, mixed>              $sz
 * @return array<int, array<string, mixed>>
 */
function sdh_tema_elem_szur(array $elemek, array $sz, string $csoportmezo = 'csoport'): array
{
    $kulcsok = sdh_tema_szovegek($sz, 'kulcsok');

    if ($kulcsok !== []) {
        $m = [];

        foreach ($elemek as $e) {
            $m[(string) $e['kulcs']] = $e;
        }

        $elemek = array_values(array_filter(array_map(static fn ($k) => $m[$k] ?? null, $kulcsok)));
    }

    $cs = sdh_tema_mezo($sz, $csoportmezo);

    if ($cs !== '') {
        $cs_h   = remove_accents(mb_strtolower($cs));
        $elemek = array_values(array_filter($elemek, static fn ($e) => remove_accents(mb_strtolower((string) ($e['adat'][$csoportmezo] ?? ''))) === $cs_h));
    }

    $db = (int) ($sz['db'] ?? $sz['max'] ?? 0);

    return $db > 0 ? array_slice($elemek, 0, $db) : $elemek;
}

/** Egy elem neve (nev / cim / az elem címe). @param array<string, mixed> $e */
function sdh_tema_elem_nev(array $e): string
{
    $a = (array) ($e['adat'] ?? []);

    return sdh_tema_t((string) ($a['nev'] ?? $a['cim'] ?? $e['cim'] ?? $e['kulcs'] ?? ''));
}

/**
 * Tartalomtár-elem szerkesztési jelölése: `t:{tipus}/{kulcs}[/{mezo}]` (a Stúdió kattintható előnézetéhez).
 *
 * @param array<string, mixed> $e a SDH_Sales_Tartalom::lista() egy sora
 */
function sdh_tema_elem_szerk(array $e, string $mezo = ''): string
{
    $t = (string) ($e['tipus'] ?? '');
    $k = (string) ($e['kulcs'] ?? '');

    if ($t === '' || $k === '') {
        return '';
    }

    if ($mezo === 'nev') {
        $a    = (array) ($e['adat'] ?? []);
        $mezo = isset($a['nev']) && $a['nev'] !== '' ? 'nev' : 'cim';
    } elseif ($mezo === 'leiras') {
        $a    = (array) ($e['adat'] ?? []);
        $mezo = isset($a['rovid']) && $a['rovid'] !== '' ? 'rovid' : 'leiras';
    }

    return sdh_tema_szerk('t:' . $t . '/' . $k . ($mezo !== '' ? '/' . $mezo : ''));
}

/** Egy elem rövid leírása (rovid / leiras, HTML nélkül). @param array<string, mixed> $e */
function sdh_tema_elem_leiras(array $e, int $max = 0): string
{
    $a = (array) ($e['adat'] ?? []);
    $l = trim(wp_strip_all_tags(sdh_tema_t((string) ($a['rovid'] ?? $a['leiras'] ?? ''))));

    return $max > 0 && mb_strlen($l) > $max ? rtrim(mb_substr($l, 0, $max - 1)) . '…' : $l;
}

/** A „Kapcsolódó” blokk egy elemhez (a bővítmény SEO-modulja adja a linkeket). */
function sdh_tema_kapcsolodo(string $tipus, string $kulcs, int $max = 6, string $cim = ''): string
{
    if (!class_exists('SDH_Sales_Seo') || $kulcs === '') {
        return '';
    }

    try {
        $l = SDH_Sales_Seo::kapcsolodo($tipus, $kulcs, $max);
    } catch (Throwable $e) {
        return '';
    }

    if ($l === []) {
        return '';
    }

    $cimek = [
        'funkcio' => sdh_tema__('Funkció'),
        'iparag'  => sdh_tema__('Iparág'),
        'modul'   => sdh_tema__('Modul'),
        'blog'    => sdh_tema__('Cikk'),
        'gyik'    => sdh_tema__('Kérdés'),
        'csomag'  => sdh_tema__('Csomag'),
    ];

    $azon = sdh_tema_azon('kapcsolodo');
    $ki   = '<aside class="s-kapcsolodo" aria-labelledby="' . esc_attr($azon) . '"><h2 id="' . esc_attr($azon) . '" class="s-kapcsolodo__cim">' . esc_html($cim !== '' ? $cim : sdh_tema__('Kapcsolódó')) . '</h2><ul class="s-kapcsolodo__lista">';

    foreach ($l as $e) {
        $url = (string) ($e['url'] ?? '');

        if ($url === '') {
            continue;
        }

        $t   = (string) ($e['tipus'] ?? '');
        $ki .= '<li><a class="s-kapcsolodo__elem" href="' . esc_url($url) . '">'
            . ($t !== '' && isset($cimek[$t]) ? '<span class="s-pill s-pill--kicsi">' . esc_html($cimek[$t]) . '</span>' : '')
            . '<span class="s-kapcsolodo__nev">' . esc_html(sdh_tema_t((string) ($e['cim'] ?? ''))) . '</span>'
            . (!empty($e['leiras']) ? '<span class="s-kapcsolodo__leiras">' . esc_html(sdh_tema_t((string) $e['leiras'])) . '</span>' : '')
            . '</a></li>';
    }

    return $ki . '</ul></aside>';
}

/** Az aktuális oldal tartalom-eleme (funkciók/<kulcs>, iparagak/<kulcs>) a „Kapcsolódó” blokkhoz. @return array{0: string, 1: string} */
function sdh_tema_oldal_elem(int $post_id): array
{
    $p = get_post($post_id);

    if (!$p instanceof WP_Post || $p->post_type !== 'page' || (int) $p->post_parent === 0) {
        return ['', ''];
    }

    $szulo = get_post((int) $p->post_parent);
    $s     = $szulo instanceof WP_Post ? (string) $szulo->post_name : '';
    $map   = ['funkciok' => 'funkcio', 'iparagak' => 'iparag', 'modulok' => 'modul'];

    return isset($map[$s]) ? [$map[$s], (string) $p->post_name] : ['', ''];
}
