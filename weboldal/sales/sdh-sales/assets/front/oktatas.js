/**
 * SDH Értékesítés – oktató mód (vanilla JS): kurzusok és leckék, lépésenkénti lejátszó haladásjelzővel, kvíz szerveroldali
 * értékeléssel és magyarázattal, „Magyarázd el, Szikra” (oktató mód), tanúsítvány.
 * Csatolás: <div data-sdh="oktatas" data-opciok='{"modul":"","lecke":""}'></div>
 * Adat: window.SDH_OKTATAS (SDH_Sales_Oktatas::js_adat).
 * Haladás: localStorage (látogató); bejelentkezve a REST /oktatas/haladas is (a kettő összefésülve).
 */
(function () {
  'use strict';

  var A = window.SDH_OKTATAS;
  if (!A || !A.rest) { return; }
  var S = A.szoveg || {};
  var TAR = 'sdh_oktatas';

  function olvas() {
    try { var v = JSON.parse(window.localStorage.getItem(TAR) || '{}'); return v && typeof v === 'object' ? v : {}; } catch (e) { return {}; }
  }
  function ir(v) {
    try { window.localStorage.setItem(TAR, JSON.stringify(v)); } catch (e) { /* nincs tár: csak memóriában */ }
  }

  var haladas = olvas();

  function api(ut, metodus, adat) {
    var h = { 'Accept': 'application/json' };
    if (A.nonce) { h['X-WP-Nonce'] = A.nonce; }
    var o = { method: metodus, credentials: 'same-origin', headers: h };
    if (adat !== undefined) { h['Content-Type'] = 'application/json'; o.body = JSON.stringify(adat); }
    return fetch(A.rest + ut, o).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error(j && j.message ? j.message : S.hiba); e.adat = j; throw e; }
        return j;
      });
    });
  }

  function osszefesul(a, b) {
    var ki = JSON.parse(JSON.stringify(a || {}));
    Object.keys(b || {}).forEach(function (k) {
      var x = ki[k] || {};
      var y = b[k] || {};
      ki[k] = {
        lepes: Math.max(parseInt(x.lepes, 10) || 0, parseInt(y.lepes, 10) || 0),
        kesz: !!(x.kesz || y.kesz),
        kviz: (y.kviz && y.kviz.igazolas) ? y.kviz : (x.kviz || y.kviz || null)
      };
    });
    return ki;
  }

  var szinkronIdo = null;
  function ment(lecke, valtozas) {
    haladas[lecke] = osszefesul({ x: haladas[lecke] || {} }, { x: valtozas }).x;
    ir(haladas);
    if (A.belepett) {
      clearTimeout(szinkronIdo);
      szinkronIdo = setTimeout(function () {
        api('/haladas', 'POST', { haladas: haladas }).then(function (j) {
          if (j && j.haladas) { haladas = osszefesul(haladas, j.haladas); ir(haladas); }
        }).catch(function () { /* később újra */ });
      }, 600);
    }
  }

  function el(tag, osztaly, szoveg) {
    var e = document.createElement(tag);
    if (osztaly) { e.className = osztaly; }
    if (szoveg !== undefined && szoveg !== null) { e.textContent = szoveg; }
    return e;
  }
  function gomb(szoveg, osztaly, fv) {
    var b = el('button', 's-okt__gomb' + (osztaly ? ' ' + osztaly : ''), szoveg);
    b.type = 'button';
    b.addEventListener('click', fv);
    return b;
  }
  /** A lecke szövege a tartalom-tárból (szerkesztőtől) jön: egyszerű HTML-t engedünk, a többit szövegként. */
  function tisztaHtml(html) {
    var t = document.createElement('template');
    t.innerHTML = String(html || '');
    var enged = { P: 1, BR: 1, UL: 1, OL: 1, LI: 1, STRONG: 1, B: 1, EM: 1, I: 1, CODE: 1, A: 1, KBD: 1 };
    (function jar(n) {
      Array.prototype.slice.call(n.childNodes).forEach(function (c) {
        if (c.nodeType === 1) {
          if (!enged[c.tagName]) { c.replaceWith(document.createTextNode(c.textContent)); return; }
          Array.prototype.slice.call(c.attributes).forEach(function (at) {
            if (!(c.tagName === 'A' && at.name === 'href' && /^(https?:|\/|#)/i.test(at.value))) { c.removeAttribute(at.name); }
          });
          jar(c);
        } else if (c.nodeType !== 3) {
          c.remove();
        }
      });
    }(t.content));
    var d = document.createElement('div');
    d.appendChild(t.content);
    return d;
  }

  var leckek = {};
  (A.leckek || []).forEach(function (l) { leckek[l.kulcs] = l; });

  function Jatszo(gyoker, opc) {
    this.g = gyoker;
    this.opc = opc;
    this.g.classList.add('s-okt');
    if (opc.lecke && leckek[opc.lecke]) { this.lecke(opc.lecke); } else { this.lista(); }
  }

  Jatszo.prototype.ures = function () {
    this.g.textContent = '';
  };

  Jatszo.prototype.lista = function () {
    var self = this;
    this.ures();
    var kurzusok = (A.kurzusok || []).filter(function (k) { return !self.opc.modul || k.modul === self.opc.modul; });
    if (!kurzusok.length) { this.g.appendChild(el('p', 's-okt__ures', S.nincs)); return; }
    if (!A.belepett) { this.g.appendChild(el('p', 's-okt__megj', S.helyi)); }

    kurzusok.forEach(function (k) {
      var sec = el('section', 's-okt__kurzus');
      var kesz = k.leckek.filter(function (lk) { return haladas[lk] && haladas[lk].kesz; }).length;
      var fej = el('div', 's-okt__kurzus-fej');
      fej.appendChild(el('h2', 's-okt__kurzus-cim', k.cim));
      var pr = el('progress', 's-okt__haladas');
      pr.max = k.leckek.length;
      pr.value = kesz;
      pr.setAttribute('aria-label', S.haladas + ': ' + k.cim);
      fej.appendChild(pr);
      fej.appendChild(el('span', 's-okt__szam', kesz + ' / ' + k.leckek.length));
      sec.appendChild(fej);

      var ul = el('ul', 's-okt__leckek');
      k.leckek.forEach(function (lk) {
        var l = leckek[lk];
        if (!l) { return; }
        var li = el('li', 's-okt__lecke' + (haladas[lk] && haladas[lk].kesz ? ' s-okt__lecke--kesz' : ''));
        var b = gomb(l.cim, 's-okt__lecke-gomb', function () { self.lecke(lk); });
        if (haladas[lk] && haladas[lk].kesz) { b.setAttribute('aria-label', l.cim + ' – ' + S.lecke_kesz); }
        li.appendChild(b);
        var meta = [];
        if (l.ido_perc) { meta.push(l.ido_perc + ' ' + S.perc); }
        meta.push(l.lepesek.length + ' ' + S.lepes.toLowerCase());
        if (haladas[lk] && haladas[lk].kesz) { meta.push('✓ ' + S.kesz); }
        li.appendChild(el('span', 's-okt__meta', meta.join(' · ')));
        ul.appendChild(li);
      });
      sec.appendChild(ul);
      sec.appendChild(self.tanusitvanyDoboz(k));
      self.g.appendChild(sec);
    });
  };

  Jatszo.prototype.tanusitvanyDoboz = function (k) {
    var self = this;
    var d = el('div', 's-okt__tanusitvany');
    var kvizes = k.leckek.filter(function (lk) { return leckek[lk] && leckek[lk].kviz && leckek[lk].kviz.length; });
    var megvan = kvizes.every(function (lk) { return haladas[lk] && haladas[lk].kviz && haladas[lk].kviz.igazolas; });
    d.appendChild(el('h3', '', S.tanusitvany));
    if (!kvizes.length || !megvan) { d.appendChild(el('p', 's-okt__megj', S.tanusitvany_feltetel)); return d; }

    var id = 's-okt-nev-' + k.modul;
    var cimke = el('label', '', S.tanusitvany_nev);
    cimke.setAttribute('for', id);
    var mezo = el('input', 's-okt__mezo');
    mezo.id = id;
    mezo.type = 'text';
    mezo.maxLength = 80;
    mezo.autocomplete = 'name';
    var ered = el('p', 's-okt__eredmeny');
    ered.setAttribute('role', 'status');
    var b = gomb(S.tanusitvany_ker, '', function () {
      var ig = {};
      kvizes.forEach(function (lk) { ig[lk] = haladas[lk].kviz.igazolas; });
      b.disabled = true;
      api('/tanusitvany', 'POST', { kurzus: k.modul, nev: mezo.value, igazolasok: ig }).then(function (j) {
        ered.textContent = S.tanusitvany_kesz + ' ' + j.kod + ' ';
        var a = el('a', '', S.tanusitvany_nyit);
        a.href = j.url;
        a.target = '_blank';
        a.rel = 'noopener';
        ered.appendChild(a);
      }).catch(function (e) {
        ered.textContent = e.message || S.hiba;
      }).then(function () { b.disabled = false; });
    });
    d.appendChild(cimke);
    d.appendChild(mezo);
    d.appendChild(b);
    d.appendChild(ered);
    void self;
    return d;
  };

  Jatszo.prototype.lecke = function (kulcs, lepes) {
    var self = this;
    var l = leckek[kulcs];
    if (!l) { this.lista(); return; }
    var h = haladas[kulcs] || {};
    var i = typeof lepes === 'number' ? lepes : Math.min(parseInt(h.lepes, 10) || 0, l.lepesek.length - 1);
    this.ures();

    var fej = el('div', 's-okt__fej');
    if (!this.opc.lecke) { fej.appendChild(gomb('← ' + S.vissza, 's-okt__gomb--halk', function () { self.lista(); })); }
    fej.appendChild(el('h2', 's-okt__cim', l.cim));
    if (l.cel) {
      var c = el('p', 's-okt__cel');
      c.appendChild(el('strong', '', S.cel + ': '));
      c.appendChild(document.createTextNode(l.cel));
      fej.appendChild(c);
    }
    this.g.appendChild(fej);

    var pr = el('progress', 's-okt__haladas');
    pr.max = l.lepesek.length;
    pr.value = i + 1;
    pr.setAttribute('aria-label', S.haladas);
    this.g.appendChild(pr);

    var lp = l.lepesek[i];
    var kartya = el('article', 's-okt__lepes');
    kartya.setAttribute('aria-live', 'polite');
    kartya.appendChild(el('p', 's-okt__lepes-szam', S.lepes + ' ' + (i + 1) + ' / ' + l.lepesek.length));
    if (lp.cim) { kartya.appendChild(el('h3', 's-okt__lepes-cim', lp.cim)); }
    kartya.appendChild(tisztaHtml(lp.szoveg));
    if (lp.tipp) {
      var t = el('p', 's-okt__tipp');
      t.appendChild(el('strong', '', 'Tipp: '));
      t.appendChild(document.createTextNode(lp.tipp));
      kartya.appendChild(t);
    }
    this.g.appendChild(kartya);
    ment(kulcs, { lepes: i });

    var sor = el('div', 's-okt__gombsor');
    var elozo = gomb(S.elozo, 's-okt__gomb--halk', function () { self.lecke(kulcs, i - 1); });
    elozo.disabled = i === 0;
    sor.appendChild(elozo);

    if (A.szikra && window.SDH && window.SDH.szikra) {
      sor.appendChild(gomb(S.magyarazd, 's-okt__gomb--halk', function () {
        window.SDH.szikra.kerdez(S.magyarazd_kerdes + ' (' + l.cim + ', ' + S.lepes.toLowerCase() + ' ' + (i + 1) + ')', { mod: 'oktato', lecke: kulcs, lepes: i });
      }));
    }

    if (i < l.lepesek.length - 1) {
      sor.appendChild(gomb(S.kovetkezo, '', function () { self.lecke(kulcs, i + 1); }));
    } else if (l.kviz && l.kviz.length) {
      sor.appendChild(gomb(S.kviz_inditas, '', function () { self.kviz(kulcs); }));
    } else {
      sor.appendChild(gomb(S.kesz, '', function () { ment(kulcs, { kesz: true }); self.opc.lecke ? self.lecke(kulcs, 0) : self.lista(); }));
    }
    this.g.appendChild(sor);
    var fokusz = kartya.querySelector('h3') || kartya;
    fokusz.tabIndex = -1;
    if (typeof lepes === 'number') { fokusz.focus({ preventScroll: false }); }
  };

  Jatszo.prototype.kviz = function (kulcs) {
    var self = this;
    var l = leckek[kulcs];
    this.ures();
    this.g.appendChild(el('h2', 's-okt__cim', S.kviz + ': ' + l.cim));
    var urlap = el('form', 's-okt__kviz');
    urlap.noValidate = true;

    l.kviz.forEach(function (q, qi) {
      var fs = el('fieldset', 's-okt__kerdes');
      fs.appendChild(el('legend', '', (qi + 1) + '. ' + q.kerdes));
      if (q.tobb) { fs.appendChild(el('p', 's-okt__megj', S.tobb)); }
      q.valaszok.forEach(function (v, vi) {
        var id = 's-okt-' + kulcs + '-' + qi + '-' + vi;
        var sor = el('div', 's-okt__valasz');
        var inp = el('input');
        inp.type = q.tobb ? 'checkbox' : 'radio';
        inp.name = 'q' + qi;
        inp.value = String(vi);
        inp.id = id;
        var lab = el('label', '', v);
        lab.setAttribute('for', id);
        sor.appendChild(inp);
        sor.appendChild(lab);
        fs.appendChild(sor);
      });
      fs.appendChild(el('div', 's-okt__magyarazat'));
      urlap.appendChild(fs);
    });

    var ered = el('p', 's-okt__eredmeny');
    ered.setAttribute('role', 'status');
    var sor = el('div', 's-okt__gombsor');
    var ell = el('button', 's-okt__gomb', S.ellenoriz);
    ell.type = 'submit';
    sor.appendChild(gomb(S.vissza, 's-okt__gomb--halk', function () { self.opc.lecke ? self.lecke(kulcs, 0) : self.lista(); }));
    sor.appendChild(ell);
    urlap.appendChild(ered);
    urlap.appendChild(sor);

    urlap.addEventListener('submit', function (e) {
      e.preventDefault();
      var valaszok = l.kviz.map(function (q, qi) {
        var jel = urlap.querySelectorAll('input[name="q' + qi + '"]:checked');
        var v = Array.prototype.map.call(jel, function (x) { return parseInt(x.value, 10); });
        return q.tobb ? v : (v.length ? v[0] : null);
      });
      ell.disabled = true;
      api('/kviz', 'POST', { lecke: kulcs, valaszok: valaszok }).then(function (j) {
        var fs = urlap.querySelectorAll('fieldset');
        j.helyes.forEach(function (jo, qi) {
          var m = fs[qi].querySelector('.s-okt__magyarazat');
          m.textContent = '';
          m.className = 's-okt__magyarazat s-okt__magyarazat--' + (jo ? 'jo' : 'rossz');
          m.appendChild(el('strong', '', (jo ? '✓ ' + S.helyes : '✗ ' + S.hibas) + '. '));
          if (j.magyarazat[qi]) { m.appendChild(document.createTextNode(j.magyarazat[qi])); }
        });
        ered.textContent = j.pont + ' / ' + j.ossz + ' – ' + (j.sikeres ? S.sikeres : S.sikertelen);
        ered.className = 's-okt__eredmeny s-okt__eredmeny--' + (j.sikeres ? 'jo' : 'rossz');
        ment(kulcs, { kesz: j.sikeres, kviz: { pont: j.pont, ossz: j.ossz, igazolas: j.igazolas } });
        ell.textContent = j.sikeres ? S.kesz : S.ujra;
        ell.disabled = false;
        if (j.sikeres) {
          ell.type = 'button';
          ell.addEventListener('click', function () { self.opc.lecke ? self.lecke(kulcs, 0) : self.lista(); }, { once: true });
        }
        ered.focus && ered.setAttribute('tabindex', '-1');
        ered.focus();
      }).catch(function (err) {
        ered.textContent = err.message || S.hiba;
        ell.disabled = false;
      });
    });

    this.g.appendChild(urlap);
  };

  function indit() {
    var helyek = document.querySelectorAll('[data-sdh="oktatas"]:not([data-sdh-kesz])');
    Array.prototype.forEach.call(helyek, function (g) {
      var opc = {};
      try { opc = JSON.parse(g.getAttribute('data-opciok') || '{}') || {}; } catch (e) { opc = {}; }
      g.setAttribute('data-sdh-kesz', '1');
      g.__sdhOktatas = new Jatszo(g, opc);
    });
  }

  function start() {
    if (A.belepett) {
      api('/haladas', 'GET').then(function (j) {
        if (j && j.haladas) {
          haladas = osszefesul(haladas, j.haladas);
          ir(haladas);
          api('/haladas', 'POST', { haladas: haladas }).catch(function () {});
        }
      }).catch(function () {}).then(indit);
    } else {
      indit();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
}());
