/* Értékesítés – admin-felület: váz, közös komponensek (popup, kétlépcsős gomb, űrlapmezők, parancspaletta),
   és az értékesítési panelek (Áttekintés, Rendelések, Vevők, Leadek, Foglalások, Napló). Külső könyvtár nélkül.
   A tartalom-panelek: admin-tartalom.js, a beállítás-panelek: admin-beallitas.js. Külső panel:
   window.PA.panel('kulcs', async (hova, param) => {…}). */
(() => {
    'use strict';

    const B = window.PA_BOOT || {};
    const $ = (s, el = document) => el.querySelector(s);
    const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
    const e = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /* ------------------------------------------------------------------ Ikonok (admin) */

    const P = {
        attekintes: '<rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/>',
        rendeles: '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
        vevo: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        lead: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        naptar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        oldal: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
        elem: '<path d="M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>',
        menu: '<path d="M4 6h16M4 12h16M4 18h10"/>',
        arculat: '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.65-.75 1.65-1.69 0-.44-.18-.84-.44-1.13-.29-.29-.44-.65-.44-1.13a1.64 1.64 0 0 1 1.67-1.67h2c3.05 0 5.56-2.5 5.56-5.55C21.97 6.01 17.46 2 12 2z"/>',
        beallitas: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-2.82 1.17V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 3.6 14H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 14 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        naplo: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        export: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        feltolt: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        kereses: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        nap: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        hold: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/>',
        wp: '<circle cx="12" cy="12" r="10"/><path d="M3.5 9h17M3.5 15h17M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
        hamburger: '<path d="M4 6h16M4 12h16M4 18h16"/>',
        x: '<path d="M18 6 6 18M6 6l12 12"/>',
        plusz: '<path d="M12 5v14M5 12h14"/>',
        pipa: '<path d="M20 6 9 17l-5-5"/>',
        figyel: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
        info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        penz: '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
        szamla: '<path d="M4 2v20l3-2 3 2 3-2 3 2 3-2 2 2V2l-2 2-3-2-3 2-3-2-3 2-3-2z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        kulcs: '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
        lanc: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        ujra: '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        email: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
        masol: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        kulso: '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/>',
        kilep: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        fogo: '<circle cx="9" cy="6" r="1.2"/><circle cx="15" cy="6" r="1.2"/><circle cx="9" cy="12" r="1.2"/><circle cx="15" cy="12" r="1.2"/><circle cx="9" cy="18" r="1.2"/><circle cx="15" cy="18" r="1.2"/>',
        fel: '<path d="m18 15-6-6-6 6"/>',
        le: '<path d="m6 9 6 6 6-6"/>',
        jobbra: '<path d="m9 18 6-6-6-6"/>',
        balra: '<path d="m15 18-6-6 6-6"/>',
        torol: '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6"/>',
        duplikal: '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
        szem: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
        szem_ki: '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61M2 2l20 20"/>',
        asztal: '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
        mobil: '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>',
        mentes: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
        kep: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
        link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        lista: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        szamlista: '<path d="M10 6h11M10 12h11M10 18h11M4 6h1v4M4 10h2M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
        kod: '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
        ceg: '<path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/>',
        kartya: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        kozpont: '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/>',
        szikra: '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3z"/>',
        pajzs: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        visszaall: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        letolt: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        ora: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    };
    const I = (n, cls = '') => {
        const ut = P[n] || (B.ikonok && B.ikonok[n]) || P.info;
        return `<svg class="${e(cls)}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${ut}</svg>`;
    };
    /** Webhely-ikon (a szekciók / elemek ikonjai: a bővítmény ikonkészlete). */
    const WI = (n) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${(B.ikonok && B.ikonok[n]) || P[n] || P.info}</svg>`;

    /* ------------------------------------------------------------------ Formázás */

    const nf = new Intl.NumberFormat('hu-HU');
    const szam = (n) => nf.format(Math.round(Number(n) || 0));
    const ft = (n) => szam(n) + '\u00a0Ft';
    const brutto = (netto) => Math.round((Number(netto) || 0) * (100 + (Number(B.afa) || 0)) / 100);
    const ket = (n) => String(n).padStart(2, '0');
    const datum = (ts) => { if (!ts) return '–'; const d = new Date(ts * 1000); return `${d.getFullYear()}.${ket(d.getMonth() + 1)}.${ket(d.getDate())}.`; };
    const datumIdo = (ts) => { if (!ts) return '–'; const d = new Date(ts * 1000); return `${datum(ts)} ${ket(d.getHours())}:${ket(d.getMinutes())}`; };
    const relativ = (ts) => {
        if (!ts) return '–';
        const d = Math.floor(Date.now() / 1000) - ts;
        if (d < 60) return 'most';
        if (d < 3600) return `${Math.round(d / 60)} perce`;
        if (d < 86400) return `${Math.round(d / 3600)} órája`;
        if (d < 86400 * 14) return `${Math.round(d / 86400)} napja`;
        return datum(ts);
    };

    const ALLAPOT = { uj: ['Új', 'szurke'], dijbekero: ['Díjbekérő', 'figyel'], fizetesre_var: ['Fizetésre vár', 'figyel'], fizetve: ['Fizetve', 'kiemelo'], kiszolgalva: ['Kiszolgálva', 'ok'], sikertelen: ['Sikertelen', 'hiba'], torolve: ['Törölve', 'szurke'], visszaterites: ['Visszatérítés', 'hiba'] };
    const FIZMOD = { atutalas: 'Átutalás', barion: 'Barion', simplepay: 'SimplePay' };
    const RTIPUS = { uj: 'Új', megujitas: 'Megújítás', bovites: 'Bővítés', szolgaltatas: 'Szolgáltatás' };
    const CIKLUS = { havi: 'havi', eves: 'éves', egyszeri: 'egyszeri' };
    const LEAD_FORRAS = { kapcsolat: 'Kapcsolat', ajanlat: 'Ajánlatkérés', szikra: 'Szikra', egyedi: 'Egyedi igény', partner: 'Partner', demo: 'Demó' };
    const LANC = { ok: ['ok', 'pipa'], kihagyva: ['ok', 'pipa'], hiba: ['hiba', 'figyel'], var: ['var', 'ora'], fut: ['var', 'ujra'], nincs: ['', 'ora'] };
    const SZINT = { info: 'szurke', siker: 'ok', figyel: 'figyel', hiba: 'hiba' };
    /** A lead-adat mezőkulcsainak magyar felirata (ismeretlen kulcs: „Kulcs neve”). */
    const LEAD_MEZO = { nev: 'Név', kapcsolattarto: 'Kapcsolattartó', ceg: 'Cég', cegnev: 'Cég', telefon: 'Telefon', email: 'E-mail', uzenet: 'Üzenet', targy: 'Tárgy', csomag: 'Csomag', modulok: 'Modulok', modul: 'Modul', telephely: 'Telephelyek', telephelyek: 'Telephelyek', felhasznalo: 'Felhasználók', felhasznalok: 'Felhasználók', iparag: 'Iparág', varos: 'Város', cim: 'Cím', weboldal: 'Weboldal', adoszam: 'Adószám', hatarido: 'Határidő', keret: 'Keret', koltsegkeret: 'Költségkeret', leiras: 'Leírás', forras_oldal: 'Forrás oldal', oldal: 'Oldal', utm: 'Kampány (UTM)', hozzajarulas: 'Hozzájárulás', adatkezeles: 'Adatkezelés elfogadva', hirlevel: 'Hírlevél', nyelv: 'Nyelv', idopont: 'Időpont', beszelgetes: 'Beszélgetés', osszefoglalo: 'Összefoglaló' };
    const mezoNev = (k) => LEAD_MEZO[k] || (String(k).charAt(0).toUpperCase() + String(k).slice(1)).replace(/_/g, ' ');

    const cimke = (szoveg, tipus = 'szurke', extra = '') => `<span class="sa-cimke sa-cimke--${e(tipus)} ${extra}">${e(szoveg)}</span>`;
    const allapotCimke = (a) => { const x = ALLAPOT[a] || [a, 'szurke']; return cimke(x[0], x[1]); };
    const van = (ut) => (B.utak || []).includes(ut);

    /* ------------------------------------------------------------------ API */

    async function api(ut, { method = 'GET', body = null, query = null, nyers = false } = {}) {
        let url = B.rest + ut.replace(/^\//, '');
        const q = Object.assign({}, query || {});
        if (S.nyelv && S.nyelv !== 'hu' && /admin\/(elem|menu|oldal)/.test(ut)) q.nyelv = S.nyelv;
        const qs = new URLSearchParams(Object.entries(q).filter(([, v]) => v !== '' && v !== null && v !== undefined)).toString();
        if (qs) url += (url.includes('?') ? '&' : '?') + qs;
        const opt = { method, headers: { 'X-WP-Nonce': B.nonce }, credentials: 'same-origin' };
        if (body !== null) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
        const r = await fetch(url, opt);
        if (nyers) { if (!r.ok) throw new Error(`Hiba (${r.status})`); return r; }
        let j = null;
        try { j = await r.json(); } catch (x) { j = null; }
        if (!r.ok) {
            if (j && j.code === 'rest_cookie_invalid_nonce') { jelez('A munkamenet lejárt – újratöltöm az oldalt.', 'figyel'); setTimeout(() => location.reload(), 1200); }
            const h = new Error((j && j.message) || `Hiba (${r.status})`);
            h.status = r.status; h.adat = j && j.data;
            throw h;
        }
        return j;
    }

    /* ------------------------------------------------------------------ Értesítés, élő régió */

    function jelez(szoveg, tipus = 'ok') {
        let box = $('.sa-ertesitesek');
        if (!box) { box = document.createElement('div'); box.className = 'sa-ertesitesek'; box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite'); document.body.appendChild(box); }
        const el = document.createElement('div');
        el.className = `sa-ertesites sa-ertesites--${tipus}`;
        el.textContent = szoveg;
        box.appendChild(el);
        setTimeout(() => { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 320); }, tipus === 'hiba' ? 6000 : 3800);
    }

    function bemond(szoveg) {
        let r = $('#sa-elo');
        if (!r) { r = document.createElement('div'); r.id = 'sa-elo'; r.className = 'sa-sr'; r.setAttribute('aria-live', 'assertive'); document.body.appendChild(r); }
        r.textContent = '';
        setTimeout(() => { r.textContent = szoveg; }, 30);
    }

    async function masol(szoveg) {
        try { await navigator.clipboard.writeText(szoveg); jelez('Vágólapra másolva.'); }
        catch (x) { const t = document.createElement('textarea'); t.value = szoveg; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); jelez('Vágólapra másolva.'); } catch (y) { jelez('Nem sikerült másolni.', 'hiba'); } t.remove(); }
    }

    function letolt(nev, tartalom, tipus = 'application/json') {
        const blob = tartalom instanceof Blob ? tartalom : new Blob([tartalom], { type: tipus });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = nev;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    /* ------------------------------------------------------------------ Kétlépcsős gomb (confirm() helyett) */

    document.addEventListener('click', (ev) => {
        const g = ev.target.closest('[data-ketlepcsos]');
        if (!g || g.disabled) return;
        if (!g.classList.contains('is-elesitve')) {
            ev.preventDefault();
            ev.stopImmediatePropagation();
            $$('[data-ketlepcsos].is-elesitve').forEach(lefegyverez);
            g.classList.add('is-elesitve');
            g.dataset.eredeti = g.innerHTML;
            g.dataset.eredetiCim = g.getAttribute('aria-label') || '';
            const szoveg = g.dataset.ketlepcsos || 'Biztos? Kattints újra';
            if (g.classList.contains('sa-ikon-gomb')) { g.setAttribute('aria-label', szoveg); g.title = szoveg; } else { g.innerHTML = e(szoveg); }
            bemond(szoveg);
            g._idozito = setTimeout(() => lefegyverez(g), 4000);
            return;
        }
        lefegyverez(g);
    }, true);

    function lefegyverez(g) {
        clearTimeout(g._idozito);
        if (!g.classList.contains('is-elesitve')) return;
        g.classList.remove('is-elesitve');
        if (g.classList.contains('sa-ikon-gomb')) { if (g.dataset.eredetiCim) g.setAttribute('aria-label', g.dataset.eredetiCim); g.title = g.dataset.eredetiCim || ''; } else if (g.dataset.eredeti !== undefined) { g.innerHTML = g.dataset.eredeti; }
    }

    /** Gomb töltés közben (dupla katt ellen). */
    async function tolt(g, fn) {
        if (!g || g.classList.contains('is-tolt')) return undefined;
        g.classList.add('is-tolt');
        g.setAttribute('aria-busy', 'true');
        try { return await fn(); } finally { g.classList.remove('is-tolt'); g.removeAttribute('aria-busy'); }
    }

    /* ------------------------------------------------------------------ Popup (fókuszkezeléssel) */

    let popupSzam = 0;
    const FOKUSZALHATO = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [contenteditable="true"], [tabindex]:not([tabindex="-1"])';

    function popup({ cim, test = '', lab = '', meret = '', nyit = null, bezaraskor = null }) {
        const elozo = document.activeElement;
        const id = 'sa-popup-' + (++popupSzam);
        const hatter = document.createElement('div');
        hatter.className = 'sa-popup-hatter';
        hatter.innerHTML = `<div class="sa-popup ${meret ? 'sa-popup--' + e(meret) : ''}" role="dialog" aria-modal="true" aria-labelledby="${id}">
            <div class="sa-popup__fej"><h2 class="sa-popup__cim" id="${id}">${e(cim)}</h2><span class="sa-ter"></span><button type="button" class="sa-ikon-gomb" data-bezar aria-label="Bezárás">${I('x')}</button></div>
            <div class="sa-popup__test">${test}</div>${lab ? `<div class="sa-popup__lab">${lab}</div>` : ''}</div>`;
        const p = $('.sa-popup', hatter);
        const nyitva = () => document.body.classList.contains('modal-open') || $('.sa-naptar, .sa-ikonracs');
        const bezar = () => {
            if (!hatter.isConnected) return;
            if (bezaraskor && bezaraskor() === false) return;
            hatter.remove();
            document.removeEventListener('keydown', bill, true);
            if (elozo && elozo.isConnected && typeof elozo.focus === 'function') elozo.focus();
        };
        const bill = (ev) => {
            if (hatter !== $$('.sa-popup-hatter').pop()) return;
            if (ev.key === 'Escape' && !nyitva()) { ev.preventDefault(); bezar(); }
            if (ev.key === 'Tab' && !document.body.classList.contains('modal-open')) {
                const f = $$(FOKUSZALHATO, p).filter((x) => x.offsetParent !== null);
                if (!f.length) return;
                if (ev.shiftKey && document.activeElement === f[0]) { ev.preventDefault(); f[f.length - 1].focus(); }
                else if (!ev.shiftKey && document.activeElement === f[f.length - 1]) { ev.preventDefault(); f[0].focus(); }
                else if (!p.contains(document.activeElement)) { ev.preventDefault(); f[0].focus(); }
            }
        };
        hatter.addEventListener('mousedown', (ev) => { if (ev.target === hatter) bezar(); });
        hatter.addEventListener('click', (ev) => { if (ev.target.closest('[data-bezar]')) bezar(); });
        document.addEventListener('keydown', bill, true);
        document.body.appendChild(hatter);
        if (nyit) nyit(p, bezar);
        setTimeout(() => {
            const elso = $('[data-autofokusz]', p) || $$('input:not([type=hidden]):not([readonly]):not([type=checkbox]), textarea, select, [contenteditable="true"]', p).find((x) => x.offsetParent !== null) || $('[data-bezar]', p);
            if (elso) elso.focus();
        }, 30);
        return { el: p, bezar };
    }

    const popupHiba = (p, uzenet) => {
        let h = $('.sa-popup__hiba', p);
        if (!h) { h = document.createElement('span'); h.className = 'sa-popup__hiba'; h.setAttribute('role', 'alert'); const lab = $('.sa-popup__lab', p); if (lab) lab.prepend(h); else $('.sa-popup__test', p).prepend(h); }
        h.textContent = uzenet;
    };

    /* ------------------------------------------------------------------ Lapfülek */

    function fulek(gyoker, valtaskor = null) {
        const sav = $('.sa-fulek', gyoker);
        if (!sav) return;
        const gombok = $$('[role="tab"]', sav);
        const valt = (g, fokusz = false) => {
            gombok.forEach((x) => { const be = x === g; x.setAttribute('aria-selected', be ? 'true' : 'false'); x.tabIndex = be ? 0 : -1; const pn = gyoker.querySelector('#' + x.getAttribute('aria-controls')); if (pn) pn.hidden = !be; });
            if (fokusz) g.focus();
            if (valtaskor) valtaskor(g.dataset.ful);
        };
        sav.addEventListener('click', (ev) => { const g = ev.target.closest('[role="tab"]'); if (g) valt(g); });
        sav.addEventListener('keydown', (ev) => {
            const i = gombok.indexOf(document.activeElement);
            if (i < 0) return;
            if (ev.key === 'ArrowRight') { ev.preventDefault(); valt(gombok[(i + 1) % gombok.length], true); }
            if (ev.key === 'ArrowLeft') { ev.preventDefault(); valt(gombok[(i - 1 + gombok.length) % gombok.length], true); }
            if (ev.key === 'Home') { ev.preventDefault(); valt(gombok[0], true); }
            if (ev.key === 'End') { ev.preventDefault(); valt(gombok[gombok.length - 1], true); }
        });
        return { valt: (kulcs) => { const g = gombok.find((x) => x.dataset.ful === kulcs); if (g) valt(g); } };
    }

    let fulSzam = 0;
    /** Lapfül-sáv + panelek HTML-je: [[kulcs, cím, html], …]. */
    function fulHtml(lista, aktiv = null) {
        const n = ++fulSzam;
        const a = aktiv || lista[0][0];
        return `<div class="sa-fulek" role="tablist">${lista.map(([k, c]) => `<button type="button" role="tab" id="sa-ful-${n}-${e(k)}" data-ful="${e(k)}" aria-controls="sa-fp-${n}-${e(k)}" aria-selected="${k === a}" tabindex="${k === a ? 0 : -1}">${c}</button>`).join('')}</div>`
            + lista.map(([k, , h]) => `<div class="sa-ful-panel" role="tabpanel" id="sa-fp-${n}-${e(k)}" aria-labelledby="sa-ful-${n}-${e(k)}" ${k === a ? '' : 'hidden'}>${h}</div>`).join('');
    }

    /* ------------------------------------------------------------------ Lebegő réteg (ikonrács, naptár) */

    function lebego(el, horgony, bezaraskor) {
        document.body.appendChild(el);
        const r = horgony.getBoundingClientRect();
        const w = el.offsetWidth, h = el.offsetHeight;
        let x = Math.min(r.left, window.innerWidth - w - 8);
        let y = r.bottom + 6;
        if (y + h > window.innerHeight - 8) y = Math.max(8, r.top - h - 6);
        el.style.left = Math.max(8, x) + 'px';
        el.style.top = y + 'px';
        const kint = (ev) => { if (!el.contains(ev.target) && !horgony.contains(ev.target)) zar(); };
        const bill = (ev) => { if (ev.key === 'Escape') { ev.stopPropagation(); ev.preventDefault(); zar(); horgony.focus(); } };
        const zar = () => { if (!el.isConnected) return; el.remove(); document.removeEventListener('mousedown', kint, true); document.removeEventListener('keydown', bill, true); if (bezaraskor) bezaraskor(); };
        setTimeout(() => { document.addEventListener('mousedown', kint, true); document.addEventListener('keydown', bill, true); }, 0);
        return zar;
    }

    /* ------------------------------------------------------------------ Tartalom-elemek listája (választókhoz) */

    const elemCache = {};
    function elemLista(tipus, friss = false) {
        if (!elemCache[tipus] || friss) elemCache[tipus] = api('admin/elemek', { query: { tipus } }).then((r) => (r.lista || []).map((x) => ({ kulcs: x.kulcs, cim: x.cim || x.kulcs, aktiv: x.aktiv }))).catch(() => []);
        return elemCache[tipus];
    }

    /* ------------------------------------------------------------------ Űrlapmezők (séma alapján) */

    const kiolvas = (o, k) => {
        if (!o) return undefined;
        if (Object.prototype.hasOwnProperty.call(o, k)) return o[k];
        return k.split('.').reduce((a, x) => (a == null ? undefined : a[x]), o);
    };
    const beir = (o, k, v) => {
        const d = k.split('.');
        let r = o;
        d.slice(0, -1).forEach((x) => { if (typeof r[x] !== 'object' || r[x] === null || Array.isArray(r[x])) r[x] = {}; r = r[x]; });
        r[d[d.length - 1]] = v;
    };

    let mezoSzam = 0;
    const kapcsolo = (id, be, cimke, extra = '') => `<label class="sa-kapcsolo"><input type="checkbox" id="${e(id)}" ${be ? 'checked' : ''} aria-label="${e(cimke)}" ${extra}><span aria-hidden="true"></span></label>`;

    /**
     * Egy mező vezérlője: { el, ertek(), ellenoriz()? }.
     * ctx: { szerkeszt: bool (meglévő elem – a kulcs nem írható), valtozas: fn }
     */
    function mezoVezerlo(m, v, ctx = {}) {
        const id = 'sa-m-' + (++mezoSzam);
        const w = document.createElement('div');
        w.className = 'sa-mezo' + (m.fel ? ' sa-mezo--fel' : ' sa-mezo--szeles');
        w.dataset.mezo = m.k;
        const cimHtml = `<label class="sa-mezo__cim" for="${id}">${e(m.c)}${m.kotelezo ? '<em aria-hidden="true">*</em>' : ''}${m.max && ['szoveg', 'hosszu'].includes(m.t) && m.max >= 120 ? `<span class="sa-szamlalo" data-szamlalo aria-hidden="true"></span>` : ''}</label>`;
        const sugo = m.s ? `<span class="sa-mezo__sugo" id="${id}-s">${e(m.s)}</span>` : '';
        const desc = m.s ? `aria-describedby="${id}-s"` : '';
        const valt = () => { if (ctx.valtozas) ctx.valtozas(); };
        let ertek = () => undefined;
        const req = m.kotelezo ? 'aria-required="true"' : '';

        switch (m.t) {
            case 'szoveg': case 'email': case 'url': case 'tizedes': {
                const tipus = m.t === 'email' ? 'email' : m.t === 'url' ? 'url' : 'text';
                const ro = m.kulcs && ctx.szerkeszt ? 'readonly' : '';
                w.innerHTML = cimHtml + `<input class="sa-input ${m.kulcs ? 'sa-input--mono' : ''}" id="${id}" type="${tipus}" ${m.t === 'tizedes' ? 'inputmode="decimal"' : ''} value="${e(v ?? '')}" ${m.max ? `maxlength="${Number(m.max)}"` : ''} ${ro} ${req} ${desc} autocomplete="off">` + sugo;
                const inp = $('input', w);
                const sz = $('[data-szamlalo]', w);
                const frissit = () => { if (sz) { sz.textContent = `${inp.value.length}/${m.max}`; sz.classList.toggle('is-sok', inp.value.length > m.max * 0.9); } };
                inp.addEventListener('input', () => { frissit(); valt(); });
                frissit();
                ertek = () => (m.t === 'tizedes' ? (inp.value.trim() === '' ? '' : Number(inp.value.replace(',', '.'))) : inp.value);
                break;
            }
            case 'hosszu': {
                w.innerHTML = cimHtml + `<textarea class="sa-textarea" id="${id}" rows="${m.max && m.max > 500 ? 4 : 3}" ${m.max ? `maxlength="${Number(m.max)}"` : ''} ${req} ${desc}>${e(v ?? '')}</textarea>` + sugo;
                const t = $('textarea', w);
                const sz = $('[data-szamlalo]', w);
                const frissit = () => { if (sz) sz.textContent = `${t.value.length}/${m.max}`; };
                t.addEventListener('input', () => { frissit(); valt(); });
                frissit();
                ertek = () => t.value;
                break;
            }
            case 'szam': {
                w.innerHTML = cimHtml + `<input class="sa-input" id="${id}" inputmode="numeric" value="${e(v ?? '')}" ${req} ${desc} autocomplete="off">` + (m.penz ? `<span class="sa-brutto" data-brutto aria-live="polite"></span>` : '') + sugo;
                const inp = $('input', w);
                const br = $('[data-brutto]', w);
                const frissit = () => { if (br) { const n = Number(String(inp.value).replace(/\s/g, '')) || 0; br.innerHTML = `Bruttó: <b>${e(ft(brutto(n)))}</b> · ${e(B.afa)}% áfa`; } };
                inp.addEventListener('input', () => { inp.value = inp.value.replace(/[^\d-]/g, ''); frissit(); valt(); });
                frissit();
                ertek = () => (inp.value.trim() === '' ? '' : parseInt(inp.value, 10));
                break;
            }
            case 'html': {
                w.innerHTML = `<span class="sa-mezo__cim" id="${id}-c">${e(m.c)}</span><div class="sa-rt">
                    <div class="sa-rt__sav" role="toolbar" aria-label="Formázás">
                        <button type="button" data-p="bold" aria-label="Félkövér"><b>B</b></button>
                        <button type="button" data-p="italic" aria-label="Dőlt"><i>I</i></button>
                        ${m.szuk ? '' : '<button type="button" data-p="h3" aria-label="Alcím">H</button><button type="button" data-p="p" aria-label="Bekezdés">¶</button>'}
                        <button type="button" data-p="insertUnorderedList" aria-label="Felsorolás">${I('lista')}</button>
                        <button type="button" data-p="insertOrderedList" aria-label="Számozott lista">${I('szamlista')}</button>
                        <button type="button" data-p="link" aria-label="Hivatkozás">${I('link')}</button>
                        <button type="button" data-p="removeFormat" aria-label="Formázás törlése">${I('x')}</button>
                        <span class="sa-ter"></span>
                        <button type="button" data-p="forras" aria-label="HTML-forrás" aria-pressed="false">${I('kod')}</button>
                    </div>
                    <div class="sa-rt__ter" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="${id}-c" id="${id}" data-hely="Írd ide a szöveget…"></div>
                    <textarea class="sa-textarea" hidden aria-label="${e(m.c)} – HTML-forrás" style="border:0;border-radius:0;min-height:140px;font-family:var(--sa-mono);font-size:12px"></textarea>
                </div>` + sugo;
                const ter = $('.sa-rt__ter', w), forras = $('textarea', w);
                ter.innerHTML = String(v ?? '');
                let tartomany = null;
                ter.addEventListener('input', valt);
                ter.addEventListener('blur', () => { const s = window.getSelection(); if (s.rangeCount) tartomany = s.getRangeAt(0).cloneRange(); });
                ter.addEventListener('paste', (ev) => { ev.preventDefault(); document.execCommand('insertText', false, (ev.clipboardData || window.clipboardData).getData('text/plain')); });
                forras.addEventListener('input', valt);
                $('.sa-rt__sav', w).addEventListener('mousedown', (ev) => { if (ev.target.closest('button') && ev.target.closest('[data-p]').dataset.p !== 'link') ev.preventDefault(); });
                $('.sa-rt__sav', w).addEventListener('click', (ev) => {
                    const g = ev.target.closest('[data-p]');
                    if (!g) return;
                    const p = g.dataset.p;
                    if (p === 'forras') {
                        const be = forras.hidden;
                        if (be) { forras.value = ter.innerHTML; } else { ter.innerHTML = forras.value; }
                        forras.hidden = !be; ter.hidden = be; g.setAttribute('aria-pressed', String(be));
                        (be ? forras : ter).focus();
                        return;
                    }
                    if (!forras.hidden) return;
                    if (p === 'link') {
                        const sav = $('.sa-rt__sav', w);
                        if ($('[data-linkmezo]', sav)) return;
                        const s = window.getSelection();
                        const rng = s.rangeCount && ter.contains(s.anchorNode) ? s.getRangeAt(0).cloneRange() : tartomany;
                        const d = document.createElement('span');
                        d.className = 'sa-sor';
                        d.style.width = '100%';
                        d.innerHTML = `<input class="sa-input sa-input--kicsi" data-linkmezo placeholder="https://… vagy /oldal" aria-label="Hivatkozás címe"><button type="button" class="sa-gomb sa-gomb--kicsi" data-linkok>Beszúrás</button>`;
                        sav.appendChild(d);
                        const li = $('input', d);
                        const kesz = (ok) => {
                            if (ok && li.value.trim() && rng) { ter.focus(); const s2 = window.getSelection(); s2.removeAllRanges(); s2.addRange(rng); document.execCommand('createLink', false, li.value.trim()); valt(); }
                            d.remove(); ter.focus();
                        };
                        li.addEventListener('keydown', (x) => { if (x.key === 'Enter') { x.preventDefault(); kesz(true); } if (x.key === 'Escape') { x.preventDefault(); x.stopPropagation(); kesz(false); } });
                        $('[data-linkok]', d).addEventListener('click', () => kesz(true));
                        li.focus();
                        return;
                    }
                    ter.focus();
                    if (p === 'h3' || p === 'p') document.execCommand('formatBlock', false, p === 'h3' ? '<h3>' : '<p>');
                    else document.execCommand(p, false, null);
                    valt();
                });
                ertek = () => { const h = forras.hidden ? ter.innerHTML : forras.value; return h.replace(/^(<br\s*\/?>|\s)+$/i, ''); };
                break;
            }
            case 'kep': {
                w.innerHTML = cimHtml + `<div class="sa-kep"><span class="sa-kep__elo" aria-hidden="true"></span><input class="sa-input" id="${id}" type="url" value="${e(v ?? '')}" placeholder="https://…" ${desc}>${B.media && window.wp && wp.media ? `<button type="button" class="sa-gomb" data-media>${I('kep')}Médiatár</button>` : ''}</div>` + sugo;
                const inp = $('input', w), elo = $('.sa-kep__elo', w);
                const frissit = () => { elo.style.backgroundImage = inp.value ? `url("${inp.value.replace(/["\\]/g, '')}")` : ''; };
                inp.addEventListener('input', () => { frissit(); valt(); });
                frissit();
                const mg = $('[data-media]', w);
                if (mg) mg.addEventListener('click', () => {
                    const keret = wp.media({ title: m.c, multiple: false, library: { type: 'image' }, button: { text: 'Kiválasztás' } });
                    keret.on('select', () => { const a = keret.state().get('selection').first().toJSON(); inp.value = a.url || ''; frissit(); valt(); });
                    keret.on('close', () => setTimeout(() => mg.focus(), 50));
                    keret.open();
                });
                ertek = () => inp.value.trim();
                break;
            }
            case 'ikon': {
                let ik = String(v ?? '');
                w.innerHTML = cimHtml + `<button type="button" class="sa-ikonvalaszto" id="${id}" aria-haspopup="listbox" ${desc}></button>` + sugo;
                const g = $('button', w);
                const rajzol = () => { g.innerHTML = ik ? `${WI(ik)}<span>${e(ik)}</span>` : '<span class="sa-halvany">Nincs ikon – válassz</span>'; };
                rajzol();
                g.addEventListener('click', () => {
                    const r = document.createElement('div');
                    r.className = 'sa-ikonracs';
                    r.setAttribute('role', 'listbox');
                    r.setAttribute('aria-label', 'Ikonok');
                    const nevek = Object.keys(B.ikonok || {});
                    r.innerHTML = `<button type="button" class="sa-ikonracs__nincs" data-ik="" role="option">Nincs ikon</button>` + nevek.map((n) => `<button type="button" role="option" data-ik="${e(n)}" aria-selected="${n === ik}" aria-label="${e(n)}" title="${e(n)}">${WI(n)}</button>`).join('');
                    const zar = lebego(r, g);
                    const gombok = $$('button', r);
                    (gombok.find((x) => x.dataset.ik === ik) || gombok[0]).focus();
                    r.addEventListener('click', (ev) => { const x = ev.target.closest('[data-ik]'); if (!x) return; ik = x.dataset.ik; rajzol(); valt(); zar(); g.focus(); });
                    r.addEventListener('keydown', (ev) => {
                        const i = gombok.indexOf(document.activeElement);
                        const lep = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 7, ArrowUp: -7 }[ev.key];
                        if (lep && i >= 0) { ev.preventDefault(); const j = Math.max(0, Math.min(gombok.length - 1, i + lep)); gombok[j].focus(); }
                    });
                });
                ertek = () => ik;
                break;
            }
            case 'valaszto': {
                const o = m.o || {};
                const akt = v ?? m.alap ?? Object.keys(o)[0] ?? '';
                w.innerHTML = cimHtml + `<select class="sa-select" id="${id}" ${desc}>${Object.entries(o).map(([k, c]) => `<option value="${e(k)}" ${String(k) === String(akt) ? 'selected' : ''}>${e(c)}</option>`).join('')}</select>` + sugo;
                const s = $('select', w);
                s.addEventListener('change', valt);
                ertek = () => s.value;
                break;
            }
            case 'kapcsolo': {
                const be = v === undefined || v === null || v === '' ? !!m.alap : !!v && v !== '0';
                w.innerHTML = `<div class="sa-kapcsolosor"><label class="sa-kapcsolosor__szoveg" for="${id}">${e(m.c)}${m.s ? `<small>${e(m.s)}</small>` : ''}</label>${kapcsolo(id, be, m.c)}</div>`;
                const c = $('input', w);
                c.addEventListener('change', valt);
                ertek = () => c.checked;
                break;
            }
            case 'datum': {
                w.innerHTML = cimHtml + `<div class="sa-datum"><input class="sa-input" id="${id}" readonly value="${e(v ? String(v).replace(/-/g, '.') + '.' : '')}" placeholder="Nincs megadva" aria-haspopup="dialog" ${desc}><input type="hidden" value="${e(v ?? '')}"></div>` + sugo;
                const kij = $('input:not([type=hidden])', w), rejt = $('input[type=hidden]', w);
                const nyit = () => naptar(kij, rejt.value, (iso) => { rejt.value = iso; kij.value = iso ? iso.replace(/-/g, '.') + '.' : ''; valt(); });
                kij.addEventListener('click', nyit);
                kij.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ' || ev.key === 'ArrowDown') { ev.preventDefault(); nyit(); } if (ev.key === 'Delete' || ev.key === 'Backspace') { rejt.value = ''; kij.value = ''; valt(); } });
                ertek = () => rejt.value;
                break;
            }
            case 'szin': {
                const sz = String(v ?? '');
                w.innerHTML = cimHtml + `<div class="sa-szin"><span class="sa-szin__minta"><i style="background:${e(sz)}"></i><input type="color" tabindex="-1" aria-hidden="true" value="${/^#[0-9a-f]{6}$/i.test(sz) ? e(sz) : '#7c6cff'}"></span><input class="sa-input" id="${id}" value="${e(sz)}" placeholder="#rrggbb" ${desc}></div>` + sugo;
                const t = $('.sa-input', w), c = $('input[type=color]', w), i = $('i', w);
                t.addEventListener('input', () => { i.style.background = t.value; valt(); });
                c.addEventListener('input', () => { t.value = c.value; i.style.background = c.value; valt(); });
                ertek = () => t.value.trim();
                break;
            }
            case 'link': {
                const l = v && typeof v === 'object' ? v : { url: v || '' };
                w.innerHTML = `<span class="sa-mezo__cim">${e(m.c)}</span><div class="sa-mezo__sor">
                    <input class="sa-input" id="${id}" value="${e(l.szoveg || '')}" placeholder="Felirat" aria-label="${e(m.c)} – felirat" maxlength="80" style="flex:.8">
                    <input class="sa-input" value="${e(l.url || '')}" placeholder="/oldal vagy https://…" aria-label="${e(m.c)} – cél">
                    ${kapcsolo(id + '-uj', !!l.uj, m.c + ' – új lapon')}<span class="sa-kicsi sa-halvany">Új lapon</span></div>` + sugo;
                const [sz, u] = $$('.sa-input', w);
                const uj = $('input[type=checkbox]', w);
                [sz, u, uj].forEach((x) => x.addEventListener('input', valt));
                ertek = () => ({ szoveg: sz.value, url: u.value.trim(), uj: uj.checked });
                break;
            }
            case 'tobb': case 'egy': {
                let kiv = m.t === 'tobb' ? (Array.isArray(v) ? v.map(String) : []) : String(v ?? '');
                w.innerHTML = cimHtml + (m.t === 'tobb' ? `<div class="sa-pillek" data-pillek></div>` : `<select class="sa-select" id="${id}" ${desc}><option value="">— Nincs —</option></select>`) + sugo;
                let lista = [];
                const nev = (k) => (lista.find((x) => x.kulcs === k) || {}).cim || k;
                const rajzol = () => {
                    if (m.t === 'egy') {
                        const s = $('select', w);
                        s.innerHTML = '<option value="">— Nincs —</option>' + lista.map((x) => `<option value="${e(x.kulcs)}" ${x.kulcs === kiv ? 'selected' : ''}>${e(x.cim)}</option>`).join('') + (kiv && !lista.some((x) => x.kulcs === kiv) ? `<option value="${e(kiv)}" selected>${e(kiv)}</option>` : '');
                        return;
                    }
                    const d = $('[data-pillek]', w);
                    d.innerHTML = kiv.map((k, i) => `<span class="sa-pill" title="${e(nev(k))}"><span>${e(nev(k))}</span><button type="button" data-ki="${i}" aria-label="${e(nev(k))} eltávolítása">${I('x')}</button></span>`).join('')
                        + `<select class="sa-select" id="${id}" aria-label="${e(m.c)} – hozzáadás"><option value="">+ Hozzáadás…</option>${lista.filter((x) => !kiv.includes(x.kulcs)).map((x) => `<option value="${e(x.kulcs)}">${e(x.cim)}</option>`).join('')}</select>`;
                };
                w.addEventListener('change', (ev) => {
                    const s = ev.target.closest('select');
                    if (!s) return;
                    if (m.t === 'egy') { kiv = s.value; valt(); return; }
                    if (s.value) { kiv.push(s.value); rajzol(); valt(); const uj = $('select', w); if (uj) uj.focus(); }
                });
                w.addEventListener('click', (ev) => { const g = ev.target.closest('[data-ki]'); if (!g) return; kiv.splice(Number(g.dataset.ki), 1); rajzol(); valt(); const s = $('select', w); if (s) s.focus(); });
                rajzol();
                elemLista(m.tipus).then((l) => { lista = l; rajzol(); });
                ertek = () => (m.t === 'tobb' ? kiv.slice() : kiv);
                break;
            }
            case 'nyelvek': {
                let kiv = Array.isArray(v) && v.length ? v.slice() : ['hu'];
                const NY = ctx.nyelvek || { hu: 'Magyar' };
                w.innerHTML = `<span class="sa-mezo__cim" id="${id}">${e(m.c)}</span><div class="sa-sor sa-sor--tor" role="group" aria-labelledby="${id}">${Object.entries(NY).map(([k, c]) => `<button type="button" class="sa-valaszto-pill" data-ny="${e(k)}" aria-pressed="${kiv.includes(k)}" ${k === 'hu' ? 'disabled title="Az alapnyelv mindig él"' : ''}>${e(c)}</button>`).join('')}</div>` + sugo;
                w.addEventListener('click', (ev) => { const g = ev.target.closest('[data-ny]'); if (!g || g.disabled) return; const k = g.dataset.ny; kiv = kiv.includes(k) ? kiv.filter((x) => x !== k) : [...kiv, k]; g.setAttribute('aria-pressed', String(kiv.includes(k))); valt(); });
                ertek = () => kiv.slice();
                break;
            }
            case 'hivatkozas': {
                w.innerHTML = `<span class="sa-mezo__cim">${e(m.c)}</span><div><a class="sa-gomb" href="${e(m.url)}" data-letoltes>${I('letolt')}${e(m.c)}</a></div>`;
                $('a', w).addEventListener('click', async (ev) => {
                    if (!String(m.url).startsWith(B.rest)) return;
                    ev.preventDefault();
                    try { const r = await fetch(m.url, { headers: { 'X-WP-Nonce': B.nonce }, credentials: 'same-origin' }); if (!r.ok) throw new Error(`Hiba (${r.status})`); const cd = r.headers.get('Content-Disposition') || ''; const n = (cd.match(/filename="?([^";]+)/) || [])[1] || 'letoltes'; letolt(n, await r.blob()); } catch (x) { jelez(x.message, 'hiba'); }
                });
                ertek = () => undefined;
                break;
            }
            case 'ismetlo': {
                const al = m.al || [];
                let sorok = (Array.isArray(v) ? v : []).map((x) => ({ adat: x, nyitva: false }));
                w.classList.add('sa-ismetlo');
                w.innerHTML = `<div class="sa-sor"><span class="sa-mezo__cim" id="${id}">${e(m.c)}</span><span class="sa-ter"></span><span class="sa-kicsi sa-halvany" data-db></span><button type="button" class="sa-gomb sa-gomb--kicsi" data-uj>${I('plusz')}Új elem</button></div><div class="sa-ismetlo__lista" role="list" aria-labelledby="${id}"></div>` + sugo;
                const lista = $('.sa-ismetlo__lista', w);
                const vez = [];
                const cimOf = (adat, i) => { const elso = al.find((x) => ['szoveg', 'hosszu'].includes(x.t)); const t = elso ? String(adat[elso.k] || '').trim() : ''; return t || `${i + 1}. elem`; };
                const atvesz = () => { vez.forEach((x, i) => { if (x) sorok[i].adat = x.ertek(); }); };
                const rajzol = (fokusz = null) => {
                    lista.innerHTML = '';
                    vez.length = 0;
                    sorok.forEach((s, i) => {
                        const d = document.createElement('div');
                        d.className = 'sa-ismetlo__elem';
                        d.setAttribute('role', 'listitem');
                        d.innerHTML = `<div class="sa-ismetlo__fej"><button type="button" class="sa-ismetlo__nyit" aria-expanded="${s.nyitva}" title="${e(cimOf(s.adat, i))}">${I(s.nyitva ? 'le' : 'jobbra')}<span>${e(cimOf(s.adat, i))}</span></button>
                            <button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-m="fel" aria-label="Feljebb" ${i === 0 ? 'disabled' : ''}>${I('fel')}</button>
                            <button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-m="le" aria-label="Lejjebb" ${i === sorok.length - 1 ? 'disabled' : ''}>${I('le')}</button>
                            <button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi sa-ikon-gomb--veszely" data-m="torol" aria-label="Elem törlése" data-ketlepcsos="Biztos? Katt újra a törléshez">${I('torol')}</button></div>`;
                        if (s.nyitva) {
                            const t = document.createElement('div');
                            t.className = 'sa-ismetlo__test';
                            const f = urlapMezok(al, s.adat, { ...ctx, valtozas: () => { valt(); const sp = $('.sa-ismetlo__nyit span', d); atvesz(); sp.textContent = cimOf(sorok[i].adat, i); sp.parentElement.title = sp.textContent; } });
                            f.vezerlok.forEach((x) => t.appendChild(x.el));
                            d.appendChild(t);
                            vez[i] = { ertek: f.ertek };
                        } else {
                            vez[i] = null;
                        }
                        d.addEventListener('click', (ev) => {
                            const g = ev.target.closest('button');
                            if (!g || !$('.sa-ismetlo__fej', d).contains(g)) return;
                            atvesz();
                            if (g.classList.contains('sa-ismetlo__nyit')) { s.nyitva = !s.nyitva; rajzol([i, 'nyit']); return; }
                            const mm = g.dataset.m;
                            if (mm === 'fel' && i > 0) { [sorok[i - 1], sorok[i]] = [sorok[i], sorok[i - 1]]; rajzol([i - 1, 'fel']); bemond(`Áthelyezve: ${i}. hely`); }
                            if (mm === 'le' && i < sorok.length - 1) { [sorok[i + 1], sorok[i]] = [sorok[i], sorok[i + 1]]; rajzol([i + 1, 'le']); bemond(`Áthelyezve: ${i + 2}. hely`); }
                            if (mm === 'torol') { sorok.splice(i, 1); rajzol([Math.max(0, i - 1), 'nyit']); bemond('Elem törölve'); }
                            valt();
                        });
                        lista.appendChild(d);
                    });
                    $('[data-db]', w).textContent = `${sorok.length}${m.max ? '/' + m.max : ''}`;
                    $('[data-uj]', w).disabled = !!m.max && sorok.length >= m.max;
                    if (fokusz) { const el = lista.children[fokusz[0]]; const g = el && (fokusz[1] === 'nyit' ? $('.sa-ismetlo__nyit', el) : $(`[data-m="${fokusz[1]}"]`, el)); if (g && !g.disabled) g.focus(); else if (el) $('.sa-ismetlo__nyit', el).focus(); }
                };
                $('[data-uj]', w).addEventListener('click', () => { atvesz(); sorok.forEach((s) => { s.nyitva = false; }); sorok.push({ adat: {}, nyitva: true }); rajzol(); const u = lista.lastElementChild; const f = u && $('input, textarea, select, [contenteditable], .sa-ikonvalaszto', $('.sa-ismetlo__test', u)); if (f) f.focus(); valt(); });
                rajzol();
                ertek = () => { atvesz(); return sorok.map((s) => s.adat); };
                break;
            }
            default: {
                w.innerHTML = cimHtml + `<input class="sa-input" id="${id}" value="${e(typeof v === 'object' ? JSON.stringify(v) : (v ?? ''))}" ${desc}>` + sugo;
                const inp = $('input', w);
                inp.addEventListener('input', valt);
                ertek = () => inp.value;
            }
        }

        const ellenoriz = () => {
            if (!m.kotelezo) return '';
            const x = ertek();
            const ures = x === undefined || x === null || String(typeof x === 'object' ? (x.url || '') : x).trim() === '';
            w.classList.toggle('is-hibas', ures);
            return ures ? `„${m.c}” kötelező.` : '';
        };

        return { m, el: w, ertek, ellenoriz };
    }

    /** Mezőlista vezérlői (lapfülek nélkül). */
    function urlapMezok(mezok, adat = {}, ctx = {}) {
        const vezerlok = mezok.filter((m) => !m.rejtett_mezo).map((m) => mezoVezerlo(m, kiolvas(adat, m.k), ctx));
        const ertek = () => {
            const o = {};
            vezerlok.forEach((v) => { const x = v.ertek(); if (x !== undefined) beir(o, v.m.k, x); });
            return o;
        };
        return { vezerlok, ertek };
    }

    /**
     * Teljes űrlap lapfülekkel (a mezők `ful` kulcsa szerint). Vissza: { el, ertek(), ellenoriz() → hibák[] }.
     */
    function urlap(mezok, adat = {}, ctx = {}) {
        const f = urlapMezok(mezok, adat, ctx);
        const alap = ctx.alapFul || 'Alap';
        const csop = [];
        f.vezerlok.forEach((v) => { const k = v.m.ful || alap; let c = csop.find((x) => x[0] === k); if (!c) { c = [k, []]; csop.push(c); } c[1].push(v); });
        const el = document.createElement('div');
        el.className = 'sa-urlap';
        if (csop.length > 1) {
            el.innerHTML = fulHtml(csop.map(([k], i) => ['f' + i, e(k), '<div class="sa-mezok"></div>']));
            csop.forEach(([, vk], i) => { const d = $$('.sa-ful-panel .sa-mezok', el)[i]; vk.forEach((v) => d.appendChild(v.el)); });
            fulek(el);
        } else {
            const d = document.createElement('div');
            d.className = 'sa-mezok';
            f.vezerlok.forEach((v) => d.appendChild(v.el));
            el.appendChild(d);
        }
        const ellenoriz = () => {
            const h = f.vezerlok.map((v) => v.ellenoriz()).filter(Boolean);
            if (h.length) {
                const elso = f.vezerlok.find((v) => v.el.classList.contains('is-hibas'));
                const pn = elso && elso.el.closest('.sa-ful-panel');
                if (pn && pn.hidden) { const g = $(`[aria-controls="${pn.id}"]`, el); if (g) g.click(); }
                const fo = elso && $('input, textarea, select, [contenteditable]', elso.el);
                if (fo) fo.focus();
            }
            return h;
        };
        return { el, ertek: f.ertek, ellenoriz, vezerlok: f.vezerlok };
    }

    /* ------------------------------------------------------------------ Naptár (natív dátumválasztó helyett) */

    const HONAP = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
    function naptar(horgony, iso, kesz) {
        const ma = new Date();
        const iso2 = (d) => `${d.getFullYear()}-${ket(d.getMonth() + 1)}-${ket(d.getDate())}`;
        let fokusz = iso ? new Date(iso + 'T12:00:00') : new Date(ma.getFullYear(), ma.getMonth(), ma.getDate(), 12);
        const el = document.createElement('div');
        el.className = 'sa-naptar';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-label', 'Dátum választása');
        let zar = null;
        const rajzol = () => {
            const ev = fokusz.getFullYear(), ho = fokusz.getMonth();
            const elso = (new Date(ev, ho, 1).getDay() + 6) % 7;
            const napok = new Date(ev, ho + 1, 0).getDate();
            let h = `<div class="sa-naptar__fej"><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-ho="-1" aria-label="Előző hónap">${I('balra')}</button><span aria-live="polite">${ev}. ${HONAP[ho]}</span><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-ho="1" aria-label="Következő hónap">${I('jobbra')}</button></div><div class="sa-naptar__racs" role="grid">`;
            h += ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'].map((x) => `<span aria-hidden="true">${x}</span>`).join('') + '<span></span>'.repeat(elso);
            for (let n = 1; n <= napok; n++) {
                const d = iso2(new Date(ev, ho, n));
                h += `<button type="button" data-nap="${d}" aria-selected="${d === iso}" class="${d === iso2(ma) ? 'is-ma' : ''}" tabindex="${n === fokusz.getDate() ? 0 : -1}" aria-label="${ev}. ${HONAP[ho]} ${n}.">${n}</button>`;
            }
            h += `</div><div class="sa-naptar__lab"><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--szoveg" data-torol>Törlés</button><button type="button" class="sa-gomb sa-gomb--kicsi" data-ma>Ma</button></div>`;
            el.innerHTML = h;
            const f = $(`[data-nap="${iso2(fokusz)}"]`, el);
            if (f) f.focus();
        };
        el.addEventListener('click', (ev) => {
            const g = ev.target.closest('button');
            if (!g) return;
            if (g.dataset.ho) { fokusz = new Date(fokusz.getFullYear(), fokusz.getMonth() + Number(g.dataset.ho), 1, 12); rajzol(); return; }
            if (g.dataset.nap) { kesz(g.dataset.nap); zar(); horgony.focus(); }
            if (g.hasAttribute('data-torol')) { kesz(''); zar(); horgony.focus(); }
            if (g.hasAttribute('data-ma')) { kesz(iso2(ma)); zar(); horgony.focus(); }
        });
        el.addEventListener('keydown', (ev) => {
            const lep = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 7, ArrowUp: -7 }[ev.key];
            if (lep && ev.target.dataset.nap) { ev.preventDefault(); fokusz = new Date(fokusz.getFullYear(), fokusz.getMonth(), fokusz.getDate() + lep, 12); rajzol(); }
        });
        zar = lebego(el, horgony);
        rajzol();
    }

    /* ------------------------------------------------------------------ Rendezés húzással (pointer) + billentyűvel */

    /**
     * lista: a konténer; elem: a sorok szelektora; fogo: a fogantyú szelektora; kesz(regiIndex, ujIndex).
     * Billentyű a fogantyún: ↑ / ↓ mozgat.
     */
    function rendezheto(lista, elem, fogo, kesz) {
        lista.addEventListener('pointerdown', (ev) => {
            const f = ev.target.closest(fogo);
            if (!f || ev.button !== 0) return;
            const sor = f.closest(elem);
            if (!sor || !lista.contains(sor)) return;
            ev.preventDefault();
            const szulo = sor.parentElement;
            const sorok = () => $$(':scope > ' + elem, szulo);
            const regi = sorok().indexOf(sor);
            const kezd = ev.clientY;
            const h = sor.getBoundingClientRect().height;
            const hely = document.createElement(sor.tagName);
            hely.className = 'sa-helyorzo';
            hely.style.height = h + 'px';
            sor.classList.add('is-huz');
            sor.after(hely);
            sor.style.position = 'relative';
            f.setPointerCapture(ev.pointerId);
            let dy = 0;
            const mozog = (x) => {
                dy = x.clientY - kezd;
                sor.style.transform = `translateY(${dy}px)`;
                const kozep = sor.getBoundingClientRect().top + h / 2;
                const tobbi = sorok().filter((s) => s !== sor);
                for (const s of tobbi) {
                    const r = s.getBoundingClientRect();
                    const elotte = hely.compareDocumentPosition(s) & Node.DOCUMENT_POSITION_PRECEDING;
                    if (elotte && kozep < r.top + r.height / 2) { s.before(hely); break; }
                    if (!elotte && kozep > r.top + r.height / 2) { s.after(hely); }
                }
            };
            const vege = () => {
                f.removeEventListener('pointermove', mozog);
                f.removeEventListener('pointerup', vege);
                f.removeEventListener('pointercancel', vege);
                sor.classList.remove('is-huz');
                sor.style.transform = '';
                sor.style.position = '';
                hely.replaceWith(sor);
                const uj = sorok().indexOf(sor);
                if (uj !== regi) kesz(regi, uj); else f.focus();
            };
            f.addEventListener('pointermove', mozog);
            f.addEventListener('pointerup', vege);
            f.addEventListener('pointercancel', vege);
        });
        lista.addEventListener('keydown', (ev) => {
            const f = ev.target.closest(fogo);
            if (!f || !['ArrowUp', 'ArrowDown'].includes(ev.key)) return;
            const sor = f.closest(elem);
            if (!sor) return;
            const sorok = $$(':scope > ' + elem, sor.parentElement);
            const i = sorok.indexOf(sor);
            const j = ev.key === 'ArrowUp' ? i - 1 : i + 1;
            if (i < 0 || j < 0 || j >= sorok.length) return;
            ev.preventDefault();
            kesz(i, j);
        });
    }

    /* ------------------------------------------------------------------ Diagramok */

    function spark(ertekek, { szin = 'var(--sa-kiemelo)', w = 96, hh = 32 } = {}) {
        const v = (ertekek || []).map(Number);
        if (v.length < 2) return '';
        const max = Math.max(...v, 1), min = Math.min(...v, 0);
        const pt = v.map((x, i) => [(i / (v.length - 1)) * w, hh - 2 - ((x - min) / (max - min || 1)) * (hh - 4)]);
        const d = pt.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
        return `<svg class="sa-kpi__spark" viewBox="0 0 ${w} ${hh}" preserveAspectRatio="none" aria-hidden="true"><path d="${d} L${w} ${hh} L0 ${hh} Z" fill="${szin}" opacity=".14"/><path d="${d}" fill="none" stroke="${szin}" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/></svg>`;
    }

    function terulet(el, cimkek, ertekek, format = ft) {
        const W = 760, H = 220, bal = 64, jobb = 12, fel = 12, le = 26;
        const max = Math.max(...ertekek, 1);
        const x = (i) => bal + (i / Math.max(1, ertekek.length - 1)) * (W - bal - jobb);
        const y = (v) => fel + (1 - v / max) * (H - fel - le);
        let s = '';
        for (let i = 0; i <= 4; i++) { const v = (max / 4) * i; s += `<line class="sa-diagram__racs" x1="${bal}" x2="${W - jobb}" y1="${y(v)}" y2="${y(v)}"/><text class="sa-diagram__tengely" x="${bal - 8}" y="${y(v) + 4}" text-anchor="end">${e(szam(v / (max >= 100000 ? 1000 : 1)))}${max >= 100000 ? 'e' : ''}</text>`; }
        cimkek.forEach((c, i) => { if (i % 5 === 0 || i === cimkek.length - 1) s += `<text class="sa-diagram__tengely" x="${x(i)}" y="${H - 6}" text-anchor="middle">${e(c.slice(5).replace('-', '.'))}.</text>`; });
        const d = ertekek.map((v, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1)).join(' ');
        s += `<defs><linearGradient id="sa-ter-g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--sa-kiemelo)" stop-opacity=".35"/><stop offset="1" stop-color="var(--sa-kiemelo)" stop-opacity="0"/></linearGradient></defs>`;
        s += `<path d="${d} L${x(ertekek.length - 1)} ${y(0)} L${x(0)} ${y(0)} Z" fill="url(#sa-ter-g)"/><path d="${d}" fill="none" stroke="var(--sa-kiemelo)" stroke-width="2.2" stroke-linejoin="round"/>`;
        s += `<line class="sa-diagram__fuggoleges" data-vonal x1="0" x2="0" y1="${fel}" y2="${H - le}" stroke="var(--sa-vonal-eros)" stroke-dasharray="3 3" hidden/><circle data-pont r="4.5" fill="var(--sa-kiemelo)" stroke="var(--sa-panel-eros)" stroke-width="2" hidden/>`;
        el.innerHTML = `<div class="sa-diagram"><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Bevétel az elmúlt ${ertekek.length} napban">${s}</svg><div class="sa-diagram__tipp" hidden></div></div>`;
        const svg = $('svg', el), tipp = $('.sa-diagram__tipp', el), vonal = $('[data-vonal]', el), pont = $('[data-pont]', el);
        svg.addEventListener('mousemove', (ev) => {
            const r = svg.getBoundingClientRect();
            const px = ((ev.clientX - r.left) / r.width) * W;
            const i = Math.max(0, Math.min(ertekek.length - 1, Math.round(((px - bal) / (W - bal - jobb)) * (ertekek.length - 1))));
            vonal.setAttribute('x1', x(i)); vonal.setAttribute('x2', x(i)); vonal.removeAttribute('hidden');
            pont.setAttribute('cx', x(i)); pont.setAttribute('cy', y(ertekek[i])); pont.removeAttribute('hidden');
            tipp.hidden = false;
            tipp.style.left = (x(i) / W) * r.width + 'px';
            tipp.style.top = (y(ertekek[i]) / H) * r.height + 'px';
            tipp.innerHTML = `<span class="sa-halvany">${e(cimkek[i].replace(/-/g, '.'))}.</span><br><b>${e(format(ertekek[i]))}</b>`;
        });
        svg.addEventListener('mouseleave', () => { tipp.hidden = true; vonal.setAttribute('hidden', ''); pont.setAttribute('hidden', ''); });
    }

    /* ------------------------------------------------------------------ Közös darabok */

    const ures = (szoveg, ikon = 'info', cim = '') => `<div class="sa-ures">${I(ikon)}${cim ? `<b>${e(cim)}</b>` : ''}${e(szoveg)}</div>`;
    const csontvaz = () => `<div class="sa-racs sa-racs--kpi">${'<div class="sa-kartya sa-csontvaz" style="height:112px"></div>'.repeat(4)}</div><div class="sa-kartya sa-csontvaz" style="height:320px"></div>`;
    const hibaKartya = (x) => `<div class="sa-kartya">${ures(x.message || String(x), 'figyel', 'Nem sikerült betölteni')}</div>`;
    const nincsBekapcsolva = (cim) => `<div class="sa-kartya">${ures('Ez a rész akkor jelenik meg, ha a hozzá tartozó modul be van kapcsolva.', 'info', `${cim}: még nincs bekapcsolva`)}</div>`;

    function szegmens(nev, opciok, aktiv) {
        return `<div class="sa-szegmens" role="group" aria-label="${e(nev)}" data-szegmens="${e(nev)}">${Object.entries(opciok).map(([k, v]) => `<button type="button" data-ertek="${e(k)}" aria-pressed="${String(k) === String(aktiv)}">${v}</button>`).join('')}</div>`;
    }
    document.addEventListener('click', (ev) => {
        const g = ev.target.closest('[data-szegmens] button');
        if (!g) return;
        const sz = g.parentElement;
        $$('button', sz).forEach((b) => b.setAttribute('aria-pressed', String(b === g)));
        sz.dispatchEvent(new CustomEvent('valtozas', { detail: g.dataset.ertek, bubbles: true }));
    });

    function lapozo(ossz, oldal, db, valt) {
        const max = Math.max(1, Math.ceil(ossz / db));
        const d = document.createElement('div');
        d.className = 'sa-lapozo';
        d.innerHTML = `<span>${szam(ossz)} tétel · ${oldal}/${max}. oldal</span><span class="sa-sor"><button type="button" class="sa-gomb sa-gomb--kicsi" data-l="-1" ${oldal <= 1 ? 'disabled' : ''}>${I('balra')}Előző</button><button type="button" class="sa-gomb sa-gomb--kicsi" data-l="1" ${oldal >= max ? 'disabled' : ''}>Következő${I('jobbra')}</button></span>`;
        d.addEventListener('click', (ev) => { const g = ev.target.closest('[data-l]'); if (g) valt(oldal + Number(g.dataset.l)); });
        return d;
    }

    /** Kattintható sor: Enter / Szóköz is nyitja. */
    function sorKattint(hova, szelektor, fn) {
        hova.addEventListener('click', (ev) => { const s = ev.target.closest(szelektor); if (s && !ev.target.closest('button, a, input, select, label')) fn(s); });
        hova.addEventListener('keydown', (ev) => { const s = ev.target.closest(szelektor); if (s && ev.target === s && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); fn(s); } });
    }

    const kesleltet = (fn, ms = 280) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

    /* ------------------------------------------------------------------ Állapot, váz, útválasztó */

    const S = { nyelv: (B.nyelvek || ['hu'])[0], oldal: '', mentetlen: null, kilepes: null, szurok: {} };
    const RAJZOLOK = {};
    const PANEL_IKON = { attekintes: 'attekintes', rendelesek: 'rendeles', vevok: 'vevo', leadek: 'lead', foglalasok: 'naptar', oldalak: 'oldal', elemek: 'elem', menuk: 'menu', arculat: 'arculat', beallitasok: 'beallitas', naplo: 'naplo', export: 'export' };

    function panelek() { return Object.entries(B.panelek || {}); }

    function vaz() {
        const app = $('#sa-app');
        const csop = [];
        panelek().filter(([, p]) => p.elerheto).forEach(([k, p]) => { let c = csop.find((x) => x[0] === p.csoport); if (!c) { c = [p.csoport, []]; csop.push(c); } c[1].push([k, p]); });
        const monogram = (B.teljes || B.nev || '?').trim().split(/\s+/).map((x) => x[0]).slice(0, 2).join('').toUpperCase();
        app.innerHTML = `
            <aside class="sa-oldalsav" id="sa-oldalsav" aria-label="Oldalsáv">
                <div class="sa-oldalsav__fej"><a class="sa-logo" href="#/"><span class="sa-logo__jel">${I('rendeles')}</span><span class="sa-logo__nev">Értékesítés<small title="${e(B.termeknev)}">${e(B.termeknev)}</small></span></a><button type="button" class="sa-ikon-gomb sa-oldalsav__zar" data-menu-zar aria-label="Menü bezárása">${I('x')}</button></div>
                <nav class="sa-menu" aria-label="Fő menü">
                    ${csop.map(([c, l]) => (c ? `<div class="sa-menu__cim" aria-hidden="true">${e(c)}</div>` : '') + l.map(([k, p]) => `<a href="#/${k === 'attekintes' ? '' : e(k)}" data-menupont="${e(k)}">${I(p.ikon || PANEL_IKON[k] || 'info')}<span>${e(p.cim)}</span><span class="sa-menu__jel" data-jel="${e(k)}" hidden></span></a>`).join('')).join('')}
                </nav>
                <div class="sa-oldalsav__alja">
                    <a class="sa-wp-gomb" href="${e(B.wp)}">${I('wp')}<span>Haladó rendszer-admin</span></a>
                    <a class="sa-wp-gomb" href="${e(B.webhely)}" target="_blank" rel="noopener">${I('kulso')}<span>Webhely megnyitása</span></a>
                    <div class="sa-halvany sa-kicsi" style="padding:2px 12px">v${e(B.verzio)}</div>
                </div>
            </aside>
            <div class="sa-menu-fatyol" data-menu-zar aria-hidden="true"></div>
            <div class="sa-fo">
                <header class="sa-fejlec">
                    <button type="button" class="sa-ikon-gomb sa-mobil-menu" data-menu aria-label="Menü" aria-expanded="false" aria-controls="sa-oldalsav">${I('hamburger')}</button>
                    <h1 class="sa-fejlec__cim" data-cim>Áttekintés</h1>
                    <span class="sa-fejlec__ter"></span>
                    ${(B.nyelvek || []).length > 1 ? `<select class="sa-select sa-select--kicsi" data-nyelv aria-label="Tartalom nyelve" style="width:auto">${B.nyelvek.map((n) => `<option value="${e(n)}">${e(n.toUpperCase())}</option>`).join('')}</select>` : ''}
                    <button type="button" class="sa-kereso" data-paletta aria-label="Keresés és parancsok (Ctrl+K)">${I('kereses')}<span>Keresés, parancs…</span><kbd>Ctrl K</kbd></button>
                    <button type="button" class="sa-ikon-gomb" data-tema-gomb aria-label="Világos / sötét téma">${I(document.documentElement.dataset.tema === 'vilagos' ? 'hold' : 'nap')}</button>
                    <a class="sa-ikon-gomb" href="${e(B.kilep)}" aria-label="Kijelentkezés" title="Kijelentkezés">${I('kilep')}</a>
                    <span class="sa-profil" title="${e(B.teljes)}">${B.avatar ? `<img src="${e(B.avatar)}" alt="" data-avatar>` : e(monogram)}</span>
                </header>
                <main id="sa-tartalom" class="sa-tartalom" data-tartalom tabindex="-1"></main>
            </div>`;
        const av = $('[data-avatar]', app);
        if (av) av.addEventListener('error', () => { av.replaceWith(document.createTextNode(monogram)); });
        $('[data-menu]', app).addEventListener('click', () => menuNyit(!app.classList.contains('is-menu')));
        $$('[data-menu-zar]', app).forEach((x) => x.addEventListener('click', () => menuNyit(false)));
        app.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && app.classList.contains('is-menu')) { ev.preventDefault(); menuNyit(false); } });
        $('[data-paletta]', app).addEventListener('click', paletta);
        $('[data-tema-gomb]', app).addEventListener('click', temaValt);
        const ny = $('[data-nyelv]', app);
        if (ny) ny.addEventListener('change', () => { S.nyelv = ny.value; Object.keys(elemCache).forEach((k) => delete elemCache[k]); route(); });
    }

    /** Mobil oldalsáv (≤ 960 px): fátyol, Esc, fókusz be / vissza, görgetészár. */
    function menuNyit(be, fokusz = true) {
        const app = $('#sa-app');
        const g = $('[data-menu]', app);
        if (!g || app.classList.contains('is-menu') === be) return;
        app.classList.toggle('is-menu', be);
        document.documentElement.classList.toggle('sa-zar', be);
        g.setAttribute('aria-expanded', String(be));
        if (!fokusz) return;
        if (be) { const a = $('.sa-menu a[aria-current="page"]', app) || $('.sa-menu a', app); if (a) setTimeout(() => a.focus(), 30); } else g.focus();
    }

    function temaValt() {
        const html = document.documentElement;
        const most = html.dataset.tema || (matchMedia('(prefers-color-scheme: light)').matches ? 'vilagos' : 'sotet');
        const uj = most === 'vilagos' ? 'sotet' : 'vilagos';
        html.dataset.tema = uj;
        $('[data-tema-gomb]').innerHTML = I(uj === 'vilagos' ? 'hold' : 'nap');
        api('admin/tema', { method: 'POST', body: { tema: uj } }).catch(() => {});
    }

    function cim(szoveg, morzsa = '') {
        const h = $('[data-cim]');
        h.innerHTML = (morzsa ? `<span class="sa-fejlec__morzsa">${morzsa} / </span>` : '') + e(szoveg);
        h.title = h.textContent;
        document.title = `${szoveg} · Értékesítés · ${B.termeknev}`;
    }

    const tartalom = () => $('[data-tartalom]');

    let elozoHash = location.hash;
    function route() {
        const hash = location.hash || '#/';
        if (S.mentetlen && S.mentetlen() && hash !== elozoHash) {
            if (S.kilepes !== hash) {
                S.kilepes = hash;
                history.replaceState(null, '', elozoHash || '#/');
                jelez('Mentetlen változásaid vannak. Mentsd el, vagy kattints újra a továbblépéshez.', 'figyel');
                setTimeout(() => { if (S.kilepes === hash) S.kilepes = null; }, 4000);
                return;
            }
        }
        S.kilepes = null;
        S.mentetlen = null;
        elozoHash = hash;
        $('.sa-naptar, .sa-ikonracs')?.remove();
        const app = $('#sa-app');
        menuNyit(false, false);
        const [k, ...param] = hash.slice(2).split('/');
        const kulcs = k || 'attekintes';
        const p = (B.panelek || {})[kulcs];
        S.oldal = kulcs;
        $$('[data-menupont]').forEach((a) => { if (a.dataset.menupont === kulcs) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
        // Minden panel friss tárolót kap: a korábbi panel eseménykezelői nem halmozódnak.
        const regi = tartalom();
        const hova = regi.cloneNode(false);
        regi.replaceWith(hova);
        hova.className = 'sa-tartalom';
        window.scrollTo(0, 0);
        if (!p) { cim('Áttekintés'); return RAJZOLOK.attekintes(hova); }
        cim(p.cim, p.csoport && p.csoport !== 'Értékesítés' && p.csoport !== 'Rendszer' ? e(p.csoport) : '');
        if (!p.elerheto || !RAJZOLOK[kulcs]) { hova.innerHTML = nincsBekapcsolva(p.cim); return undefined; }
        hova.innerHTML = csontvaz();
        Promise.resolve(RAJZOLOK[kulcs](hova, param.map(decodeURIComponent))).catch((x) => { hova.innerHTML = hibaKartya(x); });
        return undefined;
    }

    window.addEventListener('hashchange', route);
    window.addEventListener('beforeunload', (ev) => { if (S.mentetlen && S.mentetlen()) { ev.preventDefault(); ev.returnValue = ''; } });

    async function menuJelek() {
        try {
            const a = await api('admin/attekintes');
            const jel = (k, n, ok = false) => { const j = $(`[data-jel="${k}"]`); if (!j) return; j.hidden = !n; j.textContent = n; j.className = 'sa-menu__jel' + (ok ? ' sa-menu__jel--ok' : ''); };
            jel('rendelesek', a.lanc_hiba);
            jel('leadek', a.uj_lead, true);
            return a;
        } catch (x) { return null; }
    }

    /* ================================================================== PANELEK */

    /* ------------------------------------------------------------------ Áttekintés */

    RAJZOLOK.attekintes = async (hova) => {
        const a = await api('admin/attekintes');
        const napok = Object.keys(a.napok), bev = napok.map((n) => a.napok[n].bevetel), ren = napok.map((n) => a.napok[n].rendeles);
        const SAV = { ma: 'Ma', het: '7 nap', ho: '30 nap' };
        S.szurok.attekintes = S.szurok.attekintes || 'het';
        const ora = new Date().getHours();
        const koszon = ora < 10 ? 'Jó reggelt' : ora < 18 ? 'Szia' : 'Jó estét';
        const kpik = (s) => {
            const k = a.kpi[s];
            const n = s === 'ma' ? 1 : s === 'het' ? 7 : 30;
            return `
                <div class="sa-kartya sa-kpi is-katt" data-nav="#/rendelesek" tabindex="0" role="link"><div class="sa-kpi__cimke">${I('rendeles')}Rendelések</div><div class="sa-kpi__ertek">${szam(k.rendeles)}</div><div class="sa-kpi__al">${SAV[s]}</div>${spark(ren.slice(-Math.max(n, 7)))}</div>
                <div class="sa-kartya sa-kpi"><div class="sa-kpi__cimke">${I('penz')}Bevétel (nettó)</div><div class="sa-kpi__ertek">${szam(k.bevetel_netto)}<small>Ft</small></div><div class="sa-kpi__al">bruttó ${e(ft(k.bevetel_brutto))}</div>${spark(bev.slice(-Math.max(n, 7)), { szin: 'var(--sa-ok)' })}</div>
                <div class="sa-kartya sa-kpi is-katt" data-nav="#/rendelesek/nyitott" tabindex="0" role="link"><div class="sa-kpi__cimke">${I('szamla')}Kifizetetlen díjbekérő</div><div class="sa-kpi__ertek">${szam(a.nyitott.db)}</div><div class="sa-kpi__al">${e(ft(a.nyitott.osszeg))} nyitott</div></div>
                <div class="sa-kartya sa-kpi is-katt" data-nav="#/leadek" tabindex="0" role="link"><div class="sa-kpi__cimke">${I('lead')}Leadek</div><div class="sa-kpi__ertek">${szam(k.lead)}</div><div class="sa-kpi__al">${szam(a.uj_lead)} feldolgozatlan</div></div>
                <div class="sa-kartya sa-kpi ${B.panelek.foglalasok && B.panelek.foglalasok.elerheto ? 'is-katt' : ''}" ${B.panelek.foglalasok && B.panelek.foglalasok.elerheto ? 'data-nav="#/foglalasok" tabindex="0" role="link"' : ''}><div class="sa-kpi__cimke">${I('naptar')}Foglalások</div><div class="sa-kpi__ertek">${szam(k.foglalas)}</div><div class="sa-kpi__al">${szam(a.kozelgo_foglalas)} közelgő</div></div>
                <div class="sa-kartya sa-kpi is-katt ${a.lanc_hiba ? 'sa-kpi--hiba' : ''}" data-nav="#/rendelesek/hiba" tabindex="0" role="link"><div class="sa-kpi__cimke">${I('lanc')}Kiszolgálási hibák</div><div class="sa-kpi__ertek">${szam(a.lanc_hiba)}</div><div class="sa-kpi__al">${a.lanc_hiba ? 'beavatkozást kér' : 'minden rendben'}</div></div>`;
        };
        hova.innerHTML = `
            <div class="sa-udv"><div><h2>${e(koszon)}, <span>${e(B.nev)}</span>!</h2><p>Ez történt a ${e(B.termeknev)} értékesítésében.</p></div>${szegmens('Időszak', SAV, S.szurok.attekintes)}</div>
            <div class="sa-racs sa-racs--kpi" data-kpik>${kpik(S.szurok.attekintes)}</div>
            <div class="sa-racs sa-racs--2">
                <div class="sa-kartya"><div class="sa-kartya__fej"><h2 class="sa-kartya__cim">Bevétel (bruttó, fizetés napja szerint)</h2><span class="sa-ter"></span><span class="sa-kartya__al">30 nap · összesen ${e(ft(a.kpi.ho.bevetel_brutto))}</span></div><div data-diagram></div></div>
                <div class="sa-kartya"><div class="sa-kartya__fej"><h2 class="sa-kartya__cim">Kiszolgálási hibák</h2><span class="sa-ter"></span>${a.lanc_hiba ? `<a class="sa-gomb sa-gomb--kicsi" href="#/rendelesek/hiba">Mind</a>` : ''}</div>
                    ${a.hibas.length ? `<div class="sa-lista">${a.hibas.map((r) => `<div class="sa-lista__elem is-katt" data-rendeles="${r.id}" tabindex="0" role="button"><div class="sa-lista__test"><div class="sa-lista__fo sa-mono">${e(r.azon)}</div><div class="sa-lista__al sa-egysor" title="${e(r.vevo || r.email)}">${e(r.vevo || r.email)}</div></div>${cimke('Hiba', 'hiba')}</div>`).join('')}</div>` : ures('Minden rendelés kiszolgálása rendben fut.', 'pipa')}
                </div>
            </div>
            <div class="sa-racs sa-racs--2">
                <div class="sa-kartya sa-kartya--sima"><div class="sa-kartya__fej"><h2 class="sa-kartya__cim">Legutóbbi rendelések</h2><span class="sa-ter"></span><a class="sa-gomb sa-gomb--kicsi" href="#/rendelesek">Összes</a></div>
                    ${a.utolso.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Azonosító</th><th>Vevő</th><th>Állapot</th><th class="sa-jobb">Bruttó</th><th>Létrehozva</th></tr></thead><tbody>${a.utolso.map((r) => `<tr class="is-katt" data-rendeles="${r.id}" tabindex="0"><td class="sa-mono">${e(r.azon)}</td><td><div class="sa-egysor" style="--sa-cella-max:180px" title="${e(r.vevo || r.email)}">${e(r.vevo || r.email)}</div></td><td>${allapotCimke(r.allapot)}</td><td class="sa-jobb">${e(ft(r.brutto))}</td><td class="sa-halvany">${e(relativ(r.letrehozva))}</td></tr>`).join('')}</tbody></table></div>` : ures('Még nincs rendelés.', 'rendeles')}
                </div>
                <div class="sa-kartya"><div class="sa-kartya__fej"><h2 class="sa-kartya__cim">Napló</h2><span class="sa-ter"></span><a class="sa-gomb sa-gomb--kicsi" href="#/naplo">Mind</a></div>
                    ${a.naplo.length ? `<div class="sa-lista">${a.naplo.map((n) => `<div class="sa-lista__elem"><span class="sa-cimke sa-cimke--${SZINT[n.szint] || 'szurke'}" style="padding:3px 6px"><span class="sa-pont"></span></span><div class="sa-lista__test"><div class="sa-kicsi">${e(n.szoveg)}</div><div class="sa-lista__al">${e(relativ(n.ido))} · ${e(n.ki)}</div></div></div>`).join('')}</div>` : ures('Még üres.')}
                </div>
            </div>`;
        terulet($('[data-diagram]', hova), napok, bev);
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { S.szurok.attekintes = ev.detail; $('[data-kpik]', hova).innerHTML = kpik(ev.detail); });
        sorKattint(hova, '[data-rendeles]', (s) => rendelesPopup(Number(s.dataset.rendeles)));
        sorKattint(hova, '[data-nav]', (s) => { location.hash = s.dataset.nav; });
    };

    /* ------------------------------------------------------------------ Rendelések */

    RAJZOLOK.rendelesek = async (hova, [elo]) => {
        const f = S.szurok.rendelesek = Object.assign({ allapot: 'mind', q: '', fizmod: '', tipus: '', oldal: 1 }, S.szurok.rendelesek || {});
        if (elo) { f.allapot = elo; f.oldal = 1; }
        const ALL = { mind: 'Mind', nyitott: 'Nyitott', fizetve: 'Fizetve', kiszolgalva: 'Kiszolgálva', hiba: 'Hibás lánc', sikertelen: 'Sikertelen', torolve: 'Törölve' };
        hova.innerHTML = `
            <div class="sa-eszkozsor">${szegmens('Állapot', ALL, f.allapot)}<span class="sa-ter"></span>
                <select class="sa-select" data-f="tipus" aria-label="Típus" style="width:auto"><option value="">Minden típus</option>${Object.entries(RTIPUS).map(([k, v]) => `<option value="${k}" ${f.tipus === k ? 'selected' : ''}>${e(v)}</option>`).join('')}</select>
                <select class="sa-select" data-f="fizmod" aria-label="Fizetési mód" style="width:auto"><option value="">Minden fizetési mód</option>${Object.entries(FIZMOD).map(([k, v]) => `<option value="${k}" ${f.fizmod === k ? 'selected' : ''}>${e(v)}</option>`).join('')}</select>
                <input class="sa-input" type="search" data-q placeholder="Azonosító, e-mail, cég, licenckulcs…" aria-label="Keresés a rendelésekben" value="${e(f.q)}">
            </div>
            <div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"><div class="sa-csontvaz" style="height:300px;border-radius:0"></div></div>`;
        const lista = $('[data-lista]', hova);
        const tolt2 = async () => {
            const r = await api('admin/rendelesek', { query: { allapot: f.allapot, q: f.q, fizmod: f.fizmod, tipus: f.tipus, oldal: f.oldal, db: 25 } });
            lista.innerHTML = r.lista.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Azonosító</th><th>Vevő</th><th>Típus</th><th>Állapot</th><th>Fizetés</th><th class="sa-jobb">Nettó</th><th class="sa-jobb">Bruttó</th><th>Létrehozva</th></tr></thead><tbody>${r.lista.map((x) => `
                <tr class="is-katt" data-rendeles="${x.id}" tabindex="0"><td class="sa-mono">${e(x.azon)} ${x.lanc_hiba ? `<span class="sa-cimke sa-cimke--hiba" title="Kiszolgálási hiba">${I('figyel')}</span>` : ''}</td>
                <td><div class="sa-egysor" title="${e(x.vevo || '')}">${e(x.vevo || '–')}</div><div class="sa-kicsi sa-halvany sa-egysor" title="${e(x.email)}">${e(x.email)}</div></td>
                <td>${e(RTIPUS[x.tipus] || x.tipus)} <span class="sa-halvany sa-kicsi">${e(CIKLUS[x.ciklus] || '')}</span></td><td>${allapotCimke(x.allapot)}</td><td>${e(FIZMOD[x.fizmod] || x.fizmod)}</td>
                <td class="sa-jobb">${e(ft(x.netto))}</td><td class="sa-jobb sa-vastag">${e(ft(x.brutto))}</td><td class="sa-halvany">${e(datumIdo(x.letrehozva))}</td></tr>`).join('')}</tbody></table></div>`
                : ures(f.q ? 'Nincs a keresésnek megfelelő rendelés.' : 'Ebben a nézetben nincs rendelés.', 'rendeles');
            if (r.ossz > r.db) lista.appendChild(lapozo(r.ossz, r.oldal, r.db, (o) => { f.oldal = o; tolt2(); }));
        };
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { f.allapot = ev.detail; f.oldal = 1; tolt2(); });
        $$('[data-f]', hova).forEach((s) => s.addEventListener('change', () => { f[s.dataset.f] = s.value; f.oldal = 1; tolt2(); }));
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); f.oldal = 1; tolt2(); }));
        sorKattint(lista, '[data-rendeles]', (s) => rendelesPopup(Number(s.dataset.rendeles), tolt2));
        await tolt2();
    };

    async function pdfLetolt(rendeles, tipus) {
        try {
            const r = await api(`admin/szamla/${rendeles}/pdf`, { query: { tipus }, nyers: true });
            const cd = r.headers.get('Content-Disposition') || '';
            letolt((cd.match(/filename="?([^";]+)/) || [])[1] || `${tipus}-${rendeles}.pdf`, await r.blob());
        } catch (x) { jelez('A PDF nem tölthető le: ' + x.message, 'hiba'); }
    }

    async function rendelesPopup(id, frissit = null) {
        let r;
        try { r = await api('admin/rendeles/' + id); } catch (x) { jelez(x.message, 'hiba'); return; }
        const v = r.vevo_adat || {};
        const fizetheto = van('/admin/rendeles/{}/fizetve') && !['fizetve', 'kiszolgalva', 'torolve', 'visszaterites'].includes(r.allapot);
        const kv = (sorok) => `<dl class="sa-kv">${sorok.filter((s) => s[1] !== '' && s[1] !== undefined && s[1] !== null).map(([k, x, nyers]) => `<dt>${e(k)}</dt><dd>${nyers ? x : e(x)}</dd>`).join('')}</dl>`;
        const cimStr = (c) => (c && typeof c === 'object' ? Object.values(c).filter(Boolean).join(', ') : String(c || ''));
        const attekint = `<div class="sa-racs sa-racs--fele">
            <div class="sa-oszlop"><h3 class="sa-kartya__cim">Vevő</h3>${kv([['Cég', v.ceg || v.nev || ''], ['Adószám', v.adoszam || ''], ['Cím', cimStr(v.cim)], ['Kapcsolattartó', v.kapcsolattarto || ''], ['E-mail', r.email], ['Telefon', v.telefon || '']])}
                <h3 class="sa-kartya__cim">Licenc</h3>${r.licenc_kulcs ? `<div class="sa-sor"><code class="sa-cimke sa-cimke--mono">${e(r.licenc_kulcs)}</code><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-masol="${e(r.licenc_kulcs)}" aria-label="Licenckulcs másolása">${I('masol')}</button></div>` : '<span class="sa-halvany sa-kicsi">Még nincs kiadva.</span>'}
            </div>
            <div class="sa-oszlop"><h3 class="sa-kartya__cim">Tételek</h3>
                ${r.tetelek.length ? `<table class="sa-tabla sa-tabla--suru"><tbody>${r.tetelek.map((t) => `<tr><td>${e(t.nev)}${t.db > 1 ? ` <span class="sa-halvany">× ${t.db}</span>` : ''}</td><td class="sa-jobb">${e(ft(t.netto))}</td></tr>`).join('')}</tbody></table>` : '<span class="sa-halvany sa-kicsi">Nincs tétel.</span>'}
                ${kv([['Nettó', ft(r.netto)], ['Áfa', ft(r.afa)], ['Bruttó', `<b>${e(ft(r.brutto))}</b>`, true], ['Befizetve', ft(r.fizetett)], ['Kupon', r.kupon], ['Fizetés', `${FIZMOD[r.fizmod] || r.fizmod} · ${CIKLUS[r.ciklus] || r.ciklus}`], ['Közlemény', r.kozlemeny], ['Határidő', r.hatarido ? r.hatarido.replace(/-/g, '.') + '.' : '']])}
            </div></div>`;
        const lanc = `<div class="sa-lanc">${r.lanc.map((l) => { const [cls, ik] = LANC[l.allapot] || ['', 'ora']; return `<div class="sa-lanc__lepes sa-lanc__lepes--${cls}"><span class="sa-lanc__jel">${I(ik)}</span><div class="sa-lanc__szoveg"><b>${e(l.cim)}</b> <span class="sa-halvany sa-kicsi">${e({ ok: 'kész', kihagyva: 'kihagyva', hiba: 'hiba', var: 'vár', fut: 'fut', nincs: 'még nem indult' }[l.allapot] || l.allapot)}${l.probalkozas ? ` · ${l.probalkozas}. próbálkozás` : ''}${l.ido ? ' · ' + e(datumIdo(l.ido)) : ''}</span>${l.uzenet ? `<small>${e(l.uzenet)}</small>` : ''}</div>
            ${B.ujra && !['ok', 'kihagyva'].includes(l.allapot) && ['fizetve', 'kiszolgalva'].includes(r.allapot) ? `<button type="button" class="sa-gomb sa-gomb--kicsi" data-ujra="${e(l.kulcs)}" data-ketlepcsos="Biztos? Újraindítás">${I('ujra')}Újra</button>` : '<span></span>'}</div>`; }).join('')}</div>
            ${B.ujra ? '' : '<p class="sa-kicsi sa-halvany">A kiszolgálás modul még nincs bekapcsolva – a lépések nem indíthatók újra.</p>'}`;
        const fiz = (r.fizetesek.length ? `<table class="sa-tabla sa-tabla--suru"><thead><tr><th>Forrás</th><th>Tranzakció</th><th class="sa-jobb">Összeg</th><th>Idő</th><th>Állapot</th></tr></thead><tbody>${r.fizetesek.map((x) => `<tr><td>${e(x.forras)}</td><td class="sa-mono sa-egysor" style="max-width:220px">${e(x.tranzakcio)}</td><td class="sa-jobb">${e(ft(x.osszeg))}</td><td class="sa-halvany">${e(datumIdo(x.ido))}</td><td>${cimke(x.allapot, x.allapot === 'ok' ? 'ok' : 'szurke')}</td></tr>`).join('')}</tbody></table>` : ures('Még nem érkezett fizetés.', 'penz'))
            + (fizetheto ? `<div class="sa-kartya" style="padding:14px"><div class="sa-mezok sa-mezok--3"><div class="sa-mezo"><label class="sa-mezo__cim" for="sa-fiz-o">Összeg (bruttó Ft)</label><input class="sa-input" id="sa-fiz-o" inputmode="numeric" value="${Math.max(0, r.brutto - r.fizetett)}"></div><div class="sa-mezo"><label class="sa-mezo__cim" for="sa-fiz-t">Hivatkozás / megjegyzés</label><input class="sa-input" id="sa-fiz-t" placeholder="pl. bankkivonat sorszáma" maxlength="100"></div><div class="sa-mezo" style="justify-content:flex-end"><button type="button" class="sa-gomb sa-gomb--fo" data-fizetve data-ketlepcsos="Biztos? Fizetettnek jelölöm">${I('pipa')}Fizetve</button></div></div><p class="sa-kicsi sa-halvany" style="margin:8px 0 0">Kézi jelölés: a fizetés naplózva, és elindítja a kiszolgálást.</p></div>` : '');
        const szamlak = `${kv([['Díjbekérő', r.szamla_dijbekero || '–'], ['Végszámla', r.szamla_vegszamla || '–']])}
            ${van('/admin/szamla/{}/pdf') ? `<div class="sa-sor sa-sor--tor">${r.szamla_dijbekero ? `<button type="button" class="sa-gomb" data-pdf="dijbekero">${I('letolt')}Díjbekérő PDF</button>` : ''}${r.szamla_vegszamla ? `<button type="button" class="sa-gomb" data-pdf="vegszamla">${I('letolt')}Végszámla PDF</button>` : ''}</div>` : '<p class="sa-kicsi sa-halvany">A számlázás modul még nincs bekapcsolva.</p>'}
            ${r.blokkok.map((b) => `<h3 class="sa-kartya__cim">${e(b.cim)}</h3>${kv(b.sorok)}`).join('')}`;
        const naplo = r.naplo.length ? `<div class="sa-lista" style="max-height:360px;overflow:auto">${r.naplo.map((n) => `<div class="sa-lista__elem"><span class="sa-cimke sa-cimke--${SZINT[n.szint] || 'szurke'}" style="padding:3px 6px"><span class="sa-pont"></span></span><div class="sa-lista__test"><div class="sa-kicsi">${e(n.szoveg)}</div><div class="sa-lista__al">${e(datumIdo(n.ido))} · ${e(n.ki)}</div></div></div>`).join('')}</div>` : ures('Nincs bejegyzés.');
        const lancHiba = r.lanc.some((l) => l.allapot === 'hiba');
        const { el, bezar } = popup({
            cim: `${r.azon} · ${(v.ceg || v.nev || r.email || '')}`,
            meret: 'szeles',
            test: `<div class="sa-sor sa-sor--tor">${allapotCimke(r.allapot)}${cimke(RTIPUS[r.tipus] || r.tipus)}${lancHiba ? cimke('Kiszolgálási hiba', 'hiba') : ''}<span class="sa-ter"></span><span class="sa-kicsi sa-halvany">Létrehozva ${e(datumIdo(r.letrehozva))}${r.fizetve_ido ? ' · fizetve ' + e(datumIdo(r.fizetve_ido)) : ''}</span></div>`
                + fulHtml([['attekintes', 'Áttekintés', attekint], ['lanc', `Kiszolgálás${lancHiba ? ' <span class="sa-cimke sa-cimke--hiba" style="padding:0 6px">!</span>' : ''}`, lanc], ['fizetes', `Fizetések (${r.fizetesek.length})`, fiz], ['szamla', 'Számlák', szamlak], ['naplo', 'Napló', naplo]], lancHiba ? 'lanc' : 'attekintes'),
            lab: `${B.ujra && ['fizetve', 'kiszolgalva'].includes(r.allapot) ? `<button type="button" class="sa-gomb" data-ujra="email" data-ketlepcsos="Biztos? Újraküldöm">${I('email')}Üdvözlő levél újraküldése</button>` : ''}<span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Bezárás</button>`,
        });
        fulek(el);
        el.addEventListener('click', async (ev) => {
            const g = ev.target.closest('button');
            if (!g) return;
            if (g.dataset.masol) masol(g.dataset.masol);
            if (g.dataset.pdf) tolt(g, () => pdfLetolt(r.id, g.dataset.pdf));
            if (g.dataset.ujra) {
                await tolt(g, async () => {
                    try { const x = await api(`admin/rendeles/${r.id}/ujra`, { method: 'POST', body: { lepes: g.dataset.ujra } }); jelez(x.uzenet, x.ok ? 'ok' : 'figyel'); bezar(); rendelesPopup(id, frissit); if (frissit) frissit(); }
                    catch (x) { jelez(x.message, 'hiba'); }
                });
            }
            if (g.hasAttribute('data-fizetve')) {
                await tolt(g, async () => {
                    try {
                        const osszeg = parseInt(String($('#sa-fiz-o', el).value).replace(/\D/g, ''), 10) || 0;
                        if (osszeg <= 0) { popupHiba(el, 'Adj meg pozitív összeget.'); return; }
                        await api(`admin/rendeles/${r.id}/fizetve`, { method: 'POST', body: { osszeg, tranzakcio: $('#sa-fiz-t', el).value.trim(), megjegyzes: $('#sa-fiz-t', el).value.trim() } });
                        jelez('Fizetettnek jelölve.');
                        bezar(); rendelesPopup(id, frissit); if (frissit) frissit(); menuJelek();
                    } catch (x) { popupHiba(el, x.message); }
                });
            }
        });
    }

    /* ------------------------------------------------------------------ Vevők */

    RAJZOLOK.vevok = async (hova) => {
        const f = S.szurok.vevok = Object.assign({ q: '', oldal: 1 }, S.szurok.vevok || {});
        hova.innerHTML = `<div class="sa-eszkozsor"><input class="sa-input" type="search" data-q placeholder="Cég, e-mail, licenckulcs…" aria-label="Keresés a vevők között" value="${e(f.q)}"><span class="sa-ter"></span><span class="sa-kicsi sa-halvany">A vevők a rendelésekből állnak össze (e-mail-cím szerint).</span></div><div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"></div>`;
        const lista = $('[data-lista]', hova);
        const tolt2 = async () => {
            const r = await api('admin/vevok', { query: { q: f.q, oldal: f.oldal } });
            S.vevok = r.lista;
            lista.innerHTML = r.lista.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Vevő</th><th>E-mail</th><th class="sa-jobb">Rendelés</th><th class="sa-jobb">Költés (bruttó)</th><th>Licencek</th><th>Utolsó</th></tr></thead><tbody>${r.lista.map((x, i) => `<tr class="is-katt" data-vevo="${i}" tabindex="0"><td><div class="sa-vastag sa-egysor" title="${e(x.ceg || x.nev || '')}">${e(x.ceg || x.nev || '–')}</div><div class="sa-kicsi sa-halvany sa-egysor">${e(x.ceg ? x.nev : '')}</div></td><td><div class="sa-egysor" style="--sa-cella-max:280px" title="${e(x.email)}">${e(x.email)}</div></td><td class="sa-jobb">${szam(x.rendeles)}</td><td class="sa-jobb">${e(ft(x.koltes))}</td><td>${x.licencek.length ? x.licencek.slice(0, 2).map((k) => `<span class="sa-cimke sa-cimke--mono">${e(k)}</span>`).join(' ') + (x.licencek.length > 2 ? ` <span class="sa-halvany">+${x.licencek.length - 2}</span>` : '') : '<span class="sa-halvany">–</span>'}</td><td class="sa-halvany">${e(relativ(x.utolso))}</td></tr>`).join('')}</tbody></table></div>` : ures(f.q ? 'Nincs ilyen vevő.' : 'Még nincs vevő.', 'vevo');
            if (r.ossz > r.db) lista.appendChild(lapozo(r.ossz, r.oldal, r.db, (o) => { f.oldal = o; tolt2(); }));
        };
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); f.oldal = 1; tolt2(); }));
        sorKattint(lista, '[data-vevo]', (s) => vevoPopup(S.vevok[Number(s.dataset.vevo)]));
        await tolt2();
    };

    async function vevoPopup(v) {
        const r = await api('admin/rendelesek', { query: { email: v.email, db: 50 } });
        const { el } = popup({
            cim: v.ceg || v.nev || v.email,
            meret: 'kozepes',
            test: `<div class="sa-racs sa-racs--fele"><dl class="sa-kv"><dt>Kapcsolattartó</dt><dd>${e(v.nev || '–')}</dd><dt>E-mail</dt><dd>${e(v.email)}</dd><dt>Telefon</dt><dd>${e(v.telefon || '–')}</dd><dt>Adószám</dt><dd>${e(v.adoszam || '–')}</dd><dt>Cím</dt><dd>${e(v.cim || '–')}</dd></dl>
                <dl class="sa-kv"><dt>Rendelések</dt><dd>${szam(v.rendeles)}</dd><dt>Költés</dt><dd>${e(ft(v.koltes))}</dd><dt>Licencek</dt><dd>${v.licencek.map((k) => `<span class="sa-cimke sa-cimke--mono">${e(k)}</span>`).join(' ') || '–'}</dd></dl></div>
                <div class="sa-kartya sa-kartya--sima" style="max-height:300px;overflow:auto"><table class="sa-tabla sa-tabla--suru"><thead><tr><th>Azonosító</th><th>Típus</th><th>Állapot</th><th class="sa-jobb">Bruttó</th><th>Dátum</th></tr></thead><tbody>${r.lista.map((x) => `<tr class="is-katt" data-rendeles="${x.id}" tabindex="0"><td class="sa-mono">${e(x.azon)}</td><td>${e(RTIPUS[x.tipus] || x.tipus)}</td><td>${allapotCimke(x.allapot)}</td><td class="sa-jobb">${e(ft(x.brutto))}</td><td class="sa-halvany">${e(datum(x.letrehozva))}</td></tr>`).join('')}</tbody></table></div>`,
            lab: `<a class="sa-gomb" href="mailto:${e(v.email)}">${I('email')}Levél</a><span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Bezárás</button>`,
        });
        sorKattint(el, '[data-rendeles]', (s) => rendelesPopup(Number(s.dataset.rendeles)));
    }

    /* ------------------------------------------------------------------ Leadek */

    RAJZOLOK.leadek = async (hova) => {
        const f = S.szurok.leadek = Object.assign({ forras: 'mind', allapot: '', q: '', oldal: 1 }, S.szurok.leadek || {});
        const ALL = B.lead_allapotok || {};
        hova.innerHTML = `<div class="sa-eszkozsor"><div data-forras></div><span class="sa-ter"></span>
            <select class="sa-select" data-allapot aria-label="Állapot" style="width:auto"><option value="">Minden állapot</option>${Object.entries(ALL).map(([k, v]) => `<option value="${e(k)}" ${f.allapot === k ? 'selected' : ''}>${e(v)}</option>`).join('')}</select>
            <input class="sa-input" type="search" data-q placeholder="E-mail, név, cég…" aria-label="Keresés a leadek között" value="${e(f.q)}"></div>
            <div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"></div>`;
        const lista = $('[data-lista]', hova);
        const tolt2 = async () => {
            const r = await api('admin/leadek', { query: { forras: f.forras, allapot: f.allapot, q: f.q, oldal: f.oldal } });
            S.leadek = r.lista;
            const db = (r.szamok && r.szamok.forras) || {};
            const ossz = Object.values(db).reduce((a, b) => a + b, 0);
            $('[data-forras]', hova).innerHTML = szegmens('Forrás', Object.assign({ mind: `Mind <small>${ossz}</small>` }, Object.fromEntries(Object.entries(LEAD_FORRAS).map(([k, v]) => [k, `${e(v)} <small>${db[k] || 0}</small>`]))), f.forras);
            lista.innerHTML = r.lista.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Kapcsolat</th><th>Forrás</th><th>Állapot</th><th>Érkezett</th></tr></thead><tbody>${r.lista.map((x, i) => `<tr class="is-katt" data-lead="${i}" tabindex="0"><td><div class="sa-vastag sa-egysor" style="--sa-cella-max:360px" title="${e(x.nev || x.email)}">${e(x.nev || x.email)}</div><div class="sa-kicsi sa-halvany sa-egysor" style="--sa-cella-max:360px" title="${e([x.ceg, x.nev ? x.email : ''].filter(Boolean).join(' · '))}">${e([x.ceg, x.nev ? x.email : ''].filter(Boolean).join(' · '))}</div></td><td>${cimke(LEAD_FORRAS[x.forras] || x.forras, 'kiemelo')}</td><td>${cimke(ALL[x.allapot] || x.allapot, { uj: 'figyel', nyert: 'ok', elveszett: 'szurke', spam: 'szurke' }[x.allapot] || 'szurke')}</td><td class="sa-halvany">${e(datumIdo(x.ido))}</td></tr>`).join('')}</tbody></table></div>` : ures('Ebben a nézetben nincs lead.', 'lead');
            if (r.ossz > r.db) lista.appendChild(lapozo(r.ossz, r.oldal, r.db, (o) => { f.oldal = o; tolt2(); }));
        };
        hova.addEventListener('valtozas', (ev) => { if (ev.target.closest('[data-forras]')) { f.forras = ev.detail; f.oldal = 1; tolt2(); } });
        $('[data-allapot]', hova).addEventListener('change', (ev) => { f.allapot = ev.target.value; f.oldal = 1; tolt2(); });
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); f.oldal = 1; tolt2(); }));
        sorKattint(lista, '[data-lead]', (s) => leadPopup(S.leadek[Number(s.dataset.lead)], tolt2));
        await tolt2();
    };

    function leadPopup(l, frissit) {
        const ALL = B.lead_allapotok || {};
        const adat = Object.entries(l.adat || {}).filter(([k]) => k !== 'admin_megjegyzes');
        const ertek = (x) => (x && typeof x === 'object' ? (Array.isArray(x) ? x.join(', ') : Object.entries(x).map(([a, b]) => `${a}: ${typeof b === 'object' ? JSON.stringify(b) : b}`).join('; ')) : String(x ?? ''));
        const { el, bezar } = popup({
            cim: `${LEAD_FORRAS[l.forras] || l.forras}: ${l.nev || l.email}`,
            meret: 'kozepes',
            test: `<div class="sa-sor sa-sor--tor"><span class="sa-mezo__cim">Állapot</span>${szegmens('Állapot', Object.fromEntries(Object.entries(ALL).map(([k, v]) => [k, e(v)])), l.allapot)}</div>
                <div style="max-height:300px;overflow:auto"><dl class="sa-kv"><dt>E-mail</dt><dd><a href="mailto:${e(l.email)}">${e(l.email)}</a></dd><dt>Érkezett</dt><dd>${e(datumIdo(l.ido))}</dd>${adat.map(([k, v]) => `<dt>${e(mezoNev(k))}</dt><dd style="white-space:pre-wrap">${e(ertek(v))}</dd>`).join('')}</dl></div>
                <div class="sa-mezo"><label class="sa-mezo__cim" for="sa-lead-m">Belső megjegyzés</label><textarea class="sa-textarea" id="sa-lead-m" rows="3" maxlength="2000">${e((l.adat || {}).admin_megjegyzes || '')}</textarea></div>`,
            lab: `<a class="sa-gomb" href="mailto:${e(l.email)}">${I('email')}Válasz</a><span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Mégse</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button>`,
        });
        let all = l.allapot;
        $('[data-szegmens]', el).addEventListener('valtozas', (ev) => { all = ev.detail; });
        $('[data-ment]', el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            try { await api('admin/lead/' + l.id, { method: 'POST', body: { allapot: all, megjegyzes: $('#sa-lead-m', el).value } }); jelez('Lead mentve.'); bezar(); frissit(); menuJelek(); }
            catch (x) { popupHiba(el, x.message); }
        }));
    }

    /* ------------------------------------------------------------------ Foglalások (a naptár modul végpontján át) */

    RAJZOLOK.foglalasok = async (hova) => {
        if (!van('/admin/foglalasok')) { hova.innerHTML = nincsBekapcsolva('Foglalások'); return; }
        const f = S.szurok.foglalasok = Object.assign({ allapot: 'foglalt', oldal: 1 }, S.szurok.foglalasok || {});
        const ALL = { foglalt: 'Foglalt', atfoglalva: 'Átfoglalva', lemondva: 'Lemondva', mind: 'Mind' };
        hova.innerHTML = `<div class="sa-eszkozsor">${szegmens('Állapot', ALL, f.allapot)}<span class="sa-ter"></span></div><div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"></div>`;
        const lista = $('[data-lista]', hova);
        const tolt2 = async () => {
            const r = await api('admin/foglalasok', { query: { allapot: f.allapot === 'mind' ? '' : f.allapot, oldal: f.oldal, db: 25 } });
            const sorok = r.sorok || r.lista || [];
            lista.innerHTML = sorok.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Időpont</th><th>Típus</th><th>Név</th><th>Elérhetőség</th><th>Állapot</th><th></th></tr></thead><tbody>${sorok.map((x) => `<tr><td class="sa-vastag">${e(x.mikor || x.kezdet)}</td><td>${cimke(x.tipus_nev || x.tipus, 'kiemelo')}</td><td>${e(x.nev)}<div class="sa-kicsi sa-halvany">${e(x.ceg || '')}</div></td><td>${e(x.email)}<div class="sa-kicsi sa-halvany">${e(x.telefon || '')}</div></td><td>${cimke(ALL[x.allapot] || x.allapot, x.allapot === 'foglalt' ? 'ok' : 'szurke')}</td><td class="sa-jobb">${x.allapot === 'foglalt' && van('/admin/foglalasok/{}/lemond') ? `<button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--veszely" data-lemond="${Number(x.id)}" data-ketlepcsos="Biztos? Lemondom">Lemondás</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : ures('Ebben a nézetben nincs foglalás.', 'naptar');
            if (r.ossz > 25) lista.appendChild(lapozo(r.ossz, f.oldal, 25, (o) => { f.oldal = o; tolt2(); }));
        };
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { f.allapot = ev.detail; f.oldal = 1; tolt2(); });
        lista.addEventListener('click', (ev) => {
            const g = ev.target.closest('[data-lemond]');
            if (g) tolt(g, async () => { try { await api(`admin/foglalasok/${g.dataset.lemond}/lemond`, { method: 'POST', body: {} }); jelez('Foglalás lemondva, a vevő értesítést kap.'); tolt2(); } catch (x) { jelez(x.message, 'hiba'); } });
        });
        await tolt2();
    };

    /* ------------------------------------------------------------------ Napló (előtte / utána, visszaállítás) */

    RAJZOLOK.naplo = async (hova) => {
        const f = S.szurok.naplo = Object.assign({ tipus: '', szint: 'mind', q: '', oldal: 1 }, S.szurok.naplo || {});
        hova.innerHTML = `<div class="sa-eszkozsor">${szegmens('Szint', { mind: 'Mind', info: 'Infó', siker: 'Siker', figyel: 'Figyelem', hiba: 'Hiba' }, f.szint)}<span class="sa-ter"></span>
            <select class="sa-select" data-tipus aria-label="Típus" style="width:auto"><option value="">Minden típus</option></select>
            <input class="sa-input" type="search" data-q placeholder="Keresés a naplóban…" aria-label="Keresés a naplóban" value="${e(f.q)}"></div>
            <div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"></div>`;
        const lista = $('[data-lista]', hova);
        const tolt2 = async () => {
            const r = await api('admin/naplo', { query: { tipus: f.tipus, szint: f.szint, q: f.q, oldal: f.oldal } });
            const ts = $('[data-tipus]', hova);
            ts.innerHTML = '<option value="">Minden típus</option>' + r.tipusok.map((t) => `<option value="${e(t)}" ${t === f.tipus ? 'selected' : ''}>${e(t)}</option>`).join('');
            lista.innerHTML = r.lista.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Idő</th><th>Típus</th><th>Esemény</th><th>Ki</th><th></th></tr></thead><tbody>${r.lista.map((n) => `<tr class="${n.valtozas ? 'is-katt' : ''}" ${n.valtozas ? `data-naplo="${n.id}" tabindex="0"` : ''}><td class="sa-halvany" style="white-space:nowrap">${e(datumIdo(n.ido))}</td><td>${cimke(n.tipus, SZINT[n.szint] || 'szurke')}</td><td>${e(n.szoveg)}</td><td class="sa-halvany">${e(n.ki)}</td><td class="sa-jobb">${n.visszaallithato ? `<span class="sa-cimke sa-cimke--kiemelo">${I('visszaall')}Visszaállítható</span>` : n.valtozas ? '<span class="sa-cimke">Változás</span>' : ''}</td></tr>`).join('')}</tbody></table></div>` : ures('Nincs ilyen bejegyzés.', 'naplo');
            if (r.ossz > r.db) lista.appendChild(lapozo(r.ossz, r.oldal, r.db, (o) => { f.oldal = o; tolt2(); }));
        };
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { f.szint = ev.detail; f.oldal = 1; tolt2(); });
        $('[data-tipus]', hova).addEventListener('change', (ev) => { f.tipus = ev.target.value; f.oldal = 1; tolt2(); });
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); f.oldal = 1; tolt2(); }));
        sorKattint(lista, '[data-naplo]', (s) => naploPopup(Number(s.dataset.naplo), tolt2));
        await tolt2();
    };

    /** Lapos kulcs → érték térkép egy JSON-ból (diffhez). */
    function lapit(o, elo = '', ki = {}) {
        if (o === null || typeof o !== 'object') { ki[elo || '(érték)'] = o; return ki; }
        const k = Object.keys(o);
        if (!k.length) ki[elo || '(üres)'] = Array.isArray(o) ? [] : {};
        k.forEach((x) => { const u = elo ? `${elo}.${x}` : x; if (o[x] !== null && typeof o[x] === 'object' && Object.keys(o[x]).length && JSON.stringify(o[x]).length > 160) lapit(o[x], u, ki); else ki[u] = o[x]; });
        return ki;
    }
    const diffErtek = (v) => (v === undefined ? '<span class="sa-halvany">—</span>' : `<pre>${e(typeof v === 'string' ? v : JSON.stringify(v, null, 1))}</pre>`);
    function diffHtml(elotte, utana, csakValtozott = true) {
        const a = lapit(elotte ?? {}), b = lapit(utana ?? {});
        const kulcsok = Array.from(new Set([...Object.keys(a), ...Object.keys(b)])).filter((k) => !csakValtozott || JSON.stringify(a[k]) !== JSON.stringify(b[k]));
        if (!kulcsok.length) return ures('Nincs eltérés.');
        return `<div class="sa-diff"><div class="sa-diff__fej">Mező</div><div class="sa-diff__fej">Előtte</div><div class="sa-diff__fej">Utána</div>${kulcsok.slice(0, 120).map((k) => `<div class="sa-diff__kulcs">${e(k)}</div><div class="sa-diff__regi">${diffErtek(a[k])}</div><div class="sa-diff__uj">${diffErtek(b[k])}</div>`).join('')}</div>`;
    }

    async function naploPopup(id, frissit) {
        let n;
        try { n = await api('admin/naplo/' + id); } catch (x) { jelez(x.message, 'hiba'); return; }
        const { el, bezar } = popup({
            cim: `Változás #${n.id}`,
            meret: 'szeles',
            test: `<div class="sa-sor sa-sor--tor">${cimke(n.tipus, SZINT[n.szint] || 'szurke')}<span>${e(n.szoveg)}</span><span class="sa-ter"></span><span class="sa-kicsi sa-halvany">${e(datumIdo(n.ido))} · ${e(n.ki)}</span></div><div style="max-height:min(52vh,460px);overflow:auto">${diffHtml(n.elotte, n.utana)}</div>`,
            lab: `${n.visszaallithato ? `<span class="sa-kicsi sa-halvany">A visszaállítás is naplózódik, így az is visszavonható.</span>` : ''}<span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Bezárás</button>${n.visszaallithato ? `<button type="button" class="sa-gomb sa-gomb--fo" data-vissza data-ketlepcsos="Biztos? Visszaállítom">${I('visszaall')}Visszaállítás</button>` : ''}`,
        });
        const g = $('[data-vissza]', el);
        if (g) g.addEventListener('click', () => tolt(g, async () => {
            try { await api(`admin/naplo/${id}/visszaallit`, { method: 'POST', body: {} }); jelez('Visszaállítva.'); bezar(); if (frissit) frissit(); }
            catch (x) { popupHiba(el, x.message); }
        }));
    }

    /* ------------------------------------------------------------------ Parancspaletta (Ctrl+K) */

    const PARANCSOK = [];

    function paletta() {
        if ($('.sa-paletta')) return;
        const elozo = document.activeElement;
        const alap = [
            ...panelek().filter(([, p]) => p.elerheto).map(([k, p]) => ({ cim: p.cim, ik: p.ikon || PANEL_IKON[k], csop: 'Ugrás', fn: () => { location.hash = '#/' + (k === 'attekintes' ? '' : k); } })),
            ...PARANCSOK,
            { cim: 'Világos / sötét téma', ik: 'nap', csop: 'Parancsok', fn: temaValt },
            { cim: 'Haladó rendszer-admin', ik: 'wp', csop: 'Parancsok', fn: () => { location.href = B.wp; } },
            { cim: 'Webhely megnyitása', ik: 'kulso', csop: 'Parancsok', fn: () => window.open(B.webhely, '_blank', 'noopener') },
        ];
        const hatter = document.createElement('div');
        hatter.className = 'sa-popup-hatter';
        hatter.innerHTML = `<div class="sa-popup sa-paletta" role="dialog" aria-modal="true" aria-label="Keresés és parancsok"><div class="sa-paletta__mezo">${I('kereses')}<input role="combobox" aria-expanded="true" aria-controls="sa-paletta-lista" aria-autocomplete="list" placeholder="Rendelés (azonosító, e-mail), oldal vagy parancs…" aria-label="Keresés"><kbd class="sa-cimke sa-cimke--mono">Esc</kbd></div><div class="sa-paletta__lista" id="sa-paletta-lista" role="listbox"></div></div>`;
        document.body.appendChild(hatter);
        const inp = $('input', hatter), lista = $('.sa-paletta__lista', hatter);
        let elemek = [], aktiv = 0, kesl, talalat = [];
        const bezar = () => { hatter.remove(); if (elozo && elozo.isConnected) elozo.focus(); };
        const rajzol = () => {
            const q = inp.value.trim().toLowerCase();
            const p = alap.filter((x) => !q || x.cim.toLowerCase().includes(q));
            const t = talalat.map((x) => ({
                cim: x.cim, al: x.al, ik: { rendeles: 'rendeles', oldal: 'oldal', lead: 'lead' }[x.tipus] || 'info', csop: { rendeles: 'Rendelések', oldal: 'Oldalak', lead: 'Leadek' }[x.tipus] || 'Találat',
                fn: () => { if (x.tipus === 'rendeles') rendelesPopup(x.id); else if (x.tipus === 'oldal') location.hash = '#/oldalak/' + x.id; else location.hash = '#/leadek'; },
            }));
            elemek = [...t, ...p];
            aktiv = Math.min(aktiv, Math.max(0, elemek.length - 1));
            let csop = '';
            lista.innerHTML = elemek.map((x, i) => { const fej = x.csop !== csop ? `<div class="sa-paletta__csoport" role="presentation">${e(x.csop)}</div>` : ''; csop = x.csop; return `${fej}<div class="sa-paletta__elem" role="option" id="sa-pe-${i}" aria-selected="${i === aktiv}" data-i="${i}">${I(x.ik)}<span>${e(x.cim)}</span>${x.al ? `<small>${e(x.al)}</small>` : ''}</div>`; }).join('') || ures('Nincs találat.');
            inp.setAttribute('aria-activedescendant', elemek.length ? 'sa-pe-' + aktiv : '');
            const a = $(`#sa-pe-${aktiv}`, lista);
            if (a) a.scrollIntoView({ block: 'nearest' });
        };
        const futtat = (i) => { const x = elemek[i]; if (!x) return; bezar(); x.fn(); };
        inp.addEventListener('input', () => {
            aktiv = 0; talalat = []; rajzol(); clearTimeout(kesl);
            if (inp.value.trim().length >= 2) kesl = setTimeout(async () => { try { const r = await api('admin/kereses', { query: { q: inp.value.trim() } }); talalat = r.lista || []; rajzol(); } catch (x) { /* csendben */ } }, 180);
        });
        inp.addEventListener('keydown', (ev) => {
            if (ev.key === 'ArrowDown') { aktiv = Math.min(elemek.length - 1, aktiv + 1); rajzol(); ev.preventDefault(); }
            if (ev.key === 'ArrowUp') { aktiv = Math.max(0, aktiv - 1); rajzol(); ev.preventDefault(); }
            if (ev.key === 'Enter') { ev.preventDefault(); futtat(aktiv); }
            if (ev.key === 'Escape') { ev.preventDefault(); bezar(); }
            if (ev.key === 'Tab') ev.preventDefault();
        });
        lista.addEventListener('click', (ev) => { const x = ev.target.closest('[data-i]'); if (x) futtat(Number(x.dataset.i)); });
        hatter.addEventListener('mousedown', (ev) => { if (ev.target === hatter) bezar(); });
        rajzol();
        setTimeout(() => inp.focus(), 20);
    }

    document.addEventListener('keydown', (ev) => {
        if ((ev.ctrlKey || ev.metaKey) && !ev.shiftKey && ev.key.toLowerCase() === 'k') { ev.preventDefault(); if (!$('.sa-popup-hatter')) paletta(); }
    });

    /* ------------------------------------------------------------------ Nyilvános felület (a többi fájlnak) */

    window.PA = {
        B, S, $, $$, e, I, WI, api, jelez, bemond, masol, letolt, tolt, popup, popupHiba, fulek, fulHtml, urlap, urlapMezok, mezoVezerlo, kapcsolo,
        szegmens, lapozo, sorKattint, kesleltet, rendezheto, elemLista, elemCache, ures, csontvaz, hibaKartya, nincsBekapcsolva, cim, diffHtml, lapit,
        cimke, ft, szam, brutto, datum, datumIdo, relativ, van, lebego, kiolvas, beir, rendelesPopup,
        panel: (kulcs, fn) => { RAJZOLOK[kulcs] = fn; },
        parancs: (cim, ik, fn, csop = 'Parancsok') => { PARANCSOK.push({ cim, ik, fn, csop }); },
        indul: () => { vaz(); route(); menuJelek(); setInterval(() => { if (!document.hidden) menuJelek(); }, 120000); },
    };
})();
