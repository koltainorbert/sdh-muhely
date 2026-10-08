/*
 * SDH Műhely – popup (modál) kezelés.
 *
 * Mindkét felületen ugyanez fut: az adminban és a saját műhely-felületen.
 * A natív <dialog> elemre épül, nem kell hozzá könyvtár.
 *
 * Működés:
 *   1. Bármelyik elem, amin van data-sdh-urlap="<modul>" és
 *      data-sdh-id="<azonosító vagy üres>", megnyitja a popupot.
 *   2. Az űrlap HTML-jét AJAX-szal kérjük le – így a lista oldala
 *      könnyű marad, és a szerkesztés mindig friss adatot mutat.
 *   3. A beküldés is AJAX-szal megy; siker után az oldal frissül,
 *      hogy a lista a változást mutassa.
 *
 * Ha a JavaScript nem fut, a linkek sima teljes oldalas űrlapra
 * visznek – a rendszer JS nélkül is használható marad.
 */

(function () {
    'use strict';

    var beallitas = window.SDH_MUHELY || {};
    /*
     * Két popup-szint van: a 0. a fő (munkalap, ügyfél, eszköz), az 1. erre
     * épül rá – például az új ügyfél vagy új eszköz felvitele a munkalap
     * űrlapjáról. A ráépülő szint bezárása után a fő popup érintetlen marad.
     */
    var szintek = [null, null];

    /* ---------------------------------------------------------------- */
    /* A popup váza – szintenként egyszer jön létre, utána újrahasznosul */
    /* ---------------------------------------------------------------- */

    function vaz(n) {
        if (szintek[n]) {
            return szintek[n];
        }

        var dialog = document.createElement('dialog');
        dialog.className = 'sdh-modal' + (n > 0 ? ' sdh-modal--ralepo' : '');
        dialog.innerHTML =
            '<div class="sdh-modal__doboz">' +
            '  <button type="button" class="sdh-modal__bezar" aria-label="Bezárás">&times;</button>' +
            '  <div class="sdh-modal__torzs"></div>' +
            '</div>';

        document.body.appendChild(dialog);

        var szint = {
            dialog: dialog,
            torzs: dialog.querySelector('.sdh-modal__torzs')
        };

        szintek[n] = szint;

        dialog.querySelector('.sdh-modal__bezar').addEventListener('click', function () {
            bezar(n);
        });

        // Kattintás a sötét háttérre: bezárás. A dobozon belüli kattintás nem.
        dialog.addEventListener('click', function (esemeny) {
            if (esemeny.target === dialog) {
                bezar(n);
            }
        });

        return szint;
    }

    function bezar(n) {
        var szint = szintek[n || 0];

        if (szint && szint.dialog.open) {
            szint.dialog.close();
        }
    }

    function toltesKozben(szint) {
        szint.torzs.innerHTML = '<div class="sdh-modal__toltes">Betöltés…</div>';
    }

    function hiba(szint, szoveg) {
        szint.torzs.innerHTML =
            '<div class="sdh-uzenet sdh-uzenet--hiba">' + szovegBiztonsagos(szoveg) + '</div>';
    }

    /**
     * A ráépülő popup űrlapjának azonosítói ütköznének a fő popupéval
     * (pl. mindkettőben van „nev”), és a címkék rossz mezőre mutatnának.
     * Ezért a ráépülő szint minden azonosítóját előtaggal látjuk el.
     */
    function idElotag(gyoker, elotag) {
        Array.prototype.forEach.call(gyoker.querySelectorAll('[id]'), function (elem) {
            elem.id = elotag + elem.id;
        });

        Array.prototype.forEach.call(gyoker.querySelectorAll('label[for]'), function (elem) {
            elem.setAttribute('for', elotag + elem.getAttribute('for'));
        });

        Array.prototype.forEach.call(gyoker.querySelectorAll('[list]'), function (elem) {
            elem.setAttribute('list', elotag + elem.getAttribute('list'));
        });
    }

    function szovegBiztonsagos(szoveg) {
        var elem = document.createElement('div');
        elem.textContent = String(szoveg);

        return elem.innerHTML;
    }

    /* ---------------------------------------------------------------- */
    /* Megnyitás                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Popup megnyitása.
     *
     * opciok (mind elhagyható):
     *   szint      – 0 (fő, alapértelmezett) vagy 1 (ráépülő);
     *   parameterek – extra lekérdezési paraméterek az űrlap kéréséhez
     *                 (pl. { ugyfel_id: 12 } az új eszköz előtöltéséhez);
     *   siker      – függvény: sikeres mentés után ezt hívjuk a szerver
     *                válaszával a lapfrissítés helyett, és bezárjuk a popupot.
     */
    function nyit(modul, id, opciok) {
        opciok = opciok || {};

        var n = opciok.szint || 0;
        var szint = vaz(n);

        toltesKozben(szint);

        if (!szint.dialog.open) {
            szint.dialog.showModal();
        }

        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_' + modul + '_urlap');
        cim.searchParams.set('id', id || '0');
        cim.searchParams.set('kontextus', beallitas.kontextus || 'admin');
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        Object.keys(opciok.parameterek || {}).forEach(function (nev) {
            cim.searchParams.set(nev, opciok.parameterek[nev]);
        });

        fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (valasz) {
                if (!valasz.ok) {
                    throw new Error('A szerver ' + valasz.status + ' hibakóddal válaszolt.');
                }

                return valasz.text();
            })
            .then(function (html) {
                szint.torzs.innerHTML = html;

                if (n > 0) {
                    idElotag(szint.torzs, 'sz' + n + '-');
                }

                bekotUrlap(szint, n, opciok);
                mintaKeres(szint.torzs);

                var elso = szint.torzs.querySelector('input:not([type="hidden"]), select, textarea');
                if (elso) {
                    elso.focus();
                }
            })
            .catch(function (ok) {
                hiba(szint, 'Az űrlap nem töltődött be. ' + ok.message);
            });
    }

    /* ---------------------------------------------------------------- */
    /* Beküldés                                                         */
    /* ---------------------------------------------------------------- */

    function bekotUrlap(szint, n, opciok) {
        var urlap = szint.torzs.querySelector('form');

        if (!urlap) {
            return;
        }

        var megsem = szint.torzs.querySelector('[data-sdh-megsem]');
        if (megsem) {
            megsem.addEventListener('click', function (esemeny) {
                esemeny.preventDefault();
                bezar(n);
            });
        }

        urlap.addEventListener('submit', function (esemeny) {
            esemeny.preventDefault();

            var gomb = urlap.querySelector('button[type="submit"]');
            var eredetiFelirat = gomb ? gomb.textContent : '';

            if (gomb) {
                gomb.disabled = true;
                gomb.textContent = 'Mentés…';
            }

            var adatok = new FormData(urlap);
            adatok.set('action', urlap.dataset.sdhAjaxAction);

            fetch(beallitas.ajax, {
                method: 'POST',
                body: adatok,
                credentials: 'same-origin'
            })
                .then(function (valasz) {
                    return valasz.json();
                })
                .then(function (eredmeny) {
                    if (eredmeny && eredmeny.success) {
                        // Ráépülő popupnál (pl. új ügyfél a munkalapról) nincs
                        // oldalfrissítés: a hívó megkapja az új rekordot, a fő
                        // popup és a benne félig kitöltött űrlap érintetlen marad.
                        if (typeof opciok.siker === 'function') {
                            bezar(n);
                            opciok.siker(eredmeny.data);

                            return;
                        }

                        // A lista így mutatja a változást, és az üzenet is
                        // megjelenik a megszokott helyen.
                        window.location.href = eredmeny.data.vissza;

                        return;
                    }

                    if (gomb) {
                        gomb.disabled = false;
                        gomb.textContent = eredetiFelirat;
                    }

                    mutatUrlapHiba(
                        urlap,
                        (eredmeny && eredmeny.data && eredmeny.data.uzenet) || 'A mentés nem sikerült.'
                    );
                })
                .catch(function (ok) {
                    if (gomb) {
                        gomb.disabled = false;
                        gomb.textContent = eredetiFelirat;
                    }

                    mutatUrlapHiba(urlap, 'A mentés nem sikerült. ' + ok.message);
                });
        });
    }

    function mutatUrlapHiba(urlap, szoveg) {
        var regi = urlap.querySelector('.sdh-modal__hiba');

        if (regi) {
            regi.remove();
        }

        var doboz = document.createElement('div');
        doboz.className = 'sdh-uzenet sdh-uzenet--hiba sdh-modal__hiba';
        doboz.textContent = szoveg;

        urlap.insertBefore(doboz, urlap.firstChild);
        doboz.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    /* ---------------------------------------------------------------- */
    /* Választómező (gépelésre kereső)                                  */
    /* ---------------------------------------------------------------- */

    var keresesIdozito = null;

    function valasztoLista(doboz) {
        return doboz.querySelector('.sdh-valaszto__lista');
    }

    function valasztoTorol(doboz) {
        var lista = valasztoLista(doboz);
        lista.innerHTML = '';
        lista.hidden = true;
    }

    function valasztoKeres(doboz, q) {
        var modul = doboz.dataset.sdhValaszto;
        var lista = valasztoLista(doboz);

        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_' + modul + '_kereso');
        cim.searchParams.set('q', q);
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (valasz) {
                return valasz.json();
            })
            .then(function (eredmeny) {
                var talalatok = (eredmeny && eredmeny.success && eredmeny.data) || [];

                lista.innerHTML = '';

                if (!talalatok.length) {
                    lista.innerHTML = '<li class="sdh-valaszto__ures">Nincs találat.</li>';
                    lista.hidden = false;

                    return;
                }

                talalatok.forEach(function (talalat) {
                    var elem = document.createElement('li');
                    elem.className = 'sdh-valaszto__elem';
                    elem.dataset.id = talalat.id;
                    elem.dataset.nev = talalat.nev;
                    elem.innerHTML =
                        '<strong>' + szovegBiztonsagos(talalat.nev) + '</strong>' +
                        (talalat.reszlet
                            ? '<span>' + szovegBiztonsagos(talalat.reszlet) + '</span>'
                            : '');

                    lista.appendChild(elem);
                });

                lista.hidden = false;
            })
            .catch(function () {
                valasztoTorol(doboz);
            });
    }

    document.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target.closest('.sdh-valaszto__mezo');

        if (!mezo) {
            return;
        }

        var doboz = mezo.closest('.sdh-valaszto');
        var rejtett = doboz.querySelector('input[type="hidden"]');

        // Amíg nem választott a listából, nincs érvényes azonosító.
        // Így nem fordulhat elő, hogy átírja a nevet, és közben a régi
        // ügyfélhez menti az eszközt.
        rejtett.value = '0';
        munkalapEszkozFrissit(doboz, '0');

        window.clearTimeout(keresesIdozito);

        var q = mezo.value.trim();

        if (q.length < 2) {
            valasztoTorol(doboz);

            return;
        }

        keresesIdozito = window.setTimeout(function () {
            valasztoKeres(doboz, q);
        }, 250);
    });

    document.addEventListener('keydown', function (esemeny) {
        var mezo = esemeny.target.closest('.sdh-valaszto__mezo');

        if (!mezo) {
            return;
        }

        var doboz = mezo.closest('.sdh-valaszto');
        var elso = doboz.querySelector('.sdh-valaszto__elem');

        if (esemeny.key === 'Escape') {
            valasztoTorol(doboz);

            return;
        }

        // Enterre az első találat – a pultnál ez a leggyorsabb.
        if (esemeny.key === 'Enter' && elso) {
            esemeny.preventDefault();
            valasztoValaszt(doboz, elso);
        }
    });

    function valasztoValaszt(doboz, elem) {
        doboz.querySelector('input[type="hidden"]').value = elem.dataset.id;
        doboz.querySelector('.sdh-valaszto__mezo').value = elem.dataset.nev;
        valasztoTorol(doboz);
        munkalapEszkozFrissit(doboz, elem.dataset.id);
    }

    /* ---------------------------------------------------------------- */
    /* IMEI – kikeresés és kitöltés                                     */
    /* ---------------------------------------------------------------- */

    var imeiIdozito = null;

    /**
     * IMEI ellenőrzőszám (Luhn). Elgépelést jelez, nem tilt.
     */
    function imeiErvenyes(imei) {
        if (!/^\d{15}$/.test(imei)) {
            return false;
        }

        var osszeg = 0;

        for (var i = 0; i < 15; i++) {
            var szamjegy = parseInt(imei[i], 10);

            if ((14 - i) % 2 === 1) {
                szamjegy *= 2;

                if (szamjegy > 9) {
                    szamjegy -= 9;
                }
            }

            osszeg += szamjegy;
        }

        return osszeg % 10 === 0;
    }

    function jelzesTorol(urlap) {
        var regi = urlap.querySelector('.sdh-urlap__jelzes');

        if (regi) {
            regi.remove();
        }
    }

    function jelzes(urlap, tipus, szoveg) {
        jelzesTorol(urlap);

        var doboz = document.createElement('div');
        doboz.className = 'sdh-uzenet sdh-uzenet--' + tipus + ' sdh-urlap__jelzes';
        doboz.textContent = szoveg;

        urlap.insertBefore(doboz, urlap.firstChild);
    }

    /**
     * A megtalált készülék adatait beírja az űrlapba.
     */
    function urlapKitolt(urlap, eredmeny) {
        Object.keys(eredmeny.mezok).forEach(function (nev) {
            var mezo = urlap.querySelector('[name="' + nev + '"]');

            if (!mezo) {
                return;
            }

            if (mezo.type === 'checkbox') {
                mezo.checked = !!eredmeny.mezok[nev];

                return;
            }

            mezo.value = eredmeny.mezok[nev];
        });

        // Ügyfélválasztó: a rejtett azonosító és a látható név együtt.
        var valaszto = urlap.querySelector('.sdh-valaszto');

        if (valaszto && eredmeny.ugyfel && eredmeny.ugyfel.id) {
            valaszto.querySelector('input[type="hidden"]').value = eredmeny.ugyfel.id;
            valaszto.querySelector('.sdh-valaszto__mezo').value = eredmeny.ugyfel.nev;
        }

        // Innentől ezt a rekordot szerkesztjük – nem viszünk fel másodszor
        // ugyanazt a készüléket.
        var azonosito = urlap.querySelector('input[name="id"]');

        if (azonosito) {
            azonosito.value = eredmeny.id;
        }

        var gomb = urlap.querySelector('button[type="submit"]');

        if (gomb) {
            gomb.textContent = 'Mentés';
        }
    }

    function imeiKereses(urlap, imei) {
        var azonosito = urlap.querySelector('input[name="id"]');

        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_eszkozok_imei');
        cim.searchParams.set('imei', imei);
        cim.searchParams.set('kizar', azonosito ? azonosito.value : '0');
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (valasz) {
                return valasz.json();
            })
            .then(function (valasz) {
                var eredmeny = valasz && valasz.success ? valasz.data : null;

                if (!eredmeny) {
                    return;
                }

                if (!eredmeny.talalat) {
                    // Nem járt még nálunk – a TAC-adatbázis legalább a
                    // gyártót és a típust megmondja.
                    if (eredmeny.tac) {
                        var gyarto = urlap.querySelector('[name="gyarto"]');
                        var tipus = urlap.querySelector('[name="tipus"]');
                        var megnev = urlap.querySelector('[name="megnevezes"]');

                        if (gyarto && !gyarto.value) {
                            gyarto.value = eredmeny.tac.gyarto;
                        }

                        if (tipus && !tipus.value) {
                            tipus.value = eredmeny.tac.modell;
                        }

                        if (megnev && !megnev.value) {
                            megnev.value = eredmeny.tac.megnevezes;
                        }

                        var cimke = (eredmeny.tac.gyarto + ' ' + eredmeny.tac.modell).trim();

                        if (eredmeny.tac.megnevezes) {
                            cimke += ' (' + eredmeny.tac.megnevezes + ')';
                        }

                        jelzes(
                            urlap,
                            eredmeny.tac.modell ? 'siker' : 'figyelem',
                            eredmeny.tac.modell
                                ? 'Új készülék: ' + cimke + ' – az IMEI alapján. ' +
                                  'Az ügyfelet és a többi adatot töltsd ki.'
                                : 'Az IMEI alapján ' + eredmeny.tac.gyarto +
                                  ', de a gyári szám nincs az adatbázisban – írd be kézzel.'
                        );

                        return;
                    }

                    if (eredmeny.ervenyes === false) {
                        jelzes(
                            urlap,
                            'figyelem',
                            'Ez a 15 számjegy nem ad ki érvényes IMEI-t – nézd meg, nem gépelted-e el. ' +
                                'Menteni így is tudod.'
                        );
                    } else {
                        jelzes(urlap, 'siker', 'Ez a készülék még nem járt nálunk – új rekord lesz.');
                    }

                    return;
                }

                urlapKitolt(urlap, eredmeny);
                kepFrissit(urlap);

                jelzes(
                    urlap,
                    'siker',
                    'Ez a készülék már szerepel: ' + eredmeny.megnevez +
                        (eredmeny.ugyfel && eredmeny.ugyfel.nev ? ' – ' + eredmeny.ugyfel.nev : '') +
                        (eredmeny.utoljara ? ' (utoljára ' + eredmeny.utoljara + ')' : '') +
                        '. Az adatait betöltöttem, a mentés ezt a rekordot frissíti.'
                );
            })
            .catch(function () {
                // A kikeresés kényelmi funkció: ha nem megy, a kézi
                // kitöltés attól még működik.
            });
    }

    document.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target.closest('[data-sdh-imei]');

        if (!mezo) {
            return;
        }

        // Az IMEI csak számjegy – a vonalkódolvasók néha szóközt is küldenek.
        var tiszta = mezo.value.replace(/\D/g, '');

        if (tiszta !== mezo.value) {
            mezo.value = tiszta;
        }

        var urlap = mezo.closest('form');

        if (!urlap) {
            return;
        }

        window.clearTimeout(imeiIdozito);

        if (tiszta.length !== 15) {
            jelzesTorol(urlap);

            return;
        }

        if (!imeiErvenyes(tiszta)) {
            jelzes(
                urlap,
                'figyelem',
                'Ez a 15 számjegy nem ad ki érvényes IMEI-t – nézd meg, nem gépelted-e el.'
            );
        }

        imeiIdozito = window.setTimeout(function () {
            imeiKereses(urlap, tiszta);
        }, 150);
    });

    /* ---------------------------------------------------------------- */
    /* Feloldó minta – 3×3 rács, egérrel behúzható                      */
    /* ---------------------------------------------------------------- */

    var MINTA_NS = 'http://www.w3.org/2000/svg';

    /** Egy pötty középpontja a 240×240-es rajzvásznon. */
    function mintaPont(index) {
        var sor = Math.floor((index - 1) / 3);
        var oszlop = (index - 1) % 3;

        return { x: 40 + oszlop * 80, y: 40 + sor * 80 };
    }

    /**
     * Két pötty között átlépett harmadik pötty.
     *
     * Az Android ugyanígy működik: ha 1-ből 3-ba húzol, a 2 is bekerül,
     * akár akarod, akár nem. Enélkül a felvett minta nem az lenne, amit
     * az ügyfél valójában rajzol.
     */
    function mintaKozbenso(a, b) {
        var sa = Math.floor((a - 1) / 3), oa = (a - 1) % 3;
        var sb = Math.floor((b - 1) / 3), ob = (b - 1) % 3;

        if ((sa + sb) % 2 !== 0 || (oa + ob) % 2 !== 0) {
            return 0;
        }

        var sk = (sa + sb) / 2;
        var ok = (oa + ob) / 2;
        var kozep = sk * 3 + ok + 1;

        return kozep === a || kozep === b ? 0 : kozep;
    }

    function mintaElem(nev, tulajdonsagok) {
        var elem = document.createElementNS(MINTA_NS, nev);

        Object.keys(tulajdonsagok).forEach(function (kulcs) {
            elem.setAttribute(kulcs, tulajdonsagok[kulcs]);
        });

        return elem;
    }

    /** Egy CSS-változó aktuális értéke – így a minta is követi a témát. */
    function szinValtozo(nev, tartalek) {
        var ertek = getComputedStyle(document.documentElement).getPropertyValue(nev).trim();

        return ertek || tartalek;
    }

    function mintaRajzol(doboz, sorrend, elonezetPont) {
        var svg = doboz.querySelector('.sdh-minta__rajz');
        var piros = szinValtozo('--sdh-accent', '#d4231d');
        var halvanyPotty = szinValtozo('--sdh-keret-eros', '#c3c7cc');

        svg.textContent = '';

        // Vonalak a már bejárt pöttyök között
        for (var i = 0; i < sorrend.length - 1; i++) {
            var p1 = mintaPont(sorrend[i]);
            var p2 = mintaPont(sorrend[i + 1]);

            svg.appendChild(mintaElem('line', {
                x1: p1.x, y1: p1.y, x2: p2.x, y2: p2.y,
                stroke: piros, 'stroke-width': 4, 'stroke-linecap': 'round'
            }));

            // Nyílhegy a szakasz közepén – ez mutatja az irányt
            var kx = (p1.x + p2.x) / 2;
            var ky = (p1.y + p2.y) / 2;
            var szog = Math.atan2(p2.y - p1.y, p2.x - p1.x) * 180 / Math.PI;

            svg.appendChild(mintaElem('path', {
                d: 'M -9 -7 L 9 0 L -9 7 Z',
                fill: piros,
                transform: 'translate(' + kx + ',' + ky + ') rotate(' + szog + ')'
            }));
        }

        // A húzás közbeni szabad szakasz az egérig
        if (elonezetPont && sorrend.length) {
            var utolso = mintaPont(sorrend[sorrend.length - 1]);

            svg.appendChild(mintaElem('line', {
                x1: utolso.x, y1: utolso.y, x2: elonezetPont.x, y2: elonezetPont.y,
                stroke: piros, 'stroke-width': 3, 'stroke-linecap': 'round',
                'stroke-dasharray': '6 6', opacity: '.6'
            }));
        }

        // A kilenc pötty
        for (var n = 1; n <= 9; n++) {
            var p = mintaPont(n);
            var helye = sorrend.indexOf(n);

            svg.appendChild(mintaElem('circle', {
                cx: p.x, cy: p.y, r: helye === -1 ? 7 : 11,
                fill: helye === -1 ? halvanyPotty : piros
            }));

            // A kezdőpont kap egy gyűrűt, hogy ránézésre látszódjon
            if (helye === 0) {
                svg.appendChild(mintaElem('circle', {
                    cx: p.x, cy: p.y, r: 18,
                    fill: 'none', stroke: piros, 'stroke-width': 2, opacity: '.5'
                }));
            }
        }

        var sorElem = doboz.querySelector('.sdh-minta__sor');

        if (sorElem) {
            sorElem.textContent = sorrend.length
                ? sorrend.join(' → ')
                : 'Nincs minta felvéve.';
        }
    }

    function mintaErtek(doboz) {
        var rejtett = doboz.querySelector('input[type="hidden"]');
        var nyers = (rejtett.value || '').replace(/[^1-9]/g, '');
        var sorrend = [];

        nyers.split('').forEach(function (sz) {
            var n = parseInt(sz, 10);

            if (sorrend.indexOf(n) === -1) {
                sorrend.push(n);
            }
        });

        return sorrend;
    }

    function mintaMent(doboz, sorrend) {
        doboz.querySelector('input[type="hidden"]').value = sorrend.join('');
    }

    /** Képernyő-koordinátából rajzvászon-koordináta. */
    function mintaVasznon(svg, esemeny) {
        var teglalap = svg.getBoundingClientRect();

        return {
            x: (esemeny.clientX - teglalap.left) / teglalap.width * 240,
            y: (esemeny.clientY - teglalap.top) / teglalap.height * 240
        };
    }

    function mintaTalalat(pont) {
        for (var n = 1; n <= 9; n++) {
            var p = mintaPont(n);
            var tav = Math.hypot(p.x - pont.x, p.y - pont.y);

            if (tav <= 30) {
                return n;
            }
        }

        return 0;
    }

    function mintaBekot(doboz) {
        if (doboz.dataset.sdhMintaKesz) {
            return;
        }

        doboz.dataset.sdhMintaKesz = '1';

        var svg = doboz.querySelector('.sdh-minta__rajz');
        var sorrend = mintaErtek(doboz);
        var huzas = false;

        mintaRajzol(doboz, sorrend, null);

        svg.addEventListener('pointerdown', function (esemeny) {
            esemeny.preventDefault();
            huzas = true;
            sorrend = [];

            var elso = mintaTalalat(mintaVasznon(svg, esemeny));

            if (elso) {
                sorrend.push(elso);
            }

            svg.setPointerCapture(esemeny.pointerId);
            mintaRajzol(doboz, sorrend, mintaVasznon(svg, esemeny));
        });

        svg.addEventListener('pointermove', function (esemeny) {
            if (!huzas) {
                return;
            }

            var pont = mintaVasznon(svg, esemeny);
            var talalat = mintaTalalat(pont);

            if (talalat && sorrend.indexOf(talalat) === -1) {
                if (sorrend.length) {
                    var kozbenso = mintaKozbenso(sorrend[sorrend.length - 1], talalat);

                    if (kozbenso && sorrend.indexOf(kozbenso) === -1) {
                        sorrend.push(kozbenso);
                    }
                }

                sorrend.push(talalat);
            }

            mintaRajzol(doboz, sorrend, pont);
        });

        function lezar(esemeny) {
            if (!huzas) {
                return;
            }

            huzas = false;

            // Egyetlen pötty nem minta – azt eldobjuk.
            if (sorrend.length < 2) {
                sorrend = [];
            }

            mintaMent(doboz, sorrend);
            mintaRajzol(doboz, sorrend, null);

            if (esemeny && esemeny.pointerId !== undefined && svg.hasPointerCapture(esemeny.pointerId)) {
                svg.releasePointerCapture(esemeny.pointerId);
            }
        }

        svg.addEventListener('pointerup', lezar);
        svg.addEventListener('pointercancel', lezar);
        svg.addEventListener('pointerleave', lezar);

        var torol = doboz.querySelector('[data-sdh-minta-torol]');

        if (torol) {
            torol.addEventListener('click', function (esemeny) {
                esemeny.preventDefault();
                sorrend = [];
                mintaMent(doboz, sorrend);
                mintaRajzol(doboz, sorrend, null);
            });
        }
    }

    /**
     * Az űrlap a popupba AJAX-szal érkezik, ezért a rajzolót akkor
     * kötjük be, amikor megjelenik.
     */
    function mintaKeres(gyoker) {
        Array.prototype.forEach.call(
            (gyoker || document).querySelectorAll('[data-sdh-minta]'),
            mintaBekot
        );
    }

    document.addEventListener('DOMContentLoaded', function () {
        mintaKeres(document);
        megjelenesIndul();
    });

    if (document.readyState !== 'loading') {
        mintaKeres(document);
        megjelenesIndul();
    }

    /* ---------------------------------------------------------------- */
    /* Gyári adatok lekérdezése a szolgáltatótól                        */
    /* ---------------------------------------------------------------- */

    /**
     * Beírja a kapott mezőket, és frissíti a képet.
     *
     * A már kitöltött mezőket nem írja felül: amit a kollégád kézzel
     * beírt, az erősebb, mint amit a szolgáltató gondol.
     */
    function mezoketKitolt(urlap, mezok, felulir) {
        var beirt = 0;

        Object.keys(mezok).forEach(function (nev) {
            var mezo = urlap.querySelector('[name="' + nev + '"]');

            if (!mezo || !mezok[nev]) {
                return;
            }

            if (mezo.value && !felulir) {
                return;
            }

            mezo.value = mezok[nev];
            beirt++;
        });

        kepFrissit(urlap);

        return beirt;
    }

    function kepFrissit(urlap) {
        var mezo = urlap.querySelector('[data-sdh-kep-mezo]');
        var doboz = urlap.querySelector('[data-sdh-kep]');

        if (!mezo || !doboz) {
            return;
        }

        doboz.innerHTML = '';

        if (!mezo.value) {
            return;
        }

        var kep = document.createElement('img');
        kep.src = mezo.value;
        kep.alt = '';
        doboz.appendChild(kep);
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest('[data-sdh-imei-lekerdez]');

        if (!gomb) {
            return;
        }

        esemeny.preventDefault();

        var urlap = gomb.closest('form');
        var imeiMezo = urlap.querySelector('[data-sdh-imei]');
        var imei = imeiMezo ? imeiMezo.value.replace(/\D/g, '') : '';

        if (imei.length !== 15) {
            jelzes(urlap, 'figyelem', 'Előbb írd be a teljes, 15 számjegyű IMEI-t.');

            return;
        }

        var eredeti = gomb.textContent;
        gomb.disabled = true;
        gomb.textContent = 'Lekérdezés…';

        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_imei_lekerdez');
        cim.searchParams.set('imei', imei);
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (valasz) {
                gomb.disabled = false;
                gomb.textContent = eredeti;

                if (!valasz || !valasz.success) {
                    jelzes(
                        urlap,
                        'hiba',
                        (valasz && valasz.data && valasz.data.uzenet) ||
                            'A lekérdezés nem sikerült.'
                    );

                    return;
                }

                // A szolgáltató adata hitelesebb, mint a korábbi
                // becslésünk, ezért itt felülírunk.
                var beirt = mezoketKitolt(urlap, valasz.data.mezok, true);

                var jelzo = urlap.querySelector('input[name="lekerdezve"]');

                if (!jelzo) {
                    jelzo = document.createElement('input');
                    jelzo.type = 'hidden';
                    jelzo.name = 'lekerdezve';
                    urlap.appendChild(jelzo);
                }

                jelzo.value = '1';

                jelzes(
                    urlap,
                    beirt ? 'siker' : 'figyelem',
                    beirt
                        ? 'Gyári adatok betöltve – ' + beirt + ' mező frissült.'
                        : 'A szolgáltató válaszolt, de nem adott használható mezőt.'
                );
            })
            .catch(function (ok) {
                gomb.disabled = false;
                gomb.textContent = eredeti;
                jelzes(urlap, 'hiba', 'A lekérdezés nem sikerült. ' + ok.message);
            });
    });

    document.addEventListener('input', function (esemeny) {
        if (esemeny.target.closest('[data-sdh-kep-mezo]')) {
            kepFrissit(esemeny.target.closest('form'));
        }
    });

    /* ---------------------------------------------------------------- */
    /* Beillesztett eredmény feldolgozása                               */
    /* ---------------------------------------------------------------- */

    function beillesztesFeldolgoz(urlap, szoveg, csendben) {
        if (!szoveg.trim()) {
            if (!csendben) {
                jelzes(urlap, 'figyelem', 'Előbb illeszd be az eredményt a mezőbe.');
            }

            return;
        }

        var adatok = new FormData();
        adatok.set('action', 'sdh_muhely_imei_beillesztes');
        adatok.set('szoveg', szoveg);
        adatok.set('_wpnonce', beallitas.nonce || '');

        fetch(beallitas.ajax, {
            method: 'POST',
            body: adatok,
            credentials: 'same-origin'
        })
            .then(function (v) { return v.json(); })
            .then(function (valasz) {
                if (!valasz || !valasz.success) {
                    jelzes(
                        urlap,
                        'hiba',
                        (valasz && valasz.data && valasz.data.uzenet) ||
                            'Ebből nem tudtam adatot kiolvasni.'
                    );

                    return;
                }

                // A beillesztett adat a gyártótól jön, tehát erősebb a
                // korábbi becslésünknél – itt felülírunk.
                var beirt = mezoketKitolt(urlap, valasz.data.mezok, true);

                jelzes(
                    urlap,
                    beirt ? 'siker' : 'figyelem',
                    beirt
                        ? 'Kész – ' + beirt + ' mező kitöltve a beillesztett adatból.'
                        : 'Nem találtam benne használható mezőt.'
                );
            })
            .catch(function (ok) {
                jelzes(urlap, 'hiba', 'A feldolgozás nem sikerült. ' + ok.message);
            });
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest('[data-sdh-beillesztes-feldolgoz]');

        if (!gomb) {
            return;
        }

        esemeny.preventDefault();

        var urlap = gomb.closest('form');
        var mezo = urlap.querySelector('[data-sdh-beillesztes]');

        beillesztesFeldolgoz(urlap, mezo ? mezo.value : '', false);
    });

    // Beillesztéskor rögtön feldolgozzuk – ne kelljen gombot keresni.
    document.addEventListener('paste', function (esemeny) {
        var mezo = esemeny.target.closest('[data-sdh-beillesztes]');

        if (!mezo) {
            return;
        }

        var szoveg = (esemeny.clipboardData || window.clipboardData).getData('text');

        if (!szoveg) {
            return;
        }

        window.setTimeout(function () {
            beillesztesFeldolgoz(mezo.closest('form'), szoveg, true);
        }, 0);
    });

    /* ---------------------------------------------------------------- */
    /* Ellenőrző oldal megnyitása                                       */
    /* ---------------------------------------------------------------- */

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest('[data-sdh-ellenorzo]');

        if (!gomb) {
            return;
        }

        esemeny.preventDefault();

        var urlap = gomb.closest('form');
        var imeiMezo = urlap ? urlap.querySelector('[data-sdh-imei]') : null;
        var imei = imeiMezo ? imeiMezo.value.replace(/\D/g, '') : '';
        var cim = gomb.dataset.sdhEllenorzo;

        if (imei.length !== 15) {
            jelzes(urlap, 'figyelem', 'Előbb írd be a teljes, 15 számjegyű IMEI-t.');

            return;
        }

        // Ha az oldal URL-ben is fogadja az IMEI-t, beírva nyitjuk meg.
        if (cim.indexOf('{imei}') !== -1) {
            window.open(cim.replace('{imei}', encodeURIComponent(imei)), '_blank', 'noopener');
            jelzes(urlap, 'siker', 'Megnyitottam az ellenőrzőt. Az eredményt másold ide vissza.');

            return;
        }

        // Különben a vágólapra tesszük, hogy ott csak be kelljen illeszteni.
        var nyit = function (uzenet, tipus) {
            window.open(cim, '_blank', 'noopener');
            jelzes(urlap, tipus, uzenet);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(imei)
                .then(function () {
                    nyit(
                        'Az IMEI a vágólapon – illeszd be az oldalon, majd az eredményt ' +
                            'másold vissza a fenti mezőbe.',
                        'siker'
                    );
                })
                .catch(function () {
                    nyit('Megnyitottam az ellenőrzőt. Az IMEI: ' + imei, 'siker');
                });

            return;
        }

        nyit('Megnyitottam az ellenőrzőt. Az IMEI: ' + imei, 'siker');
    });

    /* ---------------------------------------------------------------- */
    /* Megjelenés: világos / sötét / rendszer, kiemelő szín, menüsáv     */
    /* ---------------------------------------------------------------- */

    function temaAlkalmaz(ertek) {
        var sotet = ertek === 'sotet' ||
            (ertek === 'rendszer' &&
             window.matchMedia('(prefers-color-scheme: dark)').matches);

        document.documentElement.setAttribute('data-theme', sotet ? 'dark' : 'light');

        Array.prototype.forEach.call(
            document.querySelectorAll('[data-sdh-tema] button'),
            function (gomb) {
                gomb.setAttribute('aria-pressed', gomb.dataset.ertek === ertek ? 'true' : 'false');
            }
        );
    }

    function szinAlkalmaz(ertek) {
        if (ertek) {
            document.documentElement.setAttribute('data-accent', ertek);
        } else {
            document.documentElement.removeAttribute('data-accent');
        }

        Array.prototype.forEach.call(
            document.querySelectorAll('[data-sdh-szin] button'),
            function (gomb) {
                gomb.setAttribute('aria-pressed', gomb.dataset.ertek === ertek ? 'true' : 'false');
            }
        );
    }

    function beallitasOlvas(kulcs, alap) {
        try {
            return localStorage.getItem(kulcs) || alap;
        } catch (e) {
            return alap;
        }
    }

    function beallitasIr(kulcs, ertek) {
        try {
            localStorage.setItem(kulcs, ertek);
        } catch (e) {
            // Privát ablakban nem baj, csak nem jegyzi meg.
        }
    }

    function megjelenesIndul() {
        // Előbb amit a dolgozó állított ezen a gépen, aztán a cég
        // alapértelmezése az Arculat képernyőről.
        temaAlkalmaz(beallitasOlvas('sdh-tema', beallitas.alapTema || 'rendszer'));

        var szin = null;

        try {
            szin = localStorage.getItem('sdh-szin');
        } catch (e) {
            szin = null;
        }

        szinAlkalmaz(szin === null ? (beallitas.alapSzin || '') : szin);

        if (document.body && beallitasOlvas('sdh-sav', '') === 'csukva') {
            document.body.dataset.sav = 'csukva';
        }
    }

    // A rendszer módot követjük, ha közben vált a gép.
    if (window.matchMedia) {
        if (window.matchMedia('(prefers-color-scheme: dark)').addEventListener) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
                if (beallitasOlvas('sdh-tema', beallitas.alapTema || 'rendszer') === 'rendszer') {
                    temaAlkalmaz('rendszer');
                }
            });
        }
    }

    document.addEventListener('click', function (esemeny) {
        var tema = esemeny.target.closest('[data-sdh-tema] button');

        if (tema) {
            beallitasIr('sdh-tema', tema.dataset.ertek);
            temaAlkalmaz(tema.dataset.ertek);

            return;
        }

        var szin = esemeny.target.closest('[data-sdh-szin] button');

        if (szin) {
            beallitasIr('sdh-szin', szin.dataset.ertek);
            szinAlkalmaz(szin.dataset.ertek);

            return;
        }

        var savGomb = esemeny.target.closest('[data-sdh-sav]');

        if (savGomb) {
            var csukva = document.body.dataset.sav === 'csukva';

            if (csukva) {
                delete document.body.dataset.sav;
            } else {
                document.body.dataset.sav = 'csukva';
            }

            beallitasIr('sdh-sav', csukva ? '' : 'csukva');

            return;
        }

        // Profilmenü nyitás/zárás
        var profil = document.querySelector('[data-sdh-profil]');

        if (!profil) {
            return;
        }

        var panel = profil.querySelector('.sdh-profil__panel');
        var gomb = profil.querySelector('.sdh-profil__gomb');

        if (esemeny.target.closest('.sdh-profil__gomb')) {
            panel.hidden = !panel.hidden;
            gomb.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');

            return;
        }

        if (!panel.hidden && !esemeny.target.closest('.sdh-profil__panel')) {
            panel.hidden = true;
            gomb.setAttribute('aria-expanded', 'false');
        }
    });

    /* ---------------------------------------------------------------- */
    /* Globális kereső                                                  */
    /* ---------------------------------------------------------------- */

    var kutatIdozito = null;

    function kutatRajzol(doboz, csoportok) {
        var lista = doboz.querySelector('.sdh-kutat__lista');

        lista.innerHTML = '';

        var van = csoportok.some(function (cs) {
            return cs.talalatok.length;
        });

        if (!van) {
            lista.innerHTML = '<li class="sdh-kutat__ures">Nincs találat.</li>';
            lista.hidden = false;

            return;
        }

        csoportok.forEach(function (csoport) {
            if (!csoport.talalatok.length) {
                return;
            }

            var fej = document.createElement('li');
            fej.className = 'sdh-kutat__csoport';
            fej.textContent = csoport.cim;
            lista.appendChild(fej);

            csoport.talalatok.forEach(function (talalat) {
                var elem = document.createElement('li');
                var link = document.createElement('a');

                link.className = 'sdh-kutat__elem';
                link.href = talalat.url;
                link.innerHTML = szovegBiztonsagos(talalat.cim) +
                    (talalat.reszlet ? '<span>' + szovegBiztonsagos(talalat.reszlet) + '</span>' : '');

                elem.appendChild(link);
                lista.appendChild(elem);
            });
        });

        lista.hidden = false;
    }

    function kutat(doboz, q) {
        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_globalis_kereso');
        cim.searchParams.set('q', q);
        cim.searchParams.set('kontextus', beallitas.kontextus || 'frontend');
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (valasz) {
                if (valasz && valasz.success) {
                    kutatRajzol(doboz, valasz.data);
                }
            })
            .catch(function () {});
    }

    document.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target.closest('.sdh-kutat__mezo');

        if (!mezo) {
            return;
        }

        var doboz = mezo.closest('[data-sdh-kutat]');
        var lista = doboz.querySelector('.sdh-kutat__lista');

        window.clearTimeout(kutatIdozito);

        var q = mezo.value.trim();

        if (q.length < 2) {
            lista.hidden = true;
            lista.innerHTML = '';

            return;
        }

        kutatIdozito = window.setTimeout(function () {
            kutat(doboz, q);
        }, 220);
    });

    // A „/" billentyű a keresőre ugrik – ez a megszokott gyorsbillentyű.
    document.addEventListener('keydown', function (esemeny) {
        if (esemeny.key === 'Escape') {
            Array.prototype.forEach.call(
                document.querySelectorAll('.sdh-kutat__lista'),
                function (l) { l.hidden = true; }
            );
        }

        if (esemeny.key !== '/' || esemeny.ctrlKey || esemeny.metaKey) {
            return;
        }

        var aktiv = document.activeElement;

        if (aktiv && /^(INPUT|TEXTAREA|SELECT)$/.test(aktiv.tagName)) {
            return;
        }

        var mezo = document.querySelector('.sdh-kutat__mezo');

        if (mezo) {
            esemeny.preventDefault();
            mezo.focus();
        }
    });

    document.addEventListener('click', function (esemeny) {
        if (!esemeny.target.closest('[data-sdh-kutat]')) {
            Array.prototype.forEach.call(
                document.querySelectorAll('.sdh-kutat__lista'),
                function (l) { l.hidden = true; }
            );
        }
    });

    /* ---------------------------------------------------------------- */
    /* Munkalap – eszközválasztó, hibasorok, állapotpötty               */
    /* ---------------------------------------------------------------- */

    /**
     * A munkalap-űrlapon az eszközlista az ügyfélhez igazodik: ügyfélváltáskor
     * újratöltjük, hogy ne lehessen másik ügyfél készülékét kiválasztani.
     * Más űrlapon (nincs eszközválasztó) nem csinál semmit.
     */
    function munkalapEszkozFrissit(doboz, ugyfelId, kivalasztando) {
        var urlap = doboz.closest('form');
        var valaszto = urlap ? urlap.querySelector('[data-sdh-eszkoz-valaszto]') : null;

        if (!valaszto) {
            return;
        }

        var eloszor = function (felirat) {
            valaszto.innerHTML = '';
            var elem = document.createElement('option');
            elem.value = '0';
            elem.textContent = felirat;
            valaszto.appendChild(elem);
        };

        if (!ugyfelId || ugyfelId === '0') {
            eloszor('— előbb válassz ügyfelet —');

            return;
        }

        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_munkalapok_eszkozok');
        cim.searchParams.set('ugyfel_id', ugyfelId);
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (valasz) {
                return valasz.json();
            })
            .then(function (eredmeny) {
                var eszkozok = (eredmeny && eredmeny.success && eredmeny.data) || [];

                if (!eszkozok.length) {
                    eloszor('Ennek az ügyfélnek még nincs eszköze – a + gombbal vihetsz fel újat');

                    return;
                }

                eloszor('— válassz eszközt —');

                eszkozok.forEach(function (eszkoz) {
                    var elem = document.createElement('option');
                    elem.value = String(eszkoz.id);
                    elem.textContent = eszkoz.nev;
                    valaszto.appendChild(elem);
                });

                // Egyetlen készüléknél nincs mit választani.
                if (eszkozok.length === 1) {
                    valaszto.value = String(eszkozok[0].id);
                }

                // Frissen felvitt eszköznél az új készülék legyen kiválasztva.
                if (kivalasztando) {
                    valaszto.value = String(kivalasztando);
                }
            })
            .catch(function () {
                eloszor('Az eszközök nem töltődtek be');
            });
    }

    /**
     * A munkalap ügyfélmezőjét beállítja (rejtett azonosító + látható név).
     * Visszaadja a választó dobozát, vagy null-t, ha az űrlapon nincs ilyen.
     */
    function munkalapUgyfelBeallit(urlap, id, nev) {
        var valaszto = urlap ? urlap.querySelector('.sdh-valaszto') : null;

        if (!valaszto) {
            return null;
        }

        valaszto.querySelector('input[type="hidden"]').value = String(id);
        valaszto.querySelector('.sdh-valaszto__mezo').value = nev || '';
        valasztoTorol(valaszto);

        return valaszto;
    }

    /**
     * „+” az ügyfélmező mellett: az új ügyfél popupban nyílik a munkalap
     * fölött. Mentés után az űrlap megmarad, az új ügyfél be van töltve.
     */
    function munkalapUjUgyfel(gomb) {
        var urlap = gomb.closest('form');

        nyit('ugyfelek', '0', {
            szint: 1,
            siker: function (adat) {
                var doboz = munkalapUgyfelBeallit(urlap, adat.id, adat.nev);

                if (doboz) {
                    munkalapEszkozFrissit(doboz, String(adat.id));
                }

                var eszkoz = urlap ? urlap.querySelector('[data-sdh-eszkoz-valaszto]') : null;

                if (eszkoz) {
                    eszkoz.focus();
                }
            }
        });
    }

    /**
     * „+” az eszközmező mellett: az új eszköz popupban nyílik, a már
     * kiválasztott ügyféllel előtöltve. Mentés után az új eszköz ki van
     * választva; ha közben az eszköz ügyfelét átírták, az ügyfélmező követi.
     */
    function munkalapUjEszkoz(gomb) {
        var urlap = gomb.closest('form');
        var valaszto = urlap ? urlap.querySelector('.sdh-valaszto') : null;
        var ugyfelId = valaszto ? valaszto.querySelector('input[type="hidden"]').value : '0';

        nyit('eszkozok', '0', {
            szint: 1,
            parameterek: ugyfelId && ugyfelId !== '0' ? { ugyfel_id: ugyfelId } : {},
            siker: function (adat) {
                var doboz = munkalapUgyfelBeallit(urlap, adat.ugyfel_id, adat.ugyfel_nev);

                if (doboz) {
                    munkalapEszkozFrissit(doboz, String(adat.ugyfel_id), adat.id);
                }
            }
        });
    }

    /** Új hibasor: a <template> sablont klónozza a következő indexszel. */
    function hibasorUj(gomb) {
        var doboz = gomb.closest('.sdh-doboz');
        var lista = doboz ? doboz.querySelector('[data-sdh-hibasorok]') : null;
        var sablon = doboz ? doboz.querySelector('[data-sdh-hibasor-sablon]') : null;

        if (!lista || !sablon) {
            return;
        }

        var index = parseInt(lista.dataset.kovetkezo || '0', 10);
        lista.dataset.kovetkezo = String(index + 1);

        var html = sablon.innerHTML.split('__I__').join(String(index));
        var tarolo = document.createElement('div');
        tarolo.innerHTML = html;

        var sor = tarolo.firstElementChild;

        if (!sor) {
            return;
        }

        lista.appendChild(sor);

        var elso = sor.querySelector('input[type="text"]');
        if (elso) {
            elso.focus();
        }
    }

    /**
     * Hibasor eltávolítása. Az utolsó sort nem töröljük, csak kiürítjük,
     * hogy az űrlap mindig mutasson legalább egy sort.
     */
    function hibasorTorol(gomb) {
        var sor = gomb.closest('[data-sdh-hibasor]');
        var lista = gomb.closest('[data-sdh-hibasorok]');

        if (!sor || !lista) {
            return;
        }

        if (lista.querySelectorAll('[data-sdh-hibasor]').length <= 1) {
            Array.prototype.forEach.call(sor.querySelectorAll('input[type="text"]'), function (mezo) {
                mezo.value = '';
            });

            return;
        }

        sor.remove();
    }

    /** Az állapotválasztó melletti pötty színe követi a kiválasztott állapotot. */
    function allapotPontFrissit(valaszto) {
        var pont = valaszto.parentNode.querySelector('[data-sdh-allapot-pont]');
        var kivalasztott = valaszto.options[valaszto.selectedIndex];

        if (!pont || !kivalasztott) {
            return;
        }

        Array.prototype.slice.call(pont.classList).forEach(function (nev) {
            if (nev.indexOf('sdh-allapot--') === 0) {
                pont.classList.remove(nev);
            }
        });

        pont.classList.add('sdh-allapot--' + (kivalasztott.dataset.szin || 'szurke'));
    }

    document.addEventListener('change', function (esemeny) {
        var allapot = esemeny.target.closest('[data-sdh-allapot-valaszto]');

        if (allapot) {
            allapotPontFrissit(allapot);

            return;
        }

        // A listák szűrője azonnal érvényesül, külön Keresés-gomb nélkül.
        var szuro = esemeny.target.closest('select[data-sdh-szuro]');

        if (szuro && szuro.form) {
            szuro.form.submit();
        }
    });

    /* ---------------------------------------------------------------- */
    /* Indítás                                                          */
    /* ---------------------------------------------------------------- */

    document.addEventListener('click', function (esemeny) {
        var talalat = esemeny.target.closest('.sdh-valaszto__elem');

        if (talalat) {
            esemeny.preventDefault();
            valasztoValaszt(talalat.closest('.sdh-valaszto'), talalat);

            return;
        }

        // Máshová kattintva a nyitott találati listák bezárulnak.
        Array.prototype.forEach.call(
            document.querySelectorAll('.sdh-valaszto'),
            function (doboz) {
                if (!doboz.contains(esemeny.target)) {
                    valasztoTorol(doboz);
                }
            }
        );

        var ujUgyfelGomb = esemeny.target.closest('[data-sdh-uj-ugyfel]');

        if (ujUgyfelGomb) {
            esemeny.preventDefault();
            munkalapUjUgyfel(ujUgyfelGomb);

            return;
        }

        var ujEszkozGomb = esemeny.target.closest('[data-sdh-uj-eszkoz]');

        if (ujEszkozGomb) {
            esemeny.preventDefault();
            munkalapUjEszkoz(ujEszkozGomb);

            return;
        }

        var hibasorUjGomb = esemeny.target.closest('[data-sdh-hibasor-uj]');

        if (hibasorUjGomb) {
            esemeny.preventDefault();
            hibasorUj(hibasorUjGomb);

            return;
        }

        var hibasorTorolGomb = esemeny.target.closest('[data-sdh-hibasor-torol]');

        if (hibasorTorolGomb) {
            esemeny.preventDefault();
            hibasorTorol(hibasorTorolGomb);

            return;
        }

        var indito = esemeny.target.closest('[data-sdh-urlap]');

        if (!indito) {
            return;
        }

        esemeny.preventDefault();
        nyit(indito.dataset.sdhUrlap, indito.dataset.sdhId || '0');
    });
}());
