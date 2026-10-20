#!/usr/bin/env python3
"""
Licencközpont-panelek (Licencek, Telepítések, Kiadások) – vizuális QA bejáró. Ugyanazokkal a mérőkkel, mint az
admin-vizualis.py (a MERO / FOKUSZ_JS onnan töltődik): túlcsordulás, tábla, átfedés, levágás, popup (1366×768-on
görgetés nélkül), ív, ikon, kapcsoló, kontraszt, téma, igazítás, célterület, fókusz, konzol.

  python3 weboldal/tesztek/e2e/licenc-vizualis.py http://127.0.0.1:8903/wp --belepo=iroda-xxxx [kimeneti mappa] [--gyors] [--kep]
A tesztoldalon legyen néhány licenc, telepítés és kiadás. A bejáró nem ment semmit (a popupokat Mégse / Esc zárja).
"""
import json
import os
import re
import sys
import time

from playwright.sync_api import sync_playwright

ARGS = sys.argv[1:]
ALAP = next((a for a in ARGS if a.startswith('http')), 'http://127.0.0.1:8903/wp').rstrip('/')
KI = next((a for a in ARGS if not a.startswith('-') and not a.startswith('http')), '/tmp/licenc-vqa')
GYORS = '--gyors' in ARGS
KEP = '--kep' in ARGS
BELEPO = next((a.split('=', 1)[1] for a in ARGS if a.startswith('--belepo=')), 'wp-login.php')
FELH = os.environ.get('VQA_FELHASZNALO', 'admin')
JELSZO = os.environ.get('VQA_JELSZO', 'Admin123!teszt')
ADMIN = ALAP + '/wp-admin/admin.php?page=ertekesites'
MERETEK = [(1366, 768), (390, 844)] if GYORS else [(1366, 768), (1024, 768), (1600, 900), (1920, 1080), (390, 844)]
MODOK = ['sotet', 'vilagos']
os.makedirs(KI, exist_ok=True)

_forras = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'admin-vizualis.py'), encoding='utf-8').read()
MERO = re.search(r'^MERO = r"""(.*?)"""', _forras, re.S | re.M).group(1)
FOKUSZ_JS = re.search(r'^FOKUSZ_JS = r"""(.*?)"""', _forras, re.S | re.M).group(1)


def var(pg, ms=250):
    try:
        pg.wait_for_function("() => !document.querySelector('[data-tartalom] .sa-csontvaz') && document.querySelector('[data-tartalom]') && document.querySelector('[data-tartalom]').children.length", timeout=15000)
    except Exception:
        pass
    try:
        pg.wait_for_load_state('networkidle', timeout=5000)
    except Exception:
        pass
    pg.wait_for_timeout(ms)


def hash_(pg, h):
    pg.evaluate("h => { if (location.hash === h) window.dispatchEvent(new HashChangeEvent('hashchange')); else location.hash = h; }", h)
    var(pg)


def popup_bezar(pg):
    for _ in range(4):
        if not pg.query_selector('.sa-popup-hatter, .sa-naptar'):
            return
        pg.keyboard.press('Escape')
        pg.wait_for_timeout(120)
    pg.evaluate("() => document.querySelectorAll('.sa-popup-hatter, .sa-naptar').forEach((x) => x.remove())")


def bejar(pg, w, h, mod, jegyez):
    n = [0]
    nev0 = f'{w}/{mod}'

    def meres(allapot, scope='#sa-app', popup=False):
        n[0] += 1
        a = f'{nev0} {allapot}'
        pg.evaluate("() => Promise.race([Promise.all(document.getAnimations().filter((x) => x.effect && isFinite(x.effect.getComputedTiming().endTime)).map((x) => x.finished.catch(() => {}))), new Promise((r) => setTimeout(r, 1500))])")
        r = pg.evaluate(MERO, [scope, {'w': w, 'mod': mod, 'popup': popup}])
        for k, l in r.items():
            jegyez(k, a, l)
        if popup:
            pr = pg.evaluate("""() => { const hs = [...document.querySelectorAll('.sa-popup-hatter')]; const p = hs.length && hs[hs.length - 1].querySelector('.sa-popup'); if (!p) return null; const t = p.querySelector('.sa-popup__test'); const r = p.getBoundingClientRect(); return { test: t ? [t.scrollHeight, t.clientHeight] : [0, 0], top: r.top, bottom: r.bottom, left: r.left, right: r.right }; }""")
            if pr:
                if h <= 800 and w >= 1024 and pr['test'][0] > pr['test'][1] + 1:
                    jegyez('popup', a, [f'a popup törzse görget ({pr["test"][0]} > {pr["test"][1]} px)'])
                if pr['top'] < -0.5 or pr['bottom'] > h + 0.5 or pr['left'] < -0.5 or pr['right'] > w + 0.5:
                    jegyez('popup', a, [f'a popup kilóg a nézetből ({round(pr["top"])}…{round(pr["bottom"])})'])
        if KEP:
            fn = re.sub(r'[^a-z0-9]+', '-', allapot.lower()).strip('-')[:60]
            pg.screenshot(path=os.path.join(KI, f'{fn}-{w}-{mod}.png'))

    def fulek(allapot):
        db = pg.evaluate("() => { const r = [...document.querySelectorAll('.sa-popup-hatter')].pop(); return r ? r.querySelectorAll('.sa-fulek [role=tab]').length : 0; }")
        for i in range(1, db):
            pg.evaluate("i => { const r = [...document.querySelectorAll('.sa-popup-hatter')].pop(); const t = r.querySelectorAll('.sa-fulek [role=tab]')[i]; if (t && t.offsetParent) t.click(); }", i)
            pg.wait_for_timeout(150)
            meres(f'{allapot} ›fül {i + 1}', '.sa-popup-hatter', popup=True)

    def fokusz(allapot, db=30):
        if w != 1366:
            return
        pg.evaluate("() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); }")
        rossz = []
        for _ in range(db):
            pg.keyboard.press('Tab')
            pg.wait_for_timeout(200)
            r = pg.evaluate(FOKUSZ_JS)
            if r and not r['latszik']:
                rossz.append(r['nev'])
        jegyez('fokusz', f'{nev0} {allapot}', sorted(set(rossz)))

    def popup(katt, allapot, tabok=True, utana=None):
        pg.click(katt)
        pg.wait_for_selector('.sa-popup', timeout=10000)
        pg.wait_for_timeout(300)
        meres(allapot, '.sa-popup-hatter', popup=True)
        if tabok:
            fulek(allapot)
        if utana:
            utana()

    def lepes(nev, fn):
        try:
            fn()
        except Exception as x:
            jegyez('allapot', f'{nev0} {nev}', ['elakadt: ' + str(x).splitlines()[0][:140]])
            try:
                popup_bezar(pg)
            except Exception:
                pass

    def licencek():
        hash_(pg, '#/licencek')
        meres('Licencek')
        fokusz('Licencek')
        pg.click('[data-szuro] [data-ertek="lejar"]')
        var(pg, 200)
        meres('Licencek – hamarosan lejár')
        pg.click('[data-szuro] [data-ertek="mind"]')
        var(pg, 200)

        def reszlet_al():
            for g, nev in [('[data-m="hosszabbit"]', 'Hosszabbítás'), ('[data-m="csomag"]', 'Csomagváltás'), ('[data-m="kuld"]', 'Kulcs küldése'), ('[data-m="szerk"]', 'Szerkesztés')]:
                pg.click('.sa-popup-hatter >> nth=-1 >> ' + g)
                pg.wait_for_timeout(300)
                meres(f'Licenc › {nev} popup', '.sa-popup-hatter', popup=True)
                if nev == 'Szerkesztés':
                    fulek('Licenc › Szerkesztés')
                pg.keyboard.press('Escape')
                pg.wait_for_timeout(200)
            g = pg.query_selector('.sa-popup-hatter >> nth=-1 >> [data-m="tilt"]')
            if g:
                g.click()  # kétlépcsős: csak élesít
                pg.wait_for_timeout(150)
                meres('Licenc › élesített felfüggesztés', '.sa-popup-hatter', popup=True)
                pg.mouse.click(5, 5)

        popup('[data-licenc] td:nth-child(3)', 'Licenc részletek', True, reszlet_al)
        popup_bezar(pg)
        popup('[data-uj]', 'Új licenc popup', False)
        pg.click('.sa-popup-hatter [data-szegmens] [data-ertek="uj"]')
        pg.wait_for_timeout(200)
        meres('Új licenc – új vevő', '.sa-popup-hatter', popup=True)
        popup_bezar(pg)

    def telepitesek():
        hash_(pg, '#/telepitesek')
        meres('Telepítések')

        def al():
            pg.evaluate("() => { const r = [...document.querySelectorAll('.sa-popup-hatter')].pop(); r.querySelectorAll('.sa-fulek [role=tab]')[1].click(); }")
            pg.wait_for_timeout(150)
            b = pg.query_selector('.sa-popup-hatter >> nth=-1 >> [data-muv="uzenet"]:not([disabled])')
            if b:
                b.click()
                pg.wait_for_timeout(300)
                meres('Telepítés › Üzenet popup', '.sa-popup-hatter', popup=True)
                pg.keyboard.press('Escape')
                pg.wait_for_timeout(200)

        if pg.query_selector('[data-peldany]'):
            popup('[data-peldany] td:nth-child(3)', 'Telepítés részletek', True, al)
            popup_bezar(pg)

    def kiadasok():
        hash_(pg, '#/kiadasok')
        meres('Kiadások')

    for nev, fn in [('licencek', licencek), ('telepitesek', telepitesek), ('kiadasok', kiadasok)]:
        lepes(nev, fn)
    return n[0]


def main():
    hibak = {}
    konzol = []
    ossz_allapot = 0
    kezd = time.time()

    def jegyez(k, a, l):
        for x in l:
            hibak.setdefault(k, []).append(f'{a}: {x}')

    with sync_playwright() as p:
        b = p.chromium.launch()
        ctx0 = b.new_context()
        pg = ctx0.new_page()
        pg.goto(f'{ALAP}/{BELEPO}', wait_until='domcontentloaded')
        pg.fill('#user_login', FELH)
        pg.fill('#user_pass', JELSZO)
        pg.click('#wp-submit')
        pg.wait_for_load_state('domcontentloaded')
        allapot = os.path.join(KI, '_suti.json')
        ctx0.storage_state(path=allapot)
        ctx0.close()
        for (w, h) in MERETEK:
            for mod in MODOK:
                if w == 390 and mod == 'vilagos' and not GYORS:
                    continue
                ctx = b.new_context(viewport={'width': w, 'height': h}, storage_state=allapot, color_scheme='dark' if mod == 'sotet' else 'light')
                pg = ctx.new_page()
                pg.on('console', lambda m, w=w, mod=mod: konzol.append(f'{w}/{mod} {m.type}: {m.text[:160]}') if m.type in ('error', 'warning') else None)
                pg.on('pageerror', lambda x, w=w, mod=mod: konzol.append(f'{w}/{mod} pageerror: {str(x)[:200]}'))
                pg.on('response', lambda r, w=w, mod=mod: konzol.append(f'{w}/{mod} HTTP {r.status}: {r.url[:140]}') if r.status >= 400 and r.url.startswith(ALAP.split('/wp')[0]) else None)
                pg.goto(ADMIN, wait_until='domcontentloaded')
                pg.wait_for_selector('.sa-oldalsav', timeout=20000)
                pg.evaluate("m => { document.documentElement.dataset.tema = m; }", mod)
                ossz_allapot += bejar(pg, w, h, mod, jegyez)
                ctx.close()
        b.close()

    jegyez('konzol', 'konzol', sorted(set(konzol)))
    print(f'Licenc-panelek vizuális QA – {ossz_allapot} állapot, {round(time.time() - kezd)} s')
    ossz = 0
    for k in ['tulcsordulas', 'tabla', 'atfedes', 'levagott', 'popup', 'iv', 'ikon', 'kapcsolo', 'kontraszt', 'tema', 'igazitas', 'celterulet', 'fokusz', 'menu', 'konzol', 'allapot']:
        l = hibak.get(k, [])
        ossz += len(l)
        print(f'  {k:<13} {len(l):>4}' + ('' if not l else '   ' + ' | '.join(list(dict.fromkeys(l))[:3])))
    with open(os.path.join(KI, 'hibak.json'), 'w', encoding='utf-8') as f:
        json.dump(hibak, f, ensure_ascii=False, indent=1)
    print(f'Összesen {ossz} hiba' + ('' if ossz else ' – minden zöld') + f'. Részletek: {os.path.join(KI, "hibak.json")}')
    sys.exit(1 if ossz else 0)


if __name__ == '__main__':
    main()
