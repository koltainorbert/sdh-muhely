/* Beállítások › Névjegy › Frissítés (0.74.0): ellenőrzés és kétlépcsős „Frissítés most” gomb. */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};

    function kuld(doboz, muvelet) {
        var adat = new FormData();

        adat.set('action', muvelet);
        adat.set('_wpnonce', doboz.getAttribute('data-nonce') || '');

        return fetch(new URL(doboz.getAttribute('data-ajax') || B.ajax || '/wp-admin/admin-ajax.php', window.location.origin).toString(),
            {method: 'POST', body: adat, credentials: 'same-origin'})
            .then(function (v) { return v.json(); })
            .catch(function () { return {success: false, data: {uzenet: 'Nem sikerült elérni a szervert.'}}; });
    }

    function szoveg(v, alap) {
        return (v && v.data && v.data.uzenet) || alap;
    }

    function indit(doboz) {
        var uzenet = doboz.querySelector('[data-sdh-frissites-uzenet]');
        var uj = doboz.querySelector('[data-sdh-frissites-uj]');
        var ellenorzes = doboz.querySelector('[data-sdh-frissites-ellenorzes]');
        var frissit = doboz.querySelector('[data-sdh-frissites-indit]');
        var ido = null;

        if (ellenorzes) {
            ellenorzes.addEventListener('click', function () {
                ellenorzes.disabled = true;
                uzenet.textContent = 'Ellenőrzés…';
                kuld(doboz, 'sdh_muhely_frissites_ellenorzes').then(function (v) {
                    ellenorzes.disabled = false;
                    uzenet.textContent = szoveg(v, 'Az ellenőrzés nem sikerült.');

                    if (v && v.success && v.data) {
                        if (uj && v.data.verzio) {
                            uj.textContent = v.data.verzio;
                        }

                        if (frissit) {
                            frissit.hidden = !v.data.ujabb;
                        }
                    }
                });
            });
        }

        if (frissit) {
            frissit.addEventListener('click', function () {
                if (frissit.getAttribute('data-sdh-biztos') !== '1') {
                    frissit.setAttribute('data-sdh-biztos', '1');
                    frissit.textContent = 'Biztosan? Kattints újra';
                    ido = window.setTimeout(function () {
                        frissit.removeAttribute('data-sdh-biztos');
                        frissit.textContent = 'Frissítés most';
                    }, 6000);

                    return;
                }

                window.clearTimeout(ido);
                frissit.removeAttribute('data-sdh-biztos');
                frissit.disabled = true;
                if (ellenorzes) {
                    ellenorzes.disabled = true;
                }
                uzenet.textContent = 'Mentés, majd frissítés… ne zárd be az oldalt.';
                kuld(doboz, 'sdh_muhely_frissites_indit').then(function (v) {
                    uzenet.textContent = szoveg(v, v && v.success ? 'Kész.' : 'A frissítés nem sikerült.');

                    if (v && v.success) {
                        window.setTimeout(function () { window.location.reload(); }, 2500);

                        return;
                    }

                    frissit.disabled = false;
                    frissit.textContent = 'Frissítés most';
                    if (ellenorzes) {
                        ellenorzes.disabled = false;
                    }
                });
            });
        }
    }

    function betolt() {
        var lista = document.querySelectorAll('[data-sdh-frissites]');

        for (var i = 0; i < lista.length; i++) {
            if (!lista[i].hasAttribute('data-sdh-frissites-kesz')) {
                lista[i].setAttribute('data-sdh-frissites-kesz', '1');
                indit(lista[i]);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', betolt);
    } else {
        betolt();
    }
})();
