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

        var indito = esemeny.target.closest('[data-sdh-urlap]');

        if (!indito) {
            return;
        }

        esemeny.preventDefault();
        nyit(indito.dataset.sdhUrlap, indito.dataset.sdhId || '0');
    });
}());
