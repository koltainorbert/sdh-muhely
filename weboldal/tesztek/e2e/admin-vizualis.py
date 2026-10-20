#!/usr/bin/env python3
"""
Értékesítés-admin – vizuális QA bejáró (Playwright, Chromium). Belép a titkos belépő címen, végigjárja az admin minden
panelét, popupját és űrlapját 1366 / 1600 / 1920 / 1024 szélességen (és 390-en gyors használhatósági körrel), sötét és
világos módban, és minden állapotban méri (kovetes/vizualis-qa.md, az admin-felületre alkalmazva):

  tulcsordulas  – vízszintes oldalgörgetés, a nézetből kilógó elem, vízszintesen görgő doboz (a táblák dobozán kívül)
  tabla         – tábla, ami nem a saját görgető dobozában fér el; 1366 fölött a tábla dobozának görgetése is hiba
  atfedes       – egymást fedő szöveg / gomb / mező / ikon (nem ős–utód, > 2 px)
  levagott      – levágott tartalom (overflow), „…”-ra csonkolt szöveg title nélkül
  popup         – 1366×768-on a popup törzse görgetés nélkül fér el, és a popup a nézeten belül van
  iv            – minden mező / legördülő / gomb / pill lekerekített; többsoros mező íve ≤ 15 px
  kapcsolo      – kapcsoló mellett nincs Aktív/Inaktív felirat, körülötte ≥ 6 px levegő
  kontraszt     – szöveg ≥ 4,5:1 (nagy szöveg ≥ 3:1), mezőkeret ≥ 3:1
  tema          – a kért mód él; nagy felület rossz fényerővel (sötétben világos / világosban sötét)
  igazitas      – KPI-kártyák egy sorban azonos felső él és magasság
  celterulet    – kattintható elem legalább 24×24 px (WCAG 2.5.8)
  fokusz        – Tab-bal elért elemen látható fókuszjelzés (csak 1366-on)
  menu          – a menüben csak megnyitható pont van (mind betölt, nincs „még nincs bekapcsolva”)
  mozgas        – prefers-reduced-motion mellett nincs 0 ms-nál hosszabb animáció / átmenet
  konzol        – 0 hiba / figyelmeztetés, 0 sikertelen (4xx / 5xx) saját kérés

Futtatás:
  python3 weboldal/tesztek/e2e/admin-vizualis.py http://127.0.0.1:8877/wp --wp=/home/claude/env/vqa-admin/wp [kimeneti mappa] [--gyors] [--kep]
    --wp=…        a WP gyökér: ebből olvassa ki a titkos belépő címet (SDH_Sales_Rejtes::belepo_szelet())
    --belepo=…    vagy közvetlenül a belépő szelet (pl. iroda-a81da2c566)
    --gyors       csak 1366 + 390, mindkét mód      --kep  képernyőképek minden állapotról
  Felhasználó: VQA_FELHASZNALO / VQA_JELSZO (alap: admin / Admin123!teszt).
Kimenet: hibatábla ellenőrzésenként (db + első találatok), hibak.json (+ képek) a kimeneti mappában. Kilépési kód 0, ha nincs hiba.
A bejáró nem ment semmit: új szekciót csak a böngészőben ad hozzá (mentés nélkül), az importot csak előnézetig viszi.
"""
import json
import os
import re
import subprocess
import sys
import time

from playwright.sync_api import sync_playwright

ARGS = sys.argv[1:]
ALAP = (next((a for a in ARGS if a.startswith('http')), 'http://127.0.0.1:8877/wp')).rstrip('/')
KI = next((a for a in ARGS if not a.startswith('-') and not a.startswith('http')), os.path.join(os.path.dirname(os.path.abspath(__file__)), 'kepek-admin'))
GYORS = '--gyors' in ARGS
KEP = '--kep' in ARGS
WP = next((a.split('=', 1)[1] for a in ARGS if a.startswith('--wp=')), '')
BELEPO = next((a.split('=', 1)[1] for a in ARGS if a.startswith('--belepo=')), os.environ.get('VQA_BELEPO', ''))
FELH = os.environ.get('VQA_FELHASZNALO', 'admin')
JELSZO = os.environ.get('VQA_JELSZO', 'Admin123!teszt')
os.makedirs(KI, exist_ok=True)

if not BELEPO and WP:
    php = "require '%s/wp-load.php'; echo SDH_Sales_Rejtes::belepo_szelet();" % WP.rstrip('/')
    BELEPO = subprocess.run(['php', '-r', php], capture_output=True, text=True, timeout=60).stdout.strip().splitlines()[-1].strip()
if not BELEPO:
    print('Hiányzik a belépő cím: --wp=<wp gyökér> vagy --belepo=<szelet>')
    sys.exit(2)

ADMIN = ALAP + '/wp-admin/admin.php?page=ertekesites'
# (szélesség, magasság): a fő méretek + 390 a gyors használhatósági körhöz.
MERETEK = [(1366, 768), (390, 844)] if GYORS else [(1366, 768), (1600, 900), (1920, 1080), (1024, 768), (390, 844)]
MODOK = ['sotet', 'vilagos']

# ------------------------------------------------------------------------------------------------------------------
# Mérő (a böngészőben fut). scope: CSS-szelektor; opt: {w, mod, popup, tabla_szigoru}
# ------------------------------------------------------------------------------------------------------------------
MERO = r"""
(arg) => {
  const [scopeSel, opt] = arg;
  const vw = innerWidth, vh = innerHeight;
  const ki = {};
  const add = (k, v) => { (ki[k] = ki[k] || []).push(v); };
  const roots = [...document.querySelectorAll(scopeSel)];
  if (!roots.length) { add('allapot', 'nincs: ' + scopeSel); return ki; }
  const root = roots[roots.length - 1];
  const sel = (el) => {
    if (!el || !el.tagName) return '?';
    let s = el.tagName.toLowerCase();
    if (el.id) return s + '#' + el.id;
    const c = [...el.classList].filter((x) => !x.startsWith('is-')).slice(0, 2);
    if (c.length) s += '.' + c.join('.');
    for (const a of ['data-mezo', 'data-tipus', 'data-ful', 'data-csop', 'data-menupont', 'data-ertek', 'aria-label']) { if (el.hasAttribute && el.hasAttribute(a)) { s += `[${a}="${String(el.getAttribute(a)).slice(0, 30)}"]`; break; } }
    const p = el.parentElement;
    let ps = '';
    if (p && p !== document.body) { const pc = [...p.classList].filter((x) => !x.startsWith('is-')).slice(0, 1); ps = (pc.length ? '.' + pc[0] : p.tagName.toLowerCase()) + ' > '; }
    const t = (el.innerText || el.value || '').trim().replace(/\s+/g, ' ').slice(0, 24);
    return ps + s + (t ? ` «${t}»` : '');
  };
  const cs = (el) => getComputedStyle(el);
  const latszik = (el) => {
    if (!el.isConnected) return false;
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    for (let x = el; x && x !== document.documentElement; x = x.parentElement) {
      const s = cs(x);
      if (s.display === 'none' || s.visibility === 'hidden' || parseFloat(s.opacity) === 0) return false;
      if (x.hidden) return false;
    }
    return true;
  };
  // Ős-vágás: a látható téglalap a görgető / levágó ősök metszetével.
  const vagott = (el) => {
    let r = el.getBoundingClientRect();
    let L = r.left, T = r.top, R = r.right, Bt = r.bottom;
    for (let x = el.parentElement; x && x !== document.documentElement; x = x.parentElement) {
      const s = cs(x);
      if (s.overflowX !== 'visible' || s.overflowY !== 'visible') {
        const q = x.getBoundingClientRect();
        if (s.overflowX !== 'visible') { L = Math.max(L, q.left); R = Math.min(R, q.right); }
        if (s.overflowY !== 'visible') { T = Math.max(T, q.top); Bt = Math.min(Bt, q.bottom); }
      }
      if (s.position === 'fixed') break;
    }
    return { left: L, top: T, right: R, bottom: Bt, width: R - L, height: Bt - T };
  };
  const all = [...root.querySelectorAll('*')].filter((el) => !el.closest('svg') || el.tagName.toLowerCase() === 'svg');
  const vis = all.filter(latszik);

  // ---- 1 túlcsordulás
  if (document.documentElement.scrollWidth > vw + 1) add('tulcsordulas', `oldal: scrollWidth ${document.documentElement.scrollWidth} > ${vw}`);
  const SZANDEKOS_GORGETO = '.sa-tabla-doboz, .sa-fulek, .sa-elonezet, .sa-szegmens, .sa-diff pre, .sa-rt__ter, .sa-paletta__lista';
  for (const el of vis) {
    const v = vagott(el);
    if (v.width <= 0 || v.height <= 0) continue;
    if (v.right > vw + 1) add('tulcsordulas', `${sel(el)} jobb éle ${Math.round(v.right)} > ${vw}`);
    const s = cs(el);
    if ((s.overflowX === 'auto' || s.overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 1 && !el.matches(SZANDEKOS_GORGETO)) add('tulcsordulas', `${sel(el)} vízszintesen görög (${el.scrollWidth} > ${el.clientWidth})`);
  }
  // ---- tábla
  for (const t of root.querySelectorAll('table')) {
    if (!latszik(t)) continue;
    const doboz = t.closest('.sa-tabla-doboz, [style*="overflow"]');
    const kartya = t.closest('.sa-kartya, .sa-popup__test, .sa-ful-panel');
    const tw = t.getBoundingClientRect().width;
    if (!doboz && kartya && tw > kartya.clientWidth + 1) add('tabla', `${sel(t)} ${Math.round(tw)} px > doboz ${kartya.clientWidth} px (nincs görgető doboz)`);
    if (doboz && opt.w >= 1366 && doboz.scrollWidth > doboz.clientWidth + 1) add('tabla', `${sel(t)} ${opt.w}-on is görgetni kell (${doboz.scrollWidth} > ${doboz.clientWidth})`);
  }

  // ---- levágott / csonkolt
  for (const el of vis) {
    const s = cs(el);
    const rejt = (v) => v === 'hidden' || v === 'clip';
    if (el.matches('.sa-sr, svg, img, iframe, input, select, textarea, .sa-kapcsolo, .sa-kapcsolo *, .sa-csontvaz')) continue;
    if (rejt(s.overflowX) && el.scrollWidth > el.clientWidth + 2) {
      if (s.textOverflow === 'ellipsis') {
        if (!el.closest('[title]') && !el.closest('[aria-label]')) add('levagott', `csonkolt, title nélkül: ${sel(el)}`);
      } else if (!el.matches('.sa-kartya--sima, .sa-popup, .sa-minta, .sa-rt, .sa-szin__minta')) add('levagott', `vízszintesen levágva: ${sel(el)} (${el.scrollWidth} > ${el.clientWidth})`);
    }
    if (rejt(s.overflowY) && el.scrollHeight > el.clientHeight + 2 && !el.matches('.sa-kartya--sima, .sa-szin__minta, .sa-rt')) add('levagott', `függőlegesen levágva: ${sel(el)} (${el.scrollHeight} > ${el.clientHeight})`);
  }
  for (const g of root.querySelectorAll('.sa-gomb, .sa-cimke, .sa-szegmens button, .sa-fulek button')) {
    if (!latszik(g)) continue;
    const s = cs(g);
    const lh = parseFloat(s.lineHeight) || parseFloat(s.fontSize) * 1.3;
    const r = g.getBoundingClientRect();
    if (s.whiteSpace !== 'nowrap' && g.scrollHeight > Math.max(lh * 1.7, parseFloat(s.height) || 0) + 2 && r.width >= 200) add('levagott', `két sorba tört gomb: ${sel(g)}`);
  }

  // ---- átfedés (levél-elemek)
  const levelek = vis.filter((el) => {
    if (el.matches('.sa-sr, .sa-csontvaz, .sa-diagram__tipp, option')) return false;
    if (el.matches('input, select, textarea, button, img, svg, .sa-kapcsolo, .sa-cimke, .sa-pill, .sa-kpi__spark, iframe')) return true;
    return [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim());
  }).filter((el) => !(el.matches('input') && el.closest('.sa-kapcsolo, .sa-szin__minta')) && !(el.tagName.toLowerCase() === 'svg' && el.closest('button, a, .sa-cimke, .sa-kpi__cimke, .sa-lanc__jel, .sa-kereso, .sa-ikonvalaszto, .sa-ures')));
  const doboz = levelek.map((el) => ({ el, r: vagott(el) })).filter((x) => x.r.width > 1 && x.r.height > 1).slice(0, 1400);
  let atf = 0;
  for (let i = 0; i < doboz.length; i++) {
    for (let j = i + 1; j < doboz.length; j++) {
      const a = doboz[i], b = doboz[j];
      const ix = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left);
      const iy = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top);
      if (ix <= 2 || iy <= 2) continue;
      if (a.el.contains(b.el) || b.el.contains(a.el)) continue;
      // kapcsoló: a láthatatlan input és a sín egy egység
      if (a.el.closest('.sa-kapcsolo') && a.el.closest('.sa-kapcsolo') === b.el.closest('.sa-kapcsolo')) continue;
      if (atf++ < 40) add('atfedes', `${sel(a.el)} ⟂ ${sel(b.el)} (${Math.round(ix)}×${Math.round(iy)})`);
    }
  }

  // ---- ív
  for (const el of root.querySelectorAll('input, select, textarea, button, .sa-cimke, .sa-pill, .sa-valaszto-pill, .sa-kereso, .sa-ikonvalaszto, a.sa-gomb, a.sa-ikon-gomb, .sa-pillek, .sa-rt')) {
    if (!latszik(el)) continue;
    if (el.matches('input[type=color], input[type=file], input[type=hidden]') || (el.matches('input') && el.closest('.sa-kapcsolo'))) continue;
    if (el.matches('.sa-rt textarea')) continue; // a szerkesztő dobozán belül, a doboz íves
    const s = cs(el);
    const r = ['borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius'].map((k) => parseFloat(s[k]) || 0);
    const kulso = el.matches('.sa-fulek button') ? r[0] + r[1] : Math.min(...r);
    if (kulso <= 0) add('iv', `nem lekerekített: ${sel(el)} (${r.join('/')})`);
    if (el.matches('textarea, select[multiple], .sa-pillek, .sa-rt') && Math.max(...r) > 15.5) add('iv', `többsoros mező íve > 15 px: ${sel(el)} (${r[0]})`);
  }

  // ---- kapcsoló
  for (const k of root.querySelectorAll('.sa-kapcsolo')) {
    if (!latszik(k)) continue;
    const sin = k.querySelector('span') || k;
    const kr = sin.getBoundingClientRect();
    if (k.textContent.trim()) add('kapcsolo', `szöveg a kapcsolóban: ${sel(k)}`);
    const szomszed = [k.previousElementSibling, k.nextElementSibling, k.parentElement && k.parentElement.nextElementSibling].filter(Boolean);
    for (const s of szomszed) if (/^(aktív|inaktív|be|ki|bekapcsolva|kikapcsolva|igen|nem)$/i.test((s.textContent || '').trim())) add('kapcsolo', `állapot-felirat a kapcsoló mellett: ${sel(k)} «${s.textContent.trim()}»`);
    const L = kr.left - 6 + 0.5, R = kr.right + 6 - 0.5, T = kr.top - 6 + 0.5, Bt = kr.bottom + 6 - 0.5;
    for (const o of doboz) {
      if (o.el === k || k.contains(o.el) || o.el.contains(k)) continue;
      const r = o.r;
      if (r.right > L && r.left < R && r.bottom > T && r.top < Bt) { add('kapcsolo', `< 6 px levegő: ${sel(k)} ↔ ${sel(o.el)}`); break; }
    }
    // keretes szülő széle
    for (let p = k.parentElement; p && p !== root.parentElement; p = p.parentElement) {
      const s = cs(p);
      const keretes = ['Top', 'Right', 'Bottom', 'Left'].some((x) => parseFloat(s['border' + x + 'Width']) > 0 && s['border' + x + 'Style'] !== 'none') && !p.matches('td, th, tr, table, .sa-kartya, .sa-popup__lab, .sa-popup, .sa-ismetlo__elem');
      if (!keretes) continue;
      const q = p.getBoundingClientRect();
      const tav = Math.min(kr.left - q.left, q.right - kr.right, kr.top - q.top, q.bottom - kr.bottom);
      if (tav < 5.5) add('kapcsolo', `< 6 px a keretig: ${sel(k)} (${tav.toFixed(1)} px, ${sel(p)})`);
      break;
    }
  }

  // ---- kontraszt
  const szin = (s) => { const m = String(s).match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[,\s/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const keverd = (f, h) => ({ r: f.r * f.a + h.r * (1 - f.a), g: f.g * f.a + h.g * (1 - f.a), b: f.b * f.a + h.b * (1 - f.a), a: 1 });
  const lum = (c) => { const t = [c.r, c.g, c.b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * t[0] + 0.7152 * t[1] + 0.0722 * t[2]; };
  const arany = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const hatterek = (el) => {
    // ősök háttérszínei (alulról fölfelé), az első átlátszatlanig; a színátmenet minden megállója egy-egy jelölt
    const lanc = [];
    for (let x = el; x; x = x.parentElement) {
      const s = cs(x);
      const img = s.backgroundImage && s.backgroundImage !== 'none' && !/url\(/.test(s.backgroundImage) ? (s.backgroundImage.match(/rgba?\([^)]+\)/g) || []).map(szin) : [];
      const c = szin(s.backgroundColor);
      if (img.length) { lanc.push({ tobb: img }); if (img.every((z) => z.a >= 1)) break; }
      if (c && c.a > 0) { lanc.push({ egy: c }); if (c.a >= 1) break; }
    }
    let jeloltek = [{ r: 0, g: 0, b: 0, a: 1 }];
    const html = szin(cs(document.body).backgroundColor);
    if (html && html.a >= 1) jeloltek = [html];
    for (let i = lanc.length - 1; i >= 0; i--) {
      const l = lanc[i].egy ? [lanc[i].egy] : lanc[i].tobb;
      const uj = [];
      for (const j of jeloltek) for (const c of l) uj.push(keverd(c, j));
      jeloltek = uj.slice(0, 6);
    }
    return jeloltek;
  };
  let kdb = 0;
  for (const el of vis) {
    if (!el.childNodes || ![...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) continue;
    if (el.closest('.sa-minta, .sa-sr, svg, option, [disabled], .sa-udv h2 span, .sa-csontvaz')) continue;
    let op = 1;
    for (let x = el; x; x = x.parentElement) op *= parseFloat(cs(x).opacity);
    if (op < 0.6) continue; // szándékosan halványított (rejtett szekció, letiltott gomb)
    const s = cs(el);
    const fg0 = szin(s.color);
    if (!fg0 || s.webkitTextFillColor === 'rgba(0, 0, 0, 0)' || s.color === 'rgba(0, 0, 0, 0)') continue;
    const fs = parseFloat(s.fontSize), fw = parseInt(s.fontWeight, 10) || 400;
    const nagy = fs >= 24 || (fs >= 18.66 && fw >= 700);
    const hat = nagy ? 3 : 4.5;
    const bg = hatterek(el);
    let leg = 99;
    for (const b of bg) { const f = keverd(Object.assign({}, fg0, { a: fg0.a * op }), b); leg = Math.min(leg, arany(f, b)); }
    if (leg < hat - 0.05 && kdb++ < 30) add('kontraszt', `${sel(el)} ${leg.toFixed(2)}:1 < ${hat}`);
  }
  for (const el of root.querySelectorAll('.sa-input, .sa-select, .sa-textarea, .sa-pillek, .sa-rt')) {
    if (!latszik(el) || el.closest('.sa-pillek') && el.matches('.sa-select')) continue;
    if (el.matches('.sa-rt textarea')) continue;
    const s = cs(el);
    const k = szin(s.borderTopColor);
    if (!k || parseFloat(s.borderTopWidth) === 0) continue;
    const bg = hatterek(el.parentElement);
    let leg = 99;
    for (const b of bg) leg = Math.min(leg, arany(keverd(k, b), b));
    if (leg < 2.95 && kdb++ < 60) add('kontraszt', `mezőkeret ${sel(el)} ${leg.toFixed(2)}:1 < 3`);
  }

  // ---- téma
  const kert = opt.mod;
  if ((document.documentElement.dataset.tema || '') !== kert) add('tema', `data-tema="${document.documentElement.dataset.tema}" (kért: ${kert})`);
  for (const el of vis) {
    const r = el.getBoundingClientRect();
    if (r.width < 200 || r.height < 60) continue;
    if (el.closest('.sa-minta, iframe, .sa-elonezet, .media-modal')) continue;
    const s = cs(el);
    const c = szin(s.backgroundColor);
    if (!c || c.a < 0.5) continue;
    const L = lum(hatterek(el)[0]);
    if (kert === 'sotet' && L > 0.35) add('tema', `világos felület sötét módban: ${sel(el)} (L=${L.toFixed(2)})`);
    if (kert === 'vilagos' && L < 0.25) add('tema', `sötét felület világos módban: ${sel(el)} (L=${L.toFixed(2)})`);
  }

  // ---- igazítás (KPI-sor)
  for (const racs of root.querySelectorAll('.sa-racs--kpi')) {
    const k = [...racs.children].filter(latszik).map((x) => x.getBoundingClientRect());
    const sorok = {};
    k.forEach((r) => { const t = Math.round(r.top / 4); (sorok[t] = sorok[t] || []).push(r); });
    for (const s of Object.values(sorok)) {
      const h = s.map((r) => r.height);
      if (Math.max(...h) - Math.min(...h) > 2) add('igazitas', `KPI-sor eltérő magasság: ${h.map(Math.round).join('/')}`);
    }
  }
  for (const sz of root.querySelectorAll('.sa-eszkozsor, .sa-popup__lab, .sa-epito__fej, .sa-kartya__fej')) {
    if (!latszik(sz)) continue;
    const ko = [...sz.children].filter((x) => latszik(x) && x.matches('.sa-gomb, .sa-input, .sa-select, .sa-ikon-gomb, .sa-szegmens'));
    const sorok = {};
    ko.forEach((x) => { const r = x.getBoundingClientRect(); const t = Math.round((r.top + r.height / 2) / 12); (sorok[t] = sorok[t] || []).push([x, r]); });
    for (const s of Object.values(sorok)) {
      const k = s.map(([, r]) => r.top + r.height / 2);
      if (Math.max(...k) - Math.min(...k) > 2.5) add('igazitas', `nem egy vonalban (függőleges közép): ${sel(sz)} ${k.map((x) => x.toFixed(0)).join('/')}`);
    }
  }

  // ---- célterület
  for (const el of root.querySelectorAll('button, a[href], input:not([type=hidden]), select, [role=button], [role=tab], summary')) {
    if (!latszik(el)) continue;
    if (el.matches('.sa-kapcsolo input, input[type=color], .sa-ugro')) continue;
    if (el.matches('a') && !el.matches('.sa-gomb, .sa-ikon-gomb, .sa-wp-gomb, .sa-logo, .sa-menu a') && el.closest('p, dd, li, td, .sa-kv, .sa-fejlec__cim')) continue; // szövegközi link
    const r = el.getBoundingClientRect();
    if (r.width < 23.5 || r.height < 23.5) add('celterulet', `${sel(el)} ${Math.round(r.width)}×${Math.round(r.height)}`);
  }
  return ki;
}
"""

# Fókusz: az aktív elem és szomszédainak stílusa fókuszban / fókusz nélkül.
FOKUSZ_JS = r"""
() => {
  const el = document.activeElement;
  if (!el || el === document.body) return null;
  const nev = el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.split(/\s+/).filter(Boolean).slice(0, 2).join('.') : '') + ' «' + ((el.innerText || el.getAttribute('aria-label') || '').trim().slice(0, 24)) + '»';
  const kep = (x) => { if (!x) return ''; const s = getComputedStyle(x); return [s.outlineStyle, s.outlineWidth, s.outlineColor, s.boxShadow, s.borderColor, s.backgroundColor].join('|'); };
  const t = [el, el.nextElementSibling, el.parentElement];
  const be = t.map(kep);
  el.blur();
  const ki = t.map(kep);
  return { nev, latszik: be.some((x, i) => x !== ki[i]), kapcsolo: !!el.closest('.sa-kapcsolo') };
}
"""


def main():
    hibak = {}
    osszes_allapot = 0
    konzol = []
    kezdes = time.time()

    def jegyez(kulcs, allapot, lista):
        for x in lista:
            hibak.setdefault(kulcs, []).append(f'{allapot}: {x}')

    with sync_playwright() as p:
        b = p.chromium.launch()
        # Belépés egyszer, a sütik minden kontextusba.
        ctx0 = b.new_context()
        pg = ctx0.new_page()
        pg.goto(f'{ALAP}/{BELEPO}', wait_until='domcontentloaded')
        pg.fill('#user_login', FELH)
        pg.fill('#user_pass', JELSZO)
        pg.click('#wp-submit')
        pg.wait_for_load_state('domcontentloaded')
        allapot_fajl = os.path.join(KI, '_suti.json')
        ctx0.storage_state(path=allapot_fajl)
        ctx0.close()

        for (w, h) in MERETEK:
            for mod in MODOK:
                if w == 390 and mod == 'vilagos' and not GYORS:
                    continue  # 390: gyors használhatósági kör, csak egy módban (a téma-váltás a többi méreten mérve)
                ctx = b.new_context(viewport={'width': w, 'height': h}, storage_state=allapot_fajl, color_scheme='dark' if mod == 'sotet' else 'light', accept_downloads=True)
                pg = ctx.new_page()
                pg.on('dialog', lambda d: d.accept())
                pg.on('console', lambda m, w=w, mod=mod: konzol.append(f'{w}/{mod} {m.type}: {m.text[:160]}') if m.type in ('error', 'warning') else None)
                pg.on('pageerror', lambda x, w=w, mod=mod: konzol.append(f'{w}/{mod} pageerror: {str(x)[:200]}'))
                pg.on('response', lambda r, w=w, mod=mod: konzol.append(f'{w}/{mod} HTTP {r.status}: {r.url[:140]}') if r.status >= 400 and r.url.startswith(ALAP.split('/wp')[0]) else None)
                pg.goto(ADMIN, wait_until='domcontentloaded')
                pg.wait_for_selector('.sa-oldalsav', timeout=20000)
                pg.evaluate("m => { document.documentElement.dataset.tema = m; }", mod)
                n = bejar(pg, w, h, mod, jegyez)
                osszes_allapot += n
                ctx.close()

        # Mozgás csökkentve
        ctx = b.new_context(viewport={'width': 1366, 'height': 768}, storage_state=allapot_fajl, reduced_motion='reduce')
        pg = ctx.new_page()
        pg.goto(ADMIN, wait_until='domcontentloaded')
        pg.wait_for_selector('.sa-kpi', timeout=20000)
        hosszu = pg.evaluate("""() => [...document.querySelectorAll('*')].filter((el) => { const s = getComputedStyle(el); const d = (v) => Math.max(...v.split(',').map((x) => parseFloat(x) || 0)); return d(s.animationDuration) * (s.animationName !== 'none' ? 1 : 0) > 0 || d(s.transitionDuration) > 0; }).slice(0, 10).map((el) => el.tagName.toLowerCase() + '.' + [...el.classList].join('.'))""")
        jegyez('mozgas', '1366 reduce', hosszu)
        ctx.close()
        b.close()

    jegyez('konzol', 'konzol', sorted(set(konzol)))

    print(f'Admin vizuális QA – {osszes_allapot} állapot, {round(time.time() - kezdes)} s')
    nevek = ['tulcsordulas', 'tabla', 'atfedes', 'levagott', 'popup', 'iv', 'kapcsolo', 'kontraszt', 'tema', 'igazitas', 'celterulet', 'fokusz', 'menu', 'mozgas', 'konzol', 'allapot']
    ossz = 0
    for k in nevek:
        l = hibak.get(k, [])
        ossz += len(l)
        print(f'  {k:<13} {len(l):>4}' + ('' if not l else '   ' + ' | '.join(list(dict.fromkeys(l))[:3])))
    with open(os.path.join(KI, 'hibak.json'), 'w', encoding='utf-8') as f:
        json.dump(hibak, f, ensure_ascii=False, indent=1)
    print(f'Összesen {ossz} hiba' + ('' if ossz else ' – minden zöld') + f'. Részletek: {os.path.join(KI, "hibak.json")}')
    sys.exit(1 if ossz else 0)


# ------------------------------------------------------------------------------------------------------------------
# Bejárás
# ------------------------------------------------------------------------------------------------------------------

def var(pg, ms=250):
    """Megvárja, hogy a panel betöltsön (nincs csontváz, a hálózat elcsendesedik)."""
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
        if not pg.query_selector('.sa-popup-hatter, .sa-ikonracs, .sa-naptar'):
            return
        pg.keyboard.press('Escape')
        pg.wait_for_timeout(120)
    pg.evaluate("() => document.querySelectorAll('.sa-popup-hatter, .sa-ikonracs, .sa-naptar').forEach((x) => x.remove())")


def bejar(pg, w, h, mod, jegyez):
    n = [0]
    teljes = w in (1366, 1024) or GYORS  # popupok, űrlapok: 1366 és 1024 (a szabály 1366×768)
    nev0 = f'{w}/{mod}'

    def meres(allapot, scope='#sa-app', popup=False):
        n[0] += 1
        a = f'{nev0} {allapot}'
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

    def fulek_bejar(allapot, scope):
        """A popup / űrlap minden lapfüle."""
        db = pg.evaluate("s => { const r = [...document.querySelectorAll(s)].pop(); return r ? r.querySelectorAll('.sa-fulek [role=tab]').length : 0; }", scope)
        for i in range(1, db):
            pg.evaluate("([s, i]) => { const r = [...document.querySelectorAll(s)].pop(); const t = r.querySelectorAll('.sa-fulek [role=tab]')[i]; if (t && t.offsetParent) t.click(); }", [scope, i])
            pg.wait_for_timeout(120)
            meres(f'{allapot} ›fül {i + 1}', scope, popup=scope.startswith('.sa-popup'))

    def fokusz(allapot, db=30):
        if w != 1366:
            return
        pg.evaluate("() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); }")
        rossz = []
        for _ in range(db):
            pg.keyboard.press('Tab')
            r = pg.evaluate(FOKUSZ_JS)
            if r and not r['latszik']:
                rossz.append(r['nev'])
        jegyez('fokusz', f'{nev0} {allapot}', sorted(set(rossz)))
        pg.evaluate("() => window.scrollTo(0, 0)")

    POPUP = '.sa-popup-hatter'

    # ---------------- menü: csak megnyitható pont
    menupontok = pg.evaluate("() => [...document.querySelectorAll('[data-menupont]')].map((a) => a.dataset.menupont)")
    if w == 1366:
        for k in menupontok:
            hash_(pg, '#/' + ('' if k == 'attekintes' else k))
            hiba = pg.evaluate("() => { const t = document.querySelector('[data-tartalom]'); const s = t ? t.innerText : ''; return /még nincs bekapcsolva|Nem sikerült betölteni/.test(s) ? s.slice(0, 120) : ''; }")
            if hiba:
                jegyez('menu', f'{nev0} #{k}', [hiba])

    # ---------------- Áttekintés
    hash_(pg, '#/')
    meres('Áttekintés')
    fokusz('Áttekintés', 40)
    for sav in ['ma', 'ho']:
        pg.click(f'.sa-udv [data-ertek="{sav}"]')
        pg.wait_for_timeout(150)
        meres(f'Áttekintés {sav}')
    pg.click('.sa-udv [data-ertek="het"]')
    # Mobil menü 390-en
    if w < 961:
      try:
        pg.click('[data-menu]')
        pg.wait_for_timeout(300)
        meres('mobil menü nyitva', '.sa-oldalsav')
        zar = pg.evaluate("() => getComputedStyle(document.documentElement).overflow === 'hidden'")
        if not zar:
            jegyez('menu', f'{nev0} mobil menü', ['nyitott menü mellett az oldal görgethető'])
        pg.keyboard.press('Escape')
        pg.wait_for_timeout(250)
        if pg.evaluate("() => document.querySelector('#sa-app').classList.contains('is-menu')"):
            jegyez('menu', f'{nev0} mobil menü', ['Esc nem zárja'])
        pg.click('[data-menu]')
        pg.wait_for_timeout(300)
        pg.click('.sa-oldalsav [data-menu-zar]')
        pg.wait_for_timeout(250)
        if pg.evaluate("() => document.querySelector('#sa-app').classList.contains('is-menu')"):
            jegyez('menu', f'{nev0} mobil menü', ['a bezáró gomb nem zárja'])
      except Exception as x:
        jegyez('menu', f'{nev0} mobil menü', ['nyitott menü nem zárható: ' + str(x).splitlines()[0][:80]])
        pg.evaluate("() => document.querySelector('#sa-app').classList.remove('is-menu')")

    # ---------------- Rendelések
    hash_(pg, '#/rendelesek')
    meres('Rendelések')
    fokusz('Rendelések', 25)
    for f in (['nyitott', 'hiba', 'torolve'] if teljes else []):
        pg.click(f'.sa-eszkozsor [data-ertek="{f}"]')
        var(pg, 300)
        meres(f'Rendelések {f}')
    pg.click('.sa-eszkozsor [data-ertek="mind"]')
    var(pg, 300)
    pg.fill('[data-q]', 'nincs-ilyen-rendeles-xyz')
    var(pg, 600)
    meres('Rendelések üres keresés')
    pg.fill('[data-q]', '')
    var(pg, 600)
    ids = pg.evaluate("() => [...document.querySelectorAll('[data-rendeles]')].map((x) => x.dataset.rendeles)")
    for i, rid in enumerate(ids if teljes else ids[:2]):
        pg.click(f'tr[data-rendeles="{rid}"] td:nth-child(3)')
        pg.wait_for_selector('.sa-popup', timeout=10000)
        pg.wait_for_timeout(250)
        meres(f'Rendelés #{rid} popup', POPUP, popup=True)
        if i < 3 or teljes:
            fulek_bejar(f'Rendelés #{rid}', POPUP)
        if i == 0 and w == 1366:
            fokusz(f'Rendelés #{rid} popup', 20)
        popup_bezar(pg)

    # ---------------- Vevők
    hash_(pg, '#/vevok')
    meres('Vevők')
    if pg.query_selector('[data-vevo]'):
        pg.click('[data-vevo] td:nth-child(3)')
        pg.wait_for_selector('.sa-popup', timeout=10000)
        pg.wait_for_timeout(250)
        meres('Vevő popup', POPUP, popup=True)
        popup_bezar(pg)

    # ---------------- Leadek
    hash_(pg, '#/leadek')
    meres('Leadek')
    lead_db = pg.evaluate("() => document.querySelectorAll('[data-lead]').length")
    for i in range(lead_db if teljes else min(1, lead_db)):
        pg.click(f'[data-lead="{i}"] td:nth-child(4)')
        pg.wait_for_selector('.sa-popup', timeout=10000)
        pg.wait_for_timeout(200)
        meres(f'Lead {i} popup', POPUP, popup=True)
        popup_bezar(pg)

    # ---------------- Foglalások
    hash_(pg, '#/foglalasok')
    meres('Foglalások')
    if teljes:
        pg.click('.sa-eszkozsor [data-ertek="mind"]')
        var(pg, 300)
        meres('Foglalások mind')

    # ---------------- Oldalak + építő
    hash_(pg, '#/oldalak')
    meres('Oldalak')
    pg.click('[data-uj]')
    pg.wait_for_selector('.sa-popup')
    pg.wait_for_timeout(200)
    meres('Új oldal popup', POPUP, popup=True)
    popup_bezar(pg)
    oid = pg.evaluate("() => { const t = [...document.querySelectorAll('[data-oldal]')]; const k = t.find((x) => /Kezdőlap/.test(x.innerText)) || t[0]; return k ? k.dataset.oldal : ''; }")
    if oid:
        hash_(pg, f'#/oldalak/{oid}')
        pg.wait_for_selector('.sa-szelem', timeout=15000)
        pg.wait_for_timeout(1200)
        meres('Építő')
        fokusz('Építő', 30)
        if w <= 1280 and w > 960:
            pg.click('[data-elo-valt]')
            pg.wait_for_timeout(500)
            meres('Építő előnézet (keskeny)')
            pg.click('[data-urlap-valt]')
            pg.wait_for_timeout(200)
        if w > 1280 or w <= 960:
            pg.click('.sa-epito__oszlop--elo [data-ertek="mobil"]')
            pg.wait_for_timeout(500)
            meres('Építő mobil előnézet')
            pg.click('.sa-epito__oszlop--elo [data-ertek="asztal"]')
        # meglévő szekciók űrlapjai
        db = pg.evaluate("() => document.querySelectorAll('.sa-szelem').length")
        for i in range(db if teljes else 0):
            pg.click(f'.sa-szelem[data-i="{i}"] [data-nyit]')
            pg.wait_for_timeout(150)
            meres(f'Építő szekció {i + 1}')
            fulek_bejar(f'Építő szekció {i + 1}', '[data-urlap]')
        # SEO popup
        pg.click('[data-seo]')
        pg.wait_for_selector('.sa-popup')
        pg.wait_for_timeout(200)
        meres('SEO popup', POPUP, popup=True)
        popup_bezar(pg)
        # szekció hozzáadása: típusválasztó + minden típus űrlapja (mentés nélkül)
        pg.click('[data-hozzaad]')
        pg.wait_for_selector('.sa-tipusracs')
        pg.wait_for_timeout(200)
        meres('Szekció hozzáadása popup', POPUP, popup=True)
        tipusok = pg.evaluate("() => [...document.querySelectorAll('[data-tipus]')].map((x) => x.dataset.tipus)")
        popup_bezar(pg)
        for t in (tipusok if teljes else tipusok[:3]):
            pg.click('[data-hozzaad]')
            pg.wait_for_selector('.sa-tipusracs')
            pg.click(f'.sa-tipusracs [data-tipus="{t}"]')
            pg.wait_for_timeout(250)
            meres(f'Szekció-űrlap {t}')
            fulek_bejar(f'Szekció-űrlap {t}', '[data-urlap]')
            # ismétlők: minden fülön az első „Új elem”
            nyitott = pg.evaluate("""() => { let n = 0; document.querySelectorAll('[data-urlap] .sa-ful-panel, [data-urlap] .sa-urlap').forEach(() => {}); const tabs = [...document.querySelectorAll('[data-urlap] .sa-fulek [role=tab]')]; const panelek = tabs.length ? tabs : [null];
                for (const t of panelek) { if (t) t.click(); const g = [...document.querySelectorAll('[data-urlap] .sa-ismetlo [data-uj]')].filter((x) => x.offsetParent && !x.disabled); g.forEach((x) => { x.click(); n++; }); }
                return n; }""")
            if nyitott:
                pg.wait_for_timeout(200)
                for i, tab in enumerate(pg.query_selector_all('[data-urlap] .sa-fulek [role=tab]') or [None]):
                    if tab:
                        tab.click()
                        pg.wait_for_timeout(100)
                    meres(f'Szekció-űrlap {t} ismétlő nyitva {i + 1}')
            # ikonválasztó és naptár
            ik = pg.query_selector('[data-urlap] .sa-ikonvalaszto')
            if ik and ik.is_visible():
                ik.click()
                pg.wait_for_timeout(150)
                meres(f'Ikonrács {t}', '.sa-ikonracs')
                pg.keyboard.press('Escape')
            dt = pg.query_selector('[data-urlap] .sa-datum input')
            if dt and dt.is_visible():
                dt.click()
                pg.wait_for_timeout(150)
                meres(f'Naptár {t}', '.sa-naptar')
                pg.keyboard.press('Escape')
            # vissza az eredeti állapotra: a hozzáadott szekció törlése (kétlépcsős gomb: két katt)
            pg.evaluate("() => { const a = document.querySelector('.sa-szelem.is-aktiv [data-torol]'); if (a) { a.click(); a.click(); } }")
            pg.wait_for_timeout(120)
        pg.evaluate("() => { const g = document.querySelector('[data-elvet]'); if (g && !g.disabled) { g.click(); g.click(); } }")
        pg.wait_for_timeout(300)

    # ---------------- Elemek
    hash_(pg, '#/elemek')
    meres('Elemek')
    etipusok = pg.evaluate("() => [...document.querySelectorAll('.sa-almenu [data-tipus]')].map((x) => x.dataset.tipus)")
    for t in (etipusok if teljes else etipusok[:2]):
        pg.click(f'.sa-almenu [data-tipus="{t}"]')
        var(pg, 350)
        meres(f'Elemek {t}')
        pg.click('[data-uj]')
        pg.wait_for_selector('.sa-popup')
        pg.wait_for_timeout(250)
        meres(f'Új {t} popup', POPUP, popup=True)
        fulek_bejar(f'Új {t}', POPUP)
        popup_bezar(pg)
        if pg.query_selector('[data-kulcs]'):
            pg.click('[data-kulcs] td:nth-child(3)')
            pg.wait_for_selector('.sa-popup')
            pg.wait_for_timeout(250)
            meres(f'{t} szerkesztése popup', POPUP, popup=True)
            fulek_bejar(f'{t} szerkesztése', POPUP)
            # ismétlő elem kinyitása
            nyit = pg.evaluate("""() => { const p = [...document.querySelectorAll('.sa-popup-hatter')].pop(); let n = 0; const tabs = [...p.querySelectorAll('.sa-fulek [role=tab]')]; for (const t of (tabs.length ? tabs : [null])) { if (t) t.click(); const g = [...p.querySelectorAll('.sa-ismetlo__nyit')].filter((x) => x.offsetParent)[0]; if (g) { g.click(); n++; return n; } } return n; }""")
            if nyit:
                pg.wait_for_timeout(200)
                meres(f'{t} szerkesztése, ismétlő nyitva', POPUP, popup=True)
            popup_bezar(pg)

    # ---------------- Menü és lábléc
    hash_(pg, '#/menuk')
    meres('Menük')
    for hely in pg.evaluate("() => [...document.querySelectorAll('.sa-eszkozsor [data-szegmens] button')].map((x) => x.dataset.ertek)")[1:]:
        pg.click(f'.sa-eszkozsor [data-ertek="{hely}"]')
        pg.wait_for_timeout(200)
        meres(f'Menük {hely}')

    # ---------------- Arculat
    hash_(pg, '#/arculat')
    meres('Arculat')
    fulek_bejar('Arculat', '.sa-arculat')
    pg.click('.sa-arculat [data-ertek="vilagos"]')
    pg.wait_for_timeout(150)
    meres('Arculat minta világos')

    # ---------------- Beállítások
    hash_(pg, '#/beallitasok')
    csoportok = pg.evaluate("() => [...document.querySelectorAll('[data-csop]')].map((x) => x.dataset.csop)")
    for c in (csoportok if teljes else csoportok[:3]):
        pg.click(f'[data-csop="{c}"]')
        pg.wait_for_timeout(250)
        meres(f'Beállítások {c}')
        fulek_bejar(f'Beállítások {c}', '[data-csoport]')
        if c == csoportok[0]:
            fokusz(f'Beállítások {c}', 25)

    # ---------------- Napló
    hash_(pg, '#/naplo')
    meres('Napló')
    if pg.query_selector('[data-naplo]'):
        pg.click('[data-naplo] td:nth-child(3)')
        pg.wait_for_selector('.sa-popup')
        pg.wait_for_timeout(250)
        meres('Napló diff popup', POPUP, popup=True)
        popup_bezar(pg)

    # ---------------- Export / Import (előnézetig)
    hash_(pg, '#/export')
    meres('Export')
    if teljes:
        csomag = pg.evaluate("""async () => { const r = await fetch(PA_BOOT.rest + 'admin/export', { headers: { 'X-WP-Nonce': PA_BOOT.nonce }, credentials: 'same-origin' }); const j = await r.json(); return j; }""")
        try:
            b = csomag.get('beallitasok') if isinstance(csomag, dict) else None
            if isinstance(b, dict):
                for k in list(b.keys())[:3]:
                    if isinstance(b[k], str):
                        b[k] = b[k] + ' (próba)'
        except Exception:
            pass
        fajl = os.path.join(KI, '_import-proba.json')
        with open(fajl, 'w', encoding='utf-8') as f:
            json.dump(csomag, f, ensure_ascii=False)
        pg.set_input_files('[data-fajl]', fajl)
        try:
            pg.wait_for_selector('.sa-popup', timeout=10000)
            pg.wait_for_timeout(300)
            meres('Import előnézet popup', POPUP, popup=True)
            fulek_bejar('Import előnézet', POPUP)
        except Exception as x:
            jegyez('allapot', f'{nev0} Import előnézet', [str(x)[:120]])
        popup_bezar(pg)

    # ---------------- Ctrl+K
    hash_(pg, '#/')
    pg.keyboard.press('Control+k')
    pg.wait_for_selector('.sa-paletta')
    pg.wait_for_timeout(150)
    meres('Ctrl+K', POPUP, popup=False)
    pg.keyboard.type('R-2026')
    pg.wait_for_timeout(700)
    meres('Ctrl+K keresés', POPUP, popup=False)
    popup_bezar(pg)
    return n[0]


if __name__ == '__main__':
    main()
