/* Stúdió – Megjelenés: Fejléc, Lábléc, Menük (fa-szerkesztő), Feliratok (a téma minden kódba írt szövege),
   beállítás-csoport nézetek (Süti-sáv, Szikra, Értesítések), és az Arculat skin-választója. */
(() => {
    'use strict';

    const A = window.PA;
    if (!A) return;
    const { B, S, $, $$, e, I, api, jelez, bemond, tolt, urlap, kapcsolo, szegmens, kesleltet, rendezheto, ures, cimke, lapozo } = A;

    const MELYSEG = 3;

    /* ================================================================== Menü fa-szerkesztő (beágyazható) */

    /**
     * hova: konténer, hely: 'fo' | 'lablec' | 'jogi'. opciok.cim / opciok.sugo: a kártya fejléce.
     * Vissza: { mentetlen() } – a panel a navigációs védelemhez.
     */
    async function menuSzerkeszto(hova, hely, opciok = {}) {
        const m = await api('admin/menuk');
        const h = m.helyek[hely];
        if (!h) { hova.innerHTML = ures('Ismeretlen menühely.', 'menu'); return { mentetlen: () => false }; }
        const lapit = (elemek, m0 = 0, ki = []) => { (elemek || []).forEach((x) => { ki.push({ cim: x.cim || '', cel: x.oldal ? x.oldal : (x.url || ''), uj: !!x.uj, m: m0 }); lapit(x.gyerekek, m0 + 1, ki); }); return ki; };
        const fa = (l) => {
            const gy = [], verem = [{ m: -1, gyerekek: gy }];
            l.forEach((x) => {
                const cel = x.cel.trim();
                const elem = { cim: x.cim.trim() };
                if (cel && !/^(\/|#|https?:|mailto:|tel:)/i.test(cel)) elem.oldal = cel.replace(/^\/|\/$/g, ''); else elem.url = cel;
                if (x.uj) elem.uj = true;
                while (verem.length > 1 && verem[verem.length - 1].m >= x.m) verem.pop();
                const sz = verem[verem.length - 1];
                (sz.gyerekek = sz.gyerekek || []).push(elem);
                verem.push(Object.assign(elem, { m: x.m }));
            });
            const tisztit = (a) => a.map(({ m: _m, ...r }) => (r.gyerekek ? Object.assign(r, { gyerekek: tisztit(r.gyerekek) }) : r));
            return tisztit(gy);
        };
        let L = lapit(h.elemek);
        let mentett = JSON.stringify(L);
        // Becsukott ágak (csak a nézethez – nem mentődik). Hosszú menünél alapból minden ág csukva.
        const zart = new WeakSet();
        const vanGyerek = (i) => i + 1 < L.length && L[i + 1].m > L[i].m;
        if (L.length > 12) L.forEach((x, i) => { if (vanGyerek(i)) zart.add(x); });
        const rejtett = (i) => { let m0 = L[i].m; for (let j = i - 1; j >= 0 && m0 > 0; j--) { if (L[j].m < m0) { if (zart.has(L[j])) return true; m0 = L[j].m; } } return false; };
        const mentetlen = () => JSON.stringify(L) !== mentett;
        const n = hely + Math.random().toString(36).slice(2, 6);
        hova.innerHTML = `<div class="sa-kartya"><div class="sa-kartya__fej"><div><h2 class="sa-kartya__cim">${e(opciok.cim || h.cim)}</h2>${opciok.sugo ? `<div class="sa-kartya__al">${e(opciok.sugo)}</div>` : ''}</div><span class="sa-ter"></span><span data-allapot aria-live="polite"></span></div>
            <ol class="sa-fa" data-fa aria-label="${e(h.cim)} – menüpontok"></ol>
            <div class="sa-sor sa-sor--tor" style="margin-top:12px"><button type="button" class="sa-gomb" data-uj>${I('plusz')}Új menüpont</button><span class="sa-ter"></span><button type="button" class="sa-gomb" data-elvet data-ketlepcsos="Biztos? Elvetem">Elvetés</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button></div>
            <p class="sa-kicsi sa-halvany" style="margin:10px 0 0">A cél egy oldal útvonala (pl. <code>arak</code>) vagy cím (<code>/kosar/</code>, <code>https://…</code>, <code>#gyik</code>). A ← → gomb alá- és fölérendel (legfeljebb ${MELYSEG} szint). Tokenek a feliratban: {termeknev}.</p></div>`;
        const faEl = $('[data-fa]', hova);
        const jel = () => { const p = mentetlen(); $('[data-allapot]', hova).innerHTML = p ? cimke('Mentetlen', 'figyel') : cimke('Mentve', 'ok'); $('[data-ment]', hova).disabled = !p; $('[data-elvet]', hova).disabled = !p; };
        const atvesz = () => { $$('.sa-fa__sor', faEl).forEach((s, i) => { L[i].cim = $('[data-cim]', s).value; L[i].cel = $('[data-cel]', s).value; L[i].uj = $('[data-ujlap]', s).checked; }); };
        const rajzol = (fokusz = null) => {
            faEl.innerHTML = L.map((x, i) => `<li class="sa-fa__sor st-fa__sor" data-i="${i}" style="--m:${x.m}" ${rejtett(i) ? 'hidden' : ''}>
                ${vanGyerek(i) ? `<button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi st-fa__ag" data-ag aria-expanded="${!zart.has(x)}" aria-label="${e(x.cim || 'Menüpont')} – almenü ${zart.has(x) ? 'kinyitása' : 'becsukása'}">${I(zart.has(x) ? 'jobbra' : 'le')}</button>` : '<span class="st-fa__ag" aria-hidden="true"></span>'}
                <button type="button" class="sa-szelem__fogo" data-fogo aria-label="${e(x.cim || 'Menüpont')} áthelyezése – ↑ ↓">${I('fogo')}</button>
                <input class="sa-input sa-input--kicsi" data-cim value="${e(x.cim)}" placeholder="Felirat" aria-label="${i + 1}. menüpont felirata (${x.m + 1}. szint)" maxlength="80">
                <span class="sa-mezo__sor st-fa__cel"><input class="sa-input sa-input--kicsi sa-input--mono" data-cel value="${e(x.cel)}" placeholder="oldal-útvonal vagy /cím" aria-label="${i + 1}. menüpont célja"><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-ov aria-label="${i + 1}. menüpont – oldal választása" title="Oldal választása">${I('oldal')}</button></span>
                <span class="sa-sor" style="gap:2px">${kapcsolo('st-mu-' + n + '-' + i, x.uj, `${i + 1}. menüpont – új lapon nyílik`, 'data-ujlap')}<span class="sa-kicsi sa-halvany" aria-hidden="true">új lap</span></span>
                <span class="sa-fa__gombok"><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-ki aria-label="Kijjebb" ${x.m === 0 ? 'disabled' : ''}>${I('balra')}</button><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-be aria-label="Beljebb" ${i === 0 || x.m >= MELYSEG - 1 || x.m > L[i - 1].m ? 'disabled' : ''}>${I('jobbra')}</button><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi sa-ikon-gomb--veszely" data-torol aria-label="Törlés" data-ketlepcsos="Biztos? Katt újra">${I('torol')}</button></span></li>`).join('') || `<li>${ures('A menü üres.', 'menu')}</li>`;
            jel();
            if (fokusz) { const li = faEl.children[fokusz[0]]; const g = li && $(fokusz[1], li); if (g && !g.disabled) g.focus(); else if (li) $('[data-fogo]', li).focus(); }
        };
        const ag = (i) => { let j = i + 1; while (j < L.length && L[j].m > L[i].m) j++; return j - i; };
        faEl.addEventListener('input', () => { atvesz(); jel(); });
        faEl.addEventListener('change', () => { atvesz(); jel(); });
        faEl.addEventListener('click', (ev) => {
            const g = ev.target.closest('button');
            const li = ev.target.closest('[data-i]');
            if (!g || !li || g.hasAttribute('data-fogo')) return;
            atvesz();
            const i = Number(li.dataset.i), nn = ag(i);
            if (g.hasAttribute('data-ag')) { if (zart.has(L[i])) zart.delete(L[i]); else zart.add(L[i]); rajzol([i, '[data-ag]']); return; }
            if (g.hasAttribute('data-ov')) { A.oldalValaszto(g, (u) => { L[i].cel = u.replace(/^\/|\/$/g, ''); rajzol([i, '[data-cel]']); }); return; }
            if (g.hasAttribute('data-ki')) { for (let k = i; k < i + nn; k++) L[k].m = Math.max(0, L[k].m - 1); rajzol([i, '[data-ki]']); bemond('Kijjebb'); }
            if (g.hasAttribute('data-be')) { for (let k = i; k < i + nn; k++) L[k].m = Math.min(MELYSEG - 1, L[k].m + 1); rajzol([i, '[data-be]']); bemond('Beljebb'); }
            if (g.hasAttribute('data-torol')) { L.splice(i, nn); rajzol(L.length ? [Math.max(0, i - 1), '[data-cim]'] : null); bemond('Menüpont törölve'); }
        });
        rendezheto(faEl, '.sa-fa__sor', '[data-fogo]', (regi, uj) => {
            atvesz();
            const nn = ag(regi);
            const blokk = L.splice(regi, nn);
            let cel = uj > regi ? uj - nn + 1 : uj;
            cel = Math.max(0, Math.min(L.length, cel));
            const elozoM = cel > 0 ? L[cel - 1].m : -1;
            const elt = blokk[0].m - Math.min(blokk[0].m, elozoM + 1);
            blokk.forEach((x) => { x.m = Math.max(0, x.m - elt); });
            L.splice(cel, 0, ...blokk);
            rajzol([cel, '[data-fogo]']);
            bemond(`Áthelyezve: ${cel + 1}. hely`);
        });
        $('[data-uj]', hova).addEventListener('click', () => { atvesz(); L.push({ cim: '', cel: '', uj: false, m: 0 }); rajzol([L.length - 1, '[data-cim]']); });
        $('[data-elvet]', hova).addEventListener('click', () => { L = JSON.parse(mentett); rajzol(); });
        $('[data-ment]', hova).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            atvesz();
            if (L.some((x) => !x.cim.trim())) { jelez('Minden menüpontnak kell felirat.', 'hiba'); const i = L.findIndex((x) => !x.cim.trim()); $('[data-cim]', faEl.children[i]).focus(); return; }
            try {
                const r = await api('admin/menu/' + hely, { method: 'POST', body: { elemek: fa(L) } });
                L = lapit(r.elemek); mentett = JSON.stringify(L);
                rajzol();
                jelez(`${h.cim} mentve.`);
            } catch (x) { jelez(x.message, 'hiba'); }
        }));
        rajzol();
        return { mentetlen };
    }
    A.menuSzerkeszto = menuSzerkeszto;

    A.panel('menuk', async (hova, [helyParam]) => {
        const helyek = B.helyek || { fo: 'Főmenü', lablec: 'Lábléc', jogi: 'Jogi sáv' };
        const f = S.szurok.menuk = Object.assign({ hely: 'fo' }, S.szurok.menuk || {});
        if (helyParam && helyek[helyParam]) f.hely = helyParam;
        hova.innerHTML = `<div class="sa-eszkozsor">${szegmens('Menühely', Object.fromEntries(Object.entries(helyek).map(([k, v]) => [k, e(v)])), f.hely)}</div><div data-szerk></div>`;
        let sz = await menuSzerkeszto($('[data-szerk]', hova), f.hely);
        S.mentetlen = () => sz.mentetlen();
        $('[data-szegmens]', hova).addEventListener('valtozas', async (ev) => {
            if (sz.mentetlen()) { jelez('Előbb mentsd vagy vesd el a változásokat.', 'figyel'); $$('[data-szegmens] button', hova).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.ertek === f.hely))); return; }
            f.hely = ev.detail;
            sz = await menuSzerkeszto($('[data-szerk]', hova), f.hely);
        });
    });

    /* ================================================================== Kis beállítás-űrlap (megadott kulcsokkal) */

    async function beallitasKartya(hova, kulcsok, cim2, sugo = '') {
        const r = await api('admin/beallitasok');
        const mezok = Object.values(r.csoportok).flatMap((c) => c.mezok).filter((m, i, a) => kulcsok.includes(m.k) && a.findIndex((x) => x.k === m.k) === i && m.t !== 'titok');
        mezok.sort((a, b) => kulcsok.indexOf(a.k) - kulcsok.indexOf(b.k));
        if (!mezok.length) { hova.innerHTML = ''; return { mentetlen: () => false }; }
        let p = false;
        const u = urlap(mezok, r.ertekek, { nyelvek: r.nyelvek, valtozas: () => { p = true; jel(); } });
        hova.innerHTML = `<div class="sa-kartya"><div class="sa-kartya__fej"><div><h2 class="sa-kartya__cim">${e(cim2)}</h2>${sugo ? `<div class="sa-kartya__al">${e(sugo)}</div>` : ''}</div><span class="sa-ter"></span><span data-allapot></span></div><div data-urlap></div><div class="sa-sor sa-sor--veg" style="margin-top:12px"><button type="button" class="sa-gomb sa-gomb--fo" data-ment disabled>${I('mentes')}Mentés</button></div></div>`;
        $('[data-urlap]', hova).appendChild(u.el);
        const jel = () => { $('[data-allapot]', hova).innerHTML = p ? cimke('Mentetlen', 'figyel') : ''; $('[data-ment]', hova).disabled = !p; };
        $('[data-ment]', hova).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            const h = u.ellenoriz();
            if (h.length) { jelez(h.join(' '), 'hiba'); return; }
            try { await api('admin/beallitasok', { method: 'POST', body: { ertekek: u.ertek() } }); p = false; jel(); jelez('Mentve.'); } catch (x) { jelez(x.message, 'hiba'); }
        }));
        return { mentetlen: () => p };
    }

    /** Logó-kártya (az arculat logói, közvetlen mentéssel). */
    async function logoKartya(hova) {
        const a = await api('admin/arculat');
        const mezo = (k, c) => `<div class="sa-mezo"><span class="sa-mezo__cim">${e(c)}</span><div class="st-logo-elo ${k === 'logo_vilagos' ? 'st-logo-elo--vilagos' : 'st-logo-elo--sotet'}" data-elo="${k}">${a[k] ? `<img src="${e(a[k])}" alt="">` : `<span class="sa-kicsi">Nincs – a terméknév jelenik meg</span>`}</div><div class="sa-sor" style="margin-top:6px"><button type="button" class="sa-gomb sa-gomb--kicsi" data-logo="${k}">${I('kep')}Médiatár</button><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--szoveg" data-logo-torol="${k}">Eltávolítás</button></div></div>`;
        hova.innerHTML = `<div class="sa-kartya"><div class="sa-kartya__fej"><div><h2 class="sa-kartya__cim">Logó</h2><div class="sa-kartya__al">A fejlécben és a láblécben; a világos változat a világos módhoz. Választás után azonnal él.</div></div></div><div class="sa-mezok">${mezo('logo', 'Logó sötét háttérre')}${mezo('logo_vilagos', 'Logó világos háttérre')}</div></div>`;
        const allit = async (k, url) => {
            try {
                const r = await api('admin/arculat', { method: 'POST', body: { [k]: url } });
                $(`[data-elo="${k}"]`, hova).innerHTML = r[k] ? `<img src="${e(r[k])}" alt="">` : `<span class="sa-kicsi">Nincs – a terméknév jelenik meg</span>`;
                jelez('Logó mentve.');
            } catch (x) { jelez(x.message, 'hiba'); }
        };
        hova.addEventListener('click', async (ev) => {
            const g = ev.target.closest('[data-logo]');
            if (g) { const x = await A.mediaValaszt({ cim: 'Logó kiválasztása' }); if (x) allit(g.dataset.logo, x.url); return; }
            const t = ev.target.closest('[data-logo-torol]');
            if (t) allit(t.dataset.logoTorol, '');
        });
    }

    /* ================================================================== Feliratok (szótár) */

    async function feliratKartya(hova, csak = null, opciok = {}) {
        const r = await api('admin/feliratok');
        if (!r.van_jegyzek && !r.csoportok.length) {
            hova.innerHTML = `<div class="sa-kartya">${ures('A téma még nem adott felirat-jegyzéket. Ha a téma frissül, itt jelenik meg a felület összes szövege.', 'felirat', 'Nincs felirat-jegyzék')}</div>`;
            return { mentetlen: () => false };
        }
        const cs = r.csoportok.filter((c) => !csak || csak.includes(c.cim));
        const f = { csop: opciok.csop || (cs[0] || {}).cim || '', q: '', csakFelulirt: false, oldal: 1 };
        const valt = {};
        const LAP = 20;
        hova.innerHTML = `<div class="${cs.length > 1 && !csak ? 'sa-ketpanel' : ''}">
            ${cs.length > 1 && !csak ? `<nav class="sa-kartya sa-almenu" aria-label="Felirat-csoportok">${cs.map((c) => `<button type="button" data-fcsop="${e(c.cim)}" aria-current="${c.cim === f.csop}">${I('felirat')}<span>${e(c.cim)}</span><small>${c.elemek.length}</small></button>`).join('')}</nav>` : ''}
            <div class="sa-oszlop"><div class="sa-kartya"><div class="sa-kartya__fej"><div><h2 class="sa-kartya__cim" data-fcim>${e(opciok.cim || 'Feliratok')}</h2><div class="sa-kartya__al">Üresen hagyva az alapszöveg él. Tokenek: {termeknev}, {ceg}.</div></div><span class="sa-ter"></span><span data-allapot></span></div>
                <div class="sa-sor sa-sor--tor" style="margin-bottom:10px"><input class="sa-input" type="search" data-fq placeholder="Keresés a feliratokban…" aria-label="Keresés a feliratokban" style="flex:1;min-width:180px"><div class="sa-kapcsolosor"><label class="sa-kapcsolosor__szoveg" for="st-fcsak">Csak a módosítottak</label>${kapcsolo('st-fcsak', false, 'Csak a módosítottak')}</div></div>
                <div data-flista></div>
                <div class="sa-sor sa-sor--veg" style="margin-top:12px"><button type="button" class="sa-gomb" data-felvet data-ketlepcsos="Biztos? Elvetem" disabled>Elvetés</button><button type="button" class="sa-gomb sa-gomb--fo" data-fment disabled>${I('mentes')}Mentés</button></div></div></div></div>`;
        const flista = $('[data-flista]', hova);
        const minden = () => (csak || f.q ? cs : cs.filter((c) => c.cim === f.csop)).flatMap((c) => c.elemek.map((x) => Object.assign({ csoport: c.cim }, x)));
        const ertekOf = (x) => (valt[x.kulcs] !== undefined ? valt[x.kulcs] : x.ertek);
        const jel = () => { const p = Object.keys(valt).length > 0; $('[data-allapot]', hova).innerHTML = p ? cimke(`${Object.keys(valt).length} mentetlen`, 'figyel') : ''; $('[data-fment]', hova).disabled = !p; $('[data-felvet]', hova).disabled = !p; };
        const rajzol = () => {
            const q = f.q.toLowerCase();
            const l = minden().filter((x) => (!q || (x.kulcs + ' ' + x.alap + ' ' + ertekOf(x) + ' ' + x.leiras).toLowerCase().includes(q)) && (!f.csakFelulirt || ertekOf(x)));
            const max = Math.max(1, Math.ceil(l.length / LAP));
            f.oldal = Math.min(f.oldal, max);
            const lap = l.slice((f.oldal - 1) * LAP, f.oldal * LAP);
            flista.innerHTML = lap.length ? `<div class="st-feliratok">${lap.map((x) => `<div class="st-felirat ${ertekOf(x) ? 'is-felulirt' : ''}" data-fk="${e(x.kulcs)}"><label class="st-felirat__cim" for="st-f-${e(x.kulcs)}"><span>${e(x.leiras || x.alap || x.kulcs)}</span><small class="sa-mono sa-halvany">${e(x.kulcs)}${f.q && !csak ? ' · ' + e(x.csoport) : ''}</small></label><div class="sa-mezo__sor"><input class="sa-input" id="st-f-${e(x.kulcs)}" data-fe maxlength="500" value="${e(ertekOf(x))}" placeholder="${e(x.alap)}"><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-falap aria-label="${e(x.kulcs)} – alapra" title="Alapra" ${ertekOf(x) ? '' : 'disabled'}>${I('visszaall')}</button></div></div>`).join('')}</div>`
                : ures(f.q ? 'Nincs találat.' : 'Nincs módosított felirat.', 'felirat');
            if (l.length > LAP) flista.appendChild(lapozo(l.length, f.oldal, LAP, (n) => { f.oldal = n; rajzol(); }));
            jel();
        };
        const eredeti = (k) => (minden().concat(cs.flatMap((c) => c.elemek)).find((x) => x.kulcs === k) || {}).ertek || '';
        flista.addEventListener('input', (ev) => {
            const i = ev.target.closest('[data-fe]');
            if (!i) return;
            const k = i.closest('[data-fk]').dataset.fk;
            if (i.value === eredeti(k)) delete valt[k]; else valt[k] = i.value;
            i.closest('.st-felirat').classList.toggle('is-felulirt', !!i.value);
            $('[data-falap]', i.closest('.st-felirat')).disabled = !i.value;
            jel();
        });
        flista.addEventListener('click', (ev) => {
            const g = ev.target.closest('[data-falap]');
            if (!g) return;
            const sor = g.closest('[data-fk]');
            const i = $('[data-fe]', sor);
            i.value = '';
            i.dispatchEvent(new Event('input', { bubbles: true }));
            i.focus();
        });
        $('[data-fq]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); f.oldal = 1; rajzol(); }, 150));
        $('#st-fcsak', hova).addEventListener('change', (ev) => { f.csakFelulirt = ev.target.checked; f.oldal = 1; rajzol(); });
        hova.addEventListener('click', (ev) => {
            const g = ev.target.closest('[data-fcsop]');
            if (!g) return;
            f.csop = g.dataset.fcsop; f.oldal = 1;
            $$('[data-fcsop]', hova).forEach((x) => x.setAttribute('aria-current', String(x === g)));
            rajzol();
        });
        $('[data-felvet]', hova).addEventListener('click', () => { Object.keys(valt).forEach((k) => delete valt[k]); rajzol(); });
        $('[data-fment]', hova).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            try {
                await api('admin/feliratok', { method: 'POST', body: { ertekek: valt } });
                cs.forEach((c) => c.elemek.forEach((x) => { if (valt[x.kulcs] !== undefined) x.ertek = valt[x.kulcs]; }));
                Object.keys(valt).forEach((k) => delete valt[k]);
                rajzol();
                jelez('Feliratok mentve – a webhelyen azonnal élnek.');
            } catch (x) { jelez(x.message, 'hiba'); }
        }));
        rajzol();
        return { mentetlen: () => Object.keys(valt).length > 0 };
    }
    A.feliratKartya = feliratKartya;

    A.panel('feliratok', async (hova) => {
        const k = await feliratKartya(hova, null, { cim: 'A felület szövegei' });
        S.mentetlen = () => k.mentetlen();
    });

    /* ================================================================== Fejléc és lábléc (összetett panelek) */

    A.panel('fejlec', async (hova) => {
        hova.innerHTML = `<div class="sa-racs sa-racs--fele"><div data-logo></div><div data-bea></div></div><div data-menu></div><div data-fel></div>`;
        const r = await Promise.all([
            logoKartya($('[data-logo]', hova)),
            beallitasKartya($('[data-bea]', hova), ['termeknev', 'termeknev_rovid', 'demo.url'], 'Név és gombok', 'A logó helyén a terméknév áll, ha nincs logó; a „Demó” gomb célja.'),
            menuSzerkeszto($('[data-menu]', hova), 'fo', { cim: 'Főmenü', sugo: 'A fejléc menüje: felső szint + legördülő almenük.' }),
            feliratKartya($('[data-fel]', hova), ['Fejléc'], { cim: 'A fejléc feliratai' }),
        ]);
        S.mentetlen = () => r.some((x) => x && x.mentetlen && x.mentetlen());
    });

    A.panel('lablec', async (hova) => {
        hova.innerHTML = `<div data-oszlop></div><div class="sa-racs sa-racs--fele"><div data-jogi></div><div data-ceg></div></div><div data-fel></div>`;
        const r = await Promise.all([
            menuSzerkeszto($('[data-oszlop]', hova), 'lablec', { cim: 'Lábléc-oszlopok', sugo: 'A felső szintű pontok az oszlopok címei, alattuk a hivatkozások.' }),
            menuSzerkeszto($('[data-jogi]', hova), 'jogi', { cim: 'Jogi sáv', sugo: 'A lábléc alján: ÁSZF, adatkezelés, sütik…' }),
            beallitasKartya($('[data-ceg]', hova), ['szlogen', 'ceg.nev', 'ceg.cim', 'ceg.email', 'ceg.telefon', 'ceg.adoszam', 'ceg.cegjegyzek'], 'Cégadatok a láblécben', 'Számlán, leveleken és a jogi oldalakon is ezek jelennek meg.'),
            feliratKartya($('[data-fel]', hova), ['Lábléc'], { cim: 'A lábléc feliratai' }),
        ]);
        S.mentetlen = () => r.some((x) => x && x.mentetlen && x.mentetlen());
    });

    /* ================================================================== Beállítás-csoport nézetek (Süti-sáv, Szikra, Értesítések) */

    Object.entries(B.panelek || {}).forEach(([k, p]) => {
        if ((p.beallitas || []).length) A.panel(k, (hova, [cs]) => A.beallitasNezet(hova, cs, p.beallitas, k));
    });

    /* ================================================================== Arculat: skin-választó (ha a téma ad skineket) */

    A.skinKartya = async (hova) => {
        const a = await api('admin/arculat');
        if (!(a.skinek || []).length) return false;
        const minta = (t, ts) => {
            const v = (k, alap) => (t && t[k]) || alap;
            const s = (k, alap) => (ts && ts[k]) || v(k, alap);
            return `<span class="st-skin__minta" aria-hidden="true"><i style="background:${e(s('bg', '#0b0d14'))}"></i><i style="background:${e(s('felulet', '#151823'))}"></i><i style="background:${e(v('kiemelo', '#7c6cff'))}"></i><i style="background:${e(v('kiemelo-2', '#22d3ee'))}"></i><i style="background:${e(v('neon', v('kiemelo-2', '#22d3ee')))}"></i></span>`;
        };
        hova.innerHTML = `<div class="sa-kartya"><div class="sa-kartya__fej"><div><h2 class="sa-kartya__cim">Skin</h2><div class="sa-kartya__al">Előre hangolt színvilág – világos és sötét módban is. A finomhangolás lent, tokenenként.</div></div><span class="sa-ter"></span></div>
            <div class="st-skinek" role="radiogroup" aria-label="Skin">${a.skinek.map((s) => `<button type="button" class="st-skin" role="radio" aria-checked="${s.kulcs === a.skin}" data-skin="${e(s.kulcs)}">${minta(s.tokenek, s.tokenek_sotet)}<b>${e(s.nev)}</b><small>${e(s.leiras)}</small></button>`).join('')}</div></div>`;
        hova.addEventListener('click', (ev) => {
            const g = ev.target.closest('[data-skin]');
            if (!g || g.getAttribute('aria-checked') === 'true') return;
            tolt(g, async () => {
                try {
                    await api('admin/arculat', { method: 'POST', body: { skin: g.dataset.skin } });
                    $$('[data-skin]', hova).forEach((x) => x.setAttribute('aria-checked', String(x === g)));
                    jelez(`Skin: ${$('b', g).textContent} – a webhelyen azonnal él.`);
                } catch (x) { jelez(x.message, 'hiba'); }
            });
        });
        return true;
    };

    // Az Arculat panel elé a skin-választó kerül (a token-szerkesztő változatlanul alatta).
    const tokenSzerkeszto = A.rajzolo('arculat');
    if (tokenSzerkeszto) {
        A.panel('arculat', async (hova, param) => {
            hova.innerHTML = '<div data-skinek></div><div data-tokenek></div>';
            const sk = $('[data-skinek]', hova);
            if (!(await A.skinKartya(sk))) sk.remove();
            await tokenSzerkeszto($('[data-tokenek]', hova), param);
        });
    }
})();
