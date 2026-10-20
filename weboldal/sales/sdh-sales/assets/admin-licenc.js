/* Licencek, Telepítések, Kiadások – a Portál adminjának licenc-panelei (a beágyazott vagy különálló licencközpont adatai).
   A közös felületet (popup, űrlap, kétlépcsős gomb, lapozó…) a window.PA adja (admin.js). Külső könyvtár nélkül. */
(() => {
    'use strict';

    if (!window.PA || window.PA_LICENC) return;
    window.PA_LICENC = true;

    const { S, $, $$, e, I, api, jelez, masol, tolt, popup, popupHiba, fulek, fulHtml, urlap, kapcsolo, szegmens, lapozo, sorKattint, kesleltet, ures, cimke, ft, szam, datumIdo, relativ, rendelesPopup } = window.PA;

    /* ------------------------------------------------------------------ Közös */

    const KOD = { aktiv: ['Aktív', 'ok'], lejar: ['Hamarosan lejár', 'figyel'], lejart: ['Lejárt', 'hiba'], tiltva: ['Felfüggesztve', 'szurke'] };
    const PST = { online: ['Online', 'ok'], figyel: ['Figyelmeztet', 'figyel'], hiba: ['Hibás', 'hiba'], offline: ['Nem jelentkezik', 'hiba'], napi: ['Napi jelentés', 'info'], uj: ['Még nem jelentkezett', 'szurke'] };
    const CIKLUS = { havi: 'havi', eves: 'éves', egyszeri: 'egyszeri' };
    const JOG = { frissites: 'Frissítés', tamogatas: 'Támogatás', tavoli: 'Távoli karbantartás' };
    const RTIP = { uj: 'Új', megujitas: 'Megújítás', bovites: 'Bővítés', szolgaltatas: 'Szolgáltatás', ajanlat: 'Ajánlatkérés', proba: 'Próba', megujit: 'Megújítás', bovit: 'Bővítés' };
    const RALL = { uj: ['Új', 'szurke'], dijbekero: ['Díjbekérő', 'figyel'], fizetesre_var: ['Fizetésre vár', 'figyel'], fizetve: ['Fizetve', 'kiemelo'], kiszolgalva: ['Kiszolgálva', 'ok'], sikertelen: ['Sikertelen', 'hiba'], torolve: ['Törölve', 'szurke'], visszaterites: ['Visszatérítés', 'hiba'], ajanlat: ['Ajánlatkérés', 'info'] };
    const SZINT = { info: 'szurke', figyel: 'figyel', hiba: 'hiba', siker: 'ok' };
    const MUV_ALL = { var: ['vár', 'figyel'], kuldve: ['elküldve', 'info'], kesz: ['kész', 'ok'], hiba: ['hiba', 'hiba'], lejart: ['lejárt', 'szurke'] };

    const meret = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1).replace('.', ',')} MB` : b >= 1024 ? `${szam(b / 1024)} KB` : `${szam(b)} bájt`);
    const isoDatum = (iso) => (iso ? String(iso).slice(0, 10).replace(/-/g, '.') + '.' : '–');
    const kodCimke = (k) => { const x = KOD[k] || [k, 'szurke']; return cimke(x[0], x[1]); };
    const pstCimke = (s) => { const x = PST[s] || [s, 'szurke']; return cimke(x[0], x[1]); };
    const modSzoveg = (m, kat) => (m === '*' ? 'Minden modul' : `${(m || []).length} / ${Object.keys(kat || {}).length} modul`);
    const napSzoveg = (n) => (n === null || n === undefined ? '' : n < 0 ? `${-n} napja lejárt` : n === 0 ? 'ma jár le' : `${n} nap`);
    const lejarHtml = (l) => (l.lejar ? `${e(isoDatum(l.lejar))}<div class="sa-kicsi ${l.kod === 'lejart' ? 'pl-hiba' : l.kod === 'lejar' ? 'pl-figyel' : 'sa-halvany'}">${e(napSzoveg(l.lejar_nap))}</div>` : '<span class="sa-halvany">nincs lejárat</span>');

    // Néhány saját stílus (a közös admin.css változóival).
    const stilus = document.createElement('style');
    stilus.textContent = `
        .pl-hiba{color:var(--sa-hiba)} .pl-figyel{color:var(--sa-figyel)}
        .pl-kulcs{display:inline-flex;align-items:center;gap:8px;font-family:var(--sa-mono);font-size:15px;font-weight:600;letter-spacing:.02em}
        .pl-modulok{columns:3 230px;column-gap:22px}
        .pl-mcsop{break-inside:avoid;margin:0 0 10px}
        .pl-modulok h4{margin:0 0 2px;padding:0 8px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--sa-szoveg-3)}
        .pl-modul{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:3px 8px;border-radius:10px;font-size:13px}
        .pl-modul:hover{background:var(--sa-panel-eros)}
        .pl-link{display:flex;gap:8px;align-items:center} .pl-link .sa-input{flex:1;font-family:var(--sa-mono);font-size:12px}
        .pl-problema{display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border-radius:10px;background:var(--sa-panel-eros);margin:0 0 6px;font-size:13px}
        .pl-problema svg{width:16px;height:16px;flex:none;margin-top:2px}
        .pl-muveletek{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px}
        .pl-vevolista{max-height:220px;overflow:auto;display:flex;flex-direction:column;gap:4px;margin-top:8px}
        .pl-vevolista button{flex:none;text-align:left;justify-content:flex-start;height:auto;min-height:40px;padding:6px 12px;white-space:normal}
        .pl-vevolista button span{display:flex;flex-direction:column;align-items:flex-start;min-width:0;gap:1px}
        .pl-vevolista button small{font-weight:400;color:var(--sa-szoveg-3);overflow-wrap:anywhere}
        .pl-vevolista button[aria-pressed="true"]{border-color:var(--sa-kiemelo);box-shadow:0 0 0 1px var(--sa-kiemelo) inset}
        .sa-popup .sa-cimke--mono.pl-ugro{cursor:pointer;text-decoration:underline dotted;text-underline-offset:3px}
        .pl-eszkoz2 .sa-input[type=search]{flex:1 1 280px;max-width:460px}
        .pl-racs-fent{align-items:start}
        .pl-fajl{display:flex;align-items:center;gap:10px;flex-wrap:wrap} .pl-fajl input[type=file]{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
        .pl-fajl__nev{font-size:13px;color:var(--sa-szoveg-3);min-width:0;overflow-wrap:anywhere}
        .pl-ido{display:flex;flex-direction:column;gap:6px} .pl-ido__elem{display:grid;grid-template-columns:150px 1fr;gap:10px;font-size:13px;align-items:start}
        @media (max-width:700px){.pl-ido__elem{grid-template-columns:1fr}}
    `;
    document.head.appendChild(stilus);

    let ALL = null;
    async function allapot(friss = false) {
        if (!ALL || friss) ALL = await api('admin/licenc/allapot');
        return ALL;
    }

    function nemKezelheto(hova, a) {
        const tavoli = a.van && a.mod === 'tavoli';
        hova.innerHTML = `<div class="sa-kartya">${ures(
            tavoli ? 'Ez a webhely távoli licencközponttal dolgozik (Beállítások › Licencközpont): a licenceket ott kezeled.' : 'Ezen a webhelyen nem fut licencközpont.',
            'kulcs', tavoli ? 'A licencek a távoli központban vannak' : 'Nincs licencközpont',
        )}${tavoli && a.kozpont_cim ? `<p style="text-align:center;margin:0 0 16px"><a class="sa-gomb" href="${e(a.kozpont_cim)}" target="_blank" rel="noopener">${I('kulso')}A távoli központ megnyitása</a></p>` : ''}</div>`;
    }

    function idovonal(lista) {
        if (!lista || !lista.length) return ures('Még nincs bejegyzés.', 'naplo');
        return `<div class="pl-ido">${lista.map((x) => `<div class="pl-ido__elem"><span class="sa-halvany">${e(datumIdo(x.ido))}</span><span>${cimke(x.tipus || 'info', SZINT[x.szint] || 'szurke')} ${e(x.szoveg)}${x.ki ? ` <span class="sa-halvany">· ${e(x.ki)}</span>` : ''}</span></div>`).join('')}</div>`;
    }

    /** Egyszerű kis popup egy űrlappal; ment(ertek) → igaz: bezár. */
    function urlapPopup({ cim, mezok, adat = {}, gomb = 'Mentés', meret = 'kicsi', elotte = '', ment }) {
        const f = urlap(mezok, adat);
        const p = popup({ cim, meret, test: elotte, lab: `<span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Mégse</button><button type="button" class="sa-gomb sa-gomb--fo" data-ok>${I('pipa')}${e(gomb)}</button>` });
        $('.sa-popup__test', p.el).appendChild(f.el);
        $('[data-ok]', p.el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            if (f.ellenoriz().length) return;
            try { if (await ment(f.ertek(), p)) p.bezar(); } catch (x) { popupHiba(p.el, x.message); }
        }));
        return { ...p, f };
    }

    /* ------------------------------------------------------------------ Licencek – lista */

    window.PA.panel('licencek', async (hova, param = []) => {
        const a = await allapot(true);
        if (!a.kezelheto) { nemKezelheto(hova, a); return; }
        const f = S.szurok.licencek = Object.assign({ szuro: 'mind', q: '', rend: 'lejar', irany: 'novekvo', oldal: 1 }, S.szurok.licencek || {});
        let nyit = 0;
        if (param[0] === 'k' && param[1]) { f.q = param[1]; f.szuro = 'mind'; f.oldal = 1; }
        else if (/^\d+$/.test(param[0] || '')) nyit = Number(param[0]);
        else if (param[0] === 'vevo' && param[1]) { f.q = param[1]; f.szuro = 'mind'; f.oldal = 1; }

        const sz = a.szamok || {};
        const kpi = (k, ik, cim, al) => `<div class="sa-kartya sa-kpi is-katt" data-kpi="${k}" tabindex="0" role="button" aria-label="${e(cim)}: szűrés"><div class="sa-kpi__cimke">${I(ik)}${e(cim)}</div><div class="sa-kpi__ertek">${szam(sz[k] || 0)}</div><div class="sa-kpi__al">${e(al)}</div></div>`;
        hova.innerHTML = `
            <div class="sa-racs sa-racs--kpi">
                ${kpi('mind', 'kulcs', 'Összes licenc', 'a licencközpontban')}
                ${kpi('aktiv', 'pipa', 'Aktív', 'érvényes licenc')}
                ${kpi('lejar', 'ora', 'Hamarosan lejár', `${a.lejar_nap} napon belül`)}
                ${kpi('lejart', 'figyel', 'Lejárt', 'megújításra vár')}
                ${kpi('tiltva', 'pajzs', 'Felfüggesztve', 'kézzel letiltva')}
                ${kpi('proba', 'info', 'Próba', 'próbalicenc')}
            </div>
            <div class="sa-eszkozsor"><div data-szuro></div><span class="sa-ter"></span>
                ${a.halado_url ? `<a class="sa-gomb" href="${e(a.halado_url)}" title="A teljes licencközpont-felület (rendszerfigyelés, mérések, támogatás, beállítások)">${I('kozpont')}Haladó nézet</a>` : ''}
                <button type="button" class="sa-gomb sa-gomb--fo" data-uj>${I('plusz')}Új licenc</button>
            </div>
            <div class="sa-eszkozsor pl-eszkoz2">
                <input class="sa-input" type="search" data-q placeholder="Kulcs, cég, e-mail, adószám, domain…" aria-label="Keresés a licencek között" value="${e(f.q)}">
                <span class="sa-ter"></span>
                <select class="sa-select" data-rend aria-label="Rendezés" style="width:auto">
                    <option value="lejar">Lejárat szerint</option><option value="letrehozva">Létrehozás szerint</option><option value="vevo">Vevő szerint</option><option value="kulcs">Kulcs szerint</option><option value="peldany">Utolsó jelentkezés szerint</option>
                </select>
                <button type="button" class="sa-ikon-gomb" data-irany aria-label="Rendezés iránya">${I(f.irany === 'csokken' ? 'le' : 'fel')}</button>
            </div>
            <div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"></div>`;
        $('[data-rend]', hova).value = f.rend;
        const lista = $('[data-lista]', hova);
        const tolt2 = async () => {
            const r = await api('admin/licencek', { query: { q: f.q, szuro: f.szuro, rend: f.rend, irany: f.irany, oldal: f.oldal } });
            S.licencek = r.lista;
            const s = r.szamok || {};
            $('[data-szuro]', hova).innerHTML = szegmens('Szűrő', {
                mind: `Mind <small>${s.mind || 0}</small>`, aktiv: `Aktív <small>${s.aktiv || 0}</small>`, lejar: `Lejár <small>${s.lejar || 0}</small>`,
                lejart: `Lejárt <small>${s.lejart || 0}</small>`, tiltva: `Felfüggesztve <small>${s.tiltva || 0}</small>`, proba: `Próba <small>${s.proba || 0}</small>`,
            }, f.szuro);
            lista.innerHTML = r.lista.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Licenckulcs</th><th>Vevő</th><th>Csomag</th><th class="sa-jobb">Telepítés</th><th>Lejárat</th><th>Állapot</th></tr></thead><tbody>${r.lista.map((l, i) => `
                <tr class="is-katt" data-licenc="${i}" tabindex="0">
                    <td><span class="sa-cimke sa-cimke--mono">${e(l.kulcs)}</span>${l.proba ? ' ' + cimke('próba', 'info') : ''}</td>
                    <td><div class="sa-vastag sa-egysor" style="--sa-cella-max:220px" title="${e(l.vevo)}">${e(l.vevo || '–')}</div><div class="sa-kicsi sa-halvany sa-egysor" style="--sa-cella-max:220px" title="${e(l.vevo_email)}">${e(l.vevo_email || l.kapcsolattarto || '')}</div></td>
                    <td><div>${e(l.csomag || '–')}</div><div class="sa-kicsi sa-halvany">${e(modSzoveg(l.modulok, a.katalogus))}</div></td>
                    <td class="sa-jobb"><div>${szam(l.peldany_db)} / ${szam(l.max_peldany)}</div><div class="sa-kicsi sa-halvany">${l.utolso_jel ? e(relativ(l.utolso_jel)) : '–'}</div></td>
                    <td>${lejarHtml(l)}</td>
                    <td>${kodCimke(l.kod)}</td>
                </tr>`).join('')}</tbody></table></div>`
                : ures(f.q || f.szuro !== 'mind' ? 'Ebben a nézetben nincs licenc.' : 'Még nincs licenc. A sikeres fizetések után maguktól jönnek létre, vagy vegyél fel egyet kézzel.', 'kulcs');
            if (r.ossz > r.db) lista.appendChild(lapozo(r.ossz, r.oldal, r.db, (o) => { f.oldal = o; tolt2(); }));
        };
        hova.addEventListener('valtozas', (ev) => { if (ev.target.closest('[data-szuro]')) { f.szuro = ev.detail; f.oldal = 1; tolt2(); } });
        $$('[data-kpi]', hova).forEach((k) => {
            const fn = () => { f.szuro = k.dataset.kpi; f.oldal = 1; tolt2(); };
            k.addEventListener('click', fn);
            k.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); fn(); } });
        });
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); f.oldal = 1; tolt2(); }));
        $('[data-rend]', hova).addEventListener('change', (ev) => { f.rend = ev.target.value; f.oldal = 1; tolt2(); });
        $('[data-irany]', hova).addEventListener('click', (ev) => { f.irany = f.irany === 'csokken' ? 'novekvo' : 'csokken'; ev.currentTarget.innerHTML = I(f.irany === 'csokken' ? 'le' : 'fel'); tolt2(); });
        $('[data-uj]', hova).addEventListener('click', () => ujLicenc(a, (id) => { tolt2(); licencPopup(id, tolt2); }));
        sorKattint(lista, '[data-licenc]', (s) => licencPopup(S.licencek[Number(s.dataset.licenc)].id, tolt2));
        await tolt2();
        if (nyit) licencPopup(nyit, tolt2);
    });

    /* ------------------------------------------------------------------ Licenc – részletek */

    async function licencPopup(id, frissit = null) {
        const a = await allapot();
        let d;
        try { d = await api(`admin/licenc/${id}`); } catch (x) { jelez(x.message, 'hiba'); return; }
        let aktivFul = 'attekintes';
        const p = popup({ cim: 'Licenc', meret: 'szeles', test: '', lab: '' });
        const kat = a.katalogus || {};

        const futtat = async (muv, extra = {}) => {
            try {
                const r = await api(`admin/licenc/${id}/muvelet`, { method: 'POST', body: Object.assign({ muvelet: muv }, extra) });
                jelez(r.uzenet || 'Kész.');
                d = r;
                rajzol();
                if (frissit) frissit();
                return r;
            } catch (x) { jelez(x.message, 'hiba'); return null; }
        };
        // Gombról indítva a gomb „tölt” állapotban van (dupla kattintás ellen); popupból gomb nélkül.
        const muvelet = (g, muv, extra = {}) => (g ? tolt(g, () => futtat(muv, extra)) : futtat(muv, extra));

        function rajzol() {
            const l = d.licenc, v = d.vevo || {};
            const cimEl = $('.sa-popup__cim', p.el);
            cimEl.textContent = `${l.kulcs} · ${l.vevo || ''}`;
            cimEl.title = cimEl.textContent;
            const mod = l.modulok === '*' ? Object.keys(kat) : (l.modulok || []);
            const csop = {};
            Object.entries(kat).forEach(([k, m]) => { (csop[m.csoport || 'Egyéb'] = csop[m.csoport || 'Egyéb'] || []).push([k, m]); });
            const atte = `
                <div class="sa-sor" style="margin:0 0 14px;flex-wrap:wrap;gap:10px"><span class="pl-kulcs">${e(l.kulcs)}<button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-masol-kulcs aria-label="Kulcs másolása" title="Kulcs másolása">${I('masol')}</button></span>${kodCimke(l.kod)}${l.proba ? cimke('próba', 'info') : ''}${l.csatorna === 'teszt' ? cimke('teszt csatorna', 'figyel') : ''}</div>
                <div class="sa-racs sa-racs--fele">
                    <div class="sa-kartya" style="padding:14px"><h3 class="sa-kicsi sa-halvany" style="margin:0 0 8px;text-transform:uppercase;letter-spacing:.06em">Vevő</h3><dl class="sa-kv">
                        <dt>Cég</dt><dd>${e(v.nev || l.vevo || '–')}</dd><dt>Kapcsolattartó</dt><dd>${e(v.kapcsolattarto || '–')}</dd>
                        <dt>E-mail</dt><dd>${v.email ? `<a href="mailto:${e(v.email)}">${e(v.email)}</a>` : '–'}</dd><dt>Telefon</dt><dd>${v.telefon ? `<a href="tel:${e(v.telefon)}">${e(v.telefon)}</a>` : '–'}</dd>
                        <dt>Adószám</dt><dd>${e(v.adoszam || '–')}</dd><dt>Cím</dt><dd>${e(v.cim || '–')}</dd>${l.vevo_azonosito ? `<dt>Vevő-azonosító</dt><dd class="sa-mono">${e(l.vevo_azonosito)}</dd>` : ''}
                    </dl></div>
                    <div class="sa-kartya" style="padding:14px"><h3 class="sa-kicsi sa-halvany" style="margin:0 0 8px;text-transform:uppercase;letter-spacing:.06em">Licenc</h3><dl class="sa-kv">
                        <dt>Csomag</dt><dd>${e(l.csomag || '–')}</dd><dt>Modulok</dt><dd>${e(modSzoveg(l.modulok, kat))}</dd>
                        <dt>Telepítések</dt><dd>${szam(l.peldany_db)} / ${szam(l.max_peldany)}</dd><dt>Felhasználók</dt><dd>${l.max_felhasznalo ? szam(l.max_felhasznalo) : 'korlátlan'}</dd>
                        <dt>Díj</dt><dd>${l.dij ? `${e(ft(l.dij))} (nettó, ${e(CIKLUS[l.ciklus] || l.ciklus)})` : '–'}</dd>
                        <dt>Lejárat</dt><dd>${l.lejar ? `${e(isoDatum(l.lejar))} <span class="sa-halvany">· ${e(napSzoveg(l.lejar_nap))}</span>` : 'nincs lejárat'}</dd>
                        <dt>Jogok</dt><dd>${Object.entries(l.jogok || {}).map(([k, x]) => cimke(JOG[k] || k, x ? 'ok' : 'szurke')).join(' ')}</dd>
                        <dt>Létrehozva</dt><dd>${e(datumIdo(l.letrehozva))}</dd>
                    </dl></div>
                </div>
                ${l.uzenet ? `<div class="pl-problema" style="margin-top:12px">${I('info')}<span><b>Üzenet a vevő rendszerében:</b> ${e(l.uzenet)}</span></div>` : ''}
                ${l.megjegyzes ? `<div class="pl-problema" style="margin-top:8px">${I('naplo')}<span><b>Belső megjegyzés:</b> ${e(l.megjegyzes)}</span></div>` : ''}`;
            const modulok = `<p class="sa-halvany sa-kicsi" style="margin:0 0 6px">${l.modulok === '*' ? 'A licenc minden modult tartalmaz. Ha egyet kikapcsolsz, a többi egyenként marad bekapcsolva.' : 'A változás a vevő rendszerében a következő jelentkezéskor érvényes.'}</p>
                <div class="pl-modulok">${Object.entries(csop).map(([c, ms]) => `<div class="pl-mcsop"><h4>${e(c)}</h4>${ms.map(([k, m]) => `<div class="pl-modul"><label for="pl-m-${e(k)}">${e(m.cim)}</label>${kapcsolo('pl-m-' + k, mod.includes(k), m.cim, `data-modul="${e(k)}"`)}</div>`).join('')}</div>`).join('')}</div>`;
            const pk = d.peldanyok || [];
            const telep = pk.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla sa-tabla--suru"><thead><tr><th>Rendszer</th><th>Verzió</th><th>Állapot</th><th>Utolsó jel</th><th class="sa-jobb">Művelet</th></tr></thead><tbody>${pk.map((x) => `
                <tr class="is-katt" data-peldany="${x.id}" tabindex="0"><td><div class="sa-vastag sa-egysor" style="--sa-cella-max:240px" title="${e(x.nev)}">${e(x.nev)}</div><div class="sa-kicsi sa-halvany sa-egysor" style="--sa-cella-max:240px">${e(x.webhely || x.peldany)}</div></td>
                <td class="sa-mono">${e(x.verzio || '–')}${d.kiadas && x.verzio && x.verzio !== d.kiadas ? ` <span class="sa-halvany sa-kicsi">(legújabb: ${e(d.kiadas)})</span>` : ''}</td><td>${pstCimke(x.statusz)}</td><td class="sa-halvany">${x.utolso ? e(relativ(x.utolso)) : '–'}</td>
                <td class="sa-jobb"><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--veszely" data-levalaszt="${x.id}" data-ketlepcsos="Biztos? Leválasztom">${I('x')}Leválasztás</button></td></tr>`).join('')}</tbody></table></div>
                <p class="sa-halvany sa-kicsi">A leválasztás helyet szabadít fel a licencen. Ha a gép újra jelentkezik, újra felveszi (ha van szabad hely).</p>`
                : ures('Ehhez a licenchez még egy rendszer sem jelentkezett.', 'kozpont');
            const lt = d.letoltesek || [];
            const letolt = `<div class="sa-sor" style="margin-bottom:10px;gap:8px;flex-wrap:wrap"><span class="sa-halvany sa-kicsi">Telepítőcsomag: ${d.kiadas ? `<b>${e(d.kiadas)}</b> (a licenc csatornája szerint)` : 'még nincs feltöltött kiadás (Kiadások)'}</span><span class="sa-ter"></span>
                <button type="button" class="sa-gomb sa-gomb--kicsi" data-uj-link data-ketlepcsos="Biztos? A régi linkek érvénytelenek lesznek">${I('lanc')}Új letöltő link</button></div>
                ${lt.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla sa-tabla--suru"><thead><tr><th>Létrehozva</th><th>Forrás</th><th class="sa-jobb">Letöltve</th><th>Lejár</th><th>Utoljára</th><th>Állapot</th></tr></thead><tbody>${lt.map((t) => `
                    <tr><td>${e(datumIdo(t.letrehozva))}</td><td class="sa-mono">${e(t.rendeles === 'kezi' ? 'kézi' : t.rendeles || '–')}</td><td class="sa-jobb">${szam(t.db)} / ${szam(t.max_db)}</td><td>${e(datumIdo(t.lejar))}</td><td class="sa-halvany">${t.utolso ? e(relativ(t.utolso)) : '–'}</td><td>${cimke(t.el ? 'érvényes' : 'lejárt', t.el ? 'ok' : 'szurke')}</td></tr>`).join('')}</tbody></table></div>`
                : ures('Ehhez a licenchez még nem készült letöltő link.', 'letolt')}`;
            const rs = d.rendelesek || [];
            const rend = (rs.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla sa-tabla--suru"><thead><tr><th>Rendelés</th><th>Típus</th><th>Állapot</th><th class="sa-jobb">Bruttó</th><th>Számla</th><th>Dátum</th></tr></thead><tbody>${rs.map((x) => `
                <tr class="is-katt" data-rendeles="${x.id}" tabindex="0"><td class="sa-mono">${e(x.azon)}</td><td>${e(RTIP[x.tipus] || x.tipus)}</td><td>${cimke((RALL[x.allapot] || [x.allapot])[0], (RALL[x.allapot] || [0, 'szurke'])[1])}</td><td class="sa-jobb">${e(ft(x.brutto))}</td><td class="sa-mono sa-kicsi">${e(x.vegszamla || x.dijbekero || '–')}</td><td class="sa-halvany">${e(datumIdo(x.letrehozva))}</td></tr>`).join('')}</tbody></table></div>`
                : ures('Ehhez a licenchez nem tartozik webes rendelés (kézzel felvett licenc).', 'rendeles'))
                + ((d.kozpont_rendelesek || []).length ? `<h3 class="sa-kicsi sa-halvany" style="margin:14px 0 6px;text-transform:uppercase;letter-spacing:.06em">A licencközpont kiszolgálásai</h3><div class="pl-ido">${d.kozpont_rendelesek.map((x) => `<div class="pl-ido__elem"><span class="sa-halvany">${e(datumIdo(x.ido))}</span><span><span class="sa-mono">${e(x.rendeles)}</span> · ${e(RTIP[x.muvelet] || x.muvelet)}</span></div>`).join('')}</div>` : '');
            const naplo = idovonal(d.esemenyek);

            $('.sa-popup__test', p.el).innerHTML = fulHtml([
                ['attekintes', 'Áttekintés', atte],
                ['modulok', `Modulok <small class="sa-halvany">${l.modulok === '*' ? 'mind' : mod.length}</small>`, modulok],
                ['telepitesek', `Telepítések <small class="sa-halvany">${pk.length}</small>`, telep],
                ['letoltesek', 'Letöltések', letolt],
                ['rendelesek', `Rendelések <small class="sa-halvany">${rs.length}</small>`, rend],
                ['naplo', 'Napló', naplo],
            ], aktivFul);
            fulek($('.sa-popup__test', p.el), (k) => { aktivFul = k; });

            const lab = $('.sa-popup__lab', p.el) || (() => { const x = document.createElement('div'); x.className = 'sa-popup__lab'; p.el.appendChild(x); return x; })();
            lab.innerHTML = `
                <button type="button" class="sa-gomb" data-m="hosszabbit">${I('ora')}Hosszabbítás</button>
                <button type="button" class="sa-gomb" data-m="csomag">${I('elem')}Csomagváltás</button>
                <button type="button" class="sa-gomb" data-m="kuld">${I('email')}Kulcs küldése</button>
                <button type="button" class="sa-gomb" data-m="szerk">${I('beallitas')}Szerkesztés</button>
                <span class="sa-ter"></span>
                ${l.allapot === 'tiltva'
                    ? `<button type="button" class="sa-gomb" data-m="enged" data-ketlepcsos="Biztos? Visszaállítom">${I('ujra')}Visszaállítás</button>`
                    : `<button type="button" class="sa-gomb sa-gomb--veszely" data-m="tilt" data-ketlepcsos="Biztos? Felfüggesztem">${I('pajzs')}Felfüggesztés</button>`}
                <button type="button" class="sa-gomb sa-gomb--veszely" data-m="ujkulcs" data-ketlepcsos="Biztos? A régi kulcs megszűnik">${I('kulcs')}Új kulcs</button>
                <button type="button" class="sa-gomb" data-bezar>Bezárás</button>`;

            $('[data-masol-kulcs]', p.el)?.addEventListener('click', () => masol(l.kulcs));
            $$('[data-modul]', p.el).forEach((c) => c.addEventListener('change', async () => {
                c.disabled = true;
                const r = await muvelet(null, c.checked ? 'modul_be' : 'modul_ki', { modul: c.dataset.modul });
                if (!r) { c.checked = !c.checked; c.disabled = false; }
            }));
            sorKattint(p.el, '[data-peldany]', (s) => peldanyPopup(Number(s.dataset.peldany), async () => { d = await api(`admin/licenc/${id}`); rajzol(); }));
            sorKattint(p.el, '[data-rendeles]', (s) => rendelesPopup(Number(s.dataset.rendeles)));
            $$('[data-levalaszt]', p.el).forEach((g) => g.addEventListener('click', () => tolt(g, async () => {
                try { await api(`admin/licenc/peldany/${g.dataset.levalaszt}/levalaszt`, { method: 'POST', body: {} }); jelez('Leválasztva.'); d = await api(`admin/licenc/${id}`); rajzol(); if (frissit) frissit(); } catch (x) { jelez(x.message, 'hiba'); }
            })));
            $('[data-uj-link]', p.el)?.addEventListener('click', async (ev) => {
                const r = await muvelet(ev.currentTarget, 'letoltes_link', { regiek_le: true });
                if (r && r.link) linkPopup(r.link, r.uzenet);
            });
            $$('[data-m]', lab).forEach((g) => g.addEventListener('click', () => {
                const m = g.dataset.m;
                if (['tilt', 'enged', 'ujkulcs'].includes(m)) { muvelet(g, m); return; }
                if (m === 'hosszabbit') hosszabbitPopup(l, (be) => muvelet(null, 'hosszabbit', be), () => muvelet(null, 'lejarat_nelkul'));
                if (m === 'csomag') csomagPopup(a, l, (be) => muvelet(null, 'csomag', be));
                if (m === 'kuld') kuldPopup(l, v, (be) => muvelet(null, 'kuld', be));
                if (m === 'szerk') szerkPopup(l, async (be) => {
                    const r = await api(`admin/licenc/${id}`, { method: 'POST', body: be });
                    d = r; rajzol(); jelez('Mentve.'); if (frissit) frissit();
                    return true;
                });
            }));
        }
        rajzol();
    }

    function linkPopup(link, uzenet = '') {
        const p = popup({
            cim: 'Új letöltő link', meret: 'kicsi',
            test: `${uzenet ? `<p style="margin:0 0 10px">${e(uzenet)}</p>` : ''}<div class="pl-link"><input class="sa-input" readonly value="${e(link)}" aria-label="Letöltő link"><button type="button" class="sa-gomb" data-masol-link>${I('masol')}Másolás</button></div><p class="sa-halvany sa-kicsi">A link csak most látszik teljes egészében – másold ki, vagy küldd el a „Kulcs küldése” gombbal (az új linket készít).</p>`,
            lab: '<span class="sa-ter"></span><button type="button" class="sa-gomb sa-gomb--fo" data-bezar>Kész</button>',
        });
        $('[data-masol-link]', p.el).addEventListener('click', () => masol(link));
    }

    function hosszabbitPopup(l, kesz, nelkul) {
        let ho = 'ciklus';
        const p = urlapPopup({
            cim: 'Licenc hosszabbítása', gomb: 'Hosszabbítás',
            elotte: `<p style="margin:0 0 10px">Jelenlegi lejárat: <b>${e(l.lejar ? isoDatum(l.lejar) : 'nincs')}</b>. Lejárt licencnél a mai naptól számol.</p>
                <div class="sa-mezo sa-mezo--szeles"><span class="sa-mezo__cim">Mennyivel</span>${szegmens('Mennyivel', { ciklus: `Egy ciklus (${e(CIKLUS[l.ciklus] || l.ciklus)})`, 1: '+1 hó', 3: '+3 hó', 6: '+6 hó', 12: '+12 hó', datum: 'Dátumra' }, 'ciklus')}</div>`,
            mezok: [{ k: 'lejar', c: 'Új lejárat (ha „Dátumra”)', t: 'datum' }],
            adat: { lejar: l.lejar || '' },
            ment: async (be) => {
                const adat = ho === 'datum' ? { lejar: be.lejar || '' } : ho === 'ciklus' ? {} : { ho: Number(ho) };
                if (ho === 'datum' && !adat.lejar) throw new Error('Válassz dátumot.');
                return !!(await kesz(adat));
            },
        });
        $('[data-szegmens]', p.el).addEventListener('valtozas', (ev) => { ho = ev.detail; });
        const lab = $('.sa-popup__lab', p.el);
        if (l.lejar) {
            const g = document.createElement('button');
            g.type = 'button'; g.className = 'sa-gomb sa-gomb--szoveg';
            g.dataset.ketlepcsos = 'Biztos? Lejárat nélküli lesz';
            g.innerHTML = 'Lejárat nélkül';
            lab.prepend(g);
            g.addEventListener('click', () => tolt(g, async () => { if (await nelkul()) p.bezar(); }));
        }
    }

    function csomagPopup(a, l, kesz) {
        const cs = a.csomagok || [];
        const akt = (cs.find((c) => c.nev === l.csomag) || cs[0] || {}).kulcs || '';
        urlapPopup({
            cim: 'Csomagváltás', gomb: 'Váltás',
            elotte: `<p style="margin:0 0 10px">Jelenlegi csomag: <b>${e(l.csomag || '–')}</b>.</p>`,
            mezok: [
                { k: 'csomag', c: 'Új csomag', t: 'valaszto', o: Object.fromEntries(cs.map((c) => [c.kulcs, `${c.nev} – ${c.modulok === '*' ? 'minden modul' : c.modulok.length + ' modul'}, ${c.max_peldany} telepítés${c.max_felhasznalo ? ', ' + c.max_felhasznalo + ' felhasználó' : ''}`])) },
                { k: 'modulok_is', c: 'A modulokat és a kereteket is a csomaghoz igazítsa', t: 'kapcsolo', alap: true, s: 'Kikapcsolva csak a csomag neve változik.' },
            ],
            adat: { csomag: akt, modulok_is: true },
            ment: async (be) => !!(await kesz({ csomag: be.csomag, modulok_is: !!be.modulok_is })),
        });
    }

    function kuldPopup(l, v, kesz) {
        urlapPopup({
            cim: 'Licenckulcs küldése', gomb: 'Küldés',
            elotte: `<p style="margin:0 0 10px">A vevő megkapja a kulcsot, a központ címét, a csomagot és a lejáratot.</p>`,
            mezok: [
                { k: 'email', c: 'Címzett', t: 'email', kotelezo: true },
                { k: 'link', c: 'Új letöltő link is (14 napig, 3 letöltésre)', t: 'kapcsolo', alap: true },
            ],
            adat: { email: v.email || '', link: true },
            ment: async (be) => !!(await kesz({ email: be.email, link: !!be.link })),
        });
    }

    function szerkMezok() {
        return [
            { k: 'csomag', c: 'Csomag neve', t: 'szoveg', max: 60, fel: true, ful: 'Licenc' },
            { k: 'vevo_azonosito', c: 'Vevő-azonosító (belső)', t: 'szoveg', max: 60, fel: true, ful: 'Licenc' },
            { k: 'max_peldany', c: 'Telepítések (gépek) száma', t: 'szam', fel: true, ful: 'Licenc', s: '1–99' },
            { k: 'max_felhasznalo', c: 'Felhasználók száma', t: 'szam', fel: true, ful: 'Licenc', s: '0 = korlátlan' },
            { k: 'lejar', c: 'Lejárat', t: 'datum', fel: true, ful: 'Licenc', s: 'Üresen: nincs lejárat' },
            { k: 'csatorna', c: 'Frissítési csatorna', t: 'valaszto', o: { stabil: 'Stabil', teszt: 'Teszt (előzetes kiadások)' }, fel: true, ful: 'Licenc' },
            { k: 'proba', c: 'Próbalicenc', t: 'kapcsolo', ful: 'Licenc' },
            { k: 'dij', c: 'Díj (nettó Ft)', t: 'szam', penz: true, fel: true, ful: 'Számlázás' },
            { k: 'ciklus', c: 'Ciklus', t: 'valaszto', o: CIKLUS, fel: true, ful: 'Számlázás' },
            { k: 'esedekes', c: 'Következő esedékesség', t: 'datum', fel: true, ful: 'Számlázás' },
            { k: 'jogok.frissites', c: 'Frissítéseket kap', t: 'kapcsolo', alap: true, ful: 'Jogok' },
            { k: 'jogok.tamogatas', c: 'Támogatás jár (belépő link)', t: 'kapcsolo', alap: true, ful: 'Jogok' },
            { k: 'jogok.tavoli', c: 'Távoli karbantartás (mentés, diagnosztika, frissítés)', t: 'kapcsolo', alap: true, ful: 'Jogok' },
            { k: 'uzenet', c: 'Üzenet a vevő rendszerében', t: 'szoveg', max: 255, ful: 'Megjegyzés', s: 'A vevő adminisztrátorai látják (pl. „A licenc hamarosan lejár”). Üresen: nincs üzenet.' },
            { k: 'megjegyzes', c: 'Belső megjegyzés', t: 'hosszu', max: 2000, ful: 'Megjegyzés', s: 'Csak itt látszik.' },
        ];
    }

    function szerkPopup(l, ment) {
        urlapPopup({
            cim: `Licenc szerkesztése – ${l.kulcs}`, meret: 'kozepes', mezok: szerkMezok(),
            adat: Object.assign({}, l, { jogok: Object.assign({}, l.jogok) }),
            ment: async (be) => {
                const adat = Object.assign({}, be);
                adat.max_peldany = Number(adat.max_peldany || 1);
                adat.max_felhasznalo = Number(adat.max_felhasznalo || 0);
                adat.dij = Number(adat.dij || 0);
                return ment(adat);
            },
        });
    }

    /* ------------------------------------------------------------------ Új licenc */

    function ujLicenc(a, kesz) {
        const cs = a.csomagok || [];
        let vevo = null;
        const p = popup({
            cim: 'Új licenc', meret: 'szeles',
            test: `<div class="sa-racs sa-racs--fele pl-racs-fent"><div class="sa-kartya" style="padding:14px"><div class="sa-sor" style="gap:8px;flex-wrap:wrap">${szegmens('Vevő', { meglevo: 'Meglévő vevő', uj: 'Új vevő' }, 'meglevo')}</div>
                <div data-vevo-meglevo style="margin-top:10px"><input class="sa-input" type="search" data-vq placeholder="Keresés: cég, e-mail, adószám…" aria-label="Vevő keresése"><div class="pl-vevolista" data-vlista role="listbox" aria-label="Vevők"></div></div>
                <div data-vevo-uj hidden style="margin-top:10px"></div></div>
                <div data-licenc-urlap></div></div>
                <p class="sa-halvany sa-kicsi" style="margin:10px 0 0">A modulokat, a jogokat és a belső megjegyzést létrehozás után a licenc részleteinél állíthatod.</p>`,
            lab: '<span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Mégse</button><button type="button" class="sa-gomb sa-gomb--fo" data-ok>' + I('pipa') + 'Licenc létrehozása</button>',
        });
        const vf = urlap([
            { k: 'nev', c: 'Cég neve', t: 'szoveg', max: 190, kotelezo: true },
            { k: 'kapcsolattarto', c: 'Kapcsolattartó', t: 'szoveg', max: 190, fel: true },
            { k: 'email', c: 'E-mail', t: 'email', max: 190, fel: true },
            { k: 'telefon', c: 'Telefon', t: 'szoveg', max: 60, fel: true },
            { k: 'adoszam', c: 'Adószám', t: 'szoveg', max: 40, fel: true },
            { k: 'cim', c: 'Cím', t: 'szoveg', max: 255 },
        ], {});
        $('[data-vevo-uj]', p.el).appendChild(vf.el);
        const elso = cs[0] || { kulcs: '', max_peldany: 1, max_felhasznalo: 0 };
        const lf = urlap([
            { k: 'csomag', c: 'Csomag', t: 'valaszto', o: Object.assign({ '': 'Egyedi (minden modul)' }, Object.fromEntries(cs.map((c) => [c.kulcs, c.nev]))), fel: true },
            { k: 'lejar', c: 'Lejárat', t: 'datum', fel: true, s: 'Üresen: nincs lejárat' },
            { k: 'max_peldany', c: 'Telepítések száma', t: 'szam', fel: true },
            { k: 'max_felhasznalo', c: 'Felhasználók száma', t: 'szam', fel: true, s: '0 = korlátlan' },
            { k: 'ciklus', c: 'Ciklus', t: 'valaszto', o: CIKLUS, fel: true },
            { k: 'dij', c: 'Díj (nettó Ft)', t: 'szam', penz: true, fel: true },
            { k: 'proba', c: 'Próbalicenc', t: 'kapcsolo' },
            { k: 'kuld', c: 'Kulcs elküldése a vevőnek e-mailben', t: 'kapcsolo', alap: true },
            { k: 'link', c: 'Letöltő linkkel együtt', t: 'kapcsolo', alap: true },
        ], { csomag: elso.kulcs, max_peldany: elso.max_peldany, max_felhasznalo: elso.max_felhasznalo, ciklus: 'eves', dij: 0, kuld: true, link: true, lejar: '' });
        $('[data-licenc-urlap]', p.el).appendChild(lf.el);
        // Csomagválasztáskor a keretek a csomag alapértékeire állnak.
        const csSel = $('[data-mezo="csomag"] select', lf.el);
        csSel?.addEventListener('change', () => {
            const c = cs.find((x) => x.kulcs === csSel.value);
            if (!c) return;
            const mp = $('[data-mezo="max_peldany"] input', lf.el), mf = $('[data-mezo="max_felhasznalo"] input', lf.el);
            if (mp) mp.value = c.max_peldany;
            if (mf) mf.value = c.max_felhasznalo;
        });
        let mod = 'meglevo';
        $('[data-szegmens]', p.el).addEventListener('valtozas', (ev) => {
            mod = ev.detail;
            $('[data-vevo-meglevo]', p.el).hidden = mod !== 'meglevo';
            $('[data-vevo-uj]', p.el).hidden = mod !== 'uj';
        });
        const vlista = $('[data-vlista]', p.el);
        const keres = async (q) => {
            try {
                const r = await api('admin/licenc/vevok', { query: { q } });
                vlista.innerHTML = r.lista.length ? r.lista.map((x) => `<button type="button" class="sa-gomb" role="option" data-vid="${x.id}" aria-pressed="${vevo === x.id}"><span><b>${e(x.nev)}</b><small>${e([x.email, x.adoszam].filter(Boolean).join(' · ') || '–')}</small></span></button>`).join('')
                    : `<p class="sa-halvany sa-kicsi" style="margin:4px 0">Nincs ilyen vevő – válts „Új vevő”-re.</p>`;
            } catch (x) { vlista.innerHTML = `<p class="sa-kicsi">${e(x.message)}</p>`; }
        };
        $('[data-vq]', p.el).addEventListener('input', kesleltet((ev) => keres(ev.target.value.trim())));
        vlista.addEventListener('click', (ev) => {
            const g = ev.target.closest('[data-vid]');
            if (!g) return;
            vevo = Number(g.dataset.vid);
            $$('[data-vid]', vlista).forEach((x) => x.setAttribute('aria-pressed', String(x === g)));
        });
        keres('');
        $('[data-ok]', p.el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            if (mod === 'meglevo' && !vevo) { popupHiba(p.el, 'Válassz vevőt a listából, vagy válts „Új vevő”-re.'); return; }
            if (mod === 'uj' && vf.ellenoriz().length) return;
            if (lf.ellenoriz().length) return;
            const be = lf.ertek();
            const c = cs.find((x) => x.kulcs === be.csomag);
            const body = {
                csomag: c ? c.nev : 'Egyedi', modulok: c ? c.modulok : '*', lejar: be.lejar || '',
                max_peldany: Number(be.max_peldany || 1), max_felhasznalo: Number(be.max_felhasznalo || 0),
                ciklus: be.ciklus, dij: Number(be.dij || 0), proba: !!be.proba, megjegyzes: be.megjegyzes || '',
                kuld: !!be.kuld, link: !!be.link,
            };
            if (mod === 'meglevo') body.vevo_id = vevo; else body.vevo = vf.ertek();
            try {
                const r = await api('admin/licenc', { method: 'POST', body });
                jelez(r.uzenet || 'A licenc elkészült.');
                ALL = null;
                p.bezar();
                if (kesz) kesz(r.licenc.id);
            } catch (x) { popupHiba(p.el, x.message); }
        }));
    }

    /* ------------------------------------------------------------------ Telepítések */

    window.PA.panel('telepitesek', async (hova) => {
        const a = await allapot(true);
        if (!a.kezelheto) { nemKezelheto(hova, a); return; }
        const f = S.szurok.telepitesek = Object.assign({ szuro: 'mind', q: '', oldal: 1 }, S.szurok.telepitesek || {});
        hova.innerHTML = `<div class="sa-eszkozsor"><div data-szuro></div><span class="sa-ter"></span><input class="sa-input" type="search" data-q placeholder="Rendszer, cég, kulcs, verzió, domain…" aria-label="Keresés a telepítések között" value="${e(f.q)}" style="max-width:320px">${a.halado_url ? `<a class="sa-gomb" href="${e(a.halado_url)}#/rendszerek">${I('kozpont')}Rendszerfigyelés</a>` : ''}</div>
            <div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"></div>`;
        const lista = $('[data-lista]', hova);
        const tolt2 = async () => {
            const r = await api('admin/licenc/peldanyok', { query: { q: f.q, szuro: f.szuro, oldal: f.oldal } });
            S.telepitesek = r.lista;
            const s = r.szamok || {};
            $('[data-szuro]', hova).innerHTML = szegmens('Szűrő', { mind: `Mind <small>${s.mind || 0}</small>`, online: `Jelentkezik <small>${s.online || 0}</small>`, offline: `Nem jelentkezik <small>${s.offline || 0}</small>`, hiba: `Gondban <small>${s.hiba || 0}</small>`, regi: `Régi verzió <small>${s.regi || 0}</small>` }, f.szuro);
            lista.innerHTML = r.lista.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Rendszer</th><th>Licenc</th><th>Verzió</th><th>Állapot</th><th>Utolsó jel</th><th class="sa-jobb">Válaszidő</th></tr></thead><tbody>${r.lista.map((x, i) => `
                <tr class="is-katt" data-peldany="${i}" tabindex="0"><td><div class="sa-vastag sa-egysor" style="--sa-cella-max:240px" title="${e(x.nev)}">${e(x.nev)}</div><div class="sa-kicsi sa-halvany sa-egysor" style="--sa-cella-max:240px" title="${e(x.webhely || x.vevo)}">${e(x.webhely || x.vevo)}</div></td>
                <td><span class="sa-cimke sa-cimke--mono">${e(x.kulcs || '–')}</span></td><td class="sa-mono">${e(x.verzio || '–')}${x.regi ? ' ' + cimke('frissíthető', 'figyel') : ''}</td><td>${pstCimke(x.statusz)}${(x.problemak || []).length ? `<div class="sa-kicsi sa-halvany sa-egysor" style="--sa-cella-max:200px" title="${e(x.problemak.map((y) => y.szoveg).join(' · '))}">${e(x.problemak[0].szoveg)}</div>` : ''}</td>
                <td class="sa-halvany">${x.utolso ? e(relativ(x.utolso)) : '–'}</td><td class="sa-jobb">${x.valasz_ms ? szam(x.valasz_ms) + ' ms' : '–'}</td></tr>`).join('')}</tbody></table></div>`
                : ures(f.q || f.szuro !== 'mind' ? 'Ebben a nézetben nincs telepítés.' : 'Még egy rendszer sem jelentkezett. A vevő a központ címével és a licenckulccsal aktiválja (Beállítások › Licenc).', 'kozpont');
            if (r.ossz > r.db) lista.appendChild(lapozo(r.ossz, r.oldal, r.db, (o) => { f.oldal = o; tolt2(); }));
        };
        hova.addEventListener('valtozas', (ev) => { if (ev.target.closest('[data-szuro]')) { f.szuro = ev.detail; f.oldal = 1; tolt2(); } });
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); f.oldal = 1; tolt2(); }));
        sorKattint(lista, '[data-peldany]', (s) => peldanyPopup(S.telepitesek[Number(s.dataset.peldany)].id, tolt2));
        await tolt2();
    });

    async function peldanyPopup(id, frissit = null) {
        let d;
        try { d = await api(`admin/licenc/peldany/${id}`); } catch (x) { jelez(x.message, 'hiba'); return; }
        const p = popup({ cim: 'Telepítés', meret: 'szeles', test: '', lab: '' });
        let aktivFul = 'attekintes';
        const rajzol = () => {
            const x = d.p, l = d.licenc, v = d.vevo || {};
            const cimEl = $('.sa-popup__cim', p.el);
            cimEl.textContent = x.nev; cimEl.title = x.nev;
            const tilt = x.mod === 'napi' || !(l && l.jogok && l.jogok.tavoli) || !x.tavoli;
            const ok = !l ? 'Nincs licenc.' : x.mod === 'napi' ? 'Régi verzió: a távoli műveletekhez frissíteni kell.' : !l.jogok.tavoli ? 'A licenc nem tartalmaz távoli karbantartást (Licenc › Szerkesztés › Jogok).' : !x.tavoli ? 'A vevő kikapcsolta a távoli karbantartást.' : '';
            const s = d.allapot || {};
            const atte = `<div class="sa-racs sa-racs--fele">
                <div class="sa-kartya" style="padding:14px"><dl class="sa-kv">
                    <dt>Állapot</dt><dd>${pstCimke(x.statusz)}</dd><dt>Vevő</dt><dd>${e((v && v.nev) || x.vevo || '–')}</dd>
                    <dt>Licenc</dt><dd>${l ? `<span class="sa-cimke sa-cimke--mono pl-ugro" data-licenc-ugro="${l.id}" role="link" tabindex="0">${e(l.kulcs)}</span>` : '–'}</dd>
                    <dt>Verzió</dt><dd class="sa-mono">${e(x.verzio || '–')}${d.legujabb && x.verzio && x.verzio !== d.legujabb ? ` <span class="sa-halvany">(legújabb: ${e(d.legujabb)})</span>` : ''}</dd>
                    <dt>Utolsó jel</dt><dd>${x.utolso ? e(datumIdo(x.utolso)) + ` <span class="sa-halvany">· ${e(relativ(x.utolso))}</span>` : '–'}</dd>
                    <dt>Első jel</dt><dd>${e(datumIdo(x.elso))}</dd><dt>Válaszidő</dt><dd>${x.valasz_ms ? szam(x.valasz_ms) + ' ms' : '–'}</dd>
                    <dt>PHP</dt><dd>${e(x.php || '–')}</dd><dt>Felhasználók</dt><dd>${szam(x.felhasznalo)}</dd><dt>Munkalapok</dt><dd>${szam(x.munkalap)} <span class="sa-halvany">(ma ${szam(x.munkalap_ma)})</span></dd>
                </dl></div>
                <div class="sa-kartya" style="padding:14px"><h3 class="sa-kicsi sa-halvany" style="margin:0 0 8px;text-transform:uppercase;letter-spacing:.06em">Problémák</h3>
                    ${(x.problemak || []).length ? x.problemak.map((y) => `<div class="pl-problema">${I(y.szint === 'hiba' ? 'figyel' : 'info')}<span>${e(y.szoveg)}</span></div>`).join('') : `<p class="sa-halvany" style="margin:0">Nincs ismert probléma.</p>`}
                    <div class="sa-mezok" style="margin-top:12px"><div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="pl-cimke">Címke</label><input class="sa-input" id="pl-cimke" maxlength="120" value="${e(x.cimke)}" placeholder="pl. Belvárosi bolt"></div>
                    <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="pl-jegyzet">Jegyzet</label><textarea class="sa-textarea" id="pl-jegyzet" rows="2">${e(d.jegyzet || '')}</textarea></div></div>
                    <div class="sa-sor" style="justify-content:flex-end;margin-top:8px"><button type="button" class="sa-gomb sa-gomb--kicsi" data-ment>${I('mentes')}Mentés</button></div>
                </div></div>
                ${s && s.rendszer ? '' : ''}`;
            const muv = `${ok ? `<div class="pl-problema">${I('info')}<span>${e(ok)}</span></div>` : ''}
                <p class="sa-halvany sa-kicsi">A rendszer a következő jelentkezéskor (élő követésben fél percen belül) végrehajtja.</p>
                <div class="pl-muveletek">${Object.entries(d.muveletek_lista || {}).map(([k, c]) => {
                    const mindig = ['uzenet', 'uzenet_torol', 'tamogatas_keres'].includes(k);
                    const ki = mindig ? x.mod === 'napi' || (k === 'tamogatas_keres' && !(l && l.jogok.tamogatas)) : tilt;
                    return `<button type="button" class="sa-gomb" data-muv="${e(k)}" ${ki ? 'disabled' : ''} ${k === 'frissites_telepit' ? 'data-ketlepcsos="Biztos? Mentés, majd frissítés"' : ''}>${e(c)}</button>`;
                }).join('')}</div>
                <h3 class="sa-kicsi sa-halvany" style="margin:16px 0 6px;text-transform:uppercase;letter-spacing:.06em">Előzmények</h3>
                ${(d.muveletek || []).length ? `<div class="pl-ido">${d.muveletek.map((m) => `<div class="pl-ido__elem"><span class="sa-halvany">${e(datumIdo(m.letrehozva))}</span><span>${e(m.cim)} ${cimke((MUV_ALL[m.allapot] || [m.allapot])[0], (MUV_ALL[m.allapot] || [0, 'szurke'])[1])}${m.ki ? ` <span class="sa-halvany">· ${e(m.ki)}</span>` : ''}${m.eredmeny && m.eredmeny.uzenet ? `<br><span class="sa-kicsi">${e(m.eredmeny.uzenet)}</span>` : ''}</span></div>`).join('')}</div>` : ures('Még nem volt távoli művelet.', 'ora')}`;
            const tam = (d.tamogatas || []).filter((t) => t.el);
            const tamHtml = tam.length ? tam.map((t) => `<div class="pl-link" style="margin-bottom:8px"><input class="sa-input" readonly value="${e(t.link)}" aria-label="Belépő link"><a class="sa-gomb sa-gomb--fo" href="${e(t.link)}" target="_blank" rel="noopener noreferrer">${I('kulso')}Belépés</a><button type="button" class="sa-gomb" data-masol="${e(t.link)}">${I('masol')}</button></div><p class="sa-halvany sa-kicsi" style="margin:-4px 0 10px">Engedélyezte: ${e(t.ki)} · lejár ${e(datumIdo(t.lejar))}</p>`).join('')
                : '<p class="sa-halvany">Most nincs érvényes belépő link. Kérj hozzáférést a Műveletek fülön („Támogatási hozzáférés kérése”): ha a vevő engedélyezi, a link ide érkezik.</p>';
            $('.sa-popup__test', p.el).innerHTML = fulHtml([
                ['attekintes', 'Áttekintés', atte],
                ['muveletek', 'Távoli műveletek', muv],
                ['tamogatas', 'Támogatás', tamHtml],
                ['naplo', 'Napló', idovonal(d.esemenyek)],
            ], aktivFul);
            fulek($('.sa-popup__test', p.el), (k) => { aktivFul = k; });
            const lab = $('.sa-popup__lab', p.el) || (() => { const y = document.createElement('div'); y.className = 'sa-popup__lab'; p.el.appendChild(y); return y; })();
            lab.innerHTML = `<button type="button" class="sa-gomb sa-gomb--veszely" data-levalaszt data-ketlepcsos="Biztos? Leválasztom">${I('x')}Leválasztás a licencről</button><span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Bezárás</button>`;
            $('[data-ment]', p.el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
                try { await api(`admin/licenc/peldany/${id}`, { method: 'POST', body: { cimke: $('#pl-cimke', p.el).value, jegyzet: $('#pl-jegyzet', p.el).value } }); jelez('Mentve.'); d = await api(`admin/licenc/peldany/${id}`); rajzol(); if (frissit) frissit(); } catch (y) { jelez(y.message, 'hiba'); }
            }));
            $$('[data-masol]', p.el).forEach((g) => g.addEventListener('click', () => masol(g.dataset.masol)));
            const ugro = $('[data-licenc-ugro]', p.el);
            if (ugro) {
                const nyit = () => { p.bezar(); location.hash = '#/licencek/' + ugro.dataset.licencUgro; };
                ugro.addEventListener('click', nyit);
                ugro.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') nyit(); });
            }
            $$('[data-muv]', p.el).forEach((g) => g.addEventListener('click', () => {
                if (g.classList.contains('is-elesitve')) return;
                const tipus = g.dataset.muv;
                const kuld = async (parameter = {}) => {
                    try { const r = await api(`admin/licenc/peldany/${id}/muvelet`, { method: 'POST', body: { tipus, parameter } }); jelez(r.uzenet || 'Elküldve.'); d = await api(`admin/licenc/peldany/${id}`); rajzol(); return true; } catch (y) { jelez(y.message, 'hiba'); return false; }
                };
                if (tipus === 'uzenet') {
                    urlapPopup({ cim: 'Üzenet a vevőnek', gomb: 'Küldés', mezok: [{ k: 'szoveg', c: 'Szöveg', t: 'hosszu', max: 500, kotelezo: true, s: 'A vevő adminisztrátorai sávként látják a rendszer tetején, amíg le nem veszed.' }, { k: 'szint', c: 'Jelleg', t: 'valaszto', o: { info: 'Tájékoztatás', figyel: 'Figyelmeztetés', hiba: 'Sürgős' } }], adat: { szint: 'info' }, ment: (be) => kuld(be) });
                    return;
                }
                if (tipus === 'tamogatas_keres') {
                    urlapPopup({ cim: 'Támogatási hozzáférés kérése', gomb: 'Kérés elküldése', elotte: '<p style="margin:0 0 10px" class="sa-halvany">A vevő adminisztrátora egy sávot lát: „Engedélyezem” vagy „Elutasítom”. Ha engedélyezi, ideiglenes fiók jön létre, és a belépő link ide érkezik.</p>', mezok: [{ k: 'ora', c: 'Időtartam', t: 'valaszto', o: { 2: '2 óra', 24: '1 nap', 72: '3 nap' } }, { k: 'indok', c: 'Miért kéred?', t: 'szoveg', max: 200, s: 'A vevő ezt látja a kérésben.' }], adat: { ora: '2' }, ment: (be) => kuld(be) });
                    return;
                }
                tolt(g, () => kuld());
            }));
            $('[data-levalaszt]', lab).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
                try { await api(`admin/licenc/peldany/${id}/levalaszt`, { method: 'POST', body: {} }); jelez('Leválasztva.'); p.bezar(); if (frissit) frissit(); } catch (y) { jelez(y.message, 'hiba'); }
            }));
        };
        rajzol();
    }

    /* ------------------------------------------------------------------ Kiadások */

    window.PA.panel('kiadasok', async (hova) => {
        const a = await allapot(true);
        if (!a.kezelheto) { nemKezelheto(hova, a); return; }
        const rajz = async () => {
            const r = await api('admin/licenc/kiadasok');
            hova.innerHTML = `<div class="sa-racs sa-racs--fele pl-racs-fent">
                <div class="sa-kartya" style="padding:16px"><h3 style="margin:0 0 4px;font-size:15px">Új kiadás feltöltése</h3><p class="sa-halvany sa-kicsi" style="margin:0 0 12px">A vevői zip (benne a program fő fájlja verziófejléccel). A stabil csatornára töltött kiadást minden vevő megkapja; a teszt csatornát csak a teszt-licencek.</p>
                    <div class="sa-mezok"><div class="sa-mezo sa-mezo--szeles"><span class="sa-mezo__cim" id="pl-zip-c">Zip-fájl</span><div class="pl-fajl"><input id="pl-zip" type="file" accept=".zip,application/zip" aria-labelledby="pl-zip-c"><label class="sa-gomb" for="pl-zip" tabindex="0" data-fajl-gomb>${I('feltolt')}Zip kiválasztása</label><span class="pl-fajl__nev" data-fajl-nev>Nincs kiválasztva</span></div></div>
                    <div class="sa-mezo sa-mezo--szeles"><span class="sa-mezo__cim">Csatorna</span>${szegmens('Csatorna', { stabil: 'Stabil', teszt: 'Teszt' }, 'stabil')}</div>
                    <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="pl-megj">Megjegyzés</label><textarea class="sa-textarea" id="pl-megj" rows="2" maxlength="2000" placeholder="Mi változott?"></textarea></div></div>
                    <div class="sa-sor" style="justify-content:flex-end;margin-top:10px"><button type="button" class="sa-gomb sa-gomb--fo" data-feltolt>${I('feltolt')}Feltöltés</button></div></div>
                <div class="sa-kartya" style="padding:16px"><dl class="sa-kv"><dt>Stabil</dt><dd class="sa-mono">${e(r.stabil || '–')}</dd><dt>Teszt</dt><dd class="sa-mono">${e(r.teszt || '–')}</dd>
                    <dt>Verziók a vevőknél</dt><dd>${Object.entries(r.verziok || {}).map(([v, db]) => `<span class="sa-cimke sa-cimke--mono">${e(v || '?')}</span> ${szam(db)}`).join(' &nbsp; ') || '–'}</dd></dl></div></div>
                <div class="sa-kartya sa-kartya--sima" style="margin-top:16px">${r.lista.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Verzió</th><th>Csatorna</th><th class="sa-jobb">Méret</th><th>Feltöltve</th><th>Megjegyzés</th><th class="sa-jobb">Vevőknél</th><th class="sa-jobb">Művelet</th></tr></thead><tbody>${r.lista.map((k) => `
                    <tr><td class="sa-mono sa-vastag">${e(k.verzio)}${k.van ? '' : ' ' + cimke('fájl hiányzik', 'hiba')}</td><td>${cimke(k.csatorna === 'teszt' ? 'teszt' : 'stabil', k.csatorna === 'teszt' ? 'figyel' : 'ok')}</td><td class="sa-jobb">${e(meret(k.meret))}</td><td>${e(datumIdo(k.ido))}<div class="sa-kicsi sa-halvany">${e(k.ki || '')}</div></td>
                    <td><div class="sa-egysor" style="--sa-cella-max:260px" title="${e(k.megjegyzes)}">${e(k.megjegyzes || '–')}</div></td><td class="sa-jobb">${szam(k.peldany_db)}</td>
                    <td class="sa-jobb"><span class="sa-sor" style="justify-content:flex-end;gap:6px">${k.csatorna === 'teszt' ? `<button type="button" class="sa-gomb sa-gomb--kicsi" data-csat="${k.id}" data-ertek="stabil" data-ketlepcsos="Biztos? Mindenki megkapja">Kiadás mindenkinek</button>` : `<button type="button" class="sa-gomb sa-gomb--kicsi" data-csat="${k.id}" data-ertek="teszt">Vissza tesztre</button>`}<button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi sa-ikon-gomb--veszely" data-torol="${k.id}" aria-label="Kiadás törlése" data-ketlepcsos="Biztos? Katt újra a törléshez">${I('torol')}</button></span></td></tr>`).join('')}</tbody></table></div>`
                    : ures('Még nincs feltöltött kiadás. Amíg nincs, a vevők letöltő linkje nem működik.', 'feltolt')}</div>`;
            let csat = 'stabil';
            $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { csat = ev.detail; });
            const fi = $('#pl-zip', hova);
            fi.addEventListener('change', () => { $('[data-fajl-nev]', hova).textContent = fi.files[0] ? `${fi.files[0].name} (${meret(fi.files[0].size)})` : 'Nincs kiválasztva'; });
            $('[data-fajl-gomb]', hova).addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); fi.click(); } });
            $('[data-feltolt]', hova).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
                const fajl = $('#pl-zip', hova).files[0];
                if (!fajl) { jelez('Válassz zip-fájlt.', 'figyel'); return; }
                const fd = new FormData();
                fd.append('zip', fajl); fd.append('csatorna', csat); fd.append('megjegyzes', $('#pl-megj', hova).value);
                try {
                    const v = await fetch(window.PA.B.rest + 'admin/licenc/kiadas', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-WP-Nonce': window.PA.B.nonce } });
                    const j = await v.json().catch(() => ({}));
                    if (!v.ok) throw new Error(j.message || `Hiba (${v.status})`);
                    jelez('Feltöltve.');
                    rajz();
                } catch (x) { jelez(x.message, 'hiba'); }
            }));
            // A kétlépcsős gombok első kattintását az admin.js elkapja; ide csak a megerősített kattintás ér el.
            $$('[data-csat]', hova).forEach((g) => g.addEventListener('click', () => tolt(g, async () => {
                try { await api(`admin/licenc/kiadas/${g.dataset.csat}`, { method: 'POST', body: { csatorna: g.dataset.ertek } }); jelez('Mentve.'); rajz(); } catch (x) { jelez(x.message, 'hiba'); }
            })));
            $$('[data-torol]', hova).forEach((g) => g.addEventListener('click', () => tolt(g, async () => {
                try { await api(`admin/licenc/kiadas/${g.dataset.torol}`, { method: 'POST', body: { torol: true } }); jelez('Törölve.'); rajz(); } catch (x) { jelez(x.message, 'hiba'); }
            })));
        };
        await rajz();
    });

    /* ------------------------------------------------------------------ Kapcsolatok a többi panellel */

    // A Vevők / Rendelés popupban a licenckulcs-címke a licenc részleteire visz.
    const KULCS_MINTA = /^[A-Z0-9]{2,12}(-[A-Z0-9]{4}){4}$/;
    document.addEventListener('mouseover', (ev) => {
        const c = ev.target.closest && ev.target.closest('.sa-popup .sa-cimke--mono');
        if (c && !c.classList.contains('pl-ugro') && KULCS_MINTA.test(c.textContent.trim())) { c.classList.add('pl-ugro'); c.title = 'A licenc megnyitása'; }
    });
    document.addEventListener('click', (ev) => {
        const c = ev.target.closest && ev.target.closest('.sa-popup .sa-cimke--mono');
        if (!c || c.hasAttribute('data-licenc-ugro') || !KULCS_MINTA.test(c.textContent.trim())) return;
        ev.preventDefault();
        ev.stopPropagation();
        $$('.sa-popup-hatter').forEach((h) => h.remove());
        location.hash = '#/licencek/k/' + encodeURIComponent(c.textContent.trim());
    }, true);

    if (window.PA.parancs) {
        window.PA.parancs('Új licenc', 'kulcs', async () => { const a = await allapot(true); if (a.kezelheto) ujLicenc(a, (id) => { location.hash = '#/licencek/' + id; }); }, 'Licencek');
        window.PA.parancs('Lejáró licencek', 'ora', () => { S.szurok.licencek = Object.assign(S.szurok.licencek || {}, { szuro: 'lejar', oldal: 1, q: '' }); location.hash = '#/licencek'; }, 'Licencek');
    }
})();
