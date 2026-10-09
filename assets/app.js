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
     * Több popup-szint van: a 0. a fő (munkalap, ügyfél, eszköz), a 1–3. erre
     * épül rá – például az új ügyfél vagy új eszköz felvitele a munkalap
     * űrlapjáról, vagy az új ügyfél az eszközűrlapról, ami maga is a munkalap
     * fölött áll. A ráépülő szint bezárása után az alatta lévő popup érintetlen marad.
     */
    var szintek = [null, null, null, null];

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
    /* Popup képernyőhöz illesztése – soha nincs görgetősáv             */
    /* ---------------------------------------------------------------- */

    var ILLESZT_MIN = 0.55;

    /**
     * Ha a popup magasabb, mint a képernyő, nem görgetőssé tesszük, hanem
     * arányosan kicsinyítjük (CSS zoom). Mérés közben a zoom nulla, így a
     * természetes magasságot látjuk; a mérés és a beállítás egy lépésben fut.
     */
    function illesztPopup(dialog) {
        if (!dialog || !dialog.open) {
            return;
        }

        dialog.style.zoom = '';
        dialog.classList.add('sdh-modal--illeszt');

        var termeszetes = dialog.getBoundingClientRect().height;
        var szabad = window.innerHeight - 24;

        // A class marad: a popupnak nincs max-magassága és görgetése, csak zoomja.
        if (termeszetes > szabad && termeszetes > 0) {
            dialog.style.zoom = String(Math.max(ILLESZT_MIN, szabad / termeszetes));
        }
    }

    function illesztMind() {
        Array.prototype.forEach.call(document.querySelectorAll('dialog.sdh-modal[open]'), illesztPopup);
    }

    function illesztFigyel(dialog) {
        if (dialog.dataset.sdhIllesztKesz) {
            return;
        }

        dialog.dataset.sdhIllesztKesz = '1';

        var ido = 0;
        var kesleltet = function () {
            window.cancelAnimationFrame(ido);
            ido = window.requestAnimationFrame(function () {
                illesztPopup(dialog);
            });
        };

        new MutationObserver(kesleltet).observe(dialog, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['open', 'hidden']
        });

        // A lapfülek rádiógombjai és a mezők is változtatják a magasságot.
        dialog.addEventListener('change', kesleltet);
        dialog.addEventListener('input', kesleltet);
        dialog.addEventListener('load', kesleltet, true);
        kesleltet();
    }

    function illesztIndul() {
        new MutationObserver(function (valtozasok) {
            valtozasok.forEach(function (v) {
                Array.prototype.forEach.call(v.addedNodes, function (csomopont) {
                    if (csomopont.nodeType === 1 && csomopont.matches('dialog.sdh-modal')) {
                        illesztFigyel(csomopont);
                    }
                });
            });
        }).observe(document.body, { childList: true });

        Array.prototype.forEach.call(document.querySelectorAll('dialog.sdh-modal'), illesztFigyel);
        window.addEventListener('resize', illesztMind);
    }

    if (document.body) {
        illesztIndul();
    } else {
        document.addEventListener('DOMContentLoaded', illesztIndul);
    }

    /* ---------------------------------------------------------------- */
    /* Elavult CSS/JS felismerése                                       */
    /* ---------------------------------------------------------------- */

    function elemVerzio(elem, attr) {
        var cim = elem ? elem.getAttribute(attr) || '' : '';
        var talalat = /[?&](?:v|ver)=([^&]+)/.exec(cim);

        return talalat ? decodeURIComponent(talalat[1]) : '';
    }

    /**
     * A szerver minden válaszban megadja a friss CSS|JS verziót. Ha a nyitva
     * felejtett oldal régi stíluslapot használ, kicseréljük (az oldal marad);
     * régi szkriptnél egyszer frissítjük az oldalt – ilyenkor a popup még csak
     * töltődik, tehát nincs mit elveszíteni.
     */
    function verzioEllenoriz(fejlec) {
        if (!fejlec || fejlec.indexOf('|') < 0) {
            return;
        }

        var resz = fejlec.split('|');
        var css = document.querySelector('link[rel="stylesheet"][href*="assets/admin.css"]');
        var regiCss = elemVerzio(css, 'href');

        if (css && regiCss && resz[0] && regiCss !== resz[0]) {
            var uj = css.cloneNode(false);
            uj.setAttribute('href', css.getAttribute('href').replace(/([?&](?:v|ver)=)[^&]+/, '$1' + encodeURIComponent(resz[0])));
            uj.addEventListener('load', function () {
                if (css.parentNode) {
                    css.parentNode.removeChild(css);
                }
            });
            css.parentNode.insertBefore(uj, css.nextSibling);
        }

        var js = document.querySelector('script[src*="assets/app.js"]');
        var regiJs = elemVerzio(js, 'src');

        if (js && regiJs && resz[1] && regiJs !== resz[1]) {
            var kulcs = 'sdhFrissitve:' + resz[1];
            var mehet = true;

            try {
                if (window.sessionStorage.getItem(kulcs)) {
                    mehet = false;
                } else {
                    window.sessionStorage.setItem(kulcs, '1');
                }
            } catch (e) { /* nincs tárhely: egyszeri frissítés nem garantálható */
                mehet = false;
            }

            if (mehet) {
                window.location.reload();
            }
        }
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

                verzioEllenoriz(valasz.headers.get('X-SDH-Verzio'));

                return valasz.text();
            })
            .then(function (html) {
                szint.torzs.innerHTML = html;

                if (n > 0) {
                    idElotag(szint.torzs, 'sz' + n + '-');
                }

                bekotUrlap(szint, n, opciok);
                mintaKeres(szint.torzs);
                csatKeres(szint.torzs);
                szamKeres(szint.torzs);

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
                    return valasz.text().then(function (szoveg) {
                        try {
                            return JSON.parse(szoveg);
                        } catch (e) {
                            // Jellemzően a szerver beküldési korlátja (post_max_size) vágta el.
                            throw new Error(
                                valasz.status === 413 || valasz.status === 403 || szoveg === '-1'
                                    ? 'A beküldött adat túl nagy vagy lejárt a munkamenet. Kevesebb vagy kisebb fájllal próbáld, vagy frissítsd az oldalt.'
                                    : 'A szerver válasza nem értelmezhető.'
                            );
                        }
                    });
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

                        // Ahol a lista helyben frissíthető (a kezdőképernyő
                        // munkalap-rácsa), ott a figyelő elfogja a mentést:
                        // nincs oldalváltás, a popup bezárul, a rács újratölt.
                        var mentve = new CustomEvent('sdh:mentve', {
                            cancelable: true,
                            detail: {
                                action: urlap.dataset.sdhAjaxAction || '',
                                adat: eredmeny.data || {}
                            }
                        });

                        if (!document.dispatchEvent(mentve)) {
                            bezar(n);

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
            var kx = p1.x + (p2.x - p1.x) * 0.6;
            var ky = p1.y + (p2.y - p1.y) * 0.6;
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

    var MINTA_MAX = 20;

    /**
     * A tárolt érték a pöttyök sorrendje; ugyanaz a pötty többször is szerepelhet
     * (szabad rajz), csak két egymás utáni nem lehet azonos.
     */
    function mintaErtek(doboz) {
        var rejtett = doboz.querySelector('input[type="hidden"]');
        var nyers = (rejtett.value || '').replace(/[^1-9]/g, '');
        var sorrend = [];

        nyers.split('').forEach(function (sz) {
            var n = parseInt(sz, 10);

            if (sorrend[sorrend.length - 1] !== n && sorrend.length < MINTA_MAX) {
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

        var szabadJelolo = doboz.querySelector('[data-sdh-minta-szabad]');

        svg.addEventListener('pointerdown', function (esemeny) {
            esemeny.preventDefault();

            // Szabad rajz: pöttyönként kattintva (érintve) építhető a minta. Így egy
            // pötty újra érinthető, és a köztes pötty kihagyható (pl. 1→3 a 2 nélkül).
            if (szabadJelolo && szabadJelolo.checked) {
                var kattintott = mintaTalalat(mintaVasznon(svg, esemeny));

                if (kattintott && sorrend[sorrend.length - 1] !== kattintott && sorrend.length < MINTA_MAX) {
                    sorrend.push(kattintott);
                    mintaMent(doboz, sorrend.length >= 2 ? sorrend : []);
                    mintaRajzol(doboz, sorrend, null);
                }

                return;
            }

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

        var vissza = doboz.querySelector('[data-sdh-minta-vissza]');

        if (vissza) {
            vissza.addEventListener('click', function (esemeny) {
                esemeny.preventDefault();
                sorrend.pop();
                mintaMent(doboz, sorrend.length >= 2 ? sorrend : []);
                mintaRajzol(doboz, sorrend, null);
            });
        }

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

    /**
     * Az ellenőrző gombok valódi linkek: a kattintás mindig megnyitja az
     * oldalt új lapon – az IMEI hiánya sem akadály. Ha már megvan a teljes
     * IMEI, a vágólapra tesszük, hogy az oldalon csak be kelljen illeszteni.
     * Ha a cím tartalmaz {imei} helyőrzőt, abba beírjuk.
     */
    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest('[data-sdh-ellenorzo]');

        if (!gomb) {
            return;
        }

        var urlap = gomb.closest('form');
        var imeiMezo = urlap ? urlap.querySelector('[data-sdh-imei]') : null;
        var imei = imeiMezo ? imeiMezo.value.replace(/\D/g, '') : '';
        var teljes = imei.length === 15;
        var cim = gomb.dataset.sdhEllenorzo || gomb.getAttribute('href') || '';

        if (cim.indexOf('{imei}') !== -1) {
            esemeny.preventDefault();
            window.open(cim.replace('{imei}', teljes ? encodeURIComponent(imei) : ''), '_blank', 'noopener');

            if (urlap) {
                jelzes(
                    urlap,
                    teljes ? 'siker' : 'figyelem',
                    teljes
                        ? 'Megnyitottam az ellenőrzőt. Az eredményt másold ide vissza.'
                        : 'Megnyitottam az ellenőrzőt. Az IMEI-t még nem írtad be teljesen (15 számjegy).'
                );
            }

            return;
        }

        // A link maga nyílik meg (böngésző kezeli) – itt csak a vágólap és a jelzés.
        if (!urlap) {
            return;
        }

        if (!teljes) {
            jelzes(urlap, 'figyelem', 'Megnyílt az ellenőrző. Az IMEI-t még nem írtad be teljesen (15 számjegy).');

            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(imei)
                .then(function () {
                    jelzes(
                        urlap,
                        'siker',
                        'Az IMEI a vágólapon – illeszd be az oldalon, majd az eredményt másold ide vissza.'
                    );
                })
                .catch(function () {
                    jelzes(urlap, 'siker', 'Megnyílt az ellenőrző. Az IMEI: ' + imei);
                });

            return;
        }

        jelzes(urlap, 'siker', 'Megnyílt az ellenőrző. Az IMEI: ' + imei);
    });

    /* ---------------------------------------------------------------- */
    /* Popupból választható mezők (kategória, gyártó, szín, tartozék)   */
    /* ---------------------------------------------------------------- */

    /**
     * A mező csak olvasható; kattintásra középen felugró választó jön.
     * A választás visszaíródik a mezőbe, így a mentés és az IMEI-kitöltés
     * ugyanúgy látja, mint bármelyik sima szövegmezőt.
     *
     * A tartalom a mező data-sdh-pop-adat attribútumában utazik (JSON):
     *   kategoria, gyarto: [{cim, elemek: [szöveg…]}]
     *   szin:              [{nev, h}]   (h = a mintakör CSS-háttere)
     *   tartozek:          [szöveg…]
     */

    function popElem(tag, osztaly, szoveg) {
        var elem = document.createElement(tag);

        if (osztaly) {
            elem.className = osztaly;
        }

        if (szoveg !== undefined) {
            elem.textContent = szoveg;
        }

        return elem;
    }

    function popBeir(mezo, ertek) {
        mezo.value = ertek;
        mezo.dispatchEvent(new Event('input', { bubbles: true }));
        mezo.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function popNorm(szoveg) {
        return String(szoveg || '').toLowerCase().trim();
    }

    /** Kereső a választó tetején: a nem egyező gombokat és üres csoportokat elrejti. */
    function popKereso(torzs, hely) {
        var mezo = popElem('input', 'sdh-pop__kereso');
        mezo.type = 'search';
        mezo.placeholder = 'Keresés…';
        mezo.setAttribute('aria-label', 'Keresés');
        mezo.autocomplete = 'off';
        hely.appendChild(mezo);

        mezo.addEventListener('input', function () {
            var q = popNorm(mezo.value);

            Array.prototype.forEach.call(torzs.querySelectorAll('[data-pop-elem]'), function (elem) {
                elem.hidden = q !== '' && popNorm(elem.dataset.popElem).indexOf(q) === -1;
            });

            Array.prototype.forEach.call(torzs.querySelectorAll('[data-pop-csoport]'), function (csoport) {
                csoport.hidden = !csoport.querySelector('[data-pop-elem]:not([hidden])');
            });
        });

        return mezo;
    }

    /** Csoportosított gombok (kategória, gyártó). */
    function popCsoportok(torzs, adat, aktualis, valasztott) {
        adat.forEach(function (csoport) {
            var doboz = popElem('section', 'sdh-pop__csoport');
            doboz.setAttribute('data-pop-csoport', '');
            doboz.appendChild(popElem('h4', 'sdh-pop__csoportcim', csoport.cim));

            var racs = popElem('div', 'sdh-pop__racs');

            csoport.elemek.forEach(function (nev) {
                var gomb = popElem('button', 'sdh-pop__chip', nev);
                gomb.type = 'button';
                gomb.setAttribute('data-pop-elem', nev);

                if (popNorm(nev) === popNorm(aktualis)) {
                    gomb.classList.add('is-aktiv');
                }

                gomb.addEventListener('click', function () {
                    valasztott(nev);
                });

                racs.appendChild(gomb);
            });

            doboz.appendChild(racs);
            torzs.appendChild(doboz);
        });
    }

    /** „Saját érték” sor: szövegmező + Használ gomb. */
    function popSajat(hely, cimke, helyorzo, kezdet, valasztott) {
        var sor = popElem('div', 'sdh-pop__sajat');
        sor.appendChild(popElem('span', 'sdh-pop__sajatcimke', cimke));

        var mezo = popElem('input', 'sdh-pop__sajatmezo');
        mezo.type = 'text';
        mezo.placeholder = helyorzo;
        mezo.value = kezdet || '';
        mezo.autocomplete = 'off';

        sor.appendChild(mezo);

        // Valasztott nélkül (tartozéklista) csak mező van: a „Kész” gomb gyűjti össze.
        if (valasztott) {
            var gomb = popElem('button', 'sdh-gomb sdh-gomb--vilagos', 'Használ');
            gomb.type = 'button';

            var kesz = function () {
                var ertek = mezo.value.trim();

                if (ertek) {
                    valasztott(ertek);
                }
            };

            gomb.addEventListener('click', kesz);
            sor.appendChild(gomb);
        }

        mezo.addEventListener('keydown', function (esemeny) {
            if (esemeny.key === 'Enter') {
                esemeny.preventDefault();

                if (valasztott) {
                    var ertek = mezo.value.trim();

                    if (ertek) {
                        valasztott(ertek);
                    }
                }
            }
        });

        hely.appendChild(sor);

        return mezo;
    }

    function popSzinek(torzs, adat, aktualis, valasztott) {
        var racs = popElem('div', 'sdh-pop__racs sdh-pop__racs--szin');

        adat.forEach(function (szin) {
            var gomb = popElem('button', 'sdh-pop__chip sdh-pop__chip--szin');
            gomb.type = 'button';
            gomb.setAttribute('data-pop-elem', szin.nev);

            var kor = popElem('span', 'sdh-pop__szin');
            kor.style.background = szin.h;
            gomb.appendChild(kor);
            gomb.appendChild(popElem('span', '', szin.nev));

            if (popNorm(szin.nev) === popNorm(aktualis)) {
                gomb.classList.add('is-aktiv');
            }

            gomb.addEventListener('click', function () {
                valasztott(szin.nev);
            });

            racs.appendChild(gomb);
        });

        torzs.appendChild(racs);
    }

    function popTartozekok(torzs, lista, aktualis, valasztott) {
        var jelolt = String(aktualis || '').split(',').map(function (t) {
            return t.trim();
        }).filter(Boolean);

        var ismert = lista.map(popNorm);
        var egyeb = jelolt.filter(function (t) {
            return ismert.indexOf(popNorm(t)) === -1;
        });

        var racs = popElem('div', 'sdh-pop__racs sdh-pop__racs--tartozek');
        var dobozok = [];

        lista.forEach(function (nev) {
            var cimke = popElem('label', 'sdh-pop__jelolo');
            cimke.setAttribute('data-pop-elem', nev);

            var doboz = document.createElement('input');
            doboz.type = 'checkbox';
            doboz.value = nev;
            doboz.checked = jelolt.some(function (t) {
                return popNorm(t) === popNorm(nev);
            });

            cimke.appendChild(doboz);
            cimke.appendChild(popElem('span', '', nev));
            racs.appendChild(cimke);
            dobozok.push(doboz);
        });

        torzs.appendChild(racs);

        var lab = popElem('div', 'sdh-pop__lab');
        var egyebMezo = popSajat(torzs, 'Egyéb', 'Más tartozék – vesszővel több is írható', egyeb.join(', '), null);

        var kesz = popElem('button', 'sdh-gomb sdh-gomb--elsodleges', 'Kész');
        kesz.type = 'button';
        kesz.addEventListener('click', function () {
            var ki = dobozok.filter(function (d) {
                return d.checked;
            }).map(function (d) {
                return d.value;
            });

            egyebMezo.value.split(',').forEach(function (t) {
                t = t.trim();

                if (t && ki.indexOf(t) === -1) {
                    ki.push(t);
                }
            });

            valasztott(ki.join(', '));
        });

        var torol = popElem('button', 'sdh-gomb sdh-gomb--vilagos', 'Mindet töröl');
        torol.type = 'button';
        torol.addEventListener('click', function () {
            dobozok.forEach(function (d) {
                d.checked = false;
            });
            egyebMezo.value = '';
        });

        lab.appendChild(kesz);
        lab.appendChild(torol);
        torzs.appendChild(lab);
    }

    var HONAPOK = ['Január', 'Február', 'Március', 'Április', 'Május', 'Június', 'Július',
        'Augusztus', 'Szeptember', 'Október', 'November', 'December'];
    var NAPOK = ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];

    function datumKettes(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function datumIso(ev, honap, nap) {
        return ev + '-' + datumKettes(honap + 1) + '-' + datumKettes(nap);
    }

    /**
     * Saját naptár a natív helyett: a böngésző beépített naptára nem
     * stílusozható, és a popupok közé sem illett. Az érték mindig ÉÉÉÉ-HH-NN.
     */
    function popDatum(torzs, aktualis, valasztott) {
        var ma = new Date();
        var egyezes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(aktualis || ''));
        var kijelolt = egyezes ? aktualis : '';
        var ev = egyezes ? parseInt(egyezes[1], 10) : ma.getFullYear();
        var honap = egyezes ? parseInt(egyezes[2], 10) - 1 : ma.getMonth();
        var maIso = datumIso(ma.getFullYear(), ma.getMonth(), ma.getDate());

        var fej = popElem('div', 'sdh-naptar__fej');
        var elozo = popElem('button', 'sdh-naptar__lep', '‹');
        var kovetkezo = popElem('button', 'sdh-naptar__lep', '›');
        var honapValaszto = popElem('select', 'sdh-naptar__valaszto');
        var evValaszto = popElem('select', 'sdh-naptar__valaszto sdh-naptar__valaszto--ev');
        var racs = popElem('div', 'sdh-naptar__racs');

        elozo.type = 'button';
        kovetkezo.type = 'button';
        elozo.setAttribute('aria-label', 'Előző hónap');
        kovetkezo.setAttribute('aria-label', 'Következő hónap');

        HONAPOK.forEach(function (nev, i) {
            var elem = popElem('option', '', nev);
            elem.value = String(i);
            honapValaszto.appendChild(elem);
        });

        for (var e = ma.getFullYear() + 6; e >= ma.getFullYear() - 40; e--) {
            var evElem = popElem('option', '', String(e));
            evElem.value = String(e);
            evValaszto.appendChild(evElem);
        }

        function rajzol() {
            honapValaszto.value = String(honap);

            if (!evValaszto.querySelector('option[value="' + ev + '"]')) {
                var kulon = popElem('option', '', String(ev));
                kulon.value = String(ev);
                evValaszto.insertBefore(kulon, evValaszto.firstChild);
            }

            evValaszto.value = String(ev);
            racs.innerHTML = '';

            NAPOK.forEach(function (nev) {
                racs.appendChild(popElem('span', 'sdh-naptar__nev', nev));
            });

            // Hétfővel kezdünk: a JS vasárnapja 0, ezért tolunk egyet.
            var eltolas = (new Date(ev, honap, 1).getDay() + 6) % 7;
            var napokSzama = new Date(ev, honap + 1, 0).getDate();

            for (var u = 0; u < eltolas; u++) {
                racs.appendChild(popElem('span', 'sdh-naptar__ures'));
            }

            for (var nap = 1; nap <= napokSzama; nap++) {
                var iso = datumIso(ev, honap, nap);
                var gomb = popElem('button', 'sdh-naptar__nap', String(nap));
                gomb.type = 'button';
                gomb.dataset.iso = iso;

                if (iso === maIso) {
                    gomb.classList.add('is-ma');
                }

                if (iso === kijelolt) {
                    gomb.classList.add('is-aktiv');
                }

                racs.appendChild(gomb);
            }
        }

        function lep(irany) {
            honap += irany;

            if (honap < 0) {
                honap = 11;
                ev--;
            } else if (honap > 11) {
                honap = 0;
                ev++;
            }

            rajzol();
        }

        elozo.addEventListener('click', function () { lep(-1); });
        kovetkezo.addEventListener('click', function () { lep(1); });

        honapValaszto.addEventListener('change', function () {
            honap = parseInt(honapValaszto.value, 10);
            rajzol();
        });

        evValaszto.addEventListener('change', function () {
            ev = parseInt(evValaszto.value, 10);
            rajzol();
        });

        racs.addEventListener('click', function (esemeny) {
            var gomb = esemeny.target.closest('[data-iso]');

            if (gomb) {
                valasztott(gomb.dataset.iso);
            }
        });

        fej.appendChild(elozo);
        fej.appendChild(honapValaszto);
        fej.appendChild(evValaszto);
        fej.appendChild(kovetkezo);
        torzs.appendChild(fej);
        torzs.appendChild(racs);

        var lab = popElem('div', 'sdh-pop__lab sdh-pop__lab--kozep');
        var maGomb = popElem('button', 'sdh-gomb sdh-gomb--vilagos', 'Ma');
        var torolGomb = popElem('button', 'sdh-gomb sdh-gomb--vilagos', 'Törlés');
        maGomb.type = 'button';
        torolGomb.type = 'button';
        maGomb.addEventListener('click', function () { valasztott(maIso); });
        torolGomb.addEventListener('click', function () { valasztott(''); });
        lab.appendChild(maGomb);
        lab.appendChild(torolGomb);
        torzs.appendChild(lab);

        rajzol();
    }

    function popNyit(mezo) {
        var tipus = mezo.dataset.sdhPop;
        var adat;

        try {
            adat = JSON.parse(mezo.getAttribute('data-sdh-pop-adat') || '[]');
        } catch (e) {
            adat = [];
        }

        var cimek = {
            kategoria: 'Kategória',
            gyarto: 'Gyártó',
            szin: 'Szín',
            tartozek: 'Tartozékok',
            datum: 'Dátum'
        };

        var dialog = document.createElement('dialog');
        dialog.className = 'sdh-modal sdh-modal--pop sdh-modal--pop-' + tipus;
        dialog.innerHTML =
            '<div class="sdh-modal__doboz">' +
            '  <button type="button" class="sdh-modal__bezar" aria-label="Bezárás">&times;</button>' +
            '  <div class="sdh-modal__torzs"></div>' +
            '</div>';

        document.body.appendChild(dialog);

        var torzs = dialog.querySelector('.sdh-modal__torzs');
        var bezarva = false;

        var bezarPop = function () {
            if (!bezarva) {
                bezarva = true;

                if (dialog.open) {
                    dialog.close();
                }
            }
        };

        var valasztott = function (ertek) {
            popBeir(mezo, ertek);
            bezarPop();
        };

        dialog.querySelector('.sdh-modal__bezar').addEventListener('click', bezarPop);

        dialog.addEventListener('click', function (esemeny) {
            if (esemeny.target === dialog) {
                bezarPop();
            }
        });

        dialog.addEventListener('close', function () {
            dialog.remove();
            mezo.focus();
        });

        torzs.appendChild(popElem('h2', 'sdh-modal__cim', cimek[tipus] || 'Választás'));

        var fej = popElem('div', 'sdh-pop__fej');
        var targy = popElem('div', 'sdh-pop__targy');
        var kereso = null;

        if (tipus === 'kategoria' || tipus === 'gyarto') {
            kereso = popKereso(targy, fej);
            torzs.appendChild(fej);
        }

        torzs.appendChild(targy);

        if (tipus === 'kategoria') {
            popCsoportok(targy, adat, mezo.value, valasztott);
        } else if (tipus === 'gyarto') {
            popCsoportok(targy, adat, mezo.value, valasztott);

            var alj = popElem('div', 'sdh-pop__alj');
            popSajat(alj, 'Más gyártó', 'Írd be a nevét', '', valasztott);

            if (mezo.value) {
                var ures = popElem('button', 'sdh-gomb sdh-gomb--vilagos', 'Gyártó törlése');
                ures.type = 'button';
                ures.addEventListener('click', function () {
                    valasztott('');
                });
                alj.appendChild(ures);
            }

            torzs.appendChild(alj);
        } else if (tipus === 'szin') {
            popSzinek(targy, adat, mezo.value, valasztott);

            var szinAlj = popElem('div', 'sdh-pop__alj');
            popSajat(szinAlj, 'Más szín', 'Pl. Neon sárga', '', valasztott);

            if (mezo.value) {
                var szinUres = popElem('button', 'sdh-gomb sdh-gomb--vilagos', 'Szín törlése');
                szinUres.type = 'button';
                szinUres.addEventListener('click', function () {
                    valasztott('');
                });
                szinAlj.appendChild(szinUres);
            }

            torzs.appendChild(szinAlj);
        } else if (tipus === 'tartozek') {
            popTartozekok(targy, adat, mezo.value, valasztott);
        } else if (tipus === 'datum') {
            popDatum(targy, mezo.value, valasztott);
        }

        dialog.showModal();

        // Csak a keresőbe ugrik a kurzor: gombra, jelölőnégyzetre vagy más mezőre
        // tett fókusz keretet rajzolna megnyitáskor, ami zavaró.
        if (kereso) {
            kereso.focus();
        }
    }

    document.addEventListener('click', function (esemeny) {
        var mezo = esemeny.target.closest('[data-sdh-pop]');

        if (!mezo) {
            return;
        }

        esemeny.preventDefault();
        popNyit(mezo);
    });

    document.addEventListener('keydown', function (esemeny) {
        var mezo = esemeny.target.closest ? esemeny.target.closest('[data-sdh-pop]') : null;

        if (!mezo) {
            return;
        }

        if (esemeny.key === 'Enter' || esemeny.key === ' ' || esemeny.key === 'ArrowDown') {
            esemeny.preventDefault();
            popNyit(mezo);

            return;
        }

        // Gyors törlés a mezőből – kategóriát nem lehet üresre venni.
        if ((esemeny.key === 'Backspace' || esemeny.key === 'Delete') && mezo.dataset.sdhPop !== 'kategoria') {
            esemeny.preventDefault();
            popBeir(mezo, '');
        }
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
    function sajatSzint(elem) {
        for (var i = 0; i < szintek.length; i++) {
            if (szintek[i] && szintek[i].dialog.contains(elem)) {
                return i;
            }
        }

        return -1;
    }

    function munkalapUjUgyfel(gomb) {
        var urlap = gomb.closest('form');

        nyit('ugyfelek', '0', {
            // Az űrlap fölé épül: munkalapról vagy eszközről, popupból vagy oldalról is.
            szint: Math.min(sajatSzint(gomb) + 1, szintek.length - 1),
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
            szint: Math.min(sajatSzint(gomb) + 1, szintek.length - 1),
            parameterek: ugyfelId && ugyfelId !== '0' ? { ugyfel_id: ugyfelId } : {},
            siker: function (adat) {
                var doboz = munkalapUgyfelBeallit(urlap, adat.ugyfel_id, adat.ugyfel_nev);

                if (doboz) {
                    munkalapEszkozFrissit(doboz, String(adat.ugyfel_id), adat.id);
                }
            }
        });
    }

    /**
     * Lapfüles űrlapon (pl. ügyfél) a rejtett lapfülön lévő hibás mező nem
     * fókuszálható, a böngésző ilyenkor szó nélkül nem küldi be az űrlapot.
     * Ezért érvénytelen mezőnél átváltunk az őt tartalmazó lapfülre.
     */
    document.addEventListener('invalid', function (esemeny) {
        var panel = esemeny.target.closest ? esemeny.target.closest('.sdh-fulek__panel') : null;
        var fulek = panel ? panel.closest('.sdh-fulek') : null;

        if (!fulek) {
            return;
        }

        var panelek = Array.prototype.filter.call(fulek.children, function (elem) {
            return elem.classList.contains('sdh-fulek__panel');
        });
        var valasztok = Array.prototype.filter.call(fulek.children, function (elem) {
            return elem.classList.contains('sdh-fulek__ful');
        });
        var radio = valasztok[panelek.indexOf(panel)];

        if (radio && !radio.checked) {
            radio.checked = true;
        }
    }, true);

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

    /** Kis üzenet a lista fölött (a lista nem űrlap, ezért külön jelzés kell). */
    function listaJelzes(szoveg) {
        // A kezdőképernyő rácsa nem .sdh-tabla: ott a jelzés helyét külön jelöljük.
        var tabla = document.querySelector('[data-sdh-jelzes-hely], .sdh-tabla');

        if (!tabla) {
            window.alert(szoveg);

            return;
        }

        var doboz = document.querySelector('.sdh-lista-jelzes');

        if (!doboz) {
            doboz = document.createElement('div');
            doboz.className = 'sdh-lista-jelzes';
            doboz.setAttribute('role', 'alert');
            tabla.parentNode.insertBefore(doboz, tabla);
        }

        doboz.textContent = szoveg;
        window.clearTimeout(doboz.sdhIdo);
        doboz.sdhIdo = window.setTimeout(function () {
            doboz.remove();
        }, 6000);
    }

    /** Állapotváltás közvetlenül a munkalap-listából. */
    function listaAllapotValt(valaszto) {
        var hely = valaszto.closest('[data-sdh-allapot-hely]');
        var elozo = valaszto.dataset.elozo || '';
        var adatok = new FormData();

        adatok.set('action', 'sdh_muhely_munkalapok_allapot');
        adatok.set('_wpnonce', beallitas.nonce || '');
        adatok.set('id', valaszto.dataset.id || '0');
        adatok.set('allapot', valaszto.value);

        valaszto.disabled = true;

        function vissza(uzenet) {
            valaszto.value = elozo;
            valaszto.disabled = false;
            listaJelzes(uzenet);
        }

        fetch(new URL(beallitas.ajax, window.location.origin).toString(), {
            method: 'POST',
            body: adatok,
            credentials: 'same-origin'
        })
            .then(function (valasz) {
                return valasz.json();
            })
            .then(function (valasz) {
                if (!valasz || !valasz.success) {
                    vissza((valasz && valasz.data && valasz.data.uzenet) || 'Az állapot nem módosult.');

                    return;
                }

                var adat = valasz.data;

                Array.prototype.slice.call(hely.classList).forEach(function (nev) {
                    if (nev.indexOf('sdh-allapot--') === 0 && nev !== 'sdh-allapot--valaszthato') {
                        hely.classList.remove(nev);
                    }
                });

                hely.classList.add('sdh-allapot--' + adat.szin);
                valaszto.dataset.elozo = valaszto.value;
                valaszto.disabled = false;

                var sor = valaszto.closest('tr');

                if (sor) {
                    sor.classList.toggle('sdh-sor--zart', !!adat.zart);

                    // Az első számozott állapotba lépéskor a lap számot kap.
                    var szam = sor.querySelector('[data-sdh-munkalap-szam]');

                    if (szam && adat.szam && szam.textContent.trim() !== adat.szam) {
                        szam.textContent = adat.szam;
                    }
                }

                // A rács és a részletpanel ebből tudja, hogy frissítenie kell.
                document.dispatchEvent(new CustomEvent('sdh:allapot', {
                    detail: { id: valaszto.dataset.id || '0', adat: adat }
                }));
            })
            .catch(function () {
                vissza('Nem sikerült elérni a szervert, az állapot nem módosult.');
            });
    }

    document.addEventListener('change', function (esemeny) {
        var listaAllapot = esemeny.target.closest('select[data-sdh-lista-allapot]');

        if (listaAllapot) {
            listaAllapotValt(listaAllapot);

            return;
        }

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
    /* Saját legördülő menü (a böngésző natív listája helyett)          */
    /* ---------------------------------------------------------------- */
    /*
     * Minden egysoros <select>-et lefed, felülről delegálva, ezért az
     * AJAX-szal betöltött popupokra is érvényes. Az érték és a change
     * esemény a selecten marad, így a meglévő kód nem változik.
     * Kikapcsolás egy selecten: data-sdh-nativ.
     */
    var legordulo = null;

    function legorduloZar() {
        if (!legordulo) {
            return;
        }

        var l = legordulo;

        legordulo = null;
        document.removeEventListener('mousedown', l.kinti, true);
        document.removeEventListener('keydown', l.billentyu, true);
        window.removeEventListener('resize', l.zar, true);
        window.removeEventListener('scroll', l.gorget, true);

        try {
            if (l.menu.hidePopover) {
                l.menu.hidePopover();
            }
        } catch (e) {
            /* nem volt nyitva */
        }

        l.menu.remove();
        l.select.classList.remove('sdh-legordulo--nyitva');
    }

    function legorduloKepes(select) {
        return select
            && select.tagName === 'SELECT'
            && !select.multiple
            && !(select.size > 1)
            && !select.hasAttribute('data-sdh-nativ')
            && !!select.closest('.sdh-app, .sdh-wrap, .sdh-modal');
    }

    function legorduloNyit(select) {
        legorduloZar();

        if (select.disabled || !select.options.length) {
            return;
        }

        var tarolo = select.closest('dialog[open]') || document.body;
        var menu = document.createElement('div');

        menu.className = 'sdh-menu';
        menu.setAttribute('role', 'listbox');

        if (menu.showPopover) {
            menu.setAttribute('popover', 'manual');
        }

        var elemek = [];

        function elemKesz(opcio) {
            var gomb = document.createElement('button');

            gomb.type = 'button';
            gomb.className = 'sdh-menu__elem';
            gomb.setAttribute('role', 'option');
            gomb.disabled = opcio.disabled;
            gomb.sdhOpcio = opcio;

            if (opcio.dataset.szin) {
                var pont = document.createElement('span');

                pont.className = 'sdh-allapot-pont sdh-allapot--' + opcio.dataset.szin;
                gomb.appendChild(pont);
            }

            var szoveg = document.createElement('span');

            szoveg.className = 'sdh-menu__szoveg';
            szoveg.textContent = opcio.textContent.trim() || ' ';
            gomb.appendChild(szoveg);

            if (opcio.selected) {
                gomb.classList.add('sdh-menu__elem--aktiv');
                gomb.setAttribute('aria-selected', 'true');
            }

            menu.appendChild(gomb);
            elemek.push(gomb);
        }

        Array.prototype.forEach.call(select.children, function (gyerek) {
            if (gyerek.tagName === 'OPTGROUP') {
                var cim = document.createElement('div');

                cim.className = 'sdh-menu__csoport';
                cim.textContent = gyerek.label;
                menu.appendChild(cim);
                Array.prototype.forEach.call(gyerek.children, elemKesz);
            } else if (gyerek.tagName === 'OPTION') {
                elemKesz(gyerek);
            }
        });

        tarolo.appendChild(menu);

        if (menu.showPopover) {
            menu.showPopover();
        }

        /* A popup CSS zoomja a menüre is hat: a pozíciót vissza kell osztani. */
        var zoom = parseFloat(window.getComputedStyle(menu).zoom) || 1;
        var r = select.getBoundingClientRect();
        var meret = window.innerHeight;
        var szelesseg = Math.max(r.width, 180) / zoom;

        menu.style.minWidth = szelesseg + 'px';
        menu.style.left = (r.left / zoom) + 'px';
        menu.style.top = ((r.bottom + 4) / zoom) + 'px';

        var h = menu.getBoundingClientRect().height;
        var lent = meret - r.bottom - 12;
        var fent = r.top - 12;

        if (h > lent && fent > lent) {
            menu.style.top = (Math.max(8, r.top - 4 - Math.min(h, fent)) / zoom) + 'px';
            menu.style.maxHeight = (Math.min(h, fent) / zoom) + 'px';
        } else if (h > lent) {
            menu.style.maxHeight = (lent / zoom) + 'px';
        }

        var jobb = menu.getBoundingClientRect().right;

        if (jobb > window.innerWidth - 8) {
            menu.style.left = (parseFloat(menu.style.left) - (jobb - window.innerWidth + 8) / zoom) + 'px';
        }

        var aktiv = menu.querySelector('.sdh-menu__elem--aktiv') || elemek[0];

        function fokusz(gomb) {
            if (gomb) {
                gomb.focus({ preventScroll: false });
            }
        }

        function lep(irany) {
            var szabad = elemek.filter(function (e) {
                return !e.disabled;
            });
            var i = szabad.indexOf(document.activeElement);

            fokusz(szabad[Math.max(0, Math.min(szabad.length - 1, i + irany))]);
        }

        function valaszt(gomb) {
            if (!gomb || gomb.disabled) {
                return;
            }

            var opcio = gomb.sdhOpcio;

            legorduloZar();
            select.focus();

            if (select.value !== opcio.value || !opcio.selected) {
                select.value = opcio.value;
                select.dispatchEvent(new Event('input', { bubbles: true }));
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        legordulo = {
            menu: menu,
            select: select,
            zar: function () {
                legorduloZar();
            },
            gorget: function (esemeny) {
                if (!menu.contains(esemeny.target)) {
                    legorduloZar();
                }
            },
            kinti: function (esemeny) {
                if (!menu.contains(esemeny.target) && esemeny.target !== select) {
                    legorduloZar();
                }
            },
            billentyu: function (esemeny) {
                var k = esemeny.key;

                if (k === 'Escape' || k === 'Tab') {
                    if (k === 'Escape') {
                        esemeny.preventDefault();
                        esemeny.stopPropagation();
                    }

                    legorduloZar();
                    select.focus();

                    return;
                }

                if (k === 'ArrowDown' || k === 'ArrowUp') {
                    esemeny.preventDefault();
                    lep(k === 'ArrowDown' ? 1 : -1);
                } else if (k === 'Home' || k === 'End') {
                    esemeny.preventDefault();
                    var sz = elemek.filter(function (e) {
                        return !e.disabled;
                    });

                    fokusz(k === 'Home' ? sz[0] : sz[sz.length - 1]);
                } else if (k === 'Enter' || k === ' ') {
                    esemeny.preventDefault();
                    valaszt(document.activeElement.closest('.sdh-menu__elem'));
                }
            }
        };

        menu.addEventListener('click', function (esemeny) {
            valaszt(esemeny.target.closest('.sdh-menu__elem'));
        });

        document.addEventListener('mousedown', legordulo.kinti, true);
        document.addEventListener('keydown', legordulo.billentyu, true);
        window.addEventListener('resize', legordulo.zar, true);
        window.addEventListener('scroll', legordulo.gorget, true);

        select.classList.add('sdh-legordulo--nyitva');
        fokusz(aktiv);
    }

    document.addEventListener('mousedown', function (esemeny) {
        var select = esemeny.target.closest ? esemeny.target.closest('select') : null;

        if (!legorduloKepes(select) || esemeny.button !== 0) {
            return;
        }

        esemeny.preventDefault();

        if (legordulo && legordulo.select === select) {
            legorduloZar();

            return;
        }

        select.focus();
        legorduloNyit(select);
    });

    document.addEventListener('keydown', function (esemeny) {
        var select = esemeny.target;

        if (!legorduloKepes(select) || legordulo) {
            return;
        }

        if (esemeny.key === 'Enter' || esemeny.key === ' '
            || (esemeny.altKey && esemeny.key === 'ArrowDown')) {
            esemeny.preventDefault();
            legorduloNyit(select);
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

    /* ---------------------------------------------------------------- */
    /* Irányítószám ↔ település                                         */
    /* ---------------------------------------------------------------- */

    /*
     * A teljes magyar lista egyszer töltődik le (kb. 100 KB, a böngésző
     * napokra gyorsítótárazza), utána minden keresés helyben fut, ezért
     * gépelés közben nincs várakozás. Az egyező mezőpárt a [data-sdh-cimsor]
     * sor adja: abban az Isz. ([data-sdh-isz]) és a Település
     * ([data-sdh-telepules]) mező tartozik össze.
     */
    var iszAdat = null;
    var iszIgeret = null;

    function iszNorm(szoveg) {
        return String(szoveg || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/[\s\-–]+/g, ' ')
            .trim();
    }

    /*
     * Egy sor: { isz, nev (település), resz (településrész vagy ''),
     *            kitolt (ami a Település mezőbe kerül), nk (kitolt normalizálva),
     *            fo (településnév normalizálva), rk (rész normalizálva) }.
     *
     * Településrésznél a mezőbe „Veszprém-Kádárta” kerül, ahogy postai címben
     * írják. Budapest kerületei csak megjelennek a listában („V. kerület”), a
     * mezőbe „Budapest” kerül – a kerületet az irányítószám már hordozza.
     */
    function iszSor(s) {
        var nev = s[1] || '';
        var resz = s[2] || '';
        var kerulet = nev === 'Budapest' && / kerület$/.test(resz);
        var kitolt = resz && !kerulet ? nev + '-' + resz : nev;

        return {
            isz: s[0],
            nev: nev,
            resz: resz,
            kitolt: kitolt,
            nk: iszNorm(kitolt),
            fo: iszNorm(nev),
            rk: iszNorm(resz)
        };
    }

    function iszBetolt() {
        if (iszAdat) {
            return Promise.resolve(iszAdat);
        }

        if (iszIgeret) {
            return iszIgeret;
        }

        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_iranyitoszamok');
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');
        cim.searchParams.set('v', beallitas.iszVerzio || '');

        iszIgeret = fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (valasz) {
                if (!valasz.ok) {
                    throw new Error('HTTP ' + valasz.status);
                }

                return valasz.json();
            })
            .then(function (valasz) {
                if (!valasz || !valasz.success) {
                    throw new Error('Üres válasz');
                }

                var sorok = valasz.data.sorok.map(iszSor);
                var iszSzerint = {};
                var nevSzerint = {};
                var foSzerint = {};

                sorok.forEach(function (s) {
                    (iszSzerint[s.isz] = iszSzerint[s.isz] || []).push(s);
                    (nevSzerint[s.nk] = nevSzerint[s.nk] || []).push(s);
                    (foSzerint[s.fo] = foSzerint[s.fo] || []).push(s);
                });

                iszAdat = { sorok: sorok, iszSzerint: iszSzerint, nevSzerint: nevSzerint, foSzerint: foSzerint };

                return iszAdat;
            })
            .catch(function (ok) {
                // Hiba esetén a következő mezőérintés újra próbálkozhat.
                iszIgeret = null;
                throw ok;
            });

        return iszIgeret;
    }

    /** Helyi keresés: a találatok listája, legfeljebb `max` darab. */
    function iszKeres(adat, mezo, szoveg, max) {
        var talalat = [];

        if (mezo === 'isz') {
            var szam = String(szoveg).replace(/\s+/g, '');

            if (szam === '') {
                return talalat;
            }

            for (var i = 0; i < adat.sorok.length && talalat.length < max; i++) {
                if (adat.sorok[i].isz.indexOf(szam) === 0) {
                    talalat.push(adat.sorok[i]);
                }
            }

            return talalat;
        }

        var kulcs = iszNorm(szoveg);

        if (kulcs.length < 2) {
            return talalat;
        }

        // Csoport = egy kitöltendő név (pl. „Veszprém”, „Veszprém-Kádárta”).
        // Keresünk a teljes névben és külön a településrész nevében is,
        // így a „Kádárta” vagy a „Diszel” is megtalálja a helyét.
        var csoportok = {};
        var rend = [];

        adat.sorok.forEach(function (s) {
            var hely = s.nk.indexOf(kulcs);
            var reszHely = s.rk ? s.rk.indexOf(kulcs) : -1;

            if (hely === -1 && reszHely === -1) {
                return;
            }

            var rang = s.nk === kulcs || s.rk === kulcs ? 0
                : (hely === 0 || reszHely === 0 ? 1 : 2);

            if (!csoportok[s.nk]) {
                csoportok[s.nk] = { s: s, nk: s.nk, rang: rang, sorok: [] };
                rend.push(csoportok[s.nk]);
            }

            csoportok[s.nk].rang = Math.min(csoportok[s.nk].rang, rang);
            csoportok[s.nk].sorok.push(s);
        });

        // Pontos egyezés, aztán az elején egyező, végül a többi; a rövidebb
        // név előbb, a fő település a részei előtt.
        // A településrészek közvetlenül a saját településük alatt állnak.
        var foRang = {};
        rend.forEach(function (g) {
            foRang[g.s.fo] = Math.min(foRang[g.s.fo] === undefined ? 9 : foRang[g.s.fo], g.rang);
        });

        rend.sort(function (a, b) {
            return foRang[a.s.fo] - foRang[b.s.fo] ||
                a.s.fo.length - b.s.fo.length ||
                (a.s.fo < b.s.fo ? -1 : (a.s.fo > b.s.fo ? 1 : 0)) ||
                (a.s.resz ? 1 : 0) - (b.s.resz ? 1 : 0) ||
                (a.nk < b.nk ? -1 : 1);
        });

        return rend.slice(0, max).map(function (g) {
            // Egy irányítószámú név közvetlenül választható; többnél (Budapest,
            // Miskolc…) a választás után a kódjai közül lehet választani.
            return g.sorok.length === 1
                ? g.sorok[0]
                : { isz: '', nev: g.s.nev, resz: '', kitolt: g.s.kitolt, nk: g.nk, csoport: g.sorok };
        });
    }

    var iszLista = null;      // az éppen nyitott találati lista elem
    var iszAllapot = null;    // { sor, mezo, talalatok, kijelolt }

    function iszZar() {
        if (iszLista) {
            iszLista.hidden = true;
            iszLista.innerHTML = '';
        }

        iszAllapot = null;
    }

    function iszMezok(sor) {
        return {
            isz: sor.querySelector('[data-sdh-isz]'),
            nev: sor.querySelector('[data-sdh-telepules]')
        };
    }

    /**
     * Beírja a kiválasztott párt, és jelzi a változást a többi szkriptnek.
     * `csakSzam`: gépelés közben a Település mezőt nem írjuk át (különben a
     * „Tap” → „Táp” javítás vagy a kötőjel eltűnése belerontana a gépelésbe);
     * a pontos alak kilépéskor kerül be (lásd focusout).
     */
    function iszKitolt(sor, tetel, csakSzam) {
        var mezok = iszMezok(sor);
        var parok = [[mezok.isz, tetel.isz]];

        if (!csakSzam) {
            parok.push([mezok.nev, tetel.kitolt]);
        }

        parok.forEach(function (par) {
            if (par[0] && par[0].value !== par[1]) {
                par[0].value = par[1];
                par[0].dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        sor.classList.add('sdh-cimsor--kitoltve');
        window.setTimeout(function () {
            sor.classList.remove('sdh-cimsor--kitoltve');
        }, 700);
    }

    /** Egy listaelem kiválasztása: kitölti a párt, vagy a név kódjait kínálja fel. */
    function iszValaszt(tetel) {
        var sor = iszAllapot && iszAllapot.sor;

        if (!sor || !tetel) {
            return;
        }

        if (tetel.csoport) {
            var mezok = iszMezok(sor);

            if (mezok.nev) {
                mezok.nev.value = tetel.kitolt;
                mezok.nev.dispatchEvent(new Event('change', { bubbles: true }));
            }

            if (mezok.isz) {
                mezok.isz.value = '';
                mezok.isz.focus();
            }

            iszAllapot = { sor: sor, mezo: 'isz', talalatok: tetel.csoport, kijelolt: 0 };
            iszListaRajzol();

            return;
        }

        iszZar();
        iszKitolt(sor, tetel);

        var kovetkezo = sor.querySelector('input[name$="_cim"]');
        if (kovetkezo) {
            kovetkezo.focus();
        }
    }

    function iszTetelHtml(t) {
        var resz = t.resz
            ? ' <span class="sdh-isz__resz">' + szovegBiztonsagos(t.resz) + '</span>'
            : '';

        if (t.csoport) {
            return '<b></b><span class="sdh-isz__nev">' + szovegBiztonsagos(t.kitolt) + '</span>' +
                ' <i>' + t.csoport.length + ' irányítószám ›</i>';
        }

        return '<b>' + szovegBiztonsagos(t.isz) + '</b><span class="sdh-isz__nev">' +
            szovegBiztonsagos(t.nev) + '</span>' + resz;
    }

    function iszListaRajzol() {
        if (!iszAllapot) {
            return;
        }

        if (!iszLista) {
            iszLista = document.createElement('ul');
            iszLista.className = 'sdh-isz__lista';
            iszLista.setAttribute('role', 'listbox');
            iszLista.hidden = true;

            // Egérkattintásnál ne vegye el a fókuszt a mezőtől.
            iszLista.addEventListener('mousedown', function (esemeny) {
                esemeny.preventDefault();
            });

            iszLista.addEventListener('click', function (esemeny) {
                var li = esemeny.target.closest('[data-sdh-isz-tetel]');

                if (!li || !iszAllapot) {
                    return;
                }

                iszValaszt(iszAllapot.talalatok[parseInt(li.dataset.sdhIszTetel, 10)]);
            });

            document.body.appendChild(iszLista);
        }

        // A lista a popup (<dialog>) tetején jelenik meg, ezért a dialóguson belül kell lennie.
        var szulo = iszAllapot.sor.closest('dialog') || document.body;

        if (iszLista.parentNode !== szulo) {
            szulo.appendChild(iszLista);
        }

        var mezok = iszMezok(iszAllapot.sor);
        var mezo = iszAllapot.mezo === 'isz' ? mezok.isz : mezok.nev;

        if (!mezo || iszAllapot.talalatok.length === 0) {
            iszZar();
            return;
        }

        iszLista.innerHTML = iszAllapot.talalatok.map(function (t, i) {
            return '<li role="option" data-sdh-isz-tetel="' + i + '"' +
                (i === iszAllapot.kijelolt ? ' class="is-kijelolt" aria-selected="true"' : '') + '>' +
                iszTetelHtml(t) + '</li>';
        }).join('');

        var hely = mezo.getBoundingClientRect();
        var szuloHely = szulo === document.body ? { left: 0, top: 0 } : szulo.getBoundingClientRect();

        iszLista.style.left = Math.round(hely.left - szuloHely.left) + 'px';
        iszLista.style.top = Math.round(hely.bottom - szuloHely.top + 2) + 'px';
        iszLista.style.minWidth = Math.max(240, Math.round(hely.width)) + 'px';
        iszLista.hidden = false;

        var kijelolt = iszLista.querySelector('.is-kijelolt');
        if (kijelolt && kijelolt.scrollIntoView) {
            kijelolt.scrollIntoView({ block: 'nearest' });
        }
    }

    /** A lista megnyitása; a kijelölt sor az, amelyik most a mezőkben áll. */
    function iszListaNyit(sor, mezo, talalatok) {
        var mezok = iszMezok(sor);
        var most = iszNorm(mezok.nev && mezok.nev.value);
        var szamMost = mezok.isz ? mezok.isz.value.replace(/\s+/g, '') : '';
        var kijelolt = 0;

        talalatok.forEach(function (t, i) {
            if (!t.csoport && t.nk === most && t.isz === szamMost) {
                kijelolt = i;
            }
        });

        iszAllapot = { sor: sor, mezo: mezo, talalatok: talalatok, kijelolt: kijelolt };
        iszListaRajzol();
    }

    /** Egy mező módosítása után: kitöltés vagy lista. */
    function iszValtozas(mezo) {
        var sor = mezo.closest('[data-sdh-cimsor]');

        if (!sor) {
            return;
        }

        var melyik = mezo.hasAttribute('data-sdh-isz') ? 'isz' : 'nev';
        var mezok = iszMezok(sor);
        var ertek = mezo.value.trim();

        iszBetolt().then(function (adat) {
            // Közben a mező változhatott vagy elveszett a fókusz.
            if (mezo.value.trim() !== ertek || document.activeElement !== mezo) {
                return;
            }

            if (melyik === 'isz') {
                var szam = ertek.replace(/\s+/g, '');
                var pontos = /^\d{4}$/.test(szam) ? adat.iszSzerint[szam] : null;

                if (pontos && pontos.length === 1) {
                    iszZar();
                    iszKitolt(sor, pontos[0]);
                    return;
                }

                if (pontos && pontos.length > 1) {
                    var mostani = iszNorm(mezok.nev && mezok.nev.value);
                    var egyezo = pontos.filter(function (t) { return t.nk === mostani; });

                    // Ha a település már be van írva és ehhez a kódhoz tartozik, békén hagyjuk.
                    if (egyezo.length === 1) {
                        iszZar();
                        return;
                    }

                    // Egy település több része ugyanazon a kódon: a fő település
                    // azonnal bekerül, a részek közül a listából lehet pontosítani.
                    var fok = {};
                    pontos.forEach(function (t) { fok[t.fo] = true; });

                    if (Object.keys(fok).length === 1) {
                        var fo = pontos.filter(function (t) { return !t.resz; })[0] || pontos[0];
                        iszKitolt(sor, fo);
                    }

                    iszListaNyit(sor, 'isz', pontos);
                    return;
                }

                iszListaNyit(sor, 'isz', iszKeres(adat, 'isz', szam, 8));
                return;
            }

            // Település mező.
            var kulcs = iszNorm(ertek);
            var pontosNev = kulcs !== '' ? adat.nevSzerint[kulcs] : null;

            if (pontosNev && pontosNev.length === 1) {
                // Egyetlen irányítószámú név: kitöltjük az irányítószámot.
                iszKitolt(sor, pontosNev[0], true);

                // Ha a településnek vannak részei (pl. Veszprém – Kádárta),
                // a lista nyitva marad, hogy pontosítani lehessen.
                var csalad = adat.foSzerint[pontosNev[0].fo] || [];

                if (!pontosNev[0].resz && csalad.length > 1 && csalad.length <= 40) {
                    iszListaNyit(sor, 'nev', csalad);
                } else {
                    iszZar();
                }

                return;
            }

            if (pontosNev && pontosNev.length > 1) {
                var szamMost = mezok.isz ? mezok.isz.value.replace(/\s+/g, '') : '';
                var jo = pontosNev.filter(function (t) { return t.isz === szamMost; });

                if (jo.length === 1) {
                    iszZar();
                    return;
                }

                // Pontos név, több irányítószám: csak ennek a névnek a kódjait mutatjuk.
                iszListaNyit(sor, 'nev', pontosNev);
                return;
            }

            iszListaNyit(sor, 'nev', iszKeres(adat, 'nev', ertek, 12));
        }).catch(function () {
            iszZar();
        });
    }

    document.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target;

        if (mezo && mezo.matches && mezo.matches('[data-sdh-isz], [data-sdh-telepules]')) {
            iszValtozas(mezo);
        }
    });

    document.addEventListener('focusin', function (esemeny) {
        var mezo = esemeny.target;

        if (mezo && mezo.matches && mezo.matches('[data-sdh-isz], [data-sdh-telepules]')) {
            // Már az első érintésnél elkezdjük letölteni, hogy gépeléskor kész legyen.
            iszBetolt().catch(function () {});
        }
    });

    document.addEventListener('focusout', function (esemeny) {
        var mezo = esemeny.target;

        // Kilépéskor a Település mező a hivatalos alakot kapja (ékezet,
        // kötőjel: „veszprem kadarta” → „Veszprém-Kádárta”), ha egyértelmű.
        if (mezo && mezo.matches && mezo.matches('[data-sdh-telepules]') && iszAdat) {
            var talalt = iszAdat.nevSzerint[iszNorm(mezo.value)];

            if (talalt && talalt.length && mezo.value !== talalt[0].kitolt) {
                mezo.value = talalt[0].kitolt;
                mezo.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        if (mezo && mezo.matches && mezo.matches('[data-sdh-isz], [data-sdh-telepules]')) {
            window.setTimeout(function () {
                if (!document.activeElement || !document.activeElement.matches ||
                    !document.activeElement.matches('[data-sdh-isz], [data-sdh-telepules]')) {
                    iszZar();
                }
            }, 120);
        }
    });

    document.addEventListener('keydown', function (esemeny) {
        if (!iszAllapot || !iszLista || iszLista.hidden) {
            return;
        }

        var mezo = esemeny.target;

        if (!mezo || !mezo.matches || !mezo.matches('[data-sdh-isz], [data-sdh-telepules]')) {
            return;
        }

        var db = iszAllapot.talalatok.length;

        if (esemeny.key === 'ArrowDown') {
            esemeny.preventDefault();
            iszAllapot.kijelolt = (iszAllapot.kijelolt + 1) % db;
            iszListaRajzol();
        } else if (esemeny.key === 'ArrowUp') {
            esemeny.preventDefault();
            iszAllapot.kijelolt = (iszAllapot.kijelolt - 1 + db) % db;
            iszListaRajzol();
        } else if (esemeny.key === 'Enter') {
            esemeny.preventDefault();

            iszValaszt(iszAllapot.talalatok[iszAllapot.kijelolt]);
        } else if (esemeny.key === 'Escape') {
            // A popup se záródjon be a lista helyett.
            esemeny.preventDefault();
            esemeny.stopPropagation();
            iszZar();
        }
    }, true);

    /* ---------------------------------------------------------------- */
    /* Csatolt fájlok                                                   */
    /* ---------------------------------------------------------------- */

    function csatMeret(bajt) {
        if (bajt >= 1048576) {
            return (bajt / 1048576).toFixed(1).replace('.', ',') + ' MB';
        }

        return Math.max(1, Math.round(bajt / 1024)) + ' KB';
    }

    function csatKiterjesztes(nev) {
        var pont = String(nev).lastIndexOf('.');

        return pont > -1 ? String(nev).slice(pont + 1).toLowerCase() : '';
    }

    function csatBekot(doboz) {
        if (doboz.dataset.sdhCsatKesz) {
            return;
        }

        doboz.dataset.sdhCsatKesz = '1';

        var input = doboz.querySelector('[data-sdh-csat-input]');
        var lista = doboz.querySelector('[data-sdh-csat-lista]');
        var zona = doboz.querySelector('[data-sdh-csat-zona]');
        var figyelem = doboz.querySelector('[data-sdh-csat-figyelem]');
        var maxFajl = parseInt(doboz.dataset.maxFajl, 10) || 0;
        var maxOsszes = parseInt(doboz.dataset.maxOsszes, 10) || 0;
        var engedett = (doboz.dataset.engedett || '').split(',');
        var varakozo = []; // a még fel nem töltött, kiválasztott fájlok

        function jelez(szoveg) {
            if (!figyelem) {
                return;
            }

            figyelem.textContent = szoveg || '';
            figyelem.hidden = !szoveg;
        }

        function frissit() {
            // A böngészőnek csak az input.files adja át a fájlokat az űrlapnak.
            var gyujto = new DataTransfer();
            varakozo.forEach(function (elem) {
                gyujto.items.add(elem.fajl);
            });
            input.files = gyujto.files;

            doboz.classList.toggle('sdh-csat--ures', lista.children.length === 0);
        }

        function ujElem(fajl) {
            var ext = csatKiterjesztes(fajl.name);
            var li = document.createElement('li');
            li.className = 'sdh-csat__elem sdh-csat__elem--uj';
            li.setAttribute('data-sdh-csat-uj', '');

            var kep = document.createElement('span');
            kep.className = 'sdh-csat__kep';

            var url = null;

            if (/^image\/(jpeg|png|gif|webp)$/.test(fajl.type)) {
                url = URL.createObjectURL(fajl);

                var img = document.createElement('img');
                img.alt = '';
                img.src = url;
                kep.appendChild(img);
            } else {
                var ikon = document.createElement('span');
                ikon.className = 'sdh-csat__ikon';
                ikon.textContent = (ext || 'fájl').toUpperCase();
                kep.appendChild(ikon);
            }

            var nev = document.createElement('span');
            nev.className = 'sdh-csat__nev';
            nev.textContent = fajl.name;
            nev.title = fajl.name;

            var meta = document.createElement('span');
            meta.className = 'sdh-csat__meta';
            meta.textContent = csatMeret(fajl.size) + ' · mentéskor kerül fel';

            var muv = document.createElement('span');
            muv.className = 'sdh-csat__muveletek';

            var torol = document.createElement('button');
            torol.type = 'button';
            torol.className = 'sdh-csat__gomb sdh-csat__gomb--torol';
            torol.title = 'Mégsem csatolom';
            torol.setAttribute('aria-label', 'Mégsem csatolom');
            torol.textContent = '×';
            muv.appendChild(torol);

            li.appendChild(kep);
            li.appendChild(nev);
            li.appendChild(meta);
            li.appendChild(muv);

            var elem = { fajl: fajl, li: li, url: url };

            torol.addEventListener('click', function () {
                varakozo = varakozo.filter(function (x) { return x !== elem; });
                if (elem.url) {
                    URL.revokeObjectURL(elem.url);
                }
                li.remove();
                jelez('');
                frissit();
            });

            return elem;
        }

        function hozzaad(fajlok) {
            var hibak = [];
            var osszes = varakozo.reduce(function (o, x) { return o + x.fajl.size; }, 0);

            Array.prototype.forEach.call(fajlok, function (fajl) {
                var ext = csatKiterjesztes(fajl.name);

                if (engedett.indexOf(ext) === -1) {
                    hibak.push('„' + fajl.name + '” típusa nem engedélyezett.');
                } else if (maxFajl && fajl.size > maxFajl) {
                    hibak.push('„' + fajl.name + '” túl nagy (legfeljebb ' + csatMeret(maxFajl) + ').');
                } else if (maxOsszes && osszes + fajl.size > maxOsszes) {
                    hibak.push('„' + fajl.name + '” már nem fér bele egy mentésbe (összesen legfeljebb ' + csatMeret(maxOsszes) + ').');
                } else if (varakozo.some(function (x) {
                    return x.fajl.name === fajl.name && x.fajl.size === fajl.size && x.fajl.lastModified === fajl.lastModified;
                })) {
                    // Ugyanaz a fájl kétszer: csendben kihagyjuk.
                } else {
                    var elem = ujElem(fajl);
                    varakozo.push(elem);
                    lista.appendChild(elem.li);
                    osszes += fajl.size;
                }
            });

            jelez(hibak.join(' '));
            frissit();
        }

        input.addEventListener('change', function () {
            // A böngésző az input.files-t felülírja, ezért a mostani választást
            // átmásoljuk a gyűjtőbe, és a régieket visszatesszük.
            var uj = Array.prototype.slice.call(input.files);
            var regi = varakozo.map(function (x) { return x.fajl; });
            var azonos = uj.length === regi.length && uj.every(function (f, i) { return f === regi[i]; });

            if (azonos) {
                return;
            }

            hozzaad(uj.filter(function (f) { return regi.indexOf(f) === -1; }));
        });

        doboz.querySelector('[data-sdh-csat-hozzaad]').addEventListener('click', function () {
            input.click();
        });

        // Az üres felületre kattintva is megnyílik a tallózó.
        zona.addEventListener('click', function (esemeny) {
            if (esemeny.target.closest('[data-sdh-csat-elem], [data-sdh-csat-uj], button, a')) {
                return;
            }

            input.click();
        });

        // Húzás és ejtés.
        ['dragenter', 'dragover'].forEach(function (nev) {
            zona.addEventListener(nev, function (esemeny) {
                if (esemeny.dataTransfer && Array.prototype.indexOf.call(esemeny.dataTransfer.types, 'Files') > -1) {
                    esemeny.preventDefault();
                    zona.classList.add('sdh-csat__zona--huzas');
                }
            });
        });

        ['dragleave', 'dragend'].forEach(function (nev) {
            zona.addEventListener(nev, function (esemeny) {
                if (!zona.contains(esemeny.relatedTarget)) {
                    zona.classList.remove('sdh-csat__zona--huzas');
                }
            });
        });

        zona.addEventListener('drop', function (esemeny) {
            esemeny.preventDefault();
            zona.classList.remove('sdh-csat__zona--huzas');

            if (esemeny.dataTransfer && esemeny.dataTransfer.files.length) {
                hozzaad(esemeny.dataTransfer.files);
            }
        });

        // Meglévő fájl törlésre jelölése (mentéskor törlődik); újra kattintva visszavonható.
        lista.addEventListener('click', function (esemeny) {
            var gomb = esemeny.target.closest('[data-sdh-csat-torol]');

            if (!gomb) {
                return;
            }

            var li = gomb.closest('[data-sdh-csat-elem]');
            var rejtett = li.querySelector('input[name="torol_csatolmany[]"]');
            var jelolt = li.classList.toggle('sdh-csat__elem--torolve');

            rejtett.disabled = !jelolt;
            gomb.textContent = jelolt ? '↺' : '×';
            gomb.title = jelolt ? 'Mégsem törlöm' : 'Eltávolítás mentéskor';
            gomb.setAttribute('aria-label', gomb.title);
        });

        // Nézetváltó.
        var nezetek = doboz.querySelectorAll('[data-sdh-csat-nezet]');

        Array.prototype.forEach.call(nezetek, function (gomb) {
            gomb.addEventListener('click', function () {
                doboz.dataset.nezet = gomb.dataset.sdhCsatNezet;

                Array.prototype.forEach.call(nezetek, function (g) {
                    g.setAttribute('aria-pressed', g === gomb ? 'true' : 'false');
                });

                try {
                    window.localStorage.setItem('sdhCsatNezet', gomb.dataset.sdhCsatNezet);
                } catch (e) { /* privát ablak: nem baj */ }
            });
        });

        try {
            var mentett = window.localStorage.getItem('sdhCsatNezet');
            var gombMentett = mentett ? doboz.querySelector('[data-sdh-csat-nezet="' + mentett + '"]') : null;

            if (gombMentett) {
                gombMentett.click();
            }
        } catch (e) { /* nincs tároló: marad az ikon nézet */ }

        frissit();
    }

    function csatKeres(gyoker) {
        Array.prototype.forEach.call(
            (gyoker || document).querySelectorAll('[data-sdh-csat]'),
            csatBekot
        );
    }

    document.addEventListener('DOMContentLoaded', function () {
        csatKeres(document);
    });

    if (document.readyState !== 'loading') {
        csatKeres(document);
    }

    /* ---------------------------------------------------------------- */
    /* Számmezők: modern fel/le léptető                                 */
    /* ---------------------------------------------------------------- */

    var SZAM_NYIL_FEL = '<svg viewBox="0 0 10 10" aria-hidden="true"><path d="M2.2 6.4 5 3.6l2.8 2.8" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var SZAM_NYIL_LE = '<svg viewBox="0 0 10 10" aria-hidden="true"><path d="M2.2 3.6 5 6.4l2.8-2.8" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    /** A gombok tiltása a mező határain (min / max). */
    function szamHatar(input, doboz) {
        var gombok = doboz.querySelectorAll('.sdh-szam__gombok button');
        var ertek = parseFloat(input.value);
        var min = input.min !== '' ? parseFloat(input.min) : null;
        var max = input.max !== '' ? parseFloat(input.max) : null;

        if (gombok.length === 2) {
            gombok[0].disabled = input.disabled || input.readOnly || (max !== null && !isNaN(ertek) && ertek >= max);
            gombok[1].disabled = input.disabled || input.readOnly || (min !== null && !isNaN(ertek) && ertek <= min);
        }
    }

    function szamLep(input, irany) {
        try {
            if (irany > 0) {
                input.stepUp();
            } else {
                input.stepDown();
            }
        } catch (e) {
            // Érvénytelen kiinduló érték: a lépésköz szerint indulunk.
            input.value = input.min !== '' ? input.min : '0';
        }

        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function szamBekot(input) {
        if (input.dataset.sdhSzamKesz) {
            return;
        }

        input.dataset.sdhSzamKesz = '1';

        var doboz = input.parentElement && input.parentElement.classList.contains('sdh-szam')
            ? input.parentElement
            : null;

        if (!doboz) {
            doboz = document.createElement('span');
            doboz.className = 'sdh-szam';
            input.parentNode.insertBefore(doboz, input);
            doboz.appendChild(input);
        }

        doboz.classList.add('sdh-szam--kesz');

        var gombok = document.createElement('span');
        gombok.className = 'sdh-szam__gombok';
        gombok.innerHTML =
            '<button type="button" tabindex="-1" data-irany="1" aria-label="Növelés">' + SZAM_NYIL_FEL + '</button>' +
            '<button type="button" tabindex="-1" data-irany="-1" aria-label="Csökkentés">' + SZAM_NYIL_LE + '</button>';
        doboz.appendChild(gombok);

        var idozito = null;
        var ismetlo = null;

        function megall() {
            window.clearTimeout(idozito);
            window.clearInterval(ismetlo);
            idozito = null;
            ismetlo = null;
        }

        gombok.addEventListener('pointerdown', function (esemeny) {
            var gomb = esemeny.target.closest('button');

            if (!gomb || gomb.disabled || esemeny.button !== 0) {
                return;
            }

            // A fókusz a mezőn marad, a gomb nem veszi el.
            esemeny.preventDefault();
            input.focus();

            var irany = parseInt(gomb.dataset.irany, 10);

            szamLep(input, irany);
            megall();

            // Nyomva tartva: kis szünet után gyorsan léptet tovább.
            idozito = window.setTimeout(function () {
                ismetlo = window.setInterval(function () {
                    if (gomb.disabled) {
                        megall();
                        return;
                    }

                    szamLep(input, irany);
                }, 60);
            }, 400);
        });

        ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (nev) {
            gombok.addEventListener(nev, megall);
        });

        input.addEventListener('input', function () {
            szamHatar(input, doboz);
        });

        szamHatar(input, doboz);
    }

    function szamKeres(gyoker) {
        Array.prototype.forEach.call(
            (gyoker || document).querySelectorAll('input[type="number"]'),
            szamBekot
        );
    }

    document.addEventListener('DOMContentLoaded', function () {
        szamKeres(document);
    });

    if (document.readyState !== 'loading') {
        szamKeres(document);
    }

}());
