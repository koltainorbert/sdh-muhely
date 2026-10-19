/**
 * SDH Értékesítés – árkalkulátor. A számítás (SDHAr.szamol) a PHP SDH_Sales_Ar::szamol_tiszta() pontos tükre:
 * ugyanazok a szabályok, kerekítések és magyar hibaüzenetek (a t210 teszt ugyanazokat az eseteket mindkettőn lefuttatja).
 * A rendelésnél mindig a szerver ára számít; ez csak az élő előnézet.
 * DOM: <div data-sdh="kalkulator" data-opciok='{"csomag":"pro","ciklus":"eves"}'></div>
 * Adat: window.SDH_KALKULATOR (SDH_Sales_Katalogus::publikus()).
 */
(function (gyoker) {
    'use strict';

    var MAX_FELHASZNALO = 1000, MAX_TELEPHELY = 100, MAX_DB = 1000;

    function kerek(a, b) { return Math.floor((2 * a + b) / (2 * b)); }
    function kerekf(x) { return Math.floor(x + 0.5); }
    function int(v) { v = parseInt(v, 10); return isNaN(v) ? 0 : v; }
    function ft(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft'; }
    function van(o, k) { return o !== null && typeof o === 'object' && Object.prototype.hasOwnProperty.call(o, k); }
    function obj(o) { return o !== null && typeof o === 'object' && !Array.isArray(o); }
    function lista(a) { return Array.isArray(a) ? a.map(String) : (obj(a) ? Object.keys(a).map(function (k) { return String(a[k]); }) : []); }
    function egesz(v) {
        if (typeof v === 'number') { return Math.floor(v) === v ? v : null; }
        if (typeof v === 'string' && /^-?\d{1,9}$/.test(v.trim())) { return parseInt(v.trim(), 10); }
        return null;
    }
    function egyedi(a) { var l = []; a.forEach(function (x) { if (l.indexOf(x) < 0) { l.push(x); } }); return l; }
    function eves(havi, ex, kedv) {
        if (ex !== null && ex !== undefined && int(ex) > 0) { return int(ex); }
        return kerek(havi * 12 * (100 - Math.max(0, Math.min(100, kedv))), 100);
    }
    function nyers(v) { return v === null || v === undefined ? '' : v; }
    function nv(o, k, alap) { return o[k] === null || o[k] === undefined ? String(alap) : String(o[k]); }
    function ia(o, k, alap) { return o[k] === null || o[k] === undefined ? alap : int(o[k]); }

    /** A PHP szamol_tiszta() tükre. */
    function szamol(kat, tetelek, opciok) {
        opciok = opciok || {};
        var csomagok = kat.csomagok || {}, modulok = kat.modulok || {}, szolg = kat.szolgaltatasok || {};
        if (Array.isArray(csomagok)) { csomagok = kulcsolt(csomagok); }
        if (Array.isArray(modulok)) { modulok = kulcsolt(modulok); }
        if (Array.isArray(szolg)) { szolg = kulcsolt(szolg); }
        var ek = int(kat.eves_kedvezmeny), afaK = Math.max(0, Math.min(100, kat.afa === undefined ? 27 : int(kat.afa)));
        var ma = String(kat.ma || new Date().toISOString().slice(0, 10));
        var hibak = [], figy = [];

        var ciklus = nv(opciok, 'ciklus', 'eves');
        if (ciklus !== 'havi' && ciklus !== 'eves') { hibak.push('Ismeretlen számlázási ciklus. Válassz havi vagy éves díjat.'); ciklus = 'eves'; }

        var csomagK = null, modK = [], szolgDb = {}, szolgSor = [];
        (tetelek || []).forEach(function (t) {
            if (!obj(t)) { hibak.push('Érvénytelen tétel a kosárban.'); return; }
            var tipus = String(nyers(t.tipus)), k = String(nyers(t.kulcs));
            if (tipus === 'csomag') {
                if (!obj(csomagok[k]) || !van(csomagok, k)) { hibak.push('Ismeretlen csomag: ' + k + '.'); }
                else if (csomagK !== null && csomagK !== k) { hibak.push('Egy rendelésben csak egy csomag lehet.'); }
                else { csomagK = k; }
            } else if (tipus === 'modul') {
                if (!van(modulok, k) || !obj(modulok[k])) { hibak.push('Ismeretlen modul: ' + k + '.'); }
                else if (modK.indexOf(k) >= 0) { figy.push('A(z) ' + nv(modulok[k], 'nev', k) + ' modul kétszer szerepelt, egyszer számoltuk.'); }
                else { modK.push(k); }
            } else if (tipus === 'szolgaltatas') {
                var db = egesz(t.db === null || t.db === undefined ? 1 : t.db);
                if (!van(szolg, k) || !obj(szolg[k])) { hibak.push('Ismeretlen szolgáltatás: ' + k + '.'); }
                else if (db === null || db < 1 || db > MAX_DB) { hibak.push('Érvénytelen mennyiség: ' + nv(szolg[k], 'nev', k) + '.'); }
                else { if (!van(szolgDb, k)) { szolgDb[k] = 0; szolgSor.push(k); } szolgDb[k] += db; }
            } else {
                hibak.push('Ismeretlen tételtípus a kosárban.');
            }
        });

        var alapCsomag = csomagK !== null ? csomagK : String(nyers(opciok.alap_csomag));
        var alapC = alapCsomag !== '' && van(csomagok, alapCsomag) && obj(csomagok[alapCsomag]) ? csomagok[alapCsomag] : null;
        if (csomagK === null && alapCsomag !== '' && alapC === null) { hibak.push('Ismeretlen csomag: ' + alapCsomag + '.'); }
        if (modK.length && alapC === null) { hibak.push('Kiegészítő modul csak csomaggal együtt rendelhető.'); }
        if (csomagK === null && !modK.length && !szolgSor.length && !hibak.length) { hibak.push('A kosár üres.'); }

        var benne = [];
        if (alapC !== null) {
            benne = lista(alapC.modulok);
            Object.keys(modulok).forEach(function (mk) {
                var m = modulok[mk];
                if (obj(m) && lista(m.csomagban).indexOf(alapCsomag) >= 0 && benne.indexOf(mk) < 0) { benne.push(mk); }
            });
        }
        var minden = benne.indexOf('*') >= 0;

        var sorok = [], megt = 0;
        function ar(e, hm, em) {
            var havi = Math.max(0, int(e[hm]));
            var ex = van(e, em) && e[em] !== null ? int(e[em]) : null;
            if (ciklus === 'havi') { return [havi, 'havi']; }
            var ev = eves(havi, ex, ek);
            megt += Math.max(0, havi * 12 - ev);
            return [ev, 'eves'];
        }

        var felh = null, telh = null, maxp = 0, r;
        if (alapC !== null) {
            var fAlap = Math.max(1, ia(alapC, 'felhasznalo_alap', 1));
            var tAlap = Math.max(1, ia(alapC, 'telephely_alap', 1));
            maxp = Math.max(0, ia(alapC, 'max_peldany', 1));
            felh = van(opciok, 'felhasznalo') && opciok.felhasznalo !== null && opciok.felhasznalo !== '' ? egesz(opciok.felhasznalo) : fAlap;
            telh = van(opciok, 'telephely') && opciok.telephely !== null && opciok.telephely !== '' ? egesz(opciok.telephely) : tAlap;
            if (felh === null || felh < 1 || felh > MAX_FELHASZNALO) { hibak.push('A felhasználók száma 1 és ' + MAX_FELHASZNALO + ' között lehet.'); felh = fAlap; }
            if (telh === null || telh < 1 || telh > MAX_TELEPHELY) { hibak.push('A telephelyek száma 1 és ' + MAX_TELEPHELY + ' között lehet.'); telh = tAlap; }
            var cnev = nv(alapC, 'nev', alapCsomag);

            [['felhasznalo', felh, fAlap, 'felhasználó'], ['telephely', telh, tAlap, 'telephely']].forEach(function (x) {
                var max = van(alapC, x[0] + '_max') ? int(alapC[x[0] + '_max']) : 0;
                if (max > 0 && x[1] > max) { hibak.push('A(z) ' + cnev + ' csomagban legfeljebb ' + max + ' ' + x[3] + ' lehet. Válassz nagyobb csomagot.'); }
                else if (x[1] > x[2] && (!van(alapC, x[0] + '_extra_havi') || alapC[x[0] + '_extra_havi'] === null)) { hibak.push('A(z) ' + cnev + ' csomagban legfeljebb ' + x[2] + ' ' + x[3] + ' lehet. Válassz nagyobb csomagot.'); }
            });

            var fKezd, tKezd;
            if (csomagK !== null) {
                r = ar(alapC, 'havi', 'eves');
                sorok.push({ tipus: 'csomag', kulcs: csomagK, nev: cnev + ' csomag', mennyiseg: 1, egysegar: r[0], netto_lista: r[0], ciklus: r[1] });
                fKezd = fAlap; tKezd = tAlap;
            } else {
                fKezd = Math.max(fAlap, ia(opciok, 'alap_felhasznalo', fAlap));
                tKezd = Math.max(tAlap, ia(opciok, 'alap_telephely', tAlap));
            }

            [['felhasznalo', felh, fKezd, 'További felhasználó'], ['telephely', telh, tKezd, 'További telephely']].forEach(function (x) {
                var extra = x[1] - x[2];
                if (extra > 0 && van(alapC, x[0] + '_extra_havi') && alapC[x[0] + '_extra_havi'] !== null) {
                    var h = int(alapC[x[0] + '_extra_havi']);
                    var rr = ar({ h: h, e: van(alapC, x[0] + '_extra_eves') ? alapC[x[0] + '_extra_eves'] : null }, 'h', 'e');
                    megt += rr[1] === 'eves' ? Math.max(0, h * 12 - rr[0]) * (extra - 1) : 0;
                    sorok.push({ tipus: x[0], kulcs: alapCsomag, nev: x[3] + ' (' + cnev + ')', mennyiseg: extra, egysegar: rr[0], netto_lista: rr[0] * extra, ciklus: rr[1] });
                }
            });
        }

        modK.forEach(function (mk) {
            var m = modulok[mk];
            if (minden || benne.indexOf(mk) >= 0) { figy.push('A(z) ' + nv(m, 'nev', mk) + ' modul már benne van a csomagban, nem számoltuk fel.'); return; }
            r = ar(m, 'havi', 'eves');
            sorok.push({ tipus: 'modul', kulcs: mk, nev: nv(m, 'nev', mk) + ' modul', mennyiseg: 1, egysegar: r[0], netto_lista: r[0], ciklus: r[1] });
        });

        szolgSor.forEach(function (sk) {
            var s = szolg[sk], db = szolgDb[sk], st = nv(s, 'tipus', 'egyszeri'), a = Math.max(0, int(s.ar)), rr;
            if (st === 'havi') {
                rr = ar({ h: a, e: van(s, 'eves') ? s.eves : null }, 'h', 'e');
                megt += rr[1] === 'eves' ? Math.max(0, a * 12 - rr[0]) * (db - 1) : 0;
            } else if (st === 'eves') { rr = [a, 'eves']; } else { rr = [a, 'egyszeri']; }
            sorok.push({ tipus: 'szolgaltatas', kulcs: sk, nev: nv(s, 'nev', sk), mennyiseg: db, egysegar: rr[0], netto_lista: rr[0] * db, ciklus: rr[1] });
        });

        var listaO = 0;
        sorok.forEach(function (s) { s.kedvezmeny = 0; s.netto = s.netto_lista; listaO += s.netto_lista; });

        var kod = String(nyers(opciok.kupon)).trim().toUpperCase(), kuponKi = null, kedvezmeny = 0;
        if (kod !== '') {
            var kp = obj(kat.kuponok) && van(kat.kuponok, kod) ? kat.kuponok[kod] : null, kh = '';
            if (!obj(kp) || (van(kp, 'aktiv') && !kp.aktiv)) { kh = 'Ismeretlen kuponkód: ' + kod + '.'; }
            else if (kp.lejar && ma > String(kp.lejar).slice(0, 10)) { kh = 'A(z) ' + kod + ' kupon lejárt.'; }
            else if (kp.kezd && ma < String(kp.kezd).slice(0, 10)) { kh = 'A(z) ' + kod + ' kupon még nem érvényes.'; }
            else if (van(kp, 'max') && kp.max !== null && int(kp.max) > 0 && int(kp.hasznalva) >= int(kp.max)) { kh = 'A(z) ' + kod + ' kupon elfogyott.'; }
            else if (kp.ciklus && String(kp.ciklus) !== ciklus) { kh = 'A(z) ' + kod + ' kupon csak ' + (String(kp.ciklus) === 'eves' ? 'éves' : 'havi') + ' díjjal használható.'; }
            else if (int(kp.min) > 0 && listaO < int(kp.min)) { kh = 'A(z) ' + kod + ' kupon legalább ' + ft(int(kp.min)) + ' nettó rendelési értéktől használható.'; }

            var jogos = [];
            if (kh === '') {
                var hat = van(kp, 'hatokor') ? kp.hatokor : 'mind';
                sorok.forEach(function (s, i) {
                    var fo = (s.tipus === 'felhasznalo' || s.tipus === 'telephely') ? 'csomag' : s.tipus;
                    var hl = Array.isArray(hat) ? hat.map(String) : null;
                    if (hat === 'mind' || hat === '' || hat === null || (typeof hat === 'string' && hat === fo) ||
                        (hl && (hl.indexOf(s.kulcs) >= 0 || hl.indexOf(fo) >= 0))) {
                        if (s.netto_lista > 0) { jogos.push(i); }
                    }
                });
                if (!jogos.length) { kh = 'A(z) ' + kod + ' kupon erre a kosárra nem érvényes.'; }
            }

            if (kh !== '') { hibak.push(kh); } else {
                var E = 0, D;
                jogos.forEach(function (i) { E += sorok[i].netto_lista; });
                var tip = nv(kp, 'tipus', 'szazalek'), ert = parseFloat(kp.ertek) || 0;
                if (tip === 'fix' || tip === 'osszeg') { D = Math.min(E, Math.max(0, Math.trunc(ert))); }
                else { D = Math.min(E, Math.max(0, kerekf(E * Math.max(0, Math.min(100, ert)) / 100))); }
                var resz = {}, ossz = 0;
                jogos.forEach(function (i) {
                    var sz = D * sorok[i].netto_lista, alap = Math.floor(sz / E);
                    resz[i] = [alap, sz - alap * E]; ossz += alap;
                });
                var maradt = D - ossz, sorr = jogos.slice();
                sorr.sort(function (a, b) { return (resz[b][1] - resz[a][1]) || (a - b); });
                sorr.forEach(function (i, n) {
                    var k = resz[i][0] + (n < maradt ? 1 : 0);
                    sorok[i].kedvezmeny = k; sorok[i].netto = sorok[i].netto_lista - k;
                });
                kedvezmeny = D;
                kuponKi = { kod: kod, tipus: tip === 'osszeg' ? 'fix' : tip, ertek: (tip === 'fix' || tip === 'osszeg') ? Math.trunc(ert) : ert };
            }
        }

        var netto = listaO - kedvezmeny, afa = kerek(netto * afaK, 100), vanIsm = false;
        sorok.forEach(function (s) { if (s.ciklus !== 'egyszeri') { vanIsm = true; } });
        var licMod = alapC === null ? [] : (minden ? ['*'] : egyedi(benne.concat(modK)));

        return {
            sorok: sorok, ciklus: vanIsm ? ciklus : 'egyszeri', csomag: csomagK, modulok: licMod,
            felhasznalo: felh, telephely: telh, max_peldany: maxp, netto_lista: listaO, kedvezmeny: kedvezmeny,
            netto: netto, afa: afa, afa_kulcs: afaK, brutto: netto + afa, eves_megtakaritas: ciklus === 'eves' ? megt : 0,
            kupon: kuponKi, hibak: egyedi(hibak), figyelmeztetesek: egyedi(figy)
        };
    }

    function kulcsolt(l) { var o = {}; l.forEach(function (e) { if (obj(e) && e.kulcs) { o[String(e.kulcs)] = e; } }); return o; }

    var SDHAr = { szamol: szamol, ft: ft, kerek: kerek, eves: eves, kulcsolt: kulcsolt };

    if (typeof module === 'object' && module.exports) { module.exports = SDHAr; return; }
    gyoker.SDHAr = SDHAr;

    /* ===================== Felület ===================== */
    var D = gyoker.SDH_KALKULATOR;
    if (!D || !gyoker.document) { return; }
    var doc = gyoker.document;

    function el(tag, attr, gyerek) {
        var e = doc.createElement(tag);
        Object.keys(attr || {}).forEach(function (k) {
            if (k === 'szoveg') { e.textContent = attr[k]; } else if (k === 'osztaly') { e.className = attr[k]; } else { e.setAttribute(k, attr[k]); }
        });
        (gyerek || []).forEach(function (g) { if (g) { e.appendChild(typeof g === 'string' ? doc.createTextNode(g) : g); } });
        return e;
    }

    function csatol(gy) {
        if (gy.getAttribute('data-sdh-kesz')) { return; }
        gy.setAttribute('data-sdh-kesz', '1');
        var o = {};
        try { o = JSON.parse(gy.getAttribute('data-opciok') || '{}') || {}; } catch (e) { o = {}; }
        var kat = { csomagok: kulcsolt(D.csomagok || []), modulok: kulcsolt(D.modulok || []), szolgaltatasok: kulcsolt(D.szolgaltatasok || []), eves_kedvezmeny: D.eves_kedvezmeny, afa: D.afa, ma: D.ma };
        var ck = Object.keys(kat.csomagok);
        var all = { csomag: o.csomag && kat.csomagok[o.csomag] ? o.csomag : (ck.filter(function (k) { return kat.csomagok[k].kiemelt; })[0] || ck[0] || ''), ciklus: o.ciklus === 'havi' ? 'havi' : 'eves', felhasznalo: null, telephely: null, modulok: [], szolg: {} };
        var azon = 'sdh-kalk-' + Math.random().toString(36).slice(2, 8);

        gy.textContent = '';
        gy.classList.add('s-kalk');
        var urlap = el('form', { osztaly: 's-kalk-urlap', novalidate: '' });
        gy.appendChild(urlap);
        var osszeg = el('div', { osztaly: 's-kalk-osszeg', 'aria-live': 'polite' });
        gy.appendChild(osszeg);

        // Ciklus
        var cik = el('fieldset', { osztaly: 's-kalk-ciklus' }, [el('legend', { szoveg: 'Számlázás' })]);
        ['havi', 'eves'].forEach(function (c) {
            var i = el('input', { type: 'radio', name: azon + '-ciklus', value: c, id: azon + '-c-' + c });
            if (all.ciklus === c) { i.checked = true; }
            i.addEventListener('change', function () { all.ciklus = c; frissit(); });
            cik.appendChild(el('label', { osztaly: 's-pill', for: azon + '-c-' + c }, [i, c === 'havi' ? ' Havi' : ' Éves' + (int(D.eves_kedvezmeny) > 0 ? ' (−' + int(D.eves_kedvezmeny) + '%)' : '')]));
        });
        urlap.appendChild(cik);

        // Csomag
        var cs = el('fieldset', { osztaly: 's-kalk-csomagok' }, [el('legend', { szoveg: 'Csomag' })]);
        ck.forEach(function (k) {
            var c = kat.csomagok[k], i = el('input', { type: 'radio', name: azon + '-csomag', value: k, id: azon + '-cs-' + k });
            if (all.csomag === k) { i.checked = true; }
            i.addEventListener('change', function () { all.csomag = k; all.felhasznalo = null; all.telephely = null; modulRajz(); frissit(); });
            cs.appendChild(el('label', { osztaly: 's-kalk-csomag', for: azon + '-cs-' + k }, [i, el('strong', { szoveg: ' ' + (c.nev || k) }), c.leiras ? el('span', { osztaly: 's-halvany', szoveg: ' ' + c.leiras }) : null]));
        });
        urlap.appendChild(cs);

        // Létszám
        function szam(nev, cimke) {
            var i = el('input', { type: 'number', inputmode: 'numeric', min: '1', step: '1', id: azon + '-' + nev, osztaly: 's-mezo' });
            i.addEventListener('input', function () { all[nev] = i.value === '' ? null : i.value; frissit(); });
            return [el('label', { for: azon + '-' + nev, szoveg: cimke }), i];
        }
        var fs = el('fieldset', { osztaly: 's-kalk-letszam' }, [el('legend', { szoveg: 'Méret' })].concat(szam('felhasznalo', 'Felhasználók'), szam('telephely', 'Telephelyek')));
        urlap.appendChild(fs);

        var modDoboz = el('fieldset', { osztaly: 's-kalk-modulok' });
        urlap.appendChild(modDoboz);

        function modulRajz() {
            modDoboz.textContent = '';
            modDoboz.appendChild(el('legend', { szoveg: 'Kiegészítő modulok' }));
            var mods = (D.modulok || []).filter(function (m) {
                var c = kat.csomagok[all.csomag] || {}, l = lista(c.modulok);
                return l.indexOf('*') < 0 && l.indexOf(m.kulcs) < 0 && lista(m.csomagban).indexOf(all.csomag) < 0;
            });
            all.modulok = all.modulok.filter(function (k) { return mods.some(function (m) { return m.kulcs === k; }); });
            if (!mods.length) { modDoboz.appendChild(el('p', { osztaly: 's-halvany', szoveg: 'Ebben a csomagban minden modul benne van.' })); return; }
            mods.forEach(function (m) {
                var i = el('input', { type: 'checkbox', value: m.kulcs, id: azon + '-m-' + m.kulcs });
                if (all.modulok.indexOf(m.kulcs) >= 0) { i.checked = true; }
                i.addEventListener('change', function () {
                    all.modulok = all.modulok.filter(function (k) { return k !== m.kulcs; });
                    if (i.checked) { all.modulok.push(m.kulcs); }
                    frissit();
                });
                modDoboz.appendChild(el('label', { osztaly: 's-kalk-modul', for: azon + '-m-' + m.kulcs }, [i, ' ' + (m.nev || m.kulcs) + ' – ' + ft(int(m.havi)) + '/hó']));
            });
        }
        modulRajz();

        if ((D.szolgaltatasok || []).length) {
            var sz = el('fieldset', { osztaly: 's-kalk-szolg' }, [el('legend', { szoveg: 'Szolgáltatások' })]);
            D.szolgaltatasok.forEach(function (s) {
                var i = el('input', { type: 'checkbox', value: s.kulcs, id: azon + '-s-' + s.kulcs });
                i.addEventListener('change', function () { if (i.checked) { all.szolg[s.kulcs] = 1; } else { delete all.szolg[s.kulcs]; } frissit(); });
                var utotag = s.tipus === 'havi' ? '/hó' : (s.tipus === 'eves' ? '/év' : ' egyszeri');
                sz.appendChild(el('label', { osztaly: 's-kalk-modul', for: azon + '-s-' + s.kulcs }, [i, ' ' + (s.nev || s.kulcs) + ' – ' + ft(int(s.ar)) + utotag]));
            });
            urlap.appendChild(sz);
        }

        function tetelek() {
            var t = [];
            if (all.csomag) { t.push({ tipus: 'csomag', kulcs: all.csomag }); }
            all.modulok.forEach(function (k) { t.push({ tipus: 'modul', kulcs: k }); });
            Object.keys(all.szolg).forEach(function (k) { t.push({ tipus: 'szolgaltatas', kulcs: k, db: all.szolg[k] }); });
            return t;
        }
        function opc() {
            var o2 = { ciklus: all.ciklus };
            if (all.felhasznalo !== null) { o2.felhasznalo = all.felhasznalo; }
            if (all.telephely !== null) { o2.telephely = all.telephely; }
            return o2;
        }

        function frissit() {
            var e = szamol(kat, tetelek(), opc());
            var c = kat.csomagok[all.csomag] || {};
            var fi = doc.getElementById(azon + '-felhasznalo'), ti = doc.getElementById(azon + '-telephely');
            if (fi && all.felhasznalo === null) { fi.value = String(Math.max(1, int(c.felhasznalo_alap) || 1)); }
            if (ti && all.telephely === null) { ti.value = String(Math.max(1, int(c.telephely_alap) || 1)); }
            osszeg.textContent = '';
            var tb = el('table', { osztaly: 's-kalk-sorok' });
            e.sorok.forEach(function (s) {
                tb.appendChild(el('tr', {}, [el('td', { szoveg: s.nev + (s.mennyiseg > 1 ? ' × ' + s.mennyiseg : '') }), el('td', { osztaly: 's-szam', szoveg: ft(s.netto_lista) + (s.ciklus === 'havi' ? '/hó' : s.ciklus === 'eves' ? '/év' : '') })]));
            });
            osszeg.appendChild(tb);
            var ism = e.ciklus === 'havi' ? '/hó' : e.ciklus === 'eves' ? '/év' : '';
            osszeg.appendChild(el('p', { osztaly: 's-kalk-netto' }, [el('strong', { szoveg: ft(e.netto) + ism }), ' + ' + e.afa_kulcs + '% áfa = ' + ft(e.brutto) + ' bruttó']));
            if (e.eves_megtakaritas > 0) { osszeg.appendChild(el('p', { osztaly: 's-kalk-megtakaritas', szoveg: 'Éves díjjal ' + ft(e.eves_megtakaritas) + ' nettót spórolsz.' })); }
            e.hibak.concat(e.figyelmeztetesek).forEach(function (h) { osszeg.appendChild(el('p', { osztaly: 's-kalk-uzenet', role: 'status', szoveg: h })); });
            var gomb = el('button', { type: 'button', osztaly: 's-gomb s-gomb-fo', szoveg: 'Kosárba teszem' });
            if (e.hibak.length) { gomb.disabled = true; }
            gomb.addEventListener('click', function () {
                if (!gyoker.SDH || !gyoker.SDH.kosar) { return; }
                gyoker.SDH.kosar.beallit(tetelek(), opc());
                gyoker.SDH.uzen('A kosárba került.', 'ok');
                if (D.kosar_url) { gyoker.location.href = D.kosar_url; }
            });
            osszeg.appendChild(gomb);
        }
        frissit();
    }

    function indit() { Array.prototype.forEach.call(doc.querySelectorAll('[data-sdh="kalkulator"]'), csatol); }
    if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', indit); } else { indit(); }
}(typeof window !== 'undefined' ? window : this));
