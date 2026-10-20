#!/usr/bin/env python3
"""
Interaktív modulok – vizuális QA bejáró (Playwright, Chromium). A bővítmény front-moduljait (kalkulátor, kosár, pénztár +
állapotoldal, fiók, egyedi igényfelmérő, kapcsolat / partner űrlap, időpontfoglalás, Szikra, oktatás, süti-sáv + ablak,
értesítők) járja végig 390 / 768 / 1366 szélességen, sötét és világos módban, minden állapotukban (üres, kitöltött, hibás,
sikeres), és a modulok gyökerén belül méri (kovetes/vizualis-qa.md):

  tulcsordulas – vízszintes görgetés, a nézeten túlnyúló elem
  atfedes      – egymást fedő szöveg / gomb / mező (nem ős–utód, > 2 px); a lebegő elemek (toast, Szikra, süti-sáv) egymáson
  celterulet   – 44 px alatti célterület (gomb, mező, link; jelölőnél / rádiónál a sor)
  szoveg       – levágott szöveg, két sorba tört gomb széles szülőben, cím text-wrap: balance nélkül
  kontraszt    – szöveg / háttér ≥ 4,5:1 (nagy szöveg ≥ 3:1), mezőkeret ≥ 3:1
  kapcsolo     – kapcsoló körül ≥ 6 px levegő, mellette nincs Aktív / Inaktív felirat
  iv           – minden mező / gomb / pill lekerekített; többsoros ≤ 15 px
  tema         – nagy felület rossz fényerővel (sötétben világos / világosban sötét)
  datum        – natív dátum- / időmező (tilos)
  rejtes       – sdh / wp- kezdetű osztály / azonosító a modulokban
  allapot      – folyamat-ellenőrzések (fókuszcsapda, Esc, görgetészár, egyforma süti-gombok, léptető, másoló gomb, …)
  mozgas       – prefers-reduced-motion: reduce mellett 20 ms-nál hosszabb animáció / átmenet
  konzol       – 0 hiba / figyelmeztetés, 0 sikertelen (4xx / 5xx) saját kérés (a szándékos hibás beküldések kivételével)

Futtatás (a SAJÁT tesztoldalon; a segéd teszt-rendelést, kupont, hamis Szikra-kulcsot tesz rá):
  python3 weboldal/tesztek/e2e/modulok-vizualis.py http://127.0.0.1:8873/wp [kimeneti mappa] [--wp=/…/wp-load.php] [--gyors] [--allapot=<részlet>]
  --gyors : csak 390 + 1366      --allapot=kosar : csak az illeszkedő állapotok
Kimenet: hibatábla ellenőrzésenként (db + első találatok), hibak.json + képek (<allapot>-<szélesség>-<mód>.png). Kilépési kód 0, ha nincs hiba.
"""
import json
import os
import re
import subprocess
import sys
import time

from playwright.sync_api import sync_playwright

ALAP = (next((a for a in sys.argv[1:] if a.startswith('http')), 'http://127.0.0.1:8873/wp')).rstrip('/')
KI = next((a for a in sys.argv[2:] if not a.startswith('-') and not a.startswith('http')), os.path.join(os.path.dirname(os.path.abspath(__file__)), 'kepek-modulok'))
WP = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--wp=')), os.environ.get('VQA_WP_LOAD', '/home/claude/env/vqa-modulok/wp/wp-load.php'))
GYORS = '--gyors' in sys.argv
SZURO = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--allapot=')), '')
SEGED = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'modulok-seged.php')
HOST = re.sub(r'^https?://', '', ALAP).split('/')[0]
GYOKER = ALAP.split('/wp')[0] if '/wp' in ALAP else ALAP
os.makedirs(KI, exist_ok=True)

NEZETEK = [(390, 844), (768, 1024), (1366, 900)]
if GYORS:
    NEZETEK = [(390, 844), (1366, 900)]
MODOK = ('sotet', 'vilagos')

KOSAR_TELE = {'tetelek': [{'tipus': 'csomag', 'kulcs': 'pro'}, {'tipus': 'modul', 'kulcs': 'sms'}, {'tipus': 'szolgaltatas', 'kulcs': 'telepites', 'db': 1}], 'opciok': {'ciklus': 'eves', 'felhasznalo': 4}}


def seged(parancs):
    try:
        k = subprocess.run(['php', SEGED, WP, HOST, parancs], capture_output=True, text=True, timeout=120)
        sor = [s for s in k.stdout.splitlines() if s.startswith('{')]
        return json.loads(sor[-1]) if sor else {}
    except Exception as e:  # noqa: BLE001
        print('segéd hiba:', e)
        return {}


# A modulok gyökerén belüli ellenőrzések – {ellenőrzés: [leírás, …]}.
ELLENORZES = r"""() => {
  const W = document.documentElement.clientWidth, H = innerHeight;
  const hibak = {};
  const add = (k, s) => { (hibak[k] = hibak[k] || []).push(s); };
  const cls = el => (typeof el.className === 'string' ? el.className : (el.className && el.className.baseVal) || '').trim().split(/\s+/).filter(Boolean);
  const sel = el => { let s = el.tagName.toLowerCase(); if (el.id && !/^(kalk|egyedi|urlap|s-fogl|s-okt|s-szikra)-/.test(el.id)) s += '#' + el.id; const c = cls(el).slice(0, 2); if (c.length) s += '.' + c.join('.'); return s; };
  const ut = el => { const p = []; let e = el; while (e && e !== document.body && p.length < 3) { p.unshift(sel(e)); e = e.parentElement; } return p.join('>'); };
  const rov = s => String(s || '').replace(/\s+/g, ' ').trim().slice(0, 40);
  const cs = el => getComputedStyle(el);
  const lathato = el => { const st = cs(el); if (st.display === 'none' || st.visibility === 'hidden' || parseFloat(st.opacity) === 0) return false; const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
  const sajatSzoveg = el => { for (const n of el.childNodes) { if (n.nodeType === 3 && n.textContent.trim()) return true; } return false; };
  const REJT = '.s-rejtett, .s-rejtett-szoveg, .s-fogl__csapda, [aria-hidden="true"], template, script, style, noscript, [hidden]';
  const GYOK = '[data-modul], #s-suti, #s-suti-modal, .s-uzenet-sav';
  const gyokerek = Array.from(document.querySelectorAll(GYOK)).filter(g => !g.parentElement.closest(GYOK) && lathato(g));
  // Vizuálisan rejtett (clip / 1 px) elem vagy őse → kihagyjuk.
  const klippelt = el => { for (let e = el; e && e !== document.body; e = e.parentElement) { const st = cs(e); if (st.clipPath === 'inset(50%)' || (st.clip && st.clip !== 'auto') || (st.position === 'absolute' && parseFloat(st.width) <= 1 && parseFloat(st.height) <= 1)) return true; } return false; };
  // A görgethető / levágó ősök által ténylegesen látható téglalap (null, ha teljesen kívül esik).
  const latszo = el => {
    let r = el.getBoundingClientRect(); let x1 = r.left, y1 = r.top, x2 = r.right, y2 = r.bottom;
    for (let e = el.parentElement; e && e !== document.body; e = e.parentElement) {
      const st = cs(e); if (st.overflowX === 'visible' && st.overflowY === 'visible') continue;
      const q = e.getBoundingClientRect(); x1 = Math.max(x1, q.left); y1 = Math.max(y1, q.top); x2 = Math.min(x2, q.right); y2 = Math.min(y2, q.bottom);
      if (x2 - x1 <= 0 || y2 - y1 <= 0) return null;
    }
    return { left: x1, top: y1, right: x2, bottom: y2, width: x2 - x1, height: y2 - y1 };
  };
  const mind = [];
  gyokerek.forEach(g => { mind.push(g); g.querySelectorAll('*').forEach(e => { if (!e.closest(REJT) && lathato(e) && !klippelt(e)) mind.push(e); }); });
  const gyokere = el => el.closest('.s-uzenet-sav, #s-suti, #s-suti-modal, .s-szikra--lebego') || el.closest('[data-modul]');

  /* túlcsordulás */
  if (document.documentElement.scrollWidth > document.documentElement.clientWidth) add('tulcsordulas', 'dokumentum ' + (document.documentElement.scrollWidth - document.documentElement.clientWidth) + 'px');
  for (const el of mind) {
    let gorg = false;
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) { const o = cs(p).overflowX; if (o === 'auto' || o === 'scroll' || o === 'hidden') { gorg = true; break; } }
    if (gorg && !el.matches(GYOK)) continue;
    const r = el.getBoundingClientRect();
    if (r.width <= 1 || r.height <= 1) continue;
    if (r.right > W + 1 || r.left < -1) add('tulcsordulas', ut(el) + ' ' + Math.round(r.left) + '…' + Math.round(r.right) + ' (W ' + W + ')');
  }

  /* átfedés (ugyanazon gyökéren belül) */
  const jelolt = [];
  for (const el of mind) {
    const t = el.tagName, st = cs(el);
    if (st.position === 'absolute' && !el.matches('.s-szikra__panel')) continue;
    const jo = sajatSzoveg(el) || t === 'IMG' || t === 'svg' || t === 'INPUT' || t === 'SELECT' || t === 'TEXTAREA' || t === 'BUTTON' || el.classList.contains('s-gomb') || el.classList.contains('s-pill');
    if (!jo) continue;
    if (t === 'INPUT' && /^(checkbox|radio)$/.test(el.type) && el.closest('label.s-pill')) continue;
    const lr = latszo(el); if (!lr) continue;
    const rects = st.display === 'inline' ? Array.from(el.getClientRects()) : [lr];
    for (const r of rects) { if (r.width > 0 && r.height > 0) jelolt.push({ el, r, g: gyokere(el) }); }
  }
  jelolt.sort((a, b) => a.r.top - b.r.top);
  const lattam = new Set();
  for (let i = 0; i < jelolt.length; i++) {
    const a = jelolt[i];
    for (let j = i + 1; j < jelolt.length && jelolt[j].r.top < a.r.bottom - 2; j++) {
      const b = jelolt[j];
      if (a.g !== b.g) continue;
      if (a.el === b.el || a.el.contains(b.el) || b.el.contains(a.el)) continue;
      const w = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left);
      const h = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top);
      if (w > 2 && h > 2) {
        const k = ut(a.el) + ' × ' + ut(b.el);
        if (!lattam.has(k)) { lattam.add(k); add('atfedes', k + ' (' + Math.round(w) + '×' + Math.round(h) + ') „' + rov(a.el.textContent) + '” / „' + rov(b.el.textContent) + '”'); }
      }
    }
  }
  /* lebegő rétegek egymáson (toast / Szikra-indító / süti-sáv) */
  const lebego = ['.s-uzenet-sav .s-uzenet', '.s-szikra__indito', '#s-suti:not([hidden]) .s-suti-sav'].map(s => Array.from(document.querySelectorAll(s)).filter(lathato)).flat();
  for (let i = 0; i < lebego.length; i++) for (let j = i + 1; j < lebego.length; j++) {
    const a = lebego[i].getBoundingClientRect(), b = lebego[j].getBoundingClientRect();
    const w = Math.min(a.right, b.right) - Math.max(a.left, b.left), h = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
    if (w > 2 && h > 2) add('atfedes', 'lebegő: ' + sel(lebego[i]) + ' × ' + sel(lebego[j]) + ' (' + Math.round(w) + '×' + Math.round(h) + ')');
  }

  /* célterület */
  for (const el of mind) {
    if (!el.matches('a[href], button, input:not([type=hidden]), select, textarea, [role=button], [role=switch], summary')) continue;
    if (el.matches('a') && el.closest('p, li > label, label, .s-fogl__halvany, .s-szikra__tajekoztato, .s-szikra__forrasok, .s-szikra__szoveg, .s-suti-leiras')) continue;
    let r = el.getBoundingClientRect();
    if (el.matches('input[type=checkbox], input[type=radio]')) {
      if (el.closest('.s-suti-kapcsolo')) { r = el.closest('.s-suti-kapcsolo').getBoundingClientRect(); if (r.width < 44 || r.height < 24) add('celterulet', ut(el) + ' kapcsoló ' + Math.round(r.width) + '×' + Math.round(r.height)); continue; }
      const sor = el.closest('label, .s-pipa, .s-okt__valasz, .s-kapcsolo-sor') || (el.id && document.querySelector('label[for="' + el.id + '"]'));
      const rr = sor ? sor.getBoundingClientRect() : r;
      if (rr.height < 44) add('celterulet', ut(el) + ' sor ' + Math.round(rr.width) + '×' + Math.round(rr.height));
      if (r.width < 24 || r.height < 24) add('celterulet', ut(el) + ' jelölő ' + Math.round(r.width) + '×' + Math.round(r.height));
      continue;
    }
    if (r.height < 44 || r.width < 44) add('celterulet', ut(el) + ' ' + Math.round(r.width) + '×' + Math.round(r.height) + ' „' + rov(el.textContent || el.value || el.getAttribute('aria-label')) + '”');
  }

  /* szöveg */
  for (const el of mind) {
    if (!sajatSzoveg(el)) continue;
    const st = cs(el);
    if (st.display === 'inline' || st.textOverflow === 'ellipsis' || st.overflowX === 'auto' || st.overflowX === 'scroll') continue;
    if (el.closest('pre, textarea, svg')) continue;
    if (el.scrollWidth > el.clientWidth + 1) add('szoveg', ut(el) + ' levágva/kilóg ' + el.scrollWidth + '>' + el.clientWidth + ' „' + rov(el.textContent) + '”');
  }
  mind.filter(e => e.matches('h1, h2, h3')).forEach(h => {
    const st = cs(h);
    const tw = st.textWrap || st.textWrapStyle || st.getPropertyValue('text-wrap') || st.getPropertyValue('text-wrap-style');
    if (!/balance|pretty/.test(String(tw))) add('szoveg', ut(h) + ' text-wrap: ' + tw);
  });
  // Gombszöveg sorainak száma: a szöveg-csomópontok sorai (Range), nem a doboz magassága.
  const sorok = g => { const tops = new Set(); const w = document.createTreeWalker(g, NodeFilter.SHOW_TEXT); let n; while ((n = w.nextNode())) { if (!n.textContent.trim() || klippelt(n.parentElement) || n.parentElement.closest('.s-gomb__al')) continue; const rg = document.createRange(); rg.selectNodeContents(n); for (const r of rg.getClientRects()) { if (r.width > 1) tops.add(Math.round(r.top / 4)); } } return tops.size; };
  mind.filter(e => e.matches('button, .s-gomb, .s-pill, .s-fogl__gomb, .s-suti-gomb, .s-okt__gomb, .s-szikra__gomb')).forEach(g => {
    if (g.closest('.s-okt__lecke, .s-kalk-csomag, .s-valaszto, .s-valasztok')) return;
    const r = g.getBoundingClientRect();
    const szul = g.parentElement ? g.parentElement.getBoundingClientRect().width : 0;
    if (sorok(g) > 1 && szul >= 200) add('szoveg', ut(g) + ' gombszöveg két sorban ' + Math.round(r.width) + '×' + Math.round(r.height) + ' (szülő ' + Math.round(szul) + 'px) „' + rov(g.textContent) + '”');
  });

  /* kontraszt */
  const szin = c => { const m = String(c).match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[\s,\/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const arany = (a, b) => { const l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); };
  const kever = (fel, al, a) => ({ r: fel.r * a + al.r * (1 - a), g: fel.g * a + al.g * (1 - a), b: fel.b * a + al.b * (1 - a) });
  const lapHatter = szin(cs(document.body).backgroundColor) || { r: 255, g: 255, b: 255, a: 1 };
  const hatter = el => {
    const retegek = [];
    for (let e = el; e; e = e.parentElement) {
      const c = szin(cs(e).backgroundColor);
      if (c && c.a > 0) { retegek.push(c); if (c.a >= 1) break; }
    }
    let ki = lapHatter.a >= 1 ? lapHatter : { r: 255, g: 255, b: 255 };
    for (let i = retegek.length - 1; i >= 0; i--) ki = kever(retegek[i], ki, retegek[i].a);
    return ki;
  };
  const opac = el => { let o = 1; for (let e = el; e && e !== document.body; e = e.parentElement) o *= parseFloat(cs(e).opacity); return o; };
  const kontrLat = new Set();
  for (const el of mind) {
    if (!sajatSzoveg(el)) continue;
    const st = cs(el);
    if (el.closest(':disabled, [aria-disabled="true"]')) continue;
    let c = szin(st.color); if (!c) continue;
    const bg = hatter(el);
    const o = opac(el);
    if (c.a < 1) c = kever(c, bg, c.a);
    if (o < 1) c = kever(c, bg, o);
    const fs = parseFloat(st.fontSize), fw = parseInt(st.fontWeight, 10) || 400;
    const nagy = fs >= 24 || (fs >= 18.66 && fw >= 700);
    const r = arany(c, bg);
    if (r < (nagy ? 3 : 4.5)) { const k = ut(el) + '|' + st.color; if (!kontrLat.has(k)) { kontrLat.add(k); add('kontraszt', ut(el) + ' ' + r.toFixed(2) + ':1 (' + st.color + ' / rgb(' + [bg.r, bg.g, bg.b].map(Math.round).join(',') + ')) „' + rov(el.textContent) + '”'); } }
  }
  mind.filter(e => e.matches('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), select, textarea')).forEach(el => {
    const st = cs(el);
    const bc = szin(st.borderTopColor); if (!bc || st.borderTopWidth === '0px') return;
    if (el.closest('.s-lepteto')) return;
    const kinti = hatter(el.parentElement || el);
    const belso = szin(st.backgroundColor);
    const alap = belso && belso.a > 0 ? kever(belso, kinti, belso.a) : kinti;
    const k = kever(bc, alap, bc.a);
    const r = arany(k, kinti);
    if (r < 3) add('kontraszt', ut(el) + ' mezőkeret ' + r.toFixed(2) + ':1');
  });

  /* kapcsoló */
  mind.filter(e => e.matches('.s-kapcsolo, .s-suti-kapcsolo, [role=switch]')).forEach(k => {
    const st = cs(k);
    ['marginTop', 'marginRight', 'marginBottom', 'marginLeft'].forEach(m => { if (parseFloat(st[m]) < 6) add('kapcsolo', ut(k) + ' ' + m + ' ' + st[m]); });
    const sor = k.closest('label, .s-kapcsolo-sor, .s-suti-kat-fej') || k.parentElement;
    if (/\b(Aktív|Inaktív|Be|Ki)\b\s*$/.test((sor.textContent || '').trim())) add('kapcsolo', ut(k) + ' felirat a kapcsoló mellett');
  });

  /* ív */
  mind.filter(e => e.matches('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea, button, .s-pill, .s-gomb, [role=button]')).forEach(el => {
    if (el.closest('.s-lepteto') && el.matches('input')) return;
    const st = cs(el);
    const r = Math.max(parseFloat(st.borderTopLeftRadius), parseFloat(st.borderTopRightRadius));
    if (!(r > 0)) add('iv', ut(el) + ' border-radius ' + st.borderTopLeftRadius);
    if ((el.tagName === 'TEXTAREA' || (el.tagName === 'SELECT' && el.multiple)) && r > 15) add('iv', ut(el) + ' többsoros ív ' + st.borderTopLeftRadius);
  });

  /* téma: nagy felületek fényereje */
  const mod = document.documentElement.getAttribute('data-tema');
  for (const el of mind) {
    if (/^(IMG|svg|VIDEO|PICTURE|CANVAS|IFRAME|BUTTON)$/.test(el.tagName) || el.matches('.s-gomb, .s-gomb-fo, .s-fogl__gomb')) continue;
    const r = el.getBoundingClientRect();
    if (r.width * r.height < 20000) continue;
    const c = szin(cs(el).backgroundColor);
    if (!c || c.a < 0.9) continue;
    const l = lum(c);
    if (mod === 'sotet' && l > 0.4) add('tema', ut(el) + ' világos felület sötét módban (L=' + l.toFixed(2) + ')');
    if (mod === 'vilagos' && l < 0.15) add('tema', ut(el) + ' sötét felület világos módban (L=' + l.toFixed(2) + ')');
  }

  /* natív dátum, rejtés */
  document.querySelectorAll('input[type=date], input[type=datetime-local], input[type=month], input[type=week], input[type=time]').forEach(e => add('datum', ut(e)));
  for (const el of mind) {
    const c = cls(el).join(' ') + ' ' + (el.id || '');
    if (/(^|\s)(sdh|wp-)|sdh_/i.test(c)) add('rejtes', ut(el));
  }
  return hibak;
}"""

MOZGAS = r"""() => {
  const ki = [];
  const p = v => v.split(',').map(x => x.trim()).map(x => x.endsWith('ms') ? parseFloat(x) / 1000 : parseFloat(x));
  document.querySelectorAll('[data-modul] *, [data-modul], #s-suti *, #s-suti-modal *, .s-uzenet-sav *').forEach(el => {
    if (el.closest('[hidden]')) return;
    const st = getComputedStyle(el);
    const a = Math.max(...p(st.animationDuration)), t = Math.max(...p(st.transitionDuration));
    const n = el.tagName.toLowerCase() + '.' + (typeof el.className === 'string' ? el.className.split(' ')[0] : '');
    if (a > 0.02 && st.animationName !== 'none') ki.push(n + ' animation ' + a + 's');
    if (t > 0.02) ki.push(n + ' transition ' + t + 's');
    if (st.scrollBehavior === 'smooth') ki.push(n + ' scroll-behavior: smooth');
  });
  return Array.from(new Set(ki)).slice(0, 12);
}"""

FOKUSZ = r"""() => {
  const el = document.activeElement;
  if (!el || el === document.body) return null;
  const st = getComputedStyle(el);
  let ring = (st.outlineStyle !== 'none' && parseFloat(st.outlineWidth) > 0) || (st.boxShadow && st.boxShadow !== 'none');
  const p = el.closest('label.s-pill, .s-kalk-csomag, .s-valaszto, .s-suti-kapcsolo, .s-lepteto');
  if (!ring && p) { const ps = getComputedStyle(p); ring = (ps.outlineStyle !== 'none' && parseFloat(ps.outlineWidth) > 0) || (ps.boxShadow && ps.boxShadow !== 'none'); }
  if (!ring && el.classList.contains('s-suti-valaszto')) { const c = el.nextElementSibling; const cs2 = c && getComputedStyle(c); ring = cs2 && cs2.outlineStyle !== 'none' && parseFloat(cs2.outlineWidth) > 0; }
  const cls = typeof el.className === 'string' ? el.className.split(' ')[0] : '';
  return { sel: el.tagName.toLowerCase() + '.' + cls + (el.type ? '[' + el.type + ']' : ''), ring: !!ring, modul: !!el.closest('[data-modul], #s-suti, #s-suti-modal') };
}"""


def _png():
    import struct
    import zlib

    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 8, 8, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(b''.join(b'\x00' + b'\x30\x60\xd0' * 8 for _ in range(8)))) + chunk(b'IEND', b'')


PNG = _png()


def suti_cookie():
    return {'name': 'suti', 'value': '1|1.0|000|' + str(int(time.time())), 'url': ALAP}


def main():
    hibak = {}
    jegyzet = []
    kepek = []

    def hiba(k, s):
        hibak.setdefault(k, []).append(s)

    adat = seged('elokeszit')
    if not adat.get('statusz_url'):
        print('A segéd nem adott állapotoldalt:', adat)
        sys.exit(2)

    with sync_playwright() as p:
        b = p.chromium.launch()

        # Belépett fiók: egyszer belépünk, a sütiket minden fiókos állapot megkapja.
        belepve_suti = []
        ctx0 = b.new_context()
        ctx0.add_cookies([suti_cookie()])
        lap0 = ctx0.new_page()
        lap0.goto(adat['belepo_url'], wait_until='networkidle')
        lap0.wait_for_timeout(1200)
        try:
            lap0.wait_for_selector('.s-fiok-fej', timeout=10000)
            belepve_suti = ctx0.cookies()
        except Exception:
            hiba('allapot', 'fiók: a belépő link nem léptetett be')
        ctx0.close()

        def kosar_init(ctx, kosar):
            ctx.add_init_script('try{localStorage.setItem("kosar_v1", %s)}catch(e){}' % json.dumps(json.dumps(kosar)))

        def menj(lap, ut):
            return lap.goto(ut if ut.startswith('http') else ALAP + ut, wait_until='networkidle', timeout=60000)

        def gorget(lap, s):
            lap.evaluate("(s) => { const e = document.querySelector(s); if (e) e.scrollIntoView({block: 'center'}); }", s)
            lap.wait_for_timeout(150)

        # ---------- Állapotok: (név, előkészítés(ctx), lépések(lap) → a képernyőképhez gyökér-szelektor) ----------
        def a_kalk(lap):
            menj(lap, '/arak/')
            lap.wait_for_selector('.s-kalk-osszeg .s-gomb')
            gorget(lap, '.s-kalk')
            return '.s-kalk'

        def a_kalk_havi(lap):
            menj(lap, '/arak/')
            lap.wait_for_selector('.s-kalk-osszeg .s-gomb')
            lap.locator('.s-kalk-ciklus label').first.click()
            mods = lap.locator('.s-kalk-modul')
            for i in range(min(3, mods.count())):
                mods.nth(i).click()
            lep = lap.locator('.s-kalk-letszam .s-lepteto__gomb')
            if lep.count() >= 2:
                lep.nth(1).click()
                lep.nth(1).click()
            elif lap.locator('.s-kalk-letszam input').count():
                hiba('allapot', 'kalkulátor: nincs léptető (−/+) a létszámmezők körül')
            v = lap.evaluate("(() => { const i = document.querySelector('.s-kalk-letszam input'); return i ? i.value : null; })()")
            if lep.count() >= 2 and v not in ('3', '4', '5', '6', '7', '8', '9', '10'):
                hiba('allapot', f'kalkulátor: a + léptető nem növelte a felhasználószámot ({v})')
            n = lap.evaluate("document.querySelectorAll('.s-kalk-sorok tr').length")
            if n < 2:
                hiba('allapot', f'kalkulátor: a modulok bekapcsolása után csak {n} sor az összesítőben')
            gorget(lap, '.s-kalk-modulok')
            return '.s-kalk'

        def a_kalk_hiba(lap):
            menj(lap, '/arak/')
            lap.wait_for_selector('.s-kalk-osszeg .s-gomb')
            lap.fill('.s-kalk-letszam input >> nth=0', '5000')
            lap.wait_for_timeout(100)
            if not lap.evaluate("document.querySelector('.s-kalk-osszeg .s-gomb').disabled"):
                hiba('allapot', 'kalkulátor: hibás létszámnál a Kosárba gomb nem tiltott')
            gorget(lap, '.s-kalk-osszeg')
            return '.s-kalk'

        def a_kosar(lap):
            menj(lap, '/kosar/')
            lap.wait_for_selector('.s-kosar-osszeg')
            return '.s-kosar'

        def a_kosar_kupon_hiba(lap):
            menj(lap, '/kosar/')
            lap.wait_for_selector('.s-kosar-osszeg')
            lap.fill('#kosar-kupon', 'NINCSILYEN')
            lap.click('.s-kosar-kupon .s-gomb')
            lap.wait_for_selector('.s-uzenet-sav .s-uzenet-hiba', timeout=8000)
            return '.s-kosar'

        def a_kosar_kupon_ok(lap):
            menj(lap, '/kosar/')
            lap.wait_for_selector('.s-kosar-osszeg')
            lap.fill('#kosar-kupon', 'TESZT10')
            lap.click('.s-kosar-kupon .s-gomb')
            lap.wait_for_selector('.s-uzenet-sav .s-uzenet-ok', timeout=8000)
            lap.wait_for_timeout(600)
            if 'kedvezmény' not in (lap.text_content('.s-kosar-osszeg') or '').lower():
                hiba('allapot', 'kosár: sikeres kupon után nincs kedvezmény-sor')
            return '.s-kosar'

        def a_kosar_torles(lap):
            menj(lap, '/kosar/')
            lap.wait_for_selector('.s-kosar-tabla [data-ketlepcsos]')
            g = lap.locator('.s-kosar-tabla [data-ketlepcsos]').last
            elotte = g.bounding_box()
            g.click()
            lap.wait_for_timeout(100)
            if lap.evaluate("document.querySelectorAll('.s-kosar-tabla [data-elesitve=\"1\"]').length") != 1:
                hiba('allapot', 'kosár: az első Törlés-kattintás nem élesített (kétlépcsős)')
            ut = g.bounding_box()
            if elotte and ut and abs(ut['height'] - elotte['height']) > 1:
                hiba('allapot', f'kosár: az élesített Törlés gomb magassága változik ({elotte["height"]}→{ut["height"]})')
            return '.s-kosar'

        def a_kosar_ures(lap):
            menj(lap, '/kosar/')
            lap.wait_for_selector('.s-kosar-ures')
            return '.s-kosar'

        def a_penztar(lap):
            menj(lap, '/penztar/')
            lap.wait_for_selector('.s-penztar-osszeg p:nth-child(2)', timeout=10000)
            return '.s-penztar'

        def a_penztar_hiba(lap):
            menj(lap, '/penztar/')
            lap.wait_for_selector('.s-penztar-osszeg p:nth-child(2)', timeout=10000)
            lap.wait_for_timeout(3200)  # a robotszűrés kitöltési ideje
            lap.click('.s-penztar > .s-gomb-fo')
            lap.wait_for_selector('.s-penztar .s-mezo-hiba:not(:empty)', timeout=10000)
            lap.wait_for_timeout(300)
            n = lap.evaluate("document.querySelectorAll('.s-penztar .s-mezo-hiba:not(:empty)').length")
            if n < 3:
                hiba('allapot', f'pénztár: üres beküldésre csak {n} mezőhiba')
            lap.evaluate("window.scrollTo(0, 0)")
            gorget(lap, '.s-penztar fieldset')
            return '.s-penztar'

        def a_statusz(lap):
            menj(lap, adat['statusz_url'])
            lap.wait_for_selector('.s-atutalas', timeout=10000)
            n = lap.evaluate("document.querySelectorAll('.s-atutalas .s-masol').length")
            if n < 2:
                hiba('allapot', f'állapotoldal: {n} másoló gomb az átutalási adatoknál (számlaszám, összeg, közlemény kell)')
            if n:
                lap.locator('.s-atutalas .s-masol').first.click()
                lap.wait_for_timeout(150)
                if not lap.evaluate("!!document.querySelector('.s-atutalas .s-masol.s-masolva')"):
                    hiba('allapot', 'állapotoldal: a másoló gomb nem jelez vissza (s-masolva)')
            return '.s-penztar-doboz'

        def _statusz_mod(lap, modosit):
            def kezel(route):
                r = route.fetch()
                try:
                    j = r.json()
                except Exception:
                    route.fulfill(response=r)
                    return
                modosit(j)
                route.fulfill(response=r, json=j)
            lap.route(re.compile(r'.*/rendeles/[^/]+/allapot.*'), kezel)

        def a_statusz_kesz(lap):
            def m(j):
                j.update({'kesz': True, 'allapot': 'kiszolgalva', 'allapot_szoveg': 'Kifizetve, licenc kiküldve', 'szamla_url': ALAP + '/fiok/'})
            _statusz_mod(lap, m)
            menj(lap, adat['statusz_url'])
            lap.wait_for_selector('.s-statusz .s-uzenet-ok', timeout=10000)
            return '.s-penztar-doboz'

        def a_statusz_kartya(lap):
            def m(j):
                j.update({'atutalas': None, 'ujraproba': True, 'allapot_szoveg': 'A kártyás fizetés nem sikerült'})
            _statusz_mod(lap, m)
            menj(lap, adat['statusz_url'])
            lap.wait_for_selector('.s-statusz .s-gombsor', timeout=10000)
            return '.s-penztar-doboz'

        def a_fiok_belepes(lap):
            menj(lap, '/fiok/')
            lap.wait_for_selector('.s-fiok-belepes')
            return '.s-fiok'

        def a_fiok_link(lap):
            menj(lap, '/fiok/')
            lap.wait_for_selector('.s-fiok-belepes')
            lap.fill('#fiok-email', 'valaki@example.com')
            lap.click('.s-fiok-belepes .s-gomb-fo')
            lap.wait_for_selector('.s-fiok-belepes .s-uzenet', timeout=10000)
            return '.s-fiok'

        def a_fiok(lap):
            menj(lap, '/fiok/')
            lap.wait_for_selector('.s-fiok-fej', timeout=10000)
            if not lap.evaluate("!!document.querySelector('.s-fiok-licenc.s-lejart') && !!document.querySelector('.s-fiok-licenc.s-hamarosan')"):
                hiba('allapot', 'fiók: a lejárt / hamarosan lejáró licenc jelölése hiányzik')
            return '.s-fiok'

        def a_fiok_ures(lap):
            def kezel(route):
                if route.request.method != 'GET':
                    route.continue_()
                    return
                r = route.fetch()
                j = r.json()
                for k in ('licencek', 'letoltesek', 'rendelesek', 'foglalasok', 'egyedi'):
                    j[k] = []
                j['oktatas'] = {}
                route.fulfill(response=r, json=j)
            lap.route(re.compile(r'.*/fiok(\?.*)?$'), kezel)
            menj(lap, '/fiok/')
            lap.wait_for_selector('.s-fiok-fej', timeout=10000)
            return '.s-fiok'

        def a_egyedi(lap):
            menj(lap, '/egyedi-szoftverfejlesztes/')
            lap.wait_for_selector('.s-egyedi')
            gorget(lap, '.s-lepesjelzo')
            return '[data-modul="egyedi"]'

        def a_egyedi_hiba(lap):
            menj(lap, '/egyedi-szoftverfejlesztes/')
            lap.wait_for_selector('.s-egyedi')
            lap.locator('.s-egyedi .s-gombsor .s-gomb-fo:visible').first.click()
            lap.wait_for_timeout(200)
            if not lap.evaluate("document.querySelectorAll('.s-egyedi .s-mezo-hiba:not(:empty)').length"):
                hiba('allapot', 'egyedi: üres Tovább-ra nincs mezőhiba')
            gorget(lap, '.s-lepesjelzo')
            return '[data-modul="egyedi"]'

        def _egyedi_kitolt(lap, cel):
            """Végigkattint a lépéseken (kötelező mezők kitöltésével) a `cel`-edik lépésig (0-tól)."""
            for _ in range(cel):
                lap.evaluate("""(() => {
                  const p = Array.from(document.querySelectorAll('.s-egyedi-lepes')).find(x => !x.hidden);
                  p.querySelectorAll('.s-mezo-sor').forEach(s => {
                    const r = s.querySelector('input[type=radio], input[type=checkbox]:not(.s-kapcsolo)');
                    if (r && !s.querySelector('input:checked')) { r.click(); return; }
                    const m = s.querySelector('input[type=text], input[type=email], input[type=tel], input[type=number], input[type=url], textarea');
                    if (m && !m.value) { m.value = m.type === 'email' ? 'teszt@example.com' : m.type === 'tel' ? '+36301234567' : (m.type === 'number' ? '3' : 'Teszt adat a vizuális ellenőrzéshez'); }
                  });
                })()""")
                lap.locator('.s-egyedi .s-gombsor .s-gomb-fo:visible').first.click()
                lap.wait_for_timeout(150)

        def a_egyedi_utolso(lap):
            menj(lap, '/egyedi-szoftverfejlesztes/')
            lap.wait_for_selector('.s-egyedi')
            n = lap.evaluate("document.querySelectorAll('.s-egyedi-lepes').length")
            _egyedi_kitolt(lap, n - 1)
            if lap.evaluate("Array.from(document.querySelectorAll('.s-egyedi-lepes')).findIndex(x => !x.hidden)") != n - 1:
                hiba('allapot', 'egyedi: kitöltve sem jut el az utolsó lépésig')
            gorget(lap, '.s-lepesjelzo')
            return '[data-modul="egyedi"]'

        def a_egyedi_kesz(lap):
            menj(lap, '/egyedi-szoftverfejlesztes/')
            lap.wait_for_selector('.s-egyedi')
            # Feltöltés: egy kis PNG a csatolmány-mezőbe (a fájl nélküli beküldés szerveroldali hibáját lásd a jelentésben).
            if lap.locator('.s-egyedi input[type=file]').count():
                lap.set_input_files('.s-egyedi input[type=file]', files=[{'name': 'minta.png', 'mimeType': 'image/png', 'buffer': PNG}])
            n = lap.evaluate("document.querySelectorAll('.s-egyedi-lepes').length")
            _egyedi_kitolt(lap, n - 1)
            lap.evaluate("(() => { const p = Array.from(document.querySelectorAll('.s-egyedi-lepes')).find(x => !x.hidden); p.querySelectorAll('input[type=email], input[type=text], input[type=tel], textarea').forEach(m => { if (!m.value) m.value = m.type === 'email' ? 'teszt@example.com' : m.type === 'tel' ? '+36301234567' : 'Teszt'; }); const a = p.querySelector('[name=adatkezeles]'); if (a) a.checked = true; })()")
            lap.wait_for_timeout(3200)  # a kitöltési idő (robotszűrés)
            lap.locator('.s-egyedi .s-gombsor [type=submit]').click()
            try:
                lap.wait_for_selector('.s-egyedi-kesz', timeout=15000)
            except Exception:
                hiba('allapot', 'egyedi: a beküldés után nincs siker-összefoglaló: ' + (lap.text_content('.s-uzenet-sav') or '')[:120])
            return '[data-modul="egyedi"]'

        def a_kapcsolat(lap):
            menj(lap, '/kapcsolat/')
            lap.wait_for_selector('[data-modul="urlap"] form')
            gorget(lap, '[data-modul="urlap"]')
            return '[data-modul="urlap"]'

        def a_kapcsolat_hiba(lap):
            menj(lap, '/kapcsolat/')
            lap.wait_for_selector('[data-modul="urlap"] form')
            lap.wait_for_timeout(3200)
            lap.click('[data-modul="urlap"] [type=submit]')
            try:
                lap.wait_for_selector('[data-modul="urlap"] .s-mezo-hiba:not(:empty)', timeout=10000)
            except Exception:
                hiba('allapot', 'kapcsolat: üres beküldésre nincs mezőhiba')
            gorget(lap, '[data-modul="urlap"]')
            return '[data-modul="urlap"]'

        def a_kapcsolat_kesz(lap):
            menj(lap, '/kapcsolat/')
            lap.wait_for_selector('[data-modul="urlap"] form')
            lap.evaluate("""(() => { const f = document.querySelector('[data-modul="urlap"] form'); f.querySelectorAll('input, textarea, select').forEach(m => {
              if (m.type === 'checkbox') { m.checked = true; return; } if (m.type === 'hidden' || m.closest('[aria-hidden]')) return;
              if (m.tagName === 'SELECT') { if (m.options.length > 1) m.selectedIndex = 1; return; }
              m.value = m.type === 'email' ? 'teszt@example.com' : m.type === 'tel' ? '+36301234567' : m.type === 'number' ? '2' : 'Teszt üzenet a vizuális ellenőrzéshez, elég hosszú.'; }); })()""")
            lap.wait_for_timeout(3200)
            lap.click('[data-modul="urlap"] [type=submit]')
            try:
                lap.wait_for_selector('[data-modul="urlap"] .s-uzenet-ok', timeout=10000)
            except Exception:
                hiba('allapot', 'kapcsolat: kitöltött beküldésre nincs siker-üzenet: ' + (lap.text_content('[data-modul="urlap"]') or '')[:160])
            gorget(lap, '[data-modul="urlap"]')
            return '[data-modul="urlap"]'

        def a_partner(lap):
            menj(lap, '/partnerprogram/')
            lap.wait_for_selector('[data-modul="urlap"] form')
            gomb = lap.text_content('[data-modul="urlap"] [type=submit]') or ''
            if 'Jelentkezem' not in gomb:
                hiba('allapot', f'partner űrlap: nem a partner mezői / gombja jelenik meg („{gomb.strip()}”)')
            gorget(lap, '[data-modul="urlap"]')
            return '[data-modul="urlap"]'

        def a_foglalas(lap):
            menj(lap, '/idopontfoglalas/')
            lap.wait_for_selector('.s-fogl__nap')
            lap.wait_for_timeout(500)
            gorget(lap, '.s-fogl')
            return '[data-modul="foglalas"]'

        def _foglalas_nap(lap):
            menj(lap, '/idopontfoglalas/')
            lap.wait_for_selector('.s-fogl__nap')
            for _ in range(3):
                if lap.locator('.s-fogl__nap[data-van="1"]').count():
                    break
                lap.wait_for_timeout(600)
                if not lap.locator('.s-fogl__nap[data-van="1"]').count():
                    lap.click('.s-fogl__lep >> nth=1')
                    lap.wait_for_timeout(600)
            if not lap.locator('.s-fogl__nap[data-van="1"]').count():
                hiba('allapot', 'foglalás: nincs szabad nap a naptárban (3 hónap)')
                return False
            lap.locator('.s-fogl__nap[data-van="1"]').first.click()
            lap.wait_for_selector('.s-fogl__sav')
            return True

        def a_foglalas_billentyu(lap):
            menj(lap, '/idopontfoglalas/')
            lap.wait_for_selector('.s-fogl__nap')
            lap.wait_for_timeout(500)
            lap.focus('.s-fogl__nap[tabindex="0"]')
            elso = lap.evaluate("document.activeElement.getAttribute('data-nap')")
            lap.keyboard.press('ArrowRight')
            lap.keyboard.press('ArrowDown')
            masod = lap.evaluate("document.activeElement.getAttribute('data-nap')")
            if not elso or not masod or elso == masod:
                hiba('allapot', f'foglalás: a nyilak nem léptetik a fókuszt ({elso} → {masod})')
            f = lap.evaluate(FOKUSZ)
            if f and not f['ring']:
                hiba('allapot', 'foglalás: a fókuszált napon nincs látható gyűrű')
            gorget(lap, '.s-fogl__naptar')
            return '[data-modul="foglalas"]'

        def a_foglalas_urlap(lap):
            if not _foglalas_nap(lap):
                return '[data-modul="foglalas"]'
            lap.locator('.s-fogl__sav').first.click()
            lap.wait_for_selector('.s-fogl__urlap')
            lap.click('.s-fogl__urlap [type=submit]')
            lap.wait_for_timeout(150)
            gorget(lap, '.s-fogl__racs')
            return '[data-modul="foglalas"]'

        def a_foglalas_kesz(lap):
            if not _foglalas_nap(lap):
                return '[data-modul="foglalas"]'
            lap.locator('.s-fogl__sav').last.click()
            lap.wait_for_selector('.s-fogl__urlap')
            lap.fill('.s-fogl__urlap [name=nev]', 'Teszt Elek')
            lap.fill('.s-fogl__urlap [name=email]', f'foglalo.{int(time.time() * 1000)}@example.com')  # e-mailenként napi korlát van
            lap.wait_for_timeout(3200)
            lap.click('.s-fogl__urlap [type=submit]')
            try:
                lap.wait_for_selector('.s-fogl__kartya', timeout=10000)
                if not lap.locator('.s-fogl__kartya a[download]').count():
                    hiba('allapot', 'foglalás: a siker-kártyán nincs ICS-letöltés')
            except Exception:
                hiba('allapot', 'foglalás: nincs siker-kártya: ' + (lap.text_content('.s-fogl__uzenet') or '')[:120])
            gorget(lap, '.s-fogl')
            return '[data-modul="foglalas"]'

        SZIKRA_VALASZ = {'ok': True, 'html': '<p>A <strong>munkalap</strong> modul a javítás teljes útját követi:</p><ul><li>felvétel aláírással és fotóval,</li><li>állapotok és értesítők,</li><li>átadás és garancia.</li></ul><p>Részletek: <a href="' + ALAP + '/funkciok/munkalapok/">Munkalapok</a>.</p>',
                         'forrasok': [{'cim': 'Munkalapok', 'url': ALAP + '/funkciok/munkalapok/'}, {'cim': 'Árak', 'url': ALAP + '/arak/'}], 'hozzajarulas_kell': True}

        def a_szikra(lap):
            menj(lap, '/')
            lap.wait_for_selector('.s-szikra__indito')
            return '.s-szikra'

        def a_szikra_panel(lap):
            varo = []
            lap.route(re.compile(r'.*/szikra/?(\?.*)?$'), lambda r: varo.append(r) if r.request.method == 'POST' else r.continue_())
            menj(lap, '/')
            lap.click('.s-szikra__indito')
            lap.wait_for_selector('.s-szikra__panel:not([hidden])')
            if lap.evaluate("document.activeElement && document.activeElement.classList.contains('s-szikra__mezo')") is not True:
                hiba('allapot', 'Szikra: nyitáskor a fókusz nem a mezőn')
            lap.fill('.s-szikra__mezo', 'Mit tud a munkalap modul?')
            lap.keyboard.press('Enter')
            lap.wait_for_selector('.s-szikra__gondolkodik')
            lap.wait_for_timeout(100)
            return '.s-szikra'

        def a_szikra_valasz(lap):
            lap.route(re.compile(r'.*/szikra/?(\?.*)?$'), lambda r: r.fulfill(json=SZIKRA_VALASZ) if r.request.method == 'POST' else r.continue_())
            menj(lap, '/')
            lap.click('.s-szikra__indito')
            lap.fill('.s-szikra__mezo', 'Mit tud a munkalap modul?')
            lap.keyboard.press('Enter')
            lap.wait_for_selector('.s-szikra__hozzajarulas')
            lap.wait_for_timeout(200)
            return '.s-szikra'

        def a_szikra_hiba(lap):
            menj(lap, '/')
            lap.click('.s-szikra__indito')
            lap.fill('.s-szikra__mezo', 'Szia!')
            lap.keyboard.press('Enter')
            lap.wait_for_selector('.s-szikra__uzenet--hiba', timeout=20000)
            lap.keyboard.press('Escape')
            lap.wait_for_timeout(100)
            if not lap.evaluate("document.querySelector('.s-szikra__panel').hidden"):
                hiba('allapot', 'Szikra: Esc nem zárja a panelt')
            if lap.evaluate("document.activeElement && document.activeElement.classList.contains('s-szikra__indito')") is not True:
                hiba('allapot', 'Szikra: záráskor a fókusz nem tér vissza az indítóra')
            lap.click('.s-szikra__indito')
            lap.wait_for_timeout(150)
            return '.s-szikra'

        def a_oktatas(lap):
            menj(lap, '/oktatas/')
            lap.wait_for_selector('.s-okt__kurzus, .s-okt__ures')
            gorget(lap, '.s-okt')
            return '[data-modul="oktatas"]'

        def a_oktatas_lepes(lap):
            menj(lap, '/oktatas/')
            lap.wait_for_selector('.s-okt__lecke-gomb')
            lap.locator('.s-okt__lecke-gomb').first.click()
            lap.wait_for_selector('.s-okt__lepes')
            nxt = lap.locator('.s-okt__gombsor .s-okt__gomb:not(.s-okt__gomb--halk)')
            if nxt.count():
                nxt.first.click()
                lap.wait_for_timeout(150)
            gorget(lap, '.s-okt')
            return '[data-modul="oktatas"]'

        def a_oktatas_kviz(lap):
            menj(lap, '/oktatas/')
            lap.wait_for_selector('.s-okt__lecke-gomb')
            lap.locator('.s-okt__lecke-gomb').first.click()
            lap.wait_for_selector('.s-okt__lepes')
            for _ in range(30):
                if lap.locator('.s-okt__kviz').count():
                    break
                g = lap.locator('.s-okt__gombsor .s-okt__gomb:not(.s-okt__gomb--halk)').last
                if not g.count():
                    break
                g.click()
                lap.wait_for_timeout(80)
            if not lap.locator('.s-okt__kviz').count():
                jegyzet.append('oktatás: az első leckében nincs kvíz')
                return '[data-modul="oktatas"]'
            lap.evaluate("document.querySelectorAll('.s-okt__kerdes').forEach(f => { const i = f.querySelector('input'); if (i) i.checked = true; })")
            lap.click('.s-okt__kviz [type=submit]')
            lap.wait_for_selector('.s-okt__eredmeny:not(:empty)', timeout=10000)
            lap.wait_for_timeout(150)
            return '[data-modul="oktatas"]'

        def a_suti(lap):
            menj(lap, '/')
            lap.wait_for_selector('#s-suti:not([hidden])')
            g = lap.evaluate("Array.from(document.querySelectorAll('#s-suti .s-suti-gomb')).map(b => { const r = b.getBoundingClientRect(), s = getComputedStyle(b); return [Math.round(r.width), Math.round(r.height), s.backgroundColor, s.color, s.borderTopColor, s.fontWeight]; })")
            if len(g) < 3 or len({json.dumps(x[1:]) for x in g}) != 1 or (max(x[0] for x in g) - min(x[0] for x in g)) > 2:
                hiba('allapot', f'süti-sáv: a gombok nem egyformák {g}')
            return '#s-suti'

        def a_suti_modal(lap):
            menj(lap, '/')
            lap.wait_for_selector('#s-suti:not([hidden])')
            sz0 = lap.evaluate('document.documentElement.clientWidth')
            lap.click('#s-suti [data-suti-muvelet="beallit"]')
            lap.wait_for_selector('#s-suti-modal:not([hidden])')
            m = lap.evaluate("(() => ({ lock: getComputedStyle(document.documentElement).overflow, szel: document.documentElement.clientWidth, fokusz: !!document.activeElement.closest('.s-suti-ablak') }))()")
            if m['lock'] != 'hidden':
                hiba('allapot', f'süti-ablak: nincs görgetészár ({m["lock"]})')
            if m['szel'] != sz0:
                hiba('allapot', f'süti-ablak: nyitáskor elugrik a tartalom ({sz0}→{m["szel"]}px)')
            if not m['fokusz']:
                hiba('allapot', 'süti-ablak: nyitáskor a fókusz nem az ablakban')
            kint = 0
            for _ in range(14):
                lap.keyboard.press('Tab')
                if not lap.evaluate("!!document.activeElement.closest('.s-suti-ablak')"):
                    kint += 1
                f = lap.evaluate(FOKUSZ)
                if f and not f['ring']:
                    hiba('allapot', f'süti-ablak: fókuszgyűrű nélkül {f["sel"]}')
            if kint:
                hiba('allapot', f'süti-ablak: a fókusz {kint}× kiszökött (fókuszcsapda)')
            lap.keyboard.press('Shift+Tab')
            lap.wait_for_timeout(50)
            return '#s-suti-modal'

        def a_suti_lab(lap):
            menj(lap, '/')
            lap.wait_for_timeout(300)
            if lap.evaluate("!document.querySelector('#s-suti').hidden"):
                hiba('allapot', 'süti: döntés után is látszik a sáv')
            l = lap.locator('.s-suti-megnyit')
            if not l.count():
                hiba('allapot', 'süti: nincs újranyitó link a láblécben')
                return '#s-suti-modal'
            l.first.scroll_into_view_if_needed()
            l.first.click()
            lap.wait_for_selector('#s-suti-modal:not([hidden])')
            lap.keyboard.press('Escape')
            lap.wait_for_timeout(100)
            if not lap.evaluate("document.querySelector('#s-suti-modal').hidden"):
                hiba('allapot', 'süti-ablak: Esc nem zár')
            if not lap.evaluate("!!document.activeElement.closest('.s-suti-megnyit')"):
                hiba('allapot', 'süti-ablak: záráskor a fókusz nem tér vissza a lábléc-linkre')
            l.first.click()
            lap.wait_for_selector('#s-suti-modal:not([hidden])')
            return '#s-suti-modal'

        def a_toast(lap):
            menj(lap, '/kosar/')
            lap.wait_for_selector('.s-kosar-ures')
            lap.evaluate("APP.uzen('A kosárba került.', 'ok'); APP.uzen('Valami nem sikerült, kérjük, próbáld újra egy perc múlva.', 'hiba'); APP.uzen('Tájékoztató üzenet.', 'info')")
            lap.wait_for_timeout(500)
            return '.s-uzenet-sav'

        ALLAPOTOK = [
            # név, függvény, kosár (None = üres), belépve, süti elfogadva
            ('kalk', a_kalk, None, False, True),
            ('kalk-havi-modulok', a_kalk_havi, None, False, True),
            ('kalk-hiba', a_kalk_hiba, None, False, True),
            ('kosar', a_kosar, KOSAR_TELE, False, True),
            ('kosar-kupon-hiba', a_kosar_kupon_hiba, KOSAR_TELE, False, True),
            ('kosar-kupon-ok', a_kosar_kupon_ok, KOSAR_TELE, False, True),
            ('kosar-torles', a_kosar_torles, KOSAR_TELE, False, True),
            ('kosar-ures', a_kosar_ures, None, False, True),
            ('penztar', a_penztar, KOSAR_TELE, False, True),
            ('penztar-hiba', a_penztar_hiba, KOSAR_TELE, False, True),
            ('statusz', a_statusz, None, False, True),
            ('statusz-kesz', a_statusz_kesz, None, False, True),
            ('statusz-kartya', a_statusz_kartya, None, False, True),
            ('fiok-belepes', a_fiok_belepes, None, False, True),
            ('fiok-link', a_fiok_link, None, False, True),
            ('fiok', a_fiok, None, True, True),
            ('fiok-ures', a_fiok_ures, None, True, True),
            ('egyedi', a_egyedi, None, False, True),
            ('egyedi-hiba', a_egyedi_hiba, None, False, True),
            ('egyedi-utolso', a_egyedi_utolso, None, False, True),
            ('egyedi-kesz', a_egyedi_kesz, None, False, True),
            ('kapcsolat', a_kapcsolat, None, False, True),
            ('kapcsolat-hiba', a_kapcsolat_hiba, None, False, True),
            ('kapcsolat-kesz', a_kapcsolat_kesz, None, False, True),
            ('partner', a_partner, None, False, True),
            ('foglalas', a_foglalas, None, False, True),
            ('foglalas-billentyu', a_foglalas_billentyu, None, False, True),
            ('foglalas-urlap', a_foglalas_urlap, None, False, True),
            ('foglalas-kesz', a_foglalas_kesz, None, False, True),
            ('szikra', a_szikra, None, False, True),
            ('szikra-panel', a_szikra_panel, None, False, True),
            ('szikra-valasz', a_szikra_valasz, None, False, True),
            ('szikra-hiba', a_szikra_hiba, None, False, True),
            ('oktatas', a_oktatas, None, False, True),
            ('oktatas-lepes', a_oktatas_lepes, None, False, True),
            ('oktatas-kviz', a_oktatas_kviz, None, False, True),
            ('suti', a_suti, None, False, False),
            ('suti-modal', a_suti_modal, None, False, False),
            ('suti-lab', a_suti_lab, None, False, True),
            ('toast', a_toast, None, False, True),
        ]
        # A szándékos hibás beküldések 4xx válaszai nem hibák.
        VART_4XX = {'kosar-kupon-hiba': '/kupon', 'penztar-hiba': '/rendeles', 'egyedi-hiba': '/egyedi', 'kapcsolat-hiba': '/lead', 'szikra-hiba': '/szikra', 'fiok-belepes': '/fiok', 'fiok-link': '/fiok'}
        allapotok = [a for a in ALLAPOTOK if not SZURO or SZURO in a[0]]
        # A sikeres beküldések (új lead / foglalás / igény) csak egy szélességen futnak, hogy ne szemeteljék a tesztoldalt.
        EGYSZER = {'egyedi-kesz', 'kapcsolat-kesz', 'foglalas-kesz'}
        # Beküldő állapotok: előttük a tesztoldal IP-nkénti korlátait ürítjük (különben egy teljes bejárás alatt betelnek).
        KORLATOS = {'penztar-hiba', 'fiok-link', 'egyedi-kesz', 'kapcsolat-hiba', 'kapcsolat-kesz', 'foglalas-kesz', 'kosar-kupon-hiba', 'kosar-kupon-ok'}

        for (w, h) in NEZETEK:
            for mod in MODOK:
                for nev, fv, kosar, belepve, suti in allapotok:
                    if nev in EGYSZER and (w, mod) not in ((390, 'sotet'), (1366, 'vilagos')):
                        continue
                    ctx = b.new_context(viewport={'width': w, 'height': h}, device_scale_factor=1, color_scheme='dark' if mod == 'sotet' else 'light',
                                        is_mobile=w < 700, has_touch=w < 700, locale='hu-HU', permissions=['clipboard-read', 'clipboard-write'])
                    ctx.add_init_script("try{localStorage.setItem('szinmod','%s'); sessionStorage.removeItem('szikra')}catch(e){}" % mod)
                    if kosar:
                        kosar_init(ctx, kosar)
                    else:
                        ctx.add_init_script('try{localStorage.removeItem("kosar_v1")}catch(e){}')
                    if suti:
                        ctx.add_cookies([suti_cookie()])
                    if belepve and belepve_suti:
                        ctx.add_cookies(belepve_suti)
                    if nev in KORLATOS:
                        seged('korlat')
                    lap = ctx.new_page()
                    konzol, halo = [], []
                    lap.on('console', lambda m, k=konzol: k.append(m.type + ': ' + m.text) if m.type in ('error', 'warning') else None)
                    lap.on('pageerror', lambda e, k=konzol: k.append('pageerror: ' + str(e)))
                    lap.on('response', lambda r, k=halo: k.append(f'{r.status} {r.request.method} {r.url}') if r.status >= 400 and r.url.startswith(GYOKER) else None)
                    cimke = f'{nev}@{w}/{mod}'
                    try:
                        gyok = fv(lap)
                        lap.evaluate('document.fonts.ready')
                    except Exception as e:  # noqa: BLE001
                        hiba('allapot', f'{cimke}: {str(e).splitlines()[0][:160]}')
                        try:
                            lap.screenshot(path=os.path.join(KI, f'{nev}-{w}-{mod}-HIBA.png'))
                        except Exception:
                            pass
                        ctx.close()
                        continue
                    if lap.evaluate("document.documentElement.getAttribute('data-tema')") != mod:
                        hiba('tema', f'{cimke}: data-tema nem {mod}')
                    lap.evaluate("Promise.all(document.getAnimations().filter(a => { const t = a.effect && a.effect.getTiming(); return t && isFinite(t.iterations); }).map(a => a.finished.catch(() => null)))")
                    for k, lista in lap.evaluate(ELLENORZES).items():
                        for s in lista:
                            hiba(k, f'{cimke}: {s}')
                    vart = VART_4XX.get(nev)
                    elvart = ('4', '5') if nev == 'szikra-hiba' else ('4',)  # Szikra hálózat nélkül: 502
                    halo2 = [x for x in halo if not (vart and vart in x and x[:1] in elvart)]
                    konz2 = [x for x in konzol if not (vart and any('status of ' + e in x for e in elvart))]
                    for x in (konz2[:3] + halo2[:3]):
                        hiba('konzol', f'{cimke}: {x[:180]}')
                    try:
                        fn = os.path.join(KI, f'{nev}-{w}-{mod}.png')
                        loc = lap.locator(gyok).first if gyok else None
                        if gyok in ('.s-uzenet-sav', '#s-suti', '#s-suti-modal', '.s-szikra') or not loc or not loc.count():
                            lap.screenshot(path=fn)
                        else:
                            bb = lap.evaluate("(s) => { const e = document.querySelector(s); const p = e.closest('.s-panel') || e; const r = p.getBoundingClientRect(); return { x: Math.max(0, r.left - 8), y: Math.max(0, r.top + scrollY - 8), width: Math.min(document.documentElement.clientWidth, r.width + 16), height: Math.min(3000, r.height + 16) }; }", gyok)
                            # a lebegő Szikra-indító (fixed) a teljes oldalas képen a panelre kerülne: ezekről a képekről elrejtjük
                            lap.evaluate("(() => { const s = document.querySelector('.s-szikra--lebego'); if (s) s.style.visibility = 'hidden'; window.scrollTo(0, 0); })()")
                            lap.wait_for_timeout(80)
                            lap.screenshot(path=fn, full_page=True, clip=bb)
                        kepek.append(fn)
                    except Exception as ex:  # noqa: BLE001
                        jegyzet.append(f'{cimke}: képernyőkép hiba {str(ex)[:80]}')
                    ctx.close()

        # ---------- Fókuszgyűrű és hover a modulok vezérlőin (1366, mindkét mód) ----------
        for mod in MODOK:
            for ut, kosar in (('/arak/', None), ('/kosar/', KOSAR_TELE), ('/penztar/', KOSAR_TELE), ('/egyedi-szoftverfejlesztes/', None), ('/kapcsolat/', None)):
                ctx = b.new_context(viewport={'width': 1366, 'height': 900}, color_scheme='dark' if mod == 'sotet' else 'light', locale='hu-HU')
                ctx.add_init_script("try{localStorage.setItem('szinmod','%s')}catch(e){}" % mod)
                if kosar:
                    kosar_init(ctx, kosar)
                ctx.add_cookies([suti_cookie()])
                lap = ctx.new_page()
                menj(lap, ut)
                lap.wait_for_selector('[data-modul][data-kesz] button, [data-modul][data-kesz] input', timeout=10000)
                lap.wait_for_timeout(500)
                lap.focus('[data-modul]:not([data-modul=szikra]) button, [data-modul]:not([data-modul=szikra]) input:not([type=hidden])')
                lap.keyboard.press('Shift+Tab')
                rossz = []
                for _ in range(24):
                    lap.keyboard.press('Tab')
                    f = lap.evaluate(FOKUSZ)
                    if f and f['modul'] and not f['ring']:
                        rossz.append(f['sel'])
                for r in sorted(set(rossz)):
                    hiba('allapot', f'fókuszgyűrű nélkül {ut} ({mod}): {r}')
                ctx.close()

        # ---------- Csökkentett mozgás ----------
        ctx = b.new_context(viewport={'width': 1366, 'height': 900}, reduced_motion='reduce', locale='hu-HU')
        kosar_init(ctx, KOSAR_TELE)
        ctx.add_cookies([suti_cookie()])
        lap = ctx.new_page()
        for ut in ('/arak/', '/kosar/', '/idopontfoglalas/', '/egyedi-szoftverfejlesztes/', '/oktatas/'):
            menj(lap, ut)
            lap.wait_for_timeout(400)
            if ut == '/arak/':
                lap.evaluate("APP.uzen('Teszt', 'ok')")
                lap.click('.s-szikra__indito')
                lap.wait_for_timeout(100)
            for x in lap.evaluate(MOZGAS):
                hiba('mozgas', f'{ut}: {x}')
        ctx.close()

        # ---------- Nyomtatás: a lebegő elemek rejtve ----------
        ctx = b.new_context(viewport={'width': 1366, 'height': 900}, locale='hu-HU')
        lap = ctx.new_page()
        menj(lap, '/kosar/')
        lap.evaluate("APP.uzen('Teszt', 'ok')")
        lap.emulate_media(media='print')
        pr = lap.evaluate("(() => ['.s-szikra--lebego', '#s-suti', '.s-uzenet-sav'].map(s => { const e = document.querySelector(s); return [s, e ? getComputedStyle(e).display : 'none']; }))()")
        for s, v in pr:
            if v != 'none':
                hiba('nyomtatas', f'{s} nyomtatásban {v}')
        ctx.close()
        b.close()

    with open(os.path.join(KI, 'hibak.json'), 'w', encoding='utf-8') as f:
        json.dump({'hibak': hibak, 'jegyzet': jegyzet}, f, ensure_ascii=False, indent=1)

    sorrend = ['tulcsordulas', 'atfedes', 'celterulet', 'szoveg', 'kontraszt', 'kapcsolo', 'iv', 'tema', 'datum', 'rejtes', 'allapot', 'mozgas', 'nyomtatas', 'konzol']
    osszes = sum(len(v) for v in hibak.values())
    print(f"modulok vizuális QA: {'OK' if osszes == 0 else 'HIBA'} – {len(allapotok)} állapot × {len(NEZETEK)} szélesség × 2 mód, {osszes} hiba, {len(kepek)} kép → {KI}")
    for k in sorrend + [x for x in hibak if x not in sorrend]:
        n = len(hibak.get(k, []))
        if n:
            print(f'  {k:13} {n:4}')
            for s in hibak[k][:8]:
                print('      ✗ ' + s[:230])
    for j in jegyzet[:5]:
        print('  · ' + j)
    sys.exit(0 if osszes == 0 else 1)


main()
