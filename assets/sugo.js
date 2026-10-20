/**
 * Súgó (0.51).
 *
 *  - Keresés gépelés közben: a legjobb találatok a mező alatt (nyilakkal
 *    léptethető, Enter megnyitja; Enter kijelölés nélkül a teljes találati lista).
 *  - „Segített?" – kérdés–válaszonként egy szavazat (átírható).
 *  - Közvetlen hivatkozás egy kérdésre (#gyik-…): magától lenyílik.
 *  - A tartalomjegyzékben kiemeli, melyik szakasznál jár az olvasó.
 *  - Nyomtatás: nyomtatáskor minden kérdés–válasz lenyílik.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};
    var gyoker = document.querySelector('[data-sdh-sugo]');

    if (!gyoker) {
        return;
    }

    function e(szoveg) {
        var d = document.createElement('div');

        d.textContent = String(szoveg == null ? '' : szoveg);

        return d.innerHTML;
    }

    function kuld(akcio, adat, post) {
        var cim = new URL(B.ajax, window.location.origin);
        var torzs = null;

        adat = adat || {};
        adat.action = 'sdh_muhely_' + akcio;
        adat._wpnonce = B.nonce || '';
        adat.kontextus = B.kontextus || 'admin';

        if (post) {
            torzs = new URLSearchParams();
            Object.keys(adat).forEach(function (k) { torzs.set(k, adat[k]); });
        } else {
            Object.keys(adat).forEach(function (k) { cim.searchParams.set(k, adat[k]); });
        }

        return fetch(cim.toString(), { method: post ? 'POST' : 'GET', credentials: 'same-origin', body: torzs })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                if (!v || !v.success) {
                    throw new Error((v && v.data && v.data.uzenet) || 'A kérés nem sikerült.');
                }

                return v.data;
            });
    }

    /* ---------------------------------------------------------------- */
    /* Keresés gépelés közben                                            */
    /* ---------------------------------------------------------------- */

    var urlap = gyoker.querySelector('[data-sdh-sugo-kereso]');
    var mezo = gyoker.querySelector('[data-sdh-sugo-mezo]');
    var lista = gyoker.querySelector('[data-sdh-sugo-lista]');
    var ido = 0;
    var sorszam = 0;
    var kijelolt = -1;

    function listaZar() {
        if (lista) {
            lista.hidden = true;
            lista.innerHTML = '';
        }

        kijelolt = -1;
    }

    function kijelol(n) {
        var elemek = lista.querySelectorAll('.sdh-sugo-kereso__elem');

        if (!elemek.length) {
            return;
        }

        kijelolt = (n + elemek.length) % elemek.length;

        Array.prototype.forEach.call(elemek, function (el, i) {
            el.classList.toggle('is-kijelolt', i === kijelolt);
        });
    }

    function keres() {
        var q = mezo.value.trim();
        var en = ++sorszam;

        if (q.length < 3) {
            listaZar();

            return;
        }

        kuld('sugo_keres', { q: q }).then(function (adat) {
            // Közben tovább gépelt: a régi válasz már nem kell.
            if (en !== sorszam || mezo.value.trim() !== q) {
                return;
            }

            var html = (adat.talalatok || []).map(function (t) {
                return '<a class="sdh-sugo-kereso__elem" href="' + e(t.url) + '"><small>' + e(t.tipus) + '<br>' + e(t.fejezet) + '</small>' +
                    '<strong>' + e(t.cim) + '</strong><span>' + e(t.reszlet) + '</span></a>';
            }).join('');

            lista.innerHTML = html
                ? html + '<a class="sdh-sugo-kereso__mind" href="' + e(adat.mind) + '">Minden találat erre: „' + e(q) + '”</a>'
                : '<p class="sdh-sugo-kereso__ures">Erre nincs kész válasz – nyomj Entert, és megkérdezheted az asszisztenst.</p>';
            lista.hidden = false;
            kijelolt = -1;
        }).catch(function () {
            listaZar();
        });
    }

    if (mezo && lista) {
        mezo.addEventListener('input', function () {
            window.clearTimeout(ido);
            ido = window.setTimeout(keres, 220);
        });

        mezo.addEventListener('keydown', function (ev) {
            if (lista.hidden) {
                return;
            }

            if (ev.key === 'ArrowDown') {
                ev.preventDefault();
                kijelol(kijelolt + 1);
            } else if (ev.key === 'ArrowUp') {
                ev.preventDefault();
                kijelol(kijelolt - 1);
            } else if (ev.key === 'Enter' && kijelolt >= 0) {
                var el = lista.querySelectorAll('.sdh-sugo-kereso__elem')[kijelolt];

                if (el) {
                    ev.preventDefault();
                    window.location.href = el.href;
                }
            } else if (ev.key === 'Escape') {
                listaZar();
            }
        });

        document.addEventListener('click', function (ev) {
            if (urlap && !urlap.contains(ev.target)) {
                listaZar();
            }
        });
    }

    /* ---------------------------------------------------------------- */
    /* „Segített?"                                                        */
    /* ---------------------------------------------------------------- */

    gyoker.addEventListener('click', function (ev) {
        var gomb = ev.target.closest && ev.target.closest('.sdh-sugo-kv__szavaz');

        if (!gomb) {
            return;
        }

        var lab = gomb.closest('[data-sdh-sugo-ertekel]');

        if (!lab || lab.sdhFut) {
            return;
        }

        lab.sdhFut = true;

        kuld('sugo_ertekel', { id: lab.getAttribute('data-sdh-sugo-ertekel'), ertek: gomb.getAttribute('data-ertek') }, true)
            .then(function (adat) {
                Array.prototype.forEach.call(lab.querySelectorAll('.sdh-sugo-kv__szavaz'), function (g) {
                    var aktiv = g.getAttribute('data-ertek') === String(adat.ertek);

                    g.classList.toggle('is-aktiv', aktiv);
                    g.setAttribute('aria-pressed', aktiv ? 'true' : 'false');
                });

                var jo = lab.querySelector('[data-sdh-sugo-jo]');
                var nem = lab.querySelector('[data-sdh-sugo-nem]');

                if (jo) { jo.textContent = adat.jo > 0 ? String(adat.jo) : ''; }
                if (nem) { nem.textContent = adat.nem > 0 ? String(adat.nem) : ''; }

                // Nem segített: az asszisztens felajánlja, hogy tovább kérdezhetsz.
                if (String(adat.ertek) === '-1' && window.SDH_AI_API && window.SDH_AI_API.buborek) {
                    var kerdes = lab.closest('.sdh-sugo-kv').querySelector('.sdh-sugo-kv__kerdes');

                    window.SDH_AI_API.buborek('Kár, hogy ez nem segített. Megpróbálom másképp elmagyarázni?', [
                        { felirat: 'Igen, kérlek', fo: true, tesz: function () { window.SDH_AI_API.nyit(kerdes ? kerdes.textContent.trim() + ' – A súgó válasza nem segített, magyarázd el másképp, lépésenként.' : ''); } },
                        { felirat: 'Nem kell', tesz: function () {} }
                    ]);
                }
            })
            .catch(function () {})
            .then(function () { lab.sdhFut = false; });
    });

    /* ---------------------------------------------------------------- */
    /* Közvetlen hivatkozás, tartalomjegyzék, nyomtatás                  */
    /* ---------------------------------------------------------------- */

    function horgonyNyit() {
        var cel = window.location.hash ? document.getElementById(decodeURIComponent(window.location.hash.slice(1))) : null;

        if (cel && cel.tagName === 'DETAILS') {
            cel.open = true;
            cel.scrollIntoView({ block: 'center' });
        }
    }

    window.addEventListener('hashchange', horgonyNyit);
    horgonyNyit();

    // Melyik szakasznál jár az olvasó: a tartalomjegyzék kiemeli.
    var tjLinkek = gyoker.querySelectorAll('.sdh-sugo-tj__szakaszok a');

    if (tjLinkek.length && 'IntersectionObserver' in window) {
        var lathatok = {};
        var figyelo = new IntersectionObserver(function (bejegyzesek) {
            bejegyzesek.forEach(function (b) {
                lathatok[b.target.id] = b.isIntersecting;
            });

            var elso = '';

            Array.prototype.some.call(gyoker.querySelectorAll('.sdh-sugo-szakasz'), function (sz) {
                if (lathatok[sz.id]) {
                    elso = sz.id;

                    return true;
                }

                return false;
            });

            Array.prototype.forEach.call(tjLinkek, function (a) {
                a.classList.toggle('is-aktiv', elso !== '' && a.getAttribute('href') === '#' + elso);
            });
        }, { rootMargin: '-70px 0px -55% 0px' });

        Array.prototype.forEach.call(gyoker.querySelectorAll('.sdh-sugo-szakasz'), function (sz) {
            figyelo.observe(sz);
        });
    }

    var nyomtat = gyoker.querySelector('[data-sdh-sugo-nyomtat]');

    if (nyomtat) {
        nyomtat.addEventListener('click', function () {
            window.print();
        });
    }

    // Nyomtatáskor minden válasz látszódjon, utána vissza az eredeti állapotba.
    var nyitvaVoltak = [];

    window.addEventListener('beforeprint', function () {
        nyitvaVoltak = [];

        Array.prototype.forEach.call(gyoker.querySelectorAll('details.sdh-sugo-kv'), function (d) {
            nyitvaVoltak.push([d, d.open]);
            d.open = true;
        });
    });

    window.addEventListener('afterprint', function () {
        nyitvaVoltak.forEach(function (p) { p[0].open = p[1]; });
    });
}());
