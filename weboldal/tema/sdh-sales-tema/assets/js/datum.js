/*
 * Dátumválasztó (natív <input type="date"> helyett). Csatolás: <input type="text" data-datum
 *   [data-min="ÉÉÉÉ-HH-NN"] [data-max="…"] [data-tiltott="…,…"] [data-hetvege="0"] name="…">
 * A látható mező „ÉÉÉÉ.HH.NN.” formátumú (gépelhető is), a beküldött érték ÉÉÉÉ-HH-NN (rejtett mező, az eredeti name-mel).
 * Hozzáférhető naptár-rács (role="grid"): hétfő az első nap, magyar nevek, nyilak / PgUp / PgDn / Home / End / Enter / Esc.
 * Esemény: `change` a rejtett mezőn és `datum` (detail = ÉÉÉÉ-HH-NN) az eredeti mezőn. API: window.APP_DATUM.csatol(input).
 */
(function (w, d) {
    'use strict';

    var SZ = (w.APP_TEMA && w.APP_TEMA.datum_szoveg) || {};
    var HONAP = SZ.honapok || ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
    var NAP = SZ.napok || ['hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap'];
    var ROVID = SZ.rovid || ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];
    var n = 0;

    function k2(x) { return (x < 10 ? '0' : '') + x; }
    function iso(dt) { return dt.getFullYear() + '-' + k2(dt.getMonth() + 1) + '-' + k2(dt.getDate()); }
    function lathato(dt) { return dt.getFullYear() + '.' + k2(dt.getMonth() + 1) + '.' + k2(dt.getDate()) + '.'; }
    function beolvas(s) {
        var m = String(s || '').trim().match(/^(\d{4})[.\-\/ ]\s*(\d{1,2})[.\-\/ ]\s*(\d{1,2})\.?$/);
        if (!m) { return null; }
        var dt = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
        return dt.getMonth() === Number(m[2]) - 1 ? dt : null;
    }
    function ugyanaz(a, b) { return !!a && !!b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }
    function hetnap(dt) { return (dt.getDay() + 6) % 7; } // 0 = hétfő
    function plusz(dt, nap) { return new Date(dt.getFullYear(), dt.getMonth(), dt.getDate() + nap); }

    function csatol(input) {
        if (!input || input.hasAttribute('data-datum-kesz')) { return; }
        input.setAttribute('data-datum-kesz', '1');
        n++;
        var azon = 'datum-' + n;
        var min = beolvas((input.getAttribute('data-min') || '').replace(/-/g, '.'));
        var max = beolvas((input.getAttribute('data-max') || '').replace(/-/g, '.'));
        var tiltott = (input.getAttribute('data-tiltott') || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean);
        var hetvege = input.getAttribute('data-hetvege') !== '0';

        // A beküldött érték rejtett mezőben, az eredeti name-mel.
        var rejtett = d.createElement('input');
        rejtett.type = 'hidden';
        rejtett.name = input.name || '';
        input.removeAttribute('name');
        input.type = 'text';
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('placeholder', input.getAttribute('placeholder') || 'ÉÉÉÉ.HH.NN.');
        input.classList.add('s-mezo');

        var burok = d.createElement('div');
        burok.className = 's-datum';
        var sor = d.createElement('div');
        sor.className = 's-datum__mezo';
        input.parentNode.insertBefore(burok, input);
        sor.appendChild(input);
        burok.appendChild(sor);
        burok.appendChild(rejtett);

        var gomb = d.createElement('button');
        gomb.type = 'button';
        gomb.className = 's-ikongomb s-datum__gomb';
        gomb.setAttribute('aria-haspopup', 'dialog');
        gomb.setAttribute('aria-expanded', 'false');
        gomb.setAttribute('aria-controls', azon + '-panel');
        gomb.innerHTML = '<svg class="s-ikon" aria-hidden="true" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><span class="s-sr"></span>';
        gomb.querySelector('.s-sr').textContent = SZ.nyit || 'Naptár megnyitása';
        sor.appendChild(gomb);

        var hiba = d.createElement('p');
        hiba.className = 's-hibauzenet';
        hiba.id = azon + '-hiba';
        hiba.hidden = true;
        burok.appendChild(hiba);

        var panel = d.createElement('div');
        panel.className = 's-datum__panel';
        panel.id = azon + '-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'false');
        panel.setAttribute('aria-labelledby', azon + '-cim');
        panel.hidden = true;
        burok.appendChild(panel);

        var valasztott = beolvas(input.value) || beolvas((input.getAttribute('value') || '').replace(/-/g, '.'));
        var fokusz = valasztott || new Date();
        var ma = new Date();

        function valaszthato(dt) {
            if (min && dt < new Date(min.getFullYear(), min.getMonth(), min.getDate())) { return false; }
            if (max && dt > new Date(max.getFullYear(), max.getMonth(), max.getDate())) { return false; }
            if (!hetvege && hetnap(dt) >= 5) { return false; }
            return tiltott.indexOf(iso(dt)) === -1;
        }
        function beallit(dt, csendes) {
            valasztott = dt;
            input.value = dt ? lathato(dt) : '';
            rejtett.value = dt ? iso(dt) : '';
            hibaMutat('');
            if (!csendes) {
                rejtett.dispatchEvent(new Event('change', { bubbles: true }));
                input.dispatchEvent(new CustomEvent('datum', { bubbles: true, detail: rejtett.value }));
            }
        }
        function hibaMutat(sz) {
            hiba.hidden = !sz;
            hiba.textContent = sz;
            if (sz) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', hiba.id); } else { input.removeAttribute('aria-invalid'); input.removeAttribute('aria-describedby'); }
        }
        if (valasztott) { beallit(valasztott, true); }

        function rajzol() {
            var ev = fokusz.getFullYear(), ho = fokusz.getMonth();
            var elso = new Date(ev, ho, 1);
            var kezd = plusz(elso, -hetnap(elso));
            var html = '<div class="s-datum__fej">'
                + '<button type="button" class="s-ikongomb" data-lep="-1" aria-label="' + (SZ.elozo || 'Előző hónap') + '"><svg class="s-ikon" aria-hidden="true" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg></button>'
                + '<p class="s-datum__honap" id="' + azon + '-cim" aria-live="polite">' + ev + '. ' + HONAP[ho] + '</p>'
                + '<button type="button" class="s-ikongomb" data-lep="1" aria-label="' + (SZ.kovetkezo || 'Következő hónap') + '"><svg class="s-ikon" aria-hidden="true" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="m9 18 6-6-6-6"/></svg></button>'
                + '</div><table class="s-datum__racs" role="grid" aria-labelledby="' + azon + '-cim"><thead><tr>';
            for (var i = 0; i < 7; i++) { html += '<th scope="col" abbr="' + NAP[i] + '">' + ROVID[i] + '</th>'; }
            html += '</tr></thead><tbody>';
            for (var h = 0; h < 6; h++) {
                html += '<tr>';
                for (var c = 0; c < 7; c++) {
                    var dt = plusz(kezd, h * 7 + c);
                    var jo = valaszthato(dt);
                    var fok = ugyanaz(dt, fokusz);
                    var cls = 's-datum__nap' + (dt.getMonth() !== ho ? ' s-datum__nap--mas' : '') + (ugyanaz(dt, ma) ? ' s-datum__nap--ma' : '');
                    html += '<td role="gridcell"><button type="button" class="' + cls + '" data-nap="' + iso(dt) + '" tabindex="' + (fok ? '0' : '-1') + '"'
                        + ' aria-label="' + dt.getFullYear() + '. ' + HONAP[dt.getMonth()] + ' ' + dt.getDate() + '., ' + NAP[hetnap(dt)] + '"'
                        + ' aria-selected="' + (ugyanaz(dt, valasztott) ? 'true' : 'false') + '"'
                        + (ugyanaz(dt, ma) ? ' aria-current="date"' : '')
                        + (jo ? '' : ' aria-disabled="true"') + '>' + dt.getDate() + '</button></td>';
                }
                html += '</tr>';
            }
            html += '</tbody></table><div class="s-datum__lab"><button type="button" class="s-gomb s-gomb--szellem s-gomb--kicsi" data-ma>' + (SZ.ma || 'Ma') + '</button><button type="button" class="s-gomb s-gomb--szellem s-gomb--kicsi" data-torol>' + (SZ.torol || 'Törlés') + '</button></div>';
            panel.innerHTML = html;
        }
        function fokuszNap() { var b = panel.querySelector('[data-nap="' + iso(fokusz) + '"]'); if (b) { b.focus(); } }
        function nyit() { rajzol(); panel.hidden = false; gomb.setAttribute('aria-expanded', 'true'); fokuszNap(); }
        function zar(vissza) { if (panel.hidden) { return; } panel.hidden = true; gomb.setAttribute('aria-expanded', 'false'); if (vissza) { gomb.focus(); } }

        gomb.addEventListener('click', function () { if (panel.hidden) { fokusz = valasztott || fokusz; nyit(); } else { zar(true); } });
        panel.addEventListener('click', function (e) {
            var lep = e.target.closest('[data-lep]');
            if (lep) { fokusz = new Date(fokusz.getFullYear(), fokusz.getMonth() + Number(lep.getAttribute('data-lep')), 1); rajzol(); return; }
            if (e.target.closest('[data-ma]')) { fokusz = new Date(); if (valaszthato(fokusz)) { beallit(fokusz); zar(true); } else { rajzol(); fokuszNap(); } return; }
            if (e.target.closest('[data-torol]')) { beallit(null); zar(true); return; }
            var b = e.target.closest('[data-nap]');
            if (!b) { return; }
            if (b.getAttribute('aria-disabled') === 'true') { hibaMutat(SZ.tartomany || 'Ez a nap nem választható.'); return; }
            var p = b.getAttribute('data-nap').split('-');
            beallit(new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])));
            zar(true);
        });
        panel.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); zar(true); return; }
            if (!e.target.hasAttribute('data-nap')) { return; }
            var uj = null;
            switch (e.key) {
                case 'ArrowLeft': uj = plusz(fokusz, -1); break;
                case 'ArrowRight': uj = plusz(fokusz, 1); break;
                case 'ArrowUp': uj = plusz(fokusz, -7); break;
                case 'ArrowDown': uj = plusz(fokusz, 7); break;
                case 'Home': uj = plusz(fokusz, -hetnap(fokusz)); break;
                case 'End': uj = plusz(fokusz, 6 - hetnap(fokusz)); break;
                case 'PageUp': uj = new Date(fokusz.getFullYear() - (e.shiftKey ? 1 : 0), fokusz.getMonth() - (e.shiftKey ? 0 : 1), Math.min(fokusz.getDate(), 28)); break;
                case 'PageDown': uj = new Date(fokusz.getFullYear() + (e.shiftKey ? 1 : 0), fokusz.getMonth() + (e.shiftKey ? 0 : 1), Math.min(fokusz.getDate(), 28)); break;
                default: return;
            }
            e.preventDefault();
            var masHo = uj.getMonth() !== fokusz.getMonth();
            fokusz = uj;
            if (masHo) { rajzol(); } else {
                Array.prototype.forEach.call(panel.querySelectorAll('[data-nap]'), function (b) { b.tabIndex = b.getAttribute('data-nap') === iso(fokusz) ? 0 : -1; });
            }
            fokuszNap();
        });
        d.addEventListener('click', function (e) { if (!panel.hidden && !burok.contains(e.target)) { zar(false); } });
        input.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown' && e.altKey) { e.preventDefault(); gomb.click(); } });
        input.addEventListener('change', function () {
            if (!input.value.trim()) { beallit(null); return; }
            var dt = beolvas(input.value);
            if (!dt) { hibaMutat(SZ.hibas || 'Érvénytelen dátum.'); rejtett.value = ''; return; }
            if (!valaszthato(dt)) { hibaMutat(SZ.tartomany || 'Ez a nap nem választható.'); rejtett.value = ''; return; }
            fokusz = dt;
            beallit(dt);
        });
    }

    w.APP_DATUM = { csatol: csatol, beolvas: beolvas, iso: iso };
})(window, document);
