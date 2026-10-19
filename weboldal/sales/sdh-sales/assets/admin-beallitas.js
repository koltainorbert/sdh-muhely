/* Értékesítés – admin: Beállítások (csoportos űrlapok, titkok), Arculat (élő előnézet), Export / Import (előnézeti diff). */
(() => {
    'use strict';

    const A = window.PA;
    if (!A) return;
    const { B, S, $, $$, e, I, api, jelez, tolt, popup, popupHiba, urlap, szegmens, ures, cimke, letolt, diffHtml, datumIdo, kapcsolo } = A;

    /* ================================================================== Beállítások */

    A.panel('beallitasok', async (hova, [csopParam]) => {
        const r = await api('admin/beallitasok');
        const cs = r.csoportok;
        const kulcsok = Object.keys(cs);
        const f = S.szurok.beallitasok = Object.assign({ csop: kulcsok[0] }, S.szurok.beallitasok || {});
        if (csopParam && cs[csopParam]) f.csop = csopParam;
        if (!cs[f.csop]) f.csop = kulcsok[0];
        let ertekek = r.ertekek, titkok = r.titkok, aktiv = null;
        S.mentetlen = () => !!(aktiv && aktiv.piszkos);
        hova.innerHTML = `<div class="sa-ketpanel">
            <nav class="sa-kartya sa-almenu" aria-label="Beállítás-csoportok">${kulcsok.map((k) => `<button type="button" data-csop="${e(k)}" aria-current="${k === f.csop}">${I(cs[k].ikon || 'beallitas')}<span>${e(cs[k].cim)}</span></button>`).join('')}</nav>
            <div class="sa-oszlop" data-csoport></div></div>`;
        const doboz = $('[data-csoport]', hova);

        const rajzol = () => {
            const c = cs[f.csop];
            const sima = c.mezok.filter((m) => m.t !== 'titok');
            const titkosak = c.mezok.filter((m) => m.t === 'titok');
            const u = urlap(sima, ertekek, { nyelvek: r.nyelvek, valtozas: () => { aktiv.piszkos = true; jel(); } });
            aktiv = { u, piszkos: false };
            doboz.innerHTML = `<div class="sa-kartya"><div class="sa-kartya__fej"><div><h2 class="sa-kartya__cim">${e(c.cim)}</h2>${c.leiras ? `<div class="sa-kartya__al">${e(c.leiras)}</div>` : ''}</div><span class="sa-ter"></span><span data-allapot aria-live="polite"></span></div><div data-urlap></div>
                ${titkosak.length ? `<h3 class="sa-kartya__cim" style="margin:18px 0 10px">Titkos kulcsok</h3><div class="sa-mezok" data-titkok></div><p class="sa-kicsi sa-halvany" style="margin:8px 0 0">A titkok titkosítva tárolódnak, és soha nem jelennek meg újra – csak cserélni vagy törölni lehet őket. Exportba sem kerülnek.</p>` : ''}
                ${c.allapot ? `<h3 class="sa-kartya__cim" style="margin:18px 0 10px">Állapot</h3><dl class="sa-kv">${Object.entries(c.allapot).map(([k, v]) => `<dt>${e(k)}</dt><dd>${e(typeof v === 'boolean' ? (v ? 'igen' : 'nem') : typeof v === 'object' ? JSON.stringify(v) : v)}</dd>`).join('')}</dl>` : ''}
                ${(c.muveletek || []).length ? `<div class="sa-sor sa-sor--tor" style="margin-top:16px">${c.muveletek.map((m, i) => `<button type="button" class="sa-gomb" data-muvelet="${i}" ${m.ketlepcsos ? `data-ketlepcsos="${e(m.ketlepcsos)}"` : ''}>${e(m.cim)}</button>`).join('')}</div><div class="sa-kicsi" data-muvelet-ki aria-live="polite" style="margin-top:8px"></div>` : ''}
                </div>
                <div class="sa-sor sa-sor--veg"><button type="button" class="sa-gomb" data-elvet data-ketlepcsos="Biztos? Elvetem">Elvetés</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button></div>`;
            if (sima.length) $('[data-urlap]', doboz).appendChild(u.el); else $('[data-urlap]', doboz).remove();
            const td = $('[data-titkok]', doboz);
            if (td) titkosak.forEach((m) => td.appendChild(titokMezo(m)));
            jel();
            $('[data-ment]', doboz).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
                const h = u.ellenoriz();
                if (h.length) { jelez(h.join(' '), 'hiba'); return; }
                try {
                    const x = await api('admin/beallitasok', { method: 'POST', body: { ertekek: u.ertek() } });
                    ertekek = x.ertekek; titkok = x.titkok;
                    aktiv.piszkos = false;
                    jel();
                    jelez('Beállítások mentve.');
                    if (f.csop === 'altalanos' && ertekek.termeknev) { B.termeknev = ertekek.termeknev; const s = $('.sa-logo__nev small'); if (s) s.textContent = B.termeknev; }
                } catch (y) { jelez(y.message, 'hiba'); }
            }));
            $('[data-elvet]', doboz).addEventListener('click', rajzol);
            $$('[data-muvelet]', doboz).forEach((g) => g.addEventListener('click', () => tolt(g, async () => {
                const m = c.muveletek[Number(g.dataset.muvelet)];
                const ki = $('[data-muvelet-ki]', doboz);
                try {
                    const x = await api(m.ut.replace(/^\//, ''), { method: 'POST', body: {} });
                    if (m.lista) {
                        const t = x.talalatok || [];
                        ki.innerHTML = (t.length ? cimke(`${t.length} árulkodó nyom (${x.ellenorzott || 0} cím ellenőrizve)`, 'figyel') + `<ul class="sa-kicsi" style="margin:8px 0 0;padding-left:18px">${t.map((y) => `<li class="sa-mono">${e(y)}</li>`).join('')}</ul>` : cimke(`Nincs árulkodó nyom (${x.ellenorzott || 0} cím ellenőrizve).`, 'ok'));
                    } else {
                        ki.innerHTML = cimke(x.uzenet || 'Kész.', x.ok === false ? 'figyel' : 'ok');
                    }
                }
                catch (y) { ki.innerHTML = cimke(y.message, 'hiba'); }
            })));
        };

        const jel = () => {
            const a = $('[data-allapot]', doboz);
            if (a) a.innerHTML = aktiv.piszkos ? cimke('Mentetlen', 'figyel') : '';
            const m = $('[data-ment]', doboz), el = $('[data-elvet]', doboz);
            if (m) m.disabled = !aktiv.piszkos;
            if (el) el.disabled = !aktiv.piszkos;
        };

        function titokMezo(m) {
            const w = document.createElement('div');
            w.className = 'sa-mezo' + (m.fel ? '' : ' sa-mezo--szeles');
            const id = 'sa-titok-' + m.k.replace(/\W/g, '-');
            const ra = () => {
                const be = !!titkok[m.k];
                w.innerHTML = `<label class="sa-mezo__cim" for="${id}">${e(m.c)} ${be ? cimke('beállítva', 'ok') : cimke('nincs megadva', 'szurke')}</label>
                    <div class="sa-mezo__sor"><input class="sa-input sa-input--mono" id="${id}" type="password" autocomplete="new-password" spellcheck="false" placeholder="${be ? 'Új érték a cseréhez…' : 'Érték…'}">
                    <button type="button" class="sa-gomb" data-csere>${be ? 'Csere' : 'Mentés'}</button>${be ? `<button type="button" class="sa-gomb sa-gomb--veszely" data-torles data-ketlepcsos="Biztos? Törlöm">Törlés</button>` : ''}</div>${m.s ? `<span class="sa-mezo__sugo">${e(m.s)}</span>` : ''}`;
                const inp = $('input', w);
                const kuld = (ertek, g) => tolt(g, async () => {
                    try { const x = await api('admin/titok', { method: 'POST', body: { kulcs: m.k, ertek } }); titkok[m.k] = x.beallitva; jelez(x.beallitva ? `${m.c}: beállítva.` : `${m.c}: törölve.`); ra(); }
                    catch (y) { jelez(y.message, 'hiba'); }
                });
                $('[data-csere]', w).addEventListener('click', (ev) => { if (!inp.value.trim()) { inp.focus(); return; } kuld(inp.value.trim(), ev.currentTarget); });
                inp.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' && inp.value.trim()) { ev.preventDefault(); kuld(inp.value.trim(), $('[data-csere]', w)); } });
                const t = $('[data-torles]', w);
                if (t) t.addEventListener('click', () => kuld('', t));
            };
            ra();
            return w;
        }

        hova.addEventListener('click', (ev) => {
            const g = ev.target.closest('[data-csop]');
            if (!g || g.dataset.csop === f.csop) return;
            if (aktiv && aktiv.piszkos) { jelez('Előbb mentsd vagy vesd el a változásokat.', 'figyel'); return; }
            f.csop = g.dataset.csop;
            $$('[data-csop]', hova).forEach((x) => x.setAttribute('aria-current', String(x === g)));
            rajzol();
        });
        rajzol();
    });

    /* ================================================================== Arculat */

    A.panel('arculat', async (hova) => {
        let a = await api('admin/arculat');
        const T = a.tokenek;
        const CSOP = ['Színek', 'Állapotszínek', 'Formák', 'Betűk'];
        let mod = 'sotet';
        const ertekek = () => {
            const o = {};
            $$('[data-token]', hova).forEach((s) => { const k = s.dataset.token; const inp = $$('[data-ertek]', s).map((x) => x.value.trim()); o[k] = T[k].modos ? inp : (inp[0] || ''); });
            return o;
        };
        let mentett = '';
        S.mentetlen = () => mentett !== '' && JSON.stringify([ertekek(), $('[data-mod]', hova) && $('[data-mod]', hova).value, logok()]) !== mentett;
        const logok = () => ({ logo: ($('[data-logo="logo"]', hova) || {}).value || '', logo_vilagos: ($('[data-logo="logo_vilagos"]', hova) || {}).value || '', favicon: ($('[data-logo="favicon"]', hova) || {}).value || '' });

        const szinVez = (k, i, v, alap) => `<div class="sa-szin"><span class="sa-szin__minta"><i style="background:${e(v || alap)}"></i><input type="color" tabindex="-1" aria-hidden="true" value="${/^#[0-9a-f]{6}$/i.test(v || alap) ? e(v || alap) : '#808080'}"></span><input class="sa-input" data-ertek value="${e(v)}" placeholder="${e(alap)}" aria-label="${e(T[k].cimke)} – ${i ? 'világos' : 'sötét'} mód"></div>`;
        const tokenSor = (k) => {
            const t = T[k];
            if (t.fajta === 'szin') return `<div class="sa-token" data-token="${e(k)}"><div class="sa-token__cim">${e(t.cimke)}<small>--s-${e(k)}</small></div>${szinVez(k, 0, t.ertek[0], t.alap[0])}${szinVez(k, 1, t.ertek[1], t.alap[1])}</div>`;
            if (t.fajta === 'arnyek') return `<div class="sa-token" data-token="${e(k)}"><div class="sa-token__cim">${e(t.cimke)}<small>--s-${e(k)}</small></div><input class="sa-input sa-input--mono" data-ertek value="${e(t.ertek[0])}" placeholder="${e(t.alap[0])}" aria-label="${e(t.cimke)} – sötét"><input class="sa-input sa-input--mono" data-ertek value="${e(t.ertek[1])}" placeholder="${e(t.alap[1])}" aria-label="${e(t.cimke)} – világos"></div>`;
            if (t.fajta === 'betu') return `<div class="sa-token sa-token--egy" data-token="${e(k)}"><div class="sa-token__cim">${e(t.cimke)}<small>--s-${e(k)}</small></div><select class="sa-select" data-ertek aria-label="${e(t.cimke)}"><option value="">Alap (${e(Object.entries(a.betuk).find(([x]) => x === t.alap[0])?.[1] || 'téma')})</option>${Object.entries(a.betuk).map(([v, n]) => `<option value="${e(v)}" ${v === t.ertek[0] ? 'selected' : ''} style="font-family:${e(v)}">${e(n)}</option>`).join('')}${t.ertek[0] && !a.betuk[t.ertek[0]] ? `<option value="${e(t.ertek[0])}" selected>${e(t.ertek[0])}</option>` : ''}</select></div>`;
            return `<div class="sa-token sa-token--egy" data-token="${e(k)}"><div class="sa-token__cim">${e(t.cimke)}<small>--s-${e(k)}</small></div><input class="sa-input sa-input--mono" data-ertek value="${e(t.ertek[0])}" placeholder="${e(t.alap[0])}" aria-label="${e(t.cimke)}"></div>`;
        };
        const logoMezo = (k, c) => `<div class="sa-mezo"><label class="sa-mezo__cim" for="sa-ar-${k}">${e(c)}</label><div class="sa-kep"><span class="sa-kep__elo" style="background-image:${a[k] ? `url('${e(a[k])}')` : 'none'}"></span><input class="sa-input" id="sa-ar-${k}" data-logo="${k}" value="${e(a[k])}" placeholder="https://…">${B.media && window.wp && wp.media ? `<button type="button" class="sa-gomb" data-media="${k}">${I('kep')}Médiatár</button>` : ''}</div></div>`;

        hova.innerHTML = `<div class="sa-arculat">
            <div class="sa-oszlop">
                <div class="sa-kartya">${A.fulHtml([
                    ...CSOP.map((c) => [c, e(c), `${['Színek', 'Állapotszínek'].includes(c) || c === 'Formák' ? `<div class="sa-token" style="border:0;padding-top:0"><span></span>${['Színek', 'Állapotszínek'].includes(c) ? '<span class="sa-kicsi sa-halvany">Sötét mód</span><span class="sa-kicsi sa-halvany">Világos mód</span>' : ''}</div>` : ''}${Object.keys(T).filter((k) => T[k].csoport === c).map(tokenSor).join('')}`]),
                    ['logo', 'Logó és mód', `<div class="sa-mezok">${logoMezo('logo', 'Logó (sötét háttérre)')}${logoMezo('logo_vilagos', 'Logó (világos háttérre)')}${logoMezo('favicon', 'Favicon')}<div class="sa-mezo"><label class="sa-mezo__cim" for="sa-ar-mod">Alap megjelenés</label><select class="sa-select" id="sa-ar-mod" data-mod>${Object.entries({ rendszer: 'A látogató rendszere szerint', sotet: 'Mindig sötét', vilagos: 'Mindig világos' }).map(([k, v]) => `<option value="${k}" ${a.mod === k ? 'selected' : ''}>${v}</option>`).join('')}</select></div></div>`],
                ])}
                <p class="sa-kicsi sa-halvany" style="margin:12px 0 0">Üres mező = a téma alapértéke${a.tema_tokenek ? '' : ' (a téma tokenfájlja még nincs meg – a beépített alap él)'}. Csak az eltérő, érvényes értékek kerülnek a webhelyre.</p></div>
                <div class="sa-sor sa-sor--veg"><span data-allapot aria-live="polite"></span><span class="sa-ter"></span><button type="button" class="sa-gomb" data-alap data-ketlepcsos="Biztos? Minden mező az alapra áll">${I('visszaall')}Mind alapra</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button></div>
            </div>
            <div class="sa-oszlop" style="position:sticky;top:84px">
                <div class="sa-kartya sa-kartya--sima"><div class="sa-kartya__fej" style="padding-bottom:12px"><h2 class="sa-kartya__cim">Élő minta</h2><span class="sa-ter"></span>${szegmens('Mód', { sotet: `${I('hold')}Sötét`, vilagos: `${I('nap')}Világos` }, mod)}<button type="button" class="sa-gomb sa-gomb--kicsi" data-webhely>${I('kulso')}A webhelyen</button></div>
                    <div class="sa-minta" data-minta><div class="sa-minta__test">
                        <img data-minta-logo alt="" style="max-height:34px;max-width:180px;align-self:flex-start" hidden>
                        <span class="sa-minta__pill" style="background:color-mix(in srgb,var(--s-kiemelo) 18%,transparent);color:var(--s-kiemelo);align-self:flex-start">Újdonság</span>
                        <h3>${e(B.termeknev)} – a szerviz, ami magától megy</h3>
                        <p>Munkalap, ügyfélkezelés és számlázás egy helyen. Ez a minta a beállított színekkel, betűkkel és ívekkel rajzolódik.</p>
                        <div class="sa-sor sa-sor--tor"><span class="sa-minta__gomb">Kipróbálom</span><span class="sa-minta__gomb sa-minta__gomb--masod">Árak</span></div>
                        <div class="sa-minta__kartya"><b>Pro csomag</b><span class="sa-minta__mezo">E-mail-cím</span><div class="sa-sor sa-sor--tor"><span class="sa-minta__pill" style="background:color-mix(in srgb,var(--s-ok) 18%,transparent);color:var(--s-ok)">Fizetve</span><span class="sa-minta__pill" style="background:color-mix(in srgb,var(--s-figyel) 18%,transparent);color:var(--s-figyel)">Függőben</span><span class="sa-minta__pill" style="background:color-mix(in srgb,var(--s-hiba) 18%,transparent);color:var(--s-hiba)">Hiba</span></div><span class="sa-minta__kod">R-2026-000123</span></div>
                    </div></div></div>
            </div></div>`;
        A.fulek($('.sa-kartya', hova));

        const minta = $('[data-minta]', hova);
        const frissitMinta = () => {
            const o = ertekek();
            Object.keys(T).forEach((k) => {
                const t = T[k];
                const i = t.modos && mod === 'vilagos' ? 1 : 0;
                const v = t.modos ? (o[k][i] || t.alap[i]) : (o[k] || t.alap[0]);
                minta.style.setProperty('--s-' + k, v);
            });
            minta.style.setProperty('--s-r-kartya', o['r-kartya'] || T['r-kartya'].alap[0]);
            const lg = $('[data-minta-logo]', hova);
            const l = logok();
            const src = mod === 'vilagos' && l.logo_vilagos ? l.logo_vilagos : l.logo;
            lg.hidden = !src;
            if (src) lg.src = src;
            jel();
        };
        const jel = () => { const p = S.mentetlen(); $('[data-allapot]', hova).innerHTML = p ? cimke('Mentetlen', 'figyel') : ''; };
        hova.addEventListener('input', (ev) => {
            const sz = ev.target.closest('.sa-szin');
            if (sz) {
                const c = $('input[type=color]', sz), t = $('[data-ertek]', sz);
                if (ev.target === c) t.value = c.value;
                $('i', sz).style.background = t.value || t.placeholder;
            }
            const lg = ev.target.closest('[data-logo]');
            if (lg) { const el = lg.parentElement.querySelector('.sa-kep__elo'); el.style.backgroundImage = lg.value ? `url('${lg.value.replace(/['\\]/g, '')}')` : 'none'; }
            frissitMinta();
        });
        hova.addEventListener('change', frissitMinta);
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { mod = ev.detail; frissitMinta(); });
        $$('[data-media]', hova).forEach((g) => g.addEventListener('click', () => {
            const k = wp.media({ title: 'Kép kiválasztása', multiple: false, library: { type: 'image' }, button: { text: 'Kiválasztás' } });
            k.on('select', () => { const x = k.state().get('selection').first().toJSON(); const inp = $(`[data-logo="${g.dataset.media}"]`, hova); inp.value = x.url || ''; inp.dispatchEvent(new Event('input', { bubbles: true })); });
            k.on('close', () => setTimeout(() => g.focus(), 50));
            k.open();
        }));
        $('[data-alap]', hova).addEventListener('click', () => { $$('[data-ertek]', hova).forEach((x) => { x.value = ''; }); $$('.sa-szin', hova).forEach((s) => { $('i', s).style.background = $('[data-ertek]', s).placeholder; }); frissitMinta(); });
        $('[data-webhely]', hova).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            try { const r = await api('admin/arculat/elonezet', { method: 'POST', body: Object.assign({ ertekek: ertekek(), mod }, logok()) }); window.open(r.url, '_blank', 'noopener'); }
            catch (x) { jelez(x.message, 'hiba'); }
        }));
        $('[data-ment]', hova).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            try {
                a = await api('admin/arculat', { method: 'POST', body: Object.assign({ ertekek: ertekek(), mod: $('[data-mod]', hova).value }, logok()) });
                mentett = JSON.stringify([ertekek(), $('[data-mod]', hova).value, logok()]);
                jel();
                jelez('Arculat mentve – a webhelyen azonnal él.');
            } catch (x) {
                jelez(x.message, 'hiba');
                const hibas = (x.adat && x.adat.hibas) || [];
                hibas.forEach((h) => { const k = h.split(' ')[0]; const s = $(`[data-token="${k}"]`, hova); if (s) $$('[data-ertek]', s).forEach((y) => y.closest('.sa-szin, .sa-token')?.classList.add('is-hibas')); });
            }
        }));
        mentett = JSON.stringify([ertekek(), $('[data-mod]', hova).value, logok()]);
        frissitMinta();
    });

    /* ================================================================== Export / Import */

    A.panel('export', async (hova) => {
        const m = await api('admin/mentesek').catch(() => ({ lista: [] }));
        hova.innerHTML = `<div class="sa-racs sa-racs--fele">
            <div class="sa-kartya"><div class="sa-kartya__fej"><h2 class="sa-kartya__cim">Export</h2></div>
                <p class="sa-kicsi sa-halvany" style="margin-top:0">Egy JSON-fájl: beállítások (titkok nélkül), minden tartalomelem (csomagok, modulok, GYIK, e-mail-sablonok, menük…), az oldalak szekciói és SEO-mezői. Verziószámmal – csak azonos fő verzióba tölthető vissza.</p>
                <button type="button" class="sa-gomb sa-gomb--fo" data-export>${I('letolt')}Export letöltése</button></div>
            <div class="sa-kartya"><div class="sa-kartya__fej"><h2 class="sa-kartya__cim">Import</h2></div>
                <div class="sa-ejto" data-ejto tabindex="0" role="button" aria-describedby="sa-imp-s">${I('feltolt')}<div>Húzd ide az exportfájlt, vagy kattints a kiválasztáshoz</div><input type="file" accept="application/json,.json" hidden data-fajl></div>
                <p class="sa-kicsi sa-halvany" id="sa-imp-s">Importálás előtt megmutatom, mi változik. Az import a meglévőt nem törli, csak frissít és hozzáad; előtte automatikus mentés készül.</p></div>
        </div>
        <div class="sa-kartya sa-kartya--sima"><div class="sa-kartya__fej"><h2 class="sa-kartya__cim">Import előtti mentések</h2><span class="sa-ter"></span><span class="sa-kartya__al">a legutóbbi 10</span></div>
            ${m.lista.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Mentés</th><th>Idő (UTC)</th><th class="sa-jobb">Méret</th><th></th></tr></thead><tbody>${m.lista.map((x) => `<tr><td class="sa-mono">${e(x.nev)}</td><td class="sa-halvany">${e(x.ido)}</td><td class="sa-jobb">${A.szam(Math.round(x.meret / 1024))} KB</td><td class="sa-jobb"><button type="button" class="sa-gomb sa-gomb--kicsi" data-mentes="${e(x.nev)}">${I('letolt')}Letöltés</button> <button type="button" class="sa-gomb sa-gomb--kicsi" data-visszatolt="${e(x.nev)}">${I('visszaall')}Visszatöltés…</button></td></tr>`).join('')}</tbody></table></div>` : ures('Még nem volt import.', 'mentes')}
        </div>
        <div class="sa-kartya"><div class="sa-kartya__fej"><h2 class="sa-kartya__cim">Tartalom újratöltése</h2></div>
            <p class="sa-kicsi sa-halvany" style="margin-top:0">A kezdőtartalom (árak, oldalak, GYIK, e-mailek, menük) betöltése a csomag adatfájljaiból. A szerkesztett elemekhez egyik sem nyúl.</p>
            <div class="sa-sor sa-sor--tor"><button type="button" class="sa-gomb" data-betolt="0" data-ketlepcsos="Biztos? Csak a hiányzók töltődnek be">${I('letolt')}Hiányzók betöltése</button><button type="button" class="sa-gomb" data-betolt="1" data-ketlepcsos="Biztos? A nem szerkesztett elemek frissülnek">${I('ujra')}Nem szerkesztettek frissítése</button></div><div class="sa-kicsi" data-betolt-ki aria-live="polite" style="margin-top:8px"></div></div>`;

        $('[data-export]', hova).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            try {
                const r = await api('admin/export', { nyers: true });
                const cd = r.headers.get('Content-Disposition') || '';
                letolt((cd.match(/filename="?([^";]+)/) || [])[1] || 'portal-export.json', await r.blob());
                jelez('Export letöltve.');
            } catch (x) { jelez(x.message, 'hiba'); }
        }));
        const ejto = $('[data-ejto]', hova), fajl = $('[data-fajl]', hova);
        ejto.addEventListener('click', () => fajl.click());
        ejto.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); fajl.click(); } });
        ['dragenter', 'dragover'].forEach((n) => ejto.addEventListener(n, (ev) => { ev.preventDefault(); ejto.classList.add('is-felette'); }));
        ['dragleave', 'drop'].forEach((n) => ejto.addEventListener(n, () => ejto.classList.remove('is-felette')));
        ejto.addEventListener('drop', (ev) => { ev.preventDefault(); const f = ev.dataTransfer.files[0]; if (f) beolvas(f); });
        fajl.addEventListener('change', () => { if (fajl.files[0]) beolvas(fajl.files[0]); fajl.value = ''; });

        async function beolvas(f) {
            if (f.size > 20 * 1024 * 1024) { jelez('A fájl túl nagy (legfeljebb 20 MB).', 'hiba'); return; }
            let csomag;
            try { csomag = JSON.parse(await f.text()); } catch (x) { jelez('A fájl nem érvényes JSON.', 'hiba'); return; }
            importElonezet(csomag, f.name);
        }

        $$('[data-mentes]', hova).forEach((g) => g.addEventListener('click', () => tolt(g, async () => {
            try { const r = await api('admin/mentes', { query: { nev: g.dataset.mentes } }); letolt(g.dataset.mentes, JSON.stringify(r, null, 2)); } catch (x) { jelez(x.message, 'hiba'); }
        })));
        $$('[data-visszatolt]', hova).forEach((g) => g.addEventListener('click', () => tolt(g, async () => {
            try { const r = await api('admin/mentes', { query: { nev: g.dataset.visszatolt } }); importElonezet(r, g.dataset.visszatolt); } catch (x) { jelez(x.message, 'hiba'); }
        })));
        $$('[data-betolt]', hova).forEach((g) => g.addEventListener('click', () => tolt(g, async () => {
            const ki = $('[data-betolt-ki]', hova);
            try { const r = await api('admin/tartalom/betolt', { method: 'POST', body: { kenyszer: g.dataset.betolt === '1' } }); ki.innerHTML = Object.entries(r.db || {}).map(([k, v]) => cimke(`${k}: ${v}`, Number(v) ? 'ok' : 'szurke')).join(' ') || cimke('Nem volt mit betölteni.', 'szurke'); Object.keys(A.elemCache).forEach((k) => delete A.elemCache[k]); }
            catch (x) { ki.innerHTML = cimke(x.message, 'hiba'); }
        })));
    });

    async function importElonezet(csomag, nev) {
        let p;
        try { p = await api('admin/import/elonezet', { method: 'POST', body: { csomag } }); } catch (x) { jelez(x.message, 'hiba'); return; }
        if (!p.ok) {
            popup({ cim: 'Nem importálható', meret: 'kicsi', test: `<p>${p.hibak.map(e).join('<br>')}</p>`, lab: '<button type="button" class="sa-gomb" data-bezar>Bezárás</button>' });
            return;
        }
        const t = p.tartalom, o = p.oldalak;
        const nincs = !p.beallitas.length && !t.uj.length && !t.modosul.length && !o.uj.length && !o.modosul.length;
        const lista = (l, fn) => (l.length ? `<ul style="margin:0;padding-left:18px">${l.slice(0, 200).map(fn).join('')}</ul>${l.length > 200 ? `<p class="sa-kicsi sa-halvany">… és még ${l.length - 200}</p>` : ''}` : '<p class="sa-kicsi sa-halvany">Nincs.</p>');
        const bRegi = Object.fromEntries(p.beallitas.map((x) => [x.kulcs, x.regi])), bUj = Object.fromEntries(p.beallitas.map((x) => [x.kulcs, x.uj]));
        const { el, bezar } = popup({
            cim: 'Import előnézete',
            meret: 'szeles',
            test: `<div class="sa-sor sa-sor--tor"><span class="sa-mono sa-kicsi">${e(nev)}</span>${cimke('v' + p.verzio, 'kiemelo')}${p.ido ? `<span class="sa-kicsi sa-halvany">készült: ${e(p.ido.replace('T', ' ').slice(0, 16))}</span>` : ''}${p.webhely ? `<span class="sa-kicsi sa-halvany">${e(p.webhely)}</span>` : ''}</div>
                ${p.figyel.map((x) => `<div>${cimke(x, 'figyel')}</div>`).join('')}
                ${nincs ? ures('A fájl tartalma megegyezik a jelenlegivel – nincs mit importálni.', 'pipa') : A.fulHtml([
                    ['b', `Beállítások (${p.beallitas.length})`, `<div style="max-height:min(46vh,420px);overflow:auto">${p.beallitas.length ? diffHtml(bRegi, bUj, false) : ures('Nincs változás.')}</div>`],
                    ['t', `Tartalom (${t.uj.length} új, ${t.modosul.length} módosul)`, `<div style="max-height:min(46vh,420px);overflow:auto"><div class="sa-racs sa-racs--fele"><div><h3 class="sa-kartya__cim">Új elemek</h3>${lista(t.uj, (x) => `<li><span class="sa-mono">${e(x.nev)}</span> – ${e(x.cim)}</li>`)}</div><div><h3 class="sa-kartya__cim">Módosuló elemek</h3>${lista(t.modosul, (x) => `<li><span class="sa-mono">${e(x.nev)}</span> <span class="sa-halvany">(${e(x.mezok.join(', '))})</span></li>`)}</div></div><p class="sa-kicsi sa-halvany">Változatlan: ${t.valtozatlan}</p></div>`],
                    ['o', `Oldalak (${o.uj.length} új, ${o.modosul.length} módosul)`, `<div style="max-height:min(46vh,420px);overflow:auto"><div class="sa-racs sa-racs--fele"><div><h3 class="sa-kartya__cim">Új oldalak</h3>${lista(o.uj, (x) => `<li><span class="sa-mono">/${e(x.ut)}</span> – ${e(x.cim)} <span class="sa-halvany">(${x.szekcio} szekció)</span></li>`)}</div><div><h3 class="sa-kartya__cim">Módosuló oldalak</h3>${lista(o.modosul, (x) => `<li><span class="sa-mono">/${e(x.ut)}</span> <span class="sa-halvany">(${e(x.mezok.join(', '))})</span></li>`)}</div></div><p class="sa-kicsi sa-halvany">Változatlan: ${o.valtozatlan}</p></div>`],
                ])}`,
            lab: `<span class="sa-kicsi sa-halvany">Előtte automatikus mentés készül.</span><span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Mégse</button>${nincs ? '' : `<button type="button" class="sa-gomb sa-gomb--fo" data-import data-ketlepcsos="Biztos? Importálom">${I('feltolt')}Importálás</button>`}`,
        });
        A.fulek(el);
        const g = $('[data-import]', el);
        if (g) g.addEventListener('click', () => tolt(g, async () => {
            try {
                const r = await api('admin/import', { method: 'POST', body: { csomag } });
                jelez(`Import kész: ${r.db.beallitas} beállítás, ${r.db.tartalom} elem, ${r.db.oldal} oldal. Mentés: ${r.mentes}`);
                Object.keys(A.elemCache).forEach((k) => delete A.elemCache[k]);
                bezar();
                if (S.oldal === 'export') window.dispatchEvent(new HashChangeEvent('hashchange'));
            } catch (x) { popupHiba(el, x.message); }
        }));
    }

    A.parancs('Export letöltése', 'export', () => { location.hash = '#/export'; });
    A.parancs('Arculat', 'arculat', () => { location.hash = '#/arculat'; });
})();
