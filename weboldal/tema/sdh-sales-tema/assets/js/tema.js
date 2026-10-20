/*
 * Téma – közös front-JS (vanilla, defer, jQuery nélkül). Adat: window.APP_TEMA (szövegek, a kereső és a naptár JS címe,
 * a kereső-index címe, REST-cím – mind a szervertől, semmi nincs beégetve).
 *
 *  - sötét / világos mód váltó (localStorage „szinmod”, <html data-tema>; a festés előtti beállítást a fejben lévő
 *    apró inline szkript végzi)
 *  - főmenü: hozzáférhető legördülők (gomb + aria-expanded, Esc, kattintás kívül), mobil fiókmenü fókuszcsapdával
 *  - ragadós fejléc árnyéka görgetéskor
 *  - kereső (Ctrl+K, „/”, gomb) – a kereso.js csak az első megnyitáskor töltődik
 *  - dátumválasztó – a datum.js csak akkor töltődik, ha van [data-datum] mező az oldalon
 *  - kétlépcsős gomb ([data-ketlepcsos]) tartalék, ha a közös modul (window.APP.ketlepcsos) nincs jelen
 *  - értesítő (window.APP.uzen tartalék), videó kattintásra, árak ciklusváltó, GYIK- / kártyaszűrő,
 *    ellenőrzőlista (localStorage), tartalomjegyzék kiemelés, nyomtatás gomb
 */
(function (w, d) {
    'use strict';

    var CFG = w.APP_TEMA || {};
    var SZ = CFG.szoveg || {};
    var gyoker = d.documentElement;

    function $(s, c) { return (c || d).querySelector(s); }
    function $$(s, c) { return Array.prototype.slice.call((c || d).querySelectorAll(s)); }
    function tarol(k, v) { try { if (v === undefined) { return w.localStorage.getItem(k); } if (v === null) { w.localStorage.removeItem(k); } else { w.localStorage.setItem(k, v); } } catch (e) { /* privát mód */ } return null; }
    function szkript(src) {
        return new Promise(function (ok, hiba) {
            if (!src) { hiba(new Error('nincs src')); return; }
            var meglevo = $('script[data-lusta="' + src + '"]');
            if (meglevo) { if (meglevo.dataset.kesz) { ok(); } else { meglevo.addEventListener('load', function () { ok(); }); } return; }
            var s = d.createElement('script');
            s.src = src; s.defer = true; s.setAttribute('data-lusta', src);
            s.onload = function () { s.dataset.kesz = '1'; ok(); };
            s.onerror = hiba;
            d.body.appendChild(s);
        });
    }
    var FOKUSZALHATO = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    /* ---------- Sötét / világos mód ---------- */
    function aktualisMod() {
        var t = gyoker.getAttribute('data-tema');
        if (t === 'sotet' || t === 'vilagos') { return t; }
        return w.matchMedia && w.matchMedia('(prefers-color-scheme: light)').matches ? 'vilagos' : 'sotet';
    }
    function valtoFrissit() {
        var mod = aktualisMod();
        $$('[data-temavalto]').forEach(function (g) {
            g.setAttribute('aria-pressed', mod === 'vilagos' ? 'true' : 'false');
            var f = $('[data-temavalto-felirat]', g);
            if (f) { f.textContent = mod === 'vilagos' ? (SZ.sotet || 'Sötét mód') : (SZ.vilagos || 'Világos mód'); }
        });
        var meta = $$('meta[name="theme-color"]');
        meta.forEach(function (m) { m.removeAttribute('media'); m.setAttribute('content', mod === 'vilagos' ? '#f4f5f9' : '#07080c'); });
    }
    d.addEventListener('click', function (e) {
        var g = e.target.closest('[data-temavalto]');
        if (!g) { return; }
        var uj = aktualisMod() === 'vilagos' ? 'sotet' : 'vilagos';
        gyoker.setAttribute('data-tema', uj);
        tarol('szinmod', uj);
        valtoFrissit();
    });
    if (w.matchMedia) {
        var mq = w.matchMedia('(prefers-color-scheme: light)');
        var figyel = function () { if (!tarol('szinmod')) { valtoFrissit(); } };
        if (mq.addEventListener) { mq.addEventListener('change', figyel); } else if (mq.addListener) { mq.addListener(figyel); }
    }

    /* ---------- Főmenü: legördülők ---------- */
    function almenuZar(kiveve) {
        $$('.s-fomenu__nyito[aria-expanded="true"]').forEach(function (b) {
            if (b === kiveve) { return; }
            b.setAttribute('aria-expanded', 'false');
            var a = d.getElementById(b.getAttribute('aria-controls'));
            if (a) { a.hidden = true; }
        });
    }
    d.addEventListener('click', function (e) {
        var b = e.target.closest('.s-fomenu__nyito');
        if (b) {
            var nyitva = b.getAttribute('aria-expanded') === 'true';
            almenuZar(b);
            b.setAttribute('aria-expanded', nyitva ? 'false' : 'true');
            var a = d.getElementById(b.getAttribute('aria-controls'));
            if (a) { a.hidden = nyitva; }
            return;
        }
        if (!e.target.closest('.s-almenu')) { almenuZar(null); }
    });
    d.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        var nyitott = $('.s-fomenu__nyito[aria-expanded="true"]');
        if (nyitott) { almenuZar(null); nyitott.focus(); }
    });
    // Fókusz elhagyja a legördülőt → zár.
    d.addEventListener('focusin', function (e) {
        var elem = e.target.closest('.s-fomenu__elem--szulo');
        $$('.s-fomenu__elem--szulo').forEach(function (sz) {
            if (sz !== elem) {
                var b = $('.s-fomenu__nyito', sz);
                if (b && b.getAttribute('aria-expanded') === 'true' && !menuNyitva()) { b.setAttribute('aria-expanded', 'false'); var a = d.getElementById(b.getAttribute('aria-controls')); if (a) { a.hidden = true; } }
            }
        });
    });
    // Nyíl le a nyitógombon: az első almenüpontra.
    d.addEventListener('keydown', function (e) {
        var b = e.target.closest && e.target.closest('.s-fomenu__nyito');
        if (!b || e.key !== 'ArrowDown') { return; }
        e.preventDefault();
        b.setAttribute('aria-expanded', 'true');
        var a = d.getElementById(b.getAttribute('aria-controls'));
        if (a) { a.hidden = false; var l = $('a', a); if (l) { l.focus(); } }
    });

    /* ---------- Mobil fiókmenü ---------- */
    var menu = $('[data-fomenu]');
    var menugomb = $('[data-menugomb]');
    function menuNyitva() { return !!(menu && menu.classList.contains('s-nyitva')); }
    function menuAllit(nyit) {
        if (!menu || !menugomb) { return; }
        menu.classList.toggle('s-nyitva', nyit);
        menugomb.setAttribute('aria-expanded', nyit ? 'true' : 'false');
        gyoker.classList.toggle('s-menu-nyitva', nyit);
        var f = $('[data-menugomb-felirat]', menugomb);
        if (f) { f.textContent = nyit ? (SZ.menu_zar || 'Menü bezárása') : (SZ.menu_nyit || 'Menü megnyitása'); }
        if (nyit) { var elso = $(FOKUSZALHATO, menu); if (elso) { elso.focus(); } } else { menugomb.focus(); }
    }
    if (menugomb) {
        menugomb.addEventListener('click', function () { menuAllit(!menuNyitva()); });
        d.addEventListener('keydown', function (e) {
            if (!menuNyitva()) { return; }
            if (e.key === 'Escape') { menuAllit(false); return; }
            if (e.key !== 'Tab') { return; }
            // Fókuszcsapda: a menü + a menügomb.
            var l = $$(FOKUSZALHATO, menu).filter(function (x) { return x.offsetParent !== null; });
            l.push(menugomb);
            var i = l.indexOf(d.activeElement);
            if (e.shiftKey && (i <= 0)) { e.preventDefault(); l[l.length - 1].focus(); } else if (!e.shiftKey && i === l.length - 1) { e.preventDefault(); l[0].focus(); }
        });
        w.addEventListener('resize', function () { if (w.innerWidth >= 1100 && menuNyitva()) { menuAllit(false); } });
        if (menu) { menu.addEventListener('click', function (e) { if (e.target.closest('a[href]') && menuNyitva()) { menu.classList.remove('s-nyitva'); gyoker.classList.remove('s-menu-nyitva'); menugomb.setAttribute('aria-expanded', 'false'); } }); }
    }

    /* ---------- Fejléc görgetéskor ---------- */
    var fejlec = $('[data-fejlec]');
    if (fejlec) {
        var jel = d.createElement('div');
        jel.setAttribute('aria-hidden', 'true');
        jel.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:8px;pointer-events:none';
        d.body.insertBefore(jel, d.body.firstChild);
        if ('IntersectionObserver' in w) {
            new IntersectionObserver(function (b) { fejlec.classList.toggle('s-gorgetve', !b[0].isIntersecting); }).observe(jel);
        }
    }

    /* ---------- Kereső (lusta betöltés) ---------- */
    var keresoBetoltve = null;
    function keresoNyit(kezdo) {
        if (!keresoBetoltve) {
            keresoBetoltve = (w.APP_KERESO ? Promise.resolve() : szkript(CFG.kereso_js)).catch(function () { keresoBetoltve = null; });
        }
        keresoBetoltve.then(function () {
            if (w.APP_KERESO && w.APP_KERESO.nyit) { w.APP_KERESO.nyit(kezdo || ''); } else { w.location.href = (CFG.kereses || '/') + '?s=' + encodeURIComponent(kezdo || ''); }
        });
    }
    d.addEventListener('click', function (e) {
        if (e.target.closest('[data-kereso-nyit]')) { e.preventDefault(); keresoNyit(''); }
    });
    d.addEventListener('keydown', function (e) {
        var cel = e.target;
        var irhato = cel && (cel.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(cel.tagName));
        if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); keresoNyit(''); return; }
        if (e.key === '/' && !irhato && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); keresoNyit(''); }
    });
    // Az oldalkereső-szekció is a kereso.js-t használja (első fókuszra töltődik).
    $$('[data-oldalkereso]').forEach(function (f) {
        var inp = $('input[type="search"]', f);
        if (!inp) { return; }
        var be = function () {
            inp.removeEventListener('focus', be);
            (w.APP_KERESO ? Promise.resolve() : szkript(CFG.kereso_js)).then(function () { if (w.APP_KERESO && w.APP_KERESO.oldal) { w.APP_KERESO.oldal(f); } }).catch(function () {});
        };
        inp.addEventListener('focus', be);
    });

    /* ---------- Dátumválasztó (lusta) ---------- */
    function datumIndit(gyok) {
        var mezok = $$('[data-datum]:not([data-datum-kesz])', gyok || d);
        if (!mezok.length) { return; }
        (w.APP_DATUM ? Promise.resolve() : szkript(CFG.datum_js)).then(function () {
            if (w.APP_DATUM) { mezok.forEach(function (m) { w.APP_DATUM.csatol(m); }); }
        }).catch(function () {});
    }
    datumIndit();
    // A bővítmény JS-e később rajzol mezőket → figyeljük az új [data-datum] elemeket.
    if ('MutationObserver' in w) {
        var mo = new MutationObserver(function (l) {
            for (var i = 0; i < l.length; i++) {
                for (var j = 0; j < l[i].addedNodes.length; j++) {
                    var n = l[i].addedNodes[j];
                    if (n.nodeType === 1 && (n.hasAttribute('data-datum') || n.querySelector('[data-datum]'))) { datumIndit(n.parentNode || d); return; }
                }
            }
        });
        $$('[data-modul]').forEach(function (m) { mo.observe(m, { childList: true, subtree: true }); });
    }

    /* ---------- Értesítő (tartalék, ha a közös modul nincs jelen) ---------- */
    function uzen(szoveg, tipus, ido) {
        var hely = $('[data-ertesitok]');
        if (!hely) { return; }
        var e = d.createElement('div');
        e.className = 's-ertesito' + (tipus ? ' s-ertesito--' + tipus : '');
        e.setAttribute('role', tipus === 'hiba' ? 'alert' : 'status');
        var p = d.createElement('p');
        p.className = 's-ertesito__szoveg';
        p.textContent = String(szoveg);
        var z = d.createElement('button');
        z.type = 'button'; z.className = 's-ertesito__zar'; z.setAttribute('aria-label', SZ.bezar || 'Bezárás'); z.textContent = '×';
        var torol = function () { e.classList.add('s-ki'); setTimeout(function () { if (e.parentNode) { e.parentNode.removeChild(e); } }, 250); };
        z.addEventListener('click', torol);
        e.appendChild(p); e.appendChild(z);
        hely.appendChild(e);
        setTimeout(torol, ido || (tipus === 'hiba' ? 8000 : 4500));
    }
    w.APP = w.APP || {};
    if (!w.APP.uzen) { w.APP.uzen = uzen; }

    /* ---------- Kétlépcsős gomb (tartalék) ---------- */
    d.addEventListener('click', function (e) {
        var g = e.target.closest('[data-ketlepcsos]');
        if (!g || (w.APP && w.APP.ketlepcsos_kezeli)) { return; }
        if (g.dataset.elesitve === '1') {
            g.dataset.elesitve = '';
            g.classList.remove('s-biztos');
            if (g.dataset.eredeti) { g.textContent = g.dataset.eredeti; }
            clearTimeout(Number(g.dataset.idozito || 0));
            g.dispatchEvent(new CustomEvent('ketlepcsos', { bubbles: true }));
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        g.dataset.elesitve = '1';
        g.dataset.eredeti = g.textContent;
        g.textContent = g.getAttribute('data-ketlepcsos') || SZ.biztos || 'Biztos? Kattints újra.';
        g.classList.add('s-biztos');
        g.dataset.idozito = String(setTimeout(function () {
            g.dataset.elesitve = '';
            g.classList.remove('s-biztos');
            g.textContent = g.dataset.eredeti;
        }, 4000));
    }, true);

    /* ---------- Videó: kattintásra (előtte nincs harmadik féltől jövő kérés) ---------- */
    d.addEventListener('click', function (e) {
        var g = e.target.closest('.s-video__gomb');
        if (!g) { return; }
        var v = g.closest('[data-video]');
        if (!v) { return; }
        var src = v.getAttribute('data-src');
        if (!/^https:\/\//.test(src || '') && !/^\//.test(src || '')) { return; }
        var el;
        if (v.getAttribute('data-video') === 'video') {
            el = d.createElement('video');
            el.src = src; el.controls = true; el.autoplay = true; el.playsInline = true;
        } else {
            el = d.createElement('iframe');
            el.src = src;
            el.title = v.getAttribute('data-cim') || '';
            el.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
            el.setAttribute('allowfullscreen', '');
            el.loading = 'eager';
            el.referrerPolicy = 'strict-origin-when-cross-origin';
        }
        v.textContent = '';
        v.appendChild(el);
        el.focus();
    });

    /* ---------- Árak: ciklusváltó (a CSS :has mellett a régebbi böngészőknek) + a kosár-linkbe a ciklus ---------- */
    $$('[data-ciklus-valto]').forEach(function (fs) {
        var doboz = fs.closest('.s-arak__doboz');
        var frissit = function () {
            var r = $('input:checked', fs);
            if (!r || !doboz) { return; }
            doboz.setAttribute('data-ciklus', r.value);
            $$('[data-ciklus-link]', doboz).forEach(function (a) {
                try { var u = new URL(a.href, w.location.href); u.searchParams.set('ciklus', r.value); a.href = u.toString(); } catch (e) { /* régi böngésző */ }
            });
        };
        fs.addEventListener('change', frissit);
        frissit();
    });

    /* ---------- GYIK- és kártyaszűrő ---------- */
    function hajt(s) { return String(s || '').toLowerCase().normalize ? String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '') : String(s || '').toLowerCase(); }
    $$('[data-gyik-szuro], [data-kartya-szuro]').forEach(function (inp) {
        var sz = inp.closest('.s-szekcio') || d;
        var gyik = inp.hasAttribute('data-gyik-szuro');
        var elemek = gyik ? $$('.s-harmonika', sz) : $$('.s-kartya', sz);
        var szamlalo = $('[data-gyik-szamlalo]', sz);
        var idoz = 0;
        inp.addEventListener('input', function () {
            clearTimeout(idoz);
            idoz = setTimeout(function () {
                var q = hajt(inp.value.trim());
                var db = 0;
                elemek.forEach(function (el) {
                    var jo = !q || hajt(el.textContent).indexOf(q) !== -1;
                    el.hidden = !jo;
                    if (jo) { db++; }
                    if (gyik && q && jo && q.length > 2) { el.open = true; }
                });
                $$('.s-csoportcim', sz).forEach(function (h) {
                    var kov = h.nextElementSibling;
                    if (kov) { var lat = $$(gyik ? '.s-harmonika' : '.s-kartya', kov).some(function (x) { return !x.hidden; }); h.hidden = !lat; kov.hidden = !lat; }
                });
                if (szamlalo) { szamlalo.textContent = (CFG.kereso_szoveg && CFG.kereso_szoveg.talalat ? CFG.kereso_szoveg.talalat : '%d').replace('%d', db); }
            }, 120);
        });
    });
    // GYIK: horgonyra érkezve a kérdés nyitva.
    function horgonyNyit() {
        if (!w.location.hash) { return; }
        var id = decodeURIComponent(w.location.hash.slice(1));
        var el = id ? d.getElementById(id) : null;
        if (el && el.tagName === 'DETAILS') { el.open = true; }
    }
    horgonyNyit();
    w.addEventListener('hashchange', horgonyNyit);

    /* ---------- Ellenőrzőlista (csak a böngészőben marad) ---------- */
    $$('[data-ellenorzo]').forEach(function (fs) {
        var k = 'ell-' + fs.getAttribute('data-ellenorzo');
        var allapot = {};
        try { allapot = JSON.parse(tarol(k) || '{}') || {}; } catch (e) { allapot = {}; }
        $$('input[type="checkbox"]', fs).forEach(function (c) {
            c.checked = !!allapot[c.value];
            c.addEventListener('change', function () { allapot[c.value] = c.checked; tarol(k, JSON.stringify(allapot)); });
        });
    });

    /* ---------- Tartalomjegyzék: az aktuális rész kiemelése ---------- */
    var tjLinkek = $$('.s-tj__lista a');
    if (tjLinkek.length && 'IntersectionObserver' in w) {
        var cimek = tjLinkek.map(function (a) { return d.getElementById(decodeURIComponent(a.getAttribute('href').slice(1))); }).filter(Boolean);
        var lathato = {};
        var io = new IntersectionObserver(function (b) {
            b.forEach(function (x) { lathato[x.target.id] = x.isIntersecting; });
            var akt = null;
            for (var i = 0; i < cimek.length; i++) { if (lathato[cimek[i].id]) { akt = cimek[i].id; break; } }
            if (!akt) { return; }
            tjLinkek.forEach(function (a) { if (a.getAttribute('href') === '#' + akt) { a.setAttribute('aria-current', 'true'); } else { a.removeAttribute('aria-current'); } });
        }, { rootMargin: '-80px 0px -60% 0px' });
        cimek.forEach(function (c) { io.observe(c); });
    }
    // A TJ csukva érkezik (keskeny képernyőn a tartalom fölött áll – nyitva-csukás után ugrana, CLS); ahol oldalsávban,
    // a tartalom mellett áll (kétoszlopos rács), ott a kinyitása semmit nem tol el, ezért kinyitjuk.
    $$('.s-tj__doboz:not([open])').forEach(function (x) {
        var racs = x.closest('.s-cikk__racs, .s-tudas__racs, .s-jogi__racs');
        if (racs && getComputedStyle(racs).gridTemplateColumns.trim().split(/\s+/).length > 1) { x.open = true; }
    });

    /* ---------- Nyomtatás ---------- */
    d.addEventListener('click', function (e) { if (e.target.closest('[data-nyomtat]')) { w.print(); } });

    valtoFrissit();
})(window, document);
