/* SDH Központ 2.0 – egyoldalas felület (külső könyvtár nélkül). */
(() => {
    'use strict';

    const B = window.SDH_KP || {};
    const $ = (s, el = document) => el.querySelector(s);
    const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
    const e = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /* ------------------------------------------------------------------ Ikonok */

    const P = {
        attekintes: '<rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/>',
        rendszer: '<rect x="2" y="4" width="20" height="13" rx="2.5"/><path d="M8 21h8M12 17v4"/><path d="M6 12l2.5-3 2.5 2 3-4 4 5"/>',
        vevo: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        licenc: '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
        kiadas: '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
        tamogatas: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><path d="m4.93 4.93 4.24 4.24M14.83 14.83l4.24 4.24M14.83 9.17l4.24-4.24M4.93 19.07l4.24-4.24"/>',
        naplo: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        beallitas: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        kereses: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        nap: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        hold: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/>',
        wp: '<circle cx="12" cy="12" r="10"/><path d="M3.5 9h17M3.5 15h17M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
        menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
        x: '<path d="M18 6 6 18M6 6l12 12"/>',
        plusz: '<path d="M12 5v14M5 12h14"/>',
        pipa: '<path d="M20 6 9 17l-5-5"/>',
        figyel: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
        info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        villam: '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
        aktiv: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        db: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>',
        lemez: '<path d="M22 12H2M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11zM6 16h.01M10 16h.01"/>',
        mentes: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
        felhasznalo: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        munkalap: '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 12h6M9 16h6"/>',
        diagnosztika: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        gyorsitotar: '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        halozat: '<path d="M5 12.55a11 11 0 0 1 14.08 0M1.42 9a16 16 0 0 1 21.16 0M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01"/>',
        frissites_keres: '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        frissites_telepit: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        uzenet: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        uzenet_torol: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="m9 8 6 6M15 8l-6 6"/>',
        tamogatas_keres: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/>',
        mentes_most: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>',
        kulso: '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/>',
        masol: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        szerkeszt: '<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        feltolt: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        penz: '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
        ora: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        szerver: '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/>',
        modul: '<path d="M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>',
        pajzs: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        nyil: '<path d="m9 18 6-6-6-6"/>',
        vissza: '<path d="m15 18-6-6 6-6"/>',
        kilep: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        jegyzet: '<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    };
    const I = (n, cls = '') => `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${P[n] || P.info}</svg>`;

    /* ------------------------------------------------------------------ Formázás */

    const nf = new Intl.NumberFormat('hu-HU');
    const szam = (n) => nf.format(Math.round(Number(n) || 0));
    const ft = (n) => szam(n) + ' Ft';
    const ms = (n) => (n >= 1000 ? (n / 1000).toFixed(1).replace('.', ',') + ' s' : szam(n) + ' ms');
    const mb = (n) => (n < 0 ? '–' : n >= 1024 ? (n / 1024).toFixed(1).replace('.', ',') + ' GB' : szam(n) + ' MB');
    const ketjegy = (n) => String(n).padStart(2, '0');
    const datum = (ts) => { if (!ts) return '–'; const d = new Date(ts * 1000); return `${d.getFullYear()}.${ketjegy(d.getMonth() + 1)}.${ketjegy(d.getDate())}.`; };
    const datumIdo = (ts) => { if (!ts) return '–'; const d = new Date(ts * 1000); return `${datum(ts)} ${ketjegy(d.getHours())}:${ketjegy(d.getMinutes())}`; };
    const isoDatum = (s) => (s ? s.replace(/-/g, '.') + '.' : '–');
    const oraPerc = (ts) => { const d = new Date(ts * 1000); return `${ketjegy(d.getHours())}:${ketjegy(d.getMinutes())}`; };
    const mostTs = () => Math.floor(Date.now() / 1000);
    const relativ = (ts) => {
        if (!ts) return 'soha';
        const d = mostTs() - ts;
        if (d < 0) { const f = -d; if (f < 3600) return `${Math.ceil(f / 60)} perc múlva`; if (f < 86400) return `${Math.round(f / 3600)} óra múlva`; return `${Math.round(f / 86400)} nap múlva`; }
        if (d < 45) return 'most';
        if (d < 3600) return `${Math.max(1, Math.round(d / 60))} perce`;
        if (d < 86400) return `${Math.round(d / 3600)} órája`;
        if (d < 86400 * 30) return `${Math.round(d / 86400)} napja`;
        return datum(ts);
    };

    const STATUSZ = { online: 'Működik', figyel: 'Figyelmet kér', hiba: 'Hiba', offline: 'Nem jelentkezik', napi: 'Napi jelentés (régi verzió)', uj: 'Új' };
    const STATUSZ_CIMKE = { online: 'ok', figyel: 'figyel', hiba: 'hiba', offline: 'szurke', napi: 'kiemelo', uj: 'szurke' };
    const LICENC_ALLAPOT = { aktiv: 'Aktív', lejart: 'Lejárt', tiltva: 'Letiltva' };
    const CIKLUS = { havi: 'havonta', eves: 'évente', egyszeri: 'egyszeri' };
    const MUVELET_LEIRAS = {
        diagnosztika: 'Teljes állapotkép és sebességmérés most.',
        mentes: 'Azonnali teljes mentés a vevő gépén.',
        gyorsitotar: 'A rendszer gyorsítótárainak ürítése.',
        halozat: 'Internet- és helyi kapcsolat ellenőrzése.',
        frissites_keres: 'Megnézi, van-e újabb verzió.',
        frissites_telepit: 'Mentés után telepíti az újat.',
        uzenet: 'Sáv a vevő adminisztrátorainak.',
        uzenet_torol: 'A kiküldött üzenet levétele.',
        tamogatas_keres: 'A vevő egy gombbal engedi a belépést.',
    };
    const MUVELET_ALLAPOT = { var: ['Vár', 'szurke'], kuldve: ['Kiküldve', 'kiemelo'], kesz: ['Kész', 'ok'], hiba: ['Hiba', 'hiba'], lejart: ['Lejárt', 'szurke'] };

    /* ------------------------------------------------------------------ API */

    async function api(ut, { method = 'GET', body = null, form = null, query = null } = {}) {
        let url = B.rest + ut;
        if (query) {
            const q = new URLSearchParams(Object.entries(query).filter(([, v]) => v !== '' && v !== null && v !== undefined));
            url += (url.includes('?') ? '&' : '?') + q.toString();
        }
        const opt = { method, headers: { 'X-WP-Nonce': B.nonce }, credentials: 'same-origin' };
        if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
        if (form) opt.body = form;
        const r = await fetch(url, opt);
        let j = null;
        try { j = await r.json(); } catch (x) { j = null; }
        if (!r.ok) {
            if (r.status === 401 || r.status === 403) {
                if (j && j.code === 'rest_cookie_invalid_nonce') { location.reload(); }
            }
            throw new Error((j && j.message) || `Hiba (${r.status})`);
        }
        return j;
    }

    /* ------------------------------------------------------------------ Értesítés */

    function jelez(szoveg, tipus = 'ok') {
        let box = $('.kp-ertesitesek');
        if (!box) { box = document.createElement('div'); box.className = 'kp-ertesitesek'; document.body.appendChild(box); }
        const el = document.createElement('div');
        el.className = `kp-ertesites kp-ertesites--${tipus}`;
        el.setAttribute('role', 'status');
        el.textContent = szoveg;
        box.appendChild(el);
        setTimeout(() => { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 320); }, 3800);
    }

    async function masol(szoveg) {
        try { await navigator.clipboard.writeText(szoveg); jelez('Vágólapra másolva.'); } catch (x) { jelez('Nem sikerült másolni.', 'hiba'); }
    }

    /* ------------------------------------------------------------------ Kétlépcsős gomb (confirm helyett) */

    document.addEventListener('click', (ev) => {
        const g = ev.target.closest('[data-ketlepcsos]');
        if (!g || g.disabled) return;
        if (!g.classList.contains('is-elesitve')) {
            ev.preventDefault();
            ev.stopImmediatePropagation();
            g.dataset.eredeti = g.innerHTML;
            g.classList.add('is-elesitve');
            g.innerHTML = e(g.dataset.ketlepcsos || 'Biztos? Kattints újra');
            g._idozito = setTimeout(() => { g.classList.remove('is-elesitve'); g.innerHTML = g.dataset.eredeti; }, 4000);
            return;
        }
        clearTimeout(g._idozito);
        g.classList.remove('is-elesitve');
        if (g.dataset.eredeti) g.innerHTML = g.dataset.eredeti;
    }, true);

    /* ------------------------------------------------------------------ Diagramok */

    function spark(ertekek, { szin = 'var(--kp-kiemelo)', w = 120, hh = 34, kitolt = true } = {}) {
        const v = (ertekek || []).filter((x) => x !== null && x !== undefined);
        if (v.length < 2) return `<svg viewBox="0 0 ${w} ${hh}" class="kp-spark" preserveAspectRatio="none"><path d="M0 ${hh - 2}H${w}" stroke="var(--kp-vonal-eros)" stroke-dasharray="3 3"/></svg>`;
        const max = Math.max(...v), min = Math.min(...v), tav = max - min || 1;
        const pt = v.map((x, i) => [(i / (v.length - 1)) * w, hh - 3 - ((x - min) / tav) * (hh - 6)]);
        const d = pt.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join('');
        const id = 'g' + Math.random().toString(36).slice(2, 8);
        return `<svg viewBox="0 0 ${w} ${hh}" class="kp-spark" preserveAspectRatio="none" style="width:100%;height:${hh}px">
            <defs><linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${szin}" stop-opacity=".35"/><stop offset="1" stop-color="${szin}" stop-opacity="0"/></linearGradient></defs>
            ${kitolt ? `<path d="${d}L${w} ${hh}L0 ${hh}Z" fill="url(#${id})"/>` : ''}
            <path d="${d}" fill="none" stroke="${szin}" stroke-width="1.8" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/></svg>`;
    }

    /** Területdiagram tengelyekkel és tippel. sorok: [{nev, ertekek, szin}], t: időbélyegek. */
    function terulet(el, { t, sorok, egyseg = '', format = (x) => szam(x) + egyseg, magas = 220, napos = false }) {
        const W = Math.max(320, el.clientWidth || 600), H = magas, bal = 46, jobb = 10, fel = 10, le = 24;
        const mind = sorok.flatMap((s) => s.ertekek.filter((x) => x !== null && x !== undefined));
        if (!t.length || !mind.length) { el.innerHTML = `<div class="kp-ures" style="height:${H}px;display:grid;place-content:center">Még nincs mérés ebből az időszakból.</div>`; return; }
        let max = Math.max(...mind, 1);
        const lepes = Math.pow(10, Math.floor(Math.log10(max)));
        max = Math.ceil(max / lepes) * lepes;
        const t0 = t[0], t1 = t[t.length - 1] === t0 ? t0 + 1 : t[t.length - 1];
        const x = (ts) => bal + ((ts - t0) / (t1 - t0)) * (W - bal - jobb);
        const y = (v) => fel + (1 - v / max) * (H - fel - le);
        let racs = '';
        for (let i = 0; i <= 4; i++) {
            const v = (max / 4) * i;
            racs += `<line class="kp-diagram__racs" x1="${bal}" x2="${W - jobb}" y1="${y(v)}" y2="${y(v)}"/><text class="kp-diagram__tengely" x="${bal - 8}" y="${y(v) + 4}" text-anchor="end">${e(format(v).replace(/ (ms|MB|db)$/, ''))}</text>`;
        }
        const n = Math.min(6, t.length);
        for (let i = 0; i < n; i++) {
            const ts = t0 + ((t1 - t0) / Math.max(1, n - 1)) * i;
            racs += `<text class="kp-diagram__tengely" x="${x(ts)}" y="${H - 6}" text-anchor="${i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}">${napos ? datum(ts).slice(5) : oraPerc(ts)}</text>`;
        }
        let utak = '';
        sorok.forEach((s, si) => {
            const pontok = [];
            s.ertekek.forEach((v, i) => { if (v !== null && v !== undefined) pontok.push([x(t[i]), y(v)]); });
            if (!pontok.length) return;
            const d = pontok.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join('');
            const id = 'a' + si + Math.random().toString(36).slice(2, 7);
            utak += `<defs><linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${s.szin}" stop-opacity="${si ? '.12' : '.32'}"/><stop offset="1" stop-color="${s.szin}" stop-opacity="0"/></linearGradient></defs>`;
            utak += `<path d="${d}L${pontok[pontok.length - 1][0]} ${y(0)}L${pontok[0][0]} ${y(0)}Z" fill="url(#${id})"/>`;
            utak += `<path d="${d}" fill="none" stroke="${s.szin}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" ${si ? 'stroke-dasharray="4 3"' : ''}/>`;
        });
        el.innerHTML = `<div class="kp-diagram"><svg viewBox="0 0 ${W} ${H}" height="${H}">${racs}${utak}<line class="kp-diagram__fuggoleges" y1="${fel}" y2="${H - le}" x1="-10" x2="-10"/><rect x="${bal}" y="0" width="${W - bal - jobb}" height="${H}" fill="transparent"/></svg><div class="kp-diagram__tipp" hidden></div></div>`;
        const svg = $('svg', el), tipp = $('.kp-diagram__tipp', el), vonal = $('.kp-diagram__fuggoleges', el);
        svg.addEventListener('mousemove', (ev) => {
            const r = svg.getBoundingClientRect();
            const px = ((ev.clientX - r.left) / r.width) * W;
            let bi = 0, bd = Infinity;
            t.forEach((ts, i) => { const d = Math.abs(x(ts) - px); if (d < bd) { bd = d; bi = i; } });
            const xx = x(t[bi]);
            vonal.setAttribute('x1', xx); vonal.setAttribute('x2', xx);
            tipp.hidden = false;
            tipp.style.left = (xx / W) * 100 + '%';
            tipp.style.top = (y(Math.max(...sorok.map((s) => s.ertekek[bi] || 0))) / H) * 100 + '%';
            tipp.innerHTML = `<div class="kp-halvany kp-kicsi">${napos ? datumIdo(t[bi]) : datumIdo(t[bi])}</div>` + sorok.map((s) => `<div><span style="color:${s.szin}">●</span> ${e(s.nev)}: <b>${s.ertekek[bi] === null || s.ertekek[bi] === undefined ? '–' : e(format(s.ertekek[bi]))}</b></div>`).join('');
        });
        svg.addEventListener('mouseleave', () => { tipp.hidden = true; vonal.setAttribute('x1', -10); vonal.setAttribute('x2', -10); });
    }

    function fanko(szeletek) {
        const ossz = szeletek.reduce((a, s) => a + s.ertek, 0) || 1;
        let szog = -90;
        const R = 52, r = 36, c = 70;
        const pt = (a, rr) => [c + rr * Math.cos((a * Math.PI) / 180), c + rr * Math.sin((a * Math.PI) / 180)];
        let utak = '';
        szeletek.forEach((s) => {
            if (!s.ertek) return;
            const tart = (s.ertek / ossz) * 360;
            if (tart >= 359.9) { utak += `<circle cx="${c}" cy="${c}" r="${(R + r) / 2}" fill="none" stroke="${s.szin}" stroke-width="${R - r}"/>`; return; }
            const [x1, y1] = pt(szog, R), [x2, y2] = pt(szog + tart, R), [x3, y3] = pt(szog + tart, r), [x4, y4] = pt(szog, r);
            const nagy = tart > 180 ? 1 : 0;
            utak += `<path d="M${x1} ${y1}A${R} ${R} 0 ${nagy} 1 ${x2} ${y2}L${x3} ${y3}A${r} ${r} 0 ${nagy} 0 ${x4} ${y4}Z" fill="${s.szin}" stroke="var(--kp-panel-eros)" stroke-width="2"/>`;
            szog += tart;
        });
        return `<div class="kp-fanko"><svg viewBox="0 0 140 140">${utak}<text x="70" y="68" text-anchor="middle" font-size="22" font-weight="700" fill="var(--kp-szoveg)">${szam(ossz)}</text><text x="70" y="86" text-anchor="middle" font-size="10" fill="var(--kp-szoveg-3)">rendszer</text></svg>
            <div class="kp-jelmagyarazat">${szeletek.map((s) => `<div><i style="background:${s.szin}"></i>${e(s.nev)}<span>${szam(s.ertek)}</span></div>`).join('')}</div></div>`;
    }

    const PALETTA = ['#7c6cff', '#22d3ee', '#34d399', '#fbbf24', '#fb7185', '#a78bfa', '#60a5fa', '#f472b6'];

    /* ------------------------------------------------------------------ Űrlapelemek */

    const kapcsolo = (nev, be, cimke, extra = '') => `<label class="kp-kapcsolo"><input type="checkbox" name="${e(nev)}" ${be ? 'checked' : ''} aria-label="${e(cimke)}" ${extra}><span></span></label>`;
    const kapcsolosor = (nev, be, cim, al = '', extra = '') => `<div class="kp-kapcsolosor"><div class="kp-kapcsolosor__szoveg">${e(cim)}${al ? `<small>${e(al)}</small>` : ''}</div>${kapcsolo(nev, be, cim, extra)}</div>`;
    const mezo = (cim, input, sugo = '', cls = '') => `<div class="kp-mezo ${cls}"><span class="kp-mezo__cim">${e(cim)}</span>${input}${sugo ? `<span class="kp-mezo__sugo">${e(sugo)}</span>` : ''}</div>`;
    const input = (nev, ertek = '', extra = '') => `<input class="kp-input" name="${e(nev)}" value="${e(ertek)}" autocomplete="off" ${extra}>`;
    const select = (nev, opciok, ertek) => `<select class="kp-select" name="${e(nev)}">${Object.entries(opciok).map(([k, v]) => `<option value="${e(k)}" ${String(k) === String(ertek) ? 'selected' : ''}>${e(v)}</option>`).join('')}</select>`;
    const datumMezo = (nev, ertek = '', ures = 'Nincs megadva') => `<div class="kp-datum" data-datum><input type="hidden" name="${e(nev)}" value="${e(ertek)}"><input class="kp-input" readonly data-datum-kijelzo placeholder="${e(ures)}" value="${ertek ? e(isoDatum(ertek)) : ''}"></div>`;
    const szegmens = (nev, opciok, ertek) => `<div class="kp-szegmens" data-szegmens="${e(nev)}">${Object.entries(opciok).map(([k, v]) => `<button type="button" data-ertek="${e(k)}" class="${String(k) === String(ertek) ? 'is-aktiv' : ''}">${e(v)}</button>`).join('')}</div>`;

    // Dátumválasztó (a natív helyett).
    const HONAP = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
    document.addEventListener('click', (ev) => {
        const kij = ev.target.closest('[data-datum-kijelzo]');
        const nyitott = $('.kp-datum__naptar');
        if (nyitott && !ev.target.closest('.kp-datum__naptar') && ev.target !== kij) nyitott.remove();
        if (!kij) return;
        if (kij.parentElement.querySelector('.kp-datum__naptar')) return;
        const doboz = kij.parentElement, rejtett = $('input[type=hidden]', doboz);
        let alap = rejtett.value ? new Date(rejtett.value + 'T12:00:00') : new Date();
        const nap = document.createElement('div');
        nap.className = 'kp-datum__naptar';
        const rajzol = () => {
            const ev0 = alap.getFullYear(), ho = alap.getMonth();
            const elso = (new Date(ev0, ho, 1).getDay() + 6) % 7;
            const napok = new Date(ev0, ho + 1, 0).getDate();
            const ma = new Date(), mai = `${ma.getFullYear()}-${ketjegy(ma.getMonth() + 1)}-${ketjegy(ma.getDate())}`;
            let gombok = '';
            for (let i = 0; i < elso; i++) gombok += '<i></i>';
            for (let d = 1; d <= napok; d++) {
                const iso = `${ev0}-${ketjegy(ho + 1)}-${ketjegy(d)}`;
                gombok += `<button type="button" data-iso="${iso}" class="${iso === mai ? 'is-ma' : ''} ${iso === rejtett.value ? 'is-valasztott' : ''}">${d}</button>`;
            }
            nap.innerHTML = `<div class="kp-datum__fej"><button type="button" data-lep="-1" aria-label="Előző hónap">${I('vissza')}</button><span>${ev0}. ${HONAP[ho]}</span><button type="button" data-lep="1" aria-label="Következő hónap">${I('nyil')}</button></div>
                <div class="kp-datum__napok">${['H', 'K', 'Sz', 'Cs', 'P', 'Sz', 'V'].map((x) => `<span>${x}</span>`).join('')}${gombok}</div>
                <div class="kp-datum__gyors"><button type="button" class="kp-gomb kp-gomb--kicsi" data-plusz="1">+1 hónap</button><button type="button" class="kp-gomb kp-gomb--kicsi" data-plusz="12">+1 év</button><button type="button" class="kp-gomb kp-gomb--kicsi kp-gomb--szoveg" data-torol>Törlés</button></div>`;
            $$('[data-lep] svg', nap).forEach((s) => { s.style.width = '14px'; s.style.height = '14px'; });
        };
        const beallit = (iso) => { rejtett.value = iso; kij.value = iso ? isoDatum(iso) : ''; rejtett.dispatchEvent(new Event('change', { bubbles: true })); nap.remove(); };
        nap.addEventListener('click', (x) => {
            x.stopPropagation();
            const l = x.target.closest('[data-lep]'), d = x.target.closest('[data-iso]'), p = x.target.closest('[data-plusz]');
            if (l) { alap = new Date(alap.getFullYear(), alap.getMonth() + Number(l.dataset.lep), 1); rajzol(); }
            if (d) beallit(d.dataset.iso);
            if (p) { const b = rejtett.value ? new Date(rejtett.value + 'T12:00:00') : new Date(); b.setMonth(b.getMonth() + Number(p.dataset.plusz)); beallit(`${b.getFullYear()}-${ketjegy(b.getMonth() + 1)}-${ketjegy(b.getDate())}`); }
            if (x.target.closest('[data-torol]')) beallit('');
        });
        rajzol();
        doboz.appendChild(nap);
    });

    document.addEventListener('click', (ev) => {
        const g = ev.target.closest('[data-szegmens] button');
        if (!g) return;
        const sz = g.parentElement;
        $$('button', sz).forEach((b) => b.classList.toggle('is-aktiv', b === g));
        sz.dispatchEvent(new CustomEvent('valtozas', { detail: g.dataset.ertek, bubbles: true }));
    });

    const urlapAdat = (el) => {
        const o = {};
        $$('input[name], select[name], textarea[name]', el).forEach((m) => {
            if (m.type === 'checkbox') { o[m.name] = m.checked ? 1 : 0; return; }
            o[m.name] = m.value;
        });
        $$('[data-szegmens]', el).forEach((s) => { const a = $('.is-aktiv', s); if (a) o[s.dataset.szegmens] = a.dataset.ertek; });
        return o;
    };

    /* ------------------------------------------------------------------ Popup */

    function popup({ cim, test, lab = '', meret = '', nyit = null }) {
        const hatter = document.createElement('div');
        hatter.className = 'kp-popup-hatter';
        hatter.innerHTML = `<div class="kp-popup ${meret ? 'kp-popup--' + meret : ''}" role="dialog" aria-modal="true" aria-label="${e(cim)}">
            <div class="kp-popup__fej"><h2 class="kp-popup__cim">${e(cim)}</h2><span class="kp-ter" style="flex:1"></span><button type="button" class="kp-ikon-gomb" data-bezar aria-label="Bezárás">${I('x')}</button></div>
            <div class="kp-popup__test">${test}</div>${lab ? `<div class="kp-popup__lab">${lab}</div>` : ''}</div>`;
        const bezar = () => { hatter.remove(); document.removeEventListener('keydown', esc); };
        const esc = (ev) => { if (ev.key === 'Escape' && !$('.kp-datum__naptar')) bezar(); };
        hatter.addEventListener('mousedown', (ev) => { if (ev.target === hatter) bezar(); });
        hatter.addEventListener('click', (ev) => { if (ev.target.closest('[data-bezar]')) bezar(); });
        document.addEventListener('keydown', esc);
        document.body.appendChild(hatter);
        const p = $('.kp-popup', hatter);
        if (nyit) nyit(p, bezar);
        const elso = $('input:not([type=hidden]):not([readonly]), textarea', p);
        if (elso) setTimeout(() => elso.focus(), 40);
        return { el: p, bezar };
    }

    const hibaKi = (p, uzenet) => { let h = $('.kp-popup__hiba', p); if (!h) { h = document.createElement('span'); h.className = 'kp-popup__hiba'; $('.kp-popup__lab', p).prepend(h); } h.textContent = uzenet; };

    /* ------------------------------------------------------------------ Váz */

    const MENU = [
        ['', 'attekintes', 'Áttekintés'],
        ['rendszerek', 'rendszer', 'Rendszerek'],
        ['vevok', 'vevo', 'Vevők'],
        ['licencek', 'licenc', 'Licencek'],
        ['kiadasok', 'kiadas', 'Kiadások'],
        ['tamogatas', 'tamogatas', 'Támogatás'],
        ['naplo', 'naplo', 'Napló'],
        ['beallitasok', 'beallitas', 'Beállítások'],
    ];

    const S = { idozito: null, oldal: '', jelek: {} };

    function vaz() {
        const app = $('#kp-app');
        app.innerHTML = `
            <aside class="kp-oldalsav">
                <a class="kp-logo" href="#/"><span class="kp-logo__jel"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17 19 7"/></svg></span><span class="kp-logo__nev">SDH Központ<small>Vezérlőközpont</small></span></a>
                <nav class="kp-menu" aria-label="Fő menü">
                    ${MENU.slice(0, 2).map(menuPont).join('')}
                    <div class="kp-menu__cim">Ügyfélkezelés</div>
                    ${MENU.slice(2, 4).map(menuPont).join('')}
                    <div class="kp-menu__cim">Szolgáltatás</div>
                    ${MENU.slice(4, 7).map(menuPont).join('')}
                    <div class="kp-menu__cim">Rendszer</div>
                    ${MENU.slice(7).map(menuPont).join('')}
                </nav>
                <div class="kp-oldalsav__alja">
                    <a class="kp-wp-gomb" href="${e(B.wp)}">${I('wp')}<span>WordPress admin</span></a>
                    <a class="kp-wp-gomb" href="${e(B.oldal)}" target="_blank" rel="noopener">${I('kulso')}<span>Webhely megnyitása</span></a>
                    <div class="kp-halvany kp-kicsi" style="padding:4px 12px">v${e(B.verzio)}</div>
                </div>
            </aside>
            <div class="kp-fo">
                <header class="kp-fejlec">
                    <button type="button" class="kp-ikon-gomb kp-mobil-menu" data-menu aria-label="Menü">${I('menu')}</button>
                    <h1 class="kp-fejlec__cim" data-cim>Áttekintés</h1>
                    <span class="kp-fejlec__ter"></span>
                    <button type="button" class="kp-kereso" data-paletta>${I('kereses')}<span>Keresés, parancs…</span><kbd>Ctrl K</kbd></button>
                    <span class="kp-elo" title="Az adatok maguktól frissülnek"><span class="kp-elo__pont"></span><span data-frissult>Élő</span></span>
                    <button type="button" class="kp-ikon-gomb" data-tema-gomb aria-label="Világos / sötét">${I(document.documentElement.dataset.tema === 'vilagos' ? 'hold' : 'nap')}</button>
                    <a class="kp-ikon-gomb" href="${e(B.kilep)}" aria-label="Kijelentkezés" title="Kijelentkezés">${I('kilep')}</a>
                    <span class="kp-profil" title="${e(B.teljes)}"><img src="${e(B.avatar)}" alt="" onerror="this.replaceWith(Object.assign(document.createElement('b'),{textContent:${e(JSON.stringify((B.teljes || B.nev || '?').trim().split(/\s+/).map((x) => x[0]).slice(0, 2).join('').toUpperCase()))}}))"></span>
                </header>
                <main class="kp-tartalom" data-tartalom></main>
            </div>`;
        $('[data-menu]').addEventListener('click', () => app.classList.toggle('is-menu'));
        $('[data-paletta]').addEventListener('click', paletta);
        $('[data-tema-gomb]').addEventListener('click', temaValt);
    }

    const menuPont = ([ut, ikon, nev]) => `<a href="#/${ut}" data-menupont="${ut}">${I(ikon)}<span>${e(nev)}</span><span class="kp-menu__jel" data-jel="${ut}" hidden></span></a>`;

    function temaValt() {
        const html = document.documentElement;
        const most = html.dataset.tema || (matchMedia('(prefers-color-scheme: light)').matches ? 'vilagos' : 'sotet');
        const uj = most === 'vilagos' ? 'sotet' : 'vilagos';
        html.dataset.tema = uj;
        $('[data-tema-gomb]').innerHTML = I(uj === 'vilagos' ? 'hold' : 'nap');
        api('tema', { method: 'POST', body: { tema: uj } }).catch(() => {});
        route();
    }

    function cim(szoveg, morzsa = '') {
        $('[data-cim]').innerHTML = (morzsa ? `<span class="kp-fejlec__morzsa">${morzsa} / </span>` : '') + e(szoveg);
        document.title = `${szoveg} · SDH Központ`;
    }

    function menuJelek(adat) {
        const j = $('[data-jel="rendszerek"]');
        if (!j || !adat) return;
        const rossz = (adat.rendszerek.hiba || 0) + (adat.rendszerek.offline || 0) + (adat.rendszerek.figyel || 0);
        j.hidden = !rossz;
        j.textContent = rossz;
        const t = $('[data-jel="tamogatas"]');
        t.hidden = !adat.tamogatas;
        t.textContent = adat.tamogatas;
        t.className = 'kp-menu__jel kp-menu__jel--ok';
    }

    function frissult() {
        const f = $('[data-frissult]');
        if (f) f.textContent = 'Élő · ' + oraPerc(mostTs());
    }

    const tartalom = () => $('[data-tartalom]');
    const betolt = () => { tartalom().innerHTML = `<div class="kp-racs kp-racs--kpi">${'<div class="kp-kartya kp-csontvaz" style="height:112px"></div>'.repeat(6)}</div><div class="kp-kartya kp-csontvaz" style="height:320px"></div>`; };
    const hibaOldal = (x) => { tartalom().innerHTML = `<div class="kp-kartya"><div class="kp-ures">${I('figyel')}${e(x.message || x)}</div></div>`; };

    function ismetel(fn, mp) {
        clearInterval(S.idozito);
        S.idozito = setInterval(() => { if (!document.hidden) fn(true); }, mp * 1000);
    }

    /* ------------------------------------------------------------------ Útválasztó */

    function route() {
        clearInterval(S.idozito);
        $('.kp-datum__naptar')?.remove();
        $('#kp-app').classList.remove('is-menu');
        const h = (location.hash || '#/').slice(2).split('/');
        const [o, id] = h;
        S.oldal = o || '';
        $$('[data-menupont]').forEach((a) => a.classList.toggle('is-aktiv', a.dataset.menupont === (o === 'rendszer' ? 'rendszerek' : o === 'vevo' ? 'vevok' : S.oldal)));
        window.scrollTo(0, 0);
        const oldalak = { '': attekintes, rendszerek, rendszer, vevok, vevo, licencek, kiadasok, tamogatas, naplo, beallitasok };
        (oldalak[o || ''] || attekintes)(false, id);
    }

    window.addEventListener('hashchange', route);

    document.addEventListener('click', (ev) => {
        const n = ev.target.closest('[data-nav]');
        if (!n || ev.target.closest('a[href]:not([data-nav]), button:not([data-nav]), input, label')) return;
        location.hash = n.dataset.nav;
    });

    /* ------------------------------------------------------------------ Közös darabok */

    const kpi = (ikon, cimke, ertek, al = '', extra = '', cls = '') => `<div class="kp-kartya kp-kpi ${cls}"><div class="kp-kpi__cimke">${I(ikon)}${e(cimke)}</div><div class="kp-kpi__ertek">${ertek}</div><div class="kp-kpi__al">${al}</div>${extra}</div>`;
    const pont = (st, nagy = false) => `<span class="kp-pont kp-pont--${e(st)} ${nagy ? 'kp-pont--nagy' : ''}" title="${e(STATUSZ[st] || st)}"></span>`;
    const statuszCimke = (st) => `<span class="kp-cimke kp-cimke--${STATUSZ_CIMKE[st] || 'szurke'}">${pont(st)}${e(STATUSZ[st] || st)}</span>`;
    const ures = (szoveg, ikon = 'info') => `<div class="kp-ures">${I(ikon)}${e(szoveg)}</div>`;

    function rendszerKartya(p, legujabb) {
        const pr = (p.problemak || [])[0];
        const regi = legujabb && p.verzio && p.verzio !== legujabb;
        return `<div class="kp-rk kp-rk--${e(p.statusz)}" data-nav="#/rendszer/${p.id}" tabindex="0">
            <div class="kp-rk__fej">${pont(p.statusz, true)}<div style="min-width:0;flex:1"><div class="kp-rk__nev">${e(p.nev)}</div><div class="kp-rk__vevo">${e(p.vevo || 'Nincs vevő')}</div></div><span class="kp-cimke ${regi ? 'kp-cimke--figyel' : ''} kp-cimke--mono">${e(p.verzio || '?')}</span></div>
            <div class="kp-rk__spark">${spark(p.spark, { szin: p.statusz === 'hiba' ? 'var(--kp-hiba)' : p.statusz === 'figyel' ? 'var(--kp-figyel)' : 'var(--kp-kiemelo)' })}</div>
            <div class="kp-rk__adatok"><div class="kp-rk__adat"><b>${p.mod === 'napi' ? '–' : ms(p.valasz_ms)}</b>válaszidő</div><div class="kp-rk__adat"><b>${p.mod === 'napi' ? '–' : szam(p.aktiv)}</b>aktív most</div><div class="kp-rk__adat"><b>${p.mod === 'napi' ? '–' : szam(p.munkalap_ma)}</b>munkalap ma</div></div>
            ${pr ? `<div class="kp-rk__problema">${e(pr.szoveg)}${p.problemak.length > 1 ? ` (+${p.problemak.length - 1})` : ''}</div>` : `<div class="kp-rk__lab"><span>${e(STATUSZ[p.statusz])}</span><span>${relativ(p.utolso)}</span></div>`}
        </div>`;
    }

    function idovonal(lista, { vevo = true } = {}) {
        if (!lista.length) return ures('Még nincs esemény.');
        const ikon = { riasztas: 'figyel', licenc: 'licenc', peldany: 'rendszer', muvelet: 'villam', tamogatas: 'tamogatas', kiadas: 'kiadas', vevo: 'vevo', jegyzet: 'jegyzet', rendszer: 'info' };
        return `<div class="kp-idovonal">${lista.map((x) => `<div class="kp-ie kp-ie--${e(x.szint)}"><span class="kp-ie__jel">${I(ikon[x.tipus] || 'info')}</span>
            <div class="kp-ie__szoveg">${e(x.szoveg)}<small>${[vevo && x.vevo ? `<a href="#/vevo/${x.vevo_id}">${e(x.vevo)}</a>` : '', x.peldany ? `<a href="#/rendszer/${x.peldany_id}">${e(x.peldany)}</a>` : '', x.ki ? e(x.ki) : ''].filter(Boolean).join(' · ')}</small></div>
            <span class="kp-ie__ido" title="${e(datumIdo(x.ido))}">${relativ(x.ido)}</span></div>`).join('')}</div>`;
    }

    /* ================================================================== Áttekintés */

    async function attekintes(csendes) {
        cim('Áttekintés');
        if (!csendes) betolt();
        let a;
        try { a = await api('attekintes'); } catch (x) { if (!csendes) hibaOldal(x); return; }
        if (S.oldal !== '') return;
        menuJelek(a);
        frissult();
        const ora = new Date().getHours();
        const koszon = ora < 9 ? 'Jó reggelt' : ora < 18 ? 'Szép napot' : 'Jó estét';
        const r = a.rendszerek;
        const mukodik = r.online + r.figyel + r.hiba + r.napi;
        const rossz = r.hiba + r.offline;
        const verziok = Object.entries(a.verziok);

        tartalom().innerHTML = `
            <div class="kp-udv"><div><h2>${koszon}, <span>${e(B.nev)}</span></h2><p>${rossz ? `${rossz} rendszer azonnali figyelmet kér.` : r.figyel ? `Minden rendszer fut, ${r.figyel} jelez valamit.` : r.osszes ? 'Minden rendszer rendben működik.' : 'Még egyetlen rendszer sem csatlakozott – adj ki egy licencet.'}</p></div>
                <div class="kp-sor"><button class="kp-gomb" data-uj-vevo>${I('plusz')}Új vevő</button><button class="kp-gomb kp-gomb--fo" data-uj-licenc>${I('licenc')}Új licenc</button></div></div>
            <div class="kp-racs kp-racs--kpi">
                ${kpi('rendszer', 'Élő rendszerek', `${szam(mukodik)}<small>/ ${szam(r.osszes)}</small>`, r.offline ? `<span style="color:var(--kp-hiba)">${r.offline} nem jelentkezik</span>` : 'mind jelentkezik', '', 'kp-kpi--kiemelt')}
                ${kpi('figyel', 'Figyelmet kér', `<span style="color:${rossz ? 'var(--kp-hiba)' : r.figyel ? 'var(--kp-figyel)' : 'var(--kp-ok)'}">${szam(rossz + r.figyel)}</span>`, `${r.hiba} hiba · ${r.figyel} figyelmeztetés`)}
                ${kpi('aktiv', 'Átlagos válaszidő', a.atlag_ms ? ms(a.atlag_ms) : '–', 'a működő rendszereken', `<div class="kp-kpi__spark">${spark(a.flotta.map((f) => f.valasz))}</div>`)}
                ${kpi('felhasznalo', 'Aktív felhasználó most', szam(a.aktiv_most), `${szam(a.munkalap_ma)} új munkalap ma`)}
                ${kpi('licenc', 'Aktív licenc', szam(a.licencek.aktiv), `${a.licencek.proba} próba · ${a.licencek.lejart} lejárt · ${szam(a.vevok)} vevő`)}
                ${kpi('penz', 'Havi bevétel', ft(a.mrr), `évente ${ft(a.arr)} · 30 napon belül esedékes: ${ft(a.esedekes30)}`)}
            </div>
            <div class="kp-racs kp-racs--2">
                <div class="kp-oszlop">
                    <section class="kp-kartya">
                        <div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Rendszerek most</h3><span class="kp-kartya__al">${szam(r.osszes)} telepítés · élőben frissül</span><span class="kp-ter"></span><a class="kp-gomb kp-gomb--kicsi" href="#/rendszerek">Mind ${I('nyil')}</a></div>
                        ${a.lista.length ? `<div class="kp-rendszerek">${a.lista.slice(0, 12).map((p) => rendszerKartya(p, a.legujabb)).join('')}</div>` : ures('Még nincs csatlakozott rendszer. A vevőnél: Beállítások › Licenc – a Központ címe és a licenckulcs.', 'rendszer')}
                    </section>
                    <section class="kp-kartya">
                        <div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Flotta – 24 óra</h3><span class="kp-kartya__al">átlagos válaszidő és kérések óránként</span></div>
                        <div data-flotta></div>
                    </section>
                </div>
                <div class="kp-oszlop">
                    <section class="kp-kartya">
                        <div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Riasztások</h3><span class="kp-ter"></span>${a.riasztasok.length ? `<span class="kp-cimke kp-cimke--hiba">${a.riasztasok.length}</span>` : '<span class="kp-cimke kp-cimke--ok">Minden rendben</span>'}</div>
                        ${a.riasztasok.length ? `<div class="kp-lista">${a.riasztasok.slice(0, 7).map((x) => `<div class="kp-lista__elem is-katt" data-nav="#/rendszer/${x.id}">${pont(x.statusz)}<div class="kp-lista__test"><div class="kp-lista__fo">${e(x.nev)}</div><div class="kp-lista__al">${e((x.problemak[0] || {}).szoveg || STATUSZ[x.statusz])}${x.statusz === 'offline' ? ' · utolsó jel ' + relativ(x.utolso) : ''}</div></div></div>`).join('')}</div>` : ures('Nincs nyitott riasztás.', 'pipa')}
                    </section>
                    <section class="kp-kartya">
                        <div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Verziók</h3><span class="kp-ter"></span><span class="kp-kartya__al">legújabb: <b>${e(a.legujabb || '–')}</b></span></div>
                        ${verziok.length ? fanko(verziok.slice(0, 6).map(([v, db], i) => ({ nev: v + (v === a.legujabb ? ' (legújabb)' : ''), ertek: db, szin: v === a.legujabb ? '#34d399' : PALETTA[(i + 1) % PALETTA.length] }))) : ures('Még nincs adat.')}
                    </section>
                    <section class="kp-kartya">
                        <div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Lejáró licencek</h3><span class="kp-kartya__al">60 napon belül</span></div>
                        ${a.lejarok.length ? `<div class="kp-lista">${a.lejarok.map((l) => `<div class="kp-lista__elem is-katt" data-nav="#/vevo/${l.vevo_id}"><div class="kp-lista__test"><div class="kp-lista__fo">${e(l.vevo)}</div><div class="kp-lista__al kp-mono">${e(l.kulcs)}</div></div><span class="kp-cimke kp-cimke--${l.nap <= 14 ? 'hiba' : 'figyel'}">${l.nap <= 0 ? 'ma' : l.nap + ' nap'}</span></div>`).join('')}</div>` : ures('Nincs lejáró licenc.', 'pipa')}
                    </section>
                    <section class="kp-kartya">
                        <div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Legutóbb</h3><span class="kp-ter"></span><a class="kp-gomb kp-gomb--kicsi kp-gomb--szoveg" href="#/naplo">Napló ${I('nyil')}</a></div>
                        ${idovonal(a.esemenyek)}
                    </section>
                </div>
            </div>`;
        terulet($('[data-flotta]'), { t: a.flotta.map((f) => f.t), sorok: [{ nev: 'Válaszidő', ertekek: a.flotta.map((f) => f.valasz), szin: '#7c6cff' }], format: (x) => ms(x), magas: 200 });
        $('[data-uj-vevo]').addEventListener('click', () => vevoPopup(null, () => attekintes(true)));
        $('[data-uj-licenc]').addEventListener('click', () => licencPopup(null, () => attekintes(true)));
        ismetel(attekintes, 15);
    }

    /* ================================================================== Rendszerek */

    async function rendszerek(csendes) {
        cim('Rendszerek');
        if (!csendes) betolt();
        let a;
        try { a = await api('peldanyok'); } catch (x) { if (!csendes) hibaOldal(x); return; }
        if (S.oldal !== 'rendszerek') return;
        frissult();
        const szuro = S.rszuro || 'mind', q = (S.rq || '').toLowerCase(), nezet = S.rnezet || 'kartya';
        const db = (st) => a.lista.filter((p) => (st === 'gond' ? ['figyel', 'hiba', 'offline'].includes(p.statusz) : p.statusz === st)).length;
        const lista = a.lista.filter((p) => (szuro === 'mind' || (szuro === 'gond' ? ['figyel', 'hiba', 'offline'].includes(p.statusz) : p.statusz === szuro)) && (!q || (p.nev + ' ' + p.vevo + ' ' + p.verzio + ' ' + p.kulcs).toLowerCase().includes(q)));

        const fokusz = document.activeElement && document.activeElement.matches('[data-rq]');
        tartalom().innerHTML = `
            <div class="kp-sor kp-sor--kozte kp-sor--tor">
                <div class="kp-szuro">${szegmens('rszuro', { mind: `Mind (${a.lista.length})`, online: `Működik (${db('online')})`, gond: `Gond (${db('gond')})`, offline: `Áll (${db('offline')})`, napi: `Régi (${db('napi')})` }, szuro)}
                <input class="kp-input" data-rq placeholder="Keresés: vevő, verzió, kulcs…" value="${e(S.rq || '')}"></div>
                ${szegmens('rnezet', { kartya: 'Kártyák', tabla: 'Táblázat' }, nezet)}
            </div>
            ${!lista.length ? `<div class="kp-kartya">${ures(a.lista.length ? 'Nincs a szűrésnek megfelelő rendszer.' : 'Még nincs csatlakozott rendszer.', 'rendszer')}</div>` : nezet === 'kartya'
                ? `<div class="kp-rendszerek">${lista.map((p) => rendszerKartya(p, a.legujabb)).join('')}</div>`
                : `<div class="kp-kartya kp-kartya--sima"><div class="kp-tabla-doboz"><table class="kp-tabla"><thead><tr><th>Rendszer</th><th>Állapot</th><th>Verzió</th><th class="kp-jobb">Válaszidő</th><th>24 óra</th><th class="kp-jobb">Adatbázis</th><th class="kp-jobb">Szabad lemez</th><th>Mentés</th><th class="kp-jobb">Felh.</th><th>Utolsó jel</th></tr></thead><tbody>
                    ${lista.map((p) => `<tr class="is-katt" data-nav="#/rendszer/${p.id}"><td><div class="kp-vastag">${e(p.nev)}</div><div class="kp-halvany kp-kicsi">${e(p.vevo)}</div></td><td>${statuszCimke(p.statusz)}</td>
                    <td><span class="kp-cimke kp-cimke--mono ${a.legujabb && p.verzio !== a.legujabb ? 'kp-cimke--figyel' : ''}">${e(p.verzio || '?')}</span></td><td class="kp-jobb">${p.mod === 'napi' ? '–' : ms(p.valasz_ms)}</td><td style="width:120px">${spark(p.spark, { hh: 26 })}</td>
                    <td class="kp-jobb">${p.db_mb ? mb(p.db_mb) : '–'}</td><td class="kp-jobb">${mb(p.lemez_mb)}</td><td>${p.mentes ? relativ(p.mentes) : '–'}</td><td class="kp-jobb">${p.mod === 'napi' ? '–' : szam(p.felhasznalo)}</td><td class="kp-halvany">${relativ(p.utolso)}</td></tr>`).join('')}
                </tbody></table></div></div>`}`;
        $('[data-szegmens="rszuro"]').addEventListener('valtozas', (ev) => { S.rszuro = ev.detail; rendszerek(true); });
        $('[data-szegmens="rnezet"]').addEventListener('valtozas', (ev) => { S.rnezet = ev.detail; rendszerek(true); });
        const qi = $('[data-rq]');
        qi.addEventListener('input', () => { S.rq = qi.value; clearTimeout(S.rqi); S.rqi = setTimeout(() => rendszerek(true), 200); });
        if (fokusz) { qi.focus(); qi.setSelectionRange(qi.value.length, qi.value.length); }
        ismetel(rendszerek, 15);
    }

    /* ================================================================== Rendszer adatlap */

    const CIMKEK = {
        rendszer: ['Szerver', 'szerver', { os: 'Operációs rendszer', szerver: 'Webszerver', sapi: 'PHP-futtató', php: 'PHP', memoria_mb: 'PHP-memória (MB)', max_exec: 'Max. futásidő (s)', feltoltes_mb: 'Feltöltési korlát (MB)', opcache: 'OPcache', https: 'HTTPS', idozona: 'Időzóna', nyelv: 'Nyelv', wp: 'Keretrendszer', db_motor: 'Adatbázis-motor', db_verzio: 'Adatbázis-verzió', cron_tiltva: 'Beépített ütemező tiltva', debug_kijelzes: 'Hibák kiírása', multisite: 'Többoldalas', cpu: 'Processzormagok', terheles: 'Terhelés (1 perc)' }],
        muhely: ['SDH Műhely', 'modul', { verzio: 'Verzió', db_verzio: 'Adatbázis-séma', tesztuzem: 'Tesztüzem', git: 'Fejlesztői (git) példány', ketlepcsos: 'Kétlépcsős belépés', tavoli: 'Távoli karbantartás engedélyezve', licenc_allapot: 'Licenc a vevő szerint', kozos_fiok: 'Közös fiók', zart_mod: 'Zárt mód' }],
        adat: ['Adatok', 'db', { munkalap: 'Munkalap (összes)', munkalap_nyitott: 'Nyitott munkalap', munkalap_ma: 'Munkalap ma', munkalap_30: 'Munkalap 30 nap', ugyfel: 'Ügyfél', eszkoz: 'Eszköz', termek: 'Termék', felhasznalo: 'Felhasználó', db_mb: 'Adatbázis (MB)', feltoltes_mb: 'Feltöltések (MB)', tablak: 'Táblák' }],
        mentes: ['Mentés', 'mentes', { utolso: 'Utolsó mentés', db: 'Mentések száma', meret_mb: 'Utolsó mérete (MB)', kulso: 'Külső másolat', kulso_hiba: 'Külső mentés hibája', megtartas: 'Megtartás (nap)' }],
        halozat: ['Hálózat', 'halozat', { online: 'Internet', ellenorizve: 'Ellenőrizve', sms: 'SMS-telefon', szamlazas: 'Számlázás beállítva', levelezes: 'Levelezés beállítva' }],
        cron: ['Ütemező', 'ora', { kesik: 'Késő feladat', kesik_perc: 'Legrégebbi késés (perc)', osszes: 'Ütemezett feladat', kovetkezo: 'Következő futás' }],
        lemez: ['Tárhely', 'lemez', { szabad_mb: 'Szabad (MB)', ossz_mb: 'Összes (MB)' }],
        sebesseg: ['Sebesség (az utolsó jel óta)', 'aktiv', { atlag_ms: 'Átlag (ms)', p95_ms: '95% (ms)', max_ms: 'Leglassabb (ms)', keres: 'Kérés', lassu: 'Lassú kérés (>1 s)', db_ms: 'Adatbázis-próba (ms)', mem_mb: 'Memóriacsúcs (MB)' }],
        aktivitas: ['Aktivitás', 'felhasznalo', { most: 'Aktív most (15 perc)', ma: 'Aktív ma', bejelentkezes_24: 'Bejelentkezés (24 óra)' }],
    };
    const IDOMEZOK = ['utolso', 'ellenorizve', 'kovetkezo'];
    const ertek = (k, v) => {
        if (v === true) return '<span class="kp-cimke kp-cimke--ok">Igen</span>';
        if (v === false) return '<span class="kp-cimke kp-cimke--szurke">Nem</span>';
        if (v === null || v === '' || v === undefined) return '<span class="kp-halvany">–</span>';
        if (IDOMEZOK.includes(k) && Number(v) > 1e9) return `${e(datumIdo(Number(v)))} <span class="kp-halvany">(${relativ(Number(v))})</span>`;
        if (typeof v === 'number') return szam(v);
        if (Array.isArray(v)) return e(v.join(', '));
        if (typeof v === 'object') return `<code class="kp-mono">${e(JSON.stringify(v))}</code>`;
        return e(v);
    };

    async function rendszer(csendes, id) {
        if (!csendes) { cim('Rendszer', '<a href="#/rendszerek">Rendszerek</a>'); betolt(); S.rtav = S.rtav || '24h'; S.rful = S.rful || 'attekintes'; }
        let a;
        try { a = await api('peldany/' + id); } catch (x) { if (!csendes) hibaOldal(x); return; }
        if (S.oldal !== 'rendszer') return;
        frissult();
        if (csendes && (document.activeElement?.closest('[data-jegyzet], .kp-input, .kp-textarea') || $('.kp-popup-hatter'))) { ismetel((c) => rendszer(c, id), 10); return; }
        S.radat = a;
        const p = a.p, s = a.allapot || {}, l = a.licenc, napi = p.mod === 'napi';
        cim(p.nev, '<a href="#/rendszerek">Rendszerek</a>');
        const seb = s.sebesseg || {}, adat = s.adat || {}, mentes = s.mentes || {};
        const mutato = (cimke, v, allapot = '') => `<div class="kp-mutato ${allapot ? 'kp-mutato--' + allapot : ''}"><div class="kp-mutato__cimke">${e(cimke)}</div><div class="kp-mutato__ertek">${v}</div></div>`;
        const mentesKor = mentes.utolso ? mostTs() - mentes.utolso : null;
        const regi = a.legujabb && p.verzio && p.verzio !== a.legujabb;

        tartalom().innerHTML = `
            <section class="kp-kartya">
                <div class="kp-hos">
                    <div class="kp-hos__orb">${pont(p.statusz, true)}</div>
                    <div style="min-width:0"><h2 class="kp-hos__cim">${e(p.nev)}</h2>
                        <div class="kp-hos__al">${statuszCimke(p.statusz)}<span class="kp-cimke kp-cimke--mono ${regi ? 'kp-cimke--figyel' : ''}">${e(p.verzio || '?')}${regi ? ' → ' + e(a.legujabb) : ''}</span>
                        ${a.vevo ? `<a class="kp-cimke" href="#/vevo/${a.vevo.id}">${I('vevo')}${e(a.vevo.nev)}</a>` : ''}
                        ${l ? `<span class="kp-licenckulcs" data-masol="${e(l.kulcs)}" title="Másolás">${e(l.kulcs)}</span>` : ''}
                        <span class="kp-halvany">utolsó jel: ${relativ(p.utolso)} · először: ${datum(p.elso)}</span></div></div>
                    <div class="kp-hos__gombok">
                        ${napi ? '' : `<div class="kp-kapcsolosor" title="30 másodpercenként jelentkezik 15 percig">${'<div class="kp-kapcsolosor__szoveg">Élő követés<small>' + (p.elo ? 'bekapcsolva' : '30 mp-es frissítés') + '</small></div>'}${kapcsolo('elo', p.elo, 'Élő követés', 'data-elo')}</div>`}
                        <button class="kp-gomb" data-cimke-szerk>${I('szerkeszt')}Elnevezés</button>
                        <button class="kp-gomb kp-gomb--veszely" data-elfelejt data-ketlepcsos="Biztos? Helyet szabadít fel a licencen">Eltávolítás</button>
                    </div>
                </div>
            </section>
            ${napi ? `<div class="kp-kartya"><div class="kp-problema">${I('info')}Ez a rendszer régi verzió (${e(p.verzio)}): naponta egyszer jelentkezik, csak a verziót küldi. A 0.99.0-tól élőben látszik minden adat, és működik a távoli karbantartás – frissítsd.</div></div>` : `
            <div class="kp-mutato-sor">
                ${mutato('Válaszidő (átlag)', seb.atlag_ms !== undefined ? ms(seb.atlag_ms) : '–', seb.atlag_ms > 3000 ? 'hiba' : seb.atlag_ms > 1200 ? 'figyel' : 'ok')}
                ${mutato('Leglassabb 5%', seb.p95_ms !== undefined ? ms(seb.p95_ms) : '–')}
                ${mutato('Aktív most', szam((s.aktivitas || {}).most || 0) + `<small>/ ${szam(adat.felhasznalo || 0)}</small>`)}
                ${mutato('Munkalap ma', szam(adat.munkalap_ma || 0) + `<small>${szam(adat.munkalap_nyitott || 0)} nyitott</small>`)}
                ${mutato('Adatbázis', adat.db_mb ? mb(adat.db_mb) : '–')}
                ${mutato('Szabad tárhely', mb((s.lemez || {}).szabad_mb ?? -1), ((s.lemez || {}).szabad_mb ?? 99999) < 2048 ? 'figyel' : '')}
                ${mutato('Utolsó mentés', mentesKor === null ? 'nincs' : relativ(mentes.utolso), mentesKor === null || mentesKor > 72 * 3600 ? 'hiba' : mentesKor > 26 * 3600 ? 'figyel' : 'ok')}
            </div>`}
            ${(p.problemak || []).length ? `<div class="kp-problemak">${p.problemak.map((x) => `<div class="kp-problema kp-problema--${e(x.szint)}">${I('figyel')}${e(x.szoveg)}</div>`).join('')}</div>` : ''}
            <div class="kp-fulek" role="tablist">${[['attekintes', 'Teljesítmény'], ['adatok', 'Minden adat'], ['muveletek', 'Távoli karbantartás'], ['tamogatas', 'Támogatás'], ['modulok', 'Modulok és licenc'], ['hibak', 'Hibák és bővítmények'], ['naplo', 'Napló'], ['nyers', 'Nyers adat']].map(([k, n]) => `<button type="button" data-rful="${k}" class="${S.rful === k ? 'is-aktiv' : ''}">${e(n)}</button>`).join('')}</div>
            <div data-rtartalom></div>`;

        rendszerFul(a, id);
        $$('[data-rful]').forEach((b) => b.addEventListener('click', () => { S.rful = b.dataset.rful; $$('[data-rful]').forEach((x) => x.classList.toggle('is-aktiv', x === b)); rendszerFul(S.radat, id); }));
        $('[data-masol]')?.addEventListener('click', (ev) => masol(ev.currentTarget.dataset.masol));
        $('[data-elo]')?.addEventListener('change', async (ev) => { await api('peldany/' + id, { method: 'POST', body: { elo: ev.target.checked ? 15 : 0 } }); jelez(ev.target.checked ? 'Élő követés 15 percig: a rendszer 30 másodpercenként jelentkezik.' : 'Élő követés kikapcsolva.'); });
        $('[data-cimke-szerk]').addEventListener('click', () => {
            const pp = popup({ cim: 'Rendszer elnevezése', meret: 'kicsi', test: `${mezo('Név a Központban', input('cimke', p.cimke, 'placeholder="pl. Veszprém – pult gép"'), 'Csak itt látszik, a vevőnél nem.')}${mezo('Belső jegyzet', `<textarea class="kp-textarea" name="jegyzet">${e(a.jegyzet)}</textarea>`)}`, lab: '<button class="kp-gomb" data-bezar>Mégse</button><button class="kp-gomb kp-gomb--fo" data-ment>Mentés</button>' });
            $('[data-ment]', pp.el).addEventListener('click', async () => { await api('peldany/' + id, { method: 'POST', body: urlapAdat(pp.el) }); pp.bezar(); jelez('Elmentve.'); rendszer(false, id); });
        });
        $('[data-elfelejt]').addEventListener('click', async (ev) => {
            if (ev.currentTarget.classList.contains('is-elesitve')) return;
            await api('peldany/' + id + '/elfelejt', { method: 'POST', body: {} });
            jelez('A rendszer eltávolítva a nyilvántartásból.');
            location.hash = '#/rendszerek';
        });
        ismetel((c) => rendszer(c, id), p.elo ? 10 : 20);
    }

    function rendszerFul(a, id) {
        const hova = $('[data-rtartalom]');
        if (!hova) return;
        const s = a.allapot || {}, p = a.p;
        const ful = S.rful;

        if (ful === 'attekintes') {
            hova.innerHTML = `<div class="kp-racs kp-racs--fele">
                <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Válaszidő</h3><span class="kp-kartya__al">átlag és a leglassabb 5%</span><span class="kp-ter"></span>${szegmens('tav', { '1h': '1 óra', '24h': '24 óra', '7d': '7 nap', '30d': '30 nap', '90d': '90 nap' }, S.rtav)}</div><div data-d="valasz"></div></section>
                <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Aktivitás</h3><span class="kp-kartya__al">aktív felhasználók és kérések</span></div><div data-d="aktiv"></div></section>
                <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Adatbázis és tárhely</h3><span class="kp-kartya__al">adatbázis mérete, szabad lemez</span></div><div data-d="db"></div></section>
                <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Erőforrás és hibák</h3><span class="kp-kartya__al">memóriacsúcs, adatbázis-próba, végzetes hibák</span></div><div data-d="mem"></div></section>
            </div>`;
            const rajzol = async () => {
                const m = await api(`peldany/${id}/meres`, { query: { tav: S.rtav } });
                const napos = ['7d', '30d', '90d'].includes(S.rtav);
                terulet($('[data-d="valasz"]'), { t: m.t, sorok: [{ nev: 'Átlag', ertekek: m.valasz_ms, szin: '#7c6cff' }, { nev: 'Leglassabb 5%', ertekek: m.p95_ms, szin: '#22d3ee' }], format: ms, napos });
                terulet($('[data-d="aktiv"]'), { t: m.t, sorok: [{ nev: 'Aktív felhasználó', ertekek: m.aktiv, szin: '#34d399' }, { nev: 'Kérés', ertekek: m.keres, szin: '#a78bfa' }], format: szam, napos });
                terulet($('[data-d="db"]'), { t: m.t, sorok: [{ nev: 'Szabad lemez', ertekek: m.lemez_mb, szin: '#fbbf24' }, { nev: 'Adatbázis', ertekek: m.db_mb, szin: '#60a5fa' }], format: mb, napos });
                terulet($('[data-d="mem"]'), { t: m.t, sorok: [{ nev: 'Memóriacsúcs (MB)', ertekek: m.mem_mb, szin: '#f472b6' }, { nev: 'Adatbázis-próba (ms)', ertekek: m.db_ms, szin: '#22d3ee' }, { nev: 'Végzetes hiba', ertekek: m.hiba, szin: '#fb7185' }], format: szam, napos });
            };
            $('[data-szegmens="tav"]', hova).addEventListener('valtozas', (ev) => { S.rtav = ev.detail; rajzol(); });
            rajzol().catch(() => {});
            return;
        }

        if (ful === 'adatok') {
            const csoportok = Object.entries(CIMKEK).map(([kulcs, [nev, ikon, cimkek]]) => {
                const obj = s[kulcs];
                if (!obj || typeof obj !== 'object' || Array.isArray(obj)) return '';
                const sorok = Object.entries(obj).filter(([k]) => !['katalogus', 'modulok_be', 'modulok_ki'].includes(k)).map(([k, v]) => `<dt>${e(cimkek[k] || k)}</dt><dd>${ertek(k, v)}</dd>`).join('');
                return sorok ? `<section class="kp-kartya kp-adatcsoport"><h4>${I(ikon)}${e(nev)}</h4><dl class="kp-kv">${sorok}</dl></section>` : '';
            }).join('');
            const egyeb = Object.entries(s).filter(([k, v]) => !CIMKEK[k] && !['hibak', 'bovitmenyek'].includes(k) && v && typeof v === 'object' && !Array.isArray(v))
                .map(([k, v]) => `<section class="kp-kartya kp-adatcsoport"><h4>${I('info')}${e(k)}</h4><dl class="kp-kv">${Object.entries(v).map(([kk, vv]) => `<dt>${e(kk)}</dt><dd>${ertek(kk, vv)}</dd>`).join('')}</dl></section>`).join('');
            hova.innerHTML = csoportok || egyeb ? `<div class="kp-adatok">${csoportok}${egyeb}</div><p class="kp-halvany kp-kicsi">A vevő ügyfeleinek személyes adata (név, telefon, e-mail, cím) és a forgalom összege nem jön át – csak műszaki adat és darabszám.</p>` : `<div class="kp-kartya">${ures('Ez a rendszer még nem küld részletes adatot (régi verzió).')}</div>`;
            return;
        }

        if (ful === 'muveletek') {
            const tilt = p.mod === 'napi' || !(a.licenc && a.licenc.jogok.tavoli) || !p.tavoli;
            const ok = !a.licenc ? 'Nincs licenc.' : p.mod === 'napi' ? 'Régi verzió: frissíteni kell 0.99.0-ra.' : !a.licenc.jogok.tavoli ? 'A licenc nem tartalmaz távoli karbantartást (Licencek › Jogok).' : !p.tavoli ? 'A vevő kikapcsolta a távoli karbantartást (nála: Beállítások › Licenc).' : '';
            const gomb = (k) => {
                const mindig = ['uzenet', 'uzenet_torol', 'tamogatas_keres'].includes(k);
                const ki = mindig ? p.mod === 'napi' || (k === 'tamogatas_keres' && !(a.licenc && a.licenc.jogok.tamogatas)) : tilt;
                return `<button type="button" class="kp-muvelet" data-muvelet="${k}" ${ki ? 'disabled' : ''} ${k === 'frissites_telepit' ? 'data-ketlepcsos="Biztos? Mentés, majd frissítés"' : ''}>${I(k)}<b>${e(a.muveletek_lista[k])}</b><small>${e(MUVELET_LEIRAS[k] || '')}</small></button>`;
            };
            hova.innerHTML = `<div class="kp-racs kp-racs--2"><section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Távoli karbantartás</h3><span class="kp-kartya__al">a rendszer a következő jelentkezéskor (élő követésben fél percen belül) végrehajtja</span></div>
                ${ok ? `<div class="kp-problema" style="margin-bottom:12px">${I('info')}${e(ok)}</div>` : ''}
                <div class="kp-muveletek">${Object.keys(a.muveletek_lista).map(gomb).join('')}</div></section>
                <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Előzmények</h3></div>
                ${a.muveletek.length ? `<div class="kp-lista">${a.muveletek.map((m) => `<div class="kp-lista__elem"><div class="kp-lista__test"><div class="kp-lista__fo">${e(m.cim)} <span class="kp-cimke kp-cimke--${MUVELET_ALLAPOT[m.allapot][1]}">${MUVELET_ALLAPOT[m.allapot][0]}</span></div>
                    <div class="kp-lista__al">${datumIdo(m.letrehozva)}${m.ki ? ' · ' + e(m.ki) : ''}${m.eredmeny && m.eredmeny.uzenet ? `<br>${e(m.eredmeny.uzenet)}` : ''}</div></div></div>`).join('')}</div>` : ures('Még nem volt távoli művelet.')}</section></div>`;
            $$('[data-muvelet]', hova).forEach((b) => b.addEventListener('click', () => {
                if (b.classList.contains('is-elesitve')) return;
                muveletIndit(id, b.dataset.muvelet);
            }));
            return;
        }

        if (ful === 'tamogatas') {
            const el = a.tamogatas.filter((t) => t.el);
            hova.innerHTML = `<div class="kp-racs kp-racs--2"><section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Belépés a vevő rendszerébe</h3></div>
                ${el.length ? el.map((t) => `<div class="kp-lista__elem"><div class="kp-lista__test"><div class="kp-lista__fo">Engedélyezte: ${e(t.ki)}</div><div class="kp-lista__al">lejár ${datumIdo(t.lejar)} (${relativ(t.lejar)})</div></div><a class="kp-gomb kp-gomb--fo" href="${e(t.link)}" target="_blank" rel="noopener noreferrer">${I('tamogatas_keres')}Belépés</a><button class="kp-gomb" data-masol="${e(t.link)}">${I('masol')}</button></div>`).join('')
                    : `<p class="kp-halvany">Most nincs érvényes belépő link. Kérj hozzáférést: a vevő adminisztrátora egy gombbal engedélyezi, és a link ide érkezik.</p>`}
                <div class="kp-sor" style="margin-top:12px"><button class="kp-gomb kp-gomb--fo" data-muvelet="tamogatas_keres" ${p.mod === 'napi' || !(a.licenc && a.licenc.jogok.tamogatas) ? 'disabled' : ''}>${I('tamogatas_keres')}Hozzáférés kérése</button><button class="kp-gomb" data-muvelet="uzenet" ${p.mod === 'napi' ? 'disabled' : ''}>${I('uzenet')}Üzenet a vevőnek</button></div></section>
                <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Korábbi hozzáférések</h3></div>
                ${a.tamogatas.length ? `<div class="kp-lista">${a.tamogatas.map((t) => `<div class="kp-lista__elem"><div class="kp-lista__test"><div class="kp-lista__fo">${e(t.ki)}</div><div class="kp-lista__al">${datumIdo(t.erkezett)} → ${datumIdo(t.lejar)}</div></div><span class="kp-cimke kp-cimke--${t.el ? 'ok' : 'szurke'}">${t.el ? 'érvényes' : 'lejárt'}</span></div>`).join('')}</div>` : ures('Még nem volt.')}
                ${a.vevo ? `<div class="kp-kv" style="margin-top:14px"><dt>Kapcsolattartó</dt><dd>${e(a.vevo.kapcsolattarto || '–')}</dd><dt>Telefon</dt><dd>${a.vevo.telefon ? `<a href="tel:${e(a.vevo.telefon)}">${e(a.vevo.telefon)}</a>` : '–'}</dd><dt>E-mail</dt><dd>${a.vevo.email ? `<a href="mailto:${e(a.vevo.email)}">${e(a.vevo.email)}</a>` : '–'}</dd></div>` : ''}</section></div>`;
            $$('[data-masol]', hova).forEach((b) => b.addEventListener('click', () => masol(b.dataset.masol)));
            $$('[data-muvelet]', hova).forEach((b) => b.addEventListener('click', () => muveletIndit(id, b.dataset.muvelet)));
            return;
        }

        if (ful === 'modulok') {
            const l = a.licenc, be = (s.muhely || {}).modulok_be || [], kiL = (s.muhely || {}).modulok_ki || [];
            const kat = a.katalogus;
            const lic = l ? (l.modulok === '*' ? Object.keys(kat) : l.modulok) : [];
            hova.innerHTML = `<div class="kp-racs kp-racs--2"><section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Modulok</h3><span class="kp-kartya__al">licenc szerint és a vevő gépén</span></div>
                <div class="kp-tabla-doboz"><table class="kp-tabla"><thead><tr><th>Modul</th><th>Licenc</th><th>A vevőnél</th></tr></thead><tbody>${Object.entries(kat).map(([k, m]) => `<tr><td>${e(m.cim)}<div class="kp-halvany kp-kicsi">${e(m.csoport)}</div></td><td>${lic.includes(k) ? '<span class="kp-cimke kp-cimke--ok">benne</span>' : '<span class="kp-cimke kp-cimke--szurke">nincs</span>'}</td><td>${be.includes(k) ? '<span class="kp-cimke kp-cimke--ok">fut</span>' : kiL.includes(k) ? '<span class="kp-cimke kp-cimke--szurke">kikapcsolva</span>' : '<span class="kp-halvany">–</span>'}</td></tr>`).join('')}</tbody></table></div></section>
                <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Licenc</h3><span class="kp-ter"></span>${l ? '<button class="kp-gomb kp-gomb--kicsi" data-licenc-szerk>' + I('szerkeszt') + 'Szerkesztés</button>' : ''}</div>
                ${l ? `<dl class="kp-kv"><dt>Kulcs</dt><dd class="kp-mono">${e(l.kulcs)}</dd><dt>Csomag</dt><dd>${e(l.csomag || '–')}</dd><dt>Állapot</dt><dd>${e(LICENC_ALLAPOT[l.tenyleges])}${l.proba ? ' · próba' : ''}</dd><dt>Lejár</dt><dd>${l.lejar ? isoDatum(l.lejar) : 'soha'}</dd><dt>Példány</dt><dd>${l.max_peldany}</dd><dt>Felhasználó</dt><dd>${l.max_felhasznalo || 'korlátlan'}</dd><dt>Csatorna</dt><dd>${l.csatorna === 'teszt' ? 'teszt' : 'stabil'}</dd>
                ${Object.entries(l.jogok).map(([k, v]) => `<dt>${e({ frissites: 'Frissítés', tamogatas: 'Támogatás', tavoli: 'Távoli karbantartás' }[k])}</dt><dd>${v ? 'igen' : 'nem'}</dd>`).join('')}</dl>` : ures('Nincs licenc.')}</section></div>`;
            $('[data-licenc-szerk]', hova)?.addEventListener('click', () => licencPopup(l, () => rendszer(false, id)));
            return;
        }

        if (ful === 'hibak') {
            const hibak = s.hibak || [], bov = s.bovitmenyek || [];
            hova.innerHTML = `<div class="kp-racs kp-racs--2"><section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Végzetes hibák</h3><span class="kp-kartya__al">az utolsó 20 különböző hiba</span></div>
                ${hibak.length ? hibak.map((h) => `<div class="kp-hibalista__elem"><div class="kp-sor kp-sor--kozte"><b>${e(h.tipus || 'Hiba')}</b><span class="kp-halvany kp-kicsi">${szam(h.db)}× · ${relativ(h.utolso)}</span></div><div>${e(h.uzenet || '')}</div><code>${e(h.fajl || '')}:${e(h.sor || '')}</code></div>`).join('') : ures('Nem volt végzetes hiba.', 'pipa')}</section>
                <section class="kp-kartya kp-kartya--sima"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Bekapcsolt bővítmények</h3><span class="kp-kartya__al">${bov.length} db</span></div>
                ${bov.length ? `<table class="kp-tabla"><tbody>${bov.map((b) => `<tr><td>${e(b.n)}</td><td class="kp-jobb kp-mono kp-halvany">${e(b.v)}</td></tr>`).join('')}</tbody></table>` : ures('Nincs adat.')}</section></div>`;
            return;
        }

        if (ful === 'naplo') {
            hova.innerHTML = `<section class="kp-kartya">${idovonal(a.esemenyek, { vevo: false })}</section>`;
            return;
        }

        hova.innerHTML = `<section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">A legutóbbi jelentés, ahogy érkezett</h3><span class="kp-ter"></span><button class="kp-gomb kp-gomb--kicsi" data-masol-json>${I('masol')}Másolás</button></div><pre class="kp-json">${e(JSON.stringify(s, null, 2))}</pre></section>`;
        $('[data-masol-json]', hova).addEventListener('click', () => masol(JSON.stringify(s, null, 2)));
    }

    async function muveletIndit(id, tipus) {
        const kuld = async (parameter = {}) => {
            try {
                const r = await api(`peldany/${id}/muvelet`, { method: 'POST', body: { tipus, parameter } });
                jelez(r.uzenet);
                const a = await api('peldany/' + id);
                S.radat = a;
                rendszerFul(a, id);
                return true;
            } catch (x) { jelez(x.message, 'hiba'); return false; }
        };
        if (tipus === 'uzenet') {
            const pp = popup({ cim: 'Üzenet a vevőnek', meret: 'kicsi', test: `${mezo('Szöveg', '<textarea class="kp-textarea" name="szoveg" maxlength="500" placeholder="pl. Ma 18 órakor frissítjük a rendszert, kb. 5 perc."></textarea>', 'A vevő adminisztrátorai sávként látják a rendszer tetején, amíg le nem veszed.')}${mezo('Jelleg', szegmens('szint', { info: 'Tájékoztatás', figyel: 'Figyelmeztetés', hiba: 'Sürgős' }, 'info'))}`, lab: '<button class="kp-gomb" data-bezar>Mégse</button><button class="kp-gomb kp-gomb--fo" data-kuld>Küldés</button>' });
            $('[data-kuld]', pp.el).addEventListener('click', async () => { if (await kuld(urlapAdat(pp.el))) pp.bezar(); });
            return;
        }
        if (tipus === 'tamogatas_keres') {
            const pp = popup({ cim: 'Támogatási hozzáférés kérése', meret: 'kicsi', test: `<p class="kp-halvany" style="margin:0">A vevő adminisztrátora a rendszerében egy sávot lát: „Engedélyezem” vagy „Elutasítom”. Ha engedélyezi, ideiglenes fiók jön létre, és a belépő link ide érkezik.</p>${mezo('Időtartam', szegmens('ora', { 2: '2 óra', 24: '1 nap', 72: '3 nap' }, '2'))}${mezo('Miért kéred?', input('indok', '', 'maxlength="200" placeholder="pl. a nyomtatási hiba megnézése"'), 'A vevő ezt látja a kérésben.')}`, lab: '<button class="kp-gomb" data-bezar>Mégse</button><button class="kp-gomb kp-gomb--fo" data-kuld>Kérés elküldése</button>' });
            $('[data-kuld]', pp.el).addEventListener('click', async () => { if (await kuld(urlapAdat(pp.el))) pp.bezar(); });
            return;
        }
        kuld();
    }

    /* ================================================================== Vevők */

    const VEVO_ALLAPOT = { erdeklodo: 'Érdeklődő', aktiv: 'Aktív', szunetel: 'Szünetel', volt: 'Volt vevő' };

    async function vevok(csendes) {
        cim('Vevők');
        if (!csendes) betolt();
        let a;
        try { a = await api('vevok'); } catch (x) { if (!csendes) hibaOldal(x); return; }
        if (S.oldal !== 'vevok') return;
        const q = (S.vq || '').toLowerCase(), sz = S.vszuro || 'mind';
        const lista = a.lista.filter((v) => (sz === 'mind' || v.allapot === sz) && (!q || (v.nev + ' ' + v.email + ' ' + v.telefon + ' ' + v.kapcsolattarto + ' ' + v.cimkek).toLowerCase().includes(q)));
        const ossz = a.lista.reduce((x, v) => x + v.havi, 0);
        const fokusz = document.activeElement && document.activeElement.matches('[data-vq]');
        tartalom().innerHTML = `
            <div class="kp-racs kp-racs--kpi" style="grid-template-columns:repeat(4,minmax(0,1fr))">
                ${kpi('vevo', 'Vevők', szam(a.lista.filter((v) => v.allapot !== 'volt').length), `${a.lista.filter((v) => v.allapot === 'erdeklodo').length} érdeklődő`)}
                ${kpi('licenc', 'Aktív licenc', szam(a.lista.reduce((x, v) => x + v.aktiv_licenc, 0)), 'összesen')}
                ${kpi('rendszer', 'Telepítés', szam(a.lista.reduce((x, v) => x + v.peldany_db, 0)), `${a.lista.reduce((x, v) => x + v.rossz, 0)} gondban`)}
                ${kpi('penz', 'Havi bevétel', ft(ossz), 'licencdíjakból')}
            </div>
            <div class="kp-sor kp-sor--kozte kp-sor--tor"><div class="kp-szuro">${szegmens('vszuro', { mind: 'Mind', aktiv: 'Aktív', erdeklodo: 'Érdeklődő', szunetel: 'Szünetel', volt: 'Volt' }, sz)}<input class="kp-input" data-vq placeholder="Név, e-mail, telefon, címke…" value="${e(S.vq || '')}"></div><button class="kp-gomb kp-gomb--fo" data-uj>${I('plusz')}Új vevő</button></div>
            <div class="kp-kartya kp-kartya--sima">${lista.length ? `<div class="kp-tabla-doboz"><table class="kp-tabla"><thead><tr><th>Vevő</th><th>Kapcsolat</th><th>Állapot</th><th class="kp-jobb">Licenc</th><th>Rendszerek</th><th>Legközelebb lejár</th><th class="kp-jobb">Havi díj</th></tr></thead><tbody>
                ${lista.map((v) => `<tr class="is-katt" data-nav="#/vevo/${v.id}"><td><div class="kp-vastag">${e(v.nev)}</div><div class="kp-halvany kp-kicsi">${e(v.cimkek)}</div></td><td><div>${e(v.kapcsolattarto || '–')}</div><div class="kp-halvany kp-kicsi">${e([v.telefon, v.email].filter(Boolean).join(' · '))}</div></td>
                <td><span class="kp-cimke ${v.allapot === 'aktiv' ? 'kp-cimke--ok' : v.allapot === 'erdeklodo' ? 'kp-cimke--kiemelo' : 'kp-cimke--szurke'}">${e(VEVO_ALLAPOT[v.allapot] || v.allapot)}</span></td><td class="kp-jobb">${v.aktiv_licenc} / ${v.licenc_db}</td>
                <td>${v.peldany_db ? `<span class="kp-sor">${pont(v.rossz ? 'hiba' : v.online ? 'online' : 'offline')}${v.online} / ${v.peldany_db} jelentkezik</span>` : '<span class="kp-halvany">–</span>'}</td><td>${v.lejar ? isoDatum(v.lejar) : '<span class="kp-halvany">–</span>'}</td><td class="kp-jobb">${v.havi ? ft(v.havi) : '–'}</td></tr>`).join('')}
            </tbody></table></div>` : ures(a.lista.length ? 'Nincs találat.' : 'Még nincs vevő. Vegyél fel egyet!', 'vevo')}</div>`;
        $('[data-szegmens="vszuro"]').addEventListener('valtozas', (ev) => { S.vszuro = ev.detail; vevok(true); });
        const qi = $('[data-vq]');
        qi.addEventListener('input', () => { S.vq = qi.value; clearTimeout(S.vqi); S.vqi = setTimeout(() => vevok(true), 200); });
        if (fokusz) { qi.focus(); qi.setSelectionRange(qi.value.length, qi.value.length); }
        $('[data-uj]').addEventListener('click', () => vevoPopup(null, (id) => { location.hash = '#/vevo/' + id; }));
    }

    function vevoPopup(v, kesz) {
        v = v || { allapot: 'aktiv' };
        const pp = popup({
            cim: v.id ? 'Vevő szerkesztése' : 'Új vevő', meret: '',
            test: `<div class="kp-mezok">
                ${mezo('Cégnév / név', input('nev', v.nev || '', 'required'), '', 'kp-mezo--szeles')}
                ${mezo('Kapcsolattartó', input('kapcsolattarto', v.kapcsolattarto || ''))}
                ${mezo('Állapot', select('allapot', VEVO_ALLAPOT, v.allapot))}
                ${mezo('E-mail', input('email', v.email || '', 'inputmode="email"'))}
                ${mezo('Telefon', input('telefon', v.telefon || '', 'inputmode="tel"'))}
                ${mezo('Cím', input('cim', v.cim || ''))}
                ${mezo('Adószám', input('adoszam', v.adoszam || ''))}
                ${mezo('Weboldal', input('weboldal', v.weboldal || '', 'inputmode="url" placeholder="https://"'))}
                ${mezo('Címkék', input('cimkek', v.cimkek || '', 'placeholder="pl. Veszprém, Apple, kiemelt"'), 'Vesszővel elválasztva.')}
                ${mezo('Megjegyzés', `<textarea class="kp-textarea" name="megjegyzes" rows="2">${e(v.megjegyzes || '')}</textarea>`, '', 'kp-mezo--szeles')}
            </div>`,
            lab: '<button class="kp-gomb" data-bezar>Mégse</button><button class="kp-gomb kp-gomb--fo" data-ment>Mentés</button>',
        });
        $('[data-ment]', pp.el).addEventListener('click', async () => {
            try {
                const r = await api('vevo', { method: 'POST', body: { ...urlapAdat(pp.el), id: v.id || 0 } });
                pp.bezar(); jelez('Vevő elmentve.'); kesz && kesz(r.id);
            } catch (x) { hibaKi(pp.el, x.message); }
        });
    }

    async function vevo(csendes, id) {
        if (!csendes) { cim('Vevő', '<a href="#/vevok">Vevők</a>'); betolt(); }
        let a;
        try { a = await api('vevo/' + id); } catch (x) { if (!csendes) hibaOldal(x); return; }
        if (S.oldal !== 'vevo') return;
        if (csendes && document.activeElement?.closest('[data-jegyzet]')) return;
        const v = a.v;
        cim(v.nev, '<a href="#/vevok">Vevők</a>');
        tartalom().innerHTML = `
            <section class="kp-kartya"><div class="kp-hos">
                <div class="kp-hos__orb" style="background:var(--kp-atmenet);color:#fff;font-weight:800;font-size:20px;border:0">${e((v.nev || '?').trim().slice(0, 2).toUpperCase())}</div>
                <div style="min-width:0"><h2 class="kp-hos__cim">${e(v.nev)}</h2><div class="kp-hos__al"><span class="kp-cimke ${v.allapot === 'aktiv' ? 'kp-cimke--ok' : 'kp-cimke--szurke'}">${e(VEVO_ALLAPOT[v.allapot])}</span>${v.cimkek ? v.cimkek.split(',').map((c) => `<span class="kp-cimke">${e(c.trim())}</span>`).join('') : ''}<span class="kp-halvany">vevő ${datum(v.letrehozva)} óta</span></div></div>
                <div class="kp-hos__gombok"><button class="kp-gomb" data-szerk>${I('szerkeszt')}Szerkesztés</button><button class="kp-gomb kp-gomb--fo" data-uj-licenc>${I('licenc')}Új licenc</button></div>
            </div></section>
            <div class="kp-racs kp-racs--2">
                <div class="kp-oszlop">
                    <section class="kp-kartya kp-kartya--sima"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Licencek</h3><span class="kp-kartya__al">havi ${ft(a.havi)}</span></div>
                        ${a.licencek.length ? licencTabla(a.licencek, false) : `<div style="padding:0 20px 20px">${ures('Még nincs licenc.', 'licenc')}</div>`}</section>
                    <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Rendszerek</h3><span class="kp-kartya__al">${a.peldanyok.length} telepítés</span></div>
                        ${a.peldanyok.length ? `<div class="kp-rendszerek">${a.peldanyok.map((p) => rendszerKartya(p, '')).join('')}</div>` : ures('Még nem csatlakozott rendszer.', 'rendszer')}</section>
                </div>
                <div class="kp-oszlop">
                    <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Kapcsolat</h3></div>
                        <dl class="kp-kv"><dt>Kapcsolattartó</dt><dd>${e(v.kapcsolattarto || '–')}</dd><dt>Telefon</dt><dd>${v.telefon ? `<a href="tel:${e(v.telefon)}">${e(v.telefon)}</a>` : '–'}</dd><dt>E-mail</dt><dd>${v.email ? `<a href="mailto:${e(v.email)}">${e(v.email)}</a>` : '–'}</dd><dt>Cím</dt><dd>${e(v.cim || '–')}</dd><dt>Adószám</dt><dd>${e(v.adoszam || '–')}</dd><dt>Weboldal</dt><dd>${v.weboldal ? `<a href="${e(v.weboldal)}" target="_blank" rel="noopener">${e(v.weboldal)}</a>` : '–'}</dd></dl>
                        ${v.megjegyzes ? `<p class="kp-halvany" style="white-space:pre-wrap;margin:14px 0 0">${e(v.megjegyzes)}</p>` : ''}</section>
                    <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Idővonal</h3></div>
                        <div class="kp-sor" data-jegyzet style="margin-bottom:12px"><input class="kp-input" placeholder="Jegyzet: hívás, ajánlat, megbeszélés…" data-jegyzet-mezo><button class="kp-gomb" data-jegyzet-ment>${I('plusz')}</button></div>
                        ${idovonal(a.esemenyek, { vevo: false })}</section>
                </div>
            </div>`;
        $('[data-szerk]').addEventListener('click', () => vevoPopup(v, () => vevo(false, id)));
        $('[data-uj-licenc]').addEventListener('click', () => licencPopup({ vevo_id: v.id }, () => vevo(false, id)));
        licencTablaKotes(() => vevo(false, id));
        const jm = $('[data-jegyzet-mezo]');
        const jment = async () => { if (!jm.value.trim()) return; await api(`vevo/${id}/jegyzet`, { method: 'POST', body: { szoveg: jm.value } }); jelez('Jegyzet rögzítve.'); vevo(false, id); };
        $('[data-jegyzet-ment]').addEventListener('click', jment);
        jm.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') jment(); });
        ismetel((c) => vevo(c, id), 30);
    }

    /* ================================================================== Licencek */

    function licencTabla(lista, vevoOszlop = true) {
        return `<div class="kp-tabla-doboz"><table class="kp-tabla"><thead><tr><th>Kulcs</th>${vevoOszlop ? '<th>Vevő</th>' : ''}<th>Csomag</th><th>Lejár</th><th class="kp-jobb">Példány</th><th class="kp-jobb">Díj</th><th>Állapot</th></tr></thead><tbody>
            ${lista.map((l) => `<tr class="is-katt" data-licenc="${l.id}"><td><span class="kp-licenckulcs" data-masol-kulcs="${e(l.kulcs)}" title="Másolás">${e(l.kulcs)}</span></td>${vevoOszlop ? `<td><a href="#/vevo/${l.vevo_id}" class="kp-vastag">${e(l.vevo)}</a></td>` : ''}
            <td>${e(l.csomag || '–')}<div class="kp-halvany kp-kicsi">${l.modulok === '*' ? 'minden modul' : l.modulok.length + ' modul'}</div></td>
            <td>${l.lejar ? `${isoDatum(l.lejar)}${l.tenyleges === 'aktiv' && l.lejar_nap !== null && l.lejar_nap <= 30 ? ` <span class="kp-cimke kp-cimke--${l.lejar_nap <= 14 ? 'hiba' : 'figyel'}">${l.lejar_nap} nap</span>` : ''}` : '<span class="kp-halvany">soha</span>'}</td>
            <td class="kp-jobb">${l.peldany_db} / ${l.max_peldany}</td><td class="kp-jobb">${l.dij ? ft(l.dij) + `<div class="kp-halvany kp-kicsi">${CIKLUS[l.ciklus]}</div>` : '–'}</td>
            <td><span class="kp-cimke kp-cimke--${l.tenyleges === 'aktiv' ? (l.proba ? 'kiemelo' : 'ok') : l.tenyleges === 'lejart' ? 'figyel' : 'hiba'}">${l.tenyleges === 'aktiv' && l.proba ? 'Próba' : e(LICENC_ALLAPOT[l.tenyleges])}</span>${l.csatorna === 'teszt' ? ' <span class="kp-cimke kp-cimke--kiemelo">teszt</span>' : ''}</td></tr>`).join('')}
        </tbody></table></div>`;
    }

    function licencTablaKotes(kesz) {
        $$('[data-masol-kulcs]').forEach((k) => k.addEventListener('click', (ev) => { ev.stopPropagation(); masol(k.dataset.masolKulcs); }));
        $$('[data-licenc]').forEach((tr) => tr.addEventListener('click', async (ev) => {
            if (ev.target.closest('a')) return;
            const d = await api('licencek');
            licencPopup(d.lista.find((l) => l.id === Number(tr.dataset.licenc)), kesz, d);
        }));
    }

    async function licencek(csendes) {
        cim('Licencek');
        if (!csendes) betolt();
        let a;
        try { a = await api('licencek'); } catch (x) { if (!csendes) hibaOldal(x); return; }
        if (S.oldal !== 'licencek') return;
        const sz = S.lszuro || 'mind', q = (S.lq || '').toLowerCase();
        const illik = (l) => sz === 'mind' || (sz === 'aktiv' && l.tenyleges === 'aktiv' && !l.proba) || (sz === 'proba' && l.tenyleges === 'aktiv' && l.proba) || (sz === 'lejaro' && l.tenyleges === 'aktiv' && l.lejar_nap !== null && l.lejar_nap <= 60) || l.tenyleges === sz;
        const lista = a.lista.filter((l) => illik(l) && (!q || (l.kulcs + ' ' + l.vevo + ' ' + l.csomag + ' ' + l.vevo_azonosito).toLowerCase().includes(q)));
        const db = (s) => a.lista.filter((l) => (s === 'proba' ? l.tenyleges === 'aktiv' && l.proba : s === 'lejaro' ? l.tenyleges === 'aktiv' && l.lejar_nap !== null && l.lejar_nap <= 60 : s === 'aktiv' ? l.tenyleges === 'aktiv' && !l.proba : l.tenyleges === s)).length;
        const fokusz = document.activeElement && document.activeElement.matches('[data-lq]');
        tartalom().innerHTML = `
            <div class="kp-sor kp-sor--kozte kp-sor--tor"><div class="kp-szuro">${szegmens('lszuro', { mind: `Mind (${a.lista.length})`, aktiv: `Aktív (${db('aktiv')})`, proba: `Próba (${db('proba')})`, lejaro: `Lejáró (${db('lejaro')})`, lejart: `Lejárt (${db('lejart')})`, tiltva: `Tiltott (${db('tiltva')})` }, sz)}
            <input class="kp-input" data-lq placeholder="Kulcs, vevő, csomag…" value="${e(S.lq || '')}"></div><button class="kp-gomb kp-gomb--fo" data-uj>${I('plusz')}Új licenc</button></div>
            <div class="kp-kartya kp-kartya--sima">${lista.length ? licencTabla(lista) : ures(a.lista.length ? 'Nincs találat.' : 'Még nincs licenc. Hozz létre egyet – a kulcs magától készül.', 'licenc')}</div>`;
        $('[data-szegmens="lszuro"]').addEventListener('valtozas', (ev) => { S.lszuro = ev.detail; licencek(true); });
        const qi = $('[data-lq]');
        qi.addEventListener('input', () => { S.lq = qi.value; clearTimeout(S.lqi); S.lqi = setTimeout(() => licencek(true), 200); });
        if (fokusz) { qi.focus(); qi.setSelectionRange(qi.value.length, qi.value.length); }
        $('[data-uj]').addEventListener('click', () => licencPopup(null, () => licencek(true), a));
        licencTablaKotes(() => licencek(true));
    }

    async function licencPopup(l, kesz, adat = null) {
        try { adat = adat || await api('licencek'); } catch (x) { jelez(x.message, 'hiba'); return; }
        const uj = !l || !l.id;
        l = Object.assign({ modulok: '*', jogok: { frissites: true, tamogatas: true, tavoli: true }, max_peldany: 1, max_felhasznalo: 0, ciklus: 'eves', csatorna: 'stabil', allapot: 'aktiv', proba: 0, dij: 0 }, l || {});
        const kat = adat.katalogus, minden = l.modulok === '*';
        const csoportok = {};
        Object.entries(kat).forEach(([k, m]) => { (csoportok[m.csoport] = csoportok[m.csoport] || []).push([k, m]); });
        const vevoOpc = { 0: '— Válassz vevőt —', ...Object.fromEntries(adat.vevok.map((v) => [v.id, v.nev])), uj: '+ Új vevő…' };
        const csomagOpc = { '': '— Egyedi —', ...Object.fromEntries(adat.csomagok.map((c) => [c.nev, c.nev])) };

        const pp = popup({
            cim: uj ? 'Új licenc' : 'Licenc: ' + l.kulcs, meret: 'szeles',
            test: `<div class="kp-fulek" style="margin-bottom:4px">${[['alap', 'Alapadatok'], ['jogok', 'Modulok és jogok'], ['penz', 'Díj és esedékesség'], ['egyeb', 'Üzenet, megjegyzés']].map(([k, n], i) => `<button type="button" data-lful="${k}" class="${i ? '' : 'is-aktiv'}">${e(n)}</button>`).join('')}</div>
                <div data-lpanel="alap"><div class="kp-mezok">
                    ${mezo('Vevő', select('vevo_id', vevoOpc, l.vevo_id || 0))}
                    <div class="kp-mezo" data-uj-vevo hidden><span class="kp-mezo__cim">Új vevő neve</span>${input('vevo_nev', '')}</div>
                    ${mezo('Csomag', select('csomag', csomagOpc, l.csomag || ''), 'A csomag kitölti a modulokat és a korlátokat.')}
                    ${mezo('Lejárat', datumMezo('lejar', l.lejar || '', 'Soha nem jár le'), 'Lejárt licencnél a rendszer működik, csak frissítést nem kap.')}
                    ${mezo('Példányok (gépek) száma', input('max_peldany', l.max_peldany, 'inputmode="numeric"'))}
                    ${mezo('Felhasználók száma', input('max_felhasznalo', l.max_felhasznalo, 'inputmode="numeric"'), '0 = korlátlan. Túllépésnél figyelmeztetés jön.')}
                    ${mezo('Vevőazonosító (pl. szerződésszám)', input('vevo_azonosito', l.vevo_azonosito || ''))}
                    ${mezo('Frissítési csatorna', szegmens('csatorna', { stabil: 'Stabil', teszt: 'Teszt (előzetes)' }, l.csatorna))}
                    <div class="kp-mezok" style="grid-column:1/-1;grid-template-columns:1fr 1fr">${kapcsolosor('proba', !!l.proba, 'Próbaidős licenc', 'Próbaként jelenik meg, bevételbe nem számít')}${kapcsolosor('aktiv', l.allapot !== 'tiltva', 'Engedélyezve', 'Kikapcsolva: letiltott, frissítést nem kap')}</div>
                </div></div>
                <div data-lpanel="jogok" hidden>
                    <div class="kp-kapcsolok" style="margin-bottom:12px">${Object.entries(adat.jogok).map(([k, n]) => kapcsolosor('jog_' + k, l.jogok[k], { frissites: 'Frissítés', tamogatas: 'Támogatás', tavoli: 'Távoli karbantartás' }[k], n)).join('')}</div>
                    <div class="kp-sor kp-sor--kozte" style="margin:4px 0 8px"><b>Modulok</b><div class="kp-sor"><span class="kp-halvany kp-kicsi">minden modul (a jövőbeliek is)</span>${kapcsolo('minden', minden, 'Minden modul', 'data-minden')}</div></div>
                    <div class="kp-kapcsolok" data-modulok>${Object.entries(csoportok).map(([, ms]) => ms.map(([k, m]) => kapcsolosor('mod_' + k, minden || (Array.isArray(l.modulok) && l.modulok.includes(k)), m.cim, m.csoport, minden ? 'disabled' : '')).join('')).join('')}</div>
                    <p class="kp-halvany kp-kicsi" style="margin:10px 0 0">Az alaprendszer (munkalap, ügyfél, eszköz, szolgáltatás, beállítás, mentés, napló) mindig benne van. A ki nem fizetett modul a vevőnél kikapcsol, az adata megmarad.</p>
                </div>
                <div data-lpanel="penz" hidden><div class="kp-mezok kp-mezok--3">
                    ${mezo('Díj (Ft)', input('dij', l.dij || '', 'inputmode="numeric" placeholder="0"'))}
                    ${mezo('Számlázás', select('ciklus', { havi: 'Havonta', eves: 'Évente', egyszeri: 'Egyszeri' }, l.ciklus))}
                    ${mezo('Következő esedékesség', datumMezo('esedekes', l.esedekes || '', 'Nincs'))}
                </div><p class="kp-halvany kp-kicsi">A havi bevétel a havi díjakból és az éves díj tizenketted részéből jön; a próbaidős és a lejárt licenc nem számít bele.</p>
                ${uj ? '' : `<div class="kp-sor" style="margin-top:8px"><button class="kp-gomb" data-lmuv="megujit">${I('frissites_keres')}Megújítás +1 ${l.ciklus === 'havi' ? 'hónap' : 'év'}</button></div>`}</div>
                <div data-lpanel="egyeb" hidden><div class="kp-mezok">
                    ${mezo('Üzenet a vevőnek', `<textarea class="kp-textarea" name="uzenet" maxlength="255" rows="3">${e(l.uzenet || '')}</textarea>`, 'A vevő a Beállítások › Licenc lapon látja.')}
                    ${mezo('Belső megjegyzés', `<textarea class="kp-textarea" name="megjegyzes" rows="3">${e(l.megjegyzes || '')}</textarea>`, 'Csak itt látszik.')}
                </div>
                ${uj ? '' : `<div class="kp-sor kp-sor--tor" style="margin-top:6px"><button class="kp-gomb kp-gomb--veszely" data-lmuv="ujkulcs" data-ketlepcsos="Biztos? A vevőnél cserélni kell">${I('licenc')}Új kulcs generálása</button></div>`}</div>`,
            lab: `${uj ? '' : `<span class="kp-licenckulcs" data-masol-kulcs="${e(l.kulcs)}">${e(l.kulcs)}</span>`}<span class="kp-ter"></span><button class="kp-gomb" data-bezar>Mégse</button><button class="kp-gomb kp-gomb--fo" data-ment>${uj ? 'Licenc létrehozása' : 'Mentés'}</button>`,
        });
        const p = pp.el;
        $$('[data-lful]', p).forEach((b) => b.addEventListener('click', () => { $$('[data-lful]', p).forEach((x) => x.classList.toggle('is-aktiv', x === b)); $$('[data-lpanel]', p).forEach((x) => { x.hidden = x.dataset.lpanel !== b.dataset.lful; }); }));
        $('[name="vevo_id"]', p).addEventListener('change', (ev) => { $('[data-uj-vevo]', p).hidden = ev.target.value !== 'uj'; });
        $('[data-minden]', p).addEventListener('change', (ev) => { $$('[data-modulok] input', p).forEach((i) => { i.disabled = ev.target.checked; if (ev.target.checked) i.checked = true; }); });
        $('[name="csomag"]', p).addEventListener('change', (ev) => {
            const c = adat.csomagok.find((x) => x.nev === ev.target.value);
            if (!c) return;
            $('[name="max_peldany"]', p).value = c.max_peldany;
            $('[name="max_felhasznalo"]', p).value = c.max_felhasznalo;
            const mind = c.modulok === '*';
            $('[data-minden]', p).checked = mind;
            $$('[data-modulok] input', p).forEach((i) => { i.disabled = mind; i.checked = mind || c.modulok.includes(i.name.slice(4)); });
        });
        $('[data-masol-kulcs]', p)?.addEventListener('click', (ev) => masol(ev.currentTarget.dataset.masolKulcs));
        $$('[data-lmuv]', p).forEach((b) => b.addEventListener('click', async () => {
            if (b.classList.contains('is-elesitve')) return;
            try { await api(`licenc/${l.id}/muvelet`, { method: 'POST', body: { muvelet: b.dataset.lmuv } }); pp.bezar(); jelez(b.dataset.lmuv === 'megujit' ? 'Licenc megújítva.' : 'Új kulcs elkészült.'); kesz && kesz(); } catch (x) { hibaKi(p, x.message); }
        }));
        $('[data-ment]', p).addEventListener('click', async () => {
            const d = urlapAdat(p);
            const body = {
                id: l.id || 0, vevo_id: d.vevo_id === 'uj' ? 0 : Number(d.vevo_id), vevo_nev: d.vevo_id === 'uj' ? d.vevo_nev : '', csomag: d.csomag, lejar: d.lejar,
                max_peldany: d.max_peldany, max_felhasznalo: d.max_felhasznalo, vevo_azonosito: d.vevo_azonosito, csatorna: d.csatorna, proba: d.proba,
                allapot: d.aktiv ? 'aktiv' : 'tiltva', dij: String(d.dij).replace(/\D/g, ''), ciklus: d.ciklus, esedekes: d.esedekes, uzenet: d.uzenet, megjegyzes: d.megjegyzes,
                jogok: Object.fromEntries(Object.keys(adat.jogok).map((k) => [k, d['jog_' + k]])),
                modulok: d.minden ? '*' : Object.keys(kat).filter((k) => d['mod_' + k]),
            };
            try {
                const r = await api('licenc', { method: 'POST', body });
                pp.bezar();
                if (uj) {
                    const k = r.licenc.kulcs;
                    const kp = popup({ cim: 'A licenc elkészült', meret: 'kicsi', test: `<p style="margin:0">Add meg a vevőnél (Beállítások › Licenc):</p><div class="kp-kv"><dt>A központ címe</dt><dd class="kp-mono">${e(B.oldal)}</dd><dt>Licenckulcs</dt><dd><span class="kp-licenckulcs" data-masol-kulcs="${e(k)}">${e(k)}</span></dd></div>`, lab: `<button class="kp-gomb" data-masol-mind>${I('masol')}Mindkettő másolása</button><button class="kp-gomb kp-gomb--fo" data-bezar>Kész</button>` });
                    $('[data-masol-kulcs]', kp.el).addEventListener('click', () => masol(k));
                    $('[data-masol-mind]', kp.el).addEventListener('click', () => masol(`A központ címe: ${B.oldal}\nLicenckulcs: ${k}`));
                } else { jelez('Licenc elmentve.'); }
                kesz && kesz();
            } catch (x) { hibaKi(p, x.message); }
        });
    }

    /* ================================================================== Kiadások */

    async function kiadasok(csendes) {
        cim('Kiadások');
        if (!csendes) betolt();
        let a;
        try { a = await api('kiadasok'); } catch (x) { if (!csendes) hibaOldal(x); return; }
        if (S.oldal !== 'kiadasok') return;
        const ossz = Object.values(a.verziok).reduce((x, y) => x + y, 0) || 1;
        tartalom().innerHTML = `
            <div class="kp-racs kp-racs--2">
                <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Új kiadás feltöltése</h3><span class="kp-kartya__al">az SDH Műhely vevői zipje (benne: sdh-muhely/sdh-muhely.php)</span></div>
                    <label class="kp-feltolto" data-feltolto>${I('feltolt')}<b data-fajlnev>Húzd ide a zipet, vagy kattints</b><div class="kp-halvany kp-kicsi">A verziót a fájl fejlécéből olvasom ki.</div><input type="file" accept=".zip" hidden data-fajl></label>
                    <div class="kp-mezok" style="margin-top:14px">${mezo('Csatorna', szegmens('csatorna', { stabil: 'Stabil – mindenkinek', teszt: 'Teszt – csak a teszt csatornás licenceknek' }, 'stabil'))}${mezo('Mi változott?', input('megjegyzes', '', 'placeholder="pl. gyorsabb rács, új riport"'))}</div>
                    <div class="kp-sor" style="margin-top:14px;justify-content:flex-end"><button class="kp-gomb kp-gomb--fo" data-feltolt disabled>${I('feltolt')}Feltöltés és kiadás</button></div></section>
                <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Most kiadva</h3></div>
                    <dl class="kp-kv"><dt>Stabil</dt><dd><span class="kp-cimke kp-cimke--ok kp-cimke--mono">${e(a.stabil || '–')}</span></dd><dt>Teszt</dt><dd><span class="kp-cimke kp-cimke--kiemelo kp-cimke--mono">${e(a.teszt || '–')}</span></dd></dl>
                    <h4 class="kp-halvany kp-kicsi" style="margin:16px 0 8px;text-transform:uppercase;letter-spacing:.07em">Melyik verzió fut</h4>
                    <div class="kp-savdiagram">${Object.entries(a.verziok).sort((x, y) => (x[0] < y[0] ? 1 : -1)).map(([v, db]) => `<div class="kp-savdiagram__sor"><span class="kp-mono">${e(v || '?')}</span><span class="kp-savdiagram__sav"><i style="width:${(db / ossz) * 100}%"></i></span><b>${db}</b></div>`).join('') || ures('Még nincs rendszer.')}</div></section>
            </div>
            <section class="kp-kartya kp-kartya--sima"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Korábbi kiadások</h3></div>
                ${a.lista.length ? `<div class="kp-tabla-doboz"><table class="kp-tabla"><thead><tr><th>Verzió</th><th>Csatorna</th><th>Mi változott</th><th>Feltöltve</th><th class="kp-jobb">Méret</th><th class="kp-jobb">Fut rajta</th><th></th></tr></thead><tbody>
                ${a.lista.map((k) => `<tr><td><span class="kp-cimke kp-cimke--mono">${e(k.verzio)}</span>${k.van ? '' : ' <span class="kp-cimke kp-cimke--hiba">a fájl hiányzik</span>'}</td><td><span class="kp-cimke kp-cimke--${k.csatorna === 'stabil' ? 'ok' : 'kiemelo'}">${k.csatorna === 'stabil' ? 'stabil' : 'teszt'}</span></td><td>${e(k.megjegyzes || '–')}</td><td>${datumIdo(k.ido)}<div class="kp-halvany kp-kicsi">${e(k.ki)}</div></td><td class="kp-jobb">${mb(Math.round(k.meret / 1048576 * 10) / 10)}</td><td class="kp-jobb">${k.peldany_db}</td>
                <td class="kp-jobb"><div class="kp-sor" style="justify-content:flex-end">${k.csatorna === 'teszt' ? `<button class="kp-gomb kp-gomb--kicsi" data-kcs="${k.id}" data-ertek="stabil">Kiadás mindenkinek</button>` : `<button class="kp-gomb kp-gomb--kicsi kp-gomb--szoveg" data-kcs="${k.id}" data-ertek="teszt">Vissza tesztre</button>`}<button class="kp-gomb kp-gomb--kicsi kp-gomb--veszely" data-ktorol="${k.id}" data-ketlepcsos="Törlöd?">Törlés</button></div></td></tr>`).join('')}
                </tbody></table></div>` : `<div style="padding:0 20px 20px">${ures('Még nincs kiadás.', 'kiadas')}</div>`}</section>`;
        const fe = $('[data-feltolto]'), fi = $('[data-fajl]'), fg = $('[data-feltolt]');
        let fajl = null;
        const valaszt = (f) => { fajl = f; $('[data-fajlnev]').textContent = f ? f.name + ' (' + mb(Math.round(f.size / 104857.6) / 10) + ')' : 'Húzd ide a zipet, vagy kattints'; fg.disabled = !f; };
        fi.addEventListener('change', () => valaszt(fi.files[0] || null));
        ['dragenter', 'dragover'].forEach((t) => fe.addEventListener(t, (ev) => { ev.preventDefault(); fe.classList.add('is-rajta'); }));
        ['dragleave', 'drop'].forEach((t) => fe.addEventListener(t, (ev) => { ev.preventDefault(); fe.classList.remove('is-rajta'); }));
        fe.addEventListener('drop', (ev) => valaszt(ev.dataTransfer.files[0] || null));
        fg.addEventListener('click', async () => {
            const d = urlapAdat(tartalom());
            const f = new FormData();
            f.append('zip', fajl); f.append('csatorna', d.csatorna || 'stabil'); f.append('megjegyzes', d.megjegyzes || '');
            fg.disabled = true; fg.textContent = 'Feltöltés…';
            try { await api('kiadas', { method: 'POST', form: f }); jelez('Kiadva. A rendszerek a következő jelentkezéskor látják.'); kiadasok(); } catch (x) { jelez(x.message, 'hiba'); fg.disabled = false; fg.textContent = 'Feltöltés és kiadás'; }
        });
        $$('[data-kcs]').forEach((b) => b.addEventListener('click', async () => { await api('kiadas/' + b.dataset.kcs, { method: 'POST', body: { csatorna: b.dataset.ertek } }); jelez('Elmentve.'); kiadasok(true); }));
        $$('[data-ktorol]').forEach((b) => b.addEventListener('click', async () => { if (b.classList.contains('is-elesitve')) return; await api('kiadas/' + b.dataset.ktorol, { method: 'POST', body: { torol: 1 } }); jelez('Kiadás törölve.'); kiadasok(true); }));
    }

    /* ================================================================== Támogatás */

    async function tamogatas(csendes) {
        cim('Támogatás');
        if (!csendes) betolt();
        let a;
        try { a = await api('tamogatas'); } catch (x) { if (!csendes) hibaOldal(x); return; }
        if (S.oldal !== 'tamogatas') return;
        const el = a.linkek.filter((t) => t.el);
        tartalom().innerHTML = `
            <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Érvényes belépések</h3><span class="kp-kartya__al">a vevő engedélyezte, időkorláttal – lejáratkor a vevőnél a fiók törlődik</span></div>
                ${el.length ? `<div class="kp-rendszerek">${el.map((t) => `<div class="kp-rk" style="cursor:default"><div class="kp-rk__fej">${pont('online', true)}<div style="min-width:0;flex:1"><div class="kp-rk__nev">${e(t.cimke || t.vevo)}</div><div class="kp-rk__vevo">engedélyezte: ${e(t.ki)}</div></div></div>
                    <div class="kp-halvany kp-kicsi">lejár ${datumIdo(t.lejar)} · ${relativ(t.lejar)}</div><div class="kp-sor"><a class="kp-gomb kp-gomb--fo" href="${e(t.link)}" target="_blank" rel="noopener noreferrer" style="flex:1">${I('tamogatas_keres')}Belépés</a>${t.peldany_id ? `<a class="kp-gomb" href="#/rendszer/${t.peldany_id}">${I('rendszer')}</a>` : ''}</div></div>`).join('')}</div>` : ures('Most nincs érvényes belépő link. Egy rendszer adatlapján (Támogatás fül) kérhetsz hozzáférést.', 'tamogatas')}</section>
            <div class="kp-racs kp-racs--fele">
                <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Kérések</h3></div>
                    ${a.kerelmek.length ? `<div class="kp-lista">${a.kerelmek.map((k) => `<div class="kp-lista__elem is-katt" data-nav="#/rendszer/${k.peldany_id}"><div class="kp-lista__test"><div class="kp-lista__fo">${e(k.cimke || k.vevo)} <span class="kp-cimke kp-cimke--${MUVELET_ALLAPOT[k.allapot][1]}">${MUVELET_ALLAPOT[k.allapot][0]}</span></div><div class="kp-lista__al">${datumIdo(k.letrehozva)}${k.parameter.indok ? ' · ' + e(k.parameter.indok) : ''}${k.eredmeny && k.eredmeny.uzenet ? '<br>' + e(k.eredmeny.uzenet) : ''}</div></div></div>`).join('')}</div>` : ures('Még nem kértél hozzáférést.')}</section>
                <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Előzmények</h3></div>
                    ${a.linkek.length ? `<div class="kp-lista">${a.linkek.map((t) => `<div class="kp-lista__elem"><div class="kp-lista__test"><div class="kp-lista__fo">${e(t.cimke || t.vevo)}</div><div class="kp-lista__al">${e(t.ki)} · ${datumIdo(t.erkezett)} → ${datumIdo(t.lejar)}</div></div><span class="kp-cimke kp-cimke--${t.el ? 'ok' : 'szurke'}">${t.el ? 'érvényes' : 'lejárt'}</span></div>`).join('')}</div>` : ures('Még nem volt.')}</section>
            </div>`;
        ismetel(tamogatas, 20);
    }

    /* ================================================================== Napló */

    const NAPLO_TIPUS = { '': 'Minden típus', riasztas: 'Riasztás', licenc: 'Licenc', peldany: 'Rendszer', muvelet: 'Távoli művelet', tamogatas: 'Támogatás', kiadas: 'Kiadás', vevo: 'Vevő', jegyzet: 'Jegyzet', rendszer: 'Központ' };

    async function naplo(csendes) {
        cim('Napló');
        const f = S.nf || { tipus: '', szint: '', q: '' };
        if (!csendes) {
            tartalom().innerHTML = `<div class="kp-szuro">${select('tipus', NAPLO_TIPUS, f.tipus)}${szegmens('szint', { '': 'Mind', hiba: 'Hiba', figyel: 'Figyelmeztetés', ok: 'Rendben', info: 'Infó' }, f.szint)}<input class="kp-input" name="q" placeholder="Keresés a szövegben…" value="${e(f.q)}"></div><section class="kp-kartya" data-nlista><div class="kp-csontvaz" style="height:300px"></div></section>`;
            const fris = () => { S.nf = { tipus: $('[name="tipus"]').value, szint: $('.kp-szegmens .is-aktiv').dataset.ertek, q: $('[name="q"]').value }; naplo(true); };
            $('[name="tipus"]').addEventListener('change', fris);
            $('[data-szegmens="szint"]').addEventListener('valtozas', fris);
            $('[name="q"]').addEventListener('input', () => { clearTimeout(S.nqi); S.nqi = setTimeout(fris, 250); });
        }
        try {
            const a = await api('naplo', { query: f });
            if (S.oldal !== 'naplo') return;
            const hova = $('[data-nlista]');
            hova.innerHTML = idovonal(a.lista) + (a.lista.length >= 80 ? '<div class="kp-sor" style="justify-content:center;margin-top:10px"><button class="kp-gomb" data-tobb>Régebbiek</button></div>' : '');
            const tobb = (utolso) => { const g = $('[data-tobb]', hova); if (!g) return; g.addEventListener('click', async () => { const b = await api('naplo', { query: { ...f, elotte: utolso } }); g.parentElement.remove(); hova.insertAdjacentHTML('beforeend', idovonal(b.lista) + (b.lista.length >= 80 ? '<div class="kp-sor" style="justify-content:center;margin-top:10px"><button class="kp-gomb" data-tobb>Régebbiek</button></div>' : '')); if (b.lista.length) tobb(b.lista[b.lista.length - 1].id); }); };
            if (a.lista.length) tobb(a.lista[a.lista.length - 1].id);
        } catch (x) { hibaOldal(x); }
    }

    /* ================================================================== Beállítások */

    async function beallitasok() {
        cim('Beállítások');
        betolt();
        let a;
        try { a = await api('beallitas'); } catch (x) { hibaOldal(x); return; }
        const b = a.b;
        const szamMezo = (nev, cimke, sugo = '') => mezo(cimke, input(nev, b[nev], 'inputmode="numeric"'), sugo);
        tartalom().innerHTML = `
            <div class="kp-racs kp-racs--fele">
                <section class="kp-kartya" data-b="altalanos"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Általános</h3></div>
                    <div class="kp-problemak">${kapcsolosor('belepeskor', b.belepeskor, 'Belépéskor a Központ nyíljon meg', 'A WordPress vezérlőpult helyett; a „WordPress admin” gombbal mehetsz tovább.')}
                    ${kapcsolosor('email_riasztas', b.email_riasztas, 'E-mail riasztás', 'Új hibánál, álló rendszernél és támogatási linknél.')}</div>
                    <div class="kp-mezok" style="margin-top:14px">${mezo('Riasztás ide', input('email_cim', b.email_cim, `inputmode="email" placeholder="${e(a.admin_email)}"`), 'Üresen a webhely adminisztrátori címére megy.', 'kp-mezo--szeles')}</div>
                    <div class="kp-sor" style="justify-content:flex-end;margin-top:14px"><button class="kp-gomb kp-gomb--fo" data-bment="altalanos">Mentés</button></div></section>
                <section class="kp-kartya" data-b="kuszob"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Riasztási küszöbök</h3></div>
                    <div class="kp-mezok kp-mezok--3">${szamMezo('offline_perc', 'Nem jelentkezik (perc)', 'Ennyi csend után „áll”.')}${szamMezo('valasz_figyel', 'Lassú (ms)', 'Átlagos válaszidő.')}${szamMezo('valasz_hiba', 'Nagyon lassú (ms)')}${szamMezo('mentes_ora', 'Mentés kora (óra)', 'Háromszorosánál hiba.')}${szamMezo('lemez_gb', 'Kevés tárhely (GB)')}${szamMezo('meres_nap', 'Mérések megőrzése (nap)')}</div>
                    <div class="kp-sor" style="justify-content:flex-end;margin-top:14px"><button class="kp-gomb kp-gomb--fo" data-bment="kuszob">Mentés</button></div></section>
            </div>
            <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Csomagok</h3><span class="kp-kartya__al">sablonok új licenchez: modulok és korlátok (az árat a licencnél adod meg)</span><span class="kp-ter"></span><button class="kp-gomb kp-gomb--kicsi" data-csomag-uj>${I('plusz')}Új csomag</button></div>
                <div class="kp-rendszerek">${b.csomagok.map((c, i) => `<div class="kp-rk" data-csomag="${i}"><div class="kp-rk__fej"><span class="kp-hos__orb" style="width:34px;height:34px;border-radius:11px">${I('modul')}</span><div style="flex:1;min-width:0"><div class="kp-rk__nev">${e(c.nev)}</div><div class="kp-rk__vevo">${c.modulok === '*' ? 'minden modul' : c.modulok.length + ' modul'} · ${c.max_peldany} gép · ${c.max_felhasznalo || 'korlátlan'} felhasználó</div></div></div></div>`).join('')}</div></section>
            <section class="kp-kartya"><div class="kp-kartya__fej"><h3 class="kp-kartya__cim">Csatlakozás</h3><span class="kp-kartya__al">ezt adod meg a vevőnél: Beállítások › Licenc</span></div>
                <dl class="kp-kv"><dt>A központ címe</dt><dd><span class="kp-licenckulcs" data-masol="${e(a.cim)}">${e(a.cim)}</span></dd><dt>Aláíró kulcs</dt><dd><span class="kp-licenckulcs" data-masol="${e(a.kulcs)}" style="word-break:break-all">${e(a.kulcs)}</span></dd><dt>Központ verzió</dt><dd>${e(a.verzio)}</dd></dl>
                <p class="kp-halvany kp-kicsi" style="margin:12px 0 0">A vevő rendszere az első kapcsolatkor megjegyzi az aláíró kulcsot, utána csak ezzel aláírt választ fogad el. A vevőtől műszaki adat és darabszám jön; ügyfeleinek személyes adata és a forgalom összege nem.</p></section>`;
        $$('[data-masol]').forEach((x) => x.addEventListener('click', () => masol(x.dataset.masol)));
        $$('[data-bment]').forEach((g) => g.addEventListener('click', async () => { await api('beallitas', { method: 'POST', body: urlapAdat($(`[data-b="${g.dataset.bment}"]`)) }); jelez('Beállítások elmentve.'); }));
        const csomagPopup = (i) => {
            const c = i === null ? { nev: '', modulok: '*', max_peldany: 1, max_felhasznalo: 0 } : b.csomagok[i];
            const minden = c.modulok === '*';
            const pp = popup({
                cim: i === null ? 'Új csomag' : 'Csomag: ' + c.nev, meret: 'szeles',
                test: `<div class="kp-mezok kp-mezok--3">${mezo('Név', input('nev', c.nev))}${mezo('Gépek száma', input('max_peldany', c.max_peldany, 'inputmode="numeric"'))}${mezo('Felhasználók (0 = korlátlan)', input('max_felhasznalo', c.max_felhasznalo, 'inputmode="numeric"'))}</div>
                    <div class="kp-sor kp-sor--kozte"><b>Modulok</b><div class="kp-sor"><span class="kp-halvany kp-kicsi">minden modul</span>${kapcsolo('minden', minden, 'Minden modul', 'data-minden')}</div></div>
                    <div class="kp-kapcsolok" data-modulok>${Object.entries(a.katalogus).map(([k, m]) => kapcsolosor('mod_' + k, minden || c.modulok.includes(k), m.cim, m.csoport, minden ? 'disabled' : '')).join('')}</div>`,
                lab: `${i === null ? '' : '<button class="kp-gomb kp-gomb--veszely" data-torol data-ketlepcsos="Törlöd a csomagot?">Törlés</button>'}<span class="kp-ter"></span><button class="kp-gomb" data-bezar>Mégse</button><button class="kp-gomb kp-gomb--fo" data-ment>Mentés</button>`,
            });
            $('[data-minden]', pp.el).addEventListener('change', (ev) => $$('[data-modulok] input', pp.el).forEach((x) => { x.disabled = ev.target.checked; if (ev.target.checked) x.checked = true; }));
            const ment = async (lista) => { await api('beallitas', { method: 'POST', body: { csomagok: lista } }); pp.bezar(); jelez('Csomagok elmentve.'); beallitasok(); };
            $('[data-ment]', pp.el).addEventListener('click', () => {
                const d = urlapAdat(pp.el);
                const uj = { kulcs: c.kulcs || '', nev: d.nev, max_peldany: d.max_peldany, max_felhasznalo: d.max_felhasznalo, modulok: d.minden ? '*' : Object.keys(a.katalogus).filter((k) => d['mod_' + k]) };
                const lista = b.csomagok.slice();
                if (i === null) lista.push(uj); else lista[i] = uj;
                ment(lista);
            });
            $('[data-torol]', pp.el)?.addEventListener('click', (ev) => { if (ev.currentTarget.classList.contains('is-elesitve')) return; ment(b.csomagok.filter((_, j) => j !== i)); });
        };
        $('[data-csomag-uj]').addEventListener('click', () => csomagPopup(null));
        $$('[data-csomag]').forEach((x) => x.addEventListener('click', () => csomagPopup(Number(x.dataset.csomag))));
    }

    /* ------------------------------------------------------------------ Parancspaletta (Ctrl+K) */

    function paletta() {
        if ($('.kp-paletta')) return;
        const parancsok = [
            ['Új licenc', 'licenc', () => licencPopup(null, () => route())],
            ['Új vevő', 'vevo', () => vevoPopup(null, (id) => { location.hash = '#/vevo/' + id; })],
            ['Kiadás feltöltése', 'kiadas', () => { location.hash = '#/kiadasok'; }],
            ...MENU.map(([ut, ik, nev]) => [nev, ik, () => { location.hash = '#/' + ut; }]),
            ['WordPress admin', 'wp', () => { location.href = B.wp; }],
            ['Világos / sötét téma', 'nap', temaValt],
        ];
        const hatter = document.createElement('div');
        hatter.className = 'kp-popup-hatter';
        hatter.innerHTML = `<div class="kp-popup kp-paletta" role="dialog" aria-label="Keresés"><div class="kp-paletta__mezo">${I('kereses')}<input placeholder="Vevő, licenckulcs, rendszer vagy parancs…" aria-label="Keresés"><kbd class="kp-cimke kp-cimke--mono">Esc</kbd></div><div class="kp-paletta__lista"></div></div>`;
        document.body.appendChild(hatter);
        const inp = $('input', hatter), lista = $('.kp-paletta__lista', hatter);
        let elemek = [], aktiv = 0, kesl;
        const bezar = () => hatter.remove();
        const rajzol = (talalat = []) => {
            const q = inp.value.trim().toLowerCase();
            const p = parancsok.filter(([n]) => !q || n.toLowerCase().includes(q)).map(([n, ik, fn]) => ({ cim: n, ik, fn, csop: 'Parancsok' }));
            const t = talalat.map((x) => ({ cim: x.cim, al: x.al, ik: x.tipus === 'vevo' ? 'vevo' : x.tipus === 'licenc' ? 'licenc' : 'rendszer', csop: { vevo: 'Vevők', licenc: 'Licencek', peldany: 'Rendszerek' }[x.tipus], fn: () => { location.hash = x.tipus === 'vevo' ? '#/vevo/' + x.id : x.tipus === 'licenc' ? '#/vevo/' + x.vevo_id : '#/rendszer/' + x.id; } }));
            elemek = [...t, ...p];
            aktiv = Math.min(aktiv, Math.max(0, elemek.length - 1));
            let csop = '';
            lista.innerHTML = elemek.map((x, i) => { const fej = x.csop !== csop ? `<div class="kp-paletta__csoport">${e(x.csop)}</div>` : ''; csop = x.csop; return `${fej}<div class="kp-paletta__elem ${i === aktiv ? 'is-aktiv' : ''}" data-i="${i}">${I(x.ik)}<span>${e(x.cim)}</span>${x.al ? `<small>${e(x.al)}</small>` : ''}</div>`; }).join('') || ures('Nincs találat.');
        };
        const futtat = (i) => { const x = elemek[i]; if (!x) return; bezar(); x.fn(); };
        inp.addEventListener('input', () => { aktiv = 0; rajzol(); clearTimeout(kesl); if (inp.value.trim().length >= 2) kesl = setTimeout(async () => { try { const r = await api('kereses', { query: { q: inp.value.trim() } }); rajzol(r.lista); } catch (x) { /* csendben */ } }, 180); });
        inp.addEventListener('keydown', (ev) => {
            if (ev.key === 'ArrowDown') { aktiv = Math.min(elemek.length - 1, aktiv + 1); rajzol(); ev.preventDefault(); }
            if (ev.key === 'ArrowUp') { aktiv = Math.max(0, aktiv - 1); rajzol(); ev.preventDefault(); }
            if (ev.key === 'Enter') futtat(aktiv);
            if (ev.key === 'Escape') bezar();
        });
        lista.addEventListener('click', (ev) => { const x = ev.target.closest('[data-i]'); if (x) futtat(Number(x.dataset.i)); });
        hatter.addEventListener('mousedown', (ev) => { if (ev.target === hatter) bezar(); });
        rajzol();
        setTimeout(() => inp.focus(), 30);
    }

    document.addEventListener('keydown', (ev) => {
        if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') { ev.preventDefault(); paletta(); }
        if (ev.key === '/' && !ev.target.closest('input, textarea, select') && !$('.kp-popup-hatter')) { ev.preventDefault(); paletta(); }
    });

    /* ------------------------------------------------------------------ Indulás */

    vaz();
    route();
    api('attekintes').then(menuJelek).catch(() => {});
    setInterval(() => { if (!document.hidden && S.oldal !== '') api('attekintes').then(menuJelek).catch(() => {}); }, 60000);
})();
