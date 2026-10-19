/**
 * Kosár oldal. DOM: <div data-modul="kosar"></div>. Adat: window.APP_KOSAR (katalógus, fizetési módok, b2b_felirat).
 * A kosár a böngészőben (APP.kosar); az árat mindig a szerver adja (POST /kosar/ellenoriz), kuponnal együtt.
 */
(function (w, d) {
    'use strict';
    var APP = w.APP, D = w.APP_KOSAR || {};
    if (!APP || !APP.modulok) { return; }
    var el = APP.el, ft = APP.ft;

    function nev(tipus, kulcs) {
        var l = { csomag: D.csomagok, modul: D.modulok, szolgaltatas: D.szolgaltatasok }[tipus] || [];
        for (var i = 0; i < l.length; i++) { if (l[i].kulcs === kulcs) { return l[i].nev || kulcs; } }
        return kulcs;
    }

    APP.modulok('kosar', function (gy) {
        gy.classList.add('s-kosar');
        var fo = el('div', { osztaly: 's-kosar-tartalom', 'aria-live': 'polite' });
        gy.appendChild(fo);
        var keres = 0;

        function rajz(v) {
            fo.textContent = '';
            var tetelek = APP.kosar.tetelek(), o = APP.kosar.opciok();
            if (!tetelek.length) {
                fo.appendChild(el('p', { osztaly: 's-kosar-ures', szoveg: 'A kosarad üres.' }));
                if (D.arak_url) { fo.appendChild(el('a', { href: D.arak_url, osztaly: 's-gomb', szoveg: 'Csomagok és árak' })); }
                return;
            }
            if (!v) { fo.appendChild(el('p', { osztaly: 's-halvany', szoveg: 'Árak számolása…' })); return; }
            var ar = v.ar;

            // Ciklus
            var cik = el('div', { osztaly: 's-kosar-ciklus', role: 'group', 'aria-label': 'Számlázás' });
            ['havi', 'eves'].forEach(function (c) {
                var g = el('button', { type: 'button', osztaly: 's-pill' + ((o.ciklus || 'eves') === c ? ' s-aktiv' : ''), 'aria-pressed': (o.ciklus || 'eves') === c ? 'true' : 'false', szoveg: c === 'havi' ? 'Havi díj' : 'Éves díj' + (D.eves_kedvezmeny ? ' (−' + D.eves_kedvezmeny + '%)' : '') });
                g.addEventListener('click', function () { APP.kosar.opcio('ciklus', c); });
                cik.appendChild(g);
            });
            fo.appendChild(cik);

            // Sorok
            var tb = el('table', { osztaly: 's-kosar-tabla' }, [el('thead', {}, [el('tr', {}, [el('th', { szoveg: 'Tétel' }), el('th', { osztaly: 's-szam', szoveg: 'Nettó' }), el('th', {}, [el('span', { osztaly: 's-rejtett-szoveg', szoveg: 'Művelet' })])])])]);
            var tbody = el('tbody');
            ar.sorok.forEach(function (s) {
                var torolheto = ['csomag', 'modul', 'szolgaltatas'].indexOf(s.tipus) >= 0;
                var g = torolheto ? el('button', { type: 'button', osztaly: 's-gomb s-gomb-kicsi', 'data-ketlepcsos': '1', szoveg: 'Törlés', 'aria-label': 'Törlés: ' + s.nev }) : null;
                if (g) { g.addEventListener('ketlepcsos', function () { APP.kosar.vesz(s.tipus, s.kulcs); }); }
                var utotag = s.ciklus === 'havi' ? '/hó' : s.ciklus === 'eves' ? '/év' : '';
                tbody.appendChild(el('tr', {}, [
                    el('td', {}, [s.nev + (s.mennyiseg > 1 ? ' × ' + s.mennyiseg : ''), s.kedvezmeny ? el('small', { osztaly: 's-halvany', szoveg: ' (kedvezmény: −' + ft(s.kedvezmeny) + ')' }) : null]),
                    el('td', { osztaly: 's-szam', szoveg: ft(s.netto) + utotag }),
                    el('td', {}, [g])
                ]));
            });
            tb.appendChild(tbody);
            fo.appendChild(tb);

            // Létszám (csomagnál)
            if (ar.csomag) {
                var ls = el('div', { osztaly: 's-kosar-letszam' });
                [['felhasznalo', 'Felhasználók'], ['telephely', 'Telephelyek']].forEach(function (x) {
                    var id = 'kosar-' + x[0];
                    var i = el('input', { type: 'number', min: '1', step: '1', inputmode: 'numeric', id: id, osztaly: 's-mezo', value: String(ar[x[0]] || 1) });
                    i.addEventListener('change', function () { APP.kosar.opcio(x[0], i.value === '' ? null : parseInt(i.value, 10)); });
                    ls.appendChild(el('label', { for: id, szoveg: x[1] }));
                    ls.appendChild(i);
                });
                fo.appendChild(ls);
            }

            // Kupon
            var ki = el('input', { type: 'text', id: 'kosar-kupon', osztaly: 's-mezo', autocomplete: 'off', value: o.kupon || '', maxlength: '40' });
            var kg = el('button', { type: 'button', osztaly: 's-gomb', szoveg: o.kupon ? 'Kupon törlése' : 'Beváltás' });
            kg.addEventListener('click', function () {
                if (o.kupon) { APP.kosar.opcio('kupon', null); return; }
                var k = ki.value.trim().toUpperCase();
                if (!k) { return; }
                APP.api('/kupon', { kod: k, tetelek: tetelek, opciok: o }).then(function (e) {
                    if (e.ok) { APP.kosar.opcio('kupon', k); APP.uzen(e.uzenet, 'ok'); } else { APP.uzen(e.uzenet, 'hiba'); }
                }, function (h) { APP.uzen(h.uzenet, 'hiba'); });
            });
            fo.appendChild(el('div', { osztaly: 's-kosar-kupon' }, [el('label', { for: 'kosar-kupon', szoveg: 'Kuponkód' }), ki, kg]));

            // Összesen
            var ism = ar.ciklus === 'havi' ? ' / hó' : ar.ciklus === 'eves' ? ' / év' : '';
            fo.appendChild(el('dl', { osztaly: 's-kosar-osszeg' }, [
                el('dt', { szoveg: 'Nettó' }), el('dd', { szoveg: ft(ar.netto) + ism }),
                ar.kedvezmeny ? el('dt', { szoveg: 'Ebből kedvezmény' }) : null, ar.kedvezmeny ? el('dd', { szoveg: '−' + ft(ar.kedvezmeny) }) : null,
                el('dt', { szoveg: 'Áfa (' + ar.afa_kulcs + '%)' }), el('dd', { szoveg: ft(ar.afa) }),
                el('dt', { szoveg: 'Fizetendő' }), el('dd', {}, [el('strong', { szoveg: ft(ar.brutto) })])
            ]));
            if (ar.eves_megtakaritas > 0) { fo.appendChild(el('p', { osztaly: 's-kosar-megtakaritas', szoveg: 'Éves díjjal ' + ft(ar.eves_megtakaritas) + ' nettót spórolsz.' })); }
            ar.hibak.concat(ar.figyelmeztetesek).forEach(function (h) { fo.appendChild(el('p', { osztaly: 's-uzenet s-uzenet-' + (ar.hibak.indexOf(h) >= 0 ? 'hiba' : 'info'), role: 'status', szoveg: h })); });

            if (D.b2b_felirat) { fo.appendChild(el('p', { osztaly: 's-kosar-b2b', szoveg: D.b2b_felirat })); }
            var tovabb = el('a', { href: D.penztar_url || '#', osztaly: 's-gomb s-gomb-fo', szoveg: 'Tovább a rendeléshez' });
            if (ar.hibak.length) { tovabb.setAttribute('aria-disabled', 'true'); tovabb.addEventListener('click', function (e) { e.preventDefault(); }); }
            fo.appendChild(tovabb);
        }

        function frissit() {
            var t = APP.kosar.tetelek();
            if (!t.length) { rajz(null); return; }
            rajz(null);
            var n = ++keres;
            APP.api('/kosar/ellenoriz', { tetelek: t, opciok: APP.kosar.opciok() }).then(function (v) {
                if (n === keres) { rajz(v); }
            }, function (h) { if (n === keres) { fo.textContent = ''; fo.appendChild(el('p', { osztaly: 's-uzenet s-uzenet-hiba', szoveg: h.uzenet })); } });
        }

        APP.kosar.figyel(frissit);
        frissit();
    });
}(window, document));
