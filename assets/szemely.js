/* Közös fiók: „Ki vagy?" választó (0.72.0, H2).
   Név → (PIN) → a szerver rögzíti a választást ebben a böngészőben → újratöltés. */
(function () {
    'use strict';

    function kuld(adat, kesz) {
        var B = window.SDH_MUHELY || {};
        var fd = new FormData();

        if (!B.ajax) {
            return;
        }

        fd.set('_wpnonce', B.nonce || '');
        Object.keys(adat).forEach(function (k) {
            fd.set(k, adat[k]);
        });

        fetch(B.ajax, {method: 'POST', body: fd, credentials: 'same-origin'})
            .then(function (v) {
                return v.json().catch(function () {
                    return {success: false};
                });
            })
            .then(kesz)
            .catch(function () {
                kesz({success: false, data: {uzenet: 'Nincs kapcsolat a szerverrel.'}});
            });
    }

    function valaszto(doboz) {
        var pinKell = doboz.getAttribute('data-pin') === '1';
        var pinSor = doboz.querySelector('[data-sdh-kivagy-pinsor]');
        var pin = doboz.querySelector('[data-sdh-kivagy-pin]');
        var ok = doboz.querySelector('[data-sdh-kivagy-ok]');
        var hiba = doboz.querySelector('[data-sdh-kivagy-hiba]');
        var valasztott = '';
        var foglalt = false;

        function hibaKi(szoveg) {
            if (hiba) {
                hiba.textContent = szoveg;
                hiba.hidden = !szoveg;
            }
        }

        function belep() {
            if (foglalt || !valasztott) {
                return;
            }

            if (pinKell && pin && !pin.value) {
                hibaKi('Add meg a PIN-ed.');
                pin.focus();

                return;
            }

            foglalt = true;
            hibaKi('');
            kuld({action: 'sdh_muhely_ki_vagy', szemely: valasztott, pin: pin ? pin.value : ''}, function (v) {
                foglalt = false;

                if (v && v.success) {
                    window.location.reload();

                    return;
                }

                hibaKi((v && v.data && v.data.uzenet) || 'Nem sikerült.');

                if (pin) {
                    pin.value = '';
                    pin.focus();
                }
            });
        }

        doboz.addEventListener('click', function (e) {
            var gomb = e.target.closest('[data-sdh-kivagy-nev]');

            if (gomb) {
                valasztott = gomb.getAttribute('data-sdh-kivagy-nev') || '';
                doboz.querySelectorAll('[data-sdh-kivagy-nev]').forEach(function (g) {
                    g.classList.toggle('is-valasztott', g === gomb);
                });
                hibaKi('');

                if (pinKell && pinSor) {
                    pinSor.hidden = false;
                    pin.value = '';
                    pin.focus();
                } else {
                    belep();
                }

                return;
            }

            if (e.target.closest('[data-sdh-kivagy-ok]')) {
                belep();
            }
        });

        if (pin) {
            pin.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    belep();
                }
            });
        }

        if (ok) {
            ok.type = 'button';
        }
    }

    document.addEventListener('click', function (e) {
        var valt = e.target.closest && e.target.closest('[data-sdh-kivagy-valt]');

        if (!valt) {
            return;
        }

        valt.disabled = true;
        kuld({action: 'sdh_muhely_ki_vagy_ki'}, function () {
            window.location.reload();
        });
    });

    function indit() {
        var doboz = document.querySelector('[data-sdh-kivagy]');

        if (doboz) {
            valaszto(doboz);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', indit);
    } else {
        indit();
    }
})();
