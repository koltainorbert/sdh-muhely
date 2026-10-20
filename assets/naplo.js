/**
 * Napló és lezárt lap (0.59).
 *
 * - A rekordok „Napló" gombja (data-sdh-naplo-nyit) a Napló ablakot nyitja
 *   popupban, a már nyitott ablak fölé; a „Régebbiek" gomb lapoz.
 * - A Napló ablak megnyitásakor a figura felajánlja, hogy elmondja, mi
 *   történt; a „Mi történt?" gomb (data-sdh-naplo-ai) azonnal megkérdezi.
 * - A lezárt lap (data-sdh-zarolt) űrlapja csak olvasható: a mezők és a
 *   szerkesztő gombok letiltva, a Mentés gomb rejtve. Ami zárolva is mehet
 *   (lapfülek, másolás, ügyfél és eszköz adatlapja, nyomtatás, matrica,
 *   számla, Napló, RMA-csevegő), az él. Az Újranyitás kétlépcsős gomb.
 * - A Napló menüpont: épségellenőrzés.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};

    function app() {
        return window.SDH_MUHELY_APP || null;
    }

    function e(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function post(akcio, adat) {
        var fd = new FormData();

        fd.set('action', 'sdh_muhely_' + akcio);
        fd.set('_wpnonce', B.nonce || '');
        Object.keys(adat || {}).forEach(function (k) {
            fd.set(k, adat[k]);
        });

        return fetch(B.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (j) {
                if (!j || !j.success) {
                    throw new Error((j && j.data && j.data.uzenet) || 'A művelet nem sikerült.');
                }

                return j.data;
            });
    }

    function szintje(elem) {
        var a = app();

        return a && typeof a.sajatSzint === 'function' ? a.sajatSzint(elem) : -1;
    }

    function ai() {
        return window.SDH_AI_API || null;
    }

    /* ---------------------------------------------------------------- */
    /* A Napló ablak                                                     */
    /* ---------------------------------------------------------------- */

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-naplo-nyit]') : null;

        if (gomb) {
            esemeny.preventDefault();

            var a = app();

            if (!a) {
                return;
            }

            a.nyit('naplo', gomb.getAttribute('data-id') || '0', {
                szint: Math.min(szintje(gomb) + 1, 3),
                parameterek: { tipus: gomb.getAttribute('data-tipus') || 'munkalap' }
            });

            return;
        }

        var kerd = esemeny.target.closest ? esemeny.target.closest('[data-sdh-naplo-ai]') : null;

        if (kerd) {
            esemeny.preventDefault();

            if (ai() && typeof ai().kerdez === 'function') {
                ai().kerdez(kerd.getAttribute('data-kerdes') || 'Mi történt itt?');
            }

            return;
        }

        var tobb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-naplo-tobb]') : null;

        if (tobb) {
            esemeny.preventDefault();
            tobbBetolt(tobb);

            return;
        }

        var ell = esemeny.target.closest ? esemeny.target.closest('[data-sdh-naplo-ellenoriz]') : null;

        if (ell) {
            esemeny.preventDefault();
            ellenoriz(ell);
        }
    });

    function tobbBetolt(gomb) {
        var ablak = gomb.closest('[data-sdh-naplo-ablak]');

        if (!ablak) {
            return;
        }

        gomb.disabled = true;
        gomb.textContent = 'Betöltés…';

        post('naplo_tobb', {
            tipus: ablak.getAttribute('data-tipus') || '',
            id: ablak.getAttribute('data-id') || '0',
            lap: gomb.getAttribute('data-lap') || '2'
        }).then(function (d) {
            ablak.querySelector('[data-sdh-naplo-lista]').insertAdjacentHTML('beforeend', d.html || '');

            if (d.van_meg) {
                gomb.disabled = false;
                gomb.textContent = 'Régebbiek';
                gomb.setAttribute('data-lap', String(d.lap));
            } else {
                gomb.parentNode.remove();
            }
        }).catch(function (h) {
            gomb.disabled = false;
            gomb.textContent = 'Régebbiek';
            window.alert(h.message);
        });
    }

    /** A Napló ablak nyílt: a figura felajánlja, hogy elmondja, mi történt. */
    function ablakIndul(ablak) {
        var osszes = parseInt(ablak.getAttribute('data-osszes') || '0', 10);
        var A = ai();
        var gomb = ablak.querySelector('[data-sdh-naplo-ai]');

        if (!A || typeof A.buborek !== 'function' || !gomb || osszes < 1) {
            return;
        }

        window.setTimeout(function () {
            A.buborek('**' + e(ablak.getAttribute('data-cimke') || 'Ez a rekord') + '** – ' + osszes + ' bejegyzés a naplóban. Elmondjam röviden, mi történt vele?', [
                { felirat: 'Igen, mondd el', fo: true, tesz: function () { A.kerdez(gomb.getAttribute('data-kerdes')); } },
                { felirat: 'Most nem', tesz: function () {} }
            ], { ms: 12000, allapot: 'beszel' });
        }, 500);
    }

    /* ---------------------------------------------------------------- */
    /* Lezárt lap: csak olvasható                                        */
    /* ---------------------------------------------------------------- */

    // Ami zárolt lapon is mehet (nem a lap tartalmát írja).
    var SZABAD = [
        '[data-sdh-zarsav]', '.sdh-fulek__panel--rma', '.sdh-fulek__ful', '[data-sdh-masol]', '[data-sdh-eszkoz-szerk]',
        '[data-sdh-ugyfel-szerk]', '[data-sdh-nyomtat]', '[data-sdh-naplo-nyit]', '[data-sdh-matrica]', '[data-sdh-szamla]',
        '[data-sdh-szamlagombok]', '[data-sdh-megsem]', '[data-sdh-rma]', 'summary'
    ].join(',');

    function szabad(el) {
        if (el.closest(SZABAD)) {
            return true;
        }

        // A „Fizetés" gomb a már kifizetett lapon a pénztárba írás miatt mehet.
        return el.matches('[data-sdh-penztar-fizetes].is-fizetve');
    }

    function tilt(gyoker) {
        gyoker.querySelectorAll('input, select, textarea, button').forEach(function (el) {
            if (szabad(el) || el.type === 'hidden') {
                return;
            }

            // (A [hidden] attribútumot a gomb saját display-szabálya felülírná.)
            if (el.matches('button[type="submit"], [data-sdh-penztar-fizetes]')) {
                el.hidden = true;
                el.style.display = 'none';
            }

            if (!el.disabled) {
                el.disabled = true;
                el.setAttribute('data-sdh-zar-tiltva', '1');
            }
        });
    }

    function zarol(torzs, modul, id) {
        var lap = torzs.querySelector('[data-sdh-zarolt]');

        if (!lap) {
            return;
        }

        var urlap = lap.closest('form') || lap;

        urlap.classList.add('is-zarolt');
        tilt(urlap);

        // Az aszinkron betöltött részek (összefoglaló, kártyák) is zároltak legyenek.
        if (window.MutationObserver) {
            var figyelo = new MutationObserver(function () { tilt(urlap); });

            figyelo.observe(urlap, { childList: true, subtree: true });
            torzs.addEventListener('sdh:zar-vege', function () { figyelo.disconnect(); }, { once: true });
        }

        // A mentés akkor sem mehet ki, ha valami mégis beküldené (a szerver is elutasítja).
        urlap.addEventListener('submit', function (esemeny) {
            if (urlap.classList.contains('is-zarolt')) {
                esemeny.preventDefault();
                esemeny.stopImmediatePropagation();
            }
        }, true);

        var gomb = lap.querySelector('[data-sdh-ujranyit]');

        if (!gomb) {
            return;
        }

        var elesitve = false;
        var idozito = null;

        gomb.addEventListener('click', function () {
            var indokMezo = lap.querySelector('[data-sdh-ujranyit-indok]');
            var indok = indokMezo ? indokMezo.value.trim() : '';

            if (indokMezo && indokMezo.getAttribute('data-kotelezo') === '1' && indok === '') {
                indokMezo.focus();
                indokMezo.classList.add('is-hibas');

                return;
            }

            // Kétlépcsős gomb (confirm helyett): az első kattintás élesít, a második nyit újra.
            if (!elesitve) {
                elesitve = true;
                gomb.textContent = 'Biztosan? Kattints újra';
                gomb.classList.add('is-elesitve');
                window.clearTimeout(idozito);
                idozito = window.setTimeout(function () {
                    elesitve = false;
                    gomb.textContent = 'Újranyitás';
                    gomb.classList.remove('is-elesitve');
                }, 4000);

                return;
            }

            window.clearTimeout(idozito);
            gomb.disabled = true;
            gomb.textContent = 'Újranyitás…';

            var valaszto = lap.querySelector('[data-sdh-ujranyit-allapot]');

            post('munkalapok_ujranyit', {
                id: gomb.getAttribute('data-id') || id || '0',
                allapot: valaszto ? valaszto.value : '',
                indok: indok
            }).then(function (adat) {
                torzs.dispatchEvent(new CustomEvent('sdh:zar-vege'));

                document.dispatchEvent(new CustomEvent('sdh:allapot', {
                    detail: { id: String(gomb.getAttribute('data-id') || id || '0'), adat: adat }
                }));

                // Az űrlap újratölt – most már szerkeszthető.
                var a = app();

                if (a) {
                    a.nyit(modul || 'munkalapok', String(gomb.getAttribute('data-id') || id || '0'), { szint: Math.max(0, szintje(torzs)) });
                }
            }).catch(function (h) {
                gomb.disabled = false;
                elesitve = false;
                gomb.textContent = 'Újranyitás';
                gomb.classList.remove('is-elesitve');

                var hely = lap.querySelector('.sdh-zarsav__hiba');

                if (!hely) {
                    hely = document.createElement('div');
                    hely.className = 'sdh-zarsav__hiba';
                    lap.appendChild(hely);
                }

                hely.textContent = h.message;
            });
        });
    }

    document.addEventListener('sdh:urlap-betoltve', function (esemeny) {
        var d = esemeny.detail || {};

        if (!d.torzs) {
            return;
        }

        var ablak = d.torzs.querySelector('[data-sdh-naplo-ablak]');

        if (ablak) {
            ablakIndul(ablak);
        }

        if (d.torzs.querySelector('[data-sdh-zarolt]')) {
            zarol(d.torzs, d.modul, d.id);
        }
    });

    /* ---------------------------------------------------------------- */
    /* Napló menüpont: épség ellenőrzése                                 */
    /* ---------------------------------------------------------------- */

    function ellenoriz(gomb) {
        var oldal = gomb.closest('[data-sdh-naplo-oldal]') || document;
        var stat = oldal.querySelector('[data-sdh-naplo-epseg]');
        var reszlet = oldal.querySelector('[data-sdh-naplo-epseg-reszlet]');

        gomb.disabled = true;
        gomb.textContent = 'Ellenőrzés…';

        post('naplo_ellenoriz', {}).then(function (d) {
            gomb.disabled = false;
            gomb.textContent = 'Épség ellenőrzése';

            if (stat) {
                stat.classList.toggle('is-ok', !!d.ok);
                stat.classList.toggle('is-hiba', !d.ok);
                stat.querySelector('strong').textContent = d.ok ? 'Ép' : 'Eltérés!';
                stat.querySelector('span').textContent = 'Ellenőrizve: ' + String(d.ido || '').slice(0, 16);
            }

            if (reszlet) {
                reszlet.hidden = false;
                reszlet.className = 'sdh-uzenet ' + (d.ok ? 'sdh-uzenet--siker' : 'sdh-uzenet--hiba') + ' sdh-naplo__epseg-reszlet';
                reszlet.textContent = d.szoveg || '';
            }
        }).catch(function (h) {
            gomb.disabled = false;
            gomb.textContent = 'Épség ellenőrzése';

            if (reszlet) {
                reszlet.hidden = false;
                reszlet.className = 'sdh-uzenet sdh-uzenet--hiba sdh-naplo__epseg-reszlet';
                reszlet.textContent = h.message;
            }
        });
    }
}());
