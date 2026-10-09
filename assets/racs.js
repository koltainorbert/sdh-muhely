/*
 * SDH Műhely – munkalap-rács és részletpanel (kezdőképernyő).
 *
 * A MunkaLap 3 főablakának webes megfelelője. A szerver szűr, rendez és
 * lapoz (includes/class-sdh-muhely-racs.php) – ez a fájl csak kirajzol és
 * az állapotot tartja: melyik nézet, mely oszlopok, milyen szűrők, hányadik
 * oldal, melyik sor van kijelölve.
 *
 * Az állapot a böngészőben megmarad (localStorage), így a rács ugyanúgy
 * nyílik, ahogy legutóbb hagyták. A mentett nézetek a szerveren, a
 * felhasználónál tárolódnak.
 *
 * Kapcsolat az app.js-sel:
 *   - a sorok állapotválasztója ugyanaz a select, mint a Munkalapok
 *     listában (data-sdh-lista-allapot), a váltást az app.js intézi;
 *   - a szerkesztő popupot a data-sdh-urlap attribútum nyitja;
 *   - mentés után az app.js `sdh:mentve`, állapotváltás után `sdh:allapot`
 *     eseményt küld – ebből frissül a rács oldalváltás nélkül.
 */

(function () {
    'use strict';

    var gyoker = document.querySelector('[data-sdh-racs]');

    if (!gyoker) {
        return;
    }

    var B = window.SDH_MUHELY || {};
    var K;

    try {
        K = JSON.parse(gyoker.querySelector('[data-sdh-racs-beallitas]').textContent);
    } catch (e) {
        return;
    }

    /* ---------------------------------------------------------------- */
    /* Szótárak                                                         */
    /* ---------------------------------------------------------------- */

    var OSZLOP = {};

    K.oszlopok.forEach(function (o) {
        OSZLOP[o.k] = o;
        o.ertekTar = {};

        (o.ertekek || []).forEach(function (e) {
            o.ertekTar[e.e] = e;
        });
    });

    var MUVELET = {
        tartalmaz: 'tartalmazza',
        nem_tartalmaz: 'nem tartalmazza',
        egyenlo: 'egyenlő',
        nem_egyenlo: 'nem egyenlő',
        kezdodik: 'ezzel kezdődik',
        vegzodik: 'erre végződik',
        nagyobb: 'nagyobb, mint',
        legalabb: 'legalább',
        kisebb: 'kisebb, mint',
        legfeljebb: 'legfeljebb',
        kozott: 'kettő között',
        elott: 'ez előtt',
        utan: 'ez után',
        ma: 'ma',
        tegnap: 'tegnap',
        het: 'ezen a héten',
        honap: 'ebben a hónapban',
        utolso7: 'az utolsó 7 napban',
        utolso30: 'az utolsó 30 napban',
        mult: 'ma előtt (lejárt)',
        ures: 'üres',
        nem_ures: 'nem üres'
    };

    /* Műveletek, amelyekhez nem kell értéket írni. */
    var ERTEK_NELKUL = {
        ures: 1, nem_ures: 1, ma: 1, tegnap: 1, het: 1, honap: 1, utolso7: 1, utolso30: 1, mult: 1
    };

    /* A szűrősorba gépelt szöveg alapművelete típusonként. */
    var ALAP_MUVELET = { szoveg: 'tartalmaz', szam: 'kezdodik', penz: 'egyenlo', datum: 'tartalmaz' };

    /* Gépelhető rövidítések a számoknál és a dátumoknál: >5000, <=100, 1000..5000 */
    var JEL = { nagyobb: '>', legalabb: '>=', kisebb: '<', legfeljebb: '<=', nem_egyenlo: '<>' };
    var DATUM_JEL = { utan: '>', elott: '<' };

    var IKON = {
        nezet: '<svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="4" width="14" height="12" rx="1.8"/><path d="M3 8h14M8 8v8"/></svg>',
        lefele: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6 8l4 4 4-4"/></svg>',
        elso: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 6l-4 4 4 4M15 6l-4 4 4 4"/></svg>',
        elozo: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M12 6l-4 4 4 4"/></svg>',
        kovetkezo: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M8 6l4 4-4 4"/></svg>',
        utolso: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 6l4 4-4 4M5 6l4 4-4 4"/></svg>',
        frissit: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M15.5 8.5A6 6 0 0 0 4.6 7.2M4.5 11.5a6 6 0 0 0 10.9 1.3"/><path d="M4.4 3.8v3.6H8M15.6 16.2v-3.6H12"/></svg>',
        szumma: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M14.5 5.5V4h-9l5 6-5 6h9v-1.5"/></svg>',
        torol: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 5.5l9 9M14.5 5.5l-9 9"/></svg>',
        kereso: '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.8" cy="8.8" r="5"/><path d="M12.6 12.6L17 17"/></svg>',
        oszlopok: '<svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="4" width="14" height="12" rx="1.8"/><path d="M7.7 4v12M12.3 4v12"/></svg>',
        zaszlo: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 17V3.5M5 4.2h9.5l-2.2 3.3 2.2 3.3H5"/></svg>'
    };

    var FUL_IKON = {
        munkalap: '<svg viewBox="0 0 20 20" aria-hidden="true"><rect x="4" y="3" width="12" height="14" rx="1.8"/><path d="M7 7.5h6M7 10.5h6M7 13.5h3.5"/></svg>',
        eszkoz: '<svg viewBox="0 0 20 20" aria-hidden="true"><rect x="6" y="2.5" width="8" height="15" rx="1.8"/><path d="M8.6 15.2h2.8"/></svg>',
        ugyfel: '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="7" r="3"/><path d="M4.5 16.5c0-3 2.4-5 5.5-5s5.5 2 5.5 5"/></svg>',
        hibak: '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 6.2v4.6M10 13.6v.2"/></svg>',
        szolgaltatasok: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M12.2 3.6a3.6 3.6 0 0 0-4.6 4.6L3.5 12.3v3.2h3.2l.9-.9v-1.4h1.4l1.1-1.1a3.6 3.6 0 0 0 4.9-4.3l-2.3 2.3-1.8-.5-.5-1.8z"/></svg>',
        termekek: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3.5 6.5L10 3l6.5 3.5v7L10 17l-6.5-3.5z"/><path d="M3.5 6.5L10 10l6.5-3.5M10 10v7"/></svg>',
        szamlak: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 3h10v14l-2.5-1.5L10 17l-2.5-1.5L5 17z"/><path d="M7.5 7h5M7.5 10h5"/></svg>',
        penztar: '<svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="5" width="14" height="10" rx="1.8"/><circle cx="10" cy="10" r="2.2"/></svg>'
    };

    /* ---------------------------------------------------------------- */
    /* Segédek                                                          */
    /* ---------------------------------------------------------------- */

    function esc(szoveg) {
        return String(szoveg === null || szoveg === undefined ? '' : szoveg)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function masol(ertek) {
        return JSON.parse(JSON.stringify(ertek === undefined ? null : ertek));
    }

    /**
     * Sima objektum másolata. A PHP az üres tömböt `[]`-ként küldi, és a
     * tömbre írt névvel ellátott kulcsok a JSON-ból kimaradnának – ezért a
     * szűrők mindig ezen mennek át.
     */
    function targy(ertek) {
        return ertek && typeof ertek === 'object' && !Array.isArray(ertek) ? masol(ertek) : {};
    }

    function penz(ertek) {
        var n = Math.round(Number(ertek) || 0);

        return (n < 0 ? '-' : '') + String(Math.abs(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function szam(ertek) {
        return String(ertek).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function elem(tag, osztaly, html) {
        var e = document.createElement(tag);

        if (osztaly) {
            e.className = osztaly;
        }

        if (html !== undefined) {
            e.innerHTML = html;
        }

        return e;
    }

    function kesleltet(fuggveny, ido) {
        var t = 0;

        return function () {
            var arg = arguments;

            window.clearTimeout(t);
            t = window.setTimeout(function () {
                fuggveny.apply(null, arg);
            }, ido);
        };
    }

    /** Kérés a szerverhez; a válasz `data` része vagy hiba. */
    function kuld(akcio, mezok, jel) {
        var adat = new FormData();

        adat.set('action', akcio);
        adat.set('_wpnonce', B.nonce || '');
        adat.set('kontextus', B.kontextus || 'admin');

        Object.keys(mezok || {}).forEach(function (nev) {
            adat.set(nev, mezok[nev]);
        });

        return fetch(new URL(B.ajax, window.location.origin).toString(), {
            method: 'POST',
            body: adat,
            credentials: 'same-origin',
            signal: jel
        })
            .then(function (valasz) {
                return valasz.text().then(function (szoveg) {
                    var json;

                    try {
                        json = JSON.parse(szoveg);
                    } catch (e) {
                        throw new Error(
                            valasz.status === 403 || szoveg === '-1' || szoveg === '0'
                                ? 'Lejárt a munkamenet – frissítsd az oldalt.'
                                : 'A szerver válasza nem értelmezhető (' + valasz.status + ').'
                        );
                    }

                    if (!json || !json.success) {
                        throw new Error((json && json.data && json.data.uzenet) || 'A kérés nem sikerült.');
                    }

                    return json.data;
                });
            });
    }

    /* ---------------------------------------------------------------- */
    /* Állapot                                                          */
    /* ---------------------------------------------------------------- */

    var TAR_KULCS = 'sdh-racs-v1:' + (K.felhasznalo || 0);

    function alapElrendezes() {
        return K.oszlopok.map(function (o) {
            return { k: o.k, sz: o.sz, l: !!o.alap };
        });
    }

    /**
     * Mentett elrendezés hozzáigazítása a mostani oszloplistához: ami
     * időközben megszűnt, kimarad; ami új, a helyére kerül, rejtve vagy
     * láthatóan az alapértelmezése szerint.
     */
    function elrendezesRendez(mentett) {
        var volt = {};
        var ki = [];

        (Array.isArray(mentett) ? mentett : []).forEach(function (e) {
            if (e && OSZLOP[e.k] && !volt[e.k]) {
                volt[e.k] = true;
                ki.push({
                    k: e.k,
                    sz: Math.min(800, Math.max(40, parseInt(e.sz, 10) || OSZLOP[e.k].sz)),
                    l: !!e.l
                });
            }
        });

        if (!ki.length) {
            return alapElrendezes();
        }

        K.oszlopok.forEach(function (o, i) {
            if (!volt[o.k]) {
                ki.splice(Math.min(i, ki.length), 0, { k: o.k, sz: o.sz, l: !!o.alap });
            }
        });

        return ki;
    }

    var A = {
        nezet: 'mind',
        oszlopok: alapElrendezes(),
        rendezes: [{ k: 'szam', i: 'le' }],
        szurok: {},
        q: '',
        oldal: 1,
        meret: K.alapMeret || 50,
        osszeg: true,
        reszletMagas: 264,
        reszletRejtve: false,
        ful: 'munkalap'
    };

    (function betolt() {
        var mentett = null;

        try {
            mentett = JSON.parse(window.localStorage.getItem(TAR_KULCS) || 'null');
        } catch (e) {
            mentett = null;
        }

        if (!mentett || typeof mentett !== 'object') {
            return;
        }

        A.nezet = typeof mentett.nezet === 'string' ? mentett.nezet : A.nezet;
        A.oszlopok = elrendezesRendez(mentett.oszlopok);
        A.rendezes = Array.isArray(mentett.rendezes) ? mentett.rendezes.filter(function (r) {
            return r && OSZLOP[r.k];
        }) : A.rendezes;
        A.szurok = targy(mentett.szurok);
        A.meret = K.meretek.indexOf(mentett.meret) >= 0 ? mentett.meret : A.meret;
        A.osszeg = mentett.osszeg !== false;
        A.reszletMagas = Math.max(120, parseInt(mentett.reszletMagas, 10) || A.reszletMagas);
        A.reszletRejtve = !!mentett.reszletRejtve;
        A.ful = typeof mentett.ful === 'string' ? mentett.ful : A.ful;

        Object.keys(A.szurok).forEach(function (k) {
            if (!OSZLOP[k]) {
                delete A.szurok[k];
            }
        });
    }());

    function ment() {
        try {
            window.localStorage.setItem(TAR_KULCS, JSON.stringify({
                nezet: A.nezet,
                oszlopok: A.oszlopok,
                rendezes: A.rendezes,
                szurok: A.szurok,
                meret: A.meret,
                osszeg: A.osszeg,
                reszletMagas: A.reszletMagas,
                reszletRejtve: A.reszletRejtve,
                ful: A.ful
            }));
        } catch (e) {
            /* nincs tárhely: a beállítás erre a betöltésre szól */
        }
    }

    function lathatoOszlopok() {
        return A.oszlopok.filter(function (o) {
            return o.l;
        });
    }

    function osszesNezet() {
        return K.nezetek.concat(K.sajatNezetek || []);
    }

    function nezetKeres(id) {
        var talalat = null;

        osszesNezet().forEach(function (n) {
            if (n.id === id) {
                talalat = n;
            }
        });

        return talalat;
    }

    function sajatNezet(id) {
        return (K.sajatNezetek || []).some(function (n) {
            return n.id === id;
        });
    }

    /** Eltér-e a pillanatnyi szűrés és rendezés a kiválasztott nézetétől. */
    function nezetModosult() {
        var n = nezetKeres(A.nezet);

        if (!n) {
            return true;
        }

        return JSON.stringify(szurokRendezve(targy(n.szurok))) !== JSON.stringify(szurokRendezve(A.szurok))
            || JSON.stringify(n.rendezes || []) !== JSON.stringify(A.rendezes)
            || (n.q || '') !== A.q;
    }

    /** A szűrők kulcs szerint rendezve és kiegészítő mezők nélkül – összehasonlításhoz. */
    function szurokRendezve(szurok) {
        var ki = {};

        Object.keys(szurok).sort().forEach(function (k) {
            var s = szurok[k];

            ki[k] = { op: s.op, e: s.e || '', e2: s.e2 || '', l: (s.l || []).slice().sort() };
        });

        return ki;
    }

    function szuroDb() {
        return Object.keys(A.szurok).length + (A.q !== '' ? 1 : 0);
    }

    /* ---------------------------------------------------------------- */
    /* Váz                                                              */
    /* ---------------------------------------------------------------- */

    var eszkoztar = elem('div', 'sdh-racs__eszkoztar');
    eszkoztar.setAttribute('data-sdh-jelzes-hely', '');
    eszkoztar.innerHTML =
        '<button type="button" class="sdh-racs__gomb sdh-racs__gomb--nezet" data-m="nezet" title="Nézetek">' +
            IKON.nezet + '<span data-nezetnev></span>' + IKON.lefele +
        '</button>' +
        '<span class="sdh-racs__csoport">' +
            '<button type="button" class="sdh-racs__gomb sdh-racs__gomb--ikon" data-m="elso" title="Első oldal" aria-label="Első oldal">' + IKON.elso + '</button>' +
            '<button type="button" class="sdh-racs__gomb sdh-racs__gomb--ikon" data-m="elozo" title="Előző oldal" aria-label="Előző oldal">' + IKON.elozo + '</button>' +
            '<span class="sdh-racs__oldal" data-oldal></span>' +
            '<button type="button" class="sdh-racs__gomb sdh-racs__gomb--ikon" data-m="kovetkezo" title="Következő oldal" aria-label="Következő oldal">' + IKON.kovetkezo + '</button>' +
            '<button type="button" class="sdh-racs__gomb sdh-racs__gomb--ikon" data-m="utolso" title="Utolsó oldal" aria-label="Utolsó oldal">' + IKON.utolso + '</button>' +
        '</span>' +
        '<span class="sdh-racs__csoport">' +
            '<button type="button" class="sdh-racs__gomb sdh-racs__gomb--ikon" data-m="frissit" title="Frissítés" aria-label="Frissítés">' + IKON.frissit + '</button>' +
            '<button type="button" class="sdh-racs__gomb sdh-racs__gomb--ikon" data-m="osszeg" title="Összesítő sor" aria-label="Összesítő sor">' + IKON.szumma + '</button>' +
            '<button type="button" class="sdh-racs__gomb" data-m="torol" title="Minden szűrő törlése">' + IKON.torol + '<span>Szűrők törlése</span><b class="sdh-racs__jelveny" data-szurodb hidden></b></button>' +
        '</span>' +
        '<span class="sdh-racs__talalat" data-talalat></span>' +
        '<span class="sdh-racs__uzenet" data-uzenet role="status" hidden></span>' +
        '<span class="sdh-racs__toltelek"></span>' +
        '<label class="sdh-racs__kereso">' + IKON.kereso +
            '<input type="search" autocomplete="off" placeholder="Keresés a látható oszlopokban…" aria-label="Keresés a látható oszlopokban" data-kereso>' +
        '</label>' +
        '<select class="sdh-racs__meret" data-meret aria-label="Sorok száma oldalanként"></select>' +
        '<button type="button" class="sdh-racs__gomb" data-m="oszlopok" title="Oszlopok megjelenítése és elrejtése">' + IKON.oszlopok + '<span>Oszlopok</span></button>';

    var keret = elem('div', 'sdh-racs__keret');
    var gorgeto = elem('div', 'sdh-racs__gorgeto');
    gorgeto.tabIndex = 0;
    gorgeto.setAttribute('role', 'grid');
    gorgeto.setAttribute('aria-label', 'Munkalapok');

    var tabla = elem('table', 'sdh-racs__tabla');
    var colgroup = elem('colgroup');
    var thead = elem('thead');
    var tbody = elem('tbody');
    var tfoot = elem('tfoot');

    tabla.appendChild(colgroup);
    tabla.appendChild(thead);
    tabla.appendChild(tbody);
    tabla.appendChild(tfoot);
    gorgeto.appendChild(tabla);
    keret.appendChild(gorgeto);

    var elvalaszto = elem('div', 'sdh-racs__elvalaszto');
    elvalaszto.innerHTML =
        '<span class="sdh-racs__fogantyu"></span>' +
        '<button type="button" class="sdh-racs__rejto" data-rejto></button>';

    var reszlet = elem('div', 'sdh-reszlet');
    reszlet.innerHTML =
        '<div class="sdh-reszlet__fulek" role="tablist" data-fulek></div>' +
        '<div class="sdh-reszlet__torzs" data-torzs>' +
            '<p class="sdh-reszlet__ures">Válassz egy munkalapot a listából – a részletei itt jelennek meg.</p>' +
        '</div>' +
        '<div class="sdh-reszlet__lablec" data-lablec></div>';

    /* A szerkesztő popup rejtett indítója: az app.js a kattintását figyeli. */
    var indito = elem('a');
    indito.href = '#';
    indito.hidden = true;
    indito.setAttribute('data-sdh-urlap', K.urlapModul);
    indito.setAttribute('data-sdh-id', '0');

    gyoker.appendChild(eszkoztar);
    gyoker.appendChild(keret);
    gyoker.appendChild(elvalaszto);
    gyoker.appendChild(reszlet);
    gyoker.appendChild(indito);

    var keresoMezo = eszkoztar.querySelector('[data-kereso]');
    var meretValaszto = eszkoztar.querySelector('[data-meret]');
    var uzenetHely = eszkoztar.querySelector('[data-uzenet]');
    var reszletFulek = reszlet.querySelector('[data-fulek]');
    var reszletTorzs = reszlet.querySelector('[data-torzs]');
    var reszletLablec = reszlet.querySelector('[data-lablec]');

    K.meretek.forEach(function (m) {
        var o = document.createElement('option');

        o.value = String(m);
        o.textContent = m + ' sor / oldal';
        meretValaszto.appendChild(o);
    });

    /* ---------------------------------------------------------------- */
    /* Méretezés: a munkatér a képernyő aljáig ér                       */
    /* ---------------------------------------------------------------- */

    function meretez() {
        var teteje = gyoker.getBoundingClientRect().top + window.scrollY;
        var magas = Math.max(460, window.innerHeight - teteje - 14);

        gyoker.style.height = magas + 'px';

        var felso = Math.max(120, magas - 260);

        reszlet.style.height = Math.min(A.reszletMagas, felso) + 'px';
        reszlet.hidden = A.reszletRejtve;
        elvalaszto.classList.toggle('is-rejtve', A.reszletRejtve);
        elvalaszto.querySelector('[data-rejto]').textContent =
            A.reszletRejtve ? 'Részletek megjelenítése' : 'Részletek elrejtése';
    }

    /* ---------------------------------------------------------------- */
    /* Fejléc és szűrősor                                               */
    /* ---------------------------------------------------------------- */

    /**
     * A szűrő rövid, a szűrősorban megjelenő alakja.
     * `gepelheto`: a mező szabadon átírható (az érték maga a szöveg);
     * különben csak felirat, és a kattintás a szűrőpanelt nyitja.
     */
    function szuroSzoveg(k) {
        var s = A.szurok[k];
        var o = OSZLOP[k];

        if (!s) {
            return { szoveg: '', gepelheto: true };
        }

        if (s.op === ALAP_MUVELET[o.tipus]) {
            return { szoveg: s.e || '', gepelheto: true };
        }

        if (o.tipus === 'szam' || o.tipus === 'penz') {
            if (JEL[s.op]) {
                return { szoveg: JEL[s.op] + (s.e || ''), gepelheto: true };
            }

            if (s.op === 'egyenlo') {
                return { szoveg: '=' + (s.e || ''), gepelheto: true };
            }

            if (s.op === 'kozott') {
                return { szoveg: (s.e || '') + '..' + (s.e2 || ''), gepelheto: true };
            }
        }

        if (o.tipus === 'datum') {
            if (DATUM_JEL[s.op]) {
                return { szoveg: DATUM_JEL[s.op] + (s.e || ''), gepelheto: true };
            }

            if (s.op === 'egyenlo') {
                return { szoveg: '=' + (s.e || ''), gepelheto: true };
            }

            if (s.op === 'kozott') {
                return { szoveg: (s.e || '') + '..' + (s.e2 || ''), gepelheto: true };
            }
        }

        return {
            szoveg: MUVELET[s.op] + (ERTEK_NELKUL[s.op] ? '' : ': ' + (s.e || '')),
            gepelheto: false
        };
    }

    /** A szűrősorba gépelt szöveg szűrővé alakítva (null: nincs szűrő). */
    function gepeltSzuro(k, szoveg) {
        var o = OSZLOP[k];
        var t = szoveg.trim();
        var m;

        if (t === '') {
            return null;
        }

        if (o.tipus === 'szam' || o.tipus === 'penz' || o.tipus === 'datum') {
            m = /^(.+?)\s*\.\.\s*(.+)$/.exec(t);

            if (m) {
                return { op: 'kozott', e: m[1].trim(), e2: m[2].trim() };
            }
        }

        if (o.tipus === 'szam' || o.tipus === 'penz') {
            m = /^(>=|<=|<>|!=|>|<|=)\s*(.*)$/.exec(t);

            if (m) {
                return {
                    op: { '>': 'nagyobb', '>=': 'legalabb', '<': 'kisebb', '<=': 'legfeljebb', '<>': 'nem_egyenlo', '!=': 'nem_egyenlo', '=': 'egyenlo' }[m[1]],
                    e: m[2].trim(),
                    e2: ''
                };
            }
        }

        if (o.tipus === 'datum') {
            m = /^(>|<|=)\s*(.*)$/.exec(t);

            if (m) {
                return { op: { '>': 'utan', '<': 'elott', '=': 'egyenlo' }[m[1]], e: m[2].trim(), e2: '' };
            }
        }

        return { op: ALAP_MUVELET[o.tipus], e: t, e2: '' };
    }

    function halmazSzoveg(k) {
        var s = A.szurok[k];
        var o = OSZLOP[k];

        if (!s || !s.l || !s.l.length) {
            return '';
        }

        var elso = o.ertekTar[s.l[0]] ? o.ertekTar[s.l[0]].nev : s.l[0];
        var szoveg = s.l.length === 1 ? elso : s.l.length + ' kiválasztva';

        return (s.op === 'nem_egyike' ? 'nem: ' : '') + szoveg;
    }

    function rajzolFej() {
        var lathato = lathatoOszlopok();
        var szelesseg = 0;
        var cols = '';
        var fej = '';
        var szuro = '';

        lathato.forEach(function (lo) {
            var o = OSZLOP[lo.k];
            var rendIndex = -1;

            A.rendezes.forEach(function (r, i) {
                if (r.k === lo.k) {
                    rendIndex = i;
                }
            });

            var rend = rendIndex >= 0 ? A.rendezes[rendIndex] : null;

            szelesseg += lo.sz;
            cols += '<col data-k="' + esc(lo.k) + '" style="width:' + lo.sz + 'px">';

            fej += '<th scope="col" draggable="true" data-k="' + esc(lo.k) + '" title="' + esc(o.cim) + '" class="' +
                (o.igazit === 'jobb' ? 'is-jobb ' : '') +
                (rend ? 'is-rendezett ' + (rend.i === 'fel' ? 'is-fel' : 'is-le') : '') + '"' +
                ' aria-sort="' + (rend ? (rend.i === 'fel' ? 'ascending' : 'descending') : 'none') + '">' +
                esc(o.cim) +
                (rend ? '<span class="sdh-racs__nyil"></span>' : '') +
                (rend && A.rendezes.length > 1 ? '<span class="sdh-racs__sorrendszam">' + (rendIndex + 1) + '</span>' : '') +
                '<span class="sdh-racs__fogo" data-fogo></span></th>';

            if (o.tipus === 'halmaz') {
                var hsz = halmazSzoveg(lo.k);

                szuro += '<th data-k="' + esc(lo.k) + '"><div class="sdh-racs__szuro' + (hsz ? ' is-aktiv' : '') + '">' +
                    '<button type="button" class="sdh-racs__szurohalmaz" data-szuropanel="' + esc(lo.k) + '"' +
                    ' aria-label="' + esc(o.cim) + ' szűrése"><span>' + esc(hsz) + '</span></button></div></th>';
            } else {
                var sz = szuroSzoveg(lo.k);

                szuro += '<th data-k="' + esc(lo.k) + '"><div class="sdh-racs__szuro' + (A.szurok[lo.k] ? ' is-aktiv' : '') + '">' +
                    '<input type="text" class="sdh-racs__szuromezo" autocomplete="off" spellcheck="false"' +
                    ' data-szuromezo="' + esc(lo.k) + '" value="' + esc(sz.szoveg) + '"' +
                    (sz.gepelheto ? '' : ' readonly') +
                    ' aria-label="' + esc(o.cim) + ' szűrése">' +
                    '<button type="button" class="sdh-racs__szurogomb" data-szuropanel="' + esc(lo.k) + '"' +
                    ' title="Szűrési feltétel" aria-label="' + esc(o.cim) + ': szűrési feltétel"></button></div></th>';
            }
        });

        colgroup.innerHTML = cols;
        thead.innerHTML = '<tr class="sdh-racs__fejsor">' + fej + '</tr><tr class="sdh-racs__szurosor">' + szuro + '</tr>';
        tabla.style.width = szelesseg + 'px';
    }

    /** Csak a szűrősor jelzéseit frissíti – gépelés közben a mező nem épül újra. */
    function szuroJelzesek() {
        Array.prototype.forEach.call(thead.querySelectorAll('.sdh-racs__szurosor th'), function (th) {
            var k = th.getAttribute('data-k');
            var doboz = th.querySelector('.sdh-racs__szuro');

            if (doboz) {
                doboz.classList.toggle('is-aktiv', !!A.szurok[k]);
            }
        });
    }

    /* ---------------------------------------------------------------- */
    /* Sorok                                                            */
    /* ---------------------------------------------------------------- */

    var adat = null;
    var kijeloltId = 0;

    function allapotCella(sor) {
        var ismert = false;
        var szin = 'szurke';
        var opciok = '';

        K.allapotok.forEach(function (a) {
            if (a.k === sor.allapot) {
                ismert = true;
                szin = a.szin;
            }

            opciok += '<option value="' + esc(a.k) + '" data-szin="' + esc(a.szin) + '"' +
                (a.k === sor.allapot ? ' selected' : '') + '>' + esc(a.nev) + '</option>';
        });

        if (!ismert) {
            opciok = '<option value="' + esc(sor.allapot) + '" selected>' + esc(sor.allapot) + '</option>' + opciok;
        }

        return '<span class="sdh-allapot sdh-allapot--valaszthato sdh-allapot--' + esc(szin) + '" data-sdh-allapot-hely>' +
            '<select data-sdh-lista-allapot data-id="' + sor.id + '" data-elozo="' + esc(sor.allapot) + '"' +
            ' aria-label="Állapot módosítása">' + opciok + '</select></span>';
    }

    function cella(o, sor) {
        var ertek = sor[o.k];

        switch (o.k) {
            case 'szam':
                return '<td class="is-jobb is-szam"><span data-sdh-munkalap-szam>' + esc(ertek || '—') + '</span></td>';

            case 'jelzes':
                return '<td class="is-kozep"><button type="button" class="sdh-racs__zaszlo' + (ertek ? ' is-be' : '') + '"' +
                    ' data-jelzes="' + sor.id + '" title="' + (ertek ? 'Jelzés levétele' : 'Megjelölés') + '"' +
                    ' aria-label="' + (ertek ? 'Jelzés levétele' : 'Megjelölés') + '" aria-pressed="' + (ertek ? 'true' : 'false') + '">' +
                    IKON.zaszlo + '</button></td>';

            case 'allapot':
                return '<td class="is-allapot">' + allapotCella(sor) + '</td>';

            case 'fizetve':
                return '<td class="is-kozep">' + (ertek ? '<span class="sdh-racs__pipa" title="Fizetve"></span>' : '') + '</td>';

            case 'hatarido':
                return '<td' + (sor._lejart ? ' class="is-lejart" title="Lejárt határidő"' : '') + '>' + esc(ertek) + '</td>';
        }

        if (o.tipus === 'penz') {
            return '<td class="is-jobb' + (Number(ertek) ? '' : ' is-nulla') + '">' + penz(ertek) + '</td>';
        }

        return '<td' + (ertek ? ' title="' + esc(ertek) + '"' : '') + '>' + esc(ertek) + '</td>';
    }

    function rajzolSorok() {
        var lathato = lathatoOszlopok();
        var html = '';

        if (!adat) {
            tbody.innerHTML = '';
            tfoot.innerHTML = '';

            return;
        }

        if (!adat.sorok.length) {
            html = '<tr><td class="sdh-racs__ures" colspan="' + Math.max(1, lathato.length) + '">' +
                (szuroDb()
                    ? 'A szűrésnek egyetlen munkalap sem felel meg.<button type="button" data-m="torol">Szűrők törlése</button>'
                    : 'Még nincs munkalap. A „+ Új munkalap” gombbal viheted fel az elsőt.') +
                '</td></tr>';
        }

        adat.sorok.forEach(function (sor) {
            html += '<tr data-id="' + sor.id + '" class="' +
                (sor.id === kijeloltId ? 'is-kijelolt ' : '') + (sor._zart ? 'sdh-sor--zart' : '') + '"' +
                ' aria-selected="' + (sor.id === kijeloltId ? 'true' : 'false') + '">';

            lathato.forEach(function (lo) {
                html += cella(OSZLOP[lo.k], sor);
            });

            html += '</tr>';
        });

        tbody.innerHTML = html;

        if (A.osszeg && lathato.length) {
            var lab = '<tr>';

            lathato.forEach(function (lo, i) {
                var o = OSZLOP[lo.k];

                if (o.osszeg) {
                    lab += '<td class="is-jobb">' + penz(adat.osszegek[lo.k]) + '</td>';
                } else if (i === 0) {
                    lab += '<td class="is-jobb" title="A szűrésnek megfelelő munkalapok száma">Σ ' + szam(adat.osszesen) + '</td>';
                } else {
                    lab += '<td></td>';
                }
            });

            tfoot.innerHTML = lab + '</tr>';
        } else {
            tfoot.innerHTML = '';
        }
    }

    function frissitEszkoztar() {
        var nezet = nezetKeres(A.nezet);
        var db = szuroDb();
        var jelveny = eszkoztar.querySelector('[data-szurodb]');

        eszkoztar.querySelector('[data-nezetnev]').textContent =
            (nezet ? nezet.nev : 'Egyéni szűrés') + (nezet && nezetModosult() ? ' •' : '');

        eszkoztar.querySelector('[data-oldal]').textContent = adat
            ? adat.oldal + ' / ' + szam(adat.oldalak)
            : '…';

        eszkoztar.querySelector('[data-talalat]').textContent = adat
            ? szam(adat.osszesen) + ' munkalap'
            : '';

        ['elso', 'elozo'].forEach(function (m) {
            eszkoztar.querySelector('[data-m="' + m + '"]').disabled = !adat || adat.oldal <= 1;
        });

        ['kovetkezo', 'utolso'].forEach(function (m) {
            eszkoztar.querySelector('[data-m="' + m + '"]').disabled = !adat || adat.oldal >= adat.oldalak;
        });

        // A törlés az alapnézetre áll vissza: akkor él, ha attól bármi eltér
        // (szűrő, kereső, rendezés vagy más nézet).
        eszkoztar.querySelector('[data-m="torol"]').disabled =
            db === 0 && A.nezet === 'mind' && !nezetModosult();
        eszkoztar.querySelector('[data-m="osszeg"]').classList.toggle('is-be', A.osszeg);
        eszkoztar.querySelector('[data-m="osszeg"]').setAttribute('aria-pressed', A.osszeg ? 'true' : 'false');

        jelveny.hidden = db === 0;
        jelveny.textContent = String(db);

        meretValaszto.value = String(A.meret);

        if (document.activeElement !== keresoMezo) {
            keresoMezo.value = A.q;
        }
    }

    var uzenetIdo = 0;

    function uzenet(szoveg, hiba) {
        uzenetHely.textContent = szoveg;
        uzenetHely.hidden = false;
        uzenetHely.classList.toggle('sdh-racs__uzenet--hiba', !!hiba);

        window.clearTimeout(uzenetIdo);
        uzenetIdo = window.setTimeout(function () {
            uzenetHely.hidden = true;
        }, hiba ? 9000 : 6000);
    }

    /* ---------------------------------------------------------------- */
    /* Adatok lekérése                                                  */
    /* ---------------------------------------------------------------- */

    var kerSorszam = 0;
    var kerMegszakito = null;

    /**
     * Egy oldalnyi sor a szerverről.
     *
     * opciok.kijelol – ezt a munkalapot kell kijelölni a betöltés után;
     * opciok.csendben – a kijelölt lap részleteit nem tölti újra, ha a
     *                   kijelölés nem változik.
     */
    function leker(opciok) {
        opciok = opciok || {};

        var sorszam = ++kerSorszam;

        if (kerMegszakito) {
            kerMegszakito.abort();
        }

        kerMegszakito = window.AbortController ? new AbortController() : null;
        keret.classList.add('is-toltes');

        return kuld('sdh_muhely_racs_adat', {
            allapot: JSON.stringify({
                oldal: A.oldal,
                meret: A.meret,
                rendezes: A.rendezes,
                szurok: A.szurok,
                q: A.q,
                lathato: lathatoOszlopok().map(function (o) {
                    return o.k;
                })
            })
        }, kerMegszakito ? kerMegszakito.signal : undefined)
            .then(function (valasz) {
                if (sorszam !== kerSorszam) {
                    return;
                }

                adat = valasz;
                A.oldal = valasz.oldal;
                keret.classList.remove('is-toltes');

                var cel = opciok.kijelol || kijeloltId;
                var megvan = adat.sorok.some(function (s) {
                    return s.id === cel;
                });
                var elozo = kijeloltId;

                kijeloltId = megvan ? cel : (adat.sorok.length ? adat.sorok[0].id : 0);

                rajzolSorok();
                frissitEszkoztar();

                if (kijeloltId !== elozo || !opciok.csendben) {
                    reszletTolt(kijeloltId);
                }

                if (opciok.kijelol && megvan) {
                    latoterbe();
                }
            })
            .catch(function (ok) {
                if (ok && ok.name === 'AbortError') {
                    return;
                }

                if (sorszam !== kerSorszam) {
                    return;
                }

                keret.classList.remove('is-toltes');
                tbody.innerHTML = '<tr><td class="sdh-racs__ures" colspan="' + Math.max(1, lathatoOszlopok().length) + '">' +
                    'A munkalapok nem töltődtek be. ' + esc(ok.message) +
                    '<button type="button" data-m="frissit">Újra</button></td></tr>';
                tfoot.innerHTML = '';
            });
    }

    var lekerKesve = kesleltet(function () {
        leker({ csendben: true });
    }, 280);

    /** Szűrő, rendezés vagy nézet változott: első oldal, mentés, lekérés. */
    function valtozott(azonnal) {
        A.oldal = 1;
        ment();
        frissitEszkoztar();

        if (azonnal) {
            leker({ csendben: true });
        } else {
            lekerKesve();
        }
    }

    /* ---------------------------------------------------------------- */
    /* Kijelölés                                                        */
    /* ---------------------------------------------------------------- */

    function sorElem(id) {
        return tbody.querySelector('tr[data-id="' + id + '"]');
    }

    function latoterbe() {
        var sor = sorElem(kijeloltId);

        if (!sor) {
            return;
        }

        var fejMagas = thead.getBoundingClientRect().height;
        var labMagas = tfoot.getBoundingClientRect().height;
        var s = sor.getBoundingClientRect();
        var g = gorgeto.getBoundingClientRect();

        if (s.top < g.top + fejMagas) {
            gorgeto.scrollTop -= (g.top + fejMagas) - s.top;
        } else if (s.bottom > g.bottom - labMagas) {
            gorgeto.scrollTop += s.bottom - (g.bottom - labMagas);
        }
    }

    var reszletKesve = kesleltet(function (id) {
        reszletTolt(id);
    }, 130);

    function kijelol(id, azonnal) {
        if (!id || id === kijeloltId) {
            return;
        }

        var regi = sorElem(kijeloltId);
        var uj = sorElem(id);

        if (regi) {
            regi.classList.remove('is-kijelolt');
            regi.setAttribute('aria-selected', 'false');
        }

        if (uj) {
            uj.classList.add('is-kijelolt');
            uj.setAttribute('aria-selected', 'true');
        }

        kijeloltId = id;
        latoterbe();

        if (azonnal) {
            reszletTolt(id);
        } else {
            reszletKesve(id);
        }
    }

    function lep(irany) {
        if (!adat || !adat.sorok.length) {
            return;
        }

        var i = -1;

        adat.sorok.forEach(function (s, n) {
            if (s.id === kijeloltId) {
                i = n;
            }
        });

        var uj = Math.max(0, Math.min(adat.sorok.length - 1, i + irany));

        kijelol(adat.sorok[uj].id, false);
    }

    function szerkeszt(id) {
        if (!id) {
            return;
        }

        indito.setAttribute('data-sdh-id', String(id));
        indito.click();
    }

    /* ---------------------------------------------------------------- */
    /* Részletpanel                                                     */
    /* ---------------------------------------------------------------- */

    var reszletSorszam = 0;
    var reszletMegszakito = null;
    var alfulek = {};

    function fulValt(kulcs) {
        var van = false;

        Array.prototype.forEach.call(reszletFulek.children, function (gomb) {
            if (gomb.getAttribute('data-ful') === kulcs) {
                van = true;
            }
        });

        if (!van && reszletFulek.firstChild) {
            kulcs = reszletFulek.firstChild.getAttribute('data-ful');
        }

        Array.prototype.forEach.call(reszletFulek.children, function (gomb) {
            var aktiv = gomb.getAttribute('data-ful') === kulcs;

            gomb.classList.toggle('is-aktiv', aktiv);
            gomb.setAttribute('aria-selected', aktiv ? 'true' : 'false');
        });

        Array.prototype.forEach.call(reszletTorzs.querySelectorAll('.sdh-reszlet__panel'), function (panel) {
            panel.hidden = panel.getAttribute('data-ful') !== kulcs;
        });

        reszletTorzs.scrollTop = 0;
        reszletTorzs.scrollLeft = 0;
    }

    function alfulValt(panel, kulcs) {
        var van = !!panel.querySelector('[data-sdh-alpanel="' + kulcs + '"]');

        if (!van) {
            kulcs = 'adatok';
        }

        Array.prototype.forEach.call(panel.querySelectorAll('[data-sdh-alful]'), function (gomb) {
            gomb.classList.toggle('is-aktiv', gomb.getAttribute('data-sdh-alful') === kulcs);
        });

        Array.prototype.forEach.call(panel.querySelectorAll('[data-sdh-alpanel]'), function (resz) {
            resz.hidden = resz.getAttribute('data-sdh-alpanel') !== kulcs;
        });
    }

    function reszletUres(szoveg) {
        reszletFulek.innerHTML = '';
        reszletTorzs.innerHTML = '<p class="sdh-reszlet__ures">' + esc(szoveg) + '</p>';
        reszletLablec.textContent = '';
    }

    function reszletTolt(id) {
        var sorszam = ++reszletSorszam;

        if (reszletMegszakito) {
            reszletMegszakito.abort();
        }

        if (!id) {
            reszletUres(adat && !adat.sorok.length
                ? 'Nincs megjeleníthető munkalap.'
                : 'Válassz egy munkalapot a listából – a részletei itt jelennek meg.');

            return;
        }

        reszletMegszakito = window.AbortController ? new AbortController() : null;
        reszletTorzs.classList.add('is-toltes');

        kuld('sdh_muhely_racs_reszlet', { id: String(id) }, reszletMegszakito ? reszletMegszakito.signal : undefined)
            .then(function (valasz) {
                if (sorszam !== reszletSorszam) {
                    return;
                }

                var fulHtml = '';
                var torzsHtml = '';

                valasz.fulek.forEach(function (ful) {
                    fulHtml += '<button type="button" class="sdh-reszlet__ful" role="tab" data-ful="' + esc(ful.k) + '">' +
                        (FUL_IKON[ful.k] || '') + '<span>' + esc(ful.cim) + '</span></button>';

                    // A lapfülek tartalma a szerveren készült, már szűrt HTML.
                    torzsHtml += '<div class="sdh-reszlet__panel" role="tabpanel" data-ful="' + esc(ful.k) + '" hidden>' +
                        ful.html + '</div>';
                });

                reszletFulek.innerHTML = fulHtml;
                reszletTorzs.innerHTML = torzsHtml;
                reszletTorzs.classList.remove('is-toltes');
                reszletLablec.textContent = valasz.lablec || '';

                Array.prototype.forEach.call(reszletTorzs.querySelectorAll('.sdh-reszlet__panel'), function (panel) {
                    alfulValt(panel, alfulek[panel.getAttribute('data-ful')] || 'adatok');
                });

                fulValt(A.ful);
            })
            .catch(function (ok) {
                if ((ok && ok.name === 'AbortError') || sorszam !== reszletSorszam) {
                    return;
                }

                reszletTorzs.classList.remove('is-toltes');
                reszletUres('A részletek nem töltődtek be. ' + ok.message);
            });
    }

    /* ---------------------------------------------------------------- */
    /* Felugró panel (szűrő, nézetek, oszlopok)                         */
    /* ---------------------------------------------------------------- */

    var felugro = null;

    function felugroZar() {
        if (!felugro) {
            return;
        }

        var f = felugro;

        felugro = null;
        f.panel.remove();
        document.removeEventListener('mousedown', f.kinti, true);
        document.removeEventListener('keydown', f.billentyu, true);
        window.removeEventListener('resize', felugroZar);
        gorgeto.removeEventListener('scroll', felugroGorget);

        if (f.zaraskor) {
            f.zaraskor();
        }
    }

    function felugroHelyez() {
        if (!felugro) {
            return;
        }

        var p = felugro.panel;
        var r = felugro.horgony.getBoundingClientRect();
        var m = p.getBoundingClientRect();
        var bal = Math.max(8, Math.min(r.left, window.innerWidth - m.width - 8));
        var fent = r.bottom + 4;

        // Ha lefelé nem fér ki, fölfelé nyílik.
        if (fent + m.height > window.innerHeight - 8 && r.top - 4 - m.height > 8) {
            fent = r.top - 4 - m.height;
        }

        p.style.left = bal + 'px';
        p.style.top = Math.max(8, fent) + 'px';
    }

    /**
     * A rács görgetésekor a panel a horgonyával együtt mozog; ha a horgony
     * kigördül a rácsból, a panel bezárul. (Nem zárhat be minden görgetésre:
     * a félig takart szűrőgombot a böngésző kattintáskor maga görgeti be.)
     */
    function felugroGorget() {
        if (!felugro) {
            return;
        }

        if (!gorgeto.contains(felugro.horgony)) {
            return;
        }

        var h = felugro.horgony.getBoundingClientRect();
        var g = gorgeto.getBoundingClientRect();

        if (h.right < g.left + 8 || h.left > g.right - 8) {
            felugroZar();

            return;
        }

        felugroHelyez();
    }

    /**
     * @param {Element}  horgony  Amihez a panel igazodik.
     * @param {Function} epit     (panel) => void: a tartalom felépítése.
     * @param {Function} zaraskor Bezáráskor fut (elhagyható).
     */
    function felugroNyit(horgony, epit, zaraskor) {
        var ugyanaz = felugro && felugro.horgony === horgony;

        felugroZar();

        if (ugyanaz) {
            return null;
        }

        var panel = elem('div', 'sdh-racs__felugro');

        panel.setAttribute('role', 'dialog');
        document.body.appendChild(panel);

        felugro = {
            panel: panel,
            horgony: horgony,
            zaraskor: zaraskor,
            kinti: function (esemeny) {
                // Az app.js saját legördülő menüje (.sdh-menu) a panelhez tartozik.
                if (!panel.contains(esemeny.target) && !horgony.contains(esemeny.target)
                    && !(esemeny.target.closest && esemeny.target.closest('.sdh-menu'))) {
                    felugroZar();
                }
            },
            billentyu: function (esemeny) {
                if (esemeny.key === 'Escape') {
                    esemeny.preventDefault();
                    esemeny.stopPropagation();
                    felugroZar();

                    if (horgony.focus) {
                        horgony.focus();
                    }
                }
            }
        };

        epit(panel);
        felugroHelyez();

        document.addEventListener('mousedown', felugro.kinti, true);
        document.addEventListener('keydown', felugro.billentyu, true);
        window.addEventListener('resize', felugroZar);
        gorgeto.addEventListener('scroll', felugroGorget);

        return panel;
    }

    /* ---- Szűrőpanel: szöveg, szám, pénz, dátum ---- */

    function szuroPanel(k, horgony) {
        var o = OSZLOP[k];

        if (o.tipus === 'halmaz') {
            halmazPanel(k, horgony);

            return;
        }

        var mostani = A.szurok[k] ? masol(A.szurok[k]) : { op: ALAP_MUVELET[o.tipus], e: '', e2: '' };

        felugroNyit(horgony, function (panel) {
            function rajzol() {
                var lista = '';

                (K.muveletek[o.tipus] || []).forEach(function (op) {
                    lista += '<button type="button" class="sdh-racs__elem' + (op === mostani.op ? ' is-aktiv' : '') + '"' +
                        ' data-op="' + op + '"><span>' + esc(MUVELET[op]) + '</span></button>';
                });

                var kell = !ERTEK_NELKUL[mostani.op];
                var helyorzo = o.tipus === 'datum' ? 'éééé.hh.nn' : (o.tipus === 'szoveg' ? 'szöveg' : 'szám');

                panel.innerHTML =
                    '<h3>' + esc(o.cim) + ' – szűrési feltétel</h3>' +
                    '<div class="sdh-racs__lista' + ((K.muveletek[o.tipus] || []).length > 8 ? ' sdh-racs__lista--ket' : '') + '">' + lista + '</div>' +
                    (kell
                        ? '<div class="sdh-racs__sor">' +
                            '<input type="text" data-e autocomplete="off" spellcheck="false" placeholder="' + helyorzo + '" value="' + esc(mostani.e || '') + '">' +
                            (mostani.op === 'kozott'
                                ? '<span>–</span><input type="text" data-e2 autocomplete="off" spellcheck="false" placeholder="' + helyorzo + '" value="' + esc(mostani.e2 || '') + '">'
                                : '') +
                          '</div>'
                        : '') +
                    (o.tipus === 'datum' && kell && mostani.op !== 'tartalmaz'
                        ? '<p class="sdh-racs__sugo">Teljes dátumot írj, például 2026.10.07</p>'
                        : '') +
                    (o.tipus === 'datum' && mostani.op === 'tartalmaz'
                        ? '<p class="sdh-racs__sugo">Részlet is elég: a „2026.10” az egész hónapot adja.</p>'
                        : '') +
                    '<div class="sdh-racs__sor">' +
                        '<button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-alkalmaz>Alkalmaz</button>' +
                        '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-szurotorol>Szűrő törlése</button>' +
                    '</div>';

                var mezo = panel.querySelector('[data-e]');

                if (mezo) {
                    mezo.focus();
                    mezo.select();
                }

                felugroHelyez();
            }

            function olvas() {
                var e = panel.querySelector('[data-e]');
                var e2 = panel.querySelector('[data-e2]');

                mostani.e = e ? e.value.trim() : '';
                mostani.e2 = e2 ? e2.value.trim() : '';
            }

            function alkalmaz() {
                olvas();

                if (ERTEK_NELKUL[mostani.op] || mostani.e !== '') {
                    A.szurok[k] = { op: mostani.op, e: mostani.e, e2: mostani.e2 };
                } else {
                    delete A.szurok[k];
                }

                felugroZar();
                rajzolFej();
                valtozott(true);
            }

            panel.addEventListener('click', function (esemeny) {
                var op = esemeny.target.closest('[data-op]');

                if (op) {
                    olvas();
                    mostani.op = op.getAttribute('data-op');

                    // Az érték nélküli műveletek azonnal érvényesülnek.
                    if (ERTEK_NELKUL[mostani.op]) {
                        alkalmaz();
                    } else {
                        rajzol();
                    }

                    return;
                }

                if (esemeny.target.closest('[data-alkalmaz]')) {
                    alkalmaz();

                    return;
                }

                if (esemeny.target.closest('[data-szurotorol]')) {
                    delete A.szurok[k];
                    felugroZar();
                    rajzolFej();
                    valtozott(true);
                }
            });

            panel.addEventListener('keydown', function (esemeny) {
                if (esemeny.key === 'Enter' && esemeny.target.tagName === 'INPUT') {
                    esemeny.preventDefault();
                    alkalmaz();
                }
            });

            rajzol();
        });
    }

    /* ---- Szűrőpanel: halmaz (állapot, felelős, igen/nem…) ---- */

    function halmazPanel(k, horgony) {
        var o = OSZLOP[k];
        var mostani = A.szurok[k] ? masol(A.szurok[k]) : { op: 'egyike', l: [] };

        function alkalmaz() {
            if (mostani.l.length) {
                A.szurok[k] = { op: mostani.op, l: mostani.l.slice() };
            } else {
                delete A.szurok[k];
            }

            var gomb = thead.querySelector('[data-szuropanel="' + k + '"] span');

            if (gomb) {
                gomb.textContent = halmazSzoveg(k);
            }

            szuroJelzesek();
            valtozott(true);
        }

        felugroNyit(horgony, function (panel) {
            function rajzol() {
                var lista = '';

                (o.ertekek || []).forEach(function (e) {
                    var jelolt = mostani.l.indexOf(e.e) >= 0;

                    lista += '<button type="button" class="sdh-racs__elem' + (jelolt ? ' is-jelolt' : '') + '"' +
                        ' role="checkbox" aria-checked="' + (jelolt ? 'true' : 'false') + '" data-ertek="' + esc(e.e) + '">' +
                        '<i class="sdh-racs__negyzet"></i>' +
                        (e.szin ? '<i class="sdh-allapot-pont sdh-allapot--' + esc(e.szin) + '"></i>' : '') +
                        '<span>' + esc(e.nev) + '</span></button>';
                });

                var kizar = mostani.op === 'nem_egyike';

                // Sok értéknél több hasábba törik: a panelben nincs görgetősáv.
                var hasab = Math.min(6, Math.ceil((o.ertekek || []).length / 12));

                panel.style.maxWidth = hasab > 2 ? 'calc(100vw - 16px)' : '';
                panel.innerHTML =
                    '<h3>' + esc(o.cim) + ' – szűrés</h3>' +
                    '<div class="sdh-racs__lista"' +
                    (hasab > 1 ? ' style="grid-template-columns:repeat(' + hasab + ',minmax(0,1fr));column-gap:.3rem"' : '') +
                    '>' + lista + '</div>' +
                    '<div class="sdh-racs__vonal"></div>' +
                    '<button type="button" class="sdh-racs__elem' + (kizar ? ' is-jelolt' : '') + '" role="checkbox"' +
                    ' aria-checked="' + (kizar ? 'true' : 'false') + '" data-kizar>' +
                    '<i class="sdh-racs__negyzet"></i><span>A kijelöltek kizárása</span></button>' +
                    '<div class="sdh-racs__sor">' +
                        '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-mind>Mind</button>' +
                        '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-szurotorol>Szűrő törlése</button>' +
                    '</div>';

                felugroHelyez();
            }

            panel.addEventListener('click', function (esemeny) {
                var ertek = esemeny.target.closest('[data-ertek]');

                if (ertek) {
                    var e = ertek.getAttribute('data-ertek');
                    var i = mostani.l.indexOf(e);

                    if (i >= 0) {
                        mostani.l.splice(i, 1);
                    } else {
                        mostani.l.push(e);
                    }

                    rajzol();
                    alkalmaz();

                    return;
                }

                if (esemeny.target.closest('[data-kizar]')) {
                    mostani.op = mostani.op === 'nem_egyike' ? 'egyike' : 'nem_egyike';
                    rajzol();
                    alkalmaz();

                    return;
                }

                if (esemeny.target.closest('[data-mind]')) {
                    mostani.l = (o.ertekek || []).map(function (e) {
                        return e.e;
                    });
                    rajzol();
                    alkalmaz();

                    return;
                }

                if (esemeny.target.closest('[data-szurotorol]')) {
                    mostani = { op: 'egyike', l: [] };
                    alkalmaz();
                    felugroZar();
                }
            });

            rajzol();
        });
    }

    /* ---- Nézetek ---- */

    function nezetAlkalmaz(id) {
        var n = nezetKeres(id);

        if (!n) {
            return;
        }

        A.nezet = n.id;
        A.szurok = targy(n.szurok);
        A.rendezes = Array.isArray(n.rendezes) ? masol(n.rendezes) : [];
        A.q = n.q || '';

        if (n.meret && K.meretek.indexOf(n.meret) >= 0) {
            A.meret = n.meret;
        }

        // A saját nézet az oszlopokat is hozza; a beépített a mostaniakat hagyja.
        if (Array.isArray(n.oszlopok) && n.oszlopok.length) {
            A.oszlopok = elrendezesRendez(n.oszlopok);
        }

        keresoMezo.value = A.q;
        rajzolFej();
        valtozott(true);
    }

    function nezetPanel(horgony) {
        felugroNyit(horgony, function (panel) {
            function rajzol(hiba) {
                var html = '<h3>Nézetek</h3><div class="sdh-racs__lista">';

                K.nezetek.forEach(function (n) {
                    html += '<button type="button" class="sdh-racs__elem' + (n.id === A.nezet ? ' is-aktiv' : '') + '"' +
                        ' data-nezet="' + esc(n.id) + '"><span>' + esc(n.nev) + '</span></button>';
                });

                html += '</div>';

                if ((K.sajatNezetek || []).length) {
                    html += '<div class="sdh-racs__vonal"></div><h3>Saját nézetek</h3><div class="sdh-racs__lista">';

                    K.sajatNezetek.forEach(function (n) {
                        html += '<div class="sdh-racs__elem' + (n.id === A.nezet ? ' is-aktiv' : '') + '" role="button" tabindex="0"' +
                            ' data-nezet="' + esc(n.id) + '"><span>' + esc(n.nev) + '</span>' +
                            '<button type="button" class="sdh-racs__torol" data-nezettorol="' + esc(n.id) + '"' +
                            ' title="Nézet törlése" aria-label="' + esc(n.nev) + ' törlése">&times;</button></div>';
                    });

                    html += '</div>';
                }

                html += '<div class="sdh-racs__vonal"></div>' +
                    '<h3>A mostani szűrés, rendezés és oszlopok mentése</h3>';

                if (sajatNezet(A.nezet)) {
                    html += '<div class="sdh-racs__sor"><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-felulir>' +
                        '„' + esc(nezetKeres(A.nezet).nev) + '” frissítése</button></div>';
                }

                html += '<div class="sdh-racs__sor">' +
                    '<input type="text" maxlength="60" placeholder="Új nézet neve" data-nezetnev-mezo>' +
                    '<button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-nezetment>Mentés</button></div>' +
                    (hiba ? '<p class="sdh-racs__hiba">' + esc(hiba) + '</p>' : '');

                panel.innerHTML = html;
                felugroHelyez();
            }

            function mentes(id, nev) {
                kuld('sdh_muhely_racs_nezet', {
                    muvelet: 'ment',
                    id: id || '',
                    nev: nev,
                    allapot: JSON.stringify({
                        szurok: A.szurok,
                        rendezes: A.rendezes,
                        q: A.q,
                        meret: A.meret,
                        oszlopok: A.oszlopok
                    })
                })
                    .then(function (valasz) {
                        K.sajatNezetek = valasz.nezetek || [];
                        A.nezet = valasz.id;
                        ment();
                        frissitEszkoztar();
                        felugroZar();
                        uzenet('A nézet elmentve.');
                    })
                    .catch(function (ok) {
                        rajzol(ok.message);
                    });
            }

            panel.addEventListener('click', function (esemeny) {
                var torlendo = esemeny.target.closest('[data-nezettorol]');

                if (torlendo) {
                    esemeny.stopPropagation();

                    var tid = torlendo.getAttribute('data-nezettorol');

                    kuld('sdh_muhely_racs_nezet', { muvelet: 'torol', id: tid })
                        .then(function (valasz) {
                            K.sajatNezetek = valasz.nezetek || [];

                            if (A.nezet === tid) {
                                A.nezet = 'mind';
                                ment();
                            }

                            frissitEszkoztar();
                            rajzol();
                        })
                        .catch(function (ok) {
                            rajzol(ok.message);
                        });

                    return;
                }

                var valasztott = esemeny.target.closest('[data-nezet]');

                if (valasztott) {
                    felugroZar();
                    nezetAlkalmaz(valasztott.getAttribute('data-nezet'));

                    return;
                }

                if (esemeny.target.closest('[data-felulir]')) {
                    mentes(A.nezet, nezetKeres(A.nezet).nev);

                    return;
                }

                if (esemeny.target.closest('[data-nezetment]')) {
                    var nev = panel.querySelector('[data-nezetnev-mezo]').value.trim();

                    if (nev === '') {
                        rajzol('Adj nevet a nézetnek.');
                        panel.querySelector('[data-nezetnev-mezo]').focus();

                        return;
                    }

                    mentes('', nev);
                }
            });

            panel.addEventListener('keydown', function (esemeny) {
                if (esemeny.key !== 'Enter') {
                    return;
                }

                if (esemeny.target.matches('[data-nezetnev-mezo]')) {
                    esemeny.preventDefault();
                    panel.querySelector('[data-nezetment]').click();
                } else if (esemeny.target.matches('[data-nezet]')) {
                    esemeny.preventDefault();
                    esemeny.target.click();
                }
            });

            rajzol();
        });
    }

    /* ---- Oszlopok ---- */

    function oszlopPanel(horgony) {
        felugroNyit(horgony, function (panel) {
            function rajzol() {
                var lista = '';

                A.oszlopok.forEach(function (lo) {
                    lista += '<button type="button" class="sdh-racs__elem' + (lo.l ? ' is-jelolt' : '') + '"' +
                        ' role="checkbox" aria-checked="' + (lo.l ? 'true' : 'false') + '" data-oszlop="' + esc(lo.k) + '">' +
                        '<i class="sdh-racs__negyzet"></i><span>' + esc(OSZLOP[lo.k].cim) + '</span></button>';
                });

                panel.innerHTML =
                    '<h3>Látható oszlopok</h3>' +
                    '<div class="sdh-racs__lista sdh-racs__lista--harom">' + lista + '</div>' +
                    '<p class="sdh-racs__sugo">A sorrendet a fejléc húzásával, a szélességet az oszlop szélének húzásával állíthatod.</p>' +
                    '<div class="sdh-racs__sor">' +
                        '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-alap>Alaphelyzet</button>' +
                    '</div>';

                felugroHelyez();
            }

            panel.addEventListener('click', function (esemeny) {
                var gomb = esemeny.target.closest('[data-oszlop]');

                if (gomb) {
                    var k = gomb.getAttribute('data-oszlop');

                    A.oszlopok.forEach(function (lo) {
                        if (lo.k === k) {
                            // Legalább egy oszlop mindig látszik.
                            if (lo.l && lathatoOszlopok().length <= 1) {
                                return;
                            }

                            lo.l = !lo.l;
                        }
                    });

                    ment();
                    rajzol();
                    rajzolFej();
                    rajzolSorok();

                    // A kereső a látható oszlopokban keres: változásukkor újra kell kérdezni.
                    if (A.q !== '') {
                        leker({ csendben: true });
                    }

                    return;
                }

                if (esemeny.target.closest('[data-alap]')) {
                    A.oszlopok = alapElrendezes();
                    ment();
                    rajzol();
                    rajzolFej();
                    rajzolSorok();

                    if (A.q !== '') {
                        leker({ csendben: true });
                    }
                }
            });

            rajzol();
        });
    }

    /* ---------------------------------------------------------------- */
    /* Események – eszköztár                                            */
    /* ---------------------------------------------------------------- */

    function muvelet(m, gomb) {
        switch (m) {
            case 'nezet':
                nezetPanel(gomb);
                break;

            case 'oszlopok':
                oszlopPanel(gomb);
                break;

            case 'elso':
                A.oldal = 1;
                leker();
                break;

            case 'elozo':
                A.oldal = Math.max(1, A.oldal - 1);
                leker();
                break;

            case 'kovetkezo':
                A.oldal += 1;
                leker();
                break;

            case 'utolso':
                A.oldal = adat ? adat.oldalak : 1;
                leker();
                break;

            case 'frissit':
                leker();
                break;

            case 'osszeg':
                A.osszeg = !A.osszeg;
                ment();
                rajzolSorok();
                frissitEszkoztar();
                break;

            case 'torol':
                A.szurok = {};
                A.q = '';
                A.nezet = 'mind';
                A.rendezes = masol(nezetKeres('mind').rendezes);
                keresoMezo.value = '';
                rajzolFej();
                valtozott(true);
                break;
        }
    }

    gyoker.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest('[data-m]');

        if (gomb && !gomb.disabled) {
            muvelet(gomb.getAttribute('data-m'), gomb);
        }
    });

    keresoMezo.addEventListener('input', function () {
        A.q = keresoMezo.value.trim();
        valtozott(false);
    });

    keresoMezo.addEventListener('keydown', function (esemeny) {
        if (esemeny.key === 'Escape' && keresoMezo.value !== '') {
            keresoMezo.value = '';
            A.q = '';
            valtozott(true);
        } else if (esemeny.key === 'ArrowDown') {
            esemeny.preventDefault();
            gorgeto.focus();
        }
    });

    meretValaszto.addEventListener('change', function () {
        A.meret = parseInt(meretValaszto.value, 10) || A.meret;
        valtozott(true);
    });

    /* ---------------------------------------------------------------- */
    /* Események – fejléc: rendezés, átméretezés, sorrend, szűrők       */
    /* ---------------------------------------------------------------- */

    var huzasUtan = false;

    thead.addEventListener('click', function (esemeny) {
        var panelGomb = esemeny.target.closest('[data-szuropanel]');

        if (panelGomb) {
            szuroPanel(panelGomb.getAttribute('data-szuropanel'), panelGomb.closest('.sdh-racs__szuro'));

            return;
        }

        var mezo = esemeny.target.closest('[data-szuromezo]');

        if (mezo) {
            // A csak feliratként megjelenő szűrő a panelben szerkeszthető.
            if (mezo.readOnly) {
                szuroPanel(mezo.getAttribute('data-szuromezo'), mezo.closest('.sdh-racs__szuro'));
            }

            return;
        }

        var th = esemeny.target.closest('.sdh-racs__fejsor th');

        if (!th || esemeny.target.closest('[data-fogo]') || huzasUtan) {
            return;
        }

        var k = th.getAttribute('data-k');
        var hol = -1;

        A.rendezes.forEach(function (r, i) {
            if (r.k === k) {
                hol = i;
            }
        });

        // Kattintás: növekvő → csökkenő → nincs. Shifttel másodlagos rendezés.
        if (esemeny.shiftKey) {
            if (hol < 0) {
                if (A.rendezes.length < 3) {
                    A.rendezes.push({ k: k, i: 'fel' });
                }
            } else if (A.rendezes[hol].i === 'fel') {
                A.rendezes[hol].i = 'le';
            } else {
                A.rendezes.splice(hol, 1);
            }
        } else if (hol === 0 && A.rendezes.length === 1) {
            if (A.rendezes[0].i === 'fel') {
                A.rendezes[0].i = 'le';
            } else {
                A.rendezes = [];
            }
        } else {
            A.rendezes = [{ k: k, i: 'fel' }];
        }

        rajzolFej();
        valtozott(true);
    });

    thead.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target.closest('[data-szuromezo]');

        if (!mezo || mezo.readOnly) {
            return;
        }

        var k = mezo.getAttribute('data-szuromezo');
        var szuro = gepeltSzuro(k, mezo.value);

        if (szuro) {
            A.szurok[k] = szuro;
        } else {
            delete A.szurok[k];
        }

        szuroJelzesek();
        valtozott(false);
    });

    thead.addEventListener('keydown', function (esemeny) {
        var mezo = esemeny.target.closest('[data-szuromezo]');

        if (!mezo) {
            return;
        }

        if (esemeny.key === 'Enter') {
            esemeny.preventDefault();
            valtozott(true);
        } else if (esemeny.key === 'Escape' && A.szurok[mezo.getAttribute('data-szuromezo')]) {
            esemeny.preventDefault();
            delete A.szurok[mezo.getAttribute('data-szuromezo')];
            rajzolFej();
            valtozott(true);
        } else if (esemeny.key === 'ArrowDown') {
            esemeny.preventDefault();
            gorgeto.focus();
        }
    });

    /* ---- Oszlopszélesség húzással ---- */

    thead.addEventListener('mousedown', function (esemeny) {
        var fogo = esemeny.target.closest('[data-fogo]');

        if (!fogo || esemeny.button !== 0) {
            return;
        }

        esemeny.preventDefault();
        esemeny.stopPropagation();

        var k = fogo.parentNode.getAttribute('data-k');
        var oszlop = null;

        A.oszlopok.forEach(function (lo) {
            if (lo.k === k) {
                oszlop = lo;
            }
        });

        if (!oszlop) {
            return;
        }

        var kezdoX = esemeny.clientX;
        var kezdoSz = oszlop.sz;
        var col = colgroup.querySelector('col[data-k="' + k + '"]');

        fogo.classList.add('is-huz');
        document.body.style.cursor = 'col-resize';

        function mozog(e) {
            var uj = Math.min(800, Math.max(40, kezdoSz + e.clientX - kezdoX));

            tabla.style.width = (parseFloat(tabla.style.width) + uj - oszlop.sz) + 'px';
            oszlop.sz = uj;
            col.style.width = uj + 'px';
        }

        function vege() {
            document.removeEventListener('mousemove', mozog);
            document.removeEventListener('mouseup', vege);
            document.body.style.cursor = '';
            fogo.classList.remove('is-huz');
            ment();

            // A felengedés kattintásnak is számítana: ne rendezzen.
            huzasUtan = true;
            window.setTimeout(function () {
                huzasUtan = false;
            }, 0);
        }

        document.addEventListener('mousemove', mozog);
        document.addEventListener('mouseup', vege);
    });

    /* ---- Oszlopsorrend a fejléc húzásával ---- */

    var huzott = null;

    function celpontTorol() {
        Array.prototype.forEach.call(thead.querySelectorAll('.is-celpont-bal, .is-celpont-jobb, .is-huzott'), function (th) {
            th.classList.remove('is-celpont-bal', 'is-celpont-jobb', 'is-huzott');
        });
    }

    thead.addEventListener('dragstart', function (esemeny) {
        var th = esemeny.target.closest ? esemeny.target.closest('.sdh-racs__fejsor th') : null;

        if (!th) {
            return;
        }

        huzott = th.getAttribute('data-k');
        th.classList.add('is-huzott');

        try {
            esemeny.dataTransfer.effectAllowed = 'move';
            esemeny.dataTransfer.setData('text/plain', huzott);
        } catch (e) {
            /* némely böngésző nem engedi: a húzás így is működik */
        }
    });

    thead.addEventListener('dragover', function (esemeny) {
        var th = esemeny.target.closest ? esemeny.target.closest('.sdh-racs__fejsor th') : null;

        if (!huzott || !th || th.getAttribute('data-k') === huzott) {
            return;
        }

        esemeny.preventDefault();

        var r = th.getBoundingClientRect();
        var bal = esemeny.clientX < r.left + r.width / 2;

        Array.prototype.forEach.call(thead.querySelectorAll('.is-celpont-bal, .is-celpont-jobb'), function (masik) {
            masik.classList.remove('is-celpont-bal', 'is-celpont-jobb');
        });

        th.classList.add(bal ? 'is-celpont-bal' : 'is-celpont-jobb');
    });

    thead.addEventListener('drop', function (esemeny) {
        var th = esemeny.target.closest ? esemeny.target.closest('.sdh-racs__fejsor th') : null;

        if (!huzott || !th) {
            return;
        }

        esemeny.preventDefault();

        var cel = th.getAttribute('data-k');
        var r = th.getBoundingClientRect();
        var ele = esemeny.clientX < r.left + r.width / 2;
        var mozgo = null;

        A.oszlopok = A.oszlopok.filter(function (lo) {
            if (lo.k === huzott) {
                mozgo = lo;

                return false;
            }

            return true;
        });

        var hova = A.oszlopok.length;

        A.oszlopok.forEach(function (lo, i) {
            if (lo.k === cel) {
                hova = ele ? i : i + 1;
            }
        });

        if (mozgo) {
            A.oszlopok.splice(hova, 0, mozgo);
        }

        huzott = null;
        ment();
        rajzolFej();
        rajzolSorok();
    });

    thead.addEventListener('dragend', function () {
        huzott = null;
        celpontTorol();
    });

    /* ---------------------------------------------------------------- */
    /* Események – sorok                                                */
    /* ---------------------------------------------------------------- */

    tbody.addEventListener('click', function (esemeny) {
        var zaszlo = esemeny.target.closest('[data-jelzes]');
        var sor = esemeny.target.closest('tr[data-id]');

        if (sor) {
            kijelol(parseInt(sor.getAttribute('data-id'), 10), true);
        }

        if (!zaszlo) {
            return;
        }

        var id = parseInt(zaszlo.getAttribute('data-jelzes'), 10);
        var be = !zaszlo.classList.contains('is-be');

        zaszlo.disabled = true;

        kuld('sdh_muhely_racs_jelzes', { id: String(id), jelzes: be ? 'piros' : '' })
            .then(function () {
                zaszlo.disabled = false;
                zaszlo.classList.toggle('is-be', be);
                zaszlo.setAttribute('aria-pressed', be ? 'true' : 'false');
                zaszlo.title = be ? 'Jelzés levétele' : 'Megjelölés';
                zaszlo.setAttribute('aria-label', zaszlo.title);

                (adat ? adat.sorok : []).forEach(function (s) {
                    if (s.id === id) {
                        s.jelzes = be ? 'piros' : '';
                    }
                });
            })
            .catch(function (ok) {
                zaszlo.disabled = false;
                uzenet(ok.message, true);
            });
    });

    tbody.addEventListener('dblclick', function (esemeny) {
        var sor = esemeny.target.closest('tr[data-id]');

        // Az állapotválasztón és a zászlón a dupla kattintás nem szerkesztés.
        if (!sor || esemeny.target.closest('select, button, .sdh-allapot')) {
            return;
        }

        szerkeszt(parseInt(sor.getAttribute('data-id'), 10));
    });

    gorgeto.addEventListener('keydown', function (esemeny) {
        // A mezők, választók és gombok saját billentyűit nem vesszük el.
        if (esemeny.target.closest('input, select, button, textarea')) {
            return;
        }

        var oldalnyi = Math.max(1, Math.floor((gorgeto.clientHeight - 100) / 30));

        switch (esemeny.key) {
            case 'ArrowDown':
                esemeny.preventDefault();
                lep(1);
                break;

            case 'ArrowUp':
                esemeny.preventDefault();
                lep(-1);
                break;

            case 'PageDown':
                esemeny.preventDefault();
                lep(oldalnyi);
                break;

            case 'PageUp':
                esemeny.preventDefault();
                lep(-oldalnyi);
                break;

            case 'Home':
                esemeny.preventDefault();
                lep(-100000);
                break;

            case 'End':
                esemeny.preventDefault();
                lep(100000);
                break;

            case 'Enter':
                esemeny.preventDefault();
                szerkeszt(kijeloltId);
                break;
        }
    });

    /* ---------------------------------------------------------------- */
    /* Események – részletpanel és elválasztó                           */
    /* ---------------------------------------------------------------- */

    /* A panelből nyitott popup (ügyfél, eszköz) mentése helyben frissül. */
    var sajatPopup = false;

    document.addEventListener('click', function (esemeny) {
        var nyito = esemeny.target.closest ? esemeny.target.closest('[data-sdh-urlap]') : null;

        if (nyito) {
            sajatPopup = gyoker.contains(nyito);
        }
    }, true);

    reszlet.addEventListener('click', function (esemeny) {
        var ful = esemeny.target.closest('[data-ful].sdh-reszlet__ful');

        if (ful) {
            A.ful = ful.getAttribute('data-ful');
            ment();
            fulValt(A.ful);

            return;
        }

        var alful = esemeny.target.closest('[data-sdh-alful]');

        if (alful) {
            var panel = alful.closest('.sdh-reszlet__panel');

            alfulek[panel.getAttribute('data-ful')] = alful.getAttribute('data-sdh-alful');
            alfulValt(panel, alful.getAttribute('data-sdh-alful'));
        }
    });

    elvalaszto.addEventListener('click', function (esemeny) {
        if (esemeny.target.closest('[data-rejto]')) {
            A.reszletRejtve = !A.reszletRejtve;
            ment();
            meretez();
        }
    });

    elvalaszto.addEventListener('mousedown', function (esemeny) {
        if (esemeny.button !== 0 || A.reszletRejtve || esemeny.target.closest('[data-rejto]')) {
            return;
        }

        esemeny.preventDefault();

        var kezdoY = esemeny.clientY;
        var kezdoMagas = reszlet.getBoundingClientRect().height;
        var felso = gyoker.getBoundingClientRect().height - 260;

        document.body.style.cursor = 'row-resize';

        function mozog(e) {
            A.reszletMagas = Math.max(120, Math.min(Math.max(120, felso), kezdoMagas - (e.clientY - kezdoY)));
            reszlet.style.height = A.reszletMagas + 'px';
        }

        function vege() {
            document.removeEventListener('mousemove', mozog);
            document.removeEventListener('mouseup', vege);
            document.body.style.cursor = '';
            ment();
        }

        document.addEventListener('mousemove', mozog);
        document.addEventListener('mouseup', vege);
    });

    /* ---------------------------------------------------------------- */
    /* Kapcsolat az app.js-sel                                          */
    /* ---------------------------------------------------------------- */

    var MENTES_UZENET = {
        munkalap_mentve: 'A munkalap elmentve.',
        munkalap_letrehozva: 'A munkalap létrejött.',
        mentve: 'Elmentve.',
        letrehozva: 'Az ügyfél létrejött.',
        letrehozva_eszkoz: 'Az eszköz létrejött.'
    };

    document.addEventListener('sdh:mentve', function (esemeny) {
        var reszletek = esemeny.detail || {};
        var munkalap = /_munkalapok_ment$/.test(reszletek.action || '');

        // Az oldal fejlécéből indított ügyfél- vagy eszközfelvitel a régi
        // úton megy tovább (a saját listájára); minden más itt marad.
        if (!munkalap && !sajatPopup) {
            return;
        }

        esemeny.preventDefault();

        var szoveg = 'Elmentve.';

        try {
            var cim = new URL((reszletek.adat && reszletek.adat.vissza) || '', window.location.href);
            var kulcs = cim.searchParams.get('uzenet') || '';
            var lapszam = cim.searchParams.get('szam') || '';

            szoveg = MENTES_UZENET[kulcs] || szoveg;

            if (kulcs === 'munkalap_letrehozva' && lapszam) {
                szoveg = 'A munkalap létrejött. Száma: ' + lapszam;
            }
        } catch (e) {
            /* a cím nem értelmezhető: marad az általános üzenet */
        }

        uzenet(szoveg);

        leker({ kijelol: munkalap && reszletek.adat ? parseInt(reszletek.adat.id, 10) || 0 : 0 });
    });

    document.addEventListener('sdh:allapot', function () {
        // Az állapotváltás a Lezárva dátumot és a szűrt listát is érintheti.
        leker();
    });

    /* ---------------------------------------------------------------- */
    /* Indulás                                                          */
    /* ---------------------------------------------------------------- */

    window.addEventListener('resize', kesleltet(meretez, 60));

    if (!nezetKeres(A.nezet)) {
        A.nezet = 'mind';
    }

    meretez();
    rajzolFej();
    frissitEszkoztar();
    leker();
}());
