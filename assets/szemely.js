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

    /* Kétlépcsős belépés (H7, 0.81.0): profilmenü → popup, a saját fiók műveletei, és a
       Beállítások › Jogosultságok „Visszaállítás” gombja (kétlépcsős gomb, confirm nélkül). */

    function ketBiztos(gomb, felirat) {
        if (gomb.getAttribute('data-sdh-biztos') === '1') {
            gomb.removeAttribute('data-sdh-biztos');

            return true;
        }

        var eredeti = gomb.textContent;

        gomb.setAttribute('data-sdh-biztos', '1');
        gomb.textContent = felirat;
        setTimeout(function () {
            if (gomb.getAttribute('data-sdh-biztos') === '1') {
                gomb.removeAttribute('data-sdh-biztos');
                gomb.textContent = eredeti;
            }
        }, 4000);

        return false;
    }

    function ketUzenet(doboz, szoveg, jo) {
        var u = doboz.querySelector('[data-sdh-ketlepcsos-uzenet]');

        if (u) {
            u.textContent = szoveg || '';
            u.className = 'sdh-uzenet ' + (jo ? 'sdh-uzenet--siker' : 'sdh-uzenet--hiba');
            u.hidden = !szoveg;
        }
    }

    function ketTartalek(doboz, kodok) {
        var lista = doboz.querySelector('[data-sdh-ketlepcsos-tartalek]');

        if (!lista || !kodok || !kodok.length) {
            return;
        }

        while (lista.firstChild) {
            lista.removeChild(lista.firstChild);
        }
        kodok.forEach(function (k) {
            var li = document.createElement('li');
            var c = document.createElement('code');

            c.textContent = k;
            li.appendChild(c);
            lista.appendChild(li);
        });
        lista.hidden = false;
    }

    document.addEventListener('click', function (e) {
        var nyit = e.target.closest && e.target.closest('[data-sdh-ketlepcsos-nyit]');

        if (nyit) {
            e.preventDefault();

            if (window.SDH_MUHELY_APP && window.SDH_MUHELY_APP.nyit) {
                window.SDH_MUHELY_APP.nyit('ketlepcsos', 0);
            }

            return;
        }

        var akcioGomb = e.target.closest && e.target.closest('[data-sdh-ketlepcsos-akcio]');

        if (akcioGomb) {
            e.preventDefault();

            var doboz = akcioGomb.closest('[data-sdh-ketlepcsos]');
            var akcio = akcioGomb.getAttribute('data-sdh-ketlepcsos-akcio');
            var mezo = doboz.querySelector('[data-sdh-ketlepcsos-kod]');

            if (akcio === 'kikapcsol' && !ketBiztos(akcioGomb, 'Biztosan kikapcsolod?')) {
                return;
            }

            if (!mezo || !mezo.value.trim()) {
                ketUzenet(doboz, 'Írd be a kódot a telefonról.', false);

                if (mezo) {
                    mezo.focus();
                }

                return;
            }

            akcioGomb.disabled = true;
            kuld({action: 'sdh_muhely_ketlepcsos_' + akcio, kod: mezo.value}, function (v) {
                akcioGomb.disabled = false;
                mezo.value = '';

                var adat = (v && v.data) || {};

                ketUzenet(doboz, adat.uzenet || (v && v.success ? 'Kész.' : 'Nem sikerült.'), !!(v && v.success));

                if (v && v.success) {
                    ketTartalek(doboz, adat.tartalek);

                    if (akcio === 'bekapcsol') {
                        Array.prototype.forEach.call(doboz.querySelectorAll('.sdh-ketlepcsos__beallit, .sdh-mezok, .sdh-ketlepcsos__gombok, .sdh-sugo'), function (el) {
                            el.hidden = true;
                        });
                    }

                    if (adat.ujratolt) {
                        setTimeout(function () { window.location.reload(); }, 1200);
                    }
                }
            });

            return;
        }

        var vissza = e.target.closest && e.target.closest('[data-sdh-ketlepcsos-visszaallit]');

        if (vissza) {
            e.preventDefault();

            if (!ketBiztos(vissza, 'Biztosan visszaállítod?')) {
                return;
            }

            var B = window.SDH_MUHELY || {};
            var fd = new FormData();

            fd.set('action', 'sdh_muhely_ketlepcsos_visszaallit');
            fd.set('_wpnonce', vissza.getAttribute('data-nonce') || '');
            fd.set('felhasznalo', vissza.getAttribute('data-sdh-ketlepcsos-visszaallit') || '0');
            vissza.disabled = true;

            fetch(B.ajax || '/wp-admin/admin-ajax.php', {method: 'POST', body: fd, credentials: 'same-origin'})
                .then(function (v) { return v.json(); })
                .then(function (v) {
                    var sor = vissza.closest('tr');

                    if (v && v.success && sor) {
                        sor.cells[1].textContent = 'Visszaállítva';
                        sor.cells[2].textContent = '–';
                        vissza.remove();
                    } else {
                        vissza.disabled = false;
                        vissza.textContent = (v && v.data && v.data.uzenet) || 'Nem sikerült.';
                    }
                })
                .catch(function () {
                    vissza.disabled = false;
                    vissza.textContent = 'Nincs kapcsolat.';
                });
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target.matches || !e.target.matches('[data-sdh-ketlepcsos-kod]')) {
            return;
        }

        var doboz = e.target.closest('[data-sdh-ketlepcsos]');
        var elso = doboz && doboz.querySelector('[data-sdh-ketlepcsos-akcio="bekapcsol"]');

        if (elso) {
            e.preventDefault();
            elso.click();
        }
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
