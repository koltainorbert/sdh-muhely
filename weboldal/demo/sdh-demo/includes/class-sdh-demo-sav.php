<?php
/**
 * A „BEMUTATÓ” sáv a műhely felületének alján: visszaszámlálás a következő visszaállításig, a hátralévő új-rekord
 * keret, link a Kimenő üzenetekre és az értékesítő webhelyre. Akadálymentes (régió + élő terület), saját semleges
 * azonosítókkal (bm-*), a keret minden mentés után frissül (`?bemutato=allapot`).
 *
 * @package SDH_Demo
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Demo_Sav
{
    public static function init(): void
    {
        add_action('sdh_muhely_felulet_vege', [self::class, 'kiir']);
    }

    /** @return array<string, mixed> */
    public static function adatok(): array
    {
        $id = get_current_user_id();

        return [
            'keret'         => SDH_Demo_Keret::maradek(),
            'max'           => (int) SDH_Demo::b('keret'),
            'visszaallitas' => SDH_Demo::kovetkezo_visszaallitas(),
            'lejar'         => $id > 0 ? (int) get_user_meta($id, SDH_Demo::META_LEJAR, true) : 0,
            'most'          => time(),
        ];
    }

    public static function kiir(): void
    {
        if (!SDH_Demo::demo_felhasznalo() && !current_user_can('manage_options')) {
            return;
        }

        echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput -- a html() escapel.
    }

    /** Hátralévő idő magyarul: „4 óra 12 perc”. */
    public static function hatra(int $mp): string
    {
        $mp = max(0, $mp);
        $o  = intdiv($mp, 3600);
        $p  = intdiv($mp % 3600, 60);

        return $o > 0 ? $o . ' óra ' . $p . ' perc' : max(1, $p) . ' perc';
    }

    public static function html(): string
    {
        $a        = self::adatok();
        $kimeno   = SDH_Muhely_Modulok::frontend_url(SDH_Demo_Kimeno::KULCS);
        $elado    = (string) SDH_Demo::b('ertekesito_url');
        $allapot  = add_query_arg(SDH_Demo_Inditas::VALTOZO, 'allapot', home_url('/'));
        $ora      = wp_date('H:i', (int) $a['visszaallitas']);

        $h = '<style>'
            . ':root{--bm-sav-bg:#f5b301;--bm-sav-szoveg:#1d1a10;--bm-sav-link:#1d1a10;--bm-sav-pill:rgba(29,26,16,.12);--bm-sav-mag:48px}'
            . '.bm-sav{position:fixed;left:0;right:0;bottom:0;z-index:99990;min-height:var(--bm-sav-mag);display:flex;flex-wrap:wrap;align-items:center;gap:6px 14px;'
            . 'padding:6px 14px;background:var(--bm-sav-bg);color:var(--bm-sav-szoveg);font:600 14px/1.35 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
            . 'box-shadow:0 -2px 10px rgba(0,0,0,.18)}'
            . '.bm-sav__jel{letter-spacing:.1em;font-weight:800;padding:3px 10px;border-radius:999px;background:var(--bm-sav-szoveg);color:var(--bm-sav-bg)}'
            . '.bm-sav__adat{display:inline-flex;gap:6px;align-items:center;padding:3px 10px;border-radius:999px;background:var(--bm-sav-pill)}'
            . '.bm-sav__adat b{font-variant-numeric:tabular-nums}'
            . '.bm-sav__linkek{display:inline-flex;gap:8px;margin-left:auto;flex-wrap:wrap}'
            . '.bm-sav a{color:var(--bm-sav-link);text-decoration:underline;text-underline-offset:3px;padding:3px 10px;border-radius:999px}'
            . '.bm-sav a.bm-sav__fo{background:var(--bm-sav-szoveg);color:var(--bm-sav-bg);text-decoration:none}'
            . '.bm-sav a:focus-visible{outline:3px solid var(--bm-sav-szoveg);outline-offset:2px}'
            . '.bm-sav__keret--nulla{background:#7a1f12;color:#fff}'
            . 'body{padding-bottom:var(--bm-sav-mag)}'
            // A jobb alsó sarokban a felület saját lebegő gombja (asszisztens) ül: oda nem kerül tartalom.
            . '@media (min-width:641px){.bm-sav{padding-right:104px}}'
            . '@media (max-width:640px){.bm-sav{font-size:13px;gap:4px 8px;padding:6px 10px}.bm-sav__linkek{margin-left:0}.bm-sav__szoveg{display:none}}'
            . '@media print{.bm-sav{display:none}body{padding-bottom:0}}'
            . '</style>';

        $h .= '<section class="bm-sav" role="region" aria-label="Bemutató tájékoztató" data-bm-sav'
            . ' data-bm-allapot="' . esc_url($allapot) . '" data-bm-cel="' . (int) $a['visszaallitas'] . '" data-bm-most="' . (int) $a['most'] . '">'
            . '<span class="bm-sav__jel">BEMUTATÓ</span>'
            . '<span class="bm-sav__szoveg">Kitalált adatok – semmi nem megy ki.</span>'
            . '<span class="bm-sav__adat" title="Ekkor minden adat visszaáll az eredeti állapotra">Visszaállítás ' . esc_html($ora) . '-kor, még '
            . '<b data-bm-hatra>' . esc_html(self::hatra((int) $a['visszaallitas'] - (int) $a['most'])) . '</b></span>'
            . '<span class="bm-sav__adat' . ((int) $a['keret'] === 0 ? ' bm-sav__keret--nulla' : '') . '" data-bm-keret-doboz aria-live="polite">'
            . 'Még <b data-bm-keret>' . (int) $a['keret'] . '</b> / ' . (int) $a['max'] . ' új rekord ma</span>'
            . '<span class="bm-sav__linkek">'
            . '<a href="' . esc_url($kimeno) . '">Kimenő üzenetek</a>'
            . ($elado !== '' ? '<a class="bm-sav__fo" href="' . esc_url($elado) . '" target="_blank" rel="noopener">Megrendelem</a>' : '')
            . '</span></section>';

        $h .= '<script>(function(){'
            . 'var s=document.querySelector("[data-bm-sav]");if(!s||s.getAttribute("data-bm-kesz")){return;}s.setAttribute("data-bm-kesz","1");'
            . 'var cel=+s.getAttribute("data-bm-cel"),elter=(+s.getAttribute("data-bm-most"))*1000-Date.now(),hatra=s.querySelector("[data-bm-hatra]"),'
            . 'keret=s.querySelector("[data-bm-keret]"),doboz=s.querySelector("[data-bm-keret-doboz]"),url=s.getAttribute("data-bm-allapot"),idoz=null;'
            . 'function ido(mp){mp=Math.max(0,mp|0);var o=Math.floor(mp/3600),p=Math.floor(mp%3600/60);return o>0?o+" óra "+p+" perc":Math.max(1,p)+" perc";}'
            . 'function lep(){hatra.textContent=ido(cel-(Date.now()+elter)/1000);}'
            . 'function frissit(){fetch(url,{credentials:"same-origin",headers:{"Accept":"application/json"}}).then(function(r){return r.ok?r.json():null;}).then(function(a){'
            . 'if(!a){return;}keret.textContent=a.keret;doboz.classList.toggle("bm-sav__keret--nulla",+a.keret===0);if(a.visszaallitas){cel=+a.visszaallitas;}lep();}).catch(function(){});}'
            . 'var f=window.fetch;if(f){window.fetch=function(c,o){var p=f.apply(this,arguments);if(o&&o.method&&String(o.method).toUpperCase()==="POST"&&String(c).indexOf(url)<0){'
            . 'p.then(function(){clearTimeout(idoz);idoz=setTimeout(frissit,500);},function(){});}return p;};}'
            . 'document.addEventListener("submit",function(){clearTimeout(idoz);idoz=setTimeout(frissit,1500);},true);'
            . 'lep();setInterval(lep,30000);setInterval(frissit,120000);'
            . '}());</script>';

        return $h;
    }

    /** `?bemutato=allapot` – a sáv adatai JSON-ban (bejelentkezett bemutató-felhasználónak). */
    public static function allapot_json(): void
    {
        nocache_headers();

        if (!SDH_Demo::demo_felhasznalo() && !current_user_can('manage_options')) {
            status_header(403);
            wp_send_json(['uzenet' => 'Nincs bemutató munkamenet.'], 403);
        }

        wp_send_json(self::adatok());
    }
}
