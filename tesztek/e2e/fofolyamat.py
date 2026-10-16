#!/usr/bin/env python3
"""
Böngészős alapteszt (H9, 0.82.1): a fő folyamatok egy valódi böngészőben.

    SDH_E2E_CIM=http://127.0.0.1:8765/wp SDH_E2E_NEV=admin SDH_E2E_JELSZO=... python3 tesztek/e2e/fofolyamat.py

Kell hozzá: Python + Playwright (Chromium). Csak TESZToldalon futtasd (ugyanaz a szabály, mint a PHP-teszteknél).
Ellenőrzi: bejelentkező oldal (nem árulkodik), belépés, Áttekintés, a munkalap / ügyfél / eszköz popupja,
minden Beállítások-lapfül, a Súgó, a kilépés – JavaScript-hiba és PHP-figyelmeztetés nélkül.
Kimenet: egy összegző sor; hibánál alatta a bukott lépések. Kilépési kód: 0, ha minden rendben.
"""
import asyncio
import os
import re
import sys

from playwright.async_api import async_playwright

CIM = os.environ.get('SDH_E2E_CIM', 'http://127.0.0.1:8765/wp').rstrip('/')
NEV = os.environ.get('SDH_E2E_NEV', 'admin')
JELSZO = os.environ.get('SDH_E2E_JELSZO', '')
PHP_HIBA = re.compile(r'(Warning|Notice|Deprecated|Fatal error)</b>:|PHP (Warning|Notice|Fatal)')
ZAJ = ".sdh-gpu-sav,.sdh-ny-ajanlat{display:none!important}"

ok, hibak = 0, []


def allit(feltetel, nev):
    global ok
    if feltetel:
        ok += 1
    else:
        hibak.append(nev)


async def csendesit(o):
    await o.evaluate("document.querySelectorAll('dialog.sdh-ny-ajanlat').forEach(function(d){d.close();})")
    await o.add_style_tag(content=ZAJ)


async def fo():
    if os.environ.get('SDH_TESZT_ADATBAZIS') != '1':
        print('A böngészős teszt adatot írhat: csak tesztoldalon, SDH_TESZT_ADATBAZIS=1 mellett fut.')
        return 2

    async with async_playwright() as p:
        b = await p.chromium.launch()
        o = await (await b.new_context(viewport={'width': 1440, 'height': 900})).new_page()
        js = []
        o.on('pageerror', lambda e: js.append(str(e)))

        await o.goto(CIM + '/?muhely=belepes')
        html = await o.content()
        allit('name="log"' in html and 'name="pwd"' in html, 'bejelentkező: név és jelszó mező')
        allit('WordPress' not in html and 'wp-login' not in html, 'bejelentkező: nem árulkodik')

        await o.fill('#sdh-belepes-nev', NEV)
        await o.fill('#sdh-belepes-jelszo', JELSZO)
        await o.click('.sdh-belepes__gomb')
        await o.wait_for_timeout(2000)
        allit('muhely=belepes' not in o.url, 'belépés (ha kétlépcsős kód kell, ezt a tesztet kód nélküli fiókkal futtasd)')
        await csendesit(o)
        allit(await o.locator('.sdh-app__torzs').count() == 1, 'Áttekintés: a műhely-felület betöltött')
        allit(not PHP_HIBA.search(await o.content()), 'Áttekintés: nincs PHP-figyelmeztetés')

        for modul in ['munkalapok', 'ugyfelek', 'eszkozok']:
            await o.evaluate("window.SDH_MUHELY_APP && window.SDH_MUHELY_APP.nyit('%s', '0')" % modul)
            await o.wait_for_timeout(1500)
            nyitott = o.locator('dialog[open] form')
            allit(await nyitott.count() >= 1, 'popup: új ' + modul + ' űrlapja megnyílt')
            torzs = await o.evaluate("(document.querySelector('dialog[open]')||{}).innerHTML||''")
            allit(not PHP_HIBA.search(torzs), 'popup: ' + modul + ' – nincs PHP-figyelmeztetés')
            await o.evaluate("window.SDH_MUHELY_APP && window.SDH_MUHELY_APP.bezar(0)")
            await o.wait_for_timeout(300)

        await o.goto(CIM + '/?muhely=beallitasok')
        await csendesit(o)
        fulek = await o.eval_on_selector_all('.sdh-beall__menu a.sdh-pill', 'l => l.map(a => a.href)')
        allit(len(fulek) >= 10, 'Beállítások: a lapfülek listája (%d)' % len(fulek))
        for href in fulek:
            await o.goto(href)
            tartalom = await o.content()
            allit(not PHP_HIBA.search(tartalom), 'Beállítások: ' + href.split('ful=')[-1] + ' – nincs PHP-figyelmeztetés')
            allit(await o.locator('.sdh-beall__tartalom').count() == 1, 'Beállítások: ' + href.split('ful=')[-1] + ' betöltött')

        await o.goto(CIM + '/?muhely=sugo')
        allit(not PHP_HIBA.search(await o.content()), 'Súgó: nincs PHP-figyelmeztetés')

        kilep = await o.get_attribute('.sdh-profil__kilep', 'href')
        await o.goto(kilep)
        await o.wait_for_timeout(800)
        allit('muhely=belepes' in o.url, 'kilépés: vissza a bejelentkezőre')

        allit(js == [], 'nincs JavaScript-hiba' + ('' if not js else ': ' + js[0][:120]))
        await b.close()

    print('e2e fő folyamatok: %d/%d %s' % (ok, ok + len(hibak), 'OK' if not hibak else 'HIBA'))
    for h in hibak:
        print('   ✗ ' + h)
    return 0 if not hibak else 1


sys.exit(asyncio.run(fo()))
