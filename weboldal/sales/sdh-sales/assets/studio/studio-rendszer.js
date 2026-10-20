/* Stúdió – Kommunikáció › Űrlapok (mezők felirata, kötelezősége, láthatósága) és Rendszer › Revíziók és kuka
   (kukába tett oldalak, cikkek, tartalom-elemek visszaállítása; az oldalak legutóbbi revíziói). */
(() => {
    'use strict';

    const A = window.PA;
    if (!A) return;
    const { S, $, $$, e, I, api, jelez, tolt, kapcsolo, szegmens, ures, cimke, datumIdo, relativ } = A;

    /* ================================================================== Űrlapok */

    A.panel('urlapok', async (hova, [tipusParam]) => {
        let r = await api('admin/urlapok');
        const tipusok = Object.keys(r.tipusok);
        const f = S.szurok.urlapok = Object.assign({ tipus: tipusok[0] }, S.szurok.urlapok || {});
        if (tipusParam && r.tipusok[tipusParam]) f.tipus = tipusParam;
        let mentett = JSON.stringify(r.tipusok);
        hova.innerHTML = `<div class="sa-eszkozsor">${szegmens('Űrlap', Object.fromEntries(tipusok.map((k) => [k, e(r.tipusok[k].cim)])), f.tipus)}<span class="sa-ter"></span><span data-allapot></span>
            <button type="button" class="sa-gomb" data-elvet data-ketlepcsos="Biztos? Elvetem" disabled>Elvetés</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment disabled>${I('mentes')}Mentés</button></div>
            <div class="sa-kartya sa-kartya--sima"><div data-lista></div></div>
            <p class="sa-kicsi sa-halvany">A név és az e-mail-cím mindig kötelező (a válaszhoz kell). Az elrejtett mező nem jelenik meg és nem kötelező. A beérkező üzenetek a Leadek között vannak.</p>`;
        const lista = $('[data-lista]', hova);
        const T = () => r.tipusok[f.tipus];
        const jel = () => { const p = JSON.stringify(r.tipusok) !== mentett; $('[data-allapot]', hova).innerHTML = p ? cimke('Mentetlen', 'figyel') : ''; $('[data-ment]', hova).disabled = !p; $('[data-elvet]', hova).disabled = !p; };
        S.mentetlen = () => JSON.stringify(r.tipusok) !== mentett;
        const rajzol = () => {
            lista.innerHTML = `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Mező</th><th>Felirat az űrlapon</th><th>Kötelező</th><th>Látszik</th></tr></thead><tbody>${T().mezok.map((m, i) => `<tr data-i="${i}"><td><span class="sa-vastag">${e(m.cimke_alap)}</span><div class="sa-kicsi sa-halvany sa-mono">${e(m.nev)} · ${e(m.tipus)}</div></td>
                <td><input class="sa-input sa-input--kicsi" data-cimke value="${e(m.cimke)}" placeholder="${e(m.cimke_alap)}" maxlength="120" aria-label="${e(m.cimke_alap)} – felirat"></td>
                <td>${kapcsolo(`st-u-k-${f.tipus}-${i}`, m.kotelezo, `${m.cimke_alap} – kötelező`, `data-kotelezo ${m.zarolt || !m.latszik ? 'disabled' : ''}`)}</td>
                <td>${kapcsolo(`st-u-l-${f.tipus}-${i}`, m.latszik, `${m.cimke_alap} – látszik`, `data-latszik ${m.zarolt ? 'disabled' : ''}`)}</td></tr>`).join('')}</tbody></table></div>`;
            jel();
        };
        lista.addEventListener('input', (ev) => { const tr = ev.target.closest('[data-i]'); if (tr && ev.target.matches('[data-cimke]')) { T().mezok[Number(tr.dataset.i)].cimke = ev.target.value; jel(); } });
        lista.addEventListener('change', (ev) => {
            const tr = ev.target.closest('[data-i]');
            if (!tr) return;
            const m = T().mezok[Number(tr.dataset.i)];
            if (ev.target.matches('[data-kotelezo]')) m.kotelezo = ev.target.checked;
            if (ev.target.matches('[data-latszik]')) { m.latszik = ev.target.checked; if (!m.latszik) m.kotelezo = false; rajzol(); return; }
            jel();
        });
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { f.tipus = ev.detail; rajzol(); });
        $('[data-elvet]', hova).addEventListener('click', () => { r.tipusok = JSON.parse(mentett); rajzol(); });
        $('[data-ment]', hova).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            const body = {};
            Object.entries(r.tipusok).forEach(([t, x]) => { body[t] = Object.fromEntries(x.mezok.map((m) => [m.nev, { cimke: m.cimke, kotelezo: m.kotelezo, latszik: m.latszik }])); });
            try { r = await api('admin/urlapok', { method: 'POST', body: { tipusok: body } }); mentett = JSON.stringify(r.tipusok); rajzol(); jelez('Űrlapok mentve – a webhelyen azonnal élnek.'); } catch (x) { jelez(x.message, 'hiba'); }
        }));
        rajzol();
    });

    /* ================================================================== Revíziók és kuka */

    A.panel('kuka', async (hova) => {
        const f = S.szurok.kuka = Object.assign({ nezet: 'kuka' }, S.szurok.kuka || {});
        hova.innerHTML = `<div class="sa-eszkozsor">${szegmens('Nézet', { kuka: `${I('torol')}Kuka`, reviziok: `${I('elozmeny')}Legutóbbi revíziók` }, f.nezet)}</div><div data-tart></div>`;
        const tart = $('[data-tart]', hova);
        const rajzol = async () => {
            tart.innerHTML = A.csontvaz();
            if (f.nezet === 'reviziok') {
                const r = await api('admin/naplo', { query: { ref_tipus: 'szekciok', db: 40 } });
                tart.innerHTML = `<div class="sa-kartya sa-kartya--sima">${r.lista.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Mikor</th><th>Ki</th><th>Változás</th><th class="sa-jobb"><span class="sa-sr">Műveletek</span></th></tr></thead><tbody>${r.lista.map((x) => `<tr><td>${e(datumIdo(x.ido))}</td><td class="sa-halvany">${e(x.ki)}</td><td><div class="sa-egysor" style="--sa-cella-max:420px" title="${e(x.szoveg)}">${e(x.szoveg)}</div></td><td class="sa-jobb"><span class="sa-sor" style="justify-content:flex-end;gap:4px"><a class="sa-gomb sa-gomb--kicsi" href="#/oldalak/${x.ref_id}">${I('retegek')}Oldalépítő</a>${x.visszaallithato ? `<button type="button" class="sa-gomb sa-gomb--kicsi" data-naplo="${x.id}">${I('lista')}Részletek</button>` : ''}</span></td></tr>`).join('')}</tbody></table></div>` : ures('Még nincs mentett revízió.', 'elozmeny')}</div>
                    <p class="sa-kicsi sa-halvany">Egy oldal revízióit az oldalépítő „Revíziók” gombja mutatja; ott egy kattintással vissza is tölthetők.</p>`;
                return;
            }
            const r = await api('admin/kuka');
            const blokk = (cim2, l, sor) => `<div class="sa-kartya sa-kartya--sima"><div class="sa-kartya__fej" style="padding:14px 16px 0"><h2 class="sa-kartya__cim">${e(cim2)} <span class="sa-halvany">(${l.length})</span></h2></div>${l.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><tbody>${l.map(sor).join('')}</tbody></table></div>` : `<div style="padding:4px 16px 16px" class="sa-kicsi sa-halvany">Üres.</div>`}</div>`;
            const ES = A.B.elemek || {};
            tart.innerHTML = blokk('Oldalak', r.oldalak, (x) => `<tr><td class="sa-vastag">${e(x.cim || '(cím nélkül)')}</td><td class="sa-halvany">${e(x.ido ? relativ(x.ido) : '')}</td><td class="sa-jobb"><span class="sa-sor" style="justify-content:flex-end;gap:4px"><button type="button" class="sa-gomb sa-gomb--kicsi" data-poszt-vissza="${x.id}" data-ut="oldal">${I('visszaall')}Visszaállítás</button><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--veszely" data-vegleges="${x.id}" data-ketlepcsos="Biztos? Véglegesen törlöm">${I('torol')}Végleges törlés</button></span></td></tr>`)
                + blokk('Blogcikkek', r.cikkek, (x) => `<tr><td class="sa-vastag">${e(x.cim || '(cím nélkül)')}</td><td class="sa-halvany">${e(x.ido ? relativ(x.ido) : '')}</td><td class="sa-jobb"><span class="sa-sor" style="justify-content:flex-end;gap:4px"><button type="button" class="sa-gomb sa-gomb--kicsi" data-poszt-vissza="${x.id}" data-ut="blog">${I('visszaall')}Visszaállítás</button><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--veszely" data-vegleges="${x.id}" data-ketlepcsos="Biztos? Véglegesen törlöm">${I('torol')}Végleges törlés</button></span></td></tr>`)
                + blokk('Tartalom-elemek', r.elemek, (x) => `<tr><td><span class="sa-vastag">${e(x.cim)}</span> <span class="sa-halvany">· ${e((ES[x.tipus] || { egyes: x.tipus }).egyes)}</span><div class="sa-kicsi sa-halvany sa-mono">${e(x.kulcs)}</div></td><td class="sa-halvany">${e(relativ(x.ido))} · ${e(x.ki)}</td><td class="sa-jobb"><button type="button" class="sa-gomb sa-gomb--kicsi" data-elem-vissza="${x.naplo}">${I('visszaall')}Visszaállítás</button></td></tr>`);
        };
        tart.addEventListener('click', (ev) => {
            const g = ev.target.closest('[data-poszt-vissza], [data-vegleges], [data-elem-vissza], [data-naplo]');
            if (!g) return;
            if (g.dataset.naplo) { A.route && location.assign('#/naplo'); return; }
            tolt(g, async () => {
                try {
                    if (g.dataset.posztVissza) await api(`admin/${g.dataset.ut}/${g.dataset.posztVissza}/vissza`, { method: 'POST', body: {} });
                    else if (g.dataset.vegleges) await api(`admin/kuka/${g.dataset.vegleges}/vegleges`, { method: 'POST', body: {} });
                    else await api(`admin/naplo/${g.dataset.elemVissza}/visszaallit`, { method: 'POST', body: {} });
                    Object.keys(A.elemCache).forEach((k) => delete A.elemCache[k]);
                    A.oldalLista(true);
                    jelez(g.dataset.vegleges ? 'Véglegesen törölve.' : 'Visszaállítva.');
                    rajzol();
                } catch (x) { jelez(x.message, 'hiba'); }
            });
        });
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { f.nezet = ev.detail; rajzol(); });
        await rajzol();
    });
})();
