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

    /**
     * Az ablak gombjai a bal felső sarokban, macOS módra: piros = bezárás, zöld = teljes képernyő.
     * A jel rajz (nem betű), ezért minden gépen pontosan a kör közepén ül. Minden felugró ablak ezt használja.
     */
    function ablakGombok(teljes) {
        return '  <button type="button" class="sdh-modal__bezar" aria-label="Bezárás" title="Bezárás">' +
            '<svg viewBox="0 0 12 12" aria-hidden="true"><path d="M3.6 3.6l4.8 4.8M8.4 3.6l-4.8 4.8"/></svg></button>' +
            (teljes
                ? '  <button type="button" class="sdh-modal__teljes" aria-label="Teljes képernyő" title="Teljes képernyő">' +
                    '<svg class="sdh-modal__teljes-be" viewBox="0 0 12 12" aria-hidden="true"><path d="M3.2 6.6V3.2h3.4zM8.8 5.4v3.4H5.4z"/></svg>' +
                    '<svg class="sdh-modal__teljes-ki" viewBox="0 0 12 12" aria-hidden="true"><path d="M5.6 2.4v3.2H2.4zM6.4 9.6V6.4h3.2z"/></svg>' +
                    '</button>'
                : '');
    }

    function vaz(n) {
        if (szintek[n]) {
            return szintek[n];
        }

        var dialog = document.createElement('dialog');
        dialog.className = 'sdh-modal' + (n > 0 ? ' sdh-modal--ralepo' : '');
        dialog.innerHTML =
            '<div class="sdh-modal__doboz">' +
            ablakGombok(true) +
            '  <div class="sdh-modal__torzs"></div>' +
            // Méretező fogók a négy szélen és a négy sarkon (data-x / data-y: melyik irányba húz).
            '  <span class="sdh-modal__fogo sdh-modal__fogo--b" data-x="-1" data-y="0"></span>' +
            '  <span class="sdh-modal__fogo sdh-modal__fogo--j" data-x="1" data-y="0"></span>' +
            '  <span class="sdh-modal__fogo sdh-modal__fogo--f" data-x="0" data-y="-1"></span>' +
            '  <span class="sdh-modal__fogo sdh-modal__fogo--a" data-x="0" data-y="1"></span>' +
            '  <span class="sdh-modal__fogo sdh-modal__fogo--bf" data-x="-1" data-y="-1"></span>' +
            '  <span class="sdh-modal__fogo sdh-modal__fogo--jf" data-x="1" data-y="-1"></span>' +
            '  <span class="sdh-modal__fogo sdh-modal__fogo--ba" data-x="-1" data-y="1"></span>' +
            '  <span class="sdh-modal__fogo sdh-modal__fogo--ja" data-x="1" data-y="1"></span>' +
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

        meretezhetoveTesz(dialog);

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
        meretBeallit(dialog, 1);

        var termeszetes = dialog.getBoundingClientRect().height;
        var szabad = window.innerHeight - MERET_SZEL;

        // A class marad: a popupnak nincs max-magassága és görgetése, csak zoomja.
        if (termeszetes > szabad + 0.5 && termeszetes > 0) {
            var zoom = Math.max(ILLESZT_MIN, szabad / termeszetes);

            // A kért (vagy teljes képernyős) méret a kicsinyítés UTÁNI méret:
            // a popupot annyival szélesebbre vesszük, amennyivel a zoom összehúzza.
            if (dialog.sdhMeret) {
                meretBeallit(dialog, zoom);
                termeszetes = dialog.getBoundingClientRect().height;
                zoom = Math.max(ILLESZT_MIN, Math.min(zoom, szabad / termeszetes));
            }

            dialog.style.zoom = String(zoom);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Popup méretezése és teljes képernyő                              */
    /* ---------------------------------------------------------------- */
    /*
     * Minden űrlap-popup (a vaz() által létrehozott ablak) húzással
     * méretezhető és teljes képernyőre tehető – új modulhoz nem kell semmi.
     * A méret űrlapfajtánként (modulonként) megmarad a böngészőben.
     *
     * A popup középen áll, ezért a méretezés a közepe körül szimmetrikus:
     * a megfogott szél követi az egeret, a szemközti ugyanannyit mozdul.
     * A magasság csak nőhet a tartalom fölé – görgetősáv így sincs.
     */
    var MERET_SZEL = 24;
    var MERET_MIN_SZELES = 420;

    function meretKulcs(modul) {
        return 'sdh-popup:' + (modul || 'altalanos');
    }

    function meretOlvas(modul) {
        try {
            var m = JSON.parse(window.localStorage.getItem(meretKulcs(modul)) || 'null');

            if (m && typeof m === 'object') {
                return {
                    w: Math.max(0, parseInt(m.w, 10) || 0),
                    h: Math.max(0, parseInt(m.h, 10) || 0),
                    teljes: !!m.teljes
                };
            }
        } catch (e) { /* nincs tárhely vagy sérült érték: alapméret */ }

        return null;
    }

    function meretIr(modul, meret) {
        try {
            if (meret && (meret.w || meret.h || meret.teljes)) {
                window.localStorage.setItem(meretKulcs(modul), JSON.stringify(meret));
            } else {
                window.localStorage.removeItem(meretKulcs(modul));
            }
        } catch (e) { /* nincs tárhely: a méret erre a megnyitásra szól */ }
    }

    /**
     * A popupra írja a kért méretet. A `zoom` az a kicsinyítés, amit az
     * illesztPopup utána alkalmazni fog: a méretet azzal előre visszaosztjuk.
     * Csak a méretezhető popupokhoz nyúl (a választó popupok mérete a CSS-é).
     */
    function meretBeallit(dialog, zoom) {
        var gomb = dialog.querySelector('.sdh-modal__teljes');

        if (!gomb) {
            return;
        }

        var m = dialog.sdhMeret;
        var doboz = dialog.querySelector('.sdh-modal__doboz');
        var teljes = !!(m && m.teljes);
        var maxSz = window.innerWidth - MERET_SZEL;
        var maxM = window.innerHeight - MERET_SZEL;
        var w = 0;
        var h = 0;

        if (teljes) {
            w = maxSz;
            h = maxM;
        } else if (m) {
            w = m.w ? Math.min(maxSz, Math.max(MERET_MIN_SZELES, m.w)) : 0;
            h = m.h ? Math.min(maxM, m.h) : 0;
        }

        dialog.style.width = w ? (w / zoom) + 'px' : '';

        if (doboz) {
            doboz.style.minHeight = h ? (h / zoom) + 'px' : '';
        }

        dialog.classList.toggle('sdh-modal--meretezett', h > 0);
        dialog.classList.toggle('sdh-modal--teljes', teljes);

        var felirat = teljes ? 'Vissza ablakméretre' : 'Teljes képernyő';

        gomb.setAttribute('aria-label', felirat);
        gomb.setAttribute('title', felirat);
        gomb.setAttribute('aria-pressed', teljes ? 'true' : 'false');
    }

    function teljesValt(dialog) {
        var m = dialog.sdhMeret || { w: 0, h: 0, teljes: false };

        m = { w: m.w || 0, h: m.h || 0, teljes: !m.teljes };

        dialog.sdhMeret = (m.w || m.h || m.teljes) ? m : null;
        meretIr(dialog.sdhModul, dialog.sdhMeret);
        illesztPopup(dialog);
    }

    function meretezhetoveTesz(dialog) {
        dialog.querySelector('.sdh-modal__teljes').addEventListener('click', function () {
            teljesValt(dialog);
        });

        dialog.addEventListener('dblclick', function (esemeny) {
            // Dupla kattintás a címsoron: teljes képernyő ki/be – ahogy egy ablaknál.
            if (esemeny.target.closest('.sdh-modal__cim')) {
                teljesValt(dialog);

                return;
            }

            // Dupla kattintás egy fogón: vissza az alapméretre.
            if (esemeny.target.closest('.sdh-modal__fogo')) {
                dialog.sdhMeret = null;
                meretIr(dialog.sdhModul, null);
                illesztPopup(dialog);
            }
        });

        dialog.addEventListener('mousedown', function (esemeny) {
            var fogo = esemeny.target.closest('.sdh-modal__fogo');

            if (!fogo || esemeny.button !== 0) {
                return;
            }

            esemeny.preventDefault();

            var ix = parseInt(fogo.getAttribute('data-x'), 10) || 0;
            var iy = parseInt(fogo.getAttribute('data-y'), 10) || 0;
            var r = dialog.getBoundingClientRect();
            var kozepX = r.left + r.width / 2;
            var kozepY = r.top + r.height / 2;
            // A fogót nem pontosan a szélén fogjuk meg: ennyivel beljebb. E nélkül
            // az ablak az első mozdulatra ugrana egyet.
            var eltX = r.width / 2 - Math.abs(esemeny.clientX - kozepX);
            var eltY = r.height / 2 - Math.abs(esemeny.clientY - kozepY);
            var elozo = dialog.sdhMeret || {};
            // Teljes képernyőről indulva a mostani látható méret a kiindulás.
            var m = {
                w: elozo.teljes ? Math.round(r.width) : (elozo.w || 0),
                h: elozo.teljes ? Math.round(r.height) : (elozo.h || 0),
                teljes: false
            };
            var kep = 0;
            var mozdult = false;

            document.body.style.cursor = window.getComputedStyle(fogo).cursor;

            function mozog(e) {
                mozdult = true;

                if (ix) {
                    m.w = Math.round(Math.min(
                        window.innerWidth - MERET_SZEL,
                        Math.max(MERET_MIN_SZELES, 2 * (Math.abs(e.clientX - kozepX) + eltX))
                    ));
                }

                if (iy) {
                    m.h = Math.round(Math.min(
                        window.innerHeight - MERET_SZEL,
                        Math.max(120, 2 * (Math.abs(e.clientY - kozepY) + eltY))
                    ));
                }

                dialog.sdhMeret = m;

                window.cancelAnimationFrame(kep);
                kep = window.requestAnimationFrame(function () {
                    illesztPopup(dialog);
                });
            }

            function vege() {
                document.removeEventListener('mousemove', mozog);
                document.removeEventListener('mouseup', vege);
                document.body.style.cursor = '';

                if (mozdult) {
                    window.cancelAnimationFrame(kep);
                    meretIr(dialog.sdhModul, m);
                    illesztPopup(dialog);
                }
            }

            document.addEventListener('mousemove', mozog);
            document.addEventListener('mouseup', vege);
        });
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

        // A popup az ehhez az űrlapfajtához legutóbb beállított méretben nyílik.
        szint.dialog.sdhModul = modul;
        szint.dialog.sdhMeret = meretOlvas(modul);
        szint.dialog.setAttribute('data-sdh-modul', modul);

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
                munkalapIndul(szint.torzs);

                // Kért lapfül (data-sdh-ful): a rádiógomb bejelölése váltja a fület.
                var kertFul = opciok.ful
                    ? szint.torzs.querySelector('.sdh-fulek__ful[data-sdh-ful-kulcs="' + String(opciok.ful).replace(/[^a-z0-9_-]/gi, '') + '"]')
                    : null;

                if (kertFul) {
                    kertFul.checked = true;
                    kertFul.dispatchEvent(new Event('change', { bubbles: true }));
                }

                // Más modulok (pl. rma.js) itt kapcsolódhatnak a betöltött űrlaphoz.
                document.dispatchEvent(new CustomEvent('sdh:urlap-betoltve', {
                    detail: { modul: modul, id: id, torzs: szint.torzs, ful: opciok.ful || '' }
                }));

                var kertSzam = kertFul ? (kertFul.className.match(/sdh-fulek__ful--(\d+)/) || [])[1] : '';
                var elso = kertSzam
                    ? kertFul.parentNode.querySelector(':scope > .sdh-fulek__panel--' + kertSzam + ' textarea')
                    : szint.torzs.querySelector('input:not([type="hidden"]), select, textarea');
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

    /**
     * Rövid, kötött lista választása felugró ablakban, „pill" gombokkal (a natív legördülő helyett).
     * o: { cim, elemek: [{ ertek, cimke, alcim }], aktualis, valaszt(ertek, elem) }
     * A horgony az az elem, amelyre a választás után a fókusz visszakerül.
     */
    function pillNyit(horgony, o) {
        var dialog = document.createElement('dialog');

        dialog.className = 'sdh-modal sdh-modal--pop sdh-modal--pop-pill';
        dialog.innerHTML = '<div class="sdh-modal__doboz">' + ablakGombok(false) + '  <div class="sdh-modal__torzs"></div></div>';
        document.body.appendChild(dialog);

        var torzs = dialog.querySelector('.sdh-modal__torzs');
        var lista = popElem('div', 'sdh-pillek sdh-pillek--oszlop');

        torzs.appendChild(popElem('h2', 'sdh-modal__cim', o.cim || 'Választás'));
        lista.setAttribute('role', 'listbox');

        (o.elemek || []).forEach(function (elem) {
            var gomb = popElem('button', 'sdh-pill' + (String(elem.ertek) === String(o.aktualis) ? ' is-aktiv' : ''));

            gomb.type = 'button';
            gomb.setAttribute('role', 'option');
            gomb.setAttribute('aria-selected', String(elem.ertek) === String(o.aktualis) ? 'true' : 'false');
            gomb.setAttribute('data-sdh-pill', String(elem.ertek));
            gomb.appendChild(popElem('span', 'sdh-pill__cimke', elem.cimke));

            if (elem.alcim) {
                gomb.appendChild(popElem('span', 'sdh-pill__alcim', elem.alcim));
            }

            gomb.addEventListener('click', function () {
                dialog.close();
                o.valaszt(elem.ertek, elem);
            });
            lista.appendChild(gomb);
        });

        torzs.appendChild(lista);

        dialog.querySelector('.sdh-modal__bezar').addEventListener('click', function () {
            dialog.close();
        });

        dialog.addEventListener('click', function (esemeny) {
            if (esemeny.target === dialog) {
                dialog.close();
            }
        });

        dialog.addEventListener('keydown', function (esemeny) {
            if (esemeny.key !== 'ArrowDown' && esemeny.key !== 'ArrowUp') {
                return;
            }

            var gombok = Array.prototype.slice.call(lista.querySelectorAll('.sdh-pill'));
            var i = gombok.indexOf(document.activeElement);

            esemeny.preventDefault();
            gombok[(i + (esemeny.key === 'ArrowDown' ? 1 : gombok.length - 1)) % gombok.length].focus();
        });

        dialog.addEventListener('close', function () {
            dialog.remove();

            if (horgony && horgony.focus) {
                horgony.focus();
            }
        });

        dialog.showModal();

        var aktiv = lista.querySelector('.sdh-pill.is-aktiv') || lista.querySelector('.sdh-pill');

        if (aktiv) {
            aktiv.focus();
        }
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
            datum: 'Dátum',
            termekkat: 'Kategória',
            beszallito: 'Beszállító'
        };

        // Bővíthető listák: csoportosított gombok + „saját érték" sor + törlés.
        var bovitheto = {
            gyarto: ['Más gyártó', 'Írd be a nevét', 'Gyártó törlése'],
            termekkat: ['Új kategória', 'Írd be a nevét', 'Kategória törlése'],
            beszallito: ['Más beszállító', 'Írd be a nevét', 'Beszállító törlése']
        };

        var dialog = document.createElement('dialog');
        dialog.className = 'sdh-modal sdh-modal--pop sdh-modal--pop-' + tipus;
        dialog.innerHTML =
            '<div class="sdh-modal__doboz">' +
            ablakGombok(false) +
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

        if (tipus === 'kategoria' || bovitheto[tipus]) {
            kereso = popKereso(targy, fej);
            torzs.appendChild(fej);
        }

        torzs.appendChild(targy);

        if (tipus === 'kategoria') {
            popCsoportok(targy, adat, mezo.value, valasztott);
        } else if (bovitheto[tipus]) {
            popCsoportok(targy, adat, mezo.value, valasztott);

            var alj = popElem('div', 'sdh-pop__alj');
            popSajat(alj, bovitheto[tipus][0], bovitheto[tipus][1], '', valasztott);

            if (mezo.value) {
                var ures = popElem('button', 'sdh-gomb sdh-gomb--vilagos', bovitheto[tipus][2]);
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
            munkalapOsszefoglalo(urlap);

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
                    munkalapOsszefoglalo(urlap);

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

                munkalapOsszefoglalo(urlap);
            })
            .catch(function () {
                eloszor('Az eszközök nem töltődtek be');
            });
    }

    /* ---------------------------------------------------------------- */
    /* Munkalap-űrlap: összegek, tételek, előleg                        */
    /* ---------------------------------------------------------------- */
    /*
     * Az űrlap gépelés közben számol: tételsoronként a nettó és a bruttó
     * érték, lapfülenként az összesítő, bal oldalt az Összeg (nettó, áfa,
     * bruttó) és a Fizetendő. A számolás ugyanaz, mint a szerveren
     * (SDH_Muhely_Tetel): a bruttó egységárból az áfakulccsal lesz nettó.
     * Ez csak kijelzés – mentéskor a szerver számol újra, az a mérvadó.
     */

    /** Beírt szám: „13 000", „13000,5" és „13.000,50" is jó. Hibás érték: 0. */
    function mlSzam(szoveg) {
        var t = String(szoveg === null || szoveg === undefined ? '' : szoveg)
            .replace(/[\s\u00a0\u202f]|Ft|%/gi, '');

        if (t.indexOf(',') >= 0) {
            t = t.replace(/\./g, '');
        }

        t = t.replace(',', '.');

        var n = parseFloat(t);

        return isFinite(n) && /^-?\d*\.?\d*$/.test(t) ? n : 0;
    }

    function mlKerek(n) {
        return Math.round((n + Number.EPSILON) * 100) / 100;
    }

    /** Összeg kiírva: egész forint, ezres tagolással. */
    function mlPenz(n) {
        var e = Math.round(n);

        return (e < 0 ? '-' : '') + String(Math.abs(e)).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
    }

    function mlMenny(n) {
        return String(Math.round(n * 1000) / 1000).replace('.', ',');
    }

    function mlIr(elem, szoveg) {
        if (elem && elem.textContent !== szoveg) {
            elem.textContent = szoveg;
        }
    }

    function mlDarab(gyoker, kulcs, db) {
        var jel = gyoker.querySelector('[data-sdh-db="' + kulcs + '"]');

        if (jel) {
            jel.hidden = db === 0;
            jel.textContent = String(db);
        }
    }

    /** Egy tételsor kiszámolt értéke (az árat a hívó adhatja, pl. a levonás sorának). */
    function mlSorErtek(sor, arFelulir) {
        var mezo = function (nev) {
            return sor.querySelector('[data-sdh-tetel="' + nev + '"]');
        };
        var nevMezo = sor.querySelector('input[name$="[megnevezes]"]');
        // Csak a díj levonásának sora lehet negatív.
        var ar = typeof arFelulir === 'number' ? arFelulir
            : (sor.hasAttribute('data-sdh-bevle') ? mlSzam(mezo('ar').value) : Math.max(0, mlSzam(mezo('ar').value)));
        var m = mezo('menny').value.trim() === '' ? 1 : mlSzam(mezo('menny').value);
        var kedv = Math.min(100, Math.max(0, mlSzam(mezo('kedv').value)));
        var afaOpcio = mezo('afa').options[mezo('afa').selectedIndex];
        var afa = afaOpcio ? parseFloat(afaOpcio.getAttribute('data-szazalek')) || 0 : 0;

        if (m <= 0) {
            m = 1;
        }

        // Az üres sor (se megnevezés, se ár) mentéskor eldobódik: nem számít bele.
        var ures = ((!nevMezo || nevMezo.value.trim() === '') && ar === 0) || sor.classList.contains('is-torlendo');
        var nettoAr = mlKerek(ar / (1 + afa / 100));

        return {
            ures: ures,
            m: m,
            netto: ures ? 0 : mlKerek(nettoAr * m * (1 - kedv / 100)),
            brutto: ures ? 0 : mlKerek(ar * m * (1 - kedv / 100))
        };
    }

    function munkalapSzamol(gyoker) {
        if (!gyoker) {
            return;
        }

        var osszNetto = 0;
        var osszBrutto = 0;

        // Bevizsgálási díj: bepipálva az előleg külön tételsor (+díj), és ha az
        // ügyfél kéri a javítást („Levonódik"), egy mínusz sor vonja le a
        // végösszegből – legfeljebb a többi tétel összegéig. A sorokat a
        // szerver tartja karban mentéskor; itt a kijelzés követi őket, és ha
        // még nincsenek meg, az összegekben már most számítanak.
        var bevPipa = gyoker.querySelector('[data-sdh-bevizsgalas]');
        var bevElolegMezo = gyoker.querySelector('[data-sdh-fizetett]');
        var bevEloleg = bevElolegMezo ? Math.max(0, mlSzam(bevElolegMezo.value)) : 0;
        var bevBe = !!(bevPipa && bevPipa.checked && bevEloleg > 0);
        var bevSor = gyoker.querySelector('[data-sdh-tetelsor][data-sdh-bev]');
        var bevSzamlazva = !!(bevSor && bevSor.getAttribute('data-sdh-bev') === 'szamlazva');
        var leSor = gyoker.querySelector('[data-sdh-tetelsor][data-sdh-bevle]');
        var leSzamlazva = !!(leSor && leSor.getAttribute('data-sdh-bevle') === 'szamlazva');
        var modJelolt = gyoker.querySelector('[data-sdh-bev-mod]:checked');
        var bevMod = modJelolt && modJelolt.value === 'marad' ? 'marad' : 'levon';

        if (bevSor && !bevSzamlazva) {
            var bevAr = bevSor.querySelector('[data-sdh-tetel="ar"]');

            if (bevBe && bevAr && document.activeElement !== bevAr && Math.abs(mlSzam(bevAr.value) - bevEloleg) >= 0.005) {
                bevAr.value = String(bevEloleg).replace('.', ',');
            }

            bevSor.classList.toggle('is-torlendo', !bevBe);
        }

        // A többi tétel (a díjsorok nélkül): ebből vonható le a díj.
        var tobbi = 0;

        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-tetelek] [data-sdh-tetelsorok] [data-sdh-tetelsor]'), function (sor) {
            if (!sor.hasAttribute('data-sdh-bev') && !sor.hasAttribute('data-sdh-bevle')) {
                tobbi += mlSorErtek(sor).brutto;
            }
        });

        var levon = bevBe && bevMod === 'levon' ? Math.min(bevEloleg, Math.max(0, tobbi)) : 0;

        if (leSor && !leSzamlazva) {
            var leAr = leSor.querySelector('[data-sdh-tetel="ar"]');

            if (leAr) {
                leAr.value = levon > 0 ? String(-levon).replace('.', ',') : '0';
            }

            leSor.classList.toggle('is-torlendo', levon <= 0);
        }

        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-tetelek]'), function (panel) {
            var menny = 0;
            var netto = 0;
            var brutto = 0;
            var db = 0;

            Array.prototype.forEach.call(panel.querySelectorAll('[data-sdh-tetelsorok] [data-sdh-tetelsor]'), function (sor) {
                var e = mlSorErtek(sor);

                mlIr(sor.querySelector('[data-sdh-tetel="netto"]'), mlPenz(e.netto));
                mlIr(sor.querySelector('[data-sdh-tetel="brutto"]'), mlPenz(e.brutto));

                if (!e.ures) {
                    db += 1;
                    menny += e.m;
                    netto += e.netto;
                    brutto += e.brutto;
                }
            });

            mlIr(panel.querySelector('[data-sdh-tetel-ossz="menny"]'), mlMenny(menny));
            mlIr(panel.querySelector('[data-sdh-tetel-ossz="netto"]'), mlPenz(netto));
            mlIr(panel.querySelector('[data-sdh-tetel-ossz="brutto"]'), mlPenz(brutto));
            mlDarab(gyoker, panel.getAttribute('data-sdh-tetelek'), db);

            osszNetto += netto;
            osszBrutto += brutto;
        });

        // Még nincs díjsor / levonássor (a mentés hozza létre): már most beleszámít.
        var bevLapAfa = gyoker.querySelector('[data-sdh-lap-afa]');
        var bevOpcio = bevLapAfa ? bevLapAfa.options[bevLapAfa.selectedIndex] : null;
        var bevSzazalek = bevOpcio ? parseFloat(bevOpcio.getAttribute('data-szazalek')) || 0 : 27;

        if (bevBe && !bevSor) {
            osszBrutto += bevEloleg;
            osszNetto += mlKerek(bevEloleg / (1 + bevSzazalek / 100));
        }

        if (levon > 0 && !leSor) {
            osszBrutto -= levon;
            osszNetto -= mlKerek(levon / (1 + bevSzazalek / 100));
        }

        bevInfo(gyoker, bevPipa, bevEloleg, bevMod, levon, tobbi);

        var hibaDb = 0;

        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-hibasorok] [data-sdh-hibasor]'), function (sor) {
            var leiras = sor.querySelector('.sdh-hibasor__leiras');
            var javitas = sor.querySelector('.sdh-hibasor__javitas');

            if ((leiras && leiras.value.trim() !== '') || (javitas && javitas.value.trim() !== '')) {
                hibaDb += 1;
            }
        });

        mlDarab(gyoker, 'hibak', hibaDb);

        // Az előleg (Fizetett) mindig levonódik a teljes összegből; a
        // kiegyenlített lapon nincs fizetendő.
        var fizetettMezo = gyoker.querySelector('[data-sdh-fizetett]');
        var fizetveMezo = gyoker.querySelector('[data-sdh-fizetve]');
        var fizetett = fizetettMezo ? Math.max(0, mlSzam(fizetettMezo.value)) : 0;
        var fizetve = !!(fizetveMezo && fizetveMezo.checked);
        var fizetendo = fizetve ? 0 : osszBrutto - fizetett;
        var kiiras = function (nev) {
            return gyoker.querySelector('[data-sdh-ossz="' + nev + '"]');
        };

        mlIr(kiiras('netto'), mlPenz(osszNetto));
        mlIr(kiiras('afa'), mlPenz(osszBrutto - osszNetto));
        mlIr(kiiras('brutto'), mlPenz(osszBrutto));
        mlIr(kiiras('fizetendo'), mlPenz(fizetendo));

        if (kiiras('fizetendo')) {
            kiiras('fizetendo').classList.toggle('is-negativ', fizetendo < 0);
        }
    }

    /** A bevizsgálási díj sora alatti magyarázat, és a „Ha javítás" választó láthatósága. */
    function bevInfo(gyoker, pipa, dij, mod, levon, tobbi) {
        var info = gyoker.querySelector('[data-sdh-bev-info]');
        var modDoboz = gyoker.querySelector('[data-sdh-bev-mod-doboz]');
        var nevMezo = gyoker.querySelector('[data-sdh-bev-nev]');
        var be = !!(pipa && pipa.checked);
        var szoveg = '';

        if (modDoboz) {
            modDoboz.hidden = !be;
        }

        if (be) {
            var nev = nevMezo && nevMezo.value ? nevMezo.value + ': ' : '';

            if (dij <= 0) {
                szoveg = 'Írd be a díjat a „Fizetett (előleg)” mezőbe, vagy válaszd a díjlistából.';
            } else if (mod === 'marad') {
                szoveg = nev + mlPenz(dij) + '\u00a0Ft – munkadíjként marad, nem vonódik le.';
            } else if (levon > 0) {
                szoveg = nev + mlPenz(dij) + '\u00a0Ft – levonódik a végösszegből: −' + mlPenz(levon) + '\u00a0Ft' +
                    (levon < dij ? ' (a többi tétel összegéig)' : '') + '.';
            } else {
                szoveg = nev + mlPenz(dij) + '\u00a0Ft – ha lesz javítás, levonódik belőle; addig munkadíj.';
            }
        }

        if (info) {
            info.textContent = szoveg;
            info.hidden = szoveg === '';
        }
    }

    /** Díj választása a díjlistából: az előleg, a jelölő és a díj neve egyszerre töltődik. */
    function bevListaNyit(gomb) {
        var gyoker = gomb.closest('[data-sdh-munkalap]');

        if (!gyoker) {
            return;
        }

        var talal = function (t, q) {
            if (!t.dij) {
                return false;
            }

            if (q === '') {
                return true;
            }

            var szoveg = iszNorm(t.kat + ' ' + t.nev + ' ' + t.kod);

            return q.split(/\s+/).every(function (szo) {
                return szoveg.indexOf(szo) !== -1;
            });
        };

        szValNyit(gomb, {
            cim: 'Bevizsgálási díj',
            alcim: 'Válaszd ki az eszköz díját: az előlegbe kerül, és tételként a munkalapra. Az árakat a Szolgáltatások › Árlista oldalon írhatod át.',
            helyorzo: 'Eszköz, díj vagy kód…',
            egyseg: 'díj',
            ures: 'Még nincs bevizsgálási díj. A Szolgáltatások › „Árlista beillesztése” gombbal viheted fel a díjlistát, vagy egy szolgáltatásnál bepipálhatod a „Bevizsgálási díj” jelölőt.',
            oszlopok: [['Díj', ''], ['Kód', '4.5rem'], ['Ár', '8rem', true]],
            forras: function (q) {
                return szolgBetolt().then(function (adat) {
                    var n = iszNorm(String(q || '').trim());

                    return adat.filter(function (t) {
                        return talal(t, n);
                    });
                });
            },
            cellak: function (t) {
                var rovid = t.kat && t.nev.indexOf(t.kat + ' – ') === 0 ? t.nev.slice(t.kat.length + 3) : t.nev;

                return [
                    (t.kat ? '<span class="sdh-bevval__kat">' + szovegBiztonsagos(t.kat) + '</span> ' : '') + szovegBiztonsagos(rovid),
                    '<span class="sdh-tabla__halvany">' + szovegBiztonsagos(t.kod || '') + '</span>',
                    arSzoveg(t)
                ];
            },
            valaszt: function (t) {
                var eloleg = gyoker.querySelector('[data-sdh-fizetett]');
                var pipa = gyoker.querySelector('[data-sdh-bevizsgalas]');
                var nev = gyoker.querySelector('[data-sdh-bev-nev]');
                var szolg = gyoker.querySelector('[data-sdh-bev-szolg]');

                if (eloleg) {
                    eloleg.value = String(t.ar || 0);
                }

                if (pipa) {
                    pipa.checked = true;
                }

                if (nev) {
                    nev.value = t.nev;
                }

                if (szolg) {
                    szolg.value = String(t.id);
                }

                munkalapSzamol(gyoker);

                if (eloleg) {
                    eloleg.dispatchEvent(new Event('change', { bubbles: true }));
                    eloleg.focus();
                }
            },
            kezi: function () {
                var eloleg = gyoker.querySelector('[data-sdh-fizetett]');
                var pipa = gyoker.querySelector('[data-sdh-bevizsgalas]');
                var nev = gyoker.querySelector('[data-sdh-bev-nev]');

                if (pipa) {
                    pipa.checked = true;
                }

                if (nev) {
                    nev.value = '';
                }

                munkalapSzamol(gyoker);

                if (eloleg) {
                    eloleg.focus();
                    eloleg.select();
                }
            }
        });
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-bev-lista]') : null;

        if (gomb) {
            esemeny.preventDefault();
            bevListaNyit(gomb);
        }
    });

    /** Új tételsor: a sablon klónja, a lap kedvezményével és áfakulcsával. */
    function tetelUj(gomb) {
        var panel = gomb.closest('[data-sdh-tetelek]');
        var lista = panel ? panel.querySelector('[data-sdh-tetelsorok]') : null;
        var sablon = panel ? panel.querySelector('[data-sdh-tetel-sablon]') : null;

        if (!lista || !sablon) {
            return null;
        }

        var index = parseInt(panel.getAttribute('data-kovetkezo') || '0', 10);

        panel.setAttribute('data-kovetkezo', String(index + 1));

        var tarolo = document.createElement('div');

        tarolo.innerHTML = sablon.innerHTML.split('__I__').join(String(index));

        var sor = tarolo.querySelector('[data-sdh-tetelsor]');

        if (!sor) {
            return null;
        }

        var gyoker = panel.closest('[data-sdh-munkalap]');
        var lapKedv = gyoker ? gyoker.querySelector('[data-sdh-lap-kedv]') : null;
        var lapAfa = gyoker ? gyoker.querySelector('[data-sdh-lap-afa]') : null;

        if (lapKedv && mlSzam(lapKedv.value) > 0) {
            sor.querySelector('[data-sdh-tetel="kedv"]').value = String(mlSzam(lapKedv.value)).replace('.', ',');
        }

        if (lapAfa) {
            sor.querySelector('[data-sdh-tetel="afa"]').value = lapAfa.value;
        }

        lista.appendChild(sor);
        munkalapSzamol(gyoker);

        var elso = sor.querySelector('input[type="text"]');

        if (elso) {
            elso.focus();
        }

        return sor;
    }

    /** Tételsor eltávolítása; az utolsó sort csak kiürítjük, hogy mindig legyen hová írni. */
    function tetelTorol(gomb) {
        var sor = gomb.closest('[data-sdh-tetelsor]');
        var lista = gomb.closest('[data-sdh-tetelsorok]');

        if (!sor || !lista) {
            return;
        }

        var gyoker = sor.closest('[data-sdh-munkalap]');

        if (lista.querySelectorAll('[data-sdh-tetelsor]').length <= 1) {
            Array.prototype.forEach.call(sor.querySelectorAll('input[type="text"]'), function (mezo) {
                mezo.value = mezo.name.slice(-11) === '[mennyiseg]' ? '1' : (mezo.name.slice(-4) === '[me]' ? 'db' : '');
            });
            // Az azonosító törlésével a mentés új sorként kezeli – a régi tétel törlődik.
            sor.querySelector('input[type="hidden"]').value = '0';

            // A kiürített sor a terméktörzshöz sem kötődik tovább.
            var termekAzonosito = sor.querySelector('[data-sdh-termek-id]');

            if (termekAzonosito) {
                termekAzonosito.value = '0';
            }
            sor.querySelector('.sdh-tetelsor__sorszam').textContent = 'új';
        } else {
            sor.remove();
        }

        munkalapSzamol(gyoker);
    }

    /** A lap kedvezménye vagy áfakulcsa megváltozott: minden tételsor követi. */
    function tetelAlapertek(mezo) {
        var gyoker = mezo.closest('[data-sdh-munkalap]');

        if (!gyoker) {
            return;
        }

        var kedv = mezo.hasAttribute('data-sdh-lap-kedv');
        var ertek = kedv ? Math.min(100, Math.max(0, mlSzam(mezo.value))) : mezo.value;

        Array.prototype.forEach.call(
            gyoker.querySelectorAll('[data-sdh-tetelsorok] [data-sdh-tetel="' + (kedv ? 'kedv' : 'afa') + '"]'),
            function (celmezo) {
                // A díj levonásának sora csak olvasható: a szerver tartja karban.
                if (celmezo.disabled) {
                    return;
                }

                celmezo.value = kedv ? (ertek > 0 ? String(ertek).replace('.', ',') : '') : ertek;
            }
        );

        munkalapSzamol(gyoker);
    }

    /** Az Eszköz lapfül: a kiválasztott eszköz és ügyfél adatai a szerverről. */
    function munkalapOsszefoglalo(urlap) {
        var hely = urlap ? urlap.querySelector('[data-sdh-osszefoglalo]') : null;

        if (!hely) {
            return;
        }

        var ugyfel = urlap.querySelector('.sdh-valaszto input[type="hidden"]');
        var eszkoz = urlap.querySelector('[data-sdh-eszkoz-valaszto]');
        var cim = new URL(beallitas.ajax, window.location.origin);

        cim.searchParams.set('action', 'sdh_muhely_munkalapok_osszefoglalo');
        cim.searchParams.set('ugyfel_id', ugyfel ? ugyfel.value : '0');
        cim.searchParams.set('eszkoz_id', eszkoz ? eszkoz.value : '0');
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        var sorszam = (hely.sdhKeres || 0) + 1;

        hely.sdhKeres = sorszam;

        fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (valasz) {
                if (!valasz.ok) {
                    throw new Error(String(valasz.status));
                }

                return valasz.text();
            })
            .then(function (html) {
                // Csak a legutóbbi kérés válasza kerül ki (gyors váltásnál se keveredjen).
                if (hely.sdhKeres === sorszam) {
                    hely.innerHTML = html;
                }
            })
            .catch(function () { /* az összefoglaló csak tájékoztat: hiba esetén a régi marad */ });
    }

    /** Frissen betöltött munkalap-űrlap: első számolás. */
    function munkalapIndul(gyoker) {
        Array.prototype.forEach.call((gyoker || document).querySelectorAll('[data-sdh-munkalap]'), munkalapSzamol);
    }

    document.addEventListener('input', function (esemeny) {
        var gyoker = esemeny.target.closest ? esemeny.target.closest('[data-sdh-munkalap]') : null;

        if (!gyoker) {
            return;
        }

        if (esemeny.target.hasAttribute('data-sdh-lap-kedv')) {
            tetelAlapertek(esemeny.target);

            return;
        }

        munkalapSzamol(gyoker);
    });

    document.addEventListener('change', function (esemeny) {
        var cel = esemeny.target;
        var gyoker = cel.closest ? cel.closest('[data-sdh-munkalap]') : null;

        if (!gyoker) {
            return;
        }

        if (cel.hasAttribute('data-sdh-lap-afa') || cel.hasAttribute('data-sdh-lap-kedv')) {
            tetelAlapertek(cel);

            return;
        }

        // Kifizetettre jelölve a fizetés napja a mai nap, ha még üres.
        if (cel.hasAttribute('data-sdh-fizetve') && cel.checked) {
            var nap = gyoker.querySelector('input[name="fizetes_ideje"]');

            if (nap && nap.value === '') {
                nap.value = gyoker.getAttribute('data-ma') || '';
            }
        }

        if (cel.hasAttribute('data-sdh-eszkoz-valaszto')) {
            munkalapOsszefoglalo(cel.closest('form'));
        }

        munkalapSzamol(gyoker);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            munkalapIndul(document);
        });
    } else {
        munkalapIndul(document);
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
        var doboz = gomb.closest('[data-sdh-hibak], .sdh-doboz');
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
        munkalapSzamol(lista.closest('[data-sdh-munkalap]'));

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
            munkalapSzamol(lista.closest('[data-sdh-munkalap]'));

            return;
        }

        sor.remove();
        munkalapSzamol(lista.closest('[data-sdh-munkalap]'));
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

        var tetelUjGomb = esemeny.target.closest('[data-sdh-tetel-uj]');

        if (tetelUjGomb) {
            esemeny.preventDefault();

            var tetelPanel = tetelUjGomb.closest('[data-sdh-tetelek]');

            // Nem üres sor nyílik, hanem a választó popup (szolgáltatás, illetve termék).
            if (tetelPanel && tetelPanel.getAttribute('data-sdh-tetelek') === 'szolgaltatas') {
                szolgValasztoNyit(tetelPanel, null, tetelUjGomb);
            } else if (tetelPanel && tetelPanel.getAttribute('data-sdh-tetelek') === 'termek') {
                termValasztoNyit(tetelPanel, null, tetelUjGomb);
            } else {
                tetelUj(tetelUjGomb);
            }

            return;
        }

        var tetelTorolGomb = esemeny.target.closest('[data-sdh-tetel-torol]');

        if (tetelTorolGomb) {
            esemeny.preventDefault();
            tetelTorol(tetelTorolGomb);

            return;
        }

        var indito = esemeny.target.closest('[data-sdh-urlap]');

        if (!indito) {
            return;
        }

        esemeny.preventDefault();
        // data-sdh-ful: a popup ezen a lapfülön nyíljon (pl. RMA).
        nyit(indito.dataset.sdhUrlap, indito.dataset.sdhId || '0', indito.dataset.sdhFul ? { ful: indito.dataset.sdhFul } : undefined);
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
    /* Szolgáltatás-törzs: választó popup a munkalapon                  */
    /* ---------------------------------------------------------------- */

    /*
     * A munkalap „Szolgáltatások" lapfülén a szolgáltatást külön popupból
     * lehet kiválasztani (nem lenyíló listából): a „+ Szolgáltatás" gomb és a
     * Megnevezés mező végén álló gomb nyitja. A popupban kereső, a törzs
     * táblázata (lapozva – görgetősáv nincs), soronként szerkesztés, és új
     * szolgáltatás felvitele. A választás kitölti a nevet, az egységet és a
     * bruttó árat. A törzs kezelőfelülete a „Szolgáltatások" modul
     * (szerver: SDH_Muhely_Szolgaltatas).
     *
     * A lista egyszer töltődik le, a keresés helyben fut; minden módosítás
     * után (mentés, szerkesztés, törlés) újra lekérjük.
     */
    var SZOLG_OLDAL = 10;
    var szolgAdat = null;      // [{ id, nev, me, ar, afa, db, nk, kulcs }]
    var szolgIgeret = null;
    var szolgVal = null;       // a nyitott választó: { n, panel, sor, gomb, kerdes, oldal, kijelolt, talalatok }

    /** Ugyanaz az azonosság, mint a szerveren: kisbetű, egységes szóközök. */
    var LAP_NYIL_BAL = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg>';
    var LAP_NYIL_JOBB = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7.5 4.5 13 10l-5.5 5.5"/></svg>';

    /** Ár kiírva: fix ár, vagy sáv („12 000 – 18 000 Ft"). */
    function arSzoveg(t) {
        if (!(t.ar > 0) && !(t.max > 0)) {
            return '—';
        }

        return t.max > t.ar
            ? mlPenz(t.ar) + '\u00a0–\u00a0' + mlPenz(t.max) + '\u00a0Ft'
            : mlPenz(t.ar) + '\u00a0Ft';
    }

    function szolgKulcs(nev) {
        return String(nev || '').replace(/[\s  ]+/g, ' ').trim().toLowerCase();
    }

    function szolgElavult() {
        szolgAdat = null;
        szolgIgeret = null;
    }

    function szolgBetolt() {
        if (szolgAdat) {
            return Promise.resolve(szolgAdat);
        }

        if (szolgIgeret) {
            return szolgIgeret;
        }

        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_szolgaltatasok');
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        var igeret = fetch(cim.toString(), { credentials: 'same-origin' })
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

                var adat = valasz.data.sorok.map(function (s) {
                    return {
                        id: s[0],
                        nev: s[1],
                        me: s[2],
                        ar: s[3],
                        afa: s[4],
                        db: s[5],
                        kat: s[6] || '',
                        kod: s[7] || '',
                        max: s[8] || 0,
                        dij: s[9] === 1,
                        nk: iszNorm(s[1]),
                        kulcs: szolgKulcs(s[1])
                    };
                });

                // Közben elavulttá vált kérés eredménye nem kerül a tárba.
                if (szolgIgeret === igeret) {
                    szolgAdat = adat;
                    szolgIgeret = null;
                }

                return adat;
            })
            .catch(function (hiba) {
                if (szolgIgeret === igeret) {
                    szolgIgeret = null;
                }

                throw hiba;
            });

        szolgIgeret = igeret;

        return igeret;
    }

    /**
     * Keresés: minden beírt szónak szerepelnie kell a névben (ékezet és
     * kis-/nagybetű nem számít). Elöl a beírt szöveggel kezdődők, aztán
     * amelyikben valamelyik szó így kezdődik; azon belül a gyakoribb elöl.
     */
    function szolgKeres(adat, szoveg) {
        var kerdes = iszNorm(szoveg);

        if (kerdes === '') {
            return adat.slice();
        }

        var szavak = kerdes.split(' ');
        var talalatok = [];

        adat.forEach(function (t, i) {
            var mind = szavak.every(function (szo) {
                return t.nk.indexOf(szo) >= 0;
            });

            if (!mind) {
                return;
            }

            var rang = t.nk.indexOf(kerdes) === 0 ? 0 : ((' ' + t.nk).indexOf(' ' + szavak[0]) >= 0 ? 1 : 2);

            talalatok.push({ t: t, rang: rang, i: i });
        });

        talalatok.sort(function (a, b) {
            return a.rang - b.rang || a.i - b.i;
        });

        return talalatok.map(function (x) { return x.t; });
    }

    function szolgPontos(nev) {
        var kulcs = szolgKulcs(nev);

        if (!szolgAdat || kulcs === '') {
            return null;
        }

        for (var i = 0; i < szolgAdat.length; i++) {
            if (szolgAdat[i].kulcs === kulcs) {
                return szolgAdat[i];
            }
        }

        return null;
    }

    function szolgAr(n) {
        return String(Math.round(n * 100) / 100).replace('.', ',');
    }

    /** A tételsor kitöltése a törzsből. `kimeloen`: a már beírt árat és egységet nem írja át. */
    function szolgKitolt(mezo, t, kimeloen) {
        var sor = mezo.closest('[data-sdh-tetelsor]');

        if (!sor) {
            return;
        }

        var me = sor.querySelector('[data-sdh-tetel="me"]');
        var ar = sor.querySelector('[data-sdh-tetel="ar"]');

        mezo.value = t.nev;

        if (me && t.me && !(kimeloen && me.value.trim() !== '' && me.value.trim() !== 'db')) {
            me.value = t.me;
        }

        if (ar && t.ar > 0 && !(kimeloen && mlSzam(ar.value) > 0)) {
            ar.value = szolgAr(t.ar);
        }

        // A munkalap élő számolása az input eseményre figyel.
        mezo.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /* ---- A választó popup ---- */

    function szolgValTorzs() {
        return szolgVal && szintek[szolgVal.n] ? szintek[szolgVal.n].torzs : null;
    }

    function szolgValNyitva() {
        var torzs = szolgValTorzs();

        return !!(torzs && szintek[szolgVal.n].dialog.open && torzs.querySelector('[data-sdh-szolgval]'));
    }

    /**
     * A választó megnyitása.
     *   panel – a munkalap Szolgáltatások panelje ([data-sdh-tetelek]);
     *   sor   – a kitöltendő tételsor, vagy null: akkor új sor készül;
     *   gomb  – a „+ Szolgáltatás" gomb (új sorhoz).
     */
    function szolgValasztoNyit(panel, sor, gomb) {
        var n = Math.min(sajatSzint(panel) + 1, szintek.length - 1);
        var szint = vaz(n);
        var mezo = sor ? sor.querySelector('[data-sdh-szolg]') : null;

        szint.dialog.sdhModul = 'szolgvalaszto';
        szint.dialog.sdhMeret = meretOlvas('szolgvalaszto');
        szint.dialog.setAttribute('data-sdh-modul', 'szolgvalaszto');

        var fejSorok = '';

        for (var i = 0; i < SZOLG_OLDAL; i++) {
            fejSorok += '<tr class="sdh-szolgval__ures"><td colspan="5">&nbsp;</td></tr>';
        }

        szint.torzs.innerHTML =
            '<h2 class="sdh-modal__cim">Szolgáltatás választása</h2>' +
            '<p class="sdh-modal__alcim">Kattints a sorra, vagy ↑ ↓ és Enter. A ceruzával szerkeszthető.</p>' +
            '<div class="sdh-szolgval" data-sdh-szolgval>' +
            '  <div class="sdh-szolgval__fej">' +
            '    <input type="search" autocomplete="off" data-sdh-szolgval-kereso' +
            '           aria-label="Keresés a szolgáltatások között" placeholder="Keresés a megnevezésben…">' +
            '    <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-szolgval-uj><span class="sdh-plusz" aria-hidden="true"></span>Új szolgáltatás</button>' +
            '  </div>' +
            '  <table class="sdh-tabla sdh-szolgval__tabla">' +
            '    <thead><tr><th>Megnevezés</th><th>M.e.</th><th class="is-jobb">Bruttó ár</th>' +
            '      <th class="is-jobb" title="Hány munkalap-tételben szerepelt">Használat</th><th></th></tr></thead>' +
            '    <tbody data-sdh-szolgval-sorok>' + fejSorok + '</tbody>' +
            '  </table>' +
            '  <div class="sdh-szolgval__lab">' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-lapnyil" data-sdh-szolgval-lap="-1" aria-label="Előző oldal">' + LAP_NYIL_BAL + '</button>' +
            '    <span class="sdh-szolgval__oldal" data-sdh-szolgval-oldal></span>' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-lapnyil" data-sdh-szolgval-lap="1" aria-label="Következő oldal">' + LAP_NYIL_JOBB + '</button>' +
            '    <span class="sdh-szolgval__db" data-sdh-szolgval-db></span>' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--jobbra" data-sdh-szolgval-kezi' +
            '            title="Üres sor a munkalapon: a nevet és az árat kézzel írod be">Kézzel írom be</button>' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-szolgval-megsem>Mégsem</button>' +
            '  </div>' +
            '</div>';

        // Ha a sorban félbehagyott (a törzsben még nem szereplő) szöveg áll, azzal indul a keresés.
        var kezdo = mezo && mezo.value.trim() !== '' && !szolgPontos(mezo.value) ? mezo.value.trim() : '';

        szolgVal = { n: n, panel: panel, sor: sor, gomb: gomb || null, kerdes: kezdo, oldal: 0, kijelolt: 0, talalatok: [] };

        if (!szint.dialog.open) {
            szint.dialog.showModal();
        }

        var kereso = szint.torzs.querySelector('[data-sdh-szolgval-kereso]');

        kereso.value = kezdo;
        kereso.focus();

        szolgValFrissit();
    }

    /** A törzs (újra)betöltése és a táblázat kirajzolása. */
    function szolgValFrissit() {
        var allapot = szolgVal;

        szolgBetolt().then(function (adat) {
            if (szolgVal !== allapot || !szolgValNyitva()) {
                return;
            }

            allapot.talalatok = szolgKeres(adat, allapot.kerdes);
            szolgValRajzol();
        }).catch(function () {
            var torzs = szolgValTorzs();
            var sorok = torzs ? torzs.querySelector('[data-sdh-szolgval-sorok]') : null;

            if (sorok) {
                sorok.innerHTML = '<tr><td colspan="5" class="sdh-tabla__ures">A szolgáltatások listája nem töltődött be.</td></tr>';
            }
        });
    }

    function szolgValRajzol() {
        var torzs = szolgValTorzs();

        if (!torzs || !szolgVal) {
            return;
        }

        var db = szolgVal.talalatok.length;
        var oldalak = Math.max(1, Math.ceil(db / SZOLG_OLDAL));

        szolgVal.kijelolt = Math.max(0, Math.min(szolgVal.kijelolt, db - 1));
        szolgVal.oldal = db > 0 ? Math.floor(szolgVal.kijelolt / SZOLG_OLDAL) : 0;

        var kezdet = szolgVal.oldal * SZOLG_OLDAL;
        var html = '';

        // Mindig ugyanannyi sor: a popup magassága gépelés közben nem ugrál.
        for (var i = kezdet; i < kezdet + SZOLG_OLDAL; i++) {
            var t = szolgVal.talalatok[i];

            if (!t) {
                html += i === 0
                    ? '<tr class="sdh-szolgval__ures"><td colspan="5" class="sdh-tabla__ures">' +
                        (szolgVal.kerdes !== ''
                            ? 'Nincs ilyen szolgáltatás. A „+ Új szolgáltatás" gombbal felveheted.'
                            : 'Még nincs szolgáltatás. A „+ Új szolgáltatás" gombbal felveheted az elsőt.') +
                        '</td></tr>'
                    : '<tr class="sdh-szolgval__ures"><td colspan="5">&nbsp;</td></tr>';
                continue;
            }

            html += '<tr data-sdh-szolgval-sor="' + i + '"' + (i === szolgVal.kijelolt ? ' class="is-kijelolt" aria-selected="true"' : '') + '>' +
                '<td class="sdh-szolgval__nev">' + szovegBiztonsagos(t.nev) + '</td>' +
                '<td>' + szovegBiztonsagos(t.me) + '</td>' +
                '<td class="is-jobb">' + arSzoveg(t) + '</td>' +
                '<td class="is-jobb sdh-tabla__halvany">' + t.db + '</td>' +
                '<td class="is-jobb"><button type="button" class="sdh-szolgval__szerk" data-sdh-szolgval-szerk="' + t.id + '"' +
                ' title="Szerkesztés" aria-label="' + szovegBiztonsagos(t.nev) + ' szerkesztése">' +
                '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M10.8 2.7l2.5 2.5-7.6 7.6-3 .5.5-3z"/></svg></button></td></tr>';
        }

        torzs.querySelector('[data-sdh-szolgval-sorok]').innerHTML = html;
        torzs.querySelector('[data-sdh-szolgval-oldal]').textContent = (szolgVal.oldal + 1) + ' / ' + oldalak;
        torzs.querySelector('[data-sdh-szolgval-db]').textContent = db + ' szolgáltatás';
        torzs.querySelector('[data-sdh-szolgval-lap="-1"]').disabled = szolgVal.oldal <= 0;
        torzs.querySelector('[data-sdh-szolgval-lap="1"]').disabled = szolgVal.oldal >= oldalak - 1;
    }

    /** A munkalap tételsora, amelyet a választás kitölt (ha kell, újat készít). */
    function szolgValCelsor() {
        if (szolgVal.sor && szolgVal.sor.isConnected) {
            return szolgVal.sor;
        }

        var lista = szolgVal.panel.querySelector('[data-sdh-tetelsorok]');
        var sorok = lista ? lista.querySelectorAll('[data-sdh-tetelsor]') : [];
        var utolso = sorok.length ? sorok[sorok.length - 1] : null;

        // Az üresen álló utolsó sort használjuk fel, nem nyitunk mellé újat.
        if (utolso) {
            var nev = utolso.querySelector('[data-sdh-szolg]');
            var ar = utolso.querySelector('[data-sdh-tetel="ar"]');

            if (nev && nev.value.trim() === '' && (!ar || mlSzam(ar.value) <= 0)) {
                return utolso;
            }
        }

        var gomb = szolgVal.gomb || szolgVal.panel.querySelector('[data-sdh-tetel-uj]');

        return gomb ? tetelUj(gomb) : null;
    }

    function szolgValValaszt(t) {
        if (!szolgVal || !t) {
            return;
        }

        var sor = szolgValCelsor();

        bezar(szolgVal.n);
        szolgVal = null;

        if (!sor) {
            return;
        }

        szolgKitolt(sor.querySelector('[data-sdh-szolg]'), t, false);

        var menny = sor.querySelector('[data-sdh-tetel="menny"]');

        if (menny) {
            menny.focus();
            menny.select();
        }
    }

    /** „Kézzel írom be": üres sor a munkalapon, a fókusz a Megnevezés mezőn. */
    function szolgValKezi() {
        if (!szolgVal) {
            return;
        }

        var sor = szolgValCelsor();

        bezar(szolgVal.n);
        szolgVal = null;

        var mezo = sor ? sor.querySelector('[data-sdh-szolg]') : null;

        if (mezo) {
            mezo.focus();
        }
    }

    /** Új felvitel vagy szerkesztés a választó fölött; mentés után a választó frissül. */
    function szolgValUrlap(id) {
        if (!szolgVal) {
            return;
        }

        var allapot = szolgVal;
        var uj = !id;

        nyit('szolgaltatasok', uj ? '0' : String(id), {
            szint: Math.min(allapot.n + 1, szintek.length - 1),
            parameterek: uj && allapot.kerdes !== '' ? { nev: allapot.kerdes } : {},
            siker: function (adat) {
                szolgElavult();

                if (szolgVal !== allapot) {
                    return;
                }

                // Az új szolgáltatás rögtön a munkalapra kerül.
                if (uj) {
                    szolgValValaszt({ id: adat.id, nev: adat.nev, me: adat.me, ar: adat.brutto_ar });

                    return;
                }

                szolgValFrissit();

                var kereso = szolgValTorzs().querySelector('[data-sdh-szolgval-kereso]');

                if (kereso) {
                    kereso.focus();
                }
            }
        });
    }

    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;

        if (!cel.closest) {
            return;
        }

        // A Megnevezés mező végén álló gomb: választás ebbe a sorba.
        var valaszt = cel.closest('[data-sdh-szolg-valaszt]');

        if (valaszt) {
            esemeny.preventDefault();
            szolgValasztoNyit(valaszt.closest('[data-sdh-tetelek]'), valaszt.closest('[data-sdh-tetelsor]'), null);

            return;
        }

        // Törlés a szolgáltatás űrlapján: az első kattintás csak rákérdez.
        var torol = cel.closest('[data-sdh-szolg-torol]');

        if (torol) {
            esemeny.preventDefault();
            szolgTorol(torol);

            return;
        }

        if (!szolgVal || !cel.closest('[data-sdh-szolgval]')) {
            return;
        }

        var szerk = cel.closest('[data-sdh-szolgval-szerk]');

        if (szerk) {
            szolgValUrlap(parseInt(szerk.getAttribute('data-sdh-szolgval-szerk'), 10));

            return;
        }

        var sor = cel.closest('[data-sdh-szolgval-sor]');

        if (sor) {
            szolgValValaszt(szolgVal.talalatok[parseInt(sor.getAttribute('data-sdh-szolgval-sor'), 10)]);

            return;
        }

        var lap = cel.closest('[data-sdh-szolgval-lap]');

        if (lap) {
            var irany = parseInt(lap.getAttribute('data-sdh-szolgval-lap'), 10);

            szolgVal.kijelolt = (szolgVal.oldal + irany) * SZOLG_OLDAL;
            szolgValRajzol();

            return;
        }

        if (cel.closest('[data-sdh-szolgval-uj]')) {
            szolgValUrlap(0);
        } else if (cel.closest('[data-sdh-szolgval-kezi]')) {
            szolgValKezi();
        } else if (cel.closest('[data-sdh-szolgval-megsem]')) {
            bezar(szolgVal.n);
            szolgVal = null;
        }
    });

    document.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target;

        if (szolgVal && mezo && mezo.matches && mezo.matches('[data-sdh-szolgval-kereso]')) {
            szolgVal.kerdes = mezo.value;
            szolgVal.kijelolt = 0;
            szolgVal.talalatok = szolgAdat ? szolgKeres(szolgAdat, mezo.value) : [];
            szolgValRajzol();
        }

        if (mezo && mezo.closest && mezo.closest('[data-sdh-szolgurlap]')) {
            szolgUrlapNetto(mezo.closest('[data-sdh-szolgurlap]'));
        }
    });

    document.addEventListener('change', function (esemeny) {
        var urlap = esemeny.target.closest ? esemeny.target.closest('[data-sdh-szolgurlap]') : null;

        if (urlap) {
            szolgUrlapNetto(urlap);
        }
    });

    /* ---- Árlista: beillesztés élő előnézettel, és az árak helyben mentése ---- */

    var arlistaIdozito = null;
    var arlistaKor = 0;

    function arlistaElonezet(mezo) {
        var urlap = mezo.closest('[data-sdh-arlista]');
        var hely = urlap ? urlap.querySelector('[data-sdh-arlista-elonezet]') : null;

        if (!hely) {
            return;
        }

        var kor = ++arlistaKor;
        var adat = new FormData();

        adat.set('action', 'sdh_muhely_szolgaltatas_arlista_elonezet');
        adat.set('_wpnonce', beallitas.nonce || '');
        adat.set('szoveg', mezo.value);

        hely.classList.add('is-tolt');

        fetch(beallitas.ajax, { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (valasz) {
                return valasz.json();
            })
            .then(function (valasz) {
                if (kor !== arlistaKor) {
                    return;
                }

                hely.classList.remove('is-tolt');
                hely.innerHTML = valasz && valasz.success
                    ? valasz.data.html
                    : '<p class="sdh-uzenet sdh-uzenet--hiba">Az előnézet nem készült el.</p>';
            })
            .catch(function () {
                if (kor === arlistaKor) {
                    hely.classList.remove('is-tolt');
                    hely.innerHTML = '<p class="sdh-uzenet sdh-uzenet--hiba">Az előnézet nem készült el.</p>';
                }
            });
    }

    document.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target;

        if (mezo && mezo.matches && mezo.matches('[data-sdh-arlista-szoveg]')) {
            window.clearTimeout(arlistaIdozito);
            arlistaIdozito = window.setTimeout(function () {
                arlistaElonezet(mezo);
            }, 300);
        }

        // Árlista nézet: a módosított sor kiemelve, amíg nincs mentve.
        var arSor = mezo && mezo.closest ? mezo.closest('[data-sdh-arak] tr') : null;

        if (arSor) {
            arSor.classList.add('is-modositva');
        }
    });

    document.addEventListener('submit', function (esemeny) {
        var urlap = esemeny.target;

        if (!urlap.matches || !urlap.matches('[data-sdh-arak]')) {
            return;
        }

        esemeny.preventDefault();

        var gomb = urlap.querySelector('[data-sdh-arak-ment]');
        var allapot = urlap.querySelector('[data-sdh-arak-allapot]');
        var adat = new FormData(urlap);

        adat.set('action', 'sdh_muhely_szolgaltatas_arak');

        if (gomb) {
            gomb.disabled = true;
        }

        fetch(beallitas.ajax, { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (valasz) {
                return valasz.json();
            })
            .then(function (valasz) {
                if (!valasz || !valasz.success) {
                    throw new Error((valasz && valasz.data && valasz.data.uzenet) || 'A mentés nem sikerült.');
                }

                Array.prototype.forEach.call(urlap.querySelectorAll('tr.is-modositva'), function (sor) {
                    var ar = sor.querySelector('input[name$="[ar]"]');
                    var max = sor.querySelector('input[name$="[max]"]');
                    var nyomtat = sor.querySelector('.sdh-arlista-tabla__nyomtat');

                    if (nyomtat && ar) {
                        nyomtat.textContent = arSzoveg({ ar: mlSzam(ar.value), max: max ? mlSzam(max.value) : 0 });
                    }

                    sor.classList.remove('is-modositva');
                });

                szolgElavult();

                if (allapot) {
                    allapot.textContent = valasz.data.db > 0 ? 'Elmentve – ' + valasz.data.db + ' tétel változott.' : 'Nem volt változás.';
                    allapot.classList.remove('is-hiba');
                }
            })
            .catch(function (hiba) {
                if (allapot) {
                    allapot.textContent = hiba.message;
                    allapot.classList.add('is-hiba');
                }
            })
            .then(function () {
                if (gomb) {
                    gomb.disabled = false;
                }
            });
    });

    document.addEventListener('keydown', function (esemeny) {
        var mezo = esemeny.target;

        if (!mezo || !mezo.matches) {
            return;
        }

        // A munkalap Megnevezés mezőjében a lefelé nyíl is a választót nyitja.
        if (mezo.matches('[data-sdh-szolg]') && esemeny.key === 'ArrowDown') {
            esemeny.preventDefault();
            szolgValasztoNyit(mezo.closest('[data-sdh-tetelek]'), mezo.closest('[data-sdh-tetelsor]'), null);

            return;
        }

        if (!szolgVal || !mezo.matches('[data-sdh-szolgval-kereso]')) {
            return;
        }

        var db = szolgVal.talalatok.length;
        var lepes = { ArrowDown: 1, ArrowUp: -1, PageDown: SZOLG_OLDAL, PageUp: -SZOLG_OLDAL }[esemeny.key];

        if (lepes && db > 0) {
            esemeny.preventDefault();
            szolgVal.kijelolt = Math.max(0, Math.min(db - 1, szolgVal.kijelolt + lepes));
            szolgValRajzol();
        } else if (esemeny.key === 'Enter') {
            esemeny.preventDefault();

            if (db > 0) {
                szolgValValaszt(szolgVal.talalatok[szolgVal.kijelolt]);
            }
        }
    }, true);

    document.addEventListener('focusin', function (esemeny) {
        var mezo = esemeny.target;

        if (!mezo || !mezo.matches) {
            return;
        }

        // Már az első érintésnél letöltjük a törzset, hogy a választó azonnal nyíljon.
        if (mezo.matches('[data-sdh-szolg]')) {
            szolgBetolt().catch(function () {});
        }

        if (mezo.closest('[data-sdh-szolgurlap]')) {
            szolgUrlapNetto(mezo.closest('[data-sdh-szolgurlap]'));
        }
    });

    document.addEventListener('focusout', function (esemeny) {
        var mezo = esemeny.target;

        if (!mezo || !mezo.matches || !mezo.matches('[data-sdh-szolg]')) {
            return;
        }

        // Ha a kézzel beírt név már megvan a törzsben (csak a kis-/nagybetű vagy
        // a szóköz tér el), a törzs írásmódját kapja, és az üres árat is kitöltjük.
        var pontos = szolgPontos(mezo.value);
        var sor = mezo.closest('[data-sdh-tetelsor]');
        var ar = sor ? sor.querySelector('[data-sdh-tetel="ar"]') : null;

        if (pontos && (mezo.value !== pontos.nev || (ar && pontos.ar > 0 && mlSzam(ar.value) <= 0))) {
            szolgKitolt(mezo, pontos, true);
        }
    });

    /* ---- A szolgáltatás űrlapja (Szolgáltatások modul és a választó fölött) ---- */

    /** A nettó ár kiírása a bruttóból és az áfakulcsból. */
    function szolgUrlapNetto(urlap) {
        var brutto = urlap.querySelector('[data-sdh-szolg-brutto]');
        var afa = urlap.querySelector('[data-sdh-szolg-afa]');
        var ki = urlap.querySelector('[data-sdh-szolg-netto]');

        if (!brutto || !afa || !ki) {
            return;
        }

        var opcio = afa.options[afa.selectedIndex];
        var szazalek = opcio ? parseFloat(opcio.getAttribute('data-szazalek') || '0') : 0;
        var ertek = mlSzam(brutto.value);

        mlIr(ki, ertek > 0 ? mlPenz(ertek / (1 + szazalek / 100)) + ' Ft' : '—');
    }

    /** Törlés a törzsből, két lépésben (rákérdezés a gombon, natív ablak nélkül). */
    function szolgTorol(gomb) {
        if (gomb.dataset.sdhBiztos !== '1') {
            gomb.dataset.sdhBiztos = '1';
            gomb.dataset.sdhFelirat = gomb.textContent;
            gomb.textContent = 'Biztosan törlöd?';
            gomb.classList.add('is-veszely');

            window.setTimeout(function () {
                if (gomb.isConnected && gomb.dataset.sdhBiztos === '1') {
                    gomb.dataset.sdhBiztos = '';
                    gomb.textContent = gomb.dataset.sdhFelirat;
                    gomb.classList.remove('is-veszely');
                }
            }, 4000);

            return;
        }

        var urlap = gomb.closest('form');
        var adat = new FormData();

        adat.append('action', 'sdh_muhely_szolgaltatas_felejt');
        adat.append('_wpnonce', beallitas.nonce || '');
        adat.append('id', gomb.getAttribute('data-sdh-szolg-torol'));
        gomb.disabled = true;

        fetch(beallitas.ajax, { method: 'POST', credentials: 'same-origin', body: adat })
            .then(function (valasz) { return valasz.json(); })
            .then(function (valasz) {
                if (!valasz || !valasz.success) {
                    throw new Error((valasz && valasz.data && valasz.data.uzenet) || 'A törlés nem sikerült.');
                }

                szolgElavult();

                var n = sajatSzint(gomb);

                // A választó fölött: csak ez a popup zárul, a választó frissül.
                if (szolgVal && n > szolgVal.n && szolgValNyitva()) {
                    bezar(n);
                    szolgValFrissit();

                    return;
                }

                window.location.href = gomb.getAttribute('data-vissza') || window.location.href;
            })
            .catch(function (hiba) {
                gomb.disabled = false;

                if (urlap) {
                    mutatUrlapHiba(urlap, hiba.message);
                }
            });
    }

    // Munkalap mentése után a törzs változhatott: a következő nyitáskor újra lekérjük.
    document.addEventListener('sdh:mentve', szolgElavult);

    /* ---------------------------------------------------------------- */
    /* Terméktörzs: választó popup a munkalapon és a termék űrlapja     */
    /* ---------------------------------------------------------------- */

    /*
     * A munkalap „Termékek" lapfülén a terméket a készletből lehet
     * kiválasztani: a „+ Termék" gomb és a Megnevezés mező végén álló gomb
     * nyitja a választót. A keresés a névben és minden kódban fut (cikkszám,
     * termékkód, gyári szám, vonalkód) – vonalkódolvasóval beolvasott kód +
     * Enter a terméket rögtön a munkalapra teszi. A választás kitölti a
     * nevet, a kódokat, az egységet, a bruttó árat és az áfakulcsot, és a
     * sor megjegyzi a termék azonosítóját: mentéskor ennyi lejön a
     * készletről (szerver: SDH_Muhely_Termek::munkalap_mozgasok).
     */
    var TERM_OLDAL = 10;
    var termAdat = null;       // [{ id, nev, me, ar, afa, keszlet, cikkszam, termekkod, gyari, vonalkod, kategoria, kedv, jelzes, nk }]
    var termIgeret = null;
    var termVal = null;        // a nyitott választó: { n, panel, sor, gomb, kerdes, oldal, kijelolt, talalatok }

    function termElavult() {
        termAdat = null;
        termIgeret = null;
    }

    function termObjektum(s) {
        var t = Array.isArray(s)
            ? {
                id: s[0], nev: s[1], me: s[2], ar: s[3], afa: s[4], keszlet: s[5], cikkszam: s[6],
                termekkod: s[7], gyari: s[8], vonalkod: s[9], kategoria: s[10], kedv: s[11], jelzes: s[12]
            }
            : {
                id: s.id, nev: s.nev, me: s.me, ar: s.brutto_ar, afa: s.afa_kulcs, keszlet: s.keszlet, cikkszam: s.cikkszam,
                termekkod: s.termekkod, gyari: s.gyari_szam, vonalkod: s.vonalkod, kategoria: s.kategoria, kedv: s.kedvezmeny, jelzes: s.jelzes
            };

        t.nk = iszNorm([t.nev, t.cikkszam, t.termekkod, t.gyari, t.vonalkod, t.kategoria].join(' '));
        t.nevk = iszNorm(t.nev);

        return t;
    }

    function termBetolt() {
        if (termAdat) {
            return Promise.resolve(termAdat);
        }

        if (termIgeret) {
            return termIgeret;
        }

        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_termekek');
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        var igeret = fetch(cim.toString(), { credentials: 'same-origin' })
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

                var adat = valasz.data.sorok.map(termObjektum);

                // Közben elavulttá vált kérés eredménye nem kerül a tárba.
                if (termIgeret === igeret) {
                    termAdat = adat;
                    termIgeret = null;
                }

                return adat;
            })
            .catch(function (hiba) {
                if (termIgeret === igeret) {
                    termIgeret = null;
                }

                throw hiba;
            });

        termIgeret = igeret;

        return igeret;
    }

    /**
     * Keresés: minden beírt szónak szerepelnie kell a névben vagy valamelyik
     * kódban. Elöl a pontos kódegyezés (vonalkód, cikkszám, termékkód, gyári
     * szám), aztán a beírt szöveggel kezdődő nevek, végül a többi.
     */
    function termKeres(adat, szoveg) {
        var kerdes = iszNorm(szoveg);

        if (kerdes === '') {
            return adat.slice();
        }

        var szavak = kerdes.split(' ');
        var talalatok = [];

        adat.forEach(function (t, i) {
            var mind = szavak.every(function (szo) {
                return t.nk.indexOf(szo) >= 0;
            });

            if (!mind) {
                return;
            }

            var pontos = [t.vonalkod, t.cikkszam, t.termekkod, t.gyari].some(function (kod) {
                return kod && iszNorm(kod) === kerdes;
            });

            talalatok.push({ t: t, rang: pontos ? 0 : (t.nevk.indexOf(kerdes) === 0 ? 1 : 2), i: i });
        });

        talalatok.sort(function (a, b) {
            return a.rang - b.rang || a.i - b.i;
        });

        return talalatok.map(function (x) { return x.t; });
    }

    function termSorMezo(sor, nev) {
        return sor.querySelector('input[name$="[' + nev + ']"]');
    }

    /** A munkalap tételsorának kitöltése a terméktörzsből. */
    function termKitolt(sor, t) {
        var beir = function (mezo, ertek) {
            if (mezo) {
                mezo.value = ertek === null || ertek === undefined ? '' : String(ertek);
            }
        };

        beir(termSorMezo(sor, 'termek_id'), t.id);
        beir(termSorMezo(sor, 'megnevezes'), t.nev);
        beir(termSorMezo(sor, 'termekkod'), t.termekkod);
        beir(termSorMezo(sor, 'cikkszam'), t.cikkszam);
        beir(termSorMezo(sor, 'gyari_szam'), t.gyari);
        beir(sor.querySelector('[data-sdh-tetel="me"]'), t.me || 'db');
        beir(sor.querySelector('[data-sdh-tetel="ar"]'), t.ar > 0 ? szolgAr(t.ar) : '');

        var afa = sor.querySelector('[data-sdh-tetel="afa"]');

        if (afa && t.afa) {
            afa.value = t.afa;
        }

        if (t.kedv > 0) {
            beir(sor.querySelector('[data-sdh-tetel="kedv"]'), szolgAr(t.kedv));
        }

        // A munkalap élő számolása az input eseményre figyel.
        termSorMezo(sor, 'megnevezes').dispatchEvent(new Event('input', { bubbles: true }));
    }

    function termKeszletSzoveg(t) {
        if (t.keszlet === null || t.keszlet === undefined) {
            return '<span class="sdh-tabla__halvany" title="Nincs készletkezelés">—</span>';
        }

        return '<span class="sdh-keszlet sdh-keszlet--' + (t.jelzes || 'ok') + '">' +
            mlMenny(t.keszlet) + ' ' + szovegBiztonsagos(t.me || '') + '</span>';
    }

    /* ---- A választó popup ---- */

    function termValTorzs() {
        return termVal && szintek[termVal.n] ? szintek[termVal.n].torzs : null;
    }

    function termValNyitva() {
        var torzs = termValTorzs();

        return !!(torzs && szintek[termVal.n].dialog.open && torzs.querySelector('[data-sdh-termval]'));
    }

    /**
     * A választó megnyitása.
     *   panel – a munkalap Termékek panelje ([data-sdh-tetelek]);
     *   sor   – a kitöltendő tételsor, vagy null: akkor új sor készül;
     *   gomb  – a „+ Termék" gomb (új sorhoz).
     */
    function termValasztoNyit(panel, sor, gomb) {
        var n = Math.min(sajatSzint(panel) + 1, szintek.length - 1);
        var szint = vaz(n);
        var mezo = sor ? termSorMezo(sor, 'megnevezes') : null;
        var azonosito = sor ? termSorMezo(sor, 'termek_id') : null;

        szint.dialog.sdhModul = 'termvalaszto';
        szint.dialog.sdhMeret = meretOlvas('termvalaszto');
        szint.dialog.setAttribute('data-sdh-modul', 'termvalaszto');

        var fejSorok = '';

        for (var i = 0; i < TERM_OLDAL; i++) {
            fejSorok += '<tr class="sdh-szolgval__ures"><td colspan="6">&nbsp;</td></tr>';
        }

        szint.torzs.innerHTML =
            '<h2 class="sdh-modal__cim">Termék választása</h2>' +
            '<p class="sdh-modal__alcim">Kattints a sorra, vagy ↑ ↓ és Enter. Vonalkód, cikkszám és termékkód is kereshető.</p>' +
            '<div class="sdh-szolgval" data-sdh-termval>' +
            '  <div class="sdh-szolgval__fej">' +
            '    <input type="search" autocomplete="off" data-sdh-termval-kereso' +
            '           aria-label="Keresés a termékek között" placeholder="Megnevezés, cikkszám, termékkód, vonalkód…">' +
            '    <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-termval-uj><span class="sdh-plusz" aria-hidden="true"></span>Új termék</button>' +
            '  </div>' +
            '  <table class="sdh-tabla sdh-szolgval__tabla sdh-termval__tabla">' +
            '    <thead><tr><th>Megnevezés</th><th>Cikkszám</th><th>Termékkód</th>' +
            '      <th class="is-jobb">Készlet</th><th class="is-jobb">Bruttó ár</th><th></th></tr></thead>' +
            '    <tbody data-sdh-termval-sorok>' + fejSorok + '</tbody>' +
            '  </table>' +
            '  <div class="sdh-szolgval__lab">' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-termval-lap="-1" aria-label="Előző oldal">‹</button>' +
            '    <span class="sdh-szolgval__oldal" data-sdh-termval-oldal></span>' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-termval-lap="1" aria-label="Következő oldal">›</button>' +
            '    <span class="sdh-szolgval__db" data-sdh-termval-db></span>' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--jobbra" data-sdh-termval-kezi' +
            '            title="Üres sor a munkalapon: a nevet és az árat kézzel írod be, a készlet nem változik">Kézzel írom be</button>' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-termval-megsem>Mégsem</button>' +
            '  </div>' +
            '</div>';

        // Ha a sorban kézzel beírt (a törzshöz nem kötött) szöveg áll, azzal indul a keresés.
        var kezdo = mezo && mezo.value.trim() !== '' && !(azonosito && parseInt(azonosito.value, 10) > 0) ? mezo.value.trim() : '';

        termVal = { n: n, panel: panel, sor: sor, gomb: gomb || null, kerdes: kezdo, oldal: 0, kijelolt: 0, talalatok: [] };

        if (!szint.dialog.open) {
            szint.dialog.showModal();
        }

        var kereso = szint.torzs.querySelector('[data-sdh-termval-kereso]');

        kereso.value = kezdo;
        kereso.focus();

        termValFrissit();
    }

    /** A törzs (újra)betöltése és a táblázat kirajzolása. */
    function termValFrissit() {
        var allapot = termVal;

        termBetolt().then(function (adat) {
            if (termVal !== allapot || !termValNyitva()) {
                return;
            }

            allapot.talalatok = termKeres(adat, allapot.kerdes);
            termValRajzol();
        }).catch(function () {
            var torzs = termValTorzs();
            var sorok = torzs ? torzs.querySelector('[data-sdh-termval-sorok]') : null;

            if (sorok) {
                sorok.innerHTML = '<tr><td colspan="6" class="sdh-tabla__ures">A termékek listája nem töltődött be.</td></tr>';
            }
        });
    }

    function termValRajzol() {
        var torzs = termValTorzs();

        if (!torzs || !termVal) {
            return;
        }

        var db = termVal.talalatok.length;
        var oldalak = Math.max(1, Math.ceil(db / TERM_OLDAL));

        termVal.kijelolt = Math.max(0, Math.min(termVal.kijelolt, db - 1));
        termVal.oldal = db > 0 ? Math.floor(termVal.kijelolt / TERM_OLDAL) : 0;

        var kezdet = termVal.oldal * TERM_OLDAL;
        var html = '';

        // Mindig ugyanannyi sor: a popup magassága gépelés közben nem ugrál.
        for (var i = kezdet; i < kezdet + TERM_OLDAL; i++) {
            var t = termVal.talalatok[i];

            if (!t) {
                html += i === 0
                    ? '<tr class="sdh-szolgval__ures"><td colspan="6" class="sdh-tabla__ures">' +
                        (termVal.kerdes !== ''
                            ? 'Nincs ilyen termék. Az „+ Új termék" gombbal felveheted.'
                            : 'Még nincs termék. Az „+ Új termék" gombbal felveheted az elsőt.') +
                        '</td></tr>'
                    : '<tr class="sdh-szolgval__ures"><td colspan="6">&nbsp;</td></tr>';
                continue;
            }

            html += '<tr data-sdh-termval-sor="' + i + '"' + (i === termVal.kijelolt ? ' class="is-kijelolt" aria-selected="true"' : '') + '>' +
                '<td class="sdh-szolgval__nev" title="' + szovegBiztonsagos(t.nev) + '">' + szovegBiztonsagos(t.nev) +
                (t.kategoria ? ' <span class="sdh-termval__kat">' + szovegBiztonsagos(t.kategoria) + '</span>' : '') + '</td>' +
                '<td>' + szovegBiztonsagos(t.cikkszam || '') + '</td>' +
                '<td>' + szovegBiztonsagos(t.termekkod || '') + '</td>' +
                '<td class="is-jobb">' + termKeszletSzoveg(t) + '</td>' +
                '<td class="is-jobb">' + (t.ar > 0 ? mlPenz(t.ar) + ' Ft' : '—') + '</td>' +
                '<td class="is-jobb"><button type="button" class="sdh-szolgval__szerk" data-sdh-termval-szerk="' + t.id + '"' +
                ' title="Szerkesztés" aria-label="' + szovegBiztonsagos(t.nev) + ' szerkesztése">' +
                '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M10.8 2.7l2.5 2.5-7.6 7.6-3 .5.5-3z"/></svg></button></td></tr>';
        }

        torzs.querySelector('[data-sdh-termval-sorok]').innerHTML = html;
        torzs.querySelector('[data-sdh-termval-oldal]').textContent = (termVal.oldal + 1) + ' / ' + oldalak;
        torzs.querySelector('[data-sdh-termval-db]').textContent = db + ' termék';
        torzs.querySelector('[data-sdh-termval-lap="-1"]').disabled = termVal.oldal <= 0;
        torzs.querySelector('[data-sdh-termval-lap="1"]').disabled = termVal.oldal >= oldalak - 1;
    }

    /** A munkalap tételsora, amelyet a választás kitölt (ha kell, újat készít). */
    function termValCelsor() {
        if (termVal.sor && termVal.sor.isConnected) {
            return termVal.sor;
        }

        var lista = termVal.panel.querySelector('[data-sdh-tetelsorok]');
        var sorok = lista ? lista.querySelectorAll('[data-sdh-tetelsor]') : [];
        var utolso = sorok.length ? sorok[sorok.length - 1] : null;

        // Az üresen álló utolsó sort használjuk fel, nem nyitunk mellé újat.
        if (utolso) {
            var nev = termSorMezo(utolso, 'megnevezes');
            var ar = utolso.querySelector('[data-sdh-tetel="ar"]');

            if (nev && nev.value.trim() === '' && (!ar || mlSzam(ar.value) <= 0)) {
                return utolso;
            }
        }

        var gomb = termVal.gomb || termVal.panel.querySelector('[data-sdh-tetel-uj]');

        return gomb ? tetelUj(gomb) : null;
    }

    function termValValaszt(t) {
        if (!termVal || !t) {
            return;
        }

        var sor = termValCelsor();

        bezar(termVal.n);
        termVal = null;

        if (!sor) {
            return;
        }

        termKitolt(sor, t);

        var menny = sor.querySelector('[data-sdh-tetel="menny"]');

        if (menny) {
            menny.focus();
            menny.select();
        }
    }

    /** „Kézzel írom be": üres sor a munkalapon, a fókusz a Megnevezés mezőn. */
    function termValKezi() {
        if (!termVal) {
            return;
        }

        var sor = termValCelsor();

        bezar(termVal.n);
        termVal = null;

        var mezo = sor ? termSorMezo(sor, 'megnevezes') : null;

        if (mezo) {
            mezo.focus();
        }
    }

    /** Új felvitel vagy szerkesztés a választó fölött; mentés után a választó frissül. */
    function termValUrlap(id) {
        if (!termVal) {
            return;
        }

        var allapot = termVal;
        var uj = !id;

        nyit('termekek', uj ? '0' : String(id), {
            szint: Math.min(allapot.n + 1, szintek.length - 1),
            parameterek: uj && allapot.kerdes !== '' ? { nev: allapot.kerdes } : {},
            siker: function (adat) {
                termElavult();

                if (termVal !== allapot) {
                    return;
                }

                // Az új termék rögtön a munkalapra kerül.
                if (uj) {
                    termValValaszt(termObjektum(adat));

                    return;
                }

                termValFrissit();

                var kereso = termValTorzs().querySelector('[data-sdh-termval-kereso]');

                if (kereso) {
                    kereso.focus();
                }
            }
        });
    }

    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;

        if (!cel.closest) {
            return;
        }

        // A Megnevezés mező végén álló gomb: választás ebbe a sorba.
        var valaszt = cel.closest('[data-sdh-termek-valaszt]');

        if (valaszt) {
            esemeny.preventDefault();
            termValasztoNyit(valaszt.closest('[data-sdh-tetelek]'), valaszt.closest('[data-sdh-tetelsor]'), null);

            return;
        }

        // Törlés a termék űrlapján: az első kattintás csak rákérdez.
        var torol = cel.closest('[data-sdh-termek-torol]');

        if (torol) {
            esemeny.preventDefault();
            termTorol(torol);

            return;
        }

        if (!termVal || !cel.closest('[data-sdh-termval]')) {
            return;
        }

        var szerk = cel.closest('[data-sdh-termval-szerk]');

        if (szerk) {
            termValUrlap(parseInt(szerk.getAttribute('data-sdh-termval-szerk'), 10));

            return;
        }

        var sor = cel.closest('[data-sdh-termval-sor]');

        if (sor) {
            termValValaszt(termVal.talalatok[parseInt(sor.getAttribute('data-sdh-termval-sor'), 10)]);

            return;
        }

        var lap = cel.closest('[data-sdh-termval-lap]');

        if (lap) {
            var irany = parseInt(lap.getAttribute('data-sdh-termval-lap'), 10);

            termVal.kijelolt = (termVal.oldal + irany) * TERM_OLDAL;
            termValRajzol();

            return;
        }

        if (cel.closest('[data-sdh-termval-uj]')) {
            termValUrlap(0);
        } else if (cel.closest('[data-sdh-termval-kezi]')) {
            termValKezi();
        } else if (cel.closest('[data-sdh-termval-megsem]')) {
            bezar(termVal.n);
            termVal = null;
        }
    });

    document.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target;

        if (!mezo || !mezo.matches) {
            return;
        }

        if (termVal && mezo.matches('[data-sdh-termval-kereso]')) {
            termVal.kerdes = mezo.value;
            termVal.kijelolt = 0;
            termVal.talalatok = termAdat ? termKeres(termAdat, mezo.value) : [];
            termValRajzol();

            return;
        }

        // A kiürített Megnevezés elengedi a terméket: az a sor már nem fogyaszt készletet.
        if (mezo.matches('[data-sdh-termek]') && mezo.value.trim() === '') {
            var azonosito = termSorMezo(mezo.closest('[data-sdh-tetelsor]'), 'termek_id');

            if (azonosito) {
                azonosito.value = '0';
            }

            return;
        }

        var urlap = mezo.closest('[data-sdh-termekurlap]');

        if (urlap) {
            termUrlapSzamol(urlap, mezo.getAttribute('data-t') || '');
        }
    });

    document.addEventListener('change', function (esemeny) {
        var mezo = esemeny.target;
        var urlap = mezo && mezo.closest ? mezo.closest('[data-sdh-termekurlap]') : null;

        if (urlap) {
            termUrlapSzamol(urlap, mezo.tagName === 'SELECT' || mezo.type === 'checkbox' ? (mezo.getAttribute('data-t') || '') : '');
        }

        // A lista szűrője (kategória) választásra rögtön keres.
        if (mezo && mezo.matches && mezo.matches('select[data-sdh-auto-kuld]') && mezo.form) {
            mezo.form.submit();
        }
    });

    document.addEventListener('keydown', function (esemeny) {
        var mezo = esemeny.target;

        if (!mezo || !mezo.matches) {
            return;
        }

        // A munkalap Megnevezés mezőjében a lefelé nyíl is a választót nyitja.
        if (mezo.matches('[data-sdh-termek]') && esemeny.key === 'ArrowDown') {
            esemeny.preventDefault();
            termValasztoNyit(mezo.closest('[data-sdh-tetelek]'), mezo.closest('[data-sdh-tetelsor]'), null);

            return;
        }

        if (!termVal || !mezo.matches('[data-sdh-termval-kereso]')) {
            return;
        }

        var db = termVal.talalatok.length;
        var lepes = { ArrowDown: 1, ArrowUp: -1, PageDown: TERM_OLDAL, PageUp: -TERM_OLDAL }[esemeny.key];

        if (lepes && db > 0) {
            esemeny.preventDefault();
            termVal.kijelolt = Math.max(0, Math.min(db - 1, termVal.kijelolt + lepes));
            termValRajzol();
        } else if (esemeny.key === 'Enter') {
            esemeny.preventDefault();

            if (db > 0) {
                termValValaszt(termVal.talalatok[termVal.kijelolt]);
            }
        }
    }, true);

    document.addEventListener('focusin', function (esemeny) {
        var mezo = esemeny.target;

        // Már az első érintésnél letöltjük a törzset, hogy a választó azonnal nyíljon.
        if (mezo && mezo.matches && mezo.matches('[data-sdh-termek]')) {
            termBetolt().catch(function () {});
        }
    });

    /* ---- A termék űrlapja (Termékek modul és a választó fölött) ---- */

    function termMezo(urlap, nev) {
        return urlap.querySelector('[data-t="' + nev + '"]');
    }

    function termSzazalek(select) {
        var opcio = select ? select.options[select.selectedIndex] : null;

        return opcio ? parseFloat(opcio.getAttribute('data-szazalek') || '0') || 0 : 0;
    }

    function termIr(mezo, n) {
        if (mezo) {
            mezo.value = n > 0 ? String(mlKerek(n)).replace('.', ',') : '';
        }
    }

    /**
     * Az űrlap élő számolása. `forras`: melyik mező változott (data-t) –
     * az marad érintetlen, a többi abból adódik:
     *   beszerzési nettó ↔ bruttó (a beszerzés áfakulcsával),
     *   eladási nettó ↔ bruttó (az eladás áfakulcsával; áfaváltásnál a bruttó marad),
     *   haszonkulcs → eladási ár (a beszerzési nettóból), különben az árakból a haszonkulcs.
     * Mentéskor a szerver az eladási bruttóból és a beszerzési nettóból számol.
     */
    function termUrlapSzamol(urlap, forras) {
        var beszNetto = termMezo(urlap, 'besz_netto');
        var beszBrutto = termMezo(urlap, 'besz_brutto');
        var netto = termMezo(urlap, 'netto');
        var brutto = termMezo(urlap, 'brutto');
        var haszon = termMezo(urlap, 'haszon');

        if (!beszNetto || !beszBrutto || !netto || !brutto || !haszon) {
            return;
        }

        var beszAfa = termSzazalek(termMezo(urlap, 'besz_afa'));
        var afa = termSzazalek(termMezo(urlap, 'afa'));

        if (forras === 'besz_brutto') {
            termIr(beszNetto, mlSzam(beszBrutto.value) / (1 + beszAfa / 100));
        } else if (forras === 'besz_netto' || forras === 'besz_afa') {
            termIr(beszBrutto, mlSzam(beszNetto.value) * (1 + beszAfa / 100));
        }

        var besz = mlSzam(beszNetto.value);

        if (forras === 'haszon') {
            if (besz > 0 && haszon.value.trim() !== '') {
                termIr(netto, besz * (1 + mlSzam(haszon.value) / 100));
                termIr(brutto, mlSzam(netto.value) * (1 + afa / 100));
            }
        } else if (forras === 'netto') {
            termIr(brutto, mlSzam(netto.value) * (1 + afa / 100));
        } else if (forras === 'brutto' || forras === 'afa') {
            termIr(netto, mlSzam(brutto.value) / (1 + afa / 100));
        }

        var eladasi = mlSzam(netto.value);

        if (forras !== 'haszon') {
            haszon.value = besz > 0 && eladasi > 0
                ? String(Math.round((eladasi - besz) / besz * 1000) / 10).replace('.', ',')
                : '';
        }

        mlIr(termMezo(urlap, 'haszon_ft'), besz > 0 && eladasi > 0
            ? 'Haszon darabonként: ' + mlPenz(eladasi - besz) + ' Ft (nettó)'
            : '');

        // Készletkezelés nélkül a készletmezők halványak; az átírt készlethez ok is írható.
        var kezelt = termMezo(urlap, 'kezelt');
        var keszlet = termMezo(urlap, 'keszlet');
        var be = !kezelt || kezelt.checked;

        Array.prototype.forEach.call(urlap.querySelectorAll('[data-t-keszletsor]'), function (elem) {
            elem.classList.toggle('is-kikapcsolt', !be);
        });

        var ok = urlap.querySelector('[data-t-keszletok]');
        var sugo = urlap.querySelector('[data-t-keszletsugo]');
        var gyoker = urlap.closest('form');
        var eredeti = gyoker ? gyoker.querySelector('input[name="keszlet_eredeti"]') : null;

        // Ugyanazon a helyen áll a súgó és a „változás oka" mező: a popup nem ugrál.
        if (ok && keszlet && eredeti) {
            var valtozott = be && Math.abs(mlSzam(keszlet.value) - (parseFloat(eredeti.value) || 0)) >= 0.0005;

            ok.hidden = !valtozott;

            if (sugo) {
                sugo.hidden = valtozott;
            }
        }
    }

    // A betöltött űrlap rögtön kiírja a számolt mezőket.
    document.addEventListener('sdh:urlap-betoltve', function (esemeny) {
        var urlap = esemeny.detail && esemeny.detail.torzs ? esemeny.detail.torzs.querySelector('[data-sdh-termekurlap]') : null;

        if (urlap) {
            termUrlapSzamol(urlap, '');
        }
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-termekurlap]'), function (urlap) {
        termUrlapSzamol(urlap, '');
    });

    /** Törlés két lépésben (rákérdezés a gombon, natív ablak nélkül). */
    function termTorol(gomb) {
        if (gomb.dataset.sdhBiztos !== '1') {
            gomb.dataset.sdhBiztos = '1';
            gomb.dataset.sdhFelirat = gomb.textContent;
            gomb.textContent = 'Biztosan törlöd?';
            gomb.classList.add('is-veszely');

            window.setTimeout(function () {
                if (gomb.isConnected && gomb.dataset.sdhBiztos === '1') {
                    gomb.dataset.sdhBiztos = '';
                    gomb.textContent = gomb.dataset.sdhFelirat;
                    gomb.classList.remove('is-veszely');
                }
            }, 4000);

            return;
        }

        var urlap = gomb.closest('form');
        var adat = new FormData();

        adat.append('action', 'sdh_muhely_termek_torol');
        adat.append('_wpnonce', beallitas.nonce || '');
        adat.append('id', gomb.getAttribute('data-sdh-termek-torol'));
        gomb.disabled = true;

        fetch(beallitas.ajax, { method: 'POST', credentials: 'same-origin', body: adat })
            .then(function (valasz) { return valasz.json(); })
            .then(function (valasz) {
                if (!valasz || !valasz.success) {
                    throw new Error((valasz && valasz.data && valasz.data.uzenet) || 'A törlés nem sikerült.');
                }

                termElavult();

                var n = sajatSzint(gomb);

                // A választó fölött: csak ez a popup zárul, a választó frissül.
                if (termVal && n > termVal.n && termValNyitva()) {
                    bezar(n);
                    termValFrissit();

                    return;
                }

                window.location.href = (valasz.data.inaktiv ? gomb.getAttribute('data-vissza-inaktiv') : gomb.getAttribute('data-vissza'))
                    || window.location.href;
            })
            .catch(function (hiba) {
                gomb.disabled = false;

                if (urlap) {
                    mutatUrlapHiba(urlap, hiba.message);
                }
            });
    }

    // Munkalap mentése után a készlet változhatott: a következő nyitáskor újra lekérjük.
    document.addEventListener('sdh:mentve', termElavult);

    /* ---------------------------------------------------------------- */
    /* Számlázás (Számlázz.hu) a munkalap-ablakból                      */
    /* ---------------------------------------------------------------- */

    /*
     * A munkalap láblécének számlagombja ([data-sdh-szamla="fo|sdh"]):
     *   1. elmenti a munkalapot (a számla a MENTETT tételekből készül),
     *   2. újratölti a munkalap-ablakot (a tételsorok így a végleges
     *      azonosítójukkal állnak az űrlapban),
     *   3. fölé nyitja a számla ablakát (szerver: SDH_Muhely_Szamla).
     * Számla csak ott, a „Számla kiállítása" gombra készül. Kiállítás után a
     * munkalap-ablak újratölt, és a tetején megjelenik az elkészült számla.
     */
    var szamlaKesz = null;     // a frissen kiállított számla, amíg a munkalap-ablak újra nem tölt

    function szamlaIndit(gomb) {
        var urlap = gomb.closest('form');
        var azonosito = urlap ? urlap.querySelector('input[name="id"]') : null;

        if (!urlap || !azonosito || !(parseInt(azonosito.value, 10) > 0)) {
            return;
        }

        var id = azonosito.value;
        var n = sajatSzint(gomb);
        var kert = gomb.getAttribute('data-sdh-szamla');
        // fo / sdh = Számlázz.hu számla; helyi = helyben készülő nyomtatvány (nem számla).
        var sorozat = kert === 'sdh' || kert === 'helyi' ? kert : 'fo';
        var gombok = urlap.querySelectorAll('[data-sdh-szamla]');
        var felirat = gomb.textContent;

        if (!urlap.reportValidity || !urlap.reportValidity()) {
            return;
        }

        Array.prototype.forEach.call(gombok, function (g) { g.disabled = true; });
        gomb.textContent = 'Mentés…';

        var adat = new FormData(urlap);

        adat.set('action', urlap.dataset.sdhAjaxAction);

        fetch(beallitas.ajax, { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (valasz) { return valasz.json(); })
            .then(function (eredmeny) {
                if (!eredmeny || !eredmeny.success) {
                    throw new Error((eredmeny && eredmeny.data && eredmeny.data.uzenet) || 'A munkalap mentése nem sikerült.');
                }

                // A lista (rács) is értesül a mentésről; a popup nyitva marad.
                // (forras: a pénztár ilyenkor nem kérdez – a számla után ajánl.)
                document.dispatchEvent(new CustomEvent('sdh:mentve', {
                    cancelable: true,
                    detail: { action: urlap.dataset.sdhAjaxAction || '', adat: eredmeny.data || {}, forras: 'szamla' }
                }));

                nyit('munkalapok', id, { szint: n });
                szamlaNyit(n, id, sorozat);
            })
            .catch(function (hiba) {
                Array.prototype.forEach.call(gombok, function (g) { g.disabled = false; });
                gomb.textContent = felirat;
                mutatUrlapHiba(urlap, 'A számla előtt a munkalapot el kell menteni. ' + hiba.message);
            });
    }

    function szamlaNyit(n, munkalapId, sorozat) {
        nyit('szamla', munkalapId, {
            szint: Math.min(n + 1, szintek.length - 1),
            parameterek: { sorozat: sorozat },
            siker: function (adat) {
                szamlaKesz = adat || null;
                nyit('munkalapok', munkalapId, { szint: n });
                // A pénztár (penztar.js) beírja / felajánlja a számla pénzét.
                document.dispatchEvent(new CustomEvent('sdh:szamla-kesz', {
                    detail: { munkalapId: munkalapId, sorozat: sorozat, adat: adat || {} }
                }));
            }
        });
    }

    /**
     * A számla ablakának végösszege: a munkalap kipipált tételei + az itt
     * felvett új sorok. A kiállítás és az előnézet gombja csak akkor él, ha
     * van tétel, és a vevő kötelező adatai megvannak.
     */
    function szamlaOsszeg(urlap) {
        var netto = 0;
        var brutto = 0;
        var db = 0;
        var sorokDb = 0;

        Array.prototype.forEach.call(urlap.querySelectorAll('[data-sdh-szamla-tetel]'), function (pipa) {
            sorokDb += 1;

            if (pipa.checked) {
                netto += parseFloat(pipa.getAttribute('data-netto')) || 0;
                brutto += parseFloat(pipa.getAttribute('data-brutto')) || 0;
                db += 1;
            }
        });

        Array.prototype.forEach.call(urlap.querySelectorAll('[data-sdh-szamla-ujtetel]'), function (sor) {
            var sz = szamlaUjSorSzamol(sor);

            sorokDb += 1;

            if (sz.ervenyes) {
                netto += sz.netto;
                brutto += sz.brutto;
                db += 1;
            }
        });

        mlIr(urlap.querySelector('[data-sdh-szamla-ossz="netto"]'), mlPenz(netto));
        mlIr(urlap.querySelector('[data-sdh-szamla-ossz="brutto"]'), mlPenz(brutto));

        var ures = urlap.querySelector('[data-sdh-szamla-ures]');

        if (ures) {
            ures.hidden = sorokDb > 0;
        }

        var hiany = szamlaVevoFrissit(urlap);
        var mehet = db > 0 && hiany.length === 0;

        Array.prototype.forEach.call(urlap.querySelectorAll('[data-sdh-szamla-kuld], [data-sdh-szamla-elonezet]'), function (gomb) {
            if (!gomb.hasAttribute('data-sdh-tiltva')) {
                gomb.disabled = !mehet;
            }
        });
    }

    /* ---- Új tétel a számla ablakában (választóból vagy kézzel) ---- */

    function szamlaUjMezo(sor, nev) {
        return sor.querySelector('[data-u="' + nev + '"]');
    }

    /** Ugyanaz a számolás, mint a szerveren (Szamla::tetel_sor): a nettó egységár két tizedesre kerekítve. */
    function szamlaUjSorSzamol(sor) {
        var afa = szamlaUjMezo(sor, 'afa_kulcs');
        var valasztott = afa && afa.options[afa.selectedIndex];
        var szazalek = valasztott ? parseFloat(valasztott.getAttribute('data-szazalek')) || 0 : 27;
        var menny = mlSzam(szamlaUjMezo(sor, 'mennyiseg').value);
        var ar = mlSzam(szamlaUjMezo(sor, 'brutto_ar').value);
        var nev = szamlaUjMezo(sor, 'megnevezes').value.trim();
        var egysegar = mlKerek(ar / (1 + szazalek / 100));
        var netto = mlKerek(egysegar * (menny > 0 ? menny : 0));
        var brutto = mlKerek(netto + mlKerek(netto * szazalek / 100));

        mlIr(sor.querySelector('[data-u-ki="egysegar"]'), egysegar.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' '));
        mlIr(sor.querySelector('[data-u-ki="netto"]'), mlPenz(netto));
        mlIr(sor.querySelector('[data-u-ki="brutto"]'), mlPenz(brutto));

        return { netto: netto, brutto: brutto, ervenyes: nev !== '' && menny > 0 && ar >= 0 };
    }

    /** Új tételsor a sablonból; `adat` (választóból): { tipus, termek_id, megnevezes, me, brutto_ar, afa_kulcs }. */
    function szamlaSorUj(urlap, adat) {
        var sablon = urlap.querySelector('template[data-sdh-szamla-ujsor]');
        var sorok = urlap.querySelector('[data-sdh-szamla-sorok]');

        if (!sablon || !sorok) {
            return null;
        }

        var i = parseInt(urlap.getAttribute('data-sdh-szamla-ujdb'), 10) || 0;
        var tar = document.createElement('tbody');

        urlap.setAttribute('data-sdh-szamla-ujdb', String(i + 1));
        tar.innerHTML = sablon.innerHTML.replace(/__I__/g, String(i));

        var sor = tar.querySelector('tr');

        if (!sor) {
            return null;
        }

        sorok.appendChild(sor);

        if (adat) {
            szamlaUjMezo(sor, 'megnevezes').value = adat.megnevezes || '';
            szamlaUjMezo(sor, 'me').value = adat.me || 'db';
            szamlaUjMezo(sor, 'brutto_ar').value = adat.brutto_ar > 0 ? szolgAr(adat.brutto_ar) : '';
            szamlaUjMezo(sor, 'termek_id').value = String(adat.termek_id || 0);

            var afa = szamlaUjMezo(sor, 'afa_kulcs');

            if (afa && adat.afa_kulcs !== undefined && afa.querySelector('option[value="' + String(adat.afa_kulcs).replace(/[^A-Za-z0-9]/g, '') + '"]')) {
                afa.value = String(adat.afa_kulcs);
            }

            szamlaTipusBeallit(sor, adat.tipus === 'termek' ? 'termek' : 'szolgaltatas');
        }

        szamlaOsszeg(urlap);

        return sor;
    }

    function szamlaTipusBeallit(sor, tipus) {
        var gomb = sor.querySelector('[data-sdh-szamla-tipus]');

        szamlaUjMezo(sor, 'tipus').value = tipus;

        if (gomb) {
            gomb.textContent = tipus === 'termek' ? 'Termék' : 'Szolgáltatás';
        }
    }

    /** A „+ Termék…" / „+ Szolgáltatás…" / „+ Kézi tétel" gomb. */
    function szamlaUjTetel(gomb) {
        var urlap = gomb.closest('form');
        var fajta = gomb.getAttribute('data-sdh-szamla-uj');
        var kezi = function (tipus, nev) {
            var sor = szamlaSorUj(urlap, { tipus: tipus, megnevezes: nev || '', me: 'db', brutto_ar: 0 });

            if (sor) {
                szamlaUjMezo(sor, 'megnevezes').focus();
            }
        };
        var felvesz = function (tipus) {
            return function (t) {
                var sor = szamlaSorUj(urlap, {
                    tipus: tipus,
                    termek_id: tipus === 'termek' ? t.id : 0,
                    megnevezes: t.nev,
                    me: t.me,
                    brutto_ar: t.ar,
                    afa_kulcs: t.afa
                });
                var menny = sor ? szamlaUjMezo(sor, 'mennyiseg') : null;

                if (menny) {
                    menny.focus();
                    menny.select();
                }
            };
        };

        if (fajta === 'kezi') {
            kezi('szolgaltatas', '');

            return;
        }

        if (fajta === 'termek') {
            szValNyit(gomb, {
                cim: 'Termék a számlára',
                alcim: 'Kattints a sorra, vagy ↑ ↓ és Enter. Vonalkód, cikkszám és termékkód is kereshető.',
                helyorzo: 'Megnevezés, cikkszám, termékkód, vonalkód…',
                egyseg: 'termék',
                oszlopok: [['Megnevezés', ''], ['Cikkszám', '10rem'], ['Készlet', '6.5rem', true], ['Bruttó ár', '7.5rem', true]],
                forras: function (q) { return termBetolt().then(function (adat) { return termKeres(adat, q); }); },
                cellak: function (t) {
                    return [
                        szovegBiztonsagos(t.nev) + (t.kategoria ? ' <span class="sdh-termval__kat">' + szovegBiztonsagos(t.kategoria) + '</span>' : ''),
                        szovegBiztonsagos(t.cikkszam || ''),
                        termKeszletSzoveg(t),
                        t.ar > 0 ? mlPenz(t.ar) + ' Ft' : '—'
                    ];
                },
                valaszt: felvesz('termek'),
                kezi: function (q) { kezi('termek', q); }
            });

            return;
        }

        szValNyit(gomb, {
            cim: 'Szolgáltatás a számlára',
            alcim: 'Kattints a sorra, vagy ↑ ↓ és Enter.',
            helyorzo: 'Szolgáltatás neve…',
            egyseg: 'szolgáltatás',
            oszlopok: [['Megnevezés', ''], ['Egység', '5rem'], ['Bruttó ár', '8rem', true]],
            forras: function (q) { return szolgBetolt().then(function (adat) { return szolgKeres(adat, q); }); },
            cellak: function (t) {
                return [szovegBiztonsagos(t.nev), szovegBiztonsagos(t.me || ''), t.ar > 0 ? mlPenz(t.ar) + ' Ft' : '—'];
            },
            valaszt: felvesz('szolgaltatas'),
            kezi: function (q) { kezi('szolgaltatas', q); }
        });
    }

    /* ---- Általános választó a számla ablaka fölött (termék, szolgáltatás, vevő) ---- */

    var SZVAL_OLDAL = 10;
    var szVal = null;          // { n, o, kerdes, kijelolt, oldal, talalatok, kor }
    var szValIdozito = null;

    function szValTorzs() {
        return szVal && szintek[szVal.n] ? szintek[szVal.n].torzs : null;
    }

    function szValNyitva() {
        var torzs = szValTorzs();

        return !!(torzs && szintek[szVal.n].dialog.open && torzs.querySelector('[data-sdh-szval]'));
    }

    /**
     * o: { cim, alcim, helyorzo, egyseg, oszlopok: [[cím, szélesség, jobbra]], forras(q) → Promise<tömb>,
     *      cellak(t) → [html…], valaszt(t), kezi(q) | null, kezdo, kesleltet (ms, szerveroldali keresésnél) }
     */
    function szValNyit(elem, o) {
        var n = Math.min(sajatSzint(elem) + 1, szintek.length - 1);
        var szint = vaz(n);
        var oszlopDb = o.oszlopok.length;
        var ures = '';

        szint.dialog.sdhModul = 'szamlavalaszto';
        szint.dialog.sdhMeret = meretOlvas('szamlavalaszto');
        szint.dialog.setAttribute('data-sdh-modul', 'szamlavalaszto');

        for (var i = 0; i < SZVAL_OLDAL; i++) {
            ures += '<tr class="sdh-szolgval__ures"><td colspan="' + oszlopDb + '">&nbsp;</td></tr>';
        }

        szint.torzs.innerHTML =
            '<h2 class="sdh-modal__cim">' + szovegBiztonsagos(o.cim) + '</h2>' +
            '<p class="sdh-modal__alcim">' + szovegBiztonsagos(o.alcim || '') + '</p>' +
            '<div class="sdh-szolgval" data-sdh-szval>' +
            '  <div class="sdh-szolgval__fej">' +
            '    <input type="search" autocomplete="off" data-sdh-szval-kereso aria-label="Keresés"' +
            '           placeholder="' + szovegBiztonsagos(o.helyorzo || 'Keresés…') + '">' +
            '  </div>' +
            '  <table class="sdh-tabla sdh-szolgval__tabla sdh-szval__tabla">' +
            '    <thead><tr>' + o.oszlopok.map(function (oszlop) {
                return '<th' + (oszlop[2] ? ' class="is-jobb"' : '') + (oszlop[1] ? ' style="width:' + oszlop[1] + '"' : ' style="width:auto"') + '>' +
                    szovegBiztonsagos(oszlop[0]) + '</th>';
            }).join('') + '</tr></thead>' +
            '    <tbody data-sdh-szval-sorok>' + ures + '</tbody>' +
            '  </table>' +
            '  <div class="sdh-szolgval__lab">' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-lapnyil" data-sdh-szval-lap="-1" aria-label="Előző oldal">' + LAP_NYIL_BAL + '</button>' +
            '    <span class="sdh-szolgval__oldal" data-sdh-szval-oldal></span>' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-lapnyil" data-sdh-szval-lap="1" aria-label="Következő oldal">' + LAP_NYIL_JOBB + '</button>' +
            '    <span class="sdh-szolgval__db" data-sdh-szval-db></span>' +
            (o.kezi ? '    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--jobbra" data-sdh-szval-kezi' +
                '            title="Nem a listából: kézzel írod be">Kézzel írom be</button>' : '') +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos' + (o.kezi ? '' : ' sdh-gomb--jobbra') + '" data-sdh-szval-megsem>Mégsem</button>' +
            '  </div>' +
            '</div>';

        szVal = { n: n, o: o, kerdes: o.kezdo || '', kijelolt: 0, oldal: 0, talalatok: [], kor: 0, toltve: false };

        if (!szint.dialog.open) {
            szint.dialog.showModal();
        }

        var kereso = szint.torzs.querySelector('[data-sdh-szval-kereso]');

        kereso.value = szVal.kerdes;
        kereso.focus();

        szValFrissit();
    }

    function szValFrissit() {
        var allapot = szVal;
        var kor = ++allapot.kor;

        allapot.o.forras(allapot.kerdes).then(function (talalatok) {
            if (szVal !== allapot || allapot.kor !== kor || !szValNyitva()) {
                return;
            }

            allapot.talalatok = talalatok || [];
            allapot.toltve = true;
            allapot.kijelolt = 0;
            szValRajzol();
        }).catch(function () {
            var torzs = szVal === allapot ? szValTorzs() : null;
            var sorok = torzs ? torzs.querySelector('[data-sdh-szval-sorok]') : null;

            if (sorok) {
                sorok.innerHTML = '<tr><td colspan="' + allapot.o.oszlopok.length + '" class="sdh-tabla__ures">A lista nem töltődött be.</td></tr>';
            }
        });
    }

    function szValRajzol() {
        var torzs = szValTorzs();

        if (!torzs || !szVal) {
            return;
        }

        var o = szVal.o;
        var db = szVal.talalatok.length;
        var oszlopDb = o.oszlopok.length;
        var oldalak = Math.max(1, Math.ceil(db / SZVAL_OLDAL));

        szVal.kijelolt = Math.max(0, Math.min(szVal.kijelolt, db - 1));
        szVal.oldal = db > 0 ? Math.floor(szVal.kijelolt / SZVAL_OLDAL) : 0;

        var kezdet = szVal.oldal * SZVAL_OLDAL;
        var html = '';

        // Mindig ugyanannyi sor: a popup magassága gépelés közben nem ugrál.
        for (var i = kezdet; i < kezdet + SZVAL_OLDAL; i++) {
            var t = szVal.talalatok[i];

            if (!t) {
                html += i === 0
                    ? '<tr class="sdh-szolgval__ures"><td colspan="' + oszlopDb + '" class="sdh-tabla__ures">' +
                        (szVal.toltve ? (o.ures && szVal.kerdes === '' ? o.ures : 'Nincs találat.' + (o.kezi ? ' A „Kézzel írom be" gombbal így is felveheted.' : '')) : 'Keresés…') + '</td></tr>'
                    : '<tr class="sdh-szolgval__ures"><td colspan="' + oszlopDb + '">&nbsp;</td></tr>';
                continue;
            }

            html += '<tr data-sdh-szval-sor="' + i + '"' + (i === szVal.kijelolt ? ' class="is-kijelolt" aria-selected="true"' : '') + '>' +
                o.cellak(t).map(function (cella, j) {
                    return '<td class="' + (j === 0 ? 'sdh-szolgval__nev' : '') + (o.oszlopok[j] && o.oszlopok[j][2] ? ' is-jobb' : '') + '">' + cella + '</td>';
                }).join('') + '</tr>';
        }

        torzs.querySelector('[data-sdh-szval-sorok]').innerHTML = html;
        torzs.querySelector('[data-sdh-szval-oldal]').textContent = (szVal.oldal + 1) + ' / ' + oldalak;
        torzs.querySelector('[data-sdh-szval-db]').textContent = db + ' ' + (o.egyseg || 'találat');
        torzs.querySelector('[data-sdh-szval-lap="-1"]').disabled = szVal.oldal <= 0;
        torzs.querySelector('[data-sdh-szval-lap="1"]').disabled = szVal.oldal >= oldalak - 1;
    }

    function szValZar() {
        if (szVal) {
            window.clearTimeout(szValIdozito);
            bezar(szVal.n);
            szVal = null;
        }
    }

    function szValValaszt(t) {
        if (!szVal || !t) {
            return;
        }

        var valaszt = szVal.o.valaszt;

        szValZar();
        valaszt(t);
    }

    /* ---- A vevő a számla ablakában: listáról, adószámból, név alapján, kézzel ---- */

    var VEVO_KOTELEZO = [['nev', 'név'], ['irsz', 'irányítószám'], ['telepules', 'település'], ['cim', 'utca, házszám']];
    var vevoIdozito = null;

    function szamlaVevoDoboz(urlap) {
        return urlap ? urlap.querySelector('[data-sdh-szamla-vevo]') : null;
    }

    function szamlaVevoMezo(urlap, nev) {
        var doboz = szamlaVevoDoboz(urlap);

        return doboz ? doboz.querySelector('[data-v="' + nev + '"]') : null;
    }

    /** Üzenet a vevő doboza alatt. tipus: '' | 'jo' | 'hiba'. Üres szöveggel a hiányjelzés látszik (ha van). */
    function szamlaVevoUzen(urlap, szoveg, tipus) {
        var doboz = szamlaVevoDoboz(urlap);

        if (!doboz) {
            return;
        }

        doboz.sdhUzenet = szoveg ? { szoveg: szoveg, tipus: tipus || '' } : null;
        szamlaVevoFrissit(urlap);
    }

    /** A hiányzó kötelező vevőadatok; közben frissíti az üzenetsort. Visszaadja a hiányzók listáját. */
    function szamlaVevoFrissit(urlap) {
        var doboz = szamlaVevoDoboz(urlap);
        var hiany = [];

        if (!doboz) {
            return hiany;
        }

        var szamlahoz = doboz.getAttribute('data-kotelezo') === '1';

        VEVO_KOTELEZO.forEach(function (par) {
            var mezo = szamlaVevoMezo(urlap, par[0]);
            var ures = !mezo || mezo.value.trim() === '';

            // A helyi nyomtatványhoz elég a név; a számlához a teljes cím kell.
            if (ures && (szamlahoz || par[0] === 'nev')) {
                hiany.push(par[1]);
            }

            if (mezo) {
                mezo.classList.toggle('is-hianyzik', ures && (szamlahoz || par[0] === 'nev'));
            }
        });

        var sor = doboz.querySelector('[data-sdh-szamla-vevo-allapot]');

        if (sor) {
            if (!doboz.hasAttribute('data-sdh-alap')) {
                doboz.setAttribute('data-sdh-alap', sor.textContent.trim());
            }

            var uzenet = doboz.sdhUzenet;

            if (uzenet) {
                sor.className = 'sdh-szamlaurlap__allapot' + (uzenet.tipus ? ' is-' + uzenet.tipus : '');
                mlIr(sor, uzenet.szoveg);
            } else if (hiany.length) {
                sor.className = 'sdh-szamlaurlap__allapot is-hiba';
                mlIr(sor, 'Hiányzik a vevő adataiból: ' + hiany.join(', ') + '.');
            } else {
                sor.className = 'sdh-szamlaurlap__allapot';
                mlIr(sor, szamlaVevoUgyanaz(urlap) ? doboz.getAttribute('data-sdh-alap') : '');
            }
        }

        return hiany;
    }

    function szamlaVevoUgyanaz(urlap) {
        var doboz = szamlaVevoDoboz(urlap);
        var nev = szamlaVevoMezo(urlap, 'nev');

        return !!(doboz && nev && nev.value.trim().toLowerCase() === (doboz.getAttribute('data-eredeti-nev') || '').trim().toLowerCase());
    }

    function szamlaTorzsszam(szoveg) {
        var szamok = String(szoveg || '').replace(/\D/g, '');

        return szamok.length >= 8 ? szamok.slice(0, 8) : '';
    }

    /** A vevő mezőinek kitöltése egy találatból (lista, NAV). Az e-mailt csak akkor írja át, ha a találatban van. */
    function szamlaVevoKitolt(urlap, adat, uzenet) {
        var doboz = szamlaVevoDoboz(urlap);

        if (!doboz || !adat) {
            return;
        }

        ['nev', 'adoszam', 'irsz', 'telepules', 'cim'].forEach(function (nev) {
            var mezo = szamlaVevoMezo(urlap, nev);

            if (mezo && (adat[nev] || nev !== 'adoszam' || !adat.megtartAdoszam)) {
                mezo.value = adat[nev] || '';
            }
        });

        var email = szamlaVevoMezo(urlap, 'email');

        if (email && adat.email) {
            email.value = adat.email;
        }

        // Amit most írtunk be, arra nem indul újabb keresés.
        doboz.sdhNev = (adat.nev || '').trim().toLowerCase();
        doboz.sdhTorzs = szamlaTorzsszam(szamlaVevoMezo(urlap, 'adoszam').value);

        szamlaEmailFrissit(urlap);
        szamlaVevoUzen(urlap, uzenet || '', 'jo');
        szamlaOsszeg(urlap);
    }

    /** Cégadatok az adószámból (NAV a Számlázz.hu-n át; ha az nem megy, a saját ügyfelek). */
    function szamlaAdozo(urlap, csendben) {
        var doboz = szamlaVevoDoboz(urlap);
        var mezo = szamlaVevoMezo(urlap, 'adoszam');
        var torzs = mezo ? szamlaTorzsszam(mezo.value) : '';

        if (!doboz || !mezo) {
            return;
        }

        if (torzs === '') {
            if (!csendben) {
                szamlaVevoUzen(urlap, 'A cég kereséséhez írd be az adószám első 8 számjegyét.', 'hiba');
                mezo.focus();
            }

            return;
        }

        var adat = new FormData();
        var keres = (doboz.sdhKeres = (doboz.sdhKeres || 0) + 1);

        adat.append('action', 'sdh_muhely_szamla_adozo');
        adat.append('_wpnonce', beallitas.nonce || '');
        adat.append('adoszam', mezo.value);

        doboz.sdhTorzs = torzs;
        szamlaVevoUzen(urlap, 'Cég keresése az adószám alapján…', '');

        fetch(beallitas.ajax, { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (valasz) { return valasz.json(); })
            .then(function (eredmeny) {
                if (!urlap.isConnected || doboz.sdhKeres !== keres) {
                    return;
                }

                if (eredmeny && eredmeny.success && eredmeny.data && eredmeny.data.vevo) {
                    var vevo = eredmeny.data.vevo;

                    // Ha a válaszban nincs teljes adószám, a beírt marad.
                    vevo.megtartAdoszam = !vevo.adoszam;
                    szamlaVevoKitolt(urlap, vevo, eredmeny.data.uzenet || '');

                    return;
                }

                szamlaVevoUzen(urlap, (eredmeny && eredmeny.data && eredmeny.data.uzenet) || 'A cég keresése nem sikerült.', 'hiba');
            })
            .catch(function () {
                if (urlap.isConnected && doboz.sdhKeres === keres) {
                    szamlaVevoUzen(urlap, 'A cég keresése nem sikerült (nincs kapcsolat). Töltsd ki kézzel.', 'hiba');
                }
            });
    }

    function szamlaVevoKeres(q) {
        var cim = new URL(beallitas.ajax, window.location.origin);

        cim.searchParams.set('action', 'sdh_muhely_szamla_vevokereso');
        cim.searchParams.set('q', q);
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        return fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (valasz) { return valasz.json(); })
            .then(function (eredmeny) {
                if (!eredmeny || !eredmeny.success) {
                    throw new Error('Üres válasz');
                }

                return eredmeny.data.sorok || [];
            });
    }

    /** A kiválasztott vevő beírása; ha van adószáma, de címe nincs, a NAV-tól pótoljuk. */
    function szamlaVevoValaszt(urlap, t) {
        szamlaVevoKitolt(urlap, t, 'Kitöltve a listából: ' + t.nev + '.');

        if (t.adoszam && (!t.irsz || !t.telepules || !t.cim)) {
            szamlaAdozo(urlap, true);
        }
    }

    function szamlaVevoLista(urlap, kezdo) {
        var forrasNev = { ugyfel: 'Ügyfél', szamla: 'Korábbi számla', ceg: 'Cégadatbázis' };

        szValNyit(urlap, {
            cim: 'Vevő választása',
            alcim: 'Az ügyfelek és a korábbi számlák vevői. Név, adószám, telefonszám vagy e-mail alapján kereshető.',
            helyorzo: 'Név, cégnév, adószám, telefon…',
            egyseg: 'találat',
            kezdo: kezdo || '',
            kesleltet: 220,
            oszlopok: [['Név', ''], ['Adószám', '8.5rem'], ['Cím', '38%'], ['Forrás', '7.5rem']],
            forras: szamlaVevoKeres,
            cellak: function (t) {
                return [
                    szovegBiztonsagos(t.nev),
                    szovegBiztonsagos(t.adoszam || ''),
                    szovegBiztonsagos([t.irsz, t.telepules].filter(Boolean).join(' ') + (t.cim ? ', ' + t.cim : '')),
                    szovegBiztonsagos(forrasNev[t.forras] || '')
                ];
            },
            valaszt: function (t) { szamlaVevoValaszt(urlap, t); },
            kezi: function (q) {
                var nev = szamlaVevoMezo(urlap, 'nev');

                if (nev) {
                    if (q) {
                        nev.value = q;
                    }

                    szamlaVevoDoboz(urlap).sdhNev = nev.value.trim().toLowerCase();
                    nev.focus();
                    szamlaOsszeg(urlap);
                }
            }
        });
    }

    /**
     * Beírt (cég)név: megkeressük a listában. Egyetlen pontos egyezésnél kitölt,
     * több találatnál a választó nyílik, találat nélkül a kézi kitöltés marad.
     */
    function szamlaVevoNev(urlap) {
        var doboz = szamlaVevoDoboz(urlap);
        var mezo = szamlaVevoMezo(urlap, 'nev');
        var q = mezo ? mezo.value.trim() : '';
        var kulcs = q.toLowerCase();

        if (!doboz || q.length < 3 || szamlaVevoUgyanaz(urlap) || doboz.sdhNev === kulcs) {
            return;
        }

        var keres = (doboz.sdhKeres = (doboz.sdhKeres || 0) + 1);

        doboz.sdhNev = kulcs;
        szamlaVevoUzen(urlap, 'Keresés a listában: ' + q + '…', '');

        szamlaVevoKeres(q).then(function (sorok) {
            if (!urlap.isConnected || doboz.sdhKeres !== keres || szVal) {
                return;
            }

            var pontos = sorok.filter(function (t) { return t.nev.trim().toLowerCase() === kulcs; });

            if (pontos.length === 1) {
                szamlaVevoValaszt(urlap, pontos[0]);
            } else if (sorok.length) {
                szamlaVevoUzen(urlap, '', '');
                szamlaVevoLista(urlap, q);
            } else {
                szamlaVevoUzen(urlap, '„' + q + '" nincs a listában. Add meg az adószámát – abból a cím kitöltődik –, vagy írd be kézzel.', '');
            }
        }).catch(function () {
            if (urlap.isConnected && doboz.sdhKeres === keres) {
                szamlaVevoUzen(urlap, '', '');
            }
        });
    }

    /** „E-mail az ügyfélnek": pipánál az e-mail-cím kötelező – ha nincs, a mezőre ugrunk érte. */
    function szamlaEmailFrissit(urlap, ugras) {
        var pipa = urlap.querySelector('[data-sdh-szamla-email]');
        var mezo = szamlaVevoMezo(urlap, 'email');

        if (!pipa || !mezo) {
            return;
        }

        var kell = pipa.checked && !pipa.closest('[hidden]');

        mezo.required = kell;
        mezo.classList.toggle('is-hianyzik', kell && mezo.value.trim() === '');

        if (kell && ugras && mezo.value.trim() === '') {
            szamlaVevoUzen(urlap, 'Írd be a vevő e-mail-címét – a Számlázz.hu erre küldi a számlát.', 'hiba');
            mezo.focus();
        } else if (szamlaVevoDoboz(urlap).sdhUzenet && /e-mail-címét/.test(szamlaVevoDoboz(urlap).sdhUzenet.szoveg) && (!kell || mezo.value.trim() !== '')) {
            szamlaVevoUzen(urlap, '', '');
        }
    }

        /**
     * PDF új lapon a számla ablakából:
     *   előnézet     – a Számlázz.hu PDF-je, számla nem készül;
     *   nyomtatvány  – a helyben készülő számla-előkészítő (API nélkül); ehhez
     *                  letöltő link is megjelenik a gomb mellett.
     */
    function szamlaPdf(gomb, muvelet, folyamatban) {
        var urlap = gomb.closest('form');
        var felirat = gomb.textContent;
        // Az új lapot még a kattintásban kell megnyitni, különben a böngésző letiltja.
        var ablak = window.open('', '_blank');
        var adat = new FormData(urlap);
        var fajlnev = gomb.getAttribute('data-fajlnev') || '';
        var letoltes = urlap.querySelector('[data-sdh-szamla-letoltes]');

        adat.set('action', muvelet);
        gomb.disabled = true;
        gomb.textContent = folyamatban;

        fetch(beallitas.ajax, { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (valasz) {
                if ((valasz.headers.get('Content-Type') || '').indexOf('application/pdf') === 0) {
                    return valasz.blob().then(function (pdf) {
                        var cim = URL.createObjectURL(pdf);

                        if (ablak) {
                            ablak.location.href = cim;
                        } else {
                            window.open(cim, '_blank');
                        }

                        if (letoltes && fajlnev) {
                            var link = document.createElement('a');

                            link.href = cim;
                            link.download = fajlnev;
                            link.className = 'sdh-szamlajel';
                            link.setAttribute('data-sdh-szamla-letolt', '');
                            link.textContent = 'Letöltés: ' + fajlnev;
                            letoltes.textContent = '';
                            letoltes.appendChild(link);
                        }
                    });
                }

                return valasz.json().then(function (eredmeny) {
                    throw new Error((eredmeny && eredmeny.data && eredmeny.data.uzenet) || 'A PDF nem készült el.');
                });
            })
            .catch(function (hiba) {
                if (ablak) {
                    ablak.close();
                }

                mutatUrlapHiba(urlap, hiba.message);
            })
            .then(function () {
                gomb.disabled = false;
                gomb.textContent = felirat;
            });
    }

    /** Beállítások: a kapcsolat ellenőrzése (számla nem készül). */
    function szamlaKapcsolat(gomb) {
        var ki = gomb.parentNode.querySelector('[data-sdh-szamla-kapcsolat-ki]');
        var adat = new FormData();

        adat.append('action', 'sdh_muhely_szamla_kapcsolat');
        adat.append('_wpnonce', beallitas.nonce || '');
        gomb.disabled = true;

        if (ki) {
            ki.className = '';
            ki.textContent = 'Ellenőrzés…';
        }

        fetch(beallitas.ajax, { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (valasz) { return valasz.json(); })
            .then(function (eredmeny) {
                if (ki) {
                    ki.className = eredmeny && eredmeny.success ? 'is-jo' : 'is-hiba';
                    ki.textContent = (eredmeny && eredmeny.data && eredmeny.data.uzenet) || 'Ismeretlen válasz.';
                }
            })
            .catch(function () {
                if (ki) {
                    ki.className = 'is-hiba';
                    ki.textContent = 'A kérés nem ment el.';
                }
            })
            .then(function () {
                gomb.disabled = false;
            });
    }

    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;

        if (!cel.closest) {
            return;
        }

        // --- A számla ablakának választója (termék, szolgáltatás, vevő) ---
        if (szVal && cel.closest('[data-sdh-szval]')) {
            var szvSor = cel.closest('[data-sdh-szval-sor]');
            var szvLap = cel.closest('[data-sdh-szval-lap]');

            if (szvSor) {
                szValValaszt(szVal.talalatok[parseInt(szvSor.getAttribute('data-sdh-szval-sor'), 10)]);
            } else if (szvLap) {
                szVal.kijelolt = (szVal.oldal + parseInt(szvLap.getAttribute('data-sdh-szval-lap'), 10)) * SZVAL_OLDAL;
                szValRajzol();
            } else if (cel.closest('[data-sdh-szval-kezi]')) {
                var szvKezi = szVal.o.kezi;
                var szvKerdes = szVal.kerdes.trim();

                szValZar();
                szvKezi(szvKerdes);
            } else if (cel.closest('[data-sdh-szval-megsem]')) {
                szValZar();
            }

            return;
        }

        var ujTetel = cel.closest('[data-sdh-szamla-uj]');

        if (ujTetel) {
            esemeny.preventDefault();
            szamlaUjTetel(ujTetel);

            return;
        }

        var sorTorol = cel.closest('[data-sdh-szamla-sortorol]');

        if (sorTorol) {
            var torlendo = sorTorol.closest('tr');
            var torolUrlap = sorTorol.closest('form');

            esemeny.preventDefault();
            torlendo.remove();
            szamlaOsszeg(torolUrlap);

            return;
        }

        var tipusGomb = cel.closest('[data-sdh-szamla-tipus]');

        if (tipusGomb) {
            var tipusSor = tipusGomb.closest('tr');

            esemeny.preventDefault();
            // A típus váltásával a sor elengedi a terméktörzset (kézi tétel lesz).
            szamlaUjMezo(tipusSor, 'termek_id').value = '0';
            szamlaTipusBeallit(tipusSor, szamlaUjMezo(tipusSor, 'tipus').value === 'termek' ? 'szolgaltatas' : 'termek');

            return;
        }

        var megjGomb = cel.closest('[data-sdh-szamla-megj]');

        if (megjGomb) {
            var megjMezo = megjGomb.closest('td').querySelector('[data-sdh-szamla-megjmezo]');

            esemeny.preventDefault();

            if (megjMezo) {
                // A kitöltött megjegyzés nem tűnik el: csak az üres mező csukható vissza.
                megjMezo.hidden = !megjMezo.hidden && megjMezo.value.trim() === '';

                if (!megjMezo.hidden) {
                    megjMezo.focus();
                }
            }

            return;
        }

        var vevoLista = cel.closest('[data-sdh-szamla-vevo-lista]');

        if (vevoLista) {
            esemeny.preventDefault();
            szamlaVevoLista(vevoLista.closest('form'), '');

            return;
        }

        var adozoGomb = cel.closest('[data-sdh-szamla-adozo]');

        if (adozoGomb) {
            esemeny.preventDefault();
            szamlaAdozo(adozoGomb.closest('form'), false);

            return;
        }

        var szamlaGomb = cel.closest('[data-sdh-szamla]');

        if (szamlaGomb) {
            esemeny.preventDefault();
            szamlaIndit(szamlaGomb);

            return;
        }

        var elonezet = cel.closest('[data-sdh-szamla-elonezet]');

        if (elonezet) {
            esemeny.preventDefault();
            szamlaPdf(elonezet, 'sdh_muhely_szamla_elonezet', 'Előnézet készül…');

            return;
        }

        var nyomtat = cel.closest('[data-sdh-szamla-nyomtat]');

        if (nyomtat) {
            esemeny.preventDefault();
            szamlaPdf(nyomtat, 'sdh_muhely_szamla_nyomtatvany', 'PDF készül…');

            return;
        }

        var kapcsolat = cel.closest('[data-sdh-szamla-kapcsolat]');

        if (kapcsolat) {
            esemeny.preventDefault();
            szamlaKapcsolat(kapcsolat);
        }
    });

    document.addEventListener('change', function (esemeny) {
        var mezo = esemeny.target;
        var urlap = mezo && mezo.closest ? mezo.closest('[data-sdh-szamlaurlap]') : null;

        if (!urlap) {
            return;
        }

        if (mezo.matches('[data-sdh-szamla-email]')) {
            szamlaEmailFrissit(urlap, true);

            return;
        }

        if (mezo.matches('[data-v="nev"]')) {
            szamlaVevoNev(urlap);
        } else if (mezo.matches('[data-v="adoszam"]')) {
            // Kilépéskor egységes alak: 12345678-1-12.
            var szamok = mezo.value.replace(/\D/g, '');

            if (szamok.length === 11) {
                mezo.value = szamok.slice(0, 8) + '-' + szamok.slice(8, 9) + '-' + szamok.slice(9);
            }
        }

        szamlaOsszeg(urlap);
    });

    document.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target;

        if (!mezo || !mezo.matches) {
            return;
        }

        // A választó keresője: helyi listánál azonnal, szerveroldali keresésnél rövid késleltetéssel.
        if (szVal && mezo.matches('[data-sdh-szval-kereso]')) {
            szVal.kerdes = mezo.value;
            window.clearTimeout(szValIdozito);

            if (szVal.o.kesleltet) {
                szValIdozito = window.setTimeout(szValFrissit, szVal.o.kesleltet);
            } else {
                szValFrissit();
            }

            return;
        }

        var urlap = mezo.closest('[data-sdh-szamlaurlap]');

        if (!urlap) {
            return;
        }

        if (mezo.matches('[data-v]')) {
            var doboz = szamlaVevoDoboz(urlap);

            // Kézi átírásnál a korábbi üzenet (pl. „Kitöltve a NAV adataiból") már nem igaz.
            doboz.sdhUzenet = null;

            if (mezo.matches('[data-v="adoszam"]')) {
                var torzs = szamlaTorzsszam(mezo.value);
                var hossz = mezo.value.replace(/\D/g, '').length;

                window.clearTimeout(vevoIdozito);

                // Teljes törzsszám (8 jegy) vagy teljes adószám (11 jegy): magától megkeresi a céget.
                if (torzs !== '' && torzs !== doboz.sdhTorzs && (hossz === 8 || hossz === 11)) {
                    vevoIdozito = window.setTimeout(function () {
                        szamlaAdozo(urlap, true);
                    }, 450);
                }
            }

            if (mezo.matches('[data-v="email"]')) {
                szamlaEmailFrissit(urlap, false);
            }
        }

        szamlaOsszeg(urlap);
    });

    document.addEventListener('keydown', function (esemeny) {
        var mezo = esemeny.target;

        if (!mezo || !mezo.matches) {
            return;
        }

        if (szVal && mezo.matches('[data-sdh-szval-kereso]')) {
            var db = szVal.talalatok.length;
            var lepes = { ArrowDown: 1, ArrowUp: -1, PageDown: SZVAL_OLDAL, PageUp: -SZVAL_OLDAL }[esemeny.key];

            if (lepes && db > 0) {
                esemeny.preventDefault();
                szVal.kijelolt = Math.max(0, Math.min(db - 1, szVal.kijelolt + lepes));
                szValRajzol();
            } else if (esemeny.key === 'Enter') {
                esemeny.preventDefault();

                if (db > 0) {
                    szValValaszt(szVal.talalatok[szVal.kijelolt]);
                }
            }

            return;
        }

        // A számla ablakában az Enter SOHA nem állít ki számlát egy szövegmezőből:
        // a névnél és az adószámnál keres, máshol nem csinál semmit.
        if (esemeny.key === 'Enter' && mezo.tagName === 'INPUT' && mezo.type !== 'checkbox' && mezo.closest('[data-sdh-szamlaurlap]')) {
            var urlap = mezo.closest('[data-sdh-szamlaurlap]');

            esemeny.preventDefault();

            if (mezo.matches('[data-v="nev"]')) {
                szamlaVevoNev(urlap);
            } else if (mezo.matches('[data-v="adoszam"]')) {
                window.clearTimeout(vevoIdozito);
                szamlaAdozo(urlap, false);
            }
        }
    }, true);

    // Ha a választót a × gombbal vagy Esc-pel zárják be, az állapota is törlődik.
    document.addEventListener('close', function (esemeny) {
        if (szVal && szintek[szVal.n] && esemeny.target === szintek[szVal.n].dialog && !esemeny.target.open) {
            window.clearTimeout(szValIdozito);
            szVal = null;
        }
    }, true);

    document.addEventListener('sdh:urlap-betoltve', function (esemeny) {
        var reszlet = esemeny.detail || {};
        var torzs = reszlet.torzs;

        if (!torzs) {
            return;
        }

        var szamlaUrlap = torzs.querySelector('[data-sdh-szamlaurlap]');

        if (szamlaUrlap) {
            // Amit a szerver tiltott le (nincs Agent kulcs), azt a kitöltés nem oldja fel.
            Array.prototype.forEach.call(szamlaUrlap.querySelectorAll('[data-sdh-szamla-kuld], [data-sdh-szamla-elonezet]'), function (gomb) {
                if (gomb.disabled) {
                    gomb.setAttribute('data-sdh-tiltva', '');
                }
            });

            var vevoDoboz = szamlaVevoDoboz(szamlaUrlap);

            // A megnyitáskor beírt névre és adószámra nem indul keresés.
            if (vevoDoboz) {
                vevoDoboz.sdhNev = szamlaVevoMezo(szamlaUrlap, 'nev').value.trim().toLowerCase();
                vevoDoboz.sdhTorzs = szamlaTorzsszam(szamlaVevoMezo(szamlaUrlap, 'adoszam').value);
            }

            szamlaEmailFrissit(szamlaUrlap, false);
            szamlaOsszeg(szamlaUrlap);

            return;
        }

        // Kiállítás után az újratöltött munkalap-ablak tetején: az elkészült számla.
        if (szamlaKesz && reszlet.modul === 'munkalapok') {
            var kesz = szamlaKesz;
            var urlap = torzs.querySelector('form');

            szamlaKesz = null;

            if (urlap) {
                var doboz = document.createElement('div');

                doboz.className = 'sdh-uzenet sdh-uzenet--siker sdh-szamla-kesz';
                doboz.setAttribute('data-sdh-szamla-kesz', kesz.szamlaszam || '');
                doboz.textContent = 'Elkészült a számla: ' + (kesz.szamlaszam || '') +
                    (kesz.brutto ? ' · ' + mlPenz(kesz.brutto) + ' Ft' : '') +
                    (kesz.email ? ' · a Számlázz.hu e-mailben elküldi az ügyfélnek' : '') + ' · ';

                if (kesz.pdf) {
                    var link = document.createElement('a');

                    link.href = kesz.pdf;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.textContent = 'PDF megnyitása';
                    doboz.appendChild(link);
                }

                urlap.insertBefore(doboz, urlap.firstChild);
            }
        }
    });

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
        // Saját lépésköz (data-sdh-lepes): a gomb ennyit lép, de a mező bármilyen
        // értéket elfogad – a step attribútum a közbenső értéket (pl. 4500 Ft
        // előleg 1000-es lépésnél) érvénytelennek venné, és a mentést megfogná.
        var lepes = parseFloat(input.getAttribute('data-sdh-lepes') || '');

        if (lepes > 0) {
            var most = parseFloat(input.value);
            var uj = (isNaN(most) ? 0 : most) + irany * lepes;

            if (input.min !== '') {
                uj = Math.max(parseFloat(input.min), uj);
            }

            if (input.max !== '') {
                uj = Math.min(parseFloat(input.max), uj);
            }

            input.value = String(Math.round(uj * 100) / 100);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));

            return;
        }

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

    /* ---------------------------------------------------------------- */
    /* Áttekintés: a modulcsempék elrejtése / megjelenítése             */
    /* ---------------------------------------------------------------- */

    function csempekAllapot() {
        var csempek = document.querySelector('[data-sdh-csempek]');
        var gomb = document.querySelector('[data-sdh-csempek-valt]');

        if (csempek && gomb) {
            gomb.textContent = csempek.hidden ? 'Csempék megjelenítése' : 'Csempék elrejtése';
            gomb.setAttribute('aria-expanded', csempek.hidden ? 'false' : 'true');
        }
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-csempek-valt]') : null;
        var csempek = document.querySelector('[data-sdh-csempek]');

        if (!gomb || !csempek) {
            return;
        }

        esemeny.preventDefault();
        csempek.hidden = !csempek.hidden;

        try {
            window.localStorage.setItem('sdh-csempek', csempek.hidden ? 'rejtve' : 'latszik');
        } catch (hiba) {
            /* privát módban a választás csak erre a betöltésre szól */
        }

        csempekAllapot();
        // A munkalap-rács az így felszabaduló (vagy elfoglalt) helyhez igazodik.
        window.dispatchEvent(new Event('resize'));
    });

    csempekAllapot();

    // Más fájlok (levelezes.js) ezeken keresztül nyitnak popupot és választót.
    window.SDH_MUHELY_APP = {
        nyit: nyit,
        bezar: bezar,
        sajatSzint: sajatSzint,
        valaszto: szValNyit,
        pillek: pillNyit,
        hiba: mutatUrlapHiba
    };

    document.addEventListener('DOMContentLoaded', function () {
        szamKeres(document);
    });

    if (document.readyState !== 'loading') {
        szamKeres(document);
    }

}());
