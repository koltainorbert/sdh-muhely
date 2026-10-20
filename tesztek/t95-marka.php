<?php
/**
 * t95 – Márka (0.99.5): a termék neve beállítható, a vevői csomag márkasemleges és licenc-teljes.
 *
 * - Beállítások › Logó és megjelenés › Termék neve: belső példányban üresen a régi név, vevőnél „Munkora”;
 *   mentés, a felirat (bal felső sarok, böngészőfül, belépő oldal, Névjegy) és a súgó {termeknev} jele követi.
 * - Az asszisztens rendszerüzenete, a Drive-mentés mappája, a PDF-ek készítője a termék nevét viszi.
 * - A vevői zip (tools/vevo-csomag.sh, a munkafából): benne a LICENSE (GNU GPL v2), a fejléc licencsora és a
 *   terméknév; nincs benne includes/sdh/, tools/, README.md, 27-es súgó; a kiszolgált fájlokban (JS, CSS, a PHP
 *   szövegei és kiírt HTML-je) és a nem fejlesztőnek szóló súgófejezetekben nincs „SDH” (a kódazonosítók maradnak).
 */

require __DIR__ . '/_keret.php';

$A      = 'SDH_Muhely_Arculat';
$S      = 'SDH_Muhely_Sugo';
$gyoker = rtrim(SDH_MUHELY_DIR, '/');
$regi   = get_option('sdh_muhely_arculat');
$vevo   = static fn (): array => [];

/* --- 1. A termék neve: alapértékek ------------------------------------------------- */

delete_option('sdh_muhely_arculat');
T::igaz($A::belso(), 'a repóban a belső példány fut (includes/sdh/data/termek.json)');
T::egyezik('SDH Műhely', $A::termeknev(), 'belső példány: üresen a régi név marad');
T::egyezik('SDH Műhely', $A::rendszer_nev(), 'belső példány: a rendszer neve sem változik');
T::egyezik('SDH', $A::rovidnev(), 'belső példány: a szerepnevek rövid neve a régi');
T::egyezik('SDH Központ', $A::kozpontnev(), 'belső példány: a központ neve a régi');

add_filter('sdh_muhely_belso_termek', $vevo);
T::igaz(!$A::belso(), 'vevői példány (a belső adat nélkül)');
T::egyezik('Munkora', $A::termeknev(), 'vevői példány: a termék neve alapból Munkora');
T::egyezik('Munkora', $A::rendszer_nev(), 'vevői példány: jel és név nélkül a rendszer neve a termék neve');
T::egyezik(['', ''], [$A::beallitas()['jel'], $A::beallitas()['nev']], 'vevői példány: nincs gyári jel és név');
T::egyezik('Licencközpont', $A::kozpontnev(), 'vevői példány: a központ neve semleges');
T::egyezik('Munkora', $A::rovidnev(), 'vevői példány: a szerepnevek a termék nevét viszik');
T::egyezik(['az', 'a', 'az', 'a', 'az'], [$A::nevelo('SDH Műhely'), $A::nevelo('Munkora'), $A::nevelo('Ezermester'), $A::nevelo('CRM'), $A::nevelo('MX Szerviz')], 'a névelő a névhez igazodik');

/* --- 2. Mentés a Beállításokból, és amit a név átír ----------------------------------- */

$ment = static fn (array $mezok): array => t_ajax('sdh_muhely_beallitasok_ment', $mezok + ['ful' => 'arculat', 'tema' => 'rendszer', 'szin' => ''], 'sdh_nonce', 'sdh_muhely_beallitasok');

$r = $ment(['arculat_jel' => '', 'arculat_nev' => '', 'arculat_termeknev' => '  Szervizpult <b>Pro</b>  ']);
T::igaz(!empty($r['success']), 'a Logó és megjelenés lapfül menthető');
T::egyezik('Szervizpult Pro', $A::termeknev(), 'a termék neve elmentve (címke és szóköz nélkül)');
T::egyezik('Szervizpult Pro', $A::rendszer_nev(), 'a látható cím a termék neve (jel és név üres)');

$r = $ment(['arculat_jel' => '', 'arculat_nev' => '']);
T::egyezik('Szervizpult Pro', $A::termeknev(), 'a mező nélküli mentés nem törli a termék nevét');

$r = $ment(['arculat_jel' => '', 'arculat_nev' => '', 'arculat_termeknev' => str_repeat('x', 60)]);
T::egyezik(40, mb_strlen($A::termeknev()), 'a termék neve legfeljebb 40 karakter');
$ment(['arculat_jel' => '', 'arculat_nev' => '', 'arculat_termeknev' => 'Szervizpult Pro']);

// Beállítások-mező.
ob_start();
$A::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::tartalmaz($doboz, 'name="arculat_termeknev"', 'a Termék neve mező a Logó és megjelenés lapfülön');
T::tartalmaz($doboz, 'placeholder="Munkora"', 'a mező üres értéke vevőnél: Munkora');

// A műhely-felület címe (böngészőfül + bal felső sarok).
ob_start();
SDH_Muhely_Frontend::keret('Próba', 'attekintes', static function (): void {
    echo '<p>tartalom</p>';
});
$keret = (string) ob_get_clean();
T::tartalmaz($keret, '<title>Próba – Szervizpult Pro</title>', 'a böngészőfül címe a termék nevét viszi');
T::tartalmaz($keret, '<span class="sdh-sav__nev">Szervizpult Pro</span>', 'a bal felső sarokban a termék neve áll');
T::tartalmaz($keret, '"termeknev":"Szervizpult Pro"', 'a szkriptek is megkapják a termék nevét');

// Belépő oldal: üres címsorral a rendszer neve.
$belepo = SDH_Muhely_Rejtes::belepo_html('', '', '');
T::tartalmaz($belepo, 'Belépés – Szervizpult Pro', 'a belépő oldal címe a termék neve');

// Névjegy (Beállítások › Rendszer).
ob_start();
SDH_Muhely_Beallitasok::rendszer_doboz();
$nevjegy = (string) ob_get_clean();
T::tartalmaz($nevjegy, '<tr><th>Termék</th><td>Szervizpult Pro</td></tr>', 'a Névjegy a termék nevét mutatja');

// Súgó: a {termeknev} jel a beállított nevet kapja.
$S::urit();
$mentes = $S::fejezet('mentes-biztonsag');
$sz     = $mentes ? implode("\n", array_map(static fn (array $s): string => (string) $s['torzs'], (array) $mentes['szakaszok'])) : '';
T::tartalmaz($sz, '**Szervizpult Pro mentés**', 'a súgó a termék nevét írja ({termeknev})');
T::nem_tartalmaz($sz, '{termeknev}', 'a súgóban nem marad cseretoken');
$mj = $S::fejezet('megjelenes');
T::tartalmaz((string) wp_json_encode($mj, JSON_UNESCAPED_UNICODE), 'most **Szervizpult Pro**', 'a Megjelenés fejezet a mostani termék nevét mutatja');

// Asszisztens, Drive-mappa, frissítés, mérés.
delete_transient('sdh_muhely_ai_statikus');
delete_transient('sdh_muhely_ai_statikus_f');
$st = SDH_Muhely_Asszisztens_Tudas::statikus();
T::tartalmaz($st, 'a Szervizpult Pro beépített AI-asszisztense', 'az asszisztens a termék nevén mutatkozik be');
T::nem_tartalmaz($st, 'SDH Műhely', 'az asszisztens rendszerüzenetében nincs a régi név');
T::egyezik('Szervizpult Pro mentés', SDH_Muhely_Mentes_Gdrive::mappa_nev(), 'a Drive-mentés mappája a termék nevét viszi');
T::tartalmaz(implode("\n", SDH_Muhely_Meres::futtat()), 'Szervizpult Pro mérés (csak olvas)', 'a mérés kimenete a termék nevét viszi');

// Szerepnevek vevőnél.
T::egyezik('Szervizpult Pro adminisztrátor', $A::rovidnev() . ' adminisztrátor', 'a szerepnév vevőnél a termék nevét viszi');

remove_filter('sdh_muhely_belso_termek', $vevo);
update_option('sdh_muhely_arculat', ['termeknev' => '']);
T::egyezik('SDH Műhely', $A::termeknev(), 'belső példányban az üres mező a régi nevet adja vissza');
$S::urit();

/* --- 3. A vevői csomag ------------------------------------------------------------- */

$tmp = trailingslashit(get_temp_dir()) . 't95-' . wp_generate_password(8, false);
wp_mkdir_p($tmp);
$zip = $tmp . '/vevo.zip';
exec('sh ' . escapeshellarg($gyoker . '/tools/vevo-csomag.sh') . ' ' . escapeshellarg($zip) . ' --munkafa 2>&1', $ki, $kod);
T::egyezik(0, $kod, 'a vevői csomag elkészül (' . trim(implode(' ', $ki)) . ')');

$z  = new ZipArchive();
$ok = is_file($zip) && $z->open($zip) === true;
T::igaz($ok, 'a vevői zip megnyitható');

$nevek = [];
for ($i = 0; $ok && $i < $z->numFiles; $i++) {
    $nevek[] = (string) $z->getNameIndex($i);
}

$licenc = $ok ? (string) $z->getFromName('sdh-muhely/LICENSE') : '';
T::tartalmaz($licenc, 'GNU GENERAL PUBLIC LICENSE', 'a vevői zipben ott a LICENSE');
T::tartalmaz($licenc, 'Version 2, June 1991', 'a LICENSE a GPL v2 teljes szövege');
T::igaz(strlen($licenc) > 17000 && $licenc === (string) file_get_contents($gyoker . '/LICENSE'), 'a LICENSE teljes és azonos a repóéval');

$fo = $ok ? (string) $z->getFromName('sdh-muhely/sdh-muhely.php') : '';
T::igaz(preg_match('/^ \* License:\s+GPL-2\.0-or-later$/m', $fo) === 1, 'a fejlécben: License: GPL-2.0-or-later');
T::igaz(preg_match('#^ \* License URI:\s+https://www\.gnu\.org/licenses/old-licenses/gpl-2\.0\.html$#m', $fo) === 1, 'a fejlécben: License URI');
T::igaz(preg_match('/^ \* Plugin Name:\s+Munkora$/m', $fo) === 1, 'a vevői fejlécben a termék neve áll');
T::igaz(preg_match('/^ \* Version:\s+' . preg_quote(SDH_MUHELY_VERSION, '/') . '$/m', $fo) === 1, 'a vevői fejléc verziója a mostani');

$tilos = array_values(array_filter($nevek, static fn (string $n): bool => (bool) preg_match('#^sdh-muhely/(includes/sdh/|tools/|tesztek/|kozpont/|weboldal/|tesztadat/|README\.md$|sugo/27-sdh-belso\.md$)#', $n)));
T::egyezik([], $tilos, 'a vevői zipben nincs belső rész, eszköz, teszt, verziónapló');
T::igaz(count($nevek) > 150, 'a vevői zip fájljainak száma (' . count($nevek) . ')');

// Látható szövegek: JS, CSS, JSON, SVG, TXT teljes egészében; PHP-ben a szövegek és a kiírt HTML (a kódazonosítók nem).
$sdh     = '/(?<![\w-])SDH(?![_\w])|SDH-(?!Verzio\b|Oldalak\b)/';
$talalat = [];
$vizsgalt = 0;

foreach ($nevek as $n) {
    if (substr($n, -1) === '/' || !preg_match('/\.(php|js|css|json|txt|svg|html|md)$/', $n)) {
        continue;
    }

    $t = (string) $z->getFromName($n);
    $vizsgalt++;

    if (substr($n, -4) === '.php') {
        foreach (token_get_all($t) as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true) && preg_match($sdh, $tk[1]) === 1) {
                $talalat[] = $n . ':' . $tk[2];
            }
        }
        continue;
    }

    if (substr($n, -3) === '.md') {
        // Súgó: a fejlesztőnek szóló részek (fejezet / szakasz / alcím) kívül esnek.
        if (preg_match('/^csak:\s*fejleszto\s*$/m', $t) === 1) {
            continue;
        }
        $t = (string) preg_replace('/^(#{2,3}) [^\n]*\[fejlesztő\][^\n]*\n.*?(?=^#{2,3} (?![^\n]*\[fejlesztő\])|\z)/msu', '', $t);
    }

    // A HTTP-fejléc nevek (X-SDH-…) és a sdh- / data-sdh azonosítók kódazonosítók; ezeket a regex nem fogja.
    if (preg_match_all($sdh, $t, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $x) {
            $talalat[] = $n . ': ' . substr($t, max(0, $x[1] - 30), 70);
        }
    }
}

if ($ok) {
    $z->close();
}

T::igaz($vizsgalt > 150, 'vizsgált vevői fájlok (' . $vizsgalt . ')');
T::egyezik([], array_slice($talalat, 0, 15), 'a vevői csomag látható szövegeiben és a nem fejlesztőnek szóló súgóban nincs „SDH”');

// A licencoldal helyőrzője semleges.
ob_start();
SDH_Muhely_Licenc::beallitas_doboz();
$lap = (string) ob_get_clean();
T::tartalmaz($lap, 'placeholder="XXXX-XXXX-XXXX-XXXX"', 'a licenckulcs helyőrzője semleges');

array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

/* --- 4. Visszaállítás --------------------------------------------------------------- */

if (is_array($regi)) {
    update_option('sdh_muhely_arculat', $regi);
} else {
    delete_option('sdh_muhely_arculat');
}

T::vege('t95 márka: terméknév, vevői csomag LICENSE-szel, SDH-mentes feliratok');
