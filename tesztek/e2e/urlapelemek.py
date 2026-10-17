#!/usr/bin/env python3
"""
Űrlapelem-szabály ellenőrzése böngészőben (0.97.0) – minden menüpont és Beállítások-lapfül, asztalon:

  1. a kapcsoló (be/ki jelölő) mellett nincs „Aktív / Inaktív” felirat,
  2. a kapcsoló nem ér hozzá semmihez (vízszintesen és függőlegesen legalább RES px hely),
  3. minden szövegmező, legördülő és gomb lekerekített (border-radius > 0).

    SDH_TESZT_ADATBAZIS=1 SDH_E2E_JELSZO=... python3 tesztek/e2e/urlapelemek.py

Kimenet: egy összegző sor, alatta legfeljebb 40 hiba. Kilépési kód: 0, ha minden rendben.
"""
import asyncio
import os
import re
import sys

from playwright.async_api import async_playwright

CIM = os.environ.get('SDH_E2E_CIM', 'http://127.0.0.1:8765/wp').rstrip('/')
NEV = os.environ.get('SDH_E2E_NEV', 'admin')
JELSZO = os.environ.get('SDH_E2E_JELSZO', '')
RES = 6

MERES = r"""
(res) => {
  const zart = el => { const d = el.closest('details:not([open])'); return !!d && !el.closest('summary'); };
  const lat = el => { if (zart(el)) return false; const r = el.getBoundingClientRect(); const s = getComputedStyle(el);
      return r.width > 2 && r.height > 2 && s.visibility !== 'hidden' && s.display !== 'none' && r.bottom > -2000; };
  const nev = el => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.name ? '[name=' + el.name + ']' : '') +
      (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
  const ki = {felirat: [], ter: [], kerek: []};
  const kapcsolok = [...document.querySelectorAll('.sdh-app input[type=checkbox], .sdh-modal input[type=checkbox]')]
      .filter(i => !i.closest('table') && !i.closest('.sdh-cs-pill') && !i.hasAttribute('data-sdh-nativ') && lat(i));
  // Szomszédok: látható, szöveget hordozó levélelemek és vezérlők.
  const lebeg = e => { for (let x = e; x && x !== document.body; x = x.parentElement) {
      const p = getComputedStyle(x).position; if (p === 'fixed' || p === 'sticky') return true; } return false; };
  const jelolt = [...document.querySelectorAll('.sdh-app *, .sdh-modal *')].filter(e => {
      if (!lat(e) || lebeg(e) || e.tagName === 'svg' || e.closest('svg') || e.tagName === 'OPTION') return false;
      if (/^(INPUT|SELECT|TEXTAREA|BUTTON|IMG)$/.test(e.tagName)) return true;
      return [...e.childNodes].some(n => n.nodeType === 3 && n.textContent.trim() !== '');
  });
  for (const i of kapcsolok) {
      const s = i.nextElementSibling;
      if (s && /^(aktív|inaktív)$/i.test((s.textContent || '').trim())) ki.felirat.push(nev(i));
      if (lebeg(i)) continue;   // lebegő értesítő / sáv: nem a lap folyamában áll
      const a = i.getBoundingClientRect();
      for (const e of jelolt) {
          if (e === i || e.contains(i)) continue;
          const b = e.getBoundingClientRect();
          // Szöveg esetén a szöveg tényleges doboza számít (nem a teljes blokkszélesség).
          let r = b;
          if (!/^(INPUT|SELECT|TEXTAREA|BUTTON|IMG)$/.test(e.tagName)) {
              const t = document.createRange(); t.selectNodeContents(e); const rr = t.getBoundingClientRect();
              if (rr.width > 0) r = rr;
          }
          const fugg = Math.min(a.bottom, r.bottom) - Math.max(a.top, r.top);
          const vizsz = Math.min(a.right, r.right) - Math.max(a.left, r.left);
          let tav = null;
          if (fugg > 2) tav = Math.max(r.left - a.right, a.left - r.right);
          else if (vizsz > 2) tav = Math.max(r.top - a.bottom, a.top - r.bottom);
          if (tav !== null && tav < res) { ki.ter.push(nev(i) + ' ↔ ' + nev(e) + ' (' + Math.round(tav) + ' px)'); break; }
      }
  }
  const mezok = [...document.querySelectorAll(
      '.sdh-app input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=range]):not([type=color]):not([type=file]), ' +
      '.sdh-app textarea, .sdh-app select, .sdh-app button, .sdh-app .sdh-gomb, ' +
      '.sdh-modal input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=range]):not([type=color]):not([type=file]), ' +
      '.sdh-modal textarea, .sdh-modal select, .sdh-modal button, .sdh-modal .sdh-gomb')].filter(lat);
  for (const m of mezok) {
      const s = getComputedStyle(m);
      const keret = parseFloat(s.borderTopWidth) > 0 || (s.backgroundColor !== 'rgba(0, 0, 0, 0)' && s.backgroundColor !== 'transparent');
      if (!keret || m.closest('.sdh-szam__gombok')) continue;   // a léptető fél-gombjai a kerek dobozon belül   // keret és háttér nélküli (ikon- / szöveggomb): nincs mit kerekíteni
      if (parseFloat(s.borderTopLeftRadius) <= 0 && parseFloat(s.borderBottomRightRadius) <= 0) ki.kerek.push(nev(m));
  }
  return ki;
}
"""


async def fo():
    if os.environ.get('SDH_TESZT_ADATBAZIS') != '1':
        print('Csak tesztoldalon, SDH_TESZT_ADATBAZIS=1 mellett fut.')
        return 2

    async with async_playwright() as p:
        b = await p.chromium.launch()
        o = await (await b.new_context(viewport={'width': 1440, 'height': 900})).new_page()
        await o.goto(CIM + '/?muhely=belepes')
        await o.fill('#sdh-belepes-nev', NEV)
        await o.fill('#sdh-belepes-jelszo', JELSZO)
        await o.click('.sdh-belepes__gomb')
        await o.wait_for_timeout(1500)
        linkek = set(await o.eval_on_selector_all('.sdh-sav a.sdh-sav__link', 'l => l.map(a => a.href)'))
        await o.goto(CIM + '/?muhely=beallitasok')
        linkek |= set(await o.eval_on_selector_all('.sdh-beall__menu a.sdh-pill', 'l => l.map(a => a.href)'))
        osszes = {'felirat': {}, 'ter': {}, 'kerek': {}}
        csak = os.environ.get('SDH_E2E_CSAK', '')
        if csak:
            linkek = {l for l in linkek if csak in l}
        db = 0
        popupok = 0

        for l in sorted(linkek):
            await o.goto(l, wait_until='load', timeout=30000)
            await o.wait_for_timeout(400)
            m = await o.evaluate(MERES, RES)
            db += 1
            for k in osszes:
                for h in m[k]:
                    osszes[k].setdefault(h, l.split('?')[-1])

            # Az oldal „Új …” popupja is (felvitel popupban történik).
            uj = o.locator('.sdh-app__torzs :is(a, button):visible').filter(has_text=re.compile(r'^\s*\+?\s*Új\b'))
            if await uj.count() > 0:
                try:
                    await uj.first.click(timeout=3000)
                    await o.wait_for_timeout(1500)
                    if await o.locator('dialog.sdh-modal[open]').count() > 0:
                        popupok += 1
                        m = await o.evaluate(MERES, RES)
                        for k in osszes:
                            for h in m[k]:
                                osszes[k].setdefault(h, l.split('?')[-1] + ' (popup)')
                    await o.keyboard.press('Escape')
                except Exception:
                    pass

        await b.close()

    hiba = sum(len(v) for v in osszes.values())
    print('űrlapelemek: %d oldal + %d popup, felirat %d, összeérő kapcsoló %d, kerekítetlen %d' % (db, popupok, len(osszes['felirat']), len(osszes['ter']), len(osszes['kerek'])))
    n = 0
    for k, v in osszes.items():
        for h, hol in v.items():
            if n < int(os.environ.get('SDH_E2E_MAX', '40')):
                print('   %s: %s  [%s]' % (k, h, hol))
            n += 1

    return 0 if hiba == 0 else 1


sys.exit(asyncio.run(fo()))
