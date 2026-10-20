<?php
/**
 * t246 – skinek (SDH_Sales_Arculat::skinek, `arculat.skin` beállítás):
 * - hat skin, az alap („neon”) tokenlistái üresek → alapbeállítással a kimenet pontosan a régi (nincs arculat-CSS),
 * - minden másik skin mindkét módban teljes tokenkészletet ad; WCAG AA kontraszt: a három szín szövegként ≥ 4,5:1 a
 *   háttéren és a felületen, a kitöltésen a felirat ≥ 4,5:1,
 * - mentés / visszaállítás / ismeretlen kulcs, előnézet (nem mentett skin), a tokenenkénti felülírás a skin után jön,
 * - a CSS-ben nincs kitörésre alkalmas karakter.
 */
require __DIR__ . '/_keret.php';

$A = 'SDH_Sales_Arculat';

// Tiszta kiindulás: semmi arculat-beállítás.
$o = get_option(SDH_Sales::OPT, []);
foreach (array_keys(is_array($o) ? $o : []) as $k) {
    if (strpos((string) $k, 'arculat.') === 0) {
        unset($o[$k]);
    }
}
SDH_Sales::b_felulir(is_array($o) ? $o : []);

$sk = $A::skinek();
T::egyezik(['neon', 'ibolya', 'kobalt', 'smaragd', 'borostyan', 'magenta'], array_keys($sk), 'hat skin, az első az alap');
T::egyezik([], $sk['neon']['tokenek'], 'neon: nincs világos felülírás');
T::egyezik([], $sk['neon']['tokenek_sotet'], 'neon: nincs sötét felülírás');
T::egyezik('neon', $A::skin(), 'alap skin: neon');
T::egyezik('', $A::css(), 'alapbeállítással nincs arculat-CSS (a kinézet változatlan)');
T::egyezik('', $A::skin_css('neon'), 'a neon skin CSS-e üres');

/* --- Kontraszt ----------------------------------------------------------------------------------------------------- */

$lum = static function (string $hex): float {
    $h = ltrim($hex, '#');
    $c = [];

    foreach ([0, 2, 4] as $i) {
        $v   = hexdec(substr($h, $i, 2)) / 255;
        $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$arany = static function (string $a, string $b) use ($lum): float {
    $x = $lum($a);
    $y = $lum($b);

    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
};

$hatter = ['sotet' => ['#07080c', '#11131b', '#0c0e15'], 'vilagos' => ['#f4f5f9', '#ffffff', '#eceef5']];

foreach ($sk as $k => $s) {
    $kell = ['kiemelo', 'kiemelo-2', 'neon', 'kiemelo-felirat', 'kiemelo-halvany', 'atmenet', 'aurora-1', 'fokusz', 'ragyogas-kiemelo'];

    foreach (['tokenek' => 'vilagos', 'tokenek_sotet' => 'sotet'] as $mezo => $mod) {
        $t = $k === 'neon' ? null : $s[$mezo];

        if ($t !== null) {
            T::egyezik([], array_values(array_diff($kell, array_keys($t))), $k . ' (' . $mod . '): teljes tokenkészlet');
        }

        $szinek = $mod === 'sotet' ? $s['minta'] : $s['minta_vilagos'];
        $felirat = $mod === 'sotet' ? '#0a0b10' : '#ffffff';

        foreach ($szinek as $i => $sz) {
            foreach ($hatter[$mod] as $h) {
                $r = $arany($sz, $h);
                T::igaz($r >= 4.5, sprintf('%s (%s): %s szövegként a %s háttéren %.2f:1', $k, $mod, $sz, $h, $r));
            }

            $r = $arany($sz, $felirat);
            T::igaz($r >= 4.5, sprintf('%s (%s): felirat a %s kitöltésen %.2f:1', $k, $mod, $sz, $r));
        }
    }
}

/* --- Mentés, előnézet, felülírás ----------------------------------------------------------------------------------- */

$r = $A::ment(['skin' => 'kobalt']);
T::egyezik([], $r['hibas'], 'kobalt mentése hiba nélkül');
T::egyezik('kobalt', $A::skin(), 'a beállított skin: kobalt');
$css = $A::css();
T::tartalmaz($css, '--s-kiemelo:#60a5fa', 'kobalt: sötét kiemelő a CSS-ben');
T::tartalmaz($css, ':root[data-tema="vilagos"]{--s-kiemelo:#1d4ed8', 'kobalt: világos kiemelő');
T::tartalmaz($css, ':root:not([data-tema="sotet"]):not([data-tema="vilagos"])', 'rendszer-világos mód a téma szelektorával');
T::igaz(preg_match('#</|<!--|-->|[{};]\s*@import|url\(#i', $css) !== 1, 'nincs kitörésre alkalmas minta');

$all = $A::allapot();
T::egyezik('kobalt', $all['skin'], 'állapot: skin');
T::egyezik(6, count($all['skinek']), 'állapot: a skinek listája (Stúdió)');

$r = $A::ment(['skin' => 'nincsilyen']);
T::tartalmaz(implode(',', $r['hibas']), 'skin', 'ismeretlen skin: hiba');
T::egyezik('kobalt', $A::skin(), 'ismeretlen skinnél a régi marad');

$elo = $A::css($A::terkep(['skin' => 'magenta']));
T::tartalmaz($elo, '#f472b6', 'előnézet: a nem mentett skin színe');
T::nem_tartalmaz($elo, '#60a5fa', 'előnézet: a mentett skin nem keveredik bele');

$A::ment(['skin' => 'kobalt', 'ertekek' => ['kiemelo' => ['#123456', '']]]);
$css = $A::css();
T::igaz(strpos($css, '#123456') !== false && strpos($css, '#123456') > strpos($css, '#60a5fa'), 'a tokenenkénti felülírás a skin után jön (az nyer)');
$A::ment(['ertekek' => ['kiemelo' => ['', '']]]);

$A::ment(['skin' => 'neon']);
T::egyezik('neon', $A::skin(), 'visszaállítás az alapra');
T::egyezik('', $A::css(), 'az alap skinnel ismét nincs arculat-CSS');
$m = get_option(SDH_Sales::OPT, []);
T::igaz(!array_key_exists('arculat.skin', is_array($m) ? $m : []), 'az alap skin nem marad a beállításban');

T::vege('t246 skinek');
