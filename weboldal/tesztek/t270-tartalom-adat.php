<?php
/**
 * t270 – a tartalom ügynök (A) adatfájljai: JSON-érvényesség, oldal-szerződés (slug, cim, seo ≤ 60 / ≤ 155, kulcsszó),
 * szekciótípusok a szerződés listájából, minden funkciónak / iparágnak van oldala, a menük slugjai léteznek
 * (oldal-JSON vagy jogi MD, a jogi szülő alatt), nincs beégetett terméknév, lorem, tiltott szó (REJTES.md),
 * a GYIK-kulcsok léteznek (ha van gyik.json), leckék / telepítés szerkezete, végül betöltés SDH_Sales_Tartalom::betolt()-tel.
 */
require __DIR__ . '/_keret.php';

$d = SDH_SALES_DIR . 'data/';

$json = static function (string $f) {
    $s = is_file($f) ? (string) file_get_contents($f) : '';
    $a = json_decode($s, true);

    return [$a, json_last_error() === JSON_ERROR_NONE && $s !== ''];
};

$tipusok = ['hero', 'logo_sav', 'jellemzok', 'funkcio_racs', 'modul_racs', 'iparag_racs', 'arak', 'ar_tablazat', 'kalkulator',
    'osszehasonlitas', 'lepesek', 'szoveg', 'kep_szoveg', 'video', 'velemeny', 'szamok', 'gyik', 'cta', 'urlap', 'foglalas',
    'demo_inditas', 'blog_lista', 'tudasbazis', 'statusz', 'jogi', 'kereso', 'fiok', 'kosar', 'penztar', 'oktatas', 'telepito'];
$tilos   = '/wordpress|\bsdh\b|sdh[-_ ]|plugin|bővítmény|veszprém|lorem|ipsum|SDH CRM|\bWP\b|wp-(content|admin|json|login)/iu';

// --- Típusos adatfájlok ---
$fajlok = [];

foreach (['funkciok.json', 'iparagak.json', 'menuk.json', 'oktatas.json', 'telepites.json'] as $f) {
    [$a, $ok] = $json($d . $f);
    T::igaz($ok && is_array($a) && $a !== [], 'érvényes JSON: ' . $f);
    $fajlok[$f] = is_array($a) ? $a : [];
}

[$gyik, $van_gyik] = $json($d . 'gyik.json');
$gyik_k  = $van_gyik ? array_flip(array_column(is_array($gyik['elemek'] ?? null) ? $gyik['elemek'] : (array) $gyik, 'kulcs')) : [];
$gyik_kt = $van_gyik ? array_flip(array_column(is_array($gyik['elemek'] ?? null) ? $gyik['elemek'] : (array) $gyik, 'kategoria')) : [];

$funk = array_column($fajlok['funkciok.json'], null, 'kulcs');
$ipar = array_column($fajlok['iparagak.json'], null, 'kulcs');
T::igaz(count($funk) >= 40 && count($funk) <= 55, 'funkciók száma 40–55: ' . count($funk));
T::egyezik(['szerviz', 'ipar', 'kkv', 'zrt', 'allami-szektor', 'egeszsegugy', 'kereskedelem', 'egyeb'], array_keys($ipar), 'iparágak');

foreach ($funk as $k => $x) {
    foreach (['cim', 'rovid', 'leiras', 'ikon', 'modul', 'csoport', 'kep'] as $m) {
        T::igaz(trim((string) ($x[$m] ?? '')) !== '', "funkció $k: $m");
    }
    $e = (array) ($x['elonyok'] ?? []);
    T::igaz(count($e) >= 3 && count($e) <= 5, "funkció $k: 3–5 előny");
    foreach ((array) ($x['kapcsolodo'] ?? []) as $r) {
        T::igaz(isset($funk[$r]), "funkció $k: kapcsolódó létezik ($r)");
    }
    foreach ((array) ($x['gyik'] ?? []) as $g) {
        T::igaz(!$van_gyik || isset($gyik_k[$g]), "funkció $k: gyik-kulcs ($g)");
    }
}

foreach ($ipar as $k => $x) {
    foreach ((array) $x['funkciok'] as $r) {
        T::igaz(isset($funk[$r]), "iparág $k: funkció létezik ($r)");
    }
}

// --- Leckék ---
$leckek = $fajlok['oktatas.json'];
T::igaz(count($leckek) >= 20, 'legalább 20 lecke: ' . count($leckek));
$lmod = [];

foreach ($leckek as $l) {
    $lmod[(string) $l['modul']] = true;
    T::igaz(count((array) $l['lepesek']) >= 2 && (int) $l['ido_perc'] > 0 && trim((string) $l['cel']) !== '', 'lecke szerkezet: ' . $l['kulcs']);
    foreach ((array) $l['kviz'] as $q) {
        T::igaz(isset($q['valaszok'][(int) $q['helyes']]) && trim((string) $q['magyarazat']) !== '', 'kvíz helyes index: ' . $l['kulcs']);
    }
}

foreach (['alap', 'termekek', 'arukiadas', 'rendelesek', 'penztar', 'szamlazas', 'uzenetek', 'sms', 'levelezes', 'csapat', 'naptar', 'foglalas', 'asszisztens', 'nyomtatas', 'imei', 'tac', 'matrica', 'mockup'] as $m) {
    T::igaz(isset($lmod[$m]), 'van lecke a modulhoz: ' . $m);
}

// --- Telepítés ---
$tel = $fajlok['telepites.json'];
T::igaz(count(array_filter($tel, static fn ($e) => ($e['tipus'] ?? '') === 'ellenorzolista' && count((array) $e['elemek']) >= 5)) === 1, 'telepítés: egy ellenőrzőlista');
T::igaz(count(array_filter($tel, static fn ($e) => !empty($e['kovetelmenyek']))) >= 1, 'telepítés: követelmények');

// --- Oldalak ---
$oldalak = [];

foreach (glob($d . 'oldalak/*.json') ?: [] as $f) {
    [$a, $ok] = $json($f);
    T::igaz($ok && is_array($a), 'érvényes oldal-JSON: ' . basename($f));
    if (!is_array($a)) {
        continue;
    }
    $s = (string) ($a['slug'] ?? '');
    T::egyezik(basename($f, '.json'), $s, 'fájlnév = slug');
    T::igaz(trim((string) ($a['cim'] ?? '')) !== '', "$s: cím");
    $seo = (array) ($a['seo'] ?? []);
    $t   = SDH_Sales::t((string) ($seo['title'] ?? ''));
    $ds  = SDH_Sales::t((string) ($seo['description'] ?? ''));
    T::igaz($t !== '' && mb_strlen($t) <= 60, "$s: seo.title ≤ 60 (" . mb_strlen($t) . ')');
    T::igaz($ds !== '' && mb_strlen($ds) <= 155, "$s: seo.description ≤ 155 (" . mb_strlen($ds) . ')');
    T::igaz(trim((string) ($seo['kulcsszo'] ?? '')) !== '', "$s: kulcsszó");
    $h = 0;
    foreach ((array) ($a['szekciok'] ?? []) as $sz) {
        T::igaz(in_array($sz['tipus'] ?? '', $tipusok, true), "$s: ismert szekciótípus (" . ($sz['tipus'] ?? '?') . ')');
        $h += ($sz['tipus'] ?? '') === 'hero' && empty($sz['rejtett']) ? 1 : 0;
        if (($sz['tipus'] ?? '') === 'gyik') {
            foreach ((array) ($sz['kulcsok'] ?? []) as $g) {
                T::igaz(!$van_gyik || isset($gyik_k[$g]), "$s: gyik-kulcs ($g)");
            }
            if (!empty($sz['kategoria'])) {
                T::igaz(!$van_gyik || isset($gyik_kt[$sz['kategoria']]), "$s: gyik-kategória ({$sz['kategoria']})");
            }
        }
        foreach (['funkcio_racs' => $funk, 'iparag_racs' => $ipar] as $ty => $hal) {
            if (($sz['tipus'] ?? '') === $ty) {
                foreach ((array) ($sz['kulcsok'] ?? []) as $r) {
                    T::igaz(isset($hal[$r]), "$s: $ty kulcs ($r)");
                }
            }
        }
    }
    T::egyezik(1, $h, "$s: pontosan egy H1 (hero)");
    $oldalak[(empty($a['szulo']) ? '' : $a['szulo'] . '/') . $s] = $a;
}

T::igaz(count($oldalak) >= 80, 'oldalak száma: ' . count($oldalak));

foreach (array_keys($funk) as $k) {
    T::igaz(isset($oldalak['funkciok/' . $k]), 'funkció-oldal: ' . $k);
}
foreach (array_keys($ipar) as $k) {
    T::igaz(isset($oldalak['iparagak/' . $k]), 'iparág-oldal: ' . $k);
}
foreach (['kezdolap', 'funkciok', 'arak', 'modulok', 'iparagak', 'egyedi-modulfejlesztes', 'egyedi-szoftverfejlesztes', 'demo', 'telepites', 'oktatas', 'gyik', 'osszehasonlitas', 'esettanulmany', 'blog', 'kapcsolat', 'idopontfoglalas', 'rolunk', 'partnerprogram', 'statusz', 'fiok', 'kosar', 'penztar', 'kereses', 'adatvedelem-biztonsag', 'jogi'] as $k) {
    T::igaz(isset($oldalak[$k]), 'kötelező oldal: ' . $k);
}

// --- Menük ---
$jogi_slug = ['aszf', 'adatkezeles', 'suti-tajekoztato', 'impresszum', 'licencszerzodes', 'sla', 'adatfeldolgozasi-szerzodes', 'elallas-visszaterites', 'ai-chat-tajekoztato', 'demo-feltetelek'];
$menuk     = array_column($fajlok['menuk.json'], null, 'kulcs');
T::egyezik(['fo', 'lablec', 'jogi'], array_keys($menuk), 'menük');
$bejar = static function (array $elemek) use (&$bejar, $oldalak, $jogi_slug): void {
    foreach ($elemek as $e) {
        if (!empty($e['oldal'])) {
            $o = (string) $e['oldal'];
            $j = strpos($o, 'jogi/') === 0 && in_array(substr($o, 5), $jogi_slug, true);
            T::igaz(isset($oldalak[$o]) || $j, 'menü-slug létezik: ' . $o);
        }
        $bejar((array) ($e['gyerekek'] ?? []));
    }
};
foreach ($menuk as $m) {
    $bejar((array) $m['elemek']);
}

// --- Szöveg: tiltott szavak, beégetett név, lorem ---
foreach (array_merge(glob($d . 'oldalak/*.json') ?: [], array_map(static fn ($f) => $d . $f, array_keys($fajlok))) as $f) {
    T::igaz(preg_match($tilos, (string) file_get_contents($f)) !== 1, 'tiltott szó / beégetett név nincs: ' . basename($f));
}

// --- Betöltés ---
$db = SDH_Sales_Tartalom::betolt(false);
T::igaz((int) ($db['oldal'] ?? 0) >= count($oldalak), 'betöltött oldalak: ' . ($db['oldal'] ?? 0));
T::egyezik(count($funk), count(SDH_Sales_Tartalom::lista('funkcio')), 'betöltött funkciók');
T::egyezik(count($ipar), count(SDH_Sales_Tartalom::lista('iparag')), 'betöltött iparágak');
T::egyezik(count($leckek), count(SDH_Sales_Tartalom::lista('lecke')), 'betöltött leckék');
T::igaz(SDH_Sales_Tartalom::menu('fo') !== [] && SDH_Sales_Tartalom::menu('lablec') !== [] && SDH_Sales_Tartalom::menu('jogi') !== [], 'menük betöltve');

foreach (['funkciok/munkalapok', 'iparagak/szerviz', 'jogi', 'kezdolap'] as $u) {
    $p = get_page_by_path($u);
    T::igaz($p instanceof WP_Post && SDH_Sales_Tartalom::szekciok((int) $p->ID) !== [], 'oldal + szekciók: ' . $u);
}
$p = get_page_by_path('funkciok/munkalapok');
T::igaz($p && get_post_meta((int) $p->ID, 'rank_math_focus_keyword', true) === 'munkalap program', 'kulcsszó a metában');
T::egyezik((int) get_page_by_path('kezdolap')->ID, (int) get_option('page_on_front'), 'kezdőlap beállítva');

T::vege('t270 tartalom-adat');
