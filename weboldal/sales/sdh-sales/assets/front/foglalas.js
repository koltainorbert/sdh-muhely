/**
 * SDH Értékesítés – időpontfoglalás (front). Csatolás: <div data-sdh="foglalas" data-tipus="demo"></div>
 * Típusválasztó, akadálymentes havi naptár (hétfővel kezdődik, nyilakkal bejárható, natív dátummező nélkül),
 * szabad sávok, űrlap, siker (ICS-letöltés). URL-paraméterek: ?tipus=…&rendeles=…&rt=… (rendeléshez kötött ajánlat),
 * ?foglalas=<token> (meglévő foglalás lemondása / áthelyezése).
 * Adat: window.SDH_FOGLALAS (rest, tipusok, max_nap, adatvedelem), window.SDH_KOZOS (rest, nonce). Vanilla JS, jQuery nélkül.
 */
(function () {
  'use strict';

  var CFG = window.SDH_FOGLALAS || {};
  var KOZ = window.SDH_KOZOS || {};
  var HONAP = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
  var NAP_ROVID = ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];
  var NAP_HOSSZU = ['hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap'];

  function rest() {
    return String(CFG.rest || KOZ.rest || '/wp-json/sdh-sales/v1').replace(/\/$/, '');
  }

  /** Egységes API-hívás: {ok, status, adat}. A közös segédet (window.SDH.api) POST-nál használja, ha van. */
  function api(ut, adat, metodus) {
    metodus = metodus || (adat ? 'POST' : 'GET');
    var url = rest() + ut;
    var fej = { 'Accept': 'application/json' };
    var opt = { method: metodus, headers: fej, credentials: 'same-origin' };

    if (KOZ.nonce) { fej['X-WP-Nonce'] = KOZ.nonce; }

    if (metodus === 'GET' && adat) {
      var q = Object.keys(adat).filter(function (k) { return adat[k] !== undefined && adat[k] !== ''; })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(adat[k]); }).join('&');
      url += (url.indexOf('?') === -1 ? '?' : '&') + q;
    } else if (adat) {
      fej['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(adat);
    }

    return fetch(url, opt).then(function (v) {
      return v.json().catch(function () { return {}; }).then(function (j) {
        return { ok: v.ok, status: v.status, adat: j || {} };
      });
    }).catch(function () {
      return { ok: false, status: 0, adat: { message: 'Nincs kapcsolat. Ellenőrizd az internetet, és próbáld újra.' } };
    });
  }

  function el(tag, attr, gyerek) {
    var e = document.createElement(tag);
    Object.keys(attr || {}).forEach(function (k) {
      var v = attr[k];
      if (v === null || v === undefined || v === false) { return; }
      if (k === 'text') { e.textContent = v; } else if (k === 'class') { e.className = v; } else if (k.indexOf('on') === 0 && typeof v === 'function') { e.addEventListener(k.slice(2), v); } else { e.setAttribute(k, v === true ? '' : String(v)); }
    });
    (gyerek || []).forEach(function (g) { if (g) { e.appendChild(typeof g === 'string' ? document.createTextNode(g) : g); } });
    return e;
  }

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(ev, ho, nap) { return ev + '-' + pad(ho + 1) + '-' + pad(nap); }
  function napNev(s) {
    var p = s.split('-').map(Number);
    var d = new Date(Date.UTC(p[0], p[1] - 1, p[2]));
    var hn = (d.getUTCDay() + 6) % 7;
    return p[0] + '. ' + HONAP[p[1] - 1] + ' ' + p[2] + '. (' + NAP_HOSSZU[hn] + ')';
  }
  function maBp() {
    try {
      return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Budapest', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    } catch (e) {
      var d = new Date();
      return ymd(d.getFullYear(), d.getMonth(), d.getDate());
    }
  }

  /** Kétlépcsős gomb (confirm() helyett): 1. katt „Biztos?”, 4 mp-en belül 2. katt = végrehajt. */
  function ketlepcsos(gomb, fn) {
    var eredeti = gomb.textContent;
    var idozito = null;
    gomb.addEventListener('click', function () {
      if (gomb.getAttribute('data-elesitve') === '1') {
        clearTimeout(idozito);
        gomb.removeAttribute('data-elesitve');
        gomb.textContent = eredeti;
        fn();
        return;
      }
      gomb.setAttribute('data-elesitve', '1');
      gomb.textContent = (KOZ.szoveg && KOZ.szoveg.biztos) || 'Biztos? Kattints újra.';
      idozito = setTimeout(function () { gomb.removeAttribute('data-elesitve'); gomb.textContent = eredeti; }, 4000);
    });
  }

  var STILUS = '' +
    '.s-fogl{--f-r:var(--s-r-mezo,12px);display:grid;gap:20px;color:var(--s-szoveg);font-family:var(--s-betu-szoveg,inherit)}' +
    '.s-fogl *{box-sizing:border-box}' +
    '.s-fogl h3{margin:0 0 8px;font-family:var(--s-betu-cim,inherit);font-size:1.1rem}' +
    '.s-fogl__tipusok{display:flex;flex-wrap:wrap;gap:8px}' +
    '.s-fogl__tipus{border:1px solid var(--s-vonal);background:var(--s-felulet);color:var(--s-szoveg);border-radius:var(--s-r-pill,999px);padding:8px 16px;cursor:pointer;font:inherit}' +
    '.s-fogl__tipus[aria-checked="true"]{background:var(--s-kiemelo);border-color:var(--s-kiemelo);color:var(--s-bg)}' +
    '.s-fogl__leiras{color:var(--s-halvany);margin:6px 0 0}' +
    '.s-fogl__racs{display:grid;grid-template-columns:minmax(0,1fr);gap:20px}' +
    '@media(min-width:760px){.s-fogl__racs{grid-template-columns:minmax(0,340px) minmax(0,1fr)}}' +
    '.s-fogl__naptar{background:var(--s-felulet);border:1px solid var(--s-vonal);border-radius:var(--s-r-kartya,16px);padding:14px}' +
    '.s-fogl__fej{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}' +
    '.s-fogl__lep{border:1px solid var(--s-vonal);background:transparent;color:var(--s-szoveg);border-radius:var(--s-r-gomb,10px);width:40px;height:40px;cursor:pointer;font:inherit}' +
    '.s-fogl__lep:disabled{opacity:.35;cursor:default}' +
    '.s-fogl table{width:100%;border-collapse:collapse;table-layout:fixed}' +
    '.s-fogl th{font-size:.8rem;color:var(--s-halvany);font-weight:600;padding:4px 0;text-align:center}' +
    '.s-fogl td{padding:2px;text-align:center}' +
    '.s-fogl__nap{width:100%;aspect-ratio:1;max-height:44px;min-height:36px;border:1px solid transparent;border-radius:var(--s-r-gomb,10px);background:transparent;color:var(--s-halvany);font:inherit;cursor:default}' +
    '.s-fogl__nap[data-van="1"]{color:var(--s-szoveg);border-color:var(--s-vonal);cursor:pointer;font-weight:600}' +
    '.s-fogl__nap[aria-selected="true"]{background:var(--s-kiemelo);color:var(--s-bg);border-color:var(--s-kiemelo)}' +
    '.s-fogl__nap[data-ma="1"]{text-decoration:underline}' +
    '.s-fogl button:focus-visible,.s-fogl input:focus-visible,.s-fogl textarea:focus-visible,.s-fogl a:focus-visible{outline:3px solid var(--s-neon,var(--s-kiemelo));outline-offset:2px}' +
    '.s-fogl__savok{display:flex;flex-wrap:wrap;gap:8px}' +
    '.s-fogl__sav{border:1px solid var(--s-vonal);background:var(--s-felulet);color:var(--s-szoveg);border-radius:var(--s-r-pill,999px);padding:8px 14px;min-width:76px;cursor:pointer;font:inherit}' +
    '.s-fogl__sav[aria-pressed="true"]{background:var(--s-kiemelo);border-color:var(--s-kiemelo);color:var(--s-bg)}' +
    '.s-fogl__urlap{display:grid;gap:12px}' +
    '.s-fogl__mezo{display:grid;gap:4px}' +
    '.s-fogl__mezo label{font-weight:600;font-size:.92rem}' +
    '.s-fogl__mezo input,.s-fogl__mezo textarea{width:100%;border:1px solid var(--s-vonal);background:var(--s-bg);color:var(--s-szoveg);border-radius:var(--s-r-mezo,12px);padding:10px 12px;font:inherit}' +
    '.s-fogl__mezo textarea{border-radius:min(var(--s-r-mezo,12px),15px);min-height:90px;resize:vertical}' +
    '.s-fogl__ket{display:grid;gap:12px}@media(min-width:560px){.s-fogl__ket{grid-template-columns:1fr 1fr}}' +
    '.s-fogl__csapda{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}' +
    '.s-fogl__gomb{border:0;background:var(--s-kiemelo);color:var(--s-bg);border-radius:var(--s-r-gomb,10px);padding:12px 20px;font:inherit;font-weight:700;cursor:pointer;justify-self:start}' +
    '.s-fogl__gomb--masod{background:transparent;color:var(--s-szoveg);border:1px solid var(--s-vonal)}' +
    '.s-fogl__gomb[data-elesitve="1"]{background:var(--s-hiba);color:var(--s-bg)}' +
    '.s-fogl__gomb:disabled{opacity:.6;cursor:wait}' +
    '.s-fogl__uzenet{min-height:1.4em;margin:0}' +
    '.s-fogl__uzenet[data-szint="hiba"]{color:var(--s-hiba)}' +
    '.s-fogl__uzenet[data-szint="ok"]{color:var(--s-ok)}' +
    '.s-fogl__kartya{background:var(--s-felulet);border:1px solid var(--s-vonal);border-radius:var(--s-r-kartya,16px);padding:18px;display:grid;gap:10px}' +
    '.s-fogl__halvany{color:var(--s-halvany);font-size:.9rem;margin:0}' +
    '.s-fogl__gombsor{display:flex;flex-wrap:wrap;gap:10px}' +
    '.s-fogl a.s-fogl__gomb{text-decoration:none;display:inline-block}';

  function stilus() {
    if (document.getElementById('s-fogl-stilus')) { return; }
    var s = document.createElement('style');
    s.id = 's-fogl-stilus';
    if (KOZ.csp_nonce) { s.setAttribute('nonce', KOZ.csp_nonce); }
    s.textContent = STILUS;
    document.head.appendChild(s);
  }

  var szamlalo = 0;

  function Foglalo(gyoker) {
    this.g = gyoker;
    this.id = 's-fogl-' + (++szamlalo);
    var p = new URLSearchParams(window.location.search);
    this.rendeles = p.get('rendeles') || '';
    this.rt = p.get('rt') || '';
    this.kezelo = p.get('foglalas') || '';
    var tipusok = Array.isArray(CFG.tipusok) ? CFG.tipusok : [];
    var kert = (this.rendeles && p.get('tipus')) || gyoker.getAttribute('data-tipus') || '';
    this.tipusok = tipusok.filter(function (t) { return t.nyilvanos || t.kulcs === kert; });
    if (this.rendeles) { this.tipusok = tipusok.filter(function (t) { return t.kulcs === kert; }); }
    this.tipus = (this.tipusok.filter(function (t) { return t.kulcs === kert; })[0] || this.tipusok[0] || {}).kulcs || '';
    this.ma = maBp();
    var m = this.ma.split('-').map(Number);
    this.ev = m[0];
    this.ho = m[1] - 1;
    this.napok = {};
    this.betoltve = {};
    this.valasztott = '';
    this.fokusz = this.ma;
    this.sav = null;
    this.atfoglal = '';
    this.elotolt = {};
    stilus();
    this.indit();
  }

  Foglalo.prototype.indit = function () {
    var self = this;
    this.g.textContent = '';
    this.g.classList.add('s-fogl');

    if (this.kezelo) {
      this.kezeles();
      return;
    }

    if (!this.tipus) {
      this.g.appendChild(el('p', { class: 's-fogl__uzenet', text: 'Most nem foglalható időpont. Írj nekünk, és egyeztetünk.' }));
      return;
    }

    if (this.rendeles && this.rt) {
      api('/foglalas/elotolt', { tipus: this.tipus, rendeles: this.rendeles, rt: this.rt }, 'GET').then(function (v) {
        if (v.ok) { self.elotolt = v.adat; self.kitolt(); }
      });
    }

    this.rajzol();
  };

  Foglalo.prototype.rajzol = function () {
    var self = this;
    this.g.textContent = '';

    if (this.tipusok.length > 1 && !this.atfoglal) {
      var csoport = el('div', { class: 's-fogl__tipusok', role: 'radiogroup', 'aria-label': 'Milyen időpontot foglalsz?' });
      this.tipusok.forEach(function (t, i) {
        var b = el('button', { type: 'button', class: 's-fogl__tipus', role: 'radio', 'aria-checked': t.kulcs === self.tipus ? 'true' : 'false', tabindex: t.kulcs === self.tipus ? '0' : '-1', 'data-kulcs': t.kulcs, text: t.nev + ' · ' + t.hossz + ' perc' });
        b.addEventListener('click', function () { self.tipusValt(t.kulcs); });
        b.addEventListener('keydown', function (e) {
          var d = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : (e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0);
          if (!d) { return; }
          e.preventDefault();
          var uj = self.tipusok[(i + d + self.tipusok.length) % self.tipusok.length];
          self.tipusValt(uj.kulcs, true);
        });
        csoport.appendChild(b);
      });
      this.g.appendChild(csoport);
    }

    var t = this.tipusAdat();
    this.leiras = el('p', { class: 's-fogl__leiras', text: t ? (t.leiras || '') + ' (' + t.hossz + ' perc, magyar idő szerint)' : '' });
    this.g.appendChild(this.leiras);

    var racs = el('div', { class: 's-fogl__racs' });
    this.naptarDoboz = el('div', { class: 's-fogl__naptar' });
    this.jobb = el('div', {});
    racs.appendChild(this.naptarDoboz);
    racs.appendChild(this.jobb);
    this.g.appendChild(racs);

    this.elo = el('p', { class: 's-fogl__uzenet', role: 'status', 'aria-live': 'polite' });
    this.g.appendChild(this.elo);

    this.honap();
    this.jobbRajzol();
  };

  Foglalo.prototype.tipusAdat = function () {
    var self = this;
    return this.tipusok.filter(function (t) { return t.kulcs === self.tipus; })[0] || null;
  };

  Foglalo.prototype.tipusValt = function (k, fokusz) {
    if (k === this.tipus) { return; }
    this.tipus = k;
    this.napok = {};
    this.betoltve = {};
    this.valasztott = '';
    this.sav = null;
    this.rajzol();
    if (fokusz) {
      var b = this.g.querySelector('.s-fogl__tipus[data-kulcs="' + k + '"]');
      if (b) { b.focus(); }
    }
  };

  Foglalo.prototype.uzen = function (s, szint) {
    if (!this.elo) { return; }
    this.elo.textContent = s || '';
    this.elo.setAttribute('data-szint', szint || '');
  };

  Foglalo.prototype.honapKulcs = function () { return this.ev + '-' + pad(this.ho + 1); };

  Foglalo.prototype.betolt = function () {
    var self = this;
    var kulcs = this.tipus + '|' + this.honapKulcs();
    if (this.betoltve[kulcs]) { return Promise.resolve(); }
    var utolso = new Date(Date.UTC(this.ev, this.ho + 1, 0)).getUTCDate();
    this.uzen('Szabad időpontok betöltése…');
    return api('/foglalas/savok', { tipus: this.tipus, tol: ymd(this.ev, this.ho, 1), ig: ymd(this.ev, this.ho, utolso) }, 'GET').then(function (v) {
      if (!v.ok) {
        self.uzen(v.adat.message || 'Az időpontok most nem tölthetők be.', 'hiba');
        return;
      }
      self.betoltve[kulcs] = true;
      self.utolsoNap = v.adat.utolso || '';
      var n = v.adat.napok || {};
      Object.keys(n).forEach(function (k) { self.napok[k] = n[k]; });
      var db = Object.keys(n).length;
      self.uzen(db ? '' : 'Ebben a hónapban nincs szabad időpont – nézd meg a következőt.');
    });
  };

  Foglalo.prototype.honap = function () {
    var self = this;
    this.betolt().then(function () { self.naptarRajzol(); });
    this.naptarRajzol();
  };

  Foglalo.prototype.naptarRajzol = function () {
    var self = this;
    var d = this.naptarDoboz;
    var fokuszVolt = d.contains(document.activeElement) && document.activeElement.classList.contains('s-fogl__nap');
    d.textContent = '';

    var cimId = this.id + '-ho';
    var mostHo = this.ma.slice(0, 7);
    var maxHo = (this.utolsoNap || '').slice(0, 7);
    var elozo = el('button', { type: 'button', class: 's-fogl__lep', 'aria-label': 'Előző hónap', text: '‹', disabled: this.honapKulcs() <= mostHo });
    var kov = el('button', { type: 'button', class: 's-fogl__lep', 'aria-label': 'Következő hónap', text: '›', disabled: maxHo !== '' && this.honapKulcs() >= maxHo });
    elozo.addEventListener('click', function () { self.lapoz(-1); });
    kov.addEventListener('click', function () { self.lapoz(1); });
    d.appendChild(el('div', { class: 's-fogl__fej' }, [elozo, el('h3', { id: cimId, 'aria-live': 'polite', text: this.ev + '. ' + HONAP[this.ho] }), kov]));

    var tabla = el('table', { role: 'grid', 'aria-labelledby': cimId });
    var fejsor = el('tr', {});
    NAP_ROVID.forEach(function (n, i) { fejsor.appendChild(el('th', { scope: 'col', abbr: NAP_HOSSZU[i], text: n })); });
    tabla.appendChild(el('thead', {}, [fejsor]));

    var tb = el('tbody', {});
    var elsoNap = (new Date(Date.UTC(this.ev, this.ho, 1)).getUTCDay() + 6) % 7;
    var napDb = new Date(Date.UTC(this.ev, this.ho + 1, 0)).getUTCDate();
    var sor = el('tr', {});
    var i;

    for (i = 0; i < elsoNap; i++) { sor.appendChild(el('td', { role: 'gridcell' })); }

    var vanFokusz = false;
    var fokuszNap = this.fokusz && this.fokusz.slice(0, 7) === this.honapKulcs() ? this.fokusz : '';
    if (!fokuszNap || !this.napok[fokuszNap]) {
      fokuszNap = this.valasztott && this.valasztott.slice(0, 7) === this.honapKulcs() ? this.valasztott : '';
    }
    if (!fokuszNap) {
      for (i = 1; i <= napDb; i++) { if (this.napok[ymd(this.ev, this.ho, i)]) { fokuszNap = ymd(this.ev, this.ho, i); break; } }
    }
    if (!fokuszNap) { fokuszNap = ymd(this.ev, this.ho, 1); }

    for (i = 1; i <= napDb; i++) {
      var nap = ymd(this.ev, this.ho, i);
      var van = !!(this.napok[nap] && this.napok[nap].length);
      var b = el('button', {
        type: 'button',
        class: 's-fogl__nap',
        'data-nap': nap,
        'data-van': van ? '1' : '0',
        'data-ma': nap === this.ma ? '1' : null,
        'aria-selected': nap === this.valasztott ? 'true' : 'false',
        'aria-disabled': van ? null : 'true',
        'aria-label': napNev(nap) + (van ? ', ' + this.napok[nap].length + ' szabad időpont' : ', nincs szabad időpont'),
        tabindex: nap === fokuszNap ? '0' : '-1',
        text: String(i)
      });
      if (nap === fokuszNap) { vanFokusz = true; }
      b.addEventListener('click', this.napKatt.bind(this, nap));
      b.addEventListener('keydown', this.napBillentyu.bind(this, nap));
      sor.appendChild(el('td', { role: 'gridcell' }, [b]));
      if ((elsoNap + i) % 7 === 0) { tb.appendChild(sor); sor = el('tr', {}); }
    }

    if (sor.childNodes.length) {
      while (sor.childNodes.length < 7) { sor.appendChild(el('td', { role: 'gridcell' })); }
      tb.appendChild(sor);
    }

    tabla.appendChild(tb);
    d.appendChild(tabla);
    d.appendChild(el('p', { class: 's-fogl__halvany', text: 'Nyilakkal is léptethetsz; Enter választ. Időpontok magyar idő szerint.' }));

    if (fokuszVolt && vanFokusz) {
      var f = d.querySelector('[data-nap="' + fokuszNap + '"]');
      if (f) { f.focus(); }
    }
  };

  Foglalo.prototype.lapoz = function (d, fokuszNap) {
    this.ho += d;
    if (this.ho < 0) { this.ho = 11; this.ev--; }
    if (this.ho > 11) { this.ho = 0; this.ev++; }
    this.fokusz = fokuszNap || '';
    this.honap();
  };

  Foglalo.prototype.napKatt = function (nap) {
    if (!this.napok[nap] || !this.napok[nap].length) { return; }
    this.valasztott = nap;
    this.fokusz = nap;
    this.sav = null;
    this.naptarRajzol();
    this.jobbRajzol();
    var elso = this.jobb.querySelector('.s-fogl__sav');
    if (elso) { elso.focus(); }
  };

  Foglalo.prototype.napBillentyu = function (nap, e) {
    var lepes = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
    var p = nap.split('-').map(Number);
    var d = new Date(Date.UTC(p[0], p[1] - 1, p[2]));
    if (e.key === 'Home') { lepes = -((d.getUTCDay() + 6) % 7); }
    if (e.key === 'End') { lepes = 6 - ((d.getUTCDay() + 6) % 7); }
    if (e.key === 'PageUp' || e.key === 'PageDown') {
      e.preventDefault();
      var ho = e.key === 'PageUp' ? -1 : 1;
      var c = new Date(Date.UTC(p[0], p[1] - 1 + ho, 1));
      var utolso = new Date(Date.UTC(c.getUTCFullYear(), c.getUTCMonth() + 1, 0)).getUTCDate();
      var cel = ymd(c.getUTCFullYear(), c.getUTCMonth(), Math.min(p[2], utolso));
      if ((ho < 0 && cel.slice(0, 7) < this.ma.slice(0, 7)) || (ho > 0 && this.utolsoNap && cel.slice(0, 7) > this.utolsoNap.slice(0, 7))) { return; }
      this.lapoz(ho, cel);
      return;
    }
    if (lepes === undefined) { return; }
    e.preventDefault();
    d.setUTCDate(d.getUTCDate() + lepes);
    var uj = ymd(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
    if (uj < this.ma.slice(0, 8) + '01' && uj.slice(0, 7) < this.ma.slice(0, 7)) { return; }
    if (this.utolsoNap && uj.slice(0, 7) > this.utolsoNap.slice(0, 7)) { return; }
    if (uj.slice(0, 7) !== nap.slice(0, 7)) {
      this.lapoz(uj.slice(0, 7) > nap.slice(0, 7) ? 1 : -1, uj);
      var self = this;
      setTimeout(function () { var f = self.naptarDoboz.querySelector('[data-nap="' + uj + '"]'); if (f) { f.focus(); } }, 0);
      return;
    }
    this.fokusz = uj;
    var regi = this.naptarDoboz.querySelector('[tabindex="0"]');
    var ujGomb = this.naptarDoboz.querySelector('[data-nap="' + uj + '"]');
    if (regi) { regi.setAttribute('tabindex', '-1'); }
    if (ujGomb) { ujGomb.setAttribute('tabindex', '0'); ujGomb.focus(); }
  };

  Foglalo.prototype.jobbRajzol = function () {
    var self = this;
    var j = this.jobb;
    j.textContent = '';

    if (!this.valasztott) {
      j.appendChild(el('p', { class: 's-fogl__halvany', text: 'Válassz egy napot a naptárban – a kiemelt napokon van szabad időpont.' }));
      return;
    }

    var savok = this.napok[this.valasztott] || [];
    j.appendChild(el('h3', { id: this.id + '-savcim', text: napNev(this.valasztott) }));
    var lista = el('div', { class: 's-fogl__savok', role: 'group', 'aria-labelledby': this.id + '-savcim' });
    savok.forEach(function (s) {
      var b = el('button', { type: 'button', class: 's-fogl__sav', 'aria-pressed': self.sav && self.sav.utc === s.utc ? 'true' : 'false', 'aria-label': s.ido + '–' + s.veg, text: s.ido });
      b.addEventListener('click', function () {
        self.sav = s;
        Array.prototype.forEach.call(lista.children, function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        self.urlapRajzol();
        var elso = self.urlapDoboz.querySelector('input:not([tabindex="-1"]), button');
        if (elso) { elso.focus(); }
      });
      lista.appendChild(b);
    });
    j.appendChild(lista);
    this.urlapDoboz = el('div', { style: 'margin-top:16px' });
    j.appendChild(this.urlapDoboz);
    if (this.sav) { this.urlapRajzol(); }
  };

  Foglalo.prototype.mezo = function (nev, cimke, attr) {
    var id = this.id + '-' + nev;
    var tag = nev === 'megjegyzes' ? 'textarea' : 'input';
    var a = Object.assign({ id: id, name: nev }, attr || {});
    if (tag === 'input' && !a.type) { a.type = 'text'; }
    return el('div', { class: 's-fogl__mezo' }, [el('label', { for: id, text: cimke }), el(tag, a)]);
  };

  Foglalo.prototype.urlapRajzol = function () {
    var self = this;
    var d = this.urlapDoboz;
    d.textContent = '';

    if (this.atfoglal) {
      var at = el('button', { type: 'button', class: 's-fogl__gomb', text: 'Áthelyezem erre: ' + napNev(this.valasztott) + ' ' + this.sav.ido });
      at.addEventListener('click', function () { self.kuld({ atfoglal: self.atfoglal }, at); });
      d.appendChild(at);
      return;
    }

    var f = el('form', { class: 's-fogl__urlap', novalidate: true });
    f.appendChild(el('p', { class: 's-fogl__halvany', text: 'Választott időpont: ' + napNev(this.valasztott) + ', ' + this.sav.ido + '–' + this.sav.veg }));
    f.appendChild(el('div', { class: 's-fogl__ket' }, [
      this.mezo('nev', 'Név *', { required: true, autocomplete: 'name', maxlength: 190 }),
      this.mezo('email', 'E-mail *', { type: 'email', required: true, autocomplete: 'email', maxlength: 190 })
    ]));
    f.appendChild(el('div', { class: 's-fogl__ket' }, [
      this.mezo('telefon', 'Telefon', { type: 'tel', autocomplete: 'tel', maxlength: 60 }),
      this.mezo('ceg', 'Cég / szerviz neve', { autocomplete: 'organization', maxlength: 190 })
    ]));
    f.appendChild(this.mezo('megjegyzes', 'Megjegyzés (miről beszéljünk?)', { maxlength: 1500, rows: 3 }));
    f.appendChild(el('div', { class: 's-fogl__csapda', 'aria-hidden': 'true' }, [el('label', { for: this.id + '-weboldal', text: 'Weboldal' }), el('input', { id: this.id + '-weboldal', name: 'weboldal', tabindex: '-1', autocomplete: 'off' })]));
    var adv = CFG.adatvedelem ? el('a', { href: CFG.adatvedelem, target: '_blank', rel: 'noopener', text: 'adatkezelési tájékoztatóban' }) : null;
    f.appendChild(el('p', { class: 's-fogl__halvany' }, adv ? ['Az adataidat csak az időpont egyeztetésére használjuk – részletek az ', adv, '.'] : ['Az adataidat csak az időpont egyeztetésére használjuk.']));
    var gomb = el('button', { type: 'submit', class: 's-fogl__gomb', text: 'Időpont lefoglalása' });
    f.appendChild(gomb);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var adat = {};
      Array.prototype.forEach.call(f.elements, function (x) { if (x.name) { adat[x.name] = x.value; } });
      var hiba = !adat.nev || adat.nev.trim().length < 2 ? 'nev' : (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(adat.email || '') ? 'email' : '');
      Array.prototype.forEach.call(f.querySelectorAll('[aria-invalid]'), function (x) { x.removeAttribute('aria-invalid'); });
      if (hiba) {
        var m = f.elements[hiba];
        m.setAttribute('aria-invalid', 'true');
        m.focus();
        self.uzen(hiba === 'nev' ? 'Add meg a neved.' : 'Adj meg egy működő e-mail-címet.', 'hiba');
        return;
      }
      self.kuld(adat, gomb);
    });
    d.appendChild(f);
    this.kitolt();
  };

  Foglalo.prototype.kitolt = function () {
    var f = this.urlapDoboz && this.urlapDoboz.querySelector('form');
    var e = this.elotolt || {};
    if (!f) { return; }
    ['nev', 'email', 'telefon', 'ceg'].forEach(function (k) { if (e[k] && f.elements[k] && !f.elements[k].value) { f.elements[k].value = e[k]; } });
  };

  Foglalo.prototype.kuld = function (adat, gomb) {
    var self = this;
    adat.tipus = this.tipus;
    adat.kezdet = this.sav.utc;
    if (this.rendeles) { adat.rendeles = this.rendeles; adat.rt = this.rt; }
    if (window.SDH && typeof window.SDH.vedelem === 'function') {
      try { Object.assign(adat, window.SDH.vedelem() || {}); } catch (e) { /* a védelem nélkül is megy, a szerver dönt */ }
    }
    gomb.disabled = true;
    this.uzen('Foglalás folyamatban…');
    api('/foglalas', adat, 'POST').then(function (v) {
      gomb.disabled = false;
      if (!v.ok) {
        self.uzen(v.adat.message || 'A foglalás nem sikerült. Próbáld újra.', 'hiba');
        if (v.status === 409) {
          var k = self.tipus + '|' + self.honapKulcs();
          delete self.betoltve[k];
          self.sav = null;
          self.betolt().then(function () { self.naptarRajzol(); self.jobbRajzol(); self.uzen(v.adat.message, 'hiba'); });
        }
        return;
      }
      self.siker(v.adat);
    });
  };

  Foglalo.prototype.siker = function (a) {
    this.g.textContent = '';
    var cim = el('h3', { tabindex: '-1', text: this.atfoglal ? 'Áthelyeztük az időpontot' : 'Köszönjük, rögzítettük az időpontot!' });
    this.g.appendChild(el('div', { class: 's-fogl__kartya', role: 'status' }, [
      cim,
      el('p', { text: a.uzenet || a.mikor || '' }),
      el('div', { class: 's-fogl__gombsor' }, [
        a.ics_url ? el('a', { class: 's-fogl__gomb', href: a.ics_url, download: 'idopont.ics', text: 'Hozzáadás a naptárhoz (.ics)' }) : null,
        a.kezelo_url ? el('a', { class: 's-fogl__gomb s-fogl__gomb--masod', href: a.kezelo_url, text: 'Lemondás vagy áthelyezés' }) : null
      ]),
      el('p', { class: 's-fogl__halvany', text: 'A visszaigazolást a naptárfájllal együtt e-mailben is elküldtük.' })
    ]));
    cim.focus();
  };

  /** Meglévő foglalás kezelése (?foglalas=token): adatok, lemondás (kétlépcsős), áthelyezés. */
  Foglalo.prototype.kezeles = function () {
    var self = this;
    this.g.appendChild(el('p', { class: 's-fogl__uzenet', role: 'status', text: 'Betöltés…' }));
    api('/foglalas/adat', { t: this.kezelo }, 'GET').then(function (v) {
      self.g.textContent = '';
      if (!v.ok) {
        self.g.appendChild(el('p', { class: 's-fogl__uzenet', 'data-szint': 'hiba', role: 'alert', text: v.adat.message || 'A link érvénytelen.' }));
        return;
      }
      var a = v.adat;
      var allapot = a.allapot === 'foglalt' ? '' : (a.allapot === 'lemondva' ? ' – lemondva' : ' – áthelyezve');
      var elo = el('p', { class: 's-fogl__uzenet', role: 'status', 'aria-live': 'polite' });
      var kartya = el('div', { class: 's-fogl__kartya' }, [
        el('h3', { text: a.tipus_nev + allapot }),
        el('p', { text: a.mikor + ' (magyar idő szerint)' })
      ]);
      if (a.lemondhato) {
        var lem = el('button', { type: 'button', class: 's-fogl__gomb s-fogl__gomb--masod', text: 'Lemondom' });
        var ath = el('button', { type: 'button', class: 's-fogl__gomb', text: 'Másik időpontot választok' });
        ketlepcsos(lem, function () {
          lem.disabled = true;
          api('/foglalas/lemond', { token: self.kezelo }, 'POST').then(function (r) {
            lem.disabled = false;
            if (r.ok) {
              kartya.replaceChildren(el('h3', { text: 'Lemondtuk az időpontot' }), el('p', { text: r.adat.uzenet || '' }));
            } else {
              elo.textContent = r.adat.message || 'Nem sikerült lemondani.';
              elo.setAttribute('data-szint', 'hiba');
            }
          });
        });
        ath.addEventListener('click', function () {
          self.atfoglal = self.kezelo;
          self.tipus = a.tipus;
          self.tipusok = (Array.isArray(CFG.tipusok) ? CFG.tipusok : []).filter(function (t) { return t.kulcs === a.tipus; });
          if (!self.tipusok.length) { self.tipusok = [{ kulcs: a.tipus, nev: a.tipus_nev, leiras: '', hossz: 0 }]; }
          self.rajzol();
        });
        kartya.appendChild(el('div', { class: 's-fogl__gombsor' }, [ath, lem]));
      } else if (a.allapot === 'foglalt') {
        kartya.appendChild(el('p', { class: 's-fogl__halvany', text: 'Ez az időpont már közel van, ezért online nem módosítható. Írj vagy telefonálj nekünk.' }));
      }
      self.g.appendChild(kartya);
      self.g.appendChild(elo);
    });
  };

  function csatol(gyoker) {
    var lista = (gyoker || document).querySelectorAll('[data-sdh="foglalas"]:not([data-sdh-kesz])');
    Array.prototype.forEach.call(lista, function (g) {
      g.setAttribute('data-sdh-kesz', '1');
      try { new Foglalo(g); } catch (e) { g.textContent = 'Az időpontfoglalás most nem tölthető be.'; }
    });
  }

  window.SDH = window.SDH || {};
  window.SDH.foglalas = { csatol: csatol };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { csatol(); });
  } else {
    csatol();
  }
})();
