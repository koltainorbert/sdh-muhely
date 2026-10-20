/* Stúdió – tartalomtárak (csomagok, modulok, szolgáltatások, kuponok, GYIK, funkciók, iparágak, leckék, telepítés,
   konkurensek, e-mail-sablonok), blog, jogi oldalak, médiatár. Minden lista lapozott (asztalon nincs oldalgörgetés),
   a sorrend húzással állítható, az elemek popupban szerkeszthetők (minden mező), másolhatók, kukába tehetők. */
(() => {
    'use strict';

    const A = window.PA;
    if (!A) return;
    const { B, S, $, $$, e, I, WI, api, jelez, bemond, tolt, popup, popupHiba, urlap, kapcsolo, szegmens, sorKattint, kesleltet, rendezheto, ures, cim, cimke, ft, brutto, relativ, datum, datumIdo, lapozo } = A;

    const ES = B.elemek || {};
    const PENZ = (s) => (s.mezok || []).filter((m) => m.penz).map((m) => m.k).slice(0, 2);
    const LAP = 20;

    /* ================================================================== Általános tartalomtár-panel */

    const tarPanel = (kulcs) => async (hova, [tipusParam]) => {
        const p = B.panelek[kulcs] || {};
        const tipusok = (p.tipusok || []).filter((t) => ES[t]);
        if (!tipusok.length) { hova.innerHTML = A.nincsBekapcsolva(p.cim || kulcs); return; }
        const f = S.szurok[kulcs] = Object.assign({ tipus: tipusok[0], q: '', oldal: 1, kat: '' }, S.szurok[kulcs] || {});
        if (tipusParam && tipusok.includes(tipusParam)) f.tipus = tipusParam;
        if (!tipusok.includes(f.tipus)) f.tipus = tipusok[0];
        hova.innerHTML = `<div class="sa-eszkozsor">${tipusok.length > 1 ? szegmens('Elemtípus', Object.fromEntries(tipusok.map((t) => [t, `${WI(ES[t].ikon)}${e(ES[t].cim)}`])), f.tipus) : `<h2 class="sa-kartya__cim" style="margin:0">${e(ES[f.tipus].cim)} <span class="sa-halvany" data-db></span></h2>`}
            <span class="sa-ter"></span><span data-katszuro></span>
            <input class="sa-input" type="search" data-q placeholder="Keresés…" aria-label="Keresés" value="${e(f.q)}" style="max-width:260px">
            <button type="button" class="sa-gomb sa-gomb--fo" data-uj>${I('plusz')}<span data-ujcim></span></button></div>
            <div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"></div>`;
        const lista = $('[data-lista]', hova);
        let elemek = [];
        const katMezo = () => (ES[f.tipus].mezok || []).find((m) => ['kategoria', 'csoport', 'modul'].includes(m.k) && ['szoveg', 'egy'].includes(m.t));
        const szurt = () => {
            const q = f.q.toLowerCase();
            const km = katMezo();
            return elemek.filter((x) => (!q || (x.cim + ' ' + x.kulcs + ' ' + JSON.stringify(x.adat)).toLowerCase().includes(q)) && (!f.kat || !km || String(A.kiolvas(x.adat, km.k) || '') === f.kat));
        };
        const rajzol = () => {
            const s = ES[f.tipus];
            const penz = PENZ(s);
            const km = katMezo();
            const l = szurt();
            const max = Math.max(1, Math.ceil(l.length / LAP));
            f.oldal = Math.min(Math.max(1, f.oldal), max);
            const lap = l.slice((f.oldal - 1) * LAP, f.oldal * LAP);
            const rendez = !f.q && !f.kat;
            const db = $('[data-db]', hova);
            if (db) db.textContent = `(${elemek.length})`;
            $('[data-ujcim]', hova).textContent = 'Új ' + s.egyes.toLowerCase();
            if (km) {
                const ertekek = [...new Set(elemek.map((x) => String(A.kiolvas(x.adat, km.k) || '')).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'hu'));
                $('[data-katszuro]', hova).innerHTML = ertekek.length > 1 ? `<select class="sa-select" data-kat aria-label="${e(km.c)} szerint" style="width:auto;max-width:220px"><option value="">Minden ${e(km.c.toLowerCase())}</option>${ertekek.map((x) => `<option value="${e(x)}" ${x === f.kat ? 'selected' : ''}>${e(x)}</option>`).join('')}</select>` : '';
            } else $('[data-katszuro]', hova).innerHTML = '';
            lista.innerHTML = lap.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr>${rendez ? '<th class="sa-fogo-cella"><span class="sa-sr">Sorrend</span></th>' : ''}<th>${e(s.egyes)}</th>${km ? `<th>${e(km.c)}</th>` : ''}<th>Kulcs</th>${penz.map((pk) => `<th class="sa-jobb">${e((s.mezok.find((m) => m.k === pk) || {}).c || pk)}</th>`).join('')}<th>Módosítva</th><th>Látszik</th><th class="sa-jobb"><span class="sa-sr">Műveletek</span></th></tr></thead><tbody>${lap.map((x) => `
                <tr class="is-katt" data-kulcs="${e(x.kulcs)}" tabindex="0">${rendez ? `<td><button type="button" class="sa-szelem__fogo" data-fogo aria-label="${e(x.cim)} áthelyezése – ↑ ↓">${I('fogo')}</button></td>` : ''}
                <td><div class="sa-sor sa-sor--tor" style="gap:4px 8px"><span class="sa-vastag sa-egysor" style="--sa-cella-max:${penz.length ? 240 : 340}px" title="${e(x.cim || x.kulcs)}">${e(x.cim || x.kulcs)}</span>${x.szerkesztve ? '' : '<span class="sa-cimke sa-cimke--szurke" title="A kezdőtartalomból, még nem szerkesztett">alap</span>'}</div></td>
                ${km ? `<td class="sa-halvany">${e(String(A.kiolvas(x.adat, km.k) || '–'))}</td>` : ''}<td class="sa-mono sa-halvany"><div class="sa-egysor" style="--sa-cella-max:${penz.length ? 130 : 180}px" title="${e(x.kulcs)}">${e(x.kulcs)}</div></td>
                ${penz.map((pk) => { const n = Number(A.kiolvas(x.adat, pk)) || 0; return `<td class="sa-jobb"><div>${e(ft(n))}</div><div class="sa-kicsi sa-halvany">bruttó ${e(ft(brutto(n)))}</div></td>`; }).join('')}
                <td class="sa-halvany">${e(relativ(x.modositva))}</td><td>${kapcsolo('st-ea-' + f.tipus + '-' + x.kulcs, x.aktiv, (x.cim || x.kulcs) + ' – megjelenik a webhelyen', 'data-aktiv')}</td>
                <td class="sa-jobb"><span class="sa-sor" style="justify-content:flex-end;gap:4px"><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-masol aria-label="${e(x.cim)} másolása" title="Másolat">${I('duplikal')}</button><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi sa-ikon-gomb--veszely" data-torol aria-label="${e(x.cim)} törlése" title="Kukába" data-ketlepcsos="Biztos? Kukába teszem">${I('torol')}</button></span></td></tr>`).join('')}</tbody></table></div>`
                : ures(f.q || f.kat ? 'Nincs találat.' : `Még nincs ${s.egyes.toLowerCase()}.`, 'elem');
            if (l.length > LAP) lista.appendChild(lapozo(l.length, f.oldal, LAP, (n) => { f.oldal = n; rajzol(); }));
        };
        const toltes = async () => { elemek = (await api('admin/elemek', { query: { tipus: f.tipus } })).lista; rajzol(); };
        const sz = $('[data-szegmens]', hova);
        if (sz) sz.addEventListener('valtozas', (ev) => { f.tipus = ev.detail; f.oldal = 1; f.kat = ''; toltes(); });
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); f.oldal = 1; rajzol(); }, 150));
        hova.addEventListener('change', (ev) => { if (ev.target.matches('[data-kat]')) { f.kat = ev.target.value; f.oldal = 1; rajzol(); } });
        $('[data-uj]', hova).addEventListener('click', () => elemPopup(f.tipus, null, toltes));
        lista.addEventListener('change', async (ev) => {
            const c = ev.target.closest('[data-aktiv]');
            if (!c) return;
            const x = elemek.find((y) => y.kulcs === c.closest('[data-kulcs]').dataset.kulcs);
            try { await api('admin/elem', { method: 'POST', body: { tipus: f.tipus, eredeti: x.kulcs, adat: x.adat, aktiv: c.checked } }); x.aktiv = c.checked; jelez(c.checked ? 'Megjelenik a webhelyen.' : 'Elrejtve a webhelyről.'); }
            catch (y) { c.checked = !c.checked; jelez(y.message, 'hiba'); }
        });
        lista.addEventListener('click', (ev) => {
            const g = ev.target.closest('[data-masol], [data-torol]');
            if (!g) return;
            const x = elemek.find((y) => y.kulcs === g.closest('[data-kulcs]').dataset.kulcs);
            if (g.hasAttribute('data-masol')) { elemPopup(f.tipus, null, toltes, '', masolat(f.tipus, x)); return; }
            tolt(g, async () => { try { await api('admin/elem/torol', { method: 'POST', body: { tipus: f.tipus, kulcs: x.kulcs } }); delete A.elemCache[f.tipus]; jelez('A kukába került – a Revíziók és kuka panelen visszaállítható.'); toltes(); } catch (y) { jelez(y.message, 'hiba'); } });
        });
        sorKattint(lista, '[data-kulcs]', (s0) => elemPopup(f.tipus, elemek.find((x) => x.kulcs === s0.dataset.kulcs), toltes));
        rendezheto(lista, 'tr', '[data-fogo]', async (regi, uj) => {
            const ofs = (f.oldal - 1) * LAP;
            const [x] = elemek.splice(ofs + regi, 1);
            elemek.splice(ofs + uj, 0, x);
            rajzol();
            const g = $$('[data-fogo]', lista)[uj];
            if (g) g.focus();
            bemond(`Áthelyezve: ${ofs + uj + 1}. hely`);
            try { await api('admin/elemek/sorrend', { method: 'POST', body: { tipus: f.tipus, kulcsok: elemek.map((y) => y.kulcs) } }); } catch (y) { jelez(y.message, 'hiba'); toltes(); }
        });
        await toltes();
        return undefined;
    };

    Object.entries(B.panelek || {}).forEach(([k, p]) => { if ((p.tipusok || []).length) A.panel(k, tarPanel(k)); });

    // Régi cím: #/elemek/<típus> → a típus saját panelje.
    A.panel('elemek', (hova, [t]) => {
        const cel = Object.entries(B.panelek || {}).find(([, p]) => (p.tipusok || []).includes(t)) || Object.entries(B.panelek || {}).find(([, p]) => (p.tipusok || []).length);
        S.mentetlen = null;
        location.replace('#/' + (cel ? cel[0] + (t ? '/' + t : '') : ''));
    });

    /** Másolat adatai: új kulcs (-masolat, -masolat-2…), „(másolat)” a címben. */
    function masolat(tipus, x) {
        const s = ES[tipus];
        const adat = JSON.parse(JSON.stringify(x.adat || {}));
        const km = s.kulcsmezo;
        const alap = String(x.kulcs).replace(/-masolat(-\d+)?$/, '');
        adat[km] = (s.mezok.find((m) => m.k === km) || {}).nagybetu ? (alap + '-M').toUpperCase().replace(/[^A-Z0-9_-]/g, '') : alap + '-masolat';
        if (s.cimmezo && s.cimmezo !== km && typeof adat[s.cimmezo] === 'string') adat[s.cimmezo] += ' (másolat)';
        return adat;
    }

    /* ================================================================== Elem-szerkesztő popup */

    /**
     * tipus, x (meglévő elem vagy null), kesz(), fokuszMezo (kijelölendő mező), elotolt (új elem kezdőadata).
     */
    function elemPopup(tipus, x, kesz, fokuszMezo = '', elotolt = null) {
        const s = ES[tipus];
        const f = urlap(s.mezok, x ? x.adat : (elotolt || {}), { szerkeszt: !!x });
        const email = tipus === 'email';
        const { el, bezar } = popup({
            cim: x ? `${s.egyes}: ${x.cim || x.kulcs}` : (elotolt ? `${s.egyes} – másolat` : `Új ${s.egyes.toLowerCase()}`),
            meret: s.mezok.some((m) => ['html', 'ismetlo', 'lista'].includes(m.t)) || email ? 'kozepes' : '',
            test: '',
            lab: `${x ? `<button type="button" class="sa-gomb sa-gomb--veszely" data-torol data-ketlepcsos="Biztos? Kukába teszem">${I('torol')}Kukába</button><button type="button" class="sa-gomb" data-masol>${I('duplikal')}Másolat</button>` : ''}
                ${email ? `<button type="button" class="sa-gomb" data-elonezet>${I('szem')}Előnézet</button><button type="button" class="sa-gomb" data-proba>${I('email')}Próbalevél nekem</button>` : ''}
                <div class="sa-kapcsolosor" style="min-height:38px"><label class="sa-kapcsolosor__szoveg" for="st-elem-aktiv">Látszik</label>${kapcsolo('st-elem-aktiv', x ? x.aktiv : true, 'Megjelenik a webhelyen')}</div>
                <span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Mégse</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button>`,
        });
        $('.sa-popup__test', el).appendChild(f.el);
        if (fokuszMezo) setTimeout(() => { const w = $(`[data-mezo="${CSS.escape(fokuszMezo)}"]`, el); if (w) { const pn = w.closest('.sa-ful-panel'); if (pn && pn.hidden) { const g = $(`[aria-controls="${pn.id}"]`, el); if (g) g.click(); } const i = $('input, textarea, [contenteditable]', w); if (i) i.focus(); w.classList.add('is-kiemelt'); } }, 60);
        const adatNow = () => Object.assign({}, x ? x.adat : (elotolt || {}), f.ertek());
        $('[data-ment]', el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            const h = f.ellenoriz();
            if (h.length) { popupHiba(el, h.join(' ')); return; }
            try {
                await api('admin/elem', { method: 'POST', body: { tipus, eredeti: x ? x.kulcs : '', adat: adatNow(), aktiv: $('#st-elem-aktiv', el).checked } });
                delete A.elemCache[tipus];
                jelez('Mentve.');
                bezar();
                kesz();
            } catch (y) { popupHiba(el, y.message); }
        }));
        const t = $('[data-torol]', el);
        if (t) t.addEventListener('click', () => tolt(t, async () => {
            try { await api('admin/elem/torol', { method: 'POST', body: { tipus, kulcs: x.kulcs } }); delete A.elemCache[tipus]; jelez('A kukába került – visszaállítható.'); bezar(); kesz(); }
            catch (y) { popupHiba(el, y.message); }
        }));
        const m = $('[data-masol]', el);
        if (m) m.addEventListener('click', () => { const a = masolat(tipus, { kulcs: x.kulcs, adat: adatNow() }); bezar(); elemPopup(tipus, null, kesz, '', a); });
        const ev2 = $('[data-elonezet]', el);
        if (ev2) ev2.addEventListener('click', () => tolt(ev2, async () => {
            try {
                const r = await api('admin/email/elonezet', { method: 'POST', body: { kulcs: x ? x.kulcs : String(adatNow().kulcs || ''), adat: adatNow() } });
                popup({ cim: `Előnézet: ${r.targy}`, meret: 'szeles', test: `<div class="sa-kicsi sa-halvany" style="margin-bottom:8px">Mintaadatokkal. Tárgy: <b>${e(r.targy)}</b></div><iframe class="st-email-elo" sandbox="" title="A levél előnézete"></iframe>`, lab: '<span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Bezárás</button>', nyit: (p) => { $('iframe', p).srcdoc = r.html; } });
            } catch (y) { popupHiba(el, y.message); }
        }));
        const pr = $('[data-proba]', el);
        if (pr) pr.addEventListener('click', () => tolt(pr, async () => {
            try { const r = await api('admin/email/proba', { method: 'POST', body: { kulcs: x ? x.kulcs : '', adat: adatNow() } }); jelez(`Próbalevél elküldve: ${r.cimzett}`); } catch (y) { popupHiba(el, y.message); }
        }));
        return { el, bezar };
    }
    A.elemSzerkeszt = elemPopup;

    /* ================================================================== Blog */

    const BSTAT = { publish: ['Közzétéve', 'ok'], draft: ['Vázlat', 'figyel'], future: ['Ütemezve', 'info'], private: ['Privát', 'szurke'], pending: ['Jóváhagyásra vár', 'figyel'], trash: ['Kukában', 'hiba'] };

    A.panel('blog', async (hova, [id]) => {
        if (id) return cikkSzerkeszto(hova, Number(id));
        const f = S.szurok.blog = Object.assign({ statusz: '', kat: '', q: '', oldal: 1 }, S.szurok.blog || {});
        const k = await api('admin/blog/kategoriak');
        hova.innerHTML = `<div class="sa-eszkozsor">${szegmens('Állapot', { '': 'Mind', publish: 'Közzétéve', draft: 'Vázlat', future: 'Ütemezve', trash: 'Kuka' }, f.statusz)}<span class="sa-ter"></span>
            <select class="sa-select" data-kat aria-label="Kategória" style="width:auto"><option value="">Minden kategória</option>${k.lista.map((x) => `<option value="${x.id}" ${String(x.id) === String(f.kat) ? 'selected' : ''}>${e(x.nev)} (${x.db})</option>`).join('')}</select>
            <input class="sa-input" type="search" data-q placeholder="Keresés a cikkekben…" aria-label="Keresés a cikkekben" value="${e(f.q)}" style="max-width:240px">
            <button type="button" class="sa-gomb sa-gomb--fo" data-uj>${I('plusz')}Új cikk</button></div>
            <div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"></div>`;
        const lista = $('[data-lista]', hova);
        const rajzol = async () => {
            const r = await api('admin/blog', { query: { statusz: f.statusz, kategoria: f.kat, q: f.q, oldal: f.oldal, db: LAP } });
            lista.innerHTML = r.lista.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Cikk</th><th>Kategória</th><th>Állapot</th><th>Dátum</th><th class="sa-jobb"><span class="sa-sr">Műveletek</span></th></tr></thead><tbody>${r.lista.map((x) => `
                <tr class="${f.statusz === 'trash' ? '' : 'is-katt'}" data-cikk="${x.id}" ${f.statusz === 'trash' ? '' : 'tabindex="0"'}><td><div class="sa-sor" style="gap:10px;flex-wrap:nowrap">${x.kep ? `<img src="${e(x.kep)}" alt="" class="st-blog-kep" loading="lazy">` : `<span class="st-blog-kep st-blog-kep--ures">${I('kep')}</span>`}<span class="sa-vastag sa-egysor" style="--sa-cella-max:420px" title="${e(x.cim)}">${e(x.cim || '(cím nélkül)')}</span></div></td>
                <td class="sa-halvany">${e(x.kategoriak.join(', ') || '–')}</td><td>${cimke(...(BSTAT[x.statusz] || [x.statusz, 'szurke']))}</td><td class="sa-halvany">${e(datum(x.datum))}</td>
                <td class="sa-jobb"><span class="sa-sor" style="justify-content:flex-end;gap:4px">${f.statusz === 'trash' ? `<button type="button" class="sa-gomb sa-gomb--kicsi" data-vissza="${x.id}">${I('visszaall')}Visszaállítás</button><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--veszely" data-vegleges="${x.id}" data-ketlepcsos="Biztos? Véglegesen törlöm">${I('torol')}Végleges törlés</button>` : `<a class="sa-ikon-gomb sa-ikon-gomb--kicsi" href="${e(x.url)}" target="_blank" rel="noopener" aria-label="${e(x.cim)} megnyitása a webhelyen" title="Megnyitás a webhelyen">${I('kulso')}</a>`}</span></td></tr>`).join('')}</tbody></table></div>`
                : ures(f.q ? 'Nincs találat.' : (f.statusz === 'trash' ? 'A kuka üres.' : 'Még nincs cikk.'), 'konyv');
            if (r.ossz > LAP) lista.appendChild(lapozo(r.ossz, f.oldal, LAP, (n) => { f.oldal = n; rajzol(); }));
        };
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { f.statusz = ev.detail; f.oldal = 1; rajzol(); });
        $('[data-kat]', hova).addEventListener('change', (ev) => { f.kat = ev.target.value; f.oldal = 1; rajzol(); });
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); f.oldal = 1; rajzol(); }, 250));
        $('[data-uj]', hova).addEventListener('click', () => {
            const { el, bezar } = popup({ cim: 'Új cikk', meret: 'kicsi', test: `<div class="sa-mezo"><label class="sa-mezo__cim" for="st-uc">Cím<em aria-hidden="true">*</em></label><input class="sa-input" id="st-uc" maxlength="200"></div>`, lab: `<button type="button" class="sa-gomb" data-bezar>Mégse</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('plusz')}Létrehozás vázlatként</button>` });
            $('[data-ment]', el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
                const c = $('#st-uc', el).value.trim();
                if (!c) { popupHiba(el, 'Add meg a címet.'); return; }
                try { const r = await api('admin/blog', { method: 'POST', body: { cim: c } }); bezar(); location.hash = '#/blog/' + r.id; } catch (y) { popupHiba(el, y.message); }
            }));
        });
        lista.addEventListener('click', (ev) => {
            const g = ev.target.closest('[data-vissza], [data-vegleges]');
            if (!g) return;
            tolt(g, async () => {
                try { if (g.dataset.vissza) await api(`admin/blog/${g.dataset.vissza}/vissza`, { method: 'POST', body: {} }); else await api(`admin/kuka/${g.dataset.vegleges}/vegleges`, { method: 'POST', body: {} }); jelez(g.dataset.vissza ? 'Visszaállítva.' : 'Véglegesen törölve.'); rajzol(); } catch (y) { jelez(y.message, 'hiba'); }
            });
        });
        sorKattint(lista, '[data-cikk][tabindex]', (s0) => { location.hash = '#/blog/' + s0.dataset.cikk; });
        await rajzol();
        return undefined;
    });

    async function cikkSzerkeszto(hova, id) {
        const [c, k] = await Promise.all([api('admin/blog/' + id), api('admin/blog/kategoriak')]);
        cim(c.cim || '(cím nélkül)', '<a href="#/blog">Blog</a>');
        let kat = c.kategoria_id.slice(), katok = k.lista;
        hova.innerHTML = `<div class="st-cikk">
            <div class="st-cikk__fo sa-kartya">
                <input class="st-cikk__cim" data-c value="${e(c.cim)}" maxlength="200" aria-label="A cikk címe" placeholder="A cikk címe">
                <div data-torzs></div>
            </div>
            <aside class="st-cikk__oldal">
                <div class="sa-kartya"><div class="sa-kartya__fej"><h2 class="sa-kartya__cim">Közzététel</h2><span class="sa-ter"></span><span data-allapot></span></div>
                    <div class="sa-mezok"><div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-c-st">Állapot</label><select class="sa-select" id="st-c-st">${Object.entries({ publish: 'Közzétéve', draft: 'Vázlat', private: 'Privát' }).map(([kk, v]) => `<option value="${kk}" ${(c.statusz === kk || (c.statusz === 'future' && kk === 'publish')) ? 'selected' : ''}>${v}</option>`).join('')}</select></div>
                    <div class="sa-mezo"><span class="sa-mezo__cim">Dátum</span><div class="sa-datum"><input class="sa-input" readonly data-nap value="${e(c.nap.replace(/-/g, '.') + '.')}" aria-label="Megjelenés napja" aria-haspopup="dialog"><input type="hidden" data-nap-iso value="${e(c.nap)}"></div></div>
                    <div class="sa-mezo"><label class="sa-mezo__cim" for="st-c-o">Időpont</label><input class="sa-input" id="st-c-o" data-ora value="${e(c.ora)}" inputmode="numeric" maxlength="5" placeholder="óó:pp"></div></div>
                    <div class="sa-sor sa-sor--veg" style="margin-top:12px"><a class="sa-gomb sa-gomb--kicsi" href="${e(c.url)}" target="_blank" rel="noopener">${I('kulso')}Megnyitás</a><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--veszely" data-kuka data-ketlepcsos="Biztos? Kukába teszem">${I('torol')}Kukába</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button></div></div>
                <div class="sa-kartya"><h2 class="sa-kartya__cim">Kategóriák és címkék</h2><div class="sa-pillek" data-katok></div>
                    <div class="sa-mezo__sor" style="margin-top:8px"><input class="sa-input sa-input--kicsi" data-ujkat placeholder="Új kategória…" aria-label="Új kategória neve" maxlength="80"><button type="button" class="sa-gomb sa-gomb--kicsi" data-katment>${I('plusz')}Hozzáad</button></div>
                    <div class="sa-mezo" style="margin-top:10px"><label class="sa-mezo__cim" for="st-c-t">Címkék (vesszővel)</label><input class="sa-input" id="st-c-t" value="${e(c.cimkek)}"></div></div>
                <div class="sa-kartya"><h2 class="sa-kartya__cim">Kiemelt kép</h2><div class="st-cikk__kep" data-kep style="background-image:${c.kep_url ? `url('${e(c.kep_url)}')` : 'none'}">${c.kep_url ? '' : `<span class="sa-halvany sa-kicsi">Nincs kép</span>`}</div><input type="hidden" data-kep-url value="${e(c.kep_url)}">
                    <div class="sa-sor" style="margin-top:8px"><button type="button" class="sa-gomb sa-gomb--kicsi" data-kepvalaszt>${I('kep')}Médiatár</button><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--szoveg" data-keptorol>Eltávolítás</button></div></div>
                <div class="sa-kartya"><h2 class="sa-kartya__cim">Kivonat és cím</h2><div class="sa-mezok">
                    <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-c-k">Kivonat (listákban, megosztásnál)</label><textarea class="sa-textarea" id="st-c-k" rows="3" maxlength="600">${e(c.kivonat)}</textarea></div>
                    <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-c-s">Útvonal (slug)</label><input class="sa-input sa-input--mono" id="st-c-s" value="${e(c.slug)}"></div></div></div>
                <div class="sa-kartya"><h2 class="sa-kartya__cim">SEO</h2><div class="sa-mezok">
                    <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-c-sc">Cím a keresőben</label><input class="sa-input" id="st-c-sc" maxlength="200" value="${e(c.seo.title)}" placeholder="${e(c.cim)}"></div>
                    <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-c-sd">Leírás</label><textarea class="sa-textarea" id="st-c-sd" rows="3" maxlength="400">${e(c.seo.description)}</textarea></div>
                    <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-c-sk">Fókusz-kulcsszó</label><input class="sa-input" id="st-c-sk" maxlength="120" value="${e(c.seo.kulcsszo)}"></div>
                    <div class="sa-mezo sa-mezo--szeles"><div class="sa-kapcsolosor"><label class="sa-kapcsolosor__szoveg" for="st-c-n">Ne jelenjen meg a keresőkben</label>${kapcsolo('st-c-n', !!c.seo.noindex, 'Ne jelenjen meg a keresőkben')}</div></div></div></div>
            </aside></div>`;
        const torzs = A.mezoVezerlo({ k: 'tartalom', t: 'html', c: 'Szöveg' }, c.tartalom, { valtozas: () => piszkosit() });
        $('[data-torzs]', hova).appendChild(torzs.el);
        let mentett = null;
        const allapot = () => JSON.stringify([$('[data-c]', hova).value, torzs.ertek(), $('#st-c-st', hova).value, $('[data-nap-iso]', hova).value, $('[data-ora]', hova).value, kat, $('#st-c-t', hova).value, $('[data-kep-url]', hova).value, $('#st-c-k', hova).value, $('#st-c-s', hova).value, $('#st-c-sc', hova).value, $('#st-c-sd', hova).value, $('#st-c-sk', hova).value, $('#st-c-n', hova).checked]);
        const piszkosit = () => { const p = mentett !== null && allapot() !== mentett; $('[data-allapot]', hova).innerHTML = p ? cimke('Mentetlen', 'figyel') : cimke(...(BSTAT[c.statusz] || [c.statusz, 'szurke'])); };
        S.mentetlen = () => mentett !== null && allapot() !== mentett;
        const katRajzol = () => {
            $('[data-katok]', hova).innerHTML = katok.map((x) => `<button type="button" class="sa-valaszto-pill" data-kat="${x.id}" aria-pressed="${kat.includes(x.id)}">${e(x.nev)}</button>`).join('') || '<span class="sa-halvany sa-kicsi">Nincs kategória.</span>';
        };
        katRajzol();
        hova.addEventListener('input', piszkosit);
        hova.addEventListener('change', piszkosit);
        $('[data-katok]', hova).addEventListener('click', (ev) => { const g = ev.target.closest('[data-kat]'); if (!g) return; const n = Number(g.dataset.kat); kat = kat.includes(n) ? kat.filter((x) => x !== n) : [...kat, n]; katRajzol(); piszkosit(); });
        $('[data-katment]', hova).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            const n = $('[data-ujkat]', hova).value.trim();
            if (!n) return;
            try { const r = await api('admin/blog/kategoria', { method: 'POST', body: { nev: n } }); if (!katok.some((x) => x.id === r.id)) katok.push({ id: r.id, nev: r.nev, db: 0 }); kat.push(r.id); $('[data-ujkat]', hova).value = ''; katRajzol(); piszkosit(); } catch (y) { jelez(y.message, 'hiba'); }
        }));
        const nap = $('[data-nap]', hova), napIso = $('[data-nap-iso]', hova);
        const napNyit = () => A.naptar(nap, napIso.value, (iso) => { if (!iso) return; napIso.value = iso; nap.value = iso.replace(/-/g, '.') + '.'; piszkosit(); });
        nap.addEventListener('click', napNyit);
        nap.addEventListener('keydown', (ev) => { if (['Enter', ' ', 'ArrowDown'].includes(ev.key)) { ev.preventDefault(); napNyit(); } });
        const kep = $('[data-kep]', hova), kepUrl = $('[data-kep-url]', hova);
        const kepAllit = (u) => { kepUrl.value = u; kep.style.backgroundImage = u ? `url('${u.replace(/['\\]/g, '')}')` : 'none'; kep.innerHTML = u ? '' : '<span class="sa-halvany sa-kicsi">Nincs kép</span>'; piszkosit(); };
        $('[data-kepvalaszt]', hova).addEventListener('click', async () => { const x = await A.mediaValaszt({ cim: 'Kiemelt kép' }); if (x) kepAllit(x.url); });
        $('[data-keptorol]', hova).addEventListener('click', () => kepAllit(''));
        const ment = (g) => tolt(g, async () => {
            const ora = $('[data-ora]', hova).value.trim();
            if (!/^\d{1,2}:\d{2}$/.test(ora)) { jelez('Az időpont alakja: óó:pp', 'hiba'); $('[data-ora]', hova).focus(); return; }
            try {
                const r = await api('admin/blog/' + id, { method: 'POST', body: {
                    cim: $('[data-c]', hova).value.trim(), tartalom: torzs.ertek(), statusz: $('#st-c-st', hova).value, nap: napIso.value, ora, kategoriak: kat, cimkek: $('#st-c-t', hova).value,
                    kep: kepUrl.value, kivonat: $('#st-c-k', hova).value, slug: $('#st-c-s', hova).value.trim(),
                    seo: { title: $('#st-c-sc', hova).value.trim(), description: $('#st-c-sd', hova).value.trim(), kulcsszo: $('#st-c-sk', hova).value.trim(), noindex: $('#st-c-n', hova).checked },
                } });
                c.statusz = r.statusz;
                $('#st-c-s', hova).value = r.slug;
                mentett = allapot();
                piszkosit();
                cim(r.cim, '<a href="#/blog">Blog</a>');
                jelez(r.statusz === 'future' ? 'Mentve – ütemezve a megadott időpontra.' : 'Cikk mentve.');
            } catch (y) { jelez(y.message, 'hiba'); }
        });
        $('[data-ment]', hova).addEventListener('click', (ev) => ment(ev.currentTarget));
        hova.addEventListener('keydown', (ev) => { if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') { ev.preventDefault(); ment($('[data-ment]', hova)); } });
        $('[data-kuka]', hova).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => { try { await api(`admin/blog/${id}/kuka`, { method: 'POST', body: {} }); S.mentetlen = null; jelez('A cikk a kukába került.'); location.hash = '#/blog'; } catch (y) { jelez(y.message, 'hiba'); } }));
        mentett = allapot();
        piszkosit();
    }

    /* ================================================================== Jogi oldalak */

    A.panel('jogi', async (hova, [id]) => {
        if (id) return jogiSzerkeszto(hova, Number(id));
        const r = await api('admin/oldalak');
        const l = r.lista.filter((x) => x.jogi);
        hova.innerHTML = `<div class="sa-kartya sa-kartya--sima">${l.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Dokumentum</th><th>Útvonal</th><th>Állapot</th><th>Módosítva</th><th class="sa-jobb"><span class="sa-sr">Megnyitás</span></th></tr></thead><tbody>${l.map((x) => `<tr class="is-katt" tabindex="0" data-jogi="${x.id}"><td class="sa-vastag">${e(x.cim)}</td><td class="sa-mono sa-halvany">/${e(x.ut)}</td><td>${cimke(x.statusz === 'publish' ? 'Közzétéve' : 'Vázlat', x.statusz === 'publish' ? 'ok' : 'figyel')}</td><td class="sa-halvany">${e(relativ(x.modositva))}</td><td class="sa-jobb"><a class="sa-ikon-gomb sa-ikon-gomb--kicsi" href="${e(x.url)}" target="_blank" rel="noopener" aria-label="${e(x.cim)} megnyitása a webhelyen">${I('kulso')}</a></td></tr>`).join('')}</tbody></table></div>` : ures('Nincs jogi oldal. A kezdőtartalom betöltése (Export / Import) létrehozza őket.', 'gdpr')}</div>
            <p class="sa-kicsi sa-halvany">A jogi szövegek tervezetek – élesítés előtt jogásszal ellenőriztesd őket. Minden mentés naplózva, visszaállítható.</p>`;
        sorKattint(hova, '[data-jogi]', (s0) => { location.hash = '#/jogi/' + s0.dataset.jogi; });
        return undefined;
    });

    async function jogiSzerkeszto(hova, id) {
        const o = await api('admin/oldal/' + id);
        cim(o.cim, '<a href="#/jogi">Jogi oldalak</a>');
        const ji = o.szekciok.findIndex((x) => x.tipus === 'jogi');
        const js = ji >= 0 ? o.szekciok[ji] : { tipus: 'jogi' };
        hova.innerHTML = `<div class="st-cikk"><div class="st-cikk__fo sa-kartya"><input class="st-cikk__cim" data-c value="${e(o.cim)}" maxlength="200" aria-label="A dokumentum címe"><div data-torzs></div></div>
            <aside class="st-cikk__oldal"><div class="sa-kartya"><div class="sa-kartya__fej"><h2 class="sa-kartya__cim">Dokumentum</h2><span class="sa-ter"></span><span data-allapot></span></div><div data-meta></div>
                <div class="sa-sor sa-sor--veg" style="margin-top:12px"><a class="sa-gomb sa-gomb--kicsi" href="${e(o.url)}" target="_blank" rel="noopener">${I('kulso')}Megnyitás</a><a class="sa-gomb sa-gomb--kicsi" href="#/oldalak/${id}">${I('retegek')}Oldalépítő</a><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button></div></div>
                <div class="sa-kartya"><h2 class="sa-kartya__cim">SEO</h2><div class="sa-mezok"><div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-j-t">Cím a keresőben</label><input class="sa-input" id="st-j-t" maxlength="200" value="${e(o.seo.title)}"></div><div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-j-d">Leírás</label><textarea class="sa-textarea" id="st-j-d" rows="3" maxlength="400">${e(o.seo.description)}</textarea></div></div></div></aside></div>`;
        const torzs = A.mezoVezerlo({ k: 'tartalom', t: 'html', c: 'Szöveg' }, o.tartalom, { valtozas: () => jel() });
        $('[data-torzs]', hova).appendChild(torzs.el);
        const meta = urlap([{ k: 'verzio', t: 'szoveg', c: 'Verzió', max: 30, fel: true }, { k: 'hatalyos', t: 'datum', c: 'Hatályos', fel: true }], js, { valtozas: () => jel() });
        $('[data-meta]', hova).appendChild(meta.el);
        let mentett = null;
        const allapot = () => JSON.stringify([$('[data-c]', hova).value, torzs.ertek(), meta.ertek(), $('#st-j-t', hova).value, $('#st-j-d', hova).value]);
        const jel = () => { $('[data-allapot]', hova).innerHTML = mentett !== null && allapot() !== mentett ? cimke('Mentetlen', 'figyel') : ''; };
        S.mentetlen = () => mentett !== null && allapot() !== mentett;
        hova.addEventListener('input', jel);
        const ment = (g) => tolt(g, async () => {
            const sz = o.szekciok.slice();
            const uj = Object.assign({}, js, meta.ertek(), { tipus: 'jogi' });
            if (ji >= 0) sz[ji] = uj; else sz.unshift(uj);
            try {
                await api('admin/oldal/' + id, { method: 'POST', body: { cim: $('[data-c]', hova).value.trim(), tartalom: torzs.ertek(), szekciok: sz, seo: Object.assign({}, o.seo, { title: $('#st-j-t', hova).value.trim(), description: $('#st-j-d', hova).value.trim() }) } });
                mentett = allapot();
                jel();
                jelez('Jogi dokumentum mentve – a korábbi változat a Naplóból visszaállítható.');
            } catch (y) { jelez(y.message, 'hiba'); }
        });
        $('[data-ment]', hova).addEventListener('click', (ev) => ment(ev.currentTarget));
        hova.addEventListener('keydown', (ev) => { if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') { ev.preventDefault(); ment($('[data-ment]', hova)); } });
        mentett = allapot();
    }

    /* ================================================================== Médiatár */

    const meretSzoveg = (b) => (b > 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');

    A.panel('media', async (hova) => {
        const f = S.szurok.media = Object.assign({ tipus: '', q: '', oldal: 1 }, S.szurok.media || {});
        const DB = 36;
        hova.innerHTML = `<div class="sa-eszkozsor">${szegmens('Típus', { '': 'Mind', kep: `${I('kep')}Képek`, video: `${I('video')}Videók`, dokumentum: `${I('dokumentum')}Dokumentumok` }, f.tipus)}<span class="sa-ter"></span>
            <input class="sa-input" type="search" data-q placeholder="Keresés (cím, fájlnév)…" aria-label="Keresés a médiatárban" value="${e(f.q)}" style="max-width:260px">
            ${B.media && window.wp && wp.media ? `<button type="button" class="sa-gomb sa-gomb--fo" data-feltolt>${I('feltolt')}Feltöltés</button>` : ''}</div>
            <div data-racs aria-live="polite"></div>`;
        const racs = $('[data-racs]', hova);
        let lista = [];
        const rajzol = async () => {
            const r = await api('admin/media', { query: { tipus: f.tipus, q: f.q, oldal: f.oldal, db: DB } });
            lista = r.lista;
            racs.innerHTML = lista.length ? `<div class="st-media">${lista.map((x) => `<button type="button" class="st-media__elem" data-media-id="${x.id}" title="${e(x.cim)}">${x.kiskep ? `<img src="${e(x.kiskep)}" alt="${e(x.alt)}" loading="lazy">` : `<span class="st-media__ikon">${I(x.mime.startsWith('video') ? 'video' : 'dokumentum')}<small class="sa-mono">${e((x.mime.split('/')[1] || '').toUpperCase())}</small></span>`}<span class="st-media__nev">${e(x.cim || '(névtelen)')}</span>${x.mime.startsWith('image') && !x.alt ? '<span class="st-media__jel" title="Nincs alternatív szöveg">alt?</span>' : ''}</button>`).join('')}</div>`
                : `<div class="sa-kartya">${ures(f.q ? 'Nincs találat.' : 'A médiatár üres.', 'kep')}</div>`;
            if (r.ossz > DB) racs.appendChild(lapozo(r.ossz, f.oldal, DB, (n) => { f.oldal = n; rajzol(); }));
        };
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { f.tipus = ev.detail; f.oldal = 1; rajzol(); });
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); f.oldal = 1; rajzol(); }, 250));
        const fg = $('[data-feltolt]', hova);
        if (fg) fg.addEventListener('click', () => { const k = wp.media({ title: 'Feltöltés a médiatárba', multiple: true, button: { text: 'Kész' } }); k.on('close', () => { rajzol(); setTimeout(() => fg.focus(), 50); }); k.open(); });
        racs.addEventListener('click', (ev) => { const g = ev.target.closest('[data-media-id]'); if (g) mediaPopup(lista.find((x) => String(x.id) === g.dataset.mediaId), rajzol); });
        await rajzol();
    });

    function mediaPopup(x, kesz) {
        const { el, bezar } = popup({
            cim: x.cim || 'Médiaelem',
            meret: 'kozepes',
            test: `<div class="st-media-reszlet"><div class="st-media-reszlet__kep">${x.mime.startsWith('image') ? `<img src="${e(x.url)}" alt="">` : x.mime.startsWith('video') ? `<video src="${e(x.url)}" controls preload="metadata"></video>` : `<span class="st-media__ikon">${I('dokumentum')}</span>`}</div>
                <div><dl class="sa-kv"><dt>Típus</dt><dd class="sa-mono">${e(x.mime)}</dd><dt>Méret</dt><dd>${e(meretSzoveg(x.meret))}${x.w ? ` · ${x.w}×${x.h} px` : ''}</dd><dt>Feltöltve</dt><dd>${e(datumIdo(x.datum))}</dd></dl>
                <div class="sa-mezok" style="margin-top:12px"><div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-md-u">Cím (URL)</label><div class="sa-mezo__sor"><input class="sa-input sa-input--mono" id="st-md-u" readonly value="${e(x.url)}"><button type="button" class="sa-gomb" data-masol>${I('masol')}Másolás</button></div></div>
                <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-md-c">Cím</label><input class="sa-input" id="st-md-c" maxlength="200" value="${e(x.cim)}"></div>
                ${x.mime.startsWith('image') ? `<div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-md-a">Alternatív szöveg (akadálymentesség, SEO)</label><input class="sa-input" id="st-md-a" maxlength="300" value="${e(x.alt)}"></div>` : ''}
                <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-md-f">Képaláírás</label><textarea class="sa-textarea" id="st-md-f" rows="2" maxlength="600">${e(x.felirat)}</textarea></div></div></div></div>`,
            lab: `<span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Mégse</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button>`,
        });
        $('[data-masol]', el).addEventListener('click', () => A.masol(x.url));
        $('[data-ment]', el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            try {
                const body = { cim: $('#st-md-c', el).value, felirat: $('#st-md-f', el).value };
                const a = $('#st-md-a', el);
                if (a) body.alt = a.value;
                await api('admin/media/' + x.id, { method: 'POST', body });
                jelez('Médiaelem mentve.');
                bezar();
                kesz();
            } catch (y) { popupHiba(el, y.message); }
        }));
    }
})();
