/**
 * Párhuzamos szerkesztés védelme (0.70.0, H1).
 *
 * Két feladata van:
 *
 *  1. „Közben módosította": ha a szerver ütközést jelez (más belépett felhasználó írt
 *     ugyanarra a lapra / ügyfélre / eszközre a betöltés óta), az űrlap tetején sáv jelenik
 *     meg két gombbal. Mindkettő kétlépcsős (nincs böngészős megerősítő ablak):
 *       – „Frissítés": a legfrissebb állapotot tölti be, a saját, még nem mentett változtatások elvesznek;
 *       – „Az enyémet mentsd": a saját változtatás felülírja a másikét.
 *     Sikeres mentés után az űrlap új alapot kap (szerk_naplo), a sáv eltűnik.
 *
 *  3. Zárolás (0.71.0): amit másik ablak szerkeszt, azt a szerver nem engedi megnyitni; helyette zárolás-doboz jön
 *     (`data-sdh-zar`), benne a felülbíráló kód mezője. A kód külön POST-kérésben ellenőrződik (nem kerül URL-be),
 *     siker után a rekord újra megnyílik. Az ablakot egy lapazonosító (fiok) különbözteti meg: a lapon belül állandó,
 *     újratöltés után is megmarad (sessionStorage).
 *
 *  2. „X is szerkeszti": a nyitott űrlapok a közös pulzussal (csapat.js, 30 mp) jelentkeznek;
 *     a válaszban (`szerkeszti`) megjön, ki más tartja még nyitva ugyanazt. A jelzés az
 *     űrlap láblécében látszik. Megnyitáskor és bezáráskor azonnal pulzus indul.
 *
 * Minden szöveg textContent-tel kerül ki (a nevek adatbázisból jönnek).
 */
(function () {
    'use strict';

    if (window.SDH_UTKOZES) {
        return;
    }

    var JELZO_OSZTALY = 'sdh-szerk-jelzo';

    /** A böngészőlap (ablak) azonosítója: kisbetű + szám, 8–48 karakter. */
    var fiokAzon = '';

    function fiok() {
        if (fiokAzon) {
            return fiokAzon;
        }

        try {
            fiokAzon = String(window.sessionStorage.getItem('sdhSzerkFiok') || '');
        } catch (x) { fiokAzon = ''; }

        if (!/^[a-z0-9]{8,48}$/.test(fiokAzon)) {
            fiokAzon = '';

            for (var i = 0; i < 3; i++) {
                fiokAzon += Math.random().toString(36).slice(2, 10);
            }

            fiokAzon = (fiokAzon + Date.now().toString(36)).replace(/[^a-z0-9]/g, '').slice(0, 40);

            try {
                window.sessionStorage.setItem('sdhSzerkFiok', fiokAzon);
            } catch (x) { /* a lapon belül így is állandó */ }
        }

        return fiokAzon;
    }
    var SAV_OSZTALY = 'sdh-utkozes-sav';

    /** A nyitott, védett űrlapok mezői (a láthatatlan / bezárt popupé nem számít). */
    function mezok() {
        return Array.prototype.filter.call(document.querySelectorAll('input[data-sdh-szerk]'), function (m) {
            var ablak = m.closest ? m.closest('dialog') : null;

            return document.body.contains(m) && (!ablak || ablak.open);
        });
    }

    function kulcsok() {
        var ki = [];

        mezok().forEach(function (m) {
            var k = m.getAttribute('data-sdh-szerk');

            if (k && ki.indexOf(k) < 0) {
                ki.push(k);
            }
        });

        return ki;
    }

    var jelentett = [];

    /** A pulzus kérésének kiegészítése: mi van nyitva most, és mi záródott be az előző pulzus óta. */
    function pulzusAdat() {
        var most = kulcsok();
        var el = jelentett.filter(function (k) { return most.indexOf(k) < 0; });
        var adat = { szerk_fiok: fiok() };

        if (most.length) {
            adat.szerk = most.join(',');
        }

        if (el.length) {
            adat.szerk_el = el.join(',');
        }

        jelentett = most;

        return adat;
    }

    function pulzusKer() {
        if (window.SDH_CSAPAT && typeof window.SDH_CSAPAT.pulzus === 'function') {
            window.SDH_CSAPAT.pulzus();
        }
    }

    /* ---- „X is szerkeszti" ------------------------------------------------ */

    function nevekSzoveg(nevek) {
        if (nevek.length === 1) {
            return nevek[0] + ' is szerkeszti';
        }

        return nevek.join(', ') + ' is szerkeszti';
    }

    function jelzoHely(urlap) {
        return urlap.querySelector('.sdh-urlap__lablec') || urlap;
    }

    function jelzoBeallit(urlap, nevek) {
        var hely = jelzoHely(urlap);
        var j = urlap.querySelector('.' + JELZO_OSZTALY);

        if (!nevek || !nevek.length) {
            if (j) {
                j.remove();
            }

            return;
        }

        if (!j) {
            j = document.createElement('span');
            j.className = JELZO_OSZTALY;
            j.setAttribute('role', 'status');
            j.setAttribute('aria-live', 'polite');
            hely.insertBefore(j, hely.firstChild);
        }

        j.textContent = nevekSzoveg(nevek);
    }

    document.addEventListener('sdh:pulzus', function (ev) {
        var d = (ev && ev.detail) || {};
        var kik = d.szerkeszti || {};

        mezok().forEach(function (m) {
            var urlap = m.form || (m.closest && m.closest('form'));

            if (urlap) {
                jelzoBeallit(urlap, kik[m.getAttribute('data-sdh-szerk')] || []);
            }
        });
    });

    // Megnyitáskor és bezáráskor azonnal pulzus: a másik gépen hamar látszik, és hamar eltűnik a jelzés.
    document.addEventListener('sdh:urlap-betoltve', function () {
        window.setTimeout(function () {
            if (mezok().length) {
                pulzusKer();
            }
        }, 60);
    });

    document.addEventListener('close', function (ev) {
        if (ev.target && ev.target.tagName === 'DIALOG' && jelentett.length) {
            window.setTimeout(pulzusKer, 120);
        }
    }, true);

    window.addEventListener('pagehide', function () {
        // Oldalelhagyáskor a nyitott űrlapok jelenléte nem marad ott a lejáratig (a pulzus ugyanazt a végpontot használja).
        var most = kulcsok();

        if (!most.length || !window.SDH_MUHELY || !window.SDH_MUHELY.ajax || !navigator.sendBeacon) {
            return;
        }

        try {
            var fd = new FormData();

            fd.set('action', 'sdh_muhely_pulzus');
            fd.set('_wpnonce', window.SDH_MUHELY.nonce || '');
            fd.set('szerk_fiok', fiok());
            fd.set('szerk_el', most.join(','));
            navigator.sendBeacon(window.SDH_MUHELY.ajax, fd);
        } catch (x) { /* a jelenlét magától lejár */ }
    });

    /* ---- „Közben módosította" ---------------------------------------------- */

    /** Kétlépcsős gomb: az első kattintás a „biztosan?" feliratot mutatja, a második fut le. */
    function ketlepcsosGomb(felirat, biztos, osztaly, vegre) {
        var g = document.createElement('button');
        var var_ = false;
        var idozito = 0;

        g.type = 'button';
        g.className = 'sdh-gomb ' + (osztaly || '');
        g.textContent = felirat;

        g.addEventListener('click', function () {
            if (!var_) {
                var_ = true;
                g.textContent = biztos;
                g.setAttribute('data-sdh-biztos', '1');
                window.clearTimeout(idozito);
                idozito = window.setTimeout(function () {
                    var_ = false;
                    g.textContent = felirat;
                    g.removeAttribute('data-sdh-biztos');
                }, 5000);

                return;
            }

            window.clearTimeout(idozito);
            vegre();
        });

        return g;
    }

    function savEltavolit(urlap) {
        var s = urlap.querySelector('.' + SAV_OSZTALY);

        if (s) {
            s.remove();
        }
    }

    function felulirMezo(urlap) {
        return urlap.querySelector('input[name="szerk_felulir"]');
    }

    document.addEventListener('sdh:utkozes', function (ev) {
        var d = (ev && ev.detail) || {};
        var urlap = d.urlap;
        var adat = d.adat || {};

        if (!urlap || !document.body.contains(urlap)) {
            return;
        }

        // A sáv már áll: csak a szöveg frissül (az automatikus mentés minden változtatásnál újra jelezhet).
        var sav = urlap.querySelector('.' + SAV_OSZTALY);

        if (!sav) {
            sav = document.createElement('div');
            sav.className = 'sdh-uzenet sdh-uzenet--figyelem ' + SAV_OSZTALY;
            sav.setAttribute('role', 'alert');

            var szoveg = document.createElement('p');

            szoveg.className = 'sdh-utkozes-sav__szoveg';
            sav.appendChild(szoveg);

            var gombok = document.createElement('div');

            gombok.className = 'sdh-utkozes-sav__gombok';

            gombok.appendChild(ketlepcsosGomb(
                'Frissítés a legújabbra',
                'Biztosan? A változtatásaim elvesznek',
                '',
                function () {
                    if (typeof d.frissit === 'function') {
                        d.frissit();
                    }
                }
            ));

            gombok.appendChild(ketlepcsosGomb(
                'Az enyémet mentsd',
                'Biztosan? A másik módosítása felülíródik',
                'sdh-gomb--elsodleges',
                function () {
                    var m = felulirMezo(urlap);

                    if (m) {
                        m.value = '1';
                    }

                    savEltavolit(urlap);

                    // Újra a megszokott úton megy: gombos beküldés vagy az automatikus mentés változásra indulása.
                    if (d.forras === 'kuldes' && typeof urlap.requestSubmit === 'function') {
                        urlap.requestSubmit();
                    } else {
                        urlap.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            ));

            sav.appendChild(gombok);
            urlap.insertBefore(sav, urlap.firstChild);
        }

        sav.querySelector('.sdh-utkozes-sav__szoveg').textContent = String(adat.uzenet || 'Közben más módosította ezt a rekordot.');

        if (sav.scrollIntoView) {
            sav.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    });

    // Sikeres (automatikus) mentés után: új alap a következő ellenőrzéshez, a felülírás jelzője lekapcsol.
    document.addEventListener('sdh:automentve', function (ev) {
        var d = (ev && ev.detail) || {};
        var urlap = d.urlap;

        if (!urlap || !document.body.contains(urlap)) {
            return;
        }

        var alap = d.adat && d.adat.szerk_naplo;
        var m = urlap.querySelector('input[name="szerk_naplo"]');

        if (m && alap !== undefined && alap !== null) {
            m.value = String(alap);
        }

        var f = felulirMezo(urlap);

        if (f) {
            f.value = '0';
        }

        savEltavolit(urlap);
    });

    /* ---- Zárolás-doboz ------------------------------------------------------ */

    function zarKotes(doboz) {
        if (doboz.getAttribute('data-sdh-zar-kotve') === '1') {
            return;
        }

        doboz.setAttribute('data-sdh-zar-kotve', '1');

        var kulcs = doboz.getAttribute('data-sdh-zar') || '';
        var mezo = doboz.querySelector('[data-sdh-zar-kod]');
        var hiba = doboz.querySelector('[data-sdh-zar-hiba]');
        var nyit = doboz.querySelector('[data-sdh-zar-nyit]');
        var bezar = doboz.querySelector('[data-sdh-zar-bezar]');
        var foglalt = false;

        function hibaKi(szoveg) {
            if (hiba) {
                hiba.textContent = szoveg;
                hiba.hidden = !szoveg;
            }
        }

        function probal() {
            var B = window.SDH_MUHELY || {};

            if (foglalt || !mezo || !B.ajax) {
                return;
            }

            if (!mezo.value) {
                hibaKi('Add meg a kódot.');

                return;
            }

            foglalt = true;
            hibaKi('');

            var fd = new FormData();

            fd.set('action', 'sdh_muhely_szerk_felulir');
            fd.set('_wpnonce', B.nonce || '');
            fd.set('szerk_fiok', fiok());
            fd.set('kulcs', kulcs);
            fd.set('kod', mezo.value);

            fetch(B.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (v) { return v.json(); })
                .then(function (j) {
                    foglalt = false;

                    if (!j || !j.success) {
                        mezo.value = '';
                        hibaKi((j && j.data && j.data.uzenet) || 'A kód nem fogadható el.');

                        return;
                    }

                    // Az engedély egyszeri és rövid életű: azonnal újra megnyitjuk ugyanazt a rekordot.
                    if (window.SDH_MUHELY_APP && window.SDH_MUHELY_APP.ujraNyit) {
                        window.SDH_MUHELY_APP.ujraNyit(doboz);
                    }
                })
                .catch(function () {
                    foglalt = false;
                    hibaKi('A kód ellenőrzése nem sikerült. Próbáld újra.');
                });
        }

        if (nyit) {
            nyit.addEventListener('click', probal);
        }

        if (mezo) {
            mezo.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    probal();
                }
            });
            window.setTimeout(function () { mezo.focus(); }, 30);
        }

        if (bezar) {
            bezar.addEventListener('click', function () {
                if (window.SDH_MUHELY_APP && window.SDH_MUHELY_APP.sajatSzint && window.SDH_MUHELY_APP.bezar) {
                    var n = window.SDH_MUHELY_APP.sajatSzint(doboz);

                    window.SDH_MUHELY_APP.bezar(n >= 0 ? n : 0);
                }
            });
        }
    }

    document.addEventListener('sdh:urlap-betoltve', function (ev) {
        var torzs = ev && ev.detail && ev.detail.torzs;
        var doboz = torzs && torzs.querySelector ? torzs.querySelector('[data-sdh-zar]') : null;

        if (doboz) {
            zarKotes(doboz);
        }
    });

    window.SDH_UTKOZES = {
        pulzusAdat: pulzusAdat,
        nyitott: kulcsok,
        fiok: fiok
    };
}());
