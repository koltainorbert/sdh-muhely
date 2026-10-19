<?php
/**
 * t275 – tartalom B: data/gyik.json és data/blog/*.md.
 * GYIK: érvényes JSON, ≥ 160 elem, a 15 kategória mindegyikében ≥ 8, egyedi ASCII-kulcsok, a kapcsolódók léteznek (1–3),
 * a válasz csak engedett HTML (p/ul/ol/li/strong/em/a/code), 40–120 szó, nincs beégetett terméknév, nincs lorem.
 * Blog: ≥ 20 cikk, teljes frontmatter, ≥ 900 szó, „Röviden:” összefoglaló, H2, {termeknev} 1–3-szor, CTA (/demo/ vagy /arak/),
 * a belső linkek létező célra mutatnak (funkció-, iparág- és egyéb oldalak: ha a cél adata még nincs meg, kihagyva),
 * a GYIK-horgonyok létező kulcsra. Betöltés: SDH_Sales_Tartalom::betolt() létrehozza a bejegyzéseket.
 */
require __DIR__ . '/_keret.php';

$adat = SDH_SALES_DIR . 'data/';
$nyers = (string) file_get_contents($adat . 'gyik.json');
$gyik  = json_decode($nyers, true);
T::igaz(is_array($gyik), 'gyik.json érvényes JSON');
$gyik = is_array($gyik) ? $gyik : [];
T::igaz(count($gyik) >= 160, 'gyik: legalább 160 elem (' . count($gyik) . ')');

$kat_kell = ['vasarlas', 'arak', 'licenc', 'telepites', 'adatbiztonsag', 'adatatvetel', 'modulok', 'iparagak', 'tamogatas', 'technikai', 'szerver', 'offline', 'integraciok', 'egyedi-fejlesztes', 'demo'];
$kulcsok   = array_column($gyik, 'kulcs');
$kulcs_set = array_flip($kulcsok);
T::egyezik(count($kulcsok), count(array_unique($kulcsok)), 'gyik: egyedi kulcsok');

$katdb = array_count_values(array_column($gyik, 'kategoria'));
foreach ($kat_kell as $k) {
    T::igaz(($katdb[$k] ?? 0) >= 8, "gyik: {$k} legalább 8 (" . ($katdb[$k] ?? 0) . ')');
}

$szavak = static function (string $s): int {
    return count(preg_split('/\s+/u', trim((string) preg_replace('/<[^>]+>/', ' ', $s)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
};

$hibak = ['kulcs' => [], 'kat' => [], 'kapcs' => [], 'html' => [], 'szo' => [], 'nev' => []];
foreach ($gyik as $e) {
    $k = (string) ($e['kulcs'] ?? '');
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $k)) {
        $hibak['kulcs'][] = $k;
    }
    if (!in_array($e['kategoria'] ?? '', $kat_kell, true)) {
        $hibak['kat'][] = $k;
    }
    $kp = (array) ($e['kapcsolodo'] ?? []);
    if (count($kp) < 1 || count($kp) > 3) {
        $hibak['kapcs'][] = $k . ' (db)';
    }
    foreach ($kp as $x) {
        if (!isset($kulcs_set[$x]) || $x === $k) {
            $hibak['kapcs'][] = $k . '→' . $x;
        }
    }
    $v = (string) ($e['valasz'] ?? '');
    preg_match_all('#</?([a-zA-Z0-9]+)#', $v, $m);
    $tilos = array_diff(array_unique(array_map('strtolower', $m[1])), ['p', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'code']);
    if ($tilos !== [] || preg_match('/\son\w+=|javascript:|style=/i', $v)) {
        $hibak['html'][] = $k;
    }
    $n = $szavak($v);
    if ($n < 40 || $n > 120) {
        $hibak['szo'][] = "{$k} ({$n})";
    }
    $teljes = ($e['kerdes'] ?? '') . ' ' . $v;
    if (stripos($teljes, 'SDH CRM') !== false || preg_match('/lorem|wordpress|plugin|bővítmény|github|woocommerce|veszprém|sdh/iu', $teljes)) {
        $hibak['nev'][] = $k;
    }
    if (trim((string) ($e['kerdes'] ?? '')) === '') {
        $hibak['kat'][] = $k . ' (üres kérdés)';
    }
}
foreach ($hibak as $nev => $l) {
    T::egyezik([], array_slice($l, 0, 5), "gyik: {$nev}");
}

/* ------------------------------ Blog ------------------------------ */

$fajlok = glob($adat . 'blog/*.md') ?: [];
T::igaz(count($fajlok) >= 20, 'blog: legalább 20 cikk (' . count($fajlok) . ')');

$funkciok = [];
foreach (['funkciok.json' => 'funkciok', 'iparagak.json' => 'iparagak'] as $f => $elotag) {
    $a = is_file($adat . $f) ? json_decode((string) file_get_contents($adat . $f), true) : null;
    if (is_array($a)) {
        $a = isset($a['elemek']) && is_array($a['elemek']) ? $a['elemek'] : $a;
        foreach ($a as $e) {
            foreach (['slug', 'kulcs'] as $m) {
                if (!empty($e[$m])) {
                    $funkciok[$elotag][(string) $e[$m]] = true;
                }
            }
        }
    }
}
$oldalak = [];
foreach (glob($adat . 'oldalak/*.json') ?: [] as $f) {
    $o = json_decode((string) file_get_contents($f), true);
    if (is_array($o) && !empty($o['slug'])) {
        $szulo = trim((string) ($o['szulo'] ?? ''), '/');
        $oldalak[trim(($szulo !== '' ? $szulo . '/' : '') . $o['slug'], '/')] = true;
        $oldalak[trim((string) $o['slug'], '/')] = $oldalak[trim((string) $o['slug'], '/')] ?? false;
    }
}

$bh = ['fej' => [], 'szo' => [], 'roviden' => [], 'h2' => [], 'nev' => [], 'cta' => [], 'link' => [], 'gyik' => [], 'datum' => []];
$kihagyott = 0;
$linkek    = [];
foreach ($fajlok as $f) {
    $slug       = basename($f, '.md');
    [$fej, $md] = SDH_Sales_Tartalom::frontmatter((string) file_get_contents($f));
    foreach (['cim', 'leiras', 'kategoria', 'cimkek', 'datum', 'kulcsszo', 'seo_cim'] as $m) {
        if (empty($fej[$m])) {
            $bh['fej'][] = "{$slug}:{$m}";
        }
    }
    if (!is_array($fej['cimkek'] ?? null)) {
        $bh['fej'][] = "{$slug}:cimkek lista";
    }
    $d = (string) ($fej['datum'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d < '2026-06-01' || $d > '2026-10-19') {
        $bh['datum'][] = "{$slug}:{$d}";
    }
    $szoveg = preg_replace('/\]\([^)]*\)/', ']', $md);
    $n      = count(preg_split('/\s+/u', trim((string) preg_replace('/[#*|`>\[\]]/', ' ', (string) $szoveg)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    if ($n < 900 || $n > 1700) {
        $bh['szo'][] = "{$slug} ({$n})";
    }
    if (strpos($md, '**Röviden:**') === false) {
        $bh['roviden'][] = $slug;
    }
    if (preg_match_all('/^## /m', $md) < 3) {
        $bh['h2'][] = $slug;
    }
    $tn = substr_count($md, '{termeknev}');
    if ($tn < 1 || $tn > 3 || stripos($md . implode(' ', array_filter($fej, 'is_string')), 'SDH CRM') !== false || preg_match('/lorem|wordpress|plugin|bővítmény|github|woocommerce|veszprém|sdh/iu', $md)) {
        $bh['nev'][] = "{$slug} ({$tn})";
    }
    if (strpos($md, '](/demo/)') === false && strpos($md, '](/arak/)') === false) {
        $bh['cta'][] = $slug;
    }
    preg_match_all('#\]\((/[^)\s]*)\)#', $md, $lk);
    $linkek[$slug] = $lk[1];
}
foreach ($gyik as $e) {
    preg_match_all('#href="(/[^"]*)"#', (string) ($e['valasz'] ?? ''), $lk);
    $linkek['gyik:' . ($e['kulcs'] ?? '')] = $lk[1];
}
foreach ($linkek as $slug => $lista) {
    foreach ($lista as $u) {
        if (preg_match('#^/gyik/\#gyik-([a-z0-9-]+)$#', $u, $g)) {
            if (!isset($kulcs_set[$g[1]])) {
                $bh['gyik'][] = "{$slug}→{$g[1]}";
            }
            continue;
        }
        if (!preg_match('#^/([a-z0-9-]+(/[a-z0-9-]+)?)/$#', $u, $p)) {
            $bh['link'][] = "{$slug}→{$u}";
            continue;
        }
        $ut = $p[1];
        if (preg_match('#^(funkciok|iparagak)/([a-z0-9-]+)$#', $ut, $q)) {
            if (empty($funkciok[$q[1]]) && !isset($oldalak[$ut])) {
                $kihagyott++;
                continue;
            }
            if (!isset($funkciok[$q[1]][$q[2]]) && !isset($oldalak[$ut])) {
                $bh['link'][] = "{$slug}→{$u}";
            }
            continue;
        }
        if ($oldalak === []) {
            $kihagyott++;
            continue;
        }
        if (!array_key_exists($ut, $oldalak) && !in_array($ut, ['gyik', 'demo', 'arak'], true)) {
            $bh['link'][] = "{$slug}→{$u}";
        }
    }
}
foreach ($bh as $nev => $l) {
    T::egyezik([], array_slice($l, 0, 6), "blog: {$nev}");
}

/* ------------------------- Betöltés a tesztoldalon ------------------------- */

$db = SDH_Sales_Tartalom::betolt(false);
T::igaz(count(SDH_Sales_Tartalom::lista('gyik')) >= 160, 'betöltés: gyik a tárban');
$p = get_page_by_path('szerviz-program-kivalasztasa', OBJECT, 'post');
T::igaz($p instanceof WP_Post, 'betöltés: blogbejegyzés létrejött');
$letrejott = 0;
foreach ($fajlok as $f) {
    $letrejott += get_page_by_path(basename($f, '.md'), OBJECT, 'post') instanceof WP_Post ? 1 : 0;
}
T::egyezik(count($fajlok), $letrejott, 'betöltés: minden cikk bejegyzés');
if ($p instanceof WP_Post) {
    T::tartalmaz($p->post_content, '<h2', 'bejegyzés: H2');
    T::tartalmaz($p->post_content, '<table', 'bejegyzés: táblázat');
    T::tartalmaz($p->post_content, 'href="/demo/"', 'bejegyzés: belső link');
    T::igaz(has_term('Szervizvezetés', 'category', $p), 'bejegyzés: kategória');
    T::igaz(has_term('szerviz program', 'post_tag', $p), 'bejegyzés: címke');
    T::egyezik('2026-06-01', substr($p->post_date, 0, 10), 'bejegyzés: dátum');
}

if ($kihagyott > 0) {
    echo "   (t275: {$kihagyott} belső link célja még nincs a data/ alatt – kihagyva)\n";
}
T::vege('t275 gyik+blog');
