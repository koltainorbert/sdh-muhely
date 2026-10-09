/**
 * SDH Műhely – RMA / Üzenetek lapfül a munkalap részletein (0.26).
 *
 *  - üzenet küldése az ügyfélnek (AJAX, a szál helyben frissül),
 *  - az ügyféloldal címének másolása,
 *  - a lapfül megnyitásakor a bejövő üzenetek olvasottra állnak.
 *
 * Eseménydelegálással dolgozik, mert a részletpanel tartalmát a rács
 * AJAX-szal cseréli.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};

    function kuld(akcio, mezok) {
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
            credentials: 'same-origin'
        })
            .then(function (valasz) {
                return valasz.json().catch(function () {
                    throw new Error('A szerver nem várt választ adott (' + valasz.status + ').');
                });
            })
            .then(function (json) {
                if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.uzenet) || 'A művelet nem sikerült.');
                }

                return json.data;
            });
    }

    /** A lapfül címének frissítése a számlálóból. */
    function fulCim(szamlalo) {
        var cim = document.querySelector('.sdh-reszlet__ful[data-ful="rma"] span');

        if (!cim || !szamlalo) {
            return;
        }

        cim.textContent = 'RMA / Üzenetek' + (szamlalo.osszes > 0
            ? ' (' + szamlalo.osszes + (szamlalo.uj > 0 ? ', ' + szamlalo.uj + ' új' : '') + ')'
            : '');
    }

    document.addEventListener('submit', function (esemeny) {
        var urlap = esemeny.target.closest('[data-sdh-uzenet-urlap]');

        if (!urlap) {
            return;
        }

        esemeny.preventDefault();

        var mezo = urlap.querySelector('textarea[name="szoveg"]');
        var email = urlap.querySelector('input[name="email"]');
        var gomb = urlap.querySelector('button[type="submit"]');
        var hiba = urlap.querySelector('[data-sdh-uzenet-hiba]');
        var szoveg = (mezo.value || '').trim();

        if (!szoveg) {
            mezo.focus();
            return;
        }

        gomb.disabled = true;
        hiba.hidden = true;

        kuld('sdh_muhely_uzenet_kuld', {
            id: urlap.getAttribute('data-id'),
            szoveg: szoveg,
            email: email && email.checked && !email.disabled ? '1' : ''
        })
            .then(function (adat) {
                var panel = urlap.closest('[data-sdh-rma]');
                var szal = panel && panel.querySelector('[data-sdh-uzenetek]');

                if (szal) {
                    szal.innerHTML = adat.html; // a szerveren szűrt HTML
                }

                mezo.value = '';
                fulCim(adat.szamlalo);

                if (email && email.checked && !email.disabled && !adat.email) {
                    hiba.textContent = 'Az üzenet elmentve, de az e-mail nem ment el (ellenőrizd a levelezés beállítását).';
                    hiba.hidden = false;
                }
            })
            .catch(function (ok) {
                hiba.textContent = ok.message;
                hiba.hidden = false;
            })
            .then(function () {
                gomb.disabled = false;
            });
    });

    document.addEventListener('keydown', function (esemeny) {
        // Ctrl/Cmd + Enter = küldés.
        if (esemeny.key !== 'Enter' || !(esemeny.ctrlKey || esemeny.metaKey)) {
            return;
        }

        var urlap = esemeny.target.closest && esemeny.target.closest('[data-sdh-uzenet-urlap]');

        if (urlap) {
            esemeny.preventDefault();
            urlap.requestSubmit();
        }
    });

    document.addEventListener('click', function (esemeny) {
        var masol = esemeny.target.closest('[data-sdh-rma-masol]');

        if (masol) {
            var mezo = masol.parentNode.querySelector('input');

            mezo.select();

            var kesz = function () {
                var eredeti = masol.textContent;

                masol.textContent = 'Másolva';
                setTimeout(function () { masol.textContent = eredeti; }, 1400);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(mezo.value).then(kesz, function () {
                    document.execCommand('copy');
                    kesz();
                });
            } else {
                document.execCommand('copy');
                kesz();
            }

            return;
        }

        var ful = esemeny.target.closest('.sdh-reszlet__ful[data-ful="rma"]');

        if (ful && / új\)/.test(ful.textContent)) {
            var panel = document.querySelector('[data-sdh-rma]');

            if (panel) {
                kuld('sdh_muhely_uzenet_olvasva', { id: panel.getAttribute('data-sdh-rma') })
                    .then(function (adat) { fulCim(adat.szamlalo); })
                    .catch(function () {});
            }
        }
    });
}());
