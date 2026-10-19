"""
t241 – téma böngészőben (Playwright, Chromium): képernyőképek asztali 1366 + mobil 390 szélességen, sötét + világos módban
(kezdőlap, funkció-oldal, árak, blogcikk, 404, tudásbázis-fejezet); programozott ellenőrzés: vízszintes túlcsordulás,
konzolhibák, 44 px-nél kisebb célterületek, mobil menü + Ctrl+K kereső + témaváltó működése; labor-mérés:
LCP / CLS (PerformanceObserver), átvitt CSS / JS / betű bájt.

Futtatás: python3 weboldal/tesztek/e2e/t241-tema-bongeszo.py http://127.0.0.1:8847/wp [kimeneti mappa]
Kimenet: egy összegző sor + a mérések JSON-ja (meresek.json) és a képek a kimeneti mappában.
"""
import json
import os
import sys

from playwright.sync_api import sync_playwright

ALAP = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8847/wp').rstrip('/')
KI = sys.argv[2] if len(sys.argv) > 2 else os.path.join(os.path.dirname(__file__), 'kepek-t241')
os.makedirs(KI, exist_ok=True)

OLDALAK = {
    'kezdolap': '/',
    'funkcio': '/funkciok/munkalapok/',
    'arak': '/arak/',
    'blogcikk': os.environ.get('T241_CIKK', '/szerviz-kpi-mutatok-riportok/'),
    '404': '/nincs-ilyen-oldal/',
    'tudasbazis': '/tudasbazis/elso-lepesek/',
}
NEZETEK = {'asztal': {'width': 1366, 'height': 900}, 'mobil': {'width': 390, 'height': 844}}

MERO = """
window.__lcp = 0; window.__cls = 0;
try {
  new PerformanceObserver(function (l) { l.getEntries().forEach(function (e) { window.__lcp = e.renderTime || e.loadTime || e.startTime; }); }).observe({type: 'largest-contentful-paint', buffered: true});
  new PerformanceObserver(function (l) { l.getEntries().forEach(function (e) { if (!e.hadRecentInput) { window.__cls += e.value; } }); }).observe({type: 'layout-shift', buffered: true});
} catch (e) {}
"""

TULCSORDUL = """() => {
  const w = document.documentElement.clientWidth;
  const ki = [];
  document.querySelectorAll('body *').forEach(el => {
    if (el.closest('.s-tabla-gorgeto, .tablazat, pre, .s-sr, .s-sprite, [hidden], .s-kereso, .s-almenu, .s-tudas__oldalsav')) return;
    const r = el.getBoundingClientRect();
    if (r.width && (r.right > w + 1 || r.left < -1)) {
      const st = getComputedStyle(el);
      if (st.position === 'fixed' || st.visibility === 'hidden') return;
      ki.push(el.tagName.toLowerCase() + '.' + (el.className && el.className.baseVal === undefined ? String(el.className).split(' ')[0] : '') + ' ' + Math.round(r.right - w));
    }
  });
  return { doc: document.documentElement.scrollWidth - w, elemek: ki.slice(0, 5) };
}"""

KICSI = """() => {
  const ki = [];
  document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button]').forEach(el => {
    if (el.closest('.s-proza, .s-sr, [hidden], .s-kereso, .s-morzsa, .s-kartya__szoveg, .s-tj, .s-forraslista, p, li > a.s-pill--kicsi')) return;
    const r = el.getBoundingClientRect();
    if (!r.width || !r.height) return;
    const st = getComputedStyle(el);
    if (st.visibility === 'hidden' || st.display === 'none') return;
    // A kártya-link célterülete a teljes kártya (::after) – a kártya méretét nézzük.
    const cel = el.classList.contains('s-kartya__link') ? el.closest('.s-kartya').getBoundingClientRect() : r;
    if (cel.height < 24 || cel.width < 24) ki.push((el.className || el.tagName) + ' ' + Math.round(cel.width) + 'x' + Math.round(cel.height));
  });
  return ki.slice(0, 8);
}"""


def main():
    hibak = []
    meres = {}
    with sync_playwright() as p:
        b = p.chromium.launch()
        for nezet, meret in NEZETEK.items():
            for mod in ('sotet', 'vilagos'):
                ctx = b.new_context(viewport=meret, device_scale_factor=1, color_scheme='dark' if mod == 'sotet' else 'light',
                                    is_mobile=nezet == 'mobil', has_touch=nezet == 'mobil', locale='hu-HU')
                ctx.add_init_script("try{localStorage.setItem('szinmod','%s')}catch(e){}" % mod)
                ctx.add_init_script(MERO)
                for nev, ut in OLDALAK.items():
                    lap = ctx.new_page()
                    konzol = []
                    lap.on('console', lambda m, k=konzol: k.append(m.text) if m.type == 'error' else None)
                    lap.on('pageerror', lambda e, k=konzol: k.append(str(e)))
                    bajt = {'css': 0, 'js': 0, 'font': 0, 'kep': 0, 'html': 0}
                    kulso = []

                    def valasz(r, bajt=bajt, kulso=kulso):
                        u = r.url
                        if not u.startswith(ALAP.split('/wp')[0]):
                            kulso.append(u)
                            return
                        try:
                            n = len(r.body())
                        except Exception:
                            return
                        t = r.request.resource_type
                        k = {'stylesheet': 'css', 'script': 'js', 'font': 'font', 'image': 'kep', 'document': 'html'}.get(t)
                        if k:
                            bajt[k] += n
                    lap.on('response', valasz)
                    v = lap.goto(ALAP + ut, wait_until='networkidle')
                    lap.wait_for_timeout(400)
                    kod = v.status if v else 0
                    vart = 404 if nev == '404' else 200
                    if kod != vart:
                        hibak.append(f'{nezet}/{mod}/{nev}: HTTP {kod}')
                    if lap.evaluate("document.documentElement.getAttribute('data-tema')") != mod:
                        hibak.append(f'{nezet}/{mod}/{nev}: data-tema nem {mod}')
                    t = lap.evaluate(TULCSORDUL)
                    if t['doc'] > 0 or t['elemek']:
                        hibak.append(f'{nezet}/{mod}/{nev}: vízszintes túlcsordulás {t}')
                    if konzol:
                        hibak.append(f'{nezet}/{mod}/{nev}: konzolhiba {konzol[:2]}')
                    if kulso:
                        hibak.append(f'{nezet}/{mod}/{nev}: külső kérés {kulso[:2]}')
                    if mod == 'sotet':
                        k = lap.evaluate(KICSI)
                        if k:
                            hibak.append(f'{nezet}/{nev}: 24 px alatti célterület {k}')
                    lap.mouse.wheel(0, 3000)
                    lap.wait_for_timeout(300)
                    lcp = lap.evaluate('window.__lcp')
                    cls = lap.evaluate('window.__cls')
                    meres[f'{nezet}/{mod}/{nev}'] = {'lcp_ms': round(lcp), 'cls': round(cls, 4), **bajt}
                    lap.evaluate('window.scrollTo(0,0)')
                    lap.wait_for_timeout(200)
                    lap.screenshot(path=os.path.join(KI, f'{nev}-{nezet}-{mod}.png'), full_page=nev in ('kezdolap', 'arak') and nezet == 'mobil' and mod == 'sotet')
                    lap.close()
                ctx.close()

        # Interakciók (mobil, sötét): menü, kereső, témaváltó
        ctx = b.new_context(viewport=NEZETEK['mobil'], is_mobile=True, has_touch=True, color_scheme='dark')
        lap = ctx.new_page()
        konzol = []
        lap.on('pageerror', lambda e: konzol.append(str(e)))
        lap.goto(ALAP + '/', wait_until='networkidle')
        lap.click('[data-menugomb]')
        if lap.get_attribute('[data-menugomb]', 'aria-expanded') != 'true' or not lap.is_visible('#fomenu'):
            hibak.append('mobil menü nem nyílik')
        lap.screenshot(path=os.path.join(KI, 'menu-mobil.png'))
        lap.keyboard.press('Escape')
        if lap.get_attribute('[data-menugomb]', 'aria-expanded') != 'false':
            hibak.append('mobil menü Esc-re nem zár')
        lap.click('[data-temavalto]')
        if lap.evaluate("document.documentElement.getAttribute('data-tema')") != 'vilagos':
            hibak.append('témaváltó nem vált')
        if lap.evaluate("localStorage.getItem('szinmod')") != 'vilagos':
            hibak.append('témaváltó nem tárol')
        lap.keyboard.press('Control+k')
        lap.wait_for_selector('[data-kereso]:not([hidden])', timeout=4000)
        lap.keyboard.type('munkalp')  # elírás
        lap.wait_for_timeout(900)
        n = lap.evaluate("document.querySelectorAll('#kereso-lista [role=option]').length")
        if n < 1:
            hibak.append('kereső: elírásra nincs találat')
        elso = lap.evaluate("(document.querySelector('#kereso-lista [role=option] .s-kt__cim')||{}).textContent||''")
        lap.screenshot(path=os.path.join(KI, 'kereso-mobil.png'))
        lap.keyboard.press('Escape')
        lap.wait_for_timeout(200)
        if not lap.evaluate("document.querySelector('[data-kereso]').hidden"):
            hibak.append('kereső Esc-re nem zár')
        lap.goto(ALAP + '/', wait_until='networkidle')
        lap.keyboard.press('/')
        lap.wait_for_selector('[data-kereso]:not([hidden])', timeout=4000)
        lap.keyboard.type('szamlazz')  # ékezet és nagybetű nélkül
        lap.wait_for_timeout(600)
        n2 = lap.evaluate("document.querySelectorAll('#kereso-lista [role=option]').length")
        if n2 < 1:
            hibak.append('kereső: ékezet nélküli keresésre nincs találat')
        if konzol:
            hibak.append(f'interakció: konzolhiba {konzol[:2]}')
        meres['kereso'] = {'munkalp_talalat': n, 'elso': elso, 'szamlazz_talalat': n2}

        # Árak: ciklusváltó
        lap.goto(ALAP + '/arak/', wait_until='networkidle')
        if lap.locator('.s-szegmens__cimke').count() >= 2:
            lap.locator('.s-szegmens__cimke').first.click()
            if lap.evaluate("document.querySelector('.s-arak__doboz').getAttribute('data-ciklus')") != 'havi':
                hibak.append('árak: ciklusváltó nem vált')
        ctx.close()
        b.close()

    with open(os.path.join(KI, 'meresek.json'), 'w', encoding='utf-8') as f:
        json.dump(meres, f, ensure_ascii=False, indent=1)
    lcp = [v['lcp_ms'] for k, v in meres.items() if 'lcp_ms' in v]
    cls = [v['cls'] for k, v in meres.items() if 'cls' in v]
    ok = 0 if hibak else 1
    print(f"t241 téma-böngésző: {'OK' if ok else 'HIBA'} – {len(OLDALAK) * 4} nézet, LCP max {max(lcp)} ms, CLS max {max(cls)}")
    for h in hibak[:25]:
        print('   ✗ ' + h)
    sys.exit(0 if ok else 1)


main()
