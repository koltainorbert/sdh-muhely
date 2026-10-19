/**
 * Pénztár: számlázási adatok, fizetési mód, nyilatkozatok (B2B, ÁSZF, licencszerződés) → POST /rendeles; utána az
 * állapotoldal (átutalási adatok + QR, kártyás visszatérés, fizetés figyelése).
 * DOM: <div data-modul="penztar" data-opciok='{"tipus":"uj"}'></div>. Megújítás / bővítés: ?tipus=megujitas&licenc=KULCS.
 * Állapotoldal: ?rendeles=R-2026-000123&t=<token> (a levélből / átirányításból). Adat: window.APP_PENZTAR.
 */
(function (w, d) {
    'use strict';
    var APP = w.APP, D = w.APP_PENZTAR || {};
    if (!APP || !APP.modulok) { return; }
    var el = APP.el, ft = APP.ft;
    var Q = new URLSearchParams(w.location.search);

    function mezo(nev, cimke, tipus, ertek, attr) {
        var id = 'penztar-' + nev;
        var a = { id: id, name: nev, type: tipus || 'text', osztaly: 's-mezo', value: ertek || '' };
        Object.keys(attr || {}).forEach(function (k) { a[k] = attr[k]; });
        return el('div', { osztaly: 's-mezo-sor', 'data-mezo': nev }, [el('label', { for: id, szoveg: cimke }), el('input', a), el('p', { osztaly: 's-mezo-hiba', id: id + '-hiba', 'aria-live': 'polite' })]);
    }

    function hibak(f, h) {
        Array.prototype.forEach.call(f.querySelectorAll('.s-mezo-hiba'), function (p) { p.textContent = ''; });
        Array.prototype.forEach.call(f.querySelectorAll('[aria-invalid]'), function (m) { m.removeAttribute('aria-invalid'); });
        var elso = null;
        Object.keys(h || {}).forEach(function (k) {
            var sor = f.querySelector('[data-mezo="' + k + '"]') || f.querySelector('[data-mezo="kosar"]');
            if (!sor) { return; }
            var p = sor.querySelector('.s-mezo-hiba');
            if (p) { p.textContent = h[k]; }
            var m = sor.querySelector('input,select,textarea');
            if (m) { m.setAttribute('aria-invalid', 'true'); m.setAttribute('aria-describedby', p ? p.id : ''); if (!elso) { elso = m; } }
        });
        if (elso) { elso.focus(); }
    }

    /* ---------------- Állapotoldal ---------------- */
    function allapot(gy, azon, token) {
        gy.textContent = '';
        var doboz = el('div', { osztaly: 's-statusz', 'aria-live': 'polite' });
        gy.appendChild(doboz);
        var figyelo = null, kor = 0;

        function rajz(r) {
            doboz.textContent = '';
            doboz.appendChild(el('h2', { szoveg: 'Rendelés: ' + r.azon }));
            doboz.appendChild(el('p', {}, ['Állapot: ', el('strong', { szoveg: r.allapot_szoveg })]));
            if (r.kesz) {
                doboz.appendChild(el('p', { osztaly: 's-uzenet s-uzenet-ok', szoveg: 'Köszönjük, a fizetés megérkezett. A licencet és a telepítéshez szükséges mindent e-mailben küldjük.' }));
                if (r.szamla_url) { doboz.appendChild(el('a', { href: r.szamla_url, osztaly: 's-gomb', szoveg: 'Számla letöltése (PDF)' })); }
                if (D.fiok_url) { doboz.appendChild(el('a', { href: D.fiok_url, osztaly: 's-gomb', szoveg: 'Fiókom' })); }
                if (figyelo) { clearInterval(figyelo); }
                if (APP.kosar.db()) { APP.kosar.urit(); }
                return;
            }
            if (r.atutalas) {
                var a = r.atutalas;
                doboz.appendChild(el('p', { szoveg: 'Az alábbi adatokkal utalj; a beérkezés után a licenced automatikusan elkészül.' }));
                doboz.appendChild(el('dl', { osztaly: 's-atutalas' }, [
                    el('dt', { szoveg: 'Kedvezményezett' }), el('dd', { szoveg: a.kedvezmenyezett }),
                    el('dt', { szoveg: 'Számlaszám' }), el('dd', {}, [el('code', { szoveg: a.bankszamla })]),
                    a.iban ? el('dt', { szoveg: 'IBAN' }) : null, a.iban ? el('dd', {}, [el('code', { szoveg: a.iban })]) : null,
                    el('dt', { szoveg: 'Összeg' }), el('dd', {}, [el('strong', { szoveg: ft(a.osszeg) })]),
                    el('dt', { szoveg: 'Közlemény' }), el('dd', {}, [el('code', { osztaly: 's-kozlemeny', szoveg: a.kozlemeny })]),
                    el('dt', { szoveg: 'Határidő' }), el('dd', { szoveg: a.hatarido })
                ]));
                var masol = el('button', { type: 'button', osztaly: 's-gomb s-gomb-kicsi', szoveg: 'Közlemény másolása' });
                masol.addEventListener('click', function () {
                    if (navigator.clipboard) { navigator.clipboard.writeText(a.kozlemeny).then(function () { APP.uzen('Kimásolva.', 'ok'); }); }
                });
                doboz.appendChild(masol);
                if (a.qr_svg) {
                    var q = el('figure', { osztaly: 's-qr' });
                    q.innerHTML = a.qr_svg; // a szerver által készített, tisztán generált SVG (csak rect + path)
                    q.appendChild(el('figcaption', { szoveg: 'Olvasd be a banki alkalmazásoddal – minden adat kitöltődik.' }));
                    doboz.appendChild(q);
                }
                if (r.dijbekero_url) { doboz.appendChild(el('a', { href: r.dijbekero_url, osztaly: 's-gomb', szoveg: 'Díjbekérő letöltése (PDF)' })); }
                if (r.fizetett > 0) { doboz.appendChild(el('p', { osztaly: 's-uzenet s-uzenet-info', szoveg: 'Eddig beérkezett: ' + ft(r.fizetett) + ', hátralék: ' + ft(r.hatralek) + '.' })); }
            }
            if (r.ujraproba) {
                var uj = el('button', { type: 'button', osztaly: 's-gomb s-gomb-fo', szoveg: 'Fizetés újra kártyával' });
                uj.addEventListener('click', function () { ujra(r.azon, token, ''); });
                var atv = el('button', { type: 'button', osztaly: 's-gomb', szoveg: 'Inkább átutalással fizetek' });
                atv.addEventListener('click', function () { ujra(r.azon, token, 'atutalas'); });
                doboz.appendChild(el('div', { osztaly: 's-gombsor' }, [uj, atv]));
            }
        }

        function ujra(az, t, fizmod) {
            APP.api('/fizetes/ujra', { azon: az, t: t, fizmod: fizmod || undefined }).then(function (v) {
                if (v.atiranyit) { w.location.href = v.atiranyit; return; }
                if (v.rendeles) { rajz(v.rendeles); }
            }, function (h) { APP.uzen(h.uzenet, 'hiba'); });
        }

        function lekér() {
            return APP.api('/rendeles/' + encodeURIComponent(azon) + '/allapot', null, { query: { t: token } }).then(rajz, function (h) {
                doboz.textContent = '';
                doboz.appendChild(el('p', { osztaly: 's-uzenet s-uzenet-hiba', szoveg: h.uzenet }));
                if (figyelo) { clearInterval(figyelo); }
            });
        }

        // SimplePay visszatérés (?r=&s=)
        var p = Q.get('r'), s = Q.get('s');
        var elso = p && s ? APP.api('/fizetes/simplepay/vissza', null, { query: { r: p, s: s } }).then(function (v) { APP.uzen(v.uzenet, v.esemeny === 'SUCCESS' ? 'ok' : 'hiba'); }, function () { /* a lekérdezés úgyis jön */ }) : Promise.resolve();
        elso.then(lekér);
        figyelo = setInterval(function () { if (++kor > 60) { clearInterval(figyelo); return; } lekér(); }, 15000);
    }

    /* ---------------- Pénztár ---------------- */
    function penztar(gy, opc) {
        var tipus = Q.get('tipus') || opc.tipus || 'uj', licenc = Q.get('licenc') || '';
        var v = D.vevo || {};
        var f = el('form', { osztaly: 's-penztar', novalidate: '' });
        gy.textContent = '';
        gy.appendChild(f);

        var osszeg = el('div', { osztaly: 's-penztar-osszeg', 'data-mezo': 'kosar', 'aria-live': 'polite' }, [el('p', { osztaly: 's-mezo-hiba', id: 'penztar-kosar-hiba' })]);
        f.appendChild(osszeg);
        if (D.b2b && D.b2b.felirat) { f.appendChild(el('p', { osztaly: 's-penztar-b2b', szoveg: D.b2b.felirat })); }

        var adat = el('fieldset', {}, [el('legend', { szoveg: 'Számlázási adatok' })]);
        var orszag = el('select', { id: 'penztar-orszag', name: 'orszag', osztaly: 's-mezo' });
        [['HU', 'Magyarország'], ['AT', 'Ausztria'], ['SK', 'Szlovákia'], ['RO', 'Románia'], ['HR', 'Horvátország'], ['SI', 'Szlovénia'], ['DE', 'Németország'], ['CZ', 'Csehország'], ['PL', 'Lengyelország']].forEach(function (o) {
            var op = el('option', { value: o[0], szoveg: o[1] });
            if ((v.orszag || 'HU') === o[0]) { op.selected = true; }
            orszag.appendChild(op);
        });
        adat.appendChild(mezo('ceg', 'Cégnév', 'text', v.ceg, { autocomplete: 'organization', required: true }));
        adat.appendChild(el('div', { osztaly: 's-mezo-sor', 'data-mezo': 'orszag' }, [el('label', { for: 'penztar-orszag', szoveg: 'Ország' }), orszag]));
        var adoszam = mezo('adoszam', 'Adószám', 'text', v.adoszam, { inputmode: 'numeric', placeholder: '12345678-1-42' });
        adat.appendChild(adoszam);
        adat.appendChild(mezo('eu_adoszam', 'Közösségi adószám (ha van)', 'text', v.eu_adoszam, { placeholder: 'HU12345678' }));
        adat.appendChild(mezo('irsz', 'Irányítószám', 'text', v.irsz, { autocomplete: 'postal-code', inputmode: 'numeric' }));
        adat.appendChild(mezo('telepules', 'Település', 'text', v.telepules, { autocomplete: 'address-level2' }));
        adat.appendChild(mezo('utca', 'Utca, házszám', 'text', v.utca, { autocomplete: 'street-address' }));
        adat.appendChild(mezo('kapcsolattarto', 'Kapcsolattartó neve', 'text', v.kapcsolattarto, { autocomplete: 'name' }));
        adat.appendChild(mezo('email', 'E-mail-cím', 'email', v.email, { autocomplete: 'email' }));
        adat.appendChild(mezo('telefon', 'Telefonszám', 'tel', v.telefon, { autocomplete: 'tel', placeholder: '+36 30 123 4567' }));
        f.appendChild(adat);
        orszag.addEventListener('change', function () { adoszam.style.display = orszag.value === 'HU' ? '' : 'none'; });
        adoszam.style.display = orszag.value === 'HU' ? '' : 'none';

        var fm = el('fieldset', { 'data-mezo': 'fizmod' }, [el('legend', { szoveg: 'Fizetési mód' })]);
        (D.fizmodok || []).forEach(function (m, i) {
            var r = el('input', { type: 'radio', name: 'fizmod', value: m.kulcs, id: 'penztar-fm-' + m.kulcs });
            if (i === 0) { r.checked = true; }
            fm.appendChild(el('label', { osztaly: 's-valaszto', for: 'penztar-fm-' + m.kulcs }, [r, el('strong', { szoveg: ' ' + m.nev }), el('span', { osztaly: 's-halvany', szoveg: ' – ' + m.leiras })]));
        });
        fm.appendChild(el('p', { osztaly: 's-mezo-hiba', id: 'penztar-fizmod-hiba' }));
        f.appendChild(fm);

        f.appendChild(el('div', { osztaly: 's-mezo-sor', 'data-mezo': 'megjegyzes' }, [el('label', { for: 'penztar-megjegyzes', szoveg: 'Megjegyzés (nem kötelező)' }), el('textarea', { id: 'penztar-megjegyzes', name: 'megjegyzes', osztaly: 's-mezo', rows: '3', maxlength: '1000' })]));

        function pipa(nev, gyerek) {
            var id = 'penztar-' + nev;
            return el('div', { osztaly: 's-mezo-sor s-pipa', 'data-mezo': 'elfogad_' + nev }, [el('input', { type: 'checkbox', id: id, name: 'elfogad_' + nev }), el('label', { for: id }, gyerek), el('p', { osztaly: 's-mezo-hiba', id: id + '-hiba' })]);
        }
        var J = D.jogi || {};
        f.appendChild(pipa('vallalkozas', [(D.b2b && D.b2b.nyilatkozat) || 'Vállalkozásként / szervezetként vásárolok (nem fogyasztóként).']));
        f.appendChild(pipa('aszf', ['Elfogadom az ', el('a', { href: (J.aszf || {}).url || '#', target: '_blank', rel: 'noopener', szoveg: 'Általános Szerződési Feltételeket' }), (J.aszf && J.aszf.verzio ? ' (' + J.aszf.verzio + ')' : '') + '.']));
        if (tipus !== 'szolgaltatas') {
            f.appendChild(pipa('eula', ['Elfogadom a ', el('a', { href: (J.eula || {}).url || '#', target: '_blank', rel: 'noopener', szoveg: 'licencszerződést' }), (J.eula && J.eula.verzio ? ' (' + J.eula.verzio + ')' : '') + '.']));
        }
        f.appendChild(el('p', { osztaly: 's-halvany' }, ['Az adataidat az ', el('a', { href: (J.adatvedelem || {}).url || '#', target: '_blank', rel: 'noopener', szoveg: 'adatkezelési tájékoztató' }), ' szerint kezeljük.']));

        var gomb = el('button', { type: 'submit', osztaly: 's-gomb s-gomb-fo', szoveg: 'Megrendelem (fizetési kötelezettséggel)' });
        f.appendChild(gomb);
        APP.vedelem(f, D.vedelem);

        var utolsoAr = null;
        function arFrissit() {
            var t = tipus === 'megujitas' ? [] : APP.kosar.tetelek();
            if (tipus === 'uj' && !t.length) { osszeg.firstChild.textContent = 'A kosarad üres.'; gomb.disabled = true; return; }
            if (tipus !== 'uj') { return; }
            APP.api('/kosar/ellenoriz', { tetelek: t, opciok: APP.kosar.opciok() }).then(function (v2) {
                utolsoAr = v2.ar;
                while (osszeg.childNodes.length > 1) { osszeg.removeChild(osszeg.lastChild); }
                v2.ar.sorok.forEach(function (s) { osszeg.appendChild(el('p', {}, [s.nev + (s.mennyiseg > 1 ? ' × ' + s.mennyiseg : '') + ': ', el('span', { osztaly: 's-szam', szoveg: ft(s.netto) })])); });
                osszeg.appendChild(el('p', {}, [el('strong', { szoveg: 'Fizetendő: ' + ft(v2.ar.brutto) }), ' (nettó ' + ft(v2.ar.netto) + ' + ' + v2.ar.afa_kulcs + '% áfa)']));
                gomb.disabled = !v2.ok;
                if (!v2.ok) { osszeg.firstChild.textContent = v2.ar.hibak.join(' '); }
            }, function (h) { osszeg.firstChild.textContent = h.uzenet; });
        }
        arFrissit();
        APP.kosar.figyel(arFrissit);

        f.addEventListener('submit', function (e) {
            e.preventDefault();
            var u = APP.urlap(f);
            var test = {
                tipus: tipus,
                licenc_kulcs: licenc || undefined,
                tetelek: APP.kosar.tetelek(),
                opciok: APP.kosar.opciok(),
                fizmod: u.fizmod,
                megjegyzes: u.megjegyzes,
                vevo: { ceg: u.ceg, orszag: u.orszag, adoszam: u.adoszam, eu_adoszam: u.eu_adoszam, irsz: u.irsz, telepules: u.telepules, utca: u.utca, kapcsolattarto: u.kapcsolattarto, email: u.email, telefon: u.telefon },
                elfogad: { vallalkozas: !!u.elfogad_vallalkozas, aszf: !!u.elfogad_aszf, eula: !!u.elfogad_eula },
                _t: u._t, turnstile: u.turnstile
            };
            if (D.vedelem && D.vedelem.hp) { test[D.vedelem.hp] = u[D.vedelem.hp] || ''; }
            if (utolsoAr) { test.vart_brutto = utolsoAr.brutto; }
            gomb.disabled = true;
            gomb.setAttribute('aria-busy', 'true');
            APP.api('/rendeles', test).then(function (v2) {
                if (v2.atiranyit) { w.location.href = v2.atiranyit; return; }
                APP.kosar.urit();
                w.location.href = v2.statusz_url;
            }, function (h) {
                gomb.disabled = false;
                gomb.removeAttribute('aria-busy');
                var vj = h.valasz || {};
                if (vj.arvaltozas) { arFrissit(); }
                hibak(f, vj.hibak || {});
                APP.uzen(h.uzenet, 'hiba');
            });
        });
    }

    APP.modulok('penztar', function (gy, opc) {
        gy.classList.add('s-penztar-doboz');
        var azon = Q.get('rendeles'), token = Q.get('t');
        if (azon && token) { allapot(gy, azon, token); } else { penztar(gy, opc); }
    });
}(window, document));
