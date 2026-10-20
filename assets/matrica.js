/**
 * RMA-matrica (0.59).
 *
 * - A munkalap „Matrica" gombja (data-sdh-matrica) a Matrica ablakot nyitja:
 *   eszközönként egy előnézet, pipával választható, példányszám.
 * - Nyomtatás: böngészős módban a PDF rejtett keretben nyílik, és a böngésző
 *   nyomtatás-ablaka jön (ott a Brother nyomtatót kell választani);
 *   hálózati módban a szerver közvetlenül a nyomtatóra küldi.
 * - Termék- és szolgáltatás-címke (0.59.3): ugyanez a gomb az űrlapjukon
 *   (data-sdh-matrica-tipus="termek" / "szolgaltatas").
 * - Beállítások › Címkenyomtatás: közös nyomtató és tekercs, sablononként
 *   (RMA / termék / szolgáltatás) élő előnézet a még el nem mentett
 *   értékekkel, a mezők sorrendje nyilakkal, próbanyomat.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};

    function app() {
        return window.SDH_MUHELY_APP || null;
    }

    function cim(akcio, parameterek) {
        var u = new URL(B.ajax, window.location.origin);

        u.searchParams.set('action', 'sdh_muhely_' + akcio);
        u.searchParams.set('_wpnonce', B.nonce || '');

        Object.keys(parameterek || {}).forEach(function (k) {
            var v = parameterek[k];

            if (Array.isArray(v)) {
                v.forEach(function (x) { u.searchParams.append(k + '[]', x); });
            } else {
                u.searchParams.set(k, v);
            }
        });

        return u.toString();
    }

    function post(akcio, fd) {
        fd.set('action', 'sdh_muhely_' + akcio);
        fd.set('_wpnonce', B.nonce || '');

        return fetch(B.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (j) {
                if (!j || !j.success) {
                    throw new Error((j && j.data && j.data.uzenet) || 'A művelet nem sikerült.');
                }

                return j.data;
            });
    }

    /* ---- Nyomtatás rejtett keretből (mint a Nyomtatás ablaknál) ---- */

    var keret = null;

    function nyomtatBlob(blob) {
        var url = URL.createObjectURL(blob);

        if (keret) {
            keret.remove();
        }

        keret = document.createElement('iframe');
        keret.setAttribute('aria-hidden', 'true');
        keret.setAttribute('tabindex', '-1');
        keret.style.cssText = 'position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none';

        window.setTimeout(function () { URL.revokeObjectURL(url); }, 10 * 60 * 1000);

        return new Promise(function (kesz) {
            var eldolt = false;

            function vege(ok) {
                if (!eldolt) {
                    eldolt = true;
                    kesz({ ok: ok, url: url });
                }
            }

            keret.addEventListener('load', function () {
                window.setTimeout(function () {
                    try {
                        keret.contentWindow.focus();
                        keret.contentWindow.print();
                        vege(true);
                    } catch (h) {
                        vege(false);
                    }
                }, 350);
            });

            window.setTimeout(function () { vege(false); }, 6000);

            keret.src = url;
            document.body.appendChild(keret);
        });
    }

    function b64Blob(b64, tipus) {
        var bin = window.atob(b64);
        var t = new Uint8Array(bin.length);

        for (var i = 0; i < bin.length; i++) {
            t[i] = bin.charCodeAt(i);
        }

        return new Blob([t], { type: tipus });
    }

    /* ---------------------------------------------------------------- */
    /* A Matrica ablak                                                    */
    /* ---------------------------------------------------------------- */

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-matrica]') : null;

        if (gomb) {
            esemeny.preventDefault();

            var a = app();

            if (a) {
                var szint = typeof a.sajatSzint === 'function' ? a.sajatSzint(gomb) : -1;

                a.nyit('matrica', gomb.getAttribute('data-sdh-matrica') || '0', {
                    szint: Math.min(szint + 1, 3),
                    parameterek: { tipus: gomb.getAttribute('data-sdh-matrica-tipus') || 'rma' }
                });
            }

            return;
        }

        var ablak = esemeny.target.closest ? esemeny.target.closest('[data-sdh-matrica-ablak]') : null;

        if (!ablak) {
            return;
        }

        var lep = esemeny.target.closest('[data-sdh-matrica-lep]');

        if (lep) {
            esemeny.preventDefault();

            var mezo = ablak.querySelector('[data-sdh-matrica-peldany]');

            if (mezo) {
                mezo.value = String(Math.max(1, Math.min(50, (parseInt(mezo.value, 10) || 1) + (parseInt(lep.getAttribute('data-sdh-matrica-lep'), 10) || 0))));
            }

            return;
        }

        if (esemeny.target.closest('[data-sdh-matrica-nyomtat]')) {
            esemeny.preventDefault();
            nyomtat(ablak, esemeny.target.closest('[data-sdh-matrica-nyomtat]'));
        } else if (esemeny.target.closest('[data-sdh-matrica-pdf]')) {
            esemeny.preventDefault();
            window.open(cim('matrica_pdf', adatok(ablak)), '_blank', 'noopener');
        }
    });

    /** Az egyedi címke négy sora, ahogy a mezőkben áll (üres sor is megy: a helye így marad). */
    function egyediSorok(ablak) {
        var mezok = ablak.querySelectorAll('[data-sdh-matrica-sor]');

        return mezok.length ? Array.prototype.map.call(mezok, function (m) { return m.value || ''; }) : null;
    }

    function adatok(ablak) {
        var eszk = Array.prototype.slice.call(ablak.querySelectorAll('[data-sdh-matrica-eszkoz]:checked')).map(function (c) { return c.value; });
        var pld = ablak.querySelector('[data-sdh-matrica-peldany]');
        var d = {
            id: ablak.getAttribute('data-id') || '0',
            tipus: ablak.getAttribute('data-tipus') || 'rma',
            eszkoz: eszk,
            peldany: pld ? String(Math.max(1, Math.min(50, parseInt(pld.value, 10) || 1))) : '1'
        };

        var sorok = egyediSorok(ablak);

        if (sorok) {
            d.sor = sorok;
        }

        return d;
    }

    /* ---- Egyedi címke: az előnézet gépelés közben követi a szöveget ---- */

    var egyediIdo = 0;

    function egyediElonezet(ablak) {
        var sorok = egyediSorok(ablak);
        var kep = ablak.querySelector('.sdh-matrica__kep');

        if (!sorok || !kep) {
            return;
        }

        var fd = new FormData();

        fd.set('id', '0');
        fd.set('tipus', 'egyedi');
        fd.set('kulcs', '0');
        sorok.forEach(function (x) { fd.append('sor[]', x); });

        post('matrica_kep', fd).then(function (d) {
            if (d && d.png) {
                kep.src = 'data:image/png;base64,' + d.png;
            }
        }).catch(function () { /* az előnézet kényelmi szolgáltatás: hiba esetén a régi kép marad */ });
    }

    document.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target.closest ? esemeny.target.closest('[data-sdh-matrica-sor]') : null;

        if (!mezo) {
            return;
        }

        var ablak = mezo.closest('[data-sdh-matrica-ablak]');

        window.clearTimeout(egyediIdo);
        egyediIdo = window.setTimeout(function () { egyediElonezet(ablak); }, 350);
    });

    function jelez(ablak, szoveg, hiba) {
        var hely = ablak.querySelector('[data-sdh-matrica-allapot]');

        if (hely) {
            hely.textContent = szoveg;
            hely.classList.toggle('is-hiba', !!hiba);
        }
    }

    function nyomtat(ablak, gomb) {
        var d = adatok(ablak);

        if (!d.eszkoz.length) {
            jelez(ablak, 'Jelölj be legalább egy címkét.', true);

            return;
        }

        gomb.disabled = true;

        if (ablak.getAttribute('data-mod') === 'halozat') {
            jelez(ablak, 'Küldés a nyomtatóra…');

            var fd = new FormData();

            fd.set('id', d.id);
            fd.set('tipus', d.tipus);
            fd.set('peldany', d.peldany);
            d.eszkoz.forEach(function (k) { fd.append('eszkoz[]', k); });
            (d.sor || []).forEach(function (x) { fd.append('sor[]', x); });

            post('matrica_nyomtat', fd).then(function (v) {
                gomb.disabled = false;
                jelez(ablak, v.uzenet || 'Elküldve.');
            }).catch(function (h) {
                gomb.disabled = false;
                jelez(ablak, h.message, true);
            });

            return;
        }

        jelez(ablak, 'A címke készül…');

        fetch(cim('matrica_pdf', Object.assign({ nyomtat: '1' }, d)), { credentials: 'same-origin' })
            .then(function (v) {
                if (!v.ok) {
                    throw new Error('A címke nem készült el (' + v.status + ').');
                }

                return v.blob();
            })
            .then(nyomtatBlob)
            .then(function (r) {
                gomb.disabled = false;

                if (r.ok) {
                    jelez(ablak, 'A nyomtatás-ablakban válaszd a Brother nyomtatót.');
                } else {
                    jelez(ablak, 'A böngésző nem nyitotta meg a nyomtatást – nyisd meg a PDF-et, és onnan nyomtass.', true);
                    window.open(r.url, '_blank', 'noopener');
                }
            })
            .catch(function (h) {
                gomb.disabled = false;
                jelez(ablak, h.message, true);
            });
    }

    /* ---------------------------------------------------------------- */
    /* Beállítások › Címkenyomtatás                                       */
    /* ---------------------------------------------------------------- */

    // style.display: a mező saját display-szabálya a [hidden]-t felülírná.
    function mutat(el, igen) {
        el.hidden = !igen;
        el.style.display = igen ? '' : 'none';
    }

    /** A közös (nyomtató, tekercs) mezőktől függő láthatóság az egész űrlapon. */
    function lathatosag(urlap) {
        var mod = urlap.querySelector('#matrica_mod');
        var ck = urlap.querySelector('#matrica_cimke');
        var tipus = ck && ck.selectedOptions[0] ? ck.selectedOptions[0].getAttribute('data-tipus') : '';

        urlap.querySelectorAll('[data-sdh-matrica-halozat]').forEach(function (el) { mutat(el, !!mod && mod.value === 'halozat'); });
        urlap.querySelectorAll('[data-sdh-matrica-folyamatos]').forEach(function (el) { mutat(el, tipus === 'folyamatos'); });
        urlap.querySelectorAll('[data-sdh-matrica-egyedi]').forEach(function (el) { mutat(el, tipus === 'egyedi'); });
    }

    /** Egy sablon panelje: élő előnézet, sorrend, próbanyomat. Visszaadja a frissítőt. */
    function panelIndul(gyoker, urlap) {
        var idozito = null;
        var sorszam = 0;
        var sablon = gyoker.getAttribute('data-sablon') || 'rma';

        function adat() {
            var fd = new FormData(urlap);

            fd.set('sablon', sablon);

            return fd;
        }

        function elonezet() {
            var kep = gyoker.querySelector('[data-sdh-matrica-kep]');
            var jel = gyoker.querySelector('[data-sdh-matrica-jel]');
            var meret = gyoker.querySelector('[data-sdh-matrica-meret]');
            var en = ++sorszam;

            post('matrica_elonezet', adat()).then(function (d) {
                if (en !== sorszam) {
                    return;
                }

                if (d.png) {
                    kep.src = 'data:image/png;base64,' + d.png;
                    kep.hidden = false;
                }

                if (meret) {
                    meret.textContent = d.meret || '';
                }

                var uz = [];

                if (d.levagva) {
                    uz.push('Nem fér ki minden: kapcsolj ki egy adatot, válassz nagyobb címkét vagy kisebb betűt.');
                } else if (d.skala < 100) {
                    uz.push('A betű ' + d.skala + '%-ra kicsinyítve, hogy kiférjen.');
                }

                if (d.kozvetlen) {
                    uz.push(d.kozvetlen);
                }

                jel.textContent = uz.join(' ');
                jel.classList.toggle('is-hiba', !!(d.levagva || d.kozvetlen));
            }).catch(function (h) {
                if (en === sorszam) {
                    jel.textContent = h.message;
                    jel.classList.add('is-hiba');
                }
            });
        }

        function kesleltet() {
            window.clearTimeout(idozito);
            idozito = window.setTimeout(elonezet, 300);
        }

        gyoker.addEventListener('change', function (esemeny) {
            var sor = esemeny.target.closest('[data-sdh-matrica-mezo]');

            if (sor && esemeny.target.name && /\[be\]$/.test(esemeny.target.name)) {
                sor.classList.toggle('is-be', esemeny.target.checked);
            }

            kesleltet();
        });

        gyoker.addEventListener('input', function (esemeny) {
            if (esemeny.target.matches('input[type="text"], input[type="number"]')) {
                kesleltet();
            }
        });

        gyoker.addEventListener('click', function (esemeny) {
            var fel = esemeny.target.closest('[data-sdh-matrica-fel]');
            var le = esemeny.target.closest('[data-sdh-matrica-le]');

            if (fel || le) {
                esemeny.preventDefault();

                var sor = (fel || le).closest('[data-sdh-matrica-mezo]');

                if (fel && sor.previousElementSibling) {
                    sor.parentNode.insertBefore(sor, sor.previousElementSibling);
                } else if (le && sor.nextElementSibling) {
                    sor.parentNode.insertBefore(sor.nextElementSibling, sor);
                }

                (fel || le).focus();
                kesleltet();

                return;
            }

            var proba = esemeny.target.closest('[data-sdh-matrica-proba]');

            if (proba) {
                esemeny.preventDefault();

                var jel = gyoker.querySelector('[data-sdh-matrica-jel]');

                proba.disabled = true;

                post('matrica_proba', adat()).then(function (d) {
                    proba.disabled = false;

                    if (d.pdf) {
                        nyomtatBlob(b64Blob(d.pdf, 'application/pdf')).then(function (r) {
                            if (!r.ok) {
                                window.open(r.url, '_blank', 'noopener');
                            }
                        });
                        jel.textContent = 'A próbacímke PDF-je megnyílt – a nyomtatás-ablakban válaszd a Brother nyomtatót.';
                    } else {
                        jel.textContent = d.uzenet || 'Elküldve.';
                    }

                    jel.classList.remove('is-hiba');
                }).catch(function (h) {
                    proba.disabled = false;
                    jel.textContent = h.message;
                    jel.classList.add('is-hiba');
                });
            }
        });

        elonezet();

        return kesleltet;
    }

    function beallitasIndul(keret) {
        var urlap = keret.closest('form');

        if (!urlap) {
            return;
        }

        var frissitok = [];

        keret.querySelectorAll('[data-sdh-matrica-beall]').forEach(function (gyoker) {
            frissitok.push(panelIndul(gyoker, urlap));
        });

        // A közös mezők (nyomtató, tekercs) mindegyik sablon előnézetét érintik.
        var kozos = keret.querySelector('[data-sdh-matrica-kozos]');

        if (kozos) {
            ['change', 'input'].forEach(function (tipus) {
                kozos.addEventListener(tipus, function (esemeny) {
                    if (tipus === 'input' && !esemeny.target.matches('input[type="text"], input[type="number"]')) {
                        return;
                    }

                    lathatosag(urlap);
                    frissitok.forEach(function (f) { f(); });
                });
            });
        }

        // Sablonválasztó fülek.
        keret.addEventListener('click', function (esemeny) {
            var gomb = esemeny.target.closest('[data-sdh-matrica-sablon-gomb]');

            if (!gomb) {
                return;
            }

            esemeny.preventDefault();

            var s = gomb.getAttribute('data-sdh-matrica-sablon-gomb');

            keret.querySelectorAll('[data-sdh-matrica-sablon-gomb]').forEach(function (g) {
                var be = g === gomb;

                g.classList.toggle('is-aktiv', be);
                g.setAttribute('aria-selected', be ? 'true' : 'false');
            });

            keret.querySelectorAll('[data-sdh-matrica-beall]').forEach(function (p) {
                mutat(p, p.getAttribute('data-sablon') === s);
            });

            lathatosag(urlap);
        });

        lathatosag(urlap);
    }

    function indul() {
        document.querySelectorAll('[data-sdh-matrica-beallitas]').forEach(beallitasIndul);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', indul);
    } else {
        indul();
    }
}());
