/**
 * Közös front-segéd (minden front-modul előtt töltődik). Adat: window.APP_KOZOS {rest, nonce, afa, nyelv, szoveg}.
 *
 *   APP.api(ut, adat?, opciok?)   → Promise<json>. ut: '/ar' (a kapott `rest` címhez fűzve) vagy teljes URL;
 *                                   adat nélkül GET, adattal POST JSON; opciok.metodus ('GET'|'POST'|'DELETE'), opciok.query.
 *                                   Hiba: a Promise elutasul egy Error-ral (.uzenet, .status, .kod, .adat, .valasz = a teljes JSON).
 *   APP.kosar                     → böngészőben tárolt kosár (localStorage, tartalék: memória):
 *                                   tetelek(), opciok(), beallit(tetelek, opciok), tesz(tetel), vesz(tipus, kulcs),
 *                                   opcio(kulcs, ertek), urit(), db(), figyel(fn) – a változásra (más fülön is) szól.
 *   APP.uzen(szoveg, tipus?)      → értesítő sáv (tipus: 'ok'|'hiba'|'info'), képernyőolvasónak is (aria-live).
 *   APP.ketlepcsos(gomb, fn)      → kétlépcsős gomb kézzel; a `data-ketlepcsos` attribútumú gombok maguktól azok:
 *                                   1. kattintás „Biztos? Kattints újra.” (4 mp), 2. kattintás `ketlepcsos` eseményt küld.
 *   APP.ft(n)                     → „12 700 Ft”
 *   APP.esc(s)                    → HTML-escapelt szöveg
 *   APP.el(tag, attr, gyerekek)   → DOM-elem (attr.szoveg = textContent, attr.osztaly = className)
 *   APP.urlap(form)               → az űrlap mezői objektumként (több azonos nevű → tömb; checkbox → bool)
 *   APP.vedelem(form, adat)       → a robotszűrés mezői (mézesbödön, időbélyeg, Turnstile) az űrlapba; adat: {turnstile, hp, t}
 *   APP.modulok(nev, fn)          → fn(gyoker, opciok) minden `[data-modul="nev"]` elemre, egyszer (DOM kész után)
 */
(function (w, d) {
    'use strict';

    var K = w.APP_KOZOS || {};
    var SZ = K.szoveg || {};
    var APP = w.APP = w.APP || {};
    var TAR = 'kosar_v1';

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function ft(n) {
        n = Math.round(Number(n) || 0);
        var s = String(Math.abs(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        return (n < 0 ? '−' : '') + s + ' Ft';
    }

    function el(tag, attr, gyerek) {
        var e = d.createElement(tag);
        Object.keys(attr || {}).forEach(function (k) {
            var v = attr[k];
            if (v === null || v === undefined || v === false) { return; }
            if (k === 'szoveg') { e.textContent = v; } else if (k === 'osztaly') { e.className = v; } else if (k.indexOf('on') === 0 && typeof v === 'function') { e.addEventListener(k.slice(2), v); } else { e.setAttribute(k, v === true ? '' : v); }
        });
        (gyerek || []).forEach(function (g) {
            if (g === null || g === undefined || g === false) { return; }
            e.appendChild(typeof g === 'string' || typeof g === 'number' ? d.createTextNode(String(g)) : g);
        });
        return e;
    }

    /* ---------------- API ---------------- */
    function api(ut, adat, opciok) {
        opciok = opciok || {};
        var metodus = opciok.metodus || (adat === undefined || adat === null ? 'GET' : 'POST');
        var url = /^https?:\/\//.test(ut) ? ut : String(K.rest || '').replace(/\/$/, '') + '/' + String(ut).replace(/^\//, '');
        if (opciok.query) {
            var q = Object.keys(opciok.query).filter(function (k) { return opciok.query[k] !== undefined && opciok.query[k] !== null; })
                .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(opciok.query[k]); }).join('&');
            if (q) { url += (url.indexOf('?') < 0 ? '?' : '&') + q; }
        }
        var fej = { 'Accept': 'application/json' };
        var init = { method: metodus, headers: fej, credentials: 'same-origin' };
        if (K.nonce) { fej['X-App-Nonce'] = K.nonce; }
        if (metodus !== 'GET' && adat !== undefined && adat !== null) {
            if (typeof FormData !== 'undefined' && adat instanceof FormData) { init.body = adat; } else { fej['Content-Type'] = 'application/json'; init.body = JSON.stringify(adat); }
        }
        return fetch(url, init).then(function (v) {
            return v.text().then(function (t) {
                var j = null;
                try { j = t ? JSON.parse(t) : null; } catch (e) { j = null; }
                if (!v.ok) {
                    var h = new Error((j && j.message) || SZ.hiba || 'Valami nem sikerült. Próbáld újra.');
                    h.uzenet = h.message; h.status = v.status; h.kod = j && j.code; h.adat = j && j.data; h.valasz = j || {};
                    throw h;
                }
                return j;
            });
        }, function () {
            var h = new Error(SZ.halozat || 'Nincs kapcsolat a szerverrel. Ellenőrizd az internetet, és próbáld újra.');
            h.uzenet = h.message; h.status = 0;
            throw h;
        });
    }

    /* ---------------- Értesítő ---------------- */
    var sav = null;
    function uzen(szoveg, tipus) {
        if (!d.body) { return; }
        if (!sav) {
            sav = el('div', { osztaly: 's-uzenet-sav', role: 'status', 'aria-live': 'polite' });
            d.body.appendChild(sav);
        }
        var u = el('div', { osztaly: 's-uzenet s-uzenet-' + (tipus || 'info') }, [
            el('span', { szoveg: String(szoveg) }),
            el('button', { type: 'button', osztaly: 's-uzenet-zar', 'aria-label': 'Bezárás', szoveg: '×' })
        ]);
        u.lastChild.addEventListener('click', function () { if (u.parentNode) { u.parentNode.removeChild(u); } });
        sav.appendChild(u);
        setTimeout(function () { if (u.parentNode) { u.parentNode.removeChild(u); } }, tipus === 'hiba' ? 9000 : 5000);
    }

    /* ---------------- Kétlépcsős gomb ---------------- */
    function ketlepcsos(gomb, fn) {
        if (gomb.getAttribute('data-ketlepcsos-kesz')) { return; }
        gomb.setAttribute('data-ketlepcsos-kesz', '1');
        var idozito = null, eredeti = null;
        gomb.addEventListener('click', function (e) {
            if (gomb.getAttribute('data-elesitve') !== '1') {
                e.preventDefault();
                e.stopImmediatePropagation();
                eredeti = gomb.textContent;
                gomb.setAttribute('data-elesitve', '1');
                gomb.classList.add('s-gomb-biztos');
                gomb.textContent = gomb.getAttribute('data-biztos') || SZ.biztos || 'Biztos? Kattints újra.';
                idozito = setTimeout(function () {
                    gomb.removeAttribute('data-elesitve');
                    gomb.classList.remove('s-gomb-biztos');
                    if (eredeti !== null) { gomb.textContent = eredeti; }
                }, 4000);
                return;
            }
            clearTimeout(idozito);
            gomb.removeAttribute('data-elesitve');
            gomb.classList.remove('s-gomb-biztos');
            if (eredeti !== null) { gomb.textContent = eredeti; }
            if (gomb.type === 'submit' && !fn) { return; } // az űrlap beküldése megy tovább
            e.preventDefault();
            gomb.dispatchEvent(new CustomEvent('ketlepcsos', { bubbles: true }));
            if (fn) { fn(e); }
        });
    }

    d.addEventListener('click', function (e) {
        var g = e.target && e.target.closest ? e.target.closest('[data-ketlepcsos]') : null;
        if (g && !g.getAttribute('data-ketlepcsos-kesz')) {
            ketlepcsos(g);
            // az első kattintást a most felrakott kezelő nem látta: ismételjük
            g.click();
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    /* ---------------- Kosár ---------------- */
    var mem = null, figyelok = [];
    function olvas() {
        if (mem) { return mem; }
        var a = null;
        try { a = JSON.parse(w.localStorage.getItem(TAR) || 'null'); } catch (e) { a = null; }
        mem = a && Array.isArray(a.tetelek) ? a : { tetelek: [], opciok: { ciklus: 'eves' } };
        if (!mem.opciok || typeof mem.opciok !== 'object') { mem.opciok = { ciklus: 'eves' }; }
        return mem;
    }
    function ir(a) {
        a.ido = Date.now();
        mem = a;
        try { w.localStorage.setItem(TAR, JSON.stringify(a)); } catch (e) { /* privát mód: marad a memóriában */ }
        szol();
    }
    function szol() { figyelok.forEach(function (f) { try { f(kosar); } catch (e) { /* egy figyelő hibája ne állítsa meg a többit */ } }); }
    function tiszta(t) {
        if (!t || typeof t !== 'object') { return null; }
        var tipus = String(t.tipus || ''), kulcs = String(t.kulcs || '');
        if (['csomag', 'modul', 'szolgaltatas'].indexOf(tipus) < 0 || !/^[a-z0-9_-]{1,60}$/i.test(kulcs)) { return null; }
        var o = { tipus: tipus, kulcs: kulcs };
        if (tipus === 'szolgaltatas') { o.db = Math.max(1, Math.min(1000, parseInt(t.db, 10) || 1)); }
        return o;
    }
    var kosar = {
        tetelek: function () { return olvas().tetelek.slice(); },
        opciok: function () { var o = olvas().opciok, k = {}; Object.keys(o).forEach(function (x) { k[x] = o[x]; }); return k; },
        beallit: function (tetelek, opciok) {
            var a = { tetelek: (tetelek || []).map(tiszta).filter(Boolean), opciok: opciok || olvas().opciok };
            ir(a);
        },
        tesz: function (t) {
            t = tiszta(t);
            if (!t) { return; }
            var a = olvas(), l = a.tetelek.filter(function (x) {
                if (t.tipus === 'csomag' && x.tipus === 'csomag') { return false; }
                return !(x.tipus === t.tipus && x.kulcs === t.kulcs);
            });
            l.push(t);
            ir({ tetelek: l, opciok: a.opciok });
        },
        vesz: function (tipus, kulcs) {
            var a = olvas();
            ir({ tetelek: a.tetelek.filter(function (x) { return !(x.tipus === tipus && x.kulcs === kulcs); }), opciok: a.opciok });
        },
        opcio: function (k, v) {
            var a = olvas(), o = kosar.opciok();
            if (v === null || v === undefined || v === '') { delete o[k]; } else { o[k] = v; }
            ir({ tetelek: a.tetelek, opciok: o });
        },
        urit: function () { ir({ tetelek: [], opciok: { ciklus: olvas().opciok.ciklus || 'eves' } }); },
        db: function () { return olvas().tetelek.length; },
        figyel: function (fn) { if (typeof fn === 'function') { figyelok.push(fn); } }
    };
    w.addEventListener('storage', function (e) { if (e.key === TAR) { mem = null; szol(); } });

    /* ---------------- Űrlap ---------------- */
    function urlap(f) {
        var o = {};
        Array.prototype.forEach.call(f.elements, function (m) {
            if (!m.name || m.disabled || m.type === 'submit' || m.type === 'button' || m.type === 'file') { return; }
            var v;
            if (m.type === 'checkbox') {
                if (f.querySelectorAll('[name="' + m.name + '"]').length > 1) {
                    if (!Array.isArray(o[m.name])) { o[m.name] = []; }
                    if (m.checked) { o[m.name].push(m.value); }
                    return;
                }
                v = m.checked;
            } else if (m.type === 'radio') {
                if (!m.checked) { if (!(m.name in o)) { o[m.name] = ''; } return; }
                v = m.value;
            } else if (m.tagName === 'SELECT' && m.multiple) {
                v = Array.prototype.filter.call(m.options, function (x) { return x.selected; }).map(function (x) { return x.value; });
            } else { v = m.value; }
            o[m.name] = v;
        });
        return o;
    }

    function vedelem(f, adat) {
        adat = adat || {};
        if (f.getAttribute('data-vedelem')) { return; }
        f.setAttribute('data-vedelem', '1');
        if (adat.hp) {
            var hp = el('div', { osztaly: 's-rejtett', 'aria-hidden': 'true', style: 'position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden' }, [
                el('label', { szoveg: 'Ezt hagyd üresen' }, [el('input', { type: 'text', name: adat.hp, tabindex: '-1', autocomplete: 'off' })])
            ]);
            f.appendChild(hp);
        }
        if (adat.t) { f.appendChild(el('input', { type: 'hidden', name: '_t', value: adat.t })); }
        if (adat.turnstile) {
            var doboz = el('div', { osztaly: 'cf-turnstile', 'data-sitekey': adat.turnstile, 'data-response-field-name': 'turnstile' });
            var gomb = f.querySelector('[type="submit"]');
            f.insertBefore(doboz, gomb ? gomb.parentNode === f ? gomb : gomb.parentNode : null);
            if (!d.querySelector('script[data-turnstile]')) {
                var s = d.createElement('script');
                s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
                s.async = true; s.defer = true; s.setAttribute('data-turnstile', '1');
                var n = d.querySelector('script[nonce]');
                if (n && n.nonce) { s.nonce = n.nonce; }
                d.head.appendChild(s);
            }
        }
    }

    function modulok(nev, fn) {
        function fut() {
            Array.prototype.forEach.call(d.querySelectorAll('[data-modul="' + nev + '"]'), function (g) {
                if (g.getAttribute('data-kesz')) { return; }
                g.setAttribute('data-kesz', '1');
                var o = {};
                try { o = JSON.parse(g.getAttribute('data-opciok') || '{}') || {}; } catch (e) { o = {}; }
                fn(g, o);
            });
        }
        if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', fut); } else { fut(); }
    }

    APP.api = api;
    APP.uzen = uzen;
    APP.ketlepcsos = ketlepcsos;
    APP.kosar = kosar;
    APP.ft = ft;
    APP.esc = esc;
    APP.el = el;
    APP.urlap = urlap;
    APP.vedelem = vedelem;
    APP.modulok = modulok;
    APP.afa = parseInt(K.afa, 10) || 27;
}(window, document));
