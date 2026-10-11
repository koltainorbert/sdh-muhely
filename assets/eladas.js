/**
 * SDH Műhely – eladás: napi árukiadás és rendelések (0.45).
 *
 * Az űrlap a munkalapé (app.js), a fizetés ablaka a pénztáré (penztar.js);
 * ez a fájl azt adja hozzá, ami az eladásé:
 *
 *  - „Rögzítés és fizetés": az új árukiadás / rendelés mentése után rögtön a
 *    fizetés ablaka nyílik, a pult így két kattintás.
 *  - Az Áttekintés „+ Új termék" és „+ Új szolgáltatás" gombja: mentés után az
 *    Áttekintésen maradunk (nem visz át a termék- / szolgáltatáslistára).
 *  - Helyszínek és beszállítók szerkesztése popupban (új sor, a rendelés
 *    űrlapján a beszállító-lista frissítése).
 *  - Rendelés: „Megjött – értesítés" az ügyfélnek.
 *
 * Eseménydelegálással dolgozik: a popupok tartalma AJAX-szal cserélődik.
 */
(function () {
    'use strict';

    function app() {
        return window.SDH_MUHELY_APP || null;
    }

    /* ---------------------------------------------------------------- */
    /* Rövid visszajelzés (a pénztár értesítőjének kinézetével)          */
    /* ---------------------------------------------------------------- */

    var toastDoboz = null;

    function toast(szoveg, tipus) {
        if (!szoveg) {
            return;
        }

        if (!toastDoboz || !document.body.contains(toastDoboz)) {
            toastDoboz = document.querySelector('.sdh-pt-toastok');

            if (!toastDoboz) {
                toastDoboz = document.createElement('div');
                toastDoboz.className = 'sdh-pt-toastok';
                toastDoboz.setAttribute('aria-live', 'polite');
                document.body.appendChild(toastDoboz);
            }
        }

        var t = document.createElement('div');

        t.className = 'sdh-pt-toast' + (tipus ? ' sdh-pt-toast--' + tipus : '');
        t.textContent = szoveg;
        toastDoboz.appendChild(t);

        window.setTimeout(function () {
            t.classList.add('is-el');
            window.setTimeout(function () { t.remove(); }, 400);
        }, tipus === 'hiba' ? 8000 : 4500);
    }

    /** A rács (Áttekintés) helyben frissül; a saját listaoldal újratöltéssel. */
    function listaOldal() {
        return document.querySelector('[data-sdh-eladas-lista]');
    }

    /* ---------------------------------------------------------------- */
    /* Kattintások                                                       */
    /* ---------------------------------------------------------------- */

    // Az Áttekintésről indított termék- / szolgáltatásfelvitel: mentés után maradunk.
    var maradUzenet = '';

    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;

        if (!cel || !cel.closest) {
            return;
        }

        var marad = cel.closest('[data-sdh-marad]');

        if (marad) {
            maradUzenet = marad.getAttribute('data-sdh-marad') || 'Elmentve.';
        } else if (cel.closest('[data-sdh-urlap]') && !cel.closest('dialog')) {
            maradUzenet = '';
        }

        // Az ügyfél adatlapján egy munkalap / rendelés / vásárlás sora: az ügyfél ablaka fölött nyílik.
        var lapNyit = cel.closest('[data-sdh-lap-nyit]');

        if (lapNyit && app()) {
            esemeny.preventDefault();
            app().nyit('munkalapok', lapNyit.getAttribute('data-sdh-lap-nyit'), {
                szint: Math.min(Math.max(0, app().sajatSzint(lapNyit)) + 1, 3)
            });

            return;
        }

        // Új eladás lábléce: melyik gombbal mentik (fizetéssel vagy anélkül).
        var ment = cel.closest('[data-sdh-eladas-ment]');

        if (ment) {
            var jelzo = ment.form && ment.form.querySelector('[data-sdh-utana-fizet]');

            if (jelzo) {
                jelzo.value = ment.getAttribute('data-sdh-eladas-ment') === '1' ? '1' : '';
            }

            return; // a beküldés megy tovább
        }

        // Törzs-szerkesztő (helyszínek, beszállítók): új sor.
        var ujSor = cel.closest('[data-sdh-torzs-uj]');

        if (ujSor) {
            esemeny.preventDefault();

            var urlap = ujSor.closest('form');
            var sorok = urlap && urlap.querySelector('[data-sdh-torzs-sorok]');
            var sablon = urlap && urlap.querySelector('[data-sdh-torzs-sablon]');

            if (sorok && sablon) {
                var n = parseInt(sorok.getAttribute('data-kovetkezo') || '0', 10) || 0;
                var tarto = document.createElement('div');

                // Az első (üres) sor a 0. indexet viseli: az új mindig nagyobbat kap.
                n = Math.max(n, sorok.children.length);
                tarto.innerHTML = sablon.innerHTML.replace(/__I__/g, String(n));
                sorok.setAttribute('data-kovetkezo', String(n + 1));

                while (tarto.firstElementChild) {
                    sorok.appendChild(tarto.firstElementChild);
                }

                var mezo = sorok.lastElementChild && sorok.lastElementChild.querySelector('input[type="text"]');

                if (mezo) {
                    mezo.focus();
                }
            }

            return;
        }

        // Rendelés űrlapja: a beszállítók szerkesztése fölötte, a lista helyben frissül.
        var besz = cel.closest('[data-sdh-beszallitok]');

        if (besz && app()) {
            esemeny.preventDefault();

            var valaszto = besz.closest('form') && besz.closest('form').querySelector('[data-sdh-beszallito-valaszto]');
            var szint = Math.max(0, app().sajatSzint(besz));

            app().nyit('beszallitok', '0', {
                szint: Math.min(szint + 1, 3),
                siker: function (adat) {
                    beszallitoFrissit(valaszto, (adat && adat.lista) || {});
                    toast((adat && adat.uzenet) || 'Elmentve.', 'siker');
                }
            });

            return;
        }

        // Rendelés: értesítés, hogy megjött az áru.
        var ertesit = cel.closest('[data-sdh-rendeles-ertesit]');

        if (ertesit && app()) {
            esemeny.preventDefault();

            var eUrlap = ertesit.closest('form');
            var eSzint = Math.max(0, app().sajatSzint(ertesit));

            app().nyit('rendelesertesit', ertesit.getAttribute('data-sdh-rendeles-ertesit'), {
                szint: Math.min(eSzint + 1, 3),
                siker: function (adat) {
                    toast((adat && adat.uzenet) || 'Értesítés elküldve.', adat && adat.email === false && /nem ment el/.test(adat.uzenet || '') ? 'hiba' : 'siker');
                    ertesitesUtan(eUrlap, ertesit);
                }
            });
        }
    });

    /* ---------------------------------------------------------------- */
    /* Rendelések listája: a rendelés útja a sorból állítható            */
    /* ---------------------------------------------------------------- */

    document.addEventListener('change', function (esemeny) {
        var valaszto = esemeny.target && esemeny.target.closest ? esemeny.target.closest('select[data-sdh-rend-allapot]') : null;

        if (!valaszto) {
            return;
        }

        var B = window.SDH_MUHELY || {};
        var hely = valaszto.closest('[data-sdh-allapot-hely]');
        var elozo = valaszto.getAttribute('data-elozo') || '';
        var adatok = new FormData();

        adatok.set('action', 'sdh_muhely_rendelesek_allapot');
        adatok.set('_wpnonce', B.nonce || '');
        adatok.set('id', valaszto.getAttribute('data-id') || '0');
        adatok.set('allapot', valaszto.value);

        valaszto.disabled = true;

        function vissza(uzenet) {
            valaszto.value = elozo;
            valaszto.disabled = false;
            toast(uzenet, 'hiba');
        }

        fetch(new URL(B.ajax, window.location.origin).toString(), { method: 'POST', body: adatok, credentials: 'same-origin' })
            .then(function (valasz) { return valasz.json(); })
            .then(function (valasz) {
                if (!valasz || !valasz.success) {
                    vissza((valasz && valasz.data && valasz.data.uzenet) || 'A rendelés állapota nem módosult.');

                    return;
                }

                var adat = valasz.data;

                if (hely) {
                    Array.prototype.slice.call(hely.classList).forEach(function (nev) {
                        if (nev.indexOf('sdh-allapot--') === 0 && nev !== 'sdh-allapot--valaszthato') {
                            hely.classList.remove(nev);
                        }
                    });

                    hely.classList.add('sdh-allapot--' + adat.szin);
                }

                valaszto.setAttribute('data-elozo', adat.kulcs);
                valaszto.disabled = false;

                // Az átadott és kifizetett rendelés magától lezárul: a sor ezt is mutassa.
                var sor = valaszto.closest('tr');
                var lap = sor && sor.querySelector('[data-sdh-lap-allapot]');

                if (sor) {
                    sor.classList.toggle('sdh-sor--zart', !!adat.zart);
                }

                if (lap) {
                    lap.textContent = adat.lap_allapot || '';
                    lap.hidden = !adat.lap_allapot;
                }

                // A menü jelvénye: megérkezett, de az ügyfél még nem tud róla.
                Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-jelveny="rendelesek"]'), function (jel) {
                    jel.setAttribute('data-db', String(adat.erkezett));
                    jel.textContent = adat.erkezett > 999 ? '999+' : String(adat.erkezett);
                    jel.hidden = !(adat.erkezett > 0);
                });

                toast('A rendelés állapota: ' + adat.nev + (adat.most_zart ? ' – a rendelés lezárult.' : '.'), 'siker');
                document.dispatchEvent(new CustomEvent('sdh:allapot', { detail: { id: String(adat.id), adat: adat } }));
            })
            .catch(function () {
                vissza('Nem sikerült elérni a szervert, a rendelés állapota nem módosult.');
            });
    });

    /** A beszállító-választó újraépítése a mentett listából; a választás megmarad. */
    function beszallitoFrissit(valaszto, lista) {
        if (!valaszto) {
            return;
        }

        var regi = valaszto.value;
        var voltak = {};

        Array.prototype.forEach.call(valaszto.options, function (o) { voltak[o.value] = true; });

        var ujak = Object.keys(lista).filter(function (id) { return !voltak[id]; });

        valaszto.innerHTML = '';

        var ures = document.createElement('option');

        ures.value = '0';
        ures.textContent = '— válassz beszállítót —';
        valaszto.appendChild(ures);

        Object.keys(lista).forEach(function (id) {
            var o = document.createElement('option');

            o.value = id;
            o.textContent = lista[id];
            valaszto.appendChild(o);
        });

        // Ha még nem volt választás, és most vettek fel egyet: az lesz kiválasztva.
        valaszto.value = lista[regi] ? regi : (regi === '0' && ujak.length === 1 ? ujak[0] : '0');
    }

    /** Az értesítés a szerveren átírta a rendelés állapotát: a nyitott űrlap kövesse (különben a Mentés visszaírná). */
    function ertesitesUtan(urlap, gomb) {
        if (!urlap) {
            return;
        }

        var allapot = urlap.querySelector('select[name="rend_allapot"]');

        if (allapot && allapot.value !== 'atadva') {
            allapot.value = 'ertesitve';
        }

        gomb.textContent = 'Újra értesít';

        // Az értesítő a lap üzenetei közé került: a csevegő (RMA fül) most frissüljön.
        document.dispatchEvent(new CustomEvent('sdh:uzenet-frissit'));

        var sor = gomb.closest('.sdh-eladas-rendeles');
        var info = sor && sor.querySelector('.sdh-eladas-rendeles__info');

        if (sor && !info) {
            info = document.createElement('p');
            info.className = 'sdh-eladas-rendeles__info';
            sor.appendChild(info);
        }

        if (info) {
            info.textContent = 'Az ügyfél értesítve: most.';
        }
    }

    /* ---------------------------------------------------------------- */
    /* Mentés után                                                       */
    /* ---------------------------------------------------------------- */

    // A saját listaoldalon a fizetés ablakának bezárása után ide töltünk újra.
    var fuggoVissza = '';

    document.addEventListener('sdh:mentve', function (esemeny) {
        var d = esemeny.detail || {};
        var akcio = d.action || '';
        var adat = d.adat || {};

        // Áttekintés: az új termék / szolgáltatás után maradunk.
        if (maradUzenet && /_(termekek|szolgaltatasok)_ment$/.test(akcio)) {
            esemeny.preventDefault();
            toast(maradUzenet, 'siker');
            maradUzenet = '';

            return;
        }

        // Helyszínek / beszállítók a Beállítások oldaláról: a félig kitöltött beállítások ne vesszenek el.
        if (/_(helyszinek|beszallitok)_ment$/.test(akcio) && document.querySelector('input[name="eladas_jelen"]')) {
            esemeny.preventDefault();
            toast(adat.uzenet || 'Elmentve.', 'siker');

            return;
        }

        if (!/_munkalapok_ment$/.test(akcio) || !adat.fajta || adat.fajta === 'javitas') {
            return;
        }

        // Új eladás „Rögzítés és fizetés" gombbal: a fizetés ablaka jön.
        if (adat.utana_fizet && adat.fizetendo > 0.5 && app() && !d.forras) {
            var lista = listaOldal();

            esemeny.preventDefault(); // a popup bezárul, nincs átirányítás

            window.setTimeout(function () {
                // Itt már lezajlott az űrlap ablakának bezárása: csak a fizetés ablakáé számít.
                if (lista) {
                    fuggoVissza = adat.vissza || window.location.href;
                }

                app().nyit('penztarfizetes', String(adat.id), {
                    siker: function (valasz) {
                        toast((valasz && valasz.uzenet) || 'Fizetés rögzítve.', 'siker');
                        document.dispatchEvent(new CustomEvent('sdh:penztar-valtozott'));
                    }
                });
            }, 60);
        }
    });

    // A fizetés ablaka bezárult (fizetve vagy mégsem): a listaoldal mutassa az új sort.
    document.addEventListener('close', function (esemeny) {
        var d = esemeny.target;

        if (d && d.tagName === 'DIALOG') {
            if (d.getAttribute('data-sdh-modul') !== 'penztarfizetes') {
                // Bármelyik más ablak bezárása: az Áttekintésről indított felvitel jelzője lejár.
                if (/^(termekek|szolgaltatasok)$/.test(d.getAttribute('data-sdh-modul') || '')) {
                    maradUzenet = '';
                }

                return;
            }

            if (fuggoVissza) {
                var hova = fuggoVissza;

                fuggoVissza = '';
                window.setTimeout(function () { window.location.href = hova; }, 250);
            }
        }
    }, true);
}());
