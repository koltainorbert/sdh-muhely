/**
 * SDH Értékesítés – Szikra csevegő (vanilla JS, külső függőség nélkül).
 * Csatolás: <div data-sdh="szikra" data-opciok='{"lebego":true}'></div> – lebegő indítógomb + panel;
 * lebego nélkül a csevegő helyben jelenik meg (pl. egy oldal szekciójában).
 * Adat: window.SDH_SZIKRA (SDH_Sales_Szikra::js_adat). Munkamenet: sessionStorage (nincs süti).
 * Más modulnak: window.SDH.szikra.nyit(), window.SDH.szikra.kerdez(szoveg, {mod, lecke, lepes}).
 */
(function () {
  'use strict';

  var A = window.SDH_SZIKRA;
  if (!A || !A.rest) { return; }
  var S = A.szoveg || {};
  var TAR = 'sdh_szikra';

  function tarOlvas() {
    try { return JSON.parse(window.sessionStorage.getItem(TAR) || 'null'); } catch (e) { return null; }
  }
  function tarIr(v) {
    try { window.sessionStorage.setItem(TAR, JSON.stringify(v)); } catch (e) { /* privát mód: csak memóriában */ }
  }
  function ujAzon() {
    var b = new Uint8Array(18);
    (window.crypto || window.msCrypto).getRandomValues(b);
    return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('').slice(0, 32);
  }

  var allapot = tarOlvas();
  if (!allapot || typeof allapot.mm !== 'string' || !/^[A-Za-z0-9_-]{16,40}$/.test(allapot.mm)) {
    allapot = { mm: ujAzon(), uzenetek: [], nyitva: false };
  }
  if (!Array.isArray(allapot.uzenetek)) { allapot.uzenetek = []; }

  function el(tag, osztaly, szoveg) {
    var e = document.createElement(tag);
    if (osztaly) { e.className = osztaly; }
    if (szoveg !== undefined && szoveg !== null) { e.textContent = szoveg; }
    return e;
  }

  var peldanyok = [];
  var foglalt = false;

  function Csevego(gyoker, opc) {
    this.gyoker = gyoker;
    this.lebego = !!opc.lebego;
    this.epit();
    peldanyok.push(this);
  }

  Csevego.prototype.epit = function () {
    var self = this;
    var g = this.gyoker;
    g.classList.add('s-szikra');
    g.textContent = '';

    if (this.lebego) {
      g.classList.add('s-szikra--lebego');
      this.inditas = el('button', 's-szikra__indito');
      this.inditas.type = 'button';
      this.inditas.setAttribute('aria-expanded', 'false');
      this.inditas.setAttribute('aria-controls', 's-szikra-panel');
      var ikon = el('span', 's-szikra__ikon');
      ikon.setAttribute('aria-hidden', 'true');
      ikon.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" focusable="false"><path fill="currentColor" d="M13 2 4 14h6l-1 8 9-12h-6l1-8z"/></svg>';
      this.inditas.appendChild(ikon);
      this.inditas.appendChild(el('span', 's-szikra__indito-szoveg', S.nyit));
      this.inditas.addEventListener('click', function () { self.valt(); });
      g.appendChild(this.inditas);
    }

    var p = el('section', 's-szikra__panel');
    p.id = this.lebego ? 's-szikra-panel' : '';
    p.setAttribute('role', this.lebego ? 'dialog' : 'region');
    p.setAttribute('aria-label', S.cim || 'Szikra');
    if (this.lebego) { p.hidden = true; p.setAttribute('aria-modal', 'false'); }
    this.panel = p;

    var fej = el('header', 's-szikra__fej');
    var cimek = el('div', 's-szikra__cimek');
    cimek.appendChild(el('h2', 's-szikra__cim', S.cim));
    cimek.appendChild(el('p', 's-szikra__alcim', S.alcim));
    fej.appendChild(cimek);

    var uj = el('button', 's-szikra__gomb s-szikra__gomb--halk', S.uj);
    uj.type = 'button';
    uj.addEventListener('click', function () { self.uj(); });
    fej.appendChild(uj);

    if (this.lebego) {
      var bez = el('button', 's-szikra__bezar');
      bez.type = 'button';
      bez.setAttribute('aria-label', S.bezar);
      bez.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7 4.3 4.3l6.3 6.3 6.3-6.3z"/></svg>';
      bez.addEventListener('click', function () { self.valt(false); });
      fej.appendChild(bez);
    }
    p.appendChild(fej);

    this.lista = el('div', 's-szikra__lista');
    this.lista.setAttribute('role', 'log');
    this.lista.setAttribute('aria-live', 'polite');
    this.lista.setAttribute('aria-relevant', 'additions');
    this.lista.tabIndex = 0;
    p.appendChild(this.lista);

    var urlap = el('form', 's-szikra__urlap');
    urlap.noValidate = true;
    var cimke = el('label', 's-rejtett', S.mezo);
    var mezoId = 's-szikra-mezo-' + peldanyok.length;
    cimke.setAttribute('for', mezoId);
    this.mezo = el('textarea', 's-szikra__mezo');
    this.mezo.id = mezoId;
    this.mezo.rows = 2;
    this.mezo.maxLength = A.max || 1500;
    this.mezo.placeholder = S.mezo;
    this.mezo.setAttribute('autocomplete', 'off');
    this.szamlalo = el('span', 's-szikra__szamlalo');
    this.szamlalo.setAttribute('aria-live', 'off');
    this.kuld = el('button', 's-szikra__gomb s-szikra__kuld', S.kuld);
    this.kuld.type = 'submit';
    urlap.appendChild(cimke);
    urlap.appendChild(this.mezo);
    var sor = el('div', 's-szikra__urlap-sor');
    sor.appendChild(this.szamlalo);
    sor.appendChild(this.kuld);
    urlap.appendChild(sor);
    urlap.addEventListener('submit', function (e) { e.preventDefault(); self.kuldes(); });
    this.mezo.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); self.kuldes(); }
    });
    this.mezo.addEventListener('input', function () { self.szamol(); });
    p.appendChild(urlap);

    p.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && self.lebego) { self.valt(false); }
    });

    g.appendChild(p);
    this.rajzol();
    this.szamol();
    if (this.lebego && allapot.nyitva) { this.valt(true, true); }
  };

  Csevego.prototype.szamol = function () {
    var n = this.mezo.value.length;
    this.szamlalo.textContent = n > (A.max || 1500) * 0.8 ? n + ' / ' + (A.max || 1500) + ' ' + (S.karakter || '') : '';
  };

  Csevego.prototype.valt = function (nyit, csendes) {
    if (!this.lebego) { this.mezo.focus(); return; }
    var most = typeof nyit === 'boolean' ? nyit : this.panel.hidden;
    this.panel.hidden = !most;
    this.inditas.setAttribute('aria-expanded', most ? 'true' : 'false');
    this.gyoker.classList.toggle('s-szikra--nyitva', most);
    allapot.nyitva = most;
    tarIr(allapot);
    if (most && !csendes) { this.mezo.focus(); this.gorget(); }
    if (!most && !csendes) { this.inditas.focus(); }
  };

  Csevego.prototype.gorget = function () {
    this.lista.scrollTop = this.lista.scrollHeight;
  };

  /** Az adatvédelmi tájékoztató – mindig a beszélgetés elején, az első üzenet előtt. */
  Csevego.prototype.tajekoztato = function () {
    var d = el('div', 's-szikra__tajekoztato');
    d.appendChild(el('p', '', S.adatvedelem));
    if (A.tajekoztato) {
      var a = el('a', '', S.tajekoztato);
      a.href = A.tajekoztato;
      d.appendChild(a);
    }
    return d;
  };

  Csevego.prototype.buborek = function (u) {
    var b = el('div', 's-szikra__uzenet s-szikra__uzenet--' + (u.szerep === 'te' ? 'te' : (u.szerep === 'hiba' ? 'hiba' : 'szikra')));
    var ki = el('span', 's-rejtett', u.szerep === 'te' ? (S.te || '') + ':' : (A.nev || 'Szikra') + ':');
    b.appendChild(ki);
    var t = el('div', 's-szikra__szoveg');
    if (u.html) {
      t.innerHTML = u.html; // a szerver tisztította (SDH_Sales_Szikra_Kimenet::html)
    } else {
      t.textContent = u.szoveg || '';
    }
    b.appendChild(t);
    if (u.forrasok && u.forrasok.length) {
      var f = el('div', 's-szikra__forrasok');
      f.appendChild(el('span', 's-szikra__forrasok-cim', S.forrasok + ':'));
      var ul = el('ul');
      u.forrasok.forEach(function (x) {
        if (!x || !x.url) { return; }
        var li = el('li');
        var a = el('a', '', x.cim || x.url);
        a.href = x.url;
        li.appendChild(a);
        ul.appendChild(li);
      });
      f.appendChild(ul);
      b.appendChild(f);
    }
    return b;
  };

  Csevego.prototype.hozzajarulasDoboz = function () {
    var self = this;
    var d = el('div', 's-szikra__hozzajarulas');
    d.setAttribute('role', 'group');
    d.appendChild(el('p', '', S.hozzajarulas));
    var igen = el('button', 's-szikra__gomb', S.igen);
    igen.type = 'button';
    var nem = el('button', 's-szikra__gomb s-szikra__gomb--halk', S.nem);
    nem.type = 'button';
    igen.addEventListener('click', function () {
      allapot.hozzajarulas_kell = false;
      self.kuldes(S.igen_uzenet, { hozzajarulas: true });
    });
    nem.addEventListener('click', function () {
      allapot.hozzajarulas_kell = false;
      self.kuldes(S.nem_uzenet);
    });
    var sor = el('div', 's-szikra__gombsor');
    sor.appendChild(igen);
    sor.appendChild(nem);
    d.appendChild(sor);
    return d;
  };

  Csevego.prototype.rajzol = function () {
    var self = this;
    this.lista.textContent = '';
    this.lista.appendChild(this.tajekoztato());
    if (A.udvozlo) { this.lista.appendChild(this.buborek({ szerep: 'szikra', szoveg: A.udvozlo })); }
    allapot.uzenetek.forEach(function (u) { self.lista.appendChild(self.buborek(u)); });
    if (allapot.hozzajarulas_kell) { this.lista.appendChild(this.hozzajarulasDoboz()); }
    if (foglalt) {
      var g = el('div', 's-szikra__uzenet s-szikra__uzenet--szikra s-szikra__gondolkodik', S.gondolkodik);
      g.setAttribute('role', 'status');
      this.lista.appendChild(g);
    }
    this.kuld.disabled = foglalt;
    this.mezo.setAttribute('aria-busy', foglalt ? 'true' : 'false');
    this.gorget();
  };

  function mindRajzol() { peldanyok.forEach(function (p) { p.rajzol(); }); }

  Csevego.prototype.uj = function () {
    allapot = { mm: ujAzon(), uzenetek: [], nyitva: allapot.nyitva };
    tarIr(allapot);
    mindRajzol();
    this.mezo.focus();
  };

  /** Egy üzenet elküldése. opc: {mod, lecke, lepes, hozzajarulas} */
  function kerdez(szoveg, opc) {
    opc = opc || {};
    szoveg = String(szoveg || '').trim();
    if (!szoveg || foglalt) { return Promise.resolve(null); }
    if (szoveg.length > (A.max || 1500)) { szoveg = szoveg.slice(0, A.max || 1500); }

    allapot.uzenetek.push({ szerep: 'te', szoveg: szoveg });
    allapot.hozzajarulas_kell = false;
    foglalt = true;
    tarIr(allapot);
    mindRajzol();

    var test = { munkamenet: allapot.mm, uzenet: szoveg, mod: opc.mod === 'oktato' ? 'oktato' : 'ertekesites' };
    if (opc.hozzajarulas) { test.hozzajarulas = true; }
    if (opc.lecke) { test.lecke = String(opc.lecke); test.lepes = parseInt(opc.lepes, 10) || 0; }

    return fetch(A.rest, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(test)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; });
    }).then(function (v) {
      if (v.ok && v.j && v.j.ok) {
        allapot.uzenetek.push({ szerep: 'szikra', html: String(v.j.html || ''), forrasok: Array.isArray(v.j.forrasok) ? v.j.forrasok : [] });
        allapot.hozzajarulas_kell = !!v.j.hozzajarulas_kell;
      } else {
        allapot.uzenetek.push({ szerep: 'hiba', szoveg: (v.j && v.j.message) ? String(v.j.message) : S.hiba });
      }
      return v.j;
    }).catch(function () {
      allapot.uzenetek.push({ szerep: 'hiba', szoveg: S.hiba });
      return null;
    }).then(function (j) {
      foglalt = false;
      if (allapot.uzenetek.length > 60) { allapot.uzenetek = allapot.uzenetek.slice(-60); }
      tarIr(allapot);
      mindRajzol();
      return j;
    });
  }

  Csevego.prototype.kuldes = function (szoveg, opc) {
    var self = this;
    var sz = typeof szoveg === 'string' ? szoveg : this.mezo.value;
    if (!String(sz).trim() || foglalt) { return; }
    if (typeof szoveg !== 'string') { this.mezo.value = ''; this.szamol(); }
    kerdez(sz, opc).then(function () { self.mezo.focus(); });
  };

  function indit() {
    var helyek = document.querySelectorAll('[data-sdh="szikra"]:not([data-sdh-kesz])');
    var vanLebego = peldanyok.some(function (p) { return p.lebego; });
    Array.prototype.forEach.call(helyek, function (g) {
      var opc = {};
      try { opc = JSON.parse(g.getAttribute('data-opciok') || '{}') || {}; } catch (e) { opc = {}; }
      g.setAttribute('data-sdh-kesz', '1');
      if (opc.lebego && vanLebego) { g.remove(); return; }
      if (opc.lebego) { vanLebego = true; }
      new Csevego(g, opc);
    });
  }

  window.SDH = window.SDH || {};
  window.SDH.szikra = {
    nyit: function () {
      var p = peldanyok.filter(function (x) { return x.lebego; })[0] || peldanyok[0];
      if (p) { p.valt(true); }
    },
    kerdez: function (szoveg, opc) {
      window.SDH.szikra.nyit();
      return kerdez(szoveg, opc);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', indit);
  } else {
    indit();
  }
}());
