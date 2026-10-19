/*
 * Kereső – Ctrl+K / „/” / gomb ablak és az oldalkereső-szekció (vanilla, lusta betöltés a tema.js-ből).
 *
 * Index: window.APP_TEMA.index (a szerver adja az URL-t; elemek {t, u, k, s, w, n}, `n` = ékezet nélküli kisbetűs
 * cím + kulcsszavak). Az index csak az első keresési szándéknál töltődik le (egyszer, a böngésző gyorsítótárával).
 * Egyezés: magyar ékezet-hajtogatás (á→a, ő→o, ű→u…), szavanként: pontos / előtag / részsztring / elírás-tűrés
 * (Damerau–Levenshtein, a szóhossz szerinti küszöbbel) + trigram-hasonlóság; a cím-találat erősebb, a típus súlyoz.
 * Csoportosítás típus szerint, billentyűzet: ↑↓ Home End Enter Esc; legutóbbi keresések (localStorage).
 * Tartalék: ha az index nem érhető el, GET {rest}/kereso?q= (a REST-címet a szerver adja).
 */
(function (w, d) {
    'use strict';

    var CFG = w.APP_TEMA || {};
    var SZ = CFG.kereso_szoveg || {};
    var CSOP = SZ.csoport || {};
    var SORREND = ['funkcio', 'modul', 'iparag', 'oldal', 'gyik', 'tudas', 'blog'];
    var SULY = { funkcio: 1.15, modul: 1.1, iparag: 1.05, oldal: 1, gyik: 0.95, tudas: 0.9, blog: 0.85 };
    var IKON = { funkcio: 'villam', modul: 'modul', iparag: 'bolt', oldal: 'link', gyik: 'kerdes', tudas: 'konyv', blog: 'konyv' };
    var MAX = 24;

    var index = null;
    var indexIg = null;
    var restMod = false;

    /* ---------- Normalizálás ---------- */
    var EK = { 'á': 'a', 'é': 'e', 'í': 'i', 'ó': 'o', 'ö': 'o', 'ő': 'o', 'ú': 'u', 'ü': 'u', 'ű': 'u', 'ä': 'a', 'ô': 'o', 'õ': 'o', 'û': 'u' };
    function hajt(s) {
        s = String(s || '').toLowerCase().replace(/[áéíóöőúüűäôõû]/g, function (c) { return EK[c] || c; });
        if (s.normalize) { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); }
        return s.replace(/[^a-z0-9]+/g, ' ').trim();
    }
    function szavak(s) { return hajt(s).split(' ').filter(function (x) { return x.length > 0; }); }
    function trigramok(s) {
        var t = {}; s = ' ' + s + ' ';
        for (var i = 0; i < s.length - 2; i++) { t[s.substr(i, 3)] = 1; }
        return t;
    }
    function hasonlo(a, b) {
        var ta = trigramok(a), tb = trigramok(b), k = 0, n = 0, x;
        for (x in ta) { if (ta.hasOwnProperty(x)) { n++; if (tb[x]) { k++; } } }
        for (x in tb) { if (tb.hasOwnProperty(x) && !ta[x]) { n++; } }
        return n ? k / n : 0;
    }
    // Korlátos Damerau–Levenshtein (korai kilépéssel).
    function tav(a, b, max) {
        if (Math.abs(a.length - b.length) > max) { return max + 1; }
        var el = [], ak = [], i, j;
        for (j = 0; j <= b.length; j++) { ak[j] = j; }
        var elozo2 = null;
        for (i = 1; i <= a.length; i++) {
            var sor = [i], min = i;
            for (j = 1; j <= b.length; j++) {
                var k = a[i - 1] === b[j - 1] ? 0 : 1;
                var v = Math.min(ak[j] + 1, sor[j - 1] + 1, ak[j - 1] + k);
                if (elozo2 && i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) { v = Math.min(v, elozo2[j - 2] + 1); }
                sor[j] = v;
                if (v < min) { min = v; }
            }
            if (min > max) { return max + 1; }
            elozo2 = ak; ak = sor;
        }
        el = ak;
        return el[b.length];
    }
    function turelem(n) { return n <= 3 ? 0 : (n <= 6 ? 1 : 2); }

    /* ---------- Pontozás ---------- */
    function elokeszit(e) {
        if (e._n) { return; }
        e._cim = hajt(e.t);
        e._n = e.n ? hajt(e.n) : hajt((e.t || '') + ' ' + (e.w || ''));
        e._szavak = e._n.split(' ');
        e._cimSzavak = e._cim.split(' ');
    }
    function pont(e, qszavak, qteljes) {
        elokeszit(e);
        var p = 0;
        if (e._cim === qteljes) { p += 12; } else if (e._cim.indexOf(qteljes) === 0) { p += 7; } else if (e._cim.indexOf(qteljes) !== -1) { p += 4; }
        for (var i = 0; i < qszavak.length; i++) {
            var q = qszavak[i], legjobb = 0;
            var lista = e._szavak;
            for (var j = 0; j < lista.length; j++) {
                var s = lista[j], v = 0;
                if (!s) { continue; }
                if (s === q) { v = 3; } else if (s.indexOf(q) === 0) { v = q.length >= 2 ? 2.2 : 0.8; } else if (q.length >= 3 && s.indexOf(q) !== -1) { v = 1.2; } else {
                    var t = turelem(q.length);
                    if (t > 0) {
                        var d0 = tav(q, s.length > q.length + 2 ? s.substr(0, q.length + 1) : s, t);
                        if (d0 <= t) { v = 1.6 - d0 * 0.4; }
                    }
                }
                if (v > 0 && e._cimSzavak.indexOf(s) !== -1) { v *= 1.35; }
                if (v > legjobb) { legjobb = v; }
            }
            if (legjobb === 0) { return 0; } // minden szónak találnia kell
            p += legjobb;
        }
        if (qteljes.length >= 4) { p += hasonlo(qteljes, e._cim) * 2; }
        return p * (SULY[e.k] || 1);
    }
    function keres(q) {
        var qt = hajt(q), qs = szavak(q);
        if (!qs.length || !index) { return []; }
        var ki = [];
        for (var i = 0; i < index.length; i++) {
            var p = pont(index[i], qs, qt);
            if (p > 0) { ki.push({ e: index[i], p: p }); }
        }
        ki.sort(function (a, b) { return b.p - a.p; });
        return ki.slice(0, MAX).map(function (x) { return x.e; });
    }

    /* ---------- Adat ---------- */
    function indexTolt() {
        if (index) { return Promise.resolve(index); }
        if (indexIg) { return indexIg; }
        if (!CFG.index || !w.fetch) { restMod = true; return Promise.resolve(null); }
        indexIg = fetch(CFG.index, { credentials: 'omit' }).then(function (r) {
            if (!r.ok) { throw new Error(String(r.status)); }
            return r.json();
        }).then(function (j) {
            var l = Array.isArray(j) ? j : (j && Array.isArray(j.elemek) ? j.elemek : []);
            index = l.filter(function (e) { return e && e.t && e.u; });
            return index;
        }).catch(function () { restMod = true; indexIg = null; return null; });
        return indexIg;
    }
    function restKeres(q) {
        if (!CFG.rest || !w.fetch) { return Promise.reject(new Error('nincs')); }
        var u = CFG.rest.replace(/\/$/, '') + '/kereso?q=' + encodeURIComponent(q) + '&max=' + MAX;
        return fetch(u, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) {
            if (!r.ok) { throw new Error(String(r.status)); }
            return r.json();
        }).then(function (j) { return (j && j.talalatok) || []; });
    }

    /* ---------- Legutóbbiak ---------- */
    function legutobbi(uj) {
        var l = [];
        try { l = JSON.parse(w.localStorage.getItem('kereso-elozmeny') || '[]') || []; } catch (e) { l = []; }
        if (uj) {
            l = [uj].concat(l.filter(function (x) { return x.u !== uj.u; })).slice(0, 5);
            try { w.localStorage.setItem('kereso-elozmeny', JSON.stringify(l)); } catch (e) { /* privát mód */ }
        }
        return l;
    }

    /* ---------- Kirajzolás ---------- */
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function biztonsagosUrl(u) {
        try { var x = new URL(u, w.location.href); return (x.protocol === 'https:' || x.protocol === 'http:') ? x.href : '#'; } catch (e) { return '#'; }
    }
    function kiemel(cim, q) {
        var qs = szavak(q), h = hajt(cim);
        if (!qs.length || h.length !== String(cim).length) { return esc(cim); }
        var jel = new Array(h.length + 1).join('0').split('');
        qs.forEach(function (s) { var i = -1; while ((i = h.indexOf(s, i + 1)) !== -1) { for (var k = i; k < i + s.length; k++) { jel[k] = '1'; } } });
        var ki = '', nyit = false;
        for (var i = 0; i < cim.length; i++) {
            if (jel[i] === '1' && !nyit) { ki += '<mark>'; nyit = true; }
            if (jel[i] !== '1' && nyit) { ki += '</mark>'; nyit = false; }
            ki += esc(cim[i]);
        }
        return ki + (nyit ? '</mark>' : '');
    }
    function ikonSvg(n) {
        // A szimbólumot a téma sprite-ja adja, ha az oldalon szerepel; különben egy pont.
        var van = d.getElementById('i-' + n);
        return van ? '<svg class="s-ikon" aria-hidden="true" width="24" height="24"><use href="#i-' + n + '"></use></svg>' : '<svg class="s-ikon" aria-hidden="true" viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="12" r="3" fill="currentColor"/></svg>';
    }

    function Lista(input, doboz, allapot, nyitottJelzo) {
        this.input = input; this.doboz = doboz; this.allapot = allapot;
        this.elemek = []; this.akt = -1; this.q = '';
        this.azon = doboz.id || ('kt-' + Math.random().toString(36).slice(2));
        this.jelzo = nyitottJelzo;
    }
    Lista.prototype.rajzol = function (talalatok, q, cimke) {
        var self = this;
        this.q = q;
        this.elemek = talalatok;
        this.akt = talalatok.length ? 0 : -1;
        if (!talalatok.length) {
            this.doboz.innerHTML = q ? '<p class="s-kt__ures">' + esc(SZ.nincs || 'Nincs találat.') + '</p>' : '';
            if (q) { this.nepszeru(true); }
            this.input.setAttribute('aria-expanded', 'false');
            this.input.removeAttribute('aria-activedescendant');
            if (this.allapot) { this.allapot.textContent = q ? (SZ.nincs || '') : ''; }
            return;
        }
        var cs = {};
        talalatok.forEach(function (e) { var k = cimke ? '_' : (e.k || 'oldal'); (cs[k] = cs[k] || []).push(e); });
        var sor = cimke ? ['_'] : SORREND.filter(function (k) { return cs[k]; }).concat(Object.keys(cs).filter(function (k) { return SORREND.indexOf(k) === -1; }));
        var html = '', i = 0, rendezett = [];
        sor.forEach(function (k) {
            var cid = self.azon + '-cs-' + k;
            html += '<div role="group" aria-labelledby="' + cid + '"><p class="s-kt__csoport" id="' + cid + '">' + esc(cimke || CSOP[k] || k) + '</p>';
            cs[k].forEach(function (e) {
                var id = self.azon + '-' + i;
                html += '<a class="s-kt__elem" role="option" id="' + id + '" href="' + esc(biztonsagosUrl(e.u)) + '" data-i="' + i + '" aria-selected="' + (i === 0 ? 'true' : 'false') + '" tabindex="-1">'
                    + '<span class="s-kt__ikon">' + ikonSvg(IKON[e.k] || 'link') + '</span>'
                    + '<span class="s-kt__szoveg"><span class="s-kt__cim">' + kiemel(e.t, q) + '</span>'
                    + (e.s ? '<span class="s-kt__kivonat">' + esc(e.s) + '</span>' : '') + '</span></a>';
                rendezett.push(e);
                i++;
            });
            html += '</div>';
        });
        this.elemek = rendezett;
        this.doboz.innerHTML = html;
        this.input.setAttribute('aria-expanded', 'true');
        this.jelol(0);
        if (this.allapot) { this.allapot.textContent = q ? (SZ.talalat || '%d találat').replace('%d', rendezett.length) : ''; }
    };
    Lista.prototype.nepszeru = function (hozzafuz) {
        var l = legutobbi();
        var html = '';
        if (!hozzafuz && l.length) {
            this.rajzol(l, '', SZ.legutobbi || 'Legutóbbi keresések');
            return;
        }
        var n = CFG.nepszeru || [];
        if (!n.length) { return; }
        if (!hozzafuz) { this.rajzol(n.map(function (x) { return { t: x.t, u: x.u, k: 'oldal' }; }), '', SZ.nepszeru || 'Népszerű'); return; }
        html = '<p class="s-kt__csoport">' + esc(SZ.nepszeru || 'Népszerű') + '</p>';
        n.forEach(function (x) { html += '<a class="s-kt__elem" href="' + esc(biztonsagosUrl(x.u)) + '"><span class="s-kt__ikon">' + ikonSvg('link') + '</span><span class="s-kt__szoveg"><span class="s-kt__cim">' + esc(x.t) + '</span></span></a>'; });
        this.doboz.insertAdjacentHTML('beforeend', html);
    };
    Lista.prototype.jelol = function (i) {
        var l = this.doboz.querySelectorAll('[role="option"]');
        if (!l.length) { this.akt = -1; return; }
        i = (i + l.length) % l.length;
        for (var k = 0; k < l.length; k++) { l[k].setAttribute('aria-selected', k === i ? 'true' : 'false'); }
        this.akt = i;
        this.input.setAttribute('aria-activedescendant', l[i].id);
        if (l[i].scrollIntoView) { l[i].scrollIntoView({ block: 'nearest' }); }
    };
    Lista.prototype.billentyu = function (e) {
        var l = this.doboz.querySelectorAll('[role="option"]');
        if (e.key === 'ArrowDown') { e.preventDefault(); this.jelol(this.akt + 1); return true; }
        if (e.key === 'ArrowUp') { e.preventDefault(); this.jelol(this.akt - 1); return true; }
        if (e.key === 'Home' && l.length && e.ctrlKey) { e.preventDefault(); this.jelol(0); return true; }
        if (e.key === 'End' && l.length && e.ctrlKey) { e.preventDefault(); this.jelol(l.length - 1); return true; }
        if (e.key === 'Enter' && this.akt >= 0 && l[this.akt]) {
            e.preventDefault();
            this.megnyit(this.akt);
            return true;
        }
        return false;
    };
    Lista.prototype.megnyit = function (i) {
        var e = this.elemek[i];
        if (!e) { return; }
        legutobbi({ t: e.t, u: e.u, k: e.k, s: e.s });
        w.location.href = biztonsagosUrl(e.u);
    };
    Lista.prototype.figyel = function () {
        var self = this, idoz = 0, sorszam = 0;
        this.input.addEventListener('input', function () {
            clearTimeout(idoz);
            var q = self.input.value;
            idoz = setTimeout(function () {
                var sz = ++sorszam;
                if (!q.trim()) { self.nepszeru(false); return; }
                if (self.allapot) { self.allapot.textContent = SZ.tolt || ''; }
                indexTolt().then(function () {
                    if (sz !== sorszam) { return; }
                    if (index && !restMod) { self.rajzol(keres(q), q); return; }
                    if (hajt(q).length < 2) { self.rajzol([], q); return; }
                    restKeres(q).then(function (t) { if (sz === sorszam) { self.rajzol(t, q); } }).catch(function () {
                        if (sz === sorszam) { self.doboz.innerHTML = '<p class="s-kt__ures">' + esc(SZ.hiba || '') + '</p>'; }
                    });
                });
            }, index ? 40 : 120);
        });
        this.input.addEventListener('keydown', function (e) { self.billentyu(e); });
        this.doboz.addEventListener('click', function (e) {
            var a = e.target.closest('[role="option"]');
            if (!a) { return; }
            e.preventDefault();
            self.megnyit(Number(a.getAttribute('data-i')));
        });
        this.doboz.addEventListener('mousemove', function (e) {
            var a = e.target.closest('[role="option"]');
            if (a) { var i = Number(a.getAttribute('data-i')); if (i !== self.akt) { self.jelol(i); } }
        });
    };

    /* ---------- Ablak ---------- */
    var ablak = d.querySelector('[data-kereso]');
    var lista = null, elozoFokusz = null;

    function zar() {
        if (!ablak || ablak.hidden) { return; }
        ablak.hidden = true;
        d.documentElement.classList.remove('s-modal-nyitva');
        if (elozoFokusz && elozoFokusz.focus) { elozoFokusz.focus(); }
    }
    function nyit(kezdo) {
        if (!ablak) { w.location.href = (CFG.kereses || '/') + '?s=' + encodeURIComponent(kezdo || ''); return; }
        var input = ablak.querySelector('#kereso-mezo');
        if (!lista) {
            lista = new Lista(input, ablak.querySelector('#kereso-lista'), ablak.querySelector('[data-kereso-allapot]'));
            lista.figyel();
            ablak.addEventListener('click', function (e) { if (e.target.closest('[data-kereso-zar]')) { zar(); } });
            ablak.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { e.preventDefault(); zar(); return; }
                if (e.key === 'Tab') {
                    var f = Array.prototype.slice.call(ablak.querySelectorAll('input, button, a[href]:not([tabindex="-1"])')).filter(function (x) { return x.offsetParent !== null; });
                    var i = f.indexOf(d.activeElement);
                    if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); } else if (!e.shiftKey && i === f.length - 1) { e.preventDefault(); f[0].focus(); }
                }
            });
        }
        elozoFokusz = d.activeElement;
        ablak.hidden = false;
        d.documentElement.classList.add('s-modal-nyitva');
        input.value = kezdo || '';
        input.focus();
        indexTolt();
        if (kezdo) { input.dispatchEvent(new Event('input')); } else { lista.nepszeru(false); }
    }

    /* ---------- Oldalkereső-szekció ---------- */
    function oldal(urlap) {
        if (urlap.dataset.kesz) { return; }
        urlap.dataset.kesz = '1';
        var input = urlap.querySelector('input[type="search"]');
        var doboz = urlap.parentNode.querySelector('[data-oldalkereso-lista]');
        var all = urlap.parentNode.querySelector('[data-oldalkereso-allapot]');
        if (!input || !doboz) { return; }
        var l = new Lista(input, doboz, all);
        l.figyel();
        indexTolt();
        if (input.value) { input.dispatchEvent(new Event('input')); }
    }

    w.APP_KERESO = { nyit: nyit, zar: zar, oldal: oldal, keres: function (q) { return indexTolt().then(function () { return keres(q); }); }, hajt: hajt, _tav: tav };
})(window, document);
