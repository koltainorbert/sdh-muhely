#!/usr/bin/env python3
"""
Böngészős bejárás (0.96.0): minden menüpont és minden Beállítások-lapfül asztalon (1440 px) és telefonon (390 px).

    SDH_TESZT_ADATBAZIS=1 SDH_E2E_CIM=http://127.0.0.1:8765/wp SDH_E2E_NEV=admin SDH_E2E_JELSZO=... python3 tesztek/e2e/bejaras.py

Hibának számít: az oldal nem töltődik be vagy 4xx/5xx választ ad (a menüben csak megnyitható oldal állhat), PHP-figyelmeztetés, JavaScript-hiba, telefonon vízszintes oldalgörgetés.
Kimenet: nézetenként egy összegző sor, alatta a hibák. Kilépési kód: 0, ha minden rendben.
"""
import asyncio
import os
import re
import sys

from playwright.async_api import async_playwright

CIM = os.environ.get('SDH_E2E_CIM', 'http://127.0.0.1:8765/wp').rstrip('/')
NEV = os.environ.get('SDH_E2E_NEV', 'admin')
JELSZO = os.environ.get('SDH_E2E_JELSZO', '')
PHP = re.compile(r'(Warning|Notice|Deprecated|Fatal error)</b>:')


async def nezet(b, szel, mag, cimke):
    ctx = await b.new_context(viewport={'width': szel, 'height': mag})
    o = await ctx.new_page()
    js = []
    o.on('pageerror', lambda e: js.append(o.url.split('?')[-1] + ': ' + str(e)[:150]))
    await o.goto(CIM + '/?muhely=belepes')
    await o.fill('#sdh-belepes-nev', NEV)
    await o.fill('#sdh-belepes-jelszo', JELSZO)
    await o.click('.sdh-belepes__gomb')
    await o.wait_for_timeout(1500)
    linkek = set(await o.eval_on_selector_all('.sdh-sav a.sdh-sav__link', 'l => l.map(a => a.href)'))
    await o.goto(CIM + '/?muhely=beallitasok')
    await o.wait_for_timeout(500)
    linkek |= set(await o.eval_on_selector_all('.sdh-beall__menu a.sdh-pill', 'l => l.map(a => a.href)'))
    hibak = []

    for l in sorted(linkek):
        try:
            v = await o.goto(l, wait_until='load', timeout=30000)
            await o.wait_for_timeout(400)
            if v is not None and v.status >= 400:
                # A menüben csak az álljon, amit a belépett fiók meg is nyithat.
                hibak.append('HTTP %d (a menüben van, de nem nyitható meg): %s' % (v.status, l.split('?')[-1]))
                continue
        except Exception:
            hibak.append('nem töltődik be: ' + l)
            continue

        if PHP.search(await o.content()):
            hibak.append('PHP-figyelmeztetés: ' + l)

        if szel < 600:
            tobb = await o.evaluate('document.documentElement.scrollWidth - window.innerWidth')

            if tobb > 4:
                hibak.append('vízszintes görgetés %d px: %s' % (tobb, l.split('?')[-1]))

    await ctx.close()
    hibak += js
    print('%s: %d oldal, %d hiba' % (cimke, len(linkek), len(hibak)))

    for h in hibak[:30]:
        print('   ' + h)

    return len(hibak)


async def fo():
    if os.environ.get('SDH_TESZT_ADATBAZIS') != '1':
        print('Csak tesztoldalon, SDH_TESZT_ADATBAZIS=1 mellett fut.')
        return 2

    async with async_playwright() as p:
        b = await p.chromium.launch()
        h = await nezet(b, 1440, 900, 'asztali') + await nezet(b, 390, 844, 'telefon')
        await b.close()

    return 0 if h == 0 else 1


sys.exit(asyncio.run(fo()))
