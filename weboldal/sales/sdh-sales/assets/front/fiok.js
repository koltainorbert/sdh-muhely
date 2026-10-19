/**
 * Ügyfélfiók. DOM: <div data-modul="fiok"></div>. Adat: window.APP_FIOK {belepve, vedelem, perc, penztar, kosar, egyedi}.
 * Kijelentkezve: e-mailes belépő link kérése (POST /fiok/belepes); ?belepes=<token> → POST /fiok/belep.
 * Belépve: GET /fiok → rendelések, licencek (megújítás / bővítés), letöltések, foglalások, egyedi igények, oktatás.
 */
(function (w, d) {
    'use strict';
    var APP = w.APP, D = w.APP_FIOK || {};
    if (!APP || !APP.modulok) { return; }
    var el = APP.el, ft = APP.ft;

    function datum(s) {
        if (!s) { return ''; }
        var t = new Date(String(s).replace(' ', 'T') + (String(s).length > 10 && !/Z|\+/.test(s) ? 'Z' : ''));
        return isNaN(t) ? String(s) : t.toLocaleDateString('hu-HU', { year: 'numeric', month: 'long', day: 'numeric' });
    }

    function urlap(gy) {
        gy.textContent = '';
        var f = el('form', { osztaly: 's-fiok-belepes', novalidate: '' }, [
            el('h2', { szoveg: 'Belépés a fiókodba' }),
            el('p', { szoveg: 'Jelszó nem kell: add meg a rendeléskor használt e-mail-címed, és küldünk egy belépő linket (' + (D.perc || 30) + ' percig érvényes, egyszer használható).' }),
            el('label', { for: 'fiok-email', szoveg: 'E-mail-cím' }),
            el('input', { type: 'email', id: 'fiok-email', name: 'email', osztaly: 's-mezo', autocomplete: 'email', required: true }),
            el('button', { type: 'submit', osztaly: 's-gomb s-gomb-fo', szoveg: 'Belépő link küldése' })
        ]);
        gy.appendChild(f);
        APP.vedelem(f, D.vedelem);
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            var u = APP.urlap(f);
            f.querySelector('button').disabled = true;
            APP.api('/fiok/belepes', u).then(function (v) {
                f.textContent = '';
                f.appendChild(el('p', { osztaly: 's-uzenet s-uzenet-ok', role: 'status', szoveg: v.uzenet }));
            }, function (h) { f.querySelector('button').disabled = false; APP.uzen(h.uzenet, 'hiba'); });
        });
    }

    function szakasz(cim, gyerek) { return el('section', { osztaly: 's-fiok-szakasz' }, [el('h2', { szoveg: cim })].concat(gyerek)); }

    function fiok(gy) {
        gy.textContent = '';
        gy.appendChild(el('p', { osztaly: 's-halvany', szoveg: 'Betöltés…' }));
        APP.api('/fiok').then(function (a) {
            gy.textContent = '';
            var ki = el('button', { type: 'button', osztaly: 's-gomb s-gomb-kicsi', szoveg: 'Kijelentkezés' });
            ki.addEventListener('click', function () { APP.api('/fiok/kilepes', {}).then(function () { w.location.href = w.location.pathname; }); });
            gy.appendChild(el('div', { osztaly: 's-fiok-fej' }, [el('h1', { szoveg: 'Szia' + (a.nev ? ', ' + a.nev : '') + '!' }), el('span', { osztaly: 's-halvany', szoveg: a.email }), ki]));

            // Licencek
            var lic = (a.licencek || []).map(function (l) {
                var meg = el('a', { osztaly: 's-gomb', href: D.penztar + (D.penztar.indexOf('?') < 0 ? '?' : '&') + 'tipus=megujitas&licenc=' + encodeURIComponent(l.kulcs), szoveg: 'Megújítás' });
                var bov = el('a', { osztaly: 's-gomb', href: D.penztar + (D.penztar.indexOf('?') < 0 ? '?' : '&') + 'tipus=bovites&licenc=' + encodeURIComponent(l.kulcs), szoveg: 'Bővítés' });
                return el('article', { osztaly: 's-kartya s-fiok-licenc' + (l.lejart ? ' s-lejart' : l.hamarosan ? ' s-hamarosan' : '') }, [
                    el('h3', { szoveg: l.csomag }),
                    el('p', {}, ['Licenckulcs: ', el('code', { szoveg: l.kulcs })]),
                    el('p', { szoveg: 'Felhasználók: ' + (l.felhasznalo || '–') + ', telephelyek: ' + (l.telephely || '–') + ', ' + (l.ciklus === 'havi' ? 'havi' : 'éves') + ' díj' }),
                    el('p', {}, ['Érvényes: ', el('strong', { szoveg: l.lejar ? datum(l.lejar) : 'a kiszolgálás után' }), l.lejart ? ' – lejárt' : l.hamarosan ? ' – hamarosan lejár' : '']),
                    el('div', { osztaly: 's-gombsor' }, [meg, bov])
                ]);
            });
            gy.appendChild(szakasz('Licenceim', lic.length ? lic : [el('p', { osztaly: 's-halvany', szoveg: 'Még nincs licenced.' })]));

            if ((a.letoltesek || []).length) {
                gy.appendChild(szakasz('Letöltések', [el('ul', {}, a.letoltesek.map(function (x) { return el('li', {}, [el('a', { href: x.url, szoveg: x.cim })]); }))]));
            }

            // Rendelések
            var rend = (a.rendelesek || []).map(function (r) {
                return el('tr', {}, [
                    el('td', {}, [el('a', { href: r.statusz, szoveg: r.azon })]),
                    el('td', { szoveg: datum(r.datum) }),
                    el('td', { szoveg: r.allapot_szoveg }),
                    el('td', { osztaly: 's-szam', szoveg: ft(r.brutto) }),
                    el('td', {}, [r.szamla_url ? el('a', { href: r.szamla_url, szoveg: 'Számla' }) : r.dijbekero_url ? el('a', { href: r.dijbekero_url, szoveg: 'Díjbekérő' }) : '–'])
                ]);
            });
            gy.appendChild(szakasz('Rendeléseim', rend.length ? [el('table', { osztaly: 's-tabla' }, [el('thead', {}, [el('tr', {}, ['Rendelés', 'Dátum', 'Állapot', 'Összeg', 'Bizonylat'].map(function (x) { return el('th', { szoveg: x }); }))]), el('tbody', {}, rend)])] : [el('p', { osztaly: 's-halvany', szoveg: 'Még nincs rendelésed.' })]));

            if ((a.foglalasok || []).length) {
                gy.appendChild(szakasz('Időpontjaim', [el('ul', {}, a.foglalasok.map(function (x) {
                    var t = new Date(String(x.kezdet).replace(' ', 'T') + 'Z');
                    return el('li', { szoveg: ({ demo: 'Bemutató', telepites: 'Telepítés', oktatas: 'Oktatás', support: 'Támogatás' }[x.tipus] || x.tipus) + ': ' + (isNaN(t) ? x.kezdet : t.toLocaleString('hu-HU', { dateStyle: 'long', timeStyle: 'short' })) + ' (' + x.allapot + ')' });
                }))]));
            }

            var egy = (a.egyedi || []).map(function (x) {
                return el('article', { osztaly: 's-kartya' }, [
                    el('h3', { szoveg: x.azon + ' – ' + x.allapot_szoveg }),
                    el('ul', {}, (x.osszefoglalo || []).map(function (s) { return el('li', { szoveg: s }); })),
                    x.becsles ? el('p', { osztaly: 's-becsles', szoveg: x.becsles.cimke + ' ' + x.becsles.ora_min + '–' + x.becsles.ora_max + ' óra, ' + ft(x.becsles.ar_min) + ' – ' + ft(x.becsles.ar_max) + ' + áfa.' }) : null
                ]);
            });
            if (egy.length) { gy.appendChild(szakasz('Egyedi fejlesztési igényeim', egy)); }

            var ok = a.oktatas || {};
            if ((ok.kurzusok || []).length) {
                gy.appendChild(szakasz('Oktatás', [el('ul', {}, ok.kurzusok.map(function (k) {
                    return el('li', {}, [k.url ? el('a', { href: k.url, szoveg: k.cim || k.kulcs }) : (k.cim || k.kulcs), k.szazalek !== undefined ? ' – ' + k.szazalek + '%' : '']);
                }))].concat((ok.tanusitvanyok || []).length ? [el('p', {}, ok.tanusitvanyok.map(function (t) { return el('a', { href: t.url, osztaly: 's-gomb s-gomb-kicsi', szoveg: 'Tanúsítvány: ' + (t.cim || '') }); }))] : [])));
            }
        }, function (h) {
            if (h.status === 401 || h.status === 403) { urlap(gy); return; }
            gy.textContent = '';
            gy.appendChild(el('p', { osztaly: 's-uzenet s-uzenet-hiba', szoveg: h.uzenet }));
        });
    }

    APP.modulok('fiok', function (gy) {
        gy.classList.add('s-fiok');
        var q = new URLSearchParams(w.location.search), token = q.get('belepes');
        if (token) {
            w.history.replaceState(null, '', w.location.pathname);
            APP.api('/fiok/belep', { token: token }).then(function () { w.location.reload(); }, function (h) { urlap(gy); APP.uzen(h.uzenet, 'hiba'); });
            return;
        }
        if (D.belepve) { fiok(gy); } else { urlap(gy); }
    });
}(window, document));
