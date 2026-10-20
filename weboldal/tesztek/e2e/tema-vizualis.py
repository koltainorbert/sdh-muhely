#!/usr/bin/env python3
"""
Téma – vizuális QA bejáró (Playwright, Chromium). A téma statikus oldalait és szekcióit járja végig 390 / 768 / 1366 / 1920
szélességen, sötét és világos módban, és minden oldalon méri (kovetes/vizualis-qa.md ellenőrzőlistája):

  1 túlcsordulás     – vízszintes görgetés, a nézeten túlnyúló elem
  2 átfedés          – egymást fedő szöveg / gomb / kép / kártya (nem ős–utód, > 2 px)
  3 rács             – egy sorban eltérő kártyamagasság / felső él, összeomlott oszlop 768-on, árva kártya, 44 px alatti célterület
  4 szöveg           – levágott szöveg, túl hosszú sor (> 80 ch), cím text-wrap nélkül, két sorba tört gomb, betűk (Onest, Bricolage, JetBrains, latin-ext)
  5 kép              – width/height nélkül, törött (naturalWidth 0), torz arány (object-fit nélkül)
  6 kontraszt        – szöveg / háttér ≥ 4,5:1 (nagy szöveg ≥ 3:1), mezőkeret ≥ 3:1
  7 állapot          – fókuszgyűrű látszik (Tab), kapcsoló felirat nélkül, ≥ 6 px levegő
  8 ív               – minden mező / gomb / pill lekerekített; többsoros ≤ 15 px
  9 ritmus           – szekciók közti levegő (sáv után 0 felső padding, dupla margó), lábléc leválása
 10 fejléc           – 72 px, ragadós, scroll-padding, mobil menü (nyit / zár / görgetészár), legördülő a nézeten belül, CLS < 0,05
 11 téma             – data-tema a kért mód, nagy felület rossz fényerővel (sötétben világos / világosban sötét)
 12 nyomtatás        – fejléc / lábléc / süti / kereső rejtve
 13 mozgás           – prefers-reduced-motion: reduce mellett nincs 20 ms-nál hosszabb animáció / átmenet
 14 konzol           – 0 hiba, 0 figyelmeztetés a saját kódból; 0 sikertelen (4xx / 5xx) saját kérés

Futtatás: python3 weboldal/tesztek/e2e/tema-vizualis.py http://127.0.0.1:8871/wp [kimeneti mappa] [--gyors] [--oldal=<részlet>] [--kep]
  --gyors : csak 390 + 1366, mindkét mód      --oldal=arak : csak az illeszkedő oldalak      --kep : teljes oldalas képernyőképek
  --szel=390,768 : csak ezek a szélességek    --mod=sotet|vilagos : csak ez a mód             --nincs-interakcio : csak az oldalbejárás
Kimenet: hibatábla ellenőrzésenként (db + első találatok), hibak.json + képek a kimeneti mappában. Kilépési kód 0, ha nincs hiba.
"""
import json
import os
import re
import sys
import time

from playwright.sync_api import sync_playwright

ALAP = (next((a for a in sys.argv[1:] if a.startswith('http')), 'http://127.0.0.1:8871/wp')).rstrip('/')
KI = next((a for a in sys.argv[2:] if not a.startswith('-') and not a.startswith('http')), os.path.join(os.path.dirname(os.path.abspath(__file__)), 'kepek-vizualis'))
GYORS = '--gyors' in sys.argv
KEP = '--kep' in sys.argv
SZURO = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--oldal=')), '')
os.makedirs(KI, exist_ok=True)

# (név, út, kötelező-e) – a nem kötelező oldal hiánya (404) csak megjegyzés.
OLDALAK = [
    ('kezdolap', '/', True),
    ('funkciok', '/funkciok/', True),
    ('funkcio-munkalapok', '/funkciok/munkalapok/', True),
    ('funkcio-asszisztens', '/funkciok/asszisztens/', True),
    ('funkcio-szamlazas', '/funkciok/szamlazas/', True),
    ('funkcio-offline', '/funkciok/offline/', True),
    ('modulok', '/modulok/', True),
    ('arak', '/arak/', True),
    ('iparagak', '/iparagak/', True),
    ('iparag-szerviz', '/iparagak/szerviz/', True),
    ('egyedi-modulfejlesztes', '/egyedi-modulfejlesztes/', True),
    ('demo', '/demo/', True),
    ('telepites', '/telepites/', True),
    ('oktatas', '/oktatas/', True),
    ('gyik', '/gyik/', True),
    ('osszehasonlitas', '/osszehasonlitas/', True),
    ('esettanulmany', '/esettanulmany/', True),
    ('blog', '/blog/', True),
    ('cikk', os.environ.get('VQA_CIKK', '/szerviz-kpi-mutatok-riportok/'), True),
    ('kapcsolat', '/kapcsolat/', True),
    ('idopontfoglalas', '/idopontfoglalas/', True),
    ('rolunk', '/rolunk/', True),
    ('partnerprogram', '/partnerprogram/', True),
    ('statusz', '/statusz/', True),
    ('adatvedelem', '/adatvedelem-biztonsag/', True),
    ('jogi', '/jogi/', True),
    ('jogi-aszf', '/jogi/aszf/', True),
    ('tudasbazis', '/tudasbazis/', True),
    ('tudas-fejezet', '/tudasbazis/elso-lepesek/', True),
    ('kereses', '/kereses/?s=munkalap', True),
    ('kereses-talalat', '/?s=munkalap', True),
    ('kosar', '/kosar/', True),
    ('404', '/nincs-ilyen/', True),
    ('teszt-szekciok', '/teszt-szekciok/', False),
]
NEZETEK = [(390, 844), (768, 1024), (1366, 900), (1920, 1080)]
if GYORS:
    NEZETEK = [(390, 844), (1366, 900)]
SZEL = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--szel=')), '')
if SZEL:
    NEZETEK = [x for x in NEZETEK if str(x[0]) in SZEL.split(',')]
MODOK = ('sotet', 'vilagos')
MODSZ = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--mod=')), '')
if MODSZ:
    MODOK = tuple(m for m in MODOK if m == MODSZ)
INTERAKCIO = '--nincs-interakcio' not in sys.argv

MERO = """
window.__cls = 0;
try { new PerformanceObserver(function (l) { l.getEntries().forEach(function (e) { if (!e.hadRecentInput) { window.__cls += e.value; } }); }).observe({type: 'layout-shift', buffered: true}); } catch (e) {}
"""

# Az oldalon futó ellenőrzések – egy objektumot ad vissza: {ellenőrzés: [leírás, …]}.
ELLENORZES = r"""() => {
  const W = document.documentElement.clientWidth;
  const hibak = {};
  const add = (k, s) => { (hibak[k] = hibak[k] || []).push(s); };
  const cls = el => (typeof el.className === 'string' ? el.className : (el.className && el.className.baseVal) || '').trim().split(/\s+/).filter(Boolean);
  const sel = el => { let s = el.tagName.toLowerCase(); if (el.id) s += '#' + el.id; const c = cls(el).slice(0, 2); if (c.length) s += '.' + c.join('.'); return s; };
  const ut = el => { const p = []; let e = el; while (e && e !== document.body && p.length < 3) { p.unshift(sel(e)); e = e.parentElement; } return p.join('>'); };
  const rov = s => String(s || '').replace(/\s+/g, ' ').trim().slice(0, 40);
  const cs = el => getComputedStyle(el);
  const lathato = el => {
    const st = cs(el); if (st.display === 'none' || st.visibility === 'hidden' || st.opacity === '0') return false;
    /* zárt <details> tartalma (content-visibility: hidden) és rejtett ősök */
    if (el.checkVisibility && !el.checkVisibility({ contentVisibilityAuto: false, opacityProperty: true, visibilityProperty: true })) return false;
    const d = el.closest('details:not([open])'); if (d && d !== el && !el.closest('summary')) return false;
    const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0;
  };
  /* Hány sorba tört egy elem szövege (a szövegcsomópontok soronkénti téglalapjai alapján) */
  const sorszam = el => {
    const tetok = new Set();
    const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    for (let n = w.nextNode(); n; n = w.nextNode()) {
      if (!n.textContent.trim() || (n.parentElement && n.parentElement.closest('.s-sr'))) continue;
      const rg = document.createRange(); rg.selectNodeContents(n);
      for (const r of rg.getClientRects()) { if (r.width > 1 && r.height > 1) tetok.add(Math.round(r.top / 4)); }
    }
    return tetok.size;
  };
  const sajatSzoveg = el => { for (const n of el.childNodes) { if (n.nodeType === 3 && n.textContent.trim()) return true; } return false; };
  const KIHAGY = '[hidden], .s-sr, .screen-reader-text, .s-sprite, script, style, noscript, template, .s-kereso, .s-suti, .s-ertesitok, .s-ugro, .s-szikra, [data-modul]';
  const mind = Array.from(document.querySelectorAll('body *')).filter(el => !el.closest(KIHAGY) && lathato(el));

  /* 1. túlcsordulás */
  if (document.documentElement.scrollWidth > W) add('tulcsordulas', 'dokumentum ' + (document.documentElement.scrollWidth - W) + 'px');
  for (const el of mind) {
    const st = cs(el);
    if (st.position === 'fixed') continue;
    if (el.closest('[aria-hidden="true"]:not(svg):not(span)')) continue;
    let gorg = false;
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) { const o = cs(p).overflowX; if (o === 'auto' || o === 'scroll') { gorg = true; break; } }
    if (gorg) continue;
    const r = el.getBoundingClientRect();
    if (r.right > W + 1 || r.left < -1) add('tulcsordulas', ut(el) + ' ' + Math.round(r.left) + '…' + Math.round(r.right));
  }

  /* 2. átfedés */
  const jelolt = [];
  for (const el of mind) {
    const t = el.tagName;
    const st = cs(el);
    if (st.position === 'absolute' || st.position === 'fixed') continue;
    if (el.closest('[aria-hidden="true"]:not(svg):not(span)')) continue;
    if (el.closest('.s-tabla-gorgeto, .tablazat, pre')) continue;
    const jo = sajatSzoveg(el) || t === 'IMG' || t === 'svg' || t === 'INPUT' || t === 'SELECT' || t === 'TEXTAREA' || t === 'BUTTON' || el.classList.contains('s-kartya') || el.classList.contains('s-gomb') || el.classList.contains('s-pill') || el.classList.contains('s-ikondoboz');
    if (!jo) continue;
    const inl = st.display === 'inline';
    const rects = inl ? Array.from(el.getClientRects()) : [el.getBoundingClientRect()];
    for (const r of rects) { if (r.width > 0 && r.height > 0) jelolt.push({ el, r }); }
  }
  jelolt.sort((a, b) => a.r.top - b.r.top);
  const lattam = new Set();
  for (let i = 0; i < jelolt.length; i++) {
    const a = jelolt[i];
    for (let j = i + 1; j < jelolt.length && jelolt[j].r.top < a.r.bottom - 2; j++) {
      const b = jelolt[j];
      if (a.el === b.el || a.el.contains(b.el) || b.el.contains(a.el)) continue;
      const w = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left);
      const h = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top);
      if (w > 2 && h > 2) {
        const k = ut(a.el) + ' × ' + ut(b.el);
        if (!lattam.has(k)) { lattam.add(k); add('atfedes', k + ' (' + Math.round(w) + '×' + Math.round(h) + ') „' + rov(a.el.textContent) + '” / „' + rov(b.el.textContent) + '”'); }
      }
    }
  }

  /* 3. rács */
  document.querySelectorAll('.s-racs, .s-lepesek, .s-szamok, .s-kapcsolodo__lista, .s-lablec__racs, .s-esemenyek, .s-logo-sav, .s-hero__racs, .s-kepszoveg').forEach(g => {
    if (!lathato(g) || g.closest(KIHAGY)) return;
    const kids = Array.from(g.children).filter(lathato);
    if (kids.length < 2) return;
    const sorok = [];
    kids.forEach(k => { const r = k.getBoundingClientRect(); const s = sorok.find(x => Math.abs(x.top - r.top) <= 2); if (s) s.elemek.push({ k, r }); else sorok.push({ top: r.top, elemek: [{ k, r }] }); });
    const kartyas = g.classList.contains('s-racs') || g.classList.contains('s-kapcsolodo__lista') || g.classList.contains('s-esemenyek');
    sorok.forEach(s => {
      if (s.elemek.length < 2) return;
      const mag = s.elemek.map(x => x.r.height);
      if (kartyas && Math.max(...mag) - Math.min(...mag) > 2) add('racs', ut(g) + ' egyenetlen magasság ' + mag.map(Math.round).join('/'));
    });
    if (W >= 640 && /s-racs--[234]/.test(g.className) && sorok[0].elemek.length === 1 && kids.length > 1) add('racs', ut(g) + ' 1 oszlopra esett ' + W + 'px-en');
    if (sorok.length > 1 && sorok[0].elemek.length >= 3 && sorok[sorok.length - 1].elemek.length === 1 && kartyas) add('racs', ut(g) + ' árva kártya az utolsó sorban (' + kids.length + ' elem, ' + sorok[0].elemek.length + ' oszlop)');
  });
  document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button]').forEach(el => {
    if (!lathato(el) || el.closest(KIHAGY)) return;
    if (el.closest('.s-proza, p, .s-morzsa, .s-kartya__szoveg, .s-tj, .s-forraslista, .s-lablec__ceg, .s-lablec__jogi, .s-entitas__linkek, address, .s-fejezetlista, .s-kartya__cim, .s-eyebrow, li.s-tj__elem, .s-pillsor')) return;
    let r = el.classList.contains('s-kartya__link') && el.closest('.s-kartya') ? el.closest('.s-kartya').getBoundingClientRect() : el.getBoundingClientRect();
    /* jelölő / rádió: a hozzá tartozó címke (vagy a .s-jelolo sor) is célterület */
    if (el.labels && el.labels.length && /checkbox|radio/.test(el.type)) {
      const sor = el.closest('.s-jelolo, label') || el.labels[0];
      const lr = sor.getBoundingClientRect(); if (lr.height > r.height) r = lr;
    }
    /* érintőképernyőn ≥ 44 px (a QA-szabály), egérrel a WCAG 2.5.8 AA minimum (≥ 24 px) */
    const min = matchMedia('(pointer: coarse)').matches ? 44 : 24;
    if (r.height < min || r.width < min) add('racs', ut(el) + ' célterület ' + Math.round(r.width) + '×' + Math.round(r.height) + ' „' + rov(el.textContent || el.value) + '”');
  });

  /* 4. szöveg */
  for (const el of mind) {
    if (!sajatSzoveg(el)) continue;
    const st = cs(el);
    if (st.display === 'inline' || st.textOverflow === 'ellipsis' || st.overflowX === 'auto' || st.overflowX === 'scroll') continue;
    if (el.closest('.s-tabla-gorgeto, .tablazat, pre, code')) continue;
    if (el.scrollWidth > el.clientWidth + 1) add('szoveg', ut(el) + ' levágva/kilóg ' + el.scrollWidth + '>' + el.clientWidth + ' „' + rov(el.textContent) + '”');
    /* sorhossz: a bekezdés tényleges karakter / sor átlaga (az utolsó, rövid sor nélkül) */
    if (el.tagName === 'P' && el.textContent.trim().length > 240 && !el.closest('.s-kartya, .s-harmonika, .s-lablec, .s-tabla')) {
      const n = sorszam(el);
      if (n >= 3) { const kps = el.textContent.replace(/\s+/g, ' ').trim().length / (n - 0.5); if (kps > 82) add('szoveg', ut(el) + ' sorhossz ≈ ' + Math.round(kps) + ' karakter/sor'); }
    }
  }
  document.querySelectorAll('h1, h2, h3').forEach(h => {
    if (!lathato(h) || h.closest(KIHAGY)) return;
    const st = cs(h);
    const tw = st.textWrap || st.textWrapStyle || st.getPropertyValue('text-wrap') || st.getPropertyValue('text-wrap-style');
    if (!/balance/.test(String(tw))) add('szoveg', ut(h) + ' text-wrap: ' + tw);
  });
  /* szó közepén tört cím / gomb (overflow-wrap: break-word mentette meg a kilógástól, de csúnya): egy szó két sorban */
  document.querySelectorAll('h1, h2, h3, .s-gomb, .s-kartya__cim').forEach(h => {
    if (!lathato(h) || h.closest(KIHAGY)) return;
    const w = document.createTreeWalker(h, NodeFilter.SHOW_TEXT);
    for (let n = w.nextNode(); n; n = w.nextNode()) {
      const t = n.textContent; const re = /[^\s\-–‑\/]+/g; let m;
      while ((m = re.exec(t))) {
        if (m[0].length < 4) continue;
        const rg = document.createRange(); rg.setStart(n, m.index); rg.setEnd(n, m.index + m[0].length);
        const tetok = new Set(Array.from(rg.getClientRects()).filter(r => r.width > 1).map(r => Math.round(r.top / 4)));
        if (tetok.size > 1) { add('szoveg', ut(h) + ' szó közepén tört: „' + m[0] + '”'); return; }
      }
    }
  });
  document.querySelectorAll('.s-gomb, .s-fomenu__link, .s-pill').forEach(g => {
    if (!lathato(g) || g.closest(KIHAGY)) return;
    const r = g.getBoundingClientRect();
    const szul = g.parentElement ? g.parentElement.getBoundingClientRect().width : 0;
    const n = sorszam(g);
    if (n > 1 && szul >= 200) add('szoveg', ut(g) + ' gombszöveg ' + n + ' sorban ' + Math.round(r.width) + '×' + Math.round(r.height) + ' (szülő ' + Math.round(szul) + 'px) „' + rov(g.textContent) + '”');
  });
  /* betűk: amelyik család (+ latin-ext tartomány, pl. ő ű) a látható szövegben ténylegesen kell, az be van-e töltve */
  const betuk = {};
  try { document.fonts.forEach(f => { const k = f.family.replace(/"/g, '') + (/U\+0?100-/i.test(f.unicodeRange) ? ' ext' : ''); if (f.status === 'loaded') betuk[k] = true; }); } catch (e) {}
  const kell = new Set();
  for (const el of mind) {
    if (!sajatSzoveg(el)) continue;
    const csal = cs(el).fontFamily.split(',')[0].replace(/["']/g, '').trim();
    if (!/Onest|Bricolage|JetBrains/.test(csal)) continue;
    let t = ''; for (const n of el.childNodes) { if (n.nodeType === 3) t += n.textContent; }
    if (/[A-Za-z0-9]/.test(t)) kell.add(csal);
    if (/[\u0100-\u02BA]/.test(t)) kell.add(csal + ' ext');
  }
  kell.forEach(k => { if (!betuk[k]) add('szoveg', 'betű nincs betöltve: ' + k); });
  const h1 = document.querySelector('h1');
  if (h1 && !/Bricolage/.test(cs(h1).fontFamily)) add('szoveg', 'h1 betűcsalád: ' + cs(h1).fontFamily);

  /* 5. kép */
  document.querySelectorAll('img').forEach(img => {
    if (img.closest(KIHAGY) || !lathato(img)) return;
    if (!img.getAttribute('width') || !img.getAttribute('height')) add('kep', ut(img) + ' nincs width/height (' + (img.getAttribute('src') || '').split('/').pop() + ')');
    if (img.complete && img.naturalWidth === 0) add('kep', ut(img) + ' törött: ' + (img.currentSrc || img.src).split('/').pop());
    if (img.naturalWidth > 0 && cs(img).objectFit === 'fill') {
      const r = img.getBoundingClientRect();
      const a = r.width / r.height, b = img.naturalWidth / img.naturalHeight;
      if (Math.abs(a - b) / b > 0.05) add('kep', ut(img) + ' torz arány ' + a.toFixed(2) + ' vs ' + b.toFixed(2));
    }
  });

  /* 6. kontraszt */
  const szin = c => { const m = String(c).match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[\s,\/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const arany = (a, b) => { const l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); };
  const kever = (fel, al, a) => ({ r: fel.r * a + al.r * (1 - a), g: fel.g * a + al.g * (1 - a), b: fel.b * a + al.b * (1 - a) });
  const hatter = el => {
    const retegek = [];
    for (let e = el; e; e = e.parentElement) {
      const c = szin(cs(e).backgroundColor);
      if (c && c.a > 0) { retegek.push(c); if (c.a >= 1) break; }
    }
    let ki = { r: 255, g: 255, b: 255 };
    for (let i = retegek.length - 1; i >= 0; i--) ki = kever(retegek[i], ki, retegek[i].a);
    return ki;
  };
  const opac = el => { let o = 1; for (let e = el; e && e !== document.body; e = e.parentElement) o *= parseFloat(cs(e).opacity); return o; };
  const kontrLat = new Set();
  for (const el of mind) {
    if (!sajatSzoveg(el)) continue;
    const st = cs(el);
    if (st.webkitBackgroundClip === 'text' || st.backgroundClip === 'text' || st.webkitTextFillColor === 'rgba(0, 0, 0, 0)') continue;
    if (el.closest('[aria-hidden="true"]:not(svg):not(span)') && !el.closest('.s-kartya__tovabb')) continue;
    let c = szin(st.color); if (!c) continue;
    const bg = hatter(el);
    const o = opac(el);
    if (c.a < 1) c = kever(c, bg, c.a);
    if (o < 1) c = kever(c, bg, o);
    const fs = parseFloat(st.fontSize), fw = parseInt(st.fontWeight, 10) || 400;
    const nagy = fs >= 24 || (fs >= 18.66 && fw >= 700);
    const r = arany(c, bg);
    const kell = nagy ? 3 : 4.5;
    if (r < kell) { const k = ut(el) + '|' + st.color; if (!kontrLat.has(k)) { kontrLat.add(k); add('kontraszt', ut(el) + ' ' + r.toFixed(2) + ':1 (' + st.color + ' / rgb(' + [bg.r, bg.g, bg.b].map(Math.round).join(',') + ')) „' + rov(el.textContent) + '”'); } }
  }
  document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea').forEach(el => {
    if (!lathato(el) || el.closest(KIHAGY)) return;
    const st = cs(el);
    const bc = szin(st.borderTopColor); if (!bc || st.borderTopWidth === '0px') return;
    const kinti = hatter(el.parentElement || el);
    const belso = szin(st.backgroundColor);
    const alap = belso && belso.a > 0 ? kever(belso, kinti, belso.a) : kinti;
    const k = kever(bc, alap, bc.a);
    const r = arany(k, kinti);
    if (r < 3) add('kontraszt', ut(el) + ' mezőkeret ' + r.toFixed(2) + ':1');
  });

  /* 7. kapcsoló */
  document.querySelectorAll('.s-kapcsolo').forEach(k => {
    if (!lathato(k)) return;
    const st = cs(k);
    ['marginTop', 'marginRight', 'marginBottom', 'marginLeft'].forEach(m => { if (parseFloat(st[m]) < 6) add('allapot', ut(k) + ' ' + m + ' ' + st[m]); });
    const sz = (k.parentElement ? k.parentElement.textContent : '');
    if (/\b(Aktív|Inaktív)\b/.test(sz)) add('allapot', ut(k) + ' felirat a kapcsoló mellett');
  });

  /* 8. ív */
  document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea, button, .s-pill, .s-gomb, [role=button]').forEach(el => {
    if (!lathato(el) || el.closest(KIHAGY)) return;
    const st = cs(el);
    const r = parseFloat(st.borderTopLeftRadius);
    if (!(r > 0)) add('iv', ut(el) + ' border-radius ' + st.borderTopLeftRadius);
    if ((el.tagName === 'TEXTAREA' || (el.tagName === 'SELECT' && el.multiple)) && r > 15) add('iv', ut(el) + ' többsoros ív ' + st.borderTopLeftRadius);
  });

  /* 9. ritmus */
  const szek = Array.from(document.querySelectorAll('.s-fo > .s-szekcio, .s-fo > div.s-szekcio, .s-fo > .s-oldalfej, .s-fo > article, .s-fo > .s-morzsa-sav')).filter(lathato);
  const tart = el => { const k = el.querySelector(':scope > .s-kont') || el; const kids = Array.from(k.children).filter(lathato); const rs = kids.length ? kids.map(x => x.getBoundingClientRect()) : [el.getBoundingClientRect()]; return { top: Math.min(...rs.map(r => r.top)), bottom: Math.max(...rs.map(r => r.bottom)) }; };
  for (let i = 1; i < szek.length; i++) {
    const a = szek[i - 1], b = szek[i];
    const ta = tart(a), tb = tart(b);
    const res = tb.top - ta.bottom;
    const pb = parseFloat(cs(a).paddingBottom), pt = parseFloat(cs(b).paddingTop);
    /* azonos (alap) háttér: egy szekció-padding a köz (dupla margó hiba); eltérő háttér (sávhatár): mindkét oldalon a saját padding */
    const hat = el => (el.className.match(/s-hatter--(\w+)/) || [, 'alap'])[1];
    const azonos = hat(a) === hat(b) && !/kiemelt|atmenet/.test(hat(a));
    if (azonos && res > Math.max(pb, pt, 64) * 1.5 + 40) add('ritmus', sel(a) + ' → ' + sel(b) + ': ' + Math.round(res) + 'px levegő azonos háttéren (pb ' + Math.round(pb) + ' + pt ' + Math.round(pt) + ')');
    if (!azonos && b.classList.contains('s-szekcio') && pt < 24 && !b.classList.contains('s-hero')) add('ritmus', sel(a) + ' → ' + sel(b) + ': sávhatár után csak ' + Math.round(pt) + 'px felső padding');
    if (res < 24) add('ritmus', sel(a) + ' → ' + sel(b) + ': csak ' + Math.round(res) + 'px levegő');
  }
  const lab = document.querySelector('.s-lablec');
  const fo = document.querySelector('.s-fo');
  if (lab && fo && szek.length) {
    const ut_ = tart(szek[szek.length - 1]);
    const res = lab.getBoundingClientRect().top - ut_.bottom;
    if (res > 200) add('ritmus', 'lábléc előtt ' + Math.round(res) + 'px levegő');
    if (res < 24) add('ritmus', 'lábléc előtt csak ' + Math.round(res) + 'px');
  }

  /* 10. fejléc */
  const fej = document.querySelector('.s-fejlec');
  if (fej) {
    const r = fej.getBoundingClientRect();
    if (Math.abs(r.height - 72) > 1) add('fejlec', 'magasság ' + Math.round(r.height));
    if (cs(fej).position !== 'sticky') add('fejlec', 'nem ragadós');
    if (parseFloat(cs(document.documentElement).scrollPaddingTop) < r.height) add('fejlec', 'scroll-padding-top ' + cs(document.documentElement).scrollPaddingTop);
  }

  /* 11. téma: nagy felületek fényereje */
  const mod = document.documentElement.getAttribute('data-tema');
  for (const el of mind) {
    if (/^(IMG|svg|VIDEO|PICTURE|CANVAS|IFRAME)$/.test(el.tagName)) continue;
    const r = el.getBoundingClientRect();
    if (r.width * r.height < 40000) continue;
    const c = szin(cs(el).backgroundColor);
    if (!c || c.a < 0.9) continue;
    const l = lum(c);
    if (mod === 'sotet' && l > 0.4) add('tema', ut(el) + ' világos felület sötét módban (L=' + l.toFixed(2) + ')');
    if (mod === 'vilagos' && l < 0.15) add('tema', ut(el) + ' sötét felület világos módban (L=' + l.toFixed(2) + ')');
  }

  return hibak;
}"""

MOZGAS = r"""() => {
  const ki = [];
  const p = v => v.split(',').map(x => x.trim()).map(x => x.endsWith('ms') ? parseFloat(x) / 1000 : parseFloat(x));
  document.querySelectorAll('body *').forEach(el => {
    if (el.closest('[hidden], .s-sr')) return;
    const st = getComputedStyle(el);
    const a = Math.max(...p(st.animationDuration)), t = Math.max(...p(st.transitionDuration));
    if (a > 0.02 && st.animationName !== 'none') ki.push(el.tagName.toLowerCase() + '.' + (typeof el.className === 'string' ? el.className.split(' ')[0] : '') + ' animation ' + a + 's');
    if (t > 0.02) ki.push(el.tagName.toLowerCase() + '.' + (typeof el.className === 'string' ? el.className.split(' ')[0] : '') + ' transition ' + t + 's');
  });
  if (getComputedStyle(document.documentElement).scrollBehavior === 'smooth') ki.push('html scroll-behavior: smooth');
  return Array.from(new Set(ki)).slice(0, 12);
}"""

FOKUSZ = r"""() => {
  const el = document.activeElement;
  if (!el || el === document.body) return null;
  const st = getComputedStyle(el);
  const kartya = el.closest('.s-kartya');
  const kst = kartya ? getComputedStyle(kartya) : null;
  const ring = (st.outlineStyle !== 'none' && parseFloat(st.outlineWidth) > 0) || /rgba?\(/.test(st.boxShadow) && st.boxShadow !== 'none' || (kst && kst.outlineStyle !== 'none' && parseFloat(kst.outlineWidth) > 0) || st.borderColor !== getComputedStyle(el.parentElement).borderColor;
  const cls = typeof el.className === 'string' ? el.className.split(' ')[0] : '';
  return { sel: el.tagName.toLowerCase() + '.' + cls, ring: !!ring, outline: st.outlineStyle + ' ' + st.outlineWidth, shadow: st.boxShadow };
}"""


def suti_cookie():
    return {'name': 'suti', 'value': '1|1.0|000|' + str(int(time.time())), 'url': ALAP}


def main():
    hibak = {}      # kulcs: [sorok]
    jegyzet = []
    meres = {}

    def hiba(k, s):
        hibak.setdefault(k, []).append(s)

    oldalak = [o for o in OLDALAK if not SZURO or SZURO in o[0] or SZURO in o[1]]
    gyoker = ALAP.split('/wp')[0]

    with sync_playwright() as p:
        b = p.chromium.launch()
        for (w, h) in NEZETEK:
            for mod in MODOK:
                ctx = b.new_context(viewport={'width': w, 'height': h}, device_scale_factor=1, color_scheme='dark' if mod == 'sotet' else 'light',
                                    is_mobile=w < 700, has_touch=w < 700, locale='hu-HU')
                ctx.add_init_script("try{localStorage.setItem('szinmod','%s')}catch(e){}" % mod)
                ctx.add_init_script(MERO)
                ctx.add_cookies([suti_cookie()])
                for nev, ut, kotelezo in oldalak:
                    lap = ctx.new_page()
                    konzol = []
                    halo = []
                    lap.on('console', lambda m, k=konzol: k.append(m.type + ': ' + m.text) if m.type in ('error', 'warning') else None)
                    lap.on('pageerror', lambda e, k=konzol: k.append('pageerror: ' + str(e)))
                    lap.on('response', lambda r, k=halo: k.append(f'{r.status} {r.url}') if r.status >= 400 and r.url.startswith(gyoker) else None)
                    cimke = f'{nev}@{w}/{mod}'
                    try:
                        v = lap.goto(ALAP + ut, wait_until='networkidle', timeout=60000)
                    except Exception as e:
                        hiba('betoltes', f'{cimke}: {str(e)[:100]}')
                        lap.close()
                        continue
                    kod = v.status if v else 0
                    if nev == '404':
                        if kod != 404:
                            hiba('betoltes', f'{cimke}: HTTP {kod} (404 kellene)')
                        halo = [x for x in halo if not x.startswith('404 ' + ALAP + ut)]
                        konzol = [x for x in konzol if 'status of 404' not in x]
                    elif kod != 200:
                        if kotelezo:
                            hiba('betoltes', f'{cimke}: HTTP {kod}')
                        else:
                            jegyzet.append(f'{cimke}: nincs ilyen oldal (HTTP {kod}) – kihagyva')
                        lap.close()
                        continue
                    lap.wait_for_timeout(300)
                    try:
                        lap.evaluate('document.fonts.ready')
                    except Exception:
                        pass
                    if lap.evaluate("document.documentElement.getAttribute('data-tema')") != mod:
                        hiba('tema', f'{cimke}: data-tema nem {mod}')
                    e = lap.evaluate(ELLENORZES)
                    for k, lista in e.items():
                        for s in lista:
                            hiba(k, f'{cimke}: {s}')
                    # görgetés végig (lazy képek, CLS), majd a ragadós fejléc + TJ ellenőrzése
                    magas = lap.evaluate('document.documentElement.scrollHeight')
                    y = 0
                    while y < magas:
                        y += h
                        lap.evaluate(f"window.scrollTo(0, {y})")
                        lap.wait_for_timeout(60)
                    lap.wait_for_timeout(250)
                    fej = lap.evaluate("(() => { const f = document.querySelector('.s-fejlec'); if (!f) return null; const r = f.getBoundingClientRect(); return [Math.round(r.top), Math.round(r.height)]; })()")
                    if fej and (fej[0] != 0 or abs(fej[1] - 72) > 1):
                        hiba('fejlec', f'{cimke}: görgetve top={fej[0]} h={fej[1]}')
                    tj = lap.evaluate("""(() => { const t = document.querySelector('.s-cikk__oldalsav .s-tj, .s-jogi__oldalsav .s-tj, .s-tudas__oldalsav'); const l = document.querySelector('.s-lablec'); if (!t || !l) return null; const a = t.getBoundingClientRect(), b = l.getBoundingClientRect(); return a.height > 0 && a.bottom > b.top + 1 && a.top < b.bottom ? Math.round(a.bottom - b.top) : 0; })()""")
                    if tj:
                        hiba('ritmus', f'{cimke}: a ragadós tartalomjegyzék {tj}px-szel a láblécre lóg')
                    kep = lap.evaluate("Array.from(document.images).filter(i => i.complete && i.naturalWidth === 0 && !i.closest('[hidden]')).map(i => (i.currentSrc || i.src).split('/').pop())")
                    for k in kep:
                        hiba('kep', f'{cimke}: törött kép (görgetés után) {k}')
                    cls = lap.evaluate('window.__cls')
                    meres[cimke] = {'cls': round(cls, 4)}
                    if cls >= 0.05:
                        hiba('fejlec', f'{cimke}: CLS {cls:.3f}')
                    for k in konzol[:3]:
                        hiba('konzol', f'{cimke}: {k[:160]}')
                    for k in halo[:3]:
                        hiba('konzol', f'{cimke}: {k[:160]}')
                    lap.evaluate("document.documentElement.style.scrollBehavior='auto'; window.scrollTo(0,0)")
                    lap.wait_for_timeout(150)
                    if KEP or (w in (390, 1366)):
                        try:
                            lap.screenshot(path=os.path.join(KI, f'{nev}-{w}-{mod}.png'), full_page=KEP or w == 390)
                        except Exception as ex:
                            jegyzet.append(f'{cimke}: képernyőkép hiba {str(ex)[:80]}')
                    lap.close()
                ctx.close()

        # ---------- Interakciók ----------
        for mod in (MODOK if INTERAKCIO else ()):
            # Mobil: menü, kereső, témaváltó, harmonika, árkártya szalag, összehasonlító tábla
            ctx = b.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True, color_scheme='dark' if mod == 'sotet' else 'light', locale='hu-HU')
            ctx.add_init_script("try{localStorage.setItem('szinmod','%s')}catch(e){}" % mod)
            ctx.add_cookies([suti_cookie()])
            lap = ctx.new_page()
            konzol = []
            lap.on('pageerror', lambda e, k=konzol: k.append(str(e)))
            lap.goto(ALAP + '/', wait_until='networkidle')
            szel0 = lap.evaluate('document.documentElement.clientWidth')
            lap.click('[data-menugomb]')
            lap.wait_for_timeout(200)
            m = lap.evaluate("""(() => { const f = document.getElementById('fomenu'); const r = f.getBoundingClientRect(); const html = getComputedStyle(document.documentElement); return { nyitva: document.querySelector('[data-menugomb]').getAttribute('aria-expanded'), lat: r.height, h: innerHeight, lock: html.overflow, cls: document.documentElement.classList.contains('s-menu-nyitva'), tul: document.documentElement.scrollWidth - document.documentElement.clientWidth, szel: document.documentElement.clientWidth, kilog: Array.from(f.querySelectorAll('*')).filter(e => e.getBoundingClientRect().right > innerWidth + 1).length }; })()""")
            if m['nyitva'] != 'true' or m['lat'] < m['h'] * 0.8:
                hiba('fejlec', f'mobil menü ({mod}): nem nyílik / nem tölti ki ({m})')
            if m['lock'] != 'hidden' or not m['cls']:
                hiba('fejlec', f'mobil menü ({mod}): nincs görgetészár ({m["lock"]})')
            if m['tul'] > 0 or m['kilog'] or m['szel'] != szel0:
                hiba('fejlec', f'mobil menü ({mod}): túlcsordul / szélességváltás ({m})')
            lap.click('.s-fomenu__nyito >> nth=0')
            lap.wait_for_timeout(150)
            lap.screenshot(path=os.path.join(KI, f'menu-390-{mod}.png'))
            lap.keyboard.press('Escape')
            lap.wait_for_timeout(100)
            if lap.get_attribute('[data-menugomb]', 'aria-expanded') != 'false':
                hiba('fejlec', f'mobil menü ({mod}): Esc nem zár')
            # témaváltó
            lap.click('[data-temavalto]')
            uj = 'vilagos' if mod == 'sotet' else 'sotet'
            t = lap.evaluate("(() => [document.documentElement.getAttribute('data-tema'), localStorage.getItem('szinmod'), document.querySelector('[data-temavalto]').getAttribute('aria-pressed'), document.querySelector('meta[name=theme-color]').content])()")
            if t[0] != uj or t[1] != uj:
                hiba('tema', f'témaváltó ({mod}→{uj}): {t}')
            lap.click('[data-temavalto]')
            # kereső
            lap.keyboard.press('Control+k')
            try:
                lap.wait_for_selector('[data-kereso]:not([hidden])', timeout=5000)
            except Exception:
                hiba('fejlec', f'kereső ({mod}): nem nyílik Ctrl+K-ra')
            else:
                lap.keyboard.type('munkalap')
                lap.wait_for_timeout(800)
                k = lap.evaluate("""(() => { const a = document.querySelector('.s-kereso__ablak').getBoundingClientRect(); return { n: document.querySelectorAll('#kereso-lista [role=option]').length, bal: Math.round(a.left), jobb: Math.round(innerWidth - a.right), lock: getComputedStyle(document.documentElement).overflow, fokusz: document.activeElement && document.activeElement.id, tul: document.documentElement.scrollWidth - document.documentElement.clientWidth }; })()""")
                if k['n'] < 1 or k['bal'] < 8 or k['jobb'] < 8 or k['tul'] > 0:
                    hiba('fejlec', f'kereső ({mod}): {k}')
                if k['fokusz'] != 'kereso-mezo':
                    hiba('allapot', f'kereső ({mod}): a fókusz nem a mezőn ({k["fokusz"]})')
                lap.screenshot(path=os.path.join(KI, f'kereso-390-{mod}.png'))
                lap.keyboard.press('Escape')
                lap.wait_for_timeout(200)
                if not lap.evaluate("document.querySelector('[data-kereso]').hidden"):
                    hiba('fejlec', f'kereső ({mod}): Esc nem zár')
            # GYIK harmonika
            lap.goto(ALAP + '/gyik/', wait_until='networkidle')
            akk = lap.evaluate("""(() => { const d = document.querySelector('.s-harmonika'); if (!d) return null; const f = d.querySelector('.s-harmonika__fej'); const i = f.querySelector('.s-harmonika__ikon'); const c = f.querySelector('.s-harmonika__cim') || f; const fr = f.getBoundingClientRect(), ir = i.getBoundingClientRect(), cr = c.getBoundingClientRect(); const lh = parseFloat(getComputedStyle(c).lineHeight); return { ikonKozep: Math.round(ir.top + ir.height / 2), fejKozep: Math.round(fr.top + fr.height / 2), elsoSorKozep: Math.round(cr.top + lh / 2), sorok: Math.round(cr.height / lh), ikonJobb: Math.round(fr.right - ir.right), ikon: Math.round(ir.width) }; })()""")
            if akk:
                if abs(akk['ikonKozep'] - akk['fejKozep']) > 2 and abs(akk['ikonKozep'] - akk['elsoSorKozep']) > 3:
                    hiba('racs', f'GYIK ikon ({mod}): nem középen ({akk})')
                if akk['ikonJobb'] < 16 or akk['ikonJobb'] > 28:
                    hiba('racs', f'GYIK ikon ({mod}): jobb oldali távolság {akk["ikonJobb"]}px')
            lap.click('.s-harmonika >> nth=0 >> .s-harmonika__fej')
            lap.wait_for_timeout(300)
            if not lap.evaluate("document.querySelector('.s-harmonika').open"):
                hiba('allapot', f'GYIK ({mod}): a harmonika nem nyílik')
            lap.screenshot(path=os.path.join(KI, f'gyik-nyitva-390-{mod}.png'))
            # Árak: szalag + tábla
            lap.goto(ALAP + '/arak/', wait_until='networkidle')
            sz = lap.evaluate("""(() => { const s = document.querySelector('.s-arkartya__szalag'); if (!s) return null; const k = s.closest('.s-arkartya'); const sr = s.getBoundingClientRect(), kr = k.getBoundingClientRect(); const nev = k.querySelector('.s-arkartya__nev').getBoundingClientRect(); return { dx: Math.round((sr.left + sr.width / 2) - (kr.left + kr.width / 2)), dy: Math.round(sr.top + sr.height / 2 - kr.top), nevKoz: Math.round(nev.top - sr.bottom), kilog: sr.right > innerWidth || sr.left < 0 }; })()""")
            if sz and (abs(sz['dx']) > 1 or abs(sz['dy']) > 1 or sz['nevKoz'] < 8 or sz['kilog']):
                hiba('racs', f'árkártya szalag ({mod}): {sz}')
            tb = lap.evaluate("""(() => { const g = document.querySelector('.s-tabla-gorgeto'); if (!g) return null; const r = g.getBoundingClientRect(); return { belso: g.scrollWidth > g.clientWidth, jobb: Math.round(r.right), w: innerWidth, dok: document.documentElement.scrollWidth - document.documentElement.clientWidth }; })()""")
            if tb and (not tb['belso'] or tb['jobb'] > tb['w'] + 1 or tb['dok'] > 0):
                hiba('tulcsordulas', f'ár-tábla 390 ({mod}): {tb}')
            lap.goto(ALAP + '/osszehasonlitas/', wait_until='networkidle')
            tb = lap.evaluate("""(() => { const g = document.querySelector('.s-tabla-gorgeto'); if (!g) return null; g.scrollLeft = 120; const e = g.querySelector('tbody .s-tabla__elso'); const r = g.getBoundingClientRect(), er = e.getBoundingClientRect(); return { belso: g.scrollWidth > g.clientWidth, jobb: Math.round(r.right), w: innerWidth, dok: document.documentElement.scrollWidth - document.documentElement.clientWidth, ragad: Math.round(er.left - r.left) }; })()""")
            if tb and (not tb['belso'] or tb['jobb'] > tb['w'] + 1 or tb['dok'] > 0 or tb['ragad'] > 2):
                hiba('tulcsordulas', f'összehasonlító tábla 390 ({mod}): {tb}')
            lap.evaluate("(() => { const g = document.querySelector('.s-tabla-gorgeto'); if (g) { document.documentElement.style.scrollBehavior = 'auto'; g.scrollIntoView({block: 'center'}); } })()")
            lap.wait_for_timeout(150)
            lap.screenshot(path=os.path.join(KI, f'osszehasonlitas-tabla-390-{mod}.png'))
            if konzol:
                hiba('konzol', f'interakció 390 ({mod}): {konzol[:2]}')
            ctx.close()

            # Asztal: legördülők (1100, 1366, 1920), fókuszgyűrű, hover, nyomtatás, csökkentett mozgás
            for w in (1100, 1180, 1280, 1366, 1920):
                ctx = b.new_context(viewport={'width': w, 'height': 900}, color_scheme='dark' if mod == 'sotet' else 'light', locale='hu-HU')
                ctx.add_init_script("try{localStorage.setItem('szinmod','%s')}catch(e){}" % mod)
                ctx.add_cookies([suti_cookie()])
                lap = ctx.new_page()
                lap.goto(ALAP + '/', wait_until='networkidle')
                n = lap.locator('.s-fomenu__nyito').count()
                for i in range(n):
                    lap.locator('.s-fomenu__nyito').nth(i).click()
                    lap.wait_for_timeout(120)
                    d = lap.evaluate("""(i) => { const b = document.querySelectorAll('.s-fomenu__nyito')[i]; const a = document.getElementById(b.getAttribute('aria-controls')); const r = a.getBoundingClientRect(); const lk = Array.from(a.querySelectorAll('a')).map(x => x.getBoundingClientRect()); const f = document.querySelector('.s-fejlec'); const fr = f.getBoundingClientRect(); const bg = getComputedStyle(a).backgroundColor; const alfa = (bg.match(/rgba\\(([^)]+)\\)/) || [, '0,0,0,1'])[1].split(',').map(Number)[3]; const kilogFej = Array.from(f.querySelectorAll('.s-fejlec__belso > *, .s-fejlec__eszkozok > *')).filter(e => e.getBoundingClientRect().right > innerWidth + 1).length; return { hidden: a.hidden, poz: getComputedStyle(a).position, fejMag: Math.round(fr.height), kilogFej: kilogFej, alfa: alfa, bal: Math.round(r.left), jobb: Math.round(innerWidth - r.right), alj: Math.round(innerHeight - r.bottom), kilog: lk.filter(x => x.right > r.right + 1).length, cim: b.textContent.trim() }; }""", i)
                    if d['poz'] != 'absolute' or d['fejMag'] != 72 or d['kilogFej'] or (d['alfa'] is not None and d['alfa'] < 0.97):
                        hiba('fejlec', f'legördülő „{d["cim"]}” {w}px ({mod}): elrendezés / háttér hibás {d}')
                    if d['hidden'] or d['bal'] < 0 or d['jobb'] < 0 or d['kilog']:
                        hiba('fejlec', f'legördülő „{d["cim"]}” {w}px ({mod}): {d}')
                    if d['alj'] < 0:
                        hiba('fejlec', f'legördülő „{d["cim"]}” {w}px ({mod}): a nézet alja alá lóg {-d["alj"]}px')
                    if i == 0 and w == 1366:
                        lap.screenshot(path=os.path.join(KI, f'legordulo-{w}-{mod}.png'), clip={'x': 0, 'y': 0, 'width': w, 'height': 520})
                    lap.keyboard.press('Escape')
                if w == 1366:
                    # Fókuszgyűrű: az első 14 Tab
                    gyuru = []
                    for i in range(14):
                        lap.keyboard.press('Tab')
                        f = lap.evaluate(FOKUSZ)
                        if f and not f['ring']:
                            gyuru.append(f['sel'] + ' ' + f['outline'])
                        if i == 3:
                            lap.screenshot(path=os.path.join(KI, f'fokusz-{mod}.png'), clip={'x': 0, 'y': 0, 'width': 1366, 'height': 160})
                    for g in gyuru:
                        hiba('allapot', f'fókuszgyűrű nélkül ({mod}): {g}')
                    # Hover a fő CTA-n
                    g = lap.locator('.s-hero .s-gomb--elsodleges').first
                    if g.count():
                        g.hover()
                        lap.wait_for_timeout(300)
                        bb = g.bounding_box()
                        lap.screenshot(path=os.path.join(KI, f'hover-gomb-{mod}.png'), clip={'x': max(0, bb['x'] - 30), 'y': max(0, bb['y'] - 30), 'width': bb['width'] + 60, 'height': bb['height'] + 60})
                    # Kereső asztali
                    lap.keyboard.press('Control+k')
                    try:
                        lap.wait_for_selector('[data-kereso]:not([hidden])', timeout=5000)
                        lap.keyboard.type('szamla')
                        lap.wait_for_timeout(700)
                        lap.screenshot(path=os.path.join(KI, f'kereso-1366-{mod}.png'))
                        lap.keyboard.press('Escape')
                    except Exception:
                        hiba('fejlec', f'kereső 1366 ({mod}): nem nyílik')
                    # Blog TJ a láblécen (görgetés az aljára, több lépésben)
                    lap.goto(ALAP + next(o[1] for o in OLDALAK if o[0] == 'cikk'), wait_until='networkidle')
                    for y in (800, 2000, 4000, 99999):
                        lap.evaluate(f'window.scrollTo(0, {y})')
                        lap.wait_for_timeout(120)
                        tj = lap.evaluate("""(() => { const t = document.querySelector('.s-cikk__oldalsav .s-tj'); const l = document.querySelector('.s-lablec'); const c = document.querySelector('.s-cikk__racs'); if (!t || !l) return null; const a = t.getBoundingClientRect(), b = l.getBoundingClientRect(), cr = c.getBoundingClientRect(); return { lab: Math.round(a.bottom - b.top), racs: Math.round(a.bottom - cr.bottom), y: scrollY }; })()""")
                        if tj and (tj['lab'] > 0 or tj['racs'] > 1):
                            hiba('ritmus', f'blog TJ 1366 ({mod}): kilóg (lábléc {tj["lab"]}px, rács {tj["racs"]}px) y={tj["y"]}')
                    lap.screenshot(path=os.path.join(KI, f'cikk-vege-1366-{mod}.png'))
                    # Nyomtatás
                    lap.goto(ALAP + '/jogi/aszf/', wait_until='networkidle')
                    lap.emulate_media(media='print')
                    pr = lap.evaluate("""(() => ['.s-fejlec', '.s-lablec', '#s-suti', '.s-kereso', '.s-ertesitok', '.s-jogi__oldalsav'].map(s => { const e = document.querySelector(s); return s + ':' + (e ? getComputedStyle(e).display : 'nincs'); }).concat(['proza:' + (document.querySelector('.s-proza') ? getComputedStyle(document.querySelector('.s-proza')).display : 'nincs'), 'bg:' + getComputedStyle(document.body).backgroundColor]))()""")
                    for x in pr:
                        s, v = x.split(':', 1)
                        if s in ('.s-fejlec', '.s-lablec', '#s-suti', '.s-kereso', '.s-ertesitok', '.s-jogi__oldalsav') and v not in ('none', 'nincs'):
                            hiba('nyomtatas', f'{s} nyomtatásban {v} ({mod})')
                        if s == 'proza' and v == 'none':
                            hiba('nyomtatas', f'a jogi szöveg nyomtatásban rejtett ({mod})')
                    lap.emulate_media(media='screen')
                ctx.close()

        # Csökkentett mozgás
        for _ in ([1] if INTERAKCIO else []):
          ctx = b.new_context(viewport={'width': 1366, 'height': 900}, reduced_motion='reduce', locale='hu-HU')
          ctx.add_cookies([suti_cookie()])
          lap = ctx.new_page()
          for ut in ('/', '/arak/', '/gyik/', '/blog/', '/funkciok/', '/szerviz-kpi-mutatok-riportok/'):
              lap.goto(ALAP + ut, wait_until='networkidle')
              for x in lap.evaluate(MOZGAS):
                  hiba('mozgas', f'{ut}: {x}')
          ctx.close()
        b.close()

    with open(os.path.join(KI, 'hibak.json'), 'w', encoding='utf-8') as f:
        json.dump({'hibak': hibak, 'jegyzet': jegyzet, 'cls': meres}, f, ensure_ascii=False, indent=1)

    sorrend = ['betoltes', 'tulcsordulas', 'atfedes', 'racs', 'szoveg', 'kep', 'kontraszt', 'allapot', 'iv', 'ritmus', 'fejlec', 'tema', 'nyomtatas', 'mozgas', 'konzol']
    osszes = sum(len(v) for v in hibak.values())
    print(f"téma vizuális QA: {'OK' if osszes == 0 else 'HIBA'} – {len(oldalak)} oldal × {len(NEZETEK)} szélesség × 2 mód, {osszes} hiba, CLS max {max([v['cls'] for v in meres.values()] or [0])}")
    for k in sorrend + [x for x in hibak if x not in sorrend]:
        n = len(hibak.get(k, []))
        if n:
            print(f'  {k:14} {n:4}')
            for s in hibak[k][:6]:
                print('      ✗ ' + s[:220])
    for j in jegyzet[:5]:
        print('  · ' + j)
    sys.exit(0 if osszes == 0 else 1)


main()
