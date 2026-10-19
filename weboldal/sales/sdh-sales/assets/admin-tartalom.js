/* Értékesítés – admin: Tartalom › Oldalak (lista + szekció-építő élő előnézettel), Tartalom › Elemek, Menü és lábléc. */
(() => {
    'use strict';

    const A = window.PA;
    if (!A) return;
    const { B, S, $, $$, e, I, WI, api, jelez, bemond, tolt, popup, popupHiba, urlap, kapcsolo, szegmens, sorKattint, kesleltet, rendezheto, elemLista, ures, cim, cimke, ft, brutto, datumIdo, relativ } = A;

    const SZT = B.szekciok || {};
    const STATUSZ = { publish: ['Közzétéve', 'ok'], draft: ['Vázlat', 'figyel'], private: ['Privát', 'szurke'], pending: ['Jóváhagyásra vár', 'figyel'] };

    /* ================================================================== Oldalak – lista */

    A.panel('oldalak', async (hova, [id]) => {
        if (id) return epito(hova, Number(id));
        const f = S.szurok.oldalak = Object.assign({ nezet: 'szekcios', q: '' }, S.szurok.oldalak || {});
        const r = await api('admin/oldalak');
        const oldalak = r.lista;
        hova.innerHTML = `<div class="sa-eszkozsor">${szegmens('Nézet', { szekcios: `Szekciós oldalak <small>${oldalak.filter((x) => x.szekcios).length}</small>`, mind: `Mind <small>${oldalak.length}</small>` }, f.nezet)}<span class="sa-ter"></span>
            <input class="sa-input" type="search" data-q placeholder="Cím vagy útvonal…" aria-label="Keresés az oldalak között" value="${e(f.q)}">
            <button type="button" class="sa-gomb sa-gomb--fo" data-uj>${I('plusz')}Új oldal</button></div>
            <div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"></div>`;
        const lista = $('[data-lista]', hova);
        const rajzol = () => {
            const q = f.q.toLowerCase();
            const l = oldalak.filter((x) => (f.nezet === 'mind' || x.szekcios) && (!q || x.cim.toLowerCase().includes(q) || x.ut.includes(q)));
            lista.innerHTML = l.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Oldal</th><th>Útvonal</th><th class="sa-jobb">Szekciók</th><th>Állapot</th><th>Módosítva</th><th></th></tr></thead><tbody>${l.map((x) => `
                <tr class="is-katt" data-oldal="${x.id}" tabindex="0"><td><span class="sa-vastag">${e(x.cim || '(cím nélkül)')}</span>${x.kezdolap ? ' ' + cimke('Kezdőlap', 'kiemelo') : ''}</td><td class="sa-mono sa-halvany">/${e(x.ut)}</td>
                <td class="sa-jobb">${x.szekcios ? A.szam(x.szekcio_db) : '<span class="sa-halvany">–</span>'}</td><td>${cimke(...(STATUSZ[x.statusz] || [x.statusz, 'szurke']))}</td><td class="sa-halvany">${e(relativ(x.modositva))}</td>
                <td class="sa-jobb"><a class="sa-ikon-gomb sa-ikon-gomb--kicsi" href="${e(x.url)}" target="_blank" rel="noopener" aria-label="${e(x.cim)} megnyitása a webhelyen">${I('kulso')}</a></td></tr>`).join('')}</tbody></table></div>`
                : ures(f.nezet === 'szekcios' ? 'Még nincs szekciós oldal. Hozz létre egyet, vagy töltsd be a kezdőtartalmat (Export / Import).' : 'Nincs találat.', 'oldal');
        };
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { f.nezet = ev.detail; rajzol(); });
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); rajzol(); }, 150));
        $('[data-uj]', hova).addEventListener('click', () => ujOldal(oldalak));
        sorKattint(lista, '[data-oldal]', (s) => { location.hash = '#/oldalak/' + s.dataset.oldal; });
        rajzol();
        return undefined;
    });

    function ujOldal(oldalak) {
        const { el, bezar } = popup({
            cim: 'Új oldal',
            meret: 'kicsi',
            test: `<div class="sa-mezo"><label class="sa-mezo__cim" for="sa-uo-c">Cím<em aria-hidden="true">*</em></label><input class="sa-input" id="sa-uo-c" maxlength="200" aria-required="true"></div>
                <div class="sa-mezo"><label class="sa-mezo__cim" for="sa-uo-s">Útvonal (slug)</label><input class="sa-input sa-input--mono" id="sa-uo-s" maxlength="80" placeholder="a címből készül"></div>
                <div class="sa-mezo"><label class="sa-mezo__cim" for="sa-uo-p">Szülő oldal</label><select class="sa-select" id="sa-uo-p"><option value="0">— Nincs (legfelső szint) —</option>${oldalak.map((x) => `<option value="${x.id}">/${e(x.ut)}</option>`).join('')}</select></div>
                <div class="sa-mezo"><label class="sa-mezo__cim" for="sa-uo-m">Kiindulás</label><select class="sa-select" id="sa-uo-m"><option value="">Üres oldal (egy nyitó szekcióval)</option>${oldalak.filter((x) => x.szekcios).map((x) => `<option value="${x.id}">Másolat: ${e(x.cim)}</option>`).join('')}</select></div>`,
            lab: `<button type="button" class="sa-gomb" data-bezar>Mégse</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('plusz')}Létrehozás vázlatként</button>`,
        });
        const c = $('#sa-uo-c', el), s = $('#sa-uo-s', el);
        c.addEventListener('input', () => { if (!s.dataset.kezi) s.value = c.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); });
        s.addEventListener('input', () => { s.dataset.kezi = '1'; });
        $('[data-ment]', el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            if (!c.value.trim()) { popupHiba(el, 'Add meg az oldal címét.'); c.focus(); return; }
            try {
                let szekciok = [{ tipus: 'hero', cim: c.value.trim(), elrendezes: 'kozep' }];
                const m = Number($('#sa-uo-m', el).value);
                if (m) szekciok = (await api('admin/oldal/' + m)).szekciok;
                const r = await api('admin/oldal', { method: 'POST', body: { cim: c.value.trim(), slug: s.value.trim(), szulo: Number($('#sa-uo-p', el).value), szekciok } });
                bezar();
                jelez('Oldal létrehozva (vázlat).');
                location.hash = '#/oldalak/' + r.id;
            } catch (x) { popupHiba(el, x.message); }
        }));
    }

    /* ================================================================== Szekció-építő */

    const szAlcim = (sz) => {
        const t = SZT[sz.tipus];
        const sz1 = [sz.cim, sz.alcim, sz.szoveg].find((x) => typeof x === 'string' && x.trim());
        return sz1 ? sz1.replace(/<[^>]+>/g, '').slice(0, 80) : (t ? t.leiras : '');
    };

    async function epito(hova, id) {
        const o = await api('admin/oldal/' + id);
        const E = { szekciok: (o.szekciok || []).map((x) => Object.assign({}, x)), aktiv: o.szekciok.length ? 0 : -1, piszkos: false, mentett: JSON.stringify(o.szekciok || []), seo: o.seo, cim: o.cim, statusz: o.statusz, urlap: null };
        S.mentetlen = () => E.piszkos;
        cim(o.cim || '(cím nélkül)', '<a href="#/oldalak">Oldalak</a>');
        hova.className = 'sa-tartalom sa-tartalom--teli';
        hova.innerHTML = `<div class="sa-epito" data-epito>
            <section class="sa-epito__oszlop" aria-label="Szekciók">
                <div class="sa-epito__fej"><h2>Szekciók <span class="sa-halvany" data-db></span></h2><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--fo" data-hozzaad>${I('plusz')}Szekció</button></div>
                <div class="sa-epito__gorgeto"><ol class="sa-szlista" data-szlista aria-describedby="sa-ep-sugo"></ol><p class="sa-kicsi sa-halvany" id="sa-ep-sugo" style="margin:10px 2px 0">Húzd a fogantyút, vagy fókuszáld és használd a ↑ ↓ billentyűt.</p></div>
                <div class="sa-epito__lab"><button type="button" class="sa-gomb sa-gomb--kicsi" data-seo>${I('kereses')}SEO és oldal</button><span class="sa-ter"></span><span class="sa-kicsi" data-allapot aria-live="polite"></span></div>
            </section>
            <section class="sa-epito__oszlop sa-epito__oszlop--urlap" aria-label="Szekció szerkesztése">
                <div class="sa-epito__fej"><h2 data-urlapcim>Szekció</h2><button type="button" class="sa-gomb sa-gomb--kicsi" data-elo-valt hidden>${I('szem')}Előnézet</button></div>
                <div class="sa-epito__gorgeto" data-urlap></div>
                <div class="sa-epito__lab"><a class="sa-gomb sa-gomb--kicsi sa-gomb--szoveg" href="#/oldalak">${I('balra')}Oldalak</a><span class="sa-ter"></span><button type="button" class="sa-gomb" data-elvet disabled data-ketlepcsos="Biztos? Elvetem a változásokat">Elvetés</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment disabled>${I('mentes')}Mentés</button></div>
            </section>
            <section class="sa-epito__oszlop sa-epito__oszlop--elo" aria-label="Élő előnézet">
                <div class="sa-epito__fej"><h2>Előnézet</h2>${szegmens('Előnézet mérete', { asztal: `${I('asztal')}<span class="sa-sr">Asztali</span>`, mobil: `${I('mobil')}<span class="sa-sr">Mobil</span>` }, 'asztal')}<button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-frissit aria-label="Előnézet frissítése">${I('ujra')}</button><a class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-uj-lap target="_blank" rel="noopener" href="${e(o.url)}" aria-label="Előnézet új lapon">${I('kulso')}</a><button type="button" class="sa-gomb sa-gomb--kicsi" data-urlap-valt hidden>Űrlap</button></div>
                <div class="sa-elonezet" data-elonezet><iframe title="Az oldal előnézete" data-iframe></iframe></div>
            </section></div>`;
        const ep = $('[data-epito]', hova), lista = $('[data-szlista]', hova), urlapDoboz = $('[data-urlap]', hova), iframe = $('[data-iframe]', hova);

        const allapotJel = () => {
            $('[data-db]', hova).textContent = `(${E.szekciok.length})`;
            $('[data-ment]', hova).disabled = !E.piszkos;
            $('[data-elvet]', hova).disabled = !E.piszkos;
            $('[data-allapot]', hova).innerHTML = E.piszkos ? cimke('Mentetlen', 'figyel') : cimke('Mentve', 'ok');
        };
        const piszkos = () => { E.piszkos = JSON.stringify(E.szekciok) !== E.mentett; allapotJel(); };

        const listaRajzol = (fokusz = null) => {
            lista.innerHTML = E.szekciok.map((sz, i) => {
                const t = SZT[sz.tipus] || { cim: sz.tipus, ikon: 'info' };
                return `<li class="sa-szelem ${i === E.aktiv ? 'is-aktiv' : ''} ${sz.rejtett ? 'is-rejtett' : ''}" data-i="${i}">
                    <button type="button" class="sa-szelem__fogo" data-fogo aria-label="${i + 1}. szekció (${e(t.cim)}) áthelyezése – ↑ ↓">${I('fogo')}</button>
                    <button type="button" class="sa-szelem__nyit" data-nyit aria-current="${i === E.aktiv}">${WI(t.ikon)}<span><b>${e(t.cim)}${sz.rejtett ? ' · rejtett' : ''}</b><small>${e(szAlcim(sz))}</small></span></button>
                    <button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-rejt aria-label="${sz.rejtett ? 'Megjelenítés' : 'Elrejtés'}" aria-pressed="${!!sz.rejtett}" title="${sz.rejtett ? 'Megjelenítés' : 'Elrejtés'}">${I(sz.rejtett ? 'szem_ki' : 'szem')}</button>
                    <button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-dup aria-label="Duplikálás" title="Duplikálás">${I('duplikal')}</button>
                    <button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi sa-ikon-gomb--veszely" data-torol aria-label="Törlés" title="Törlés" data-ketlepcsos="Biztos? Katt újra a törléshez">${I('torol')}</button></li>`;
            }).join('') || `<li>${ures('Még nincs szekció. Adj hozzá egyet.', 'elem')}</li>`;
            allapotJel();
            if (fokusz) { const li = lista.children[fokusz[0]]; const g = li && $(fokusz[1], li); if (g) g.focus(); }
        };

        const urlapRajzol = () => {
            const sz = E.szekciok[E.aktiv];
            if (!sz) { E.urlap = null; $('[data-urlapcim]', hova).textContent = 'Szekció'; urlapDoboz.innerHTML = ures('Válassz egy szekciót a bal oldalon, vagy adj hozzá újat.', 'elem'); return; }
            const t = SZT[sz.tipus];
            $('[data-urlapcim]', hova).textContent = `${E.aktiv + 1}. ${t ? t.cim : sz.tipus}`;
            if (!t) { E.urlap = null; urlapDoboz.innerHTML = ures(`Ismeretlen szekciótípus: ${sz.tipus}. Mentéskor kimarad.`, 'figyel'); return; }
            const f = urlap(t.mezok, sz, { valtozas: () => valtozott() });
            E.urlap = f;
            urlapDoboz.innerHTML = `<p class="sa-kicsi sa-halvany" style="margin:0 0 12px">${e(t.leiras)}</p>`;
            urlapDoboz.appendChild(f.el);
        };

        const valtozott = kesleltet(() => {
            const sz = E.szekciok[E.aktiv];
            if (!sz || !E.urlap) return;
            E.szekciok[E.aktiv] = Object.assign({}, sz, E.urlap.ertek(), { tipus: sz.tipus, rejtett: sz.rejtett });
            if (!E.szekciok[E.aktiv].rejtett) delete E.szekciok[E.aktiv].rejtett;
            const li = lista.children[E.aktiv];
            if (li) $('small', li).textContent = szAlcim(E.szekciok[E.aktiv]);
            piszkos();
            elonezet();
        }, 220);

        const valaszt = (i) => { E.aktiv = i; listaRajzol(); urlapRajzol(); };

        /* Előnézet: rövid életű token, a téma sablonjaival. */
        let elonezetSzam = 0;
        const elonezet = kesleltet(async () => {
            const n = ++elonezetSzam;
            try {
                const r = await api('admin/elonezet', { method: 'POST', body: { post_id: id, szekciok: E.szekciok } });
                if (n !== elonezetSzam) return;
                const gorg = (() => { try { return iframe.contentWindow.scrollY; } catch (x) { return 0; } })();
                iframe.onload = () => { try { iframe.contentWindow.scrollTo(0, gorg); } catch (x) { /* más eredet */ } };
                iframe.src = r.url;
                $('[data-uj-lap]', hova).href = r.url;
            } catch (x) { /* az előnézet hibája nem állítja meg a szerkesztést */ }
        }, 650);

        lista.addEventListener('click', (ev) => {
            const li = ev.target.closest('[data-i]');
            if (!li) return;
            const i = Number(li.dataset.i);
            if (ev.target.closest('[data-nyit]')) { valaszt(i); const f = $('input, textarea, select, [contenteditable], .sa-ikonvalaszto', urlapDoboz); if (f && window.innerWidth > 960) f.focus(); return; }
            if (ev.target.closest('[data-rejt]')) { E.szekciok[i].rejtett = !E.szekciok[i].rejtett; if (!E.szekciok[i].rejtett) delete E.szekciok[i].rejtett; listaRajzol([i, '[data-rejt]']); bemond(E.szekciok[i].rejtett ? 'Szekció elrejtve' : 'Szekció látható'); piszkos(); elonezet(); return; }
            if (ev.target.closest('[data-dup]')) { E.szekciok.splice(i + 1, 0, JSON.parse(JSON.stringify(E.szekciok[i]))); E.aktiv = i + 1; listaRajzol([i + 1, '[data-nyit]']); urlapRajzol(); bemond('Szekció duplikálva'); piszkos(); elonezet(); return; }
            if (ev.target.closest('[data-torol]')) { E.szekciok.splice(i, 1); E.aktiv = Math.min(E.aktiv >= i ? E.aktiv - (E.aktiv > i ? 1 : 0) : E.aktiv, E.szekciok.length - 1); listaRajzol(E.szekciok.length ? [Math.max(0, i - 1), '[data-nyit]'] : null); urlapRajzol(); bemond('Szekció törölve'); piszkos(); elonezet(); }
        });
        rendezheto(lista, '.sa-szelem', '[data-fogo]', (regi, uj) => {
            const [x] = E.szekciok.splice(regi, 1);
            E.szekciok.splice(uj, 0, x);
            if (E.aktiv === regi) E.aktiv = uj; else if (E.aktiv > regi && E.aktiv <= uj) E.aktiv--; else if (E.aktiv < regi && E.aktiv >= uj) E.aktiv++;
            listaRajzol([uj, '[data-fogo]']);
            urlapRajzol();
            bemond(`Áthelyezve: ${uj + 1}. hely / ${E.szekciok.length}`);
            piszkos();
            elonezet();
        });

        $('[data-hozzaad]', hova).addEventListener('click', () => tipusValaszto((tipus) => {
            const uj = { tipus };
            (SZT[tipus].mezok || []).forEach((m) => { if (m.alap !== undefined) uj[m.k] = m.alap; });
            if (SZT[tipus].mezok.some((m) => m.k === 'cim')) uj.cim = SZT[tipus].cim;
            const hol = E.aktiv >= 0 ? E.aktiv + 1 : E.szekciok.length;
            E.szekciok.splice(hol, 0, uj);
            E.aktiv = hol;
            listaRajzol([hol, '[data-nyit]']);
            urlapRajzol();
            piszkos();
            elonezet();
        }));

        $('[data-ment]', hova).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            try {
                const r = await api('admin/oldal/' + id, { method: 'POST', body: { szekciok: E.szekciok } });
                E.szekciok = r.szekciok.map((x) => Object.assign({}, x));
                E.mentett = JSON.stringify(r.szekciok);
                E.aktiv = Math.min(E.aktiv, E.szekciok.length - 1);
                piszkos();
                listaRajzol();
                urlapRajzol();
                jelez('Oldal mentve. A korábbi állapot a Naplóból visszaállítható.');
            } catch (x) { jelez(x.message, 'hiba'); }
        }));
        $('[data-elvet]', hova).addEventListener('click', () => { E.szekciok = JSON.parse(E.mentett); E.aktiv = E.szekciok.length ? 0 : -1; piszkos(); listaRajzol(); urlapRajzol(); elonezet(); });
        $('[data-seo]', hova).addEventListener('click', () => seoPopup(id, E, (uj) => { cim(uj.cim, '<a href="#/oldalak">Oldalak</a>'); }));
        $('[data-frissit]', hova).addEventListener('click', () => elonezet());
        $('.sa-epito__oszlop--elo [data-szegmens]', hova).addEventListener('valtozas', (ev) => $('[data-elonezet]', hova).classList.toggle('is-mobil', ev.detail === 'mobil'));
        const keskeny = matchMedia('(max-width: 1280px) and (min-width: 961px)');
        const valtGombok = () => { $('[data-elo-valt]', hova).hidden = !keskeny.matches; $('[data-urlap-valt]', hova).hidden = !keskeny.matches; };
        keskeny.addEventListener('change', valtGombok);
        valtGombok();
        $('[data-elo-valt]', hova).addEventListener('click', () => { ep.classList.add('is-elo'); $('[data-urlap-valt]', hova).focus(); });
        $('[data-urlap-valt]', hova).addEventListener('click', () => { ep.classList.remove('is-elo'); $('[data-elo-valt]', hova).focus(); });
        hova.addEventListener('keydown', (ev) => { if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') { ev.preventDefault(); if (E.piszkos) $('[data-ment]', hova).click(); } });

        listaRajzol();
        urlapRajzol();
        elonezet();
    }

    function tipusValaszto(kesz) {
        const csop = {};
        Object.entries(SZT).forEach(([k, t]) => { (csop[t.csoport] = csop[t.csoport] || []).push([k, t]); });
        const { el, bezar } = popup({
            cim: 'Szekció hozzáadása',
            meret: 'szeles',
            test: `<input class="sa-input" type="search" data-szur placeholder="Szűrés (név, leírás, csoport)…" aria-label="Szekciótípus keresése" data-autofokusz><div class="sa-tipusracs sa-tipusracs--suru" data-racs>${Object.entries(csop).map(([c, l]) => l.map(([k, t]) => `<button type="button" class="sa-tipus" data-tipus="${e(k)}" title="${e(c + ' – ' + t.leiras)}" data-kereso="${e((t.cim + ' ' + t.leiras + ' ' + k + ' ' + c).toLowerCase())}">${WI(t.ikon)}<span><b>${e(t.cim)}</b><small>${e(c)} · ${e(t.leiras)}</small></span></button>`).join('')).join('')}</div>`,
        });
        $('[data-szur]', el).addEventListener('input', (ev) => {
            const q = ev.target.value.trim().toLowerCase();
            $$('[data-tipus]', el).forEach((g) => { g.hidden = !!q && !g.dataset.kereso.includes(q); });
        });
        $('[data-szur]', el).addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { const g = $$('[data-tipus]', el).find((x) => !x.hidden); if (g) g.click(); } });
        $('[data-racs]', el).addEventListener('click', (ev) => { const g = ev.target.closest('[data-tipus]'); if (!g) return; bezar(); kesz(g.dataset.tipus); });
    }

    function seoPopup(id, E, kesz) {
        const sz = (n, max) => `<span class="sa-szamlalo" data-sz="${n}" data-max="${max}" aria-hidden="true"></span>`;
        const { el, bezar } = popup({
            cim: 'SEO és oldal-beállítások',
            meret: 'kozepes',
            test: `<div class="sa-mezok">
                <div class="sa-mezo"><label class="sa-mezo__cim" for="sa-seo-c">Oldal címe</label><input class="sa-input" id="sa-seo-c" maxlength="200" value="${e(E.cim)}"></div>
                <div class="sa-mezo"><label class="sa-mezo__cim" for="sa-seo-st">Állapot</label><select class="sa-select" id="sa-seo-st">${Object.entries({ publish: 'Közzétéve', draft: 'Vázlat', private: 'Privát' }).map(([k, v]) => `<option value="${k}" ${E.statusz === k ? 'selected' : ''}>${v}</option>`).join('')}</select></div>
                <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="sa-seo-t">Keresőben megjelenő cím (title)${sz('t', 60)}</label><input class="sa-input" id="sa-seo-t" maxlength="200" value="${e(E.seo.title)}" placeholder="${e(E.cim)}" aria-describedby="sa-seo-ts"><span class="sa-mezo__sugo" id="sa-seo-ts">Ajánlott: 50–60 karakter. Tokenek: {termeknev}, {ceg}.</span></div>
                <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="sa-seo-d">Leírás (meta description)${sz('d', 160)}</label><textarea class="sa-textarea" id="sa-seo-d" rows="3" maxlength="400" aria-describedby="sa-seo-ds">${e(E.seo.description)}</textarea><span class="sa-mezo__sugo" id="sa-seo-ds">Ajánlott: 120–160 karakter.</span></div>
                <div class="sa-mezo"><label class="sa-mezo__cim" for="sa-seo-k">Fókusz-kulcsszó</label><input class="sa-input" id="sa-seo-k" maxlength="120" value="${e(E.seo.kulcsszo)}"></div>
            </div>
            <div class="sa-kartya" style="padding:14px"><div class="sa-kicsi sa-halvany">Találati előnézet</div><div data-serp-t style="color:var(--sa-kiemelo);font-size:17px;margin-top:4px" class="sa-egysor"></div><div data-serp-d class="sa-kicsi sa-halvany" style="margin-top:2px"></div></div>`,
            lab: `<button type="button" class="sa-gomb" data-bezar>Mégse</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button>`,
        });
        const t = $('#sa-seo-t', el), d = $('#sa-seo-d', el), c = $('#sa-seo-c', el);
        const frissit = () => {
            [['t', t], ['d', d]].forEach(([n, x]) => { const s = $(`[data-sz="${n}"]`, el); const max = Number(s.dataset.max); s.textContent = `${x.value.length}/${max}`; s.classList.toggle('is-sok', x.value.length > max); });
            $('[data-serp-t]', el).textContent = (t.value || c.value).replace('{termeknev}', B.termeknev);
            $('[data-serp-d]', el).textContent = (d.value || 'Nincs leírás – a kereső maga választ szöveget.').replace('{termeknev}', B.termeknev);
        };
        [t, d, c].forEach((x) => x.addEventListener('input', frissit));
        frissit();
        $('[data-ment]', el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            try {
                const r = await api('admin/oldal/' + id, { method: 'POST', body: { cim: c.value.trim(), statusz: $('#sa-seo-st', el).value, seo: { title: t.value.trim(), description: d.value.trim(), kulcsszo: $('#sa-seo-k', el).value.trim() } } });
                E.seo = r.seo; E.cim = r.cim; E.statusz = r.statusz;
                jelez('SEO és oldal-beállítások mentve.');
                bezar();
                kesz(r);
            } catch (x) { popupHiba(el, x.message); }
        }));
    }

    /* ================================================================== Elemek */

    const ES = B.elemek || {};
    const PENZ = (s) => (s.mezok || []).filter((m) => m.penz).map((m) => m.k);

    A.panel('elemek', async (hova, [tipusParam]) => {
        const tipusok = Object.keys(ES);
        const f = S.szurok.elemek = Object.assign({ tipus: tipusok[0], q: '' }, S.szurok.elemek || {});
        if (tipusParam && ES[tipusParam]) f.tipus = tipusParam;
        const sz = await api('admin/elemek');
        hova.innerHTML = `<div class="sa-ketpanel">
            <nav class="sa-kartya sa-almenu" aria-label="Elemtípusok">${tipusok.map((k) => `<button type="button" data-tipus="${e(k)}" aria-current="${k === f.tipus}">${WI(ES[k].ikon)}<span>${e(ES[k].cim)}</span><small>${A.szam((sz.szamok || {})[k] || 0)}</small></button>`).join('')}
                <hr style="border:0;border-top:1px solid var(--sa-vonal);margin:8px 4px">
                <button type="button" data-betolt="0" data-ketlepcsos="Biztos? Csak a hiányzók töltődnek be">${I('letolt')}<span>Hiányzó kezdőtartalom</span></button>
                <button type="button" data-betolt="1" data-ketlepcsos="Biztos? A nem szerkesztettek frissülnek">${I('ujra')}<span>Kezdőtartalom frissítése</span></button>
            </nav>
            <div class="sa-oszlop"><div class="sa-eszkozsor"><h2 class="sa-kartya__cim" data-cim></h2><span class="sa-ter"></span><input class="sa-input" type="search" data-q placeholder="Keresés…" aria-label="Keresés az elemek között" value="${e(f.q)}"><button type="button" class="sa-gomb sa-gomb--fo" data-uj>${I('plusz')}<span data-ujcim></span></button></div>
            <div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"></div></div></div>`;
        const lista = $('[data-lista]', hova);
        let elemek = [];
        const rajzol = () => {
            const s = ES[f.tipus];
            const penz = PENZ(s);
            const q = f.q.toLowerCase();
            const l = elemek.filter((x) => !q || (x.cim + ' ' + x.kulcs).toLowerCase().includes(q));
            $('[data-cim]', hova).textContent = s.cim;
            $('[data-ujcim]', hova).textContent = 'Új ' + s.egyes.toLowerCase();
            lista.innerHTML = l.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr>${q ? '' : '<th class="sa-fogo-cella"><span class="sa-sr">Sorrend</span></th>'}<th>${e(s.egyes)}</th><th>Kulcs</th>${penz.map((p) => `<th class="sa-jobb">${e((s.mezok.find((m) => m.k === p) || {}).c || p)}</th>`).join('')}<th>Módosítva</th><th>Látszik</th></tr></thead><tbody data-tbody>${l.map((x) => `
                <tr class="is-katt" data-kulcs="${e(x.kulcs)}" tabindex="0">${q ? '' : `<td><button type="button" class="sa-szelem__fogo" data-fogo aria-label="${e(x.cim)} áthelyezése – ↑ ↓">${I('fogo')}</button></td>`}
                <td><span class="sa-vastag">${e(x.cim || x.kulcs)}</span>${x.szerkesztve ? '' : ' <span class="sa-cimke sa-cimke--szurke" title="A kezdőtartalomból, még nem szerkesztett">alap</span>'}</td><td class="sa-mono sa-halvany">${e(x.kulcs)}</td>
                ${penz.map((p) => { const n = Number(A.kiolvas(x.adat, p)) || 0; return `<td class="sa-jobb"><div>${e(ft(n))}</div><div class="sa-kicsi sa-halvany">bruttó ${e(ft(brutto(n)))}</div></td>`; }).join('')}
                <td class="sa-halvany">${e(relativ(x.modositva))}</td><td>${kapcsolo('sa-ea-' + x.kulcs, x.aktiv, (x.cim || x.kulcs) + ' – megjelenik a webhelyen', 'data-aktiv')}</td></tr>`).join('')}</tbody></table></div>`
                : ures(q ? 'Nincs találat.' : `Még nincs ${s.egyes.toLowerCase()}.`, 'elem');
        };
        const tolt2 = async () => { elemek = (await api('admin/elemek', { query: { tipus: f.tipus } })).lista; rajzol(); };
        hova.addEventListener('click', (ev) => {
            const g = ev.target.closest('[data-tipus]');
            if (g) { f.tipus = g.dataset.tipus; $$('[data-tipus]', hova).forEach((x) => x.setAttribute('aria-current', String(x === g))); tolt2(); }
        });
        $$('[data-betolt]', hova).forEach((g) => g.addEventListener('click', () => tolt(g, async () => {
            try { const r = await api('admin/tartalom/betolt', { method: 'POST', body: { kenyszer: g.dataset.betolt === '1' } }); const n = Object.values(r.db || {}).reduce((a, b) => a + Number(b || 0), 0); jelez(`Kezdőtartalom: ${n} elem / oldal betöltve.`); Object.keys(A.elemCache).forEach((k) => delete A.elemCache[k]); tolt2(); } catch (x) { jelez(x.message, 'hiba'); }
        })));
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); rajzol(); }, 150));
        $('[data-uj]', hova).addEventListener('click', () => elemPopup(f.tipus, null, tolt2));
        lista.addEventListener('change', async (ev) => {
            const c = ev.target.closest('[data-aktiv]');
            if (!c) return;
            const x = elemek.find((y) => y.kulcs === c.closest('[data-kulcs]').dataset.kulcs);
            try { await api('admin/elem', { method: 'POST', body: { tipus: f.tipus, eredeti: x.kulcs, adat: x.adat, aktiv: c.checked } }); x.aktiv = c.checked; jelez(c.checked ? 'Megjelenik a webhelyen.' : 'Elrejtve a webhelyről.'); }
            catch (y) { c.checked = !c.checked; jelez(y.message, 'hiba'); }
        });
        sorKattint(lista, '[data-kulcs]', (s) => elemPopup(f.tipus, elemek.find((x) => x.kulcs === s.dataset.kulcs), tolt2));
        rendezheto(lista, 'tr', '[data-fogo]', async (regi, uj) => {
            const [x] = elemek.splice(regi, 1);
            elemek.splice(uj, 0, x);
            rajzol();
            const g = $$('[data-fogo]', lista)[uj];
            if (g) g.focus();
            bemond(`Áthelyezve: ${uj + 1}. hely`);
            try { await api('admin/elemek/sorrend', { method: 'POST', body: { tipus: f.tipus, kulcsok: elemek.map((y) => y.kulcs) } }); } catch (y) { jelez(y.message, 'hiba'); tolt2(); }
        });
        // A táblázat-sorrend mozgatásához a tbody a konténer.
        await tolt2();
    });

    function elemPopup(tipus, x, kesz) {
        const s = ES[tipus];
        const f = urlap(s.mezok, x ? x.adat : {}, { szerkeszt: !!x });
        const { el, bezar } = popup({
            cim: x ? `${s.egyes}: ${x.cim || x.kulcs}` : `Új ${s.egyes.toLowerCase()}`,
            meret: s.mezok.some((m) => m.t === 'html' || m.t === 'ismetlo') ? 'kozepes' : '',
            test: '',
            lab: `${x ? `<button type="button" class="sa-gomb sa-gomb--veszely" data-torol data-ketlepcsos="Biztos? Katt újra a törléshez">${I('torol')}Törlés</button>` : ''}
                <div class="sa-kapcsolosor" style="min-height:38px"><label class="sa-kapcsolosor__szoveg" for="sa-elem-aktiv">Megjelenik a webhelyen</label>${kapcsolo('sa-elem-aktiv', x ? x.aktiv : true, 'Megjelenik a webhelyen')}</div>
                <span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Mégse</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button>`,
        });
        $('.sa-popup__test', el).appendChild(f.el);
        $('[data-ment]', el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            const h = f.ellenoriz();
            if (h.length) { popupHiba(el, h.join(' ')); return; }
            try {
                const adat = Object.assign({}, x ? x.adat : {}, f.ertek());
                await api('admin/elem', { method: 'POST', body: { tipus, eredeti: x ? x.kulcs : '', adat, aktiv: $('#sa-elem-aktiv', el).checked } });
                delete A.elemCache[tipus];
                jelez('Mentve.');
                bezar();
                kesz();
            } catch (y) { popupHiba(el, y.message); }
        }));
        const t = $('[data-torol]', el);
        if (t) t.addEventListener('click', () => tolt(t, async () => {
            try { await api('admin/elem/torol', { method: 'POST', body: { tipus, kulcs: x.kulcs } }); delete A.elemCache[tipus]; jelez('Törölve – a Naplóból visszaállítható.'); bezar(); kesz(); }
            catch (y) { popupHiba(el, y.message); }
        }));
    }

    /* ================================================================== Menü és lábléc (fa-szerkesztő) */

    const MELYSEG = 3;

    A.panel('menuk', async (hova, [helyParam]) => {
        const [m, o] = await Promise.all([api('admin/menuk'), api('admin/oldalak')]);
        const helyek = m.helyek;
        const f = S.szurok.menuk = Object.assign({ hely: 'fo' }, S.szurok.menuk || {});
        if (helyParam && helyek[helyParam]) f.hely = helyParam;
        const utak = o.lista.map((x) => x.ut);
        // Fa → lapos lista mélységgel (a szerkesztéshez), és vissza.
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
        let L = lapit(helyek[f.hely].elemek);
        let mentett = JSON.stringify(L);
        S.mentetlen = () => JSON.stringify(L) !== mentett;
        hova.innerHTML = `<div class="sa-eszkozsor">${szegmens('Menühely', Object.fromEntries(Object.entries(helyek).map(([k, v]) => [k, e(v.cim)])), f.hely)}<span class="sa-ter"></span><span data-allapot aria-live="polite"></span>
            <button type="button" class="sa-gomb" data-elvet data-ketlepcsos="Biztos? Elvetem">Elvetés</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button></div>
            <div class="sa-kartya"><p class="sa-kicsi sa-halvany" style="margin:0 0 12px">A cél egy oldal útvonala (pl. <code>arak</code>) vagy cím (<code>/kosar/</code>, <code>https://…</code>, <code>#gyik</code>). A beljebb / kijjebb gomb alá- és fölérendel (legfeljebb ${MELYSEG} szint). Tokenek a feliratban: {termeknev}.</p>
            <datalist id="sa-menu-utak">${utak.map((u) => `<option value="${e(u)}">`).join('')}</datalist><ol class="sa-fa" data-fa aria-label="Menüelemek"></ol>
            <div class="sa-sor" style="margin-top:12px"><button type="button" class="sa-gomb" data-uj>${I('plusz')}Új menüpont</button></div></div>`;
        const faEl = $('[data-fa]', hova);
        const jel = () => { const p = S.mentetlen(); $('[data-allapot]', hova).innerHTML = p ? cimke('Mentetlen', 'figyel') : cimke('Mentve', 'ok'); $('[data-ment]', hova).disabled = !p; $('[data-elvet]', hova).disabled = !p; };
        const atvesz = () => { $$('.sa-fa__sor', faEl).forEach((s, i) => { L[i].cim = $('[data-cim]', s).value; L[i].cel = $('[data-cel]', s).value; L[i].uj = $('[data-ujlap]', s).checked; }); };
        const rajzol = (fokusz = null) => {
            faEl.innerHTML = L.map((x, i) => `<li class="sa-fa__sor" data-i="${i}" style="margin-left:${x.m * 28}px">
                <button type="button" class="sa-szelem__fogo" data-fogo aria-label="${e(x.cim || 'Menüpont')} áthelyezése – ↑ ↓">${I('fogo')}</button>
                <input class="sa-input sa-input--kicsi" data-cim value="${e(x.cim)}" placeholder="Felirat" aria-label="${i + 1}. menüpont felirata (${x.m + 1}. szint)" maxlength="80">
                <input class="sa-input sa-input--kicsi sa-input--mono" data-cel value="${e(x.cel)}" list="sa-menu-utak" placeholder="oldal-utvonal vagy /cím" aria-label="${i + 1}. menüpont célja">
                <span class="sa-sor" style="gap:2px">${kapcsolo('sa-mu-' + i, x.uj, `${i + 1}. menüpont – új lapon nyílik`, 'data-ujlap')}<span class="sa-kicsi sa-halvany" aria-hidden="true">új lap</span></span>
                <span class="sa-fa__gombok"><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-ki aria-label="Kijjebb" ${x.m === 0 ? 'disabled' : ''}>${I('balra')}</button><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-be aria-label="Beljebb" ${i === 0 || x.m >= MELYSEG - 1 || x.m > L[i - 1].m ? 'disabled' : ''}>${I('jobbra')}</button><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi sa-ikon-gomb--veszely" data-torol aria-label="Törlés" data-ketlepcsos="Biztos? Katt újra">${I('torol')}</button></span></li>`).join('') || `<li>${ures('A menü üres.', 'menu')}</li>`;
            jel();
            if (fokusz) { const li = faEl.children[fokusz[0]]; const g = li && $(fokusz[1], li); if (g && !g.disabled) g.focus(); else if (li) $('[data-fogo]', li).focus(); }
        };
        /** Egy elem a gyerekeivel együtt (a mozgatáshoz / törléshez). */
        const ag = (i) => { let j = i + 1; while (j < L.length && L[j].m > L[i].m) j++; return j - i; };
        faEl.addEventListener('input', () => { atvesz(); jel(); });
        faEl.addEventListener('change', () => { atvesz(); jel(); });
        faEl.addEventListener('click', (ev) => {
            const g = ev.target.closest('button');
            const li = ev.target.closest('[data-i]');
            if (!g || !li || g.hasAttribute('data-fogo')) return;
            atvesz();
            const i = Number(li.dataset.i), n = ag(i);
            if (g.hasAttribute('data-ki')) { for (let k = i; k < i + n; k++) L[k].m = Math.max(0, L[k].m - 1); rajzol([i, '[data-ki]']); bemond('Kijjebb'); }
            if (g.hasAttribute('data-be')) { for (let k = i; k < i + n; k++) L[k].m = Math.min(MELYSEG - 1, L[k].m + 1); rajzol([i, '[data-be]']); bemond('Beljebb'); }
            if (g.hasAttribute('data-torol')) { L.splice(i, n); rajzol(L.length ? [Math.max(0, i - 1), '[data-cim]'] : null); bemond('Menüpont törölve'); }
        });
        rendezheto(faEl, '.sa-fa__sor', '[data-fogo]', (regi, uj) => {
            atvesz();
            const n = ag(regi);
            const blokk = L.splice(regi, n);
            let cel = uj > regi ? uj - n + 1 : uj;
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
                const r = await api('admin/menu/' + f.hely, { method: 'POST', body: { elemek: fa(L) } });
                L = lapit(r.elemek); mentett = JSON.stringify(L); helyek[f.hely].elemek = r.elemek;
                rajzol();
                jelez('Menü mentve.');
            } catch (x) { jelez(x.message, 'hiba'); }
        }));
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => {
            if (S.mentetlen()) { jelez('Előbb mentsd vagy vesd el a változásokat.', 'figyel'); $$('[data-szegmens] button', hova).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.ertek === f.hely))); return; }
            f.hely = ev.detail; L = lapit(helyek[f.hely].elemek); mentett = JSON.stringify(L); rajzol();
        });
        rajzol();
    });

    A.parancs('Új oldal', 'oldal', () => { location.hash = '#/oldalak'; setTimeout(() => { const g = $('[data-uj]'); if (g) g.click(); }, 400); });
    A.parancs('Kezdőtartalom: hiányzók betöltése', 'letolt', () => { location.hash = '#/elemek'; });
})();
