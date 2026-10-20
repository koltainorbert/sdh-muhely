/**
 * Egyedi fejlesztés – többlépéses igényfelmérő. DOM: <div data-modul="egyedi"></div>.
 * Adat: window.APP_EGYEDI {lepesek[{cim, mezok[]}], vedelem, max_fajl, max_mb, tipusok[], adatvedelem}.
 * Beküldés: POST /egyedi (multipart, a fájlokkal) → összefoglaló + becsült sáv (BECSLÉS felirattal).
 */
(function (w, d) {
    'use strict';
    var APP = w.APP, D = w.APP_EGYEDI || {};
    if (!APP || !APP.modulok) { return; }
    var el = APP.el, ft = APP.ft;

    APP.modulok('egyedi', function (gy) {
        var lepesek = D.lepesek || [], akt = 0, azon = 'egyedi-' + Math.random().toString(36).slice(2, 7);
        var f = el('form', { osztaly: 's-egyedi', novalidate: '' });
        var jelzo = el('ol', { osztaly: 's-lepesjelzo', 'aria-label': 'Lépések' });
        gy.textContent = '';
        gy.appendChild(jelzo);
        gy.appendChild(f);
        var panelek = [];

        lepesek.forEach(function (l, i) {
            jelzo.appendChild(el('li', { 'data-lepes': String(i), szoveg: l.cim }));
            var p = el('fieldset', { osztaly: 's-egyedi-lepes', 'data-lepes': String(i) }, [el('legend', { szoveg: (i + 1) + '. ' + l.cim })]);
            l.mezok.forEach(function (m) {
                var id = azon + '-' + m.nev, sor = el('div', { osztaly: 's-mezo-sor', 'data-mezo': m.nev });
                if (m.tipus === 'radio' || m.tipus === 'checkbox') {
                    var cs = el('fieldset', { osztaly: 's-valasztok' }, [el('legend', { szoveg: m.cimke + (m.kotelezo ? ' *' : '') })]);
                    (m.opciok || []).forEach(function (o, j) {
                        var oid = id + '-' + j;
                        cs.appendChild(el('label', { osztaly: 's-pill', for: oid }, [el('input', { type: m.tipus, name: m.nev, value: o, id: oid }), ' ' + o]));
                    });
                    sor.appendChild(cs);
                } else if (m.tipus === 'kapcsolo') {
                    sor.classList.add('s-kapcsolo-sor');
                    sor.appendChild(el('input', { type: 'checkbox', role: 'switch', osztaly: 's-kapcsolo', id: id, name: m.nev }));
                    sor.appendChild(el('label', { for: id, szoveg: m.cimke }));
                } else if (m.tipus === 'file') {
                    sor.appendChild(el('label', { for: id, szoveg: m.cimke }));
                    sor.appendChild(el('input', { type: 'file', id: id, name: 'fajlok[]', multiple: true, osztaly: 's-mezo', accept: (D.tipusok || []).map(function (t) { return '.' + t; }).join(',') }));
                    sor.appendChild(el('p', { osztaly: 's-halvany', szoveg: 'Legfeljebb ' + D.max_fajl + ' fájl, egyenként ' + D.max_mb + ' MB: ' + (D.tipusok || []).join(', ') + '.' }));
                } else {
                    sor.appendChild(el('label', { for: id, szoveg: m.cimke + (m.kotelezo ? ' *' : '') }));
                    var a = { id: id, name: m.nev, osztaly: 's-mezo' };
                    if (m.max) { a.maxlength = String(m.max); }
                    if (m.tipus === 'textarea') { a.rows = '6'; sor.appendChild(el('textarea', a)); } else {
                        a.type = m.tipus;
                        if (m.tipus === 'number') { a.min = '1'; a.inputmode = 'numeric'; a.value = '1'; }
                        var mez = el('input', a);
                        sor.appendChild(m.tipus === 'number' && APP.lepteto ? APP.lepteto(mez, m.cimke, 'input') : mez);
                    }
                }
                sor.appendChild(el('p', { osztaly: 's-mezo-hiba', id: id + '-hiba', 'aria-live': 'polite' }));
                p.appendChild(sor);
            });
            if (i === lepesek.length - 1) {
                var ak = azon + '-adatkezeles';
                p.appendChild(el('div', { osztaly: 's-mezo-sor s-pipa', 'data-mezo': 'adatkezeles' }, [
                    el('input', { type: 'checkbox', id: ak, name: 'adatkezeles' }),
                    el('label', { for: ak }, ['Elolvastam az ', el('a', { href: D.adatvedelem || '#', target: '_blank', rel: 'noopener', szoveg: 'adatkezelési tájékoztatót' }), '.']),
                    el('p', { osztaly: 's-mezo-hiba', id: ak + '-hiba' })
                ]));
            }
            panelek.push(p);
            f.appendChild(p);
        });

        var vissza = el('button', { type: 'button', osztaly: 's-gomb', szoveg: 'Vissza' });
        var tovabb = el('button', { type: 'button', osztaly: 's-gomb s-gomb-fo', szoveg: 'Tovább' });
        var kuld = el('button', { type: 'submit', osztaly: 's-gomb s-gomb-fo', szoveg: 'Igényfelmérés elküldése' });
        f.appendChild(el('div', { osztaly: 's-gombsor' }, [vissza, tovabb, kuld]));
        APP.vedelem(f, D.vedelem);

        function mutat(i) {
            akt = i;
            panelek.forEach(function (p, j) { p.hidden = j !== i; });
            Array.prototype.forEach.call(jelzo.children, function (li, j) { if (j === i) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); } });
            vissza.hidden = i === 0;
            tovabb.hidden = i === panelek.length - 1;
            kuld.hidden = i !== panelek.length - 1;
            var m = panelek[i].querySelector('input,textarea,select');
            if (m && i > 0) { m.focus(); }
        }

        function hibat(n, s) {
            var sor = f.querySelector('[data-mezo="' + n + '"]');
            if (!sor) { return; }
            var p = sor.querySelector('.s-mezo-hiba');
            p.textContent = s;
            // a mező kerete is jelez (a szöveg mellett), a képernyőolvasó a hibaszöveget is felolvassa
            Array.prototype.forEach.call(sor.querySelectorAll('input:not([type=hidden]), textarea, select'), function (m) {
                if (s) { m.setAttribute('aria-invalid', 'true'); m.setAttribute('aria-describedby', p.id); } else { m.removeAttribute('aria-invalid'); }
            });
        }

        function lepesJo(i) {
            var jo = true;
            lepesek[i].mezok.forEach(function (m) {
                var sor = f.querySelector('[data-mezo="' + m.nev + '"]');
                hibat(m.nev, '');
                if (!m.kotelezo) { return; }
                var ok;
                if (m.tipus === 'radio' || m.tipus === 'checkbox') { ok = !!sor.querySelector('input:checked'); }
                else { var x = sor.querySelector('input,textarea'); ok = x && x.value.trim() !== '' && (m.tipus !== 'email' || /.+@.+\..+/.test(x.value)); }
                if (!ok) { hibat(m.nev, 'Ezt töltsd ki.'); jo = false; }
            });
            return jo;
        }

        vissza.addEventListener('click', function () { mutat(Math.max(0, akt - 1)); });
        tovabb.addEventListener('click', function () { if (lepesJo(akt)) { mutat(akt + 1); } });

        f.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!lepesJo(akt)) { return; }
            var fd = new FormData(f);
            var fajl = f.querySelector('input[type="file"]');
            if (fajl && fajl.files.length > (D.max_fajl || 5)) { hibat('fajlok', 'Legfeljebb ' + D.max_fajl + ' fájlt csatolhatsz.'); return; }
            ['folyamatok', 'integraciok'].forEach(function (n) {
                var l = fd.getAll(n); fd.delete(n);
                l.forEach(function (x) { fd.append(n + '[]', x); });
            });
            var k = f.querySelector('.s-kapcsolo');
            if (k) { fd.set(k.name, k.checked ? '1' : '0'); }
            kuld.disabled = true;
            APP.api('/egyedi', fd).then(function (v) {
                gy.textContent = '';
                var b = v.vazlat || {};
                gy.appendChild(el('div', { osztaly: 's-egyedi-kesz', role: 'status' }, [
                    el('h2', { szoveg: 'Köszönjük! Azonosító: ' + v.azon }),
                    el('p', { szoveg: v.uzenet }),
                    el('ul', {}, (b.osszefoglalo || []).map(function (s) { return el('li', { szoveg: s }); })),
                    el('p', { osztaly: 's-becsles' }, [el('strong', { szoveg: b.cimke || 'BECSLÉS' }), el('br'), 'Munkaigény: ' + b.ora_min + '–' + b.ora_max + ' óra, kb. ' + ft(b.ar_min) + ' – ' + ft(b.ar_max) + ' nettó + ' + APP.afa + '% áfa (bruttó ' + ft(Math.round(b.ar_min * (100 + APP.afa) / 100)) + ' – ' + ft(Math.round(b.ar_max * (100 + APP.afa) / 100)) + '), ' + b.het_min + '–' + b.het_max + ' hét.']),
                    (v.hibak || []).length ? el('p', { osztaly: 's-uzenet s-uzenet-figyel', szoveg: v.hibak.join(' ') }) : null
                ]));
            }, function (h) {
                kuld.disabled = false;
                var hb = (h.valasz || {}).hibak || {};
                var elso = null;
                Object.keys(hb).forEach(function (n) {
                    hibat(n, hb[n]);
                    panelek.forEach(function (p, i) { if (elso === null && p.querySelector('[data-mezo="' + n + '"]')) { elso = i; } });
                });
                if (elso !== null) { mutat(elso); }
                APP.uzen(h.uzenet, 'hiba');
            });
        });

        mutat(0);
    });
}(window, document));
