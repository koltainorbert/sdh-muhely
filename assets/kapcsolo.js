/* Egységes kapcsoló (0.72.0): a be/ki jelölőnégyzet mellé „Aktív" / „Inaktív" feliratot tesz,
   és követi az állapotot. Dinamikusan beszúrt (popup) űrlapokra is érvényes. */
(function () {
    'use strict';

    var VALASZTO = 'input[type="checkbox"]:not([data-sdh-nativ])';

    function kapcsolo(i) {
        return !i.closest('table') && !i.closest('.sdh-cs-pill') &&
            !!i.closest('.sdh-app, .sdh-modal');
    }

    function frissit(i) {
        var s = i.nextElementSibling;
        if (!s || !s.classList.contains('sdh-kapcs-allapot')) {
            return;
        }
        s.textContent = i.checked ? 'Aktív' : 'Inaktív';
        s.classList.toggle('is-aktiv', i.checked);
    }

    function felkeszit(i) {
        if (i.hasAttribute('data-sdh-kapcs-kesz') || !kapcsolo(i)) {
            return;
        }
        i.setAttribute('data-sdh-kapcs-kesz', '1');
        var s = document.createElement('span');
        s.className = 'sdh-kapcs-allapot';
        s.setAttribute('aria-hidden', 'true');
        i.insertAdjacentElement('afterend', s);
        frissit(i);
    }

    function bejar(gyoker) {
        if (!gyoker || gyoker.nodeType !== 1) {
            return;
        }
        if (gyoker.matches && gyoker.matches(VALASZTO)) {
            felkeszit(gyoker);
        }
        var lista = gyoker.querySelectorAll ? gyoker.querySelectorAll(VALASZTO) : [];
        for (var n = 0; n < lista.length; n++) {
            felkeszit(lista[n]);
        }
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.matches && e.target.matches(VALASZTO)) {
            frissit(e.target);
        }
    });

    /* Programból átállított érték (pl. űrlap-visszaállítás) után is helyes legyen a felirat. */
    document.addEventListener('reset', function (e) {
        setTimeout(function () {
            var lista = e.target.querySelectorAll ? e.target.querySelectorAll('[data-sdh-kapcs-kesz]') : [];
            for (var n = 0; n < lista.length; n++) {
                frissit(lista[n]);
            }
        }, 0);
    });

    function indit() {
        bejar(document.body);
        new MutationObserver(function (lista) {
            for (var a = 0; a < lista.length; a++) {
                for (var b = 0; b < lista[a].addedNodes.length; b++) {
                    bejar(lista[a].addedNodes[b]);
                }
            }
        }).observe(document.body, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', indit);
    } else {
        indit();
    }

    /* Más szkriptek (pl. automentés-visszaállítás) ezzel frissíthetik egy kapcsoló feliratát. */
    window.SDH_KAPCSOLO = {frissit: frissit, bejar: bejar};
})();
