/*
 * popup (modál) kezelés.
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

    /*
     * Popup bezárása a sötét háttérre kattintva: csak akkor, ha az egérgombot
     * is a háttéren nyomták le. Ha a lenyomás a popupban volt (szövegkijelölés,
     * legördülő lista, húzás), és csak a felengedés esett kívülre, a böngésző a
     * kattintást a közös ősre – a <dialog>-ra – küldi, ami háttérkattintásnak
     * látszana, és bezárná a félig kitöltött űrlapot. Minden popupra érvényes
     * (a többi modulé is), mert még a saját figyelőjük előtt megfogjuk.
     */
    var egerLenyomva = null;

    document.addEventListener('mousedown', function (esemeny) {
        egerLenyomva = esemeny.target;
    }, true);

    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;

        // detail === 0: billentyűzetről indított kattintás, annak nincs lenyomási helye.
        if (cel && cel.tagName === 'DIALOG' && esemeny.detail > 0 && egerLenyomva !== cel) {
            esemeny.stopPropagation();
        }
    }, true);

    /* ---------------------------------------------------------------- */
    /* A popup váza – szintenként egyszer jön létre, utána újrahasznosul */
    /* ---------------------------------------------------------------- */

    /**
     * Pénz került a kasszába (0.67.0) – „sdh:penz-be" esemény, erre az asszisztens figurája
     * vigyázzba áll, összecsattantja a bokáját és tiszteleg. Új bevétel vagy befizetés a
     * pénztár űrlapján, illetve munkalap / árukiadás / rendelés fizetése. Szerkesztésre,
     * kivétre és kifizetésre nem.
     */
    function penzBeJelez(urlap) {
        var akcio = urlap && urlap.dataset ? urlap.dataset.sdhAjaxAction || '' : '';
        var be = false;

        if (akcio === 'sdh_muhely_penztar_fizet') {
            be = true;
        } else if (akcio === 'sdh_muhely_penztar_ment') {
            var id = urlap.querySelector('input[name="id"]');
            var t = urlap.querySelector('input[name="tipus"]:checked');
            var tipus = t ? t.value : (urlap.getAttribute('data-tipus') || 'bevetel');

            be = !(id && parseInt(id.value, 10) > 0) && (tipus === 'bevetel' || tipus === 'befizetes');
        }

        if (be) {
            document.dispatchEvent(new CustomEvent('sdh:penz-be', { detail: { forras: akcio } }));
        }
    }

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
        // 0.64: a popup a betöltés alatt már a végleges (legutóbbi) méretében áll – nem „nyílik ki".
        popupRogzit(szint.dialog);
        szint.torzs.innerHTML = '<div class="sdh-modal__toltes">Betöltés…</div>';
    }

    function hiba(szint, szoveg) {
        popupElenged(szint.dialog);
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

    /*
     * Betöltés alatti rögzített méret (0.64).
     *
     * Régen a popup a kis „Betöltés…" felirattal nyílt meg, aztán a tartalom
     * beérkezésekor megnőtt, majd az illesztés (zoom) összehúzta – az ablak
     * kétszer-háromszor „ugrott". Most az ablak űrlapfajtánként megjegyzi a
     * legutóbbi végleges méretét és nagyítását; a következő megnyitáskor a
     * betöltés alatt ebben áll, az illesztés addig szünetel, és a tartalom
     * beillesztése után egyetlen lépésben (ugyanabban a képkockában) áll be
     * a pontos méret.
     */
    var POPUP_UTOLSO = {};

    function popupUtolsoKulcs(modul) {
        return 'sdh-popup-utolso:' + (modul || 'altalanos');
    }

    function popupUtolso(modul) {
        var m = POPUP_UTOLSO[modul];

        if (!m) {
            try {
                m = JSON.parse(window.localStorage.getItem(popupUtolsoKulcs(modul)) || 'null');
            } catch (e) { m = null; }
        }

        // Más ablakméretnél (másik monitor, átméretezett böngésző) nem érvényes.
        if (!m || m.vw !== window.innerWidth || m.vh !== window.innerHeight || !(m.w > 0) || !(m.h > 0) || !(m.z > 0)) {
            return null;
        }

        return m;
    }

    function popupRogzit(dialog) {
        var m = popupUtolso(dialog.sdhModul);
        var doboz = dialog.querySelector('.sdh-modal__doboz');

        if (!m || !doboz) {
            return;
        }

        dialog.classList.add('sdh-modal--tolt');
        dialog.style.zoom = m.z !== 1 ? String(m.z) : '';
        dialog.style.width = (m.w / m.z) + 'px';
        doboz.style.minHeight = (m.h / m.z) + 'px';
    }

    /** A rögzítés feloldása és az egyszeri, azonnali illesztés (a tartalom már bent van). */
    function popupElenged(dialog) {
        if (!dialog || !dialog.classList.contains('sdh-modal--tolt')) {
            return;
        }

        var doboz = dialog.querySelector('.sdh-modal__doboz');

        dialog.classList.remove('sdh-modal--tolt');
        dialog.style.width = '';

        if (doboz) {
            doboz.style.minHeight = '';
        }
    }

    function popupBeallt(dialog) {
        popupElenged(dialog);
        illesztPopup(dialog);

        // A beillesztés DOM-változásaira már nem kell újra illeszteni (ez a hívás elvégezte).
        if (dialog.sdhIllesztFigyelo) {
            dialog.sdhIllesztFigyelo.takeRecords();
        }
    }

    /**
     * Ha a popup magasabb, mint a képernyő, nem görgetőssé tesszük, hanem
     * arányosan kicsinyítjük (CSS zoom). Mérés közben a zoom nulla, így a
     * természetes magasságot látjuk; a mérés és a beállítás egy lépésben fut.
     */
    function illesztPopup(dialog) {
        if (!dialog || !dialog.open || dialog.classList.contains('sdh-modal--tolt')) {
            return;
        }

        dialog.sdhIllesztDb = (dialog.sdhIllesztDb || 0) + 1;

        // Telefonon (0.96.0) a kicsinyítés olvashatatlan lenne: a popup kitölti a képernyőt, és görgethető.
        if (window.innerWidth < 600) {
            dialog.style.zoom = '';
            dialog.classList.remove('sdh-modal--illeszt');
            dialog.classList.add('sdh-modal--telefon');

            return;
        }

        dialog.classList.remove('sdh-modal--telefon');

        var elozo = dialog.style.zoom;

        dialog.style.zoom = '';
        dialog.classList.add('sdh-modal--illeszt');
        meretBeallit(dialog, 1);

        var termeszetes = dialog.getBoundingClientRect().height;
        var szabad = window.innerHeight - MERET_SZEL;
        var zoom = 1;

        // A class marad: a popupnak nincs max-magassága és görgetése, csak zoomja.
        if (termeszetes > szabad + 0.5 && termeszetes > 0) {
            zoom = Math.max(ILLESZT_MIN, szabad / termeszetes);

            // A kért (vagy teljes képernyős) méret a kicsinyítés UTÁNI méret:
            // a popupot annyival szélesebbre vesszük, amennyivel a zoom összehúzza.
            if (dialog.sdhMeret) {
                meretBeallit(dialog, zoom);
                termeszetes = dialog.getBoundingClientRect().height;
                zoom = Math.max(ILLESZT_MIN, Math.min(zoom, szabad / termeszetes));
            }

            // Apró eltérésre (fél százalék alatt) nem változik a betűméret: ne „lélegezzen" a lap.
            var regi = parseFloat(elozo || '1') || 1;

            if (elozo && Math.abs(regi - zoom) < 0.005) {
                zoom = regi;
            }

            dialog.style.zoom = String(zoom);

            if (dialog.sdhMeret && zoom === regi) {
                meretBeallit(dialog, zoom);
            }
        }

        // Az AI-figura (Szikra) a popupba költözik: a zoom őt ne kicsinyítse (asszisztens.js helyez()).
        Array.prototype.forEach.call(dialog.querySelectorAll(':scope > .sdh-ai'), function (figura) {
            figura.style.zoom = zoom !== 1 ? String(1 / zoom) : '';
        });

        // A végleges méret megjegyzése a következő megnyitás betöltési idejére (popupRogzit).
        if (dialog.sdhModul && !dialog.querySelector('.sdh-modal__toltes')) {
            var r = dialog.getBoundingClientRect();

            POPUP_UTOLSO[dialog.sdhModul] = {
                w: Math.round(r.width), h: Math.round(r.height), z: zoom,
                vw: window.innerWidth, vh: window.innerHeight
            };
        }
    }

    /**
     * Ami a popup magasságát nem befolyásolja, az ne indítsa újra az
     * illesztést: az AI-figura (Szikra) mozgása, jelenetei, buborékja és a
     * fogaskerék lenyílója. Különben Szikra minden mozdulatára újramérődne
     * az ablak, és vele „mozogna" a munkalap betűmérete (0.62.1).
     */
    var ILLESZT_KIHAGY = '.sdh-ai, .sdh-ub, .sdh-emlek-lista';

    function illesztKihagy(csomopont) {
        var elem = csomopont && csomopont.nodeType === 1 ? csomopont : (csomopont ? csomopont.parentElement : null);

        return !!(elem && elem.closest && elem.closest(ILLESZT_KIHAGY));
    }

    function illesztSzamit(valtozasok) {
        return valtozasok.some(function (v) {
            if (illesztKihagy(v.target)) {
                return false;
            }

            if (v.type !== 'childList') {
                return true;
            }

            var mind = Array.prototype.slice.call(v.addedNodes).concat(Array.prototype.slice.call(v.removedNodes));

            return mind.length === 0 || mind.some(function (cs) {
                return !illesztKihagy(cs) && !(cs.nodeType === 1 && cs.matches && cs.matches(ILLESZT_KIHAGY));
            });
        });
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

        dialog.sdhIllesztFigyelo = new MutationObserver(function (valtozasok) {
            if (illesztSzamit(valtozasok)) {
                kesleltet();
            }
        });

        // Bezáráskor a megjegyzett méret a böngészőben is megmarad (a következő oldalbetöltésre).
        dialog.addEventListener('close', function () {
            var m = POPUP_UTOLSO[dialog.sdhModul];

            if (m) {
                try {
                    window.localStorage.setItem(popupUtolsoKulcs(dialog.sdhModul), JSON.stringify(m));
                } catch (e) { /* nincs tárhely: csak erre a munkamenetre szól */ }
            }
        });

        dialog.sdhIllesztFigyelo.observe(dialog, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['open', 'hidden']
        });

        // A lapfülek rádiógombjai és a mezők is változtatják a magasságot (a fogaskerék kapcsolói élőben rejtenek: azok is).
        var esemenyre = function (esemeny) {
            if (!illesztKihagy(esemeny.target) || (esemeny.target.closest && esemeny.target.closest('.sdh-ub-panel'))) {
                kesleltet();
            }
        };

        dialog.addEventListener('change', esemenyre);
        dialog.addEventListener('input', esemenyre);
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

        // 0.64: összefűzött csomag (Gyors betöltés) – a címkén a forrásfájlok verziója; eltérésnél egyszer frissít.
        var cssCsomag = document.querySelector('link[data-sdh-css-v]');
        var jsCsomag = document.querySelector('script[data-sdh-js-v]');

        if ((cssCsomag && resz[0] && cssCsomag.getAttribute('data-sdh-css-v') !== resz[0]) ||
            (jsCsomag && resz[1] && jsCsomag.getAttribute('data-sdh-js-v') !== resz[1])) {
            egyszerFrissit('sdhFrissitve:' + resz[0] + '|' + resz[1]);

            return;
        }

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
            egyszerFrissit('sdhFrissitve:' + resz[1]);
        }
    }

    /** Az oldal egyszeri újratöltése (ugyanarra a verzióra csak egyszer – nem kerülhet körbe). */
    function egyszerFrissit(kulcs) {
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
        szint.modul = modul;
        szint.id = id;
        szint.opciok = opciok;
        szint.dialog.setAttribute('data-sdh-modul', modul);

        // Csendes frissítés (automatikus mentés, számla után): a tartalom a helyén marad, amíg az új meg nem jön.
        if (!opciok.csendes) {
            toltesKozben(szint);
        }

        if (!szint.dialog.open) {
            szint.dialog.showModal();
        }

        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_' + modul + '_urlap');
        cim.searchParams.set('id', id || '0');
        cim.searchParams.set('kontextus', beallitas.kontextus || 'admin');
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        // Egyszerre egy ablak (0.71.0): a szerver ebből tudja, melyik böngészőlap nyitja a rekordot (utkozes.js).
        if (window.SDH_UTKOZES && window.SDH_UTKOZES.fiok) {
            cim.searchParams.set('szerk_fiok', window.SDH_UTKOZES.fiok());
        }

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
                if (opciok.csendes && typeof opciok.elotte === 'function' && !opciok.elotte()) {
                    return;
                }

                beilleszt(szint, n, modul, id, opciok, html);
            })
            .catch(function (ok) {
                if (opciok.csendes) {
                    return;
                }

                hiba(szint, 'Az űrlap nem töltődött be. ' + ok.message);
            });
    }

    /**
     * A betöltött űrlap beillesztése és bekötése. Csendes frissítésnél megmarad
     * a nyitott lapfül, a fókusz (a kurzor helyével) és a görgetés – a felhasználó
     * nem veszi észre, hogy a tartalom kicserélődött.
     */
    function beilleszt(szint, n, modul, id, opciok, html) {
        var allas = opciok.csendes ? urlapAllas(szint) : null;

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
            detail: { modul: modul, id: id, torzs: szint.torzs, ful: opciok.ful || '', csendes: !!opciok.csendes }
        }));

        if (allas) {
            urlapAllasVissza(szint, allas);
            popupBeallt(szint.dialog);

            return;
        }

        popupBeallt(szint.dialog);

        var kertSzam = kertFul ? (kertFul.className.match(/sdh-fulek__ful--(\d+)/) || [])[1] : '';
        var elso = kertSzam
            ? kertFul.parentNode.querySelector(':scope > .sdh-fulek__panel--' + kertSzam + ' textarea')
            : szint.torzs.querySelector('input:not([type="hidden"]), select, textarea');
        if (elso) {
            elso.focus();
        }
    }

    /** A nyitott lapfülek, a fókuszban lévő mező és a görgetések – a csendes frissítés előtt. */
    function urlapAllas(szint) {
        var akt = document.activeElement;
        var gorgetes = [];

        Array.prototype.forEach.call(szint.torzs.querySelectorAll('*'), function (el, i) {
            if (el.scrollTop > 0) {
                gorgetes.push([i, el.scrollTop]);
            }
        });

        return {
            fulek: Array.prototype.map.call(szint.torzs.querySelectorAll('input.sdh-fulek__ful:checked'), function (f) { return f.id; }),
            fokusz: akt && szint.torzs.contains(akt) ? (akt.id || akt.getAttribute('name') || '') : '',
            kurzor: akt && typeof akt.selectionStart === 'number' ? [akt.selectionStart, akt.selectionEnd] : null,
            torzs: szint.torzs.scrollTop,
            gorgetes: gorgetes
        };
    }

    function urlapAllasVissza(szint, a) {
        a.fulek.forEach(function (fulId) {
            var f = fulId ? szint.torzs.querySelector('#' + CSS.escape(fulId)) : null;

            if (f && !f.checked) {
                f.checked = true;
                f.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        var elemek = szint.torzs.querySelectorAll('*');

        a.gorgetes.forEach(function (g) {
            if (elemek[g[0]]) {
                elemek[g[0]].scrollTop = g[1];
            }
        });
        szint.torzs.scrollTop = a.torzs;

        if (a.fokusz) {
            var mezo = szint.torzs.querySelector('#' + CSS.escape(a.fokusz)) || szint.torzs.querySelector('[name="' + a.fokusz.replace(/"/g, '') + '"]');

            if (mezo) {
                mezo.focus({ preventScroll: true });

                if (a.kurzor && typeof mezo.setSelectionRange === 'function') {
                    try { mezo.setSelectionRange(a.kurzor[0], a.kurzor[1]); } catch (x) { /* nem szövegmező */ }
                }
            }
        }
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

        automentesBekot(szint, n, urlap);

        urlap.addEventListener('submit', function (esemeny) {
            esemeny.preventDefault();
            automentesAll(szint);

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
                        // A Mentés gomb maga is értesít: a bezáráskor nem kell még egyszer.
                        szint.automentve = false;

                        // Pénz került a kasszába (0.67.0): az asszisztens vigyázzba áll és tiszteleg.
                        penzBeJelez(urlap);

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

                    // Közben más módosította (0.70.0): az ütközés-sáv (utkozes.js) kínálja a frissítést vagy a felülírást.
                    if (eredmeny && eredmeny.data && eredmeny.data.utkozes) {
                        utkozesJelez(urlap, eredmeny.data, 'kuldes', n);

                        return;
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

    /* ---------------------------------------------------------------- */
    /* Automatikus mentés (0.59.6)                                       */
    /* ---------------------------------------------------------------- */

    /*
     * Meglévő rekord űrlapján (munkalap, rendelés, árukiadás, ügyfél, eszköz,
     * termék, szolgáltatás) minden változás magától mentődik: tétel felvétele
     * vagy törlése, állapot, pipa, választó azonnal; a beírt mező az elhagyásakor,
     * a hosszú szöveg (megjegyzés) a gépelés megállása után kb. 2,5 másodperccel. Az ablak nyitva marad, a lapfül, a fókusz és a
     * görgetés is. A még el nem mentett új rekordot NEM menti magától (különben
     * minden félbehagyott felvitel munkalapszámot kapna); a lezárt, zárolt lapot sem.
     * A számlát, fizetést, üzenetküldést soha – azok csak gombra történnek.
     *
     * Mentés után az új tétel- és hibasorok visszakapják a végleges azonosítójukat
     * (uj_sorok), így a következő mentés nem veszi fel őket újra. Ha a szerver
     * díjsort hozott létre, a munkalap csendben újratölt – de csak ha közben nem
     * írtál bele. A bezáráskor a lista frissül (sdh:mentve).
     */
    var AUTOMENTES = /^sdh_muhely_(munkalapok|ugyfelek|eszkozok|termekek|szolgaltatasok)_ment$/;
    var AUTO_FRISSIT = /^sdh_muhely_munkalapok_ment$/;

    /** Az űrlap pillanatképe: ebből látszik, változott-e valami a legutóbbi mentés óta. */
    function urlapKep(urlap) {
        var reszek = [];

        new FormData(urlap).forEach(function (ertek, nev) {
            reszek.push(nev + '=' + (typeof ertek === 'string' ? ertek : (ertek && ertek.name ? 'F:' + ertek.name + ':' + ertek.size : '')));
        });

        return reszek.join('&');
    }

    function automentesJelzo(urlap, szoveg, allapot) {
        var lab = urlap.querySelector('.sdh-urlap__lablec') || urlap;
        var j = lab.querySelector('[data-sdh-automentes]');

        if (!j) {
            j = document.createElement('span');
            j.className = 'sdh-automentes';
            j.setAttribute('data-sdh-automentes', '');
            j.setAttribute('role', 'status');
            j.setAttribute('aria-live', 'polite');
            lab.appendChild(j);
        }

        j.textContent = szoveg;
        j.setAttribute('data-allapot', allapot || '');
    }

    function automentesBekot(szint, n, urlap) {
        var akcio = urlap.dataset.sdhAjaxAction || '';
        var idMezo = urlap.querySelector('input[name="id"]');
        var zarolt = !!(urlap.querySelector('[data-sdh-zarolt="1"]') || urlap.closest('[data-sdh-zarolt="1"]'));

        szint.auto = null;

        if (!AUTOMENTES.test(akcio) || !idMezo || !(parseInt(idMezo.value, 10) > 0) || zarolt || (szint.opciok && typeof szint.opciok.siker === 'function')) {
            return;
        }

        var a = {
            urlap: urlap,
            akcio: akcio,
            id: idMezo.value,
            kep: urlapKep(urlap),
            allapot: (urlap.querySelector('[name="allapot"]') || {}).value || '',
            ido: 0,
            fut: false,
            ujra: false
        };

        szint.auto = a;
        automentesJelzo(urlap, 'Automatikus mentés bekapcsolva', 'be');

        // A Mégsem gomb ilyenkor csak bezár (a változások már mentve vannak).
        var megsem = urlap.querySelector('[data-sdh-megsem]');

        if (megsem) {
            megsem.textContent = 'Bezárás';
        }

        function utemez(ms) {
            window.clearTimeout(a.ido);
            a.ido = window.setTimeout(function () { automentesFut(szint, n, a); }, ms);
        }

        a.utemez = utemez;

        urlap.addEventListener('input', function (ev) {
            // Hosszú szöveg (megjegyzés, leírás): a gépelés megállása után is ment. A rövid mezők
            // (ár, előleg, név) a mező elhagyásakor – így az előleg beírása közben nem jön félkész
            // összegre pénztár-ajánlat. A keresőmezők (választók) nem számítanak.
            if (!ev.target || ev.target.tagName !== 'TEXTAREA' || (ev.target.closest && ev.target.closest('.sdh-valaszto, [data-sdh-nem-ment]'))) {
                return;
            }

            utemez(2500);
        });
        urlap.addEventListener('change', function (ev) {
            var c = ev.target;

            // Saját, azonnali mentése van (a lap és az eszköz állapota): az után jön a frissítés (sdh:allapot).
            if (c && c.closest && c.closest('[data-sdh-allapot-valaszto], select[data-sdh-eszkoz-allapot], select[data-sdh-lista-allapot]')) {
                return;
            }

            // A „Fizetve" és a „Bevizsgálási díj" pipa levétele előtt a rendszer figyelmeztet: a döntés előtt
            // nem mentünk. Ha a pipa lent marad, a következő változással vagy a bezáráskor mentődik.
            if (c && c.type === 'checkbox' && !c.checked && (c.hasAttribute('data-sdh-fizetve') || c.hasAttribute('data-sdh-bevizsgalas'))) {
                return;
            }

            utemez(350);
        });

        // Tételsor, hibasor felvétele vagy törlése (gombbal, választóból): rögtön.
        if (window.MutationObserver) {
            var figyelo = new MutationObserver(function (valtozasok) {
                var szamit = valtozasok.some(function (v) {
                    return Array.prototype.some.call(v.addedNodes, function (el) { return el.nodeType === 1 && !el.hasAttribute('data-sdh-bev-elonezet') && !el.classList.contains('sdh-modal__hiba'); }) ||
                        Array.prototype.some.call(v.removedNodes, function (el) { return el.nodeType === 1 && !el.hasAttribute('data-sdh-bev-elonezet') && !el.classList.contains('sdh-modal__hiba'); });
                });

                if (szamit) {
                    utemez(500);
                }
            });

            Array.prototype.forEach.call(urlap.querySelectorAll('[data-sdh-tetelsorok], [data-sdh-hibasorok]'), function (lista) {
                figyelo.observe(lista, { childList: true });
            });
            a.figyelo = figyelo;
        }

        if (!szint.autoZarFigyel) {
            szint.autoZarFigyel = true;
            szint.dialog.addEventListener('close', function () { automentesZaras(szint); });
        }
    }

    // Az állapot azonnali mentése után a lap is mentődik / frissül: lezárásnál a zárolt nézet töltődik be.
    document.addEventListener('sdh:allapot', function (ev) {
        var d = ev.detail || {};

        szintek.forEach(function (szint) {
            if (szint && szint.auto && String(szint.auto.id) === String(d.id || '')) {
                szint.auto.utemez(150);
            }
        });
    });

    function automentesAll(szint) {
        if (szint.auto) {
            window.clearTimeout(szint.auto.ido);

            if (szint.auto.figyelo) {
                szint.auto.figyelo.disconnect();
            }
        }

        szint.auto = null;
    }

    function automentesFut(szint, n, a) {
        if (szint.auto !== a || !document.body.contains(a.urlap)) {
            return;
        }

        var kep = urlapKep(a.urlap);

        if (kep === a.kep) {
            return;
        }

        if (a.fut) {
            a.ujra = true;

            return;
        }

        // A böngésző saját ellenőrzése (kötelező mező): hibás állapotot nem mentünk.
        if (a.urlap.checkValidity && !a.urlap.checkValidity()) {
            automentesJelzo(a.urlap, 'Nincs mentve – hiányzik egy kötelező mező', 'hiba');

            return;
        }

        a.fut = true;
        automentesJelzo(a.urlap, 'Mentés…', 'fut');

        var adat = new FormData(a.urlap);

        adat.set('action', a.akcio);
        adat.set('automentes', '1');

        fetch(beallitas.ajax, { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (eredmeny) {
                a.fut = false;

                if (!eredmeny || !eredmeny.success) {
                    // A lap közben lezárult (zárolt): a zárolt nézet töltődik be, nincs több automatikus mentés.
                    if (eredmeny && eredmeny.data && eredmeny.data.zarolt) {
                        automentesAll(szint);
                        csendesFrissit(n, true);

                        return;
                    }

                    // Közben más módosította (0.70.0): nincs csendes felülírás – az ütközés-sáv (utkozes.js) kínálja a döntést.
                    if (eredmeny && eredmeny.data && eredmeny.data.utkozes) {
                        automentesJelzo(a.urlap, 'Nincs mentve – közben más módosította', 'hiba');
                        utkozesJelez(a.urlap, eredmeny.data, 'auto', n);

                        return;
                    }

                    automentesJelzo(a.urlap, 'Nincs mentve: ' + ((eredmeny && eredmeny.data && eredmeny.data.uzenet) || 'hiba'), 'hiba');

                    return;
                }

                // Az új sorok azonosítója vissza az űrlapba: a következő mentés már frissíti őket, nem veszi fel újra.
                var valtozatlan = urlapKep(a.urlap) === kep;
                var ujSorok = (eredmeny.data && eredmeny.data.uj_sorok) || {};

                Object.keys(ujSorok).forEach(function (kulcs) {
                    var r = kulcs.split(':');
                    var nev = r[0] === 'hiba' ? 'hibak[' + r[1] + '][id]' : 'tetelek[' + r[0] + '][' + r[1] + '][id]';
                    var mezo = a.urlap.querySelector('input[name="' + nev + '"]');

                    if (mezo && !(parseInt(mezo.value, 10) > 0)) {
                        mezo.value = String(ujSorok[kulcs]);
                    }
                });

                a.kep = valtozatlan ? urlapKep(a.urlap) : kep;
                kep = a.kep;
                szint.automentve = true;

                var regiHiba = a.urlap.querySelector('.sdh-modal__hiba');

                if (regiHiba) {
                    regiHiba.remove();
                }

                var most = new Date();

                automentesJelzo(a.urlap, 'Mentve ' + ('0' + most.getHours()).slice(-2) + ':' + ('0' + most.getMinutes()).slice(-2), 'kesz');

                document.dispatchEvent(new CustomEvent('sdh:automentve', {
                    detail: { action: a.akcio, adat: eredmeny.data || {}, id: a.id, urlap: a.urlap }
                }));

                // Közben újra változott: előbb az is mentődjön, a frissítés utána jön.
                if (a.ujra || urlapKep(a.urlap) !== kep) {
                    a.ujra = false;
                    a.utemez(300);

                    return;
                }

                // Újratöltés csak ha kell: a szerver olyat hozott létre, ami az űrlapon még csak előnézet
                // (a bevizsgálási díj és levonása), vagy állapotot váltott (a lezárt lap zárolt nézetre vált).
                var ujAllapot = (a.urlap.querySelector('[name="allapot"]') || {}).value || '';
                var allapotValtott = ujAllapot !== a.allapot;

                a.allapot = ujAllapot;

                if (AUTO_FRISSIT.test(a.akcio) && (allapotValtott || a.urlap.querySelector('[data-sdh-bev-elonezet]'))) {
                    csendesFrissit(n);
                }
            })
            .catch(function (hiba) {
                a.fut = false;
                automentesJelzo(a.urlap, 'Nincs mentve: ' + hiba.message, 'hiba');
            });
    }

    /**
     * Párhuzamos szerkesztés (0.70.0): a szerver ütközést jelzett (más módosította a rekordot).
     * Az `sdh:utkozes` eseményt az utkozes.js rajzolja ki (sáv, két kétlépcsős gomb); a `frissit`
     * a legfrissebb állapotot tölti be (a saját, még nem mentett változtatások elvesznek).
     */
    function utkozesJelez(urlap, adat, forras, n) {
        document.dispatchEvent(new CustomEvent('sdh:utkozes', {
            detail: {
                urlap: urlap,
                adat: adat,
                forras: forras,
                frissit: function () { csendesFrissit(n, true); }
            }
        }));
    }

    /** A nyitott űrlap újratöltése „Betöltés…" nélkül – ha közben nem változott. */
    function csendesFrissit(n, kotelezo) {
        var szint = szintek[n];

        if (!szint || !szint.dialog.open || !szint.modul) {
            return;
        }

        var a = szint.auto;
        var kep = a ? urlapKep(a.urlap) : '';

        // Csak akkor cseréljük, ha a betöltés közben sem írtak bele (különben elveszne).
        var opciok = Object.assign({}, szint.opciok || {}, { csendes: true, szint: n, ful: '' });

        opciok.elotte = function () {
            return kotelezo || !a || !document.body.contains(a.urlap) || urlapKep(a.urlap) === kep;
        };

        nyit(szint.modul, szint.id, opciok);
    }

    /** Bezáráskor: ami még nincs mentve, elmegy; ha volt automatikus mentés, a lista frissül. */
    function automentesZaras(szint) {
        var a = szint.auto;

        if (a && document.body.contains(a.urlap) && urlapKep(a.urlap) !== a.kep && !a.fut) {
            var adat = new FormData(a.urlap);

            adat.set('action', a.akcio);
            adat.set('automentes', '1');
            szint.automentve = true;

            try {
                fetch(beallitas.ajax, { method: 'POST', body: adat, credentials: 'same-origin', keepalive: true }).catch(function () {});
            } catch (x) { /* túl nagy a keepalive-hoz: a normál mentés gombbal menthető */ }
        }

        automentesAll(szint);

        if (!szint.automentve) {
            return;
        }

        szint.automentve = false;

        var ev = new CustomEvent('sdh:mentve', {
            cancelable: true,
            detail: { action: a ? a.akcio : '', adat: {}, forras: 'automentes' }
        });

        // A rács (kezdőképernyő) helyben frissül; máshol az aktuális oldal tölt újra – nem visz el innen.
        if (document.dispatchEvent(ev)) {
            window.setTimeout(function () { window.location.reload(); }, 250);
        }
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

    /* 0.64: gépelés-késleltetés; legalább ennyi karaktertől megy kérés (ügyfeleknél 3: az első két betűnél
     * az eredmény úgyis „mindenki"); a régi kérés megszakad; ugyanarra a szövegre a már megkapott lista
     * azonnal (késleltetés és kérés nélkül) jön vissza – pl. törlésnél, visszagépelésnél. */
    var VALASZTO_KESLELTETES = 100;
    /* Ha az előző leütés óta ennél több idő telt el (lassú, „pultos" gépelés), a kérés azonnal megy –
     * csak a gyors egymás utáni leütéseket fogja össze a késleltetés. */
    var VALASZTO_SZUNET = 150;
    var valasztoUtolsoLeutes = 0;
    var VALASZTO_MIN = { ugyfelek: 3 };
    var VALASZTO_ALAP_MIN = 2;
    var VALASZTO_ELET = 30000;
    var valasztoTar = {};

    function valasztoMin(modul) {
        return VALASZTO_MIN[modul] || VALASZTO_ALAP_MIN;
    }

    function valasztoLista(doboz) {
        return doboz.querySelector('.sdh-valaszto__lista');
    }

    function valasztoTorol(doboz) {
        var lista = valasztoLista(doboz);
        lista.innerHTML = '';
        lista.hidden = true;
    }

    function valasztoKirajzol(doboz, talalatok) {
        var lista = valasztoLista(doboz);
        var darab = document.createDocumentFragment();

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

            darab.appendChild(elem);
        });

        lista.appendChild(darab);
        lista.hidden = false;
    }

    function valasztoTarolt(modul, q) {
        var tarolt = valasztoTar[modul + '|' + q];

        return tarolt && Date.now() - tarolt.ido <= VALASZTO_ELET ? tarolt.sorok : null;
    }

    function valasztoKeres(doboz, q) {
        var modul = doboz.dataset.sdhValaszto;
        var kulcs = modul + '|' + q;
        var tarolt = valasztoTarolt(modul, q);

        if (tarolt) {
            valasztoKirajzol(doboz, tarolt);

            return;
        }

        if (doboz.sdhKeresMegszak) {
            doboz.sdhKeresMegszak.abort();
        }

        var megszakito = typeof AbortController === 'function' ? new AbortController() : null;
        doboz.sdhKeresMegszak = megszakito;

        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_' + modul + '_kereso');
        cim.searchParams.set('q', q);
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        fetch(cim.toString(), { credentials: 'same-origin', signal: megszakito ? megszakito.signal : undefined })
            .then(function (valasz) {
                return valasz.json();
            })
            .then(function (eredmeny) {
                var mezo = doboz.querySelector('.sdh-valaszto__mezo');

                // Közben tovább gépelt: ez a válasz már nem az övé.
                if (mezo && mezo.value.trim() !== q) {
                    return;
                }

                var talalatok = (eredmeny && eredmeny.success && eredmeny.data) || [];

                valasztoTar[kulcs] = { ido: Date.now(), sorok: talalatok };
                valasztoKirajzol(doboz, talalatok);
            })
            .catch(function (hiba) {
                if (hiba && hiba.name === 'AbortError') {
                    return;
                }

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
        // ügyfélhez menti az eszközt. (0.64: az eszközlista és az összefoglaló
        // csak akkor töltődik újra, ha tényleg volt választott ügyfél – nem
        // minden leütésre.)
        var volt = rejtett.value;

        rejtett.value = '0';

        if (volt !== '0') {
            munkalapEszkozFrissit(doboz, '0');
        }

        window.clearTimeout(keresesIdozito);

        var q = mezo.value.trim();

        if (doboz.sdhKeresMegszak) {
            doboz.sdhKeresMegszak.abort();
            doboz.sdhKeresMegszak = null;
        }

        if (q.length < valasztoMin(doboz.dataset.sdhValaszto)) {
            valasztoTorol(doboz);

            return;
        }

        // Már megkapott lista (ugyanez a szöveg): azonnal, kérés nélkül.
        if (valasztoTarolt(doboz.dataset.sdhValaszto, q)) {
            valasztoKeres(doboz, q);

            return;
        }

        var most = Date.now();
        var szunet = most - valasztoUtolsoLeutes;

        valasztoUtolsoLeutes = most;

        if (szunet >= VALASZTO_SZUNET) {
            valasztoKeres(doboz, q);

            return;
        }

        keresesIdozito = window.setTimeout(function () {
            valasztoKeres(doboz, q);
        }, VALASZTO_KESLELTETES);
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
                : (doboz.querySelector('[data-sdh-minta-rejtett]') ? 'Mentett minta van (rejtve).' : 'Nincs minta felvéve.');
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

                // H6: a Törlés a rejtett, mentett mintát is törli (különben az üres mező mellett megmaradna).
                var marad = doboz.closest('form') ? doboz.closest('form').querySelector('input[name="minta_marad"]') : null;

                if (marad) {
                    marad.value = '0';
                }

                var jel = doboz.querySelector('[data-sdh-minta-rejtett]');

                if (jel) {
                    jel.remove();
                }
            });
        }

        // H6: a Megmutat gomb után a felfedett minta kerül a rajzra.
        doboz.addEventListener('sdh-minta-betolt', function () {
            sorrend = mintaErtek(doboz);
            mintaRajzol(doboz, sorrend, null);
        });
    }

    /* -----------------------------------------------------------------
     * H6 (0.79): feloldó kód / minta – Megmutat (jogkötelesen, a szerver naplózza)
     * ----------------------------------------------------------------- */

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-zarkod-megmutat]') : null;

        if (!gomb) {
            return;
        }

        esemeny.preventDefault();

        var adat = new FormData();
        adat.set('action', 'sdh_muhely_zarkod_megmutat');
        adat.set('id', gomb.getAttribute('data-sdh-zarkod-megmutat') || '0');
        adat.set('_wpnonce', beallitas.nonce || '');
        gomb.disabled = true;

        fetch(beallitas.ajax, { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (valasz) {
                gomb.disabled = false;

                if (!valasz || !valasz.success) {
                    gomb.textContent = (valasz && valasz.data && valasz.data.uzenet) || 'Nem sikerült.';

                    return;
                }

                var d = valasz.data;
                var hatokor = gomb.closest('form') || gomb.closest('dl') || gomb.parentNode;
                var kod = hatokor.querySelector('input[name="zarkod"]');

                if (kod) {
                    kod.value = d.zarkod || '';
                    kod.placeholder = d.olvashatatlan ? 'A mentett kód ezen a gépen nem olvasható' : '';
                }

                ['zarkod_marad', 'minta_marad'].forEach(function (nev) {
                    var m = hatokor.querySelector('input[name="' + nev + '"]');

                    if (m) {
                        m.value = '0';
                    }
                });

                var minta = hatokor.querySelector('[data-sdh-minta]');

                if (minta) {
                    minta.querySelector('input[name="minta"]').value = d.minta || '';

                    var jel = minta.querySelector('[data-sdh-minta-rejtett]');

                    if (jel) {
                        jel.remove();
                    }

                    minta.dispatchEvent(new CustomEvent('sdh-minta-betolt'));
                }

                hatokor.querySelectorAll('[data-sdh-zarkod-ertek]').forEach(function (el) {
                    var ertek = el.getAttribute('data-sdh-zarkod-ertek') === 'minta' ? d.minta_kiiras : d.zarkod;
                    el.textContent = ertek || (d.olvashatatlan ? 'ezen a gépen nem olvasható' : '—');
                    el.classList.remove('sdh-adatlista__ures');
                });

                gomb.remove();
            })
            .catch(function () {
                gomb.disabled = false;
                gomb.textContent = 'Nem sikerült.';
            });
    });

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

        // Papír: e-könyv-olvasó-szerű, nyugodt felület (admin.css: :root[data-papir]).
        if (ertek === 'papir') {
            document.documentElement.setAttribute('data-papir', '1');
        } else {
            document.documentElement.removeAttribute('data-papir');
        }

        // A Beállítások űrlapján álló választó (a cég alapértelmezése) a saját mezőit követi.
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-sdh-tema] button'),
            function (gomb) {
                if (!gomb.closest('[data-sdh-megj="urlap"]')) {
                    gomb.setAttribute('aria-pressed', gomb.dataset.ertek === ertek ? 'true' : 'false');
                }
            }
        );
    }

    /* ---- Kékfény-szűrő: melegebb kép, 0–100% (0 = ki) ---- */

    function kekfenySzukit(ertek) {
        return Math.max(0, Math.min(100, Math.round((parseInt(ertek, 10) || 0) / 5) * 5));
    }

    function kekfenySajat() {
        var mentett = null;

        try {
            mentett = localStorage.getItem('sdh-kekfeny');
        } catch (e) {
            mentett = null;
        }

        return kekfenySzukit(mentett === null ? (beallitas.alapKekfeny || 0) : mentett);
    }

    function kekfenyKijelez(doboz, szazalek) {
        var csuszka = doboz.querySelector('[data-sdh-kekfeny-csuszka]');
        var ertek = doboz.querySelector('[data-sdh-kekfeny-ertek]');

        if (csuszka && String(csuszka.value) !== String(szazalek)) {
            csuszka.value = String(szazalek);
        }

        if (ertek) {
            ertek.textContent = szazalek > 0 ? szazalek + '%' : 'Ki';
        }

        doboz.style.setProperty('--p', szazalek + '%');
    }

    /**
     * A szűrő a gyökérelemre kerül (a felugró ablakokra is hat): a kék
     * csatornát erősen, a zöldet enyhén veszi vissza. Csak a műhely-felületen
     * van szűrő; a wp-adminban a csúszka csak a mentendő értéket mutatja.
     */
    function kekfenyAlkalmaz(szazalek) {
        szazalek = kekfenySzukit(szazalek);

        var matrix = document.querySelector('#sdh-kekfeny-szuro feColorMatrix');
        var k = szazalek / 100;

        if (matrix) {
            matrix.setAttribute('values', '1 0 0 0 0  0 ' + (1 - 0.22 * k).toFixed(3) + ' 0 0 0  0 0 ' + (1 - 0.62 * k).toFixed(3) + ' 0 0  0 0 0 1 0');
        }

        if (matrix && szazalek > 0) {
            document.documentElement.setAttribute('data-kekfeny', String(szazalek));
        } else {
            document.documentElement.removeAttribute('data-kekfeny');
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-kekfeny="sajat"]'), function (doboz) {
            kekfenyKijelez(doboz, szazalek);
        });
    }

    document.addEventListener('input', function (esemeny) {
        var csuszka = esemeny.target.closest ? esemeny.target.closest('[data-sdh-kekfeny-csuszka]') : null;

        if (!csuszka) {
            return;
        }

        var doboz = csuszka.closest('[data-sdh-kekfeny]');
        var szazalek = kekfenySzukit(csuszka.value);

        if (doboz.getAttribute('data-sdh-kekfeny') === 'urlap') {
            // A cég alapértelmezése: mentésig csak a csúszka mutatja (a saját beállításod marad érvényben).
            kekfenyKijelez(doboz, szazalek);

            return;
        }

        beallitasIr('sdh-kekfeny', String(szazalek));
        kekfenyAlkalmaz(szazalek);
    });

    /**
     * Üvegszín: a kiemelő szín (data-accent) mellé a mód – 'duo' (két
     * kontrasztos szín) vagy 'mono' (egy színcsalád). A színek a CSS-ben vannak.
     */
    function uvegAlkalmaz(szin, uveg) {
        uveg = uveg === 'duo' ? 'duo' : 'mono';
        document.documentElement.setAttribute('data-uveg', uveg);

        Array.prototype.forEach.call(
            document.querySelectorAll('[data-sdh-megj="profil"] [data-sdh-uvegszin] button'),
            function (gomb) {
                var sor = gomb.closest('[data-sdh-uvegszin]');
                var aktiv = sor.getAttribute('data-sdh-uvegszin') === uveg && gomb.dataset.ertek === (szin || '');

                gomb.setAttribute('aria-pressed', aktiv ? 'true' : 'false');
            }
        );
    }

    function sajatSzin() {
        var szin = null;

        try {
            szin = localStorage.getItem('sdh-szin');
        } catch (e) {
            szin = null;
        }

        return szin === null ? (beallitas.alapSzin || '') : szin;
    }

    /* ---- Betűnagyítás: a csúszka húzás közben azonnal méretez ---- */

    // Függvények (nem változók): a megjelenés már a fájl elején beáll, mielőtt ide érne a futás.
    function nagyMin() { return parseInt(beallitas.nagyitasMin, 10) || 80; }
    function nagyMax() { return parseInt(beallitas.nagyitasMax, 10) || 160; }

    function nagyitasSzukit(szazalek) {
        var n = Math.round((parseInt(szazalek, 10) || 100) / 5) * 5;

        return Math.max(nagyMin(), Math.min(nagyMax(), n));
    }

    /** A dolgozó saját mérete ezen a gépen, különben a cég alapértelmezése. */
    function nagyitasSajat() {
        var sajat = 0;

        try {
            sajat = parseInt(localStorage.getItem('sdh-nagyitas'), 10) || 0;
        } catch (e) {
            sajat = 0;
        }

        return nagyitasSzukit(sajat || beallitas.alapNagyitas || 100);
    }

    function nagyitoKijelez(doboz, szazalek) {
        var csuszka = doboz.querySelector('[data-sdh-nagyito-csuszka]');
        var ertek = doboz.querySelector('[data-sdh-nagyito-ertek]');
        var minta = doboz.querySelector('[data-sdh-nagyito-minta]');

        // A minta („Aa") mutatja húzás közben, mekkora lesz a betű – a felület még nem mozdul.
        if (minta) {
            minta.style.setProperty('--m', String(szazalek / 100));
        }

        if (csuszka && String(csuszka.value) !== String(szazalek)) {
            csuszka.value = String(szazalek);
        }

        if (ertek) {
            ertek.textContent = szazalek + '%';
        }

        doboz.style.setProperty('--p', ((szazalek - nagyMin()) / (nagyMax() - nagyMin()) * 100) + '%');
    }

    /**
     * A felület méretezése: a --sdh-n változó szorozza a stíluslapok méreteit
     * (a rem a gyökér betűméretén át követi). A wp-adminban a felület a
     * WordPress saját méretében marad – ott csak a csúszka kijelzése frissül.
     */
    function nagyitasAlkalmaz(szazalek) {
        szazalek = nagyitasSzukit(szazalek);

        window.cancelAnimationFrame(nagyitasAnimal.kep);

        if (beallitas.kontextus === 'frontend') {
            document.documentElement.style.setProperty('--sdh-n', String(szazalek / 100));
            document.documentElement.style.setProperty('--sdh-n-alap', String(szazalek / 100));
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-nagyito="sajat"]'), function (doboz) {
            nagyitoKijelez(doboz, szazalek);
        });

        // A rács és a nyitott popupok az új mérethez igazodnak.
        window.clearTimeout(nagyitasAlkalmaz.ido);
        nagyitasAlkalmaz.ido = window.setTimeout(function () {
            window.dispatchEvent(new Event('resize'));
        }, 60);
    }

    /**
     * Lágy váltás az új méretre (kb. negyed másodperc alatt): a felület nem
     * ugrik, hanem odasiklik. Csökkentett mozgásnál és a wp-adminban azonnal vált.
     */
    function nagyitasAnimal(szazalek) {
        szazalek = nagyitasSzukit(szazalek);

        var gyoker = document.documentElement;
        var tol = parseFloat(gyoker.style.getPropertyValue('--sdh-n')) || 1;
        var ig = szazalek / 100;
        var nyugodt = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (beallitas.kontextus !== 'frontend' || nyugodt || Math.abs(ig - tol) < 0.005) {
            nagyitasAlkalmaz(szazalek);

            return;
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-nagyito="sajat"]'), function (doboz) {
            nagyitoKijelez(doboz, szazalek);
        });

        var kezdet = 0;
        var hossz = 260;

        window.cancelAnimationFrame(nagyitasAnimal.kep);

        function lep(most) {
            kezdet = kezdet || most;

            var t = Math.min(1, (most - kezdet) / hossz);
            var puha = 1 - Math.pow(1 - t, 3);

            gyoker.style.setProperty('--sdh-n', (tol + (ig - tol) * puha).toFixed(4));
            gyoker.style.setProperty('--sdh-n-alap', (tol + (ig - tol) * puha).toFixed(4));

            if (t < 1) {
                nagyitasAnimal.kep = window.requestAnimationFrame(lep);
            } else {
                nagyitasAlkalmaz(szazalek);
            }
        }

        nagyitasAnimal.kep = window.requestAnimationFrame(lep);
    }

    /**
     * A csúszka állása. $vegleges nélkül (húzás közben) csak a százalék és a
     * minta frissül – a felület a csúszka ELENGEDÉSEKOR vált, különben a
     * csúszka maga is arrébb csúszna az egér alól, és a méret ugrálna.
     */
    function nagyitoValt(doboz, szazalek, vegleges) {
        szazalek = nagyitasSzukit(szazalek);

        if (!vegleges) {
            nagyitoKijelez(doboz, szazalek);
            doboz.classList.add('is-huz');

            return;
        }

        doboz.classList.remove('is-huz');

        if (doboz.getAttribute('data-sdh-nagyito') === 'urlap') {
            // A cég alapértelmezése: mentésig csak előnézet ezen a képernyőn.
            nagyitoKijelez(doboz, szazalek);
            nagyitasAnimalUrlap(szazalek);

            return;
        }

        beallitasIr('sdh-nagyitas', String(szazalek));
        nagyitasAnimal(szazalek);
    }

    /** A Beállítások előnézete: a felület odasiklik, de a saját (mentett) méret csúszkái nem mozdulnak. */
    function nagyitasAnimalUrlap(szazalek) {
        if (beallitas.kontextus === 'frontend') {
            document.documentElement.style.setProperty('--sdh-n', String(szazalek / 100));
            document.documentElement.style.setProperty('--sdh-n-alap', String(szazalek / 100));
            window.dispatchEvent(new Event('resize'));
        }
    }

    document.addEventListener('input', function (esemeny) {
        var csuszka = esemeny.target.closest ? esemeny.target.closest('[data-sdh-nagyito-csuszka]') : null;

        if (csuszka) {
            nagyitoValt(csuszka.closest('[data-sdh-nagyito]'), csuszka.value, false);
        }
    });

    // Elengedés (egér, érintés) vagy billentyű: ekkor vált a felület.
    document.addEventListener('change', function (esemeny) {
        var csuszka = esemeny.target.closest ? esemeny.target.closest('[data-sdh-nagyito-csuszka]') : null;

        if (csuszka) {
            nagyitoValt(csuszka.closest('[data-sdh-nagyito]'), csuszka.value, true);
        }
    });

    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target.closest ? esemeny.target.closest('[data-sdh-nagyito-lep], [data-sdh-nagyito-alap]') : null;

        if (!cel) {
            return;
        }

        var doboz = cel.closest('[data-sdh-nagyito]');
        var csuszka = doboz ? doboz.querySelector('[data-sdh-nagyito-csuszka]') : null;

        if (!csuszka) {
            return;
        }

        esemeny.preventDefault();

        if (cel.hasAttribute('data-sdh-nagyito-alap')) {
            var alap = parseInt(csuszka.getAttribute('data-alap'), 10) || 100;

            if (doboz.getAttribute('data-sdh-nagyito') === 'sajat') {
                // Vissza a cég alapértelmezésére: a saját beállítás törlődik.
                try {
                    localStorage.removeItem('sdh-nagyitas');
                } catch (e) { /* privát ablak */ }

                nagyitasAnimal(alap);
            } else {
                nagyitoValt(doboz, alap, true);
            }

            return;
        }

        nagyitoValt(doboz, (parseInt(csuszka.value, 10) || 100) + 5 * (parseInt(cel.getAttribute('data-sdh-nagyito-lep'), 10) || 0), true);
    });

    /* ---- Beállítások: a betűtípus előnézete már mentés előtt ---- */

    function betutipusElonezet() {
        var lista = document.querySelector('[data-sdh-betutipus]');

        if (!lista) {
            return;
        }

        var opcio = lista.options[lista.selectedIndex];
        var csalad = opcio ? opcio.getAttribute('data-csalad') || '' : '';
        var egyeni = document.querySelector('[data-sdh-betutipus-egyeni]');
        var nev = egyeni ? egyeni.value.replace(/[^\p{L}\p{N} \-]/gu, '').trim() : '';

        if (lista.value === 'egyeni' && nev !== '') {
            csalad = '"' + nev + '", ' + csalad;
        }

        document.documentElement.style.setProperty('--sdh-betutipus', csalad);
    }

    document.addEventListener('change', function (esemeny) {
        if (esemeny.target.closest && esemeny.target.closest('[data-sdh-betutipus]')) {
            betutipusElonezet();
        }
    });

    document.addEventListener('input', function (esemeny) {
        if (esemeny.target.closest && esemeny.target.closest('[data-sdh-betutipus-egyeni]')) {
            betutipusElonezet();
        }
    });

    /* ---- Beállítások: betű területenként – a mintasor azonnal mutatja (0.60.1) ---- */

    function betuTeruletMinta(sor) {
        if (!sor) {
            return;
        }

        var lista = sor.querySelector('[data-sdh-betuter-tipus]');
        var egyeni = sor.querySelector('[data-sdh-betuter-egyeni]');
        var csuszka = sor.querySelector('[data-sdh-betuter-meret]');
        var ertek = sor.querySelector('[data-sdh-betuter-ertek]');
        var minta = sor.querySelector('[data-sdh-betuter-minta]');

        if (!minta) {
            return;
        }

        var opcio = lista ? lista.options[lista.selectedIndex] : null;
        var csalad = opcio ? opcio.getAttribute('data-csalad') || '' : '';
        var nev = egyeni ? egyeni.value.replace(/[^\p{L}\p{N} \-]/gu, '').trim() : '';

        if (lista && lista.value === 'egyeni' && nev !== '') {
            csalad = '"' + nev + '", ' + csalad;
        }

        // Üres családnál a minta az alap betűt mutatja (azt örökli).
        minta.style.fontFamily = csalad;

        var szazalek = csuszka ? parseInt(csuszka.value, 10) || 100 : 100;

        if (ertek) {
            ertek.textContent = szazalek + '%';
        }

        // A minta a saját --sdh-n értékét kapja: ugyanúgy nő, ahogy majd a felület.
        minta.style.setProperty('--sdh-n', String((parseFloat(
            document.documentElement.style.getPropertyValue('--sdh-n-alap')
        ) || 1) * szazalek / 100));
        minta.style.fontSize = 'calc(14px * var(--sdh-n))';
    }

    function betuTeruletMind() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-betuter]'), betuTeruletMinta);
    }

    ['change', 'input'].forEach(function (nev) {
        document.addEventListener(nev, function (esemeny) {
            var sor = esemeny.target.closest ? esemeny.target.closest('[data-sdh-betuter]') : null;

            if (sor) {
                betuTeruletMinta(sor);
            }
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', betuTeruletMind);
    } else {
        betuTeruletMind();
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

    /**
     * A popupok neon kerete. Az érték a szín kulcsa (üres = nincs keret,
     * `kiemelo` = a kiemelő szín, `egyeni` = az Arculatban megadott színkód);
     * a színek maguk a CSS-ben vannak (admin.css: --sdh-popneon-*).
     */
    function neonAlkalmaz(ertek) {
        var gyoker = document.documentElement;

        if (ertek === 'egyeni' && !/^#[0-9a-f]{6}$/i.test(beallitas.neonEgyeni || '')) {
            ertek = 'kiemelo';
        }

        if (ertek) {
            gyoker.setAttribute('data-neon', ertek);
        } else {
            gyoker.removeAttribute('data-neon');
        }

        if (ertek === 'egyeni') {
            gyoker.style.setProperty('--sdh-popneon-egyeni', beallitas.neonEgyeni);
        } else {
            gyoker.style.removeProperty('--sdh-popneon-egyeni');
        }

        gyoker.setAttribute('data-neon-ero', beallitas.neonEro || 'kozepes');

        Array.prototype.forEach.call(
            document.querySelectorAll('[data-sdh-neon] button'),
            function (gomb) {
                gomb.setAttribute('aria-pressed', gomb.dataset.ertek === ertek ? 'true' : 'false');
            }
        );
    }

    /** Arculat képernyő: a neon keret előnézete már mentés előtt követi a választást. */
    function neonElonezet(esemeny) {
        var cel = esemeny.target;

        if (!cel.closest || !cel.closest('[data-sdh-neon-valaszto], [data-sdh-neon-egyeni], [data-sdh-neon-ero-valaszto]')) {
            return;
        }

        var szin = document.querySelector('[data-sdh-neon-valaszto]');
        var kod = document.querySelector('[data-sdh-neon-egyeni]');
        var ero = document.querySelector('[data-sdh-neon-ero-valaszto]');
        var hex = kod ? kod.value.trim() : '';

        if (hex !== '' && hex.charAt(0) !== '#') {
            hex = '#' + hex;
        }

        beallitas.neonEgyeni = /^#[0-9a-f]{6}$/i.test(hex) ? hex : '';
        beallitas.neonEro = ero ? ero.value : 'kozepes';
        neonAlkalmaz(szin ? szin.value : '');
    }

    document.addEventListener('input', neonElonezet);
    document.addEventListener('change', neonElonezet);

    /** A dolgozó saját választása ezen a gépen, különben a cég alapértelmezése. */
    function neonErtek() {
        // Az Arculat képernyőn az űrlap választása látszik, nem a gép saját beállítása.
        var urlapon = document.querySelector('[data-sdh-neon-valaszto]');

        if (urlapon) {
            return urlapon.value;
        }

        var sajat = null;

        try {
            sajat = localStorage.getItem('sdh-neon');
        } catch (e) {
            sajat = null;
        }

        return sajat === null ? (typeof beallitas.alapNeon === 'string' ? beallitas.alapNeon : 'kiemelo') : sajat;
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

        szinAlkalmaz(sajatSzin());
        uvegAlkalmaz(sajatSzin(), beallitasOlvas('sdh-uveg', beallitas.alapUveg || 'mono'));
        neonAlkalmaz(neonErtek());

        nagyitasAlkalmaz(nagyitasSajat());
        kekfenyAlkalmaz(kekfenySajat());

        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-kekfeny="urlap"]'), function (doboz) {
            var kf = doboz.querySelector('[data-sdh-kekfeny-csuszka]');

            kekfenyKijelez(doboz, kekfenySzukit(kf ? kf.value : 0));
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-nagyito="urlap"]'), function (doboz) {
            var csuszka = doboz.querySelector('[data-sdh-nagyito-csuszka]');

            nagyitoKijelez(doboz, nagyitasSzukit(csuszka ? csuszka.value : 100));
        });

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
        var megjUrlap = esemeny.target.closest('[data-sdh-megj="urlap"]');

        // A Beállítások űrlapján a választás a rejtett mezőkbe kerül: mentéskor lesz a cég alapértelmezése.
        function megjMezo(nev, ertek) {
            var mezo = megjUrlap.querySelector('[data-sdh-megj-mezo="' + nev + '"]');

            if (mezo) {
                mezo.value = ertek;
                mezo.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        if (tema && megjUrlap) {
            megjMezo('tema', tema.dataset.ertek);

            Array.prototype.forEach.call(megjUrlap.querySelectorAll('[data-sdh-tema] button'), function (gomb) {
                gomb.setAttribute('aria-pressed', gomb === tema ? 'true' : 'false');
            });

            return;
        }

        if (tema) {
            beallitasIr('sdh-tema', tema.dataset.ertek);
            temaAlkalmaz(tema.dataset.ertek);

            return;
        }

        var uvegSzin = esemeny.target.closest('[data-sdh-uvegszin] button');

        if (uvegSzin) {
            var uvegMod = uvegSzin.closest('[data-sdh-uvegszin]').getAttribute('data-sdh-uvegszin');

            if (megjUrlap) {
                megjMezo('szin', uvegSzin.dataset.ertek);
                megjMezo('uveg', uvegMod);

                Array.prototype.forEach.call(megjUrlap.querySelectorAll('[data-sdh-uvegszin] button'), function (gomb) {
                    gomb.setAttribute('aria-pressed', gomb === uvegSzin ? 'true' : 'false');
                });

                return;
            }

            beallitasIr('sdh-szin', uvegSzin.dataset.ertek);
            beallitasIr('sdh-uveg', uvegMod);
            szinAlkalmaz(uvegSzin.dataset.ertek);
            uvegAlkalmaz(uvegSzin.dataset.ertek, uvegMod);

            return;
        }

        var szin = esemeny.target.closest('[data-sdh-szin] button');

        if (szin) {
            beallitasIr('sdh-szin', szin.dataset.ertek);
            szinAlkalmaz(szin.dataset.ertek);

            return;
        }

        var neon = esemeny.target.closest('[data-sdh-neon] button');

        if (neon) {
            beallitasIr('sdh-neon', neon.dataset.ertek);
            neonAlkalmaz(neon.dataset.ertek);

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

                // 0.99.4: a találat a saját ablakában (popup) nyílik, az oldal nem vált.
                if (talalat.urlap && talalat.id) {
                    link.setAttribute('data-sdh-urlap', talalat.urlap);
                    link.setAttribute('data-sdh-id', String(talalat.id));
                }
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
        cim.searchParams.set('elso', window.SDH_OKOS_MODUL ? window.SDH_OKOS_MODUL() : '');
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
            tovabbiSzur(urlap, []);
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

                // Másik ügyfél készüléke nem maradhat csatolva.
                tovabbiSzur(urlap, eszkozok.map(function (e) {
                    return String(e.id);
                }));

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
        //
        // Minden eszköz önálló ügy a lapon: saját díj, saját tételek, és a díja
        // a SAJÁT tételeiből vonódik le. A csatolt eszköz díja a címkéjén áll,
        // a fő eszközé az előleg maradéka. Az eszköz kulcsa: 0 = a fő eszköz.
        var bevPipa = gyoker.querySelector('[data-sdh-bevizsgalas]');
        var bevElolegMezo = gyoker.querySelector('[data-sdh-fizetett]');
        var bevElolegOssz = bevElolegMezo ? Math.max(0, mlSzam(bevElolegMezo.value)) : 0;
        var bevJelolo = !!(bevPipa && bevPipa.checked);
        var modJelolt = gyoker.querySelector('[data-sdh-bev-mod]:checked');
        var bevMod = modJelolt && modJelolt.value === 'marad' ? 'marad' : 'levon';
        // Eszközönként: díj, külön befizetés, a többi tétel összege, levonás.
        var esz = { '0': { nev: '', dij: 0, kulon: 0, tobbi: 0, levon: 0, vanDij: false, vanLe: false } };
        var eszSor = ['0'];
        var bevCsatolt = 0;
        var bevKulon = 0;
        var foValaszto = gyoker.querySelector('[data-sdh-eszkoz-valaszto]');
        // A fő eszközre a díján felül befizetett rész (előleg / részfizetés / végfizetés) nem díj.
        var foKulon = Math.max(0, parseFloat(gyoker.getAttribute('data-fo-kulon') || '0') || 0);
        // A fizetés visszavonása: eszközönként pontosan a lapot lezáró fizetés megy vissza.
        var visszaMezo = (gyoker.closest('form') || gyoker).querySelector('[data-sdh-fizetes-vissza]');
        var vissza = !!(visszaMezo && visszaMezo.value === '1');
        // Lejjebb írt előleg: a hiány előbb a díjakon felüli befizetéseket viszi el (a fő
        // eszközét, aztán a csatoltakét sorban) – ugyanúgy, mint a szerver mentéskor.
        var elolegHiany = vissza ? 0 : Math.max(0, (parseFloat(gyoker.getAttribute('data-eloleg-mentett') || '0') || 0) - bevElolegOssz);

        if (vissza) {
            foKulon = Math.max(0, foKulon - (parseFloat(gyoker.getAttribute('data-fo-veg') || '0') || 0));
        } else {
            var foLe = Math.min(foKulon, elolegHiany);

            foKulon -= foLe;
            elolegHiany -= foLe;
        }

        esz['0'].nev = foValaszto && foValaszto.value !== '0' && foValaszto.options[foValaszto.selectedIndex]
            ? foValaszto.options[foValaszto.selectedIndex].textContent.trim()
            : 'fő eszköz';

        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-tovabbi-lista] [data-sdh-tovabbi]'), function (cimke) {
            var mezo = cimke.querySelector('[data-sdh-tovabbi-dij]');
            var dij = bevJelolo && mezo ? Math.max(0, mlSzam(mezo.value)) : 0;
            var kulon = parseFloat(cimke.getAttribute('data-kulon') || '0') || 0;
            var kulcs = cimke.getAttribute('data-sdh-tovabbi');

            if (vissza) {
                kulon = Math.max(0, kulon - (parseFloat(cimke.getAttribute('data-veg') || '0') || 0));
            } else {
                var le = Math.min(kulon, elolegHiany);

                kulon -= le;
                elolegHiany -= le;
            }
            var nevElem = cimke.querySelector('[data-sdh-tovabbi-nev]');

            esz[kulcs] = { nev: nevElem ? nevElem.textContent.trim() : '', dij: dij, kulon: kulon, tobbi: 0, levon: 0, vanDij: false, vanLe: false };
            eszSor.push(kulcs);
            bevCsatolt += dij;
            bevKulon += kulon;
        });

        esz['0'].dij = bevJelolo ? Math.max(0, bevElolegOssz - bevCsatolt - bevKulon - foKulon) : 0;

        var bevFo = esz['0'].dij;
        // A díjak összege: ennyi számít díjként a végösszegbe.
        var bevEloleg = bevFo + bevCsatolt;

        /** Egy sor eszköze: a saját választója, díjsornál a hozzá kötött eszköz; ami nincs a lapon, az a fő eszközé. */
        function sorEszkoz(sor) {
            var kulcs = sor.hasAttribute('data-sdh-bev-eszkoz')
                ? sor.getAttribute('data-sdh-bev-eszkoz')
                : ((sor.querySelector('[data-sdh-tetel-eszkoz]') || {}).value || '0');

            return esz[kulcs] ? kulcs : '0';
        }

        // A többi tétel (a díjsorok nélkül), eszközönként: ebből vonható le a díj.
        var tobbi = 0;

        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-tetelek] [data-sdh-tetelsorok] [data-sdh-tetelsor]'), function (sor) {
            if (!sor.hasAttribute('data-sdh-bev') && !sor.hasAttribute('data-sdh-bevle')) {
                var ertek = mlSorErtek(sor).brutto;

                esz[sorEszkoz(sor)].tobbi += ertek;
                tobbi += ertek;
            }
        });

        var levon = 0;

        eszSor.forEach(function (kulcs) {
            var e = esz[kulcs];

            e.levon = e.dij > 0 && bevMod === 'levon' ? Math.min(e.dij, Math.max(0, e.tobbi)) : 0;
            levon += e.levon;
        });

        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-tetelsor][data-sdh-bev]'), function (bevSor) {
            var nyers = bevSor.getAttribute('data-sdh-bev-eszkoz') || '0';
            var e = esz[nyers];
            var cel = e ? e.dij : 0;

            if (e) {
                e.vanDij = true;
            }

            if (bevSor.getAttribute('data-sdh-bev') === 'szamlazva') {
                return;
            }

            var bevAr = bevSor.querySelector('[data-sdh-tetel="ar"]');

            if (cel > 0 && bevAr && document.activeElement !== bevAr && Math.abs(mlSzam(bevAr.value) - cel) >= 0.005) {
                bevAr.value = String(cel).replace('.', ',');
            }

            bevSor.classList.toggle('is-torlendo', cel <= 0);
        });

        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-tetelsor][data-sdh-bevle]'), function (leSor) {
            var nyers = leSor.getAttribute('data-sdh-bev-eszkoz') || '0';
            var e = esz[nyers];
            var cel = e ? e.levon : 0;

            if (e) {
                e.vanLe = true;
            }

            if (leSor.getAttribute('data-sdh-bevle') === 'szamlazva') {
                return;
            }

            var leAr = leSor.querySelector('[data-sdh-tetel="ar"]');

            if (leAr) {
                leAr.value = cel > 0 ? String(-cel).replace('.', ',') : '0';
            }

            leSor.classList.toggle('is-torlendo', cel <= 0);
        });

        bevElonezet(gyoker, esz, eszSor);

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
            mlDarab(gyoker, panel.getAttribute('data-sdh-tetelek'), db + panel.querySelectorAll('[data-sdh-bev-elonezet]').length);

            osszNetto += netto;
            osszBrutto += brutto;
        });

        // Még nincs díjsor / levonássor (a mentés hozza létre): már most beleszámít.
        var bevLapAfa = gyoker.querySelector('[data-sdh-lap-afa]');
        var bevOpcio = bevLapAfa ? bevLapAfa.options[bevLapAfa.selectedIndex] : null;
        var bevSzazalek = bevOpcio ? parseFloat(bevOpcio.getAttribute('data-szazalek')) || 0 : 27;
        var bevHianyzo = 0;

        eszSor.forEach(function (kulcs) {
            var e = esz[kulcs];

            bevHianyzo += (e.vanDij ? 0 : e.dij) - (e.vanLe ? 0 : e.levon);
        });

        if (Math.abs(bevHianyzo) >= 0.005) {
            osszBrutto += bevHianyzo;
            osszNetto += mlKerek(bevHianyzo / (1 + bevSzazalek / 100));
        }

        // Eszközönkénti bontás a bal oldalon: végösszeg és fizetendő készülékenként.
        var bontasHely = gyoker.querySelector('[data-sdh-eszkoz-bontas]');
        var bontasFizetve = !!(gyoker.querySelector('[data-sdh-fizetve]') || {}).checked;

        if (bontasHely) {
            bontasHely.hidden = eszSor.length < 2;
            bontasHely.innerHTML = eszSor.length < 2 ? '' : '<h4>Eszközönként</h4>' + eszSor.map(function (kulcs, i) {
                var e = esz[kulcs];
                var brutto = e.tobbi + e.dij - e.levon;
                // A csatolt eszközre jutó előleg: a díja + amit külön befizettek rá; a fő eszközé a maradék.
                var eloleg = kulcs === '0' ? Math.max(0, bevElolegOssz - bevCsatolt - bevKulon) : e.dij + e.kulon;
                var fizetendo = bontasFizetve ? 0 : brutto - eloleg;

                return '<div class="sdh-ml__bontas-sor" data-sdh-bontas="' + szovegBiztonsagos(kulcs) + '" title="' + szovegBiztonsagos(e.nev) + '">' +
                    '<span class="sdh-ml__bontas-nev">' + (i + 1) + '. ' + szovegBiztonsagos(e.nev) + '</span>' +
                    '<span class="sdh-ml__bontas-szam"><b>' + mlPenz(brutto) + '\u00a0Ft</b>' +
                    '<i' + (fizetendo < 0 ? ' class="is-negativ"' : '') + '>fizetendő ' + mlPenz(fizetendo) + '\u00a0Ft</i></span></div>';
            }).join('');
        }

        // Az Eszközök panel kártyái: eszközönként végösszeg, befizetve, fizetendő (vagy „Fizetve").
        eszSor.forEach(function (kulcs) {
            var penzHely = gyoker.querySelector('[data-sdh-eszk-penz="' + kulcs + '"]');

            if (!penzHely) {
                return;
            }

            if (eszSor.length < 2) {
                penzHely.innerHTML = '';

                return;
            }

            var e = esz[kulcs];
            var kartya = penzHely.closest('[data-sdh-eszk], [data-sdh-tovabbi]');
            var brutto = e.tobbi + e.dij - e.levon;
            var eloleg = kulcs === '0' ? Math.max(0, bevElolegOssz - bevCsatolt - bevKulon) : e.dij + e.kulon;
            var kifizetve = bontasFizetve || !!(kartya && kartya.getAttribute('data-fizetve') === '1');
            var hatra = kifizetve ? 0 : brutto - eloleg;

            // Három adat egymás mellett (felirat fölül, összeg alul); kifizetve egy zöld sáv.
            var stat = function (cimke, osszeg, osztaly) {
                return '<span class="sdh-eszk__stat' + (osztaly ? ' ' + osztaly : '') + '"><i>' + cimke + '</i><b>' + mlPenz(osszeg) + '\u00a0Ft</b></span>';
            };

            penzHely.innerHTML = kifizetve
                ? '<span class="sdh-eszk__fizetve"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.4l3 3 6-6.4"/></svg>Fizetve' + (eloleg > 0.5 ? '<b>' + mlPenz(eloleg) + '\u00a0Ft</b>' : '') + '</span>'
                : stat('Végösszeg', brutto, '') +
                  stat('Befizetve', eloleg, eloleg > 0.5 ? '' : 'is-nulla') +
                  stat('Fizetendő', hatra, hatra > 0.5 ? 'is-hatra' : (hatra < -0.5 ? 'is-negativ' : 'is-nulla'));
        });

        // A fő eszköz díja: az előlegből adódik (nincs saját mezője).
        var foDijHely = gyoker.querySelector('[data-sdh-eszk-dij="0"]');

        if (foDijHely) {
            foDijHely.hidden = !bevJelolo || eszSor.length < 2;
            foDijHely.innerHTML = '<span class="sdh-eszk__dijcimke">Díj</span><b>' + mlPenz(esz['0'].dij) + '\u00a0Ft</b><i>az előlegből</i>';
        }

        bevInfo(gyoker, bevPipa, bevEloleg, bevMod, levon, tobbi, bevFo, bevCsatolt);

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

        // A pipák figyelmeztetései (pipaSugo) ebből veszik a számokat.
        gyoker.sdhSzamok = { dijak: bevEloleg, brutto: osszBrutto, fizetett: fizetett, fizetendo: fizetendo };

        if (bevEloleg > 0) {
            gyoker.sdhDijak = bevEloleg;
        }

        mlIr(kiiras('netto'), mlPenz(osszNetto));
        mlIr(kiiras('afa'), mlPenz(osszBrutto - osszNetto));
        mlIr(kiiras('brutto'), mlPenz(osszBrutto));
        mlIr(kiiras('fizetendo'), mlPenz(fizetendo));

        if (kiiras('fizetendo')) {
            kiiras('fizetendo').classList.toggle('is-negativ', fizetendo < 0);
        }
    }

    /**
     * A még el nem mentett bevizsgálási díj (és levonása) azonnal látszik a
     * Szolgáltatások között – nem kell menteni és újranyitni a lapot. Ezek csak
     * előnézeti sorok (szürke, szaggatott keret, nem küldődnek be, és nem számítanak
     * kétszer a végösszegbe): a valódi sort a mentés hozza létre, ugyanezzel az
     * összeggel. Eszközönként egy díjsor és – javításnál – egy levonássor.
     */
    function bevElonezet(gyoker, esz, eszSor) {
        var panel = gyoker.querySelector('[data-sdh-tetelek="szolgaltatas"]');
        var sorok = panel ? panel.querySelector('[data-sdh-tetelsorok]') : null;
        var sablon = panel ? panel.querySelector('template[data-sdh-tetel-sablon]') : null;

        if (!sorok) {
            return;
        }

        Array.prototype.forEach.call(sorok.querySelectorAll('[data-sdh-bev-elonezet]'), function (s) { s.remove(); });

        if (!sablon || !sablon.content) {
            return;
        }

        var afaMezo = gyoker.querySelector('[data-sdh-lap-afa]');
        var afaOpcio = afaMezo ? afaMezo.options[afaMezo.selectedIndex] : null;
        var szazalek = afaOpcio ? parseFloat(afaOpcio.getAttribute('data-szazalek')) || 0 : 27;
        var tobbEszkoz = eszSor.length > 1;

        function sor(nev, ar, levonas, kulcs) {
            var minta = sablon.content.querySelector('[data-sdh-tetelsor]');

            if (!minta) {
                return;
            }

            var s = minta.cloneNode(true);
            var nevMezo = s.querySelector('input[name$="[megnevezes]"]');
            var arMezo = s.querySelector('[data-sdh-tetel="ar"]');
            var eszkMezo = s.querySelector('[data-sdh-tetel-eszkoz]');

            if (nevMezo) {
                nevMezo.value = nev;
            }

            if (arMezo) {
                arMezo.value = String(ar).replace('.', ',');
            }

            if (eszkMezo) {
                eszkMezo.value = kulcs;
            }

            mlIr(s.querySelector('[data-sdh-tetel="netto"]'), mlPenz(mlKerek(ar / (1 + szazalek / 100))));
            mlIr(s.querySelector('[data-sdh-tetel="brutto"]'), mlPenz(ar));

            // Csak kijelzés: semmi nem küldődik be belőle, és a gombjai nem élnek.
            Array.prototype.forEach.call(s.querySelectorAll('input, select, button, textarea'), function (el) {
                el.disabled = true;
                el.removeAttribute('name');
            });
            Array.prototype.forEach.call(s.querySelectorAll('*'), function (el) {
                Array.prototype.slice.call(el.attributes).forEach(function (a) {
                    if (a.name.indexOf('data-') === 0 && a.name !== 'data-sdh-tetel') {
                        el.removeAttribute(a.name);
                    }
                });
            });
            s.removeAttribute('data-sdh-tetelsor');
            s.setAttribute('data-sdh-bev-elonezet', levonas ? 'le' : 'dij');
            s.classList.add('sdh-tetelsor--elonezet');

            if (levonas) {
                s.classList.add('sdh-tetelsor--levonas');
            }

            s.title = 'Mentéskor kerül a munkalapra – addig előnézet';
            sorok.insertBefore(s, sorok.firstChild);
        }

        // Fordított sorrendben szúrjuk be: a lap tetején díj, alatta a levonás, eszközönként.
        eszSor.slice().reverse().forEach(function (kulcs) {
            var e = esz[kulcs];
            var elotag = tobbEszkoz && e.nev ? e.nev + ' – ' : '';

            if (e.levon > 0 && !e.vanLe) {
                sor(elotag + 'Bevizsgálási díj levonása (javítás esetén)', -e.levon, true, kulcs);
            }

            if (e.dij > 0 && !e.vanDij) {
                sor(elotag ? elotag + 'bevizsgálási díj' : 'Bevizsgálási díj', e.dij, false, kulcs);
            }
        });
    }

    /** A bevizsgálási díj sora alatti magyarázat, és a „Ha javítás" választó láthatósága. */
    function bevInfo(gyoker, pipa, dij, mod, levon, tobbi, foDij, csatoltDij) {
        var info = gyoker.querySelector('[data-sdh-bev-info]');
        var modDoboz = gyoker.querySelector('[data-sdh-bev-mod-doboz]');
        var nevMezo = gyoker.querySelector('[data-sdh-bev-nev]');
        var be = !!(pipa && pipa.checked);
        var szoveg = '';

        if (modDoboz) {
            modDoboz.hidden = !be;
        }

        // Áttekinthető kártya: a díj, mi lesz vele (összeg), és egy rövid megjegyzés.
        var sor = function (cimke, ertek, osztaly) {
            return '<div class="sdh-bev__tetel' + (osztaly ? ' ' + osztaly : '') + '"><span>' + cimke + '</span><b>' + ertek + '</b></div>';
        };
        var html = '';

        if (be) {
            var nev = nevMezo && nevMezo.value ? nevMezo.value : '';

            if (dij <= 0) {
                html = '<p class="sdh-bev__megj">Írd be a díjat a <b>Fizetett (előleg)</b> mezőbe, vagy válaszd a díjlistából.</p>';
            } else {
                html = sor(csatoltDij > 0 ? 'Díjak összesen' : 'Bevizsgálási díj', mlPenz(dij) + '\u00a0Ft', '');

                if (mod === 'marad') {
                    html += sor('Munkadíjként marad', 'nem vonódik le', 'is-marad');
                } else if (levon > 0) {
                    html += sor('Levonódik a végösszegből', '\u2212' + mlPenz(levon) + '\u00a0Ft', 'is-levon');

                    if (levon < dij) {
                        html += '<p class="sdh-bev__megj">Csak a többi tétel összegéig vonódik le.</p>';
                    }
                } else {
                    html += sor('Javításnál levonódik', 'addig munkadíj', 'is-marad');
                }

                if (nev !== '' && csatoltDij <= 0) {
                    html += '<p class="sdh-bev__megj">' + szovegBiztonsagos(nev) + '</p>';
                }

                if (csatoltDij > 0) {
                    html += '<p class="sdh-bev__megj">Eszközönként az Eszközök panel kártyáin.</p>';
                }
            }
        }

        szoveg = html;

        if (info) {
            info.innerHTML = html;
            info.hidden = html === '';
        }
    }

    /** Díj választása a díjlistából: az előleg, a jelölő és a díj neve egyszerre töltődik. */
    function bevListaNyit(gomb, cimke) {
        // cimke: egy csatolt eszköz címkéje – akkor annak a díja töltődik, nem a fő eszközé.
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

                if (cimke) {
                    var dijMezo = cimke.querySelector('[data-sdh-tovabbi-dij]');

                    cimke.querySelector('[data-sdh-tovabbi-dijnev]').value = t.nev;
                    cimke.querySelector('[data-sdh-tovabbi-dijszolg]').value = String(t.id);
                    dijMezo.value = String(t.ar || 0);
                    // Az előleg és a jelölő a díj változását követi (tovabbiDijValtozott).
                    dijMezo.sdhListabol = true;
                    dijMezo.dispatchEvent(new Event('change', { bubbles: true }));
                    dijMezo.focus();

                    return;
                }

                if (eloleg) {
                    // Az előleg az összes díj (a fő eszközé + a csatoltaké) és ami a díjakon felül már befolyt.
                    eloleg.value = String((t.ar || 0) + tovabbiDijOssz(gyoker) + kulonOssz(gyoker));
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

                if (cimke) {
                    var kezzel = cimke.querySelector('[data-sdh-tovabbi-dij]');

                    cimke.querySelector('[data-sdh-tovabbi-dijnev]').value = '';
                    cimke.querySelector('[data-sdh-tovabbi-dijszolg]').value = '0';
                    kezzel.focus();
                    kezzel.select();

                    return;
                }

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

        // Több eszköznél az új sor az előző (nem díj-) sor eszközét örökli: jellemzően
        // egymás után ugyanannak a készüléknek a tételeit viszik fel.
        var elozoSorok = lista.querySelectorAll('[data-sdh-tetelsor]:not([data-sdh-bev]):not([data-sdh-bevle]) [data-sdh-tetel-eszkoz]');
        var orokolt = elozoSorok.length ? elozoSorok[elozoSorok.length - 1].value : '0';

        lista.appendChild(sor);
        hibaEszkozFrissit(lista.closest('form'));

        var ujEszkoz = sor.querySelector('[data-sdh-tetel-eszkoz]');

        if (ujEszkoz && ujEszkoz.querySelector('option[value="' + orokolt + '"]')) {
            ujEszkoz.value = orokolt;
            ujEszkoz.title = ujEszkoz.options[ujEszkoz.selectedIndex].textContent;
        }

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

        // Az ügyfél és az eszköz szerkesztőgombja csak kiválasztott ügyfélnél / eszköznél él.
        ugyfelSzerkAllapot(urlap);
        eszkozSzerkAllapot(urlap);

        if (!hely) {
            return;
        }

        var ugyfel = urlap.querySelector('.sdh-valaszto input[type="hidden"]');
        var eszkoz = urlap.querySelector('[data-sdh-eszkoz-valaszto]');
        var lap = urlap.querySelector('[data-sdh-munkalap]');
        var cim = new URL(beallitas.ajax, window.location.origin);

        cim.searchParams.set('action', 'sdh_muhely_munkalapok_osszefoglalo');
        cim.searchParams.set('ugyfel_id', ugyfel ? ugyfel.value : '0');
        cim.searchParams.set('eszkoz_id', eszkoz ? eszkoz.value : '0');
        cim.searchParams.set('tovabbi', tovabbiIdk(urlap).join(','));
        // Eladásnál (árukiadás, rendelés) nincs eszköz: csak az ügyfél kártyája jön.
        cim.searchParams.set('fajta', (lap && lap.getAttribute('data-sdh-fajta')) || 'javitas');
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        tovabbiSzamol(urlap);

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
                    eszkKartyaFrissit(urlap, hely);
                }
            })
            .catch(function () { /* az összefoglaló csak tájékoztat: hiba esetén a régi marad */ });
    }

    /* ---- Több eszköz egy munkalapon: a csatolt (további) eszközök címkéi ---- */

    function tovabbiLista(urlap) {
        return urlap ? urlap.querySelector('[data-sdh-tovabbi-lista]') : null;
    }

    /** A csatolt eszközök azonosítói, sorrendben. */
    function tovabbiIdk(urlap) {
        var lista = tovabbiLista(urlap);

        return lista
            ? Array.prototype.map.call(lista.querySelectorAll('[data-sdh-tovabbi]'), function (cimke) {
                return cimke.getAttribute('data-sdh-tovabbi');
            })
            : [];
    }

    /** A csatolt eszközök bevizsgálási díjainak összege. */
    function tovabbiDijOssz(gyoker) {
        var ossz = 0;

        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-tovabbi-lista] [data-sdh-tovabbi-dij]'), function (mezo) {
            ossz += Math.max(0, mlSzam(mezo.value));
        });

        return ossz;
    }

    /** A díjakon felül befizetett összeg: a fő eszközé (data-fo-kulon) + a csatolt eszközöké (data-kulon). */
    function kulonOssz(gyoker) {
        var ossz = Math.max(0, parseFloat(gyoker.getAttribute('data-fo-kulon') || '0') || 0);

        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-tovabbi-lista] [data-sdh-tovabbi]'), function (cimke) {
            ossz += Math.max(0, parseFloat(cimke.getAttribute('data-kulon') || '0') || 0);
        });

        return ossz;
    }

    /**
     * Egy csatolt eszköz díja megváltozott (vagy az eszköz lekerült a lapról):
     * az előleg ugyanennyivel változik – az előleg a díjak összege –, és ha
     * van díj, a „Bevizsgálási díj" jelölő bekapcsol.
     */
    function tovabbiDijValtozott(gyoker, mezo, torolve) {
        var uj = torolve ? 0 : Math.max(0, mlSzam(mezo.value));
        var regi = parseFloat(mezo.getAttribute('data-elozo') || '0') || 0;
        var eloleg = gyoker.querySelector('[data-sdh-fizetett]');
        var pipa = gyoker.querySelector('[data-sdh-bevizsgalas]');

        mezo.setAttribute('data-elozo', String(uj));

        if (eloleg && Math.abs(uj - regi) >= 0.005) {
            eloleg.value = String(Math.max(0, mlSzam(eloleg.value) + uj - regi)).replace('.', ',');
        }

        if (pipa && uj > 0) {
            pipa.checked = true;
        }

        munkalapSzamol(gyoker);
    }

    /**
     * A hibasorok eszközválasztója: 0 = a fő eszköz, utána a csatoltak. Több
     * eszköznél látszik; a listája követi, mi van éppen a lapon. Ha egy hiba
     * eszköze lekerül a lapról, a hiba a fő eszközhöz kerül.
     */
    function hibaEszkozFrissit(urlap) {
        var panel = urlap ? urlap.querySelector('[data-sdh-hibak]') : null;
        var lista = tovabbiLista(urlap);

        if (!panel || !lista) {
            return;
        }

        var fo = urlap.querySelector('[data-sdh-eszkoz-valaszto]');
        var foNev = fo && fo.value !== '0' && fo.options[fo.selectedIndex] ? fo.options[fo.selectedIndex].textContent.trim() : 'fő eszköz';
        var elemek = [['0', '1. ' + foNev]];

        Array.prototype.forEach.call(lista.querySelectorAll('[data-sdh-tovabbi]'), function (cimke, i) {
            elemek.push([cimke.getAttribute('data-sdh-tovabbi'), (i + 2) + '. ' + cimke.querySelector('[data-sdh-tovabbi-nev]').textContent.trim()]);
        });

        var lapGyoker = urlap.querySelector('[data-sdh-munkalap]');

        panel.classList.toggle('is-tobb-eszkoz', elemek.length > 1);
        panel.sdhEszkozok = elemek;

        // A tételsorok (szolgáltatás, termék) eszközválasztója ugyanebből a listából él.
        if (lapGyoker) {
            lapGyoker.classList.toggle('is-tobb-eszkoz', elemek.length > 1);
        }

        Array.prototype.forEach.call(urlap.querySelectorAll('[data-sdh-hibasorok] [data-sdh-hiba-eszkoz], [data-sdh-tetelsorok] [data-sdh-tetel-eszkoz]'), function (valaszto) {
            var sor = valaszto.closest('[data-sdh-tetelsor]');
            // A díj és a levonás sora az eszközéhez kötött: a választója azt mutatja.
            var most = sor && sor.hasAttribute('data-sdh-bev-eszkoz') ? sor.getAttribute('data-sdh-bev-eszkoz') : valaszto.value;

            valaszto.innerHTML = '';

            elemek.forEach(function (e) {
                var opcio = document.createElement('option');

                opcio.value = e[0];
                opcio.textContent = e[1];
                valaszto.appendChild(opcio);
            });

            valaszto.value = elemek.some(function (e) { return e[0] === most; }) ? most : '0';
            valaszto.title = valaszto.options[valaszto.selectedIndex] ? valaszto.options[valaszto.selectedIndex].textContent : '';
        });
    }

    /**
     * Az Eszközök panel kártyáinak képe, neve és alcíme az Eszköz lapfül
     * friss összefoglalójából (data-sdh-eszk-info): eszközcsere és új eszköz után.
     */
    function eszkKartyaFrissit(urlap, hely) {
        var forras = hely ? hely.querySelector('[data-sdh-eszk-info]') : null;
        var info = {};

        try {
            info = forras ? JSON.parse(forras.textContent || '{}') || {} : {};
        } catch (hiba) {
            info = {};
        }

        Array.prototype.forEach.call(urlap.querySelectorAll('[data-sdh-fo-kartya], [data-sdh-tovabbi-lista] [data-sdh-tovabbi]'), function (kartya) {
            var kulcs = kartya.hasAttribute('data-sdh-fo-kartya') ? '0' : kartya.getAttribute('data-sdh-tovabbi');
            var adat = info[kulcs];
            var kepHely = kartya.querySelector('[data-sdh-eszk-kep]');
            var al = kartya.querySelector('[data-sdh-eszk-al]');
            var foNev = kartya.querySelector('[data-sdh-fo-nev]');

            if (kulcs === '0') {
                kartya.classList.toggle('is-ures', !adat);

                if (foNev) {
                    foNev.textContent = adat ? adat.nev : 'Válassz eszközt a fenti mezőben';
                }
            }

            if (al) {
                al.textContent = adat ? adat.al || '' : '';
            }

            // A csatolt eszköz neve is a tömör megnevezés legyen (az IMEI az alcímben áll).
            var tNev = kulcs !== '0' ? kartya.querySelector('[data-sdh-tovabbi-nev]') : null;

            if (tNev && adat && adat.nev) {
                tNev.textContent = adat.nev;
            }

            if (kepHely) {
                var kep = kepHely.querySelector('img');
                var cim = adat && adat.kep ? adat.kep : '';

                if (!cim) {
                    kepHely.innerHTML = '<span class="sdh-mockup sdh-mockup--m sdh-mockup--ures" aria-hidden="true"></span>';
                } else if (!kep || kep.getAttribute('src') !== cim) {
                    kepHely.innerHTML = '<span class="sdh-mockup sdh-mockup--m"><img alt="" loading="lazy" decoding="async"></span>';
                    kepHely.querySelector('img').src = cim;
                }
            }
        });
    }

    /** A lapfül jelvénye (hány eszköz van a lapon) és az „üres" felirat. */
    function tovabbiSzamol(urlap) {
        var lista = tovabbiLista(urlap);

        if (!lista) {
            return;
        }

        hibaEszkozFrissit(urlap);

        var fo = urlap.querySelector('[data-sdh-eszkoz-valaszto]');
        var db = tovabbiIdk(urlap).length;
        var ures = lista.querySelector('[data-sdh-tovabbi-ures]');
        var jel = urlap.querySelector('[data-sdh-db="eszkoz"]');
        var ossz = db + (fo && fo.value && fo.value !== '0' ? 1 : 0);

        if (ures) {
            ures.hidden = db > 0;
        }

        if (jel) {
            jel.hidden = ossz < 2;
            jel.textContent = String(ossz);
        }
    }

    function tovabbiTorol(urlap, id) {
        var lista = tovabbiLista(urlap);
        var cimke = lista && id && id !== '0'
            ? lista.querySelector('[data-sdh-tovabbi="' + String(id).replace(/[^0-9]/g, '') + '"]')
            : null;

        if (cimke) {
            tovabbiLevesz(cimke);
        }

        tovabbiSzamol(urlap);
    }

    /** Címke levétele úgy, hogy az eszköz díja az előlegből is kikerül. */
    function tovabbiLevesz(cimke) {
        var dij = cimke.querySelector('[data-sdh-tovabbi-dij]');
        var gyoker = cimke.closest('[data-sdh-munkalap]');

        cimke.remove();

        if (dij && gyoker) {
            tovabbiDijValtozott(gyoker, dij, true);
        }
    }

    /** Csak a megadott azonosítójú csatolt eszközök maradnak (ügyfélváltás után). */
    function tovabbiSzur(urlap, megmarad) {
        var lista = tovabbiLista(urlap);

        if (!lista) {
            return;
        }

        Array.prototype.forEach.call(lista.querySelectorAll('[data-sdh-tovabbi]'), function (cimke) {
            if (megmarad.indexOf(cimke.getAttribute('data-sdh-tovabbi')) === -1) {
                tovabbiLevesz(cimke);
            }
        });

        tovabbiSzamol(urlap);
    }

    /**
     * Eszköz a munkalapra. Ha a fő választó még üres, oda kerül; különben új
     * címkeként a csatoltak közé. Ami már a lapon van, azt nem veszi fel újra.
     */
    function tovabbiHozzaad(urlap, id, nev) {
        var lista = tovabbiLista(urlap);
        var fo = urlap.querySelector('[data-sdh-eszkoz-valaszto]');

        id = String(id);

        if (!lista || !id || id === '0') {
            return;
        }

        if (fo && (!fo.value || fo.value === '0')) {
            if (!fo.querySelector('option[value="' + id + '"]')) {
                var opcio = document.createElement('option');

                opcio.value = id;
                opcio.textContent = nev;
                fo.appendChild(opcio);
            }

            fo.value = id;
            fo.dispatchEvent(new Event('change', { bubbles: true }));

            return;
        }

        if ((fo && fo.value === id) || tovabbiIdk(urlap).indexOf(id) !== -1) {
            return;
        }

        var sablon = urlap.querySelector('[data-sdh-tovabbi-sablon]');
        var tarolo = document.createElement('div');

        // A sablonban az azonosító helyén `__ID__` áll (a díjmezők neve ettől eszközönként más).
        tarolo.innerHTML = sablon ? sablon.innerHTML.split('__ID__').join(id.replace(/[^0-9]/g, '')) : '';

        var cimke = tarolo.querySelector('[data-sdh-tovabbi]');

        if (!cimke) {
            return;
        }

        cimke.querySelector('[data-sdh-tovabbi-nev]').textContent = nev;
        cimke.title = nev;
        lista.appendChild(cimke);

        munkalapOsszefoglalo(urlap);
    }

    /** Az ügyfél eszközei a szerverről: [{ id, nev }]. */
    function ugyfelEszkozei(ugyfelId) {
        var cim = new URL(beallitas.ajax, window.location.origin);

        cim.searchParams.set('action', 'sdh_muhely_munkalapok_eszkozok');
        cim.searchParams.set('ugyfel_id', ugyfelId);
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        return fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (valasz) { return valasz.json(); })
            .then(function (eredmeny) {
                return (eredmeny && eredmeny.success && eredmeny.data) || [];
            });
    }

    /** „Eszköz csatolása": az ügyfél még fel nem vett készülékei választó popupban, vagy új felvitele. */
    function tovabbiNyit(gomb) {
        var urlap = gomb.closest('form');
        var valaszto = urlap ? urlap.querySelector('.sdh-valaszto') : null;
        var ugyfelId = valaszto ? valaszto.querySelector('input[type="hidden"]').value : '0';

        if (!ugyfelId || ugyfelId === '0') {
            mutatUrlapHiba(urlap, 'Előbb válassz ügyfelet – az eszköz mindig egy ügyfélhez tartozik.');

            return;
        }

        var betoltve = null;

        function foglalt() {
            var fo = urlap.querySelector('[data-sdh-eszkoz-valaszto]');

            return tovabbiIdk(urlap).concat(fo && fo.value !== '0' ? [fo.value] : []);
        }

        function ujEszkoz() {
            nyit('eszkozok', '0', {
                szint: Math.min(sajatSzint(gomb) + 1, szintek.length - 1),
                parameterek: { ugyfel_id: ugyfelId },
                siker: function (adat) {
                    if (String(adat.ugyfel_id) !== String(ugyfelId)) {
                        mutatUrlapHiba(urlap, 'Az új eszköz másik ügyfélhez került, ezért erre a munkalapra nem csatolható.');

                        return;
                    }

                    ugyfelEszkozei(ugyfelId).then(function (eszkozok) {
                        var fo = urlap.querySelector('[data-sdh-eszkoz-valaszto]');
                        var nev = 'Eszköz ' + adat.id;

                        eszkozok.forEach(function (e) {
                            if (String(e.id) === String(adat.id)) {
                                nev = e.nev;
                            }

                            // A fő választó is ismerje meg az új készüléket.
                            if (fo && !fo.querySelector('option[value="' + e.id + '"]')) {
                                var opcio = document.createElement('option');

                                opcio.value = String(e.id);
                                opcio.textContent = e.nev;
                                fo.appendChild(opcio);
                            }
                        });

                        tovabbiHozzaad(urlap, adat.id, nev);
                    });
                }
            });
        }

        szValNyit(gomb, {
            cim: 'Eszköz csatolása',
            alcim: 'Az ügyfél készülékei, amelyek még nincsenek ezen a munkalapon. Egy munkalapra akárhány eszköz felvehető.',
            helyorzo: 'Típus, IMEI, sorozatszám…',
            egyseg: 'eszköz',
            ures: 'Az ügyfélnek nincs több felvitt eszköze. Az „Új eszköz felvitele” gombbal vihetsz fel újat.',
            oszlopok: [['Eszköz', '']],
            keziCimke: 'Új eszköz felvitele',
            forras: function (q) {
                betoltve = betoltve || ugyfelEszkozei(ugyfelId);

                return betoltve.then(function (eszkozok) {
                    var van = foglalt();
                    var szavak = iszNorm(String(q || '').trim()).split(/\s+/).filter(Boolean);

                    return eszkozok.filter(function (e) {
                        var szoveg = iszNorm(e.nev);

                        return van.indexOf(String(e.id)) === -1 && szavak.every(function (szo) {
                            return szoveg.indexOf(szo) !== -1;
                        });
                    });
                });
            },
            cellak: function (e) {
                return [szovegBiztonsagos(e.nev)];
            },
            valaszt: function (e) {
                tovabbiHozzaad(urlap, e.id, e.nev);
            },
            kezi: ujEszkoz
        });
    }

    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;

        if (!cel.closest) {
            return;
        }

        var csatol = cel.closest('[data-sdh-tovabbi-uj]');

        if (csatol) {
            esemeny.preventDefault();
            tovabbiNyit(csatol);

            return;
        }

        var levesz = cel.closest('[data-sdh-tovabbi-torol]');

        if (levesz) {
            var urlap = levesz.closest('form');

            esemeny.preventDefault();

            // A levett eszköz díja az előlegből is kikerül.
            tovabbiLevesz(levesz.closest('[data-sdh-tovabbi]'));
            munkalapOsszefoglalo(urlap);

            return;
        }

        var dijLista = cel.closest('[data-sdh-tovabbi-dijlista]');

        if (dijLista) {
            esemeny.preventDefault();
            bevListaNyit(dijLista, dijLista.closest('[data-sdh-tovabbi]'));
        }
    });

    document.addEventListener('change', function (esemeny) {
        var mezo = esemeny.target.closest ? esemeny.target.closest('[data-sdh-tovabbi-dij]') : null;
        var gyoker = mezo ? mezo.closest('[data-sdh-munkalap]') : null;

        if (gyoker) {
            // Kézzel átírt díj már nem a díjlista tétele.
            if (mezo.sdhListabol) {
                mezo.sdhListabol = false;
            } else {
                var cimke = mezo.closest('[data-sdh-tovabbi]');

                cimke.querySelector('[data-sdh-tovabbi-dijnev]').value = '';
                cimke.querySelector('[data-sdh-tovabbi-dijszolg]').value = '0';
            }

            tovabbiDijValtozott(gyoker, mezo, false);
        }
    });

    /** Frissen betöltött munkalap-űrlap: első számolás. */
    function munkalapIndul(gyoker) {
        Array.prototype.forEach.call((gyoker || document).querySelectorAll('[data-sdh-munkalap]'), munkalapSzamol);

        Array.prototype.forEach.call((gyoker || document).querySelectorAll('[data-sdh-tovabbi-eszkozok]'), function (blokk) {
            tovabbiSzamol(blokk.closest('form'));

            // A betöltött díj a kiindulás: ehhez képest változik majd az előleg.
            Array.prototype.forEach.call(blokk.querySelectorAll('[data-sdh-tovabbi-dij]'), function (mezo) {
                mezo.setAttribute('data-elozo', String(Math.max(0, mlSzam(mezo.value))));
            });
        });

        // A mentett állapot: ehhez képest dől el, kell-e azonnal menteni (urlapAllapotMent).
        Array.prototype.forEach.call((gyoker || document).querySelectorAll('[data-sdh-allapot-valaszto]'), function (valaszto) {
            valaszto.dataset.elozo = valaszto.value;
        });
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
            // A fő eszköznek választott készülék nem maradhat a csatoltak között is.
            tovabbiTorol(cel.closest('form'), cel.value);
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

    /** A kiválasztott ügyfél azonosítója az űrlapon ('0', ha nincs). */
    function munkalapUgyfelId(urlap) {
        var rejtett = urlap ? urlap.querySelector('.sdh-valaszto input[type="hidden"]') : null;

        return rejtett && rejtett.value ? String(rejtett.value) : '0';
    }

    /** Az ügyfélmező melletti szerkesztőgomb csak kiválasztott ügyfélnél kattintható. */
    function ugyfelSzerkAllapot(urlap) {
        if (!urlap) {
            return;
        }

        var nincs = munkalapUgyfelId(urlap) === '0';

        Array.prototype.forEach.call(urlap.querySelectorAll('.sdh-mezo__sor [data-sdh-ugyfel-szerk]'), function (gomb) {
            gomb.disabled = nincs;
        });
    }

    /**
     * Ceruza az ügyfélmező mellett (és „Szerkesztés" az ügyfél kártyáján): a
     * betöltött ügyfél űrlapja a munkalap / árukiadás / rendelés fölött nyílik.
     * Mentés után a lap megmarad, a név és az adatok helyben frissülnek.
     */
    function munkalapUgyfelSzerk(gomb) {
        var urlap = gomb.closest('form');
        var id = munkalapUgyfelId(urlap);

        if (id === '0') {
            var mezo = urlap ? urlap.querySelector('.sdh-valaszto__mezo') : null;

            if (mezo) {
                mezo.focus();
            }

            return;
        }

        nyit('ugyfelek', id, {
            szint: Math.min(sajatSzint(gomb) + 1, szintek.length - 1),
            siker: function (adat) {
                // Közben másik ügyfelet választottak? Akkor a lapot nem írjuk felül.
                if (munkalapUgyfelId(urlap) === String(adat.id)) {
                    munkalapUgyfelBeallit(urlap, adat.id, adat.nev);
                }

                munkalapOsszefoglalo(urlap);
                document.dispatchEvent(new CustomEvent('sdh:ugyfel-mentve', { detail: { id: String(adat.id), nev: adat.nev } }));
            }
        });
    }

    /** A fő eszköz ceruzája csak kiválasztott eszköznél kattintható. */
    function eszkozSzerkAllapot(urlap) {
        var fo = urlap ? urlap.querySelector('[data-sdh-eszkoz-valaszto]') : null;

        if (!fo) {
            return;
        }

        Array.prototype.forEach.call(urlap.querySelectorAll('.sdh-mezo__sor [data-sdh-eszkoz-szerk]'), function (gomb) {
            gomb.disabled = !fo.value || fo.value === '0';
        });
    }

    /**
     * Szerkesztés után az eszközök neve helyben frissül: a fő választóban és
     * a csatolt eszközök címkéin is. Ha az eszköz közben másik ügyfélhez
     * került, lekerül a lapról (más ügyfél készüléke nem maradhat rajta).
     */
    function munkalapEszkozNevek(urlap) {
        var ugyfelId = munkalapUgyfelId(urlap);
        var fo = urlap ? urlap.querySelector('[data-sdh-eszkoz-valaszto]') : null;

        if (!fo || ugyfelId === '0') {
            munkalapOsszefoglalo(urlap);

            return;
        }

        var cim = new URL(beallitas.ajax, window.location.origin);

        cim.searchParams.set('action', 'sdh_muhely_munkalapok_eszkozok');
        cim.searchParams.set('ugyfel_id', ugyfelId);
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (valasz) { return valasz.json(); })
            .then(function (eredmeny) {
                var eszkozok = (eredmeny && eredmeny.success && eredmeny.data) || [];
                var nevek = {};

                eszkozok.forEach(function (e) { nevek[String(e.id)] = e.nev; });

                Array.prototype.slice.call(fo.options).forEach(function (opcio) {
                    if (opcio.value === '0') {
                        return;
                    }

                    if (nevek[opcio.value] === undefined) {
                        if (opcio.selected) {
                            fo.value = '0';
                        }

                        fo.removeChild(opcio);
                    } else {
                        opcio.textContent = nevek[opcio.value];
                    }
                });

                // Az ügyfél új (itt még nem látott) eszközei is választhatók legyenek.
                eszkozok.forEach(function (e) {
                    if (!fo.querySelector('option[value="' + String(e.id) + '"]')) {
                        var elem = document.createElement('option');

                        elem.value = String(e.id);
                        elem.textContent = e.nev;
                        fo.appendChild(elem);
                    }
                });

                tovabbiSzur(urlap, Object.keys(nevek));

                var lista = tovabbiLista(urlap);

                Array.prototype.forEach.call(lista ? lista.querySelectorAll('[data-sdh-tovabbi]') : [], function (cimke) {
                    var nev = cimke.querySelector('[data-sdh-tovabbi-nev]');
                    var uj = nevek[cimke.getAttribute('data-sdh-tovabbi')];

                    if (nev && uj !== undefined) {
                        nev.textContent = uj;
                    }
                });

                munkalapOsszefoglalo(urlap);
                munkalapSzamol(urlap.querySelector('[data-sdh-munkalap]') || urlap);
            })
            .catch(function () {
                munkalapOsszefoglalo(urlap);
            });
    }

    /**
     * Ceruza az eszközmező mellett, „Szerkesztés" az eszköz kártyáján és a
     * csatolt eszközökön: a betöltött eszköz űrlapja a munkalap fölött nyílik.
     * Mentés után a lap megmarad, a név és az adatok helyben frissülnek.
     */
    function munkalapEszkozSzerk(gomb) {
        var urlap = gomb.closest('form');
        var fo = urlap ? urlap.querySelector('[data-sdh-eszkoz-valaszto]') : null;
        var id = gomb.getAttribute('data-sdh-eszkoz-szerk') || (fo ? fo.value : '0');

        if (!id || id === '0' || !/^\d+$/.test(id)) {
            if (fo) {
                fo.focus();
            }

            return;
        }

        nyit('eszkozok', id, {
            szint: Math.min(sajatSzint(gomb) + 1, szintek.length - 1),
            siker: function (adat) {
                munkalapEszkozNevek(urlap);
                document.dispatchEvent(new CustomEvent('sdh:eszkoz-mentve', { detail: { id: String(adat && adat.id ? adat.id : id) } }));
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
        hibaEszkozFrissit(lista.closest('form'));
        munkalapSzamol(lista.closest('[data-sdh-munkalap]'));

        var elso = sor.querySelector('input[type="text"]');
        if (elso) {
            elso.focus();
        }
    }

    /* ---- Gyakori hibák: egy kattintással a munkalapra (Hibák lapfül) ---- */

    var GYH_OLDAL = 30;

    /** A munkalapon most szereplő hibák (kisbetűsen), hogy a popup jelölni tudja őket. */
    function gyhFelvettek(panel, eszkoz) {
        var ki = {};

        Array.prototype.forEach.call(panel.querySelectorAll('[data-sdh-hibasorok] [data-sdh-hibasor]'), function (sor) {
            var mezo = sor.querySelector('.sdh-hibasor__leiras');
            var valaszto = sor.querySelector('[data-sdh-hiba-eszkoz]');
            var szoveg = mezo ? mezo.value.trim().toLowerCase() : '';

            // Ugyanaz a hiba két eszközön két külön hiba: csak a kért eszközét nézzük.
            if (szoveg && (!valaszto || (valaszto.value || '0') === String(eszkoz || '0'))) {
                ki[szoveg] = true;
            }
        });

        return ki;
    }

    /** Hiba a munkalapra: az első üres sorba, különben új sorba kerül. */
    function gyhSorba(panel, szoveg, eszkoz) {
        var ures = null;

        Array.prototype.forEach.call(panel.querySelectorAll('[data-sdh-hibasor]'), function (sor) {
            var leiras = sor.querySelector('.sdh-hibasor__leiras');
            var javitas = sor.querySelector('.sdh-hibasor__javitas');

            if (!ures && leiras && leiras.value.trim() === '' && (!javitas || javitas.value.trim() === '')) {
                ures = sor;
            }
        });

        if (!ures) {
            hibasorUj(panel.querySelector('[data-sdh-hibasor-uj]'));

            var sorok = panel.querySelectorAll('[data-sdh-hibasor]');

            ures = sorok[sorok.length - 1];
        }

        var mezo = ures ? ures.querySelector('.sdh-hibasor__leiras') : null;
        var kie = ures ? ures.querySelector('[data-sdh-hiba-eszkoz]') : null;

        if (kie) {
            kie.value = String(eszkoz || '0');
        }

        if (mezo) {
            mezo.value = szoveg;
            mezo.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    function gyakoriHibakNyit(gomb) {
        var panel = gomb.closest('[data-sdh-hibak]');

        if (!panel) {
            return;
        }

        var lista = [];

        try {
            lista = JSON.parse(panel.getAttribute('data-sdh-gyakori') || '[]') || [];
        } catch (e) {
            lista = [];
        }

        var oldal = 0;
        // Több eszköznél: melyik eszköz hibáját vesszük fel (0 = a fő eszköz).
        var eszkozok = panel.sdhEszkozok && panel.sdhEszkozok.length > 1 ? panel.sdhEszkozok : [];
        var eszkoz = '0';
        var dialog = document.createElement('dialog');

        dialog.className = 'sdh-modal sdh-modal--pop sdh-gyh';
        dialog.innerHTML = '<div class="sdh-modal__doboz">' + ablakGombok(false) +
            '  <div class="sdh-modal__torzs">' +
            '    <h2 class="sdh-modal__cim">Gyakori hibák</h2>' +
            '    <p class="sdh-modal__alcim">Kattints a hibára – felkerül a munkalapra. Egymás után többet is választhatsz.</p>' +
            (eszkozok.length
                ? '    <div class="sdh-gyh__eszkozok" role="radiogroup" aria-label="Melyik eszköz hibája"><span>Melyik eszköz hibája:</span>' +
                    eszkozok.map(function (e) {
                        return '<button type="button" class="sdh-pill' + (e[0] === '0' ? ' is-aktiv' : '') + '" role="radio"' +
                            ' aria-checked="' + (e[0] === '0' ? 'true' : 'false') + '" data-gyh-eszkoz="' + szovegBiztonsagos(e[0]) + '">' +
                            '<span class="sdh-pill__cimke">' + szovegBiztonsagos(e[1]) + '</span></button>';
                    }).join('') + '</div>'
                : '') +
            '    <div class="sdh-gyh__lista" data-gyh-lista role="listbox" aria-multiselectable="true"></div>' +
            '    <div class="sdh-gyh__lapozo" data-gyh-lapozo hidden>' +
            '      <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-lapnyil" data-gyh-lap="-1" aria-label="Előző oldal">' + LAP_NYIL_BAL + '</button>' +
            '      <span data-gyh-oldal></span>' +
            '      <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-lapnyil" data-gyh-lap="1" aria-label="Következő oldal">' + LAP_NYIL_JOBB + '</button>' +
            '    </div>' +
            '    <form class="sdh-gyh__uj" data-gyh-uj>' +
            '      <input type="text" maxlength="255" autocomplete="off" data-gyh-mezo aria-label="Más hiba" placeholder="Más hiba – írd be…">' +
            '      <button type="submit" class="sdh-gomb sdh-gomb--vilagos"><span class="sdh-plusz" aria-hidden="true"></span>Felvétel</button>' +
            '      <label class="sdh-jelolo" title="Bepipálva a beírt hiba a gyakori hibák listájára is felkerül, és legközelebb már innen választható.">' +
            '        <input type="checkbox" data-gyh-listara checked> A gyakori hibák közé is</label>' +
            '    </form>' +
            '    <p class="sdh-gyh__uzenet" data-gyh-uzenet role="status"></p>' +
            '    <div class="sdh-gyh__lab">' +
            '      <span class="sdh-mezo__sugo" data-gyh-db></span>' +
            '      <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-gyh-kesz>Kész</button>' +
            '    </div>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(dialog);

        var hely = dialog.querySelector('[data-gyh-lista]');
        var uzenet = dialog.querySelector('[data-gyh-uzenet]');
        var felveve = 0;

        function rajzol() {
            var van = gyhFelvettek(panel, eszkoz);
            var oldalak = Math.max(1, Math.ceil(lista.length / GYH_OLDAL));

            oldal = Math.max(0, Math.min(oldal, oldalak - 1));
            hely.innerHTML = '';

            if (!lista.length) {
                hely.appendChild(popElem('p', 'sdh-gyh__ures', 'A lista még üres – írd be alul az első hibát.'));
            }

            lista.slice(oldal * GYH_OLDAL, (oldal + 1) * GYH_OLDAL).forEach(function (nev) {
                var bent = !!van[nev.toLowerCase()];
                var elem = popElem('button', 'sdh-pill sdh-gyh__elem' + (bent ? ' is-aktiv' : ''));

                elem.type = 'button';
                elem.setAttribute('role', 'option');
                elem.setAttribute('aria-selected', bent ? 'true' : 'false');
                elem.setAttribute('data-gyh-hiba', nev);
                elem.title = bent ? nev + ' – már a munkalapon van' : nev;
                elem.appendChild(popElem('span', 'sdh-pill__cimke', nev));
                hely.appendChild(elem);
            });

            dialog.querySelector('[data-gyh-lapozo]').hidden = oldalak <= 1;
            dialog.querySelector('[data-gyh-oldal]').textContent = (oldal + 1) + ' / ' + oldalak;
            dialog.querySelector('[data-gyh-lap="-1"]').disabled = oldal <= 0;
            dialog.querySelector('[data-gyh-lap="1"]').disabled = oldal >= oldalak - 1;
            dialog.querySelector('[data-gyh-db]').textContent = felveve ? felveve + ' hiba felvéve a munkalapra' : '';
        }

        function felvesz(nev) {
            if (gyhFelvettek(panel, eszkoz)[nev.toLowerCase()]) {
                return;
            }

            gyhSorba(panel, nev, eszkoz);
            felveve++;
            rajzol();
        }

        dialog.addEventListener('click', function (esemeny) {
            var cel = esemeny.target;

            if (cel === dialog || cel.closest('.sdh-modal__bezar') || cel.closest('[data-gyh-kesz]')) {
                dialog.close();

                return;
            }

            var kie = cel.closest('[data-gyh-eszkoz]');

            if (kie) {
                eszkoz = kie.getAttribute('data-gyh-eszkoz');

                Array.prototype.forEach.call(dialog.querySelectorAll('[data-gyh-eszkoz]'), function (g) {
                    g.classList.toggle('is-aktiv', g === kie);
                    g.setAttribute('aria-checked', g === kie ? 'true' : 'false');
                });

                rajzol();

                return;
            }

            var hiba = cel.closest('[data-gyh-hiba]');

            if (hiba) {
                felvesz(hiba.getAttribute('data-gyh-hiba'));

                return;
            }

            var lap = cel.closest('[data-gyh-lap]');

            if (lap) {
                oldal += parseInt(lap.getAttribute('data-gyh-lap'), 10);
                rajzol();
            }
        });

        dialog.querySelector('[data-gyh-uj]').addEventListener('submit', function (esemeny) {
            esemeny.preventDefault();
            // A munkalap űrlapja ettől nem küldődhet be.
            esemeny.stopPropagation();

            var mezo = dialog.querySelector('[data-gyh-mezo]');
            var nev = mezo.value.trim();

            uzenet.textContent = '';
            uzenet.className = 'sdh-gyh__uzenet';

            if (!nev) {
                mezo.focus();

                return;
            }

            felvesz(nev);
            mezo.value = '';
            mezo.focus();

            if (!dialog.querySelector('[data-gyh-listara]').checked) {
                return;
            }

            var adatok = new FormData();

            adatok.set('action', 'sdh_muhely_munkalapok_gyakori_hiba');
            adatok.set('_wpnonce', beallitas.nonce || '');
            adatok.set('nev', nev);

            fetch(beallitas.ajax, { method: 'POST', body: adatok, credentials: 'same-origin' })
                .then(function (valasz) { return valasz.json(); })
                .then(function (valasz) {
                    if (!valasz || !valasz.success) {
                        throw new Error((valasz && valasz.data && valasz.data.uzenet) || 'A lista nem bővült.');
                    }

                    lista = valasz.data.lista || lista;

                    // A még nyitott űrlapok is az új listát kapják.
                    Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-gyakori]'), function (p) {
                        p.setAttribute('data-sdh-gyakori', JSON.stringify(lista));
                    });

                    oldal = Math.floor(Math.max(0, lista.length - 1) / GYH_OLDAL);
                    uzenet.textContent = '„' + nev + '” felkerült a gyakori hibák közé is.';
                    uzenet.className = 'sdh-gyh__uzenet is-jo';
                    rajzol();
                })
                .catch(function (ok) {
                    uzenet.textContent = 'A munkalapra felkerült, de a listára nem: ' + ok.message;
                    uzenet.className = 'sdh-gyh__uzenet is-hiba';
                });
        });

        dialog.addEventListener('close', function () {
            dialog.remove();

            if (gomb.focus) {
                gomb.focus();
            }
        });

        rajzol();
        dialog.showModal();

        var elso = hely.querySelector('.sdh-gyh__elem:not(.is-aktiv)') || dialog.querySelector('[data-gyh-mezo]');

        if (elso) {
            elso.focus();
        }
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-gyakori-hibak]') : null;

        if (gomb) {
            esemeny.preventDefault();
            gyakoriHibakNyit(gomb);
        }
    });

    /* ---- Gyakori megjegyzések: popupból a munkalap „Megjegyzés" mezőjébe ---- */

    /** A megjegyzés sorai (üres sorok nélkül). */
    function megjSorok(mezo) {
        return (mezo.value || '').split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
    }

    /**
     * Előre megírt megjegyzések választója. Kattintásra a szöveg a megjegyzés
     * új sora lesz; újra kattintva lekerül. Egyszerre több is választható, és
     * a lista innen is bővíthető. Bármelyik állapotú lapon működik.
     */
    function gyakoriMegjegyzesNyit(gomb) {
        var fulek = gomb.closest('.sdh-fulek');
        var mezo = fulek ? fulek.querySelector('textarea[name="ugyfel_megjegyzes"]') : null;

        if (!mezo) {
            return;
        }

        // A „Megjegyzés" fül kerül elő (a gomb a Belső megjegyzés fülről is elérhető).
        var megjFul = fulek.querySelector(':scope > .sdh-fulek__ful--1');

        if (megjFul && !megjFul.checked) {
            megjFul.checked = true;
            megjFul.dispatchEvent(new Event('change', { bubbles: true }));
        }

        var lista = [];

        try {
            lista = JSON.parse(gomb.getAttribute('data-sdh-lista') || '[]') || [];
        } catch (e) {
            lista = [];
        }

        var oldal = 0;
        var dialog = document.createElement('dialog');

        dialog.className = 'sdh-modal sdh-modal--pop sdh-gyh sdh-gym';
        dialog.innerHTML = '<div class="sdh-modal__doboz">' + ablakGombok(false) +
            '  <div class="sdh-modal__torzs">' +
            '    <h2 class="sdh-modal__cim">Gyakori megjegyzések</h2>' +
            '    <p class="sdh-modal__alcim">Kattints a megjegyzésre – a munkalap Megjegyzésébe kerül (az ügyfél is látja). Többet is választhatsz; újra kattintva lekerül.</p>' +
            '    <div class="sdh-gyh__lista" data-gyh-lista role="listbox" aria-multiselectable="true"></div>' +
            '    <div class="sdh-gyh__lapozo" data-gyh-lapozo hidden>' +
            '      <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-lapnyil" data-gyh-lap="-1" aria-label="Előző oldal">' + LAP_NYIL_BAL + '</button>' +
            '      <span data-gyh-oldal></span>' +
            '      <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-lapnyil" data-gyh-lap="1" aria-label="Következő oldal">' + LAP_NYIL_JOBB + '</button>' +
            '    </div>' +
            '    <form class="sdh-gyh__uj" data-gyh-uj>' +
            '      <input type="text" maxlength="255" autocomplete="off" data-gyh-mezo aria-label="Más megjegyzés" placeholder="Más megjegyzés – írd be…">' +
            '      <button type="submit" class="sdh-gomb sdh-gomb--vilagos"><span class="sdh-plusz" aria-hidden="true"></span>Felvétel</button>' +
            '      <label class="sdh-jelolo" title="Bepipálva a beírt megjegyzés a gyakori megjegyzések listájára is felkerül, és legközelebb már innen választható.">' +
            '        <input type="checkbox" data-gyh-listara checked> A gyakori megjegyzések közé is</label>' +
            '    </form>' +
            '    <p class="sdh-gyh__uzenet" data-gyh-uzenet role="status"></p>' +
            '    <div class="sdh-gyh__lab">' +
            '      <span class="sdh-mezo__sugo" data-gyh-db></span>' +
            '      <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-gyh-kesz>Kész</button>' +
            '    </div>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(dialog);

        var hely = dialog.querySelector('[data-gyh-lista]');
        var uzenet = dialog.querySelector('[data-gyh-uzenet]');
        var MEGJ_OLDAL = 12;

        function bent(szoveg) {
            var kis = szoveg.toLowerCase();

            return megjSorok(mezo).some(function (s) { return s.toLowerCase() === kis; });
        }

        function rajzol() {
            var oldalak = Math.max(1, Math.ceil(lista.length / MEGJ_OLDAL));
            var db = lista.filter(bent).length;

            oldal = Math.max(0, Math.min(oldal, oldalak - 1));
            hely.innerHTML = '';

            if (!lista.length) {
                hely.appendChild(popElem('p', 'sdh-gyh__ures', 'A lista még üres – írd be alul az első megjegyzést.'));
            }

            lista.slice(oldal * MEGJ_OLDAL, (oldal + 1) * MEGJ_OLDAL).forEach(function (szoveg) {
                var van = bent(szoveg);
                var elem = popElem('button', 'sdh-pill sdh-gyh__elem' + (van ? ' is-aktiv' : ''));

                elem.type = 'button';
                elem.setAttribute('role', 'option');
                elem.setAttribute('aria-selected', van ? 'true' : 'false');
                elem.setAttribute('data-gym-szoveg', szoveg);
                elem.title = van ? szoveg + ' – a megjegyzésben van (kattintásra lekerül)' : szoveg;
                elem.appendChild(popElem('span', 'sdh-pill__cimke', szoveg));
                hely.appendChild(elem);
            });

            dialog.querySelector('[data-gyh-lapozo]').hidden = oldalak <= 1;
            dialog.querySelector('[data-gyh-oldal]').textContent = (oldal + 1) + ' / ' + oldalak;
            dialog.querySelector('[data-gyh-lap="-1"]').disabled = oldal <= 0;
            dialog.querySelector('[data-gyh-lap="1"]').disabled = oldal >= oldalak - 1;
            dialog.querySelector('[data-gyh-db]').textContent = db ? db + ' gyakori megjegyzés a munkalapon' : '';
        }

        /** A szöveg sora be a megjegyzésbe / ki belőle; a kézzel írt többi sor érintetlen. */
        function valt(szoveg, csakBe) {
            var kis = szoveg.toLowerCase();

            if (bent(szoveg)) {
                if (csakBe) {
                    return;
                }

                mezo.value = (mezo.value || '').split(/\r?\n/).filter(function (s) {
                    return s.trim().toLowerCase() !== kis;
                }).join('\n').replace(/^\n+|\n+$/g, '');
            } else {
                mezo.value = (mezo.value || '').replace(/\s+$/, '');
                mezo.value += (mezo.value ? '\n' : '') + szoveg;
            }

            mezo.dispatchEvent(new Event('input', { bubbles: true }));
            rajzol();
        }

        dialog.addEventListener('click', function (esemeny) {
            var cel = esemeny.target;

            if (cel === dialog || cel.closest('.sdh-modal__bezar') || cel.closest('[data-gyh-kesz]')) {
                dialog.close();

                return;
            }

            var elem = cel.closest('[data-gym-szoveg]');

            if (elem) {
                valt(elem.getAttribute('data-gym-szoveg'), false);

                return;
            }

            var lap = cel.closest('[data-gyh-lap]');

            if (lap) {
                oldal += parseInt(lap.getAttribute('data-gyh-lap'), 10);
                rajzol();
            }
        });

        dialog.querySelector('[data-gyh-uj]').addEventListener('submit', function (esemeny) {
            esemeny.preventDefault();
            // A munkalap űrlapja ettől nem küldődhet be.
            esemeny.stopPropagation();

            var beMezo = dialog.querySelector('[data-gyh-mezo]');
            var szoveg = beMezo.value.trim();

            uzenet.textContent = '';
            uzenet.className = 'sdh-gyh__uzenet';

            if (!szoveg) {
                beMezo.focus();

                return;
            }

            valt(szoveg, true);
            beMezo.value = '';
            beMezo.focus();

            if (!dialog.querySelector('[data-gyh-listara]').checked) {
                return;
            }

            var adatok = new FormData();

            adatok.set('action', 'sdh_muhely_munkalapok_gyakori_megjegyzes');
            adatok.set('_wpnonce', beallitas.nonce || '');
            adatok.set('nev', szoveg);

            fetch(beallitas.ajax, { method: 'POST', body: adatok, credentials: 'same-origin' })
                .then(function (valasz) { return valasz.json(); })
                .then(function (valasz) {
                    if (!valasz || !valasz.success) {
                        throw new Error((valasz && valasz.data && valasz.data.uzenet) || 'A lista nem bővült.');
                    }

                    lista = valasz.data.lista || lista;

                    // A még nyitott űrlapok gombjai is az új listát kapják.
                    Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-gyakori-megj]'), function (g) {
                        g.setAttribute('data-sdh-lista', JSON.stringify(lista));
                    });

                    oldal = Math.floor(Math.max(0, lista.length - 1) / MEGJ_OLDAL);
                    uzenet.textContent = '„' + szoveg + '” felkerült a gyakori megjegyzések közé is.';
                    uzenet.className = 'sdh-gyh__uzenet is-jo';
                    rajzol();
                })
                .catch(function (ok) {
                    uzenet.textContent = 'A munkalapra felkerült, de a listára nem: ' + ok.message;
                    uzenet.className = 'sdh-gyh__uzenet is-hiba';
                });
        });

        dialog.addEventListener('close', function () {
            dialog.remove();
            mezo.focus();
        });

        rajzol();
        dialog.showModal();

        var elso = hely.querySelector('.sdh-gyh__elem') || dialog.querySelector('[data-gyh-mezo]');

        if (elso) {
            elso.focus();
        }
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-gyakori-megj]') : null;

        if (gomb) {
            esemeny.preventDefault();
            gyakoriMegjegyzesNyit(gomb);
        }
    });

    /**
     * Gyors szövegek az RMA csevegőjéhez: előre megírt üzenetek. Kattintásra a
     * beírómezőbe kerülnek (el nem mennek – a küldés gombnyomás marad). A lista
     * itt szerkeszthető: felvétel, átírás, sorrend, kétlépcsős törlés; minden
     * változás azonnal mentődik (rma: ajax_gyors_ment), a teljes listával.
     */
    function gyorsSzovegNyit(gomb) {
        var urlap = gomb.closest('[data-sdh-uzenet-urlap]');
        var mezo = urlap ? urlap.querySelector('[data-sdh-uzenet-szoveg]') : null;

        if (!mezo) {
            return;
        }

        var lista = [];

        try {
            lista = JSON.parse(gomb.getAttribute('data-sdh-lista') || '[]') || [];
        } catch (e) {
            lista = [];
        }

        var GYSZ_OLDAL = 6;
        var GYSZ_MAX = 40;
        var oldal = 0;
        var szerkesztett = -1;     // a most átírt sor indexe (-1: egyik sem)
        var torlendo = -1;         // a kétlépcsős törlés első lépése után
        var torlesIdo = 0;
        var dialog = document.createElement('dialog');

        dialog.className = 'sdh-modal sdh-modal--pop sdh-gyh sdh-gysz';
        dialog.innerHTML = '<div class="sdh-modal__doboz">' + ablakGombok(false) +
            '  <div class="sdh-modal__torzs">' +
            '    <h2 class="sdh-modal__cim">Gyors szövegek</h2>' +
            '    <p class="sdh-modal__alcim">Kattints a szövegre – a csevegő beírómezőjébe kerül, ott még átírhatod, és a Küldés gombbal megy el. A ceruzával átírod, a nyilakkal sorba rakod, a kukával törlöd.</p>' +
            '    <div class="sdh-gysz__lista" data-gysz-lista></div>' +
            '    <div class="sdh-gyh__lapozo" data-gyh-lapozo hidden>' +
            '      <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-lapnyil" data-gyh-lap="-1" aria-label="Előző oldal">' + LAP_NYIL_BAL + '</button>' +
            '      <span data-gyh-oldal></span>' +
            '      <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-lapnyil" data-gyh-lap="1" aria-label="Következő oldal">' + LAP_NYIL_JOBB + '</button>' +
            '    </div>' +
            '    <form class="sdh-gyh__uj sdh-gysz__uj" data-gysz-uj>' +
            '      <input type="text" maxlength="500" autocomplete="off" data-gysz-mezo aria-label="Új gyors szöveg" placeholder="Új gyors szöveg – írd be…">' +
            '      <button type="submit" class="sdh-gomb sdh-gomb--vilagos"><span class="sdh-plusz" aria-hidden="true"></span>Felvétel</button>' +
            '    </form>' +
            '    <p class="sdh-gyh__uzenet" data-gyh-uzenet role="status"></p>' +
            '    <div class="sdh-gyh__lab">' +
            '      <span class="sdh-mezo__sugo" data-gysz-db></span>' +
            '      <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-gyh-kesz>Kész</button>' +
            '    </div>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(dialog);

        var hely = dialog.querySelector('[data-gysz-lista]');
        var uzenet = dialog.querySelector('[data-gyh-uzenet]');
        var IKON_CERUZA = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M12.5 4.5l3 3L7 16H4v-3z"/></svg>';
        var IKON_KUKA = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4.5 6h11M8 6V4.5h4V6M6 6l.7 9.5h6.6L14 6"/></svg>';
        var IKON_FEL = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 12 10 7.5l4.5 4.5"/></svg>';
        var IKON_LE = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 8 10 12.5 14.5 8"/></svg>';

        function jelez(szoveg, fajta) {
            uzenet.textContent = szoveg || '';
            uzenet.className = 'sdh-gyh__uzenet' + (fajta ? ' is-' + fajta : '');
        }

        function ikonGomb(adat, i, ikon, cim, extra) {
            var g = popElem('button', 'sdh-gysz__ikon' + (extra || ''));

            g.type = 'button';
            g.setAttribute(adat, String(i));
            g.setAttribute('aria-label', cim);
            g.title = cim;
            g.innerHTML = ikon;

            return g;
        }

        function rajzol() {
            var oldalak = Math.max(1, Math.ceil(lista.length / GYSZ_OLDAL));

            oldal = Math.max(0, Math.min(oldal, oldalak - 1));
            hely.innerHTML = '';

            if (!lista.length) {
                hely.appendChild(popElem('p', 'sdh-gyh__ures', 'A lista üres – írd be alul az első gyors szöveget.'));
            }

            lista.slice(oldal * GYSZ_OLDAL, (oldal + 1) * GYSZ_OLDAL).forEach(function (szoveg, k) {
                var i = oldal * GYSZ_OLDAL + k;
                var sor = popElem('div', 'sdh-gysz__sor');

                if (i === szerkesztett) {
                    var be = popElem('input', 'sdh-gysz__szerk');

                    be.type = 'text';
                    be.maxLength = 500;
                    be.value = szoveg;
                    be.setAttribute('data-gysz-szerkmezo', String(i));
                    be.setAttribute('aria-label', 'A gyors szöveg átírása');
                    sor.appendChild(be);

                    var ment = popElem('button', 'sdh-gomb sdh-gomb--elsodleges sdh-gysz__ment', 'Mentés');

                    ment.type = 'button';
                    ment.setAttribute('data-gysz-ment', String(i));
                    sor.appendChild(ment);

                    var megse = popElem('button', 'sdh-gomb sdh-gomb--vilagos', 'Mégse');

                    megse.type = 'button';
                    megse.setAttribute('data-gysz-megse', '1');
                    sor.appendChild(megse);
                    hely.appendChild(sor);

                    return;
                }

                var elem = popElem('button', 'sdh-pill sdh-gysz__elem');

                elem.type = 'button';
                elem.setAttribute('data-gysz-be', String(i));
                elem.title = 'Beillesztés a beírómezőbe';
                elem.appendChild(popElem('span', 'sdh-pill__cimke', szoveg));
                sor.appendChild(elem);

                var fel = ikonGomb('data-gysz-fel', i, IKON_FEL, 'Feljebb');
                var le = ikonGomb('data-gysz-le', i, IKON_LE, 'Lejjebb');

                fel.disabled = i === 0;
                le.disabled = i === lista.length - 1;
                sor.appendChild(fel);
                sor.appendChild(le);
                sor.appendChild(ikonGomb('data-gysz-szerk', i, IKON_CERUZA, 'Átírás'));

                var torol = ikonGomb('data-gysz-torol', i, IKON_KUKA,
                    i === torlendo ? 'Biztosan törlöd? Kattints újra' : 'Törlés', ' sdh-gysz__ikon--torol');

                if (i === torlendo) {
                    torol.classList.add('is-biztos');
                    torol.innerHTML = '<span>Biztosan?</span>';
                }

                sor.appendChild(torol);
                hely.appendChild(sor);
            });

            dialog.querySelector('[data-gyh-lapozo]').hidden = oldalak <= 1;
            dialog.querySelector('[data-gyh-oldal]').textContent = (oldal + 1) + ' / ' + oldalak;
            dialog.querySelector('[data-gyh-lap="-1"]').disabled = oldal <= 0;
            dialog.querySelector('[data-gyh-lap="1"]').disabled = oldal >= oldalak - 1;
            dialog.querySelector('[data-gysz-db]').textContent = lista.length + ' / ' + GYSZ_MAX + ' gyors szöveg';

            var szerkMezo = hely.querySelector('[data-gysz-szerkmezo]');

            if (szerkMezo) {
                szerkMezo.focus();
                szerkMezo.setSelectionRange(szerkMezo.value.length, szerkMezo.value.length);
            }
        }

        /** A teljes lista mentése; hiba esetén a korábbi lista áll vissza. */
        function ment(uj, siker) {
            var elozo = lista.slice();
            var adatok = new FormData();

            lista = uj;
            rajzol();
            adatok.set('action', 'sdh_muhely_rma_gyors_ment');
            adatok.set('_wpnonce', beallitas.nonce || '');
            adatok.set('lista', JSON.stringify(uj));

            fetch(beallitas.ajax, { method: 'POST', body: adatok, credentials: 'same-origin' })
                .then(function (valasz) { return valasz.json(); })
                .then(function (valasz) {
                    if (!valasz || !valasz.success) {
                        throw new Error((valasz && valasz.data && valasz.data.uzenet) || 'A lista nem mentődött.');
                    }

                    lista = valasz.data.lista || uj;

                    // A többi nyitott csevegő gombja is az új listát kapja.
                    Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-gyors-szoveg]'), function (g) {
                        g.setAttribute('data-sdh-lista', JSON.stringify(lista));
                    });

                    jelez(siker, 'jo');
                    rajzol();
                })
                .catch(function (ok) {
                    lista = elozo;
                    jelez('Nem mentődött: ' + ok.message, 'hiba');
                    rajzol();
                });
        }

        /** Beillesztés: üres mezőbe a szöveg, különben új sorba a meglévő után; a kurzor a végére áll. */
        function beilleszt(szoveg) {
            var regi = (mezo.value || '').replace(/\s+$/, '');

            mezo.value = regi ? regi + '\n' + szoveg : szoveg;
            mezo.dispatchEvent(new Event('input', { bubbles: true }));
            dialog.close();
            mezo.focus();
            mezo.setSelectionRange(mezo.value.length, mezo.value.length);
        }

        function szerkMentes() {
            var be = hely.querySelector('[data-gysz-szerkmezo]');

            if (!be) {
                return;
            }

            var i = parseInt(be.getAttribute('data-gysz-szerkmezo'), 10);
            var szoveg = be.value.trim();

            if (!szoveg) {
                jelez('Üres szöveg nem menthető – a törléshez használd a kukát.', 'hiba');
                be.focus();

                return;
            }

            var kis = szoveg.toLowerCase();

            if (lista.some(function (s, j) { return j !== i && s.toLowerCase() === kis; })) {
                jelez('Ez a szöveg már szerepel a listában.', 'hiba');
                be.focus();

                return;
            }

            var uj = lista.slice();

            uj[i] = szoveg;
            szerkesztett = -1;
            ment(uj, 'Átírva.');
        }

        dialog.addEventListener('click', function (esemeny) {
            var cel = esemeny.target;

            if (cel === dialog || cel.closest('.sdh-modal__bezar') || cel.closest('[data-gyh-kesz]')) {
                dialog.close();

                return;
            }

            var el;

            if ((el = cel.closest('[data-gysz-be]'))) {
                beilleszt(lista[parseInt(el.getAttribute('data-gysz-be'), 10)] || '');

                return;
            }

            if ((el = cel.closest('[data-gysz-szerk]'))) {
                szerkesztett = parseInt(el.getAttribute('data-gysz-szerk'), 10);
                torlendo = -1;
                jelez('');
                rajzol();

                return;
            }

            if (cel.closest('[data-gysz-ment]')) {
                szerkMentes();

                return;
            }

            if (cel.closest('[data-gysz-megse]')) {
                szerkesztett = -1;
                jelez('');
                rajzol();

                return;
            }

            if ((el = cel.closest('[data-gysz-fel], [data-gysz-le]'))) {
                var fel = el.hasAttribute('data-gysz-fel');
                var i = parseInt(el.getAttribute(fel ? 'data-gysz-fel' : 'data-gysz-le'), 10);
                var j = fel ? i - 1 : i + 1;

                if (j < 0 || j >= lista.length) {
                    return;
                }

                var uj = lista.slice();
                var cs = uj[i];

                uj[i] = uj[j];
                uj[j] = cs;
                torlendo = -1;
                oldal = Math.floor(j / GYSZ_OLDAL);
                ment(uj, 'Sorrend mentve.');

                return;
            }

            if ((el = cel.closest('[data-gysz-torol]'))) {
                var t = parseInt(el.getAttribute('data-gysz-torol'), 10);

                // Kétlépcsős törlés: az első kattintás csak élesít, 4 mp-ig.
                if (torlendo !== t) {
                    torlendo = t;
                    clearTimeout(torlesIdo);
                    torlesIdo = setTimeout(function () {
                        if (dialog.isConnected && torlendo === t) {
                            torlendo = -1;
                            rajzol();
                        }
                    }, 4000);
                    rajzol();

                    return;
                }

                var maradek = lista.filter(function (s, k) { return k !== t; });

                torlendo = -1;
                ment(maradek, 'Törölve.');

                return;
            }

            var lap = cel.closest('[data-gyh-lap]');

            if (lap) {
                oldal += parseInt(lap.getAttribute('data-gyh-lap'), 10);
                szerkesztett = -1;
                torlendo = -1;
                rajzol();
            }
        });

        dialog.addEventListener('keydown', function (esemeny) {
            if (esemeny.target.matches && esemeny.target.matches('[data-gysz-szerkmezo]') && esemeny.key === 'Enter') {
                esemeny.preventDefault();
                szerkMentes();
            }
        });

        // Esc átírás közben csak az átírást vonja vissza, az ablak nyitva marad.
        dialog.addEventListener('cancel', function (esemeny) {
            if (szerkesztett >= 0) {
                esemeny.preventDefault();
                szerkesztett = -1;
                rajzol();
            }
        });

        dialog.querySelector('[data-gysz-uj]').addEventListener('submit', function (esemeny) {
            esemeny.preventDefault();
            // A munkalap űrlapja ettől nem küldődhet be.
            esemeny.stopPropagation();

            var beMezo = dialog.querySelector('[data-gysz-mezo]');
            var szoveg = beMezo.value.trim();
            var kis = szoveg.toLowerCase();

            jelez('');

            if (!szoveg) {
                beMezo.focus();

                return;
            }

            if (lista.some(function (s) { return s.toLowerCase() === kis; })) {
                jelez('Ez a szöveg már szerepel a listában.', 'hiba');

                return;
            }

            if (lista.length >= GYSZ_MAX) {
                jelez('A lista betelt (' + GYSZ_MAX + ' db) – törölj belőle.', 'hiba');

                return;
            }

            beMezo.value = '';
            oldal = Math.floor(lista.length / GYSZ_OLDAL);
            ment(lista.concat([szoveg]), '„' + szoveg + '” felkerült a listára.');
            beMezo.focus();
        });

        dialog.addEventListener('close', function () {
            clearTimeout(torlesIdo);
            dialog.remove();
        });

        rajzol();
        dialog.showModal();

        var elso = hely.querySelector('.sdh-gysz__elem') || dialog.querySelector('[data-gysz-mezo]');

        if (elso) {
            elso.focus();
        }
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-gyors-szoveg]') : null;

        if (gomb) {
            esemeny.preventDefault();
            gyorsSzovegNyit(gomb);
        }
    });

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

                // Több eszközös lap: a lap állapota minden eszközé lett – a blokkok is kövessék.
                eszkozBlokkCsere(valaszto.dataset.id || '0', adat);

                // A rács és a részletpanel ebből tudja, hogy frissítenie kell.
                document.dispatchEvent(new CustomEvent('sdh:allapot', {
                    detail: { id: valaszto.dataset.id || '0', adat: adat }
                }));
            })
            .catch(function () {
                vissza('Nem sikerült elérni a szervert, az állapot nem módosult.');
            });
    }

    /** Rövid visszajelzés az űrlap állapotválasztója alatt (mentve / majd a Mentéssel). */
    function urlapAllapotJelez(valaszto, szoveg, tipus) {
        var doboz = valaszto.closest('.sdh-ig') || valaszto.parentNode;
        var jel = doboz.querySelector('[data-sdh-allapot-jel]');

        if (!jel) {
            jel = document.createElement('span');
            jel.setAttribute('data-sdh-allapot-jel', '');
            jel.setAttribute('role', 'status');
            doboz.appendChild(jel);
        }

        jel.className = 'sdh-allapot-jel sdh-allapot-jel--' + tipus;
        jel.textContent = szoveg;
        window.clearTimeout(jel.sdhIdo);

        if (tipus === 'jo') {
            jel.sdhIdo = window.setTimeout(function () {
                jel.remove();
            }, 3500);
        }
    }

    /**
     * Állapotváltás a munkalap űrlapján. Már mentett lapnál az új állapot
     * azonnal mentődik (ugyanazon az úton, mint a listából), a popup nyitva
     * marad, a mögötte lévő rács és a részletek pedig maguktól frissülnek –
     * nem kell se Mentést nyomni, se újratölteni. Új (még nem mentett) lapnál
     * az állapot a többi mezővel együtt, a Mentéssel megy.
     */
    function urlapAllapotMent(valaszto) {
        var urlap = valaszto.closest('form');
        var idMezo = urlap ? urlap.querySelector('input[name="id"]') : null;
        var id = idMezo ? parseInt(idMezo.value, 10) || 0 : 0;

        if (!id || valaszto.value === (valaszto.dataset.elozo || '')) {
            return;
        }

        var kert = valaszto.value;
        var adatok = new FormData();

        adatok.set('action', 'sdh_muhely_munkalapok_allapot');
        adatok.set('_wpnonce', beallitas.nonce || '');
        adatok.set('id', String(id));
        adatok.set('allapot', kert);

        fetch(new URL(beallitas.ajax, window.location.origin).toString(), {
            method: 'POST',
            body: adatok,
            credentials: 'same-origin'
        })
            .then(function (valasz) {
                return valasz.json();
            })
            .then(function (valasz) {
                // Közben már mást választott: az a kérés a mérvadó.
                if (valaszto.value !== kert) {
                    return;
                }

                if (!valasz || !valasz.success) {
                    // Pl. az űrlapon most kiválasztott ügyfél még nincs elmentve:
                    // a választás az űrlapon marad, és a Mentéssel megy tovább.
                    urlapAllapotJelez(
                        valaszto,
                        'A Mentéssel együtt mentődik. ' + ((valasz && valasz.data && valasz.data.uzenet) || ''),
                        'var'
                    );

                    return;
                }

                var adat = valasz.data;

                valaszto.dataset.elozo = kert;
                urlapAllapotJelez(valaszto, 'Állapot elmentve', 'jo');

                // Az első számozott állapotba lépéskor a lap számot kap: a cím is mutassa.
                var cim = valaszto.closest('.sdh-modal__torzs');
                var cimSor = cim ? cim.querySelector('.sdh-modal__cim') : null;

                if (cimSor && adat.szam && adat.szam !== '—' && cimSor.firstChild
                    && cimSor.firstChild.nodeType === 3 && cimSor.firstChild.textContent.indexOf(adat.szam) === -1) {
                    cimSor.firstChild.textContent = 'Munkalap ' + adat.szam + ' ';
                }

                var lezarva = urlap.querySelector('[data-sdh-lezarva]');

                if (lezarva) {
                    lezarva.hidden = !adat.lezarva;
                    lezarva.querySelector('[data-sdh-lezarva-ertek]').textContent = adat.lezarva || '';
                }

                // Több eszközös lap: a lap állapota minden eszközé lett – a blokk is kövesse.
                eszkozBlokkCsere(String(id), adat);

                // A rács, a részletpanel és a csempék ebből tudják, hogy frissíteniük kell.
                document.dispatchEvent(new CustomEvent('sdh:allapot', {
                    detail: { id: String(id), adat: adat, forras: 'urlap' }
                }));
            })
            .catch(function () {
                urlapAllapotJelez(valaszto, 'A szerver nem érhető el – az állapot a Mentéssel mentődik.', 'var');
            });
    }

    /* ----------------------------------------------------------------
     * Több eszközös munkalap: eszközönként saját állapot és „fizetve"
     *
     * A blokkot a szerver rajzolja (SDH_Muhely_Munkalap::eszkoz_allapot_blokk),
     * az űrlap bal oldalán és az Áttekintés részletpanelén is ott van. A váltás
     * azonnal mentődik; a válasz hozza a frissített blokkot és a lap közös
     * állapotát, amely az eszközökéből adódik.
     * ---------------------------------------------------------------- */

    /** A munkalap minden látható eszköz-blokkjának cseréje a szerver válaszára. */
    function eszkozBlokkCsere(id, adat) {
        if (!adat || typeof adat.blokk !== 'string') {
            return;
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-eszkoz-allapotok="' + String(id) + '"]'), function (regi) {
            if (adat.blokk === '') {
                regi.remove();

                return;
            }

            var tarto = document.createElement('div');

            tarto.innerHTML = adat.blokk;

            if (tarto.firstElementChild) {
                regi.parentNode.replaceChild(tarto.firstElementChild, regi);
            }
        });
    }

    /** Rövid visszajelzés az eszköz-blokk alján (mentve / hiba). */
    function eszkozBlokkJelez(id, szoveg, tipus) {
        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-eszkoz-allapotok="' + String(id) + '"]'), function (blokk) {
            var jel = blokk.querySelector('[data-sdh-eszall-jel]');

            if (!jel) {
                jel = document.createElement('p');
                jel.setAttribute('data-sdh-eszall-jel', '');
                jel.setAttribute('role', 'status');
                blokk.appendChild(jel);
            }

            jel.className = 'sdh-allapot-jel sdh-allapot-jel--' + tipus;
            jel.textContent = szoveg;
            window.clearTimeout(jel.sdhIdo);

            if (tipus === 'jo') {
                jel.sdhIdo = window.setTimeout(function () {
                    jel.remove();
                }, 3500);
            }
        });
    }

    /** A lap közös állapota megváltozott: a nyitott űrlap választója is kövesse. */
    function kozosAllapotAzUrlapon(id, adat) {
        if (!adat || !adat.kulcs) {
            return;
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-munkalap]'), function (gyoker) {
            var urlap = gyoker.closest('form');
            var idMezo = urlap ? urlap.querySelector('input[name="id"]') : null;
            var valaszto = urlap ? urlap.querySelector('[data-sdh-allapot-valaszto]') : null;

            if (!idMezo || !valaszto || String(parseInt(idMezo.value, 10) || 0) !== String(id)) {
                return;
            }

            var van = Array.prototype.some.call(valaszto.options, function (o) {
                return o.value === adat.kulcs;
            });

            // A „Részben elkészült" nincs a választható állapotok közt: most kerül be.
            if (!van) {
                var uj = document.createElement('option');

                uj.value = adat.kulcs;
                uj.textContent = adat.nev || adat.kulcs;
                uj.dataset.szin = adat.szin || 'szurke';
                valaszto.appendChild(uj);
            }

            valaszto.value = adat.kulcs;
            valaszto.dataset.elozo = adat.kulcs;
            allapotPontFrissit(valaszto);

            var lezarva = urlap.querySelector('[data-sdh-lezarva]');

            if (lezarva) {
                lezarva.hidden = !adat.lezarva;
                lezarva.querySelector('[data-sdh-lezarva-ertek]').textContent = adat.lezarva || '';
            }
        });
    }

    /** Egy eszköz műveletének elküldése (állapot vagy a „fizetve" levétele). */
    function eszkozMuvelet(action, mezok, siker, hiba) {
        var adatok = new FormData();

        adatok.set('action', action);
        adatok.set('_wpnonce', beallitas.nonce || '');

        Object.keys(mezok).forEach(function (k) {
            adatok.set(k, mezok[k]);
        });

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
                    hiba((valasz && valasz.data && valasz.data.uzenet) || 'A módosítás nem sikerült.');

                    return;
                }

                siker(valasz.data);
            })
            .catch(function () {
                hiba('Nem sikerült elérni a szervert, semmi nem módosult.');
            });
    }

    /** Egy eszköz saját állapotának váltása – azonnal mentődik. */
    function eszkozAllapotValt(valaszto) {
        var id = valaszto.dataset.id || '0';
        var elozo = valaszto.dataset.elozo || '';

        if (valaszto.value === elozo) {
            return;
        }

        valaszto.disabled = true;

        eszkozMuvelet('sdh_muhely_munkalapok_eszkoz_allapot', {
            id: id,
            kulcs: valaszto.dataset.kulcs || '0',
            allapot: valaszto.value
        }, function (adat) {
            eszkozBlokkCsere(id, adat);
            eszkozBlokkJelez(id, 'Elmentve – közös állapot: ' + (adat.nev || ''), 'jo');
            kozosAllapotAzUrlapon(id, adat);
            kartyaAllapotKesz(valaszto, 'Elmentve – közös állapot: ' + (adat.nev || ''), 'jo');

            // A rács, a részletpanel és a csempék ebből tudják, hogy frissíteniük kell.
            document.dispatchEvent(new CustomEvent('sdh:allapot', {
                detail: { id: id, adat: adat, forras: 'eszkoz' }
            }));
        }, function (uzenet) {
            valaszto.value = elozo;
            valaszto.disabled = false;
            eszkozBlokkJelez(id, uzenet, 'var');
            kartyaAllapotKesz(valaszto, uzenet, 'var');
        });
    }

    /**
     * Az űrlap eszközkártyáján álló választó (azt a szerver válasza nem cseréli):
     * újra élő, a színe követi az állapotot, és rövid jelzés kerül a kártyára.
     */
    function kartyaAllapotKesz(valaszto, szoveg, tipus) {
        var kartya = valaszto.closest('.sdh-eszk');

        if (!kartya || !document.body.contains(valaszto)) {
            return;
        }

        valaszto.disabled = false;

        if (tipus === 'jo') {
            valaszto.dataset.elozo = valaszto.value;

            var opcio = valaszto.options[valaszto.selectedIndex];
            var hely = valaszto.closest('[data-sdh-allapot-hely]');

            if (hely && opcio) {
                hely.className = hely.className.replace(/\bsdh-allapot--(?!valaszthato\b)[a-z0-9_-]+/g, '').trim() + ' sdh-allapot--' + (opcio.getAttribute('data-szin') || 'szurke');
            }
        }

        var jel = kartya.querySelector('[data-sdh-eszk-jel]');

        if (!jel) {
            jel = document.createElement('p');
            jel.setAttribute('data-sdh-eszk-jel', '');
            jel.setAttribute('role', 'status');
            kartya.querySelector('.sdh-eszk__test').appendChild(jel);
        }

        jel.className = 'sdh-allapot-jel sdh-allapot-jel--' + tipus;
        jel.textContent = szoveg;
        window.clearTimeout(jel.sdhIdo);

        if (tipus === 'jo') {
            jel.sdhIdo = window.setTimeout(function () {
                jel.remove();
            }, 3500);
        }
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-eszkoz-fizetve-vissza]') : null;

        if (!gomb) {
            return;
        }

        esemeny.preventDefault();

        var id = gomb.dataset.id || '0';
        var befizetve = parseFloat(gomb.dataset.befizetve || '0') || 0;

        function levesz() {
            gomb.disabled = true;

            eszkozMuvelet('sdh_muhely_munkalapok_eszkoz_fizetve', { id: id, kulcs: gomb.dataset.kulcs || '0' }, function (adat) {
                // Az űrlap kártyáján: a jelző lekerül, a pénzsor újraszámolódik.
                var kartya = gomb.closest('.sdh-eszk');

                if (kartya) {
                    var lapGyoker = kartya.closest('[data-sdh-munkalap]');

                    kartya.setAttribute('data-fizetve', '0');
                    (gomb.closest('.sdh-eszk__vissza-hely') || gomb).remove();

                    if (lapGyoker) {
                        munkalapSzamol(lapGyoker);
                    }
                }

                eszkozBlokkCsere(id, adat);
                eszkozBlokkJelez(id, 'A „Fizetve” jelző lekerült az eszközről.', 'jo');
                document.dispatchEvent(new CustomEvent('sdh:allapot', {
                    detail: { id: id, adat: adat, forras: 'eszkoz' }
                }));
            }, function (uzenet) {
                gomb.disabled = false;
                eszkozBlokkJelez(id, uzenet, 'var');
            });
        }

        pipaSugo(
            gomb.closest('.sdh-eszall') || gomb,
            '**Ha leveszed a „Fizetve” jelzőt erről az eszközről** (' + (gomb.dataset.nev || '') + '):\n\n' +
            '- csak a jelző kerül le, az eszköz újra „nincs kifizetve” lesz;\n' +
            '- a rá befizetett **' + mlPenz(befizetve) + ' Ft megmarad**, ezért a fizetendője 0 Ft marad;\n' +
            '- a házipénztárból semmi nem törlődik.\n\n' +
            '**Mit tegyél?** Ha a fizetés tényleg megtörtént, ne vedd le. Ha tévedés volt: vedd le, utána a lapon a „Fizetett (előleg)” mezőt csökkentsd ' + mlPenz(befizetve) + ' Ft-tal (a díj marad), ments, és a Házipénztárban töröld a tételt.',
            [
                { felirat: 'Mégsem – maradjon fizetve', fo: true, tesz: function () {} },
                { felirat: 'Leveszem a jelzőt', tesz: levesz }
            ]
        );
    });

    /* ----------------------------------------------------------------
     * A munkalap pipáinak levétele: mielőtt bármi megváltozna, a rendszer
     * elmondja, mi történik és mit érdemes tenni. Ha az AI-asszisztens be van
     * kapcsolva, az ő buboréka szól; különben ugyanaz a szöveg a mező alatt.
     * ---------------------------------------------------------------- */

    /** Figyelmeztetés gombokkal: az asszisztens buborékában, vagy (ha nincs) a mező alatt. */
    function pipaSugo(horgony, szoveg, gombok) {
        if (window.SDH_AI_API && typeof window.SDH_AI_API.buborek === 'function') {
            window.SDH_AI_API.buborek(szoveg, gombok, { ms: 0, allapot: 'beszel' });

            return;
        }

        var hely = (horgony.closest && horgony.closest('.sdh-ig')) || horgony;
        var regi = hely.parentNode.querySelector('[data-sdh-pipa-sugo]');

        if (regi) {
            regi.remove();
        }

        var doboz = document.createElement('div');

        doboz.className = 'sdh-pipa-sugo';
        doboz.setAttribute('data-sdh-pipa-sugo', '');
        doboz.setAttribute('role', 'alert');

        szoveg.replace(/\*\*/g, '').split('\n').forEach(function (sor) {
            if (sor.trim() === '') {
                return;
            }

            var p = document.createElement('p');

            p.textContent = sor.replace(/^- /, '• ');
            doboz.appendChild(p);
        });

        var sav = document.createElement('div');

        sav.className = 'sdh-pipa-sugo__gombok';

        gombok.forEach(function (gomb) {
            var b = document.createElement('button');

            b.type = 'button';
            b.className = 'sdh-gomb ' + (gomb.fo ? 'sdh-gomb--elsodleges' : 'sdh-gomb--vilagos');
            b.textContent = gomb.felirat;
            b.addEventListener('click', function () {
                doboz.remove();
                gomb.tesz();
            });
            sav.appendChild(b);
        });

        doboz.appendChild(sav);
        hely.parentNode.insertBefore(doboz, hely.nextSibling);
    }

    /** A lap „Fizetve” pipájának levétele egy már kifizetett (mentett) lapon. */
    function fizetveLevetel(pipa) {
        var gyoker = pipa.closest('[data-sdh-munkalap]');
        var sz = gyoker.sdhSzamok || {};
        var eloleg = gyoker.querySelector('[data-sdh-fizetett]');
        var visszaMezo = (gyoker.closest('form') || gyoker).querySelector('[data-sdh-fizetes-vissza]');
        var mentett = parseFloat(gyoker.getAttribute('data-eloleg-mentett') || '0') || 0;
        var veg = Math.min(mentett, parseFloat(gyoker.getAttribute('data-veg-ossz') || '0') || 0);
        var kassza = parseFloat(gyoker.getAttribute('data-kasszaban') || '0') || 0;

        var szoveg = '**Ha kiveszed a „Fizetve” pipát:**\n\n' +
            '- a munkalap újra „nincs kifizetve” lesz;\n' +
            '- a befizetett **' + mlPenz(mentett) + ' Ft a „Fizetett” mezőben marad**, ezért a fizetendő ettől még nem jön vissza;\n' +
            (kassza > 0 ? '- a házipénztárban **' + mlPenz(kassza) + ' Ft** szerepel erre a lapra – az sem törlődik magától.\n' : '') +
            '\n**Mit tegyél?**\n\n' +
            '- Ha az ügyfél tényleg fizetett: **ne vedd ki** a pipát.\n' +
            (veg > 0
                ? '- Ha a fizetés nem történt meg: válaszd a **„Fizetés visszavonása”** gombot. A lezáró ' + mlPenz(veg) + ' Ft lekerül, a „Fizetett” ' + mlPenz(mentett - veg) + ' Ft lesz (a díjak és a korábbi előlegek megmaradnak), a fizetendő visszajön. Utána **Mentés**' + (kassza > 0 ? ', és a Házipénztárban töröld a hozzá tartozó tételt.' : '.') + '\n'
                : '- Ha a fizetés nem történt meg: vedd ki a pipát, a „Fizetett (előleg)” mezőbe írd be, amennyit valóban fizetett, és ments' + (kassza > 0 ? '; a Házipénztárban töröld a téves tételt.' : '.') + '\n');

        var gombok = [
            { felirat: 'Mégsem – maradjon fizetve', fo: true, tesz: function () {
                pipa.checked = true;
                munkalapSzamol(gyoker);
            } }
        ];

        if (veg > 0) {
            gombok.push({ felirat: 'Fizetés visszavonása', tesz: function () {
                if (visszaMezo) {
                    visszaMezo.value = '1';
                }

                if (eloleg) {
                    eloleg.value = String(Math.max(0, mentett - veg)).replace('.', ',');
                }

                munkalapSzamol(gyoker);
            } });
        }

        gombok.push({ felirat: 'Csak a pipát veszem ki', tesz: function () {
            munkalapSzamol(gyoker);
        } });

        pipaSugo(pipa, szoveg, gombok);

        return sz;
    }

    /** A „Bevizsgálási díj” pipa levétele egy olyan lapon, amelyen már van mentett díj. */
    function bevizsgalasLevetel(pipa) {
        var gyoker = pipa.closest('[data-sdh-munkalap]');
        var dijak = 0;

        // A pipa már ki van véve (a díj most 0): az utolsó, még bepipált állapot díjösszege kell.
        dijak = gyoker.sdhDijak || 0;

        pipaSugo(
            pipa,
            '**Ha kiveszed a „Bevizsgálási díj” pipát:**\n\n' +
            '- mentéskor **törlődnek a bevizsgálási díj sorai és a levonásuk** minden eszközről' + (dijak > 0 ? ' (most ' + mlPenz(dijak) + ' Ft díj)' : '') + ';\n' +
            '- a már befizetett összeg nem vész el: sima előlegként a „Fizetett” mezőben marad, és levonódik a végösszegből;\n' +
            '- ha nincs javítás, a díj nem számlázódik ki – a befizetett díj visszajár az ügyfélnek.\n\n' +
            '**Mit tegyél?** Csak akkor vedd ki, ha tévedésből lett díj a lapon. Ha az ügyfél díjat fizetett, hagyd bepipálva: javításnál magától levonódik, javítás nélkül munkadíjként marad.',
            [
                { felirat: 'Mégsem – maradjon a díj', fo: true, tesz: function () {
                    pipa.checked = true;
                    munkalapSzamol(gyoker);
                } },
                { felirat: 'Kiveszem a pipát', tesz: function () {} }
            ]
        );
    }

    document.addEventListener('change', function (esemeny) {
        var cel = esemeny.target;
        var gyoker = cel.closest ? cel.closest('[data-sdh-munkalap]') : null;

        if (!gyoker) {
            return;
        }

        if (cel.hasAttribute('data-sdh-fizetve')) {
            var visszaMezo = (gyoker.closest('form') || gyoker).querySelector('[data-sdh-fizetes-vissza]');

            // Visszapipálva a visszavonás is érvényét veszti.
            if (cel.checked && visszaMezo && visszaMezo.value === '1') {
                var eloleg = gyoker.querySelector('[data-sdh-fizetett]');

                visszaMezo.value = '';

                if (eloleg) {
                    eloleg.value = String(parseFloat(gyoker.getAttribute('data-eloleg-mentett') || '0') || 0).replace('.', ',');
                }

                munkalapSzamol(gyoker);
            }

            if (!cel.checked && gyoker.getAttribute('data-fizetve-mentett') === '1') {
                fizetveLevetel(cel);
            }

            return;
        }

        if (cel.hasAttribute('data-sdh-bevizsgalas') && !cel.checked && gyoker.getAttribute('data-bev-mentett') === '1') {
            bevizsgalasLevetel(cel);
        }
    });

    document.addEventListener('change', function (esemeny) {
        var listaAllapot = esemeny.target.closest('select[data-sdh-lista-allapot]');

        if (listaAllapot) {
            listaAllapotValt(listaAllapot);

            return;
        }

        var eszkozAllapot = esemeny.target.closest('select[data-sdh-eszkoz-allapot]');

        if (eszkozAllapot) {
            eszkozAllapotValt(eszkozAllapot);

            return;
        }

        var allapot = esemeny.target.closest('[data-sdh-allapot-valaszto]');

        if (allapot) {
            allapotPontFrissit(allapot);
            urlapAllapotMent(allapot);

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

        /*
         * A popup CSS zoomja a menüre is hat: a pozíciót vissza kell osztani.
         * A zoom a popupon (a tárolón) áll, a menü csak örökli – a saját
         * számított `zoom` értéke ezért mindig 1 volna. Rossz osztónál a menü
         * a mező FÖLÉ csúszott, az egér felengedése már a menüre esett, és a
         * kattintás a popup hátterének számított: a munkalap bezárult.
         */
        var zoom = typeof menu.currentCSSZoom === 'number' && menu.currentCSSZoom > 0
            ? menu.currentCSSZoom
            : (tarolo !== document.body ? parseFloat(window.getComputedStyle(tarolo).zoom) || 1 : 1);
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
                var cel = esemeny.target;

                // Csak az a görgetés zár, ami a mezőt is elmozdítja: egy másik
                // doboz görgetése (pl. a rács alatti részletpanel újratöltése a
                // sor kijelölésekor) nem csukhatja be a frissen nyitott listát.
                if (!menu.contains(cel) && (!cel || !cel.contains || cel === document || cel.contains(select))) {
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

        var eszkozSzerkGomb = esemeny.target.closest('[data-sdh-eszkoz-szerk]');

        if (eszkozSzerkGomb) {
            esemeny.preventDefault();
            munkalapEszkozSzerk(eszkozSzerkGomb);

            return;
        }

        var ugyfelSzerkGomb = esemeny.target.closest('[data-sdh-ugyfel-szerk]');

        if (ugyfelSzerkGomb) {
            esemeny.preventDefault();
            munkalapUgyfelSzerk(ugyfelSzerkGomb);

            return;
        }

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
        // data-sdh-param-<név>: további kérésparaméter az űrlapnak (pl. előre kitöltött kérdés a Súgóban).
        var inditoOpciok = indito.dataset.sdhFul ? { ful: indito.dataset.sdhFul } : null;

        Object.keys(indito.dataset).forEach(function (kulcs) {
            if (kulcs.indexOf('sdhParam') === 0 && kulcs.length > 8) {
                inditoOpciok = inditoOpciok || {};
                inditoOpciok.parameterek = inditoOpciok.parameterek || {};
                inditoOpciok.parameterek[kulcs.charAt(8).toLowerCase() + kulcs.slice(9)] = indito.dataset[kulcs];
            }
        });

        nyit(indito.dataset.sdhUrlap, indito.dataset.sdhId || '0', inditoOpciok || undefined);
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
        // A kicsinyített (zoomolt) popupban a mért képpontokat vissza kell osztani.
        var iszZoom = szulo === document.body ? 1 : (parseFloat(window.getComputedStyle(szulo).zoom) || 1);

        iszLista.style.left = Math.round((hely.left - szuloHely.left) / iszZoom) + 'px';
        iszLista.style.top = Math.round((hely.bottom - szuloHely.top) / iszZoom + 2) + 'px';
        iszLista.style.minWidth = Math.max(240, Math.round(hely.width / iszZoom)) + 'px';
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

    var szolgTobb = false;     // 0.99.4: több szolgáltatás van, mint amennyit a választó helyben tart
    var szolgSzIdo = null;
    var szolgSzSzam = 0;

    function szolgSorObjektum(s) {
        return {
            id: s[0], nev: s[1], me: s[2], ar: s[3], afa: s[4], db: s[5], kat: s[6] || '', kod: s[7] || '',
            max: s[8] || 0, dij: s[9] === 1, nk: iszNorm(s[1]), kulcs: szolgKulcs(s[1])
        };
    }

    /** 0.99.4: keresés a szerveren a teljes szolgáltatás-törzsben (ha a helyi lista nem a teljes törzs). */
    function szolgSzerverKeres() {
        window.clearTimeout(szolgSzIdo);

        if (!szolgTobb || !szolgVal || iszNorm(szolgVal.kerdes).length < 2) {
            return;
        }

        var allapot = szolgVal;
        var kerdes = allapot.kerdes;
        var sz = ++szolgSzSzam;

        szolgSzIdo = window.setTimeout(function () {
            var cim = new URL(beallitas.ajax, window.location.origin);
            cim.searchParams.set('action', 'sdh_muhely_szolgaltatasok');
            cim.searchParams.set('_wpnonce', beallitas.nonce || '');
            cim.searchParams.set('q', kerdes);

            fetch(cim.toString(), { credentials: 'same-origin' })
                .then(function (v) { return v.json(); })
                .then(function (v) {
                    if (sz !== szolgSzSzam || szolgVal !== allapot || allapot.kerdes !== kerdes || !v || !v.success || !szolgValNyitva()) {
                        return;
                    }

                    var mar = {};
                    var egyutt = (szolgAdat || []).slice();

                    egyutt.forEach(function (t) { mar[t.id] = true; });
                    v.data.sorok.map(szolgSorObjektum).forEach(function (t) {
                        if (!mar[t.id]) {
                            egyutt.push(t);
                        }
                    });

                    allapot.talalatok = szolgKeres(egyutt, kerdes);
                    szolgValRajzol();
                })
                .catch(function () { /* a helyi találatok maradnak */ });
        }, 220);
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

                var adat = valasz.data.sorok.map(szolgSorObjektum);

                szolgTobb = !!valasz.data.tobb;

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
            szolgSzerverKeres();
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
            szolgSzerverKeres();
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
    var termTobb = false;      // 0.99.4: több termék van, mint amennyit a választó helyben tart – a szerver is keres
    var termSzIdo = null;
    var termSzSzam = 0;

    /**
     * 0.99.4: keresés a szerveren a teljes törzsben (ha a helyben tartott lista nem a teljes törzs). A helyi és a
     * szerver találatai együtt, a szokásos sorrendben (pontos kód, a névvel kezdődők, a többi).
     */
    function termSzerverKeres() {
        window.clearTimeout(termSzIdo);

        if (!termTobb || !termVal || iszNorm(termVal.kerdes).length < 2) {
            return;
        }

        var allapot = termVal;
        var kerdes = allapot.kerdes;
        var sz = ++termSzSzam;

        termSzIdo = window.setTimeout(function () {
            var cim = new URL(beallitas.ajax, window.location.origin);
            cim.searchParams.set('action', 'sdh_muhely_termekek');
            cim.searchParams.set('_wpnonce', beallitas.nonce || '');
            cim.searchParams.set('q', kerdes);

            fetch(cim.toString(), { credentials: 'same-origin' })
                .then(function (v) { return v.json(); })
                .then(function (v) {
                    if (sz !== termSzSzam || termVal !== allapot || allapot.kerdes !== kerdes || !v || !v.success || !termValNyitva()) {
                        return;
                    }

                    var mar = {};
                    var egyutt = (termAdat || []).slice();

                    egyutt.forEach(function (t) { mar[t.id] = true; });
                    v.data.sorok.map(termObjektum).forEach(function (t) {
                        if (!mar[t.id]) {
                            egyutt.push(t);
                        }
                    });

                    allapot.talalatok = termKeres(egyutt, kerdes);
                    termValRajzol();
                })
                .catch(function () { /* a helyi találatok maradnak */ });
        }, 220);
    }

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

                termTobb = !!valasz.data.tobb;

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
            termSzerverKeres();
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
            termSzerverKeres();

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
     * A munkalap láblécének számlagombja ([data-sdh-szamla="fo|helyi"]):
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
        // fo = Számlázz.hu számla; helyi = helyben készülő nyomtatvány (nem számla).
        var sorozat = kert === 'helyi' ? 'helyi' : 'fo';
        var gombok = urlap.querySelectorAll('[data-sdh-szamla]');
        var felirat = gomb.textContent;

        if (!urlap.reportValidity || !urlap.reportValidity()) {
            return;
        }

        // Automatikus mentésnél, ha minden mentve van: a számla rögtön nyílik (nincs mentés, nincs újratöltés).
        var szint = szintek[n];
        var auto = szint && szint.auto;

        if (auto && !auto.fut && urlapKep(urlap) === auto.kep) {
            window.clearTimeout(auto.ido);
            szamlaNyit(n, id, sorozat);

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

                // Előbb a számla nyílik; a munkalap a háttérben, „Betöltés…" nélkül frissül.
                Array.prototype.forEach.call(gombok, function (g) { g.disabled = false; });
                gomb.textContent = felirat;

                if (szint && szint.auto) {
                    szint.auto.kep = urlapKep(urlap);
                }

                szamlaNyit(n, id, sorozat);
                csendesFrissit(n);
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

                // A munkalap a helyén frissül (a kiállított számla jelölése, a tételek számlaszáma).
                if (szintek[n] && szintek[n].dialog.open && szintek[n].modul === 'munkalapok') {
                    csendesFrissit(n);
                } else {
                    nyit('munkalapok', munkalapId, { szint: n });
                }
                // A pénztár (penztar.js) beírja / felajánlja a számla pénzét.
                document.dispatchEvent(new CustomEvent('sdh:szamla-kesz', {
                    detail: { munkalapId: munkalapId, sorozat: sorozat, adat: adat || {} }
                }));
            }
        });
    }

    /**
     * Több eszközös lap számlája: az eszközválasztó csak a kiválasztott eszköz
     * tételeit hagyja kipipálva (így készülékenként külön számla készíthető),
     * a megjegyzésbe az az egy eszköz kerül, a helyi nyomtatvány pedig saját
     * sorszámot kap. „Minden eszköz együtt": minden (még nem számlázott) tétel.
     * A pipák utána kézzel is átállíthatók – a szerver azt számlázza, ami ki van pipálva.
     */
    function szamlaEszkozValt(radio) {
        var urlap = radio.closest('form');
        var doboz = radio.closest('[data-sdh-szamla-eszkozok]');

        if (!urlap || !doboz) {
            return;
        }

        var mind = radio.value === 'mind';

        Array.prototype.forEach.call(urlap.querySelectorAll('[data-sdh-szamla-tetel]'), function (pipa) {
            var szamlazva = pipa.getAttribute('data-szamlazva') === '1';

            pipa.checked = !szamlazva && !pipa.closest('[data-sdh-ub-ki]') && (mind || pipa.getAttribute('data-eszkoz') === radio.value);
        });

        // A megjegyzés csak akkor cserélődik, ha még a gép által kitöltött szöveg áll benne.
        var megj = urlap.querySelector('input[name="megjegyzes"]');
        var ujMegj = mind ? doboz.getAttribute('data-megj-mind') : radio.getAttribute('data-megj');

        if (megj && ujMegj !== null) {
            var gepi = [doboz.getAttribute('data-megj-mind')];

            Array.prototype.forEach.call(doboz.querySelectorAll('[data-sdh-szamla-eszkoz][data-megj]'), function (r) {
                gepi.push(r.getAttribute('data-megj'));
            });

            if (gepi.indexOf(megj.value) !== -1) {
                megj.value = ujMegj;
            }
        }

        var nyomtat = urlap.querySelector('[data-sdh-szamla-nyomtat]');
        var fajl = mind ? doboz.getAttribute('data-fajl-mind') : radio.getAttribute('data-fajl');

        if (nyomtat && fajl) {
            nyomtat.setAttribute('data-fajlnev', fajl);

            // A cím sorszáma is kövesse: az eszköz nyomtatványa saját számot visel.
            var modal = urlap.closest('.sdh-modal__torzs') || urlap.parentNode;
            var sorszam = modal ? modal.querySelector('[data-sdh-szamla-sorszam]') : null;

            if (sorszam) {
                sorszam.textContent = fajl.replace(/\.pdf$/, '');
            }
        }

        szamlaOsszeg(urlap);
    }

    document.addEventListener('change', function (esemeny) {
        var radio = esemeny.target.closest ? esemeny.target.closest('[data-sdh-szamla-eszkoz]') : null;

        if (radio && radio.checked) {
            szamlaEszkozValt(radio);
        }
    });

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
            // A fogaskerékkel elrejtett munkalap-tétel (0.63.0) nem számít: se sornak, se összegnek.
            if (pipa.closest('[data-sdh-ub-ki]')) {
                pipa.checked = false;

                return;
            }

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

        // Bevizsgálási díj (0.68.2): csak a díjlista (a Szolgáltatások között „bevizsgálási díj”-ként
        // jelöltek); a sor neve a készülékkel együtt, az ablakban választott eszközé (ennek híján a fő eszközé).
        if (fajta === 'bevizsgalas') {
            var nevek = {};

            try {
                nevek = JSON.parse(gomb.getAttribute('data-sdh-dijnevek') || '{}') || {};
            } catch (x) {
                nevek = {};
            }

            var dijNev = function (dij) {
                var valasztott = urlap.querySelector('input[name="eszkoz"]:checked');
                var kulcs = valasztott && valasztott.value !== 'mind' ? valasztott.value : '0';
                var minta = nevek[kulcs] || nevek['0'] || 'Bevizsgálási díj';
                var nev = minta.replace(/\{dij\}/g, dij || '').replace(/\s+/g, ' ').trim();

                return nev ? nev.charAt(0).toUpperCase() + nev.slice(1) : 'Bevizsgálási díj';
            };

            szValNyit(gomb, {
                cim: 'Bevizsgálási díj a számlára',
                alcim: 'Kattints a díjra, vagy ↑ ↓ és Enter. A tétel neve a készülékkel együtt kerül a számlára.',
                helyorzo: 'Díj neve…',
                egyseg: 'díj',
                oszlopok: [['Díj', ''], ['Bruttó ár', '8rem', true]],
                forras: function (q) {
                    return szolgBetolt().then(function (adat) {
                        return szolgKeres(adat.filter(function (t) { return t.dij; }), q);
                    });
                },
                cellak: function (t) {
                    return [szovegBiztonsagos(t.nev) + (t.kat ? ' <span class="sdh-termval__kat">' + szovegBiztonsagos(t.kat) + '</span>' : ''), t.ar > 0 ? mlPenz(t.ar) + ' Ft' : '—'];
                },
                valaszt: function (t) {
                    var sor = szamlaSorUj(urlap, { tipus: 'szolgaltatas', megnevezes: dijNev(t.nev), me: t.me || 'db', brutto_ar: t.ar, afa_kulcs: t.afa });
                    var ar = sor ? szamlaUjMezo(sor, 'brutto_ar') : null;

                    if (ar) {
                        ar.focus();
                        ar.select();
                    }
                },
                kezi: function () { kezi('szolgaltatas', dijNev('')); }
            });

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
                '            title="' + szovegBiztonsagos(o.keziCimke ? o.keziCimke : 'Nem a listából: kézzel írod be') + '">' +
                szovegBiztonsagos(o.keziCimke || 'Kézzel írom be') + '</button>' : '') +
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
                        (szVal.toltve ? (o.ures && szVal.kerdes === '' ? o.ures : 'Nincs találat.' + (o.kezi ? (o.keziCimke ? ' Használd a(z) „' + szovegBiztonsagos(o.keziCimke) + '" gombot.' : ' A „Kézzel írom be" gombbal így is felveheted.') : '')) : 'Keresés…') + '</td></tr>'
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
            var rejtett = li.querySelector('input[data-sdh-csat-torol-mezo]') || li.querySelector('input[name="torol_csatolmany[]"]');
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

    /**
     * A számmező szélessége a beírt szám hosszát követi, de legalább 5
     * számjegynyi (0.62.1). ch egységben: a betűmérettel együtt nő.
     */
    var SZAM_MIN_JEGY = 5;

    function szamSzelesseg(input) {
        var szoveg = String(input.value || input.placeholder || '');
        var jegy = Math.max(SZAM_MIN_JEGY, szoveg.length);

        // Tizedesjel / mínuszjel keskenyebb egy számjegynél.
        var keskeny = (szoveg.match(/[.,\-]/g) || []).length * 0.55;

        input.style.width = (jegy - (szoveg.length > SZAM_MIN_JEGY ? keskeny : 0) + 0.6).toFixed(2) + 'ch';
    }

    /**
     * A kódból írt érték (input.value = …) nem küld eseményt: a mező saját
     * value-tulajdonsága ezért a szélességet is igazítja.
     */
    function szamErtekFigyel(input, doboz) {
        var leiro = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

        if (!leiro || !leiro.set || Object.prototype.hasOwnProperty.call(input, 'value')) {
            return;
        }

        try {
            Object.defineProperty(input, 'value', {
                configurable: true,
                get: function () {
                    return leiro.get.call(this);
                },
                set: function (v) {
                    leiro.set.call(this, v);
                    szamSzelesseg(this);
                    szamHatar(this, doboz);
                }
            });
        } catch (e) { /* régi böngésző: csak eseményre igazodik */ }
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

        // Ahol saját, nagyobb léptetőgombok állnak a mező mellett (pl. a címke
        // példányszáma), ott nem kérünk a kis nyilakból: a data-sdh-szam-nincs
        // jelzi, hogy ezt a mezőt hagyjuk békén.
        if (input.hasAttribute('data-sdh-szam-nincs')) {
            input.dataset.sdhSzamKesz = '1';

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
            szamSzelesseg(input);
        });

        input.addEventListener('change', function () {
            szamSzelesseg(input);
        });

        // A doboz bármely pontjára kattintva a mezőbe kerül a kurzor.
        doboz.addEventListener('mousedown', function (esemeny) {
            if (esemeny.target === doboz || esemeny.target.classList.contains('sdh-szam__utotag')) {
                esemeny.preventDefault();
                input.focus();
            }
        });

        szamErtekFigyel(input, doboz);
        szamSzelesseg(input);
        szamHatar(input, doboz);
    }

    function szamKeres(gyoker) {
        Array.prototype.forEach.call(
            (gyoker || document).querySelectorAll('input[type="number"]'),
            szamBekot
        );
    }

    /* ---------------------------------------------------------------- */
    /* Másoló gomb: bármely adat a vágólapra (data-sdh-masol)            */
    /* ---------------------------------------------------------------- */

    /**
     * Szöveg a vágólapra. A böngésző vágólap-API-ja csak biztonságos
     * (https vagy localhost) oldalon él; a belső hálózati, http-s címen ezért
     * a régi, kijelölésre épülő út megy. A segédmezőnek a nyitott popupon
     * belül kell lennie, különben a popup mögött nem kaphat fókuszt.
     */
    function vagolapra(szoveg, horgony) {
        if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(szoveg).catch(function () {
                return vagolapraRegi(szoveg, horgony);
            });
        }

        return vagolapraRegi(szoveg, horgony);
    }

    function vagolapraRegi(szoveg, horgony) {
        return new Promise(function (kesz, hiba) {
            var tarolo = (horgony && horgony.closest && horgony.closest('dialog[open]')) || document.body;
            var mezo = document.createElement('textarea');
            var siker = false;

            mezo.value = szoveg;
            mezo.setAttribute('readonly', '');
            mezo.className = 'sdh-masol__seged';
            tarolo.appendChild(mezo);
            mezo.select();

            try {
                siker = document.execCommand('copy');
            } catch (e) {
                siker = false;
            }

            mezo.remove();

            if (horgony && horgony.focus) {
                horgony.focus({ preventScroll: true });
            }

            if (siker) {
                kesz();
            } else {
                hiba(new Error('A böngésző nem engedte a másolást.'));
            }
        });
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-masol]') : null;

        if (!gomb) {
            return;
        }

        esemeny.preventDefault();

        var eredeti = gomb.getAttribute('data-sdh-masol-cim') || gomb.title || 'Másolás';

        gomb.setAttribute('data-sdh-masol-cim', eredeti);

        vagolapra(gomb.getAttribute('data-sdh-masol') || '', gomb)
            .then(function () {
                gomb.classList.remove('is-hiba');
                gomb.classList.add('is-kesz');
                gomb.title = 'Kimásolva';
            })
            .catch(function () {
                gomb.classList.add('is-hiba');
                gomb.title = 'Nem sikerült kimásolni – jelöld ki, és Ctrl+C';
            })
            .then(function () {
                window.clearTimeout(gomb.sdhIdo);
                gomb.sdhIdo = window.setTimeout(function () {
                    gomb.classList.remove('is-kesz', 'is-hiba');
                    gomb.title = eredeti;
                }, 1600);
            });
    });

    /* ---------------------------------------------------------------- */
    /* Áttekintés: a modulcsempék elrejtése / megjelenítése             */
    /* ---------------------------------------------------------------- */

    function csempekAllapot() {
        var csempek = document.querySelector('[data-sdh-csempek]');
        var gomb = document.querySelector('[data-sdh-csempek-valt]');

        if (csempek && gomb) {
            // A gombon ikon is van: csak a felirat cserélődik.
            var felirat = gomb.querySelector('.sdh-gomb__felirat') || gomb;

            felirat.textContent = csempek.hidden ? 'Csempék megjelenítése' : 'Csempék elrejtése';
            gomb.setAttribute('aria-expanded', csempek.hidden ? 'false' : 'true');
            gomb.classList.toggle('is-aktiv', !csempek.hidden);
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

    /*
     * A csempék számai (nyitott munkalap, ügyfél, eszköz, kassza) mentés és
     * állapotváltás után maguktól frissülnek – nem kell újratölteni az oldalt.
     */
    var csempeIdo = 0;

    function csempekFrissit() {
        if (!document.querySelector('[data-sdh-csempek] [data-sdh-kartya]')) {
            return;
        }

        window.clearTimeout(csempeIdo);
        csempeIdo = window.setTimeout(function () {
            var cim = new URL(beallitas.ajax, window.location.origin);

            cim.searchParams.set('action', 'sdh_muhely_csempe_szamok');
            cim.searchParams.set('_wpnonce', beallitas.nonce || '');

            fetch(cim.toString(), { credentials: 'same-origin' })
                .then(function (valasz) { return valasz.json(); })
                .then(function (valasz) {
                    var szamok = (valasz && valasz.success && valasz.data) || {};

                    Object.keys(szamok).forEach(function (kulcs) {
                        var szam = document.querySelector('[data-sdh-kartya="' + kulcs + '"] .sdh-kartya__szam');

                        if (szam && szam.textContent !== szamok[kulcs]) {
                            szam.textContent = szamok[kulcs];
                        }
                    });
                })
                .catch(function () { /* a csempe csak tájékoztat: marad a régi szám */ });
        }, 250);
    }

    document.addEventListener('sdh:allapot', csempekFrissit);
    document.addEventListener('sdh:mentve', csempekFrissit);

    /* ---------------------------------------------------------------- */
    /* Áttekintés: óra és a zárásig hátralévő idő                       */
    /* ---------------------------------------------------------------- */

    var ORA_NAPOK = ['', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap'];
    var ORA_HONAPOK = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];

    function oraKet(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function oraPerc(ido) {
        var r = String(ido || '').split(':');

        return (parseInt(r[0], 10) || 0) * 60 + (parseInt(r[1], 10) || 0);
    }

    /** „2 óra 14 perc", „14 perc", „kevesebb mint 1 perc". */
    function oraTartam(perc) {
        if (perc < 1) {
            return 'kevesebb mint 1 perc';
        }

        var ora = Math.floor(perc / 60);
        var p = perc % 60;

        return ((ora > 0 ? ora + ' óra ' : '') + (p > 0 || ora === 0 ? p + ' perc' : '')).trim();
    }

    /**
     * Hol tart a nap: ugyanaz a számítás, mint a szerveren
     * (SDH_Muhely_Nyitvatartas::allapot). `d` a szerver helyi ideje, UTC-ként tárolva.
     */
    function oraAllapot(d, het, figy) {
        var nap = d.getUTCDay() === 0 ? 7 : d.getUTCDay();
        var mp = d.getUTCHours() * 3600 + d.getUTCMinutes() * 60 + d.getUTCSeconds();
        var ma = het[String(nap)] || null;

        if (!Object.keys(het).length) {
            return { allapot: 'nincs', szoveg: '', arany: 0 };
        }

        if (ma) {
            var nyit = oraPerc(ma[0]) * 60;
            var zar = oraPerc(ma[1]) * 60;

            if (mp < nyit) {
                return { allapot: 'elott', szoveg: 'Nyitásig ' + oraTartam(Math.ceil((nyit - mp) / 60)), arany: 0 };
            }

            if (mp < zar) {
                var perc = Math.ceil((zar - mp) / 60);

                return {
                    allapot: figy > 0 && perc <= figy ? 'hamar' : 'nyitva',
                    szoveg: 'Zárásig ' + oraTartam(perc),
                    arany: (mp - nyit) / Math.max(1, zar - nyit)
                };
            }
        }

        for (var i = 1; i <= 7; i++) {
            var kov = ((nap - 1 + i) % 7) + 1;

            if (het[String(kov)]) {
                return {
                    allapot: 'zarva',
                    szoveg: (ma ? 'Zárva' : 'Ma zárva') + ' · nyitás: ' + (i === 1 ? 'holnap' : ORA_NAPOK[kov]) + ' ' + het[String(kov)][0],
                    arany: 0
                };
            }
        }

        return { allapot: 'zarva', szoveg: 'Zárva', arany: 0 };
    }

    function oraIndul() {
        var doboz = document.querySelector('[data-sdh-ora]');

        if (!doboz) {
            return;
        }

        // A szerver helyi ideje (a rendszer időzónájában), UTC-ként értelmezve: a
        // böngésző órája és időzónája nem számít, csak az eltelt időt adja hozzá.
        var r = String(doboz.getAttribute('data-most') || '').match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/);

        if (!r) {
            return;
        }

        var szerver0 = Date.UTC(+r[1], +r[2] - 1, +r[3], +r[4], +r[5], +r[6]);
        var gep0 = Date.now();
        var het = {};
        var figy = parseInt(doboz.getAttribute('data-figy') || '0', 10) || 0;

        try {
            het = JSON.parse(doboz.getAttribute('data-het') || '{}') || {};
        } catch (hiba) {
            het = {};
        }

        var elIdo = doboz.querySelector('[data-sdh-ora-ido]');
        var elMp = doboz.querySelector('[data-sdh-ora-mp]');
        var elDatum = doboz.querySelector('[data-sdh-ora-datum]');
        var elZaras = doboz.querySelector('[data-sdh-ora-zaras]');

        function leptet() {
            var d = new Date(szerver0 + (Date.now() - gep0));
            var a = oraAllapot(d, het, figy);
            var nap = d.getUTCDay() === 0 ? 7 : d.getUTCDay();

            if (elIdo) {
                elIdo.textContent = oraKet(d.getUTCHours()) + ':' + oraKet(d.getUTCMinutes());
            }

            if (elMp) {
                elMp.textContent = ':' + oraKet(d.getUTCSeconds());
            }

            if (elDatum) {
                elDatum.textContent = ORA_HONAPOK[d.getUTCMonth()] + ' ' + d.getUTCDate() + '., ' + ORA_NAPOK[nap];
            }

            if (elZaras && a.allapot !== 'nincs' && elZaras.textContent !== a.szoveg) {
                elZaras.textContent = a.szoveg;
            }

            // A gyűrű (CSS: --sdh-ora-arany) a nyitvatartási nap eltelt részét mutatja.
            doboz.style.setProperty('--sdh-ora-arany', String(Math.round(a.arany * 10000) / 10000));

            if (doboz.getAttribute('data-allapot') !== a.allapot) {
                doboz.setAttribute('data-allapot', a.allapot);
            }
        }

        leptet();
        // A másodperc elejéhez igazítva lép, hogy ne „ugorjon" a kijelzés.
        window.setTimeout(function ciklus() {
            leptet();
            window.setTimeout(ciklus, 1000 - ((szerver0 + (Date.now() - gep0)) % 1000) + 5);
        }, 1000 - ((szerver0 + (Date.now() - gep0)) % 1000) + 5);
    }

    oraIndul();

    // Más fájlok (levelezes.js) ezeken keresztül nyitnak popupot és választót.
    /**
     * Számla ablaka kívülről (a lezáráskor felugró nyomtatási ajánlatból): ha a munkalap nyitva
     * van, fölé nyílik, és utána a munkalap a helyén frissül; ha nincs, önállóan nyílik.
     */
    function szamlaKivulrol(munkalapId, sorozat) {
        var n = -1;

        szintek.forEach(function (sz, i) {
            if (sz && sz.dialog.open && sz.modul === 'munkalapok' && String(sz.id) === String(munkalapId)) {
                n = i;
            }
        });

        if (n >= 0) {
            szamlaNyit(n, munkalapId, sorozat);

            return;
        }

        nyit('szamla', munkalapId, {
            szint: 0,
            parameterek: { sorozat: sorozat },
            siker: function (adat) {
                document.dispatchEvent(new CustomEvent('sdh:szamla-kesz', {
                    detail: { munkalapId: munkalapId, sorozat: sorozat, adat: adat || {} }
                }));
            }
        });
    }

    window.SDH_MUHELY_APP = {
        szamla: szamlaKivulrol,
        // Új tétel a számla ablakában kívülről (emlek.js: Gyakori tételek).
        szamlaSor: function (urlap, adat) { return szamlaSorUj(urlap, adat); },
        szamlaUjMezo: szamlaUjMezo,
        nyit: nyit,
        bezar: bezar,
        sajatSzint: sajatSzint,
        // Ugyanaz a rekord újra, ugyanabban a szintben (a zárolás-doboz kód utáni megnyitása – utkozes.js).
        ujraNyit: function (elem) {
            var n = sajatSzint(elem);
            var sz = n >= 0 ? szintek[n] : null;

            if (sz && sz.modul) {
                nyit(sz.modul, sz.id, Object.assign({}, sz.opciok || {}, { szint: n }));
            }
        },
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

/* Javítási garancia (F1, 0.83.0): a munkalap Eszköz lapfülén a „Garanciális visszatérésként rögzítem”
   gomb az űrlap rejtett mezőjébe írja az eredeti lap azonosítóját (mentéskor kerül a lapra). */
(function () {
    'use strict';

    function frissit(gomb, urlap) {
        var mezo = urlap.querySelector('[data-sdh-garancia-elozo-mezo]');
        var sav = urlap.querySelector('[data-sdh-garancia-elozo-sav]');
        var id = gomb.getAttribute('data-sdh-garancia-elozo');
        var be = mezo && mezo.value === id;

        gomb.textContent = be ? 'Garanciális visszatérés ✓ (visszavonás)' : 'Garanciális visszatérésként rögzítem';
        gomb.classList.toggle('is-aktiv', be);

        if (sav) {
            sav.hidden = !(mezo && mezo.value !== '0' && mezo.value !== '');

            if (be) {
                sav.textContent = 'Garanciális visszatérés – az eredeti lap: ' + (gomb.getAttribute('data-sdh-garancia-felirat') || '');
            }
        }
    }

    document.addEventListener('click', function (e) {
        var gomb = e.target.closest && e.target.closest('[data-sdh-garancia-elozo]');

        if (!gomb) {
            return;
        }

        e.preventDefault();

        var urlap = gomb.closest('form');
        var mezo = urlap && urlap.querySelector('[data-sdh-garancia-elozo-mezo]');

        if (!mezo) {
            return;
        }

        mezo.value = mezo.value === gomb.getAttribute('data-sdh-garancia-elozo') ? '0' : gomb.getAttribute('data-sdh-garancia-elozo');
        mezo.dispatchEvent(new Event('change', { bubbles: true }));
        frissit(gomb, urlap);
    });

    // A lap a saját garanciájára nem lehet visszatérés: ott a gomb nem kell; a többinél az állapotot mutatja.
    function rendez(gyoker) {
        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-garancia-elozo]'), function (gomb) {
            var urlap = gomb.closest('form');
            var sajat = urlap && urlap.querySelector('input[name="id"]');

            if (sajat && sajat.value === gomb.getAttribute('data-sdh-garancia-elozo')) {
                gomb.hidden = true;

                return;
            }

            if (urlap) {
                frissit(gomb, urlap);
            }
        });
    }

    new MutationObserver(function (valtozasok) {
        valtozasok.forEach(function (v) {
            Array.prototype.forEach.call(v.addedNodes, function (n) {
                if (n.nodeType === 1 && (n.matches('[data-sdh-garancia-jel]') || n.querySelector('[data-sdh-garancia-jel]'))) {
                    rendez(n.parentNode || n);
                }
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });
}());

/* Online árajánlat (F2, 0.84.0): a munkalap „Online árajánlat” csoportjának gombjai. */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var gomb = e.target.closest && e.target.closest('[data-sdh-ajanlat-akcio]');

        if (!gomb) {
            return;
        }

        e.preventDefault();

        var doboz = gomb.closest('[data-sdh-ajanlat]');
        var B = window.SDH_MUHELY || {};
        var fd = new FormData();
        var uzenet = doboz.querySelector('[data-sdh-ajanlat-uzenet]');

        fd.set('action', 'sdh_muhely_ajanlat_' + gomb.getAttribute('data-sdh-ajanlat-akcio'));
        fd.set('id', doboz.getAttribute('data-sdh-ajanlat'));
        fd.set('_wpnonce', B.nonce || '');
        gomb.disabled = true;

        fetch(B.ajax || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                if (v && v.success && v.data && v.data.html) {
                    var uj = document.createElement('div');

                    uj.innerHTML = v.data.html;

                    if (uj.firstElementChild) {
                        doboz.replaceWith(uj.firstElementChild);
                    }

                    return;
                }

                gomb.disabled = false;

                if (uzenet) {
                    uzenet.textContent = (v && v.data && v.data.uzenet) || 'Nem sikerült.';
                }
            })
            .catch(function () {
                gomb.disabled = false;

                if (uzenet) {
                    uzenet.textContent = 'Nincs kapcsolat a szerverrel.';
                }
            });
    });
}());

/* Átvételi aláírás és fotók (F3, 0.85.0): aláíró vászon (ujj, toll, egér) a munkalap Átvétel lapfülén.
   A kész aláírás PNG-ként a rejtett mezőbe kerül; a lap mentésekor megy fel. */
(function () {
    'use strict';

    function meretez(vaszon) {
        var r = vaszon.getBoundingClientRect();
        var arany = window.devicePixelRatio || 1;

        if (r.width < 10 || (vaszon.width === Math.round(r.width * arany) && vaszon.height === Math.round(r.height * arany))) {
            return false;
        }

        vaszon.width = Math.round(r.width * arany);
        vaszon.height = Math.round(r.height * arany);

        var c = vaszon.getContext('2d');

        c.setTransform(arany, 0, 0, arany, 0, 0);
        c.lineWidth = 2.2;
        c.lineCap = 'round';
        c.lineJoin = 'round';
        c.strokeStyle = '#111';

        return true;
    }

    function bekot(vaszon) {
        if (vaszon.hasAttribute('data-sdh-kesz')) {
            return;
        }

        vaszon.setAttribute('data-sdh-kesz', '1');

        var doboz = vaszon.closest('[data-sdh-alairas]');
        var mezo = doboz.querySelector('[data-sdh-alairas-mezo]');
        var rajzol = false;
        var volt = false;
        var elozo = null;

        function pont(e) {
            var r = vaszon.getBoundingClientRect();

            return { x: e.clientX - r.left, y: e.clientY - r.top };
        }

        vaszon.addEventListener('pointerdown', function (e) {
            meretez(vaszon);
            rajzol = true;
            elozo = pont(e);
            vaszon.setPointerCapture(e.pointerId);

            var c = vaszon.getContext('2d');

            c.beginPath();
            c.arc(elozo.x, elozo.y, 1.1, 0, Math.PI * 2);
            c.fillStyle = '#111';
            c.fill();
            volt = true;
            e.preventDefault();
        });

        vaszon.addEventListener('pointermove', function (e) {
            if (!rajzol) {
                return;
            }

            var p = pont(e);
            var c = vaszon.getContext('2d');

            c.beginPath();
            c.moveTo(elozo.x, elozo.y);
            c.lineTo(p.x, p.y);
            c.stroke();
            elozo = p;
            e.preventDefault();
        });

        function vege() {
            if (!rajzol) {
                return;
            }

            rajzol = false;

            if (volt && mezo) {
                mezo.value = vaszon.toDataURL('image/png');
                mezo.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        vaszon.addEventListener('pointerup', vege);
        vaszon.addEventListener('pointercancel', vege);

        doboz.addEventListener('click', function (e) {
            if (e.target.closest('[data-sdh-alairas-torol]')) {
                e.preventDefault();
                vaszon.getContext('2d').clearRect(0, 0, vaszon.width, vaszon.height);
                volt = false;

                if (mezo) {
                    mezo.value = '';
                }
            }

            if (e.target.closest('[data-sdh-alairas-uj]')) {
                e.preventDefault();

                var meglevo = doboz.querySelector('[data-sdh-alairas-meglevo]');
                var pad = doboz.querySelector('[data-sdh-alairas-pad]');

                if (pad) {
                    pad.hidden = false;
                }

                if (meglevo) {
                    meglevo.hidden = true;
                }

                meretez(vaszon);
            }
        });

        if (window.ResizeObserver) {
            new ResizeObserver(function () { meretez(vaszon); }).observe(vaszon);
        }
    }

    function keres(gyoker) {
        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-alairas-vaszon]'), bekot);
    }

    new MutationObserver(function (v) {
        v.forEach(function (m) {
            Array.prototype.forEach.call(m.addedNodes, function (n) {
                if (n.nodeType === 1) {
                    keres(n.matches('[data-sdh-alairas-vaszon]') ? n.parentNode : n);
                }
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });

    keres(document);

    // „Fotó készítése”: hány kép van kiválasztva (a csatolt fájlok listájától függetlenül, a mentéssel megy fel).
    document.addEventListener('change', function (e) {
        if (!e.target.matches || !e.target.matches('[data-sdh-atvetel-kamera]')) {
            return;
        }

        var db = e.target.files ? e.target.files.length : 0;
        var hely = e.target.closest('.sdh-atvetel__resz').querySelector('[data-sdh-atvetel-kamera-db]');

        if (hely) {
            hely.textContent = db > 0 ? db + ' fotó kiválasztva – a mentéssel kerül fel.' : '';
        }
    });
}());

/* Raktár (F4, 0.86.0): a rendelési javaslat „Megérkezett” gombja – bevételezés a sor mennyiségével. */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var gomb = e.target.closest && e.target.closest('[data-sdh-raktar-bevet]');

        if (!gomb) {
            return;
        }

        e.preventDefault();

        var sor = gomb.closest('[data-sdh-raktar-sor]');
        var menny = sor ? sor.querySelector('[data-sdh-raktar-menny]') : null;
        var B = window.SDH_MUHELY || {};
        var fd = new FormData();

        fd.set('action', 'sdh_muhely_raktar_bevet');
        fd.set('_wpnonce', B.nonce || '');
        fd.set('termek', gomb.getAttribute('data-sdh-raktar-bevet'));
        fd.set('mennyiseg', menny ? menny.value : '0');
        gomb.disabled = true;

        fetch(B.ajax || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                if (v && v.success) {
                    var k = sor.querySelector('[data-sdh-raktar-keszlet]');

                    if (k) {
                        k.textContent = v.data.keszlet;
                        k.classList.remove('is-hiba');
                    }

                    gomb.textContent = 'Bevételezve ✓';
                    sor.classList.add('is-kesz');

                    return;
                }

                gomb.disabled = false;
                gomb.textContent = (v && v.data && v.data.uzenet) || 'Nem sikerült';
            })
            .catch(function () {
                gomb.disabled = false;
                gomb.textContent = 'Nincs kapcsolat';
            });
    });
}());

/* Elégedettségi kérdés (F7, 0.89.0): „Kérdés küldése most” a munkalap Elégedettség csoportjában. */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var gomb = e.target.closest && e.target.closest('[data-sdh-elegedettseg-kuld]');

        if (!gomb) {
            return;
        }

        e.preventDefault();

        var doboz = gomb.closest('[data-sdh-elegedettseg]');
        var B = window.SDH_MUHELY || {};
        var fd = new FormData();
        var uzenet = doboz.querySelector('[data-sdh-elegedettseg-uzenet]');

        fd.set('action', 'sdh_muhely_elegedettseg_kuld');
        fd.set('id', doboz.getAttribute('data-sdh-elegedettseg'));
        fd.set('_wpnonce', B.nonce || '');
        gomb.disabled = true;

        fetch(B.ajax || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                var html = v && v.data && v.data.html;

                if (html) {
                    var uj = document.createElement('div');

                    uj.innerHTML = html;

                    if (uj.firstElementChild) {
                        doboz.replaceWith(uj.firstElementChild);
                        doboz = uj.firstElementChild;
                    }
                } else {
                    gomb.disabled = false;
                }

                var u = doboz.querySelector('[data-sdh-elegedettseg-uzenet]') || uzenet;

                if (u) {
                    u.textContent = (v && v.data && v.data.uzenet) || 'Nem sikerült.';
                }
            })
            .catch(function () {
                gomb.disabled = false;

                if (uzenet) {
                    uzenet.textContent = 'Nincs kapcsolat a szerverrel.';
                }
            });
    });
}());

/* Adatbázis-mérés (H10, 0.90.0): Beállítások › Sebesség – csak fejlesztőnek. */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var gomb = e.target.closest && e.target.closest('[data-sdh-meres-indit]');

        if (!gomb) {
            return;
        }

        e.preventDefault();

        var doboz = gomb.closest('[data-sdh-meres]');
        var B = window.SDH_MUHELY || {};
        var allapot = doboz.querySelector('[data-sdh-meres-allapot]');
        var ki = doboz.querySelector('[data-sdh-meres-ki]');
        var fd = new FormData();

        fd.set('action', 'sdh_muhely_meres');
        fd.set('_wpnonce', doboz.getAttribute('data-nonce') || '');
        gomb.disabled = true;
        allapot.textContent = 'Mérés folyamatban…';

        fetch(B.ajax || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                gomb.disabled = false;

                if (v && v.success) {
                    ki.textContent = v.data.szoveg;
                    ki.hidden = false;
                    allapot.textContent = v.data.lassu.length ? 'Lassú: ' + v.data.lassu.join(', ') : 'Minden lépés gyors.';

                    return;
                }

                allapot.textContent = (v && v.data && v.data.uzenet) || 'Nem sikerült.';
            })
            .catch(function () {
                gomb.disabled = false;
                allapot.textContent = 'Nincs kapcsolat a szerverrel.';
            });
    });
}());

/* Netkimaradás (H12, 0.91.0): „Nincs internet” jelzés a fejlécben a pulzus alapján. A helyi munka közben is megy. */
(function () {
    'use strict';

    var jel = null;

    document.addEventListener('sdh:pulzus', function (ev) {
        var net = ev.detail && ev.detail.net;

        if (!net) {
            return;
        }

        if (!jel) {
            var fej = document.querySelector('.sdh-fej');

            if (!fej) {
                return;
            }

            jel = document.createElement('span');
            jel.className = 'sdh-net';
            jel.setAttribute('role', 'status');
            jel.hidden = true;
            fej.appendChild(jel);
        }

        jel.hidden = !!net.online;
        jel.textContent = net.online ? '' : 'Nincs internet' + (net.ota ? ' (' + net.ota + ' óta)' : '') + ' – a helyi munka megy';
        jel.title = net.online ? '' : 'Ami internetet kér (számla, e-mail, AI, külső mentés), az most nem megy ki. Súgó › Hibaelhárítás › Ha nincs internet.';
    });
}());

/* N6 (0.92.0): fejlesztői fiók átállítása rendszer-adminisztrátorra – kétlépcsős gomb. */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var gomb = e.target.closest && e.target.closest('[data-sdh-atallit]');

        if (!gomb) {
            return;
        }

        e.preventDefault();

        if (!gomb.hasAttribute('data-elso')) {
            gomb.setAttribute('data-elso', '1');
            gomb.setAttribute('data-eredeti', gomb.textContent);
            gomb.textContent = 'Biztosan? Kattints újra';
            window.setTimeout(function () {
                if (gomb.hasAttribute('data-elso')) {
                    gomb.removeAttribute('data-elso');
                    gomb.textContent = gomb.getAttribute('data-eredeti');
                }
            }, 4000);

            return;
        }

        gomb.removeAttribute('data-elso');

        var doboz = gomb.closest('[data-sdh-atallas]');
        var uzenet = gomb.parentNode.querySelector('[data-sdh-atallit-uzenet]');
        var B = window.SDH_MUHELY || {};
        var fd = new FormData();

        fd.set('action', 'sdh_muhely_admin_atallit');
        fd.set('user', gomb.getAttribute('data-sdh-atallit'));
        fd.set('_wpnonce', doboz.getAttribute('data-nonce') || '');
        gomb.disabled = true;

        fetch(B.ajax || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                uzenet.textContent = (v && v.data && v.data.uzenet) || 'Nem sikerült.';

                if (v && v.success) {
                    gomb.hidden = true;
                } else {
                    gomb.disabled = false;
                    gomb.textContent = gomb.getAttribute('data-eredeti');
                }
            })
            .catch(function () {
                gomb.disabled = false;
                uzenet.textContent = 'Nincs kapcsolat a szerverrel.';
            });
    });
}());

/* Licenc és támogatási hozzáférés (N4, 0.93.0): Beállítások › Licenc. */
(function () {
    'use strict';

    function kuld(doboz, adat, kesz) {
        var B = window.SDH_MUHELY || {};
        var fd = new FormData();

        Object.keys(adat).forEach(function (k) { fd.set(k, adat[k]); });
        fd.set('_wpnonce', doboz.getAttribute('data-nonce') || '');

        fetch(B.ajax || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(kesz)
            .catch(function () { kesz({ success: false, data: { uzenet: 'Nincs kapcsolat a szerverrel.' } }); });
    }

    document.addEventListener('click', function (e) {
        var g = e.target.closest && e.target.closest('[data-sdh-licenc-ellenoriz],[data-sdh-tamogatas-indit],[data-sdh-tamogatas-visszavon]');

        if (!g) {
            return;
        }

        e.preventDefault();

        var doboz = g.closest('[data-sdh-licenc]');
        var uzenet = doboz.querySelector('[data-sdh-licenc-uzenet]');
        var adat;

        if (g.hasAttribute('data-sdh-tamogatas-visszavon') || g.hasAttribute('data-sdh-tamogatas-indit')) {
            // Kétlépcsős gomb: az első kattintás csak megerősítést kér.
            if (!g.hasAttribute('data-elso')) {
                g.setAttribute('data-elso', '1');
                g.setAttribute('data-eredeti', g.textContent);
                g.textContent = 'Biztosan? Kattints újra';
                window.setTimeout(function () {
                    if (g.hasAttribute('data-elso')) {
                        g.removeAttribute('data-elso');
                        g.textContent = g.getAttribute('data-eredeti');
                    }
                }, 4000);

                return;
            }

            g.removeAttribute('data-elso');
            g.textContent = g.getAttribute('data-eredeti');
        }

        if (g.hasAttribute('data-sdh-licenc-ellenoriz')) {
            adat = { action: 'sdh_muhely_licenc_ellenoriz' };
        } else if (g.hasAttribute('data-sdh-tamogatas-indit')) {
            adat = { action: 'sdh_muhely_tamogatas_indit', ora: (doboz.querySelector('[data-sdh-tamogatas-ora]') || {}).value || '24' };
        } else {
            adat = { action: 'sdh_muhely_tamogatas_visszavon', user: g.getAttribute('data-sdh-tamogatas-visszavon') };
        }

        g.disabled = true;

        if (uzenet) {
            uzenet.textContent = 'Folyamatban…';
        }

        kuld(doboz, adat, function (v) {
            g.disabled = false;

            if (uzenet) {
                uzenet.textContent = (v && v.data && v.data.uzenet) || (v && v.success ? 'Kész.' : 'Nem sikerült.');
            }

            var link = doboz.querySelector('[data-sdh-tamogatas-link]');

            if (v && v.success && v.data && v.data.link && link) {
                link.value = v.data.link;
                link.hidden = false;
                link.select();
            }

            if (v && v.success && g.hasAttribute('data-sdh-tamogatas-visszavon')) {
                var sor = g.closest('tr');

                if (sor) {
                    sor.remove();
                }
            }
        });
    });
}());

/* Adatátvétel (U1, 0.94.0): Beállítások › Adatátvétel – feltöltés, párosítás, próba, adagos átvétel, visszavonás. */
(function () {
    'use strict';

    var allapot = null;

    function el(cimke, szoveg) {
        var e = document.createElement(cimke);

        if (szoveg !== undefined) {
            e.textContent = szoveg;
        }

        return e;
    }

    function hivas(doboz, adat, kesz) {
        var B = window.SDH_MUHELY || {};
        var fd = adat instanceof FormData ? adat : new FormData();

        if (!(adat instanceof FormData)) {
            Object.keys(adat).forEach(function (k) { fd.set(k, adat[k]); });
        }

        fd.set('_wpnonce', doboz.getAttribute('data-nonce') || '');

        fetch(B.ajax || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(kesz)
            .catch(function () { kesz({ success: false, data: { uzenet: 'Nincs kapcsolat a szerverrel.' } }); });
    }

    function par(doboz) {
        var p = {};

        doboz.querySelectorAll('[data-sdh-atvetel-mezo]').forEach(function (s) {
            p[s.getAttribute('data-sdh-atvetel-mezo')] = parseInt(s.value, 10);
        });

        return JSON.stringify(p);
    }

    function kozos(doboz) {
        var t = doboz.querySelector('[data-sdh-atvetel-allapot-parok]');

        return { fajl: allapot.fajl, par: par(doboz), allapot_parok: t ? t.value : '' };
    }

    function parositas(doboz, d) {
        var tbody = doboz.querySelector('[data-sdh-atvetel-sorok]');

        while (tbody.firstChild) {
            tbody.removeChild(tbody.firstChild);
        }

        d.mezok.forEach(function (m) {
            var tr = el('tr');
            var sel = el('select');
            var minta = el('td');
            var nincs = el('option', '– nincs –');

            nincs.value = '-1';
            sel.appendChild(nincs);
            sel.setAttribute('data-sdh-atvetel-mezo', m.kulcs);

            d.fejlec.forEach(function (f, i) {
                var o = el('option', (i + 1) + '. ' + f);

                o.value = String(i);
                sel.appendChild(o);
            });

            sel.value = String(d.javaslat[m.kulcs] !== undefined ? d.javaslat[m.kulcs] : -1);

            function mintaFrissit() {
                var i = parseInt(sel.value, 10);

                minta.textContent = i >= 0 ? d.minta.map(function (s) { return s[i] || ''; }).filter(Boolean).slice(0, 3).join(' · ') : '';
            }

            sel.addEventListener('change', function () {
                mintaFrissit();
                doboz.querySelector('[data-sdh-atvetel-futtat]').disabled = true;
            });
            mintaFrissit();

            tr.appendChild(el('td', m.felirat + (m.kotelezo ? ' *' : '')));
            var td = el('td');
            td.appendChild(sel);
            tr.appendChild(td);
            tr.appendChild(minta);
            tbody.appendChild(tr);
        });

        doboz.querySelector('[data-sdh-atvetel-info]').textContent = d.nev + ': ' + d.sorok + ' sor, kódolás: ' + d.kodolas + ', elválasztó: ' + d.elvalaszto + '. A javasolt párosítást ellenőrizd; a *-os mező kötelező.';
        doboz.querySelector('[data-sdh-atvetel-allapot]').hidden = d.entitas !== 'munkalap';
        doboz.querySelector('[data-sdh-atvetel-allapot-kulcsok]').textContent = 'Az új állapotok kulcsai: ' + d.allapotok.join(', ');
        doboz.querySelector('[data-sdh-atvetel-parositas]').hidden = false;
        doboz.querySelector('[data-sdh-atvetel-futtat]').disabled = true;
        doboz.querySelector('[data-sdh-atvetel-jelentes]').hidden = true;
    }

    function jelentes(doboz, szoveg) {
        var j = doboz.querySelector('[data-sdh-atvetel-jelentes]');

        j.textContent = szoveg;
        j.hidden = false;
    }

    document.addEventListener('click', function (e) {
        var g = e.target.closest && e.target.closest('[data-sdh-atvetel-feltolt],[data-sdh-atvetel-proba],[data-sdh-atvetel-futtat],[data-sdh-atvetel-visszavon]');

        if (!g) {
            return;
        }

        e.preventDefault();

        var doboz = g.closest('[data-sdh-atvetel]');
        var uzenet = doboz.querySelector('[data-sdh-atvetel-uzenet]');

        if (g.hasAttribute('data-sdh-atvetel-feltolt')) {
            var f = doboz.querySelector('[data-sdh-atvetel-fajl]').files[0];

            if (!f) {
                uzenet.textContent = 'Válassz fájlt.';

                return;
            }

            var fd = new FormData();

            fd.set('action', 'sdh_muhely_atvetel_feltolt');
            fd.set('entitas', doboz.querySelector('[data-sdh-atvetel-entitas]').value);
            fd.set('fajl', f);
            g.disabled = true;
            uzenet.textContent = 'Feltöltés és elemzés…';

            hivas(doboz, fd, function (v) {
                g.disabled = false;

                if (!v || !v.success) {
                    uzenet.textContent = (v && v.data && v.data.uzenet) || 'Nem sikerült.';

                    return;
                }

                uzenet.textContent = '';
                allapot = { fajl: v.data.id, sorok: v.data.sorok };
                parositas(doboz, v.data);
            });

            return;
        }

        if (g.hasAttribute('data-sdh-atvetel-proba')) {
            g.disabled = true;
            jelentes(doboz, 'Próba folyamatban – a teljes fájlt végigolvassa, semmit nem ír…');

            hivas(doboz, Object.assign({ action: 'sdh_muhely_atvetel_proba' }, kozos(doboz)), function (v) {
                g.disabled = false;

                if (!v || !v.success) {
                    jelentes(doboz, (v && v.data && v.data.uzenet) || 'Nem sikerült.');

                    return;
                }

                var d = v.data;
                var sorok = ['Sorok: ' + d.sorok, 'Átvehető: ' + d.jo, 'Már bent van (kimarad): ' + d.meglevo, 'Hibás: ' + d.hibas];

                if (d.osszeg) {
                    sorok.push('Bruttó összesen (átvehető sorok): ' + Math.round(d.osszeg).toLocaleString('hu-HU') + ' Ft');
                }

                if (d.allapotok && Object.keys(d.allapotok).length) {
                    sorok.push('Állapotok: ' + Object.keys(d.allapotok).map(function (k) { return k + ' ' + d.allapotok[k]; }).join(', '));
                }

                if (d.hibak.length) {
                    sorok.push('', 'Hibák (az első 25):');
                    sorok = sorok.concat(d.hibak);
                }

                jelentes(doboz, sorok.join('\n'));
                doboz.querySelector('[data-sdh-atvetel-futtat]').disabled = d.jo === 0;
            });

            return;
        }

        if (g.hasAttribute('data-sdh-atvetel-futtat')) {
            if (!g.hasAttribute('data-elso')) {
                g.setAttribute('data-elso', '1');
                g.setAttribute('data-eredeti', g.textContent);
                g.textContent = 'Biztosan? Kattints újra';
                window.setTimeout(function () {
                    if (g.hasAttribute('data-elso')) {
                        g.removeAttribute('data-elso');
                        g.textContent = g.getAttribute('data-eredeti');
                    }
                }, 4000);

                return;
            }

            g.removeAttribute('data-elso');
            g.textContent = g.getAttribute('data-eredeti');
            g.disabled = true;

            var ossz = { uj: 0, meglevo: 0, hibas: 0, hibak: [] };
            var alap = kozos(doboz);

            (function kor(tol, imp) {
                hivas(doboz, Object.assign({ action: 'sdh_muhely_atvetel_futtat', tol: tol, import: imp || '' }, alap), function (v) {
                    if (!v || !v.success) {
                        jelentes(doboz, 'Megszakadt ' + tol + '. sornál: ' + ((v && v.data && v.data.uzenet) || 'hiba') + '. Az eddigiek bent vannak; a Visszavonás az Eddigi átvételek között.');
                        g.disabled = false;

                        return;
                    }

                    var d = v.data;

                    ossz.uj += d.uj;
                    ossz.meglevo += d.meglevo;
                    ossz.hibas += d.hibas;
                    ossz.hibak = ossz.hibak.concat(d.hibak).slice(0, 25);
                    jelentes(doboz, 'Átvétel: ' + d.tovabb + ' / ' + d.osszes + ' sor… (új: ' + ossz.uj + ', már bent: ' + ossz.meglevo + ', hibás: ' + ossz.hibas + ')');

                    if (!d.kesz) {
                        kor(d.tovabb, d.import);

                        return;
                    }

                    jelentes(doboz, ['Kész.', 'Új sor: ' + ossz.uj, 'Már bent volt: ' + ossz.meglevo, 'Hibás: ' + ossz.hibas].concat(ossz.hibak.length ? ['', 'Hibák:'].concat(ossz.hibak) : []).join('\n'));
                });
            }(0, ''));

            return;
        }

        // Visszavonás – kétlépcsős.
        if (!g.hasAttribute('data-elso')) {
            g.setAttribute('data-elso', '1');
            g.setAttribute('data-eredeti', g.textContent);
            g.textContent = 'Biztosan? Kattints újra';
            window.setTimeout(function () {
                if (g.hasAttribute('data-elso')) {
                    g.removeAttribute('data-elso');
                    g.textContent = g.getAttribute('data-eredeti');
                }
            }, 4000);

            return;
        }

        g.removeAttribute('data-elso');
        g.disabled = true;

        hivas(doboz, { action: 'sdh_muhely_atvetel_visszavon', import: g.getAttribute('data-sdh-atvetel-visszavon') }, function (v) {
            var td = g.parentNode;

            td.textContent = (v && v.data && v.data.uzenet) || 'Nem sikerült.';
        });
    });
}());

/* H5b (0.95.0): „Tételár átírása” jog nélkül a tételár / kedvezmény mezőbe billentyűzettel sem lehet írni. */
document.addEventListener('focusin', function (e) {
    'use strict';

    var t = e.target;

    if (document.body.classList.contains('sdh-nem-tetel_ar') && t && t.name && /^tetelek.*\[(brutto_ar|kedvezmeny)\]$/.test(t.name)) {
        t.readOnly = true;
        t.title = 'Az ár átírásához „Tételár átírása a lapon” jog kell.';
    }
});

/* Távoli karbantartás (0.99.0): a forgalmazó üzenete és támogatási kérése – sáv az adminisztrátornak. */
(function () {
    'use strict';

    function kuld(sav, adat, kesz) {
        var B = window.SDH_MUHELY || {};
        var fd = new FormData();

        Object.keys(adat).forEach(function (k) { fd.set(k, adat[k]); });
        fd.set('_wpnonce', sav.getAttribute('data-nonce') || '');

        fetch(B.ajax || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(kesz)
            .catch(function () { kesz({ success: false, data: { uzenet: 'Nincs kapcsolat a szerverrel.' } }); });
    }

    document.addEventListener('click', function (e) {
        var g = e.target.closest && e.target.closest('[data-sdh-tavoli-valasz],[data-sdh-tavoli-uzenet-le]');

        if (!g) {
            return;
        }

        e.preventDefault();

        var sav = g.closest('[data-sdh-tavoli]');
        var allapot = sav.querySelector('[data-sdh-tavoli-allapot]');

        if (g.hasAttribute('data-sdh-tavoli-uzenet-le')) {
            kuld(sav, { action: 'sdh_muhely_tavoli_uzenet_le' }, function () { sav.remove(); });

            return;
        }

        // Kétlépcsős gomb: az első kattintás csak megerősítést kér.
        if (!g.hasAttribute('data-elso')) {
            g.setAttribute('data-elso', '1');
            g.setAttribute('data-eredeti', g.textContent);
            g.textContent = 'Biztosan? Kattints újra';
            window.setTimeout(function () {
                if (g.hasAttribute('data-elso')) {
                    g.removeAttribute('data-elso');
                    g.textContent = g.getAttribute('data-eredeti');
                }
            }, 4000);

            return;
        }

        g.removeAttribute('data-elso');
        g.textContent = g.getAttribute('data-eredeti');
        sav.querySelectorAll('button').forEach(function (b) { b.disabled = true; });

        kuld(sav, { action: 'sdh_muhely_tavoli_valasz', engedi: g.getAttribute('data-sdh-tavoli-valasz') }, function (v) {
            var szoveg = (v && v.data && v.data.uzenet) || (v && v.success ? 'Kész.' : 'Nem sikerült.');

            if (v && v.success) {
                sav.textContent = szoveg;
            } else {
                sav.querySelectorAll('button').forEach(function (b) { b.disabled = false; });

                if (allapot) {
                    allapot.textContent = szoveg;
                }
            }
        });
    });
}());

/* =====================================================================
 * Keresés és listák (0.99.4) – Beállítások › Keresés és listák
 *  - azonnali (lenyíló) találatok minden keresőben, mint a fejlécben; ↑ ↓ Enter
 *  - oldalszám-ugró mező a számozott lapozóknál
 *  - dupla kattintás a lista során = megnyitás
 *  - az űrlapok „E-mail” gombja és a munkalap „Dokumentumok elküldése” gombja
 * ================================================================== */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};
    var O = B.okos || {};

    function be(kulcs) {
        return O[kulcs] === undefined ? true : !!O[kulcs];
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);

        return d.innerHTML;
    }

    function cim(akcio, parameterek) {
        var u = new URL(B.ajax || '/wp-admin/admin-ajax.php', window.location.origin);
        u.searchParams.set('action', akcio);
        u.searchParams.set('_wpnonce', B.nonce || '');
        u.searchParams.set('kontextus', B.kontextus || 'frontend');

        Object.keys(parameterek || {}).forEach(function (k) {
            u.searchParams.set(k, parameterek[k]);
        });

        return u.toString();
    }

    function postol(akcio, adat) {
        var fd = new FormData();
        fd.set('action', akcio);
        fd.set('_wpnonce', B.nonce || '');
        fd.set('kontextus', B.kontextus || 'frontend');

        Object.keys(adat || {}).forEach(function (k) {
            fd.set(k, adat[k]);
        });

        return fetch(B.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) { return v.text(); })
            .then(function (t) {
                try {
                    return JSON.parse(t);
                } catch (h) {
                    throw new Error('A szerver nem várt választ adott.');
                }
            });
    }

    /** Melyik modul oldalán vagyunk (a találatok közül ennek a csoportja kerül előre). */
    function modul() {
        var m = window.location.pathname.match(/\/muhely\/([a-z_-]+)/) ||
            window.location.search.match(/[?&]muhely=([a-z_-]+)/) ||
            window.location.search.match(/[?&]page=sdh-muhely-([a-z_-]+)/);

        return m ? m[1] : 'attekintes';
    }

    window.SDH_OKOS_MODUL = modul;

    /* ---------------------------------------------------------------- */
    /* Lenyíló találatok                                                */
    /* ---------------------------------------------------------------- */

    var lebego = null;     // a listák keresőinek közös lenyílója (a body-ban, a mező alá igazítva)
    var lebegoMezo = null;
    var idozito = null;
    var sorszam = 0;

    function lebegoLista() {
        if (!lebego) {
            lebego = document.createElement('ul');
            lebego.className = 'sdh-kutat__lista sdh-okos-lista';
            lebego.hidden = true;
            lebego.setAttribute('role', 'listbox');
            document.body.appendChild(lebego);
        }

        return lebego;
    }

    function listaja(mezo) {
        if (mezo.classList.contains('sdh-kutat__mezo')) {
            var doboz = mezo.closest('[data-sdh-kutat]');

            return doboz ? doboz.querySelector('.sdh-kutat__lista') : null;
        }

        return lebegoLista();
    }

    function elhelyez(mezo) {
        var r = mezo.getBoundingClientRect();
        var l = lebegoLista();
        var szel = Math.max(r.width, 340);

        l.style.position = 'fixed';
        l.style.left = Math.max(8, Math.min(r.left, window.innerWidth - szel - 8)) + 'px';
        l.style.top = (r.bottom + 5) + 'px';
        l.style.width = szel + 'px';
        l.style.right = 'auto';
        l.style.maxHeight = Math.max(160, window.innerHeight - r.bottom - 24) + 'px';
    }

    function elrejt() {
        if (lebego) {
            lebego.hidden = true;
            lebego.innerHTML = '';
        }

        lebegoMezo = null;
    }

    var TIPUS_NEV = {
        imei: 'IMEI-szám', telefon: 'telefonszám', szam: 'sorszám', email: 'e-mail cím',
        kod: 'kód / gyári szám', marka: 'márka', szoveg: ''
    };

    function rajzol(lista, valasz) {
        var csoportok = (valasz && valasz.csoportok) || [];
        var html = '';

        if (valasz && valasz.tipus && TIPUS_NEV[valasz.tipus]) {
            html += '<li class="sdh-okos-lista__tipus">Felismerve: ' + esc(TIPUS_NEV[valasz.tipus]) + '</li>';
        }

        csoportok.forEach(function (cs) {
            html += '<li class="sdh-kutat__csoport">' + esc(cs.cim) + '</li>';

            cs.talalatok.forEach(function (t) {
                html += '<li><a class="sdh-kutat__elem" role="option" href="' + esc(t.url) + '"' +
                    (t.urlap && t.id ? ' data-sdh-urlap="' + esc(t.urlap) + '" data-sdh-id="' + esc(t.id) + '"' : '') + '>' +
                    esc(t.cim) + (t.reszlet ? '<span>' + esc(t.reszlet) + '</span>' : '') + '</a></li>';
            });
        });

        if (!csoportok.length) {
            html += '<li class="sdh-kutat__ures">Nincs találat. Enterrel a listában keresel.</li>';
        }

        lista.innerHTML = html;
        lista.hidden = false;
    }

    function keres(mezo) {
        var q = mezo.value.trim();
        var lista = listaja(mezo);

        if (!lista) {
            return;
        }

        if (q.length < 2) {
            lista.hidden = true;
            lista.innerHTML = '';

            return;
        }

        var sz = ++sorszam;

        fetch(cim('sdh_muhely_okos_kereso', { q: q, elso: modul() }), { credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                if (sz !== sorszam || document.activeElement !== mezo || !v || !v.success) {
                    return;
                }

                if (lista === lebego) {
                    lebegoMezo = mezo;
                    elhelyez(mezo);
                }

                rajzol(lista, v.data);
            })
            .catch(function () { /* csendben: a lista keresője Enterre úgyis működik */ });
    }

    /** A lista-keresők, amelyek a fejléc keresőjéhez hasonlóan lenyílót kapnak. */
    function okosMezo(el) {
        if (!el || el.tagName !== 'INPUT' || !be('azonnali')) {
            return false;
        }

        return el.matches('form.sdh-kereso input[type="search"], .sdh-racs__kereso input[data-kereso], input[data-sdh-okos-kereso]');
    }

    document.addEventListener('input', function (ev) {
        var mezo = ev.target;

        if (!okosMezo(mezo)) {
            return;
        }

        window.clearTimeout(idozito);
        idozito = window.setTimeout(function () { keres(mezo); }, 220);
    });

    // ↑ ↓ Enter Esc – a fejléc keresőjében és a listák keresőiben is.
    document.addEventListener('keydown', function (ev) {
        var mezo = ev.target;

        if (!mezo || mezo.tagName !== 'INPUT' || !(okosMezo(mezo) || mezo.classList.contains('sdh-kutat__mezo'))) {
            return;
        }

        var lista = listaja(mezo);

        if (!lista || lista.hidden) {
            return;
        }

        var elemek = Array.prototype.slice.call(lista.querySelectorAll('.sdh-kutat__elem'));
        var most = elemek.indexOf(lista.querySelector('.sdh-kutat__elem.is-kijelolt'));

        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
            if (!elemek.length) {
                return;
            }

            ev.preventDefault();
            var uj = ev.key === 'ArrowDown' ? Math.min(elemek.length - 1, most + 1) : Math.max(0, most - 1);

            elemek.forEach(function (a, i) { a.classList.toggle('is-kijelolt', i === uj); });
            elemek[uj].scrollIntoView({ block: 'nearest' });

            return;
        }

        if (ev.key === 'Enter' && most >= 0) {
            ev.preventDefault();
            elemek[most].click();
            lista.hidden = true;

            return;
        }

        if (ev.key === 'Enter' || ev.key === 'Escape') {
            lista.hidden = true;
        }
    });

    document.addEventListener('click', function (ev) {
        if (ev.target.closest && ev.target.closest('.sdh-kutat__elem')) {
            window.setTimeout(function () {
                elrejt();
                Array.prototype.forEach.call(document.querySelectorAll('.sdh-kutat__lista'), function (l) { l.hidden = true; });
            }, 0);

            return;
        }

        if (lebego && !lebego.hidden && ev.target !== lebegoMezo && !(ev.target.closest && ev.target.closest('.sdh-okos-lista'))) {
            elrejt();
        }
    });

    window.addEventListener('resize', function () {
        if (lebegoMezo && lebego && !lebego.hidden) {
            elhelyez(lebegoMezo);
        }
    });

    window.addEventListener('scroll', function () {
        if (lebegoMezo && lebego && !lebego.hidden) {
            elhelyez(lebegoMezo);
        }
    }, true);

    /* ---------------------------------------------------------------- */
    /* Oldalszám-ugró a számozott lapozóknál                            */
    /* ---------------------------------------------------------------- */

    function lapozoBovit(lapozo) {
        if (lapozo.hasAttribute('data-sdh-ugro-kesz')) {
            return;
        }

        lapozo.setAttribute('data-sdh-ugro-kesz', '1');

        var max = 0;
        var minta = null;
        var param = '';

        Array.prototype.forEach.call(lapozo.querySelectorAll('a, span'), function (el) {
            var n = parseInt((el.textContent || '').trim(), 10);

            if (!isNaN(n) && String(n) === (el.textContent || '').trim()) {
                max = Math.max(max, n);

                if (!minta && el.tagName === 'A' && el.getAttribute('href') && el.getAttribute('href') !== '#') {
                    try {
                        var u = new URL(el.href, window.location.href);

                        u.searchParams.forEach(function (ertek, nev) {
                            if (!param && ertek === String(n)) {
                                param = nev;
                            }
                        });

                        if (param) {
                            minta = u;
                        }
                    } catch (h) { /* nem URL */ }
                }
            }
        });

        if (!minta || max < 2) {
            return;
        }

        var doboz = document.createElement('span');
        doboz.className = 'sdh-lapozo__ugro';
        doboz.innerHTML = '<input type="text" inputmode="numeric" autocomplete="off" class="sdh-lapozo__mezo" placeholder="Oldal"' +
            ' aria-label="Ugrás erre az oldalra (1–' + max + ')" title="Írd be az oldalszámot, és nyomj Entert (1–' + max + ')">' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-lapozo__ugrik">Ugrás</button>';
        lapozo.appendChild(doboz);

        var mezo = doboz.querySelector('input');

        function ugrik() {
            var n = parseInt(String(mezo.value).replace(/\D+/g, ''), 10);

            if (isNaN(n)) {
                mezo.focus();

                return;
            }

            n = Math.max(1, Math.min(max, n));
            minta.searchParams.set(param, String(n));
            window.location.href = minta.toString();
        }

        mezo.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                ugrik();
            }
        });

        doboz.querySelector('button').addEventListener('click', ugrik);
    }

    function lapozokBovit(gyoker) {
        if (!be('ugro')) {
            return;
        }

        Array.prototype.forEach.call((gyoker || document).querySelectorAll('.sdh-lapozo, .sdh-cs-lapozo--lap'), lapozoBovit);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { lapozokBovit(document); });
    } else {
        lapozokBovit(document);
    }

    if (window.MutationObserver) {
        new MutationObserver(function (valtozasok) {
            var kell = valtozasok.some(function (v) {
                return Array.prototype.some.call(v.addedNodes, function (n) {
                    return n.nodeType === 1 && (n.matches('.sdh-lapozo, .sdh-cs-lapozo--lap') || n.querySelector('.sdh-lapozo, .sdh-cs-lapozo--lap'));
                });
            });

            if (kell) {
                lapozokBovit(document);
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    /* ---------------------------------------------------------------- */
    /* Dupla kattintás a lista során: megnyitás                         */
    /* ---------------------------------------------------------------- */

    document.addEventListener('dblclick', function (ev) {
        if (!be('dupla') || !ev.target.closest) {
            return;
        }

        // Mezőn, gombon, linken, választón nem: ott a dupla kattintás mást jelent (pl. kijelölés).
        if (ev.target.closest('input, select, textarea, button, a, label, [contenteditable], .sdh-allapot, [data-sdh-masol]')) {
            return;
        }

        var sor = ev.target.closest('table.sdh-tabla tbody tr, [data-sdh-eladas-lista] tbody tr');

        if (!sor) {
            return;
        }

        var nyito = sor.querySelector('[data-sdh-urlap][data-sdh-id]');

        if (nyito) {
            ev.preventDefault();

            if (window.getSelection) {
                window.getSelection().removeAllRanges();
            }

            nyito.click();
        }
    });

    /* ---------------------------------------------------------------- */
    /* Űrlap továbbítása e-mailben                                      */
    /* ---------------------------------------------------------------- */

    var ablak = null;

    function ablakEpit() {
        if (ablak) {
            return ablak;
        }

        ablak = document.createElement('dialog');
        ablak.className = 'sdh-modal sdh-uemail';
        ablak.innerHTML =
            '<form method="dialog" class="sdh-uemail__urlap">' +
                '<h2 class="sdh-modal__cim" data-ue-cim>Továbbítás e-mailben</h2>' +
                '<div class="sdh-mezo"><label for="sdh_uemail_cimzett">Címzett</label>' +
                    '<input type="text" id="sdh_uemail_cimzett" data-ue-cimzett autocomplete="email" placeholder="nev@pelda.hu (több cím vesszővel)"></div>' +
                '<div class="sdh-mezo"><label for="sdh_uemail_targy">Tárgy</label>' +
                    '<input type="text" id="sdh_uemail_targy" data-ue-targy maxlength="200"></div>' +
                '<div class="sdh-mezo"><label for="sdh_uemail_szoveg">Üzenet</label>' +
                    '<textarea id="sdh_uemail_szoveg" data-ue-szoveg rows="9"></textarea></div>' +
                '<div class="sdh-uemail__pdf" data-ue-pdf-sor hidden>' +
                    '<label class="sdh-kapcsolo" title="A nyomtatvány PDF-je csatolmányként"><input type="checkbox" data-ue-pdf checked></label>' +
                    '<span>A nyomtatvány PDF-je csatolmányként</span></div>' +
                '<p class="sdh-uemail__allapot" data-ue-allapot role="status"></p>' +
                '<div class="sdh-urlap__lablec">' +
                    '<button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-ue-kuld>Küldés</button>' +
                    '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-ue-bezar>Mégsem</button>' +
                '</div>' +
            '</form>';
        document.body.appendChild(ablak);

        ablak.querySelector('[data-ue-bezar]').addEventListener('click', function () { ablak.close(); });

        ablak.querySelector('[data-ue-kuld]').addEventListener('click', function () {
            var gomb = this;
            var allapot = ablak.querySelector('[data-ue-allapot]');

            gomb.disabled = true;
            allapot.textContent = 'Küldés…';
            allapot.classList.remove('is-hiba');

            postol('sdh_muhely_urlap_email_kuld', {
                urlap: ablak.dataset.urlap,
                id: ablak.dataset.id,
                cimzett: ablak.querySelector('[data-ue-cimzett]').value,
                targy: ablak.querySelector('[data-ue-targy]').value,
                szoveg: ablak.querySelector('[data-ue-szoveg]').value,
                pdf: ablak.querySelector('[data-ue-pdf]').checked && !ablak.querySelector('[data-ue-pdf-sor]').hidden ? '1' : ''
            }).then(function (v) {
                gomb.disabled = false;
                allapot.textContent = (v && v.data && v.data.uzenet) || (v && v.success ? 'Elküldve.' : 'Nem sikerült.');
                allapot.classList.toggle('is-hiba', !(v && v.success));

                if (v && v.success) {
                    window.setTimeout(function () { if (ablak.open) { ablak.close(); } }, 1400);
                }
            }).catch(function (h) {
                gomb.disabled = false;
                allapot.textContent = h.message;
                allapot.classList.add('is-hiba');
            });
        });

        return ablak;
    }

    document.addEventListener('click', function (ev) {
        var gomb = ev.target.closest && ev.target.closest('[data-sdh-urlap-email]');

        if (!gomb) {
            return;
        }

        ev.preventDefault();

        var a = ablakEpit();
        var allapot = a.querySelector('[data-ue-allapot]');

        a.dataset.urlap = gomb.getAttribute('data-sdh-urlap-email');
        a.dataset.id = gomb.getAttribute('data-id');
        a.querySelector('[data-ue-cimzett]').value = '';
        a.querySelector('[data-ue-targy]').value = '';
        a.querySelector('[data-ue-szoveg]').value = '';
        a.querySelector('[data-ue-pdf-sor]').hidden = true;
        allapot.textContent = 'Betöltés…';
        allapot.classList.remove('is-hiba');

        if (!a.open) {
            a.showModal();
        }

        postol('sdh_muhely_urlap_email_elo', { urlap: a.dataset.urlap, id: a.dataset.id }).then(function (v) {
            if (!v || !v.success) {
                allapot.textContent = (v && v.data && v.data.uzenet) || 'Nem sikerült betölteni.';
                allapot.classList.add('is-hiba');

                return;
            }

            a.querySelector('[data-ue-cim]').textContent = 'Továbbítás e-mailben – ' + v.data.cim;
            a.querySelector('[data-ue-cimzett]').value = v.data.cimzett || '';
            a.querySelector('[data-ue-targy]').value = v.data.targy || '';
            a.querySelector('[data-ue-szoveg]').value = v.data.szoveg || '';
            a.querySelector('[data-ue-pdf-sor]').hidden = !v.data.pdf;
            allapot.textContent = '';
            a.querySelector('[data-ue-cimzett]').focus();
        }).catch(function (h) {
            allapot.textContent = h.message;
            allapot.classList.add('is-hiba');
        });
    });

    // A munkalap Fájlok lapfüle: a feltöltött dokumentumok a beállított címre.
    document.addEventListener('click', function (ev) {
        var gomb = ev.target.closest && ev.target.closest('[data-sdh-fajlok-kuld]');

        if (!gomb) {
            return;
        }

        ev.preventDefault();

        var allapot = gomb.parentNode.querySelector('[data-sdh-fajlok-allapot]');

        gomb.disabled = true;

        if (allapot) {
            allapot.textContent = 'Küldés…';
        }

        postol('sdh_muhely_munkalap_fajlok_kuld', { id: gomb.getAttribute('data-id') }).then(function (v) {
            gomb.disabled = false;

            if (allapot) {
                allapot.textContent = (v && v.data && v.data.uzenet) || (v && v.success ? 'Elküldve.' : 'Nem sikerült.');
            }
        }).catch(function (h) {
            gomb.disabled = false;

            if (allapot) {
                allapot.textContent = h.message;
            }
        });
    });
}());
