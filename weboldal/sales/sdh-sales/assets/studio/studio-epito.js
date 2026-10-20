/* Stúdió – Oldalak és az oldalépítő.
   Oldalak: fa-nézet (szülő / gyerek), állapot, piszkozat-jelző, műveletek (szerkesztés, másolás, kezdőlap, kuka).
   Oldalépítő: bal oldalt szekciófa (húzás, ki/be, másolás, törlés, + szekció a sematikus könyvtárból), középen az oldal
   VALÓDI megjelenítése (két iframe váltakozva – villogás nélkül; asztali / tablet / mobil, világos / sötét), jobbra a
   kijelölt szekció tulajdonságai. Az előnézetben kattintva kijelöl (szekció: <template data-s-szakasz> jelölő,
   mező: data-s-szerk), a szöveg helyben is írható. Visszavonás / újra, automatikus piszkozat, Közzététel, revíziók. */
(() => {
    'use strict';

    const A = window.PA;
    if (!A) return;
    const { B, S, $, $$, e, I, WI, api, jelez, bemond, tolt, popup, popupHiba, urlap, szegmens, kesleltet, rendezheto, ures, cim, cimke, relativ, datumIdo, kapcsolo } = A;

    const SZT = B.szekciok || {};
    const STATUSZ = { publish: ['Közzétéve', 'ok'], draft: ['Vázlat', 'figyel'], private: ['Privát', 'szurke'], pending: ['Jóváhagyásra vár', 'figyel'], trash: ['Kukában', 'hiba'], future: ['Ütemezve', 'info'] };
    const MERET = { asztal: 1280, tablet: 820, mobil: 390 };
    const SZOVEGES = ['szoveg', 'hosszu'];

    /* ================================================================== Sematikus szekció-bélyegképek */

    const vazlatKep = (tipus) => {
        const v = (x, y, w, h, o = 1, r = 2) => `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${r}" opacity="${o}"/>`;
        const sor = (x, y, w, o = 0.35) => v(x, y, w, 4, o, 2);
        const kartyak = (n, y = 30, h = 44) => { let s = ''; const w = (148 - (n - 1) * 6) / n; for (let i = 0; i < n; i++) { const x = 6 + i * (w + 6); s += `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="4" fill="none" stroke="currentColor" stroke-opacity=".35"/>` + `<circle cx="${x + 9}" cy="${y + 10}" r="4" class="k"/>` + sor(x + 5, y + 20, w - 10) + sor(x + 5, y + 28, w - 16, 0.22); } return s; };
        const cimsor = (y = 8, w = 70, x = 45) => v(x, y, w, 6, 0.75, 3) + sor(x + 8, y + 10, w - 16, 0.3);
        const gomb = (x, y, w = 26, k = true) => `<rect x="${x}" y="${y}" width="${w}" height="8" rx="4" class="${k ? 'k' : ''}" opacity="${k ? 1 : 0.35}"/>`;
        const m = {
            hero: v(8, 18, 60, 7, 0.8, 3) + v(8, 28, 50, 7, 0.8, 3) + sor(8, 42, 64) + sor(8, 50, 52) + gomb(8, 62) + gomb(38, 62, 24, false) + `<rect x="86" y="14" width="66" height="60" rx="5" class="k" opacity=".25"/>`,
            logo_sav: cimsor(16) + [0, 1, 2, 3, 4].map((i) => v(10 + i * 29, 46, 22, 12, 0.3, 3)).join(''),
            jellemzok: cimsor() + kartyak(3),
            funkcio_racs: cimsor() + kartyak(3),
            iparag_racs: cimsor() + kartyak(4),
            modul_racs: cimsor() + kartyak(4),
            arak: cimsor(4) + [0, 1, 2].map((i) => `<rect x="${10 + i * 48}" y="${i === 1 ? 20 : 24}" width="42" height="${i === 1 ? 64 : 58}" rx="4" fill="none" stroke="currentColor" ${i === 1 ? 'class="ks"' : 'stroke-opacity=".35"'}/>` + v(16 + i * 48, 32, 18, 6, 0.75) + sor(16 + i * 48, 44, 28) + sor(16 + i * 48, 52, 24) + gomb(16 + i * 48, i === 1 ? 72 : 68, 30, i === 1)).join(''),
            ar_tablazat: cimsor() + [0, 1, 2, 3, 4, 5].map((i) => sor(10, 32 + i * 9, 140, i === 0 ? 0.6 : 0.25)).join(''),
            osszehasonlitas: cimsor() + [0, 1, 2, 3, 4].map((i) => sor(10, 32 + i * 10, 50, 0.3) + `<circle cx="96" cy="${34 + i * 10}" r="3" class="k"/>` + `<circle cx="132" cy="${34 + i * 10}" r="3" opacity=".25"/>`).join(''),
            kalkulator: cimsor() + [0, 1, 2, 3].map((i) => sor(10, 32 + i * 11, 70) + `<rect x="10" y="${36 + i * 11}" width="70" height="4" rx="2" opacity=".12"/>`).join('') + `<rect x="94" y="30" width="56" height="50" rx="4" class="k" opacity=".18"/>` + gomb(104, 68, 36),
            lepesek: cimsor() + [0, 1, 2, 3].map((i) => `<circle cx="${22 + i * 38}" cy="46" r="7" class="k"/>` + sor(10 + i * 38, 60, 26)).join('') + `<path d="M29 46h22M67 46h22M105 46h22" stroke="currentColor" stroke-opacity=".3"/>`,
            szoveg: v(30, 10, 60, 6, 0.75, 3) + [0, 1, 2, 3, 4, 5, 6].map((i) => sor(30, 24 + i * 8, i % 3 === 2 ? 70 : 100)).join(''),
            jogi: v(30, 10, 60, 6, 0.75, 3) + [0, 1, 2, 3, 4, 5, 6].map((i) => sor(30, 24 + i * 8, i % 2 ? 96 : 100, 0.25)).join(''),
            kep_szoveg: `<rect x="8" y="12" width="66" height="66" rx="5" class="k" opacity=".25"/>` + v(84, 18, 56, 6, 0.75, 3) + [0, 1, 2, 3].map((i) => sor(84, 32 + i * 8, 62)).join('') + gomb(84, 66),
            video: cimsor(6) + `<rect x="24" y="24" width="112" height="60" rx="5" opacity=".2"/><path d="M74 44v20l17-10z" class="k"/>`,
            velemeny: cimsor() + [0, 1, 2].map((i) => `<rect x="${8 + i * 50}" y="30" width="44" height="48" rx="4" fill="none" stroke="currentColor" stroke-opacity=".35"/><text x="${13 + i * 50}" y="44" font-size="14" class="kt">“</text>` + sor(13 + i * 50, 50, 34) + sor(13 + i * 50, 58, 28) + `<circle cx="${18 + i * 50}" cy="70" r="4" opacity=".4"/>`).join(''),
            szamok: cimsor() + [0, 1, 2, 3].map((i) => v(12 + i * 37, 38, 26, 12, 0.8, 3) + sor(12 + i * 37, 56, 28)).join(''),
            gyik: cimsor() + [0, 1, 2, 3, 4].map((i) => `<rect x="20" y="${30 + i * 11}" width="120" height="8" rx="3" fill="none" stroke="currentColor" stroke-opacity=".3"/><path d="M132 ${33 + i * 11}l3 3 3-3" stroke="currentColor" stroke-opacity=".5" fill="none"/>`).join(''),
            cta: `<rect x="8" y="22" width="144" height="48" rx="7" class="k" opacity=".22"/>` + v(40, 32, 80, 7, 0.8, 3) + sor(50, 44, 60) + gomb(52, 54, 26) + gomb(82, 54, 26, false),
            urlap: cimsor() + [0, 1, 2].map((i) => `<rect x="30" y="${30 + i * 13}" width="100" height="9" rx="4" fill="none" stroke="currentColor" stroke-opacity=".35"/>`).join('') + gomb(30, 72, 34),
            foglalas: cimsor() + [0, 1, 2, 3, 4, 5, 6].map((i) => `<rect x="${14 + i * 19}" y="32" width="15" height="15" rx="3" ${i === 3 ? 'class="k"' : 'opacity=".2"'}/>`).join('') + [0, 1, 2, 3].map((i) => `<rect x="${14 + i * 34}" y="56" width="28" height="9" rx="4" fill="none" stroke="currentColor" stroke-opacity=".35"/>`).join(''),
            demo_inditas: cimsor(18) + gomb(56, 50, 48),
            blog_lista: cimsor() + [0, 1, 2].map((i) => `<rect x="${8 + i * 50}" y="30" width="44" height="22" rx="4" class="k" opacity=".22"/>` + sor(8 + i * 50, 58, 40) + sor(8 + i * 50, 66, 30)).join(''),
            tudasbazis: cimsor() + `<rect x="24" y="30" width="112" height="10" rx="5" fill="none" stroke="currentColor" stroke-opacity=".45"/>` + [0, 1, 2, 3].map((i) => sor(24, 50 + i * 8, 100)).join(''),
            kereso: cimsor() + `<rect x="24" y="34" width="112" height="12" rx="6" fill="none" stroke="currentColor" stroke-opacity=".5"/><circle cx="32" cy="40" r="3" class="k"/>`,
            statusz: cimsor() + [0, 1, 2, 3].map((i) => `<circle cx="28" cy="${36 + i * 11}" r="3" ${i === 2 ? 'opacity=".5"' : 'class="k"'}/>` + sor(36, 34 + i * 11, 90)).join(''),
            fiok: cimsor() + `<rect x="40" y="30" width="80" height="50" rx="5" fill="none" stroke="currentColor" stroke-opacity=".35"/>` + sor(48, 40, 60) + sor(48, 50, 50) + gomb(48, 64, 30),
            kosar: cimsor() + [0, 1, 2].map((i) => sor(14, 34 + i * 12, 90) + v(120, 33 + i * 12, 26, 6, 0.5)).join('') + gomb(112, 74, 36),
            penztar: cimsor() + [0, 1, 2, 3].map((i) => `<rect x="10" y="${30 + i * 12}" width="84" height="8" rx="4" fill="none" stroke="currentColor" stroke-opacity=".35"/>`).join('') + `<rect x="102" y="30" width="48" height="44" rx="4" class="k" opacity=".2"/>`,
            oktatas: cimsor() + [0, 1, 2, 3].map((i) => `<path d="M16 ${36 + i * 11}l3 3 5-6" stroke="currentColor" fill="none" class="ks"/>` + sor(30, 34 + i * 11, 100)).join(''),
            telepito: cimsor() + [0, 1, 2, 3].map((i) => `<circle cx="20" cy="${36 + i * 11}" r="4" class="k"/>` + sor(30, 34 + i * 11, 96)).join(''),
        };
        return `<svg viewBox="0 0 160 92" class="st-vazlat" aria-hidden="true" focusable="false" fill="currentColor">${m[tipus] || cimsor(10) + kartyak(2, 34, 40)}</svg>`;
    };
    A.vazlatKep = vazlatKep;

    /* ================================================================== Oldalak – lista */

    A.panel('oldalak', async (hova, [id]) => {
        if (id) return epito(hova, Number(id));
        const f = S.szurok.oldalak = Object.assign({ nezet: 'mind', q: '' }, S.szurok.oldalak || {});
        const [r, kuka] = await Promise.all([api('admin/oldalak'), api('admin/oldalak', { query: { kuka: 1 } }).catch(() => ({ lista: [] }))]);
        let oldalak = r.lista;
        hova.innerHTML = `<div class="sa-eszkozsor">${szegmens('Nézet', { mind: `Minden oldal <small>${oldalak.length}</small>`, szekcios: `Szekciós <small>${oldalak.filter((x) => x.szekcios).length}</small>`, vazlat: `Vázlatok <small>${oldalak.filter((x) => x.statusz !== 'publish' || x.piszkozat).length}</small>`, kuka: `Kuka <small>${kuka.lista.length}</small>` }, f.nezet)}<span class="sa-ter"></span>
            <input class="sa-input" type="search" data-q placeholder="Cím vagy útvonal…" aria-label="Keresés az oldalak között" value="${e(f.q)}">
            <button type="button" class="sa-gomb sa-gomb--fo" data-uj>${I('plusz')}Új oldal</button></div>
            <div class="sa-kartya sa-kartya--sima" data-lista aria-live="polite"></div>`;
        const lista = $('[data-lista]', hova);
        const melyseg = (x) => (x.ut.match(/\//g) || []).length;
        const rajzol = () => {
            const q = f.q.toLowerCase();
            const forras = f.nezet === 'kuka' ? kuka.lista : oldalak;
            const l = forras.filter((x) => (f.nezet !== 'szekcios' || x.szekcios) && (f.nezet !== 'vazlat' || x.statusz !== 'publish' || x.piszkozat) && (!q || x.cim.toLowerCase().includes(q) || x.ut.includes(q)));
            lista.innerHTML = l.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Oldal</th><th>Útvonal</th><th class="sa-jobb">Szekciók</th><th>Állapot</th><th>Módosítva</th><th class="sa-jobb"><span class="sa-sr">Műveletek</span></th></tr></thead><tbody>${l.map((x) => `
                <tr class="${f.nezet === 'kuka' ? '' : 'is-katt'}" data-oldal="${x.id}" ${f.nezet === 'kuka' ? '' : 'tabindex="0"'}><td><span class="st-fa-behuz" style="--m:${q ? 0 : melyseg(x)}"></span><span class="sa-vastag">${e(x.cim || '(cím nélkül)')}</span>${x.kezdolap ? ' ' + cimke('Kezdőlap', 'kiemelo') : ''}${x.piszkozat ? ' ' + cimke('Mentetlen piszkozat', 'figyel') : ''}${x.jogi ? ' ' + cimke('Jogi', 'szurke') : ''}</td><td class="sa-mono sa-halvany"><div class="sa-egysor" style="--sa-cella-max:240px" title="/${e(x.ut)}">/${e(x.ut)}</div></td>
                <td class="sa-jobb">${x.szekcios ? A.szam(x.szekcio_db) : '<span class="sa-halvany">–</span>'}</td><td>${cimke(...(STATUSZ[x.statusz] || [x.statusz, 'szurke']))}</td><td class="sa-halvany">${e(relativ(x.modositva))}</td>
                <td class="sa-jobb"><span class="sa-sor" style="justify-content:flex-end;gap:4px">${f.nezet === 'kuka'
                    ? `<button type="button" class="sa-gomb sa-gomb--kicsi" data-vissza="${x.id}">${I('visszaall')}Visszaállítás</button><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--veszely" data-vegleges="${x.id}" data-ketlepcsos="Biztos? Véglegesen törlöm">${I('torol')}Végleges törlés</button>`
                    : `<button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-masol="${x.id}" aria-label="${e(x.cim)} másolása" title="Másolat (vázlat)">${I('duplikal')}</button>${x.statusz === 'publish' && !x.kezdolap ? `<button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-kezdo="${x.id}" aria-label="${e(x.cim)} legyen a kezdőlap" title="Legyen a kezdőlap" data-ketlepcsos="Biztos? Ez lesz a kezdőlap">${I('csillag')}</button>` : ''}${x.kezdolap ? '' : `<button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi sa-ikon-gomb--veszely" data-kuka="${x.id}" aria-label="${e(x.cim)} kukába" title="Kukába" data-ketlepcsos="Biztos? A kukába teszem">${I('torol')}</button>`}<a class="sa-ikon-gomb sa-ikon-gomb--kicsi" href="${e(x.url)}" target="_blank" rel="noopener" aria-label="${e(x.cim)} megnyitása a webhelyen" title="Megnyitás a webhelyen">${I('kulso')}</a>`}</span></td></tr>`).join('')}</tbody></table></div>`
                : ures(f.nezet === 'kuka' ? 'A kuka üres.' : (q ? 'Nincs találat.' : 'Még nincs oldal.'), 'oldal');
        };
        const frissit = async () => { oldalak = (await api('admin/oldalak')).lista; kuka.lista = (await api('admin/oldalak', { query: { kuka: 1 } })).lista; A.oldalLista(true); rajzol(); };
        $('[data-szegmens]', hova).addEventListener('valtozas', (ev) => { f.nezet = ev.detail; rajzol(); });
        $('[data-q]', hova).addEventListener('input', kesleltet((ev) => { f.q = ev.target.value.trim(); rajzol(); }, 150));
        $('[data-uj]', hova).addEventListener('click', () => ujOldal(oldalak));
        lista.addEventListener('click', async (ev) => {
            const g = ev.target.closest('button');
            if (!g) return;
            const muv = [['masol', 'masol'], ['kezdo', 'kezdolap'], ['kuka', 'kuka'], ['vissza', 'vissza'], ['vegleges', '']].find(([d]) => g.dataset[d] !== undefined);
            if (!muv) return;
            const oid = g.dataset[muv[0]];
            await tolt(g, async () => {
                try {
                    if (muv[0] === 'vegleges') await api(`admin/kuka/${oid}/vegleges`, { method: 'POST', body: {} });
                    else {
                        const x = await api(`admin/oldal/${oid}/${muv[1]}`, { method: 'POST', body: {} });
                        if (muv[0] === 'masol') { jelez('Másolat elkészült (vázlat).'); location.hash = '#/oldalak/' + x.id; return; }
                    }
                    jelez({ kezdo: 'Ez lett a kezdőlap.', kuka: 'A kukába került – a Kuka nézetből visszaállítható.', vissza: 'Visszaállítva (vázlatként).', vegleges: 'Véglegesen törölve.' }[muv[0]]);
                    await frissit();
                } catch (x) { jelez(x.message, 'hiba'); }
            });
        });
        A.sorKattint(lista, '[data-oldal][tabindex]', (s) => { location.hash = '#/oldalak/' + s.dataset.oldal; });
        rajzol();
        return undefined;
    });

    function ujOldal(oldalak) {
        const { el, bezar } = popup({
            cim: 'Új oldal',
            meret: 'kicsi',
            test: `<div class="sa-mezo"><label class="sa-mezo__cim" for="st-uo-c">Cím<em aria-hidden="true">*</em></label><input class="sa-input" id="st-uo-c" maxlength="200" aria-required="true"></div>
                <div class="sa-mezo"><label class="sa-mezo__cim" for="st-uo-s">Útvonal (slug)</label><input class="sa-input sa-input--mono" id="st-uo-s" maxlength="80" placeholder="a címből készül"></div>
                <div class="sa-mezo"><label class="sa-mezo__cim" for="st-uo-p">Szülő oldal</label><select class="sa-select" id="st-uo-p"><option value="0">— Nincs (legfelső szint) —</option>${oldalak.map((x) => `<option value="${x.id}">/${e(x.ut)}</option>`).join('')}</select></div>
                <div class="sa-mezo"><label class="sa-mezo__cim" for="st-uo-m">Kiindulás</label><select class="sa-select" id="st-uo-m"><option value="">Üres oldal (egy nyitó szekcióval)</option>${oldalak.filter((x) => x.szekcios).map((x) => `<option value="${x.id}">Másolat: ${e(x.cim)}</option>`).join('')}</select></div>`,
            lab: `<button type="button" class="sa-gomb" data-bezar>Mégse</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('plusz')}Létrehozás vázlatként</button>`,
        });
        const c = $('#st-uo-c', el), s = $('#st-uo-s', el);
        c.addEventListener('input', () => { if (!s.dataset.kezi) s.value = c.value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); });
        s.addEventListener('input', () => { s.dataset.kezi = '1'; });
        $('[data-ment]', el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            if (!c.value.trim()) { popupHiba(el, 'Add meg az oldal címét.'); c.focus(); return; }
            try {
                let szekciok = [{ tipus: 'hero', cim: c.value.trim(), elrendezes: 'kozep' }];
                const m = Number($('#st-uo-m', el).value);
                if (m) szekciok = (await api('admin/oldal/' + m)).szekciok;
                const r = await api('admin/oldal', { method: 'POST', body: { cim: c.value.trim(), slug: s.value.trim(), szulo: Number($('#st-uo-p', el).value), szekciok } });
                bezar();
                A.oldalLista(true);
                jelez('Oldal létrehozva (vázlat).');
                location.hash = '#/oldalak/' + r.id;
            } catch (x) { popupHiba(el, x.message); }
        }));
    }

    /* ================================================================== Út (data-s-szerk) értelmezése */

    /** 'o:12/3/cim' → {fajta:'o', post:12, i:3, mezo:'cim'}; listaelem: {…, elem: 2, almezo: 'cim'}. */
    function utBont(ut) {
        const m = /^([ofbmt]):(.+)$/.exec(String(ut || ''));
        if (!m) return null;
        const d = m[2].split('/');
        if (m[1] === 'o') {
            const x = { fajta: 'o', post: Number(d[0]), i: Number(d[1]), mezo: d[2] || '' };
            if (d.length >= 5) { x.elem = Number(d[3]); x.almezo = d[4]; }
            // A téma gombsora: o:{post}/{i}/cta/{n} – az első a „gomb”, a második a „gomb2” mező.
            if (x.mezo === 'cta' && d.length === 4) { x.mezo = Number(d[3]) === 0 ? 'gomb' : 'gomb2'; }
            return Number.isInteger(x.i) ? x : null;
        }
        if (m[1] === 'f') return { fajta: 'f', kulcs: m[2] };
        if (m[1] === 'b') return { fajta: 'b', kulcs: m[2] };
        if (m[1] === 'm') return { fajta: 'm', hely: d[0], ind: d.slice(1).map(Number) };
        if (m[1] === 't') return { fajta: 't', tipus: d[0], kulcs: d[1] || '', mezo: d[2] || '' };
        return null;
    }
    A.utBont = utBont;

    /** A szekció sémájában egy (pontozott) mező leírása; listaelemnél az almező. */
    function mezoSema(tipus, mezo, almezo) {
        const t = SZT[tipus];
        if (!t) return null;
        const gyo = String(mezo).split('.')[0];
        const m = t.mezok.find((x) => x.k === mezo) || t.mezok.find((x) => x.k === gyo);
        if (!m) return null;
        if (almezo !== undefined && m.t === 'ismetlo') return (m.al || []).find((x) => x.k === almezo) || null;
        if (m.t === 'link' && mezo.endsWith('.szoveg')) return { k: mezo, t: 'szoveg', c: m.c + ' – felirat', max: 80 };
        return m;
    }

    /* ================================================================== Oldalépítő */

    async function epito(hova, id) {
        const o = await api('admin/oldal/' + id);
        const piszkozatJobb = o.vazlat && o.vazlat.ido > (o.modositva || 0) && JSON.stringify(o.vazlat.szekciok) !== JSON.stringify(o.szekciok);
        const E = {
            id, o, szekciok: JSON.parse(JSON.stringify(piszkozatJobb ? o.vazlat.szekciok : (o.szekciok || []))),
            mentett: JSON.stringify(o.szekciok || []), aktiv: -1, kijelolt: null, urlap: null,
            elozmeny: [], jovo: [], utolsoAllapot: '', meret: 'asztal', mod: '', vazlatIdo: piszkozatJobb ? o.vazlat.ido : 0,
        };
        E.aktiv = E.szekciok.length ? 0 : -1;
        E.utolsoAllapot = JSON.stringify(E.szekciok);
        const piszkos = () => JSON.stringify(E.szekciok) !== E.mentett;
        S.mentetlen = () => piszkos();
        cim(o.cim || '(cím nélkül)', '<a href="#/oldalak">Oldalak</a>');
        document.getElementById('sa-app').classList.add('is-epito');
        const takarit = () => { document.getElementById('sa-app')?.classList.remove('is-epito'); window.removeEventListener('hashchange', takarit); };
        window.addEventListener('hashchange', takarit);
        hova.className = 'sa-tartalom st-tartalom';
        hova.innerHTML = `<div class="st-epito" data-epito data-nezet="elo">
            <header class="st-eszkoz" role="toolbar" aria-label="Oldalépítő eszköztár">
                <a class="sa-ikon-gomb" href="#/oldalak" aria-label="Vissza az oldalakhoz" title="Vissza az oldalakhoz">${I('balra')}</a>
                <div class="st-eszkoz__cim"><button type="button" class="st-oldalvalto" data-oldalvalto aria-haspopup="listbox" title="Másik oldal megnyitása"><b data-cim>${e(o.cim || '(cím nélkül)')}</b>${I('le')}</button><span class="sa-mono sa-halvany sa-kicsi st-egysor" data-ut>/${e(o.ut)}</span></div>
                <span data-statusz>${cimke(...(STATUSZ[o.statusz] || [o.statusz, 'szurke']))}</span>
                <span class="sa-kicsi sa-halvany st-mentesjel" data-mentesjel aria-live="polite"></span>
                <span class="sa-ter"></span>
                <span class="st-csoport" role="group" aria-label="Visszavonás"><button type="button" class="sa-ikon-gomb" data-vissza aria-label="Visszavonás (Ctrl+Z)" title="Visszavonás (Ctrl+Z)" disabled>${I('visszavon')}</button><button type="button" class="sa-ikon-gomb" data-elore aria-label="Újra (Ctrl+Y)" title="Újra (Ctrl+Y)" disabled>${I('ujrabe')}</button></span>
                ${szegmens('Előnézet mérete', { asztal: `${I('asztal')}<span class="sa-sr">Asztali</span>`, tablet: `${I('tablet')}<span class="sa-sr">Tablet</span>`, mobil: `${I('mobil')}<span class="sa-sr">Mobil</span>` }, 'asztal').replace('data-szegmens=', 'data-meret data-szegmens=')}
                <button type="button" class="sa-ikon-gomb" data-mod-valt aria-label="Előnézet: világos / sötét" title="Előnézet: világos / sötét" aria-pressed="false">${I('hold')}</button>
                <button type="button" class="sa-ikon-gomb" data-frissit aria-label="Előnézet frissítése" title="Előnézet frissítése">${I('ujra')}</button>
                <a class="sa-ikon-gomb" data-uj-lap target="_blank" rel="noopener" href="${e(o.url)}" aria-label="Előnézet új lapon" title="Előnézet új lapon">${I('kulso')}</a>
                <button type="button" class="sa-gomb sa-gomb--kicsi" data-reviziok aria-label="Revíziók" title="Revíziók">${I('elozmeny')}<span>Revíziók</span></button>
                <button type="button" class="sa-gomb sa-gomb--kicsi" data-beallit aria-label="Az oldal beállításai (cím, útvonal, SEO)" title="Az oldal beállításai (cím, útvonal, SEO)">${I('beallitas')}<span>Oldal</span></button>
                <button type="button" class="sa-gomb sa-gomb--kicsi" data-elvet data-ketlepcsos="Biztos? Elvetem a nem közzétett változásokat" disabled>Elvetés</button>
                <button type="button" class="sa-gomb sa-gomb--fo" data-kozzetesz disabled>${I('kozzetesz')}Közzététel</button>
            </header>
            <div class="st-mobil-fulek" role="tablist" aria-label="Nézet">${[['fa', 'Szekciók'], ['elo', 'Előnézet'], ['tul', 'Szerkesztő']].map(([k, c]) => `<button type="button" role="tab" data-mobilful="${k}" aria-selected="${k === 'elo'}">${c}</button>`).join('')}</div>
            ${piszkozatJobb ? `<div class="st-sav st-sav--figyel" data-piszkozatsav role="status">${I('info')}<span>Mentetlen piszkozatot töltöttem be (${e(datumIdo(o.vazlat.ido))}${o.vazlat.ki ? ', ' + e(o.vazlat.ki) : ''}). A közzétételig a látogatók a korábbi változatot látják.</span><span class="sa-ter"></span><button type="button" class="sa-gomb sa-gomb--kicsi" data-piszkozat-elvet data-ketlepcsos="Biztos? A piszkozatot eldobom">Piszkozat eldobása</button></div>` : ''}
            <section class="st-fa" aria-label="Szekciók" data-panel="fa">
                <div class="st-panelfej"><h2>Szekciók <span class="sa-halvany" data-db></span></h2><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--fo" data-hozzaad>${I('plusz')}Szekció</button></div>
                <ol class="st-szlista" data-szlista aria-describedby="st-ep-sugo"></ol>
                <p class="sa-kicsi sa-halvany st-sugo" id="st-ep-sugo">Húzd a fogantyút, vagy fókuszáld és használd a ↑ ↓ billentyűt. Az előnézetben is kattinthatsz.</p>
            </section>
            <section class="st-elo" aria-label="Élő előnézet" data-panel="elo">
                <div class="st-elo__keret" data-keret><div class="st-elo__vaszon" data-vaszon><iframe title="Az oldal előnézete" data-iframe="0" tabindex="0"></iframe><iframe title="Az oldal előnézete" data-iframe="1" tabindex="-1" class="is-hatter" aria-hidden="true"></iframe></div><div class="st-elo__tolt" data-tolt hidden><span></span></div></div>
            </section>
            <section class="st-tul" aria-label="Tulajdonságok" data-panel="tul">
                <div class="st-panelfej"><div class="st-tul__fej"><span data-tulikon></span><div><h2 data-tulcim>Szekció</h2><small class="sa-halvany" data-tulal></small></div></div></div>
                <div class="st-tul__test" data-tul></div>
            </section>
        </div>`;
        const ep = $('[data-epito]', hova), lista = $('[data-szlista]', hova), tul = $('[data-tul]', hova);
        const iframes = $$('[data-iframe]', hova);
        let elol = 0; // a látható iframe indexe

        /* ---------------- Állapotjelzők */

        const jel = () => {
            const p = piszkos();
            $('[data-db]', hova).textContent = `(${E.szekciok.length})`;
            $('[data-kozzetesz]', hova).disabled = !p;
            $('[data-elvet]', hova).disabled = !p;
            $('[data-vissza]', hova).disabled = !E.elozmeny.length;
            $('[data-elore]', hova).disabled = !E.jovo.length;
            const mj = $('[data-mentesjel]', hova);
            mj.textContent = p ? (E.vazlatIdo ? `Piszkozat mentve ${new Date(E.vazlatIdo * 1000).toLocaleTimeString('hu-HU', { hour: '2-digit', minute: '2-digit' })}` : 'Mentetlen változás') : 'Minden közzétéve';
            mj.classList.toggle('is-piszkos', p);
        };

        /* ---------------- Előzmény (visszavonás / újra) */

        const pillanatkep = kesleltet(() => {
            const most = JSON.stringify(E.szekciok);
            if (most === E.utolsoAllapot) return;
            E.elozmeny.push(E.utolsoAllapot);
            if (E.elozmeny.length > 100) E.elozmeny.shift();
            E.jovo = [];
            E.utolsoAllapot = most;
            jel();
        }, 450);
        const visszaall = (honnan, hova2) => {
            if (!honnan.length) return;
            pillanatkep.flush?.();
            hova2.push(JSON.stringify(E.szekciok));
            E.szekciok = JSON.parse(honnan.pop());
            E.utolsoAllapot = JSON.stringify(E.szekciok);
            E.aktiv = Math.min(E.aktiv, E.szekciok.length - 1);
            E.kijelolt = null;
            listaRajzol(); tulRajzol(); jel(); elonezet(true); piszkozatMent();
        };
        const vissza = () => { visszaall(E.elozmeny, E.jovo); bemond('Visszavonva'); };
        const elore = () => { visszaall(E.jovo, E.elozmeny); bemond('Újra'); };

        /* ---------------- Piszkozat (automatikus mentés) */

        const piszkozatMent = kesleltet(async () => {
            if (!piszkos()) return;
            try { const r = await api(`admin/oldal/${id}/vazlat`, { method: 'POST', body: { szekciok: E.szekciok } }); E.vazlatIdo = r.ido; jel(); } catch (x) { /* a következő változásnál újra */ }
        }, 2500);

        const valtozott = (elo = true) => { pillanatkep(); piszkozatMent(); jel(); if (elo) elonezet(); };

        /* ---------------- Szekciólista */

        const szAlcim = (sz) => {
            const t = SZT[sz.tipus];
            const s1 = [sz.cim, sz.alcim, sz.szoveg].find((x) => typeof x === 'string' && x.trim());
            return s1 ? s1.replace(/<[^>]+>/g, '').slice(0, 80) : (t ? t.leiras : '');
        };
        const listaRajzol = (fokusz = null) => {
            lista.innerHTML = E.szekciok.map((sz, i) => {
                const t = SZT[sz.tipus] || { cim: sz.tipus, ikon: 'info' };
                return `<li class="st-szelem ${i === E.aktiv ? 'is-aktiv' : ''} ${sz.rejtett ? 'is-rejtett' : ''}" data-i="${i}">
                    <button type="button" class="sa-szelem__fogo" data-fogo aria-label="${i + 1}. szekció (${e(t.cim)}) áthelyezése – ↑ ↓">${I('fogo')}</button>
                    <button type="button" class="st-szelem__nyit" data-nyit aria-current="${i === E.aktiv}" title="${e(t.cim + ' – ' + szAlcim(sz))}">${WI(t.ikon)}<span><b>${e(t.cim)}${sz.rejtett ? ' · rejtett' : ''}</b><small>${e(szAlcim(sz))}</small></span></button>
                    <span class="st-szelem__gombok"><button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-rejt aria-label="${sz.rejtett ? 'Megjelenítés' : 'Elrejtés'}" aria-pressed="${!!sz.rejtett}" title="${sz.rejtett ? 'Megjelenítés' : 'Elrejtés'}">${I(sz.rejtett ? 'szem_ki' : 'szem')}</button>
                    <button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi" data-dup aria-label="Másolás" title="Másolás">${I('duplikal')}</button>
                    <button type="button" class="sa-ikon-gomb sa-ikon-gomb--kicsi sa-ikon-gomb--veszely" data-torol aria-label="Törlés" title="Törlés" data-ketlepcsos="Biztos? Katt újra a törléshez">${I('torol')}</button></span></li>`;
            }).join('') || `<li class="st-ures">${ures('Még nincs szekció. Adj hozzá egyet.', 'elem')}</li>`;
            jel();
            if (fokusz) { const li = lista.children[fokusz[0]]; const g = li && $(fokusz[1], li); if (g) g.focus(); }
        };

        /* ---------------- Tulajdonság-panel */

        const tulFej = (ikon, c, al) => {
            $('[data-tulikon]', hova).innerHTML = ikon;
            $('[data-tulcim]', hova).textContent = c;
            $('[data-tulal]', hova).textContent = al || '';
        };

        const tulRajzol = () => {
            const k = E.kijelolt;
            if (k && k.fajta !== 'o') return kontextusRajzol(k);
            const sz = E.szekciok[E.aktiv];
            if (!sz) { E.urlap = null; tulFej('', 'Szekció', ''); tul.innerHTML = ures('Válassz egy szekciót a bal oldalon, vagy kattints rá az előnézetben.', 'kijelol'); return; }
            const t = SZT[sz.tipus];
            tulFej(t ? WI(t.ikon) : '', `${E.aktiv + 1}. ${t ? t.cim : sz.tipus}`, t ? t.leiras : '');
            if (!t) { E.urlap = null; tul.innerHTML = ures(`Ismeretlen szekciótípus: ${sz.tipus}. Közzétételkor kimarad.`, 'figyel'); return; }
            const f = urlap(t.mezok, sz, { valtozas: () => urlapValtozott(), alapFul: 'Tartalom' });
            E.urlap = f;
            tul.innerHTML = '';
            tul.appendChild(f.el);
            const lab = document.createElement('div');
            lab.className = 'st-tul__lab';
            lab.innerHTML = `<button type="button" class="sa-gomb sa-gomb--kicsi" data-ugrik>${I('kijelol')}Mutasd az előnézetben</button>`;
            tul.appendChild(lab);
            $('[data-ugrik]', lab).addEventListener('click', () => { kijelolElo(E.aktiv, null, true); ep.dataset.nezet = 'elo'; mobilFul('elo'); });
            if (k && k.fajta === 'o' && k.i === E.aktiv) mezoKiemel(k);
        };

        /** A panelben a kijelölt mező megkeresése, a lista-elem kinyitása, villanó kiemelés. */
        const mezoKiemel = (k) => {
            if (!E.urlap) return;
            const gyo = String(k.mezo).split('.')[0];
            let w = $(`[data-mezo="${CSS.escape(gyo)}"]`, tul);
            if (!w) return;
            const panel = w.closest('.sa-ful-panel');
            if (panel && panel.hidden) { const g = $(`[aria-controls="${panel.id}"]`, tul); if (g) g.click(); }
            if (k.elem !== undefined && w.classList.contains('sa-ismetlo')) {
                const sor = $$('.sa-ismetlo__elem', w)[k.elem];
                if (sor) {
                    const ny = $('.sa-ismetlo__nyit', sor);
                    if (ny && ny.getAttribute('aria-expanded') !== 'true') ny.click();
                    const sor2 = $$('.sa-ismetlo__elem', w)[k.elem];
                    const belso = sor2 && $(`[data-mezo="${CSS.escape(k.almezo || '')}"]`, sor2);
                    if (belso) w = belso; else if (sor2) w = sor2;
                }
            }
            w.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            w.classList.remove('is-kiemelt'); void w.offsetWidth; w.classList.add('is-kiemelt');
        };

        const urlapValtozott = kesleltet(() => {
            const sz = E.szekciok[E.aktiv];
            if (!sz || !E.urlap) return;
            E.szekciok[E.aktiv] = Object.assign({}, sz, E.urlap.ertek(), { tipus: sz.tipus, rejtett: sz.rejtett });
            if (!E.szekciok[E.aktiv].rejtett) delete E.szekciok[E.aktiv].rejtett;
            const li = lista.children[E.aktiv];
            if (li && $('small', li)) $('small', li).textContent = szAlcim(E.szekciok[E.aktiv]);
            eloSzovegFrissit(E.aktiv);
            valtozott();
        }, 200);

        /* ---------------- Előnézet: két iframe váltakozva */

        const keret = $('[data-keret]', hova), vaszon = $('[data-vaszon]', hova);
        const meretez = () => {
            const w = MERET[E.meret];
            const kw = keret.clientWidth - 24;
            const sk = Math.min(1, kw / w);
            vaszon.style.width = w + 'px';
            vaszon.style.left = Math.max(12, 12 + (kw - w * sk) / 2) + 'px';
            vaszon.style.transform = `scale(${sk})`;
            vaszon.style.height = ((keret.clientHeight - 24) / sk) + 'px';
            vaszon.dataset.skala = sk;
        };
        new ResizeObserver(meretez).observe(keret);

        let eloSzam = 0;
        const doc = () => { try { return iframes[elol].contentDocument; } catch (x) { return null; } };
        const elonezet = kesleltet(async (azonnal) => {
            const n = ++eloSzam;
            $('[data-tolt]', hova).hidden = false;
            try {
                const r = await api('admin/elonezet', { method: 'POST', body: { post_id: id, szekciok: E.szekciok } });
                if (n !== eloSzam) return;
                const regi = iframes[elol], uj = iframes[1 - elol];
                const gorg = (() => { try { return regi.contentWindow.scrollY; } catch (x) { return 0; } })();
                uj.onload = () => {
                    if (n !== eloSzam) return;
                    try { uj.contentWindow.scrollTo(0, gorg); } catch (x) { /* */ }
                    elol = 1 - elol;
                    uj.classList.remove('is-hatter'); uj.removeAttribute('aria-hidden'); uj.tabIndex = 0;
                    regi.classList.add('is-hatter'); regi.setAttribute('aria-hidden', 'true'); regi.tabIndex = -1;
                    $('[data-tolt]', hova).hidden = true;
                    elokeszit(uj);
                };
                uj.src = r.url;
                $('[data-uj-lap]', hova).href = r.url;
            } catch (x) { $('[data-tolt]', hova).hidden = true; }
        }, 700);

        /** Az előnézeti dokumentum felkészítése: szekció-gyökerek, egérkövetés, kattintás, helyben szerkesztés. */
        const elokeszit = (ifr) => {
            let d;
            try { d = ifr.contentDocument; } catch (x) { return; }
            if (!d || !d.body) return;
            if (E.mod) d.documentElement.setAttribute('data-tema', E.mod);
            $$('template[data-s-szakasz]', d).forEach((t) => { const s = t.nextElementSibling; if (s) s.setAttribute('data-s-gyoker', t.getAttribute('data-s-szakasz')); });
            const st = d.createElement('style');
            st.textContent = `[data-s-gyoker],[data-s-szerk]{cursor:pointer}[data-s-szerk][contenteditable]{cursor:text;outline:2px solid #22d3ee!important;outline-offset:2px}
                .s-stud-jel{position:absolute;pointer-events:none;z-index:2147483000;border:2px solid #7c6cff;border-radius:6px;box-shadow:0 0 0 1px rgba(255,255,255,.6),0 0 18px rgba(124,108,255,.45);transition:all .08s}
                .s-stud-jel--fole{border-style:dashed;border-color:#22d3ee;box-shadow:none}
                .s-stud-jel b{position:absolute;left:-2px;top:-24px;background:#5b4cf0;color:#fff;font:600 11px/1 system-ui,sans-serif;padding:5px 8px;border-radius:6px 6px 6px 0;white-space:nowrap}
                .s-stud-jel--fole b{background:#0e6a80}`;
            d.head.appendChild(st);
            const fole = d.createElement('div'); fole.className = 's-stud-jel s-stud-jel--fole'; fole.hidden = true; fole.innerHTML = '<b></b>';
            const kij = d.createElement('div'); kij.className = 's-stud-jel'; kij.hidden = true; kij.innerHTML = '<b></b>';
            d.body.append(fole, kij);
            const w = ifr.contentWindow;
            const helyez = (jelolo, el, szoveg) => {
                if (!el) { jelolo.hidden = true; return; }
                const r = el.getBoundingClientRect();
                Object.assign(jelolo.style, { left: (r.left + w.scrollX - 3) + 'px', top: (r.top + w.scrollY - 3) + 'px', width: (r.width + 6) + 'px', height: (r.height + 6) + 'px' });
                jelolo.firstChild.textContent = szoveg;
                jelolo.hidden = false;
            };
            const cel = (t) => {
                const sz = t.closest && t.closest('[data-s-szerk]');
                const gy = t.closest && t.closest('[data-s-gyoker]');
                return { sz, gy };
            };
            const nev = (sz, gy) => {
                if (sz) {
                    const u = utBont(sz.getAttribute('data-s-szerk'));
                    if (u && u.fajta === 'o') { const s0 = E.szekciok[u.i]; if (!u.mezo) return s0 ? `${u.i + 1}. ${(SZT[s0.tipus] || { cim: s0.tipus }).cim}` : 'Szekció'; const m = s0 && mezoSema(s0.tipus, u.mezo, u.almezo); return (m ? m.c : u.mezo) + (u.elem !== undefined ? ` · ${u.elem + 1}. elem` : ''); }
                    return { f: 'Felirat', b: 'Beállítás (az egész webhelyen)', m: 'Menüpont', t: 'Tartalomtár-elem' }[u ? u.fajta : ''] || 'Elem';
                }
                if (gy) { const s0 = E.szekciok[Number(gy.getAttribute('data-s-gyoker'))]; return s0 ? `${Number(gy.getAttribute('data-s-gyoker')) + 1}. ${(SZT[s0.tipus] || { cim: s0.tipus }).cim}` : 'Szekció'; }
                return '';
            };
            let szerkesztett = null;
            d.addEventListener('mouseover', (ev) => { if (szerkesztett) return; const { sz, gy } = cel(ev.target); helyez(fole, sz || gy, nev(sz, gy)); });
            d.addEventListener('mouseleave', () => { fole.hidden = true; });
            const ujrahelyez = () => { if (E._kijEl && E._kijEl.isConnected) helyez(kij, E._kijEl, kij.firstChild.textContent); else kij.hidden = true; fole.hidden = true; };
            w.addEventListener('scroll', ujrahelyez, { passive: true });
            w.addEventListener('resize', ujrahelyez);
            d.addEventListener('submit', (ev) => ev.preventDefault(), true);
            d.addEventListener('click', (ev) => {
                const a = ev.target.closest('a, button, input, select, textarea, label, summary');
                if (szerkesztett && szerkesztett.contains(ev.target)) return;
                if (a) ev.preventDefault();
                const { sz, gy } = cel(ev.target);
                if (!sz && !gy) return;
                ev.preventDefault();
                ev.stopPropagation();
                if (sz) kijelolUt(sz.getAttribute('data-s-szerk'), sz, true);
                else kijelolElo(Number(gy.getAttribute('data-s-gyoker')), gy, false);
            }, true);
            d.addEventListener('keydown', (ev) => {
                if (szerkesztett && ev.key === 'Escape') { ev.preventDefault(); szerkesztett.blur(); return; }
                if (szerkesztett) return;
                billentyu(ev);
            });
            // Helyben szerkesztés
            E._helyben = (el, u, m) => {
                if (szerkesztett) szerkesztett.blur();
                const gazdag = m.t === 'html';
                el.setAttribute('contenteditable', gazdag ? 'true' : 'plaintext-only');
                if (el.getAttribute('contenteditable') !== (gazdag ? 'true' : 'plaintext-only')) el.setAttribute('contenteditable', 'true');
                el.spellcheck = true;
                szerkesztett = el;
                fole.hidden = true;
                el.focus();
                const be = () => {
                    const s0 = E.szekciok[u.i];
                    if (!s0) return;
                    const ertek = gazdag ? el.innerHTML : el.innerText.replace(/\n+$/, '');
                    if (u.elem !== undefined) {
                        const l = Array.isArray(s0[u.mezo]) ? s0[u.mezo].map((x) => Object.assign({}, x)) : [];
                        if (!l[u.elem]) return;
                        l[u.elem][u.almezo] = m.max ? ertek.slice(0, m.max) : ertek;
                        s0[u.mezo] = l;
                    } else {
                        A.beir(s0, u.mezo, m.max ? ertek.slice(0, m.max) : ertek);
                    }
                    const li = lista.children[u.i];
                    if (li && $('small', li)) $('small', li).textContent = szAlcim(s0);
                    valtozott(false);
                    helyez(kij, el, kij.firstChild.textContent);
                };
                const ki = () => {
                    el.removeEventListener('input', be);
                    el.removeEventListener('blur', ki);
                    el.removeEventListener('paste', beill);
                    el.removeAttribute('contenteditable');
                    szerkesztett = null;
                    if (u.i === E.aktiv) tulRajzol();
                    elonezet();
                };
                const beill = (ev) => { if (!gazdag) return; ev.preventDefault(); d.execCommand('insertText', false, (ev.clipboardData || w.clipboardData).getData('text/plain')); };
                el.addEventListener('input', be);
                el.addEventListener('blur', ki);
                el.addEventListener('paste', beill);
                bemond('Helyben szerkesztés – Esc: kész');
            };
            E._jeloloHelyez = (el, szoveg) => helyez(kij, el, szoveg);
            // A korábbi kijelölés visszaállítása az új dokumentumban.
            if (E.kijelolt && E.kijelolt.ut) { const el = $(`[data-s-szerk="${CSS.escape(E.kijelolt.ut)}"]`, d); if (el) { E._kijEl = el; helyez(kij, el, nev(el, null)); } }
            else if (E.aktiv >= 0) { const el = $(`[data-s-gyoker="${E.aktiv}"]`, d); E._kijEl = el; if (el) helyez(kij, el, nev(null, el)); }
        };

        /** Szöveges mezők azonnali frissítése az előnézetben (a teljes újrarajzolás előtt). */
        const eloSzovegFrissit = (i) => {
            const d = doc();
            const sz = E.szekciok[i];
            if (!d || !sz) return;
            $$(`[data-s-szerk^="o:${id}/${i}/"]`, d).forEach((el) => {
                if (el.hasAttribute('contenteditable')) return;
                const u = utBont(el.getAttribute('data-s-szerk'));
                const m = u && mezoSema(sz.tipus, u.mezo, u.almezo);
                if (!m || !SZOVEGES.includes(m.t)) return;
                const v = u.elem !== undefined ? ((sz[u.mezo] || [])[u.elem] || {})[u.almezo] : A.kiolvas(sz, u.mezo);
                if (typeof v === 'string' && !v.includes('{')) el.textContent = v;
            });
        };

        /** Szekció kijelölése (listából vagy az előnézetből). */
        const kijelolElo = (i, el, gorget) => {
            if (!E.szekciok[i]) return;
            E.kijelolt = null;
            if (E.aktiv !== i) { E.aktiv = i; listaRajzol(); }
            else $$('.st-szelem', lista).forEach((x, j) => x.classList.toggle('is-aktiv', j === i));
            tulRajzol();
            const d = doc();
            const cel2 = el || (d && $(`[data-s-gyoker="${i}"]`, d));
            E._kijEl = cel2;
            if (cel2 && E._jeloloHelyez) {
                E._jeloloHelyez(cel2, `${i + 1}. ${(SZT[E.szekciok[i].tipus] || { cim: '' }).cim}`);
                if (gorget) cel2.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
            const li = lista.children[i];
            if (li) li.scrollIntoView({ block: 'nearest' });
        };

        /** Mező (data-s-szerk) kijelölése; szöveges mezőnél helyben szerkesztés. */
        const kijelolUt = (ut, el, kattintas) => {
            const u = utBont(ut);
            if (!u) return;
            u.ut = ut;
            E._kijEl = el;
            if (u.fajta === 'o') {
                if (u.post !== id || !E.szekciok[u.i]) return;
                E.kijelolt = u;
                if (E.aktiv !== u.i) { E.aktiv = u.i; listaRajzol(); } else $$('.st-szelem', lista).forEach((x, j) => x.classList.toggle('is-aktiv', j === u.i));
                tulRajzol();
                mobilFul(window.innerWidth <= 960 ? 'tul' : null);
                const sz = E.szekciok[u.i];
                const m = mezoSema(sz.tipus, u.mezo, u.almezo);
                if (E._jeloloHelyez && el) E._jeloloHelyez(el, !u.mezo ? `${u.i + 1}. ${(SZT[sz.tipus] || { cim: sz.tipus }).cim}` : (m ? m.c : u.mezo) + (u.elem !== undefined ? ` · ${u.elem + 1}. elem` : ''));
                if (!m || !kattintas) return;
                const ertek = u.elem !== undefined ? ((sz[u.mezo] || [])[u.elem] || {})[u.almezo] : A.kiolvas(sz, u.mezo);
                if (m.t === 'kep') { A.mediaValaszt({ cim: m.c, tipus: 'image' }).then((x) => { if (!x) return; if (u.elem !== undefined) { const l = (sz[u.mezo] || []).map((y) => Object.assign({}, y)); if (l[u.elem]) { l[u.elem][u.almezo] = x.url; sz[u.mezo] = l; } } else A.beir(sz, u.mezo, x.url); tulRajzol(); valtozott(); }); return; }
                if (SZOVEGES.includes(m.t) || m.t === 'html') {
                    const lathato = (el.innerText || '').replace(/\s+/g, ' ').trim();
                    const tarolt = String(ertek ?? '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
                    if (typeof ertek === 'string' && ertek.includes('{')) { jelez('Ez a szöveg tokent tartalmaz ({…}) – a jobb oldali mezőben szerkeszd.', 'figyel'); return; }
                    if (m.t !== 'html' && lathato !== tarolt && tarolt !== '') { jelez('Itt a megjelenített szöveg eltér a tárolttól – a jobb oldali mezőben szerkeszd.', 'figyel'); return; }
                    if (E._helyben) E._helyben(el, u, m);
                }
                return;
            }
            E.kijelolt = u;
            $$('.st-szelem', lista).forEach((x) => x.classList.remove('is-aktiv'));
            if (E._jeloloHelyez && el) E._jeloloHelyez(el, { f: 'Felirat', b: 'Beállítás', m: 'Menüpont', t: 'Tartalomtár-elem' }[u.fajta]);
            tulRajzol();
            mobilFul(window.innerWidth <= 960 ? 'tul' : null);
        };

        /* ---------------- Kontextus-szerkesztő (felirat, beállítás, menüpont, tartalomtár-elem) */

        let beallCache = null;
        async function kontextusRajzol(u) {
            E.urlap = null;
            const vissza2 = `<div class="st-tul__lab"><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--szoveg" data-vissza-szekcio>${I('balra')}Vissza a szekcióhoz</button></div>`;
            const vege = () => { const g = $('[data-vissza-szekcio]', tul); if (g) g.addEventListener('click', () => { E.kijelolt = null; listaRajzol(); tulRajzol(); }); };
            if (u.fajta === 'f') {
                tulFej(I('felirat'), 'Felirat', 'A téma beépített szövege – minden oldalon ugyanez.');
                tul.innerHTML = A.csontvaz ? '<div class="sa-csontvaz" style="height:120px;border-radius:12px"></div>' : '';
                const r = await api('admin/feliratok');
                const x = r.csoportok.flatMap((c) => c.elemek).find((y) => y.kulcs === u.kulcs) || { kulcs: u.kulcs, alap: '', ertek: '', leiras: '' };
                tul.innerHTML = `<div class="sa-mezok"><div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-f-e">Szöveg</label><input class="sa-input" id="st-f-e" maxlength="500" value="${e(x.ertek || x.alap)}"><span class="sa-mezo__sugo">Alap: „${e(x.alap)}” · kulcs: <span class="sa-mono">${e(x.kulcs)}</span>${x.leiras ? ' · ' + e(x.leiras) : ''}</span></div></div>
                    <div class="sa-sor sa-sor--veg"><button type="button" class="sa-gomb" data-alap>${I('visszaall')}Alapra</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button></div>${vissza2}`;
                const inp = $('#st-f-e', tul);
                const ment = (ertek, g) => tolt(g, async () => { try { await api('admin/feliratok', { method: 'POST', body: { ertekek: { [u.kulcs]: ertek } } }); jelez('Felirat mentve – minden oldalon él.'); elonezet(true); } catch (y) { jelez(y.message, 'hiba'); } });
                $('[data-ment]', tul).addEventListener('click', (ev) => ment(inp.value, ev.currentTarget));
                $('[data-alap]', tul).addEventListener('click', (ev) => { inp.value = x.alap; ment('', ev.currentTarget); });
                inp.focus();
                return vege();
            }
            if (u.fajta === 'b') {
                tulFej(I('beallitas'), 'Beállítás', 'Az egész webhelyre hat, azonnal mentődik.');
                if (u.kulcs === 'arculat.logo' || u.kulcs === 'arculat.logo_vilagos') {
                    tulFej(I('kep'), 'Logó', 'A fejlécben és a láblécben – azonnal mentődik.');
                    const a = await api('admin/arculat');
                    tul.innerHTML = `<div class="sa-mezok">${['logo', 'logo_vilagos'].map((k) => `<div class="sa-mezo sa-mezo--szeles"><span class="sa-mezo__cim">${k === 'logo' ? 'Logó sötét háttérre' : 'Logó világos háttérre'}</span><div class="st-logo-elo st-logo-elo--${k === 'logo' ? 'sotet' : 'vilagos'}" data-elo="${k}">${a[k] ? `<img src="${e(a[k])}" alt="">` : '<span class="sa-kicsi">Nincs – a terméknév jelenik meg</span>'}</div><div class="sa-sor sa-sor--tor" style="margin-top:6px"><button type="button" class="sa-gomb sa-gomb--kicsi" data-logo="${k}">${I('kep')}Médiatár</button><button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--szoveg" data-logo-ki="${k}">Eltávolítás</button></div></div>`).join('')}</div>${vissza2}`;
                    $('.sa-mezok', tul).addEventListener('click', async (ev) => {
                        const g = ev.target.closest('[data-logo], [data-logo-ki]');
                        if (!g) return;
                        const k = g.dataset.logo || g.dataset.logoKi;
                        let url = '';
                        if (g.dataset.logo) { const x = await A.mediaValaszt({ cim: 'Logó kiválasztása' }); if (!x) return; url = x.url; }
                        try { const r = await api('admin/arculat', { method: 'POST', body: { [k]: url } }); $(`[data-elo="${k}"]`, tul).innerHTML = r[k] ? `<img src="${e(r[k])}" alt="">` : '<span class="sa-kicsi">Nincs – a terméknév jelenik meg</span>'; jelez('Logó mentve.'); elonezet(true); } catch (y) { jelez(y.message, 'hiba'); }
                    });
                    return vege();
                }
                beallCache = beallCache || await api('admin/beallitasok');
                const osszes = Object.values(beallCache.csoportok).flatMap((c) => c.mezok);
                // Egy kulcs, vagy egy csoport (pl. b:ceg – a cég összes adata).
                let mezok = osszes.filter((y) => y.k === u.kulcs);
                if (!mezok.length) mezok = osszes.filter((y, i, a) => y.k.startsWith(u.kulcs + '.') && a.findIndex((z) => z.k === y.k) === i);
                mezok = mezok.filter((y) => y.t !== 'titok');
                if (!mezok.length) { tul.innerHTML = ures('Ez a beállítás itt nem szerkeszthető – a Beállítások között találod.', 'beallitas') + vissza2; return vege(); }
                const f = urlap(mezok, beallCache.ertekek, {});
                tul.innerHTML = '';
                tul.appendChild(f.el);
                tul.insertAdjacentHTML('beforeend', `<div class="sa-sor sa-sor--veg"><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button></div>${vissza2}`);
                $('[data-ment]', tul).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
                    try { const x = await api('admin/beallitasok', { method: 'POST', body: { ertekek: f.ertek() } }); beallCache.ertekek = x.ertekek; jelez('Beállítás mentve.'); elonezet(true); } catch (y) { jelez(y.message, 'hiba'); }
                }));
                return vege();
            }
            if (u.fajta === 'm') {
                tulFej(I('menu'), 'Menüpont', (B.helyek || {})[u.hely] || u.hely);
                const r = await api('admin/menuk');
                const fa = (r.helyek[u.hely] || {}).elemek || [];
                let elem = null, l = fa;
                u.ind.forEach((n, j) => { elem = l[n]; l = elem && j < u.ind.length - 1 ? (elem.gyerekek || []) : []; });
                if (!elem) { tul.innerHTML = ures('A menüpont nem található.', 'menu') + vissza2; return vege(); }
                tul.innerHTML = `<div class="sa-mezok"><div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-m-c">Felirat</label><input class="sa-input" id="st-m-c" maxlength="80" value="${e(elem.cim)}"></div>
                    <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-m-u">Cél</label><div class="sa-mezo__sor"><input class="sa-input sa-input--mono" id="st-m-u" value="${e(elem.oldal || elem.url || '')}"><button type="button" class="sa-ikon-gomb" data-ov aria-label="Oldal választása" title="Oldal választása">${I('oldal')}</button></div></div></div>
                    <div class="sa-sor sa-sor--veg"><a class="sa-gomb" href="#/menuk/${e(u.hely)}">${I('menu')}Teljes menü</a><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button></div>${vissza2}`;
                $('[data-ov]', tul).addEventListener('click', (ev) => A.oldalValaszto(ev.currentTarget, (x) => { $('#st-m-u', tul).value = x.replace(/^\/|\/$/g, ''); }));
                $('[data-ment]', tul).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
                    const c = $('#st-m-c', tul).value.trim(), cel2 = $('#st-m-u', tul).value.trim();
                    if (!c) { jelez('A felirat kötelező.', 'hiba'); return; }
                    elem.cim = c; delete elem.oldal; delete elem.url;
                    if (cel2 && !/^(\/|#|https?:|mailto:|tel:)/i.test(cel2)) elem.oldal = cel2.replace(/^\/|\/$/g, ''); else elem.url = cel2;
                    try { await api('admin/menu/' + u.hely, { method: 'POST', body: { elemek: fa } }); jelez('Menüpont mentve.'); elonezet(true); } catch (y) { jelez(y.message, 'hiba'); }
                }));
                return vege();
            }
            if (u.fajta === 't') {
                const s = (B.elemek || {})[u.tipus];
                tulFej(s ? WI(s.ikon) : '', s ? s.egyes : 'Tartalomtár-elem', 'Közös elem – minden oldalon, ahol megjelenik.');
                tul.innerHTML = `<p class="sa-kicsi sa-halvany" style="margin:0 0 12px">A(z) <b>${e(u.kulcs)}</b> elem a tartalomtárban él; a módosítás mindenhol érvényes.</p><div class="sa-sor"><button type="button" class="sa-gomb sa-gomb--fo" data-nyit>${I('ceruza')}Szerkesztés</button></div>${vissza2}`;
                $('[data-nyit]', tul).addEventListener('click', async () => {
                    if (!A.elemSzerkeszt) return;
                    const r = await api('admin/elemek', { query: { tipus: u.tipus } });
                    const x = (r.lista || []).find((y) => y.kulcs === u.kulcs);
                    if (!x) { jelez('Az elem nem található.', 'hiba'); return; }
                    A.elemSzerkeszt(u.tipus, x, () => elonezet(true), u.mezo);
                });
                return vege();
            }
            return undefined;
        }

        /* ---------------- Mobil nézetváltó */

        const mobilFul = (k) => {
            if (!k) return;
            ep.dataset.nezet = k;
            $$('[data-mobilful]', hova).forEach((g) => g.setAttribute('aria-selected', String(g.dataset.mobilful === k)));
        };
        $('.st-mobil-fulek', hova).addEventListener('click', (ev) => { const g = ev.target.closest('[data-mobilful]'); if (g) mobilFul(g.dataset.mobilful); });

        /* ---------------- Események */

        lista.addEventListener('click', (ev) => {
            const li = ev.target.closest('[data-i]');
            if (!li) return;
            const i = Number(li.dataset.i);
            if (ev.target.closest('[data-nyit]')) { kijelolElo(i, null, true); if (window.innerWidth <= 960) mobilFul('tul'); return; }
            if (ev.target.closest('[data-rejt]')) { E.szekciok[i].rejtett = !E.szekciok[i].rejtett; if (!E.szekciok[i].rejtett) delete E.szekciok[i].rejtett; listaRajzol([i, '[data-rejt]']); bemond(E.szekciok[i].rejtett ? 'Szekció elrejtve' : 'Szekció látható'); valtozott(); return; }
            if (ev.target.closest('[data-dup]')) { E.szekciok.splice(i + 1, 0, JSON.parse(JSON.stringify(E.szekciok[i]))); E.aktiv = i + 1; E.kijelolt = null; listaRajzol([i + 1, '[data-nyit]']); tulRajzol(); bemond('Szekció másolva'); valtozott(); return; }
            if (ev.target.closest('[data-torol]')) { E.szekciok.splice(i, 1); E.aktiv = Math.min(E.aktiv >= i ? E.aktiv - (E.aktiv > i ? 1 : 0) : E.aktiv, E.szekciok.length - 1); E.kijelolt = null; listaRajzol(E.szekciok.length ? [Math.max(0, i - 1), '[data-nyit]'] : null); tulRajzol(); bemond('Szekció törölve – Ctrl+Z visszahozza'); valtozott(); }
        });
        rendezheto(lista, '.st-szelem', '[data-fogo]', (regi, uj) => {
            const [x] = E.szekciok.splice(regi, 1);
            E.szekciok.splice(uj, 0, x);
            if (E.aktiv === regi) E.aktiv = uj; else if (E.aktiv > regi && E.aktiv <= uj) E.aktiv--; else if (E.aktiv < regi && E.aktiv >= uj) E.aktiv++;
            E.kijelolt = null;
            listaRajzol([uj, '[data-fogo]']);
            tulRajzol();
            bemond(`Áthelyezve: ${uj + 1}. hely / ${E.szekciok.length}`);
            valtozott();
        });

        $('[data-hozzaad]', hova).addEventListener('click', () => szekcioKonyvtar((tipus) => {
            const uj = { tipus };
            (SZT[tipus].mezok || []).forEach((m) => { if (m.alap !== undefined) uj[m.k] = m.alap; });
            if (SZT[tipus].mezok.some((m) => m.k === 'cim')) uj.cim = SZT[tipus].cim;
            const hol = E.aktiv >= 0 ? E.aktiv + 1 : E.szekciok.length;
            E.szekciok.splice(hol, 0, uj);
            E.aktiv = hol;
            E.kijelolt = null;
            listaRajzol([hol, '[data-nyit]']);
            tulRajzol();
            valtozott();
            setTimeout(() => kijelolElo(hol, null, true), 1400);
        }));

        const kozzetesz = (g) => tolt(g, async () => {
            if (E.urlap) { const h = E.urlap.ellenoriz(); if (h.length) { jelez(h.join(' '), 'hiba'); return; } }
            try {
                const r = await api('admin/oldal/' + id, { method: 'POST', body: { szekciok: E.szekciok } });
                E.szekciok = r.szekciok.map((x) => Object.assign({}, x));
                E.mentett = JSON.stringify(r.szekciok);
                E.utolsoAllapot = E.mentett;
                E.vazlatIdo = 0;
                E.aktiv = Math.min(E.aktiv, E.szekciok.length - 1);
                $('[data-piszkozatsav]', hova)?.remove();
                listaRajzol(); tulRajzol(); jel();
                jelez(o.statusz === 'publish' ? 'Közzétéve – a látogatók már az új változatot látják.' : 'Mentve. Az oldal még vázlat – az Oldal beállításainál teheted közzé.');
            } catch (x) {
                jelez(x.message, 'hiba');
            }
        });
        $('[data-kozzetesz]', hova).addEventListener('click', (ev) => kozzetesz(ev.currentTarget));
        $('[data-elvet]', hova).addEventListener('click', async () => {
            E.elozmeny.push(JSON.stringify(E.szekciok));
            E.szekciok = JSON.parse(E.mentett);
            E.utolsoAllapot = E.mentett;
            E.aktiv = E.szekciok.length ? Math.min(Math.max(0, E.aktiv), E.szekciok.length - 1) : -1;
            E.kijelolt = null;
            listaRajzol(); tulRajzol(); jel(); elonezet(true);
            try { await api(`admin/oldal/${id}/vazlat/elvet`, { method: 'POST', body: {} }); E.vazlatIdo = 0; jel(); } catch (x) { /* */ }
            $('[data-piszkozatsav]', hova)?.remove();
            jelez('Változások elvetve (Ctrl+Z visszahozza).');
        });
        const ps = $('[data-piszkozat-elvet]', hova);
        if (ps) ps.addEventListener('click', () => $('[data-elvet]', hova).click());
        $('[data-vissza]', hova).addEventListener('click', vissza);
        $('[data-elore]', hova).addEventListener('click', elore);
        $('[data-frissit]', hova).addEventListener('click', () => elonezet(true));
        $('[data-meret]', hova).addEventListener('valtozas', (ev) => { E.meret = ev.detail; meretez(); });
        $('[data-mod-valt]', hova).addEventListener('click', (ev) => {
            const d = doc();
            const most = E.mod || (d && d.documentElement.getAttribute('data-tema')) || 'sotet';
            E.mod = most === 'vilagos' ? 'sotet' : 'vilagos';
            ev.currentTarget.setAttribute('aria-pressed', String(E.mod === 'vilagos'));
            ev.currentTarget.innerHTML = I(E.mod === 'vilagos' ? 'nap' : 'hold');
            iframes.forEach((f) => { try { f.contentDocument.documentElement.setAttribute('data-tema', E.mod); } catch (x) { /* */ } });
        });
        $('[data-reviziok]', hova).addEventListener('click', () => reviziok(E, (szekciok) => { E.elozmeny.push(JSON.stringify(E.szekciok)); E.szekciok = szekciok; E.aktiv = Math.min(Math.max(0, E.aktiv), E.szekciok.length - 1); E.kijelolt = null; listaRajzol(); tulRajzol(); valtozott(); jelez('A korábbi változat betöltve – Közzététellel élesíted.'); }));
        $('[data-beallit]', hova).addEventListener('click', () => oldalBeallitasok(E, (r) => {
            o.cim = r.cim; o.statusz = r.statusz; o.ut = r.ut; o.url = r.url;
            $('[data-cim]', hova).textContent = r.cim; $('[data-ut]', hova).textContent = '/' + r.ut;
            $('[data-statusz]', hova).innerHTML = cimke(...(STATUSZ[r.statusz] || [r.statusz, 'szurke']));
            cim(r.cim, '<a href="#/oldalak">Oldalak</a>');
            elonezet(true);
        }));
        $('[data-oldalvalto]', hova).addEventListener('click', (ev) => A.oldalValaszto(ev.currentTarget, async (ut) => {
            const l = await A.oldalLista();
            const x = l.find((y) => '/' + y.ut + '/' === ut);
            if (x) location.hash = '#/oldalak/' + x.id;
        }));

        function billentyu(ev) {
            const k = ev.key.toLowerCase();
            const mezoben = ev.target && ev.target.closest && ev.target.closest('input, textarea, select, [contenteditable]');
            if ((ev.ctrlKey || ev.metaKey) && k === 's') { ev.preventDefault(); if (piszkos()) kozzetesz($('[data-kozzetesz]', hova)); return; }
            if (mezoben) return;
            if ((ev.ctrlKey || ev.metaKey) && k === 'z' && !ev.shiftKey) { ev.preventDefault(); vissza(); }
            if ((ev.ctrlKey || ev.metaKey) && (k === 'y' || (k === 'z' && ev.shiftKey))) { ev.preventDefault(); elore(); }
        }
        hova.addEventListener('keydown', billentyu);

        listaRajzol();
        tulRajzol();
        jel();
        meretez();
        elonezet(true);
    }

    /* ================================================================== Szekciókönyvtár */

    function szekcioKonyvtar(kesz) {
        const csop = {};
        Object.entries(SZT).forEach(([k, t]) => { (csop[t.csoport] = csop[t.csoport] || []).push([k, t]); });
        const { el, bezar } = popup({
            cim: 'Szekció hozzáadása',
            meret: 'szeles',
            test: `<div class="sa-sor sa-sor--tor"><input class="sa-input" type="search" data-szur placeholder="Keresés (név, leírás)…" aria-label="Szekciótípus keresése" data-autofokusz style="flex:1;min-width:200px">
                <div class="sa-sor sa-sor--tor" role="group" aria-label="Csoport">${['Mind', ...Object.keys(csop)].map((c, i) => `<button type="button" class="sa-valaszto-pill" data-csopszur="${e(c)}" aria-pressed="${i === 0}">${e(c)}</button>`).join('')}</div></div>
                <div class="st-konyvtar" data-racs>${Object.entries(csop).map(([c, l]) => l.map(([k, t]) => `<button type="button" class="st-konyvtar__elem" data-tipus="${e(k)}" data-csop="${e(c)}" data-kereso="${e((t.cim + ' ' + t.leiras + ' ' + k + ' ' + c).toLowerCase())}">${vazlatKep(k)}<span><b>${e(t.cim)}</b><small>${e(t.leiras)}</small></span></button>`).join('')).join('')}</div>`,
        });
        let cs = 'Mind';
        const szur = () => {
            const q = $('[data-szur]', el).value.trim().toLowerCase();
            $$('[data-tipus]', el).forEach((g) => { g.hidden = (!!q && !g.dataset.kereso.includes(q)) || (cs !== 'Mind' && g.dataset.csop !== cs); });
        };
        $('[data-szur]', el).addEventListener('input', szur);
        $('[data-szur]', el).addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { const g = $$('[data-tipus]', el).find((x) => !x.hidden); if (g) g.click(); } });
        el.addEventListener('click', (ev) => {
            const p = ev.target.closest('[data-csopszur]');
            if (p) { cs = p.dataset.csopszur; $$('[data-csopszur]', el).forEach((x) => x.setAttribute('aria-pressed', String(x === p))); szur(); return; }
            const g = ev.target.closest('[data-tipus]');
            if (!g) return;
            bezar();
            kesz(g.dataset.tipus);
        });
    }

    /* ================================================================== Revíziók */

    async function reviziok(E, betolt) {
        const { el, bezar } = popup({ cim: 'Revíziók – közzétett változatok', meret: 'szeles', test: '<div class="sa-csontvaz" style="height:240px;border-radius:12px"></div>', lab: '<span class="sa-kicsi sa-halvany">Minden közzététel egy revízió. Betöltés után Közzététellel élesítheted.</span><span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Bezárás</button>' });
        const test = $('.sa-popup__test', el);
        try {
            const r = await api(`admin/oldal/${E.id}/reviziok`);
            test.innerHTML = r.lista.length ? `<div class="sa-tabla-doboz"><table class="sa-tabla"><thead><tr><th>Mikor</th><th>Ki</th><th>Változás</th><th class="sa-jobb"><span class="sa-sr">Műveletek</span></th></tr></thead><tbody>${r.lista.map((x, i) => `<tr><td>${e(datumIdo(x.ido))}${i === 0 ? ' ' + cimke('legutóbbi', 'kiemelo') : ''}</td><td class="sa-halvany">${e(x.ki)}</td><td class="sa-halvany"><div class="sa-egysor" style="--sa-cella-max:260px" title="${e(x.szoveg)}">${e(x.szoveg)}</div></td>
                <td class="sa-jobb"><span class="sa-sor" style="justify-content:flex-end;gap:4px"><button type="button" class="sa-gomb sa-gomb--kicsi" data-osszevet="${x.id}">${I('lista')}Összevetés</button><button type="button" class="sa-gomb sa-gomb--kicsi" data-betolt="${x.id}">${I('visszaall')}Ezt töltsd be</button></span></td></tr>`).join('')}</tbody></table></div><div data-diff></div>`
                : ures('Ehhez az oldalhoz még nincs mentett revízió.', 'elozmeny');
        } catch (x) { test.innerHTML = A.hibaKartya(x); return; }
        test.addEventListener('click', (ev) => {
            const g = ev.target.closest('[data-osszevet], [data-betolt]');
            if (!g) return;
            tolt(g, async () => {
                try {
                    const n = await api('admin/naplo/' + (g.dataset.osszevet || g.dataset.betolt));
                    if (g.dataset.betolt) { if (Array.isArray(n.utana)) { bezar(); betolt(n.utana); } return; }
                    const nevez = (l) => Object.fromEntries((Array.isArray(l) ? l : []).map((s, i) => [`${i + 1}. ${(SZT[s.tipus] || { cim: s.tipus }).cim}`, s]));
                    $('[data-diff]', test).innerHTML = `<h3 class="sa-kartya__cim" style="margin:16px 0 8px">Mi változott ebben a mentésben</h3><div style="max-height:min(40vh,380px);overflow:auto">${A.diffHtml(nevez(n.elotte), nevez(n.utana))}</div>`;
                } catch (x) { jelez(x.message, 'hiba'); }
            });
        });
    }

    /* ================================================================== Oldal-beállítások (cím, slug, szülő, sablon, SEO) */

    async function oldalBeallitasok(E, kesz) {
        const o = await api('admin/oldal/' + E.id);
        const oldalak = (await A.oldalLista()).filter((x) => x.id !== E.id);
        const sz = (n, max) => `<span class="sa-szamlalo" data-sz="${n}" data-max="${max}" aria-hidden="true"></span>`;
        const { el, bezar } = popup({
            cim: 'Oldal beállításai',
            meret: 'kozepes',
            test: A.fulHtml([
                ['alap', 'Oldal', `<div class="sa-mezok">
                    <div class="sa-mezo"><label class="sa-mezo__cim" for="st-ob-c">Cím</label><input class="sa-input" id="st-ob-c" maxlength="200" value="${e(o.cim)}"></div>
                    <div class="sa-mezo"><label class="sa-mezo__cim" for="st-ob-s">Útvonal (slug)</label><input class="sa-input sa-input--mono" id="st-ob-s" maxlength="200" value="${e(o.slug)}"></div>
                    <div class="sa-mezo"><label class="sa-mezo__cim" for="st-ob-p">Szülő oldal</label><select class="sa-select" id="st-ob-p"><option value="0">— Nincs —</option>${oldalak.map((x) => `<option value="${x.id}" ${x.id === o.szulo ? 'selected' : ''}>/${e(x.ut)}</option>`).join('')}</select></div>
                    <div class="sa-mezo"><label class="sa-mezo__cim" for="st-ob-t">Sablon</label><select class="sa-select" id="st-ob-t">${Object.entries(o.sablonok || { '': 'Alap' }).map(([k, v]) => `<option value="${e(k)}" ${k === o.sablon ? 'selected' : ''}>${e(v)}</option>`).join('')}</select></div>
                    <div class="sa-mezo"><label class="sa-mezo__cim" for="st-ob-st">Állapot</label><select class="sa-select" id="st-ob-st">${Object.entries({ publish: 'Közzétéve', draft: 'Vázlat', private: 'Privát' }).map(([k, v]) => `<option value="${k}" ${o.statusz === k ? 'selected' : ''}>${v}</option>`).join('')}</select></div>
                    <div class="sa-mezo"><label class="sa-mezo__cim" for="st-ob-o">Sorrend (menük, listák)</label><input class="sa-input" id="st-ob-o" inputmode="numeric" value="${e(o.sorrend || 0)}"></div>
                </div>
                <div class="sa-sor sa-sor--tor" style="margin-top:14px">${o.kezdolap ? cimke('Ez a kezdőlap', 'kiemelo') : `<button type="button" class="sa-gomb sa-gomb--kicsi" data-kezdo data-ketlepcsos="Biztos? Ez lesz a kezdőlap" ${o.statusz !== 'publish' ? 'disabled title="Csak közzétett oldal lehet kezdőlap"' : ''}>${I('csillag')}Legyen a kezdőlap</button>`}
                    <button type="button" class="sa-gomb sa-gomb--kicsi" data-masol>${I('duplikal')}Másolat</button>
                    ${o.kezdolap ? '' : `<button type="button" class="sa-gomb sa-gomb--kicsi sa-gomb--veszely" data-kuka data-ketlepcsos="Biztos? A kukába teszem">${I('torol')}Kukába</button>`}</div>`],
                ['seo', 'SEO és megosztás', `<div class="sa-mezok">
                    <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-seo-t">Keresőben megjelenő cím (title)${sz('t', 60)}</label><input class="sa-input" id="st-seo-t" maxlength="200" value="${e(o.seo.title)}" placeholder="${e(o.cim)}" title="Ajánlott: 50–60 karakter (cím), 120–160 (leírás). Tokenek: {termeknev}, {ceg}."></div>
                    <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-seo-d">Leírás (meta description)${sz('d', 160)}</label><textarea class="sa-textarea" id="st-seo-d" rows="2" maxlength="400">${e(o.seo.description)}</textarea></div>
                    <div class="sa-mezo"><label class="sa-mezo__cim" for="st-seo-k">Fókusz-kulcsszó</label><input class="sa-input" id="st-seo-k" maxlength="120" value="${e(o.seo.kulcsszo)}"></div>
                    <div class="sa-mezo"><label class="sa-mezo__cim" for="st-seo-c">Kanonikus cím (üresen: az oldal saját címe)</label><input class="sa-input sa-input--mono" id="st-seo-c" value="${e(o.seo.kanonikus || '')}" placeholder="https://…"></div>
                    <div class="sa-mezo sa-mezo--szeles"><label class="sa-mezo__cim" for="st-seo-og">Megosztási kép (OG)</label><div class="sa-kep"><span class="sa-kep__elo" data-ogelo style="background-image:${o.seo.og_kep ? `url('${e(o.seo.og_kep)}')` : 'none'}"></span><input class="sa-input" id="st-seo-og" value="${e(o.seo.og_kep || '')}" readonly placeholder="Nincs (a webhely alapképe él)"><button type="button" class="sa-gomb" data-og>${I('kep')}Médiatár</button><button type="button" class="sa-ikon-gomb" data-og-torol aria-label="Megosztási kép törlése">${I('x')}</button></div></div>
                    <div class="sa-mezo sa-mezo--szeles"><div class="sa-kapcsolosor"><label class="sa-kapcsolosor__szoveg" for="st-seo-n">Ne jelenjen meg a keresőkben (noindex)</label>${kapcsolo('st-seo-n', !!o.seo.noindex, 'Ne jelenjen meg a keresőkben')}</div></div>
                </div>
                <div class="sa-kartya st-serp"><div class="sa-kicsi sa-halvany">Találati előnézet</div><div class="sa-kicsi st-serp__url" data-serp-u></div><div data-serp-t class="st-serp__cim sa-egysor"></div><div data-serp-d class="sa-kicsi sa-halvany"></div></div>`],
                ...(o.tartalom ? [['torzs', 'Oldal szövege', '<div data-torzs></div>']] : []),
            ]),
            lab: `<span class="sa-ter"></span><button type="button" class="sa-gomb" data-bezar>Mégse</button><button type="button" class="sa-gomb sa-gomb--fo" data-ment>${I('mentes')}Mentés</button>`,
        });
        A.fulek(el);
        let torzs = null;
        if (o.tartalom) { torzs = A.mezoVezerlo({ k: 'tartalom', t: 'html', c: 'Az oldal törzsszövege (a szekciók között jelenik meg)' }, o.tartalom); $('[data-torzs]', el).appendChild(torzs.el); }
        const t = $('#st-seo-t', el), d = $('#st-seo-d', el), c = $('#st-ob-c', el);
        const frissit = () => {
            [['t', t], ['d', d]].forEach(([n, x]) => { const s = $(`[data-sz="${n}"]`, el); const max = Number(s.dataset.max); s.textContent = `${x.value.length}/${max}`; s.classList.toggle('is-sok', x.value.length > max); });
            $('[data-serp-u]', el).textContent = (B.webhely || '').replace(/\/$/, '') + '/' + ($('#st-ob-s', el).value || o.slug) + '/';
            $('[data-serp-t]', el).textContent = (t.value || c.value).replace('{termeknev}', B.termeknev);
            $('[data-serp-t]', el).title = $('[data-serp-t]', el).textContent;
            $('[data-serp-d]', el).textContent = (d.value || 'Nincs leírás – a kereső maga választ szöveget.').replace('{termeknev}', B.termeknev);
        };
        el.addEventListener('input', frissit);
        frissit();
        $('[data-og]', el).addEventListener('click', async () => { const x = await A.mediaValaszt({ cim: 'Megosztási kép', tipus: 'image' }); if (x) { $('#st-seo-og', el).value = x.url; $('[data-ogelo]', el).style.backgroundImage = `url('${x.url.replace(/['\\]/g, '')}')`; } });
        $('[data-og-torol]', el).addEventListener('click', () => { $('#st-seo-og', el).value = ''; $('[data-ogelo]', el).style.backgroundImage = 'none'; });
        const muv = (sel, ut, uz, utana) => { const g = $(sel, el); if (g) g.addEventListener('click', () => tolt(g, async () => { try { const x = await api(`admin/oldal/${E.id}/${ut}`, { method: 'POST', body: {} }); jelez(uz); bezar(); A.oldalLista(true); utana(x); } catch (y) { popupHiba(el, y.message); } })); };
        muv('[data-kezdo]', 'kezdolap', 'Ez lett a kezdőlap.', () => {});
        muv('[data-masol]', 'masol', 'Másolat elkészült (vázlat).', (x) => { S.mentetlen = null; location.hash = '#/oldalak/' + x.id; });
        muv('[data-kuka]', 'kuka', 'Az oldal a kukába került.', () => { S.mentetlen = null; location.hash = '#/oldalak'; });
        $('[data-ment]', el).addEventListener('click', (ev) => tolt(ev.currentTarget, async () => {
            try {
                const body = {
                    cim: c.value.trim(), slug: $('#st-ob-s', el).value.trim(), szulo: Number($('#st-ob-p', el).value), sablon: $('#st-ob-t', el).value, statusz: $('#st-ob-st', el).value, sorrend: Number($('#st-ob-o', el).value) || 0,
                    seo: { title: t.value.trim(), description: d.value.trim(), kulcsszo: $('#st-seo-k', el).value.trim(), kanonikus: $('#st-seo-c', el).value.trim(), og_kep: $('#st-seo-og', el).value.trim(), noindex: $('#st-seo-n', el).checked },
                };
                if (torzs) body.tartalom = torzs.ertek();
                const r = await api('admin/oldal/' + E.id, { method: 'POST', body });
                jelez('Oldal beállításai mentve.');
                A.oldalLista(true);
                bezar();
                kesz(r);
            } catch (x) { popupHiba(el, x.message); }
        }));
    }

    A.parancs('Új oldal', 'oldal', () => { location.hash = '#/oldalak'; setTimeout(() => { const g = $('[data-uj]'); if (g) g.click(); }, 400); });
})();
