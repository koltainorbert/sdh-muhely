/**
 * SDH Műhely – Csapat: belső üzenetek (0.38).
 *
 * Két része van:
 *  1. MINDEN CRM-oldalon: a „pulzus" – 15 másodpercenként megnézi az
 *     olvasatlanokat (oldalmenü jelvénye), az új üzenetekből felugró kártyát
 *     mutat (gyors válasszal), a fontos üzenetet felugró ablakban hozza,
 *     amíg nyugtázzák. A választ `sdh:pulzus` eseményként továbbadja – az
 *     AI-asszisztens (asszisztens.js) is ebből él.
 *  2. A Csapat oldal ([data-sdh-cs-app]): beszélgetéslista lapozva, élő
 *     szál (3,5 mp-enként frissül), válasz idézettel, kitűzés, fontos,
 *     visszavonás, gépelés-jelzés, „látta".
 *
 * Minden adatból jövő szöveg az e()-n megy át.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};

    if (!B.ajax || window.SDH_CSAPAT) {
        return;
    }

    function app() {
        return window.SDH_MUHELY_APP || {};
    }

    function e(szoveg) {
        return String(szoveg === null || szoveg === undefined ? '' : szoveg)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** Szöveg HTML-ként: sortörés, linkek (csak http/https). */
    function torzs(szoveg) {
        return e(szoveg)
            .replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]])/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>')
            .replace(/\n/g, '<br>');
    }

    function kerdes(akcio, adat, post) {
        adat = adat || {};

        var cim = new URL(B.ajax, window.location.origin);
        var opciok = { credentials: 'same-origin' };

        if (post) {
            var fd = new FormData();

            fd.set('action', 'sdh_muhely_' + akcio);
            fd.set('_wpnonce', B.nonce || '');
            Object.keys(adat).forEach(function (k) {
                if (Array.isArray(adat[k])) {
                    adat[k].forEach(function (v) { fd.append(k + '[]', v); });
                } else {
                    fd.set(k, adat[k]);
                }
            });
            opciok.method = 'POST';
            opciok.body = fd;
        } else {
            cim.searchParams.set('action', 'sdh_muhely_' + akcio);
            cim.searchParams.set('_wpnonce', B.nonce || '');
            Object.keys(adat).forEach(function (k) { cim.searchParams.set(k, adat[k]); });
        }

        return fetch(cim.toString(), opciok)
            .then(function (v) {
                return v.text().then(function (t) {
                    var j;

                    try {
                        j = JSON.parse(t);
                    } catch (x) {
                        throw new Error(v.status === 403 || t === '-1' ? 'Lejárt a munkamenet – frissítsd az oldalt.' : 'A szerver válasza nem értelmezhető.');
                    }

                    if (!j || !j.success) {
                        throw new Error((j && j.data && j.data.uzenet) || 'A művelet nem sikerült.');
                    }

                    return j.data;
                });
            });
    }

    var IKON = {
        kuld: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3.5 10h12M11 5l5 5-5 5"/></svg>',
        valasz: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M8 5 3.5 9.5 8 14"/><path d="M4 9.5h7.5a5 5 0 0 1 5 5v1"/></svg>',
        tu: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M12.5 3.5 16.5 7.5l-2.6 1.1-3.4 3.4.4 3.2-1.2 1.2-6.2-6.2 1.2-1.2 3.2.4 3.4-3.4z"/><path d="M6.4 13.6 3.5 16.5"/></svg>',
        vissza: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6 6h6.5a4 4 0 0 1 0 8H8"/><path d="M8.5 3.5 6 6l2.5 2.5"/></svg>',
        x: '<svg viewBox="0 0 12 12" aria-hidden="true"><path d="M3.6 3.6l4.8 4.8M8.4 3.6l-4.8 4.8"/></svg>',
        fontos: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3.2v8.3"/><path d="M10 15.6v.4"/></svg>',
        bal: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M10 3 5 8l5 5"/></svg>',
        jobb: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M6 3l5 5-5 5"/></svg>'
    };

    function avatar(a, online, osztaly) {
        a = a || {};

        return '<span class="sdh-cs-av sdh-cs-av--' + e(a.szin || 'kek') + (online ? ' is-online' : '') + (osztaly ? ' ' + osztaly : '') + '"><span>' + e(a.betu || '?') + '</span></span>';
    }

    function avatarok(lista, online) {
        lista = lista || [];

        if (lista.length <= 1) {
            return avatar(lista[0], online);
        }

        return '<span class="sdh-cs-avcsoport">' + lista.slice(0, 3).map(function (a) { return avatar(a, false); }).join('') + '</span>';
    }

    /* ================================================================ */
    /* 1. Pulzus – minden oldalon                                        */
    /* ================================================================ */

    var MAX_KULCS = 'sdh-cs-max';
    var utana = 0;
    var pulzusIdozito = 0;
    var eredetiCim = document.title.replace(/^\(\d+\+?\)\s*/, '');
    var latottFontos = {};

    try {
        utana = parseInt(window.sessionStorage.getItem(MAX_KULCS), 10) || 0;
    } catch (x) { /* privát mód */ }

    function jelveny(db) {
        db = parseInt(db, 10) || 0;

        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-jelveny="csapat"]'), function (jel) {
            var elozo = parseInt(jel.getAttribute('data-db'), 10) || 0;

            jel.setAttribute('data-db', String(db));
            jel.textContent = db > 999 ? '999+' : String(db);
            jel.title = db + ' olvasatlan';
            jel.hidden = db <= 0;

            if (db > elozo) {
                jel.classList.remove('is-friss');
                void jel.offsetWidth;
                jel.classList.add('is-friss');
            }
        });

        // A böngészőfül címe: a többi modul (pl. RMA) saját számát nem írjuk felül, csak hozzátesszük.
        var rma = document.querySelector('[data-sdh-jelveny="uzenetek"]');
        var rmaDb = rma ? (parseInt(rma.textContent, 10) || 0) : 0;
        var osszes = db + rmaDb;

        document.title = osszes > 0 ? '(' + osszes + ') ' + eredetiCim : eredetiCim;
    }

    function pulzus() {
        window.clearTimeout(pulzusIdozito);

        kerdes('pulzus', { utana: utana })
            .then(function (d) {
                jelveny(d.olvasatlan);

                if (utana > 0) {
                    (d.uj || []).forEach(function (u) {
                        if (oldal && oldal.aktiv === u.besz && document.visibilityState === 'visible') {
                            return; // épp ezt a beszélgetést nézi
                        }

                        toast(u, d.csapatUrl);
                    });
                }

                if (d.max > utana) {
                    utana = d.max;

                    try {
                        window.sessionStorage.setItem(MAX_KULCS, String(utana));
                    } catch (x) { /* privát mód */ }
                }

                (d.fontos || []).forEach(function (u) {
                    if (!latottFontos[u.id]) {
                        latottFontos[u.id] = true;
                        fontosSor.push(u);
                    }
                });
                fontosKovetkezo(d.csapatUrl);

                document.dispatchEvent(new CustomEvent('sdh:pulzus', { detail: d }));
            })
            .catch(function () { /* hálózati hiba: a következő ütem újrapróbálja */ })
            .then(function () {
                pulzusIdozito = window.setTimeout(pulzus, document.visibilityState === 'visible' ? 15000 : 60000);
            });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            pulzus();
        }
    });

    /* ---- Felugró kártyák (új üzenet) ---- */

    var toastDoboz = null;

    function toast(u, csapatUrl) {
        if (!toastDoboz) {
            toastDoboz = document.createElement('div');
            toastDoboz.className = 'sdh-cs-toastok';
            toastDoboz.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastDoboz);
        }

        var k = document.createElement('div');
        var url = (csapatUrl || '') + (String(csapatUrl).indexOf('?') >= 0 ? '&' : '?') + 'besz=' + u.besz;

        k.className = 'sdh-cs-toast';
        k.innerHTML =
            '<button type="button" class="sdh-cs-toast__x" aria-label="Bezárás">' + IKON.x + '</button>' +
            '<div class="sdh-cs-toast__fej">' + avatar(u.felado) +
            '<div><strong>' + e(u.felado.nev) + '</strong>' +
            (u.besz_tipus !== 'ketto' ? '<span>' + e(u.besz_nev) + '</span>' : '<span>üzent neked</span>') + '</div></div>' +
            '<p class="sdh-cs-toast__szoveg">' + torzs(String(u.szoveg).slice(0, 280)) + '</p>' +
            '<form class="sdh-cs-toast__valasz"><input type="text" maxlength="2000" placeholder="Gyors válasz…" aria-label="Gyors válasz">' +
            '<button type="submit" class="sdh-cs-kuldgomb" aria-label="Küldés">' + IKON.kuld + '</button></form>' +
            '<a class="sdh-cs-toast__link" href="' + e(url) + '">Megnyitom a beszélgetést</a>';

        toastDoboz.appendChild(k);
        requestAnimationFrame(function () { k.classList.add('is-latszik'); });

        var idozito = 0;

        function zar() {
            k.classList.remove('is-latszik');
            window.setTimeout(function () { k.remove(); }, 300);
        }

        function indit() {
            window.clearTimeout(idozito);
            idozito = window.setTimeout(zar, 14000);
        }

        k.addEventListener('mouseenter', function () { window.clearTimeout(idozito); });
        k.addEventListener('mouseleave', function () {
            if (!k.contains(document.activeElement)) {
                indit();
            }
        });
        k.addEventListener('focusin', function () { window.clearTimeout(idozito); });
        k.querySelector('.sdh-cs-toast__x').addEventListener('click', zar);
        k.querySelector('form').addEventListener('submit', function (ev) {
            ev.preventDefault();

            var mezo = k.querySelector('input');
            var szoveg = mezo.value.trim();

            if (!szoveg) {
                return;
            }

            mezo.disabled = true;
            kerdes('csapat_kuld', { besz: u.besz, szoveg: szoveg, valasz_id: u.besz_tipus === 'ketto' ? 0 : u.id }, true)
                .then(function () {
                    k.querySelector('.sdh-cs-toast__valasz').outerHTML = '<p class="sdh-cs-toast__kesz">Elküldve ✓</p>';
                    window.setTimeout(zar, 1800);
                    pulzus();
                })
                .catch(function (h) {
                    mezo.disabled = false;
                    mezo.placeholder = h.message;
                });
        });

        indit();

        // Legfeljebb négy kártya látszik.
        while (toastDoboz.children.length > 4) {
            toastDoboz.firstChild.remove();
        }
    }

    /* ---- Fontos üzenet: felugró ablak, amíg nyugtázzák ---- */

    var fontosSor = [];
    var fontosAblak = null;

    function fontosKovetkezo(csapatUrl) {
        if (fontosAblak && fontosAblak.open) {
            return;
        }

        var u = fontosSor.shift();

        if (!u) {
            return;
        }

        if (!fontosAblak) {
            fontosAblak = document.createElement('dialog');
            fontosAblak.className = 'sdh-cs-fontos';
            document.body.appendChild(fontosAblak);
        }

        var url = (csapatUrl || '') + (String(csapatUrl).indexOf('?') >= 0 ? '&' : '?') + 'besz=' + u.besz;

        fontosAblak.innerHTML =
            '<div class="sdh-cs-fontos__doboz">' +
            '<button type="button" class="sdh-modal__bezar" aria-label="Később" title="Később">' + IKON.x + '</button>' +
            '<p class="sdh-cs-fontos__kalap"><span class="sdh-cs-fontos__jel">' + IKON.fontos + '</span>Fontos üzenet</p>' +
            '<div class="sdh-cs-fontos__fej">' + avatar(u.felado) + '<div><strong>' + e(u.felado.nev) + '</strong><span>' +
            e(u.besz_tipus === 'ketto' ? 'neked' : u.besz_nev) + ' · ' + e(u.ido_szoveg) + '</span></div></div>' +
            '<div class="sdh-cs-fontos__szoveg">' + torzs(u.szoveg) + '</div>' +
            '<div class="sdh-cs-fontos__gombok">' +
            '<button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-nyugta>Elolvastam</button>' +
            '<a class="sdh-gomb" href="' + e(url) + '" data-valasz>Válaszolok</a>' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-kesobb>Később</button>' +
            '</div></div>';

        function nyugta() {
            return kerdes('csapat_nyugta', { id: u.id }, true).then(function (d) {
                jelveny(d.olvasatlan);
            }).catch(function () { /* a következő pulzus újra hozza */ });
        }

        function zar() {
            fontosAblak.close();
            window.setTimeout(function () { fontosKovetkezo(csapatUrl); }, 400);
        }

        fontosAblak.querySelector('[data-nyugta]').addEventListener('click', function () {
            nyugta();
            zar();
        });
        fontosAblak.querySelector('[data-valasz]').addEventListener('click', function () {
            nyugta();
        });
        fontosAblak.querySelector('[data-kesobb]').addEventListener('click', function () {
            delete latottFontos[u.id]; // a következő betöltésnél újra jön
            zar();
        });
        fontosAblak.querySelector('.sdh-modal__bezar').addEventListener('click', function () {
            delete latottFontos[u.id];
            zar();
        });

        fontosAblak.showModal();
    }

    /* ================================================================ */
    /* Pillek (Új üzenet, kolléga, csoport popupja)                     */
    /* ================================================================ */

    function pillFrissit(gyoker) {
        Array.prototype.forEach.call(gyoker.querySelectorAll('.sdh-cs-pill'), function (p) {
            var be = p.querySelector('input');

            p.classList.toggle('is-aktiv', !!(be && be.checked));
        });
    }

    document.addEventListener('change', function (ev) {
        var pill = ev.target.closest && ev.target.closest('.sdh-cs-pill');

        if (!pill) {
            return;
        }

        var csoport = pill.closest('.sdh-cs-pillek');

        // Címzettek: a „Mindenki" kizárja a többit, és fordítva.
        if (csoport && csoport.hasAttribute('data-sdh-cs-cimzettek') && ev.target.checked) {
            Array.prototype.forEach.call(csoport.querySelectorAll('input'), function (be) {
                if (be !== ev.target && (ev.target.value === 'mindenki' || be.value === 'mindenki')) {
                    be.checked = false;
                }
            });

            var szemelyek = csoport.querySelectorAll('input:checked').length;
            var nevmezo = csoport.closest('form').querySelector('[data-sdh-cs-nevmezo]');

            if (nevmezo) {
                nevmezo.hidden = szemelyek < 2;
            }
        } else if (csoport && csoport.hasAttribute('data-sdh-cs-cimzettek')) {
            var nm = csoport.closest('form').querySelector('[data-sdh-cs-nevmezo]');

            if (nm) {
                nm.hidden = csoport.querySelectorAll('input:checked').length < 2;
            }
        }

        pillFrissit(csoport || document);
    });

    /* ================================================================ */
    /* 2. A Csapat oldal                                                 */
    /* ================================================================ */

    var oldal = null;
    var gyoker = document.querySelector('[data-sdh-cs-app]');

    if (gyoker) {
        oldal = csapatOldal(gyoker);
    }

    function csapatOldal(g) {
        var LAP = 9;
        var s = {
            aktiv: 0,
            lista: [],
            kollegak: [],
            csoportok: [],
            en: {},
            lap: 0,
            szuro: '',
            min: 0,
            max: 0,
            tobb: false,
            valasz: null,
            besz: null,
            frissitIdo: 0,
            gepelt: 0,
            latta: []
        };

        var listaElem = g.querySelector('[data-sdh-cs-lista]');
        var lapozoElem = g.querySelector('[data-sdh-cs-lapozo]');
        var szalElem = g.querySelector('[data-sdh-cs-szal]');
        var kereso = g.querySelector('[data-sdh-cs-kereso]');

        function listaBetolt() {
            return kerdes('csapat_lista').then(function (d) {
                s.lista = d.beszelgetesek || [];
                s.kollegak = d.kollegak || [];
                s.csoportok = d.csoportok || [];
                s.en = d.en || {};
                listaRajzol();
            }).catch(function (h) {
                listaElem.innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">' + e(h.message) + '</div>';
            });
        }

        function illik(szoveg) {
            return !s.szuro || String(szoveg).toLowerCase().indexOf(s.szuro) >= 0;
        }

        function listaRajzol() {
            var elemek = s.lista.filter(function (b) { return illik(b.nev); });
            var vanKetto = {};

            s.lista.forEach(function (b) {
                if (b.tipus === 'ketto') {
                    vanKetto[b.nev] = true;
                }
            });

            // A kollégák, akikkel még nincs beszélgetés, és a csoportok a lista végén – egy kattintással indítható.
            s.kollegak.forEach(function (k) {
                if (!vanKetto[k.nev] && illik(k.nev)) {
                    elemek.push({ uj: 'kivel', id: k.id, nev: k.nev, alcim: k.online ? 'Elérhető' : (k.titulus || 'Kolléga'), kep: [k], online: k.online });
                }
            });

            var oldalak = Math.max(1, Math.ceil(elemek.length / LAP));

            s.lap = Math.min(s.lap, oldalak - 1);

            var resz = elemek.slice(s.lap * LAP, s.lap * LAP + LAP);

            if (!resz.length) {
                listaElem.innerHTML = '<p class="sdh-cs-halvany">' + (s.szuro ? 'Nincs találat.' : 'Még nincs beszélgetés.') + '</p>';
            } else {
                listaElem.innerHTML = resz.map(function (b) {
                    var aktiv = !b.uj && b.id === s.aktiv;

                    return '<button type="button" class="sdh-cs-elem' + (aktiv ? ' is-aktiv' : '') + (b.olvasatlan ? ' is-olvasatlan' : '') + (b.uj ? ' is-uj' : '') + '"' +
                        (b.uj ? ' data-kivel="' + b.id + '"' : ' data-besz="' + b.id + '"') + '>' +
                        avatarok(b.kep, b.online) +
                        '<span class="sdh-cs-elem__test"><span class="sdh-cs-elem__sor"><strong>' + e(b.nev) + '</strong>' +
                        (b.utolso ? '<small>' + e(b.utolso.ido) + '</small>' : '') + '</span>' +
                        '<span class="sdh-cs-elem__elo">' + (b.utolso ? e(b.utolso.nev) + ': ' + e(b.utolso.szoveg) : e(b.uj ? 'Új beszélgetés indítása' : b.alcim)) + '</span></span>' +
                        (b.olvasatlan ? '<span class="sdh-cs-elem__db">' + (b.olvasatlan > 99 ? '99+' : b.olvasatlan) + '</span>' : '') +
                        '</button>';
                }).join('');
            }

            if (oldalak > 1) {
                lapozoElem.hidden = false;
                lapozoElem.innerHTML =
                    '<button type="button" class="sdh-gomb sdh-lapnyil" data-lap="-1" aria-label="Előző oldal"' + (s.lap <= 0 ? ' disabled' : '') + '>' + IKON.bal + '</button>' +
                    '<span>' + (s.lap + 1) + ' / ' + oldalak + '</span>' +
                    '<button type="button" class="sdh-gomb sdh-lapnyil" data-lap="1" aria-label="Következő oldal"' + (s.lap >= oldalak - 1 ? ' disabled' : '') + '>' + IKON.jobb + '</button>';
            } else {
                lapozoElem.hidden = true;
                lapozoElem.innerHTML = '';
            }
        }

        listaElem.addEventListener('click', function (ev) {
            var el = ev.target.closest('.sdh-cs-elem');

            if (!el) {
                return;
            }

            if (el.hasAttribute('data-kivel')) {
                megnyit({ kivel: el.getAttribute('data-kivel') });
            } else {
                megnyit({ besz: el.getAttribute('data-besz') });
            }
        });

        lapozoElem.addEventListener('click', function (ev) {
            var gomb = ev.target.closest('[data-lap]');

            if (gomb && !gomb.disabled) {
                s.lap += parseInt(gomb.getAttribute('data-lap'), 10);
                listaRajzol();
            }
        });

        kereso.addEventListener('input', function () {
            s.szuro = kereso.value.trim().toLowerCase();
            s.lap = 0;
            listaRajzol();
        });

        /* ---- A szál ---- */

        function uzenetHtml(u, elozo) {
            if (u.torolve) {
                return '<div class="sdh-cs-uz is-torolt' + (u.sajat ? ' is-sajat' : '') + '" data-id="' + u.id + '"><div class="sdh-cs-uz__buborek"><em>Visszavont üzenet</em></div></div>';
            }

            var egyutt = elozo && !elozo.torolve && elozo.felado.id === u.felado.id && elozo.forras === u.forras;
            var meta = [e(u.ido_szoveg)];

            if (u.sajat && u.latta && u.latta.length) {
                meta.push('Látta: ' + e(u.latta.join(', ')));
            }

            if (u.fontos && u.nyugtazta && u.nyugtazta.length) {
                meta.push('Elolvasta: ' + e(u.nyugtazta.join(', ')));
            }

            return '<div class="sdh-cs-uz' + (u.sajat ? ' is-sajat' : '') + (u.fontos ? ' is-fontos' : '') + (u.kituzve ? ' is-kituzve' : '') + (egyutt ? ' is-folytatas' : '') + (u.forras === 'ai' ? ' is-ai' : '') + '" data-id="' + u.id + '">' +
                (u.sajat ? '' : (egyutt ? '<span class="sdh-cs-uz__hely"></span>' : avatar(u.felado))) +
                '<div class="sdh-cs-uz__oszlop">' +
                (!u.sajat && !egyutt ? '<span class="sdh-cs-uz__nev">' + e(u.felado.nev) + (u.forras === 'ai' ? ' <em>AI</em>' : '') + '</span>' : '') +
                '<div class="sdh-cs-uz__buborek">' +
                (u.fontos ? '<span class="sdh-cs-uz__jel sdh-cs-uz__jel--fontos">Fontos</span>' : '') +
                (u.kituzve ? '<span class="sdh-cs-uz__jel">Kitűzve</span>' : '') +
                (u.valasz ? '<button type="button" class="sdh-cs-uz__idezet" data-ugrik="' + u.valasz.id + '"><strong>' + e(u.valasz.nev) + '</strong>' + e(u.valasz.szoveg) + '</button>' : '') +
                '<div class="sdh-cs-uz__szoveg">' + torzs(u.szoveg) + '</div>' +
                (u.hivatkozas ? '<button type="button" class="sdh-cs-uz__hiv" data-hiv-tipus="' + e(u.hivatkozas.tipus) + '" data-hiv-id="' + u.hivatkozas.id + '">' + e(u.hivatkozas.cim) + ' megnyitása</button>' : '') +
                '</div>' +
                '<div class="sdh-cs-uz__meta">' + meta.join(' · ') + '</div>' +
                '</div>' +
                '<div class="sdh-cs-uz__muveletek">' +
                '<button type="button" data-muvelet="valasz" title="Válasz" aria-label="Válasz">' + IKON.valasz + '</button>' +
                ((u.sajat || B.admin) ? '<button type="button" data-muvelet="kituz" title="' + (u.kituzve ? 'Levétel' : 'Kitűzés') + '" aria-label="Kitűzés">' + IKON.tu + '</button>' : '') +
                (u.sajat ? '<button type="button" data-muvelet="visszavon" title="Visszavonás" aria-label="Visszavonás">' + IKON.vissza + '</button>' : '') +
                '</div></div>';
        }

        function szalVaz(d) {
            var b = d.besz;

            szalElem.innerHTML =
                '<header class="sdh-cs-szal__fej">' + avatarok(b.kep, b.online) +
                '<div class="sdh-cs-szal__cim"><strong>' + e(b.nev) + '</strong><span>' + e(b.alcim) + '</span></div>' +
                '<div class="sdh-cs-szal__tagok">' + (b.tagok || []).slice(0, 8).map(function (t) {
                    return '<span title="' + e(t.nev) + (t.online ? ' – elérhető' : '') + '">' + avatar(t, t.online, 'sdh-cs-av--kicsi') + '</span>';
                }).join('') + '</div></header>' +
                '<div class="sdh-cs-kituzott" data-kituzott hidden></div>' +
                '<div class="sdh-cs-uzenetek" data-uzenetek></div>' +
                '<div class="sdh-cs-gepel" data-gepel hidden><span></span><span></span><span></span><em></em></div>' +
                '<form class="sdh-cs-iro" data-iro>' +
                '<div class="sdh-cs-iro__valasz" data-valaszsav hidden></div>' +
                '<div class="sdh-cs-iro__sor">' +
                '<textarea rows="1" maxlength="4000" placeholder="Üzenet ' + e(b.nev) + ' részére… (Enter: küldés, Shift+Enter: új sor)" aria-label="Üzenet"></textarea>' +
                '<button type="submit" class="sdh-cs-kuldgomb sdh-cs-kuldgomb--nagy" aria-label="Küldés">' + IKON.kuld + '</button></div>' +
                '<div class="sdh-cs-iro__kapcsolok">' +
                '<label class="sdh-cs-mini"><input type="checkbox" name="fontos" value="1"><span>' + IKON.fontos + 'Fontos</span></label>' +
                '<label class="sdh-cs-mini"><input type="checkbox" name="kituz" value="1"><span>' + IKON.tu + 'Kitűzés</span></label>' +
                '</div></form>';
        }

        function kituzottRajzol(lista) {
            var doboz = szalElem.querySelector('[data-kituzott]');

            if (!doboz) {
                return;
            }

            if (!lista || !lista.length) {
                doboz.hidden = true;
                doboz.innerHTML = '';

                return;
            }

            doboz.hidden = false;
            doboz.innerHTML = '<span class="sdh-cs-kituzott__cim">' + IKON.tu + 'Kitűzve (' + lista.length + ')</span>' +
                lista.slice(0, 3).map(function (u) {
                    return '<button type="button" data-ugrik="' + u.id + '"><strong>' + e(u.felado.nev) + ':</strong> ' + e(String(u.szoveg).slice(0, 120)) + '</button>';
                }).join('');
        }

        function uzenetekRajzol(lista, elejere) {
            var doboz = szalElem.querySelector('[data-uzenetek]');
            var html = '';
            var elozo = null;

            lista.forEach(function (u) {
                html += uzenetHtml(u, elozo);
                elozo = u;
            });

            if (elejere) {
                var regiMagassag = doboz.scrollHeight;
                var regiTov = doboz.querySelector('[data-korabbi]');

                if (regiTov) {
                    regiTov.remove();
                }

                doboz.insertAdjacentHTML('afterbegin', html);
                doboz.scrollTop = doboz.scrollHeight - regiMagassag;
            } else {
                var lent = doboz.scrollHeight - doboz.scrollTop - doboz.clientHeight < 80;

                doboz.insertAdjacentHTML('beforeend', html);

                if (lent || !elozoVolt) {
                    doboz.scrollTop = doboz.scrollHeight;
                }
            }

            if (s.tobb && !doboz.querySelector('[data-korabbi]')) {
                doboz.insertAdjacentHTML('afterbegin', '<button type="button" class="sdh-cs-korabbi" data-korabbi>Korábbi üzenetek</button>');
            }

            if (!doboz.children.length) {
                doboz.innerHTML = '<p class="sdh-cs-halvany sdh-cs-halvany--kozep">Még nincs üzenet. Írd meg az elsőt!</p>';
            }
        }

        var elozoVolt = false;

        function megnyit(par) {
            window.clearTimeout(s.frissitIdo);
            s.valasz = null;
            elozoVolt = false;
            szalElem.innerHTML = '<div class="sdh-cs-toltes">Betöltés…</div>';

            return kerdes('csapat_szal', par).then(function (d) {
                s.besz = d.besz;
                s.aktiv = d.besz.id;
                s.tobb = d.tobb;
                s.min = d.uzenetek.length ? d.uzenetek[0].id : 0;
                s.max = d.uzenetek.length ? d.uzenetek[d.uzenetek.length - 1].id : 0;
                s.utolsoUzenet = d.uzenetek.length ? d.uzenetek[d.uzenetek.length - 1] : null;

                szalVaz(d);
                kituzottRajzol(d.kituzott);
                uzenetekRajzol(d.uzenetek, false);
                elozoVolt = true;
                iroBekot();

                try {
                    var url = new URL(window.location.href);

                    url.searchParams.set('besz', String(s.aktiv));
                    window.history.replaceState(null, '', url.toString());
                } catch (x) { /* régi böngésző */ }

                g.classList.add('is-szal');

                // A listában az olvasatlan eltűnik.
                s.lista.forEach(function (b) {
                    if (b.id === s.aktiv) {
                        b.olvasatlan = 0;
                    }
                });
                if (!s.lista.some(function (b) { return b.id === s.aktiv; })) {
                    listaBetolt();
                } else {
                    listaRajzol();
                }

                pulzus();
                frissitUtemez();
            }).catch(function (h) {
                szalElem.innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">' + e(h.message) + '</div>';
            });
        }

        function frissitUtemez() {
            window.clearTimeout(s.frissitIdo);
            s.frissitIdo = window.setTimeout(frissit, document.visibilityState === 'visible' ? 3500 : 20000);
        }

        function frissit() {
            if (!s.aktiv) {
                return;
            }

            var besz = s.aktiv;

            kerdes('csapat_frissit', { besz: besz, utana: s.max }).then(function (d) {
                if (besz !== s.aktiv) {
                    return;
                }

                if (d.uzenetek.length) {
                    var ures = szalElem.querySelector('.sdh-cs-halvany--kozep');

                    if (ures) {
                        ures.remove();
                    }

                    var csakUj = d.uzenetek.filter(function (u) { return !szalElem.querySelector('.sdh-cs-uz[data-id="' + u.id + '"]'); });

                    if (csakUj.length) {
                        var html = '';
                        var elozo = s.utolsoUzenet;

                        csakUj.forEach(function (u) {
                            html += uzenetHtml(u, elozo);
                            elozo = u;
                        });

                        var doboz = szalElem.querySelector('[data-uzenetek]');
                        var lent = doboz.scrollHeight - doboz.scrollTop - doboz.clientHeight < 120;

                        doboz.insertAdjacentHTML('beforeend', html);

                        if (lent) {
                            doboz.scrollTop = doboz.scrollHeight;
                        }

                        s.utolsoUzenet = csakUj[csakUj.length - 1];
                    }

                    s.max = Math.max(s.max, d.uzenetek[d.uzenetek.length - 1].id);
                    listaBetolt();
                    pulzus(); // a menü jelvénye a már olvasottnak jelölt üzenetekkel frissüljön
                }

                // Visszavont / kitűzött üzenetek frissítése helyben.
                (d.valtozott || []).forEach(function (u) {
                    var regi = szalElem.querySelector('.sdh-cs-uz[data-id="' + u.id + '"]');

                    if (regi) {
                        var tmp = document.createElement('div');

                        tmp.innerHTML = uzenetHtml(u, null);
                        regi.replaceWith(tmp.firstChild);
                    }
                });

                // „Látta" a saját utolsó üzenetemen.
                lattaFrissit(d.latta || []);

                var gep = szalElem.querySelector('[data-gepel]');

                if (gep) {
                    gep.hidden = !d.gepel.length;
                    gep.querySelector('em').textContent = d.gepel.length ? d.gepel.join(', ') + ' gépel…' : '';
                }
            }).catch(function () { /* hálózat: újrapróba */ }).then(frissitUtemez);
        }

        function lattaFrissit(latta) {
            var sajatok = szalElem.querySelectorAll('.sdh-cs-uz.is-sajat:not(.is-torolt)');
            var utolso = sajatok[sajatok.length - 1];

            if (!utolso) {
                return;
            }

            var id = parseInt(utolso.getAttribute('data-id'), 10);
            var nevek = latta.filter(function (l) { return l.olvasott >= id; }).map(function (l) { return l.nev; });
            var meta = utolso.querySelector('.sdh-cs-uz__meta');

            if (!meta || !nevek.length) {
                return;
            }

            var resz = meta.innerHTML.split(' · ').filter(function (x) { return x.indexOf('Látta:') !== 0; });

            resz.splice(1, 0, 'Látta: ' + e(nevek.join(', ')));
            meta.innerHTML = resz.join(' · ');
        }

        function valaszSav() {
            var sav = szalElem.querySelector('[data-valaszsav]');

            if (!sav) {
                return;
            }

            if (!s.valasz) {
                sav.hidden = true;
                sav.innerHTML = '';

                return;
            }

            sav.hidden = false;
            sav.innerHTML = IKON.valasz + '<span><strong>' + e(s.valasz.nev) + '</strong> ' + e(String(s.valasz.szoveg).slice(0, 120)) + '</span>' +
                '<button type="button" aria-label="Válasz elvetése" data-valasz-x>' + IKON.x + '</button>';
        }

        function iroBekot() {
            var urlap = szalElem.querySelector('[data-iro]');
            var mezo = urlap.querySelector('textarea');

            function meret() {
                mezo.style.height = 'auto';
                mezo.style.height = Math.min(mezo.scrollHeight, 160) + 'px';
            }

            mezo.addEventListener('input', function () {
                meret();

                if (Date.now() - s.gepelt > 3000 && mezo.value.trim()) {
                    s.gepelt = Date.now();
                    kerdes('csapat_gepel', { besz: s.aktiv }, true).catch(function () {});
                }
            });

            mezo.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
                    ev.preventDefault();
                    urlap.requestSubmit();
                }

                if (ev.key === 'Escape' && s.valasz) {
                    s.valasz = null;
                    valaszSav();
                }
            });

            urlap.addEventListener('submit', function (ev) {
                ev.preventDefault();

                var szoveg = mezo.value.trim();

                if (!szoveg) {
                    mezo.focus();

                    return;
                }

                var gomb = urlap.querySelector('button[type="submit"]');
                var fontos = urlap.querySelector('[name="fontos"]');
                var kituz = urlap.querySelector('[name="kituz"]');

                gomb.disabled = true;

                kerdes('csapat_kuld', {
                    besz: s.aktiv,
                    szoveg: szoveg,
                    valasz_id: s.valasz ? s.valasz.id : 0,
                    fontos: fontos.checked ? '1' : '0',
                    kituz: kituz.checked ? '1' : '0'
                }, true).then(function () {
                    mezo.value = '';
                    meret();
                    fontos.checked = false;
                    kituz.checked = false;
                    s.valasz = null;
                    valaszSav();
                    window.clearTimeout(s.frissitIdo);
                    frissit();
                }).catch(function (h) {
                    app().hiba ? app().hiba(urlap, h.message) : window.alert(h.message);
                }).then(function () {
                    gomb.disabled = false;
                    mezo.focus();
                });
            });

            mezo.focus();
        }

        szalElem.addEventListener('click', function (ev) {
            var korabbi = ev.target.closest('[data-korabbi]');

            if (korabbi) {
                korabbi.disabled = true;
                kerdes('csapat_szal', { besz: s.aktiv, elotte: s.min }).then(function (d) {
                    s.tobb = d.tobb;
                    s.min = d.uzenetek.length ? d.uzenetek[0].id : s.min;
                    uzenetekRajzol(d.uzenetek, true);
                }).catch(function () { korabbi.disabled = false; });

                return;
            }

            var ugrik = ev.target.closest('[data-ugrik]');

            if (ugrik) {
                var cel = szalElem.querySelector('.sdh-cs-uz[data-id="' + ugrik.getAttribute('data-ugrik') + '"]');

                if (cel) {
                    cel.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    cel.classList.remove('is-villan');
                    void cel.offsetWidth;
                    cel.classList.add('is-villan');
                }

                return;
            }

            var hiv = ev.target.closest('[data-hiv-tipus]');

            if (hiv && app().nyit) {
                app().nyit(hiv.getAttribute('data-hiv-tipus'), hiv.getAttribute('data-hiv-id'));

                return;
            }

            if (ev.target.closest('[data-valasz-x]')) {
                s.valasz = null;
                valaszSav();

                return;
            }

            var gomb = ev.target.closest('[data-muvelet]');

            if (!gomb) {
                return;
            }

            var uz = gomb.closest('.sdh-cs-uz');
            var id = parseInt(uz.getAttribute('data-id'), 10);
            var muvelet = gomb.getAttribute('data-muvelet');

            if (muvelet === 'valasz') {
                var nev = uz.querySelector('.sdh-cs-uz__nev');
                var szov = uz.querySelector('.sdh-cs-uz__szoveg');

                s.valasz = { id: id, nev: uz.classList.contains('is-sajat') ? 'Te' : (nev ? nev.textContent : ''), szoveg: szov ? szov.textContent : '' };
                valaszSav();
                szalElem.querySelector('[data-iro] textarea').focus();
            } else if (muvelet === 'kituz') {
                kerdes('csapat_kituz', { id: id, be: uz.classList.contains('is-kituzve') ? '0' : '1' }, true)
                    .then(function () { return megnyit({ besz: s.aktiv }); })
                    .catch(function (h) { window.alert(h.message); });
            } else if (muvelet === 'visszavon') {
                // Megerősítés helyben (nem a böngésző ablaka).
                if (!gomb.classList.contains('is-biztos')) {
                    gomb.classList.add('is-biztos');
                    gomb.title = 'Még egy kattintás: visszavonás';
                    gomb.setAttribute('aria-label', 'Biztosan visszavonod? Kattints még egyszer.');
                    window.setTimeout(function () { gomb.classList.remove('is-biztos'); }, 3500);

                    return;
                }

                kerdes('csapat_visszavon', { id: id }, true).then(function () {
                    var tmp = document.createElement('div');

                    tmp.innerHTML = uzenetHtml({ id: id, torolve: true, sajat: true }, null);
                    uz.replaceWith(tmp.firstChild);
                    listaBetolt();
                }).catch(function (h) { window.alert(h.message); });
            }
        });

        // Az „Új üzenet" popupból küldve: nincs oldalfrissítés, a beszélgetés megnyílik.
        document.addEventListener('sdh:mentve', function (ev) {
            if (ev.detail && ev.detail.action === 'sdh_muhely_csapat_kuld' && ev.detail.adat && ev.detail.adat.besz) {
                ev.preventDefault();
                listaBetolt().then(function () { megnyit({ besz: ev.detail.adat.besz }); });
            }
        });

        document.addEventListener('sdh:pulzus', function (ev) {
            var uj = (ev.detail && ev.detail.uj) || [];

            if (!uj.length) {
                return;
            }

            // A nyitott beszélgetés új üzenete: azonnal behúzzuk (ez olvasottnak is jelöli).
            if (uj.some(function (u) { return u.besz === s.aktiv; })) {
                window.clearTimeout(s.frissitIdo);
                frissit();
            } else {
                listaBetolt();
            }
        });

        // Indulás: a lista, és ha az URL-ben van beszélgetés, az is.
        listaBetolt().then(function () {
            var kert = parseInt(g.getAttribute('data-besz'), 10) || 0;

            if (kert) {
                megnyit({ besz: kert });
            } else if (window.innerWidth > 900 && s.lista.length) {
                megnyit({ besz: s.lista[0].id });
            }
        });

        // Mobilon a szálból vissza a listához.
        g.addEventListener('click', function (ev) {
            if (ev.target.closest('.sdh-cs-szal__fej') && window.innerWidth <= 900 && ev.target.closest('.sdh-cs-av, .sdh-cs-avcsoport')) {
                g.classList.remove('is-szal');
            }
        });

        return {
            get aktiv() { return s.aktiv; },
            megnyit: megnyit,
            listaBetolt: listaBetolt
        };
    }

    /* ================================================================ */
    /* Indulás                                                           */
    /* ================================================================ */

    window.SDH_CSAPAT = {
        pulzus: pulzus,
        /** Más modul (pl. az asszisztens) nyithat beszélgetést. */
        megnyit: function (par) {
            if (oldal) {
                return oldal.megnyit(par);
            }

            return null;
        }
    };

    window.setTimeout(pulzus, 800);
}());
