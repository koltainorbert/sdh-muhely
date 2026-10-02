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
    var dialog = null;
    var torzs = null;

    /* ---------------------------------------------------------------- */
    /* A popup váza – egyszer jön létre, utána újrahasznosul            */
    /* ---------------------------------------------------------------- */

    function vaz() {
        if (dialog) {
            return dialog;
        }

        dialog = document.createElement('dialog');
        dialog.className = 'sdh-modal';
        dialog.innerHTML =
            '<div class="sdh-modal__doboz">' +
            '  <button type="button" class="sdh-modal__bezar" aria-label="Bezárás">&times;</button>' +
            '  <div class="sdh-modal__torzs"></div>' +
            '</div>';

        document.body.appendChild(dialog);
        torzs = dialog.querySelector('.sdh-modal__torzs');

        dialog.querySelector('.sdh-modal__bezar').addEventListener('click', bezar);

        // Kattintás a sötét háttérre: bezárás. A dobozon belüli kattintás nem.
        dialog.addEventListener('click', function (esemeny) {
            if (esemeny.target === dialog) {
                bezar();
            }
        });

        return dialog;
    }

    function bezar() {
        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    function toltesKozben() {
        torzs.innerHTML = '<div class="sdh-modal__toltes">Betöltés…</div>';
    }

    function hiba(szoveg) {
        torzs.innerHTML =
            '<div class="sdh-uzenet sdh-uzenet--hiba">' + szovegBiztonsagos(szoveg) + '</div>';
    }

    function szovegBiztonsagos(szoveg) {
        var elem = document.createElement('div');
        elem.textContent = String(szoveg);

        return elem.innerHTML;
    }

    /* ---------------------------------------------------------------- */
    /* Megnyitás                                                        */
    /* ---------------------------------------------------------------- */

    function nyit(modul, id) {
        vaz();
        toltesKozben();

        if (!dialog.open) {
            dialog.showModal();
        }

        var cim = new URL(beallitas.ajax, window.location.origin);
        cim.searchParams.set('action', 'sdh_muhely_' + modul + '_urlap');
        cim.searchParams.set('id', id || '0');
        cim.searchParams.set('kontextus', beallitas.kontextus || 'admin');
        cim.searchParams.set('_wpnonce', beallitas.nonce || '');

        fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (valasz) {
                if (!valasz.ok) {
                    throw new Error('A szerver ' + valasz.status + ' hibakóddal válaszolt.');
                }

                return valasz.text();
            })
            .then(function (html) {
                torzs.innerHTML = html;
                bekotUrlap();

                var elso = torzs.querySelector('input:not([type="hidden"]), select, textarea');
                if (elso) {
                    elso.focus();
                }
            })
            .catch(function (ok) {
                hiba('Az űrlap nem töltődött be. ' + ok.message);
            });
    }

    /* ---------------------------------------------------------------- */
    /* Beküldés                                                         */
    /* ---------------------------------------------------------------- */

    function bekotUrlap() {
        var urlap = torzs.querySelector('form');

        if (!urlap) {
            return;
        }

        var megsem = torzs.querySelector('[data-sdh-megsem]');
        if (megsem) {
            megsem.addEventListener('click', function (esemeny) {
                esemeny.preventDefault();
                bezar();
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
    /* Indítás                                                          */
    /* ---------------------------------------------------------------- */

    document.addEventListener('click', function (esemeny) {
        var indito = esemeny.target.closest('[data-sdh-urlap]');

        if (!indito) {
            return;
        }

        esemeny.preventDefault();
        nyit(indito.dataset.sdhUrlap, indito.dataset.sdhId || '0');
    });
}());
