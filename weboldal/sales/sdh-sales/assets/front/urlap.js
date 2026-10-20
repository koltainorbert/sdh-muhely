/**
 * Kapcsolat / Enterprise-ajánlat / partner űrlap. DOM: <div data-modul="urlap" data-opciok='{"tipus":"kapcsolat"}'></div>
 * Adat: window.APP_URLAP {mezok{tipus: [...]}, vedelem, adatvedelem}. Beküldés: POST /lead.
 */
(function (w, d) {
    'use strict';
    var APP = w.APP, D = w.APP_URLAP || {};
    if (!APP || !APP.modulok) { return; }
    var el = APP.el;

    APP.modulok('urlap', function (gy, o) {
        // Az űrlap fajtája: data-opciok.tipus, vagy a csatolási ponté (data-opciok.urlap / data-urlap).
        var kert = o.tipus || o.urlap || gy.getAttribute('data-urlap') || '';
        var tipus = (D.mezok || {})[kert] ? kert : 'kapcsolat';
        var azon = 'urlap-' + tipus + '-' + Math.random().toString(36).slice(2, 7);
        var f = el('form', { osztaly: 's-urlap', novalidate: '' });
        gy.textContent = '';
        gy.appendChild(f);

        (D.mezok[tipus] || []).forEach(function (m) {
            var id = azon + '-' + m.nev, mez;
            var a = { id: id, name: m.nev, osztaly: 's-mezo' };
            if (m.kotelezo) { a.required = true; a['aria-required'] = 'true'; }
            if (m.max) { a.maxlength = String(m.max); }
            if (m.tipus === 'textarea') { a.rows = '5'; mez = el('textarea', a); }
            else if (m.tipus === 'select') {
                mez = el('select', a, [el('option', { value: '', szoveg: 'Válassz…' })].concat((m.opciok || []).map(function (x) { return el('option', { value: x, szoveg: x }); })));
            } else {
                a.type = m.tipus === 'number' ? 'number' : m.tipus;
                if (m.tipus === 'email') { a.autocomplete = 'email'; }
                if (m.tipus === 'tel') { a.autocomplete = 'tel'; }
                if (m.nev === 'nev') { a.autocomplete = 'name'; }
                if (m.nev === 'ceg') { a.autocomplete = 'organization'; }
                if (m.tipus === 'number') { a.min = '1'; a.inputmode = 'numeric'; }
                mez = el('input', a);
            }
            f.appendChild(el('div', { osztaly: 's-mezo-sor', 'data-mezo': m.nev }, [el('label', { for: id, szoveg: m.cimke + (m.kotelezo ? ' *' : '') }), mez, el('p', { osztaly: 's-mezo-hiba', id: id + '-hiba', 'aria-live': 'polite' })]));
        });

        var ak = azon + '-adatkezeles';
        f.appendChild(el('div', { osztaly: 's-mezo-sor s-pipa', 'data-mezo': 'adatkezeles' }, [
            el('input', { type: 'checkbox', id: ak, name: 'adatkezeles' }),
            el('label', { for: ak }, ['Elolvastam az ', el('a', { href: D.adatvedelem || '#', target: '_blank', rel: 'noopener', szoveg: 'adatkezelési tájékoztatót' }), '.']),
            el('p', { osztaly: 's-mezo-hiba', id: ak + '-hiba' })
        ]));
        var gomb = el('button', { type: 'submit', osztaly: 's-gomb s-gomb-fo', szoveg: tipus === 'ajanlat' ? 'Ajánlatot kérek' : tipus === 'partner' ? 'Jelentkezem' : 'Elküldöm' });
        f.appendChild(gomb);
        APP.vedelem(f, D.vedelem);

        f.addEventListener('submit', function (e) {
            e.preventDefault();
            var u = APP.urlap(f);
            u.tipus = tipus;
            u.oldal = w.location.href;
            gomb.disabled = true;
            APP.api('/lead', u).then(function (v) {
                f.textContent = '';
                f.appendChild(el('p', { osztaly: 's-uzenet s-uzenet-ok', role: 'status', szoveg: v.uzenet }));
            }, function (h) {
                gomb.disabled = false;
                var hb = (h.valasz || {}).hibak || {};
                Array.prototype.forEach.call(f.querySelectorAll('.s-mezo-hiba'), function (p) { p.textContent = ''; });
                Array.prototype.forEach.call(f.querySelectorAll('[aria-invalid]'), function (m) { m.removeAttribute('aria-invalid'); });
                var elso = null;
                Object.keys(hb).forEach(function (k) {
                    var s = f.querySelector('[data-mezo="' + k + '"]');
                    if (!s) { return; }
                    s.querySelector('.s-mezo-hiba').textContent = hb[k];
                    var m = s.querySelector('input,select,textarea');
                    if (m) { m.setAttribute('aria-invalid', 'true'); m.setAttribute('aria-describedby', s.querySelector('.s-mezo-hiba').id); if (!elso) { elso = m; } }
                });
                if (elso) { elso.focus(); }
                APP.uzen(h.uzenet, 'hiba');
            });
        });
    });
}(window, document));
